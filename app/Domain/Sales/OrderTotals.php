<?php

namespace App\Domain\Sales;

/**
 * order_total = items_subtotal − item_discount − order_discount + shipping + other_charges + tax
 * cod_amount  = order_total − advance (never below zero)
 * bcmath strings only; inputs are validated to ≤ 2 decimals so no rounding occurs.
 */
class OrderTotals
{
    /** @param array<int,array{qty:int,unit_price:string,discount:string}> $lines */
    public static function compute(array $lines, string $orderDiscount, string $shipping, string $other, string $tax, string $advance): array
    {
        $subtotal = $itemDiscount = '0.00';
        $out = [];
        foreach ($lines as $i => $l) {
            $gross = bcmul((string) $l['qty'], $l['unit_price'], 2);
            if (bccomp($l['discount'], $gross, 2) > 0) {
                throw new \InvalidArgumentException("Line {$i}: discount exceeds the line amount.");
            }
            $subtotal = bcadd($subtotal, $gross, 2);
            $itemDiscount = bcadd($itemDiscount, $l['discount'], 2);
            $out[$i] = bcsub($gross, $l['discount'], 2);
        }
        $total = bcadd(bcadd(bcadd(bcsub(bcsub($subtotal, $itemDiscount, 2), $orderDiscount, 2), $shipping, 2), $other, 2), $tax, 2);
        if (bccomp($total, '0', 2) < 0) {
            throw new \InvalidArgumentException('Discounts exceed the order amount.');
        }
        if (bccomp($advance, $total, 2) > 0) {
            throw new \InvalidArgumentException('Advance cannot exceed the order total.');
        }

        return [
            'line_totals' => $out, 'items_subtotal' => $subtotal, 'item_discount' => $itemDiscount, 'total' => $total,
            'cod_amount' => bcsub($total, $advance, 2),
        ];
    }
}
