// Gerenciamento de Temporadas e Sprints
const seasonsState = {
    currentLeague: null,
    currentSeason: null,
    draftPlayers: []
};

function resolveStartYear(season) {
    if (!season) return null;
    if (season.start_year) return Number(season.start_year);
    if (season.year && season.season_number) {
        return Number(season.year) - Number(season.season_number) + 1;
    }
    return null;
}

function promptStartYear(defaultYear) {
    const fallback = defaultYear ?? new Date().getFullYear();
    const input = prompt('Informe o ano inicial do sprint (ex: 2016):', fallback);
    if (input === null) return null;
    const parsed = parseInt(input, 10);
    if (!parsed || parsed < 1900) {
        alert('Ano inválido. Informe um número como 2016.');
        return null;
    }
    return parsed;
}

// ========== TELA PRINCIPAL DE TEMPORADAS ==========
async function showSeasonsManagement() {
    appState.view = 'seasons';
    updateBreadcrumb();

    const container = document.getElementById('mainContainer');
    container.innerHTML = `<div class="text-center py-5"><div class="spinner-border text-orange"></div></div>`;

    const leagues = [
        { name: 'ELITE',  label: '20 temporadas por sprint' },
        { name: 'NEXT',   label: '15 temporadas por sprint' },
        { name: 'RISE',   label: '10 temporadas por sprint' },
        { name: 'ROOKIE', label: '10 temporadas por sprint' },
    ];

    const results = await Promise.allSettled(
        leagues.map(l => api(`seasons.php?action=current_season&league=${l.name}`))
    );

    const leagueCards = leagues.map((l, i) => {
        const season = results[i].status === 'fulfilled' ? results[i].value?.season : null;
        const hasSprint = !!season;
        const seasonInfo = season
            ? `Sprint ${season.sprint_number || '?'} · T${season.season_number || '?'} · ${season.year || ''}`
            : 'Sem sprint ativo';

        const mainBtn = hasSprint
            ? `<button class="btn btn-sm btn-outline-orange w-100 mb-2" onclick="showAvancarTemporada('${l.name}')">
                   <i class="bi bi-arrow-right-circle me-1"></i>Avançar Temporada
               </button>`
            : `<button class="btn btn-sm btn-orange w-100 mb-2" onclick="showAvancarTemporada('${l.name}')">
                   <i class="bi bi-play-circle me-1"></i>Criar Sprint
               </button>`;

        return `
            <div class="col-md-6 col-lg-3">
                <div class="league-card" style="cursor:default">
                    <h3>${l.name}</h3>
                    <p class="text-light-gray mb-1">${l.label}</p>
                    <p class="mb-3" style="font-size:11px;color:${hasSprint ? '#ff6b00' : '#666'}">${seasonInfo}</p>
                    ${mainBtn}
                    <button class="btn btn-sm btn-outline-secondary w-100" onclick="showPointsManagement('${l.name}')">
                        <i class="bi bi-bar-chart-steps me-1"></i>Histórico Pontuação
                    </button>
                </div>
            </div>`;
    }).join('');

    container.innerHTML = `
        <div class="row g-4 mb-4">
            <div class="col-12">
                <h3 class="text-white mb-3">
                    <i class="bi bi-calendar3 text-orange me-2"></i>
                    Gerenciar Temporadas
                </h3>
            </div>
            ${leagueCards}
        </div>

        <div class="row g-4">
            <div class="col-12">
                <h3 class="text-white mb-3">
                    <i class="bi bi-info-circle text-orange me-2"></i>
                    Informações do Sistema
                </h3>
            </div>
            <div class="col-md-4">
                <div class="card bg-dark-panel border-orange">
                    <div class="card-body">
                        <h5 class="text-orange mb-2"><i class="bi bi-calendar-check"></i> Temporadas</h5>
                        <p class="text-light-gray mb-0">Cada liga possui um número específico de temporadas por sprint (ciclo).</p>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card bg-dark-panel border-orange">
                    <div class="card-body">
                        <h5 class="text-orange mb-2"><i class="bi bi-people"></i> Picks Automáticas</h5>
                        <p class="text-light-gray mb-0">Ao criar uma temporada, são geradas automaticamente 2 picks (1ª e 2ª rodada) para cada time.</p>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card bg-dark-panel border-orange">
                    <div class="card-body">
                        <h5 class="text-orange mb-2"><i class="bi bi-bar-chart"></i> Ranking Acumulativo</h5>
                        <p class="text-light-gray mb-0">Os pontos do ranking são acumulativos e nunca resetam. Use "Rankings" no menu para visualizar.</p>
                    </div>
                </div>
            </div>
        </div>
    `;
}

// ========== BUSCAR TEMPORADA ATUAL ==========
async function loadCurrentSeason(league) {
    try {
        const data = await api(`seasons.php?action=current_season&league=${league}`);
        seasonsState.currentSeason = data.season;
        return data.season;
    } catch (e) {
        console.error('Erro ao carregar temporada:', e);
        return null;
    }
}

// ========== CRIAR NOVA TEMPORADA ==========
async function createNewSeason(league) {
    const currentSeason = await loadCurrentSeason(league);
    const startYear = resolveStartYear(currentSeason) ?? promptStartYear(new Date().getFullYear());
    if (!startYear) return;

    const nextSeasonNumber = Number(currentSeason?.season_number || 0) + 1;
    const seasonYear = startYear + nextSeasonNumber - 1;

    if (!confirm(`Criar temporada ${String(nextSeasonNumber).padStart(2, '0')} para a liga ${league} (ano ${seasonYear})?`)) {
        return;
    }
    
    try {
        const data = await api('seasons.php?action=create_season', {
            method: 'POST',
            body: JSON.stringify({ league, season_year: seasonYear, start_year: startYear })
        });
        
        alert(data.message);
        showSeasonsManagement();
    } catch (e) {
        alert('Erro ao criar temporada: ' + (e.error || 'Desconhecido'));
    }
}

// ========== PLAYOFF BRACKET ==========
let _bracket = null;

function generateBracket(league) {
    try {
        const form = document.getElementById('formAvancarTemporada') || document.getElementById('formRegistroPontuacao');
        if (!form) {
            showAlert('danger', 'Formulário não encontrado. Recarregue a página.');
            return;
        }
        const tById = seasonsState.teamsById || {};
        if (!Object.keys(tById).length) {
            showAlert('warning', 'Carregue os times da liga antes de gerar o chaveamento.');
            return;
        }
        const getSeeds = (conf) => Array.from({length: 8}, (_, i) => {
            const el = form.querySelector(`[name="${conf}_rank_${i + 1}"]`);
            return el?.value ? tById[String(el.value)] : null;
        }).filter(Boolean);
        const leste = getSeeds('leste'), oeste = getSeeds('oeste');
        if (leste.length < 8 || oeste.length < 8) {
            const parts = [];
            if (leste.length < 8) parts.push(`Leste: ${leste.length}/8`);
            if (oeste.length < 8) parts.push(`Oeste: ${oeste.length}/8`);
            showAlert('warning', `Selecione os 8 times de cada conferência. (${parts.join(' · ')})`);
            return;
        }
        // 1v8 e 4v5 → R2 topo; 2v7 e 3v6 → R2 baixo
        const initConf = (s) => ({
            r1: [
                {t1: s[0], t2: s[7], w: null, s1: 1, s2: 8},
                {t1: s[3], t2: s[4], w: null, s1: 4, s2: 5},
                {t1: s[1], t2: s[6], w: null, s1: 2, s2: 7},
                {t1: s[2], t2: s[5], w: null, s1: 3, s2: 6},
            ],
            r2: [null, null], cf: null, winner: null,
        });
        _bracket = {leste: initConf(leste), oeste: initConf(oeste), final: null};
        _renderBracket(league);
        try { _saveBracketCache(league, seasonsState.currentSeasonId); } catch (_) {}
        const bracketEl = document.getElementById('playoffBracketContainer');
        if (bracketEl) bracketEl.scrollIntoView({behavior: 'smooth', block: 'start'});
    } catch (e) {
        console.error('generateBracket error:', e);
        showAlert('danger', 'Erro ao gerar chaveamento: ' + (e.message || 'erro desconhecido'));
    }
}

function _setBracketWinner(conf, round, idx, winId) {
    const b = _bracket;
    if (!b) return;
    winId = String(winId);
    if (round === 'final') {
        if (b.final) b.final.w = (b.final.w === winId) ? null : winId;
    } else {
        const m = round === 'cf' ? b[conf].cf : b[conf][round][idx];
        if (!m) return;
        m.w = (m.w === winId) ? null : winId;
        _rebuildConf(conf);
        _rebuildFinal();
    }
    _renderBracket(seasonsState.currentLeague);
    _saveBracketCache(seasonsState.currentLeague, seasonsState.currentSeasonId);
}

function _rebuildConf(conf) {
    const c = _bracket[conf], t = seasonsState.teamsById;
    const wOf = (arr, i) => { const m = arr[i]; return m?.w ? t[m.w] : null; };
    c.r2[0] = _mkMatchup(c.r2[0], wOf(c.r1, 0), wOf(c.r1, 1));
    c.r2[1] = _mkMatchup(c.r2[1], wOf(c.r1, 2), wOf(c.r1, 3));
    c.cf = _mkMatchup(c.cf, wOf(c.r2, 0), wOf(c.r2, 1));
    c.winner = c.cf?.w ? t[c.cf.w] : null;
}

function _rebuildFinal() {
    const b = _bracket, lw = b.leste.winner, ow = b.oeste.winner;
    b.final = (lw && ow) ? _mkMatchup(b.final, lw, ow) : null;
}

