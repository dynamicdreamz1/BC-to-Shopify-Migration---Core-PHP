<?php
error_reporting(1);
set_time_limit(0);
ini_set('max_execution_time', 0);

// echo "Hiii";exit;
require_once 'config.php';

require_once 'BigCommerce.php';

require 'Shopify.php';

try {
    $file = 'product/products-2026-02-16.csv';
    if (!file_exists($file)) {
        die("CSV not found");
    }
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
    $handle = fopen($file, 'r');
    $headers = fgetcsv($handle); // first row head
    
    $productCsv = fopen('product_video_mapping.csv', 'w');

    fputcsv($productCsv, [
        'Handle',
        '(product.metafields.custom.videos)',
        'tt'
    ]);


    $products = [];
    while (($row = fgetcsv($handle, 0, ",")) !== false) {

        $product = array_combine($headers, $row);
        $productId = $product['Product ID'] ?? '';
        $productTitle = trim($product['Name']) ?? '';
    
        $productByIdData = $bigcommerce->getProductById($productId);
        $videosData = $productByIdData['videos'] ?? [];
    
        if (empty($videosData)) {
            continue;
        }
    
        // Shopify handle (IMPORTANT)
        $shopifyHandle = getShopifyHandle($product['Product URL']);
    
        $metaobjectHandles = [];
        $index = 1;
    
        foreach ($videosData as $video) {
    
            if ($video['type'] !== 'youtube') {
                continue;
            }
    
            $videoUrl = 'https://' . YOUTUBE_SHORT_CODE . '/' . $video['video_id'];

            $videoTitle = $video['title'] ?? '';
            $videoDescription = $video['description'] ?? '';


            // deterministic handle (safe for reruns)
            $metaHandle = "bc-{$productId}-video-{$index}-{$video['video_id']}";
    
            $result = createVideoMetaobject(
                $metaHandle,
                $videoUrl,
                $videoTitle,
                $videoDescription
            );
            // sleep(2);
            $metaobjectId = $response['data']['metaobjectCreate']['metaobject']['id'] ?? null;

            // if ($metaobjectId) {
            //     activateMetaobject($metaobjectId);
            // } else {
            //     writeLog(
            //         "Metaobject activation failed: " . json_encode($result),
            //         'ERROR',
            //         'video_error.log'
            //     );
            // }

    
            if (!empty($result['data']['metaobjectCreate']['metaobject'])) {
                $metaobjectHandles[] = $metaHandle;
            } else {
                writeLog(
                    "Metaobject failed: " . json_encode($result),
                    'ERROR',
                    'video_error.log'
                );
            }
    
            $index++;
        }
    
        // ✅ Write product mapping CSV
        if (!empty($metaobjectHandles)) {
            fputcsv($productCsv, [
                $shopifyHandle,
                implode(';', $metaobjectHandles),
                $productTitle
            ]);
            writeLog(
                "Metaobject success: " . $productId,
                'ERROR',
                'video_success.log'
            );
        }
        
    }
    echo "done";die;
    fclose($handle);
    echo "All videos metafield has been successfully uploaded";
    die;

} catch (\Exception $e) {
    writeLog($e->getMessage() , $type = 'ERROR');
}

function shopifyGraphQL($query, $variables = [])
{
    $url = "https://" . SH_SHOP_URL . "/admin/api/" . SH_VERSION . "/graphql.json";

    $payload = json_encode([
        "query" => $query,
        "variables" => $variables
    ]);

    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            "Content-Type: application/json",
            "X-Shopify-Access-Token: " . SH_ACCESS_TOKEN,
            "Content-Length: " . strlen($payload)
        ]
    ]);

    $response = curl_exec($ch);
    curl_close($ch);

    return json_decode($response, true);
}

function activateMetaobject($metaobjectId)
{
    $mutation = <<<GQL
mutation metaobjectUpdate(\$id: ID!, \$metaobject: MetaobjectUpdateInput!) {
  metaobjectUpdate(id: \$id, metaobject: \$metaobject) {
    metaobject {
      id
      status
    }
    userErrors {
      field
      message
    }
  }
}
GQL;

    $variables = [
        "id" => $metaobjectId,
        "metaobject" => [
            "status" => "ACTIVE"
        ]
    ];

    return shopifyGraphQL($mutation, $variables);
}


