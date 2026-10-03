<?php
/**
 * Módulo «Lista de deseos» de Woo Cuenta Simple.
 * QUÉ HACE   Corazón en tarjetas y fichas, varias listas por cliente, compartir
 *            por lista, [pa_la_wishlist] y alertas diarias por correo («bajó de
 *            precio» / «volvió a estar disponible»).
 * DÓNDE SE NOTA  Tarjetas de la tienda, fichas, la página con el shortcode y
 *            Mi cuenta → Mis listas.
 * PRIVACIDAD  Las alertas son OPT-IN: apagadas en Ajustes por defecto y, aun
 *            encendidas, solo salen a quien las activó en su lista (🔔). El
 *            filtro 'plw_alert_allowed' permite conectar un sistema propio de
 *            consentimiento y bajas.
 * AJUSTES    Ajustes → Cuenta · Lista de deseos (opción plw_options).
 * CÓMO SE APAGA  update_option( 'pa_cuenta_wishlist_off', 1 );
 * CÓMO SE DESHACE  delete_option( 'pa_cuenta_wishlist_off' );
 */

defined( 'ABSPATH' ) || exit;

define( 'PLW_VERSION', '2.4.0' );
define( 'PLW_DIR', plugin_dir_path( __FILE__ ) );
define( 'PLW_URL', plugin_dir_url( __FILE__ ) );
if ( ! defined( 'PLW_MAX_ITEMS' ) ) {
	define( 'PLW_MAX_ITEMS', 500 ); // máximo de productos por lista.
}


/**
 * Opciones con sus valores por defecto.
 */
function plw_options() {
	$defaults = array(
		'color'   => '', // vacío = contraste del tema.
		'page_id' => 0, // página con [pa_la_wishlist].
		'alerts'  => '', // '1' = enviar alertas de precio / stock (opt-in por usuario).
	);
	$opt = get_option( 'plw_options', array() );
	return wp_parse_args( is_array( $opt ) ? $opt : array(), $defaults );
}


/**
 * URL de la página de la lista: la elegida en Ajustes o, si no hay,
 * se autodetecta buscando la página publicada que contiene el shortcode.
 */
function plw_page_url() {
	$opt = plw_options();
	if ( $opt['page_id'] ) {
		return get_permalink( (int) $opt['page_id'] ) ?: '';
	}
	$cached = get_option( 'plw_page_autodetect', null );
	if ( null === $cached || '' === $cached ) {
		global $wpdb;
		$pid = (int) $wpdb->get_var(
			"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'page' AND post_status = 'publish' AND post_content LIKE '%pa_la_wishlist%' LIMIT 1"
		);
		update_option( 'plw_page_autodetect', $pid, false );
		$cached = $pid;
	}
	return $cached ? ( get_permalink( (int) $cached ) ?: '' ) : '';
}

// Si se edita una página, redetectar (por si movieron el shortcode).
add_action( 'save_post_page', function () {
	delete_option( 'plw_page_autodetect' );
} );

// ══════════════════════════════════════════════════════════════════════════════
// 1. DATOS ── Varias listas por usuario: meta plw_lists = token => [name, ids].
// ══════════════════════════════════════════════════════════════════════════════

/** Listas del usuario; migra desde plw_list (v1) y WPC al primer uso. */
function plw_get_lists( $user_id ) {
	$lists = get_user_meta( $user_id, 'plw_lists', true );
	if ( is_array( $lists ) && ! empty( $lists ) ) {
		return $lists;
	}
	// Migración: lista única v1 → si no, WPC Smart Wishlist.
	$ids = get_user_meta( $user_id, 'plw_list', true );
	if ( ! is_array( $ids ) ) {
		$ids = array();
		$key = get_user_meta( $user_id, 'woosw_key', true );
		if ( $key ) {
			$old = get_option( 'woosw_list_' . $key );
			if ( is_array( $old ) ) {
				$ids = array_map( 'intval', array_keys( $old ) );
			}
		}
	}
	$lists = array(
		wp_generate_password( 16, false ) => array(
			'name' => 'Mi lista de deseos',
			'ids'  => array_slice( array_values( array_unique( array_map( 'intval', $ids ) ) ), 0, PLW_MAX_ITEMS ),
		),
	);
	update_user_meta( $user_id, 'plw_lists', $lists );
	return $lists;
}

function plw_save_lists( $user_id, array $lists ) {
	foreach ( $lists as $t => $l ) {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $l['ids'] ) ) ) );
		// Tope por lista: evita metas gigantes enviadas a mano por la API.
		$lists[ $t ]['ids'] = array_slice( $ids, 0, PLW_MAX_ITEMS );
	}
	update_user_meta( $user_id, 'plw_lists', $lists );
}

