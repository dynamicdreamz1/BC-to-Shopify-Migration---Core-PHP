<?php

class BigCommerce
{
    private $storeHash;
    private $accessToken;
    private $apiVersion;
    private $baseUrl;

    /**
     * Constructor
     */
    public function __construct($storeHash, $accessToken, $apiVersion = 'v3')
    {
        $this->storeHash   = $storeHash;
        $this->accessToken = $accessToken;
        $this->apiVersion  = $apiVersion;

        $this->baseUrl = "https://api.bigcommerce.com/stores/{$this->storeHash}/{$this->apiVersion}/";
        $this->baseUrl;
    }

    /**
     * Generic CURL Request Function
     */
    private function request($method, $endpoint, $data = [])
    {
        $url = $this->baseUrl . ltrim($endpoint, '/');

        // GET query params
        if ($method === 'GET' && !empty($data)) {
            $url .= '?' . http_build_query($data);
        }

        $ch = curl_init();

        $headers = [
            "X-Auth-Token: {$this->accessToken}",
            "Content-Type: application/json",
            "Accept: application/json"
        ];

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
        ]);

        // POST / PUT body
        if (in_array($method, ['POST', 'PUT']) && !empty($data)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }

        $response = curl_exec($ch);
        $error    = curl_error($ch);
        $status   = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        curl_close($ch);

        if ($error) {
            throw new Exception("cURL Error: " . $error);
        }

        $decoded = json_decode($response, true);

        if ($status >= 400) {
            throw new Exception("API Error ({$status}): " . $response);
        }

        return $decoded;
    }

    /**
     * =====================================
     * Get ALL Variants By Product ID
     * =====================================
     */
    public function getProductVariantsByProductId($productId)
    {
        $page = 1;
        $limit = 250;
        $variants = [];

        do {

            $response = $this->request(
                'GET',
                "catalog/products/{$productId}/variants",
                [
                    'page'  => $page,
                    'limit' => $limit
                ]
            );

            if (!empty($response['data'])) {
                $variants = array_merge($variants, $response['data']);
            }

            $pagination = $response['meta']['pagination'];

            $hasMore = $pagination['current_page'] < $pagination['total_pages'];

            $page++;

        } while ($hasMore);

        return $variants;
    }

    public function getProductById($productId)
    {
        $response = $this->request(
            'GET',
            "catalog/products/{$productId}",
            [
                'include' => 'videos,images,custom_fields,options,modifiers'
            ]
        );

        return $response['data'] ?? [];
    }

    public function getAllCustomers()
    {
        $page = 1;
        $limit = 250;
        $customers = [];

        do {

            $response = $this->request(
                'GET',
                'customers',
                [
                    'page'  => $page,
                    'limit' => $limit
                ]
            );

            if (!empty($response['data'])) {
                $customers = array_merge($customers, $response['data']);
            }

            $pagination = $response['meta']['pagination'];

            $hasMore = $pagination['current_page'] < $pagination['total_pages'];

            $page++;

        } while ($hasMore);

        return $customers;
    }

    public function getProductModifiers($productId)
    {
        $page = 1;
        $limit = 250;
        $modifiers = [];

        do {

            $response = $this->request(
                'GET',
                "catalog/products/{$productId}/modifiers",
                [
                    'page'  => $page,
                    'limit' => $limit
                ]
            );

            if (!empty($response['data'])) {
                $modifiers = array_merge($modifiers, $response['data']);
            }

            $pagination = $response['meta']['pagination'] ?? null;

            $hasMore = $pagination &&
                    ($pagination['current_page'] < $pagination['total_pages']);

            $page++;

        } while ($hasMore);

        return $modifiers;
    }


    public function getCustomerAddressesByCustomerId($customerId)
    {
        $page = 1;
        $limit = 250;
        $addresses = [];

        do {

            $response = $this->request(
                'GET',
                'customers/addresses',
                [
                    'customer_id:in' => $customerId,
                    'page'  => $page,
                    'limit' => $limit
                ]
            );

            if (!empty($response['data'])) {
                $addresses = array_merge($addresses, $response['data']);
            }

            $pagination = $response['meta']['pagination'];
            $hasMore = $pagination['current_page'] < $pagination['total_pages'];

            $page++;

        } while ($hasMore);

        return $addresses;
    }

    public function getOrderProductsById($orderId)
    {
        return $this->request(
            'GET',
            "orders/{$orderId}/products"
        );
    }


    public function getOrderById($orderId)
    {
        $response = $this->request(
            'GET',
            'orders/' . $orderId
        );

        return $response ?? [];
    }

    public function getOrderCoupons($orderId)
    {
        $response = $this->request(
            'GET',
            'orders/' . $orderId . '/coupons'
        );

        return $response ?? [];
    }

    public function getCustomerAttributeValues($customerId)
    {
        $response = $this->request(
            'GET',
            'customers/attribute-values',
            [
                'customer_id:in' => $customerId
            ]
        );
    
        return $response['data'] ?? [];
    }
    

    public function getAllCategories()
    {
        $page = 1;
        $limit = 250;
        $categories = [];

        do {

            $response = $this->request(
                'GET',
                'catalog/categories',
                [
                    'page'  => $page,
                    'limit' => $limit
                ]
            );
            // echo "<pre>";
            // print_r(json_encode($response, true ));die;
            if (!empty($response['data'])) {
                $categories = array_merge($categories, $response['data']);
            }

            $pagination = $response['meta']['pagination'];

            $hasMore = $pagination['current_page'] < $pagination['total_pages'];

            $page++;

        } while ($hasMore);

        return $categories;
    }

    /**
     * =====================================
     * Get ALL Blog Posts (V2 API)
     * =====================================
     */
    public function getAllBlogs()
    {
        $page = 1;
        $limit = 250;
        $blogs = [];

        do {

            $response = $this->requestV2(
                'GET',
                "blog/posts",
                [
                    'page'  => $page,
                    'limit' => $limit
                ]
            );

            if (!empty($response)) {
                $blogs = array_merge($blogs, $response);
            }

            $hasMore = count($response) == $limit;
            $page++;

        } while ($hasMore);

        return $blogs;
    }

    /**
     * V2 Request Handler (Blogs use V2)
     */
    private function requestV2($method, $endpoint, $data = [])
    {
        $url = "https://api.bigcommerce.com/stores/{$this->storeHash}/v2/" . ltrim($endpoint,'/');
        echo $url;die;
        if ($method === 'GET' && !empty($data)) {
            $url .= '?' . http_build_query($data);
        }

        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER => [
                "X-Auth-Token: {$this->accessToken}",
                "Accept: application/json",
                "Content-Type: application/json"
            ]
        ]);

        $response = curl_exec($ch);
        $status   = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        curl_close($ch);

        if ($status >= 400) {
            throw new Exception("BC Blog API Error ({$status}): ".$response);
        }

        return json_decode($response, true);
    }


}


