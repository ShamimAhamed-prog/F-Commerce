<?php

namespace App\Domain\Imports;

/** Data-only field schema for the product importer. NO image fields (IMG-I1). */
class ProductImportSchema
{
    /** key => [label, aliases] */
    public const FIELDS = [
        'name' => ['Product name', ['title', 'product_name', 'item_name']],
        'sku' => ['SKU (parent / product key)', ['product_sku', 'parent_sku', 'product_key', 'code', 'item_code']],
        'category' => ['Category', ['product_category']],
        'brand' => ['Brand', ['manufacturer']],
        'product_type' => ['Type (simple/variable)', ['type']],
        'description' => ['Description', ['details']],
        'purchase_cost' => ['Purchase cost', ['cost', 'buying_price', 'cost_price']],
        'selling_price' => ['Selling price', ['price', 'sale_price', 'sell_price', 'mrp']],
        'compare_at_price' => ['Compare-at price', ['old_price', 'regular_price']],
        'tax_rate' => ['Tax rate %', ['tax', 'vat']],
        'barcode' => ['Barcode', ['ean', 'upc']],
        'weight' => ['Weight', []],
        'dimensions' => ['Dimensions', ['size_cm']],
        'status' => ['Status', []],
        'reorder_threshold' => ['Reorder threshold', ['reorder_level', 'low_stock']],
        'opening_stock' => ['Opening stock', ['stock', 'qty', 'quantity']],
        'variant_sku' => ['Variant SKU', []],
        'option1_name' => ['Option 1 name', []],
        'option1_value' => ['Option 1 value', []],
        'option2_name' => ['Option 2 name', []],
        'option2_value' => ['Option 2 value', []],
        'option3_name' => ['Option 3 name', []],
        'option3_value' => ['Option 3 value', []],
        'variant_price' => ['Variant price', ['variant_selling_price']],
        'variant_cost' => ['Variant cost', []],
        'variant_barcode' => ['Variant barcode', []],
        'variant_stock' => ['Variant stock', ['variant_qty']],
    ];

    public static function keys(): array
    {
        return array_keys(self::FIELDS);
    }

    public static function normalizeHeader(string $h): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', '_', strtolower($h)), '_');
    }

    /** Suggest field per header; image-like headers are never mapped. */
    public static function autoMap(array $headers): array
    {
        $map = [];
        $used = [];
        foreach ($headers as $header) {
            $map[$header] = null;
            if (ImageColumnClassifier::looksLikeImage($header)) {
                continue;
            }
            $n = self::normalizeHeader($header);
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
        return self::keys();
    }

    public static function templateRows(): array
    {
        $blank = array_fill_keys(self::keys(), '');
        $rows = [
            ['name' => 'Cotton Panjabi', 'sku' => 'PNJ-001', 'category' => 'Clothing', 'brand' => 'Local', 'product_type' => 'simple',
                'purchase_cost' => '900', 'selling_price' => '1500', 'status' => 'draft', 'opening_stock' => '12'],
            ['name' => 'Basic Tee', 'sku' => 'TEE', 'category' => 'Clothing', 'product_type' => 'variable', 'purchase_cost' => '300',
                'selling_price' => '600', 'variant_sku' => 'TEE-S', 'option1_name' => 'Size', 'option1_value' => 'S', 'variant_stock' => '5'],
            ['sku' => 'TEE', 'variant_sku' => 'TEE-M', 'option1_name' => 'Size', 'option1_value' => 'M', 'variant_price' => '650', 'variant_stock' => '8'],
        ];

        return array_map(fn ($r) => array_values(array_merge($blank, $r)), $rows);
    }
}
