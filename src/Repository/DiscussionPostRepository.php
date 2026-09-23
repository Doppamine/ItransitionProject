<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DiscussionPost;
use App\Entity\Position;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<DiscussionPost> */
final class DiscussionPostRepository extends ServiceEntityRepository
{
    private const BATCH_SIZE = 50;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DiscussionPost::class);
    }

    /** @return list<DiscussionPost> */
    public function latestForPosition(Position $position): array
    {
        $posts = $this->orderedBuilder($position, 'DESC')
            ->setMaxResults(self::BATCH_SIZE)->getQuery()->getResult();
        return array_reverse($posts);
    }

    /** @return list<DiscussionPost> */
    public function after(Position $position, int $lastId): array
    {
        return $this->orderedBuilder($position)
            ->andWhere('post.id > :lastId')->setParameter('lastId', $lastId)
            ->setMaxResults(self::BATCH_SIZE)->getQuery()->getResult();
    }

    private function orderedBuilder(Position $position, string $direction = 'ASC'): \Doctrine\ORM\QueryBuilder
    {
        return $this->createQueryBuilder('post')
            ->join('post.author', 'author')->addSelect('author')
            ->where('post.position = :position')->setParameter('position', $position)
            ->orderBy('post.createdAt', $direction)->addOrderBy('post.id', $direction);
    }
}
