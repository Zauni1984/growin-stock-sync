<?php
/**
 * Abgleich der Preisliste mit den WooCommerce-Produkten.
 *
 * @package GrowinStockSync
 */

defined( 'ABSPATH' ) || exit;

/**
 * Führt den eigentlichen Bestandsabgleich durch.
 */
class GSS_Sync {

	const META_EXCLUDE = '_growin_exclude';
	const META_ARTICLE = '_growin_article_no';
	const META_PRICE   = '_growin_price_dealer';
	const META_WEIGHT  = '_growin_weight';
	const META_SYNCED  = '_growin_last_sync';

	/**
	 * Führt einen kompletten Lauf aus.
	 *
	 * @param string $trigger Auslöser (cron, manual, upload).
	 * @return array Ergebnisdaten.
	 */
	public static function run( $trigger = 'cron' ) {
		$started  = microtime( true );
		$settings = GSS_Settings::all();
		$errors   = array();
		$notes    = array();

		$result = array(
			'time'     => current_time( 'mysql' ),
			'trigger'  => $trigger,
			'source'   => '',
			'rows'     => 0,
			'matched'  => 0,
			'updated'  => 0,
			'skipped'  => 0,
			'unknown'  => 0,
			'dry_run'  => ( 'yes' === $settings['dry_run'] ),
			'duration' => 0,
			'errors'   => array(),
			'notes'    => array(),
		);

		// 1. Liste beschaffen.
		if ( 'auto' === $settings['source_mode'] ) {
			$remote = GSS_Source::fetch_remote();

			if ( $remote['ok'] ) {
				GSS_Source::store( $remote['content'] );
				$content         = $remote['content'];
				$result['source'] = 'Abruf aus dem Kundenbereich';
			} else {
				$errors[] = 'Automatischer Abruf fehlgeschlagen: ' . $remote['error'];
				$notes    = array_merge( $notes, $remote['steps'] );

				$content = GSS_Source::read_stored();

				if ( is_wp_error( $content ) ) {
					$result['errors']   = array_merge( $errors, array( $content->get_error_message() ) );
					$result['notes']    = $notes;
					$result['duration'] = round( microtime( true ) - $started, 1 );
					GSS_Logger::add( $result );

					return $result;
				}

				$notes[]          = 'Es wurde ersatzweise die zuletzt gespeicherte Liste verwendet.';
				$result['source'] = 'zuletzt gespeicherte Datei';
			}
		} else {
			$content = GSS_Source::read_stored();

			if ( is_wp_error( $content ) ) {
				$result['errors']   = array( $content->get_error_message() );
				$result['duration'] = round( microtime( true ) - $started, 1 );
				GSS_Logger::add( $result );

				return $result;
			}

			$result['source'] = 'hochgeladene Datei';
		}

		// 2. Datei auswerten.
		$parsed = GSS_Parser::parse( $content );

		if ( ! $parsed['ok'] ) {
			$result['errors']   = array_merge( $errors, array( $parsed['error'] ) );
			$result['notes']    = $notes;
			$result['duration'] = round( microtime( true ) - $started, 1 );
			GSS_Logger::add( $result );

			return $result;
		}

		$rows           = $parsed['rows'];
		$result['rows'] = count( $rows );

		if ( ! empty( $parsed['duplicates'] ) ) {
			$notes[] = sprintf(
				'%d Zeile(n) der Liste hatten eine bereits vorhandene Artikelnummer und wurden zusammengefasst.',
				(int) $parsed['duplicates']
			);
		}

		// 3. Katalog fortschreiben.
		GSS_Catalog::upsert( $rows );

		// 4. Produkte zuordnen.
		$matches = self::match_products( $rows );

		$result['matched'] = count( $matches );

		if ( $matches ) {
			GSS_Catalog::link_products( wp_list_pluck( $matches, 'product_id' ) );
		}

		// 5. Bestände schreiben.
		$apply = self::apply( $matches, $rows, $settings, $started );

		$result['updated'] = $apply['updated'];
		$result['skipped'] = $apply['skipped'];
		$result['unknown'] = $apply['unknown'];
		$notes             = array_merge( $notes, $apply['notes'] );
		$errors            = array_merge( $errors, $apply['errors'] );

		$result['errors']   = $errors;
		$result['notes']    = $notes;
		$result['duration'] = round( microtime( true ) - $started, 1 );

		update_option( 'gss_last_run', $result['time'], false );

		GSS_Logger::add( $result );

		return $result;
	}

