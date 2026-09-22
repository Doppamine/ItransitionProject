<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AttributeDefinition;
use App\Entity\AttributeOption;
use App\Enum\AttributeType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<AttributeDefinition> */
final class AttributeDefinitionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AttributeDefinition::class);
    }

    /** @return list<array<string, mixed>> */
    public function search(string $prefix, ?int $categoryId, ?AttributeType $type): array
    {
        $sql = 'SELECT d.id, d.name, c.name AS category, d.type, d.is_built_in,
                       ((SELECT COUNT(*) FROM profile_attribute_value v WHERE v.attribute_definition_id = d.id)
                        + (SELECT COUNT(*) FROM position_attribute pa WHERE pa.attribute_definition_id = d.id)
                        + (SELECT COUNT(*) FROM position_access_rule pr WHERE pr.attribute_definition_id = d.id)) AS usage_count
                FROM attribute_definition d
                JOIN attribute_category c ON c.id = d.category_id
                WHERE LEFT(d.normalized_name, LENGTH(:prefix)) = :prefix';
        $parameters = ['prefix' => mb_strtolower(trim($prefix), 'UTF-8')];
        if ($categoryId !== null) {
            $sql .= ' AND d.category_id = :category';
            $parameters['category'] = $categoryId;
        }
        if ($type !== null) {
            $sql .= ' AND d.type = :type';
            $parameters['type'] = $type->value;
        }
        $sql .= ' ORDER BY d.normalized_name, d.id LIMIT 100';

        return $this->getEntityManager()->getConnection()->executeQuery($sql, $parameters)->fetchAllAssociative();
    }

    public function isUsed(AttributeDefinition $definition): bool
    {
        return (bool) $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT EXISTS(SELECT 1 FROM profile_attribute_value WHERE attribute_definition_id = ?)
                 OR EXISTS(SELECT 1 FROM position_attribute WHERE attribute_definition_id = ?)
                 OR EXISTS(SELECT 1 FROM position_access_rule WHERE attribute_definition_id = ?)',
            [$definition->getId(), $definition->getId(), $definition->getId()],
        );
    }

    /** @return list<AttributeDefinition> */
    public function findSelectable(bool $rulesOnly = false): array
    {
        $builder = $this->createQueryBuilder('d')->leftJoin('d.category', 'c')->addSelect('c')
            ->orderBy('d.normalizedName', 'ASC')->setMaxResults(100);
        if ($rulesOnly) {
            $builder->andWhere('d.type != :image')->setParameter('image', AttributeType::IMAGE);
        }
        return $builder->getQuery()->getResult();
    }

    public function isOptionReferenced(AttributeOption $option): bool
    {
        return (bool) $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT EXISTS(SELECT 1 FROM profile_attribute_value WHERE option_id = ?)
                 OR EXISTS(SELECT 1 FROM position_access_rule WHERE option_id = ?)',
            [$option->getId(), $option->getId()],
        );
    }

}
