<?php

use App\Models\AuditLog;
use App\Models\Import;
use App\Models\ImportMapping;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\StockBalance;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->withoutVite();
    Storage::fake('local');
    Http::preventStrayRequests();
    [$this->user, $this->tenant] = memberOf();
    $this->as = fn (?User $u = null) => $this->actingAs($u ?? $this->user)->withSession(['tenant_id' => $this->tenant->id]);
    $this->csv = fn (string $body, string $name = 'products.csv') => UploadedFile::fake()->createWithContent($name, $body);
    $this->upload = fn (string $body, string $name = 'products.csv') => ($this->as)()->post('/app/imports/products', ['file' => ($this->csv)($body, $name)]);
    $this->import = fn () => Import::withoutGlobalScopes()->latest('id')->first();
    $this->fullMap = fn (Import $i, string $policy = 'skip') => ($this->as)()->post("/app/imports/{$i->id}/mapping", ['mapping' => $i->mapping, 'duplicate_policy' => $policy]);

    $this->basic = "name,sku,selling_price,purchase_cost,category,opening_stock\nCotton Panjabi,PNJ-1,1500,900,Clothing,12\nSilk Saree,SRE-1,\"4,500\",3000,Clothing,3\n";
    /** upload → mapped → validated, ready to commit */
    $this->prepare = function (string $body, string $policy = 'skip') {
        ($this->upload)($body)->assertRedirect();
        $i = ($this->import)();
        ($this->fullMap)($i->refresh(), $policy)->assertSessionHasNoErrors();

        return $i->refresh();
    };
    $this->commit = fn (Import $i) => ($this->as)()->post("/app/imports/{$i->id}/commit")->assertRedirect();
});

it('uploads, parses asynchronously and proposes a mapping', function () {
    ($this->upload)($this->basic)->assertRedirect();
    $i = ($this->import)();

    expect($i->status)->toBe('preview_ready')->and($i->total_rows)->toBe(2)
        ->and($i->headers)->toBe(['name', 'sku', 'selling_price', 'purchase_cost', 'category', 'opening_stock'])
        ->and($i->mapping['sku'])->toBe('sku')->and($i->mapping['selling_price'])->toBe('selling_price');
    Storage::disk('local')->assertExists($i->path);
    expect($i->path)->toStartWith("tenants/{$this->tenant->id}/imports/");
});

it('imports products, grouped variants and opening stock', function () {
    $csv = "sku,name,selling_price,purchase_cost,variant_sku,option1_name,option1_value,variant_price,variant_stock,category,opening_stock\n"
        ."TEE,Basic Tee,600,300,TEE-S,Size,S,,5,Clothing\n"
        .",,,,TEE-M,Size,M,650,8,\n"   // continuation row: no parent SKU -> invalid
        ."TEE,,,,TEE-L,Size,L,700,2,\n"
        ."MUG,Mug,250,100,,,,,,Home,20\n";
    $i = ($this->prepare)($csv);
    expect($i->options['invalid_rows'])->toBe(1)->and($i->options['valid_rows'])->toBe(3);

    ($this->commit)($i);
    $i->refresh();

    expect($i->status)->toBe('completed_with_errors')->and($i->created_count)->toBe(2)->and($i->failed_count)->toBe(1);
    asTenant($this->tenant);
    $tee = Product::where('sku', 'TEE')->first();
    expect($tee->type)->toBe('variable')->and($tee->variants->pluck('sku')->all())->toBe(['TEE-S', 'TEE-L'])
        ->and(StockBalance::where('variant_id', $tee->variants[0]->id)->value('on_hand'))->toBe(5)
        ->and(Product::where('sku', 'MUG')->first()->type)->toBe('simple');
    expect(StockBalance::where('product_id', Product::where('sku', 'MUG')->value('id'))->value('on_hand'))->toBe(20);
});

it('accepts Bangla text, a UTF-8 BOM, thousands separators and Bangla digits', function () {
    $csv = "\xEF\xBB\xBFনাম,sku,price\nসুতির পাঞ্জাবি,PNJ-BN,\"১,৫০০\"\n";
    ($this->upload)($csv);
    $i = ($this->import)();
    ($this->as)()->post("/app/imports/{$i->id}/mapping", ['mapping' => ['নাম' => 'name', 'sku' => 'sku', 'price' => 'selling_price'], 'duplicate_policy' => 'skip'])->assertSessionHasNoErrors();
    ($this->commit)($i->refresh());

    asTenant($this->tenant);
    $p = Product::where('sku', 'PNJ-BN')->first();
    expect($i->refresh()->headers[0])->toBe('নাম')->and((string) $p->selling_price)->toBe('1500.00');
});

