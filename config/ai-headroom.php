<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Default model
    |--------------------------------------------------------------------------
    |
    | Used by Headroom::count() when no model is given. Null uses the default
    | text model of laravel/ai's default provider.
    |
    */

    'default_model' => env('HEADROOM_DEFAULT_MODEL'),

    /*
    |--------------------------------------------------------------------------
    | Counting method
    |--------------------------------------------------------------------------
    |
    | auto: estimate locally, and fetch a native count only when the estimate
    |       is within the escalation band of a limit (recommended)
    | native: always use the provider's counting endpoint where one exists
    | estimate: never call a provider; prompt content stays in your app
    |
    */

    'method' => env('HEADROOM_METHOD', 'auto'),

    /*
    |--------------------------------------------------------------------------
    | Estimates
    |--------------------------------------------------------------------------
    |
    | The safety margin is added to estimates for models without an exact local
    | tokeniser (0.10 = +10%). As calibration measures the real error, the
    | margin shrinks, but never below the floor.
    |
    | The escalation band is how close to a limit an estimate must be before
    | auto mode pays for a native count (0.15 = within 15%).
    |
    */

    'estimate_safety_margin' => 0.10,

    'safety_margin_floor' => 0.02,

    'escalation_band' => 0.15,

    /*
    |--------------------------------------------------------------------------
    | Tokeniser
    |--------------------------------------------------------------------------
    |
    | The OpenAI tokeniser downloads its vocabulary files on first use. They
    | are cached here; warm the cache before deploying to an offline host.
    |
    */

    'tokeniser' => [
        'cache_path' => storage_path('framework/cache/headroom-tokeniser'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Self-calibration
    |--------------------------------------------------------------------------
    |
    | Compares estimates with the actual input tokens providers report, and
    | keeps a correction factor per model. Nothing changes until a model has
    | min_samples; corrections are clamped to 1 +/- max_correction.
    |
    */

    'calibration' => [
        'enabled' => env('HEADROOM_CALIBRATION', true),
        'min_samples' => 20,
        'max_correction' => 0.5,
        'store' => 'cache',
    ],

    /*
    |--------------------------------------------------------------------------
    | Native count cache
    |--------------------------------------------------------------------------
    |
    | Native counts are cached by a hash of the counted content. Null store
    | uses your default cache store. TTL in seconds.
    |
    */

    'cache' => [
        'enabled' => env('HEADROOM_CACHE', true),
        'store' => env('HEADROOM_CACHE_STORE'),
        'ttl' => 86_400,
    ],

    /*
    |--------------------------------------------------------------------------
    | Models
    |--------------------------------------------------------------------------
    |
    | Context windows and prices (per million tokens) ship with the package in
    | resources/models.php and are updated with each release. Use this array
    | to override fields of a shipped model or to add your own:
    |
    | 'claude-sonnet-5-5' => ['input_price' => 1.80],
    | 'my-fine-tune' => [
    |     'provider' => 'openai',
    |     'context_window' => 128_000,
    |     'max_output_tokens' => 16_000,
    |     'input_price' => 3.00,
    |     'output_price' => 12.00,
    | ],
    |
    | Unknown models use the fallback, which is deliberately cautious (small
    | window, high prices) so limits trip early rather than late.
    |
    | Currency labels the figures in Cost. Shipped prices are USD, so only
    | change it if you override every model's prices.
    |
    */

    'currency' => 'USD',

    'models' => [],

    'fallback_model' => [
        'context_window' => 128_000,
        'max_output_tokens' => 16_000,
        'input_price' => 10.00,
        'output_price' => 50.00,
    ],

    /*
    |--------------------------------------------------------------------------
    | Middleware defaults
    |--------------------------------------------------------------------------
    |
    | Used by EnsureHeadroom when no value is passed. mode: throw raises
    | TokenLimitExceeded; report fires HeadroomLow and lets the request run.
    |
    */

    'middleware' => [
        'max_percent_of_context' => 80,
        'max_cost' => null,
        'mode' => 'throw',
    ],

];
