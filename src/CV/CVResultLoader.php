<?php

declare(strict_types=1);

namespace App\CV;

use App\Entity\CV;
use App\Repository\CVLikeRepository;
use App\Repository\CVRepository;
use App\Repository\PositionRepository;
use App\Repository\ProfileRepository;
use App\Security\CVVoter;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

final class CVResultLoader
{
    public function __construct(
        private readonly CVRepository $cvs,
        private readonly PositionRepository $positions,
        private readonly ProfileRepository $profiles,
        private readonly CVLikeRepository $likes,
        private readonly AuthorizationCheckerInterface $authorization,
    ) {
    }

    /** @param list<int> $ids
     *  @return list<array{cv: CV, candidateName: string, likeCount: int}>
     */
    public function visibleRowsByIds(array $ids, int $limit): array
    {
        if ($ids === []) {
            return [];
        }
        $found = $this->cvs->findByIdsForResults($ids);
        $this->positions->hydrateChildren(array_map(static fn (CV $cv) => $cv->getPosition(), $found));
        $this->profiles->hydrateValues(array_map(static fn (CV $cv) => $cv->getProfile(), $found));
        $byId = [];
        foreach ($found as $cv) {
            $byId[$cv->getId()] = $cv;
        }
        $visible = [];
        foreach ($ids as $id) {
            $cv = $byId[$id] ?? null;
            if ($cv === null || !$this->authorization->isGranted(CVVoter::VIEW, $cv)) {
                continue;
            }
            $visible[] = $cv;
            if (count($visible) === $limit) {
                break;
            }
        }
        $names = $this->profiles->publicDetailsForUsers(array_map(static fn (CV $cv): int => (int) $cv->getProfile()->getUser()->getId(), $visible));
        $counts = $this->likes->countsForCVs($visible);
        $rows = [];
        foreach ($visible as $cv) {
            $candidateId = (int) $cv->getProfile()->getUser()->getId();
            $name = trim(($names[$candidateId]['first name'] ?? '').' '.($names[$candidateId]['last name'] ?? ''));
            $rows[] = [
                'cv' => $cv,
                'candidateName' => $name !== '' ? $name : 'Candidate #'.$candidateId,
                'likeCount' => $counts[$cv->getId()] ?? 0,
            ];
        }
        return $rows;
    }
}
