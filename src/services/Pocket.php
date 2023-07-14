<?php

namespace flusio\services;

/**
 * @author  Marien Fressinaud <dev@marienfressinaud.fr>
 * @license http://www.gnu.org/licenses/agpl-3.0.en.html AGPL
 */
class Pocket
{
    public const HOST = 'https://getpocket.com';

    private string $consumer_key;

    private \SpiderBits\Http $http;

    public function __construct(string $consumer_key)
    {
        $this->consumer_key = $consumer_key;

        $this->http = new \SpiderBits\Http();
        $this->http->headers = [
            'Content-Type' => 'application/x-www-form-urlencoded; charset=UTF8',
            'X-Accept' => 'application/json',
        ];
        /** @var string */
        $user_agent = \Minz\Configuration::$application['user_agent'];
        $this->http->user_agent = $user_agent;
        $this->http->timeout = 20;
    }

    /**
     * Get the list of items from Pocket
     *
     * @see https://getpocket.com/developer/docs/v3/retrieve
     *
     * @param string $access_token
     * @param array<string, mixed> $parameters
     *     List of optional parameters to pass in the request
     *
     * @throw \flusio\services\PocketError
     *
     * @return array<array<string, mixed>>
     */
    public function retrieve(string $access_token, array $parameters = []): array
    {
        $endpoint = self::HOST . '/v3/get';
        try {
            $response = $this->http->post($endpoint, array_merge($parameters, [
                'consumer_key' => $this->consumer_key,
                'access_token' => $access_token,
            ]));
        } catch (\SpiderBits\HttpError $e) {
            throw new PocketError(42, $e->getMessage());
        }

        if ($response->success) {
            /** @var ?mixed[] */
            $json = json_decode($response->data, true);

            if (!$json || !is_array($json['list'] ?? null)) {
                throw new PocketError(42, 'Invalid data');
            }

            return $json['list'];
        } else {
            /** @var string */
            $error_code = $response->header('X-Error-Code', '42');
            throw new PocketError($error_code, 'Request failed');
        }
    }

    /**
     * Get a request token from Pocket.
     *
     * @throws PocketError
     */
    public function requestToken(string $redirect_uri): string
    {
        $endpoint = self::HOST . '/v3/oauth/request';
        try {
            $response = $this->http->post($endpoint, [
                'consumer_key' => $this->consumer_key,
                'redirect_uri' => $redirect_uri,
            ]);
        } catch (\SpiderBits\HttpError $e) {
            throw new PocketError(42, $e->getMessage());
        }

        if ($response->success) {
            /** @var ?mixed[] */
            $json = json_decode($response->data, true);

            if (!$json || !is_string($json['code'] ?? null)) {
                throw new PocketError(42, 'Invalid data');
            }

            return $json['code'];
        } else {
            /** @var string */
            $error_code = $response->header('X-Error-Code', '42');
            throw new PocketError($error_code, 'Request failed');
        }
    }

    /**
     * Return the URL to redirect user so it can authorize flusio
     */
    public function authorizationUrl(string $request_token, string $redirect_uri): string
    {
        $url = self::HOST . '/auth/authorize';
        $query = http_build_query([
            'request_token' => $request_token,
            'redirect_uri' => $redirect_uri,
        ]);
        return $url . '?' . $query;
    }

    /**
     * Get access token (and username) from a request token
     *
     * @throws \flusio\services\PocketError
     *
     * @return array{string, string} First item is token, second item is username
     */
    public function accessToken(string $request_token): array
    {
        $endpoint = self::HOST . '/v3/oauth/authorize';
        try {
            $response = $this->http->post($endpoint, [
                'consumer_key' => $this->consumer_key,
                'code' => $request_token,
            ]);
        } catch (\SpiderBits\HttpError $e) {
            throw new PocketError(42, $e->getMessage());
        }

        if ($response->success) {
            /** @var ?mixed[] */
            $json = json_decode($response->data, true);

            if (
                !$json ||
                !is_string($json['access_token'] ?? null) ||
                !is_string($json['username'] ?? null)
            ) {
                throw new PocketError(42, 'Invalid data');
            }

            return [
                $json['access_token'],
                $json['username'],
            ];
        } else {
            /** @var string */
            $error_code = $response->header('X-Error-Code', '42');
            throw new PocketError($error_code, 'Request failed');
        }
    }
}
