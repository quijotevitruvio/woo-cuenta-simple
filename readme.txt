=== Woo Cuenta Simple ===
Contributors: quijotevitruvio
Donate link: https://paypal.me/bibliotecologo
Tags: woocommerce, wishlist, login, social login, my account
Requires at least: 6.3
Tested up to: 6.8
Requires PHP: 7.4
Requires Plugins: woocommerce
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

One plugin, three modules for the WooCommerce customer account: wishlists, Google sign-in (optional Facebook / X) and a friendlier «My account» with order progress.

== Description ==

* **Wishlist**: heart on cards and product pages, multiple named lists (up to 20), move between lists, notes, public / private lists, shareable `?lista=TOKEN` link with gift reservations the owner never sees, optional opt-in email alerts (price drop / back in stock), migration from WPC Smart Wishlist. Guests use localStorage, merged on login.
* **Sign-in**: Sign in / Create account modal; Google Sign-In verified server-side (aud, iss, exp, email_verified); optional Facebook and X; 5 attempts / 10 min limit, honeypot, `?author=N` block; «Connected accounts» to link / unlink networks.
* **My account**: dashboard with greeting and cards, uploadable profile photo (re-encoded to 512 px WebP), order progress, «Order again», «My lists» tab, editable menu labels and optional WhatsApp help card.

Each module can be switched off with an option (`pa_cuenta_entrar_off`, `pa_cuenta_wishlist_off`, `pa_cuenta_micuenta_off`).

Author: Andrés Valencia Tobón - https://github.com/quijotevitruvio - https://www.linkedin.com/in/andr%C3%A9s-valencia-tob%C3%B3n/
Developed for librosmedellin.com. Support: https://paypal.me/bibliotecologo

== Installation ==

1. Copy the `woo-cuenta-simple` folder into `wp-content/plugins/` and activate it.
2. Wishlist: create a page with `[pa_la_wishlist]` and pick it under Settings → Cuenta · Lista de deseos.
3. My account: save Settings → Permalinks once (`mis-listas` endpoint).
4. Sign-in: Settings → Cuenta · Entrar, paste your Google Client ID (or define `PA_CUENTA_GOOGLE_CLIENT_ID` in wp-config.php). Facebook / X secrets are best defined as constants: `PA_CUENTA_FACEBOOK_APP_ID`, `PA_CUENTA_FACEBOOK_APP_SECRET`, `PA_CUENTA_X_CLIENT_ID`, `PA_CUENTA_X_CLIENT_SECRET`.
5. New accounts are only created when WooCommerce (or WordPress) registration is enabled.

== Frequently Asked Questions ==

= Can someone sign in to my admin account with Google or Facebook? =
No. Accounts with edit / admin capabilities are never opened by email from a social network, and Facebook / X never open an existing account by email. Link the network first from «Account details» while signed in with your password.

= Is my App Secret stored securely? =
If saved in Settings it is plain text in the database. Prefer the wp-config.php constants.

= Why does X ask for an email at checkout? =
X does not provide an email: an account is created with a technical address (`x_<id>@social.<your domain>`) and the real one is collected at the first purchase.

= Does it work with full-page cache? =
The wishlist puts no nonces in the HTML for guests and is page-cache compatible.

== Screenshots ==

Pending.

== Changelog ==

= 1.0.0 =
* First public release: single plugin with the three modules, settings for every store-specific value, hardened social sign-in and avatar upload, opt-in wishlist alerts.

== Upgrade Notice ==

= 1.0.0 =
First public release.
