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
                       COALESCE(usage.value_count, 0) AS usage_count
                FROM attribute_definition d
                JOIN attribute_category c ON c.id = d.category_id
                LEFT JOIN (SELECT attribute_definition_id, COUNT(*) AS value_count
                           FROM profile_attribute_value GROUP BY attribute_definition_id) usage
                    ON usage.attribute_definition_id = d.id
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
        return (int) $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM profile_attribute_value WHERE attribute_definition_id = ?',
            [$definition->getId()],
        ) > 0;
    }

    public function isOptionReferenced(AttributeOption $option): bool
    {
        return (int) $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM profile_attribute_value WHERE option_id = ?',
            [$option->getId()],
        ) > 0;
    }
}
