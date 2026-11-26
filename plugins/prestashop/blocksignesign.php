<?php

use BlockSign\ESign\Client;
use BlockSign\ESign\Gate;
use BlockSign\ESign\SignatureWorkflow;

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/core/Client.php';
require_once __DIR__ . '/core/Gate.php';
require_once __DIR__ . '/core/SignatureWorkflow.php';

class BlocksignEsign extends Module
{
    private const PRODUCT = 'BLOCKSIGN_ESIGN_PRODUCT';

    public function __construct()
    {
        $this->name = 'blocksignesign';
        $this->version = '0.1.0';
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
            if ($productId === false || !Configuration::updateValue(self::PRODUCT, $productId)) {
                $output = $this->displayError('Enter a valid product ID.');
            } else {
                $output = $this->displayConfirmation('Settings saved.');
            }
        }

        $form = new HelperForm();
        $form->module = $this;
        $form->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $form->token = Tools::getAdminTokenLite('AdminModules');
        $form->submit_action = 'submitBlocksignEsign';
        $form->fields_value = [self::PRODUCT => (int) Configuration::get(self::PRODUCT)];
        return $output . $form->generateForm([['form' => [
            'legend' => ['title' => 'BlockSign eSign'],
            'input' => [[
                'type' => 'text',
                'name' => self::PRODUCT,
                'label' => 'Protected product ID',
                'desc' => 'Use a free virtual product with no native download or attachment. Enter 0 to disable new requests.',
            ]],
            'submit' => ['title' => 'Save'],
        ]]]);
    }

    public function hookActionValidateOrderAfter(array $params): void
    {
        foreach ($params['orders'] ?? [] as $order) {
            $productId = (int) Configuration::get(self::PRODUCT, null, null, (int) $order->id_shop);
            if (!$productId || $order->module !== 'free_order' || (float) $order->total_paid_tax_incl !== 0.0) {
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
                $agreement = hash_file('sha256', self::asset('agreement.pdf'));
                $download = hash_file('sha256', self::asset('download.pdf'));
                if (!$agreement || !$download) {
                    throw new RuntimeException('Could not read the private files.');
                }
                $state = [
                    'product_id' => $productId,
                    'agreement_hash' => $agreement,
                    'download_hash' => $download,
                    'signer_email' => $customer->email,
                    'phase' => 'upload',
                    'completed' => false,
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
        if (!Validate::isLoadedObject($order) || $order->module !== 'free_order'
            || (float) $order->total_paid_tax_incl !== 0.0) {
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

    public static function downloadAllowed(Order $order, array $state): bool
    {
        return self::orderEligible($order, $state)
            && Gate::allows('fulfillment', [['timing' => 'without_payment', 'completed' => $state['completed']]]);
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
                WHERE q.next_attempt BETWEEN 1 AND " . time() . ' ORDER BY q.next_attempt, q.id_order LIMIT 20',
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
        $retry = false;
        if ((int) $row['id_shop'] === (int) $order->id_shop
            && self::orderEligible($order, $state) && !$state['completed']
            && !str_ends_with($state['phase'], '_pending_review') && time() - (int) $row['created_at'] < 604800) {
            try {
                $retry = $workflow->advance(
                    $state,
                    static function (array $next) use ($id, &$state): void {
                        self::updateRow($id, ['state' => pSQL(json_encode($next, JSON_THROW_ON_ERROR), true)]);
                        $state = $next;
                    },
                    static fn () => file_get_contents(self::asset('agreement.pdf'), false, null, 0, 10 * 1024 * 1024 + 1)
                );
            } catch (Throwable $error) {
                $retry = !str_ends_with($state['phase'], '_pending_review');
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
