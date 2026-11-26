<?php

declare(strict_types=1);

namespace Plugin\blocksign_esign\Migrations;

use JTL\Plugin\Migration;
use JTL\Update\IMigration;

final class Migration20261126000000 extends Migration implements IMigration
{
    public function up()
    {
        $this->execute('CREATE TABLE IF NOT EXISTS xplugin_blocksign_esign_order (
            kBestellung INT UNSIGNED NOT NULL PRIMARY KEY,
            kKunde INT UNSIGNED NOT NULL,
            state MEDIUMTEXT NOT NULL,
            next_attempt INT UNSIGNED NOT NULL,
            INDEX pending (next_attempt),
            INDEX customer (kKunde, kBestellung)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    }

    public function down()
    {
        // Keep the signature records available for existing orders on reinstall.
    }
}
