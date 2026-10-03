# Woo Cuenta Simple

> One plugin with three modules for the WooCommerce customer account: wishlists with multiple lists, Google sign-in (optional Facebook / X) and a friendlier «My account» with order progress.

Español: [README.md](README.md)

## What it is
- **Wishlist**: heart on cards and product pages, **multiple named lists** (up to 20), move between lists, 140-char notes, public or private lists, shareable `?lista=TOKEN` link with **gift reservations** the owner never sees, optional daily email alerts (price drop / back in stock) and migration from WPC Smart Wishlist. Guests use `localStorage`, merged on login.
- **Sign-in**: Sign in / Create account modal that intercepts links to «My account»; **Google Sign-In** (Client ID only; the token is verified server-side), optional Facebook and X; 5 attempts / 10 min limit, honeypot and `?author=N` enumeration block. «Connected accounts» section to link / unlink networks.
- **My account**: dashboard with greeting, uploadable profile photo (max 4 MB, re-encoded to 512 px WebP), cards (latest order with progress, lists, addresses, optional WhatsApp), progress column and «Order again» button, «My lists» tab and editable menu labels.
- Modules can be switched off separately; «My lists» needs the wishlist.

## Who it is for
WooCommerce shops that want to replace several wishlist, social-login and account-customisation plugins with one small piece.

## Screenshots
_(Pending: they will live in `docs/img/` without real customer data.)_

## Requirements
- WordPress 6.3+, PHP 7.4+, WooCommerce 8.0+.
- Google: an OAuth Client ID with your domain under «Authorized JavaScript origins».
- Facebook / X (optional): App ID + App Secret (X: Client ID / Client Secret).

## Installation
1. Copy the `woo-cuenta-simple/` folder into `wp-content/plugins/` and activate it.
2. Wishlist: create a page with `[pa_la_wishlist]` and pick it under **Settings → Cuenta · Lista de deseos**.
3. My account: save **Settings → Permalinks** once (`mis-listas` endpoint).
4. Sign-in: under **Settings → Cuenta · Entrar** paste your Google Client ID and, optionally, Facebook / X details (preferably as `wp-config.php` constants).
5. To allow new accounts, enable WooCommerce «Allow customers to create an account on the My account page» (or WordPress «Anyone can register»).

## Settings
| Setting | Option / constant | Default | What it does |
|---|---|---|---|
| Enable sign-in modal | `pent_options[enabled]` | on | Shows the custom Sign in / Create account modal. |
| Intercept My account links | `pent_options[replace_links]` | on | Logged-out links to My account open the modal. |
| Modal colour | `pent_options[color]` | empty (theme contrast) | Accent colour of the modal. |
| Google Client ID | `pent_options[google_id]` or `PA_CUENTA_GOOGLE_CLIENT_ID` | empty (Google off) | Enables «Continue with Google». |
| Facebook App ID | `pent_options[fb_id]` or `PA_CUENTA_FACEBOOK_APP_ID` | empty | With the secret, enables Facebook. |
| Facebook App Secret | `pent_options[fb_secret]` or `PA_CUENTA_FACEBOOK_APP_SECRET` | empty | Facebook app secret. |
| X Client ID | `pent_options[x_id]` or `PA_CUENTA_X_CLIENT_ID` | empty | Enables «Continue with X». |
| X Client Secret | `pent_options[x_secret]` or `PA_CUENTA_X_CLIENT_SECRET` | empty | For confidential X apps. |
| Technical domain for X accounts | `PA_CUENTA_SOCIAL_DOMAIN` or filter `pa_cuenta_social_domain` | `social.<site domain>` | X gives no email: account is created as `x_<id>@<domain>`. |
| Wishlist colour | `plw_options[color]` | empty (theme contrast) | Heart, buttons and alert email. |
| Wishlist page | `plw_options[page_id]` | 0 (auto-detected) | Page holding `[pa_la_wishlist]`. |
| Email alerts | `plw_options[alerts]` | off | When on, sent only to users who enable them in their list (🔔). |
| Max products per list | `PLW_MAX_ITEMS` | 500 | Per-list cap. |
| My account colour | `pmc_color` | empty (grey `#333333`) | Dashboard and progress accent. |
| Greeting subtitle | `pmc_options[tagline]` | empty | Text under «Hola, name». |
| Help WhatsApp | `pmc_options[whatsapp]` | empty (no card) | International number, digits only. |
| WhatsApp message | `pmc_options[whatsapp_text]` | «Hola, necesito ayuda con mi cuenta» | Initial chat text. |
| Menu labels | `pmc_options[label_*]` | empty (WooCommerce labels); «Mis listas» | Dashboard, Orders, My lists, Addresses, Account details, Log out. |
| Switch a module off | `pa_cuenta_entrar_off`, `pa_cuenta_wishlist_off`, `pa_cuenta_micuenta_off` | 0 | `update_option( '…_off', 1 )` disables that module. |

## Shortcodes, filters and hooks
- `[pa_la_wishlist]`: lists page. `[pa_entrar texto="" texto2="" accion="" saludo="Hola, %s" salir="" accsalir="" clase=""]`: header sign-in link / greeting.
- Filters: `pent_registration_allowed`, `pent_is_privileged_user`, `pent_modal_title`, `pent_modal_subtitle`, `plw_share_bar_html`, `plw_alert_allowed` (plug in your consent / unsubscribe system), `plw_alert_footer_html`, `plw_alert_headers`, `pmc_avatar_subdir`, `pmc_default_avatar_url`.
- Actions: `plw_alert_sent`, `pmc_dashboard_after_cards` (for recommendations under the cards).
- REST routes: `lm-auth/v1/*` (sign-in) and `plw/v1/*` (lists). Redirect URLs to register in Facebook / X: `…/wp-json/lm-auth/v1/facebook/cb` and `…/x/cb`.

## Security and privacy
- Google: the token is verified with Google and `aud` (your Client ID), `iss`, `exp` and `email_verified` are required.
- An existing account is **never** opened by email from Facebook or X (they do not guarantee the email) nor when it has edit / admin capabilities: sign in with the password and link the network from «Account details».
- Secrets saved in Settings are stored as plain text in the database; prefer the `wp-config.php` constants.
- Email alerts are opt-in. Review your privacy policy: wishlists and social links are personal data.
- More in [SEGURIDAD.md](SEGURIDAD.md) (Spanish).

## How to undo
- Switch off the module you do not want with its `…_off` option; the others keep working.
- The wishlist keeps old WPC data (does not delete it); its lists live in the `plw_lists` user meta.
- To return to the WooCommerce login, switch off «Entrar».

## FAQ
**Does it work with full-page cache?**
The wishlist puts no nonces in the HTML for guests and is page-cache compatible.

**Why does X ask for an email at checkout?**
X does not provide an email: an account is created with a technical address and the real one is collected at the first purchase.

## Limitations
- Texts are in Spanish, not yet wrapped in `__()` (pending: i18n and `.pot`).
- Legacy internal prefixes (`pent_`, `plw_`, `pmc_`, `lm-auth/v1` routes) are kept for compatibility.

## Licence, author and support
- Licence: GPL-2.0-or-later (see `LICENSE`).
- Author: **Andrés Valencia Tobón** · [GitHub](https://github.com/quijotevitruvio) · [LinkedIn](https://www.linkedin.com/in/andr%C3%A9s-valencia-tob%C3%B3n/)
- Support the project: https://paypal.me/bibliotecologo
- Developed for librosmedellin.com.
