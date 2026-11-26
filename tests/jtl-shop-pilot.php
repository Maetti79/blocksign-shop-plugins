<?php

declare(strict_types=1);

namespace JTL\DB {
    interface DbInterface {}
}

namespace {
    use BlockSign\ESign\Client;
    use BlockSign\ESign\SignatureWorkflow;
    use JTL\DB\DbInterface;
    use Plugin\blocksign_esign\Orders;
    use Plugin\blocksign_esign\Settings;
    use Plugin\blocksign_esign\Worker;

    define('C_WARENKORBPOS_TYP_ARTIKEL', 1);
    define('BESTELLUNG_STATUS_STORNO', -1);

    final class FixtureDb implements DbInterface
    {
        public array $orders = [];
        public array $jobs = [];
        public bool $locked = false;

        public function getSingleObject(string $sql, array $params): ?stdClass
        {
            return str_contains($sql, 'FROM tbestellung b') ? ($this->orders[$params['id']] ?? null)
                : (isset($this->jobs[$params['id']]) ? (object) $this->jobs[$params['id']] : null);
        }

        public function getObjects(string $sql, array $params): array
        {
            return [(object) ['kArtikel' => 7, 'nAnzahl' => '1.000']];
        }

        public function getInts(string $sql, string $column, array $params): array
        {
            return $column === 'kKategorie' ? [21] : array_keys(array_filter(
                $this->jobs, static fn (array $job): bool => $job['next'] > 0 && $job['next'] <= time()
            ));
        }

        public function getSingleInt(string $sql, string $column): int
        {
            if (str_contains($sql, 'RELEASE_LOCK')) {
                $this->locked = false;
                return 1;
            }
            if ($this->locked) {
                return 0;
            }
            $this->locked = true;
            return 1;
        }

        public function getAffectedRows(string $sql, array $params): int
        {
            $this->jobs[$params['id']] = ['state' => $params['state'], 'next' => $params['next']];
            return 1;
        }
    }

    function requireState(bool $condition, string $failure): void
    {
        if (!$condition) {
            throw new RuntimeException($failure);
        }
    }

    require ($argv[1] ?? 'build/blocksign_esign') . '/src/load.php';
    $files = sys_get_temp_dir() . '/blocksign-jtl-' . bin2hex(random_bytes(8));
    mkdir($files, 0700);
    foreach (['agreement.pdf', 'category.pdf', 'download.pdf'] as $name) {
        file_put_contents($files . '/' . $name, '%PDF fixture');
    }
    register_shutdown_function(static function () use ($files): void {
        foreach (glob($files . '/*.pdf') as $file) {
            unlink($file);
        }
        rmdir($files);
    });

    $settings = new Settings(['files' => $files, 'categories' => [21 => [
        'agreement' => 'category.pdf', 'placement' => ['signature_level' => 'fes', 'page' => 2],
    ]]], __DIR__);
    $db = new FixtureDb();
    foreach ([42, 43, 44] as $id) {
        $db->orders[$id] = (object) [
            'kBestellung' => $id, 'kWarenkorb' => $id + 5, 'kKunde' => 2, 'kWaehrung' => 1,
            'cStatus' => 1, 'cRegistriert' => $id === 44 ? 'N' : 'Y', 'cMail' => 'member@example.test',
            'fGesamtsumme' => $id === 43 ? '0.00' : '25.00', 'refunded' => false, 'dBezahltDatum' => '0000-00-00 00:00:00',
        ];
    }
    $orders = new Orders($db);
    $orders->register(42, $settings);
    $orders->register(42, $settings);
    $orders->register(43, $settings);
    $orders->register(44, $settings);
    requireState(array_keys($db->jobs) === [42, 43], 'a duplicate or unsigned guest enrollment was accepted');
    $snapshot = $orders->find(42);
    requireState($snapshot['documents'][0]['file'] === 'category.pdf' && $snapshot['documents'][0]['placement']['signature_level'] === 'fes',
        'the configuration did not select the category document and its placement');

    $documentId = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';
    $requestId = 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb';
    $base = '/api/v1/signature-requests/' . $requestId;
    $calls = 0;
    $workflow = new SignatureWorkflow(new Client(static function (string $method, string $path, string $type, string $body) use (&$calls, $documentId, $requestId, $base): array {
        ++$calls;
        if ($path === '/api/v1/signature-requests') {
            $settings = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            requireState($settings['signature_level'] === 'fes' && $settings['page'] === 2, 'configured placement was not sent');
        }
        return match ([$method, $path]) {
            ['POST', '/api/v1/documents'] => ['document_id' => $documentId],
            ['POST', '/api/v1/signature-requests'] => ['request_id' => $requestId],
            ['POST', $base . '/send'] => ['sent_at' => date(DATE_ATOM)],
            ['GET', $base] => ['document_id' => $documentId, 'request_id' => $requestId, 'completed' => true,
                'signers' => [['email' => 'member@example.test', 'signed_at' => date(DATE_ATOM)]]],
        };
    }));
    $worker = new Worker($orders, $settings);
    $worker->run($workflow);
    requireState($calls === 4 && !$orders->allowed($db->orders[42], $orders->find(42)) && $orders->allowed($db->orders[43], $orders->find(43)),
        'the worker did not distinguish an unpaid order from a free order');

    $db->orders[42]->dBezahltDatum = '2026-11-26 12:00:00';
    $worker->run($workflow);
    requireState($calls === 8 && $orders->allowed($db->orders[42], $orders->find(42)), 'the paid order did not unlock after its signature');
    $db->orders[42]->refunded = true;
    requireState(!$orders->allowed($db->orders[42], $orders->find(42)), 'a refunded order retained its download');
    $db->orders[43]->cStatus = BESTELLUNG_STATUS_STORNO;
    requireState(!$orders->allowed($db->orders[43], $orders->find(43)), 'a cancelled order retained its download');
    requireState(!$db->locked, 'the worker did not release its advisory lock');
    echo "PASS JTL-Shop category documents, paid and free orders, signatures, and revoked downloads\n";
}
