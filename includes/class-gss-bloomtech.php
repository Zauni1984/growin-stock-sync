<?php
/**
 * Erkennt, welche Produkte bereits vom Bloomtech-Sync betreut werden.
 *
 * Bloomtech hat immer Vorrang: Produkte, die dort gepflegt werden, fasst der
 * Growin-Abgleich nicht an. Erkannt wird das ausschließlich über die
 * Artikelnummer: Steht die SKU eines Produkts im Bloomtech-Katalog, gehört es
 * Bloomtech. Einzige Ausnahme ist das Bloomtech-Häkchen „Eigenbestand“
 * (_bloomtech_exclude = yes), dann darf Growin übernehmen.
 *
 * @package GrowinStockSync
 */

defined( 'ABSPATH' ) || exit;

/**
 * Nachschlagen der Bloomtech-Hoheit.
 */
class GSS_Bloomtech {

	/**
	 * Findet die Katalogtabelle des Bloomtech-Plugins.
	 *
	 * @return string Tabellenname oder leerer String.
	 */
	public static function catalog_table() {
		global $wpdb;

		$cached = get_transient( 'gss_bloomtech_table_v2' );

		if ( false !== $cached ) {
			return (string) $cached;
		}

		$found  = '';
		$tables = $wpdb->get_col( "SHOW TABLES LIKE '%bloomtech%'" ); // phpcs:ignore WordPress.DB

		foreach ( (array) $tables as $table ) {
			$columns = $wpdb->get_col( "SHOW COLUMNS FROM `{$table}`" ); // phpcs:ignore WordPress.DB

			if ( self::article_column( (array) $columns ) ) {
				$found = (string) $table;
				break;
			}
		}

		set_transient( 'gss_bloomtech_table_v2', $found, HOUR_IN_SECONDS );

		return $found;
	}

	/**
	 * Sucht die Spalte mit der Artikelnummer.
	 *
	 * @param array $columns Spaltennamen.
	 * @return string
	 */
	public static function article_column( array $columns ) {
		$candidates = array( 'artnr', 'article_no', 'artikelnummer', 'artikel_nr', 'sku', 'article_number', 'articleno' );

		foreach ( $candidates as $candidate ) {
			foreach ( $columns as $column ) {
				if ( strtolower( (string) $column ) === $candidate ) {
					return (string) $column;
				}
			}
		}

		return '';
	}

	/**
	 * Liefert die Produkt-IDs, die dem Bloomtech-Sync gehören.
	 *
	 * @param array<int,int> $product_ids Zu prüfende Produkt-IDs.
	 * @param array<int,string> $skus     Zugehörige SKUs, gleiche Schlüssel wie $product_ids.
	 * @return array<int,bool> Produkt-ID => true.
	 */
	public static function owned( array $product_ids, array $skus = array() ) {
		global $wpdb;

		$owned = array();

		if ( ! $product_ids ) {
			return $owned;
		}

		$product_ids = array_map( 'absint', $product_ids );
		$product_ids = array_values( array_unique( array_filter( $product_ids ) ) );

		// Vom Bloomtech-Abgleich ausdrücklich ausgenommene Produkte darf Growin übernehmen.
		$excluded_from_bloomtech = array();

		foreach ( array_chunk( $product_ids, 500 ) as $chunk ) {
			$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );

			$sql = "SELECT post_id
				FROM {$wpdb->postmeta}
				WHERE post_id IN ({$placeholders})
				AND meta_key = '_bloomtech_exclude'
				AND meta_value = 'yes'";

			$rows = $wpdb->get_col( $wpdb->prepare( $sql, $chunk ) ); // phpcs:ignore WordPress.DB

			foreach ( (array) $rows as $post_id ) {
				$excluded_from_bloomtech[ (int) $post_id ] = true;
			}
		}

		// Bloomtech-Ware: Die SKU steht im Bloomtech-Katalog.
		$table = self::catalog_table();

		if ( '' === $table || ! $skus ) {
			return $owned;
		}

		$columns = $wpdb->get_col( "SHOW COLUMNS FROM `{$table}`" ); // phpcs:ignore WordPress.DB
		$column  = self::article_column( (array) $columns );

		if ( '' === $column ) {
			return $owned;
		}

		$sku_to_id = array();

		foreach ( $skus as $product_id => $sku ) {
			$sku = (string) $sku;

			if ( '' === $sku || isset( $excluded_from_bloomtech[ (int) $product_id ] ) ) {
				continue;
			}

			$sku_to_id[ $sku ][] = (int) $product_id;
		}

		if ( ! $sku_to_id ) {
			return $owned;
		}

		foreach ( array_chunk( array_keys( $sku_to_id ), 500 ) as $chunk ) {
			$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );

			$sql   = "SELECT `{$column}` AS article_no FROM `{$table}` WHERE `{$column}` IN ({$placeholders})";
			$found = $wpdb->get_col( $wpdb->prepare( $sql, $chunk ) ); // phpcs:ignore WordPress.DB

			foreach ( (array) $found as $article_no ) {
				$article_no = (string) $article_no;

				if ( ! isset( $sku_to_id[ $article_no ] ) ) {
					continue;
				}

				foreach ( $sku_to_id[ $article_no ] as $product_id ) {
					$owned[ $product_id ] = true;
				}
			}
		}

		return $owned;
	}

	/**
	 * Beschreibt für den Adminbereich, worauf die Erkennung fußt.
	 *
	 * @return string
	 */
	public static function describe() {
		global $wpdb;

		$parts = array();
		$table = self::catalog_table();

		if ( '' !== $table ) {
			$count   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" ); // phpcs:ignore WordPress.DB
			$parts[] = sprintf(
				/* translators: 1: table name, 2: number of entries */
				__( 'Bloomtech-Katalog %1$s mit %2$s Artikelnummern erkannt.', 'growin-stock-sync' ),
				$table,
				number_format_i18n( $count )
			);
		} else {
			$parts[] = __( 'Keine Bloomtech-Katalogtabelle gefunden.', 'growin-stock-sync' );
		}

		$meta_count = (int) $wpdb->get_var(
			"SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = '_bloomtech_exclude' AND meta_value = 'yes'" // phpcs:ignore WordPress.DB
		);

		$parts[] = sprintf(
			/* translators: %s: number of products */
			__( '%s Produkte sind vom Bloomtech-Abgleich ausgenommen und dürfen von Growin übernommen werden.', 'growin-stock-sync' ),
			number_format_i18n( $meta_count )
		);

		return implode( ' ', $parts );
	}

	/**
	 * Vergisst die zwischengespeicherte Tabellenerkennung.
	 *
	 * @return void
	 */
	public static function flush() {
		delete_transient( 'gss_bloomtech_table_v2' );
	}
}
