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

// add_action — records every registration into $GLOBALS['_test_actions'] so
// tests can locate and invoke hook callbacks (init closures, wp_head emitters)
// and assert on remove_action() effects. Callbacks are never auto-executed.
if ( ! function_exists( 'add_action' ) ) {
    function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
        $GLOBALS['_test_actions'][ $hook ][] = [
            'callback' => $callback,
            'priority' => $priority,
        ];
    }
}

if ( ! function_exists( 'remove_action' ) ) {
    function remove_action( $hook, $callback, $priority = 10 ) {
        $GLOBALS['_test_removed_actions'][] = [
            'hook'     => $hook,
            'callback' => $callback,
            'priority' => $priority,
        ];
        foreach ( $GLOBALS['_test_actions'][ $hook ] ?? [] as $i => $entry ) {
            if ( $entry['callback'] === $callback && $entry['priority'] === $priority ) {
                unset( $GLOBALS['_test_actions'][ $hook ][ $i ] );
            }
        }
        return true;
    }
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
// Accepts a WP_Post object like core does.
if ( ! function_exists( 'get_permalink' ) ) {
    function get_permalink( $id = 0 ) {
        if ( is_object( $id ) ) {
            $id = $id->ID ?? 0;
        }
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
// Load-time stubs added as the plugin grew (activation hooks, i18n)
// ------------------------------------------------------------------

if ( ! function_exists( 'register_activation_hook' ) ) {
    function register_activation_hook( $file, $callback ) {}
}

if ( ! function_exists( 'register_deactivation_hook' ) ) {
    function register_deactivation_hook( $file, $callback ) {}
}

if ( ! function_exists( 'plugin_basename' ) ) {
    function plugin_basename( $file ) {
        return basename( $file );
    }
}

// __ / esc_html__ — return the string unchanged (no translation in tests).
if ( ! function_exists( '__' ) ) {
    function __( $text, $domain = 'default' ) {
        return $text;
    }
}

if ( ! function_exists( 'esc_html__' ) ) {
    function esc_html__( $text, $domain = 'default' ) {
        return $text;
    }
}

// Taxonomy archive conditionals — configurable via $GLOBALS, default false.
if ( ! function_exists( 'is_tax' ) ) {
    function is_tax( $taxonomy = '', $term = '' ) {
        return $GLOBALS['_test_is_tax'] ?? false;
    }
}

if ( ! function_exists( 'is_category' ) ) {
    function is_category( $category = '' ) {
        return $GLOBALS['_test_is_category'] ?? false;
    }
}

if ( ! function_exists( 'is_tag' ) ) {
    function is_tag( $tag = '' ) {
        return $GLOBALS['_test_is_tag'] ?? false;
    }
}

// ------------------------------------------------------------------
// Meta registration + write path (issue #3 regression coverage)
// ------------------------------------------------------------------
// register_post_meta records registrations; update_post_meta applies the
// registered sanitize_callback exactly like WP core's sanitize_meta() does.
// This is the layer that flattened array meta to '' in v2.14.4-v2.18.0.

if ( ! function_exists( 'register_post_meta' ) ) {
    function register_post_meta( $post_type, $meta_key, array $args = [] ) {
        $GLOBALS['_test_registered_meta'][ $meta_key ] = $args;
        return true;
    }
}

if ( ! function_exists( 'update_post_meta' ) ) {
    function update_post_meta( $post_id, $meta_key, $meta_value ) {
        $args = $GLOBALS['_test_registered_meta'][ $meta_key ] ?? null;
        if ( $args && ! empty( $args['sanitize_callback'] ) && is_callable( $args['sanitize_callback'] ) ) {
            $meta_value = call_user_func( $args['sanitize_callback'], $meta_value );
        }
        $GLOBALS['_test_post_meta'][ $post_id ][ $meta_key ] = $meta_value;
        return true;
    }
}

// sanitize_textarea_field — mirrors WP core: arrays and objects sanitize to ''.
if ( ! function_exists( 'sanitize_textarea_field' ) ) {
    function sanitize_textarea_field( $str ) {
        if ( is_object( $str ) || is_array( $str ) ) {
            return '';
        }
        return trim( (string) $str );
    }
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
    function sanitize_text_field( $str ) {
        if ( is_object( $str ) || is_array( $str ) ) {
            return '';
        }
        return trim( preg_replace( '/[\r\n\t ]+/', ' ', (string) $str ) );
    }
}

// get_role — null so the capability-provisioning init closure bails cleanly.
if ( ! function_exists( 'get_role' ) ) {
    function get_role( $role ) {
        return null;
    }
}

// Audit-log runtime dependencies (rr_audit_log).
if ( ! function_exists( 'get_current_user_id' ) ) {
    function get_current_user_id() {
        return $GLOBALS['_test_current_user_id'] ?? 0;
    }
}

if ( ! function_exists( 'get_userdata' ) ) {
    function get_userdata( $user_id ) {
        return $GLOBALS['_test_users'][ $user_id ] ?? null;
    }
}

// esc_url — identity; sufficient for output assertions in emitter tests.
if ( ! function_exists( 'esc_url' ) ) {
    function esc_url( $url ) {
        return $url;
    }
}

// ------------------------------------------------------------------
// Action engine stubs (v3.0 Bite 2)
// ------------------------------------------------------------------

if ( ! function_exists( 'absint' ) ) {
    function absint( $maybeint ) {
        return abs( (int) $maybeint );
    }
}

// update_option — writes through to $GLOBALS['_test_options'].
if ( ! function_exists( 'update_option' ) ) {
    function update_option( $key, $value, $autoload = null ) {
        $GLOBALS['_test_options'][ $key ] = $value;
        return true;
    }
}

// wp_cache_delete — records calls so tests can assert the cache-bust invariant.
if ( ! function_exists( 'wp_cache_delete' ) ) {
    function wp_cache_delete( $key, $group = '' ) {
        $GLOBALS['_test_cache_deletes'][] = [ 'key' => $key, 'group' => $group ];
        return true;
    }
}

// do_action — records fired hooks (e.g. litespeed_purge_url) for assertions.
if ( ! function_exists( 'do_action' ) ) {
    function do_action( $hook, ...$args ) {
        $GLOBALS['_test_fired_actions'][] = [ 'hook' => $hook, 'args' => $args ];
    }
}

if ( ! function_exists( 'get_rest_url' ) ) {
    function get_rest_url( $blog_id = null, $path = '/' ) {
        return 'https://example.test/wp-json/' . ltrim( $path, '/' );
    }
}

if ( ! function_exists( 'delete_transient' ) ) {
    function delete_transient( $transient ) {
        unset( $GLOBALS['_test_transients'][ $transient ] );
        return true;
    }
}

// ------------------------------------------------------------------
// Load the plugin (defines all constants and functions under test)
// ------------------------------------------------------------------
require dirname( __DIR__ ) . '/rankmath-rest-bridge.php';
