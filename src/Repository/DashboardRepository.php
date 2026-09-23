<?php

declare(strict_types=1);

namespace App\Repository;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

final class DashboardRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /** @return list<int> */
    public function latestPositionIds(bool $publicOnly, int $limit): array
    {
        return array_map('intval', $this->connection->executeQuery(
            "SELECT id FROM position WHERE (:public_only = FALSE OR access_type = 'public') ORDER BY updated_at DESC, created_at DESC, id DESC LIMIT :limit",
            ['public_only' => $publicOnly, 'limit' => $limit],
            ['public_only' => ParameterType::BOOLEAN, 'limit' => ParameterType::INTEGER],
        )->fetchFirstColumn());
    }

    /** @return list<int> */
    public function recentTaggedPositionIds(int $limit): array
    {
        return array_map('intval', $this->connection->executeQuery(
            <<<'SQL'
                SELECT p.id FROM position p
                WHERE EXISTS (SELECT 1 FROM position_project_tag link WHERE link.position_id = p.id)
                ORDER BY p.updated_at DESC, p.id DESC
                LIMIT :limit
                SQL,
            ['limit' => $limit],
            ['limit' => ParameterType::INTEGER],
        )->fetchFirstColumn());
    }

    /** @return array<int, int> Position ID => published CV count, in popularity order */
    public function popularPositionCounts(bool $publicOnly, int $limit): array
    {
        $rows = $this->connection->executeQuery(
            <<<'SQL'
                SELECT p.id, COUNT(cv.id) AS submitted
                FROM position p
                LEFT JOIN cv ON cv.position_id = p.id AND cv.status = 'PUBLISHED'
                WHERE (:public_only = FALSE OR p.access_type = 'public')
                GROUP BY p.id, p.updated_at
                ORDER BY COUNT(cv.id) DESC, p.updated_at DESC, p.id DESC
                LIMIT :limit
                SQL,
            ['public_only' => $publicOnly, 'limit' => $limit],
            ['public_only' => ParameterType::BOOLEAN, 'limit' => ParameterType::INTEGER],
        )->fetchAllAssociative();
        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['id']] = (int) $row['submitted'];
        }
        return $counts;
    }

    /** @param list<int>|null $visiblePositionIds
     *  @return list<array{id: int, name: string, weight: int}>
     */
    public function tagCloud(bool $publicOnly, ?array $visiblePositionIds, int $limit = 25): array
    {
        if ($visiblePositionIds === []) {
            return [];
        }
        $sql = <<<'SQL'
            SELECT t.id, t.name, COUNT(DISTINCT link.position_id) AS weight
            FROM tag t
            JOIN position_project_tag link ON link.tag_id = t.id
            JOIN position p ON p.id = link.position_id
            WHERE (:public_only = FALSE OR p.access_type = 'public')
            SQL;
        $parameters = ['public_only' => $publicOnly, 'limit' => $limit];
        $types = ['public_only' => ParameterType::BOOLEAN, 'limit' => ParameterType::INTEGER];
        if ($visiblePositionIds !== null) {
            $sql .= ' AND p.id IN (:ids)';
            $parameters['ids'] = $visiblePositionIds;
            $types['ids'] = ArrayParameterType::INTEGER;
        }
        $sql .= ' GROUP BY t.id, t.name, t.normalized_name ORDER BY COUNT(DISTINCT link.position_id) DESC, t.normalized_name, t.id LIMIT :limit';
        $rows = $this->connection->executeQuery($sql, $parameters, $types)->fetchAllAssociative();
        return array_map(static fn (array $row): array => ['id' => (int) $row['id'], 'name' => $row['name'], 'weight' => (int) $row['weight']], $rows);
    }

    /** @return list<int> */
    public function taggedPositionIds(int $tagId, bool $publicOnly, int $limit): array
    {
        return array_map('intval', $this->connection->executeQuery(
            <<<'SQL'
                SELECT p.id FROM position p
                JOIN position_project_tag link ON link.position_id = p.id
                WHERE link.tag_id = :tag AND (:public_only = FALSE OR p.access_type = 'public')
                ORDER BY p.updated_at DESC, p.id DESC LIMIT :limit
                SQL,
            ['tag' => $tagId, 'public_only' => $publicOnly, 'limit' => $limit],
            ['tag' => ParameterType::INTEGER, 'public_only' => ParameterType::BOOLEAN, 'limit' => ParameterType::INTEGER],
        )->fetchFirstColumn());
    }

    /** @return list<int> */
    public function taggedCVIds(int $tagId, bool $publishedOnly, int $limit): array
    {
        return array_map('intval', $this->connection->executeQuery(
            <<<'SQL'
                SELECT cv.id FROM cv
                JOIN position_project_tag link ON link.position_id = cv.position_id
                WHERE link.tag_id = :tag AND (:published_only = FALSE OR cv.status = 'PUBLISHED')
                ORDER BY cv.updated_at DESC, cv.id DESC LIMIT :limit
                SQL,
            ['tag' => $tagId, 'published_only' => $publishedOnly, 'limit' => $limit],
            ['tag' => ParameterType::INTEGER, 'published_only' => ParameterType::BOOLEAN, 'limit' => ParameterType::INTEGER],
        )->fetchFirstColumn());
    }

    /** @return array{cvs24h: int, positions: int, candidates: int, recruiters: int, submitted: int} */
    public function statistics(): array
    {
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT
                    (SELECT COUNT(*) FROM cv WHERE created_at >= CURRENT_TIMESTAMP - INTERVAL '24 hours') AS cvs24h,
                    (SELECT COUNT(*) FROM position) AS positions,
                    (SELECT COUNT(*) FROM app_user WHERE roles::jsonb @> '["ROLE_CANDIDATE"]'::jsonb) AS candidates,
                    (SELECT COUNT(*) FROM app_user WHERE roles::jsonb @> '["ROLE_RECRUITER"]'::jsonb) AS recruiters,
                    (SELECT COUNT(*) FROM cv WHERE status = 'PUBLISHED') AS submitted
                SQL,
        );
        return array_map('intval', $row);
    }
}
