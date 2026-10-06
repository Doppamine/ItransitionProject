<?php

declare(strict_types=1);

namespace App\Tests\Integration\Salesforce;

use App\Integration\Salesforce\SalesforceAuthenticationException;
use App\Integration\Salesforce\SalesforceClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class SalesforceClientTest extends TestCase
{
    public function testSalesforceClientExists(): void
    {
        self::assertTrue(
            class_exists(SalesforceClient::class),
            'SalesforceClient should exist.',
        );
    }

    #[DataProvider('successfulTokenRequests')]
    public function testAcquiresAccessTokenUsingClientCredentials(string $baseUrl, string $accessToken): void
    {
        $httpClient = new MockHttpClient(static function (string $method, string $url, array $options) use ($accessToken): MockResponse {
            self::assertSame('POST', $method);
            self::assertSame('https://acme.my.salesforce.com/services/oauth2/token', $url);
            self::assertContains('Content-Type: application/x-www-form-urlencoded', $options['headers']);
            self::assertSame(0, $options['max_redirects']);
            parse_str($options['body'], $parameters);
            self::assertSame([
                'grant_type' => 'client_credentials',
                'client_id' => 'client-id+&=',
                'client_secret' => 'client-secret+&= /',
            ], $parameters);

            return new MockResponse(json_encode([
                'access_token' => $accessToken,
                'instance_url' => 'https://acme.my.salesforce.com',
                'token_type' => 'Bearer',
            ], JSON_THROW_ON_ERROR));
        });
        $client = $this->createClient($httpClient, $baseUrl);

        self::assertSame($accessToken, $client->getAccessToken());
    }

    public static function successfulTokenRequests(): iterable
    {
        yield 'base URL without trailing slash' => ['https://acme.my.salesforce.com', 'salesforce-access-token'];
        yield 'base URL with trailing slash' => ['https://acme.my.salesforce.com/', 'another-access-token'];
    }

    #[DataProvider('failedResponses')]
    public function testResponseFailuresRaiseCleanAuthenticationException(string $body, int $statusCode): void
    {
        $client = $this->createClient(
            new MockHttpClient(new MockResponse($body, ['http_code' => $statusCode])),
        );

        $this->expectException(SalesforceAuthenticationException::class);
        $this->expectExceptionMessage('Salesforce authentication failed.');

        $client->getAccessToken();
    }

    public static function failedResponses(): iterable
    {
        yield 'invalid credentials' => ['{"error":"invalid_client","error_description":"Sensitive Salesforce details"}', 400];
        yield 'unauthorized with token' => ['{"access_token":"must-not-be-used"}', 401];
        yield 'server error' => ['<html>Sensitive server details</html>', 500];
        yield 'redirect with token' => ['{"access_token":"must-not-be-used"}', 302];
        yield 'informational status with token' => ['{"access_token":"must-not-be-used"}', 199];
        yield 'invalid JSON' => ['not JSON: sensitive details', 200];
        yield 'empty body' => ['', 200];
        yield 'JSON scalar' => ['"sensitive details"', 200];
    }

    #[DataProvider('transportFailures')]
    public function testTransportFailuresRaiseCleanAuthenticationException(bool $duringRequest): void
    {
        $httpClient = new MockHttpClient(static function () use ($duringRequest): MockResponse {
            if ($duringRequest) {
                throw new TransportException('Sensitive connection details');
            }

            return new MockResponse([new TransportException('Sensitive response details')]);
        });
        $client = $this->createClient($httpClient);

        $this->expectException(SalesforceAuthenticationException::class);
        $this->expectExceptionMessage('Salesforce authentication failed.');

        $client->getAccessToken();
    }

    public static function transportFailures(): iterable
    {
        yield 'request fails' => [true];
        yield 'response stream fails' => [false];
    }

    #[DataProvider('invalidTokenResponses')]
    public function testRejectsResponsesWithoutUsableAccessToken(string $body): void
    {
        $client = $this->createClient(new MockHttpClient(new MockResponse($body)));

        $this->expectException(SalesforceAuthenticationException::class);
        $this->expectExceptionMessage('Salesforce authentication failed.');

        $client->getAccessToken();
    }

    public static function invalidTokenResponses(): iterable
    {
        yield 'missing token' => ['{"error":"invalid_client"}'];
        yield 'null token' => ['{"access_token":null}'];
        yield 'numeric token' => ['{"access_token":123}'];
        yield 'boolean token' => ['{"access_token":true}'];
        yield 'array token' => ['{"access_token":["token"]}'];
        yield 'empty token' => ['{"access_token":""}'];
        yield 'blank token' => ['{"access_token":" \t\n"}'];
    }

    private function createClient(MockHttpClient $httpClient, string $baseUrl = 'https://acme.my.salesforce.com'): SalesforceClient
    {
        return new SalesforceClient($httpClient, $baseUrl, 'client-id+&=', 'client-secret+&= /', 'v66.0');
    }
}
