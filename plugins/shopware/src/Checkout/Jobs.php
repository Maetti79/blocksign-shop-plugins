<?php

declare(strict_types=1);

namespace BlockSign\Shopware\Checkout;

use Doctrine\DBAL\Connection;
use Shopware\Core\Defaults;

final class Jobs
{
    public function __construct(private readonly Connection $db)
    {
    }

    public function find(string $id): ?array
    {
        $row = $this->db->fetchAssociative(
            'SELECT id, state, next_run, expires FROM blocksign_esign_job WHERE id = ? AND (order_id IS NOT NULL OR expires > ?)',
            [$id, time()]
        );
        return $row ? $row + ['data' => json_decode($row['state'], true, 512, JSON_THROW_ON_ERROR)] : null;
    }

    public function enqueue(string $id, array $state, ?string $proof = null): void
    {
        $order = strlen($id) === 32;
        $now = time();
        if (!$order) {
            $this->db->executeStatement('DELETE FROM blocksign_esign_job WHERE id = ? AND expires <= ?', [$id, $now]);
            $state['proof_nonce'] = bin2hex(random_bytes(32));
        }
        $this->db->executeStatement(
            'INSERT INTO blocksign_esign_job (id, order_id, proof, state, next_run, expires)
             VALUES (?, UNHEX(?), ?, ?, ?, ?) ON DUPLICATE KEY UPDATE id = id',
            [$id, $order ? $id : null, $proof, json_encode($state, JSON_THROW_ON_ERROR), $now, $now + 604800]
        );
        if (!$this->find($id)) {
            throw new \RuntimeException('This BlockSign confirmation was already used.');
        }
    }

    public function expireProof(string $id): void
    {
        $this->db->executeStatement('UPDATE blocksign_esign_job SET expires = 0, next_run = 0 WHERE id = ? AND order_id IS NULL', [$id]);
    }

    public function save(string $id, array $state, int $next): void
    {
        $json = json_encode($state, JSON_THROW_ON_ERROR);
        if (isset($state['proof_nonce'])) {
            $this->db->executeStatement(
                "UPDATE blocksign_esign_job SET state = ?, next_run = ?
                 WHERE id = ? AND expires > ? AND JSON_UNQUOTE(JSON_EXTRACT(state, '$.proof_nonce')) = ?",
                [$json, $next, $id, time(), $state['proof_nonce']]
            );
            return;
        }
        $this->db->update('blocksign_esign_job', ['state' => $json, 'next_run' => $next], ['id' => $id]);
    }

    public function due(): array
    {
        $now = time();
        $this->db->executeStatement(
            'DELETE j FROM blocksign_esign_job j LEFT JOIN `order` o ON o.id = j.order_id AND o.version_id = UNHEX(?)
             WHERE j.expires <= ? AND o.id IS NULL',
            [Defaults::LIVE_VERSION, $now]
        );
        return $this->db->fetchFirstColumn(
            'SELECT j.id FROM blocksign_esign_job j LEFT JOIN `order` o ON o.id = j.order_id AND o.version_id = UNHEX(?)
             WHERE j.next_run > 0 AND j.next_run <= ? AND (o.id IS NOT NULL OR (j.order_id IS NULL AND j.expires > ?))
             ORDER BY j.next_run LIMIT 20',
            [Defaults::LIVE_VERSION, $now, $now]
        );
    }

    public function lock(): bool
    {
        return (int) $this->db->fetchOne("SELECT GET_LOCK(CONCAT('blocksign-', LEFT(SHA2(DATABASE(), 256), 32)), 0)") === 1;
    }

    public function unlock(): void
    {
        $this->db->fetchOne("SELECT RELEASE_LOCK(CONCAT('blocksign-', LEFT(SHA2(DATABASE(), 256), 32)))");
    }
}
