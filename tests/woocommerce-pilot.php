<?php

declare(strict_types=1);

use function BlockSign\WooCommerce\advance;
use function BlockSign\WooCommerce\download_allowed;

define('ABSPATH', __DIR__);
define('WEEK_IN_SECONDS', 604800);
define('BLOCKSIGN_ESIGN_API_KEY', 'fixture-only');
define('BLOCKSIGN_ESIGN_FILES', sys_get_temp_dir() . '/blocksign-test-' . bin2hex(random_bytes(8)));
define('PLUGIN_PATH', $argv[1] ?? __DIR__ . '/../plugins/woocommerce');
mkdir(BLOCKSIGN_ESIGN_FILES, 0700);
file_put_contents(BLOCKSIGN_ESIGN_FILES . '/agreement.pdf', '%PDF-mock agreement');
file_put_contents(BLOCKSIGN_ESIGN_FILES . '/download.pdf', '%PDF-mock download');

register_shutdown_function(static function (): void {
    unlink(BLOCKSIGN_ESIGN_FILES . '/agreement.pdf');
    unlink(BLOCKSIGN_ESIGN_FILES . '/download.pdf');
    unlink(BLOCKSIGN_ESIGN_FILES . '/custom-agreement.pdf');
    unlink(BLOCKSIGN_ESIGN_FILES . '/custom-download.pdf');
    if (file_exists(BLOCKSIGN_ESIGN_FILES . '/category-terms.pdf')) {
        unlink(BLOCKSIGN_ESIGN_FILES . '/category-terms.pdf');
    }
    rmdir(BLOCKSIGN_ESIGN_FILES);
});

class WC_Order
{
    public float $total = 0;
    public float $refunded = 0;
    public bool $paid = false;
    public string $email = 'customer@example.com';
    public ?DateTimeImmutable $paidAt = null;

    public function get_total(): float { return $this->total; }
    public function get_total_refunded(): float { return $this->refunded; }
    public function is_paid(): bool { return $this->paid; }
    public function get_date_paid(): ?DateTimeImmutable { return $this->paidAt; }
    public function get_payment_method(): string { return $this->method; }
    public function get_id(): int { return 42; }
    public function needs_payment(): bool { return $this->total > 0 && !$this->paid; }
    public function get_billing_email(): string { return $this->email; }
    public function get_checkout_order_received_url(): string { return 'https://shop.example/received/42'; }
    public function get_checkout_payment_url(): string { return 'https://shop.example/pay/42'; }
    public array $items = [];
    public function get_items(): array { return $this->items ?: [new WC_Order_Item()]; }
    public string $method = 'stripe';
    public function set_payment_method(string $method): void { $this->method = $method; }
    public function set_billing_email(string $email): void { $this->email = $email; }
    public function set_total(float $total): void { $this->total = $total; }
    public function add_item(WC_Order_Item $item): void { $this->items[] = $item; }
    public function get_meta(string $key): array { return $this->state; }

    public function __construct(public array $state = [])
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

    public function delete_meta_data(string $key): void { $this->state = []; }

    public function save_meta_data(): void
    {
    }

    public function save(): int
    {
        $GLOBALS['saved'] = $this->state;
        return $this->get_id();
    }
}

class WC_Product
{
    public function is_type(string $type): bool { return $type === 'simple'; }
    public function __construct(private bool $virtual = true) {}
    public function is_virtual(): bool { return $this->virtual; }
    public static bool $downloadable = false;
    public static array $metadata = [];
    public function get_meta(string $key): string { return self::$metadata[$key] ?? ''; }
    public function update_meta_data(string $key, string $value): void { self::$metadata[$key] = $value; }
    public function delete_meta_data(string $key): void { unset(self::$metadata[$key]); }
    public function is_downloadable(): bool { return self::$downloadable; }
    public function get_category_ids(): array { return [21]; }
}

class WC_Order_Item
{
    public function __construct(private int $productId = 7, private bool $virtual = true) {}
    public function get_product_id(): int { return $this->productId; }
    public function get_variation_id(): int { return 0; }
    public function get_quantity(): int { return 1; }
    public function get_product(): WC_Product { return new WC_Product($this->virtual); }
}

class WC_Order_Item_Product extends WC_Order_Item
{
    private WC_Product $product;
    private int $quantity;
    public function set_product(WC_Product $product): void { $this->product = $product; }
    public function set_quantity(int $quantity): void { $this->quantity = $quantity; }
    public function get_product(): WC_Product { return $this->product; }
    public function get_quantity(): int { return $this->quantity; }
}

