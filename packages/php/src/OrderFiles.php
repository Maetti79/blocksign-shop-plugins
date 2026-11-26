<?php

declare(strict_types=1);

namespace BlockSign\ESign;

final class OrderFiles
{
    public static function combine(array $products): array
    {
        $downloads = [];
        $documents = [];
        foreach ($products as $id => $files) {
            if (isset($files['download_file'], $files['download_hash'])) {
                $downloads[$id] = [
                    'file' => $files['download_file'],
                    'hash' => $files['download_hash'],
                ];
            }
            foreach ($files['documents'] as $document) {
                $key = json_encode([$document['file'], $document['agreement_hash'], $document['placement']], JSON_THROW_ON_ERROR);
                $documents[$key] ??= $document;
            }
        }

        return ['downloads' => $downloads, 'documents' => array_values($documents)];
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
