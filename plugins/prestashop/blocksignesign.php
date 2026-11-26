<?php

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
use PrestaShop\PrestaShop\Core\Module\Exception\ModuleErrorException;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Validator\Constraints\Regex;

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/core/Client.php';
require_once __DIR__ . '/core/Gate.php';
require_once __DIR__ . '/core/SignatureSettings.php';
require_once __DIR__ . '/core/SignatureWorkflow.php';
require_once __DIR__ . '/core/RequiredSignatures.php';
require_once __DIR__ . '/core/ProductScope.php';
require_once __DIR__ . '/core/ProductFiles.php';
require_once __DIR__ . '/core/OrderFiles.php';
require_once __DIR__ . '/core/CategoryDocuments.php';
require_once __DIR__ . '/core/EvidenceArchive.php';

class BlocksignEsign extends Module
{
    private const PREORDER = 'BLOCKSIGN_ESIGN_PREORDER';
    private const OFFLINE_MODULES = ['ps_wirepayment', 'ps_checkpayment'];
    private static array $approvedCarts = [];
    private const PRODUCT = 'BLOCKSIGN_ESIGN_PRODUCT';
    private const SCOPE = 'BLOCKSIGN_ESIGN_SCOPE';
    private const SHIPPING_FLAG = 'blocksign_esign_shipping';
    private const SHIPPING_FLAG_PREFIX = 'BLOCKSIGN_ESIGN_SHIPPING_FLAG_';
    private const SHIPPING_STATUS = 'BLOCKSIGN_ESIGN_SHIPPING_STATUS';
    private const PRODUCT_FLAG = 'blocksign_esign_required';
    private const PRODUCT_FLAG_PREFIX = 'BLOCKSIGN_ESIGN_PRODUCT_FLAG_';
    private const PRODUCT_FILES_PREFIX = 'BLOCKSIGN_ESIGN_PRODUCT_FILES_';
    private const SIGNATURE = 'BLOCKSIGN_ESIGN_SIGNATURE';
    private const DOCUMENTS = 'BLOCKSIGN_ESIGN_DOCUMENTS';
    private const CATEGORY_DOCUMENTS = 'BLOCKSIGN_ESIGN_CATEGORY_DOCUMENTS';

    public function __construct()
    {
        $this->name = 'blocksignesign';
        $this->version = '0.11.0';
        $this->author = 'BlockSign';
        $this->tab = 'administration';
        $this->ps_versions_compliancy = ['min' => '9.0.0', 'max' => _PS_VERSION_];
        parent::__construct();
        $this->displayName = 'BlockSign eSign';
        $this->description = 'Release protected PDFs and shipments after BlockSign confirmation.';
    }

