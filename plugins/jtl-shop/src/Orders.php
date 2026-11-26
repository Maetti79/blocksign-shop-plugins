<?php

declare(strict_types=1);

namespace Plugin\blocksign_esign;

use BlockSign\ESign\Gate;
use BlockSign\ESign\OrderFiles;
use BlockSign\ESign\RequiredSignatures;
use JTL\DB\DbInterface;
use stdClass;

final class Orders
{
    private const TABLE = 'xplugin_blocksign_esign_order';

    public function __construct(private readonly DbInterface $db)
    {
    }

    public function order(int $id): ?stdClass
    {
        return $this->db->getSingleObject(
            'SELECT b.kBestellung, b.kWarenkorb, b.kKunde, b.kWaehrung, b.cStatus, b.cBestellNr,
                    b.fGesamtsumme, b.dBezahltDatum, k.cMail, k.cRegistriert,
                    EXISTS (SELECT 1 FROM tzahlungseingang z WHERE z.kBestellung = b.kBestellung AND z.fBetrag < 0) refunded
               FROM tbestellung b INNER JOIN tkunde k ON k.kKunde = b.kKunde
              WHERE b.kBestellung = :id',
            ['id' => $id]
        );
    }

    public function lines(int $cart): array
    {
        $rows = $this->db->getObjects(
            'SELECT kArtikel, nAnzahl FROM twarenkorbpos
              WHERE kWarenkorb = :cart AND nPosTyp = :type AND kArtikel > 0
              ORDER BY kArtikel, kWarenkorbPos',
            ['cart' => $cart, 'type' => \C_WARENKORBPOS_TYP_ARTIKEL]
        );
        return array_map(static fn (stdClass $row): array => [(int) $row->kArtikel, (string) $row->nAnzahl], $rows);
    }

    public function register(int $id, Settings $settings): void
    {
        if ($this->find($id)) {
            return;
        }
        $order = $this->order($id);
        if (!$order || (string) $order->cRegistriert !== 'Y' || !filter_var($order->cMail, FILTER_VALIDATE_EMAIL)
            || (int) $order->cStatus === \BESTELLUNG_STATUS_STORNO || (float) $order->fGesamtsumme < 0) {
            return;
        }

        $products = [];
        $lines = $this->lines((int) $order->kWarenkorb);
        foreach (array_unique(array_column($lines, 0)) as $product) {
            $categories = $this->db->getInts(
                'SELECT kKategorie FROM tkategorieartikel WHERE kArtikel = :product ORDER BY kKategorie',
                'kKategorie',
                ['product' => $product]
            );
            $files = $settings->snapshot($product, $categories);
            if ($files) {
                $products[$product] = $files;
            }
        }
        if (!$products) {
            return;
        }

        $state = OrderFiles::combine($products) + [
            'timing' => (float) $order->fGesamtsumme === 0.0 ? 'without_payment' : 'after_payment',
            'customer' => (int) $order->kKunde,
            'currency' => (int) $order->kWaehrung,
            'amount' => (float) $order->fGesamtsumme,
            'signer_email' => $order->cMail,
            'lines' => $lines,
        ];
        $this->db->getAffectedRows(
            'INSERT IGNORE INTO ' . self::TABLE . ' (kBestellung, kKunde, state, next_attempt)
             VALUES (:id, :customer, :state, :next)',
            ['id' => $id, 'customer' => $state['customer'], 'state' => json_encode($state, JSON_THROW_ON_ERROR), 'next' => time()]
        );
    }

    public function find(int $id): ?array
    {
        $row = $this->db->getSingleObject('SELECT state FROM ' . self::TABLE . ' WHERE kBestellung = :id', ['id' => $id]);
        return $row ? json_decode($row->state, true, 512, JSON_THROW_ON_ERROR) : null;
    }

    public function forCustomer(int $id): array
    {
        return $this->db->getObjects(
            'SELECT p.kBestellung AS id, b.cBestellNr AS number FROM ' . self::TABLE . ' p
             INNER JOIN tbestellung b ON b.kBestellung = p.kBestellung AND b.kKunde = p.kKunde
             WHERE p.kKunde = :customer ORDER BY p.kBestellung DESC LIMIT 50',
            ['customer' => $id]
        );
    }

    public function eligible(?stdClass $order, array $state): bool
    {
        return $order !== null && (int) $order->kKunde === $state['customer'] && $order->cRegistriert === 'Y'
            && (int) $order->kWaehrung === $state['currency'] && (float) $order->fGesamtsumme === (float) $state['amount']
            && (int) $order->cStatus !== \BESTELLUNG_STATUS_STORNO && !(bool) $order->refunded
            && strcasecmp($order->cMail, $state['signer_email']) === 0
            && $this->lines((int) $order->kWarenkorb) === $state['lines'];
    }

    public static function paid(stdClass $order, array $state): bool
    {
        $date = (string) $order->dBezahltDatum;
        return $state['timing'] === 'without_payment' ? (float) $order->fGesamtsumme === 0.0
            : $date !== '' && !str_starts_with($date, '0000-00-00') && strtotime($date) > 0;
    }

    public function allowed(?stdClass $order, array $state): bool
    {
        return $this->eligible($order, $state) && self::paid($order, $state) && Gate::allows('fulfillment', [[
            'timing' => $state['timing'], 'completed' => RequiredSignatures::complete($state),
        ]]);
    }

    public function due(): array
    {
        return $this->db->getInts(
            'SELECT p.kBestellung FROM ' . self::TABLE . ' p
              INNER JOIN tbestellung b ON b.kBestellung = p.kBestellung
             WHERE p.next_attempt > 0 AND p.next_attempt <= :now
               AND (b.fGesamtsumme = 0 OR b.dBezahltDatum > :unpaid OR b.cStatus = :cancelled)
             ORDER BY p.next_attempt LIMIT 20',
            'kBestellung',
            ['now' => time(), 'unpaid' => '1000-01-01 00:00:00', 'cancelled' => \BESTELLUNG_STATUS_STORNO]
        );
    }

    public function save(int $id, array $state, int $next): void
    {
        $this->db->getAffectedRows('UPDATE ' . self::TABLE . ' SET state = :state, next_attempt = :next WHERE kBestellung = :id',
            ['state' => json_encode($state, JSON_THROW_ON_ERROR), 'next' => $next, 'id' => $id]);
    }

    public function lock(): bool
    {
        return $this->db->getSingleInt("SELECT GET_LOCK(CONCAT('blocksign-jtl-', LEFT(SHA2(DATABASE(), 256), 32)), 0) AS result", 'result') === 1;
    }

    public function unlock(): void
    {
        $this->db->getSingleInt("SELECT RELEASE_LOCK(CONCAT('blocksign-jtl-', LEFT(SHA2(DATABASE(), 256), 32))) AS result", 'result');
    }
}
