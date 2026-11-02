<?php

namespace App\Domain\Shipping;

use App\Models\CourierIntegration;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Steadfast adapter. Implemented so far: connection test only.
 *
 * Auth (from the merchant guide): headers `Api-Key` and `Secret-Key` on every call, no token step.
 * `/ping` needs no keys, so it cannot validate them; `/get_balance` needs both, and the guide defines the
 * status codes, so we judge the keys by HTTP status alone (we do NOT parse any response body here).
 *
 * Booking, tracking, balance and webhook handling are added after the per-endpoint request/response
 * and webhook specs are confirmed. Nothing is guessed.
 */
class SteadfastProvider
{
    public function __construct(private CourierIntegration $integration) {}

    private function base(): string
    {
        return rtrim(config('couriers.steadfast.base_url'), '/'); // platform-configured host only
    }

    public function testConnection(): ConnectionResult
    {
        $c = $this->integration->credentials ?? [];
        if (empty($c['api_key']) || empty($c['secret_key'])) {
            return new ConnectionResult(false, 'missing_credentials', 'Enter both the API key and the secret key.');
        }

        try {
            $res = Http::withHeaders(['Api-Key' => $c['api_key'], 'Secret-Key' => $c['secret_key']])
                ->acceptJson()->timeout(config('couriers.steadfast.timeout'))->get($this->base().'/get_balance');
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
}
