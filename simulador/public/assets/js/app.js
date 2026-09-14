// FBA Games · Simulador — interações globais da casca do jogo.
(function () {
  // ─── Diálogo do jogo: substitui o alert/confirm do navegador ───────────────
  let current = null;

  function show(opts) {
    if (current) current(false);
    return new Promise(function (resolve) {
      const prevFocus = document.activeElement;
      const wrap = document.createElement('div');
      wrap.className = 'fba-dialog';
      const box = document.createElement('div');
      box.className = 'fd-box' + (opts.danger ? ' fd-danger' : '') + (opts.alert ? ' fd-alert' : '');
      box.setAttribute('role', opts.alert ? 'alertdialog' : 'dialog');
      box.setAttribute('aria-modal', 'true');
      box.setAttribute('aria-labelledby', 'fdTitle');
      box.setAttribute('aria-describedby', 'fdMsg');

      const head = document.createElement('div');
      head.className = 'fd-head';
      const icon = document.createElement('span');
      icon.className = 'fd-icon';
      icon.setAttribute('aria-hidden', 'true');
      icon.textContent = opts.icon || (opts.danger ? '⚠️' : (opts.alert ? '📣' : '🏀'));
      const title = document.createElement('h3');
      title.className = 'fd-title';
      title.id = 'fdTitle';
      title.textContent = opts.title || (opts.alert ? 'Aviso' : 'Confirmar');
      head.append(icon, title);

      const msg = document.createElement('p');
      msg.className = 'fd-msg';
      msg.id = 'fdMsg';
      msg.textContent = opts.message || '';

      const actions = document.createElement('div');
      actions.className = 'fd-actions';
      const ok = document.createElement('button');
      ok.type = 'button';
      ok.className = 'fd-btn fd-ok' + (opts.danger ? ' fd-ok-danger' : '');
      ok.textContent = opts.ok || (opts.alert ? 'Entendi' : 'Confirmar');
      let cancel = null;
      if (!opts.alert) {
        cancel = document.createElement('button');
        cancel.type = 'button';
        cancel.className = 'fd-btn fd-cancel';
        cancel.textContent = opts.cancel || 'Cancelar';
        actions.append(cancel);
      }
      actions.append(ok);
      box.append(head, msg, actions);
      wrap.append(box);

      function close(val) {
        if (current !== close) return;
        current = null;
        document.removeEventListener('keydown', onKey, true);
        wrap.classList.add('fd-out');
        setTimeout(function () { wrap.remove(); }, 160);
        if (prevFocus && typeof prevFocus.focus === 'function') { try { prevFocus.focus({ preventScroll: true }); } catch (err) {} }
        resolve(opts.alert ? true : val);
      }
      function onKey(e) {
        if (e.key === 'Escape') { e.preventDefault(); close(false); return; }
        if (e.key === 'Tab') {
          const f = cancel ? [cancel, ok] : [ok];
          const i = f.indexOf(document.activeElement);
          e.preventDefault();
          f[(i + (e.shiftKey ? f.length - 1 : 1) + f.length) % f.length].focus();
          return;
        }
        if (e.key === 'Enter' && !box.contains(document.activeElement)) { e.preventDefault(); close(true); }
      }
      ok.addEventListener('click', function () { close(true); });
      if (cancel) cancel.addEventListener('click', function () { close(false); });
      wrap.addEventListener('click', function (e) { if (e.target === wrap) close(false); });
      document.addEventListener('keydown', onKey, true);

      current = close;
      document.body.append(wrap);
      ok.focus({ preventScroll: true });
    });
  }

  window.fbaConfirm = function (message, opts) { return show(Object.assign({}, opts || {}, { message: String(message) })); };
  window.fbaAlert = function (message, opts) { return show(Object.assign({}, opts || {}, { message: String(message), alert: true })); };
  // qualquer alert() esquecido também abre o diálogo do jogo
  window.alert = function (m) { window.fbaAlert(m); };

  // ─── Tela de "simulando": ações que rodam dias mostram a bola quicando ─────
  const BUSY = {
    'advance': 'Simulando o dia',
    'sim-days': 'Simulando a semana',
    'sim-season': 'Simulando a temporada',
    'sim-round': 'Simulando a rodada',
    'sim-game-ai': 'Simulando seu jogo',
    'sim-game': 'Simulando o jogo',
    'preseason-advance': 'Avançando a pré-temporada',
    'preseason-finish': 'Encerrando a pré-temporada',
    'next-season': 'Rodando a entressafra',
    'finish-fa': 'Montando os elencos',
    'start-draft': 'Abrindo o draft',
    'draft-pick': 'Os outros times estão escolhendo'
  };
  function busyText(el) {
    if (el.dataset && el.dataset.busy) return el.dataset.busy;
    const m = /[?&]action=([a-z-]+)/.exec(el.getAttribute('href') || '');
    return m && BUSY[m[1]] ? BUSY[m[1]] : null;
  }
  function showBusy(text) {
    let el = document.getElementById('simload');
    if (!el) {
      el = document.createElement('div');
      el.id = 'simload';
      el.className = 'simload';
      el.setAttribute('role', 'status');
      el.innerHTML = '<div class="simload-box"><div class="simload-ball"></div><b></b><small>Isso leva alguns segundos.</small></div>';
      document.body.append(el);
    }
    el.querySelector('b').textContent = text + '…';
    el.hidden = false;
  }
  window.fbaBusy = showBusy;
  // voltar pelo histórico não pode deixar a tela de carregamento presa
  window.addEventListener('pageshow', function () {
    const el = document.getElementById('simload');
    if (el) el.hidden = true;
  });

  // Links e botões com data-confirm pedem confirmação no diálogo do jogo.
  document.addEventListener('click', function (e) {
    const el = e.target.closest ? e.target.closest('[data-confirm]') : null;
    if (!el) return;
    if (el.dataset.confirmPass === '1') { delete el.dataset.confirmPass; return; }
    e.preventDefault();
    e.stopImmediatePropagation();
    window.fbaConfirm(el.dataset.confirm, {
      title: el.dataset.confirmTitle,
      ok: el.dataset.confirmOk,
      danger: el.hasAttribute('data-confirm-danger')
    }).then(function (yes) {
      if (!yes) return;
      const t = busyText(el);
      if (el.tagName === 'A' && el.href) { if (t) showBusy(t); window.location.href = el.href; return; }
      const form = el.form || el.closest('form');
      if (form && el.type === 'submit') {
        if (typeof form.requestSubmit === 'function') form.requestSubmit(el);
        else { if (t) showBusy(t); form.submit(); }
        return;
      }
      el.dataset.confirmPass = '1';
      el.click();
    });
  }, true);

  // Link comum que simula (sem confirmação): mostra a tela e segue.
  document.addEventListener('click', function (e) {
    if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
    const a = e.target.closest ? e.target.closest('a[href]') : null;
    if (!a || a.target === '_blank') return;
    const t = busyText(a);
    if (t) showBusy(t);
  });
  document.addEventListener('submit', function (e) {
    const f = e.target;
    if (!e.defaultPrevented && f && f.dataset && f.dataset.busy) showBusy(f.dataset.busy);
  });

  // ─── Toasts: somem sozinhos, e o aviso sai da URL pra não voltar no F5 ─────
  document.querySelectorAll('.toast').forEach(function (t) {
    const close = function () { t.classList.add('out'); setTimeout(function () { t.remove(); }, 320); };
    const x = t.querySelector('.t-x');
    if (x) x.addEventListener('click', close);
    setTimeout(close, t.classList.contains('bad') ? 9000 : 5000);
  });
  try {
    const u = new URL(window.location.href);
    let changed = false;
    ['msg', 'err', 'dmsg', 'autosaved'].forEach(function (k) {
      if (u.searchParams.has(k)) { u.searchParams.delete(k); changed = true; }
    });
    if (changed) history.replaceState(history.state, '', u.pathname + u.search + u.hash);
  } catch (err) {}

  // ─── Menu "mais opções" do dock: fecha ao tocar fora ou no Esc ─────────────
  document.addEventListener('click', function (e) {
    document.querySelectorAll('details.dock-menu[open]').forEach(function (d) {
      if (!d.contains(e.target)) d.removeAttribute('open');
    });
  });
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    document.querySelectorAll('details.dock-menu[open]').forEach(function (d) { d.removeAttribute('open'); });
  });
})();
