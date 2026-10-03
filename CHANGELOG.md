# Changelog

Formato: [Keep a Changelog](https://keepachangelog.com/es-ES/1.1.0/). Versionado semántico.

## [1.0.0] - 2026-10-03
### Añadido
- Primera versión pública: un solo plugin (`woo-cuenta-simple/`) con tres módulos: Entrar, Lista de deseos y Mi cuenta.
- Ajustes para todo lo que antes era propio de una tienda: Client ID de Google y App ID de Facebook vacíos por defecto, constantes `PA_CUENTA_*` en `wp-config.php` para claves y secretos, dominio técnico de cuentas de X, color, WhatsApp, subtítulo y etiquetas del menú de «Mi cuenta».
- Filtros y acciones: `plw_alert_allowed`, `plw_share_bar_html`, `pmc_dashboard_after_cards`, `pent_registration_allowed`, `pent_is_privileged_user`, entre otros.

### Seguridad
- Google: se exigen además `iss` y `exp`, y el código de respuesta 200 de Google.
- No se abren cuentas existentes por correo desde Facebook / X, ni cuentas con permisos de edición o administración desde ninguna red social.
- El registro (formulario y redes) respeta el ajuste de WooCommerce / WordPress que permite crear cuentas.
- Foto de perfil: comprobación de subida real, tope de píxeles, nombre aleatorio, `index.php` en la carpeta y borrado solo dentro de ella.
- Lista de deseos: tope de productos por lista, búsqueda exacta del token de lista compartida, escape de URL en el JS.
- Alertas por correo opt-in (apagadas por defecto).

### Quitado
- Textos, colores, dominio, teléfono e imagen de avatar de la tienda original; integraciones con plugins privados (carrusel de recomendaciones, barra social, sistema de bajas), sustituidas por filtros.
- El módulo de suscripciones del paquete original no forma parte de este repositorio.

Este historial empieza en 1.0.0; el desarrollo anterior fue interno de librosmedellin.com.

---

## [1.0.0] - 2026-10-03 (English)
- First public release: one plugin with three modules (sign-in, wishlist, my account); every store-specific value moved to settings or `PA_CUENTA_*` constants; hardened Google verification, no account linking by unverified email or for privileged users, registration respects the site setting, safer avatar upload, opt-in wishlist alerts.
