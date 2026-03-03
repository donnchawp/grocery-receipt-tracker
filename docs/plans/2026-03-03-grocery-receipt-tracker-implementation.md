# Grocery Receipt Tracker Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Build a WordPress plugin + PWA that captures grocery receipts via camera, extracts items with Tesseract OCR + store-specific parsers, and tracks price history with analytics.

**Architecture:** WordPress custom plugin with custom DB tables, REST API endpoints, React frontend built with wp-scripts, Tesseract OCR in Docker. Synchronous processing — upload image, OCR immediately, return parsed results for user review.

**Tech Stack:** PHP 8.1+, WordPress 6.x, Tesseract OCR, React 18 (wp-scripts), Recharts, Service Workers

**Design doc:** `docs/plans/2026-03-03-grocery-receipt-tracker-design.md`

---

### Task 1: Plugin Scaffold + Build Tooling

**Files:**
- Create: `grocery-receipt-tracker.php`
- Create: `package.json`
- Create: `Makefile`
- Create: `.gitignore`
- Create: `docker/Dockerfile`
- Create: `src/index.js` (minimal placeholder)

**Step 1: Create .gitignore**

```gitignore
node_modules/
build/
vendor/
.DS_Store
*.log
```

**Step 2: Create main plugin file**

```php
<?php
/**
 * Plugin Name: Grocery Receipt Tracker
 * Description: Track grocery prices via receipt OCR capture
 * Version: 0.1.0
 * Requires PHP: 8.1
 * Author: Donncha
 * Text Domain: grocery-receipt-tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'GRT_VERSION', '0.1.0' );
define( 'GRT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'GRT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'GRT_PLUGIN_FILE', __FILE__ );

require_once GRT_PLUGIN_DIR . 'includes/class-activator.php';
require_once GRT_PLUGIN_DIR . 'includes/class-rest-api.php';

register_activation_hook( __FILE__, array( 'GRT_Activator', 'activate' ) );

add_action( 'rest_api_init', array( 'GRT_REST_API', 'register_routes' ) );

/**
 * Enqueue the React app on the plugin's front-end page.
 */
function grt_enqueue_app() {
    if ( ! is_page( 'grocery-tracker' ) ) {
        return;
    }

    $asset_file = GRT_PLUGIN_DIR . 'build/index.asset.php';
    if ( ! file_exists( $asset_file ) ) {
        return;
    }
    $asset = require $asset_file;

    wp_enqueue_script(
        'grt-app',
        GRT_PLUGIN_URL . 'build/index.js',
        $asset['dependencies'],
        $asset['version'],
        true
    );

    wp_enqueue_style(
        'grt-app',
        GRT_PLUGIN_URL . 'build/index.css',
        array(),
        $asset['version']
    );

    wp_localize_script( 'grt-app', 'grtSettings', array(
        'apiUrl'  => rest_url( 'grt/v1' ),
        'nonce'   => wp_create_nonce( 'wp_rest' ),
        'siteUrl' => home_url(),
    ) );
}
add_action( 'wp_enqueue_scripts', 'grt_enqueue_app' );

/**
 * Register the shortcode to render the app container.
 */
function grt_shortcode() {
    return '<div id="grt-app"></div>';
}
add_shortcode( 'grocery_tracker', 'grt_shortcode' );

/**
 * Admin notice if Tesseract is not available.
 */
function grt_admin_notices() {
    $tesseract_path = @exec( 'which tesseract' );
    if ( empty( $tesseract_path ) ) {
        echo '<div class="notice notice-warning"><p>';
        echo esc_html__( 'Grocery Receipt Tracker: Tesseract OCR is not installed. Receipt scanning will not work.', 'grocery-receipt-tracker' );
        echo '</p></div>';
    }
}
add_action( 'admin_notices', 'grt_admin_notices' );
```

**Step 3: Create package.json**

```json
{
  "name": "grocery-receipt-tracker",
  "version": "0.1.0",
  "private": true,
  "scripts": {
    "build": "wp-scripts build",
    "start": "wp-scripts start",
    "lint:js": "wp-scripts lint-js",
    "lint:css": "wp-scripts lint-style",
    "test": "wp-scripts test-unit-js"
  },
  "devDependencies": {
    "@wordpress/scripts": "^30.0.0"
  },
  "dependencies": {
    "recharts": "^2.15.0"
  }
}
```

**Step 4: Create minimal React entry point**

`src/index.js`:
```jsx
import { createRoot } from '@wordpress/element';

function App() {
    return <div>Grocery Receipt Tracker loading...</div>;
}

const container = document.getElementById( 'grt-app' );
if ( container ) {
    createRoot( container ).render( <App /> );
}
```

**Step 5: Create Makefile**

```makefile
.PHONY: help build dev lint test clean

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
```

**Step 6: Create Dockerfile**

`docker/Dockerfile`:
```dockerfile
FROM wordpress:latest

RUN apt-get update && \
    apt-get install -y tesseract-ocr tesseract-ocr-eng && \
    rm -rf /var/lib/apt/lists/*
```

**Step 7: Install dependencies and verify build**

Run: `npm install && npm run build`
Expected: Build completes, `build/index.js` and `build/index.asset.php` created.

**Step 8: Commit**

```bash
git add -A
git commit -m "feat: scaffold plugin with wp-scripts build tooling"
```

---

### Task 2: Database Tables (Activator)

**Files:**
- Create: `includes/class-activator.php`

**Step 1: Create the activator class**

```php
<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class GRT_Activator {

    public static function activate() {
        self::create_tables();
        flush_rewrite_rules();
    }

    private static function create_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        $prefix = $wpdb->prefix . 'grt_';

        $sql = "CREATE TABLE {$prefix}receipts (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            store varchar(100) NOT NULL DEFAULT '',
            receipt_date date NOT NULL,
            total decimal(10,2) NOT NULL DEFAULT 0,
            image_attachment_id bigint(20) unsigned DEFAULT NULL,
            raw_ocr_text longtext DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY receipt_date (receipt_date)
        ) $charset_collate;

        CREATE TABLE {$prefix}products (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            canonical_name varchar(255) NOT NULL,
            brand varchar(100) DEFAULT NULL,
            category varchar(100) NOT NULL DEFAULT 'uncategorized',
            barcode varchar(50) DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY canonical_name (canonical_name),
            KEY category (category)
        ) $charset_collate;

        CREATE TABLE {$prefix}receipt_items (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            receipt_id bigint(20) unsigned NOT NULL,
            product_id bigint(20) unsigned DEFAULT NULL,
            raw_item_text varchar(255) NOT NULL DEFAULT '',
            quantity decimal(10,3) NOT NULL DEFAULT 1.000,
            original_price decimal(10,2) NOT NULL DEFAULT 0,
            discount decimal(10,2) NOT NULL DEFAULT 0,
            final_price decimal(10,2) NOT NULL DEFAULT 0,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY receipt_id (receipt_id),
            KEY product_id (product_id)
        ) $charset_collate;

        CREATE TABLE {$prefix}price_history (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            product_id bigint(20) unsigned NOT NULL,
            receipt_item_id bigint(20) unsigned DEFAULT NULL,
            store varchar(100) NOT NULL DEFAULT '',
            price_date date NOT NULL,
            final_price decimal(10,2) NOT NULL,
            PRIMARY KEY (id),
            KEY product_price_date (product_id, price_date),
            KEY store (store)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        update_option( 'grt_db_version', GRT_VERSION );
    }
}
```

**Step 2: Verify activation creates tables**

Activate the plugin in WP admin or via WP-CLI: `wp plugin activate grocery-receipt-tracker`
Then check: `wp db query "SHOW TABLES LIKE '%grt_%'"`
Expected: Four tables listed.

**Step 3: Commit**

```bash
git add includes/class-activator.php
git commit -m "feat: add database table creation on plugin activation"
```

---

### Task 3: OCR Processor

**Files:**
- Create: `includes/class-ocr-processor.php`
- Create: `tests/php/test-ocr-processor.php` (manual verification — no WP test harness yet)

**Step 1: Create the OCR processor class**

```php
<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class GRT_OCR_Processor {

    /**
     * Check if Tesseract is available on the system.
     */
    public static function is_available(): bool {
        $output = array();
        $code   = 0;
        @exec( 'which tesseract 2>/dev/null', $output, $code );
        return $code === 0 && ! empty( $output[0] );
    }

    /**
     * Run Tesseract OCR on an image file.
     *
     * @param string $image_path Absolute path to image file.
     * @return array{success: bool, text?: string, error?: string}
     */
    public static function process( string $image_path ): array {
        if ( ! self::is_available() ) {
            return array(
                'success' => false,
                'error'   => 'Tesseract OCR is not installed.',
            );
        }

        if ( ! file_exists( $image_path ) ) {
            return array(
                'success' => false,
                'error'   => 'Image file not found.',
            );
        }

        $escaped_path = escapeshellarg( $image_path );
        $output       = array();
        $return_code  = 0;

        exec( "tesseract {$escaped_path} stdout --psm 6 2>/dev/null", $output, $return_code );

        if ( $return_code !== 0 ) {
            return array(
                'success' => false,
                'error'   => 'Tesseract failed to process image.',
            );
        }

        $text = implode( "\n", $output );
        $text = trim( $text );

        if ( empty( $text ) ) {
            return array(
                'success' => false,
                'error'   => 'No text extracted from image.',
            );
        }

        return array(
            'success' => true,
            'text'    => $text,
        );
    }
}
```

**Step 2: Add require to main plugin file**

Add to `grocery-receipt-tracker.php` after the activator require:
```php
require_once GRT_PLUGIN_DIR . 'includes/class-ocr-processor.php';
```

**Step 3: Commit**

```bash
git add includes/class-ocr-processor.php grocery-receipt-tracker.php
git commit -m "feat: add Tesseract OCR processor class"
```

---

### Task 4: Parser Interface + Generic Fallback Parser

**Files:**
- Create: `includes/parsers/class-parser-interface.php`
- Create: `includes/parsers/class-generic-parser.php`
- Create: `includes/class-receipt-parser.php`

**Step 1: Create the parser interface**

