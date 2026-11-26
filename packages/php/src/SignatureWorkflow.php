<?php

declare(strict_types=1);

namespace BlockSign\ESign;

use Closure;
use RuntimeException;

final class SignatureWorkflow
{
    public function __construct(private Client $client)
    {
    }

    public function advance(array $state, Closure $persist, Closure $readAgreement): bool
    {
        $client = $this->client;
        if ($state['phase'] === 'upload') {
            $pdf = $readAgreement();
            if ($pdf === false || strlen($pdf) > 10 * 1024 * 1024
                || !hash_equals($state['agreement_hash'], hash('sha256', $pdf))) {
                throw new RuntimeException('Agreement file does not match the order.');
            }

            $state['phase'] = 'upload_pending_review';
            $persist($state);
            $state['document_id'] = $client->upload($pdf);
            $state['phase'] = 'prepare';
            $persist($state);
        }

        if ($state['phase'] === 'prepare') {
            $placement = SignatureSettings::validate($state['placement'] ?? SignatureSettings::DEFAULTS);
            $state['phase'] = 'prepare_pending_review';
            $persist($state);
            try {
                $state['request_id'] = $client->prepare(
                    $state['document_id'],
                    ['signer_email' => $state['signer_email']],
                    $placement
                );
            } catch (RuntimeException $error) {
                // The documented 409 means the document is not ready or is locked.
                if ($error->getCode() !== 409) {
                    throw $error;
                }
                $state['phase'] = 'prepare';
                $persist($state);
                return true;
            }

            $state['phase'] = 'send';
            $persist($state);
        }

        if ($state['phase'] === 'send') {
            $status = $client->send($state['request_id']);
            if (empty($status['sent_at']) && ($status['completed'] ?? false) !== true) {
                return true;
            }
            $state['phase'] = 'status';
            $persist($state);
        }

        if ($state['phase'] === 'status') {
            $status = $client->status($state['request_id']);
            if (($status['request_id'] ?? null) !== $state['request_id']
                || ($status['document_id'] ?? null) !== $state['document_id']) {
                throw new RuntimeException('BlockSign response does not match the order.');
            }
            if (($status['completed'] ?? false) === true) {
                foreach ($status['signers'] ?? [] as $signer) {
                    if (!empty($signer['signed_at']) && strcasecmp($signer['email'] ?? '', $state['signer_email']) === 0) {
                        $state['completed'] = true;
                        $persist($state);
                        return false;
                    }
                }
                throw new RuntimeException('BlockSign has not confirmed the expected signer.');
            }
            return !in_array($status['status'] ?? '', ['canceled', 'cancelled', 'declined', 'expired'], true);
        }

        return false;
    }
}
