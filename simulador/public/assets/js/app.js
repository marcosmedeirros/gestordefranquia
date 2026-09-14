// Pequenos aprimoramentos de UX globais.

// ─── Diálogo do jogo: substitui o alert/confirm do navegador ───────────────
(function () {
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

  const BUSY_RE = /action=(advance|sim-season|sim-days|sim-round|preseason-finish|next-season|finish-fa)/;
  function markBusy(a) {
    if (a && a.matches('a.btn[href*="action="], a.gmh-next-btn[href*="action="], a.cta-btn[href*="action="]') && BUSY_RE.test(a.href)) {
      a.style.opacity = '.6';
      a.textContent = '⏳ Simulando...';
    }
  }

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
      if (el.tagName === 'A' && el.href) { markBusy(el); window.location.href = el.href; return; }
      const form = el.form || el.closest('form');
      if (form && el.type === 'submit') {
        if (typeof form.requestSubmit === 'function') form.requestSubmit(el);
        else form.submit();
        return;
      }
      el.dataset.confirmPass = '1';
      el.click();
    });
  }, true);

  // Ações que simulam dias mostram "Simulando..." no botão.
  document.addEventListener('click', function (e) {
    const a = e.target.closest ? e.target.closest('a[href*="action="]') : null;
    if (a) markBusy(a);
  });
})();

// Menu agrupado (Liga / Franquia): no toque abre no clique; fecha ao clicar fora.
document.addEventListener('click', function (e) {
  const btn = e.target.closest('.tn-group-btn');
  document.querySelectorAll('.tn-group.open').forEach(g => { if (!btn || g !== btn.parentElement) { g.classList.remove('open'); g.querySelector('.tn-group-btn').setAttribute('aria-expanded', 'false'); } });
  if (btn) {
    const g = btn.parentElement;
    const open = g.classList.toggle('open');
    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    e.preventDefault();
  }
});
