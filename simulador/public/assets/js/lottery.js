// Loteria do draft: revela as posições uma a uma, da última escolha da loteria até a 1ª.
// Antes de cada envelope abrir, um instante de suspense com os logos girando (mais longo
// nas quatro primeiras). A revelação mais recente fica em destaque no topo da lista.
(function () {
  const reveal = window.LOTTERY || [];
  const slots = document.getElementById('lotterySlots');
  const hint = document.getElementById('lotteryHint');
  const btn = document.getElementById('revealBtn');
  const allBtn = document.getElementById('revealAllBtn');
  const startBtn = document.getElementById('startDraftBtn');
  const count = document.getElementById('lotteryCount');
  const stage = document.getElementById('loteria');
  if (!btn || !slots) return;

  const calm = !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
  let i = 0;
  let busy = false;

  // logos no cache antes do sorteio: o giro não pisca
  reveal.forEach(function (r) { if (r.logo) { const im = new Image(); im.src = r.logo; } });

  function node(tag, cls, text) {
    const n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text != null) n.textContent = text;
    return n;
  }

  function icon(name) {
    const n = document.createElement('i');
    n.className = 'bi bi-' + name;
    n.setAttribute('aria-hidden', 'true');
    return n;
  }

  function chip(text, tone, ic) {
    const c = node('span', 'chip' + (tone ? ' ' + tone : ''));
    if (ic) c.appendChild(icon(ic));
    c.appendChild(document.createTextNode(text));
    return c;
  }

  function pickBox(n) {
    const box = node('span', 'lc-pick');
    box.append(node('small', '', 'Escolha'), node('b', '', String(n)));
    return box;
  }

  function logoBox(item) {
    const box = node('span', 'lc-logo');
    const img = document.createElement('img');
    img.alt = '';
    img.addEventListener('error', function () { box.classList.add('no-img'); img.remove(); });
    box.append(img, node('b', '', item.abbr));
    if (item.logo) img.src = item.logo; else box.classList.add('no-img');
    return box;
  }

  function card(item) {
    const top = item.pick === 1;
    const c = node('div', 'lot-card pop' + (top ? ' top-pick' : '') + (item.is_gm ? ' is-gm' : ''));
    if (item.color) c.style.setProperty('--tc', item.color);

    const txt = node('span', 'lc-txt');
    txt.append(node('b', '', item.name), node('small', '', item.record + (item.odds ? ' · ' + item.odds + ' de chance' : '')));

    const side = node('span', 'lc-side');
    if (top) side.appendChild(chip('1ª escolha do draft', 'go', 'trophy-fill'));
    if (item.is_gm) side.appendChild(chip('Seu time', 'team', 'person-fill'));
    if (item.proj) {
      const move = item.proj - item.pick;
      const n = Math.abs(move);
      const pos = n === 1 ? ' posição' : ' posições';
      if (move > 0) side.appendChild(chip('Subiu ' + n + pos, 'ok', 'arrow-up'));
      else if (move < 0) side.appendChild(chip('Caiu ' + n + pos, 'bad', 'arrow-down'));
      else side.appendChild(chip('Manteve a posição', '', 'dash'));
    }

    c.append(pickBox(item.pick), logoBox(item), txt, side);
    return c;
  }

  // o envelope girando: logos dos times ainda no páreo passam até o resultado sair
  function drawing(item, ms, done) {
    const pool = reveal.slice(i).sort(function () { return Math.random() - 0.5; });
    const c = node('div', 'lot-card drawing');
    const box = node('span', 'lc-logo');
    const img = document.createElement('img');
    img.alt = '';
    img.addEventListener('error', function () { img.style.visibility = 'hidden'; });
    box.appendChild(img);
    const txt = node('span', 'lc-txt');
    txt.append(node('b', '', 'Sorteando…'), node('small', '', 'Abrindo o envelope da ' + item.pick + 'ª escolha'));
    c.append(pickBox(item.pick), box, txt);
    slots.insertBefore(c, slots.firstChild);

    let k = 0;
    function spin() {
      const r = pool[k++ % pool.length];
      img.style.visibility = '';
      if (r && r.logo) img.src = r.logo;
    }
    spin();
    const timer = setInterval(spin, 120);
    setTimeout(function () { clearInterval(timer); c.remove(); done(); }, ms);
  }

  function label() {
    btn.textContent = '';
    btn.append(icon('dice-5-fill'), document.createTextNode('Revelar a ' + reveal[i].pick + 'ª escolha'));
  }

  function finish() {
    btn.hidden = true;
    if (allBtn) allBtn.hidden = true;
    if (stage) stage.classList.add('is-done');
    if (startBtn) {
      startBtn.hidden = false;
      startBtn.focus({ preventScroll: true });
    }
  }

  function place(item) {
    if (hint && hint.parentNode) hint.remove();
    slots.insertBefore(card(item), slots.firstChild);
    i++;
    if (count) count.textContent = String(i);
    if (i >= reveal.length) finish(); else label();
  }

  function lock(on) {
    busy = on;
    btn.disabled = on;
    if (allBtn) allBtn.disabled = on;
  }

  function revealNext() {
    if (busy || i >= reveal.length) return;
    if (hint && hint.parentNode) hint.remove();
    const item = reveal[i];
    const ms = calm ? 0 : (item.pick <= 4 ? 1600 : 900);
    if (!ms) { place(item); return; }
    lock(true);
    drawing(item, ms, function () { lock(false); place(item); });
  }

  function revealAll() {
    if (busy) return;
    while (i < reveal.length) place(reveal[i]);
  }

  btn.addEventListener('click', revealNext);
  if (allBtn) allBtn.addEventListener('click', revealAll);
})();
