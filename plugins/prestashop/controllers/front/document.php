<?php

use BlockSign\ESign\OrderFiles;

if (!defined('_PS_VERSION_')) {
    exit;
}

class BlocksignEsignDocumentModuleFrontController extends ModuleFrontController
{
    public $ssl = true;

    public function initContent(): void
    {
        header('Cache-Control: private, no-store, max-age=0');
        header('Referrer-Policy: no-referrer');
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'; base-uri 'none'");

        $orderId = (int) Tools::getValue('order', 0);
        $token = Tools::getValue('token', '');
        if ($orderId <= 0 || !is_string($token) || !preg_match('/^[0-9a-f]{64}$/D', $token)) {
            $this->unavailable();
        }

        $row = BlocksignEsign::findOrder($orderId);
        if (!$row || (int) $row['id_shop'] !== (int) $this->context->shop->id
            || !hash_equals($row['access_token'], $token)) {
            $this->unavailable();
        }

        $order = new Order($orderId);
        if ((int) $order->id_shop !== (int) $row['id_shop']) {
            $this->unavailable();
        }
        $customer = new Customer((int) $order->id_customer);
        if (!Validate::isLoadedObject($customer)
            || (!$customer->is_guest && (!$this->context->customer->isLogged()
                || (int) $this->context->customer->id !== (int) $customer->id))) {
            $this->unavailable();
        }

        $state = json_decode($row['state'], true, 512, JSON_THROW_ON_ERROR);
        if (!BlocksignEsign::orderEligible($order, $state)) {
            $this->unavailable();
        }
        $allowed = BlocksignEsign::downloadAllowed($order, $state);
        if (Tools::getValue('download') === '1') {
            $product = filter_var(Tools::getValue('product', 0), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
            $download = $product === false ? null : OrderFiles::download($state, $product);
            if (!$allowed || !$download) {
                $this->unavailable();
            }
            $this->download($download['file'], $download['hash']);
        }

        $message = (int) $row['next_attempt'] > 0
            ? 'Your download will be available here after all required signatures and delivery confirmations are complete. Check your email for the invitations and reload this page after signing.'
            : 'The download is locked. Contact the shop for help with this order.';
        if ($allowed) {
            $links = [];
            foreach (OrderFiles::downloads($state) as $product => $file) {
                $url = $this->context->link->getModuleLink($this->module->name, 'document', [
                    'order' => $orderId,
                    'token' => $token,
                    'download' => '1',
                    'product' => $product,
                ], true);
                $links[] = '<a href="' . BlocksignEsign::escape($url) . '">Download ' . BlocksignEsign::escape($file['file']) . '</a>';
            }
            $message = $links ? implode('<br>', $links) : 'All required confirmations are complete.';
        }

        if (!BlocksignEsign::paymentReady($order, $state)) {
            $message = 'The download is locked while payment is unconfirmed.';
        }

        header('Content-Type: text/html; charset=UTF-8');
        echo '<!DOCTYPE html><html lang="en"><meta charset="utf-8"><title>BlockSign</title>'
            . '<main><h1>Signature and download</h1><p>' . $message . '</p></main></html>';
        exit;
    }

    private function unavailable(): never
    {
        http_response_code(404);
        header('Content-Type: text/plain; charset=UTF-8');
        exit('Download unavailable.');
    }

    private function download(string $file, string $expectedHash): never
    {
        try {
            $stream = fopen(BlocksignEsign::asset($file), 'rb');
            if ($stream === false) {
                $this->unavailable();
            }
            $hash = hash_init('sha256');
            hash_update_stream($hash, $stream);
            if (!hash_equals($expectedHash, hash_final($hash)) || !rewind($stream)) {
                fclose($stream);
                $this->unavailable();
            }
        } catch (RuntimeException $error) {
            $this->unavailable();
        }

        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="download.pdf"');
        fpassthru($stream);
        fclose($stream);
        exit;
    }
}
