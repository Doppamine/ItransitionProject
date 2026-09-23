<?php

declare(strict_types=1);

namespace App\Tests;

use App\Entity\AttributeDefinition;
use App\Entity\CV;
use App\Entity\Position;
use App\Entity\Profile;
use App\Entity\User;
use App\Enum\PositionAccessType;
use App\Image\CloudinaryImages;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class Task014ImageTest extends WebTestCase
{
    private EntityManagerInterface $em;
    private User $candidate;
    private Profile $profile;
    private AttributeDefinition $photo;

    protected function setUp(): void
    {
        static::createClient()->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();
        $this->candidate = new User('image-'.bin2hex(random_bytes(4)).'@example.test');
        $this->candidate->setRoles(['ROLE_CANDIDATE']);
        $this->photo = $this->em->getRepository(AttributeDefinition::class)->findOneBy(['normalizedName' => 'personal photo']);
        $this->profile = Profile::createWithBuiltIns($this->candidate, $this->em->getRepository(AttributeDefinition::class)->findBy(['isBuiltIn' => true]));
        $this->em->persist($this->candidate);
        $this->em->persist($this->profile);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        if (isset($this->em) && $this->em->getConnection()->isTransactionActive()) {
            $this->em->getConnection()->rollBack();
            $this->em->clear();
        }
        parent::tearDown();
    }

    public function testSignedImageCompletionStoresKeyRendersDynamicCVAndCanClear(): void
    {
        $client = static::getClient();
        $position = new Position('Image Test Position', '', PositionAccessType::PUBLIC, 0);
        $cv = new CV($this->profile, $position);
        $this->em->persist($position);
        $this->em->persist($cv);
        $this->em->flush();
        $client->loginUser($this->candidate);
        $page = $client->request('GET', '/profile');
        self::assertResponseIsSuccessful();
        $editor = $page->filter('[data-definition-id="'.$this->photo->getId().'"] [data-controller="image-upload"]');
        self::assertCount(1, $editor);
        $token = $editor->attr('data-image-upload-token-value');
        $profileToken = $page->filter('main[data-profile-autosave-token-value]')->attr('data-profile-autosave-token-value');
        $signUrl = $editor->attr('data-image-upload-sign-url-value');
        $completeUrl = $editor->attr('data-image-upload-complete-url-value');

        $client->request('POST', $signUrl, server: ['HTTP_X_CSRF_TOKEN' => $token]);
        self::assertResponseIsSuccessful();
        $upload = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('https://api.cloudinary.com/v1_1/task014-test/image/upload', $upload['url']);
        self::assertStringStartsWith('profiles/'.$this->profile->getId().'/attributes/'.$this->photo->getId().'/', $upload['fields']['public_id']);
        self::assertArrayNotHasKey('api_secret', $upload['fields']);
        $signed = array_diff_key($upload['fields'], ['signature' => true, 'api_key' => true]);
        ksort($signed);
        $pairs = [];
        foreach ($signed as $name => $input) {
            $pairs[] = $name.'='.$input;
        }
        self::assertSame(sha1(implode('&', $pairs).'test-only-secret'), $upload['fields']['signature']);

        $key = $upload['fields']['public_id'];
        $version = 123;
        $signature = sha1('public_id='.$key.'&version='.$version.'test-only-secret');
        $client->request('POST', $completeUrl, server: ['HTTP_X_CSRF_TOKEN' => $token, 'CONTENT_TYPE' => 'application/json'], content: json_encode(['public_id' => $key, 'version' => $version, 'signature' => $signature, 'profileVersion' => $this->profile->getVersion()]));
        self::assertResponseIsSuccessful();
        $saved = json_decode($client->getResponse()->getContent(), true);
        self::assertSame($key, $this->em->getConnection()->fetchOne('SELECT image_key FROM profile_attribute_value WHERE profile_id = ? AND attribute_definition_id = ?', [$this->profile->getId(), $this->photo->getId()]));
        $client->request('POST', $completeUrl, server: ['HTTP_X_CSRF_TOKEN' => $token, 'CONTENT_TYPE' => 'application/json'], content: json_encode(['public_id' => $key, 'version' => $version, 'signature' => $signature, 'profileVersion' => $saved['version']]));
        self::assertResponseStatusCodeSame(422);
        $client->request('GET', '/cvs/'.$cv->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('img[src="'.$saved['url'].'"]');
        self::assertSelectorTextNotContains('body', $key);

        $client->request('POST', '/profile/autosave', server: ['HTTP_X_CSRF_TOKEN' => $profileToken, 'CONTENT_TYPE' => 'application/json'], content: json_encode(['version' => $saved['version'], 'changes' => [$this->photo->getId() => '']]));
        self::assertResponseIsSuccessful();
        self::assertNull($this->em->getConnection()->fetchOne('SELECT image_key FROM profile_attribute_value WHERE profile_id = ? AND attribute_definition_id = ?', [$this->profile->getId(), $this->photo->getId()]));
        $client->request('GET', '/cvs/'.$cv->getId());
        self::assertSelectorNotExists('img[src="'.$saved['url'].'"]');
    }

    public function testImageSigningRequiresEditableImageAndValidCloudinaryCompletion(): void
    {
        $client = static::getClient();
        $client->request('POST', '/profile/images/'.$this->photo->getId().'/sign');
        self::assertResponseRedirects('/login');
        $client->loginUser($this->candidate);
        $page = $client->request('GET', '/profile');
        $editor = $page->filter('[data-definition-id="'.$this->photo->getId().'"] [data-controller="image-upload"]');
        $token = $editor->attr('data-image-upload-token-value');
        $name = $this->em->getRepository(AttributeDefinition::class)->findOneBy(['normalizedName' => 'first name']);
        $client->request('POST', '/profile/images/'.$name->getId().'/sign', server: ['HTTP_X_CSRF_TOKEN' => $token]);
        self::assertResponseStatusCodeSame(403);
        $client->request('POST', $editor->attr('data-image-upload-sign-url-value'), server: ['HTTP_X_CSRF_TOKEN' => $token]);
        $upload = json_decode($client->getResponse()->getContent(), true);
        $client->request('POST', $editor->attr('data-image-upload-complete-url-value'), server: ['HTTP_X_CSRF_TOKEN' => $token, 'CONTENT_TYPE' => 'application/json'], content: json_encode(['public_id' => $upload['fields']['public_id'], 'version' => 1, 'signature' => 'forged', 'profileVersion' => $this->profile->getVersion()]));
        self::assertResponseStatusCodeSame(422);
        self::assertNull($this->profile->getValueFor($this->photo)->getImageKey());
        self::assertNull(static::getContainer()->get(CloudinaryImages::class)->url('https://evil.example/photo'));

        $position = new Position('CV image signing', '', PositionAccessType::PUBLIC, 0);
        $cv = new CV($this->em->find(Profile::class, $this->profile->getId()), $position);
        $this->em->persist($position);
        $this->em->persist($cv);
        $this->em->flush();
        $page = $client->request('GET', '/cvs/'.$cv->getId());
        self::assertResponseIsSuccessful();
        $cvEditor = $page->filter('[data-definition-id="'.$this->photo->getId().'"] [data-controller="image-upload"]');
        $client->request('POST', $cvEditor->attr('data-image-upload-sign-url-value'), server: ['HTTP_X_CSRF_TOKEN' => $cvEditor->attr('data-image-upload-token-value')]);
        self::assertResponseIsSuccessful();
    }
}
