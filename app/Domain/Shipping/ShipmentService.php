<?php

namespace App\Domain\Shipping;

use App\Domain\Audit\Audit;
use App\Domain\Sales\OrderService;
use App\Domain\Sales\PhoneNormalizer;
use App\Models\CourierIntegration;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Booking rules (spec §5.6/5.7, risk register "duplicate parcels on timeout"):
 *  - booking is ONLINE-ONLY and NEVER retried automatically
 *  - the shipment row is committed in state `booking` BEFORE the HTTP call: it is the in-flight lock, so a
 *    double click or a second tab cannot send twice
 *  - anything that might have created a parcel (timeout, 5xx, unreadable reply) becomes `booking_unknown`
 *    and must be RECONCILED against the provider before a retry is possible
 *  - "delivered" in the provider's system moves the order forward; it is not "COD paid" (settlement is separate)
 */
class ShipmentService
{
    /** Order states from which a parcel can be sent. */
    public const BOOKABLE = ['confirmed', 'processing', 'ready_to_ship'];

    /** After this long, an in-flight `booking` is treated as unknown (the request died mid-way). */
    private const STALE_BOOKING_SECONDS = 120;

    /** A "not found" conclusion needs the provider's 60 s cache to have expired. */
    private const NOT_FOUND_AFTER_SECONDS = 90;

    public function __construct(private OrderService $orders) {}

    private function provider(CourierIntegration $i): SteadfastProvider
    {
        return match ($i->provider) {
            'steadfast' => new SteadfastProvider($i),
            default => throw ValidationException::withMessages(['courier' => 'Unsupported courier.']),
        };
    }

    public function book(Order $order, CourierIntegration $integration): Shipment
    {
        if ($integration->status !== 'connected') {
            throw ValidationException::withMessages(['courier' => 'Connect and test your courier account first (Settings → Couriers).']);
        }
        $phone = PhoneNormalizer::normalize($order->phone)
            ?? throw ValidationException::withMessages(['courier' => 'The customer phone number is not a valid Bangladeshi mobile number. Fix it on the order first.']);

        // 1) Claim the order atomically. Committed before any network call.
        $shipment = DB::transaction(function () use ($order, $integration) {
            $o = Order::lockForUpdate()->findOrFail($order->id);
            if (! in_array($o->status, self::BOOKABLE, true)) {
                throw ValidationException::withMessages(['courier' => 'Only confirmed orders that have not shipped yet can be sent to the courier.']);
            }
            $all = Shipment::where('order_id', $o->id)->where('provider', $integration->provider)->orderBy('id')->get();
            if ($active = $all->first(fn (Shipment $s) => $s->isActive())) {
                throw ValidationException::withMessages(['courier' => match ($active->state) {
                    'booked' => 'This order is already booked with the courier.',
                    'booking' => 'A booking for this order is already in progress.',
                    default => 'A previous booking could not be confirmed. Check its status before booking again.',
                }]);
            }
            $failed = $all->first(fn (Shipment $s) => $s->state === 'failed');
            if ($failed) { // never reached the provider: reuse the same invoice, it is still free there
                $failed->update(['state' => 'booking', 'error_code' => null, 'message' => null, 'cod_amount' => $o->cod_amount, 'courier_integration_id' => $integration->id, 'created_by' => auth()->id()]);

                return $failed;
            }
            $attempt = $all->count() + 1; // a re-ship after a finished/cancelled parcel needs a fresh invoice
            $s = Shipment::create([
                'order_id' => $o->id, 'courier_integration_id' => $integration->id, 'provider' => $integration->provider, 'attempt' => $attempt,
                'invoice' => $attempt === 1 ? $o->order_number : "{$o->order_number}-{$attempt}", 'state' => 'booking',
                'cod_amount' => $o->cod_amount, 'created_by' => auth()->id(),
            ]);
            $this->event($s, 'booking', 'Sending to courier');

            return $s;
        });

        // 2) Talk to the provider (no DB lock held).
        $result = $this->provider($integration)->createShipment($shipment->invoice, $order->fresh(), $phone);

        // 3) Record exactly what we know.
        $shipment->update([
            'state' => match ($result->outcome) { 'booked' => 'booked', 'failed' => 'failed', default => 'booking_unknown' },
            'consignment_id' => $result->consignmentId, 'tracking_code' => $result->trackingCode, 'provider_status' => $result->status,
            'error_code' => $result->outcome === 'booked' ? null : $result->code, 'message' => $result->outcome === 'booked' ? null : $result->message,
            'booked_at' => $result->outcome === 'booked' ? now() : null, 'last_synced_at' => $result->outcome === 'booked' ? now() : null,
        ]);
        $this->event($shipment, $result->outcome === 'booked' ? ($result->status ?? 'booked') : $result->outcome, $result->outcome === 'booked' ? 'Booked with courier' : $result->message);
        Audit::record('shipment.'.$result->outcome, $order, new: ['shipment_id' => $shipment->id, 'invoice' => $shipment->invoice, 'code' => $result->code]);

        return $shipment->refresh();
    }

