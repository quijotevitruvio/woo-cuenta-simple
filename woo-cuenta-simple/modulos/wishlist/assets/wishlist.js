/* Pa la wishlist v2 — listas múltiples estilo Amazon.
   Invitado: una lista local (localStorage). Con cuenta: varias listas (REST).
   Corazón → toast de confirmación con enlace a la lista. */
(function () {
	'use strict';

	var cfg = window.plwCfg;
	if (!cfg) {
		return;
	}

	var LS_KEY = 'plw_list';
	var allIds = []; // unión de todas las listas → estado de los corazones.
	var lists = []; // [{token,name,count}] (solo logueados).

	function lsGet() {
		try {
			return (JSON.parse(localStorage.getItem(LS_KEY)) || []).map(Number);
		} catch (e) {
			return [];
		}
	}

	function lsSet(ids) {
		try {
			localStorage.setItem(LS_KEY, JSON.stringify(ids));
		} catch (e) {}
	}

	function headers(extra) {
		var h = extra || {};
		if (cfg.nonce) {
			h['X-WP-Nonce'] = cfg.nonce;
		}
		return h;
	}

	function get(path) {
		return fetch(cfg.rest + path, { credentials: 'same-origin', headers: headers() })
			.then(function (r) { return r.json(); });
	}

	function post(path, body) {
		return fetch(cfg.rest + path, {
			method: 'POST',
			credentials: 'same-origin',
			headers: headers({ 'Content-Type': 'application/json' }),
			body: JSON.stringify(body)
		}).then(function (r) {
			return r.json().then(function (d) {
				if (!r.ok) { throw d; }
				return d;
			});
		});
	}

	// ── Estado inicial + fusión invitado → cuenta ─────────────────────────
	function init() {
		if (cfg.loggedIn) {
			var guest = lsGet();
			var ready = guest.length
				? post('merge', { ids: guest }).then(function (d) { lsSet([]); return d; })
				: get('state');
			ready.then(function (d) {
				allIds = d.allIds || [];
				lists = d.lists || [];
				paintHearts();
				if (pageEl) { renderPage(); }
			});
		} else {
			allIds = lsGet();
			paintHearts();
			if (pageEl) { renderPage(); }
		}
	}

	function paintHearts() {
		document.querySelectorAll('.plw-heart').forEach(function (b) {
			b.classList.toggle('plw-on', allIds.indexOf(parseInt(b.dataset.plw, 10)) !== -1);
		});
		document.querySelectorAll('[data-plw-label]').forEach(function (l) {
			var on = allIds.indexOf(parseInt(l.dataset.plwLabel, 10)) !== -1;
			l.textContent = on ? 'En tu lista de deseos ❤️' : 'Añadir a la lista de deseos';
		});
		paintHeaderChip();
	}

	// Contador ❤️N junto al saludo del header (enlaza a la lista).
	function paintHeaderChip() {
		var host = document.querySelector('.pent-header .pent-line1');
		if (!host || !cfg.pageUrl) {
			return;
		}
		var chip = document.getElementById('plw-head-chip');
		if (!chip) {
			chip = document.createElement('a');
			chip.id = 'plw-head-chip';
			chip.href = cfg.pageUrl;
			chip.setAttribute('aria-label', 'Mi lista de deseos');
			host.appendChild(chip);
		}
		chip.textContent = '❤️' + allIds.length;
		chip.hidden = allIds.length === 0;
	}

	// ── Toast ─────────────────────────────────────────────────────────────
	var toastT;
	function toast(msg, showLink) {
		var t = document.getElementById('plw-toast');
		if (!t) {
			return;
		}
		document.getElementById('plw-toast-msg').textContent = msg;
		var link = document.getElementById('plw-toast-link');
		link.hidden = !showLink || !cfg.pageUrl;
		link.href = cfg.pageUrl || '#';
		// Todo el toast es clic cuando lleva enlace a la lista.
		t.classList.toggle('plw-toast-click', !link.hidden);
		t.hidden = false;
		t.classList.add('plw-toast-in');
		clearTimeout(toastT);
		toastT = setTimeout(function () {
			t.classList.remove('plw-toast-in');
			t.hidden = true;
		}, 8000);
	}

	// Clic en cualquier parte del toast → ir a la lista.
	document.addEventListener('click', function (ev) {
		var t = ev.target.closest('#plw-toast.plw-toast-click');
		if (t && !ev.target.closest('a') && cfg.pageUrl) {
			location.href = cfg.pageUrl;
		}
	});

	// ── Corazones ─────────────────────────────────────────────────────────
	function toggle(id) {
		var i = allIds.indexOf(id);
		var adding = i === -1;
		if (adding) { allIds.push(id); } else { allIds.splice(i, 1); }
		paintHearts();
		if (cfg.loggedIn) {
			post('toggle', { id: id }).then(function (d) {
				allIds = d.allIds || allIds;
				lists = d.lists || lists;
				paintHearts();
			});
			toast(adding ? 'Agregado a "Mi lista de deseos" ❤️' : 'Quitado de tu lista', adding);
		} else {
			lsSet(allIds);
			toast(adding ? 'Agregado a tu lista ❤️' : 'Quitado de tu lista', adding);
		}
	}

	// ── Menú del producto simple (estilo Amazon) ─────────────────────────
	// Si el libro YA está en la lista, el clic en la etiqueta abre opciones:
	// ver la lista, crear una lista nueva con este libro, o quitarlo.
	function closePop() {
		var p = document.getElementById('plw-pop');
		if (p) {
			p.remove();
		}
	}

	function openPop(label, id) {
		closePop();
		var wrap = label.closest('.plw-single-wrap') || label.parentElement;
		wrap.style.position = 'relative';
		var pop = document.createElement('div');
		pop.id = 'plw-pop';
		var html = '';
		if (cfg.pageUrl) {
			html += '<a href="' + escHtml(cfg.pageUrl) + '">Ver mis listas</a>';
		}
		if (cfg.loggedIn && lists.length) {
			// Guardar también en una lista existente (estilo Amazon).
			html += '<span class="plw-pop-sep">Guardar en:</span>';
			lists.forEach(function (l) {
				html += '<button type="button" data-save="' + l.token + '">' + (l.private ? '🔒 ' : '') + escHtml(l.name) + ' <em>(' + l.count + ')</em></button>';
			});
		}
		if (cfg.loggedIn) {
			html += '<button type="button" data-act="new">+ Nueva lista con este producto</button>';
		} else {
			html += '<a href="#" class="pent-login-tgr">Inicia sesión para crear listas</a>';
		}
		html += '<button type="button" data-act="remove">Quitar de la lista 💔</button>';
		pop.innerHTML = html;
		wrap.appendChild(pop);

		pop.addEventListener('click', function (ev) {
			var save = ev.target.closest('[data-save]');
			if (save) {
				post('toggle', { id: id, token: save.dataset.save, set: 'on' }).then(function (d) {
					allIds = d.allIds;
					lists = d.lists;
					paintHearts();
					toast('Guardado en "' + save.textContent.replace(/\s*\(\d+\)$/, '').trim() + '" ❤️', true);
				});
				closePop();
			}
		});

		pop.addEventListener('click', function (ev) {
			var act = ev.target.closest('[data-act]');
			if (!act) {
				return;
			}
			if ('remove' === act.dataset.act) {
				allIds = allIds.filter(function (x) { return x !== id; });
				paintHearts();
				if (cfg.loggedIn) {
					post('toggle', { id: id, set: 'off' }).then(function (d) {
						allIds = d.allIds;
						lists = d.lists;
						paintHearts();
					});
				} else {
					lsSet(allIds);
				}
				toast('Quitado de tu lista', false);
			}
			if ('new' === act.dataset.act) {
				var name = prompt('Nombre de la nueva lista:', '');
				if (!name) {
					return;
				}
				post('lists', { action: 'create', name: name }).then(function (d) {
					lists = d.lists;
					// El libro pasa a la lista nueva (sale de la principal).
					return post('toggle', { id: id, token: d.token, set: 'on' }).then(function () {
						return post('toggle', { id: id, token: lists[0].token, set: 'off' });
					});
				}).then(function (d) {
					allIds = d.allIds;
					lists = d.lists;
					paintHearts();
					toast('Movido a "' + name + '" ✨', true);
				}).catch(function (e) { toast((e && e.message) || 'No se pudo crear.', false); });
			}
			closePop();
		});
	}

	document.addEventListener('click', function (ev) {
		if (!ev.target.closest('#plw-pop')) {
			closePop();
		}
		var b = ev.target.closest('.plw-heart, [data-plw-label]');
		if (!b || b.closest('#plw-page')) {
			return;
		}
		ev.preventDefault();
		var id = parseInt(b.dataset.plw || b.dataset.plwLabel, 10);
		// Etiqueta del producto simple con el libro ya en lista → menú.
		if (b.dataset.plwLabel && allIds.indexOf(id) !== -1) {
			openPop(b, id);
			return;
		}
		toggle(id);
	});

	if (typeof MutationObserver !== 'undefined') {
		var deb;
		new MutationObserver(function () {
			clearTimeout(deb);
			deb = setTimeout(paintHearts, 150);
		}).observe(document.body, { childList: true, subtree: true });
	}

	// ── Página de listas ──────────────────────────────────────────────────
	var pageEl = document.getElementById('plw-page');
	var active = ''; // token de la lista activa.
	var sharedToken = new URLSearchParams(location.search).get('lista');

	function esc(s) {
		var d = document.createElement('div');
		d.textContent = s == null ? '' : String(s);
		return d.innerHTML;
	}
	var escHtml = esc;

	function renderSidebar() {
		var ul = document.getElementById('plw-lists');
		if (cfg.loggedIn) {
			ul.innerHTML = lists.map(function (l) {
				return '<li><button type="button" class="plw-list-item' + (l.token === active ? ' is-active' : '') + '" data-token="' + l.token + '">'
					+ '<span>' + esc(l.name) + '</span><em>' + l.count + '</em></button></li>';
			}).join('');
		} else {
			ul.innerHTML = '<li><button type="button" class="plw-list-item is-active"><span>Tu lista (en este navegador)</span><em>' + allIds.length + '</em></button></li>';
			document.getElementById('plw-new').hidden = true;
			document.getElementById('plw-guest-cta').hidden = false;
		}
	}

	function lsNotes() {
		try {
			return JSON.parse(localStorage.getItem('plw_notes')) || {};
		} catch (e) {
			return {};
		}
	}

	function lsGifts() {
		try {
			return JSON.parse(localStorage.getItem('plw_gifts')) || {};
		} catch (e) {
			return {};
		}
	}

	function renderItems(items, readonly, extra) {
		extra = extra || {};
		var notes = extra.notes || (cfg.loggedIn ? {} : lsNotes());
		var reserved = extra.reserved || [];
		var myGifts = (lsGifts()[sharedToken] || {});
		var itemsEl = document.getElementById('plw-items');
		var actions = document.getElementById('plw-actions');
		if (!items.length) {
			itemsEl.innerHTML = '<p class="plw-empty">Aún no hay nada aquí. Toca el corazón ❤️ de lo que te guste.</p>';
			actions.hidden = true;
			return;
		}
		var moveOpts = '';
		if (!readonly && cfg.loggedIn && lists.length > 1) {
			moveOpts = lists.filter(function (l) { return l.token !== active; })
				.map(function (l) { return '<option value="' + l.token + '">' + esc(l.name) + '</option>'; }).join('');
		}
		var html = '<div class="plw-grid">';
		items.forEach(function (it) {
			var note = notes[it.id] || '';
			var isReserved = reserved.indexOf(it.id) !== -1;
			var isMine = !!myGifts[it.id];
			html += '<div class="plw-card" data-id="' + it.id + '">'
				+ (readonly ? '' : '<button type="button" class="plw-remove" aria-label="Quitar">×</button>')
				+ '<a href="' + esc(it.permalink) + '">' + (it.img ? '<img src="' + esc(it.img) + '" alt="" loading="lazy">' : '')
				+ '<span class="plw-name">' + esc(it.name) + '</span></a>'
				+ '<span class="plw-price">' + esc(it.price) + '</span>'
				+ (note && !readonly ? '<span class="plw-note">📝 ' + esc(note) + '</span>' : '')
				+ (it.in_stock
					? (it.simple ? '<button type="button" class="plw-add plw-btn-primary" data-add="' + it.id + '">Al carrito 🛒</button>'
						: '<a class="plw-btn-ghost" href="' + esc(it.permalink) + '">Escoger</a>')
					: '<span class="plw-out">Agotado</span>')
				// Vista compartida: reservar el regalo (anónimo, el dueño no lo ve).
				+ (readonly && sharedToken
					? (isMine
						? '<button type="button" class="plw-gift plw-gift-mine" data-gift="' + it.id + '" data-cancel="1">Reservado por ti — cancelar 🎁</button>'
						: (isReserved
							? '<span class="plw-gift-taken">🎁 Alguien ya lo va a regalar</span>'
							: '<button type="button" class="plw-gift plw-btn-ghost" data-gift="' + it.id + '">Lo voy a regalar 🎁</button>'))
					: '')
				+ (!readonly ? '<button type="button" class="plw-note-btn" data-note="' + it.id + '">📝 ' + (note ? 'Editar nota' : 'Nota') + '</button>' : '')
				+ (moveOpts ? '<select class="plw-move" data-id="' + it.id + '"><option value="">Mover a…</option>' + moveOpts + '</select>' : '')
				+ '</div>';
		});
		html += '</div>';
		itemsEl.innerHTML = html;
		actions.hidden = readonly || !items.some(function (it) { return it.in_stock && it.simple; });
	}

	function activeList() {
		return lists.filter(function (x) { return x.token === active; })[0];
	}

	function paintPrivacy() {
		var btn = document.getElementById('plw-priv-btn');
		var l = activeList();
		if (btn && l) {
			btn.textContent = l.private ? '🔒 Privada' : '🌍 Pública';
		}
	}

	function openList(token) {
		active = token;
		renderSidebar();
		document.getElementById('plw-share').hidden = true;
		var l = activeList();
		document.getElementById('plw-title').textContent = l ? l.name : 'Mi lista de deseos';
		document.getElementById('plw-tools').hidden = false;
		paintPrivacy();
		get('list?token=' + encodeURIComponent(token)).then(function (d) {
			renderItems(d.items || [], false, { notes: d.notes || {} });
		});
	}

	function renderPage() {
		// Vista compartida (enlace de regalo): solo lectura.
		if (sharedToken) {
			document.getElementById('plw-sidebar').hidden = true;
			get('list?token=' + encodeURIComponent(sharedToken)).then(function (d) {
				if (d.code) {
					document.getElementById('plw-items').innerHTML = '<p class="plw-empty">Esta lista ya no existe. 😢</p>';
					return;
				}
				document.getElementById('plw-title').textContent = d.name || 'Lista de deseos';
				if (d.owner) {
					var o = document.getElementById('plw-owner');
					o.hidden = false;
					o.textContent = 'Lista de ' + d.owner + ' — regálale algo 🎁';
				}
				renderItems(d.items || [], !d.mine, { reserved: d.reserved || [], notes: d.notes || {} });
			});
			return;
		}

		if (!cfg.loggedIn) {
			renderSidebar();
			document.getElementById('plw-title').textContent = 'Tu lista de deseos';
			var ids = lsGet();
			(ids.length ? get('products?ids=' + ids.join(',')) : Promise.resolve({ items: [] }))
				.then(function (d) { renderItems(d.items || [], false); });
			return;
		}

		// Toggle de alertas 🔔 (solo logueados).
		var sb = document.getElementById('plw-sidebar');
		if (sb && cfg.alertsSite && !document.getElementById('plw-alerts')) {
			var lbl = document.createElement('label');
			lbl.id = 'plw-alerts';
			lbl.innerHTML = '<input type="checkbox"' + (cfg.alertsOn ? ' checked' : '') + '> 🔔 Avisarme por correo si bajan de precio o vuelven a stock';
			sb.appendChild(lbl);
			lbl.querySelector('input').addEventListener('change', function () {
				post('alerts', { on: this.checked ? 1 : 0 });
				toast(this.checked ? 'Alertas activadas 🔔' : 'Alertas apagadas 🔕', false);
			});
		}

		renderSidebar();
		if (lists.length) {
			openList(lists[0].token);
		}

		// Sidebar: cambiar de lista.
		document.getElementById('plw-lists').addEventListener('click', function (ev) {
			var b = ev.target.closest('.plw-list-item');
			if (b && b.dataset.token) {
				openList(b.dataset.token);
			}
		});

		// Crear lista.
		document.getElementById('plw-new-btn').addEventListener('click', function () {
			var inp = document.getElementById('plw-new-name');
			var name = inp.value.trim();
			if (!name) {
				inp.focus();
				return;
			}
			post('lists', { action: 'create', name: name }).then(function (d) {
				lists = d.lists;
				inp.value = '';
				openList(d.token);
				toast('Lista "' + name + '" creada ✨', false);
			}).catch(function (e) { toast((e && e.message) || 'No se pudo crear.', false); });
		});

		// Renombrar / eliminar / compartir.
		document.getElementById('plw-rename-btn').addEventListener('click', function () {
			var l = lists.filter(function (x) { return x.token === active; })[0];
			var name = prompt('Nuevo nombre de la lista:', l ? l.name : '');
			if (!name) {
				return;
			}
			post('lists', { action: 'rename', token: active, name: name }).then(function (d) {
				lists = d.lists;
				renderSidebar();
				document.getElementById('plw-title').textContent = name;
			});
		});

		document.getElementById('plw-delete-btn').addEventListener('click', function () {
			if (!confirm('¿Eliminar esta lista y todo lo que tiene?')) {
				return;
			}
			post('lists', { action: 'delete', token: active }).then(function (d) {
				lists = d.lists;
				openList(lists[0].token);
				toast('Lista eliminada', false);
			}).catch(function (e) { toast((e && e.message) || 'No se pudo eliminar.', false); });
		});

		// Privacidad: alterna pública 🌍 / privada 🔒 (privada = el enlace
		// compartido deja de funcionar para otros).
		document.getElementById('plw-priv-btn').addEventListener('click', function () {
			var l = activeList();
			if (!l) {
				return;
			}
			post('lists', { action: 'privacy', token: active, private: l.private ? 0 : 1 }).then(function (d) {
				lists = d.lists;
				paintPrivacy();
				renderSidebar();
				toast(activeList().private ? 'Lista privada 🔒 — solo tú la ves' : 'Lista pública 🌍 — ya puedes compartirla', false);
			});
		});

		document.getElementById('plw-share-btn').addEventListener('click', function () {
			var box = document.getElementById('plw-share');
			// Privada: ofrecer hacerla pública antes de compartir.
			var l = activeList();
			if (box.hidden && l && l.private) {
				if (!confirm('Esta lista es privada 🔒. ¿La hacemos pública para poder compartirla?')) {
					return;
				}
				post('lists', { action: 'privacy', token: active, private: 0 }).then(function (d) {
					lists = d.lists;
					paintPrivacy();
				});
			}
			box.hidden = !box.hidden;
			if (box.hidden || !cfg.pageUrl) {
				return;
			}
			var listUrl = cfg.pageUrl + (cfg.pageUrl.indexOf('?') === -1 ? '?' : '&') + 'lista=' + active;
			document.getElementById('plw-share-url').value = listUrl;

			// Apuntar la barra opcional de compartir (filtro plw_share_bar_html) a ESTA lista.
			var bar = box.querySelector('.pld-share');
			if (bar) {
				var l = lists.filter(function (x) { return x.token === active; })[0];
				var newTitle = (l ? l.name : 'Mi lista de deseos') + (cfg.siteName ? ' en ' + cfg.siteName : '');
				var oldU = encodeURIComponent(bar.dataset.url);
				var newU = encodeURIComponent(listUrl);
				var oldT = encodeURIComponent(bar.dataset.title);
				var newT = encodeURIComponent(newTitle);
				bar.querySelectorAll('a[href]').forEach(function (a) {
					a.href = a.href.split(oldU).join(newU).split(oldT).join(newT);
				});
				bar.dataset.url = listUrl;
				bar.dataset.title = newTitle;
			}
		});

		document.getElementById('plw-share-copy').addEventListener('click', function () {
			var inp = document.getElementById('plw-share-url');
			inp.select();
			(navigator.clipboard ? navigator.clipboard.writeText(inp.value) : Promise.reject()).catch(function () {
				document.execCommand('copy');
			});
			this.textContent = '¡Copiado!';
			var btn = this;
			setTimeout(function () { btn.textContent = 'Copiar'; }, 2000);
		});
	}

	// ── Acciones dentro del grid (delegado, vive en todas las vistas) ─────
	if (pageEl) {
		var storeNonce = '';
		fetch(cfg.storeApi + 'cart', { credentials: 'same-origin' }).then(function (r) {
			storeNonce = r.headers.get('Nonce') || '';
		});

		function storeAdd(id) {
			return fetch(cfg.storeApi + 'cart/add-item', {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json', Nonce: storeNonce },
				body: JSON.stringify({ id: id, quantity: 1 })
			}).then(function (r) {
				storeNonce = r.headers.get('Nonce') || storeNonce;
				return r;
			});
		}

		document.getElementById('plw-items').addEventListener('click', function (ev) {
			var rm = ev.target.closest('.plw-remove');
			if (rm) {
				var card = rm.closest('.plw-card');
				var id = parseInt(card.dataset.id, 10);
				card.remove();
				if (cfg.loggedIn) {
					post('toggle', { id: id, token: active }).then(function (d) {
						allIds = d.allIds;
						lists = d.lists;
						renderSidebar();
						paintHearts();
					});
				} else {
					allIds = allIds.filter(function (x) { return x !== id; });
					lsSet(allIds);
					renderSidebar();
					paintHearts();
				}
				return;
			}
			var add = ev.target.closest('[data-add]');
			if (add) {
				add.disabled = true;
				storeAdd(parseInt(add.dataset.add, 10)).then(function (r) {
					add.textContent = r.ok ? 'Agregado ✓' : 'Error';
					if (r.ok && window.jQuery) {
						window.jQuery(document.body).trigger('added_to_cart');
					}
				});
				return;
			}

			// Nota personal del libro.
			var nb = ev.target.closest('[data-note]');
			if (nb) {
				var nid = parseInt(nb.dataset.note, 10);
				var current = cfg.loggedIn ? '' : (lsNotes()[nid] || '');
				var txt = prompt('Nota para este producto (ej. "cumpleaños de mamá 🎂"):', current);
				if (null === txt) {
					return;
				}
				if (cfg.loggedIn) {
					post('note', { token: active, id: nid, note: txt }).then(function () {
						openList(active);
					});
				} else {
					var n = lsNotes();
					if (txt) { n[nid] = txt; } else { delete n[nid]; }
					try { localStorage.setItem('plw_notes', JSON.stringify(n)); } catch (e) {}
					renderPage();
				}
				return;
			}

			// Reservar / cancelar regalo (vista compartida).
			var gift = ev.target.closest('[data-gift]');
			if (gift) {
				var gid = parseInt(gift.dataset.gift, 10);
				var cancel = !!gift.dataset.cancel;
				gift.disabled = true;
				post('reserve', { token: sharedToken, id: gid, cancel: cancel ? 1 : 0 }).then(function (d) {
					var gifts = lsGifts();
					gifts[sharedToken] = gifts[sharedToken] || {};
					if (cancel) { delete gifts[sharedToken][gid]; } else { gifts[sharedToken][gid] = 1; }
					try { localStorage.setItem('plw_gifts', JSON.stringify(gifts)); } catch (e) {}
					toast(cancel ? 'Reserva cancelada' : '¡Regalo reservado! 🎁 Nadie más lo verá disponible.', false);
					renderPage();
				}).catch(function (e) {
					gift.disabled = false;
					toast((e && e.message) || 'No se pudo reservar.', false);
				});
			}
		});

		document.getElementById('plw-items').addEventListener('change', function (ev) {
			var sel = ev.target.closest('.plw-move');
			if (!sel || !sel.value) {
				return;
			}
			post('move', { id: parseInt(sel.dataset.id, 10), from: active, to: sel.value }).then(function (d) {
				lists = d.lists;
				sel.closest('.plw-card').remove();
				renderSidebar();
				toast('Movido ✨', false);
			});
		});

		var addAll = document.getElementById('plw-add-all');
		addAll.addEventListener('click', function () {
			addAll.disabled = true;
			var btns = Array.prototype.slice.call(document.querySelectorAll('#plw-items [data-add]'));
			// En serie: la Store API rechaza ráfagas paralelas sobre el carrito.
			btns.reduce(function (p, b) {
				return p.then(function () {
					b.disabled = true;
					return storeAdd(parseInt(b.dataset.add, 10)).then(function (r) {
						b.textContent = r.ok ? 'Agregado ✓' : 'Error';
					});
				});
			}, Promise.resolve()).then(function () {
				addAll.textContent = '¡Todo en el carrito! 🛒';
				if (window.jQuery) {
					window.jQuery(document.body).trigger('added_to_cart');
				}
			});
		});
	}

	init();
})();
