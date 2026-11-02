<?php

namespace App\Domain\Imports;

/** Order import fields (spec §5.6). Courier columns arrive with the Phase 3 courier integration. */
class OrderImportSchema
{
    public const FIELDS = [
        'external_order_id' => ['External order ID', ['order_id', 'order_no', 'order_number', 'invoice']],
        'customer_name' => ['Customer name', ['name', 'customer']],
        'customer_phone' => ['Customer phone', ['phone', 'mobile', 'contact']],
        'delivery_address' => ['Delivery address', ['address']],
        'area' => ['Area', ['district', 'city']],
        'product_sku' => ['Product SKU', ['sku', 'item_sku']],
        'quantity' => ['Quantity', ['qty']],
        'unit_price' => ['Unit price', ['price']],
        'discount' => ['Line discount', []],
        'delivery_charge' => ['Delivery charge', ['shipping', 'shipping_charge']],
        'advance_paid' => ['Advance paid', ['advance']],
        'notes' => ['Notes', ['note', 'remarks']],
    ];

    public static function autoMap(array $headers): array
    {
        $map = [];
        $used = [];
        foreach ($headers as $header) {
            $map[$header] = null;
            $n = ProductImportSchema::normalizeHeader($header);
            foreach (self::FIELDS as $key => [$label, $aliases]) {
                if (! isset($used[$key]) && ($n === $key || in_array($n, $aliases, true))) {
                    $map[$header] = $key;
                    $used[$key] = true;
                    break;
                }
            }
        }

        return $map;
    }

    public static function templateHeaders(): array
    {
        return array_keys(self::FIELDS);
    }

    public static function templateRows(): array
    {
        return [
            ['A-1001', 'Rahim Uddin', '01712345678', 'House 5, Road 3, Dhanmondi', 'Dhaka', 'PNJ-001', '1', '', '', '80', '100', ''],
            ['A-1001', '', '', '', '', 'TEE-S', '2', '', '', '', '', ''],
            ['A-1002', 'Karim', '+8801812345678', 'Chawkbazar', 'Chattogram', 'PNJ-001', '1', '1400', '', '120', '', 'Call before delivery'],
        ];
    }
}
