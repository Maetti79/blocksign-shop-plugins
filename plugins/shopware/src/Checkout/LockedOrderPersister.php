<?php

declare(strict_types=1);

namespace BlockSign\Shopware\Checkout;

use Doctrine\DBAL\Connection;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\Order\OrderPersisterInterface;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

final class LockedOrderPersister implements OrderPersisterInterface
{
    public function __construct(private readonly OrderPersisterInterface $inner, private readonly Connection $db)
    {
    }

    public function persist(Cart $cart, SalesChannelContext $context): string
    {
        return $this->db->transactional(fn () => $this->inner->persist($cart, $context));
    }
}
