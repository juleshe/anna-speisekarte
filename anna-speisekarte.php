<?php
/**
 * Plugin Name:       Anna Imbiss – Speisekarte
 * Description:       Speisekarte aus einem Google Sheet: lesbare HTML-Karte per Shortcode [anna_speisekarte] und die Tafeln im TV-Look als Bild zum Speichern aufs Handy.
 * Version:           1.1.0
 * Update URI:        https://github.com/juleshe/anna-speisekarte
 * Author:            dreamcode
 * Author URI:        https://dreamcode.ch
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Text Domain:       anna-speisekarte
 */

defined( 'ABSPATH' ) || exit;

define( 'ANNA_SK_VER', '1.1.0' );
define( 'ANNA_SK_DIR', plugin_dir_path( __FILE__ ) );
define( 'ANNA_SK_URL', plugin_dir_url( __FILE__ ) );
define( 'ANNA_SK_CACHE_MIN', 10 );

require_once ANNA_SK_DIR . 'includes/parser.php';

/* ------------------------------------------------------------------ *
 * Automatische Updates aus GitHub (github.com/juleshe/anna-speisekarte)
 * ------------------------------------------------------------------ */
if ( file_exists( ANNA_SK_DIR . 'lib/plugin-update-checker/plugin-update-checker.php' ) ) {
	require_once ANNA_SK_DIR . 'lib/plugin-update-checker/plugin-update-checker.php';
	$GLOBALS['anna_sk_updater'] = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
		'https://github.com/juleshe/anna-speisekarte/',
		__FILE__,
		'anna-speisekarte'
	);
	// Version wird aus dem Plugin-Header im Branch "main" gelesen (Releases mit ZIP-Asset werden bevorzugt, falls vorhanden)
	$GLOBALS['anna_sk_updater']->setBranch( 'main' );
	$GLOBALS['anna_sk_updater']->getVcsApi()->enableReleaseAssets( '/anna-speisekarte\.zip($|[?&#])/i' );
}

// Updates dieses Plugins immer automatisch installieren
add_filter(
	'auto_update_plugin',
	function ( $update, $item ) {
		if ( isset( $item->plugin ) && $item->plugin === plugin_basename( __FILE__ ) ) {
			return true;
		}
		return $update;
	},
	10,
	2
);

/* ------------------------------------------------------------------ *
 * Daten: Google Sheet serverseitig laden (keine Besucher-IP an Google),
 * 10 Minuten Cache, bei Fehlern letzter guter Stand bzw. mitgelieferte CSV.
 * ------------------------------------------------------------------ */

function anna_sk_count_items( $boards ) {
	$n = 0;
	foreach ( (array) $boards as $b ) {
		foreach ( (array) $b['columns'] as $col ) {
			foreach ( $col as $blk ) {
				if ( isset( $blk['t'] ) && $blk['t'] === 'item' ) {
					$n++;
				}
			}
		}
	}
	return $n;
}

function anna_sk_fallback() {
	$f = ANNA_SK_DIR . 'data/speisekarte.csv';
	return file_exists( $f ) ? anna_sk_parse_csv( file_get_contents( $f ) ) : array();
}

function anna_sk_get_boards( $force = false ) {
	$url = anna_sk_csv_url( get_option( 'anna_sk_sheet_url', '' ) );
	if ( $url === '' ) {
		return anna_sk_fallback();
	}
	if ( ! $force ) {
		$cached = get_transient( 'anna_sk_boards' );
		if ( is_array( $cached ) ) {
			return $cached;
		}
	}

	$err = '';
	$res = wp_safe_remote_get( $url, array( 'timeout' => 8, 'redirection' => 5 ) );
	if ( is_wp_error( $res ) ) {
		$err = 'Google Sheet nicht erreichbar: ' . $res->get_error_message();
	} elseif ( (int) wp_remote_retrieve_response_code( $res ) !== 200 ) {
		$err = 'Google Sheet antwortet mit Status ' . (int) wp_remote_retrieve_response_code( $res ) . '. Ist es für „Jeder mit dem Link“ freigegeben?';
	} else {
		$body = wp_remote_retrieve_body( $res );
		if ( stripos( substr( $body, 0, 500 ), '<html' ) !== false || stripos( substr( $body, 0, 500 ), '<!doctype' ) !== false ) {
			$err = 'Google liefert eine Webseite statt Daten. Bitte das Sheet für „Jeder mit dem Link: Betrachter“ freigeben.';
		} else {
			$boards = anna_sk_parse_csv( $body );
			$items  = anna_sk_count_items( $boards );
			if ( $items > 0 ) {
				set_transient( 'anna_sk_boards', $boards, ANNA_SK_CACHE_MIN * MINUTE_IN_SECONDS );
				update_option( 'anna_sk_last_good', array( 'boards' => $boards, 'time' => time() ), false );
				update_option( 'anna_sk_status', array( 'ok' => true, 'time' => time(), 'msg' => count( $boards ) . ' Tafeln, ' . $items . ' Gerichte geladen.' ), false );
				return $boards;
			}
			$err = 'Im Sheet wurden keine Gerichte gefunden. Stimmen die Spaltenüberschriften (Typ, Spalte, Nr, Name, Beschreibung, Preis 1, Preis 2, Zusatz)?';
		}
	}

	update_option( 'anna_sk_status', array( 'ok' => false, 'time' => time(), 'msg' => $err ), false );
	$last   = get_option( 'anna_sk_last_good' );
	$boards = ( is_array( $last ) && ! empty( $last['boards'] ) ) ? $last['boards'] : anna_sk_fallback();
	set_transient( 'anna_sk_boards', $boards, 2 * MINUTE_IN_SECONDS );
	return $boards;
}

