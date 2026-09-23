<?php

declare(strict_types=1);

namespace App\Position;

use App\Entity\Position;
use App\Entity\Profile;
use App\Entity\User;
use App\Repository\ProfileRepository;

final class PositionDiscussionAccess
{
    public function __construct(
        private readonly ProfileRepository $profiles,
        private readonly PositionEligibilityChecker $eligibility,
    ) {
    }

    public function isManager(?User $user): bool
    {
        return $user !== null && (in_array('ROLE_RECRUITER', $user->getRoles(), true) || in_array('ROLE_ADMIN', $user->getRoles(), true));
    }

    public function eligibleCandidateProfile(?User $user, Position $position): ?Profile
    {
        if ($user === null || !in_array('ROLE_CANDIDATE', $user->getRoles(), true)) {
            return null;
        }
        $profile = $this->profiles->findForEligibility($user);
        return $profile !== null && $this->eligibility->isEligible($position, $profile) ? $profile : null;
    }

    public function canParticipate(?User $user, Position $position): bool
    {
        return $this->isManager($user) || $this->eligibleCandidateProfile($user, $position) !== null;
    }
}
