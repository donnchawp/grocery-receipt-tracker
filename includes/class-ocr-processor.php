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

        if ( filesize( $image_path ) > 10 * 1024 * 1024 ) {
            return array(
                'success' => false,
                'error'   => 'Image too large for OCR (max 10MB).',
            );
        }

        $escaped_path = escapeshellarg( $image_path );
        $output       = array();
        $return_code  = 0;

        exec( "timeout 30 tesseract {$escaped_path} stdout --psm 6 2>/dev/null", $output, $return_code );

        if ( $return_code === 124 ) {
            return array(
                'success' => false,
                'error'   => 'OCR processing timed out.',
            );
        }

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
