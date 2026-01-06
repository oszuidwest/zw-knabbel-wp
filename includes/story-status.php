<?php
/**
 * Story status enum definition
 *
 * Defines the possible processing states for stories sent to the Babbel API.
 *
 * @package KnabbelWP
 * @since   0.0.1
 */

declare(strict_types=1);

namespace KnabbelWP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Represents the processing status for a story.
 *
 * @since 0.1.0
 */
enum StoryStatus: string {

	case Scheduled  = 'scheduled';
	case Processing = 'processing';
	case Sent       = 'sent';
	case Error      = 'error';
	case Deleted    = 'deleted';
}
