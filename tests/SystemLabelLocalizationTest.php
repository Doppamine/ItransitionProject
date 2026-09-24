<?php

declare(strict_types=1);

namespace App\Tests;

use App\Entity\AttributeCategory;
use App\Entity\AttributeDefinition;
use App\Entity\CV;
use App\Entity\Position;
use App\Entity\PositionAccessRule;
use App\Entity\Profile;
use App\Entity\User;
use App\Enum\AccessRuleOperator;
use App\Enum\AttributeType;
use App\Enum\PositionAccessType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SystemLabelLocalizationTest extends WebTestCase
{
    private EntityManagerInterface $em;
    private User $candidate;
    private User $recruiter;
    private Profile $profile;
    private AttributeCategory $customCategory;
    private AttributeDefinition $customDefinition;

    protected function setUp(): void
    {
        static::createClient()->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();
        $suffix = bin2hex(random_bytes(4));
        $this->candidate = new User('labels-candidate-'.$suffix.'@example.test');
        $this->candidate->setRoles(['ROLE_CANDIDATE']);
        $this->recruiter = new User('labels-recruiter-'.$suffix.'@example.test');
        $this->recruiter->setRoles(['ROLE_RECRUITER']);
        $this->customCategory = new AttributeCategory('Custom Skills '.$suffix);
        $this->customDefinition = new AttributeDefinition($this->customCategory, 'IELTS Score '.$suffix, AttributeType::STRING);
        $builtIns = $this->em->getRepository(AttributeDefinition::class)->findBy(['isBuiltIn' => true]);
        $this->profile = Profile::createWithBuiltIns($this->candidate, $builtIns);
        $this->profile->selectAttribute($this->customDefinition)->setText('7');
        foreach ([$this->candidate, $this->recruiter, $this->customCategory, $this->customDefinition, $this->profile] as $entity) {
            $this->em->persist($entity);
        }
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

    public function testRussianProfileUsesSystemLabelsButPreservesCustomNames(): void
    {
        $this->setRussian($this->candidate, '/profile');
        $definitions = $this->em->getRepository(AttributeDefinition::class);
        foreach (['first name' => 'Имя', 'last name' => 'Фамилия', 'location' => 'Местоположение'] as $normalized => $label) {
            $definition = $definitions->findOneBy(['normalizedName' => $normalized]);
            self::assertNotNull($definition);
            self::assertSelectorTextContains('label[for="attribute-'.$definition->getId().'"]', $label);
        }
        self::assertSelectorTextContains('main', 'Личная фотография');
        self::assertSelectorTextContains('label[for="attribute-'.$this->customDefinition->getId().'"]', $this->customDefinition->getName());
        foreach (['certification' => 'Сертификация', 'domain knowledge' => 'Предметные знания', 'personal information' => 'Личная информация', 'soft skills' => 'Гибкие навыки'] as $normalized => $label) {
            $category = $this->em->getRepository(AttributeCategory::class)->findOneBy(['normalizedName' => $normalized]);
            self::assertNotNull($category);
            self::assertSelectorTextContains('#attribute-category option[value="'.$category->getId().'"]', $label);
        }
        self::assertSelectorTextContains('#attribute-category option[value="'.$this->customCategory->getId().'"]', $this->customCategory->getName());
    }

    public function testRussianLibraryPositionRuleAndCVUseTheSameDisplayLabels(): void
    {
        $first = $this->em->getRepository(AttributeDefinition::class)->findOneBy(['normalizedName' => 'first name']);
        self::assertNotNull($first);
        $sameLabelCategory = new AttributeCategory('Сертификация');
        $this->em->persist($sameLabelCategory);
        $position = new Position('Labels position', '', PositionAccessType::PUBLIC, 0);
        $position->addAttribute($first, 10);
        $position->addAttribute($this->customDefinition, 20);
        (new PositionAccessRule($position, $first, AccessRuleOperator::EQUAL))->setTextExpected('Nina');
        $cv = new CV($this->profile, $position);
        $cv->publish();
        $this->em->persist($position);
        $this->em->persist($cv);
        $this->em->flush();

        $client = $this->setRussian($this->recruiter, '/attributes');
        $client->request('GET', '/attributes?q=First');
        self::assertSelectorTextContains('table tbody tr', 'Имя');
        self::assertSelectorTextContains('table tbody tr', 'Личная информация');
        $client->request('GET', '/attributes?q=IELTS');
        self::assertSelectorTextContains('table tbody tr', $this->customDefinition->getName());
        self::assertSelectorTextContains('table tbody tr', $this->customCategory->getName());
        $client->request('GET', '/attributes/new');
        self::assertSelectorTextContains('select[name="attribute_definition[category]"]', 'Сертификация');
        self::assertSelectorTextContains('select[name="attribute_definition[category]"]', $this->customCategory->getName());
        self::assertSelectorTextContains('select[name="attribute_definition[category]"] option[value="'.$sameLabelCategory->getId().'"]', 'Сертификация');
        $seededCertification = $this->em->getRepository(AttributeCategory::class)->findOneBy(['normalizedName' => 'certification']);
        self::assertSelectorTextContains('select[name="attribute_definition[category]"] option[value="'.$seededCertification->getId().'"]', 'Сертификация');
        $client->request('GET', '/attributes/'.$first->getId().'/edit');
        self::assertSelectorExists('input[name="attribute_definition[name]"][value="Имя"]:disabled');

        $client->request('GET', '/positions/'.$position->getId().'/edit?attribute_q=First');
        self::assertSelectorTextContains('select[name="definition"]', 'Имя');
        self::assertSelectorTextContains('#template-attribute-category', 'Гибкие навыки');
        self::assertSelectorTextContains('main', $this->customDefinition->getName());
        $client->request('GET', '/positions/'.$position->getId().'/rules/new?attribute_q=First');
        self::assertSelectorTextContains('select[name="definition"]', 'Имя');
        self::assertSelectorTextContains('#rule-attribute-category', 'Предметные знания');
        $client->request('GET', '/positions/'.$position->getId().'/rules/new?definition='.$first->getId());
        self::assertSelectorTextContains('main', 'Имя');
        $client->request('GET', '/positions/'.$position->getId());
        self::assertSelectorTextContains('main', 'Имя');
        self::assertSelectorTextContains('main', $this->customDefinition->getName());
        $client->request('GET', '/cvs/'.$cv->getId());
        self::assertSelectorTextContains('main', 'Имя');
        self::assertSelectorTextContains('main', $this->customDefinition->getName());
    }

    private function setRussian(User $user, string $page): \Symfony\Bundle\FrameworkBundle\KernelBrowser
    {
        $client = static::getClient();
        $client->loginUser($user);
        $crawler = $client->request('GET', $page);
        $token = $crawler->filter('form[action="/preferences/locale"] input[name="_token"]')->attr('value');
        $client->request('POST', '/preferences/locale', ['_token' => $token, 'locale' => 'ru']);
        $client->request('GET', $page);
        return $client;
    }
}
