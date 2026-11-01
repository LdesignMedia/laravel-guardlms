<?php

namespace GuardLms\Laravel;

use Illuminate\Http\Client\Factory;
use RuntimeException;

class Client
{
    public function __construct(private Factory $http) {}

    public function push(array $payload): void
    {
        $url = rtrim((string) config('guardlms.api_url'), '/');
        $key = trim((string) config('guardlms.api_key'));
        if ($key === '' || ! filter_var($url, FILTER_VALIDATE_URL)
            || parse_url($url, PHP_URL_SCHEME) !== 'https'
            || parse_url($url, PHP_URL_USER) !== null || parse_url($url, PHP_URL_QUERY) !== null
            || parse_url($url, PHP_URL_FRAGMENT) !== null) {
            throw new RuntimeException('Configure a GuardLMS HTTPS API URL and website-bound inventory API key.');
        }
        $response = $this->http->withToken($key)->acceptJson()->asJson()
            ->connectTimeout(5)->timeout(30)->withOptions(['allow_redirects' => false])
            ->post($url.'/api/externalpush/laravel', $payload);
        if (! $response->successful()) {
            // Never print request headers, credentials, or a server's arbitrary response body.
            throw new RuntimeException('GuardLMS rejected inventory (HTTP '.$response->status().'). Check the website URL and inventory key.');
        }
    }
}
