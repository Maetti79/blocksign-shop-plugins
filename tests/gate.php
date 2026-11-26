<?php

declare(strict_types=1);

require __DIR__ . '/../packages/php/src/Gate.php';
require __DIR__ . '/../packages/php/src/ShopConfiguration.php';

use BlockSign\ESign\ShopConfiguration;

$examples = json_decode(file_get_contents(__DIR__ . '/gate.json'), true, 512, JSON_THROW_ON_ERROR);
foreach ($examples as $example) {
    $result = \BlockSign\ESign\Gate::allows($example['stage'], $example['requirements']);
    if ($result !== $example['allowed']) {
        throw new RuntimeException($example['name']);
    }
    echo 'PASS ' . $example['name'] . PHP_EOL;
}

$directory = realpath(sys_get_temp_dir());
$credentials = ShopConfiguration::credentials(['key' => '', 'directory' => $directory], 'saved-key', [__DIR__]);
if ($credentials !== ['key' => 'saved-key', 'directory' => $directory]
    || ShopConfiguration::credentials(['remove_key' => '1'], 'saved-key', [__DIR__])['key'] !== ''
    || ShopConfiguration::credentials(['key' => 'new-key'], 'saved-key', [__DIR__])['key'] !== 'new-key') {
    throw new RuntimeException('BlockSign credential updates did not retain, remove or replace the key.');
}
foreach ([['directory' => __DIR__], ['directory' => '..'], ['key' => "bad-key\r\nHeader: value"]] as $input) {
    try {
        ShopConfiguration::credentials($input, 'saved-key', [dirname(__DIR__)]);
        throw new RuntimeException('Invalid BlockSign connection settings were accepted.');
    } catch (InvalidArgumentException) {
    }
}
echo 'PASS private PDF directory and write-only key updates' . PHP_EOL;
