<?php

declare(strict_types=1);

use BlockSign\ESign\Client;
use BlockSign\ESign\SignatureWorkflow;

define('_PS_VERSION_', '9.0.0');
define('_PS_ROOT_DIR_', __DIR__);
define('_DB_PREFIX_', 'ps_');

$files = sys_get_temp_dir() . '/blocksign-presta-test-' . bin2hex(random_bytes(8));
putenv('BLOCKSIGN_ESIGN_FILES=' . $files);
mkdir($files, 0700);
file_put_contents($files . '/agreement.pdf', '%PDF agreement');
file_put_contents($files . '/download.pdf', '%PDF download');
file_put_contents($files . '/mandate.pdf', '%PDF mandate');
register_shutdown_function(static function () use ($files): void {
    unlink($files . '/agreement.pdf');
    unlink($files . '/download.pdf');
    unlink($files . '/mandate.pdf');
    unlink($files . '/custom-agreement.pdf');
    unlink($files . '/custom-download.pdf');
    if (file_exists($files . '/category-terms.pdf')) {
        unlink($files . '/category-terms.pdf');
    }
    rmdir($files);
});

#[AllowDynamicProperties]
class Module
{
    public object $context;
    public array $hooks = [];

    public function __construct()
    {
        $this->context = (object) ['shop' => (object) ['id' => 2]];
    }

    public function registerHook(array $hooks): bool
    {
        $this->hooks = $hooks;
        return true;
    }
}

class Shop
{
    public static function isFeatureActive(): bool { return false; }
}

class Configuration
{
    public static array $productFlags = [];

    public static function updateValue(string $key, int|string $value, bool $html, ?int $group, int $shop): bool
    {
        self::$productFlags[$shop][$key] = $value;
        return true;
    }

    public static function get(string $key, ...$context): int|string
    {
        if (str_starts_with($key, 'BLOCKSIGN_ESIGN_PRODUCT_FLAG_') || str_starts_with($key, 'BLOCKSIGN_ESIGN_PRODUCT_FILES_')) {
            return self::$productFlags[$context[2]][$key] ?? 0;
        }
        return match ($key) {
            'BLOCKSIGN_ESIGN_PRODUCT' => 99,
            'BLOCKSIGN_ESIGN_CATEGORY_DOCUMENTS' => $GLOBALS['category_documents'] ?? '',
            'BLOCKSIGN_ESIGN_SCOPE' => $GLOBALS['configured_scope'] ?? '',
            'BLOCKSIGN_ESIGN_DOCUMENTS' => 'mandate.pdf | FES | 3 | 25 | 40',
            'BLOCKSIGN_ESIGN_SIGNATURE' => '{"signature_level":"qes","page":2,"x":12.5,"y":0}',
            'PS_OS_CANCELED' => 6,
            'PS_OS_REFUND' => 7,
            'PS_OS_ERROR' => 8,
        };
    }
}

class Order
{
    public int $id_shop = 2;
    public int $id_lang = 1;
    public int $id_customer = 81;
    public static bool $paid = false;
    public static bool $paidOrder = false;
    public int $current_state = 2;

    public function getCurrentOrderState(): object
    {
        return (object) ['paid' => self::$paid];
    }

    public string $module = 'free_order';
    public string $total_paid_tax_incl = '0.000000';

    public function __construct(public int $id)
    {
        if (self::$paidOrder) {
            $this->module = 'ps_checkpayment';
            $this->total_paid_tax_incl = '10.000000';
        }
    }

    public array $items = [];

    public function getProductsDetail(): array
    {
        return $this->items ?: [['product_id' => '99', 'product_quantity' => '1', 'product_quantity_refunded' => '0']];
    }
}

class Product
{
    public bool $is_virtual;

    public function __construct(int $id)
    {
        $this->is_virtual = $id !== 100;
    }

    public function getCategories(): array { return ['21']; }
    public function getAttachments(int $language): array { return []; }
}

class ProductDownload
{
    public static bool $active = false;

    public static function getIdFromIdProduct(int $id): int
    {
        return self::$active ? 1 : 0;
    }
}

class Validate
{
    public static function isEmail(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    public static function isLoadedObject(object $model): bool
    {
        return $model->id > 0;
    }
}

class Db
{
    public const INSERT_IGNORE = 2;
    public static array $row = [];

    public static function getInstance(): self
    {
        return new self();
    }

    public function insert(string $table, array $data, bool $nulls, bool $cache, int $mode): bool
    {
        check($table === 'blocksign_esign' && $mode === self::INSERT_IGNORE, 'unexpected insertion');
        self::$row = self::$row ?: $data;
        return true;
    }

    public function getRow(string $sql, bool $cache): array
    {
        check(str_ends_with($sql, 'id_order = 42') && !$cache, 'unexpected state lookup');
        return self::$row;
    }

