<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->timestamps();
            $t->unique(['tenant_id', 'name']);
        });

        Schema::create('brands', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->timestamps();
            $t->unique(['tenant_id', 'name']);
        });

        Schema::create('products', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->string('sku', 100);
            $t->string('slug')->nullable();
            $t->text('description')->nullable();
            $t->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('brand_id')->nullable()->constrained()->nullOnDelete();
            $t->string('type', 10)->default('simple'); // simple | variable
            $t->decimal('purchase_cost', 18, 2)->default(0);
            $t->decimal('selling_price', 18, 2)->default(0);
            $t->decimal('compare_at_price', 18, 2)->nullable();
            $t->decimal('tax_rate', 5, 2)->nullable();
            $t->string('barcode', 100)->nullable();
            $t->decimal('weight', 10, 3)->nullable();
            $t->string('dimensions', 60)->nullable();
            $t->string('status', 10)->default('draft'); // draft | active | archived
            $t->unsignedInteger('reorder_threshold')->default(0);
            $t->foreignId('created_by')->nullable();
            $t->foreignId('updated_by')->nullable();
            $t->timestamps();
            $t->unique(['tenant_id', 'sku']);
            $t->index(['tenant_id', 'status']);
            $t->index(['tenant_id', 'category_id']);
        });

        Schema::create('product_variants', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('product_id')->constrained()->cascadeOnDelete();
            $t->string('sku', 100);
            $t->string('barcode', 100)->nullable();
            $t->json('options'); // {"Size":"M","Color":"Red"}
            $t->decimal('selling_price', 18, 2)->nullable(); // override
            $t->decimal('purchase_cost', 18, 2)->nullable(); // override
            $t->unsignedInteger('position')->default(0);
            $t->timestamps();
            $t->unique(['tenant_id', 'sku']);
            $t->index(['tenant_id', 'product_id']);
        });

        Schema::create('media_files', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->string('disk', 30);
            $t->string('path');            // processed "detail" derivative
            $t->string('original_path')->nullable();
            $t->string('mime', 60);
            $t->unsignedBigInteger('size');           // bytes, all stored files (quota)
            $t->unsignedInteger('width')->nullable();
            $t->unsignedInteger('height')->nullable();
            $t->string('checksum', 64)->nullable();
            $t->json('derivatives')->nullable();      // {"thumb":"..","card":".."}
            $t->timestamps();
            $t->index('tenant_id');
        });

        Schema::create('product_images', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('product_id')->constrained()->cascadeOnDelete();
            $t->foreignId('media_file_id')->nullable()->constrained()->nullOnDelete();
            $t->unsignedInteger('position');
            $t->boolean('is_primary')->default(false);
            $t->string('alt_text', 200)->nullable();
            $t->string('caption', 300)->nullable();
            $t->string('status', 12)->default('processing'); // processing | ready | failed
            $t->string('error', 200)->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->foreignId('created_by')->nullable();
            $t->foreignId('updated_by')->nullable();
            $t->timestamps();
            $t->index(['tenant_id', 'product_id', 'position']);
        });

        Schema::create('product_image_variant', function (Blueprint $t) {
            $t->foreignId('product_image_id')->constrained()->cascadeOnDelete();
            $t->foreignId('product_variant_id')->constrained()->cascadeOnDelete();
            $t->primary(['product_image_id', 'product_variant_id']);
        });
    }

    public function down(): void
    {
        foreach (['product_image_variant', 'product_images', 'media_files', 'product_variants', 'products', 'brands', 'categories'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
