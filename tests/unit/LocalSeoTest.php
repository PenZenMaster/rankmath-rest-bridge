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
        $GLOBALS['_test_filters']         = array();
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

    // -- Snippet applicability (shared matcher) ---------------------------------

    public function test_snippet_nodes_use_shared_matcher_including_url_targets(): void {
        $this->make_post( 5, 'page' );
        $GLOBALS['_test_permalink'][5]                = 'https://example.test/contact/';
        $content                                      = '<script type="application/ld+json">{"@type":"LocalBusiness","@id":"https://example.test/#a","name":"A"}</script>';
        $GLOBALS['_test_options'][ RMB_SNIPPETS_KEY ] = array(
            'by-url' => array( 'status' => 'active', 'display_on' => 'url:/contact', 'content' => $content ),
        );
        $this->assertCount( 1, rr_local_seo_snippet_nodes( 5, false, 'page' ) );
        $this->assertCount( 0, rr_local_seo_snippet_nodes( 0, true, '' ) );
    }

    public function test_snippet_nodes_honor_global_killswitch(): void {
        $content                                      = '<script type="application/ld+json">{"@type":"LocalBusiness","name":"A"}</script>';
        $GLOBALS['_test_options'][ RMB_SNIPPETS_KEY ] = array(
            'a' => array( 'status' => 'active', 'display_on' => 'sitewide', 'content' => $content ),
        );
        $this->assertCount( 1, rr_local_seo_snippet_nodes( 0, true, '' ) );
        $GLOBALS['_test_options']['rrseo_emit_snippets'] = false;
        $this->assertSame( array(), rr_local_seo_snippet_nodes( 0, true, '' ) );
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

    // -- Stage 2: emitter inventory --------------------------------------------------

    public function test_active_emitters_detects_known_plugins_only(): void {
        $GLOBALS['_test_options']['active_plugins'] = array(
            'header-footer-code-manager/99robots-header-footer-code-manager.php',
            'seo-by-rank-math/rank-math.php',
            'hello.php',
            'some-other-plugin/some-other-plugin.php',
        );
        $slugs = array_column( rr_local_seo_active_emitters(), 'slug' );
        $this->assertSame( array( 'seo-by-rank-math', 'header-footer-code-manager' ), $slugs );
    }

    public function test_active_emitters_reads_network_active_plugins(): void {
        $GLOBALS['_test_options']['active_sitewide_plugins'] = array( 'insert-headers-and-footers/ihaf.php' => 1700000000 );
        $found = rr_local_seo_active_emitters();
        $this->assertSame( 'WPCode', $found[0]['name'] );
        $this->assertSame( 'code_injection', $found[0]['kind'] );
    }

    public function test_active_emitters_empty_when_none(): void {
        $this->assertSame( array(), rr_local_seo_active_emitters() );
    }

    public function test_other_emitter_names_exclude_rank_math(): void {
        $GLOBALS['_test_options']['active_plugins'] = array( 'seo-by-rank-math/rank-math.php', 'wordpress-seo/wp-seo.php' );
        $this->assertSame( array( 'Yoast SEO' ), rr_local_seo_other_emitter_names() );
    }

    public function test_warnings_name_other_emitters_only_when_enabled(): void {
        $GLOBALS['_test_options']['active_plugins'] = array( 'header-footer-code-manager/x.php' );
        $this->assertSame( array(), rr_local_seo_warnings( rr_local_seo_get_config() ) );
        $joined = implode( ' ', rr_local_seo_warnings( $this->enabled_config() ) );
        $this->assertStringContainsString( 'Header Footer Code Manager', $joined );
    }

    // -- Stage 2: public scan --------------------------------------------------------

    private function ld( array $node ): string {
        return '<html><head><script type="application/ld+json">' . wp_json_encode( $node ) . '</script></head></html>';
    }

    private function supply_html( array $by_post ): void {
        $GLOBALS['_test_filters']['rrseo_local_seo_public_html'][] = function ( $value, $post_id ) use ( $by_post ) {
            return array_key_exists( $post_id, $by_post ) ? $by_post[ $post_id ] : $value;
        };
    }

    public function test_strip_own_blocks_removes_only_marked_region(): void {
        $own   = RR_SCHEMA_HYGIENE_MARKER_START . '<script type="application/ld+json">{"@type":"Organization"}</script>' . RR_SCHEMA_HYGIENE_MARKER_END;
        $other = '<script type="application/ld+json">{"@type":"WebSite"}</script>';
        $out   = rr_local_seo_strip_own_blocks( $own . $other );
        $this->assertStringNotContainsString( 'Organization', $out );
        $this->assertStringContainsString( 'WebSite', $out );
    }

    public function test_public_nodes_ignore_own_blocks(): void {
        $html = RR_SCHEMA_HYGIENE_MARKER_START . '<script type="application/ld+json">{"@type":"Organization","@id":"mine"}</script>' . RR_SCHEMA_HYGIENE_MARKER_END
            . '<script type="application/ld+json">{"@type":"WebSite","@id":"theirs"}</script>';
        $ids = array_column( rr_local_seo_public_nodes( $html ), '@id' );
        $this->assertSame( array( 'theirs' ), $ids );
    }

    public function test_scan_page_reports_same_id_conflict_from_third_party(): void {
        $GLOBALS['_test_options']['page_on_front'] = 2;
        $this->make_post( 2 );
        $this->supply_html( array( 2 => $this->ld( array( '@type' => 'Organization', '@id' => 'https://example.test/#organization', 'name' => 'HFCM Org' ) ) ) );

        $page = rr_local_seo_scan_page( $this->enabled_config(), 2, true );
        $this->assertSame( 'inspected', $page['status'] );
        $this->assertSame( 'https://example.test/#organization', $page['conflicts'][0]['@id'] );
        $this->assertSame( 'same_id', $page['conflicts'][0]['reason'] );
    }

    public function test_scan_page_reports_same_name_conflict(): void {
        $GLOBALS['_test_options']['page_on_front'] = 2;
        $this->make_post( 2 );
        $this->supply_html( array( 2 => $this->ld( array( '@type' => 'LocalBusiness', '@id' => 'https://example.test/#other', 'name' => 'Olson Recycling' ) ) ) );

        $page = rr_local_seo_scan_page( $this->enabled_config(), 2, true );
        $this->assertSame( 'same_type_and_name', $page['conflicts'][0]['reason'] );
    }

    public function test_scan_page_clean_when_nothing_matches(): void {
        $this->make_post( 2 );
        $this->supply_html( array( 2 => $this->ld( array( '@type' => 'WebSite', '@id' => 'https://example.test/#website', 'name' => 'Site' ) ) ) );
        $page = rr_local_seo_scan_page( $this->enabled_config(), 2, true );
        $this->assertSame( array(), $page['conflicts'] );
    }

    public function test_scan_page_unavailable_without_page_or_html(): void {
        $page = rr_local_seo_scan_page( $this->enabled_config(), 0, true );
        $this->assertSame( 'unavailable', $page['status'] );
        $this->assertSame( 'no_page_to_fetch', $page['error'] );
    }

    public function test_scan_page_nothing_to_emit_without_entity(): void {
        $page = rr_local_seo_scan_page( rr_local_seo_get_config(), 2, true );
        $this->assertSame( 'nothing_to_emit', $page['status'] );
    }

    public function test_scan_site_covers_front_and_assigned_pages_and_flags_unverified(): void {
        $GLOBALS['_test_options']['page_on_front'] = 2;
        $this->make_post( 2 );
        $this->make_post( 21 );
        $config                            = $this->enabled_config();
        $config['locations'][0]['post_id'] = 21;
        $this->supply_html( array( 2 => $this->ld( array( '@type' => 'WebSite', '@id' => 'x' ) ) ) );

        $scan = rr_local_seo_scan_site( $config );
        $this->assertCount( 2, $scan['pages'] );
        $this->assertSame( array( 21 ), array( $scan['pages'][1]['post_id'] ) );
        $this->assertTrue( $scan['unverified'], 'page 21 has no HTML and no loopback in tests' );
        $this->assertSame( array(), $scan['conflicts'] );
    }

    // -- Stage 2: enable gate --------------------------------------------------------

    private function enable_request( array $extra = array() ): WP_REST_Request {
        return new WP_REST_Request( array_merge( array( 'enabled' => true ), $extra ) );
    }

    private function seed_disabled_config(): void {
        $config            = $this->enabled_config();
        $config['enabled'] = false;
        update_option( RR_LOCAL_SEO_KEY, $config );
    }

    public function test_gate_refuses_enable_on_conflict(): void {
        $this->seed_disabled_config();
        $GLOBALS['_test_options']['page_on_front'] = 2;
        $this->make_post( 2 );
        $this->supply_html( array( 2 => $this->ld( array( '@type' => 'Organization', '@id' => 'https://example.test/#organization' ) ) ) );

        $result = rmb_local_seo_set( $this->enable_request() );
        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertSame( 'duplicate_schema_conflict', $result->code );
        $this->assertSame( 422, $result->data['status'] );
        $this->assertNotEmpty( $result->data['conflicts'] );
        $this->assertFalse( get_option( RR_LOCAL_SEO_KEY )['enabled'], 'nothing was written' );
    }

    public function test_gate_allows_enable_with_acknowledge_and_warns(): void {
        $this->seed_disabled_config();
        $GLOBALS['_test_options']['page_on_front'] = 2;
        $this->make_post( 2 );
        $this->supply_html( array( 2 => $this->ld( array( '@type' => 'Organization', '@id' => 'https://example.test/#organization' ) ) ) );

        $result = rmb_local_seo_set( $this->enable_request( array( 'acknowledge_duplicates' => true ) ) );
        $this->assertTrue( $result['success'] );
        $this->assertTrue( get_option( RR_LOCAL_SEO_KEY )['enabled'] );
        $this->assertStringContainsString( 'acknowledged duplicates', implode( ' ', $result['warnings'] ) );
    }

    public function test_gate_allows_clean_enable(): void {
        $this->seed_disabled_config();
        $GLOBALS['_test_options']['page_on_front'] = 2;
        $this->make_post( 2 );
        $this->supply_html( array( 2 => $this->ld( array( '@type' => 'WebSite', '@id' => 'x' ) ) ) );

        $result = rmb_local_seo_set( $this->enable_request() );
        $this->assertTrue( $result['success'] );
        $this->assertSame( array(), $result['scan']['conflicts'] );
    }

    public function test_gate_warns_but_allows_when_scan_unverified(): void {
        $this->seed_disabled_config();
        $result = rmb_local_seo_set( $this->enable_request() );
        $this->assertTrue( $result['success'] );
        $this->assertStringContainsString( 'unverified', implode( ' ', $result['warnings'] ) );
    }

    public function test_gate_applies_to_dry_run_without_writing(): void {
        $this->seed_disabled_config();
        $GLOBALS['_test_options']['page_on_front'] = 2;
        $this->make_post( 2 );
        $this->supply_html( array( 2 => $this->ld( array( '@type' => 'Organization', '@id' => 'https://example.test/#organization' ) ) ) );

        $result = rmb_local_seo_set( $this->enable_request( array( 'dry_run' => true ) ) );
        $this->assertInstanceOf( WP_Error::class, $result );
    }

    public function test_gate_does_not_scan_when_already_enabled_or_staying_disabled(): void {
        update_option( RR_LOCAL_SEO_KEY, $this->enabled_config() );
        $result = rmb_local_seo_set( new WP_REST_Request( array( 'locations' => array( $this->location( 'x' ) ) ) ) );
        $this->assertNull( $result['scan'] );

        $this->seed_disabled_config();
        $result = rmb_local_seo_set( new WP_REST_Request( array( 'locations' => array( $this->location( 'y' ) ) ) ) );
        $this->assertNull( $result['scan'] );
    }

    public function test_preview_inspect_public_attaches_scan(): void {
        update_option( RR_LOCAL_SEO_KEY, $this->enabled_config() );
        $GLOBALS['_test_options']['page_on_front'] = 2;
        $this->make_post( 2 );
        $this->supply_html( array( 2 => $this->ld( array( '@type' => 'Organization', '@id' => 'https://example.test/#organization' ) ) ) );

        $with = rmb_local_seo_preview( new WP_REST_Request( array( 'inspect_public' => true ) ) );
        $this->assertSame( 'same_id', $with['public']['conflicts'][0]['reason'] );

        $without = rmb_local_seo_preview( new WP_REST_Request() );
        $this->assertNull( $without['public'] );
    }

    // -- Stage 2: business facts and audit integration -------------------------------

    public function test_business_facts_from_config(): void {
        $loc                  = $this->location( 'seneca' );
        $loc['area_served']   = array( 'Seneca', 'Streator' );
        $config               = rr_validate_local_seo( array( 'entity' => $this->entity(), 'locations' => array( $loc ) ), rr_local_seo_get_config() )['config'];
        $facts                = rr_local_seo_business_facts( $config );

        $this->assertSame( 'Olson Recycling', $facts['business_name'] );
        $this->assertSame( 'RecyclingCenter', $facts['schema_type'] );
        $this->assertSame( 'https://example.test/#organization', $facts['entity_id'] );
        $this->assertSame( '(815) 357-8625', $facts['phone'] );
        $this->assertSame( '354 W. Jackson St., Seneca, IL, 61360', $facts['address'] );
        $this->assertSame( array( 'Seneca', 'Streator' ), $facts['service_area'] );
    }

    public function test_business_facts_empty_without_entity(): void {
        $this->assertSame( array(), rr_local_seo_business_facts( rr_local_seo_get_config() ) );
    }

    public function test_resolve_business_facts_precedence(): void {
        $config = rr_validate_local_seo( array( 'entity' => $this->entity() ), rr_local_seo_get_config() )['config'];
        update_option( RR_LOCAL_SEO_KEY, $config );

        $facts = rr_resolve_business_facts( array() );
        $this->assertSame( 'Olson Recycling', $facts['business_name'], 'local_seo beats homepage and fallback' );

        $manual = rr_resolve_business_facts( array( 'business_facts' => array( 'business_name' => 'Manual Co' ) ) );
        $this->assertSame( 'Manual Co', $manual['business_name'], 'manual wins' );
    }

    public function test_resolve_business_facts_unchanged_without_local_seo(): void {
        $facts = rr_resolve_business_facts( array() );
        $this->assertArrayNotHasKey( 'entity_id', $facts );
    }

    public function test_entity_signals_source_label(): void {
        update_option( RR_LOCAL_SEO_KEY, rr_validate_local_seo( array( 'entity' => $this->entity() ), rr_local_seo_get_config() )['config'] );
        $this->assertSame( 'local_seo', rr_aeo_compute_entity_signals()['source'] );
    }

    public function test_audit_source_null_when_disabled_and_typed_when_enabled(): void {
        $this->assertNull( rr_local_seo_audit_source( 2, true ) );

        update_option( RR_LOCAL_SEO_KEY, $this->enabled_config() );
        $source = rr_local_seo_audit_source( 2, true );
        $this->assertSame( 'local_seo', $source['source'] );
        $this->assertContains( 'Organization', $source['types'] );
        $this->assertContains( 'RecyclingCenter', $source['types'] );
    }

    // -- Stage 2: import preview -----------------------------------------------------

    private function import_snippets(): array {
        $graph = array(
            '@context' => 'https://schema.org',
            '@graph'   => array(
                array(
                    '@type'                     => 'LocalBusiness',
                    '@id'                       => 'https://old.test/#localbusiness-seneca',
                    'name'                      => 'Olson Recycling Seneca',
                    'telephone'                 => '(815) 357-8625',
                    'priceRange'                => '$$',
                    'address'                   => array(
                        '@type'           => 'PostalAddress',
                        'streetAddress'   => '354 W. Jackson St.',
                        'addressLocality' => 'Seneca',
                        'addressRegion'   => 'IL',
                        'postalCode'      => '61360',
                        'addressCountry'  => 'US',
                    ),
                    'geo'                       => array( '@type' => 'GeoCoordinates', 'latitude' => '41.31', 'longitude' => '-88.61' ),
                    'openingHoursSpecification' => array(
                        array( 'dayOfWeek' => array( 'https://schema.org/Monday', 'Tuesday' ), 'opens' => '08:00:00', 'closes' => '17:00:00' ),
                    ),
                    'sameAs'                    => array( 'https://www.facebook.com/olson', 'http://insecure.test/x' ),
                    'areaServed'                => array( array( '@type' => 'Place', 'name' => 'Streator' ), 'Seneca' ),
                ),
                array( '@type' => 'Organization', '@id' => 'https://old.test/#org', 'name' => 'Olson Recycling', 'url' => 'https://example.test', 'logo' => array( '@type' => 'ImageObject', 'url' => 'https://example.test/l.png' ) ),
                array(
                    '@type'    => 'Service',
                    '@id'      => 'https://old.test/#svc',
                    'name'     => 'Recycling',
                    'provider' => array( '@type' => 'Organization', 'name' => 'Nested Provider' ),
                ),
            ),
        );
        return array(
            'lb-all' => array( 'status' => 'inactive', 'display_on' => 'home', 'content' => '<script type="application/ld+json">' . wp_json_encode( $graph ) . '</script>' ),
            'noise'  => array( 'status' => 'active', 'display_on' => 'sitewide', 'content' => '<script>console.log(1)</script>' ),
        );
    }

    public function test_import_candidates_map_location_and_entity(): void {
        $found = rr_local_seo_import_candidates( $this->import_snippets() );
        $this->assertCount( 2, $found, 'nested provider Organization and the Service are not proposed' );

        $loc = $found[0];
        $this->assertSame( 'location', $loc['kind'] );
        $this->assertSame( 'lb-all', $loc['snippet_id'] );
        $this->assertSame( 'inactive', $loc['snippet_status'] );
        $this->assertSame( 'seneca', $loc['proposed']['id'] );
        $this->assertSame( 41.31, $loc['proposed']['geo']['lat'] );
        $this->assertSame( array( 'Monday', 'Tuesday' ), $loc['proposed']['opening_hours'][0]['days'] );
        $this->assertSame( '08:00', $loc['proposed']['opening_hours'][0]['opens'] );
        $this->assertSame( array( 'https://www.facebook.com/olson' ), $loc['proposed']['same_as'] );
        $this->assertSame( array( 'Streator', 'Seneca' ), $loc['proposed']['area_served'] );
        $this->assertTrue( $loc['valid'], implode( '; ', $loc['errors'] ) );
        $this->assertNotEmpty( $loc['notes'], 'the http sameAs entry was dropped with a note' );
        $this->assertSame( 'LocalBusiness', $loc['normalized']['business_type'] );

        $org = $found[1];
        $this->assertSame( 'entity', $org['kind'] );
        $this->assertSame( 'https://example.test/l.png', $org['proposed']['logo'] );
        $this->assertTrue( $org['valid'] );
    }

    public function test_import_candidate_flags_invalid_mapping(): void {
        $snippets = array(
            's' => array( 'status' => 'active', 'display_on' => 'sitewide', 'content' => '<script type="application/ld+json">{"@type":"LocalBusiness","name":"No Address Co"}</script>' ),
        );
        $found = rr_local_seo_import_candidates( $snippets );
        $this->assertCount( 1, $found );
        $this->assertFalse( $found[0]['valid'] );
        $this->assertNotEmpty( $found[0]['errors'] );
        $this->assertNull( $found[0]['normalized'] );
    }

    public function test_import_candidate_person_maps_to_person_entity(): void {
        $snippets = array(
            's' => array( 'status' => 'active', 'display_on' => 'home', 'content' => '<script type="application/ld+json">{"@type":"Person","@id":"https://x.test/#person","name":"Trevor Aspiranti","jobTitle":"Coach"}</script>' ),
        );
        $found = rr_local_seo_import_candidates( $snippets );
        $this->assertSame( 'Person', $found[0]['proposed']['type'] );
        $this->assertSame( 'Coach', $found[0]['proposed']['job_title'] );
        $this->assertTrue( $found[0]['valid'] );
    }

    public function test_import_preview_handler_is_read_only_and_summarizes(): void {
        $GLOBALS['_test_options'][ RMB_SNIPPETS_KEY ] = $this->import_snippets();
        $before                                       = $GLOBALS['_test_options'];

        $result = rmb_local_seo_import_preview( new WP_REST_Request() );
        $this->assertTrue( $result['read_only'] );
        $this->assertSame( 2, $result['summary']['candidates'] );
        $this->assertSame( 1, $result['summary']['locations'] );
        $this->assertSame( 1, $result['summary']['entities'] );
        $this->assertSame( $before, $GLOBALS['_test_options'], 'no options were written' );
    }
}
