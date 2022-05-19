<?php


namespace Pagarme;
use GuzzleHttp\Client;

class PagarMeBaseService
{
    /**
     * The base uri to consume the authors service
     * @var string
     */
    public $baseUri;

    /**
     * The secret to consume the authors service
     * @var string
     */
    public $secret;

    public function __construct()
    {
        $this->baseUri = config('services.pagarme.base_uri');
        $this->secret = config('services.pagarme.secret');
    }


    protected function get($url)
    {
        return $this->performRequest('GET', $url);
    }

    protected function post($url, $data = null)
    {
        return $this->performRequest('POST', $url, $data);
    }

    protected function put($url, $data = null)
    {
        return $this->performRequest('PUT', $url, $data);
    }

    protected function delete($url)
    {
        return $this->performRequest('DELETE', $url);
    }

    public function performRequest($method, $requestUrl, $formParams = [], $headers = [])
    {
        try {
        $client = new Client([
                                 'auth' => [$this->secret, 'x']
                             ]);
        $response = $client->request($method, sprintf('%s/%s',$this->baseUri, $requestUrl) , ['form_params' => $formParams, 'headers' => ['follow_redirects' => TRUE]]);
        return collect(json_decode($response->getBody()->getContents()))->all();
        }catch (Exception $e) {
            dd($e->getMessage());
           return $e->getMessage();
        }
    }
}
