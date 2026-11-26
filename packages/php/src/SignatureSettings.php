<?php

declare(strict_types=1);

namespace BlockSign\ESign;

use InvalidArgumentException;

final class SignatureSettings
{
    public const DELIVERY_PROOF = 'pod';
    public const DEFAULTS = ['signature_level' => 'aes', 'page' => 1, 'x' => 50, 'y' => 50];

    public static function validate(array $input): array
    {
        $level = $input['signature_level'] ?? null;
        if ($level === self::DELIVERY_PROOF) {
            return ['signature_level' => self::DELIVERY_PROOF] + self::DEFAULTS;
        }
        $page = filter_var($input['page'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $x = filter_var($input['x'] ?? null, FILTER_VALIDATE_FLOAT);
        $y = filter_var($input['y'] ?? null, FILTER_VALIDATE_FLOAT);
        if (!in_array($level, ['aes', 'fes', 'qes'], true) || $page === false
            || $x === false || $y === false || !is_finite($x) || !is_finite($y) || $x < 0 || $y < 0) {
            throw new InvalidArgumentException('Choose a signature level, a page starting at 1 and non-negative X/Y coordinates.');
        }
        return ['signature_level' => $level, 'page' => $page, 'x' => $x, 'y' => $y];
    }
}
