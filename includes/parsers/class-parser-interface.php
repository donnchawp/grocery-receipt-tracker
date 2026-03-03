<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

interface GRT_Parser_Interface {

    /**
     * Check if this parser can handle the given OCR text.
     *
     * @param string $raw_text Raw OCR text from Tesseract.
     * @return bool
     */
    public function can_parse( string $raw_text ): bool;

    /**
     * Parse raw OCR text into structured receipt data.
     *
     * @param string $raw_text Raw OCR text from Tesseract.
     * @return array{
     *     store: string,
     *     date: string|null,
     *     items: array,
     * }
     */
    public function parse( string $raw_text ): array;
}
