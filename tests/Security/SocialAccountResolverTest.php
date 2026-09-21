<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\AttributeDefinition;
use App\Entity\OAuthAccount;
use App\Entity\Profile;
use App\Entity\User;
use App\Enum\OAuthProvider;
use App\Security\SocialAccountResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Exception\AuthenticationException;

final class SocialAccountResolverTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $databaseUrl = $_SERVER['DATABASE_URL'] ?? $_ENV['DATABASE_URL'] ?? '';

        if (parse_url($databaseUrl, PHP_URL_HOST) === 'HOST') {
            self::markTestSkipped('Neon development DATABASE_URL is required for database integration tests.');
        }

        self::bootKernel();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (isset($this->entityManager)) {
            $connection = $this->entityManager->getConnection();

            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }

            $this->entityManager->clear();
        }

        parent::tearDown();
    }

    public function testNewIdentityCreatesCandidateAccountAndProfileWithAllBuiltIns(): void
    {
        $user = $this->resolver()->resolve(OAuthProvider::GOOGLE, 'new-google-identity', static fn (): string => ' New@Example.test ');

        self::assertSame(['ROLE_CANDIDATE'], $user->getRoles());
        self::assertSame('new@example.test', $user->getNormalizedEmail());
        self::assertCount(1, $this->entityManager->getRepository(OAuthAccount::class)->findBy(['user' => $user]));

        $profile = $this->entityManager->getRepository(Profile::class)->findOneBy(['user' => $user]);
        self::assertNotNull($profile);
        $builtIns = $this->entityManager->getRepository(AttributeDefinition::class)->findBy(['isBuiltIn' => true]);
        self::assertCount(4, $builtIns);
        self::assertCount(count($builtIns), $profile->getValues());

        foreach ($builtIns as $definition) {
            self::assertTrue($profile->getValueFor($definition)->isEmpty());
        }
    }

    public function testExistingEmailLinksWithoutChangingRolesOrCreatingProfile(): void
    {
        $existing = new User('Existing@Example.test');
        $existing->setRoles(['ROLE_RECRUITER', 'ROLE_ADMIN']);
        $this->entityManager->persist($existing);
        $this->entityManager->flush();

        $resolved = $this->resolver()->resolve(OAuthProvider::GITHUB, 'new-github-identity', static fn (): string => 'existing@example.test');

        self::assertSame($existing, $resolved);
        self::assertSame(['ROLE_RECRUITER', 'ROLE_ADMIN'], $resolved->getRoles());
        self::assertNull($this->entityManager->getRepository(Profile::class)->findOneBy(['user' => $existing]));
        self::assertCount(1, $this->entityManager->getRepository(OAuthAccount::class)->findBy(['user' => $existing]));
    }

    public function testLinkedIdentityWinsAndRepeatedLoginDoesNotDuplicateRows(): void
    {
        $resolver = $this->resolver();
        $user = $resolver->resolve(OAuthProvider::GOOGLE, 'repeat-google', static fn (): string => 'repeat@example.test');
        $again = $resolver->resolve(OAuthProvider::GOOGLE, 'repeat-google', static function (): never {
            throw new \LogicException('Known provider identity must not fetch email again.');
        });
        $otherProvider = $resolver->resolve(OAuthProvider::GITHUB, 'repeat-github', static fn (): string => 'REPEAT@example.test');

        self::assertSame($user, $again);
        self::assertSame($user, $otherProvider);
        self::assertCount(1, $this->entityManager->getRepository(User::class)->findBy(['normalizedEmail' => 'repeat@example.test']));
        self::assertCount(2, $this->entityManager->getRepository(OAuthAccount::class)->findBy(['user' => $user]));
        self::assertCount(1, $this->entityManager->getRepository(Profile::class)->findBy(['user' => $user]));
    }

    public function testUnknownIdentityWithoutVerifiedEmailCannotCreateAnything(): void
    {
        try {
            $this->resolver()->resolve(OAuthProvider::GITHUB, 'missing-email', static fn (): ?string => null);
            self::fail('Expected authentication to fail without a verified email.');
        } catch (AuthenticationException) {
            self::assertSame([], $this->entityManager->getRepository(OAuthAccount::class)->findBy(['providerUserId' => 'missing-email']));
        }
    }

    public function testProviderEmailIsFetchedBeforeOpeningTheWriteTransaction(): void
    {
        $outerTransactionLevel = $this->entityManager->getConnection()->getTransactionNestingLevel();
        $levelWhileFetching = null;

        $this->resolver()->resolve(OAuthProvider::GOOGLE, 'fetch-before-write', function () use (&$levelWhileFetching): string {
            $levelWhileFetching = $this->entityManager->getConnection()->getTransactionNestingLevel();

            return 'before-write@example.test';
        });

        self::assertSame($outerTransactionLevel, $levelWhileFetching);
    }

    private function resolver(): SocialAccountResolver
    {
        return new SocialAccountResolver($this->entityManager);
    }
}
