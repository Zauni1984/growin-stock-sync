<?php
/**
 * Beschafft die Preisliste: automatischer Abruf mit Login oder manueller Upload.
 *
 * @package GrowinStockSync
 */

defined( 'ABSPATH' ) || exit;

/**
 * Holt die CSV-Datei aus dem Growin-Kundenbereich oder aus dem Upload-Verzeichnis.
 */
class GSS_Source {

	const UPLOAD_DIRNAME = 'growin-stock-sync';
	const FILENAME       = 'growin-pricelist.csv';

	/**
	 * Pfad zur zuletzt gespeicherten Liste.
	 *
	 * @return string
	 */
	public static function stored_file() {
		$uploads = wp_upload_dir();

		return trailingslashit( $uploads['basedir'] ) . self::UPLOAD_DIRNAME . '/' . self::FILENAME;
	}

	/**
	 * Legt das Zielverzeichnis an und schützt es vor direktem Zugriff.
	 *
	 * @return string|WP_Error Verzeichnis.
	 */
	public static function prepare_dir() {
		$uploads = wp_upload_dir();
		$dir     = trailingslashit( $uploads['basedir'] ) . self::UPLOAD_DIRNAME;

		if ( ! wp_mkdir_p( $dir ) ) {
			return new WP_Error( 'gss_mkdir', __( 'Das Verzeichnis für die Bestandsliste konnte nicht angelegt werden.', 'growin-stock-sync' ) );
		}

		$htaccess = $dir . '/.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			file_put_contents( $htaccess, "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" ); // phpcs:ignore
		}

