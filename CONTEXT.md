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
| `src/Models/` | `ModelRegistry`: context windows and prices from config |
| `src/Data/`, `src/Enums/` | `TokenCount`, `Cost`, `CountMethod` value objects |
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
| `pestphp/pest`, `pestphp/pest-plugin-laravel` (dev) | `^4.0\|^5.0` | Pest 5 needs PHP 8.4, so Pest 4 covers the PHP 8.3 CI leg |
| `orchestra/testbench` (dev) | `^10.0\|^11.0` | Testbench 10 = Laravel 12, 11 = Laravel 13 |
| `larastan/larastan` (dev) | `^3.0` | Static analysis at `level: max`, no baseline |

## Decisions

| Decision | Reason |
|---|---|
| Guard and calibration run as `laravel/ai` agent step middleware on `PendingStep` | v1.2 hands middleware the fully resolved request (instructions, history, prompt, attachments, tools, schema, options) and the actual usage afterwards; no reflection or upstream PR needed. Details: `.claude/docs/request-resolver-spike.md` |
| `RequestResolver` mirrors `GeneratesText::prompt()` from public APIs, guarded by a parity test against a captured `PendingStep` | Faking the agent to capture the step would mutate global state and write conversations |
| Native counters subclass the SDK gateways to reuse their protected request-body builders | Exact provider payloads without reimplementing serialisation; protected methods are outside semver, so the weekly `sdk-latest` CI job watches them |
| Gemini native counting maps to `generateContentRequest` itself | The SDK's Gemini gateway uses the Interactions API, whose body `countTokens` does not accept |
| Tokeniser: `yethee/tiktoken` with a persistent vocab cache path | MIT, maintained, o200k support, ~13 ms per 100 KB; downloads vocab files on first use |
| No Docker: everything runs on the host PHP (WSL), deliberately overriding the default Docker/FrankenPHP/MySQL stack | A package has nothing to serve and no database; contributors expect `composer install && composer test`, CI runs on GitHub's PHP runners, and a container only duplicated the host runtime |
| `composer.lock` gitignored and export-ignored | Libraries should not pin consumers' dependencies |
| `.claude/` gitignored | Planning files are not part of the deliverable |
| Husky 9 git hooks in `.husky/` (tracked), installed by `npm install` via the `prepare` script | Hooks survive a fresh clone; `commit-msg` enforces lowercase conventional commits, `pre-commit` runs `pint --test` and `osv-scanner` against the local `composer.lock` |
| `phpunit.xml` `defaultTestSuite="Unit,Feature"` | The `Accuracy` suite calls real APIs and only runs via `composer test:accuracy`; it fails fast with a clear message when no provider key is set |
| Interface placement checked by a Finder-based test, not a Pest arch expectation | Pest has no expectation for "interfaces live only in `Contracts`" |
| Larastan analyses `src/` and `config/` only, not `tests/` | Pest's dynamic `arch()->expect()` API is untypable at max level; tests are covered by Pest itself |
| Hook bodies are POSIX `sh` | Husky runs hooks with `sh -e`, which is `dash` on Ubuntu/WSL; verify with `sh .husky/<hook>` |

## Running things

Everything runs on the host. `make help` lists every target.

**make**: install | test | test-coverage | test-accuracy | lint | analyse | archive-check

`test`, `test-coverage`, `test-accuracy` and `analyse` call composer scripts; `test-coverage` enforces 90% on `src/`; `archive-check` runs `scripts/archive-check.sh`.

## Host prerequisites

| Tool | Why |
|---|---|
| PHP 8.3+ with `curl`, `mbstring`, `pdo_sqlite`, `zip` | Composer, Pest (Testbench uses in-memory SQLite), Pint, Larastan |
| `pcov` or `xdebug` (optional) | Local coverage only; CI reports coverage |
| Composer 2 | Dependencies |
| Node + npm | Husky hooks (`npm install` once after cloning) |
| `osv-scanner` | `pre-commit` security audit (`go install github.com/google/osv-scanner/cmd/osv-scanner@latest`) |
