<?php
/**
 * Módulo «Entrar» de Woo Cuenta Simple.
 * QUÉ HACE   Ventana propia de acceso y registro, entrar con Google (y con
 *            Facebook / X si se configuran), límite de intentos y trampa para
 *            bots, sección «Cuentas conectadas» en Mis datos y [pa_entrar].
 * DÓNDE SE NOTA  Enlace de acceso del encabezado, enlaces a Mi cuenta sin
 *            sesión, Mi cuenta → Detalles de la cuenta. Rutas REST lm-auth/v1/*.
 * AJUSTES    Ajustes → Cuenta · Entrar (opción pent_options). Las claves se
 *            pueden fijar también con constantes en wp-config.php (ver README).
 * SEGURIDAD  El token de Google se verifica en el servidor (firma vía Google,
 *            aud, iss, exp y email_verified). Una cuenta existente NUNCA se
 *            abre por correo con un proveedor que no garantiza el correo
 *            (Facebook, X) ni si tiene permisos de edición/administración.
 * CÓMO SE APAGA  update_option( 'pa_cuenta_entrar_off', 1 );  (queda el
 *            formulario normal de WooCommerce en Mi cuenta).
 * CÓMO SE DESHACE  delete_option( 'pa_cuenta_entrar_off' );
 *
 * Author: Andrés Valencia Tobón
 * Author URI: https://github.com/quijotevitruvio
 * LinkedIn: https://www.linkedin.com/in/andr%C3%A9s-valencia-tob%C3%B3n/
 * Donate link: https://paypal.me/bibliotecologo
 */

defined( 'ABSPATH' ) || exit;

define( 'PENT_VERSION', '1.3.1' );
define( 'PENT_DIR', plugin_dir_path( __FILE__ ) );
define( 'PENT_URL', plugin_dir_url( __FILE__ ) );


/**
 * Opciones con sus valores por defecto.
 */
function pent_options() {
	$defaults = array(
		'enabled'       => '1',
		'replace_links' => '1', // interceptar enlaces a Mi Cuenta cuando no hay sesión.
		'color'         => '', // vacío = contraste del tema.
		'google_id'     => '', // Client ID de OAuth de Google (no es secreto, pero es tuyo).
		'fb_id'         => '',
		'fb_secret'     => '',
		'x_id'          => '',
		'x_secret'      => '',
	);
	$opt = get_option( 'pent_options', array() );
	$opt = wp_parse_args( is_array( $opt ) ? $opt : array(), $defaults );
	// Constantes opcionales en wp-config.php: mandan sobre lo guardado en la
	// base de datos (así los secretos no viven en wp_options).
	$consts = array(
		'google_id' => 'PA_CUENTA_GOOGLE_CLIENT_ID',
		'fb_id'     => 'PA_CUENTA_FACEBOOK_APP_ID',
		'fb_secret' => 'PA_CUENTA_FACEBOOK_APP_SECRET',
		'x_id'      => 'PA_CUENTA_X_CLIENT_ID',
		'x_secret'  => 'PA_CUENTA_X_CLIENT_SECRET',
	);
	foreach ( $consts as $key => $const ) {
		if ( defined( $const ) && '' !== (string) constant( $const ) ) {
			$opt[ $key ] = (string) constant( $const );
		}
	}
	return $opt;
}

/** ¿Este sitio permite crear cuentas nuevas desde el frente? */
function pent_registration_allowed() {
	$allowed = 'yes' === get_option( 'woocommerce_enable_myaccount_registration' ) || (bool) get_option( 'users_can_register' );
	return (bool) apply_filters( 'pent_registration_allowed', $allowed );
}

/** ¿El usuario tiene permisos por encima de cliente? (a esas cuentas no se entra por red social sin vínculo previo). */
function pent_is_privileged( $user ) {
	$privileged = user_can( $user, 'edit_posts' ) || user_can( $user, 'manage_woocommerce' ) || user_can( $user, 'list_users' );
	return (bool) apply_filters( 'pent_is_privileged_user', $privileged, $user );
}

// ══════════════════════════════════════════════════════════════════════════════
// 1. SEGURIDAD ── Rate limit por IP+usuario y honeypot (reemplaza el addon).
// ══════════════════════════════════════════════════════════════════════════════

function pent_client_ip() {
	return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '0';
}

/** true = bloqueado (demasiados intentos). 5 intentos por 10 minutos. */
function pent_is_rate_limited( $bucket ) {
	$key = 'pent_rl_' . md5( $bucket . '|' . pent_client_ip() );
	return (int) get_transient( $key ) >= 5;
}

function pent_register_attempt( $bucket ) {
	$key = 'pent_rl_' . md5( $bucket . '|' . pent_client_ip() );
	$n   = (int) get_transient( $key );
	set_transient( $key, $n + 1, 10 * MINUTE_IN_SECONDS );
}

// Bloquear enumeración de usuarios por ?author=N.
add_action( 'template_redirect', function () {
	if ( ! is_admin() && isset( $_GET['author'] ) ) {
		wp_safe_redirect( home_url(), 301 );
		exit;
	}
} );

// ══════════════════════════════════════════════════════════════════════════════
// 2. HELPERS ── Buscar/crear cliente y abrir sesión.
// ══════════════════════════════════════════════════════════════════════════════

/**
 * Crea (o encuentra por email) un cliente Woo y devuelve WP_User|WP_Error.
 *
 * $email_verified: el proveedor GARANTIZA que el correo pertenece a quien
 * entra (Google con email_verified). Si no lo garantiza, una cuenta existente
 * con ese correo NO se abre: hay que entrar con la contraseña y conectar la
 * red desde Mis datos. Las cuentas con permisos de edición o administración
 * nunca se abren por correo desde una red social.
 */
