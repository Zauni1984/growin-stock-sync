<?php
/**
 * Einstellungen des Plugins.
 *
 * @package GrowinStockSync
 */

defined( 'ABSPATH' ) || exit;

/**
 * Kapselt Lesen und Schreiben der Plugin-Optionen.
 */
class GSS_Settings {

	const OPTION = 'gss_settings';

	/**
	 * Bestand-Codes der Growin-Liste.
	 *
	 * @return array<int,string>
	 */
	public static function stock_codes() {
		return array(
			1 => 'lieferbar',
			2 => 'geringer Bestand',
			3 => 'im Zulauf',
			4 => 'nicht auf Lager',
			5 => 'Bestellware',
		);
	}

	/**
	 * Standardwerte.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			// Bezugsquelle.
			'source_mode'        => 'manual',
			'list_url'           => 'https://www.growin.de/lists/pricelist/?lang=de',
			'login_url'          => '',
			'login_post_url'     => '',
			'login_user'         => '',
			'login_pass'         => '',
			'field_user'         => '',
			'field_pass'         => '',
			'extra_fields'       => '',

			// Zeitsteuerung.
			'cron_interval'      => 'gss_six_hours',

			// Zuordnung.
			'sku_prefix'         => '',

			// Vorrang gegenüber anderen Lieferanten-Syncs.
			'precedence'         => 'bloomtech',

			// Bestandslogik.
			'status_map'         => array(
				1 => 'instock',
				2 => 'instock',
				3 => 'onbackorder',
				4 => 'outofstock',
				5 => 'onbackorder',
			),
			'set_quantities'     => 'yes',
			'quantity_map'       => array(
				1 => 25,
				2 => 5,
				3 => 0,
				4 => 0,
				5 => 0,
			),
			'backorder_map'      => array(
				1 => 'no',
				2 => 'no',
				3 => 'notify',
				4 => 'no',
				5 => 'notify',
			),
			'discontinued'       => 'report',

			// Verkaufspreise.
			'sync_price'         => 'off',
			'price_source'       => 'retail',
			'price_is_gross'     => 'yes',
			'price_markup'       => 0,
			'price_change_limit' => 60,
			'price_on_sale'      => 'skip',
			'price_tax_fallback' => 19,

			// Zusatzdaten.
			'store_dealer_price' => 'yes',
			'store_weight'       => 'no',

			// Betrieb.
			'dry_run'            => 'no',
			'time_budget'        => 45,
			'log_entries'        => 30,
			'notify_email'       => '',
		);
	}

	/**
	 * Alle Einstellungen.
	 *
	 * @return array
	 */
	public static function all() {
		$stored = get_option( self::OPTION, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$settings = wp_parse_args( $stored, self::defaults() );

		foreach ( array( 'status_map', 'quantity_map', 'backorder_map' ) as $map ) {
			$defaults           = self::defaults();
			$settings[ $map ]   = is_array( $settings[ $map ] ) ? $settings[ $map ] : array();
			$settings[ $map ]   = array_replace( $defaults[ $map ], $settings[ $map ] );
		}

		return $settings;
	}

	/**
	 * Einzelne Einstellung.
	 *
	 * @param string $key     Schlüssel.
	 * @param mixed  $default Rückfallwert.
	 * @return mixed
	 */
	public static function get( $key, $default = null ) {
		$all = self::all();

		return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
	}

	/**
	 * Speichert Einstellungen (nur bekannte Schlüssel).
	 *
	 * @param array $input Rohdaten.
	 * @return array Gespeicherte Werte.
	 */
	public static function save( array $input ) {
		$current  = self::all();
		$defaults = self::defaults();
		$clean    = $current;

		$text_keys = array( 'list_url', 'login_url', 'login_post_url', 'login_user', 'field_user', 'field_pass', 'sku_prefix', 'notify_email' );
		foreach ( $text_keys as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$value = sanitize_text_field( wp_unslash( $input[ $key ] ) );

				if ( in_array( $key, array( 'list_url', 'login_url', 'login_post_url' ), true ) && '' !== $value ) {
					$value = esc_url_raw( $value );
				}

				if ( 'notify_email' === $key && '' !== $value && ! is_email( $value ) ) {
					$value = '';
				}

				$clean[ $key ] = $value;
			}
		}

		/*
		 * Das Passwort wird bewusst nicht durch sanitize_text_field geschickt und auch
		 * nicht erneut entschlüsselt: Der Adminbereich hat wp_unslash bereits angewandt,
		 * ein zweiter Durchlauf würde Backslashes im Passwort verschlucken.
		 */
		if ( isset( $input['login_pass'] ) && ! self::password_is_fixed() ) {
			$pass = (string) $input['login_pass'];

			// Ein leeres Feld lässt das gespeicherte Passwort unverändert.
			if ( '' !== $pass ) {
				$clean['login_pass'] = $pass;
				update_option( 'gss_password_set_at', current_time( 'mysql' ), false );
			}
		}

		// Steht das Passwort in der wp-config.php, gehört es nicht in die Datenbank.
		if ( self::password_is_fixed() ) {
			$clean['login_pass'] = '';
		}

		if ( isset( $input['extra_fields'] ) ) {
			$clean['extra_fields'] = sanitize_textarea_field( wp_unslash( $input['extra_fields'] ) );
		}

		if ( isset( $input['source_mode'] ) ) {
			$clean['source_mode'] = in_array( $input['source_mode'], array( 'auto', 'manual' ), true ) ? $input['source_mode'] : 'manual';
		}

		if ( isset( $input['cron_interval'] ) ) {
			$allowed                = array_keys( self::cron_choices() );
			$clean['cron_interval'] = in_array( $input['cron_interval'], $allowed, true ) ? $input['cron_interval'] : $defaults['cron_interval'];
		}

		if ( isset( $input['precedence'] ) ) {
			$clean['precedence'] = in_array( $input['precedence'], array( 'bloomtech', 'growin' ), true ) ? $input['precedence'] : 'bloomtech';
		}

		if ( isset( $input['discontinued'] ) ) {
			$clean['discontinued'] = in_array( $input['discontinued'], array( 'ignore', 'report', 'outofstock' ), true ) ? $input['discontinued'] : 'report';
		}

		/*
		 * Nicht angehakte Kästchen tauchen im Formular gar nicht auf. Deshalb werden sie
		 * nur dann auf "no" gesetzt, wenn die Absendung wirklich aus dem Formular kommt.
		 * Ein Teil-Speichern lässt die bisherigen Werte damit unangetastet.
		 */
		$from_form = isset( $input['form'] ) && 'settings' === $input['form'];

		foreach ( array( 'set_quantities', 'store_dealer_price', 'store_weight', 'dry_run' ) as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$clean[ $key ] = ( 'yes' === $input[ $key ] ) ? 'yes' : 'no';
			} elseif ( $from_form ) {
				$clean[ $key ] = 'no';
			}
		}

