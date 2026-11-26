<?php

declare(strict_types=1);

namespace BlockSign\PrestaCheckout;

use Closure;
use InvalidArgumentException;
use RuntimeException;

final class StripeCheckout
{
    public const EVENTS = ['checkout.session.completed', 'checkout.session.async_payment_succeeded'];

    public function __construct(private readonly Closure $request, private readonly bool $live)
    {
    }

    public static function configured(): bool
    {
        return preg_match('/\A(?:sk|rk)_(?:live|test)_[a-zA-Z0-9]{8,}\z/', getenv('BLOCKSIGN_STRIPE_SECRET_KEY') ?: '') === 1
            && preg_match('/\Awhsec_[a-zA-Z0-9]{8,}\z/', getenv('BLOCKSIGN_STRIPE_WEBHOOK_SECRET') ?: '') === 1;
    }

    public static function client(): self
    {
        if (!self::configured()) {
            throw new RuntimeException('Stripe Checkout credentials are unavailable.');
        }
        $key = (string) getenv('BLOCKSIGN_STRIPE_SECRET_KEY');
        return new self(static function (string $method, string $path, array $body, ?string $idempotency) use ($key): array {
            $curl = curl_init('https://api.stripe.com' . $path);
            $headers = ['Authorization: Bearer ' . $key, 'Accept: application/json'];
            if ($idempotency !== null) {
                $headers[] = 'Idempotency-Key: ' . $idempotency;
            }
            $response = '';
            curl_setopt_array($curl, [
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 20,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$response): int {
                    if (strlen($response) + strlen($chunk) > 1048576) {
                        return 0;
                    }
                    $response .= $chunk;
                    return strlen($chunk);
                },
            ]);
            if ($method === 'POST') {
                curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($body, '', '&', PHP_QUERY_RFC3986));
            }
            $ok = curl_exec($curl);
            $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            if ($ok === false || $status < 200 || $status >= 300) {
                throw new RuntimeException('Stripe Checkout request failed.', $status);
            }
            return json_decode($response, true, 512, JSON_THROW_ON_ERROR);
        }, preg_match('/\A(?:sk|rk)_live_/', $key) === 1);
    }

    public static function minor(float $total): int
    {
        $cents = round($total * 100);
        if (!is_finite($total) || abs($total * 100 - $cents) > 0.00001 || $cents < 50 || $cents > 99999999) {
            throw new InvalidArgumentException('Stripe Checkout requires an exact EUR amount of at least 0.50.');
        }
        return (int) $cents;
    }

    public static function methods(string $configuration): array
    {
        $methods = array_values(array_unique(array_map('trim', explode(',', $configuration ?: 'card,paypal,sepa_debit'))));
        if (array_diff($methods, ['card', 'paypal', 'sepa_debit'])) {
            throw new InvalidArgumentException('Configure only card, paypal or sepa_debit for this checkout.');
        }
        return $methods;
    }

    public function create(int $order, int $shop, string $reference, int $amount, string $email, string $returnUrl, array $methods, string $key): array
    {
        $session = ($this->request)('POST', '/v1/checkout/sessions', [
            'mode' => 'payment',
            'client_reference_id' => (string) $order,
            'customer_email' => $email,
            'payment_method_types' => $methods,
            'line_items' => [[
                'price_data' => ['currency' => 'eur', 'unit_amount' => $amount, 'product_data' => ['name' => 'Bestellung ' . $reference]],
                'quantity' => 1,
            ]],
            'metadata' => ['blocksign_order' => (string) $order, 'blocksign_shop' => (string) $shop],
            'success_url' => $returnUrl,
            'cancel_url' => $returnUrl,
        ], $key);
        $this->assertSession($session, $order, $shop, $amount);
        return $session;
    }

    public function retrieve(string $id): array
    {
        return ($this->request)('GET', '/v1/checkout/sessions/' . self::sessionId($id), [], null);
    }

    public function expire(string $id): array
    {
        return ($this->request)('POST', '/v1/checkout/sessions/' . self::sessionId($id) . '/expire', [], null);
    }

    public function assertSession(array $session, int $order, int $shop, int $amount): void
    {
        self::sessionId($session['id'] ?? '');
        if (($session['livemode'] ?? null) !== $this->live || ($session['mode'] ?? null) !== 'payment'
            || ($session['client_reference_id'] ?? null) !== (string) $order
            || ($session['metadata']['blocksign_order'] ?? null) !== (string) $order
            || ($session['metadata']['blocksign_shop'] ?? null) !== (string) $shop
            || ($session['currency'] ?? null) !== 'eur' || ($session['amount_total'] ?? null) !== $amount) {
            throw new RuntimeException('Stripe Checkout session does not match the order.');
        }
    }

    public static function sessionId(string $id): string
    {
        if (!preg_match('/\Acs_(?:test|live)_[a-zA-Z0-9]{5,128}\z/', $id)) {
            throw new InvalidArgumentException('Invalid Stripe Checkout session.');
        }
        return $id;
    }

    public static function redirectUrl(array $session): string
    {
        $url = $session['url'] ?? '';
        $parts = is_string($url) ? parse_url($url) : false;
        if (($session['status'] ?? null) !== 'open' || !$parts || ($parts['scheme'] ?? '') !== 'https'
            || ($parts['host'] ?? '') !== 'checkout.stripe.com' || (isset($parts['user']) || isset($parts['pass']))
            || isset($parts['port']) || !str_starts_with($parts['path'] ?? '', '/c/pay/')) {
            throw new RuntimeException('Stripe Checkout returned an invalid payment link.');
        }
        return $url;
    }

    public static function paidReference(array $session): ?string
    {
        if (($session['status'] ?? null) !== 'complete' || ($session['payment_status'] ?? null) !== 'paid') {
            return null;
        }
        $reference = $session['payment_intent'] ?? '';
        if (!is_string($reference) || !preg_match('/\Api_[a-zA-Z0-9]{5,128}\z/', $reference)) {
            throw new RuntimeException('Stripe Checkout returned an invalid payment reference.');
        }
        return $reference;
    }

    public static function event(string $payload, string $signature, string $secret, int $now): array
    {
        $values = [];
        foreach (explode(',', $signature) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            $values[$key][] = $value;
        }
        $timestamps = $values['t'] ?? [];
        if ($secret === '' || count($timestamps) !== 1 || !preg_match('/\A[0-9]{10}\z/', $timestamps[0])
            || abs($now - (int) $timestamps[0]) > 300) {
            throw new InvalidArgumentException('Invalid Stripe webhook timestamp.');
        }
        $hash = hash_hmac('sha256', $timestamps[0] . '.' . $payload, $secret);
        foreach ($values['v1'] ?? [] as $candidate) {
            if (hash_equals($hash, $candidate)) {
                $value = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
                if (is_array($value)) {
                    return $value;
                }
            }
        }
        throw new InvalidArgumentException('Invalid Stripe webhook signature.');
    }
}
