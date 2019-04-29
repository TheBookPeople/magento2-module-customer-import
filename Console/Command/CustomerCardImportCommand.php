<?php

namespace Augustash\CustomerImport\Console\Command;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\App\State;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\File\Csv;
use Magento\Framework\Filesystem\Io\File;
use Magento\Vault\Api\Data\PaymentTokenInterface;
use Magento\Vault\Api\Data\PaymentTokenInterfaceFactory;
use Magento\Vault\Api\PaymentTokenRepositoryInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Magento\Vault\Api\PaymentTokenManagementInterface;

class CustomerCardImportCommand extends Command
{



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

    ];

    protected $websiteId = 1;
    protected $storeId = 1;



    /**
     * @var PaymentTokenInterfaceFactory
     */
    private $paymentTokenFactory;

    /**
     * @var PaymentTokenRepositoryInterface
     */
    protected $paymentTokenRepository;

    /**
     * @var EncryptorInterface
     */
    private $encryptor;

    /**
     * @var PaymentTokenManagementInterface
     */
    private $paymentTokenManagement;


    /**
     * Constructor
     *
     * @param \Magento\Customer\Api\CustomerRepositoryInterface $customerRepositoryInterface
     * @param PaymentTokenInterfaceFactory $paymentTokenFactory
     * @param PaymentTokenRepositoryInterface $paymentTokenRepository
     * @param PaymentTokenManagementInterface $paymentTokenManagement
     * @param \Magento\Framework\File\Csv $fileCsv
     * @param \Magento\Framework\App\Filesystem\DirectoryList $directoryList
     * @param \Magento\Framework\Filesystem\Io\File $io
     * @param EncryptorInterface $encryptor
     */
    public function __construct(
        \Magento\Customer\Api\CustomerRepositoryInterface $customerRepositoryInterface,
        PaymentTokenInterfaceFactory $paymentTokenFactory,
        PaymentTokenRepositoryInterface $paymentTokenRepository,
        PaymentTokenManagementInterface $paymentTokenManagement,
        Csv $fileCsv,
        DirectoryList $directoryList,
        File $io,
        EncryptorInterface $encryptor
    ) {
        $this->customerRepositoryInterface = $customerRepositoryInterface;
        $this->paymentTokenFactory = $paymentTokenFactory;
        $this->paymentTokenRepository = $paymentTokenRepository;
        $this->directoryList = $directoryList;
        $this->fileCsv = $fileCsv;
        $this->io = $io;
        $this->encryptor = $encryptor;
        $this->paymentTokenManagement = $paymentTokenManagement;
        // create the var/import directory if it doesn't exist
        $this->io->mkdir($this->directoryList->getRoot() . $this->csvFilePath, 0775);

        parent::__construct();
    }

    protected function configure()
    {
        $this->setName('customer:card:import')
            ->setDescription("Import Stored Cards from a CSV file ({$this->csvFileName}) located in the {$this->csvFilePath} directory.");

        $this->addOption('info', null, null, $this->info['info']);
        $this->addOption('filename', null, InputOption::VALUE_OPTIONAL, $this->info['filename'], 'stored_cards.csv');
        $this->addOption('website-id', null, InputOption::VALUE_OPTIONAL, $this->info['website-id'], 1);
        $this->addOption('store-id', null, InputOption::VALUE_OPTIONAL, $this->info['store-id'], 1);
        $this->addOption('customer-id-column', null, InputOption::VALUE_OPTIONAL, $this->info['customer-id-column'], 'USERS_ID');

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
        $appState = ObjectManager::getInstance()->get(\Magento\Framework\App\State::class);
        try {
            $appState->setAreaCode('adminhtml');
        } catch (\Exception $e) {
            try {
                $appState->setAreaCode('adminhtml');
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


            echo "\n\nStored Card Import expects the {$this->csvFileName} file to be located in the {$this->csvFilePath} directory.\n\nThe log file is at {$this->logPath}" . PHP_EOL . PHP_EOL;
            exit;
        }

        $options = $input->getOptions();

        $this->csvFileName = (isset($options['filename'])) ? $options['filename'] : $this->csvFilename;

        $websiteId = (isset($options['website-id'])) ? $options['website-id'] : $this->websiteId;
        $storeId = (isset($options['store-id'])) ? $options['store-id'] : $this->storeId;
        $customerIdColumn = isset($options['customer-id-column']) ? $options['customer-id-column'] : $this->customerIdColumn;

        $output->writeln('<info>Starting Stored Card Import</info>');

        $output->writeln('filename: ' . var_export($this->csvFileName, true));
        $output->writeln('websiteId: ' . var_export($websiteId, true));
        $output->writeln('storeId: ' . var_export($storeId, true));

        $csvData = $this->fileCsv->getData($this->getCsvFilePath());
        $csvHeaders = array_values(array_shift($csvData));

        $existingStoredCards = [];
        $rowsWithErrors = [];
        foreach ($csvData as $key => $row) {

            //Skip Empty Row
            if (sizeof($row) == 0) {
                continue;
            }

            try {
                $cardData = array_combine($csvHeaders, $row);
                $customerId = array_values($cardData)[0];
                //$customerId = $cardData['USERS_ID'];
                $formattedCardData = $this->mapData($cardData);


                $cardToken = $formattedCardData['CARDTOKEN'];
                $publicHash = $this->encryptor->getHash($cardToken);
                $existingCardToken = false;
                if ($this->paymentTokenManagement->getByPublicHash($publicHash,$customerId)) {
                    $existingCardToken = true;
                }

                if ($existingCardToken) {
                    $output->writeln('Stored card ' . $cardToken . ' already exists for customer '.$customerId );
                    // $existingStoredCards[$key] = $cardData;
                } else {
                    if (isset($formattedCardData['CARDTOKEN']) &&
                        isset($formattedCardData['CARDBRAND']) &&
                        isset($formattedCardData['CARDNUMBER']) &&
                        isset($formattedCardData['EXPIRYYEAR']) &&
                        isset($formattedCardData['EXPIRYMONTH']) &&
                        isset($formattedCardData['CURRENCY'])
                    ) {

                        try {
                            if (!$this->customerRepositoryInterface->getById($customerId)) {
                                $output->writeln("Customer ".$customerId ." does not exist skipping");
                                continue;
                            }
                        }catch (\Magento\Framework\Exception\NoSuchEntityException $e){
                            $output->writeln("Customer ".$customerId ." does not exist skipping");
                            continue;
                        }


                        $output->writeln('Adding card ' . $formattedCardData['CARDNUMBER'] . ' for customer ' . $customerId);
                        // create new stored card
                        $cardToken = $formattedCardData['CARDTOKEN'];
                        $cardBrand = $formattedCardData['CARDBRAND'];
                        $cardNumber = $formattedCardData['CARDNUMBER'];
                        $currency = $formattedCardData['CURRENCY'];
                        $expiryDate = sprintf('%02d', $formattedCardData['EXPIRYMONTH']) . substr($formattedCardData['EXPIRYYEAR'], 2, 2) ;


                        $paymentToken = $this->paymentTokenFactory->create();
                        $paymentToken->setPaymentMethodCode('tns');
                        $paymentToken->setGatewayToken($cardToken);
                        $paymentToken->setExpiresAt($this->getExpirationDate($expiryDate));
                        $paymentToken->setTokenDetails($this->convertDetailsToJSON([
                            'type' => $cardBrand,
                            'maskedCC' => $cardNumber,
                            'expirationDate' => $expiryDate,
                            'currency' => $currency
                        ]));

                        $paymentToken->setCustomerId($customerId);
                        $paymentToken->setPublicHash($publicHash);

                        $this->paymentTokenRepository->save($paymentToken);
                        $output->writeln('Stored card saved with token ' . $cardToken);

                    } else {
                        $output->writeln('Formatted card data incomplete');
                        $output->writeln('CARDTOKEN: ' . isset($formattedCardData['CARDTOKEN']));
                        $output->writeln('CARDBRAND: ' . isset($formattedCardData['CARDBRAND']));
                        $output->writeln('CARDNUMBER: ' . isset($formattedCardData['CARDNUMBER']));
                        $output->writeln('EXPIRYYEAR: ' . isset($formattedCardData['EXPIRYYEAR']));
                        $output->writeln('EXPIRYMONTH: ' . isset($formattedCardData['EXPIRYMONTH']));
                        $output->writeln('CURRENCY: ' . isset($formattedCardData['CURRENCY']));

                        // $rowsWithErrors[$key] = $cardData;
                    }
                }
                // }
            } catch (LocalizedException $e) {
                $output->writeln($e->getMessage());
            } catch (\Exception $e) {
                $output->writeln('Not able to import stored cards because: ');
                $output->writeln($e->getMessage());
            }
        }

        $countExistingStoredCards = count($existingStoredCards);
        $countRowsWithErrors = count($rowsWithErrors);

        $output->writeln('============================');
        $output->writeln('Existing Stored Cards (skipped): ' . $countExistingStoredCards);
        $output->writeln('============================');


        $output->writeln('============================');
        $output->writeln('Rows with errors (skipped): ' . $countRowsWithErrors);
        $output->writeln('============================');
        $output->writeln(print_r($rowsWithErrors, true));

        $output->writeln("<info>Existing Stored Cards (skipped): {$countExistingStoredCards}</info>");
        $output->writeln("<info>Rows with errors (skipped): {$countRowsWithErrors}. See log for details.</info>");
        $output->writeln('<info>Finished Stored Card Import</info>');
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
            'EXPIRYMONTH' => null,
            'CURRENCY'=> null
        ];

        foreach ($data as $key => $value) {
            switch ($key) {
                case 'CARDTOKEN':
                case 'CARDBRAND':
                case 'EXPIRYYEAR':
                case 'EXPIRYMONTH':
                case 'EXPIRYMONTH':
                case 'CURRENCY':
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



    public function getCsvFilePath()
    {
        return $this->directoryList->getRoot() . $this->csvFilePath . DIRECTORY_SEPARATOR . $this->csvFileName;
    }

    /**
     * @return string
     */
    private function getExpirationDate(string $expiry)
    {


        $month = $this->getExpirationMonth($expiry);
        $year = $this->getExpirationYear($expiry);
        $expDate = new \DateTime($year . '-' . $month  . '-' . '01' . ' ' . '00:00:00', new \DateTimeZone('UTC'));
        $expDate->add(new \DateInterval('P1M'));
        return $expDate->format('Y-m-d 00:00:00');
    }

    /**
     * @return string
     */
    private function getExpirationMonth(string $expiry)
    {
        return substr($expiry, 0, 2);
    }

    /**
     * @return string
     */
    private function getExpirationYear(string $expiry)
    {
        return substr($expiry, 2);
    }

    /**
     * Convert payment token details to JSON
     * @param array $details
     * @return string
     */
    private function convertDetailsToJSON($details)
    {
        $json = \Zend_Json::encode($details);
        return $json ? $json : '{}';
    }
}
