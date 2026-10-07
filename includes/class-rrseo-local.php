<?php
/**
 * Module/Script Name: RankRocket SEO -- Local SEO settings (issue #39, Stage 1)
 * Path: includes/class-rrseo-local.php
 *
 * Description:
 * A structured, REST-managed Local SEO / Knowledge Graph settings object
 * stored in the `rr_local_seo` option. It holds one entity (Organization or
 * Person) and any number of locations (address, geo, opening hours, phone,
 * area served, sameAs), validates every field strictly (rejecting rather
 * than sanitizing), and renders LocalBusiness / Organization / Person
 * JSON-LD into the page head.
 *
 * Design decisions (recorded in docs/rank-math-replacement-gap-report.md):
 * - Dedicated options object, not the snippets store: snippets are
 *   free-form blobs that cannot be validated per field or queried by
 *   location.
 * - Emission is opt-in (`enabled`, default false) and never duplicates a
 *   node already present in the post's stored schema graph or in an
 *   applicable snippet (same @id, or same entity type family and name).
 * - Stable node @ids: {home}/#organization (or #person) and
 *   {home}/#localbusiness-{slug}.
 * - Site-level writes are recorded in the rrseo_action_log option (not
 *   reversible), not in a per-post audit log.
 *
 * Stage 2 (v3.22.0): passive schema-emitter inventory, public-page scan for
 * duplicates printed by other plugins, a gate on enabling emission,
 * business_facts / entity-audit / schema-audit integration, and a read-only
 * import-from-snippets preview.
 *
 * Author(s):
 * Rank Rocket Co (C) Copyright 2026 - All Rights Reserved
 *
 * Created Date: 2026-10-07
 * Last Modified Date: 2026-10-07
 *
 * Comments:
 * v1.00 - Initial release (Stage 1): settings, CRUD, preview, emission.
 * v2.00 - Stage 2: shared snippet matcher, emitter inventory, public scan,
 *         enable gate, business facts, audit source, import preview.
 *
 * @package RankRocket_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'RR_LOCAL_SEO_KEY' ) ) {
	define( 'RR_LOCAL_SEO_KEY', 'rr_local_seo' );
}
if ( ! defined( 'RR_LOCAL_SEO_MAX_LOCATIONS' ) ) {
	define( 'RR_LOCAL_SEO_MAX_LOCATIONS', 50 );
}
if ( ! defined( 'RR_LOCAL_SEO_MAX_SAME_AS' ) ) {
	define( 'RR_LOCAL_SEO_MAX_SAME_AS', 20 );
}
if ( ! defined( 'RR_LOCAL_SEO_MAX_HOURS' ) ) {
	define( 'RR_LOCAL_SEO_MAX_HOURS', 14 );
}
if ( ! defined( 'RR_LOCAL_SEO_SCAN_MAX_PAGES' ) ) {
	define( 'RR_LOCAL_SEO_SCAN_MAX_PAGES', 10 );
}
if ( ! defined( 'RR_LOCAL_SEO_MAX_AREAS' ) ) {
	define( 'RR_LOCAL_SEO_MAX_AREAS', 50 );
}
if ( ! defined( 'RR_LOCAL_SEO_ENTITY_TYPES' ) ) {
	define( 'RR_LOCAL_SEO_ENTITY_TYPES', array( 'Organization', 'Person' ) );
}
if ( ! defined( 'RR_LOCAL_SEO_DAYS' ) ) {
	define( 'RR_LOCAL_SEO_DAYS', array( 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday' ) );
}
if ( ! defined( 'RR_LOCAL_SEO_LOCATION_FIELDS' ) ) {
	define(
		'RR_LOCAL_SEO_LOCATION_FIELDS',
		array(
			'name',
			'business_type',
			'url',
			'post_id',
			'phone',
			'email',
			'image',
			'price_range',
			'address',
			'geo',
			'opening_hours',
			'area_served',
			'same_as',
			'map_url',
			'service_area_only',
		)
	);
}
if ( ! defined( 'RR_LOCAL_SEO_BUSINESS_TYPES' ) ) {
	define(
		'RR_LOCAL_SEO_BUSINESS_TYPES',
		array(
			'LocalBusiness',
			'ProfessionalService',
			'Store',
			'HomeAndConstructionBusiness',
			'HVACBusiness',
			'Plumber',
			'Electrician',
			'RoofingContractor',
			'GeneralContractor',
			'Locksmith',
			'MovingCompany',
			'HousePainter',
			'AutomotiveBusiness',
			'AutoRepair',
			'HealthAndBeautyBusiness',
			'HealthClub',
			'SportsActivityLocation',
			'MedicalBusiness',
			'Dentist',
			'LegalService',
			'Attorney',
			'AccountingService',
			'FinancialService',
			'RealEstateAgent',
			'FoodEstablishment',
			'Restaurant',
			'LodgingBusiness',
			'EntertainmentBusiness',
			'ChildCare',
			'RecyclingCenter',
		)
	);
}

/**
 * Returns the allowed LocalBusiness subtype list (filterable).
 *
 * @return string[]
 */
function rr_local_seo_business_types(): array {
	$types = apply_filters( 'rrseo_local_business_types', RR_LOCAL_SEO_BUSINESS_TYPES );
	return is_array( $types ) ? array_values( array_filter( $types, 'is_string' ) ) : RR_LOCAL_SEO_BUSINESS_TYPES;
}

// -- Config storage -----------------------------------------------------------

/**
 * Returns the stored config merged over defaults.
 *
 * @return array{enabled: bool, entity: array, locations: array}
 */
function rr_local_seo_get_config(): array {
	$stored = get_option( RR_LOCAL_SEO_KEY, array() );
	$stored = is_array( $stored ) ? $stored : array();

	return array(
		'enabled'   => ! empty( $stored['enabled'] ),
		'entity'    => ( isset( $stored['entity'] ) && is_array( $stored['entity'] ) ) ? $stored['entity'] : array(),
		'locations' => ( isset( $stored['locations'] ) && is_array( $stored['locations'] ) ) ? array_values( $stored['locations'] ) : array(),
	);
}

/**
 * Persists a validated config and busts the option cache.
 *
 * @param array $config Validated config (enabled, entity, locations).
 * @return void
 */
function rr_local_seo_save_config( array $config ): void {
	update_option( RR_LOCAL_SEO_KEY, $config, false );
	rrseo_bust_option_cache( RR_LOCAL_SEO_KEY );
}

// -- Field validators ---------------------------------------------------------

/**
 * Validates a plain-text string field.
 *
 * Rejects non-strings, empty strings, over-length values, control characters
 * and angle brackets (none of which belong in business data).
 *
 * @param mixed  $value Raw value.
 * @param int    $max   Maximum length in characters.
 * @param string $label Field label for the error message.
 * @param array  $errors Error list, appended to by reference.
 * @return string|null Trimmed value, or null when invalid.
 */
function rr_local_seo_text( $value, int $max, string $label, array &$errors ): ?string {
	if ( ! is_string( $value ) ) {
		$errors[] = "{$label} must be a string";
		return null;
	}
	$value = trim( $value );
	if ( '' === $value ) {
		$errors[] = "{$label} must not be empty";
		return null;
	}
	$length = function_exists( 'mb_strlen' ) ? mb_strlen( $value ) : strlen( $value );
	if ( $length > $max ) {
		$errors[] = "{$label} must be at most {$max} characters";
		return null;
	}
	if ( 1 === preg_match( '/[\x00-\x1F\x7F<>]/', $value ) ) {
		$errors[] = "{$label} contains control characters or angle brackets";
		return null;
	}
	return $value;
}

/**
 * Validates an absolute https URL.
 *
 * @param mixed  $value  Raw value.
 * @param string $label  Field label for the error message.
 * @param array  $errors Error list, appended to by reference.
 * @return string|null Normalized URL, or null when invalid.
 */
function rr_local_seo_url( $value, string $label, array &$errors ): ?string {
	if ( ! is_string( $value ) ) {
		$errors[] = "{$label} must be a string URL";
		return null;
	}
	$value = trim( $value );
	$parts = wp_parse_url( $value );
	$valid = is_array( $parts ) && ! empty( $parts['host'] ) && isset( $parts['scheme'] ) && 'https' === $parts['scheme'];
	if ( strlen( $value ) > 2048 || ! $valid || false === filter_var( $value, FILTER_VALIDATE_URL ) ) {
		$errors[] = "{$label} must be an absolute https URL";
		return null;
	}
	return $value;
}

/**
 * Validates a list of https URLs (sameAs).
 *
 * @param mixed  $value  Raw value.
 * @param string $label  Field label.
 * @param array  $errors Error list, appended to by reference.
 * @return string[]|null Unique URLs, or null when invalid.
 */
function rr_local_seo_url_list( $value, string $label, array &$errors ): ?array {
	if ( ! is_array( $value ) || ! wp_is_numeric_array( $value ) ) {
		$errors[] = "{$label} must be an array of URLs";
		return null;
	}
	if ( count( $value ) > RR_LOCAL_SEO_MAX_SAME_AS ) {
		$errors[] = "{$label} may contain at most " . RR_LOCAL_SEO_MAX_SAME_AS . ' URLs';
		return null;
	}
	$out = array();
	foreach ( $value as $i => $url ) {
		$clean = rr_local_seo_url( $url, "{$label}[{$i}]", $errors );
		if ( null !== $clean ) {
			$out[ $clean ] = $clean;
		}
	}
	return array_values( $out );
}

/**
 * Validates a phone number (digits, spaces, + ( ) . -).
 *
 * @param mixed  $value  Raw value.
 * @param string $label  Field label.
 * @param array  $errors Error list, appended to by reference.
 * @return string|null
 */
