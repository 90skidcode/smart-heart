<?php

declare(strict_types=1);

namespace SmartHeart\Infra;

use PDO;

/**
 * Appends to the hash-chained audit_log: row_hash = sha256(prev_hash || canonical JSON of the row).
 * The chain head row is locked for the rest of the caller's transaction, so concurrent writers queue
 * instead of forking the chain. Call inside a transaction; one is opened (and committed) if none is.
 */
final readonly class AuditLog
{
    public function __construct(private PDO $db, private Clock $clock)
    {
    }

    /**
     * @param array{type: 'user'|'participant'|'caregiver'|'system', id: ?int} $actor
     * @param array{request_id?: ?string, ip?: ?string, user_agent?: ?string} $context
     */
    public function record(
        array $actor,
        string $action,
        ?string $entityTable = null,
        ?int $entityId = null,
        ?int $participantId = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?string $reason = null,
        array $context = [],
    ): void {
        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction) {
            $this->db->beginTransaction();
        }
        try {
            $prev = (string) $this->db->query('SELECT last_hash FROM audit_chain_head WHERE id = 1 FOR UPDATE')->fetchColumn();
            $row = [
                'occurred_at' => Clock::sql($this->clock->now()),
                'actor_type' => $actor['type'],
                'actor_id' => $actor['id'],
                'action' => $action,
                'entity_table' => $entityTable,
                'entity_id' => $entityId,
                'participant_id' => $participantId,
                'old_values' => $oldValues,
                'new_values' => $newValues,
                'reason' => $reason,
                'request_id' => $context['request_id'] ?? null,
            ];
            $hash = hash('sha256', $prev . self::canonical($row));

            $this->db->prepare('INSERT INTO audit_log (occurred_at, actor_type, actor_id, action, entity_table, entity_id,
                    participant_id, old_values, new_values, reason, request_id, ip, user_agent, prev_hash, row_hash)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([
                $row['occurred_at'], $row['actor_type'], $row['actor_id'], $action, $entityTable, $entityId,
                $participantId,
                $oldValues === null ? null : self::canonical($oldValues),
                $newValues === null ? null : self::canonical($newValues),
                $reason, $row['request_id'],
                isset($context['ip']) ? (@inet_pton($context['ip']) ?: null) : null,
                isset($context['user_agent']) ? substr($context['user_agent'], 0, 255) : null,
                $prev, $hash,
            ]);
            $this->db->prepare('UPDATE audit_chain_head SET last_id = ?, last_hash = ? WHERE id = 1')
                ->execute([(int) $this->db->lastInsertId(), $hash]);

            if ($ownTransaction) {
                $this->db->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /** Stable JSON: keys sorted at every level, so the same row always hashes the same. */
    public static function canonical(array $value): string
    {
        $sort = static function (mixed $v) use (&$sort): mixed {
            if (!is_array($v)) {
                return $v;
            }
            if (!array_is_list($v)) {
                ksort($v, SORT_STRING);
            }
            return array_map($sort, $v);
        };
        return json_encode($sort($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }
}
