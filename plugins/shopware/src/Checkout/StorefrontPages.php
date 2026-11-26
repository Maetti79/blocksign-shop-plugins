<?php

declare(strict_types=1);

namespace BlockSign\Shopware\Checkout;

use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPageLoadedEvent;
use Shopware\Storefront\Page\Checkout\Finish\CheckoutFinishPageLoadedEvent;
use Shopware\Storefront\Page\Account\Order\AccountOrderPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class StorefrontPages implements EventSubscriberInterface
{
    public function __construct(
        private readonly Rules $rules,
        private readonly Jobs $jobs,
        private readonly OrderConfirmation $confirmation,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CheckoutConfirmPageLoadedEvent::class => 'confirm',
            CheckoutFinishPageLoadedEvent::class => 'finish',
            AccountOrderPageLoadedEvent::class => 'orders',
        ];
    }

    public function confirm(CheckoutConfirmPageLoadedEvent $event): void
    {
        try {
            $snapshot = $this->rules->cart($event->getPage()->getCart(), $event->getSalesChannelContext());
            if ($snapshot && $snapshot[1]['documents']) {
                $event->getPage()->addExtension('blocksign', new ArrayStruct([
                    'proof' => $snapshot[0],
                    'confirmed' => $this->confirmation->confirmed($snapshot[0]) !== null,
                    'requested' => $this->jobs->find($snapshot[0]) !== null,
                ]));
            }
        } catch (\Throwable) {
            $event->getPage()->addExtension('blocksign', new ArrayStruct(['unavailable' => true]));
        }
    }

    public function finish(CheckoutFinishPageLoadedEvent $event): void
    {
        $order = $event->getPage()->getOrder();
        if ($this->jobs->find($order->getId())) {
            $order->addExtension('blocksign', new ArrayStruct([]));
        }
    }

    public function orders(AccountOrderPageLoadedEvent $event): void
    {
        foreach ($event->getPage()->getOrders() as $order) {
            if ($this->jobs->find($order->getId())) {
                $order->addExtension('blocksign', new ArrayStruct([]));
            }
        }
    }
}
