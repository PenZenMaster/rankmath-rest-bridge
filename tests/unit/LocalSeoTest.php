<?php

use PHPUnit\Framework\TestCase;

/**
 * Tests for the Local SEO module (includes/class-rrseo-local.php, issue #39
 * Stage 1): strict validation, config merging, node building, duplicate
 * detection against stored schema and snippets, emission gating and the
 * action-log record for site-level writes.
 */
class LocalSeoTest extends TestCase {

    protected function setUp(): void {
        $GLOBALS['_test_options']         = array();
        $GLOBALS['_test_post_meta']       = array();
        $GLOBALS['_test_posts']           = array();
        $GLOBALS['_test_registered_meta'] = array();
        $GLOBALS['_test_is_front_page']   = false;
        $GLOBALS['_test_is_singular']     = false;
        $GLOBALS['_test_queried_object_id'] = 0;
        $GLOBALS['_test_attachment_urls'] = array();
        $GLOBALS['_test_permalink']       = array();
    }

    private function make_post( int $id, string $type = 'page' ): WP_Post {
        $post            = new WP_Post();
        $post->ID        = $id;
        $post->post_type = $type;

        $GLOBALS['_test_posts'][ $id ] = $post;
        return $post;
    }

    private function entity(): array {
        return array(
            'type'    => 'Organization',
            'name'    => 'Olson Recycling',
            'logo'    => 'https://example.test/logo.png',
            'same_as' => array( 'https://www.facebook.com/olson' ),
        );
    }

    private function location( string $id = 'seneca' ): array {
        return array(
            'id'            => $id,
            'name'          => 'Olson Recycling ' . ucfirst( $id ),
            'business_type' => 'RecyclingCenter',
            'phone'         => '(815) 357-8625',
            'address'       => array(
                'street'      => '354 W. Jackson St.',
                'locality'    => 'Seneca',
                'region'      => 'IL',
                'postal_code' => '61360',
                'country'     => 'US',
            ),
            'geo'           => array(
                'lat' => 41.31,
                'lng' => -88.61,
            ),
            'opening_hours' => array(
                array(
                    'days'   => array( 'Monday', 'Tuesday' ),
                    'opens'  => '08:00',
                    'closes' => '17:00',
                ),
            ),
        );
    }

    // -- Entity validation ---------------------------------------------------------

    public function test_entity_valid_organization(): void {
        $result = rr_validate_local_entity( $this->entity() );
        $this->assertSame( array(), $result['errors'] );
        $this->assertSame( 'Olson Recycling', $result['data']['name'] );
    }

    public function test_entity_person_accepts_job_title_rejects_legal_name(): void {
        $ok = rr_validate_local_entity( array( 'type' => 'Person', 'name' => 'Trevor Aspiranti', 'job_title' => 'Coach' ) );
        $this->assertSame( array(), $ok['errors'] );

        $bad = rr_validate_local_entity( array( 'type' => 'Person', 'name' => 'Trevor', 'legal_name' => 'X' ) );
        $this->assertNotEmpty( $bad['errors'] );
    }

    public function test_entity_organization_rejects_job_title(): void {
        $bad = rr_validate_local_entity( array( 'type' => 'Organization', 'name' => 'Acme', 'job_title' => 'CEO' ) );
        $this->assertNotEmpty( $bad['errors'] );
    }

    public function test_entity_rejects_bad_type_unknown_field_and_http_url(): void {
        $this->assertNotEmpty( rr_validate_local_entity( array( 'type' => 'Thing', 'name' => 'X' ) )['errors'] );
        $this->assertNotEmpty( rr_validate_local_entity( array( 'type' => 'Organization', 'name' => 'X', 'bogus' => 1 ) )['errors'] );
        $this->assertNotEmpty( rr_validate_local_entity( array( 'type' => 'Organization', 'name' => 'X', 'url' => 'http://example.test' ) )['errors'] );
        $this->assertNotEmpty( rr_validate_local_entity( array( 'type' => 'Organization', 'name' => 'X', 'same_as' => array( 'not a url' ) ) )['errors'] );
    }

    public function test_entity_rejects_angle_brackets_and_control_chars(): void {
        $this->assertNotEmpty( rr_validate_local_entity( array( 'type' => 'Organization', 'name' => 'A<script>' ) )['errors'] );
        $this->assertNotEmpty( rr_validate_local_entity( array( 'type' => 'Organization', 'name' => "A\x01B" ) )['errors'] );
    }

