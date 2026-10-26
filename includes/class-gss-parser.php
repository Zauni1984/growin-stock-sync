<?php
/**
 * Liest die Growin-Preisliste (CSV) ein.
 *
 * @package GrowinStockSync
 */

defined( 'ABSPATH' ) || exit;

/**
 * Wandelt den CSV-Inhalt in normalisierte Zeilen um.
 */
class GSS_Parser {

	/**
	 * Zuordnung normalisierter Spaltennamen auf interne Feldnamen.
	 *
	 * @return array<string,string>
	 */
	protected static function column_map() {
		return array(
			'artikelnr'            => 'article_no',
			'artikelnummer'        => 'article_no',
			'marke'                => 'brand',
			'bezeichnung'          => 'name',
			'ean'                  => 'ean',
			'status'               => 'status',
			'sperrgut'             => 'bulky',
			'palletenartikel'      => 'pallet',
			'palettenartikel'      => 'pallet',
			'nichtfuerendkunden'   => 'b2b_only',
			'gewichtkg'            => 'weight',
			'hoehecm'              => 'height',
			'breitecm'             => 'width',
			'tiefecm'              => 'depth',
			'nachfolger'           => 'successor',
			'alternativen'         => 'alternatives',
			'einheit'              => 'unit',
			've'                   => 'packing_unit',
			'mwst'                 => 'tax_rate',
			'endkundenpreis'       => 'price_retail',
			'haendlerpreis'        => 'price_dealer',
			'endkundensonderpreis' => 'price_retail_special',
			'haendlersonderpreis'  => 'price_dealer_special',
			'bestand'              => 'stock_label',
			'kommentar'            => 'comment',
		);
	}

	/**
	 * Normalisiert einen Spaltenkopf für den Abgleich.
	 *
	 * @param string $header Rohwert.
	 * @return string
	 */
	protected static function normalize_header( $header ) {
		$header = self::to_utf8( (string) $header );
		$header = strtolower( $header );
		$header = strtr(
			$header,
			array(
				'ä' => 'ae',
				'ö' => 'oe',
				'ü' => 'ue',
				'ß' => 'ss',
				'€' => '',
			)
		);

		return preg_replace( '/[^a-z0-9]/', '', $header );
	}

	/**
	 * Stellt UTF-8 sicher (die Liste kommt als Windows-1252).
	 *
	 * @param string $text Eingabe.
	 * @return string
	 */
	public static function to_utf8( $text ) {
		$text = (string) $text;

		if ( '' === $text ) {
			return '';
		}

		if ( function_exists( 'mb_check_encoding' ) && mb_check_encoding( $text, 'UTF-8' ) ) {
			return $text;
		}

		if ( function_exists( 'mb_convert_encoding' ) ) {
			return mb_convert_encoding( $text, 'UTF-8', 'Windows-1252' );
		}

		if ( function_exists( 'iconv' ) ) {
			$converted = @iconv( 'Windows-1252', 'UTF-8//TRANSLIT', $text );

			if ( false !== $converted ) {
				return $converted;
			}
		}

		return $text;
	}

	/**
	 * Wandelt eine deutsche Dezimalzahl in float.
	 *
	 * @param string $value Rohwert.
	 * @return float|null
	 */
	public static function to_float( $value ) {
		$value = trim( (string) $value );

		if ( '' === $value ) {
			return null;
		}

		$value = str_replace( array( ' ', "\xc2\xa0" ), '', $value );

		// 1.234,56 -> 1234.56 ; 1234,56 -> 1234.56 ; 1234.56 bleibt.
		if ( false !== strpos( $value, ',' ) ) {
			$value = str_replace( '.', '', $value );
			$value = str_replace( ',', '.', $value );
		}

		if ( ! is_numeric( $value ) ) {
			return null;
		}

		return (float) $value;
	}

	/**
	 * Liest den Bestand-Code aus dem Text ("2 - geringer Bestand" -> 2).
	 *
	 * @param string $label Rohwert.
	 * @return int 0 wenn unbekannt.
	 */
	public static function stock_code( $label ) {
		if ( preg_match( '/^\s*([1-9])\b/', (string) $label, $matches ) ) {
			return (int) $matches[1];
		}

		$label = strtolower( self::to_utf8( (string) $label ) );

		$fallback = array(
			'lieferbar'       => 1,
			'geringer'        => 2,
			'zulauf'          => 3,
			'nicht auf lager' => 4,
			'bestellware'     => 5,
		);

		foreach ( $fallback as $needle => $code ) {
			if ( false !== strpos( $label, $needle ) ) {
				return $code;
			}
		}

		return 0;
	}