function pent_find_or_create_user( $email, $name = '', $provider = '', $email_verified = false ) {
	$user = get_user_by( 'email', $email );
	if ( $user ) {
		if ( ! $provider ) {
			return $user;
		}
		if ( ! $email_verified || pent_is_privileged( $user ) ) {
			return new WP_Error( 'pent_link_required', 'Ya existe una cuenta con ese correo. Entra con tu contraseña y conecta la red desde los detalles de tu cuenta.', array( 'status' => 409 ) );
		}
		return $user;
	}
	if ( $provider && ! pent_registration_allowed() ) {
		return new WP_Error( 'pent_no_register', 'Este sitio no permite crear cuentas nuevas.', array( 'status' => 403 ) );
	}
	$base  = sanitize_user( current( explode( '@', $email ) ), true );
	$login = $base;
	$i     = 1;
	while ( username_exists( $login ) ) {
		$login = $base . $i++;
	}
	$uid = wp_create_user( $login, wp_generate_password( 24 ), $email );
	if ( is_wp_error( $uid ) ) {
		return $uid;
	}
	wp_update_user( array(
		'ID'           => $uid,
		'display_name' => $name ?: $login,
		'first_name'   => $name,
		'role'         => 'customer',
	) );
	if ( $provider ) {
		update_user_meta( $uid, 'pent_provider', $provider );
	}
	return get_user_by( 'id', $uid );
}

/** Primer usuario cuyo meta coincide (identidades sociales vinculadas). */
function pent_find_user_by_meta( $key, $value ) {
	if ( '' === (string) $value ) {
		return null;
	}
	$users = get_users( array( 'meta_key' => $key, 'meta_value' => $value, 'number' => 1 ) );
	return $users ? $users[0] : null;
}

/** URL de "Mis datos" en Mi cuenta. */
function pent_account_edit_url() {
	return function_exists( 'wc_get_account_endpoint_url' ) ? wc_get_account_endpoint_url( 'edit-account' ) : admin_url( 'profile.php' );
}

/** Abre sesión y redirige de vuelta a donde estaba el lector. */
function pent_login_and_redirect( $user, $redirect = '' ) {
	wp_set_current_user( $user->ID );
	wp_set_auth_cookie( $user->ID, true );
	do_action( 'wp_login', $user->user_login, $user );
	$to = $redirect ? esc_url_raw( $redirect ) : home_url();
	// Solo redirecciones internas (mismo host permitido).
	$to = wp_validate_redirect( $to, home_url() );
	wp_safe_redirect( $to );
	exit;
}

// ══════════════════════════════════════════════════════════════════════════════
// 3. REST ── lm-auth/v1: login, registro y callbacks sociales.
// ══════════════════════════════════════════════════════════════════════════════

add_action( 'rest_api_init', function () {
	$pub = array( 'permission_callback' => '__return_true' );

	register_rest_route( 'lm-auth/v1', '/login', $pub + array(
		'methods'  => 'POST',
		'callback' => 'pent_rest_login',
	) );
	register_rest_route( 'lm-auth/v1', '/register', $pub + array(
		'methods'  => 'POST',
		'callback' => 'pent_rest_register',
	) );
	register_rest_route( 'lm-auth/v1', '/google', $pub + array(
		'methods'  => 'POST',
		'callback' => 'pent_rest_google',
	) );
	register_rest_route( 'lm-auth/v1', '/facebook/start', $pub + array(
		'methods'  => 'GET',
		'callback' => 'pent_facebook_start',
	) );
	register_rest_route( 'lm-auth/v1', '/facebook/cb', $pub + array(
		'methods'  => 'GET',
		'callback' => 'pent_facebook_cb',
	) );
	register_rest_route( 'lm-auth/v1', '/x/start', $pub + array(
		'methods'  => 'GET',
		'callback' => 'pent_x_start',
	) );
	register_rest_route( 'lm-auth/v1', '/x/cb', $pub + array(
		'methods'  => 'GET',
		'callback' => 'pent_x_cb',
	) );
	// Desvincular una red de la cuenta (requiere sesión + X-WP-Nonce).
	register_rest_route( 'lm-auth/v1', '/unlink', array(
		'methods'             => 'POST',
		'permission_callback' => 'is_user_logged_in',
		'callback'            => function ( WP_REST_Request $req ) {
			$provider = sanitize_text_field( (string) $req->get_param( 'provider' ) );
			$uid      = get_current_user_id();
			$map      = array(
				'google'   => array( 'pent_google_email', 'pent_google_name' ),
				'facebook' => array( 'pent_fb_id', 'pent_fb_name' ),
				'x'        => array( 'pent_x_id', 'pent_x_username' ),
			);
			if ( ! isset( $map[ $provider ] ) ) {
				return new WP_Error( 'pent_bad', 'Proveedor inválido.', array( 'status' => 400 ) );
			}
			foreach ( $map[ $provider ] as $meta ) {
				delete_user_meta( $uid, $meta );
			}
			return array( 'ok' => true );
		},
	) );
} );

function pent_rest_login( WP_REST_Request $req ) {
	if ( pent_is_rate_limited( 'login' ) ) {
		return new WP_Error( 'pent_limited', 'Demasiados intentos. Espera 10 minutos y vuelve a probar. ☕', array( 'status' => 429 ) );
	}
	$creds = array(
		'user_login'    => sanitize_text_field( (string) $req->get_param( 'user' ) ),
		'user_password' => (string) $req->get_param( 'pass' ),
		'remember'      => true,
	);
	$user = wp_signon( $creds );
	if ( is_wp_error( $user ) ) {
		pent_register_attempt( 'login' );
		return new WP_Error( 'pent_bad_login', 'Usuario o contraseña incorrectos.', array( 'status' => 401 ) );
	}
	return array( 'ok' => true );
}