    public function test_entity_logo_attachment_id_must_be_media(): void {
        $this->make_post( 7, 'page' );
        $bad = rr_validate_local_entity( array( 'type' => 'Organization', 'name' => 'X', 'logo' => 7 ) );
        $this->assertNotEmpty( $bad['errors'] );

        $this->make_post( 8, 'attachment' );
        $ok = rr_validate_local_entity( array( 'type' => 'Organization', 'name' => 'X', 'logo' => 8 ) );
        $this->assertSame( array(), $ok['errors'] );
    }

    // -- Location validation -------------------------------------------------------

    public function test_location_valid(): void {
        $result = rr_validate_local_location( $this->location() );
        $this->assertSame( array(), $result['errors'] );
        $this->assertSame( 'RecyclingCenter', $result['data']['business_type'] );
    }

    public function test_location_default_business_type(): void {
        $loc = $this->location();
        unset( $loc['business_type'] );
        $result = rr_validate_local_location( $loc );
        $this->assertSame( 'LocalBusiness', $result['data']['business_type'] );
    }

    public function test_location_rejects_bad_id_and_type(): void {
        $loc       = $this->location();
        $loc['id'] = 'Bad ID';
        $this->assertNotEmpty( rr_validate_local_location( $loc )['errors'] );

        $loc                  = $this->location();
        $loc['business_type'] = 'Spaceship';
        $this->assertNotEmpty( rr_validate_local_location( $loc )['errors'] );
    }

    public function test_location_street_required_unless_service_area_only(): void {
        $loc = $this->location();
        unset( $loc['address']['street'] );
        $this->assertNotEmpty( rr_validate_local_location( $loc )['errors'] );

        $loc['service_area_only'] = true;
        $this->assertSame( array(), rr_validate_local_location( $loc )['errors'] );
    }

    public function test_location_country_must_be_iso2(): void {
        $loc                        = $this->location();
        $loc['address']['country'] = 'USA';
        $this->assertNotEmpty( rr_validate_local_location( $loc )['errors'] );
    }

    public function test_location_geo_ranges_and_types(): void {
        $loc        = $this->location();
        $loc['geo'] = array( 'lat' => 91, 'lng' => 0 );
        $this->assertNotEmpty( rr_validate_local_location( $loc )['errors'] );

        $loc['geo'] = array( 'lat' => '41.3', 'lng' => -88.6 );
        $this->assertNotEmpty( rr_validate_local_location( $loc )['errors'] );

        $loc['geo'] = array( 'lat' => 41.3 );
        $this->assertNotEmpty( rr_validate_local_location( $loc )['errors'] );
    }

    public function test_location_hours_validation(): void {
        $loc = $this->location();

        $loc['opening_hours'] = array( array( 'days' => array( 'Funday' ), 'opens' => '08:00', 'closes' => '17:00' ) );
        $this->assertNotEmpty( rr_validate_local_location( $loc )['errors'] );

        $loc['opening_hours'] = array( array( 'days' => array( 'Monday' ), 'opens' => '8am', 'closes' => '17:00' ) );
        $this->assertNotEmpty( rr_validate_local_location( $loc )['errors'] );

        $loc['opening_hours'] = array( array( 'days' => array( 'Monday' ), 'opens' => '08:00', 'closes' => '08:00' ) );
        $this->assertNotEmpty( rr_validate_local_location( $loc )['errors'] );

        $loc['opening_hours'] = array( array( 'days' => array( 'Monday' ), 'opens' => '22:00', 'closes' => '02:00' ) );
        $this->assertSame( array(), rr_validate_local_location( $loc )['errors'], 'overnight hours are allowed' );
    }

    public function test_location_phone_rejects_letters(): void {
        $loc          = $this->location();
        $loc['phone'] = 'call us';
        $this->assertNotEmpty( rr_validate_local_location( $loc )['errors'] );
    }

    public function test_location_post_id_must_exist(): void {
        $loc            = $this->location();
        $loc['post_id'] = 999;
        $this->assertNotEmpty( rr_validate_local_location( $loc )['errors'] );

        $this->make_post( 999 );
        $this->assertSame( array(), rr_validate_local_location( $loc )['errors'] );
    }

    // -- Settings validation and merge ---------------------------------------------

    public function test_settings_enabled_requires_entity(): void {
        $result = rr_validate_local_seo( array( 'enabled' => true ), rr_local_seo_get_config() );
        $this->assertNotEmpty( $result['errors'] );
    }

