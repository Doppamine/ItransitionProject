<?php

declare(strict_types=1);

namespace App\Tests\Integration\Dropbox;

use App\Integration\Dropbox\DropboxApiException;
use App\Integration\Dropbox\DropboxAuthenticationException;
use App\Integration\Dropbox\DropboxClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class DropboxClientTest extends TestCase
{
    #[DataProvider('supportFolders')]
    public function testRefreshesTokenAndUploadsUnchangedJson(string $folder): void
    {
        $json = "{\n  \"message\": \"Тест café \\\"quoted\\\"\"\n}\n";
        $httpClient = new MockHttpClient([
            static function (string $method, string $url, array $options): MockResponse {
                self::assertSame('POST', $method);
                self::assertSame('https://api.dropbox.com/oauth2/token', $url);
                self::assertContains('Content-Type: application/x-www-form-urlencoded', $options['headers']);
                self::assertContains('Authorization: Basic '.base64_encode('app-key:app-secret+&= /'), $options['headers']);
                self::assertSame(0, $options['max_redirects']);
                parse_str($options['body'], $parameters);
                self::assertSame([
                    'grant_type' => 'refresh_token',
                    'refresh_token' => 'refresh-token+&= /',
                ], $parameters);

                return new MockResponse('{"access_token":"access-token","token_type":"bearer","expires_in":14400}');
            },
            static function (string $method, string $url, array $options) use ($json): MockResponse {
                self::assertSame('POST', $method);
                self::assertSame('https://content.dropboxapi.com/2/files/upload', $url);
                self::assertContains('Authorization: Bearer access-token', $options['headers']);
                self::assertContains('Content-Type: application/octet-stream', $options['headers']);
                self::assertSame(0, $options['max_redirects']);
                self::assertSame([
                    'path' => '/Support/Tickets/ticket-123_abc.json',
                    'mode' => 'add',
                    'autorename' => true,
                    'mute' => false,
                ], json_decode(substr($options['normalized_headers']['dropbox-api-arg'][0], strlen('Dropbox-API-Arg: ')), true, 512, JSON_THROW_ON_ERROR));
                self::assertSame($json, $options['body']);

                return new MockResponse('{".tag":"file","id":"id:ticket","name":"ticket-123_abc (1).json","path_display":"/Support/Tickets/ticket-123_abc (1).json"}');
            },
        ]);

        $result = $this->createClient($httpClient, $folder)->uploadJson('ticket-123_abc.json', $json);

        self::assertSame('id:ticket', $result->id);
        self::assertSame('ticket-123_abc (1).json', $result->name);
        self::assertSame('/Support/Tickets/ticket-123_abc (1).json', $result->pathDisplay);
        self::assertSame(2, $httpClient->getRequestsCount());
    }

    public static function supportFolders(): iterable
    {
        yield 'without trailing slash' => ['/Support/Tickets'];
        yield 'with trailing slash' => ['/Support/Tickets/'];
    }

    #[DataProvider('invalidFileNames')]
    public function testRejectsInvalidFileNamesBeforeHttp(string $fileName): void
    {
        $httpClient = new MockHttpClient(static function (): never {
            self::fail('Invalid filenames must not trigger HTTP requests.');
        });
        $client = $this->createClient($httpClient);

        $this->expectException(\InvalidArgumentException::class);

        try {
            $client->uploadJson($fileName, '{}');
        } finally {
            self::assertSame(0, $httpClient->getRequestsCount());
        }
    }

    public static function invalidFileNames(): iterable
    {
        foreach (['', ' ', "\t\n", '.', '..', '../ticket.json', 'sub/ticket.json', 'sub\\ticket.json', '/ticket.json', '\\ticket.json', '.hidden.json', 'ticket.json/..', 'ticket name.json', 'ticket.json ', "ticket\n.json", "ticket\0.json", 'ticket.txt', 'ticket', 'ticket?.json', 'ticket:123.json'] as $fileName) {
            yield 'filename '.bin2hex($fileName) => [$fileName];
        }
    }

    #[DataProvider('failedTokenResponses')]
    public function testTokenFailuresAreSanitizedAndStopUpload(string $body, int $statusCode): void
    {
        $httpClient = new MockHttpClient(new MockResponse($body, ['http_code' => $statusCode]));

        $this->assertAuthenticationFailure($httpClient);
        self::assertSame(1, $httpClient->getRequestsCount());
    }

    public static function failedTokenResponses(): iterable
    {
        yield 'bad credentials' => ['{"error":"app-secret+&= / refresh-token+&= / access-token raw-error"}', 400];
        yield 'unauthorized' => ['{"access_token":"access-token"}', 401];
        yield 'server failure' => ['app-secret+&= / refresh-token+&= / access-token raw-error', 500];
        yield 'redirect' => ['{"access_token":"access-token"}', 302];
        yield 'malformed JSON' => ['app-secret+&= / refresh-token+&= / access-token raw-error', 200];
        yield 'empty body' => ['', 200];
        yield 'JSON scalar' => ['"access-token"', 200];
        yield 'missing token' => ['{}', 200];
        yield 'null token' => ['{"access_token":null}', 200];
        yield 'numeric token' => ['{"access_token":123}', 200];
        yield 'boolean token' => ['{"access_token":true}', 200];
        yield 'array token' => ['{"access_token":["access-token"]}', 200];
        yield 'empty token' => ['{"access_token":""}', 200];
        yield 'blank token' => ['{"access_token":" \t\n"}', 200];
    }

    #[DataProvider('transportFailures')]
    public function testTokenTransportFailuresAreSanitized(bool $duringRequest): void
    {
        $httpClient = new MockHttpClient(static function () use ($duringRequest): MockResponse {
            $exception = new TransportException('app-secret+&= / refresh-token+&= / access-token raw-error');
            if ($duringRequest) {
                throw $exception;
            }

            return new MockResponse([$exception]);
        });

        $this->assertAuthenticationFailure($httpClient);
    }

    public static function transportFailures(): iterable
    {
        yield 'request failure' => [true];
        yield 'response stream failure' => [false];
    }

    #[DataProvider('failedUploadResponses')]
    public function testUploadFailuresAreSanitized(string $body, int $statusCode): void
    {
        $httpClient = new MockHttpClient([
            new MockResponse('{"access_token":"access-token"}'),
            new MockResponse($body, ['http_code' => $statusCode]),
        ]);

        $this->assertUploadFailure($this->createClient($httpClient));
        self::assertSame(2, $httpClient->getRequestsCount());
    }

    public static function failedUploadResponses(): iterable
    {
        yield 'bad request' => ['{"error":"app-secret+&= / refresh-token+&= / access-token raw-error"}', 400];
        yield 'unauthorized' => ['{"error_summary":"access-token raw-error"}', 401];
        yield 'conflict' => ['{"error_summary":"path/conflict raw-error"}', 409];
        yield 'server failure' => ['app-secret+&= / refresh-token+&= / access-token raw-error', 500];
        yield 'redirect' => ['{}', 302];
        yield 'malformed JSON' => ['app-secret+&= / refresh-token+&= / access-token raw-error', 200];
        yield 'empty body' => ['', 200];
        yield 'JSON scalar' => ['"access-token"', 200];

        foreach (['id', 'name', 'path_display'] as $field) {
            $data = ['id' => 'id:ticket', 'name' => 'ticket.json', 'path_display' => '/Support/Tickets/ticket.json'];
            unset($data[$field]);
            yield 'missing '.$field => [json_encode($data, JSON_THROW_ON_ERROR), 200];

            foreach (['null' => null, 'number' => 123, 'boolean' => true, 'array' => ['raw-error'], 'empty' => '', 'blank' => " \t\n"] as $label => $value) {
                $data[$field] = $value;
                yield $label.' '.$field => [json_encode($data, JSON_THROW_ON_ERROR), 200];
            }
        }
    }

    #[DataProvider('transportFailures')]
    public function testUploadTransportFailuresAreSanitized(bool $duringRequest): void
    {
        $httpClient = new MockHttpClient([
            new MockResponse('{"access_token":"access-token"}'),
            static function () use ($duringRequest): MockResponse {
                $exception = new TransportException('app-secret+&= / refresh-token+&= / access-token raw-error');
                if ($duringRequest) {
                    throw $exception;
                }

                return new MockResponse([$exception]);
            },
        ]);

        $this->assertUploadFailure($this->createClient($httpClient));
    }

    public function testInvalidMetadataEncodingRaisesSanitizedUploadException(): void
    {
        $httpClient = new MockHttpClient(new MockResponse('{"access_token":"access-token"}'));

        $this->assertUploadFailure($this->createClient($httpClient, "/Support/\xFF"));
        self::assertSame(1, $httpClient->getRequestsCount());
    }

    private function assertUploadFailure(DropboxClient $client): void
    {
        try {
            $client->uploadJson('ticket.json', '{}');
        } catch (\Throwable $exception) {
            self::assertInstanceOf(DropboxApiException::class, $exception);
            self::assertSame('Dropbox upload failed.', $exception->getMessage());
            self::assertNull($exception->getPrevious());
            $this->assertSanitized($exception);

            return;
        }

        self::fail('A Dropbox upload failure was expected.');
    }

    private function assertAuthenticationFailure(MockHttpClient $httpClient): void
    {
        try {
            $this->createClient($httpClient)->uploadJson('ticket.json', '{}');
        } catch (\Throwable $exception) {
            self::assertInstanceOf(DropboxAuthenticationException::class, $exception);
            self::assertSame('Dropbox authentication failed.', $exception->getMessage());
            self::assertNull($exception->getPrevious());
            $this->assertSanitized($exception);

            return;
        }

        self::fail('A Dropbox authentication failure was expected.');
    }

    private function assertSanitized(\Throwable $exception): void
    {
        foreach (['app-key', 'app-secret+&= /', 'refresh-token+&= /', 'access-token', 'raw-error'] as $sensitiveValue) {
            self::assertStringNotContainsString($sensitiveValue, $exception->getMessage());
        }
    }

    private function createClient(MockHttpClient $httpClient, string $folder = '/Support/Tickets'): DropboxClient
    {
        return new DropboxClient($httpClient, 'app-key', 'app-secret+&= /', 'refresh-token+&= /', $folder);
    }
}