/** Gängige Seiten-Caches leeren, damit neue Preise sofort sichtbar sind. */
function anna_sk_purge_page_caches() {
	if ( function_exists( 'rocket_clean_domain' ) ) {
		rocket_clean_domain();
	}
	if ( function_exists( 'w3tc_flush_all' ) ) {
		w3tc_flush_all();
	}
	if ( function_exists( 'wp_cache_clear_cache' ) ) {
		wp_cache_clear_cache();
	}
	if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
		sg_cachepress_purge_cache();
	}
	do_action( 'litespeed_purge_all' );
	if ( class_exists( 'autoptimizeCache' ) && method_exists( 'autoptimizeCache', 'clearall' ) ) {
		autoptimizeCache::clearall();
	}
}

/* ------------------------------------------------------------------ *
 * Assets
 * ------------------------------------------------------------------ */

add_action(
	'wp_enqueue_scripts',
	function () {
		wp_register_style( 'anna-sk', ANNA_SK_URL . 'assets/menu.css', array(), ANNA_SK_VER );
		wp_register_script( 'anna-sk-renderer', ANNA_SK_URL . 'assets/renderer.js', array(), ANNA_SK_VER, true );
		wp_register_script( 'anna-sk', ANNA_SK_URL . 'assets/menu.js', array( 'anna-sk-renderer' ), ANNA_SK_VER, true );
		wp_enqueue_style( 'anna-sk' );
	}
);

/* ------------------------------------------------------------------ *
 * Shortcode [anna_speisekarte]
 * ------------------------------------------------------------------ */

function anna_sk_slug( $s ) {
	$s = strtr( function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $s, 'UTF-8' ) : strtolower( (string) $s ), array( 'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss', '&' => 'und' ) );
	$s = preg_replace( '/[^a-z0-9]+/', '-', $s );
	return trim( $s, '-' );
}