		if ( isset( $input['sync_price'] ) ) {
			$clean['sync_price'] = in_array( $input['sync_price'], array( 'off', 'on' ), true ) ? $input['sync_price'] : 'off';
		}

		if ( isset( $input['price_source'] ) ) {
			$clean['price_source'] = in_array( $input['price_source'], array( 'retail', 'dealer' ), true ) ? $input['price_source'] : 'retail';
		}

		if ( isset( $input['price_is_gross'] ) ) {
			$clean['price_is_gross'] = ( 'yes' === $input['price_is_gross'] ) ? 'yes' : 'no';
		}

		if ( isset( $input['price_on_sale'] ) ) {
			$clean['price_on_sale'] = in_array( $input['price_on_sale'], array( 'skip', 'update' ), true ) ? $input['price_on_sale'] : 'skip';
		}

		if ( isset( $input['price_markup'] ) ) {
			$markup                 = (float) str_replace( ',', '.', (string) $input['price_markup'] );
			$clean['price_markup']  = max( -90, min( 500, $markup ) );
		}

		if ( isset( $input['price_change_limit'] ) ) {
			$clean['price_change_limit'] = max( 0, min( 100, absint( $input['price_change_limit'] ) ) );
		}

		if ( isset( $input['price_tax_fallback'] ) ) {
			$rate                        = (float) str_replace( ',', '.', (string) $input['price_tax_fallback'] );
			$clean['price_tax_fallback'] = max( 0, min( 50, $rate ) );
		}