```php
<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

interface GRT_Parser_Interface {

    /**
     * Check if this parser can handle the given OCR text.
     *
     * @param string $raw_text Raw OCR text from Tesseract.
     * @return bool
     */
    public function can_parse( string $raw_text ): bool;

    /**
     * Parse raw OCR text into structured receipt data.
     *
     * @param string $raw_text Raw OCR text from Tesseract.
     * @return array{
     *     store: string,
     *     date: string|null,
     *     items: array<array{name: string, quantity: float, original_price: float, discount: float, final_price: float}>,
     * }
     */
    public function parse( string $raw_text ): array;
}
```

**Step 2: Create the generic fallback parser**

```php
<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/class-parser-interface.php';

class GRT_Generic_Parser implements GRT_Parser_Interface {

    public function can_parse( string $raw_text ): bool {
        // Generic parser always accepts — it's the fallback.
        return true;
    }

    public function parse( string $raw_text ): array {
        $lines = explode( "\n", $raw_text );
        $items = array();
        $date  = null;
        $store = 'Unknown';

        // Try to extract date (formats: DD/MM/YYYY, DD-MM-YYYY, DD.MM.YYYY)
        foreach ( $lines as $line ) {
            if ( preg_match( '/(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{2,4})/', $line, $m ) ) {
                $year = strlen( $m[3] ) === 2 ? '20' . $m[3] : $m[3];
                $date = sprintf( '%s-%s-%s', $year, str_pad( $m[2], 2, '0', STR_PAD_LEFT ), str_pad( $m[1], 2, '0', STR_PAD_LEFT ) );
                break;
            }
        }

        // Use first non-empty line as store name guess.
        foreach ( $lines as $line ) {
            $trimmed = trim( $line );
            if ( ! empty( $trimmed ) && strlen( $trimmed ) > 2 ) {
                $store = $trimmed;
                break;
            }
        }

        // Extract items: look for lines with a price pattern (€X.XX or X.XX at end of line)
        foreach ( $lines as $line ) {
            $line = trim( $line );
            if ( empty( $line ) ) {
                continue;
            }

            // Match: item text followed by a price (€1.99 or 1.99)
            if ( preg_match( '/^(.+?)\s+€?\s*(\d+\.\d{2})\s*$/', $line, $m ) ) {
                $name  = trim( $m[1] );
                $price = (float) $m[2];

                // Skip lines that look like totals/subtotals
                if ( preg_match( '/\b(total|subtotal|balance|change|cash|card|visa|mastercard|paid)\b/i', $name ) ) {
                    continue;
                }

                $items[] = array(
                    'name'           => $name,
                    'quantity'       => 1.0,
                    'original_price' => $price,
                    'discount'       => 0.0,
                    'final_price'    => $price,
                );
            }
        }

        return array(
            'store' => $store,
            'date'  => $date,
            'items' => $items,
        );
    }
}
```

**Step 3: Create the receipt parser dispatcher**

```php
<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/parsers/class-parser-interface.php';
require_once __DIR__ . '/parsers/class-generic-parser.php';

class GRT_Receipt_Parser {

    /** @var GRT_Parser_Interface[] */
    private array $parsers = array();

    public function __construct() {
        $this->load_parsers();
    }

    private function load_parsers() {
        $parser_dir   = GRT_PLUGIN_DIR . 'includes/parsers/';
        $parser_files = glob( $parser_dir . 'class-*-parser.php' );

        foreach ( $parser_files as $file ) {
            $basename = basename( $file, '.php' );
            // Skip interface and generic (generic is the fallback).
            if ( in_array( $basename, array( 'class-parser-interface', 'class-generic-parser' ), true ) ) {
                continue;
            }

            require_once $file;

            // Convert filename to class name: class-dunnes-parser.php → GRT_Dunnes_Parser
            $class_name = str_replace( 'class-', '', $basename );
            $class_name = str_replace( '-', '_', $class_name );
            $class_name = 'GRT_' . implode( '_', array_map( 'ucfirst', explode( '_', $class_name ) ) );

            if ( class_exists( $class_name ) ) {
                $this->parsers[] = new $class_name();
            }
        }

        // Generic parser is always last (fallback).
        $this->parsers[] = new GRT_Generic_Parser();
    }

    /**
     * Parse raw OCR text using the first matching store parser.
     *
     * @param string $raw_text Raw OCR output.
     * @return array Parsed receipt data.
     */
    public function parse( string $raw_text ): array {
        foreach ( $this->parsers as $parser ) {
            if ( $parser->can_parse( $raw_text ) ) {
                return $parser->parse( $raw_text );
            }
        }

        // Should never reach here because generic always matches.
        return ( new GRT_Generic_Parser() )->parse( $raw_text );
    }
}
```

**Step 4: Add requires to main plugin file**

Add to `grocery-receipt-tracker.php`:
```php
require_once GRT_PLUGIN_DIR . 'includes/class-receipt-parser.php';
```

**Step 5: Commit**

```bash
git add includes/parsers/ includes/class-receipt-parser.php grocery-receipt-tracker.php
git commit -m "feat: add parser interface, generic parser, and dispatcher"
```

---

### Task 5: Store-Specific Parsers (Dunnes, Aldi, Lidl, SuperValu, Centra)

**Files:**
- Create: `includes/parsers/class-dunnes-parser.php`
- Create: `includes/parsers/class-aldi-parser.php`
- Create: `includes/parsers/class-lidl-parser.php`
- Create: `includes/parsers/class-supervalu-parser.php`
- Create: `includes/parsers/class-centra-parser.php`
- Create: `tests/js/parsers.test.js` (to validate regex patterns against sample receipt text)

**Note:** These parsers will need real receipt samples to refine. Start with best-guess patterns based on common Irish receipt formats. Each parser follows the same structure.

**Step 1: Create Dunnes parser**

```php
<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/class-parser-interface.php';

class GRT_Dunnes_Parser implements GRT_Parser_Interface {

    public function can_parse( string $raw_text ): bool {
        return (bool) preg_match( '/dunnes\s*stores?/i', $raw_text );
    }

    public function parse( string $raw_text ): array {
        $lines = explode( "\n", $raw_text );
        $items = array();
        $date  = null;

        // Extract date
        foreach ( $lines as $line ) {
            if ( preg_match( '/(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{2,4})/', $line, $m ) ) {
                $year = strlen( $m[3] ) === 2 ? '20' . $m[3] : $m[3];
                $date = sprintf( '%s-%s-%s', $year, str_pad( $m[2], 2, '0', STR_PAD_LEFT ), str_pad( $m[1], 2, '0', STR_PAD_LEFT ) );
                break;
            }
        }

        $skip_patterns = '/\b(total|subtotal|balance|change|cash|card|visa|mastercard|paid|vat|tax|clubcard|points|saving)\b/i';

        foreach ( $lines as $line ) {
            $line = trim( $line );
            if ( empty( $line ) ) {
                continue;
            }

            // Dunnes format: ITEM NAME    €X.XX
            if ( preg_match( '/^(.+?)\s+€?\s*(\d+\.\d{2})\s*$/', $line, $m ) ) {
                $name  = trim( $m[1] );
                $price = (float) $m[2];

                if ( preg_match( $skip_patterns, $name ) ) {
                    continue;
                }

                // Check for discount on next/same line (negative or with minus)
                $discount = 0.0;
                if ( preg_match( '/-\s*€?\s*(\d+\.\d{2})/', $line, $dm ) ) {
                    $discount = (float) $dm[1];
                }

                $items[] = array(
                    'name'           => $name,
                    'quantity'       => 1.0,
                    'original_price' => $price,
                    'discount'       => $discount,
                    'final_price'    => $price - $discount,
                );
            }

            // Handle quantity lines: "2 @ €1.50"
            if ( preg_match( '/^(\d+)\s*[@xX]\s*€?\s*(\d+\.\d{2})/', $line, $qm ) ) {
                $qty        = (int) $qm[1];
                $unit_price = (float) $qm[2];
                // This modifies the last item if it exists
                if ( ! empty( $items ) ) {
                    $last_idx = count( $items ) - 1;
                    $items[ $last_idx ]['quantity']       = (float) $qty;
                    $items[ $last_idx ]['original_price'] = $unit_price * $qty;
                    $items[ $last_idx ]['final_price']    = $items[ $last_idx ]['original_price'] - $items[ $last_idx ]['discount'];
                }
            }
        }

        return array(
            'store' => 'Dunnes Stores',
            'date'  => $date,
            'items' => $items,
        );
    }
}
```

**Step 2: Create Aldi parser**

```php
<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/class-parser-interface.php';

class GRT_Aldi_Parser implements GRT_Parser_Interface {

    public function can_parse( string $raw_text ): bool {
        return (bool) preg_match( '/\baldi\b/i', $raw_text );
    }

    public function parse( string $raw_text ): array {
        $lines = explode( "\n", $raw_text );
        $items = array();
        $date  = null;

        foreach ( $lines as $line ) {
            if ( preg_match( '/(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{2,4})/', $line, $m ) ) {
                $year = strlen( $m[3] ) === 2 ? '20' . $m[3] : $m[3];
                $date = sprintf( '%s-%s-%s', $year, str_pad( $m[2], 2, '0', STR_PAD_LEFT ), str_pad( $m[1], 2, '0', STR_PAD_LEFT ) );
                break;
            }
        }

        $skip_patterns = '/\b(total|subtotal|balance|change|cash|card|visa|mastercard|paid|vat|tax)\b/i';

        foreach ( $lines as $line ) {
            $line = trim( $line );
            if ( empty( $line ) ) {
                continue;
            }

            // Aldi format: ITEM NAME    X.XX (often no € symbol)
            if ( preg_match( '/^(.+?)\s+€?\s*(\d+\.\d{2})\s*$/', $line, $m ) ) {
                $name  = trim( $m[1] );
                $price = (float) $m[2];

                if ( preg_match( $skip_patterns, $name ) ) {
                    continue;
                }

                $items[] = array(
                    'name'           => $name,
                    'quantity'       => 1.0,
                    'original_price' => $price,
                    'discount'       => 0.0,
                    'final_price'    => $price,
                );
            }
        }

        return array(
            'store' => 'Aldi',
            'date'  => $date,
            'items' => $items,
        );
    }
}
```

**Step 3: Create Lidl parser**

