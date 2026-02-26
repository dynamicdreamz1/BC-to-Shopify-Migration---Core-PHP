<?php

require_once 'config.php';
require_once 'BigCommerce.php';
require_once 'Shopify.php';

error_reporting(1);
set_time_limit(0);
ini_set('max_execution_time', 0);

$bigcommerce = new BigCommerce(
    BC_STORE_HASH,
    BC_ACCESS_TOKEN,
    BC_VERSION
);

$shopify = new Shopify(
    SH_SHOP_URL,
    SH_ACCESS_TOKEN,
    SH_VERSION
);

$customerData = $bigcommerce->getAllCustomers() ?? [];
// echo "<pre>";
// print_r($customerData);die;
if(!empty($customerData)){
    $customerGroups = [
        25 => "Tax Exempt",
        26 => "Subscriptions",
        27 => "Davis Houk Mechanical",
        28 => "Scherbon Consolidated",
        29 => "Mount Sinai Health Systems"
    ];

    // echo "<pre>";
    // print_r($customerGroups);die;
    foreach ($customerData as $c =>  $customer) {
        // if($c != 11){
        //     continue;
        // }
        $customerId = $customer['id'];
        $importedCustomers = getImportedCustomers();
        if (isset($importedCustomers[$customerId])) {
            writeLog("Customer already imported: BC ID {$customerId}" , $type = 'INFO');
            continue; // ✅ skip product:
        }
        /* ===============================
           CREATE SHOPIFY CUSTOMER
        =============================== */
        $emailConsent = [
            "state" => !empty($customer['accepts_product_review_abandoned_cart_emails'])
                ? "subscribed"
                : "not_subscribed",
            "opt_in_level" => "single_opt_in"
        ];
        
        $customerPayload = [
            "customer" => [
                "first_name" => $customer['first_name'] ?? '',
                "last_name"  => $customer['last_name'] ?? '',
                "email"      => $customer['email'] ?? '',
                // "phone"      => $customer['phone'] ?? '',
                "note"      => $customer['notes'] ?? '',
                "verified_email" => true,
                "accepts_marketing" => true,
                "tags" => 'OLD__'.$customerId.'__ID',
                "tax_exempt" => false,
                "send_email_welcome" => false,
                "password" => CUSTOMER_PASSWORD,
                "password_confirmation" => CUSTOMER_PASSWORD,
                "email_marketing_consent" => $emailConsent ?? [],
            ]
        ];

        $metafields = [];
        if (isValidShopifyPhone($phone)) {
            $shopifyPhone = $phone;
        } else {
            $shopifyPhone = null;
            // store invalid phone in metafield
            if (!empty($phone)) {
                $metafields[] = buildMetafield(
                    'phone',
                    $phone
                );
            }
        }
        $customerPayload['customer']['phone'] = $shopifyPhone ?? '';
        $customerGroupId = $customer['customer_group_id'] ?? '';
        $customerGroupName = $customerGroups[$customerGroupId] ?? '';
        
        

        addMetafieldIfNotEmpty(
            $metafields,
            'registration_ip_address',
            $customer['registration_ip_address'] ?? ''
        );

        addMetafieldIfNotEmpty(
            $metafields,
            'tax_exempt_category',
            $customer['tax_exempt_category'] ?? ''
        );

        addMetafieldIfNotEmpty(
            $metafields,
            'accepts_product_review_abandoned_cart_emails',
            $customer['accepts_product_review_abandoned_cart_emails'] ?? '',
            'boolean'
        );

        addMetafieldIfNotEmpty(
            $metafields,
            'customer_group',
            $customerGroupName ?? null
        );
        if(!empty($metafields)){
            $customerPayload["customer"]["metafields"] = $metafields ?? [];
        }
        try {
            $dataCustomer = $shopify->createCustomer($customerPayload);
        
            if (empty($dataCustomer['customer']['id'])) {
                echo "Customer failed: {$customerId}\n";
                writeLog('Customer failed - - BC id='.$customerId, $type = 'ERROR');
                continue;
            }
            $shopifyCustomerId = $dataCustomer['customer']['id'] ?? '';
        
            $addresses = $bigcommerce->getCustomerAddressesByCustomerId($customerId);
        
            if (empty($addresses)) {
                echo "No addresses found\n";
                writeLog('No addresses found - - BC id='.$customerId, $type = 'ERROR');
                continue;
            }
            
            foreach ($addresses as $cKey =>  $addr) {
                $addressPayload = [
                    "address" => [
                        "first_name" => $addr['first_name'] ?? '',
                        "last_name"  => $addr['last_name'] ?? '',
                        "company"    => $addr['company'] ?? '',
                        "address1"   => $addr['address1'] ?? '',
                        "address2"   => $addr['address2'] ?? '',
                        "city"       => $addr['city'] ?? '',
                        "province"   => $addr['state_or_province'] ?? '',
                        "country"    => $addr['country'] ?? '',
                        "zip"        => $addr['postal_code'] ?? '',
                        "phone"      => $addr['phone'] ?? '',
                    ]
                ];
                $addressesRes = $shopify->createCustomerAddress(
                    $shopifyCustomerId,
                    $addressPayload
                );
                // Shopify rate limit safety
                sleep(2);
            }
            echo "Customer Created: BC {$customerId} → Shopify {$shopifyCustomerId}\n";
            writeLog('Customer been created - Shopidy ID - '. $dataCustomer['customer']['id']. ' - BC id='.$customerId, $type = 'INFO');
            saveImportedCustomer($customerId, $shopifyCustomerId);
        } catch(\Exception $e){
            writeLog('Execption error - '. $e->getMessage().' - BC id='.$customerId. ' -- Email: '. $customer['email'], $type = 'ERROR');
            continue;
        }
        
    }
    echo "Customer Imported ✔\n";
    die;
}