it('maps common header aliases and lets the user map the rest', function () {
    ($this->upload)("Title,Code,Price\nCup,CUP-1,99\n");
    expect(($this->import)()->mapping)->toBe(['Title' => 'name', 'Code' => 'sku', 'Price' => 'selling_price']);
});

it('IMG-I2: shows image-like columns as ignored, never maps them and never stores their values', function () {
    $csv = "name,sku,selling_price,Image URL,photo,Gallery Images,thumbnail_link,img\nA,A-1,10,https://evil.example/a.png,x.jpg,\"https://a/1.png,https://a/2.png\",http://t,i\n";
    ($this->upload)($csv)->assertRedirect();
    $i = ($this->import)();

    expect($i->ignored_columns)->toEqualCanonicalizing(['Image URL', 'photo', 'Gallery Images', 'thumbnail_link', 'img']);
    foreach ($i->ignored_columns as $c) {
        expect($i->mapping[$c])->toBeNull();
    }
    expect(json_encode($i->rows()->first()->raw_data))->not->toContain('evil.example')->not->toContain('x.jpg');

    // Trying to force-map an image column is silently refused.
    $m = $i->mapping;
    $m['Image URL'] = 'description';
    ($this->as)()->post("/app/imports/{$i->id}/mapping", ['mapping' => $m, 'duplicate_policy' => 'skip'])->assertSessionHasNoErrors();
    expect($i->refresh()->mapping['Image URL'])->toBeNull();
});

it('IMG-I3: performs no outbound HTTP during a full import and creates no images', function () {
    Http::fake();
    $csv = "name,sku,selling_price,image_url\nA,A-1,10,https://169.254.169.254/latest/meta-data\nB,B-1,20,http://localhost/admin\n";
    $i = ($this->prepare)($csv);
    ($this->commit)($i);

    Http::assertNothingSent();
    asTenant($this->tenant);
    expect(Product::count())->toBe(2)->and(ProductImage::count())->toBe(0)->and(Storage::disk('local')->allFiles())->toHaveCount(1); // only the upload
});

it('IMG-I4: rejects ZIP and archive uploads with a clear message', function () {
    $zip = UploadedFile::fake()->createWithContent('bundle.zip', "PK\x03\x04 fake");
    ($this->as)()->post('/app/imports/products', ['file' => $zip])->assertSessionHasErrors('file');
    expect(session('errors')->first('file'))->toContain('ZIP');

    // A ZIP renamed to .xlsx without a workbook is not accepted either.
    $tmp = tempnam(sys_get_temp_dir(), 'z').'.zip';
    $z = new ZipArchive;
    $z->open($tmp, ZipArchive::CREATE);
    $z->addFromString('product-1.jpg', 'x');
    $z->close();
    ($this->as)()->post('/app/imports/products', ['file' => new UploadedFile($tmp, 'photos.xlsx', null, null, true)])->assertSessionHasErrors('file');
    expect(Import::withoutGlobalScopes()->count())->toBe(0);
});

it('rejects unsupported types, binary files and non-UTF-8 CSV', function () {
    ($this->as)()->post('/app/imports/products', ['file' => ($this->csv)('x', 'notes.txt')])->assertSessionHasErrors('file');
    ($this->as)()->post('/app/imports/products', ['file' => ($this->csv)("name,sku\n\0\0\0", 'bin.csv')])->assertSessionHasErrors('file');
    ($this->as)()->post('/app/imports/products', ['file' => ($this->csv)("name,sku\nCaf\xE9,1\n", 'latin1.csv')])->assertSessionHasErrors('file');
    expect(session('errors')->first('file'))->toContain('UTF-8');
    config(['imports.max_file_kb' => 1]);
    ($this->as)()->post('/app/imports/products', ['file' => ($this->csv)(str_repeat('a,b,c'.PHP_EOL, 600), 'big.csv')])->assertSessionHasErrors('file');
});

