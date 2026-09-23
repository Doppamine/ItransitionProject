<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CV;
use App\Entity\CVLike;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<CVLike> */
final class CVLikeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CVLike::class);
    }

    public function like(CV $cv, User $recruiter): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'INSERT INTO cv_like (cv_id, recruiter_user_id, created_at) VALUES (:cv, :recruiter, CURRENT_TIMESTAMP) ON CONFLICT (cv_id, recruiter_user_id) DO NOTHING',
            ['cv' => $cv->getId(), 'recruiter' => $recruiter->getId()],
        );
    }

    public function unlike(CV $cv, User $recruiter): void
    {
        $this->getEntityManager()->getConnection()->delete('cv_like', ['cv_id' => $cv->getId(), 'recruiter_user_id' => $recruiter->getId()]);
    }

    public function hasLiked(CV $cv, User $recruiter): bool
    {
        return (bool) $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT 1 FROM cv_like WHERE cv_id = :cv AND recruiter_user_id = :recruiter',
            ['cv' => $cv->getId(), 'recruiter' => $recruiter->getId()],
        );
    }

    /** @param list<CV> $cvs
     *  @return array<int, int>
     */
    public function countsForCVs(array $cvs): array
    {
        if ($cvs === []) {
            return [];
        }
        $ids = array_map(static fn (CV $cv): int => (int) $cv->getId(), $cvs);
        $counts = array_fill_keys($ids, 0);
        $rows = $this->getEntityManager()->getConnection()->executeQuery(
            'SELECT cv_id, COUNT(*) AS likes FROM cv_like WHERE cv_id IN (:ids) GROUP BY cv_id',
            ['ids' => $ids],
            ['ids' => ArrayParameterType::INTEGER],
        )->fetchAllAssociative();
        foreach ($rows as $row) {
            $counts[(int) $row['cv_id']] = (int) $row['likes'];
        }
        return $counts;
    }
}
