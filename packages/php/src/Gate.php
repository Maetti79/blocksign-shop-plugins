<?php

declare(strict_types=1);

namespace BlockSign\ESign;

final class Gate
{
    public static function allows(string $stage, array $requirements): bool
    {
        $blockingStages = match ($stage) {
            'order' => ['before_order'],
            'payment' => ['before_order', 'before_payment'],
            'fulfillment' => ['before_order', 'before_payment', 'after_payment', 'without_payment'],
        };

        foreach ($requirements as $requirement) {
            if (in_array($requirement['timing'], $blockingStages, true)
                && $requirement['completed'] !== true) {
                return false;
            }
        }

        return true;
    }
}
