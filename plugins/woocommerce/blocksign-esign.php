<?php
/**
 * Plugin Name: BlockSign eSign for WooCommerce
 * Description: Release a free virtual product after BlockSign confirms its signature.
 * Version: 0.1.0
 * Requires PHP: 8.1
 * Requires Plugins: woocommerce
 * License: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace BlockSign\WooCommerce;

use BlockSign\ESign\Client;
use BlockSign\ESign\Gate;
use BlockSign\ESign\SignatureSettings;
use BlockSign\ESign\SignatureWorkflow;
use InvalidArgumentException;
use RuntimeException;
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

const META = '_blocksign_esign';
const JOB = 'blocksign_esign_check';
const GROUP = 'blocksign-esign';

function configured_product(): int
{
    return absint(get_option('blocksign_esign_product', 0));
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
    register_setting('blocksign_esign', 'blocksign_esign_product', [
        'type' => 'integer',
        'sanitize_callback' => 'absint',
    ]);
});

add_action('admin_menu', static function (): void {
    add_options_page('BlockSign eSign', 'BlockSign eSign', 'manage_options', 'blocksign-esign', static function (): void {
        echo '<div class="wrap"><h1>BlockSign eSign</h1><form action="options.php" method="post">';
        settings_fields('blocksign_esign');
        printf('<label for="blocksign_esign_product">Protected product ID</label> <input id="blocksign_esign_product" type="number" min="0" name="blocksign_esign_product" value="%d">', configured_product());
        $settings = signature_settings();
        echo '<p><label for="blocksign-signature-level">Signature level</label> <select id="blocksign-signature-level" name="blocksign_esign_signature[signature_level]">';
        foreach (['aes' => 'EES', 'fes' => 'FES', 'qes' => 'QES'] as $value => $label) {
            printf('<option value="%s"%s>%s</option>', esc_attr($value), selected($settings['signature_level'], $value, false), esc_html($label));
        }
        echo '</select></p>';
        foreach (['page' => 'PDF page', 'x' => 'X from left', 'y' => 'Y from top'] as $key => $label) {
            printf('<p><label for="blocksign-%1$s">%2$s</label> <input id="blocksign-%1$s" name="blocksign_esign_signature[%1$s]" type="number" min="%3$d" step="%4$s" value="%5$s" required></p>',
                esc_attr($key), esc_html($label), $key === 'page' ? 1 : 0, $key === 'page' ? '1' : 'any', esc_attr((string) $settings[$key]));
        }
        submit_button();
        echo '</form></div>';
    });
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

function snapshot(WC_Order $order): void
{
    $existing = $order->get_meta(META);
    $productId = $existing['product_id'] ?? configured_product();
    $items = $order->get_items();
    foreach ($items as $item) {
        if (!$productId || $item->get_product_id() !== $productId) {
            continue;
        }

        $product = $item->get_product();
        if (!$product instanceof WC_Product || !$product->is_type('simple') || !$product->is_virtual()
            || $product->is_downloadable() || count($items) !== 1 || $item->get_quantity() !== 1
            || (float) $order->get_total() !== 0.0 || !is_email($order->get_billing_email())) {
            throw new RuntimeException('The protected product must be ordered alone as a free virtual item with a valid email address.');
        }

        if ($existing) {
            if (strcasecmp($existing['signer_email'], $order->get_billing_email()) !== 0) {
                throw new RuntimeException('The protected order email cannot be changed.');
            }
            return;
        }

        if (!defined('BLOCKSIGN_ESIGN_API_KEY') || !BLOCKSIGN_ESIGN_API_KEY) {
            throw new RuntimeException('BlockSign is not configured.');
        }

        $agreement = hash_file('sha256', asset('agreement.pdf'));
        $download = hash_file('sha256', asset('download.pdf'));
        if (!$agreement || !$download) {
            throw new RuntimeException('BlockSign private files could not be read.');
        }

        $order->update_meta_data(META, [
            'product_id' => $productId,
            'agreement_hash' => $agreement,
            'download_hash' => $download,
            'signer_email' => $order->get_billing_email(),
            'phase' => 'upload',
            'completed' => false,
            'placement' => signature_settings(),
        ]);
        return;
    }
    if ($existing) {
        throw new RuntimeException('The protected order product cannot be changed.');
    }
}

add_action('woocommerce_checkout_create_order', __NAMESPACE__ . '\\snapshot');
add_action('woocommerce_store_api_checkout_order_processed', static function (WC_Order $order): void {
    try {
        snapshot($order);
        $order->save_meta_data();
        enqueue($order);
    } catch (RuntimeException $error) {
        throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException(
            'blocksign_checkout', $error->getMessage(), 400
        );
    }
});

function enqueue(WC_Order $order): void
{
    if ($order->get_meta(META)) {
        as_enqueue_async_action(JOB, [$order->get_id()], GROUP, true);
    }
}

add_action('woocommerce_checkout_order_processed', static function (int $id, array $data, WC_Order $order): void {
    enqueue($order);
}, 10, 3);

function client(): Client
{
    return new Client(static function (string $method, string $path, string $type, string $body): array {
        $response = wp_remote_request('https://blocksign.io' . $path, [
            'method' => $method,
            'headers' => [
                'Authorization' => 'Bearer ' . BLOCKSIGN_ESIGN_API_KEY,
                'Content-Type' => $type,
                'Accept' => 'application/json',
            ],
            'body' => $body,
            'timeout' => 25,
            'redirection' => 0,
            'limit_response_size' => 1048576,
        ]);
        $code = wp_remote_retrieve_response_code($response);
        if (is_wp_error($response) || $code < 200 || $code >= 300) {
            throw new RuntimeException('BlockSign API request failed.', (int) $code);
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
    return (new SignatureWorkflow(client()))->advance(
        $state,
        static fn (array $next) => persist($order, $next),
        static fn () => file_get_contents(asset('agreement.pdf'), false, null, 0, 10 * 1024 * 1024 + 1)
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
        if (!$state || $state['completed'] || $order->has_status(['cancelled', 'refunded', 'failed'])) {
            return;
        }

        try {
            $retry = advance($order, $state);
        } catch (Throwable $error) {
            $state = $order->get_meta(META);
            $retry = !str_ends_with($state['phase'], '_pending_review');
            if (!$retry) {
                $order->add_order_note('BlockSign creation needs review before retrying.');
            }
        }

        if ($retry && time() - $order->get_date_created()->getTimestamp() < WEEK_IN_SECONDS) {
            as_schedule_single_action(time() + 120, JOB, [$id], GROUP);
        }
    } finally {
        delete_option($lock);
    }
});

function download_allowed(WC_Order $order, array $state): bool
{
    return $state && !$order->has_status(['cancelled', 'refunded', 'failed', 'checkout-draft'])
        && Gate::allows('fulfillment', [['timing' => 'without_payment', 'completed' => $state['completed']]]);
}

add_action('woocommerce_order_details_after_order_table', static function (WC_Order $order): void {
    $state = $order->get_meta(META);
    if (!$state) {
        return;
    }
    if (!download_allowed($order, $state)) {
        echo '<p>BlockSign: the download is locked until your signature is confirmed.</p>';
        return;
    }
    $url = add_query_arg([
        'action' => 'blocksign_esign_download',
        'order' => $order->get_id(),
        'key' => $order->get_order_key(),
    ], admin_url('admin-post.php'));
    printf('<p><a href="%s">Download PDF</a></p>', esc_url($url));
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

    try {
        $file = asset('download.pdf');
        $stream = fopen($file, 'rb');
        if ($stream === false) {
            throw new RuntimeException('Download could not be opened.');
        }
        $hash = hash_init('sha256');
        hash_update_stream($hash, $stream);
        if (!hash_equals($state['download_hash'], hash_final($hash)) || !rewind($stream)) {
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
