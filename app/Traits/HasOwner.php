<?php

namespace App\Traits;

use MongoDB\Laravel\Relations\MorphMany;
use MongoDB\Laravel\Relations\MorphTo;

trait HasOwner
{
    public function owner(): MorphTo {
        return $this->morphTo();
    }
}