function pent_rest_register( WP_REST_Request $req ) {
	if ( pent_is_rate_limited( 'register' ) ) {
		return new WP_Error( 'pent_limited', 'Demasiados intentos. Espera 10 minutos. ☕', array( 'status' => 429 ) );
	}
	// Honeypot: el campo "website" está oculto; si llega lleno, es un bot.
	if ( '' !== (string) $req->get_param( 'website' ) ) {
		return new WP_Error( 'pent_bot', 'No pudimos crear la cuenta.', array( 'status' => 400 ) );
	}
	$email = sanitize_email( (string) $req->get_param( 'email' ) );
	$name  = sanitize_text_field( (string) $req->get_param( 'name' ) );
	$pass  = (string) $req->get_param( 'pass' );
	if ( ! is_email( $email ) || strlen( $pass ) < 8 ) {
		return new WP_Error( 'pent_invalid', 'Revisa el correo y usa una contraseña de mínimo 8 caracteres.', array( 'status' => 400 ) );
	}
	if ( ! pent_registration_allowed() ) {
		return new WP_Error( 'pent_no_register', 'Este sitio no permite crear cuentas nuevas.', array( 'status' => 403 ) );
	}
	if ( email_exists( $email ) ) {
		return new WP_Error( 'pent_exists', 'Ese correo ya tiene cuenta. Inicia sesión.', array( 'status' => 409 ) );
	}
	pent_register_attempt( 'register' );
	$user = pent_find_or_create_user( $email, $name );
	if ( is_wp_error( $user ) ) {
		return $user;
	}
	wp_set_password( $pass, $user->ID );
	wp_set_current_user( $user->ID );
	wp_set_auth_cookie( $user->ID, true );
	return array( 'ok' => true );
}

// ── Google: el botón GIS entrega un JWT; se verifica contra Google. ─────────
// El endpoint tokeninfo de Google comprueba la firma y la caducidad; aquí se
// exigen además aud (nuestro Client ID), iss (Google), exp y email_verified.
function pent_rest_google( WP_REST_Request $req ) {
	$opt        = pent_options();
	$credential = (string) $req->get_param( 'credential' );
	if ( ! $opt['google_id'] || ! $credential ) {
		return new WP_Error( 'pent_google', 'Google Sign-In no está configurado.', array( 'status' => 400 ) );
	}
	$resp = wp_remote_get( 'https://oauth2.googleapis.com/tokeninfo?id_token=' . rawurlencode( $credential ), array( 'timeout' => 10 ) );
	if ( is_wp_error( $resp ) ) {
		return new WP_Error( 'pent_google', 'No se pudo verificar con Google.', array( 'status' => 502 ) );
	}
	if ( 200 !== (int) wp_remote_retrieve_response_code( $resp ) ) {
		return new WP_Error( 'pent_google', 'Token de Google inválido.', array( 'status' => 401 ) );
	}
	$data     = json_decode( wp_remote_retrieve_body( $resp ), true );
	$data     = is_array( $data ) ? $data : array();
	$iss_ok   = in_array( $data['iss'] ?? '', array( 'accounts.google.com', 'https://accounts.google.com' ), true );
	$exp_ok   = isset( $data['exp'] ) && (int) $data['exp'] > time();
	$verified = in_array( $data['email_verified'] ?? '', array( true, 'true' ), true );
	if ( empty( $data['aud'] ) || ! hash_equals( (string) $opt['google_id'], (string) $data['aud'] ) || ! $iss_ok || ! $exp_ok || empty( $data['email'] ) || ! $verified ) {
		return new WP_Error( 'pent_google', 'Token de Google inválido.', array( 'status' => 401 ) );
	}
	$gmail = sanitize_email( $data['email'] );

	// Modo vínculo: lector con sesión conectando su Google desde Mis datos.
	if ( $req->get_param( 'link' ) && is_user_logged_in() ) {
		$dupe = pent_find_user_by_meta( 'pent_google_email', $gmail );
		if ( $dupe && (int) $dupe->ID !== get_current_user_id() ) {
			return new WP_Error( 'pent_taken', 'Ese Google ya está vinculado a otra cuenta.', array( 'status' => 409 ) );
		}
		update_user_meta( get_current_user_id(), 'pent_google_email', $gmail );
		update_user_meta( get_current_user_id(), 'pent_google_name', sanitize_text_field( $data['name'] ?? '' ) );
		return array( 'linked' => true, 'email' => $gmail );
	}

	// Login: primero por vínculo guardado, luego por email, luego se crea.
	$user = pent_find_user_by_meta( 'pent_google_email', $gmail );
	if ( ! $user ) {
		$user = pent_find_or_create_user( $gmail, sanitize_text_field( $data['name'] ?? '' ), 'google', true );
	}
	if ( is_wp_error( $user ) ) {
		return $user;
	}
	update_user_meta( $user->ID, 'pent_google_email', $gmail );
	wp_set_current_user( $user->ID );
	wp_set_auth_cookie( $user->ID, true );
	return array( 'ok' => true );
}

