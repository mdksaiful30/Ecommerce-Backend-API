<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Category image uploads
    |--------------------------------------------------------------------------
    |
    | Central place to tune how category images are validated and stored.
    | "disk" must match a disk defined in config/filesystems.php.
    | "max_size" is expressed in kilobytes (5120 = 5 MB).
    |
    */

    'images' => [
        'disk' => env('CATEGORY_IMAGE_DISK', 'public'),

        'directory' => env('CATEGORY_IMAGE_DIRECTORY', 'categories'),

        'max_size' => (int) env('CATEGORY_IMAGE_MAX_SIZE', 5120),

        'max_count' => (int) env('CATEGORY_IMAGE_MAX_COUNT', 10),

        'mimes' => explode(',', env('CATEGORY_IMAGE_MIMES', 'jpg,jpeg,png,webp,gif')),
    ],

];
