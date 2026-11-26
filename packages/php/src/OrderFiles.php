<?php

declare(strict_types=1);

namespace BlockSign\ESign;

final class OrderFiles
{
    public static function combine(array $products): array
    {
        $downloads = [];
        $documents = [];
        $shipping = [];
        $accessGroups = [];
        $scheduled = false;
        foreach ($products as $id => $files) {
            if (isset($files['download_file'], $files['download_hash'])) {
                $downloads[$id] = [
                    'file' => $files['download_file'],
                    'hash' => $files['download_hash'],
                ];
            }
            if (!empty($files['shipping'])) {
                $shipping[] = (int) $id;
            }
            if (!empty($files['access_group'])) {
                $accessGroups[$id] = $files['access_group'];
            }
            foreach ($files['documents'] as $document) {
                $key = json_encode([$document['file'], $document['agreement_hash'], $document['placement']], JSON_THROW_ON_ERROR);

                if (!isset($files['timing'])) {
                    $documents[$key] ??= $document;
                    continue;
                }
                $scheduled = true;
                if (!isset($documents[$key])) {
                    $documents[$key] = $document + ['timing' => $files['timing'], 'products' => []];
                } elseif (DocumentSchedule::earlier($files['timing'], $documents[$key]['timing'])) {
                    $documents[$key]['timing'] = $files['timing'];
                }
                $documents[$key]['products'][] = (int) $id;
            }
        }

        $state = ['downloads' => $downloads, 'documents' => array_values($documents)];
        if ($scheduled) {
            $state['scheduled_documents'] = true;
        }
        if ($shipping) {
            $state['shipping_products'] = $shipping;
        }
        if ($accessGroups) {
            $state['access_groups'] = $accessGroups;
        }
        return $state;
    }

    public static function downloads(array $state): array
    {
        if (array_key_exists('downloads', $state)) {
            return $state['downloads'];
        }
        if (!isset($state['product_id'], $state['download_hash'])) {
            return [];
        }

        return [$state['product_id'] => [
            'file' => $state['download_file'] ?? ProductFiles::DEFAULTS['download'],
            'hash' => $state['download_hash'],
        ]];
    }

    public static function download(array $state, int $product): ?array
    {
        $downloads = self::downloads($state);
        if ($product === 0 && count($downloads) === 1) {
            return reset($downloads);
        }
        return $downloads[$product] ?? null;
    }
}