/** Unión de IDs de todas las listas (para pintar corazones). */
function plw_all_ids( array $lists ) {
	$all = array();
	foreach ( $lists as $l ) {
		$all = array_merge( $all, $l['ids'] );
	}
	return array_values( array_unique( $all ) );
}

/** Dueño de un token de lista (para vistas compartidas). */
function plw_find_owner_by_token( $token ) {
	global $wpdb;
	if ( ! preg_match( '/^[a-zA-Z0-9]{8,32}$/', $token ) ) {
		return 0;
	}
	// Se busca la clave serializada exacta ("TOKEN") y se confirma en PHP: un
	// nombre o una nota que contengan el texto no confunden al dueño.
	$uids = $wpdb->get_col( $wpdb->prepare(
		"SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = 'plw_lists' AND meta_value LIKE %s LIMIT 5",
		'%' . $wpdb->esc_like( '"' . $token . '"' ) . '%'
	) );
	foreach ( (array) $uids as $uid ) {
		$lists = get_user_meta( (int) $uid, 'plw_lists', true );
		if ( is_array( $lists ) && isset( $lists[ $token ] ) ) {
			return (int) $uid;
		}
	}
	return 0;
}

/** Datos compactos de productos para pintar tarjetas. */
function plw_products_data( array $ids ) {
	$out = array();
	foreach ( array_slice( $ids, 0, 100 ) as $id ) {
		$p = wc_get_product( $id );
		if ( ! $p || 'publish' !== $p->get_status() ) {
			continue;
		}
		// El price_html de Woo trae un span screen-reader-text ("Price range:
		// X through Y") que al quitar etiquetas queda pegado al precio visible.
		$price_html = preg_replace( '/<span[^>]*screen-reader-text[^>]*>.*?<\/span>/s', '', $p->get_price_html() );
		$price_txt  = trim( preg_replace( '/\s+/', ' ', html_entity_decode( wp_strip_all_tags( $price_html ), ENT_QUOTES, 'UTF-8' ) ) );

		$out[] = array(
			'id'        => $p->get_id(),
			'name'      => html_entity_decode( wp_strip_all_tags( $p->get_name() ), ENT_QUOTES, 'UTF-8' ),
			'price'     => $price_txt,
			'img'       => wp_get_attachment_image_url( $p->get_image_id(), 'medium' ) ?: '',
			'permalink' => $p->get_permalink(),
			'in_stock'  => $p->is_in_stock(),
			'simple'    => $p->is_type( 'simple' ),
		);
	}
	return $out;
}

/** Reservas vigentes de una lista (las de +90 días expiran solas). */
function plw_reserved_ids( array $list ) {
	$out = array();
	foreach ( (array) ( $list['reserved'] ?? array() ) as $pid => $ts ) {
		if ( (int) $ts > time() - 90 * DAY_IN_SECONDS ) {
			$out[] = (int) $pid;
		}
	}
	return $out;
}

/** Resumen de listas para el front (sin items, solo conteos). */
function plw_lists_summary( array $lists ) {
	$out = array();
	foreach ( $lists as $token => $l ) {
		$out[] = array( 'token' => $token, 'name' => $l['name'], 'count' => count( $l['ids'] ), 'private' => ! empty( $l['private'] ) );
	}
	return $out;
}

// ══════════════════════════════════════════════════════════════════════════════
// 2. REST ── plw/v1.
// ══════════════════════════════════════════════════════════════════════════════