    public function test_settings_merge_replaces_only_sent_keys(): void {
        $current = rr_local_seo_get_config();
        $first   = rr_validate_local_seo( array( 'entity' => $this->entity(), 'locations' => array( $this->location() ) ), $current );
        $this->assertSame( array(), $first['errors'] );

        $second = rr_validate_local_seo( array( 'enabled' => true ), $first['config'] );
        $this->assertSame( array(), $second['errors'] );
        $this->assertTrue( $second['config']['enabled'] );
        $this->assertSame( 'Olson Recycling', $second['config']['entity']['name'] );
        $this->assertCount( 1, $second['config']['locations'] );
    }

    public function test_settings_reject_unknown_key_and_non_bool_enabled(): void {
        $this->assertNotEmpty( rr_validate_local_seo( array( 'bogus' => 1 ), rr_local_seo_get_config() )['errors'] );
        $this->assertNotEmpty( rr_validate_local_seo( array( 'enabled' => 'yes' ), rr_local_seo_get_config() )['errors'] );
    }

    public function test_settings_reject_duplicate_location_ids(): void {
        $result = rr_validate_local_seo(
            array( 'locations' => array( $this->location( 'a' ), $this->location( 'a' ) ) ),
            rr_local_seo_get_config()
        );
        $this->assertNotEmpty( $result['errors'] );
    }

    public function test_settings_reject_too_many_locations(): void {
        $many = array();
        for ( $i = 0; $i <= RR_LOCAL_SEO_MAX_LOCATIONS; $i++ ) {
            $many[] = $this->location( 'loc-' . $i );
        }
        $result = rr_validate_local_seo( array( 'locations' => $many ), rr_local_seo_get_config() );
        $this->assertNotEmpty( $result['errors'] );
    }

    public function test_settings_failed_validation_keeps_current_config(): void {
        $current = rr_validate_local_seo( array( 'entity' => $this->entity() ), rr_local_seo_get_config() )['config'];
        $bad     = $this->location();
        unset( $bad['name'] );
        $result = rr_validate_local_seo( array( 'locations' => array( $bad ) ), $current );
        $this->assertNotEmpty( $result['errors'] );
        $this->assertSame( array(), $result['config']['locations'] );
    }

    public function test_settings_empty_entity_clears_when_disabled(): void {
        $current = rr_validate_local_seo( array( 'entity' => $this->entity() ), rr_local_seo_get_config() )['config'];
        $result  = rr_validate_local_seo( array( 'entity' => array() ), $current );
        $this->assertSame( array(), $result['errors'] );
        $this->assertSame( array(), $result['config']['entity'] );
    }

    // -- Single-location upsert and delete -----------------------------------------

    public function test_apply_location_creates_replaces_and_deletes(): void {
        $config = rr_local_seo_get_config();

        $created = rr_local_seo_apply_location( $config, 'seneca', $this->location() );
        $this->assertSame( array(), $created['errors'] );
        $this->assertFalse( $created['found'] );
        $this->assertCount( 1, $created['config']['locations'] );

        $body         = $this->location();
        $body['name'] = 'Renamed';
        $replaced     = rr_local_seo_apply_location( $created['config'], 'seneca', $body );
        $this->assertTrue( $replaced['found'] );
        $this->assertCount( 1, $replaced['config']['locations'] );
        $this->assertSame( 'Renamed', $replaced['config']['locations'][0]['name'] );

        $deleted = rr_local_seo_apply_location( $replaced['config'], 'seneca', null );
        $this->assertTrue( $deleted['found'] );
        $this->assertSame( array(), $deleted['config']['locations'] );

        $missing = rr_local_seo_apply_location( $deleted['config'], 'seneca', null );
        $this->assertFalse( $missing['found'] );
    }

    public function test_apply_location_rejects_id_mismatch(): void {
        $result = rr_local_seo_apply_location( rr_local_seo_get_config(), 'fairbury', $this->location( 'seneca' ) );
        $this->assertNotEmpty( $result['errors'] );
    }

    // -- Node builders -------------------------------------------------------------

    public function test_entity_node_organization(): void {
        $node = rr_local_seo_build_entity_node( $this->entity() );
        $this->assertSame( 'Organization', $node['@type'] );
        $this->assertSame( 'https://example.test/#organization', $node['@id'] );
        $this->assertSame( 'ImageObject', $node['logo']['@type'] );
        $this->assertSame( array( 'https://www.facebook.com/olson' ), $node['sameAs'] );
    }

