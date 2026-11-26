<?php

declare(strict_types=1);

namespace BlockSign\Shopware\Checkout;

use BlockSign\ESign\RequiredSignatures;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\Order\CartConvertedEvent;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class OrderConfirmation implements EventSubscriberInterface
{
    public function __construct(private readonly Rules $rules, private readonly Jobs $jobs)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [CartConvertedEvent::class => 'record'];
    }

    public function requireConfirmation(Cart $cart, SalesChannelContext $context): void
    {
        $snapshot = $this->rules->cart($cart, $context);
        if ($snapshot && $snapshot[1]['documents'] && !$this->confirmed($snapshot[0])) {
            throw new ConfirmationRequired();
        }
    }

    public function record(CartConvertedEvent $event): void
    {
        if (!$event->getConversionContext()->shouldIncludePersistentData()) {
            return;
        }
        $snapshot = $this->rules->cart($event->getCart(), $event->getSalesChannelContext());
        if (!$snapshot) {
            return;
        }
        [$id, $state] = $snapshot;
        $proof = $state['documents'] ? $this->confirmed($id) : null;
        if ($state['documents'] && !$proof) {
            throw new ConfirmationRequired();
        }
        $state['documents'] = array_merge($proof['documents'] ?? [], $state['after']);
        unset($state['after']);
        $this->jobs->enqueue($event->getConvertedCart()['id'], $state, $proof['proof_nonce'] ?? null);
        if ($proof) {
            $this->jobs->expireProof($id);
        }
    }

    public function confirmed(string $id): ?array
    {
        $state = $this->jobs->find($id)['data'] ?? null;
        return $state && RequiredSignatures::complete($state) ? $state : null;
    }
}
