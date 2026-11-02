<?php

use App\Models\AuditLog;
use App\Models\MediaFile;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->withoutVite();
    Storage::fake('local');
    [$this->user, $this->tenant] = memberOf();
    asTenant($this->tenant);
    $this->product = Product::factory()->create();
    $this->base = "/app/products/{$this->product->id}/images";
    $this->as = fn (?\App\Models\User $u = null) => $this->actingAs($u ?? $this->user)->withSession(['tenant_id' => $this->tenant->id]);
    $this->png = fn (string $name = 'a.png', int $w = 800, int $h = 600) => UploadedFile::fake()->image($name, $w, $h);
    $this->add = fn (int $n = 1, ?\App\Models\User $u = null) => ($this->as)($u)->postJson($this->base, [
        'images' => array_map(fn ($i) => ($this->png)("p{$i}.png"), range(1, $n)),
    ]);
    $this->ids = fn () => ProductImage::where('product_id', $this->product->id)->orderBy('position')->pluck('id')->all();
});

/** JPEG with an EXIF Orientation tag. */
function jpegWithOrientation(int $w, int $h, int $orientation): UploadedFile
{
    $im = imagecreatetruecolor($w, $h);
    ob_start();
    imagejpeg($im);
    $jpg = ob_get_clean();
    $tiff = 'MM'.pack('n', 42).pack('N', 8).pack('n', 1).pack('nnN', 0x0112, 3, 1).pack('nn', $orientation, 0).pack('N', 0);
    $app1 = "Exif\0\0".$tiff;
    $bytes = substr($jpg, 0, 2)."\xFF\xE1".pack('n', strlen($app1) + 2).$app1.substr($jpg, 2);
    $path = tempnam(sys_get_temp_dir(), 'exif').'.jpg';
    file_put_contents($path, $bytes);

    return new UploadedFile($path, 'photo.jpg', 'image/jpeg', null, true);
}

it('adds multiple images, processes them, and makes the first primary', function () {
    $res = ($this->add)(3)->assertCreated();

    $items = $res->json('items');
    expect($items)->toHaveCount(3)
        ->and(collect($items)->pluck('status')->unique()->all())->toBe(['ready'])
        ->and(collect($items)->pluck('position')->all())->toBe([1, 2, 3])
        ->and(collect($items)->pluck('is_primary')->all())->toBe([true, false, false]);
});

it('stores files under the tenant path with randomized names, never the client filename', function () {
    ($this->add)(1)->assertCreated();
    $media = MediaFile::first();

    expect($media->path)->toStartWith("tenants/{$this->tenant->id}/products/{$this->product->id}/")
        ->and($media->path)->not->toContain('p1')
        ->and($media->original_path)->not->toContain('p1.png');
    foreach ([$media->path, $media->original_path, ...array_values($media->derivatives)] as $p) {
        Storage::disk('local')->assertExists($p);
    }
    expect($media->mime)->toBe('image/webp')->and(array_keys($media->derivatives))->toEqualCanonicalizing(['card', 'thumb']);
});

it('resizes output to at most 2000px on the long edge and makes small derivatives', function () {
    ($this->as)()->postJson($this->base, ['images' => [($this->png)('big.png', 3000, 1500)]])->assertCreated();
    $media = MediaFile::first();

    [$w, $h] = getimagesizefromstring(Storage::disk('local')->get($media->path));
    expect([$w, $h])->toBe([2000, 1000]);
    [$tw] = getimagesizefromstring(Storage::disk('local')->get($media->derivatives['thumb']));
    expect($tw)->toBe(150);
});

it('applies EXIF orientation and strips metadata', function () {
    ($this->as)()->postJson($this->base, ['images' => [jpegWithOrientation(200, 100, 6)]])->assertCreated();
    $bytes = Storage::disk('local')->get(MediaFile::first()->path);

    [$w, $h] = getimagesizefromstring($bytes);
    expect([$w, $h])->toBe([100, 200]); // rotated upright
    expect(str_contains($bytes, 'Exif'))->toBeFalse();
});

it('enforces the per-product image limit', function () {
    config(['catalog.max_images_per_product' => 3]);
    ($this->add)(3)->assertCreated();
    ($this->add)(1)->assertStatus(422)->assertJsonPath('errors.images.0', 'Too many images. Maximum is 3 per product.');
    expect(ProductImage::count())->toBe(3);
});

