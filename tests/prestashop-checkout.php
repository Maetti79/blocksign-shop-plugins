<?php

declare(strict_types=1);

use BlockSign\PrestaCheckout\StripeCheckout;

define('_PS_VERSION_', '9.0.0');
define('_DB_PREFIX_', 'ps_');
define('_DB_NAME_', 'blocksign_fixture');
putenv('BLOCKSIGN_STRIPE_SECRET_KEY=sk_test_fixture1234');
putenv('BLOCKSIGN_STRIPE_WEBHOOK_SECRET=whsec_fixture1234');
putenv('BLOCKSIGN_STRIPE_SHOP_ID=1');

#[AllowDynamicProperties]
class Module
{
    public bool $active = true;
    public object $context;

    public function __construct()
    {
        $this->context = (object) ['shop' => (object) ['id' => 1], 'customer' => new Customer(7), 'cart' => new Cart(),
            'link' => new class {
                public function getModuleLink(): string { return 'https://shop.example.test/checkout'; }
            }];
    }

    public static function isEnabled(string $name): bool { return in_array($name, ['blocksignesign', 'blocksigncheckout'], true); }
    public static function getPaymentModules(): array { return [['name' => 'blocksigncheckout']]; }
    public static function getInstanceByName(string $name): BlocksignEsign { return new BlocksignEsign(); }
}

class PaymentModule extends Module
{
    public static int $acceptedOrders = 0;

    public function validateOrder(int $cart, int $state, int $amount, string $method, ...$extra): bool
    {
        check($state === 13 && $amount === 0, 'native order must be created unpaid');
        check(BlocksignEsign::$approved, 'native order was created before confirmation');
        ++self::$acceptedOrders;
        Order::$exists = true;
        Db::$orderStatus = $state;
        return true;
    }
}

class BlocksignEsign
{
    public static bool $approved = false;
    public static array $confirmedCart = [[99, 0, 1]];

    public function onlineCheckoutReady(): bool { return self::$approved; }

    public static function findOrder(int $id): ?array
    {
        if (!Order::$exists || $id !== 42) {
            return null;
        }
        return ['id_shop' => 1, 'access_token' => str_repeat('a', 64), 'state' => json_encode([
            'timing' => 'before_order', 'confirmed_currency_id' => 2, 'customer_id' => 7, 'confirmed_total' => '24.50',
            'confirmed_cart' => self::$confirmedCart, 'signer_email' => 'buyer@example.test', 'scheduled_documents' => true,
            'documents' => [
                ['completed' => self::$approved, 'timing' => 'before_order'],
                ['completed' => false, 'timing' => 'after_payment'],
            ],
        ], JSON_THROW_ON_ERROR)];
    }

    public static function orderEligible(Order $order, array $state): bool { return $order->current_state !== 99; }
}

class Currency {
    public string $iso_code = 'EUR';
    public function __construct(int $id) {}
}

class Customer
{
    public bool $is_guest = false;
    public string $email = 'buyer@example.test';
    public string $secure_key = 'fixture-key';

    public function __construct(public int $id) {}
    public function isLogged(bool $allowGuest): bool { return $this->id > 0; }
}

class Cart
{
    public const BOTH = 3;
    public int $id = 76;
    public int $id_shop = 1;
    public int $id_customer = 7;
    public int $id_currency = 2;

    public function getOrderTotal(bool $tax, int $type): float { return 24.50; }
    public function getPackageList(): array { return [[['product_list' => [99]]]]; }
    public function OrderExists(): bool { return Order::$exists; }
}

class Order
{
    public static bool $exists = false;
    public static float $total = 24.50;
    public int $id_shop = 1;
    public int $id_customer = 7;
    public int $id_currency = 2;
    public int $current_state;
    public float $total_paid_tax_incl;
    public float $total_paid_real;
    public string $module = 'blocksigncheckout';
    public string $reference = 'REFERENCE';

    public function __construct(public int $id)
    {
        if (!self::$exists) {
            $this->id = 0;
        }
        $this->current_state = Db::$orderStatus;
        $this->total_paid_tax_incl = self::$total;
        $this->total_paid_real = array_sum(array_column(Db::$payments, 'amount'));
    }
    public function getProductsDetail(): array { return [['product_id' => 99, 'product_quantity' => 1]]; }
    public function getOrderPayments(): array { return Db::$payments; }

    public function addOrderPayment(float $amount, string $method, string $id): bool
    {
        check(Db::$transaction, 'payment must be booked in a transaction');
        Db::$payments[] = ['amount' => $amount, 'method' => $method, 'id' => $id];
        return true;
    }
}

class OrderState
{
    public bool $deleted = false;
    public bool $paid;
    public function __construct(public int $id) { $this->paid = $id === 21; }
}

