<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/parsers/class-parser-interface.php';
require_once __DIR__ . '/parsers/class-generic-parser.php';

class GRT_Receipt_Parser {

    /** @var GRT_Parser_Interface[] */
    private array $parsers = array();

    public function __construct() {
        $this->load_parsers();
    }

    private function load_parsers() {
        $parser_dir   = GRT_PLUGIN_DIR . 'includes/parsers/';
        $parser_files = glob( $parser_dir . 'class-*-parser.php' );

        foreach ( $parser_files as $file ) {
            $basename = basename( $file, '.php' );
            if ( in_array( $basename, array( 'class-parser-interface', 'class-generic-parser' ), true ) ) {
                continue;
            }

            require_once $file;

            $class_name = str_replace( 'class-', '', $basename );
            $class_name = str_replace( '-', '_', $class_name );
            $class_name = 'GRT_' . implode( '_', array_map( 'ucfirst', explode( '_', $class_name ) ) );

            if ( class_exists( $class_name ) ) {
                $this->parsers[] = new $class_name();
            }
        }

        $this->parsers[] = new GRT_Generic_Parser();
    }

    public function parse( string $raw_text ): array {
        foreach ( $this->parsers as $parser ) {
            if ( $parser->can_parse( $raw_text ) ) {
                return $parser->parse( $raw_text );
            }
        }

        return ( new GRT_Generic_Parser() )->parse( $raw_text );
    }
}
