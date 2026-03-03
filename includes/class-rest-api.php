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
