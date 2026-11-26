<?php

declare(strict_types=1);

namespace BlockSign\Shopware\Checkout;

use Shopware\Core\Checkout\Cart\Error\Error;

final class ConfirmationRequired extends Error
{
    public function __construct()
    {
        parent::__construct('Complete the required BlockSign confirmations before you place the order.');
    }

    public function getId(): string { return 'blocksign-confirmation-required'; }
    public function getMessageKey(): string { return $this->getId(); }
    public function getLevel(): int { return self::LEVEL_ERROR; }
    public function blockOrder(): bool { return true; }
    public function isPersistent(): bool { return false; }
    public function getParameters(): array { return []; }
}
