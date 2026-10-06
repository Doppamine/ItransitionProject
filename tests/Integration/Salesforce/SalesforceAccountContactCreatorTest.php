<?php

declare(strict_types=1);

namespace App\Tests\Integration\Salesforce;

use App\Integration\Salesforce\SalesforceAccountContactCreator;
use App\Integration\Salesforce\SalesforceAccountContactInput;
use App\Integration\Salesforce\SalesforceClient;
use App\Integration\Salesforce\SalesforceCompositeException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class SalesforceAccountContactCreatorTest extends TestCase
{
    public function testCreatesAccountAndLinkedContactInOneAtomicCompositeRequest(): void
    {
        $httpClient = new MockHttpClient([
            new MockResponse('{"access_token":"composite-test-token"}'),
            static function (string $method, string $url, array $options): MockResponse {
                self::assertSame('POST', $method);
                self::assertSame('https://acme.my.salesforce.com/services/data/v66.0/composite', $url);
                self::assertSame([
                    'allOrNone' => true,
                    'compositeRequest' => [
                        [
                            'method' => 'POST',
                            'url' => '/services/data/v66.0/sobjects/Account',
                            'referenceId' => 'account',
                            'body' => ['Name' => 'Example Company'],
                        ],
                        [
                            'method' => 'POST',
                            'url' => '/services/data/v66.0/sobjects/Contact',
                            'referenceId' => 'contact',
                            'body' => ['AccountId' => '@{account.id}', 'LastName' => 'Doe'],
                        ],
                    ],
                ], json_decode($options['body'], true, 512, JSON_THROW_ON_ERROR));

                return self::successfulResponse();
            },
        ]);
        $creator = $this->createCreator($httpClient);

        $result = $creator->create(new SalesforceAccountContactInput('Example Company', 'Doe'));

        self::assertSame('001000000000001AAA', $result->accountId);
        self::assertSame('003000000000001AAA', $result->contactId);
        self::assertSame(2, $httpClient->getRequestsCount());
    }

    public function testMapsAllOptionalAccountAndContactFields(): void
    {
        $httpClient = new MockHttpClient([
            new MockResponse('{"access_token":"composite-test-token"}'),
            static function (string $method, string $url, array $options): MockResponse {
                $payload = json_decode($options['body'], true, 512, JSON_THROW_ON_ERROR);
                self::assertSame([
                    'Name' => 'Example Company',
                    'Website' => 'https://example.com',
                ], $payload['compositeRequest'][0]['body']);
                self::assertSame([
                    'AccountId' => '@{account.id}',
                    'FirstName' => 'John',
                    'LastName' => 'Doe',
                    'Email' => 'john@example.com',
                    'Title' => 'Engineer',
                    'Phone' => '+1 555 123 4567',
                    'MailingCity' => 'Almaty',
                ], $payload['compositeRequest'][1]['body']);

                return self::successfulResponse();
            },
        ]);
        $creator = $this->createCreator($httpClient);

        $result = $creator->create(new SalesforceAccountContactInput(
            companyName: 'Example Company',
            lastName: 'Doe',
            website: 'https://example.com',
            firstName: 'John',
            email: 'john@example.com',
            jobTitle: 'Engineer',
            phone: '+1 555 123 4567',
            location: 'Almaty',
        ));

        self::assertSame('001000000000001AAA', $result->accountId);
        self::assertSame('003000000000001AAA', $result->contactId);
        self::assertSame(2, $httpClient->getRequestsCount());
    }

    public function testOmitsEmptyOptionalFields(): void
    {
        $httpClient = new MockHttpClient([
            new MockResponse('{"access_token":"composite-test-token"}'),
            static function (string $method, string $url, array $options): MockResponse {
                $payload = json_decode($options['body'], true, 512, JSON_THROW_ON_ERROR);
                self::assertSame(['Name' => 'Example Company'], $payload['compositeRequest'][0]['body']);
                self::assertSame(['AccountId' => '@{account.id}', 'LastName' => 'Doe'], $payload['compositeRequest'][1]['body']);

                return self::successfulResponse();
            },
        ]);

        $this->createCreator($httpClient)->create(new SalesforceAccountContactInput(
            companyName: 'Example Company',
            lastName: 'Doe',
            website: '',
            firstName: " \t",
            email: ' ',
            jobTitle: '',
            phone: "\n",
            location: "\r\n",
        ));

        self::assertSame(2, $httpClient->getRequestsCount());
    }

    #[DataProvider('blankRequiredFields')]
    public function testRejectsBlankRequiredFieldsBeforeHttp(string $companyName, string $lastName, string $message): void
    {
        $httpClient = new MockHttpClient(new MockResponse('{}'));
        $creator = $this->createCreator($httpClient);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        try {
            $creator->create(new SalesforceAccountContactInput($companyName, $lastName));
        } finally {
            self::assertSame(0, $httpClient->getRequestsCount());
        }
    }

    public static function blankRequiredFields(): iterable
    {
        yield 'empty company' => ['', 'Doe', 'Company name must not be blank.'];
        yield 'blank company' => [" \t\r\n", 'Doe', 'Company name must not be blank.'];
        yield 'empty last name' => ['Example Company', '', 'Contact last name must not be blank.'];
        yield 'blank last name' => ['Example Company', " \n\t", 'Contact last name must not be blank.'];
    }

    public function testUsesConfiguredVersionAndMatchesResponseReferenceIds(): void
    {
        $httpClient = new MockHttpClient([
            new MockResponse('{"access_token":"composite-test-token"}'),
            static function (string $method, string $url, array $options): MockResponse {
                self::assertSame('https://acme.my.salesforce.com/services/data/v65.0/composite', $url);
                $payload = json_decode($options['body'], true, 512, JSON_THROW_ON_ERROR);
                self::assertSame('/services/data/v65.0/sobjects/Account', $payload['compositeRequest'][0]['url']);
                self::assertSame('/services/data/v65.0/sobjects/Contact', $payload['compositeRequest'][1]['url']);

                return new MockResponse('{"compositeResponse":[{"body":{"id":"003000000000001AAA","success":true,"errors":[]},"httpStatusCode":201,"referenceId":"contact"},{"body":{"id":"001000000000001AAA","success":true,"errors":[]},"httpStatusCode":201,"referenceId":"account"}]}');
            },
        ]);

        $result = $this->createCreator($httpClient, '65.0')->create(new SalesforceAccountContactInput('Example Company', 'Doe'));

        self::assertSame('001000000000001AAA', $result->accountId);
        self::assertSame('003000000000001AAA', $result->contactId);
        self::assertSame(2, $httpClient->getRequestsCount());
    }

    #[DataProvider('failedCompositeResponses')]
    public function testRejectsFailedOrMalformedCompositeResponseDespiteOuterHttpSuccess(array $response): void
    {
        $httpClient = new MockHttpClient([
            new MockResponse('{"access_token":"composite-test-token"}'),
            new MockResponse(json_encode($response, JSON_THROW_ON_ERROR), ['http_code' => 200]),
        ]);

        $this->assertCompositeFailure($httpClient);
    }

    public static function failedCompositeResponses(): iterable
    {
        $account = ['referenceId' => 'account', 'httpStatusCode' => 201, 'body' => ['id' => '001000000000001AAA', 'success' => true, 'errors' => []]];
        $contact = ['referenceId' => 'contact', 'httpStatusCode' => 201, 'body' => ['id' => '003000000000001AAA', 'success' => true, 'errors' => []]];
        $error = [['message' => 'Sensitive Salesforce details', 'errorCode' => 'REQUIRED_FIELD_MISSING']];

        yield 'Account inner failure' => [['compositeResponse' => [array_replace($account, ['httpStatusCode' => 400, 'body' => $error]), $contact]]];
        yield 'Contact inner failure' => [['compositeResponse' => [$account, array_replace($contact, ['httpStatusCode' => 400, 'body' => $error])]]];
        yield 'inner server failure' => [['compositeResponse' => [$account, array_replace($contact, ['httpStatusCode' => 500])]]];
        yield 'inner redirect' => [['compositeResponse' => [array_replace($account, ['httpStatusCode' => 302]), $contact]]];
        yield 'inner informational status' => [['compositeResponse' => [array_replace($account, ['httpStatusCode' => 199]), $contact]]];
        yield 'missing compositeResponse' => [[]];
        yield 'null compositeResponse' => [['compositeResponse' => null]];
        yield 'scalar compositeResponse' => [['compositeResponse' => 'unexpected']];
        yield 'object instead of response list' => [['compositeResponse' => ['account' => $account, 'contact' => $contact]]];
        yield 'missing Account reference' => [['compositeResponse' => [$contact]]];
        yield 'missing Contact reference' => [['compositeResponse' => [$account]]];
        yield 'unexpected reference' => [['compositeResponse' => [array_replace($account, ['referenceId' => 'unexpected']), $contact]]];
        yield 'duplicate reference' => [['compositeResponse' => [$account, $account]]];
        yield 'extra response' => [['compositeResponse' => [$account, $contact, $account]]];
        yield 'scalar response entry' => [['compositeResponse' => ['unexpected', $contact]]];
        yield 'missing status' => [['compositeResponse' => [array_replace($account, ['httpStatusCode' => null]), $contact]]];
        yield 'string status' => [['compositeResponse' => [$account, array_replace($contact, ['httpStatusCode' => '201'])]]];
        yield 'missing Account ID' => [['compositeResponse' => [array_replace($account, ['body' => ['success' => true, 'errors' => []]]), $contact]]];
        yield 'missing Contact ID' => [['compositeResponse' => [$account, array_replace($contact, ['body' => ['success' => true, 'errors' => []]])]]];
        yield 'blank Account ID' => [['compositeResponse' => [array_replace($account, ['body' => ['id' => ' ']]), $contact]]];
        yield 'numeric Contact ID' => [['compositeResponse' => [$account, array_replace($contact, ['body' => ['id' => 123]])]]];
        yield 'invalid Account ID format' => [['compositeResponse' => [array_replace($account, ['body' => ['id' => 'not-a-salesforce-id']]), $contact]]];
        yield 'array Contact ID' => [['compositeResponse' => [$account, array_replace($contact, ['body' => ['id' => ['003000000000001AAA']]])]]];
        yield 'scalar body' => [['compositeResponse' => [array_replace($account, ['body' => 'unexpected']), $contact]]];
        yield 'explicit unsuccessful body' => [['compositeResponse' => [$account, array_replace($contact, ['body' => ['id' => '003000000000001AAA', 'success' => false, 'errors' => $error]])]]];
        yield 'errors in successful body' => [['compositeResponse' => [array_replace($account, ['body' => ['id' => '001000000000001AAA', 'success' => true, 'errors' => $error]]), $contact]]];
    }

    #[DataProvider('outerFailures')]
    public function testSanitizesOuterApiFailures(string $body, int $status): void
    {
        $httpClient = new MockHttpClient([
            new MockResponse('{"access_token":"composite-test-token"}'),
            new MockResponse($body, ['http_code' => $status]),
        ]);

        $this->assertCompositeFailure($httpClient);
        self::assertSame(2, $httpClient->getRequestsCount());
    }

    public static function outerFailures(): iterable
    {
        yield 'outer client failure' => ['[{"message":"client-secret composite-test-token"}]', 400];
        yield 'outer server failure' => ['Sensitive Salesforce details', 500];
        yield 'malformed JSON' => ['{"client-secret":', 200];
    }

    public function testSanitizesTransportFailure(): void
    {
        $httpClient = new MockHttpClient([
            new MockResponse('{"access_token":"composite-test-token"}'),
            static function (): never {
                throw new TransportException('client-secret composite-test-token sensitive transport details');
            },
        ]);

        $this->assertCompositeFailure($httpClient);
    }

    public function testSanitizesAuthenticationFailureAndStopsBeforeComposite(): void
    {
        $httpClient = new MockHttpClient(new MockResponse('client-secret sensitive OAuth details', ['http_code' => 401]));

        $this->assertCompositeFailure($httpClient);
        self::assertSame(1, $httpClient->getRequestsCount());
    }

    public function testPreservesNonEmptyZeroAndAcceptsFifteenCharacterIds(): void
    {
        $httpClient = new MockHttpClient([
            new MockResponse('{"access_token":"composite-test-token"}'),
            static function (string $method, string $url, array $options): MockResponse {
                $payload = json_decode($options['body'], true, 512, JSON_THROW_ON_ERROR);
                self::assertSame('0', $payload['compositeRequest'][1]['body']['Phone']);

                return new MockResponse('{"compositeResponse":[{"body":{"id":"001000000000001"},"httpStatusCode":200,"referenceId":"account"},{"body":{"id":"003000000000001"},"httpStatusCode":201,"referenceId":"contact"}]}');
            },
        ]);

        $result = $this->createCreator($httpClient)->create(new SalesforceAccountContactInput('Example Company', 'Doe', phone: '0'));

        self::assertSame('001000000000001', $result->accountId);
        self::assertSame('003000000000001', $result->contactId);
        self::assertSame(2, $httpClient->getRequestsCount());
    }

    private function assertCompositeFailure(MockHttpClient $httpClient): void
    {
        try {
            $this->createCreator($httpClient)->create(new SalesforceAccountContactInput('Example Company', 'Doe'));
            self::fail('A Salesforce composite failure was expected.');
        } catch (SalesforceCompositeException $exception) {
            self::assertSame('Salesforce Account and Contact creation failed.', $exception->getMessage());
            self::assertNull($exception->getPrevious());
        }
    }

    private static function successfulResponse(): MockResponse
    {
        return new MockResponse('{"compositeResponse":[{"body":{"id":"001000000000001AAA","success":true,"errors":[]},"httpHeaders":{},"httpStatusCode":201,"referenceId":"account"},{"body":{"id":"003000000000001AAA","success":true,"errors":[]},"httpHeaders":{},"httpStatusCode":201,"referenceId":"contact"}]}');
    }

    private function createCreator(MockHttpClient $httpClient, string $apiVersion = 'v66.0'): SalesforceAccountContactCreator
    {
        return new SalesforceAccountContactCreator(new SalesforceClient(
            $httpClient,
            'https://acme.my.salesforce.com',
            'client-id',
            'client-secret',
            $apiVersion,
        ));
    }
}