function anna_sk_render_block( $b, $slots = 1 ) {
	$out = '';
	switch ( $b['t'] ) {
		case 'kat':
			$out .= '<div class="anna-sk__kat' . ( $b['name'] === '' ? ' anna-sk__kat--labels' : '' ) . '">';
			if ( $b['name'] !== '' ) {
				$out .= '<h3 class="anna-sk__kattitle">' . esc_html( $b['name'] ) . '</h3>';
			}
			if ( $b['price'] !== '' ) {
				$out .= '<span class="anna-sk__prices"><span class="anna-sk__price">' . esc_html( $b['price'] ) . '</span></span>';
			} elseif ( ! empty( $b['labels'] ) ) {
				$out .= '<span class="anna-sk__labels anna-sk__labels--' . count( $b['labels'] ) . '">';
				foreach ( $b['labels'] as $l ) {
					$out .= '<span>' . esc_html( $l ) . '</span>';
				}
				$out .= '</span>';
			}
			if ( $b['note'] !== '' ) {
				$out .= '<p class="anna-sk__note">' . esc_html( $b['note'] ) . '</p>';
			}
			$out .= '</div>';
			break;

		case 'item':
			$n    = count( $b['prices'] );
			$out .= '<div class="anna-sk__item">';
			$out .= '<div class="anna-sk__text"><p class="anna-sk__name">';
			if ( $b['nr'] !== '' ) {
				$out .= '<span class="anna-sk__nr">' . esc_html( $b['nr'] ) . '.)</span> ';
			}
			$out .= esc_html( $b['name'] );
			if ( $b['suffix'] !== '' ) {
				$out .= ' <small>' . esc_html( $b['suffix'] ) . '</small>';
			}
			$out .= '</p>';
			if ( $b['desc'] !== '' ) {
				$out .= '<p class="anna-sk__desc">' . esc_html( $b['desc'] ) . '</p>';
			}
			$out .= '</div>';
			$out .= '<span class="anna-sk__prices anna-sk__prices--' . max( $n, $slots ) . '">';
			for ( $k = $n; $k < $slots; $k++ ) {
				$out .= '<span class="anna-sk__price anna-sk__price--empty"></span>';
			}
			foreach ( $b['prices'] as $p ) {
				$out .= '<span class="anna-sk__price">' . esc_html( $p ) . '</span>';
			}
			$out .= '</span></div>';
			break;

		case 'banner':
			$out .= '<p class="anna-sk__banner">' . esc_html( $b['text'] );
			if ( $b['bubble'] !== '' ) {
				$out .= ' <strong class="anna-sk__bubble">' . esc_html( str_replace( '|', ' ', $b['bubble'] ) ) . '</strong>';
			}
			$out .= '</p>';
			break;

		case 'script':
			$out .= '<p class="anna-sk__script">' . esc_html( $b['text'] ) . '</p>';
			break;
	}
	return $out;
}

function anna_sk_shortcode() {
	$boards = anna_sk_get_boards();
	if ( empty( $boards ) ) {
		return '';
	}
	wp_enqueue_style( 'anna-sk' );
	wp_enqueue_script( 'anna-sk' );

	$uid  = 'anna-sk-' . wp_rand( 1000, 9999 );
	$data = array( 'boards' => array() );
	$html = '<div class="anna-sk" id="' . esc_attr( $uid ) . '" data-anna-sk>';

	// Tabs
	$html .= '<div class="anna-sk__tabs" role="tablist" aria-label="Speisekarte">';
	foreach ( $boards as $i => $b ) {
		$html .= '<button type="button" role="tab" class="anna-sk__tab" id="' . esc_attr( $uid . '-t' . $i ) . '" aria-controls="' . esc_attr( $uid . '-b' . $i ) . '" aria-selected="' . ( $i === 0 ? 'true' : 'false' ) . '">' . esc_html( $b['title'] ) . '</button>';
	}
	$html .= '</div>';

	// Speichern
	$html .= '<div class="anna-sk__save"><button type="button" class="anna-sk__savebtn" data-anna-sk-save>'
		. '<svg aria-hidden="true" viewBox="0 0 24 24" width="20" height="20"><path fill="currentColor" d="M12 3a1 1 0 0 1 1 1v9.6l3.3-3.3a1 1 0 1 1 1.4 1.4l-5 5a1 1 0 0 1-1.4 0l-5-5a1 1 0 0 1 1.4-1.4l3.3 3.3V4a1 1 0 0 1 1-1zM5 19h14a1 1 0 1 1 0 2H5a1 1 0 1 1 0-2z"/></svg>'
		. '<span>Speisekarte aufs Handy speichern</span></button>'
		. '<p class="anna-sk__savehint">Alle ' . count( $boards ) . ' Seiten als Bild</p></div>';

	// Tafeln
	foreach ( $boards as $i => $b ) {
		$bg = $b['bg'] !== '' ? $b['bg'] : ( file_exists( ANNA_SK_DIR . 'assets/bg/' . ( $i + 1 ) . '.jpg' ) ? ANNA_SK_URL . 'assets/bg/' . ( $i + 1 ) . '.jpg' : '' );

		$data['boards'][] = array(
			'title'   => $b['title'],
			'file'    => 'anna-imbiss-speisekarte-' . ( $i + 1 ) . '-' . anna_sk_slug( $b['title'] ) . '.jpg',
			'bg'      => $bg,
			'columns' => $b['columns'],
		);

		$html .= '<section class="anna-sk__board" role="tabpanel" id="' . esc_attr( $uid . '-b' . $i ) . '" aria-labelledby="' . esc_attr( $uid . '-t' . $i ) . '">';
		$html .= '<h2 class="anna-sk__boardtitle">' . esc_html( $b['title'] ) . '</h2><div class="anna-sk__cols">';
		foreach ( $b['columns'] as $col ) {
			if ( empty( $col ) ) {
				continue;
			}
			$html .= '<div class="anna-sk__col">';
			$slots = 1;
			foreach ( $col as $blk ) {
				if ( $blk['t'] === 'kat' ) {
					$slots = max( 1, count( $blk['labels'] ) );
				}
				$html .= anna_sk_render_block( $blk, $slots );
			}
			$html .= '</div>';
		}
		$html .= '</div></section>';
	}

	$html .= '<script type="application/json" class="anna-sk__data">' . wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP ) . '</script>';
	$html .= '</div>';
	return $html;
}
add_shortcode( 'anna_speisekarte', 'anna_sk_shortcode' );

