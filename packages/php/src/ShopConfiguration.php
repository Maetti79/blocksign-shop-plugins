<?php

declare(strict_types=1);

namespace BlockSign\ESign;

use InvalidArgumentException;

final class ShopConfiguration
{
    public static function credentials(array $input, string $previousKey, array $publicRoots): array
    {
        $key = $input['key'] ?? '';
        $directory = $input['directory'] ?? '';
        if (!is_string($key) || !is_string($directory)) {
            throw new InvalidArgumentException('Enter a valid BlockSign API key and private PDF directory.');
        }
        $key = trim($key);
        if ($key !== '' && !preg_match('/\A[!-~]{1,512}\z/', $key)) {
            throw new InvalidArgumentException('Enter a BlockSign API key without spaces or control characters.');
        }
        $directory = trim($directory);
        $private = $directory === '' ? '' : self::privateDirectory($directory, $publicRoots);
        if ($private === null) {
            throw new InvalidArgumentException('Enter an existing absolute directory outside the web root that PHP can read.');
        }
        return [
            'key' => $key !== '' ? $key : (($input['remove_key'] ?? null) === '1' ? '' : $previousKey),
            'directory' => $private,
        ];
    }

    public static function privateDirectory(string $path, array $publicRoots): ?string
    {
        $absolute = str_starts_with($path, DIRECTORY_SEPARATOR)
            || (DIRECTORY_SEPARATOR === '\\' && preg_match('~^[a-zA-Z]:[/\\\\]~', $path));
        $directory = $absolute && !str_contains($path, "\0") ? realpath($path) : false;
        if (!$directory || !is_dir($directory) || !is_readable($directory)) {
            return null;
        }
        foreach (array_filter($publicRoots) as $path) {
            $root = realpath($path);
            if (!$root || $directory === $root || str_starts_with($directory, rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) {
                return null;
            }
        }
        return $directory;
    }
}