    public function canReconcile(Shipment $s): bool
    {
        return $s->state === 'booking_unknown'
            || ($s->state === 'booking' && $s->updated_at->lt(now()->subSeconds(self::STALE_BOOKING_SECONDS)));
    }

    /**
     * Ask the provider whether the parcel exists. Never creates anything.
     * Because "no such invoice" and "bad keys" both answer 401, "not booked" is concluded only when the keys are
     * proven good AND the provider's cache window has passed; otherwise the shipment stays unresolved.
     */
    public function reconcile(Shipment $s): Shipment
    {
        if (! $this->canReconcile($s)) {
            throw ValidationException::withMessages(['courier' => 'This booking does not need reconciling.']);
        }
        $integration = $s->integration ?? throw ValidationException::withMessages(['courier' => 'The courier account was disconnected.']);
        $p = $this->provider($integration);
        $r = $p->statusByInvoice($s->invoice);

        if ($r['found'] === true) {
            $s->update(['state' => 'booked', 'provider_status' => $r['status'] ?? $s->provider_status ?? 'in_review', 'error_code' => null, 'message' => null, 'booked_at' => $s->booked_at ?? now(), 'last_synced_at' => now()]);
            $this->event($s, $s->provider_status, 'Confirmed with courier');
            Audit::record('shipment.reconciled', $s->order, new: ['shipment_id' => $s->id, 'result' => 'found']);

            return $s->refresh();
        }

        if ($r['code'] === 'unauthorized') {
            $valid = $p->keysAreValid();
            if ($valid === false) {
                throw ValidationException::withMessages(['courier' => 'Steadfast rejected the saved keys, so the parcel cannot be checked. Re-enter them under Settings → Couriers.']);
            }
            if ($valid === true) {
                if ($s->updated_at->gt(now()->subSeconds(self::NOT_FOUND_AFTER_SECONDS))) {
                    throw ValidationException::withMessages(['courier' => 'Steadfast may still be updating. Wait a minute and check again.']);
                }
                $s->update(['state' => 'failed', 'error_code' => 'not_found_at_provider', 'message' => 'Steadfast has no parcel for this order, so it is safe to send it again.']);
                $this->event($s, 'failed', 'Not found at courier; safe to retry');
                Audit::record('shipment.reconciled', $s->order, new: ['shipment_id' => $s->id, 'result' => 'not_found']);

                return $s->refresh();
            }
        }

        throw ValidationException::withMessages(['courier' => 'Could not reach Steadfast to check. Try again in a moment.']);
    }

    /** Pull the latest status. Updates the order only for CONFIRMED outcomes on an order that is already shipped. */
    public function sync(Shipment $s): Shipment
    {
        if ($s->state !== 'booked' || ! $s->integration) {
            return $s;
        }
        $r = $this->provider($s->integration)->statusByInvoice($s->invoice);
        if ($r['found'] !== true || $r['status'] === null) {
            return $s; // never overwrite known state with an unreadable answer
        }
        $changed = $r['status'] !== $s->provider_status;
        $s->update(['provider_status' => $r['status'], 'last_synced_at' => now()]);
        if ($changed) {
            $this->event($s, $r['status'], null);
            $this->advanceOrder($s->refresh());
        }

        return $s->refresh();
    }

    /** `*_approval_pending` is the rider's word, not the courier's: never act on it (guide). */
    private function advanceOrder(Shipment $s): void
    {
        $order = $s->order;
        if (! $order || $order->status !== 'shipped') {
            return;
        }
        try {
            if ($s->provider_status === 'delivered') {
                $this->orders->transition($order, 'delivered', 'Delivered (confirmed by courier)');
            } elseif ($s->provider_status === 'cancelled') {
                $this->orders->transition($order, 'delivery_failed', 'Courier reported the parcel as not delivered');
            }
        } catch (ValidationException) {
            // Order moved on by hand in the meantime: leave it.
        }
    }

    private function event(Shipment $s, ?string $status, ?string $note): void
    {
        ShipmentEvent::create(['shipment_id' => $s->id, 'status' => (string) $status, 'note' => $note ? mb_substr($note, 0, 300) : null]);
    }
}
