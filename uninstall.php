<?php
/**
 * Uninstall Script — runs when the plugin is deleted via WP admin.
 *
 * Removes all plugin data: database tables, post meta, options, transients,
 * and scheduled actions.
 *
 * @package Schema_AI
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// 1. Drop the log table.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}schema_ai_log" );

// 2. Delete all _schema_ai_* post meta.
$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '_schema_ai_%'" );

// 3. Delete all schema_ai_* options.
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'schema_ai_%'" );

// 4. Clear all schema_ai_* transients (value + timeout entries).
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_schema_ai_%'" );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_schema_ai_%'" );

// 5. Unschedule Action Scheduler actions (if available).
if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( 'schema_ai_bulk_process' );
}
