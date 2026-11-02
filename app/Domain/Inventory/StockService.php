<?php

namespace App\Domain\Inventory;

use App\Models\StockBalance;
use App\Models\StockMovement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Single writer of the stock ledger (spec §5.4). Stock is never edited
 * directly: every change is an immutable StockMovement and the balance row
 * is a cache updated in the same transaction under a row lock.
 *
 * Cost method: WEIGHTED AVERAGE only (never mixed with FIFO). Money is handled
 * with bcmath strings, never floats.
 */
class StockService
{
    /** Types that move physical stock. */
    public const IN = ['opening', 'purchase', 'return'];

    public const OUT = ['sale'];

    /** Adjustment moves stock in either direction (signed qty). */
    public const SIGNED = ['adjustment', 'transfer'];

    /** Types that only change the reserved counter. */
    public const RESERVATION = ['reservation', 'release'];

    public function record(
        int $productId,
        ?int $variantId,
        string $type,
        int $qty,
        ?string $unitCost = null,
        ?string $reason = null,
        ?Model $source = null,
    ): StockMovement {
        if ($qty === 0) {
            throw new \InvalidArgumentException('Quantity must not be zero.');
        }
        $this->guardDirection($type, $qty);

        return DB::transaction(function () use ($productId, $variantId, $type, $qty, $unitCost, $reason, $source) {
            $balance = $this->lockedBalance($productId, $variantId);

            if (in_array($type, self::RESERVATION, true)) {
                $newReserved = $balance->reserved + $qty;
                if ($type === 'reservation' && $newReserved > $balance->on_hand) {
                    throw new InsufficientStock('Not enough available stock to reserve.');
                }
                if ($newReserved < 0) {
                    throw new InsufficientStock('Cannot release more than is reserved.');
                }
                $balance->reserved = $newReserved;
                $cost = (string) $balance->avg_cost;
            } else {
                $newOnHand = $balance->on_hand + $qty;
                if ($newOnHand < 0) {
                    throw new InsufficientStock("Only {$balance->on_hand} on hand.");
                }
                if ($newOnHand < $balance->reserved && $qty < 0 && $type !== 'sale') {
                    throw new InsufficientStock('Would drop below reserved quantity.');
                }

                if ($qty > 0) {
                    $cost = $unitCost ?? (string) $balance->avg_cost;
                    $balance->avg_cost = $this->newAverage($balance->on_hand, (string) $balance->avg_cost, $qty, $cost);
                } else {
                    $cost = (string) $balance->avg_cost; // outflow valued at current average
                }
                $balance->on_hand = $newOnHand;
                if ($type === 'sale') {
                    $balance->reserved = max(0, $balance->reserved + $qty); // dispatch consumes its reservation
                }
            }

            $balance->save();

            $movement = StockMovement::create([
                'product_id' => $productId,
                'product_variant_id' => $variantId,
                'type' => $type,
                'qty' => $qty,
                'unit_cost' => $cost,
                'source_type' => $source?->getMorphClass(),
                'source_id' => $source?->getKey(),
                'reason' => $reason,
                'actor_id' => auth()->id(),
            ]);

            // Keep the Inventory asset equal to stock valuation. Sales are posted with the order (COGS);
            // purchases are posted by the caller together with their payment account.
            if (in_array($type, ['opening', 'adjustment', 'return'], true)) {
                app(\App\Domain\Accounting\Poster::class)->stock($movement);
            }

            return $movement;
        });
    }

    public function available(int $productId, ?int $variantId): int
    {
        $b = StockBalance::where('product_id', $productId)->where('variant_id', $variantId ?? 0)->first();

        return $b ? $b->on_hand - $b->reserved : 0;
    }

    /** Recompute on_hand from the ledger (used by tests / reconciliation). */
    public function ledgerOnHand(int $productId, ?int $variantId): int
    {
        return (int) StockMovement::where('product_id', $productId)
            ->where('product_variant_id', $variantId)
            ->whereNotIn('type', self::RESERVATION)
            ->sum('qty');
    }

    /** Weighted average: (oldQty*oldAvg + inQty*inCost) / (oldQty+inQty). */
    public function newAverage(int $oldQty, string $oldAvg, int $inQty, string $inCost): string
    {
        $oldQty = max(0, $oldQty);
        $total = $oldQty + $inQty;
        if ($total <= 0) {
            return $oldAvg;
        }
        $value = bcadd(bcmul((string) $oldQty, $oldAvg, 6), bcmul((string) $inQty, $inCost, 6), 6);

        return bcdiv($value, (string) $total, 4);
    }

    private function lockedBalance(int $productId, ?int $variantId): StockBalance
    {
        $vid = $variantId ?? 0;
        $find = fn () => StockBalance::where('product_id', $productId)->where('variant_id', $vid)->lockForUpdate()->first();

        $balance = $find();
        if (! $balance) {
            try {
                StockBalance::create(['product_id' => $productId, 'variant_id' => $vid]);
            } catch (\Illuminate\Database\UniqueConstraintViolationException) {
                // lost the race; the row now exists
            }
            $balance = $find();
        }

        return $balance;
    }

    private function guardDirection(string $type, int $qty): void
    {
        $ok = match (true) {
            in_array($type, self::IN, true) => $qty > 0,
            in_array($type, self::OUT, true) => $qty < 0,
            $type === 'reservation' => $qty > 0,
            $type === 'release' => $qty < 0,
            in_array($type, self::SIGNED, true) => true,
            default => throw new \InvalidArgumentException("Unknown movement type {$type}."),
        };
        if (! $ok) {
            throw new \InvalidArgumentException("Quantity sign is invalid for {$type}.");
        }
    }
}
