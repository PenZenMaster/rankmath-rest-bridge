<?php
/**
 * PHPUnit bootstrap.
 *
 * Defines the minimal WordPress stubs required to load rankmath-rest-bridge.php
 * outside of a real WP environment. Only top-level functions called at file-load
 * time need to be stubbed here; functions used inside REST handlers are not
 * called by unit tests and can be added on demand.
 */

define( 'ABSPATH', dirname( __DIR__ ) . '/' );

// ------------------------------------------------------------------
// Core WP stubs (load-time only)
// ------------------------------------------------------------------

if ( ! function_exists( 'plugin_dir_path' ) ) {
    function plugin_dir_path( $file ) {
        return rtrim( dirname( $file ), '/\\' ) . '/';
    }
}

if ( ! function_exists( 'add_action' ) ) {
    function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {}
}

if ( ! function_exists( 'add_filter' ) ) {
    function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {}
}

// apply_filters: return the value unchanged so allowlist constants pass through.
if ( ! function_exists( 'apply_filters' ) ) {
    function apply_filters( $hook, $value ) {
        return $value;
    }
}

// get_post: default stub returns null (post not found).
// Override per test with a $GLOBALS helper — see SeoValidationTest.
if ( ! function_exists( 'get_post' ) ) {
    function get_post( $post_id ) {
        return $GLOBALS['_test_posts'][ $post_id ] ?? null;
    }
}

if ( ! class_exists( 'WP_Error' ) ) {
    class WP_Error {
        public $code;
        public $message;
        public $data;

        public function __construct( $code = '', $message = '', $data = '' ) {
            $this->code    = $code;
            $this->message = $message;
            $this->data    = $data;
        }

        public function get_error_message() {
            return $this->message;
        }
    }
}

if ( ! function_exists( 'is_wp_error' ) ) {
    function is_wp_error( $thing ) {
        return $thing instanceof WP_Error;
    }
}

// ------------------------------------------------------------------
// Load the plugin (defines all constants and functions under test)
// ------------------------------------------------------------------
require dirname( __DIR__ ) . '/rankmath-rest-bridge.php';
