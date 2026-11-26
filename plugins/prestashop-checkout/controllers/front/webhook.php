<?php

declare(strict_types=1);

use BlockSign\PrestaCheckout\StripeCheckout;

if (!defined('_PS_VERSION_')) {
    exit;
}

final class BlocksignCheckoutWebhookModuleFrontController extends ModuleFrontController
{
    public $ssl = true;

    public function postProcess(): void
    {
        header('Cache-Control: no-store');
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            http_response_code(405);
            exit;
        }

        try {
            $payload = file_get_contents('php://input', false, null, 0, 1048577);
            if (!is_string($payload) || strlen($payload) > 1048576) {
                throw new InvalidArgumentException('Invalid payload.');
            }
            $event = StripeCheckout::event($payload, $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '', getenv('BLOCKSIGN_STRIPE_WEBHOOK_SECRET') ?: '', time());
        } catch (InvalidArgumentException | JsonException $error) {
            http_response_code(400);
            exit('Invalid Stripe webhook.');
        }

        try {
            $this->module->acceptEvent($event, StripeCheckout::client());
            http_response_code(204);
            exit;
        } catch (Throwable $error) {
            PrestaShopLogger::addLog('BlockSign Checkout could not reconcile a Stripe payment.', 3);
            http_response_code(500);
            exit('Payment reconciliation unavailable.');
        }
    }
}
