<?php

class Shopify
{
    private $shopUrl;
    private $accessToken;
    private $apiVersion;
    private $baseUrl;

    /**
     * Constructor
     */
    public function __construct($shopUrl, $accessToken, $apiVersion = '2025-01')
    {
        $this->shopUrl     = rtrim($shopUrl, '/');
        $this->accessToken = $accessToken;
        $this->apiVersion  = $apiVersion;

        $this->baseUrl = "{$this->shopUrl}/admin/api/{$this->apiVersion}/";
    }

    /**
     * ====================================
     * Generic CURL Request Handler
     * ====================================
     */
    private function request($method, $endpoint, $data = [])
    {
        $url = 'https://'.$this->baseUrl . ltrim($endpoint, '/');
        
        // GET query parameters
        if ($method === 'GET' && !empty($data)) {
            $url .= '?' . http_build_query($data);
        }

        $headers = [
            "X-Shopify-Access-Token: {$this->accessToken}",
            "Content-Type: application/json"
        ];

        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
        ]);

        if (in_array($method, ['POST','PUT']) && !empty($data)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }

        $response = curl_exec($ch);
        $error    = curl_error($ch);

        
        $status   = curl_getinfo($ch, CURLINFO_HTTP_CODE);


        if ($error) {
            throw new Exception("cURL Error: " . $error);
        }

        if ($status >= 400) {
            throw new Exception("Shopify API Error ({$status}): {$response}");
        }

        return json_decode($response, true);
    }

    /**
     * ====================================
     * PRODUCTS
     * ====================================
     */

    public function getProducts($params = [])
    {
        return $this->request('GET', 'products.json', $params);
    }

    public function getProductById($productId)
    {
        return $this->request('GET', "products/{$productId}.json");
    }

    /**
     * ====================================
     * ORDERS
     * ====================================
     */

    public function getOrders($params = [])
    {
        return $this->request('GET', 'orders.json', $params);
    }

    public function getOrderById($orderId)
    {
        return $this->request('GET', "orders/{$orderId}.json");
    }

    /**
     * ====================================
     * COLLECTIONS
     * ====================================
     */

    public function getCustomCollections($params = [])
    {
        return $this->request('GET', 'custom_collections.json', $params);
    }

    public function getSmartCollections($params = [])
    {
        return $this->request('GET', 'smart_collections.json', $params);
    }

    /**
     * ====================================
     * CUSTOMERS
     * ====================================
     */

    public function getCustomers($params = [])
    {
        return $this->request('GET', 'customers.json', $params);
    }

    /**
     * ====================================
     * CREATE PRODUCT
     * ====================================
     */

    public function createProduct($data)
    {
        return $this->request('POST', 'products.json', $data);
    }

    /**
     * ====================================
     * UPDATE PRODUCT
     * ====================================
     */

    public function updateProduct($productId, $data)
    {
        return $this->request('PUT', "products/{$productId}.json", $data);
    }


    public function createCustomer($data)
    {
        return $this->request('POST', 'customers.json', $data);
    }


    public function createCustomerAddress($customerId, $addressData)
    {
        return $this->request(
            'POST',
            "customers/{$customerId}/addresses.json",
            $addressData
        );
    }

    public function createOrder(array $orderData)
    {
        try {
            $response = $this->requestOrder(
                'POST',
                'orders.json',
                $orderData
            );
            return $response['order'] ?? [];

        } catch (Exception $e) {

            error_log('Shopify Order Create Error: ' . $e->getMessage());
            return false;
        }
    }

    private function requestOrder($method, $endpoint, $data = [])
    {

        $url = 'https://'.$this->baseUrl . ltrim($endpoint, '/');
        $ch = curl_init($url);

        $headers = [
            "X-Shopify-Access-Token: {$this->accessToken}",
            "Content-Type: application/json"
        ];

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        if (!empty($data)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }   
        
        $response = curl_exec($ch);
        $error    = curl_error($ch);

        $status   = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($error) {
            throw new Exception("cURL Error: " . $error);
            return json_decode($error, true);
        }

        if ($status >= 400) {
            throw new Exception("Shopify API Error ({$status}): {$response}");
            return json_decode($response, true);
            
        }
        return json_decode($response, true);
       
    }

}