```php
<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/class-parser-interface.php';

class GRT_Lidl_Parser implements GRT_Parser_Interface {

    public function can_parse( string $raw_text ): bool {
        return (bool) preg_match( '/\blidl\b/i', $raw_text );
    }

    public function parse( string $raw_text ): array {
        $lines = explode( "\n", $raw_text );
        $items = array();
        $date  = null;

        foreach ( $lines as $line ) {
            if ( preg_match( '/(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{2,4})/', $line, $m ) ) {
                $year = strlen( $m[3] ) === 2 ? '20' . $m[3] : $m[3];
                $date = sprintf( '%s-%s-%s', $year, str_pad( $m[2], 2, '0', STR_PAD_LEFT ), str_pad( $m[1], 2, '0', STR_PAD_LEFT ) );
                break;
            }
        }

        $skip_patterns = '/\b(total|subtotal|balance|change|cash|card|visa|mastercard|paid|vat|tax|lidl\s*plus)\b/i';

        foreach ( $lines as $i => $line ) {
            $line = trim( $line );
            if ( empty( $line ) ) {
                continue;
            }

            // Lidl format: ITEM NAME    €X.XX  or  ITEM NAME    X.XX A/B (tax code)
            if ( preg_match( '/^(.+?)\s+€?\s*(\d+\.\d{2})\s*[A-B]?\s*$/', $line, $m ) ) {
                $name  = trim( $m[1] );
                $price = (float) $m[2];

                if ( preg_match( $skip_patterns, $name ) ) {
                    continue;
                }

                // Check next line for discount
                $discount  = 0.0;
                $next_line = isset( $lines[ $i + 1 ] ) ? trim( $lines[ $i + 1 ] ) : '';
                if ( preg_match( '/^-\s*€?\s*(\d+\.\d{2})/', $next_line, $dm ) ) {
                    $discount = (float) $dm[1];
                }

                $items[] = array(
                    'name'           => $name,
                    'quantity'       => 1.0,
                    'original_price' => $price,
                    'discount'       => $discount,
                    'final_price'    => $price - $discount,
                );
            }
        }

        return array(
            'store' => 'Lidl',
            'date'  => $date,
            'items' => $items,
        );
    }
}
```

**Step 4: Create SuperValu parser**

```php
<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/class-parser-interface.php';

class GRT_Supervalu_Parser implements GRT_Parser_Interface {

    public function can_parse( string $raw_text ): bool {
        return (bool) preg_match( '/super\s*valu/i', $raw_text );
    }

    public function parse( string $raw_text ): array {
        $lines = explode( "\n", $raw_text );
        $items = array();
        $date  = null;

        foreach ( $lines as $line ) {
            if ( preg_match( '/(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{2,4})/', $line, $m ) ) {
                $year = strlen( $m[3] ) === 2 ? '20' . $m[3] : $m[3];
                $date = sprintf( '%s-%s-%s', $year, str_pad( $m[2], 2, '0', STR_PAD_LEFT ), str_pad( $m[1], 2, '0', STR_PAD_LEFT ) );
                break;
            }
        }

        $skip_patterns = '/\b(total|subtotal|balance|change|cash|card|visa|mastercard|paid|vat|tax|real\s*rewards)\b/i';

        foreach ( $lines as $line ) {
            $line = trim( $line );
            if ( empty( $line ) ) {
                continue;
            }

            if ( preg_match( '/^(.+?)\s+€?\s*(\d+\.\d{2})\s*$/', $line, $m ) ) {
                $name  = trim( $m[1] );
                $price = (float) $m[2];

                if ( preg_match( $skip_patterns, $name ) ) {
                    continue;
                }

                $items[] = array(
                    'name'           => $name,
                    'quantity'       => 1.0,
                    'original_price' => $price,
                    'discount'       => 0.0,
                    'final_price'    => $price,
                );
            }
        }

        return array(
            'store' => 'SuperValu',
            'date'  => $date,
            'items' => $items,
        );
    }
}
```

**Step 5: Create Centra parser**

```php
<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/class-parser-interface.php';

class GRT_Centra_Parser implements GRT_Parser_Interface {

    public function can_parse( string $raw_text ): bool {
        return (bool) preg_match( '/\bcentra\b/i', $raw_text );
    }

    public function parse( string $raw_text ): array {
        $lines = explode( "\n", $raw_text );
        $items = array();
        $date  = null;

        foreach ( $lines as $line ) {
            if ( preg_match( '/(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{2,4})/', $line, $m ) ) {
                $year = strlen( $m[3] ) === 2 ? '20' . $m[3] : $m[3];
                $date = sprintf( '%s-%s-%s', $year, str_pad( $m[2], 2, '0', STR_PAD_LEFT ), str_pad( $m[1], 2, '0', STR_PAD_LEFT ) );
                break;
            }
        }

        $skip_patterns = '/\b(total|subtotal|balance|change|cash|card|visa|mastercard|paid|vat|tax)\b/i';

        foreach ( $lines as $line ) {
            $line = trim( $line );
            if ( empty( $line ) ) {
                continue;
            }

            if ( preg_match( '/^(.+?)\s+€?\s*(\d+\.\d{2})\s*$/', $line, $m ) ) {
                $name  = trim( $m[1] );
                $price = (float) $m[2];

                if ( preg_match( $skip_patterns, $name ) ) {
                    continue;
                }

                $items[] = array(
                    'name'           => $name,
                    'quantity'       => 1.0,
                    'original_price' => $price,
                    'discount'       => 0.0,
                    'final_price'    => $price,
                );
            }
        }

        return array(
            'store' => 'Centra',
            'date'  => $date,
            'items' => $items,
        );
    }
}
```

**Step 6: Commit**

```bash
git add includes/parsers/
git commit -m "feat: add store-specific parsers for Dunnes, Aldi, Lidl, SuperValu, Centra"
```

---

### Task 6: REST API — Receipt Scanning Endpoint

**Files:**
- Create: `includes/class-rest-api.php`
- Modify: `grocery-receipt-tracker.php` (already has require + hook)

**Step 1: Create the REST API class with the scan endpoint**