    public function test_entity_node_person_uses_image_and_person_id(): void {
        $node = rr_local_seo_build_entity_node( array( 'type' => 'Person', 'name' => 'Trevor', 'logo' => 'https://example.test/me.jpg' ) );
        $this->assertSame( 'https://example.test/#person', $node['@id'] );
        $this->assertSame( 'https://example.test/me.jpg', $node['image'] );
        $this->assertArrayNotHasKey( 'logo', $node );
    }

    public function test_entity_node_resolves_attachment_logo(): void {
        $this->make_post( 8, 'attachment' );
        $GLOBALS['_test_attachment_urls'][8] = 'https://example.test/wp-content/uploads/logo.png';
        $node = rr_local_seo_build_entity_node( array( 'type' => 'Organization', 'name' => 'X', 'logo' => 8 ) );
        $this->assertSame( 'https://example.test/wp-content/uploads/logo.png', $node['logo']['url'] );
    }

    public function test_location_node_full(): void {
        $node = rr_local_seo_build_location_node( $this->location(), $this->entity() );
        $this->assertSame( 'RecyclingCenter', $node['@type'] );
        $this->assertSame( 'https://example.test/#localbusiness-seneca', $node['@id'] );
        $this->assertSame( 'PostalAddress', $node['address']['@type'] );
        $this->assertSame( '354 W. Jackson St.', $node['address']['streetAddress'] );
        $this->assertSame( 41.31, $node['geo']['latitude'] );
        $this->assertSame( 'OpeningHoursSpecification', $node['openingHoursSpecification'][0]['@type'] );
        $this->assertSame( array( 'Monday', 'Tuesday' ), $node['openingHoursSpecification'][0]['dayOfWeek'] );
        $this->assertSame( array( '@id' => 'https://example.test/#organization' ), $node['parentOrganization'] );
    }

    public function test_location_node_person_entity_has_no_parent(): void {
        $node = rr_local_seo_build_location_node( $this->location(), array( 'type' => 'Person', 'name' => 'Trevor' ) );
        $this->assertArrayNotHasKey( 'parentOrganization', $node );
    }

    public function test_location_node_url_falls_back_to_post_permalink(): void {
        $this->make_post( 12 );
        $GLOBALS['_test_permalink'][12] = 'https://example.test/service-area/seneca/';
        $loc                            = $this->location();
        $loc['post_id']                 = 12;
        $node                           = rr_local_seo_build_location_node( $loc, $this->entity() );
        $this->assertSame( 'https://example.test/service-area/seneca/', $node['url'] );
    }

    public function test_location_node_omits_street_for_service_area(): void {
        $loc = $this->location();
        unset( $loc['address']['street'] );
        $loc['service_area_only'] = true;
        $node                     = rr_local_seo_build_location_node( $loc, $this->entity() );
        $this->assertArrayNotHasKey( 'streetAddress', $node['address'] );
    }

    // -- Duplicate detection -------------------------------------------------------

    public function test_duplicate_same_id(): void {
        $node = array( '@type' => 'LocalBusiness', '@id' => 'https://example.test/#x', 'name' => 'A' );
        $this->assertSame( 'same_id', rr_local_seo_duplicate_reason( $node, array( array( '@id' => 'https://example.test/#x' ) ) ) );
    }

    public function test_duplicate_same_name_within_business_family(): void {
        $node     = array( '@type' => 'RecyclingCenter', '@id' => 'a', 'name' => 'Olson  Recycling' );
        $existing = array( array( '@type' => 'LocalBusiness', '@id' => 'b', 'name' => 'olson recycling' ) );
        $this->assertSame( 'same_type_and_name', rr_local_seo_duplicate_reason( $node, $existing ) );
    }

    public function test_not_duplicate_for_different_name_or_family(): void {
        $node = array( '@type' => 'LocalBusiness', '@id' => 'a', 'name' => 'Olson Recycling' );
        $this->assertNull( rr_local_seo_duplicate_reason( $node, array( array( '@type' => 'LocalBusiness', '@id' => 'b', 'name' => 'Other' ) ) ) );
        $this->assertNull( rr_local_seo_duplicate_reason( $node, array( array( '@type' => 'WebPage', '@id' => 'c', 'name' => 'Olson Recycling' ) ) ) );
    }

