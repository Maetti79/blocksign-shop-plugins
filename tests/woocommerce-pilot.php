<?php

declare(strict_types=1);

use function BlockSign\WooCommerce\advance;
use function BlockSign\WooCommerce\download_allowed;

define('ABSPATH', __DIR__);
define('BLOCKSIGN_ESIGN_API_KEY', 'fixture-only');
define('BLOCKSIGN_ESIGN_FILES', sys_get_temp_dir() . '/blocksign-test-' . bin2hex(random_bytes(8)));
define('PLUGIN_PATH', $argv[1] ?? __DIR__ . '/../plugins/woocommerce');
mkdir(BLOCKSIGN_ESIGN_FILES, 0700);
file_put_contents(BLOCKSIGN_ESIGN_FILES . '/agreement.pdf', '%PDF-mock agreement');

register_shutdown_function(static function (): void {
    unlink(BLOCKSIGN_ESIGN_FILES . '/agreement.pdf');
    rmdir(BLOCKSIGN_ESIGN_FILES);
});

class WC_Order
{
    public function __construct(public array $state)
    {
    }

    public function has_status(array $statuses): bool
    {
        return false;
    }

    public function update_meta_data(string $key, array $value): void
    {
        $this->state = $value;
    }

    public function save_meta_data(): void
    {
    }
}

function add_action(...$args): void
{
}

function is_wp_error(array $response): bool
{
    return false;
}

function wp_remote_retrieve_body(array $response): string
{
    return json_encode($response['body'], JSON_THROW_ON_ERROR);
}

function wp_remote_retrieve_response_code(array $response): int
{
    return $response['code'];
}

function wp_remote_request(string $url, array $options): array
{
    $call = array_shift($GLOBALS['responses']);
    check($call !== null && $url === 'https://blocksign.io' . $call['path'], 'unexpected API URL');
    check($options['method'] === $call['method'], 'unexpected method');
    check($options['headers']['Authorization'] === 'Bearer fixture-only', 'missing authentication');
    return $call;
}

function check(bool $result, string $label): void
{
    if (!$result) {
        throw new RuntimeException($label);
    }
}

require PLUGIN_PATH . '/blocksign-esign.php';

$documentId = '11111111-1111-1111-1111-111111111111';
$requestId = '22222222-2222-2222-2222-222222222222';
$signer = 'customer@example.com';
$documents = '/api/v1/documents';
$requests = '/api/v1/signature-requests';
$request = "$requests/$requestId";
$pending = ['request_id' => $requestId, 'document_id' => $documentId, 'completed' => false];
$complete = array_replace($pending, [
    'completed' => true,
    'signers' => [['email' => $signer, 'signed_at' => '2026-11-26T00:00:00Z']],
]);

$responses = [
    ['method' => 'POST', 'path' => $documents, 'code' => 202, 'body' => ['document_id' => $documentId]],
    ['method' => 'POST', 'path' => $requests, 'code' => 409, 'body' => []],
    ['method' => 'POST', 'path' => $requests, 'code' => 201, 'body' => ['request_id' => $requestId]],
    ['method' => 'POST', 'path' => "$request/send", 'code' => 200, 'body' => ['sent_at' => '2026-11-26T00:00:00Z']],
    ['method' => 'GET', 'path' => $request, 'code' => 200, 'body' => $pending],
    ['method' => 'GET', 'path' => $request, 'code' => 200, 'body' => $complete],
];
$order = new WC_Order([
    'phase' => 'upload',
    'completed' => false,
    'signer_email' => $signer,
    'agreement_hash' => hash('sha256', '%PDF-mock agreement'),
]);

check(!download_allowed($order, $order->state), 'download unlocked before upload');
check(advance($order, $order->state), 'document processing should retry');
check($order->state['phase'] === 'prepare', 'retry should keep the uploaded document');
check(advance($order, $order->state), 'pending signature should retry');
check(!download_allowed($order, $order->state), 'download unlocked before signature');
check(!advance($order, $order->state), 'completed signature should stop polling');
check(download_allowed($order, $order->state), 'signed download should unlock');
check($responses === [], 'unused API responses');
echo "PASS WooCommerce pilot with mocked BlockSign API\n";
