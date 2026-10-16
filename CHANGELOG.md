# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- `EnsureHeadroom` agent middleware: counts every generation step before it is sent against `maxPercentOfContext`, `maxCost` and the context window; throws `TokenLimitExceeded` (after firing `RequestBlocked`) or, with `->report()`, fires `HeadroomLow` and lets the request through; reports `TokensUsed` after each step, including streams, and `RequestFailed` when a provider call fails
- Self-calibration in use: real usage reported through `TokensUsed` (from the middleware or `Headroom::recordUsage()`) trains each model's correction, and estimates for calibrated models are corrected and reported as `CountMethod::Calibrated` with the measured error as confidence. Native counts are never calibrated
- `TokensUsed::$countedModel`, the model the request was counted for
- Self-calibration engine: a per-model correction factor and measured error learned from estimate versus actual pairs, exponentially weighted (`calibration.smoothing`), clamped per observation (`calibration.max_correction`), active after `calibration.min_samples`, with the safety margin following the measured error down to `safety_margin_floor`; stored in the cache (`HEADROOM_CALIBRATION_STORE`)
- Events as public API for recording every request without Headroom storing anything: `TokensCounted`, `TokensUsed`, `HeadroomLow`, `RequestBlocked` and `RequestFailed`, linked by a ULID `TokenCount::requestId()` and laravel/ai's invocation ID, and never carrying prompt, response or error text (see `docs/events.md`)
- `Headroom::recordUsage($count, $response)` to report actual usage for requests counted with `Headroom::for()`, for normal and streamed responses
- `Headroom::resolveContextUsing()` to tag requests with scalar context such as `tenant_id`; failures log a warning and never fail a request
- Actual cost on `TokensUsed`, with optional `cache_read_price` and `cache_write_price` in the model registry (shipped for Claude models) and an `approximate` flag on `Cost` when cache tokens cannot be priced
- `TokenLimitExceeded` exception carrying the `TokenCount`, and the `LimitType` enum
- `TokenCount` value object: total, per-part breakdown (instructions, history, tools, attachments, prompt), counting method, confidence, model, context window, percent used, remaining tokens, fits and estimated cost
- `Cost` value object: input cost plus worst-case output cost, rounded to 8 decimal places
- `CountMethod` enum: `Native`, `Estimated`, `Calibrated`
- Model registry with context windows, max output and prices for the current Anthropic, OpenAI and Gemini models (checked 2026-10-16), including long-prompt price tiers
- `models` config key to override fields of a shipped model or add your own, and a cautious `fallback_model` for unknown models
- Costs in USD or GBP via the `currency` config, converted from USD at configurable `exchange_rates` (`HEADROOM_CURRENCY`, `HEADROOM_RATE_GBP`)
- `Headroom` facade and `Headroom::count($text, model: ...)` for plain text, defaulting to `default_model` or laravel/ai's default text model
- `Headroom::for($agent, $prompt, ...)` counts the whole request a laravel/ai agent would send (instructions, history, tools, attachments, structured output schema and prompt) without calling the model, with the same arguments as `prompt()` plus `messages:` and `tools:` for state set with `withMessages()` and `withTools()`
- Local estimating counter: OpenAI's o200k tokeniser, per-provider `estimate_ratios` and the safety margin for other providers, and fixed `attachment_tokens` for images, documents, audio and video
- Anthropic native counter using `POST /v1/messages/count_tokens` with the provider's laravel/ai credentials and base URL
- Gemini native counter using `POST models/{model}:countTokens` with a `generateContentRequest` built from the step
- OpenAI native counter using `POST /v1/responses/input_tokens` with the provider's laravel/ai credentials and base URL
- `auto` counting (the default) estimates first and only fetches a native count when the estimate is within `escalation_band` of a limit, so clear passes and clear fails never call the provider
- `method` config (`auto`, `native`, `estimate`) and a `counters` map from laravel/ai driver to counter class, so apps can register counters for other providers
- Opt-in cache for native counts (`HEADROOM_CACHE`, `HEADROOM_CACHE_TTL`, `HEADROOM_CACHE_STORE`), keyed by a SHA-256 fingerprint of the request; stores the hash and count only, never content
- Full `config/ai-headroom.php`: default model, counting method, safety margins, escalation band, tokeniser cache path, calibration, cache, currency and middleware defaults
