<?php

declare(strict_types=1);

namespace BlockSign\ESign;

use Closure;
use InvalidArgumentException;
use RuntimeException;

final class ProductFiles
{
    public const DEFAULTS = ['agreement' => 'agreement.pdf', 'download' => 'download.pdf'];
    public const BASENAME_PATTERN = '/^[a-z0-9][a-z0-9._-]{0,119}\.pdf$/iD';

    public static function validate(string $value): string
    {
        $value = trim($value);
        if ($value !== '' && !preg_match(self::BASENAME_PATTERN, $value)) {
            throw new InvalidArgumentException('Enter a PDF filename without a directory or URL.');
        }
        return $value;
    }

    public static function snapshot(array $overrides, array $placement, array $additional, Closure $locate): array
    {
        $agreement = self::validate($overrides['agreement'] ?? '') ?: self::DEFAULTS['agreement'];
        $download = self::validate($overrides['download'] ?? '') ?: self::DEFAULTS['download'];
        $additionalNames = array_map('strtolower', array_keys($additional));
        if (strcasecmp($agreement, $download) === 0 || in_array(strtolower($download), $additionalNames, true)
            || in_array(strtolower($agreement), $additionalNames, true)) {
            throw new RuntimeException('Use different files for the download and each required document.');
        }

        $hash = hash_file('sha256', $locate($download));
        if ($hash === false) {
            throw new RuntimeException('Could not read the private download.');
        }

        return [
            'download_file' => $download,
            'download_hash' => $hash,
            'documents' => RequiredSignatures::snapshot($placement, $additional, $locate, $agreement),
        ];
    }
}