// ── Facebook: OAuth redirect clásico (necesita App Secret). ─────────────────
function pent_facebook_start() {
	$opt = pent_options();
	if ( ! $opt['fb_id'] || ! $opt['fb_secret'] ) {
		wp_die( 'Facebook Login no está configurado (falta App Secret).' );
	}
	$state = wp_generate_password( 24, false );
	// link=1 (+_wpnonce de wp_rest): vincular a la cuenta con sesión.
	$link_uid = ( isset( $_GET['link'] ) && is_user_logged_in() ) ? get_current_user_id() : 0;
	set_transient( 'pent_fb_' . $state, array(
		'back' => $link_uid ? pent_account_edit_url() : ( isset( $_GET['back'] ) ? esc_url_raw( wp_unslash( $_GET['back'] ) ) : home_url() ),
		'link' => $link_uid,
	), 10 * MINUTE_IN_SECONDS );
	$url = 'https://www.facebook.com/v19.0/dialog/oauth?' . http_build_query( array(
		'client_id'    => $opt['fb_id'],
		'redirect_uri' => rest_url( 'lm-auth/v1/facebook/cb' ),
		'state'        => $state,
		'scope'        => 'email,public_profile',
	) );
	wp_redirect( $url ); // phpcs:ignore WordPress.Security.SafeRedirect -- destino externo de OAuth.
	exit;
}

function pent_facebook_cb( WP_REST_Request $req ) {
	$opt   = pent_options();
	$state = sanitize_text_field( (string) $req->get_param( 'state' ) );
	$code  = (string) $req->get_param( 'code' );
	$saved = get_transient( 'pent_fb_' . $state );
	if ( false === $saved || ! $code ) {
		wp_safe_redirect( home_url() );
		exit;
	}
	delete_transient( 'pent_fb_' . $state );
	$saved = is_array( $saved ) ? $saved : array( 'back' => $saved, 'link' => 0 );
	$back  = $saved['back'];

	$token_resp = wp_remote_get( 'https://graph.facebook.com/v19.0/oauth/access_token?' . http_build_query( array(
		'client_id'     => $opt['fb_id'],
		'client_secret' => $opt['fb_secret'],
		'redirect_uri'  => rest_url( 'lm-auth/v1/facebook/cb' ),
		'code'          => $code,
	) ), array( 'timeout' => 10 ) );
	$token = json_decode( wp_remote_retrieve_body( $token_resp ), true )['access_token'] ?? '';
	if ( ! $token ) {
		wp_safe_redirect( home_url() );
		exit;
	}
	$me_resp = wp_remote_get( 'https://graph.facebook.com/v19.0/me?fields=name,email&access_token=' . rawurlencode( $token ), array( 'timeout' => 10 ) );
	$me      = json_decode( wp_remote_retrieve_body( $me_resp ), true );
	if ( empty( $me['id'] ) ) {
		wp_safe_redirect( home_url() );
		exit;
	}

	// Modo vínculo: guardar la identidad en la cuenta y volver a Mis datos.
	if ( ! empty( $saved['link'] ) ) {
		$dupe = pent_find_user_by_meta( 'pent_fb_id', $me['id'] );
		if ( $dupe && (int) $dupe->ID !== (int) $saved['link'] ) {
			wp_safe_redirect( add_query_arg( 'pent_link_error', 'facebook', pent_account_edit_url() ) );
			exit;
		}
		update_user_meta( (int) $saved['link'], 'pent_fb_id', sanitize_text_field( $me['id'] ) );
		update_user_meta( (int) $saved['link'], 'pent_fb_name', sanitize_text_field( $me['name'] ?? '' ) );
		wp_safe_redirect( add_query_arg( 'pent_linked', 'facebook', pent_account_edit_url() ) );
		exit;
	}

	// Login: primero por vínculo guardado, luego por email.
	$user = pent_find_user_by_meta( 'pent_fb_id', $me['id'] );
	if ( ! $user ) {
		if ( empty( $me['email'] ) ) {
			wp_die( 'Tu cuenta de Facebook no compartió el correo. Usa otro método de ingreso.' );
		}
		// Facebook no garantiza que el correo esté verificado: no abre cuentas
		// existentes por correo (solo por vínculo hecho desde Mis datos).
		$user = pent_find_or_create_user( sanitize_email( $me['email'] ), sanitize_text_field( $me['name'] ?? '' ), 'facebook', false );
	}
	if ( is_wp_error( $user ) ) {
		wp_die( esc_html( $user->get_error_message() ) );
	}
	update_user_meta( $user->ID, 'pent_fb_id', sanitize_text_field( $me['id'] ) );
	update_user_meta( $user->ID, 'pent_fb_name', sanitize_text_field( $me['name'] ?? '' ) );
	pent_login_and_redirect( $user, $back );
}

// ── X: OAuth 2.0 con PKCE. X no siempre entrega email → cuenta provisional. ─
function pent_x_start() {
	$opt = pent_options();
	if ( ! $opt['x_id'] ) {
		wp_die( 'X Login no está configurado.' );
	}
	$state    = wp_generate_password( 24, false );
	$verifier = wp_generate_password( 64, false );
	$link_uid = ( isset( $_GET['link'] ) && is_user_logged_in() ) ? get_current_user_id() : 0;
	set_transient( 'pent_x_' . $state, array(
		'verifier' => $verifier,
		'back'     => $link_uid ? pent_account_edit_url() : ( isset( $_GET['back'] ) ? esc_url_raw( wp_unslash( $_GET['back'] ) ) : home_url() ),
		'link'     => $link_uid,
	), 10 * MINUTE_IN_SECONDS );
	$challenge = rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );
	$url = 'https://twitter.com/i/oauth2/authorize?' . http_build_query( array(
		'response_type'         => 'code',
		'client_id'             => $opt['x_id'],
		'redirect_uri'          => rest_url( 'lm-auth/v1/x/cb' ),
		'scope'                 => 'users.read tweet.read',
		'state'                 => $state,
		'code_challenge'        => $challenge,
		'code_challenge_method' => 'S256',
	) );
	wp_redirect( $url ); // phpcs:ignore WordPress.Security.SafeRedirect
	exit;
}