		if ( isset( $input['time_budget'] ) ) {
			$clean['time_budget'] = max( 10, min( 300, absint( $input['time_budget'] ) ) );
		}

		if ( isset( $input['log_entries'] ) ) {
			$clean['log_entries'] = max( 5, min( 200, absint( $input['log_entries'] ) ) );
		}

		$valid_status = array( 'instock', 'outofstock', 'onbackorder' );
		foreach ( array_keys( self::stock_codes() ) as $code ) {
			if ( isset( $input['status_map'][ $code ] ) && in_array( $input['status_map'][ $code ], $valid_status, true ) ) {
				$clean['status_map'][ $code ] = $input['status_map'][ $code ];
			}

			if ( isset( $input['quantity_map'][ $code ] ) ) {
				$clean['quantity_map'][ $code ] = max( 0, (int) $input['quantity_map'][ $code ] );
			}

			if ( isset( $input['backorder_map'][ $code ] ) && in_array( $input['backorder_map'][ $code ], array( 'no', 'notify', 'yes' ), true ) ) {
				$clean['backorder_map'][ $code ] = $input['backorder_map'][ $code ];
			}
		}

		update_option( self::OPTION, $clean, false );

		return $clean;
	}

	/**
	 * Ist das Passwort fest in der wp-config.php hinterlegt?
	 *
	 * Dann landet es nie in der Datenbank.
	 *
	 * @return bool
	 */
	public static function password_is_fixed() {
		return defined( 'GROWIN_SYNC_PASSWORD' ) && '' !== (string) constant( 'GROWIN_SYNC_PASSWORD' );
	}

	/**
	 * Das für den Abruf zu verwendende Passwort.
	 *
	 * @return string
	 */
	public static function get_password() {
		if ( self::password_is_fixed() ) {
			return (string) constant( 'GROWIN_SYNC_PASSWORD' );
		}

		return (string) self::get( 'login_pass', '' );
	}

	/**
	 * Der für den Abruf zu verwendende Benutzername.
	 *
	 * @return string
	 */
	public static function get_username() {
		if ( defined( 'GROWIN_SYNC_USER' ) && '' !== (string) constant( 'GROWIN_SYNC_USER' ) ) {
			return (string) constant( 'GROWIN_SYNC_USER' );
		}

		return (string) self::get( 'login_user', '' );
	}

	/**
	 * Ist überhaupt ein Passwort verfügbar?
	 *
	 * @return bool
	 */
	public static function has_password() {
		return '' !== self::get_password();
	}

	/**
	 * Löscht das gespeicherte Passwort.
	 *
	 * @return void
	 */
	public static function forget_password() {
		$all               = self::all();
		$all['login_pass'] = '';

		update_option( self::OPTION, $all, false );
		delete_option( 'gss_password_set_at' );
	}

	/**
	 * Auswahl der Cron-Intervalle.
	 *
	 * @return array<string,string>
	 */
	public static function cron_choices() {
		return array(
			'gss_two_hours'   => __( 'alle 2 Stunden', 'growin-stock-sync' ),
			'gss_four_hours'  => __( 'alle 4 Stunden', 'growin-stock-sync' ),
			'gss_six_hours'   => __( 'alle 6 Stunden', 'growin-stock-sync' ),
			'twicedaily'      => __( 'zweimal täglich', 'growin-stock-sync' ),
			'daily'           => __( 'einmal täglich', 'growin-stock-sync' ),
			'gss_manual_only' => __( 'kein automatischer Lauf', 'growin-stock-sync' ),
		);
	}

	/**
	 * Zusätzliche POST-Felder für den Login als Array.
	 *
	 * @return array<string,string>
	 */
	public static function extra_login_fields() {
		$raw    = (string) self::get( 'extra_fields', '' );
		$fields = array();

		foreach ( preg_split( '/\r\n|\r|\n/', $raw ) as $line ) {
			$line = trim( $line );

			if ( '' === $line || false === strpos( $line, '=' ) ) {
				continue;
			}

			list( $key, $value ) = explode( '=', $line, 2 );
			$key                 = trim( $key );

			if ( '' !== $key ) {
				$fields[ $key ] = trim( $value );
			}
		}

		return $fields;
	}
}
