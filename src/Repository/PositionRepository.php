<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Position;
use App\Enum\PositionAccessType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Position> */
final class PositionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Position::class);
    }

    /** @return list<Position> */
    public function findForList(bool $publicOnly): array
    {
        $builder = $this->createQueryBuilder('p')->orderBy('p.updatedAt', 'DESC')->addOrderBy('p.id', 'DESC')->setMaxResults(100);
        if ($publicOnly) {
            $builder->andWhere('p.accessType = :access')->setParameter('access', PositionAccessType::PUBLIC);
        }
        $positions = $builder->getQuery()->getResult();
        $this->hydrateChildren($positions);
        return $positions;
    }

    public function findDetailed(int $id): ?Position
    {
        $position = $this->find($id);
        if ($position !== null) {
            $this->hydrateChildren([$position]);
        }
        return $position;
    }

    /** @param list<int> $ids
     *  @return list<Position>
     */
    public function findByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $positions = $this->findBy(['id' => $ids]);
        $this->hydrateChildren($positions);
        return $positions;
    }

    /** @param list<Position> $positions */
    public function hydrateChildren(array $positions): void
    {
        if ($positions === []) {
            return;
        }
        $ids = array_map(static fn (Position $position): int => (int) $position->getId(), $positions);
        $em = $this->getEntityManager();
        $em->createQueryBuilder()->select('p', 'a', 'd', 'c')->from(Position::class, 'p')
            ->leftJoin('p.attributes', 'a')->leftJoin('a.definition', 'd')->leftJoin('d.category', 'c')
            ->where('p.id IN (:ids)')->setParameter('ids', $ids)->getQuery()->getResult();
        $em->createQueryBuilder()->select('p', 'l', 't')->from(Position::class, 'p')
            ->leftJoin('p.projectTagLinks', 'l')->leftJoin('l.tag', 't')
            ->where('p.id IN (:ids)')->setParameter('ids', $ids)->getQuery()->getResult();
        $em->createQueryBuilder()->select('p', 'r', 'd', 'o')->from(Position::class, 'p')
            ->leftJoin('p.accessRules', 'r')->leftJoin('r.definition', 'd')->leftJoin('r.option', 'o')
            ->where('p.id IN (:ids)')->setParameter('ids', $ids)->getQuery()->getResult();
    }
}
