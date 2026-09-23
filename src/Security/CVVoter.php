<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\CV;
use App\Entity\User;
use App\Enum\CVStatus;
use App\Position\PositionEligibilityChecker;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

final class CVVoter extends Voter
{
    public const VIEW = 'CV_VIEW';
    public const EDIT = 'CV_EDIT';
    public const PUBLISH = 'CV_PUBLISH';

    public function __construct(private readonly PositionEligibilityChecker $eligibility)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $subject instanceof CV && in_array($attribute, [self::VIEW, self::EDIT, self::PUBLISH], true);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }
        if (in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            return true;
        }

        $cv = $subject;
        if (in_array('ROLE_CANDIDATE', $user->getRoles(), true)
            && ($cv->getProfile()->getUser() === $user || ($user->getId() !== null && $cv->getProfile()->getUser()->getId() === $user->getId()))) {
            return $this->eligibility->isEligible($cv->getPosition(), $cv->getProfile());
        }

        return $attribute === self::VIEW
            && in_array('ROLE_RECRUITER', $user->getRoles(), true)
            && $cv->getStatus() === CVStatus::PUBLISHED
            && $this->eligibility->isEligible($cv->getPosition(), $cv->getProfile());
    }
}
