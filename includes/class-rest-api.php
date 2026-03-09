<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class GRT_REST_API {

    /**
     * Get a prefixed custom table name.
     *
     * @param string $table Base table name (e.g. 'receipts').
     * @return string Full table name with WP prefix.
     */
    private static function table( string $table ): string {
        global $wpdb;
        return $wpdb->prefix . 'grt_' . $table;
    }

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

        // CSV Import (external tools like Claude).
        register_rest_route( 'grt/v1', '/receipts/import-csv', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'import_csv' ),
            'permission_callback' => array( __CLASS__, 'check_api_key_permission' ),
        ) );
    }

    public static function check_permission(): bool {
        return current_user_can( 'edit_posts' );
    }

    /**
     * Permission check that also accepts an API key header.
     */
    public static function check_api_key_permission( WP_REST_Request $request ): bool {
        if ( current_user_can( 'edit_posts' ) ) {
            return true;
        }

        $api_key    = $request->get_header( 'X-GRT-API-Key' );
        $stored_key = get_option( 'grt_api_key', '' );

        if ( empty( $stored_key ) || empty( $api_key ) ) {
            return false;
        }

        if ( ! hash_equals( $stored_key, $api_key ) ) {
            return false;
        }

        $user_id = (int) get_option( 'grt_api_key_user_id', 1 );
        wp_set_current_user( $user_id );

        return true;
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

        // Try vision model first (bypasses OCR entirely).
        $llm_parser = new GRT_LLM_Parser();
        if ( $llm_parser->can_parse_image() ) {
            $vision_result = $llm_parser->parse_image( $image_path );
            if ( empty( $vision_result['_llm_failed'] ) ) {
                return rest_ensure_response( array(
                    'attachment_id'    => $attachment_id,
                    'raw_text'         => '',
                    'store'            => $vision_result['store'],
                    'date'             => $vision_result['date'],
                    'voucher_discount' => $vision_result['voucher_discount'] ?? 0,
                    'items'            => $vision_result['items'],
                ) );
            }
        }

        // Fall back to Tesseract + text parsing.
        $ocr_result = GRT_OCR_Processor::process( $image_path );

        if ( ! $ocr_result['success'] ) {
            return new WP_Error( 'ocr_failed', $ocr_result['error'], array( 'status' => 500 ) );
        }

        // Parse the OCR text.
        $parser = new GRT_Receipt_Parser();
        $parsed = $parser->parse( $ocr_result['text'] );

        return rest_ensure_response( array(
            'attachment_id'    => $attachment_id,
            'raw_text'         => $ocr_result['text'],
            'store'            => $parsed['store'],
            'date'             => $parsed['date'],
            'voucher_discount' => $parsed['voucher_discount'] ?? 0,
            'items'            => $parsed['items'],
        ) );
    }

    /**
     * POST /receipts — Save reviewed receipt + items.
     */
    public static function create_receipt( WP_REST_Request $request ) {
        global $wpdb;
        $receipts_table      = self::table( 'receipts' );
        $receipt_items_table  = self::table( 'receipt_items' );
        $price_history_table = self::table( 'price_history' );

        $body = $request->get_json_params();

        $store            = sanitize_text_field( $body['store'] ?? '' );
        $receipt_date     = sanitize_text_field( $body['date'] ?? '' );
        $items            = $body['items'] ?? array();
        $raw_ocr_text     = $body['raw_text'] ?? '';
        $attachment_id    = absint( $body['attachment_id'] ?? 0 );
        $voucher_discount = (float) ( $body['voucher_discount'] ?? 0 );

        if ( empty( $store ) || empty( $receipt_date ) || empty( $items ) ) {
            return new WP_Error( 'missing_fields', 'Store, date, and items are required.', array( 'status' => 400 ) );
        }

        // Validate date format.
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $receipt_date )
            || ! checkdate( (int) substr( $receipt_date, 5, 2 ), (int) substr( $receipt_date, 8, 2 ), (int) substr( $receipt_date, 0, 4 ) ) ) {
            return new WP_Error( 'invalid_date', 'Date must be a valid YYYY-MM-DD.', array( 'status' => 400 ) );
        }

        // final_price is per-unit; multiply by quantity for line totals.
        $total = array_reduce(
            $items,
            static function ( $carry, $item ) {
                return $carry + ( (float) $item['final_price'] * (float) $item['quantity'] );
            },
            0.0
        ) - $voucher_discount;

        // Insert receipt.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $wpdb->insert(
            $receipts_table,
            array(
                'user_id'             => get_current_user_id(),
                'store'               => $store,
                'receipt_date'        => $receipt_date,
                'total'               => $total,
                'voucher_discount'    => $voucher_discount,
                'image_attachment_id' => $attachment_id ?: null,
                'raw_ocr_text'        => $raw_ocr_text,
            ),
            array( '%d', '%s', '%s', '%f', '%f', '%d', '%s' )
        );

        $receipt_id = $wpdb->insert_id;

        if ( ! $receipt_id ) {
            return new WP_Error( 'db_error', 'Failed to save receipt.', array( 'status' => 500 ) );
        }

        // Insert items.
        foreach ( $items as $item ) {
            $product_id = self::resolve_product( $item );

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $wpdb->insert(
                $receipt_items_table,
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
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
                $wpdb->insert(
                    $price_history_table,
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
        $products_table = self::table( 'products' );

        // If product_id provided (user matched existing product), use it.
        if ( ! empty( $item['product_id'] ) ) {
            return absint( $item['product_id'] );
        }

        $name = sanitize_text_field( $item['name'] ?? '' );
        if ( empty( $name ) ) {
            return null;
        }

        // Check if product already exists by canonical name.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $existing = $wpdb->get_var(
            $wpdb->prepare( 'SELECT id FROM %i WHERE canonical_name = %s', $products_table, $name )
        );

        if ( $existing ) {
            return (int) $existing;
        }

        // Create new product.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $wpdb->insert(
            $products_table,
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
     * POST /receipts/import-csv — Accept raw CSV text, parse and save.
     */
    public static function import_csv( WP_REST_Request $request ) {
        $csv = $request->get_body();

        if ( empty( trim( $csv ) ) ) {
            return new WP_Error( 'empty_body', 'No CSV data provided.', array( 'status' => 400 ) );
        }

        $parsed = self::parse_csv( $csv );

        if ( is_wp_error( $parsed ) ) {
            return $parsed;
        }

        // Reuse create_receipt via a synthetic JSON request.
        $json_request = new WP_REST_Request( 'POST' );
        $json_request->set_header( 'Content-Type', 'application/json' );
        $json_request->set_body( wp_json_encode( $parsed ) );

        return self::create_receipt( $json_request );
    }

    /**
     * Parse CSV text into receipt data array.
     *
     * @param string $text Raw CSV text.
     * @return array|WP_Error Parsed receipt data or error.
     */
    private static function parse_csv( string $text ) {
        $text  = str_replace( "\r", '', $text );
        $lines = array_values( array_filter( array_map( 'trim', explode( "\n", trim( $text ) ) ) ) );

        if ( count( $lines ) < 4 ) {
            return new WP_Error( 'invalid_csv', 'CSV must have at least 4 rows (metadata header, metadata, items header, item).', array( 'status' => 400 ) );
        }

        // Row 0: metadata headers.
        $meta_headers = array_map( 'strtolower', array_map( 'trim', explode( ',', $lines[0] ) ) );

        if ( ( $meta_headers[0] ?? '' ) !== 'store' || ( $meta_headers[1] ?? '' ) !== 'date' || ( $meta_headers[2] ?? '' ) !== 'voucher_discount' ) {
            return new WP_Error( 'invalid_csv', 'First row must be: store,date,voucher_discount', array( 'status' => 400 ) );
        }

        // Row 1: metadata values.
        $meta_values      = array_map( 'trim', explode( ',', $lines[1] ) );
        $store            = $meta_values[0] ?? '';
        $date             = $meta_values[1] ?? '';
        $voucher_discount = (float) ( $meta_values[2] ?? 0 );

        if ( empty( $store ) ) {
            return new WP_Error( 'invalid_csv', 'Store name is empty.', array( 'status' => 400 ) );
        }

        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
            return new WP_Error( 'invalid_csv', 'Date must be YYYY-MM-DD format.', array( 'status' => 400 ) );
        }

        // Row 2: item headers.
        $item_headers = array_map( 'strtolower', array_map( 'trim', explode( ',', $lines[2] ) ) );

        if ( ( $item_headers[0] ?? '' ) !== 'name' || ( $item_headers[1] ?? '' ) !== 'quantity' || ( $item_headers[2] ?? '' ) !== 'price' || ( $item_headers[3] ?? '' ) !== 'discount' ) {
            return new WP_Error( 'invalid_csv', 'Third row must be: name,quantity,price,discount', array( 'status' => 400 ) );
        }

        // Rows 3+: items.
        $items = array();
        $count = count( $lines );

        for ( $i = 3; $i < $count; $i++ ) {
            $parts = array_map( 'trim', explode( ',', $lines[ $i ] ) );

            if ( count( $parts ) < 4 ) {
                return new WP_Error( 'invalid_csv', sprintf( 'Row %d: expected at least 4 columns.', $i + 1 ), array( 'status' => 400 ) );
            }

            $name           = $parts[0];
            $quantity       = (float) $parts[1];
            $original_price = (float) $parts[2];
            $discount       = (float) $parts[3];

            if ( empty( $name ) ) {
                return new WP_Error( 'invalid_csv', sprintf( 'Row %d: item name is empty.', $i + 1 ), array( 'status' => 400 ) );
            }

            // Discount in CSV is line-level; convert to per-unit to match frontend convention.
            $per_unit_discount = $quantity > 0 ? $discount / $quantity : $discount;

            $items[] = array(
                'name'           => $name,
                'quantity'       => $quantity,
                'original_price' => $original_price,
                'discount'       => $per_unit_discount,
                'final_price'    => $original_price - $per_unit_discount,
            );
        }

        return array(
            'store'            => $store,
            'date'             => $date,
            'voucher_discount' => $voucher_discount,
            'items'            => $items,
            'raw_text'         => $text,
            'attachment_id'    => 0,
        );
    }

    /**
     * GET /receipts — List receipts (paginated).
     */
    public static function list_receipts( WP_REST_Request $request ) {
        global $wpdb;
        $receipts_table = self::table( 'receipts' );

        $page     = $request->get_param( 'page' );
        $per_page = $request->get_param( 'per_page' );
        $store    = $request->get_param( 'store' );
        $offset   = ( $page - 1 ) * $per_page;

        $query        = 'SELECT COUNT(*) FROM %i WHERE user_id = %d';
        $prepare_args = array( $receipts_table, get_current_user_id() );

        if ( $store ) {
            $query         .= ' AND store = %s';
            $prepare_args[] = $store;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- $query contains only placeholders (%i, %d, %s), not user input.
        $total = $wpdb->get_var(
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $wpdb->prepare( $query, ...$prepare_args )
        );

        $results_query        = 'SELECT id, store, receipt_date, total, voucher_discount, image_attachment_id, created_at FROM %i WHERE user_id = %d';
        $results_prepare_args = array( $receipts_table, get_current_user_id() );

        if ( $store ) {
            $results_query         .= ' AND store = %s';
            $results_prepare_args[] = $store;
        }

        $results_query         .= ' ORDER BY receipt_date DESC LIMIT %d OFFSET %d';
        $results_prepare_args[] = $per_page;
        $results_prepare_args[] = $offset;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- $results_query contains only placeholders (%i, %d, %s), not user input.
        $receipts = $wpdb->get_results(
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $wpdb->prepare( $results_query, ...$results_prepare_args )
        );

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
        $receipts_table      = self::table( 'receipts' );
        $receipt_items_table  = self::table( 'receipt_items' );
        $products_table      = self::table( 'products' );
        $id                  = absint( $request['id'] );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $receipt = $wpdb->get_row(
            $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d AND user_id = %d', $receipts_table, $id, get_current_user_id() )
        );

        if ( ! $receipt ) {
            return new WP_Error( 'not_found', 'Receipt not found.', array( 'status' => 404 ) );
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $items = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT ri.*, p.canonical_name, p.brand, p.category FROM %i ri LEFT JOIN %i p ON ri.product_id = p.id WHERE ri.receipt_id = %d',
                $receipt_items_table,
                $products_table,
                $id
            )
        );

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
        $receipts_table      = self::table( 'receipts' );
        $receipt_items_table  = self::table( 'receipt_items' );
        $price_history_table = self::table( 'price_history' );
        $id                  = absint( $request['id'] );

        // Verify ownership.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $receipt = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT id, image_attachment_id FROM %i WHERE id = %d AND user_id = %d',
                $receipts_table,
                $id,
                get_current_user_id()
            )
        );

        if ( ! $receipt ) {
            return new WP_Error( 'not_found', 'Receipt not found.', array( 'status' => 404 ) );
        }

        // Delete price history entries for this receipt's items.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query(
            $wpdb->prepare(
                'DELETE ph FROM %i ph INNER JOIN %i ri ON ph.receipt_item_id = ri.id WHERE ri.receipt_id = %d',
                $price_history_table,
                $receipt_items_table,
                $id
            )
        );

        // Delete receipt items.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->delete( $receipt_items_table, array( 'receipt_id' => $id ), array( '%d' ) );

        // Delete receipt.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->delete( $receipts_table, array( 'id' => $id ), array( '%d' ) );

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
        $products_table = self::table( 'products' );

        $search   = $request->get_param( 'search' );
        $category = $request->get_param( 'category' );

        $query        = 'SELECT * FROM %i WHERE 1=1';
        $prepare_args = array( $products_table );

        if ( $search ) {
            $query         .= ' AND canonical_name LIKE %s';
            $prepare_args[] = '%' . $wpdb->esc_like( $search ) . '%';
        }

        if ( $category ) {
            $query         .= ' AND category = %s';
            $prepare_args[] = $category;
        }

        $query .= ' ORDER BY canonical_name ASC LIMIT 100';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- $query contains only placeholders (%i, %s), not user input.
        $products = $wpdb->get_results(
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $wpdb->prepare( $query, ...$prepare_args )
        );

        return rest_ensure_response( $products );
    }

    /**
     * PUT /products/{id}
     */
    public static function update_product( WP_REST_Request $request ) {
        global $wpdb;
        $products_table = self::table( 'products' );
        $id             = absint( $request['id'] );
        $body           = $request->get_json_params();

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

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->update( $products_table, $data, array( 'id' => $id ), $format, array( '%d' ) );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $product = $wpdb->get_row(
            $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $products_table, $id )
        );

        return rest_ensure_response( $product );
    }

    /**
     * GET /products/{id}/price-history
     */
    public static function get_price_history( WP_REST_Request $request ) {
        global $wpdb;
        $price_history_table = self::table( 'price_history' );
        $id                  = absint( $request['id'] );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $history = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT price_date, store, final_price FROM %i WHERE product_id = %d ORDER BY price_date ASC',
                $price_history_table,
                $id
            )
        );

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
        $price_history_table = self::table( 'price_history' );
        $products_table      = self::table( 'products' );
        $category            = sanitize_text_field( $request['category'] );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $trends = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT ph.price_date, AVG(ph.final_price) as avg_price, COUNT(*) as item_count FROM %i ph INNER JOIN %i p ON ph.product_id = p.id WHERE p.category = %s GROUP BY ph.price_date ORDER BY ph.price_date ASC',
                $price_history_table,
                $products_table,
                $category
            )
        );

        return rest_ensure_response( array(
            'category' => $category,
            'trends'   => $trends,
        ) );
    }
}
