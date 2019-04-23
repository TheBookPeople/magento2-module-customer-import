<?php

namespace Augustash\CustomerImport\Console\Command;

use Magento\Customer\Model\AddressFactory;
use Magento\Customer\Model\CustomerFactory;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\State;
use Magento\Framework\File\Csv;
use Magento\Framework\Filesystem\Io\File;
use Magento\Vault\Api\Data\PaymentTokenInterface;
use Magento\Cybersource\Gateway\Vault\PaymentTokenManagement;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use Zero1\VaultWebApi\Api\PaymentTokenRepositoryInterface;

class CustomerCardImportCommand extends Command
{
    /**
     * @var \Magento\Framework\App\State
     */
    protected $appState;

    /**
     * @var \Magento\Customer\Model\AddressFactory
     */
    protected $addressFactory;

    /**
     * @var \Magento\Customer\Model\CustomerFactory
     */
    protected $customerFactory;

    /**
     * @var \Magento\Framework\File\Csv
     */
    protected $fileCsv;

    /**
     * @var \Magento\Framework\App\Filesystem\DirectoryList
     */
    protected $directoryList;

    /**
     * @var \Magento\Framework\Filesystem\Io\File
     */
    protected $io;

    /**
     * CSV File Name
     * @var string
     */
    protected $csvFileName = 'stored_cards.csv';

    /**
     * CSV file path (relative to Magento root directory)
     * @var string
     */
    protected $csvFilePath = '/var/import';

    /**
     * @var string
     */
    protected $logPath = '/var/log/stored_card_import.log';

    /**
     * @var array
     */
    protected $customAttributes = [];

    /**
     * Info
     * @var array
     */
    protected $info = ['info' => 'Display additional information about this command (i.e., logs, filenames, etc.)',
        'filename' => 'File name of stored cards csv file',
        'website-id' => 'Set the website the customer should belong to.',
        'store-id' => 'Set the store view the customer should belong to.',
        'customer-id-column' => 'Identify which column within the spreadsheet identifies the ID of the customer the address belongs to',
        'find-customer-by-attribute' => 'Specify which customer attribute should be used to query the database to find a matching customer (i.e., "email", "customer_id", "entity_id").',
        'custom-attributes' => 'Define custom attributes as a comma-seperated list that should be included from the CSV.',
    ];

    protected $requiredColumns = [
        'USERS_ID',
        'CARDTOKEN',
        'CARDBRAND',
        'CARDNUMBER',
        'EXPIRYYEAR',
        'EXPIRYMONTH'
    ];

    // params (default)
    protected $websiteId = 1;
    protected $storeId = 1;
    protected $customerIdColumn = 'USERS_ID';
    protected $findCustomerByAttribute = 'entity_id';

    /**
     * Constructor
     *
     * @param \Magento\Framework\App\State $appState
     * @param \Magento\Customer\Model\CustomerFactory $customerFactory
     * @param \Magento\Framework\File\Csv $fileCsv
     * @param \Magento\Framework\App\Filesystem\DirectoryList $directoryList
     * @param \Magento\Framework\Filesystem\Io\File $io
     */
    protected $vaultTokenRepository;
    private $paymentTokenManagement;

    public function __construct(
        PaymentTokenManagement $paymentTokenManagement,
        \Magento\Vault\Api\PaymentTokenRepositoryInterface $paymentTokenRepository,
        State $appState,
        CustomerFactory $customerFactory,
        Csv $fileCsv,
        DirectoryList $directoryList,
        File $io
    ) {
        $this->paymentTokenManagement = $paymentTokenManagement;
        $this->vaultTokenRepository = $paymentTokenRepository;
        $this->appState = $appState;
        $this->customerFactory = $customerFactory;
        $this->directoryList = $directoryList;
        $this->fileCsv = $fileCsv;
        $this->io = $io;

        // create the var/import directory if it doesn't exist
        $this->io->mkdir($this->directoryList->getRoot() . $this->csvFilePath, 0775);

        parent::__construct();
    }

