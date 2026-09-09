<?php
/**
 * Module/Script Name: RankRocket SEO -- Pages (create_page action type)
 * Path: includes/class-rrseo-pages.php
 *
 * Description:
 * Validation and create pipeline for the typed action engine's create_page
 * action type (includes/class-rrseo-actions.php). Lets an audit workflow
 * create a new WordPress page as a draft for human review -- never
 * published directly through this path. No custom table or option; this
 * wraps core wp_insert_post()/wp_trash_post(), matching how create_redirect
 * wraps class-rrseo-redirects.php's own option-backed pipeline.
 *
 * Author(s):
 * Rank Rocket Co (C) Copyright 2026 - All Rights Reserved
 *
 * Created Date: 2026-09-09
 * Last Modified Date: 2026-09-09
 *
 * Comments:
 * v1.00 - Initial release. create_page action type: title (required),
 *         content/parent/template (optional), status hard-clamped to
 *         draft/pending -- publish is never accepted through this path.
 *
 * @package RankRocket_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Statuses create_page is allowed to create a page in. Deliberately excludes
// 'publish' -- pages made this way always need a separate, explicit human
// action to go live.
if ( ! defined( 'RR_PAGE_ALLOWED_STATUSES' ) ) {
	define( 'RR_PAGE_ALLOWED_STATUSES', array( 'draft', 'pending' ) );
}

// ── Validation (pure, unit-testable) ──────────────────────────────────────────

/**
 * Validates and normalizes a create_page field set.
 *
 * @param array $fields Raw input: title, content, parent, slug, template,
 *                       status.
 * @return array{errors: string[], warnings: string[], normalized: array}
 */
function rr_validate_page_fields( array $fields ) {
	$errors = array();

	$title = isset( $fields['title'] ) ? sanitize_text_field( (string) $fields['title'] ) : '';
	if ( '' === $title ) {
		$errors[] = 'title is required';
	}

	$status = 'draft';
	if ( array_key_exists( 'status', $fields ) && null !== $fields['status'] && '' !== $fields['status'] ) {
		$status = sanitize_text_field( (string) $fields['status'] );
	}
	if ( ! in_array( $status, RR_PAGE_ALLOWED_STATUSES, true ) ) {
		$errors[] = 'status must be one of: ' . implode( ', ', RR_PAGE_ALLOWED_STATUSES )
			. ' (pages are never published directly through this action)';
	}

	$content = isset( $fields['content'] ) ? wp_kses_post( (string) $fields['content'] ) : '';

	$parent = 0;
	if ( array_key_exists( 'parent', $fields ) && null !== $fields['parent'] && '' !== $fields['parent'] ) {
		$parent = absint( $fields['parent'] );
	}

	$slug = isset( $fields['slug'] ) ? sanitize_title( (string) $fields['slug'] ) : '';

	$template = isset( $fields['template'] ) ? sanitize_text_field( (string) $fields['template'] ) : '';

	if ( ! empty( $errors ) ) {
		return array(
			'errors'     => $errors,
			'warnings'   => array(),
			'normalized' => array(),
		);
	}

	return array(
		'errors'     => array(),
		'warnings'   => array(),
		'normalized' => array(
			'title'    => $title,
			'content'  => $content,
			'status'   => $status,
			'parent'   => $parent,
			'slug'     => $slug,
			'template' => $template,
		),
	);
}


// ── Pipeline (create) ──────────────────────────────────────────────────────────

/**
 * Validates and (unless dry-run) creates a new draft WordPress page.
 *
 * @param array $fields  Raw input fields.
 * @param bool  $dry_run True to validate and return the would-be result
 *                       without writing.
 * @return array{status: string, errors?: string[], post?: array}
 */
function rr_page_create( array $fields, $dry_run = false ) {
	$validation = rr_validate_page_fields( $fields );
	if ( ! empty( $validation['errors'] ) ) {
		return array(
			'status' => 'invalid',
			'errors' => $validation['errors'],
		);
	}

	$normalized = $validation['normalized'];

	if ( $dry_run ) {
		return array(
			'status' => 'simulated',
			'post'   => array_merge( array( 'id' => null ), $normalized ),
		);
	}

	$postarr = array(
		'post_type'    => 'page',
		'post_status'  => $normalized['status'],
		'post_title'   => $normalized['title'],
		'post_content' => $normalized['content'],
		'post_parent'  => $normalized['parent'],
	);
	if ( '' !== $normalized['slug'] ) {
		$postarr['post_name'] = $normalized['slug'];
	}
	if ( '' !== $normalized['template'] ) {
		$postarr['page_template'] = $normalized['template'];
	}

	$post_id = wp_insert_post( $postarr, true );
	if ( is_wp_error( $post_id ) ) {
		return array(
			'status' => 'invalid',
			'errors' => array( $post_id->get_error_message() ),
		);
	}

	return array(
		'status' => 'created',
		'post'   => array(
			'id'       => $post_id,
			'title'    => $normalized['title'],
			'status'   => $normalized['status'],
			'url'      => get_permalink( $post_id ),
			'edit_url' => get_edit_post_link( $post_id ),
		),
	);
}
