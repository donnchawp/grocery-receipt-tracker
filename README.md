# Grocery Receipt Tracker

A WordPress plugin that tracks grocery prices by scanning receipts. Captures receipt data via camera OCR, LLM-assisted parsing, or CSV paste, then stores items and prices for tracking over time.

## Features

- **Receipt scanning** — capture receipts with your phone camera, parsed via Tesseract OCR
- **LLM parsing** — optional local LLM (Ollama) for improved receipt text extraction
- **CSV paste import** — paste receipt data from ChatGPT/Claude, bypassing automated parsing
- **Price tracking** — view product price history with charts
- **Product search** — search across all tracked products
- **PWA support** — installable as a standalone app on mobile

## Requirements

- WordPress 6.x
- PHP 8.1+
- Node.js 18+
- Docker (for wp-env development environment)
- Tesseract OCR (installed automatically in wp-env)

## Setup

```bash
make install        # Install npm dependencies
make env-start      # Start WordPress dev environment (includes Tesseract)
make dev            # Start React dev server with hot reload
```

The plugin renders on a WordPress page with the slug `grocery-tracker` using the `[grocery_tracker]` shortcode.

## Development

```bash
make help           # Show all available commands
make build          # Build React frontend for production
make dev            # Start dev server with hot reload
make lint           # Lint JS and CSS
make test           # Run JS unit tests
make env-logs       # Tail WordPress debug.log
make env-cli CMD="option list"  # Run WP-CLI commands
```

## Optional: LLM Parsing

For improved receipt parsing with a local LLM:

```bash
make ollama-setup   # Pull default models (requires: brew install ollama)
ollama serve        # Start the Ollama server
```

Then enable in WP Admin > Settings > Receipt Tracker LLM.

## CSV Import

The most reliable way to import receipts is to photograph the receipt, send the image to a paid-tier AI model (Claude or ChatGPT), and paste the resulting CSV into the plugin. Local LLMs and free-tier Gemini models tend to produce garbled store names, missing items, and incorrect prices — paid models like Claude Sonnet or GPT-4o are significantly more accurate.

Use this prompt with your receipt image:

```
Parse this grocery receipt image into CSV with exactly this format:

store,date,voucher_discount
<store name>,<YYYY-MM-DD>,<voucher discount or 0>
name,quantity,price,discount
<item name>,<quantity>,<price>,<discount or 0>

Rules:
- One row per line item on the receipt
- price = the original price before any discount
- discount = the amount subtracted for that item (0 if none)
- voucher_discount = any whole-receipt voucher/coupon amount (0 if none)
- Use the exact item names as printed on the receipt
- Date format must be YYYY-MM-DD
- No currency symbols, just numbers
- No quotes around fields
- Omit subtotals, totals, tax lines, and payment method lines

Return only the CSV, no explanation.
```

Then click "Paste CSV" on the dashboard and paste the output.

## Architecture

- **Frontend**: React app (`src/`) built with `@wordpress/scripts`
- **Backend**: WordPress REST API (`includes/class-rest-api.php`)
- **Data**: Custom database tables created on plugin activation
- **OCR**: Tesseract via shell exec, with optional Ollama LLM fallback
