<?php

namespace App\Http\Requests\Catalog;

use App\Domain\Catalog\SkuRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can($this->route('product') ? 'products.update' : 'products.create');
    }

    public function rules(): array
    {
        $product = $this->route('product');
        $money = ['nullable', 'numeric', 'min:0', 'max:9999999999999.99', 'decimal:0,2'];

        return [
            'name' => ['required', 'string', 'max:200'],
            'sku' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9._\-\/]+$/', function ($a, $v, $fail) use ($product) {
                if (SkuRegistry::taken($v, $product?->id)) {
                    $fail('This SKU is already in use.');
                }
            }],
            'description' => ['nullable', 'string', 'max:10000'],
            'category' => ['nullable', 'string', 'max:120'],
            'brand' => ['nullable', 'string', 'max:120'],
            'type' => ['required', Rule::in(['simple', 'variable'])],
            'purchase_cost' => $money,
            'selling_price' => ['required', ...array_slice($money, 1)],
            'compare_at_price' => $money,
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'barcode' => ['nullable', 'string', 'max:100'],
            'weight' => ['nullable', 'numeric', 'min:0'],
            'dimensions' => ['nullable', 'string', 'max:60'],
            'status' => ['required', Rule::in(['draft', 'active', 'archived'])],
            'reorder_threshold' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'opening_stock' => ['nullable', 'integer', 'min:0', 'max:10000000'],
            'variants' => ['exclude_unless:type,variable', 'required_if:type,variable', 'array', 'min:1', 'max:100'],
            'variants.*.id' => ['nullable', 'integer'],
            'variants.*.sku' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9._\-\/]+$/', 'distinct', function ($attr, $v, $fail) use ($product) {
                $idx = explode('.', $attr)[1];
                $vid = $this->input("variants.$idx.id");
                if (SkuRegistry::taken($v, $product?->id, $vid ? (int) $vid : null) || $v === $this->input('sku')) {
                    $fail('This SKU is already in use.');
                }
            }],
            'variants.*.barcode' => ['nullable', 'string', 'max:100'],
            'variants.*.options' => ['required', 'array', 'min:1'],
            'variants.*.options.*' => ['required', 'string', 'max:100'],
            'variants.*.selling_price' => $money,
            'variants.*.purchase_cost' => $money,
            'variants.*.opening_stock' => ['nullable', 'integer', 'min:0', 'max:10000000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        // Empty strings from forms become null so nullable rules apply.
        $this->merge(array_map(fn ($v) => $v === '' ? null : $v, $this->all()));
    }
}
