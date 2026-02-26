<?php

require_once 'config.php';
require_once 'BigCommerce.php';
require_once 'Shopify.php';

set_time_limit(0);
ini_set('memory_limit','1024M');

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

$blogsData = $bigcommerce->getAllBlogs();
echo "<pre>";
print_r($blogsData);die;