<?php

namespace App\Command;

use App\Entity\StripeTransfer;
use App\Message\ProcessTransferMessage;
use App\Repository\MiraklStripeMappingRepository;
use App\Repository\StripeTransferRepository;
use App\Utils\MiraklClient;
use App\Utils\StripeProxy;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\MessageBusInterface;

class ProcessTransferCommand extends Command implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    protected static $defaultName = 'connector:dispatch:process-transfer';

    protected static $ignoredOrderStatuses = [
            'STAGING', 'WAITING_ACCEPTANCE', // Order not scored or approved yet
            'WAITING_DEBIT', 'WAITING_DEBIT_PAYMENT', // Payment not processed yet
            'REFUSED', 'CANCELED' // Order aborted by operator or seller
    ];

    /**
     * @var MessageBusInterface
     */
    private $bus;

    /**
     * @var bool
     */
    private $enablesAutoTransferCreation;

    /**
     * @var MiraklClient
     */
    private $miraklClient;

    /**
     * @var StripeProxy
     */
    private $stripeProxy;

    /**
     * @var StripeTransferRepository
     */
    private $stripeTransferRepository;

    /**
     * @var MiraklStripeMappingRepository
     */
    private $miraklStripeMappingRepository;

    public function __construct(MessageBusInterface $bus, bool $enablesAutoTransferCreation, MiraklClient $miraklClient, StripeProxy $stripeProxy, StripeTransferRepository $stripeTransferRepository, MiraklStripeMappingRepository $miraklStripeMappingRepository)
    {
        $this->bus = $bus;
        $this->enablesAutoTransferCreation = $enablesAutoTransferCreation;
        $this->miraklClient = $miraklClient;
        $this->stripeProxy = $stripeProxy;
        $this->stripeTransferRepository = $stripeTransferRepository;
        $this->miraklStripeMappingRepository = $miraklStripeMappingRepository;
        parent::__construct();
    }

    protected function configure()
    {
        $this->addArgument('mirakl_order_ids', InputArgument::IS_ARRAY);
    }

    protected function execute(InputInterface $input, OutputInterface $output): ?int
    {
        if (!$this->enablesAutoTransferCreation) {
            $output->writeln('Transfer creation is disabled.');
            $output->writeln('You can enable it by setting the environement variable ENABLES_AUTOMATIC_TRANSFER_CREATION to true.');

            return 0;
        }

        // getArgument should never return a string when using InputArgument::IS_ARRAY
        $miraklOrderIds = $input->getArgument('mirakl_order_ids');
        assert(null === $miraklOrderIds || is_array($miraklOrderIds));

        if (null !== $miraklOrderIds && count($miraklOrderIds) > 0) {
            $this->logger->info(
                'Executing with specific orders',
                [ 'order_ids' => $miraklOrderIds ]
            );
            $miraklOrders = $this->miraklClient->listOrdersById($miraklOrderIds);
        } else {
            $lastMiraklUpdateTime = $this->stripeTransferRepository->getLastMiraklUpdateTime();

            if (null !== $lastMiraklUpdateTime) {
                $this->logger->info(
                    'Executing for recent orders',
                    [ 'since' => $lastMiraklUpdateTime->format(MiraklClient::DATE_FORMAT) ]
                );
                $miraklOrders = $this->miraklClient->listOrdersByDate($lastMiraklUpdateTime);
            } else {
                $this->logger->info('Executing for all orders');
                $miraklOrders = $this->miraklClient->listOrders();
            }
        }

        if (count($miraklOrders) > 0) {
            $transfers = $this->prepareTransfers($miraklOrders);
            $this->dispatchTransfers($transfers);
        }

        return 0;
    }

    private function prepareTransfers(array $miraklOrders): array
    {
        // Retrieve existing StripeTransfers with provided order IDs
        $existingTransfers = $this->stripeTransferRepository
            ->findExistingTransfersByOrderIds(array_column($miraklOrders, 'order_id'));

        $transfers = [];
        foreach ($miraklOrders as $miraklOrder) {
            $orderId = $miraklOrder['order_id'];

            if (in_array($miraklOrder['order_state'], self::$ignoredOrderStatuses)) {
                $ignoreReason = sprintf(
                    'Skipping order in state %s',
                    $miraklOrder['order_state']
                );
                $this->logger->info($ignoreReason, [ 'order_id' => $orderId ]);
                continue;
            }

            if (isset($existingTransfers[$orderId])) {
                // Use existing transfer
                $transfer = $existingTransfers[$orderId];

                if ($transfer->getStatus() == StripeTransfer::TRANSFER_CREATED) {
                    $ignoreReason = 'Skipping order transfer with existing created transfer';
                    $this->logger->info($ignoreReason, [ 'order_id' => $orderId ]);
                    continue;
                } elseif ($transfer->getTransferId()) {
                    // Should not happen but in case it does let's clean it up
                    $transfer->setStatus(StripeTransfer::TRANSFER_CREATED);
                    $ignoreReason = 'Cleaning order transfer with existing Stripe transfer';
                    $this->logger->info($ignoreReason, [ 'order_id' => $orderId ]);
                    continue;
                }

                // Transfer to be retried for this order
                $transfer->setStatus(StripeTransfer::TRANSFER_PENDING);
            } else {
                // Initiating new transfer
                $transfer = new StripeTransfer();
                $transfer->setType(StripeTransfer::TRANSFER_ORDER);
                $transfer->setStatus(StripeTransfer::TRANSFER_PENDING);
                $transfer->setMiraklId($orderId);

                // Setting payment ID
                $transactionId = $miraklOrder['transaction_number'];
                if (0 === strpos($transactionId, 'ch_') || 0 === strpos($transactionId, 'py_')) {
                    $transfer->setTransactionId($transactionId);
                }

                // Create new transfer
                $this->stripeTransferRepository->persist($transfer);
            }

            $transfer = $this->prepareTransfer($miraklOrder, $transfer);

            if ($transfer) {
                $transfers[] = $transfer;
            }
        }

        // Save everything
        $this->stripeTransferRepository->flush();

        return $transfers;
    }

    private function prepareTransfer(array $miraklOrder, StripeTransfer $transfer)
    {
        // Transfer amount
        $amount = $miraklOrder['total_price'] - $miraklOrder['total_commission'];
        if (isset($miraklOrder['order_lines'])) {
            foreach ($miraklOrder['order_lines'] as $orderLine) {
                if (in_array($orderLine['order_line_state'], self::$ignoredOrderStatuses)) {
                    continue;
                }

                $allTaxes = array_merge(
                    (array) $orderLine['shipping_taxes'],
                    (array) $orderLine['taxes']
                );

                foreach ($allTaxes as $tax) {
                    $amount += (float) $tax['amount'];
                }
            }
        }

        $amount = gmp_intval((string) ($amount * 100));
        if ($amount < 0) {
            return $this->failTransfer($transfer, sprintf(
                'Cannot transfer negative amount, %s provided',
                (string) $amount
            ));
        }

        $transfer->setAmount($amount);
        $transfer->setCurrency($miraklOrder['currency_iso_code']);

        // Seller ID
        $shopId = $miraklOrder['shop_id'];
        $mapping = $this->miraklStripeMappingRepository->findOneBy([
            'miraklShopId' => $shopId,
        ]);

        if (!$mapping) {
            return $this->failTransfer($transfer, sprintf(
                'Cannot find Stripe account for Seller ID %s',
                $shopId
            ));
        }

        $transfer->setMiraklStripeMapping($mapping);

        // Mirakl update time
        $miraklUpdateTime = \DateTime::createFromFormat(
            MiraklClient::DATE_FORMAT,
            $miraklOrder['last_updated_date']
        );

        if (!$miraklUpdateTime) {
            return $this->failTransfer($transfer, sprintf(
                'Cannot parse last_updated_date %s',
                $miraklOrder['last_updated_date']
            ));
        }

        $transfer->setMiraklUpdateTime($miraklUpdateTime);

        return $transfer;
    }

    private function failTransfer($transfer, $reason): StripeTransfer
    {
        $this->logger->error($reason, ['order_id' => $transfer->getMiraklId()]);

        $transfer->setStatus(StripeTransfer::TRANSFER_FAILED);
        $transfer->setFailedReason($reason);

        return $transfer;
    }

    private function dispatchTransfers(array $transfers): void
    {
        foreach ($transfers as $transfer) {
            $this->bus->dispatch(new ProcessTransferMessage(
                StripeTransfer::TRANSFER_ORDER,
                $transfer->getId()
            ));
        }
    }
}
