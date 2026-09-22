<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AttributeDefinition;
use App\Entity\Profile;
use App\Entity\User;
use App\Enum\AttributeType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Profile> */
final class ProfileRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Profile::class);
    }

    public function findForUser(User $user): ?Profile
    {
        $profile = $this->createQueryBuilder('p')
            ->leftJoin('p.values', 'v')->addSelect('v')
            ->leftJoin('v.definition', 'd')->addSelect('d')
            ->leftJoin('d.category', 'c')->addSelect('c')
            ->leftJoin('v.option', 'selected')->addSelect('selected')
            ->where('p.user = :user')->setParameter('user', $user)
            ->getQuery()->getOneOrNullResult();

        if ($profile === null) {
            return null;
        }

        $selectIds = [];
        foreach ($profile->getValues() as $value) {
            $definition = $value->getDefinition();
            if ($definition->getType() === AttributeType::SELECT) {
                $selectIds[] = $definition->getId();
            }
        }

        if ($selectIds !== []) {
            $this->getEntityManager()->createQueryBuilder()
                ->select('d', 'o')
                ->from(AttributeDefinition::class, 'd')
                ->leftJoin('d.options', 'o')
                ->where('d.id IN (:ids)')->setParameter('ids', $selectIds)
                ->getQuery()->getResult();
        }

        return $profile;
    }

    /** @return list<array{id: int, name: string, category: string, type: string}> */
    public function searchAvailable(Profile $profile, string $prefix, ?int $categoryId): array
    {
        $sql = 'SELECT d.id, d.name, c.name AS category, d.type
            FROM attribute_definition d
            JOIN attribute_category c ON c.id = d.category_id
            WHERE d.is_built_in = FALSE
              AND LEFT(d.normalized_name, LENGTH(:prefix)) = :prefix
              AND NOT EXISTS (
                  SELECT 1 FROM profile_attribute_value v
                  WHERE v.profile_id = :profile AND v.attribute_definition_id = d.id
              )';
        $parameters = ['prefix' => mb_strtolower(trim($prefix), 'UTF-8'), 'profile' => $profile->getId()];
        if ($categoryId !== null) {
            $sql .= ' AND d.category_id = :category';
            $parameters['category'] = $categoryId;
        }
        $sql .= ' ORDER BY d.normalized_name, d.id LIMIT 20';

        return $this->getEntityManager()->getConnection()->executeQuery($sql, $parameters)->fetchAllAssociative();
    }
}
