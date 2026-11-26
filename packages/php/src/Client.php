<?php

declare(strict_types=1);

namespace BlockSign\ESign;

use Closure;
use RuntimeException;

final class Client
{
    public function __construct(private Closure $request)
    {
    }

    public function upload(string $pdf): string
    {
        $boundary = bin2hex(random_bytes(24));
        $body = "--$boundary\r\n"
            . "Content-Disposition: form-data; name=\"file\"; filename=\"agreement.pdf\"\r\n"
            . "Content-Type: application/pdf\r\n\r\n"
            . $pdf . "\r\n--$boundary--\r\n";

        $result = ($this->request)('POST', '/api/v1/documents', "multipart/form-data; boundary=$boundary", $body);

        return self::uuid($result['document_id'] ?? '');
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