function pent_x_cb( WP_REST_Request $req ) {
	$opt   = pent_options();
	$state = sanitize_text_field( (string) $req->get_param( 'state' ) );
	$code  = (string) $req->get_param( 'code' );
	$saved = get_transient( 'pent_x_' . $state );
	if ( ! $saved || ! $code ) {
		wp_safe_redirect( home_url() );
		exit;
	}
	delete_transient( 'pent_x_' . $state );

	$args = array(
		'timeout' => 10,
		'body'    => array(
			'grant_type'    => 'authorization_code',
			'code'          => $code,
			'client_id'     => $opt['x_id'],
			'redirect_uri'  => rest_url( 'lm-auth/v1/x/cb' ),
			'code_verifier' => $saved['verifier'],
		),
	);
	// Cliente confidencial: X exige Basic auth si la app tiene secret.
	if ( $opt['x_secret'] ) {
		$args['headers'] = array( 'Authorization' => 'Basic ' . base64_encode( $opt['x_id'] . ':' . $opt['x_secret'] ) );
	}
	$token_resp = wp_remote_post( 'https://api.twitter.com/2/oauth2/token', $args );
	$token      = json_decode( wp_remote_retrieve_body( $token_resp ), true )['access_token'] ?? '';
	if ( ! $token ) {
		wp_safe_redirect( home_url() );
		exit;
	}
	$me_resp = wp_remote_get( 'https://api.twitter.com/2/users/me', array(
		'timeout' => 10,
		'headers' => array( 'Authorization' => 'Bearer ' . $token ),
	) );
	$me = json_decode( wp_remote_retrieve_body( $me_resp ), true )['data'] ?? array();
	if ( empty( $me['id'] ) ) {
		wp_safe_redirect( home_url() );
		exit;
	}

	// Modo vínculo: guardar la identidad y volver a Mis datos.
	if ( ! empty( $saved['link'] ) ) {
		$dupe = pent_find_user_by_meta( 'pent_x_id', $me['id'] );
		if ( $dupe && (int) $dupe->ID !== (int) $saved['link'] ) {
			wp_safe_redirect( add_query_arg( 'pent_link_error', 'x', pent_account_edit_url() ) );
			exit;
		}
		update_user_meta( (int) $saved['link'], 'pent_x_id', sanitize_text_field( $me['id'] ) );
		update_user_meta( (int) $saved['link'], 'pent_x_username', sanitize_text_field( $me['username'] ?? '' ) );
		wp_safe_redirect( add_query_arg( 'pent_linked', 'x', pent_account_edit_url() ) );
		exit;
	}

	// Login: primero por vínculo guardado; si no, cuenta ligada al id de X
	// (X no entrega email; Woo lo pide en el primer checkout).
	$user = pent_find_user_by_meta( 'pent_x_id', $me['id'] );
	if ( ! $user ) {
		$email = 'x_' . preg_replace( '/[^0-9]/', '', (string) $me['id'] ) . '@' . pa_cuenta_social_domain();
		$user  = pent_find_or_create_user( $email, sanitize_text_field( $me['name'] ?? ( $me['username'] ?? 'Usuario de X' ) ), 'x', false );
	}
	if ( is_wp_error( $user ) ) {
		wp_die( esc_html( $user->get_error_message() ) );
	}
	update_user_meta( $user->ID, 'pent_x_id', sanitize_text_field( $me['id'] ) );
	update_user_meta( $user->ID, 'pent_x_username', sanitize_text_field( $me['username'] ?? '' ) );
	pent_login_and_redirect( $user, $saved['back'] );
}

// ══════════════════════════════════════════════════════════════════════════════
// 4. FRONTEND ── Modal (solo para visitantes sin sesión).
// ══════════════════════════════════════════════════════════════════════════════

add_action( 'wp_enqueue_scripts', function () {
	$opt = pent_options();
	if ( '1' !== $opt['enabled'] ) {
		return;
	}
	// El CSS carga siempre (estila también el enlace del header con sesión);
	// el modal y su JS solo sin sesión.
	wp_enqueue_style( 'pent-modal', PENT_URL . 'assets/modal.css', array(), PENT_VERSION );
	if ( $opt['color'] ) {
		wp_add_inline_style( 'pent-modal', ':root{--pent-accent:' . $opt['color'] . '}' );
	}
	if ( is_user_logged_in() ) {
		return;
	}
	wp_enqueue_script( 'pent-modal', PENT_URL . 'assets/modal.js', array(), PENT_VERSION, true );
	if ( $opt['google_id'] ) {
		wp_enqueue_script( 'pent-gis', 'https://accounts.google.com/gsi/client', array(), null, true );
	}
	wp_add_inline_script( 'pent-modal', 'window.pentCfg=' . wp_json_encode( array(
		'rest'         => esc_url_raw( rest_url( 'lm-auth/v1/' ) ),
		'googleId'     => $opt['google_id'],
		'facebookOn'   => (bool) ( $opt['fb_id'] && $opt['fb_secret'] ),
		'xOn'          => (bool) $opt['x_id'],
		'replaceLinks' => '1' === $opt['replace_links'],
		'accountUrl'   => function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : wp_login_url(),
	) ) . ';', 'before' );
} );

