<?php

declare(strict_types=1);

namespace Plugin\blocksign_esign;

use JTL\Events\Dispatcher;
use JTL\Plugin\Bootstrapper;
use JTL\Session\Frontend;
use Throwable;

require_once __DIR__ . '/src/load.php';

final class Bootstrap extends Bootstrapper
{
    public function boot(Dispatcher $dispatcher): void
    {
        parent::boot($dispatcher);

        $dispatcher->hookInto(\HOOK_BESTELLABSCHLUSS_INC_BESTELLUNGINDB_ENDE, function (array $args): void {
            $id = (int) ($args['bestellID']->kBestellung ?? $args['oBestellung']->kBestellung ?? 0);
            if ($id <= 0) {
                return;
            }
            try {
                $settings = Settings::load(\PFAD_ROOT);
                if ($settings) {
                    (new Orders($this->getDB()))->register($id, $settings);
                }
            } catch (Throwable $error) {
                error_log('BlockSign JTL-Shop could not register a protected order.');
            }
        });

        $dispatcher->hookInto(\HOOK_SMARTY_OUTPUTFILTER, function (array $args): void {
            try {
                $order = ($args['smarty'] ?? null)?->getTemplateVars('oBestellung');
                $id = (int) ($order->kBestellung ?? 0);
                if ($id <= 0) {
                    return;
                }
                $customer = Frontend::getCustomer();
                if ((int) $customer->kKunde <= 0 || $customer->cRegistriert !== 'Y') {
                    return;
                }
                $state = (new Orders($this->getDB()))->find($id);
                if (!$state || $state['customer'] !== (int) $customer->kKunde) {
                    return;
                }
                $link = $this->getPlugin()->getLinks()->getLinks()->first();
                if ($link) {
                    $args['document']->find('main')->first()->prepend(
                        '<p><a href="' . htmlspecialchars($link->getURL(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">BlockSign-Status und Downloads</a></p>'
                    );
                }
            } catch (Throwable $error) {
                // Omit the link when the order status is unavailable.
            }
        });
    }
}
