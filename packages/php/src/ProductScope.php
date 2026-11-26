<?php

declare(strict_types=1);

namespace BlockSign\ESign;

use InvalidArgumentException;

final class ProductScope
{
    public const DEFAULTS = ['mode' => 'product', 'category_id' => 0];

    public static function validate(array $input): array
    {
        $mode = $input['mode'] ?? '';
        $categoryId = filter_var($input['category_id'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if (!in_array($mode, ['product', 'category', 'all'], true) || $categoryId === false || ($mode === 'category' && $categoryId === 0)) {
            throw new InvalidArgumentException('Select a product scope and enter a positive category ID for category scope.');
        }
        return ['mode' => $mode, 'category_id' => $categoryId];
    }

    public static function matches(array $scope, int $configuredProduct, int $product, array $categories = []): bool
    {
        return match ($scope['mode']) {
            'product' => $configuredProduct > 0 && $product === $configuredProduct,
            'category' => in_array($scope['category_id'], array_map('intval', $categories), true),
            'all' => true,
            default => false,
        };
    }
}
