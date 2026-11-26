<?php

declare(strict_types=1);

namespace BlockSign\Shopware\Controller;

use BlockSign\ESign\RequiredSignatures;
use BlockSign\Shopware\Checkout\Fulfillment;
use BlockSign\Shopware\Checkout\Jobs;
use BlockSign\Shopware\Checkout\Rules;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: ['_routeScope' => ['storefront'], '_loginRequired' => true, '_loginRequiredAllowGuest' => true])]
final class ConfirmationController extends StorefrontController
{
    public function __construct(
        private readonly CartService $carts,
        private readonly Rules $rules,
        private readonly Jobs $jobs,
        private readonly Fulfillment $fulfillment,
    ) {
    }

    #[Route(path: '/blocksign/confirmation', name: 'frontend.blocksign.request', methods: ['POST'])]
    public function requestConfirmation(Request $request, SalesChannelContext $context): Response
    {
        $cart = $this->carts->getCart($context->getToken(), $context);
        $snapshot = $this->rules->cart($cart, $context);
        if (!$snapshot || !$snapshot[1]['documents'] || !hash_equals($snapshot[0], $request->request->getString('proof'))) {
            throw new ConflictHttpException('This checkout changed. Reload the confirmation page.');
        }

        [$id, $state] = $snapshot;
        unset($state['after']);
        $this->jobs->enqueue($id, $state);

        return $this->redirectToRoute('frontend.checkout.confirm.page');
    }

    #[Route(path: '/blocksign/order/{orderId}', name: 'frontend.blocksign.order', methods: ['GET'])]
    public function orderStatus(string $orderId, Request $request, SalesChannelContext $context): Response
    {
        if (!Uuid::isValid($orderId)) {
            throw $this->createNotFoundException();
        }
        $state = $this->jobs->find($orderId)['data'] ?? null;
        $order = $state ? $this->fulfillment->order($orderId, $context->getContext()) : null;
        if (!$state || !$order || !$context->getCustomer()
            || $state['customer'] !== $context->getCustomer()->getId() || $state['channel'] !== $context->getSalesChannelId()
            || $state['customer'] !== $order->getOrderCustomer()?->getCustomerId()) {
            throw $this->createNotFoundException();
        }
        $ready = $this->fulfillment->allowed($order, $state);
        $product = $request->query->getString('product');
        if ($product !== '') {
            $file = $state['downloads'][$product] ?? null;
            if (!$ready || !$file) {
                throw $this->createNotFoundException();
            }
            $path = $this->rules->asset($file['file'], $state['channel']);
            $hash = hash_file('sha256', $path);
            if (!$hash || !hash_equals($file['hash'], $hash)) {
                throw $this->createNotFoundException();
            }
            $response = new BinaryFileResponse($path);
            $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $file['file']);
            $response->headers->set('Content-Type', 'application/pdf');
        } else {
            $response = $this->renderStorefront('@BlockSignEsign/storefront/page/account/order/blocksign.html.twig', [
                'orderId' => $orderId,
                'orderNumber' => $order->getOrderNumber(),
                'confirmed' => RequiredSignatures::complete($state),
                'paid' => $this->fulfillment->paid($order),
                'downloads' => $ready ? $state['downloads'] : [],
            ]);
        }
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