it('rejects SVG, GIF, text renamed as an image, oversize and too-large dimensions', function () {
    $svg = UploadedFile::fake()->createWithContent('x.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
    $gif = UploadedFile::fake()->createWithContent('x.gif', base64_decode('R0lGODlhAQABAAAAACH5BAEKAAEALAAAAAABAAEAAAICTAEAOw=='));
    $fake = UploadedFile::fake()->createWithContent('evil.png', '<?php echo 1; ?> not an image');
    $php = UploadedFile::fake()->createWithContent('shell.jpg', "\xFF\xD8\xFF<?php system(\$_GET['c']); ?>");

    foreach ([$svg, $gif, $fake, $php] as $file) {
        ($this->as)()->postJson($this->base, ['images' => [$file]])->assertStatus(422);
    }

    config(['catalog.max_upload_kb' => 1]);
    ($this->as)()->postJson($this->base, ['images' => [($this->png)('a.png', 900, 900)]])->assertStatus(422);
    config(['catalog.max_upload_kb' => 5120, 'catalog.max_input_px' => 500]);
    ($this->as)()->postJson($this->base, ['images' => [($this->png)('a.png', 800, 600)]])->assertStatus(422);

    expect(ProductImage::count())->toBe(0);
    expect(Storage::disk('local')->allFiles())->toBe([]);
});

it('warns about very small images but accepts them', function () {
    ($this->as)()->postJson($this->base, ['images' => [($this->png)('tiny.png', 200, 200)]])
        ->assertCreated()->assertJsonPath('warnings.0', fn ($w) => str_contains($w, 'small'));
});

it('marks undecodable files as failed in the job instead of crashing', function () {
    // Valid header (passes upload checks) but truncated pixel data fails decode.
    $src = ($this->png)('a.png', 100, 100);
    $good = file_get_contents($src->getRealPath());
    $truncated = UploadedFile::fake()->createWithContent('t.png', substr($good, 0, 60));
    $res = ($this->as)()->postJson($this->base, ['images' => [$truncated]]);

    if ($res->status() === 201) {
        expect(ProductImage::first()->status)->toBe('failed')->and(ProductImage::first()->error)->not->toBeNull();
    } else {
        $res->assertStatus(422); // rejected up front is equally acceptable
    }
});

it('enforces the storage quota', function () {
    config(['catalog.storage_quota_mb' => 0]);
    ($this->add)(1)->assertStatus(422)->assertJsonPath('errors.images.0', 'Storage quota exceeded.');
});

it('replaces an image keeping position, primary flag, alt text and variant links', function () {
    $v = $this->product->variants()->create(['sku' => 'V1', 'options' => ['S' => 'M']]);
    ($this->add)(2);
    [$first, $second] = ($this->ids)();
    ($this->as)()->patchJson("{$this->base}/{$first}", ['alt_text' => 'Front', 'variant_ids' => [$v->id]])->assertOk();
    $oldPaths = MediaFile::find(ProductImage::find($first)->media_file_id)->only('path', 'original_path');

    ($this->as)()->postJson("{$this->base}/{$first}/replace", ['image' => ($this->png)('new.png', 640, 640)])->assertOk();

    $img = ProductImage::find($first);
    expect($img->position)->toBe(1)->and($img->is_primary)->toBeTrue()->and($img->alt_text)->toBe('Front')
        ->and($img->variants->pluck('id')->all())->toBe([$v->id])->and($img->status)->toBe('ready');
    foreach ($oldPaths as $p) {
        Storage::disk('local')->assertMissing($p); // old file removed after success
    }
    expect(MediaFile::count())->toBe(2); // no orphan media rows
    expect(AuditLog::where('action', 'product.image.replaced')->exists())->toBeTrue();
});

it('keeps the old image when a replacement cannot be processed', function () {
    ($this->add)(1);
    $id = ($this->ids)()[0];
    $old = ProductImage::find($id)->media_file_id;

    // Passes upload checks, fails decoding in the job.
    $src = ($this->png)('a.png', 100, 100);
    $good = file_get_contents($src->getRealPath());
    $res = ($this->as)()->postJson("{$this->base}/{$id}/replace", ['image' => UploadedFile::fake()->createWithContent('t.png', substr($good, 0, 70))]);

    if ($res->status() === 200) {
        expect(ProductImage::find($id)->media_file_id)->toBe($old)->and(ProductImage::find($id)->status)->toBe('ready');
    } else {
        $res->assertStatus(422);
    }
    Storage::disk('local')->assertExists(MediaFile::find($old)->path);
});

it('edits alt text, caption and assigns variants, rejecting foreign variants', function () {
    $mine = $this->product->variants()->create(['sku' => 'V1', 'options' => ['S' => 'M']]);
    $other = Product::factory()->create();
    $foreign = $other->variants()->create(['sku' => 'V2', 'options' => ['S' => 'L']]);
    ($this->add)(1);
    $id = ($this->ids)()[0];

    ($this->as)()->patchJson("{$this->base}/{$id}", ['alt_text' => 'Red shirt', 'caption' => 'Front', 'variant_ids' => [$mine->id]])
        ->assertOk()->assertJsonPath('items.0.alt_text', 'Red shirt')->assertJsonPath('items.0.variant_ids.0', $mine->id);
    ($this->as)()->patchJson("{$this->base}/{$id}", ['variant_ids' => [$foreign->id]])->assertStatus(422);
});

it('rotates and crops by creating new derivatives while keeping the original', function () {
    ($this->as)()->postJson($this->base, ['images' => [($this->png)('a.png', 400, 200)]]);
    $id = ($this->ids)()[0];
    $media = MediaFile::first();
    $oldDetail = $media->path;

    ($this->as)()->patchJson("{$this->base}/{$id}", ['transform' => ['rotate' => 90]])->assertOk();
    $media->refresh();
    [$w, $h] = getimagesizefromstring(Storage::disk('local')->get($media->path));
    expect([$w, $h])->toBe([200, 400]);
    Storage::disk('local')->assertMissing($oldDetail);
    Storage::disk('local')->assertExists($media->original_path); // original retained (IMG-9)

    ($this->as)()->patchJson("{$this->base}/{$id}", ['transform' => ['crop' => 'square']])->assertOk();
    [$w, $h] = getimagesizefromstring(Storage::disk('local')->get($media->fresh()->path));
    expect($w)->toBe($h);

    ($this->as)()->patchJson("{$this->base}/{$id}", ['transform' => ['rotate' => 45]])->assertStatus(422);
});

it('sets the primary image, with exactly one primary at all times', function () {
    ($this->add)(3);
    [$a, $b, $c] = ($this->ids)();
    $etag = ($this->as)()->getJson($this->base)->json('etag');

    ($this->as)()->patchJson("{$this->base}/{$c}", ['is_primary' => true, 'etag' => $etag])->assertOk();

    expect(ProductImage::where('product_id', $this->product->id)->where('is_primary', true)->pluck('id')->all())->toBe([$c]);
});

it('deleting the primary promotes the next image and keeps positions contiguous', function () {
    ($this->add)(3);
    [$a, $b, $c] = ($this->ids)();

    ($this->as)()->deleteJson($this->base, ['ids' => [$a]])->assertOk();

    $imgs = ProductImage::where('product_id', $this->product->id)->orderBy('position')->get();
    expect($imgs->pluck('id')->all())->toBe([$b, $c])->and($imgs->pluck('position')->all())->toBe([1, 2])
        ->and($imgs->pluck('is_primary')->all())->toBe([true, false]);
    expect(MediaFile::count())->toBe(2);
});

it('multi-deletes, clears variant links and removes files', function () {
    $v = $this->product->variants()->create(['sku' => 'V1', 'options' => ['S' => 'M']]);
    ($this->add)(3);
    [$a, $b, $c] = ($this->ids)();
    ($this->as)()->patchJson("{$this->base}/{$b}", ['variant_ids' => [$v->id]]);

    ($this->as)()->deleteJson($this->base, ['ids' => [$b, $c]])->assertOk();

    expect(ProductImage::count())->toBe(1)->and(\DB::table('product_image_variant')->count())->toBe(0);
    expect(count(Storage::disk('local')->allFiles()))->toBe(4); // 1 original + detail + card + thumb
    expect(AuditLog::where('action', 'product.image.deleted')->count())->toBe(2);
});

it('reorders images and persists the position', function () {
    ($this->add)(3);
    [$a, $b, $c] = ($this->ids)();
    $etag = ($this->as)()->getJson($this->base)->json('etag');

    ($this->as)()->postJson("{$this->base}/reorder", ['order' => [$c, $a, $b], 'etag' => $etag])->assertOk();

    expect(($this->ids)())->toBe([$c, $a, $b]);
    expect(ProductImage::find($a)->is_primary)->toBeTrue(); // primary is explicit, not position-derived
    expect(AuditLog::where('action', 'product.image.reordered')->exists())->toBeTrue();
});

it('returns 409 for a stale reorder or primary change instead of overwriting', function () {
    ($this->add)(3);
    [$a, $b, $c] = ($this->ids)();
    $stale = ($this->as)()->getJson($this->base)->json('etag');

    ($this->as)()->postJson("{$this->base}/reorder", ['order' => [$b, $a, $c], 'etag' => $stale])->assertOk(); // someone else wins

    ($this->as)()->postJson("{$this->base}/reorder", ['order' => [$c, $b, $a], 'etag' => $stale])
        ->assertStatus(409)->assertJsonPath('code', 'conflict');
    ($this->as)()->patchJson("{$this->base}/{$c}", ['is_primary' => true, 'etag' => $stale])->assertStatus(409);
    expect(($this->ids)())->toBe([$b, $a, $c]);
});

it('rejects a reorder that does not list every image exactly once', function () {
    ($this->add)(3);
    [$a, $b, $c] = ($this->ids)();
    $etag = ($this->as)()->getJson($this->base)->json('etag');

    ($this->as)()->postJson("{$this->base}/reorder", ['order' => [$a, $b], 'etag' => $etag])->assertStatus(422);
    ($this->as)()->postJson("{$this->base}/reorder", ['order' => [$a, $b, 999999], 'etag' => $etag])->assertStatus(422);
});

it('isolates images between tenants (management and file delivery)', function () {
    ($this->add)(1);
    $id = ($this->ids)()[0];
    [$u2, $t2] = memberOf();
    $b = fn () => $this->actingAs($u2)->withSession(['tenant_id' => $t2->id]);

    $b()->getJson("/app/media/{$id}/thumb")->assertNotFound();
    $b()->getJson($this->base)->assertNotFound();
    $b()->postJson($this->base, ['images' => [($this->png)()]])->assertNotFound();
    $b()->deleteJson($this->base, ['ids' => [$id]])->assertNotFound();
    expect(ProductImage::withoutGlobalScopes()->count())->toBe(1);

    ($this->as)()->get("/app/media/{$id}/thumb")->assertOk()->assertHeader('Content-Type', 'image/webp');
});

it('does not let one product manage another product image id', function () {
    ($this->add)(1);
    $id = ($this->ids)()[0];
    $other = Product::factory()->create();

    ($this->as)()->patchJson("/app/products/{$other->id}/images/{$id}", ['alt_text' => 'x'])->assertNotFound();
    ($this->as)()->postJson("/app/products/{$other->id}/images/{$id}/replace", ['image' => ($this->png)()])->assertNotFound();
});

it('lets viewers see images but not manage them', function () {
    ($this->add)(1);
    $id = ($this->ids)()[0];
    [$viewer] = memberOf($this->tenant, 'viewer');

    ($this->as)($viewer)->getJson($this->base)->assertOk();
    ($this->as)($viewer)->get("/app/media/{$id}/card")->assertOk();
    ($this->as)($viewer)->postJson($this->base, ['images' => [($this->png)()]])->assertForbidden();
    ($this->as)($viewer)->deleteJson($this->base, ['ids' => [$id]])->assertForbidden();
    ($this->as)($viewer)->patchJson("{$this->base}/{$id}", ['alt_text' => 'x'])->assertForbidden();
    ($this->as)($viewer)->postJson("{$this->base}/reorder", ['order' => [$id], 'etag' => 'x'])->assertForbidden();
});

it('lets inventory staff manage images and delivery staff only view', function () {
    [$inv] = memberOf($this->tenant, 'inventory_staff');
    [$del] = memberOf($this->tenant, 'delivery_staff');

    ($this->add)(1, $inv)->assertCreated();
    ($this->add)(1, $del)->assertForbidden();
});

it('audits add, edit, delete', function () {
    ($this->add)(1);
    $id = ($this->ids)()[0];
    ($this->as)()->patchJson("{$this->base}/{$id}", ['alt_text' => 'x']);
    ($this->as)()->deleteJson($this->base, ['ids' => [$id]]);

    foreach (['added', 'updated', 'deleted'] as $a) {
        expect(AuditLog::where('action', "product.image.{$a}")->where('tenant_id', $this->tenant->id)->where('user_id', $this->user->id)->exists())->toBeTrue($a);
    }
});

it('cleans up all files when a product is deleted', function () {
    ($this->add)(2);
    ($this->as)()->delete("/app/products/{$this->product->id}")->assertRedirect('/app/products');

    expect(Storage::disk('local')->allFiles())->toBe([])->and(MediaFile::count())->toBe(0);
});

it('lists products missing images and walks through them with next', function () {
    $p2 = Product::factory()->create();
    $p3 = Product::factory()->create();
    ($this->add)(1); // this->product now has an image

    ($this->as)()->get('/app/products/missing-images')->assertOk()->assertInertia(fn ($a) => $a
        ->component('Products/MissingImages')->has('products.data', 2)->where('products.data.0.id', $p2->id));

    ($this->as)()->get("/app/products/missing-images/next?after={$p2->id}")->assertRedirect("/app/products/{$p3->id}/edit");
    ($this->as)()->get("/app/products/missing-images/next?after={$p3->id}")->assertRedirect("/app/products/{$p2->id}/edit"); // wraps
});

it('serves products with their image state in the editor payload', function () {
    ($this->add)(2);
    ($this->as)()->get("/app/products/{$this->product->id}/edit")->assertInertia(fn ($a) => $a
        ->has('images.items', 2)->has('images.etag')->where('images.max', 10));
});
