<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Scoped through its parent Import (always accessed via $import->rows()). */
class ImportRow extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['raw_data' => 'array', 'normalized_data' => 'array', 'errors' => 'array'];
    }
}
