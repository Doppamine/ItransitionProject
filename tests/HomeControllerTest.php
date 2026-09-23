<?php

namespace App\Tests;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class HomeControllerTest extends WebTestCase
{
    public function testHomePageRendersRequiredDashboardSections(): void
    {
        $client = static::createClient();

        $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Main Page');
        self::assertSelectorExists('#latest-positions');
        self::assertSelectorExists('#popular-positions');
        self::assertSelectorTextContains('main', 'Technology Tag Cloud');
        self::assertSelectorTextContains('main', 'Statistics');
    }
}