function rr_local_seo_phone( $value, string $label, array &$errors ): ?string {
	if ( ! is_string( $value ) || 1 !== preg_match( '/^\+?[0-9 ().\-]{7,20}$/', trim( $value ) ) ) {
		$errors[] = "{$label} must be a phone number (7-20 digits, spaces, + ( ) . -)";
		return null;
	}
	return trim( $value );
}

/**
 * Validates an email address.
 *
 * @param mixed  $value  Raw value.
 * @param string $label  Field label.
 * @param array  $errors Error list, appended to by reference.
 * @return string|null
 */
function rr_local_seo_email( $value, string $label, array &$errors ): ?string {
	if ( ! is_string( $value ) || strlen( $value ) > 254 || false === filter_var( trim( $value ), FILTER_VALIDATE_EMAIL ) ) {
		$errors[] = "{$label} must be a valid email address";
		return null;
	}
	return trim( $value );
}

/**
 * Validates an image reference: an https URL or an attachment ID.
 *
 * @param mixed  $value  Raw value.
 * @param string $label  Field label.
 * @param array  $errors Error list, appended to by reference.
 * @return string|int|null
 */
function rr_local_seo_image( $value, string $label, array &$errors ) {
	if ( is_int( $value ) ) {
		$attachment = $value > 0 ? get_post( $value ) : null;
		if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
			$errors[] = "{$label} attachment ID does not match a media item";
			return null;
		}
		return $value;
	}
	return rr_local_seo_url( $value, $label, $errors );
}

/**
 * Rejects any key not in the allowlist.
 *
 * @param array  $input   Raw object.
 * @param array  $allowed Allowed keys.
 * @param string $label   Object label.
 * @param array  $errors  Error list, appended to by reference.
 * @return void
 */
function rr_local_seo_reject_unknown( array $input, array $allowed, string $label, array &$errors ): void {
	foreach ( array_keys( $input ) as $key ) {
		if ( ! in_array( $key, $allowed, true ) ) {
			$errors[] = "{$label}: unknown field '{$key}'";
		}
	}
}

// -- Entity -------------------------------------------------------------------

/**
 * Validates the entity (Organization or Person) object.
 *
 * @param mixed $input Raw entity object.
 * @return array{errors: string[], data: array}
 */
function rr_validate_local_entity( $input ): array {
	$errors = array();
	$data   = array();

	if ( ! is_array( $input ) || ( wp_is_numeric_array( $input ) && ! empty( $input ) ) ) {
		return array(
			'errors' => array( 'entity must be an object' ),
			'data'   => array(),
		);
	}

	rr_local_seo_reject_unknown(
		$input,
		array( 'type', 'name', 'legal_name', 'url', 'logo', 'description', 'email', 'phone', 'same_as', 'job_title' ),
		'entity',
		$errors
	);

	$type = isset( $input['type'] ) ? $input['type'] : null;
	if ( ! in_array( $type, RR_LOCAL_SEO_ENTITY_TYPES, true ) ) {
		$errors[] = 'entity.type must be one of: ' . implode( ', ', RR_LOCAL_SEO_ENTITY_TYPES );
	} else {
		$data['type'] = $type;
	}

	if ( ! isset( $input['name'] ) ) {
		$errors[] = 'entity.name is required';
	} else {
		$name = rr_local_seo_text( $input['name'], 200, 'entity.name', $errors );
		if ( null !== $name ) {
			$data['name'] = $name;
		}
	}

	foreach ( array(
		'legal_name'  => array( 200, 'Organization' ),
		'job_title'   => array( 120, 'Person' ),
		'description' => array( 1000, null ),
	) as $field => $rule ) {
		if ( ! isset( $input[ $field ] ) ) {
			continue;
		}
		if ( null !== $rule[1] && $rule[1] !== $type ) {
			$errors[] = "entity.{$field} is only valid for type {$rule[1]}";
			continue;
		}
		$clean = rr_local_seo_text( $input[ $field ], $rule[0], "entity.{$field}", $errors );
		if ( null !== $clean ) {
			$data[ $field ] = $clean;
		}
	}

	if ( isset( $input['url'] ) ) {
		$clean = rr_local_seo_url( $input['url'], 'entity.url', $errors );
		if ( null !== $clean ) {
			$data['url'] = $clean;
		}
	}
	if ( isset( $input['logo'] ) ) {
		$clean = rr_local_seo_image( $input['logo'], 'entity.logo', $errors );
		if ( null !== $clean ) {
			$data['logo'] = $clean;
		}
	}
	if ( isset( $input['email'] ) ) {
		$clean = rr_local_seo_email( $input['email'], 'entity.email', $errors );
		if ( null !== $clean ) {
			$data['email'] = $clean;
		}
	}
	if ( isset( $input['phone'] ) ) {
		$clean = rr_local_seo_phone( $input['phone'], 'entity.phone', $errors );
		if ( null !== $clean ) {
			$data['phone'] = $clean;
		}
	}
	if ( isset( $input['same_as'] ) ) {
		$clean = rr_local_seo_url_list( $input['same_as'], 'entity.same_as', $errors );
		if ( null !== $clean ) {
			$data['same_as'] = $clean;
		}
	}

	return array(
		'errors' => $errors,
		'data'   => $data,
	);
}

// -- Locations ----------------------------------------------------------------

/**
 * Validates the address object of a location.
 *
 * @param mixed $input        Raw address.
 * @param bool  $area_only    True for a service-area-only business (street optional).
 * @param array $errors       Error list, appended to by reference.
 * @return array|null Validated address, or null when invalid.
 */
function rr_local_seo_address( $input, bool $area_only, array &$errors ): ?array {
	if ( ! is_array( $input ) || wp_is_numeric_array( $input ) ) {
		$errors[] = 'address must be an object';
		return null;
	}
	$before = count( $errors );
	rr_local_seo_reject_unknown( $input, array( 'street', 'locality', 'region', 'postal_code', 'country' ), 'address', $errors );

	$out      = array();
	$required = array( 'locality', 'region', 'country' );
	if ( ! $area_only ) {
		$required[] = 'street';
	}
	foreach ( array( 'street', 'locality', 'region', 'postal_code', 'country' ) as $field ) {
		if ( ! isset( $input[ $field ] ) ) {
			if ( in_array( $field, $required, true ) ) {
				$errors[] = "address.{$field} is required";
			}
			continue;
		}
		if ( 'country' === $field ) {
			if ( ! is_string( $input[ $field ] ) || 1 !== preg_match( '/^[A-Z]{2}$/', $input[ $field ] ) ) {
				$errors[] = 'address.country must be an ISO 3166-1 alpha-2 code (for example US)';
				continue;
			}
			$out[ $field ] = $input[ $field ];
			continue;
		}
		$clean = rr_local_seo_text( $input[ $field ], 200, "address.{$field}", $errors );
		if ( null !== $clean ) {
			$out[ $field ] = $clean;
		}
	}
	return count( $errors ) === $before ? $out : null;
}

/**
 * Validates the geo object of a location.
 *
 * @param mixed $input  Raw geo.
 * @param array $errors Error list, appended to by reference.
 * @return array|null Validated {lat, lng}, or null when invalid.
 */
function rr_local_seo_geo( $input, array &$errors ): ?array {
	if ( ! is_array( $input ) || ! isset( $input['lat'], $input['lng'] ) || count( $input ) !== 2 ) {
		$errors[] = 'geo must be an object with exactly lat and lng';
		return null;
	}
	if ( ! is_numeric( $input['lat'] ) || ! is_numeric( $input['lng'] ) || is_string( $input['lat'] ) || is_string( $input['lng'] ) ) {
		$errors[] = 'geo.lat and geo.lng must be numbers';
		return null;
	}
	$lat = (float) $input['lat'];
	$lng = (float) $input['lng'];
	if ( $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180 ) {
		$errors[] = 'geo.lat must be between -90 and 90 and geo.lng between -180 and 180';
		return null;
	}
	return array(
		'lat' => $lat,
		'lng' => $lng,
	);
}

/**
 * Validates opening hours rows.
 *
 * @param mixed $input  Raw list of {days, opens, closes}.
 * @param array $errors Error list, appended to by reference.
 * @return array|null Validated rows, or null when invalid.
 */
function rr_local_seo_hours( $input, array &$errors ): ?array {
	if ( ! is_array( $input ) || ! wp_is_numeric_array( $input ) ) {
		$errors[] = 'opening_hours must be an array';
		return null;
	}
	if ( count( $input ) > RR_LOCAL_SEO_MAX_HOURS ) {
		$errors[] = 'opening_hours may contain at most ' . RR_LOCAL_SEO_MAX_HOURS . ' rows';
		return null;
	}
	$before = count( $errors );
	$out    = array();
	$time   = '/^([01]\d|2[0-3]):[0-5]\d$/';
	foreach ( $input as $i => $row ) {
		if ( ! is_array( $row ) || array_diff( array_keys( $row ), array( 'days', 'opens', 'closes' ) ) ) {
			$errors[] = "opening_hours[{$i}] must be an object with only days, opens, closes";
			continue;
		}
		$days_ok = isset( $row['days'] ) && is_array( $row['days'] ) && ! empty( $row['days'] ) && wp_is_numeric_array( $row['days'] );
		if ( ! $days_ok || ! isset( $row['opens'], $row['closes'] ) ) {
			$errors[] = "opening_hours[{$i}] requires a non-empty days array, opens and closes";
			continue;
		}
		$days = array_values( array_unique( $row['days'] ) );
		if ( array_diff( $days, RR_LOCAL_SEO_DAYS ) ) {
			$errors[] = "opening_hours[{$i}].days must be from: " . implode( ', ', RR_LOCAL_SEO_DAYS );
			continue;
		}
		$times_ok = is_string( $row['opens'] ) && is_string( $row['closes'] )
			&& 1 === preg_match( $time, $row['opens'] ) && 1 === preg_match( $time, $row['closes'] );
		if ( ! $times_ok ) {
			$errors[] = "opening_hours[{$i}] opens/closes must be HH:MM (00:00-23:59)";
			continue;
		}
		if ( $row['opens'] === $row['closes'] ) {
			$errors[] = "opening_hours[{$i}] opens and closes must differ";
			continue;
		}
		$out[] = array(
			'days'   => $days,
			'opens'  => $row['opens'],
			'closes' => $row['closes'],
		);
	}
	return count( $errors ) === $before ? $out : null;
}

