<?php

declare(strict_types=1);

use BlockSign\ESign\OrderFiles;
use JTL\Plugin\Helper;
use JTL\Session\Frontend;
use JTL\Shop;
use Plugin\blocksign_esign\Orders;
use Plugin\blocksign_esign\Settings;

if (!defined('PFAD_ROOT') || !defined('BLOWFISH_KEY')) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../src/load.php';

header('Cache-Control: private, no-store, max-age=0');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow');

$notFound = static function (): never {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('BlockSign-Auftrag nicht verfügbar.');
};
$customer = Frontend::getCustomer();
if ((int) $customer->kKunde < 1 || $customer->cRegistriert !== 'Y') {
    $notFound();
}

try {
    $settings = Settings::load(PFAD_ROOT);
    $link = Helper::getPluginById('blocksign_esign')?->getLinks()->getLinks()->first();
    if (!$settings || !$link) {
        $notFound();
    }
    $url = $link->getURL();
    $address = static fn (array $args): string => $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($args, '', '&', PHP_QUERY_RFC3986);

    $orders = new Orders(Shop::Container()->getDB());
    $id = filter_input(INPUT_GET, 'blocksign_order', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $product = filter_input(INPUT_GET, 'blocksign_download', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $smarty = Shop::Smarty();
    $status = '';
    $orderLinks = $downloads = [];

    if ($id) {
        $state = $orders->find($id);
        $order = $orders->order($id);
        if (!$state || !$order || $state['customer'] !== (int) $customer->kKunde || (int) $order->kKunde !== (int) $customer->kKunde) {
            $notFound();
        }
        $ready = $orders->allowed($order, $state);
        if ($product !== null) {
            $file = is_int($product) ? OrderFiles::download($state, $product) : null;
            if (!$ready || !$file) {
                $notFound();
            }
            $stream = fopen($settings->asset($file['file']), 'rb');
            if ($stream === false) {
                $notFound();
            }
            $hash = hash_init('sha256');
            hash_update_stream($hash, $stream);
            if (!hash_equals($file['hash'], hash_final($hash)) || !rewind($stream)) {
                fclose($stream);
                $notFound();
            }
            header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="' . $file['file'] . '"');
            fpassthru($stream);
            fclose($stream);
            exit;
        }
        if ($ready) {
            $status = 'Die Pflichtdokumente sind bestätigt. Die Downloads sind freigegeben.';
            foreach (OrderFiles::downloads($state) as $productId => $file) {
                $downloads[] = ['name' => $file['file'], 'url' => $address(['blocksign_order' => $id, 'blocksign_download' => $productId])];
            }
        } elseif (!$orders->eligible($order, $state)) {
            $status = 'Für diese Bestellung ist keine Freigabe verfügbar.';
        } elseif (!Orders::paid($order, $state)) {
            $status = 'Die Freigabe wartet auf den Zahlungseingang.';
        } else {
            $status = 'Die Freigabe wartet auf die Bestätigung. Prüfe deine E-Mails und lade diese Seite nach der Bestätigung neu.';
        }
    } elseif ($product !== null) {
        $notFound();
    } else {
        foreach ($orders->forCustomer((int) $customer->kKunde) as $row) {
            $orderLinks[] = ['number' => $row->number, 'url' => $address(['blocksign_order' => $row->id])];
        }
    }

    $smarty->assign('blocksignStatus', $status);
    $smarty->assign('blocksignOrders', $orderLinks);
    $smarty->assign('blocksignDownloads', $downloads);
    $smarty->assign('blocksignOverview', $url);
} catch (Throwable $error) {
    $notFound();
}
