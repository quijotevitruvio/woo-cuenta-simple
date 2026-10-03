<?php
/**
 * Módulo «Mi cuenta» de Woo Cuenta Simple.
 * QUÉ HACE   Menú con etiquetas editables, escritorio con tarjetas, pedidos
 *            con progreso y «volver a pedir», pestaña Mis listas (endpoint
 *            mis-listas), foto de perfil subida por el cliente.
 * DÓNDE SE NOTA  Mi cuenta con sesión iniciada; avatares en todo el sitio.
 * AJUSTES    Ajustes → Cuenta · Mi cuenta (opción pmc_options).
 * GANCHOS    do_action( 'pmc_dashboard_after_cards', $user, $last_order ) para
 *            añadir recomendaciones u otros bloques bajo las tarjetas.
 * CÓMO SE APAGA  update_option( 'pa_cuenta_micuenta_off', 1 );  y guardar
 *            Ajustes → Enlaces permanentes (quita /mis-listas/).
 * CÓMO SE DESHACE  delete_option( 'pa_cuenta_micuenta_off' );  y guardar
 *            Enlaces permanentes.
 */

defined( 'ABSPATH' ) || exit;

define( 'PMC_VERSION', '1.1.1' );
define( 'PMC_URL', plugin_dir_url( __FILE__ ) );

/** Opciones con sus valores por defecto (vacío = etiqueta de WooCommerce / tarjeta oculta). */
function pmc_options() {
	$defaults = array(
		'tagline'         => '', // subtítulo del saludo del escritorio.
		'whatsapp'        => '', // número en formato internacional, solo dígitos; vacío = sin tarjeta.
		'whatsapp_text'   => 'Hola, necesito ayuda con mi cuenta',
		'label_dashboard' => '',
		'label_orders'    => '',
		'label_lists'     => 'Mis listas',
		'label_addresses' => '',
		'label_account'   => '',
		'label_logout'    => '',
	);
	$opt = get_option( 'pmc_options', array() );
	return wp_parse_args( is_array( $opt ) ? $opt : array(), $defaults );
}

/** Subcarpeta de uploads para las fotos de perfil. */
function pmc_avatar_subdir() {
	return trim( (string) apply_filters( 'pmc_avatar_subdir', 'pa-cuenta-avatares' ), '/' );
}


// ══════════════════════════════════════════════════════════════════════════════
// 1. MENÚ ── Voz propia + pestaña de listas de deseos.
// ══════════════════════════════════════════════════════════════════════════════

add_filter( 'woocommerce_account_menu_items', function ( $items ) {
	$o       = pmc_options();
	$map     = array(
		'dashboard'       => $o['label_dashboard'],
		'orders'          => $o['label_orders'],
		'edit-address'    => $o['label_addresses'],
		'edit-account'    => $o['label_account'],
		'customer-logout' => $o['label_logout'],
	);
	$lists   = '' !== $o['label_lists'] ? $o['label_lists'] : 'Mis listas';
	$renamed = array();
	foreach ( $items as $key => $label ) {
		$renamed[ $key ] = ( isset( $map[ $key ] ) && '' !== $map[ $key ] ) ? $map[ $key ] : $label;
		if ( 'orders' === $key ) {
			// La pestaña de listas va justo después de los pedidos.
			$renamed['mis-listas'] = $lists;
		}
	}
	if ( ! isset( $renamed['mis-listas'] ) ) {
		$renamed['mis-listas'] = $lists;
	}
	return $renamed;
} );

// Endpoint /mi-cuenta/mis-listas/ → renderiza la wishlist adentro.
add_action( 'init', function () {
	add_rewrite_endpoint( 'mis-listas', EP_ROOT | EP_PAGES );
} );

// Activar / desactivar (vaciar reglas): lo hace el archivo principal de Pa-cuenta.

add_action( 'woocommerce_account_mis-listas_endpoint', function () {
	if ( shortcode_exists( 'pa_la_wishlist' ) ) {
		echo do_shortcode( '[pa_la_wishlist]' ); // phpcs:ignore WordPress.Security.EscapeOutput
	} else {
		echo '<p>Las listas de deseos no están disponibles ahora mismo.</p>';
	}
} );

