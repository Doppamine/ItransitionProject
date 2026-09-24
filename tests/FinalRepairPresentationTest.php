<?php

declare(strict_types=1);

namespace App\Tests;

use App\Entity\AttributeDefinition;
use App\Entity\CV;
use App\Entity\Position;
use App\Entity\Profile;
use App\Entity\Project;
use App\Entity\Tag;
use App\Entity\User;
use App\Enum\AttributeType;
use App\Enum\PositionAccessType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class FinalRepairPresentationTest extends WebTestCase
{
    private EntityManagerInterface $em;
    private User $candidate;
    private User $recruiter;
    private CV $cv;

    protected function setUp(): void
    {
        static::createClient()->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();
        $suffix = bin2hex(random_bytes(4));
        $this->candidate = new User('markdown-candidate-'.$suffix.'@example.test');
        $this->candidate->setRoles(['ROLE_CANDIDATE']);
        $this->recruiter = new User('markdown-recruiter-'.$suffix.'@example.test');
        $this->recruiter->setRoles(['ROLE_RECRUITER']);
        $builtIns = $this->em->getRepository(AttributeDefinition::class)->findBy(['isBuiltIn' => true]);
        $profile = Profile::createWithBuiltIns($this->candidate, $builtIns);
        $category = $builtIns[0]->getCategory();
        $text = new AttributeDefinition($category, 'Repair narrative '.$suffix, AttributeType::TEXT);
        $profile->selectAttribute($text)->setText('**Bold attribute** <script>alert(1)</script> [unsafe](javascript:alert(1))');
        $tag = new Tag('Repair Tech '.$suffix);
        $project = new Project($profile, 'Repair project', new \DateTimeImmutable('2024-01-01'), null, '**Bold project** <script>alert(1)</script> [unsafe](javascript:alert(1))');
        $project->replaceTags([$tag]);
        $position = new Position('Markdown CV', '', PositionAccessType::PUBLIC, 1);
        $position->addAttribute($text, 10);
        $position->addProjectTag($tag);
        $this->cv = new CV($profile, $position);
        $this->cv->publish();
        foreach ([$this->candidate, $this->recruiter, $profile, $text, $tag, $project, $position, $this->cv] as $entity) {
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

    public function testProjectAndTextMarkdownRenderSafelyOnReadPages(): void
    {
        $client = static::getClient();
        $client->loginUser($this->candidate);
        $client->request('GET', '/profile/projects');
        self::assertSelectorExists('main strong:contains("Bold project")');
        self::assertSelectorNotExists('main script');
        self::assertSelectorNotExists('main a[href^="javascript:"]');

        $client->loginUser($this->recruiter);
        $client->request('GET', '/candidates/'.$this->candidate->getId().'/public');
        self::assertSelectorExists('main strong:contains("Bold project")');
        self::assertSelectorNotExists('main script');
        $client->request('GET', '/cvs/'.$this->cv->getId());
        self::assertCount(1, $this->em->getRepository(\App\Entity\Project::class)->findForCV($this->cv->getProfile(), $this->cv->getPosition()->getProjectTags(), 1));
        self::assertSelectorExists('aside[aria-labelledby="cv-projects"] strong:contains("Bold project")');
        self::assertSelectorExists('section[aria-labelledby="cv-attributes"] strong:contains("Bold attribute")');
        self::assertSelectorNotExists('main script');
        self::assertSelectorNotExists('main a[href^="javascript:"]');
    }

    public function testRussianCVLikeAndUnlikeControlsAreTranslated(): void
    {
        $client = static::getClient();
        $client->loginUser($this->recruiter);
        $page = $client->request('GET', '/cvs/'.$this->cv->getId());
        $localeToken = $page->filter('form[action="/preferences/locale"] input[name="_token"]')->attr('value');
        $client->request('POST', '/preferences/locale', ['_token' => $localeToken, 'locale' => 'ru']);
        $page = $client->request('GET', '/cvs/'.$this->cv->getId());
        self::assertSelectorTextContains('form[action$="/like"] button', 'Нравится');
        $likeToken = $page->filter('form[action$="/like"] input[name="_token"]')->attr('value');
        $client->request('POST', '/cvs/'.$this->cv->getId().'/like', ['_token' => $likeToken]);
        $client->request('GET', '/cvs/'.$this->cv->getId());
        self::assertSelectorTextContains('form[action$="/unlike"] button', 'Убрать лайк');
    }
}
