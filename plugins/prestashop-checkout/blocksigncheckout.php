<?php

declare(strict_types=1);

use BlockSign\PrestaCheckout\StripeCheckout;
use PrestaShop\PrestaShop\Core\Payment\PaymentOption;
use PrestaShop\PrestaShop\Core\Module\Exception\ModuleErrorException;

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/src/StripeCheckout.php';

final class BlocksignCheckout extends PaymentModule
{
    private const PENDING = 'BLOCKSIGN_CHECKOUT_PENDING';
    private const TABLE = 'blocksign_checkout';

    public function __construct()
    {
        $this->name = 'blocksigncheckout';
        $this->tab = 'payments_gateways';
        $this->version = '0.1.0';
        $this->author = 'BlockSign';
        $this->dependencies = ['blocksignesign'];
        $this->ps_versions_compliancy = ['min' => '9.0.0', 'max' => _PS_VERSION_];
        parent::__construct();
        $this->displayName = 'BlockSign Checkout';
        $this->description = 'Open Stripe Checkout after BlockSign confirms the documents.';
    }

    public function install(): bool
    {
        return parent::install() === true && $this->installPendingState() && Db::getInstance()->execute(
            'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . self::TABLE . '` (
                id_order INT UNSIGNED NOT NULL PRIMARY KEY,
                id_shop INT UNSIGNED NOT NULL,
                amount INT UNSIGNED NOT NULL,
                key_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                attempt CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                attempt_at INT UNSIGNED NOT NULL,
                session_id VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
                paid_intent VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
                closed TINYINT UNSIGNED NOT NULL DEFAULT 0,
                UNIQUE KEY session (session_id),
                UNIQUE KEY payment (paid_intent)
            ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4'
        ) && $this->registerHook(['paymentOptions', 'actionOrderStatusUpdate']);
    }

    private function installPendingState(): bool
    {
        $state = new OrderState(self::pendingState());
        if (Validate::isLoadedObject($state) && !$state->deleted) {
            return true;
        }
        $state = new OrderState();
        foreach (Language::getLanguages(false) as $language) {
            $state->name[(int) $language['id_lang']] = $language['iso_code'] === 'de'
                ? 'BlockSign: Online-Zahlung offen' : 'BlockSign: awaiting online payment';
        }
        $state->module_name = $this->name;
        $state->color = '#4177A8';
        $state->paid = false;
        $state->logable = false;
        $state->send_email = false;
        return $state->add() && Configuration::updateGlobalValue(self::PENDING, (int) $state->id);
    }

    private static function pendingState(): int
    {
        return (int) Configuration::getGlobalValue(self::PENDING);
    }

    private function configuredShop(): int
    {
        return (int) (getenv('BLOCKSIGN_STRIPE_SHOP_ID') ?: Configuration::getGlobalValue('PS_SHOP_DEFAULT'));
    }

    private function available(): bool
    {
        return $this->active && StripeCheckout::configured() && self::pendingState() > 0
            && (int) $this->context->shop->id === $this->configuredShop() && Module::isEnabled('blocksignesign') && Module::isEnabled($this->name);
    }

    public function hookPaymentOptions(array $params): array
    {
        $cart = $params['cart'] ?? $this->context->cart;
        try {
            if (!$cart instanceof Cart || !$this->canStart($cart) || $cart->OrderExists()) {
                return [];
            }
            return [(new PaymentOption())
                ->setCallToActionText('Online-Zahlung mit Stripe Checkout')
                ->setAction($this->context->link->getModuleLink($this->name, 'checkout', [], true))
                ->setInputs([['type' => 'hidden', 'name' => 'checkout_token', 'value' => Tools::getToken(false)]])];
        } catch (Throwable $error) {
            return [];
        }
    }

    private function canStart(Cart $cart): bool
    {
        if (!$this->available() || (int) $cart->id < 1 || (int) $cart->id_shop !== $this->configuredShop()
            || (int) $cart->id_customer !== (int) $this->context->customer->id || !$this->context->customer->isLogged(true)) {
            return false;
        }
        $currency = new Currency((int) $cart->id_currency);
        $modules = array_column(Module::getPaymentModules(), 'name');
        $gate = Module::getInstanceByName('blocksignesign');
        $amount = (float) $cart->getOrderTotal(true, Cart::BOTH);
        return $currency->iso_code === 'EUR' && in_array($this->name, $modules, true)
            && StripeCheckout::minor($amount) > 0 && array_sum(array_map('count', $cart->getPackageList())) === 1
            && $gate && method_exists($gate, 'onlineCheckoutReady') && $gate->onlineCheckoutReady();
    }

    public function createOrder(Cart $cart): Order
    {
        $id = (int) $cart->id;
        if ($id < 1 || !$this->available() || (int) $cart->id_shop !== $this->configuredShop()
            || (int) $cart->id_customer !== (int) $this->context->customer->id || !$this->context->customer->isLogged(true)) {
            throw new RuntimeException('Online checkout is unavailable.');
        }
        return self::locked('cart', $id, function () use ($cart, $id): Order {
            if (!$cart->OrderExists()) {
                if (!$this->canStart($cart)) {
                    throw new RuntimeException('The required BlockSign confirmation is missing or checkout is unavailable.');
                }
                if (!$this->validateOrder($id, self::pendingState(), 0, $this->displayName, null, [], null, false, $this->context->customer->secure_key)) {
                    throw new RuntimeException('PrestaShop could not create the order.');
                }
            }
            $rows = Db::getInstance()->executeS(
                'SELECT id_order, module FROM `' . _DB_PREFIX_ . 'orders` WHERE id_cart = ' . $id . ' LIMIT 2', true, false
            );
            if (!is_array($rows) || count($rows) !== 1 || $rows[0]['module'] !== $this->name) {
                throw new RuntimeException('The cart cannot use a single online payment.');
            }
            $order = new Order((int) $rows[0]['id_order']);
            $this->proof($order);
            return $order;
        });
    }

    public function view(Order $order, ?string $token = null): array
    {
        $row = $this->proof($order);
        $owner = $this->context->customer;
        $account = new Customer((int) $order->id_customer);
        if (!Validate::isLoadedObject($account)
            || ($token !== null && !hash_equals($row['access_token'], $token))
            || (($token === null || !$account->is_guest)
                && ((int) $owner->id !== (int) $order->id_customer || !$owner->isLogged(true)))) {
            throw new RuntimeException('Online checkout is unavailable.');
        }
        return $row;
    }

    public function canResume(Order $order): bool
    {
        return $this->available() && $order->module === $this->name && (int) $order->current_state === self::pendingState();
    }

    private function proof(Order $order): array
    {
        if (!$this->available() || !Validate::isLoadedObject($order) || $order->module !== $this->name
            || (int) $order->id_shop !== $this->configuredShop()) {
            throw new RuntimeException('Online checkout is unavailable.');
        }
        Module::getInstanceByName('blocksignesign');
        $row = BlocksignEsign::findOrder((int) $order->id);
        $state = $row ? json_decode($row['state'], true, 512, JSON_THROW_ON_ERROR) : [];
        $currency = new Currency((int) $order->id_currency);
        $customer = new Customer((int) $order->id_customer);
        $lines = array_map(static fn (array $item): array => [
            (int) $item['product_id'], (int) ($item['product_attribute_id'] ?? 0), (int) $item['product_quantity'],
        ], $order->getProductsDetail());
        sort($lines);
        if (!$row || (int) $row['id_shop'] !== $this->configuredShop() || ($state['timing'] ?? '') !== 'before_order'
            || $currency->iso_code !== 'EUR' || ($state['confirmed_currency_id'] ?? 0) !== (int) $order->id_currency
            || ($state['customer_id'] ?? 0) !== (int) $order->id_customer || !Validate::isLoadedObject($customer)
            || ($state['confirmed_cart'] ?? null) !== $lines
            || !isset($state['confirmed_total']) || StripeCheckout::minor((float) $state['confirmed_total']) !== StripeCheckout::minor((float) $order->total_paid_tax_incl)
            || !BlocksignEsign::orderEligible($order, $state)
            || !\BlockSign\ESign\DocumentSchedule::allows('payment', $state, strcasecmp($state['signer_email'] ?? '', $customer->email) === 0)) {
            throw new RuntimeException('The order does not match the approved BlockSign checkout.');
        }
        return $row;
    }

    public function paymentLink(Order $order, string $returnUrl, StripeCheckout $stripe): ?string
    {
        $id = (int) $order->id;
        return self::locked('order', $id, function () use ($id, $returnUrl, $stripe): ?string {
            $order = new Order($id);
            $proof = $this->proof($order);
            if ((int) $order->current_state !== self::pendingState()) {
                return null;
            }
            $amount = StripeCheckout::minor((float) $order->total_paid_tax_incl);
            $row = self::paymentRow($id) ?? self::reserve($order);
            if ((int) $row['id_shop'] !== $this->configuredShop() || (int) $row['amount'] !== $amount) {
                throw new RuntimeException('The payment reservation no longer matches the order.');
            }
            if ($row['paid_intent'] || $row['closed']) {
                return null;
            }
            if ($row['session_id']) {
                $session = $stripe->retrieve($row['session_id']);
                $stripe->assertSession($session, $id, $this->configuredShop(), $amount);
                if ($session['id'] !== $row['session_id']) {
                    throw new RuntimeException('Unexpected Stripe Checkout session.');
                }
                if ($session['status'] === 'open') {
                    return StripeCheckout::redirectUrl($session);
                }
                if ($session['status'] !== 'expired' || ($session['payment_status'] ?? null) !== 'unpaid') {
                    return null;
                }
                $row['attempt'] = bin2hex(random_bytes(32));
                $row['attempt_at'] = time();
                $row['key_hash'] = self::keyHash();
                self::save($id, ['session_id' => null, 'attempt' => $row['attempt'], 'attempt_at' => $row['attempt_at'], 'key_hash' => $row['key_hash']], true);
            }
            if ((int) $row['attempt_at'] < time() - 43200 || !hash_equals($row['key_hash'], self::keyHash())) {
                throw new RuntimeException('This payment attempt requires review.');
            }
            $state = json_decode($proof['state'], true, 512, JSON_THROW_ON_ERROR);
            $session = $stripe->create($id, $this->configuredShop(), $order->reference, $amount, $state['signer_email'], $returnUrl,
                StripeCheckout::methods(getenv('BLOCKSIGN_STRIPE_METHODS') ?: ''), 'blocksign-checkout-' . $row['attempt']);
            self::save($id, ['session_id' => pSQL($session['id'])]);
            return $session['status'] === 'open' ? StripeCheckout::redirectUrl($session) : null;
        });
    }

    public function acceptEvent(array $event, StripeCheckout $stripe): void
    {
        if (!in_array($event['type'] ?? '', StripeCheckout::EVENTS, true)) {
            return;
        }
        $object = $event['data']['object'] ?? [];
        if (empty($object['metadata']['blocksign_order']) || (int) ($object['metadata']['blocksign_shop'] ?? 0) !== $this->configuredShop()) {
            return;
        }
        $id = filter_var($object['metadata']['blocksign_order'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            throw new RuntimeException('Invalid Stripe Checkout order reference.');
        }
        $sessionId = StripeCheckout::sessionId($object['id'] ?? '');
        self::locked('order', $id, function () use ($id, $sessionId, $stripe): void {
            $row = self::paymentRow($id);
            if (!$row || $row['session_id'] !== $sessionId || (int) $row['id_shop'] !== $this->configuredShop()) {
                throw new RuntimeException('Stripe Checkout session has no matching reservation.');
            }
            if ($row['paid_intent']) {
                return;
            }
            $session = $stripe->retrieve($sessionId);
            $stripe->assertSession($session, $id, $this->configuredShop(), (int) $row['amount']);
            if ($session['id'] !== $sessionId) {
                throw new RuntimeException('Unexpected Stripe Checkout session.');
            }
            $reference = StripeCheckout::paidReference($session);
            if ($reference === null) {
                return;
            }
            if ($row['closed']) {
                throw new RuntimeException('A closed PrestaShop order received a Stripe payment.');
            }

            $order = new Order($id);
            $this->proof($order);
            if ((int) $order->current_state !== self::pendingState()
                || StripeCheckout::minor((float) $order->total_paid_tax_incl) !== (int) $row['amount']
                || (float) $order->total_paid_real !== 0.0 || count($order->getOrderPayments()) !== 0) {
                throw new RuntimeException('The order is no longer awaiting this Stripe payment.');
            }
            $paid = new OrderState((int) Configuration::get('PS_OS_PAYMENT', null, null, $this->configuredShop()));
            if (!Validate::isLoadedObject($paid) || $paid->deleted || !$paid->paid) {
                throw new RuntimeException('PrestaShop does not have a valid paid order state.');
            }
            $db = Db::getInstance();
            if (!$db->execute('START TRANSACTION')) {
                throw new RuntimeException('Could not start booking the Stripe payment.');
            }
            try {
                if (!$order->addOrderPayment((int) $row['amount'] / 100, $this->displayName, $reference)) {
                    throw new RuntimeException('Could not record the Stripe payment.');
                }
                $history = new OrderHistory();
                $history->id_order = $id;
                $history->changeIdOrderState((int) $paid->id, $order, true);
                if (!$history->add() || (int) (new Order($id))->current_state !== (int) $paid->id) {
                    throw new RuntimeException('Could not record the paid PrestaShop status.');
                }
                self::save($id, ['paid_intent' => pSQL($reference)]);
                if (!$db->execute('COMMIT')) {
                    throw new RuntimeException('Could not book the Stripe payment.');
                }
            } catch (Throwable $error) {
                $db->execute('ROLLBACK');
                throw $error;
            }
        });
    }

    public function hookActionOrderStatusUpdate(array $params): void
    {
        $id = (int) ($params['id_order'] ?? 0);
        if ($id < 1 || (int) ($params['newOrderStatus']->id ?? 0) !== (int) Configuration::get('PS_OS_CANCELED')) {
            return;
        }
        $order = new Order($id);
        if ($order->module !== $this->name || (int) $order->current_state !== self::pendingState()) {
            return;
        }
        try {
            $this->expirePendingPayment($order);
        } catch (Throwable $error) {
            throw new ModuleErrorException('Prüfe die offene Stripe-Zahlung, bevor du die Bestellung stornierst.');
        }
    }

    public function expirePendingPayment(Order $order, ?StripeCheckout $stripe = null): void
    {
        $id = (int) $order->id;
        self::locked('order', $id, function () use ($id, $order, $stripe): void {
            $row = self::paymentRow($id);
            if (!$row) {
                self::reserve($order, true);
                return;
            }
            if ($row['closed']) {
                return;
            }
            if (!$row['session_id'] || (int) $row['id_shop'] !== (int) $order->id_shop) {
                throw new RuntimeException('The Stripe payment attempt needs review.');
            }
            $stripe ??= StripeCheckout::client();
            $shop = (int) $row['id_shop'];
            $amount = (int) $row['amount'];
            $session = $stripe->retrieve($row['session_id']);
            $stripe->assertSession($session, $id, $shop, $amount);
            if ($session['id'] === $row['session_id'] && $session['status'] === 'open') {
                $session = $stripe->expire($row['session_id']);
                $stripe->assertSession($session, $id, $shop, $amount);
            }
            if ($session['id'] !== $row['session_id'] || $session['status'] !== 'expired' || $session['payment_status'] !== 'unpaid') {
                throw new RuntimeException('The Stripe payment attempt is still pending or paid.');
            }
            self::save($id, ['closed' => 1]);
        });
    }

    private static function reserve(Order $order, bool $closed = false): array
    {
        $id = (int) $order->id;
        if (!Db::getInstance()->insert(self::TABLE, [
            'id_order' => $id, 'id_shop' => (int) $order->id_shop,
            'amount' => StripeCheckout::minor((float) $order->total_paid_tax_incl), 'key_hash' => self::keyHash(),
            'attempt' => bin2hex(random_bytes(32)), 'attempt_at' => time(), 'closed' => (int) $closed,
        ]) || !($row = self::paymentRow($id))) {
            throw new RuntimeException('Could not reserve a Stripe Checkout attempt.');
        }
        return $row;
    }

    private static function paymentRow(int $id): ?array
    {
        $row = Db::getInstance()->getRow('SELECT * FROM `' . _DB_PREFIX_ . self::TABLE . '` WHERE id_order = ' . $id, false);
        return is_array($row) ? $row : null;
    }

    private static function keyHash(): string
    {
        return hash('sha256', getenv('BLOCKSIGN_STRIPE_SECRET_KEY') ?: '');
    }

    private static function save(int $id, array $data, bool $nulls = false): void
    {
        if (!Db::getInstance()->update(self::TABLE, $data, 'id_order = ' . $id, 0, $nulls)) {
            throw new RuntimeException('Could not save Stripe Checkout state.');
        }
    }

    private static function locked(string $kind, int $id, Closure $call): mixed
    {
        $db = Db::getInstance();
        $key = 'blocksign-' . $kind . '-' . substr(hash('sha256', _DB_NAME_ . ':' . _DB_PREFIX_ . ':' . $id), 0, 32);
        if ((int) $db->getValue("SELECT GET_LOCK('$key', 5)", false) !== 1) {
            throw new RuntimeException('Another checkout request is in progress.');
        }
        try {
            return $call();
        } finally {
            $db->getValue("SELECT RELEASE_LOCK('$key')", false);
        }
    }
}
