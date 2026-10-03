<?php
/**
 * Plugin Name: Woo Cuenta Simple
 * Description: La cuenta del cliente de WooCommerce en un solo plugin: entrar (correo, Google y opcionalmente Facebook / X), «Mi cuenta» con progreso de pedidos y lista de deseos con varias listas.
 * Version: 1.0.1
 * Author: Andrés Valencia Tobón
 * Author URI: https://github.com/quijotevitruvio
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: woo-cuenta-simple
 * Requires at least: 6.3
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * WC requires at least: 8.0
 *
 * LinkedIn: https://www.linkedin.com/in/andr%C3%A9s-valencia-tob%C3%B3n/
 * Donate link: https://paypal.me/bibliotecologo
 *
 * QUÉ HACE   Tres módulos independientes: Entrar, Lista de deseos y Mi cuenta.
 * CÓMO SE APAGA  Un módulo:  update_option( 'pa_cuenta_entrar_off', 1 );
 *                            update_option( 'pa_cuenta_wishlist_off', 1 );
 *                            update_option( 'pa_cuenta_micuenta_off', 1 );
 *                Todo: desactivar este plugin.
 * CÓMO SE DESHACE  Borrar la opción (o poner 0) y el módulo vuelve.
 * AJUSTES    Ajustes → Cuenta · Entrar,  Ajustes → Cuenta · Lista de deseos  y
 *            Ajustes → Cuenta · Mi cuenta.
 *
 * Desarrollado originalmente para librosmedellin.com.
 */

defined( 'ABSPATH' ) || exit;

define( 'PA_CUENTA_VERSION', '1.0.1' );

// WooCommerce: compatible con pedidos en tablas propias (HPOS).
add_action( 'before_woocommerce_init', function () {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
} );

/**
 * Dominio técnico para las cuentas creadas con X (X no entrega correo).
 * Por defecto «social.<dominio del sitio>». Se puede fijar con la constante
 * PA_CUENTA_SOCIAL_DOMAIN o el filtro 'pa_cuenta_social_domain'.
 */
function pa_cuenta_social_domain() {
	if ( defined( 'PA_CUENTA_SOCIAL_DOMAIN' ) && PA_CUENTA_SOCIAL_DOMAIN ) {
		$domain = (string) PA_CUENTA_SOCIAL_DOMAIN;
	} else {
		$host   = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$domain = 'social.' . ( $host ? $host : 'localhost' );
	}
	return (string) apply_filters( 'pa_cuenta_social_domain', $domain );
}

// Entrar: ventana de acceso, Google/Facebook/X, rutas REST.
if ( ! get_option( 'pa_cuenta_entrar_off' ) && ! function_exists( 'pent_options' ) ) {
	require_once __DIR__ . '/modulos/entrar/entrar.php';
}

// Lista de deseos: corazones, listas, alertas por correo.
if ( ! get_option( 'pa_cuenta_wishlist_off' ) && ! function_exists( 'plw_options' ) ) {
	require_once __DIR__ . '/modulos/wishlist/wishlist.php';
}

// Mi cuenta: menú, escritorio, pedidos, Mis listas, avatar.
if ( ! get_option( 'pa_cuenta_micuenta_off' ) && ! function_exists( 'pmc_wishlist_count' ) ) {
	require_once __DIR__ . '/modulos/mi-cuenta/mi-cuenta.php';
}

// Al activar: agendar las alertas de la lista y registrar /mis-listas/.
register_activation_hook( __FILE__, function () {
	if ( ! get_option( 'pa_cuenta_wishlist_off' ) && ! wp_next_scheduled( 'plw_daily_alerts' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'plw_daily_alerts' );
	}
	if ( ! get_option( 'pa_cuenta_micuenta_off' ) ) {
		add_rewrite_endpoint( 'mis-listas', EP_ROOT | EP_PAGES );
	}
	flush_rewrite_rules();
} );

// Al desactivar: quitar el cron de alertas y vaciar las reglas de enlaces.
register_deactivation_hook( __FILE__, function () {
	wp_clear_scheduled_hook( 'plw_daily_alerts' );
	flush_rewrite_rules();
} );
