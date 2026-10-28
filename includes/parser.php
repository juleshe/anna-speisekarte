<?php
/**
 * CSV (Google Sheet) -> Tafeln
 * Reine Funktionen ohne WordPress-Abhängigkeit (auch per CLI testbar).
 */
if ( ! defined( 'ABSPATH' ) && PHP_SAPI !== 'cli' ) {
	exit;
}

/** Preis vereinheitlichen: "11.5" / "11,5" / "11,50 €" -> "11,50"; Text bleibt Text. */
function anna_sk_price( $v ) {
	$v = trim( (string) $v );
	if ( $v === '' ) {
		return '';
	}
	$n = str_replace( array( '€', 'EUR', ' ', "\xc2\xa0" ), '', $v );
	if ( preg_match( '/^\d{1,4}([.,]\d{1,2})?$/', $n ) ) {
		return number_format( (float) str_replace( ',', '.', $n ), 2, ',', '' );
	}
	return $v;
}

function anna_sk_is_price( $v ) {
	$n = str_replace( array( '€', 'EUR', ' ', "\xc2\xa0" ), '', trim( (string) $v ) );
	return (bool) preg_match( '/^\d{1,4}([.,]\d{1,2})?$/', $n );
}

/** CSV-Text in Zeilen (assoziativ nach Kopfzeile) */
function anna_sk_csv_rows( $csv ) {
	$csv = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $csv );
	$fh  = fopen( 'php://temp', 'r+' );
	fwrite( $fh, $csv );
	rewind( $fh );
	$first = fgets( $fh );
	rewind( $fh );
	$delim = ( substr_count( (string) $first, ';' ) > substr_count( (string) $first, ',' ) ) ? ';' : ',';
	$head  = null;
	$rows  = array();
	while ( ( $r = fgetcsv( $fh, 0, $delim, '"', '\\' ) ) !== false ) {
		if ( $head === null ) {
			$head = array_map(
				function ( $h ) {
					return strtolower( trim( preg_replace( '/\s+/', ' ', $h ) ) );
				},
				$r
			);
			continue;
		}
		$row = array();
		foreach ( $head as $i => $h ) {
			$row[ $h ] = isset( $r[ $i ] ) ? trim( $r[ $i ] ) : '';
		}
		$rows[] = $row;
	}
	fclose( $fh );
	return $rows;
}

/**
 * Zeilen -> Tafeln
 * Typen: Tafel, Kategorie, Gericht, Banner, Schrift, Abstand. Zeilen mit leerem Typ oder "#" werden ignoriert.
 */
function anna_sk_build( array $rows ) {
	$boards = array();
	$cur    = -1;
	$g      = function ( $r, $k ) {
		return isset( $r[ $k ] ) ? trim( $r[ $k ] ) : '';
	};
	foreach ( $rows as $r ) {
		$typ = strtolower( $g( $r, 'typ' ) );
		if ( $typ === '' || $typ[0] === '#' ) {
			continue;
		}
		if ( $typ === 'tafel' ) {
			$boards[] = array(
				'title'   => $g( $r, 'name' ),
				'bg'      => $g( $r, 'zusatz' ),
				'columns' => array( array(), array(), array() ),
			);
			$cur      = count( $boards ) - 1;
			continue;
		}
		if ( $cur < 0 ) {
			$boards[] = array( 'title' => 'Speisekarte', 'bg' => '', 'columns' => array( array(), array(), array() ) );
			$cur      = 0;
		}
		$sp = (int) $g( $r, 'spalte' );
		$sp = max( 1, min( 3, $sp ? $sp : 1 ) ) - 1;
		$p1 = $g( $r, 'preis 1' );
		$p2 = $g( $r, 'preis 2' );

		switch ( $typ ) {
			case 'kategorie':
				$b = array( 't' => 'kat', 'name' => $g( $r, 'name' ), 'labels' => array(), 'price' => '', 'note' => $g( $r, 'beschreibung' ) );
				if ( $p1 !== '' && anna_sk_is_price( $p1 ) && $p2 === '' ) {
					$b['price'] = anna_sk_price( $p1 );
				} else {
					$b['labels'] = array_values( array_filter( array( $p1, $p2 ), 'strlen' ) );
				}
				break;
			case 'gericht':
				$b = array(
					't'      => 'item',
					'nr'     => $g( $r, 'nr' ),
					'name'   => $g( $r, 'name' ),
					'desc'   => $g( $r, 'beschreibung' ),
					'prices' => array_values( array_filter( array( anna_sk_price( $p1 ), anna_sk_price( $p2 ) ), 'strlen' ) ),
					'suffix' => $g( $r, 'zusatz' ),
				);
				if ( $b['name'] === '' ) {
					continue 2;
				}
				break;
			case 'banner':
				$b = array( 't' => 'banner', 'text' => $g( $r, 'name' ), 'bubble' => $g( $r, 'zusatz' ) );
				break;
			case 'schrift':
				$b = array( 't' => 'script', 'text' => $g( $r, 'name' ) );
				break;
			case 'abstand':
				$b = array( 't' => 'space', 'h' => (int) $g( $r, 'name' ) );
				break;
			default:
				continue 2;
		}
		$boards[ $cur ]['columns'][ $sp ][] = $b;
	}
	return $boards;
}

function anna_sk_parse_csv( $csv ) {
	return anna_sk_build( anna_sk_csv_rows( $csv ) );
}

/** Google-Sheet-Link -> CSV-Export-Link */
function anna_sk_csv_url( $url ) {
	$url = trim( (string) $url );
	if ( $url === '' ) {
		return '';
	}
	if ( strpos( $url, 'output=csv' ) !== false || strpos( $url, 'format=csv' ) !== false ) {
		return $url;
	}
	if ( preg_match( '~docs\.google\.com/spreadsheets/d/e/([^/]+)/pub(html)?~', $url, $m ) ) {
		$gid = preg_match( '~gid=(\d+)~', $url, $gm ) ? '&gid=' . $gm[1] . '&single=true' : '';
		return 'https://docs.google.com/spreadsheets/d/e/' . $m[1] . '/pub?output=csv' . $gid;
	}
	if ( preg_match( '~docs\.google\.com/spreadsheets/d/([a-zA-Z0-9_-]+)~', $url, $m ) ) {
		$gid = preg_match( '~gid=(\d+)~', $url, $gm ) ? $gm[1] : '0';
		return 'https://docs.google.com/spreadsheets/d/' . $m[1] . '/export?format=csv&gid=' . $gid;
	}
	return $url;
}