```php
<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class GRT_REST_API {

    public static function register_routes() {
        // Receipt scanning (OCR)
        register_rest_route( 'grt/v1', '/receipts/scan', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'scan_receipt' ),
            'permission_callback' => array( __CLASS__, 'check_permission' ),
        ) );

        // Receipt CRUD
        register_rest_route( 'grt/v1', '/receipts', array(
            array(
                'methods'             => 'GET',
                'callback'            => array( __CLASS__, 'list_receipts' ),
                'permission_callback' => array( __CLASS__, 'check_permission' ),
                'args'                => array(
                    'page'     => array( 'type' => 'integer', 'default' => 1, 'minimum' => 1 ),
                    'per_page' => array( 'type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => 100 ),
                    'store'    => array( 'type' => 'string' ),
                ),
            ),
            array(
                'methods'             => 'POST',
                'callback'            => array( __CLASS__, 'create_receipt' ),
                'permission_callback' => array( __CLASS__, 'check_permission' ),
            ),
        ) );

        register_rest_route( 'grt/v1', '/receipts/(?P<id>\d+)', array(
            array(
                'methods'             => 'GET',
                'callback'            => array( __CLASS__, 'get_receipt' ),
                'permission_callback' => array( __CLASS__, 'check_permission' ),
            ),
            array(
                'methods'             => 'DELETE',
                'callback'            => array( __CLASS__, 'delete_receipt' ),
                'permission_callback' => array( __CLASS__, 'check_permission' ),
            ),
        ) );

        // Products
        register_rest_route( 'grt/v1', '/products', array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'list_products' ),
            'permission_callback' => array( __CLASS__, 'check_permission' ),
            'args'                => array(
                'search'   => array( 'type' => 'string' ),
                'category' => array( 'type' => 'string' ),
            ),
        ) );

        register_rest_route( 'grt/v1', '/products/(?P<id>\d+)', array(
            'methods'             => 'PUT',
            'callback'            => array( __CLASS__, 'update_product' ),
            'permission_callback' => array( __CLASS__, 'check_permission' ),
        ) );

        register_rest_route( 'grt/v1', '/products/(?P<id>\d+)/price-history', array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'get_price_history' ),
            'permission_callback' => array( __CLASS__, 'check_permission' ),
        ) );

        // Analytics
        register_rest_route( 'grt/v1', '/analytics/category/(?P<category>[a-zA-Z0-9_-]+)', array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'get_category_analytics' ),
            'permission_callback' => array( __CLASS__, 'check_permission' ),
        ) );
    }

    public static function check_permission(): bool {
        return current_user_can( 'edit_posts' );
    }

    /**
     * POST /receipts/scan — Upload image, OCR, return parsed items.
     */
    public static function scan_receipt( WP_REST_Request $request ) {
        $files = $request->get_file_params();

        if ( empty( $files['receipt'] ) ) {
            return new WP_Error( 'no_image', 'No receipt image provided.', array( 'status' => 400 ) );
        }

        // Upload to media library.
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        $attachment_id = media_handle_upload( 'receipt', 0 );

        if ( is_wp_error( $attachment_id ) ) {
            return new WP_Error( 'upload_failed', $attachment_id->get_error_message(), array( 'status' => 500 ) );
        }

        $image_path = get_attached_file( $attachment_id );

        // Run OCR.
        $ocr_result = GRT_OCR_Processor::process( $image_path );

        if ( ! $ocr_result['success'] ) {
            return new WP_Error( 'ocr_failed', $ocr_result['error'], array( 'status' => 500 ) );
        }

        // Parse the OCR text.
        $parser = new GRT_Receipt_Parser();
        $parsed = $parser->parse( $ocr_result['text'] );

        return rest_ensure_response( array(
            'attachment_id' => $attachment_id,
            'raw_text'      => $ocr_result['text'],
            'store'         => $parsed['store'],
            'date'          => $parsed['date'],
            'items'         => $parsed['items'],
        ) );
    }

    /**
     * POST /receipts — Save reviewed receipt + items.
     */
    public static function create_receipt( WP_REST_Request $request ) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'grt_';

        $body = $request->get_json_params();

        $store         = sanitize_text_field( $body['store'] ?? '' );
        $receipt_date  = sanitize_text_field( $body['date'] ?? '' );
        $items         = $body['items'] ?? array();
        $raw_ocr_text  = $body['raw_text'] ?? '';
        $attachment_id = absint( $body['attachment_id'] ?? 0 );

        if ( empty( $store ) || empty( $receipt_date ) || empty( $items ) ) {
            return new WP_Error( 'missing_fields', 'Store, date, and items are required.', array( 'status' => 400 ) );
        }

        // Calculate total.
        $total = array_sum( array_column( $items, 'final_price' ) );

        // Insert receipt.
        $wpdb->insert(
            $prefix . 'receipts',
            array(
                'user_id'             => get_current_user_id(),
                'store'               => $store,
                'receipt_date'        => $receipt_date,
                'total'               => $total,
                'image_attachment_id' => $attachment_id ?: null,
                'raw_ocr_text'        => $raw_ocr_text,
            ),
            array( '%d', '%s', '%s', '%f', '%d', '%s' )
        );

        $receipt_id = $wpdb->insert_id;

        if ( ! $receipt_id ) {
            return new WP_Error( 'db_error', 'Failed to save receipt.', array( 'status' => 500 ) );
        }

        // Insert items.
        foreach ( $items as $item ) {
            $product_id = self::resolve_product( $item );

            $wpdb->insert(
                $prefix . 'receipt_items',
                array(
                    'receipt_id'     => $receipt_id,
                    'product_id'     => $product_id,
                    'raw_item_text'  => sanitize_text_field( $item['name'] ?? '' ),
                    'quantity'       => (float) ( $item['quantity'] ?? 1 ),
                    'original_price' => (float) ( $item['original_price'] ?? 0 ),
                    'discount'       => (float) ( $item['discount'] ?? 0 ),
                    'final_price'    => (float) ( $item['final_price'] ?? 0 ),
                ),
                array( '%d', '%d', '%s', '%f', '%f', '%f', '%f' )
            );

            $receipt_item_id = $wpdb->insert_id;

            // Update price history.
            if ( $product_id ) {
                $wpdb->insert(
                    $prefix . 'price_history',
                    array(
                        'product_id'      => $product_id,
                        'receipt_item_id' => $receipt_item_id,
                        'store'           => $store,
                        'price_date'      => $receipt_date,
                        'final_price'     => (float) ( $item['final_price'] ?? 0 ),
                    ),
                    array( '%d', '%d', '%s', '%s', '%f' )
                );
            }
        }

        return rest_ensure_response( array(
            'id'    => $receipt_id,
            'total' => $total,
        ) );
    }

    /**
     * Resolve or create a product from an item.
     */
    private static function resolve_product( array $item ): ?int {
        global $wpdb;
        $prefix = $wpdb->prefix . 'grt_';

        // If product_id provided (user matched existing product), use it.
        if ( ! empty( $item['product_id'] ) ) {
            return absint( $item['product_id'] );
        }

        $name = sanitize_text_field( $item['name'] ?? '' );
        if ( empty( $name ) ) {
            return null;
        }

        // Check if product already exists by canonical name.
        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$prefix}products WHERE canonical_name = %s",
            $name
        ) );

        if ( $existing ) {
            return (int) $existing;
        }

        // Create new product.
        $wpdb->insert(
            $prefix . 'products',
            array(
                'canonical_name' => $name,
                'brand'          => sanitize_text_field( $item['brand'] ?? '' ) ?: null,
                'category'       => sanitize_text_field( $item['category'] ?? 'uncategorized' ),
            ),
            array( '%s', '%s', '%s' )
        );

        return $wpdb->insert_id ?: null;
    }

    /**
     * GET /receipts — List receipts (paginated).
     */
    public static function list_receipts( WP_REST_Request $request ) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'grt_';

        $page     = $request->get_param( 'page' );
        $per_page = $request->get_param( 'per_page' );
        $store    = $request->get_param( 'store' );
        $offset   = ( $page - 1 ) * $per_page;

        $where = 'WHERE user_id = %d';
        $args  = array( get_current_user_id() );

        if ( $store ) {
            $where .= ' AND store = %s';
            $args[] = $store;
        }

        $total = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$prefix}receipts {$where}",
            ...$args
        ) );

        $args[] = $per_page;
        $args[] = $offset;

        $receipts = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, store, receipt_date, total, image_attachment_id, created_at
             FROM {$prefix}receipts {$where}
             ORDER BY receipt_date DESC
             LIMIT %d OFFSET %d",
            ...$args
        ) );

        $response = rest_ensure_response( $receipts );
        $response->header( 'X-WP-Total', (int) $total );
        $response->header( 'X-WP-TotalPages', ceil( $total / $per_page ) );

        return $response;
    }

    /**
     * GET /receipts/{id} — Single receipt with items.
     */
    public static function get_receipt( WP_REST_Request $request ) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'grt_';
        $id     = absint( $request['id'] );

        $receipt = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$prefix}receipts WHERE id = %d AND user_id = %d",
            $id,
            get_current_user_id()
        ) );

        if ( ! $receipt ) {
            return new WP_Error( 'not_found', 'Receipt not found.', array( 'status' => 404 ) );
        }

        $items = $wpdb->get_results( $wpdb->prepare(
            "SELECT ri.*, p.canonical_name, p.brand, p.category
             FROM {$prefix}receipt_items ri
             LEFT JOIN {$prefix}products p ON ri.product_id = p.id
             WHERE ri.receipt_id = %d",
            $id
        ) );

        $receipt->items     = $items;
        $receipt->image_url = $receipt->image_attachment_id
            ? wp_get_attachment_url( $receipt->image_attachment_id )
            : null;

        return rest_ensure_response( $receipt );
    }

    /**
     * DELETE /receipts/{id}
     */
    public static function delete_receipt( WP_REST_Request $request ) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'grt_';
        $id     = absint( $request['id'] );

        // Verify ownership.
        $receipt = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, image_attachment_id FROM {$prefix}receipts WHERE id = %d AND user_id = %d",
            $id,
            get_current_user_id()
        ) );

        if ( ! $receipt ) {
            return new WP_Error( 'not_found', 'Receipt not found.', array( 'status' => 404 ) );
        }

        // Delete price history entries for this receipt's items.
        $wpdb->query( $wpdb->prepare(
            "DELETE ph FROM {$prefix}price_history ph
             INNER JOIN {$prefix}receipt_items ri ON ph.receipt_item_id = ri.id
             WHERE ri.receipt_id = %d",
            $id
        ) );

        // Delete receipt items.
        $wpdb->delete( $prefix . 'receipt_items', array( 'receipt_id' => $id ), array( '%d' ) );

        // Delete receipt.
        $wpdb->delete( $prefix . 'receipts', array( 'id' => $id ), array( '%d' ) );

        // Optionally delete the attachment.
        if ( $receipt->image_attachment_id ) {
            wp_delete_attachment( $receipt->image_attachment_id, true );
        }

        return rest_ensure_response( array( 'deleted' => true ) );
    }

    /**
     * GET /products
     */
    public static function list_products( WP_REST_Request $request ) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'grt_';

        $search   = $request->get_param( 'search' );
        $category = $request->get_param( 'category' );

        $where = '1=1';
        $args  = array();

        if ( $search ) {
            $where .= ' AND canonical_name LIKE %s';
            $args[] = '%' . $wpdb->esc_like( $search ) . '%';
        }

        if ( $category ) {
            $where .= ' AND category = %s';
            $args[] = $category;
        }

        $query = "SELECT * FROM {$prefix}products WHERE {$where} ORDER BY canonical_name ASC LIMIT 100";

        if ( ! empty( $args ) ) {
            $products = $wpdb->get_results( $wpdb->prepare( $query, ...$args ) );
        } else {
            $products = $wpdb->get_results( $query );
        }

        return rest_ensure_response( $products );
    }

    /**
     * PUT /products/{id}
     */
    public static function update_product( WP_REST_Request $request ) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'grt_';
        $id     = absint( $request['id'] );
        $body   = $request->get_json_params();

        $data   = array();
        $format = array();

        if ( isset( $body['canonical_name'] ) ) {
            $data['canonical_name'] = sanitize_text_field( $body['canonical_name'] );
            $format[]               = '%s';
        }
        if ( isset( $body['brand'] ) ) {
            $data['brand'] = sanitize_text_field( $body['brand'] ) ?: null;
            $format[]      = '%s';
        }
        if ( isset( $body['category'] ) ) {
            $data['category'] = sanitize_text_field( $body['category'] );
            $format[]         = '%s';
        }

        if ( empty( $data ) ) {
            return new WP_Error( 'no_data', 'No fields to update.', array( 'status' => 400 ) );
        }

        $wpdb->update( $prefix . 'products', $data, array( 'id' => $id ), $format, array( '%d' ) );

        $product = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$prefix}products WHERE id = %d",
            $id
        ) );

        return rest_ensure_response( $product );
    }

    /**
     * GET /products/{id}/price-history
     */
    public static function get_price_history( WP_REST_Request $request ) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'grt_';
        $id     = absint( $request['id'] );

        $history = $wpdb->get_results( $wpdb->prepare(
            "SELECT price_date, store, final_price
             FROM {$prefix}price_history
             WHERE product_id = %d
             ORDER BY price_date ASC",
            $id
        ) );

        // Compute stats.
        $prices = array_column( $history, 'final_price' );
        $stats  = array();

        if ( ! empty( $prices ) ) {
            $prices_float = array_map( 'floatval', $prices );
            $stats = array(
                'min'     => min( $prices_float ),
                'max'     => max( $prices_float ),
                'avg'     => round( array_sum( $prices_float ) / count( $prices_float ), 2 ),
                'current' => end( $prices_float ),
                'count'   => count( $prices_float ),
            );
        }

        return rest_ensure_response( array(
            'history' => $history,
            'stats'   => $stats,
        ) );
    }

    /**
     * GET /analytics/category/{category}
     */
    public static function get_category_analytics( WP_REST_Request $request ) {
        global $wpdb;
        $prefix   = $wpdb->prefix . 'grt_';
        $category = sanitize_text_field( $request['category'] );

        $trends = $wpdb->get_results( $wpdb->prepare(
            "SELECT ph.price_date, AVG(ph.final_price) as avg_price, COUNT(*) as item_count
             FROM {$prefix}price_history ph
             INNER JOIN {$prefix}products p ON ph.product_id = p.id
             WHERE p.category = %s
             GROUP BY ph.price_date
             ORDER BY ph.price_date ASC",
            $category
        ) );

        return rest_ensure_response( array(
            'category' => $category,
            'trends'   => $trends,
        ) );
    }
}
```

