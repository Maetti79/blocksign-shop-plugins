<?php

declare(strict_types=1);

namespace BlockSign\Shopware\Command;

use BlockSign\ESign\Client;
use BlockSign\ESign\EvidenceArchive;
use BlockSign\ESign\RequiredSignatures;
use BlockSign\ESign\SignatureWorkflow;
use BlockSign\Shopware\Checkout\Fulfillment;
use BlockSign\Shopware\Checkout\Jobs;
use BlockSign\Shopware\Checkout\Rules;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(name: 'blocksign:process', description: 'Send and check due BlockSign confirmations')]
final class ProcessConfirmations extends Command
{
    public function __construct(
        private readonly Jobs $jobs,
        private readonly Rules $rules,
        private readonly Fulfillment $fulfillment,
        private readonly HttpClientInterface $http,
        private readonly LoggerInterface $logger,
        private readonly string $publicDir,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->jobs->lock()) {
            return self::SUCCESS;
        }
        try {
            foreach ($this->jobs->due() as $id) {
                $job = $this->jobs->find($id);
                if (!$job) {
                    continue;
                }

                $state = $job['data'];
                $next = 0;
                try {
                    $next = $this->process($job, $state);
                } catch (\Throwable) {
                    $this->logger->warning('A BlockSign confirmation or fulfillment step failed.', ['job' => $id]);
                    $next = time() < (int) $job['expires'] ? time() + 300 : 0;
                }
                $this->jobs->save($id, $state, $next);
            }

            return self::SUCCESS;
        } finally {
            $this->jobs->unlock();
        }
    }

    private function process(array $job, array &$state): int
    {
        $id = $job['id'];
        $isOrder = strlen($id) === 32;
        $context = Context::createDefaultContext();
        $order = $isOrder ? $this->fulfillment->order($id, $context) : null;
        $now = time();
        if ($isOrder && !$this->fulfillment->eligible($order, $state)) {
            return 0;
        }
        if ($order && !$this->fulfillment->paid($order)) {
            return $now < (int) $job['expires'] ? $now + 120 : 0;
        }
        if ($now >= (int) $job['expires'] || RequiredSignatures::needsReview($state)) {
            return 0;
        }
        $channel = $state['channel'];
        $client = $this->client($this->rules->apiKey($channel));
        if (!RequiredSignatures::complete($state)) {
            $retry = RequiredSignatures::advance(new SignatureWorkflow($client), $state, function (array $updated) use ($id, &$state): void {
                $this->jobs->save($id, $updated, time());
                $state = $updated;
            }, fn (string $file) => file_get_contents($this->rules->asset($file, $channel), false, null, 0, 10 * 1024 * 1024 + 1));
            if ($retry || !RequiredSignatures::complete($state)) {
                return $retry ? $now + 120 : 0;
            }
        }

        if ($order && $this->fulfillment->allowed($this->fulfillment->order($id, $context), $state)) {
            if (empty($state['membership_granted'])) {
                $this->fulfillment->grant($state, $context);
                $state['membership_granted'] = true;
                $this->jobs->save($id, $state, $now);
            }
            $archive = getenv('BLOCKSIGN_ESIGN_ARCHIVE') ?: $this->rules->get('archiveDirectory', $channel);
            if ($archive && (new EvidenceArchive($client))->storeNext($archive, $this->publicDir, 'shopware-' . $id, $state)) {
                return $now + 60;
            }
        }

        return 0;
    }

    private function client(string $key): Client
    {
        return new Client(function (string $method, string $path, string $type, string $body) use ($key): array|string {
            $response = $this->http->request($method, 'https://blocksign.io' . $path, [
                'headers' => ['Authorization' => 'Bearer ' . $key, 'Content-Type' => $type, 'Accept' => $type],
                'body' => $body, 'max_redirects' => 0, 'max_duration' => 25, 'buffer' => false,
            ]);
            $status = $response->getStatusCode();
            if ($status < 200 || $status >= 300) {
                $response->cancel();
                throw new \RuntimeException('The BlockSign API call failed.', $status);
            }
            $limit = $type === 'application/pdf' ? Client::MAX_SIGNED_PDF_BYTES : 1048576;
            $data = '';
            foreach ($this->http->stream($response) as $chunk) {
                $data .= $chunk->getContent();
                if (strlen($data) > $limit) {
                    $response->cancel();
                    throw new \RuntimeException('The BlockSign response exceeded the size limit.');
                }
            }
            return $type === 'application/pdf' ? $data : json_decode($data, true, 512, JSON_THROW_ON_ERROR);
        });
    }
}
