// Simcast ao vivo: busca o play-by-play na API e narra lance a lance.
(function () {
  const startBtn = document.getElementById('startBtn');
  const feed = document.getElementById('pbpFeed');
  const empty = document.getElementById('pbpEmpty');
  const sbHome = document.getElementById('sb-home');
  const sbAway = document.getElementById('sb-away');
  const sbClock = document.getElementById('sb-clock');
  const sbQuarter = document.getElementById('sb-quarter');
  const speedSel = document.getElementById('speedSel');
  const boxCard = document.getElementById('boxCard');
  const boxContent = document.getElementById('boxContent');
  const boxTitle = document.getElementById('boxTitle');
  if (!startBtn) return;

  const meta = window.GAME_META;
  let running = false;

  function qHuman(q) {
    if (q === 'Q1') return '1º Quarto';
    if (q === 'Q2') return '2º Quarto';
    if (q === 'Q3') return '3º Quarto';
    if (q === 'Q4') return '4º Quarto';
    if (q.startsWith('PR')) return 'Prorrogação ' + (q.slice(2) || '1');
    return q;
  }

  function classFor(ev) {
    let c = 'pbp-item';
    if (ev.t === 'made') c += /3/.test(ev.text) ? ' made three' : ' made';
    else if (ev.t === 'ft') c += ' ft';
    else if (ev.t === 'block') c += ' block';
    else if (ev.t === 'injury') c += ' injury';
    else if (ev.t === 'miss' || ev.t === 'turnover') c += ' ' + ev.t;
    return c;
  }

  function addItem(ev, abbr, isHome) {
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

  function quarterSep(q) {
    const div = document.createElement('div');
    div.className = 'pbp-sep';
    div.innerHTML = '<span>' + escapeHtml(qHuman(q)) + '</span>';
    feed.appendChild(div);
  }

  function escapeHtml(s) {
    return String(s).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
  }

  function setBtn(icon, text) {
    startBtn.innerHTML = '<i class="bi bi-' + icon + '" aria-hidden="true"></i>' + escapeHtml(text);
  }

  function bump(el) {
    el.classList.add('bump');
    setTimeout(() => el.classList.remove('bump'), 150);
  }

  function delay(ms) { return new Promise(r => setTimeout(r, ms)); }

  // Vencedor e perdedor no placar (some ao reassistir, volta no fim).
  function scoreSides() {
    const sb = document.getElementById('scoreboard');
    return sb ? [sb, sb.querySelector('.sb-team.home'), sb.querySelector('.sb-team.away')] : [];
  }
  function resetFinal() {
    const [sb, h, a] = scoreSides();
    if (!sb) return;
    sb.classList.remove('is-final');
    [h, a].forEach(el => { if (el) el.classList.remove('won', 'lost'); });
  }
  function markFinal(home, away) {
    const [sb, h, a] = scoreSides();
    if (!sb) return;
    sb.classList.add('is-final');
    if (h) h.classList.add(+home > +away ? 'won' : 'lost');
    if (a) a.classList.add(+away > +home ? 'won' : 'lost');
  }

  async function run() {
    if (running) return;
    running = true;
    startBtn.disabled = true;
    setBtn('hourglass-split', 'Carregando…');
    if (empty) empty.remove();
    feed.innerHTML = '';
    sbHome.textContent = '0';
    sbAway.textContent = '0';
    resetFinal();

    let data;
    try {
      const res = await fetch(window.GAME_API);
      data = await res.json();
    } catch (e) {
      data = null;
    }
    if (!data || !data.game) {
      const msg = data && data.error ? data.error : 'Tente de novo em instantes.';
      feed.innerHTML = '<div class="empty"><b>Erro ao carregar o jogo.</b>' + escapeHtml(msg) + '</div>';
      setBtn('play-fill', 'Tentar de novo');
      running = false; startBtn.disabled = false; return;
    }

    window.HOME_ID = data.game.home_id;
    window.AWAY_ID = data.game.away_id;
    setBtn('broadcast', 'Ao vivo');

    // O restante da narração é só renderização de dados que já vieram prontos
    // da API; qualquer exceção aqui não pode deixar o botão travado em
    // "Carregando..." pra sempre sem explicação.
    try {
      let lastQ = '';
      let curHome = 0, curAway = 0;
      for (const ev of data.pbp) {
        const speed = parseInt(speedSel.value, 10);
        if (ev.q !== lastQ) { quarterSep(ev.q); sbQuarter.textContent = qHuman(ev.q); lastQ = ev.q; }

        const isHome = ev.team === data.game.home_id;
        addItem(ev, isHome ? data.game.home_abbr : data.game.away_abbr, isHome);
        sbClock.textContent = ev.clock;

        if (ev.home_pts !== curHome) { curHome = ev.home_pts; sbHome.textContent = curHome; bump(sbHome); }
        if (ev.away_pts !== curAway) { curAway = ev.away_pts; sbAway.textContent = curAway; bump(sbAway); }

        if (speed > 0) await delay(speed);
      }

      sbClock.textContent = 'FINAL' + (data.game.ot ? '/PR' + (data.game.ot > 1 ? data.game.ot : '') : '');
      sbQuarter.textContent = 'Encerrado';
      sbHome.textContent = data.game.home_pts;
      sbAway.textContent = data.game.away_pts;
      markFinal(data.game.home_pts, data.game.away_pts);
      renderBox(data);
    } catch (e) {
      console.error('Erro ao narrar o simcast:', e);
      feed.insertAdjacentHTML('beforeend', '<div class="pbp-sep err"><span>Erro ao exibir o restante do jogo</span></div>');
    } finally {
      setBtn('arrow-counterclockwise', 'Reassistir lance a lance');
      startBtn.classList.remove('btn-team');
      startBtn.disabled = false;
      running = false;
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

  function renderBox(data) {
    if (!boxCard) return;
    boxCard.style.display = '';
    if (boxTitle) boxTitle.textContent = 'Box score';
    const names = meta || {};
    const teams = [
      ['away', data.game.away_id, names.away_name || data.game.away_name],
      ['home', data.game.home_id, names.home_name || data.game.home_name],
    ];
    let html = '<div class="box-teams">';
    for (const [side, tid, tname] of teams) {
      const rows = data.box.filter(b => b.team_id == tid).sort((a, b) => b.pts - a.pts);
      html += boxTeam(side, tname, rows);
    }
    boxContent.innerHTML = html + '</div>';
  }

  startBtn.addEventListener('click', run);
})();
