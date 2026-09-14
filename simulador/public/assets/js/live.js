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
  const boxTitle = document.getElementById('boxTitle');

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
  function icon(name) { return '<i class="bi bi-' + name + '" aria-hidden="true"></i>'; }
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
    const isHome = ev.team === meta.home_id;
    const abbr = isHome ? meta.home_abbr : meta.away_abbr;
    const scored = (ev.t === 'made' || ev.t === 'ft') && ev.home_pts !== undefined;
    const div = document.createElement('div');
    div.className = classFor(ev) + (isHome ? ' home' : ' away');
    div.innerHTML = '<span class="pbp-meta">' + escapeHtml((ev.q + ' ' + ev.clock).trim()) + '</span>' +
      '<span class="pbp-tag">' + escapeHtml(abbr) + '</span>' +
      '<span class="pbp-txt">' + escapeHtml(ev.text) + '</span>' +
      (scored ? '<span class="pbp-score">' + ev.away_pts + '-' + ev.home_pts + '</span>' : '');
    feed.appendChild(div);
    feed.scrollTop = feed.scrollHeight;
  }
  function sep(txt) {
    const div = document.createElement('div');
    div.className = 'pbp-sep';
    div.innerHTML = '<span>' + escapeHtml(txt) + '</span>';
    feed.appendChild(div);
  }
  function bump(el) { el.classList.add('bump'); setTimeout(() => el.classList.remove('bump'), 150); }
  function delay(ms) { return new Promise(r => setTimeout(r, ms)); }
  function markFinal(home, away) {
    const sb = document.getElementById('scoreboard');
    if (!sb) return;
    sb.classList.add('is-final');
    const h = sb.querySelector('.sb-team.home'), a = sb.querySelector('.sb-team.away');
    if (h) h.classList.add(+home > +away ? 'won' : 'lost');
    if (a) a.classList.add(+away > +home ? 'won' : 'lost');
  }

  async function start() {
    if (started) return true;
    let data;
    try {
      const res = await fetch((window.API_URL || 'api.php') + '?live=start&game=' + window.GAME_ID);
      data = await res.json();
    } catch (e) { window.fbaAlert('Erro ao iniciar o jogo.'); return false; }
    if (data.error) { window.fbaAlert(data.error); return false; }

    meta = data.game;
    window.HOME_ID = meta.home_id; window.AWAY_ID = meta.away_id;
    sideLbl.textContent = 'Você comanda ' + (data.gm_side === 'home' ? meta.home_abbr : meta.away_abbr);
    offSel.length = 0; defSel.length = 0;
    data.schemes_off.forEach(s => offSel.add(new Option(s, s)));
    data.schemes_def.forEach(s => defSel.add(new Option(s, s)));
    // dica do confronto: o que o ataque e a defesa escolhidos castigam e o que os segura
    const tacticHint = document.getElementById('tacticHint');
    const info = data.scheme_info || {};
    const showHint = () => {
      if (!tacticHint) return;
      tacticHint.textContent = [info[offSel.value], info[defSel.value]].filter(Boolean).join(' ');
    };
    offSel.addEventListener('change', showHint);
    defSel.addEventListener('change', showHint);
    showHint();
    data.opp_players.forEach(p => doubleSel.add(new Option(p.name + ' · ' + p.pos + ' · OVR ' + p.ovr, p.id)));
    if (data.cur_timeouts) toLbl.textContent = data.cur_timeouts[data.gm_side];
    sbHome.textContent = data.score.home; sbAway.textContent = data.score.away;
    if (data.resumed && data.period > 0) {
      if (empty) { empty.remove(); empty = null; }
      sbQuarter.textContent = qHuman(qFromPeriod(data.period));
      sep('jogo retomado · ' + qHuman(qFromPeriod(data.period)) + ' · ' + data.score.away + '-' + data.score.home);
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
      } catch (e) { window.fbaAlert('Erro ao simular.'); return; }
      if (data.error) { window.fbaAlert(data.error); return; }

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
        hint.innerHTML = icon('fire') + ' Clutch time! Jogo apertado: ajuste a tática e decida o jogo.';
        hint.classList.add('clutch');
      }

      if (data.done) {
        finished = true;
        sbClock.textContent = 'FINAL'; sbQuarter.textContent = 'Encerrado';
        markFinal(data.score.home, data.score.away);
        nextBtn.style.display = 'none'; autoBtn.style.display = 'none';
        const det = document.querySelector('.live-tactics'); if (det) det.style.display = 'none';
        hint.innerHTML = icon('check-circle-fill') + ' Jogo encerrado. O resultado já está salvo.';
        hint.classList.remove('clutch');
        panel.classList.add('is-done');
        renderBox(data.box);
        const bar = document.getElementById('continueBar');
        if (bar) {
          bar.hidden = false; bar.style.display = '';
          bar.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
      }
    } catch (e) {
      console.error('Erro ao processar o quarto simulado:', e);
      window.fbaAlert('Ocorreu um erro inesperado ao simular. Tente novamente.');
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
      window.fbaAlert('Não foi possível concluir a simulação automática do jogo. Tente simular quarto a quarto.');
      nextBtn.disabled = false; autoBtn.disabled = false;
    }
  }

  // Box score: a mesma tabela que o renderBoxScore do game.php monta.
  const BOX_COLS = ['min', 'pts', 'reb', 'ast', 'stl', 'blk', 'tov', 'fgm', 'fga', 'tpm', 'tpa', 'ftm', 'fta'];
  const BOX_HEAD = '<thead><tr><th>Jogador</th><th class="num hide-sm" title="Minutos">MIN</th><th class="num" title="Pontos">PTS</th>' +
    '<th class="num" title="Rebotes">REB</th><th class="num" title="Assistências">AST</th>' +
    '<th class="num hide-sm" title="Roubos de bola">RB</th><th class="num hide-sm" title="Tocos">TOC</th>' +
    '<th class="num hide-sm" title="Erros">ERR</th><th class="num" title="Arremessos de quadra">FG</th>' +
    '<th class="num" title="Bolas de três">3P</th><th class="num hide-sm" title="Lances livres">LL</th></tr></thead>';
  function boxCells(v) {
    return '<td class="num hide-sm">' + Math.round(v.min) + '</td><td class="num"><b>' + v.pts + '</b></td>' +
      '<td class="num">' + v.reb + '</td><td class="num">' + v.ast + '</td>' +
      '<td class="num hide-sm">' + v.stl + '</td><td class="num hide-sm">' + v.blk + '</td>' +
      '<td class="num hide-sm">' + v.tov + '</td>' +
      '<td class="num">' + v.fgm + '-' + v.fga + '</td><td class="num">' + v.tpm + '-' + v.tpa + '</td>' +
      '<td class="num hide-sm">' + v.ftm + '-' + v.fta + '</td>';
  }
  function boxTeam(side, tname, rows) {
    const tot = {};
    BOX_COLS.forEach(k => { tot[k] = 0; });
    let body = '';
    for (const b of rows) {
      const v = {};
      BOX_COLS.forEach(k => { v[k] = Number(b[k]) || 0; tot[k] += v[k]; });
      body += '<tr><td><a class="bx-p" href="index.php?p=player&amp;id=' + encodeURIComponent(b.player_id) + '"><b>' +
        escapeHtml(b.name) + '</b><span class="pos-tag">' + escapeHtml(b.pos) + '</span></a></td>' + boxCells(v) + '</tr>';
    }
    if (!rows.length) body = '<tr><td colspan="11" class="dim">Sem estatísticas registradas.</td></tr>';
    return '<div class="box-team ' + side + '"><h3 class="box-team-h"><span class="box-dot"></span>' + escapeHtml(tname) + '</h3>' +
      '<div class="table-wrap"><table class="tbl compact box-tbl">' + BOX_HEAD + '<tbody>' + body + '</tbody>' +
      (rows.length ? '<tfoot><tr><td>Total</td>' + boxCells(tot) + '</tr></tfoot>' : '') + '</table></div></div>';
  }

  function renderBox(box) {
    if (!boxCard || !box) return;
    boxCard.style.display = '';
    if (boxTitle) boxTitle.textContent = 'Box score';
    const names = window.GAME_META || {};
    const teams = [['away', meta.away_id, names.away_name || meta.away_name], ['home', meta.home_id, names.home_name || meta.home_name]];
    let html = '<div class="box-teams">';
    for (const [side, tid, tname] of teams) {
      const rows = box.filter(b => b.team_id == tid).sort((a, b) => b.pts - a.pts);
      html += boxTeam(side, tname, rows);
    }
    boxContent.innerHTML = html + '</div>';
  }

  nextBtn.addEventListener('click', () => step(false));
  autoBtn.addEventListener('click', auto);
  start(); // popula opções e placar ao carregar
})();
