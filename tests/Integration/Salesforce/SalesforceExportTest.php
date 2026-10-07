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

    public function testUserWithoutProfileCanExportWithCompleteTrimmedFallbackContactDetails(): void
    {
        $this->user->setRoles(['ROLE_RECRUITER']);
        $this->em->flush();
        $this->client->loginUser($this->user);
        $this->expectComposite(['Name' => 'Example Company', 'Website' => 'https://example.com'], [
            'AccountId' => '@{account.id}', 'FirstName' => 'Grace', 'LastName' => 'Fallback',
            'Email' => $this->user->getEmail(), 'Title' => 'Engineer', 'Phone' => '+44 1234', 'MailingCity' => 'Paris',
        ]);

        $this->submit('/account/salesforce', $this->additionalData() + [
            'firstName' => ' Grace ', 'lastName' => ' Fallback ', 'location' => ' Paris ',
        ]);

        self::assertResponseRedirects('/account/salesforce', 303);
        self::assertSame(2, $this->httpClient->getRequestsCount());
        $this->client->followRedirect();
        foreach (['firstName' => 40, 'lastName' => 80, 'location' => 40] as $field => $limit) {
            self::assertSelectorExists('input[name="salesforce_export['.$field.']"][required][maxlength="'.$limit.'"]');
        }
    }

    #[DataProvider('incompleteFallbackFields')]
    public function testRequiresCompleteFallbackContactDetailsBeforeSalesforceHttp(string $field, ?string $value, string $message): void
    {
        $this->submit('/account/salesforce', array_replace($this->additionalData() + [
            'firstName' => 'Grace', 'lastName' => 'Doe', 'location' => 'Paris',
        ], [$field => $value]));

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertSelectorTextContains('main', $message);
        self::assertSame(0, $this->httpClient->getRequestsCount());
    }

    public static function incompleteFallbackFields(): iterable
    {
        foreach (['firstName' => 'Enter a Contact first name.', 'lastName' => 'Enter a Contact last name.', 'location' => 'Enter a Contact location.'] as $field => $message) {
            foreach (['blank' => '', 'whitespace' => " \t\n ", 'missing' => null] as $case => $value) {
                yield $field.' '.$case => [$field, $value, $message];
            }
        }
        yield 'first name length' => ['firstName', str_repeat('a', 41), 'Use at most 40 characters.'];
        yield 'last name length' => ['lastName', str_repeat('a', 81), 'Use at most 80 characters.'];
        yield 'location length' => ['location', str_repeat('a', 41), 'Use at most 40 characters.'];
    }

    #[DataProvider('invalidSubmissions')]
    public function testRejectsInvalidSubmissionBeforeSalesforceHttp(array $changes, string $message): void
    {
        $this->submit('/account/salesforce', array_replace($this->additionalData() + [
            'firstName' => 'Grace', 'lastName' => 'Doe', 'location' => 'Paris',
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

    #[DataProvider('requiredAdditionalFields')]
    public function testRequiresCompleteAdditionalFieldsBeforeSalesforceHttp(string $field, ?string $value, string $message): void
    {
        $this->profile(['first name' => 'Ada', 'last name' => 'Lovelace', 'location' => 'London']);
        $this->submit('/account/salesforce', array_replace($this->additionalData(), [$field => $value]));

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertSelectorTextContains('main', $message);
        self::assertSame(0, $this->httpClient->getRequestsCount());
    }

    public static function requiredAdditionalFields(): iterable
    {
        foreach (['companyName' => 'Enter a company name.', 'jobTitle' => 'Enter a job title.', 'phone' => 'Enter a phone number.', 'website' => 'Enter a website URL.'] as $field => $message) {
            foreach (['blank' => '', 'whitespace' => " \t\n ", 'missing' => null] as $case => $value) {
                yield $field.' '.$case => [$field, $value, $message];
            }
        }
    }

    public function testAdditionalFieldsHaveRequiredHtmlAttributes(): void
    {
        $this->client->request('GET', '/account/salesforce');

        self::assertResponseIsSuccessful();
        foreach (['companyName' => 255, 'jobTitle' => 128, 'phone' => 40, 'website' => 255] as $field => $limit) {
            self::assertSelectorExists('input[name="salesforce_export['.$field.']"][required][maxlength="'.$limit.'"]');
        }
        self::assertSame(0, $this->httpClient->getRequestsCount());
    }

    #[DataProvider('incompleteProfileFields')]
    public function testRequiresCompleteReadOnlyProfileBeforeSalesforceHttp(string $name, ?string $value, string $label): void
    {
        $texts = ['first name' => 'Ada', 'last name' => 'Lovelace', 'location' => 'London'];
        if ($value === null) {
            unset($texts[$name]);
        } else {
            $texts[$name] = $value;
        }
        $this->profile($texts);
        $this->submit('/account/salesforce', $this->additionalData());

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertSelectorTextContains('main', 'Complete the "'.$label.'" field in your Profile before exporting.');
        foreach (['firstName', 'lastName', 'location'] as $field) {
            self::assertSelectorNotExists('input[name="salesforce_export['.$field.']"]');
        }
        self::assertSame(0, $this->httpClient->getRequestsCount());
    }

    public static function incompleteProfileFields(): iterable
    {
        foreach (['first name' => 'First name', 'last name' => 'Last name', 'location' => 'Location'] as $name => $label) {
            yield $name.' unset' => [$name, null, $label];
            yield $name.' whitespace' => [$name, " \t\n", $label];
        }
    }

    #[DataProvider('oversizedProfileFields')]
    public function testRejectsProfileFieldsExceedingSalesforceLimitsBeforeHttp(string $name, string $value, string $label, int $limit): void
    {
        $this->profile(array_replace(['first name' => 'Ada', 'last name' => 'Lovelace', 'location' => 'London'], [$name => $value]));
        $this->submit('/account/salesforce', $this->additionalData());

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertSelectorTextContains('main', 'Shorten the "'.$label.'" field in your Profile to at most '.$limit.' characters before exporting.');
        self::assertSame(0, $this->httpClient->getRequestsCount());
    }

    public static function oversizedProfileFields(): iterable
    {
        yield 'first name' => ['first name', str_repeat('a', 41), 'First name', 40];
        yield 'last name' => ['last name', str_repeat('a', 81), 'Last name', 80];
        yield 'location' => ['location', str_repeat('a', 41), 'Location', 40];
    }

    public function testEmptyPostIsRejectedWithoutSalesforceHttp(): void
    {
        $this->client->request('POST', '/account/salesforce');

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertSelectorTextContains('main', 'Unexpected form fields were submitted.');
        self::assertSame(0, $this->httpClient->getRequestsCount());
    }

    #[DataProvider('staleSuccessFailures')]
    public function testFailedCurrentSubmissionDoesNotShowPreviousSuccess(string $failure): void
    {
        $this->profile(['first name' => 'Ada', 'last name' => 'Lovelace', 'location' => 'London']);
        $this->expectComposite(['Name' => 'Example Company', 'Website' => 'https://example.com'], [
            'AccountId' => '@{account.id}', 'FirstName' => 'Ada', 'LastName' => 'Lovelace',
            'Email' => $this->user->getEmail(), 'Title' => 'Engineer', 'Phone' => '+44 1234', 'MailingCity' => 'London',
        ]);
        $this->client->request('GET', '/account/salesforce');
        $token = $this->client->getCrawler()->filter('input[name="salesforce_export[_token]"]')->attr('value');
        $data = $this->additionalData() + ['_token' => $token];
        $this->client->request('POST', '/account/salesforce', ['salesforce_export' => $data]);
        self::assertResponseRedirects('/account/salesforce', 303);
        self::assertSame(2, $this->httpClient->getRequestsCount());

        if ($failure === 'validation') {
            $data['jobTitle'] = ' ';
        } else {
            $this->httpClient->setResponseFactory(match ($failure) {
                'authentication' => [new MockResponse('private details', ['http_code' => 401])],
                'API' => [new MockResponse('{"access_token":"web-test-token"}'), new MockResponse('private details', ['http_code' => 500])],
                'composite' => [new MockResponse('{"access_token":"web-test-token"}'), new MockResponse('{"compositeResponse":[]}')],
            });
        }
        $this->client->request('POST', '/account/salesforce', ['salesforce_export' => $data]);

        self::assertSame($failure === 'validation' ? 422 : 503, $this->client->getResponse()->getStatusCode());
        self::assertSelectorTextContains('main', $failure === 'validation' ? 'Enter a job title.' : 'Salesforce export could not be completed.');
        self::assertSelectorNotExists('[role="status"]');
        self::assertSelectorTextNotContains('main', 'Account and Contact created in Salesforce.');
        self::assertSame(match ($failure) { 'validation' => 2, 'authentication' => 3, default => 4 }, $this->httpClient->getRequestsCount());
        $this->client->request('GET', '/account/salesforce');
        self::assertSelectorNotExists('[role="status"]');
    }

    public static function staleSuccessFailures(): iterable
    {
        yield 'validation' => ['validation'];
        yield from self::salesforceFailures();
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
        $this->expectComposite(['Name' => 'Example Company', 'Website' => 'https://example.com'], [
            'AccountId' => '@{account.id}', 'FirstName' => 'Ada', 'LastName' => 'Self',
            'Email' => $this->user->getEmail(), 'Title' => 'Engineer', 'Phone' => '+44 1234', 'MailingCity' => 'London',
        ]);

        $this->submit('/account/salesforce?id='.$other->getId().'&user='.$other->getId().'&targetUser='.$other->getId(),
            $this->additionalData(), [
                'id' => $other->getId(), 'user' => $other->getId(), 'targetUser' => $other->getId(),
                'email' => $other->getEmail(), 'firstName' => 'Forged', 'lastName' => 'Forged', 'location' => 'Forged',
            ]);

        self::assertResponseRedirects('/account/salesforce', 303);
        self::assertSame(2, $this->httpClient->getRequestsCount());
        $this->client->followRedirect();
        self::assertSelectorTextContains('main', $this->user->getEmail());
        self::assertSelectorTextNotContains('main', $other->getEmail());
    }

    #[DataProvider('readOnlyProfileFields')]
    public function testCannotSubmitFallbackOverrideOfBuiltInContactFields(string $field): void
    {
        $this->profile(['first name' => 'Ada', 'last name' => 'Read Only', 'location' => 'London']);

        $this->submit('/account/salesforce', $this->additionalData() + [$field => 'Forged']);

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertSelectorTextContains('main', 'Read Only');
        self::assertSelectorNotExists('input[name="salesforce_export['.$field.']"]');
        self::assertSame(0, $this->httpClient->getRequestsCount());
    }

    public static function readOnlyProfileFields(): iterable
    {
        yield 'first name' => ['firstName'];
        yield 'last name' => ['lastName'];
        yield 'location' => ['location'];
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
        $this->expectComposite(['Name' => 'Example Company', 'Website' => 'https://example.com'], [
            'AccountId' => '@{account.id}', 'FirstName' => 'Taylor', 'LastName' => 'Target',
            'Email' => $other->getEmail(), 'Title' => 'Engineer', 'Phone' => '+44 1234', 'MailingCity' => 'Toronto',
        ]);

        $this->submit($path.'?id='.$this->user->getId(), $this->additionalData(), ['id' => $this->user->getId()]);

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
            'companyName' => 'Example Company', 'firstName' => 'Grace', 'lastName' => 'Doe', 'location' => 'Paris', 'jobTitle' => 'Engineer',
            'phone' => '+44 1234', 'website' => 'https://example.com',
        ]);

        self::assertSame(503, $this->client->getResponse()->getStatusCode());
        self::assertSelectorTextContains('main', 'Salesforce export could not be completed. Please try again.');
        foreach (['companyName' => 'Example Company', 'firstName' => 'Grace', 'lastName' => 'Doe', 'location' => 'Paris', 'jobTitle' => 'Engineer', 'phone' => '+44 1234', 'website' => 'https://example.com'] as $field => $value) {
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
        foreach (['companyName' => 255, 'firstName' => 40, 'lastName' => 80, 'location' => 40, 'jobTitle' => 128, 'phone' => 40, 'website' => 255] as $field => $limit) {
            self::assertSelectorExists('input[name="salesforce_export['.$field.']"][maxlength="'.$limit.'"]');
        }

        $this->submit('/account/salesforce', ['companyName' => ' ', 'lastName' => 'Фамилия']);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertSelectorTextContains('main', 'Укажите название компании.');
        self::assertSame(0, $this->httpClient->getRequestsCount());

        $this->httpClient->setResponseFactory(new MockResponse('private Salesforce details', ['http_code' => 401]));
        $this->submit('/account/salesforce', array_replace($this->additionalData(), ['companyName' => 'Компания', 'firstName' => 'Имя', 'lastName' => 'Фамилия', 'location' => 'Город']));
        self::assertSame(503, $this->client->getResponse()->getStatusCode());
        self::assertSelectorTextContains('main', 'Не удалось выполнить экспорт в Salesforce.');

        $this->expectComposite(['Name' => 'Компания', 'Website' => 'https://example.com'], [
            'AccountId' => '@{account.id}', 'FirstName' => 'Имя', 'LastName' => 'Фамилия', 'Email' => $this->user->getEmail(),
            'Title' => 'Engineer', 'Phone' => '+44 1234', 'MailingCity' => 'Город',
        ]);
        $this->submit('/account/salesforce', array_replace($this->additionalData(), ['companyName' => 'Компания', 'firstName' => 'Имя', 'lastName' => 'Фамилия', 'location' => 'Город']));
        self::assertResponseRedirects('/account/salesforce', 303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('[role="status"]', 'Компания и контакт созданы в Salesforce.');
        self::assertSame(3, $this->httpClient->getRequestsCount());
    }

    #[DataProvider('contactSources')]
    public function testAcceptsFieldLengthBoundariesAndCountsUnicodeCharacters(bool $hasProfile): void
    {
        $company = str_repeat('К', 255);
        $lastName = str_repeat('Ф', 80);
        $title = str_repeat('Д', 128);
        $phone = str_repeat('1', 40);
        $firstName = str_repeat('И', 40);
        $location = str_repeat('Г', 40);
        $this->expectComposite(['Name' => $company, 'Website' => 'http://example.com'], [
            'AccountId' => '@{account.id}', 'FirstName' => $firstName, 'LastName' => $lastName, 'Email' => $this->user->getEmail(), 'Title' => $title, 'Phone' => $phone, 'MailingCity' => $location,
        ]);

        $data = ['companyName' => $company, 'jobTitle' => $title, 'phone' => $phone, 'website' => 'http://example.com'];
        if ($hasProfile) {
            $this->profile(['first name' => $firstName, 'last name' => $lastName, 'location' => $location]);
        } else {
            $data += ['firstName' => $firstName, 'lastName' => $lastName, 'location' => $location];
        }
        $this->submit('/account/salesforce', $data);

        self::assertResponseRedirects('/account/salesforce', 303);
        self::assertSame(2, $this->httpClient->getRequestsCount());
    }

    public static function contactSources(): iterable
    {
        yield 'Profile' => [true];
        yield 'no Profile' => [false];
    }

    public function testRussianRequiredContactAndAdditionalValidationMessages(): void
    {
        $this->user->setLocale('ru');
        $this->em->flush();
        $this->client->loginUser($this->user);
        $this->submit('/account/salesforce', array_fill_keys(['companyName', 'jobTitle', 'phone', 'website', 'firstName', 'lastName', 'location'], " \t\n"));

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        foreach (['Укажите название компании.', 'Укажите должность.', 'Укажите номер телефона.', 'Укажите URL веб-сайта.',
            'Укажите имя контакта.', 'Укажите фамилию контакта.', 'Укажите местоположение контакта.'] as $message) {
            self::assertSelectorTextContains('main', $message);
        }
        self::assertSame(0, $this->httpClient->getRequestsCount());

        $this->profile([], $this->em->find(User::class, $this->user->getId()));
        $this->submit('/account/salesforce', $this->additionalData());
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        foreach (['Имя', 'Фамилия', 'Местоположение'] as $label) {
            self::assertSelectorTextContains('main', 'Заполните поле «'.$label.'» в профиле перед экспортом.');
        }
        self::assertSame(0, $this->httpClient->getRequestsCount());
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

    private function additionalData(): array
    {
        return ['companyName' => 'Example Company', 'jobTitle' => 'Engineer', 'phone' => '+44 1234', 'website' => 'https://example.com'];
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
