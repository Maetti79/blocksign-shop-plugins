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
    rmdir($files);
});

#[AllowDynamicProperties]
class Module
{
    public function __construct()
    {
    }
}

class Configuration
{
    public static function get(string $key, ...$context): int|string
    {
        return match ($key) {
            'BLOCKSIGN_ESIGN_PRODUCT' => 99,
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
    public int $id_customer = 81;
    public int $current_state = 2;
    public string $module = 'free_order';
    public string $total_paid_tax_incl = '0.000000';

    public function __construct(public int $id)
    {
    }

    public function getProductsDetail(): array
    {
        return [['product_id' => '99', 'product_quantity' => '1', 'product_quantity_refunded' => '0']];
    }
}

class Product
{
    public bool $is_virtual = true;
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

$order->total_paid_tax_incl = '0.000000';
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
$order->current_state = 6;
check(!BlocksignEsign::downloadAllowed($order, $state), 'cancelled order remained eligible');
echo "PASS PrestaShop pilot with mocked BlockSign API\n";
