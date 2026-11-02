<?php

namespace App\Domain\Shipping;

use App\Models\CourierIntegration;
use App\Models\Order;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Steadfast adapter (merchant API guide: base URL, headers Api-Key / Secret-Key, no token step).
 *
 * Facts relied on, from the guide:
 *  - POST /create_order {invoice, recipient_name, recipient_phone, recipient_address, cod_amount, note?}
 *  - duplicate invoices are refused, so a retry with the same invoice cannot double-book
 *  - GET /status_by_invoice/{invoice}; answers are cached ~60 s
 *  - 401 means "not authenticated OR not yours". Verified live: an invoice that does not exist also answers
 *    401 "Unauthorized Access", so a 401 here is ambiguous and is disambiguated with a key check (/get_balance).
 *  - lengths: invoice 100, name 100, phone 40, address 490, note 480; the provider strips { } ; < > $ from text.
 *
 * Response bodies are parsed defensively. Anything unexpected becomes `unknown`, never a guessed success.
 */
class SteadfastProvider
{
    public function __construct(private CourierIntegration $integration) {}

    private function base(): string
    {
        return rtrim(config('couriers.steadfast.base_url'), '/'); // platform-configured host only
    }

    private function http()
    {
        $c = $this->integration->credentials ?? [];

        return Http::withHeaders(['Api-Key' => $c['api_key'] ?? '', 'Secret-Key' => $c['secret_key'] ?? ''])
            ->acceptJson()->timeout(config('couriers.steadfast.timeout'));
    }

    public function testConnection(): ConnectionResult
    {
        $c = $this->integration->credentials ?? [];
        if (empty($c['api_key']) || empty($c['secret_key'])) {
            return new ConnectionResult(false, 'missing_credentials', 'Enter both the API key and the secret key.');
        }

        try {
            $res = $this->http()->get($this->base().'/get_balance');
        } catch (ConnectionException) {
            return new ConnectionResult(false, 'unreachable', 'Could not reach Steadfast. Check your connection and try again.');
        }

        return match (true) {
            $res->successful() => new ConnectionResult(true, 'ok', 'Connected to Steadfast.'),
            $res->status() === 401 => new ConnectionResult(false, 'invalid_credentials', 'Steadfast rejected these keys. Check the API key and secret key.'),
            $res->status() === 403 => new ConnectionResult(false, 'not_allowed', 'Your Steadfast account is inactive or needs its ownership details resubmitted. Contact Steadfast.'),
            $res->status() === 429 => new ConnectionResult(false, 'rate_limited', 'Steadfast is temporarily refusing requests from this server (too many failed attempts). Wait about an hour before trying again.'),
            default => new ConnectionResult(false, 'provider_error_'.$res->status(), 'Steadfast returned an error. Try again later.'),
        };
    }

    /** Provider-side text hygiene: strip what the provider strips, trim to its limits. */
    public static function clean(?string $v, int $max): string
    {
        $v = trim(str_replace(['{', '}', ';', '<', '>', '$'], ' ', (string) $v));

        return mb_substr(preg_replace('/\s+/u', ' ', $v), 0, $max);
    }

    public function payload(string $invoice, Order $o, string $phone): array
    {
        $cod = (float) $o->cod_amount;

        return array_filter([
            'invoice' => self::clean($invoice, 100),
            'recipient_name' => self::clean($o->customer_name, 100),
            'recipient_phone' => mb_substr($phone, 0, 40),
            'recipient_address' => self::clean($o->address.($o->area ? ", {$o->area}" : ''), 490),
            'cod_amount' => floor($cod) == $cod ? (int) $cod : $cod,
            'note' => $o->notes ? self::clean($o->notes, 480) : null,
        ], fn ($v) => $v !== null);
    }

