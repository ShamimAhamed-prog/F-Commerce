<?php

namespace App\Domain\Shipping;

/** Outcome of a credential check. `message` is safe to show to the merchant (never contains secrets). */
class ConnectionResult
{
    public function __construct(public bool $ok, public string $code, public string $message) {}
}