add_action( 'rest_api_init', function () {
	$logged = array( 'permission_callback' => 'is_user_logged_in' );
	$public = array( 'permission_callback' => '__return_true' );

	// Estado completo del usuario: listas + ids unidos (corazones).
	register_rest_route( 'plw/v1', '/state', $public + array(
		'methods'  => 'GET',
		'callback' => function () {
			if ( ! is_user_logged_in() ) {
				return array( 'guest' => true );
			}
			$lists = plw_get_lists( get_current_user_id() );
			return array( 'lists' => plw_lists_summary( $lists ), 'allIds' => plw_all_ids( $lists ) );
		},
	) );

	// Items de una lista: propia (token de mis listas) o compartida (público).
	register_rest_route( 'plw/v1', '/list', $public + array(
		'methods'  => 'GET',
		'callback' => function ( WP_REST_Request $req ) {
			$token = sanitize_text_field( (string) $req->get_param( 'token' ) );
			if ( is_user_logged_in() ) {
				$lists = plw_get_lists( get_current_user_id() );
				if ( isset( $lists[ $token ] ) ) {
					return array(
						'items' => plw_products_data( $lists[ $token ]['ids'] ),
						'name'  => $lists[ $token ]['name'],
						'notes' => (object) ( $lists[ $token ]['notes'] ?? array() ),
						'mine'  => true,
					);
				}
			}
			$owner = plw_find_owner_by_token( $token );
			if ( ! $owner ) {
				return new WP_Error( 'plw_404', 'Lista no encontrada.', array( 'status' => 404 ) );
			}
			$lists = plw_get_lists( $owner );
			if ( ! isset( $lists[ $token ] ) ) {
				return new WP_Error( 'plw_404', 'Lista no encontrada.', array( 'status' => 404 ) );
			}
			// Listas privadas: solo el dueño las ve (y él entra por la rama
			// de arriba); para el resto, como si no existieran.
			if ( ! empty( $lists[ $token ]['private'] ) ) {
				return new WP_Error( 'plw_404', 'Esta lista es privada. 🔒', array( 'status' => 404 ) );
			}
			return array(
				'items'    => plw_products_data( $lists[ $token ]['ids'] ),
				'name'     => $lists[ $token ]['name'],
				'owner'    => get_user_by( 'id', $owner )->display_name,
				'shared'   => true,
				// Reservas de regalo: visibles solo en la vista compartida
				// (el dueño no las recibe — sorpresa intacta).
				'reserved' => plw_reserved_ids( $lists[ $token ] ),
			);
		},
	) );

	// Datos de productos (invitados, IDs del localStorage).
	register_rest_route( 'plw/v1', '/products', $public + array(
		'methods'  => 'GET',
		'callback' => function ( WP_REST_Request $req ) {
			$ids = array_filter( array_map( 'intval', explode( ',', (string) $req->get_param( 'ids' ) ) ) );
			return array( 'items' => plw_products_data( $ids ) );
		},
	) );

	// Quitar/poner un libro. set=on|off fuerza el estado (sin set, alterna).
	// set=off SIN token = quitar el libro de TODAS las listas.
	register_rest_route( 'plw/v1', '/toggle', $logged + array(
		'methods'  => 'POST',
		'callback' => function ( WP_REST_Request $req ) {
			$id    = (int) $req->get_param( 'id' );
			$token = sanitize_text_field( (string) $req->get_param( 'token' ) );
			$set   = sanitize_text_field( (string) $req->get_param( 'set' ) );
			$uid   = get_current_user_id();
			$lists = plw_get_lists( $uid );

			if ( 'off' === $set && '' === $token ) {
				foreach ( $lists as $t => $l ) {
					$lists[ $t ]['ids'] = array_values( array_diff( $l['ids'], array( $id ) ) );
				}
				plw_save_lists( $uid, $lists );
				return array( 'on' => false, 'allIds' => plw_all_ids( $lists ), 'lists' => plw_lists_summary( $lists ) );
			}

			if ( ! isset( $lists[ $token ] ) ) {
				$token = array_key_first( $lists );
			}
			$ids = $lists[ $token ]['ids'];
			$in  = in_array( $id, $ids, true );
			$on  = 'on' === $set ? true : ( 'off' === $set ? false : ! $in );
			if ( $on && ! $in ) {
				$lists[ $token ]['ids'][] = $id;
			} elseif ( ! $on && $in ) {
				$lists[ $token ]['ids'] = array_values( array_diff( $ids, array( $id ) ) );
			}
			plw_save_lists( $uid, $lists );
			return array( 'on' => $on, 'allIds' => plw_all_ids( $lists ), 'lists' => plw_lists_summary( $lists ) );
		},
	) );

	// Mover un libro entre listas.
	register_rest_route( 'plw/v1', '/move', $logged + array(
		'methods'  => 'POST',
		'callback' => function ( WP_REST_Request $req ) {
			$id   = (int) $req->get_param( 'id' );
			$from = sanitize_text_field( (string) $req->get_param( 'from' ) );
			$to   = sanitize_text_field( (string) $req->get_param( 'to' ) );
			$uid  = get_current_user_id();
			$lists = plw_get_lists( $uid );
			if ( ! isset( $lists[ $from ], $lists[ $to ] ) ) {
				return new WP_Error( 'plw_bad', 'Lista inválida.', array( 'status' => 400 ) );
			}
			$lists[ $from ]['ids'] = array_values( array_diff( $lists[ $from ]['ids'], array( $id ) ) );
			$lists[ $to ]['ids'][] = $id;
			plw_save_lists( $uid, $lists );
			return array( 'lists' => plw_lists_summary( $lists ) );
		},
	) );

	// Crear / renombrar / eliminar listas.
	register_rest_route( 'plw/v1', '/lists', $logged + array(
		'methods'  => 'POST',
		'callback' => function ( WP_REST_Request $req ) {
			$action = sanitize_text_field( (string) $req->get_param( 'action' ) );
			$name   = sanitize_text_field( wp_unslash( (string) $req->get_param( 'name' ) ) );
			$token  = sanitize_text_field( (string) $req->get_param( 'token' ) );
			$uid    = get_current_user_id();
			$lists  = plw_get_lists( $uid );

			if ( 'create' === $action ) {
				if ( '' === $name || count( $lists ) >= 20 ) {
					return new WP_Error( 'plw_bad', 'Ponle nombre a la lista (máximo 20 listas).', array( 'status' => 400 ) );
				}
				$new           = wp_generate_password( 16, false );
				$lists[ $new ] = array( 'name' => mb_substr( $name, 0, 60 ), 'ids' => array() );
				plw_save_lists( $uid, $lists );
				return array( 'lists' => plw_lists_summary( $lists ), 'token' => $new );
			}
			if ( 'rename' === $action && isset( $lists[ $token ] ) && '' !== $name ) {
				$lists[ $token ]['name'] = mb_substr( $name, 0, 60 );
				plw_save_lists( $uid, $lists );
				return array( 'lists' => plw_lists_summary( $lists ) );
			}
			if ( 'privacy' === $action && isset( $lists[ $token ] ) ) {
				$lists[ $token ]['private'] = (bool) $req->get_param( 'private' );
				plw_save_lists( $uid, $lists );
				return array( 'lists' => plw_lists_summary( $lists ) );
			}
			if ( 'delete' === $action && isset( $lists[ $token ] ) ) {
				if ( count( $lists ) <= 1 ) {
					return new WP_Error( 'plw_bad', 'No puedes borrar tu única lista.', array( 'status' => 400 ) );
				}
				unset( $lists[ $token ] );
				plw_save_lists( $uid, $lists );
				return array( 'lists' => plw_lists_summary( $lists ) );
			}
			return new WP_Error( 'plw_bad', 'Acción inválida.', array( 'status' => 400 ) );
		},
	) );

	// Nota personal de un libro en una lista ("para el cumple de mamá").
	register_rest_route( 'plw/v1', '/note', $logged + array(
		'methods'  => 'POST',
		'callback' => function ( WP_REST_Request $req ) {
			$id    = (int) $req->get_param( 'id' );
			$token = sanitize_text_field( (string) $req->get_param( 'token' ) );
			$note  = sanitize_text_field( wp_unslash( (string) $req->get_param( 'note' ) ) );
			$uid   = get_current_user_id();
			$lists = plw_get_lists( $uid );
			if ( ! isset( $lists[ $token ] ) ) {
				return new WP_Error( 'plw_bad', 'Lista inválida.', array( 'status' => 400 ) );
			}
			if ( ! isset( $lists[ $token ]['notes'] ) ) {
				$lists[ $token ]['notes'] = array();
			}
			if ( '' === $note ) {
				unset( $lists[ $token ]['notes'][ $id ] );
			} else {
				$lists[ $token ]['notes'][ $id ] = mb_substr( $note, 0, 140 );
			}
			plw_save_lists( $uid, $lists );
			return array( 'ok' => true );
		},
	) );

	// Reservar un regalo en una lista compartida (público, sin cuenta).
	// El dueño NUNCA ve las reservas, para no dañar la sorpresa.
	register_rest_route( 'plw/v1', '/reserve', $public + array(
		'methods'  => 'POST',
		'callback' => function ( WP_REST_Request $req ) {
			$token  = sanitize_text_field( (string) $req->get_param( 'token' ) );
			$id     = (int) $req->get_param( 'id' );
			$cancel = (bool) $req->get_param( 'cancel' );

			// Freno suave anti-abuso: 30 reservas/hora por IP.
			$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '0';
			$key = 'plw_rl_' . md5( $ip );
			$n   = (int) get_transient( $key );
			if ( $n > 30 ) {
				return new WP_Error( 'plw_limit', 'Demasiadas reservas. Espera un rato. ☕', array( 'status' => 429 ) );
			}
			set_transient( $key, $n + 1, HOUR_IN_SECONDS );

			$owner = plw_find_owner_by_token( $token );
			if ( ! $owner ) {
				return new WP_Error( 'plw_404', 'Lista no encontrada.', array( 'status' => 404 ) );
			}
			$lists = plw_get_lists( $owner );
			if ( ! isset( $lists[ $token ] ) || ! empty( $lists[ $token ]['private'] ) || ! in_array( $id, $lists[ $token ]['ids'], true ) ) {
				return new WP_Error( 'plw_404', 'Lista no encontrada.', array( 'status' => 404 ) );
			}
			if ( ! isset( $lists[ $token ]['reserved'] ) ) {
				$lists[ $token ]['reserved'] = array();
			}
			if ( $cancel ) {
				unset( $lists[ $token ]['reserved'][ $id ] );
			} else {
				$lists[ $token ]['reserved'][ $id ] = time();
			}
			plw_save_lists( $owner, $lists );
			return array( 'reserved' => plw_reserved_ids( $lists[ $token ] ) );
		},
	) );

	// Activar/desactivar las alertas de precio y stock por correo.
	register_rest_route( 'plw/v1', '/alerts', $logged + array(
		'methods'  => 'POST',
		'callback' => function ( WP_REST_Request $req ) {
			update_user_meta( get_current_user_id(), 'plw_alerts', $req->get_param( 'on' ) ? 'on' : 'off' );
			return array( 'ok' => true );
		},
	) );

	// Fusión invitado → cuenta (a la primera lista).
	register_rest_route( 'plw/v1', '/merge', $logged + array(
		'methods'  => 'POST',
		'callback' => function ( WP_REST_Request $req ) {
			$ids   = array_slice( array_filter( array_map( 'intval', (array) $req->get_param( 'ids' ) ) ), 0, PLW_MAX_ITEMS );
			$uid   = get_current_user_id();
			$lists = plw_get_lists( $uid );
			$first = array_key_first( $lists );
			$lists[ $first ]['ids'] = array_merge( $lists[ $first ]['ids'], $ids );
			plw_save_lists( $uid, $lists );
			return array( 'allIds' => plw_all_ids( $lists ), 'lists' => plw_lists_summary( $lists ) );
		},
	) );
} );

