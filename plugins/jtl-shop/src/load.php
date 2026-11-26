<?php

declare(strict_types=1);

foreach (['Client', 'Gate', 'SignatureSettings', 'SignatureWorkflow', 'RequiredSignatures', 'ProductFiles',
    'ShopConfiguration', 'EvidenceArchive', 'OrderFiles'] as $class) {
    require_once __DIR__ . '/../core/' . $class . '.php';
}

require_once __DIR__ . '/Settings.php';
require_once __DIR__ . '/Orders.php';
require_once __DIR__ . '/Worker.php';
