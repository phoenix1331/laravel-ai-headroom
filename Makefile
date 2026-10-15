.DEFAULT_GOAL := help

export UID := $(shell id -u)
export GID := $(shell id -g)

EXEC = docker compose exec app

.PHONY: help up down build shell install test test-accuracy lint analyse archive-check logs

help: ## List available targets
	@grep -E '^[a-zA-Z_-]+:.*?## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-15s\033[0m %s\n", $$1, $$2}'

up: ## Start the container
	@docker compose up -d

down: ## Stop the container
	@docker compose down

build: ## Build the container image
	@docker compose build

shell: ## Open a shell in the app container
	@$(EXEC) sh

install: ## Install composer dependencies
	@$(EXEC) composer install

test: ## Run the test suite (no real API calls)
	@$(EXEC) composer test

test-accuracy: ## Run the accuracy suite against real provider APIs (needs API keys)
	@$(EXEC) composer test:accuracy

lint: ## Fix code style with Pint
	@$(EXEC) ./vendor/bin/pint

analyse: ## Run Larastan static analysis
	@$(EXEC) composer analyse

archive-check: ## Fail if the package archive ships unexpected files
	@$(EXEC) sh scripts/archive-check.sh

logs: ## Tail app container logs
	@docker compose logs -f app
