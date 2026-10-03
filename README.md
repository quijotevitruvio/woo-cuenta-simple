# Woo Cuenta Simple

> Un plugin con tres módulos para la cuenta del cliente en WooCommerce: lista de deseos con varias listas, acceso con Google (y Facebook / X opcionales) y «Mi cuenta» con progreso de pedidos.

English: [README.en.md](README.en.md)

## Qué es
- **Lista de deseos**: corazón en tarjetas y fichas, **varias listas con nombre** (hasta 20), mover entre listas, notas de 140 caracteres, listas públicas o privadas, enlace compartible `?lista=TOKEN` con **reservas de regalo** que el dueño de la lista nunca ve, alertas diarias opcionales por correo (bajó de precio / volvió a estar disponible) y migración desde WPC Smart Wishlist. Los invitados guardan en `localStorage` y se fusiona al entrar.
- **Entrar**: ventana de Entrar / Crear cuenta que intercepta los enlaces a «Mi cuenta»; **Google Sign-In** (solo Client ID; el token se verifica en el servidor), Facebook y X opcionales; límite de 5 intentos / 10 min, trampa para bots (honeypot) y bloqueo de `?author=N`. Sección «Cuentas conectadas» para vincular / desvincular redes.
- **Mi cuenta**: escritorio con saludo, foto de perfil subible (máx. 4 MB, re-codificada a WebP 512 px), tarjetas (último pedido con progreso, listas, direcciones, WhatsApp opcional), columna de progreso y botón «Volver a pedir», pestaña «Mis listas» y etiquetas del menú editables.
- Los módulos se apagan por separado; «Mis listas» en «Mi cuenta» necesita la lista de deseos.

## Para quién
Tiendas WooCommerce que quieren reemplazar varios plugins de lista de deseos, login social y personalización de cuenta por una sola pieza pequeña.

## Capturas
_(Pendientes: se guardarán en `docs/img/` sin datos de clientes reales.)_

## Requisitos
- WordPress 6.3+, PHP 7.4+, WooCommerce 8.0+.
- Google: un Client ID de OAuth con tu dominio en «Orígenes de JavaScript autorizados».
- Facebook / X (opcional): App ID + App Secret (X: Client ID / Client Secret).

## Instalación
1. Copia la carpeta `woo-cuenta-simple/` en `wp-content/plugins/` y actívala.
2. Lista de deseos: crea una página con `[pa_la_wishlist]` y elígela en **Ajustes → Cuenta · Lista de deseos**.
3. Mi cuenta: guarda **Ajustes → Enlaces permanentes** una vez (endpoint `mis-listas`).
4. Entrar: en **Ajustes → Cuenta · Entrar** pega tu Client ID de Google y, si quieres, los datos de Facebook / X (mejor como constantes en `wp-config.php`).
5. Para que se puedan crear cuentas, activa en WooCommerce «Permitir a los clientes crear una cuenta en la página Mi cuenta» (o «Cualquiera puede registrarse» de WordPress).

## Ajustes
| Ajuste | Opción / constante | Por defecto | Qué hace |
|---|---|---|---|
| Activar la ventana de acceso | `pent_options[enabled]` | activado | Muestra la ventana propia de Entrar / Crear cuenta. |
| Interceptar enlaces a Mi cuenta | `pent_options[replace_links]` | activado | Sin sesión, los enlaces a Mi cuenta abren la ventana. |
| Color de la ventana | `pent_options[color]` | vacío (contraste del tema) | Color de acento de la ventana. |
| Google Client ID | `pent_options[google_id]` o `PA_CUENTA_GOOGLE_CLIENT_ID` | vacío (Google apagado) | Activa «Continuar con Google». |
| Facebook App ID | `pent_options[fb_id]` o `PA_CUENTA_FACEBOOK_APP_ID` | vacío | Con el secret, activa Facebook. |
| Facebook App Secret | `pent_options[fb_secret]` o `PA_CUENTA_FACEBOOK_APP_SECRET` | vacío | Secreto de la app de Facebook. |
| X Client ID | `pent_options[x_id]` o `PA_CUENTA_X_CLIENT_ID` | vacío | Activa «Continuar con X». |
| X Client Secret | `pent_options[x_secret]` o `PA_CUENTA_X_CLIENT_SECRET` | vacío | Para apps confidenciales de X. |
| Dominio técnico de cuentas de X | `PA_CUENTA_SOCIAL_DOMAIN` o filtro `pa_cuenta_social_domain` | `social.<dominio del sitio>` | X no entrega correo: la cuenta se crea con `x_<id>@<dominio>`. |
| Color de la lista de deseos | `plw_options[color]` | vacío (contraste del tema) | Corazón, botones y correo de alertas. |
| Página de la lista | `plw_options[page_id]` | 0 (se autodetecta) | Página con `[pa_la_wishlist]`. |
| Alertas por correo | `plw_options[alerts]` | apagadas | Encendidas, se envían solo a quien las activa en su lista (🔔). |
| Máximo de productos por lista | `PLW_MAX_ITEMS` | 500 | Tope por lista. |
| Color de Mi cuenta | `pmc_color` | vacío (gris `#333333`) | Acento del escritorio y del progreso. |
| Subtítulo del saludo | `pmc_options[tagline]` | vacío | Texto bajo «Hola, nombre». |
| WhatsApp de ayuda | `pmc_options[whatsapp]` | vacío (sin tarjeta) | Número internacional, solo dígitos. |
| Mensaje de WhatsApp | `pmc_options[whatsapp_text]` | «Hola, necesito ayuda con mi cuenta» | Texto inicial del chat. |
| Etiquetas del menú | `pmc_options[label_*]` | vacío (las de WooCommerce); «Mis listas» | Escritorio, Pedidos, Mis listas, Direcciones, Detalles, Salir. |
| Apagar un módulo | `pa_cuenta_entrar_off`, `pa_cuenta_wishlist_off`, `pa_cuenta_micuenta_off` | 0 | `update_option( '…_off', 1 )` apaga ese módulo. |

