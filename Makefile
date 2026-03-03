.PHONY: help build dev lint test clean install

help: ## Show this help
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | sort | awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-15s\033[0m %s\n", $$1, $$2}'

build: ## Build React frontend for production
	npm run build

dev: ## Start React dev server with hot reload
	npm run start

lint: ## Lint JS and CSS
	npm run lint:js
	npm run lint:css

test: ## Run JS unit tests
	npm run test

clean: ## Remove build artifacts
	rm -rf build/ node_modules/

install: ## Install dependencies
	npm install
