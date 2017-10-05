# Augustash_CustomerImport

## Overview:

Import Customers and Customer Addresses via `php bin/magento` or `n98-magerun2` console commands.

Provides the following console commands:
  + `customer:import`
  + `customer:address:import`
  + `customer:send:email`

## Customer Import

### Command

```bash
$ php bin/magento customer:import
```

### Assumptions:

+ CSV file named `customers.csv` is located in the `<MagentoRoot>/var/import` directory
+ Assumes customers are being imported into the default website (i.e., website_id = 1). You can override this by setting `--website-id=<WEBSITE_ID>`.
+ Assumes the customers are being imported into the default store view (i.e., store_id = 1). You can override this by setting `--store-id=<STORE_VIEW_ID>`.
+ Generates new passwords for customers (can be disabled by `--generate-passwords=false`)
+ (optional) Can send the new account email if `--send-welcome-email=true` or avoid sending emails by setting `--send-welcome-email=false`
+ Log file is located at `<MagentoRoo>/var/log/customer_import.log`

### Options

| Option | Description  | Default |
| -------| -------------| --------|
| info | Display additional information about this command (i.e., logs, filenames, etc.) |  |
| website-id | Set the website the customer should belong to. | 1 |
| store-id | Set the store view the customer should belong to. | 1 |
| generate-passwords | Generate a new password for each customer. | true |
| send-welcome-email | Send the new customer/welcome email to the customer. | false |


For available options and usage examples use the `-h` or `--help` option:

```bash
$ php bin/magento customer:import -h
Usage:
 customer:import [--info] [--generate-passwords[="..."]] [--send-welcome-email[="..."]] [--website-id[="..."]] [--store-id[="..."]]

Options:
 --info                Display additional information about this command (i.e., logs, filenames, etc.)
 --generate-passwords  Generate a new password for each customer. (default: true)
 --send-welcome-email  Send the new customer/welcome email to the customer. (default: false)
 --website-id          Set the website the customer should belong to. (default: 1)
 --store-id            Set the store view the customer should belong to. (default: 1)
 --help (-h)           Display this help message
 --quiet (-q)          Do not output any message
 --verbose (-v|vv|vvv) Increase the verbosity of messages: 1 for normal output, 2 for more verbose output and 3 for debug
 --version (-V)        Display this application version
 --ansi                Force ANSI output
 --no-ansi             Disable ANSI output
 --no-interaction (-n) Do not ask any interactive question
```
## Customer Address Import

### Command

```bash
$ php bin/magento customer:address:import
```

### Assumptions:

+ The customers need to exist in the database before running this command
+ CSV file named `customer_addresses.csv` is located in the `<MagentoRoot>/var/import` directory
+ Assumes customer addresses are being imported into the default website (i.e., website_id = 1). You can override this by setting `--website-id=<WEBSITE_ID>`.
+ Assumes the customer addresses are being imported into the default store view (i.e., store_id = 1). You can override this by setting `--store-id=<STORE_VIEW_ID>`.
+ Log file is located at `<MagentoRoo>/var/log/customer_address_import.log`

### Options

| Option | Description  | Default |
| -------| -------------| --------|
| info | Display additional information about this command (i.e., logs, filenames, etc.) |  |
| website-id | Set the website the customer should belong to. | 1 |
| store-id | Set the store view the customer should belong to. | 1 |
| customer-id-column | Identify which column within the spreadsheet identifies the ID of the customer the address belongs to | customer_id |
| find-customer-by-attribute | Specify which customer attribute should be used to query the database to find a matching customer (i.e., "email", "customer_id", "old_customer_id"). | old_customer_id |


For available options and usage examples use the `-h` or `--help` option:

```bash
$ php bin/magento customer:address:import -h
Usage:
 customer:address:import [--info] [--website-id[="..."]] [--store-id[="..."]] [--customer-id-column[="..."]] [--find-customer-by-attribute[="..."]]

Options:
 --info                        Display additional information about this command (i.e., logs, filenames, etc.)
 --website-id                  Set the website the customer should belong to. (default: 1)
 --store-id                    Set the store view the customer should belong to. (default: 1)
 --customer-id-column          Identify which column within the spreadsheet identifies the ID of the customer the address belongs to (default: "customer_id")
 --find-customer-by-attribute  Specify which customer attribute should be used to query the database to find a matching customer (i.e., "email", "customer_id", "old_customer_id"). (default: "old_customer_id")
 --help (-h)                   Display this help message
 --quiet (-q)                  Do not output any message
 --verbose (-v|vv|vvv)         Increase the verbosity of messages: 1 for normal output, 2 for more verbose output and 3 for debug
 --version (-V)                Display this application version
 --ansi                        Force ANSI output
 --no-ansi                     Disable ANSI output
 --no-interaction (-n)         Do not ask any interactive question

```

## Customer Send Email

### Command

```bash
$ php bin/magento customer:email:send
```

### Assumptions

+ Customers need to exist in the database
+ Throws an exception if no value is passed for `--email-type`
+ If no `customer-id` is provided, the entire customer base for the specified website will be emailed. If `customer-id` has a value and can be found in the database, the email will go to that customer only and skip the rest of the customers.

### Options

| Option | Description  | Default |
| -------| -------------| --------|
| email-type | Valid values: 'registered' (New Account email template), 'confirmed' (New Account email template), 'confirmation' (New Account email template), 'password-remind' (Password Remind email template), 'password-reset' (Password Reset email template) |  |
| info | Display additional information about this command |  |
| website-id | Set the website the customer should belong to. | 1 |
| store-id | Set the store view the customer should belong to. | 1 |
| customer-id | ONLY send the email to the specified customer (skips looping over entire customer collection)|  |
| test | If true, will send emails to a developer email address | false |
| test-send-to | If test option is true, specify the email address that all emails should be sent to | josh@augustash.com |
| dry-run | Dry run through the process without actually sending emails. | false |

For available options and usage examples use the `-h` or `--help` option:

```bash
$ php bin/magento customer:email:send
Usage:
 customer:email:send [--email-type="..."] [--info] [--customer-id[="..."]] [--website-id[="..."]] [--store-id[="..."]] [--test[="..."]] [--test-send-to[="..."]] [--dry-run[="..."]]

Options:
 --email-type          Valid values:
                       	'registered' (New Account email template)
                       	'confirmed' (New Account email template)
                       	'confirmation' (New Account email template)
                       	'password-remind' (Password Remind email template)
                       	'password-reset' (Password Reset email template)
 --info                Display additional information about this command
 --customer-id         ONLY send the email to the specified customer (skips looping over entire processing)
 --website-id          Set the website the customer should belong to. (default: 1)
 --store-id            Set the store view the customer should belong to. (default: 1)
 --test                If true, will send emails to a developer email address (default: false)
 --test-send-to        If test option is true, specify the email address that all emails should be sent to (default: "josh@augustash.com")
 --dry-run             Dry run through the process without actually sending emails. (default: false)
 --help (-h)           Display this help message
 --quiet (-q)          Do not output any message
 --verbose (-v|vv|vvv) Increase the verbosity of messages: 1 for normal output, 2 for more verbose output and 3 for debug
 --version (-V)        Display this application version
 --ansi                Force ANSI output
 --no-ansi             Disable ANSI output
 --no-interaction (-n) Do not ask any interactive question
```

## Installation

### Composer

```bash
$ composer config repositories.augustash-customerimport vcs https://github.com/augustash/magento2-module-customer-import.git
$ composer require augustash/module-customerimport:~1.0.5
```
