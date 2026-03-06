.PHONY: help build dev lint test clean install env-start env-stop env-destroy env-logs env-cli env-shell ollama-setup

help: ## Show this help
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | sort | awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-15s\033[0m %s\n", $$1, $$2}'

## --- Dependencies ---

install: ## Install npm dependencies
	npm install

## --- Build ---

build: ## Build React frontend for production
	npm run build
	cp src/service-worker.js assets/service-worker.js

dev: ## Start React dev server with hot reload
	npm run start

clean: ## Remove build artifacts
	rm -rf build/ node_modules/

## --- Quality ---

lint: ## Lint JS and CSS
	npm run lint:js
	npm run lint:css

test: ## Run JS unit tests
	npm run test

## --- WordPress Environment (wp-env) ---

env-start: ## Start WordPress dev environment (installs Tesseract)
	npx wp-env start

env-stop: ## Stop WordPress dev environment
	npx wp-env stop

env-destroy: ## Destroy WordPress dev environment (removes data)
	npx wp-env destroy

env-logs: ## Tail WordPress debug.log
	npx wp-env logs

env-cli: ## Open WP-CLI shell (usage: make env-cli CMD="plugin list")
	npx wp-env run cli wp $(CMD)

env-shell: ## Open bash shell in WordPress container
	npx wp-env run wordpress bash

## --- Ollama ---

ollama-setup: ## Pull the default LLM and vision models (requires: brew install ollama)
	@command -v ollama >/dev/null 2>&1 || { echo "Ollama not found. Install with: brew install ollama"; exit 1; }
	ollama pull qwen2.5:3b
	ollama pull gemma3:4b
	@echo ""
	@echo "Done. Start Ollama with: ollama serve"
	@echo "Then enable the LLM parser in WP Admin → Settings → Receipt Tracker LLM"
