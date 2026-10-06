<?php

declare(strict_types=1);

namespace App\Integration\Salesforce;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class SalesforceClient
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire('%env(SALESFORCE_BASE_URL)%')] private readonly string $baseUrl,
        #[Autowire('%env(SALESFORCE_CLIENT_ID)%')] private readonly string $clientId,
        #[Autowire('%env(SALESFORCE_CLIENT_SECRET)%')] private readonly string $clientSecret,
        #[Autowire('%env(SALESFORCE_API_VERSION)%')] private readonly string $apiVersion,
    ) {
    }

    public function getAccessToken(): string
    {
        try {
            $response = $this->httpClient->request('POST', rtrim($this->baseUrl, '/').'/services/oauth2/token', [
                'max_redirects' => 0,
                'body' => [
                    'grant_type' => 'client_credentials',
                    'client_id' => $this->clientId,
                    'client_secret' => $this->clientSecret,
                ],
            ]);

            $statusCode = $response->getStatusCode();
            if ($statusCode < 200 || $statusCode >= 300) {
                throw new SalesforceAuthenticationException();
            }

            $data = $response->toArray();
        } catch (ExceptionInterface) {
            throw new SalesforceAuthenticationException();
        }

        $accessToken = $data['access_token'] ?? null;
        if (!is_string($accessToken) || trim($accessToken) === '') {
            throw new SalesforceAuthenticationException();
        }

        return $accessToken;
    }

    public function request(string $method, string $path, ?array $jsonBody = null): array
    {
        $accessToken = $this->getAccessToken();
        $url = rtrim($this->baseUrl, '/').'/services/data/v'.ltrim($this->apiVersion, 'v').'/'.ltrim($path, '/');
        try {
            $response = $this->httpClient->request($method, $url, [
                'max_redirects' => 0,
                'auth_bearer' => $accessToken,
                'headers' => ['Accept' => 'application/json'],
                'json' => $jsonBody,
            ]);

            $statusCode = $response->getStatusCode();
            if ($statusCode < 200 || $statusCode >= 300) {
                throw new SalesforceApiException();
            }

            return $response->toArray();
        } catch (ExceptionInterface) {
            throw new SalesforceApiException();
        }
    }
}
