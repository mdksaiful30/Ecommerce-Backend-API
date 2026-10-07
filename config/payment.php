<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default currency
    |--------------------------------------------------------------------------
    |
    | ISO-4217 currency code used when creating Stripe PaymentIntents. Zero
    | decimal currencies (e.g. JPY) are handled automatically when converting
    | the order total to the smallest currency unit.
    |
    */

    'currency' => env('PAYMENT_CURRENCY', 'usd'),

    /*
    |--------------------------------------------------------------------------
    | Allowed payment methods
    |--------------------------------------------------------------------------
    |
    | Comma separated list of Stripe payment method types that a customer may
    | use to pay for an order. Defaults to "card".
    |
    */

    'methods' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('PAYMENT_METHODS', 'card'))
    ))),

    /*
    |--------------------------------------------------------------------------
    | Capture method
    |--------------------------------------------------------------------------
    |
    | Use "automatic" to capture funds immediately, or "manual" to authorize
    | now and capture the payment later from your own back office.
    |
    */

    'capture_method' => env('PAYMENT_CAPTURE_METHOD', 'automatic'),

    /*
    |--------------------------------------------------------------------------
    | Automatic payment methods
    |--------------------------------------------------------------------------
    |
    | Modern Stripe accounts manage the enabled payment methods from the
    | Dashboard, and the API rejects an explicit "payment_method_types" list.
    | When true (default) the PaymentIntent enables
    | "automatic_payment_methods"; when false the "methods" list above is sent
    | explicitly (older API versions/accounts only).
    |
    */

    'automatic_payment_methods' => (bool) env('PAYMENT_AUTOMATIC_PAYMENT_METHODS', true),

    /*
    |--------------------------------------------------------------------------
    | Return URL
    |--------------------------------------------------------------------------
    |
    | Some payment methods redirect the customer off-site (and back). Stripe
    | requires a return URL when confirming such PaymentIntents. Defaults to
    | the application root when not set.
    |
    */

    'return_url' => env('PAYMENT_RETURN_URL'),

    /*
    |--------------------------------------------------------------------------
    | Manual (server-side) confirmation
    |--------------------------------------------------------------------------
    |
    | Enables the test-only endpoint that confirms a PaymentIntent on the
    | server using a Stripe PaymentMethod token (e.g. pm_card_visa). This makes
    | the full gateway flow testable from Postman without a frontend. Keep this
    | disabled (false) in production.
    |
    */

    'allow_manual_confirmation' => (bool) env('PAYMENT_ALLOW_MANUAL_CONFIRMATION', false),

];
