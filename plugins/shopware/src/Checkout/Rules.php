<?php

declare(strict_types=1);

namespace BlockSign\Shopware\Checkout;

use BlockSign\ESign\DocumentSchedule;
use BlockSign\ESign\ProductFiles;
use BlockSign\ESign\RequiredSignatures;
use BlockSign\ESign\ShopConfiguration;
use BlockSign\ESign\SignatureSettings;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;

final class Rules
{
    private const PREFIX = 'BlockSignEsign.config.';

    public function __construct(
        private readonly SystemConfigService $config,
        private readonly EntityRepository $products,
        private readonly string $publicDir,
        private readonly string $cartSecret,
    ) {
    }

    public function get(string $key, string $channel): string
    {
        return (string) ($this->config->get(self::PREFIX . $key, $channel) ?? '');
    }

    public function apiKey(string $channel): string
    {
        $key = trim(getenv('BLOCKSIGN_ESIGN_API_KEY') ?: $this->get('apiKey', $channel));
        if (!preg_match('/\A[!-~]{1,512}\z/', $key)) {
            throw new \RuntimeException('The BlockSign API key is missing or invalid.');
        }
        return $key;
    }

    public function asset(string $file, string $channel): string
    {
        $path = getenv('BLOCKSIGN_ESIGN_FILES') ?: $this->get('pdfDirectory', $channel);
        $directory = ShopConfiguration::privateDirectory($path, [$this->publicDir, $_SERVER['DOCUMENT_ROOT'] ?? '']);
        $resolved = $directory === null ? false : realpath($directory . '/' . ProductFiles::validate($file));
        if (!$resolved || dirname($resolved) !== $directory || !is_file($resolved) || !is_readable($resolved)) {
            throw new \RuntimeException('A private BlockSign PDF is unavailable.');
        }

        return $resolved;
    }

    public function cart(Cart $cart, SalesChannelContext $context): ?array
    {
        $channel = $context->getSalesChannelId();
        if ($this->config->get(self::PREFIX . 'enabled', $channel) !== true) {
            return null;
        }
        $ids = [];
        $quantities = [];
        foreach ($cart->getLineItems()->getFlat() as $item) {
            $id = $item->getReferencedId();
            if ($item->getType() === LineItem::PRODUCT_LINE_ITEM_TYPE && $id) {
                $ids[$id] = $id;
                $quantities[$id] = ($quantities[$id] ?? 0) + $item->getQuantity();
            }
        }
        if (!$ids) {
            return null;
        }
        ksort($quantities);
        $products = [];
        foreach ($this->products->search((new Criteria(array_values($ids)))->addAssociation('categories'), $context->getContext()) as $product) {
            if ($product instanceof ProductEntity) {
                $products[$product->getId()] = $product;
            }
        }
        ksort($products);
        $rules = json_decode($this->get('productRules', $channel) ?: '{}', true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($rules)) {
            throw new \RuntimeException('Enter product and category settings as a JSON object.');
        }
        $timings = ['after_payment' => [], 'before_payment' => [], 'before_order' => []];
        $downloads = $protected = $shipping = [];
        $membership = null;
        foreach ($products as $id => $product) {
            $rule = self::rule($product, $rules, $this->get('scope', $channel));
            if (!$rule) {
                continue;
            }
            $kind = $rule['kind'] ?? 'download';
            $timing = $rule['timing'] ?? ($this->get('timing', $channel) ?: 'after_payment');
            if (!isset($timings[$timing]) || !in_array($kind, ['download', 'shipping', 'membership'], true)) {
                throw new \RuntimeException('Invalid BlockSign product timing or fulfillment type.');
            }
            $placement = SignatureSettings::validate([
                'signature_level' => $this->get('level', $channel) ?: 'aes',
                'page' => $this->get('page', $channel) ?: 1,
                'x' => $this->get('x', $channel) === '' ? 50 : $this->get('x', $channel),
                'y' => $this->get('y', $channel) === '' ? 50 : $this->get('y', $channel),
            ]);
            $additional = RequiredSignatures::parseAdditional($this->get('documents', $channel));
            if (isset($rule['documents'])) {
                $additional = array_replace($additional, RequiredSignatures::parseAdditional($rule['documents']));
            }
            $snapshot = ProductFiles::snapshot($rule, $placement, $additional, fn (string $file) => $this->asset($file, $channel), $kind === 'download');
            if ($kind === 'download') {
                $downloads[$id] = ['file' => $snapshot['download_file'], 'hash' => $snapshot['download_hash']];
            }
            if ($kind === 'shipping') {
                $shipping[] = $id;
            }
            if ($kind === 'membership') {
                $group = $rule['group'] ?? '';
                if (!is_string($group) || !preg_match('/\A[a-f0-9]{32}\z/', $group) || ($membership !== null && $membership !== $group)) {
                    throw new \RuntimeException('Configure one Shopware customer group for the membership products in this cart.');
                }
                $membership = $group;
            }
            $protected[$id] = $quantities[$id];
            foreach ($snapshot['documents'] as $document) {
                $key = json_encode([$document['file'], $document['agreement_hash'], $document['placement']], JSON_THROW_ON_ERROR);
                $timings[$timing][$key] = $document;
            }
        }
        if (!$protected) {
            return null;
        }
        $customer = $context->getCustomer();
        if (!$customer || !filter_var($customer->getEmail(), FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('Enter your customer details before requesting BlockSign confirmation.');
        }
        if ($membership && $customer->getGuest()) {
            throw new \RuntimeException('Sign in to buy a BlockSign membership.');
        }
        $total = $cart->getPrice()->getTotalPrice();
        $before = $timings['before_order'] + $timings['before_payment'];
        $after = array_diff_key($timings['after_payment'], $before);
        $state = [
            'channel' => $channel,
            'customer' => $customer->getId(),
            'signer_email' => $customer->getEmail(),
            'currency' => $context->getCurrencyId(),
            'amount' => $total,
            'products' => $protected,
            'downloads' => $downloads,
            'shipping' => $shipping,
            'group' => $membership,
            'documents' => array_values($before),
            'after' => array_values($after),
        ];
        $identity = [$cart->getToken(), $quantities, $state];

        return [hash_hmac('sha256', json_encode($identity, JSON_THROW_ON_ERROR), $this->cartSecret), $state];
    }

    private static function rule(ProductEntity $product, array $rules, string $scope): array
    {
        $rule = $scope === 'all' ? ['kind' => 'download'] : [];
        foreach ($rules['categories'] ?? [] as $id => $category) {
            if ($product->getCategories()?->has($id)) {
                if (isset($rule['timing'], $category['timing']) && DocumentSchedule::earlier($rule['timing'], $category['timing'])) {
                    unset($category['timing']);
                }
                $rule = array_replace($rule, $category);
            }
        }
        $fields = $product->getTranslated()['customFields'] ?? $product->getCustomFields() ?? [];
        if (($fields['blocksign_esign_required'] ?? false) === true) {
            $rule += ['kind' => 'download'];
        }
        return array_replace($rule, $rules['products'][$product->getId()] ?? []);
    }
}
