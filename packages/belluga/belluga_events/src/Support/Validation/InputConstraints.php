<?php

declare(strict_types=1);

namespace Belluga\Events\Support\Validation;

final class InputConstraints
{
    public const NAME_MAX = 255;

    public const DESCRIPTION_MAX = 1000;

    public const RICH_TEXT_MAX_BYTES = 102400;

    public const IMAGE_MAX_KB = 5120;

    public const OBJECT_ID_LENGTH = 24;
}
