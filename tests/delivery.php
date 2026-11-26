<?php

declare(strict_types=1);

use BlockSign\ESign\Client;
use BlockSign\ESign\EvidenceArchive;
use BlockSign\ESign\RequiredSignatures;
use BlockSign\ESign\SignatureWorkflow;

foreach (['Client', 'SignatureSettings', 'SignatureWorkflow', 'RequiredSignatures', 'EvidenceArchive'] as $class) {
    require __DIR__ . '/../packages/php/src/' . $class . '.php';
}

function check(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
}

$uuid = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';
$email = 'customer+test@example.com';
$pdf = "%PDF-1.4\nfixture\n%%EOF\n";
$statusPath = '/api/documents/signed_status?uuid=' . $uuid . '&email=customer%2Btest%40example.com';
$downloadPath = '/api/download-document?uuid=' . $uuid;
$steps = [
    ['POST', '/api/upload-document', ['uuid' => $uuid]],
    ['POST', '/api/invite-for-sign', 409],
    ['POST', '/api/invite-for-sign', ['uuid' => $uuid, 'email' => $email, 'success' => 'invitation email sent']],
    ['GET', $statusPath, 404],
    ['GET', $statusPath, ['success' => 'invitation email sent']],
    ['GET', $statusPath, ['success' => 'Document is signed']],
    ['GET', $downloadPath, $pdf],
];

$client = new Client(static function (string $method, string $path, string $type, string $body) use (&$steps, $uuid, $email, $downloadPath): array|string {
    [$expectedMethod, $expectedPath, $response] = array_shift($steps);
    check($method === $expectedMethod && $path === $expectedPath, 'unexpected proof API request');
    if ($path === '/api/invite-for-sign') {
        check(json_decode($body, true) === ['uuid' => $uuid, 'email' => $email, 'type' => 'pod'],
            'delivery request has the wrong recipient or document type');
    }
    if ($path === $downloadPath) {
        check($type === 'application/pdf', 'proof must be downloaded as PDF');
    }
    if (is_int($response)) {
        throw new RuntimeException('Simulated BlockSign error', $response);
    }
    return $response;
});

$configuration = RequiredSignatures::parseAdditional('receipt.pdf | POD');
$state = [
    'signer_email' => $email,
    'documents' => [[
        'phase' => 'upload', 'completed' => false,
        'file' => 'receipt.pdf', 'placement' => $configuration['receipt.pdf'],
        'agreement_hash' => hash('sha256', $pdf),
    ]],
];
$advance = static function () use ($client, &$state, $pdf): bool {
    return RequiredSignatures::advance(
        new SignatureWorkflow($client),
        $state,
        static function (array $next) use (&$state): void { $state = $next; },
        static fn (string $name) => $pdf
    );
};

check($advance() && $state['documents'][0]['phase'] === 'delivery_invite', 'processing should resolve without re-upload');
check($advance() && !RequiredSignatures::complete($state), 'invitation alone cannot fulfill a delivery requirement');
check($advance() && !RequiredSignatures::complete($state), 'unexpected successful message cannot fulfill delivery');
check(!$advance() && RequiredSignatures::complete($state), 'confirmed delivery did not fulfill the requirement');

$directory = sys_get_temp_dir() . '/blocksign-delivery-' . bin2hex(random_bytes(8));
mkdir($directory, 0700);
$key = 'prestashop-1-42';
$path = "$directory/$key/$uuid.delivery.pdf";
try {
    check(!(new EvidenceArchive($client))->storeNext($directory, __DIR__, $key, $state), 'a completed delivery needs no further archive runs');
    check(file_get_contents($path) === $pdf && $steps === [], 'delivery PDF was not archived');
    echo "PASS proof of delivery invitation, recipient status and archive\n";
} finally {
    if (is_file($path)) {
        unlink($path);
    }
    if (is_dir("$directory/$key")) {
        rmdir("$directory/$key");
    }
    rmdir($directory);
}