/**
 * Validates one location object.
 *
 * @param mixed $input Raw location.
 * @return array{errors: string[], data: array}
 */
function rr_validate_local_location( $input ): array {
	$errors = array();
	$data   = array();

	if ( ! is_array( $input ) || wp_is_numeric_array( $input ) ) {
		return array(
			'errors' => array( 'location must be an object' ),
			'data'   => array(),
		);
	}

	rr_local_seo_reject_unknown(
		$input,
		array_merge( array( 'id' ), RR_LOCAL_SEO_LOCATION_FIELDS ),
		'location',
		$errors
	);

	$id_ok = isset( $input['id'] ) && is_string( $input['id'] ) && strlen( $input['id'] ) <= 40
		&& 1 === preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $input['id'] );
	if ( ! $id_ok ) {
		$errors[] = 'location.id is required: lowercase letters, digits and single hyphens, at most 40 characters';
	} else {
		$data['id'] = $input['id'];
	}

	if ( ! isset( $input['name'] ) ) {
		$errors[] = 'location.name is required';
	} else {
		$name = rr_local_seo_text( $input['name'], 200, 'location.name', $errors );
		if ( null !== $name ) {
			$data['name'] = $name;
		}
	}

	$type = isset( $input['business_type'] ) ? $input['business_type'] : 'LocalBusiness';
	if ( ! is_string( $type ) || ! in_array( $type, rr_local_seo_business_types(), true ) ) {
		$errors[] = 'location.business_type must be one of: ' . implode( ', ', rr_local_seo_business_types() );
	} else {
		$data['business_type'] = $type;
	}

	$area_only = false;
	if ( isset( $input['service_area_only'] ) ) {
		if ( ! is_bool( $input['service_area_only'] ) ) {
			$errors[] = 'location.service_area_only must be a boolean';
		} else {
			$area_only                 = $input['service_area_only'];
			$data['service_area_only'] = $area_only;
		}
	}

	if ( ! isset( $input['address'] ) ) {
		$errors[] = 'location.address is required';
	} else {
		$address = rr_local_seo_address( $input['address'], $area_only, $errors );
		if ( null !== $address ) {
			$data['address'] = $address;
		}
	}

	if ( isset( $input['post_id'] ) ) {
		if ( ! is_int( $input['post_id'] ) || $input['post_id'] < 1 || ! get_post( $input['post_id'] ) ) {
			$errors[] = 'location.post_id must be the ID of an existing post or page';
		} else {
			$data['post_id'] = $input['post_id'];
		}
	}
	if ( isset( $input['phone'] ) ) {
		$clean = rr_local_seo_phone( $input['phone'], 'location.phone', $errors );
		if ( null !== $clean ) {
			$data['phone'] = $clean;
		}
	}
	if ( isset( $input['email'] ) ) {
		$clean = rr_local_seo_email( $input['email'], 'location.email', $errors );
		if ( null !== $clean ) {
			$data['email'] = $clean;
		}
	}
	foreach ( array( 'url', 'map_url' ) as $field ) {
		if ( isset( $input[ $field ] ) ) {
			$clean = rr_local_seo_url( $input[ $field ], "location.{$field}", $errors );
			if ( null !== $clean ) {
				$data[ $field ] = $clean;
			}
		}
	}
	if ( isset( $input['image'] ) ) {
		$clean = rr_local_seo_image( $input['image'], 'location.image', $errors );
		if ( null !== $clean ) {
			$data['image'] = $clean;
		}
	}
	if ( isset( $input['price_range'] ) ) {
		$clean = rr_local_seo_text( $input['price_range'], 30, 'location.price_range', $errors );
		if ( null !== $clean ) {
			$data['price_range'] = $clean;
		}
	}
	if ( isset( $input['geo'] ) ) {
		$clean = rr_local_seo_geo( $input['geo'], $errors );
		if ( null !== $clean ) {
			$data['geo'] = $clean;
		}
	}
	if ( isset( $input['opening_hours'] ) ) {
		$clean = rr_local_seo_hours( $input['opening_hours'], $errors );
		if ( null !== $clean ) {
			$data['opening_hours'] = $clean;
		}
	}
	if ( isset( $input['same_as'] ) ) {
		$clean = rr_local_seo_url_list( $input['same_as'], 'location.same_as', $errors );
		if ( null !== $clean ) {
			$data['same_as'] = $clean;
		}
	}
	if ( isset( $input['area_served'] ) ) {
		$areas_ok = is_array( $input['area_served'] ) && wp_is_numeric_array( $input['area_served'] )
			&& count( $input['area_served'] ) <= RR_LOCAL_SEO_MAX_AREAS;
		if ( ! $areas_ok ) {
			$errors[] = 'location.area_served must be an array of at most ' . RR_LOCAL_SEO_MAX_AREAS . ' place names';
		} else {
			$areas = array();
			foreach ( $input['area_served'] as $i => $place ) {
				$clean = rr_local_seo_text( $place, 100, "location.area_served[{$i}]", $errors );
				if ( null !== $clean ) {
					$areas[ $clean ] = $clean;
				}
			}
			$data['area_served'] = array_values( $areas );
		}
	}

	return array(
		'errors' => $errors,
		'data'   => $data,
	);
}

/**
 * Validates a settings write and merges it over the current config.
 *
 * Only the keys present in $input are replaced (enabled, entity, locations).
 * Enabling emission requires a valid entity.
 *
 * @param array $input   Request fields (enabled, entity, locations).
 * @param array $current Current stored config.
 * @return array{errors: string[], config: array}
 */
function rr_validate_local_seo( array $input, array $current ): array {
	$errors = array();
	$config = $current;

	rr_local_seo_reject_unknown( $input, array( 'enabled', 'entity', 'locations' ), 'settings', $errors );

	if ( array_key_exists( 'enabled', $input ) ) {
		if ( ! is_bool( $input['enabled'] ) ) {
			$errors[] = 'enabled must be a boolean';
		} else {
			$config['enabled'] = $input['enabled'];
		}
	}

	if ( array_key_exists( 'entity', $input ) ) {
		if ( is_array( $input['entity'] ) && empty( $input['entity'] ) ) {
			$config['entity'] = array();
		} else {
			$result = rr_validate_local_entity( $input['entity'] );
			$errors = array_merge( $errors, $result['errors'] );
			if ( empty( $result['errors'] ) ) {
				$config['entity'] = $result['data'];
			}
		}
	}

	if ( array_key_exists( 'locations', $input ) ) {
		if ( ! is_array( $input['locations'] ) || ( ! empty( $input['locations'] ) && ! wp_is_numeric_array( $input['locations'] ) ) ) {
			$errors[] = 'locations must be an array';
		} elseif ( count( $input['locations'] ) > RR_LOCAL_SEO_MAX_LOCATIONS ) {
			$errors[] = 'locations may contain at most ' . RR_LOCAL_SEO_MAX_LOCATIONS . ' items';
		} else {
			$locations = array();
			$seen      = array();
			foreach ( $input['locations'] as $i => $location ) {
				$result = rr_validate_local_location( $location );
				foreach ( $result['errors'] as $message ) {
					$errors[] = "locations[{$i}]: {$message}";
				}
				if ( empty( $result['errors'] ) ) {
					if ( isset( $seen[ $result['data']['id'] ] ) ) {
						$errors[] = "locations[{$i}]: duplicate id '{$result['data']['id']}'";
						continue;
					}
					$seen[ $result['data']['id'] ] = true;
					$locations[]                   = $result['data'];
				}
			}
			if ( empty( $errors ) ) {
				$config['locations'] = $locations;
			}
		}
	}

	if ( ! empty( $config['enabled'] ) && empty( $config['entity'] ) ) {
		$errors[] = 'enabled requires an entity: write entity first or in the same request';
	}

	return array(
		'errors' => $errors,
		'config' => $config,
	);
}

/**
 * Upserts or removes a single location on a config.
 *
 * @param array      $config Current config.
 * @param string     $id     Location slug from the route.
 * @param array|null $fields Location fields to upsert, or null to delete.
 * @return array{errors: string[], config: array, found: bool}
 */
function rr_local_seo_apply_location( array $config, string $id, ?array $fields ): array {
	$index = null;
	foreach ( $config['locations'] as $i => $location ) {
		if ( isset( $location['id'] ) && $location['id'] === $id ) {
			$index = $i;
			break;
		}
	}

	if ( null === $fields ) {
		if ( null === $index ) {
			return array(
				'errors' => array(),
				'config' => $config,
				'found'  => false,
			);
		}
		unset( $config['locations'][ $index ] );
		$config['locations'] = array_values( $config['locations'] );
		return array(
			'errors' => array(),
			'config' => $config,
			'found'  => true,
		);
	}

	if ( isset( $fields['id'] ) && $fields['id'] !== $id ) {
		return array(
			'errors' => array( "location.id '{$fields['id']}' does not match the route id '{$id}'" ),
			'config' => $config,
			'found'  => null !== $index,
		);
	}
	$fields['id'] = $id;
	$result       = rr_validate_local_location( $fields );
	if ( ! empty( $result['errors'] ) ) {
		return array(
			'errors' => $result['errors'],
			'config' => $config,
			'found'  => null !== $index,
		);
	}
	if ( null === $index && count( $config['locations'] ) >= RR_LOCAL_SEO_MAX_LOCATIONS ) {
		return array(
			'errors' => array( 'locations may contain at most ' . RR_LOCAL_SEO_MAX_LOCATIONS . ' items' ),
			'config' => $config,
			'found'  => false,
		);
	}
	if ( null === $index ) {
		$config['locations'][] = $result['data'];
	} else {
		$config['locations'][ $index ] = $result['data'];
	}
	return array(
		'errors' => array(),
		'config' => $config,
		'found'  => null !== $index,
	);
}

