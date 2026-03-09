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

## Scanning Receipts with Claude Code

The fastest way to import a receipt is the `/scan-receipt` slash command in Claude Code. It uses Claude's vision to read the receipt image, extracts items, shows you a confirmation table, and submits directly to the plugin's API.

### Prerequisites

1. **wp-env running** — `make env-start`
2. **API key generated** — go to **WP Admin → Settings → Receipt Tracker** and click "Generate API Key". Copy the key.
3. **`env-config.txt` created** in the project root:

```
GRT_API_URL=http://localhost:8888
GRT_API_KEY=your_api_key_here
```

This file is gitignored — it won't be committed.

### Usage

Two ways to provide the receipt image:

```bash
# Option 1: pass the image path as an argument
/scan-receipt path/to/receipt.jpg

# Option 2: paste/drop the image into the terminal first, then run
/scan-receipt
```

### What happens

1. Claude reads `env-config.txt` for the API URL and key
2. Claude analyzes the receipt image — transcribes every line, identifies the store, classifies items vs discounts vs totals
3. A confirmation table is displayed with store, date, items, prices, and discounts
4. You approve or request corrections
5. On approval, the data is POSTed as CSV to the plugin's `import-csv` endpoint
6. The receipt ID and total are reported back

### Troubleshooting

- **"env-config.txt not found"** — create the file as shown above
- **Connection refused** — make sure wp-env is running (`make env-start`)
- **401 Unauthorized** — check the API key matches what's in WP Admin → Settings → Receipt Tracker
- **Incorrect items** — say "no" at the confirmation step and describe what needs fixing

## CSV Import

For bulk or manual imports, you can also use CSV paste or the REST API directly.

### Paste in App

Use the "Paste CSV" button on the dashboard to paste AI-generated CSV output.

### API Import

Submit CSV directly via REST API. Generate an API key and copy the Claude prompt from **WP Admin > Settings > Receipt Tracker**. The prompt tells Claude to parse the receipt and output a `curl` command to submit it to your site.

**Endpoint:** `POST /wp-json/grt/v1/receipts/import-csv`
- Headers: `Content-Type: text/plain`, `X-GRT-API-Key: <key>`
- Body: raw CSV text

### CSV Format

```
store,date,voucher_discount
<store name>,<YYYY-MM-DD>,<voucher discount or 0>
name,quantity,price,discount
<item name>,<quantity>,<price>,<discount or 0>
```

Rules: one row per line item, `price` = original price before discount, `discount` = amount subtracted (0 if none), `voucher_discount` = whole-receipt coupon (0 if none). YYYY-MM-DD dates, no currency symbols, no quotes. Omit subtotals/totals/tax/payment lines.

## Architecture

- **Frontend**: React app (`src/`) built with `@wordpress/scripts`
- **Backend**: WordPress REST API (`includes/class-rest-api.php`)
- **Data**: Custom database tables created on plugin activation
- **OCR**: Tesseract via shell exec, with optional Ollama LLM fallback
