<?php

namespace Kola;

class ApiClient
{
    protected $apiUrl;
    protected $apiKey;

    const API_VERSION_PATH = '/api/5.0';

    public function __construct($coreUrl, $apiKey)
    {
        $this->apiUrl = $coreUrl . static::API_VERSION_PATH;
        $this->apiKey = $apiKey;
    }

    protected $userAgent = '';
    public function setUserAgent($userAgent)
    {
        $this->userAgent = $userAgent;
        return $this;
    }

    protected $forwardedIp;
    public function setForwardedIp($forwardedIp)
    {
        $this->forwardedIp = $forwardedIp;
        return $this;
    }

    /**
     * @param string $resource
     * @param string $username
     * @param string $password
     * @return ApiResponse
     */
    public function authorize($resource, $username, $password)
    {
        $url = $this->apiUrl . $resource;

        if (!isset($resource[0]) || $resource[0] != '/') {
            throw new \LogicException("Resource must start with '/', '$resource' provided");
        }

        list($status, $data) = $this->makeRequest('GET', $url, array(), $username, $password);

        return new ApiResponse($status, $data);
    }

    /**
     * @param string $resource
     * @param array $filters
     * @param array $postData
     * @return ApiResponse
     */
    public function callApi($resource, array $filters = array(), array $postData = array())
    {
        if (strpos($resource, ' ') !== false) {
            list($method, $relativeUrl) = explode(' ', $resource, 2);
        } else {
            $method      = 'GET';
            $relativeUrl = $resource;
        }

        $url = $this->makeFullUrl($relativeUrl, $filters);

        // TODO переделать на json json_encode($postData) (не забыть про заголовки)
        // иначе http_build_query игнорирует значения с нулами, а у нас это чекбоксы снятые могут быть
        foreach ($postData as &$value) {
            if ($value === null) {
                $value = '';
            }
        }
        list($status, $data) = $this->makeRequest($method, $url, http_build_query($postData));

        return new ApiResponse($status, $data);
    }

    /**
     * @param array $queries urlPath => array filters
     * @return ApiResponse
     */
    public function multiCallApi(array $queries)
    {
        $params = array('queries' => array());
        foreach ($queries as $urlPath => $filters) {
            $params['queries'][$urlPath] = static::API_VERSION_PATH . $urlPath . '?' . http_build_query($filters);
        }

        $params['apiKey'] = $this->apiKey;

        $url = $this->apiUrl . '/multi?' . http_build_query($params);

        list($status, $data) = $this->makeRequest('GET', $url);

        return new ApiResponse($status, $data);
    }

    protected function makeFullUrl($relativeUrl, array $filters = array())
    {
        if (!isset($relativeUrl[0]) || $relativeUrl[0] != '/') {
            throw new \LogicException("Resource must start with '/', '$relativeUrl' provided");
        }

        $filters['apiKey'] = $this->apiKey;
        return $this->apiUrl . $relativeUrl . '?' . http_build_query($filters);
    }

    protected function makeRequest($method, $url, $postContent = '', $username = null, $password = null)
    {
        $headers = array();
        $ch = curl_init($url);

        curl_setopt($ch, CURLOPT_TIMEOUT, 10);

        switch ($method) {
            case 'GET':
                break;
            case 'POST':
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $postContent);
                $headers[] = 'Content-Type: application/x-www-form-urlencoded';
                break;
            case 'PUT':
                curl_setopt($ch, CURLOPT_PUT, true);

                $fhPut = fopen('php://memory', 'rw');
                fwrite($fhPut, $postContent);
                rewind($fhPut);
                curl_setopt($ch, CURLOPT_INFILE, $fhPut);
                curl_setopt($ch, CURLOPT_INFILESIZE, strlen($postContent));
                //curl_setopt($ch, CURLOPT_HTTPHEADER, Array('Expect: '));
                $headers[] = 'Content-Type: application/x-www-form-urlencoded';
                break;
            default:
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
                break;
        }

        if ($username !== null) {
            curl_setopt($ch, CURLOPT_USERPWD, urlencode($username) . ":" . urlencode($password));
        }

        if ($this->forwardedIp) {
            $headers[] = 'X-Forwarded-For: ' . $this->forwardedIp;
        }

        curl_setopt($ch, CURLOPT_USERAGENT, $this->userAgent);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_HEADER, false); // exclude the header in the output
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); // exec will return the response body
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); // will follow redirects in response
        curl_setopt($ch, CURLOPT_VERBOSE, false);

        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (curl_error($ch)) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new \Exception($err . ' (' . $method . ' ' . $url . ')');
        }

        curl_close($ch);
        if (isset($fhPut)) {
            fclose($fhPut);
        }

        if ($status >= 500) {
            $json = @json_decode($response, true);
            if ($json) {
                $response = json_encode($json, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            }
            throw new \Exception("Core bad response to {$url} - http status {$status}, response: {$response}");
        }

        return array($status, $response);
    }
}