    public function install()
    {
        return parent::install()
            && Db::getInstance()->execute(
                'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'blocksign_esign` (
                    id_order INT UNSIGNED NOT NULL PRIMARY KEY,
                    id_shop INT UNSIGNED NOT NULL,
                    access_token CHAR(64) NOT NULL,
                    state TEXT NOT NULL,
                    next_attempt INT UNSIGNED NOT NULL,
                    created_at INT UNSIGNED NOT NULL,
                    INDEX pending (next_attempt)
                ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4'
            )
            && $this->registerHook(['actionValidateOrderAfter', 'displayOrderConfirmation', 'displayOrderDetail'])
            && $this->registerProductHooks()
            && $this->installPreorder()
            && $this->installShipping();
    }

    public function installShipping(): bool
    {
        $status = new OrderState((int) Configuration::getGlobalValue(self::SHIPPING_STATUS));
        if (!Validate::isLoadedObject($status) || $status->deleted) {
            $status = new OrderState();
            foreach (Language::getLanguages(false) as $language) {
                $status->name[(int) $language['id_lang']] = $language['iso_code'] === 'de'
                    ? 'BlockSign: Versandbereit'
                    : 'BlockSign: ready to ship';
            }
            $status->color = '#2A7555';
            $status->module_name = $this->name;
            $status->paid = true;
            $status->logable = true;
            if (!$status->add() || !Configuration::updateGlobalValue(self::SHIPPING_STATUS, (int) $status->id)) {
                return false;
            }
        }
        return $this->registerHook('actionOrderStatusUpdate');
    }

    public function installPreorder(): bool
    {
        return Db::getInstance()->execute(
            'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'blocksign_esign_preorder` (
                fingerprint CHAR(64) NOT NULL PRIMARY KEY,
                id_shop INT UNSIGNED NOT NULL,
                state TEXT NOT NULL,
                next_attempt INT UNSIGNED NOT NULL,
                expires INT UNSIGNED NOT NULL,
                used TINYINT UNSIGNED NOT NULL DEFAULT 0,
                INDEX due (next_attempt, expires),
                INDEX expiry (expires)
            ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4'
        ) && $this->registerHook(['displayPaymentTop', 'actionPresentPaymentOptions', 'actionValidateOrderBefore']);
    }

    public function registerProductHooks(): bool
    {
        return $this->registerHook(['actionProductFormBuilderModifier', 'actionAfterUpdateProductFormHandler']);
    }

    public function hookActionProductFormBuilderModifier(array $params): void
    {
        $productId = (int) ($params['id'] ?? 0);
        $shopId = $this->productEditorShop();
        $builder = $params['form_builder'];
        if (!$productId || !$shopId || !$builder->has('options')
            || (int) ($params['options']['shop_id'] ?? $shopId) !== $shopId) {
            return;
        }

        $builder->get('options')->add(self::PRODUCT_FLAG, CheckboxType::class, [
            'label' => 'Require BlockSign eSign',
            'help' => 'Applies when BlockSign is configured to use marked products.',
            'required' => false,
            'data' => self::productMarked($productId, $shopId),
        ]);
        $builder->get('options')->add(self::SHIPPING_FLAG, CheckboxType::class, [
            'label' => 'Require BlockSign before shipping',
            'help' => 'Applies to physical products. Fulfillment waits for the BlockSign ready-to-ship status.',
            'required' => false,
            'data' => self::shippingMarked($productId, $shopId),
        ]);
        $files = self::productFiles($productId, $shopId);
        foreach (ProductFiles::DEFAULTS as $kind => $default) {
            $builder->get('options')->add('blocksign_esign_' . $kind . '_file', TextType::class, [
                'label' => $kind === 'agreement' ? 'BlockSign primary agreement PDF' : 'BlockSign protected download PDF',
                'help' => 'Private PDF filename. Leave blank to use ' . $default . '.',
                'required' => false,
                'empty_data' => '',
                'data' => $files[$kind] ?? '',
                'constraints' => [new Regex(pattern: ProductFiles::BASENAME_PATTERN)],
            ]);
        }
    }

    public function hookActionAfterUpdateProductFormHandler(array $params): void
    {
        $productId = (int) ($params['id'] ?? 0);
        $shopId = $this->productEditorShop();
        $flag = $params['form_data']['options'][self::PRODUCT_FLAG] ?? null;
        if (!$productId || !$shopId || !is_bool($flag)) {
            return;
        }

        if (!Configuration::updateValue(self::PRODUCT_FLAG_PREFIX . $productId, (int) $flag, false, null, $shopId)) {
            throw new RuntimeException('Could not save the BlockSign product checkbox.');
        }
        $shippingFlag = $params['form_data']['options'][self::SHIPPING_FLAG] ?? null;
        if (is_bool($shippingFlag)) {
            $enabledAt = $shippingFlag ? (self::shippingEnabledAt($productId, $shopId) ?: time()) : 0;
            if (!Configuration::updateValue(self::SHIPPING_FLAG_PREFIX . $productId, $enabledAt, false, null, $shopId)) {
                throw new RuntimeException('Could not save the BlockSign shipping checkbox.');
            }
        }
        $files = self::productFiles($productId, $shopId);
        foreach (array_keys(ProductFiles::DEFAULTS) as $kind) {
            $value = $params['form_data']['options']['blocksign_esign_' . $kind . '_file'] ?? null;
            if (is_string($value)) {
                $files[$kind] = ProductFiles::validate($value);
            }
        }
        if ($files && !Configuration::updateValue(self::PRODUCT_FILES_PREFIX . $productId, json_encode($files, JSON_THROW_ON_ERROR), false, null, $shopId)) {
            throw new RuntimeException('Could not save the BlockSign product files.');
        }
    }

    private static function productFiles(int $productId, int $shopId): array
    {
        $stored = Configuration::get(self::PRODUCT_FILES_PREFIX . $productId, null, null, $shopId);
        return $stored ? json_decode($stored, true, 512, JSON_THROW_ON_ERROR) : [];
    }

    private function productEditorShop(): int
    {
        if (Shop::isFeatureActive()) {
            return (int) Shop::getContextShopID();
        }
        return (int) $this->context->shop->id;
    }

    private static function productMarked(int $productId, int $shopId): bool
    {
        return (bool) Configuration::get(self::PRODUCT_FLAG_PREFIX . $productId, null, null, $shopId);
    }

    private static function shippingMarked(int $productId, int $shopId): bool
    {
        return self::shippingEnabledAt($productId, $shopId) > 0;
    }

    private static function shippingEnabledAt(int $productId, int $shopId): int
    {
        return (int) Configuration::get(self::SHIPPING_FLAG_PREFIX . $productId, null, null, $shopId);
    }

    public function getContent()
    {
        $output = '';
        if (Tools::isSubmit('submitBlocksignEsign')) {
            $productId = filter_var(Tools::getValue(self::PRODUCT), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
            try {
                $scope = ProductScope::validate([
                    'mode' => Tools::getValue('blocksign_scope'),
                    'category_id' => Tools::getValue('blocksign_category', 0),
                ]);
                $placement = SignatureSettings::validate([
                    'signature_level' => Tools::getValue('blocksign_level'),
                    'page' => Tools::getValue('blocksign_page'),
                    'x' => Tools::getValue('blocksign_x'),
                    'y' => Tools::getValue('blocksign_y'),
                ]);
                if ($productId === false) {
                    throw new InvalidArgumentException('Enter a valid product ID.');
                }
                $documents = Tools::getValue(self::DOCUMENTS, '');
                if (!is_string($documents)) {
                    throw new InvalidArgumentException('Enter one PDF per line.');
                }
                RequiredSignatures::parseAdditional($documents);
                $categoryDocuments = Tools::getValue(self::CATEGORY_DOCUMENTS, '');
                if (!is_string($categoryDocuments)) {
                    throw new InvalidArgumentException('Enter one category document per line.');
                }
                CategoryDocuments::parse($categoryDocuments);
                if (!Configuration::updateValue(self::PREORDER, Tools::getValue('blocksign_preorder') === '1' ? 1 : 0)
                    || !Configuration::updateValue(self::SCOPE, json_encode($scope, JSON_THROW_ON_ERROR))
                    || !Configuration::updateValue(self::DOCUMENTS, trim($documents))
                    || !Configuration::updateValue(self::CATEGORY_DOCUMENTS, trim($categoryDocuments))
                    || !Configuration::updateValue(self::SIGNATURE, json_encode($placement, JSON_THROW_ON_ERROR))
                    || !Configuration::updateValue(self::PRODUCT, $productId)) {
                    throw new RuntimeException('Could not save the BlockSign settings.');
                }
                $output = $this->displayConfirmation('Settings saved.');
            } catch (RuntimeException | InvalidArgumentException $error) {
                $output = $this->displayError($error->getMessage());
            }
        }

        $form = new HelperForm();
        $form->module = $this;
        $form->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $form->token = Tools::getAdminTokenLite('AdminModules');
        $form->submit_action = 'submitBlocksignEsign';
        $settings = self::signatureSettings();
        $scope = self::productScope();
        $form->fields_value = [
            self::PRODUCT => (int) Configuration::get(self::PRODUCT),
            'blocksign_scope' => $scope['mode'],
            'blocksign_preorder' => (int) Configuration::get(self::PREORDER),
            'blocksign_category' => $scope['category_id'],
            self::DOCUMENTS => (string) Configuration::get(self::DOCUMENTS),
            self::CATEGORY_DOCUMENTS => (string) Configuration::get(self::CATEGORY_DOCUMENTS),
            'blocksign_level' => $settings['signature_level'],
            'blocksign_page' => $settings['page'],
            'blocksign_x' => $settings['x'],
            'blocksign_y' => $settings['y'],
        ];
        return $output . $form->generateForm([['form' => [
            'legend' => ['title' => 'BlockSign eSign'],
            'input' => [[
                'type' => 'switch',
                'name' => 'blocksign_preorder',
                'label' => 'Require confirmation before order and payment',
                'desc' => 'For protected carts, use only native free orders, bank transfer and check payment. Disable other payment modules for these products.',
                'values' => [
                    ['id' => 'blocksign_preorder_on', 'value' => 1, 'label' => 'Yes'],
                    ['id' => 'blocksign_preorder_off', 'value' => 0, 'label' => 'No'],
                ],
            ], [
                'type' => 'select',
                'name' => 'blocksign_scope',
                'label' => 'Protected products',
                'options' => ['query' => [
                    ['id' => 'product', 'name' => 'Single product'],
                    ['id' => 'category', 'name' => 'Product category'],
                    ['id' => 'flagged', 'name' => 'Products marked on their edit screen'],
                    ['id' => 'all', 'name' => 'All eligible virtual products'],
                ], 'id' => 'id', 'name' => 'name'],
            ], [
                'type' => 'text',
                'name' => 'blocksign_category',
                'label' => 'Category ID (category scope)',
            ], [
                'type' => 'text',
                'name' => self::PRODUCT,
                'label' => 'Protected product ID',
                'desc' => 'Use a virtual product with no native download or attachment. In single-product scope, enter 0 to disable new requests.',
            ], [
                'type' => 'select',
                'name' => 'blocksign_level',
                'label' => 'Signature level or proof of delivery',
                'options' => ['query' => [
                    ['id' => 'aes', 'name' => 'EES'],
                    ['id' => 'fes', 'name' => 'FES'],
                    ['id' => 'qes', 'name' => 'QES'],
                    ['id' => 'pod', 'name' => 'Proof of delivery'],
                ], 'id' => 'id', 'name' => 'name'],
            ], [
                'type' => 'text', 'name' => 'blocksign_page', 'label' => 'PDF page (starting at 1)',
            ], [
                'type' => 'text', 'name' => 'blocksign_x', 'label' => 'X from left',
            ], [
                'type' => 'text', 'name' => 'blocksign_y', 'label' => 'Y from top',
            ], [
                'type' => 'textarea',
                'name' => self::DOCUMENTS,
                'label' => 'Additional required PDFs',
                'desc' => 'One private PDF per line, for example: mandate.pdf | EES | 1 | 50 | 50',
                'rows' => 5,
            ], [
                'type' => 'textarea',
                'name' => self::CATEGORY_DOCUMENTS,
                'label' => 'Additional PDFs by product category',
                'desc' => 'Example: 21 | mandate.pdf | EES | 1 | 50 | 50',
                'rows' => 5,
            ]],
            'submit' => ['title' => 'Save'],
        ]]]);
    }

    private static function productScope(?int $shopId = null): array
    {
        $stored = Configuration::get(self::SCOPE, null, null, $shopId);
        return $stored ? ProductScope::validate(json_decode($stored, true, 512, JSON_THROW_ON_ERROR)) : ProductScope::DEFAULTS;
    }

    private static function signatureSettings(?int $shopId = null): array
    {
        $stored = Configuration::get(self::SIGNATURE, null, null, $shopId);
        return $stored ? SignatureSettings::validate(json_decode($stored, true, 512, JSON_THROW_ON_ERROR)) : SignatureSettings::DEFAULTS;
    }

    private static function fileSnapshots(array $items, int $shopId, int $languageId): array
    {
        $scope = self::productScope($shopId);
        $configuredProduct = (int) Configuration::get(self::PRODUCT, null, null, $shopId);
        $products = [];
        $placement = $additional = null;
        $categoryDocuments = CategoryDocuments::parse((string) Configuration::get(self::CATEGORY_DOCUMENTS, null, null, $shopId));
        foreach ($items as $item) {
            $productId = (int) $item['product_id'];
            if (isset($products[$productId]) || (int) ($item['product_attribute_id'] ?? 0) !== 0) {
                continue;
            }
            $product = new Product($productId, false, null, $shopId);
            $categories = $scope['mode'] === 'category' || $categoryDocuments ? $product->getCategories() : [];
            $flagged = $scope['mode'] === 'flagged' && self::productMarked($productId, $shopId);
            $shipping = !$product->is_virtual && self::shippingMarked($productId, $shopId);
            if (!$shipping && (!$product->is_virtual
                || !ProductScope::matches($scope, $configuredProduct, $productId, $categories, $flagged)
                || ProductDownload::getIdFromIdProduct($productId) || $product->getAttachments($languageId))) {
                continue;
            }
            $products[$productId] = ProductFiles::snapshot(
                self::productFiles($productId, $shopId),
                $placement ??= self::signatureSettings($shopId),
                CategoryDocuments::forProduct(
                    $additional ??= RequiredSignatures::parseAdditional((string) Configuration::get(self::DOCUMENTS, null, null, $shopId)),
                    $categoryDocuments,
                    $categories
                ),
                self::asset(...),
                !$shipping
            ) + ['shipping' => $shipping];
        }
        return $products;
    }

    private function checkoutProof(): ?array
    {
        $cart = $this->context->cart;
        if (!$cart instanceof Cart || !(int) $cart->id
            || !Configuration::get(self::PREORDER, null, null, (int) $cart->id_shop)) {
            return null;
        }
        $items = [];
        $lines = [];
        foreach ($cart->getProducts() as $item) {
            $items[] = [
                'product_id' => (int) $item['id_product'],
                'product_attribute_id' => (int) ($item['id_product_attribute'] ?? 0),
            ];
            $lines[] = [(int) $item['id_product'], (int) ($item['id_product_attribute'] ?? 0), (int) $item['cart_quantity']];
        }
        sort($lines);
        $products = self::fileSnapshots($items, (int) $cart->id_shop, (int) $cart->id_lang);
        if (!$products) {
            return null;
        }

        $customer = $this->context->customer;
        if (!$customer instanceof Customer || (int) $customer->id !== (int) $cart->id_customer
            || !$customer->isLogged(true) || !Validate::isEmail($customer->email)) {
            throw new RuntimeException('Enter your customer details before requesting BlockSign confirmation.');
        }

        $state = OrderFiles::combine($products) + ['timing' => 'before_order', 'signer_email' => $customer->email];
        $identity = [(int) $cart->id, (int) $cart->id_customer, (int) $cart->id_currency, (int) $cart->id_shop,
            (string) $cart->getOrderTotal(true, Cart::BOTH), $lines, $state];
        return [hash_hmac('sha256', json_encode($identity, JSON_THROW_ON_ERROR), _COOKIE_KEY_), $state];
    }

    private static function preorderRow(string $key): ?array
    {
        $row = Db::getInstance()->getRow(
            "SELECT * FROM `" . _DB_PREFIX_ . "blocksign_esign_preorder` WHERE fingerprint = '" . pSQL($key) . "' AND expires > " . time(),
            false
        );
        return is_array($row) ? $row : null;
    }

    private static function readyProof(?array $row): ?array
    {
        if (!$row || (int) $row['used']) {
            return null;
        }
        $state = json_decode($row['state'], true, 512, JSON_THROW_ON_ERROR);
        return RequiredSignatures::complete($state) ? $state : null;
    }

    public function hookDisplayPaymentTop(): string
    {
        try {
            $proof = $this->checkoutProof();
            if (!$proof) {
                return '';
            }
            $row = self::preorderRow($proof[0]);
            if (self::readyProof($row)) {
                return '<p>BlockSign confirmation is complete. You can now place the order.</p>';
            }
            if ($row) {
                return (int) $row['used']
                    ? '<p>This BlockSign confirmation has already been used. Check your orders or contact the shop.</p>'
                    : '<p>Check your email for BlockSign. Refresh the checkout after confirming every required document.</p>';
            }
            $action = self::escape($this->context->link->getModuleLink($this->name, 'preorder', [], true));
            return '<form method="post" action="' . $action . '"><input type="hidden" name="token" value="'
                . self::escape(Tools::getToken(false)) . '"><button type="submit">Request BlockSign confirmation</button></form>';
        } catch (Throwable $error) {
            return '<p>BlockSign confirmation is unavailable. Contact the shop before placing the order.</p>';
        }
    }

    public function hookActionPresentPaymentOptions(array $params): void
    {
        try {
            $proof = $this->checkoutProof();
            if (!$proof) {
                return;
            }
            $params['paymentOptions'] = self::readyProof(self::preorderRow($proof[0]))
                ? array_intersect_key($params['paymentOptions'], array_fill_keys([...self::OFFLINE_MODULES, 'free_order'], true))
                : [];
        } catch (Throwable $error) {
            $params['paymentOptions'] = [];
        }
    }

    public function startCheckoutProof(): void
    {
        $proof = $this->checkoutProof();
        if (!$proof || $this->context->cart->OrderExists()) {
            throw new RuntimeException('No eligible checkout is available.');
        }

        [$key, $state] = $proof;
        $db = Db::getInstance();
        $db->delete('blocksign_esign_preorder', "fingerprint = '" . pSQL($key) . "' AND expires <= " . time());
        if (!$db->insert('blocksign_esign_preorder', [
            'fingerprint' => pSQL($key),
            'id_shop' => (int) $this->context->cart->id_shop,
            'state' => pSQL(json_encode($state, JSON_THROW_ON_ERROR), true),
            'next_attempt' => time(),
            'expires' => time() + 604800,
        ], false, true, Db::INSERT_IGNORE)) {
            throw new RuntimeException('Could not start BlockSign confirmation.');
        }
    }

    public function hookActionValidateOrderBefore(array $params): void
    {
        try {
            $proof = $this->checkoutProof();
            if (!$proof) {
                return;
            }
            $cart = $this->context->cart;
            $module = $this->context->controller->module->name ?? '';
            $free = (float) $cart->getOrderTotal(true, Cart::BOTH) === 0.0
                && $this->context->controller instanceof OrderConfirmationController && (bool) Tools::getValue('free_order');
            $state = self::readyProof(self::preorderRow($proof[0]));
            if (!$state || (!$free && !in_array($module, self::OFFLINE_MODULES, true))) {
                throw new RuntimeException('BlockSign checkout confirmation is required.');
            }

            $db = Db::getInstance();
            if (!$db->update('blocksign_esign_preorder', ['used' => 1],
                "fingerprint = '" . pSQL($proof[0]) . "' AND used = 0 AND expires > " . time()) || (int) $db->Affected_Rows() !== 1) {
                throw new RuntimeException('BlockSign checkout confirmation has already been used.');
            }
            self::$approvedCarts[(int) $cart->id] = $state;
        } catch (Throwable $error) {
            // PrestaShop can swallow ordinary hook exceptions in production.
            Tools::redirect($this->context->link->getPageLink('order', true));
        }
    }

    public function hookActionValidateOrderAfter(array $params): void
    {
        foreach ($params['orders'] ?? [] as $order) {
            $shopId = (int) $order->id_shop;
            $total = (float) $order->total_paid_tax_incl;
            $timing = $total > 0.0 ? 'after_payment' : 'without_payment';
            if (!self::matchesTiming($order, $timing)) {
                continue;
            }
            $customer = $params['customer'];
            if ((int) $customer->id !== (int) $order->id_customer || !Validate::isEmail($customer->email)) {
                continue;
            }

            try {
                $items = $order->getProductsDetail();
                $approved = self::$approvedCarts[(int) ($order->id_cart ?? 0)] ?? [];
                if ($approved) {
                    $ids = array_map('intval', array_column($items, 'product_id'));
                    $downloads = array_intersect_key($approved['downloads'] ?? [], array_flip($ids));
                    $shipping = array_values(array_intersect($approved['shipping_products'] ?? [], $ids));
                    if (!$downloads && !$shipping) {
                        continue;
                    }
                    $state = array_replace($approved, ['downloads' => $downloads, 'shipping_products' => $shipping]);
                } else {
                    $products = self::fileSnapshots($items, $shopId, (int) $order->id_lang);
                    if (!$products) {
                        continue;
                    }
                    if (Configuration::get(self::PREORDER, null, null, $shopId)) {
                        throw new RuntimeException('Protected order reached registration without an approved checkout.');
                    }
                    $state = OrderFiles::combine($products) + [
                        'timing' => $timing,
                        'signer_email' => $customer->email,
                    ];
                }
                $saved = Db::getInstance()->insert('blocksign_esign', [
                    'id_order' => (int) $order->id,
                    'id_shop' => (int) $order->id_shop,
                    'access_token' => bin2hex(random_bytes(32)),
                    'state' => pSQL(json_encode($state, JSON_THROW_ON_ERROR), true),
                    'next_attempt' => time(),
                    'created_at' => time(),
                ], false, true, Db::INSERT_IGNORE);
                if (!$saved) {
                    throw new RuntimeException('Could not save the signature workflow.');
                }
            } catch (Throwable $error) {
                PrestaShopLogger::addLog('BlockSign could not register the order for signing.', 3, null, 'Order', (int) $order->id);
            }
        }
    }

    public function hookDisplayOrderConfirmation(array $params): string
    {
        return $this->orderLink($params['order']);
    }

    public function hookDisplayOrderDetail(array $params): string
    {
        return $this->orderLink($params['order']);
    }

    private function orderLink(Order $order): string
    {
        $row = self::findOrder((int) $order->id);
        if (!$row || (int) $row['id_shop'] !== (int) $order->id_shop) {
            return '';
        }
        $url = $this->context->link->getModuleLink($this->name, 'document', [
            'order' => (int) $order->id,
            'token' => $row['access_token'],
        ], true, null, (int) $order->id_shop);

        return '<p><a rel="noreferrer" href="' . self::escape($url) . '">BlockSign: signature status and download</a></p>';
    }

    public static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function findOrder(int $id): ?array
    {
        $row = Db::getInstance()->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . 'blocksign_esign` WHERE id_order = ' . $id,
            false
        );
        return is_array($row) ? $row : null;
    }

    public static function orderEligible(Order $order, array $state): bool
    {
        if (!Validate::isLoadedObject($order) || !self::matchesTiming($order, $state['timing'] ?? 'without_payment')) {
            return false;
        }
        foreach (['PS_OS_CANCELED', 'PS_OS_REFUND', 'PS_OS_ERROR'] as $key) {
            if ((int) $order->current_state === (int) Configuration::get($key, null, null, (int) $order->id_shop)) {
                return false;
            }
        }
        $protected = OrderFiles::downloads($state) + array_fill_keys($state['shipping_products'] ?? [], true);
        if (!$protected) {
            return false;
        }
        $valid = [];
        foreach ($order->getProductsDetail() as $item) {
            $id = (int) $item['product_id'];
            if (isset($protected[$id]) && (int) ($item['product_attribute_id'] ?? 0) === 0) {
                if ((int) $item['product_quantity_refunded'] > 0 || (int) $item['product_quantity'] < 1) {
                    return false;
                }
                $valid[$id] = true;
            }
        }
        return count($valid) === count($protected);
    }

    private static function matchesTiming(Order $order, string $timing): bool
    {
        $total = (float) $order->total_paid_tax_incl;
        return match ($timing) {
            'without_payment' => $total === 0.0 && $order->module === 'free_order',
            'before_order' => ($total === 0.0 && $order->module === 'free_order')
                || ($total > 0.0 && in_array($order->module, self::OFFLINE_MODULES, true)),
            'after_payment' => $total > 0.0 && !in_array($order->module, ['free_order', 'ps_cashondelivery', 'cashondelivery'], true),
            default => false,
        };
    }

    public static function paymentReady(Order $order, array $state): bool
    {
        if (($state['timing'] ?? 'without_payment') === 'without_payment'
            || (($state['timing'] ?? '') === 'before_order' && (float) $order->total_paid_tax_incl === 0.0)) {
            return true;
        }
        $status = $order->getCurrentOrderState();
        return $status && (bool) $status->paid;
    }

    public static function downloadAllowed(Order $order, array $state): bool
    {
        return self::orderEligible($order, $state) && self::paymentReady($order, $state)
            && Gate::allows('fulfillment', [['timing' => $state['timing'] ?? 'without_payment', 'completed' => RequiredSignatures::complete($state)]]);
    }

    public function hookActionOrderStatusUpdate(array $params): void
    {
        $target = $params['newOrderStatus'];
        $readyStatus = (int) Configuration::getGlobalValue(self::SHIPPING_STATUS);
        $readyTarget = $readyStatus > 0 && (int) $target->id === $readyStatus;
        if (!$readyTarget && !$target->shipped && !$target->delivery) {
            return;
        }

        $order = new Order((int) $params['id_order']);
        $row = self::findOrder((int) $order->id);
        $state = $row && (int) $row['id_shop'] === (int) $order->id_shop
            ? json_decode($row['state'], true, 512, JSON_THROW_ON_ERROR) : [];
        if (!empty($state['shipping_products']) && self::downloadAllowed($order, $state)) {
            return;
        }
        if ($readyTarget || !empty($state['shipping_products'])) {
            throw new ModuleErrorException('BlockSign confirmation and payment are required before shipping this order.');
        }
        if (!$state) {
            $createdAt = strtotime((string) $order->date_add);
            foreach ($order->getProductsDetail() as $item) {
                $id = (int) $item['product_id'];
                $enabledAt = self::shippingEnabledAt($id, (int) $order->id_shop);
                if ((int) ($item['product_attribute_id'] ?? 0) === 0 && $enabledAt > 0
                    && (!$createdAt || $createdAt >= $enabledAt)
                    && !(new Product($id, false, null, (int) $order->id_shop))->is_virtual) {
                    throw new ModuleErrorException('This order is missing the BlockSign confirmation required for shipping.');
                }
            }
        }
    }

    private static function releaseShipping(Order $order, array $state): void
    {
        $readyStatus = (int) Configuration::getGlobalValue(self::SHIPPING_STATUS);
        $current = $order->getCurrentOrderState();
        if (!$readyStatus || (int) $order->current_state === $readyStatus
            || ($current && ($current->shipped || $current->delivery)) || !self::downloadAllowed($order, $state)) {
            return;
        }
        $history = new OrderHistory();
        $history->id_order = (int) $order->id;
        $history->changeIdOrderState($readyStatus, $order, true);
        if (!$history->add()) {
            throw new RuntimeException('Could not save the shipping status history.');
        }
    }

    public static function asset(string $name): string
    {
        $directory = realpath(getenv('BLOCKSIGN_ESIGN_FILES') ?: '/nonexistent');
        $documentRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
        $root = realpath($documentRoot !== '' ? $documentRoot : _PS_ROOT_DIR_);
        $file = $directory ? realpath($directory . '/' . $name) : false;
        if (!$root || !$file || dirname($file) !== $directory
            || str_starts_with($file, rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)
            || !is_file($file) || !is_readable($file)) {
            throw new RuntimeException('BlockSign private file unavailable.');
        }
        return $file;
    }

    private static function client(string $apiKey): Client
    {
        return new Client(static function (string $method, string $path, string $type, string $body) use ($apiKey): array|string {
            $curl = curl_init('https://blocksign.io' . $path);
            $response = '';
            $limit = $type === 'application/pdf' ? Client::MAX_SIGNED_PDF_BYTES : 1048576;
            curl_setopt_array($curl, [
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $apiKey, 'Content-Type: ' . $type, ($type === 'application/pdf' ? 'Accept: application/pdf' : 'Accept: application/json')],
                CURLOPT_TIMEOUT => 25,
                CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$response, $limit): int {
                    if (strlen($response) + strlen($chunk) > $limit) {
                        return 0;
                    }
                    $response .= $chunk;
                    return strlen($chunk);
                },
            ]);
            if ($method === 'POST') {
                curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
            }
            $result = curl_exec($curl);
            $code = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            if ($result === false || $code < 200 || $code >= 300) {
                throw new RuntimeException('BlockSign API request failed.', $result === false ? 0 : $code);
            }
            if ($type === 'application/pdf') {
                return $response;
            }
            $decoded = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($decoded)) {
                throw new RuntimeException('Unexpected BlockSign response.');
            }
            return $decoded;
        });
    }

    public static function processQueue(): void
    {
        $apiKey = getenv('BLOCKSIGN_ESIGN_API_KEY');
        if (!$apiKey || preg_match('/[\r\n]/', $apiKey)) {
            throw new RuntimeException('Configure the BlockSign API key in the worker environment.');
        }
        $db = Db::getInstance();
        $lock = 'blocksign_esign_' . substr(hash('sha256', _DB_NAME_ . ':' . _DB_PREFIX_), 0, 32);
        if ((int) $db->getValue("SELECT GET_LOCK('$lock', 0)", false) !== 1) {
            return;
        }

        try {
            $rows = $db->executeS(
                'SELECT q.id_order FROM `' . _DB_PREFIX_ . 'blocksign_esign` q
                INNER JOIN `' . _DB_PREFIX_ . 'module_shop` ms ON ms.id_shop = q.id_shop
                INNER JOIN `' . _DB_PREFIX_ . "module` m ON m.id_module = ms.id_module AND m.name = 'blocksignesign'
                INNER JOIN `" . _DB_PREFIX_ . "orders` o ON o.id_order = q.id_order AND o.id_shop = q.id_shop
                LEFT JOIN `" . _DB_PREFIX_ . "order_state` os ON os.id_order_state = o.current_state
                WHERE ((o.total_paid_tax_incl = 0 AND o.module = 'free_order') OR (o.total_paid_tax_incl > 0 AND os.paid = 1))
                AND q.next_attempt BETWEEN 1 AND " . time() . ' ORDER BY q.next_attempt, q.id_order LIMIT 20',
                true,
                false
            );
            $client = self::client($apiKey);
            $workflow = new SignatureWorkflow($client);
            self::processPreorders($workflow);
            $archive = getenv('BLOCKSIGN_ESIGN_ARCHIVE') ? new EvidenceArchive($client) : null;
            foreach ($rows as $candidate) {
                self::processOrder((int) $candidate['id_order'], $workflow, $archive);
            }
        } finally {
            $db->getValue("SELECT RELEASE_LOCK('$lock')", false);
        }
    }

    private static function processPreorders(SignatureWorkflow $workflow): void
    {
        $db = Db::getInstance();
        $now = time();
        $db->delete('blocksign_esign_preorder', 'expires <= ' . $now);
        $rows = $db->executeS(
            'SELECT q.* FROM `' . _DB_PREFIX_ . 'blocksign_esign_preorder` q
            INNER JOIN `' . _DB_PREFIX_ . 'module_shop` ms ON ms.id_shop = q.id_shop
            INNER JOIN `' . _DB_PREFIX_ . "module` m ON m.id_module = ms.id_module AND m.name = 'blocksignesign'
            WHERE q.used = 0 AND q.expires > $now AND q.next_attempt BETWEEN 1 AND $now ORDER BY q.next_attempt LIMIT 20",
            true,
            false
        );
        foreach ($rows as $row) {
            if (!Configuration::get(self::PREORDER, null, null, (int) $row['id_shop'])) {
                $db->update('blocksign_esign_preorder', ['next_attempt' => time() + 3600], "fingerprint = '" . pSQL($row['fingerprint']) . "'");
                continue;
            }
            $where = "fingerprint = '" . pSQL($row['fingerprint']) . "' AND used = 0";
            $state = json_decode($row['state'], true, 512, JSON_THROW_ON_ERROR);
            try {
                $retry = RequiredSignatures::advance(
                    $workflow,
                    $state,
                    static function (array $next) use ($db, $where, &$state): void {
                        if (!$db->update('blocksign_esign_preorder', ['state' => pSQL(json_encode($next, JSON_THROW_ON_ERROR), true)], $where)) {
                            throw new RuntimeException('Could not persist BlockSign checkout progress.');
                        }
                        $state = $next;
                    },
                    static fn (string $file) => file_get_contents(self::asset($file), false, null, 0, 10 * 1024 * 1024 + 1)
                );
            } catch (Throwable $error) {
                $retry = !RequiredSignatures::needsReview($state);
            }
            $db->update('blocksign_esign_preorder', ['next_attempt' => $retry ? time() + 120 : 0], $where);
        }
    }

    public static function processOrder(int $id, SignatureWorkflow $workflow, ?EvidenceArchive $archive = null): void
    {
        $row = self::findOrder($id);
        if (!$row) {
            return;
        }
        $order = new Order($id);
        $state = json_decode($row['state'], true, 512, JSON_THROW_ON_ERROR);
        $validOrder = (int) $row['id_shop'] === (int) $order->id_shop && self::orderEligible($order, $state);
        if ($validOrder && !self::paymentReady($order, $state)) {
            return;
        }

        $retry = false;
        $started = $state['started_at'] ?? (int) $row['created_at'];
        if ($validOrder && !RequiredSignatures::complete($state) && !RequiredSignatures::needsReview($state)) {
            if (($state['timing'] ?? '') === 'after_payment' && !isset($state['started_at'])) {
                $started = $state['started_at'] = time();
                self::updateRow($id, ['state' => pSQL(json_encode($state, JSON_THROW_ON_ERROR), true)]);
            }
            if (time() - $started >= 604800) {
                self::updateRow($id, ['next_attempt' => 0]);
                return;
            }
            try {
                $retry = RequiredSignatures::advance(
                    $workflow,
                    $state,
                    static function (array $next) use ($id, &$state): void {
                        self::updateRow($id, ['state' => pSQL(json_encode($next, JSON_THROW_ON_ERROR), true)]);
                        $state = $next;
                    },
                    static fn (string $file) => file_get_contents(self::asset($file), false, null, 0, 10 * 1024 * 1024 + 1)
                );
            } catch (Throwable $error) {
                $retry = !RequiredSignatures::needsReview($state);
                if (!$retry) {
                    PrestaShopLogger::addLog('BlockSign creation needs manual review.', 3, null, 'Order', $id);
                }
            }
        }
        if ($validOrder && !empty($state['shipping_products']) && RequiredSignatures::complete($state)) {
            try {
                self::releaseShipping(new Order($id), $state);
            } catch (Throwable $error) {
                PrestaShopLogger::addLog('BlockSign could not release the order for shipping.', 2, null, 'Order', $id);
                $retry = time() - $started < 604800;
            }
        }
        if ($validOrder && $archive && RequiredSignatures::complete($state) && time() - $started < 604800) {
            try {
                $root = ($_SERVER['DOCUMENT_ROOT'] ?? '') ?: _PS_ROOT_DIR_;
                $retry = $archive->storeNext(
                    (string) getenv('BLOCKSIGN_ESIGN_ARCHIVE'), $root, 'prestashop-' . (int) $order->id_shop . '-' . $id, $state
                ) || $retry;
            } catch (Throwable $error) {
                PrestaShopLogger::addLog('BlockSign evidence could not be archived.', 2, null, 'Order', $id);
                $retry = true;
            }
        }
        self::updateRow($id, ['next_attempt' => $retry ? time() + 120 : 0]);
    }

    private static function updateRow(int $id, array $data): void
    {
        if (!Db::getInstance()->update('blocksign_esign', $data, 'id_order = ' . $id)) {
            throw new RuntimeException('Could not save the BlockSign order state.');
        }
    }
}
