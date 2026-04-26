<?php

declare(strict_types=1);

namespace App\Support\Validation;

final class InputConstraints
{
    public const PASSWORD_MIN = 8;

    public const PASSWORD_MAX = 32;

    public const NAME_MAX = 255;

    public const DESCRIPTION_MAX = 1000;

    public const ACCOUNT_PROFILE_RICH_TEXT_MAX_BYTES = 102400;

    public const EMAIL_MAX = 255;

    public const EMAIL_ARRAY_MAX = 10;

    public const PERMISSION_MAX = 64;

    public const PERMISSIONS_ARRAY_MAX = 64;

    public const PHONE_MAX = 32;

    public const PHONE_ARRAY_MAX = 5;

    public const METADATA_MAX_ITEMS = 20;

    public const TAXONOMY_BATCH_MAX_ITEMS = 100;

    public const PUBLIC_PAGE_SIZE_MAX = 50;

    public const PUBLIC_PAGE_MAX = 200;

    public const PUBLIC_FILTER_LIST_VALUES_MAX = 20;

    public const PUBLIC_GEO_DISTANCE_MAX_METERS = 100000;

    public const DISCOVERY_FILTER_ALLOWED_TAXONOMIES_MAX = 20;

    public const DISCOVERY_FILTER_PUBLIC_TAXONOMY_FILTERS_MAX = 20;

    public const DISCOVERY_FILTER_TYPE_OPTIONS_MAX = 100;

    public const DISCOVERY_FILTER_TAXONOMY_GROUPS_MAX = 20;

    public const DISCOVERY_FILTER_TAXONOMY_TERMS_PER_GROUP_MAX = 200;

    public const DISCOVERY_FILTER_TAXONOMY_TERMS_TOTAL_MAX = 1000;

    public const ADMIN_TAXONOMY_BATCH_TERMS_PER_GROUP_MAX = 200;

    public const METADATA_MAX_KB = 8;

    public const IMAGE_MAX_KB = 5120;

    public const OBJECT_ID_LENGTH = 24;
}
