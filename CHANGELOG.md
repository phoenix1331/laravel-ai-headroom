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
- Full `config/ai-headroom.php`: default model, counting method, safety margins, escalation band, tokeniser cache path, calibration, cache, currency and middleware defaults
