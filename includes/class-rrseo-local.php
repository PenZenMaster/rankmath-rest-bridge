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
 * Stage 2 (not in this file): business_facts / entity-audit integration and
 * the read-only import-from-snippets preview.
 *
 * Author(s):
 * Rank Rocket Co (C) Copyright 2026 - All Rights Reserved
 *
 * Created Date: 2026-10-07
 * Last Modified Date: 2026-10-07
 *
 * Comments:
 * v1.00 - Initial release (Stage 1): settings, CRUD, preview, emission.
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
 * Tests whether a snippet display_on target applies to a page.
 *
 * Mirrors the targeting values documented on rmb_output_snippets(), but is
 * evaluated against an explicit page description so it works for previews
 * as well as live requests.
 *
 * @param string $display_on Snippet display_on value.
 * @param int    $post_id    Queried post ID (0 when none).
 * @param bool   $is_home    True for the front page.
 * @param string $post_type  Post type of the queried post.
 * @return bool
 */
function rr_local_seo_snippet_applies( string $display_on, int $post_id, bool $is_home, string $post_type ): bool {
	$display_on = trim( $display_on );
	if ( in_array( $display_on, array( 'sitewide', 'all', 'entire_website' ), true ) ) {
		return true;
	}
	if ( in_array( $display_on, array( 'home', 'homepage', 'front_page' ), true ) ) {
		return $is_home;
	}
	if ( 'singular' === $display_on ) {
		return $post_id > 0;
	}
	if ( 'all_pages' === $display_on ) {
		return 'page' === $post_type;
	}
	if ( 'all_posts' === $display_on ) {
		return 'post' === $post_type;
	}
	if ( 1 === preg_match( '/^(?:page_id:)?(\d+)$/', $display_on, $m ) ) {
		return $post_id > 0 && (int) $m[1] === $post_id;
	}
	if ( 0 === strpos( $display_on, 'post_type:' ) ) {
		return '' !== $post_type && substr( $display_on, 10 ) === $post_type;
	}
	return false;
}

/**
 * Collects the JSON-LD nodes of active snippets that apply to a page.
 *
 * @param int    $post_id   Queried post ID (0 when none).
 * @param bool   $is_home   True for the front page.
 * @param string $post_type Post type of the queried post.
 * @return array[]
 */
function rr_local_seo_snippet_nodes( int $post_id, bool $is_home, string $post_type ): array {
	$snippets = get_option( RMB_SNIPPETS_KEY, array() );
	if ( ! is_array( $snippets ) ) {
		return array();
	}
	$nodes = array();
	foreach ( $snippets as $snippet ) {
		if ( ! is_array( $snippet ) || 'active' !== ( isset( $snippet['status'] ) ? $snippet['status'] : 'active' ) ) {
			continue;
		}
		$display_on = (string) ( isset( $snippet['display_on'] ) ? $snippet['display_on'] : 'sitewide' );
		if ( ! rr_local_seo_snippet_applies( $display_on, $post_id, $is_home, $post_type ) ) {
			continue;
		}
		$content = (string) ( isset( $snippet['content'] ) ? $snippet['content'] : '' );
		if ( 0 === preg_match_all( '#<script[^>]*application/ld\+json[^>]*>(.*?)</script>#is', $content, $matches ) ) {
			continue;
		}
		foreach ( $matches[1] as $json ) {
			$decoded = json_decode( trim( $json ), true );
			if ( is_array( $decoded ) ) {
				$nodes = array_merge( $nodes, rr_schema_graph_nodes( $decoded ) );
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
		$warnings[] = 'If a page cache sits in front of the site, purge it and confirm the public URL unauthenticated before treating the change as live.';
	}
	return $warnings;
}

// -- REST handlers ------------------------------------------------------------

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
	return rest_ensure_response(
		array(
			'enabled'   => $config['enabled'],
			'entity'    => $config['entity'],
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

	if ( ! $dry_run ) {
		rr_local_seo_save_config( $result['config'] );
		rr_local_seo_log( 'local_seo_update', null, $current, $result['config'], rr_request_id( $request ) );
		rrseo_purge_rest_cache( array( 'local-seo' ) );
	}

	return rest_ensure_response(
		array(
			'success'   => true,
			'dry_run'   => $dry_run,
			'enabled'   => $result['config']['enabled'],
			'entity'    => $result['config']['entity'],
			'locations' => $result['config']['locations'],
			'warnings'  => rr_local_seo_warnings( $result['config'] ),
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

	$plan = rr_local_seo_plan( $config, $post_id, $is_home );
	return rest_ensure_response(
		array(
			'enabled'  => $config['enabled'],
			'post_id'  => $post_id > 0 ? $post_id : null,
			'is_home'  => $is_home,
			'nodes'    => $plan['nodes'],
			'skipped'  => $plan['skipped'],
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
			return rest_ensure_response( $location );
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
		rrseo_purge_rest_cache( array( 'local-seo' ) );
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
	rrseo_purge_rest_cache( array( 'local-seo' ) );

	return rest_ensure_response(
		array(
			'success' => true,
			'deleted' => $id,
		)
	);
}
