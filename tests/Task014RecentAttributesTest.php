<?php

declare(strict_types=1);

namespace App\Tests;

use App\Attribute\RecentAttributeTracker;
use App\Entity\AttributeDefinition;
use App\Entity\Position;
use App\Entity\Profile;
use App\Entity\User;
use App\Enum\AttributeType;
use App\Enum\PositionAccessType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpFoundation\Session\Session;

final class Task014RecentAttributesTest extends WebTestCase
{
    public function testCandidateAndPositionSelectionUpdateBoundedOrderedRecents(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->getConnection()->beginTransaction();
        try {
            $user = new User('recent-'.bin2hex(random_bytes(4)).'@example.test');
            $user->setRoles(['ROLE_CANDIDATE', 'ROLE_RECRUITER']);
            $builtIns = $em->getRepository(AttributeDefinition::class)->findBy(['isBuiltIn' => true]);
            $profile = Profile::createWithBuiltIns($user, $builtIns);
            $firstName = $em->getRepository(AttributeDefinition::class)->findOneBy(['normalizedName' => 'first name']);
            $a = new AttributeDefinition($firstName->getCategory(), 'Recent Alpha', AttributeType::STRING);
            $b = new AttributeDefinition($firstName->getCategory(), 'Recent Beta', AttributeType::STRING);
            $position = new Position('Recents position', '', PositionAccessType::PUBLIC, 0);
            foreach ([$user, $profile, $a, $b, $position] as $entity) {
                $em->persist($entity);
            }
            $em->flush();
            $client->loginUser($user);
            $page = $client->request('GET', '/profile');
            $token = $page->filter('form[action="/profile/attributes/'.$a->getId().'/select"] input[name="_token"]')->attr('value');
            $client->request('POST', '/profile/attributes/'.$a->getId().'/select', ['_token' => $token]);
            self::assertResponseRedirects('/profile#info');
            $page = $client->request('GET', '/positions/'.$position->getId().'/edit');
            $positionToken = $page->filter('form[action="/positions/'.$position->getId().'/attributes/add"] input[name="_token"]')->attr('value');
            $version = $page->filter('form[action="/positions/'.$position->getId().'/attributes/add"] input[name="version"]')->attr('value');
            $client->request('POST', '/positions/'.$position->getId().'/attributes/add', ['_token' => $positionToken, 'version' => $version, 'definition' => (string) $b->getId(), 'sortOrder' => '10']);
            self::assertResponseRedirects('/positions/'.$position->getId().'/edit');
            self::assertSame([$b->getId(), $a->getId()], $client->getRequest()->getSession()->get('recent_attributes'));
            $client->request('GET', '/profile');
            self::assertSelectorExists('#recent-attributes button');
            self::assertSelectorTextContains('#recent-attributes', 'Recent Beta');
            self::assertSelectorTextNotContains('#recent-attributes', 'Recent Alpha');
            $client->request('GET', '/positions/'.$position->getId().'/edit');
            self::assertSelectorTextContains('optgroup[label="Recently used attributes"]', 'Recent Beta');

            $stack = new RequestStack();
            $request = Request::create('/');
            $session = new Session(new MockArraySessionStorage());
            $session->start();
            $request->setSession($session);
            $request->cookies->set($session->getName(), $session->getId());
            $stack->push($request);
            $tracker = new RecentAttributeTracker($stack, $em->getConnection());
            foreach (range(1, 12) as $id) {
                $tracker->record($id);
            }
            $tracker->record(5);
            self::assertSame([5, 12, 11, 10, 9, 8, 7, 6, 4, 3], $session->get('recent_attributes'));
        } finally {
            $em->getConnection()->rollBack();
            $em->clear();
        }
    }
}