## Shortcodes, filtros y ganchos
- `[pa_la_wishlist]`: página de listas. `[pa_entrar texto="" texto2="" accion="" saludo="Hola, %s" salir="" accsalir="" clase=""]`: enlace de acceso / saludo para el encabezado.
- Filtros: `pent_registration_allowed`, `pent_is_privileged_user`, `pent_modal_title`, `pent_modal_subtitle`, `plw_share_bar_html`, `plw_alert_allowed` (conecta tu sistema de consentimiento / bajas), `plw_alert_footer_html`, `plw_alert_headers`, `pmc_avatar_subdir`, `pmc_default_avatar_url`.
- Acciones: `plw_alert_sent`, `pmc_dashboard_after_cards` (para recomendaciones bajo las tarjetas).
- Rutas REST: `lm-auth/v1/*` (acceso) y `plw/v1/*` (listas). Las URL de regreso que registras en Facebook / X son `…/wp-json/lm-auth/v1/facebook/cb` y `…/x/cb`.

## Seguridad y privacidad
- Google: el token se verifica con Google y se exigen `aud` (tu Client ID), `iss`, `exp` y `email_verified`.
- Una cuenta existente **no** se abre por correo desde Facebook o X (no garantizan el correo) ni si tiene permisos de edición / administración: hay que entrar con contraseña y conectar la red desde «Detalles de la cuenta».
- Los secretos guardados en Ajustes quedan en texto plano en la base de datos; usa las constantes de `wp-config.php`.
- Las alertas por correo son opt-in. Revisa tu política de privacidad: la lista de deseos y los vínculos sociales son datos personales.
- Más detalle en [SEGURIDAD.md](SEGURIDAD.md).

## Cómo deshacer
- Apaga el módulo que no quieras con su opción `…_off`; los demás siguen.
- La lista de deseos conserva los datos viejos de WPC (no los borra); sus listas viven en el meta de usuario `plw_lists`.
- Para volver al login de WooCommerce basta apagar «Entrar».

## Preguntas frecuentes
**¿Funciona con caché de página completa?**
La lista de deseos no pone nonces en el HTML para invitados y es compatible con caché.

**¿Por qué X pide el correo en el pago?**
X no entrega correo: se crea una cuenta con un correo técnico y el real se captura en la primera compra.

## Limitaciones
- Textos en español, sin `__()` todavía (pendiente: internacionalización y `.pot`).
- Prefijos internos heredados (`pent_`, `plw_`, `pmc_`, rutas `lm-auth/v1`) se mantienen por compatibilidad.

## Licencia, autor y apoyo
- Licencia: GPL-2.0-or-later (ver `LICENSE`).
- Autor: **Andrés Valencia Tobón** · [GitHub](https://github.com/quijotevitruvio) · [LinkedIn](https://www.linkedin.com/in/andr%C3%A9s-valencia-tob%C3%B3n/)
- Apoya el proyecto: https://paypal.me/bibliotecologo
- Desarrollado para librosmedellin.com.