add_action( 'wp_footer', function () {
	$opt = pent_options();
	if ( '1' !== $opt['enabled'] || is_user_logged_in() ) {
		return;
	}
	?>
	<div id="pent-overlay" hidden></div>
	<div id="pent-modal" role="dialog" aria-modal="true" aria-label="Iniciar sesión" hidden>
		<button type="button" id="pent-close" aria-label="Cerrar">
			<svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor" aria-hidden="true"><path d="M19 6.41 17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>
		</button>
		<h3 class="pent-title"><?php echo esc_html( apply_filters( 'pent_modal_title', 'Te damos la bienvenida' ) ); ?></h3>
		<p class="pent-sub"><?php echo esc_html( apply_filters( 'pent_modal_subtitle', 'Entra o crea tu cuenta' ) ); ?></p>

		<div class="pent-social">
			<div id="pent-google"></div>
			<a class="pent-btn-social pent-fb" href="#" data-provider="facebook" hidden>
				<svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor" aria-hidden="true"><path d="M24 12.07C24 5.41 18.63 0 12 0S0 5.4 0 12.07C0 18.1 4.39 23.09 10.13 24v-8.44H7.08v-3.49h3.04V9.41c0-3.02 1.8-4.7 4.54-4.7 1.31 0 2.68.24 2.68.24v2.97h-1.5c-1.5 0-1.96.93-1.96 1.89v2.26h3.32l-.53 3.5h-2.8V24C19.62 23.1 24 18.1 24 12.07z"/></svg>
				Continuar con Facebook
			</a>
			<a class="pent-btn-social pent-x" href="#" data-provider="x" hidden>
				<svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor" aria-hidden="true"><path d="M18.9 1.15h3.68l-8.04 9.19L24 22.85h-7.4l-5.8-7.58-6.64 7.58H.46l8.6-9.83L0 1.15h7.59l5.24 6.93 6.07-6.93zm-1.29 19.5h2.04L6.49 3.24H4.3l13.31 17.41z"/></svg>
				Continuar con X
			</a>
		</div>

		<div class="pent-sep"><span>o con tu correo</span></div>

		<div class="pent-tabs">
			<button type="button" class="pent-tab is-active" data-panel="login">Entrar</button>
			<button type="button" class="pent-tab" data-panel="register">Crear cuenta</button>
		</div>

		<form id="pent-form-login" class="pent-panel" data-panel="login">
			<input type="text" name="user" placeholder="Correo o usuario" required autocomplete="username">
			<input type="password" name="pass" placeholder="Contraseña" required autocomplete="current-password">
			<button type="submit" class="pent-btn-primary">Entrar</button>
			<a class="pent-forgot" href="<?php echo esc_url( wp_lostpassword_url() ); ?>">¿Olvidaste la contraseña?</a>
		</form>

		<form id="pent-form-register" class="pent-panel" data-panel="register" hidden>
			<input type="text" name="name" placeholder="Tu nombre" autocomplete="name">
			<input type="email" name="email" placeholder="Correo" required autocomplete="email">
			<input type="password" name="pass" placeholder="Contraseña (mínimo 8)" required minlength="8" autocomplete="new-password">
			<!-- honeypot: invisible para humanos -->
			<input type="text" name="website" class="pent-hp" tabindex="-1" autocomplete="off" aria-hidden="true">
			<button type="submit" class="pent-btn-primary">Crear mi cuenta</button>
		</form>

		<p id="pent-msg" role="alert" hidden></p>
	</div>
	<?php
} );

// ══════════════════════════════════════════════════════════════════════════════
// 4b. CUENTAS CONECTADAS ── Sección en "Mis datos" para vincular redes.
// ══════════════════════════════════════════════════════════════════════════════

