<?php
/**
 * Vollständiger Katalog aller Growin-Artikelnummern.
 *
 * @package GrowinStockSync
 */

defined( 'ABSPATH' ) || exit;

/**
 * Verwaltet die Katalogtabelle.
 */
class GSS_Catalog {

	/**
	 * Tabellenname inklusive Präfix.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;

		return $wpdb->prefix . 'growin_catalog';
	}

	/**
	 * Legt die Tabelle an oder aktualisiert sie.
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			article_no varchar(64) NOT NULL,
			brand varchar(120) NOT NULL DEFAULT '',
			name text NOT NULL,
			ean varchar(32) NOT NULL DEFAULT '',
			status varchar(32) NOT NULL DEFAULT '',
			stock_code tinyint(1) NOT NULL DEFAULT 0,
			stock_label varchar(64) NOT NULL DEFAULT '',
			weight decimal(12,4) DEFAULT NULL,
			height decimal(12,2) DEFAULT NULL,
			width decimal(12,2) DEFAULT NULL,
			depth decimal(12,2) DEFAULT NULL,
			unit varchar(24) NOT NULL DEFAULT '',
			packing_unit int(11) NOT NULL DEFAULT 0,
			price_retail decimal(12,2) DEFAULT NULL,
			price_dealer decimal(12,2) DEFAULT NULL,
			comment text NOT NULL,
			product_id bigint(20) unsigned NOT NULL DEFAULT 0,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (article_no),
			KEY ean (ean),
			KEY stock_code (stock_code),
			KEY brand (brand),
			KEY product_id (product_id)
		) {$collate};";

		dbDelta( $sql );
	}

	/**
	 * Schreibt Zeilen in den Katalog (Insert oder Update).
	 *
	 * @param array $rows Geparste Zeilen.
	 * @return int Anzahl geschriebener Zeilen.
	 */
	public static function upsert( array $rows ) {
		global $wpdb;

		if ( ! $rows ) {
			return 0;
		}

		$table   = self::table();
		$now     = current_time( 'mysql' );
		$written = 0;

		foreach ( array_chunk( $rows, 200 ) as $chunk ) {
			$values       = array();
			$placeholders = array();

			foreach ( $chunk as $row ) {
				$placeholders[] = '(%s,%s,%s,%s,%s,%d,%s,%s,%s,%s,%s,%s,%d,%s,%s,%s,%s)';

				array_push(
					$values,
					$row['article_no'],
					mb_substr( (string) $row['brand'], 0, 120 ),
					(string) $row['name'],
					mb_substr( (string) $row['ean'], 0, 32 ),
					mb_substr( (string) $row['status'], 0, 32 ),
					(int) $row['stock_code'],
					mb_substr( (string) $row['stock_label'], 0, 64 ),
					self::nullable( $row['weight'] ),
					self::nullable( $row['height'] ),
					self::nullable( $row['width'] ),
					self::nullable( $row['depth'] ),
					mb_substr( (string) $row['unit'], 0, 24 ),
					(int) $row['packing_unit'],
					self::nullable( $row['price_retail'] ),
					self::nullable( $row['price_dealer'] ),
					(string) $row['comment'],
					$now
				);
			}

			$sql = "INSERT INTO {$table}
				(article_no, brand, name, ean, status, stock_code, stock_label, weight, height, width, depth, unit, packing_unit, price_retail, price_dealer, comment, updated_at)
				VALUES " . implode( ',', $placeholders ) . '
				ON DUPLICATE KEY UPDATE
					brand = VALUES(brand),
					name = VALUES(name),
					ean = VALUES(ean),
					status = VALUES(status),
					stock_code = VALUES(stock_code),
					stock_label = VALUES(stock_label),
					weight = VALUES(weight),
					height = VALUES(height),
					width = VALUES(width),
					depth = VALUES(depth),
					unit = VALUES(unit),
					packing_unit = VALUES(packing_unit),
					price_retail = VALUES(price_retail),
					price_dealer = VALUES(price_dealer),
					comment = VALUES(comment),
					updated_at = VALUES(updated_at)';

			$result = $wpdb->query( $wpdb->prepare( $sql, $values ) ); // phpcs:ignore WordPress.DB

			if ( false !== $result ) {
				$written += count( $chunk );
			}
		}

		return $written;
	}

