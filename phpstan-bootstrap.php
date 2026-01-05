<?php
/**
 * PHPStan Bootstrap File
 *
 * Defines constants that are normally defined in the main plugin file
 * to avoid PHPStan errors when analyzing individual files.
 *
 * @package KnabbelWP
 * @since   0.0.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Plugin constants
define('KNABBEL_PLUGIN_DIR', __DIR__ . '/');
define('KNABBEL_PLUGIN_URL', 'https://example.com/wp-content/plugins/zw-knabbel-wp/');

// Action Scheduler function stubs for static analysis
if (!function_exists('as_schedule_single_action')) {
    /**
     * Schedule a single action to run at a specific time.
     *
     * @param int    $timestamp When to run the action.
     * @param string $hook      The hook to trigger.
     * @param array  $args      Arguments to pass to the hook.
     * @param string $group     The group to assign this job to.
     * @return int|false The action ID or false on failure.
     */
    function as_schedule_single_action(int $timestamp, string $hook, array $args = array(), string $group = ''): int|false
    {
        return 0;
    }
}

if (!function_exists('as_has_scheduled_action')) {
    /**
     * Check if there is a scheduled action for the given hook.
     *
     * @param string $hook  The hook to check.
     * @param array  $args  Arguments to check against.
     * @param string $group The group to check.
     * @return bool|int False if no action found, action ID if found.
     */
    function as_has_scheduled_action(string $hook, array $args = array(), string $group = ''): bool|int
    {
        return false;
    }
}

if (!function_exists('as_unschedule_all_actions')) {
    /**
     * Unschedule all actions matching the given hook and arguments.
     *
     * @param string $hook  The hook to unschedule.
     * @param array  $args  Arguments to match.
     * @param string $group The group to match.
     * @return void
     */
    function as_unschedule_all_actions(string $hook, array $args = array(), string $group = ''): void
    {
    }
}

if (!function_exists('as_next_scheduled_action')) {
    /**
     * Get the next scheduled action for the given hook.
     *
     * @param string $hook  The hook to check.
     * @param array  $args  Arguments to check against.
     * @param string $group The group to check.
     * @return int|false The timestamp of the next scheduled action, or false if none found.
     */
    function as_next_scheduled_action(string $hook, array $args = array(), string $group = ''): int|false
    {
        return false;
    }
}

