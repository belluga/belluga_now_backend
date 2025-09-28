<?php


namespace Tests\Api\Traits;

use Illuminate\Support\Facades\Artisan;

trait ClearConfigCacheOnce
{
    private static bool $cacheHasBeenCleared = false;

    public function clearConfigCacheOnce(): void
    {
        if (!self::$cacheHasBeenCleared) {
            Artisan::call('config:clear');
            self::$cacheHasBeenCleared = true;
        }
    }
}