// ══════════════════════════════════════════════════════════════════════════════
// 3. FRONTEND ── Corazones + toast + assets.
// ══════════════════════════════════════════════════════════════════════════════

function plw_heart_svg() {
	return '<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path class="plw-outline" fill="none" stroke="currentColor" stroke-width="1.8" d="M12 21s-7.5-4.7-9.7-9C.7 8.7 2.4 5 6 5c2.2 0 3.6 1.2 6 3.7C14.4 6.2 15.8 5 18 5c3.6 0 5.3 3.7 3.7 7-2.2 4.3-9.7 9-9.7 9z"/><path class="plw-fill" fill="currentColor" d="M12 21s-7.5-4.7-9.7-9C.7 8.7 2.4 5 6 5c2.2 0 3.6 1.2 6 3.7C14.4 6.2 15.8 5 18 5c3.6 0 5.3 3.7 3.7 7-2.2 4.3-9.7 9-9.7 9z"/></svg>';
}

function plw_heart_button( $product_id, $extra_class = '' ) {
	// Sin elegir: corazón en línea. Elegido: 😍 (el CSS alterna).
	return '<button type="button" class="plw-heart ' . esc_attr( $extra_class ) . '" data-plw="' . (int) $product_id . '" aria-label="Agregar a la lista de deseos">' . plw_heart_svg() . '<span class="plw-emoji" aria-hidden="true">😍</span></button>';
}