    protected function configure()
    {
        $this->setName('customer:card:import')
          ->setDescription("Import Stored Cards from a CSV file ({$this->csvFileName}) located in the {$this->csvFilePath} directory.");

        // addOption($name, $shortcut, $mode, $description, $default)
        $this->addOption('info', null, null, $this->info['info']);
        $this->addOption('filename', null, InputOption::VALUE_OPTIONAL, $this->info['filename'], 'stored_cards.csv');
        $this->addOption('website-id', null, InputOption::VALUE_OPTIONAL, $this->info['website-id'], 1);
        $this->addOption('store-id', null, InputOption::VALUE_OPTIONAL, $this->info['store-id'], 1);
        $this->addOption('customer-id-column', null, InputOption::VALUE_OPTIONAL, $this->info['customer-id-column'], 'USERS_ID');
        $this->addOption('find-customer-by-attribute', null, InputOption::VALUE_OPTIONAL, $this->info['find-customer-by-attribute'], 'entity_id');
        $this->addOption('custom-attributes', null, InputOption::VALUE_OPTIONAL, $this->info['custom-attributes']);

        parent::configure();
    }

    /**
     * @param \Symfony\Component\Console\Input\InputInterface $input
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     * @throws \Magento\Framework\Exception\LocalizedException
     * @return null|int null or 0 if everything went fine, or an error code
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        try {
            $this->appState->setAreaCode('adminhtml');
        } catch (\Exception $e) {
            try {
                $this->appState->setAreaCode('adminhtml');
            } catch (\Exception $e) {
                // area code already set
            }
        }

        if ($input->getOption('info')) {
            echo "info:\n\t" . $this->info['info'] . PHP_EOL . PHP_EOL;
            echo "filename:\n\t" . $this->info['filename'] . PHP_EOL . PHP_EOL;
            echo "website-id:\n\t" . $this->info['website-id'] . PHP_EOL . PHP_EOL;
            echo "store-id:\n\t" . $this->info['store-id'] . PHP_EOL . PHP_EOL;
            echo "customer-id-column:\n\t" . $this->info['customer-id-column'] . PHP_EOL . PHP_EOL;
            echo "find-customer-by-attribute\n\t" . $this->info['find-customer-by-attribute'] . PHP_EOL . PHP_EOL;
            echo "custom-attributes:\n\t" . $this->info['custom-attributes'] . PHP_EOL . PHP_EOL;

            echo "\n\nStored Card Import expects the {$this->csvFileName} file to be located in the {$this->csvFilePath} directory.\n\nThe log file is at {$this->logPath}" . PHP_EOL . PHP_EOL;
            exit;
        }

        $options = $input->getOptions();

        $this->csvFileName = (isset($options['filename'])) ? $options['filename'] : $this->csvFilename;
        $websiteId = (isset($options['website-id'])) ? $options['website-id'] : $this->websiteId;
        $storeId = (isset($options['store-id'])) ? $options['store-id'] : $this->storeId;

        if (isset($options['custom-attributes'])) {
            $this->setCustomAttributes(explode(',', $options['custom-attributes']));
        }

        $customerIdColumn = isset($options['customer-id-column']) ? $options['customer-id-column'] : $this->customerIdColumn;
        $findCustomerByAttribute = isset($options['find-customer-by-attribute']) ? $options['find-customer-by-attribute'] : $this->findCustomerByAttribute;

        $output->writeln('<info>Starting Stored Card Import</info>');

        // $this->log('options: ' . print_r($input->getOptions(), true));
        $this->log('filename: ' . var_export($this->csvFileName, true));
        $this->log('websiteId: ' . var_export($websiteId, true));
        $this->log('storeId: ' . var_export($storeId, true));
        $this->log('customerIdColumn: ' . var_export($customerIdColumn, true));
        $this->log('findCustomerByAttribute: ' . var_export($findCustomerByAttribute, true));
        $this->log('customAttributes: ' . print_r($this->getCustomAttributes(), true));

        $csvData = $this->fileCsv->getData($this->getCsvFilePath());
        $csvHeaders = array_values(array_shift($csvData));

        $existingStoredCards = [];
        $rowsWithErrors = [];

        foreach ($csvData as $key => $row) {
            try {
                $cardData = array_combine($csvHeaders, $row);
                $customerIdColumnValue = $cardData[$customerIdColumn];

                $this->log('card token: ' . $cardData['CARDTOKEN']);
                
                $customer = $this->getCustomerByAttribute($findCustomerByAttribute, $customerIdColumnValue, $websiteId);

                if (!$customer || !$customer->getId()) {
                    $this->log('No customer for entity ' . $customerIdColumnValue);
                    continue;
                } else if (!$customer || !$customer->getId()) {
                    $this->log('No customer ID for entity ' . $customerIdColumnValue);
                    continue;
                } else {
                    $formattedCardData = $this->mapData($cardData);
                    $existingCardToken = $this->checkIfStoredCardExists($customer->getId(), $formattedCardData);

                    if ($existingCardToken) {
                        $this->log('Stored card ' . $formattedCardData['card_token'] . ' already exists');
                        $existingStoredCards[$key] = $cardData;
                        //$storedCard = $this->storedCardFactory->create();
                        //$storedCard = $storedCard->load($existingCardToken);
                        //$storedCard->save();
                    } else {
                        if (isset($formattedCardData['CARDTOKEN']) &&
                            isset($formattedCardData['CARDBRAND']) &&
                            isset($formattedCardData['CARDNUMBER']) &&
                            isset($formattedCardData['EXPIRYYEAR']) &&
                            isset($formattedCardData['EXPIRYMONTH'])
                        ) {
                            $this->log('Adding card ' . $formattedCardData['CARDNUMBER'] . ' for customer ' . $customerIdColumnValue);
                            // create new stored card
                            $cardToken = $formattedCardData['CARDTOKEN'];
                            $cardBrand = $formattedCardData['CARDBRAND'];
                            $cardNumber = $formattedCardData['CARDNUMBER'];
                            $expiryDate = sprintf('%02d', $formattedCardData['EXPIRYMONTH']) . substr($formattedCardData['EXPIRYYEAR'], 2, 2) ;

                            // Set the payment token
                            $paymentToken = $this->paymentTokenManagement->create($cardToken);

                            $paymentToken
                                ->setCustomerId($customerIdColumnValue)
                                ->setPaymentMethodCode('tns')
                                ->setType('card')
                                ->setGatewayToken($cardToken)
                                ->setPublicHash(substr(md5(rand(0, time())), 0, 128))
                                ->setTokenDetails(json_encode([
                                    'type' => $cardBrand,
                                    'maskedCC' => $cardNumber,
                                    'expirationDate' => $expiryDate
                                    ]))
                                ->setIsActive(true)
                                ->setIsVisible(true)
                                ->setExpiresAt(date('Y-m-d H:i:s', strtotime('+1 year')))
                                ->setEntityId(null);

                            // Save the card details
                            $this->vaultTokenRepository->save($paymentToken);
                            $this->log('Stored card saved with token ' . $cardToken);

                        } else {
                            $this->log('Formatted card data incomplete');
                            $this->log('CARDTOKEN: ' . isset($formattedCardData['CARDTOKEN']));
                            $this->log('CARDBRAND: ' . isset($formattedCardData['CARDBRAND']));
                            $this->log('CARDNUMBER: ' . isset($formattedCardData['CARDNUMBER']));
                            $this->log('EXPIRYYEAR: ' . isset($formattedCardData['EXPIRYYEAR']));
                            $this->log('EXPIRYMONTH: ' . isset($formattedCardData['EXPIRYMONTH']));
                            
                            $rowsWithErrors[$key] = $cardData;
                        }
                    }
                }
            } catch (LocalizedException $e) {
                $rowsWithErrors[$key] = $cardData;
                $output->writeln($e->getMessage());
            } catch (\Exception $e) {
                $rowsWithErrors[$key] = $cardData;
                $output->writeln('Not able to import stored cards because: ');
                $output->writeln($e->getMessage());
            }
        }

        $countExistingStoredCards = count($existingStoredCards);
        $countRowsWithErrors = count($rowsWithErrors);

        $this->log('============================');
        $this->log('Existing Stored Cards (skipped): ' . $countExistingStoredCards);
        $this->log('============================');
        // $this->log(print_r($existingCustomers, true));

        $this->log('============================');
        $this->log('Rows with errors (skipped): ' . $countRowsWithErrors);
        $this->log('============================');
        $this->log(print_r($rowsWithErrors, true));

        $output->writeln("<info>Existing Stored Cards (skipped): {$countExistingStoredCards}</info>");
        $output->writeln("<info>Rows with errors (skipped): {$countRowsWithErrors}. See log for details.</info>");
        $output->writeln('<info>Finished Stored Card Import</info>');
    }

    /**
     * Returns a customer model if it can be found by the
     * specified attribute(s) and value(s), otherwise returns false
     *
     * @param  string|array $attributes
     * @param  string|array $values
     * @param  string|integer $websiteId
     * @return \Magento\Customer\Model\Customer|false
     */
    public function getCustomerByAttribute($attributes, $values, $websiteId)
    {
        // convert attributes string to an array (comma-separated attributes)
        if (is_string($attributes)) {
            $attributes = explode(',', $attributes);
        }

        // convert values string to an array (comma-separated values)
        if (is_string($values)) {
            $values = explode(',', $values);
        }

        if (!is_array($attributes)) {
            $attributes = (array)$attributes;
        }

        if (!is_array($values)) {
            $values = (array)$values;
        }

        $collection = $this->customerFactory->create()->getCollection();
        $collection->addAttributeToSelect('*');
        $collection->addFieldToFilter('website_id', $websiteId);

        foreach ($attributes as $key => $attr) {
            $collection->addAttributeToFilter($attr, ['eq' => $values[$key]]);
        }

        // limit the query to fetch 1 result
        $collection->setPageSize(1, 1);

        $customer = $collection->getFirstItem();
        // $this->log('customer: ' . print_r($customer->getData(), true));

        if ($customer && $customer->getId()) {
            return $customer;
        }

        return false;
    }