// -- Node builders ------------------------------------------------------------

/**
 * Returns the stable @id for the entity node.
 *
 * @param array $entity Entity config.
 * @return string
 */
function rr_local_seo_entity_id( array $entity ): string {
	$anchor = ( isset( $entity['type'] ) && 'Person' === $entity['type'] ) ? '#person' : '#organization';
	return trailingslashit( home_url( '/' ) ) . $anchor;
}

/**
 * Returns the stable @id for a location node.
 *
 * @param string $slug Location slug.
 * @return string
 */
function rr_local_seo_location_id( string $slug ): string {
	return trailingslashit( home_url( '/' ) ) . '#localbusiness-' . $slug;
}

/**
 * Resolves an image reference to a URL.
 *
 * @param string|int $image Https URL or attachment ID.
 * @return string Empty string when it cannot be resolved.
 */
function rr_local_seo_image_url( $image ): string {
	if ( is_int( $image ) ) {
		$url = function_exists( 'wp_get_attachment_url' ) ? wp_get_attachment_url( $image ) : '';
		return is_string( $url ) ? $url : '';
	}
	return is_string( $image ) ? $image : '';
}

/**
 * Builds the entity (Organization or Person) JSON-LD node.
 *
 * @param array $entity Validated entity config.
 * @return array
 */
function rr_local_seo_build_entity_node( array $entity ): array {
	$node = array(
		'@type' => $entity['type'],
		'@id'   => rr_local_seo_entity_id( $entity ),
		'name'  => $entity['name'],
		'url'   => isset( $entity['url'] ) ? $entity['url'] : trailingslashit( home_url( '/' ) ),
	);
	if ( ! empty( $entity['legal_name'] ) ) {
		$node['legalName'] = $entity['legal_name'];
	}
	if ( ! empty( $entity['job_title'] ) ) {
		$node['jobTitle'] = $entity['job_title'];
	}
	if ( ! empty( $entity['description'] ) ) {
		$node['description'] = $entity['description'];
	}
	if ( ! empty( $entity['email'] ) ) {
		$node['email'] = $entity['email'];
	}
	if ( ! empty( $entity['phone'] ) ) {
		$node['telephone'] = $entity['phone'];
	}
	if ( ! empty( $entity['logo'] ) ) {
		$logo = rr_local_seo_image_url( $entity['logo'] );
		if ( '' !== $logo ) {
			if ( 'Person' === $entity['type'] ) {
				$node['image'] = $logo;
			} else {
				$node['logo'] = array(
					'@type' => 'ImageObject',
					'url'   => $logo,
				);
			}
		}
	}
	if ( ! empty( $entity['same_as'] ) ) {
		$node['sameAs'] = $entity['same_as'];
	}
	return $node;
}

/**
 * Builds a LocalBusiness-family JSON-LD node for one location.
 *
 * @param array $location Validated location config.
 * @param array $entity   Validated entity config (may be empty).
 * @return array
 */
function rr_local_seo_build_location_node( array $location, array $entity ): array {
	$node = array(
		'@type' => isset( $location['business_type'] ) ? $location['business_type'] : 'LocalBusiness',
		'@id'   => rr_local_seo_location_id( $location['id'] ),
		'name'  => $location['name'],
	);

	if ( ! empty( $location['url'] ) ) {
		$node['url'] = $location['url'];
	} elseif ( ! empty( $location['post_id'] ) ) {
		$permalink = get_permalink( $location['post_id'] );
		if ( is_string( $permalink ) && '' !== $permalink ) {
			$node['url'] = $permalink;
		}
	}
	if ( ! empty( $location['phone'] ) ) {
		$node['telephone'] = $location['phone'];
	}
	if ( ! empty( $location['email'] ) ) {
		$node['email'] = $location['email'];
	}
	if ( ! empty( $location['image'] ) ) {
		$image = rr_local_seo_image_url( $location['image'] );
		if ( '' !== $image ) {
			$node['image'] = $image;
		}
	}
	if ( ! empty( $location['price_range'] ) ) {
		$node['priceRange'] = $location['price_range'];
	}

	$address = $location['address'];
	$postal  = array(
		'@type'           => 'PostalAddress',
		'addressLocality' => $address['locality'],
		'addressRegion'   => $address['region'],
		'addressCountry'  => $address['country'],
	);
	if ( isset( $address['street'] ) ) {
		$postal['streetAddress'] = $address['street'];
	}
	if ( isset( $address['postal_code'] ) ) {
		$postal['postalCode'] = $address['postal_code'];
	}
	$node['address'] = $postal;

	if ( ! empty( $location['geo'] ) ) {
		$node['geo'] = array(
			'@type'     => 'GeoCoordinates',
			'latitude'  => $location['geo']['lat'],
			'longitude' => $location['geo']['lng'],
		);
	}
	if ( ! empty( $location['opening_hours'] ) ) {
		$specs = array();
		foreach ( $location['opening_hours'] as $row ) {
			$specs[] = array(
				'@type'     => 'OpeningHoursSpecification',
				'dayOfWeek' => $row['days'],
				'opens'     => $row['opens'],
				'closes'    => $row['closes'],
			);
		}
		$node['openingHoursSpecification'] = $specs;
	}
	if ( ! empty( $location['area_served'] ) ) {
		$areas = array();
		foreach ( $location['area_served'] as $place ) {
			$areas[] = array(
				'@type' => 'Place',
				'name'  => $place,
			);
		}
		$node['areaServed'] = $areas;
	}
	if ( ! empty( $location['same_as'] ) ) {
		$node['sameAs'] = $location['same_as'];
	}
	if ( ! empty( $location['map_url'] ) ) {
		$node['hasMap'] = $location['map_url'];
	}
	if ( ! empty( $entity['type'] ) && 'Organization' === $entity['type'] ) {
		$node['parentOrganization'] = array( '@id' => rr_local_seo_entity_id( $entity ) );
	}
	return $node;
}

// -- Duplicate detection ------------------------------------------------------

/**
 * Returns the normalized @type values of a node as a list of strings.
 *
 * @param array $node JSON-LD node.
 * @return string[]
 */
function rr_local_seo_node_types( array $node ): array {
	if ( ! isset( $node['@type'] ) ) {
		return array();
	}
	$types = array_filter( (array) $node['@type'], 'is_string' );
	return array_values( array_map( 'rr_schema_normalize_type', $types ) );
}

/**
 * Normalizes a node name for comparison.
 *
 * @param array $node JSON-LD node.
 * @return string Lowercase, whitespace-collapsed name; empty when absent.
 */
function rr_local_seo_node_name( array $node ): string {
	if ( ! isset( $node['name'] ) || ! is_string( $node['name'] ) ) {
		return '';
	}
	return strtolower( trim( (string) preg_replace( '/\s+/', ' ', $node['name'] ) ) );
}

/**
 * Tests whether a node duplicates one that is already present.
 *
 * Duplicate means the same @id, or the same name within the same entity
 * family (business-type family, or Person).
 *
 * @param array   $node     Candidate node.
 * @param array[] $existing Nodes already emitted or stored for the page.
 * @return string|null Reason ('same_id' or 'same_type_and_name'), or null.
 */
function rr_local_seo_duplicate_reason( array $node, array $existing ): ?string {
	$business = array_merge( RR_AEO_LOCAL_ENTITY_TYPES, rr_local_seo_business_types() );
	$types    = rr_local_seo_node_types( $node );
	$family   = array_intersect( $types, array( 'Person' ) ) ? array( 'Person' ) : $business;
	$name     = rr_local_seo_node_name( $node );

	foreach ( $existing as $other ) {
		if ( ! is_array( $other ) ) {
			continue;
		}
		if ( isset( $node['@id'], $other['@id'] ) && $node['@id'] === $other['@id'] ) {
			return 'same_id';
		}
		if ( '' !== $name && rr_local_seo_node_name( $other ) === $name
			&& array_intersect( $types, $family ) && array_intersect( rr_local_seo_node_types( $other ), $family ) ) {
			return 'same_type_and_name';
		}
	}
	return null;
}

/**
 * Collects the JSON-LD nodes of active snippets that apply to a page.
 *
 * Applicability uses rr_snippet_applies_to_post(), the same matcher the
 * schema audit uses, so the two cannot drift. Honors the global snippet
 * emission killswitch.
 *
 * @param int    $post_id   Queried post ID (0 when none).
 * @param bool   $is_home   True for the front page.
 * @param string $post_type Post type of the queried post.
 * @return array[]
 */
function rr_local_seo_snippet_nodes( int $post_id, bool $is_home, string $post_type ): array {
	$snippets = get_option( RMB_SNIPPETS_KEY, array() );
	if ( ! is_array( $snippets ) || ! (bool) get_option( 'rrseo_emit_snippets', true ) ) {
		return array();
	}
	$path = '/';
	if ( ! $is_home && $post_id > 0 ) {
		$parsed = wp_parse_url( (string) get_permalink( $post_id ), PHP_URL_PATH );
		$path   = is_string( $parsed ) ? $parsed : '/';
	}
	$ctx   = array(
		'post_id'   => $post_id,
		'post_type' => $post_type,
		'is_front'  => $is_home,
		'path'      => $path,
	);
	$nodes = array();
	foreach ( $snippets as $snippet ) {
		if ( ! is_array( $snippet ) || ! rr_snippet_applies_to_post( $snippet, $ctx ) ) {
			continue;
		}
		foreach ( rr_schema_extract_jsonld_blocks( (string) $snippet['content'] ) as $block ) {
			if ( $block['valid'] ) {
				$nodes = array_merge( $nodes, rr_schema_graph_nodes( $block['data'] ) );
			}
		}
	}
	return $nodes;
}

