<?php

declare(strict_types=1);

namespace BlockSign\Shopware\Checkout;

use Doctrine\DBAL\Connection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\UpdateCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Validation\PreWriteValidationEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class ShippingGuard implements EventSubscriberInterface
{
    public function __construct(
        private readonly Connection $db,
        private readonly Jobs $jobs,
        private readonly Fulfillment $fulfillment,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [PreWriteValidationEvent::class => 'check'];
    }

    public function check(PreWriteValidationEvent $event): void
    {
        $context = $event->getContext();
        if ($context->getVersionId() !== Defaults::LIVE_VERSION) {
            return;
        }
        foreach ($event->getCommands() as $command) {
            if (!$command instanceof UpdateCommand || $command->getEntityName() !== 'order_delivery' || !$command->hasField('state_id')) {
                continue;
            }
            $id = $this->db->fetchOne(
                "SELECT LOWER(HEX(d.order_id)) FROM order_delivery d
                 INNER JOIN state_machine_state s ON s.id = UNHEX(?) AND s.technical_name IN ('shipped', 'shipped_partially')
                 WHERE d.id = UNHEX(?) AND d.version_id = UNHEX(?)",
                [bin2hex($command->getPayload()['state_id']), $command->getDecodedPrimaryKey()['id'], Defaults::LIVE_VERSION]
            );
            if (!$id) {
                continue;
            }
            $state = $this->jobs->find($id)['data'] ?? [];
            if (!empty($state['shipping']) && !$this->fulfillment->allowed($this->fulfillment->order($id, $context), $state)) {
                throw new ConflictHttpException('BlockSign confirmation and payment are required before shipment.');
            }
        }
    }
}
