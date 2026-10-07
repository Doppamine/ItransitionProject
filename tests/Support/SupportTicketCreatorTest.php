<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Position;
use App\Entity\User;
use App\Enum\PositionAccessType;
use App\Integration\Dropbox\DropboxClient;
use App\Support\SupportTicketCreator;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class SupportTicketCreatorTest extends TestCase
{
    public function testUploadsFixedContractWithStoredReporterRolesAndUniqueSafeFileNames(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::exactly(2))->method('fetchFirstColumn')->willReturn(['a@example.test', 'z@example.test']);
        $uploads = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$uploads): MockResponse {
            if ($url === 'https://api.dropbox.com/oauth2/token') {
                return new MockResponse('{"access_token":"test-token"}');
            }
            self::assertSame('https://content.dropboxapi.com/2/files/upload', $url);
            $metadata = json_decode(substr($options['normalized_headers']['dropbox-api-arg'][0], strlen('Dropbox-API-Arg: ')), true, 512, JSON_THROW_ON_ERROR);
            $uploads[] = [basename($metadata['path']), json_decode($options['body'], true, 512, JSON_THROW_ON_ERROR)];

            return new MockResponse('{"id":"id:test","name":"ticket.json","path_display":"/Support/ticket.json"}');
        });
        $creator = new SupportTicketCreator(new DropboxClient($http, 'key', 'secret', 'refresh', '/Support'), $connection);
        $user = new User('user@example.test');
        $user->setRoles(['ROLE_ADMIN', 'ROLE_CANDIDATE']);
        $position = new Position('Junior PHP Developer', '', PositionAccessType::PUBLIC, 0);

        self::assertTrue($creator->create($user, 'Не могу отправить CV "test"', 'Average', 'https://example.com/positions/1', $position));
        self::assertTrue($creator->create($user, 'Other problem', 'Low', 'https://example.com/'));

        self::assertSame([
            'summary' => 'Не могу отправить CV "test"',
            'reportedBy' => ['email' => 'user@example.test', 'roles' => ['ROLE_ADMIN', 'ROLE_CANDIDATE']],
            'positionTitle' => 'Junior PHP Developer',
            'pageUrl' => 'https://example.com/positions/1',
            'priority' => 'Average',
            'adminEmails' => ['a@example.test', 'z@example.test'],
        ], $uploads[0][1]);
        self::assertSame('', $uploads[1][1]['positionTitle']);
        self::assertSame('Low', $uploads[1][1]['priority']);
        foreach ($uploads as [$name]) {
            self::assertMatchesRegularExpression('/\Asupport-[0-9]{8}T[0-9]{6}Z-[a-f0-9]{32}\.json\z/', $name);
        }
        self::assertNotSame($uploads[0][0], $uploads[1][0]);
    }

    public function testNoAdministratorsPreventsUpload(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('fetchFirstColumn')->willReturn([]);
        $http = new MockHttpClient(static function (): never { self::fail('No upload should occur without administrators.'); });
        $creator = new SupportTicketCreator(new DropboxClient($http, 'key', 'secret', 'refresh', '/Support'), $connection);

        self::assertFalse($creator->create(new User('user@example.test'), 'Problem', 'High', 'https://example.com/'));
        self::assertSame(0, $http->getRequestsCount());
    }

    public function testJsonEncodingFailureDoesNotUpload(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('fetchFirstColumn')->willReturn(['admin@example.test']);
        $http = new MockHttpClient(static function (): never { self::fail('Invalid UTF-8 must not be uploaded.'); });
        $creator = new SupportTicketCreator(new DropboxClient($http, 'key', 'secret', 'refresh', '/Support'), $connection);

        $this->expectException(\JsonException::class);
        $creator->create(new User('user@example.test'), "\xFF", 'High', 'https://example.com/');
    }
}
