<?php

declare(strict_types=1);

namespace App\Tests\CV;

use App\Entity\CV;
use App\Entity\Position;
use App\Entity\Profile;
use App\Entity\User;
use App\Enum\PositionAccessType;
use App\Position\PositionEligibilityChecker;
use App\Security\CVVoter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

final class CVVoterTest extends TestCase
{
    public function testCandidateOwnsEligibleCvAndRecruiterNeedsPublication(): void
    {
        $owner = $this->user('owner@example.test', ['ROLE_CANDIDATE']);
        $stranger = $this->user('stranger@example.test', ['ROLE_CANDIDATE']);
        $recruiter = $this->user('recruiter@example.test', ['ROLE_RECRUITER']);
        $cv = new CV(Profile::createWithBuiltIns($owner, []), new Position('Developer', '', PositionAccessType::PUBLIC, 0));
        $voter = new CVVoter(new PositionEligibilityChecker());

        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($voter, $owner, CVVoter::EDIT, $cv));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($voter, $stranger, CVVoter::VIEW, $cv));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($voter, $recruiter, CVVoter::VIEW, $cv));
        $cv->publish();
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($voter, $recruiter, CVVoter::VIEW, $cv));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($voter, $recruiter, CVVoter::EDIT, $cv));
    }

    public function testAdminOverrideAlsoCoversCurrentlyIneligibleCv(): void
    {
        $owner = $this->user('owner@example.test', ['ROLE_CANDIDATE']);
        $admin = $this->user('admin@example.test', ['ROLE_ADMIN']);
        $cv = new CV(Profile::createWithBuiltIns($owner, []), new Position('Private', '', PositionAccessType::RESTRICTED, 0));
        $voter = new CVVoter(new PositionEligibilityChecker());

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($voter, $owner, CVVoter::VIEW, $cv));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($voter, $admin, CVVoter::VIEW, $cv));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($voter, $admin, CVVoter::PUBLISH, $cv));
    }

    private function user(string $email, array $roles): User
    {
        $user = new User($email);
        $user->setRoles($roles);
        return $user;
    }

    private function vote(CVVoter $voter, User $user, string $permission, CV $cv): int
    {
        return $voter->vote(new UsernamePasswordToken($user, 'main', $user->getRoles()), $cv, [$permission]);
    }
}
