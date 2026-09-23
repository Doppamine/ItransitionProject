<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CV;
use App\Entity\Position;
use App\Entity\Profile;
use App\Enum\CVStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<CV> */
final class CVRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CV::class);
    }

    public function findDetailed(int $id): ?CV
    {
        return $this->createQueryBuilder('cv')
            ->join('cv.profile', 'profile')->addSelect('profile')
            ->join('cv.position', 'position')->addSelect('position')
            ->where('cv.id = :id')->setParameter('id', $id)
            ->getQuery()->getOneOrNullResult();
    }

    /** @return list<CV> */
    public function findForProfile(Profile $profile): array
    {
        return $this->createQueryBuilder('cv')
            ->join('cv.position', 'position')->addSelect('position')
            ->where('cv.profile = :profile')->setParameter('profile', $profile)
            ->orderBy('cv.updatedAt', 'DESC')->addOrderBy('cv.id', 'DESC')
            ->setMaxResults(100)->getQuery()->getResult();
    }

    /** @return list<CV> */
    public function findForPosition(Position $position, bool $publishedOnly): array
    {
        $builder = $this->createQueryBuilder('cv')
            ->join('cv.profile', 'profile')->addSelect('profile')
            ->join('profile.user', 'candidate')->addSelect('candidate')
            ->join('cv.position', 'position')->addSelect('position')
            ->where('cv.position = :position')->setParameter('position', $position)
            ->orderBy('cv.updatedAt', 'DESC')->addOrderBy('cv.id', 'DESC');
        if ($publishedOnly) {
            $builder->andWhere('cv.status = :status')->setParameter('status', CVStatus::PUBLISHED);
        }
        return $builder->setMaxResults(100)->getQuery()->getResult();
    }
}