add_action( 'woocommerce_edit_account_form', function () {
	$opt   = pent_options();
	$uid   = get_current_user_id();
	$nonce = wp_create_nonce( 'wp_rest' );

	$google = get_user_meta( $uid, 'pent_google_email', true );
	$fb     = get_user_meta( $uid, 'pent_fb_name', true ) ?: ( get_user_meta( $uid, 'pent_fb_id', true ) ? 'Conectada' : '' );
	$x      = get_user_meta( $uid, 'pent_x_username', true ) ?: ( get_user_meta( $uid, 'pent_x_id', true ) ? 'Conectada' : '' );

	$fb_url = add_query_arg( array( 'link' => 1, '_wpnonce' => $nonce ), rest_url( 'lm-auth/v1/facebook/start' ) );
	$x_url  = add_query_arg( array( 'link' => 1, '_wpnonce' => $nonce ), rest_url( 'lm-auth/v1/x/start' ) );
	?>
	<fieldset class="pent-links-field">
		<legend>Cuentas conectadas</legend>
		<p class="pent-links-hint">Conecta tus redes y entra con un solo clic la próxima vez.</p>

		<div class="pent-link-row">
			<span class="pent-link-net">Google</span>
			<?php if ( $google ) : ?>
				<span class="pent-link-status">✅ <?php echo esc_html( $google ); ?></span>
				<button type="button" class="pent-unlink" data-provider="google">Desconectar</button>
			<?php elseif ( $opt['google_id'] ) : ?>
				<div id="pent-link-google"></div>
			<?php else : ?>
				<span class="pent-link-status">No disponible</span>
			<?php endif; ?>
		</div>

		<div class="pent-link-row">
			<span class="pent-link-net">Facebook</span>
			<?php if ( $fb ) : ?>
				<span class="pent-link-status">✅ <?php echo esc_html( $fb ); ?></span>
				<button type="button" class="pent-unlink" data-provider="facebook">Desconectar</button>
			<?php elseif ( $opt['fb_id'] && $opt['fb_secret'] ) : ?>
				<a class="pent-link-connect" href="<?php echo esc_url( $fb_url ); ?>">Conectar</a>
			<?php else : ?>
				<span class="pent-link-status">No disponible</span>
			<?php endif; ?>
		</div>

		<div class="pent-link-row">
			<span class="pent-link-net">X</span>
			<?php if ( $x ) : ?>
				<span class="pent-link-status">✅ @<?php echo esc_html( ltrim( $x, '@' ) ); ?></span>
				<button type="button" class="pent-unlink" data-provider="x">Desconectar</button>
			<?php elseif ( $opt['x_id'] ) : ?>
				<a class="pent-link-connect" href="<?php echo esc_url( $x_url ); ?>">Conectar</a>
			<?php else : ?>
				<span class="pent-link-status">No disponible</span>
			<?php endif; ?>
		</div>
	</fieldset>
	<script>
	(function () {
		var rest = <?php echo wp_json_encode( esc_url_raw( rest_url( 'lm-auth/v1/' ) ) ); ?>;
		var nonce = <?php echo wp_json_encode( $nonce ); ?>;

		// Desconectar.
		document.querySelectorAll('.pent-unlink').forEach(function (b) {
			b.addEventListener('click', function () {
				if (!confirm('¿Desconectar esta red de tu cuenta?')) { return; }
				fetch(rest + 'unlink', {
					method: 'POST',
					credentials: 'same-origin',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
					body: JSON.stringify({ provider: b.dataset.provider })
				}).then(function () { location.reload(); });
			});
		});

		// Conectar Google (botón oficial GIS en modo vínculo).
		var slot = document.getElementById('pent-link-google');
		function initG() {
			if (!slot || !window.google || !google.accounts) { return false; }
			google.accounts.id.initialize({
				client_id: <?php echo wp_json_encode( $opt['google_id'] ); ?>,
				callback: function (resp) {
					fetch(rest + 'google?link=1', {
						method: 'POST',
						credentials: 'same-origin',
						headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
						body: JSON.stringify({ credential: resp.credential, link: 1 })
					}).then(function (r) { return r.json(); }).then(function (d) {
						if (d.linked) { location.reload(); } else { alert(d.message || 'No se pudo vincular.'); }
					});
				}
			});
			google.accounts.id.renderButton(slot, { theme: 'outline', size: 'medium', text: 'continue_with', shape: 'pill', locale: 'es' });
			return true;
		}
		if (!initG()) {
			var tries = 0;
			var t = setInterval(function () { if (initG() || ++tries > 20) { clearInterval(t); } }, 300);
		}
	})();
	</script>
	<?php
} );

// El script de Google también para logueados en "Mis datos".
add_action( 'wp_enqueue_scripts', function () {
	$opt = pent_options();
	if ( '1' === $opt['enabled'] && $opt['google_id'] && is_user_logged_in() && function_exists( 'is_account_page' ) && is_account_page() ) {
		wp_enqueue_script( 'pent-gis', 'https://accounts.google.com/gsi/client', array(), null, true );
	}
}, 20 );

