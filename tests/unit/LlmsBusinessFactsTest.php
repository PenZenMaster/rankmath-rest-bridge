<?php

use PHPUnit\Framework\TestCase;

/**
 * Tests for rr_validate_llms_business_facts() and the business_facts
 * rendering path in rr_render_llms_txt() / rr_render_business_facts_lines()
 * (v3.4.0, issues #9/#10).
 */
class LlmsBusinessFactsTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['_test_posts_list'] = array();
		$GLOBALS['_test_pages_list'] = array();
		$GLOBALS['_test_post_meta']  = array();
		$GLOBALS['_test_permalink']  = array();
		$GLOBALS['_test_options']    = array();
	}

	protected function tearDown(): void {
		unset(
			$GLOBALS['_test_posts_list'],
			$GLOBALS['_test_pages_list'],
			$GLOBALS['_test_post_meta'],
			$GLOBALS['_test_permalink'],
			$GLOBALS['_test_options']
		);
	}

	// ── rr_validate_llms_business_facts() ────────────────────────────────────

	public function test_empty_array_is_valid(): void {
		$this->assertTrue( rr_validate_llms_business_facts( array() ) );
	}

	public function test_business_name_and_description_required(): void {
		$result = rr_validate_llms_business_facts( array( 'phone' => '555-0100' ) );
		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'invalid_business_facts', $result->code );
		$this->assertSame( 422, $result->data['status'] );
		$this->assertStringContainsString( 'business_name', implode( ' ', $result->data['errors'] ) );
		$this->assertStringContainsString( 'description', implode( ' ', $result->data['errors'] ) );
	}

	public function test_valid_minimal_payload_passes(): void {
		$result = rr_validate_llms_business_facts(
			array(
				'business_name' => 'Kilday Baxter & Associates',
				'description'   => 'Full-service CPA firm in Oglesby, Illinois.',
			)
		);
		$this->assertTrue( $result );
	}

	public function test_valid_full_payload_passes(): void {
		$result = rr_validate_llms_business_facts(
			array(
				'business_name'       => 'Kilday Baxter & Associates',
				'description'         => 'Full-service CPA firm in Oglesby, Illinois.',
				'tagline'             => 'Accounting and tax services for the Illinois Valley',
				'phone'               => '815-883-3500',
				'address'             => '755 W. Walnut Street, Oglesby, IL 61348',
				'hours'               => 'Monday-Friday 8:00 AM - 6:00 PM.',
				'years_in_business'   => '30+',
				'primary_services'    => array( 'Bookkeeping', 'Payroll' ),
				'service_area'        => array( 'Oglesby, IL', 'LaSalle, IL' ),
				'key_differentiators' => array( 'CPAs on staff' ),
				'common_questions'    => array(
					array(
						'question' => 'Do I need a CPA or is a bookkeeper enough?',
						'answer'   => 'It depends on the complexity of your finances.',
					),
				),
			)
		);
		$this->assertTrue( $result );
	}

	public function test_non_string_scalar_field_rejected(): void {
		$result = rr_validate_llms_business_facts(
			array(
				'business_name' => 'Acme',
				'description'   => 'Desc',
				'phone'         => array( '555-0100' ),
			)
		);
		$this->assertTrue( is_wp_error( $result ) );
	}

	public function test_oversized_string_field_rejected(): void {
		$result = rr_validate_llms_business_facts(
			array(
				'business_name' => 'Acme',
				'description'   => str_repeat( 'a', 5001 ),
			)
		);
		$this->assertTrue( is_wp_error( $result ) );
	}

	public function test_list_field_must_be_array(): void {
		$result = rr_validate_llms_business_facts(
			array(
				'business_name'    => 'Acme',
				'description'      => 'Desc',
				'primary_services' => 'Bookkeeping',
			)
		);
		$this->assertTrue( is_wp_error( $result ) );
	}

	public function test_oversized_list_field_rejected(): void {
		$result = rr_validate_llms_business_facts(
			array(
				'business_name'    => 'Acme',
				'description'      => 'Desc',
				'primary_services' => array_fill( 0, 51, 'Service' ),
			)
		);
		$this->assertTrue( is_wp_error( $result ) );
	}

	public function test_common_questions_requires_question_and_answer(): void {
		$result = rr_validate_llms_business_facts(
			array(
				'business_name'    => 'Acme',
				'description'      => 'Desc',
				'common_questions' => array( array( 'question' => 'Only a question?' ) ),
			)
		);
		$this->assertTrue( is_wp_error( $result ) );
	}

	// ── Renderer ──────────────────────────────────────────────────────────────

	public function test_render_llms_txt_includes_business_facts_without_sections_config(): void {
		$config = array(
			'business_facts' => array(
				'business_name'    => 'Kilday Baxter & Associates',
				'description'      => 'Full-service CPA firm.',
				'common_questions' => array(
					array( 'question' => 'Do I need a CPA?', 'answer' => 'It depends.' ),
				),
			),
		);

		$result = rr_render_llms_txt( $config );

		$this->assertStringContainsString( '## Business Facts', $result['content'] );
		$this->assertStringContainsString( 'Business: Kilday Baxter & Associates', $result['content'] );
		$this->assertStringContainsString( 'Description: Full-service CPA firm.', $result['content'] );
		$this->assertStringContainsString( '## Common Questions', $result['content'] );
		$this->assertStringContainsString( '### Do I need a CPA?', $result['content'] );
		$this->assertStringContainsString( 'It depends.', $result['content'] );
	}

	public function test_render_llms_txt_business_facts_not_duplicated_when_sections_configured(): void {
		$config = array(
			'business_facts' => array(
				'business_name' => 'Acme',
				'description'   => 'Desc',
			),
			'sections'       => array(
				'business_facts' => array( 'label' => 'About Us', 'order' => 1 ),
			),
		);

		$result = rr_render_llms_txt( $config );

		$this->assertSame( 1, substr_count( $result['content'], '## About Us' ) );
		$this->assertStringNotContainsString( '## Business Facts', $result['content'] );
	}

	public function test_render_business_facts_lines_includes_new_fields(): void {
		$config = array(
			'business_facts' => array(
				'business_name'       => 'Acme',
				'tagline'             => 'We paint things',
				'hours'               => 'Mon-Fri 8-6',
				'years_in_business'   => '30+',
				'key_differentiators' => array( 'Licensed', 'Insured' ),
			),
		);

		$lines   = rr_render_business_facts_lines( $config );
		$content = implode( "\n", $lines );

		$this->assertStringContainsString( 'Tagline: We paint things', $content );
		$this->assertStringContainsString( 'Hours: Mon-Fri 8-6', $content );
		$this->assertStringContainsString( 'Years in business: 30+', $content );
		$this->assertStringContainsString( 'Key differentiators: Licensed, Insured', $content );
	}
}
