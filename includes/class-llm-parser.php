<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/parsers/class-parser-interface.php';

class GRT_LLM_Parser implements GRT_Parser_Interface {

    private const VISION_PROMPT = <<<'PROMPT'
Extract receipt data from this image. Return ONLY valid JSON, no other text.

{
  "store": "store name",
  "date": "YYYY-MM-DD or null",
  "voucher_discount": 0.00,
  "items": [
    {
      "name": "item name",
      "quantity": 1,
      "original_price": 0.00,
      "discount": 0.00,
      "final_price": 0.00
    }
  ]
}

Rules:
- "store" is the shop/chain name (e.g. "Dunnes Stores"), NOT the branch location
- Prices are in euros
- CRITICAL: Count carefully. Read the receipt top-to-bottom, one printed line at a time. Each printed line with a product name and a positive price produces exactly ONE item in the output. Do NOT invent extra lines or skip lines.
- If the same product name appears on multiple SEPARATE printed lines, output each as a separate item with quantity 1 — do NOT merge them, but do NOT add more than actually appear
- Discount/deal lines (e.g. "SAVER DEAL", negative amounts) apply to the item IMMEDIATELY BEFORE them
- For a discounted item: original_price is the listed price, discount is the absolute value of the negative amount, final_price = original_price - discount
- For items with no discount: discount = 0, final_price = original_price
- quantity is always 1 unless explicitly stated on the same line (e.g. "2 x 1.99")
- If there is a receipt-level discount voucher (e.g. "DISCOUNT VOUCHER 10.00"), set voucher_discount to that amount. Do NOT include it as an item.
- Ignore: totals, subtotals, balance lines, payment lines, VAT lines, barcodes
- Date format: YYYY-MM-DD (or null if no date found)
- After extracting, verify your count of items against what you see on the receipt
PROMPT;

    private const GEMINI_VISION_PROMPT = <<<'PROMPT'
You are a receipt transcription expert. Follow these steps carefully. Show your working for each step.

STEP 1 — TRANSCRIBE: Read the receipt image top-to-bottom. Write out EVERY printed line exactly as it appears, including: store logo/header, product names, prices, weight lines, discount lines, totals, payment lines, dates. Do not skip any line. Do not add any line that is not on the receipt. Number each line.

STEP 2 — IDENTIFY THE STORE: Look at the LOGO and large header text at the top of the receipt. The store name is the brand/chain name shown prominently (e.g. "SuperValu", "Dunnes Stores", "Tesco", "Lidl"). Do NOT use garbled OCR text — use the recognizable brand name.

STEP 3 — CLASSIFY EACH LINE from your transcription as one of:
  [PRODUCT] — a product with a positive price on the right
  [WEIGHTED] — a weight-priced item: a product name line followed by a line like "0.332@ €13.49 €4.48" (weight @ unit_price = total). Treat as ONE item; the name is on the line above, the final_price is the rightmost amount.
  [DISCOUNT] — a line with a negative amount (e.g. -€3.50) or deal label (e.g. "SAVER DEAL", "3 FOR 10 00"). Attach it to the item(s) IMMEDIATELY BEFORE it.
  [VOUCHER] — a receipt-level discount (e.g. "RR DISCOUNT €5.00", "DISCOUNT VOUCHER €10.00"). This goes in voucher_discount, NOT as an item.
  [IGNORE] — totals, subtotals, balance, payment method, VAT, barcodes, loyalty info, header/address lines

STEP 4 — OUTPUT JSON: Convert only [PRODUCT] and [WEIGHTED] lines into this JSON (output the JSON block and nothing after it):
```json
{
  "store": "store name from step 2",
  "date": "YYYY-MM-DD or null",
  "voucher_discount": 0.00,
  "items": [
    {
      "name": "item name",
      "quantity": 1,
      "original_price": 0.00,
      "discount": 0.00,
      "final_price": 0.00
    }
  ]
}
```

Rules for the JSON:
- Prices are in euros
- Each [PRODUCT] or [WEIGHTED] line = exactly one item. Do NOT merge duplicates. Do NOT invent items.
- For [WEIGHTED] items: name is the product name (e.g. "OPEN SANDWICH"), original_price and final_price are the rightmost price on the weight line (e.g. €4.48), NOT the per-kg/unit price
- quantity is 1 unless explicitly multiplied (e.g. "2 x 1.99")
- For [DISCOUNT] lines: apply the discount to the item immediately before. original_price is the listed price, discount is the absolute value of the negative amount, final_price = original_price - discount
- For multi-buy discounts (e.g. "3 FOR 10 00 FRUIT -€3.50"): apply the full discount to the item immediately before the discount line
- voucher_discount = sum of all [VOUCHER] amounts
- Date format: YYYY-MM-DD (or null if not visible)

STEP 5 — VERIFY:
- Count your JSON items. Count your [PRODUCT]+[WEIGHTED] lines from Step 3. They MUST match.
- Check that the sum of all final_price values minus voucher_discount is close to the receipt total.
- If either check fails, go back and fix before outputting.
PROMPT;

