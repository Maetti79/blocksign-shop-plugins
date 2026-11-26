<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

class BlocksignEsignPreorderModuleFrontController extends ModuleFrontController
{
    public $ssl = true;

    public function postProcess(): void
    {
        $token = Tools::getValue('token');
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST'
            || !is_string($token) || !hash_equals(Tools::getToken(false), $token)) {
            http_response_code(403);
            exit('Invalid checkout request.');
        }

        try {
            $this->module->startCheckoutProof();
        } catch (Throwable $error) {
            http_response_code(409);
            exit('BlockSign confirmation could not be started. Return to the checkout and try again.');
        }
        Tools::redirect($this->context->link->getPageLink('order', true));
    }
}