	/**
	 * Wandelt leere Zahlen in NULL-taugliche Strings.
	 *
	 * @param mixed $value Wert.
	 * @return string|null
	 */
	protected static function nullable( $value ) {
		if ( null === $value || '' === $value ) {
			return null;
		}

		return (string) $value;
	}

	/**
	 * Hinterlegt die gefundene Produkt-ID zu einer Artikelnummer.
	 *
	 * @param array<string,int> $pairs Artikelnummer => Produkt-ID.
	 * @return void
	 */
	public static function link_products( array $pairs ) {
		global $wpdb;

		if ( ! $pairs ) {
			return;
		}

		$table = self::table();

		foreach ( array_chunk( $pairs, 200, true ) as $chunk ) {
			foreach ( $chunk as $article_no => $product_id ) {
				$wpdb->update( // phpcs:ignore WordPress.DB
					$table,
					array( 'product_id' => (int) $product_id ),
					array( 'article_no' => (string) $article_no ),
					array( '%d' ),
					array( '%s' )
				);
			}
		}
	}

	/**
	 * Setzt alle Produktverknüpfungen zurück.
	 *
	 * @return void
	 */
	public static function reset_links() {
		global $wpdb;

		$table = self::table();
		$wpdb->query( "UPDATE {$table} SET product_id = 0 WHERE product_id <> 0" ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Anzahl der Katalogeinträge.
	 *
	 * @return int
	 */
	public static function count() {
		global $wpdb;

		$table = self::table();

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Anzahl der verknüpften Artikel.
	 *
	 * @return int
	 */
	public static function linked_count() {
		global $wpdb;

		$table = self::table();

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE product_id <> 0" ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Sucht im Katalog.
	 *
	 * @param array $args Suchparameter.
	 * @return array{items:array,total:int}
	 */
	public static function search( array $args = array() ) {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'search'   => '',
				'brand'    => '',
				'stock'    => '',
				'linked'   => '',
				'per_page' => 25,
				'page'     => 1,
			)
		);

		$table  = self::table();
		$where  = array( '1=1' );
		$params = array();

		if ( '' !== $args['search'] ) {
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[]  = '(article_no LIKE %s OR name LIKE %s OR ean LIKE %s OR brand LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		if ( '' !== $args['brand'] ) {
			$where[]  = 'brand = %s';
			$params[] = $args['brand'];
		}

		if ( '' !== $args['stock'] ) {
			$where[]  = 'stock_code = %d';
			$params[] = (int) $args['stock'];
		}

		if ( 'yes' === $args['linked'] ) {
			$where[] = 'product_id <> 0';
		} elseif ( 'no' === $args['linked'] ) {
			$where[] = 'product_id = 0';
		}

		$clause   = implode( ' AND ', $where );
		$per_page = max( 1, min( 200, (int) $args['per_page'] ) );
		$offset   = max( 0, ( (int) $args['page'] - 1 ) * $per_page );

		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$clause}";
		$total     = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : $wpdb->get_var( $count_sql ) ); // phpcs:ignore WordPress.DB

		$list_sql    = "SELECT * FROM {$table} WHERE {$clause} ORDER BY article_no ASC LIMIT %d OFFSET %d";
		$list_params = array_merge( $params, array( $per_page, $offset ) );
		$items       = $wpdb->get_results( $wpdb->prepare( $list_sql, $list_params ), ARRAY_A ); // phpcs:ignore WordPress.DB

		return array(
			'items' => is_array( $items ) ? $items : array(),
			'total' => $total,
		);
	}

	/**
	 * Alle im Katalog vertretenen Marken.
	 *
	 * @return array<int,string>
	 */
	public static function brands() {
		global $wpdb;

		$table = self::table();
		$rows  = $wpdb->get_col( "SELECT DISTINCT brand FROM {$table} WHERE brand <> '' ORDER BY brand ASC" ); // phpcs:ignore WordPress.DB

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Löscht den Katalog vollständig.
	 *
	 * @return void
	 */
	public static function truncate() {
		global $wpdb;

		$table = self::table();
		$wpdb->query( "TRUNCATE TABLE {$table}" ); // phpcs:ignore WordPress.DB
	}
}
