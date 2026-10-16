# Context

**Headroom** (`phoenix1331/laravel-ai-headroom`) is a Laravel package that counts the tokens a Laravel AI SDK agent request will use before it is sent, checks it against the model's context window, estimates cost, and can block requests that breach configured limits.

This is a Composer **package**, not a Laravel app. There is no `app/`, no routes and no database server; it is tested inside Orchestra Testbench.

## Architecture at a glance

| Path | Role |
|---|---|
| `src/AiHeadroomServiceProvider.php` | Merges and publishes `config/ai-headroom.php` (tag `ai-headroom-config`) |
| `src/Facades/`, `src/HeadroomManager.php` | Public entry point: `Headroom::for()`, `Headroom::count()`, `Headroom::fake()` |
| `src/Resolving/` | Rebuilds the `Laravel\Ai\PendingStep` an agent would send, for `Headroom::for()` and `headroom:inspect` |
| `src/Counting/` | `Counter` contract, native provider counters, estimating counter, resolver, escalation |
| `src/Calibration/` | Learns a per-model correction factor from actual usage, fed by the `EnsureHeadroom` middleware |
| `src/Models/ModelRegistry.php` | Model specs: shipped `resources/models.php` merged with `config('ai-headroom.models')` overrides; cautious fallback for unknown models |
| `resources/models.php` | Shipped context windows, max output and per-million prices (with long-prompt tiers) plus `last_checked`; not published |
| `src/Data/`, `src/Enums/` | `TokenCount`, `Cost`, `ModelSpec`, `CountMethod` value objects |
| `src/Middleware/`, `src/Exceptions/`, `src/Events/` | `EnsureHeadroom` guard, `TokenLimitExceeded`, `TokensCounted`, `HeadroomLow` |
| `src/Console/` | `headroom:inspect`, `headroom:models`, `headroom:calibration` |
| `config/ai-headroom.php` | Package config |
| `docs/` | GitHub Pages site (export-ignored) |

Most of `src/` is planned, not yet built. Treat the table as the target layout.

## External integrations

| Service | Use |
|---|---|
| `laravel/ai` | The SDK whose agent requests are counted |
| Anthropic `POST /v1/messages/count_tokens`, OpenAI `POST /v1/responses/input_tokens`, Gemini `POST /v1beta/models/{model}:countTokens` | Exact counts in `native`/`auto` mode; never called in the default test suite |

## Dependencies and constraints

| Package | Constraint | Why |
|---|---|---|
| `php` | `^8.3` | Matches `laravel/ai` |
| `illuminate/*` | `^12.0\|^13.0` | Matches `laravel/ai` |
| `laravel/ai` | `^1.2` | v1.2.3 is the version tested against; it is already past 1.0, so normal semver applies |
| `laravel/pint` (dev) | `^1.32` | Code style; `pint.json` uses the `laravel` preset plus `declare_strict_types` |
| `pestphp/pest` (dev) | `^4.2\|^5.0` | Pest 5 needs PHP 8.4, so Pest 4 covers PHP 8.3; below 4.2 the Laravel 12 prefer-lowest run breaks (PHPUnit `ErrorHandler::enable()` TypeError) |
| `pestphp/pest-plugin-laravel` (dev) | `^4.0\|^5.0` | Matches Pest |
| `orchestra/testbench` (dev) | `^10.0\|^11.0` | Testbench 10 = Laravel 12, 11 = Laravel 13 |
| `larastan/larastan` (dev) | `^3.0` | Static analysis at `level: max`, no baseline |

## CI (`.github/`)

| Workflow | Trigger | Runs |
|---|---|---|
| `tests.yml` | push/PR to `main` | PHP 8.3/8.4/8.5 x Laravel 12/13 x prefer-lowest/prefer-stable; Pint `--test` on prefer-stable; separate coverage job (pcov, `--min=90`, output in the job summary) |
| `static-analysis.yml` | push/PR to `main` | Larastan with GitHub annotations |
| `sdk-latest.yml` | Mondays 06:00 UTC + manual | `laravel/ai:*` (ignores our constraint, crosses majors), tests + Larastan |
| `release.yml` | tag `v*` | GitHub release with notes from the matching `## [X.Y.Z]` `CHANGELOG.md` section; fails if the section is missing |
| `dependabot.yml` | weekly | GitHub Actions and Composer dev dependencies |
| `ISSUE_TEMPLATE/` | - | Bug report, feature request, model price/context window update (no-code contributions); blank issues off, questions routed to Discussions, security to `SECURITY.md` |
| `PULL_REQUEST_TEMPLATE.md` | - | Tests, no real API calls, lint/analyse, changelog, docs checklist |

Community docs: `CONTRIBUTING.md` (setup, checks, adding a provider counter, updating prices), `SECURITY.md` (what each counting method sends where), `RELEASING.md`, `CODE_OF_CONDUCT.md` (Contributor Covenant 2.1), `CHANGELOG.md` (Keep a Changelog). All export-ignored.

