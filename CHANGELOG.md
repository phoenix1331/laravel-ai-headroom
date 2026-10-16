# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- `TokenCount` value object: total, per-part breakdown (instructions, history, tools, attachments, prompt), counting method, confidence, model, context window, percent used, remaining tokens, fits and estimated cost
- `Cost` value object: input cost plus worst-case output cost, rounded to 8 decimal places
- `CountMethod` enum: `Native`, `Estimated`, `Calibrated`
