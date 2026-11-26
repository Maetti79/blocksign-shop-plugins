<?php

declare(strict_types=1);

namespace BlockSign\Shopware\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

final class Migration1764000000BlockSignJobs extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1764000000;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement('CREATE TABLE IF NOT EXISTS `blocksign_esign_job` (
            `id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
            `order_id` BINARY(16) DEFAULT NULL,
            `proof` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
            `state` MEDIUMTEXT NOT NULL,
            `next_run` INT UNSIGNED NOT NULL,
            `expires` INT UNSIGNED NOT NULL,
            UNIQUE KEY `blocksign_order` (`order_id`),
            UNIQUE KEY `blocksign_proof` (`proof`),
            KEY `blocksign_due` (`next_run`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    }
}
