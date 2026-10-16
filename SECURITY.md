# Security Policy

## Supported Versions

| Version | Supported |
|---------|-----------|
| 0.x     | Latest minor only |

While Headroom is pre-1.0, fixes are released on the latest minor version only.

## Reporting a Vulnerability

Please do not open a public GitHub issue for security vulnerabilities.

Report security issues by emailing **darren@phoenix1331.co.uk**. Include:

- A description of the vulnerability and its potential impact
- Steps to reproduce or a proof-of-concept
- The package version affected, plus your `laravel/ai` version and provider

You will receive an acknowledgement within 48 hours. If the issue is confirmed, a fix will be released as soon as possible and you will be credited in the changelog unless you prefer to remain anonymous.

## Scope

Headroom counts the tokens in a Laravel AI SDK request before it is sent. Depending on the counting method, that request content may leave your application, so it is worth knowing exactly what goes where.

| Method (`method` config) | Prompt content sent to a third party? | Network access |
|---|---|---|
| `estimate` | No. Counting happens locally with a tokeniser and calibrated approximations | One-off download of tokeniser vocabulary files (see below) |
| `native` | **Yes.** The full request (instructions, conversation history, tool schemas, attachments and prompt) is sent to the provider's token counting endpoint | Every uncached count |
| `auto` (default) | **Sometimes.** Estimates first, and sends the request for a native count only when the estimate lands close to a limit | Borderline counts only |

### Potential concerns

**Native counting sends your prompt to the provider** - When a native count is made, Headroom sends the same content your agent is about to send to the same provider (Anthropic `/v1/messages/count_tokens`, OpenAI `/v1/responses/input_tokens`, Gemini `models/{model}:countTokens`), using the API keys already configured for `laravel/ai`. It never sends content to a provider other than the one the agent uses. If your data handling rules forbid any extra transmission, set `method` to `estimate`.

**Tokeniser vocabulary download** - The OpenAI tokeniser (`yethee/tiktoken`) downloads its vocabulary files from `openaipublic.blob.core.windows.net` on first use and caches them locally. No prompt content is sent. Offline or locked-down hosts need the cache warmed before use; `estimate` mode is not network-free until it is.

**Cached counts** - Native counts are cached by a hash of the counted content, model and counter. The cache stores the hash and the count, never the content itself.

**Calibration data** - The calibration store holds per-model numbers only: sample counts, correction factors and measured error. It does not store prompts, responses or user identifiers.

**Events and exceptions** - `TokensCounted`, `HeadroomLow` and `TokenLimitExceeded` carry a `TokenCount`, which holds token totals, the per-part breakdown, model and cost figures. They do not carry prompt content, so logging them does not leak conversations.

**Dependency chain** - Headroom depends on `laravel/ai` and `yethee/tiktoken`. Keep your `composer.lock` up to date and use a tool like `osv-scanner` or `composer audit` to monitor for vulnerabilities in the dependency chain.

**Estimates are not guarantees** - An estimated count can be lower than the real one, especially for images, files, code, JSON and non-English text. A request that Headroom allows can still exceed a limit at the provider. Use `native` mode or a safety margin where an overrun is costly.

## Disclosure Policy

Once a fix is released, the vulnerability will be documented in `CHANGELOG.md` under the relevant version. We follow coordinated disclosure and will not publish details until a fix is available.
