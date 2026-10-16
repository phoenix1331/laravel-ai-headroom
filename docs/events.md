# Headroom events

Headroom stores nothing about individual requests. Instead it fires events, so your app, or a companion package with a data store and dashboard, can record every request. These events and their properties are **public API covered by Semantic Versioning**: removing or renaming a property is a breaking change.

All events are `final readonly` classes in `Phoenix1331\AiHeadroom\Events` with typed public properties. **No event ever contains prompt, instruction, tool or response text, or a provider error message.** This is enforced by tests that serialise every event.

## Linking events

| ID | Meaning |
|---|---|
| `requestId` | A ULID for one counted provider call. `TokensCounted` and the events that follow it (`TokensUsed`, `HeadroomLow`, `RequestBlocked`, `RequestFailed`) share it. Also on `TokenCount::requestId()` |
| `invocationId` | laravel/ai's ID for an agent run. A run with tool calls makes several provider calls (steps), each with its own `requestId` and the same `invocationId`. Null when Headroom cannot know it yet, such as `TokensCounted` from `Headroom::for()` |

A `TokensCounted` with no later `TokensUsed`, `RequestFailed` or `RequestBlocked` is a stream the client abandoned before it completed. Providers report usage only when a stream finishes.

With the middleware, each failover attempt is counted separately: a failed attempt has `TokensCounted` then `RequestFailed`, and the provider that answered has its own `TokensCounted` then `TokensUsed`.

## Events

### `TokensCounted`

Fired before an agent request is sent: by `Headroom::for()` and by the `EnsureHeadroom` middleware. Not fired by `Headroom::count($text)`, since plain text is not a request.

| Property | Type | Description |
|---|---|---|
| `requestId` | `string` | ULID of this request |
| `invocationId` | `?string` | Agent run ID, when known |
| `agent` | `?class-string` | The agent class |
| `provider` | `?string` | laravel/ai provider name the request was counted for |
| `model` | `string` | Model counted for |
| `countMethod` | `CountMethod` | `Native`, `Estimated` or `Calibrated` |
| `estimatedInputTokens` | `int` | Input tokens counted |
| `breakdown` | `array{instructions: int, history: int, tools: int, attachments: int, prompt: int}` | Tokens per part of the request |
| `contextWindow` | `int` | The model's context window |
| `percentUsed` | `float` | Share of the window used, to 2 decimal places |
| `estimatedCost` | `Cost` | Input cost plus worst-case output cost, in the configured currency |
| `context` | `array<string, scalar>` | Tags from `Headroom::resolveContextUsing()` |
| `occurredAt` | `CarbonImmutable` | When it was counted |

### `TokensUsed`

Fired after the provider responds: by the `EnsureHeadroom` middleware for every step, including once a stream completes, or by `Headroom::recordUsage($count, $response)` when you counted with `Headroom::for()`.

| Property | Type | Description |
|---|---|---|
| `requestId` | `string` | Same ULID as the matching `TokensCounted` |
| `invocationId` | `?string` | Agent run ID |
| `agent` | `?class-string` | The agent class |
| `provider` | `?string` | Provider that actually answered; can differ from the counted one after failover |
| `model` | `string` | Model that actually answered, as the provider reports it |
| `countedModel` | `string` | Model the request was counted for; differs from `model` after failover, or when the provider reports a dated snapshot |
| `inputTokens` | `int` | Actual input tokens, including cache reads and writes |
| `outputTokens` | `int` | Actual output tokens, including reasoning |
| `cacheReadTokens` | `?int` | Input tokens read from a prompt cache, when reported |
| `cacheWriteTokens` | `?int` | Input tokens written to a prompt cache, when reported |
| `cost` | `Cost` | Actual cost in the configured currency. `cost->approximate` is `true` when cache tokens were reported but the registry has no matching cache price |
| `streamed` | `bool` | Whether the response was streamed |
| `countMethod` | `CountMethod` | How the request was counted beforehand |
| `estimatedInputTokens` | `int` | What Headroom counted beforehand, for measuring estimate error |
| `context` | `array<string, scalar>` | Same tags as the matching `TokensCounted` |
| `occurredAt` | `CarbonImmutable` | When usage was reported |

