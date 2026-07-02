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
  if (!startBtn) return;

  const meta = window.GAME_META;
  let running = false;

  function quarterLabel(q) {
    if (q.startsWith('Q')) return q.replace('Q', 'º Quarto ').replace('1º', '1º').trim() || q;
    if (q.startsWith('PR')) return 'Prorrogação ' + (q.slice(2) || '');
    return q;
  }
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

  function teamTag(ev) {
    const isHome = ev.team === window.HOME_ID;
    return isHome ? meta.home_abbr : meta.away_abbr;
  }

  function addItem(ev, abbr) {
    const div = document.createElement('div');
    div.className = classFor(ev);
    div.innerHTML = '<span class="pbp-meta">' + ev.q.replace('Q', 'Q') + ' ' + ev.clock +
      ' · ' + abbr + '</span>' + escapeHtml(ev.text);
    feed.appendChild(div);
    feed.scrollTop = feed.scrollHeight;
  }

  function quarterSep(q) {
    const div = document.createElement('div');
    div.className = 'pbp-item quarter-sep';
    div.textContent = '— ' + qHuman(q) + ' —';
    feed.appendChild(div);
  }

  function escapeHtml(s) {
    return s.replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
  }

  function bump(el) {
    el.classList.add('bump');
    setTimeout(() => el.classList.remove('bump'), 150);
  }

  function delay(ms) { return new Promise(r => setTimeout(r, ms)); }

  async function run() {
    if (running) return;
    running = true;
    startBtn.disabled = true;
    startBtn.textContent = 'Carregando...';
    if (empty) empty.remove();
    feed.innerHTML = '';
    sbHome.textContent = '0';
    sbAway.textContent = '0';

    let data;
    try {
      const res = await fetch(window.GAME_API);
      data = await res.json();
    } catch (e) {
      feed.innerHTML = '<p class="muted">Erro ao carregar o jogo.</p>';
      running = false; startBtn.disabled = false; return;
    }

    window.HOME_ID = data.game.home_id;
    window.AWAY_ID = data.game.away_id;
    startBtn.textContent = '● AO VIVO';

    let lastQ = '';
    let curHome = 0, curAway = 0;
    for (const ev of data.pbp) {
      const speed = parseInt(speedSel.value, 10);
      if (ev.q !== lastQ) { quarterSep(ev.q); sbQuarter.textContent = qHuman(ev.q); lastQ = ev.q; }

      const abbr = ev.team === data.game.home_id ? data.game.home_abbr : data.game.away_abbr;
      addItem(ev, abbr);
      sbClock.textContent = ev.clock;

      if (ev.home_pts !== curHome) { curHome = ev.home_pts; sbHome.textContent = curHome; bump(sbHome); }
      if (ev.away_pts !== curAway) { curAway = ev.away_pts; sbAway.textContent = curAway; bump(sbAway); }

      if (speed > 0) await delay(speed);
    }

    sbClock.textContent = 'FINAL' + (data.game.ot ? '/PR' + (data.game.ot > 1 ? data.game.ot : '') : '');
    sbQuarter.textContent = 'Encerrado';
    sbHome.textContent = data.game.home_pts;
    sbAway.textContent = data.game.away_pts;
    startBtn.textContent = '↻ Reassistir Simcast';
    startBtn.disabled = false;
    running = false;

    renderBox(data);
  }

  function renderBox(data) {
    if (!boxCard) return;
    boxCard.style.display = '';
    const teams = [
      [data.game.away_id, data.game.away_name],
      [data.game.home_id, data.game.home_name],
    ];
    let html = '';
    for (const [tid, tname] of teams) {
      const rows = data.box.filter(b => b.team_id == tid).sort((a, b) => b.pts - a.pts);
      html += '<h3 class="box-team">' + escapeHtml(tname) + '</h3>';
      html += '<table class="box-table"><thead><tr><th>Jogador</th><th>MIN</th><th>PTS</th><th>REB</th><th>AST</th><th>R/T</th><th>TO</th><th>FG</th><th>3P</th><th>LL</th></tr></thead><tbody>';
      for (const b of rows) {
        html += '<tr><td class="bx-name">' + escapeHtml(b.name) + ' <span class="muted">' + b.pos + '</span></td>' +
          '<td class="num">' + Math.round(b.min) + '</td>' +
          '<td class="num"><strong>' + b.pts + '</strong></td>' +
          '<td class="num">' + b.reb + '</td><td class="num">' + b.ast + '</td>' +
          '<td class="num">' + b.stl + '/' + b.blk + '</td><td class="num">' + b.tov + '</td>' +
          '<td class="num">' + b.fgm + '-' + b.fga + '</td>' +
          '<td class="num">' + b.tpm + '-' + b.tpa + '</td>' +
          '<td class="num">' + b.ftm + '-' + b.fta + '</td></tr>';
      }
      html += '</tbody></table>';
    }
    boxContent.innerHTML = html;
  }

  startBtn.addEventListener('click', run);
})();
