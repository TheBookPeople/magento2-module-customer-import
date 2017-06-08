<?php

namespace Augustash\CustomerImport\Console\Command;

use Magento\Framework\App\State;
use Magento\Customer\Model\CustomerFactory;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem\Io\File;
use Magento\Framework\File\Csv;
use Magento\Framework\Math\Random;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

class CustomerImportCommand extends Command
{
    /**
     * @var \Magento\Framework\App\State
     */
    protected $appState;

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
     * CSV File Name
     * @var string
     */
    protected $csvFileName = 'customers.csv';

    /**
     * CSV rile path (relative to Magento root directory)
     * @var string
     */
    protected $csvFilePath = '/var/import';

    /**
     * @var string
     */
    protected $logPath = '/var/log/customer_import.log';

    /**
     * @var array
     */
    protected $customAttributes = [];

    /**
     * Info
     * @var array
     */
    protected $info = ['info' => 'Display additional information about this command (i.e., logs, filenames, etc.)',
        'generate-passwords' => 'Generate a new password for each customer.',
        'send-welcome-email' => 'Send the new customer/welcome email to the customer.',
        'website-id' => 'Set the website the customer should belong to.',
        'store-id' => 'Set the store view the customer should belong to.',
    ];


    // params
    protected $generatePasswords = true;
    protected $sendWelcomeEmail = false;
    protected $websiteId = 1;
    protected $storeId = 1;

    /**
     * Constructor
     *
     * @param \Magento\Framework\App\State $appState
     * @param \Magento\Customer\Model\CustomerFactory $customerFactory
     * @param \Magento\Framework\File\Csv $fileCsv
     * @param \Magento\Framework\App\Filesystem\DirectoryList $directoryList
     * @param \Magento\Framework\Filesystem\Directory\WriteFactory $writeFactory
     * @param \Magento\Framework\Filesystem\Io\File $io
     */
    public function __construct(
        State $appState,
        CustomerFactory $customerFactory,
        Csv $fileCsv,
        DirectoryList $directoryList,
        File $io,
        Random $random
    )
    {
        $this->appState = $appState;
        $this->customerFactory = $customerFactory;
        $this->fileCsv = $fileCsv;
        $this->directoryList = $directoryList;
        $this->io = $io;
        $this->random = $random;

        // create the var/import directory if it doesn't exist
        $this->io->mkdir($this->directoryList->getRoot() . $this->csvFilePath , 0775);

        parent::__construct();
    }

    protected function configure()
    {
        $this->setName('customer:import')
            ->setDescription("Import Customers from a CSV file ({$this->csvFileName}) located in the {$this->csvFilePath} directory.");

        // addOption($name, $shortcut, $mode, $description, $default)
        $this->addOption('info', null, null, $this->info['info']);
        $this->addOption('generate-passwords', null, InputOption::VALUE_OPTIONAL, $this->info['generate-passwords'], true);
        $this->addOption('send-welcome-email', null, InputOption::VALUE_OPTIONAL, $this->info['send-welcome-email'], false);
        $this->addOption('website-id', null, InputOption::VALUE_OPTIONAL, $this->info['website-id'], 1);
        $this->addOption('store-id', null, InputOption::VALUE_OPTIONAL, $this->info['store-id'], 1);

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
                $this->appState->setAreaCode('adminhtml');
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
            echo "generate-passwords:\n\t" . $this->info['generate-passwords'] . PHP_EOL . PHP_EOL;
            echo "send-welcome-email:\n\t" . $this->info['send-welcome-email'] . PHP_EOL . PHP_EOL;
            echo "website-id:\n\t" . $this->info['website-id'] . PHP_EOL . PHP_EOL;
            echo "store-id:\n\t" . $this->info['store-id'] . PHP_EOL . PHP_EOL;

            echo "\n\nCustomer Import expects the {$this->csvFileName} file to be located in the {$this->csvFilePath} directory.\n\nThe log file is at {$this->logPath}" . PHP_EOL;
            exit;
        }

        $options = $input->getOptions();

        if (isset($options['generate-passwords'])) {
            $generatePasswords = $this->isTruthy($options["generate-passwords"]) ? true : false;
        } else {
            $generatePasswords = $this->generatePasswords;
        }