**Step 2: Verify route registration**

Run: `wp rest route list --namespace=grt/v1`
Expected: All 9 routes listed.

**Step 3: Commit**

```bash
git add includes/class-rest-api.php
git commit -m "feat: add REST API endpoints for receipts, products, and analytics"
```

---

### Task 7: React App Shell + Routing

**Files:**
- Modify: `src/index.js`
- Create: `src/App.jsx`
- Create: `src/index.css`
- Create: `src/hooks/useApi.js`
- Create: `src/utils/imageCompressor.js`

**Step 1: Create the API hook**

`src/hooks/useApi.js`:
```jsx
const API_URL = window.grtSettings?.apiUrl || '/wp-json/grt/v1';
const NONCE = window.grtSettings?.nonce || '';

export function useApi() {
    const fetchApi = async ( endpoint, options = {} ) => {
        const { method = 'GET', body, isFormData = false } = options;

        const headers = {
            'X-WP-Nonce': NONCE,
        };

        if ( ! isFormData ) {
            headers['Content-Type'] = 'application/json';
        }

        const config = {
            method,
            headers,
            credentials: 'same-origin',
        };

        if ( body ) {
            config.body = isFormData ? body : JSON.stringify( body );
        }

        const response = await fetch( `${ API_URL }${ endpoint }`, config );

        if ( ! response.ok ) {
            const error = await response.json().catch( () => ( {} ) );
            throw new Error( error.message || `API error: ${ response.status }` );
        }

        return {
            data: await response.json(),
            headers: {
                total: parseInt( response.headers.get( 'X-WP-Total' ) || '0', 10 ),
                totalPages: parseInt( response.headers.get( 'X-WP-TotalPages' ) || '0', 10 ),
            },
        };
    };

    return { fetchApi };
}
```

**Step 2: Create image compressor utility**

`src/utils/imageCompressor.js`:
```jsx
/**
 * Compress an image file to a target max width and JPEG quality.
 *
 * @param {File} file - The image file to compress.
 * @param {number} maxWidth - Maximum width in pixels (default 1500).
 * @param {number} quality - JPEG quality 0-1 (default 0.8).
 * @returns {Promise<Blob>} Compressed image as JPEG blob.
 */
export function compressImage( file, maxWidth = 1500, quality = 0.8 ) {
    return new Promise( ( resolve, reject ) => {
        const reader = new FileReader();
        reader.onload = ( e ) => {
            const img = new Image();
            img.onload = () => {
                const canvas = document.createElement( 'canvas' );
                let { width, height } = img;

                if ( width > maxWidth ) {
                    height = Math.round( ( height * maxWidth ) / width );
                    width = maxWidth;
                }

                canvas.width = width;
                canvas.height = height;

                const ctx = canvas.getContext( '2d' );
                ctx.drawImage( img, 0, 0, width, height );

                canvas.toBlob(
                    ( blob ) => {
                        if ( blob ) {
                            resolve( blob );
                        } else {
                            reject( new Error( 'Image compression failed.' ) );
                        }
                    },
                    'image/jpeg',
                    quality
                );
            };
            img.onerror = () => reject( new Error( 'Failed to load image.' ) );
            img.src = e.target.result;
        };
        reader.onerror = () => reject( new Error( 'Failed to read file.' ) );
        reader.readAsDataURL( file );
    } );
}
```

**Step 3: Create app shell with routing**

`src/App.jsx`:
```jsx
import { useState } from '@wordpress/element';
import { CameraCapture } from './components/CameraCapture';
import { ReceiptReview } from './components/ReceiptReview';
import { ReceiptList } from './components/ReceiptList';
import { Dashboard } from './components/Dashboard';
import { ProductDetail } from './components/ProductDetail';

const SCREENS = {
    DASHBOARD: 'dashboard',
    CAMERA: 'camera',
    REVIEW: 'review',
    RECEIPTS: 'receipts',
    PRODUCT: 'product',
};

export function App() {
    const [ screen, setScreen ] = useState( SCREENS.DASHBOARD );
    const [ scanResult, setScanResult ] = useState( null );
    const [ selectedProductId, setSelectedProductId ] = useState( null );

    const navigate = ( newScreen, data ) => {
        if ( newScreen === SCREENS.REVIEW && data ) {
            setScanResult( data );
        }
        if ( newScreen === SCREENS.PRODUCT && data ) {
            setSelectedProductId( data );
        }
        setScreen( newScreen );
    };

    return (
        <div className="grt-app">
            <nav className="grt-nav">
                <button
                    className={ screen === SCREENS.DASHBOARD ? 'active' : '' }
                    onClick={ () => navigate( SCREENS.DASHBOARD ) }
                >
                    Dashboard
                </button>
                <button
                    className={ screen === SCREENS.CAMERA ? 'active' : '' }
                    onClick={ () => navigate( SCREENS.CAMERA ) }
                >
                    Scan
                </button>
                <button
                    className={ screen === SCREENS.RECEIPTS ? 'active' : '' }
                    onClick={ () => navigate( SCREENS.RECEIPTS ) }
                >
                    Receipts
                </button>
            </nav>

            <main className="grt-main">
                { screen === SCREENS.DASHBOARD && (
                    <Dashboard onNavigate={ navigate } />
                ) }
                { screen === SCREENS.CAMERA && (
                    <CameraCapture
                        onScanComplete={ ( data ) => navigate( SCREENS.REVIEW, data ) }
                    />
                ) }
                { screen === SCREENS.REVIEW && scanResult && (
                    <ReceiptReview
                        scanResult={ scanResult }
                        onSaved={ () => navigate( SCREENS.RECEIPTS ) }
                        onCancel={ () => navigate( SCREENS.DASHBOARD ) }
                    />
                ) }
                { screen === SCREENS.RECEIPTS && (
                    <ReceiptList
                        onSelectProduct={ ( id ) => navigate( SCREENS.PRODUCT, id ) }
                    />
                ) }
                { screen === SCREENS.PRODUCT && selectedProductId && (
                    <ProductDetail
                        productId={ selectedProductId }
                        onBack={ () => navigate( SCREENS.RECEIPTS ) }
                    />
                ) }
            </main>
        </div>
    );
}
```

**Step 4: Create base styles**

`src/index.css`:
```css
.grt-app {
    max-width: 600px;
    margin: 0 auto;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
    padding: 0 16px;
}

.grt-nav {
    display: flex;
    gap: 0;
    border-bottom: 2px solid #e0e0e0;
    margin-bottom: 16px;
    position: sticky;
    top: 0;
    background: #fff;
    z-index: 10;
}

.grt-nav button {
    flex: 1;
    padding: 12px 8px;
    border: none;
    background: none;
    font-size: 14px;
    font-weight: 500;
    color: #666;
    cursor: pointer;
    border-bottom: 2px solid transparent;
    margin-bottom: -2px;
}

.grt-nav button.active {
    color: #0073aa;
    border-bottom-color: #0073aa;
}

.grt-main {
    padding-bottom: 32px;
}

/* Shared form styles */
.grt-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 10px 20px;
    border: none;
    border-radius: 6px;
    font-size: 14px;
    font-weight: 500;
    cursor: pointer;
    transition: background 0.2s;
}

.grt-btn-primary {
    background: #0073aa;
    color: #fff;
}

.grt-btn-primary:hover {
    background: #005a87;
}

.grt-btn-secondary {
    background: #e0e0e0;
    color: #333;
}

.grt-btn-danger {
    background: #d32f2f;
    color: #fff;
}

.grt-input {
    width: 100%;
    padding: 8px 12px;
    border: 1px solid #ccc;
    border-radius: 4px;
    font-size: 14px;
    box-sizing: border-box;
}

.grt-loading {
    text-align: center;
    padding: 32px;
    color: #666;
}

.grt-error {
    padding: 12px;
    background: #fce4e4;
    color: #c62828;
    border-radius: 4px;
    margin-bottom: 16px;
}
```

**Step 5: Update index.js entry point**

`src/index.js`:
```jsx
import { createRoot } from '@wordpress/element';
import { App } from './App';
import './index.css';

const container = document.getElementById( 'grt-app' );
if ( container ) {
    createRoot( container ).render( <App /> );
}
```

**Step 6: Build and verify**

