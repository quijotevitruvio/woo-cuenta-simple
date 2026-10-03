# Seguridad — Woo Cuenta Simple 1.0.0

Revisión hecha antes de publicar (2026-10-03). Para reportar un problema de seguridad, abre un aviso privado en GitHub (Security → Report a vulnerability) en vez de un issue público.

## Arreglado en esta versión
| Hallazgo | Archivo | Gravedad | Arreglo |
|---|---|---|---|
| El acceso con Facebook abría una cuenta existente con solo coincidir el correo (Facebook no garantiza que esté verificado): toma de cuenta. | `modulos/entrar/entrar.php` (`pent_find_or_create_user`, `pent_facebook_cb`) | Alta (si Facebook está configurado) | Facebook / X nunca abren cuentas existentes por correo; solo por vínculo hecho con sesión desde «Detalles de la cuenta». |
| El acceso con Google abría cualquier cuenta con ese correo, incluidas las de administración (se salta contraseña y 2FA). | `modulos/entrar/entrar.php` (`pent_rest_google`) | Media | Las cuentas con `edit_posts`, `manage_woocommerce` o `list_users` no se abren por correo desde ninguna red (filtro `pent_is_privileged_user`). |
| Verificación de Google sin `iss`/`exp` explícitos ni código HTTP. | `modulos/entrar/entrar.php` (`pent_rest_google`) | Baja | Se exigen `aud` (comparación constante), `iss`, `exp`, `email_verified` y respuesta 200. |
| El registro (formulario y redes) ignoraba el ajuste «permitir crear cuentas». | `modulos/entrar/entrar.php` (`pent_rest_register`) | Baja | `pent_registration_allowed()` respeta WooCommerce / WordPress. |
| Redirección tras OAuth validada con `strpos` sobre `home_url()`. | `modulos/entrar/entrar.php` (`pent_login_and_redirect`) | Baja (`wp_safe_redirect` ya la frenaba) | `wp_validate_redirect()`. |
| Foto de perfil: sin comprobar `is_uploaded_file`/error, sin tope de píxeles (memoria), nombre con el ID del usuario, carpeta sin `index.php`, borrado de la ruta guardada sin confirmar la carpeta. | `modulos/mi-cuenta/mi-cuenta.php` | Baja | Todas corregidas; la imagen se valida por contenido y siempre se re-codifica. |
| `/plw/v1/merge` sin tope de IDs (meta de usuario sin límite). | `modulos/wishlist/wishlist.php` | Baja | Tope `PLW_MAX_ITEMS` (500) por lista y en todas las escrituras. |
| Token de lista compartida buscado por subcadena con `LIMIT 1`: un nombre o nota con el mismo texto podía tapar la lista real. | `modulos/wishlist/wishlist.php` (`plw_find_owner_by_token`) | Baja | Búsqueda de la clave serializada exacta y confirmación en PHP. |
| Casilla de alertas siempre marcada en la interfaz aunque el usuario las hubiera apagado. | `modulos/wishlist/assets/wishlist.js` | Baja (privacidad) | La casilla refleja el estado guardado; las alertas son opt-in. |
| URL de producto / imagen sin escapar al pintar HTML en JS. | `modulos/wishlist/assets/wishlist.js` | Informativa (datos del servidor) | `esc()`. |

## Pendiente / a tener en cuenta
| Hallazgo | Archivo | Gravedad | Recomendación |
|---|---|---|---|
| `lm-auth/v1/login` y `/register` aceptan POST sin nonce (CSRF de inicio de sesión: forzar a alguien a entrar en una cuenta del atacante). | `modulos/entrar/entrar.php` (`rest_api_init`) | Baja | Añadir un nonce propio servido sin caché o comprobar `Origin`/`Referer`. |
| `lm-auth/v1/login` es una segunda puerta de acceso aparte de `wp-login.php`: los plugins que protegen o esconden `wp-login.php` no la cubren. Solo la frena el límite de 5 intentos / 10 min por IP. | `modulos/entrar/entrar.php` (`pent_rest_login`) | Baja | Pasar el login por los filtros `authenticate` (ya lo hace `wp_signon`) y documentarlo; opcional: límite por usuario además de por IP. |
| Los secretos de Facebook / X guardados en Ajustes quedan en texto plano en `wp_options` y se reimprimen en el campo de la página de ajustes (solo administradores). | `modulos/entrar/entrar.php` (`pent_options_page`) | Baja | Usar las constantes `PA_CUENTA_*` en `wp-config.php`. |
| El límite de intentos usa `REMOTE_ADDR`; detrás de un proxy / CDN todos comparten IP. | `modulos/entrar/entrar.php` (`pent_client_ip`) | Baja | Ajustar `REMOTE_ADDR` en el servidor con la IP real del proxy de confianza. |
| Reservas de regalo públicas por enlace (sin cuenta): quien tenga el enlace puede reservar o cancelar. Límite 30 / hora por IP. | `modulos/wishlist/wishlist.php` (`/plw/v1/reserve`) | Informativa (por diseño) | Compartir el enlace solo con quien deba verlo; la lista se puede volver privada. |
| Cuentas nuevas creadas con Facebook usan el correo que entrega Facebook. | `modulos/entrar/entrar.php` | Informativa | Meta solo entrega correos confirmados; si te preocupa, filtra `pent_registration_allowed`. |