## Decisions

| Decision | Reason |
|---|---|
| Guard and calibration run as `laravel/ai` agent step middleware on `PendingStep` | v1.2 hands middleware the fully resolved request (instructions, history, prompt, attachments, tools, schema, options) and the actual usage afterwards; no reflection or upstream PR needed. Details: `.claude/docs/request-resolver-spike.md` |
| `RequestResolver` mirrors `GeneratesText::prompt()` from public APIs, guarded by a parity test against a captured `PendingStep` | Faking the agent to capture the step would mutate global state and write conversations |
| Native counters subclass the SDK gateways to reuse their protected request-body builders | Exact provider payloads without reimplementing serialisation; protected methods are outside semver, so the weekly `sdk-latest` CI job watches them |
| Gemini native counting maps to `generateContentRequest` itself | The SDK's Gemini gateway uses the Interactions API, whose body `countTokens` does not accept |
| Tokeniser: `yethee/tiktoken` with a persistent vocab cache path | MIT, maintained, o200k support, ~13 ms per 100 KB; downloads vocab files on first use |
| `TokenCount::total()` is stored separately from the breakdown, not summed | Native endpoints return one total including message/tool overhead that belongs to no single part |
| `TokenCount::remaining()` added beyond the brief's API | It is literally the headroom; negative when the request overflows |
| `Cost` rounds amounts to 8 decimal places | Removes float noise (`0.15 + 0.96`) while keeping per-token precision on cheap models |
| Shipped models live in `resources/models.php`, not the published config; config `models` only overrides or adds | A published config would freeze prices at publish time, so users would miss every price update |
| Overrides replace fields whole per model (`array_replace`), not recursively | Recursive merge would mix a user's shorter `tiers` list with the shipped one by index |
| Price tiers (`above` threshold) apply to the whole request, input and output | Matches Claude Haiku 5.5 (over 100k) and OpenAI (over 272k) pricing |
| Fallback model is deliberately pessimistic (128k window, $10/$50) | Unknown models should trip limits early, never late |
| Worst-case output cost uses the request's `maxTokens` capped at the model maximum, or the model maximum when unset | Never under-states the ceiling |
| A feature test asserts every laravel/ai default/cheapest/smartest model has a registry entry | New SDK defaults are caught in CI, including the weekly `sdk-latest` run |
| Larastan `configDirectories: [config]` | Lets `env()` in the package config pass while keeping the rule everywhere else |
| No Docker: everything runs on the host PHP (WSL), deliberately overriding the default Docker/FrankenPHP/MySQL stack | A package has nothing to serve and no database; contributors expect `composer install && composer test`, CI runs on GitHub's PHP runners, and a container only duplicated the host runtime |
| `composer.lock` gitignored | Libraries should not pin consumers' dependencies |
| No Makefile | With Docker gone every target was a one-line wrapper; Composer scripts are the PHP package convention |
| Dist archive ships only `src/`, `config/`, `resources/`, `composer.json`, `README.md`, `LICENSE`; everything else is `export-ignore`d in `.gitattributes` | Lean `composer require`; new dev files must be added to `.gitattributes` by hand (no automated archive check) |
| `.claude/` gitignored | Planning files are not part of the deliverable |
| Husky 9 git hooks in `.husky/` (tracked), installed by `npm install` via the `prepare` script | Hooks survive a fresh clone; `commit-msg` enforces lowercase conventional commits, `pre-commit` runs `pint --test` and `osv-scanner` against the local `composer.lock` |
| `phpunit.xml` `defaultTestSuite="Unit,Feature"` | The `Accuracy` suite calls real APIs and only runs via `composer test:accuracy`; it fails fast with a clear message when no provider key is set |
| Interface placement checked by a Finder-based test, not a Pest arch expectation | Pest has no expectation for "interfaces live only in `Contracts`" |
| Larastan analyses `src/`, `config/` and `resources/`, not `tests/` | Pest's dynamic `arch()->expect()` API is untypable at max level; tests are covered by Pest itself |
| Hook bodies are POSIX `sh` | Husky runs hooks with `sh -e`, which is `dash` on Ubuntu/WSL; verify with `sh .husky/<hook>` |

## Running things

Composer scripts are the single command surface (no Makefile).

**composer**: test | test:coverage | test:accuracy | lint | lint:test | analyse

`test:coverage` enforces 90% on `src/`.

## Host prerequisites

| Tool | Why |
|---|---|
| PHP 8.3+ with `curl`, `mbstring`, `pdo_sqlite`, `zip` | Composer, Pest (Testbench uses in-memory SQLite), Pint, Larastan |
| `pcov` or `xdebug` (optional) | Local coverage only; CI reports coverage |
| Composer 2 | Dependencies |
| Node + npm | Husky hooks (`npm install` once after cloning) |
| `osv-scanner` | `pre-commit` security audit (`go install github.com/google/osv-scanner/cmd/osv-scanner@latest`) |
