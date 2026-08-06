<?php

use PHPUnit\Framework\TestCase;

/**
 * Tests for rr_get_capabilities_map().
 *
 * Pure static data — no WP dependencies.
 */
class CapabilitiesMapTest extends TestCase {

    public function test_returns_non_empty_array(): void {
        $map = rr_get_capabilities_map();
        $this->assertIsArray( $map );
        $this->assertNotEmpty( $map );
    }

    public function test_every_key_is_dotted_lowercase(): void {
        $map = rr_get_capabilities_map();
        foreach ( array_keys( $map ) as $key ) {
            $this->assertMatchesRegularExpression(
                '/^[a-z_]+(\.[a-z_]+)+$/',
                $key,
                "Capability key '{$key}' should be dotted lowercase, e.g. 'schema.write.graph'."
            );
        }
    }

    public function test_every_entry_has_required_fields(): void {
        $map = rr_get_capabilities_map();
        foreach ( $map as $key => $entry ) {
            $this->assertArrayHasKey( 'available', $entry, "'{$key}' missing 'available'" );
            $this->assertArrayHasKey( 'route', $entry, "'{$key}' missing 'route'" );
            $this->assertArrayHasKey( 'since', $entry, "'{$key}' missing 'since'" );
            $this->assertIsBool( $entry['available'], "'{$key}' available must be bool" );
            $this->assertIsString( $entry['route'], "'{$key}' route must be string" );
        }
    }

    public function test_since_is_string_or_null(): void {
        $map = rr_get_capabilities_map();
        foreach ( $map as $key => $entry ) {
            $this->assertTrue(
                is_string( $entry['since'] ) || null === $entry['since'],
                "'{$key}' since must be a string or null"
            );
        }
    }

    public function test_recently_shipped_capabilities_have_since_set(): void {
        $map = rr_get_capabilities_map();
        $this->assertEquals( '3.5.0', $map['schema.write.graph']['since'] );
        $this->assertEquals( '3.6.0', $map['media.upload']['since'] );
        $this->assertEquals( '3.7.0', $map['elementor.set_data']['since'] );
    }

    public function test_recently_shipped_capabilities_are_available(): void {
        $map = rr_get_capabilities_map();
        $this->assertTrue( $map['schema.write.graph']['available'] );
        $this->assertTrue( $map['media.upload']['available'] );
        $this->assertTrue( $map['elementor.set_data']['available'] );
    }

    public function test_expected_capability_keys_present(): void {
        $map = rr_get_capabilities_map();
        $expected = [
            'seo.meta.update',
            'seo.meta.get',
            'seo.meta.bulk_update',
            'schema.write.single_node',
            'schema.write.graph',
            'media.upload',
            'elementor.set_data',
            'llms.write',
            'snippets.set',
        ];
        foreach ( $expected as $key ) {
            $this->assertArrayHasKey( $key, $map, "Expected capability key '{$key}' to be present" );
        }
    }
}
