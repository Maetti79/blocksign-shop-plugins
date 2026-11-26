<?php

declare(strict_types=1);

namespace Plugin\blocksign_esign;

use BlockSign\ESign\Client;
use BlockSign\ESign\EvidenceArchive;
use BlockSign\ESign\RequiredSignatures;
use BlockSign\ESign\SignatureWorkflow;
use RuntimeException;
use Throwable;

final class Worker
{
    public function __construct(private readonly Orders $orders, private readonly Settings $settings)
    {
    }

    public static function client(string $key): Client
    {
        if (!preg_match('/\A[!-~]{1,512}\z/', $key)) {
            throw new RuntimeException('Configure the BlockSign API key.');
        }
        return new Client(static function (string $method, string $path, string $type, string $body) use ($key): array|string {
            $curl = curl_init('https://blocksign.io' . $path);
            $data = '';
            $limit = $type === 'application/pdf' ? Client::MAX_SIGNED_PDF_BYTES : 1048576;
            curl_setopt_array($curl, [
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $key, 'Content-Type: ' . $type, 'Accept: ' . ($type === 'application/pdf' ? $type : 'application/json')],
                CURLOPT_TIMEOUT => 25,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$data, $limit): int {
                    if (strlen($data) + strlen($chunk) > $limit) {
                        return 0;
                    }
                    $data .= $chunk;
                    return strlen($chunk);
                },
            ]);
            if ($method === 'POST') {
                curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
            }
            $success = curl_exec($curl);
            $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            if ($success === false || $status < 200 || $status >= 300) {
                throw new RuntimeException('BlockSign API request failed.', $success === false ? 0 : $status);
            }
            return $type === 'application/pdf' ? $data : json_decode($data, true, 512, JSON_THROW_ON_ERROR);
        });
    }

    public function run(SignatureWorkflow $workflow, ?EvidenceArchive $archive = null): void
    {
        if (!$this->orders->lock()) {
            return;
        }
        try {
            foreach ($this->orders->due() as $id) {
                try {
                    $this->process((int) $id, $workflow, $archive);
                } catch (Throwable $error) {
                    error_log('BlockSign JTL-Shop worker could not process an order.');
                }
            }
        } finally {
            $this->orders->unlock();
        }
    }

    private function process(int $id, SignatureWorkflow $workflow, ?EvidenceArchive $archive): void
    {
        $state = $this->orders->find($id);
        $order = $this->orders->order($id);
        if (!$state) {
            return;
        }
        if (!$this->orders->eligible($order, $state)) {
            $this->orders->save($id, $state, 0);
            return;
        }
        if (!Orders::paid($order, $state)) {
            return;
        }
        $now = time();
        if (!isset($state['started_at'])) {
            $state['started_at'] = $now;
            $this->orders->save($id, $state, $now);
        }
        if (RequiredSignatures::needsReview($state) || $now >= $state['started_at'] + 604800) {
            $this->orders->save($id, $state, 0);
            return;
        }

        $retry = false;
        try {
            $retry = RequiredSignatures::advance(
                $workflow, $state,
                function (array $next) use ($id, &$state): void {
                    $this->orders->save($id, $next, time());
                    $state = $next;
                },
                fn (string $file) => file_get_contents($this->settings->asset($file), false, null, 0, 10 * 1024 * 1024 + 1)
            );
            $directory = $archive && RequiredSignatures::complete($state) ? $this->settings->archive() : null;
            if ($directory !== null && $this->orders->allowed($this->orders->order($id), $state)) {
                $retry = $archive->storeNext($directory, $this->settings->webRoot(), 'jtl-' . $id, $state);
            }
        } catch (Throwable $error) {
            $retry = !RequiredSignatures::needsReview($state);
        }
        $this->orders->save($id, $state, $retry ? time() + 120 : 0);
    }
}