    public function test_person_duplicate_only_against_person(): void {
        $node = array( '@type' => 'Person', '@id' => 'p', 'name' => 'Trevor' );
        $this->assertSame( 'same_type_and_name', rr_local_seo_duplicate_reason( $node, array( array( '@type' => 'Person', '@id' => 'q', 'name' => 'trevor' ) ) ) );
        $this->assertNull( rr_local_seo_duplicate_reason( $node, array( array( '@type' => 'Organization', '@id' => 'r', 'name' => 'Trevor' ) ) ) );
    }

    // -- Snippet applicability -----------------------------------------------------

    public function test_snippet_applies_targets(): void {
        $this->assertTrue( rr_local_seo_snippet_applies( 'sitewide', 0, false, '' ) );
        $this->assertTrue( rr_local_seo_snippet_applies( 'home', 5, true, 'page' ) );
        $this->assertFalse( rr_local_seo_snippet_applies( 'home', 5, false, 'page' ) );
        $this->assertTrue( rr_local_seo_snippet_applies( 'page_id:5', 5, false, 'page' ) );
        $this->assertTrue( rr_local_seo_snippet_applies( '5', 5, false, 'page' ) );
        $this->assertFalse( rr_local_seo_snippet_applies( 'page_id:6', 5, false, 'page' ) );
        $this->assertTrue( rr_local_seo_snippet_applies( 'post_type:post', 5, false, 'post' ) );
        $this->assertTrue( rr_local_seo_snippet_applies( 'all_pages', 5, false, 'page' ) );
        $this->assertFalse( rr_local_seo_snippet_applies( 'all_posts', 5, false, 'page' ) );
        $this->assertFalse( rr_local_seo_snippet_applies( 'unknown', 5, false, 'page' ) );
    }

    // -- Emission plan -------------------------------------------------------------

    private function enabled_config(): array {
        $config = rr_validate_local_seo(
            array(
                'enabled'   => true,
                'entity'    => $this->entity(),
                'locations' => array( $this->location( 'seneca' ), $this->location( 'fairbury' ) ),
            ),
            rr_local_seo_get_config()
        );
        $this->assertSame( array(), $config['errors'] );
        return $config['config'];
    }

    public function test_plan_home_emits_entity_and_all_locations(): void {
        $plan = rr_local_seo_plan( $this->enabled_config(), 0, true );
        $this->assertCount( 3, $plan['nodes'] );
        $this->assertSame( array(), $plan['skipped'] );
    }

    public function test_plan_non_home_without_assignment_emits_nothing(): void {
        $this->make_post( 20 );
        $plan = rr_local_seo_plan( $this->enabled_config(), 20, false );
        $this->assertSame( array(), $plan['nodes'] );
    }

    public function test_plan_location_page_emits_only_its_location(): void {
        $this->make_post( 21 );
        $config                            = $this->enabled_config();
        $config['locations'][0]['post_id'] = 21;
        $plan                              = rr_local_seo_plan( $config, 21, false );
        $this->assertCount( 1, $plan['nodes'] );
        $this->assertSame( 'https://example.test/#localbusiness-seneca', $plan['nodes'][0]['@id'] );
    }

    public function test_plan_skips_nodes_already_in_stored_graph(): void {
        $this->make_post( 30 );
        $GLOBALS['_test_post_meta'][30][ RR_SCHEMA_META_KEY ] = array(
            '@graph' => array(
                array( '@type' => 'Organization', '@id' => 'https://example.test/#organization', 'name' => 'Whatever' ),
            ),
        );
        $plan = rr_local_seo_plan( $this->enabled_config(), 30, true );
        $this->assertCount( 2, $plan['nodes'] );
        $this->assertSame( 'same_id', $plan['skipped'][0]['reason'] );
    }

    public function test_plan_skips_nodes_already_in_applicable_snippet(): void {
        $GLOBALS['_test_options'][ RMB_SNIPPETS_KEY ] = array(
            'lb-seneca' => array(
                'status'     => 'active',
                'display_on' => 'home',
                'content'    => '<script type="application/ld+json">{"@type":"HVACBusiness","@id":"https://example.test/#old","name":"Olson Recycling Seneca"}</script>',
            ),
        );
        $plan = rr_local_seo_plan( $this->enabled_config(), 0, true );
        $ids  = array_column( $plan['skipped'], '@id' );
        $this->assertContains( 'https://example.test/#localbusiness-seneca', $ids );
        $this->assertCount( 2, $plan['nodes'] );
    }