function _mkMatchup(existing, t1, t2) {
    if (!t1 || !t2) return null;
    if (existing?.t1 && existing?.t2) {
        const same = [String(existing.t1.id), String(existing.t2.id)].sort().join();
        const neo  = [String(t1.id), String(t2.id)].sort().join();
        if (same === neo) return existing;
    }
    return {t1, t2, w: null};
}

function _ensureBracketStyles() {
    if (document.getElementById('bk-styles')) return;
    const s = document.createElement('style');
    s.id = 'bk-styles';
    s.textContent = `.bk-wrap{display:flex;align-items:stretch;overflow-x:auto;padding-bottom:4px;gap:4px}.bk-col{display:flex;flex-direction:column;min-width:148px;flex-shrink:0}.bk-col-mid{display:flex;flex-direction:column;justify-content:center;align-items:center;min-width:148px;flex-shrink:0;padding:0 4px}.bk-col-label{font-size:10px;color:#777;text-transform:uppercase;letter-spacing:.06em;text-align:center;padding:0 0 5px}.bk-matchup{border:1px solid #272727;border-radius:8px;overflow:hidden;background:#141414;margin:1px 0}.bk-empty{height:54px;display:flex;align-items:center;justify-content:center;color:#2a2a2a;font-size:18px;margin:1px 0}.bk-team{display:flex;align-items:center;padding:5px 7px;font-size:12px;cursor:pointer;border-bottom:1px solid #1c1c1c;transition:background .1s;user-select:none;line-height:1.2;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.bk-team:last-child{border-bottom:none}.bk-team:hover:not(.bk-loss):not(.bk-tbd){background:rgba(255,107,0,.12)}.bk-win{background:rgba(255,107,0,.2)!important;color:#ff6b00;font-weight:700}.bk-loss{opacity:.28;cursor:default}.bk-tbd{color:#383838;cursor:default;font-style:italic}.bk-seed{display:inline-flex;align-items:center;justify-content:center;width:15px;height:15px;border-radius:3px;background:#202020;color:#777;font-size:9px;font-weight:700;margin-right:5px;flex-shrink:0}.bk-win .bk-seed{background:rgba(255,107,0,.3);color:#ff6b00}.bk-sp{flex:1}.bk-champ{margin-top:8px;padding:7px 10px;background:rgba(255,107,0,.12);border:1px solid rgba(255,107,0,.5);border-radius:9px;text-align:center}`;
    document.head.appendChild(s);
}

function _renderBracket(league) {
    _ensureBracketStyles();
    const container = document.getElementById('playoffBracketContainer');
    if (!container || !_bracket) return;
    const b = _bracket, t = seasonsState.teamsById;
    const tn = (team) => team ? `${team.city} ${team.name}` : 'Aguardando...';
    const champion = b.final?.w ? t[b.final.w] : null;

    const mkCard = (conf, round, idx) => {
        const m = round === 'cf' ? b[conf]?.cf : (round === 'final' ? b.final : b[conf]?.[round]?.[idx]);
        if (!m) return `<div class="bk-empty"><i class="bi bi-three-dots text-muted"></i></div>`;
        const {t1, t2, w, s1, s2} = m;
        const btn = (team, seed) => {
            if (!team) return `<div class="bk-team bk-tbd"><span class="bk-seed">?</span>Aguardando...</div>`;
            const tid = String(team.id);
            const cls = w === tid ? 'bk-team bk-win' : (w ? 'bk-team bk-loss' : 'bk-team');
            const clickArg = round === 'final'
                ? `null,'final',0,${team.id}`
                : round === 'cf' ? `'${conf}','cf',0,${team.id}`
                : `'${conf}','${round}',${idx},${team.id}`;
            const sdg = seed ? `<span class="bk-seed">${seed}</span>` : `<span class="bk-seed" style="visibility:hidden">0</span>`;
            return `<div class="${cls}" onclick="_setBracketWinner(${clickArg})">${sdg}${tn(team)}</div>`;
        };
        return `<div class="bk-matchup">${btn(t1, s1)}${btn(t2, s2)}</div>`;
    };

    const lc = (conf) => ({
        r1a: mkCard(conf,'r1',0), r1b: mkCard(conf,'r1',1),
        r1c: mkCard(conf,'r1',2), r1d: mkCard(conf,'r1',3),
        r2a: mkCard(conf,'r2',0), r2b: mkCard(conf,'r2',1),
        cf:  mkCard(conf,'cf',0),
    });
    const le = lc('leste'), oe = lc('oeste');
    const fn = mkCard(null,'final',0);

    container.innerHTML = `
<div class="d-flex justify-content-between align-items-center mb-2" style="font-size:12px">
    <span class="text-orange fw-bold"><i class="bi bi-geo-alt me-1"></i>Conferência Leste</span>
    <span class="text-orange fw-bold"><i class="bi bi-trophy me-1"></i>Grande Final</span>
    <span class="text-orange fw-bold">Conferência Oeste<i class="bi bi-geo-alt ms-1"></i></span>
</div>
<div class="bk-wrap">
    <div class="bk-col">
        <div class="bk-col-label">1ª Rodada</div>
        ${le.r1a}<div class="bk-sp" style="max-height:6px"></div>
        ${le.r1b}<div class="bk-sp"></div>
        ${le.r1c}<div class="bk-sp" style="max-height:6px"></div>
        ${le.r1d}
    </div>
    <div class="bk-col">
        <div class="bk-col-label">2ª Rodada</div>
        <div class="bk-sp" style="max-height:30px"></div>
        ${le.r2a}
        <div class="bk-sp"></div>
        ${le.r2b}
        <div class="bk-sp" style="max-height:30px"></div>
    </div>
    <div class="bk-col">
        <div class="bk-col-label">Final de Conf.</div>
        <div class="bk-sp"></div>${le.cf}<div class="bk-sp"></div>
    </div>
    <div class="bk-col-mid">
        <div class="bk-col-label">Final</div>
        ${fn}
        ${champion ? `<div class="bk-champ"><div style="font-size:9px;color:#888;margin-bottom:2px">CAMPEÃO</div><div style="color:#ff6b00;font-weight:700;font-size:12px">${tn(champion)}</div></div>` : ''}
    </div>
    <div class="bk-col">
        <div class="bk-col-label">Final de Conf.</div>
        <div class="bk-sp"></div>${oe.cf}<div class="bk-sp"></div>
    </div>
    <div class="bk-col">
        <div class="bk-col-label">2ª Rodada</div>
        <div class="bk-sp" style="max-height:30px"></div>
        ${oe.r2a}
        <div class="bk-sp"></div>
        ${oe.r2b}
        <div class="bk-sp" style="max-height:30px"></div>
    </div>
    <div class="bk-col">
        <div class="bk-col-label">1ª Rodada</div>
        ${oe.r1a}<div class="bk-sp" style="max-height:6px"></div>
        ${oe.r1b}<div class="bk-sp"></div>
        ${oe.r1c}<div class="bk-sp" style="max-height:6px"></div>
        ${oe.r1d}
    </div>
</div>
<div class="mt-2">
    <button type="button" class="btn btn-sm btn-outline-secondary" style="font-size:11px" onclick="generateBracket('${league}')">
        <i class="bi bi-arrow-clockwise me-1"></i>Regerar chaveamento
    </button>
</div>`;
}

function _collectBracketPayload() {
    const b = _bracket;
    if (!b?.final?.w) return null;
    const loser = (m) => {
        if (!m?.w || !m.t1 || !m.t2) return null;
        return String(m.t1.id) === m.w ? String(m.t2.id) : String(m.t1.id);
    };
    return {
        champion: b.final.w,
        runner_up: loser(b.final),
        first_round_losses: [...b.leste.r1, ...b.oeste.r1].map(loser).filter(Boolean),
        second_round_losses: [...(b.leste.r2||[]), ...(b.oeste.r2||[])].map(loser).filter(Boolean),
        conference_final_losses: [loser(b.leste.cf), loser(b.oeste.cf)].filter(Boolean),
    };
}

function _saveBracketCache(league, seasonId) {
    if (league && seasonId) localStorage.setItem(`bk_${league}_${seasonId}`, JSON.stringify(_bracket));
}
function _restoreBracketCache(league, seasonId) {
    if (!league || !seasonId) return false;
    const raw = localStorage.getItem(`bk_${league}_${seasonId}`);
    try { _bracket = JSON.parse(raw); return !!_bracket; } catch (_) { return false; }
}
function _clearBracketCache(league, seasonId) {
    if (league && seasonId) localStorage.removeItem(`bk_${league}_${seasonId}`);
}

// ========== REGISTRO DE PONTUAÇÃO — estado ==========
let _regPtsAllTeams = [];
let _regPtsCacheKey = '';
let _regPtsLeague = '';
let _regPtsSeasonId = null;

function _regPtsSaveCache() {
    if (!_regPtsCacheKey) return;
    const form = document.getElementById('formRegistroPontuacao');
    const formState = {};
    if (form) {
        form.querySelectorAll('[name]').forEach(el => { formState[el.name] = el.value; });
    }
    localStorage.setItem(_regPtsCacheKey, JSON.stringify({ form: formState }));
}

function _regPtsLoadCache() {
    if (!_regPtsCacheKey) return null;
    const raw = localStorage.getItem(_regPtsCacheKey);
    try { return raw ? JSON.parse(raw) : null; } catch (_) { return null; }
}

