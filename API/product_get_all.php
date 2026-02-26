<?php

include 'config.php';
require_once 'BigCommerce.php';

set_time_limit(0);
ini_set('memory_limit', '1024M');

/* =========================
   BIGCOMMERCE
========================= */
$bigcommerce = new BigCommerce(
    BC_STORE_HASH,
    BC_ACCESS_TOKEN,
    BC_VERSION
);

/* =========================
   SHOPIFY CONFIG
========================= */
$shop   = SH_SHOP_URL;
$token  = SH_ACCESS_TOKEN;
$api_version = SH_VERSION;

/* =========================
   LOAD OLD ID → HANDLE MAP
========================= */

function loadHandleMap($file)
{
    $map = [];

    if (($h = fopen($file, 'r')) !== false) {

        fgetcsv($h); // skip header

        while (($row = fgetcsv($h)) !== false) {

            $handle = trim($row[1]);
            $oldId  = trim($row[2]);

            if ($oldId !== '') {
                $map[$oldId] = $handle;
            }
        }

        fclose($h);
    }

    return $map;
}

$handleMap = loadHandleMap(__DIR__.'/shopify_products_export.csv');

echo "Handle map loaded: ".count($handleMap)."\n";

/* =========================
   OUTPUT CSV
========================= */

$outputCsv = __DIR__."/shopify_related_products.csv";
$out = fopen($outputCsv, "w");

fputcsv($out, [
    'Handle',
    'product.metafields.shopify--discovery--product_recommendation.related_products',
    'Tt'
]);

/* =========================
   SHOPIFY REQUEST
========================= */

function shopifyRequest($url, $token)
{
    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            "X-Shopify-Access-Token: $token",
            "Content-Type: application/json"
        ],
        CURLOPT_HEADER => true
    ]);

    $response = curl_exec($ch);

    $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $headers = substr($response, 0, $header_size);
    $body = substr($response, $header_size);

    curl_close($ch);

    return [$headers, json_decode($body, true)];
}

/* =========================
   PAGINATION
========================= */

function getNextLink($headers)
{
    if (preg_match('/<([^>]+)>;\s*rel="next"/', $headers, $matches)) {
        return $matches[1];
    }
    return false;
}

/* =========================
   EXTRACT OLD ID FROM TAGS
========================= */

function extractOldProductId($tags)
{
    if (!$tags) return '';

    $tagArray = array_map('trim', explode(',', $tags));

    foreach ($tagArray as $tag) {
        if (preg_match('/OLD__(\d+)__ID/', $tag, $match)) {
            return $match[1];
        }
    }

    return '';
}

/* =========================
   FETCH SHOPIFY PRODUCTS
========================= */

$url = "https://$shop/admin/api/$api_version/products.json?limit=250";

do {

    list($headers, $data) = shopifyRequest($url, $token);

    if (empty($data['products'])) {
        break;
    }

    foreach ($data['products'] as $product) {

        $title  = $product['title'];
        $handle = $product['handle'];
        $tags   = $product['tags'];

        $oldProductId = extractOldProductId($tags);

        if (!$oldProductId) {
            continue;
        }

        echo "Processing: $handle (BC ID: $oldProductId)\n";

        /* =========================
           GET BIGCOMMERCE PRODUCT
        ========================= */

        $productByIdData = $bigcommerce->getProductById($oldProductId);

        if (empty($productByIdData)) {
            continue;
        }

        $relatedProducts = $productByIdData['related_products'] ?? [];

        // Skip automatic related (-1)
        if (empty($relatedProducts) || in_array(-1, $relatedProducts)) {
            continue;
        }

        /* =========================
           MAP BC IDS → SHOPIFY HANDLES
        ========================= */

        $relatedHandles = [];

        foreach ($relatedProducts as $bcRelatedId) {

            if (isset($handleMap[$bcRelatedId])) {
                $relatedHandles[] = $handleMap[$bcRelatedId];
            }
        }

        if (empty($relatedHandles)) {
            continue;
        }

        /* =========================
           WRITE CSV ROW
        ========================= */

        $value = implode(';', $relatedHandles);

        fputcsv($out, [
            $handle,
            $value,
            $title
        ]);
    }

    echo "Batch processed...\n";

    $url = getNextLink($headers);

} while ($url);

fclose($out);

echo "✅ CSV Generated: $outputCsv\n";