Run: `npm run build`
Expected: Compiles with warnings about missing component files (we'll create those next). No errors.

**Step 7: Commit**

```bash
git add src/ grocery-receipt-tracker.php
git commit -m "feat: add React app shell with routing, API hook, and image compressor"
```

---

### Task 8: Camera Capture Component

**Files:**
- Create: `src/components/CameraCapture.jsx`

**Step 1: Create camera capture component**

```jsx
import { useState, useRef } from '@wordpress/element';
import { useApi } from '../hooks/useApi';
import { compressImage } from '../utils/imageCompressor';

export function CameraCapture( { onScanComplete } ) {
    const [ status, setStatus ] = useState( 'idle' ); // idle | capturing | uploading | error
    const [ error, setError ] = useState( null );
    const fileInputRef = useRef( null );
    const { fetchApi } = useApi();

    const handleFile = async ( file ) => {
        if ( ! file ) return;

        setStatus( 'uploading' );
        setError( null );

        try {
            const compressed = await compressImage( file );
            const formData = new FormData();
            formData.append( 'receipt', compressed, 'receipt.jpg' );

            const { data } = await fetchApi( '/receipts/scan', {
                method: 'POST',
                body: formData,
                isFormData: true,
            } );

            onScanComplete( data );
        } catch ( err ) {
            setError( err.message );
            setStatus( 'error' );
        }
    };

    const handleFileInput = ( e ) => {
        const file = e.target.files?.[ 0 ];
        if ( file ) {
            handleFile( file );
        }
    };

    const handleCapture = () => {
        fileInputRef.current?.click();
    };

    return (
        <div className="grt-camera">
            <h2>Scan Receipt</h2>

            { error && <div className="grt-error">{ error }</div> }

            { status === 'uploading' ? (
                <div className="grt-loading">
                    <p>Processing receipt...</p>
                    <p style={ { fontSize: '12px', color: '#999' } }>
                        Uploading and running OCR
                    </p>
                </div>
            ) : (
                <div className="grt-camera-actions">
                    <input
                        ref={ fileInputRef }
                        type="file"
                        accept="image/*"
                        capture="environment"
                        onChange={ handleFileInput }
                        style={ { display: 'none' } }
                    />

                    <button
                        className="grt-btn grt-btn-primary grt-camera-btn"
                        onClick={ handleCapture }
                    >
                        Take Photo
                    </button>

                    <label className="grt-btn grt-btn-secondary grt-camera-btn">
                        Choose from Gallery
                        <input
                            type="file"
                            accept="image/*"
                            onChange={ handleFileInput }
                            style={ { display: 'none' } }
                        />
                    </label>
                </div>
            ) }

            <style>{ `
                .grt-camera {
                    text-align: center;
                    padding: 24px 0;
                }
                .grt-camera-actions {
                    display: flex;
                    flex-direction: column;
                    gap: 12px;
                    max-width: 300px;
                    margin: 24px auto;
                }
                .grt-camera-btn {
                    padding: 16px 24px;
                    font-size: 16px;
                }
            ` }</style>
        </div>
    );
}
```

**Step 2: Commit**

```bash
git add src/components/CameraCapture.jsx
git commit -m "feat: add camera capture component with image upload"
```

---

### Task 9: Receipt Review Component

**Files:**
- Create: `src/components/ReceiptReview.jsx`

**Step 1: Create receipt review component**

```jsx
import { useState, useEffect } from '@wordpress/element';
import { useApi } from '../hooks/useApi';

export function ReceiptReview( { scanResult, onSaved, onCancel } ) {
    const { fetchApi } = useApi();
    const [ store, setStore ] = useState( scanResult.store || '' );
    const [ date, setDate ] = useState( scanResult.date || '' );
    const [ items, setItems ] = useState( scanResult.items || [] );
    const [ products, setProducts ] = useState( [] );
    const [ saving, setSaving ] = useState( false );
    const [ error, setError ] = useState( null );

    useEffect( () => {
        fetchApi( '/products' )
            .then( ( { data } ) => setProducts( data ) )
            .catch( () => {} );
    }, [] );

    const updateItem = ( index, field, value ) => {
        setItems( ( prev ) =>
            prev.map( ( item, i ) => {
                if ( i !== index ) return item;
                const updated = { ...item, [ field ]: value };
                // Recalculate final price if original_price or discount changed.
                if ( field === 'original_price' || field === 'discount' ) {
                    updated.final_price =
                        parseFloat( updated.original_price || 0 ) -
                        parseFloat( updated.discount || 0 );
                }
                return updated;
            } )
        );
    };

    const removeItem = ( index ) => {
        setItems( ( prev ) => prev.filter( ( _, i ) => i !== index ) );
    };

    const addItem = () => {
        setItems( ( prev ) => [
            ...prev,
            {
                name: '',
                quantity: 1,
                original_price: 0,
                discount: 0,
                final_price: 0,
            },
        ] );
    };

    const handleSave = async () => {
        setSaving( true );
        setError( null );

        try {
            await fetchApi( '/receipts', {
                method: 'POST',
                body: {
                    store,
                    date,
                    items,
                    raw_text: scanResult.raw_text || '',
                    attachment_id: scanResult.attachment_id || 0,
                },
            } );
            onSaved();
        } catch ( err ) {
            setError( err.message );
            setSaving( false );
        }
    };

    const total = items.reduce(
        ( sum, item ) => sum + parseFloat( item.final_price || 0 ),
        0
    );

    return (
        <div className="grt-review">
            <h2>Review Receipt</h2>

            { error && <div className="grt-error">{ error }</div> }

            <div className="grt-review-header">
                <div className="grt-field">
                    <label>Store</label>
                    <input
                        className="grt-input"
                        value={ store }
                        onChange={ ( e ) => setStore( e.target.value ) }
                    />
                </div>
                <div className="grt-field">
                    <label>Date</label>
                    <input
                        className="grt-input"
                        type="date"
                        value={ date }
                        onChange={ ( e ) => setDate( e.target.value ) }
                    />
                </div>
            </div>

            <div className="grt-items-table">
                <div className="grt-items-header">
                    <span>Item</span>
                    <span>Qty</span>
                    <span>Price</span>
                    <span>Disc.</span>
                    <span>Final</span>
                    <span></span>
                </div>

                { items.map( ( item, i ) => (
                    <div key={ i } className="grt-item-row">
                        <input
                            className="grt-input"
                            value={ item.name }
                            onChange={ ( e ) =>
                                updateItem( i, 'name', e.target.value )
                            }
                            placeholder="Item name"
                            list="grt-products-list"
                        />
                        <input
                            className="grt-input grt-input-sm"
                            type="number"
                            step="0.001"
                            value={ item.quantity }
                            onChange={ ( e ) =>
                                updateItem( i, 'quantity', e.target.value )
                            }
                        />
                        <input
                            className="grt-input grt-input-sm"
                            type="number"
                            step="0.01"
                            value={ item.original_price }
                            onChange={ ( e ) =>
                                updateItem(
                                    i,
                                    'original_price',
                                    e.target.value
                                )
                            }
                        />
                        <input
                            className="grt-input grt-input-sm"
                            type="number"
                            step="0.01"
                            value={ item.discount }
                            onChange={ ( e ) =>
                                updateItem( i, 'discount', e.target.value )
                            }
                        />
                        <span className="grt-item-final">
                            { parseFloat( item.final_price || 0 ).toFixed( 2 ) }
                        </span>
                        <button
                            className="grt-btn-icon"
                            onClick={ () => removeItem( i ) }
                            title="Remove item"
                        >
                            &times;
                        </button>
                    </div>
                ) ) }
            </div>

            <datalist id="grt-products-list">
                { products.map( ( p ) => (
                    <option key={ p.id } value={ p.canonical_name } />
                ) ) }
            </datalist>

            <div className="grt-review-footer">
                <button
                    className="grt-btn grt-btn-secondary"
                    onClick={ addItem }
                >
                    + Add Item
                </button>

                <div className="grt-total">
                    Total: &euro;{ total.toFixed( 2 ) }
                </div>

                <div className="grt-review-actions">
                    <button
                        className="grt-btn grt-btn-secondary"
                        onClick={ onCancel }
                    >
                        Cancel
                    </button>
                    <button
                        className="grt-btn grt-btn-primary"
                        onClick={ handleSave }
                        disabled={ saving || items.length === 0 }
                    >
                        { saving ? 'Saving...' : 'Save Receipt' }
                    </button>
                </div>
            </div>

            <style>{ `
                .grt-review-header {
                    display: grid;
                    grid-template-columns: 1fr 1fr;
                    gap: 12px;
                    margin-bottom: 16px;
                }
                .grt-field label {
                    display: block;
                    font-size: 12px;
                    font-weight: 600;
                    margin-bottom: 4px;
                    color: #555;
                }
                .grt-items-header {
                    display: grid;
                    grid-template-columns: 2fr 0.5fr 0.7fr 0.7fr 0.7fr 30px;
                    gap: 6px;
                    font-size: 11px;
                    font-weight: 600;
                    color: #888;
                    padding: 8px 0;
                    border-bottom: 1px solid #e0e0e0;
                }
                .grt-item-row {
                    display: grid;
                    grid-template-columns: 2fr 0.5fr 0.7fr 0.7fr 0.7fr 30px;
                    gap: 6px;
                    padding: 6px 0;
                    align-items: center;
                    border-bottom: 1px solid #f0f0f0;
                }
                .grt-input-sm {
                    padding: 6px;
                    font-size: 13px;
                }
                .grt-item-final {
                    font-weight: 600;
                    font-size: 13px;
                    text-align: right;
                }
                .grt-btn-icon {
                    border: none;
                    background: none;
                    font-size: 18px;
                    color: #999;
                    cursor: pointer;
                    padding: 0;
                }
                .grt-btn-icon:hover { color: #d32f2f; }
                .grt-review-footer {
                    margin-top: 16px;
                }
                .grt-total {
                    font-size: 18px;
                    font-weight: 700;
                    text-align: right;
                    padding: 12px 0;
                }
                .grt-review-actions {
                    display: flex;
                    gap: 12px;
                    justify-content: flex-end;
                }
            ` }</style>
        </div>
    );
}
```

**Step 2: Commit**

```bash
git add src/components/ReceiptReview.jsx
git commit -m "feat: add receipt review component with editable items table"
```

---

### Task 10: Dashboard + Receipt List Components

**Files:**
- Create: `src/components/Dashboard.jsx`
- Create: `src/components/ReceiptList.jsx`

**Step 1: Create Dashboard component**

```jsx
import { useState, useEffect } from '@wordpress/element';
import { useApi } from '../hooks/useApi';

export function Dashboard( { onNavigate } ) {
    const { fetchApi } = useApi();
    const [ receipts, setReceipts ] = useState( [] );
    const [ loading, setLoading ] = useState( true );

    useEffect( () => {
        fetchApi( '/receipts?per_page=5' )
            .then( ( { data } ) => setReceipts( data ) )
            .catch( () => {} )
            .finally( () => setLoading( false ) );
    }, [] );

    const totalSpend = receipts.reduce(
        ( sum, r ) => sum + parseFloat( r.total || 0 ),
        0
    );

    return (
        <div className="grt-dashboard">
            <h2>Grocery Tracker</h2>

            <div className="grt-stats">
                <div className="grt-stat-card">
                    <span className="grt-stat-value">{ receipts.length }</span>
                    <span className="grt-stat-label">Recent Receipts</span>
                </div>
                <div className="grt-stat-card">
                    <span className="grt-stat-value">
                        &euro;{ totalSpend.toFixed( 2 ) }
                    </span>
                    <span className="grt-stat-label">Recent Spend</span>
                </div>
            </div>

            <button
                className="grt-btn grt-btn-primary grt-scan-btn"
                onClick={ () => onNavigate( 'camera' ) }
            >
                Scan Receipt
            </button>

            { loading ? (
                <div className="grt-loading">Loading...</div>
            ) : (
                <div className="grt-recent">
                    <h3>Recent Receipts</h3>
                    { receipts.length === 0 ? (
                        <p style={ { color: '#999' } }>
                            No receipts yet. Scan your first receipt!
                        </p>
                    ) : (
                        receipts.map( ( r ) => (
                            <div key={ r.id } className="grt-receipt-card">
                                <div>
                                    <strong>{ r.store }</strong>
                                    <span className="grt-receipt-date">
                                        { r.receipt_date }
                                    </span>
                                </div>
                                <span className="grt-receipt-total">
                                    &euro;{ parseFloat( r.total ).toFixed( 2 ) }
                                </span>
                            </div>
                        ) )
                    ) }
                </div>
            ) }

            <style>{ `
                .grt-stats {
                    display: grid;
                    grid-template-columns: 1fr 1fr;
                    gap: 12px;
                    margin-bottom: 16px;
                }
                .grt-stat-card {
                    background: #f5f5f5;
                    border-radius: 8px;
                    padding: 16px;
                    text-align: center;
                }
                .grt-stat-value {
                    display: block;
                    font-size: 24px;
                    font-weight: 700;
                }
                .grt-stat-label {
                    font-size: 12px;
                    color: #666;
                }
                .grt-scan-btn {
                    width: 100%;
                    padding: 16px;
                    font-size: 16px;
                    margin-bottom: 24px;
                }
                .grt-receipt-card {
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                    padding: 12px;
                    border: 1px solid #e0e0e0;
                    border-radius: 6px;
                    margin-bottom: 8px;
                }
                .grt-receipt-date {
                    display: block;
                    font-size: 12px;
                    color: #999;
                }
                .grt-receipt-total {
                    font-weight: 700;
                    font-size: 16px;
                }
            ` }</style>
        </div>
    );
}
```

**Step 2: Create ReceiptList component**

```jsx
import { useState, useEffect } from '@wordpress/element';
import { useApi } from '../hooks/useApi';

export function ReceiptList( { onSelectProduct } ) {
    const { fetchApi } = useApi();
    const [ receipts, setReceipts ] = useState( [] );
    const [ expanded, setExpanded ] = useState( null );
    const [ expandedItems, setExpandedItems ] = useState( [] );
    const [ page, setPage ] = useState( 1 );
    const [ totalPages, setTotalPages ] = useState( 1 );
    const [ loading, setLoading ] = useState( true );

    const loadReceipts = async ( p ) => {
        setLoading( true );
        try {
            const { data, headers } = await fetchApi(
                `/receipts?page=${ p }&per_page=20`
            );
            setReceipts( data );
            setTotalPages( headers.totalPages );
        } catch ( err ) {
            // Silent fail — show empty state.
        }
        setLoading( false );
    };

    useEffect( () => {
        loadReceipts( page );
    }, [ page ] );

    const toggleExpand = async ( id ) => {
        if ( expanded === id ) {
            setExpanded( null );
            return;
        }

        try {
            const { data } = await fetchApi( `/receipts/${ id }` );
            setExpandedItems( data.items || [] );
            setExpanded( id );
        } catch ( err ) {
            // Silent fail.
        }
    };

    const handleDelete = async ( id ) => {
        if ( ! window.confirm( 'Delete this receipt?' ) ) return;

        try {
            await fetchApi( `/receipts/${ id }`, { method: 'DELETE' } );
            setReceipts( ( prev ) => prev.filter( ( r ) => r.id !== id ) );
            if ( expanded === id ) setExpanded( null );
        } catch ( err ) {
            alert( 'Failed to delete receipt.' );
        }
    };

    if ( loading ) {
        return <div className="grt-loading">Loading receipts...</div>;
    }

    return (
        <div className="grt-receipts">
            <h2>Receipts</h2>

            { receipts.length === 0 ? (
                <p style={ { color: '#999' } }>No receipts found.</p>
            ) : (
                receipts.map( ( r ) => (
                    <div key={ r.id } className="grt-receipt-item">
                        <div
                            className="grt-receipt-summary"
                            onClick={ () => toggleExpand( r.id ) }
                        >
                            <div>
                                <strong>{ r.store }</strong>
                                <span className="grt-receipt-date">
                                    { r.receipt_date }
                                </span>
                            </div>
                            <div className="grt-receipt-right">
                                <span className="grt-receipt-total">
                                    &euro;
                                    { parseFloat( r.total ).toFixed( 2 ) }
                                </span>
                                <button
                                    className="grt-btn-icon"
                                    onClick={ ( e ) => {
                                        e.stopPropagation();
                                        handleDelete( r.id );
                                    } }
                                    title="Delete"
                                >
                                    &times;
                                </button>
                            </div>
                        </div>

                        { expanded === r.id && (
                            <div className="grt-receipt-detail">
                                { expandedItems.map( ( item, i ) => (
                                    <div key={ i } className="grt-detail-row">
                                        <span
                                            className="grt-detail-name"
                                            onClick={ () => {
                                                if ( item.product_id ) {
                                                    onSelectProduct(
                                                        item.product_id
                                                    );
                                                }
                                            } }
                                            style={
                                                item.product_id
                                                    ? { cursor: 'pointer', color: '#0073aa' }
                                                    : {}
                                            }
                                        >
                                            { item.canonical_name ||
                                                item.raw_item_text }
                                        </span>
                                        <span>
                                            { item.quantity > 1
                                                ? `${ item.quantity }x `
                                                : '' }
                                            &euro;
                                            { parseFloat(
                                                item.final_price
                                            ).toFixed( 2 ) }
                                        </span>
                                    </div>
                                ) ) }
                            </div>
                        ) }
                    </div>
                ) )
            ) }

            { totalPages > 1 && (
                <div className="grt-pagination">
                    <button
                        className="grt-btn grt-btn-secondary"
                        disabled={ page <= 1 }
                        onClick={ () => setPage( page - 1 ) }
                    >
                        Previous
                    </button>
                    <span>
                        Page { page } of { totalPages }
                    </span>
                    <button
                        className="grt-btn grt-btn-secondary"
                        disabled={ page >= totalPages }
                        onClick={ () => setPage( page + 1 ) }
                    >
                        Next
                    </button>
                </div>
            ) }

            <style>{ `
                .grt-receipt-item {
                    border: 1px solid #e0e0e0;
                    border-radius: 6px;
                    margin-bottom: 8px;
                    overflow: hidden;
                }
                .grt-receipt-summary {
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                    padding: 12px;
                    cursor: pointer;
                }
                .grt-receipt-summary:hover {
                    background: #fafafa;
                }
                .grt-receipt-right {
                    display: flex;
                    align-items: center;
                    gap: 8px;
                }
                .grt-receipt-detail {
                    border-top: 1px solid #e0e0e0;
                    padding: 8px 12px;
                    background: #fafafa;
                }
                .grt-detail-row {
                    display: flex;
                    justify-content: space-between;
                    padding: 4px 0;
                    font-size: 13px;
                }
                .grt-pagination {
                    display: flex;
                    justify-content: center;
                    align-items: center;
                    gap: 12px;
                    margin-top: 16px;
                }
            ` }</style>
        </div>
    );
}
```

**Step 3: Commit**

```bash
git add src/components/Dashboard.jsx src/components/ReceiptList.jsx
git commit -m "feat: add dashboard and receipt list components"
```

---

### Task 11: Product Detail + Price Chart Component

**Files:**
- Create: `src/components/ProductDetail.jsx`
- Create: `src/components/PriceChart.jsx`
- Create: `src/components/ProductSearch.jsx`

**Step 1: Create PriceChart component**

```jsx
import {
    LineChart,
    Line,
    XAxis,
    YAxis,
    CartesianGrid,
    Tooltip,
    ResponsiveContainer,
} from 'recharts';

export function PriceChart( { data } ) {
    if ( ! data || data.length === 0 ) {
        return <p style={ { color: '#999' } }>No price data available.</p>;
    }

    const chartData = data.map( ( d ) => ( {
        date: d.price_date,
        price: parseFloat( d.final_price ),
        store: d.store,
    } ) );

    return (
        <ResponsiveContainer width="100%" height={ 250 }>
            <LineChart data={ chartData }>
                <CartesianGrid strokeDasharray="3 3" />
                <XAxis dataKey="date" tick={ { fontSize: 11 } } />
                <YAxis
                    tick={ { fontSize: 11 } }
                    tickFormatter={ ( v ) => `\u20ac${ v.toFixed( 2 ) }` }
                />
                <Tooltip
                    formatter={ ( v ) => [ `\u20ac${ v.toFixed( 2 ) }`, 'Price' ] }
                />
                <Line
                    type="monotone"
                    dataKey="price"
                    stroke="#0073aa"
                    strokeWidth={ 2 }
                    dot={ { r: 3 } }
                />
            </LineChart>
        </ResponsiveContainer>
    );
}
```

**Step 2: Create ProductDetail component**

```jsx
import { useState, useEffect } from '@wordpress/element';
import { useApi } from '../hooks/useApi';
import { PriceChart } from './PriceChart';

export function ProductDetail( { productId, onBack } ) {
    const { fetchApi } = useApi();
    const [ product, setProduct ] = useState( null );
    const [ history, setHistory ] = useState( [] );
    const [ stats, setStats ] = useState( null );
    const [ loading, setLoading ] = useState( true );

    useEffect( () => {
        const load = async () => {
            try {
                const [ productsRes, historyRes ] = await Promise.all( [
                    fetchApi( `/products?search=` ),
                    fetchApi( `/products/${ productId }/price-history` ),
                ] );

                const found = productsRes.data.find(
                    ( p ) => String( p.id ) === String( productId )
                );
                setProduct( found || null );
                setHistory( historyRes.data.history || [] );
                setStats( historyRes.data.stats || null );
            } catch ( err ) {
                // Silent fail.
            }
            setLoading( false );
        };
        load();
    }, [ productId ] );

    if ( loading ) {
        return <div className="grt-loading">Loading product...</div>;
    }

    return (
        <div className="grt-product-detail">
            <button className="grt-btn grt-btn-secondary" onClick={ onBack }>
                &larr; Back
            </button>

            <h2>{ product?.canonical_name || 'Product' }</h2>

            { product?.brand && (
                <p className="grt-product-brand">Brand: { product.brand }</p>
            ) }
            { product?.category && (
                <p className="grt-product-category">
                    Category: { product.category }
                </p>
            ) }

            { stats && (
                <div className="grt-price-stats">
                    <div className="grt-stat-card">
                        <span className="grt-stat-value">
                            &euro;{ stats.current?.toFixed( 2 ) }
                        </span>
                        <span className="grt-stat-label">Current</span>
                    </div>
                    <div className="grt-stat-card">
                        <span className="grt-stat-value">
                            &euro;{ stats.min?.toFixed( 2 ) }
                        </span>
                        <span className="grt-stat-label">Lowest</span>
                    </div>
                    <div className="grt-stat-card">
                        <span className="grt-stat-value">
                            &euro;{ stats.max?.toFixed( 2 ) }
                        </span>
                        <span className="grt-stat-label">Highest</span>
                    </div>
                    <div className="grt-stat-card">
                        <span className="grt-stat-value">
                            &euro;{ stats.avg?.toFixed( 2 ) }
                        </span>
                        <span className="grt-stat-label">Average</span>
                    </div>
                </div>
            ) }

            <h3>Price History</h3>
            <PriceChart data={ history } />

            <style>{ `
                .grt-product-detail h2 {
                    margin-top: 16px;
                }
                .grt-product-brand, .grt-product-category {
                    color: #666;
                    font-size: 13px;
                    margin: 2px 0;
                }
                .grt-price-stats {
                    display: grid;
                    grid-template-columns: repeat(4, 1fr);
                    gap: 8px;
                    margin: 16px 0;
                }
            ` }</style>
        </div>
    );
}
```

**Step 3: Create ProductSearch component** (used for searching/browsing products)

```jsx
import { useState, useEffect } from '@wordpress/element';
import { useApi } from '../hooks/useApi';

export function ProductSearch( { onSelect } ) {
    const { fetchApi } = useApi();
    const [ query, setQuery ] = useState( '' );
    const [ results, setResults ] = useState( [] );

    useEffect( () => {
        if ( query.length < 2 ) {
            setResults( [] );
            return;
        }

        const timer = setTimeout( async () => {
            try {
                const { data } = await fetchApi(
                    `/products?search=${ encodeURIComponent( query ) }`
                );
                setResults( data );
            } catch ( err ) {
                // Silent fail.
            }
        }, 300 );

        return () => clearTimeout( timer );
    }, [ query ] );

    return (
        <div className="grt-product-search">
            <input
                className="grt-input"
                placeholder="Search products..."
                value={ query }
                onChange={ ( e ) => setQuery( e.target.value ) }
            />
            { results.length > 0 && (
                <div className="grt-search-results">
                    { results.map( ( p ) => (
                        <div
                            key={ p.id }
                            className="grt-search-result"
                            onClick={ () => onSelect( p ) }
                        >
                            <strong>{ p.canonical_name }</strong>
                            { p.brand && (
                                <span className="grt-search-brand">
                                    { p.brand }
                                </span>
                            ) }
                        </div>
                    ) ) }
                </div>
            ) }

            <style>{ `
                .grt-product-search { position: relative; }
                .grt-search-results {
                    position: absolute;
                    top: 100%;
                    left: 0;
                    right: 0;
                    background: #fff;
                    border: 1px solid #ccc;
                    border-radius: 0 0 4px 4px;
                    max-height: 200px;
                    overflow-y: auto;
                    z-index: 20;
                }
                .grt-search-result {
                    padding: 8px 12px;
                    cursor: pointer;
                    font-size: 13px;
                }
                .grt-search-result:hover { background: #f0f0f0; }
                .grt-search-brand {
                    display: block;
                    font-size: 11px;
                    color: #999;
                }
            ` }</style>
        </div>
    );
}
```

**Step 4: Build and verify**

Run: `npm run build`
Expected: Successful build with all components.

**Step 5: Commit**

```bash
git add src/components/ProductDetail.jsx src/components/PriceChart.jsx src/components/ProductSearch.jsx
git commit -m "feat: add product detail, price chart, and product search components"
```

---

### Task 12: PWA Setup — Service Worker + Manifest

**Files:**
- Create: `src/service-worker.js`
- Modify: `grocery-receipt-tracker.php` (add manifest route + SW registration)

**Step 1: Create service worker**

`src/service-worker.js`:
```js
const CACHE_NAME = 'grt-cache-v1';
const PRECACHE_URLS = [];

self.addEventListener( 'install', ( event ) => {
    event.waitUntil(
        caches
            .open( CACHE_NAME )
            .then( ( cache ) => cache.addAll( PRECACHE_URLS ) )
            .then( () => self.skipWaiting() )
    );
} );

self.addEventListener( 'activate', ( event ) => {
    event.waitUntil(
        caches.keys().then( ( names ) =>
            Promise.all(
                names
                    .filter( ( name ) => name !== CACHE_NAME )
                    .map( ( name ) => caches.delete( name ) )
            )
        ).then( () => self.clients.claim() )
    );
} );

self.addEventListener( 'fetch', ( event ) => {
    // Only cache GET requests.
    if ( event.request.method !== 'GET' ) return;

    // Skip API requests — always go to network.
    if ( event.request.url.includes( '/wp-json/' ) ) return;

    event.respondWith(
        caches.match( event.request ).then( ( cached ) => {
            const fetchPromise = fetch( event.request )
                .then( ( response ) => {
                    // Cache successful responses.
                    if ( response.ok ) {
                        const clone = response.clone();
                        caches
                            .open( CACHE_NAME )
                            .then( ( cache ) =>
                                cache.put( event.request, clone )
                            );
                    }
                    return response;
                } )
                .catch( () => cached );

            return cached || fetchPromise;
        } )
    );
} );
```

**Step 2: Add manifest + SW registration to plugin PHP**

Add to `grocery-receipt-tracker.php`:

```php
/**
 * Register the web app manifest endpoint.
 */
function grt_manifest_route() {
    register_rest_route( 'grt/v1', '/manifest.json', array(
        'methods'             => 'GET',
        'callback'            => 'grt_serve_manifest',
        'permission_callback' => '__return_true',
    ) );
}
add_action( 'rest_api_init', 'grt_manifest_route' );

function grt_serve_manifest() {
    return new WP_REST_Response( array(
        'name'             => 'Grocery Receipt Tracker',
        'short_name'       => 'GroceryTracker',
        'start_url'        => home_url( '/grocery-tracker/' ),
        'display'          => 'standalone',
        'background_color' => '#ffffff',
        'theme_color'      => '#0073aa',
        'icons'            => array(
            array(
                'src'   => GRT_PLUGIN_URL . 'assets/icon-192.png',
                'sizes' => '192x192',
                'type'  => 'image/png',
            ),
            array(
                'src'   => GRT_PLUGIN_URL . 'assets/icon-512.png',
                'sizes' => '512x512',
                'type'  => 'image/png',
            ),
        ),
    ), 200, array( 'Content-Type' => 'application/manifest+json' ) );
}

/**
 * Add manifest link and SW registration to head.
 */
function grt_pwa_head() {
    if ( ! is_page( 'grocery-tracker' ) ) {
        return;
    }
    $manifest_url = rest_url( 'grt/v1/manifest.json' );
    $sw_url       = GRT_PLUGIN_URL . 'src/service-worker.js';
    echo '<link rel="manifest" href="' . esc_url( $manifest_url ) . '">' . "\n";
    echo '<meta name="theme-color" content="#0073aa">' . "\n";
    echo '<script>
        if ("serviceWorker" in navigator) {
            navigator.serviceWorker.register("' . esc_url( $sw_url ) . '");
        }
    </script>' . "\n";
}
add_action( 'wp_head', 'grt_pwa_head' );
```

**Step 3: Create placeholder icon assets directory**

```bash
mkdir -p assets
# Create a minimal placeholder (replace with real icons later)
```

**Step 4: Commit**

```bash
git add src/service-worker.js grocery-receipt-tracker.php assets/
git commit -m "feat: add PWA service worker and web app manifest"
```

---

### Task 13: Final Build + Integration Verification

**Step 1: Full build**

Run: `npm run build`
Expected: Clean build, no errors.

**Step 2: Verify plugin loads in WordPress**

1. Copy plugin to `wp-content/plugins/grocery-receipt-tracker/`
2. Activate via WP admin or `wp plugin activate grocery-receipt-tracker`
3. Create a page with slug `grocery-tracker` and add shortcode `[grocery_tracker]`
4. Verify the React app loads on that page
5. Verify REST API routes are registered: `wp rest route list --namespace=grt/v1`
6. Verify database tables were created: `wp db query "SHOW TABLES LIKE '%grt_%'"`

**Step 3: Test the scan flow manually**

1. Open the grocery-tracker page
2. Click "Scan Receipt" → "Choose from Gallery"
3. Upload a receipt image
4. Verify OCR runs and items are returned to the review form
5. Edit items as needed and save
6. Verify receipt appears in the receipt list
7. Verify clicking a product shows price history

**Step 4: Commit any fixes**

```bash
git add -A
git commit -m "fix: integration fixes from manual testing"
```

---

### Task 14: Parser Refinement with Real Receipts

This is an iterative task. For each store:

1. Scan a real receipt
2. Compare parsed output to actual receipt
3. Adjust regex patterns in the store parser
4. Re-test

**Priority stores:** Dunnes, Aldi, Lidl (most common). SuperValu and Centra can be refined later as receipts come in.

Each parser fix should be a separate commit:

```bash
git commit -m "fix(parsers): improve Dunnes discount line detection"
```

---

## Summary

| Task | Description |
|------|-------------|
| 1 | Plugin scaffold, build tooling, Docker |
| 2 | Database table creation (activator) |
| 3 | Tesseract OCR processor |
| 4 | Parser interface + generic fallback |
| 5 | Store-specific parsers (5 stores) |
| 6 | REST API (all endpoints) |
| 7 | React app shell + routing + utilities |
| 8 | Camera capture component |
| 9 | Receipt review component |
| 10 | Dashboard + receipt list |
| 11 | Product detail + price charts |
| 12 | PWA service worker + manifest |
| 13 | Build + integration verification |
| 14 | Parser refinement with real receipts |