// ─── Auto-cálculo de pontos ───────────────────────────────────────────────────
function _calcAutoPoints(payload, allTeams, league) {
    const pts = {};
    allTeams.forEach(t => { pts[String(t.id)] = { seed: 0, playoff: 0, awards: 0, cup: 0 }; });

    const seedPts = (rank) => rank === 1 ? 4 : rank <= 4 ? 3 : rank <= 8 ? 2 : 0;
    (payload.standings_leste || []).forEach((id, i) => { const k = String(id); if (pts[k] !== undefined) pts[k].seed = seedPts(i + 1); });
    (payload.standings_oeste || []).forEach((id, i) => { const k = String(id); if (pts[k] !== undefined) pts[k].seed = seedPts(i + 1); });

    if (payload.champion)  { const k = String(payload.champion);  if (pts[k] !== undefined) pts[k].playoff = 11; }
    if (payload.runner_up) { const k = String(payload.runner_up); if (pts[k] !== undefined) pts[k].playoff = 8;  }
    (payload.conference_final_losses || []).forEach(id => { const k = String(id); if (pts[k] !== undefined) pts[k].playoff = 6; });
    (payload.second_round_losses     || []).forEach(id => { const k = String(id); if (pts[k] !== undefined) pts[k].playoff = 3; });
    (payload.first_round_losses      || []).forEach(id => { const k = String(id); if (pts[k] !== undefined) pts[k].playoff = 1; });

    ['mvp_team_id','dpoy_team_id','mip_team_id','sixth_man_team_id','roy_team_id'].forEach(key => {
        const k = String(payload[key] || '');
        if (k && pts[k] !== undefined) pts[k].awards += 1;
    });
    if (league === 'ELITE' && payload.nba_cup_team_id) {
        const k = String(payload.nba_cup_team_id);
        if (pts[k] !== undefined) pts[k].cup = 2;
    }

    Object.values(pts).forEach(p => { p.total = p.seed + p.playoff + p.awards + p.cup; });
    return pts;
}

function _showReviewPanel(seasonId, league, payload) {
    const container = document.getElementById('mainContainer');
    const allTeams = _regPtsAllTeams;
    const calcPts = _calcAutoPoints(payload, allTeams, league);

    const teamLabel = (id) => {
        const t = allTeams.find(t => String(t.id) === String(id));
        return t ? escapeHtml(t.city + ' ' + t.name) : `Time #${id}`;
    };
    const inpStyle = 'width:70px;background:var(--panel-3);border:1px solid var(--border);border-radius:8px;padding:6px 8px;color:var(--text);font-size:14px;font-weight:700;text-align:center';

    const rows = allTeams
        .filter(t => (calcPts[String(t.id)]?.total || 0) > 0)
        .sort((a, b) => (calcPts[String(b.id)]?.total || 0) - (calcPts[String(a.id)]?.total || 0))
        .map(t => {
            const id = String(t.id);
            const p = calcPts[id];
            const premLabel = p.awards + (p.cup ? `+${p.cup}` : '');
            return `<tr style="border-bottom:1px solid var(--border)">
                <td style="padding:10px 12px;font-weight:600">${teamLabel(id)}</td>
                <td style="padding:10px 12px;text-align:center;color:var(--text-2)">${p.seed}</td>
                <td style="padding:10px 12px;text-align:center;color:var(--text-2)">${p.playoff}</td>
                <td style="padding:10px 12px;text-align:center;color:var(--text-2)">${premLabel || '0'}</td>
                <td style="padding:10px 12px;text-align:center">
                    <input type="number" class="review-pts-input" data-team-id="${id}"
                           value="${p.total}" min="0" style="${inpStyle}">
                </td>
            </tr>`;
        }).join('');

    container.innerHTML = `
        <div class="mb-3">
            <button class="btn-ghost" onclick="showHome()"><i class="bi bi-arrow-left me-1"></i> Voltar</button>
        </div>
        <div class="panel mb-3">
            <div class="panel-header">
                <div>
                    <div class="panel-title"><i class="bi bi-check-circle-fill" style="color:#22c55e"></i> Pontuação Registrada — Revisar</div>
                    <div class="panel-sub">Pontos calculados automaticamente a partir das seções preenchidas. Ajuste se necessário.</div>
                </div>
                <span style="background:rgba(34,197,94,.12);color:#22c55e;border:1px solid rgba(34,197,94,.3);border-radius:999px;font-size:11px;font-weight:700;padding:4px 12px">
                    <i class="bi bi-check-lg me-1"></i>Salvo
                </span>
            </div>
        </div>
        <div class="panel mb-4">
            <div class="panel-title mb-3"><i class="bi bi-table"></i> Pontos por Time</div>
            <div style="overflow-x:auto">
                <table style="width:100%;border-collapse:collapse;font-size:13px">
                    <thead>
                        <tr style="border-bottom:2px solid var(--border)">
                            <th style="padding:8px 12px;text-align:left;color:var(--text-2);font-weight:600">Time</th>
                            <th style="padding:8px 12px;text-align:center;color:var(--text-2);font-weight:600" title="1°=4pts, 2°-4°=3pts, 5°-8°=2pts">Classificação</th>
                            <th style="padding:8px 12px;text-align:center;color:var(--text-2);font-weight:600" title="Campeão=11, Vice=8, Conf.Final=6, Semis=3, 1ªFase=1">Playoffs</th>
                            <th style="padding:8px 12px;text-align:center;color:var(--text-2);font-weight:600" title="MVP/DPOY/MIP/6°Homem/ROY=1pt${league === 'ELITE' ? ', NBA Cup=2pts' : ''}">Prêmios</th>
                            <th style="padding:8px 12px;text-align:center;color:var(--text-2);font-weight:600">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${rows || '<tr><td colspan="5" style="padding:16px;text-align:center;color:var(--text-3)">Nenhum time com pontuação calculada.</td></tr>'}
                    </tbody>
                </table>
            </div>
            <div style="font-size:11px;color:var(--text-3);margin-top:10px">
                Classificação: 1°=4pts, 2°–4°=3pts, 5°–8°=2pts &nbsp;|&nbsp; Playoffs: Campeão=11, Vice=8, Final de Conf.=6, Semis=3, 1ª Fase=1 &nbsp;|&nbsp; Prêmios: 1pt cada${league === 'ELITE' ? ' &nbsp;|&nbsp; NBA Cup: 2pts' : ''}
            </div>
        </div>
        <div style="display:flex;gap:10px;flex-wrap:wrap">
            <button id="btnSaveReview" class="btn btn-orange" onclick="_saveReviewedPoints(${seasonId}, '${league}')" style="border-radius:15px">
                <i class="bi bi-save me-1"></i> Salvar Pontuação
            </button>
            <button class="btn-ghost" onclick="showHome()">Fechar sem salvar</button>
        </div>`;
}

