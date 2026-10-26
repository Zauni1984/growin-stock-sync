<?php
/**
 * Bindet alle Bestandteile ein.
 *
 * @package GrowinStockSync
 */

defined( 'ABSPATH' ) || exit;

/**
 * Zentrale Steuerung: Cron, Hooks, Aktivierung.
 */
class GSS_Plugin {

	const CRON_HOOK = 'gss_sync_event';

	/**
	 * Adminbereich.
	 *
	 * @var GSS_Admin|null
	 */
	protected $admin = null;

	/**
	 * Konstruktor.
	 */
	public function __construct() {
		add_filter( 'cron_schedules', array( $this, 'cron_schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval
		add_action( self::CRON_HOOK, array( $this, 'run_scheduled' ) );
		add_action( 'update_option_' . GSS_Settings::OPTION, array( $this, 'reschedule' ), 10, 0 );

		if ( is_admin() ) {
			$this->admin = new GSS_Admin();
		}
	}

	/**
	 * Eigene Cron-Intervalle.
	 *
	 * @param array $schedules Bestehende Intervalle.
	 * @return array
	 */
	public function cron_schedules( $schedules ) {
		$schedules['gss_two_hours'] = array(
			'interval' => 2 * HOUR_IN_SECONDS,
			'display'  => __( 'Alle 2 Stunden (Growin)', 'growin-stock-sync' ),
		);

		$schedules['gss_four_hours'] = array(
			'interval' => 4 * HOUR_IN_SECONDS,
			'display'  => __( 'Alle 4 Stunden (Growin)', 'growin-stock-sync' ),
		);

		$schedules['gss_six_hours'] = array(
			'interval' => 6 * HOUR_IN_SECONDS,
			'display'  => __( 'Alle 6 Stunden (Growin)', 'growin-stock-sync' ),
		);

		return $schedules;
	}

	/**
	 * Geplanter Lauf.
	 *
	 * @return void
	 */
	public function run_scheduled() {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return;
		}

		GSS_Sync::run( 'cron' );
	}

	/**
	 * Plant den Cron nach einer Änderung der Einstellungen neu.
	 *
	 * @return void
	 */
	public function reschedule() {
		self::schedule();
	}

	/**
	 * Legt den Cron gemäß Einstellung an.
	 *
	 * @return void
	 */
	public static function schedule() {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );

		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}

		$interval = (string) GSS_Settings::get( 'cron_interval', 'gss_six_hours' );

		if ( 'gss_manual_only' === $interval ) {
			return;
		}

		wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, $interval, self::CRON_HOOK );
	}

	/**
	 * Aktivierung.
	 *
	 * @return void
	 */
	public static function activate() {
		GSS_Catalog::install();
		GSS_Source::prepare_dir();

		if ( false === get_option( GSS_Settings::OPTION, false ) ) {
			update_option( GSS_Settings::OPTION, GSS_Settings::defaults(), false );
		}

		self::schedule();
	}

	/**
	 * Deaktivierung.
	 *
	 * @return void
	 */
	public static function deactivate() {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );

		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}

		wp_clear_scheduled_hook( self::CRON_HOOK );
	}
}
