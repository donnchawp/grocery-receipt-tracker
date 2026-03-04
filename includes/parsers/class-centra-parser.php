<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/class-parser-interface.php';

class GRT_Centra_Parser implements GRT_Parser_Interface {

    public function can_parse( string $raw_text ): bool {
        return (bool) preg_match( '/\bcentra\b/i', $raw_text );
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

        $skip_patterns = '/\b(total|subtotal|balance|change|cash|card|visa|mastercard|paid|vat|tax)\b/i';

        foreach ( $lines as $line ) {
            $line = trim( $line );
            if ( empty( $line ) ) {
                continue;
            }

            if ( preg_match( '/^(.+?)\s+€?\s*(\d+\.\d{2})\s*$/', $line, $m ) ) {
                $name  = trim( $m[1] );
                $price = (float) $m[2];

                if ( preg_match( $skip_patterns, $name ) ) {
                    continue;
                }

                $items[] = array(
                    'name'           => $name,
                    'quantity'       => 1.0,
                    'original_price' => $price,
                    'discount'       => 0.0,
                    'final_price'    => $price,
                );
            }
        }

        return array(
            'store' => 'Centra',
            'date'  => $date,
            'items' => $items,
        );
    }
}
