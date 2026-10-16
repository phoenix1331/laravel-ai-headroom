# Releasing

Headroom follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html). Before 1.0, breaking changes bump the minor version (`0.1.x` to `0.2.0`) and are called out in the changelog.

## Steps

1. Make sure `main` is green: tests, static analysis and the most recent `sdk latest` run.
2. In `CHANGELOG.md`, rename `## [Unreleased]` to `## [X.Y.Z] - YYYY-MM-DD` and add a fresh empty `## [Unreleased]` above it.
3. If the release contains a breaking change, add an entry to `UPGRADING.md` (create it on the first breaking change).
4. If model prices or context windows changed, confirm the "last checked" date in `config/ai-headroom.php` is current.
5. Commit: `chore: release vX.Y.Z`.
6. Tag and push:

   ```bash
   git tag vX.Y.Z
   git push origin main vX.Y.Z
   ```

7. The `release` workflow creates the GitHub release using the matching `CHANGELOG.md` section as its notes. It fails if that section is missing.
8. Packagist picks up the tag through the GitHub webhook. Check the new version appears at <https://packagist.org/packages/phoenix1331/laravel-ai-headroom>.

## Before tagging 1.0.0

- The public API (`Headroom` facade, `TokenCount`, `EnsureHeadroom`, events, config keys) is documented and has not changed for at least one minor release.
- The supported `laravel/ai` range in the README matches `composer.json`.
