<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Product image uploads
    |--------------------------------------------------------------------------
    |
    | Central place to tune how product images are validated and stored.
    | "disk" must match a disk defined in config/filesystems.php.
    | "max_size" is expressed in kilobytes (5120 = 5 MB).
    |
    */

    'images' => [
        'disk' => env('PRODUCT_IMAGE_DISK', 'public'),

        'directory' => env('PRODUCT_IMAGE_DIRECTORY', 'products'),

        'max_size' => (int) env('PRODUCT_IMAGE_MAX_SIZE', 5120),

        'max_count' => (int) env('PRODUCT_IMAGE_MAX_COUNT', 10),

        'mimes' => explode(',', env('PRODUCT_IMAGE_MIMES', 'jpg,jpeg,png,webp,gif')),
    ],

];
