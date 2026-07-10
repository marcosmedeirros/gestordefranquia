// SimCast AO VIVO: o GM comanda o jogo quarto a quarto (tática, marcação dupla, timeouts).
(function () {
  const panel = document.getElementById('livePanel');
  if (!panel) return;

  const feed = document.getElementById('pbpFeed');
  let empty = document.getElementById('pbpEmpty');
  const sbHome = document.getElementById('sb-home');
  const sbAway = document.getElementById('sb-away');
  const sbClock = document.getElementById('sb-clock');
  const sbQuarter = document.getElementById('sb-quarter');
  const speedSel = document.getElementById('speedSel');
  const boxCard = document.getElementById('boxCard');
  const boxContent = document.getElementById('boxContent');

  const offSel = document.getElementById('ctrlOff');
  const defSel = document.getElementById('ctrlDef');
  const doubleSel = document.getElementById('ctrlDouble');
  const timeoutChk = document.getElementById('ctrlTimeout');
  const nextBtn = document.getElementById('liveNextBtn');
  const autoBtn = document.getElementById('liveAutoBtn');
  const sideLbl = document.getElementById('liveSide');
  const toLbl = document.getElementById('liveTimeouts');
  const hint = document.getElementById('liveHint');

  let meta = null, busy = false, finished = false, started = false;

  function qHuman(q) {
    if (q === 'Q1') return '1º Quarto';
    if (q === 'Q2') return '2º Quarto';
    if (q === 'Q3') return '3º Quarto';
    if (q === 'Q4') return '4º Quarto';
    if (q.startsWith('PR')) return 'Prorrogação ' + (q.slice(2) || '1');
    return q;
  }
  function qFromPeriod(p) { return p > 4 ? ('PR' + (p - 4)) : ('Q' + p); }
  function escapeHtml(s) {
    return String(s).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
  }
  function classFor(ev) {
    let c = 'pbp-item';
    if (ev.t === 'made') c += /3/.test(ev.text) ? ' made three' : ' made';
    else if (ev.t === 'ft') c += ' ft';
    else if (ev.t === 'block') c += ' block';
    else if (ev.t === 'injury') c += ' injury';
    else if (ev.t === 'timeout') c += ' timeout-ev';
    else if (ev.t === 'miss' || ev.t === 'turnover') c += ' ' + ev.t;
    return c;
  }
  function addItem(ev) {
    const abbr = ev.team === meta.home_id ? meta.home_abbr : meta.away_abbr;
    const div = document.createElement('div');
    div.className = classFor(ev);
    div.innerHTML = '<span class="pbp-meta">' + ev.q + ' ' + ev.clock + ' · ' + abbr + '</span>' + escapeHtml(ev.text);
    feed.appendChild(div);
    feed.scrollTop = feed.scrollHeight;
  }
  function sep(txt) {
    const div = document.createElement('div');
    div.className = 'pbp-item quarter-sep';
    div.textContent = '— ' + txt + ' —';
    feed.appendChild(div);
  }
  function bump(el) { el.classList.add('bump'); setTimeout(() => el.classList.remove('bump'), 150); }
  function delay(ms) { return new Promise(r => setTimeout(r, ms)); }

  async function start() {
    if (started) return true;
    let data;
    try {
      const res = await fetch((window.API_URL || 'api.php') + '?live=start&game=' + window.GAME_ID);
      data = await res.json();
    } catch (e) { alert('Erro ao iniciar o jogo.'); return false; }
    if (data.error) { alert(data.error); return false; }

    meta = data.game;
    window.HOME_ID = meta.home_id; window.AWAY_ID = meta.away_id;
    sideLbl.textContent = '(você comanda ' + (data.gm_side === 'home' ? meta.home_abbr : meta.away_abbr) + ')';
    offSel.length = 0; defSel.length = 0;
    data.schemes_off.forEach(s => offSel.add(new Option(s, s)));
    data.schemes_def.forEach(s => defSel.add(new Option(s, s)));
    data.opp_players.forEach(p => doubleSel.add(new Option(p.name + ' (' + p.pos + ' ' + p.ovr + ')', p.id)));
    if (data.cur_timeouts) toLbl.textContent = data.cur_timeouts[data.gm_side];
    sbHome.textContent = data.score.home; sbAway.textContent = data.score.away;
    if (data.resumed && data.period > 0) {
      if (empty) { empty.remove(); empty = null; }
      sbQuarter.textContent = qHuman(qFromPeriod(data.period));
      sep('jogo retomado · ' + qHuman(qFromPeriod(data.period)) + ' ' + data.score.away + '-' + data.score.home);
    }
    started = true;
    return true;
  }

  async function step(fast) {
    if (busy || finished) return;
    if (!await start()) return;
    busy = true;
    nextBtn.disabled = true; autoBtn.disabled = true;

    // Garante que os botões nunca fiquem travados: qualquer erro (rede, parsing,
    // ou uma exceção inesperada ao renderizar eventos) sempre libera busy/botões
    // em vez de deixá-los desabilitados para sempre sem explicação ao usuário.
    try {
      if (empty) { empty.remove(); empty = null; }

      const body = new URLSearchParams();
      body.set('off', offSel.value);
      body.set('def', defSel.value);
      body.set('double_team', doubleSel.value);
      if (timeoutChk.checked) body.set('timeout', '1');

      let data;
      try {
        const res = await fetch((window.API_URL || 'api.php') + '?live=step', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body });
        data = await res.json();
      } catch (e) { alert('Erro ao simular.'); return; }
      if (data.error) { alert(data.error); return; }

      timeoutChk.checked = false;
      if (typeof data.timeouts === 'object') toLbl.textContent = data.timeouts[data.gm_side];

      let lastQ = '';
      for (const ev of data.events) {
        const speed = parseInt(speedSel.value, 10);
        if (ev.q !== lastQ) { sep(qHuman(ev.q)); sbQuarter.textContent = qHuman(ev.q); lastQ = ev.q; }
        addItem(ev);
        sbClock.textContent = ev.clock;
        if (ev.home_pts !== undefined) { sbHome.textContent = ev.home_pts; bump(sbHome); }
        if (ev.away_pts !== undefined) { sbAway.textContent = ev.away_pts; bump(sbAway); }
        if (!fast && speed > 0) await delay(Math.max(15, speed / 6));
      }
      sbHome.textContent = data.score.home; sbAway.textContent = data.score.away;

      const diff = Math.abs(data.score.home - data.score.away);
      if (!data.done && data.period >= 4 && diff <= 6) {
        hint.textContent = '🔥 CLUTCH TIME! Jogo apertado — ajuste a tática e decida o jogo.';
        hint.classList.add('clutch');
      }

      if (data.done) {
        finished = true;
        sbClock.textContent = 'FINAL'; sbQuarter.textContent = 'Encerrado';
        nextBtn.style.display = 'none'; autoBtn.style.display = 'none';
        const det = document.querySelector('.live-tactics'); if (det) det.style.display = 'none';
        hint.textContent = '✅ Jogo encerrado e registrado.';
        hint.classList.remove('clutch');
        renderBox(data.box);
        const bar = document.getElementById('continueBar'); if (bar) bar.style.display = '';
      }
    } catch (e) {
      console.error('Erro ao processar o quarto simulado:', e);
      alert('Ocorreu um erro inesperado ao simular. Tente novamente.');
    } finally {
      busy = false;
      if (!finished) { nextBtn.disabled = false; autoBtn.disabled = false; }
    }
  }

  async function auto() {
    autoBtn.disabled = true; nextBtn.disabled = true;
    // Trava de segurança: um jogo regular + várias prorrogações nunca passa de
    // ~12 chamadas. Sem esse limite, qualquer estado inesperado em que "done"
    // nunca vire true faria isso rodar pra sempre e travar a aba.
    let guard = 0;
    while (!finished && guard < 20) {
      await step(true);
      guard++;
    }
    if (!finished) {
      alert('Não foi possível concluir a simulação automática do jogo. Tente simular quarto a quarto.');
      nextBtn.disabled = false; autoBtn.disabled = false;
    }
  }

  function renderBox(box) {
    if (!boxCard || !box) return;
    boxCard.style.display = '';
    const teams = [[meta.away_id, meta.away_name], [meta.home_id, meta.home_name]];
    let html = '';
    for (const [tid, tname] of teams) {
      const rows = box.filter(b => b.team_id == tid).sort((a, b) => b.pts - a.pts);
      html += '<h3 class="box-team">' + escapeHtml(tname) + '</h3>';
      html += '<table class="box-table"><thead><tr><th>Jogador</th><th>MIN</th><th>PTS</th><th>REB</th><th>AST</th><th>R/T</th><th>TO</th><th>FG</th><th>3P</th><th>LL</th></tr></thead><tbody>';
      for (const b of rows) {
        html += '<tr><td class="bx-name">' + escapeHtml(b.name) + ' <span class="muted">' + b.pos + '</span></td>' +
          '<td class="num">' + Math.round(b.min) + '</td><td class="num"><strong>' + b.pts + '</strong></td>' +
          '<td class="num">' + b.reb + '</td><td class="num">' + b.ast + '</td>' +
          '<td class="num">' + b.stl + '/' + b.blk + '</td><td class="num">' + b.tov + '</td>' +
          '<td class="num">' + b.fgm + '-' + b.fga + '</td><td class="num">' + b.tpm + '-' + b.tpa + '</td>' +
          '<td class="num">' + b.ftm + '-' + b.fta + '</td></tr>';
      }
      html += '</tbody></table>';
    }
    boxContent.innerHTML = html;
  }

  nextBtn.addEventListener('click', () => step(false));
  autoBtn.addEventListener('click', auto);
  start(); // popula opções e placar ao carregar
})();
