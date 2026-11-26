<?php

declare(strict_types=1);

use BlockSign\ESign\Client;
use BlockSign\ESign\EvidenceArchive;

foreach (['Client', 'RequiredSignatures', 'EvidenceArchive'] as $class) {
    require __DIR__ . '/../packages/php/src/' . $class . '.php';
}

function check(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
}

$directory = sys_get_temp_dir() . '/blocksign-evidence-' . bin2hex(random_bytes(8));
mkdir($directory, 0700);
$first = '11111111-1111-1111-1111-111111111111';
$second = '22222222-2222-2222-2222-222222222222';
$key = 'woocommerce-1-42';
$folder = "$directory/$key";
$pdf = "%PDF-1.4\nfixture\n%%EOF\n";
$calls = [];
$invalidAudit = true;
$archive = new EvidenceArchive(new Client(static function (string $method, string $path, string $type, string $body) use (&$calls, &$invalidAudit, $pdf, $first, $second): array|string {
    $calls[] = $path;
    check($method === 'GET', 'evidence must be read-only');
    $parts = explode('/', $path);
    $request = $parts[4];
    check(in_array($request, [$first, $second], true), 'unexpected request ID');
    if ($parts[5] === 'document') {
        check($type === 'application/pdf', 'signed PDF must request binary content');
        return $pdf;
    }
    check($parts[5] === 'audit-trail', 'unexpected audit endpoint');
    return ['request_id' => $invalidAudit ? $second : $request, 'signatures' => []];
}));

$state = ['documents' => [
    ['completed' => true, 'request_id' => $first],
    ['completed' => false, 'request_id' => $second],
]];

try {
    check(!$archive->storeNext($directory, __DIR__, $key, $state) && !$calls, 'pending order was archived');
    $state['documents'][1]['completed'] = true;
    try {
        $archive->storeNext($directory, $directory, $key, $state);
        check(false, 'archive path inside web root was accepted');
    } catch (RuntimeException $error) {
        check(str_contains($error->getMessage(), 'outside of the web root'), 'unexpected web root error');
    }
    try {
        $archive->storeNext($directory, __DIR__, $key, $state);
        check(false, 'mismatched audit response was accepted');
    } catch (RuntimeException $error) {
        check(str_contains($error->getMessage(), 'does not match'), 'unexpected audit error');
    }
    check(file_get_contents("$folder/$first.pdf") === $pdf, 'signed PDF missing after audit failure');
    check(!file_exists("$folder/$first.audit.json"), 'invalid audit persisted');
    check((fileperms("$folder/$first.pdf") & 0777) === 0600, 'signed PDF permissions are too broad');
    $invalidAudit = false;
    check($archive->storeNext($directory, __DIR__, $key, $state), 'second document should be queued');
    check(str_ends_with(end($calls), '/audit-trail') && count($calls) === 3, 'retry fetched an existing signed PDF');
    check(!$archive->storeNext($directory, __DIR__, $key, $state), 'finished archive should stop retrying');
    check(is_file("$folder/$second.pdf") && is_file("$folder/$second.audit.json"), 'second evidence set was not stored');
    check(!$archive->storeNext($directory, __DIR__, $key, $state) && count($calls) === 5, 'completed archive downloaded twice');
    echo "PASS private evidence archive, audit validation and retries\n";
} finally {
    foreach (glob("$folder/*") as $file) {
        unlink($file);
    }
    if (is_dir($folder)) {
        rmdir($folder);
    }
    rmdir($directory);
}
