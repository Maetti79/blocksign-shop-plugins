<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__, 2) . '/config/config.inc.php';

try {
    require_once __DIR__ . '/blocksignesign.php';
    BlocksignEsign::processQueue();
} catch (Throwable $error) {
    fwrite(STDERR, "BlockSign worker failed. Check the API key, database and PrestaShop logs.\n");
    exit(1);
}