    public function update(string $table, array $data, string $where): bool
    {
        check($table === 'blocksign_esign' && $where === 'id_order = 42', 'unexpected state update');
        self::$row = array_replace(self::$row, $data);
        return true;
    }
}

function pSQL(string $value, bool $html): string
{
    return $value;
}

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

require ($argv[1] ?? 'build/blocksignesign') . '/blocksignesign.php';

$order = new Order(42);
$module = new BlocksignEsign();
$customer = (object) ['id' => 81, 'email' => 'guest@example.com'];
$order->total_paid_tax_incl = '10.000000';
$module->hookActionValidateOrderAfter(['orders' => [$order], 'customer' => $customer]);
check(Db::$row === [], 'paid order should not be registered');

Order::$paidOrder = true;
$order = new Order(42);
$module->hookActionValidateOrderAfter(['orders' => [$order], 'customer' => $customer]);
$row = Db::$row;
$module->hookActionValidateOrderAfter(['orders' => [$order], 'customer' => $customer]);
check(Db::$row === $row, 'duplicate order hook reset the workflow or access token');
check(strlen($row['access_token']) === 64, 'missing order token');
check(!BlocksignEsign::downloadAllowed($order, json_decode($row['state'], true)), 'unsigned download unlocked');

$documents = ['aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa', 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb'];
$requests = ['cccccccc-cccc-cccc-cccc-cccccccccccc', 'dddddddd-dddd-dddd-dddd-dddddddddddd'];
$uploads = 0;
$secondSigned = false;
$workflow = new SignatureWorkflow(new Client(static function (string $method, string $path, string $type, string $body) use (
    $documents, $requests, &$uploads, &$secondSigned
): array {
    if ($path === '/api/v1/documents') {
        check($method === 'POST' && $uploads < 2, 'duplicate upload');
        check(str_contains($body, $uploads === 0 ? '%PDF agreement' : '%PDF mandate'), 'wrong file uploaded');
        return ['document_id' => $documents[$uploads++]];
    }
    $index = $uploads - 1;
    $request = $requests[$index];
    $base = "/api/v1/signature-requests/$request";
    if ($path === '/api/v1/signature-requests') {
        $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        $expected = $index === 0 ? ['qes', 2, 12.5, 0] : ['fes', 3, 25, 40];
        check([$payload['signature_level'], $payload['page'], $payload['x'], $payload['y']] === $expected,
            'configured placement not sent to BlockSign');
        return ['request_id' => $request];
    }
    return match ([$method, $path]) {
        ['POST', "$base/send"] => ['sent_at' => '2026-11-26T10:00:00Z'],
        ['GET', $base] => [
            'document_id' => $documents[$index],
            'request_id' => $request,
            'completed' => $index === 0 || $secondSigned,
            'signers' => [['email' => 'guest@example.com', 'signed_at' => '2026-11-26T11:00:00Z']],
        ],
    };
}));
$before = Db::$row;
BlocksignEsign::processOrder(42, $workflow);
check($uploads === 0 && Db::$row === $before, 'unpaid order must wait untouched for payment');
Order::$paid = true;
check(!BlocksignEsign::downloadAllowed($order, json_decode(Db::$row['state'], true)), 'payment alone unlocked the download');
BlocksignEsign::processOrder(42, $workflow);
$state = json_decode(Db::$row['state'], true);
check($state['documents'][0]['completed'] && !$state['documents'][1]['completed'], 'each signature must be tracked separately');
check(!BlocksignEsign::downloadAllowed($order, $state), 'one signature must not unlock the download');
check(Db::$row['next_attempt'] > 0, 'next required document was not scheduled');
BlocksignEsign::processOrder(42, $workflow);
check(!BlocksignEsign::downloadAllowed($order, json_decode(Db::$row['state'], true)), 'pending second signature unlocked the download');
$secondSigned = true;
BlocksignEsign::processOrder(42, $workflow);
$state = json_decode(Db::$row['state'], true);
check($uploads === 2, 'expected exactly two uploads');
check(BlocksignEsign::downloadAllowed($order, $state), 'signed download remains locked');
check(Db::$row['next_attempt'] === 0, 'completed workflow should stop polling');
Order::$paid = false;
check(!BlocksignEsign::downloadAllowed($order, $state), 'unconfirmed payment must keep a signed download locked');
Order::$paid = true;
$order->current_state = 6;
check(!BlocksignEsign::downloadAllowed($order, $state), 'cancelled order remained eligible');
echo "PASS PrestaShop pilot with mocked BlockSign API\n";

Db::$row = [];
$configured_scope = '{"mode":"category","category_id":22}';
$order = new Order(42);
$module->hookActionValidateOrderAfter(['orders' => [$order], 'customer' => $customer]);
check(Db::$row === [], 'unrelated category matched');

$configured_scope = '{"mode":"category","category_id":21}';
$module->hookActionValidateOrderAfter(['orders' => [$order], 'customer' => $customer]);
check(isset(json_decode(Db::$row['state'], true)['downloads'][99]), 'configured category did not match');

Db::$row = [];
$configured_scope = '{"mode":"all","category_id":0}';
ProductDownload::$active = true;
$module->hookActionValidateOrderAfter(['orders' => [$order], 'customer' => $customer]);
check(Db::$row === [], 'catalog-wide scope selected a native download');
ProductDownload::$active = false;
$module->hookActionValidateOrderAfter(['orders' => [$order], 'customer' => $customer]);
check(isset(json_decode(Db::$row['state'], true)['downloads'][99]), 'catalog-wide scope did not select eligible product');
echo "PASS PrestaShop product scopes\n";

require ($argv[1] ?? 'build/blocksignesign') . '/upgrade/upgrade-0.6.0.php';
check(upgrade_module_0_6_0($module), 'product hooks could not be installed during upgrade');
check($module->hooks === ['actionProductFormBuilderModifier', 'actionAfterUpdateProductFormHandler'], 'upgrade omitted product form hooks');
$configured_scope = '{"mode":"flagged","category_id":0}';
Db::$row = [];
$module->hookActionValidateOrderAfter(['orders' => [$order], 'customer' => $customer]);
check(Db::$row === [], 'unmarked product was selected');

$module->hookActionAfterUpdateProductFormHandler([
    'id' => 99, 'form_data' => ['options' => ['blocksign_esign_required' => true]],
]);
class TestRegex
{
    public function __construct(string $pattern)
    {
    }
}
class_alias(TestRegex::class, 'Symfony\Component\Validator\Constraints\Regex');
$builder = new class {
    public array $checkbox = [];
    public function has(string $name): bool { return $name === 'options'; }
    public function get(string $name): self { return $this; }
    public function add(string $name, string $type, array $options): void { $this->checkbox[$name] = $options; }
};
$module->hookActionProductFormBuilderModifier(['id' => 99, 'form_builder' => $builder, 'options' => ['shop_id' => 2]]);
check($builder->checkbox['blocksign_esign_required']['data'] === true, 'checkbox did not load the saved flag');
$module->hookActionValidateOrderAfter(['orders' => [$order], 'customer' => $customer]);
check(isset(json_decode(Db::$row['state'], true)['downloads'][99]), 'saved product checkbox did not select the product');

$module->hookActionAfterUpdateProductFormHandler([
    'id' => 99, 'form_data' => ['options' => ['blocksign_esign_required' => false]],
]);
Db::$row = [];
$module->hookActionValidateOrderAfter(['orders' => [$order], 'customer' => $customer]);
check(Db::$row === [], 'unchecked product was still selected');
echo "PASS PrestaShop product checkbox and upgrade\n";

file_put_contents($files . '/custom-agreement.pdf', '%PDF custom agreement');
file_put_contents($files . '/custom-download.pdf', '%PDF custom download');
$module->hookActionAfterUpdateProductFormHandler([
    'id' => 99,
    'form_data' => ['options' => [
        'blocksign_esign_required' => true,
        'blocksign_esign_agreement_file' => 'custom-agreement.pdf',
        'blocksign_esign_download_file' => 'custom-download.pdf',
    ]],
]);
$module->hookActionValidateOrderAfter(['orders' => [$order], 'customer' => $customer]);
$state = json_decode(Db::$row['state'], true);
check($state['documents'][0]['file'] === 'custom-agreement.pdf', 'custom agreement was not selected');
check($state['documents'][1]['file'] === 'mandate.pdf', 'additional documents were lost');
check($state['downloads'][99]['file'] === 'custom-download.pdf' && $state['downloads'][99]['hash'] === hash('sha256', '%PDF custom download'),
    'custom download was not pinned at checkout');
echo "PASS PrestaShop product-specific PDFs\n";

$configured_scope = '{"mode":"all","category_id":0}';
$order->items = [
    ['product_id' => '99', 'product_quantity' => '1', 'product_quantity_refunded' => '0'],
    ['product_id' => '100', 'product_quantity' => '1', 'product_quantity_refunded' => '0'],
    ['product_id' => '101', 'product_quantity' => '2', 'product_quantity_refunded' => '0'],
];
Db::$row = [];
$module->hookActionValidateOrderAfter(['orders' => [$order], 'customer' => $customer]);
$state = json_decode(Db::$row['state'], true);
check(array_keys($state['downloads']) === [99, 101], 'mixed cart selected the wrong products');
check(count($state['documents']) === 3, 'the two agreements and shared mandate were not deduplicated correctly');
check(BlocksignEsign::orderEligible($order, $state), 'valid mixed order was rejected');
check(\BlockSign\ESign\OrderFiles::download($state, 100) === null, 'unprotected line has a private download');
$order->items[2]['product_quantity_refunded'] = '1';
check(!BlocksignEsign::orderEligible($order, $state), 'refunded protected line remained eligible');
echo "PASS PrestaShop mixed cart and shared-document deduplication\n";

file_put_contents($files . '/category-terms.pdf', '%PDF category terms');
$category_documents = '21 | category-terms.pdf | FES | 1 | 50 | 50';
Db::$row = [];
$order = new Order(42);
$module->hookActionValidateOrderAfter(['orders' => [$order], 'customer' => $customer]);
$state = json_decode(Db::$row['state'], true);
check(array_column($state['documents'], 'file') === ['custom-agreement.pdf', 'mandate.pdf', 'category-terms.pdf'],
    'matching category document was not included');
echo "PASS PrestaShop category-specific documents\n";
