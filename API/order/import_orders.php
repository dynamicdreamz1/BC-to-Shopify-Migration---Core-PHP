<?php
error_reporting(1);
set_time_limit(0);
ini_set('max_execution_time', 0);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../BigCommerce.php';
require_once __DIR__ . '/../Shopify.php';

try {
    $file = 'orders-2026-02-25.csv';
    if (!file_exists($file)) {
        die("CSV not found");
    }
    $bigcommerce = new BigCommerce(
        BC_STORE_HASH,
        BC_ACCESS_TOKEN,
        'v2'
    );
   
    $shopify = new Shopify(
        SH_SHOP_URL,
        SH_ACCESS_TOKEN,
        SH_VERSION
    );
    $handle = fopen($file, 'r');
    $headers = fgetcsv($handle); // first row headers
    $orders['order'] = [];
    while (($row = fgetcsv($handle, 0, ",")) !== false) {
        $order = array_combine($headers, $row);
        $orderId =  $order['Order ID'] ?? '';
        
        $importedOrders = getImportedOrder();
        if (isset($importedOrders[$orderId])) {
            writeLog("Order already imported: BC ID {$orderId}" , $type = 'INFO');
            continue; // ✅ skip product
        }

        $customer = [];

        if (!empty($order['Customer Email'])) {
            $customer['email'] = $order['Customer Email'];
        }
        
        if (!empty($order['Customer Name'])) {
            $customer['first_name'] = $order['Customer Name'];
        }
        
        if (!empty($order['Customer Phone'])) {
            $customer['phone'] = $order['Customer Phone'];
        }
        
        // Only attach customer if at least one value exists
        if (!empty($customer)) {
            $orders['order']['customer'] = $customer;
        }
       
        $orders['order']['phone'] =  $order['Customer Phone'] ?? "";
        $orderProducts = $bigcommerce->getOrderProductsById($orderId);
        $orderAPIData = $bigcommerce->getOrderById($orderId);
        $lineItems = $orderProducts ?? [];
        $createDate = convertToShopifyDate($orderAPIData['date_created'] ?? '');
        $modifiedDate = convertToShopifyDate($orderAPIData['date_modified'] ?? '');
        $orderDate = convertDateToYmd($order['Order Date'] ?? '') ?? '';
        $orders['order']['tags'] = "OLD__".$orderId."__ID, CREATED__".$createDate."__DATE, UPDATED__".$modifiedDate."__DATE, ORG__".$orderDate."__DATE, BC-CUSTOMER__".($order['Customer ID'])."__ID";
        $orders['order']['send_receipt'] = false;
        $orders['order']['send_fulfillment_receipt'] = false;
        $requiresShipping = false;
        $orders['order']['created_at'] = $createDate;
        $orders['order']['processed_at'] = $createDate;
        $orders['order']['updated_at'] = $modifiedDate;
        $lineItemArray = [];
        $variantMap = [];
        if (($handleTwo = fopen('shopify_product_variant_map.csv', 'r')) !== false) {
            $headersVariants = fgetcsv($handleTwo);

            while (($row = fgetcsv($handleTwo, 50000, ',')) !== false) {
                $data = array_combine($headersVariants, $row);
                $variantMap[$data['sku']] = $data['shopify_variant_id'];
            }
            fclose($handleTwo);
        }
        if(!empty($lineItems)){
            foreach($lineItems as $item){
                $sku = $item['sku'] ?? '';
                if($sku !== "" &&  $sku !== null){
                    $variantId = '';
                    $fileOrderVariants = 'shopify_product_variant_map.csv';
                    if (!file_exists($fileOrderVariants) || !is_readable($fileOrderVariants)){
                        return false;
                    }
                    // if (($handleTwo = fopen($fileOrderVariants, 'r')) !== false){
                    //     $headersVariants = fgetcsv($handleTwo); // first row headers
                    //     while (($produtVariantData = fgetcsv($handleTwo, 50000, ',')) !== false){
                    //         $variantsCSVData = array_combine($headersVariants, $produtVariantData);
                    //         if($sku == $variantsCSVData['sku']){
                    //             $variantId = $variantsCSVData['shopify_variant_id'];
                    //             break;
                    //         }
                    //     }
                    // }
                    $variantId = $variantMap[$sku] ?? null;
                    $priceExTax = (float) ($item['total_ex_tax'] ?? 0);
                    $tax   = (float) ($item['total_tax'] ?? 0);

                    $rate = 0;
                    if ($price > 0) {
                        $rate = $tax / $priceExTax;
                    }
                    if($variantId != '' && $variantId != null){
                        $lineItemArray[] = [
                            'variant_id' =>  $variantId ?? "", 
                            'quantity' => $item['quantity'] ?? 0,
                            'price' => $item['total_ex_tax'] ?? 0,
                            'tax_lines'  => [
                                [
                                    'title' => 'Tax',
                                    'price' => number_format($tax, 2, '.', ''),
                                    'rate'  => round($rate, 4) // Shopify expects decimal
                                ]
                            ],
                        ];
                    } else {
                        $tax = 0;
                        if($productTotalPrice > 0  &&  $productOriginalPrice > 0){
                            $tax = ((float) $productTotalPrice - (float) $productOriginalPrice);
                        }
                        $lineItemArray[] = [
                            'quantity' => $item['quantity'] ?? 0,
                            'title' => $item['name'] ?? '',
                            'price' => $item['total_inc_tax'] ?? 0,
                            'tax_lines'  => [
                                [
                                    'title' => 'Tax',
                                    'price' => number_format($tax, 2, '.', ''),
                                    'rate'  => round($rate, 4) // Shopify expects decimal
                                ]
                            ],
                        ];
                    }
                }
                if (($item['type'] ?? '') === 'physical') {
                    $requiresShipping = true;
                    break;
                }
                sleep(5); 
            }
            // echo "Hiiii";
            // echo "<pre>";
            // print_r($lineItemTypes);
            // die;
        }
        $orders['order']['line_items'] = $lineItemArray ?? [];
        $orders['order']['currency'] = $order['Order Currency Code'] ?? ORDER_CURRENCY;
        $orders['order']['subtotal_price'] = $order['Subtotal (inc tax)'] ?? 0;
        $orders['order']['total_price'] = $order['Order Total (inc tax)'] ?? 0;
        $orders['order']['shipping_lines'] = [
            [
                "price" =>  $order['Shipping Cost (inc tax)'] ?? 0,
                "title" => "Shipping Tax"

            ]
        ];
        $totalDiscount  = (float)$orderAPIData['discount_amount'] ?? 0;
        $coupons = $bigcommerce->getOrderCoupons($orderId);
        if (!empty($coupons)) {

            foreach ($coupons as $coupon) {
        
                $order['order']['discount_codes'][] = [
                    "code"   => $coupon['code'],
                    "amount" => number_format($coupon['amount'], 2, '.', ''),
                    "type"   => ($coupon['type'] == 2)
                                ? 'percentage'
                                : 'fixed_amount'
                ];
            }
        }
        
        // IMPORTANT
        $orders['order']['total_discounts'] = convertFloatRound($totalDiscount);
        $orders['order']['shipping_method'] = $order['Ship Method'] ?? "";
        // $order['order']['shipping_rate'] = $discountRate;
        $orders['order']['email'] = $order['Billing Email'] ?? '';
        $shippingAddress = [];

        $shippingAddress = [
            'first_name' => $order['Shipping First Name'] ?? '',
            'last_name'  => $order['Shipping Last Name'] ?? '',
            'company'    => $order['Shipping Company'] ?? '',
            'address1'   => $order['Shipping Street 1'] ?? '',
            'address2'   => $order['Shipping Street 2'] ?? '',
            'zip'        => $order['Shipping Zip'] ?? '',
            'city'       => $order['Shipping Suburb'] ?? '', // ✅ Suburb → City
            'province'   => $order['Shipping State'] ?? '',
            'country'    => $order['Shipping Country'] ?? '',
            'phone'      => $order['Shipping Phone'] ?? '',
        ];

        $billingAddress = [
            'first_name' => $order['Billing First Name'] ?? '',
            'last_name'  => $order['Billing Last Name'] ?? '',
            'company'    => $order['Billing Company'] ?? '',
            'address1'   => $order['Billing Street 1'] ?? '',
            'address2'   => $order['Billing Street 2'] ?? '',
            'zip'        => $order['Billing Zip'] ?? '',
            'city'       => $order['Billing Suburb'] ?? '', // ✅ IMPORTANT
            'province'   => $order['Billing State'] ?? '',
            'country'    => $order['Billing Country'] ?? '',
            'phone'      => $order['Billing Phone'] ?? '',
        ];

        $orders['order']['billing_address'] = $billingAddress;

        if ($requiresShipping) {
            // if shipping empty → copy billing
            if (empty($shippingAddress['address1'])) {
                $shippingAddress = $billingAddress;
                $orders['order']['tags'] .= ', BillingUsedAsShipping';
            }
            $orders['order']['shipping_address'] = $shippingAddress;
            
        }
        $orders['order']['financial_status'] = setFinancialStatus($order['Order Status']);
        
        $orders['order']['fulfillment_status'] = setFulfillmentStatus($order['Order Status']);
        
        $orders['order']['note_attributes'] = [
            [
                "name"  => "BigCommerce Status",
                "value" => $order['Order Status'] ?? ''
            ],
            [
                "name"  => "Channel Name",
                "value" => $order['Channel Name'] ?? ''
            ]
        ];
         // Add  Refund if available
         if ($order['Refund Amount'] != 0) {
            $orders['order']['note_attributes'][] = [
                "name"  => "Refund Amount",
                "value" => $order['Refund Amount']
            ];
        }
        
        // Add Date Shipped if available
        if (!empty($order['Date Shipped'])) {
            $orders['order']['note_attributes'][] = [
                "name"  => "Date Shipped",
                "value" => $order['Date Shipped']
            ];
        }
        
        // Add Payment Method if available
        if (!empty($order['Payment Method'])) {
            $orders['order']['note_attributes'][] = [
                "name"  => "Payment Method",
                "value" => $order['Payment Method']
            ];
        }
        $orders['order']['note'] = $order['Order Notes'] ?? '';
        if($order['Order Total (inc tax)'] > 0){
            $orders['order']['transactions'] = [
                [
                    "status" => "success",
                    "amount" => $order['Order Total (inc tax)'] ?? 0
                ]
            ];
        }
        // echo "<pre>";
        // print_r($orders);
        $response = $shopify->createOrder($orders ?? []) ?? [];
        
        if(!empty($response['id'])){
            saveImportedOrder($orderId, $response['id']);
            writeLog("Order Imported: BC ID {$orderId} → Shopify ID {$response['id']}" , $type = 'INFO');

        } else {
            writeLog("Failed to import order: BC ID {$orderId}. Response: " . json_encode($response) , $type = 'ERROR');
        }
        sleep(2); // ⏱️ 1 second delay between requests to avoid hitting rate limits
        // echo "<pre>";
        // print_r($response);
        // die;
    }
    fclose($handle);
    echo "All orders Imported successfully";
    die;

} catch (\Exception $e) {
    writeLog($e->getMessage() , $type = 'ERROR');
}




