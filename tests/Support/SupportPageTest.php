<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Position;
use App\Entity\User;
use App\Enum\PositionAccessType;
use App\Integration\Dropbox\DropboxClient;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class SupportPageTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $user;
    private MockHttpClient $http;
    private array $uploads = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->http = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            if ($url === 'https://api.dropbox.com/oauth2/token') {
                return new MockResponse('{"access_token":"test-token"}');
            }
            self::assertSame('https://content.dropboxapi.com/2/files/upload', $url);
            $metadata = json_decode(substr($options['normalized_headers']['dropbox-api-arg'][0], strlen('Dropbox-API-Arg: ')), true, 512, JSON_THROW_ON_ERROR);
            self::assertMatchesRegularExpression('/\A\/Support\/support-[0-9]{8}T[0-9]{6}Z-[a-f0-9]{32}\.json\z/', $metadata['path']);
            $this->uploads[] = json_decode($options['body'], true, 512, JSON_THROW_ON_ERROR);

            return new MockResponse('{"id":"id:test","name":"ticket.json","path_display":"/Support/ticket.json"}');
        });
        static::getContainer()->set(DropboxClient::class, new DropboxClient($this->http, 'key', 'secret', 'refresh', '/Support'));
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();
        $this->user = new User('support-user-'.bin2hex(random_bytes(5)).'@example.test');
        $this->user->setRoles(['ROLE_CANDIDATE']);
        $this->em->persist($this->user);
        $this->em->flush();
        $this->client->loginUser($this->user);
    }

    protected function tearDown(): void
    {
        if (isset($this->em) && $this->em->getConnection()->isTransactionActive()) {
            $this->em->getConnection()->rollBack();
            $this->em->clear();
        }
        parent::tearDown();
    }

    public function testAuthenticatedFormAndAnonymousAccess(): void
    {
        $this->client->request('GET', '/support');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Support');
        self::assertSelectorExists('textarea[name="support_ticket[summary]"][required][maxlength="2000"]');
        self::assertSelectorExists('select[name="support_ticket[priority]"]');
        self::assertSelectorExists('input[name="support_ticket[_token]"]');
        self::assertSelectorExists('nav a[href^="/support?from="]');
        foreach (['email', 'roles', 'adminEmails', 'positionTitle'] as $field) {
            self::assertSelectorNotExists('[name="support_ticket['.$field.']"]');
        }

        $this->client->getCookieJar()->clear();
        static::getContainer()->get('security.token_storage')->setToken(null);
        $this->client->request('GET', '/support');
        self::assertResponseRedirects('/login');
        $this->client->request('POST', '/support', ['support_ticket' => ['summary' => 'Problem', 'priority' => 'High']]);
        self::assertResponseRedirects('/login');
        $this->client->request('GET', '/login');
        self::assertSelectorNotExists('nav a[href^="/support"]');
        self::assertSame(0, $this->http->getRequestsCount());
    }

    public function testCreatesVerifiedPositionTicketWithActualAdminsAndPrg(): void
    {
        $suffix = bin2hex(random_bytes(5));
        $adminZ = new User('z-support-'.$suffix.'@example.test');
        $adminZ->setRoles(['ROLE_ADMIN', 'ROLE_CANDIDATE']);
        $adminA = new User('a-support-'.$suffix.'@example.test');
        $adminA->setRoles(['ROLE_ADMIN']);
        $nonAdmin = new User('not-admin-'.$suffix.'@example.test');
        $nonAdmin->setRoles(['ROLE_RECRUITER', 'ROLE_ADMINISTRATOR']);
        $position = new Position('Junior PHP Developer', '', PositionAccessType::PUBLIC, 0);
        $this->em->persist($adminZ);
        $this->em->persist($adminA);
        $this->em->persist($nonAdmin);
        $this->em->persist($position);
        $this->em->flush();
        $expectedAdmins = [];
        foreach ($this->em->getRepository(User::class)->findAll() as $user) {
            if (in_array('ROLE_ADMIN', $user->getRoles(), true)) {
                $expectedAdmins[] = $user->getEmail();
            }
        }
        sort($expectedAdmins, SORT_STRING);
        $from = '/positions/'.$position->getId().'?view=details';
        $path = '/support?'.http_build_query(['from' => $from, 'position' => $position->getId()]);

        $this->submit($path, ['summary' => '  Не могу отправить CV "test"  ', 'priority' => 'Average'], [
            'email' => 'attacker@example.test', 'roles' => ['ROLE_ADMIN'], 'adminEmails' => ['attacker@example.test'], 'positionTitle' => 'Forged',
        ]);

        self::assertResponseRedirects('/support', 303);
        self::assertSame([[
            'summary' => 'Не могу отправить CV "test"',
            'reportedBy' => ['email' => $this->user->getEmail(), 'roles' => ['ROLE_CANDIDATE']],
            'positionTitle' => 'Junior PHP Developer',
            'pageUrl' => 'http://localhost'.$from,
            'priority' => 'Average',
            'adminEmails' => $expectedAdmins,
        ]], $this->uploads);
        self::assertContains($adminA->getEmail(), $this->uploads[0]['adminEmails']);
        self::assertContains($adminZ->getEmail(), $this->uploads[0]['adminEmails']);
        self::assertNotContains($nonAdmin->getEmail(), $this->uploads[0]['adminEmails']);
        self::assertSame(array_values(array_unique($this->uploads[0]['adminEmails'])), $this->uploads[0]['adminEmails']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('[role="status"]', 'Support ticket submitted.');
    }

    public function testCommonNavigationCarriesCurrentPageAndPosition(): void
    {
        $position = new Position('Support context', '', PositionAccessType::PUBLIC, 0);
        $this->em->persist($position);
        $this->em->flush();
        $this->user->setRoles(['ROLE_RECRUITER']);
        $this->em->flush();
        $this->client->loginUser($this->user);
        $this->client->request('GET', '/positions/'.$position->getId());
        self::assertResponseIsSuccessful();
        $href = $this->client->getCrawler()->filter('nav a[href^="/support?"]')->attr('href');
        parse_str(parse_url($href, PHP_URL_QUERY), $query);
        self::assertSame('http://localhost/positions/'.$position->getId(), $query['from']);
        self::assertSame((string) $position->getId(), $query['position']);
    }

    #[DataProvider('invalidForms')]
    public function testInvalidFormsNeverUpload(array $data, string $message): void
    {
        $this->submit('/support', $data + ['summary' => 'Problem', 'priority' => 'High']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', $message);
        self::assertSame(0, $this->http->getRequestsCount());
    }

    public static function invalidForms(): iterable
    {
        yield 'blank summary' => [['summary' => " \t\n "], 'Enter a summary.'];
        yield 'long Unicode summary' => [['summary' => str_repeat('я', 2001)], 'Use at most 2000 characters.'];
        yield 'invalid priority' => [['priority' => 'Urgent'], 'Choose High, Average, or Low.'];
        yield 'blank priority' => [['priority' => ''], 'Choose High, Average, or Low.'];
        yield 'tampered identity fields' => [['email' => 'attacker@example.test', 'roles' => ['ROLE_ADMIN'], 'adminEmails' => ['attacker@example.test']], 'Unexpected form fields were submitted.'];
        yield 'invalid CSRF' => [['_token' => 'forged'], 'CSRF'];
    }

    public function testMissingCsrfAndEmptyPostCannotUpload(): void
    {
        $this->client->request('POST', '/support', ['support_ticket' => ['summary' => 'Problem', 'priority' => 'High']]);
        self::assertResponseStatusCodeSame(422);
        $this->client->request('POST', '/support');
        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->http->getRequestsCount());
    }

    #[DataProvider('sourceUrls')]
    public function testSourceUrlValidationAndUnverifiedPosition(string|array|null $from, string $expected): void
    {
        $this->makeAdmin();
        $query = ['position' => '2147483647', 'positionTitle' => 'Forged'];
        if ($from !== null) {
            $query['from'] = $from;
        }
        $this->submit('/support?'.http_build_query($query), ['summary' => 'Problem', 'priority' => 'High']);

        self::assertResponseRedirects('/support', 303);
        self::assertSame($expected, $this->uploads[0]['pageUrl']);
        self::assertSame('', $this->uploads[0]['positionTitle']);
    }

    public static function sourceUrls(): iterable
    {
        yield 'local URL' => ['/positions?view=all', 'http://localhost/positions?view=all'];
        yield 'same origin absolute URL' => ['http://localhost/positions?view=all', 'http://localhost/positions?view=all'];
        yield 'missing' => [null, 'http://localhost/support'];
        yield 'external' => ['https://attacker.example/path', 'http://localhost/support'];
        yield 'protocol relative' => ['//attacker.example/path', 'http://localhost/support'];
        yield 'javascript' => ['javascript:alert(1)', 'http://localhost/support'];
        yield 'data' => ['data:text/html,test', 'http://localhost/support'];
        yield 'CR LF' => ["/positions\r\nInjected: value", 'http://localhost/support'];
        yield 'encoded CR LF' => ['/positions%0d%0aInjected', 'http://localhost/support'];
        yield 'backslash authority' => ['/\\attacker.example/path', 'http://localhost/support'];
        yield 'different port' => ['http://localhost:8080/positions', 'http://localhost/support'];
        yield 'credentials' => ['http://attacker@localhost/positions', 'http://localhost/support'];
        yield 'array value' => [['unexpected'], 'http://localhost/support'];
        yield 'nonexistent Position source' => ['/positions/2147483647', 'http://localhost/positions/2147483647'];
    }

    public function testTamperedExistingPositionDoesNotAssociateUnrelatedOrRestrictedPage(): void
    {
        $this->makeAdmin();
        $position = new Position('Private Position title', '', PositionAccessType::RESTRICTED, 0);
        $this->em->persist($position);
        $this->em->flush();
        foreach (['/positions', '/positions/'.$position->getId()] as $from) {
            $this->submit('/support?'.http_build_query(['from' => $from, 'position' => $position->getId()]), ['summary' => 'Problem', 'priority' => 'Low']);
            self::assertResponseRedirects('/support', 303);
            self::assertSame('', $this->uploads[array_key_last($this->uploads)]['positionTitle']);
        }
    }

    public function testOutOfRangePositionIdCannotBreakSubmission(): void
    {
        $this->makeAdmin();
        $this->submit('/support?'.http_build_query(['from' => '/positions/9999999999', 'position' => '9999999999']), ['summary' => 'Problem', 'priority' => 'High']);

        self::assertResponseRedirects('/support', 303);
        self::assertSame('', $this->uploads[0]['positionTitle']);
    }

    #[DataProvider('failures')]
    public function testFailuresAreGracefulAndLocalized(string $failure): void
    {
        $this->user->setLocale('ru');
        $this->em->flush();
        $this->client->loginUser($this->user);
        if ($failure === 'no administrators') {
            $this->em->getConnection()->executeStatement('UPDATE app_user SET roles = :roles WHERE roles::jsonb @> CAST(:admin AS jsonb)', ['roles' => '[]', 'admin' => '["ROLE_ADMIN"]']);
        } else {
            $this->makeAdmin();
        }
        $this->http->setResponseFactory(match ($failure) {
            'authentication' => [new MockResponse('secret refresh-token raw-private-error', ['http_code' => 401])],
            'upload' => [new MockResponse('{"access_token":"secret-access-token"}'), new MockResponse('secret raw-private-error', ['http_code' => 500])],
            default => static function (): never { self::fail('No Dropbox HTTP should occur for this failure.'); },
        });
        $this->submit('/support', ['summary' => $failure === 'JSON' ? "\xFF" : 'Problem', 'priority' => 'Average']);

        self::assertResponseStatusCodeSame(503);
        self::assertSelectorTextContains('main', 'Не удалось отправить обращение. Попробуйте ещё раз.');
        self::assertSelectorTextContains('h1', 'Поддержка');
        foreach (['secret', 'refresh-token', 'raw-private-error', 'secret-access-token'] as $privateValue) {
            self::assertStringNotContainsString($privateValue, $this->client->getResponse()->getContent());
        }
        self::assertSame(match ($failure) { 'authentication' => 1, 'upload' => 2, default => 0 }, $this->http->getRequestsCount());
    }

    public static function failures(): iterable
    {
        yield 'no administrators' => ['no administrators'];
        yield 'Dropbox authentication exception' => ['authentication'];
        yield 'Dropbox API exception' => ['upload'];
        yield 'JSON generation exception' => ['JSON'];
    }

    private function makeAdmin(): void
    {
        $admin = new User('support-admin-'.bin2hex(random_bytes(5)).'@example.test');
        $admin->setRoles(['ROLE_ADMIN']);
        $this->em->persist($admin);
        $this->em->flush();
    }

    private function submit(string $path, array $data, array $extra = []): void
    {
        $this->client->request('GET', $path);
        self::assertResponseIsSuccessful();
        $token = $this->client->getCrawler()->filter('input[name="support_ticket[_token]"]')->attr('value');
        $this->client->request('POST', $path, ['support_ticket' => $data + ['_token' => $token]] + $extra);
    }
}
