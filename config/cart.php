<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cart settings
    |--------------------------------------------------------------------------
    |
    | Tax rate applied to the cart total after discount.
    | Set to 0 to disable tax.
    |
    */

    'tax_rate' => (float) env('CART_TAX_RATE', 0),

    /*
    |--------------------------------------------------------------------------
    | Maximum quantity per cart line item.
    |--------------------------------------------------------------------------
    */

    'max_quantity' => (int) env('CART_MAX_QUANTITY', 100),

];
