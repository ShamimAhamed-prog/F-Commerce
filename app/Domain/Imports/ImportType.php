<?php

namespace App\Domain\Imports;

/** Registry so products and orders share the parse/validate/run pipeline. */
class ImportType
{
    private const TYPES = [
        'products' => ['schema' => ProductImportSchema::class, 'processor' => ProductImportProcessor::class, 'permission' => 'products.import',
            'required' => ['sku', 'name', 'selling_price'], 'label' => 'products'],
        'orders' => ['schema' => OrderImportSchema::class, 'processor' => OrderImportProcessor::class, 'permission' => 'orders.import',
            'required' => ['customer_phone', 'product_sku', 'quantity'], 'label' => 'orders'],
    ];

    public static function valid(string $type): bool
    {
        return isset(self::TYPES[$type]);
    }

    public static function schema(string $type): string
    {
        return self::TYPES[$type]['schema'];
    }

    public static function processor(string $type): object
    {
        return app(self::TYPES[$type]['processor']);
    }

    public static function permission(string $type): string
    {
        return self::TYPES[$type]['permission'];
    }

    public static function required(string $type): array
    {
        return self::TYPES[$type]['required'];
    }

    public static function types(): array
    {
        return array_keys(self::TYPES);
    }
}