    public function test_plan_ignores_inactive_and_non_matching_snippets(): void {
        $content                                      = '<script type="application/ld+json">{"@type":"LocalBusiness","@id":"https://example.test/#old","name":"Olson Recycling Seneca"}</script>';
        $GLOBALS['_test_options'][ RMB_SNIPPETS_KEY ] = array(
            'a' => array( 'status' => 'inactive', 'display_on' => 'sitewide', 'content' => $content ),
            'b' => array( 'status' => 'active', 'display_on' => 'page_id:99', 'content' => $content ),
        );
        $plan = rr_local_seo_plan( $this->enabled_config(), 0, true );
        $this->assertSame( array(), $plan['skipped'] );
        $this->assertCount( 3, $plan['nodes'] );
    }

    // -- Emission ------------------------------------------------------------------

    public function test_emit_outputs_nothing_when_disabled(): void {
        $config            = $this->enabled_config();
        $config['enabled'] = false;
        update_option( RR_LOCAL_SEO_KEY, $config );
        $GLOBALS['_test_is_front_page'] = true;

        ob_start();
        rr_local_seo_emit();
        $this->assertSame( '', ob_get_clean() );
    }

    public function test_emit_outputs_marked_json_ld_on_front_page(): void {
        update_option( RR_LOCAL_SEO_KEY, $this->enabled_config() );
        $GLOBALS['_test_is_front_page'] = true;

        ob_start();
        rr_local_seo_emit();
        $out = ob_get_clean();

        $this->assertStringContainsString( RR_SCHEMA_HYGIENE_MARKER_START, $out );
        $this->assertStringContainsString( RR_SCHEMA_HYGIENE_MARKER_END, $out );
        $this->assertStringContainsString( 'application/ld+json', $out );
        $this->assertStringContainsString( '#localbusiness-fairbury', $out );
    }

    public function test_emit_outputs_nothing_off_front_page_without_assignment(): void {
        update_option( RR_LOCAL_SEO_KEY, $this->enabled_config() );
        $this->make_post( 40 );
        $GLOBALS['_test_is_singular']        = true;
        $GLOBALS['_test_queried_object_id'] = 40;

        ob_start();
        rr_local_seo_emit();
        $this->assertSame( '', ob_get_clean() );
    }

    // -- Response helpers ----------------------------------------------------------

    public function test_response_helper_sets_no_cache_headers(): void {
        $response = new class() {
            public $headers = array();

            public function header( $name, $value ) {
                $this->headers[ $name ] = $value;
            }
        };
        $out = rr_local_seo_response( $response );
        $this->assertSame( 'no-store, max-age=0', $out->headers['Cache-Control'] );
        $this->assertSame( 'no-cache', $out->headers['X-LiteSpeed-Cache-Control'] );
    }

    public function test_response_helper_passes_plain_data_through(): void {
        $this->assertSame( array( 'a' => 1 ), rr_local_seo_response( array( 'a' => 1 ) ) );
    }

    public function test_as_object_encodes_empty_as_json_object(): void {
        $this->assertSame( '{}', wp_json_encode( rr_local_seo_as_object( array() ) ) );
        $this->assertSame( '{"a":1}', wp_json_encode( rr_local_seo_as_object( array( 'a' => 1 ) ) ) );
    }

    // -- Action log and warnings ---------------------------------------------------

    public function test_log_records_non_reversible_envelope(): void {
        $before = rr_local_seo_get_config();
        $after  = $this->enabled_config();
        rr_local_seo_log( 'local_seo_update', null, $before, $after, 'req-1' );

        $log = get_option( RR_ACTION_LOG_KEY, array() );
        $this->assertCount( 1, $log );
        $this->assertSame( 'local_seo_update', $log[0]['action_type'] );
        $this->assertFalse( $log[0]['reversible'] );
        $this->assertNull( $log[0]['rollback_payload'] );
        $this->assertSame( array( 'seneca', 'fairbury' ), $log[0]['after']['location_ids'] );
        $this->assertSame( 'req-1', $log[0]['request_id'] );
    }

    public function test_warnings_present_only_when_enabled(): void {
        $this->assertSame( array(), rr_local_seo_warnings( rr_local_seo_get_config() ) );
        $this->assertNotEmpty( rr_local_seo_warnings( $this->enabled_config() ) );
    }
}
