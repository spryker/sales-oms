<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace Spryker\Zed\SalesOms\Communication\Console;

use Exception;
use Generated\Shared\Transfer\OmsEventTriggerResponseTransfer;
use Spryker\Zed\Kernel\Communication\Console\Console;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * @method \Spryker\Zed\SalesOms\Business\SalesOmsFacadeInterface getFacade()
 * @method \Spryker\Zed\SalesOms\Communication\SalesOmsCommunicationFactory getFactory()
 * @method \Spryker\Zed\SalesOms\Persistence\SalesOmsRepositoryInterface getRepository()
 */
class ImportOrderItemsStatusConsole extends Console
{
    /**
     * @var string
     */
    protected const COMMAND_NAME = 'order-oms:status-import';

    /**
     * @var string
     */
    protected const COMMAND_DESCRIPTION = 'Import order item status for order items from given file.';

    /**
     * @var string
     */
    protected const ARGUMENT_FILE_PATH = 'file-path';

    /**
     * @var string
     */
    protected const OPTION_IGNORE_ERRORS = 'ignore-errors';

    /**
     * @var string
     */
    protected const OPTION_START_FROM = 'start-from';

    /**
     * @var string
     */
    protected const TABLE_HEADER_COLUMN_ROW_NUMBER = 'row_number';

    /**
     * @var string
     */
    protected const TABLE_HEADER_COLUMN_ORDER_REFERENCE = 'order_reference';

    /**
     * @var string
     */
    protected const TABLE_HEADER_COLUMN_ORDER_ITEM_REFERENCE = 'order_item_reference';

    /**
     * @var string
     */
    protected const TABLE_HEADER_COLUMN_ORDER_ITEM_EVENT_OMS = 'order_item_event_oms';

    /**
     * @var string
     */
    protected const TABLE_HEADER_COLUMN_COUNT_TRANSITIONED_ITEM = 'count_transitioned_item';

    /**
     * @var string
     */
    protected const TABLE_HEADER_COLUMN_RESULT = 'result';

    /**
     * @var string
     */
    protected const TABLE_HEADER_COLUMN_MESSAGE = 'message';

    /**
     * @uses \Spryker\Zed\Oms\OmsConfig::OMS_EVENT_TRIGGER_RESPONSE
     *
     * @var string
     */
    protected const OMS_EVENT_TRIGGER_RESPONSE = 'oms_event_trigger_response';

    /**
     * @var \Symfony\Component\Console\Output\ConsoleOutputInterface
     */
    protected $output;

    /**
     * @var \Symfony\Component\Console\Helper\Table
     */
    protected $outputTable;

    /**
     * @return array<string>
     */
    protected function getMandatoryColumns(): array
    {
        return [
            static::TABLE_HEADER_COLUMN_ORDER_ITEM_REFERENCE,
            static::TABLE_HEADER_COLUMN_ORDER_ITEM_EVENT_OMS,
        ];
    }

    /**
     * @return void
     */
    protected function configure(): void
    {
        $this->setName(static::COMMAND_NAME)
            ->setDescription(static::COMMAND_DESCRIPTION)
            ->addArgument(
                static::ARGUMENT_FILE_PATH,
                InputArgument::REQUIRED,
                'Path to the file. It can be absolute or relative to application root directory.',
            )
            ->addOption(
                static::OPTION_IGNORE_ERRORS,
                null,
                InputOption::VALUE_NONE,
                'Suppress errors if an input file row was not processed.',
            )
            ->addOption(
                static::OPTION_START_FROM,
                null,
                InputOption::VALUE_REQUIRED,
                'Start file processing from the defined row number.',
            );

        parent::configure();
    }

