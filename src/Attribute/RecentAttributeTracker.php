<?php

declare(strict_types=1);

namespace App\Attribute;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\RequestStack;

final class RecentAttributeTracker
{
    private const LIMIT = 10;

    public function __construct(private readonly RequestStack $requests, private readonly Connection $connection)
    {
    }

    public function record(int $definitionId): void
    {
        if ($definitionId < 1 || !$this->requests->getCurrentRequest()?->hasPreviousSession()) {
            return;
        }
        $session = $this->requests->getCurrentRequest()->getSession();
        $ids = array_values(array_filter($this->ids(), static fn (int $id): bool => $id !== $definitionId));
        array_unshift($ids, $definitionId);
        $session->set('recent_attributes', array_slice($ids, 0, self::LIMIT));
    }

    /** @return list<array{id: int, name: string, category: string}> */
    public function list(bool $rulesOnly = false): array
    {
        $ids = $this->ids();
        if ($ids === []) {
            return [];
        }
        $rows = $this->connection->executeQuery(
            'SELECT d.id, d.name, d.type, c.name AS category FROM attribute_definition d JOIN attribute_category c ON c.id = d.category_id WHERE d.id IN (:ids)',
            ['ids' => $ids], ['ids' => ArrayParameterType::INTEGER],
        )->fetchAllAssociative();
        $byId = [];
        foreach ($rows as $row) {
            if ($rulesOnly && $row['type'] === 'image') {
                continue;
            }
            $byId[(int) $row['id']] = ['id' => (int) $row['id'], 'name' => $row['name'], 'category' => $row['category']];
        }
        $result = [];
        foreach ($ids as $id) {
            if (isset($byId[$id])) {
                $result[] = $byId[$id];
            }
        }
        return $result;
    }

    /** @return list<int> */
    private function ids(): array
    {
        $request = $this->requests->getCurrentRequest();
        $raw = $request?->hasPreviousSession() ? $request->getSession()->get('recent_attributes', []) : [];
        if (!is_array($raw)) {
            return [];
        }
        $ids = [];
        foreach ($raw as $id) {
            if (is_int($id) && $id > 0 && !in_array($id, $ids, true)) {
                $ids[] = $id;
            }
            if (count($ids) === self::LIMIT) {
                break;
            }
        }
        return $ids;
    }
}
