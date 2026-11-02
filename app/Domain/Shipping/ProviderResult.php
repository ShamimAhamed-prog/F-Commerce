<?php

namespace App\Domain\Shipping;

/**
 * outcome:
 *   booked  - the provider confirmed the parcel (ids present)
 *   failed  - the provider definitely did NOT create it (safe to correct and try again)
 *   unknown - it MIGHT exist (timeout, 5xx, unreadable reply): never retry blindly, reconcile first
 */
class ProviderResult
{
    public function __construct(
        public string $outcome,
        public string $code,
        public string $message,
        public ?string $consignmentId = null,
        public ?string $trackingCode = null,
        public ?string $status = null,
    ) {}
}
