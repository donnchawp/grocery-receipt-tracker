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