// Avisos al volver del OAuth de vínculo.
add_action( 'template_redirect', function () {
	if ( ! function_exists( 'is_account_page' ) || ! is_account_page() || ! function_exists( 'wc_add_notice' ) ) {
		return;
	}
	if ( isset( $_GET['pent_linked'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		wc_add_notice( '¡Cuenta conectada! Ya puedes entrar con un clic.', 'success' );
	}
	if ( isset( $_GET['pent_link_error'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		wc_add_notice( 'Esa red ya está vinculada a otra cuenta de este sitio.', 'error' );
	}
} );

// ══════════════════════════════════════════════════════════════════════════════
// 5. SHORTCODE ── [pa_entrar] para el encabezado.
//    Sin sesión: abre el modal. Con sesión: saludo + salir.
// ══════════════════════════════════════════════════════════════════════════════
add_shortcode( 'pa_entrar', function ( $atts ) {
	$a = shortcode_atts( array(
		'texto'     => 'Hola,', // línea de arriba.
		'texto2'    => '', // línea de abajo, junto a la acción.
		'accion'    => 'Entrar', // enlace que abre la ventana de acceso.
		'saludo'    => 'Hola, %s', // línea de arriba; %s = nombre (en negrita).
		'salir'     => '', // línea de abajo.
		'accsalir'  => 'Salir', // enlace de cierre de sesión.
		'clase'     => '',
	), $atts, 'pa_entrar' );

	if ( is_user_logged_in() ) {
		$user   = wp_get_current_user();
		$nombre = $user->first_name ?: $user->display_name;
		$cuenta = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : admin_url( 'profile.php' );
		// Solo son clic: avatar + nombre (→ Mi cuenta) y "(Salir)". Resto, texto.
		$avatar = get_avatar( $user->ID, 56, '', $nombre, array( 'class' => 'pent-avatar' ) );
		$saludo = sprintf(
			esc_html( $a['saludo'] ),
			'<a class="pent-header-account" href="' . esc_url( $cuenta ) . '">' . $avatar . '<strong>' . esc_html( $nombre ) . '</strong></a>'
		);
		return '<span class="pent-header ' . esc_attr( $a['clase'] ) . '">'
			. '<span class="pent-line1">' . $saludo . '</span>'
			. '<span class="pent-line2">' . esc_html( $a['salir'] )
			. ' <a class="pent-header-logout" href="' . esc_url( wp_logout_url( home_url() ) ) . '"><strong>' . esc_html( $a['accsalir'] ) . '</strong></a></span>'
			. '</span>';
	}
	// Solo "(Accede)" es clic; el saludo es texto plano.
	return '<span class="pent-header ' . esc_attr( $a['clase'] ) . '">'
		. '<span class="pent-line1">' . esc_html( $a['texto'] ) . '</span>'
		. '<span class="pent-line2">' . esc_html( $a['texto2'] )
		. ' <a href="#" class="pent-login-tgr"><strong>' . esc_html( $a['accion'] ) . '</strong></a></span>'
		. '</span>';
} );

// ══════════════════════════════════════════════════════════════════════════════
// 6. AJUSTES ── wp-admin → Ajustes → Cuenta · Entrar
// ══════════════════════════════════════════════════════════════════════════════

add_action( 'admin_menu', function () {
	add_options_page( 'Cuenta · Entrar', 'Cuenta · Entrar', 'manage_options', 'pa-entrar', 'pent_options_page' );
} );

add_action( 'admin_init', function () {
	register_setting( 'pent_group', 'pent_options', array( 'sanitize_callback' => 'pent_sanitize' ) );
} );

function pent_sanitize( $input ) {
	return array(
		'enabled'       => empty( $input['enabled'] ) ? '' : '1',
		'replace_links' => empty( $input['replace_links'] ) ? '' : '1',
		'color'         => isset( $input['color'] ) ? (string) sanitize_hex_color( $input['color'] ) : '',
		'google_id'     => isset( $input['google_id'] ) ? sanitize_text_field( $input['google_id'] ) : '',
		'fb_id'         => isset( $input['fb_id'] ) ? sanitize_text_field( $input['fb_id'] ) : '',
		'fb_secret'     => isset( $input['fb_secret'] ) ? sanitize_text_field( $input['fb_secret'] ) : '',
		'x_id'          => isset( $input['x_id'] ) ? sanitize_text_field( $input['x_id'] ) : '',
		'x_secret'      => isset( $input['x_secret'] ) ? sanitize_text_field( $input['x_secret'] ) : '',
	);
}

add_action( 'admin_enqueue_scripts', function ( $hook ) {
	if ( 'settings_page_pa-entrar' !== $hook ) {
		return;
	}
	wp_enqueue_style( 'wp-color-picker' );
	wp_enqueue_script( 'wp-color-picker' );
	wp_add_inline_script( 'wp-color-picker', 'jQuery(function($){$(".pent-color").wpColorPicker();});' );
} );

function pent_options_page() {
	$o = pent_options();
	?>
	<div class="wrap">
		<h1>Cuenta · Entrar</h1>
		<p>Cómo entran los clientes: ventana propia, Google y, si se llenan sus claves, Facebook y X.</p>
		<p class="description">Recomendado: define los secretos en <code>wp-config.php</code> (<code>PA_CUENTA_FACEBOOK_APP_SECRET</code>, <code>PA_CUENTA_X_CLIENT_SECRET</code>…) en vez de guardarlos aquí; las constantes mandan sobre estos campos.</p>
		<form method="post" action="options.php">
			<?php settings_fields( 'pent_group' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">General</th>
					<td>
						<label><input type="checkbox" name="pent_options[enabled]" value="1" <?php checked( $o['enabled'], '1' ); ?>> Activar el modal de login</label><br>
						<label><input type="checkbox" name="pent_options[replace_links]" value="1" <?php checked( $o['replace_links'], '1' ); ?>> Interceptar enlaces a "Mi cuenta" cuando no hay sesión</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="pent_color">Color</label></th>
					<td><input type="text" id="pent_color" class="pent-color" name="pent_options[color]" value="<?php echo esc_attr( $o['color'] ); ?>"><p class="description">Vacío = contraste del tema.</p></td>
				</tr>
				<tr>
					<th scope="row"><label for="pent_google">Google Client ID</label></th>
					<td><input type="text" id="pent_google" class="large-text" name="pent_options[google_id]" value="<?php echo esc_attr( $o['google_id'] ); ?>">
					<p class="description">En Google Cloud Console, agrega <code><?php echo esc_html( home_url() ); ?></code> a "Orígenes de JavaScript autorizados".</p></td>
				</tr>
				<tr>
					<th scope="row"><label for="pent_fb_id">Facebook App ID</label></th>
					<td><input type="text" id="pent_fb_id" class="regular-text" name="pent_options[fb_id]" value="<?php echo esc_attr( $o['fb_id'] ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="pent_fb_secret">Facebook App Secret</label></th>
					<td><input type="password" id="pent_fb_secret" class="regular-text" name="pent_options[fb_secret]" value="<?php echo esc_attr( $o['fb_secret'] ); ?>">
					<p class="description">Meta for Developers → tu app → Configuración básica. URI de redirección válida: <code><?php echo esc_html( rest_url( 'lm-auth/v1/facebook/cb' ) ); ?></code>. El botón de Facebook solo aparece cuando este campo está lleno.</p></td>
				</tr>
				<tr>
					<th scope="row"><label for="pent_x_id">X Client ID</label></th>
					<td><input type="text" id="pent_x_id" class="regular-text" name="pent_options[x_id]" value="<?php echo esc_attr( $o['x_id'] ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="pent_x_secret">X Client Secret</label></th>
					<td><input type="password" id="pent_x_secret" class="regular-text" name="pent_options[x_secret]" value="<?php echo esc_attr( $o['x_secret'] ); ?>">
					<p class="description">X Developer Portal → OAuth 2.0. Callback URI: <code><?php echo esc_html( rest_url( 'lm-auth/v1/x/cb' ) ); ?></code>. El botón de X solo aparece cuando hay Client ID.</p></td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>
	</div>
	<?php
}
