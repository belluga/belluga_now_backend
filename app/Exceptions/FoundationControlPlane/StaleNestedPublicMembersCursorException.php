<?php

declare(strict_types=1);

namespace App\Exceptions\FoundationControlPlane;

use Illuminate\Contracts\Debug\ShouldntReport;

final class StaleNestedPublicMembersCursorException extends ConcurrencyConflictException implements ShouldntReport {}
