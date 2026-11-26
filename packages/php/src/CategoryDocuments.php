<?php

declare(strict_types=1);

namespace BlockSign\ESign;

use InvalidArgumentException;

final class CategoryDocuments
{
    public static function parse(string $configuration): array
    {
        $groups = [];
        foreach (preg_split('/\R/', $configuration) as $line) {
            if (trim($line) === '') {
                continue;
            }
            $fields = explode('|', $line, 2);
            $id = filter_var(trim($fields[0]), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (!$id || trim($fields[1] ?? '') === '') {
                throw new InvalidArgumentException('Start each category document row with a positive category ID and a vertical bar.');
            }
            $groups[$id][] = $fields[1];
        }

        foreach ($groups as $id => $rows) {
            $groups[$id] = RequiredSignatures::parseAdditional(implode("\n", $rows));
        }
        return $groups;
    }

    public static function forProduct(array $additional, array $groups, array $categoryIds): array
    {
        $byName = [];
        foreach ($additional as $file => $placement) {
            $byName[strtolower($file)] = $file;
        }
        foreach ($categoryIds as $categoryId) {
            foreach ($groups[(int) $categoryId] ?? [] as $file => $placement) {
                $name = strtolower($file);
                $existing = $byName[$name] ?? null;
                if ($existing !== null && ($existing !== $file || $additional[$existing] !== $placement)) {
                    throw new InvalidArgumentException('A required PDF has conflicting names or signature settings for the selected categories.');
                }
                $additional[$file] = $placement;
                $byName[$name] = $file;
            }
        }
        return $additional;
    }
}
