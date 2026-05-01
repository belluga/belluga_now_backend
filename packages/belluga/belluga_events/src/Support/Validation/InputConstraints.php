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

    public const PUBLIC_PAGE_SIZE_MAX = 50;

    public const PUBLIC_PAGE_MAX = 200;

    public const PUBLIC_STREAM_DELTA_LIMIT = 50;

    public const PUBLIC_FILTER_LIST_VALUES_MAX = 20;

    public const PUBLIC_GEO_DISTANCE_MAX_METERS = 100000;

    public const EVENT_TAGS_MAX = 64;

    public const EVENT_CATEGORIES_MAX = 64;

    public const EVENT_TAXONOMY_TERMS_MAX = 120;

    public const EVENT_TAXONOMY_UNIQUE_TERMS_MAX = 64;

    public const EVENT_OCCURRENCES_MAX = 120;

    public const EVENT_OCCURRENCE_TAXONOMY_TERMS_TOTAL_MAX = 240;

    public const EVENT_OCCURRENCE_TAXONOMY_UNIQUE_TERMS_MAX = 64;

    public const EVENT_OCCURRENCE_PARTIES_MAX = 64;

    public const EVENT_OCCURRENCE_PARTIES_TOTAL_MAX = 240;

    public const EVENT_PROGRAMMING_ITEMS_MAX = 96;

    public const EVENT_PROGRAMMING_ITEMS_TOTAL_MAX = 240;

    public const EVENT_PROGRAMMING_PROFILE_IDS_MAX = 24;

    public const EVENT_PROGRAMMING_REFERENCES_TOTAL_MAX = 480;

    public const MAP_POI_POLYGON_RINGS_MAX = 4;

    public const MAP_POI_POLYGON_POINTS_PER_RING_MAX = 256;
}
