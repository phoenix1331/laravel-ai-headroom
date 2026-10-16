# Contributing to Headroom

Thanks for helping. Bug reports, model price updates and new provider counters are all welcome.

Questions are best asked in [GitHub Discussions](https://github.com/phoenix1331/laravel-ai-headroom/discussions) rather than issues.

## Setup

Prerequisites: PHP 8.3+ with `mbstring`, `pdo_sqlite` and `zip`, Composer 2, Node and npm. `osv-scanner` is required by the pre-commit hook (`go install github.com/google/osv-scanner/cmd/osv-scanner@latest`).

```bash
git clone https://github.com/phoenix1331/laravel-ai-headroom
cd laravel-ai-headroom
composer install
npm install   # activates the git hooks
```

## Running checks

| Command | What it does |
|---|---|
| `composer test` | Unit and feature tests. Never calls a real API |
| `composer test:coverage` | Tests with coverage; fails below 90% on `src/` (needs `pcov` or `xdebug`) |
| `composer test:accuracy` | Compares estimates with real provider counts and rewrites `docs/accuracy.md`. Needs at least one of `ANTHROPIC_API_KEY`, `GEMINI_API_KEY`, `OPENAI_API_KEY` (providers without a key are skipped); `HEADROOM_ACCURACY_{ANTHROPIC,GEMINI,OPENAI}_MODEL` change the model. Counting endpoints do not bill generation, but they are rate-limited |
| `composer lint` | Fixes code style with Pint |
| `composer lint:test` | Checks code style without changing files |
| `composer analyse` | Larastan at max level |

The tests use Pest with Orchestra Testbench and an in-memory SQLite database, so nothing else needs to be running. The first run downloads the o200k tokeniser vocabulary (~3.6 MB) into `build/tiktoken`; later runs read it from there.

Every PHP file declares `strict_types`; Pint adds it if you forget.

The base `TestCase` calls `Http::preventStrayRequests()`, so any HTTP request a test has not faked fails instead of reaching a provider. Only `tests/Accuracy/` is allowed real requests.

## Git hooks

`npm install` installs two Husky hooks:

- `commit-msg` - commit messages must be entirely lowercase and follow [Conventional Commits](https://www.conventionalcommits.org/) without a scope, e.g. `feat: add mistral counter`. `chore(deps): ...` is rejected
- `pre-commit` - runs `pint --test` and an `osv-scanner` audit of `composer.lock`

## Adding a provider counter

A counter turns a resolved agent request into a token count using the provider's own counting endpoint.

1. **Record a fixture.** Call the provider's counting endpoint once by hand and save the JSON response in `tests/Fixtures/Responses/{provider}/`. Strip any IDs or keys.
2. **Write the counter** in `src/Counting/{Provider}Counter.php`, extending `Counting\NativeCounter` and implementing `countTotal()`. Where the provider's counting endpoint accepts the same body as generation, subclass the SDK gateway in `src/Counting/Gateways/` to reuse its request body and HTTP client, and send only the fields the endpoint accepts (see `AnthropicCounter` and `AnthropicCountingGateway`).
3. **Register it** in the `counters` array of `config/ai-headroom.php`, keyed by the `laravel/ai` driver name (not the provider name, so custom provider names on that driver work too).
4. **Test it** in `tests/Feature/Counting/{Provider}CounterTest.php` with `Http::fake()` and `responseFixture()`. Assert the exact URL, headers and JSON body sent (compare `json_decode($request->body(), true)`, since the SDK uses objects for empty JSON maps), plus the parsed count. The default suite must never make a real API call.
5. **Add a corpus run** for the provider in `tests/Accuracy/` so its estimate error can be measured.
6. **Document it** with a row in the supported providers table in `README.md` and on the docs site (`docs/index.html`).
7. **Add a changelog line** under `## [Unreleased]`.

## Updating model prices or context windows

Shipped prices and context windows live in `resources/models.php`. Outdated prices are the quickest way to lose trust, so updates are very welcome.

1. Edit the model's entry (`context_window`, `max_output_tokens`, `input_price` and `output_price` per million tokens, plus any long-prompt `tiers`) or add a new one. Use the provider's standard, non-batch prices in USD.
2. Update `last_checked` at the top of the file.
3. Link the provider's pricing page in the pull request.
4. Add a changelog line under `## [Unreleased]`, e.g. `- Updated claude-sonnet-5-5 input price`.

`tests/Feature/ModelRegistryTest.php` fails if `laravel/ai`'s default, cheapest or smartest model for a provider has no entry, so a new SDK default is caught by CI.

Apps can override any field, or add private models, through `models` in their published `config/ai-headroom.php`; those changes never belong in `resources/models.php`.

Not comfortable with a pull request? Open a "Model price or context window update" issue instead.

## Adding a currency

Prices are always stored in USD and converted for display and `max_cost` limits.

1. Add a case to `src/Enums/Currency.php` and its symbol in `symbol()`.
2. Add a default rate (units per 1 USD) under `exchange_rates` in `config/ai-headroom.php`, with an `HEADROOM_RATE_{CODE}` env override and the date it was checked.
3. Extend the datasets in `tests/Unit/Enums/CurrencyTest.php` and `tests/Feature/ModelRegistryTest.php`.
4. Update the supported list in the config comment, the README and the docs site, and add a changelog line.

## Changelog

Every user-facing change adds a line under `## [Unreleased]` in `CHANGELOG.md`, in the same pull request, using the [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) headings (`Added`, `Changed`, `Fixed`, `Removed`, `Deprecated`, `Security`). Internal refactors and test-only changes do not need an entry.

## Pull requests

- Keep each pull request to one change
- Include tests; coverage must stay at or above 90%
- `composer lint:test` and `composer analyse` must pass
- Fill in the pull request checklist

Releases are covered in [RELEASING.md](RELEASING.md).
