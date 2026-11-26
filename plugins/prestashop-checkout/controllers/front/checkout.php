<?php

declare(strict_types=1);

use BlockSign\PrestaCheckout\StripeCheckout;

if (!defined('_PS_VERSION_')) {
    exit;
}

final class BlocksignCheckoutCheckoutModuleFrontController extends ModuleFrontController
{
    public $ssl = true;

    public function postProcess(): void
    {
        header('Cache-Control: private, no-store');
        header('Referrer-Policy: no-referrer');
        $method = $_SERVER['REQUEST_METHOD'] ?? '';
        $statusUrl = null;
        try {
            if ($method === 'GET') {
                $id = filter_var(Tools::getValue('order'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if (!$id) {
                    throw new RuntimeException('Missing order.');
                }
                $order = new Order($id);
                $row = $this->module->view($order);
            } elseif ($method === 'POST') {
                $token = $_POST['checkout_token'] ?? null;
                if (!is_string($token) || !hash_equals(Tools::getToken(false), $token)) {
                    http_response_code(403);
                    exit('Invalid checkout request.');
                }
                if (isset($_POST['order'])) {
                    $id = filter_var($_POST['order'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                    $orderToken = $_POST['order_token'] ?? '';
                    if (!$id || !is_string($orderToken) || !preg_match('/\A[a-f0-9]{64}\z/', $orderToken)) {
                        throw new RuntimeException('Invalid order.');
                    }
                    $order = new Order($id);
                    $row = $this->module->view($order, $orderToken);
                } else {
                    $order = $this->module->createOrder($this->context->cart);
                    $row = $this->module->view($order);
                }
            } else {
                http_response_code(405);
                exit;
            }

            $id = (int) $order->id;
            $statusUrl = $this->context->link->getModuleLink('blocksignesign', 'document', [
                'order' => $id, 'token' => $row['access_token'],
            ], true);
            if ($method === 'POST') {
                $returnUrl = $this->context->link->getModuleLink($this->module->name, 'checkout', ['order' => $id], true, (int) $order->id_lang, (int) $order->id_shop);
                $url = $this->module->paymentLink($order, $returnUrl, StripeCheckout::client());
                if ($url !== null) {
                    Tools::redirect($url);
                }
            }
        } catch (Throwable $error) {
            if ($statusUrl === null) {
                http_response_code(409);
                exit('Online-Zahlung nicht verfügbar. Öffne die Bestellung im Shop oder kontaktiere den Shop.');
            }
            PrestaShopLogger::addLog('BlockSign Checkout could not open the payment session.', 2, null, 'Order', (int) $order->id);
        }
        Tools::redirect($statusUrl);
    }
}
