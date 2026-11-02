<?php

namespace App\Domain\Catalog;

use RuntimeException;

/** Raised when a stale client tries to reorder / change the primary (IMG-11). */
class ImageConflict extends RuntimeException
{
}