/* ------------------------------------------------------------------ *
 * Einstellungen → Speisekarte
 * ------------------------------------------------------------------ */

add_action(
	'admin_menu',
	function () {
		add_options_page( 'Speisekarte', 'Speisekarte', 'manage_options', 'anna-speisekarte', 'anna_sk_settings_page' );
	}
);

add_action(
	'admin_init',
	function () {
		register_setting(
			'anna_sk',
			'anna_sk_sheet_url',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'esc_url_raw',
				'default'           => '',
			)
		);
	}
);

// Nach dem Speichern des Links: Cache leeren
add_action(
	'update_option_anna_sk_sheet_url',
	function () {
		delete_transient( 'anna_sk_boards' );
	}
);

add_action(
	'admin_post_anna_sk_reload',
	function () {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Keine Berechtigung.' );
		}
		check_admin_referer( 'anna_sk_reload' );
		delete_transient( 'anna_sk_boards' );
		anna_sk_get_boards( true );
		anna_sk_purge_page_caches();
		wp_safe_redirect( admin_url( 'options-general.php?page=anna-speisekarte&reloaded=1' ) );
		exit;
	}
);

function anna_sk_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$status = get_option( 'anna_sk_status' );
	$url    = get_option( 'anna_sk_sheet_url', '' );
	?>
	<div class="wrap">
		<h1>Speisekarte</h1>
		<?php if ( isset( $_GET['reloaded'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
			<div class="notice notice-<?php echo ( is_array( $status ) && ! empty( $status['ok'] ) ) ? 'success' : 'error'; ?>"><p><?php echo esc_html( is_array( $status ) ? $status['msg'] : '' ); ?></p></div>
		<?php endif; ?>

		<form method="post" action="options.php">
			<?php settings_fields( 'anna_sk' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="anna_sk_sheet_url">Google-Sheet-Link</label></th>
					<td>
						<input name="anna_sk_sheet_url" id="anna_sk_sheet_url" type="url" class="large-text" value="<?php echo esc_attr( $url ); ?>" placeholder="https://docs.google.com/spreadsheets/d/…/edit#gid=0">
						<p class="description">Link aus der Browser-Adresszeile einfügen. Das Sheet muss für „Jeder mit dem Link: Betrachter“ freigegeben sein. Ohne Link wird die mitgelieferte Karte angezeigt.</p>
					</td>
				</tr>
			</table>
			<?php submit_button( 'Link speichern' ); ?>
		</form>

		<h2>Preise aktualisieren</h2>
		<p>Die Website holt sich Änderungen im Sheet automatisch alle <?php echo (int) ANNA_SK_CACHE_MIN; ?> Minuten. Sofort übernehmen (leert auch den Seiten-Cache gängiger Cache-Plugins):</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="anna_sk_reload">
			<?php wp_nonce_field( 'anna_sk_reload' ); ?>
			<?php submit_button( 'Jetzt neu laden', 'secondary', 'submit', false ); ?>
		</form>

		<?php if ( is_array( $status ) ) : ?>
			<p><strong>Letzter Abruf:</strong> <?php echo esc_html( wp_date( 'd.m.Y H:i', (int) $status['time'] ) ); ?> – <?php echo esc_html( $status['msg'] ); ?></p>
		<?php endif; ?>

		<h2>Einbinden</h2>
		<p>Shortcode <code>[anna_speisekarte]</code> in ein Elementor-Shortcode-Widget auf der Speisekarten-Seite setzen.</p>
	</div>
	<?php
}

/* Link „Einstellungen“ in der Plugin-Liste */
add_filter(
	'plugin_action_links_' . plugin_basename( __FILE__ ),
	function ( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'options-general.php?page=anna-speisekarte' ) ) . '">Einstellungen</a>' );
		return $links;
	}
);