function createVideoMetaobject($handle, $url, $title, $description)
{
    $mutation = <<<GQL
mutation metaobjectCreate(\$metaobject: MetaobjectCreateInput!) {
  metaobjectCreate(metaobject: \$metaobject) {
    metaobject {
      id
      handle
    }
    userErrors {
      field
      message
    }
  }
}
GQL;

    $variables = [
        "metaobject" => [
            "type" => "videos",
            "handle" => $handle,
            "fields" => [
                ["key" => "url", "value" => $url],
                ["key" => "title", "value" => $title],
                ["key" => "description", "value" => $description]
            ]
        ]
    ];

    return shopifyGraphQL($mutation, $variables);
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


function getShopifyHandle($productUrl)
{
    if (empty($productUrl)) {
        return null;
    }
    $productUrl = strtok($productUrl, '?');
    $productUrl = trim($productUrl, '/');
    $parts = explode('/', $productUrl);
    $handle = end($parts);
    $handle = strtolower($handle);
    $handle = preg_replace('/[^a-z0-9\-]+/', '-', $handle);
    $handle = preg_replace('/-+/', '-', $handle);
    $handle = trim($handle, '-');
    return $handle;
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

function buildShopifyVariantsFromBC($bcVariants = [], $product = [], $productApiData = [])
{
    $shopifyVariants = [];
    $optionsMap = [];
    $variantImages=[];
    if($bcVariants){
        foreach ($bcVariants as $vKay => $variant) {
            $optionIndex = 1;
            $variantOptions = [
                'option1' => null,
                'option2' => null,
                'option3' => null,
            ];
            // ---------- OPTIONS ----------
            if (!empty($variant['option_values'])) {
                foreach ($variant['option_values'] as $opt) {
                    $optionName  = $opt['option_display_name'];
                    $optionValue = $opt['label'];
                    // collect option values globally
                    $optionsMap[$optionName][] = $optionValue;
                    // assign option1/2/3
                    $variantOptions["option{$optionIndex}"] = $optionValue;
                    $optionIndex++;
                }
            }
            // ---------- PRICE ----------
            $price = !empty($variant['price'])
                ? $variant['price']
                : $variant['calculated_price'];
            // ---------- BARCODE ----------
            $barcode = $variant['upc']
                ?: ($variant['gtin'] ?? null);
            // ---------- SHOPIFY VARIANT ----------
            
            $shopifyVariants[] = array_merge([
                'sku' => $variant['sku'],
                'price' => (string)$price,
                'compare_at_price' => !empty($variant['sale_price'])
                    ? $variant['sale_price']
                    : null,
                'inventory_quantity' => (int)$variant['inventory_level'],
                'inventory_management' => 'shopify',
                'weight' => (float)$variant['calculated_weight'] ?? (float)$variant['Weight'] ?? 0,
                'weight_unit' => 'lb',
                'width' => $variant['width'] ?? $product['Width'] ?? 0,
                'depth' => $variant['depth'] ?? $product['Depth'] ?? 0,
                'width' => $variant['height'] ?? $product['Height'] ?? 0,
                'barcode' => $barcode,
                'inventory_policy' => $productApiData['inventory_tracking'] == 'none' ? 'continue' : 'deny',
                // Variant image
                'image_id' => null, // assigned after image upload
                'image_src' => $variant['image_url'], // helper field for import step
    
            ], $variantOptions);
           
            $variantImages[$vKay]['src'] = $variant['image_url'] ?? '';
            $variantImages[$vKay]['alt'] = ($variantOptions['option1'] ?? '').'-'.($variantOptions['option2'] ?? '');
        }

        $shopifyOptions = [];
        $position = 1;
        if($optionsMap){
            foreach ($optionsMap as $name => $values) {
                $shopifyOptions[] = [
                    'name' => $name,
                    'position' => $position++,
                    'values' => array_values(array_unique($values))
                ];
            }
        }
        return [
            'options' => $shopifyOptions,
            'variants' => $shopifyVariants,
            'images' => $variantImages  
        ];
    }
}

function prepareShopifySEO($product)
{
    $seoTitle = !empty($product['Page Title'])
        ? trim($product['Page Title'])
        : trim($product['Name']);

    $seoDescription = trim($product['META Description'] ?? '');

    // Shopify recommended limits
    $seoTitle = mb_substr($seoTitle, 0, 70);
    $seoDescription = mb_substr($seoDescription, 0, 320);

    return [
        'seo' => [
            'title' => $seoTitle,
            'description' => $seoDescription
        ]
    ];
}

function writeLog($message, $type = 'INFO', $filename = 'app.log')
{
    // ALWAYS resolve absolute path safely
    $logDir = realpath(__DIR__) . DIRECTORY_SEPARATOR . 'product/logs';

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



function downloadAssetsAndReplaceUrls($html, $downloadDir)
{
    if (empty($html)) {
        return $html;
    }

    $baseSiteUrl   = 'https://'.BC_STORE_PATH;
    $shopifyCdn    = 'https://'.MEDIA_STORE_PATH.'/';

    libxml_use_internal_errors(true);

    $dom = new DOMDocument();
    $dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));

    // tags to scan
    $tags = [
        ['tag' => 'a',   'attr' => 'href'],
        ['tag' => 'img', 'attr' => 'src'],
        ['tag' => 'source', 'attr' => 'src']
    ];

    foreach ($tags as $tagInfo) {

        $elements = $dom->getElementsByTagName($tagInfo['tag']);

        foreach ($elements as $el) {

            $attr = $tagInfo['attr'];
            $url  = $el->getAttribute($attr);

            if (!$url || strpos($url, '/content/') === false) {
                continue;
            }

            // make absolute URL
            $pos = strpos($url, '/content/');
            $relativePath = substr($url, $pos);

            $fileUrl = $baseSiteUrl . $relativePath;

            // filename
            $fileName = basename(parse_url($fileUrl, PHP_URL_PATH));

            $localPath = rtrim($downloadDir, '/') . '/' . $fileName;

            // download if not exists
            if (!file_exists($localPath)) {

                $fileData = @file_get_contents($fileUrl);

                if ($fileData !== false) {
                    file_put_contents($localPath, $fileData);
                }
            }

            // replace with shopify CDN path
            $shopifyUrl = $shopifyCdn . $fileName;

            $el->setAttribute($attr, $shopifyUrl);
        }
    }

    // clean html wrapper
    $body = $dom->getElementsByTagName('body')->item(0);

    $newHtml = '';
    foreach ($body->childNodes as $child) {
        $newHtml .= $dom->saveHTML($child);
    }

    return $newHtml;
}




function buildVariantImageMap($shopifyImages)
{
    $map = [];

    foreach ($shopifyImages as $img) {

        if (!empty($img['alt'])) {

            // normalize text
            $key = strtolower(trim($img['alt']));

            $map[$key] = $img['id'];
        }
    }

    return $map;
}

function attachVariantImages(&$variants, $imageMap)
{
    foreach ($variants as &$variant) {

        $option1 = trim($variant['option1'] ?? '');
        $option2 = trim($variant['option2'] ?? '');

        // create same format as ALT
        $altKey = strtolower($option1 . '-' . $option2);

        if (isset($imageMap[$altKey])) {
            $variant['image_id'] = $imageMap[$altKey];
        }

        // IMPORTANT — Shopify ignores this
        unset($variant['image_src']);
    }
}

function getImportedProducts()
{
    $file = 'product/media_imported_products.json';

    if (!file_exists($file)) {
        return [];
    }

    $data = file_get_contents($file);
    return json_decode($data, true) ?: [];
}

function saveImportedProduct($bcProductId, $shopifyProductId)
{
    $file = 'product/media_imported_products.json';

    $products = getImportedProducts();

    $products[$bcProductId] = [
        'shopify_id' => $shopifyProductId,
        'imported_at' => date('Y-m-d H:i:s')
    ];

    file_put_contents($file, json_encode($products, JSON_PRETTY_PRINT));
}
