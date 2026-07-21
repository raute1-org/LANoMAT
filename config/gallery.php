<?php

return [
    'max_upload_mb' => (int) env('GALLERY_MAX_UPLOAD_MB', 25),
    'max_edge' => (int) env('GALLERY_MAX_EDGE', 2560),
    'thumb_width' => (int) env('GALLERY_THUMB_WIDTH', 400),
    'quality' => (int) env('GALLERY_JPEG_QUALITY', 82),
    'allowed_mimes' => ['image/jpeg', 'image/png', 'image/webp'],
];
