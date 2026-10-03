/* Pa entrar — modal de login/registro + social. Solo carga sin sesión. */
(function () {
	'use strict';

	var cfg = window.pentCfg;
	var modal = document.getElementById('pent-modal');
	var overlay = document.getElementById('pent-overlay');
	if (!cfg || !modal) {
		return;
	}

	var msg = document.getElementById('pent-msg');

	function open() {
		modal.hidden = false;
		overlay.hidden = false;
		document.body.classList.add('pent-noscroll');
		initGoogle();
	}

	function close() {
		modal.hidden = true;
		overlay.hidden = true;
		document.body.classList.remove('pent-noscroll');
	}

	function say(text, ok) {
		msg.textContent = text;
		msg.hidden = false;
		msg.className = ok ? 'pent-ok' : 'pent-error';
	}

	// ── Triggers ─────────────────────────────────────────────────────────
	document.addEventListener('click', function (ev) {
		var t = ev.target.closest('.pent-login-tgr, .xoo-el-login-tgr');
		if (!t && cfg.replaceLinks) {
			var a = ev.target.closest('a[href]');
			if (a && cfg.accountUrl && a.href.indexOf(cfg.accountUrl) === 0 && !a.closest('#pent-modal')) {
				t = a;
			}
		}
		if (t) {
			ev.preventDefault();
			open();
		}
	});

	document.getElementById('pent-close').addEventListener('click', close);
	overlay.addEventListener('click', close);
	document.addEventListener('keydown', function (ev) {
		if (ev.key === 'Escape' && !modal.hidden) {
			close();
		}
	});

	// ── Tabs ─────────────────────────────────────────────────────────────
	modal.querySelectorAll('.pent-tab').forEach(function (tab) {
		tab.addEventListener('click', function () {
			modal.querySelectorAll('.pent-tab').forEach(function (t) {
				t.classList.toggle('is-active', t === tab);
			});
			modal.querySelectorAll('.pent-panel').forEach(function (p) {
				p.hidden = p.dataset.panel !== tab.dataset.panel;
			});
			msg.hidden = true;
		});
	});

	// ── Email + contraseña ───────────────────────────────────────────────
	function post(path, body) {
		return fetch(cfg.rest + path, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify(body)
		}).then(function (r) {
			return r.json().then(function (d) {
				if (!r.ok) {
					throw d;
				}
				return d;
			});
		});
	}

	function busy(form, on) {
		form.querySelector('button[type="submit"]').disabled = on;
	}

	function done() {
		say('¡Listo! Entrando… 📚', true);
		setTimeout(function () { location.reload(); }, 600);
	}

	function fail(e) {
		say((e && e.message) || 'Algo falló. Intenta de nuevo.', false);
	}

	document.getElementById('pent-form-login').addEventListener('submit', function (ev) {
		ev.preventDefault();
		var f = ev.target;
		busy(f, true);
		post('login', { user: f.user.value, pass: f.pass.value })
			.then(done).catch(fail).finally(function () { busy(f, false); });
	});

	document.getElementById('pent-form-register').addEventListener('submit', function (ev) {
		ev.preventDefault();
		var f = ev.target;
		busy(f, true);
		post('register', { name: f.name.value, email: f.email.value, pass: f.pass.value, website: f.website.value })
			.then(done).catch(fail).finally(function () { busy(f, false); });
	});

	// ── Social ───────────────────────────────────────────────────────────
	var gisDone = false;
	function initGoogle() {
		if (gisDone || !cfg.googleId || !window.google || !google.accounts) {
			return;
		}
		gisDone = true;
		google.accounts.id.initialize({
			client_id: cfg.googleId,
			callback: function (resp) {
				post('google', { credential: resp.credential }).then(done).catch(fail);
			}
		});
		google.accounts.id.renderButton(document.getElementById('pent-google'), {
			theme: 'outline',
			size: 'large',
			text: 'continue_with',
			shape: 'pill',
			width: 320, // mismo ancho que los botones de Facebook y X.
			locale: 'es'
		});
	}

	['facebook', 'x'].forEach(function (provider) {
		var btn = modal.querySelector('[data-provider="' + provider + '"]');
		if (!btn) {
			return;
		}
		var on = provider === 'facebook' ? cfg.facebookOn : cfg.xOn;
		if (!on) {
			return;
		}
		btn.hidden = false;
		btn.addEventListener('click', function (ev) {
			ev.preventDefault();
			location.href = cfg.rest + provider + '/start?back=' + encodeURIComponent(location.href);
		});
	});
})();
