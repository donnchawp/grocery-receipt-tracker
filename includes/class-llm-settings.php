<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class GRT_LLM_Settings {

    public static function init() {
        add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
        add_action( 'admin_menu', array( __CLASS__, 'add_settings_page' ) );
        add_action( 'admin_post_grt_generate_api_key', array( __CLASS__, 'generate_api_key' ) );
    }

    public static function add_settings_page() {
        add_options_page(
            __( 'Grocery Receipt Tracker Settings', 'grocery-receipt-tracker' ),
            __( 'Receipt Tracker', 'grocery-receipt-tracker' ),
            'manage_options',
            'grt-llm-settings',
            array( __CLASS__, 'render_page' )
        );
    }

    public static function register_settings() {
        register_setting( 'grt_llm', 'grt_llm_enabled', array(
            'type'              => 'boolean',
            'default'           => false,
            'sanitize_callback' => static function ( $value ) {
                delete_transient( 'grt_llm_reachable' );
                return rest_sanitize_boolean( $value );
            },
        ) );

        register_setting( 'grt_llm', 'grt_llm_host', array(
            'type'              => 'string',
            'default'           => 'http://host.docker.internal:11434',
            'sanitize_callback' => array( __CLASS__, 'sanitize_host' ),
        ) );

        register_setting( 'grt_llm', 'grt_llm_model', array(
            'type'              => 'string',
            'default'           => 'qwen2.5:3b',
            'sanitize_callback' => 'sanitize_text_field',
        ) );

        register_setting( 'grt_llm', 'grt_llm_vision_model', array(
            'type'              => 'string',
            'default'           => 'gemma3:4b',
            'sanitize_callback' => 'sanitize_text_field',
        ) );

        register_setting( 'grt_llm', 'grt_llm_vision_provider', array(
            'type'              => 'string',
            'default'           => 'ollama',
            'sanitize_callback' => static function ( $value ) {
                return in_array( $value, array( 'ollama', 'gemini' ), true ) ? $value : 'ollama';
            },
        ) );

        register_setting( 'grt_llm', 'grt_gemini_api_key', array(
            'type'              => 'string',
            'default'           => '',
            'sanitize_callback' => 'sanitize_text_field',
        ) );

        register_setting( 'grt_llm', 'grt_gemini_model', array(
            'type'              => 'string',
            'default'           => 'gemini-2.5-pro',
            'sanitize_callback' => 'sanitize_text_field',
        ) );

        add_settings_section(
            'grt_llm_section',
            __( 'Ollama LLM Parser', 'grocery-receipt-tracker' ),
            array( __CLASS__, 'render_section' ),
            'grt-llm-settings'
        );

        add_settings_field( 'grt_llm_enabled', __( 'Enable LLM parser', 'grocery-receipt-tracker' ), array( __CLASS__, 'render_enabled_field' ), 'grt-llm-settings', 'grt_llm_section' );
        add_settings_field( 'grt_llm_host', __( 'Ollama host URL', 'grocery-receipt-tracker' ), array( __CLASS__, 'render_host_field' ), 'grt-llm-settings', 'grt_llm_section' );
        add_settings_field( 'grt_llm_model', __( 'Model name', 'grocery-receipt-tracker' ), array( __CLASS__, 'render_model_field' ), 'grt-llm-settings', 'grt_llm_section' );
        add_settings_field( 'grt_llm_vision_model', __( 'Vision model name', 'grocery-receipt-tracker' ), array( __CLASS__, 'render_vision_model_field' ), 'grt-llm-settings', 'grt_llm_section' );
        add_settings_field( 'grt_llm_vision_provider', __( 'Vision provider', 'grocery-receipt-tracker' ), array( __CLASS__, 'render_vision_provider_field' ), 'grt-llm-settings', 'grt_llm_section' );
        add_settings_field( 'grt_gemini_api_key', __( 'Gemini API key', 'grocery-receipt-tracker' ), array( __CLASS__, 'render_gemini_api_key_field' ), 'grt-llm-settings', 'grt_llm_section' );
        add_settings_field( 'grt_gemini_model', __( 'Gemini model', 'grocery-receipt-tracker' ), array( __CLASS__, 'render_gemini_model_field' ), 'grt-llm-settings', 'grt_llm_section' );

        // CSV Import API section.
        add_settings_section(
            'grt_api_section',
            __( 'CSV Import API', 'grocery-receipt-tracker' ),
            array( __CLASS__, 'render_api_section' ),
            'grt-llm-settings'
        );

        add_settings_field( 'grt_api_key_display', __( 'API Key', 'grocery-receipt-tracker' ), array( __CLASS__, 'render_api_key_field' ), 'grt-llm-settings', 'grt_api_section' );
        add_settings_field( 'grt_scan_receipt', __( 'Scan Receipt (Claude Code)', 'grocery-receipt-tracker' ), array( __CLASS__, 'render_scan_receipt_field' ), 'grt-llm-settings', 'grt_api_section' );
        add_settings_field( 'grt_claude_prompt', __( 'Manual Prompt (any AI chat)', 'grocery-receipt-tracker' ), array( __CLASS__, 'render_prompt_field' ), 'grt-llm-settings', 'grt_api_section' );
    }

    public static function render_section() {
        echo '<p>' . esc_html__( 'Use a local Ollama LLM to parse receipt text instead of regex-based parsers. Ollama must be running and accessible from the WordPress container.', 'grocery-receipt-tracker' ) . '</p>';
    }

    public static function render_enabled_field() {
        $value = get_option( 'grt_llm_enabled', false );
        echo '<label><input type="checkbox" name="grt_llm_enabled" value="1" ' . checked( $value, true, false ) . ' /> '
            . esc_html__( 'Enable', 'grocery-receipt-tracker' ) . '</label>';
    }

    public static function render_host_field() {
        $value = get_option( 'grt_llm_host', 'http://host.docker.internal:11434' );
        echo '<input type="url" name="grt_llm_host" value="' . esc_attr( $value ) . '" class="regular-text" />';
        echo '<p class="description">' . esc_html__( 'Default: http://host.docker.internal:11434 (Docker host)', 'grocery-receipt-tracker' ) . '</p>';
    }

    public static function render_model_field() {
        $value = get_option( 'grt_llm_model', 'qwen2.5:3b' );
        echo '<input type="text" name="grt_llm_model" value="' . esc_attr( $value ) . '" class="regular-text" />';
        echo '<p class="description">' . esc_html__( 'Default: qwen2.5:3b', 'grocery-receipt-tracker' ) . '</p>';
    }

    public static function render_vision_model_field() {
        $value = get_option( 'grt_llm_vision_model', 'gemma3:4b' );
        echo '<input type="text" name="grt_llm_vision_model" value="' . esc_attr( $value ) . '" class="regular-text" />';
        echo '<p class="description">' . esc_html__( 'Vision-capable model for direct image parsing. Default: gemma3:4b. Leave empty to disable.', 'grocery-receipt-tracker' ) . '</p>';
    }

    public static function render_vision_provider_field() {
        $value = get_option( 'grt_llm_vision_provider', 'ollama' );
        echo '<select name="grt_llm_vision_provider">';
        echo '<option value="ollama" ' . selected( $value, 'ollama', false ) . '>' . esc_html__( 'Ollama (local)', 'grocery-receipt-tracker' ) . '</option>';
        echo '<option value="gemini" ' . selected( $value, 'gemini', false ) . '>' . esc_html__( 'Google Gemini', 'grocery-receipt-tracker' ) . '</option>';
        echo '</select>';
        echo '<p class="description">' . esc_html__( 'Which vision API to use for direct image parsing.', 'grocery-receipt-tracker' ) . '</p>';
    }

    public static function render_gemini_api_key_field() {
        $value = get_option( 'grt_gemini_api_key', '' );
        echo '<input type="password" name="grt_gemini_api_key" value="' . esc_attr( $value ) . '" class="regular-text" />';
        echo '<p class="description">' . esc_html__( 'Get a free API key from Google AI Studio.', 'grocery-receipt-tracker' ) . '</p>';
    }

    public static function render_gemini_model_field() {
        $value = get_option( 'grt_gemini_model', 'gemini-2.5-pro' );
        echo '<input type="text" name="grt_gemini_model" value="' . esc_attr( $value ) . '" class="regular-text" />';
        echo '<p class="description">' . esc_html__( 'Default: gemini-2.5-flash', 'grocery-receipt-tracker' ) . '</p>';
    }

    public static function sanitize_host( string $value ): string {
        $value = esc_url_raw( $value );
        $host  = wp_parse_url( $value, PHP_URL_HOST );

        if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
            add_settings_error( 'grt_llm_host', 'grt_llm_host_ip', __( 'IP addresses are not allowed for the Ollama host; use a hostname.', 'grocery-receipt-tracker' ) );
            return get_option( 'grt_llm_host', 'http://host.docker.internal:11434' );
        }

        delete_transient( 'grt_llm_reachable' );
        return $value;
    }

    public static function render_api_section() {
        echo '<p>' . esc_html__( 'Allow external tools (like Claude) to submit receipts via the REST API.', 'grocery-receipt-tracker' ) . '</p>';
    }

    public static function render_api_key_field() {
        $api_key = get_option( 'grt_api_key', '' );

        if ( $api_key ) {
            echo '<code style="font-size:14px;padding:4px 8px;background:#f0f0f0;">' . esc_html( $api_key ) . '</code>';
        } else {
            echo '<em>' . esc_html__( 'No API key generated yet.', 'grocery-receipt-tracker' ) . '</em>';
        }

        $url   = wp_nonce_url( admin_url( 'admin-post.php?action=grt_generate_api_key' ), 'grt_generate_api_key' );
        $label = $api_key
            ? __( 'Regenerate API Key', 'grocery-receipt-tracker' )
            : __( 'Generate API Key', 'grocery-receipt-tracker' );

        echo '<br><br>';
        echo '<a href="' . esc_url( $url ) . '" class="button"';
        if ( $api_key ) {
            echo ' onclick="return confirm(\'' . esc_js( __( 'Generate a new API key? The old key will stop working.', 'grocery-receipt-tracker' ) ) . '\');"';
        }
        echo '>' . esc_html( $label ) . '</a>';
        echo '<p class="description">' . esc_html__( 'Used to authenticate CSV import requests from external tools.', 'grocery-receipt-tracker' ) . '</p>';

        if ( $api_key ) {
            $env_config = 'GRT_API_URL=' . home_url() . "\nGRT_API_KEY=" . $api_key;
            echo '<br><p><strong>' . esc_html__( 'Claude Code env-config.txt', 'grocery-receipt-tracker' ) . '</strong></p>';
            echo '<textarea id="grt-env-config" readonly rows="2" class="large-text code" style="font-family:monospace;font-size:12px;max-width:400px;">'
                . esc_textarea( $env_config )
                . '</textarea>';
            echo '<p><button type="button" class="button" onclick="'
                . 'navigator.clipboard.writeText(document.getElementById(\'grt-env-config\').value);'
                . 'var b=this;b.textContent=\'' . esc_js( __( 'Copied!', 'grocery-receipt-tracker' ) ) . '\';'
                . 'setTimeout(function(){b.textContent=\'' . esc_js( __( 'Copy', 'grocery-receipt-tracker' ) ) . '\'},2000);">'
                . esc_html__( 'Copy', 'grocery-receipt-tracker' )
                . '</button> '
                . '<span class="description">' . esc_html__( 'Save as env-config.txt in the project root for /scan-receipt', 'grocery-receipt-tracker' ) . '</span>'
                . '</p>';
        }
    }

    public static function render_scan_receipt_field() {
        $api_key = get_option( 'grt_api_key', '' );

        echo '<p>' . esc_html__( 'If you use Claude Code, the /scan-receipt command is the fastest way to import receipts. It reads the image, extracts items, shows a confirmation table, and submits directly.', 'grocery-receipt-tracker' ) . '</p>';

        echo '<p><strong>' . esc_html__( 'Setup:', 'grocery-receipt-tracker' ) . '</strong></p>';
        echo '<ol>';
        echo '<li>' . esc_html__( 'Generate an API key above (if you haven\'t already)', 'grocery-receipt-tracker' ) . '</li>';
        echo '<li>' . esc_html__( 'Copy the env-config.txt content above and save it as env-config.txt in the project root', 'grocery-receipt-tracker' ) . '</li>';
        echo '<li>' . esc_html__( 'Open Claude Code in the project directory', 'grocery-receipt-tracker' ) . '</li>';
        echo '</ol>';

        echo '<p><strong>' . esc_html__( 'Usage:', 'grocery-receipt-tracker' ) . '</strong></p>';
        echo '<code style="display:block;padding:8px 12px;background:#f0f0f0;margin-bottom:8px;font-size:13px;">'
            . esc_html( '/scan-receipt path/to/receipt.jpg' )
            . '</code>';
        echo '<p class="description">' . esc_html__( 'Or paste/drop a receipt image into the terminal first, then run /scan-receipt without arguments.', 'grocery-receipt-tracker' ) . '</p>';

        if ( ! $api_key ) {
            echo '<p class="description" style="color:#d63638;">'
                . esc_html__( 'Generate an API key first to use this feature.', 'grocery-receipt-tracker' )
                . '</p>';
        }
    }

    public static function render_prompt_field() {
        $api_key     = get_option( 'grt_api_key', '' );
        $site_url    = home_url();
        $key_display = $api_key ? $api_key : 'YOUR_API_KEY';

        $prompt = 'Parse this grocery receipt image into CSV with exactly this format:

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

After parsing, output a curl command to submit the receipt:

curl -X POST \'' . $site_url . '/wp-json/grt/v1/receipts/import-csv\' \\
  -H \'Content-Type: text/plain\' \\
  -H \'X-GRT-API-Key: ' . $key_display . '\' \\
  -d \'<the full CSV output>\'';

        echo '<textarea id="grt-claude-prompt" readonly rows="25" class="large-text code" style="font-family:monospace;font-size:12px;">'
            . esc_textarea( $prompt )
            . '</textarea>';
        echo '<p><button type="button" class="button" onclick="'
            . 'navigator.clipboard.writeText(document.getElementById(\'grt-claude-prompt\').value);'
            . 'var b=this;b.textContent=\'' . esc_js( __( 'Copied!', 'grocery-receipt-tracker' ) ) . '\';'
            . 'setTimeout(function(){b.textContent=\'' . esc_js( __( 'Copy Prompt', 'grocery-receipt-tracker' ) ) . '\'},2000);">'
            . esc_html__( 'Copy Prompt', 'grocery-receipt-tracker' )
            . '</button></p>';

        if ( ! $api_key ) {
            echo '<p class="description" style="color:#d63638;">'
                . esc_html__( 'Generate an API key above first — the prompt contains a placeholder.', 'grocery-receipt-tracker' )
                . '</p>';
        }
    }

    public static function generate_api_key() {
        check_admin_referer( 'grt_generate_api_key' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Unauthorized.', 'grocery-receipt-tracker' ) );
        }

        $key = wp_generate_password( 32, false );
        update_option( 'grt_api_key', $key );
        update_option( 'grt_api_key_user_id', get_current_user_id() );

        wp_safe_redirect( admin_url( 'options-general.php?page=grt-llm-settings&api-key-generated=1' ) );
        exit;
    }

    public static function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        ?>
        <div class="wrap">
            <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
            <?php
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            if ( isset( $_GET['api-key-generated'] ) ) {
                echo '<div class="notice notice-success is-dismissible"><p>'
                    . esc_html__( 'API key generated successfully.', 'grocery-receipt-tracker' )
                    . '</p></div>';
            }
            ?>
            <form action="options.php" method="post">
                <?php
                settings_fields( 'grt_llm' );
                do_settings_sections( 'grt-llm-settings' );
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }
}
