<?php

use BlockSign\ESign\Client;
use BlockSign\ESign\Gate;
use BlockSign\ESign\RequiredSignatures;
use BlockSign\ESign\SignatureSettings;
use BlockSign\ESign\SignatureWorkflow;

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/core/Client.php';
require_once __DIR__ . '/core/Gate.php';
require_once __DIR__ . '/core/SignatureSettings.php';
require_once __DIR__ . '/core/SignatureWorkflow.php';
require_once __DIR__ . '/core/RequiredSignatures.php';

class BlocksignEsign extends Module
{
    private const PRODUCT = 'BLOCKSIGN_ESIGN_PRODUCT';
    private const SIGNATURE = 'BLOCKSIGN_ESIGN_SIGNATURE';
    private const DOCUMENTS = 'BLOCKSIGN_ESIGN_DOCUMENTS';

    public function __construct()
    {
        $this->name = 'blocksignesign';
        $this->version = '0.3.0';
        $this->author = 'BlockSign';
        $this->tab = 'administration';
        $this->ps_versions_compliancy = ['min' => '9.0.0', 'max' => _PS_VERSION_];
        parent::__construct();
        $this->displayName = 'BlockSign eSign';
        $this->description = 'Release a private PDF after BlockSign confirms its signature.';
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
            && $this->registerHook(['actionValidateOrderAfter', 'displayOrderConfirmation', 'displayOrderDetail']);
    }

    public function getContent()
    {
        $output = '';
        if (Tools::isSubmit('submitBlocksignEsign')) {
            $productId = filter_var(Tools::getValue(self::PRODUCT), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
            try {
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
                if (!Configuration::updateValue(self::DOCUMENTS, trim($documents))
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
        $form->fields_value = [
            self::PRODUCT => (int) Configuration::get(self::PRODUCT),
            self::DOCUMENTS => (string) Configuration::get(self::DOCUMENTS),
            'blocksign_level' => $settings['signature_level'],
            'blocksign_page' => $settings['page'],
            'blocksign_x' => $settings['x'],
            'blocksign_y' => $settings['y'],
        ];
        return $output . $form->generateForm([['form' => [
            'legend' => ['title' => 'BlockSign eSign'],
            'input' => [[
                'type' => 'text',
                'name' => self::PRODUCT,
                'label' => 'Protected product ID',
                'desc' => 'Use a virtual product with no native download or attachment. Enter 0 to disable new requests.',
            ], [
                'type' => 'select',
                'name' => 'blocksign_level',
                'label' => 'Signature level',
                'options' => ['query' => [
                    ['id' => 'aes', 'name' => 'EES'],
                    ['id' => 'fes', 'name' => 'FES'],
                    ['id' => 'qes', 'name' => 'QES'],
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
            ]],
            'submit' => ['title' => 'Save'],
        ]]]);
    }

    private static function signatureSettings(?int $shopId = null): array
    {
        $stored = Configuration::get(self::SIGNATURE, null, null, $shopId);
        return $stored ? SignatureSettings::validate(json_decode($stored, true, 512, JSON_THROW_ON_ERROR)) : SignatureSettings::DEFAULTS;
    }

    public function hookActionValidateOrderAfter(array $params): void
    {
        foreach ($params['orders'] ?? [] as $order) {
            $productId = (int) Configuration::get(self::PRODUCT, null, null, (int) $order->id_shop);
            $total = (float) $order->total_paid_tax_incl;
            $timing = $total > 0.0 ? 'after_payment' : 'without_payment';
            if (!$productId || !self::matchesTiming($order, $timing)) {
                continue;
            }
            $items = $order->getProductsDetail();
            $item = reset($items);
            if (count($items) !== 1 || (int) $item['product_id'] !== $productId || (int) $item['product_quantity'] !== 1) {
                continue;
            }

            $product = new Product($productId, false, null, (int) $order->id_shop);
            $customer = $params['customer'];
            if (!$product->is_virtual || (int) $customer->id !== (int) $order->id_customer || !Validate::isEmail($customer->email)) {
                continue;
            }

            try {
                $download = hash_file('sha256', self::asset('download.pdf'));
                if (!$download) {
                    throw new RuntimeException('Could not read the private files.');
                }
                $state = [
                    'product_id' => $productId,
                    'timing' => $timing,
                    'download_hash' => $download,
                    'signer_email' => $customer->email,
                    'documents' => RequiredSignatures::snapshot(
                        self::signatureSettings((int) $order->id_shop),
                        RequiredSignatures::parseAdditional((string) Configuration::get(self::DOCUMENTS, null, null, (int) $order->id_shop)),
                        self::asset(...)
                    ),
                ];
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
        $items = $order->getProductsDetail();
        $item = reset($items);
        return count($items) === 1 && (int) $item['product_id'] === $state['product_id']
            && (int) $item['product_quantity'] === 1 && (int) $item['product_quantity_refunded'] === 0;
    }

    private static function matchesTiming(Order $order, string $timing): bool
    {
        $total = (float) $order->total_paid_tax_incl;
        return match ($timing) {
            'without_payment' => $total === 0.0 && $order->module === 'free_order',
            'after_payment' => $total > 0.0 && !in_array($order->module, ['free_order', 'ps_cashondelivery', 'cashondelivery'], true),
            default => false,
        };
    }

    public static function paymentReady(Order $order, array $state): bool
    {
        if (($state['timing'] ?? 'without_payment') === 'without_payment') {
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

    private static function workflow(string $apiKey): SignatureWorkflow
    {
        return new SignatureWorkflow(new Client(static function (string $method, string $path, string $type, string $body) use ($apiKey): array {
            $curl = curl_init('https://blocksign.io' . $path);
            $response = '';
            curl_setopt_array($curl, [
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $apiKey, 'Content-Type: ' . $type, 'Accept: application/json'],
                CURLOPT_TIMEOUT => 25,
                CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$response): int {
                    if (strlen($response) + strlen($chunk) > 1048576) {
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
            $decoded = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($decoded)) {
                throw new RuntimeException('Unexpected BlockSign response.');
            }
            return $decoded;
        }));
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
            foreach ($rows as $candidate) {
                self::processOrder((int) $candidate['id_order'], self::workflow($apiKey));
            }
        } finally {
            $db->getValue("SELECT RELEASE_LOCK('$lock')", false);
        }
    }

    public static function processOrder(int $id, SignatureWorkflow $workflow): void
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
        self::updateRow($id, ['next_attempt' => $retry ? time() + 120 : 0]);
    }

    private static function updateRow(int $id, array $data): void
    {
        if (!Db::getInstance()->update('blocksign_esign', $data, 'id_order = ' . $id)) {
            throw new RuntimeException('Could not save the BlockSign order state.');
        }
    }
}