    public function createShipment(string $invoice, Order $o, string $phone): ProviderResult
    {
        try {
            $res = $this->http()->asJson()->post($this->base().'/create_order', $this->payload($invoice, $o, $phone));
        } catch (ConnectionException) {
            // The request may have reached Steadfast before the connection dropped: it MIGHT exist.
            return new ProviderResult('unknown', 'timeout', 'We could not confirm whether Steadfast received this parcel.');
        }

        $status = $res->status();
        if ($status === 401) {
            return new ProviderResult('failed', 'invalid_credentials', 'Steadfast rejected the saved keys. Re-enter them under Settings → Couriers.');
        }
        if ($status === 403) {
            return new ProviderResult('failed', 'not_allowed', 'Your Steadfast account is inactive or needs its ownership details resubmitted. Contact Steadfast.');
        }
        if ($status === 429) {
            return new ProviderResult('failed', 'rate_limited', 'Steadfast is refusing requests right now. Wait a while before trying again.');
        }
        if ($status === 422 || $status === 400) {
            return new ProviderResult('failed', 'rejected', $this->rejection($res));
        }
        if ($status >= 500) {
            return new ProviderResult('unknown', 'provider_error', 'Steadfast had an error and we could not confirm whether the parcel was created.');
        }
        if (! $res->successful()) {
            return new ProviderResult('failed', 'provider_error_'.$status, 'Steadfast returned an unexpected error.');
        }

        $j = $res->json();
        $j = is_array($j) ? $j : [];
        $cid = $this->dig($j, ['consignment.consignment_id', 'consignment_id']);
        $tracking = $this->dig($j, ['consignment.tracking_code', 'tracking_code']);
        $st = $this->dig($j, ['consignment.status']);

        if ($cid !== null) {
            return new ProviderResult('booked', 'ok', 'Sent to Steadfast.', (string) $cid, $tracking !== null ? (string) $tracking : null, $st !== null ? (string) $st : 'in_review');
        }
        // A 200 whose body carries a non-200 status is a rejection reported inside the reply (the guide warns about this).
        if (isset($j['status']) && is_numeric($j['status']) && (int) $j['status'] >= 400 && (int) $j['status'] < 500) {
            return new ProviderResult('failed', 'rejected', $this->rejection($res));
        }

        return new ProviderResult('unknown', 'unreadable_reply', 'Steadfast replied, but we could not read the confirmation.');
    }

    /**
     * Where is this parcel? found=true/false is only returned when it is certain.
     * @return array{found: ?bool, status: ?string, code: string}
     */
    public function statusByInvoice(string $invoice): array
    {
        try {
            $res = $this->http()->get($this->base().'/status_by_invoice/'.rawurlencode($invoice));
        } catch (ConnectionException) {
            return ['found' => null, 'status' => null, 'code' => 'unreachable'];
        }
        if ($res->status() === 401) {
            // Ambiguous by design: "bad keys" and "no such parcel" look the same. Caller decides via keysAreValid().
            return ['found' => null, 'status' => null, 'code' => 'unauthorized'];
        }
        if (! $res->successful()) {
            return ['found' => null, 'status' => null, 'code' => 'provider_error_'.$res->status()];
        }
        $j = $res->json();
        $status = is_array($j) ? $this->dig($j, ['delivery_status', 'consignment.status', 'status_text']) : null;

        return ['found' => true, 'status' => $status !== null ? (string) $status : null, 'code' => 'ok'];
    }

    /** Independent of any parcel: tells "bad keys" apart from "unknown invoice". null = could not tell. */
    public function keysAreValid(): ?bool
    {
        try {
            $res = $this->http()->get($this->base().'/get_balance');
        } catch (ConnectionException) {
            return null;
        }

        return $res->successful() ? true : ($res->status() === 401 ? false : null);
    }

    private function rejection(Response $res): string
    {
        $j = $res->json();
        $msg = is_array($j) ? ($j['message'] ?? null) : null;
        if (is_array($j) && isset($j['errors']) && is_array($j['errors'])) {
            $first = collect($j['errors'])->flatten()->first();
            $msg = is_string($first) ? $first : $msg;
        }

        return 'Steadfast rejected this parcel: '.self::clean(is_string($msg) && $msg !== '' ? $msg : 'invalid details', 160);
    }

    /** First non-empty scalar found under any of the dotted paths. */
    private function dig(array $data, array $paths): string|int|float|null
    {
        foreach ($paths as $p) {
            $v = data_get($data, $p);
            if (is_scalar($v) && $v !== '') {
                return $v;
            }
        }

        return null;
    }
}
