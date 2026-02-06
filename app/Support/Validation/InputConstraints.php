<?php

declare(strict_types=1);

namespace App\Support\Validation;

final class InputConstraints
{
    public const PASSWORD_MIN = 8;
    public const PASSWORD_MAX = 32;

    public const NAME_MAX = 255;
    public const DESCRIPTION_MAX = 1000;

    public const EMAIL_MAX = 255;
    public const EMAIL_ARRAY_MAX = 10;

    public const PERMISSION_MAX = 64;
    public const PERMISSIONS_ARRAY_MAX = 64;

    public const PHONE_MAX = 32;
    public const PHONE_ARRAY_MAX = 5;

    public const METADATA_MAX_ITEMS = 20;
    public const METADATA_MAX_KB = 8;
    public const IMAGE_MAX_KB = 5120;

    public const OBJECT_ID_LENGTH = 24;
}
