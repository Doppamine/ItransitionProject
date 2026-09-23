<?php

declare(strict_types=1);

namespace App\Repository;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

final class SearchRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function hasIndexableTerm(string $query): bool
    {
        $tree = $this->connection->fetchOne("SELECT querytree(websearch_to_tsquery('simple'::regconfig, :query))", ['query' => $query]);
        return $tree !== '' && $tree !== 'T';
    }

    /** @return list<int> */
    public function positionIds(string $query, bool $publicOnly, int $limit): array
    {
        $rows = $this->connection->executeQuery(
            <<<'SQL'
                WITH term AS (SELECT websearch_to_tsquery('simple'::regconfig, :query) AS tsq)
                SELECT p.id
                FROM position p CROSS JOIN term
                WHERE to_tsvector('simple'::regconfig, coalesce(p.title, '') || ' ' || coalesce(p.short_description, '')) @@ term.tsq
                  AND (:public_only = FALSE OR p.access_type = 'public')
                ORDER BY ts_rank(to_tsvector('simple'::regconfig, coalesce(p.title, '') || ' ' || coalesce(p.short_description, '')), term.tsq) DESC,
                         p.updated_at DESC, p.id DESC
                LIMIT :limit
                SQL,
            ['query' => $query, 'public_only' => $publicOnly, 'limit' => $limit],
            ['public_only' => ParameterType::BOOLEAN, 'limit' => ParameterType::INTEGER],
        )->fetchFirstColumn();

        return array_map('intval', $rows);
    }

    /** @return list<int> */
    public function cvIds(string $query, bool $publishedOnly, int $limit): array
    {
        $rows = $this->connection->executeQuery(
            <<<'SQL'
                WITH term AS (
                    SELECT websearch_to_tsquery('simple'::regconfig, :query) AS tsq,
                           (SELECT string_agg(quote_literal(lexeme), ' | ')::tsquery
                            FROM unnest(tsvector_to_array(to_tsvector('simple'::regconfig, :query))) AS lexeme) AS any_tsq
                ),
                hits AS (
                    SELECT cv.id
                    FROM position p CROSS JOIN term
                    JOIN cv ON cv.position_id = p.id
                    WHERE to_tsvector('simple'::regconfig, coalesce(p.title, '') || ' ' || coalesce(p.short_description, '')) @@ term.any_tsq
                      AND (:published_only = FALSE OR cv.status = 'PUBLISHED')
                    UNION
                    SELECT cv.id
                    FROM profile_attribute_value v CROSS JOIN term
                    JOIN attribute_definition d ON d.id = v.attribute_definition_id
                    JOIN cv ON cv.profile_id = v.profile_id
                    WHERE to_tsvector('simple'::regconfig, coalesce(v.text_value, '')) @@ term.any_tsq
                      AND (:published_only = FALSE OR cv.status = 'PUBLISHED')
                      AND ((d.is_built_in = TRUE AND d.normalized_name IN ('first name', 'last name', 'location'))
                           OR EXISTS (SELECT 1 FROM position_attribute pa WHERE pa.position_id = cv.position_id AND pa.attribute_definition_id = v.attribute_definition_id))
                    UNION
                    SELECT cv.id
                    FROM attribute_option o CROSS JOIN term
                    JOIN profile_attribute_value v ON v.option_id = o.id AND v.attribute_definition_id = o.attribute_definition_id
                    JOIN attribute_definition d ON d.id = v.attribute_definition_id
                    JOIN cv ON cv.profile_id = v.profile_id
                    WHERE to_tsvector('simple'::regconfig, coalesce(o.label, '')) @@ term.any_tsq
                      AND (:published_only = FALSE OR cv.status = 'PUBLISHED')
                      AND ((d.is_built_in = TRUE AND d.normalized_name IN ('first name', 'last name', 'location'))
                           OR EXISTS (SELECT 1 FROM position_attribute pa WHERE pa.position_id = cv.position_id AND pa.attribute_definition_id = v.attribute_definition_id))
                )
                SELECT cv.id
                FROM hits JOIN cv ON cv.id = hits.id
                JOIN position p ON p.id = cv.position_id
                CROSS JOIN term
                CROSS JOIN LATERAL (
                    SELECT to_tsvector('simple'::regconfig,
                        coalesce(p.title, '') || ' ' || coalesce(p.short_description, '') || ' ' ||
                        coalesce((SELECT string_agg(v.text_value, ' ' ORDER BY v.attribute_definition_id)
                                  FROM profile_attribute_value v
                                  JOIN attribute_definition d ON d.id = v.attribute_definition_id
                                  WHERE v.profile_id = cv.profile_id AND v.text_value IS NOT NULL
                                    AND ((d.is_built_in = TRUE AND d.normalized_name IN ('first name', 'last name', 'location'))
                                         OR EXISTS (SELECT 1 FROM position_attribute pa WHERE pa.position_id = cv.position_id AND pa.attribute_definition_id = v.attribute_definition_id))), '') || ' ' ||
                        coalesce((SELECT string_agg(o.label, ' ' ORDER BY v.attribute_definition_id)
                                  FROM profile_attribute_value v
                                  JOIN attribute_option o ON o.id = v.option_id AND o.attribute_definition_id = v.attribute_definition_id
                                  JOIN attribute_definition d ON d.id = v.attribute_definition_id
                                  WHERE v.profile_id = cv.profile_id
                                    AND ((d.is_built_in = TRUE AND d.normalized_name IN ('first name', 'last name', 'location'))
                                         OR EXISTS (SELECT 1 FROM position_attribute pa WHERE pa.position_id = cv.position_id AND pa.attribute_definition_id = v.attribute_definition_id))), '')
                    ) AS document
                ) corpus
                WHERE corpus.document @@ term.tsq
                ORDER BY ts_rank(corpus.document, term.tsq) DESC, cv.updated_at DESC, cv.id DESC
                LIMIT :limit
                SQL,
            ['query' => $query, 'published_only' => $publishedOnly, 'limit' => $limit],
            ['published_only' => ParameterType::BOOLEAN, 'limit' => ParameterType::INTEGER],
        )->fetchFirstColumn();

        return array_map('intval', $rows);
    }
}
