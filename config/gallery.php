<?php

declare(strict_types=1);

return [
    'max_galleries' => (int) env('GALLERY_MAX_GALLERIES', 6),
    'max_items_per_gallery' => (int) env('GALLERY_MAX_ITEMS_PER_GALLERY', 12),
];