class OrderHistory
{
    public int $id_order;
    public static bool $fails = false;

    public function changeIdOrderState(int $id, Order $order, bool $existing): void
    {
        check($id === 21 && $existing && $order->id === $this->id_order, 'native status did not use the existing payment');
        Db::$orderStatus = $id;
    }
    public function add(): bool { return !self::$fails; }
}

class Configuration
{
    public static function getGlobalValue(string $key): int { return $key === 'BLOCKSIGN_CHECKOUT_PENDING' ? 13 : 1; }
    public static function get(string $key, mixed ...$context): int { return 21; }
}

class Validate
{
    public static function isLoadedObject(object $object): bool { return $object->id > 0; }
}

class Tools
{
    public static function getToken(bool $page): string { return 'fixture-token'; }
}

class NativePaymentOption
{
    public function setCallToActionText(string $text): self { return $this; }
    public function setAction(string $url): self { return $this; }
    public function setInputs(array $inputs): self { return $this; }
}
class_alias(NativePaymentOption::class, 'PrestaShop\\PrestaShop\\Core\\Payment\\PaymentOption');

class Db
{
    public static bool $locked = false;
    public static bool $transaction = false;
    public static array $row = [];
    public static int $orderStatus = 0;
    public static array $payments = [];

    public static function getInstance(): self
    {
        static $db;
        return $db ??= new self();
    }

    public function getValue(string $query, bool $cache): int
    {
        if (str_contains($query, 'GET_LOCK')) {
            check(!self::$locked, 'nested checkout lock');
            self::$locked = true;
        } else {
            self::$locked = false;
        }
        return 1;
    }

    public function executeS(string $query, bool $array, bool $cache): array
    {
        return Order::$exists ? [['id_order' => 42, 'module' => 'blocksigncheckout']] : [];
    }
    public function getRow(string $query, bool $cache): array|false { return self::$row ?: false; }

    public function insert(string $table, array $data): bool
    {
        check(self::$locked && self::$row === [], 'duplicate or unlocked payment reservation');
        self::$row = $data + ['session_id' => null, 'paid_intent' => null];
        return true;
    }

    public function update(string $table, array $values, string $where, int $limit = 0, bool $nulls = false): bool
    {
        check(self::$locked, 'unlocked payment write');
        self::$row = array_replace(self::$row, $values);
        return true;
    }

    public function execute(string $query): bool
    {
        static $snapshot;
        if ($query === 'START TRANSACTION') {
            self::$transaction = true;
            $snapshot = [self::$row, self::$payments, self::$orderStatus];
        } elseif ($query === 'ROLLBACK') {
            [self::$row, self::$payments, self::$orderStatus] = $snapshot;
            self::$transaction = false;
        } elseif ($query === 'COMMIT') {
            self::$transaction = false;
        } else {
            throw new RuntimeException('Unexpected SQL.');
        }
        return true;
    }
}

function pSQL(string $value): string { return $value; }

function check(bool $condition, string $reason): void
{
    if (!$condition) {
        throw new LogicException($reason);
    }
}

function rejects(Closure $call, string $expected): void
{
    try {
        $call();
    } catch (RuntimeException | InvalidArgumentException $error) {
        check(str_contains($error->getMessage(), $expected), 'Unexpected failure: ' . $error->getMessage());
        return;
    }
    throw new LogicException('Expected rejection: ' . $expected);
}

require __DIR__ . '/../packages/php/src/Gate.php';
require __DIR__ . '/../packages/php/src/DocumentSchedule.php';
require ($argv[1] ?? 'build/blocksigncheckout') . '/blocksigncheckout.php';

$module = new BlocksignCheckout();
$cart = new Cart();
check($module->hookPaymentOptions(['cart' => $cart]) === [], 'unsigned payment option was offered');
rejects(fn () => $module->createOrder($cart), 'confirmation');
check(!Order::$exists, 'an unsigned order was created');

BlocksignEsign::$approved = true;
check(count($module->hookPaymentOptions(['cart' => $cart])) === 1, 'signed cart did not offer the payment method');
$order = $module->createOrder($cart);
$module->createOrder($cart);
check(PaymentModule::$acceptedOrders === 1 && $order->current_state === 13, 'repeated POST created another order');

rejects(fn () => $module->view($order, str_repeat('b', 64)), 'unavailable');
$module->context->customer = new Customer(8);
rejects(fn () => $module->view($order, str_repeat('a', 64)), 'unavailable');
$module->context->customer = new Customer(7);

