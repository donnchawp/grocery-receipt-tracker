=== Grocery Receipt Tracker ===
Contributors: donncha
Tags: grocery, receipt, ocr, price-tracking, pwa
Requires at least: 6.4
Tested up to: 6.9
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Track grocery prices by scanning receipts with OCR, LLM-assisted parsing, or CSV paste.

== Description ==

Grocery Receipt Tracker is a privacy-first, self-hosted WordPress plugin that captures grocery receipt data and tracks product prices over time.

**Features:**

* Scan receipts with your phone camera — parsed via Tesseract OCR
* Optional LLM parsing via local Ollama or Google Gemini for improved accuracy
* CSV paste import — send a receipt photo to ChatGPT or Claude and paste the output
* Price history charts for every product
* Product search across all tracked items
* Installable as a standalone PWA on mobile
* Store-specific parsers for Dunnes, Aldi, Lidl, SuperValu, and Centra

**Privacy-first:** All data stays on your server. No external services required (LLM integration is optional).

== Installation ==

1. Upload the `grocery-receipt-tracker` folder to `/wp-content/plugins/`.
2. Activate the plugin through the Plugins menu in WordPress.
3. Create a WordPress page with the slug `grocery-tracker` and add the `[grocery_tracker]` shortcode.
4. Ensure Tesseract OCR is installed on your server (`apt-get install tesseract-ocr tesseract-ocr-eng`).

For development with wp-env:

1. Run `make install` to install dependencies.
2. Run `make env-start` to start the Docker environment (Tesseract installs automatically).
3. Run `make dev` for hot-reload development.

== Frequently Asked Questions ==

= Does this require an external API? =

No. Tesseract OCR runs locally on your server. LLM integration (Ollama or Gemini) is optional and can be enabled in Settings > Receipt Tracker LLM.

= What stores are supported? =

Built-in parsers exist for Dunnes, Aldi, Lidl, SuperValu, and Centra (Irish stores). A generic fallback parser handles other receipt formats. New store parsers can be added by creating a parser class file.

= What is the CSV paste feature? =

You can photograph a receipt, send the image to a paid AI model (Claude or ChatGPT), and paste the resulting CSV into the plugin. This is often more accurate than local OCR.

= Does it work offline? =

The PWA caches static assets for faster loading, but receipt scanning and data operations require an internet connection to your WordPress site.

== Screenshots ==

1. Dashboard with recent receipts and spending stats.
2. Camera capture screen for scanning receipts.
3. Receipt review screen for editing parsed items.
4. Product price history chart.

== Changelog ==

= 0.1.0 =
* Initial release.
* Receipt scanning via Tesseract OCR.
* LLM-assisted parsing via Ollama and Google Gemini.
* CSV paste import.
* Store-specific parsers for Irish grocery stores.
* Price tracking with Recharts visualisation.
* PWA support with service worker.

== Upgrade Notice ==

= 0.1.0 =
Initial release.
