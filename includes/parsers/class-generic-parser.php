<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/class-parser-interface.php';

class GRT_Generic_Parser implements GRT_Parser_Interface {

    public function can_parse( string $raw_text ): bool {
        return true;
    }

    public function parse( string $raw_text ): array {
        $lines = explode( "\n", $raw_text );
        $items = array();
        $date  = null;
        $store = 'Unknown';

        // Try to extract date (formats: DD/MM/YYYY, DD-MM-YYYY, DD.MM.YYYY)
        foreach ( $lines as $line ) {
            if ( preg_match( '/(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{2,4})/', $line, $m ) ) {
                $year = strlen( $m[3] ) === 2 ? '20' . $m[3] : $m[3];
                $date = sprintf( '%s-%s-%s', $year, str_pad( $m[2], 2, '0', STR_PAD_LEFT ), str_pad( $m[1], 2, '0', STR_PAD_LEFT ) );
                break;
            }
        }

        // Use first non-empty line as store name guess.
        foreach ( $lines as $line ) {
            $trimmed = trim( $line );
            if ( ! empty( $trimmed ) && strlen( $trimmed ) > 2 ) {
                $store = $trimmed;
                break;
            }
        }

        // Extract items: look for lines with a price pattern
        foreach ( $lines as $line ) {
            $line = trim( $line );
            if ( empty( $line ) ) {
                continue;
            }

            if ( preg_match( '/^(.+?)\s+€?\s*(\d+\.\d{2})\s*$/', $line, $m ) ) {
                $name  = trim( $m[1] );
                $price = (float) $m[2];

                if ( preg_match( '/\b(total|subtotal|balance|change|cash|card|visa|mastercard|paid)\b/i', $name ) ) {
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
            'store' => $store,
            'date'  => $date,
            'items' => $items,
        );
    }
}
