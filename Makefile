.DEFAULT_GOAL := help

.PHONY: help install test test-coverage test-accuracy lint analyse archive-check

help: ## List available targets
	@grep -E '^[a-zA-Z_-]+:.*?## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-15s\033[0m %s\n", $$1, $$2}'

install: ## Install composer and npm dependencies (npm activates the git hooks)
	@composer install
	@npm install

test: ## Run the test suite (no real API calls)
	@composer test

test-coverage: ## Run the test suite with coverage (needs pcov or xdebug)
	@composer test:coverage

test-accuracy: ## Run the accuracy suite against real provider APIs (needs API keys)
	@composer test:accuracy

lint: ## Fix code style with Pint
	@./vendor/bin/pint

analyse: ## Run Larastan static analysis
	@composer analyse

archive-check: ## Fail if the package archive ships unexpected files
	@sh scripts/archive-check.sh