// -- Emission plan ------------------------------------------------------------

/**
 * Plans which nodes a page would emit, with duplicates removed.
 *
 * The front page gets the entity plus every location. A page assigned to
 * one or more locations (location.post_id) gets just those location nodes.
 * Nodes already present in the post's stored schema graph or in an
 * applicable snippet are skipped and reported.
 *
 * @param array $config   Local SEO config.
 * @param int   $post_id  Queried post ID (0 for none).
 * @param bool  $is_home  True for the front page.
 * @return array{nodes: array[], skipped: array[]}
 */
function rr_local_seo_plan( array $config, int $post_id, bool $is_home ): array {
	$entity     = $config['entity'];
	$candidates = array();

	if ( ! empty( $entity ) ) {
		if ( $is_home ) {
			$candidates[] = rr_local_seo_build_entity_node( $entity );
		}
		foreach ( $config['locations'] as $location ) {
			if ( $is_home || ( $post_id > 0 && isset( $location['post_id'] ) && (int) $location['post_id'] === $post_id ) ) {
				$candidates[] = rr_local_seo_build_location_node( $location, $entity );
			}
		}
	}

	$existing  = array();
	$post_type = '';
	if ( $post_id > 0 ) {
		$existing  = rr_schema_graph_nodes( get_post_meta( $post_id, RR_SCHEMA_META_KEY, true ) );
		$post      = get_post( $post_id );
		$post_type = ( $post && isset( $post->post_type ) ) ? (string) $post->post_type : '';
	}
	$existing = array_merge( $existing, rr_local_seo_snippet_nodes( $post_id, $is_home, $post_type ) );

	$nodes   = array();
	$skipped = array();
	foreach ( $candidates as $candidate ) {
		$reason = rr_local_seo_duplicate_reason( $candidate, $existing );
		if ( null === $reason ) {
			$nodes[] = $candidate;
		} else {
			$skipped[] = array(
				'@id'    => $candidate['@id'],
				'reason' => $reason,
			);
		}
	}

	return array(
		'nodes'   => $nodes,
		'skipped' => $skipped,
	);
}

/**
 * Emits the Local SEO JSON-LD block in the page head.
 *
 * @return void
 */
function rr_local_seo_emit(): void {
	if ( is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
		return;
	}
	if ( ! apply_filters( 'rrseo_local_seo_emit', true ) ) {
		return;
	}
	$config = rr_local_seo_get_config();
	if ( ! $config['enabled'] ) {
		return;
	}

	$is_home = is_front_page();
	$post_id = is_singular() ? (int) get_queried_object_id() : 0;
	if ( $is_home && 0 === $post_id ) {
		$post_id = (int) get_option( 'page_on_front' );
	}
	if ( ! $is_home && 0 === $post_id ) {
		return;
	}

	$plan = rr_local_seo_plan( $config, $post_id, $is_home );
	if ( empty( $plan['nodes'] ) ) {
		return;
	}

	$payload = array(
		'@context' => 'https://schema.org',
		'@graph'   => $plan['nodes'],
	);
	echo RR_SCHEMA_HYGIENE_MARKER_START . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- constant, not user input.
	echo '<script type="application/ld+json">' . "\n";
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON-LD, encoded by wp_json_encode.
	echo wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
	echo "\n</script>\n";
	echo RR_SCHEMA_HYGIENE_MARKER_END . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- constant, not user input.
}
add_action( 'wp_head', 'rr_local_seo_emit', 6 );

// -- Stage 2: other schema emitters -------------------------------------------

/**
 * Returns plugins known to print schema or arbitrary head code, keyed by
 * plugin directory slug.
 *
 * @return array<string, array{name: string, kind: string}>
 */
function rr_local_seo_known_emitters(): array {
	$known = array(
		'seo-by-rank-math'                  => array(
			'name' => 'Rank Math SEO',
			'kind' => 'seo',
		),
		'seo-by-rank-math-pro'              => array(
			'name' => 'Rank Math SEO PRO',
			'kind' => 'seo',
		),
		'wordpress-seo'                     => array(
			'name' => 'Yoast SEO',
			'kind' => 'seo',
		),
		'wordpress-seo-premium'             => array(
			'name' => 'Yoast SEO Premium',
			'kind' => 'seo',
		),
		'all-in-one-seo-pack'               => array(
			'name' => 'All in One SEO',
			'kind' => 'seo',
		),
		'wp-seopress'                       => array(
			'name' => 'SEOPress',
			'kind' => 'seo',
		),
		'schema-and-structured-data-for-wp' => array(
			'name' => 'Schema and Structured Data for WP',
			'kind' => 'seo',
		),
		'wp-schema-pro'                     => array(
			'name' => 'Schema Pro',
			'kind' => 'seo',
		),
		'header-footer-code-manager'        => array(
			'name' => 'Header Footer Code Manager (HFCM)',
			'kind' => 'code_injection',
		),
		'insert-headers-and-footers'        => array(
			'name' => 'WPCode',
			'kind' => 'code_injection',
		),
		'wpcode-premium'                    => array(
			'name' => 'WPCode Pro',
			'kind' => 'code_injection',
		),
		'code-snippets'                     => array(
			'name' => 'Code Snippets',
			'kind' => 'code_injection',
		),
	);
	$known = apply_filters( 'rrseo_local_seo_known_emitters', $known );
	return is_array( $known ) ? $known : array();
}

/**
 * Lists active plugins that are known to print schema or head code.
 *
 * Passive and HTTP-free: it only reads the active-plugin options. The result
 * is a hint, not proof, that a plugin prints Organization or LocalBusiness
 * schema; the public scan is the authoritative check.
 *
 * @return array<int, array{slug: string, name: string, kind: string}>
 */
function rr_local_seo_active_emitters(): array {
	$active = get_option( 'active_plugins', array() );
	$active = is_array( $active ) ? $active : array();
	$site   = get_option( 'active_sitewide_plugins', array() );
	if ( is_array( $site ) ) {
		$active = array_merge( $active, array_keys( $site ) );
	}

	$slugs = array();
	foreach ( $active as $plugin_file ) {
		if ( ! is_string( $plugin_file ) ) {
			continue;
		}
		$slug = strpos( $plugin_file, '/' ) !== false ? strstr( $plugin_file, '/', true ) : basename( $plugin_file, '.php' );
		if ( is_string( $slug ) && '' !== $slug ) {
			$slugs[ $slug ] = true;
		}
	}

	$out = array();
	foreach ( rr_local_seo_known_emitters() as $slug => $info ) {
		if ( isset( $slugs[ $slug ] ) ) {
			$out[] = array(
				'slug' => $slug,
				'name' => $info['name'],
				'kind' => $info['kind'],
			);
		}
	}
	return $out;
}

// -- Stage 2: public-page scan ------------------------------------------------

/**
 * Removes this plugin's own marker-wrapped schema blocks from page HTML so
 * that only schema printed by something else is left.
 *
 * @param string $html Page HTML.
 * @return string
 */
function rr_local_seo_strip_own_blocks( string $html ): string {
	$pattern = '/' . preg_quote( RR_SCHEMA_HYGIENE_MARKER_START, '/' ) . '.*?' . preg_quote( RR_SCHEMA_HYGIENE_MARKER_END, '/' ) . '/s';
	$out     = preg_replace( $pattern, '', $html );
	return is_string( $out ) ? $out : $html;
}

/**
 * Fetches a page's public HTML for scanning.
 *
 * The filter `rrseo_local_seo_public_html` can supply the HTML directly
 * (used by tests and by hosts that cannot loop back to themselves).
 *
 * @param int $post_id Post ID of the page (0 when there is no page post).
 * @return array{html: string|null, error: string|null}
 */
function rr_local_seo_fetch_public_html( int $post_id ): array {
	$supplied = apply_filters( 'rrseo_local_seo_public_html', null, $post_id );
	if ( is_string( $supplied ) ) {
		return array(
			'html'  => $supplied,
			'error' => null,
		);
	}
	$post = $post_id > 0 ? get_post( $post_id ) : null;
	if ( ! $post instanceof WP_Post ) {
		return array(
			'html'  => null,
			'error' => 'no_page_to_fetch',
		);
	}
	return rr_observe_fetch_frontend_html( $post );
}

/**
 * Extracts the typed JSON-LD nodes that something other than this plugin
 * prints in a page's HTML.
 *
 * @param string $html Page HTML.
 * @return array[]
 */
function rr_local_seo_public_nodes( string $html ): array {
	$nodes = array();
	foreach ( rr_schema_extract_jsonld_blocks( rr_local_seo_strip_own_blocks( $html ) ) as $block ) {
		if ( ! $block['valid'] ) {
			continue;
		}
		$inventory = rr_schema_inventory( $block['data'] );
		foreach ( $inventory['entities'] as $entity ) {
			$nodes[] = $entity['node'];
		}
	}
	return $nodes;
}

/**
 * Scans one page: compares the nodes this plugin would emit with what the
 * public HTML already contains from other sources.
 *
 * @param array $config  Local SEO config.
 * @param int   $post_id Page post ID (0 when none).
 * @param bool  $is_home True for the front page.
 * @return array{post_id: int|null, is_home: bool, status: string, error: string|null, conflicts: array[]}
 *         status: inspected | nothing_to_emit | unavailable.
 */
