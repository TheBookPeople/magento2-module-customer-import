<?php

namespace Augustash\CustomerImport\Console\Command;

use Magento\Framework\App\State;
use Magento\Customer\Model\CustomerFactory;
use Magento\Customer\Model\AddressFactory;
use Magento\Directory\Model\CountryFactory;
use Magento\Directory\Model\RegionFactory;
use Magento\Framework\Locale\TranslatedLists;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem\Io\File;
use Magento\Framework\File\Csv;
use Magento\Framework\Math\Random;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

class CustomerAddressImportCommand extends Command
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
     * @var \Magento\Framework\Math\Random
     */
    protected $random;

    /**
     * @var \Magento\Directory\Model\CountryFactory
     */
    protected $countryFactory;

    /**
     * @var \Magento\Directory\Model\RegionFactory
     */
    protected $regionFactory;

    /**
     * Use for getting the country name
     * @var \Magento\Framework\Locale\TranslatedLists
     */
    protected $translatedLists;

    /**
     * CSV File Name
     * @var string
     */
    protected $csvFileName = 'customer_addresses.csv';

    /**
     * CSV rile path (relative to Magento root directory)
     * @var string
     */
    protected $csvFilePath = '/var/import';

    /**
     * @var string
     */
    protected $logPath = '/var/log/customer_address_import.log';

    /**
     * @var array
     */
    protected $customAttributes = [];

    /**
     * Info
     * @var array
     */
    protected $info = ['info' => 'Display additional information about this command (i.e., logs, filenames, etc.)',
        'website-id' => 'Set the website the customer should belong to.',
        'store-id' => 'Set the store view the customer should belong to.',
        'customer-id-column' => 'Identify which column within the spreadsheet identifies the ID of the customer the address belongs to',
        'find-customer-by-attribute' => 'Specify which customer attribute should be used to query the database to find a matching customer (i.e., "email", "customer_id", "old_customer_id").'
    ];

    protected $requiredColumns = [
        'firstname',
        'lastname',
        'city',
        'street',
        'region',
        'postcode',
        'country_id',
        'telephone'
    ];


    // params (default)
    protected $websiteId = 1;
    protected $storeId = 1;
    protected $customerIdColumn = 'customer_id';
    protected $findCustomerByAttribute = 'old_customer_id';

    /**
     * Constructor
     *
     * @param \Magento\Framework\App\State $appState
     * @param \Magento\Customer\Model\AddressFactory $addressFactory
     * @param \Magento\Directory\Model\CountryFactory $countryFactory
     * @param \Magento\Customer\Model\CustomerFactory $customerFactory
     * @param \Magento\Framework\File\Csv $fileCsv
     * @param \Magento\Framework\App\Filesystem\DirectoryList $directoryList
     * @param \Magento\Framework\Filesystem\Io\File $io
     * @param \Magento\Framework\Math\Random $random
     * @param \Magento\Directory\Model\RegionFactory $regionFactory
     * @param \Magento\Framework\Locale\TranslatedLists $translatedLists
     */
    public function __construct(
        State $appState,
        AddressFactory $addressFactory,
        CountryFactory $countryFactory,
        CustomerFactory $customerFactory,
        Csv $fileCsv,
        DirectoryList $directoryList,
        File $io,
        Random $random,
        RegionFactory $regionFactory,
        TranslatedLists $translatedLists
    )
    {
        $this->addressFactory = $addressFactory;
        $this->appState = $appState;
        $this->countryFactory = $countryFactory;
        $this->customerFactory = $customerFactory;
        $this->directoryList = $directoryList;
        $this->fileCsv = $fileCsv;
        $this->io = $io;
        $this->random = $random;
        $this->regionFactory = $regionFactory;
        $this->translatedLists = $translatedLists;

        // create the var/import directory if it doesn't exist
        $this->io->mkdir($this->directoryList->getRoot() . $this->csvFilePath, 0775);

        parent::__construct();
    }

    protected function configure()
    {
        $this->setName('customer:address:import')
            ->setDescription("Import Customer Addresses from a CSV file ({$this->csvFileName}) located in the {$this->csvFilePath} directory.");

        // addOption($name, $shortcut, $mode, $description, $default)
        $this->addOption('info', null, null, $this->info['info']);
        $this->addOption('website-id', null, InputOption::VALUE_OPTIONAL, $this->info['website-id'], 1);
        $this->addOption('store-id', null, InputOption::VALUE_OPTIONAL, $this->info['store-id'], 1);
        $this->addOption('customer-id-column', null, InputOption::VALUE_OPTIONAL, $this->info['customer-id-column'], 'customer_id');
        $this->addOption('find-customer-by-attribute', null, InputOption::VALUE_OPTIONAL, $this->info['find-customer-by-attribute'], 'old_customer_id');

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
            if ($this->appState->getMode() == \Magento\Framework\App\State::MODE_DEVELOPER) {
                if (!$this->appState->getAreaCode()) {
                    $this->appState->setAreaCode('adminhtml');
                }
            } else {
                if (!$this->appState->getAreaCode()) {
                    $this->appState->setAreaCode('adminhtml');
                }
            }
        } catch (Exception $e) {
            $this->log($e->getMessage());
        }

        if ($input->getOption('info')) {
            echo "info:\n\t" . $this->info['info'] . PHP_EOL . PHP_EOL;
            echo "website-id:\n\t" . $this->info['website-id'] . PHP_EOL . PHP_EOL;
            echo "store-id:\n\t" . $this->info['store-id'] . PHP_EOL . PHP_EOL;
            echo "customer-id-column:\n\t" . $this->info['customer-id-column'] . PHP_EOL . PHP_EOL;
            echo "find-customer-by-attribute\n\t" . $this->info['find-customer-by-attribute'] . PHP_EOL . PHP_EOL;

            echo "\n\nCustomer Address Import expects the {$this->csvFileName} file to be located in the {$this->csvFilePath} directory.\n\nThe log file is at {$this->logPath}" . PHP_EOL . PHP_EOL;
            exit;
        }

        $options = $input->getOptions();

        $websiteId = (isset($options['website-id'])) ? $options['website-id'] : $this->websiteId;
        $storeId = (isset($options['store-id'])) ? $options['store-id'] : $this->storeId;

        $customerIdColumn = isset($options['customer-id-column']) ? $options['customer-id-column'] : $this->customerIdColumn;
        $findCustomerByAttribute = isset($options['find-customer-by-attribute']) ? $options['find-customer-by-attribute'] : $this->findCustomerByAttribute;

        $output->writeln('<info>Starting Customer Address Import</info>');

        // $this->log('options: ' . print_r($input->getOptions(), true));
        $this->log('websiteId: ' . var_export($websiteId, true));
        $this->log('storeId: ' . var_export($storeId, true));
        $this->log('customerIdColumn: ' . var_export($customerIdColumn, true));
        $this->log('findCustomerByAttribute: ' . var_export($findCustomerByAttribute, true));

        $csvData = $this->fileCsv->getData($this->getCsvFilePath());
        $headers = array_values(array_shift($csvData));

        $existingCustomerAddresses = [];
        $rowsWithErrors = [];
        foreach($csvData as $key => $row) {
            try {

                $addressData = array_combine($headers, $row);
                $customerIdColumnValue = $addressData[$customerIdColumn];

                $customer = $this->getCustomerByAttribute($findCustomerByAttribute, $customerIdColumnValue, $websiteId);

                if (!$customer || !$customer->getId()) {
                    // throw new \Exception("Row {$key}: Unable to find corresponding customer with old_customer_id = {$oldCustomerId}");

                    // skip the customer address if we can't find
                    // a corresponding customer by the old_customer_id
                    continue;
                } else {
                    $formattedAddressData = $this->mapData($addressData);

                    // $this->log('$formattedAddressData: ' . print_r($formattedAddressData, true));

                    $exists = $this->checkIfCustomerAddressExists($customer->getId(),  $formattedAddressData);

                    // $this->log('$exists: ' . var_export($exists, true));

                    $address = $this->addressFactory->create();

                    if ($exists) {
                        $existingCustomerAddresses[$key] = $addressData;
                    } else {
                        if (isset($formattedAddressData['firstname']) &&
                            isset($formattedAddressData['lastname']) &&
                            isset($formattedAddressData['street']) &&
                            isset($formattedAddressData['city']) &&
                            isset($formattedAddressData['region']) &&
                            isset($formattedAddressData['postcode']) &&
                            isset($formattedAddressData['country_id']) &&
                            isset($formattedAddressData['telephone'])
                        ) {
                            // create new customer address
                            foreach($formattedAddressData as $key => $value) {
                                $address->setData($key, $value);
                            }
                            $address->setData('is_active', true);
                            $address->setData('parent_id', $customer->getId());


                            $optionalValues = ['created_at', 'updated_at'];
                            foreach($optionalValues as $attr) {
                                if (isset($formattedAddressData[$attr])) {
                                    $address->setData($attr, $formattedAddressData[$attr]);
                                }
                            }

                            foreach($this->getCustomAttributes() as $attr) {
                                if (isset($formattedAddressData[$attr])) {
                                    $address->setData($attr, $formattedAddressData[$attr]);
                                }
                            }

                            // $this->log('address before save: ' . print_r($address->getData(), true));

                            // save the customer address
                            $address->save();
                        } else {
                            $rowsWithErrors[$key] = $addressData;
                        }
                    }
                }
            } catch (LocalizedException $e) {
                $rowsWithErrors[$key] = $addressData;
                $output->writeln($e->getMessage());
            } catch (\Exception $e) {
                $rowsWithErrors[$key] = $addressData;
                $output->writeln('Not able to import customer addresses because: ');
                $output->writeln($e->getMessage());
            }
        }

        $countExistingCustomerAddresses = count($existingCustomerAddresses);
        $countRowsWithErrors = count($rowsWithErrors);

        $this->log('============================');
        $this->log('Existing Customer Addresses (skipped): ' . $countExistingCustomerAddresses);
        $this->log('============================');
        // $this->log(print_r($existingCustomers, true));

        $this->log('============================');
        $this->log('Rows with errors (skipped): ' . $countRowsWithErrors);
        $this->log('============================');
        $this->log(print_r($rowsWithErrors, true));


        $output->writeln("<info>Existing Customer Addresses (skipped): {$countExistingCustomerAddresses}</info>");
        $output->writeln("<info>Rows with errors (skipped): {$countRowsWithErrors}. See log for details.</info>");
        $output->writeln('<info>Finished Customer Import</info>');
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

        foreach($attributes as $key => $attr) {
            $collection->addAttributeToFilter($attr, ['eq' => $values[$key]]);
        }

        // limit the query to fetch 1 result
        $collection->setPageSize(1,1);

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
        $formattedAddress = [
            'firstname' => null,
            'middlename' => null,
            'lastname' => null,
            'company' => null,
            'street' => null,
            'city' => null,
            'region' => null,
            'region_id' => null,
            'postcode' => null,
            'country_id' => null,
            'telephone' => null,
            'created_at' => null,
            'updated_at' => null
        ];

        $usCountryValues = ['US', 'us', 'USA', 'usa', 'United States', 'united states'];
        $usZipPattern = "%'.05d";

        foreach ($data as $key => $value) {
            switch ($key) {
                case 'firstname':
                case 'middlename':
                case 'lastname':
                case 'company':
                case 'city':
                    $formattedAddress[$key] = ucwords($value);
                    break;

                case 'address1':
                    $formattedAddress['street'] = ucwords($value);
                    break;

                case 'address2':
                    if (!empty($value)) {
                        $formattedAddress['street'] = $formattedAddress['street'] . "\n" . ucwords($value);
                    }
                    break;

                // street address can only contain 2 lines so append these values w/o line breaks
                case 'address3':
                case 'suite':
                    if (!empty($value)) {
                        $formattedAddress['street'] = $formattedAddress['street'] . " " . ucwords($value);
                    }
                    break;

                case 'postcode':
                case 'zip':
                    if (!empty($value)) {
                        if (isset($data['country']) && in_array(strtolower($data['country']), $usCountryValues)) {
                            $formattedAddress['postcode'] = sprintf($usZipPattern, $value);
                        } else {
                            $formattedAddress['postcode'] = $value;
                        }
                    }
                    break;

                case 'region':
                case 'state':
                    if (!isset($value) || $value == '--') {
                        throw new \Exception("Unable to find region by value: '{$value}'");
                    } else {
                        if(isset($data['country']) && in_array($data['country'], $usCountryValues)) {
                            $country = $data['country'];
                            switch (true) {
                                case (strlen($value) == 2):
                                    $region = $this->getRegionByCode($value, $country);
                                    break;
                                case (strlen($value) > 2):
                                    $region = $this->getRegionByName($value, $country);
                                    break;
                                default:
                                    throw new \Exception("Unable to find US region by: {$value}");
                                    break;
                            }

                            if (isset($region) && $region->getId()) {
                                $formattedAddress['region'] = $region->getDefaultName();
                                $formattedAddress['region_id'] = $region->getId();
                            } else {
                                throw new \Exception("Unable to find US region");
                            }
                        } else {
                            $formattedAddress['region'] = ucwords($value);
                        }
                    }
                    break;

                case 'telephone':
                case 'phone':
                    if (empty($value)) {
                        $formattedAddress['telephone'] = '000-000-0000';
                    } else {
                        $formattedAddress['telephone'] = $value;
                    }
                    break;

                case 'country':
                    $country = $this->getCountryByName($value);
                    $formattedAddress['country_id'] = $country['value'];
                    break;

                default:
                    $formattedAddress[$key] = $value;
                    break;
            }
        }

        return $formattedAddress;
    }

    /**
     * Check if a customer address already exists or not within the website
     *
     * @param integer $customerId
     * @param array $addressData
     * @return false|integer # customer id if the customer exists
     */
    public function checkIfCustomerAddressExists($customerId, $addressData)
    {
        /**
         * @todo try to find customer address record by the customer_id (parent_id)
         * and the address data (i.e., firstname, lastname, street, region, postcode)
         */

        $collection = $this->addressFactory->create()->getCollection();
        $collection->addFieldToFilter('parent_id', $customerId)
            ->addFieldToFilter('firstname', $addressData['firstname'])
            ->addFieldToFilter('lastname', $addressData['lastname'])
            ->addFieldToFilter('street', $addressData['street'])
            ->addFieldToFilter('city', $addressData['city'])
            ->addFieldToFilter('region', $addressData['region'])
            ->addFieldToFilter('postcode', $addressData['postcode'])
            ->addFieldToFilter('country_id', $addressData['country_id'])
            ->setPageSize(1,1);

        $address = $collection->getFirstItem();
        // $this->log('SQL: ' . print_r($collection->getSelect()->__toString(), true));

        if ($address && $address->getId()) {
            return $address->getId();
        }

        return false;
    }


    public function getRegionByCode($regionCode, $countryName)
    {
        $country = $this->getCountryByName($countryName);

        $region = $this->regionFactory->create();
        $region = $region->loadByCode($regionCode, $country['value']);

        return $region;
    }

    public function getRegionByName($regionName, $countryName)
    {
        $country = $this->getCountryByName($countryName);

        $region = $this->regionFactory->create();
        $region = $region->loadByName($regionName, $country['value']);

        return $region;
    }

    public function getCountryByName($countryName)
    {
        return $this->_searchCountriesByKeyAndValue('label', $countryName);
    }

    public function getCountryByCode($code)
    {
        return $this->_searchCountriesByKeyAndValue('value', $code);
    }

    protected function _searchCountriesByKeyAndValue($key, $value)
    {
        $countries = $this->getCountries();
        $foundKey = array_search($value, array_column($countries, $key));

        if ($foundKey) {
            return $countries[$foundKey];
        } else {
            throw new \Exception("Unable to find country from list by key: {$key} and value: {$value}");
        }
    }

    public function getCountries()
    {
        return $this->translatedLists->getOptionCountries();
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

    public function isTruthy($value)
    {
        return in_array(strtolower($value), [true, 'true', 'yes', 'y', 1, '1'], true);
    }

    public function isFalsey($value)
    {
        return in_array(strtolower($value), [false, 'false', 'no', 'n', 0, '0'], true);
    }

}