    /**
     * Map/format raw data to Magento address conventions
     *
     * @param  array  $data
     * @return array
     */
    public function mapData(array $data)
    {
        $formattedCard = [
            'CARDTOKEN' => null,
            'CARDBRAND' => null,
            'CARDNUMBER' => null,
            'EXPIRYYEAR' => null,
            'EXPIRYMONTH' => null
        ];

        foreach ($data as $key => $value) {
            switch ($key) {
                case 'CARDTOKEN':
                case 'CARDBRAND':
                case 'EXPIRYYEAR':
                case 'EXPIRYMONTH':
                    $formattedCard[$key] = $value;
                    break;
                case 'CARDNUMBER':
                    $formattedCard['CARDNUMBER'] = substr($value, 0, 2) . "xxxxxxxxxxxx" . substr($value, 2, 2);
                    break;
            }
        }
        return $formattedCard;
    }

    /**
     * Check if a stored card already exists on the website
     *
     * @param integer $customerId
     * @param array $cardData
     * @return false|integer # card token if the stored card exists
     */
    public function checkIfStoredCardExists($customerId, $cardData)
    {
        /**
         * @todo try to find stored card by the customer_id and the stored card token
         */

        return false;
    }

    public function setCustomAttributes(array $value)
    {
        $this->customAttributes = $value;
        return $this;
    }

    public function getCustomAttributes()
    {
        return $this->customAttributes;
    }

    public function setRequiredColumns(array $value)
    {
        $this->requiredColumns = $value;
        return $this;
    }

    public function getRequiredColumns()
    {
        return $this->requiredColumns;
    }

    public function log($info)
    {
        $writer = new \Zend\Log\Writer\Stream(BP . $this->logPath);
        $logger = new \Zend\Log\Logger();
        $logger->addWriter($writer);
        $logger->info($info);
    }

    public function getCsvFilePath()
    {
        return $this->directoryList->getRoot() . $this->csvFilePath . DIRECTORY_SEPARATOR . $this->csvFileName;
    }
}
