<?php

return [
    // Image limits (IMG-12). Plan-configurable later via tenant settings.
    'max_images_per_product' => (int) env('CATALOG_MAX_IMAGES', 10),
    'max_upload_kb' => (int) env('CATALOG_MAX_UPLOAD_KB', 5120),
    'max_input_px' => 6000,
    'output_px' => 2000,
    'min_px_warning' => 300,
    'derivatives' => ['card' => 400, 'thumb' => 150],
    'webp_quality' => 82,
    'storage_quota_mb' => (int) env('CATALOG_STORAGE_QUOTA_MB', 1024),
    'disk' => env('CATALOG_DISK', 'local'),
    'uploads_per_minute' => (int) env('RATE_IMAGE_UPLOADS_PER_MINUTE', 60),
];
