<?php

declare(strict_types=1);

namespace App\Tests;

use App\Entity\AttributeCategory;
use App\Entity\AttributeDefinition;
use App\Entity\Position;
use App\Entity\User;
use App\Enum\AccessRuleOperator;
use App\Enum\AttributeType;
use App\Enum\PositionAccessType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class FinalRepairPositionPickerTest extends WebTestCase
{
    private EntityManagerInterface $em;
    private Position $position;
    private AttributeDefinition $matching;
    private AttributeDefinition $other;
    private AttributeDefinition $image;
    private AttributeCategory $category;

    protected function setUp(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();
        $suffix = bin2hex(random_bytes(4));
        $recruiter = new User('picker-'.$suffix.'@example.test');
        $recruiter->setRoles(['ROLE_RECRUITER']);
        $this->category = new AttributeCategory('Picker '.$suffix);
        $otherCategory = new AttributeCategory('Other picker '.$suffix);
        $this->matching = new AttributeDefinition($this->category, 'Picker Alpha '.$suffix, AttributeType::STRING);
        $this->other = new AttributeDefinition($otherCategory, 'Picker Beta '.$suffix, AttributeType::STRING);
        $this->image = new AttributeDefinition($this->category, 'Picker Image '.$suffix, AttributeType::IMAGE);
        $this->position = new Position('Picker position '.$suffix, '', PositionAccessType::PUBLIC, 0);
        foreach ([$recruiter, $this->category, $otherCategory, $this->matching, $this->other, $this->image, $this->position] as $item) {
            $this->em->persist($item);
        }
        $this->em->flush();
        $client->loginUser($recruiter);
    }

    protected function tearDown(): void
    {
        if (isset($this->em) && $this->em->getConnection()->isTransactionActive()) {
            $this->em->getConnection()->rollBack();
            $this->em->clear();
        }
        parent::tearDown();
    }

    public function testTemplatePickerFiltersByPrefixAndCategoryAndKeepsRecent(): void
    {
        $client = static::getClient();
        $edit = '/positions/'.$this->position->getId().'/edit';
        $client->request('GET', $edit.'?attribute_q=Picker%20A&attribute_category='.$this->category->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form[action="'.$edit.'"] input[name="attribute_q"][value="Picker A"]');
        self::assertSelectorExists('form[action$="/attributes/add"] optgroup[label="All attributes"] option[value="'.$this->matching->getId().'"]');
        self::assertSelectorNotExists('form[action$="/attributes/add"] optgroup[label="All attributes"] option[value="'.$this->other->getId().'"]');
        $token = $client->getCrawler()->filter('form[action$="/attributes/add"] input[name="_token"]')->attr('value');
        $client->request('POST', '/positions/'.$this->position->getId().'/attributes/add', [
            '_token' => $token, 'version' => (string) $this->position->getVersion(),
            'definition' => (string) $this->matching->getId(), 'sortOrder' => '10',
        ]);
        self::assertResponseRedirects($edit);
        $client->request('GET', $edit.'?attribute_q=NoMatchingAttribute');
        self::assertSelectorExists('form[action$="/attributes/add"] optgroup[label="Recently used attributes"] option[value="'.$this->matching->getId().'"]');
        self::assertSelectorNotExists('form[action$="/attributes/add"] optgroup[label="All attributes"] option[value="'.$this->matching->getId().'"]');
    }

    public function testRulePickerFiltersAndRecordsNewRuleAsRecentWithoutImage(): void
    {
        $client = static::getClient();
        $select = '/positions/'.$this->position->getId().'/rules/new';
        $client->request('GET', $select.'?attribute_q=Picker&attribute_category='.$this->category->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form[action="'.$select.'"] input[name="attribute_q"][value="Picker"]');
        self::assertSelectorExists('select[name="definition"] optgroup[label="All attributes"] option[value="'.$this->matching->getId().'"]');
        self::assertSelectorNotExists('select[name="definition"] option[value="'.$this->other->getId().'"]');
        self::assertSelectorNotExists('select[name="definition"] option[value="'.$this->image->getId().'"]');

        $client->request('GET', $select.'?definition='.$this->matching->getId());
        $form = $client->getCrawler()->selectButton('Add Access Rule')->form();
        $form['position_access_rule[operator]'] = AccessRuleOperator::EQUAL->value;
        $form['position_access_rule[textValue]'] = 'Expected';
        $client->submit($form);
        self::assertResponseRedirects('/positions/'.$this->position->getId().'/edit');
        $client->request('GET', $select.'?attribute_q=NoMatchingAttribute');
        self::assertSelectorExists('select[name="definition"] optgroup[label="Recently used attributes"] option[value="'.$this->matching->getId().'"]');
    }
}
