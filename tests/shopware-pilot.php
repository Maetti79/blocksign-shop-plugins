<?php

declare(strict_types=1);

namespace Shopware\Core\Checkout\Cart\LineItem {
    final class LineItem
    {
        public const PRODUCT_LINE_ITEM_TYPE = 'product';

        public function __construct(private string $id, private int $quantity = 1) {}
        public function getType(): string { return 'product'; }
        public function getReferencedId(): string { return $this->id; }
        public function getQuantity(): int { return $this->quantity; }
    }
}

namespace Shopware\Core\Checkout\Cart {
    final class Cart
    {
        public function __construct(private array $items, private float $amount) {}
        public function getToken(): string { return 'storefront-session'; }
        public function getLineItems(): object
        {
            return new class($this->items) {
                public function __construct(private array $items) {}
                public function getFlat(): array { return $this->items; }
            };
        }
        public function getPrice(): object
        {
            return new class($this->amount) {
                public function __construct(private float $amount) {}
                public function getTotalPrice(): float { return $this->amount; }
            };
        }
    }
}

namespace Shopware\Core\Content\Product {
    final class ProductEntity
    {
        public function __construct(private string $id, private bool $marked = false) {}
        public function getId(): string { return $this->id; }
        public function getCustomFields(): array { return ['blocksign_esign_required' => $this->marked]; }
        public function getTranslated(): array { return []; }
        public function getCategories(): object
        {
            return new class {
                public function has(string $id): bool { return $id === 'category'; }
            };
        }
    }
}

namespace Shopware\Core\Framework\DataAbstractionLayer\Search {
    final class Criteria
    {
        public function __construct(public array $ids) {}
        public function addAssociation(string $name): self { return $this; }
    }
}

namespace Shopware\Core\Framework\DataAbstractionLayer {
    use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;

    final class EntityRepository
    {
        public function __construct(private array $products) {}
        public function search(Criteria $criteria, mixed $context): iterable
        {
            return array_intersect_key($this->products, array_flip($criteria->ids));
        }
    }
}

namespace Shopware\Core\System\SalesChannel {
    final class SalesChannelContext
    {
        public function getSalesChannelId(): string { return 'test-channel'; }
        public function getCurrencyId(): string { return 'eur'; }
        public function getContext(): mixed { return null; }
        public function getCustomer(): object
        {
            return new class {
                public function getEmail(): string { return 'shopper@example.test'; }
                public function getId(): string { return 'customer-id'; }
                public function getGuest(): bool { return false; }
            };
        }
    }
}

namespace Shopware\Core\System\SystemConfig {
    final class SystemConfigService
    {
        public function __construct(public array $values) {}
        public function get(string $key, string $channel): mixed
        {
            return $this->values[str_replace('BlockSignEsign.config.', '', $key)] ?? null;
        }
    }
}

namespace {
    use BlockSign\Shopware\Checkout\Rules;
    use Shopware\Core\Checkout\Cart\Cart;
    use Shopware\Core\Checkout\Cart\LineItem\LineItem;
    use Shopware\Core\Content\Product\ProductEntity;
    use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
    use Shopware\Core\System\SalesChannel\SalesChannelContext;
    use Shopware\Core\System\SystemConfig\SystemConfigService;

    $plugin = $argv[1] ?? 'build/BlockSignEsign';
    foreach (['ProductFiles', 'RequiredSignatures', 'SignatureSettings', 'ShopConfiguration'] as $class) {
        require "$plugin/src/Core/$class.php";
    }
    require "$plugin/src/Checkout/Rules.php";

    $dir = sys_get_temp_dir() . '/blocksign-shopware-' . bin2hex(random_bytes(8));
    mkdir($dir, 0700);
    register_shutdown_function(static function () use ($dir): void {
        foreach (glob("$dir/*.pdf") as $path) {
            unlink($path);
        }
        rmdir($dir);
    });
    foreach (['agreement.pdf', 'early.pdf', 'late.pdf', 'download.pdf', 'mandate.pdf'] as $file) {
        file_put_contents("$dir/$file", '%PDF ' . $file);
    }
    $config = new SystemConfigService([
        'enabled' => true,
        'pdfDirectory' => $dir,
        'level' => 'fes',
        'productRules' => json_encode([
            'categories' => ['category' => ['kind' => 'shipping', 'timing' => 'before_order', 'agreement' => 'early.pdf']],
            'products' => ['late' => ['kind' => 'download', 'timing' => 'after_payment', 'agreement' => 'late.pdf', 'documents' => 'mandate.pdf | POD']],
        ], JSON_THROW_ON_ERROR),
    ]);
    $repo = new EntityRepository(['early' => new ProductEntity('early'), 'late' => new ProductEntity('late')]);
    $rules = new Rules($config, $repo, __DIR__, 'local-secret');
    $context = new SalesChannelContext();
    $cart = new Cart([new LineItem('late'), new LineItem('early')], 24.0);
    [$proof, $state] = $rules->cart($cart, $context);
    if (strlen($proof) !== 64 || array_column($state['documents'], 'file') !== ['early.pdf']
        || array_column($state['after'], 'file') !== ['late.pdf', 'mandate.pdf'] || $state['shipping'] !== ['early']
        || ($state['downloads']['late']['file'] ?? '') !== 'download.pdf'
        || $state['after'][1]['placement']['signature_level'] !== 'pod'
        || $state['documents'][0]['placement']['signature_level'] !== 'fes') {
        throw new RuntimeException('Product and category rules did not keep the intended document phases.');
    }
    $changed = new Cart([new LineItem('late'), new LineItem('early', 2)], 24.0);
    if ($rules->cart($changed, $context)[0] === $proof) {
        throw new RuntimeException('Changed quantities kept an old confirmation fingerprint.');
    }
    $reordered = new Cart([new LineItem('early'), new LineItem('late')], 24.0);
    if ($rules->cart($reordered, $context)[0] !== $proof || $state['products'] !== ['early' => 1, 'late' => 1]) {
        throw new RuntimeException('The confirmation must be stable across line order and retain protected quantities.');
    }
    $config->values['enabled'] = false;
    if ($rules->cart($cart, $context) !== null) {
        throw new RuntimeException('A disabled sales channel was blocked.');
    }
    echo "PASS Shopware product and category documents, payment phases, and cart binding\n";
}