async function _saveReviewedPoints(seasonId, league) {
    const inputs = document.querySelectorAll('.review-pts-input');
    if (!inputs.length) return;

    const btn = document.getElementById('btnSaveReview');
    const originalHtml = btn ? btn.innerHTML : '';
    if (btn) { btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Salvando...'; }

    try {
        let currentPointsByTeam = {};
        try {
            const current = await api(`history-points.php?action=get_teams_for_points&season_id=${seasonId}&league=${encodeURIComponent(league)}`);
            (current.teams || []).forEach(t => {
                currentPointsByTeam[String(t.id)] = parseInt(t.current_points || '0', 10) || 0;
            });
        } catch (_) {}

        const team_points = Array.from(inputs)
            .map(inp => ({
                team_id: parseInt(inp.dataset.teamId, 10),
                points: (currentPointsByTeam[inp.dataset.teamId] || 0) + (parseInt(inp.value, 10) || 0)
            }))
            .filter(r => r.team_id);

        await api('history-points.php', {
            method: 'POST',
            body: JSON.stringify({ action: 'save_season_points', season_id: seasonId, league, team_points })
        });

        showAlert('success', 'Pontuação salva com sucesso!');
        setTimeout(() => showHome(), 1200);
    } catch (e) {
        if (btn) { btn.disabled = false; btn.innerHTML = originalHtml; }
        alert('Erro ao salvar pontuação: ' + (e?.error || 'Desconhecido'));
    }
}

// ========== AVANÇAR TEMPORADA ==========
function _formCacheKey(league, seasonId) {
    return `avancar_${league}_${seasonId}`;
}

function _saveFormCache(league, seasonId) {
    const form = document.getElementById('formAvancarTemporada') || document.getElementById('formRegistroPontuacao');
    if (!form) return;
    const state = {};
    form.querySelectorAll('[name]').forEach(el => {
        if (el.tagName === 'SELECT' && el.multiple) {
            state[el.name] = Array.from(el.selectedOptions).map(o => o.value);
        } else {
            state[el.name] = el.value;
        }
    });
    localStorage.setItem(_formCacheKey(league, seasonId), JSON.stringify(state));
}

function _restoreFormCache(league, seasonId) {
    const form = document.getElementById('formAvancarTemporada') || document.getElementById('formRegistroPontuacao');
    if (!form) return;
    const raw = localStorage.getItem(_formCacheKey(league, seasonId));
    if (!raw) return;
    try {
        const state = JSON.parse(raw);
        Object.entries(state).forEach(([name, value]) => {
            const el = form.querySelector(`[name="${name}"]`);
            if (!el) return;
            if (el.tagName === 'SELECT' && el.multiple) {
                Array.from(el.options).forEach(opt => { opt.selected = value.includes(opt.value); });
            } else {
                el.value = value;
            }
        });
    } catch (_) {}
}

function _clearFormCache(league, seasonId) {
    localStorage.removeItem(_formCacheKey(league, seasonId));
}

async function showAvancarTemporada(league) {
    seasonsState.currentLeague = league;
    const container = document.getElementById('mainContainer');
    container.innerHTML = '<div class="text-center py-5"><div class="spinner-border text-orange"></div></div>';

    const season = await loadCurrentSeason(league);

    if (!season) {
        // Nenhum sprint ativo — mostrar formulário de criação
        container.innerHTML = `
            <div class="mb-3">
                <button class="btn-ghost" onclick="showLeague('${league}')"><i class="bi bi-arrow-left me-1"></i> Voltar</button>
            </div>
            <div class="panel">
                <div class="panel-title"><i class="bi bi-play-circle-fill" style="color:#f97316"></i> Criar Sprint — ${league}</div>
                <p style="color:var(--text-2);font-size:13px;margin-bottom:20px">
                    Defina o ano inicial do sprint. As picks serão configuradas automaticamente para todos os times.
                    Após criar, você será direcionado para configurar o Draft Inicial.
                </p>
                <form id="formCriarSprint" onsubmit="_submitCriarSprint(event, '${league}')">
                    <div style="margin-bottom:16px">
                        <label style="font-size:12px;color:var(--text-2);display:block;margin-bottom:6px">Ano inicial do sprint</label>
                        <input type="number" name="start_year" value="${new Date().getFullYear()}" min="1900" max="2100"
                               style="background:var(--panel-3);border:1px solid var(--border);border-radius:8px;padding:9px 14px;color:var(--text);font-size:15px;width:160px">
                    </div>
                    <div style="display:flex;gap:10px;flex-wrap:wrap">
                        <button type="submit" class="btn-orange">
                            <i class="bi bi-plus-circle me-1"></i> Criar Sprint
                        </button>
                        <button type="button" class="btn-ghost" onclick="showLeague('${league}')">Cancelar</button>
                    </div>
                </form>
            </div>`;
        return;
    }

    seasonsState.currentSeasonId = season.id;
    seasonsState._advancingSeason = season;

    // Verificar se o histórico de pontuação já foi registrado
    let histRegistered = false;
    try {
        const hist = await api(`seasons.php?action=check_season_history&season_id=${season.id}`);
        histRegistered = !!hist.registered;
    } catch (_) {}

    const seasonLabel = `T${season.season_number} · Sprint ${season.sprint_number || '?'} · ${season.year || ''}`;

    if (!histRegistered) {
        container.innerHTML = `
            <div class="mb-3">
                <button class="btn-ghost" onclick="showLeague('${league}')"><i class="bi bi-arrow-left me-1"></i> Voltar</button>
            </div>
            <div class="panel">
                <div class="panel-title"><i class="bi bi-arrow-right-circle" style="color:#f97316"></i> Avançar Temporada — ${league}</div>
                <p style="color:var(--text-2);font-size:13px;margin-bottom:12px">Temporada atual: <strong style="color:var(--red)">${seasonLabel}</strong></p>
                <div style="background:rgba(245,158,11,.1);border:1px solid rgba(245,158,11,.3);border-radius:10px;padding:12px 16px;margin-bottom:16px;font-size:13px;color:#f59e0b">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    A pontuação desta temporada ainda não foi registrada. Registre os resultados antes de avançar.
                </div>
                <div style="display:flex;gap:10px">
                    <button class="btn-orange" onclick="showRegistroPontuacao('${league}')">
                        <i class="bi bi-clipboard-data-fill me-1"></i> Ir para Registro de Pontuação
                    </button>
                    <button class="btn-ghost" onclick="showLeague('${league}')">Cancelar</button>
                </div>
            </div>`;
        return;
    }

    // Histórico registrado — mostrar confirmação de avanço
    container.innerHTML = `
        <div class="mb-3">
            <button class="btn-ghost" onclick="showLeague('${league}')"><i class="bi bi-arrow-left me-1"></i> Voltar</button>
        </div>
        <div class="panel">
            <div class="panel-title"><i class="bi bi-arrow-right-circle" style="color:#10b981"></i> Avançar Temporada — ${league}</div>
            <p style="color:var(--text-2);font-size:13px;margin-bottom:4px">Temporada atual: <strong style="color:var(--red)">${seasonLabel}</strong></p>
            <p style="color:#22c55e;font-size:13px;margin-bottom:16px">
                <i class="bi bi-check-circle-fill me-1"></i>Pontuação registrada. Avançar criará a próxima temporada do sprint.
            </p>
            <div style="display:flex;gap:10px;flex-wrap:wrap">
                <button class="btn-orange" onclick="_confirmAdvanceSeason(${season.id}, '${league}')">
                    <i class="bi bi-arrow-right-circle me-1"></i> Confirmar e Avançar
                </button>
                <button class="btn-ghost" onclick="showLeague('${league}')">Cancelar</button>
            </div>
        </div>`;
}

async function _confirmAdvanceSeason(seasonId, league) {
    const season = seasonsState._advancingSeason;
    if (!season) { showAlert('danger', 'Dados da temporada não encontrados. Recarregue a página.'); return; }

    const startYear = resolveStartYear(season) ?? promptStartYear(new Date().getFullYear());
    if (!startYear) return;

    const nextNum = Number(season.season_number) + 1;
    const nextYear = startYear + nextNum - 1;

    if (!confirm(`Criar Temporada ${String(nextNum).padStart(2,'0')} para a liga ${league} (ano ${nextYear})?`)) return;

    try {
        await api('seasons.php?action=advance_season', {
            method: 'POST',
            body: JSON.stringify({ season_id: seasonId })
        });
        const data = await api('seasons.php?action=create_season', {
            method: 'POST',
            body: JSON.stringify({ league, season_year: nextYear, start_year: startYear })
        });
        _clearBracketCache(league, seasonId);
        seasonsState._advancingSeason = null;
        showAlert('success', data.message || 'Temporada avançada com sucesso!');
        showLeague(league);
    } catch (e) {
        showAlert('danger', 'Erro: ' + (e.error || 'Desconhecido'));
    }
}

async function showRegistroPontuacao(league) {
    league = league || (window.appState?.currentLeague) || seasonsState.currentLeague || 'ELITE';
    seasonsState.currentLeague = league;
    _regPtsLeague = league;

    const container = document.getElementById('mainContainer');
    container.innerHTML = '<div class="text-center py-5"><div class="spinner-border text-orange"></div></div>';

    const season = await loadCurrentSeason(league);

    if (!season) {
        container.innerHTML = `
            <div class="mb-3"><button class="btn-ghost" onclick="showSeasonsManagement()"><i class="bi bi-arrow-left me-1"></i> Voltar</button></div>
            <div class="panel"><p style="color:var(--text-2);margin:0"><i class="bi bi-info-circle me-2"></i>Nenhuma temporada ativa para ${league}.</p></div>`;
        return;
    }

    seasonsState.currentSeasonId = season.id;
    _regPtsSeasonId = season.id;
    _regPtsCacheKey = `reg_pts_v2_${league}_${season.id}`;

    let histRegistered = false;
    try {
        const hist = await api(`seasons.php?action=check_season_history&season_id=${season.id}`);
        histRegistered = !!hist.registered;
    } catch (_) {}

    let allTeams = [];
    try {
        const teamsData = await api(`admin.php?action=teams&league=${league}`);
        allTeams = teamsData.teams || [];
        seasonsState.teamsById = Object.fromEntries(allTeams.map(t => [String(t.id), t]));
    } catch (_) {}
    _regPtsAllTeams = allTeams;

    const seasonLabel = `T${season.season_number} · Sprint ${season.sprint_number || '?'} · ${season.year || ''}`;
    const backFn = 'showHome()';

    const cached = _regPtsLoadCache();

    const lockedBadge = histRegistered
        ? `<span style="background:rgba(239,68,68,.12);color:#ef4444;border:1px solid rgba(239,68,68,.3);border-radius:999px;font-size:11px;font-weight:700;padding:4px 12px">
               <i class="bi bi-lock-fill me-1"></i>Já registrado
           </span>`
        : '';

    const selStyle = 'width:100%;background:var(--panel-3);border:1px solid var(--border);border-radius:8px;padding:8px 10px;color:var(--text);font-size:13px';
    const inpStyle = 'width:100%;background:var(--panel-3);border:1px solid var(--border);border-radius:8px;padding:8px 10px;color:var(--text);font-size:13px';
    const lblStyle = 'font-size:12px;color:var(--text-2);margin-bottom:6px;display:block';
    const awardTeamOpts = '<option value="">Selecione...</option>' +
        allTeams.map(t => `<option value="${t.id}">${escapeHtml(t.city + ' ' + t.name)}</option>`).join('');

    container.innerHTML = `
        <div class="mb-3">
            <button class="btn-ghost" onclick="${backFn}"><i class="bi bi-arrow-left me-1"></i> Voltar</button>
        </div>

        <div class="panel mb-3">
            <div class="panel-header">
                <div>
                    <div class="panel-title"><i class="bi bi-clipboard-data-fill"></i> Registro de Pontuação — ${league}</div>
                    <div class="panel-sub">Temporada: ${seasonLabel}</div>
                </div>
                ${lockedBadge}
            </div>
        </div>

        ${histRegistered ? `
        <div class="panel" style="border-color:rgba(239,68,68,.3)">
            <p style="color:#ef4444;margin:0"><i class="bi bi-lock-fill me-2"></i>
                A pontuação desta temporada já foi registrada. Não é possível registrar novamente.
            </p>
        </div>` : `
        <form id="formRegistroPontuacao" onsubmit="saveRegistroPontuacao(event, ${season.id}, '${league}')">

            <!-- 1. Premiações -->
            <div class="panel mb-3">
                <div class="panel-title"><i class="bi bi-trophy-fill" style="color:#f59e0b"></i> 1. Premiações</div>
                <div style="font-size:12px;color:var(--text-3);margin-top:4px">MVP, DPOY, MIP, 6º Homem e ROY valem 1 ponto cada.${league === 'ELITE' ? ' NBA Cup vale 2 pontos.' : ''}</div>
                <div class="row g-3" style="margin-top:8px">
                    <div class="col-md-6"><label style="${lblStyle}">MVP (Time)</label><select name="mvp_team_id" style="${selStyle}">${awardTeamOpts}</select></div>
                    <div class="col-md-6"><label style="${lblStyle}">MVP (Jogador)</label><input type="text" name="mvp_player_name" placeholder="Nome do jogador" style="${inpStyle}"></div>
                    <div class="col-md-6"><label style="${lblStyle}">DPOY (Time)</label><select name="dpoy_team_id" style="${selStyle}">${awardTeamOpts}</select></div>
                    <div class="col-md-6"><label style="${lblStyle}">DPOY (Jogador)</label><input type="text" name="dpoy_player_name" placeholder="Nome do jogador" style="${inpStyle}"></div>
                    <div class="col-md-6"><label style="${lblStyle}">MIP (Time)</label><select name="mip_team_id" style="${selStyle}">${awardTeamOpts}</select></div>
                    <div class="col-md-6"><label style="${lblStyle}">MIP (Jogador)</label><input type="text" name="mip_player_name" placeholder="Nome do jogador" style="${inpStyle}"></div>
                    <div class="col-md-6"><label style="${lblStyle}">6º Homem (Time)</label><select name="sixth_man_team_id" style="${selStyle}">${awardTeamOpts}</select></div>
                    <div class="col-md-6"><label style="${lblStyle}">6º Homem (Jogador)</label><input type="text" name="sixth_man_player_name" placeholder="Nome do jogador" style="${inpStyle}"></div>
                    <div class="col-md-6"><label style="${lblStyle}">ROY (Time)</label><select name="roy_team_id" style="${selStyle}">${awardTeamOpts}</select></div>
                    <div class="col-md-6"><label style="${lblStyle}">ROY (Jogador)</label><input type="text" name="roy_player_name" placeholder="Nome do jogador" style="${inpStyle}"></div>
                    ${league === 'ELITE' ? `
                    <div class="col-md-6"><label style="${lblStyle}">NBA Cup (Campeão)</label><select name="nba_cup_team_id" style="${selStyle}">${awardTeamOpts}</select></div>
                    <div class="col-md-6"><label style="${lblStyle}">NBA Cup (Pontos)</label><input type="text" value="2" readonly style="${inpStyle}"></div>
                    ` : ''}
                </div>
            </div>

            <!-- 2. Classificação -->
            <div class="panel mb-3">
                <div class="panel-title"><i class="bi bi-list-ol"></i> 2. Classificação da Temporada Regular</div>
                <div id="standingsContainer" style="margin-top:12px">
                    <button type="button" class="btn-ghost" onclick="loadTeamsForStandings('${league}')">
                        <i class="bi bi-download me-1"></i> Carregar Times
                    </button>
                </div>
            </div>

            <!-- 3. Playoffs -->
            <div class="panel mb-3">
                <div class="panel-title"><i class="bi bi-diagram-3"></i> 3. Playoffs</div>
                <div id="playoffBracketContainer" style="margin-top:12px">
                    <p style="font-size:13px;color:var(--text-3);margin-bottom:10px">Carregue os times e preencha a classificação primeiro, depois gere o chaveamento.</p>
                    <button type="button" class="btn-ghost" onclick="generateBracket('${league}')">
                        <i class="bi bi-diagram-3 me-1"></i> Gerar Chaveamento
                    </button>
                </div>
            </div>

            <!-- Submit -->
            <div style="display:flex;gap:10px;flex-wrap:wrap">
                <button type="submit" class="btn btn-orange" style="border-radius:15px">
                    <i class="bi bi-save me-1"></i> Registrar Pontuação
                </button>
                <button type="button" class="btn-ghost" onclick="${backFn}">Cancelar</button>
            </div>
        </form>`}
    `;

    // Restore award/player-name fields from form cache
    if (cached?.form) {
        const form = document.getElementById('formRegistroPontuacao');
        if (form) {
            Object.entries(cached.form).forEach(([name, value]) => {
                const el = form.querySelector(`[name="${name}"]`);
                if (el) el.value = value;
            });
        }
    }

    // Attach save-on-change for awards inputs
    document.getElementById('formRegistroPontuacao')
        ?.addEventListener('change', _regPtsSaveCache);

    // Restore bracket cache if available
    if (_restoreBracketCache(league, season.id)) {
        _renderBracket(league);
    }
}

async function saveRegistroPontuacao(event, seasonId, league) {
    event.preventDefault();
    const form = event.target;

    const playoff = _collectBracketPayload();
    if (!playoff) {
        alert('Complete o chaveamento dos playoffs: selecione o campeão da Grande Final antes de salvar.');
        return;
    }

    const getRankList = (conf) => Array.from({length: 8}, (_, i) => {
        const s = form.querySelector(`[name="${conf}_rank_${i + 1}"]`);
        return s ? (s.value || null) : null;
    }).filter(Boolean);

    const payload = {
        season_id: seasonId,
        champion: playoff.champion,
        runner_up: playoff.runner_up,
        first_round_losses: playoff.first_round_losses,
        second_round_losses: playoff.second_round_losses,
        conference_final_losses: playoff.conference_final_losses,
        standings_leste: getRankList('leste'),
        standings_oeste: getRankList('oeste'),
        mvp: form.mvp_player_name?.value || null,
        mvp_team_id: form.mvp_team_id?.value || null,
        dpoy: form.dpoy_player_name?.value || null,
        dpoy_team_id: form.dpoy_team_id?.value || null,
        mip: form.mip_player_name?.value || null,
        mip_team_id: form.mip_team_id?.value || null,
        sixth_man: form.sixth_man_player_name?.value || null,
        sixth_man_team_id: form.sixth_man_team_id?.value || null,
        roy: form.roy_player_name?.value || null,
        roy_team_id: form.roy_team_id?.value || null,
        nba_cup_team_id: form.nba_cup_team_id?.value || null
    };

    const btn = form.querySelector('button[type="submit"]');
    const originalText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Salvando...';

    try {
        await api('seasons.php?action=register_pontuacao', {
            method: 'POST',
            body: JSON.stringify(payload)
        });
        // Clear caches
        if (_regPtsCacheKey) localStorage.removeItem(_regPtsCacheKey);
        _clearFormCache(league, seasonId);
        _clearBracketCache(league, seasonId);
        // Mostrar painel de revisão com pontos auto-calculados
        _showReviewPanel(seasonId, league, payload);
    } catch (e) {
        btn.disabled = false;
        btn.innerHTML = originalText;
        if (e?.already_locked) {
            alert('Esta temporada já teve a pontuação registrada. Não é permitido registrar novamente.');
        } else {
            alert('Erro ao salvar: ' + (e?.error || 'Desconhecido'));
        }
    }
}

async function saveAndAdvanceSeason(event, seasonId, league) {
    event.preventDefault();
    const form = event.target;

    const playoff = _collectBracketPayload();
    if (!playoff) {
        alert('Complete o chaveamento dos playoffs: selecione o campeão da Grande Final antes de salvar.');
        return;
    }

    const getRankList = (conf) => Array.from({length: 8}, (_, i) => {
        const s = form.querySelector(`[name="${conf}_rank_${i + 1}"]`);
        return s ? (s.value || null) : null;
    }).filter(Boolean);

    const payload = {
        season_id: seasonId,
        champion: playoff.champion,
        runner_up: playoff.runner_up,
        first_round_losses: playoff.first_round_losses,
        second_round_losses: playoff.second_round_losses,
        conference_final_losses: playoff.conference_final_losses,
        standings_leste: getRankList('leste'),
        standings_oeste: getRankList('oeste'),
        mvp: form.mvp_player_name.value || null,
        mvp_team_id: form.mvp_team_id.value || null,
        dpoy: form.dpoy_player_name.value || null,
        dpoy_team_id: form.dpoy_team_id.value || null,
        mip: form.mip_player_name.value || null,
        mip_team_id: form.mip_team_id.value || null,
        sixth_man: form.sixth_man_player_name.value || null,
        sixth_man_team_id: form.sixth_man_team_id.value || null,
        roy: form.roy_player_name?.value || null,
        roy_team_id: form.roy_team_id?.value || null
    };

    const btn = form.querySelector('button[type="submit"]');
    const originalText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Salvando...';

    try {
        await api('seasons.php?action=save_history', {
            method: 'POST',
            body: JSON.stringify(payload)
        });
        _clearFormCache(league, seasonId);
        _clearBracketCache(league, seasonId);
        btn.innerHTML = originalText;
        btn.disabled = false;
        await _doCreateNewSeason(league);
    } catch (e) {
        alert('Erro ao salvar histórico: ' + (e.error || 'Desconhecido'));
        btn.disabled = false;
        btn.innerHTML = originalText;
    }
}

async function _submitCriarSprint(event, league) {
    event.preventDefault();
    const form = event.target;
    const startYear = parseInt(form.start_year.value, 10);
    if (!startYear || startYear < 1900 || startYear > 2200) {
        alert('Ano inválido. Informe um número como 2025.');
        return;
    }
    const btn = form.querySelector('button[type="submit"]');
    const orig = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Criando...';
    try {
        const data = await api('seasons.php?action=create_season', {
            method: 'POST',
            body: JSON.stringify({ league, season_year: startYear, start_year: startYear })
        });
        showAlert('success', data.message || 'Sprint criado com sucesso!');
        setTimeout(() => {
            if (typeof showAdminDraft === 'function') {
                showAdminDraft(league);
            } else {
                showLeague(league);
            }
        }, 800);
    } catch (e) {
        btn.disabled = false;
        btn.innerHTML = orig;
        alert('Erro ao criar sprint: ' + (e?.error || 'Desconhecido'));
    }
}

async function _doCreateNewSeason(league) {
    const currentSeason = await loadCurrentSeason(league);
    const startYear = resolveStartYear(currentSeason) ?? promptStartYear(new Date().getFullYear());
    if (!startYear) return;

    const nextSeasonNumber = Number(currentSeason?.season_number || 0) + 1;
    const seasonYear = startYear + nextSeasonNumber - 1;

    if (!confirm(`Criar temporada ${String(nextSeasonNumber).padStart(2, '0')} para a liga ${league} (ano ${seasonYear})?`)) {
        return;
    }

    try {
        const data = await api('seasons.php?action=create_season', {
            method: 'POST',
            body: JSON.stringify({ league, season_year: seasonYear, start_year: startYear })
        });
        alert(data.message);
        showSeasonsManagement();
    } catch (e) {
        alert('Erro ao criar temporada: ' + (e.error || 'Desconhecido'));
    }
}

// ========== GERENCIAR DRAFT ==========
async function showDraftManagement(seasonId, league) {
    seasonsState.currentLeague = league;
    const season = await loadCurrentSeason(league);

    if (!season) {
        const container = document.getElementById('mainContainer');
        container.innerHTML = `
            <div class="mb-4">
                <button class="btn btn-back" onclick="showSeasonsManagement()">
                    <i class="bi bi-arrow-left"></i> Voltar
                </button>
            </div>
            <div class="alert alert-info" style="border-radius: 15px;">
                <i class="bi bi-info-circle me-2"></i>
                Nenhuma temporada ativa para a liga ${league}. Crie uma temporada primeiro.
            </div>
        `;
        return;
    }

    const container = document.getElementById('mainContainer');
    container.innerHTML = `
        <div class="mb-4">
            <button class="btn btn-back" onclick="showSeasonsManagement()">
                <i class="bi bi-arrow-left"></i> Voltar
            </button>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-md-6">
                <div class="card bg-dark-panel border-orange" style="border-radius: 15px;">
                    <div class="card-body">
                        <h4 class="text-white mb-1">Draft — Temporada ${season.season_number}</h4>
                        <p class="text-light-gray mb-0">${league} | Sprint ${season.sprint_number || '?'} | Ano ${season.year}</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <button class="btn btn-orange w-100 h-100" onclick="showAddDraftPlayerModal(${season.id})" style="border-radius: 15px;">
                    <i class="bi bi-plus-circle me-1"></i>Adicionar Jogador
                </button>
            </div>
            <div class="col-md-3">
                <button class="btn btn-outline-orange w-100 h-100" onclick="showImportCSVModal(${season.id}, '${league}', ${season.season_number})" style="border-radius: 15px;">
                    <i class="bi bi-file-earmark-arrow-up me-1"></i>Importar CSV
                </button>
            </div>
        </div>

        <ul class="nav nav-tabs mb-4">
            <li class="nav-item">
                <a class="nav-link active" id="draft-tab" data-bs-toggle="tab" href="#draft-panel">
                    <i class="bi bi-trophy me-1"></i>Jogadores do Draft
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" id="history-tab" data-bs-toggle="tab" href="#history-panel">
                    <i class="bi bi-clock-history me-1"></i>Cadastrar Histórico
                </a>
            </li>
        </ul>

        <div class="tab-content">
            <div class="tab-pane fade show active" id="draft-panel">
                <div id="draftPlayersContainer">
                    <div class="text-center py-4"><div class="spinner-border text-orange"></div></div>
                </div>
            </div>
            <div class="tab-pane fade" id="history-panel">
                <div id="historyContainer">
                    ${renderHistoryForm(season.id, league)}
                </div>
            </div>
        </div>
    `;

    loadDraftPlayers(season.id);
}

// ========== FORMULÁRIO DE HISTÓRICO ==========
function renderHistoryForm(seasonId, league) {
    return `
        <div class="card bg-dark-panel border-orange" style="border-radius: 15px;">
            <div class="card-body">
                <h5 class="text-white mb-4">
                    <i class="bi bi-pencil-square text-orange me-2"></i>
                    Cadastrar Resultados da Temporada
                </h5>
                
                <form id="formSeasonHistory" onsubmit="saveSeasonHistory(event, ${seasonId})">
                    <h6 class="text-orange mb-3">1. Classificação da Temporada Regular</h6>
                    <div class="mb-4" id="standingsContainer">
                        <button type="button" class="btn btn-sm btn-outline-orange" onclick="loadTeamsForStandings('${league}')">
                            <i class="bi bi-download me-1"></i>Carregar Times da Liga
                        </button>
                    </div>

                    <h6 class="text-orange mb-3">2. Resultados dos Playoffs</h6>
                    <div class="mb-3">
                        <label class="form-label text-light-gray">Campeão</label>
                        <select class="form-select bg-dark text-white border-orange" name="champion_team_id" required style="border-radius: 15px;">
                            <option value="">Selecione o campeão...</option>
                        </select>
                    </div>
                    <div class="mb-4">
                        <label class="form-label text-light-gray">Vice-Campeão</label>
                        <select class="form-select bg-dark text-white border-orange" name="runnerup_team_id" required style="border-radius: 15px;">
                            <option value="">Selecione o vice...</option>
                        </select>
                    </div>

                    <h6 class="text-orange mb-3">3. Eliminados por Fase (apenas perdedores)</h6>
                    <div class="row g-3 mb-4">
                        <div class="col-md-4">
                            <label class="form-label text-light-gray">1ª Rodada (1 ponto)</label>
                            <select class="form-select bg-dark text-white border-orange" name="first_round_losses" multiple style="border-radius: 12px; min-height: 120px;">
                                <option value="">Selecione...</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label text-light-gray">2ª Rodada (3 pontos)</label>
                            <select class="form-select bg-dark text-white border-orange" name="second_round_losses" multiple style="border-radius: 12px; min-height: 120px;">
                                <option value="">Selecione...</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label text-light-gray">Final de Conferência (6 pontos)</label>
                            <select class="form-select bg-dark text-white border-orange" name="conference_final_losses" multiple style="border-radius: 12px; min-height: 120px;">
                                <option value="">Selecione...</option>
                            </select>
                        </div>
                    </div>

                    <h6 class="text-orange mb-3">4. Premiações</h6>
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label text-light-gray">MVP (Time)</label>
                            <select class="form-select bg-dark text-white border-orange" name="mvp_team_id" style="border-radius: 15px;">
                                <option value="">Selecione...</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-light-gray">MVP (Jogador)</label>
                            <input type="text" class="form-control bg-dark text-white border-orange" name="mvp_player_name" placeholder="Nome do jogador" style="border-radius: 15px;">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-light-gray">DPOY (Time)</label>
                            <select class="form-select bg-dark text-white border-orange" name="dpoy_team_id" style="border-radius: 15px;">
                                <option value="">Selecione...</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-light-gray">DPOY (Jogador)</label>
                            <input type="text" class="form-control bg-dark text-white border-orange" name="dpoy_player_name" placeholder="Nome do jogador" style="border-radius: 15px;">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-light-gray">MIP (Time)</label>
                            <select class="form-select bg-dark text-white border-orange" name="mip_team_id" style="border-radius: 15px;">
                                <option value="">Selecione...</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-light-gray">MIP (Jogador)</label>
                            <input type="text" class="form-control bg-dark text-white border-orange" name="mip_player_name" placeholder="Nome do jogador" style="border-radius: 15px;">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-light-gray">6th Man (Time)</label>
                            <select class="form-select bg-dark text-white border-orange" name="sixth_man_team_id" style="border-radius: 15px;">
                                <option value="">Selecione...</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-light-gray">6th Man (Jogador)</label>
                            <input type="text" class="form-control bg-dark text-white border-orange" name="sixth_man_player_name" placeholder="Nome do jogador" style="border-radius: 15px;">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-light-gray">ROY (Time)</label>
                            <select class="form-select bg-dark text-white border-orange" name="roy_team_id" style="border-radius: 15px;">
                                <option value="">Selecione...</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-light-gray">ROY (Jogador)</label>
                            <input type="text" class="form-control bg-dark text-white border-orange" name="roy_player_name" placeholder="Nome do jogador" style="border-radius: 15px;">
                        </div>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-orange" style="border-radius: 15px;">
                            <i class="bi bi-save me-1"></i>Salvar Histórico
                        </button>
                        <button type="button" class="btn btn-outline-orange" onclick="loadDraftPlayers(${seasonId})" style="border-radius: 15px;">
                            Cancelar
                        </button>
                    </div>
                </form>
            </div>
        </div>
    `;
}

async function loadTeamsForStandings(league) {
    try {
        const data = await api(`admin.php?action=teams&league=${league}`);
        const teams = data.teams || [];
        seasonsState.teamsById = Object.fromEntries(teams.map(t => [String(t.id), t]));

        const leste = teams.filter(t => t.conference === 'LESTE');
        const oeste = teams.filter(t => t.conference === 'OESTE');

        const makeSlots = (conf, confTeams) => {
            const opts = '<option value="">—</option>' +
                confTeams.map(t => `<option value="${t.id}">${t.city} ${t.name}</option>`).join('');
            return Array.from({length: 8}, (_, i) => `
                <div class="d-flex align-items-center gap-2 mb-2">
                    <span class="text-light-gray fw-bold" style="width:28px;text-align:right">${i + 1}°</span>
                    <select class="form-select form-select-sm bg-dark text-white border-orange"
                            name="${conf}_rank_${i + 1}" style="border-radius:10px">${opts}</select>
                </div>`).join('');
        };

        const container = document.getElementById('standingsContainer');
        container.innerHTML = `
            <div class="row g-3">
                <div class="col-md-6">
                    <h6 class="text-orange mb-2"><i class="bi bi-geo-alt me-1"></i>Conferência Leste</h6>
                    ${makeSlots('leste', leste)}
                </div>
                <div class="col-md-6">
                    <h6 class="text-orange mb-2"><i class="bi bi-geo-alt me-1"></i>Conferência Oeste</h6>
                    ${makeSlots('oeste', oeste)}
                </div>
            </div>`;

        // Popular selects de premiações
        const selects = document.querySelectorAll('select[name$="_team_id"]');
        selects.forEach(select => {
            select.innerHTML = '<option value="">Selecione...</option>' +
                teams.map(t => `<option value="${t.id}">${t.city} ${t.name}</option>`).join('');
        });

        // Popular multi-selects de eliminados por fase
        ['first_round_losses', 'second_round_losses', 'conference_final_losses'].forEach(name => {
            const select = document.querySelector(`select[name="${name}"]`);
            if (select) {
                select.innerHTML = '<option value="">Selecione...</option>' +
                    teams.map(t => `<option value="${t.id}">${t.city} ${t.name}</option>`).join('');
            }
        });

        if (seasonsState.currentSeasonId) {
            _restoreFormCache(league, seasonsState.currentSeasonId);
            if (_restoreBracketCache(league, seasonsState.currentSeasonId)) {
                _renderBracket(league);
            }
        }
    } catch (e) {
        showAlert('danger', 'Erro ao carregar times: ' + (e.message || e.error || 'Desconhecido'));
    }
}

function saveSeasonHistory(event, seasonId) {
    event.preventDefault();
    const form = event.target;
    const getMulti = (name) => {
        const select = form.querySelector(`select[name="${name}"]`);
        if (!select) return [];
        return Array.from(select.selectedOptions).map(o => o.value).filter(Boolean);
    };

    const champion = form.champion_team_id.value;
    const runnerUp = form.runnerup_team_id.value;
    const firstRound = getMulti('first_round_losses');
    const secondRound = getMulti('second_round_losses');
    const confFinal = getMulti('conference_final_losses');

    if (!champion || !runnerUp) {
        alert('Selecione campeão e vice.');
        return;
    }
    if (champion === runnerUp) {
        alert('Campeão e vice não podem ser iguais.');
        return;
    }

    const allEliminated = [...firstRound, ...secondRound, ...confFinal];
    const hasDuplicates = new Set(allEliminated).size !== allEliminated.length;
    if (hasDuplicates) {
        alert('Um time não pode aparecer em mais de uma fase eliminada.');
        return;
    }
    if (allEliminated.includes(champion) || allEliminated.includes(runnerUp)) {
        alert('Não inclua campeão ou vice nas listas de eliminados.');
        return;
    }

    const getRankList = (conf) => Array.from({length: 8}, (_, i) => {
        const s = form.querySelector(`[name="${conf}_rank_${i + 1}"]`);
        return s ? (s.value || null) : null;
    }).filter(Boolean);

    const payload = {
        season_id: seasonId,
        champion,
        runner_up: runnerUp,
        first_round_losses: firstRound,
        second_round_losses: secondRound,
        conference_final_losses: confFinal,
        standings_leste: getRankList('leste'),
        standings_oeste: getRankList('oeste'),
        mvp: form.mvp_player_name.value || null,
        mvp_team_id: form.mvp_team_id.value || null,
        dpoy: form.dpoy_player_name.value || null,
        dpoy_team_id: form.dpoy_team_id.value || null,
        mip: form.mip_player_name.value || null,
        mip_team_id: form.mip_team_id.value || null,
        sixth_man: form.sixth_man_player_name.value || null,
        sixth_man_team_id: form.sixth_man_team_id.value || null,
        roy: form.roy_player_name?.value || null,
        roy_team_id: form.roy_team_id?.value || null
    };

    const btn = form.querySelector('button[type="submit"]');
    const originalText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Salvando...';

    api('seasons.php?action=save_history', {
        method: 'POST',
        body: JSON.stringify(payload)
    }).then(() => {
        alert('Histórico salvo! Pontuação atualizada.');
        loadDraftPlayers(seasonId);
    }).catch(e => {
        alert('Erro ao salvar histórico: ' + (e.error || 'Desconhecido'));
    }).finally(() => {
        btn.disabled = false;
        btn.innerHTML = originalText;
    });
}

// ========== GERENCIAR DRAFT (continuação) ==========

async function loadDraftPlayers(seasonId) {
    try {
        const data = await api(`seasons.php?action=draft_players&season_id=${seasonId}`);
        seasonsState.draftPlayers = data.players;
        renderDraftPlayers(data.players);
    } catch (e) {
        document.getElementById('draftPlayersContainer').innerHTML = `
            <div class="alert alert-danger">Erro ao carregar jogadores: ${e.error}</div>
        `;
    }
}

function renderDraftPlayers(players) {
    const available = players.filter(p => p.draft_status === 'available');
    const drafted   = players.filter(p => p.draft_status === 'drafted');

    const availableRows = available.map((p, idx) => `
        <tr>
            <td style="color:var(--text-3)">${idx + 1}</td>
            <td>
                <input type="number" min="1" value="${p.pick_hint || ''}" placeholder="—"
                    style="width:58px;background:var(--panel-3);border:1px solid var(--border-md);border-radius:7px;padding:4px 8px;color:var(--text);font-size:12px;text-align:center"
                    onchange="updatePickHint(${p.id}, this.value)">
            </td>
            <td style="font-weight:600">${escapeHtml(p.name)}</td>
            <td><span class="badge bg-gradient-orange">${p.position || '—'}</span></td>
            <td><span class="badge bg-success">OVR ${p.ovr}</span></td>
            <td style="color:var(--text-2)">${p.age} anos</td>
            <td>
                <button class="btn btn-sm btn-outline-danger" onclick="deleteDraftPlayer(${p.id})">
                    <i class="bi bi-trash"></i>
                </button>
            </td>
        </tr>`).join('') || `<tr><td colspan="7" class="text-center text-muted py-3">Nenhum jogador disponível</td></tr>`;

    const draftedList = drafted.map(p => `
        <div class="d-flex justify-content-between align-items-center mb-2 p-2 rounded" style="background:var(--panel-3)">
            <div>
                <div class="text-white small">${escapeHtml(p.name)}</div>
                <div class="text-muted" style="font-size:.75rem">Pick #${p.draft_order || '—'}</div>
            </div>
            <span class="badge bg-success">${p.ovr}</span>
        </div>`).join('') || '<p class="text-muted small">Nenhum ainda</p>';

    document.getElementById('draftPlayersContainer').innerHTML = `
        <div class="row g-3">
            <div class="col-lg-8">
                <div class="bg-dark-panel border-orange rounded p-4">
                    <h5 class="text-white mb-3"><i class="bi bi-people-fill me-2 text-orange"></i>Disponíveis (${available.length})</h5>
                    <div class="table-responsive">
                        <table class="table table-dark table-hover" style="font-size:13px">
                            <thead>
                                <tr>
                                    <th>#</th><th>Ordem</th><th>Nome</th><th>Pos</th><th>OVR</th><th>Idade</th><th></th>
                                </tr>
                            </thead>
                            <tbody>${availableRows}</tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div class="col-lg-4">
                <div class="bg-dark-panel rounded p-4" style="border:1px solid rgba(37,198,119,.3)">
                    <h5 class="text-white mb-3"><i class="bi bi-check-circle-fill me-2 text-success"></i>Draftados (${drafted.length})</h5>
                    ${draftedList}
                </div>
            </div>
        </div>`;
}

// ========== MODAL ADICIONAR JOGADOR ==========
function showAddDraftPlayerModal(seasonId) {
    const modalHtml = `
        <div class="modal fade" id="addDraftPlayerModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content bg-dark-panel border-orange">
                    <div class="modal-header border-orange">
                        <h5 class="modal-title text-white"><i class="bi bi-person-plus-fill text-orange me-2"></i>Adicionar Jogador ao Draft</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <form id="formAddDraftPlayer">
                            <input type="hidden" name="season_id" value="${seasonId}">
                            <div class="row g-3">
                                <div class="col-12">
                                    <label class="form-label text-light-gray">Nome</label>
                                    <input type="text" class="form-control bg-dark text-white border-orange" name="name" required>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label text-light-gray">Posição</label>
                                    <select class="form-select bg-dark text-white border-orange" name="position" required>
                                        <option value="">Selecione...</option>
                                        <option value="PG">PG - Armador</option>
                                        <option value="SG">SG - Ala-Armador</option>
                                        <option value="SF">SF - Ala</option>
                                        <option value="PF">PF - Ala-Pivô</option>
                                        <option value="C">C - Pivô</option>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label text-light-gray">Idade</label>
                                    <input type="number" class="form-control bg-dark text-white border-orange" name="age" min="18" max="50" required>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label text-light-gray">OVR</label>
                                    <input type="number" class="form-control bg-dark text-white border-orange" name="ovr" min="40" max="99" required>
                                </div>
                                <div class="col-12">
                                    <label class="form-label text-light-gray">Ordem de Pick <span class="text-muted">(opcional)</span></label>
                                    <input type="number" class="form-control bg-dark text-white border-orange" name="pick_hint" min="1" placeholder="Ex: 1">
                                </div>
                            </div>
                        </form>
                    </div>
                    <div class="modal-footer border-orange">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="button" class="btn btn-orange" onclick="submitDraftPlayer()">Adicionar</button>
                    </div>
                </div>
            </div>
        </div>`;

    document.body.insertAdjacentHTML('beforeend', modalHtml);
    const modal = new bootstrap.Modal(document.getElementById('addDraftPlayerModal'));
    modal.show();
    document.getElementById('addDraftPlayerModal').addEventListener('hidden.bs.modal', function() { this.remove(); });
}

async function submitDraftPlayer() {
    const form = document.getElementById('formAddDraftPlayer');
    const formData = new FormData(form);
    const hintVal = formData.get('pick_hint');
    const payload = {
        season_id: formData.get('season_id'),
        name: formData.get('name'),
        position: formData.get('position'),
        age: formData.get('age'),
        ovr: formData.get('ovr'),
        pick_hint: hintVal !== '' ? hintVal : null,
    };

    try {
        await api('seasons.php?action=add_draft_player', {
            method: 'POST',
            body: JSON.stringify(payload)
        });
        bootstrap.Modal.getInstance(document.getElementById('addDraftPlayerModal')).hide();
        loadDraftPlayers(payload.season_id);
        showAlert('success', 'Jogador adicionado ao draft!');
    } catch (e) {
        showAlert('danger', 'Erro: ' + (e.error || 'Desconhecido'));
    }
}

async function deleteDraftPlayer(id) {
    if (!confirm('Remover este jogador do draft?')) return;
    
    try {
        await api(`seasons.php?action=delete_draft_player&id=${id}`, { method: 'DELETE' });
        const seasonId = seasonsState.currentSeason ? seasonsState.currentSeason.id : null;
        if (seasonId) {
            loadDraftPlayers(seasonId);
        }
        alert('Jogador removido do draft!');
    } catch (e) {
        alert('Erro: ' + (e.error || 'Desconhecido'));
    }
}

// ========== RANKING ==========
async function showRankingPage(type = 'global') {
    // Atualizar breadcrumb
    appState.view = 'ranking';
    updateBreadcrumb();
    
    const container = document.getElementById('mainContainer');
    container.innerHTML = `
        <div class="mb-4">
            <h4 class="text-white mb-3">
                <i class="bi bi-trophy-fill me-2 text-orange"></i>
                Ranking ${type === 'global' ? 'Geral' : 'por Liga'}
            </h4>
            <div class="btn-group mb-3">
                <button class="btn ${type === 'global' ? 'btn-orange' : 'btn-outline-orange'}" onclick="showRankingPage('global')">Geral</button>
                <button class="btn ${type === 'elite' ? 'btn-orange' : 'btn-outline-orange'}" onclick="showRankingPage('elite')">ELITE</button>
                <button class="btn ${type === 'next' ? 'btn-orange' : 'btn-outline-orange'}" onclick="showRankingPage('next')">NEXT</button>
                <button class="btn ${type === 'rise' ? 'btn-orange' : 'btn-outline-orange'}" onclick="showRankingPage('rise')">RISE</button>
                <button class="btn ${type === 'rookie' ? 'btn-orange' : 'btn-outline-orange'}" onclick="showRankingPage('rookie')">ROOKIE</button>
            </div>
        </div>
        <div id="rankingContainer">
            <div class="text-center py-4">
                <div class="spinner-border text-orange"></div>
            </div>
        </div>
    `;
    
    try {
        const endpoint = type === 'global' 
            ? 'seasons.php?action=global_ranking'
            : `seasons.php?action=league_ranking&league=${type.toUpperCase()}`;
        
        const data = await api(endpoint);
        renderRanking(data.ranking);
    } catch (e) {
        document.getElementById('rankingContainer').innerHTML = `
            <div class="alert alert-danger">Erro ao carregar ranking</div>
        `;
    }
}

function renderRanking(ranking) {
    const container = document.getElementById('rankingContainer');
    container.innerHTML = `
        <div class="table-responsive">
            <table class="table table-dark table-hover">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Time</th>
                        <th>Liga</th>
                        <th>Pontos</th>
                        <th>Temporadas</th>
                        <th>🏆 Títulos</th>
                        <th>🥈 Vices</th>
                        <th>⭐ Prêmios</th>
                    </tr>
                </thead>
                <tbody>
                    ${ranking.map((team, idx) => `
                        <tr>
                            <td><strong class="text-orange">${idx + 1}º</strong></td>
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <img src="${team.photo_url || '/img/default-team.png'}" 
                                         alt="${team.team_name}" 
                                         style="width: 32px; height: 32px; border-radius: 50%; object-fit: cover;">
                                    <span>${team.city} ${team.team_name}</span>
                                </div>
                            </td>
                            <td><span class="badge bg-gradient-orange">${team.league}</span></td>
                            <td><strong class="text-warning">${team.total_points || 0}</strong></td>
                            <td>${team.seasons_played || 0}</td>
                            <td>${team.championships || 0}</td>
                            <td>${team.runner_ups || 0}</td>
                            <td>${team.total_awards || 0}</td>
                        </tr>
                    `).join('')}
                </tbody>
            </table>
        </div>
    `;
}

// ========== PICK HINT ==========
async function updatePickHint(playerId, value) {
    try {
        await api('seasons.php?action=update_draft_player', {
            method: 'POST',
            body: JSON.stringify({ player_id: playerId, pick_hint: value !== '' ? value : null })
        });
    } catch (e) {
        showAlert('danger', 'Erro ao salvar ordem: ' + (e.error || 'Desconhecido'));
    }
}

// ========== IMPORTAR CSV ==========
function showImportCSVModal(seasonId, league, seasonNumber) {
    const modalHtml = `
        <div class="modal fade" id="importCSVModal" tabindex="-1">
            <div class="modal-dialog modal-lg">
                <div class="modal-content bg-dark-panel border-orange">
                    <div class="modal-header border-orange">
                        <h5 class="modal-title text-white"><i class="bi bi-file-earmark-arrow-up text-orange me-2"></i>Importar Jogadores via CSV</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="alert alert-info mb-3">
                            <strong>Temporada:</strong> ${escapeHtml(league)} — Temporada ${seasonNumber}
                        </div>
                        <div class="card bg-dark-panel border-orange mb-3">
                            <div class="card-body">
                                <h6 class="text-orange"><i class="bi bi-info-circle me-1"></i>Formato do CSV</h6>
                                <p class="text-light-gray small mb-2">
                                    Colunas: <code>nome, posicao, idade, ovr, ordem</code> <span class="text-muted">(ordem é opcional)</span>
                                </p>
                                <pre class="bg-dark p-2 rounded" style="font-size:11px;color:var(--text-2)">nome,posicao,idade,ovr,ordem
LeBron James,SF,39,96,1
Stephen Curry,PG,35,95,2</pre>
                                <button class="btn btn-sm btn-outline-orange mt-2" onclick="downloadCSVTemplate()">
                                    <i class="bi bi-download me-1"></i>Baixar Template
                                </button>
                            </div>
                        </div>
                        <form id="importCSVForm" onsubmit="submitImportCSV(event, ${seasonId})">
                            <div class="mb-3">
                                <label class="form-label text-light-gray">Selecione o arquivo CSV</label>
                                <input type="file" id="csvFileInput" accept=".csv" required class="form-control bg-dark text-white border-orange">
                            </div>
                            <button type="submit" class="btn btn-orange w-100">
                                <i class="bi bi-upload me-1"></i>Importar Jogadores
                            </button>
                        </form>
                        <div id="importResult" class="mt-3" style="display:none"></div>
                    </div>
                </div>
            </div>
        </div>`;

    document.body.insertAdjacentHTML('beforeend', modalHtml);
    const modal = new bootstrap.Modal(document.getElementById('importCSVModal'));
    modal.show();
    document.getElementById('importCSVModal').addEventListener('hidden.bs.modal', function() { this.remove(); });
}

async function submitImportCSV(event, seasonId) {
    event.preventDefault();
    const fileInput = document.getElementById('csvFileInput');
    const file = fileInput.files[0];
    if (!file) { showAlert('danger', 'Selecione um arquivo CSV'); return; }

    const formData = new FormData();
    formData.append('csv_file', file);
    formData.append('season_id', seasonId);

    const resultDiv = document.getElementById('importResult');
    resultDiv.style.display = 'block';
    resultDiv.innerHTML = '<div class="alert alert-info"><i class="bi bi-hourglass-split me-2"></i>Importando...</div>';

    try {
        const response = await fetch('/api/import-draft-players.php', { method: 'POST', body: formData });
        const data = await response.json();
        if (response.ok && data.success) {
            resultDiv.innerHTML = `<div class="alert alert-success"><i class="bi bi-check-circle me-2"></i>${data.message}</div>`;
            setTimeout(() => {
                bootstrap.Modal.getInstance(document.getElementById('importCSVModal'))?.hide();
                const league = seasonsState.currentLeague;
                if (league) showDraftManagement(null, league);
            }, 2000);
        } else {
            resultDiv.innerHTML = `<div class="alert alert-danger"><i class="bi bi-x-circle me-2"></i>${data.error || 'Erro desconhecido'}</div>`;
        }
    } catch (e) {
        resultDiv.innerHTML = `<div class="alert alert-danger"><i class="bi bi-x-circle me-2"></i>${e.message || 'Erro'}</div>`;
    }
}

function downloadCSVTemplate() {
    const csv = 'nome,posicao,idade,ovr,ordem\nLeBron James,SF,39,96,1\nStephen Curry,PG,35,95,2\nKevin Durant,PF,35,94,3\n';
    const blob = new Blob([csv], { type: 'text/csv' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url; a.download = 'template-draft-players.csv'; a.click();
    window.URL.revokeObjectURL(url);
}

// Expor funções para o escopo global (necessário para onclick no HTML)
window.showSeasonsManagement = showSeasonsManagement;
window.showAvancarTemporada = showAvancarTemporada;
window._confirmAdvanceSeason = _confirmAdvanceSeason;
window.showRegistroPontuacao = showRegistroPontuacao;
window.saveRegistroPontuacao = saveRegistroPontuacao;
window.saveAndAdvanceSeason = saveAndAdvanceSeason;
window.createNewSeason = createNewSeason;
window.showDraftManagement = showDraftManagement;
window.deleteDraftPlayer = deleteDraftPlayer;
window.updatePickHint = updatePickHint;
window.showImportCSVModal = showImportCSVModal;
window.submitImportCSV = submitImportCSV;
window.downloadCSVTemplate = downloadCSVTemplate;
window.submitDraftPlayer = submitDraftPlayer;
window._saveReviewedPoints = _saveReviewedPoints;
window._submitCriarSprint = _submitCriarSprint;
