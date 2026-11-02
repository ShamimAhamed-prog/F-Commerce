<?php

namespace Database\Seeders;

use App\Domain\Tenancy\RoleCatalog;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        RoleCatalog::sync();
    }
}