	/**
	 * Sucht zu den Artikelnummern die passenden Produkte und Varianten.
	 *
	 * Verglichen wird ausschließlich die Growin-Artikelnummer (mit optionalem
	 * Präfix) gegen die SKU. EAN, Name oder andere Felder spielen keine Rolle.
	 *
	 * @param array $rows Geparste Zeilen.
	 * @return array<string,array{product_id:int,via:string}>
	 */
	public static function match_products( array $rows ) {
		global $wpdb;

		$settings = GSS_Settings::all();
		$prefix   = (string) $settings['sku_prefix'];
		$matches  = array();

		// Artikelnummer -> erwartete SKU.
		$sku_to_article = array();

		foreach ( $rows as $article_no => $row ) {
			$sku                    = $prefix . $article_no;
			$sku_to_article[ $sku ] = $article_no;
		}

		$skus = array_keys( $sku_to_article );

		foreach ( array_chunk( $skus, 500 ) as $chunk ) {
			$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );

			$sql = "SELECT pm.meta_value AS sku, pm.post_id AS product_id
				FROM {$wpdb->postmeta} pm
				INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				WHERE pm.meta_key = '_sku'
				AND pm.meta_value IN ({$placeholders})
				AND p.post_type IN ('product','product_variation')
				AND p.post_status NOT IN ('trash','auto-draft')";

			$found = $wpdb->get_results( $wpdb->prepare( $sql, $chunk ), ARRAY_A ); // phpcs:ignore WordPress.DB

			foreach ( (array) $found as $entry ) {
				$sku = (string) $entry['sku'];

				if ( ! isset( $sku_to_article[ $sku ] ) ) {
					continue;
				}

				$article_no = $sku_to_article[ $sku ];

				if ( isset( $matches[ $article_no ] ) ) {
					continue;
				}

				$matches[ $article_no ] = array(
					'product_id' => (int) $entry['product_id'],
					'via'        => 'sku',
					'sku'        => $sku,
				);
			}
		}

