<?php

declare(strict_types=1);

namespace BlockSign\ESign;

use Closure;
use InvalidArgumentException;
use RuntimeException;

final class RequiredSignatures
{
    public static function parseAdditional(string $configuration): array
    {
        $documents = [];
        $names = ['agreement.pdf' => true, 'download.pdf' => true];
        foreach (preg_split('/\R/', $configuration) as $line) {
            if (trim($line) === '') {
                continue;
            }
            $fields = array_map('trim', explode('|', $line));
            $file = $fields[0];
            $name = strtolower($file);
            $level = strtolower($fields[1] ?? '');
            $fieldCount = count($fields);
            if (($fieldCount !== 5 && !($level === SignatureSettings::DELIVERY_PROOF && $fieldCount === 2))
                || !preg_match('/^[a-z0-9][a-z0-9._-]{0,119}\.pdf$/iD', $file) || isset($names[$name])) {
                throw new InvalidArgumentException('Use a unique PDF basename and valid signature settings or POD per line. agreement.pdf and download.pdf are reserved.');
            }
            $placement = SignatureSettings::validate([
                'signature_level' => $level === 'ees' ? 'aes' : $level,
                'page' => $fields[2] ?? null,
                'x' => $fields[3] ?? null,
                'y' => $fields[4] ?? null,
            ]);
            $documents[$file] = $placement;
            $names[$name] = true;
        }
        return $documents;
    }

    public static function snapshot(array $primary, array $additional, Closure $locate, string $primaryFile = 'agreement.pdf'): array
    {
        $documents = [];
        foreach ([$primaryFile => $primary] + $additional as $file => $placement) {
            $hash = hash_file('sha256', $locate($file));
            if ($hash === false) {
                throw new RuntimeException('Could not read a required BlockSign document.');
            }
            $documents[] = [
                'file' => $file,
                'agreement_hash' => $hash,
                'placement' => $placement,
                'phase' => 'upload',
                'completed' => false,
            ];
        }
        return $documents;
    }

    public static function complete(array $order): bool
    {
        if (!array_key_exists('documents', $order)) {
            return ($order['completed'] ?? false) === true;
        }
        if (!is_array($order['documents']) || $order['documents'] === []) {
            return false;
        }
        foreach ($order['documents'] as $document) {
            if (($document['completed'] ?? false) !== true) {
                return false;
            }
        }
        return true;
    }

    public static function needsReview(array $order): bool
    {
        foreach ($order['documents'] ?? [$order] as $document) {
            if (str_ends_with($document['phase'] ?? '', '_pending_review')) {
                return true;
            }
        }
        return false;
    }

    public static function advance(SignatureWorkflow $workflow, array $order, Closure $persist, Closure $read): bool
    {
        if (self::complete($order) || self::needsReview($order)) {
            return false;
        }
        if (!array_key_exists('documents', $order)) {
            return $workflow->advance($order, $persist, static fn () => $read('agreement.pdf'));
        }

        foreach ($order['documents'] as $index => $document) {
            if ($document['completed']) {
                continue;
            }
            $retry = $workflow->advance(
                $document + ['signer_email' => $order['signer_email']],
                static function (array $next) use (&$order, $persist, $index): void {
                    unset($next['signer_email']);
                    $order['documents'][$index] = $next;
                    $persist($order);
                },
                static fn () => $read($document['file'])
            );
            return $retry || ($order['documents'][$index]['completed'] && !self::complete($order));
        }

        return false;
    }
}