`Headroom::recordUsage()` reports the first provider call of a run, which is the one `Headroom::for()` counted. Use the middleware to report every step of a multi-step run.

### `HeadroomLow`

Fired by the `EnsureHeadroom` middleware in `report` mode, once per limit breached, when a request goes over a limit and is allowed through. Limits are checked in the order context window, percent of context, cost.

| Property | Type | Description |
|---|---|---|
| `requestId` | `string` | ULID of the request |
| `invocationId` | `?string` | Agent run ID |
| `limit` | `LimitType` | `ContextWindow`, `PercentOfContext` or `Cost` |
| `limitValue` | `float` | The limit |
| `actualValue` | `float` | The counted value |
| `reason` | `string` | Generated from the figures, e.g. `85.2% of context used, limit 80%` |
| `occurredAt` | `CarbonImmutable` | When it was raised |

### `RequestBlocked`

Fired by the `EnsureHeadroom` middleware in `throw` mode just before `TokenLimitExceeded` is thrown, so blocks are recorded even when your app catches the exception. Only the first breached limit is reported (context window, then percent of context, then cost).

| Property | Type | Description |
|---|---|---|
| `requestId` | `string` | ULID of the request |
| `invocationId` | `?string` | Agent run ID |
| `limit` | `LimitType` | The limit breached |
| `limitValue` | `float` | The limit |
| `actualValue` | `float` | The counted value |
| `count` | `TokenCount` | The full count: figures, identifiers and context tags, no content |
| `occurredAt` | `CarbonImmutable` | When it was blocked |

### `RequestFailed`

Fired when a provider call counted by the `EnsureHeadroom` middleware throws, for normal and streamed calls alike (Headroom listens to laravel/ai's `StepFailed` event, since a stream fails while it is read, after the middleware has returned). Not fired for requests the middleware blocked: those fire `RequestBlocked`.

| Property | Type | Description |
|---|---|---|
| `requestId` | `string` | ULID of the request |
| `invocationId` | `?string` | Agent run ID |
| `provider` | `?string` | Provider that was called |
| `model` | `string` | Model that was called |
| `exception` | `class-string<Throwable>` | The exception class |
| `httpStatus` | `?int` | HTTP status from the exception or anything it wraps, when there is one |
| `occurredAt` | `CarbonImmutable` | When it failed |

The exception message is deliberately left out: provider errors can echo prompt content.

## Context tags

```php
use Laravel\Ai\Contracts\Agent;
use Phoenix1331\AiHeadroom\Facades\Headroom;

// In a service provider's boot() method
Headroom::resolveContextUsing(fn (?Agent $agent): array => [
    'tenant_id' => tenant()?->id,
    'user_id' => auth()->id(),
]);
```

- Called once per counted request with the agent (or `null`); the tags are copied onto `TokensCounted`, `TokensUsed` and the `TokenCount`
- Values must be scalars keyed by name. Anything else (arrays, objects, `null`) is dropped and a warning is logged
- If the callback throws, the request carries no tags and a warning naming only the exception class is logged. Tagging never fails a request

## `Cost`

| Property | Type | Description |
|---|---|---|
| `input` | `float` | Input cost |
| `output` | `float` | Output cost: worst case on `TokensCounted`, actual on `TokensUsed` |
| `currency` | `Currency` | `Usd` or `Gbp` |
| `approximate` | `bool` | Part of the cost could not be priced exactly |

`total()` returns input plus output. Amounts are rounded to 8 decimal places.

Anthropic cache writes are priced at the 5-minute rate. Providers do not report which cache TTL was written, so writes to the 1-hour cache are understated.
