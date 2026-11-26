<?php

declare(strict_types=1);

namespace BlockSign\Shopware;

use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\System\CustomField\CustomFieldTypes;

foreach ([
    'Client', 'Gate', 'SignatureSettings', 'SignatureWorkflow', 'RequiredSignatures',
    'CategoryDocuments', 'ProductFiles', 'ShopConfiguration', 'EvidenceArchive',
] as $file) {
    require_once __DIR__ . '/Core/' . $file . '.php';
}

final class BlockSignEsign extends Plugin
{
    public function install(InstallContext $installContext): void
    {
        $this->container->get('custom_field_set.repository')->upsert([[
            'id' => '53158c57000f4534a540d2c6b05eadde',
            'name' => 'blocksign_esign',
            'config' => ['label' => ['en-GB' => 'BlockSign eSign', 'de-DE' => 'BlockSign eSign']],
            'relations' => [['id' => '6c79f49a294e4d459c4eccb23094d51a', 'entityName' => 'product']],
            'customFields' => [[
                'id' => '1cd38506502d4a3d8ece78c4cc71b213',
                'name' => 'blocksign_esign_required',
                'type' => CustomFieldTypes::BOOL,
                'config' => ['label' => ['en-GB' => 'Require BlockSign confirmation', 'de-DE' => 'BlockSign-Bestätigung erforderlich']],
            ]],
        ]], $installContext->getContext());
    }
}