add_action( 'woocommerce_before_shop_loop_item_title', function () {
	global $product;
	if ( $product instanceof WC_Product ) {
		echo plw_heart_button( $product->get_id(), 'plw-heart--loop' ); // phpcs:ignore WordPress.Security.EscapeOutput
	}
}, 9 );

add_action( 'woocommerce_single_product_summary', function () {
	global $product;
	if ( $product instanceof WC_Product ) {
		echo '<div class="plw-single-wrap">' . plw_heart_button( $product->get_id(), 'plw-heart--single' ) . ' <button type="button" class="plw-single-label" data-plw-label="' . (int) $product->get_id() . '">Añadir a la lista de deseos</button></div>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}
}, 15 );

// Toast global (confirmación al marcar el corazón).
add_action( 'wp_footer', function () {
	if ( ! function_exists( 'WC' ) ) {
		return;
	}
	echo '<div id="plw-toast" hidden><span id="plw-toast-msg"></span><a id="plw-toast-link" href="#">Ver lista</a></div>';
} );

add_action( 'wp_enqueue_scripts', function () {
	if ( ! function_exists( 'WC' ) ) {
		return;
	}
	$opt = plw_options();
	wp_enqueue_style( 'plw', PLW_URL . 'assets/wishlist.css', array(), PLW_VERSION );
	wp_enqueue_script( 'plw', PLW_URL . 'assets/wishlist.js', array(), PLW_VERSION, true );

	$color = sanitize_hex_color( (string) $opt['color'] );
	if ( $color ) {
		wp_add_inline_style( 'plw', ':root{--plw-accent:' . $color . '}' );
	}

	// Invitados sin nonce (cacheable); logueados con X-WP-Nonce (sin caché).
	wp_add_inline_script( 'plw', 'window.plwCfg=' . wp_json_encode( array(
		'rest'     => esc_url_raw( rest_url( 'plw/v1/' ) ),
		'storeApi' => esc_url_raw( rest_url( 'wc/store/v1/' ) ),
		'loggedIn' => is_user_logged_in(),
		'nonce'    => is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '',
		'pageUrl'  => plw_page_url(),
		'siteName' => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
		// Alertas: encendidas en el sitio y estado propio del usuario (opt-in).
		'alertsSite' => '1' === (string) $opt['alerts'],
		'alertsOn'   => is_user_logged_in() && 'on' === get_user_meta( get_current_user_id(), 'plw_alerts', true ),
	) ) . ';', 'before' );
} );

// ══════════════════════════════════════════════════════════════════════════════
// 4. PÁGINA ── [pa_la_wishlist]: sidebar de listas + grid (estilo Amazon).
// ══════════════════════════════════════════════════════════════════════════════

/**
 * Barra opcional de botones para compartir la lista. Vacía por defecto; otro
 * plugin o el tema la puede rellenar con el filtro 'plw_share_bar_html'
 * (recibe la URL de la página y un título). Si la barra lleva la clase
 * .pld-share con data-url y data-title, el JS la apunta a la lista activa.
 */
function plw_pld_share_bar() {
	$opt = plw_options();
	$url = $opt['page_id'] ? get_permalink( (int) $opt['page_id'] ) : home_url();
	return (string) apply_filters( 'plw_share_bar_html', '', $url, sprintf( 'Mi lista de deseos en %s', get_bloginfo( 'name' ) ) );
}

add_shortcode( 'pa_la_wishlist', function () {
	return '<div id="plw-page">'
		. '<aside id="plw-sidebar">'
		. '<h3>Tus listas</h3>'
		. '<ul id="plw-lists"></ul>'
		. '<div id="plw-new"><input type="text" id="plw-new-name" placeholder="Nueva lista (ej. Regalos 🎁)" maxlength="60"><button type="button" id="plw-new-btn" class="plw-btn-ghost">+ Crear</button></div>'
		. '<p id="plw-guest-cta" hidden>Inicia sesión para crear varias listas y no perderlas al cambiar de dispositivo. <a href="' . esc_url( function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : wp_login_url() ) . '" class="pent-login-tgr"><strong>Entrar</strong></a></p>'
		. '</aside>'
		. '<section id="plw-main">'
		. '<header id="plw-head">'
		. '<h2 id="plw-title">Mi lista de deseos</h2>'
		. '<div id="plw-tools" hidden>'
		. '<button type="button" id="plw-priv-btn" class="plw-btn-ghost">🌍 Pública</button>'
		. '<button type="button" id="plw-share-btn" class="plw-btn-ghost">Compartir 🎁</button>'
		. '<button type="button" id="plw-rename-btn" class="plw-btn-ghost">Renombrar</button>'
		. '<button type="button" id="plw-delete-btn" class="plw-btn-ghost plw-danger">Eliminar</button>'
		. '</div>'
		. '</header>'
		. '<p id="plw-owner" hidden></p>'
		. '<div id="plw-share" hidden><div class="plw-share-row"><input type="text" id="plw-share-url" readonly><button type="button" id="plw-share-copy" class="plw-btn-ghost">Copiar</button></div>'
		// Barra opcional de compartir (filtro plw_share_bar_html).
		. plw_pld_share_bar()
		. '</div>'
		. '<div id="plw-items"><p class="plw-loading">Cargando tu lista…</p></div>'
		. '<div id="plw-actions" hidden><button type="button" id="plw-add-all" class="plw-btn-primary">Agregar todo al carrito 🛒</button></div>'
		. '</section>'
		. '</div>';
} );

// ══════════════════════════════════════════════════════════════════════════════
// 5. ALERTAS ── Cron diario: "bajó de precio" / "volvió a stock" por correo.
// ══════════════════════════════════════════════════════════════════════════════

// Agendar / desagendar: lo hace el archivo principal del plugin.

// Red de seguridad: si la activación no agendó (instalación por FTP), agendar.
add_action( 'init', function () {
	if ( ! wp_next_scheduled( 'plw_daily_alerts' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'plw_daily_alerts' );
	}
} );

add_action( 'plw_daily_alerts', 'plw_run_alerts' );

function plw_run_alerts() {
	$opt = plw_options();
	if ( '1' !== (string) $opt['alerts'] ) {
		return; // Alertas apagadas en Ajustes (por defecto).
	}
	$social = '@' . ( function_exists( 'pa_cuenta_social_domain' ) ? pa_cuenta_social_domain() : 'social.invalid' );
	$users  = get_users( array( 'meta_key' => 'plw_lists', 'number' => 2000 ) );
	foreach ( $users as $user ) {
		// Sin correo real (cuentas de X): saltar.
		if ( false !== strpos( $user->user_email, $social ) ) {
			continue;
		}
		// Opt-in: solo quien activó las alertas en su lista (🔔).
		if ( 'on' !== get_user_meta( $user->ID, 'plw_alerts', true ) ) {
			continue;
		}

		$ids  = plw_all_ids( plw_get_lists( $user->ID ) );
		$base = get_user_meta( $user->ID, 'plw_baseline', true );
		$base = is_array( $base ) ? $base : array();

		$drops    = array();
		$restocks = array();
		$newbase  = array();

		foreach ( $ids as $pid ) {
			$p = wc_get_product( $pid );
			if ( ! $p || 'publish' !== $p->get_status() ) {
				continue;
			}
			$price = (float) $p->get_price();
			$stock = $p->is_in_stock();
			$newbase[ $pid ] = array( 'p' => $price, 's' => $stock );

			// Primera observación: solo registrar, sin avisar.
			if ( ! isset( $base[ $pid ] ) ) {
				continue;
			}
			if ( $price > 0 && $base[ $pid ]['p'] > 0 && $price < $base[ $pid ]['p'] * 0.99 ) {
				$drops[] = array( 'p' => $p, 'antes' => (float) $base[ $pid ]['p'], 'ahora' => $price );
			}
			if ( empty( $base[ $pid ]['s'] ) && $stock ) {
				$restocks[] = $p;
			}
		}

		update_user_meta( $user->ID, 'plw_baseline', $newbase );

		if ( empty( $drops ) && empty( $restocks ) ) {
			continue;
		}

		// Privacidad: un sistema propio de consentimiento / bajas puede vetar
		// el envío con este filtro (true = se puede escribir).
		if ( ! apply_filters( 'plw_alert_allowed', true, $user ) ) {
			continue;
		}

		$tienda = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$color  = sanitize_hex_color( (string) $opt['color'] ) ?: '#333333';
		$nombre = get_user_meta( $user->ID, 'first_name', true ) ?: $user->display_name;
		$body   = '<div style="font-family:sans-serif;max-width:560px;margin:auto">';
		$body  .= '<h2 style="color:' . esc_attr( $color ) . '">Hola, ' . esc_html( $nombre ) . '</h2>';
		$body  .= '<p>Noticias de tu lista de deseos:</p>';
		foreach ( $drops as $d ) {
			$body .= '<p>📉 <a href="' . esc_url( $d['p']->get_permalink() ) . '" style="color:' . esc_attr( $color ) . ';font-weight:bold">' . esc_html( $d['p']->get_name() ) . '</a><br>'
				. 'Antes: <s>' . esc_html( wp_strip_all_tags( wc_price( $d['antes'] ) ) ) . '</s> → Ahora: <strong>' . esc_html( wp_strip_all_tags( wc_price( $d['ahora'] ) ) ) . '</strong></p>';
		}
		foreach ( $restocks as $p ) {
			$body .= '<p>📦 <a href="' . esc_url( $p->get_permalink() ) . '" style="color:' . esc_attr( $color ) . ';font-weight:bold">' . esc_html( $p->get_name() ) . '</a> ¡volvió a estar disponible!</p>';
		}
		$body .= '<p><a href="' . esc_url( plw_page_url() ?: home_url() ) . '" style="display:inline-block;background:' . esc_attr( $color ) . ';color:#fff;padding:10px 22px;border-radius:9px;text-decoration:none;font-weight:bold">Ver mi lista</a></p>';
		$body .= '<p style="font-size:12px;color:#888">Recibes esto porque activaste las alertas de tu lista de deseos en ' . esc_html( $tienda ) . '. Puedes apagarlas desde tu lista (🔔).</p>';
		$body .= (string) apply_filters( 'plw_alert_footer_html', '', $user );
		$body .= '</div>';

		$headers = (array) apply_filters( 'plw_alert_headers', array( 'Content-Type: text/html; charset=UTF-8' ), $user );
		$enviado = wp_mail(
			$user->user_email,
			count( $drops ) ? sprintf( '📉 ¡Bajó de precio un producto de tu lista! — %s', $tienda ) : sprintf( '📦 ¡Volvió un producto de tu lista! — %s', $tienda ),
			$body,
			$headers
		);
		if ( $enviado ) {
			do_action( 'plw_alert_sent', $user );
		}
	}
}

// ══════════════════════════════════════════════════════════════════════════════
// 6. AJUSTES ── wp-admin → Ajustes → Cuenta · Lista de deseos (+ Los más deseados).
// ══════════════════════════════════════════════════════════════════════════════

add_action( 'admin_menu', function () {
	add_options_page( 'Cuenta · Lista de deseos', 'Cuenta · Lista de deseos', 'manage_options', 'pa-la-wishlist', 'plw_options_page' );
} );

add_action( 'admin_init', function () {
	register_setting( 'plw_group', 'plw_options', array( 'sanitize_callback' => function ( $in ) {
		return array(
			'color'   => isset( $in['color'] ) ? (string) sanitize_hex_color( $in['color'] ) : '',
			'page_id' => isset( $in['page_id'] ) ? (int) $in['page_id'] : 0,
			'alerts'  => empty( $in['alerts'] ) ? '' : '1',
		);
	} ) );
} );

add_action( 'admin_enqueue_scripts', function ( $hook ) {
	if ( 'settings_page_pa-la-wishlist' !== $hook ) {
		return;
	}
	wp_enqueue_style( 'wp-color-picker' );
	wp_enqueue_script( 'wp-color-picker' );
	wp_add_inline_script( 'wp-color-picker', 'jQuery(function($){$(".plw-color").wpColorPicker();});' );
} );

function plw_options_page() {
	$o = plw_options();
	?>
	<div class="wrap">
		<h1>Cuenta · Lista de deseos</h1>
		<p>Corazón en tarjetas y fichas, listas de cada cliente y alertas de precio o disponibilidad (solo a quien las activa).</p>
		<form method="post" action="options.php">
			<?php settings_fields( 'plw_group' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="plw_color">Color</label></th>
					<td><input type="text" id="plw_color" class="plw-color" name="plw_options[color]" value="<?php echo esc_attr( $o['color'] ); ?>"><p class="description">Vacío = contraste del tema.</p></td>
				</tr>
				<tr>
					<th scope="row">Alertas por correo</th>
					<td><label><input type="checkbox" name="plw_options[alerts]" value="1" <?php checked( $o['alerts'], '1' ); ?>> Enviar alertas diarias de «bajó de precio» / «volvió a estar disponible»</label>
					<p class="description">Solo a quien las active en su lista (🔔). Revisa tu política de privacidad antes de encenderlas.</p></td>
				</tr>
				<tr>
					<th scope="row"><label for="plw_page">Página de la lista</label></th>
					<td>
						<?php wp_dropdown_pages( array( 'name' => 'plw_options[page_id]', 'id' => 'plw_page', 'selected' => (int) $o['page_id'], 'show_option_none' => '— Elegir —' ) ); ?>
						<p class="description">Página con el shortcode <code>[pa_la_wishlist]</code>. Los enlaces compartidos usan <code>?lista=TOKEN</code>.</p>
					</td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>

		<hr>
		<h2>Los más deseados</h2>
		<p class="description">Productos que más aparecen en las listas de tus clientes: útil para saber qué reponer y qué poner en oferta.</p>
		<?php
		$top = get_transient( 'plw_top_wished' );
		if ( false === $top ) {
			$counts = array();
			foreach ( get_users( array( 'meta_key' => 'plw_lists', 'number' => 2000 ) ) as $u ) {
				foreach ( plw_all_ids( plw_get_lists( $u->ID ) ) as $pid ) {
					$counts[ $pid ] = ( $counts[ $pid ] ?? 0 ) + 1;
				}
			}
			arsort( $counts );
			$top = array_slice( $counts, 0, 20, true );
			set_transient( 'plw_top_wished', $top, HOUR_IN_SECONDS );
		}
		if ( empty( $top ) ) {
			echo '<p>Aún no hay productos en listas.</p>';
		} else {
			echo '<table class="widefat striped" style="max-width:760px"><thead><tr><th>Producto</th><th>Deseado por</th><th>Precio</th><th>Stock</th></tr></thead><tbody>';
			foreach ( $top as $pid => $n ) {
				$p = wc_get_product( $pid );
				if ( ! $p ) {
					continue;
				}
				echo '<tr><td><a href="' . esc_url( get_edit_post_link( $pid ) ) . '">' . esc_html( $p->get_name() ) . '</a></td>'
					. '<td><strong>' . (int) $n . '</strong> cliente' . ( 1 === $n ? '' : 's' ) . '</td>'
					. '<td>' . wp_kses_post( wc_price( (float) $p->get_price() ) ) . '</td>'
					. '<td>' . ( $p->is_in_stock() ? '✅' : '❌ agotado' ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}
		?>
	</div>
	<?php
}