    /**
     * @param \Symfony\Component\Console\Input\InputInterface $input
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     *
     * @throws \Exception
     *
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $filePath = $this->resolveFilePath();

        if (!$filePath) {
            return static::CODE_ERROR;
        }

        $csvReader = $this->getFactory()
            ->getUtilDataReaderService()
            ->getCsvReader();
        $csvReader->load($filePath);

        if (
            !$csvReader->valid()
            || !$this->getFactory()->createHeaderValidator()->validate($this->getMandatoryColumns(), $csvReader)->getIsSuccessful()
        ) {
            $this->error('CSV file is invalid.');

            return static::CODE_ERROR;
        }

        $this->prepareOutputTable();

        $csvReader->rewind();
        $csvReader->getFile()->seek($this->getStartFromOption());

        $totalRowsCount = $csvReader->getTotal() - 1;
        $successfullyProcessedRowsCount = 0;
        $rowNumber = $this->getStartFromOption();

        while ($rowNumber < $totalRowsCount) {
            $rowData = $csvReader->read();
            $rowNumber++;
            try {
                $salesOrderItemTransfer = $this->getFacade()
                    ->findSalesOrderItemByOrderItemReference($rowData[static::TABLE_HEADER_COLUMN_ORDER_ITEM_REFERENCE]);

                if (!$salesOrderItemTransfer) {
                    throw new Exception('Sales order item not found');
                }
                $manualEvents = $this->getFactory()->getOmsFacade()->getManualEvents($salesOrderItemTransfer->getIdSalesOrderItemOrFail());

                if (!in_array($rowData[static::TABLE_HEADER_COLUMN_ORDER_ITEM_EVENT_OMS], $manualEvents)) {
                    throw new Exception(sprintf(
                        'Item can\'t be triggered. Available events for item: %s',
                        implode(', ', $manualEvents),
                    ));
                }
                $result = $this->getFactory()->getOmsFacade()->triggerEventForOneOrderItem(
                    $rowData[static::TABLE_HEADER_COLUMN_ORDER_ITEM_EVENT_OMS],
                    $salesOrderItemTransfer->getIdSalesOrderItemOrFail(),
                );
                $omsEventTriggerResponseTransfer = $result[static::OMS_EVENT_TRIGGER_RESPONSE] ?? null;

                if (
                    $omsEventTriggerResponseTransfer instanceof OmsEventTriggerResponseTransfer
                    && $omsEventTriggerResponseTransfer->getIsSuccessful() === false
                ) {
                    $messageTransfer = $omsEventTriggerResponseTransfer->getMessages()->getIterator()->current();

                    throw new Exception($messageTransfer->getValue());
                }

                if ($result === null) {
                    throw new Exception('Can\'t trigger event.');
                }
                $successfullyProcessedRowsCount++;

                $this->logOutput(
                    $rowNumber,
                    $rowData,
                    ($result !== null),
                );
            } catch (Throwable $exception) {
                $this->logOutput(
                    $rowNumber,
                    $rowData,
                    false,
                    $exception->getMessage(),
                );
            }
        }

        $this->info(sprintf('Rows processed: %s/%s', $successfullyProcessedRowsCount, $totalRowsCount));

        return static::CODE_SUCCESS;
    }

    /**
     * @return string|null
     */
    protected function resolveFilePath(): ?string
    {
        /** @var string $filePath */
        $filePath = $this->input->getArgument(static::ARGUMENT_FILE_PATH);
        $filePathResolverResponseTransfer = $this->getFactory()
            ->createFilePathResolver()
            ->resolveFilePath($filePath);

        if (!$filePathResolverResponseTransfer->getIsSuccessful()) {
            $this->error($filePathResolverResponseTransfer->getMessageOrFail()->getMessageOrFail());

            return null;
        }

        return $filePathResolverResponseTransfer->getFilePath();
    }

    /**
     * @return int
     */
    protected function getStartFromOption(): int
    {
        return max((int)$this->input->getOption(static::OPTION_START_FROM) - 1, 0);
    }

    /**
     * @return void
     */
    protected function prepareOutputTable(): void
    {
        if (!$this->output->isVerbose()) {
            return;
        }

        $table = (new Table($this->output->section()))->setHeaders([
            static::TABLE_HEADER_COLUMN_ROW_NUMBER,
            static::TABLE_HEADER_COLUMN_ORDER_REFERENCE,
            static::TABLE_HEADER_COLUMN_ORDER_ITEM_REFERENCE,
            static::TABLE_HEADER_COLUMN_ORDER_ITEM_EVENT_OMS,
            static::TABLE_HEADER_COLUMN_RESULT,
            static::TABLE_HEADER_COLUMN_MESSAGE,
        ]);
        $table->render();

        $this->outputTable = $table;
    }

    /**
     * @param int $rowNumber
     * @param array $rowData
     * @param bool $result
     * @param string|null $message
     *
     * @return void
     */
    protected function logOutput(
        int $rowNumber,
        array $rowData,
        bool $result,
        $message = null
    ): void {
        if ($this->output->isVerbose()) {
            $this->outputTable->appendRow([
                $rowNumber,
                $rowData[static::TABLE_HEADER_COLUMN_ORDER_REFERENCE],
                $rowData[static::TABLE_HEADER_COLUMN_ORDER_ITEM_REFERENCE],
                $rowData[static::TABLE_HEADER_COLUMN_ORDER_ITEM_EVENT_OMS],
                $result ? 'success' : 'fail',
                $message,
            ]);
        }
    }
}
