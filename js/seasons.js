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

async function promptStartYear(defaultYear) {
    const fallback = defaultYear ?? new Date().getFullYear();
    const input = await perguntarSite('Informe o ano inicial do sprint (ex: 2016):', fallback);
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
        { name: 'ELITE' },
        { name: 'NEXT' },
        { name: 'RISE' },
        { name: 'ROOKIE' },
    ];

    const results = await Promise.allSettled(
        leagues.map(l => api(`seasons.php?action=current_season&league=${l.name}`))
    );

    const leagueCards = leagues.map((l, i) => {
        const season = results[i].status === 'fulfilled' ? results[i].value?.season : null;
        const hasSprint = !!season;
        const maxSeasons = season?.sprint_max_seasons;
        const label = maxSeasons ? `${maxSeasons} temporadas por sprint` : 'Temporadas por sprint';
        const seasonInfo = season
            ? `Sprint ${season.sprint_number || '?'} · T${season.season_number || '?'} · ${season.year || ''}`
            : 'Sem sprint ativo';
        const isLastSeason = hasSprint && maxSeasons && Number(season.season_number) >= Number(maxSeasons);

        const mainBtn = !hasSprint
            ? `<button class="btn btn-sm btn-orange w-100 mb-2" onclick="showAvancarTemporada('${l.name}')">
                   <i class="bi bi-play-circle me-1"></i>Iniciar Sprint
               </button>`
            : isLastSeason
            ? `<button class="btn btn-sm btn-danger w-100 mb-2" onclick="showFinalizarSprint('${l.name}')">
                   <i class="bi bi-flag-fill me-1"></i>Finalizar Sprint
               </button>`
            : `<button class="btn btn-sm btn-outline-orange w-100 mb-2" onclick="showAvancarTemporada('${l.name}')">
                   <i class="bi bi-arrow-right-circle me-1"></i>Avançar Temporada
               </button>`;

        return `
            <div class="col-md-6 col-lg-3">
                <div class="league-card" style="cursor:default">
                    <h3>${l.name}</h3>
                    <p class="text-light-gray mb-1">${label}</p>
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
    const startYear = resolveStartYear(currentSeason) ?? await promptStartYear(new Date().getFullYear());
    if (!startYear) return;

    const nextSeasonNumber = Number(currentSeason?.season_number || 0) + 1;
    const seasonYear = startYear + nextSeasonNumber - 1;

    if (!await confirmarSite(`Criar temporada ${String(nextSeasonNumber).padStart(2, '0')} para a liga ${league} (ano ${seasonYear})?`)) {
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
        // A ORDEM DA CHAVE, de cima pra baixo: 1x8, 4x5, 3x6, 2x7.
        //
        // O 2x7 é o ÚLTIMO, não o penúltimo. É o que põe o 1º e o 2º nas duas
        // pontas da conferência — eles só se encontram na final dela, que é o
        // sentido de premiar a campanha. Com o 2x7 no meio, os dois melhores
        // caíam do mesmo lado do quadro.
        //
        // As semis continuam sendo topo (1x8 e 4x5) contra baixo (3x6 e 2x7),
        // porque _rebuildConf pareia r1[0]×r1[1] e r1[2]×r1[3] — trocar a
        // ordem aqui só muda quem é mostrado primeiro dentro do mesmo par.
        //
        // api/playoffs.php já montava assim; o chaveamento do admin era o
        // único fora de passo.
        const initConf = (s) => ({
            r1: [
                {t1: s[0], t2: s[7], w: null, s1: 1, s2: 8},
                {t1: s[3], t2: s[4], w: null, s1: 4, s2: 5},
                {t1: s[2], t2: s[5], w: null, s1: 3, s2: 6},
                {t1: s[1], t2: s[6], w: null, s1: 2, s2: 7},
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

/**
 * Em quantos jogos a série foi (4 a 7). Clicar na bolinha já marcada limpa —
 * é como desmarcar o vencedor, e evita ficar preso num número errado.
 */
function _setBracketJogos(conf, round, idx, valor) {
    const b = _bracket;
    if (!b) return;
    const m = round === 'final' ? b.final : (round === 'cf' ? b[conf]?.cf : b[conf]?.[round]?.[idx]);
    if (!m) return;
    const n = Number(valor);
    m.g = (n >= 4 && n <= 7 && m.g !== n) ? n : null;
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
    s.textContent = `.bk-wrap{display:flex;align-items:stretch;overflow-x:auto;padding-bottom:4px;gap:4px}.bk-col{display:flex;flex-direction:column;min-width:148px;flex-shrink:0}.bk-col-mid{display:flex;flex-direction:column;justify-content:center;align-items:center;min-width:148px;flex-shrink:0;padding:0 4px}.bk-col-label{font-size:10px;color:#777;text-transform:uppercase;letter-spacing:.06em;text-align:center;padding:0 0 5px}.bk-matchup{border:1px solid #272727;border-radius:8px;overflow:hidden;background:#141414;margin:1px 0}.bk-empty{height:54px;display:flex;align-items:center;justify-content:center;color:#2a2a2a;font-size:18px;margin:1px 0}.bk-team{display:flex;align-items:center;padding:5px 7px;font-size:12px;cursor:pointer;border-bottom:1px solid #1c1c1c;transition:background .1s;user-select:none;line-height:1.2;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.bk-team:last-child{border-bottom:none}.bk-team:hover:not(.bk-loss):not(.bk-tbd){background:rgba(255,107,0,.12)}.bk-win{background:rgba(255,107,0,.2)!important;color:#ff6b00;font-weight:700}.bk-loss{opacity:.28;cursor:default}.bk-tbd{color:#383838;cursor:default;font-style:italic}.bk-seed{display:inline-flex;align-items:center;justify-content:center;width:15px;height:15px;border-radius:3px;background:#202020;color:#777;font-size:9px;font-weight:700;margin-right:5px;flex-shrink:0}.bk-win .bk-seed{background:rgba(255,107,0,.3);color:#ff6b00}.bk-sp{flex:1}.bk-jogos{border-top:1px solid #1c1c1c;padding:4px 5px;background:#111;display:flex;gap:4px;justify-content:center}.bk-bola{width:20px;height:20px;border-radius:50%;border:1px solid #2e2e2e;background:#1a1a1a;color:#666;font-size:10px;font-weight:700;line-height:1;padding:0;cursor:pointer;transition:all .12s;font-family:inherit}.bk-bola:hover{border-color:#ff6b00;color:#ff6b00}.bk-bola.on{background:#ff6b00;border-color:#ff6b00;color:#fff}.bk-champ{margin-top:8px;padding:7px 10px;background:rgba(255,107,0,.12);border:1px solid rgba(255,107,0,.5);border-radius:9px;text-align:center}`;
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

        // Em quantos jogos a série foi, em bolinhas. O placar não é digitado:
        // numa melhor de 7 o vencedor sempre faz 4, então marcar 6 já diz 4-2.
        // Aparecem assim que o confronto existe, mesmo antes de escolher o
        // vencedor — quem está preenchendo já sabe o placar e não precisa
        // fazer em duas passadas.
        let jogos = '';
        if (t1 && t2) {
            const gArg = round === 'final' ? `null,'final',0`
                       : round === 'cf'   ? `'${conf}','cf',0`
                       : `'${conf}','${round}',${idx}`;
            const bolas = [4, 5, 6, 7].map((n) =>
                `<button type="button" class="bk-bola${Number(m.g) === n ? ' on' : ''}"
                         title="4-${n - 4} em ${n} jogos"
                         onclick="_setBracketJogos(${gArg}, ${n})">${n}</button>`).join('');
            jogos = `<div class="bk-jogos">${bolas}</div>`;
        }
        return `<div class="bk-matchup">${btn(t1, s1)}${btn(t2, s2)}${jogos}</div>`;
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
    // Cada confronto vira uma série: os dois times, quem passou e em quantos
    // jogos. Série sem o número de jogos é descartada — meia série gravada
    // viraria "4-0" na leitura, que é diferente de "não informado".
    const series = [];
    const addSerie = (m, fase, conferencia) => {
        if (!m?.w || !m.t1 || !m.t2 || !m.g) return;
        series.push({
            fase,
            conferencia: conferencia ? conferencia.toUpperCase() : null,
            team_a_id: Number(m.t1.id),
            team_b_id: Number(m.t2.id),
            winner_team_id: Number(m.w),
            jogos: Number(m.g),
        });
    };
    ['leste', 'oeste'].forEach((conf) => {
        (b[conf]?.r1 || []).forEach((m) => addSerie(m, 'r1', conf));
        (b[conf]?.r2 || []).forEach((m) => addSerie(m, 'r2', conf));
        addSerie(b[conf]?.cf, 'cf', conf);
    });
    addSerie(b.final, 'final', null);

    return {
        champion: b.final.w,
        runner_up: loser(b.final),
        first_round_losses: [...b.leste.r1, ...b.oeste.r1].map(loser).filter(Boolean),
        second_round_losses: [...(b.leste.r2||[]), ...(b.oeste.r2||[])].map(loser).filter(Boolean),
        conference_final_losses: [loser(b.leste.cf), loser(b.oeste.cf)].filter(Boolean),
        series,
    };
}

function _saveBracketCache(league, seasonId) {
    if (!league || !seasonId) return;
    localStorage.setItem(`bk_${league}_${seasonId}`, JSON.stringify(_bracket));
    // O chaveamento também é rascunho: marcar meia chave e fechar a aba não
    // pode custar o preenchimento. Só sobe quando a temporada em tela é a
    // mesma do registro — a tela de avançar temporada usa este mesmo cache.
    if (Number(seasonId) === Number(_regPtsSeasonId)) _regPtsAutosaveServidor();
}
function _restoreBracketCache(league, seasonId) {
    if (!league || !seasonId) return false;
    const raw = localStorage.getItem(`bk_${league}_${seasonId}`);
    // Sem cópia local não se mexe no que já está em memória: o chaveamento
    // pode ter vindo do rascunho do servidor, e sobrescrever com null aqui
    // apagava justamente o que veio de outro aparelho.
    if (!raw) return false;
    try {
        const parsed = JSON.parse(raw);
        if (!parsed) return false;
        _bracket = parsed;
        return true;
    } catch (_) { return false; }
}
function _clearBracketCache(league, seasonId) {
    if (league && seasonId) localStorage.removeItem(`bk_${league}_${seasonId}`);
}

// ========== REGISTRO DE PONTUAÇÃO — estado ==========
let _regPtsAllTeams = [];
let _regPtsCacheKey = '';
let _regPtsLeague = '';
let _regPtsSeasonId = null;
let _regPtsPendingPayload = null;
let _regPtsForceEdit = false;
let _regPtsIsCorrection = false;
// Em qual das duas etapas o registro está: 'regular' enquanto a campanha não
// foi salva, 'playoffs' depois. Vem do rascunho no servidor.
let _regPtsEtapa = 'regular';
let _regPtsAutosaveTimer = null;
// A restauração do rascunho em andamento. Salvar antes dela terminar leria
// selects de jogador ainda vazios — ver onde ela é preenchida.
let _regPtsRestaurando = null;

/**
 * As vagas dos prêmios estendidos — exclusivos da ELITE.
 *
 * Eram um card separado no admin (showExtendedAwards). Continuam existindo
 * lá pra corrigir temporada antiga, mas no registro do ano corrente entram
 * junto com o resto: preencher o MVP numa tela e o All-NBA noutra era o
 * tipo de ida e volta que faz alguém esquecer metade.
 *
 * `fase` diz em qual etapa a vaga aparece, e não é detalhe: o Finals MVP só
 * existe depois da Grande Final. Pedir ele junto da temporada regular era
 * pedir uma resposta que ninguém tem — por isso ele vai pra etapa 2, ao lado
 * do chaveamento que acabou de decidir quem foi.
 */
const REG_PTS_EXTENDED = [
    { tipo: 'finals_mvp', titulo: '🏆 Finals MVP',           vagas: 1, bonus: '+3M', fase: 'playoffs' },
    { tipo: 'all_nba_1',  titulo: 'All-NBA — 1º Time',       vagas: 5, bonus: '+3M', fase: 'regular'  },
    { tipo: 'all_nba_2',  titulo: 'All-NBA — 2º Time',       vagas: 5, bonus: '+2M', fase: 'regular'  },
    { tipo: 'all_nba_3',  titulo: 'All-NBA — 3º Time',       vagas: 5, bonus: '+1M', fase: 'regular'  },
    { tipo: 'all_def_1',  titulo: 'All-Defensive — 1º Time', vagas: 5, bonus: '+2M', fase: 'regular'  },
    { tipo: 'all_def_2',  titulo: 'All-Defensive — 2º Time', vagas: 5, bonus: '+1M', fase: 'regular'  },
];

/** Os tipos de prêmio estendido preenchidos em cada etapa. */
const REG_PTS_EXT_TIPOS = fase => REG_PTS_EXTENDED.filter(x => x.fase === fase).map(x => x.tipo);

function _regPtsSaveCache() {
    if (!_regPtsCacheKey) return;
    const form = document.getElementById('formRegistroPontuacao');
    const formState = {};
    if (form) {
        /* Caixa marcada guarda o estado, não o `value`. Num checkbox o
           `.value` é "on" esteja ele marcado ou não, então guardá-lo direto
           fazia o rascunho voltar com TODAS as caixas marcadas — bastava
           marcar duas pro formulário reabrir com dezesseis. */
        form.querySelectorAll('[name]').forEach(el => {
            formState[el.name] = (el.type === 'checkbox' || el.type === 'radio')
                ? (el.checked ? '1' : '')
                : el.value;
        });
    }
    localStorage.setItem(_regPtsCacheKey, JSON.stringify({ form: formState }));
    _regPtsAutosaveServidor();
}

function _regPtsLoadCache() {
    if (!_regPtsCacheKey) return null;
    const raw = localStorage.getItem(_regPtsCacheKey);
    try { return raw ? JSON.parse(raw) : null; } catch (_) { return null; }
}

/**
 * Manda o rascunho pro servidor, com folga entre uma tecla e outra.
 *
 * O localStorage já guarda na hora, mas morre com o navegador — e o
 * preenchimento acontece em dois dias, às vezes em máquinas diferentes.
 * A folga existe pra que digitar um nome não vire uma requisição por letra.
 */
function _regPtsAutosaveServidor() {
    if (!_regPtsSeasonId) return;
    if (!document.getElementById('formRegistroPontuacao')) return;
    clearTimeout(_regPtsAutosaveTimer);

    // O QUE VAI é fotografado AGORA, não quando o temporizador disparar.
    //
    // Antes a leitura era lá na frente, e a tela pode ter sido re-renderizada
    // nesse meio tempo: sem formulário no DOM, a gravação era abandonada e o
    // que estava preenchido nunca chegava ao servidor. Foi assim que o
    // chaveamento sumia do rascunho. Lendo aqui, o adiamento só junta teclas
    // — nunca decide se grava ou não.
    const seasonAlvo = _regPtsSeasonId;
    const dados = _regPtsRascunhoAtual();
    _regPtsAutosaveTimer = setTimeout(() => {
        if (Number(seasonAlvo) !== Number(_regPtsSeasonId)) return;
        api('seasons.php?action=salvar_rascunho', {
            method: 'POST',
            body: JSON.stringify({ season_id: seasonAlvo, dados })
        }).catch(() => { /* o rascunho local continua valendo */ });
    }, 1500);
}

/** Tudo que está digitado agora: o formulário inteiro mais o chaveamento. */
function _regPtsRascunhoAtual() {
    const form = document.getElementById('formRegistroPontuacao');
    const formState = {};
    // Mesma regra do rascunho local: checkbox guarda estado, não `value`.
    if (form) form.querySelectorAll('[name]').forEach(el => {
        formState[el.name] = (el.type === 'checkbox' || el.type === 'radio')
            ? (el.checked ? '1' : '')
            : el.value;
    });
    return { form: formState, bracket: _bracket || null };
}

/**
 * As vagas de prêmio estendido preenchidas, prontas pra API.
 *
 * `fase` limita ao que aquela etapa preenche. A etapa 1 manda só os seus:
 * se mandasse a lista inteira, um Finals MVP já gravado na etapa 2 sumiria
 * na primeira vez que alguém voltasse pra corrigir uma posição — a API
 * substitui os tipos que recebe.
 */
function _regPtsCollectExtended(fase) {
    const form = document.getElementById('formRegistroPontuacao');
    if (!form) return [];
    const out = [];
    REG_PTS_EXTENDED
        .filter(x => !fase || x.fase === fase)
        .forEach(({ tipo, vagas }) => {
            for (let i = 0; i < vagas; i++) {
                const team = form.querySelector(`[name="ext_${tipo}_${i}_team"]`)?.value || '';
                const player = (form.querySelector(`[name="ext_${tipo}_${i}_player"]`)?.value || '').trim();
                if (team && player) out.push({ award_type: tipo, team_id: team, player_name: player });
            }
        });
    return out;
}

// ─── Auto-cálculo de pontos ───────────────────────────────────────────────────
function _calcAutoPoints(payload, allTeams, league) {
    const pts = {};
    allTeams.forEach(t => { pts[String(t.id)] = { seed: 0, playoff: 0, awards: 0, cup: 0 }; });

    // A régua é a mesma de backend/pontuacao_ranking.php. Mudou lá, muda aqui.
    const seedPts = (rank) => rank <= 2 ? 5 : rank <= 4 ? 4 : rank <= 6 ? 3 : rank <= 8 ? 2 : rank <= 10 ? 1 : 0;
    (payload.standings_leste || []).forEach((id, i) => { const k = String(id); if (pts[k] !== undefined) pts[k].seed = seedPts(i + 1); });
    (payload.standings_oeste || []).forEach((id, i) => { const k = String(id); if (pts[k] !== undefined) pts[k].seed = seedPts(i + 1); });

    // Totais acumulados por onde o time parou. Quem chega à final leva a
    // final de conferência junto: vice 4+3 = 7, campeão 7+3 = 10. Cair na
    // 1ª rodada não pontua. Mesma tabela de PONTOS_PLAYOFF no PHP.
    const PLAYOFF = { champion: 10, runner_up: 7, conference_final: 3, second_round: 1, first_round: 0 };
    if (payload.champion)  { const k = String(payload.champion);  if (pts[k] !== undefined) pts[k].playoff = PLAYOFF.champion; }
    if (payload.runner_up) { const k = String(payload.runner_up); if (pts[k] !== undefined) pts[k].playoff = PLAYOFF.runner_up; }
    (payload.conference_final_losses || []).forEach(id => { const k = String(id); if (pts[k] !== undefined) pts[k].playoff = PLAYOFF.conference_final; });
    (payload.second_round_losses     || []).forEach(id => { const k = String(id); if (pts[k] !== undefined) pts[k].playoff = PLAYOFF.second_round; });
    (payload.first_round_losses      || []).forEach(id => { const k = String(id); if (pts[k] !== undefined) pts[k].playoff = PLAYOFF.first_round; });

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
                           data-pts-regular="${p.seed}"
                           data-pts-playoffs="${p.playoff}"
                           data-pts-prizes="${p.awards + p.cup}"
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
                            <th style="padding:8px 12px;text-align:center;color:var(--text-2);font-weight:600" title="1º e 2º=5 · 3º e 4º=4 · 5º e 6º=3 · 7º e 8º=2 · 9º e 10º=1">Classificação</th>
                            <th style="padding:8px 12px;text-align:center;color:var(--text-2);font-weight:600" title="Campeão=10 · Vice=7 · Final de conf.=3 · 2º turno=1 · 1ª rodada=0">Playoffs</th>
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
                Classificação: 1º e 2º=5 · 3º e 4º=4 · 5º e 6º=3 · 7º e 8º=2 · 9º e 10º=1 &nbsp;|&nbsp; Playoffs: Campeão=10 · Vice=7 · Final de conf.=3 · 2º turno=1 &nbsp;|&nbsp; Prêmios: 1pt cada${league === 'ELITE' ? ' &nbsp;|&nbsp; NBA Cup: 2pts' : ''}
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

    if (_regPtsIsCorrection && !await confirmarSite('Esta temporada já tinha sido registrada antes. Salvar agora vai sobrescrever o campeão, a classificação e os prêmios registrados anteriormente. Confirmar?')) {
        return;
    }

    const btn = document.getElementById('btnSaveReview');
    const originalHtml = btn ? btn.innerHTML : '';
    if (btn) { btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Salvando...'; }

    try {
        // 1. Registrar dados da temporada (campeão, classificação, prêmios)
        if (_regPtsPendingPayload) {
            await api('seasons.php?action=register_pontuacao', {
                method: 'POST',
                body: JSON.stringify(_regPtsPendingPayload)
            });
            _regPtsPendingPayload = null;
        }

        // 2. Salvar pontuação revisada por time
        const team_points = Array.from(inputs)
            .map(inp => ({
                team_id:         parseInt(inp.dataset.teamId,    10),
                points:          parseInt(inp.value,             10) || 0,
                points_regular:  parseInt(inp.dataset.ptsRegular,  10) || 0,
                points_playoffs: parseInt(inp.dataset.ptsPlayoffs, 10) || 0,
                points_prizes:   parseInt(inp.dataset.ptsPrizes,   10) || 0,
            }))
            .filter(r => r.team_id);

        if (_regPtsIsCorrection) {
            // Correção: zera os pontos/locks antigos desta temporada (revertendo o
            // ranking_points anterior de cada time) antes de salvar os novos valores,
            // pra não deixar pontuação "fantasma" de times que saíram do resultado corrigido.
            await api('history-points.php', {
                method: 'POST',
                body: JSON.stringify({ action: 'delete_season_points', season_id: seasonId, league })
            });
            _regPtsIsCorrection = false;
            _regPtsForceEdit = false;
        }

        await api('history-points.php', {
            method: 'POST',
            body: JSON.stringify({ action: 'save_season_points', season_id: seasonId, league, team_points })
        });

        // Agora sim: gravou, então o rascunho pode sair. O do servidor é
        // apagado pelo próprio register_pontuacao — e o autosave armado
        // precisa morrer junto, senão ele o ressuscitaria logo depois.
        clearTimeout(_regPtsAutosaveTimer);
        if (_regPtsCacheKey) localStorage.removeItem(_regPtsCacheKey);
        _clearFormCache(league, seasonId);
        _clearBracketCache(league, seasonId);
        _bracket = null;
        _regPtsEtapa = 'regular';

        showAlert('success', 'Pontuação salva com sucesso!');
        setTimeout(() => showHome(), 1200);
    } catch (e) {
        _regPtsPendingPayload = null;
        if (btn) { btn.disabled = false; btn.innerHTML = originalHtml; }
        if (e?.already_locked) {
            alert('Esta temporada já teve a pontuação registrada. Não é permitido registrar novamente.');
        } else {
            alert('Erro ao salvar pontuação: ' + (e?.error || 'Desconhecido'));
        }
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
                <div class="panel-title"><i class="bi bi-play-circle-fill" style="color:#f97316"></i> Iniciar Sprint — ${league}</div>
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
                            <i class="bi bi-plus-circle me-1"></i> Iniciar Sprint
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

    const startYear = resolveStartYear(season) ?? await promptStartYear(new Date().getFullYear());
    if (!startYear) return;

    const nextNum = Number(season.season_number) + 1;
    const nextYear = startYear + nextNum - 1;

    if (!await confirmarSite(`Criar Temporada ${String(nextNum).padStart(2,'0')} para a liga ${league} (ano ${nextYear})?`)) return;

    try {
        const avanco = await api('seasons.php?action=advance_season', {
            method: 'POST',
            body: JSON.stringify({ season_id: seasonId })
        });
        /* O checklist não impede mais o avanço, mas o que ficou aberto é
           dito em voz alta: a temporada some da lista logo em seguida, e
           descobrir depois que faltou algo é pior do que ser avisado. */
        if (avanco && Array.isArray(avanco.pendentes) && avanco.pendentes.length) {
            showAlert('warning', 'Temporada avançada com pendências no checklist: ' + avanco.pendentes.join(', ') + '.');
        }
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

// ========== FINALIZAR SPRINT (última temporada do ciclo) ==========
async function showFinalizarSprint(league) {
    seasonsState.currentLeague = league;
    const container = document.getElementById('mainContainer');
    container.innerHTML = '<div class="text-center py-5"><div class="spinner-border text-orange"></div></div>';

    const season = await loadCurrentSeason(league);
    if (!season) {
        showAlert('danger', 'Nenhuma temporada ativa encontrada para esta liga.');
        showLeague(league);
        return;
    }

    seasonsState.currentSeasonId = season.id;
    seasonsState._finalizingSeason = season;

    let histRegistered = false;
    try {
        const hist = await api(`seasons.php?action=check_season_history&season_id=${season.id}`);
        histRegistered = !!hist.registered;
    } catch (_) {}

    const seasonLabel = `T${season.season_number} · Sprint ${season.sprint_number || '?'} · ${season.year || ''}`;

    // Sugestão: continuar a contagem de anos da liga (que é fictícia e pode
    // estar bem à frente do ano real), mas quem decide é o admin.
    const proximoAnoSugerido = Number(season.year) ? Number(season.year) + 1 : new Date().getFullYear();

    if (!histRegistered) {
        container.innerHTML = `
            <div class="mb-3">
                <button class="btn-ghost" onclick="showLeague('${league}')"><i class="bi bi-arrow-left me-1"></i> Voltar</button>
            </div>
            <div class="panel">
                <div class="panel-title"><i class="bi bi-flag-fill" style="color:#ef4444"></i> Finalizar Sprint — ${league}</div>
                <p style="color:var(--text-2);font-size:13px;margin-bottom:12px">Última temporada do sprint: <strong style="color:var(--red)">${seasonLabel}</strong></p>
                <div style="background:rgba(245,158,11,.1);border:1px solid rgba(245,158,11,.3);border-radius:10px;padding:12px 16px;margin-bottom:16px;font-size:13px;color:#f59e0b">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    A pontuação desta temporada ainda não foi registrada. Registre os resultados antes de finalizar o sprint.
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

    container.innerHTML = `
        <div class="mb-3">
            <button class="btn-ghost" onclick="showLeague('${league}')"><i class="bi bi-arrow-left me-1"></i> Voltar</button>
        </div>
        <div class="panel" style="border-color:rgba(239,68,68,.35)">
            <div class="panel-title"><i class="bi bi-flag-fill" style="color:#ef4444"></i> Finalizar Sprint — ${league}</div>
            <p style="color:var(--text-2);font-size:13px;margin-bottom:4px">Última temporada do sprint: <strong style="color:var(--red)">${seasonLabel}</strong></p>
            <p style="color:#22c55e;font-size:13px;margin-bottom:16px">
                <i class="bi bi-check-circle-fill me-1"></i>Pontuação registrada. O sprint pode ser finalizado.
            </p>
            <div style="background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.3);border-radius:10px;padding:14px 16px;margin-bottom:16px;font-size:13px;color:var(--text)">
                <div style="font-weight:700;color:#ef4444;margin-bottom:8px"><i class="bi bi-exclamation-triangle-fill me-1"></i>Isso é irreversível. Ao finalizar:</div>
                <ul style="margin:0 0 0 18px;padding:0;color:var(--text-2);line-height:1.7">
                    <li>A classificação final desta sprint é congelada e fica salva pra consulta depois.</li>
                    <li><strong style="color:var(--text)">O elenco de todos os times da liga ${league} é apagado.</strong></li>
                    <li>Picks, trocas, propostas de Free Agency e táticas salvas da liga são removidas.</li>
                    <li>Pontos/títulos de ranking, moedas e contadores dos times zeram.</li>
                    <li>Um novo sprint começa, e você será direcionado pra configurar o novo Draft Inicial.</li>
                </ul>
            </div>
            <div style="margin-bottom:16px">
                <label style="font-size:12px;color:var(--text-2);display:block;margin-bottom:6px">Ano inicial do novo sprint</label>
                <input type="number" id="finalizarSprintStartYear" value="${proximoAnoSugerido}" min="1900" max="2200"
                       style="background:var(--panel-3);border:1px solid var(--border);border-radius:8px;padding:9px 14px;color:var(--text);font-size:15px;width:160px">
                <div style="font-size:11.5px;color:var(--text-3);margin-top:6px">
                    A temporada 1 do novo sprint começa nesse ano, e as picks são geradas a partir dele.
                </div>
            </div>
            <div style="margin-bottom:16px">
                <label style="font-size:12px;color:var(--text-2);display:block;margin-bottom:6px">Digite <strong>${league}</strong> pra confirmar</label>
                <input type="text" id="finalizarSprintConfirmInput" placeholder="${league}"
                       style="background:var(--panel-3);border:1px solid var(--border);border-radius:8px;padding:9px 14px;color:var(--text);font-size:15px;width:200px">
            </div>
            <div style="display:flex;gap:10px;flex-wrap:wrap">
                <button class="btn btn-danger" id="btnFinalizeSprintConfirm" onclick="_confirmFinalizeSprint('${league}')">
                    <i class="bi bi-flag-fill me-1"></i> Finalizar Sprint
                </button>
                <button class="btn-ghost" onclick="showLeague('${league}')">Cancelar</button>
            </div>
        </div>`;
}

async function _confirmFinalizeSprint(league) {
    const startYear = parseInt(document.getElementById('finalizarSprintStartYear')?.value, 10);
    if (!startYear || startYear < 1900 || startYear > 2200) {
        alert('Informe o ano inicial do novo sprint (entre 1900 e 2200).');
        return;
    }

    const input = document.getElementById('finalizarSprintConfirmInput');
    if (!input || input.value.trim().toUpperCase() !== league.toUpperCase()) {
        alert(`Digite "${league}" no campo pra confirmar.`);
        return;
    }
    if (!await confirmarSite(`Finalizar o sprint da liga ${league} agora?\n\nO novo sprint vai começar em ${startYear}. O elenco de todos os times será apagado. Essa ação não pode ser desfeita.`)) return;

    const btn = document.getElementById('btnFinalizeSprintConfirm');
    if (btn) { btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Finalizando...'; }

    try {
        const data = await api('seasons.php?action=finalize_sprint', {
            method: 'POST',
            body: JSON.stringify({ league, start_year: startYear })
        });
        seasonsState._finalizingSeason = null;
        showAlert('success', data.message || 'Sprint finalizado!');

        // Mesmo padrão de _submitCriarSprint: cria (ou recupera) a sessão de Draft
        // Inicial da nova temporada e leva o admin direto pra configurá-la.
        const seasonId = data.season_id;
        let token = null;
        if (seasonId) {
            try {
                const created = await api('initdraft.php', {
                    method: 'POST',
                    body: JSON.stringify({ action: 'create_session', season_id: seasonId })
                });
                token = created.token || null;
            } catch (e) {
                // Sessão pode já existir — recupera o token existente.
            }
            if (!token) {
                try {
                    const existing = await api(`initdraft.php?action=session_for_season&season_id=${seasonId}`);
                    token = existing?.session?.access_token || null;
                } catch (e) {}
            }
        }

        if (token) {
            window.location.href = 'initdraftselecao.php?token=' + encodeURIComponent(token);
        } else {
            showAlert('danger', 'Sprint finalizado, mas não consegui abrir o Draft Inicial automaticamente. Use o card "Draft Inicial" na aba da liga.');
            setTimeout(() => showLeague(league), 1000);
        }
    } catch (e) {
        if (btn) { btn.disabled = false; btn.innerHTML = '<i class="bi bi-flag-fill me-1"></i> Finalizar Sprint'; }
        // A API já manda a mensagem pronta — só prefixa quando não veio nada.
        const msg = e?.error || e?.message;
        alert(msg || 'Erro ao finalizar o sprint. Tente de novo.');
        // Se o ano bateu com uma temporada que já existe, a API sugere um livre.
        const sugerido = e?.suggested_start_year;
        const campo = document.getElementById('finalizarSprintStartYear');
        if (sugerido && campo) { campo.value = sugerido; campo.focus(); }
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

    if (_regPtsSeasonId !== season.id) {
        _regPtsForceEdit = false;
    }
    seasonsState.currentSeasonId = season.id;
    _regPtsSeasonId = season.id;
    _regPtsCacheKey = `reg_pts_v2_${league}_${season.id}`;
    // Estado da visita anterior não vale nesta: os elencos podem ter mudado
    // (trade, dispensa) e a restauração antiga já terminou faz tempo.
    _regPtsElencos = {};
    _extBuscaCache = {};
    _regPtsRestaurando = null;

    let histRegistered = false;
    try {
        const hist = await api(`seasons.php?action=check_season_history&season_id=${season.id}`);
        histRegistered = !!hist.registered;
    } catch (_) {}
    _regPtsIsCorrection = histRegistered;

    let allTeams = [];
    try {
        const teamsData = await api(`admin.php?action=teams&league=${league}`);
        allTeams = teamsData.teams || [];
        seasonsState.teamsById = Object.fromEntries(allTeams.map(t => [String(t.id), t]));
    } catch (_) {}
    _regPtsAllTeams = allTeams;

    const seasonLabel = `T${season.season_number} · Sprint ${season.sprint_number || '?'} · ${season.year || ''}`;
    const backFn = 'showHome()';

    // O rascunho do servidor é o que atravessa a troca de aparelho; o
    // localStorage é a cópia local e ganha quando existe, porque foi ele que
    // recebeu a última tecla nesta máquina. Como o autosave empurra um pro
    // outro o tempo todo, os dois convergem.
    let rascunhoServidor = null;
    try {
        rascunhoServidor = await api(`seasons.php?action=registro_rascunho&season_id=${season.id}`);
    } catch (_) {}
    _regPtsEtapa = rascunhoServidor?.etapa || 'regular';

    const cacheLocal = _regPtsLoadCache();
    const cached = cacheLocal?.form ? cacheLocal
                 : (rascunhoServidor?.dados?.form ? { form: rascunhoServidor.dados.form } : null);
    // Chaveamento também vem do rascunho quando esta máquina não tem cópia.
    // Sem nenhuma das duas, zera: _bracket é global, e o que sobrou da
    // temporada aberta antes apareceria como se fosse desta.
    if (!_restoreBracketCache(league, season.id)) {
        _bracket = rascunhoServidor?.dados?.bracket || null;
    }

    const naEtapaPlayoffs = _regPtsEtapa === 'playoffs';
    const isElite = league === 'ELITE';

    const lockedBadge = histRegistered
        ? `<span style="background:rgba(239,68,68,.12);color:#ef4444;border:1px solid rgba(239,68,68,.3);border-radius:999px;font-size:11px;font-weight:700;padding:4px 12px">
               <i class="bi bi-lock-fill me-1"></i>Já registrado
           </span>`
        : `<span style="background:${naEtapaPlayoffs ? 'rgba(34,197,94,.12);color:#22c55e;border:1px solid rgba(34,197,94,.3)' : 'rgba(245,158,11,.12);color:#f59e0b;border:1px solid rgba(245,158,11,.3)'};border-radius:999px;font-size:11px;font-weight:700;padding:4px 12px">
               <i class="bi bi-${naEtapaPlayoffs ? 'check-lg' : 'pencil'} me-1"></i>${naEtapaPlayoffs ? 'Etapa 2 · Playoffs' : 'Etapa 1 · Temporada regular'}
           </span>`;

    const selStyle = 'width:100%;background:var(--panel-3);border:1px solid var(--border);border-radius:8px;padding:8px 10px;color:var(--text);font-size:13px';
    const lblStyle = 'font-size:12px;color:var(--text-2);margin-bottom:6px;display:block';
    const playerSelStyle = 'width:100%;background:var(--panel-3);border:1px solid var(--border);border-radius:8px;padding:8px 10px;color:var(--text);font-size:13px;opacity:.6';
    const awardTeamOpts = '<option value="">Selecione...</option>' +
        allTeams.map(t => `<option value="${t.id}">${escapeHtml(t.city + ' ' + t.name)}</option>`).join('');
    const mkAwardRow = (label, teamName, playerName, nbaCup = false) => `
        <div class="col-md-6">
            <label style="${lblStyle}">${label} (Time)</label>
            <select name="${teamName}" style="${selStyle}" onchange="_loadAwardPlayers('${playerName}', this.value); _regPtsSaveCache();">${awardTeamOpts}</select>
        </div>
        <div class="col-md-6">
            <label style="${lblStyle}">${label} (Jogador)</label>
            <select name="${playerName}" style="${playerSelStyle}" onchange="_regPtsSaveCache();">
                <option value="">— Selecione o time primeiro —</option>
            </select>
        </div>`;

    // Prêmios estendidos: UM campo por vaga.
    //
    // Eram dois selects — primeiro o time, depois o elenco dele. Só que quem
    // preenche um All-NBA sabe o nome do jogador e não necessariamente onde
    // ele joga; obrigar a achar o time antes é pedir a informação na ordem
    // errada, 26 vezes. Agora digita o nome, escolhe na lista, e o time vem
    // junto — é ele que o cap precisa, e sai de graça da escolha.
    //
    // Os dois campos escondidos guardam exatamente o que a API já esperava
    // (_team e _player), então salvar, rascunho e restauração seguem iguais.
    const mkExtBloco = ({ tipo, titulo, vagas, bonus }) => `
        <div style="margin-bottom:14px">
            <div style="font-size:12.5px;font-weight:700;color:var(--text);margin-bottom:7px">
                ${titulo} <span style="color:var(--text-3);font-weight:400;font-size:11px">(${bonus})</span>
            </div>
            ${Array.from({ length: vagas }, (_, i) => `
            <div class="ext-vaga" id="ext-vaga-${tipo}-${i}">
                <input name="ext_${tipo}_${i}_busca" class="ext-busca" autocomplete="off"
                       placeholder="Digite o nome do jogador…"
                       oninput="_extBuscar('${tipo}',${i})"
                       onfocus="_extBuscar('${tipo}',${i})"
                       onkeydown="_extTecla(event,'${tipo}',${i})">
                <input type="hidden" name="ext_${tipo}_${i}_team">
                <input type="hidden" name="ext_${tipo}_${i}_player">
                <div class="ext-time" id="ext-time-${tipo}-${i}"></div>
                <div class="ext-sug" id="ext-sug-${tipo}-${i}" hidden
                     onmousedown="event.preventDefault()"></div>
            </div>`).join('')}
        </div>`;

    // O visual das vagas. Vai num <style> só porque são 26 caixas iguais —
    // repetir style="" em cada uma seria o mesmo CSS 26 vezes no HTML.
    // A borda verde e o nome do time embaixo são a confirmação de que a
    // escolha pegou: sem eles, um campo com nome digitado e sem jogador
    // selecionado parece preenchido e não é.
    const extEstilos = `
        <style>
          .ext-vaga{position:relative;margin-bottom:6px}
          .ext-busca{width:100%;background:var(--panel-3);border:1px solid var(--border);
            border-radius:8px;padding:8px 10px;color:var(--text);font-size:12.5px}
          .ext-busca:focus{outline:none;border-color:var(--red)}
          .ext-vaga.ok .ext-busca{border-color:rgba(34,197,94,.45)}
          .ext-time{font-size:11px;color:#22c55e;margin:3px 0 0 2px;display:none}
          .ext-vaga.ok .ext-time{display:block}
          .ext-sug{position:absolute;z-index:40;left:0;right:0;top:calc(100% + 3px);
            background:var(--panel);border:1px solid var(--border-md);border-radius:10px;
            max-height:230px;overflow-y:auto;box-shadow:0 12px 28px rgba(0,0,0,.45)}
          .ext-sug[hidden]{display:none}
          .ext-op{padding:8px 11px;font-size:12.5px;cursor:pointer;display:flex;
            align-items:baseline;gap:7px;border-bottom:1px solid var(--border)}
          .ext-op:last-child{border-bottom:0}
          .ext-op:hover,.ext-op.sel{background:var(--panel-3)}
          .ext-op b{font-weight:600;color:var(--text)}
          .ext-op span{font-size:11px;color:var(--text-3);margin-left:auto;white-space:nowrap}
          .ext-sug-vazio{padding:9px 11px;font-size:12px;color:var(--text-3)}
        </style>`;

    const extendedHtml = isElite ? `
            <div style="margin-top:18px;padding-top:16px;border-top:1px solid var(--border)">
                <div style="font-size:13px;font-weight:700;color:var(--text)"><i class="bi bi-star-half" style="color:#eab308"></i> 3. Prêmios estendidos <span style="font-size:11px;color:var(--text-3);font-weight:400">— só ELITE</span></div>
                <div style="font-size:12px;color:var(--text-3);margin:4px 0 12px">
                    All-NBA e All-Defensive. Cada bônus vale <b>só na temporada seguinte</b>, somando ao salário base no cap.
                    Não valem ponto de ranking — quem pontua são os prêmios individuais acima.
                    O <b>Finals MVP</b> fica na etapa 2: só dá pra saber depois da Grande Final.
                    <br>Digite o nome do jogador e escolha na lista — o time vem junto.
                </div>
                ${extEstilos}
                ${REG_PTS_EXTENDED.filter(x => x.fase === 'regular').map(mkExtBloco).join('')}
            </div>` : '';

    // O Finals MVP mora na etapa 2, ao lado do chaveamento que acabou de
    // decidir quem foi. É o mesmo tipo de prêmio estendido dos outros —
    // muda só a hora em que a resposta existe.
    //
    // Só aparece depois que a campanha foi salva, junto com o resto da etapa
    // 2: antes disso a Grande Final nem foi jogada, e um campo em branco
    // esperando resposta é convite pra alguém tentar adivinhar.
    //
    // Não repete `extEstilos`: quando este bloco existe, o da etapa 1 também
    // existe (os dois são só da ELITE) e já trouxe o <style>.
    const finalsMvpHtml = (isElite && naEtapaPlayoffs) ? `
                <div style="margin-top:18px;padding-top:16px;border-top:1px solid var(--border)">
                    <div style="font-size:13px;font-weight:700;color:var(--text)"><i class="bi bi-star-half" style="color:#eab308"></i> Finals MVP <span style="font-size:11px;color:var(--text-3);font-weight:400">— só ELITE</span></div>
                    <div style="font-size:12px;color:var(--text-3);margin:4px 0 12px">
                        Prêmio estendido, como o All-NBA: vale <b>+3M no cap da temporada seguinte</b> e não dá ponto de ranking.
                        Digite o nome do jogador e escolha na lista — o time vem junto.
                    </div>
                    ${REG_PTS_EXTENDED.filter(x => x.fase === 'playoffs').map(mkExtBloco).join('')}
                </div>` : '';

    const nbaCupHtml = isElite ? `
            <div style="margin-top:18px;padding-top:16px;border-top:1px solid var(--border)">
                <div style="font-size:13px;font-weight:700;color:var(--text)"><i class="bi bi-trophy" style="color:#f59e0b"></i> 2. NBA Cup <span style="font-size:11px;color:var(--text-3);font-weight:400">— só ELITE</span></div>
                <div style="font-size:12px;color:var(--text-3);margin:4px 0 10px">O campeão da NBA Cup leva 2 pontos.</div>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label style="${lblStyle}">Campeão da NBA Cup</label>
                        <select name="nba_cup_team_id" style="${selStyle}" onchange="_regPtsSaveCache();">${awardTeamOpts}</select>
                    </div>
                </div>
            </div>` : '';

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

        ${histRegistered && !_regPtsForceEdit ? `
        <div class="panel" style="border-color:rgba(239,68,68,.3)">
            <p style="color:#ef4444;margin:0 0 12px"><i class="bi bi-lock-fill me-2"></i>
                A pontuação desta temporada já foi registrada.
            </p>
            <button type="button" class="btn btn-orange" style="border-radius:15px" onclick="_regPtsForceEdit = true; showRegistroPontuacao('${league}');">
                <i class="bi bi-pencil-fill me-1"></i> Corrigir registro
            </button>
        </div>` : `
        ${histRegistered ? `
        <div class="panel mb-3" style="border-color:rgba(245,158,11,.4)">
            <p style="color:#f59e0b;margin:0"><i class="bi bi-exclamation-triangle-fill me-2"></i>
                Esta temporada já foi registrada antes. Ao salvar, o campeão, a classificação e os prêmios
                registrados anteriormente serão <b>sobrescritos</b> pelos novos dados preenchidos abaixo.
            </p>
        </div>` : ''}
        <div class="panel mb-3" style="border-color:rgba(59,130,246,.3)">
            <p style="color:var(--text-2);margin:0;font-size:12.5px;line-height:1.6"><i class="bi bi-info-circle me-2" style="color:#3b82f6"></i>
                O registro tem <b>dois salvamentos</b>, pra acompanhar como a temporada acontece de verdade.
                Na <b>etapa 1</b> entram os prêmios${isElite ? ', a NBA Cup, os prêmios estendidos' : ''} e a classificação —
                salvar aqui já <b>atualiza a Tabela</b>, <b>libera a loteria</b> e <b>monta o chaveamento</b>.
                Na <b>etapa 2</b>, com os playoffs decididos, o chaveamento é preenchido e o segundo salvamento
                soma tudo (seeds + prêmios + playoffs) e registra a pontuação.
                Tudo que for digitado fica guardado como rascunho, mesmo fechando a página.
            </p>
        </div>

        <form id="formRegistroPontuacao" onsubmit="saveRegistroPontuacao(event, ${season.id}, '${league}')">

            <!-- ETAPA 1 — temporada regular -->
            <div class="panel mb-3">
                <div class="panel-header" style="margin-bottom:6px">
                    <div>
                        <div class="panel-title"><i class="bi bi-1-circle-fill" style="color:#f59e0b"></i> Etapa 1 — Temporada regular</div>
                        <div class="panel-sub">Prêmios${isElite ? ', NBA Cup, prêmios estendidos' : ''} e classificação final.</div>
                    </div>
                    ${naEtapaPlayoffs ? `<span style="background:rgba(34,197,94,.12);color:#22c55e;border:1px solid rgba(34,197,94,.3);border-radius:999px;font-size:11px;font-weight:700;padding:4px 12px"><i class="bi bi-check-lg me-1"></i>Salva</span>` : ''}
                </div>

                <div style="margin-top:14px">
                    <div style="font-size:13px;font-weight:700;color:var(--text)"><i class="bi bi-trophy-fill" style="color:#f59e0b"></i> 1. Prêmios individuais</div>
                    <div style="font-size:12px;color:var(--text-3);margin:4px 0 10px">MVP, DPOY, MIP, 6º Homem e ROY valem 1 ponto cada.</div>
                    <div class="row g-3">
                        ${mkAwardRow('MVP',      'mvp_team_id',       'mvp_player_name')}
                        ${mkAwardRow('DPOY',     'dpoy_team_id',      'dpoy_player_name')}
                        ${mkAwardRow('MIP',      'mip_team_id',       'mip_player_name')}
                        ${mkAwardRow('6º Homem', 'sixth_man_team_id', 'sixth_man_player_name')}
                        ${mkAwardRow('ROY',      'roy_team_id',       'roy_player_name')}
                    </div>
                </div>

                ${nbaCupHtml}
                ${extendedHtml}

                <div style="margin-top:18px;padding-top:16px;border-top:1px solid var(--border)">
                    <div style="font-size:13px;font-weight:700;color:var(--text)"><i class="bi bi-list-ol"></i> ${isElite ? '4' : '2'}. Posições</div>
                    <div style="font-size:12px;color:var(--text-3);margin:4px 0 10px">Preencha a posição final de <b>todos</b> os times de cada conferência. Os 8 primeiros valem pontos de seed e formam o chaveamento; os demais ficam registrados no histórico de posições.</div>
                    <div id="standingsContainer">
                        <button type="button" class="btn-ghost" onclick="loadTeamsForStandings('${league}')">
                            <i class="bi bi-download me-1"></i> Carregar Times
                        </button>
                    </div>
                </div>

                <div style="margin-top:18px;padding-top:16px;border-top:1px solid var(--border);display:flex;gap:10px;flex-wrap:wrap;align-items:center">
                    <button type="button" id="btnSalvarRegular" class="btn btn-orange" style="border-radius:15px"
                            onclick="salvarTemporadaRegular(${season.id}, '${league}')">
                        <i class="bi bi-save me-1"></i> ${naEtapaPlayoffs ? 'Atualizar a temporada regular' : 'Salvar temporada regular'}
                    </button>
                    <span style="font-size:11.5px;color:var(--text-3)">Atualiza a Tabela, libera a loteria e monta o chaveamento.</span>
                </div>
            </div>

            <!-- ETAPA 2 — playoffs -->
            <div class="panel mb-3"${naEtapaPlayoffs ? '' : ' style="opacity:.55"'}>
                <div class="panel-header" style="margin-bottom:6px">
                    <div>
                        <div class="panel-title"><i class="bi bi-2-circle-fill" style="color:#f59e0b"></i> Etapa 2 — Playoffs</div>
                        <div class="panel-sub">Preencha o chaveamento conforme as séries forem decididas.</div>
                    </div>
                </div>
                <div id="playoffBracketContainer" style="margin-top:12px">
                    ${naEtapaPlayoffs ? `
                    <p style="font-size:13px;color:var(--text-3);margin-bottom:10px">Chaveamento ainda não montado. Gere a partir da classificação salva.</p>
                    <button type="button" class="btn-ghost" onclick="generateBracket('${league}')">
                        <i class="bi bi-diagram-3 me-1"></i> Gerar Chaveamento
                    </button>` : `
                    <p style="font-size:13px;color:var(--text-3);margin:0"><i class="bi bi-lock-fill me-2"></i>
                        Salve a etapa 1 primeiro — o chaveamento nasce da classificação.
                    </p>`}
                </div>
                ${finalsMvpHtml}
            </div>

            <!-- Submit -->
            <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
                <button type="submit" class="btn btn-orange" style="border-radius:15px"${naEtapaPlayoffs ? '' : ' disabled'}>
                    <i class="bi bi-check2-circle me-1"></i> Registrar pontuação final
                </button>
                <button type="button" class="btn-ghost" onclick="${backFn}">Cancelar</button>
                ${naEtapaPlayoffs
                    ? `<span style="font-size:11.5px;color:var(--text-3)">Soma seeds + prêmios + playoffs e fecha a temporada.</span>`
                    : `<span style="font-size:11.5px;color:var(--text-3)">Disponível depois de salvar a etapa 1.</span>`}
            </div>
        </form>`}
    `;

    // A classificação precisa dos times na tela ANTES do rascunho voltar —
    // restaurar uma posição num <select> que ainda não tem opção não guarda
    // nada. Por isso os times carregam sozinhos aqui, e o restante do
    // rascunho só entra depois.
    if (document.getElementById('standingsContainer')) {
        await loadTeamsForStandings(league);
    }

    // Restore award/player-name fields from form cache
    if (cached?.form) {
        const form = document.getElementById('formRegistroPontuacao');
        if (form) {
            // Primeiro os campos que já têm as opções na tela: times e
            // posições. Os selects de JOGADOR ficam de fora desta passada —
            // eles nascem vazios e só podem receber valor depois que o
            // elenco do time chegar, o que acontece logo abaixo.
            const ehSelectDeJogador = n => n.endsWith('_player_name') || n.endsWith('_player');
            Object.entries(cached.form).forEach(([name, value]) => {
                const el = form.querySelector(`[name="${name}"]`);
                if (!el || value === undefined || value === null) return;
                if (el.tagName === 'SELECT' && ehSelectDeJogador(name)) return;
                // Caixa marcada se restaura pelo estado; atribuir `value`
                // numa checkbox não marca nem desmarca coisa nenhuma.
                if (el.type === 'checkbox' || el.type === 'radio') { el.checked = !!value; return; }
                el.value = value;
            });
            // Posição já preenchida some das outras vagas, como no preenchimento normal.
            ['leste', 'oeste'].forEach(_updateStandingsUnique);

            /* A ORDEM GERAL vem DEPOIS das conferências, e é por isso que ela
               é remontada aqui: quando loadTeamsForStandings roda, os selects
               de conferência ainda estão vazios, e a lista nasceria vazia.

               O que o admin já tinha ajustado é lido do próprio rascunho
               (geral_rank_*) e tem prioridade sobre o palpite automático —
               reabrir a tela não pode desfazer uma ordem corrigida na mão. */
            window._ordemGeralSalva = Object.keys(cached.form)
                .filter(n => n.startsWith('geral_rank_'))
                .sort((a, b) => parseInt(a.split('geral_rank_')[1], 10) - parseInt(b.split('geral_rank_')[1], 10))
                .map(n => cached.form[n])
                .filter(Boolean);
            /* As marcações voltam indexadas pelo TIME, não pela linha: o
               rascunho guarda geral_7x8_N ao lado de geral_rank_N, e é o time
               daquela linha que carrega a marcação. */
            window._gruposLoteriaSalvos = {};
            Object.keys(cached.form)
                .filter(n => n.startsWith('geral_7x8_'))
                .forEach(n => {
                    const time = cached.form['geral_rank_' + n.split('geral_7x8_')[1]];
                    if (time && cached.form[n]) window._gruposLoteriaSalvos[String(time)] = '4';
                });
            montarOrdemGeral();

            // Prêmios estendidos: os campos escondidos já voltaram na passada
            // acima (são <input>), então aqui só falta redesenhar o visual da
            // escolha — o nome do time embaixo e a borda verde. Nada de rede:
            // o time saiu do id que já estava guardado.
            const pendentes = [];
            REG_PTS_EXTENDED.forEach(({ tipo, vagas }) => {
                for (let i = 0; i < vagas; i++) {
                    const timeId = form.querySelector(`[name="ext_${tipo}_${i}_team"]`)?.value;
                    const nome   = form.querySelector(`[name="ext_${tipo}_${i}_player"]`)?.value;
                    if (!timeId || !nome) continue;
                    const t = (seasonsState.teamsById || {})[String(timeId)];
                    const rotulo = document.getElementById(`ext-time-${tipo}-${i}`);
                    const caixa  = document.getElementById(`ext-vaga-${tipo}-${i}`);
                    if (rotulo) {
                        rotulo.innerHTML = `<i class="bi bi-check-circle-fill"></i> ` +
                            escapeHtml(t ? `${t.city} ${t.name}` : `Time #${timeId}`);
                    }
                    if (caixa) caixa.classList.add('ok');
                }
            });

            // Para cada prêmio com time selecionado, carrega jogadores e restaura o nome
            const awardNames = ['mvp','dpoy','mip','sixth_man','roy'];
            awardNames.forEach(a => {
                const teamSel = form.querySelector(`[name="${a}_team_id"]`);
                if (!teamSel?.value) return;
                pendentes.push((async () => {
                    await _loadAwardPlayers(`${a}_player_name`, teamSel.value);
                    const playerSel = form.querySelector(`[name="${a}_player_name"]`);
                    const cachedPlayer = cached.form[`${a}_player_name`];
                    if (playerSel && cachedPlayer) playerSel.value = cachedPlayer;
                })());
            });

            // Guardado pra que salvar não corra na frente da restauração:
            // um select de jogador ainda vazio seria lido como vaga em branco,
            // e o prêmio sumiria sem ninguém notar.
            _regPtsRestaurando = Promise.all(pendentes).catch(() => {});
        }
    }

    // Attach save-on-change for awards inputs
    document.getElementById('formRegistroPontuacao')
        ?.addEventListener('change', _regPtsSaveCache);

    // O chaveamento já foi recuperado lá em cima (cache local ou rascunho do
    // servidor); aqui é só desenhar o que houver.
    if (_bracket) _renderBracket(league);

    // Se esta máquina tem chaveamento e o servidor não, manda pra lá.
    //
    // É o conserto de quem já passou pelo defeito antigo: o rascunho subiu
    // sem chaveamento e ficou assim, o que deixava o /playoffs do bot cego.
    // Abrir a tela uma vez basta pra alinhar os dois — ninguém precisa
    // refazer o preenchimento.
    if (_bracket && !rascunhoServidor?.dados?.bracket) _regPtsAutosaveServidor();
}

/**
 * ETAPA 1 — salva a campanha e o que a acompanha.
 *
 * É o salvamento do "dia da temporada regular": grava a classificação, que é
 * de onde saem a página Tabela e a loteria do draft, guarda os prêmios
 * estendidos da ELITE e monta o chaveamento pro dia dos playoffs. Os pontos
 * de playoff e de prêmio individual NÃO entram aqui — eles só valem no
 * registro final, senão uma temporada salva pela metade já mexeria no ranking.
 */
async function salvarTemporadaRegular(seasonId, league) {
    const form = document.getElementById('formRegistroPontuacao');
    if (!form) return;

    // Se o rascunho ainda está voltando pra tela, espera: ler agora pegaria
    // os selects de jogador antes de eles terem sido remarcados.
    if (_regPtsRestaurando) await _regPtsRestaurando;

    const getRankList = (conf) => Array.from(form.querySelectorAll(`[name^="${conf}_rank_"]`))
        .sort((a, b) => parseInt(a.name.split('_rank_')[1], 10) - parseInt(b.name.split('_rank_')[1], 10))
        .map(s => s.value || null)
        .filter(Boolean);

    const leste = getRankList('leste'), oeste = getRankList('oeste');

    // A checagem é sobre as OITO PRIMEIRAS VAGAS, não sobre "oito preenchidas
    // em qualquer lugar". É delas que o chaveamento é montado, slot a slot.
    // Contando só o total, quem deixasse a 8ª em branco e preenchesse a 9ª
    // passava por aqui e travava no generateBracket — que reclamava e devolvia
    // o chaveamento vazio, enquanto o salvamento seguia adiante.
    const oitoPrimeiras = (conf) => {
        let n = 0;
        for (let i = 1; i <= 8; i++) {
            if (form.querySelector(`[name="${conf}_rank_${i}"]`)?.value) n++;
        }
        return n;
    };
    const nL = oitoPrimeiras('leste'), nO = oitoPrimeiras('oeste');
    if (nL < 8 || nO < 8) {
        showAlert('warning', `Preencha as 8 primeiras posições de cada conferência — é delas que sai o chaveamento. (Leste: ${nL}/8 · Oeste: ${nO}/8)`);
        return;
    }

    // O CHAVEAMENTO NASCE ANTES DO ENVIO, e isto não é detalhe de ordem.
    //
    // Ele era gerado depois da resposta da API, então o rascunho subia com
    // `bracket: null` na primeira vez. O autosave que consertaria isso é
    // adiado 1,5s e cai bem no meio do re-render da tela — sem formulário no
    // DOM, ele desiste. Resultado: o servidor ficava com o rascunho SEM
    // chaveamento pra sempre, e o /playoffs do bot, que lê justamente de lá,
    // respondia "não tem playoffs ativo" mesmo com a campanha salva.
    //
    // Gerando antes, o chaveamento viaja junto do próprio salvamento e não
    // depende de nenhuma gravação posterior dar certo. Um que já exista é
    // mantido: refazer apagaria séries de playoff já preenchidas.
    const jaTemBracket = !!_bracket;
    if (!jaTemBracket) generateBracket(league);

    // Só agora o autosave pendente é desarmado — e vem DEPOIS do
    // generateBracket de propósito, porque ele próprio agenda um. O rascunho
    // inteiro vai junto do salvamento abaixo; deixar o temporizador armado
    // só custaria uma segunda gravação, atrasada, do mesmo conteúdo.
    clearTimeout(_regPtsAutosaveTimer);

    const btn = document.getElementById('btnSalvarRegular');
    const htmlOriginal = btn ? btn.innerHTML : '';
    if (btn) { btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Salvando...'; }

    try {
        const r = await api('seasons.php?action=save_temporada_regular', {
            method: 'POST',
            body: JSON.stringify({
                season_id: seasonId,
                standings_leste: leste,
                standings_oeste: oeste,
                // A ordem geral dos que ficaram fora (17º em diante). É o que
                // a loteria usa pra montar os grupos de bolinhas — sem ela, o
                // 15º de um lado e o 15º do outro ficam empatados e o
                // desempate acaba saindo da ordem de uma consulta.
                ordem_geral: getRankList('geral'),
                // Os dois times que perderam o 7x8. É o único grupo que não
                // se deduz de posição nenhuma; o resto a loteria monta.
                perdedores_7x8: _coletarPerdedores7x8(),
                // Só os estendidos DESTA etapa — o Finals MVP é da etapa 2 e
                // não pode ser apagado por um salvamento de campanha.
                extended_awards: league === 'ELITE' ? _regPtsCollectExtended('regular') : [],
                extended_tipos:  league === 'ELITE' ? REG_PTS_EXT_TIPOS('regular') : [],
                dados: _regPtsRascunhoAtual(),
            })
        });

        _regPtsEtapa = 'playoffs';
        if (btn) { btn.disabled = false; btn.innerHTML = '<i class="bi bi-save me-1"></i> Atualizar a temporada regular'; }

        // "Atualizada" e não "salva de novo": o segundo clique reescreve a
        // mesma classificação, não cria uma segunda. Quem lê precisa saber
        // que não ficou nada duplicado atrás.
        showAlert('success', r?.correcao
            ? 'Classificação atualizada — ela foi reescrita no lugar, não duplicada. A Tabela e a loteria já leem os novos números.'
              + (jaTemBracket ? ' O chaveamento preenchido foi mantido; use "Regerar chaveamento" se as posições mudaram.' : '')
            : (jaTemBracket
                ? 'Temporada regular salva. A Tabela e a loteria já enxergam esta classificação, e o chaveamento preenchido foi mantido.'
                : 'Temporada regular salva. A Tabela e a loteria já enxergam esta classificação, e o chaveamento está montado abaixo.'));

        // Reabre a tela pra a etapa 2 destravar (o botão final e o painel
        // saem do estado bloqueado). O rascunho acabou de ir pro servidor,
        // então nada do que estava digitado se perde no caminho.
        setTimeout(() => showRegistroPontuacao(league), 900);
    } catch (e) {
        if (btn) { btn.disabled = false; btn.innerHTML = htmlOriginal; }
        showAlert('danger', 'Erro ao salvar a temporada regular: ' + (e?.error || e?.message || 'Desconhecido'));
    }
}

async function saveRegistroPontuacao(event, seasonId, league) {
    event.preventDefault();
    const form = event.target;

    // Mesmo motivo do salvamento da etapa 1: não ler o formulário no meio
    // da restauração do rascunho.
    if (_regPtsRestaurando) await _regPtsRestaurando;

    const playoff = _collectBracketPayload();
    if (!playoff) {
        alert('Complete o chaveamento dos playoffs: selecione o campeão da Grande Final antes de salvar.');
        return;
    }

    const getRankList = (conf) => Array.from(form.querySelectorAll(`[name^="${conf}_rank_"]`))
        .sort((a, b) => parseInt(a.name.split('_rank_')[1], 10) - parseInt(b.name.split('_rank_')[1], 10))
        .map(s => s.value || null)
        .filter(Boolean);

    const fv = name => form.querySelector(`[name="${name}"]`)?.value || null;

    const payload = {
        season_id: seasonId,
        champion: playoff.champion,
        runner_up: playoff.runner_up,
        first_round_losses: playoff.first_round_losses,
        second_round_losses: playoff.second_round_losses,
        conference_final_losses: playoff.conference_final_losses,
        series: playoff.series,
        standings_leste: getRankList('leste'),
        standings_oeste: getRankList('oeste'),
        mvp: fv('mvp_player_name'),
        mvp_team_id: fv('mvp_team_id'),
        dpoy: fv('dpoy_player_name'),
        dpoy_team_id: fv('dpoy_team_id'),
        mip: fv('mip_player_name'),
        mip_team_id: fv('mip_team_id'),
        sixth_man: fv('sixth_man_player_name'),
        sixth_man_team_id: fv('sixth_man_team_id'),
        roy: fv('roy_player_name'),
        roy_team_id: fv('roy_team_id'),
        nba_cup_team_id: fv('nba_cup_team_id'),
        // Aqui vão TODOS os estendidos: o Finals MVP, que é desta etapa, mais
        // os da etapa 1 — se alguém corrigiu um All-NBA aqui e não voltou a
        // salvar a campanha, a correção iria pro lixo em silêncio.
        extended_awards: league === 'ELITE' ? _regPtsCollectExtended() : []
    };

    // Guardar payload para ser enviado ao confirmar na revisão.
    // Os rascunhos NÃO são limpos aqui: a revisão ainda pode ser cancelada, e
    // apagar antes de gravar custava o preenchimento inteiro de quem voltou.
    // Quem limpa é _saveReviewedPoints(), depois do salvamento dar certo.
    _regPtsPendingPayload = payload;
    _showReviewPanel(seasonId, league, payload);
}

async function saveAndAdvanceSeason(event, seasonId, league) {
    event.preventDefault();
    const form = event.target;

    const playoff = _collectBracketPayload();
    if (!playoff) {
        alert('Complete o chaveamento dos playoffs: selecione o campeão da Grande Final antes de salvar.');
        return;
    }

    const getRankList = (conf) => Array.from(form.querySelectorAll(`[name^="${conf}_rank_"]`))
        .sort((a, b) => parseInt(a.name.split('_rank_')[1], 10) - parseInt(b.name.split('_rank_')[1], 10))
        .map(s => s.value || null)
        .filter(Boolean);

    const payload = {
        season_id: seasonId,
        champion: playoff.champion,
        runner_up: playoff.runner_up,
        first_round_losses: playoff.first_round_losses,
        second_round_losses: playoff.second_round_losses,
        conference_final_losses: playoff.conference_final_losses,
        series: playoff.series,
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
            // new_sprint: este é o "Iniciar Sprint" — pede sprint nova, não mais
            // uma temporada dentro da que já existe.
            body: JSON.stringify({ league, season_year: startYear, start_year: startYear, new_sprint: true })
        });
        showAlert('success', data.message || 'Sprint criado com sucesso!');

        // Após criar o sprint, cria (ou recupera) a sessão de Draft Inicial (initdraft)
        // da nova temporada e leva o admin direto para a página de configuração dela.
        const seasonId = data.season_id;
        let token = null;
        if (seasonId) {
            try {
                const created = await api('initdraft.php', {
                    method: 'POST',
                    body: JSON.stringify({ action: 'create_session', season_id: seasonId })
                });
                token = created.token || null;
            } catch (e) {
                // Sessão pode já existir para esta temporada — recupera o token existente.
            }
            if (!token) {
                try {
                    const existing = await api(`initdraft.php?action=session_for_season&season_id=${seasonId}`);
                    token = existing?.session?.access_token || null;
                } catch (e) {}
            }
        }

        if (token) {
            window.location.href = 'initdraftselecao.php?token=' + encodeURIComponent(token);
        } else {
            showAlert('danger', 'Sprint criado, mas não consegui abrir o Draft Inicial automaticamente. Use o card "Draft Inicial" na aba da liga.');
            setTimeout(() => showLeague(league), 1000);
        }
    } catch (e) {
        btn.disabled = false;
        btn.innerHTML = orig;
        alert('Erro ao criar sprint: ' + (e?.error || 'Desconhecido'));
    }
}

async function _doCreateNewSeason(league) {
    const currentSeason = await loadCurrentSeason(league);
    const startYear = resolveStartYear(currentSeason) ?? await promptStartYear(new Date().getFullYear());
    if (!startYear) return;

    const nextSeasonNumber = Number(currentSeason?.season_number || 0) + 1;
    const seasonYear = startYear + nextSeasonNumber - 1;

    if (!await confirmarSite(`Criar temporada ${String(nextSeasonNumber).padStart(2, '0')} para a liga ${league} (ano ${seasonYear})?`)) {
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

        <!-- No fim, e não entre os atalhos da liga: deixou de ser rotina desde
             que o ano do draft aberto parou de sair da janela de picks. Fica
             como a saída manual pra quando alguma pick desandar com o draft já
             rolando — reaplicar a ordem da loteria reembaralharia tudo. -->
        <div class="mt-5 pt-4 border-top border-secondary">
            <div class="d-flex flex-wrap align-items-center gap-3">
                <button class="btn btn-outline-info" onclick="ajustarPicksDaLiga('${league}')" style="border-radius: 12px;">
                    <i class="bi bi-calendar2-plus me-1"></i>Ajustar Picks
                </button>
                <small class="text-light-gray mb-0" style="max-width: 46ch;">
                    Devolve as escolhas que faltam — as do draft em andamento e as dos anos futuros.
                    Picks já negociadas não são tocadas.
                </small>
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

/**
 * A ORDEM GERAL dos que ficaram fora dos playoffs — o 17º em diante.
 *
 * NASCE VAZIA, de propósito. A primeira versão vinha preenchida pelas
 * conferências, mas o palpite não tem como acertar o que importa: dentro do
 * mesmo degrau — o 15º de um lado e o 15º do outro — não existe informação
 * pra dizer quem foi pior. Uma lista já montada convida a aceitar sem olhar,
 * e é justamente essa ordem que decide os grupos de bolinhas da loteria.
 * Vazia, cada posição é uma escolha de quem viu a temporada acontecer.
 *
 * As opções são só quem ficou FORA: os times da liga que não ocupam as oito
 * primeiras vagas de nenhuma conferência. Assim não dá pra colocar por
 * engano um time que se classificou.
 */
/**
 * Os times marcados como derrotados no 7x8.
 *
 * Devolve IDS, não um mapa de grupo por time: este card só sabe sobre o 7x8,
 * e mandar um mapa completo faria ele apagar sem querer uma marcação de
 * "caiu no play-in" feita na tela da loteria.
 *
 * A chave é o TIME e não a linha — mover um time de posição não muda o que
 * aconteceu com ele naquele jogo.
 */
function _coletarPerdedores7x8() {
    const ids = [];
    document.querySelectorAll('select[name^="geral_rank_"]').forEach(sel => {
        if (!sel.value) return;
        const n = sel.name.split('geral_rank_')[1];
        const chk = document.querySelector(`input[name="geral_7x8_${n}"]`);
        if (chk && chk.checked) ids.push(parseInt(sel.value, 10));
    });
    return ids;
}

function montarOrdemGeral() {
    const wrap  = document.getElementById('ordemGeralWrap');
    const slots = document.getElementById('ordemGeralSlots');
    if (!wrap || !slots) return;

    const tById = seasonsState.teamsById || {};
    const todos = Object.keys(tById);
    if (todos.length < 4) { wrap.style.display = 'none'; slots.innerHTML = ''; return; }

    // Quem está entre os 8 primeiros de alguma conferência já se classificou.
    const classificados = new Set();
    ['leste', 'oeste'].forEach(conf => {
        Array.from(document.querySelectorAll(`select[name^="${conf}_rank_"]`))
            .sort((a, b) => parseInt(a.name.split('_rank_')[1], 10) - parseInt(b.name.split('_rank_')[1], 10))
            .forEach((s, i) => { if (i < 8 && s.value) classificados.add(String(s.value)); });
    });

    const fora = todos.filter(id => !classificados.has(String(id)));
    // Enquanto as oito vagas não estiverem preenchidas nos dois lados, "quem
    // ficou de fora" ainda é a liga inteira — mostrar a lista aí seria pedir
    // uma ordem que ninguém tem como dar.
    if (classificados.size < 16 || fora.length < 2) {
        wrap.style.display = 'none';
        slots.innerHTML = '';
        return;
    }
    wrap.style.display = '';

    // A numeração continua de onde os classificados param.
    const primeiro = classificados.size + 1;

    // O que já foi preenchido (salvamento ou rascunho) volta como estava —
    // reabrir a tela não pode apagar o trabalho de quem já ordenou.
    const guardada = (window._ordemGeralSalva || []).map(String).filter(id => fora.includes(id));

    const opts = (sel) => '<option value="">—</option>' + fora.map(id => {
        const t = tById[String(id)] || {};
        return `<option value="${id}"${String(id) === String(sel) ? ' selected' : ''}>${(t.city || '') + ' ' + (t.name || id)}</option>`;
    }).join('');

    /* QUEM PERDEU O 7x8, marcado à mão.
       É o único grupo que não se deduz de jeito nenhum: quem caiu no play-in
       ainda dá pra chutar pela colocação (9º e 10º), mas ter perdido aquele
       jogo é resultado, não posição. Enquanto a loteria adivinhava — pegando
       "os 2 menos ruins que sobraram" — dois times que sequer foram ao
       play-in levavam esse rótulo, e com ele a menor chance da urna.

       Só o que for marcado aqui vira declaração; o resto a loteria continua
       montando sozinha a partir da ordem. */
    const guardadoGrupo = window._gruposLoteriaSalvos || {};

    slots.innerHTML = fora.map((_, i) => {
        const timeDaLinha = guardada[i] || '';
        const marcado = String(guardadoGrupo[String(timeDaLinha)] || '') === '4';
        return `
        <div class="d-flex align-items-center gap-2 mb-2">
            <span class="fw-bold" style="width:34px;text-align:right;color:var(--text-3)">${primeiro + i}°</span>
            <select class="form-select form-select-sm bg-dark text-white border-orange"
                    name="geral_rank_${primeiro + i}" style="border-radius:10px;flex:1 1 auto;min-width:0"
                    onchange="_updateStandingsUnique('geral'); _regPtsSaveCache();">${opts(timeDaLinha)}</select>
            <label class="d-flex align-items-center gap-1 mb-0 text-nowrap" style="flex:0 0 auto;cursor:pointer;font-size:12px;color:var(--text-3)"
                   title="Marque os dois times que perderam o jogo 7x8 — eles entram na loteria com 1 bolinha, a menor chance">
                <input type="checkbox" class="form-check-input mt-0" name="geral_7x8_${primeiro + i}"
                       ${marcado ? 'checked' : ''} onchange="_regPtsSaveCache();">
                Perdeu o 7x8
            </label>
        </div>`;
    }).join('');
    _updateStandingsUnique('geral');
}

function _updateStandingsUnique(conf) {
    const slots = Array.from(document.querySelectorAll(`select[name^="${conf}_rank_"]`));
    const selected = new Set(slots.map(s => s.value).filter(v => v));
    slots.forEach(slot => {
        const myVal = slot.value;
        slot.querySelectorAll('option').forEach(opt => {
            if (!opt.value) return;
            // Time já colocado noutra vaga some da lista em vez de ficar
            // cinza: numa liga de 15 times, rolar por uma lista cheia de
            // opções mortas pra achar as 3 que sobraram é trabalho à toa.
            //
            // O disabled continua junto de propósito — é o que garante que
            // ninguém selecione duas vezes mesmo num navegador que ignore o
            // hidden em <option>.
            const jaUsado = selected.has(opt.value) && opt.value !== myVal;
            opt.disabled = jaUsado;
            opt.hidden = jaUsado;
        });
    });
}

/**
 * O elenco de um time, buscado uma vez só.
 *
 * Os prêmios estendidos são 26 vagas, e várias caem no mesmo time — o
 * All-NBA de um campeão costuma levar dois ou três da mesma casa. Sem o
 * cache, restaurar um rascunho cheio viraria 26 idas ao servidor pra
 * buscar meia dúzia de elencos repetidos.
 *
 * Guarda a promessa, não o resultado: dois selects pedindo o mesmo time no
 * mesmo instante compartilham a mesma requisição em vez de disparar duas.
 */
let _regPtsElencos = {};
function _regPtsElenco(teamId) {
    const k = String(teamId);
    if (!_regPtsElencos[k]) {
        _regPtsElencos[k] = api(`admin.php?action=team_details&team_id=${k}`)
            .then(data => ((data.team || data).players || []).sort((a, b) => (b.ovr || 0) - (a.ovr || 0)))
            .catch(e => { delete _regPtsElencos[k]; throw e; });
    }
    return _regPtsElencos[k];
}

// ─── Busca de jogador nos prêmios estendidos ────────────────────────────────
//
// Um campo por vaga: digita o nome, escolhe na lista, e o time vem junto.
// A busca é do servidor (api admin.php?action=search_players) porque ela já
// existe, já é por liga e já usa o índice — o cache aqui é só pra não repetir
// a mesma pergunta enquanto a pessoa corrige uma letra.
let _extBuscaTimer = null;
let _extBuscaCache = {};
let _extSelIdx = {};   // 'tipo:i' => índice destacado na lista, pras setas

/** A vaga inteira, pelos ids que mkExtBloco montou. */
function _extVaga(tipo, i) {
    const form = document.getElementById('formRegistroPontuacao');
    if (!form) return null;
    return {
        caixa:  document.getElementById(`ext-vaga-${tipo}-${i}`),
        sug:    document.getElementById(`ext-sug-${tipo}-${i}`),
        rotulo: document.getElementById(`ext-time-${tipo}-${i}`),
        busca:  form.querySelector(`[name="ext_${tipo}_${i}_busca"]`),
        time:   form.querySelector(`[name="ext_${tipo}_${i}_team"]`),
        jog:    form.querySelector(`[name="ext_${tipo}_${i}_player"]`),
    };
}

async function _extBuscar(tipo, i) {
    const v = _extVaga(tipo, i);
    if (!v || !v.busca) return;
    const termo = v.busca.value.trim();

    // Mexeu no texto depois de escolher: a escolha não vale mais. Sem isto o
    // campo mostraria um nome e gravaria outro, que é o pior dos dois mundos.
    if (v.jog.value && termo !== v.jog.value) _extLimpar(tipo, i, false);

    if (termo.length < 2) { _extFecha(tipo, i); return; }

    clearTimeout(_extBuscaTimer);
    _extBuscaTimer = setTimeout(async () => {
        const chave = `${_regPtsLeague}|${termo.toLowerCase()}`;
        try {
            if (!_extBuscaCache[chave]) {
                _extBuscaCache[chave] = api(
                    `admin.php?action=search_players&league=${encodeURIComponent(_regPtsLeague)}&query=${encodeURIComponent(termo)}`
                ).then(d => d.players || []).catch(e => { delete _extBuscaCache[chave]; throw e; });
            }
            const jogadores = await _extBuscaCache[chave];
            // A pessoa continuou digitando enquanto isto voltava: a resposta
            // é de outra pergunta e não pode sobrescrever a lista atual.
            if (v.busca.value.trim() !== termo) return;
            _extDesenhaSugestoes(tipo, i, jogadores);
        } catch (e) {
            _extDesenhaSugestoes(tipo, i, null);
        }
    }, 250);
}

function _extDesenhaSugestoes(tipo, i, jogadores) {
    const v = _extVaga(tipo, i);
    if (!v || !v.sug) return;
    _extSelIdx[`${tipo}:${i}`] = -1;

    if (jogadores === null) {
        v.sug.innerHTML = '<div class="ext-sug-vazio">Não deu pra buscar agora.</div>';
        v.sug.hidden = false;
        return;
    }
    if (!jogadores.length) {
        v.sug.innerHTML = '<div class="ext-sug-vazio">Nenhum jogador com esse nome nesta liga.</div>';
        v.sug.hidden = false;
        return;
    }

    v.sug.innerHTML = jogadores.map((p, n) => {
        const time = [p.team_city, p.team_name].filter(Boolean).join(' ');
        // O apóstrofo no nome quebraria o onclick — daí o JSON no atributo.
        const arg = escapeHtml(JSON.stringify([tipo, i, p.name || '', String(p.team_id || ''), time]));
        return `<div class="ext-op" data-n="${n}" onclick='_extEscolher(...JSON.parse(this.dataset.arg))' data-arg="${arg}">
                  <b>${escapeHtml(p.name || '')}</b>
                  <span>${escapeHtml(time)} · ${p.position || ''} ${p.ovr || '—'}</span>
                </div>`;
    }).join('');
    v.sug.hidden = false;
}

// Clique fora fecha a lista aberta. Um só pra tela inteira — 26 escutas
// fazendo a mesma coisa seria só peso. O `mousedown` da própria lista
// segura o foco, então clicar numa opção não passa por aqui.
document.addEventListener('mousedown', (ev) => {
    document.querySelectorAll('.ext-sug:not([hidden])').forEach(s => {
        if (!s.closest('.ext-vaga')?.contains(ev.target)) { s.hidden = true; s.innerHTML = ''; }
    });
});

/** Fixa a escolha: nome no campo, time e nome nos escondidos, lista fechada. */
function _extEscolher(tipo, i, nome, timeId, timeNome) {
    const v = _extVaga(tipo, i);
    if (!v) return;
    v.busca.value = nome;
    v.jog.value = nome;
    v.time.value = timeId;
    if (v.rotulo) v.rotulo.innerHTML = `<i class="bi bi-check-circle-fill"></i> ${escapeHtml(timeNome || '')}`;
    if (v.caixa) v.caixa.classList.add('ok');
    _extFecha(tipo, i);
    _regPtsSaveCache();
}

/** Desfaz a escolha. `limparTexto` só quando o campo inteiro é zerado. */
function _extLimpar(tipo, i, limparTexto) {
    const v = _extVaga(tipo, i);
    if (!v) return;
    v.jog.value = '';
    v.time.value = '';
    if (limparTexto) v.busca.value = '';
    if (v.rotulo) v.rotulo.innerHTML = '';
    if (v.caixa) v.caixa.classList.remove('ok');
    _regPtsSaveCache();
}

function _extFecha(tipo, i) {
    const v = _extVaga(tipo, i);
    if (v && v.sug) { v.sug.hidden = true; v.sug.innerHTML = ''; }
}

/** Setas pra andar na lista, Enter pra escolher, Esc pra fechar. */
function _extTecla(ev, tipo, i) {
    const v = _extVaga(tipo, i);
    if (!v || !v.sug || v.sug.hidden) return;
    const ops = Array.from(v.sug.querySelectorAll('.ext-op'));
    if (!ops.length) return;
    const k = `${tipo}:${i}`;
    let idx = _extSelIdx[k] ?? -1;

    if (ev.key === 'ArrowDown' || ev.key === 'ArrowUp') {
        ev.preventDefault();
        idx = ev.key === 'ArrowDown'
            ? Math.min(idx + 1, ops.length - 1)
            : Math.max(idx - 1, 0);
        _extSelIdx[k] = idx;
        ops.forEach((o, n) => o.classList.toggle('sel', n === idx));
        ops[idx].scrollIntoView({ block: 'nearest' });
    } else if (ev.key === 'Enter') {
        // Só intercepta se houver uma opção destacada — senão o Enter volta a
        // ser o do formulário, e o registro não pode ser enviado sem querer.
        if (idx >= 0) { ev.preventDefault(); ops[idx].click(); }
    } else if (ev.key === 'Escape') {
        _extFecha(tipo, i);
    }
}

/** As <option> de jogador de um elenco, com posição e OVR pra desempatar homônimo. */
function _regPtsOpcoesJogador(players) {
    return players.map(p =>
        `<option value="${escapeHtml(p.name || '')}">${escapeHtml(p.name || '')} · ${p.position || ''} · ${p.ovr || '—'} OVR</option>`
    ).join('');
}

async function _loadAwardPlayers(playerSelectName, teamId) {
    const sel = document.querySelector(`[name="${playerSelectName}"]`);
    if (!sel) return;
    if (!teamId) {
        sel.innerHTML = '<option value="">— Selecione o time primeiro —</option>';
        sel.style.opacity = '.6';
        return;
    }
    sel.innerHTML = '<option value="">Carregando...</option>';
    sel.style.opacity = '.6';
    try {
        const players = await _regPtsElenco(teamId);
        sel.innerHTML = '<option value="">— Selecione o jogador —</option>' + _regPtsOpcoesJogador(players);
        sel.style.opacity = '1';
    } catch(e) {
        sel.innerHTML = '<option value="">Erro ao carregar jogadores</option>';
    }
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
            const slotCount = Math.max(8, confTeams.length);
            return Array.from({length: slotCount}, (_, i) => {
                const rank = i + 1;
                const isSeedCut = rank === 8 && slotCount > 8;
                const outOfPlayoffs = rank > 8;
                return `
                <div class="d-flex align-items-center gap-2 mb-2">
                    <span class="fw-bold" style="width:28px;text-align:right;color:${outOfPlayoffs ? 'var(--text-3)' : 'var(--text-2)'}">${rank}°</span>
                    <select class="form-select form-select-sm bg-dark text-white border-orange"
                            name="${conf}_rank_${rank}" style="border-radius:10px;${outOfPlayoffs ? 'opacity:.85' : ''}"
                            onchange="_updateStandingsUnique('${conf}'); montarOrdemGeral(); _regPtsSaveCache();">${opts}</select>
                </div>
                ${isSeedCut ? `<div style="display:flex;align-items:center;gap:8px;margin:4px 0 10px;padding-left:36px"><div style="flex:1;height:1px;background:var(--border-red)"></div><span style="font-size:10px;font-weight:700;letter-spacing:.5px;text-transform:uppercase;color:var(--red)">Linha do Playoff</span><div style="flex:1;height:1px;background:var(--border)"></div></div>` : ''}`;
            }).join('');
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
            </div>
            <div id="ordemGeralWrap" class="mt-4" style="display:none">
                <h6 class="text-orange mb-1"><i class="bi bi-list-ol me-1"></i>Ordem geral de quem ficou fora</h6>
                <div class="small text-secondary mb-2">
                    É daqui que a <b>loteria</b> monta os grupos de bolinhas. A colocação de
                    conferência não separa quem terminou no mesmo degrau nos dois lados —
                    esta lista separa. Preencha do <b>melhor pro pior</b>: o primeiro é quem
                    chegou mais perto do playoff, e o último é o pior da liga.
                </div>
                <div id="ordemGeralSlots"></div>
            </div>`;
        montarOrdemGeral();

        // Popular selects de premiações
        const selects = document.querySelectorAll('select[name$="_team_id"]');
        selects.forEach(select => {
            select.innerHTML = '<option value="">Selecione...</option>' +
                teams.map(t => `<option value="${t.id}">${t.city} ${t.name}</option>`).join('');
        });
        // Restaurar valores selecionados antes do rebuild
        const regCache = _regPtsLoadCache();
        if (regCache?.form) {
            selects.forEach(select => {
                const cached = regCache.form[select.name];
                if (cached) select.value = cached;
            });
        }

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

    const getRankList = (conf) => Array.from(form.querySelectorAll(`[name^="${conf}_rank_"]`))
        .sort((a, b) => parseInt(a.name.split('_rank_')[1], 10) - parseInt(b.name.split('_rank_')[1], 10))
        .map(s => s.value || null)
        .filter(Boolean);

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
    if (!await confirmarSite('Remover este jogador do draft?')) return;
    
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