	/**
	 * Parst den kompletten CSV-Inhalt.
	 *
	 * @param string $content Rohinhalt der Datei.
	 * @return array{ok:bool,rows:array,error:string,columns:array,duplicates:int}
	 */
	public static function parse( $content ) {
		$result = array(
			'ok'         => false,
			'rows'       => array(),
			'error'      => '',
			'columns'    => array(),
			'duplicates' => 0,
		);

		$content = (string) $content;

		if ( '' === trim( $content ) ) {
			$result['error'] = __( 'Die Datei ist leer.', 'growin-stock-sync' );
			return $result;
		}

		// BOM entfernen.
		$content = preg_replace( '/^\xEF\xBB\xBF/', '', $content );

		if ( preg_match( '/^\s*<(!doctype|html|\?xml)/i', $content ) ) {
			$result['error'] = __( 'Es kam HTML statt einer CSV-Datei zurück. Vermutlich war der Login nicht erfolgreich.', 'growin-stock-sync' );
			return $result;
		}

		$handle = fopen( 'php://temp', 'r+' );

		if ( ! $handle ) {
			$result['error'] = __( 'Temporärer Speicher konnte nicht geöffnet werden.', 'growin-stock-sync' );
			return $result;
		}

		fwrite( $handle, $content );
		rewind( $handle );

		$delimiter = self::detect_delimiter( $content );
		$header    = fgetcsv( $handle, 0, $delimiter, '"' );

		if ( ! $header || count( $header ) < 5 ) {
			fclose( $handle );
			$result['error'] = __( 'Die Kopfzeile der Datei konnte nicht gelesen werden.', 'growin-stock-sync' );
			return $result;
		}

		$map     = self::column_map();
		$indexes = array();

		foreach ( $header as $index => $label ) {
			$key = self::normalize_header( $label );

			if ( isset( $map[ $key ] ) && ! isset( $indexes[ $map[ $key ] ] ) ) {
				$indexes[ $map[ $key ] ] = $index;
			}

			$result['columns'][] = self::to_utf8( (string) $label );
		}

		if ( ! isset( $indexes['article_no'] ) || ! isset( $indexes['stock_label'] ) ) {
			fclose( $handle );
			$result['error'] = __( 'In der Datei fehlen die Spalten "Artikel-Nr." und/oder "Bestand".', 'growin-stock-sync' );
			return $result;
		}

		$rows = array();

		while ( false !== ( $data = fgetcsv( $handle, 0, $delimiter, '"' ) ) ) {
			if ( ! is_array( $data ) || ( 1 === count( $data ) && ( null === $data[0] || '' === trim( (string) $data[0] ) ) ) ) {
				continue;
			}

			$article_no = isset( $data[ $indexes['article_no'] ] ) ? trim( self::to_utf8( $data[ $indexes['article_no'] ] ) ) : '';

			if ( '' === $article_no ) {
				continue;
			}

			$get = static function ( $field ) use ( $data, $indexes ) {
				if ( ! isset( $indexes[ $field ] ) || ! isset( $data[ $indexes[ $field ] ] ) ) {
					return '';
				}

				return trim( self::to_utf8( $data[ $indexes[ $field ] ] ) );
			};

			$stock_label = $get( 'stock_label' );

			if ( isset( $rows[ $article_no ] ) ) {
				++$result['duplicates'];
			}

			$rows[ $article_no ] = array(
				'article_no'   => $article_no,
				'brand'        => $get( 'brand' ),
				'name'         => $get( 'name' ),
				'ean'          => preg_replace( '/[^0-9]/', '', $get( 'ean' ) ),
				'status'       => $get( 'status' ),
				'bulky'        => $get( 'bulky' ),
				'pallet'       => $get( 'pallet' ),
				'b2b_only'     => $get( 'b2b_only' ),
				'weight'       => self::to_float( $get( 'weight' ) ),
				'height'       => self::to_float( $get( 'height' ) ),
				'width'        => self::to_float( $get( 'width' ) ),
				'depth'        => self::to_float( $get( 'depth' ) ),
				'unit'         => $get( 'unit' ),
				'packing_unit' => (int) self::to_float( $get( 'packing_unit' ) ),
				'price_retail' => self::to_float( $get( 'price_retail' ) ),
				'price_dealer' => self::to_float( $get( 'price_dealer' ) ),
				'tax_rate'     => self::to_float( $get( 'tax_rate' ) ),
				'stock_label'  => $stock_label,
				'stock_code'   => self::stock_code( $stock_label ),
				'comment'      => $get( 'comment' ),
			);
		}

		fclose( $handle );

		if ( ! $rows ) {
			$result['error'] = __( 'Die Datei enthält keine auswertbaren Zeilen.', 'growin-stock-sync' );
			return $result;
		}

		$result['ok']   = true;
		$result['rows'] = $rows;

		return $result;
	}

	/**
	 * Erkennt das Trennzeichen anhand der Kopfzeile.
	 *
	 * @param string $content Rohinhalt.
	 * @return string
	 */
	protected static function detect_delimiter( $content ) {
		$first = strtok( $content, "\r\n" );

		if ( false === $first ) {
			return ';';
		}

		$counts = array(
			';'    => substr_count( $first, ';' ),
			','    => substr_count( $first, ',' ),
			"\t"   => substr_count( $first, "\t" ),
		);

		arsort( $counts );
		$delimiter = key( $counts );

		return $counts[ $delimiter ] > 0 ? $delimiter : ';';
	}
}
