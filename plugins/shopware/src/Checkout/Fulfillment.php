<?php

declare(strict_types=1);

namespace BlockSign\Shopware\Checkout;

use BlockSign\ESign\RequiredSignatures;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Order\OrderStates;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;

final class Fulfillment
{
    public function __construct(private readonly EntityRepository $orders, private readonly EntityRepository $customers)
    {
    }

    public function order(string $id, Context $context): ?OrderEntity
    {
        return $this->orders->search((new Criteria([$id]))
            ->addAssociations(['stateMachineState', 'primaryOrderTransaction.stateMachineState', 'transactions.stateMachineState', 'orderCustomer', 'lineItems']), $context)
            ->getEntities()->first();
    }

    public function eligible(?OrderEntity $order, array $state): bool
    {
        if (!$order || $order->getStateMachineState()?->getTechnicalName() === OrderStates::STATE_CANCELLED
            || $order->getSalesChannelId() !== $state['channel'] || $order->getCurrencyId() !== $state['currency']
            || abs($order->getAmountTotal() - (float) $state['amount']) > 0.00001
            || $order->getOrderCustomer()?->getCustomerId() !== $state['customer']) {
            return false;
        }
        $products = [];
        foreach ($order->getLineItems() ?? [] as $item) {
            $id = $item->getProductId();
            if ($id && $item->getQuantity() > 0) {
                $products[$id] = ($products[$id] ?? 0) + $item->getQuantity();
            }
        }
        if (array_is_list($state['products'])) {
            if (array_diff($state['products'], array_keys($products))) {
                return false;
            }
        } elseif (array_diff_assoc($state['products'], $products)) {
            return false;
        }
        foreach ($order->getTransactions() ?? [] as $transaction) {
            if (in_array($transaction->getStateMachineState()?->getTechnicalName(), [
                OrderTransactionStates::STATE_REFUNDED,
                OrderTransactionStates::STATE_PARTIALLY_REFUNDED,
                OrderTransactionStates::STATE_CHARGEBACK,
            ], true)) {
                return false;
            }
        }
        return true;
    }

    public function paid(OrderEntity $order): bool
    {
        return $order->getAmountTotal() === 0.0 || $order->getPrimaryOrderTransaction()?->getStateMachineState()?->getTechnicalName() === OrderTransactionStates::STATE_PAID;
    }

    public function allowed(?OrderEntity $order, array $state): bool
    {
        return $this->eligible($order, $state) && $this->paid($order) && RequiredSignatures::complete($state);
    }

    public function grant(array $state, Context $context): void
    {
        if (!$state['group']) {
            return;
        }
        $id = $state['customer'];
        $customer = $this->customers->search(new Criteria([$id]), $context)->getEntities()->first();
        if (!$customer instanceof CustomerEntity || !$customer->getActive() || $customer->getGuest()
            || strcasecmp($customer->getEmail(), $state['signer_email']) !== 0) {
            throw new \RuntimeException('The BlockSign membership account is unavailable.');
        }
        if ($customer->getGroupId() !== $state['group']) {
            $context->scope(Context::SYSTEM_SCOPE, fn (Context $system) => $this->customers->update([
                ['id' => $id, 'groupId' => $state['group']],
            ], $system));
        }
    }
}
