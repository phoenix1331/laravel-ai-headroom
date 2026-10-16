# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- `TokenCount` value object: total, per-part breakdown (instructions, history, tools, attachments, prompt), counting method, confidence, model, context window, percent used, remaining tokens, fits and estimated cost
- `Cost` value object: input cost plus worst-case output cost, rounded to 8 decimal places
- `CountMethod` enum: `Native`, `Estimated`, `Calibrated`
- Model registry with context windows, max output and prices for the current Anthropic, OpenAI and Gemini models (checked 2026-10-16), including long-prompt price tiers
- `models` config key to override fields of a shipped model or add your own, and a cautious `fallback_model` for unknown models
- Costs in USD or GBP via the `currency` config, converted from USD at configurable `exchange_rates` (`HEADROOM_CURRENCY`, `HEADROOM_RATE_GBP`)
- `Headroom` facade and `Headroom::count($text, model: ...)` for plain text, defaulting to `default_model` or laravel/ai's default text model
- Local estimating counter: OpenAI's o200k tokeniser, per-provider `estimate_ratios` and the safety margin for other providers, and fixed `attachment_tokens` for images, documents, audio and video
- Anthropic native counter using `POST /v1/messages/count_tokens` with the provider's laravel/ai credentials and base URL
- Full `config/ai-headroom.php`: default model, counting method, safety margins, escalation band, tokeniser cache path, calibration, cache, currency and middleware defaults
