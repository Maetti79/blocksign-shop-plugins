<?php

declare(strict_types=1);

require __DIR__ . '/../packages/php/src/Gate.php';

$examples = json_decode(file_get_contents(__DIR__ . '/gate.json'), true, 512, JSON_THROW_ON_ERROR);
foreach ($examples as $example) {
    $result = \BlockSign\ESign\Gate::allows($example['stage'], $example['requirements']);
    if ($result !== $example['allowed']) {
        throw new RuntimeException($example['name']);
    }
    echo 'PASS ' . $example['name'] . PHP_EOL;
}