$session = [
    'id' => 'cs_test_fixture123', 'mode' => 'payment', 'livemode' => false, 'status' => 'open', 'currency' => 'eur',
    'client_reference_id' => '42', 'metadata' => ['blocksign_order' => '42', 'blocksign_shop' => '1'],
    'amount_total' => 2450, 'payment_status' => 'unpaid', 'url' => 'https://checkout.stripe.com/c/pay/fixture',
];
$attempts = [];
$timeout = true;
$stripe = new StripeCheckout(static function (string $method, string $path, array $data, ?string $key) use (&$session, &$attempts, &$timeout): array {
    if ($method === 'POST') {
        $attempts[] = $key;
        check($data['payment_method_types'] === ['card', 'paypal', 'sepa_debit'], 'three methods were not offered to Stripe');
        check($data['line_items'][0]['price_data']['unit_amount'] === 2450 && $data['customer_email'] === 'buyer@example.test',
            'payment was not bound to the native total and customer');
        if ($timeout) {
            throw new RuntimeException('Stripe test timeout');
        }
    } else {
        check($path === '/v1/checkout/sessions/' . $session['id'], 'wrong Stripe session lookup');
    }
    return $session;
}, false);
$return = 'https://shop.example.test/status?order=42';

BlocksignEsign::$confirmedCart = [[99, 0, 2]];
rejects(fn () => $module->paymentLink($order, $return, $stripe), 'does not match');
BlocksignEsign::$confirmedCart = [[99, 0, 1]];
rejects(fn () => $module->paymentLink($order, $return, $stripe), 'test timeout');
$timeout = false;
check($module->paymentLink($order, $return, $stripe) === $session['url'] && $attempts[0] === $attempts[1],
    'network retry generated a different payment attempt');
check($module->paymentLink($order, $return, $stripe) === $session['url'] && count($attempts) === 2,
    'resuming the order opened a second Stripe session');

$event = ['type' => 'checkout.session.completed', 'data' => ['object' => $session]];
$payload = json_encode($event, JSON_THROW_ON_ERROR);
$now = time();
$signature = 't=' . $now . ',v1=' . hash_hmac('sha256', $now . '.' . $payload, 'whsec_fixture1234');
rejects(fn () => StripeCheckout::event($payload, $signature, 'whsec_wrong1234', $now), 'signature');
rejects(fn () => StripeCheckout::event($payload, $signature, 'whsec_fixture1234', $now + 301), 'timestamp');
$authentic = StripeCheckout::event($payload, $signature, 'whsec_fixture1234', $now);

$session['status'] = 'complete';
$module->acceptEvent($authentic, $stripe);
check(Db::$payments === [] && Db::$orderStatus === 13 && $module->paymentLink($order, $return, $stripe) === null,
    'completed but unpaid SEPA session was booked or could charge twice');
rejects(fn () => $module->expirePendingPayment($order, $stripe), 'still pending');

$session['payment_status'] = 'paid';
$session['payment_intent'] = 'pi_fixture123';
$session['amount_total'] = 2400;
$delayed = ['type' => 'checkout.session.async_payment_succeeded', 'data' => ['object' => $session]];
rejects(fn () => $module->acceptEvent($delayed, $stripe), 'does not match');
check(Db::$payments === [], 'a wrong Stripe amount was booked');
$session['amount_total'] = 2450;
OrderHistory::$fails = true;
rejects(fn () => $module->acceptEvent($delayed, $stripe), 'paid PrestaShop status');
check(Db::$payments === [] && Db::$orderStatus === 13 && !Db::$row['paid_intent'], 'a partial booking was committed');
OrderHistory::$fails = false;

$module->acceptEvent($delayed, $stripe);
$module->acceptEvent($delayed, $stripe);
check(count(Db::$payments) === 1 && Db::$payments[0]['id'] === 'pi_fixture123'
    && Db::$orderStatus === 21 && Db::$row['paid_intent'] === 'pi_fixture123' && !Db::$locked,
    'Stripe payment was not booked exactly once');

Db::$orderStatus = 13;
Db::$row['paid_intent'] = null;
Db::$payments = [];
$session['status'] = 'open';
$session['payment_status'] = 'unpaid';
$expirations = 0;
$cancellation = new StripeCheckout(static function (string $method, string $path, array $body, ?string $key) use (&$session, &$expirations): array {
    if ($method === 'POST') {
        check($path === '/v1/checkout/sessions/' . $session['id'] . '/expire', 'cancellation created a new checkout');
        ++$expirations;
        $session['status'] = 'expired';
    }
    return $session;
}, false);
$module->expirePendingPayment($order, $cancellation);
check($expirations === 1 && Db::$row['closed'] === 1, 'an open Stripe Checkout was not expired before cancellation');
check($module->paymentLink($order, $return, $cancellation) === null && $expirations === 1,
    'payment restarted while an order cancellation was in progress');
echo "PASS PrestaShop Stripe checkout authorization, session reuse, delayed settlement, idempotent payment, and cancellation\n";
