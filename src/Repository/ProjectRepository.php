<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Profile;
use App\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Project> */
final class ProjectRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Project::class);
    }

    /** @return list<Project> */
    public function findRecentForProfile(Profile $profile): array
    {
        return $this->createQueryBuilder('project')
            ->leftJoin('project.tags', 'tag')->addSelect('tag')
            ->addSelect('(CASE WHEN project.endDate IS NULL THEN 0 ELSE 1 END) AS HIDDEN endRank')
            ->where('project.profile = :profile')->setParameter('profile', $profile)
            ->orderBy('endRank', 'ASC')
            ->addOrderBy('project.endDate', 'DESC')
            ->addOrderBy('project.startDate', 'DESC')
            ->addOrderBy('project.id', 'DESC')
            ->getQuery()->getResult();
    }
}