it('imports an XLSX file', function () {
    $sheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet;
    $sheet->getActiveSheet()->fromArray([['name', 'sku', 'selling_price'], ['Mug', 'MUG-X', 250], ['Cup', 'CUP-X', 99.5]]);
    $path = tempnam(sys_get_temp_dir(), 'x').'.xlsx';
    (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($sheet))->save($path);

    ($this->as)()->post('/app/imports/products', ['file' => new UploadedFile($path, 'p.xlsx', null, null, true)])->assertRedirect();
    $i = ($this->import)();
    expect($i->status)->toBe('preview_ready')->and($i->total_rows)->toBe(2);
    ($this->fullMap)($i->refresh());
    ($this->commit)($i->refresh());

    asTenant($this->tenant);
    expect((string) Product::where('sku', 'CUP-X')->value('selling_price'))->toBe('99.50');
});

it('treats cell contents as inert text', function () {
    $i = ($this->prepare)("name,sku,selling_price\n\"=HYPERLINK(\"\"http://x\"\")\",F-1,10\n");
    ($this->commit)($i);
    asTenant($this->tenant);
    expect(Product::where('sku', 'F-1')->value('name'))->toBe('=HYPERLINK("http://x")');
    Http::assertNothingSent();
});

it('reports row-level errors without blocking the valid rows', function () {
    $csv = "name,sku,selling_price,status\nOk,OK-1,10,active\n,NONAME,10,draft\nBadPrice,BP-1,abc,draft\nBadStatus,BS-1,5,live\nDup,OK-1,7,draft\nLong,bad sku!,5,draft\n";
    $i = ($this->prepare)($csv);

    expect($i->options['valid_rows'])->toBe(1)->and($i->options['invalid_rows'])->toBe(5);
    $errs = $i->rows()->orderBy('row_number')->get()->pluck('errors', 'row_number')->all();
    expect($errs[3][0])->toContain('name')->and($errs[4][0])->toContain('selling_price')->and($errs[5][0])->toContain('Status')
        ->and($errs[6][0])->toContain('Duplicate SKU in file')->and($errs[7][0])->toContain('SKU may only');

    ($this->commit)($i);
    $i->refresh();
    expect($i->created_count)->toBe(1)->and($i->failed_count)->toBe(5)->and($i->status)->toBe('completed_with_errors');
});

it('exports an error report with formula-injection protection', function () {
    $i = ($this->prepare)("name,sku,selling_price\n=cmd|' /C calc'!A0,E-1,abc\nOk,OK-9,5\n");
    ($this->commit)($i);

    $csv = ($this->as)()->get("/app/imports/{$i->id}/errors")->assertOk()->streamedContent();
    expect($csv)->toContain("'=cmd")->and($csv)->not->toContain("\n=cmd")->and($csv)->toContain('selling_price');
});

it('applies the duplicate policy: skip', function () {
    asTenant($this->tenant);
    Product::factory()->create(['sku' => 'PNJ-1', 'name' => 'Existing', 'selling_price' => 1]);
    $i = ($this->prepare)($this->basic, 'skip');
    expect($i->options['duplicate_products'])->toBe(1);
    ($this->commit)($i);

    $i->refresh();
    expect($i->created_count)->toBe(1)->and($i->skipped_count)->toBe(1);
    expect(Product::where('sku', 'PNJ-1')->value('name'))->toBe('Existing');
});

it('applies the duplicate policy: update (only provided fields, no stock rewrite, variants kept)', function () {
    asTenant($this->tenant);
    $p = Product::factory()->create(['sku' => 'TEE', 'name' => 'Old Tee', 'type' => 'variable', 'selling_price' => '500', 'description' => 'Keep me']);
    $p->variants()->create(['sku' => 'TEE-XL', 'options' => ['Size' => 'XL']]);
    $p->variants()->create(['sku' => 'TEE-S', 'options' => ['Size' => 'S']]);
    app(\App\Domain\Inventory\StockService::class)->record($p->id, $p->variants[1]->id, 'opening', 7, '1');

    $csv = "sku,name,selling_price,variant_sku,option1_name,option1_value,variant_stock\nTEE,New Tee,650,TEE-S,Size,S,99\nTEE,,,TEE-M,Size,M,4\n";
    $i = ($this->prepare)($csv, 'update');
    ($this->commit)($i);

    $p->refresh();
    expect($i->refresh()->updated_count)->toBe(1)
        ->and($p->name)->toBe('New Tee')->and((string) $p->selling_price)->toBe('650.00')->and($p->description)->toBe('Keep me')
        ->and($p->variants->pluck('sku')->sort()->values()->all())->toBe(['TEE-M', 'TEE-S', 'TEE-XL']); // XL not deleted
    expect(StockBalance::where('variant_id', $p->variants->firstWhere('sku', 'TEE-S')->id)->value('on_hand'))->toBe(7); // not rewritten
    expect(StockBalance::where('variant_id', $p->variants->firstWhere('sku', 'TEE-M')->id)->value('on_hand'))->toBe(4); // new variant gets opening stock
});

