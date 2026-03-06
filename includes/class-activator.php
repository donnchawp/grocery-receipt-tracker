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
            voucher_discount decimal(10,2) NOT NULL DEFAULT 0,
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