function writeLog($message, $type = 'INFO', $filename = 'app.log')
{
    // ALWAYS resolve absolute path safely
    $logDir = realpath(__DIR__) . DIRECTORY_SEPARATOR . 'logs';

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

function prepareMetafields($customFieldString)
{
    $metafields = [];
    if (empty($customFieldString)) {
        return $metafields;
    }
    $fields = explode(';', $customFieldString);
    if($fields){
        foreach ($fields as $field) {

            // remove ONLY wrapping quotes safely
            $field = preg_replace('/^"(.*)"$/', '$1', trim($field));
    
            if (strpos($field, '=') === false) {
                continue;
            }
    
            list($key, $value) = explode('=', $field, 2);
    
            // Clean Shopify key format
            $formattedKey = strtolower(trim($key));
            $formattedKey = preg_replace('/[^a-z0-9]+/i', '_', $formattedKey);
            $formattedKey = trim($formattedKey, '_');
    
            $metafields[] = [
                'namespace' => 'custom',
                'key'       => $formattedKey,
                'value'     => trim($value),
                'type'      => is_numeric($value)
                    ? 'number_integer'
                    : 'single_line_text_field'
            ];
        }
    }
    return $metafields;
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

// function convertToShopifyDate($bcDate)
// {
//     $date = new DateTime($bcDate);
//     return $date->format('Y-m-d\TH:i:sP');
// }

function convertToShopifyDate($bcDate)
{
    if (!$bcDate) return null;

    $date = new DateTime($bcDate);
    $date->setTimezone(new DateTimeZone('UTC'));

    return $date->format('Y-m-d\TH:i:sP');
}

function convertFloatRound($amount){
    if($amount){
        $amountRounded = (float) $amount;
        $amountRounded = round($amountRounded, 4);
        $amountRounded = round($amountRounded, 3);
        $amountRounded = round($amountRounded, 2);
        return $amountRounded;
    } else{
        return $amount;
    }
    
}

function setFinancialStatus($statusName)
{
    $statusName = strtolower(trim($statusName));

    $statusMap = [
        'pending'      => 'pending',
        'awaiting payment' => 'pending',
        'authorized'   => 'authorized',
        'paid'         => 'paid',
        'completed'    => 'paid',
        'processing'   => 'paid',
        'partially refunded' => 'partially_refunded',
        'refunded'     => 'refunded',
        'cancelled'    => 'voided',
        'canceled'     => 'voided',
        'failed'       => 'voided'
    ];

    return $statusMap[$statusName] ?? 'paid';
}

function setFulfillmentStatus($statusName)
{
    $statusName = strtolower(trim($statusName));

    $statusMap = [
        'pending'          => null,              // not fulfilled yet
        'awaiting payment' => null,
        'awaiting fulfillment' => null,
        'processing'       => null,
        'incomplete'       => null,
        'shipped'          => 'fulfilled',
        'completed'        => 'fulfilled',
        'partially shipped' => 'partial',
        'cancelled'        => null,
        'canceled'         => null,
        'refunded'         => null,
        'declined'         => null
    ];

    return $statusMap[$statusName] ?? null;
}


function getImportedOrder()
{
    $file = 'imported_orders.json';

    if (!file_exists($file)) {
        return [];
    }

    $data = file_get_contents($file);
    return json_decode($data, true) ?: [];
}

function saveImportedOrder($bcOrderId, $shopifyOrderId)
{
    $file = 'imported_orders.json';

    $orders = getImportedOrder();

    $orders[$bcOrderId] = [
        'shopify_id' => $shopifyOrderId,
        'imported_at' => date('Y-m-d H:i:s')
    ];

    file_put_contents($file, json_encode($orders, JSON_PRETTY_PRINT));
}


function convertDateToYmd($date)
{
    if (empty($date)) {
        return null;
    }

    $dateObj = DateTime::createFromFormat('d/m/Y', $date);

    return $dateObj ? $dateObj->format('Y-m-d') : null;
}