class WP_Error
{
    public array $messages = [];
    public function add(string $code, string $message): void { $this->messages[$code] = $message; }
    public function has_errors(): bool { return $this->messages !== []; }
}

function wp_salt(string $scheme): string { return 'fixture'; }
function wc_get_price_decimals(): int { return 2; }
function wc_format_decimal(float $value, int $decimals): string { return number_format($value, $decimals, '.', ''); }
function get_woocommerce_currency(): string { return 'EUR'; }

function add_option(string $name, int $value, string $description, bool $autoload): bool
{
    if (isset($GLOBALS['locks'][$name])) {
        return false;
    }
    return $GLOBALS['locks'][$name] = true;
}

function delete_option(string $name): void { unset($GLOBALS['locks'][$name]); }
function get_transient(string $name): array|false { return $GLOBALS['transients'][$name] ?? false; }

function set_transient(string $name, array $state, int $expiry): bool
{
    $GLOBALS['transients'][$name] = $state;
    return true;
}

function delete_transient(string $name): bool
{
    if (!isset($GLOBALS['transients'][$name])) {
        return false;
    }
    unset($GLOBALS['transients'][$name]);
    return true;
}

class CheckoutInterrupted extends RuntimeException
{
    public function __construct(public array $payload)
    {
        parent::__construct();
    }
}

class RouteError extends RuntimeException
{
    public function __construct(public string $error, string $message, int $status, public array $data = [])
    {
        parent::__construct($message, $status);
    }
}

class_alias(RouteError::class, 'Automattic\WooCommerce\StoreApi\Exceptions\RouteException');

class WP_REST_Request
{
    public function __construct(private string $method, private string $route) {}
    public function get_method(): string { return $this->method; }
    public function get_route(): string { return $this->route; }
}

class WP_REST_Response
{
    public array $headers = ['Nonce' => 'fixture'];

    public function __construct(private array $data, private int $status) {}
    public function get_status(): int { return $this->status; }
    public function get_data(): array { return $this->data; }
    public function set_data(array $data): void { $this->data = $data; }
    public function set_status(int $status): void { $this->status = $status; }
}

class FakePaymentResult
{
    public string $status = '';
    public string $redirect = '';
    public function set_status(string $status): void { $this->status = $status; }
    public function set_redirect_url(string $url): void { $this->redirect = $url; }
}

class FakeCheckoutSchema
{
    public const IDENTIFIER = 'checkout';
}

class FakeStoreApi
{
    public static function container(): object
    {
        return new class {
            public function get(string $name): self { return $this; }

            public function get_item_response(object $item): array
            {
                return ['order_id' => $item->order->get_id(), 'payment_result' => [
                    'payment_status' => $item->payment_result->status,
                    'redirect_url' => $item->payment_result->redirect,
                ]];
            }
        };
    }
}

class_alias(FakePaymentResult::class, 'Automattic\WooCommerce\StoreApi\Payments\PaymentResult');
class_alias(FakeCheckoutSchema::class, 'Automattic\WooCommerce\StoreApi\Schemas\V1\CheckoutSchema');
class_alias(FakeStoreApi::class, 'Automattic\WooCommerce\StoreApi\StoreApi');

function add_filter(string $hook, callable $callback, int $priority = 10, int $args = 1): void
{
    $GLOBALS['filters'][$hook] = $callback;
}

function has_action(string $hook, string $callback): bool
{
    return $GLOBALS['classic_details'] ?? false;
}

function get_option(string $name, mixed $default = false): mixed
{
    return match ($name) {
        'blocksign_esign_product' => 7,
        'blocksign_esign_category_documents' => $GLOBALS['category_documents'] ?? '',
        'blocksign_esign_scope' => $GLOBALS['configured_scope'] ?? $default,
        'blocksign_esign_paid_timing' => $GLOBALS['configured_timing'],
        default => $default,
    };
}

function wp_unslash(string $value): string { return stripslashes($value); }
function absint(int $value): int { return abs($value); }
function is_email(string $value): bool { return filter_var($value, FILTER_VALIDATE_EMAIL) !== false; }
function wp_doing_ajax(): bool { return true; }
function esc_url(string $value): string { return $value; }

function wp_send_json(array $payload): never
{
    throw new CheckoutInterrupted($payload);
}

