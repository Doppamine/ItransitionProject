<?php

declare(strict_types=1);

namespace App\Tests;

use App\Entity\Position;
use App\Entity\Profile;
use App\Entity\AttributeDefinition;
use App\Entity\User;
use App\Enum\PositionAccessType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class Task014PreferencesTest extends WebTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        static::createClient()->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (isset($this->em) && $this->em->getConnection()->isTransactionActive()) {
            $this->em->getConnection()->rollBack();
            $this->em->clear();
        }
        parent::tearDown();
    }

    public function testAuthenticatedPreferencesPersistAndTranslateOnlyInterface(): void
    {
        $client = static::getClient();
        $user = new User('task014-pref-'.bin2hex(random_bytes(4)).'@example.test');
        $user->setRoles(['ROLE_CANDIDATE']);
        $position = new Position('Quantum Zebra Engineer', 'Original English title', PositionAccessType::PUBLIC, 0);
        $profile = Profile::createWithBuiltIns($user, $this->em->getRepository(AttributeDefinition::class)->findBy(['isBuiltIn' => true]));
        $this->em->persist($user);
        $this->em->persist($profile);
        $this->em->persist($position);
        $this->em->flush();
        $client->loginUser($user);

        $page = $client->request('GET', '/');
        self::assertSelectorExists('form[action="/preferences/locale"] input[name="_token"]');
        self::assertSelectorExists('form[action="/preferences/theme"] input[name="_token"]');
        $localeToken = $page->filter('form[action="/preferences/locale"] input[name="_token"]')->attr('value');
        $themeToken = $page->filter('form[action="/preferences/theme"] input[name="_token"]')->attr('value');
        $client->request('POST', '/preferences/locale', ['_token' => $localeToken, 'locale' => 'ru'], server: ['HTTP_REFERER' => 'https://evil.example/steal']);
        self::assertResponseRedirects('/');
        $client->request('POST', '/preferences/theme', ['_token' => $themeToken, 'theme' => 'dark']);
        self::assertResponseRedirects('/');

        $client->request('GET', '/');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('html[lang="ru"][data-bs-theme="dark"]');
        self::assertSelectorTextContains('h1', 'Главная');
        self::assertSelectorTextContains('#latest-positions', 'Quantum Zebra Engineer');
        $client->request('GET', '/profile');
        self::assertSelectorTextContains('h1', 'Ваш профиль');
        $client->request('GET', '/positions');
        self::assertSelectorTextContains('h1', 'Вакансии');
        self::assertSelectorTextContains('body', 'Quantum Zebra Engineer');
        $client->request('GET', '/search?q=Quantum');
        self::assertSelectorTextContains('h1', 'Поиск');
        self::assertSelectorTextContains('body', 'Quantum Zebra Engineer');
        self::assertSame(['ru', 'dark'], $this->em->getConnection()->fetchNumeric('SELECT locale, theme FROM app_user WHERE id = ?', [$user->getId()]));
    }

    public function testAnonymousPreferencesSurviveSubsequentRequestsAndRejectInvalidValues(): void
    {
        $client = static::getClient();
        $page = $client->request('GET', '/login');
        self::assertSelectorExists('form[action="/preferences/locale"] input[name="_token"]');
        self::assertSelectorExists('form[action="/preferences/theme"] input[name="_token"]');
        $localeToken = $page->filter('form[action="/preferences/locale"] input[name="_token"]')->attr('value');
        $themeToken = $page->filter('form[action="/preferences/theme"] input[name="_token"]')->attr('value');

        $client->request('POST', '/preferences/locale', ['_token' => $localeToken, 'locale' => 'ru']);
        self::assertResponseRedirects('/login');
        $client->request('POST', '/preferences/theme', ['_token' => $themeToken, 'theme' => 'dark']);
        self::assertResponseRedirects('/');
        $client->request('GET', '/login');
        self::assertSelectorExists('html[lang="ru"][data-bs-theme="dark"]');
        self::assertSelectorTextContains('h1', 'Войти');

        $client->request('POST', '/preferences/locale', ['_token' => $localeToken, 'locale' => 'fr']);
        self::assertResponseStatusCodeSame(422);
        $client->request('POST', '/preferences/theme', ['_token' => 'invalid', 'theme' => 'light']);
        self::assertResponseStatusCodeSame(403);
    }
}
