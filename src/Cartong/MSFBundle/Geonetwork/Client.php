<?php

namespace Cartong\MSFBundle\Geonetwork;

use Guzzle\Http\Client as GuzzleClient;

class Client
{
    protected $geonetworkUrl = 'http://msfmapcentre.cartong.org/geonetwork/';

    public function __construct($geonetworkUrl)
    {
        $this->geonetworkUrl = $geonetworkUrl;
    }
    
    public function post($query, $payload)
    {
        $client = new GuzzleClient();

        $url = $this->geonetworkUrl.$query;
        $request = $client->createRequest('POST', $url, null, $payload, [
            'allow_redirects' => false
        ]);
        $response = $client->send($request);
        return $response;
    }

    public function get($query, $jsessionID = null)
    {
        $client = new GuzzleClient();

        $url = $this->geonetworkUrl.$query;
        $request = $client->createRequest('GET', $url, null, null, [
            'cookies' => [ 'JSESSIONID' => $jsessionID ],
            'allow_redirects' => false
        ]);
        $response = $client->send($request);
        return $response;
    }

    public function head($query, $jsessionID = null)
    {
        $client = new GuzzleClient();

        $url = $this->geonetworkUrl.$query;
        $request = $client->createRequest('HEAD', $url, null, null, [
            'cookies' => [ 'JSESSIONID' => $jsessionID ],
            'allow_redirects' => false
        ]);
        $response = $client->send($request);
        return $response;
    }

}