function addMetafieldIfNotEmpty(&$metafields, $key, $value, $type = 'single_line_text_field')
{
    // skip null, empty string, empty array
    if (!isset($value) || $value === '' || $value === [] || $value === null) {
        return;
    }

    $metafields[] = buildMetafield($key, $value, $type);
}


function buildMetafield($key, $value, $type = 'single_line_text_field', $namespace = 'custom')
{
    if ($value === '' || $value === null) {
        return null;
    }

    return [
        "namespace" => $namespace,
        "key"       => formatMetafieldKey($key),
        "value"     => (string)$value,
        "type"      => $type
    ];
}

function formatMetafieldKey($key)
{
    $key = strtolower(trim($key));
    $key = preg_replace('/[^a-z0-9]+/', '_', $key);
    return trim($key, '_');
}

function writeLog($message, $type = 'INFO', $filename = 'app.log')
{
    // ALWAYS resolve absolute path safely
    $logDir = realpath(__DIR__) . DIRECTORY_SEPARATOR . 'customer/logs';

    // Create logs directory if not exists
    if (!is_dir($logDir)) {

        if (!mkdir($logDir, 0777, true) && !is_dir($logDir)) {
            die('Unable to create logs directory: ' . $logDir);
        }
    }

    $logFile = $logDir . DIRECTORY_SEPARATOR . $filename;

    $date = date('Y-m-d H:i:s');
    $logMessage = "[$date] [$type] $message" . PHP_EOL;

    file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);
}



function getImportedCustomers()
{
    $file = 'customer/imported_customers.json';

    if (!file_exists($file)) {
        return [];
    }

    $data = file_get_contents($file);
    return json_decode($data, true) ?: [];
}

function saveImportedCustomer($bcProductId, $shopifyProductId)
{
    $file = 'customer/imported_customers.json';

    $products = getImportedCustomers();

    $products[$bcProductId] = [
        'shopify_id' => $shopifyProductId,
        'imported_at' => date('Y-m-d H:i:s')
    ];

    file_put_contents($file, json_encode($products, JSON_PRETTY_PRINT));
}

function isValidShopifyPhone($phone)
{
    if (empty($phone)) {
        return false;
    }

    // remove spaces, brackets, dashes
    $phone = preg_replace('/[^0-9+]/', '', $phone);

    // must start with + and contain 8–15 digits
    return preg_match('/^\+[1-9]\d{7,14}$/', $phone);
}
