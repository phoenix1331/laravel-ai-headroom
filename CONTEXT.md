# Context

**Headroom** (`phoenix1331/laravel-ai-headroom`) is a Laravel package that counts the tokens a Laravel AI SDK agent request will use before it is sent, checks it against the model's context window, estimates cost, and can block requests that breach configured limits.

This is a Composer **package**, not a Laravel app. There is no `app/`, no routes and no database server; it is tested inside Orchestra Testbench.

## Architecture at a glance

| Path | Role |
|---|---|
| `src/AiHeadroomServiceProvider.php` | Merges and publishes `config/ai-headroom.php` (tag `ai-headroom-config`) |
| `src/Facades/Headroom.php`, `src/HeadroomManager.php` | Public entry point (alias `Headroom`). Built: `for()` (fires `TokensCounted`), `count()` (no events), `recordUsage()` (fires `TokensUsed`, waits for streams), `resolveContextUsing()`, `defaultModel()`, `toTokenCount()`. Planned: `fake()` |
| `src/Resolving/RequestResolver.php` | `resolve($agent, $prompt, $attachments, provider:, model:, messages:, tools:)` -> the first `PendingStep` the SDK would build, without calling the model |
| `tests/Fixtures/Agents/` | Parity fixtures (instructions only, conversation with tool calls/replay blocks, tools incl. provider tools and a sub-agent, structured output with attributes, provider/model attributes and methods, cheapest/smartest, failover); `CapturesSteps` records the SDK's real `PendingStep` |
| `src/Counting/` | `Contracts\Counter` (`PendingStep` -> `Tally`), `Contracts\Tokeniser`, `EstimatingCounter`, `TiktokenTokeniser` (o200k), `NativeCounter` base (provider lookup, 15 s timeout, breakdown scaling), `AnthropicCounter`, `GeminiCounter`, `OpenAiCounter`, `CounterResolver` (bound to `Counter`), `NativeCountCache`, `StepFingerprint`, `ToolDefinition` (shared tool shape), `EscalatingCounter` (auto mode) |
| `src/Counting/Gateways/` | SDK gateway subclasses exposing the protected request body and client for counting endpoints (`AnthropicCountingGateway`, `GeminiCountingGateway`, `OpenAiCountingGateway`) |
| `src/Calibration/` | Learns a per-model correction factor from actual usage, fed by the `EnsureHeadroom` middleware |
| `src/Models/ModelRegistry.php` | Model specs: shipped `resources/models.php` merged with `config('ai-headroom.models')` overrides; cautious fallback for unknown models |
| `resources/models.php` | Shipped context windows, max output and per-million prices (with long-prompt tiers) plus `last_checked`; not published |
| `src/Data/`, `src/Enums/` | `TokenCount` (with `requestId`, provider, agent, invocationId, context), `Tally` (counter output: figures only), `Limits`, `Cost` (with `approximate`), `ModelSpec`, `CountMethod`, `CountingMode`, `Currency`, `LimitType` |
| `src/Events/` | Public API (SemVer): `TokensCounted`, `TokensUsed`, `HeadroomLow`, `RequestBlocked`, `RequestFailed`; final readonly, typed, no text. Reference: `docs/events.md` |
| `src/Exceptions/TokenLimitExceeded.php` | Carries `TokenCount`, `LimitType`, limit and actual values; message is figures only |
| `src/Middleware/EnsureHeadroom.php` | Agent step middleware: counts each `PendingStep` with its limits, fires `TokensCounted`, then `RequestBlocked` + `TokenLimitExceeded` (throw) or `HeadroomLow` per breach (report), tracks the step, and fires `TokensUsed` from `StepResult::then()` |
| `src/Tracking/RequestTracker.php`, `src/Listeners/ReportFailedRequests.php` | In-process map of in-flight steps (invocationId + step number -> `TokenCount`, capped at 1,000); listener turns the SDK's `StepFailed` into `RequestFailed` |
| `src/Context/ContextResolver.php` | `Headroom::resolveContextUsing()` callback; scalar tags only, logs and continues on failure |
| `src/Currency/` | `Contracts\ExchangeRates` and `ConfiguredExchangeRates` (fixed rates per 1 USD from config) |
| `src/Middleware/`, `src/Exceptions/`, `src/Events/` | `EnsureHeadroom` guard, `TokenLimitExceeded`, `TokensCounted`, `HeadroomLow` |
| `src/Console/` | `headroom:inspect`, `headroom:models`, `headroom:calibration` |
| `config/ai-headroom.php` | Package config |
| `docs/` | GitHub Pages site and `events.md` event reference (export-ignored; the README links to it) |

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
| `yethee/tiktoken` | `^1.1` | Local o200k tokeniser; downloads ~3.6 MB vocab on first use into `tokeniser.cache_path` |
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
| `RequestResolver` mirrors `Promptable::prompt()` (provider/model resolution, prompt input, declared tools), `GeneratesText::prompt()` (history, `resolveTool()`, schema) and the loop's first step (`settleAbandonedToolCalls()`, `withoutForeignReplayBlocks()`, `toolsSupportedBy()`), all from public data | Faking the agent to capture the step would mutate global state and write conversations |
| `RequestResolverParityTest` runs each fixture through the real SDK (agent faked, capture middleware) and requires equal `StepFingerprint`, provider, model, message/tool classes and options; mutation-checked (skipping settle, tool filtering or `#[UseCheapestModel]` each fail it) | Drift in laravel/ai shows up as a failing test, including in the weekly `sdk-latest` run |
| `Headroom::for()` prices output at the agent's `maxTokens` (`#[MaxTokens]` or `maxTokens()`), else the model maximum | Same worst-case rule as plain counts, using what the SDK would actually send |
| Events are public API for a future companion store/dashboard; Headroom stores nothing per request. Design and decisions: `.claude/tasks/BRIEF.md` "Events (public API)" | Keeps core lean (no migrations) |
| `TokenCount` carries requestId, provider, agent, invocationId and context tags | `recordUsage()` can build `TokensUsed` from the count alone, even in another process |
| `recordUsage()` reports the run's first step usage and meta; prices an unrecognised reported model (dated snapshot) as the counted model | Matches what `Headroom::for()` counted; avoids fallback pricing for snapshot names |
| Actual cost: uncached input + cache reads/writes at their own prices + output; missing cache price prices those tokens as input and sets `Cost::approximate` | Never silently wrong; Anthropic cache writes use the 5-minute rate (TTL not reported) |
| Middleware failures are reported via the SDK's public `StepFailed` event, not a try/catch in the middleware | Streamed steps fail while the caller iterates, after the middleware has returned; `StepFailed` covers normal and streamed failures and is not fired for middleware-blocked steps |
| Breaches checked in order context window, percent of context, cost; throw mode stops at the first, report mode fires `HeadroomLow` for each | Predictable `TokenLimitExceeded::$limit`; report mode shows everything that was over |
| Percent-of-context breaches use the unrounded percentage | `percentUsed()` is rounded to 2 places for display and would let 80.004% pass an 80% limit |
| Middleware limits/mode default from `ai-headroom.middleware` config; `->report()` returns a new instance | Agents declare `new EnsureHeadroom(...)` inline; services are resolved from the container at handle time |
| Non-scalar context tags are dropped with a warning rather than thrown | "Rejected" while honouring "tagging never fails a request" |
| Event reference lives in `docs/events.md` until the README task | RULES.md keeps the README for the final task |
| Failover lists resolve to their first provider; approval-decision input is rejected; `withMessages()`/`withTools()` state must be passed as `messages:`/`tools:` | The SDK keeps that state private and flushes it on read; decisions resume a run rather than start one |
| Native counters subclass the SDK gateways to reuse their protected request-body builders | Exact provider payloads without reimplementing serialisation; protected methods are outside semver, so the weekly `sdk-latest` CI job watches them |
| Gemini native counting maps to `generateContentRequest` itself | The SDK's Gemini gateway uses the Interactions API, whose body `countTokens` does not accept |
| Tokeniser: `yethee/tiktoken` with a persistent vocab cache path | MIT, maintained, o200k support, ~13 ms per 100 KB; downloads vocab files on first use |
| `TokenCount::total()` is stored separately from the breakdown, not summed | Native endpoints return one total including message/tool overhead that belongs to no single part |
| `TokenCount::remaining()` added beyond the brief's API | It is literally the headroom; negative when the request overflows |
| `Cost` rounds amounts to 8 decimal places | Removes float noise (`0.15 + 0.96`) while keeping per-token precision on cheap models |
| Native counts: total from the provider, breakdown = local estimate's split scaled to that total (floored), confidence 0.0 | Counting endpoints return one number; a second call per part would multiply cost and rate-limit use |
| Anthropic counting: SDK `buildTextRequestBody()` filtered to `model, messages, system, tools, tool_choice, thinking, output_config, cache_control`, posted with the SDK client to `messages/count_tokens`, 15 s timeout | Same serialisation, key, base URL, version and beta headers as the real call |
| Native totals cached whole-request: key `headroom:native-count:` + sha256(counter class + `StepFingerprint`), value = int count only; **opt-in** (`HEADROOM_CACHE`, default off), TTL via `HEADROOM_CACHE_TTL` (default 86400 s), store via `HEADROOM_CACHE_STORE` | The brief's "cache instructions/tools separately" is not workable: endpoints return one total, per-part counts would cost extra calls and are not additive. Whole-request caching covers retries, re-counts and inspect-after-run. Off by default because each new chat turn changes the history, so typical conversation traffic rarely hits |
| `StepFingerprint` covers provider, model, instructions, messages (content, attachments via their `toArray()`, tool calls, tool results, replay blocks), tool definitions, schema, tool choice and provider options; ignores step number, timeout, maxTokens, temperature | Exactly the inputs that change an input token count. Local files add size and mtime so in-place edits miss the cache; stored-disk files are keyed by path only |
| `Counter::count(PendingStep, ?Limits)`: limits are optional on the contract | Escalation must know the limits it decides against (middleware limits vary per agent); other counters ignore them |
| `EscalatingCounter` (auto): estimate; checks the context window plus any `maxPercentOfContext`/`maxCost`; a limit clearly exceeded (> ceiling x (1 + band)) means no native call; otherwise any value within the band either side means a native count; else the estimate stands. Default band 0.15 | Clear passes and clear fails never pay for a network call; only decisions that could flip do |
| Auto mode wraps the driver's native counter in `EscalatingCounter` only when the driver has a counter and the provider has a key; otherwise plain estimate | Escalation is pointless without a way to go native |
| `CounterResolver` picks per step by the provider's laravel/ai **driver** via config `counters` (driver => Counter class) | Custom provider names on a supported driver still count natively; apps can register counters for other drivers |
| Mode rules: `estimate` never resolves the provider; `auto` escalates as above, and estimates outright when the driver has no counter, the provider is unknown, or its key is blank; `native` throws on an unknown provider and still calls the endpoint with a blank key | laravel/ai's default config defines every provider with null keys, so auto must not treat "defined" as "usable"; native should surface misconfiguration |
| Base `TestCase` calls `Http::preventStrayRequests()`; only `tests/Accuracy/` re-allows them | A configured key once let a test make a real Anthropic call; the guard makes "no real API calls" structural |
| OpenAI counting: SDK Responses `buildTextRequestBody()` filtered to `model, input, instructions, tools, tool_choice, parallel_tool_calls, text, reasoning, truncation, conversation, previous_response_id, personality`, posted to `responses/input_tokens` | Same body as the real call minus `max_output_tokens`, `temperature`, `top_p`, `store`; OpenAI documents the count as exact |
| Gemini counting builds its own `generateContentRequest` (`contents` with `user`/`model` roles, `systemInstruction`, `functionDeclarations` with `parametersJsonSchema`, `generationConfig.responseJsonSchema`) and reuses only the SDK's `mapAttachment()`, client and error handling | The SDK uses the Interactions API (`user_input`/`model_output` turns), which `countTokens` does not accept |
| Gemini provider tools (search, code execution) and assistant replay blocks are not sent for counting | No function-declaration equivalent in `countTokens`; small undercount, noted in the gateway |
| Counters take a `Laravel\Ai\PendingStep` and return a `Tally`; `HeadroomManager::toTokenCount()` adds window and cost | One input shape for plain text, `Headroom::for()` and the middleware; counters stay free of pricing |
| `Headroom::count($text)` counts the text as a single user message, so totals include message overhead | Same path as agent counting; matches what a provider would bill for that prompt |
| Estimates: o200k tokens x per-provider `estimate_ratios` (openai 1.0, anthropic 1.6, gemini 1.0, default 1.6) x (1 + safety margin) for non-OpenAI | Starting points from providers' chars-per-token guidance; to be measured by the accuracy suite and corrected by calibration |
| Breakdown split: last message, if a `UserMessage`, is `prompt`; earlier messages, tool calls and tool results are `history`; tool definitions plus structured output schema are `tools` | Matches the five-part public breakdown |
| Message overhead (3 per message + 3 per request) goes into the total only | It belongs to no single part |
| Attachments use fixed per-kind `attachment_tokens`, unscaled; any attachment raises confidence to 0.5 | Providers count media by their own rules; flagged as approximate |
| Tests use `tests/Fixtures/WordTokeniser` (one token per word); only `TiktokenTokeniserTest` uses the real vocab, cached in `build/tiktoken` locally and via `actions/cache` in CI | Deterministic expectations without re-downloading the vocab |
| Shipped models live in `resources/models.php`, not the published config; config `models` only overrides or adds | A published config would freeze prices at publish time, so users would miss every price update |
| Overrides replace fields whole per model (`array_replace`), not recursively | Recursive merge would mix a user's shorter `tiers` list with the shipped one by index |
| Price tiers (`above` threshold) apply to the whole request, input and output | Matches Claude Haiku 5.5 (over 100k) and OpenAI (over 272k) pricing |
| Costs reportable in USD or GBP (`currency` config, `Currency` enum); prices always stored in USD and converted at the end by `ModelRegistry::costFor()` | Providers bill in USD; one conversion point keeps `max_cost` and reports in the user's currency. New currencies = enum case + rate |
| Exchange rates are fixed config values (`exchange_rates`, units per 1 USD, env-overridable) behind an `ExchangeRates` contract | No network dependency or API key; a live-rate implementation can be bound to the contract later |
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
