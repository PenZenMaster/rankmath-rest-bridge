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

// WP_Post — extends stdClass so properties can be set dynamically in tests.
if ( ! class_exists( 'WP_Post' ) ) {
    class WP_Post extends stdClass {}
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
// Additional stubs for unit tests that call helper functions directly
// ------------------------------------------------------------------

// is_admin — always false in test context; admin classes are not loaded.
if ( ! function_exists( 'is_admin' ) ) {
    function is_admin() {
        return false;
    }
}

// is_singular / get_queried_object_id — configured per test via $GLOBALS.
if ( ! function_exists( 'is_singular' ) ) {
    function is_singular( $post_types = '' ) {
        return $GLOBALS['_test_is_singular'] ?? false;
    }
}

if ( ! function_exists( 'get_queried_object_id' ) ) {
    function get_queried_object_id() {
        return $GLOBALS['_test_queried_object_id'] ?? 0;
    }
}

// get_post_meta — returns values from $GLOBALS['_test_post_meta'][$post_id][$key].
if ( ! function_exists( 'get_post_meta' ) ) {
    function get_post_meta( $post_id, $key, $single = false ) {
        $val = $GLOBALS['_test_post_meta'][ $post_id ][ $key ] ?? null;
        if ( $single ) return $val !== null ? $val : '';
        return $val !== null ? [ $val ] : [];
    }
}

// get_bloginfo — returns minimal test values.
if ( ! function_exists( 'get_bloginfo' ) ) {
    function get_bloginfo( $show = '', $filter = 'raw' ) {
        $map = [
            'name'        => 'Test Site',
            'description' => 'Test Tagline',
            'url'         => 'https://example.test',
            'version'     => '6.7',
        ];
        return $map[ $show ] ?? '';
    }
}

// get_the_title — returns the post's post_title property.
if ( ! function_exists( 'get_the_title' ) ) {
    function get_the_title( $post = null ) {
        if ( is_object( $post ) ) return $post->post_title ?? '';
        return '';
    }
}

// wp_trim_words — simple PHP fallback.
if ( ! function_exists( 'wp_trim_words' ) ) {
    function wp_trim_words( $text, $num_words = 55, $more = null ) {
        $words = explode( ' ', strip_tags( $text ) );
        if ( count( $words ) <= $num_words ) return $text;
        return implode( ' ', array_slice( $words, 0, $num_words ) ) . ( $more ?? '&hellip;' );
    }
}

// ------------------------------------------------------------------
// Additional stubs for canonical URL set and discovery helpers (P0)
// ------------------------------------------------------------------

// get_posts — returns from $GLOBALS['_test_posts_list']; supports 'name', 'post_type'
// and 'numberposts' filtering. Seed $GLOBALS['_test_posts_list'] in setUp().
if ( ! function_exists( 'get_posts' ) ) {
    function get_posts( $args = array() ) {
        $list      = $GLOBALS['_test_posts_list'] ?? array();
        $post_type = $args['post_type'] ?? 'post';
        $name      = $args['name'] ?? null;
        $status    = $args['post_status'] ?? 'publish';

        $filtered = array_filter(
            $list,
            function ( $p ) use ( $post_type, $name, $status ) {
                if ( $p->post_type !== $post_type ) {
                    return false;
                }
                if ( $p->post_status !== $status ) {
                    return false;
                }
                if ( null !== $name && $p->post_name !== $name ) {
                    return false;
                }
                return true;
            }
        );

        $filtered     = array_values( $filtered );
        $numberposts  = isset( $args['numberposts'] ) ? (int) $args['numberposts'] : -1;
        if ( $numberposts > 0 ) {
            $filtered = array_slice( $filtered, 0, $numberposts );
        }
        return $filtered;
    }
}

// get_pages — returns from $GLOBALS['_test_pages_list'].
// Seed $GLOBALS['_test_pages_list'] with page stdClass objects in setUp().
if ( ! function_exists( 'get_pages' ) ) {
    function get_pages( $args = array() ) {
        $status = $args['post_status'] ?? 'publish';
        return array_values(
            array_filter(
                $GLOBALS['_test_pages_list'] ?? array(),
                function ( $p ) use ( $status ) {
                    return $p->post_status === $status;
                }
            )
        );
    }
}

// get_option — returns from $GLOBALS['_test_options'][$key] or $default.
if ( ! function_exists( 'get_option' ) ) {
    function get_option( $key, $default = false ) {
        $options = $GLOBALS['_test_options'] ?? array();
        return array_key_exists( $key, $options ) ? $options[ $key ] : $default;
    }
}

// mysql2date — pure PHP DateTime conversion; mirrors WP behaviour for ISO 8601.
if ( ! function_exists( 'mysql2date' ) ) {
    function mysql2date( $format, $date, $translate = true ) {
        if ( '' === $date || '0000-00-00 00:00:00' === $date ) {
            return false;
        }
        try {
            $dt = new DateTime( $date, new DateTimeZone( 'UTC' ) );
            return $dt->format( $format );
        } catch ( Exception $e ) {
            return false;
        }
    }
}

// get_permalink — returns from $GLOBALS['_test_permalink'][$id] or a default URL.
if ( ! function_exists( 'get_permalink' ) ) {
    function get_permalink( $id = 0 ) {
        $map = $GLOBALS['_test_permalink'] ?? array();
        return $map[ (int) $id ] ?? 'https://example.test/?p=' . (int) $id;
    }
}

// home_url — returns a base URL optionally suffixed with $path.
if ( ! function_exists( 'home_url' ) ) {
    function home_url( $path = '' ) {
        return 'https://example.test' . $path;
    }
}

// wp_parse_url — thin wrapper around PHP's parse_url().
if ( ! function_exists( 'wp_parse_url' ) ) {
    function wp_parse_url( $url, $component = -1 ) {
        return parse_url( $url, $component );
    }
}

// wp_strip_all_tags — wrapper around strip_tags with whitespace cleanup.
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
    function wp_strip_all_tags( $text, $remove_breaks = false ) {
        $text = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', $text );
        $text = strip_tags( $text );
        if ( $remove_breaks ) {
            $text = preg_replace( '/[\r\n\t ]+/', ' ', $text );
        }
        return trim( $text );
    }
}

// str_starts_with polyfill for PHP < 8.0 (plugin runs on 8.0+ in production
// but test runners may differ).
if ( ! function_exists( 'str_starts_with' ) ) {
    function str_starts_with( $haystack, $needle ) {
        return '' === $needle || strncmp( $haystack, $needle, strlen( $needle ) ) === 0;
    }
}

// ------------------------------------------------------------------
// Load the plugin (defines all constants and functions under test)
// ------------------------------------------------------------------
require dirname( __DIR__ ) . '/rankmath-rest-bridge.php';
