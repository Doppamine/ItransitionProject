<?php

declare(strict_types=1);

namespace App\Tests\Integration\Salesforce;

use App\Entity\AttributeDefinition;
use App\Entity\Profile;
use App\Entity\User;
use App\Integration\Salesforce\SalesforceClient;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class SalesforceExportTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $user;
    private MockHttpClient $httpClient;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->httpClient = new MockHttpClient(static function (): never {
            self::fail('No Salesforce HTTP request was expected.');
        });
        static::getContainer()->set(SalesforceClient::class, new SalesforceClient(
            $this->httpClient, 'https://acme.my.salesforce.com', 'web-client-id', 'web-client-secret', '66.0',
        ));
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();
        $this->user = new User('salesforce-web-'.bin2hex(random_bytes(5)).'@example.test');
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

    public function testCandidateCanOpenOwnExportFormWithoutCallingSalesforce(): void
    {
        $this->client->request('GET', '/account/salesforce');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Create in Salesforce');
        self::assertSelectorExists('input[name="salesforce_export[companyName]"]');
        self::assertSame(0, $this->httpClient->getRequestsCount());
    }

    #[DataProvider('otherAuthenticatedRoles')]
    public function testSelfExportWorksForOtherRolesWithoutBroadeningProfileAccess(array $roles): void
    {
        $this->user->setRoles($roles);
        $this->em->flush();
        $this->client->loginUser($this->user);

        $this->client->request('GET', '/account/salesforce');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->client->request('GET', '/profile');
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        self::assertSame(0, $this->httpClient->getRequestsCount());
    }

    public static function otherAuthenticatedRoles(): iterable
    {
        yield 'Recruiter' => [['ROLE_RECRUITER']];
        yield 'Admin without Candidate role' => [['ROLE_ADMIN']];
        yield 'authenticated user without roles' => [[]];
    }

    public function testAnonymousCannotOpenOrSubmitSelfExport(): void
    {
        $this->client->getCookieJar()->clear();
        static::getContainer()->get('security.token_storage')->setToken(null);

        $this->client->request('GET', '/account/salesforce');
        self::assertResponseRedirects('/login');
        $this->client->request('POST', '/account/salesforce', ['salesforce_export' => ['companyName' => 'Example']]);
        self::assertResponseRedirects('/login');
        self::assertSame(0, $this->httpClient->getRequestsCount());
    }

    public function testAdminCanSelectAnotherUserAndUnknownTargetReturns404(): void
    {
        $other = $this->anotherUser();
        $this->user->setRoles(['ROLE_ADMIN']);
        $this->em->flush();
        $this->client->loginUser($this->user);

        $this->client->request('GET', '/admin/users/'.$other->getId().'/salesforce');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSelectorTextContains('main', $other->getEmail());
        $this->client->request('GET', '/admin/users/2147483647/salesforce');
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
        self::assertSame(0, $this->httpClient->getRequestsCount());
    }

    #[DataProvider('nonAdminRoles')]
    public function testNonAdminCannotOpenOrSubmitAnotherUsersExport(array $roles): void
    {
        $other = $this->anotherUser();
        $this->user->setRoles($roles);
        $this->em->flush();
        $this->client->loginUser($this->user);
        $path = '/admin/users/'.$other->getId().'/salesforce';

        $this->client->request('GET', $path);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->client->request('POST', $path, ['salesforce_export' => ['companyName' => 'Example']]);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        self::assertSame(0, $this->httpClient->getRequestsCount());
    }

    public static function nonAdminRoles(): iterable
    {
        yield 'Candidate' => [['ROLE_CANDIDATE']];
        yield 'Recruiter' => [['ROLE_RECRUITER']];
        yield 'authenticated user without roles' => [[]];
    }

    public function testExportsProfileBuiltInsAndUserEmailWithTrimmedFormValuesUsingPrg(): void
    {
        $profile = $this->profile(['first name' => ' Ada ', 'last name' => ' Lovelace ', 'location' => ' London ']);
        $version = $profile->getVersion();
        $this->expectComposite(
            ['Name' => 'Example Company', 'Website' => 'https://example.com'],
            ['AccountId' => '@{account.id}', 'FirstName' => 'Ada', 'LastName' => 'Lovelace',
                'Email' => $this->user->getEmail(), 'Title' => 'Engineer', 'Phone' => '+44 1234', 'MailingCity' => 'London'],
        );

        $this->submit('/account/salesforce', [
            'companyName' => ' Example Company ', 'website' => ' https://example.com ',
            'jobTitle' => ' Engineer ', 'phone' => ' +44 1234 ',
        ]);

        self::assertResponseRedirects('/account/salesforce', 303);
        self::assertSame(2, $this->httpClient->getRequestsCount());
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[role="status"]', 'Account and Contact created in Salesforce.');
        self::assertSelectorTextContains('main', 'Ada');
        self::assertSelectorTextContains('main', 'Lovelace');
        self::assertSelectorTextContains('main', 'London');
        self::assertSelectorNotExists('input[name="salesforce_export[lastName]"]');
        foreach (['firstName', 'email', 'location'] as $field) {
            self::assertSelectorNotExists('input[name="salesforce_export['.$field.']"]');
        }
        $this->client->request('GET', '/account/salesforce');
        self::assertSame(2, $this->httpClient->getRequestsCount());
        $stored = $this->em->getConnection()->fetchAssociative('SELECT version FROM profile WHERE id = ?', [$profile->getId()]);
        self::assertSame($version, $stored['version']);
    }

    #[DataProvider('missingProfileLastNames')]
    public function testUserWithoutUsableProfileLastNameCanExportWithFallback(bool $hasProfile): void
    {
        $this->user->setRoles(['ROLE_RECRUITER']);
        $this->em->flush();
        $this->client->loginUser($this->user);
        if ($hasProfile) {
            $this->profile(['first name' => ' ', 'last name' => " \t", 'location' => "\n"]);
        }
        $this->expectComposite(['Name' => 'Example Company'], [
            'AccountId' => '@{account.id}', 'LastName' => 'Fallback', 'Email' => $this->user->getEmail(),
        ]);

        $this->submit('/account/salesforce', [
            'companyName' => ' Example Company ', 'lastName' => ' Fallback ',
            'jobTitle' => ' ', 'phone' => '', 'website' => ' ',
        ]);

        self::assertResponseRedirects('/account/salesforce', 303);
        self::assertSame(2, $this->httpClient->getRequestsCount());
        $this->client->followRedirect();
        self::assertSelectorExists('input[name="salesforce_export[lastName]"][required]');
    }

    public static function missingProfileLastNames(): iterable
    {
        yield 'no Profile' => [false];
        yield 'blank built-ins' => [true];
    }

    #[DataProvider('invalidSubmissions')]
    public function testRejectsInvalidSubmissionBeforeSalesforceHttp(array $changes, string $message): void
    {
        $this->submit('/account/salesforce', array_replace([
            'companyName' => 'Example Company', 'lastName' => 'Doe',
            'jobTitle' => '', 'phone' => '', 'website' => '',
        ], $changes));

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertSelectorTextContains('main', $message);
        self::assertSame(0, $this->httpClient->getRequestsCount());
    }

    public static function invalidSubmissions(): iterable
    {
        yield 'blank company' => [['companyName' => ' '], 'Enter a company name.'];
        yield 'blank fallback last name' => [['lastName' => " \t"], 'Enter a Contact last name.'];
        yield 'missing fallback last name' => [['lastName' => null], 'Enter a Contact last name.'];
        yield 'invalid website' => [['website' => 'not-a-url'], 'Enter a valid http or https website URL.'];
        yield 'unsafe website scheme' => [['website' => 'javascript:alert(1)'], 'Enter a valid http or https website URL.'];
        yield 'non-http website' => [['website' => 'ftp://example.com'], 'Enter a valid http or https website URL.'];
        yield 'company length' => [['companyName' => str_repeat('a', 256)], 'Use at most 255 characters.'];
        yield 'last name length' => [['lastName' => str_repeat('a', 81)], 'Use at most 80 characters.'];
        yield 'job title length' => [['jobTitle' => str_repeat('a', 129)], 'Use at most 128 characters.'];
        yield 'phone length' => [['phone' => str_repeat('1', 41)], 'Use at most 40 characters.'];
        yield 'website length' => [['website' => 'https://example.com/'.str_repeat('a', 256)], 'Use at most 255 characters.'];
    }

    public function testInvalidCsrfCannotExport(): void
    {
        $this->client->request('POST', '/account/salesforce', ['salesforce_export' => [
            'companyName' => 'Example Company', 'lastName' => 'Doe', '_token' => 'invalid',
        ]]);

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertSame(0, $this->httpClient->getRequestsCount());
    }

    public function testSelfRouteIgnoresTargetSelectorsAndReadOnlyValueOverrides(): void
    {
        $other = $this->anotherUser();
        $this->profile(['first name' => 'Other', 'last name' => 'Target', 'location' => 'Elsewhere'], $other);
        $this->profile(['first name' => 'Ada', 'last name' => 'Self', 'location' => 'London']);
        $this->expectComposite(['Name' => 'Example Company'], [
            'AccountId' => '@{account.id}', 'FirstName' => 'Ada', 'LastName' => 'Self',
            'Email' => $this->user->getEmail(), 'MailingCity' => 'London',
        ]);

        $this->submit('/account/salesforce?id='.$other->getId().'&user='.$other->getId().'&targetUser='.$other->getId(),
            ['companyName' => 'Example Company'], [
                'id' => $other->getId(), 'user' => $other->getId(), 'targetUser' => $other->getId(),
                'email' => $other->getEmail(), 'firstName' => 'Forged', 'lastName' => 'Forged', 'location' => 'Forged',
            ]);

        self::assertResponseRedirects('/account/salesforce', 303);
        self::assertSame(2, $this->httpClient->getRequestsCount());
        $this->client->followRedirect();
        self::assertSelectorTextContains('main', $this->user->getEmail());
        self::assertSelectorTextNotContains('main', $other->getEmail());
    }

    public function testCannotSubmitFallbackOverrideOfBuiltInLastName(): void
    {
        $this->profile(['last name' => 'Read Only']);

        $this->submit('/account/salesforce', ['companyName' => 'Example Company', 'lastName' => 'Forged']);

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertSelectorTextContains('main', 'Read Only');
        self::assertSelectorNotExists('input[name="salesforce_export[lastName]"]');
        self::assertSame(0, $this->httpClient->getRequestsCount());
    }

    public function testAdminExportsRouteSelectedUserWithoutImpersonation(): void
    {
        $other = $this->anotherUser();
        $this->profile(['first name' => 'Taylor', 'last name' => 'Target', 'location' => 'Toronto'], $other);
        $this->profile(['last name' => 'Actor']);
        $this->user->setRoles(['ROLE_ADMIN']);
        $this->em->flush();
        $this->client->loginUser($this->user);
        $path = '/admin/users/'.$other->getId().'/salesforce';
        $this->expectComposite(['Name' => 'Example Company'], [
            'AccountId' => '@{account.id}', 'FirstName' => 'Taylor', 'LastName' => 'Target',
            'Email' => $other->getEmail(), 'MailingCity' => 'Toronto',
        ]);

        $this->submit($path.'?id='.$this->user->getId(), ['companyName' => 'Example Company'], ['id' => $this->user->getId()]);

        self::assertResponseRedirects($path, 303);
        self::assertSame(2, $this->httpClient->getRequestsCount());
        $this->client->followRedirect();
        self::assertSelectorTextContains('main', $other->getEmail());
        self::assertSelectorTextContains('nav', $this->user->getEmail());
        self::assertSelectorExists('nav a[href="/admin/users"]');
    }

    #[DataProvider('salesforceFailures')]
    public function testSalesforceFailuresShowSanitizedErrorAndKeepFormValues(string $failure): void
    {
        $sensitive = 'web-client-secret web-test-token https://acme.my.salesforce.com/services/oauth2/token';
        $responses = match ($failure) {
            'authentication' => [new MockResponse($sensitive, ['http_code' => 401])],
            'API' => [new MockResponse('{"access_token":"web-test-token"}'), new MockResponse($sensitive, ['http_code' => 500])],
            'composite' => [new MockResponse('{"access_token":"web-test-token"}'), new MockResponse(json_encode([
                'compositeResponse' => [
                    ['referenceId' => 'account', 'httpStatusCode' => 201, 'body' => ['id' => '001000000000001AAA']],
                    ['referenceId' => 'contact', 'httpStatusCode' => 400, 'body' => [['message' => $sensitive]]],
                ],
            ], JSON_THROW_ON_ERROR))],
        };
        $this->httpClient->setResponseFactory($responses);

        $this->submit('/account/salesforce', [
            'companyName' => 'Example Company', 'lastName' => 'Doe', 'jobTitle' => 'Engineer',
            'phone' => '+44 1234', 'website' => 'https://example.com',
        ]);

        self::assertSame(503, $this->client->getResponse()->getStatusCode());
        self::assertSelectorTextContains('main', 'Salesforce export could not be completed. Please try again.');
        foreach (['companyName' => 'Example Company', 'lastName' => 'Doe', 'jobTitle' => 'Engineer', 'phone' => '+44 1234', 'website' => 'https://example.com'] as $field => $value) {
            self::assertSelectorExists('input[name="salesforce_export['.$field.']"][value="'.$value.'"]');
        }
        $content = $this->client->getResponse()->getContent();
        foreach (['web-client-secret', 'web-test-token', 'acme.my.salesforce.com', 'SalesforceCompositeException'] as $private) {
            self::assertStringNotContainsString($private, $content);
        }
        self::assertSame($failure === 'authentication' ? 1 : 2, $this->httpClient->getRequestsCount());
    }

    public static function salesforceFailures(): iterable
    {
        yield 'authentication' => ['authentication'];
        yield 'API' => ['API'];
        yield 'composite' => ['composite'];
    }

    public function testCandidateProfileContainsSalesforceAction(): void
    {
        $this->profile([]);
        $this->client->request('GET', '/profile');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main a[href="/account/salesforce"]', 'Create in Salesforce');
        self::assertSelectorExists('main a[href="/account/salesforce"][data-action="click->profile-autosave#guardNavigation"]');
        self::assertSame(0, $this->httpClient->getRequestsCount());
    }

    public function testRecruiterHasNavbarSelfEntryPointWithoutProfileAccess(): void
    {
        $this->user->setRoles(['ROLE_RECRUITER']);
        $this->em->flush();
        $this->client->loginUser($this->user);
        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('nav a[href="/account/salesforce"]');
        self::assertSelectorNotExists('nav a[href="/profile"]');
        self::assertSame(0, $this->httpClient->getRequestsCount());
    }

    public function testAdminUsersPageHasPerUserSalesforceAction(): void
    {
        $other = $this->anotherUser();
        $this->user->setRoles(['ROLE_ADMIN']);
        $this->em->flush();
        $this->client->loginUser($this->user);
        $this->client->request('GET', '/admin/users');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('tr a[href="/admin/users/'.$other->getId().'/salesforce"]', 'Create in Salesforce');
        self::assertSelectorExists('tr a[href="/admin/users/'.$this->user->getId().'/salesforce"]');
        self::assertSelectorExists('nav a[href="/account/salesforce"]');
        self::assertSame(0, $this->httpClient->getRequestsCount());
    }

    public function testRussianFormValidationFailureAndSuccessMessages(): void
    {
        $this->user->setLocale('ru');
        $this->em->flush();
        $this->client->loginUser($this->user);
        $this->client->request('GET', '/account/salesforce');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Создать в Salesforce');
        self::assertSelectorTextContains('label[for="salesforce_export_companyName"]', 'Название компании');
        self::assertSelectorTextContains('label[for="salesforce_export_lastName"]', 'Фамилия контакта');
        foreach (['companyName' => 255, 'lastName' => 80, 'jobTitle' => 128, 'phone' => 40, 'website' => 255] as $field => $limit) {
            self::assertSelectorExists('input[name="salesforce_export['.$field.']"][maxlength="'.$limit.'"]');
        }

        $this->submit('/account/salesforce', ['companyName' => ' ', 'lastName' => 'Фамилия']);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertSelectorTextContains('main', 'Укажите название компании.');
        self::assertSame(0, $this->httpClient->getRequestsCount());

        $this->httpClient->setResponseFactory(new MockResponse('private Salesforce details', ['http_code' => 401]));
        $this->submit('/account/salesforce', ['companyName' => 'Компания', 'lastName' => 'Фамилия']);
        self::assertSame(503, $this->client->getResponse()->getStatusCode());
        self::assertSelectorTextContains('main', 'Не удалось выполнить экспорт в Salesforce.');

        $this->expectComposite(['Name' => 'Компания'], ['AccountId' => '@{account.id}', 'LastName' => 'Фамилия', 'Email' => $this->user->getEmail()]);
        $this->submit('/account/salesforce', ['companyName' => 'Компания', 'lastName' => 'Фамилия']);
        self::assertResponseRedirects('/account/salesforce', 303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('[role="status"]', 'Компания и контакт созданы в Salesforce.');
        self::assertSame(3, $this->httpClient->getRequestsCount());
    }

    public function testAcceptsFieldLengthBoundariesAndCountsUnicodeCharacters(): void
    {
        $company = str_repeat('К', 255);
        $lastName = str_repeat('Ф', 80);
        $title = str_repeat('Д', 128);
        $phone = str_repeat('1', 40);
        $this->expectComposite(['Name' => $company, 'Website' => 'http://example.com'], [
            'AccountId' => '@{account.id}', 'LastName' => $lastName, 'Email' => $this->user->getEmail(), 'Title' => $title, 'Phone' => $phone,
        ]);

        $this->submit('/account/salesforce', ['companyName' => $company, 'lastName' => $lastName, 'jobTitle' => $title, 'phone' => $phone, 'website' => 'http://example.com']);

        self::assertResponseRedirects('/account/salesforce', 303);
        self::assertSame(2, $this->httpClient->getRequestsCount());
    }

    private function profile(array $texts, ?User $user = null): Profile
    {
        $definitions = $this->em->getRepository(AttributeDefinition::class)->findBy(['isBuiltIn' => true]);
        $profile = Profile::createWithBuiltIns($user ?? $this->user, $definitions);
        foreach ($profile->getValues() as $value) {
            $name = $value->getDefinition()->getNormalizedName();
            if (array_key_exists($name, $texts)) {
                $value->setText($texts[$name]);
            }
        }
        $this->em->persist($profile);
        $this->em->flush();

        return $profile;
    }

    private function expectComposite(array $account, array $contact): void
    {
        $this->httpClient->setResponseFactory([
            new MockResponse('{"access_token":"web-test-token"}'),
            static function (string $method, string $url, array $options) use ($account, $contact): MockResponse {
                self::assertSame('POST', $method);
                self::assertSame('https://acme.my.salesforce.com/services/data/v66.0/composite', $url);
                $payload = json_decode($options['body'], true, 512, JSON_THROW_ON_ERROR);
                self::assertTrue($payload['allOrNone']);
                self::assertSame('account', $payload['compositeRequest'][0]['referenceId']);
                self::assertSame('contact', $payload['compositeRequest'][1]['referenceId']);
                self::assertSame($account, $payload['compositeRequest'][0]['body']);
                self::assertSame($contact, $payload['compositeRequest'][1]['body']);

                return new MockResponse('{"compositeResponse":[{"body":{"id":"001000000000001AAA","success":true,"errors":[]},"httpStatusCode":201,"referenceId":"account"},{"body":{"id":"003000000000001AAA","success":true,"errors":[]},"httpStatusCode":201,"referenceId":"contact"}]}');
            },
        ]);
    }

    private function submit(string $path, array $data, array $extra = []): void
    {
        $this->client->request('GET', $path);
        self::assertResponseIsSuccessful();
        $token = $this->client->getCrawler()->filter('input[name="salesforce_export[_token]"]')->attr('value');
        $this->client->request('POST', $path, array_merge($extra, ['salesforce_export' => array_merge($data, ['_token' => $token])]));
    }

    private function anotherUser(): User
    {
        $user = new User('salesforce-target-'.bin2hex(random_bytes(5)).'@example.test');
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
