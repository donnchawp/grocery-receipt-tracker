<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/class-parser-interface.php';

class GRT_Dunnes_Parser implements GRT_Parser_Interface {

    public function can_parse( string $raw_text ): bool {
        return (bool) preg_match( '/dunnes\s*stores?/i', $raw_text );
    }

    public function parse( string $raw_text ): array {
        $lines = explode( "\n", $raw_text );
        $items = array();
        $date  = null;

        // Extract date
        foreach ( $lines as $line ) {
            if ( preg_match( '/(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{2,4})/', $line, $m ) ) {
                $year = strlen( $m[3] ) === 2 ? '20' . $m[3] : $m[3];
                $date = sprintf( '%s-%s-%s', $year, str_pad( $m[2], 2, '0', STR_PAD_LEFT ), str_pad( $m[1], 2, '0', STR_PAD_LEFT ) );
                break;
            }
        }

        $skip_patterns = '/\b(total|subtotal|balance|change|cash|card|visa|mastercard|paid|vat|tax|clubcard|points|saving)\b/i';

        foreach ( $lines as $line ) {
            $line = trim( $line );
            if ( empty( $line ) ) {
                continue;
            }

            // Dunnes format: ITEM NAME    €X.XX
            if ( preg_match( '/^(.+?)\s+€?\s*(\d+\.\d{2})\s*$/', $line, $m ) ) {
                $name  = trim( $m[1] );
                $price = (float) $m[2];

                if ( preg_match( $skip_patterns, $name ) ) {
                    continue;
                }

                // Check for discount on next/same line (negative or with minus)
                $discount = 0.0;
                if ( preg_match( '/-\s*€?\s*(\d+\.\d{2})/', $line, $dm ) ) {
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

            // Handle quantity lines: "2 @ €1.50"
            if ( preg_match( '/^(\d+)\s*[@xX]\s*€?\s*(\d+\.\d{2})/', $line, $qm ) ) {
                $qty        = (int) $qm[1];
                $unit_price = (float) $qm[2];
                // This modifies the last item if it exists
                if ( ! empty( $items ) ) {
                    $last_idx = count( $items ) - 1;
                    $items[ $last_idx ]['quantity']       = (float) $qty;
                    $items[ $last_idx ]['original_price'] = $unit_price * $qty;
                    $items[ $last_idx ]['final_price']    = $items[ $last_idx ]['original_price'] - $items[ $last_idx ]['discount'];
                }
            }
        }

        return array(
            'store' => 'Dunnes Stores',
            'date'  => $date,
            'items' => $items,
        );
    }
}