		return $matches;
	}

	/**
	 * Schreibt die Bestände in die Produkte.
	 *
	 * @param array $matches  Zuordnungen.
	 * @param array $rows     Geparste Zeilen.
	 * @param array $settings Einstellungen.
	 * @param float $started  Startzeit des Laufs.
	 * @return array
	 */
	protected static function apply( array $matches, array $rows, array $settings, $started ) {
		$out = array(
			'updated' => 0,
			'skipped' => 0,
			'unknown' => 0,
			'notes'   => array(),
			'errors'  => array(),
		);

		$dry_run       = ( 'yes' === $settings['dry_run'] );
		$budget        = (int) $settings['time_budget'];
		$managed_skips = 0;
		$excluded      = 0;
		$discontinued  = array();
		$processed     = 0;
		$price_changes = 0;
		$price_blocked = array();

		// Bloomtech hat Vorrang: alles, was dort gepflegt wird, bleibt unangetastet.
		$owned_by_bloomtech = array();

		if ( 'bloomtech' === $settings['precedence'] ) {
			$ids  = array();
			$skus = array();

			foreach ( $matches as $match ) {
				$ids[] = (int) $match['product_id'];

				if ( ! empty( $match['sku'] ) ) {
					$skus[ (int) $match['product_id'] ] = (string) $match['sku'];
				}
			}

			$owned_by_bloomtech = GSS_Bloomtech::owned( $ids, $skus );

			if ( $owned_by_bloomtech ) {
				$out['notes'][] = sprintf(
					'%d Produkt(e) werden vom Bloomtech-Abgleich betreut und daher übersprungen (Bloomtech hat Vorrang).',
					count( $owned_by_bloomtech )
				);
			}
		}

		foreach ( $matches as $article_no => $match ) {
			if ( ( microtime( true ) - $started ) > $budget ) {
				$out['notes'][] = sprintf(
					'Zeitbudget von %d Sekunden erreicht – %d von %d Zuordnungen verarbeitet. Der Rest folgt beim nächsten Lauf.',
					$budget,
					$processed,
					count( $matches )
				);
				break;
			}

			++$processed;
			$row = isset( $rows[ $article_no ] ) ? $rows[ $article_no ] : null;

			if ( ! $row ) {
				continue;
			}

			$code = (int) $row['stock_code'];

			if ( $code < 1 || $code > 5 ) {
				++$out['unknown'];
				continue;
			}

			if ( isset( $owned_by_bloomtech[ (int) $match['product_id'] ] ) ) {
				++$out['skipped'];
				continue;
			}

			$product = wc_get_product( $match['product_id'] );

			if ( ! $product ) {
				++$out['skipped'];
				continue;
			}

			if ( 'yes' === $product->get_meta( self::META_EXCLUDE, true ) ) {
				++$excluded;
				++$out['skipped'];
				continue;
			}

			$is_discontinued = ( false !== stripos( (string) $row['status'], 'auslaufend' ) );

			if ( $is_discontinued ) {
				$discontinued[] = sprintf( '%s (%s)', $row['name'], $article_no );
			}

			$target_status    = $settings['status_map'][ $code ];
			$target_backorder = $settings['backorder_map'][ $code ];
			$target_quantity  = (int) $settings['quantity_map'][ $code ];

			if ( $is_discontinued && 'outofstock' === $settings['discontinued'] ) {
				$target_status    = 'outofstock';
				$target_backorder = 'no';
				$target_quantity  = 0;
			}

			$changed = false;

			if ( $product->get_manage_stock() ) {
				if ( 'yes' !== $settings['set_quantities'] ) {
					++$managed_skips;
					++$out['skipped'];
					continue;
				}

				if ( (int) $product->get_stock_quantity() !== $target_quantity ) {
					$product->set_stock_quantity( $target_quantity );
					$changed = true;
				}

				if ( $product->get_backorders() !== $target_backorder ) {
					$product->set_backorders( $target_backorder );
					$changed = true;
				}
			}

			if ( $product->get_stock_status() !== $target_status ) {
				$product->set_stock_status( $target_status );
				$changed = true;
			}

			// Verkaufspreis, sofern eingeschaltet.
			if ( 'on' === $settings['sync_price'] ) {
				$price = self::price_for_row( $row, $settings );

				if ( null === $price ) {
					$price_blocked['no_price'] = ( $price_blocked['no_price'] ?? 0 ) + 1;
				} elseif ( '' !== (string) $product->get_sale_price() && 'skip' === $settings['price_on_sale'] ) {
					$price_blocked['on_sale'] = ( $price_blocked['on_sale'] ?? 0 ) + 1;
				} else {
					$current = (float) $product->get_regular_price();
					$limit   = (float) $settings['price_change_limit'];

					if ( $current > 0 && $limit > 0 && abs( $price - $current ) / $current * 100 > $limit ) {
						$price_blocked['limit'] = ( $price_blocked['limit'] ?? 0 ) + 1;
					} elseif ( abs( $price - $current ) >= 0.01 ) {
						$product->set_regular_price( (string) $price );

						if ( '' === (string) $product->get_sale_price() ) {
							$product->set_price( (string) $price );
						}

						++$price_changes;
						$changed = true;
					}
				}
			}

			// Referenzdaten mitschreiben (verändern keine Preise oder Versanddaten).
			$meta_changes = array(
				self::META_ARTICLE => (string) $article_no,
				self::META_SYNCED  => current_time( 'mysql' ),
			);

			if ( 'yes' === $settings['store_dealer_price'] && null !== $row['price_dealer'] ) {
				$meta_changes[ self::META_PRICE ] = (string) $row['price_dealer'];
			}

			if ( 'yes' === $settings['store_weight'] && null !== $row['weight'] ) {
				$meta_changes[ self::META_WEIGHT ] = (string) $row['weight'];
			}

			if ( $dry_run ) {
				if ( $changed ) {
					++$out['updated'];
				}

				continue;
			}

			foreach ( $meta_changes as $key => $value ) {
				$product->update_meta_data( $key, $value );
			}

			try {
				$product->save();

				if ( $changed ) {
					++$out['updated'];
				}
			} catch ( Exception $e ) {
				$out['errors'][] = sprintf( 'Produkt %d (%s): %s', $match['product_id'], $article_no, $e->getMessage() );
			}
		}

		if ( $excluded ) {
			$out['notes'][] = sprintf( '%d Produkt(e) sind vom Abgleich ausgenommen.', $excluded );
		}

		if ( $managed_skips ) {
			$out['notes'][] = sprintf(
				'%d Produkt(e) führen eine eigene Bestandsverwaltung. Da "Mengen setzen" ausgeschaltet ist, wurden sie nicht verändert.',
				$managed_skips
			);
		}

		if ( $discontinued && 'ignore' !== $settings['discontinued'] ) {
			$out['notes'][] = 'Auslaufende Artikel: ' . implode( ', ', array_slice( $discontinued, 0, 15 ) )
				. ( count( $discontinued ) > 15 ? sprintf( ' … und %d weitere', count( $discontinued ) - 15 ) : '' );
		}

		if ( 'on' === $settings['sync_price'] ) {
			$out['notes'][] = sprintf( '%d Verkaufspreis(e) angepasst.', $price_changes );

			if ( ! empty( $price_blocked['limit'] ) ) {
				$out['notes'][] = sprintf(
					'%d Preis(e) wurden nicht übernommen, weil die Abweichung über der Sicherheitsgrenze von %d Prozent lag.',
					(int) $price_blocked['limit'],
					(int) $settings['price_change_limit']
				);
			}

			if ( ! empty( $price_blocked['on_sale'] ) ) {
				$out['notes'][] = sprintf( '%d Produkt(e) haben einen Angebotspreis und blieben preislich unverändert.', (int) $price_blocked['on_sale'] );
			}

			if ( ! empty( $price_blocked['no_price'] ) ) {
				$out['notes'][] = sprintf( '%d Artikel hatten keinen verwertbaren Preis in der Liste.', (int) $price_blocked['no_price'] );
			}
		}

		if ( $dry_run ) {
			$out['notes'][] = 'Testlauf: Es wurden keine Daten geschrieben.';
		}

		return $out;
	}

	/**
	 * Ermittelt den Verkaufspreis für eine Zeile in der Schreibweise des Shops.
	 *
	 * Growin liefert den Endkundenpreis als Bruttopreis und den Händlerpreis netto.
	 * Der Shop speichert je nach WooCommerce-Einstellung netto oder brutto; es wird
	 * auf die im Shop gültige Schreibweise umgerechnet.
	 *
	 * @param array $row      Zeile der Preisliste.
	 * @param array $settings Einstellungen.
	 * @return float|null Preis oder null, wenn keiner ermittelbar ist.
	 */
	public static function price_for_row( array $row, array $settings ) {
		$value = ( 'dealer' === $settings['price_source'] ) ? $row['price_dealer'] : $row['price_retail'];

		if ( null === $value || $value <= 0 ) {
			return null;
		}

		$rate = ( isset( $row['tax_rate'] ) && $row['tax_rate'] > 0 )
			? (float) $row['tax_rate']
			: (float) $settings['price_tax_fallback'];

		$source_is_gross = ( 'yes' === $settings['price_is_gross'] );
		$shop_is_gross   = function_exists( 'wc_prices_include_tax' ) ? wc_prices_include_tax() : false;

		if ( $source_is_gross && ! $shop_is_gross ) {
			$value = $value / ( 1 + ( $rate / 100 ) );
		} elseif ( ! $source_is_gross && $shop_is_gross ) {
			$value = $value * ( 1 + ( $rate / 100 ) );
		}

		$markup = (float) $settings['price_markup'];

		if ( 0.0 !== $markup ) {
			$value = $value * ( 1 + ( $markup / 100 ) );
		}

		$decimals = function_exists( 'wc_get_price_decimals' ) ? (int) wc_get_price_decimals() : 2;

		return round( $value, $decimals );
	}
}
