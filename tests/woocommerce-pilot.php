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
    public float $total = 0;
    public float $refunded = 0;
    public bool $paid = false;
    public ?DateTimeImmutable $paidAt = null;

    public function get_total(): float { return $this->total; }
    public function get_total_refunded(): float { return $this->refunded; }
    public function is_paid(): bool { return $this->paid; }
    public function get_date_paid(): ?DateTimeImmutable { return $this->paidAt; }
    public function get_payment_method(): string { return 'stripe'; }
    public function get_id(): int { return 42; }
    public function get_meta(string $key): array { return $this->state; }

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

function add_action(string $hook, callable $callback, int $priority = 10, int $args = 1): void
{
    $GLOBALS['hooks'][$hook] = $callback;
}

function wc_get_order(int $id): WC_Order
{
    check($id === 42, 'unexpected order ID');
    return $GLOBALS['order'];
}

function as_enqueue_async_action(string $hook, array $args, string $group, bool $unique): void
{
    check($unique && $args === [42], 'paid order must enqueue a unique action');
    $GLOBALS['queued'][$hook] = $args;
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
    if ($call['path'] === '/api/v1/signature-requests') {
        $payload = json_decode($options['body'], true, 512, JSON_THROW_ON_ERROR);
        check($payload['signature_level'] === 'aes' && $payload['page'] === 1
            && $payload['x'] === 50 && $payload['y'] === 50, 'legacy order must keep the default placement');
    }
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

$order->state = [
    'timing' => 'after_payment',
    'phase' => 'upload',
    'completed' => false,
    'signer_email' => $signer,
    'agreement_hash' => hash('sha256', '%PDF-mock agreement'),
];
$order->total = 10;
$queued = [];
$hooks['woocommerce_checkout_order_processed'](42, [], $order);
check($queued === [], 'unpaid checkout enqueued a signature');
check(!advance($order, $order->state), 'unpaid order should not reach BlockSign');

$order->paid = true;
$hooks['woocommerce_order_status_changed'](42);
check($queued === [], 'paid status without a payment date enqueued a signature');
$order->paidAt = new DateTimeImmutable();
$hooks['woocommerce_payment_complete'](42);
check(count($queued) === 1, 'confirmed payment did not enqueue the signature');
check(!download_allowed($order, $order->state), 'payment alone released the download');
$order->state['completed'] = true;
check(download_allowed($order, $order->state), 'paid and signed download remains locked');
$order->refunded = 1;
check(!download_allowed($order, $order->state), 'a refund did not revoke the download');
echo "PASS WooCommerce payment and download gates\n";
