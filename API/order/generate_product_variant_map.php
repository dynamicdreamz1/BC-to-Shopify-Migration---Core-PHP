<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../BigCommerce.php';
require_once __DIR__ . '/../Shopify.php';

set_time_limit(0);
ini_set('memory_limit', '1024M');

/* =========================
   SHOPIFY CONFIG
========================= */

$shop   = SH_SHOP_URL;
$token  = SH_ACCESS_TOKEN;
$api_version = SH_VERSION;


/* =========================
   OUTPUT CSV
========================= */

$outputCsv = "shopify_product_variant_map.csv";

$out = fopen($outputCsv, "w");

fputcsv($out, [
    'bigcommerce_product_id',
    'shopify_product_id',
    'shopify_variant_id',
    'sku',
    'handle',
    'title'
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
   EXTRACT OLD BC PRODUCT ID
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
   FETCH ALL SHOPIFY PRODUCTS
========================= */

$url = "https://$shop/admin/api/$api_version/products.json?limit=250";

do {

    list($headers, $data) = shopifyRequest($url, $token);

    if (empty($data['products'])) {
        break;
    }

    foreach ($data['products'] as $product) {

        $shopifyProductId = $product['id'];
        $handle = $product['handle'];
        $title  = $product['title'];
        $tags   = $product['tags'];

        $oldProductId = extractOldProductId($tags);

        if (!$oldProductId) {
            continue;
        }

        echo "Processing: $handle (BC ID: $oldProductId)\n";

        /* =========================
           LOOP VARIANTS
        ========================= */

        foreach ($product['variants'] as $variant) {

            $variantId = $variant['id'];
            $sku       = $variant['sku'];

            fputcsv($out, [
                $oldProductId,
                $shopifyProductId,
                $variantId,
                $sku,
                $handle,
                $title
            ]);
        }
    }

    echo "Batch processed...\n";

    $url = getNextLink($headers);

} while ($url);


fclose($out);

echo "✅ CSV Generated: $outputCsv\n";