// ══════════════════════════════════════════════════════════════════════════════
// 2. ESCRITORIO ── Tarjetas útiles + recomendaciones.
// ══════════════════════════════════════════════════════════════════════════════

/** Conteo de libros en todas las listas de deseos del usuario. */
function pmc_wishlist_count( $user_id ) {
	if ( ! function_exists( 'plw_get_lists' ) ) {
		return 0;
	}
	return count( plw_all_ids( plw_get_lists( $user_id ) ) );
}

/** Mapa estado Woo → [paso 1-3, etiqueta]. */
function pmc_order_progress( $status ) {
	switch ( $status ) {
		case 'pending':
		case 'on-hold':
			return array( 1, 'Recibido' );
		case 'processing':
			return array( 2, 'En preparación' );
		case 'completed':
			return array( 3, 'Completado' );
		case 'cancelled':
		case 'refunded':
		case 'failed':
			return array( 0, 'Cancelado' );
		default:
			return array( 1, function_exists( 'wc_get_order_status_name' ) ? wc_get_order_status_name( $status ) : ucfirst( $status ) );
	}
}

function pmc_progress_bar( $order ) {
	list( $step, $label ) = pmc_order_progress( $order->get_status() );
	if ( 0 === $step ) {
		return '<span class="pmc-cancelled">' . esc_html( $label ) . '</span>';
	}
	$out = '<div class="pmc-progress" title="' . esc_attr( $label ) . '"><div class="pmc-progress-track">';
	for ( $i = 1; $i <= 3; $i++ ) {
		$out .= '<span class="pmc-dot' . ( $i <= $step ? ' is-on' : '' ) . '"></span>';
		if ( $i < 3 ) {
			$out .= '<span class="pmc-line' . ( $i < $step ? ' is-on' : '' ) . '"></span>';
		}
	}
	$out .= '</div><span class="pmc-progress-label">' . esc_html( $label ) . '</span></div>';
	return $out;
}

