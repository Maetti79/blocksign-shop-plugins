<?php
/**
 * Plugin Name: BlockSign eSign for WooCommerce
 * Description: Release a free virtual product after BlockSign confirms its signature.
 * Version: 0.13.0
 * Requires PHP: 8.1
 * Requires Plugins: woocommerce
 * License: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace BlockSign\WooCommerce;

use BlockSign\ESign\Client;
use BlockSign\ESign\Gate;
use BlockSign\ESign\EvidenceArchive;
use BlockSign\ESign\ProductScope;
use BlockSign\ESign\ProductFiles;
use BlockSign\ESign\OrderFiles;
use BlockSign\ESign\CategoryDocuments;
use BlockSign\ESign\RequiredSignatures;
use BlockSign\ESign\SignatureSettings;
use BlockSign\ESign\SignatureWorkflow;
use InvalidArgumentException;
use RuntimeException;
use Automattic\WooCommerce\StoreApi\Exceptions\RouteException;
use Automattic\WooCommerce\StoreApi\Payments\PaymentResult;
use Automattic\WooCommerce\StoreApi\SchemaController;
use Automattic\WooCommerce\StoreApi\Schemas\V1\CheckoutSchema;
use Automattic\WooCommerce\StoreApi\StoreApi;
use Throwable;
use WC_Order;
use WC_Product;

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/core/Gate.php';
require_once __DIR__ . '/core/Client.php';
require_once __DIR__ . '/core/SignatureSettings.php';
require_once __DIR__ . '/core/SignatureWorkflow.php';
require_once __DIR__ . '/core/RequiredSignatures.php';
require_once __DIR__ . '/core/ProductScope.php';
require_once __DIR__ . '/core/ProductFiles.php';
require_once __DIR__ . '/core/OrderFiles.php';
require_once __DIR__ . '/core/CategoryDocuments.php';
require_once __DIR__ . '/core/EvidenceArchive.php';

const META = '_blocksign_esign';
const JOB = 'blocksign_esign_check';
const GROUP = 'blocksign-esign';
const PRODUCT_FLAG = '_blocksign_esign_required';
const CHECKOUT_DEFERRED = 'blocksign_esign_payment_deferred';
const PREORDER_JOB = 'blocksign_esign_preorder_check';
const PREORDER_NOTICE = 'Check your email for BlockSign. After confirming all documents, submit the checkout again.';

function configured_product(): int
{
    return absint(get_option('blocksign_esign_product', 0));
}

function product_scope(): array
{
    return ProductScope::validate(get_option('blocksign_esign_scope', ProductScope::DEFAULTS));
}

function paid_timing(): string
{
    $timing = get_option('blocksign_esign_paid_timing');
    return in_array($timing, ['before_order', 'before_payment'], true) ? $timing : 'after_payment';
}

function signature_settings(): array
{
    return SignatureSettings::validate(get_option('blocksign_esign_signature', SignatureSettings::DEFAULTS));
}

add_action('admin_init', static function (): void {
    register_setting('blocksign_esign', 'blocksign_esign_signature', [
        'type' => 'array',
        'sanitize_callback' => static function ($value): array {
            try {
                return SignatureSettings::validate((array) $value);
            } catch (InvalidArgumentException $error) {
                add_settings_error('blocksign_esign_signature', 'invalid_placement', $error->getMessage());
                return signature_settings();
            }
        },
    ]);
    register_setting('blocksign_esign', 'blocksign_esign_documents', [
        'type' => 'string',
        'sanitize_callback' => static function ($value): string {
            try {
                if (!is_string($value)) {
                    throw new InvalidArgumentException('Enter one PDF per line.');
                }
                RequiredSignatures::parseAdditional($value);
                return trim($value);
            } catch (InvalidArgumentException $error) {
                add_settings_error('blocksign_esign_documents', 'invalid_documents', $error->getMessage());
                return (string) get_option('blocksign_esign_documents', '');
            }
        },
    ]);
    register_setting('blocksign_esign', 'blocksign_esign_category_documents', [
        'type' => 'string',
        'sanitize_callback' => static function ($value): string {
            try {
                if (!is_string($value)) {
                    throw new InvalidArgumentException('Enter one category document per line.');
                }
                CategoryDocuments::parse($value);
                return trim($value);
            } catch (InvalidArgumentException $error) {
                add_settings_error('blocksign_esign_category_documents', 'invalid_category_documents', $error->getMessage());
                return (string) get_option('blocksign_esign_category_documents', '');
            }
        },
    ]);
    register_setting('blocksign_esign', 'blocksign_esign_scope', [
        'type' => 'array',
        'sanitize_callback' => static function ($value): array {
            try {
                return ProductScope::validate((array) $value);
            } catch (InvalidArgumentException $error) {
                add_settings_error('blocksign_esign_scope', 'invalid_scope', $error->getMessage());
                return product_scope();
            }
        },
    ]);
    register_setting('blocksign_esign', 'blocksign_esign_paid_timing', [
        'type' => 'string',
        'sanitize_callback' => static fn ($value): string => in_array($value, ['before_order', 'before_payment'], true) ? $value : 'after_payment',
    ]);
    register_setting('blocksign_esign', 'blocksign_esign_product', [
        'type' => 'integer',
        'sanitize_callback' => 'absint',
    ]);
});

add_action('admin_menu', static function (): void {
    add_options_page('BlockSign eSign', 'BlockSign eSign', 'manage_options', 'blocksign-esign', static function (): void {
        echo '<div class="wrap"><h1>BlockSign eSign</h1>';
        settings_errors();
        echo '<form action="options.php" method="post">';
        settings_fields('blocksign_esign');
        $scope = product_scope();
        echo '<p><label for="blocksign-scope">Protected products</label> <select id="blocksign-scope" name="blocksign_esign_scope[mode]">';
        foreach (['product' => 'Single product', 'category' => 'Product category', 'flagged' => 'Products marked on their edit screen', 'all' => 'All eligible virtual products'] as $value => $label) {
            printf('<option value="%s"%s>%s</option>', esc_attr($value), selected($scope['mode'], $value, false), esc_html($label));
        }
        echo '</select></p>';
        printf('<p><label for="blocksign-category">Category ID (category scope)</label> <input id="blocksign-category" type="number" min="0" name="blocksign_esign_scope[category_id]" value="%d" required></p>', $scope['category_id']);
        printf('<label for="blocksign_esign_product">Protected product ID</label> <input id="blocksign_esign_product" type="number" min="0" name="blocksign_esign_product" value="%d">', configured_product());
        printf('<p><label for="blocksign-paid-timing">Signature timing</label> <select id="blocksign-paid-timing" name="blocksign_esign_paid_timing"><option value="after_payment"%s>After payment</option><option value="before_payment"%s>Before payment</option><option value="before_order"%s>Before order</option></select></p>',
            selected(paid_timing(), 'after_payment', false), selected(paid_timing(), 'before_payment', false), selected(paid_timing(), 'before_order', false));
        $settings = signature_settings();
        echo '<p><label for="blocksign-signature-level">Signature level or proof of delivery</label> <select id="blocksign-signature-level" name="blocksign_esign_signature[signature_level]">';
        foreach (['aes' => 'EES', 'fes' => 'FES', 'qes' => 'QES', 'pod' => 'Proof of delivery'] as $value => $label) {
            printf('<option value="%s"%s>%s</option>', esc_attr($value), selected($settings['signature_level'], $value, false), esc_html($label));
        }
        echo '</select></p>';
        foreach (['page' => 'PDF page', 'x' => 'X from left', 'y' => 'Y from top'] as $key => $label) {
            printf('<p><label for="blocksign-%1$s">%2$s</label> <input id="blocksign-%1$s" name="blocksign_esign_signature[%1$s]" type="number" min="%3$d" step="%4$s" value="%5$s" required></p>',
                esc_attr($key), esc_html($label), $key === 'page' ? 1 : 0, $key === 'page' ? '1' : 'any', esc_attr((string) $settings[$key]));
        }
        printf('<p><label for="blocksign-esign-documents">Additional required PDFs</label><br><textarea id="blocksign-esign-documents" name="blocksign_esign_documents" rows="5" cols="70" aria-describedby="blocksign-documents-help">%s</textarea><br><span id="blocksign-documents-help">One private PDF per line, for example: mandate.pdf | EES | 1 | 50 | 50</span></p>',
            esc_textarea(get_option('blocksign_esign_documents', '')));
        printf('<p><label for="blocksign-category-documents">Additional PDFs by product category</label><br><textarea id="blocksign-category-documents" name="blocksign_esign_category_documents" rows="5" cols="70">%s</textarea><br>Example: 21 | mandate.pdf | EES | 1 | 50 | 50</p>',
            esc_textarea(get_option('blocksign_esign_category_documents', '')));
        submit_button();
        echo '</form></div>';
    });
});

add_action('woocommerce_product_options_general_product_data', static function (): void {
    global $product_object;
    if (!$product_object instanceof WC_Product) {
        return;
    }
    // Distinguish an unchecked field from an update made outside the full product editor.
    printf('<input type="hidden" name="%s" value="no">', esc_attr(PRODUCT_FLAG));
    woocommerce_wp_checkbox([
        'id' => PRODUCT_FLAG,
        'label' => 'Require BlockSign eSign',
        'description' => 'Applies when BlockSign is configured to use marked products.',
        'value' => $product_object->get_meta(PRODUCT_FLAG) === 'yes' ? 'yes' : 'no',
        'wrapper_class' => 'show_if_simple',
    ]);
    foreach (ProductFiles::DEFAULTS as $kind => $default) {
        $key = '_blocksign_esign_' . $kind . '_file';
        woocommerce_wp_text_input([
            'id' => $key,
            'label' => $kind === 'agreement' ? 'BlockSign primary agreement PDF' : 'BlockSign protected download PDF',
            'placeholder' => $default,
            'value' => $product_object->get_meta($key),
            'description' => 'Private PDF filename. Leave blank to use the default.',
            'wrapper_class' => 'show_if_simple',
        ]);
    }
});

add_action('woocommerce_admin_process_product_object', static function (WC_Product $product): void {
    $value = $_POST[PRODUCT_FLAG] ?? null;
    if ($value === 'yes') {
        $product->update_meta_data(PRODUCT_FLAG, 'yes');
    } elseif ($value === 'no') {
        $product->delete_meta_data(PRODUCT_FLAG);
    }
    foreach (array_keys(ProductFiles::DEFAULTS) as $kind) {
        $key = '_blocksign_esign_' . $kind . '_file';
        if (!isset($_POST[$key]) || !is_string($_POST[$key])) {
            continue;
        }
        try {
            $file = ProductFiles::validate(wp_unslash($_POST[$key]));
            $product->update_meta_data($key, $file);
        } catch (InvalidArgumentException $error) {
            \WC_Admin_Meta_Boxes::add_error($error->getMessage());
        }
    }
});

function asset(string $name): string
{
    $directory = defined('BLOCKSIGN_ESIGN_FILES') ? realpath(BLOCKSIGN_ESIGN_FILES) : false;
    $documentRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
    $root = $documentRoot !== '' ? realpath($documentRoot) : realpath(ABSPATH);
    $file = $directory ? realpath($directory . '/' . $name) : false;
    if (!$root || !$file || dirname($file) !== $directory
        || str_starts_with($file, rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)
        || !is_file($file) || !is_readable($file)) {
        throw new RuntimeException('BlockSign private file unavailable.');
    }

    return $file;
}

function snapshot(WC_Order $order, bool $preview = false): void
{
    $existing = $order->get_meta(META);
    $items = $order->get_items();
    $cart = [];
    foreach ($items as $item) {
        $cart[] = [(int) $item->get_product_id(), (int) $item->get_variation_id(), (int) $item->get_quantity()];
    }
    sort($cart);
    $total = (float) $order->get_total();

    if ($existing) {
        $previousCart = $existing['cart'] ?? [[(int) $existing['product_id'], 0, 1]];
        if ($previousCart !== $cart) {
            throw new RuntimeException('The protected order items cannot be changed.');
        }
        $timing = $existing['timing'] ?? 'without_payment';
        if ($total < 0 || ($timing !== 'before_order' && ($timing === 'without_payment') !== ($total === 0.0))
            || strcasecmp($existing['signer_email'], $order->get_billing_email()) !== 0) {
            throw new RuntimeException('The protected order payment requirement and email cannot be changed.');
        }
        if ($timing === 'before_order' && isset($existing['confirmed_total'])
            && ($existing['confirmed_total'] !== preorder_total($total)
                || $existing['confirmed_currency'] !== get_woocommerce_currency()
                || ($order->has_status(['checkout-draft']) && ($existing['confirmed_expires'] ?? 0) <= time()))) {
            throw new RuntimeException('The confirmed checkout has expired or its total or currency has changed.');
        }
        if ($timing !== 'without_payment' && $order->get_payment_method() === 'cod') {
            throw new RuntimeException('Cash on delivery is unavailable for the protected PDF.');
        }
        if ($timing === 'before_payment' && !payment_allowed($order, $existing)) {
            $order->set_payment_method('');
        }
        return;
    }

    $scope = product_scope();
    $configuredProduct = configured_product();
    $products = [];
    foreach ($items as $item) {
        $id = (int) $item->get_product_id();
        if ($scope['mode'] === 'product' && !ProductScope::matches($scope, $configuredProduct, $id)) {
            continue;
        }
        $product = $item->get_product();
        $eligible = $product instanceof WC_Product && $product->is_type('simple')
            && $product->is_virtual() && !$product->is_downloadable();
        $categories = $scope['mode'] === 'category' && $eligible ? $product->get_category_ids() : [];
        $flagged = $scope['mode'] === 'flagged' && $eligible && $product->get_meta(PRODUCT_FLAG) === 'yes';
        if ($scope['mode'] !== 'product'
            && (!$eligible || !ProductScope::matches($scope, $configuredProduct, $id, $categories, $flagged))) {
            continue;
        }
        if (!$eligible) {
            throw new RuntimeException('The BlockSign product must be a simple virtual product without a native download.');
        }
        $products[$id] = $product;
    }
    if (!$products) {
        return;
    }
    if ($total < 0 || !is_email($order->get_billing_email())) {
        throw new RuntimeException('The protected order requires a valid email address and a non-negative total.');
    }
    $configuredTiming = paid_timing();
    $timing = $total > 0 || $configuredTiming === 'before_order' ? $configuredTiming : 'without_payment';
    if ($total > 0 && $order->get_payment_method() === 'cod') {
        throw new RuntimeException('Cash on delivery is unavailable for the protected PDF.');
    }
    if (!defined('BLOCKSIGN_ESIGN_API_KEY') || !BLOCKSIGN_ESIGN_API_KEY) {
        throw new RuntimeException('BlockSign is not configured.');
    }
    $placement = signature_settings();
    $additional = RequiredSignatures::parseAdditional(get_option('blocksign_esign_documents', ''));
    $categoryDocuments = CategoryDocuments::parse(get_option('blocksign_esign_category_documents', ''));
    foreach ($products as $id => $product) {
        $products[$id] = ProductFiles::snapshot(
            [
                'agreement' => $product->get_meta('_blocksign_esign_agreement_file'),
                'download' => $product->get_meta('_blocksign_esign_download_file'),
            ],
            $placement,
            CategoryDocuments::forProduct($additional, $categoryDocuments, $categoryDocuments ? $product->get_category_ids() : []),
            asset(...)
        );
    }
    $state = OrderFiles::combine($products) + [
        'cart' => $cart,
        'timing' => $timing,
        'signer_email' => $order->get_billing_email(),
    ];
    if ($timing === 'before_order' && !$preview) {
        $state = consume_preorder($state, $total);
    }
    $order->update_meta_data(META, $state);
    if ($timing === 'before_payment') {
        // Suppress gateway instructions until the buyer actually submits the order-pay form.
        $order->set_payment_method('');
    }
}

function preorder_total(float $total): string
{
    return wc_format_decimal($total, wc_get_price_decimals());
}

function preorder_key(array $state, float $total): string
{
    $identity = json_encode([
        WC()->session->get_customer_id(),
        get_woocommerce_currency(),
        preorder_total($total),
        $state,
    ], JSON_THROW_ON_ERROR);
    return hash_hmac('sha256', $identity, wp_salt('auth'));
}

function consume_preorder(array $state, float $total): array
{
    $key = preorder_key($state, $total);
    $lock = 'bs_esign_pre_lock_' . $key;
    if (!add_option($lock, 1, '', false)) {
        throw new RuntimeException('The signature is being checked. Please try again.');
    }
    try {
        $pending = get_transient('bs_esign_pre_' . $key);
        if (!$pending || !RequiredSignatures::complete($pending['state']) || !delete_transient('bs_esign_pre_' . $key)) {
            throw new RuntimeException('Confirm all required BlockSign documents before placing the order.');
        }
        return $pending['state'] + [
            'confirmed_total' => preorder_total($total),
            'confirmed_currency' => get_woocommerce_currency(),
            'confirmed_expires' => $pending['expires'],
        ];
    } finally {
        delete_option($lock);
    }
}

function request_preorder(array $state, float $total): bool
{
    $key = preorder_key($state, $total);
    $name = 'bs_esign_pre_' . $key;
    $lock = 'bs_esign_pre_lock_' . $key;
    if (!add_option($lock, 1, '', false)) {
        return false;
    }
    try {
        $pending = get_transient($name);
        if ($pending) {
            return RequiredSignatures::complete($pending['state']);
        }
        if (!set_transient($name, ['state' => $state, 'expires' => time() + WEEK_IN_SECONDS], WEEK_IN_SECONDS)) {
            throw new RuntimeException('Could not start BlockSign signing. Please try again.');
        }
        as_enqueue_async_action(PREORDER_JOB, [$key], GROUP, true);
        return false;
    } finally {
        delete_option($lock);
    }
}

add_action('woocommerce_after_checkout_validation', static function (array $data, \WP_Error $errors): void {
    if (paid_timing() !== 'before_order' || $errors->has_errors()) {
        return;
    }
    try {
        // Build an unsaved order from the validated cart. No order or payment is created here.
        $draft = new WC_Order();
        $draft->set_billing_email($data['billing_email'] ?? '');
        $draft->set_payment_method($data['payment_method'] ?? '');
        $total = (float) WC()->cart->get_total('edit');
        $draft->set_total($total);
        foreach (WC()->cart->get_cart() as $line) {
            $item = new \WC_Order_Item_Product();
            $item->set_product($line['data']);
            $item->set_quantity($line['quantity']);
            $draft->add_item($item);
        }
        snapshot($draft, true);
        $state = $draft->get_meta(META);
        if (!$state) {
            return;
        }

        if (!request_preorder($state, $total)) {
            $errors->add('blocksign_preorder', PREORDER_NOTICE);
        }
    } catch (RuntimeException | InvalidArgumentException $error) {
        $errors->add('blocksign_preorder', $error->getMessage());
    }
}, 10, 2);

add_action(PREORDER_JOB, static function (string $key): void {
    if (!preg_match('/^[a-f0-9]{64}$/D', $key)) {
        return;
    }
    $name = 'bs_esign_pre_' . $key;
    $lock = 'bs_esign_pre_lock_' . $key;
    if (!add_option($lock, 1, '', false)) {
        as_schedule_single_action(time() + 120, PREORDER_JOB, [$key], GROUP);
        return;
    }
    try {
        $pending = get_transient($name);
        if (!$pending || $pending['expires'] <= time()) {
            return;
        }
        try {
            $retry = RequiredSignatures::advance(
                new SignatureWorkflow(client()),
                $pending['state'],
                static function (array $state) use (&$pending, $name): void {
                    $pending['state'] = $state;
                    $remaining = $pending['expires'] - time();
                    if ($remaining <= 0 || !set_transient($name, $pending, $remaining)) {
                        throw new RuntimeException('Could not save BlockSign checkout progress.');
                    }
                },
                static fn (string $file) => file_get_contents(asset($file), false, null, 0, 10 * 1024 * 1024 + 1)
            );
        } catch (Throwable $error) {
            $retry = !RequiredSignatures::needsReview($pending['state']);
        }
        if ($retry && time() + 120 < $pending['expires']) {
            as_schedule_single_action(time() + 120, PREORDER_JOB, [$key], GROUP);
        }
    } finally {
        delete_option($lock);
    }
});

add_action('woocommerce_checkout_create_order', __NAMESPACE__ . '\\snapshot');
add_action('woocommerce_store_api_checkout_update_order_from_request', static function (WC_Order $order, \WP_REST_Request $request): void {
    if ($request->get_method() !== 'POST' || paid_timing() !== 'before_order' || $order->get_meta(META)) {
        return;
    }
    try {
        snapshot($order, true);
        $state = $order->get_meta(META);
        if (!$state) {
            return;
        }

        // Only a completed proof may remain attached when WooCommerce persists this draft.
        $order->delete_meta_data(META);
        $total = (float) $order->get_total();
        if (!request_preorder($state, $total)) {
            throw new RuntimeException(PREORDER_NOTICE);
        }
        $order->update_meta_data(META, consume_preorder($state, $total));
    } catch (RuntimeException | InvalidArgumentException $error) {
        throw new RouteException('blocksign_checkout', $error->getMessage(), 400);
    }
}, 1, 2);

add_action('woocommerce_store_api_checkout_order_processed', static function (WC_Order $order): void {
    try {
        snapshot($order);
        $stored = $order->get_meta(META);
        if (($stored['timing'] ?? '') === 'before_order' && !payment_allowed($order, $stored)) {
            throw new RuntimeException(PREORDER_NOTICE);
        }
    } catch (RuntimeException | InvalidArgumentException $error) {
        throw new RouteException('blocksign_checkout', $error->getMessage(), 400);
    }

    $state = $order->get_meta(META);
    if (($state['timing'] ?? '') !== 'before_payment') {
        $order->save_meta_data();
        enqueue($order);
        return;
    }

    // Stop the Store API before it calls any gateway and keep the order payable on the native order-pay page.
    $order->set_payment_method('');
    $order->save();
    enqueue($order);
    WC()->session->set('order_awaiting_payment', $order->get_id());
    WC()->session->save_data();
    throw new RouteException(CHECKOUT_DEFERRED, 'Payment is waiting for BlockSign confirmation.', 409, ['order_id' => $order->get_id()]);
}, 1);

add_filter('rest_request_after_callbacks', static function ($response, array $handler, \WP_REST_Request $request) {
    if (!$response instanceof \WP_REST_Response || $response->get_status() !== 409
        || $request->get_method() !== 'POST' || !preg_match('~^/wc/store/v1/checkout(?:/[0-9]+)?/?$~D', $request->get_route())) {
        return $response;
    }

    $error = $response->get_data();
    if (($error['code'] ?? '') !== CHECKOUT_DEFERRED) {
        return $response;
    }
    $order = wc_get_order((int) ($error['data']['order_id'] ?? 0));
    if (!$order instanceof WC_Order || ($order->get_meta(META)['timing'] ?? '') !== 'before_payment'
        || !$order->needs_payment()) {
        return $response;
    }

    // Reuse the Store API schema and response object so checkout extensions and session headers are preserved.
    $result = new PaymentResult();
    $result->set_status('pending');
    $result->set_redirect_url($order->get_checkout_order_received_url());
    $schema = StoreApi::container()->get(SchemaController::class)->get(CheckoutSchema::IDENTIFIER);
    $data = $schema->get_item_response((object) ['order' => $order, 'payment_result' => $result]);
    $response->set_data($data);
    $response->set_status(200);
    return $response;
}, 10, 3);

function enqueue(WC_Order $order): void
{
    $state = $order->get_meta(META);
    if ($state && ((can_start_signatures($order, $state) && !RequiredSignatures::complete($state))
        || (($state['timing'] ?? '') === 'before_order' && defined('BLOCKSIGN_ESIGN_ARCHIVE') && BLOCKSIGN_ESIGN_ARCHIVE))) {
        as_enqueue_async_action(JOB, [$order->get_id()], GROUP, true);
    }
}

add_action('woocommerce_checkout_order_processed', static function (int $id, array $data, WC_Order $order): void {
    enqueue($order);
    if (($order->get_meta(META)['timing'] ?? '') !== 'before_payment') {
        return;
    }

    // WooCommerce's checkout would otherwise call the selected gateway in this request.
    WC()->session->set('order_awaiting_payment', $id);
    WC()->session->save_data();
    $statusUrl = $order->get_checkout_order_received_url();
    if (wp_doing_ajax()) {
        wp_send_json(['result' => 'success', 'redirect' => $statusUrl]);
    }
    wp_safe_redirect($statusUrl);
    exit;
}, 1, 3);

function can_start_signatures(WC_Order $order, array $state): bool
{
    if (($state['timing'] ?? '') === 'before_payment') {
        return (float) $order->get_total() > 0.0 && $order->needs_payment() && !$order->is_paid();
    }
    return payment_ready($order, $state);
}

function payment_allowed(WC_Order $order, array $state): bool
{
    return Gate::allows('payment', [[
        'timing' => $state['timing'] ?? 'without_payment',
        'completed' => RequiredSignatures::complete($state)
            && strcasecmp($state['signer_email'] ?? '', $order->get_billing_email()) === 0,
    ]]);
}

add_action('woocommerce_before_pay_action', static function (WC_Order $order): void {
    $state = $order->get_meta(META);
    if (!payment_allowed($order, (array) $state)) {
        wp_die('Confirm all required signatures and delivery confirmations before paying for this order.', '', ['response' => 409]);
    }
    if ($state && ($_POST['payment_method'] ?? '') === 'cod') {
        wp_die('Cash on delivery is unavailable for the protected PDF.', '', ['response' => 409]);
    }
}, 1);

function enqueue_paid_order(int $id): void
{
    $order = wc_get_order($id);
    if ($order && ($order->get_meta(META)['timing'] ?? '') === 'after_payment') {
        enqueue($order);
    }
}

add_action('woocommerce_payment_complete', __NAMESPACE__ . '\enqueue_paid_order');
add_action('woocommerce_order_status_changed', __NAMESPACE__ . '\enqueue_paid_order');

function payment_ready(WC_Order $order, array $state): bool
{
    $total = (float) $order->get_total();
    $timing = $state['timing'] ?? 'without_payment';
    if ($timing === 'before_order') {
        $timing = $total === 0.0 ? 'without_payment' : 'before_payment';
    }
    return match ($timing) {
        'without_payment' => $total === 0.0,
        'after_payment', 'before_payment' => $total > 0.0 && $order->get_payment_method() !== 'cod'
            && $order->is_paid() && $order->get_date_paid() !== null && (float) $order->get_total_refunded() === 0.0,
        default => false,
    };
}

function client(): Client
{
    return new Client(static function (string $method, string $path, string $type, string $body): array|string {
        $response = wp_remote_request('https://blocksign.io' . $path, [
            'method' => $method,
            'headers' => [
                'Authorization' => 'Bearer ' . BLOCKSIGN_ESIGN_API_KEY,
                'Content-Type' => $type,
                'Accept' => $type === 'application/pdf' ? $type : 'application/json',
            ],
            'body' => $body,
            'timeout' => 25,
            'redirection' => 0,
            'limit_response_size' => $type === 'application/pdf' ? Client::MAX_SIGNED_PDF_BYTES + 1 : 1048576,
        ]);
        $code = wp_remote_retrieve_response_code($response);
        if (is_wp_error($response) || $code < 200 || $code >= 300) {
            throw new RuntimeException('BlockSign API request failed.', (int) $code);
        }

        if ($type === 'application/pdf') {
            return wp_remote_retrieve_body($response);
        }
        $result = json_decode(wp_remote_retrieve_body($response), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($result)) {
            throw new RuntimeException('Unexpected BlockSign response.');
        }

        return $result;
    });
}

function persist(WC_Order $order, array $state): void
{
    $order->update_meta_data(META, $state);
    $order->save_meta_data();
}

function advance(WC_Order $order, array $state): bool
{
    if (!can_start_signatures($order, $state)) {
        return false;
    }

    return RequiredSignatures::advance(
        new SignatureWorkflow(client()),
        $state,
        static fn (array $next) => persist($order, $next),
        static fn (string $file) => file_get_contents(asset($file), false, null, 0, 10 * 1024 * 1024 + 1)
    );
}

add_action(JOB, static function (int $id): void {
    $lock = 'blocksign_esign_lock_' . $id;
    if (!add_option($lock, '1', '', false)) {
        return;
    }

    try {
        $order = wc_get_order($id);
        $state = $order ? $order->get_meta(META) : [];
        if (!$state || $order->has_status(['cancelled', 'refunded', 'failed'])) {
            return;
        }

        try {
            $retry = advance($order, $state);
            $state = $order->get_meta(META);
            if (defined('BLOCKSIGN_ESIGN_ARCHIVE') && BLOCKSIGN_ESIGN_ARCHIVE && RequiredSignatures::complete($state)) {
                try {
                    $root = ($_SERVER['DOCUMENT_ROOT'] ?? '') ?: ABSPATH;
                    $retry = (new EvidenceArchive(client()))->storeNext(
                        BLOCKSIGN_ESIGN_ARCHIVE, $root, 'woocommerce-' . get_current_blog_id() . '-' . $id, $state
                    );
                } catch (Throwable $error) {
                    wc_get_logger()->warning('BlockSign evidence could not be archived for order ' . $id, ['source' => GROUP]);
                    $retry = true;
                }
            }
        } catch (Throwable $error) {
            $state = $order->get_meta(META);
            $retry = !RequiredSignatures::needsReview($state);
            if (!$retry) {
                $order->add_order_note('BlockSign creation needs review before retrying.');
            }
        }

        $since = ($state['timing'] ?? '') === 'after_payment' ? $order->get_date_paid() : $order->get_date_created();
        if ($retry && $since && time() - $since->getTimestamp() < WEEK_IN_SECONDS) {
            as_schedule_single_action(time() + 120, JOB, [$id], GROUP);
        }
    } finally {
        delete_option($lock);
    }
});

function download_allowed(WC_Order $order, array $state): bool
{
    return $state && payment_ready($order, $state) && !$order->has_status(['cancelled', 'refunded', 'failed', 'checkout-draft'])
        && Gate::allows('fulfillment', [['timing' => $state['timing'] ?? 'without_payment', 'completed' => RequiredSignatures::complete($state)]]);
}

function show_order_state(WC_Order $order): void
{
    $state = $order->get_meta(META);
    if (!$state) {
        return;
    }
    if (($state['timing'] ?? '') === 'before_payment' && $order->needs_payment()) {
        if (payment_allowed($order, $state)) {
            printf('<p><a href="%s">Continue to payment</a></p>', esc_url($order->get_checkout_payment_url()));
        } else {
            echo '<p>BlockSign: check your email for the signature invitations. Reload this page after signing.</p>';
        }
        return;
    }
    if (!download_allowed($order, $state)) {
        echo ($state['timing'] ?? 'without_payment') !== 'without_payment' && !payment_ready($order, $state)
            ? '<p>BlockSign: the download is locked while payment is unconfirmed.</p>'
            : '<p>BlockSign: the download is locked until all required signatures and delivery confirmations are confirmed.</p>';
        return;
    }
    foreach (OrderFiles::downloads($state) as $product => $file) {
        $url = add_query_arg([
            'action' => 'blocksign_esign_download',
            'order' => $order->get_id(),
            'key' => $order->get_order_key(),
            'product' => $product,
        ], admin_url('admin-post.php'));
        printf('<p><a href="%s">Download %s</a></p>', esc_url($url), esc_html($file['file']));
    }
}

add_action('woocommerce_order_details_after_order_table', __NAMESPACE__ . '\show_order_state');

add_action('woocommerce_thankyou', static function (int $orderId): void {
    // The block confirmation template calls this hook after removing the classic order details table.
    if (!has_action('woocommerce_thankyou', 'woocommerce_order_details_table')) {
        $order = wc_get_order($orderId);
        if ($order instanceof WC_Order) {
            show_order_state($order);
        }
    }

});

function download(): void
{
    $id = isset($_GET['order']) && is_string($_GET['order']) ? absint($_GET['order']) : 0;
    $key = isset($_GET['key']) && is_string($_GET['key']) ? wp_unslash($_GET['key']) : '';
    $order = wc_get_order($id);
    if (!$order || !$key || !hash_equals($order->get_order_key(), $key)
        || ($order->get_customer_id() && $order->get_customer_id() !== get_current_user_id())) {
        wp_die('Download unavailable.', '', ['response' => 404]);
    }

    $state = $order->get_meta(META);
    if (!download_allowed($order, (array) $state)) {
        wp_die('Download unavailable.', '', ['response' => 404]);
    }

    $product = filter_var($_GET['product'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
    $download = $product === false ? null : OrderFiles::download($state, $product);
    if (!$download) {
        wp_die('Download unavailable.', '', ['response' => 404]);
    }

    try {
        $file = asset($download['file']);
        $stream = fopen($file, 'rb');
        if ($stream === false) {
            throw new RuntimeException('Download could not be opened.');
        }
        $hash = hash_init('sha256');
        hash_update_stream($hash, $stream);
        if (!hash_equals($download['hash'], hash_final($hash)) || !rewind($stream)) {
            fclose($stream);
            throw new RuntimeException('Download does not match the order.');
        }
    } catch (RuntimeException $error) {
        wp_die('Download unavailable.', '', ['response' => 404]);
    }

    nocache_headers();
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="download.pdf"');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    fpassthru($stream);
    fclose($stream);
    exit;
}

add_action('admin_post_blocksign_esign_download', __NAMESPACE__ . '\\download');
add_action('admin_post_nopriv_blocksign_esign_download', __NAMESPACE__ . '\\download');
