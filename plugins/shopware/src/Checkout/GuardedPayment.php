<?php

declare(strict_types=1);

namespace BlockSign\Shopware\Checkout;

use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Payment\Cart\Token\TokenStruct;
use Shopware\Core\Checkout\Payment\PaymentProcessor;
use Shopware\Core\Framework\Struct\Struct;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

final class GuardedPayment extends PaymentProcessor
{
    public function __construct(private readonly PaymentProcessor $inner, private readonly OrderConfirmation $confirmation)
    {
    }

    public function validate(Cart $cart, RequestDataBag $dataBag, SalesChannelContext $salesChannelContext): ?Struct
    {
        $this->confirmation->requireConfirmation($cart, $salesChannelContext);
        return $this->inner->validate($cart, $dataBag, $salesChannelContext);
    }

    public function pay(string $orderId, Request $request, SalesChannelContext $salesChannelContext, ?string $finishUrl = null, ?string $errorUrl = null): ?RedirectResponse
    {
        return $this->inner->pay($orderId, $request, $salesChannelContext, $finishUrl, $errorUrl);
    }

    public function finalize(TokenStruct $token, Request $request, SalesChannelContext $context): TokenStruct
    {
        return $this->inner->finalize(...func_get_args());
    }
}