function rr_local_seo_scan_page( array $config, int $post_id, bool $is_home ): array {
	$page = array(
		'post_id'   => $post_id > 0 ? $post_id : null,
		'is_home'   => $is_home,
		'status'    => 'inspected',
		'error'     => null,
		'conflicts' => array(),
	);

	$plan = rr_local_seo_plan( $config, $post_id, $is_home );
	if ( empty( $plan['nodes'] ) ) {
		$page['status'] = 'nothing_to_emit';
		return $page;
	}

	$fetched = rr_local_seo_fetch_public_html( $post_id );
	if ( null === $fetched['html'] ) {
		$page['status'] = 'unavailable';
		$page['error']  = $fetched['error'];
		return $page;
	}

	$public = rr_local_seo_public_nodes( $fetched['html'] );
	foreach ( $plan['nodes'] as $node ) {
		$reason = rr_local_seo_duplicate_reason( $node, $public );
		if ( null !== $reason ) {
			$page['conflicts'][] = array(
				'@id'    => $node['@id'],
				'reason' => $reason,
			);
		}
	}
	return $page;
}

/**
 * Scans the pages a config would emit on: the front page plus each page
 * assigned to a location (at most RR_LOCAL_SEO_SCAN_MAX_PAGES).
 *
 * @param array $config Local SEO config.
 * @return array{pages: array[], conflicts: array[], unverified: bool}
 */
function rr_local_seo_scan_site( array $config ): array {
	$front = (int) get_option( 'page_on_front', 0 );
	$pages = array( rr_local_seo_scan_page( $config, $front, true ) );

	$seen = array( $front => true );
	foreach ( $config['locations'] as $location ) {
		$post_id = isset( $location['post_id'] ) ? (int) $location['post_id'] : 0;
		if ( $post_id < 1 || isset( $seen[ $post_id ] ) || count( $pages ) >= RR_LOCAL_SEO_SCAN_MAX_PAGES ) {
			continue;
		}
		$seen[ $post_id ] = true;
		$pages[]          = rr_local_seo_scan_page( $config, $post_id, false );
	}

	$conflicts  = array();
	$unverified = false;
	foreach ( $pages as $page ) {
		if ( 'unavailable' === $page['status'] ) {
			$unverified = true;
		}
		foreach ( $page['conflicts'] as $conflict ) {
			$conflicts[] = array_merge( $conflict, array( 'post_id' => $page['post_id'] ) );
		}
	}
	return array(
		'pages'      => $pages,
		'conflicts'  => $conflicts,
		'unverified' => $unverified,
	);
}

// -- Stage 2: facts and audit integration -------------------------------------

/**
 * Derives llms/entity business facts from the Local SEO config.
 *
 * Independent of `enabled`: the object is the structured source of truth for
 * business data whether or not its JSON-LD is emitted.
 *
 * @param array $config Local SEO config.
 * @return array Business facts, or an empty array when no entity is stored.
 */
function rr_local_seo_business_facts( array $config ): array {
	$entity = $config['entity'];
	if ( empty( $entity ) ) {
		return array();
	}
	$first = ! empty( $config['locations'] ) ? $config['locations'][0] : array();

	$facts = array(
		'business_name' => $entity['name'],
		'website'       => isset( $entity['url'] ) ? $entity['url'] : rtrim( home_url( '/' ), '/' ),
		'schema_type'   => ! empty( $first ) && isset( $first['business_type'] ) ? $first['business_type'] : $entity['type'],
		'entity_id'     => rr_local_seo_entity_id( $entity ),
	);

	if ( ! empty( $entity['phone'] ) ) {
		$facts['phone'] = $entity['phone'];
	} elseif ( ! empty( $first['phone'] ) ) {
		$facts['phone'] = $first['phone'];
	}
	if ( ! empty( $first['address'] ) ) {
		$parts = array();
		foreach ( array( 'street', 'locality', 'region', 'postal_code' ) as $key ) {
			if ( ! empty( $first['address'][ $key ] ) ) {
				$parts[] = $first['address'][ $key ];
			}
		}
		$facts['address'] = implode( ', ', $parts );
	}

	$areas = array();
	foreach ( $config['locations'] as $location ) {
		if ( ! empty( $location['area_served'] ) ) {
			$areas = array_merge( $areas, $location['area_served'] );
		}
	}
	if ( ! empty( $areas ) ) {
		$facts['service_area'] = array_values( array_unique( $areas ) );
	}
	return $facts;
}

/**
 * Builds a schema-audit source record for what Local SEO emits on a page.
 *
 * Returns null when emission is off or nothing would be emitted. The audit
 * adds this only when the public page was not inspected, so a node is never
 * counted both here and in the public HTML.
 *
 * @param int  $post_id  Page post ID.
 * @param bool $is_front True for the front page.
 * @return array|null
 */
function rr_local_seo_audit_source( int $post_id, bool $is_front ): ?array {
	$config = rr_local_seo_get_config();
	if ( ! $config['enabled'] ) {
		return null;
	}
	$plan = rr_local_seo_plan( $config, $post_id, $is_front );
	if ( empty( $plan['nodes'] ) ) {
		return null;
	}
	return array_merge(
		array( 'source' => 'local_seo' ),
		rr_schema_inventory( $plan['nodes'] ),
		array( 'invalid_blocks' => 0 )
	);
}

// -- Stage 2: import preview --------------------------------------------------

/**
 * Reduces a JSON-LD image value (string, ImageObject or list) to a URL.
 *
 * @param mixed $value Raw value.
 * @return string|null
 */
function rr_local_seo_import_image( $value ): ?string {
	if ( is_array( $value ) && wp_is_numeric_array( $value ) && ! empty( $value ) ) {
		$value = $value[0];
	}
	if ( is_array( $value ) && isset( $value['url'] ) ) {
		$value = $value['url'];
	}
	return is_string( $value ) && '' !== $value ? $value : null;
}

/**
 * Maps one JSON-LD business node to a proposed location object.
 *
 * @param array $node  JSON-LD node.
 * @param int   $index Position, used for a fallback slug.
 * @return array{proposed: array, notes: string[]}
 */
function rr_local_seo_import_map_location( array $node, int $index ): array {
	$notes    = array();
	$proposed = array();

	$slug = '';
	if ( isset( $node['@id'] ) && is_string( $node['@id'] ) && false !== strpos( $node['@id'], '#' ) ) {
		$slug = sanitize_title( str_replace( 'localbusiness-', '', substr( strrchr( $node['@id'], '#' ), 1 ) ) );
	}
	if ( '' === $slug && isset( $node['name'] ) && is_string( $node['name'] ) ) {
		$slug = sanitize_title( $node['name'] );
	}
	$proposed['id'] = '' !== $slug ? substr( $slug, 0, 40 ) : 'location-' . ( $index + 1 );

	if ( isset( $node['name'] ) ) {
		$proposed['name'] = $node['name'];
	}
	$allowed = rr_local_seo_business_types();
	foreach ( rr_local_seo_node_types( $node ) as $type ) {
		if ( in_array( $type, $allowed, true ) ) {
			$proposed['business_type'] = $type;
			break;
		}
	}
	foreach ( array(
		'url'        => 'url',
		'telephone'  => 'phone',
		'email'      => 'email',
		'priceRange' => 'price_range',
		'hasMap'     => 'map_url',
	) as $from => $to ) {
		if ( isset( $node[ $from ] ) ) {
			$proposed[ $to ] = $node[ $from ];
		}
	}
	$image = isset( $node['image'] ) ? rr_local_seo_import_image( $node['image'] ) : null;
	if ( null !== $image ) {
		$proposed['image'] = $image;
	}

	if ( isset( $node['address'] ) && is_array( $node['address'] ) ) {
		$map     = array(
			'streetAddress'   => 'street',
			'addressLocality' => 'locality',
			'addressRegion'   => 'region',
			'postalCode'      => 'postal_code',
			'addressCountry'  => 'country',
		);
		$address = array();
		foreach ( $map as $from => $to ) {
			if ( isset( $node['address'][ $from ] ) ) {
				$address[ $to ] = $node['address'][ $from ];
			}
		}
		$proposed['address'] = $address;
	}

	if ( isset( $node['geo'] ) && is_array( $node['geo'] ) && isset( $node['geo']['latitude'], $node['geo']['longitude'] ) ) {
		if ( is_numeric( $node['geo']['latitude'] ) && is_numeric( $node['geo']['longitude'] ) ) {
			$proposed['geo'] = array(
				'lat' => (float) $node['geo']['latitude'],
				'lng' => (float) $node['geo']['longitude'],
			);
		} else {
			$notes[] = 'geo dropped: latitude/longitude are not numeric';
		}
	}

	if ( isset( $node['openingHoursSpecification'] ) && is_array( $node['openingHoursSpecification'] ) ) {
		$specs = wp_is_numeric_array( $node['openingHoursSpecification'] ) ? $node['openingHoursSpecification'] : array( $node['openingHoursSpecification'] );
		$rows  = array();
		foreach ( $specs as $spec ) {
			if ( ! is_array( $spec ) || ! isset( $spec['dayOfWeek'], $spec['opens'], $spec['closes'] ) ) {
				$notes[] = 'an openingHoursSpecification row was dropped: missing dayOfWeek, opens or closes';
				continue;
			}
			$days = array();
			foreach ( (array) $spec['dayOfWeek'] as $day ) {
				$days[] = is_string( $day ) ? preg_replace( '#^https?://schema\.org/#', '', $day ) : $day;
			}
			$rows[] = array(
				'days'   => $days,
				'opens'  => is_string( $spec['opens'] ) ? substr( $spec['opens'], 0, 5 ) : $spec['opens'],
				'closes' => is_string( $spec['closes'] ) ? substr( $spec['closes'], 0, 5 ) : $spec['closes'],
			);
		}
		if ( ! empty( $rows ) ) {
			$proposed['opening_hours'] = $rows;
		}
	}

	if ( isset( $node['areaServed'] ) ) {
		$areas = array();
		$raw   = ( is_array( $node['areaServed'] ) && wp_is_numeric_array( $node['areaServed'] ) ) ? $node['areaServed'] : array( $node['areaServed'] );
		foreach ( $raw as $place ) {
			$name = is_array( $place ) && isset( $place['name'] ) ? $place['name'] : $place;
			if ( is_string( $name ) && '' !== trim( $name ) ) {
				$areas[] = trim( $name );
			}
		}
		if ( ! empty( $areas ) ) {
			$proposed['area_served'] = $areas;
		}
	}

	if ( isset( $node['sameAs'] ) ) {
		$urls = array();
		foreach ( (array) $node['sameAs'] as $url ) {
			if ( is_string( $url ) && 0 === strpos( $url, 'https://' ) ) {
				$urls[] = $url;
			} else {
				$notes[] = 'a sameAs entry was dropped: not an https URL';
			}
		}
		if ( ! empty( $urls ) ) {
			$proposed['same_as'] = $urls;
		}
	}

	return array(
		'proposed' => $proposed,
		'notes'    => $notes,
	);
}