    private const PROMPT_TEMPLATE = <<<'PROMPT'
Extract receipt data from the following OCR text. Return ONLY valid JSON, no other text.

{
  "store": "store name",
  "date": "YYYY-MM-DD or null",
  "voucher_discount": 0.00,
  "items": [
    {
      "name": "item name",
      "quantity": 1,
      "original_price": 0.00,
      "discount": 0.00,
      "final_price": 0.00
    }
  ]
}

Rules:
- "store" is the shop/chain name (e.g. "Dunnes Stores"), NOT the branch location
- Prices are in euros
- Each line with a product name and a positive price is a separate item
- If the same product appears on multiple lines, output each as a separate item with quantity 1 — do NOT merge them
- Discount/deal lines (e.g. "SAVER DEAL", negative amounts) apply to the item IMMEDIATELY BEFORE them
- For a discounted item: original_price is the listed price, discount is the absolute value of the negative amount, final_price = original_price - discount
- For items with no discount: discount = 0, final_price = original_price
- quantity is always 1 unless explicitly stated on the same line (e.g. "2 x 1.99")
- If there is a receipt-level discount voucher (e.g. "DISCOUNT VOUCHER 10.00"), set voucher_discount to that amount. Do NOT include it as an item.
- Ignore: totals, subtotals, balance lines, payment lines, VAT lines, barcodes
- Date format: YYYY-MM-DD (or null if no date found)

OCR text:
%s
PROMPT;

    public function can_parse( string $raw_text ): bool {
        if ( ! get_option( 'grt_llm_enabled', false ) ) {
            return false;
        }

        $cached = get_transient( 'grt_llm_reachable' );
        if ( false !== $cached ) {
            return (bool) $cached;
        }

        $host      = $this->get_host();
        $response  = wp_remote_get( $host . '/api/tags', array( 'timeout' => 3 ) );
        $reachable = ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response );

        set_transient( 'grt_llm_reachable', $reachable ? 1 : 0, $reachable ? 60 : 10 );

