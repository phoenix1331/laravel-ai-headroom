# Headroom

**Know how much room your AI request has left, before you send it.**

Token counting, context limits and cost guards for the [Laravel AI SDK](https://github.com/laravel/ai). Headroom counts the whole request an agent is about to send, checks it against the model's context window and your cost limits, and can stop it before it goes.

[![Tests](https://github.com/phoenix1331/laravel-ai-headroom/actions/workflows/tests.yml/badge.svg)](https://github.com/phoenix1331/laravel-ai-headroom/actions/workflows/tests.yml)
[![Static analysis](https://github.com/phoenix1331/laravel-ai-headroom/actions/workflows/static-analysis.yml/badge.svg)](https://github.com/phoenix1331/laravel-ai-headroom/actions/workflows/static-analysis.yml)
[![Packagist](https://img.shields.io/packagist/v/phoenix1331/laravel-ai-headroom.svg)](https://packagist.org/packages/phoenix1331/laravel-ai-headroom)
[![Licence](https://img.shields.io/packagist/l/phoenix1331/laravel-ai-headroom.svg)](LICENSE)

```php
$count = Headroom::for(new SupportAgent($user), 'Where is my refund?');

$count->total();        // 171297
$count->breakdown();    // ['instructions' => 1812, 'history' => 162240, 'tools' => 3604, 'attachments' => 0, 'prompt' => 14]
$count->percentUsed();  // 17.13
$count->fits();         // true
```

Landing page: [phoenix1331.github.io/laravel-ai-headroom](https://phoenix1331.github.io/laravel-ai-headroom)

## What this demonstrates

A chat feature works in testing. Weeks later a long conversation, plus a dozen retrieved documents, quietly goes past the context window: the provider rejects it, or the oldest history is dropped and the agent forgets what was agreed. The same growth shows up on the bill, often from a handful of tenants. Token counting was proposed for `laravel/ai` core ([#704](https://github.com/laravel/ai/issues/704)) and declined, so this is the community answer.

Existing PHP tokenisers count strings you assemble yourself, usually with OpenAI's encoding only. The closest package, [`frolaxhq/laravel-llm-tokenkit`](https://packagist.org/packages/frolaxhq/laravel-llm-tokenkit), estimates text and message arrays with a characters-per-token heuristic. Headroom differs in four ways:

1. **Exact counts from each provider's own counting endpoint** where one exists (Anthropic, OpenAI, Gemini), using the request body the SDK itself would send
2. **Counts the whole agent request**, not strings: instructions, conversation history, tool schemas, structured output schema, attachments and prompt, resolved exactly as `laravel/ai` would and checked against the real SDK in tests
3. **Self-calibrating estimates** that learn a correction per model from your app's real usage, so "how accurate is it?" becomes a measured number
4. **Middleware that stops a request before it is sent**, on every step of an agent run, with events you can record without Headroom storing anything

The interesting engineering is in how little it has to guess. laravel/ai v1.2 hands agent middleware the fully resolved request (`PendingStep`), so the guard sees exactly what is about to be sent. Native counters reuse the SDK's own request serialisation by subclassing its gateways, and a parity test runs fixture agents through the real SDK to prove the resolver builds the same request.

## Tech stack

| | |
|---|---|
| PHP | 8.3+ |
| Laravel | 12 or 13 |
| laravel/ai | `^1.2` (tested against v1.2.3; a weekly CI job runs against the newest release) |
| Tokeniser | [`yethee/tiktoken`](https://github.com/yethee/tiktoken-php) (OpenAI o200k) |
| Tests | Pest with Orchestra Testbench, Larastan at max level, Pint |

## Getting started

### Install

```bash
composer require phoenix1331/laravel-ai-headroom
php artisan vendor:publish --tag=ai-headroom-config   # optional
```

Headroom uses the provider credentials you already configured for `laravel/ai`. No extra keys are needed.

### Prerequisites for contributors

- PHP 8.3+ with `mbstring`, `pdo_sqlite` and `zip`; Composer 2; Node and npm
- `osv-scanner` for the pre-commit security audit: `go install github.com/google/osv-scanner/cmd/osv-scanner@latest`
- Run `npm install` once after cloning to activate the Husky git hooks
- `pcov` or `xdebug` only if you want coverage locally (`sudo apt install php8.4-pcov`)
- This repo's `.mcp.json` configures MCP servers for Claude Code. GitHub MCP authenticates via `gh auth token`, so run `gh auth login` once per machine. MySQL MCP reads `MYSQL_HOST`, `MYSQL_PORT`, `MYSQL_USER`, `MYSQL_PASSWORD` and `MYSQL_DATABASE` from your shell (defaults `127.0.0.1:3306`, user `root`); Headroom itself needs no database. Playwright and Context7 need only `npx`

### Commands

1. `composer install`
2. `npm install`
3. `composer test` (unit and feature tests; never calls a real API)
4. `composer test:coverage` (fails below 90% on `src/`)
5. `composer analyse` (Larastan, max level)
6. `composer lint` / `composer lint:test` (Pint)
7. `composer test:accuracy` (real provider counts; needs API keys, see [Accuracy](#accuracy))

See [CONTRIBUTING.md](CONTRIBUTING.md) for adding a provider counter, updating model prices and adding a currency.

## Environment variables

| Key | Description | Default |
|---|---|---|
| `HEADROOM_DEFAULT_MODEL` | Model for `Headroom::count($text)` when none is given | laravel/ai's default text model |
| `HEADROOM_METHOD` | `auto` (estimate, then native only near a limit), `native` or `estimate` (never send prompt content anywhere) | `auto` |
| `HEADROOM_CALIBRATION` | Learn per-model corrections from real usage | `true` |
| `HEADROOM_CALIBRATION_STORE` | Cache store for calibration data | your default cache store |
| `HEADROOM_CACHE` | Reuse native counts for identical requests | `false` |
| `HEADROOM_CACHE_TTL` | Native count cache lifetime in seconds | `86400` |
| `HEADROOM_CACHE_STORE` | Cache store for native counts | your default cache store |
| `HEADROOM_CURRENCY` | Currency for costs and `maxCost`: `USD` or `GBP` | `USD` |
| `HEADROOM_RATE_GBP` | GBP per 1 USD for cost conversion | `0.756` (checked 2026-10-09) |

Everything else (safety margins, the escalation band, estimate ratios, attachment allowances, model overrides and middleware defaults) is in `config/ai-headroom.php`, with comments.

## Usage

### Count an agent request

```php
use Phoenix1331\AiHeadroom\Facades\Headroom;

$count = Headroom::for(new SupportAgent($user), 'Where is my refund?');

$count->total();          // int
$count->breakdown();      // ['instructions' => int, 'history' => int, 'tools' => int, 'attachments' => int, 'prompt' => int]
$count->method();         // CountMethod::Native, ::Estimated or ::Calibrated
$count->confidence();     // expected relative error: 0.0 for native, the measured error when calibrated
$count->model();          // resolved model
$count->contextWindow();  // int
$count->percentUsed();    // float
$count->remaining();      // tokens left; negative when it overflows
$count->fits();           // bool
$count->estimatedCost();  // Cost: input plus worst-case output, in your currency
$count->requestId();      // ULID linking this count to the usage reported later
$count->toArray();
```

`Headroom::for()` takes the same arguments as `$agent->prompt()` (prompt, attachments, `provider:`, `model:`). History added with `withMessages()` and tools set with `withTools()` are private to the agent, so pass them as `messages:` and `tools:`.

### Count plain text

```php
Headroom::count($text, model: 'claude-sonnet-5-5');
Headroom::count($text); // default model
```

### Guard an agent

```php
use Phoenix1331\AiHeadroom\Middleware\EnsureHeadroom;

public function middleware(): array
{
    return [new EnsureHeadroom(maxPercentOfContext: 80, maxCost: 0.20)];
}
```

Every step of the run is counted before it is sent and checked against the context window, then `maxPercentOfContext`, then `maxCost`. Over a limit, Headroom fires `RequestBlocked` and throws `TokenLimitExceeded`, which carries the full `TokenCount` so you can trim history, summarise or refuse. With `(new EnsureHeadroom(...))->report()` it fires `HeadroomLow` instead and lets the request through. Limits default to `ai-headroom.middleware` config.

### Record what requests actually used

The middleware reports `TokensUsed` after every step, including when a stream completes. If you count with `Headroom::for()` yourself, report usage afterwards:

```php
$count = Headroom::for($agent, $prompt);
$response = $agent->prompt($prompt);

Headroom::recordUsage($count, $response); // also accepts a streamable response
```

### Events

Headroom stores nothing about your requests. It fires `TokensCounted`, `TokensUsed`, `HeadroomLow`, `RequestBlocked` and `RequestFailed`, linked by a request ID and laravel/ai's invocation ID, so you can log, chart or ship them to a dashboard. **No event ever contains prompt, response or provider error text.** Tag requests with your own scalar context:

```php
Headroom::resolveContextUsing(fn (?Agent $agent): array => [
    'tenant_id' => tenant()?->id,
]);
```

Events and their properties are public API covered by Semantic Versioning: see the [events reference](docs/events.md).

### Artisan

```bash
php artisan headroom:inspect "App\Ai\Agents\SupportAgent" "Where is my refund?"   # breakdown, context, cost; --json; exits 1 if it does not fit
php artisan headroom:models                # windows, prices, cache prices and tiers; --provider, --json
php artisan headroom:calibration           # samples, correction factor and measured error per model; --reset
```

### Testing your app

```php
Headroom::fake([SupportAgent::class => 900_000, 'claude-*' => 1_200]);

expect(fn () => (new SupportAgent($user))->prompt('Hello'))->toThrow(TokenLimitExceeded::class);

Headroom::assertCounted(SupportAgent::class);
```

The fake replaces only the counting, so your middleware, limits and event listeners run for real. Unmatched requests are estimated locally; nothing reaches a provider.

## Accuracy

| Method | What it can promise |
|---|---|
| Native | Exact for the request counted. In `auto` mode used only when an estimate is within 15% of a limit |
| Estimate, OpenAI models | Very close for text: same o200k tokeniser, small drift from message and tool overhead |
| Estimate, other providers | Rough until calibrated: o200k counts scaled by a per-provider ratio, plus a 10% safety margin |
| Calibrated estimate | Corrected per model from your real traffic; reports its own measured error as confidence |
| Images and files | Approximate: a fixed allowance per attachment, and the count's confidence drops to 0.5 |

Measured results per provider and content type (prose, code, JSON, French, Japanese, tool-heavy), before and after calibration, are published in [docs/accuracy.md](docs/accuracy.md) by `composer test:accuracy`:

```bash
ANTHROPIC_API_KEY=... GEMINI_API_KEY=... OPENAI_API_KEY=... composer test:accuracy
```

## Architecture notes

- **The guard is agent step middleware.** laravel/ai v1.2 passes middleware the fully resolved `PendingStep`, so `EnsureHeadroom` counts exactly what is about to be sent, including later steps of a tool loop whose history grows with tool results. No reflection into SDK internals.
- **`Headroom::for()` rebuilds that step from public agent APIs**, mirroring the SDK's own provider and model resolution, history, tool resolution and message clean-up. A parity test runs fixture agents through the real SDK and requires an identical request fingerprint; deliberately breaking the resolver fails it, so drift in a new SDK release shows up in the weekly CI run.
- **Native counters reuse the SDK's serialisation** by subclassing its Anthropic and OpenAI gateways and keeping only the fields the counting endpoints accept. Gemini is the exception: the SDK uses Gemini's Interactions API, which `countTokens` does not accept, so Headroom maps the request to `generateContentRequest` itself.
- **Escalation puts accuracy where decisions are made.** In `auto` mode a clear pass or a clear fail never waits for the network; only requests within the escalation band of a limit pay for a native count.
- **Calibration is a weighted running correction per model**, clamped per observation so one odd request cannot skew it, with a safety margin that follows the measured error down to a floor. It learns from `TokensUsed`, the same events a companion dashboard would record.
- **Prices ship in `resources/models.php`, not the published config**, so price updates reach you with each release while your overrides in config stay yours. `headroom:models` warns when shipped prices are over 60 days old.
- **Failures are reported from the SDK's own `StepFailed` event**, because a streamed step fails while it is being read, after the middleware has returned.

## Limitations

- `headroom:inspect` builds agents from the container, so agents that need constructor arguments (such as `new SupportAgent($user)`) must be bound in a service provider or counted in code with `Headroom::for()`
- A stream the client abandons never reports usage, so it has `TokensCounted` with no `TokensUsed`
- Gemini built-in tools (search, code execution) are not sent to `countTokens`, so requests using them count slightly low
- Attachments are estimated with fixed per-kind allowances; requests with attachments also add noise to calibration
- The native count cache (off by default) keys files on a storage disk by path, so replacing such a file in place keeps its old count until the cache expires
- Anthropic cache writes are priced at the 5-minute rate, because providers do not report which cache lifetime was written
- Agents with a provider failover list are counted by `Headroom::for()` against their first provider; the middleware counts each attempt as it happens

## Security

Native counting sends the request (instructions, history, tools, attachments and prompt) to the same provider the agent uses. Set `HEADROOM_METHOD=estimate` to keep prompt content in your app. See [SECURITY.md](SECURITY.md) for what each mode sends, what is cached and how to report a vulnerability.

## Links

- [Landing page](https://phoenix1331.github.io/laravel-ai-headroom)
- [Changelog](CHANGELOG.md)
- [Contributing](CONTRIBUTING.md)
- [Events reference](docs/events.md)
- [Packagist](https://packagist.org/packages/phoenix1331/laravel-ai-headroom)

## Licence

MIT. See [LICENSE](LICENSE).
