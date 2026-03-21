<?php

declare(strict_types=1);

namespace App\Entity;

use App\Core\Database;

/**
 * Manages stateful entities within the virtualization platform.
 * Each entity belongs to a namespace and has a state machine.
 */
final class EntityManager
{
    public static function create(
        string $namespace,
        string $entityType,
        string $entityRef,
        string $state,
        array  $data = [],
    ): int {
        $pdo = Database::connect();
        $stmt = $pdo->prepare("
            INSERT INTO entities (namespace, entity_type, entity_ref, state, data)
            VALUES (:ns, :type, :ref, :state, :data)
        ");
        $stmt->execute([
            'ns'    => $namespace,
            'type'  => $entityType,
            'ref'   => $entityRef,
            'state' => $state,
            'data'  => json_encode($data),
        ]);

        $entityId = (int) $pdo->lastInsertId();

        // Record initial state
        self::recordTransition($entityId, '', $state, 'api_call');

        return $entityId;
    }

    public static function find(string $namespace, string $entityType, string $entityRef): ?array
    {
        $pdo = Database::connect();
        $stmt = $pdo->prepare("
            SELECT * FROM entities
            WHERE namespace = :ns AND entity_type = :type AND entity_ref = :ref
        ");
        $stmt->execute(['ns' => $namespace, 'type' => $entityType, 'ref' => $entityRef]);
        $row = $stmt->fetch();
        if ($row) {
            $row['data'] = json_decode($row['data'], true);
        }
        return $row ?: null;
    }

    public static function findById(int $id): ?array
    {
        $pdo = Database::connect();
        $stmt = $pdo->prepare("SELECT * FROM entities WHERE id = :id");
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        if ($row) {
            $row['data'] = json_decode($row['data'], true);
        }
        return $row ?: null;
    }

    public static function findAllByNamespace(string $namespace, ?string $entityType = null): array
    {
        $pdo = Database::connect();
        $sql = "SELECT * FROM entities WHERE namespace = :ns";
        $params = ['ns' => $namespace];

        if ($entityType !== null) {
            $sql .= " AND entity_type = :type";
            $params['type'] = $entityType;
        }

        $stmt = $pdo->prepare($sql . " ORDER BY created_at DESC");
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$row) {
            $row['data'] = json_decode($row['data'], true);
        }
        return $rows;
    }

    /**
     * Transition an entity to a new state.
     * Returns true if the transition was applied, false if the entity was not found.
     */
    public static function transition(
        int    $entityId,
        string $newState,
        string $triggerType = 'api_call',
        array  $metadata = [],
        array  $dataUpdates = [],
    ): bool {
        $pdo = Database::connect();
        $entity = self::findById($entityId);
        if ($entity === null) {
            return false;
        }

        $oldState = $entity['state'];

        // Merge data updates
        $newData = array_merge($entity['data'] ?? [], $dataUpdates);

        $stmt = $pdo->prepare("UPDATE entities SET state = :state, data = :data WHERE id = :id");
        $stmt->execute([
            'state' => $newState,
            'data'  => json_encode($newData),
            'id'    => $entityId,
        ]);

        self::recordTransition($entityId, $oldState, $newState, $triggerType, $metadata);

        return true;
    }

    public static function updateData(int $entityId, array $dataUpdates): bool
    {
        $entity = self::findById($entityId);
        if ($entity === null) {
            return false;
        }

        $newData = array_merge($entity['data'] ?? [], $dataUpdates);
        $pdo = Database::connect();
        $stmt = $pdo->prepare("UPDATE entities SET data = :data WHERE id = :id");
        $stmt->execute(['data' => json_encode($newData), 'id' => $entityId]);
        return true;
    }

    public static function getHistory(int $entityId): array
    {
        $pdo = Database::connect();
        $stmt = $pdo->prepare("SELECT * FROM state_history WHERE entity_id = :id ORDER BY created_at ASC");
        $stmt->execute(['id' => $entityId]);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$row) {
            if ($row['metadata']) {
                $row['metadata'] = json_decode($row['metadata'], true);
            }
        }
        return $rows;
    }

    /**
     * Delete all entities in a namespace (used by scenario reset).
     */
    public static function deleteByNamespace(string $namespace): int
    {
        $pdo = Database::connect();
        // state_history cascades via FK
        $stmt = $pdo->prepare("DELETE FROM entities WHERE namespace = :ns");
        $stmt->execute(['ns' => $namespace]);
        return $stmt->rowCount();
    }

    private static function recordTransition(
        int    $entityId,
        string $fromState,
        string $toState,
        string $triggerType,
        array  $metadata = [],
    ): void {
        $pdo = Database::connect();
        $pdo->prepare("
            INSERT INTO state_history (entity_id, from_state, to_state, trigger_type, metadata)
            VALUES (:eid, :from, :to, :trigger, :meta)
        ")->execute([
            'eid'     => $entityId,
            'from'    => $fromState,
            'to'      => $toState,
            'trigger' => $triggerType,
            'meta'    => $metadata ? json_encode($metadata) : null,
        ]);
    }
}