it('applies the duplicate policy: create new SKU', function () {
    asTenant($this->tenant);
    Product::factory()->create(['sku' => 'PNJ-1', 'name' => 'Existing', 'selling_price' => 1]);
    $i = ($this->prepare)($this->basic, 'create_new_sku');
    ($this->commit)($i);

    expect(Product::pluck('sku')->sort()->values()->all())->toBe(['PNJ-1', 'PNJ-1-2', 'SRE-1']);
    expect($i->refresh()->created_count)->toBe(2);
});

it('does not let a variable-product import steal a variant SKU from another product', function () {
    asTenant($this->tenant);
    $other = Product::factory()->create(['type' => 'variable']);
    $other->variants()->create(['sku' => 'TAKEN', 'options' => ['a' => 'b']]);

    $i = ($this->prepare)("sku,name,selling_price,variant_sku,option1_name,option1_value\nNEW,New,10,TAKEN,Size,S\n");
    expect($i->options['invalid_rows'])->toBe(1);
});

it('enforces the plan row limit', function () {
    config(['imports.max_rows' => 3]);
    ($this->upload)("name,sku,selling_price\nA,1,1\nB,2,1\nC,3,1\nD,4,1\n");
    $i = ($this->import)();

    expect($i->status)->toBe('failed')->and($i->error)->toContain('limit')->and($i->rows()->count())->toBe(0);
});

it('rejects empty files and files without data rows', function () {
    ($this->upload)('');
    expect(($this->import)()?->status)->toBeIn(['failed', null]);
    ($this->upload)("name,sku,selling_price\n");
    expect(($this->import)()->status)->toBe('failed');
});

it('cannot commit twice (idempotent) and cannot commit unvalidated', function () {
    ($this->upload)($this->basic);
    $i = ($this->import)();
    ($this->as)()->post("/app/imports/{$i->id}/commit")->assertStatus(409); // not mapped/validated yet

    ($this->fullMap)($i->refresh());
    ($this->commit)($i);
    ($this->as)()->post("/app/imports/{$i->id}/commit")->assertStatus(409);
    asTenant($this->tenant);
    expect(Product::count())->toBe(2);
});

it('retries only failed rows after the cause is fixed', function () {
    asTenant($this->tenant);
    $i = ($this->prepare)("name,sku,selling_price\nA,A-1,10\nB,B-1,20\n");
    // B-1 becomes taken by a variant after validation but before the write -> that group fails
    $other = Product::factory()->create(['type' => 'variable']);
    $other->variants()->create(['sku' => 'B-1', 'options' => ['a' => 'b']]);
    ($this->commit)($i);

    $i->refresh();
    expect($i->status)->toBe('completed_with_errors')->and($i->created_count)->toBe(1)->and($i->failed_count)->toBe(1);

    ProductVariant::where('sku', 'B-1')->delete();
    ($this->as)()->post("/app/imports/{$i->id}/retry")->assertRedirect();
    $i->refresh();
    expect($i->status)->toBe('completed')->and(Product::where('sku', 'A-1')->count())->toBe(1)->and(Product::where('sku', 'B-1')->count())->toBe(1);
});

it('IMG-I5: reports how many imported products have no images', function () {
    $i = ($this->prepare)($this->basic);
    ($this->commit)($i);

    ($this->as)()->get("/app/imports/{$i->id}")->assertInertia(fn ($a) => $a->where('missingImages', 2)->where('import.status', 'completed'));
});

it('IMG-I6: imports land as draft when images are required before activation', function () {
    $this->tenant->update(['settings' => ['require_image_before_activation' => true]]);
    $i = ($this->prepare)("name,sku,selling_price,status\nA,A-1,10,active\n");
    ($this->commit)($i);
    asTenant($this->tenant);

    expect(Product::where('sku', 'A-1')->value('status'))->toBe('draft');
});

