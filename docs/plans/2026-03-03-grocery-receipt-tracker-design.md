# Grocery Receipt Tracker - Design Document

## Decisions

| Decision | Choice | Rationale |
|----------|--------|-----------|
| OCR Engine | Tesseract (self-hosted) | Privacy-first, no external API dependency |
| Field Extraction | Regex/heuristic parsers + manual review | Pragmatic, iteratable, good UX |
| Target Stores | Dunnes, Aldi, Lidl, SuperValu, Centra | User's primary shops |
| Hosting | Docker container | Full control, easy to add Tesseract |
| Frontend | React via wp-scripts | WP ships React, good for complex forms |
| Processing Model | Synchronous | Simple, immediate feedback, household-scale |

## Database Schema

Four custom tables (prefixed `{$wpdb->prefix}grt_`):

**grt_receipts**: id, user_id (FK wp_users), store, receipt_date, total, image_attachment_id (FK wp_posts), raw_ocr_text, created_at, updated_at

**grt_products**: id, canonical_name (UNIQUE), brand (nullable), category, barcode (nullable), created_at, updated_at

**grt_receipt_items**: id, receipt_id (FK grt_receipts), product_id (FK grt_products), raw_item_text, quantity (DECIMAL 10,3), original_price (DECIMAL 10,2), discount (DECIMAL 10,2 DEFAULT 0), final_price (DECIMAL 10,2). Indexes on receipt_id, product_id.

**grt_price_history**: id, product_id (FK grt_products), receipt_item_id (FK grt_receipt_items), store, price_date, final_price (DECIMAL 10,2). Index on (product_id, price_date).

Key decisions:
- `raw_ocr_text` stored on receipt for debugging/re-parsing
- `raw_item_text` on receipt_items traces original OCR read
- `price_history` denormalized from receipt_items for fast analytics
- `quantity` DECIMAL for weight-based items
- Receipt images use WP Media Library (attachment_id)

## Plugin Structure

```
grocery-receipt-tracker/
├── grocery-receipt-tracker.php          # Main plugin file
├── docker/
│   └── Dockerfile                       # Extends WP image, adds tesseract-ocr
├── includes/
│   ├── class-activator.php              # DB table creation
│   ├── class-deactivator.php            # Cleanup
│   ├── class-rest-api.php               # REST route registration
│   ├── class-ocr-processor.php          # Tesseract integration
│   ├── class-receipt-parser.php         # Dispatches to store parsers
│   └── parsers/
│       ├── class-parser-interface.php
│       ├── class-dunnes-parser.php
│       ├── class-aldi-parser.php
│       ├── class-lidl-parser.php
│       ├── class-supervalu-parser.php
│       └── class-centra-parser.php
├── src/                                 # React source (wp-scripts)
│   ├── index.js
│   ├── App.jsx
│   ├── components/
│   │   ├── CameraCapture.jsx
│   │   ├── ReceiptReview.jsx
│   │   ├── ReceiptList.jsx
│   │   ├── ProductSearch.jsx
│   │   ├── PriceChart.jsx
│   │   └── Dashboard.jsx
│   ├── hooks/
│   │   └── useApi.js
│   └── utils/
│       └── imageCompressor.js
├── build/                               # wp-scripts output
├── package.json
└── readme.txt
```

## REST API

Namespace: `grt/v1`. All endpoints require authentication (`current_user_can('edit_posts')`) + nonce validation.

| Method | Endpoint | Purpose |
|--------|----------|---------|
| POST | `/receipts/scan` | Upload image, OCR, return parsed items |
| POST | `/receipts` | Save reviewed receipt + items |
| GET | `/receipts` | List receipts (paginated) |
| GET | `/receipts/{id}` | Single receipt with items |
| DELETE | `/receipts/{id}` | Delete receipt |
| GET | `/products` | Search/list products |
| PUT | `/products/{id}` | Update product metadata |
| GET | `/products/{id}/price-history` | Price history data |
| GET | `/analytics/category/{category}` | Category price trends |

## OCR + Parsing Pipeline

1. User captures receipt photo in PWA
2. Client-side compression (max 1500px wide, 80% JPEG quality)
3. POST to `/receipts/scan` with image
4. Server saves image to WP Media Library
5. Tesseract extracts raw text
6. Receipt Parser detects store via text patterns
7. Store-specific parser extracts structured items via regex
8. Returns {store, date, items[], raw_text} to PWA
9. User reviews/edits in form, matches products
10. POST to `/receipts` saves final data + updates price_history

Store parsers implement `Receipt_Parser_Interface` with `can_parse(string): bool` and `parse(string): array`. Generic fallback parser handles unknown stores via basic price pattern matching.

Product matching: fuzzy match item names against `grt_products`. User confirms match or creates new product. Canonical product database builds organically.

## PWA Features

- Service worker: offline caching of recent receipts and product list
- Web App Manifest: installable, standalone display mode
- Camera: `navigator.mediaDevices.getUserMedia` with file picker fallback
- Image compression: canvas resize to JPEG blob before upload
- Charting: Recharts for price history and analytics
- State: React Context (no Redux)

## Screens

1. **Dashboard** - Recent receipts, spend summary, camera quick-access
2. **Camera/Upload** - Live viewfinder or file picker, immediate upload
3. **Receipt Review** - Image (zoomable) + editable items table, product matching
4. **Receipt History** - Paginated, filterable by store/date
5. **Product Detail** - Price history chart, min/max/avg stats, store breakdown
6. **Analytics** - Category trends, brand comparison charts

## Docker

Dockerfile snippet to extend WordPress image with Tesseract:

```dockerfile
FROM wordpress:latest
RUN apt-get update && \
    apt-get install -y tesseract-ocr tesseract-ocr-eng && \
    rm -rf /var/lib/apt/lists/*
```

Plugin detects Tesseract via `exec('which tesseract')` and surfaces admin notice if missing.

## Scope Boundaries

**In scope**: WordPress plugin directory + Dockerfile snippet for Tesseract.

**Out of scope**: docker-compose, WP installation/config, SSL/domain, multisite.
