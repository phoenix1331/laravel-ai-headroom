<?php

declare(strict_types=1);

/*
 * Model registry defaults shipped with the package. Do not edit in your app:
 * override or add models through the `models` key in config/ai-headroom.php,
 * so price updates in new releases keep reaching you.
 *
 * Prices are per million tokens in USD, standard (non-batch) rates, from the
 * providers' official pricing pages. A tier applies its prices to the whole
 * request once the prompt is above `above` input tokens.
 *
 * cache_read_price and cache_write_price are optional. cache_write_price is
 * the 5-minute cache write rate; 1-hour writes cost more, so costs for them
 * are understated. Without a cache price, reported cache tokens are priced as
 * normal input and the cost is flagged approximate.
 */

return [

    'last_checked' => '2026-10-16',

    'models' => [

        // https://platform.claude.com/docs/en/about-claude/pricing
        'claude-fable-5-1' => [
            'provider' => 'anthropic',
            'context_window' => 1_000_000,
            'max_output_tokens' => 128_000,
            'input_price' => 10.00,
            'output_price' => 50.00,
            'cache_read_price' => 0.25,
            'cache_write_price' => 12.50,
        ],

        'claude-opus-5-5' => [
            'provider' => 'anthropic',
            'context_window' => 1_000_000,
            'max_output_tokens' => 128_000,
            'input_price' => 4.00,
            'output_price' => 20.00,
            'cache_read_price' => 0.20,
            'cache_write_price' => 5.00,
        ],

        'claude-sonnet-5-5' => [
            'provider' => 'anthropic',
            'context_window' => 1_000_000,
            'max_output_tokens' => 128_000,
            'input_price' => 2.00,
            'output_price' => 10.00,
            'cache_read_price' => 0.10,
            'cache_write_price' => 2.50,
        ],

        'claude-haiku-5-5' => [
            'provider' => 'anthropic',
            'context_window' => 1_000_000,
            'max_output_tokens' => 128_000,
            'input_price' => 0.10,
            'output_price' => 0.50,
            'cache_read_price' => 0.01,
            'cache_write_price' => 0.125,
            'tiers' => [
                ['above' => 100_000, 'input_price' => 0.50, 'output_price' => 2.50, 'cache_read_price' => 0.05, 'cache_write_price' => 0.625],
            ],
        ],

        // https://developers.openai.com/api/docs/models
        // Prompts over 272K input tokens: 2x input, 1.5x output for the full request.
        'gpt-6-astra' => [
            'provider' => 'openai',
            'context_window' => 1_050_000,
            'max_output_tokens' => 128_000,
            'input_price' => 10.00,
            'output_price' => 50.00,
            'tiers' => [
                ['above' => 272_000, 'input_price' => 20.00, 'output_price' => 75.00],
            ],
        ],

        'gpt-6.1-sol' => [
            'provider' => 'openai',
            'context_window' => 1_050_000,
            'max_output_tokens' => 128_000,
            'input_price' => 2.00,
            'output_price' => 10.00,
            'tiers' => [
                ['above' => 272_000, 'input_price' => 4.00, 'output_price' => 15.00],
            ],
        ],

        'gpt-6-luna' => [
            'provider' => 'openai',
            'context_window' => 1_050_000,
            'max_output_tokens' => 128_000,
            'input_price' => 0.10,
            'output_price' => 0.50,
            'tiers' => [
                ['above' => 272_000, 'input_price' => 0.20, 'output_price' => 0.75],
            ],
        ],

        // https://ai.google.dev/gemini-api/docs/pricing
        // Price rises to $1.50 input / $7.50 output on 2027-01-01.
        'gemini-3.8-flash' => [
            'provider' => 'gemini',
            'context_window' => 1_048_576,
            'max_output_tokens' => 65_536,
            'input_price' => 0.75,
            'output_price' => 3.75,
        ],

        // Text, image and video input price; audio input is $0.50.
        'gemini-3.1-flash-lite' => [
            'provider' => 'gemini',
            'context_window' => 1_048_576,
            'max_output_tokens' => 65_536,
            'input_price' => 0.25,
            'output_price' => 1.50,
        ],

    ],

];
