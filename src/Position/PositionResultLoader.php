<?php

declare(strict_types=1);

namespace App\Position;

use App\Entity\Position;
use App\Entity\User;
use App\Enum\PositionAccessType;
use App\Repository\PositionRepository;
use App\Repository\ProfileRepository;

final class PositionResultLoader
{
    public function __construct(
        private readonly PositionRepository $positions,
        private readonly ProfileRepository $profiles,
        private readonly PositionEligibilityChecker $eligibility,
    ) {
    }

    /** @param list<int> $ids
     *  @return list<Position>
     */
    public function visibleByIds(array $ids, ?User $user, int $limit): array
    {
        if ($ids === []) {
            return [];
        }
        $manager = $user !== null && (in_array('ROLE_ADMIN', $user->getRoles(), true) || in_array('ROLE_RECRUITER', $user->getRoles(), true));
        $candidate = $user !== null && in_array('ROLE_CANDIDATE', $user->getRoles(), true);
        $profile = !$manager && $candidate ? $this->profiles->findForEligibility($user) : null;
        $byId = [];
        foreach ($this->positions->findByIds($ids) as $position) {
            $byId[$position->getId()] = $position;
        }
        $visible = [];
        foreach ($ids as $id) {
            $position = $byId[$id] ?? null;
            if ($position === null || (!$manager && $candidate && ($profile === null || !$this->eligibility->isEligible($position, $profile)))
                || (!$manager && !$candidate && $position->getAccessType() !== PositionAccessType::PUBLIC)) {
                continue;
            }
            $visible[] = $position;
            if (count($visible) === $limit) {
                break;
            }
        }
        return $visible;
    }
}
