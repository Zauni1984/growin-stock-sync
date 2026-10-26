<?php
/**
 * Aufräumen bei der Deinstallation.
 *
 * Entfernt Einstellungen, Protokoll, geplante Aufgaben und die zwischengespeicherte
 * Preisliste. Die Katalogtabelle bleibt absichtlich erhalten, damit die gesammelten
 * Artikeldaten nicht verloren gehen. Sie kann bei Bedarf von Hand gelöscht werden:
 * DROP TABLE {prefix}growin_catalog;
 *
 * @package GrowinStockSync
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'gss_settings' );
delete_option( 'gss_log' );
delete_option( 'gss_source_meta' );
delete_option( 'gss_last_run' );

delete_transient( 'gss_notice' );
delete_transient( 'gss_test_result' );
delete_transient( 'gss_notified' );

wp_clear_scheduled_hook( 'gss_sync_event' );

$uploads = wp_upload_dir();
$file    = trailingslashit( $uploads['basedir'] ) . 'growin-stock-sync/growin-pricelist.csv';

if ( file_exists( $file ) ) {
	wp_delete_file( $file );
}