		$index = $dir . '/index.html';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, '' ); // phpcs:ignore
		}

		return $dir;
	}

	/**
	 * Speichert einen CSV-Inhalt als aktuelle Liste.
	 *
	 * @param string $content Rohinhalt.
	 * @return true|WP_Error
	 */
	public static function store( $content ) {
		$dir = self::prepare_dir();

		if ( is_wp_error( $dir ) ) {
			return $dir;
		}

		$written = file_put_contents( self::stored_file(), $content ); // phpcs:ignore

		if ( false === $written ) {
			return new WP_Error( 'gss_write', __( 'Die Bestandsliste konnte nicht gespeichert werden.', 'growin-stock-sync' ) );
		}

		update_option(
			'gss_source_meta',
			array(
				'stored_at' => current_time( 'mysql' ),
				'bytes'     => strlen( $content ),
			),
			false
		);

		return true;
	}

	/**
	 * Liest die gespeicherte Liste.
	 *
	 * @return string|WP_Error
	 */
	public static function read_stored() {
		$file = self::stored_file();

		if ( ! file_exists( $file ) ) {
			return new WP_Error( 'gss_missing', __( 'Es liegt noch keine Bestandsliste vor. Bitte eine Datei hochladen oder den automatischen Abruf einrichten.', 'growin-stock-sync' ) );
		}

		$content = file_get_contents( $file ); // phpcs:ignore

		if ( false === $content ) {
			return new WP_Error( 'gss_read', __( 'Die gespeicherte Bestandsliste konnte nicht gelesen werden.', 'growin-stock-sync' ) );
		}

		return $content;
	}

	/**
	 * Informationen zur gespeicherten Datei.
	 *
	 * @return array
	 */
	public static function stored_meta() {
		$meta = get_option( 'gss_source_meta', array() );
		$file = self::stored_file();

		return array(
			'exists'    => file_exists( $file ),
			'stored_at' => isset( $meta['stored_at'] ) ? $meta['stored_at'] : '',
			'bytes'     => file_exists( $file ) ? (int) filesize( $file ) : 0,
		);
	}

	/**
	 * Übernimmt eine hochgeladene Datei.
	 *
	 * @param array $file Eintrag aus $_FILES.
	 * @return true|WP_Error
	 */
	public static function handle_upload( $file ) {
		if ( empty( $file ) || ! isset( $file['tmp_name'] ) || '' === $file['tmp_name'] ) {
			return new WP_Error( 'gss_upload', __( 'Es wurde keine Datei ausgewählt.', 'growin-stock-sync' ) );
		}

		if ( isset( $file['error'] ) && UPLOAD_ERR_OK !== (int) $file['error'] ) {
			return new WP_Error( 'gss_upload', __( 'Der Upload ist fehlgeschlagen.', 'growin-stock-sync' ) );
		}

		if ( ! is_uploaded_file( $file['tmp_name'] ) ) {
			return new WP_Error( 'gss_upload', __( 'Die Datei stammt nicht aus einem gültigen Upload.', 'growin-stock-sync' ) );
		}

		$content = file_get_contents( $file['tmp_name'] ); // phpcs:ignore

		if ( false === $content || '' === trim( (string) $content ) ) {
			return new WP_Error( 'gss_upload', __( 'Die hochgeladene Datei ist leer.', 'growin-stock-sync' ) );
		}

		$check = GSS_Parser::parse( $content );

		if ( ! $check['ok'] ) {
			return new WP_Error( 'gss_upload', $check['error'] );
		}

		return self::store( $content );
	}

	/**
	 * Ruft die Liste automatisch aus dem Kundenbereich ab.
	 *
	 * @return array{ok:bool,content:string,steps:array,error:string}
	 */
	public static function fetch_remote() {
		$out = array(
			'ok'      => false,
			'content' => '',
			'steps'   => array(),
			'error'   => '',
		);

		$list_url  = (string) GSS_Settings::get( 'list_url' );
		$login_url = (string) GSS_Settings::get( 'login_url' );
		$user      = GSS_Settings::get_username();
		$pass      = GSS_Settings::get_password();

		if ( '' === $list_url ) {
			$out['error'] = __( 'Es ist keine URL für die Preisliste hinterlegt.', 'growin-stock-sync' );
			return $out;
		}

		$cookies = array();

		if ( '' !== $login_url && '' !== $user ) {
			$login = self::login( $login_url, $user, $pass );

			$out['steps'] = array_merge( $out['steps'], $login['steps'] );

			if ( ! $login['ok'] ) {
				$out['error'] = $login['error'];
				return $out;
			}

			$cookies = $login['cookies'];
		}

		$response = wp_remote_get(
			$list_url,
			array(
				'timeout'     => 60,
				'redirection' => 5,
				'cookies'     => $cookies,
				'user-agent'  => self::user_agent(),
				'headers'     => array(
					'Accept' => 'text/csv,application/csv,text/plain,*/*',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			$out['error']   = $response->get_error_message();
			$out['steps'][] = sprintf( 'GET %s: %s', $list_url, $response->get_error_message() );
			return $out;
		}

		$code         = (int) wp_remote_retrieve_response_code( $response );
		$content_type = (string) wp_remote_retrieve_header( $response, 'content-type' );
		$body         = (string) wp_remote_retrieve_body( $response );

		$out['steps'][] = sprintf(
			'GET %s: HTTP %d, %s, %s Bytes',
			$list_url,
			$code,
			'' !== $content_type ? $content_type : 'ohne Content-Type',
			number_format_i18n( strlen( $body ) )
		);

		if ( 200 !== $code ) {
			$out['error'] = sprintf(
				/* translators: %d: HTTP status code */
				__( 'Der Abruf der Preisliste endete mit HTTP-Status %d.', 'growin-stock-sync' ),
				$code
			);
			return $out;
		}

		$check = GSS_Parser::parse( $body );

		if ( ! $check['ok'] ) {
			$out['error'] = $check['error'];

			// Genauer sagen, woran es liegt.
			if ( preg_match( '/<input\b[^>]*\btype\s*=\s*["\']?password/i', $body ) ) {
				$out['error'] = __( 'Statt der Datei kam erneut eine Login-Seite zurück. Die Anmeldung hat also nicht gegriffen.', 'growin-stock-sync' );
			} elseif ( preg_match( '/(kein zugriff|keine berechtigung|not authorized|access denied|anmelden)/i', wp_strip_all_tags( $body ) ) ) {
				$out['error'] = __( 'Die Seite meldet fehlende Berechtigung. Vermutlich fehlt dem Konto die Freigabe für die Preisliste.', 'growin-stock-sync' );
			}

			return $out;
		}

		$out['ok']      = true;
		$out['content'] = $body;

		return $out;
	}

	/**
	 * Meldet sich im Kundenbereich an und liefert die Session-Cookies.
	 *
	 * @param string $login_url Login-Adresse.
	 * @param string $user      Benutzername.
	 * @param string $pass      Passwort.
	 * @return array{ok:bool,cookies:array,steps:array,error:string}
	 */
	protected static function login( $login_url, $user, $pass ) {
		$out = array(
			'ok'      => false,
			'cookies' => array(),
			'steps'   => array(),
			'error'   => '',
		);

		// Schritt 1: Formular holen, um Cookies und versteckte Felder einzusammeln.
		$get = wp_remote_get(
			$login_url,
			array(
				'timeout'     => 30,
				'redirection' => 5,
				'user-agent'  => self::user_agent(),
			)
		);

		if ( is_wp_error( $get ) ) {
			$out['error']   = $get->get_error_message();
			$out['steps'][] = sprintf( 'GET %s: %s', $login_url, $get->get_error_message() );
			return $out;
		}

		$cookies = wp_remote_retrieve_cookies( $get );
		$html    = (string) wp_remote_retrieve_body( $get );
		$form    = self::analyze_login_form( $html, $login_url );

		// Erkanntes Formular bevorzugen, eingetragene Werte haben Vorrang.
		$fields      = $form['hidden'];
		$user_field  = (string) GSS_Settings::get( 'field_user', '' );
		$pass_field  = (string) GSS_Settings::get( 'field_pass', '' );
		$post_url    = (string) GSS_Settings::get( 'login_post_url', '' );

		if ( '' === $user_field ) {
			$user_field = '' !== $form['user_field'] ? $form['user_field'] : 'username';
		}

		if ( '' === $pass_field ) {
			$pass_field = '' !== $form['pass_field'] ? $form['pass_field'] : 'password';
		}

		if ( '' === $post_url ) {
			$post_url = '' !== $form['action'] ? $form['action'] : $login_url;
		}

		$out['steps'][] = sprintf(
			'GET %s: HTTP %d, %d Cookie(s), %s',
			$login_url,
			(int) wp_remote_retrieve_response_code( $get ),
			count( $cookies ),
			$form['found']
				? sprintf(
					'Anmeldeformular erkannt (Ziel %s, Felder %s / %s, %d verstecktes/e Feld(er))',
					$post_url,
					$user_field,
					$pass_field,
					count( $form['hidden'] )
				)
				: 'kein Anmeldeformular gefunden, es werden die eingetragenen Feldnamen verwendet'
		);

		// Schritt 2: Anmeldedaten senden.
		$fields[ $user_field ] = $user;
		$fields[ $pass_field ] = $pass;

		foreach ( GSS_Settings::extra_login_fields() as $key => $value ) {
			$fields[ $key ] = $value;
		}

		/*
		 * Bewusst ohne automatisches Folgen der Weiterleitung: Die Sitzungs-Cookies
		 * stehen in der Antwort auf den POST selbst. Würde die Weiterleitung intern
		 * verfolgt, käme nur die letzte Antwort zurück und das Cookie ginge verloren.
		 */
		$post = wp_remote_post(
			$post_url,
			array(
				'timeout'     => 45,
				'redirection' => 0,
				'cookies'     => $cookies,
				'body'        => $fields,
				'user-agent'  => self::user_agent(),
				'headers'     => array(
					'Referer' => $login_url,
				),
			)
		);

		if ( is_wp_error( $post ) ) {
			$out['error']   = $post->get_error_message();
			$out['steps'][] = sprintf( 'POST %s: %s', $post_url, $post->get_error_message() );
			return $out;
		}

		$code    = (int) wp_remote_retrieve_response_code( $post );
		$merged  = self::merge_cookies( $cookies, wp_remote_retrieve_cookies( $post ) );
		$target  = (string) wp_remote_retrieve_header( $post, 'location' );

		$out['steps'][] = sprintf(
			'POST %s: HTTP %d%s, %d Cookie(s) nach dem Login',
			$post_url,
			$code,
			'' !== $target ? ', Weiterleitung nach ' . $target : '',
			count( $merged )
		);

		// Der Weiterleitung einmal folgen, damit die Sitzung vollständig aufgebaut ist.
		if ( in_array( $code, array( 301, 302, 303, 307, 308 ), true ) && '' !== $target ) {
			$follow = wp_remote_get(
				self::absolute_url( $target, $post_url ),
				array(
					'timeout'     => 30,
					'redirection' => 3,
					'cookies'     => $merged,
					'user-agent'  => self::user_agent(),
				)
			);

			if ( ! is_wp_error( $follow ) ) {
				$merged = self::merge_cookies( $merged, wp_remote_retrieve_cookies( $follow ) );

				$out['steps'][] = sprintf(
					'GET %s: HTTP %d, %d Cookie(s)',
					self::absolute_url( $target, $post_url ),
					(int) wp_remote_retrieve_response_code( $follow ),
					count( $merged )
				);
			}
		}

		if ( 200 === $code && '' === $target ) {
			$body = (string) wp_remote_retrieve_body( $post );

			if ( preg_match( '/<input\b[^>]*\btype\s*=\s*["\']?password/i', $body ) ) {
				$out['steps'][] = 'Hinweis: Die Antwort auf den Login enthält wieder ein Passwortfeld. Das deutet auf abgelehnte Zugangsdaten oder ein fehlendes Formularfeld hin.';
			}
		}

		$out['ok']      = true;
		$out['cookies'] = $merged;

		return $out;
	}

	/**
	 * Untersucht die Login-Seite und liefert das tatsächliche Anmeldeformular.
	 *
	 * Gesucht wird das Formular, das ein Passwortfeld enthält. Daraus stammen dann
	 * Ziel-Adresse, Feldnamen und versteckte Felder. Damit funktioniert die Anmeldung
	 * auch bei Shopsystemen wie Magento, die verschachtelte Feldnamen der Art
	 * login[username] verwenden und das Formular an eine andere Adresse schicken als
	 * die, unter der es angezeigt wird.
	 *
	 * @param string $html     Seiteninhalt.
	 * @param string $page_url Adresse der Login-Seite (für relative Ziele).
	 * @return array{action:string,user_field:string,pass_field:string,hidden:array,found:bool}
	 */
	public static function analyze_login_form( $html, $page_url ) {
		$out = array(
			'action'     => '',
			'user_field' => '',
			'pass_field' => '',
			'hidden'     => array(),
			'found'      => false,
		);

		$html = (string) $html;

		if ( '' === trim( $html ) ) {
			return $out;
		}

		if ( ! preg_match_all( '/<form\b([^>]*)>(.*?)<\/form>/is', $html, $forms, PREG_SET_ORDER ) ) {
			return $out;
		}

		foreach ( $forms as $form ) {
			$attributes = $form[1];
			$inner      = $form[2];

			if ( ! preg_match( '/<input\b[^>]*\btype\s*=\s*["\']?password["\']?[^>]*>/i', $inner ) ) {
				continue;
			}

			$out['found'] = true;

			if ( preg_match( '/\baction\s*=\s*["\']([^"\']*)["\']/i', $attributes, $match ) ) {
				$out['action'] = self::absolute_url( html_entity_decode( $match[1], ENT_QUOTES, 'UTF-8' ), $page_url );
			}

			if ( '' === $out['action'] ) {
				$out['action'] = $page_url;
			}

			if ( preg_match_all( '/<input\b[^>]*>/i', $inner, $inputs ) ) {
				foreach ( $inputs[0] as $input ) {
					$type = 'text';
					$name = '';
					$val  = '';

					if ( preg_match( '/\btype\s*=\s*["\']?([^"\'\s>]+)/i', $input, $m ) ) {
						$type = strtolower( $m[1] );
					}

					if ( preg_match( '/\bname\s*=\s*["\']([^"\']+)["\']/i', $input, $m ) ) {
						$name = html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' );
					}

					if ( preg_match( '/\bvalue\s*=\s*["\']([^"\']*)["\']/i', $input, $m ) ) {
						$val = html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' );
					}

					if ( '' === $name ) {
						continue;
					}

					if ( 'password' === $type && '' === $out['pass_field'] ) {
						$out['pass_field'] = $name;
						continue;
					}

					if ( 'hidden' === $type ) {
						$out['hidden'][ $name ] = $val;
						continue;
					}

					if ( in_array( $type, array( 'text', 'email' ), true ) && '' === $out['user_field'] ) {
						$out['user_field'] = $name;
					}
				}
			}

			break;
		}

		return $out;
	}

	/**
	 * Macht aus einer möglicherweise relativen Adresse eine absolute.
	 *
	 * @param string $url  Adresse aus dem Formular.
	 * @param string $base Adresse der Seite.
	 * @return string
	 */
	public static function absolute_url( $url, $base ) {
		$url = trim( (string) $url );

		if ( '' === $url ) {
			return '';
		}

		if ( preg_match( '#^https?://#i', $url ) ) {
			return $url;
		}

		$parts = wp_parse_url( $base );

		if ( ! $parts || empty( $parts['host'] ) ) {
			return $url;
		}

		$scheme = ! empty( $parts['scheme'] ) ? $parts['scheme'] : 'https';
		$root   = $scheme . '://' . $parts['host'] . ( ! empty( $parts['port'] ) ? ':' . $parts['port'] : '' );

		if ( 0 === strpos( $url, '//' ) ) {
			return $scheme . ':' . $url;
		}

		if ( 0 === strpos( $url, '/' ) ) {
			return $root . $url;
		}

		$path = ! empty( $parts['path'] ) ? $parts['path'] : '/';
		$path = preg_replace( '#/[^/]*$#', '/', $path );

		return $root . $path . $url;
	}

	/**
	 * Liest alle versteckten Formularfelder aus einer Login-Seite.
	 *
	 * @param string $html Seiteninhalt.
	 * @return array<string,string>
	 */
	public static function extract_hidden_fields( $html ) {
		$fields = array();

		if ( '' === trim( (string) $html ) ) {
			return $fields;
		}

		if ( preg_match_all( '/<input\b[^>]*>/i', $html, $inputs ) ) {
			foreach ( $inputs[0] as $input ) {
				$type = '';
				$name = '';
				$val  = '';

				if ( preg_match( '/\btype\s*=\s*["\']?([^"\'\s>]+)/i', $input, $m ) ) {
					$type = strtolower( $m[1] );
				}

				if ( preg_match( '/\bname\s*=\s*["\']([^"\']+)["\']/i', $input, $m ) ) {
					$name = $m[1];
				}

				if ( preg_match( '/\bvalue\s*=\s*["\']([^"\']*)["\']/i', $input, $m ) ) {
					$val = html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' );
				}

				if ( '' === $name || 'hidden' !== $type ) {
					continue;
				}

				$fields[ $name ] = $val;
			}
		}

		return $fields;
	}

	/**
	 * Listet alle Formularfelder einer Seite (für die Diagnose im Adminbereich).
	 *
	 * @param string $html Seiteninhalt.
	 * @return array<int,string>
	 */
	public static function describe_fields( $html ) {
		$described = array();

		if ( preg_match_all( '/<input\b[^>]*>/i', (string) $html, $inputs ) ) {
			foreach ( $inputs[0] as $input ) {
				$type = 'text';
				$name = '';

				if ( preg_match( '/\btype\s*=\s*["\']?([^"\'\s>]+)/i', $input, $m ) ) {
					$type = strtolower( $m[1] );
				}

				if ( preg_match( '/\bname\s*=\s*["\']([^"\']+)["\']/i', $input, $m ) ) {
					$name = $m[1];
				}

				if ( '' === $name ) {
					continue;
				}

				$described[] = sprintf( '%s (%s)', $name, $type );
			}
		}

		return array_values( array_unique( $described ) );
	}

	/**
	 * Führt zwei Cookie-Listen zusammen, spätere gewinnen.
	 *
	 * @param array $first  Erste Liste.
	 * @param array $second Zweite Liste.
	 * @return array
	 */
	protected static function merge_cookies( $first, $second ) {
		$merged = array();

		foreach ( array_merge( (array) $first, (array) $second ) as $cookie ) {
			if ( is_object( $cookie ) && isset( $cookie->name ) ) {
				$merged[ $cookie->name ] = $cookie;
			}
		}

		return array_values( $merged );
	}

	/**
	 * Kennung für ausgehende Anfragen.
	 *
	 * @return string
	 */
	protected static function user_agent() {
		return 'Mozilla/5.0 (compatible; GrowinStockSync/' . GSS_VERSION . '; +' . home_url( '/' ) . ')';
	}

	/**
	 * Prüft die Verbindung und liefert eine ausführliche Diagnose.
	 *
	 * @return array{ok:bool,messages:array}
	 */
	public static function test_connection() {
		$messages  = array();
		$login_url = (string) GSS_Settings::get( 'login_url' );

		if ( '' !== $login_url ) {
			$get = wp_remote_get(
				$login_url,
				array(
					'timeout'    => 30,
					'user-agent' => self::user_agent(),
				)
			);

			if ( is_wp_error( $get ) ) {
				$messages[] = sprintf( 'Login-Seite: Fehler – %s', $get->get_error_message() );
			} else {
				$html       = (string) wp_remote_retrieve_body( $get );
				$messages[] = sprintf( 'Login-Seite: HTTP %d', (int) wp_remote_retrieve_response_code( $get ) );
				$form       = self::analyze_login_form( $html, $login_url );

				if ( $form['found'] ) {
					$messages[] = sprintf(
						'Anmeldeformular erkannt. Ziel: %s | Benutzerfeld: %s | Passwortfeld: %s | versteckte Felder: %s',
						$form['action'],
						'' !== $form['user_field'] ? $form['user_field'] : 'keines gefunden',
						'' !== $form['pass_field'] ? $form['pass_field'] : 'keines gefunden',
						$form['hidden'] ? implode( ', ', array_keys( $form['hidden'] ) ) : 'keine'
					);

					$user_override = (string) GSS_Settings::get( 'field_user', '' );
					$pass_override = (string) GSS_Settings::get( 'field_pass', '' );
					$post_override = (string) GSS_Settings::get( 'login_post_url', '' );

					if ( '' !== $user_override || '' !== $pass_override || '' !== $post_override ) {
						$messages[] = sprintf(
							'Eigene Vorgaben aktiv (überschreiben die Erkennung): Benutzerfeld %s | Passwortfeld %s | Ziel %s',
							'' !== $user_override ? $user_override : 'automatisch',
							'' !== $pass_override ? $pass_override : 'automatisch',
							'' !== $post_override ? $post_override : 'automatisch'
						);
					}
				} else {
					$fields     = self::describe_fields( $html );
					$messages[] = $fields
						? 'Kein Anmeldeformular erkannt. Gefundene Felder auf der Seite: ' . implode( ', ', array_slice( $fields, 0, 25 ) )
						: 'Auf der Login-Seite wurden keine Formularfelder gefunden.';
				}
			}
		} else {
			$messages[] = 'Keine Login-URL hinterlegt – es wird ohne Anmeldung abgerufen.';
		}

		$messages[] = sprintf(
			'Zugangsdaten: Benutzer %s, Passwort %s.',
			'' !== GSS_Settings::get_username() ? '"' . GSS_Settings::get_username() . '"' : 'nicht hinterlegt',
			GSS_Settings::has_password()
				? sprintf(
					'hinterlegt (%d Zeichen, Quelle: %s)',
					strlen( GSS_Settings::get_password() ),
					GSS_Settings::password_is_fixed() ? 'wp-config.php' : 'Datenbank'
				)
				: 'nicht hinterlegt'
		);

		$result = self::fetch_remote();

		foreach ( $result['steps'] as $step ) {
			$messages[] = $step;
		}

		if ( $result['ok'] ) {
			$parsed     = GSS_Parser::parse( $result['content'] );
			$messages[] = sprintf( 'Ergebnis: gültige Preisliste mit %s Zeilen.', number_format_i18n( count( $parsed['rows'] ) ) );
		} else {
			$messages[] = 'Ergebnis: ' . $result['error'];
		}

		return array(
			'ok'       => $result['ok'],
			'messages' => $messages,
		);
	}
}
