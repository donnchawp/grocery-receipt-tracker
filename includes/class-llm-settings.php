<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class GRT_LLM_Settings {

    public static function init() {
        add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
        add_action( 'admin_menu', array( __CLASS__, 'add_settings_page' ) );
    }

    public static function add_settings_page() {
        add_options_page(
            __( 'Grocery Receipt Tracker — LLM', 'grocery-receipt-tracker' ),
            __( 'Receipt Tracker LLM', 'grocery-receipt-tracker' ),
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

    public static function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        ?>
        <div class="wrap">
            <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
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
