<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Tag;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Tag> */
final class TagRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Tag::class);
    }

    /** @return list<string> */
    public function search(string $prefix): array
    {
        $prefix = mb_strtolower(trim(mb_substr($prefix, 0, 100)), 'UTF-8');
        if ($prefix === '') {
            return [];
        }

        return $this->getEntityManager()->getConnection()->executeQuery(
            'SELECT name FROM tag WHERE LEFT(normalized_name, LENGTH(:prefix)) = :prefix ORDER BY normalized_name, id LIMIT 15',
            ['prefix' => $prefix],
        )->fetchFirstColumn();
    }

    /** @return list<Tag> */
    public function findAlphabetical(int $limit = 100): array
    {
        return $this->createQueryBuilder('t')->orderBy('t.normalizedName', 'ASC')->setMaxResults($limit)->getQuery()->getResult();
    }
}
