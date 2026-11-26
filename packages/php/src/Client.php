<?php

declare(strict_types=1);

namespace BlockSign\ESign;

use Closure;
use RuntimeException;

final class Client
{
    public const MAX_SIGNED_PDF_BYTES = 16 * 1024 * 1024;
    public function __construct(private Closure $request)
    {
    }

    public function upload(string $pdf, bool $deliveryProof = false): string
    {
        $boundary = bin2hex(random_bytes(24));
        $body = "--$boundary\r\n"
            . "Content-Disposition: form-data; name=\"file\"; filename=\"agreement.pdf\"\r\n"
            . "Content-Type: application/pdf\r\n\r\n"
            . $pdf . "\r\n--$boundary--\r\n";

        $path = $deliveryProof ? '/api/upload-document' : '/api/v1/documents';
        $result = ($this->request)('POST', $path, "multipart/form-data; boundary=$boundary", $body);

        return self::uuid($result[$deliveryProof ? 'uuid' : 'document_id'] ?? '');
    }

    public function prepare(string $document, array $signer, array $placement): string
    {
        $body = $signer + $placement + ['document_id' => self::uuid($document)];
        $result = ($this->request)('POST', '/api/v1/signature-requests', 'application/json', json_encode($body, JSON_THROW_ON_ERROR));

        return self::uuid($result['request_id'] ?? '');
    }

    public function send(string $request): array
    {
        return ($this->request)('POST', self::path($request) . '/send', 'application/json', '');
    }

    public function status(string $request): array
    {
        return ($this->request)('GET', self::path($request), 'application/json', '');
    }

    public function documentStatus(string $document): array
    {
        return ($this->request)('GET', '/api/v1/documents/' . self::uuid($document), 'application/json', '');
    }

    public function inviteDelivery(string $document, string $recipient): void
    {
        $body = json_encode(['uuid' => self::uuid($document), 'email' => $recipient, 'type' => 'pod'], JSON_THROW_ON_ERROR);
        $result = ($this->request)('POST', '/api/invite-for-sign', 'application/json', $body);
        if (($result['uuid'] ?? null) !== $document || strcasecmp($result['email'] ?? '', $recipient) !== 0
            || ($result['success'] ?? null) !== 'invitation email sent') {
            throw new RuntimeException('BlockSign did not confirm the expected delivery invitation.');
        }
    }

    public function deliveryAccepted(string $document, string $recipient): bool
    {
        $query = http_build_query(['uuid' => self::uuid($document), 'email' => $recipient], '', '&', PHP_QUERY_RFC3986);
        try {
            $result = ($this->request)('GET', '/api/documents/signed_status?' . $query, 'application/json', '');
        } catch (RuntimeException $error) {
            if ($error->getCode() === 404) {
                return false;
            }
            throw $error;
        }
        return ($result['success'] ?? null) === 'Document is signed';
    }

    public function deliveryPdf(string $document): string
    {
        return $this->pdf('/api/download-document?uuid=' . self::uuid($document));
    }

    public function auditTrail(string $request): array
    {
        $audit = ($this->request)('GET', self::path($request) . '/audit-trail', 'application/json', '');
        if (($audit['request_id'] ?? null) !== $request || !is_array($audit['signatures'] ?? null)) {
            throw new RuntimeException('BlockSign audit trail does not match the signature request.');
        }
        return $audit;
    }

    public function signedPdf(string $request): string
    {
        return $this->pdf(self::path($request) . '/document');
    }

    private function pdf(string $path): string
    {
        $pdf = ($this->request)('GET', $path, 'application/pdf', '');
        if (!is_string($pdf) || strlen($pdf) > self::MAX_SIGNED_PDF_BYTES || !str_starts_with($pdf, '%PDF-')
            || !preg_match('/%%EOF[\x00\x09\x0a\x0c\x0d\x20]*$/D', substr($pdf, -1024))) {
            throw new RuntimeException('BlockSign did not return a complete signed PDF.');
        }
        return $pdf;
    }

    private static function path(string $request): string
    {
        return '/api/v1/signature-requests/' . self::uuid($request);
    }

    private static function uuid(string $value): string
    {
        if (!preg_match('/^[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}$/iD', $value)) {
            throw new RuntimeException('Invalid BlockSign identifier.');
        }

        return $value;
    }
}
