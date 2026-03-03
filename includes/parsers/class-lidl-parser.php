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
