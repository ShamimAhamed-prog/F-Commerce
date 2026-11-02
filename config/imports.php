<?php

return [
    // Plan-based limits (spec §5.3 step 2). Plan wiring arrives in Phase 5.
    'max_rows' => (int) env('IMPORT_MAX_ROWS', 5000),
    'max_file_kb' => (int) env('IMPORT_MAX_FILE_KB', 10240),
    'chunk_size' => 500,
    'disk' => env('IMPORT_DISK', 'local'),
];