/**
 * Maps one JSON-LD Organization or Person node to a proposed entity object.
 *
 * @param array $node JSON-LD node.
 * @return array{proposed: array, notes: string[]}
 */
function rr_local_seo_import_map_entity( array $node ): array {
	$notes    = array();
	$types    = rr_local_seo_node_types( $node );
	$proposed = array( 'type' => in_array( 'Person', $types, true ) ? 'Person' : 'Organization' );

	foreach ( array(
		'name'        => 'name',
		'legalName'   => 'legal_name',
		'jobTitle'    => 'job_title',
		'url'         => 'url',
		'description' => 'description',
		'email'       => 'email',
		'telephone'   => 'phone',
	) as $from => $to ) {
		if ( isset( $node[ $from ] ) ) {
			$proposed[ $to ] = $node[ $from ];
		}
	}
	$logo = null;
	if ( isset( $node['logo'] ) ) {
		$logo = rr_local_seo_import_image( $node['logo'] );
	} elseif ( isset( $node['image'] ) ) {
		$logo = rr_local_seo_import_image( $node['image'] );
	}
	if ( null !== $logo ) {
		$proposed['logo'] = $logo;
	}
	if ( isset( $node['sameAs'] ) ) {
		$urls = array();
		foreach ( (array) $node['sameAs'] as $url ) {
			if ( is_string( $url ) && 0 === strpos( $url, 'https://' ) ) {
				$urls[] = $url;
			} else {
				$notes[] = 'a sameAs entry was dropped: not an https URL';
			}
		}
		if ( ! empty( $urls ) ) {
			$proposed['same_as'] = $urls;
		}
	}
	return array(
		'proposed' => $proposed,
		'notes'    => $notes,
	);
}

/**
 * Finds business, Organization and Person nodes in snippets and proposes
 * Local SEO entity and location objects for them. Read-only: nothing is
 * written and no snippet is changed.
 *
 * Only top-level nodes are considered (root or direct @graph members), so
 * an Organization nested inside a Service as its provider is not proposed.
 *
 * @param array $snippets Managed snippets option value.
 * @return array[] Candidates: snippet_id, snippet_status, kind (entity|location),
 *                 source_id, valid, errors, notes, proposed, normalized.
 */
function rr_local_seo_import_candidates( array $snippets ): array {
	$business = array_merge( RR_AEO_LOCAL_ENTITY_TYPES, rr_local_seo_business_types() );
	$out      = array();
	$index    = 0;

	foreach ( $snippets as $snippet_id => $snippet ) {
		if ( ! is_array( $snippet ) ) {
			continue;
		}
		foreach ( rr_schema_extract_jsonld_blocks( (string) ( isset( $snippet['content'] ) ? $snippet['content'] : '' ) ) as $block ) {
			if ( ! $block['valid'] ) {
				continue;
			}
			$inventory = rr_schema_inventory( $block['data'] );
			foreach ( $inventory['entities'] as $entity ) {
				if ( 1 !== preg_match( '/^\$(\[\d+\])?(\.@graph\[\d+\])?$/', $entity['path'] ) ) {
					continue;
				}
				$node  = $entity['node'];
				$types = rr_local_seo_node_types( $node );
				if ( ! array_intersect( $types, array_merge( $business, array( 'Person' ) ) ) ) {
					continue;
				}

				$is_location = (bool) array_intersect( $types, rr_local_seo_business_types() );
				if ( $is_location ) {
					$mapped = rr_local_seo_import_map_location( $node, $index );
					$result = rr_validate_local_location( $mapped['proposed'] );
				} else {
					$mapped = rr_local_seo_import_map_entity( $node );
					$result = rr_validate_local_entity( $mapped['proposed'] );
				}
				++$index;
				$out[] = array(
					'snippet_id'     => (string) $snippet_id,
					'snippet_status' => (string) ( isset( $snippet['status'] ) ? $snippet['status'] : 'active' ),
					'kind'           => $is_location ? 'location' : 'entity',
					'source_id'      => isset( $node['@id'] ) && is_string( $node['@id'] ) ? $node['@id'] : null,
					'valid'          => empty( $result['errors'] ),
					'errors'         => $result['errors'],
					'notes'          => $mapped['notes'],
					'proposed'       => $mapped['proposed'],
					'normalized'     => empty( $result['errors'] ) ? $result['data'] : null,
				);
			}
		}
	}
	return $out;
}

// -- Action log ---------------------------------------------------------------

/**
 * Builds a compact summary of a config for the action log.
 *
 * @param array $config Local SEO config.
 * @return array
 */
function rr_local_seo_summary( array $config ): array {
	$ids = array();
	foreach ( $config['locations'] as $location ) {
		if ( isset( $location['id'] ) ) {
			$ids[] = $location['id'];
		}
	}
	return array(
		'enabled'      => (bool) $config['enabled'],
		'entity_type'  => isset( $config['entity']['type'] ) ? $config['entity']['type'] : null,
		'entity_name'  => isset( $config['entity']['name'] ) ? $config['entity']['name'] : null,
		'location_ids' => $ids,
	);
}

/**
 * Records a site-level Local SEO write in the rrseo_action_log option.
 *
 * Not reversible: the envelope carries no rollback payload, so the rollback
 * endpoint will refuse it.
 *
 * @param string      $action_type Action label (local_seo_update, local_seo_location_set, local_seo_location_delete).
 * @param string|null $target_id   Location slug, or null for a settings write.
 * @param array       $before      Config before the write.
 * @param array       $after       Config after the write.
 * @param string      $request_id  Request correlation ID.
 * @return void
 */
function rr_local_seo_log( string $action_type, ?string $target_id, array $before, array $after, string $request_id ): void {
	rr_action_log_store(
		array(
			'action_id'        => rr_action_id(),
			'action_type'      => $action_type,
			'target_id'        => $target_id,
			'status'           => 'completed',
			'applied_at'       => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'before'           => rr_local_seo_summary( $before ),
			'after'            => rr_local_seo_summary( $after ),
			'rollback_payload' => null,
			'reversible'       => false,
			'reason'           => 'Site-level Local SEO write; restore by writing the previous values.',
			'warnings'         => array(),
			'request_id'       => $request_id,
		)
	);
}

/**
 * Names of active schema/head-code plugins other than Rank Math.
 *
 * Rank Math has its own dedicated warning.
 *
 * @return string[]
 */
function rr_local_seo_other_emitter_names(): array {
	$names = array();
	foreach ( rr_local_seo_active_emitters() as $emitter ) {
		if ( 'seo-by-rank-math' !== $emitter['slug'] && 'seo-by-rank-math-pro' !== $emitter['slug'] ) {
			$names[] = $emitter['name'];
		}
	}
	return $names;
}

/**
 * Returns advisory warnings for a config.
 *
 * @param array $config Local SEO config.
 * @return string[]
 */
function rr_local_seo_warnings( array $config ): array {
	$warnings = array();
	if ( ! empty( $config['enabled'] ) && class_exists( 'RankMath' ) ) {
		$warnings[] = 'Rank Math is active and may emit its own Organization/LocalBusiness schema; '
			. 'check the public page for duplicates, and disable Rank Math Local SEO or this setting.';
	}
	if ( ! empty( $config['enabled'] ) ) {
		$others = rr_local_seo_other_emitter_names();
		if ( ! empty( $others ) ) {
			$warnings[] = 'Other plugins that can print schema or head code are active (' . implode( ', ', $others )
				. '); run GET /local-seo/preview?inspect_public=1 to check for duplicates.';
		}
	}
	if ( ! empty( $config['enabled'] ) ) {
		$warnings[] = 'If a page cache sits in front of the site, purge it and confirm the public URL unauthenticated before treating the change as live.';
	}
	return $warnings;
}

// -- REST handlers ------------------------------------------------------------

/**
 * Marks a REST response as uncacheable.
 *
 * These are admin-only configuration reads; a page-cache layer (LiteSpeed)
 * serving a stale copy after a write misleads the caller, so the response
 * opts out of caching.
 *
 * @param mixed $data Response data.
 * @return WP_REST_Response|mixed
 */
function rr_local_seo_response( $data ) {
	$response = rest_ensure_response( $data );
	if ( is_object( $response ) && method_exists( $response, 'header' ) ) {
		$response->header( 'Cache-Control', 'no-store, max-age=0' );
		$response->header( 'X-LiteSpeed-Cache-Control', 'no-cache' );
	}
	return $response;
}

/**
 * Returns an object-typed value for JSON output (an empty array would
 * otherwise encode as [] instead of {}).
 *
 * @param array $value Associative array.
 * @return array|stdClass
 */