add_action( 'woocommerce_account_dashboard', function () {
	$user   = wp_get_current_user();
	$nombre = $user->first_name ?: $user->display_name;

	// Último pedido.
	$orders = wc_get_orders( array( 'customer_id' => $user->ID, 'limit' => 1, 'orderby' => 'date', 'order' => 'DESC' ) );
	$last   = $orders ? $orders[0] : null;

	$wl_count = pmc_wishlist_count( $user->ID );
	$wl_url   = esc_url( wc_get_account_endpoint_url( 'mis-listas' ) );
	$o        = pmc_options();
	$wa       = preg_replace( '/[^0-9]/', '', (string) $o['whatsapp'] );
	?>
	<div class="pmc-dash">
		<div class="pmc-hello">
			<?php echo get_avatar( $user->ID, 96, '', $nombre, array( 'class' => 'pmc-avatar' ) ); ?>
			<div>
				<h3>Hola, <?php echo esc_html( $nombre ); ?></h3>
				<?php if ( '' !== $o['tagline'] ) : ?>
					<p><?php echo esc_html( $o['tagline'] ); ?></p>
				<?php endif; ?>
			</div>
		</div>

		<div class="pmc-cards">
			<?php if ( $last ) : ?>
			<a class="pmc-card" href="<?php echo esc_url( $last->get_view_order_url() ); ?>">
				<span class="pmc-card-title">Tu último pedido</span>
				<span class="pmc-card-big">#<?php echo esc_html( $last->get_order_number() ); ?> · <?php echo wp_kses_post( wc_price( $last->get_total() ) ); ?></span>
				<?php echo pmc_progress_bar( $last ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			</a>
			<?php else : ?>
			<a class="pmc-card" href="<?php echo esc_url( get_permalink( wc_get_page_id( 'shop' ) ) ); ?>">
				<span class="pmc-card-title">Aún no tienes pedidos</span>
				<span class="pmc-card-big">Ir a la tienda</span>
			</a>
			<?php endif; ?>

			<a class="pmc-card" href="<?php echo $wl_url; // phpcs:ignore WordPress.Security.EscapeOutput ?>">
				<span class="pmc-card-title">Mis listas de deseos</span>
				<span class="pmc-card-big"><?php echo (int) $wl_count; ?> producto<?php echo 1 === $wl_count ? '' : 's'; ?> guardado<?php echo 1 === $wl_count ? '' : 's'; ?></span>
				<span class="pmc-card-link">Ver mis listas →</span>
			</a>

			<a class="pmc-card" href="<?php echo esc_url( wc_get_account_endpoint_url( 'edit-address' ) ); ?>">
				<span class="pmc-card-title">Mis direcciones</span>
				<span class="pmc-card-big">¿A dónde llegan tus pedidos?</span>
			</a>

			<?php if ( $wa ) : ?>
			<a class="pmc-card" href="<?php echo esc_url( 'https://wa.me/' . $wa . '?text=' . rawurlencode( (string) $o['whatsapp_text'] ) ); ?>" target="_blank" rel="noopener">
				<span class="pmc-card-title">¿Necesitas ayuda?</span>
				<span class="pmc-card-big">Escríbenos por WhatsApp</span>
			</a>
			<?php endif; ?>
		</div>

		<?php
		// Punto de extensión: recomendaciones u otros bloques.
		do_action( 'pmc_dashboard_after_cards', $user, $last );
		?>
	</div>
	<?php
}, 5 );

// ══════════════════════════════════════════════════════════════════════════════
// 3. PEDIDOS ── Progreso en la lista + "Volver a pedir".
// ══════════════════════════════════════════════════════════════════════════════

// Columna de progreso en la tabla de pedidos.
add_filter( 'woocommerce_my_account_my_orders_columns', function ( $columns ) {
	$new = array();
	foreach ( $columns as $key => $label ) {
		$new[ $key ] = $label;
		if ( 'order-status' === $key ) {
			$new['pmc-progress'] = 'Progreso';
		}
	}
	return $new;
} );

add_action( 'woocommerce_my_account_my_orders_column_pmc-progress', function ( $order ) {
	echo pmc_progress_bar( $order ); // phpcs:ignore WordPress.Security.EscapeOutput
} );

// Botón "Volver a pedir" en las acciones de cada pedido.
add_filter( 'woocommerce_my_account_my_orders_actions', function ( $actions, $order ) {
	if ( in_array( $order->get_status(), array( 'completed', 'processing' ), true ) ) {
		$actions['pmc-reorder'] = array(
			'url'  => wp_nonce_url( admin_url( 'admin-post.php?action=pmc_reorder&order=' . $order->get_id() ), 'pmc_reorder_' . $order->get_id() ),
			'name' => 'Volver a pedir',
		);
	}
	return $actions;
}, 10, 2 );

// Mete los productos del pedido al carrito y manda al carrito.
add_action( 'admin_post_pmc_reorder', function () {
	$order_id = isset( $_GET['order'] ) ? (int) $_GET['order'] : 0;
	$nonce    = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
	if ( ! $order_id || ! wp_verify_nonce( $nonce, 'pmc_reorder_' . $order_id ) ) {
		wp_safe_redirect( wc_get_account_endpoint_url( 'orders' ) );
		exit;
	}
	$order = wc_get_order( $order_id );
	if ( ! $order || ! get_current_user_id() || (int) $order->get_customer_id() !== get_current_user_id() || ! function_exists( 'WC' ) || ! WC()->cart ) {
		wp_safe_redirect( wc_get_account_endpoint_url( 'orders' ) );
		exit;
	}
	foreach ( $order->get_items() as $item ) {
		$product = $item->get_product();
		if ( ! $product || ! $product->is_purchasable() || ! $product->is_in_stock() ) {
			continue;
		}
		if ( $item->get_variation_id() ) {
			WC()->cart->add_to_cart( $item->get_product_id(), $item->get_quantity(), $item->get_variation_id() );
		} else {
			WC()->cart->add_to_cart( $item->get_product_id(), $item->get_quantity() );
		}
	}
	wc_add_notice( 'Los productos de tu pedido están otra vez en el carrito. Revísalo antes de pagar.', 'success' );
	wp_safe_redirect( wc_get_cart_url() );
	exit;
} );

// Progreso también arriba del detalle del pedido.
add_action( 'woocommerce_view_order', function ( $order_id ) {
	$order = wc_get_order( $order_id );
	if ( $order ) {
		echo '<div class="pmc-view-progress">' . pmc_progress_bar( $order ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}
}, 1 );

// ══════════════════════════════════════════════════════════════════════════════
// 4. AVATAR PROPIO ── Subir foto en "Mis datos"; se convierte a WebP 512px.
// ══════════════════════════════════════════════════════════════════════════════

/** user_id desde lo que get_avatar reciba (id, email, user, comment). */
function pmc_resolve_user_id( $id_or_email ) {
	if ( is_numeric( $id_or_email ) ) {
		return (int) $id_or_email;
	}
	if ( $id_or_email instanceof WP_User ) {
		return $id_or_email->ID;
	}
	if ( $id_or_email instanceof WP_Comment ) {
		return (int) $id_or_email->user_id;
	}
	if ( is_string( $id_or_email ) && is_email( $id_or_email ) ) {
		$u = get_user_by( 'email', $id_or_email );
		return $u ? $u->ID : 0;
	}
	return 0;
}

/** Avatar por defecto opcional: primer archivo que exista en assets/ con
 *  nombre avatar-default.(webp|png|jpg). El repositorio no trae ninguno;
 *  también se puede fijar con el filtro 'pmc_default_avatar_url'. */
function pmc_default_avatar_url() {
	static $url = null;
	if ( null !== $url ) {
		return $url;
	}
	$url = '';
	foreach ( array( 'avatar-default.webp', 'avatar-default.png', 'avatar-default.jpg' ) as $f ) {
		if ( file_exists( plugin_dir_path( __FILE__ ) . 'assets/' . $f ) ) {
			$url = PMC_URL . 'assets/' . $f;
			break;
		}
	}
	$url = (string) apply_filters( 'pmc_default_avatar_url', $url );
	return $url;
}

// La foto subida manda sobre Gravatar en TODO el sitio (header, cuenta…);
// sin foto ni Gravatar, sale la lectora de la casa.
add_filter( 'pre_get_avatar_data', function ( $args, $id_or_email ) {
	$uid = pmc_resolve_user_id( $id_or_email );
	if ( $uid ) {
		$url = get_user_meta( $uid, 'pmc_avatar_url', true );
		if ( $url ) {
			$args['url']          = $url;
			$args['found_avatar'] = true;
			return $args;
		}
	}
	// Sin foto propia: si tampoco hay Gravatar, Gravatar redirige a la
	// imagen por defecto configurada (parámetro d=URL).
	$default = pmc_default_avatar_url();
	if ( $default ) {
		$args['default'] = $default;
	}
	return $args;
}, 10, 2 );

// Campo en Mis datos ✍️.
add_action( 'woocommerce_edit_account_form', function () {
	$uid = get_current_user_id();
	$has = (bool) get_user_meta( $uid, 'pmc_avatar_url', true );
	?>
	<fieldset class="pmc-avatar-field">
		<legend>Tu foto de perfil</legend>
		<div class="pmc-avatar-row">
			<?php echo get_avatar( $uid, 192, '', '', array( 'class' => 'pmc-avatar-preview' ) ); ?>
			<div>
				<input type="file" name="pmc_avatar" accept="image/jpeg,image/png,image/webp,image/gif">
				<p class="pmc-avatar-hint">JPG, PNG, WebP o GIF, máximo 4 MB. Se guarda recortada a 512 × 512 en WebP.</p>
				<?php if ( $has ) : ?>
					<label><input type="checkbox" name="pmc_avatar_remove" value="1"> Quitar mi foto</label>
				<?php endif; ?>
			</div>
		</div>
	</fieldset>
	<script>
		// El form de Woo no trae enctype: sin esto el archivo no viaja.
		(function () {
			var f = document.querySelector('form.woocommerce-EditAccountForm');
			if (f) { f.enctype = 'multipart/form-data'; }
		})();
	</script>
	<?php
} );

// Guardar: redimensiona a 512×512 y convierte a WebP.
add_action( 'woocommerce_save_account_details', function ( $user_id ) {
	// Woo ya verificó su nonce (save_account_details) y que el usuario es el
	// dueño de la sesión; aun así, solo se toca la cuenta propia.
	if ( (int) $user_id !== get_current_user_id() ) {
		return;
	}

	// Quitar foto.
	if ( ! empty( $_POST['pmc_avatar_remove'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- Woo ya verificó su nonce.
		pmc_delete_avatar_file( $user_id );
		delete_user_meta( $user_id, 'pmc_avatar_url' );
		delete_user_meta( $user_id, 'pmc_avatar_path' );
	}

	if ( empty( $_FILES['pmc_avatar']['tmp_name'] ) ) {
		return;
	}
	$file = $_FILES['pmc_avatar']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

	if ( ! isset( $file['error'] ) || UPLOAD_ERR_OK !== (int) $file['error'] || ! is_uploaded_file( $file['tmp_name'] ) ) {
		wc_add_notice( 'No recibimos la foto. Intenta de nuevo.', 'error' );
		return;
	}
	if ( (int) $file['size'] > 4 * MB_IN_BYTES || (int) filesize( $file['tmp_name'] ) > 4 * MB_IN_BYTES ) {
		wc_add_notice( 'La foto pesa más de 4 MB. Prueba con una más liviana.', 'error' );
		return;
	}
	// Tipo real por contenido (no por nombre ni por el MIME que manda el navegador).
	$info = @getimagesize( $file['tmp_name'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	if ( ! $info || ! in_array( $info['mime'], array( 'image/jpeg', 'image/png', 'image/webp', 'image/gif' ), true ) ) {
		wc_add_notice( 'Ese archivo no parece una imagen válida.', 'error' );
		return;
	}
	// Límite de dimensiones: evita agotar memoria al redimensionar.
	if ( (int) $info[0] < 1 || (int) $info[1] < 1 || (int) $info[0] * (int) $info[1] > 40000000 ) {
		wc_add_notice( 'La imagen es demasiado grande en píxeles. Prueba con una más pequeña.', 'error' );
		return;
	}

	$editor = wp_get_image_editor( $file['tmp_name'] );
	if ( is_wp_error( $editor ) ) {
		wc_add_notice( 'No pudimos procesar la imagen. Intenta con otra.', 'error' );
		return;
	}
	$editor->resize( 512, 512, true ); // recorte cuadrado.
	$editor->set_quality( 82 );

	$up  = wp_upload_dir();
	$sub = pmc_avatar_subdir();
	$dir = $up['basedir'] . '/' . $sub;
	wp_mkdir_p( $dir );
	// Sin listado de carpeta.
	if ( ! file_exists( $dir . '/index.php' ) ) {
		@file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore
	}
	// Nombre aleatorio: no revela el ID del usuario ni se puede adivinar.
	$base  = 'avatar-' . strtolower( wp_generate_password( 20, false ) );
	$saved = $editor->save( $dir . '/' . $base . '.webp', 'image/webp' );
	if ( is_wp_error( $saved ) ) {
		// Servidor sin soporte WebP: PNG o JPG (siempre re-codificada).
		$ext   = 'image/png' === $info['mime'] ? 'png' : 'jpg';
		$saved = $editor->save( $dir . '/' . $base . '.' . $ext, 'png' === $ext ? 'image/png' : 'image/jpeg' );
		if ( is_wp_error( $saved ) ) {
			wc_add_notice( 'No pudimos guardar la foto. Intenta de nuevo.', 'error' );
			return;
		}
	}

	// Borrar la anterior y guardar la nueva.
	pmc_delete_avatar_file( $user_id );
	update_user_meta( $user_id, 'pmc_avatar_path', $saved['path'] );
	update_user_meta( $user_id, 'pmc_avatar_url', $up['baseurl'] . '/' . $sub . '/' . $saved['file'] );
	wc_add_notice( '¡Foto actualizada!', 'success' );
} );

/** Borra la foto guardada del usuario, solo si está dentro de la carpeta de avatares. */
function pmc_delete_avatar_file( $user_id ) {
	$old = (string) get_user_meta( $user_id, 'pmc_avatar_path', true );
	if ( '' === $old || ! file_exists( $old ) ) {
		return;
	}
	$up   = wp_upload_dir();
	$dir  = realpath( $up['basedir'] . '/' . pmc_avatar_subdir() );
	$real = realpath( $old );
	if ( $dir && $real && 0 === strpos( wp_normalize_path( $real ), trailingslashit( wp_normalize_path( $dir ) ) ) ) {
		wp_delete_file( $real );
	}
}

// ══════════════════════════════════════════════════════════════════════════════
// 5. ASSETS
// ══════════════════════════════════════════════════════════════════════════════

add_action( 'wp_enqueue_scripts', function () {
	if ( ! function_exists( 'is_account_page' ) || ! is_account_page() ) {
		return;
	}
	wp_enqueue_style( 'pmc', PMC_URL . 'assets/account.css', array(), PMC_VERSION );
	$color = sanitize_hex_color( (string) get_option( 'pmc_color', '' ) );
	if ( $color ) {
		wp_add_inline_style( 'pmc', ':root{--pmc-accent:' . $color . '}' );
	}
} );

// ══════════════════════════════════════════════════════════════════════════════
// 6. AJUSTES ── wp-admin → Ajustes → Cuenta · Mi cuenta
// ══════════════════════════════════════════════════════════════════════════════

add_action( 'admin_menu', function () {
	add_options_page( 'Cuenta · Mi cuenta', 'Cuenta · Mi cuenta', 'manage_options', 'pa-mi-cuenta', 'pmc_options_page' );
} );

add_action( 'admin_init', function () {
	register_setting( 'pmc_group', 'pmc_options', array( 'sanitize_callback' => function ( $in ) {
		$in  = is_array( $in ) ? $in : array();
		$out = array();
		foreach ( array( 'tagline', 'whatsapp_text', 'label_dashboard', 'label_orders', 'label_lists', 'label_addresses', 'label_account', 'label_logout' ) as $k ) {
			$out[ $k ] = isset( $in[ $k ] ) ? mb_substr( sanitize_text_field( wp_unslash( $in[ $k ] ) ), 0, 120 ) : '';
		}
		$out['whatsapp'] = isset( $in['whatsapp'] ) ? substr( preg_replace( '/[^0-9]/', '', (string) $in['whatsapp'] ), 0, 15 ) : '';
		return $out;
	} ) );
	register_setting( 'pmc_group', 'pmc_color', array( 'sanitize_callback' => function ( $c ) {
		return (string) sanitize_hex_color( (string) $c );
	} ) );
} );

function pmc_options_page() {
	$o      = pmc_options();
	$fields = array(
		'tagline'         => array( 'Subtítulo del saludo', 'Texto bajo «Hola, nombre» en el escritorio. Vacío = sin subtítulo.' ),
		'whatsapp'        => array( 'WhatsApp de ayuda', 'Formato internacional, solo dígitos (ej. 15550000000). Vacío = la tarjeta no aparece.' ),
		'whatsapp_text'   => array( 'Mensaje inicial de WhatsApp', '' ),
		'label_dashboard' => array( 'Menú: Escritorio', 'Vacío = etiqueta de WooCommerce.' ),
		'label_orders'    => array( 'Menú: Pedidos', 'Vacío = etiqueta de WooCommerce.' ),
		'label_lists'     => array( 'Menú: Mis listas', '' ),
		'label_addresses' => array( 'Menú: Direcciones', 'Vacío = etiqueta de WooCommerce.' ),
		'label_account'   => array( 'Menú: Detalles de la cuenta', 'Vacío = etiqueta de WooCommerce.' ),
		'label_logout'    => array( 'Menú: Salir', 'Vacío = etiqueta de WooCommerce.' ),
	);
	?>
	<div class="wrap">
		<h1>Cuenta · Mi cuenta</h1>
		<form method="post" action="options.php">
			<?php settings_fields( 'pmc_group' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="pmc_color">Color de acento</label></th>
					<td><input type="text" id="pmc_color" name="pmc_color" value="<?php echo esc_attr( get_option( 'pmc_color', '' ) ); ?>" placeholder="#333333"><p class="description">Hexadecimal. Vacío = gris neutro.</p></td>
				</tr>
				<?php foreach ( $fields as $key => $f ) : ?>
				<tr>
					<th scope="row"><label for="pmc_<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $f[0] ); ?></label></th>
					<td><input type="text" class="regular-text" id="pmc_<?php echo esc_attr( $key ); ?>" name="pmc_options[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $o[ $key ] ); ?>">
					<?php if ( $f[1] ) : ?><p class="description"><?php echo esc_html( $f[1] ); ?></p><?php endif; ?></td>
				</tr>
				<?php endforeach; ?>
			</table>
			<?php submit_button(); ?>
		</form>
	</div>
	<?php
}
