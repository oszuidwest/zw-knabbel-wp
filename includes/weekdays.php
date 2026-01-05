<?php
/**
 * Weekday bitmask utilities for Babbel API integration.
 *
 * @package KnabbelWP
 */

declare(strict_types=1);

namespace KnabbelWP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Weekday bitmask values per Babbel API specification.
 * Each day is a power of 2, starting with Sunday.
 */
const WEEKDAY_SUNDAY    = 1;
const WEEKDAY_MONDAY    = 2;
const WEEKDAY_TUESDAY   = 4;
const WEEKDAY_WEDNESDAY = 8;
const WEEKDAY_THURSDAY  = 16;
const WEEKDAY_FRIDAY    = 32;
const WEEKDAY_SATURDAY  = 64;
const WEEKDAY_ALL       = 127;

/**
 * Mapping of day names to bitmask values.
 * Order matches the bit positions (Sunday = bit 0).
 */
const WEEKDAY_MAP = array(
	'sunday'    => WEEKDAY_SUNDAY,
	'monday'    => WEEKDAY_MONDAY,
	'tuesday'   => WEEKDAY_TUESDAY,
	'wednesday' => WEEKDAY_WEDNESDAY,
	'thursday'  => WEEKDAY_THURSDAY,
	'friday'    => WEEKDAY_FRIDAY,
	'saturday'  => WEEKDAY_SATURDAY,
);

/**
 * Convert plugin settings to weekdays bitmask.
 *
 * @since 0.1.0
 * @param array<string, mixed> $options Plugin settings array.
 * @return int Bitmask integer (0-127).
 */
function settings_to_weekdays_bitmask( array $options ): int {
	$bitmask = 0;

	foreach ( WEEKDAY_MAP as $day => $value ) {
		$field_key = "weekday_{$day}";
		// Default to enabled if not explicitly set.
		$is_enabled = ! isset( $options[ $field_key ] ) || ! empty( $options[ $field_key ] );
		if ( $is_enabled ) {
			$bitmask |= $value;
		}
	}

	return $bitmask;
}
