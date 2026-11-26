<?php

declare(strict_types=1);

namespace BlockSign\ESign;

use Closure;
use InvalidArgumentException;

final class DocumentSchedule
{
    private const ORDER = ['before_order' => 0, 'before_payment' => 1, 'after_payment' => 2, 'without_payment' => 2];

    public static function parse(string $settings, bool $allowBeforePayment = false): array
    {
        $rules = [];
        $accepted = $allowBeforePayment ? ['before_order', 'before_payment', 'after_payment'] : ['before_order', 'after_payment'];
        foreach (preg_split('/\R/', $settings) as $line) {
            if (trim($line) === '') {
                continue;
            }
            $fields = array_map('trim', explode('|', $line));
            [$type, $id, $timing] = $fields + ['', '', ''];
            $id = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (count($fields) !== 3 || !in_array($type, ['product', 'category'], true) || !$id
                || !in_array($timing, $accepted, true) || isset($rules[$type][$id])) {
                throw new InvalidArgumentException('Enter one timing per product or category. Example: product | 42 | before_order');
            }
            $rules[$type][$id] = $timing;
        }
        return $rules;
    }

    public static function resolve(array $rules, int $product, array $categories, string $default, bool $free): string
    {
        $timing = $rules['product'][$product] ?? null;
        if ($timing === null) {
            foreach ($categories as $id) {
                $category = $rules['category'][(int) $id] ?? null;
                if ($category !== null && ($timing === null || self::earlier($category, $timing))) {
                    $timing = $category;
                }
            }
        }
        $timing ??= $default;

        return $free && $timing !== 'before_order' ? 'without_payment' : $timing;
    }

    public static function earlier(string $first, string $second): bool
    {
        if (!isset(self::ORDER[$first], self::ORDER[$second])) {
            throw new InvalidArgumentException('Invalid BlockSign document timing.');
        }
        return self::ORDER[$first] < self::ORDER[$second];
    }

    public static function has(array $state, string $timing): bool
    {
        return empty($state['scheduled_documents'])
            ? ($state['timing'] ?? 'without_payment') === $timing
            : in_array($timing, array_column($state['documents'], 'timing'), true);
    }

    public static function view(array $state, array $timings): array
    {
        if (!empty($state['scheduled_documents'])) {
            $state['documents'] = array_filter($state['documents'], static fn (array $doc): bool => in_array($doc['timing'], $timings, true));
        }

        return $state;
    }

    public static function allows(string $stage, array $state, bool $identityMatches = true): bool
    {
        if (empty($state['scheduled_documents'])) {
            return Gate::allows($stage, [[
                'timing' => $state['timing'] ?? 'without_payment',
                'completed' => $identityMatches && RequiredSignatures::complete($state),
            ]]);
        }

        if ($stage === 'fulfillment' && $state['documents'] === []) {
            return false;
        }
        return Gate::allows($stage, array_map(static fn (array $doc): array => [
            'timing' => $doc['timing'],
            'completed' => $identityMatches && $doc['completed'] === true,
        ], $state['documents']));
    }

    public static function subsetForProducts(array $state, array $products): array
    {
        if (!empty($state['scheduled_documents'])) {
            $state['documents'] = array_values(array_filter($state['documents'], static fn (array $doc): bool => (bool) array_intersect($doc['products'], $products)));
        }
        return $state;
    }

    public static function advance(SignatureWorkflow $workflow, array $state, array $timings, Closure $persist, Closure $read): bool
    {
        if (empty($state['scheduled_documents'])) {
            return RequiredSignatures::advance($workflow, $state, $persist, $read);
        }

        return RequiredSignatures::advance(
            $workflow,
            self::view($state, $timings),
            static function (array $next) use ($state, $persist): void {
                $next['documents'] = array_replace($state['documents'], $next['documents']);
                $persist($next);
            },
            $read
        );
    }
}
