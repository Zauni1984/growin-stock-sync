<?php
/**
 * Adminoberfläche.
 *
 * @package GrowinStockSync
 */

defined( 'ABSPATH' ) || exit;

/**
 * Menü, Formulare und Ansichten im Backend.
 */
class GSS_Admin {

	/**
	 * Meldungen für die aktuelle Ansicht.
	 *
	 * @var array<int,array{type:string,text:string}>
	 */
	protected $notices = array();

	/**
	 * Konstruktor.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this, 'handle_actions' ) );

		add_action( 'woocommerce_product_options_inventory_product_data', array( $this, 'product_field' ) );
		add_action( 'woocommerce_admin_process_product_object', array( $this, 'save_product_field' ) );
	}

	/**
	 * Menüeintrag unter WooCommerce.
	 *
	 * @return void
	 */
	public function menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Growin Bestand', 'growin-stock-sync' ),
			__( 'Growin Bestand', 'growin-stock-sync' ),
			'manage_woocommerce',
			GSS_SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Verarbeitet Formulare und Aktionen.
	 *
	 * @return void
	 */
	public function handle_actions() {
		if ( ! isset( $_REQUEST['page'] ) || GSS_SLUG !== $_REQUEST['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		// Einstellungen speichern.
		if ( isset( $_POST['gss_save_settings'] ) ) {
			check_admin_referer( 'gss_settings' );

			$input = isset( $_POST['gss'] ) ? wp_unslash( $_POST['gss'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			GSS_Settings::save( (array) $input );
			GSS_Plugin::schedule();

			$this->redirect( 'settings', array( 'gss_msg' => 'saved' ) );
		}

		// Datei hochladen.
		if ( isset( $_POST['gss_upload'] ) ) {
			check_admin_referer( 'gss_upload' );

			$file   = isset( $_FILES['gss_file'] ) ? $_FILES['gss_file'] : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			$stored = GSS_Source::handle_upload( $file );

			if ( is_wp_error( $stored ) ) {
				set_transient( 'gss_notice', array( 'type' => 'error', 'text' => $stored->get_error_message() ), 60 );
				$this->redirect( 'overview' );
			}

			$run = GSS_Sync::run( 'upload' );
			set_transient( 'gss_notice', array( 'type' => 'success', 'text' => $this->summarize( $run ) ), 60 );
			$this->redirect( 'overview' );
		}

		// Jetzt synchronisieren.
		if ( isset( $_GET['gss_action'] ) && 'sync' === $_GET['gss_action'] ) {
			check_admin_referer( 'gss_sync' );

			$run = GSS_Sync::run( 'manual' );
			set_transient(
				'gss_notice',
				array(
					'type' => empty( $run['errors'] ) ? 'success' : 'warning',
					'text' => $this->summarize( $run ),
				),
				60
			);

			$this->redirect( 'overview' );
		}

		// Verbindung testen.
		if ( isset( $_GET['gss_action'] ) && 'test' === $_GET['gss_action'] ) {
			check_admin_referer( 'gss_test' );

			$test = GSS_Source::test_connection();
			set_transient( 'gss_test_result', $test, 300 );

			$this->redirect( 'settings' );
		}

		// Gespeichertes Passwort löschen.
		if ( isset( $_GET['gss_action'] ) && 'forget_pass' === $_GET['gss_action'] ) {
			check_admin_referer( 'gss_forget_pass' );

			GSS_Settings::forget_password();
			set_transient(
				'gss_notice',
				array(
					'type' => 'success',
					'text' => __( 'Das gespeicherte Passwort wurde gelöscht.', 'growin-stock-sync' ),
				),
				60
			);

			$this->redirect( 'settings' );
		}

		// Protokoll leeren.
		if ( isset( $_GET['gss_action'] ) && 'clear_log' === $_GET['gss_action'] ) {
			check_admin_referer( 'gss_clear_log' );

			GSS_Logger::clear();
			$this->redirect( 'log' );
		}
	}

	/**
	 * Leitet innerhalb der Pluginseite weiter.
	 *
	 * @param string $tab  Ziel-Reiter.
	 * @param array  $args Zusätzliche Parameter.
	 * @return void
	 */
	protected function redirect( $tab, array $args = array() ) {
		$url = add_query_arg(
			array_merge(
				array(
					'page' => GSS_SLUG,
					'tab'  => $tab,
				),
				$args
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Fasst einen Lauf in einem Satz zusammen.
	 *
	 * @param array $run Ergebnis.
	 * @return string
	 */
	protected function summarize( array $run ) {
		$text = sprintf(
			/* translators: 1: rows, 2: matched, 3: updated, 4: skipped, 5: duration */
			__( '%1$s Zeilen gelesen, %2$s Produkte zugeordnet, %3$s aktualisiert, %4$s übersprungen (%5$s Sekunden).', 'growin-stock-sync' ),
			number_format_i18n( $run['rows'] ),
			number_format_i18n( $run['matched'] ),
			number_format_i18n( $run['updated'] ),
			number_format_i18n( $run['skipped'] ),
			number_format_i18n( $run['duration'], 1 )
		);

		if ( ! empty( $run['errors'] ) ) {
			$text .= ' ' . __( 'Fehler:', 'growin-stock-sync' ) . ' ' . implode( ' | ', array_slice( $run['errors'], 0, 3 ) );
		}

		return $text;
	}

	/**
	 * Gibt die Seite aus.
	 *
	 * @return void
	 */
	public function render() {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'overview'; // phpcs:ignore WordPress.Security.NonceVerification

		$tabs = array(
			'overview' => __( 'Übersicht', 'growin-stock-sync' ),
			'settings' => __( 'Einstellungen', 'growin-stock-sync' ),
			'catalog'  => __( 'Katalog', 'growin-stock-sync' ),
			'log'      => __( 'Protokoll', 'growin-stock-sync' ),
		);

		if ( ! isset( $tabs[ $tab ] ) ) {
			$tab = 'overview';
		}

		echo '<div class="wrap"><h1>' . esc_html__( 'Growin Bestand', 'growin-stock-sync' ) . '</h1>';

		$notice = get_transient( 'gss_notice' );

		if ( $notice && is_array( $notice ) ) {
			delete_transient( 'gss_notice' );
			printf(
				'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
				esc_attr( $notice['type'] ),
				esc_html( $notice['text'] )
			);
		}

		if ( isset( $_GET['gss_msg'] ) && 'saved' === $_GET['gss_msg'] ) { // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Einstellungen gespeichert.', 'growin-stock-sync' ) . '</p></div>';
		}

		echo '<h2 class="nav-tab-wrapper">';
		foreach ( $tabs as $key => $label ) {
			printf(
				'<a href="%s" class="nav-tab %s">%s</a>',
				esc_url( add_query_arg( array( 'page' => GSS_SLUG, 'tab' => $key ), admin_url( 'admin.php' ) ) ),
				esc_attr( $tab === $key ? 'nav-tab-active' : '' ),
				esc_html( $label )
			);
		}
		echo '</h2>';

		switch ( $tab ) {
			case 'settings':
				$this->render_settings();
				break;
			case 'catalog':
				$this->render_catalog();
				break;
			case 'log':
				$this->render_log();
				break;
			default:
				$this->render_overview();
		}

		echo '</div>';
	}

	/**
	 * Reiter "Übersicht".
	 *
	 * @return void
	 */
	protected function render_overview() {
		$settings = GSS_Settings::all();
		$meta     = GSS_Source::stored_meta();
		$last     = GSS_Logger::last();
		$next     = wp_next_scheduled( GSS_Plugin::CRON_HOOK );

		$sync_url = wp_nonce_url(
			add_query_arg(
				array( 'page' => GSS_SLUG, 'gss_action' => 'sync' ),
				admin_url( 'admin.php' )
			),
			'gss_sync'
		);

		echo '<h2>' . esc_html__( 'Stand', 'growin-stock-sync' ) . '</h2>';
		echo '<table class="widefat striped" style="max-width:900px"><tbody>';

		$this->row(
			__( 'Bezugsquelle', 'growin-stock-sync' ),
			'auto' === $settings['source_mode']
				? __( 'Automatischer Abruf aus dem Kundenbereich (mit Rückfall auf die gespeicherte Datei)', 'growin-stock-sync' )
				: __( 'Manueller Upload der Preisliste', 'growin-stock-sync' )
		);

		$this->row(
			__( 'Gespeicherte Liste', 'growin-stock-sync' ),
			$meta['exists']
				? sprintf(
					/* translators: 1: date, 2: size */
					__( 'vom %1$s, %2$s', 'growin-stock-sync' ),
					esc_html( $meta['stored_at'] ),
					esc_html( size_format( $meta['bytes'] ) )
				)
				: __( 'noch keine Datei vorhanden', 'growin-stock-sync' )
		);

		$this->row(
			__( 'Nächster automatischer Lauf', 'growin-stock-sync' ),
			$next ? esc_html( get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $next ), 'd.m.Y H:i' ) ) : __( 'nicht geplant', 'growin-stock-sync' )
		);

		$this->row( __( 'Artikel im Katalog', 'growin-stock-sync' ), number_format_i18n( GSS_Catalog::count() ) );
		$this->row( __( 'davon mit Produkt verknüpft', 'growin-stock-sync' ), number_format_i18n( GSS_Catalog::linked_count() ) );

		if ( $last ) {
			$this->row(
				__( 'Letzter Lauf', 'growin-stock-sync' ),
				esc_html( $last['time'] ) . ' – ' . esc_html( $this->summarize( $last ) )
			);
		}

		if ( 'yes' === $settings['dry_run'] ) {
			$this->row(
				__( 'Hinweis', 'growin-stock-sync' ),
				'<strong>' . esc_html__( 'Der Testlauf-Modus ist aktiv. Es werden keine Bestände geschrieben.', 'growin-stock-sync' ) . '</strong>'
			);
		}

		echo '</tbody></table>';

		echo '<p style="margin-top:16px">';
		printf(
			'<a href="%s" class="button button-primary">%s</a>',
			esc_url( $sync_url ),
			esc_html__( 'Jetzt synchronisieren', 'growin-stock-sync' )
		);
		echo '</p>';

		echo '<h2>' . esc_html__( 'Preisliste hochladen', 'growin-stock-sync' ) . '</h2>';
		echo '<p>' . esc_html__( 'Die Datei liegt im Growin-Kundenbereich unter „Preisliste“ und wird als CSV (Semikolon getrennt) geliefert. Nach dem Hochladen startet der Abgleich sofort.', 'growin-stock-sync' ) . '</p>';

		echo '<form method="post" enctype="multipart/form-data">';
		wp_nonce_field( 'gss_upload' );
		echo '<input type="file" name="gss_file" accept=".csv,text/csv,text/plain" required /> ';
		echo '<button type="submit" name="gss_upload" value="1" class="button">' . esc_html__( 'Hochladen und abgleichen', 'growin-stock-sync' ) . '</button>';
		echo '</form>';
	}

	/**
	 * Reiter "Einstellungen".
	 *
	 * @return void
	 */
	protected function render_settings() {
		$s      = GSS_Settings::all();
		$codes  = GSS_Settings::stock_codes();
		$test   = get_transient( 'gss_test_result' );
		$status = array(
			'instock'     => __( 'auf Lager', 'growin-stock-sync' ),
			'outofstock'  => __( 'nicht vorrätig', 'growin-stock-sync' ),
			'onbackorder' => __( 'lieferbar auf Bestellung', 'growin-stock-sync' ),
		);

		if ( $test && is_array( $test ) ) {
			delete_transient( 'gss_test_result' );
			printf(
				'<div class="notice notice-%s"><p><strong>%s</strong></p><pre style="white-space:pre-wrap;margin:0">%s</pre></div>',
				esc_attr( $test['ok'] ? 'success' : 'error' ),
				esc_html__( 'Ergebnis der Verbindungsprüfung', 'growin-stock-sync' ),
				esc_html( implode( "\n", $test['messages'] ) )
			);
		}

		echo '<form method="post">';
		wp_nonce_field( 'gss_settings' );
		echo '<input type="hidden" name="gss[form]" value="settings" />';

		echo '<h2>' . esc_html__( 'Bezugsquelle', 'growin-stock-sync' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row">' . esc_html__( 'Modus', 'growin-stock-sync' ) . '</th><td>';
		printf(
			'<label><input type="radio" name="gss[source_mode]" value="manual" %s> %s</label><br>',
			checked( $s['source_mode'], 'manual', false ),
			esc_html__( 'Manueller Upload (funktioniert immer)', 'growin-stock-sync' )
		);
		printf(
			'<label><input type="radio" name="gss[source_mode]" value="auto" %s> %s</label>',
			checked( $s['source_mode'], 'auto', false ),
			esc_html__( 'Automatischer Abruf mit Login', 'growin-stock-sync' )
		);
		echo '<p class="description">' . esc_html__( 'Beim automatischen Abruf wird bei einem Fehlschlag ersatzweise die zuletzt gespeicherte Datei verwendet, damit der Bestand nie ins Leere läuft.', 'growin-stock-sync' ) . '</p>';
		echo '</td></tr>';

		$this->text_field( 'list_url', __( 'URL der Preisliste', 'growin-stock-sync' ), $s['list_url'], __( 'Direktlink auf die CSV im Kundenbereich.', 'growin-stock-sync' ), 'url' );
		$this->text_field( 'login_url', __( 'URL der Login-Seite', 'growin-stock-sync' ), $s['login_url'], __( 'Leer lassen, wenn kein Login nötig ist.', 'growin-stock-sync' ), 'url' );
		$this->text_field( 'login_user', __( 'Benutzername', 'growin-stock-sync' ), $s['login_user'] );

		echo '<tr><th scope="row"><label for="gss_login_pass">' . esc_html__( 'Passwort', 'growin-stock-sync' ) . '</label></th><td>';

		if ( GSS_Settings::password_is_fixed() ) {
			echo '<p><span class="dashicons dashicons-lock" style="color:#2271b1"></span> <strong>'
				. esc_html__( 'Das Passwort steht in der wp-config.php und wird nicht in der Datenbank gespeichert.', 'growin-stock-sync' )
				. '</strong></p>';
			echo '<p class="description">' . esc_html__( 'Zum Ändern die Konstante GROWIN_SYNC_PASSWORD in der wp-config.php anpassen.', 'growin-stock-sync' ) . '</p>';
		} else {
			echo '<input type="password" id="gss_login_pass" name="gss[login_pass]" value="" class="regular-text" autocomplete="new-password" /> ';

			if ( GSS_Settings::has_password() ) {
				$set_at = (string) get_option( 'gss_password_set_at', '' );

				printf(
					'<p><span class="dashicons dashicons-yes-alt" style="color:#00a32a"></span> <strong>%s</strong>%s</p>',
					esc_html(
						sprintf(
							/* translators: %d: number of characters */
							__( 'Passwort ist gespeichert (%d Zeichen).', 'growin-stock-sync' ),
							strlen( GSS_Settings::get_password() )
						)
					),
					$set_at ? ' <span class="description">' . esc_html( sprintf( __( 'zuletzt gesetzt am %s', 'growin-stock-sync' ), $set_at ) ) . '</span>' : ''
				);

				$forget_url = wp_nonce_url(
					add_query_arg( array( 'page' => GSS_SLUG, 'gss_action' => 'forget_pass' ), admin_url( 'admin.php' ) ),
					'gss_forget_pass'
				);

				printf(
					'<p class="description">%s <a href="%s">%s</a></p>',
					esc_html__( 'Das Feld bleibt aus Sicherheitsgründen immer leer, das Passwort wird nie in der Seite ausgegeben. Zum Ändern einfach ein neues eintragen und speichern.', 'growin-stock-sync' ),
					esc_url( $forget_url ),
					esc_html__( 'Gespeichertes Passwort löschen', 'growin-stock-sync' )
				);
			} else {
				echo '<p class="description">' . esc_html__( 'Noch kein Passwort hinterlegt. Es wird verschlüsselt übertragen, aber im Klartext in der Datenbank abgelegt, damit der geplante Abruf ohne Eingriff läuft.', 'growin-stock-sync' ) . '</p>';
			}

			echo '<p class="description">' . esc_html__( 'Soll das Passwort nicht in die Datenbank, kann es stattdessen in der wp-config.php stehen:', 'growin-stock-sync' )
				. ' <code>define( \'GROWIN_SYNC_PASSWORD\', \'…\' );</code></p>';
		}

		echo '</td></tr>';

		$this->text_field( 'login_post_url', __( 'Ziel des Anmeldeformulars', 'growin-stock-sync' ), $s['login_post_url'], __( 'Leer lassen: Das Plugin liest das Ziel aus dem Formular der Login-Seite. Nur ausfüllen, wenn die Erkennung danebenliegt.', 'growin-stock-sync' ), 'url' );
		$this->text_field( 'field_user', __( 'Formularfeld für den Benutzernamen', 'growin-stock-sync' ), $s['field_user'], __( 'Leer lassen für die automatische Erkennung. Manche Shops verschachteln die Namen, etwa login[username].', 'growin-stock-sync' ) );
		$this->text_field( 'field_pass', __( 'Formularfeld für das Passwort', 'growin-stock-sync' ), $s['field_pass'], __( 'Leer lassen für die automatische Erkennung, sonst etwa password oder login[password].', 'growin-stock-sync' ) );

		echo '<tr><th scope="row"><label for="gss_extra">' . esc_html__( 'Zusätzliche Formularfelder', 'growin-stock-sync' ) . '</label></th><td>';
		printf(
			'<textarea id="gss_extra" name="gss[extra_fields]" rows="3" class="large-text code" placeholder="%s">%s</textarea>',
			esc_attr( "login=1\nrememberme=forever" ),
			esc_textarea( $s['extra_fields'] )
		);
		echo '<p class="description">' . esc_html__( 'Je Zeile ein Paar aus Feldname und Wert, getrennt durch ein Gleichheitszeichen. Versteckte Felder der Login-Seite werden automatisch übernommen.', 'growin-stock-sync' ) . '</p>';
		echo '</td></tr>';

		echo '</tbody></table>';

		$test_url = wp_nonce_url(
			add_query_arg( array( 'page' => GSS_SLUG, 'gss_action' => 'test' ), admin_url( 'admin.php' ) ),
			'gss_test'
		);
		printf(
			'<p><a href="%s" class="button">%s</a> <span class="description">%s</span></p>',
			esc_url( $test_url ),
			esc_html__( 'Verbindung testen', 'growin-stock-sync' ),
			esc_html__( 'Zeigt an, welche Formularfelder die Login-Seite hat und ob eine gültige CSV zurückkommt.', 'growin-stock-sync' )
		);

		echo '<h2>' . esc_html__( 'Zeitsteuerung und Zuordnung', 'growin-stock-sync' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row">' . esc_html__( 'Automatischer Abgleich', 'growin-stock-sync' ) . '</th><td><select name="gss[cron_interval]">';
		foreach ( GSS_Settings::cron_choices() as $key => $label ) {
			printf( '<option value="%s" %s>%s</option>', esc_attr( $key ), selected( $s['cron_interval'], $key, false ), esc_html( $label ) );
		}
		echo '</select></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Vorrang', 'growin-stock-sync' ) . '</th><td>';
		printf(
			'<label><input type="radio" name="gss[precedence]" value="bloomtech" %s> %s</label><br>',
			checked( $s['precedence'], 'bloomtech', false ),
			esc_html__( 'Bloomtech geht vor: Produkte, die der Bloomtech-Abgleich betreut, werden nicht angefasst', 'growin-stock-sync' )
		);
		printf(
			'<label><input type="radio" name="gss[precedence]" value="growin" %s> %s</label>',
			checked( $s['precedence'], 'growin', false ),
			esc_html__( 'Growin schreibt auch dort mit (nicht empfohlen)', 'growin-stock-sync' )
		);
		echo '<p class="description">' . esc_html( GSS_Bloomtech::describe() ) . '</p>';
		echo '<p class="description">' . esc_html__( 'Erkannt wird die Bloomtech-Hoheit an den Produktfeldern des Bloomtech-Plugins und an dessen Artikelkatalog. Ist der Bloomtech-Abgleich für ein Produkt ausdrücklich ausgesetzt, darf Growin übernehmen.', 'growin-stock-sync' ) . '</p>';
		echo '</td></tr>';

		$this->text_field( 'sku_prefix', __( 'Präfix vor der Artikelnummer', 'growin-stock-sync' ), $s['sku_prefix'], __( 'Nur nötig, wenn die SKUs im Shop z. B. als GI-101478 angelegt sind. Zugeordnet wird ausschließlich über Artikelnummer = SKU, nie über die EAN.', 'growin-stock-sync' ) );

		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Bestandslogik', 'growin-stock-sync' ) . '</h2>';
		echo '<p>' . esc_html__( 'Growin liefert keine Stückzahlen, sondern fünf Bestandsstufen. Hier legst Du fest, was jede Stufe im Shop bedeutet.', 'growin-stock-sync' ) . '</p>';

		echo '<table class="widefat striped" style="max-width:900px"><thead><tr>';
		echo '<th>' . esc_html__( 'Growin-Stufe', 'growin-stock-sync' ) . '</th>';
		echo '<th>' . esc_html__( 'Lagerstatus', 'growin-stock-sync' ) . '</th>';
		echo '<th>' . esc_html__( 'Ersatzmenge', 'growin-stock-sync' ) . '</th>';
		echo '<th>' . esc_html__( 'Lieferrückstand', 'growin-stock-sync' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $codes as $code => $label ) {
			echo '<tr>';
			printf( '<td><strong>%d</strong> – %s</td>', (int) $code, esc_html( $label ) );

			echo '<td><select name="gss[status_map][' . (int) $code . ']">';
			foreach ( $status as $key => $text ) {
				printf( '<option value="%s" %s>%s</option>', esc_attr( $key ), selected( $s['status_map'][ $code ], $key, false ), esc_html( $text ) );
			}
			echo '</select></td>';

			printf(
				'<td><input type="number" min="0" step="1" name="gss[quantity_map][%d]" value="%d" class="small-text" /></td>',
				(int) $code,
				(int) $s['quantity_map'][ $code ]
			);

			echo '<td><select name="gss[backorder_map][' . (int) $code . ']">';
			foreach ( array(
				'no'     => __( 'nicht erlauben', 'growin-stock-sync' ),
				'notify' => __( 'erlauben, Kunde wird informiert', 'growin-stock-sync' ),
				'yes'    => __( 'erlauben', 'growin-stock-sync' ),
			) as $key => $text ) {
				printf( '<option value="%s" %s>%s</option>', esc_attr( $key ), selected( $s['backorder_map'][ $code ], $key, false ), esc_html( $text ) );
			}
			echo '</select></td>';
			echo '</tr>';
		}

		echo '</tbody></table>';

		echo '<table class="form-table" role="presentation"><tbody>';

		$this->checkbox(
			'set_quantities',
			__( 'Bei Produkten mit eigener Bestandsverwaltung Ersatzmengen setzen', 'growin-stock-sync' ),
			$s['set_quantities'],
			__( 'WooCommerce leitet den Lagerstatus bei aktiver Bestandsverwaltung aus der Menge ab. Ohne diese Option bleiben solche Produkte unverändert.', 'growin-stock-sync' )
		);

		echo '<tr><th scope="row">' . esc_html__( 'Auslaufende Artikel', 'growin-stock-sync' ) . '</th><td><select name="gss[discontinued]">';
		foreach ( array(
			'ignore'     => __( 'wie alle anderen behandeln', 'growin-stock-sync' ),
			'report'     => __( 'nur im Protokoll melden', 'growin-stock-sync' ),
			'outofstock' => __( 'auf „nicht vorrätig“ setzen', 'growin-stock-sync' ),
		) as $key => $label ) {
			printf( '<option value="%s" %s>%s</option>', esc_attr( $key ), selected( $s['discontinued'], $key, false ), esc_html( $label ) );
		}
		echo '</select></td></tr>';

		echo '</tbody></table>';

		$shop_gross = function_exists( 'wc_prices_include_tax' ) ? wc_prices_include_tax() : false;

		echo '<h2>' . esc_html__( 'Verkaufspreise', 'growin-stock-sync' ) . '</h2>';
		printf(
			'<p>%s <strong>%s</strong>.</p>',
			esc_html__( 'Dieser Shop speichert Produktpreise laut WooCommerce-Einstellung als', 'growin-stock-sync' ),
			esc_html( $shop_gross ? __( 'Bruttopreise inklusive Steuer', 'growin-stock-sync' ) : __( 'Nettopreise ohne Steuer', 'growin-stock-sync' ) )
		);
		echo '<p>' . esc_html__( 'In der Growin-Liste ist der Endkundenpreis ein Bruttopreis inklusive Mehrwertsteuer, der Händlerpreis dagegen netto. Das Plugin rechnet mit dem Steuersatz aus der Spalte MwSt auf die Schreibweise des Shops um.', 'growin-stock-sync' ) . '</p>';

		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row">' . esc_html__( 'Preisübernahme', 'growin-stock-sync' ) . '</th><td>';
		printf(
			'<label><input type="radio" name="gss[sync_price]" value="off" %s> %s</label><br>',
			checked( $s['sync_price'], 'off', false ),
			esc_html__( 'aus, Preise bleiben unverändert', 'growin-stock-sync' )
		);
		printf(
			'<label><input type="radio" name="gss[sync_price]" value="on" %s> %s</label>',
			checked( $s['sync_price'], 'on', false ),
			esc_html__( 'an, Verkaufspreis bei jedem Lauf mitziehen', 'growin-stock-sync' )
		);
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Preisquelle', 'growin-stock-sync' ) . '</th><td><select name="gss[price_source]">';
		foreach ( array(
			'retail' => __( 'Endkundenpreis (empfohlener Verkaufspreis)', 'growin-stock-sync' ),
			'dealer' => __( 'Händlerpreis (Einkauf, nur sinnvoll mit Aufschlag)', 'growin-stock-sync' ),
		) as $key => $label ) {
			printf( '<option value="%s" %s>%s</option>', esc_attr( $key ), selected( $s['price_source'], $key, false ), esc_html( $label ) );
		}
		echo '</select></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Die Preise der Quelle sind', 'growin-stock-sync' ) . '</th><td><select name="gss[price_is_gross]">';
		printf( '<option value="yes" %s>%s</option>', selected( $s['price_is_gross'], 'yes', false ), esc_html__( 'Bruttopreise inklusive Steuer (gilt für den Endkundenpreis)', 'growin-stock-sync' ) );
		printf( '<option value="no" %s>%s</option>', selected( $s['price_is_gross'], 'no', false ), esc_html__( 'Nettopreise ohne Steuer (gilt für den Händlerpreis)', 'growin-stock-sync' ) );
		echo '</select></td></tr>';

		$this->text_field( 'price_markup', __( 'Aufschlag in Prozent', 'growin-stock-sync' ), $s['price_markup'], __( 'Wird nach der Steuerumrechnung angewendet. 0 lässt den Preis der Liste unverändert.', 'growin-stock-sync' ) );
		$this->text_field( 'price_tax_fallback', __( 'Steuersatz, wenn die Liste keinen nennt', 'growin-stock-sync' ), $s['price_tax_fallback'] );
		$this->text_field( 'price_change_limit', __( 'Sicherheitsgrenze in Prozent', 'growin-stock-sync' ), $s['price_change_limit'], __( 'Weicht der neue Preis stärker vom bisherigen ab, wird er nicht übernommen, sondern im Protokoll gemeldet. 0 schaltet die Prüfung ab.', 'growin-stock-sync' ), 'number' );

		echo '<tr><th scope="row">' . esc_html__( 'Produkte im Angebot', 'growin-stock-sync' ) . '</th><td><select name="gss[price_on_sale]">';
		foreach ( array(
			'skip'   => __( 'unverändert lassen', 'growin-stock-sync' ),
			'update' => __( 'regulären Preis trotzdem anpassen', 'growin-stock-sync' ),
		) as $key => $label ) {
			printf( '<option value="%s" %s>%s</option>', esc_attr( $key ), selected( $s['price_on_sale'], $key, false ), esc_html( $label ) );
		}
		echo '</select><p class="description">' . esc_html__( 'Angebotspreise werden nie überschrieben.', 'growin-stock-sync' ) . '</p></td></tr>';

		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Weitere Felder und Betrieb', 'growin-stock-sync' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';

		$this->checkbox( 'store_dealer_price', __( 'Händler-Einkaufspreis als Produktfeld mitschreiben', 'growin-stock-sync' ), $s['store_dealer_price'], __( 'Landet im Feld _growin_price_dealer und verändert keine Verkaufspreise.', 'growin-stock-sync' ) );
		$this->checkbox( 'store_weight', __( 'Gewicht aus der Liste als Produktfeld mitschreiben', 'growin-stock-sync' ), $s['store_weight'], __( 'Landet im Feld _growin_weight. Das Versandgewicht des Produkts bleibt unangetastet.', 'growin-stock-sync' ) );
		$this->checkbox( 'dry_run', __( 'Testlauf: alles auswerten, aber nichts schreiben', 'growin-stock-sync' ), $s['dry_run'] );

		$this->text_field( 'time_budget', __( 'Zeitbudget je Lauf (Sekunden)', 'growin-stock-sync' ), $s['time_budget'], __( 'Nach Ablauf bricht der Lauf sauber ab; der Rest folgt beim nächsten Durchgang.', 'growin-stock-sync' ), 'number' );
		$this->text_field( 'notify_email', __( 'E-Mail bei Fehlern', 'growin-stock-sync' ), $s['notify_email'], __( 'Höchstens eine Nachricht pro Tag.', 'growin-stock-sync' ), 'email' );

		echo '</tbody></table>';

		echo '<p class="submit"><button type="submit" name="gss_save_settings" value="1" class="button button-primary">' . esc_html__( 'Einstellungen speichern', 'growin-stock-sync' ) . '</button></p>';
		echo '</form>';
	}

	/**
	 * Reiter "Katalog".
	 *
	 * @return void
	 */
	protected function render_catalog() {
		// phpcs:disable WordPress.Security.NonceVerification
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$brand  = isset( $_GET['brand'] ) ? sanitize_text_field( wp_unslash( $_GET['brand'] ) ) : '';
		$stock  = isset( $_GET['stock'] ) ? sanitize_text_field( wp_unslash( $_GET['stock'] ) ) : '';
		$linked = isset( $_GET['linked'] ) ? sanitize_text_field( wp_unslash( $_GET['linked'] ) ) : '';
		$paged  = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		// phpcs:enable WordPress.Security.NonceVerification

		$per_page = 25;
		$data     = GSS_Catalog::search(
			array(
				'search'   => $search,
				'brand'    => $brand,
				'stock'    => $stock,
				'linked'   => $linked,
				'per_page' => $per_page,
				'page'     => $paged,
			)
		);

		echo '<p>' . esc_html__( 'Der Katalog enthält alle Artikel der Preisliste, auch die, die noch nicht im Shop angelegt sind. Er dient als Nachschlagewerk für künftige Produktanlagen.', 'growin-stock-sync' ) . '</p>';

		echo '<form method="get"><input type="hidden" name="page" value="' . esc_attr( GSS_SLUG ) . '" /><input type="hidden" name="tab" value="catalog" />';
		printf( '<p class="search-box"><input type="search" name="s" value="%s" placeholder="%s" /> ', esc_attr( $search ), esc_attr__( 'Artikelnummer, Name, EAN', 'growin-stock-sync' ) );

		echo '<select name="brand"><option value="">' . esc_html__( 'alle Marken', 'growin-stock-sync' ) . '</option>';
		foreach ( GSS_Catalog::brands() as $entry ) {
			printf( '<option value="%s" %s>%s</option>', esc_attr( $entry ), selected( $brand, $entry, false ), esc_html( $entry ) );
		}
		echo '</select> ';

		echo '<select name="stock"><option value="">' . esc_html__( 'alle Bestandsstufen', 'growin-stock-sync' ) . '</option>';
		foreach ( GSS_Settings::stock_codes() as $code => $label ) {
			printf( '<option value="%d" %s>%s</option>', (int) $code, selected( (string) $stock, (string) $code, false ), esc_html( $code . ' – ' . $label ) );
		}
		echo '</select> ';

		echo '<select name="linked">';
		printf( '<option value="" %s>%s</option>', selected( $linked, '', false ), esc_html__( 'verknüpft und offen', 'growin-stock-sync' ) );
		printf( '<option value="yes" %s>%s</option>', selected( $linked, 'yes', false ), esc_html__( 'nur mit Produkt verknüpft', 'growin-stock-sync' ) );
		printf( '<option value="no" %s>%s</option>', selected( $linked, 'no', false ), esc_html__( 'nur ohne Produkt', 'growin-stock-sync' ) );
		echo '</select> ';

		echo '<button class="button">' . esc_html__( 'Filtern', 'growin-stock-sync' ) . '</button></p></form>';

		printf(
			'<p>%s</p>',
			esc_html(
				sprintf(
					/* translators: %s: number of entries */
					__( '%s Treffer', 'growin-stock-sync' ),
					number_format_i18n( $data['total'] )
				)
			)
		);

		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array(
			__( 'Artikel-Nr.', 'growin-stock-sync' ),
			__( 'Marke', 'growin-stock-sync' ),
			__( 'Bezeichnung', 'growin-stock-sync' ),
			__( 'EAN', 'growin-stock-sync' ),
			__( 'Gewicht', 'growin-stock-sync' ),
			__( 'EK netto', 'growin-stock-sync' ),
			__( 'Bestand', 'growin-stock-sync' ),
			__( 'Produkt', 'growin-stock-sync' ),
		) as $heading ) {
			echo '<th>' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		if ( ! $data['items'] ) {
			echo '<tr><td colspan="8">' . esc_html__( 'Keine Einträge gefunden.', 'growin-stock-sync' ) . '</td></tr>';
		}

		foreach ( $data['items'] as $item ) {
			echo '<tr>';
			echo '<td><code>' . esc_html( $item['article_no'] ) . '</code></td>';
			echo '<td>' . esc_html( $item['brand'] ) . '</td>';
			echo '<td>' . esc_html( $item['name'] ) . '</td>';
			echo '<td>' . esc_html( $item['ean'] ) . '</td>';
			echo '<td>' . esc_html( null !== $item['weight'] ? number_format_i18n( (float) $item['weight'], 3 ) . ' kg' : '' ) . '</td>';
			echo '<td>' . esc_html( null !== $item['price_dealer'] ? number_format_i18n( (float) $item['price_dealer'], 2 ) . ' €' : '' ) . '</td>';
			echo '<td>' . esc_html( $item['stock_label'] ) . '</td>';

			if ( (int) $item['product_id'] > 0 ) {
				printf(
					'<td><a href="%s">#%d</a></td>',
					esc_url( get_edit_post_link( (int) $item['product_id'] ) ? get_edit_post_link( (int) $item['product_id'] ) : '#' ),
					(int) $item['product_id']
				);
			} else {
				echo '<td>—</td>';
			}

			echo '</tr>';
		}

		echo '</tbody></table>';

		$pages = (int) ceil( $data['total'] / $per_page );

		if ( $pages > 1 ) {
			echo '<div class="tablenav"><div class="tablenav-pages">';
			echo wp_kses_post(
				paginate_links(
					array(
						'base'      => add_query_arg( 'paged', '%#%' ),
						'format'    => '',
						'current'   => $paged,
						'total'     => $pages,
						'prev_text' => '&laquo;',
						'next_text' => '&raquo;',
					)
				)
			);
			echo '</div></div>';
		}
	}

	/**
	 * Reiter "Protokoll".
	 *
	 * @return void
	 */
	protected function render_log() {
		$log = GSS_Logger::all();

		$clear_url = wp_nonce_url(
			add_query_arg( array( 'page' => GSS_SLUG, 'gss_action' => 'clear_log' ), admin_url( 'admin.php' ) ),
			'gss_clear_log'
		);

		printf( '<p><a href="%s" class="button">%s</a></p>', esc_url( $clear_url ), esc_html__( 'Protokoll leeren', 'growin-stock-sync' ) );

		if ( ! $log ) {
			echo '<p>' . esc_html__( 'Noch keine Läufe protokolliert.', 'growin-stock-sync' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array(
			__( 'Zeitpunkt', 'growin-stock-sync' ),
			__( 'Auslöser', 'growin-stock-sync' ),
			__( 'Quelle', 'growin-stock-sync' ),
			__( 'Zeilen', 'growin-stock-sync' ),
			__( 'Zugeordnet', 'growin-stock-sync' ),
			__( 'Aktualisiert', 'growin-stock-sync' ),
			__( 'Übersprungen', 'growin-stock-sync' ),
			__( 'Meldungen', 'growin-stock-sync' ),
		) as $heading ) {
			echo '<th>' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		foreach ( $log as $entry ) {
			$messages = array_merge( (array) $entry['errors'], (array) $entry['notes'] );

			echo '<tr>';
			echo '<td>' . esc_html( $entry['time'] ) . '</td>';
			echo '<td>' . esc_html( $entry['trigger'] ) . '</td>';
			echo '<td>' . esc_html( $entry['source'] ) . '</td>';
			echo '<td>' . esc_html( number_format_i18n( $entry['rows'] ) ) . '</td>';
			echo '<td>' . esc_html( number_format_i18n( $entry['matched'] ) ) . '</td>';
			echo '<td>' . esc_html( number_format_i18n( $entry['updated'] ) ) . '</td>';
			echo '<td>' . esc_html( number_format_i18n( $entry['skipped'] ) ) . '</td>';
			echo '<td>' . ( $messages ? esc_html( implode( ' | ', $messages ) ) : '—' ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Kleine Hilfsausgabe für Statuszeilen.
	 *
	 * @param string $label Beschriftung.
	 * @param string $value Wert (bereits maskiert oder unbedenklich).
	 * @return void
	 */
	protected function row( $label, $value ) {
		printf(
			'<tr><th scope="row" style="width:280px">%s</th><td>%s</td></tr>',
			esc_html( $label ),
			wp_kses_post( $value )
		);
	}

	/**
	 * Textfeld im Einstellungsformular.
	 *
	 * @param string $key         Schlüssel.
	 * @param string $label       Beschriftung.
	 * @param string $value       Wert.
	 * @param string $description Hinweis.
	 * @param string $type        Feldtyp.
	 * @return void
	 */
	protected function text_field( $key, $label, $value, $description = '', $type = 'text' ) {
		printf(
			'<tr><th scope="row"><label for="gss_%1$s">%2$s</label></th><td><input type="%3$s" id="gss_%1$s" name="gss[%1$s]" value="%4$s" class="regular-text" />%5$s</td></tr>',
			esc_attr( $key ),
			esc_html( $label ),
			esc_attr( $type ),
			esc_attr( $value ),
			$description ? '<p class="description">' . esc_html( $description ) . '</p>' : ''
		);
	}

	/**
	 * Kontrollkästchen im Einstellungsformular.
	 *
	 * @param string $key         Schlüssel.
	 * @param string $label       Beschriftung.
	 * @param string $value       Aktueller Wert.
	 * @param string $description Hinweis.
	 * @return void
	 */
	protected function checkbox( $key, $label, $value, $description = '' ) {
		printf(
			'<tr><th scope="row">%1$s</th><td><label><input type="checkbox" name="gss[%2$s]" value="yes" %3$s /> %4$s</label>%5$s</td></tr>',
			'&nbsp;',
			esc_attr( $key ),
			checked( $value, 'yes', false ),
			esc_html( $label ),
			$description ? '<p class="description">' . esc_html( $description ) . '</p>' : ''
		);
	}

	/**
	 * Kontrollkästchen im Produkt (Reiter Lagerbestand).
	 *
	 * @return void
	 */
	public function product_field() {
		woocommerce_wp_checkbox(
			array(
				'id'          => GSS_Sync::META_EXCLUDE,
				'value'       => get_post_meta( get_the_ID(), GSS_Sync::META_EXCLUDE, true ),
				'label'       => __( 'Growin-Abgleich aussetzen', 'growin-stock-sync' ),
				'description' => __( 'Der Bestand dieses Produkts wird vom automatischen Growin-Abgleich nicht verändert.', 'growin-stock-sync' ),
			)
		);
	}

	/**
	 * Speichert das Produktfeld.
	 *
	 * @param WC_Product $product Produkt.
	 * @return void
	 */
	public function save_product_field( $product ) {
		// phpcs:ignore WordPress.Security.NonceVerification -- WooCommerce prüft die Nonce bereits.
		$value = isset( $_POST[ GSS_Sync::META_EXCLUDE ] ) ? 'yes' : 'no';

		$product->update_meta_data( GSS_Sync::META_EXCLUDE, $value );
	}
}