        return $reachable;
    }

    public function parse( string $raw_text ): array {
        $host  = $this->get_host();
        $model = get_option( 'grt_llm_model', 'qwen2.5:3b' );

        $prompt = sprintf( self::PROMPT_TEMPLATE, $raw_text );
        error_log( "GRT LLM Parser: OCR text:\n" . $raw_text );
        $response = wp_remote_post(
            $host . '/api/generate',
            array(
                'timeout' => 120,
                'headers' => array( 'Content-Type' => 'application/json' ),
                'body'    => wp_json_encode(
                    array(
                        'model'  => $model,
                        'prompt' => $prompt,
                        'stream' => false,
                        'format' => 'json',
                    )
                ),
            )
        );

        if ( is_wp_error( $response ) ) {
            error_log( 'GRT LLM Parser: request failed — ' . $response->get_error_message() );
            return $this->failure();
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( 200 !== $code ) {
            error_log( 'GRT LLM Parser: unexpected status ' . $code );
            return $this->failure();
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! isset( $body['response'] ) ) {
            error_log( 'GRT LLM Parser: missing response field' );
            return $this->failure();
        }

        error_log( 'GRT LLM Parser: LLM response: ' . $body['response'] );
        $data = json_decode( $body['response'], true );
        if ( ! is_array( $data ) ) {
            error_log( 'GRT LLM Parser: invalid JSON in response' );
            return $this->failure();
        }

        return $this->normalize( $data );
    }

    public function can_parse_image(): bool {
        if ( ! get_option( 'grt_llm_enabled', false ) ) {
            return false;
        }

        $provider = get_option( 'grt_llm_vision_provider', 'ollama' );

        if ( 'gemini' === $provider ) {
            return ! empty( get_option( 'grt_gemini_api_key', '' ) );
        }

        $vision_model = get_option( 'grt_llm_vision_model', 'gemma3:4b' );
        if ( empty( $vision_model ) ) {
            return false;
        }

        $cached = get_transient( 'grt_llm_reachable' );
        if ( false !== $cached ) {
            return (bool) $cached;
        }

        $host      = $this->get_host();
        $response  = wp_remote_get( $host . '/api/tags', array( 'timeout' => 3 ) );
        $reachable = ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response );

        set_transient( 'grt_llm_reachable', $reachable ? 1 : 0, $reachable ? 60 : 10 );

        return $reachable;
    }

    public function parse_image( string $image_path ): array {
        if ( ! file_exists( $image_path ) || ! is_readable( $image_path ) ) {
            error_log( 'GRT LLM Parser: image not readable — ' . $image_path );
            return $this->failure();
        }

        $provider = get_option( 'grt_llm_vision_provider', 'ollama' );

        if ( 'gemini' === $provider ) {
            return $this->parse_image_gemini( $image_path );
        }

        return $this->parse_image_ollama( $image_path );
    }

    private function parse_image_ollama( string $image_path ): array {
        $host  = $this->get_host();
        $model = get_option( 'grt_llm_vision_model', 'gemma3:4b' );

        $image_data = base64_encode( file_get_contents( $image_path ) );

        error_log( 'GRT LLM Parser: sending image to vision model ' . $model );
        $response = wp_remote_post(
            $host . '/api/generate',
            array(
                'timeout' => 120,
                'headers' => array( 'Content-Type' => 'application/json' ),
                'body'    => wp_json_encode(
                    array(
                        'model'  => $model,
                        'prompt' => self::VISION_PROMPT,
                        'images' => array( $image_data ),
                        'stream' => false,
                        'format' => 'json',
                        'options' => array(
                            'temperature' => 0,
                            'num_ctx'     => 8192,
                            'repeat_penalty' => 1.3,
                        ),
                    )
                ),
            )
        );

        if ( is_wp_error( $response ) ) {
            error_log( 'GRT LLM Parser: vision request failed — ' . $response->get_error_message() );
            return $this->failure();
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( 200 !== $code ) {
            error_log( 'GRT LLM Parser: vision unexpected status ' . $code );
            return $this->failure();
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! isset( $body['response'] ) ) {
            error_log( 'GRT LLM Parser: vision missing response field' );
            return $this->failure();
        }

        error_log( 'GRT LLM Parser: LLM response: ' . $body['response'] );
        $data = json_decode( $body['response'], true );
        if ( ! is_array( $data ) ) {
            error_log( 'GRT LLM Parser: vision invalid JSON in response' );
            return $this->failure();
        }

        $result = $this->normalize( $data );
        if ( ! empty( $result['_llm_failed'] ) ) {
            return $result;
        }

        $result['_parser'] = 'llm-vision';
        return $result;
    }

    private function parse_image_gemini( string $image_path ): array {
        $api_key = get_option( 'grt_gemini_api_key', '' );
        if ( empty( $api_key ) ) {
            error_log( 'GRT LLM Parser: Gemini API key not configured' );
            return $this->failure();
        }

        $model     = get_option( 'grt_gemini_model', 'gemini-2.5-pro' );
        $mime_type = mime_content_type( $image_path );
        $image_b64 = base64_encode( file_get_contents( $image_path ) );

        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent';

        error_log( 'GRT LLM Parser: sending image to Gemini model ' . $model );
        $response = wp_remote_post(
            $url,
            array(
                'timeout' => 120,
                'headers' => array(
                    'Content-Type'  => 'application/json',
                    'x-goog-api-key' => $api_key,
                ),
                'body'    => wp_json_encode(
                    array(
                        'contents' => array(
                            array(
                                'parts' => array(
                                    array(
                                        'inline_data' => array(
                                            'mime_type' => $mime_type,
                                            'data'      => $image_b64,
                                        ),
                                    ),
                                    array(
                                        'text' => self::GEMINI_VISION_PROMPT,
                                    ),
                                ),
                            ),
                        ),
                        'generationConfig' => array(
                            'temperature' => 0,
                        ),
                    )
                ),
            )
        );

        if ( is_wp_error( $response ) ) {
            error_log( 'GRT LLM Parser: Gemini request failed — ' . $response->get_error_message() );
            return $this->failure();
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( 200 !== $code ) {
            error_log( 'GRT LLM Parser: Gemini unexpected status ' . $code . ' — ' . wp_remote_retrieve_body( $response ) );
            return $this->failure();
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        $text = $body['candidates'][0]['content']['parts'][0]['text'] ?? null;
        if ( null === $text ) {
            error_log( 'GRT LLM Parser: Gemini missing response text' );
            return $this->failure();
        }

        error_log( 'GRT LLM Parser: Gemini response: ' . $text );

        // Extract the last JSON block from the chain-of-thought response.
        $data = $this->extract_json( $text );
        if ( null === $data ) {
            error_log( 'GRT LLM Parser: Gemini — no valid JSON found in response' );
            return $this->failure();
        }

        $result = $this->normalize( $data );
        if ( ! empty( $result['_llm_failed'] ) ) {
            return $result;
        }

        $result['_parser'] = 'llm-vision';
        return $result;
    }

    private function get_host(): string {
        return rtrim( get_option( 'grt_llm_host', 'http://host.docker.internal:11434' ), '/' );
    }

    private function normalize( array $data ): array {
        $store = isset( $data['store'] ) ? sanitize_text_field( $data['store'] ) : 'Unknown';
        $date  = isset( $data['date'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $data['date'] ) ? $data['date'] : null;
        $items = array();

        if ( ! isset( $data['items'] ) || ! is_array( $data['items'] ) ) {
            return $this->failure();
        }

        foreach ( $data['items'] as $item ) {
            if ( ! isset( $item['name'], $item['final_price'] ) ) {
                continue;
            }

            $items[] = array(
                'name'           => sanitize_text_field( $item['name'] ),
                'quantity'       => isset( $item['quantity'] ) ? (float) $item['quantity'] : 1.0,
                'original_price' => isset( $item['original_price'] ) ? (float) $item['original_price'] : (float) $item['final_price'],
                'discount'       => isset( $item['discount'] ) ? (float) $item['discount'] : 0.0,
                'final_price'    => (float) $item['final_price'],
            );
        }

        if ( empty( $items ) ) {
            return $this->failure();
        }

        $voucher_discount = isset( $data['voucher_discount'] ) ? (float) $data['voucher_discount'] : 0.0;

        return array(
            'store'            => $store,
            'date'             => $date,
            'voucher_discount' => $voucher_discount,
            'items'            => $items,
            '_parser'          => 'llm',
        );
    }

    /**
     * Extract the last valid JSON object from a chain-of-thought response.
     * Looks for ```json fenced blocks first, then falls back to bare JSON.
     */
    private function extract_json( string $text ): ?array {
        // Try fenced ```json blocks — take the last one (the final output).
        if ( preg_match_all( '/```json\s*\n(.*?)\n```/s', $text, $matches ) ) {
            $json_str = end( $matches[1] );
            $data     = json_decode( $json_str, true );
            if ( is_array( $data ) && isset( $data['items'] ) ) {
                return $data;
            }
        }

        // Try any ``` fenced block.
        if ( preg_match_all( '/```\s*\n(.*?)\n```/s', $text, $matches ) ) {
            foreach ( array_reverse( $matches[1] ) as $block ) {
                $data = json_decode( $block, true );
                if ( is_array( $data ) && isset( $data['items'] ) ) {
                    return $data;
                }
            }
        }

        // Fall back: try decoding the entire text as JSON.
        $data = json_decode( $text, true );
        if ( is_array( $data ) && isset( $data['items'] ) ) {
            return $data;
        }

        return null;
    }

    private function failure(): array {
        return array(
            'store' => '',
            'date'  => null,
            'items' => array(),
            '_llm_failed' => true,
        );
    }
}