it('honours the file status when images are not required', function () {
    $i = ($this->prepare)("name,sku,selling_price,status\nA,A-1,10,active\n");
    ($this->commit)($i);
    asTenant($this->tenant);

    expect(Product::where('sku', 'A-1')->value('status'))->toBe('active');
});

it('saves the mapping per tenant and reuses it on the next upload', function () {
    ($this->upload)("Item,Ref,Rate,Qty\nA,A-1,10,2\n");
    $i = ($this->import)();
    $m = ['Item' => 'name', 'Ref' => 'sku', 'Rate' => 'selling_price', 'Qty' => 'opening_stock'];
    ($this->as)()->post("/app/imports/{$i->id}/mapping", ['mapping' => $m, 'duplicate_policy' => 'skip'])->assertSessionHasNoErrors();

    ($this->upload)("Item,Ref,Rate,Qty\nB,B-1,20,3\n");
    expect(($this->import)()->mapping)->toBe($m);
    expect(ImportMapping::count())->toBe(1);
});

it('requires SKU, name and price to be mapped and each field once', function () {
    ($this->upload)("a,b,c\n1,2,3\n");
    $i = ($this->import)();
    ($this->as)()->post("/app/imports/{$i->id}/mapping", ['mapping' => ['a' => 'name', 'b' => 'name', 'c' => 'sku'], 'duplicate_policy' => 'skip'])->assertSessionHasErrors('mapping');
    ($this->as)()->post("/app/imports/{$i->id}/mapping", ['mapping' => ['a' => 'name', 'b' => 'sku'], 'duplicate_policy' => 'skip'])->assertSessionHasErrors('mapping');
});

it('ignores purchase cost columns when the importer lacks products.costs.view', function () {
    [$mgr] = memberOf($this->tenant, 'manager');
    ($this->as)($mgr)->post('/app/imports/products', ['file' => ($this->csv)("name,sku,selling_price,purchase_cost\nA,A-1,10,7\n")]);
    $i = ($this->import)();
    ($this->as)($mgr)->post("/app/imports/{$i->id}/mapping", ['mapping' => $i->mapping, 'duplicate_policy' => 'skip']);
    ($this->as)($mgr)->post("/app/imports/{$i->id}/commit");

    asTenant($this->tenant);
    expect((string) Product::where('sku', 'A-1')->value('purchase_cost'))->toBe('0.00');
});

it('is limited to users with products.import and to the own tenant', function () {
    [$viewer] = memberOf($this->tenant, 'viewer');
    ($this->as)($viewer)->post('/app/imports/products', ['file' => ($this->csv)($this->basic)])->assertForbidden();
    ($this->as)($viewer)->get('/app/imports')->assertForbidden();

    ($this->upload)($this->basic);
    $i = ($this->import)();
    [$u2, $t2] = memberOf();
    $b = fn () => $this->actingAs($u2)->withSession(['tenant_id' => $t2->id]);
    $b()->get("/app/imports/{$i->id}")->assertNotFound();
    $b()->post("/app/imports/{$i->id}/commit")->assertNotFound();
    $b()->get("/app/imports/{$i->id}/errors")->assertNotFound();
});

it('serves a data-only template without image columns', function () {
    $csv = ($this->as)()->get('/app/imports/products/template')->assertOk()->streamedContent();
    $header = strtok(ltrim($csv, "\xEF\xBB\xBF"), "\n");

    expect($header)->toContain('sku')->and($header)->toContain('variant_sku');
    foreach (['image', 'img', 'photo', 'picture', 'thumbnail', 'gallery', 'url'] as $bad) {
        expect(strtolower($header))->not->toContain($bad);
    }
});

it('cancels an import before it starts', function () {
    ($this->upload)($this->basic);
    $i = ($this->import)();
    ($this->as)()->post("/app/imports/{$i->id}/cancel")->assertRedirect('/app/imports');
    expect($i->refresh()->status)->toBe('cancelled');
    ($this->as)()->post("/app/imports/{$i->id}/commit")->assertStatus(409);
});

it('audits upload, start and completion', function () {
    $i = ($this->prepare)($this->basic);
    ($this->commit)($i);
    foreach (['import.uploaded', 'import.started', 'import.completed'] as $a) {
        expect(AuditLog::where('action', $a)->where('tenant_id', $this->tenant->id)->exists())->toBeTrue($a);
    }
});
