<?php

declare(strict_types=1);
use Phoenix1331\AiHeadroom\Counting\AnthropicCounter;
use Phoenix1331\AiHeadroom\Counting\GeminiCounter;
use Phoenix1331\AiHeadroom\Counting\OpenAiCounter;

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
    | Native counters
    |--------------------------------------------------------------------------
    |
    | The counter used for each laravel/ai driver in auto and native mode.
    | Drivers not listed are always estimated. Register your own class
    | implementing Counting\Contracts\Counter to support another provider.
    |
    */

    'counters' => [
        'anthropic' => AnthropicCounter::class,
        'gemini' => GeminiCounter::class,
        'openai' => OpenAiCounter::class,
    ],

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
    | Estimate ratios and attachments
    |--------------------------------------------------------------------------
    |
    | Estimates tokenise with OpenAI's o200k encoding. Other providers split
    | text differently, so their counts are multiplied by a ratio: provider
    | tokens per o200k token. Starting points from the providers' own guidance
    | (Anthropic: ~2.5 characters per token, Gemini: ~4, o200k: ~4); the
    | accuracy suite measures them and calibration corrects them per app.
    |
    | Attachments are not tokenised locally. Each is assumed to cost a fixed
    | number of tokens by kind, and marks the count as approximate.
    |
    */

    'estimate_ratios' => [
        'openai' => 1.0,
        'anthropic' => 1.6,
        'gemini' => 1.0,
        'default' => 1.6,
    ],

    'attachment_tokens' => [
        'image' => 1_600,
        'document' => 5_000,
        'audio' => 2_000,
        'video' => 15_000,
        'other' => 5_000,
    ],

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
    | Off by default. When on, a native count is reused only when exactly the
    | same request (same history, prompt, tools and options) is counted again:
    | retries, Headroom::for() followed by sending the agent, or identical
    | opening prompts shared by many users. Each new chat turn changes the
    | history, so most conversation traffic is not cached.
    |
    | Only a SHA-256 hash of the request and the count are stored. Entries
    | expire after the TTL (seconds); counts for identical content do not
    | change, so a long TTL is safe. Null store uses your default cache store.
    |
    */

    'cache' => [
        'enabled' => (bool) env('HEADROOM_CACHE', false),
        'store' => env('HEADROOM_CACHE_STORE'),
        'ttl' => (int) env('HEADROOM_CACHE_TTL', 86_400),
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
    | All prices, shipped or overridden, are in USD: the currency providers
    | bill in.
    |
    */

    'models' => [],

    'fallback_model' => [
        'context_window' => 128_000,
        'max_output_tokens' => 16_000,
        'input_price' => 10.00,
        'output_price' => 50.00,
    ],

    /*
    |--------------------------------------------------------------------------
    | Currency
    |--------------------------------------------------------------------------
    |
    | The currency costs are reported in, and max_cost limits are set in.
    | Supported: USD, GBP. Prices are converted from USD at the fixed rates
    | below (units per 1 USD), so costs are estimates; keep the rates current.
    | GBP default checked 2026-10-09.
    |
    */

    'currency' => env('HEADROOM_CURRENCY', 'USD'),

    'exchange_rates' => [
        'GBP' => env('HEADROOM_RATE_GBP', 0.756),
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