function wp_die(string $message, string $title, array $options): never
{
    throw new CheckoutInterrupted(['status' => $options['response']]);
}

function WC(): object
{
    return (object) ['session' => new class {
        public function set(string $key, int $id): void
        {
            $GLOBALS['checkout_session'][$key] = $id;
        }
        public function save_data(): void
        {
        }

        public function get_customer_id(): string
        {
            return $GLOBALS['session_id'] ?? 'fixture-customer';
        }
    }, 'cart' => new class {
        public function get_cart(): array { return [['data' => new WC_Product(), 'quantity' => 1]]; }
        public function get_total(string $context): string { return '10.00'; }
    }];
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
    check($unique && ($args === [42] || ($hook === 'blocksign_esign_preorder_check' && strlen($args[0]) === 64)),
        'signature checks must enqueue a unique action');
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

$configured_timing = 'before_payment';
$queued = [];
$order = new WC_Order([]);
$order->total = 10;

try {
    $hooks['woocommerce_store_api_checkout_order_processed']($order);
    check(false, 'Checkout Blocks would call the gateway');
} catch (RouteError $error) {
    check($error->getCode() === 409 && count($queued) === 1, 'Checkout Blocks did not defer to signatures');
    check($saved['timing'] === 'before_payment' && !$order->paid && $order->method === '', 'unpaid order was not safely stored');
    $filter = $filters['rest_request_after_callbacks'];
    $data = ['code' => $error->error, 'data' => $error->data];
    $unrelated = new WP_REST_Response($data, 409);
    $filter($unrelated, [], new WP_REST_Request('POST', '/wc/store/v1/cart'));
    check($unrelated->get_status() === 409, 'cart route was incorrectly modified');

    $response = new WP_REST_Response($data, 409);
    $result = $filter($response, [], new WP_REST_Request('POST', '/wc/store/v1/checkout'));
    check($result === $response && $result->get_status() === 200 && $result->headers['Nonce'] === 'fixture', 'checkout response lost its session headers');
    check($result->get_data() === ['order_id' => 42, 'payment_result' => [
        'payment_status' => 'pending', 'redirect_url' => $order->get_checkout_order_received_url(),
    ]], 'Checkout Blocks did not receive a pending payment and the order status link');
}
ob_start();
$hooks['woocommerce_thankyou'](42);
check(str_contains(ob_get_clean(), 'Reload this page'), 'block confirmation did not show signature instructions');
$order->state['documents'][0]['completed'] = true;
ob_start();
$hooks['woocommerce_thankyou'](42);
check(str_contains(ob_get_clean(), $order->get_checkout_payment_url()), 'block confirmation did not show the payment link');
$classic_details = true;
ob_start();
$hooks['woocommerce_thankyou'](42);
check(ob_get_clean() === '', 'classic confirmation would show duplicate payment links');
echo "PASS Checkout Blocks payment deferral and confirmation\n";
$queued = [];

$order = new WC_Order([]);
$order->total = 10;
$hooks['woocommerce_checkout_create_order']($order);
check($order->state['timing'] === 'before_payment' && $order->method === '', 'classic checkout did not store the prepayment mode');
try {
    $hooks['woocommerce_checkout_order_processed'](42, [], $order);
    check(false, 'classic checkout would invoke the gateway without signatures');
} catch (CheckoutInterrupted $redirect) {
    check($redirect->payload['redirect'] === $order->get_checkout_order_received_url() && count($queued) === 1,
        'classic checkout did not schedule signing and redirect to status');
}
check(!download_allowed($order, $order->state), 'prepayment order has no download');
try {
    $hooks['woocommerce_before_pay_action']($order);
    check(false, 'direct order-pay request bypassed signing');
} catch (CheckoutInterrupted $response) {
    check($response->payload['status'] === 409, 'expected an order-pay conflict');
}

$order->state['documents'][0]['completed'] = true;
ob_start();
$hooks['woocommerce_order_details_after_order_table']($order);
check(str_contains(ob_get_clean(), $order->get_checkout_payment_url()), 'payment link missing after signature');
$hooks['woocommerce_before_pay_action']($order);
check(!download_allowed($order, $order->state), 'signature without payment released the download');
$order->email = 'changed@example.com';
try {
    $hooks['woocommerce_before_pay_action']($order);
    check(false, 'a changed signer bypassed the payment gate');
} catch (CheckoutInterrupted $response) {
    check($response->payload['status'] === 409, 'changed signer should require review');
}
$order->email = $signer;
$order->paid = true;
$order->paidAt = new DateTimeImmutable();
check(download_allowed($order, $order->state), 'signed and paid prepayment download remains locked');
echo "PASS WooCommerce classic checkout, early order-pay denial and payment resumption\n";

$configured_scope = ['mode' => 'category', 'category_id' => 22];
$order = new WC_Order([]);
$hooks['woocommerce_checkout_create_order']($order);
check($order->state === [], 'unrelated category matched');

$configured_scope = ['mode' => 'category', 'category_id' => 21];
$hooks['woocommerce_checkout_create_order']($order);
check(isset($order->state['downloads'][7]), 'configured category did not match');
$existing = $order->state;
$configured_scope['category_id'] = 22;
$hooks['woocommerce_checkout_create_order']($order);
check($order->state === $existing, 'changing scope altered an existing order');

$configured_scope = ['mode' => 'all', 'category_id' => 0];
$order = new WC_Order([]);
WC_Product::$downloadable = true;
$hooks['woocommerce_checkout_create_order']($order);
check($order->state === [], 'catalog-wide scope selected a native download');
WC_Product::$downloadable = false;
$hooks['woocommerce_checkout_create_order']($order);
check(isset($order->state['downloads'][7]), 'catalog-wide scope did not select eligible product');
echo "PASS WooCommerce product scopes\n";

$configured_scope = ['mode' => 'flagged', 'category_id' => 0];
$product = new WC_Product();
$order = new WC_Order([]);
$hooks['woocommerce_checkout_create_order']($order);
check($order->state === [], 'unmarked product was selected');

$_POST['_blocksign_esign_required'] = 'yes';
$hooks['woocommerce_admin_process_product_object']($product);
unset($_POST['_blocksign_esign_required']);
$hooks['woocommerce_admin_process_product_object']($product);
$hooks['woocommerce_checkout_create_order']($order);
check(isset($order->state['downloads'][7]), 'saved product checkbox did not select the product');
$existing = $order->state;

$_POST['_blocksign_esign_required'] = 'no';
$hooks['woocommerce_admin_process_product_object']($product);
unset($_POST['_blocksign_esign_required']);
$hooks['woocommerce_checkout_create_order']($order);
check($order->state === $existing, 'unchecking changed an existing order');
$order = new WC_Order([]);
$hooks['woocommerce_checkout_create_order']($order);
check($order->state === [], 'unchecked product was still selected');
echo "PASS WooCommerce product checkbox\n";

file_put_contents(BLOCKSIGN_ESIGN_FILES . '/custom-agreement.pdf', '%PDF custom agreement');
file_put_contents(BLOCKSIGN_ESIGN_FILES . '/custom-download.pdf', '%PDF custom download');
$configured_scope = ['mode' => 'product', 'category_id' => 0];
$_POST['_blocksign_esign_agreement_file'] = 'custom-agreement.pdf';
$_POST['_blocksign_esign_download_file'] = 'custom-download.pdf';
$hooks['woocommerce_admin_process_product_object']($product);
unset($_POST['_blocksign_esign_agreement_file'], $_POST['_blocksign_esign_download_file']);
$order = new WC_Order([]);
$hooks['woocommerce_checkout_create_order']($order);
check($order->state['documents'][0]['file'] === 'custom-agreement.pdf', 'custom agreement was not selected');
check($order->state['downloads'][7]['file'] === 'custom-download.pdf' && $order->state['downloads'][7]['hash'] === hash('sha256', '%PDF custom download'),
    'custom download was not pinned at checkout');
echo "PASS WooCommerce product-specific PDFs\n";

$configured_scope = ['mode' => 'all', 'category_id' => 0];
$order = new WC_Order([]);
$order->items = [new WC_Order_Item(7), new WC_Order_Item(8, false), new WC_Order_Item(9)];
$hooks['woocommerce_checkout_create_order']($order);
check(array_keys($order->state['downloads']) === [7, 9], 'mixed cart selected the wrong protected products');
check(count($order->state['documents']) === 1, 'shared agreement was duplicated');
check(\BlockSign\ESign\OrderFiles::download($order->state, 8) === null, 'unprotected product has a private download');
check(\BlockSign\ESign\OrderFiles::download($order->state, 0) === null, 'multiple downloads require a product ID');
echo "PASS WooCommerce mixed cart and shared-document deduplication\n";

file_put_contents(BLOCKSIGN_ESIGN_FILES . '/category-terms.pdf', '%PDF category terms');
$category_documents = '21 | category-terms.pdf | FES | 1 | 50 | 50';
$order = new WC_Order([]);
$hooks['woocommerce_checkout_create_order']($order);
check(array_column($order->state['documents'], 'file') === ['custom-agreement.pdf', 'category-terms.pdf'],
    'matching category document was not included');
echo "PASS WooCommerce category-specific documents\n";

$configured_timing = 'before_order';
$configured_scope = ['mode' => 'product', 'category_id' => 0];
$category_documents = '';
WC_Product::$metadata = [];
$queued = $transients = [];
$checkoutData = ['billing_email' => $signer, 'payment_method' => 'stripe'];
$errors = new WP_Error();
$hooks['woocommerce_after_checkout_validation']($checkoutData, $errors);
check($errors->has_errors() && count($queued) === 1 && count($transients) === 1, 'unsigned checkout was not blocked and queued');

$order = new WC_Order();
$order->total = 10;
try {
    $hooks['woocommerce_checkout_create_order']($order);
    check(false, 'unsigned proof was accepted when creating an order');
} catch (RuntimeException $error) {
    check(str_contains($error->getMessage(), 'Confirm all required'), 'unexpected checkout condition');
}
check($order->state === [], 'unsigned state leaked onto an order');

$responses = [
    ['method' => 'POST', 'path' => $documents, 'code' => 202, 'body' => ['document_id' => $documentId]],
    ['method' => 'POST', 'path' => $requests, 'code' => 201, 'body' => ['request_id' => $requestId]],
    ['method' => 'POST', 'path' => "$request/send", 'code' => 200, 'body' => ['sent_at' => '2026-11-26T00:00:00Z']],
    ['method' => 'GET', 'path' => $request, 'code' => 200, 'body' => $complete],
];
$hooks['blocksign_esign_preorder_check']($queued['blocksign_esign_preorder_check'][0]);
check($responses === [], 'preorder job did not complete the required provider workflow');

$session_id = 'another-session';
try {
    $hooks['woocommerce_checkout_create_order']($order);
    check(false, 'a different session reused proof');
} catch (RuntimeException $error) {
    check(str_contains($error->getMessage(), 'Confirm all required'), 'wrong-session proof was not explicitly rejected');
}
unset($session_id);
$errors = new WP_Error();
$hooks['woocommerce_after_checkout_validation']($checkoutData, $errors);
check(!$errors->has_errors(), 'signed checkout was still blocked');
$hooks['woocommerce_checkout_create_order']($order);
check($order->state['documents'][0]['completed'] && $transients === [], 'signed proof was not consumed exactly once');
check(!download_allowed($order, $order->state), 'before-order confirmation alone released a paid download');
$order->paid = true;
$order->paidAt = new DateTimeImmutable();
check(download_allowed($order, $order->state), 'signed and paid order did not release its download');

$another = new WC_Order();
$another->total = 10;
try {
    $hooks['woocommerce_checkout_create_order']($another);
    check(false, 'a second order reused proof');
} catch (RuntimeException $error) {
    check(str_contains($error->getMessage(), 'Confirm all required'), 'proof replay was not explicitly rejected');
}
$blockRequest = new WP_REST_Request('POST', '/wc/store/v1/checkout');
try {
    $hooks['woocommerce_store_api_checkout_update_order_from_request']($another, $blockRequest);
    check(false, 'Store API advanced an unsigned draft');
} catch (RouteError $error) {
    check($error->getCode() === 400 && $another->state === [] && count($transients) === 1,
        'Store API must queue signatures without attaching incomplete state');
}
$transient = array_key_first($transients);
$transients[$transient]['state']['documents'][0]['completed'] = true;
$hooks['woocommerce_store_api_checkout_update_order_from_request']($another, $blockRequest);
check($another->state['confirmed_total'] === '10.00' && $transients === [], 'Store API did not consume the completed proof');
$another->total = 11;
try {
    $hooks['woocommerce_store_api_checkout_order_processed']($another);
    check(false, 'Store API accepted a changed total');
} catch (RouteError $error) {
    check($error->getCode() === 400, 'Store API must reject amount changes after proof');
}
$another->total = 10;
$hooks['woocommerce_store_api_checkout_order_processed']($another);
check($another->state['documents'][0]['completed'] && !$another->paid, 'approved Store API order could not proceed');
echo "PASS WooCommerce before-order protection in classic checkout and Checkout Blocks\n";
