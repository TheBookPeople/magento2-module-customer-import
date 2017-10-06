<?php

namespace Augustash\CustomerImport\Console\Command;

use Magento\Framework\App\State;
use Magento\Customer\Model\CustomerFactory;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

class CustomerSendEmailCommand extends Command
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
     * @var string
     */
    protected $logPath = '/var/log/customer_send_email.log';

    /**
     * @var \Symfony\Component\Console\Input\InputInterface
     */
    protected $input;

    /**
     * @var \Symfony\Component\Console\Output\OutputInterface
     */
    protected $output;

    /**
     * Info
     * @var array
     */
    protected $info = [
        'email-type' => "Valid values:\n\t'registered' (New Account email template)\n\t'confirmed' (New Account email template)\n\t'confirmation' (New Account email template)\n\t'password-remind' (Password Remind email template)\n\t'password-reset' (Password Reset email template)",
        'info' => 'Display additional information about this command',
        'website-id' => 'Set the website the customer should belong to.',
        'store-id' => 'Set the store view the customer should belong to.',
        'test' => 'If true, will send emails to a developer email address',
        'test-send-to' => 'If test option is true, specify the email address that all emails should be sent to',
        'customer-id' => 'ONLY send the email to the specified customer (skips looping over entire processing)',
        'dry-run' => "Dry run through the process without actually sending emails."
    ];

    // params
    protected $websiteId = 1;
    protected $storeId = 1;
    protected $sendTestEmailsTo = 'josh@augustash.com';

    /**
     * Constructor
     *
     * @param \Magento\Framework\App\State $appState
     * @param \Magento\Customer\Model\CustomerFactory $customerFactory
     */
    public function __construct(State $appState, CustomerFactory $customerFactory)
    {
        $this->appState = $appState;
        $this->customerFactory = $customerFactory;

        parent::__construct();
    }

    protected function configure()
    {
        $this->setName('customer:email:send')
            ->setDescription("Send specified email templates to a single customer by specifying using the customer-id option \n\t\t\t\t\t\tor loop through all the customers and send the email to each individual.");

        // REQUIRED options
        // addOption($name, $shortcut, $mode, $description, $default)
        $this->addOption('email-type', null, InputOption::VALUE_REQUIRED, $this->info['email-type']);

        // OPTIONAL options
        // addOption($name, $shortcut, $mode, $description, $default)
        $this->addOption('info', null, null, $this->info['info']);
        $this->addOption('customer-id', null, InputOption::VALUE_OPTIONAL, $this->info['customer-id']);
        $this->addOption('website-id', null, InputOption::VALUE_OPTIONAL, $this->info['website-id'], 1);
        $this->addOption('store-id', null, InputOption::VALUE_OPTIONAL, $this->info['store-id'], 1);
        $this->addOption('test', null, InputOption::VALUE_OPTIONAL, $this->info['test'], false);
        $this->addOption('test-send-to', null, InputOption::VALUE_OPTIONAL, $this->info['test-send-to'], 'josh@augustash.com');
        $this->addOption('dry-run', null, InputOption::VALUE_OPTIONAL, $this->info['dry-run'], false);

        parent::configure();
    }

    /**
     * @param \Symfony\Component\Console\Input\InputInterface $input
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     * @throws \Magento\Framework\Exception\LocalizedException
     * @throws \Exception
     * @return void
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

        $this->input = $input;
        $this->output = $output;

        if ($input->getOption('info')) {
            echo "email-type:\n\t" . $this->info['email-type'] . PHP_EOL . PHP_EOL;
            echo "info:\n\t" . $this->info['info'] . PHP_EOL . PHP_EOL;
            echo "website-id:\n\t" . $this->info['website-id'] . PHP_EOL . PHP_EOL;
            echo "store-id:\n\t" . $this->info['store-id'] . PHP_EOL . PHP_EOL;
            echo "customer-id:\n\t" . $this->info['customer-id'] . PHP_EOL . PHP_EOL;
            echo "test:\n\t" . $this->info['test'] . PHP_EOL . PHP_EOL;
            echo "test-send-to:\n\t" . $this->info['test-send-to'] . PHP_EOL . PHP_EOL;
            echo "dry-run:\n\t" . $this->info['dry-run'] . PHP_EOL . PHP_EOL;

            echo "\n\nThe log file is at {$this->logPath}" . PHP_EOL . PHP_EOL;
            exit;
        }

        $options = $input->getOptions();

        if (isset($options['test'])) {
            $testMode = $this->isTruthy($options["test"]) ? true : false;
        } else {
            $testMode = false;
        }

        if (isset($options['dry-run'])) {
            $dryRun = $this->isTruthy($options["dry-run"]) ? true : false;
        } else {
            $dryRun = false;
        }

        $emailType = $options['email-type'];

        $customerId = (isset($options['customer-id']))
            ? $options['customer-id']
            : null;

        $websiteId = (isset($options['website-id']))
            ? $options['website-id']
            : $this->websiteId;

        $storeId = (isset($options['store-id']))
            ? $options['store-id']
            : $this->storeId;

        $sendTestEmailsTo = (isset($options['test-send-to']))
            ? $options['test-send-to']
            : $this->sendTestEmailsTo;

        $output->writeln('<info>Starting Send Emails to Customer</info>');
        // $this->log('options: ' . print_r($input->getOptions(), true));

        if ($testMode) {
            $this->log('TEST MODE ENABLED');
            $this->log('$sendTestEmailsTo: ' . var_export($sendTestEmailsTo, true));
            $output->writeln("<info>TEST MODE ENABLED (sending '{$emailType}' emails to: {$sendTestEmailsTo})</info>");
        }

        $this->log('emailType: ' . var_export($emailType, true));
        $this->log('websiteId: ' . var_export($websiteId, true));
        $this->log('storeId: ' . var_export($storeId, true));
        $this->log('dryRun: ' . var_export($dryRun, true));


        if (isset($customerId)) {
            $this->log('customerId: ' . var_export($customerId, true));
            $customer = $this->customerFactory->create()->load($customerId);

            if ($customer && $customer->getId() && $customer->getWebsiteId() == $websiteId) {
                $this->sendEmailToCustomer($customer, $emailType, $storeId, $testMode, $sendTestEmailsTo, $dryRun);
            } else {
                throw new \Exception('Unable to find customer by customer ID: ' . $customerId . ' within website: ' . $websiteId);
            }
        } else {
            $collection = $this->customerFactory->create()->getCollection();
            $collection->addFieldToFilter('website_id', $websiteId);

            foreach ($collection as $customer) {
                $this->sendEmailToCustomer($customer, $emailType, $storeId, $testMode, $sendTestEmailsTo, $dryRun);
            }
        }

        $output->writeln('<info>Finished Sending Emails to Customer(s)</info>');
    }

    /**
     * Determine which customer email template to send
     *
     * @param  \Magento\Customer\Model\Customer $customer
     * @param  string                           $emailType
     * @param  string|integer                   $storeId
     * @param  bool                             $testMode
     * @param  bool                             $sendTestEmailsTo
     * @param  bool                             $dryRun
     * @return void
     */
    public function sendEmailToCustomer(
        $customer,
        $emailType,
        $storeId,
        $testMode = false,
        $sendTestEmailsTo = 'josh@augustash.com',
        $dryRun = false
    )
    {
        try {
            $this->_sendEmailToCustomer($customer, $emailType, $storeId, $testMode, $sendTestEmailsTo, $dryRun);
        } catch (LocalizedException $e) {
            $this->output->writeln($e->getMessage());
            $this->log('[[ EXCEPTION LocalizedException (customer ID: '
                . $customer->getId() . ') ]] ' . $e->getMessage());
        } catch (\Exception $e) {
            $this->output->writeln($e->getMessage());
            $this->log('[[ EXCEPTION (customer ID: '
                . $customer->getId() . ') ]]'  . $e->getMessage());
        }
    }

    /**
     * Determine which customer email template to send
     *
     * @throws \Exception if $emailType is unrecognized
     *
     * @param  \Magento\Customer\Model\Customer $customer
     * @param  string                           $emailType
     * @param  string|integer                   $storeId
     * @param  bool                             $testMode
     * @param  bool                             $sendTestEmailsTo
     * @param  bool                             $dryRun
     * @return void
     */
    protected function _sendEmailToCustomer(
        $customer,
        $emailType,
        $storeId,
        $testMode = false,
        $sendTestEmailsTo = 'josh@augustash.com',
        $dryRun = false
    )
    {
        if ($testMode) {
            $customer->setEmail($sendTestEmailsTo);
        }

        switch ($emailType) {
            case 'registered':
            case 'confirmed':
            case 'confirmation':
                $this->log('sending ' . $emailType  . ' new account email to: ' . $customer->getEmail() . ' | test mode: ' . var_export($testMode, true) . ' | dry run: ' . var_export($dryRun, true));
                if (!$dryRun) {
                    $customer->sendNewAccountEmail($emailType, '', $storeId);
                }
                break;

            case 'password-remind':
                $this->log('sending ' . $emailType  . ' email to: ' . $customer->getEmail() . ' | test mode: ' . var_export($testMode, true) . ' | dry run: ' . var_export($dryRun, true));
                if (!$dryRun) {
                    $customer->sendPasswordReminderEmail();
                }
                break;

            case 'password-reset':
                $this->log('sending ' . $emailType  . ' email to: ' . $customer->getEmail() . ' | test mode: ' . var_export($testMode, true) . ' | dry run: ' . var_export($dryRun, true));
                if (!$dryRun) {
                    $customer->sendPasswordResetConfirmationEmail();
                }
                break;

            default:
                throw new \Exception("Unable to send email to customer because of an unrecognized email type: '{$emailType}'");
                break;
        }
    }

    public function log($info)
    {
        $writer = new \Zend\Log\Writer\Stream(BP . $this->logPath);
        $logger = new \Zend\Log\Logger();
        $logger->addWriter($writer);
        $logger->info($info);
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