function rr_local_seo_as_object( array $value ) {
	return empty( $value ) ? new stdClass() : $value;
}

/**
 * Builds the invalid-request error response.
 *
 * @param string[] $errors Validation messages.
 * @return WP_Error
 */
function rr_local_seo_invalid( array $errors ): WP_Error {
	return new WP_Error(
		'validation_failed',
		'Local SEO validation failed',
		array(
			'status' => 422,
			'errors' => $errors,
		)
	);
}

/**
 * Handles GET /local-seo -- returns the stored config.
 *
 * @param WP_REST_Request $request REST request object.
 * @return WP_REST_Response
 */
function rmb_local_seo_get( WP_REST_Request $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- REST callback signature.
	$config = rr_local_seo_get_config();
	return rr_local_seo_response(
		array(
			'enabled'   => $config['enabled'],
			'entity'    => rr_local_seo_as_object( $config['entity'] ),
			'locations' => $config['locations'],
			'warnings'  => rr_local_seo_warnings( $config ),
		)
	);
}

/**
 * Handles POST /local-seo -- validates and writes settings.
 *
 * Only the keys sent (enabled, entity, locations) are replaced.
 *
 * @param WP_REST_Request $request REST request object.
 * @return WP_REST_Response|WP_Error
 */
function rmb_local_seo_set( WP_REST_Request $request ) {
	$dry_run = (bool) $request->get_param( 'dry_run' );
	$input   = array();
	foreach ( array( 'enabled', 'entity', 'locations' ) as $key ) {
		if ( null !== $request->get_param( $key ) ) {
			$input[ $key ] = $request->get_param( $key );
		}
	}
	if ( empty( $input ) ) {
		return rr_local_seo_invalid( array( 'Send at least one of: enabled, entity, locations' ) );
	}

	$current = rr_local_seo_get_config();
	$result  = rr_validate_local_seo( $input, $current );
	if ( ! empty( $result['errors'] ) ) {
		return rr_local_seo_invalid( $result['errors'] );
	}

	// Gate: turning emission on scans the real pages for schema other plugins
	// already print. Conflicts refuse the write unless acknowledged.
	$scan     = null;
	$warnings = rr_local_seo_warnings( $result['config'] );
	if ( ! $current['enabled'] && $result['config']['enabled'] ) {
		$scan = rr_local_seo_scan_site( $result['config'] );
		if ( ! empty( $scan['conflicts'] ) ) {
			if ( ! (bool) $request->get_param( 'acknowledge_duplicates' ) ) {
				return new WP_Error(
					'duplicate_schema_conflict',
					'Other sources already print schema for nodes Local SEO would emit. Remove them, or resend with acknowledge_duplicates=true.',
					array(
						'status'    => 422,
						'conflicts' => $scan['conflicts'],
						'pages'     => $scan['pages'],
					)
				);
			}
			$warnings[] = 'Enabled with acknowledged duplicates: the conflicting nodes are printed twice.';
		}
		if ( $scan['unverified'] ) {
			$warnings[] = 'The public scan could not fetch every page, so duplicates are unverified; '
				. 'run GET /local-seo/preview?inspect_public=1 after enabling.';
		}
	}

	if ( ! $dry_run ) {
		rr_local_seo_save_config( $result['config'] );
		rr_local_seo_log( 'local_seo_update', null, $current, $result['config'], rr_request_id( $request ) );
		rrseo_purge_rest_cache( array( 'local-seo', 'local-seo/preview' ) );
	}

	return rr_local_seo_response(
		array(
			'success'   => true,
			'dry_run'   => $dry_run,
			'enabled'   => $result['config']['enabled'],
			'entity'    => rr_local_seo_as_object( $result['config']['entity'] ),
			'locations' => $result['config']['locations'],
			'scan'      => $scan,
			'warnings'  => $warnings,
		)
	);
}

/**
 * Handles GET /local-seo/preview -- shows what a page would emit.
 *
 * Optional post_id selects a page; omit for the front page. Reports the
 * plan whether or not emission is enabled, with an `enabled` flag, so the
 * output can be reviewed before turning it on.
 *
 * @param WP_REST_Request $request REST request object.
 * @return WP_REST_Response|WP_Error
 */
function rmb_local_seo_preview( WP_REST_Request $request ) {
	$config  = rr_local_seo_get_config();
	$post_id = intval( $request->get_param( 'post_id' ) );
	$is_home = false;

	if ( $post_id > 0 ) {
		if ( ! get_post( $post_id ) ) {
			return new WP_Error( 'invalid_post', 'Post not found', array( 'status' => 404 ) );
		}
		$is_home = (int) get_option( 'page_on_front' ) === $post_id;
	} else {
		$is_home = true;
		$post_id = (int) get_option( 'page_on_front' );
	}

	$plan   = rr_local_seo_plan( $config, $post_id, $is_home );
	$public = null;
	if ( (bool) $request->get_param( 'inspect_public' ) ) {
		$public = rr_local_seo_scan_page( $config, $post_id, $is_home );
	}
	return rr_local_seo_response(
		array(
			'enabled'  => $config['enabled'],
			'post_id'  => $post_id > 0 ? $post_id : null,
			'is_home'  => $is_home,
			'nodes'    => $plan['nodes'],
			'skipped'  => $plan['skipped'],
			'public'   => $public,
			'warnings' => rr_local_seo_warnings( $config ),
		)
	);
}

/**
 * Handles GET /local-seo/locations/{id}.
 *
 * @param WP_REST_Request $request REST request object.
 * @return WP_REST_Response|WP_Error
 */
function rmb_local_seo_location_get( WP_REST_Request $request ) {
	$id = (string) $request->get_param( 'id' );
	foreach ( rr_local_seo_get_config()['locations'] as $location ) {
		if ( isset( $location['id'] ) && $location['id'] === $id ) {
			return rr_local_seo_response( $location );
		}
	}
	return new WP_Error( 'not_found', 'Location not found', array( 'status' => 404 ) );
}

/**
 * Handles POST /local-seo/locations/{id} -- creates or replaces one location.
 *
 * @param WP_REST_Request $request REST request object.
 * @return WP_REST_Response|WP_Error
 */
function rmb_local_seo_location_set( WP_REST_Request $request ) {
	$id      = (string) $request->get_param( 'id' );
	$dry_run = (bool) $request->get_param( 'dry_run' );
	$fields  = array();
	foreach ( RR_LOCAL_SEO_LOCATION_FIELDS as $key ) {
		if ( null !== $request->get_param( $key ) ) {
			$fields[ $key ] = $request->get_param( $key );
		}
	}

	$current = rr_local_seo_get_config();
	$result  = rr_local_seo_apply_location( $current, $id, $fields );
	if ( ! empty( $result['errors'] ) ) {
		return rr_local_seo_invalid( $result['errors'] );
	}

	if ( ! $dry_run ) {
		rr_local_seo_save_config( $result['config'] );
		rr_local_seo_log( 'local_seo_location_set', $id, $current, $result['config'], rr_request_id( $request ) );
		rrseo_purge_rest_cache( array( 'local-seo', 'local-seo/preview', 'local-seo/locations/' . $id ) );
	}

	$saved = array();
	foreach ( $result['config']['locations'] as $location ) {
		if ( $location['id'] === $id ) {
			$saved = $location;
		}
	}
	return rest_ensure_response(
		array(
			'success'  => true,
			'dry_run'  => $dry_run,
			'created'  => ! $result['found'],
			'location' => $saved,
			'warnings' => rr_local_seo_warnings( $result['config'] ),
		)
	);
}

/**
 * Handles DELETE /local-seo/locations/{id}.
 *
 * @param WP_REST_Request $request REST request object.
 * @return WP_REST_Response|WP_Error
 */
function rmb_local_seo_location_delete( WP_REST_Request $request ) {
	$id      = (string) $request->get_param( 'id' );
	$current = rr_local_seo_get_config();
	$result  = rr_local_seo_apply_location( $current, $id, null );
	if ( ! $result['found'] ) {
		return new WP_Error( 'not_found', 'Location not found', array( 'status' => 404 ) );
	}

	rr_local_seo_save_config( $result['config'] );
	rr_local_seo_log( 'local_seo_location_delete', $id, $current, $result['config'], rr_request_id( $request ) );
	rrseo_purge_rest_cache( array( 'local-seo', 'local-seo/preview', 'local-seo/locations/' . $id ) );

	return rest_ensure_response(
		array(
			'success' => true,
			'deleted' => $id,
		)
	);
}

/**
 * Handles GET /local-seo/import-preview -- read-only proposals mapped from
 * the business, Organization and Person JSON-LD found in managed snippets.
 *
 * @param WP_REST_Request $request REST request object.
 * @return WP_REST_Response
 */
function rmb_local_seo_import_preview( WP_REST_Request $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- REST callback signature.
	$snippets   = get_option( RMB_SNIPPETS_KEY, array() );
	$candidates = rr_local_seo_import_candidates( is_array( $snippets ) ? $snippets : array() );
	$entities   = 0;
	$locations  = 0;
	$invalid    = 0;
	foreach ( $candidates as $candidate ) {
		if ( 'entity' === $candidate['kind'] ) {
			++$entities;
		} else {
			++$locations;
		}
		if ( ! $candidate['valid'] ) {
			++$invalid;
		}
	}
	return rr_local_seo_response(
		array(
			'read_only'  => true,
			'summary'    => array(
				'candidates' => count( $candidates ),
				'entities'   => $entities,
				'locations'  => $locations,
				'invalid'    => $invalid,
			),
			'candidates' => $candidates,
			'note'       => 'Nothing was written and no snippet was changed. Review each proposed object, '
				. 'then write it with POST /local-seo or POST /local-seo/locations/{id}.',
		)
	);
}
