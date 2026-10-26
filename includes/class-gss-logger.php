<?php
/**
 * Protokoll der Synchronisationsläufe.
 *
 * @package GrowinStockSync
 */

defined( 'ABSPATH' ) || exit;

/**
 * Schreibt und liest das Lauf-Protokoll.
 */
class GSS_Logger {

	const OPTION = 'gss_log';

	/**
	 * Legt einen Protokolleintrag an.
	 *
	 * @param array $entry Daten des Laufs.
	 * @return void
	 */
	public static function add( array $entry ) {
		$entry = wp_parse_args(
			$entry,
			array(
				'time'     => current_time( 'mysql' ),
				'trigger'  => 'cron',
				'source'   => '',
				'rows'     => 0,
				'matched'  => 0,
				'updated'  => 0,
				'skipped'  => 0,
				'unknown'  => 0,
				'dry_run'  => false,
				'duration' => 0,
				'errors'   => array(),
				'notes'    => array(),
			)
		);

		$entry['errors'] = array_slice( array_map( 'strval', (array) $entry['errors'] ), 0, 25 );
		$entry['notes']  = array_slice( array_map( 'strval', (array) $entry['notes'] ), 0, 25 );

		$log = self::all();
		array_unshift( $log, $entry );

		$limit = (int) GSS_Settings::get( 'log_entries', 30 );
		$log   = array_slice( $log, 0, $limit );

		update_option( self::OPTION, $log, false );

		if ( ! empty( $entry['errors'] ) ) {
			self::maybe_notify( $entry );
		}
	}

	/**
	 * Alle Protokolleinträge.
	 *
	 * @return array
	 */
	public static function all() {
		$log = get_option( self::OPTION, array() );

		return is_array( $log ) ? $log : array();
	}

	/**
	 * Letzter Eintrag.
	 *
	 * @return array|null
	 */
	public static function last() {
		$log = self::all();

		return $log ? $log[0] : null;
	}

	/**
	 * Leert das Protokoll.
	 *
	 * @return void
	 */
	public static function clear() {
		delete_option( self::OPTION );
	}

	/**
	 * Verschickt bei Fehlern eine Nachricht, höchstens einmal pro Tag.
	 *
	 * @param array $entry Protokolleintrag.
	 * @return void
	 */
	protected static function maybe_notify( array $entry ) {
		$email = (string) GSS_Settings::get( 'notify_email', '' );

		if ( '' === $email || ! is_email( $email ) ) {
			return;
		}

		if ( get_transient( 'gss_notified' ) ) {
			return;
		}

		set_transient( 'gss_notified', 1, DAY_IN_SECONDS );

		$body  = __( 'Beim Abgleich der Growin-Bestandsliste sind Fehler aufgetreten:', 'growin-stock-sync' ) . "\n\n";
		$body .= implode( "\n", $entry['errors'] ) . "\n\n";
		$body .= sprintf(
			/* translators: %s: admin URL */
			__( 'Details im Protokoll: %s', 'growin-stock-sync' ),
			admin_url( 'admin.php?page=' . GSS_SLUG . '&tab=log' )
		);

		wp_mail(
			$email,
			sprintf( '[%s] Growin Stock Sync', wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ),
			$body
		);
	}
}
