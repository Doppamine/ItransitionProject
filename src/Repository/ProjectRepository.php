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

    /** @param list<\App\Entity\Tag> $tags
     *  @return list<Project>
     */
    public function findForCV(Profile $profile, array $tags, int $limit): array
    {
        if ($tags === [] || $limit <= 0) {
            return [];
        }
        $tagIds = array_map(static fn ($tag): int => (int) $tag->getId(), $tags);

        return $this->createQueryBuilder('project')
            ->distinct()->join('project.tags', 'matchedTag')
            ->addSelect('(CASE WHEN project.endDate IS NULL THEN 0 ELSE 1 END) AS HIDDEN endRank')
            ->where('project.profile = :profile')->setParameter('profile', $profile)
            ->andWhere('matchedTag.id IN (:tags)')->setParameter('tags', $tagIds)
            ->orderBy('endRank', 'ASC')->addOrderBy('project.endDate', 'DESC')
            ->addOrderBy('project.startDate', 'DESC')->addOrderBy('project.id', 'DESC')
            ->setMaxResults($limit)->getQuery()->getResult();
    }
}
