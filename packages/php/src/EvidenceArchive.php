<?php

declare(strict_types=1);

namespace BlockSign\ESign;

use RuntimeException;

final class EvidenceArchive
{
    public function __construct(private Client $client)
    {
    }

    public function storeNext(string $directory, string $webRoot, string $orderKey, array $state): bool
    {
        if (!RequiredSignatures::complete($state)) {
            return false;
        }

        $root = realpath($webRoot);
        $archive = realpath($directory);
        if (!$root || !$archive || !is_dir($archive) || !is_writable($archive)
            || $archive === $root || str_starts_with($archive, rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)
            || !preg_match('/^(?:(?:woocommerce|prestashop)-[0-9]+-[0-9]+|shopware-[a-f0-9]{32}|jtl-[0-9]+)$/D', $orderKey)) {
            throw new RuntimeException('Configure a writable archive directory outside of the web root.');
        }

        $folder = $archive . DIRECTORY_SEPARATOR . $orderKey;
        if ((!is_dir($folder) && !mkdir($folder, 0700) && !is_dir($folder))
            || is_link($folder) || realpath($folder) !== $folder || (fileperms($folder) & 0077) !== 0) {
            throw new RuntimeException('Could not open a private BlockSign order archive.');
        }

        $savedDocument = false;
        foreach ($state['documents'] ?? [$state] as $document) {
            $deliveryProof = ($document['placement']['signature_level'] ?? '') === SignatureSettings::DELIVERY_PROOF;
            $request = $deliveryProof ? ($document['document_id'] ?? '') : ($document['request_id'] ?? '');
            if (!is_string($request) || !preg_match('/^[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}$/iD', $request)) {
                throw new RuntimeException('BlockSign order archive requires a valid request or document ID.');
            }
            $pdf = $folder . DIRECTORY_SEPARATOR . $request . ($deliveryProof ? '.delivery.pdf' : '.pdf');
            $audit = $folder . DIRECTORY_SEPARATOR . $request . '.audit.json';
            if ((is_file($pdf) && !is_link($pdf)) && ($deliveryProof || (is_file($audit) && !is_link($audit)))) {
                continue;
            }
            if ($savedDocument) {
                return true;
            }

            if (!is_file($pdf) || is_link($pdf)) {
                self::write($pdf, $deliveryProof ? $this->client->deliveryPdf($request) : $this->client->signedPdf($request));
            }
            if (!$deliveryProof && (!is_file($audit) || is_link($audit))) {
                self::write($audit, json_encode($this->client->auditTrail($request), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL);
            }
            $savedDocument = true;
        }
        return false;
    }

    private static function write(string $path, string $content): void
    {
        $folder = dirname($path);
        $temporary = tempnam($folder, '.blocksign-');
        if ($temporary === false) {
            throw new RuntimeException('Could not create a temporary BlockSign evidence file.');
        }
        try {
            if (dirname($temporary) !== $folder || !chmod($temporary, 0600)
                || file_put_contents($temporary, $content) !== strlen($content) || !rename($temporary, $path)) {
                throw new RuntimeException('Could not save BlockSign evidence.');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }
}