        if (isset($options['send-welcome-email'])) {
            $sendWelcomeEmail = $this->isTruthy($options['send-welcome-email']) ? true : false;
        } else {
            $sendWelcomeEmail = $this->sendWelcomeEmail;
        }

        $websiteId = (isset($options['website-id'])) ? $options['website-id'] : $this->websiteId;
        $storeId = (isset($options['store-id'])) ? $options['store-id'] : $this->storeId;

        $output->writeln('<info>Starting Customer Import</info>');

        // $this->log('options: ' . print_r($input->getOptions(), true));
        $this->log('generatePasswords: ' . var_export($generatePasswords, true));
        $this->log('sendWelcomeEmail: ' . var_export($sendWelcomeEmail, true));
        $this->log('websiteId: ' . var_export($websiteId, true));
        $this->log('storeId: ' . var_export($storeId, true));

        $csvData = $this->fileCsv->getData($this->getCsvFilePath());
        $headers = array_values(array_shift($csvData));

        $existingCustomers = [];
        $rowsWithErrors = [];
        foreach($csvData as $key => $row) {
            try {

                $customerData = array_combine($headers, $row);

                $customer = $this->customerFactory->create();

                $exists = $this->checkIfCustomerExists($customerData['email'], $websiteId);

                if ($exists) {
                    $existingCustomers[$key] = $customerData;
                } else {
                    if (isset($customerData['email']) && isset($customerData['firstname']) && isset($customerData['lastname'])) {
                        // create new customer
                        $customer->setData('email', strtolower($customerData['email']));
                        $customer->setData('firstname', $customerData['firstname']);
                        $customer->setData('lastname', $customerData['lastname']);
                        $customer->setData('is_active', true);
                        $customer->setData('website_id', $websiteId);
                        $customer->setData('store_id', $storeId);

                        $optionalValues = ['group_id', 'created_at', 'old_customer_id', 'is_professional'];
                        foreach($optionalValues as $attr) {
                            if (isset($customerData[$attr])) {
                                $customer->setData($attr, $customerData[$attr]);
                            }
                        }

                        foreach($this->getCustomAttributes() as $attr) {
                            if (isset($customerData[$attr])) {
                                $customer->setData($attr, $customerData[$attr]);
                            }
                        }

                        if ($generatePasswords) {
                            $customer->setPassword($this->random->getRandomString(10));
                        } else {
                            if (isset($customerData['password'])) {
                                $customer->setPassword($customerData['password']);
                            }
                        }

                        // save the customer
                        $customer->save();

                        if ($sendWelcomeEmail) {
                            $customer->sendNewAccountEmail();
                        }
                    } else {
                        $rowsWithErrors[$key] = $customerData;
                    }
                }
            } catch (LocalizedException $e) {
                $rowsWithErrors[$key] = $customerData;
                $output->writeln($e->getMessage());
            } catch (\Exception $e) {
                $rowsWithErrors[$key] = $customerData;
                $output->writeln('Not able to import customers');
                $output->writeln($e->getMessage());
            }
        }


        $countExistingCustomers = count($existingCustomers);
        $countRowsWithErrors = count($rowsWithErrors);


        $this->log('============================');
        $this->log('Existing Customers (skipped): ' . $countExistingCustomers);
        $this->log('============================');
        // $this->log(print_r($existingCustomers, true));

        $this->log('============================');
        $this->log('Rows with errors (skipped): ' . $countRowsWithErrors);
        $this->log('============================');
        $this->log(print_r($rowsWithErrors, true));


        $output->writeln("<info>Existing Customers (skipped): {$countExistingCustomers}</info>");
        $output->writeln("<info>Rows with errors (skipped): {$countRowsWithErrors}. See log for details.</info>");
        $output->writeln('<info>Finished Customer Import</info>');
    }

    /**
     * Check if a customer already exists or not within the website
     *
     * @param string $email
     * @param integer $websiteId
     * @return false|integer # customer id if the customer exists
     */
    public function checkIfCustomerExists($email, $websiteId)
    {
        $customer = $this->customerFactory->create();
        $customer->setWebsiteId($websiteId);
        $customer->loadByEmail($email);

        if ($customerId = $customer->getId()) {
            return $customerId;
        } else {
            return false;
        }
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
