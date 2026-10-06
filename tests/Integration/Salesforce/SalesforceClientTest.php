<?php

declare(strict_types=1);

namespace App\Tests\Integration\Salesforce;

use App\Integration\Salesforce\SalesforceApiException;
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

    public function testGetRequestAuthenticatesBeforeRequestingJson(): void
    {
        $httpClient = new MockHttpClient([
            static function (string $method, string $url): MockResponse {
                self::assertSame('POST', $method);
                self::assertSame('https://acme.my.salesforce.com/services/oauth2/token', $url);

                return new MockResponse('{"access_token":"rest-access-token"}');
            },
            static function (string $method, string $url, array $options): MockResponse {
                self::assertSame('GET', $method);
                self::assertSame('https://acme.my.salesforce.com/services/data/v66.0/limits', $url);
                self::assertContains('Authorization: Bearer rest-access-token', $options['headers']);
                self::assertContains('Accept: application/json', $options['headers']);
                self::assertNull($options['body']);
                self::assertSame(0, $options['max_redirects']);

                return new MockResponse('{"DailyApiRequests":{"Max":15000,"Remaining":14999}}');
            },
        ]);
        $client = $this->createClient($httpClient);

        self::assertSame(['DailyApiRequests' => ['Max' => 15000, 'Remaining' => 14999]], $client->request('GET', 'limits'));
    }

    public function testPostRequestEncodesJsonAndDecodesCreatedResponse(): void
    {
        $httpClient = new MockHttpClient([
            new MockResponse('{"access_token":"post-access-token"}'),
            static function (string $method, string $url, array $options): MockResponse {
                self::assertSame('POST', $method);
                self::assertSame('https://acme.my.salesforce.com/services/data/v66.0/sobjects/Example__c', $url);
                self::assertContains('Authorization: Bearer post-access-token', $options['headers']);
                self::assertContains('Accept: application/json', $options['headers']);
                self::assertContains('Content-Type: application/json', $options['headers']);
                self::assertSame([
                    'Name' => 'Example "quoted" \\ value',
                    'Enabled__c' => true,
                    'Count__c' => 3,
                ], json_decode($options['body'], true, 512, JSON_THROW_ON_ERROR));

                return new MockResponse('{"id":"example-id","success":true,"errors":[]}', ['http_code' => 201]);
            },
        ]);
        $client = $this->createClient($httpClient);

        self::assertSame(['id' => 'example-id', 'success' => true, 'errors' => []], $client->request('POST', 'sobjects/Example__c', [
            'Name' => 'Example "quoted" \\ value',
            'Enabled__c' => true,
            'Count__c' => 3,
        ]));
    }

    #[DataProvider('restUrls')]
    public function testConstructsVersionedRestUrl(string $baseUrl, string $apiVersion, string $path, string $expectedUrl): void
    {
        $httpClient = new MockHttpClient([
            new MockResponse('{"access_token":"url-test-token","instance_url":"https://different.my.salesforce.com"}'),
            static function (string $method, string $url) use ($expectedUrl): MockResponse {
                self::assertSame($expectedUrl, $url);

                return new MockResponse('{}');
            },
        ]);
        $client = $this->createClient($httpClient, $baseUrl, $apiVersion);

        self::assertSame([], $client->request('GET', $path));
    }

    public static function restUrls(): iterable
    {
        yield 'bare version' => [
            'https://acme.my.salesforce.com', '66.0', 'limits',
            'https://acme.my.salesforce.com/services/data/v66.0/limits',
        ];
        yield 'trailing base slash and leading path slash' => [
            'https://acme.my.salesforce.com/', 'v66.0', '/limits',
            'https://acme.my.salesforce.com/services/data/v66.0/limits',
        ];
        yield 'different version and query string' => [
            'https://acme.my.salesforce.com', 'v65.0', 'query?q=SELECT%20Id%20FROM%20Example__c',
            'https://acme.my.salesforce.com/services/data/v65.0/query?q=SELECT%20Id%20FROM%20Example__c',
        ];
    }

    #[DataProvider('failedApiResponses')]
    public function testApiResponseFailuresRaiseCleanApiException(string $method, string $body, int $statusCode): void
    {
        $client = $this->createClient(new MockHttpClient([
            new MockResponse('{"access_token":"rest-access-token"}'),
            new MockResponse($body, ['http_code' => $statusCode]),
        ]));

        $this->assertApiFailure($client, $method);
    }

    public static function failedApiResponses(): iterable
    {
        yield 'bad POST request' => ['POST', '[{"message":"Sensitive Salesforce details","errorCode":"INVALID_FIELD"}]', 400];
        yield 'unauthorized GET' => ['GET', '{"message":"Sensitive token details"}', 401];
        yield 'forbidden GET' => ['GET', '{}', 403];
        yield 'server error' => ['GET', '<html>Sensitive server details</html>', 500];
        yield 'redirect' => ['GET', '{}', 302];
        yield 'informational status' => ['GET', '{}', 199];
        yield 'invalid JSON' => ['GET', 'not JSON: sensitive details', 200];
        yield 'empty body' => ['GET', '', 200];
        yield 'JSON scalar' => ['GET', '"sensitive details"', 200];
    }

    #[DataProvider('transportFailures')]
    public function testApiTransportFailuresRaiseCleanApiException(bool $duringRequest): void
    {
        $httpClient = new MockHttpClient([
            new MockResponse('{"access_token":"rest-access-token"}'),
            static function () use ($duringRequest): MockResponse {
                if ($duringRequest) {
                    throw new TransportException('Sensitive REST connection details');
                }

                return new MockResponse([new TransportException('Sensitive REST response details')]);
            },
        ]);

        $this->assertApiFailure($this->createClient($httpClient));
    }

    public function testAuthenticationFailureStopsRestRequest(): void
    {
        $httpClient = new MockHttpClient(new MockResponse('{"error":"invalid_client"}', ['http_code' => 400]));
        $client = $this->createClient($httpClient);

        $this->expectException(SalesforceAuthenticationException::class);
        $this->expectExceptionMessage('Salesforce authentication failed.');

        try {
            $client->request('GET', 'limits');
        } finally {
            self::assertSame(1, $httpClient->getRequestsCount());
        }
    }

    private function assertApiFailure(SalesforceClient $client, string $method = 'GET'): void
    {
        try {
            $client->request($method, 'limits');
            self::fail('A Salesforce API failure was expected.');
        } catch (SalesforceApiException $exception) {
            self::assertSame('Salesforce API request failed.', $exception->getMessage());
            self::assertNull($exception->getPrevious());
        }
    }

    private function createClient(MockHttpClient $httpClient, string $baseUrl = 'https://acme.my.salesforce.com', string $apiVersion = 'v66.0'): SalesforceClient
    {
        return new SalesforceClient($httpClient, $baseUrl, 'client-id+&=', 'client-secret+&= /', $apiVersion);
    }
}
