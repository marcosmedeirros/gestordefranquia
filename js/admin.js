const api = async (path, options = {}) => {
  const res = await fetch(`/api/${path}`, {
    headers: { 'Content-Type': 'application/json', ...(options.headers || {}) },
    credentials: 'same-origin',
    ...options,
  });
  const text = await res.text();
  let body = {};
  if (text) {
    try {
      body = JSON.parse(text);
    } catch {
      body = { error: text };
    }
  }
  if (!res.ok || body.success === false) {
    const message = body.error || body.message || 'Erro desconhecido';
    throw { ...body, error: message };
  }
  return body;
};

const _leagues = window.ADMIN_LEAGUES && window.ADMIN_LEAGUES.length
  ? window.ADMIN_LEAGUES
  : ['ELITE', 'NEXT', 'RISE', 'ROOKIE'];

let appState = {
  view: 'home',
  currentLeague: null,
  currentTeam: null,
  teamDetails: null,
  currentFAleague: _leagues[0] || 'ELITE',
  adminLeagueFilter: null,
  tradeFilters: { league: 'ALL', status: 'all', teamId: '', seasonYear: '' }
};
let adminFreeAgents = [];
const freeAgencyTeamsCache = {};

function updateTradeFilter(nextFilters = {}) {
  if (Object.prototype.hasOwnProperty.call(nextFilters, 'league')
    && nextFilters.league !== appState.tradeFilters.league) {
    nextFilters.teamId = '';
  }

  appState.tradeFilters = {
    ...appState.tradeFilters,
    ...nextFilters
  };
  showTrades(appState.tradeFilters.status || 'all');
}

// ── Gestão de Usuários ────────────────────────────────────────────
let _gestaoUsers = [];
let _gestaoTodos = null;      // cache das quatro ligas, pra busca cruzada
let _gestaoBuscando = false;
let _gestaoLeague = _leagues[0] || 'ELITE';

// Aba Games do Admin. Depois da fusão as telas de administração do games
// vivem em /games/admin/, dentro do próprio site e com a mesma sessão — aqui
// fica o índice delas.
async function showGamesAdmin() {
  if (!window.IS_GAMES_ADMIN) { showHome(); return; }
  appState.view = 'games';
  updateBreadcrumb();

  /* Um card com `fn` abre uma tela DENTRO do admin; com `url`, vai pra fora.
     Eventos entrou como card e não como painel na página porque a lista pode
     ter dezenas de linhas — inline ela empurrava o resto da aba pra baixo,
     todo dia, por causa de algo que se usa de vez em quando. */
  const atalhos = [
    { fn: 'showEventosAdmin()',               icone: 'bi-calendar-event-fill', titulo: 'Eventos',      desc: 'As apostas que os GMs criam: declarar resultado, cancelar e devolver' },
    { url: '/admin-apostas.php',              icone: 'bi-graph-up-arrow', titulo: 'Apostas',           desc: 'Criar eventos, acompanhar palpites e encerrar pagando os acertos' },
    { url: '/games/admin/dadosjogadores.php', icone: 'bi-database-fill',  titulo: 'Base de Jogadores', desc: 'Banco de jogadores que alimenta os jogos' },
    { url: '/admin-games-controle.php',       icone: 'bi-toggles',        titulo: 'Controle de Jogos', desc: 'Ligar pontuação em dobro por jogo' },
  ];

  const cards = atalhos.map(a => {
    const abre = a.fn ? `href="#" onclick="${a.fn}; return false"` : `href="${a.url}"`;
    return `
    <div class="col-12 col-md-6 col-xl-4">
      <a ${abre} class="action-tile w-100 h-100 text-start" style="text-decoration:none">
        <i class="bi ${a.icone}"></i>
        <div>
          <div class="fw-bold">${a.titulo}</div>
          <div class="small text-secondary">${a.desc}</div>
        </div>
      </a>
    </div>`;
  }).join('');

  document.getElementById('mainContainer').innerHTML = `
    <div class="mb-3 d-flex align-items-center justify-content-between flex-wrap gap-2">
      <div class="small text-secondary">
        <i class="bi bi-info-circle me-1"></i>
        O games agora roda dentro do fbabrasil.com.br, no mesmo banco e com o mesmo login.
      </div>
      <a href="/games.php" class="btn btn-sm btn-outline-orange">
        <i class="bi bi-box-arrow-up-right me-1"></i>Ver a página de Games
      </a>
    </div>
    <div class="row g-3 mb-4">${cards}</div>

    <div class="panel">
      <div class="panel-title">
        <i class="bi bi-sliders" style="color:#38bdf8"></i> Configuração dos games
      </div>

      <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-1">
        <span class="fw-semibold" style="font-size:14px">
          <i class="bi bi-toggles me-1" style="color:#38bdf8"></i>Dobro de moedas por jogo
        </span>
        <button class="btn btn-sm btn-outline-orange" onclick="_carregarGamesDobro()">
          <i class="bi bi-arrow-clockwise me-1"></i>Atualizar
        </button>
      </div>
      <div class="small text-secondary mb-2">
        Cada chave vale na hora, sem salvar. Enquanto estiver ligada, o jogo paga o dobro.
      </div>
      <div id="gamesDobroWrap" class="text-center py-3">
        <div class="spinner-border text-orange"></div>
      </div>


      <hr style="border-color:var(--border);opacity:.6;margin:18px 0">

      <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-1">
        <span class="fw-semibold" style="font-size:14px">
          <i class="bi bi-hammer me-1" style="color:#f59e0b"></i>Leilão do jogo da semana
        </span>
        <button class="btn btn-sm btn-outline-orange" onclick="_carregarLeilaoSemana()">
          <i class="bi bi-arrow-clockwise me-1"></i>Atualizar
        </button>
      </div>
      <div class="small text-secondary mb-2">
        Fechar confirma a compra: os FBA Points dos <b>dois primeiros</b> deixam de estar retidos e viram
        gasto. Os lances são apagados e a liga recomeça do zero pra semana seguinte.
      </div>
      <div id="leilaoSemanaWrap" class="text-center py-3">
        <div class="spinner-border text-orange"></div>
      </div>

      <hr style="border-color:var(--border);opacity:.6;margin:18px 0">

      <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-1">
        <span class="fw-semibold" style="font-size:14px">
          <i class="bi bi-tv-fill me-1" style="color:#0ea5e9"></i>Vagas de tela da live
        </span>
        <button class="btn btn-sm btn-outline-orange" onclick="_carregarSlotsLive()">
          <i class="bi bi-arrow-clockwise me-1"></i>Atualizar
        </button>
      </div>
      <div class="small text-secondary mb-2">
        As oito vagas abrem sozinhas <b>uma hora antes</b> de cada live. A chave só adianta —
        o fechamento continua sendo o começo da transmissão ou o fim das vagas.
      </div>
      <div id="slotsLiveWrap" class="text-center py-3">
        <div class="spinner-border text-orange"></div>
      </div>

      <hr style="border-color:var(--border);opacity:.6;margin:18px 0">

      <div class="fw-semibold mb-1" style="font-size:14px">
        <i class="bi bi-exclamation-triangle me-1" style="color:#ef4444"></i>Zerar
      </div>
      <div class="small text-secondary mb-2">
        Vale para <b>todos os usuários</b> de uma vez e não dá pra desfazer — cada botão pede
        confirmação digitada antes de mandar.
      </div>
      <div class="d-flex align-items-center gap-2 flex-wrap">
        <button class="btn btn-sm btn-outline-danger" onclick="_zerarGames('pontos')">
          <i class="bi bi-arrow-counterclockwise me-1"></i>Zerar moedas
        </button>
        <button class="btn btn-sm btn-outline-danger" onclick="_zerarGames('fba_points')">
          <i class="bi bi-arrow-counterclockwise me-1"></i>Zerar FBA Points
        </button>
        <button class="btn btn-sm btn-outline-danger" onclick="_zerarConquistas('copero')"
                title="Apaga as conquistas do Copero de todo mundo">
          <i class="bi bi-trophy me-1"></i>Zerar conquistas · Copero
        </button>
        <button class="btn btn-sm btn-outline-danger" onclick="_zerarConquistas('caminho')"
                title="Apaga os desafios do Caminho de todo mundo">
          <i class="bi bi-trophy me-1"></i>Zerar desafios · Caminho
        </button>
      </div>
    </div>

    <div class="panel">
      <div class="panel-title d-flex align-items-center justify-content-between flex-wrap gap-2">
        <span><i class="bi bi-coin" style="color:#f59e0b"></i> Pontos e Moedas</span>
        <input type="text" id="gamesUserSearch" placeholder="Buscar por nome ou e-mail..."
               oninput="_filtrarGamesUsers(this.value)"
               style="background:var(--panel-3);border:1px solid var(--border);border-radius:8px;padding:7px 12px;color:var(--text);font-size:13px;min-width:220px">
      </div>
      <div id="gamesUsersWrap" class="text-center py-4">
        <div class="spinner-border text-orange"></div>
      </div>
    </div>

    <div class="panel">
      <div class="panel-title d-flex align-items-center justify-content-between flex-wrap gap-2">
        <span><i class="bi bi-pencil-square" style="color:#22c55e"></i> Elencos atualizados por terceiros</span>
        <button class="btn btn-sm btn-outline-orange" onclick="_carregarAtualizacoes()">
          <i class="bi bi-arrow-clockwise me-1"></i>Atualizar
        </button>
      </div>
      <div class="small text-secondary mb-2">
        Reverter devolve os valores que estavam lá antes, tira as moedas de quem enviou
        e libera o time pra receber outra atualização.
      </div>
      <div id="atualizacoesWrap" class="text-center py-4">
        <div class="spinner-border text-orange"></div>
      </div>
    </div>`;

  _carregarGamesUsers();
  _carregarAtualizacoes();
  _carregarGamesDobro();
  _carregarLeilaoSemana();
  _carregarSlotsLive();
}

/* ── Eventos: as apostas que os GMs criam em /games ─────────────────────
 *
 * A tela existe pro caso que a do GM não cobre. Lá quem declara o resultado é
 * só o dono, de propósito — quem banca é quem paga. Mas dono some, declara
 * errado, ou cria um evento que nunca vai ter resposta, e sem uma saída a
 * moeda de todo mundo fica retida sem prazo.
 */
function showEventosAdmin() {
  appState.view = 'eventos';
  updateBreadcrumb();
  document.getElementById('mainContainer').innerHTML = `
    <div class="mb-4 d-flex align-items-center justify-content-between flex-wrap gap-2">
      <button class="btn btn-back" onclick="showGamesAdmin()">
        <i class="bi bi-arrow-left"></i> Voltar
      </button>
      <div class="d-flex gap-2 align-items-center flex-wrap">
        <select id="evFiltro" class="form-select form-select-sm" style="width:auto" onchange="_pintarEventos()">
          <option value="">Todos os estados</option>
          <option value="aberta">Abertos</option>
          <option value="paga">Pagos</option>
          <option value="cancelada">Cancelados</option>
        </select>
        <button class="btn btn-sm btn-outline-orange" onclick="_carregarEventos()">
          <i class="bi bi-arrow-clockwise me-1"></i>Atualizar
        </button>
      </div>
    </div>

    <div class="panel">
      <div class="panel-header">
        <div>
          <div class="panel-title" style="margin-bottom:0">
            <i class="bi bi-calendar-event-fill" style="color:#22c55e"></i> Eventos
          </div>
          <div class="panel-sub">As apostas que os GMs criam na aba Eventos do /games</div>
        </div>
      </div>
      <div class="small text-secondary mb-3">
        Quem cria declara o resultado na página dele. Aqui é a saída pra quando isso
        não acontece: dono sumido, resultado declarado errado ou evento que não vai
        mais ter resposta. Declarar paga na hora; cancelar devolve tudo a quem apostou.
      </div>
      <div id="eventosWrap" class="text-center py-3">
        <div class="spinner-border text-orange"></div>
      </div>
    </div>`;
  _carregarEventos();
}

async function _carregarEventos() {
  const wrap = document.getElementById('eventosWrap');
  if (!wrap) return;
  wrap.innerHTML = '<div class="spinner-border text-orange"></div>';
  try {
    const r = await fetch('/api/enquetes.php?acao=admin_listar');
    const d = await r.json();
    if (!d.ok) { wrap.innerHTML = `<p class="empty-state">${escapeHtml(d.erro || 'Erro ao carregar.')}</p>`; return; }
    _EVENTOS = d.enquetes || [];
    _pintarEventos();
  } catch (e) {
    wrap.innerHTML = '<p class="empty-state">Não deu pra carregar os eventos.</p>';
  }
}

let _EVENTOS = [];

function _pintarEventos() {
  const wrap = document.getElementById('eventosWrap');
  if (!wrap) return;
  if (!_EVENTOS.length) { wrap.innerHTML = '<p class="empty-state">Nenhum evento criado ainda.</p>'; return; }

  const filtro = document.getElementById('evFiltro')?.value || '';
  const lista = filtro ? _EVENTOS.filter(e => e.status === filtro) : _EVENTOS;
  if (!lista.length) { wrap.innerHTML = '<p class="empty-state">Nenhum evento nesse estado.</p>'; return; }

  const cor = {aberta: '#22c55e', paga: '#71717a', cancelada: '#ef4444', fechada: '#f59e0b'};
  const dinheiro = v => Number(v || 0).toLocaleString('pt-BR');

  wrap.innerHTML = `<div class="d-flex flex-column gap-2 text-start">` + lista.map(e => {
    const aberta = e.status === 'aberta';
    const venceu = e.alternativas.find(a => a.id === e.vencedora);
    // As alternativas com o que cada uma recebeu: é o que o admin precisa ver
    // antes de declarar, porque declarar paga na hora e não desfaz sozinho.
    const alts = e.alternativas.map(a =>
      `<span class="pun-badge" style="background:#1e1e24;border-color:var(--border)">
         ${escapeHtml(a.texto)} · ${Number(a.odd).toFixed(2)} · ${dinheiro(a.apostado)}
       </span>`).join(' ');

    return `
    <div class="panel" style="padding:12px 14px;margin:0">
      <div class="d-flex align-items-center gap-2 flex-wrap mb-1">
        <span class="fw-bold" style="font-size:13.5px">${escapeHtml(e.titulo)}</span>
        <span class="pun-badge" style="background:${cor[e.status]}20;color:${cor[e.status]};border-color:${cor[e.status]}55">
          ${escapeHtml(e.status)}
        </span>
        <span class="small text-secondary">
          ${escapeHtml(e.categoria || 'Outros')} · banca: ${escapeHtml(e.criador)} ·
          ${dinheiro(e.apostado)} de ${dinheiro(e.max_total)} apostados
          ${e.retido ? ` · ${dinheiro(e.retido)} retido` : ''}
          ${e.fecha_em ? ` · fecha ${_dataCurta(e.fecha_em)}` : ''}
        </span>
      </div>
      <div class="d-flex align-items-center gap-1 flex-wrap mb-2">${alts}</div>
      ${venceu ? `<div class="small mb-2" style="color:#22c55e">
                    <i class="bi bi-trophy-fill me-1"></i>Deu <b>${escapeHtml(venceu.texto)}</b></div>` : ''}
      <div class="d-flex align-items-center gap-2 flex-wrap">
        ${aberta ? `
          <select class="form-select form-select-sm" id="evRes${e.id}" style="width:auto;max-width:230px">
            <option value="">Declarar o resultado…</option>
            ${e.alternativas.map(a => `<option value="${a.id}">${escapeHtml(a.texto)}</option>`).join('')}
          </select>
          <button class="btn btn-sm btn-success" onclick="_eventoFechar(${e.id})">
            <i class="bi bi-check2 me-1"></i>Pagar
          </button>` : ''}
        ${e.status !== 'cancelada' ? `
          <button class="btn btn-sm btn-outline-danger" onclick="_eventoCancelar(${e.id}, '${e.status}')">
            <i class="bi bi-arrow-counterclockwise me-1"></i>${
              e.status === 'paga' ? 'Reverter e devolver' : 'Cancelar e devolver'}
          </button>` : '<span class="small text-secondary">Já cancelado — as moedas voltaram.</span>'}
      </div>
    </div>`;
  }).join('') + '</div>';
}

/** "05/09 18:30" — o ano só quando não é este. */
function _dataCurta(iso) {
  const d = new Date(String(iso).replace(' ', 'T'));
  if (isNaN(d)) return escapeHtml(String(iso));
  const hoje = new Date();
  const fmt = {day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit'};
  if (d.getFullYear() !== hoje.getFullYear()) fmt.year = 'numeric';
  return d.toLocaleString('pt-BR', fmt);
}

async function _eventoFechar(id) {
  const alt = Number(document.getElementById('evRes' + id)?.value || 0);
  if (!alt) { showAlert('warning', 'Escolha qual alternativa venceu.'); return; }
  if (!confirm('Declarar esse resultado e pagar quem acertou?\n\nO pagamento sai na hora.')) return;
  try {
    const r = await fetch('/api/enquetes.php?acao=admin_fechar', {
      method: 'POST', headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({enquete_id: id, alternativa_id: alt}),
    });
    const d = await r.json();
    if (!d.ok) { showAlert('danger', d.erro || 'Não deu pra declarar.'); return; }
    showAlert('success', 'Resultado declarado e pagamentos feitos.');
    _carregarEventos();
  } catch (e) { showAlert('danger', 'Erro ao declarar o resultado.'); }
}

async function _eventoCancelar(id, status) {
  const msg = status === 'paga'
    ? 'REVERTER este pagamento? As moedas voltam pra quem apostou e o criador recebe o dele de volta.'
    : 'Cancelar e devolver tudo a quem apostou?';
  if (!confirm(msg)) return;
  try {
    const r = await fetch('/api/enquetes.php?acao=admin_cancelar', {
      method: 'POST', headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({enquete_id: id}),
    });
    const d = await r.json();
    if (!d.ok) { showAlert('danger', d.erro || 'Não deu pra cancelar.'); return; }
    showAlert('success', status === 'paga' ? 'Pagamento revertido.' : 'Evento cancelado e moedas devolvidas.');
    _carregarEventos();
  } catch (e) { showAlert('danger', 'Erro ao cancelar.'); }
}

/* ── Vagas de tela da live ──────────────────────────────────────────────
 * As oito vagas abrem sozinhas uma hora antes da live. A chave aqui só
 * adianta isso — pro dia em que a live muda de horário e ninguém quer
 * esperar o relógio. Nunca atrasa, e não mexe no fechamento.
 * ─────────────────────────────────────────────────────────────────────── */
async function _carregarSlotsLive() {
  const alvo = document.getElementById('slotsLiveWrap');
  if (!alvo) return;
  try {
    const d = await api('slots-admin.php?action=estado');
    const linhas = (d.ligas || []).map(l => {
      // Cada situação pede uma frase e um botão diferentes: "cedo" tem o que
      // fazer, "a live começou" não tem.
      let nota, chave;
      if (!l.live) {
        nota = 'Sem live marcada nas próximas duas semanas.'; chave = null;
      } else if (l.motivo === 'comecou') {
        nota = 'A live já começou — a venda fechou.'; chave = null;
      } else if (l.motivo === 'esgotado') {
        nota = `As ${l.total} vagas acabaram.`; chave = null;
      } else if (l.aberta || l.motivo === 'ja_tenho') {
        nota = l.na_mao ? 'Aberta na mão, antes da hora.' : 'Aberta — já está na hora.';
        chave = l.na_mao ? 'cancelar' : null;
      } else {
        nota = `Abre sozinha às ${escapeHtml((l.abre_em || '').slice(11, 16))}.`; chave = 'abrir';
      }
      const aberta = l.aberta || l.motivo === 'ja_tenho';
      return `
      <div class="d-flex align-items-center justify-content-between gap-3 py-2"
           style="border-top:1px solid var(--border)">
        <div style="min-width:0;text-align:left">
          <div class="fw-bold">${escapeHtml(l.liga)}
            <span class="small text-secondary">${l.live ? '· live ' + escapeHtml(l.live.hora) : ''}</span>
          </div>
          <div class="small text-secondary">${nota} ${l.live ? `<b>${l.vendidos}/${l.total}</b> vendidas` : ''}</div>
        </div>
        ${chave ? `
          <button class="btn btn-sm ${aberta ? 'btn-warning' : 'btn-outline-secondary'}"
                  onclick="_alternarSlotsLive('${escapeHtml(l.liga)}','${chave}',this)" style="min-width:92px">
            ${chave === 'abrir' ? 'abrir agora' : 'aberta'}
          </button>`
        : `<span class="small text-secondary" style="min-width:92px;text-align:right">${aberta ? 'vendendo' : '—'}</span>`}
      </div>`;
    }).join('');
    alvo.className = '';
    alvo.innerHTML = linhas || '<div class="small text-secondary py-2">Nenhuma liga.</div>';
  } catch (e) {
    alvo.className = '';
    alvo.innerHTML = `<div class="small text-danger py-2">Não deu pra carregar: ${escapeHtml(e.error || e.message || 'erro')}</div>`;
  }
}

async function _alternarSlotsLive(liga, acao, bt) {
  bt.disabled = true;
  try {
    const r = await api('slots-admin.php', { method: 'POST', body: JSON.stringify({ action: acao, liga }) });
    showAlert('success', r.message);
  } catch (e) {
    showAlert('danger', e.error || 'Não deu pra mudar.');
  }
  // Recarrega dos dois jeitos: o estado real pode não ser o que o clique
  // pediu (a live pode ter começado no meio do caminho).
  _carregarSlotsLive();
}

/* ── Dobro de moedas por jogo ───────────────────────────────────────────
   A lista vem do servidor (backend/games_config.php), e não daqui: existiam
   duas telas com listas escritas à mão, cada uma conhecendo um pedaço do
   catálogo — cinco jogos que leem o multiplicador não apareciam em nenhuma
   delas. Jogo novo entra na fonte e aparece aqui sozinho. */
async function _carregarGamesDobro() {
  const alvo = document.getElementById('gamesDobroWrap');
  if (!alvo) return;
  try {
    const d = await api('admin.php?action=games_dobro_estado');
    const linhas = (d.jogos || []).map(j => `
      <div class="d-flex align-items-center justify-content-between gap-3 py-2"
           style="border-top:1px solid var(--border)">
        <div class="d-flex align-items-center gap-2" style="min-width:0">
          <i class="bi ${j.icon}" style="color:var(--text-2)"></i>
          <div style="min-width:0">
            <div class="fw-bold">${escapeHtml(j.label)}</div>
            <div class="small text-secondary">${escapeHtml(j.desc)}</div>
          </div>
        </div>
        <button class="btn btn-sm ${j.on ? 'btn-warning' : 'btn-outline-secondary'}"
                id="dobro-${j.key}" onclick="_alternarGamesDobro('${j.key}')" style="min-width:92px">
          ${j.on ? '2× ligado' : 'desligado'}
        </button>
      </div>`).join('');
    alvo.className = '';
    alvo.innerHTML = linhas || '<div class="small text-secondary py-2">Nenhum jogo configurável.</div>';
  } catch (e) {
    alvo.className = '';
    alvo.innerHTML = `<div class="small text-danger py-2">Não deu pra carregar: ${escapeHtml(e.error || e.message || 'erro')}</div>`;
  }
}

async function _alternarGamesDobro(jogo) {
  const bt = document.getElementById('dobro-' + jogo);
  if (!bt) return;
  const ligando = !bt.classList.contains('btn-warning');
  bt.disabled = true;
  try {
    await api('admin.php?action=games_dobro_salvar', {
      method: 'POST',
      body: JSON.stringify({ jogo, ligado: ligando }),
    });
    // Um jogo por vez: mexer num não pode desligar os outros, que era o que
    // acontecia quando a tela mandava a lista inteira.
    bt.classList.toggle('btn-warning', ligando);
    bt.classList.toggle('btn-outline-secondary', !ligando);
    bt.textContent = ligando ? '2× ligado' : 'desligado';
  } catch (e) {
    showAlert('danger', e.error || e.message || 'Erro ao salvar.');
  }
  bt.disabled = false;
}

/* ── Leilão do jogo da semana ───────────────────────────────────────────
   Um botão por liga, e o confronto ao lado dele. Fechar cobra FBA Points de
   dois times de verdade: o painel mostra QUEM e QUANTO antes de qualquer
   clique, e o botão nasce desligado onde ninguém deu lance. */
let _leilaoSemana = [];

async function _carregarLeilaoSemana() {
  const alvo = document.getElementById('leilaoSemanaWrap');
  if (!alvo) return;
  try {
    const d = await api('admin.php?action=leilao_semana_estado');
    _leilaoSemana = d.ligas || [];
    // O botão passa só a LIGA. Montar o confronto dentro do onclick exigia
    // escapar aspas na mão, e um clube com aspas no nome quebraria o atributo
    // — o texto da confirmação sai daqui, do estado que acabou de chegar.
    const linhas = _leilaoSemana.map(l => {
      const temJogo = !!l.time1;
      const total = (l.valor1 || 0) + (l.valor2 || 0);
      const confronto = temJogo
        ? `${escapeHtml(l.time1)} × ${escapeHtml(l.time2 || 'vaga aberta')}`
        : '<span class="text-secondary">ninguém deu lance</span>';
      return `
        <div class="d-flex align-items-center justify-content-between gap-3 flex-wrap py-2"
             style="border-top:1px solid var(--border)">
          <div style="min-width:0">
            <div class="fw-bold">${l.liga}</div>
            <div class="small">${confronto}${temJogo
              ? ` · <b style="color:#f59e0b">${total.toLocaleString('pt-BR')}</b> FBA Points a cobrar`
              : ''}${l.na_fila ? ` <span class="text-secondary">· ${l.na_fila} na fila</span>` : ''}</div>
          </div>
          <button class="btn btn-sm ${temJogo ? 'btn-outline-orange' : 'btn-outline-secondary'}"
                  ${temJogo ? '' : 'disabled'} onclick="_fecharLeilaoSemana('${l.liga}')">
            <i class="bi bi-check2-circle me-1"></i>Fechar ${l.liga}
          </button>
        </div>`;
    }).join('');
    alvo.className = '';
    alvo.innerHTML = linhas || '<div class="small text-secondary py-2">Nenhuma liga.</div>';
  } catch (e) {
    alvo.className = '';
    alvo.innerHTML = `<div class="small text-danger py-2">Não deu pra carregar o leilão: ${escapeHtml(e.error || e.message || 'erro')}</div>`;
  }
}

async function _fecharLeilaoSemana(liga) {
  const l = _leilaoSemana.find(x => x.liga === liga);
  if (!l || !l.time1) { showAlert('warning', 'Essa liga não tem lance pra fechar.'); return; }
  const total = (l.valor1 || 0) + (l.valor2 || 0);
  const confronto = `${l.time1} × ${l.time2 || 'vaga aberta'}`;
  const ok = await confirmarSite(
    `Fechar o leilão da ${liga}?\n\n${confronto}\n\n` +
    `Isso cobra ${total} FBA Points dos dois times e apaga os lances.`
  );
  if (!ok) return;
  try {
    const d = await api('admin.php?action=leilao_semana_fechar', {
      method: 'POST',
      body: JSON.stringify({ liga }),
    });
    showAlert('success', `Leilão da ${liga} fechado — ${(d.jogo || []).join(' × ') || 'sem confronto'}, ${d.pago} FBA Points cobrados.`);
    _carregarLeilaoSemana();
  } catch (e) {
    showAlert('danger', e.error || e.message || 'Erro ao fechar o leilão.');
  }
}

/* ── Atualizações de elenco feitas por terceiros ────────────────────────
   Existe porque o pagamento é automático: alguém pode subir um CSV com
   número inventado só pra faturar. Reverter desfaz tudo de uma vez — o
   valor antigo volta, a moeda sai, e o time destrava. */
async function _carregarAtualizacoes() {
  const wrap = document.getElementById('atualizacoesWrap');
  if (!wrap) return;
  try {
    const r = await fetch('/api/atualizar-time.php?acao=historico');
    const d = await r.json();
    if (!d.ok) throw new Error(d.erro || 'falhou');
    if (!d.itens.length) {
      wrap.innerHTML = '<div class="text-secondary small py-3">Nenhuma atualização de terceiro ainda.</div>';
      return;
    }
    wrap.className = 'table-responsive';
    wrap.innerHTML = `
      <table class="table table-dark table-sm align-middle mb-0">
        <thead><tr>
          <th>Quando</th><th>Time</th><th>Quem</th><th>O quê</th>
          <th class="text-end">Jog.</th><th class="text-end">Moedas</th><th></th>
        </tr></thead>
        <tbody>${d.itens.map(i => `
          <tr class="${i.revertido_em ? 'opacity-50' : ''}">
            <td class="small text-secondary">${new Date(String(i.criado_em).replace(' ', 'T')).toLocaleString('pt-BR')}</td>
            <td>${escapeHtml(i.time || '—')} <span class="small text-secondary">${escapeHtml(i.league || '')}</span></td>
            <td>${escapeHtml(i.gm || '—')}</td>
            <td><span class="badge bg-secondary">${i.tipo === 'skills' ? 'skills' : 'estatísticas'}</span></td>
            <td class="text-end">${i.jogadores}</td>
            <td class="text-end" style="color:#f59e0b">${i.moedas}</td>
            <td class="text-end">${i.revertido_em
              ? '<span class="small text-secondary">revertido</span>'
              : `<button class="btn btn-sm btn-outline-danger" onclick="_reverterAtualizacao(${i.id})">Reverter</button>`}</td>
          </tr>`).join('')}
        </tbody>
      </table>`;
  } catch (e) {
    wrap.innerHTML = `<div class="text-danger small py-3">Erro ao carregar: ${escapeHtml(e.message)}</div>`;
  }
}

async function _reverterAtualizacao(id) {
  if (!await confirmarSite('Reverter esta atualização?\n\nOs valores antigos voltam, as moedas saem de quem enviou e o time fica livre pra receber outra.')) return;
  try {
    const body = new URLSearchParams({acao: 'reverter', id: String(id)});
    const r = await fetch('/api/atualizar-time.php', {method: 'POST', body});
    const d = await r.json();
    if (!r.ok || !d.ok) throw new Error(d.erro || 'falhou');
    showAlert('success', `Revertido. ${d.moedas_estornadas} moedas estornadas.`);
    _carregarAtualizacoes();
    _carregarGamesUsers();
  } catch (e) {
    showAlert('danger', e.message);
  }
}

let _gamesUsersCache = [];

async function _carregarGamesUsers() {
  const wrap = document.getElementById('gamesUsersWrap');
  try {
    const data = await api('admin.php?action=games_users');
    _gamesUsersCache = data.users || [];
    _renderGamesUsers(_gamesUsersCache);
  } catch (e) {
    if (wrap) wrap.innerHTML = `<div class="text-danger small p-3">${escapeHtml(e.message)}</div>`;
  }
}

/**
 * Zera moedas ou FBA Points de todos os usuários de uma vez. É o mesmo efeito
 * do reset do dia 1, só que na mão — não dá pra desfazer, então pede o nome do
 * campo digitado antes de mandar.
 */
async function _zerarGames(campo) {
  const rotulo = campo === 'pontos' ? 'moedas' : 'FBA Points';
  const confirma = await perguntarSite(
    `Isso zera ${rotulo} de TODOS os usuários e não pode ser desfeito.\n\n` +
    `Para confirmar, digite: ${rotulo}`
  );
  if (confirma === null) return;
  if (confirma.trim().toLowerCase() !== rotulo.toLowerCase()) {
    showAlert('warning', 'Confirmação não bateu — nada foi zerado.');
    return;
  }
  try {
    const d = await api('admin.php?action=games_zerar', {
      method: 'POST',
      body: JSON.stringify({ campo }),
    });
    showAlert('success', `${rotulo} zerados (${d.afetados} usuário${d.afetados === 1 ? '' : 's'}).`);
    _carregarGamesUsers();
  } catch (e) {
    showAlert('danger', e.error || e.message || `Erro ao zerar ${rotulo}.`);
  }
}

/**
 * Apaga as conquistas de um dos jogos de carreira, de todo mundo.
 *
 * Existe pro lançamento: quem jogou antes de o jogo estar pronto levou
 * conquista com regra velha, e a lista precisa começar do zero pra valer
 * igual pra todos.
 *
 * O aviso é explícito sobre o que NÃO acontece: as moedas já pagas ficam.
 * Tirar moeda de quem já gastou deixaria saldo negativo — mas a
 * consequência disso é que quem já tinha vai poder ganhar de novo, e ser
 * pago de novo. Se a ideia for zerar tudo, os botões de moeda estão ali.
 */
async function _zerarConquistas(jogo) {
  const nome = jogo === 'copero' ? 'Copero' : 'Caminho';
  const oQue = jogo === 'copero' ? 'conquistas' : 'desafios';
  const confirma = await perguntarSite(
    `Isso apaga as ${oQue} do ${nome} de TODOS os usuários e não pode ser desfeito.\n\n` +
    `As moedas já pagas por elas NÃO voltam — e como a lista zera, quem já ` +
    `tinha vai poder conquistar de novo e ser pago de novo.\n\n` +
    `Para confirmar, digite: ${nome}`
  );
  if (confirma === null) return;
  if (confirma.trim().toLowerCase() !== nome.toLowerCase()) {
    showAlert('warning', 'Confirmação não bateu — nada foi apagado.');
    return;
  }
  try {
    const d = await api('admin.php?action=games_zerar_conquistas', {
      method: 'POST',
      body: JSON.stringify({ jogo }),
    });
    const um = jogo === 'copero' ? 'conquista apagada' : 'desafio apagado';
    const varios = jogo === 'copero' ? 'conquistas apagadas' : 'desafios apagados';
    showAlert('success', d.aviso
      || `${nome}: ${d.afetados} ${d.afetados === 1 ? um : varios}, de ${d.pessoas} pessoa${d.pessoas === 1 ? '' : 's'}.`);
  } catch (e) {
    showAlert('danger', e.error || e.message || `Erro ao zerar as ${oQue}.`);
  }
}

function _filtrarGamesUsers(termo) {
  const t = (termo || '').trim().toLowerCase();
  if (!t) { _renderGamesUsers(_gamesUsersCache); return; }
  _renderGamesUsers(_gamesUsersCache.filter(u =>
    (u.name || '').toLowerCase().includes(t) || (u.email || '').toLowerCase().includes(t)));
}

/**
 * A lista de GMs com moedas e FBA Points.
 *
 * Era uma tabela de sete colunas dentro de .table-responsive. No celular ela
 * não cabia: o campo de moedas ficava espremido a ponto de cortar o número
 * (um "50" virava "5C"), e FBA Points, acertos, o switch de admin e o botão
 * de salvar ficavam fora da tela — pra editar o saldo de alguém era preciso
 * rolar de lado e perder de vista de quem era a linha.
 *
 * Agora é uma marcação só com dois layouts. No celular cada GM é um cartão
 * com os campos rotulados; no desktop o mesmo HTML vira grade alinhada com
 * cabeçalho, que é o que a tabela dava de bom. Sem markup duplicado: os
 * rótulos somem no CSS quando o cabeçalho aparece.
 */
function _renderGamesUsers(users) {
  const wrap = document.getElementById('gamesUsersWrap');
  if (!wrap) return;
  if (!users.length) {
    wrap.innerHTML = '<div class="text-secondary small p-3 text-center">Nenhum usuário encontrado.</div>';
    return;
  }

  const linhas = users.map(u => {
    const ehAdminGeral = u.user_type === 'admin';
    const marcado = Number(u.games_admin) === 1 || ehAdminGeral;
    return `
      <div class="gu-row">
        <div class="gu-gm">
          <div style="min-width:0">
            <div class="gu-nome">${escapeHtml(u.name || '—')}</div>
            <div class="gu-mail">${escapeHtml(u.email || '')}</div>
          </div>
          <span class="gu-liga">${escapeHtml(u.league || '—')}</span>
        </div>

        <label class="gu-campo">
          <span class="gu-lab">Moedas</span>
          <input type="number" min="0" inputmode="numeric" class="gu-input"
                 value="${Number(u.pontos) || 0}" id="gu-pontos-${u.id}">
        </label>

        <label class="gu-campo">
          <span class="gu-lab">FBA Points</span>
          <input type="number" min="0" inputmode="numeric" class="gu-input"
                 value="${Number(u.fba_points) || 0}" id="gu-fba-${u.id}">
        </label>

        <div class="gu-campo gu-mini">
          <span class="gu-lab">Acertos</span>
          <b class="gu-num">${Number(u.acertos_eventos) || 0}</b>
        </div>

        <div class="gu-campo gu-mini">
          <span class="gu-lab">Admin Games</span>
          ${window.IS_GLOBAL_ADMIN ? `
            <div class="form-check form-switch m-0" title="${ehAdminGeral ? 'Admin geral já tem acesso' : 'Ver a aba Games no Admin'}">
              <input class="form-check-input" type="checkbox" ${marcado ? 'checked' : ''} ${ehAdminGeral ? 'disabled' : ''}
                     onchange="_toggleGamesAdmin(${u.id}, this.checked, this)">
            </div>` : (marcado ? '<i class="bi bi-check-lg text-success"></i>' : '<span class="gu-num">—</span>')}
        </div>

        <div class="gu-acao">
          <button class="btn btn-sm btn-orange w-100" onclick="_salvarGamesSaldo(${u.id}, this)">
            <i class="bi bi-check-lg me-1"></i>Salvar
          </button>
        </div>
      </div>`;
  }).join('');

  wrap.innerHTML = `
    <div class="gu-list">
      <div class="gu-head">
        <span>GM</span><span>Moedas</span><span>FBA Points</span>
        <span>Acertos</span><span>Admin Games</span><span></span>
      </div>
      ${linhas}
    </div>`;
}
async function _salvarGamesSaldo(userId, btn) {
  const pontos = parseInt(document.getElementById(`gu-pontos-${userId}`)?.value, 10);
  const fba    = parseInt(document.getElementById(`gu-fba-${userId}`)?.value, 10);
  if (isNaN(pontos) || isNaN(fba) || pontos < 0 || fba < 0) {
    showAlert('warning', 'Informe valores válidos (zero ou mais).');
    return;
  }
  const original = btn.innerHTML;
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
  try {
    await api('admin.php?action=games_user_saldo', {
      method: 'POST',
      body: JSON.stringify({ user_id: userId, pontos, fba_points: fba })
    });
    const cached = _gamesUsersCache.find(u => Number(u.id) === Number(userId));
    if (cached) { cached.pontos = pontos; cached.fba_points = fba; }
    // Volta com rótulo: o botão agora é largo e escrito, e trocar por um
    // ícone solto deixava a linha com cara de quebrada depois de salvar.
    btn.innerHTML = '<i class="bi bi-check-lg me-1"></i>Salvo';
    showAlert('success', 'Saldo atualizado.');
  } catch (e) {
    showAlert('danger', e.message);
    btn.innerHTML = original;
  } finally {
    btn.disabled = false;
  }
}

async function _toggleGamesAdmin(userId, enabled, el) {
  el.disabled = true;
  try {
    await api('admin.php?action=games_admin_toggle', {
      method: 'POST',
      body: JSON.stringify({ user_id: userId, enabled })
    });
    const cached = _gamesUsersCache.find(u => Number(u.id) === Number(userId));
    if (cached) cached.games_admin = enabled ? 1 : 0;
    showAlert('success', enabled ? 'Agora esse GM vê a aba Games.' : 'Acesso ao admin do Games removido.');
  } catch (e) {
    el.checked = !enabled;
    showAlert('danger', e.message);
  } finally {
    el.disabled = false;
  }
}

async function showGestao(league) {
  if (!window.IS_GLOBAL_ADMIN) { showHome(); return; }
  appState.view = 'gestao';
  updateBreadcrumb();
  if (league) _gestaoLeague = league;
  // Toda entrada/refresh da Gestão invalida o cache das outras ligas: depois de
  // editar ou apagar alguém, a busca não pode devolver o estado antigo.
  _gestaoTodos = null;
  _gestaoBuscando = false;

  const container = document.getElementById('mainContainer');
  container.innerHTML = '<div class="text-center py-5"><div class="spinner-border text-orange"></div></div>';

  let leagueCounts = {};
  try {
    const leaguesData = await api('admin.php?action=leagues');
    (leaguesData.leagues || []).forEach(l => { leagueCounts[l.league] = l.team_count; });
  } catch (e) {}

  const leagueTabs = _leagues.map(lg => `
    <button class="btn btn-sm ${lg === _gestaoLeague ? 'btn-orange' : 'btn-outline-orange'}"
            onclick="showGestao('${lg}')">${lg}${leagueCounts[lg] !== undefined ? ` <span style="opacity:.75">| ${leagueCounts[lg]}</span>` : ''}</button>`).join('');

  // Mesmos cards (.action-tile) das outras abas do admin — antes isto era uma
  // fileira de botões-pílula, que destoava do resto e ficava ilegível quando a
  // lista crescia. `url` vira <a>; `fn` vira <button>.
  const gestaoAcoes = [
    { icon: 'bi-person-plus-fill',      label: 'Adicionar<br>GM',           fn: 'openCreateGmModal()',   color: '#22c55e', bg: 'rgba(34,197,94,.12)'  },
    // Quem desiste abre vaga e a fila sobe: é outro fluxo do "Adicionar GM",
    // que cria time novo. Aqui ninguém cria time — a pessoa muda de cadeira.
    { icon: 'bi-arrow-up-square-fill',  label: 'Cadeiras e<br>Promoções',   fn: 'showCadeiras()',        color: '#0ea5e9', bg: 'rgba(14,165,233,.12)' },
    { icon: 'bi-chat-left-dots-fill',   label: 'Ouvidoria',                 fn: 'showOuvidoriaModal()',  color: '#8b5cf6', bg: 'rgba(139,92,246,.12)' },
    { icon: 'bi-award-fill',            label: 'Hall da<br>Fama',           fn: 'showHallOfFame()',      color: '#eab308', bg: 'rgba(234,179,8,.12)'  },
    { icon: 'bi-record-circle',         label: 'Roletas',                   url: '/roleta.php',          color: '#ec4899', bg: 'rgba(236,72,153,.12)' },
    // O controle do calendário é a própria página: marcar evento é clicar no
    // dia. Uma tela de administração separada seria uma segunda versão do
    // calendário pra manter, mostrando a mesma coisa.
    // Ja existia, e continua sendo a porta de quem MARCA evento — o rotulo
    // e que nao dizia isso. No menu lateral o mesmo calendario e consulta;
    // aqui e onde se cria, inclusive as lives que a escala usa.
    { icon: 'bi-calendar-plus-fill',    label: 'Calendário<br>criar eventos', url: '/calendario.php',    color: '#38bdf8', bg: 'rgba(56,189,248,.12)' },
    // A Inscrição ROOKIE saiu daqui: ela já existe nas ações da liga ROOKIE,
    // que é o lugar de quem administra a ROOKIE. Em Gestão era a mesma porta
    // duas vezes.
    { icon: 'bi-shuffle',               label: 'Drafts<br>Aleatórios',      url: '/drafts-aleatorios.php',    color: '#a855f7', bg: 'rgba(168,85,247,.08)' },
    { icon: 'bi-dice-3-fill',           label: 'Loterias',                  url: '/loterias-aleatorias.php',  color: '#f59e0b', bg: 'rgba(245,158,11,.12)' },
    // A redação do jornal: escrever, publicar, editar, apagar e moderar os
    // comentários. Era rotulada "The Pathetic" quando a tela era uma caixa de
    // HTML; agora o nome diz o que se faz lá.
    { icon: 'bi-newspaper',             label: 'Redação<br>The Pathetic',   url: '/thepathetic-edit.php',     color: 'var(--red)', bg: 'color-mix(in srgb, var(--red) 12%, transparent)' },
    { icon: 'bi-person-bounding-box',   label: 'Sincronizar<br>Fotos NBA',  fn: 'syncFotosNBA()',        color: '#06b6d4', bg: 'rgba(6,182,212,.12)', id: 'btnSyncFotos' },
    { icon: 'bi-person-lines-fill',     label: 'Interessados',              fn: 'showWaitlistModal()',   color: '#22c55e', bg: 'rgba(34,197,94,.12)', badgeId: 'waitlist-badge' },
    // O que os GMs compraram na loja e resgataram. A loja NAO aplica sozinha:
    // dar slot de waiver ou badge mexe em regra de liga, e cada uma dessas
    // regras mora num sistema diferente — limite adivinhado errado vira
    // reclamacao semanas depois. O GM resgata, cai aqui, e quem aplica e gente.
    { icon: 'bi-bag-check-fill',        label: 'Pedidos<br>da Loja',        url: '/loja-pedidos.php',    color: '#f59e0b', bg: 'rgba(245,158,11,.12)' },
    // A escala das lives. Fica em Gestao e nao no menu lateral porque quem
    // monta e a organizacao — pro resto da liga, o que interessa e o aviso
    // de que foi escalado e a live no proprio calendario.
    { icon: 'bi-broadcast',             label: 'Escala<br>das Lives',       url: '/escalalive.php',      color: '#3b82f6', bg: 'rgba(59,130,246,.12)' },
    { icon: 'bi-book-half',             label: 'Ver guia<br>do usuário',    url: '/guia.php', novaAba: true, color: '#38bdf8', bg: 'rgba(56,189,248,.12)' },
    // Guia do Admin: entrada única, só aqui. A página em si também barra quem
    // não tem acesso admin, então o card não é a trava — é só o caminho.
    { icon: 'bi-journal-code',          label: 'Guia do<br>Admin',          url: '/guia-admin.php', novaAba: true, color: 'var(--red)', bg: 'color-mix(in srgb, var(--red) 12%, transparent)' },
    ...(window.IS_GLOBAL_ADMIN ? [
      // O card do Site Admin saiu de Gestão a pedido. A página segue de pé em
      // /siteadmin.php — o que sumiu foi o atalho, não o acesso.
      // O abraço já sai sozinho às 15h; isto é pra mandar na hora.
      { icon: 'bi-emoji-smile-fill',    label: 'Disparar<br>abraço',        fn: 'dispararAbraco()',      color: '#22c55e', bg: 'rgba(34,197,94,.12)', id: 'btnAbraco' },
      { icon: 'bi-patch-question-fill', label: 'Quiz do<br>grupo',          fn: 'showQuizAdmin()',       color: '#a855f7', bg: 'rgba(168,85,247,.12)' },
      // O bot tem tela própria: grupos de comando, plantão, arquivo de
      // conversa. Isso morava dentro do card do quiz, e ninguém achava —
      // cadastro de grupo do bot não tem nada a ver com pergunta do dia.
      { icon: 'bi-robot',               label: 'Painel do<br>Bot',          url: '/painelbot.php',        color: '#25d366', bg: 'rgba(37,211,102,.12)' },
    ] : []),
  ];

  const gestaoTiles = gestaoAcoes.map(a => {
    const miolo = `
      <div class="action-tile-icon" style="background:${a.bg};color:${a.color}"><i class="bi ${a.icon}"></i></div>
      <div class="action-tile-label">${a.label}</div>
      ${a.badgeId ? `<span class="action-tile-badge" id="${a.badgeId}" style="display:none">0</span>` : ''}`;
    return a.url
      ? `<a href="${a.url}"${a.novaAba ? ' target="_blank" rel="noopener"' : ''} class="action-tile" style="text-decoration:none">${miolo}</a>`
      : `<button class="action-tile"${a.id ? ` id="${a.id}"` : ''} onclick="${a.fn}">${miolo}</button>`;
  }).join('');

  container.innerHTML = `
    <div id="maintenanceBanner" class="mb-3"></div>
    <div class="mb-3 d-flex align-items-center justify-content-center flex-wrap gap-2" style="position:relative">
      <div class="d-flex gap-2 flex-wrap justify-content-center">${leagueTabs}</div>
      <button class="btn btn-sm btn-outline-orange" onclick="showGestao('${_gestaoLeague}')" style="position:absolute; right:0">
        <i class="bi bi-arrow-repeat"></i>
      </button>
    </div>
    <div class="action-grid mb-3">${gestaoTiles}</div>
    <div class="mb-3 d-flex justify-content-center">
      <div style="position:relative;max-width:420px;width:100%">
        <i class="bi bi-search" style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--text-3);font-size:13px"></i>
        <input type="search" id="gestaoBusca" autocomplete="off"
          placeholder="Buscar usuário, e-mail ou time — em todas as ligas"
          style="width:100%;background:var(--panel-2);border:1px solid var(--border-md);border-radius:9px;
                 padding:9px 12px 9px 34px;color:var(--text);font-size:13px;font-family:var(--font);outline:none">
      </div>
    </div>
    <div id="gestaoTableContainer">
      <div class="text-center py-5"><div class="spinner-border text-orange"></div></div>
    </div>`;

  document.getElementById('gestaoBusca')?.addEventListener('input', aoBuscarGestao);

  try {
    const data = await api(`admin.php?action=get_users&league=${_gestaoLeague}`);
    _gestaoUsers = data.users || [];
    renderGestaoTable(_gestaoUsers);
  } catch (e) {
    document.getElementById('gestaoTableContainer').innerHTML =
      `<div class="alert alert-danger">Erro ao carregar usuários: ${escapeHtml(e.error || 'Desconhecido')}</div>`;
  }

  try {
    const wl = await api('waitlist.php');
    updateWaitlistBadge((wl.requests || []).filter(r => r.status === 'pending').length);
  } catch (e) {}

  loadMaintenanceStatus();
}

/** Preenche nba_player_id de quem ainda não tem — é isso que faz a foto aparecer. */
// Manda o abraço do dia agora, sem esperar as 15h. Confirma antes porque isto
// posta no grupo — e o grupo tem gente de verdade.
async function dispararAbraco() {
  if (!await confirmarSite('Sortear um GM e mandar o abraço no grupo agora?\n\nIsso posta no The Pathetic, mesmo que o abraço de hoje já tenha saído.')) return;
  const btn = document.getElementById('btnAbraco');
  const original = btn ? btn.innerHTML : null;
  if (btn) {
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
  }
  try {
    const r = await api('admin.php?action=disparar_abraco');
    showAlert('success',
      `Abraço sorteado: ${r.nome} (${r.time}).` +
      (r.com_mencao ? '' : ' Sem telefone cadastrado, então foi o nome puro, sem marcar.') +
      ' A mensagem está na fila — o bot entrega em alguns segundos.');
  } catch (e) {
    showAlert('danger', e.message || 'Falha ao disparar o abraço.');
  } finally {
    if (btn) { btn.disabled = false; btn.innerHTML = original; }
  }
}

// ══════════════════════════════════════════════════════════════════
// QUIZ DO GRUPO — banco de perguntas, rodada aberta e apuração
// ══════════════════════════════════════════════════════════════════
// O padrão é a FILA — o que ainda vai sair. Pergunta que já foi ao ar não
// volta ao sorteio, então ela só atrapalharia quem veio ver o que falta.
let _quizFiltro = { tipo: '', q: '', estado: 'disponiveis' };

async function showQuizAdmin() {
  appState.view = 'quiz';
  updateBreadcrumb();
  const c = document.getElementById('mainContainer');
  c.innerHTML = '<div class="text-center py-5"><div class="spinner-border text-orange"></div></div>';
  try {
    const [e, l] = await Promise.all([
      api('quiz-admin.php?action=estado'),
      api('quiz-admin.php?action=listar'
          + (_quizFiltro.tipo ? '&tipo=' + _quizFiltro.tipo : '')
          + (_quizFiltro.q ? '&q=' + encodeURIComponent(_quizFiltro.q) : '')
          + '&estado=' + _quizFiltro.estado),
    ]);
    _quizRender(e, l.perguntas || []);
  } catch (err) {
    c.innerHTML = `<div class="alert alert-danger">Erro: ${escapeHtml(err.error || 'Desconhecido')}</div>`;
  }
}

function _quizRender(e, perguntas) {
  const n = e.contagem || {};
  const ab = e.aberta;
  const back = 'showGestao()';
  // Horários vindos do servidor — a tela não tem opinião sobre eles.
  const h = e.horario || { abre: '10:30', fecha: '10:40', minutos: 10 };

  // Antes de montar a tela, não depois: o HTML abaixo já lê _quizGrupos pra
  // trocar o JID pelo nome do grupo na lista de perguntas.
  window._quizCache = perguntas;
  window._quizGrupos = e.grupos || [];
  window._quizPremioPadrao = e.premio;
  window._quizMinutos = h.minutos;

  document.getElementById('mainContainer').innerHTML = `
<div class="mb-4 d-flex align-items-center gap-2 flex-wrap">
  <button class="btn btn-back" onclick="${back}"><i class="bi bi-arrow-left"></i> Voltar</button>
  <h5 class="mb-0" style="color:#a855f7"><i class="bi bi-patch-question-fill me-2"></i>Quiz do grupo</h5>
  <button class="btn-ghost ms-auto" style="color:#25d366;border-color:rgba(37,211,102,.35)" onclick="_quizEditar(null, true)"
          title="Monta a enquete com prêmio e critério e manda no grupo na hora">
    <i class="bi bi-bar-chart-fill me-1"></i>Enquete no grupo
  </button>
</div>

<div class="panel mb-3">
  <div class="panel-header"><div class="panel-title"><i class="bi bi-broadcast"></i> Situação</div></div>
  <div style="padding:14px 18px">
    <div style="font-size:13px;color:var(--text-2);margin-bottom:12px">
      <b>${n.total || 0}</b> perguntas · <b>${n.certas || 0}</b> com resposta certa ·
      <b>${n.votos || 0}</b> de mais votada · <b>${n['inéditas'] || 0}</b> nunca usadas
      ${Number(n.inativas) ? ` · <b>${n.inativas}</b> fora do sorteio` : ''}
      <br>Sai <b>1 por dia às ${escapeHtml(h.abre)}</b> e fecha <b>${escapeHtml(h.fecha)}</b>, valendo <b>${e.premio ?? 100}</b> moedas para cada acerto.
    </div>

    <div class="mb-3" style="font-size:12.5px">
      <div class="d-flex align-items-center gap-2 flex-wrap">
        <span style="color:var(--text-2)"><i class="bi bi-broadcast-pin me-1"></i>O quiz sai em:</span>
        <select class="form-select form-select-sm" style="width:auto;max-width:100%" onchange="_quizGrupoDoQuiz(this)">
          <option value="">Grupo principal (padrão)</option>
          ${(e.grupos || []).filter(g => !g.principal).map(g =>
            `<option value="${escapeHtml(g.jid)}" ${g.do_quiz ? 'selected' : ''}>
               ${escapeHtml(g.nome)}${g.liga ? ' · ' + escapeHtml(g.liga) : ''}</option>`).join('')}
          ${(e.vistos || []).length ? `<optgroup label="Grupos que o bot ouviu (ainda não cadastrados)">
            ${e.vistos.map(v => `<option value="${escapeHtml(v.jid)}">${escapeHtml(v.pista || v.jid).slice(0, 52)}</option>`).join('')}
          </optgroup>` : ''}
        </select>
      </div>
      <div style="color:var(--text-3);font-size:11px;margin-top:5px">
        Está saindo em <b style="color:var(--text-2)">${escapeHtml((e.grupo_quiz && e.grupo_quiz.nome) || 'grupo principal')}</b>.
        ${(e.vistos || []).length ? 'Se o grupo certo não estiver na lista de cima, ele está na de baixo — identificado pela última mensagem que o bot ouviu lá.' : ''}
      </div>
    </div>
    ${(() => {
      // O site só enfileira; quem entrega é o worker, e ele só trabalha dentro
      // da janela. Sem dizer isso aqui, "mandei e não chegou" vira caça ao erro.
      const e2 = e.envio || {};
      if (!e2.ligado) return `<div class="alert alert-danger py-2 px-3" style="font-size:12.5px">
        <b>O bot está desligado.</b> Nada sai do site enquanto ele estiver assim.</div>`;
      if (!e2.na_janela) return `<div class="alert alert-warning py-2 px-3" style="font-size:12.5px">
        <b>Fora do horário do bot</b> (${escapeHtml(e2.inicio)} às ${escapeHtml(e2.fim)}).
        O que você mandar daqui <b>sai na hora</b> — o horário só segura o que é automático,
        pra não cair aviso no grupo de madrugada.
        ${e2.pendentes ? `<br>${e2.pendentes} mensagem(ns) do quiz aguardando na fila desde antes.` : ''}</div>`;
      return e2.pendentes ? `<div class="alert alert-info py-2 px-3" style="font-size:12.5px">
        ${e2.pendentes} mensagem(ns) do quiz na fila, aguardando o worker.</div>` : '';
    })()}
    ${ab ? `
      <div class="alert alert-info py-2 px-3" style="font-size:13px">
        <b>No ar agora:</b> ${escapeHtml(ab.texto)}<br>
        <span style="font-size:12px;opacity:.85">${ab.votos} voto(s) · fecha ${escapeHtml(String(ab.fecha_em).slice(11,16))}</span>
      </div>
      <button class="btn-ghost" style="color:#22c55e"
              onclick="_quizAcao('finalizar','Finalizar o quiz agora?\\n\\nConta os ${ab.votos} voto(s), credita as moedas de quem acertou e posta o resultado no grupo.',{id:${ab.id}})">
        <i class="bi bi-flag-fill me-1"></i>Finalizar e enviar resultado</button>
      <button class="btn-ghost" style="color:#ef4444"
              onclick="_quizAcao('cancelar_rodada','Cancelar esta rodada?\\n\\nA pergunta volta pro sorteio, os ${ab.votos} voto(s) são descartados e NINGUÉM recebe moeda. Não é o mesmo que finalizar.')">
        <i class="bi bi-x-circle me-1"></i>Cancelar sem apurar</button>
      <div style="font-size:11px;color:var(--text-3);margin-top:8px;line-height:1.5">
        O cron das ${escapeHtml(h.fecha)} finaliza sozinho. Estes botões são a garantia pra quando ele falhar
        — ou pra encerrar antes da hora.
      </div>`
    : `<button class="btn-ghost" style="color:#a855f7" onclick="_quizAcao('abrir_agora','Postar a pergunta do dia no grupo agora?')">
         <i class="bi bi-send-fill me-1"></i>Mandar uma pergunta agora</button>`}
    ${!Number(n.total) ? `
      <button class="btn-ghost" style="color:#22c55e" onclick="_quizAcao('popular','Carregar o banco inicial de perguntas?')">
        <i class="bi bi-download me-1"></i>Popular banco inicial</button>` : `
      <button class="btn-ghost" onclick="_quizAcao('popular','Carregar de novo o banco inicial? As que já existem são puladas.')">
        <i class="bi bi-arrow-repeat me-1"></i>Recarregar banco inicial</button>`}
    <!-- "Grupos do bot" e "Diagnóstico" saíram daqui: são do bot inteiro,
         não do quiz, e agora moram no Painel do Bot. -->
    <a class="btn-ghost" href="/painelbot.php" style="color:#25d366"><i class="bi bi-robot me-1"></i>Painel do Bot</a>
  </div>
</div>

${(e.ultimas || []).length ? `
<div class="panel mb-3">
  <div class="panel-header"><div class="panel-title"><i class="bi bi-clock-history"></i> Últimas rodadas</div></div>
  <div style="padding:8px 18px 14px">
    ${e.ultimas.map(r => `
      <div style="display:flex;gap:10px;align-items:center;padding:7px 0;border-bottom:1px solid var(--border);font-size:12.5px">
        <span style="opacity:.6;white-space:nowrap">${escapeHtml(String(r.fechada_em).slice(0,10).split('-').reverse().join('/'))}</span>
        <span style="flex:1">${escapeHtml(r.texto)}</span>
        <span style="opacity:.7;white-space:nowrap">venceu a ${r.vencedora} · ${r.votos} voto(s)</span>
      </div>`).join('')}
  </div>
</div>` : ''}

<div class="panel">
  <div class="panel-header">
    <div class="panel-title"><i class="bi bi-list-ul"></i>
      ${_quizFiltro.estado === 'usadas' ? 'Já foram ao ar' : 'Perguntas na fila'}</div>
    <button class="btn-ghost" style="color:#a855f7" onclick="_quizEditar(null)"><i class="bi bi-plus-lg me-1"></i>Nova</button>
  </div>
  <div style="padding:12px 18px">
    <div class="d-flex gap-2 mb-3 flex-wrap">
      <input type="text" id="_quizBusca" class="form-control form-control-sm" style="flex:1;min-width:180px"
             placeholder="Buscar no texto..." value="${escapeHtml(_quizFiltro.q)}"
             onkeydown="if(event.key==='Enter'){_quizFiltro.q=this.value.trim();showQuizAdmin()}">
      <select class="form-select form-select-sm" style="width:auto" onchange="_quizFiltro.estado=this.value;showQuizAdmin()">
        <option value="disponiveis" ${_quizFiltro.estado==='disponiveis'?'selected':''}>Ainda vão sair (${n['inéditas'] || 0})</option>
        <option value="usadas" ${_quizFiltro.estado==='usadas'?'selected':''}>Já foram ao ar (${n.usadas || 0})</option>
        <option value="todas" ${_quizFiltro.estado==='todas'?'selected':''}>Todas (${n.total || 0})</option>
      </select>
      <select class="form-select form-select-sm" style="width:auto" onchange="_quizFiltro.tipo=this.value;showQuizAdmin()">
        <option value="">Todos os tipos</option>
        <option value="certa" ${_quizFiltro.tipo==='certa'?'selected':''}>Resposta certa</option>
        <option value="votos" ${_quizFiltro.tipo==='votos'?'selected':''}>Mais votada</option>
      </select>
    </div>
    ${perguntas.length ? `
      <table class="table table-dark table-hover" style="font-size:12.5px">
        <thead><tr><th style="width:70px">Tipo</th><th>Pergunta</th><th style="width:110px">Categoria</th><th style="width:130px"></th></tr></thead>
        <tbody>${perguntas.map(p => `
          <tr style="${Number(p.ativa) && !p.usada_em ? '' : 'opacity:.45'}">
            <td><span class="badge" style="background:${p.tipo==='certa'?'rgba(34,197,94,.15);color:#22c55e':'rgba(168,85,247,.15);color:#a855f7'};font-size:10px">
              ${p.tipo==='certa'?'CERTA':'VOTOS'}</span>
              ${p.usada_em ? `<div><span class="badge" style="background:rgba(148,163,184,.15);color:var(--text-3);font-size:9px;margin-top:3px">RESPONDIDA</span></div>` : ''}</td>
            <td>
              <div style="font-weight:600">${escapeHtml(p.texto)}</div>
              <div style="font-size:11px;color:var(--text-3)">${[1,2,3,4].map(i =>
                `${Number(p.correta)===i?'<b style="color:#22c55e">':''}${i}. ${escapeHtml(p['op'+i])}${Number(p.correta)===i?'</b>':''}`).join(' · ')}</div>
              ${(() => {
                // Só marca o que FOGE do padrão — repetir "grupo principal ·
                // 100 moedas" em 188 linhas viraria ruído.
                const marcas = [];
                if (p.grupo_jid) {
                  const g = (window._quizGrupos || []).find(x => x.jid === p.grupo_jid);
                  marcas.push('<i class="bi bi-people-fill"></i> ' + escapeHtml(g ? g.nome : p.grupo_jid));
                }
                if (p.premio) marcas.push('<i class="bi bi-coin"></i> ' + p.premio);
                if (p.usada_em) marcas.push('foi ao ar em ' + escapeHtml(String(p.usada_em).slice(0,10).split('-').reverse().join('/')));
                return marcas.length
                  ? `<div style="font-size:10.5px;color:var(--text-3);margin-top:3px">${marcas.join(' · ')}</div>` : '';
              })()}
            </td>
            <td style="font-size:11.5px;color:var(--text-2)">${escapeHtml(p.categoria || '—')}</td>
            <td style="text-align:right;white-space:nowrap">
              <button class="btn-ghost btn-sm" onclick="_quizEditar(${p.id})" title="Editar"><i class="bi bi-pencil"></i></button>
              ${p.usada_em ? '' : `
                <button class="btn-ghost btn-sm" onclick="_quizAcao('alternar',null,{id:${p.id}})" title="${Number(p.ativa)?'Tirar do sorteio':'Voltar pro sorteio'}">
                  <i class="bi bi-${Number(p.ativa)?'eye-slash':'eye'}"></i></button>`}
              <button class="btn-ghost btn-sm" style="color:#ef4444" onclick="_quizAcao('excluir','Apagar esta pergunta?',{id:${p.id}})" title="Apagar"><i class="bi bi-trash"></i></button>
            </td>
          </tr>`).join('')}</tbody>
      </table>`
    : '<div class="empty-state" style="padding:24px">Nenhuma pergunta. Use "Popular banco inicial" pra começar com 188.</div>'}
  </div>
</div>`;

}

/**
 * Escolhe em qual grupo o quiz do dia sai.
 *
 * Aceita tanto grupo cadastrado quanto grupo que o bot só ouviu — nesse caso
 * o servidor cadastra na hora. Exigir cadastrar antes era o que fazia o
 * seletor abrir com uma opção só e o quiz continuar saindo no lugar errado.
 */
async function _quizGrupoDoQuiz(sel) {
  const jid = sel.value;
  // Da lista de ouvidos vem a pista ("Victor: e aí"), não um nome de grupo —
  // vale mais perguntar como chamar do que gravar isso como nome pra sempre.
  let nome = '';
  if (jid && sel.selectedOptions[0]?.parentElement?.tagName === 'OPTGROUP') {
    nome = await perguntarSite('Como esse grupo se chama? (ex: Chat Off - Geral)', 'Chat Off - Geral') || '';
    if (!nome.trim()) { showQuizAdmin(); return; }
  }
  try {
    const r = await api('quiz-admin.php?action=grupo_quiz_salvar', {
      method: 'POST', body: JSON.stringify({ jid, nome }),
    });
    showAlert('success', r.message);
    showQuizAdmin();
  } catch (e) {
    showAlert('danger', e.error || 'Erro');
    showQuizAdmin();   // volta o seletor pro que está salvo de verdade
  }
}

/** Mostra o que está de fato no servidor, quando algo não funciona. */
async function _quizDiagnostico() {
  try {
    const r = await api('quiz-admin.php?action=diagnostico');
    const linhas = Object.entries(r.diagnostico || {})
      .map(([k, v]) => `${k.padEnd(26)} ${v}`).join('\n');
    alert('DIAGNÓSTICO DO QUIZ\n\n' + linhas);
  } catch (e) {
    alert('O diagnóstico também falhou:\n\n' + (e.error || 'sem detalhe') +
          '\n\nIsso normalmente quer dizer que os arquivos novos não subiram pro servidor.');
  }
}

/**
 * Cadastro dos grupos onde o bot fala.
 *
 * Não é config do quiz, é do bot inteiro — mas é aqui que a falta dela
 * aparece, quando o seletor de grupo da pergunta só oferece o principal.
 */
async function _quizGruposTela() {
  const grupos = window._quizGrupos || [];
  // Os grupos de onde o bot já ouviu alguma coisa mas que ninguém cadastrou.
  let vistos = [];
  try { vistos = (await api('quiz-admin.php?action=grupos_vistos')).vistos || []; } catch (e) { /* tela funciona sem */ }
  document.getElementById('_quizGruposModal')?.remove();
  const modal = document.createElement('div');
  modal.id = '_quizGruposModal';
  modal.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.7);z-index:2000;display:flex;align-items:flex-start;justify-content:center;overflow-y:auto;padding:20px';
  modal.innerHTML = `
    <div class="panel" style="width:100%;max-width:620px;padding:0;margin-top:20px">
      <div class="panel-header" style="padding:16px 18px 0">
        <div class="panel-title"><i class="bi bi-people-fill" style="color:#25d366"></i> Grupos do bot</div>
        <button class="btn-ghost" style="padding:4px 8px" onclick="document.getElementById('_quizGruposModal').remove()"><i class="bi bi-x-lg"></i></button>
      </div>
      <div style="padding:14px 18px">
        <p style="font-size:12.5px;color:var(--text-2);line-height:1.55">
          Onde o bot aceita <code>/comando</code> e pode postar o quiz. A liga do grupo vira
          contexto: no Chat Off da NEXT, <code>/classificacao</code> sem argumento responde a NEXT.
          <br><b>O JID</b> é o identificador do grupo no WhatsApp, terminado em <code>@g.us</code>.
        </p>

        ${grupos.length ? `
          <table class="table table-dark" style="font-size:12.5px">
            <thead><tr><th>Grupo</th><th style="width:80px">Liga</th><th style="width:50px"></th></tr></thead>
            <tbody>${grupos.map(g => `
              <tr>
                <td><div style="font-weight:600">${escapeHtml(g.nome)}${g.principal ? ' <span class="badge" style="background:rgba(37,211,102,.15);color:#25d366;font-size:9.5px">PRINCIPAL</span>' : ''}</div>
                    <div style="font-size:10.5px;color:var(--text-3)">${escapeHtml(g.jid)}</div></td>
                <td>${escapeHtml(g.liga || '—')}</td>
                <td style="text-align:right">${g.principal ? '' :
                  `<button class="btn-ghost btn-sm" style="color:#ef4444" onclick="_quizGrupoRemover('${escapeHtml(g.jid)}')"><i class="bi bi-trash"></i></button>`}</td>
              </tr>`).join('')}</tbody>
          </table>` : '<div class="empty-state" style="padding:16px">Só o grupo principal está configurado.</div>'}

        <div style="border-top:1px solid var(--border);padding-top:14px;margin-top:6px">
          <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--text-3);margin-bottom:8px">
            Grupos que o bot já ouviu</div>
          ${vistos.length ? `
            <div style="font-size:11.5px;color:var(--text-3);margin-bottom:8px">
              Clique pra preencher o formulário abaixo — o JID vem junto.
            </div>
            ${vistos.map(v => `
              <button class="btn-ghost w-100 mb-1" style="text-align:left;padding:8px 10px"
                      onclick="_quizGrupoDoVisto('${escapeHtml(v.jid)}')">
                <div style="font-size:11.5px;color:var(--text-2)">
                  ${escapeHtml(v.ultimo_autor || 'alguém')}: ${escapeHtml(v.ultima_mensagem || '—')}</div>
                <div style="font-size:10.5px;color:var(--text-3)">${escapeHtml(v.jid)} · ${v.mensagens} mensagem(ns)</div>
              </button>`).join('')}`
          : `<div style="font-size:11.5px;color:var(--text-3);line-height:1.55">
               Nenhum ainda. <b>Mande qualquer mensagem no grupo</b> que você quer cadastrar —
               o bot anota o identificador dele sozinho e ele aparece aqui.
               Só funciona com o bot ligado e o webhook apontado.
             </div>`}
        </div>

        <div style="border-top:1px solid var(--border);padding-top:14px;margin-top:12px">
          <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--text-3);margin-bottom:8px">Adicionar grupo</div>
          <div class="d-flex gap-2 flex-wrap">
            <input type="text" id="_qgNome" class="form-control form-control-sm" style="flex:2;min-width:150px" placeholder="Nome (ex: Chat Off - NEXT)">
            <select id="_qgLiga" class="form-select form-select-sm" style="width:110px">
              <option value="">Sem liga</option>
              ${['ELITE','NEXT','RISE','ROOKIE'].map(l => `<option>${l}</option>`).join('')}
            </select>
          </div>
          <input type="text" id="_qgJid" class="form-control form-control-sm mt-2" placeholder="JID do grupo — termina em @g.us">
          <button class="btn-ghost mt-2" style="color:#25d366" onclick="_quizGrupoSalvar()"><i class="bi bi-plus-lg me-1"></i>Adicionar</button>
        </div>
      </div>
    </div>`;
  document.body.appendChild(modal);
}

/** Leva o JID do grupo ouvido pro formulário, que é o campo impossível de digitar. */
function _quizGrupoDoVisto(jid) {
  const campo = document.getElementById('_qgJid');
  campo.value = jid;
  document.getElementById('_qgNome').focus();
}

async function _quizGrupoSalvar() {
  const corpo = {
    jid: document.getElementById('_qgJid').value.trim(),
    nome: document.getElementById('_qgNome').value.trim(),
    liga: document.getElementById('_qgLiga').value,
  };
  try {
    const r = await api('quiz-admin.php?action=grupos_salvar', { method: 'POST', body: JSON.stringify(corpo) });
    document.getElementById('_quizGruposModal')?.remove();
    showAlert('success', r.message);
    showQuizAdmin();
  } catch (e) { showAlert('danger', e.error || 'Erro'); }
}

async function _quizGrupoRemover(jid) {
  if (!await confirmarSite('Tirar esse grupo? O bot para de atender comando nele.')) return;
  try {
    const r = await api('quiz-admin.php?action=grupos_remover', { method: 'POST', body: JSON.stringify({ jid }) });
    document.getElementById('_quizGruposModal')?.remove();
    showAlert('success', r.message);
    showQuizAdmin();
  } catch (e) { showAlert('danger', e.error || 'Erro'); }
}

async function _quizAcao(acao, confirmar, corpo) {
  if (confirmar && !await confirmarSite(confirmar)) return;
  try {
    const r = await api('quiz-admin.php?action=' + acao, {
      method: 'POST', body: JSON.stringify(corpo || {})
    });
    showAlert('success', r.message || 'Feito.');
    showQuizAdmin();
  } catch (e) {
    showAlert('danger', e.error || 'Erro');
  }
}

function _quizEditar(id, soEnviar) {
  const p = id ? (window._quizCache || []).find(x => Number(x.id) === Number(id)) : null;
  const tipo = p?.tipo || 'certa';
  document.getElementById('_quizModal')?.remove();

  const modal = document.createElement('div');
  modal.id = '_quizModal';
  modal.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.7);z-index:2000;display:flex;align-items:flex-start;justify-content:center;overflow-y:auto;padding:20px';
  modal.innerHTML = `
    <div class="panel" style="width:100%;max-width:640px;padding:0;margin-top:20px">
      <div class="panel-header" style="padding:16px 18px 0">
        <div class="panel-title"><i class="bi bi-patch-question-fill" style="color:#a855f7"></i> ${p ? 'Editar' : (soEnviar ? 'Enquete no grupo' : 'Nova')} ${soEnviar && !p ? '' : 'pergunta'}</div>
        <button class="btn-ghost" style="padding:4px 8px" onclick="document.getElementById('_quizModal').remove()"><i class="bi bi-x-lg"></i></button>
      </div>
      <div style="padding:16px 18px">
        <input type="hidden" id="_qId" value="${p?.id || ''}">

        <label class="form-label text-light-gray">Tipo</label>
        <div class="d-flex gap-2 mb-3">
          <label class="btn-ghost" style="flex:1;cursor:pointer;text-align:center">
            <input type="radio" name="_qTipo" value="certa" ${tipo==='certa'?'checked':''} onchange="_quizTrocaTipo()"> Resposta certa
          </label>
          <label class="btn-ghost" style="flex:1;cursor:pointer;text-align:center">
            <input type="radio" name="_qTipo" value="votos" ${tipo==='votos'?'checked':''} onchange="_quizTrocaTipo()"> Mais votada
          </label>
        </div>

        <label class="form-label text-light-gray">Pergunta</label>
        <input type="text" id="_qTexto" class="form-control mb-3" maxlength="400"
               value="${escapeHtml(p?.texto || '')}" placeholder="Ex: Quem era o armador do Lakers em 2010?">

        <label class="form-label text-light-gray">Opções <span id="_qDica" style="font-size:11px;color:var(--text-3)"></span></label>
        ${[1,2,3,4].map(i => `
          <div class="d-flex gap-2 align-items-center mb-2">
            <label class="_qMarca" style="width:30px;text-align:center;cursor:pointer" title="Marcar como certa">
              <input type="radio" name="_qCorreta" value="${i}" ${Number(p?.correta)===i?'checked':''}>
            </label>
            <input type="text" id="_qOp${i}" class="form-control form-control-sm" maxlength="120"
                   value="${escapeHtml(p?.['op'+i] || '')}" placeholder="Opção ${i}">
          </div>`).join('')}

        <div class="row g-2 mt-2">
          <div class="col-5">
            <label class="form-label text-light-gray">Categoria</label>
            <input type="text" id="_qCat" class="form-control form-control-sm" maxlength="40" value="${escapeHtml(p?.categoria || '')}" placeholder="Ex: Draft">
          </div>
          <div class="col-7">
            <label class="form-label text-light-gray">Explicação <span style="font-size:11px;color:var(--text-3)">(sai no resultado)</span></label>
            <input type="text" id="_qExp" class="form-control form-control-sm" maxlength="300" value="${escapeHtml(p?.explicacao || '')}" placeholder="Opcional">
          </div>
        </div>

        <div class="row g-2 mt-2">
          <div class="col-7">
            <label class="form-label text-light-gray">Grupo</label>
            <select id="_qGrupo" class="form-select form-select-sm">
              <option value="">Padrão — grupo principal</option>
              ${(window._quizGrupos || []).map(g =>
                `<option value="${escapeHtml(g.jid)}" ${p?.grupo_jid === g.jid ? 'selected' : ''}>
                   ${escapeHtml(g.nome)}${g.liga ? ' · ' + escapeHtml(g.liga) : ''}</option>`).join('')}
            </select>
          </div>
          <div class="col-5">
            <label class="form-label text-light-gray">Moedas</label>
            <input type="number" id="_qPremio" class="form-control form-control-sm" min="0" max="100000"
                   value="${p?.premio || ''}" placeholder="Padrão: ${window._quizPremioPadrao || 100}">
          </div>
        </div>
        <div style="font-size:11px;color:var(--text-3);margin-top:6px">
          Deixar em branco usa o padrão. Assim, mudando o padrão um dia, estas perguntas acompanham.
        </div>
      </div>
      <div class="d-flex gap-2 justify-content-end align-items-center flex-wrap" style="padding:0 18px 18px">
        <button class="btn-ghost" onclick="document.getElementById('_quizModal').remove()">Cancelar</button>
        <button class="btn-ghost" style="color:#25d366" onclick="_quizSalvar(true)"
                title="Posta no grupo agora, sem esperar o horário do sorteio">
          <i class="bi bi-send-fill me-1"></i>Salvar e enviar agora</button>
        ${soEnviar ? "" : `<button class="btn-ghost" style="color:#a855f7" onclick="_quizSalvar()"><i class="bi bi-check-lg me-1"></i>Salvar na fila</button>`}
      </div>
    </div>`;
  document.body.appendChild(modal);
  _quizTrocaTipo();
}

/** Nas de mais votada não existe resposta certa — os marcadores somem. */
function _quizTrocaTipo() {
  const tipo = document.querySelector('input[name="_qTipo"]:checked')?.value || 'certa';
  const certa = tipo === 'certa';
  document.querySelectorAll('._qMarca').forEach(m => { m.style.visibility = certa ? '' : 'hidden'; });
  const dica = document.getElementById('_qDica');
  if (dica) dica.textContent = certa ? '— marque a bolinha da resposta certa' : '— vence a mais votada, não há certa';
}

async function _quizSalvar(enviarAgora) {
  const tipo = document.querySelector('input[name="_qTipo"]:checked')?.value || 'certa';
  const corpo = {
    id: Number(document.getElementById('_qId').value) || 0,
    tipo,
    texto: document.getElementById('_qTexto').value.trim(),
    opcoes: [1,2,3,4].map(i => document.getElementById('_qOp'+i).value.trim()),
    categoria: document.getElementById('_qCat').value.trim(),
    explicacao: document.getElementById('_qExp').value.trim(),
    grupo_jid: document.getElementById('_qGrupo').value,
    premio: Number(document.getElementById('_qPremio').value) || 0,
    correta: tipo === 'certa' ? Number(document.querySelector('input[name="_qCorreta"]:checked')?.value || 0) : null,
  };
  // Ir pro grupo é irreversível: sai pra todo mundo na hora. Confirmar aqui
  // custa um clique e evita mandar a pergunta ainda pela metade.
  if (enviarAgora) {
    const onde = document.getElementById('_qGrupo').selectedOptions[0]?.textContent.trim() || 'grupo principal';
    if (!await confirmarSite(`Postar esta pergunta agora em "${onde}"?\n\nEla fecha ${window._quizMinutos || 10} minutos depois e não volta a sair no sorteio.`)) return;
  }

  const acao = enviarAgora ? 'salvar_e_enviar' : 'salvar';
  try {
    const r = await api('quiz-admin.php?action=' + acao, { method: 'POST', body: JSON.stringify(corpo) });
    document.getElementById('_quizModal')?.remove();
    showAlert('success', r.message || 'Salvo.');
    showQuizAdmin();
  } catch (e) {
    showAlert('danger', e.error || 'Erro');
  }
}

async function syncFotosNBA() {
  const btn = document.getElementById('btnSyncFotos');
  if (!btn) return;
  const original = btn.innerHTML;
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Sincronizando…';
  try {
    const r = await api('admin.php?action=sync_fotos');
    const semCorresp = r.sem_correspondencia || [];
    let msg = `${r.atualizados} de ${r.total_verificados} jogador(es) sem foto foram atualizados.`;
    if (semCorresp.length) {
      msg += ` ${semCorresp.length} sem correspondência no cadastro da NBA.`;
      console.warn('Sem correspondência na NBA:', semCorresp);
    }
    showAlert(semCorresp.length && !r.atualizados ? 'warning' : 'success', msg);
  } catch (e) {
    showAlert('danger', 'Erro ao sincronizar: ' + (e.error || 'Desconhecido'));
  } finally {
    btn.disabled = false;
    btn.innerHTML = original;
  }
}

async function loadMaintenanceStatus() {
  const el = document.getElementById('maintenanceBanner');
  if (!el) return;
  try {
    const data = await api('admin.php?action=maintenance_status');
    renderMaintenanceBanner(data);
  } catch (e) {
    el.innerHTML = '';
  }
}

function renderMaintenanceBanner(data) {
  const el = document.getElementById('maintenanceBanner');
  if (!el) return;
  const on = !!data.enabled;
  const sub = on
    ? `Ativado por ${escapeHtml(data.enabled_by_name || '—')} em ${data.enabled_at ? new Date(data.enabled_at.replace(' ', 'T')).toLocaleString('pt-BR') : '—'}`
    : 'O site está funcionando normalmente para todos os usuários.';
  el.innerHTML = `
    <div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap;justify-content:space-between;
      background:${on ? 'rgba(239,68,68,.1)' : 'var(--panel)'};
      border:1px solid ${on ? 'rgba(239,68,68,.35)' : 'var(--border)'};
      border-radius:12px;padding:14px 18px;">
      <div style="display:flex;align-items:center;gap:12px;min-width:0">
        <i class="bi ${on ? 'bi-cone-striped' : 'bi-check-circle-fill'}" style="font-size:20px;color:${on ? '#ef4444' : '#22c55e'}"></i>
        <div style="min-width:0">
          <div style="font-weight:700;font-size:13px;color:var(--text)">Modo Manutenção: ${on ? 'ATIVO' : 'Desativado'}</div>
          <div style="font-size:11px;color:var(--text-3)">${sub}</div>
        </div>
      </div>
      <button class="btn btn-sm ${on ? 'btn-outline-orange' : 'btn-orange'}" style="flex-shrink:0" onclick="toggleMaintenanceMode(${on ? 'false' : 'true'})">
        <i class="bi ${on ? 'bi-play-fill' : 'bi-cone-striped'} me-1"></i>${on ? 'Desativar manutenção' : 'Ativar manutenção'}
      </button>
    </div>`;
}

async function toggleMaintenanceMode(enable) {
  let message = '';
  if (enable) {
    const confirmMsg = 'Isso vai bloquear o app inteiro para todo mundo, exceto admins gerais. Tem certeza?';
    if (!await confirmarSite(confirmMsg)) return;
    message = await perguntarSite('Mensagem opcional para exibir na página de manutenção (deixe em branco pra usar o texto padrão):', '') || '';
  } else {
    if (!await confirmarSite('Desativar o modo manutenção e liberar o site para todo mundo?')) return;
  }
  try {
    const data = await api('admin.php?action=toggle_maintenance', {
      method: 'POST',
      body: JSON.stringify({ enabled: enable, message })
    });
    showAlert('success', enable ? 'Modo manutenção ativado.' : 'Modo manutenção desativado.');
    loadMaintenanceStatus();
  } catch (e) {
    alert(e.error || 'Erro ao atualizar modo manutenção');
  }
}

// ── Busca da Gestão ───────────────────────────────────────────────
// A lista da aba é sempre de UMA liga. Buscar só nela obriga a saber de
// antemão onde a pessoa está, que é justamente o que a busca deveria evitar.
// Então a busca varre as quatro ligas: carrega as outras uma vez, guarda, e
// marca a liga de cada resultado. Campo vazio volta pra liga da aba.
async function carregarTodasAsLigas() {
  if (_gestaoTodos) return _gestaoTodos;
  const ligas = (typeof _leagues !== 'undefined' && _leagues.length)
    ? _leagues : ['ELITE', 'NEXT', 'RISE', 'ROOKIE'];
  const partes = await Promise.all(ligas.map(lg =>
    api(`admin.php?action=get_users&league=${lg}`)
      .then(d => (d.users || []).map(u => ({ ...u, _liga: lg })))
      .catch(() => [])));
  // Um mesmo usuário não deve aparecer duas vezes se figurar em duas listas.
  const porId = new Map();
  partes.flat().forEach(u => { if (!porId.has(u.id)) porId.set(u.id, u); });
  _gestaoTodos = [...porId.values()];
  return _gestaoTodos;
}

async function aoBuscarGestao() {
  const campo = document.getElementById('gestaoBusca');
  const termo = (campo?.value || '').trim().toLowerCase();

  if (!termo) {
    _gestaoBuscando = false;
    renderGestaoTable(_gestaoUsers);
    return;
  }

  _gestaoBuscando = true;
  const container = document.getElementById('gestaoTableContainer');
  if (!_gestaoTodos) {
    container.innerHTML = '<div class="text-center py-4"><div class="spinner-border text-orange"></div></div>';
  }
  const todos = await carregarTodasAsLigas();

  // Se o campo mudou enquanto carregava, quem manda é o valor atual.
  if ((campo?.value || '').trim().toLowerCase() !== termo) return;

  const bate = u => {
    const alvo = [u.name, u.email, u.team_city, u.team_name, u.phone, u.phone_formatado]
      .filter(Boolean).join(' ').toLowerCase();
    // Achar pelo telefone tem que funcionar do jeito que a pessoa digita: com
    // parênteses e traço, ou só os dígitos. Por isso os dois formatos entram,
    // e o termo também é comparado sem pontuação.
    const soDigitos = termo.replace(/\D+/g, '');
    return alvo.indexOf(termo) >= 0
        || (soDigitos.length >= 4 && alvo.replace(/\D+/g, '').indexOf(soDigitos) >= 0);
  };
  renderGestaoTable(todos.filter(bate), termo);
}

/**
 * O selo de WhatsApp na linha do GM: verde quando o bot consegue marcar a
 * pessoa, vermelho quando não.
 *
 * O veredito vem do servidor (whatsappNumeroUsavel), não daqui — é a mesma
 * regra que decide se a menção sai etiquetada ou vira texto solto no grupo.
 * É conferência de FORMA: só o WhatsApp sabe se a conta existe, mas número
 * mal formado nunca funciona, e foi o que explicou todos os casos até agora.
 */
function selo(u) {
  if (!u.phone_formatado) {
    return '<span style="opacity:.55"><i class="bi bi-whatsapp"></i> sem número</span>';
  }
  const c = u.phone_check || {};
  const num = escapeHtml(u.phone_formatado);
  if (c.ok && !c.motivo) {
    return `<i class="bi bi-whatsapp" style="color:#25d366"></i> ${num}
      <i class="bi bi-check-circle-fill" style="color:#25d366;margin-left:3px" title="O bot consegue marcar esta pessoa no grupo."></i>`;
  }
  const cor = c.ok ? 'var(--amber, #f59e0b)' : '#ef4444';
  const icone = c.ok ? 'exclamation-triangle-fill' : 'x-circle-fill';
  const dica = escapeHtml((c.motivo || 'Número que o WhatsApp não reconhece.')
    + (c.sugestao ? ` O correto provavelmente é ${c.sugestao}.` : ''));
  return `<i class="bi bi-whatsapp" style="color:${cor}"></i>
    <span style="color:${cor}">${num}</span>
    <i class="bi bi-${icone}" style="color:${cor};margin-left:3px" title="${dica}"></i>`;
}

function renderGestaoTable(users, termoBusca) {
  const container = document.getElementById('gestaoTableContainer');
  if (!users.length) {
    container.innerHTML = termoBusca
      ? `<div class="alert alert-info">Nenhum usuário ou time encontrado para “${escapeHtml(termoBusca)}” em nenhuma liga.</div>`
      : '<div class="alert alert-info">Nenhum usuário nesta liga.</div>';
    return;
  }

  const rows = users.map(u => {
    const leagueBadges = (u.admin_leagues || []).map(l =>
      `<span class="badge bg-gradient-orange me-1" style="font-size:10px">${l}</span>`).join('') || '<span class="text-muted" style="font-size:12px">—</span>';
    const globalAdminCell = window.IS_GLOBAL_ADMIN
      ? `<div class="form-check form-switch m-0" title="Ativar/desativar Admin Geral">
          <input class="form-check-input" type="checkbox" role="switch" ${u.user_type === 'admin' ? 'checked' : ''} onchange="toggleGlobalAdmin(${u.id}, this.checked, this)">
        </div>`
      : (u.user_type === 'admin'
          ? `<span class="badge" style="font-size:10px;background:var(--red,#fc0025);color:#fff">GERAL</span>`
          : '<span class="text-muted" style="font-size:12px">—</span>');
    const teamPhoto = u.team_photo
      ? `<img src="${escapeHtml(u.team_photo)}" style="width:30px;height:30px;border-radius:8px;object-fit:cover;border:1px solid var(--border)" onerror="this.style.display='none'">`
      : `<div style="width:30px;height:30px;border-radius:8px;background:var(--panel-3);border:1px solid var(--border);display:flex;align-items:center;justify-content:center"><i class="bi bi-people" style="font-size:14px;color:var(--text-3)"></i></div>`;
    const teamName = u.team_city
      ? `${escapeHtml(u.team_city)} ${escapeHtml(u.team_name || '')}`
      : (u.team_name ? escapeHtml(u.team_name) : '<span class="text-muted">—</span>');
    const teamLabelPlain = (u.team_city ? `${u.team_city} ${u.team_name || ''}` : (u.team_name || '')).trim().replace(/'/g, "\\'");
    const gmNamePlain = escapeHtml(u.name).replace(/'/g, "\\'");

    return `
      <tr>
        <td data-label="Usuário">
          <div>
            <div style="font-weight:600">${escapeHtml(u.name)}${
              // Na busca por todas as ligas, sem isto não dá pra saber de onde
              // veio cada resultado.
              (termoBusca && u._liga)
                ? ` <span style="font-size:9.5px;font-weight:800;letter-spacing:.5px;padding:2px 6px;border-radius:999px;background:var(--panel-3);color:var(--text-2);border:1px solid var(--border);vertical-align:middle">${escapeHtml(u._liga)}</span>`
                : ''}</div>
            <div style="font-size:11px;color:var(--text-3)">${escapeHtml(u.email)}</div>
            <div style="font-size:11px;color:var(--text-3)">${selo(u)}</div>
          </div>
        </td>
        <td data-label="Time">
          <div class="d-flex align-items-center gap-2">
            ${teamPhoto}
            <span style="font-size:13px">${teamName}</span>
          </div>
        </td>
        <td data-label="Ligas Admin">${leagueBadges}</td>
        <td data-label="Admin Geral">${globalAdminCell}</td>
        <td class="gestao-actions">
          <div class="d-flex gap-1">
            <button class="btn btn-sm btn-outline-orange" onclick="openGestaoEdit(${u.id})" title="Editar">
              <i class="bi bi-pencil-fill"></i>
            </button>
            ${!u.team_id ? `<button class="btn btn-sm btn-outline-danger" onclick="deleteGestaoUser(${u.id}, '${escapeHtml(u.name).replace(/'/g, "\\'")}')" title="Apagar usuário (sem time)">
              <i class="bi bi-trash-fill"></i>
            </button>` : `<button class="btn btn-sm btn-outline-danger" onclick="deleteGestaoTeam(${u.team_id}, '${teamLabelPlain}', '${gmNamePlain}')" title="Apagar time e o GM dono">
              <i class="bi bi-x-octagon-fill"></i>
            </button>`}
          </div>
        </td>
      </tr>`;
  }).join('');

  container.innerHTML = `
    <div class="table-responsive">
      <table class="table table-dark table-hover gestao-table" style="font-size:13px">
        <thead>
          <tr>
            <th>Usuário</th>
            <th>Time</th>
            <th>Ligas Admin</th>
            <th>Admin Geral</th>
            <th style="width:60px"></th>
          </tr>
        </thead>
        <tbody>${rows}</tbody>
      </table>
    </div>`;
}

async function toggleGlobalAdmin(userId, checked, checkboxEl) {
  try {
    await api('admin.php?action=set_global_admin', {
      method: 'POST',
      body: JSON.stringify({ user_id: userId, is_admin: checked })
    });
    const u = _gestaoUsers.find(x => x.id == userId);
    if (u) u.user_type = checked ? 'admin' : 'jogador';
    showAlert('success', checked ? 'Admin Geral ativado.' : 'Admin Geral desativado.');
  } catch (e) {
    if (checkboxEl) checkboxEl.checked = !checked;
    showAlert('danger', 'Erro: ' + (e.error || 'Desconhecido'));
  }
}

function openGestaoEdit(userId) {
  const u = _gestaoUsers.find(x => x.id == userId);
  if (!u) return;

  const allLeagues = ['ELITE','NEXT','RISE','ROOKIE'];
  const conf = (u.team_conference || '').toUpperCase();
  const teamNameField = u.team_id ? `
    <div class="row g-2 mb-3">
      <div class="col-12 col-sm-7">
        <label class="form-label text-light-gray">Nome do Time</label>
        <input type="text" id="gedit-team-name" class="form-control" value="${escapeHtml(u.team_name || '')}">
      </div>
      <div class="col-12 col-sm-5">
        <label class="form-label text-light-gray">Cidade</label>
        <input type="text" id="gedit-team-city" class="form-control" value="${escapeHtml(u.team_city || '')}">
      </div>
      <div class="col-12">
        <label class="form-label text-light-gray">Conferência</label>
        <select id="gedit-team-conference" class="form-select">
          <option value="" ${!conf ? 'selected' : ''}>Sem conferência</option>
          <option value="LESTE" ${conf === 'LESTE' ? 'selected' : ''}>Leste</option>
          <option value="OESTE" ${conf === 'OESTE' ? 'selected' : ''}>Oeste</option>
        </select>
      </div>
    </div>` : '';
  const leagueOptions = (selected) => allLeagues.map(l => `<option value="${l}" ${selected === l ? 'selected' : ''}>${l}</option>`).join('');
  const leaguesMismatched = u.team_id && u.team_league && u.league && u.team_league !== u.league;
  let leagueField;
  if (!u.team_id) {
    // Sem time vinculado: só existe a liga do usuário.
    leagueField = `
      <div class="mb-3">
        <label class="form-label text-light-gray">Liga do Usuário</label>
        <select id="gedit-user-league" class="form-select">${leagueOptions(u.league)}</select>
      </div>`;
  } else {
    // Sempre mostra os dois campos, lado a lado, pra dar pra conferir e
    // corrigir cada um independentemente (usuário pode estar em uma liga e o
    // time em outra por bug de dados).
    const warningBanner = leaguesMismatched ? `
      <div class="alert alert-warning py-2 px-3 mb-2" style="font-size:12px">
        <i class="bi bi-exclamation-triangle-fill me-1"></i>Usuário e time estão em ligas diferentes. Corrija abaixo.
      </div>` : '';
    leagueField = `
      ${warningBanner}
      <div class="row g-2 mb-3">
        <div class="col-6">
          <label class="form-label text-light-gray">Liga do Usuário</label>
          <select id="gedit-user-league" class="form-select">${leagueOptions(u.league)}</select>
        </div>
        <div class="col-6">
          <label class="form-label text-light-gray">Liga do Time</label>
          <select id="gedit-team-league" class="form-select">${leagueOptions(u.team_league || u.league)}</select>
        </div>
      </div>`;
  }
  const adminChecks = window.IS_GLOBAL_ADMIN ? allLeagues.map(l => `
    <div class="form-check form-check-inline">
      <input class="form-check-input" type="checkbox" id="ck-${l}" value="${l}" ${(u.admin_leagues||[]).includes(l) ? 'checked' : ''}>
      <label class="form-check-label" for="ck-${l}">${l}</label>
    </div>`).join('') : '';

  const resetBtn = window.IS_GLOBAL_ADMIN ? `
    <button type="button" class="btn btn-outline-warning w-100 mt-2" onclick="confirmResetPassword(${u.id}, '${escapeHtml(u.name)}')">
      <i class="bi bi-key-fill me-1"></i>Redefinir senha
    </button>` : '';

  const modalHtml = `
    <div class="modal fade" id="gestaoEditModal" tabindex="-1">
      <div class="modal-dialog">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title"><i class="bi bi-person-gear me-2" style="color:var(--red)"></i>Editar Usuário</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <input type="hidden" id="gedit-user-id" value="${u.id}">
            <input type="hidden" id="gedit-team-id" value="${u.team_id || ''}">

            <div class="mb-3">
              <label class="form-label text-light-gray">Nome</label>
              <input type="text" id="gedit-name" class="form-control" value="${escapeHtml(u.name)}">
            </div>
            <div class="row g-2 mb-3">
              <div class="col-12 col-sm-7">
                <label class="form-label text-light-gray">E-mail</label>
                <input type="email" id="gedit-email" class="form-control" value="${escapeHtml(u.email)}">
              </div>
              <div class="col-12 col-sm-5">
                <label class="form-label text-light-gray">Telefone</label>
                <input type="tel" id="gedit-phone" class="form-control" placeholder="(11) 98765-4321"
                       value="${escapeHtml(u.phone_formatado || '')}">
                <div style="font-size:11px;margin-top:4px" id="gedit-phone-aviso">${(() => {
                  if (!u.phone) return '<span style="color:var(--text-3)">Sem número: o bot cita o nome sem marcar.</span>';
                  const c = u.phone_check || {};
                  if (c.ok && !c.motivo) return '<span style="color:#25d366"><i class="bi bi-check-circle-fill"></i> O bot consegue marcar no grupo.</span>';
                  const cor = c.ok ? 'var(--amber, #f59e0b)' : '#ef4444';
                  // O botão de aplicar só aparece quando o servidor tem certeza
                  // do conserto — palpite que continua quebrado é pior que nada.
                  const fix = c.sugestao
                    ? ` <a href="#" onclick="aplicarSugestaoFone('${c.sugestao}');return false" style="color:${cor};text-decoration:underline">usar ${escapeHtml(c.sugestao)}</a>`
                    : '';
                  return `<span style="color:${cor}"><i class="bi bi-exclamation-triangle-fill"></i> ${escapeHtml(c.motivo || '')}</span>${fix}`;
                })()}</div>
              </div>
            </div>
            <div class="mb-3">
              <label class="form-label text-light-gray">Logo do Time</label>
              <div class="d-flex align-items-center gap-3">
                <div style="width:60px;height:60px;border-radius:10px;background:var(--panel-3);border:1px solid var(--border);overflow:hidden;flex-shrink:0;display:flex;align-items:center;justify-content:center">
                  <img id="gedit-photo-preview" src="${escapeHtml(u.team_photo || '')}"
                       style="width:100%;height:100%;object-fit:cover;${u.team_photo ? '' : 'display:none'}"
                       onerror="this.style.display='none'">
                  <i class="bi bi-image" id="gedit-photo-placeholder" style="font-size:22px;color:var(--text-3);${u.team_photo ? 'display:none' : ''}"></i>
                </div>
                <div>
                  <label class="btn-ghost" style="cursor:pointer;display:inline-flex;align-items:center;gap:6px;padding:7px 13px;font-size:12px">
                    <i class="bi bi-upload"></i> Enviar nova logo
                    <input type="file" id="gedit-team-photo-file" accept="image/png,image/jpeg,image/webp" style="display:none" onchange="onGestaoPhotoChange(this)">
                  </label>
                  <input type="hidden" id="gedit-team-photo" value="${escapeHtml(u.team_photo || '')}">
                  <div id="gedit-photo-name" style="font-size:11px;color:var(--text-3);margin-top:4px">${u.team_photo ? 'Logo atual salva' : 'Sem logo'}</div>
                </div>
              </div>
            </div>
            ${teamNameField}
            ${leagueField}
            ${window.IS_GLOBAL_ADMIN ? `
            <div class="mb-3">
              <label class="form-label text-light-gray">Ligas Admin</label>
              <div class="d-flex flex-wrap gap-2 mt-1">${adminChecks}</div>
              <div style="font-size:11px;color:var(--text-3);margin-top:4px">O Admin Geral (acesso total) é ativado direto na tabela, sem precisar abrir esta edição.</div>
            </div>` : ''}
            ${resetBtn}
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
            <button type="button" class="btn btn-orange" onclick="saveGestaoUser()">
              <i class="bi bi-save me-1"></i>Salvar
            </button>
          </div>
        </div>
      </div>
    </div>`;

  // O modal anterior só se remove no 'hidden', que leva o tempo da animação.
  // Abrir dois usuários em seguida deixava os dois no DOM, e getElementById
  // pega o PRIMEIRO — o formulário lido no salvar era o do usuário antigo.
  document.getElementById('gestaoEditModal')?.remove();

  document.body.insertAdjacentHTML('beforeend', modalHtml);
  const modal = new bootstrap.Modal(document.getElementById('gestaoEditModal'));
  modal.show();
  document.getElementById('gestaoEditModal').addEventListener('hidden.bs.modal', function() { this.remove(); });
}

/** Joga o número sugerido no campo. Só salva quando o admin clicar em Salvar. */
function aplicarSugestaoFone(numero) {
  const campo = document.getElementById('gedit-phone');
  if (!campo) return;
  campo.value = numero;
  campo.focus();
  const aviso = document.getElementById('gedit-phone-aviso');
  if (aviso) aviso.innerHTML = '<span style="color:var(--text-3)">Clique em Salvar para gravar.</span>';
}

function onGestaoPhotoChange(input) {
  const file = input.files[0];
  if (!file) return;
  const reader = new FileReader();
  reader.onload = e => {
    const data = e.target.result;
    document.getElementById('gedit-team-photo').value = data;
    const preview = document.getElementById('gedit-photo-preview');
    if (preview) { preview.src = data; preview.style.display = ''; }
    const ph = document.getElementById('gedit-photo-placeholder');
    if (ph) ph.style.display = 'none';
    const nameEl = document.getElementById('gedit-photo-name');
    if (nameEl) nameEl.textContent = file.name;
  };
  reader.readAsDataURL(file);
}

async function saveGestaoUser() {
  const userId   = parseInt(document.getElementById('gedit-user-id').value);
  const teamId   = parseInt(document.getElementById('gedit-team-id').value) || null;
  const name     = document.getElementById('gedit-name').value.trim();
  const email    = document.getElementById('gedit-email').value.trim();
  const phone    = document.getElementById('gedit-phone').value.trim();
  const teamPhoto = document.getElementById('gedit-team-photo').value.trim();
  const teamNameEl = document.getElementById('gedit-team-name');
  const teamName = teamNameEl ? teamNameEl.value.trim() : '';
  const teamCityEl = document.getElementById('gedit-team-city');
  const teamCity = teamCityEl ? teamCityEl.value.trim() : '';
  // Só vai no corpo se o campo existir (usuário sem time não tem): assim
  // salvar um usuário avulso não zera conferência de time nenhum.
  const teamConfEl = document.getElementById('gedit-team-conference');

  const userLeagueEl = document.getElementById('gedit-user-league');
  const teamLeagueEl = document.getElementById('gedit-team-league');
  const userLeague = userLeagueEl ? userLeagueEl.value : '';
  const teamLeague = teamLeagueEl ? teamLeagueEl.value : '';

  try {
    await api('admin.php?action=update_user', {
      method: 'POST',
      body: JSON.stringify(Object.assign(
        { user_id: userId, team_id: teamId, name, email, phone, team_photo: teamPhoto,
          team_name: teamName, team_city: teamCity, user_league: userLeague, team_league: teamLeague },
        teamConfEl ? { team_conference: teamConfEl.value } : {}))
    });

    if (window.IS_GLOBAL_ADMIN) {
      const leagues = Array.from(document.querySelectorAll('#gestaoEditModal #ck-ELITE, #gestaoEditModal #ck-NEXT, #gestaoEditModal #ck-RISE, #gestaoEditModal #ck-ROOKIE')).filter(c => c.checked).map(c => c.value);
      await api('admin.php?action=set_user_league_admin', {
        method: 'POST',
        body: JSON.stringify({ user_id: userId, leagues })
      });
    }

    bootstrap.Modal.getInstance(document.getElementById('gestaoEditModal'))?.hide();
    showAlert('success', 'Usuário atualizado!');
    showGestao(_gestaoLeague);
  } catch (e) {
    showAlert('danger', 'Erro: ' + (e.error || 'Desconhecido'));
  }
}

async function deleteGestaoUser(userId, userName) {
  if (!await confirmarSite(`Apagar o usuário "${userName}"? Essa ação não pode ser desfeita.`)) return;
  try {
    await api(`admin.php?action=user&id=${userId}`, { method: 'DELETE' });
    showAlert('success', 'Usuário apagado!');
    showGestao(_gestaoLeague);
  } catch (e) {
    showAlert('danger', 'Erro: ' + (e.error || 'Desconhecido'));
  }
}

async function deleteGestaoTeam(teamId, teamName, gmName) {
  if (!await confirmarSite(`Apagar o time "${teamName}" e o GM "${gmName}"?\n\nIsso apaga o elenco, picks, trocas e a conta de login do GM. Essa ação não pode ser desfeita.`)) return;
  try {
    await api(`admin.php?action=team_and_owner&team_id=${teamId}`, { method: 'DELETE' });
    showAlert('success', 'Time e GM apagados!');
    showGestao(_gestaoLeague);
  } catch (e) {
    showAlert('danger', 'Erro: ' + (e.error || 'Desconhecido'));
  }
}

function ensureCreateGmModal() {
  if (document.getElementById('createGmModal')) return;
  const modal = document.createElement('div');
  modal.className = 'modal fade';
  modal.id = 'createGmModal';
  modal.tabIndex = -1;
  modal.innerHTML = `
    <div class="modal-dialog">
      <div class="modal-content bg-dark border-orange">
        <div class="modal-header border-orange">
          <h5 class="modal-title text-white"><i class="bi bi-person-plus-fill me-2" style="color:#22c55e"></i>Adicionar GM</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label text-light-gray">Liga</label>
            <select id="cgm-league" class="form-select" onchange="toggleCgmTeamFields()">
              ${_leagues.map(lg => `<option value="${lg}">${lg}</option>`).join('')}
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label text-light-gray">Nome do GM</label>
            <input type="text" id="cgm-name" class="form-control">
          </div>
          <div class="mb-3">
            <label class="form-label text-light-gray">E-mail</label>
            <input type="email" id="cgm-email" class="form-control">
          </div>
          <div id="cgm-team-fields">
            <div class="mb-3">
              <label class="form-label text-light-gray">Nome do Time</label>
              <input type="text" id="cgm-team-name" class="form-control">
            </div>
            <div class="mb-3">
              <label class="form-label text-light-gray">Cidade do Time</label>
              <input type="text" id="cgm-team-city" class="form-control">
            </div>
          </div>
          <div class="mb-3" id="cgm-nba-field" style="display:none">
            <label class="form-label text-light-gray">Time da NBA</label>
            <select id="cgm-nba-team" class="form-select">
              <option value="">Escolha um time...</option>
            </select>
            <div style="font-size:11px;color:var(--text-3);margin-top:4px">ROOKIE não tem time fictício — é sempre um time real da NBA, um por GM.</div>
          </div>
          <div style="font-size:11px;color:var(--text-3)">
            Senha padrão: <strong>fbabrasil123</strong> — enviada por e-mail junto com o link de login. O GM pode trocar depois.
          </div>
        </div>
        <div class="modal-footer border-orange">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="button" class="btn btn-orange" id="cgm-submit-btn" onclick="submitCreateGm()">
            <i class="bi bi-check-lg me-1"></i>Criar GM
          </button>
        </div>
      </div>
    </div>
  `;
  document.body.appendChild(modal);
}

function openCreateGmModal() {
  ensureCreateGmModal();
  document.getElementById('cgm-league').value = _gestaoLeague || _leagues[0];
  document.getElementById('cgm-name').value = '';
  document.getElementById('cgm-email').value = '';
  document.getElementById('cgm-team-name').value = '';
  document.getElementById('cgm-team-city').value = '';
  toggleCgmTeamFields();
  new bootstrap.Modal(document.getElementById('createGmModal')).show();
}

let _nbaTeamsCache = null;

function toggleCgmTeamFields() {
  const isRookie = document.getElementById('cgm-league').value === 'ROOKIE';
  document.getElementById('cgm-team-fields').style.display = isRookie ? 'none' : '';
  document.getElementById('cgm-nba-field').style.display = isRookie ? '' : 'none';
  if (isRookie) populateCgmNbaSelect();
}
window.toggleCgmTeamFields = toggleCgmTeamFields;

async function populateCgmNbaSelect() {
  const sel = document.getElementById('cgm-nba-team');
  sel.innerHTML = '<option value="">Carregando...</option>';
  try {
    if (!_nbaTeamsCache) _nbaTeamsCache = await api('admin.php?action=nba_teams');
    const taken = new Set((_nbaTeamsCache.taken || []).map(Number));
    const byConf = { LESTE: [], OESTE: [] };
    (_nbaTeamsCache.teams || []).forEach(t => byConf[t.conference]?.push(t));
    const optGroup = (label, list) => `<optgroup label="${label}">${list.map(t => {
      const isTaken = taken.has(t.id);
      return `<option value="${t.id}" ${isTaken ? 'disabled' : ''}>${t.city} ${t.name}${isTaken ? ' — já escolhido' : ''}</option>`;
    }).join('')}</optgroup>`;
    sel.innerHTML = '<option value="">Escolha um time...</option>'
      + optGroup('Conferência Leste', byConf.LESTE) + optGroup('Conferência Oeste', byConf.OESTE);
  } catch (e) {
    sel.innerHTML = '<option value="">Erro ao carregar times</option>';
  }
}

async function submitCreateGm() {
  const name = document.getElementById('cgm-name').value.trim();
  const email = document.getElementById('cgm-email').value.trim();
  const league = document.getElementById('cgm-league').value;
  const isRookie = league === 'ROOKIE';
  const teamName = document.getElementById('cgm-team-name').value.trim();
  const teamCity = document.getElementById('cgm-team-city').value.trim();
  const nbaTeamId = document.getElementById('cgm-nba-team').value;

  if (!name || !email || (isRookie ? !nbaTeamId : (!teamName || !teamCity))) {
    alert(isRookie ? 'Preencha nome, e-mail e escolha um time da NBA.' : 'Preencha todos os campos.');
    return;
  }

  const btn = document.getElementById('cgm-submit-btn');
  btn.disabled = true;
  const orig = btn.innerHTML;
  btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Criando...';
  try {
    const payload = isRookie
      ? { name, email, league, nba_team_id: nbaTeamId }
      : { name, email, league, team_name: teamName, team_city: teamCity };
    const data = await api('admin.php?action=create_gm', {
      method: 'POST',
      body: JSON.stringify(payload)
    });
    bootstrap.Modal.getInstance(document.getElementById('createGmModal'))?.hide();
    showAlert('success', data.email_sent
      ? 'GM criado e e-mail enviado!'
      : 'GM criado, mas o e-mail não pôde ser enviado — repasse o login e a senha (fbabrasil123) manualmente.');
    _nbaTeamsCache = null; // próxima abertura busca de novo (time escolhido agora conta como ocupado)
    showGestao(league);
  } catch (e) {
    alert('Erro ao criar GM: ' + (e.error || 'Desconhecido'));
  } finally {
    btn.disabled = false;
    btn.innerHTML = orig;
  }
}

/* ── REDEFINIR SENHA ─────────────────────────────────────────────────────
 *
 * O confirm() e o alert() do navegador saíram daqui, e não foi só estética:
 *
 * 1. A senha aparecia dentro de um alert(), de onde não dá pra copiar em
 *    celular nenhum. Quem redefinia tinha que LER e DIGITAR uma sequência de
 *    dez caracteres hexadecimais no WhatsApp, sem errar.
 * 2. O alert() é do navegador, não do site: no Chrome ele ganha a caixinha
 *    "não deixar este site criar mais diálogos" e, uma vez marcada, o
 *    PRÓXIMO alert simplesmente não aparece — a senha era gerada, a senha
 *    antiga já tinha morrido, e ninguém via a nova.
 *
 * Agora é um modal do site, com a senha num campo selecionável e um botão
 * que copia. O texto "não será mostrada de novo" continua valendo: o banco
 * guarda só o hash, então não há de onde ler ela depois.
 */
function ensureResetPassModal() {
  if (document.getElementById('resetPassModal')) return;
  const modal = document.createElement('div');
  modal.className = 'modal fade';
  modal.id = 'resetPassModal';
  modal.tabIndex = -1;
  modal.innerHTML = `
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content bg-dark border-orange">
        <div class="modal-header border-orange">
          <h5 class="modal-title text-white">
            <i class="bi bi-key-fill me-2" style="color:#f59e0b"></i>
            <span id="rp-titulo">Redefinir senha</span>
          </h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body" id="rp-corpo"></div>
        <div class="modal-footer border-orange" id="rp-rodape"></div>
      </div>
    </div>`;
  document.body.appendChild(modal);
}

function confirmResetPassword(userId, userName) {
  ensureResetPassModal();
  const el = document.getElementById('resetPassModal');
  const modal = bootstrap.Modal.getOrCreateInstance(el);
  const corpo = document.getElementById('rp-corpo');
  const rodape = document.getElementById('rp-rodape');
  const nome = escapeHtml(userName);

  document.getElementById('rp-titulo').textContent = 'Redefinir senha';
  corpo.innerHTML = `
    <p class="text-light-gray mb-2">
      Gerar uma senha nova para <b class="text-white">${nome}</b>?
    </p>
    <p class="text-light-gray mb-0" style="font-size:13px">
      A senha atual para de funcionar na hora. A nova aparece aqui uma vez só —
      o banco guarda apenas o hash, então não tem de onde ler ela depois.
    </p>`;
  rodape.innerHTML = `
    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
    <button type="button" class="btn btn-warning" id="rp-confirmar">
      <i class="bi bi-key-fill me-1"></i>Gerar senha nova
    </button>`;

  document.getElementById('rp-confirmar').onclick = async (ev) => {
    const btn = ev.currentTarget;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Gerando...';
    try {
      const data = await api('admin.php?action=reset_user_password', {
        method: 'POST',
        body: JSON.stringify({ user_id: userId })
      });
      mostrarSenhaNova(nome, data.new_password);
      showAlert('success', `Senha de ${userName} redefinida!`);
    } catch (e) {
      // O erro fica DENTRO do modal e não num toast atrás dele: quem clicou
      // está olhando pra cá, e um aviso que aparece atrás do modal aberto é
      // um aviso que ninguém lê.
      corpo.insertAdjacentHTML('beforeend',
        `<div class="alert alert-danger mt-3 mb-0 py-2" style="font-size:13px">
           ${escapeHtml(e.error || 'Não deu pra redefinir. Tente de novo.')}
         </div>`);
      btn.disabled = false;
      btn.innerHTML = '<i class="bi bi-key-fill me-1"></i>Gerar senha nova';
    }
  };

  modal.show();
}

/** A tela da senha gerada: campo selecionável + botão que copia. */
function mostrarSenhaNova(nome, senha) {
  document.getElementById('rp-titulo').textContent = 'Senha redefinida';
  document.getElementById('rp-corpo').innerHTML = `
    <p class="text-light-gray mb-2">Senha nova de <b class="text-white">${nome}</b>:</p>
    <div class="input-group mb-2">
      <!-- readonly e não disabled: campo desabilitado não deixa selecionar
           o texto, que é justamente o plano B se a cópia falhar. -->
      <input type="text" class="form-control text-white" id="rp-senha"
             value="${escapeHtml(senha)}" readonly
             style="font-family:monospace;font-size:17px;letter-spacing:2px;background:#1c1c21">
      <button class="btn btn-warning" type="button" id="rp-copiar" style="min-width:104px">
        <i class="bi bi-clipboard me-1"></i>Copiar
      </button>
    </div>
    <p class="text-light-gray mb-0" style="font-size:12.5px">
      Anote agora — ela não será mostrada de novo. Repasse por um canal seguro.
    </p>`;
  document.getElementById('rp-rodape').innerHTML =
    '<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>';

  const campo = document.getElementById('rp-senha');
  const btn = document.getElementById('rp-copiar');

  // Já vem selecionada: se a cópia falhar, um Ctrl+C resolve sem mais nada.
  campo.focus();
  campo.select();

  btn.onclick = async () => {
    let copiou = false;
    try {
      // navigator.clipboard só existe em HTTPS (ou localhost). Em HTTP ele
      // nem está definido, e chamar direto explodiria — daí o fallback.
      if (navigator.clipboard && window.isSecureContext) {
        await navigator.clipboard.writeText(campo.value);
        copiou = true;
      } else {
        campo.select();
        copiou = document.execCommand('copy');
      }
    } catch (e) {
      copiou = false;
    }

    btn.innerHTML = copiou
      ? '<i class="bi bi-check-lg me-1"></i>Copiado'
      : '<i class="bi bi-exclamation-triangle me-1"></i>Copie na mão';
    btn.classList.toggle('btn-success', copiou);
    btn.classList.toggle('btn-warning', !copiou);
    if (!copiou) { campo.focus(); campo.select(); }

    setTimeout(() => {
      btn.innerHTML = '<i class="bi bi-clipboard me-1"></i>Copiar';
      btn.classList.remove('btn-success');
      btn.classList.add('btn-warning');
    }, 2200);
  };
}

// Onde você estava, guardado na URL. Assim recarregar (ou F5 sem querer) não
// joga de volta pra ELITE, e o endereço fica compartilhável.
// Formato: #view ou #view:LIGA
const _viewRestore = {
  league:       (lg) => showLeague(lg),
  trades:       ()   => showTrades(),
  config:       ()   => showConfig(),
  ranking:      ()   => showSerasaAdmin(),
  faadmin:      ()   => showFAAdmin(),
  eventos:      ()   => showEventosAdmin(),
  punicoes:     ()   => showPunicoes(),
  coins:        (lg) => showCoins(lg),
  tapas:        ()   => showTapas(),
  userApprovals:()   => showUserApprovals(),
  halloffame:   ()   => showHallOfFame(),
  dispensas:    ()   => showDispensas(),
  pontuacao:    (lg) => showRegistroPontuacao(lg),
  extawards:    (lg) => showExtendedAwards(lg),
  scheduler:    (lg) => showScheduler(lg),
  controlpanel: (lg) => showLeague(lg), // virou parte da aba da liga
  gestao:       (lg) => showGestao(lg),
  games:        ()   => showGamesAdmin(),
  draft:        (lg) => showAdminDraft(lg),
  // 'team' depende de um time escolhido, que a URL não carrega — volta pra liga.
  team:         (lg) => showLeague(lg),
};

/** Grava a view atual na URL. Chamado pelo updateBreadcrumb. */
function _syncAdminHash() {
  const v = appState.view;
  if (!v) return;
  const lg = appState.currentLeague;
  const alvo = '#' + v + (lg ? ':' + lg : '');
  if (location.hash !== alvo) history.replaceState(null, '', location.pathname + alvo);
}

async function init() {
  const hash = (window.location.hash || '').replace(/^#/, '');

  if (hash === 'temporadas' && typeof showSeasonsManagement === 'function') {
    history.replaceState(null, '', window.location.pathname);
    showSeasonsManagement();
    return;
  }

  if (hash) {
    const [view, liga] = hash.split(':');
    const restaurar = _viewRestore[view];
    // A liga da URL só vale se a pessoa tiver acesso a ela.
    const lg = (liga && _leagues.includes(liga)) ? liga : (_leagues[0] || null);
    if (restaurar) {
      try { restaurar(lg); return; } catch (e) { /* cai no padrão abaixo */ }
    }
  }

  if (!_leagues.length && window.IS_GAMES_ADMIN) {
    // Quem só é admin do Games não tem liga nenhuma pra abrir.
    showGamesAdmin();
  } else {
    showLeague(_leagues[0]);
  }
}

// showHome() mantido para compatibilidade com botões "Voltar" nas sub-views
function showHome() {
  if (!_leagues.length && window.IS_GAMES_ADMIN) { showGamesAdmin(); return; }
  showLeague(appState.currentLeague || _leagues[0]);
}

function updateBreadcrumb() {
  const breadcrumb = document.getElementById('breadcrumb');
  const breadcrumbContainer = document.getElementById('breadcrumbContainer');
  const pageTitle = document.getElementById('pageTitle');

  const leagueBack = appState.currentLeague || _leagues[0];
  breadcrumb.innerHTML = `<li class="breadcrumb-item"><a href="#" onclick="showLeague('${leagueBack}'); return false;">${leagueBack}</a></li>`;

  if (appState.view === 'league') {
    breadcrumbContainer.style.display = 'none';
    pageTitle.textContent = `Liga ${appState.currentLeague}`;
  } else {
    breadcrumbContainer.style.display = 'block';
    const labels = {
      team:         () => { breadcrumb.innerHTML += `<li class="breadcrumb-item active">${appState.currentTeam?.city} ${appState.currentTeam?.name}</li>`; return `${appState.currentTeam?.city} ${appState.currentTeam?.name}`; },
      trades:       () => { breadcrumb.innerHTML += '<li class="breadcrumb-item active">Trades</li>'; return 'Gerenciar Trades'; },
      config:       () => { breadcrumb.innerHTML += '<li class="breadcrumb-item active">Configurações</li>'; return 'Configurações das Ligas'; },
      seasons:      () => { breadcrumb.innerHTML += '<li class="breadcrumb-item active">Temporadas</li>'; return 'Gerenciar Temporadas'; },
      ranking:      () => { breadcrumb.innerHTML += '<li class="breadcrumb-item active">Rankings</li>'; return 'Rankings Globais'; },
      faadmin:      () => { breadcrumb.innerHTML += '<li class="breadcrumb-item active">Free Agency</li>'; return 'Free Agency'; },
      eventos:      () => { breadcrumb.innerHTML += '<li class="breadcrumb-item active">Eventos</li>'; return 'Eventos'; },
      punicoes:     () => { breadcrumb.innerHTML += '<li class="breadcrumb-item active">Punições</li>'; return 'Punições'; },
      coins:        () => { breadcrumb.innerHTML += '<li class="breadcrumb-item active">Moedas</li>'; return 'Gerenciar Moedas'; },
      tapas:        () => { breadcrumb.innerHTML += '<li class="breadcrumb-item active">Tapas</li>'; return 'Gerenciar Tapas'; },
      userApprovals:() => { breadcrumb.innerHTML += '<li class="breadcrumb-item active">Aprovação de Usuários</li>'; return 'Aprovar Usuários'; },
      halloffame:   () => { breadcrumb.innerHTML += '<li class="breadcrumb-item active">Hall da Fama</li>'; return 'Hall da Fama'; },
      dispensas:    () => { breadcrumb.innerHTML += '<li class="breadcrumb-item active">Dispensas</li>'; return 'Dispensas por Temporada'; },
      pontuacao:    () => { breadcrumb.innerHTML += '<li class="breadcrumb-item active">Pontuação</li>'; return 'Pontuação por Temporada'; },
      extawards:    () => { breadcrumb.innerHTML += '<li class="breadcrumb-item active">Prêmios Estendidos</li>'; return 'Prêmios Estendidos'; },
      scheduler:    () => { breadcrumb.innerHTML += '<li class="breadcrumb-item active">Agendador</li>'; return 'Agendador de Fases'; },
      controlpanel: () => { breadcrumb.innerHTML += '<li class="breadcrumb-item active">Painel de Controle</li>'; return 'Painel de Controle'; },
      gestao:       () => { breadcrumb.innerHTML += '<li class="breadcrumb-item active">Gestão</li>'; return 'Gestão de Usuários'; },
      quiz:         () => { breadcrumb.innerHTML += '<li class="breadcrumb-item active">Quiz</li>'; return 'Quiz do Grupo'; },
      games:        () => { breadcrumb.innerHTML += '<li class="breadcrumb-item active">Games</li>'; return 'Games e Apostas'; },
      draft:        () => { breadcrumb.innerHTML += '<li class="breadcrumb-item active">Draft</li>'; return `Draft — ${appState.currentLeague || ''}`; },
    };
    const fn = labels[appState.view];
    pageTitle.textContent = fn ? fn() : 'Painel Administrativo';
  }

  // Atualiza aba ativa do quicknav
  document.querySelectorAll('.admin-qnav-btn').forEach(b => b.classList.remove('active'));
  const abasFixas = { gestao: 'qnav-gestao', games: 'qnav-games', seasons: 'qnav-temporadas' };
  const activeId = abasFixas[appState.view]
    || `qnav-${(appState.currentLeague || _leagues[0]).toLowerCase()}`;
  const activeBtn = document.getElementById(activeId);
  if (activeBtn) activeBtn.classList.add('active');

  _syncAdminHash();
}

function escapeHtml(value) {
  const map = {
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#39;'
  };
  return String(value ?? '').replace(/[&<>"']/g, (ch) => map[ch]);
}




async function loadOuvidoriaMessages() {
  const list = document.getElementById('ouvidoriaList');
  const modalList = document.getElementById('ouvidoriaModalList');
  const totalEl = document.getElementById('ouvidoriaTotal');
  const subjectFilter = document.getElementById('ouvidoriaSubjectFilter')?.value || '';
  if (list) {
    list.innerHTML = '<div class="text-center py-3"><div class="spinner-border text-orange"></div></div>';
  }
  if (modalList) {
    modalList.innerHTML = '<div class="text-center py-3"><div class="spinner-border text-orange"></div></div>';
  }

  try {
    const params = new URLSearchParams({ limit: 8 });
    if (subjectFilter) {
      params.set('subject', subjectFilter);
    }
    const data = await api(`ouvidoria.php?${params.toString()}`);
    const messages = data.messages || [];
    if (totalEl) {
      totalEl.textContent = data.total ?? messages.length;
    }

    const renderHtml = () => {
      if (messages.length === 0) {
        return '<div class="text-center py-4 text-light-gray">Nenhuma mensagem ainda.</div>';
      }

      return messages.map(msg => {
        const date = msg.created_at ? new Date(msg.created_at).toLocaleString('pt-BR') : '-';
        const subject = escapeHtml(msg.subject || 'Reclamação');
        const content = escapeHtml(msg.message || '').replace(/\n/g, '<br>');
        return `
          <div class="bg-dark border border-secondary rounded p-3 mb-2">
            <div class="d-flex justify-content-between align-items-start gap-2">
              <div>
                <div class="text-light-gray small"><i class="bi bi-clock me-1"></i>${date}</div>
                <div class="mt-1"><span class="badge bg-secondary">${subject}</span></div>
              </div>
              <button class="btn btn-sm btn-outline-danger" type="button" onclick="deleteOuvidoriaMessage(${msg.id})">
                <i class="bi bi-trash"></i>
              </button>
            </div>
            <div class="text-white mt-2">${content}</div>
          </div>
        `;
      }).join('');
    };

    if (list) {
      list.innerHTML = renderHtml();
    }
    if (modalList) {
      modalList.innerHTML = renderHtml();
    }
  } catch (e) {
    if (list) {
      list.innerHTML = '<div class="alert alert-danger">Erro ao carregar ouvidoria.</div>';
    }
    if (modalList) {
      modalList.innerHTML = '<div class="alert alert-danger">Erro ao carregar ouvidoria.</div>';
    }
  }
}

function ensureOuvidoriaModal() {
  if (document.getElementById('ouvidoriaModal')) return;

  const modal = document.createElement('div');
  modal.className = 'modal fade';
  modal.id = 'ouvidoriaModal';
  modal.tabIndex = -1;
  modal.innerHTML = `
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
      <div class="modal-content bg-dark border-orange">
        <div class="modal-header border-orange">
          <h5 class="modal-title text-white"><i class="bi bi-chat-left-dots me-2 text-orange"></i>Ouvidoria</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
            <label class="text-light-gray" for="ouvidoriaSubjectFilter">Assunto</label>
            <select id="ouvidoriaSubjectFilter" class="form-select form-select-sm" style="max-width: 220px;">
              <option value="">Todos</option>
              <option value="Reclamação">Reclamação</option>
              <option value="Sugestão">Sugestão</option>
              <option value="Erro de Gameplay">Erro de Gameplay</option>
            </select>
          </div>
          <div id="ouvidoriaModalList"><div class="text-center py-3"><div class="spinner-border text-orange"></div></div></div>
        </div>
        <div class="modal-footer border-orange">
          <button type="button" class="btn btn-outline-light" onclick="loadOuvidoriaMessages()">
            <i class="bi bi-arrow-clockwise me-1"></i>Atualizar
          </button>
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
        </div>
      </div>
    </div>
  `;
  document.body.appendChild(modal);

  const filter = modal.querySelector('#ouvidoriaSubjectFilter');
  if (filter) {
    filter.addEventListener('change', () => loadOuvidoriaMessages());
  }
}

function showOuvidoriaModal() {
  ensureOuvidoriaModal();
  loadOuvidoriaMessages();
  const modalEl = document.getElementById('ouvidoriaModal');
  if (modalEl) {
    const modal = new bootstrap.Modal(modalEl);
    modal.show();
  }
}

async function deleteOuvidoriaMessage(messageId) {
  if (!messageId) return;
  const confirmed = await confirmarSite('Apagar esta mensagem da ouvidoria?');
  if (!confirmed) return;

  try {
    await api('ouvidoria.php', {
      method: 'POST',
      body: JSON.stringify({ action: 'delete_message', message_id: messageId })
    });
    loadOuvidoriaMessages();
  } catch (e) {
    alert(e.error || 'Erro ao apagar mensagem.');
  }
}

// ── Convite reutilizável da ROOKIE ──────────────────────────────────────────
// Um link só, que várias pessoas usam pra se cadastrar (escolhendo o time da
// NBA). Diferente do link de Convites, que é individual e queima ao ser usado.

function ensureConviteRookieModal() {
  if (document.getElementById('conviteRookieModal')) return;

  const modal = document.createElement('div');
  modal.className = 'modal fade';
  modal.id = 'conviteRookieModal';
  modal.tabIndex = -1;
  modal.innerHTML = `
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content bg-dark border-orange">
        <div class="modal-header border-orange">
          <h5 class="modal-title text-white"><i class="bi bi-clipboard-plus me-2" style="color:#a855f7"></i>Inscrição ROOKIE</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <p class="text-light-gray" style="font-size:12px">
            Link único de cadastro na ROOKIE. Pode mandar no grupo — quem abrir cria a conta
            e escolhe um time da NBA que ainda esteja livre. Gerar um novo invalida o anterior.
          </p>
          <div id="conviteRookieBody"><div class="text-center py-3"><div class="spinner-border text-orange"></div></div></div>
        </div>
        <div class="modal-footer border-orange">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
        </div>
      </div>
    </div>`;
  document.body.appendChild(modal);
}

function showConviteRookie() {
  ensureConviteRookieModal();
  _carregarConviteRookie();
  const el = document.getElementById('conviteRookieModal');
  if (el) new bootstrap.Modal(el).show();
}

async function _carregarConviteRookie() {
  const box = document.getElementById('conviteRookieBody');
  if (!box) return;
  try {
    const d = await api('admin.php?action=league_invite&league=ROOKIE');
    _renderConviteRookie(d.token || null);
  } catch (e) {
    box.innerHTML = `<div class="alert alert-danger mb-0">${escapeHtml(e.error || e.message || 'Erro ao carregar')}</div>`;
  }
}

function _renderConviteRookie(token) {
  const box = document.getElementById('conviteRookieBody');
  if (!box) return;

  if (!token) {
    box.innerHTML = `
      <div class="alert alert-secondary" style="font-size:13px">Nenhum link ativo no momento.</div>
      <button class="btn btn-orange w-100" onclick="_gerarConviteRookie()">
        <i class="bi bi-magic me-1"></i>Gerar link de inscrição
      </button>`;
    return;
  }

  const link = `${window.location.origin}/register.php?convite=${token}`;
  box.innerHTML = `
    <div class="mb-2" style="font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--text-3);font-weight:700">Link ativo</div>
    <input type="text" class="form-control mb-2" readonly value="${escapeHtml(link)}"
           onclick="this.select()" style="font-size:12px">
    <div class="d-flex gap-2 flex-wrap">
      <button class="btn btn-orange flex-grow-1" onclick="_copiarConviteRookie('${escapeHtml(link)}')">
        <i class="bi bi-clipboard me-1"></i>Copiar link
      </button>
      <button class="btn btn-outline-light" onclick="_gerarConviteRookie(true)" title="Invalida o link atual e cria outro">
        <i class="bi bi-arrow-repeat me-1"></i>Gerar novo
      </button>
      <button class="btn btn-outline-danger" onclick="_revogarConviteRookie()" title="Desativa o link sem criar outro">
        <i class="bi bi-slash-circle"></i>
      </button>
    </div>`;
}

async function _copiarConviteRookie(link) {
  try {
    await navigator.clipboard.writeText(link);
    showAlert('success', 'Link copiado! Agora é só mandar no grupo.');
  } catch (e) {
    await perguntarSite('Copie o link abaixo:', link);
  }
}

async function _gerarConviteRookie(substituindo) {
  if (substituindo && !await confirmarSite('Gerar um link novo invalida o atual — quem já recebeu não vai mais conseguir usar. Continuar?')) return;
  try {
    const d = await api('admin.php?action=league_invite', {
      method: 'POST',
      body: JSON.stringify({ league: 'ROOKIE', acao: 'gerar' }),
    });
    _renderConviteRookie(d.token || null);
    showAlert('success', 'Link de inscrição gerado.');
  } catch (e) {
    showAlert('danger', e.error || e.message || 'Erro ao gerar o link.');
  }
}

async function _revogarConviteRookie() {
  if (!await confirmarSite('Desativar o link de inscrição da ROOKIE? Ninguém mais consegue se cadastrar por ele.')) return;
  try {
    await api('admin.php?action=league_invite', {
      method: 'POST',
      body: JSON.stringify({ league: 'ROOKIE', acao: 'revogar' }),
    });
    _renderConviteRookie(null);
    showAlert('success', 'Link desativado.');
  } catch (e) {
    showAlert('danger', e.error || e.message || 'Erro ao revogar o link.');
  }
}

function ensureWaitlistModal() {
  if (document.getElementById('waitlistModal')) return;

  const modal = document.createElement('div');
  modal.className = 'modal fade';
  modal.id = 'waitlistModal';
  modal.tabIndex = -1;
  modal.innerHTML = `
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
      <div class="modal-content bg-dark border-orange">
        <div class="modal-header border-orange">
          <h5 class="modal-title text-white"><i class="bi bi-person-lines-fill me-2" style="color:#22c55e"></i>Interessados</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <p class="text-light-gray" style="font-size:12px">Quem pediu pra participar pelo login. Copie o link de cadastro e mande pelo WhatsApp — a pessoa entra direto na Liga ROOKIE.</p>
          <div id="waitlistModalList"><div class="text-center py-3"><div class="spinner-border text-orange"></div></div></div>
        </div>
        <div class="modal-footer border-orange">
          <button type="button" class="btn btn-outline-light" onclick="loadWaitlistRequests()">
            <i class="bi bi-arrow-clockwise me-1"></i>Atualizar
          </button>
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
        </div>
      </div>
    </div>
  `;
  document.body.appendChild(modal);
}

function showWaitlistModal() {
  ensureWaitlistModal();
  loadWaitlistRequests();
  const modalEl = document.getElementById('waitlistModal');
  if (modalEl) {
    const modal = new bootstrap.Modal(modalEl);
    modal.show();
  }
}

// ── Leilão (admin): acompanhar e resolver os leilões da liga ────────────────

async function showLeilaoAdmin(league) {
  league = league || appState.currentLeague;
  appState.view = 'leilao_admin';
  updateBreadcrumb();
  const container = document.getElementById('mainContainer');
  container.innerHTML = '<div class="text-center py-5"><div class="spinner-border" style="color:var(--red)"></div></div>';
  const back = `<button class="btn btn-back" onclick="showLeague('${league}')"><i class="bi bi-arrow-left"></i> Voltar</button>`;

  try {
    const data = await api(`leilao.php?action=listar_admin&league=${league}`);
    const leiloes = data.leiloes || [];
    _leilaoAdminCache = leiloes;   // o botão de copiar lê a troca daqui
    const ativos = leiloes.filter(l => l.status !== 'finalizado');
    const precisamResolucao = ativos.filter(l => Number(l.expirado));
    const emAndamento = ativos.filter(l => !Number(l.expirado));
    const finalizados = leiloes.filter(l => l.status === 'finalizado').slice(0, 20);
    const cancelados = leiloes.filter(l => l.status === 'cancelado');

    const renderCard = (l, destacar) => `
      <div class="panel mb-2" style="${destacar ? 'border-color:rgba(239,68,68,.4)' : ''}">
        <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;padding:12px 16px">
          <div style="flex:1;min-width:160px">
            <div style="font-weight:600;font-size:14px;color:var(--text)">${escapeHtml(l.player_name || '—')}</div>
            <div style="font-size:12px;color:var(--text-3)">${escapeHtml(l.team_name || 'Sem time')} · ${l.total_propostas || 0} proposta(s)</div>
          </div>
          ${destacar ? '<span class="pun-badge" style="background:rgba(239,68,68,.15);color:#ef4444;border:1px solid rgba(239,68,68,.4)">Precisa resolução</span>' : ''}
          <button class="btn-ghost" style="color:#ef4444;border-color:rgba(239,68,68,.3)" onclick="_leilaoAdminAbrirResolucao(${l.id}, ${l.team_id ? Number(l.team_id) : 'null'}, '${league}')">
            <i class="bi bi-hammer me-1"></i> Resolver
          </button>
        </div>
      </div>`;

    container.innerHTML = `
      <div class="mb-4 d-flex align-items-center gap-2 flex-wrap">
        ${back}
        <span class="text-light-gray" style="font-size:14px;font-weight:600">Leilão — ${escapeHtml(league)}</span>
      </div>
      ${_leilaoAdminFormCriar(league)}
      <!-- Os slots comprados na loja: quem pediu leilão e ainda não foi
           atendido. Fica logo abaixo do formulário porque é essa a ordem do
           trabalho — ver quem pediu, criar o leilão, marcar como usado. -->
      <div class="panel mb-3" id="slotsLeilaoPanel">
        <div class="panel-header">
          <div class="panel-title"><i class="bi bi-ticket-perforated" style="color:#3b82f6"></i> Slots de leilão comprados</div>
        </div>
        <div class="panel-body" id="slotsLeilaoBox">
          <div class="text-center py-3"><div class="spinner-border spinner-border-sm" style="color:var(--red)"></div></div>
        </div>
      </div>
      <div class="panel mb-3">
        <div class="panel-header"><div class="panel-title"><i class="bi bi-hourglass-split" style="color:#ef4444"></i> Precisam de resolução (${precisamResolucao.length})</div></div>
        <div class="panel-body">${precisamResolucao.length ? precisamResolucao.map(l => renderCard(l, true)).join('') : '<p style="color:var(--text-3);font-size:13px">Nenhum leilão expirado aguardando resolução.</p>'}</div>
      </div>
      <div class="panel mb-3">
        <div class="panel-header"><div class="panel-title"><i class="bi bi-broadcast" style="color:#22c55e"></i> Em andamento (${emAndamento.length})</div></div>
        <div class="panel-body">${emAndamento.length ? emAndamento.map(l => renderCard(l, false)).join('') : '<p style="color:var(--text-3);font-size:13px">Nenhum leilão em andamento no momento.</p>'}</div>
      </div>
      ${cancelados.length ? `
      <div class="panel mb-3">
        <div class="panel-header"><div class="panel-title"><i class="bi bi-x-circle" style="color:var(--text-3)"></i> Cancelados (${cancelados.length})</div></div>
        <div class="panel-body">
          <p style="color:var(--text-3);font-size:12.5px;margin-bottom:10px">Leilão cancelado não é histórico — é leilão que não aconteceu. Pode apagar.</p>
          ${cancelados.map(l => `
            <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;padding:8px 4px;border-bottom:1px solid var(--border);font-size:13px">
              <span style="color:var(--text)">${escapeHtml(l.player_name || '—')}</span>
              <span style="color:var(--text-3);font-size:12px;margin-left:auto">${escapeHtml(l.team_name || 'Sem time')}</span>
              <button class="btn-ghost" style="color:#ef4444;border-color:rgba(239,68,68,.3);padding:4px 10px;font-size:12px"
                onclick="_leilaoAdminExcluir(${l.id}, '${escapeHtml(String(l.player_name || '')).replace(/'/g, "\\'")}', '${league}')">
                <i class="bi bi-trash"></i>
              </button>
            </div>`).join('')}
        </div>
      </div>` : ''}
      <div class="panel">
        <div class="panel-header"><div class="panel-title"><i class="bi bi-clock-history" style="color:var(--text-3)"></i> Últimos finalizados</div></div>
        <div class="panel-body">${finalizados.length ? finalizados.map(l => _leilaoAdminLinhaFinalizado(l, league)).join('') : '<p style="color:var(--text-3);font-size:13px">Nenhum leilão finalizado ainda.</p>'}</div>
      </div>`;
    _leilaoAdminLigarFormCriar(league);
    // Depois do innerHTML: a caixa dos slots só existe a partir daqui, e ela
    // se recarrega sozinha a cada ação sem redesenhar o card inteiro.
    _carregarSlotsLeilao(league);
  } catch (e) {
    container.innerHTML = `<div class="mb-3">${back}</div><div class="alert alert-danger">Erro ao carregar leilões: ${escapeHtml(e.error || e.message || '')}</div>`;
  }
}

/**
 * Uma linha do histórico: quem era, o que a troca moveu, copiar e apagar.
 *
 * A troca vem pronta do servidor (campo "troca"), então a lista já conta o
 * desfecho — antes só dizia o nome do jogador e o time, o que obrigava a
 * abrir cada leilão pra lembrar o que tinha sido negociado.
 */
function _leilaoAdminLinhaFinalizado(l, league) {
  const nome = escapeHtml(l.player_name || '—');
  const t = l.troca;
  const semTroca = '<span style="color:var(--text-3);font-size:12px">encerrado sem troca</span>';

  const corpo = t ? `
    <div style="font-size:12.5px;color:var(--text-2);line-height:1.5;margin-top:2px">
      <strong style="color:var(--text)">${escapeHtml(t.time)}</strong> levou
      ${t.levou.map(x => escapeHtml(x)).join(' + ')}
      <br>por ${t.deu.length ? t.deu.map(x => escapeHtml(x)).join(' + ') : '<em>nada</em>'}
      ${t.obs ? `<br><span style="color:var(--text-3)">"${escapeHtml(t.obs)}"</span>` : ''}
    </div>` : semTroca;

  return `
    <div style="display:flex;align-items:flex-start;gap:10px;padding:10px 4px;border-bottom:1px solid var(--border)">
      <div style="flex:1;min-width:0">
        <div style="font-size:13px;font-weight:600;color:var(--text)">${nome}</div>
        ${corpo}
      </div>
      <div style="display:flex;gap:6px;flex-shrink:0">
        ${t ? `<button class="btn-ghost" style="padding:4px 9px;font-size:12px" title="Copiar a troca"
          onclick="_leilaoAdminCopiarTroca(${l.id}, this)"><i class="bi bi-clipboard"></i></button>` : ''}
        <button class="btn-ghost" style="padding:4px 9px;font-size:12px;color:#ef4444;border-color:rgba(239,68,68,.3)" title="Excluir do histórico"
          onclick="_leilaoAdminExcluir(${l.id}, '${escapeHtml(String(l.player_name || '')).replace(/'/g, "\\'")}', '${league}', true)"><i class="bi bi-trash"></i></button>
      </div>
    </div>`;
}

/** O texto da troca no clipboard, pra colar no grupo. */
async function _leilaoAdminCopiarTroca(leilaoId, botao) {
  const l = (_leilaoAdminCache || []).find(x => Number(x.id) === Number(leilaoId));
  const texto = l?.troca?.texto;
  if (!texto) return;
  try {
    await navigator.clipboard.writeText(texto);
    const antes = botao.innerHTML;
    botao.innerHTML = '<i class="bi bi-check2"></i>';
    setTimeout(() => { botao.innerHTML = antes; }, 1400);
  } catch (e) {
    alert(texto);   // sem clipboard (http, permissão negada): mostra pra copiar na mão
  }
}

/**
 * Os slots de leilão da liga, no card do Leilão.
 *
 * Só aparece quem já comprou algum: listar a liga inteira com zero em todo
 * mundo faria a fila real — que hoje são três GMs — sumir no meio de trinta
 * linhas vazias.
 */
async function _carregarSlotsLeilao(league) {
  const box = document.getElementById('slotsLeilaoBox');
  if (!box) return;
  try {
    const data = await api(`leilao.php?action=slots_leilao&league=${encodeURIComponent(league)}`);
    const slots = data.slots || [];
    const emAberto = slots.filter(s => s.pendentes > 0);
    if (!slots.length) {
      box.innerHTML = '<p style="color:var(--text-3);font-size:13px;margin:0">Ninguém desta liga comprou slot de leilão ainda.</p>';
      return;
    }
    const linha = s => `
      <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;padding:10px 4px;border-bottom:1px solid var(--border)">
        <div style="flex:1;min-width:150px">
          <div style="font-weight:600;font-size:13.5px;color:var(--text)">${escapeHtml(s.gm || '—')}</div>
          <div style="font-size:12px;color:var(--text-3)">${escapeHtml(s.time || 'Sem time')} · ${s.total} comprado${s.total > 1 ? 's' : ''} no total</div>
        </div>
        <span class="pun-badge" title="Slots em aberto" style="${s.pendentes
            ? 'background:rgba(59,130,246,.15);color:#3b82f6;border:1px solid rgba(59,130,246,.4)'
            : 'background:var(--panel-2);color:var(--text-3);border:1px solid var(--border)'}">
          ${s.pendentes} em aberto
        </span>
        <!-- margin-left:auto mantém os botões na direita mesmo quando o nome
             do time empurra a linha pra baixo no celular. -->
        <div style="display:flex;gap:6px;align-items:center;margin-left:auto">
          <button class="btn-ghost" style="padding:4px 10px;font-size:13px" title="Tirar um slot"
            onclick="_slotLeilao(${s.user_id}, 'tirar', '${league}')" ${s.pendentes ? '' : 'disabled'}>−</button>
          <button class="btn-ghost" style="padding:4px 10px;font-size:13px" title="Dar um slot"
            onclick="_slotLeilao(${s.user_id}, 'dar', '${league}')">+</button>
          <button class="btn-orange" style="padding:4px 12px;font-size:12.5px"
            onclick="_slotLeilao(${s.user_id}, 'usar', '${league}')" ${s.pendentes ? '' : 'disabled'}>
            <i class="bi bi-check2 me-1"></i>Feito
          </button>
        </div>
      </div>`;
    box.innerHTML = `
      <p style="color:var(--text-3);font-size:12.5px;margin-bottom:8px">
        Slot comprado é pedido de leilão em aberto. Crie o leilão do jogador acima e clique em <b>Feito</b> pra baixar o pedido.
      </p>
      ${emAberto.length ? emAberto.map(linha).join('')
        : '<p style="color:var(--text-3);font-size:13px;margin:0 0 10px">Nenhum slot em aberto.</p>'}
      ${slots.length > emAberto.length ? `
        <details style="margin-top:10px">
          <summary style="cursor:pointer;color:var(--text-3);font-size:12.5px">
            Já atendidos (${slots.length - emAberto.length})
          </summary>
          ${slots.filter(s => !s.pendentes).map(linha).join('')}
        </details>` : ''}`;
  } catch (e) {
    box.innerHTML = `<p style="color:#ef4444;font-size:13px;margin:0">Erro ao carregar: ${escapeHtml(e.error || 'desconhecido')}</p>`;
  }
}

/** Dar, tirar ou baixar um slot. Só o "tirar" pergunta: é o único que apaga pedido pago. */
async function _slotLeilao(userId, op, league) {
  if (op === 'tirar' && !await confirmarSite('Tirar um slot em aberto deste GM? Ele pagou 500 moedas por esse pedido.')) return;
  try {
    const r = await api('leilao.php', { method: 'POST',
      body: JSON.stringify({ action: 'slot_leilao_mexer', user_id: userId, op }) });
    showAlert('success', r.message || 'Pronto!');
    _carregarSlotsLeilao(league);
  } catch (e) {
    showAlert('danger', e.error || 'Erro ao mexer no slot');
  }
}

/**
 * O formulário de abrir leilão, dentro do card da liga.
 *
 * Não tem seletor de liga: quem chega aqui já entrou pela aba de uma, e
 * escolher de novo era o passo em que se abria leilão na liga errada.
 */
function _leilaoAdminFormCriar(league) {
  return `
    <div class="panel mb-3">
      <div class="panel-header"><div class="panel-title"><i class="bi bi-plus-circle" style="color:#22c55e"></i> Abrir leilão na ${escapeHtml(league)}</div></div>
      <div class="panel-body">
        <div class="d-flex gap-3 flex-wrap mb-3" style="font-size:13px">
          <label style="display:flex;align-items:center;gap:6px;cursor:pointer">
            <input type="radio" name="lqModo" id="lqModoBusca" value="busca" checked> Jogador de um elenco
          </label>
          <label style="display:flex;align-items:center;gap:6px;cursor:pointer">
            <input type="radio" name="lqModo" id="lqModoNovo" value="novo"> Jogador avulso
          </label>
        </div>

        <div id="lqAreaBusca">
          <div class="d-flex gap-2 flex-wrap">
            <input type="text" id="lqBusca" class="form-control" placeholder="Nome do jogador" style="flex:1;min-width:200px">
            <button type="button" class="btn-ghost" id="lqBtnBuscar"><i class="bi bi-search me-1"></i>Buscar</button>
          </div>
          <div id="lqResultados" style="margin-top:8px"></div>
          <div id="lqEscolhido" style="font-size:13px;color:var(--text-2);margin-top:8px"></div>
          <input type="hidden" id="lqPlayerId"><input type="hidden" id="lqTeamId">
        </div>

        <div id="lqAreaNovo" style="display:none">
          <div class="d-flex gap-2 flex-wrap">
            <input type="text" id="lqNome" class="form-control" placeholder="Nome" style="flex:2;min-width:160px">
            <select id="lqPos" class="form-select" style="flex:0 0 90px">
              <option value="PG">PG</option><option value="SG">SG</option>
              <option value="SF">SF</option><option value="PF">PF</option><option value="C">C</option>
            </select>
            <input type="number" id="lqIdade" class="form-control" value="25" min="18" max="45" style="flex:0 0 80px">
            <input type="number" id="lqOvr" class="form-control" value="70" min="40" max="99" style="flex:0 0 80px">
          </div>
          <p style="font-size:11.5px;color:var(--text-3);margin:6px 0 0">Nome · posição · idade · OVR. Jogador avulso não tem time vendedor, então o leilão dele não aceita picks.</p>
        </div>

        <button type="button" class="btn-ghost mt-3" id="lqBtnAbrir" style="color:#22c55e;border-color:rgba(34,197,94,.3)" disabled>
          <i class="bi bi-hammer me-1"></i> Abrir leilão
        </button>
      </div>
    </div>`;
}

function _leilaoAdminLigarFormCriar(league) {
  const el = (id) => document.getElementById(id);
  const modoNovo = el('lqModoNovo');
  const btnAbrir = el('lqBtnAbrir');
  if (!btnAbrir) return;

  const pronto = () => {
    if (modoNovo?.checked) {
      btnAbrir.disabled = !(el('lqNome')?.value.trim() && el('lqIdade')?.value && el('lqOvr')?.value);
    } else {
      btnAbrir.disabled = !el('lqPlayerId')?.value;
    }
  };
  const trocarModo = () => {
    const novo = modoNovo?.checked;
    el('lqAreaBusca').style.display = novo ? 'none' : '';
    el('lqAreaNovo').style.display = novo ? '' : 'none';
    pronto();
  };
  el('lqModoBusca')?.addEventListener('change', trocarModo);
  modoNovo?.addEventListener('change', trocarModo);
  ['lqNome', 'lqIdade', 'lqOvr'].forEach(id => el(id)?.addEventListener('input', pronto));

  el('lqBtnBuscar')?.addEventListener('click', async () => {
    const termo = el('lqBusca')?.value.trim();
    const alvo = el('lqResultados');
    if (!termo || !alvo) return;
    alvo.innerHTML = '<p style="color:var(--text-3);font-size:13px">Buscando...</p>';
    try {
      const d = await api(`team.php?action=search_player&query=${encodeURIComponent(termo)}&league=${encodeURIComponent(league)}`);
      const lista = d.players || [];
      alvo.innerHTML = lista.length ? lista.slice(0, 12).map(pl => `
        <div style="display:flex;align-items:center;gap:8px;padding:7px 10px;border:1px solid var(--border);border-radius:8px;margin-bottom:6px;cursor:pointer;font-size:13px"
          onclick="_leilaoAdminEscolher(${pl.id}, ${pl.team_id || 'null'}, '${escapeHtml(String(pl.name)).replace(/'/g, "\\'")}', '${escapeHtml(String(pl.team_name || ''))}')">
          <span style="flex:1;color:var(--text)">${escapeHtml(pl.name)}</span>
          <span style="color:var(--text-3);font-size:12px">${escapeHtml(pl.team_name || 'sem time')} · ${pl.ovr || '?'} OVR</span>
        </div>`).join('') : '<p style="color:var(--text-3);font-size:13px">Ninguém com esse nome nesta liga.</p>';
    } catch (e) {
      alvo.innerHTML = '<p style="color:#ef4444;font-size:13px">Erro na busca.</p>';
    }
  });
  el('lqBusca')?.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); el('lqBtnBuscar').click(); } });

  btnAbrir.addEventListener('click', () => _leilaoAdminAbrir(league));
}

function _leilaoAdminEscolher(playerId, teamId, nome, timeNome) {
  const el = (id) => document.getElementById(id);
  el('lqPlayerId').value = playerId;
  el('lqTeamId').value = teamId || '';
  el('lqEscolhido').innerHTML = `<i class="bi bi-check-circle-fill me-1" style="color:#22c55e"></i>${escapeHtml(nome)}${timeNome ? ' · ' + escapeHtml(timeNome) : ''}`;
  el('lqResultados').innerHTML = '';
  el('lqBtnAbrir').disabled = false;
}

async function _leilaoAdminAbrir(league) {
  const el = (id) => document.getElementById(id);
  const btn = el('lqBtnAbrir');
  const novo = el('lqModoNovo')?.checked;
  btn.disabled = true;
  try {
    // A liga vem da aba, não de um select: é a que o admin já escolheu.
    const corpo = { action: 'cadastrar', league: league, status: 'ativo' };
    if (novo) {
      corpo.player_id = null;
      corpo.team_id = null;
      corpo.new_player = {
        name: el('lqNome').value.trim(),
        position: el('lqPos').value,
        age: el('lqIdade').value,
        ovr: el('lqOvr').value
      };
    } else {
      corpo.player_id = el('lqPlayerId').value;
      corpo.team_id = el('lqTeamId').value || null;
    }
    const d = await api('leilao.php', { method: 'POST', body: JSON.stringify(corpo) });
    if (d && d.success === false) throw d;
    showLeilaoAdmin(league);
  } catch (e) {
    alert(e.error || e.message || 'Erro ao abrir o leilão.');
    btn.disabled = false;
  }
}

async function _leilaoAdminExcluir(leilaoId, nome, league, finalizado) {
  // No finalizado o aviso é outro: a troca já aconteceu no elenco e apagar
  // aqui não desfaz nada — só some com o registro.
  const aviso = finalizado
    ? `Apagar do histórico o leilão de ${nome}? A troca já feita NÃO é desfeita — some só o registro do leilão e as propostas dele.`
    : `Apagar o leilão cancelado de ${nome}? As propostas dele vão junto, e não dá pra desfazer.`;
  if (!await confirmarSite(aviso)) return;
  try {
    const d = await api('leilao.php', { method: 'POST', body: JSON.stringify({ action: 'excluir_leilao', leilao_id: leilaoId }) });
    if (d && d.success === false) throw d;
    showLeilaoAdmin(league);
  } catch (e) {
    alert(e.error || e.message || 'Erro ao excluir.');
  }
}

let _leilaoAdminCache = [];
let _leilaoAdminLeilaoId = null;
let _leilaoAdminSellerTeamId = null;
let _leilaoAdminLeague = null;
let _leilaoAdminPropostas = [];
let _leilaoAdminSellerData = { players: [], picks: [] };

function ensureLeilaoResolveModal() {
  if (document.getElementById('leilaoAdminResolveModal')) return;
  const modal = document.createElement('div');
  modal.className = 'modal fade';
  modal.id = 'leilaoAdminResolveModal';
  modal.tabIndex = -1;
  modal.innerHTML = `
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
      <div class="modal-content bg-dark border-orange">
        <div class="modal-header border-orange">
          <h5 class="modal-title text-white"><i class="bi bi-hammer me-2" style="color:#ef4444"></i>Resolver Leilão</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div id="leilaoAdminResolveBody"><p style="color:var(--text-3);font-size:13px">Carregando...</p></div>
        </div>
        <div class="modal-footer border-orange">
          <button type="button" class="btn btn-outline-light" onclick="_leilaoAdminEncerrarSemTroca()">
            <i class="bi bi-x-circle me-1"></i>Encerrar sem troca
          </button>
          <button type="button" class="btn btn-success" onclick="_leilaoAdminConfirmarResolucao()">
            <i class="bi bi-check2-circle me-1"></i>Confirmar resolução
          </button>
        </div>
      </div>
    </div>
  `;
  document.body.appendChild(modal);
}

function _leilaoAdminCheckboxList(items, cls, labelFn, checkedIds) {
  if (!items.length) return '<p style="font-size:12px;color:var(--text-3)">Nenhum item disponível.</p>';
  return items.map(it => `
    <label style="display:flex;align-items:center;gap:8px;padding:7px 10px;background:var(--panel-2);border:1px solid var(--border);border-radius:8px;cursor:pointer;margin-bottom:5px">
      <input type="checkbox" class="${cls}" value="${it.id}"${checkedIds && checkedIds.has(Number(it.id)) ? ' checked' : ''} style="flex-shrink:0;margin:0">
      <span style="font-size:13px;color:var(--text)">${escapeHtml(labelFn(it))}</span>
    </label>`).join('');
}

async function _leilaoAdminAbrirResolucao(leilaoId, sellerTeamId, league) {
  _leilaoAdminLeilaoId = leilaoId;
  _leilaoAdminSellerTeamId = sellerTeamId || null;
  _leilaoAdminLeague = league;
  ensureLeilaoResolveModal();
  const modalEl = document.getElementById('leilaoAdminResolveModal');
  const modal = new bootstrap.Modal(modalEl);
  modal.show();
  const body = document.getElementById('leilaoAdminResolveBody');
  if (body) body.innerHTML = '<div class="text-center py-3"><div class="spinner-border text-orange"></div></div>';
  try {
    const [dataMsgs, dataSeller] = await Promise.all([
      api(`leilao.php?action=listar_mensagens&leilao_id=${leilaoId}`),
      _leilaoAdminSellerTeamId
        ? api(`leilao.php?action=seller_items&seller_team_id=${_leilaoAdminSellerTeamId}`).catch(() => ({ players: [], picks: [] }))
        : Promise.resolve({ players: [], picks: [] })
    ]);
    const porTime = {};
    (dataMsgs.messages || []).forEach(m => { if (m.tipo === 'proposal' && m.proposta) porTime[m.proposta.team_id] = m.proposta; });
    _leilaoAdminPropostas = Object.values(porTime);
    _leilaoAdminSellerData = dataSeller || { players: [], picks: [] };
    _leilaoAdminRenderResolveBody();
  } catch (e) {
    if (body) body.innerHTML = `<p style="color:#ef4444;font-size:13px">${escapeHtml(e.error || e.message || 'Erro ao carregar')}</p>`;
  }
}

function _leilaoAdminRenderResolveBody() {
  const body = document.getElementById('leilaoAdminResolveBody');
  if (!body) return;
  if (!_leilaoAdminPropostas.length) {
    body.innerHTML = '<p style="text-align:center;color:var(--text-3);font-size:13px;padding:16px 0">Nenhuma proposta foi enviada pra este leilão — só é possível encerrar sem troca.</p>';
    return;
  }
  const options = _leilaoAdminPropostas.map(p => `<option value="${p.id}">${escapeHtml(p.team_name || ('Time #' + p.team_id))}</option>`).join('');
  body.innerHTML = `
    <div class="mb-3">
      <label class="pun-field-label">Time vencedor</label>
      <select id="leilaoAdminPropostaSelect" class="form-select" onchange="_leilaoAdminRenderItensDaProposta()">${options}</select>
    </div>
    <div id="leilaoAdminItensProposta"></div>
    <div style="border-top:1px solid var(--border);padding-top:14px;margin-top:14px">
      <div style="font-size:11px;font-weight:700;color:var(--text-3);text-transform:uppercase;letter-spacing:.06em;margin-bottom:8px"><i class="bi bi-plus-circle me-1"></i>Itens extras do vendedor (opcional)</div>
      <div id="leilaoAdminSellerItens"></div>
    </div>`;
  _leilaoAdminRenderItensDaProposta();
}

function _leilaoAdminRenderItensDaProposta() {
  const select = document.getElementById('leilaoAdminPropostaSelect');
  const proposta = _leilaoAdminPropostas.find(p => Number(p.id) === Number(select?.value));
  const el = document.getElementById('leilaoAdminItensProposta');
  if (!proposta || !el) return;

  const jogadoresHtml = _leilaoAdminCheckboxList(proposta.jogadores || [], 'leilaoAdminOfertaPlayer',
    j => `${j.name} · ${j.position || ''} · OVR ${j.ovr || '?'}`, new Set((proposta.jogadores || []).map(j => Number(j.id))));
  const picksHtml = _leilaoAdminCheckboxList(proposta.picks || [], 'leilaoAdminOfertaPick',
    pk => `${pk.season_year} R${pk.round}${pk.original_team_name ? ' · ' + pk.original_team_name.trim() : ''}${pk.swap_type ? ' [' + pk.swap_type + ']' : ''}`,
    new Set((proposta.picks || []).map(pk => Number(pk.id))));

  el.innerHTML = `
    <div style="font-size:11px;font-weight:700;color:var(--text-3);text-transform:uppercase;letter-spacing:.06em;margin-bottom:8px">Itens enviados nesta proposta</div>
    ${jogadoresHtml}
    ${picksHtml}`;

  const extraPlayerIds = new Set((proposta.extra_jogadores || []).map(j => Number(j.id)));
  const extraPickIds = new Set((proposta.extra_picks || []).map(pk => Number(pk.id)));
  const sellerEl = document.getElementById('leilaoAdminSellerItens');
  if (sellerEl) {
    const sellerJogadoresHtml = _leilaoAdminCheckboxList(_leilaoAdminSellerData.players || [], 'leilaoAdminExtraPlayer',
      p => `${p.name} · ${p.position || ''} · OVR ${p.ovr || '?'}`, extraPlayerIds);
    const sellerPicksHtml = _leilaoAdminCheckboxList(_leilaoAdminSellerData.picks || [], 'leilaoAdminExtraPick',
      pk => `${pk.season_year} R${pk.round}${pk.original_team_name ? ' · ' + pk.original_team_name.trim() : ''}`, extraPickIds);
    sellerEl.innerHTML = `${sellerJogadoresHtml}${sellerPicksHtml}`;
  }
}

async function _leilaoAdminConfirmarResolucao() {
  const select = document.getElementById('leilaoAdminPropostaSelect');
  const propostaId = select ? Number(select.value) : null;
  if (!propostaId) { alert('Selecione o time vencedor.'); return; }

  const player_ids = [...document.querySelectorAll('.leilaoAdminOfertaPlayer:checked')].map(el => Number(el.value));
  const pick_ids = [...document.querySelectorAll('.leilaoAdminOfertaPick:checked')].map(el => Number(el.value));
  const extra_player_ids = [...document.querySelectorAll('.leilaoAdminExtraPlayer:checked')].map(el => Number(el.value));
  const extra_pick_ids = [...document.querySelectorAll('.leilaoAdminExtraPick:checked')].map(el => Number(el.value));

  if (!await confirmarSite('Confirmar esta resolução?\n\nA troca será executada e o leilão não poderá mais mudar de vencedor.')) return;
  try {
    await api('leilao.php', {
      method: 'POST',
      body: JSON.stringify({
        action: 'admin_fechar_leilao',
        leilao_id: _leilaoAdminLeilaoId,
        proposta_id: propostaId,
        player_ids, pick_ids, extra_player_ids, extra_pick_ids
      })
    });
    bootstrap.Modal.getInstance(document.getElementById('leilaoAdminResolveModal'))?.hide();
    showLeilaoAdmin(_leilaoAdminLeague);
  } catch (e) {
    alert('Erro ao resolver o leilão: ' + (e.error || e.message || ''));
  }
}

async function _leilaoAdminEncerrarSemTroca() {
  if (!_leilaoAdminLeilaoId) return;
  if (!await confirmarSite('Encerrar este leilão sem executar nenhuma troca?')) return;
  try {
    await api('leilao.php', {
      method: 'POST',
      body: JSON.stringify({ action: 'admin_encerrar_sem_troca', leilao_id: _leilaoAdminLeilaoId })
    });
    bootstrap.Modal.getInstance(document.getElementById('leilaoAdminResolveModal'))?.hide();
    showLeilaoAdmin(_leilaoAdminLeague);
  } catch (e) {
    alert('Erro ao encerrar o leilão: ' + (e.error || e.message || ''));
  }
}

const WAITLIST_STATUS_LABEL = { pending: 'Aguardando', link_sent: 'Link enviado', accepted: 'Aceito', registered: 'Cadastrado' };
const WAITLIST_STATUS_COLOR = { pending: '#f59e0b', link_sent: '#3b82f6', accepted: '#22c55e', registered: '#22c55e' };

async function loadWaitlistRequests() {
  const list = document.getElementById('waitlistModalList');
  if (list) list.innerHTML = '<div class="text-center py-3"><div class="spinner-border text-orange"></div></div>';

  try {
    const data = await api('waitlist.php');
    const requests = data.requests || [];
    updateWaitlistBadge(requests.filter(r => r.status === 'pending').length);

    if (!list) return;
    if (!requests.length) {
      list.innerHTML = '<div class="alert alert-info">Nenhum pedido de participação ainda.</div>';
      return;
    }

    const sorted = [...requests].sort((a, b) => (a.status === 'accepted') - (b.status === 'accepted'));

    list.innerHTML = sorted.map(r => {
      const link = `${window.location.origin}/register.php?token=${r.token}`;
      const statusLabel = WAITLIST_STATUS_LABEL[r.status] || r.status;
      const statusColor = WAITLIST_STATUS_COLOR[r.status] || '#8d8d98';
      const waLink = `https://wa.me/${r.phone}`;
      return `
        <div class="card mb-2" style="padding:12px 14px">
          <div class="d-flex align-items-center justify-content-between gap-2 flex-wrap">
            <div>
              <div style="font-weight:600">${escapeHtml(r.name)}</div>
              <a href="${waLink}" target="_blank" rel="noopener" style="font-size:12px;color:var(--text-2)"><i class="bi bi-whatsapp me-1" style="color:#22c55e"></i>${escapeHtml(r.phone)}</a>
              <div style="font-size:11px;color:var(--text-3)">${new Date(r.created_at).toLocaleDateString('pt-BR')}</div>
            </div>
            <span class="badge" style="background:${statusColor}22;color:${statusColor};border:1px solid ${statusColor}44">${statusLabel}</span>
          </div>
          ${r.status !== 'registered' && r.status !== 'accepted' ? `
          <div class="d-flex gap-2 mt-2 flex-wrap">
            <button class="btn btn-sm btn-outline-orange" onclick="waitlistCopyLink('${link}', ${r.id})">
              <i class="bi bi-clipboard me-1"></i>Copiar link de cadastro
            </button>
            <button class="btn btn-sm btn-outline-success" onclick="waitlistAccept(${r.id})">
              <i class="bi bi-check-lg me-1"></i>Aceitar
            </button>
            <button class="btn btn-sm btn-outline-danger" onclick="waitlistDismiss(${r.id})">
              <i class="bi bi-x-lg me-1"></i>Dispensar
            </button>
          </div>` : ''}
        </div>`;
    }).join('');
  } catch (e) {
    if (list) list.innerHTML = `<div class="alert alert-danger">Erro ao carregar: ${escapeHtml(e.error || 'desconhecido')}</div>`;
  }
}

async function waitlistCopyLink(link, id) {
  try {
    await navigator.clipboard.writeText(link);
    showAlert('success', 'Link copiado! Agora é só mandar pro interessado.');
  } catch (e) {
    await perguntarSite('Copie o link abaixo:', link);
  }
  try {
    await api('waitlist.php', { method: 'PUT', body: JSON.stringify({ id, action: 'mark_sent' }) });
    loadWaitlistRequests();
  } catch (e) {}
}

async function waitlistAccept(id) {
  try {
    await api('waitlist.php', { method: 'PUT', body: JSON.stringify({ id, action: 'accept' }) });
    loadWaitlistRequests();
  } catch (e) {
    showAlert('danger', e.error || 'Erro ao aceitar pedido.');
  }
}

async function waitlistDismiss(id) {
  if (!await confirmarSite('Dispensar este pedido de participação?')) return;
  try {
    await api('waitlist.php', { method: 'PUT', body: JSON.stringify({ id, action: 'dismiss' }) });
    loadWaitlistRequests();
  } catch (e) {
    showAlert('danger', e.error || 'Erro ao dispensar pedido.');
  }
}

function updateWaitlistBadge(count) {
  const badge = document.getElementById('waitlist-badge');
  if (!badge) return;
  if (count > 0) { badge.textContent = count; badge.style.display = 'inline-flex'; }
  else { badge.style.display = 'none'; }
}

function ensureCopyRosterModal() {
  if (document.getElementById('copyRosterModal')) return;
  const modal = document.createElement('div');
  modal.className = 'modal fade';
  modal.id = 'copyRosterModal';
  modal.tabIndex = -1;
  modal.innerHTML = `
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
      <div class="modal-content bg-dark border-orange">
        <div class="modal-header border-orange">
          <h5 class="modal-title text-white"><i class="bi bi-clipboard-check me-2 text-orange"></i>Elencos da liga</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <textarea id="copyRosterTextarea" class="form-control bg-dark text-white border-secondary" rows="14" readonly></textarea>
          <small class="text-light-gray d-block mt-2">Toque e segure para copiar no celular.</small>
        </div>
        <div class="modal-footer border-orange">
          <button type="button" class="btn btn-outline-light" id="copyRosterClipboardBtn">
            <i class="bi bi-clipboard me-1"></i>Copiar
          </button>
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
        </div>
      </div>
    </div>
  `;
  document.body.appendChild(modal);

  const copyBtn = modal.querySelector('#copyRosterClipboardBtn');
  if (copyBtn) {
    copyBtn.addEventListener('click', async () => {
      const textarea = document.getElementById('copyRosterTextarea');
      if (!textarea) return;
      try {
        await navigator.clipboard.writeText(textarea.value);
        alert('Elencos copiados para a área de transferência!');
      } catch (e) {
        textarea.focus();
        textarea.select();
      }
    });
  }
}

function ensureCopyPicksModal() {
  if (document.getElementById('copyPicksModal')) return;
  const modal = document.createElement('div');
  modal.className = 'modal fade';
  modal.id = 'copyPicksModal';
  modal.tabIndex = -1;
  modal.innerHTML = `
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
      <div class="modal-content bg-dark border-orange">
        <div class="modal-header border-orange">
          <h5 class="modal-title text-white"><i class="bi bi-calendar2-check me-2 text-orange"></i>Picks 1ª rodada</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <textarea id="copyPicksTextarea" class="form-control bg-dark text-white border-secondary" rows="14" readonly></textarea>
          <small class="text-light-gray d-block mt-2">Toque e segure para copiar no celular.</small>
        </div>
        <div class="modal-footer border-orange">
          <button type="button" class="btn btn-outline-light" id="copyPicksClipboardBtn">
            <i class="bi bi-clipboard me-1"></i>Copiar
          </button>
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
        </div>
      </div>
    </div>
  `;
  document.body.appendChild(modal);

  modal.querySelector('#copyPicksClipboardBtn').addEventListener('click', async () => {
    const textarea = document.getElementById('copyPicksTextarea');
    if (!textarea) return;
    try {
      await navigator.clipboard.writeText(textarea.value);
      alert('Picks copiadas para a área de transferência!');
    } catch (e) {
      textarea.focus();
      textarea.select();
    }
  });
}

function ensureCopyTradesModal() {
  if (document.getElementById('copyTradesModal')) return;
  const modal = document.createElement('div');
  modal.className = 'modal fade';
  modal.id = 'copyTradesModal';
  modal.tabIndex = -1;
  modal.innerHTML = `
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
      <div class="modal-content bg-dark border-orange">
        <div class="modal-header border-orange">
          <h5 class="modal-title text-white"><i class="bi bi-arrow-left-right me-2 text-orange"></i>Trocas da temporada</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <textarea id="copyTradesTextarea" class="form-control bg-dark text-white border-secondary" rows="14" readonly></textarea>
          <small class="text-light-gray d-block mt-2">Toque e segure para copiar no celular.</small>
        </div>
        <div class="modal-footer border-orange">
          <button type="button" class="btn btn-outline-light" id="copyTradesClipboardBtn">
            <i class="bi bi-clipboard me-1"></i>Copiar
          </button>
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
        </div>
      </div>
    </div>
  `;
  document.body.appendChild(modal);

  modal.querySelector('#copyTradesClipboardBtn').addEventListener('click', async () => {
    const textarea = document.getElementById('copyTradesTextarea');
    if (!textarea) return;
    try {
      await navigator.clipboard.writeText(textarea.value);
      alert('Trocas copiadas para a área de transferência!');
    } catch (e) {
      textarea.focus();
      textarea.select();
    }
  });
}

/** As trocas ACEITAS da temporada corrente, em texto pra colar no grupo. */
async function copyLeagueTrades() {
  const league = appState.currentLeague || 'ELITE';
  ensureCopyTradesModal();
  const textarea = document.getElementById('copyTradesTextarea');
  if (textarea) textarea.value = 'Carregando...';
  const modalEl = document.getElementById('copyTradesModal');
  if (modalEl) new bootstrap.Modal(modalEl).show();

  try {
    const data = await api(`admin.php?action=copy_trades&league=${league}`);
    if (textarea) textarea.value = data.text || 'Nenhuma troca encontrada.';
  } catch (e) {
    if (textarea) textarea.value = e.error || 'Erro ao copiar as trocas.';
  }
}

async function copyLeaguePicks() {
  const league = appState.currentLeague || document.getElementById('copyRosterLeague')?.value || 'ELITE';
  ensureCopyPicksModal();
  const textarea = document.getElementById('copyPicksTextarea');
  if (textarea) textarea.value = 'Carregando...';
  const modalEl = document.getElementById('copyPicksModal');
  if (modalEl) new bootstrap.Modal(modalEl).show();

  try {
    const data = await api(`admin.php?action=copy_picks&league=${league}`);
    if (textarea) textarea.value = data.text || 'Nenhuma pick encontrada.';
  } catch (e) {
    if (textarea) textarea.value = e.error || 'Erro ao copiar picks.';
  }
}

/**
 * Muda o total de rodadas do draft inicial, do próprio painel.
 *
 * Existia só dentro do initdraftselecao.php — o admin que queria tirar uma
 * rodada tinha que sair da Gestão, abrir a outra tela e achar a aba. O
 * endpoint é o mesmo; muda só de onde é chamado.
 *
 * Diminuir é o caso que dá errado, então confirma. Aumentar não apaga nada e
 * vai direto.
 */
async function _initDraftRodadas(total, token, atual, league) {
  if (total < atual && !await confirmarSite(
      `Passar de ${atual} para ${total} rodada(s)?\n\n`
    + `A última rodada sai da ordem. Se já houver escolha nela, o servidor recusa e nada muda.`)) return;

  try {
    const r = await api('initdraft.php', {
      method: 'POST',
      body: JSON.stringify({ action: 'set_total_rounds', token: decodeURIComponent(token), total_rounds: total }),
    });
    showAlert('success', {
      rodadas_criadas:   `Agora são ${r.total_rounds} rodadas. As novas já entraram na ordem.`,
      rodadas_removidas: `Agora são ${r.total_rounds} rodadas. As de cima saíram da ordem.`,
    }[r.ajuste] || `Agora são ${r.total_rounds} rodadas.`);
    showLeague(league);
  } catch (e) {
    showAlert('danger', e.error || 'Erro');
  }
}

async function copyLeagueRosters() {
  const league = appState.currentLeague || document.getElementById('copyRosterLeague')?.value || 'ELITE';
  ensureCopyRosterModal();
  const textarea = document.getElementById('copyRosterTextarea');
  if (textarea) {
    textarea.value = 'Carregando...';
  }
  const modalEl = document.getElementById('copyRosterModal');
  if (modalEl) {
    const modal = new bootstrap.Modal(modalEl);
    modal.show();
  }

  try {
    const data = await api(`admin.php?action=copy_rosters&league=${league}`);
    if (textarea) {
      textarea.value = data.text || 'Nenhum elenco encontrado.';
    }
  } catch (e) {
    if (textarea) {
      textarea.value = e.error || 'Erro ao copiar elencos.';
    }
  }
}

async function showLeague(league) {
  appState.view = 'league';
  appState.currentLeague = league;
  updateBreadcrumb();

  const container = document.getElementById('mainContainer');
  container.innerHTML = '<div class="text-center py-5"><div class="spinner-border" style="color:var(--red)"></div></div>';

  try {
    const [data, seasonData, draftData] = await Promise.all([
      api(`admin.php?action=teams&league=${league}`),
      api(`seasons.php?action=list_seasons&league=${league}`).catch(() => ({ seasons: [] })),
      api(`draft.php?action=active_draft&league=${league}`).catch(() => ({ draft: null }))
    ]);
    const teams = data.teams || [];
    const seasons = seasonData.seasons || [];
    // Sem o `|| seasons[0]`: quando todas as temporadas estão concluídas não
    // existe temporada ativa, e cair na primeira da lista fazia o botão dizer
    // "Avançar Temporada" onde o certo é "Iniciar Sprint".
    const currentSeason = seasons.find(s => s.status !== 'completed') || null;
    const seasonYear = currentSeason
      ? (currentSeason.start_year && currentSeason.season_number
          ? (parseInt(currentSeason.start_year) + parseInt(currentSeason.season_number) - 1)
          : (currentSeason.year || '—'))
      : '—';
    const seasonNumber = currentSeason ? (parseInt(currentSeason.season_number) || 1) : '—';
    const totalSeasons = currentSeason?.sprint_max_seasons || seasons[0]?.sprint_max_seasons || '—';

    // Sessão de Draft Inicial (initdraft) da temporada atual, se houver
    let initDraftSession = null;
    if (currentSeason?.id) {
      try {
        const idr = await api(`initdraft.php?action=session_for_season&season_id=${currentSeason.id}`);
        if (idr && idr.session) initDraftSession = idr.session;
      } catch (e) {}
    }

    const teamCards = teams.map(t => `
      <div class="col-6 col-md-4 col-xl-3">
        <div class="team-card" onclick="showTeam(${t.id})">
          <div class="d-flex align-items-center gap-2 mb-2">
            <img src="${escapeHtml(t.photo_url || '/img/default-team.png')}" class="team-logo" onerror="this.src='/img/default-team.png'">
            <div style="min-width:0">
              <div style="font-size:13px;font-weight:700;color:var(--text);line-height:1.2">${escapeHtml(t.city)}</div>
              <div style="font-size:12px;font-weight:600;color:var(--text-2);line-height:1.2">${escapeHtml(t.name)}</div>
              <div style="font-size:11px;color:var(--text-3)">${escapeHtml(t.owner_name)}</div>
            </div>
          </div>
          <div class="d-flex justify-content-between flex-wrap gap-1" style="font-size:11px">
            <span style="color:var(--text-2)"><i class="bi bi-people-fill" style="color:var(--red)"></i> ${t.player_count}</span>
            <span style="color:var(--text-2)"><i class="bi bi-star-fill" style="color:var(--red)"></i> ${t.cap_top8}</span>
            <span style="color:var(--text-2)"><i class="bi bi-hand-index-thumb" style="color:#f59e0b"></i> ${parseInt(t.tapas||0)}</span>
            <span style="color:var(--text-2)"><i class="bi bi-arrow-left-right" style="color:#3b82f6"></i> ${parseInt(t.trades_used||0)}</span>
            <span style="color:var(--text-2)"><i class="bi bi-person-dash" style="color:#22c55e"></i> ${parseInt(t.waivers_used||0)}</span>
          </div>
          <div class="d-flex align-items-center justify-content-between mt-2" style="font-size:11px;border-top:1px solid rgba(255,255,255,.06);padding-top:6px" onclick="event.stopPropagation()">
            <span style="color:var(--text-2)"><i class="bi bi-exclamation-triangle-fill" style="color:#f43f5e"></i> Avisos</span>
            <div class="d-flex align-items-center gap-1">
              <button class="btn-ghost" style="padding:1px 7px;font-size:12px;line-height:1.4" onclick="event.stopPropagation();_adminCardAvisosAdj(${t.id},'${escapeHtml(league)}',-1,this)">−</button>
              <span id="avisos-count-${t.id}" style="font-weight:700;min-width:18px;text-align:center;color:${parseInt(t.avisos_count||0)>0?'#f43f5e':'var(--text-2)'}">${parseInt(t.avisos_count||0)}</span>
              <button class="btn-ghost" style="padding:1px 7px;font-size:12px;line-height:1.4" onclick="event.stopPropagation();_adminCardAvisosAdj(${t.id},'${escapeHtml(league)}',1,this)">+</button>
            </div>
          </div>
        </div>
      </div>`).join('');

    const actions = [
      // Painel de Controle saiu daqui: as chaves da liga (trades, FA, janela de
      // tática) agora vêm direto na aba, logo abaixo — não faz sentido esconder
      // atrás de um card o que se olha toda hora.
      { icon: 'bi-arrow-left-right',  label: 'Trades',               fn: 'showTrades()',            color: '#3b82f6', bg: 'rgba(59,130,246,.12)' },
      { icon: 'bi-people-fill',       label: 'Free Agency',          fn: 'showFAAdmin()',           color: '#22c55e', bg: 'rgba(34,197,94,.12)'  },
      { icon: 'bi-bar-chart-steps',         label: 'Pontuação<br>por Time',      fn: 'showPointsManagement()',    color: '#06b6d4', bg: 'rgba(6,182,212,.12)'   },
      { icon: 'bi-clipboard-data-fill',     label: 'Pontuação',               fn: `showRegistroPontuacao('${league}')`,   color: '#10b981', bg: 'rgba(16,185,129,.12)'  },
      // Prêmios Estendidos saiu daqui: eles agora são preenchidos dentro do
      // card de Pontuação, na etapa 1, junto do resto da temporada — era um
      // card só pra atravessar de uma tela pra outra no meio do registro. A
      // função (showExtendedAwards) continua existindo pra corrigir
      // temporada antiga, só não tem mais atalho.
      // O Agendador de Fases saiu daqui a pedido. A tela e a função continuam
      // existindo (showScheduler), só não têm mais atalho no card.
      { icon: 'bi-shuffle',                 label: 'Controle<br>Drafts',         fn: `abrirControleDrafts('${league}')`,     color: '#a855f7', bg: 'rgba(168,85,247,.12)'  },
      /* A LOTERIA ENTRA AQUI, e não na tela da loteria.
         Conduzir a cerimônia é ato de administração de UMA liga, e quem
         administra nem sempre joga nela. Na tela da loteria isso virava um
         seletor de liga que todo mundo via; aqui o card já sabe de qual liga
         se trata, porque está dentro dela. */
      { icon: 'bi-dice-5-fill',             label: 'Loteria<br>do Draft',        fn: `abrirLoteria('${league}')`,            color: '#fc0025', bg: 'rgba(252,0,37,.12)'    },
      // A tabela de cap existia dentro de Configurações, embaixo dos campos de
      // edição da liga. Quem só queria conferir passava por uma tela de mexer
      // pra chegar numa de olhar.
      { icon: 'bi-cash-coin',               label: 'Controle<br>CAP / Jogadores', fn: `showControleCap('${league}')`,        color: '#f5c542', bg: 'rgba(245,197,66,.12)'  },
      // Página própria, não tela do admin: ela recarrega sozinha a cada
      // poucos segundos e vive aberta num canto, como um WhatsApp Web.
      //
      // Só admin geral: a página mostra conversa de grupo inteiro, e a
      // própria painelbot.php recusa quem não é. Sem este filtro o card
      // apareceria pra admin de liga e levaria a um redirect — botão que
      // não faz nada é pior que botão que não existe.
      ...(window.IS_GLOBAL_ADMIN ? [
        { icon: 'bi-whatsapp',              label: 'Painel<br>do Bot',          fn: `location.href='/painelbot.php'`, color: '#25d366', bg: 'rgba(37,211,102,.12)' },
      ] : []),
      { icon: 'bi-shield-check',            label: 'FBA SERASA',                fn: 'showSerasaAdmin()',         color: '#8b5cf6', bg: 'rgba(139,92,246,.12)'  },
      { icon: 'bi-person-dash-fill',        label: 'Dispensas',                 fn: 'showDispensas()',           color: '#ef4444', bg: 'rgba(239,68,68,.12)'   },
      /* BADGES. O card estava escondido desde a fusão, e a tela seguia
         existindo sem caminho até ela. Voltou porque agora tem fila de
         verdade: o GM compra a badge na loja e pede em Meu Elenco, e alguém
         precisa aprovar. O nome é Badges e não Tapas porque é assim que a
         liga chama hoje — a tela mostra os dois tipos, que dividem a mesma
         fila. */
      { icon: 'bi-patch-check-fill',        label: 'Badges',                    fn: `showTapas('${league}')`,    color: '#f59e0b', bg: 'rgba(245,197,66,.12)'  },
      { icon: 'bi-clipboard2-pulse',        label: 'Tática',                    fn: 'showTaticaAdmin()',         color: '#14b8a6', bg: 'rgba(20,184,166,.12)'  },
      // A ROOKIE não tem lenda — o card não aparece lá.
      ...(['ELITE', 'NEXT', 'RISE'].includes(league) ? [
        { icon: 'bi-star-fill',             label: 'Lendas',                    fn: `showLendas('${league}')`,   color: '#f5c542', bg: 'rgba(245,197,66,.12)'  },
      ] : []),
      { icon: 'bi-exclamation-triangle-fill', label: 'Punições',               fn: 'showPunicoes()',            color: '#f43f5e', bg: 'rgba(244,63,94,.12)'   },
      { icon: 'bi-trophy-fill',             label: 'Draft',                     fn: 'showAdminDraft()',          color: '#a855f7', bg: 'rgba(168,85,247,.12)'  },
      { icon: 'bi-hammer',                  label: 'Leilão',                    fn: `showLeilaoAdmin('${league}')`, color: '#ef4444', bg: 'rgba(239,68,68,.12)'  },
      { icon: 'bi-archive-fill',            label: 'Banco de<br>Classes',        fn: 'showDraftClassBank()',      color: '#a855f7', bg: 'rgba(168,85,247,.08)'  },
      /* Moeda de Free Agency é das ligas de baixo. A ELITE contrata por
         salário, então o card levava a uma tela de saldos que não valem nada
         lá — e a distribuição por classificação, que é o motivo do card
         existir, também não se aplica. */
      ...(league !== 'ELITE' ? [
        { icon: 'bi-coin',                  label: 'Moedas',                    fn: 'showCoins()',               color: '#f59e0b', bg: 'rgba(245,158,11,.12)'  },
      ] : []),
      ...(league === 'ROOKIE' ? [
        // Cadastro de GM novo na ROOKIE é sempre via link de convite (o mesmo
        // Link único da ROOKIE: um só link serve pra várias pessoas, dá pra
        // jogar no grupo. O de Convites (lista de espera) continua existindo
        // pra convidar alguém específico.
        { icon: 'bi-clipboard-plus',        label: 'Inscrição<br>ROOKIE',        fn: 'showConviteRookie()',       color: '#a855f7', bg: 'rgba(168,85,247,.12)'  },
      ] : []),
      ...(window.IS_GLOBAL_ADMIN ? [
        { icon: 'bi-lightning-fill',        label: 'Force<br>Trade',            fn: `showForceTradeModal('${league}')`, color: 'var(--red)', bg: 'color-mix(in srgb, var(--red) 12%, transparent)'   },
      ] : []),
    ];

    const actionTiles = actions.map(a => `
      <button class="action-tile" onclick="${a.fn}">
        <div class="action-tile-icon" style="background:${a.bg};color:${a.color}">
          <i class="bi ${a.icon}"></i>
        </div>
        <div class="action-tile-label">${a.label}</div>
        ${a.badgeId ? `<span class="action-tile-badge" id="${a.badgeId}" style="display:none">0</span>` : ''}
      </button>`).join('');

    // Card do Draft Inicial (initdraft) — aparece quando há sessão em configuração ou em andamento
    const initDraftCard = (initDraftSession && ['setup', 'in_progress'].includes(initDraftSession.status)) ? (() => {
      const isRunning = initDraftSession.status === 'in_progress';
      const statusColor = isRunning ? '#22c55e' : '#f59e0b';
      const statusBg = isRunning ? 'rgba(34,197,94,.1)' : 'rgba(245,158,11,.1)';
      const statusLabel = isRunning ? 'Em andamento' : 'Configurando';
      const sub = isRunning
        ? `Rodada ${initDraftSession.current_round || 1} de ${initDraftSession.total_rounds || '?'}`
        : 'Configure a ordem, o pool de jogadores e inicie o draft.';
      const token = encodeURIComponent(initDraftSession.access_token || '');
      return `
      <div class="panel mb-3" style="border-color:rgba(168,85,247,.35)">
        <div class="panel-header">
          <div>
            <div class="panel-title"><i class="bi bi-stars" style="color:#a855f7"></i> Draft Inicial — ${league}</div>
            <div class="panel-sub">${sub}</div>
          </div>
          <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
            <span class="pun-badge" style="background:${statusBg};color:${statusColor};border:1px solid ${statusColor}40">${statusLabel}</span>
            <a class="btn-ghost" style="color:#a855f7;border-color:rgba(168,85,247,.3);text-decoration:none" href="initdraftselecao.php?token=${token}">
              <i class="bi bi-gear me-1"></i> Configurar Draft Inicial
            </a>
          </div>
        </div>
        ${(() => {
          const n = Number(initDraftSession.total_rounds) || 0;
          return `
        <div style="padding:0 18px 14px;display:flex;align-items:center;gap:9px;flex-wrap:wrap">
          <span style="font-size:12.5px;color:var(--text-2)">Total de rodadas:</span>
          <button class="btn-ghost btn-sm" title="Tirar uma rodada" ${n <= 1 ? 'disabled' : ''}
                  onclick="_initDraftRodadas(${n - 1}, '${token}', ${n}, '${league}')">
            <i class="bi bi-dash-lg"></i></button>
          <b style="font-size:17px;min-width:22px;text-align:center;font-variant-numeric:tabular-nums">${n || '?'}</b>
          <button class="btn-ghost btn-sm" title="Acrescentar uma rodada" ${n >= 10 ? 'disabled' : ''}
                  onclick="_initDraftRodadas(${n + 1}, '${token}', ${n}, '${league}')">
            <i class="bi bi-plus-lg"></i></button>
          <span style="font-size:11px;color:var(--text-3)">
            Tirar só vale enquanto a última rodada não tiver nenhuma escolha feita.</span>
        </div>`;
        })()}
      </div>`;
    })() : '';

    const activeDraft = draftData?.draft;
    const draftCard = (activeDraft && ['setup', 'in_progress'].includes(activeDraft.status) && !currentSeason) ? (() => {
      const isRunning = activeDraft.status === 'in_progress';
      const statusColor = isRunning ? '#22c55e' : '#f59e0b';
      const statusBg = isRunning ? 'rgba(34,197,94,.1)' : 'rgba(245,158,11,.1)';
      const statusLabel = isRunning ? 'Em andamento' : 'Configurando';
      const timerEl = (isRunning && activeDraft.pick_deadline_ts)
        ? `<span id="admin-draft-pick-timer" style="font-size:12px;font-weight:700;font-variant-numeric:tabular-nums;color:#22c55e;margin-left:6px">⏱ --:--</span>`
        : '';
      const sub = isRunning
        ? `Rodada ${activeDraft.current_round || 1} · Pick ${activeDraft.current_pick || 1}${timerEl}`
        : 'Aguardando configuração da ordem de picks';
      return `
      <div class="panel mb-3" style="border-color:rgba(168,85,247,.35)">
        <div class="panel-header">
          <div>
            <div class="panel-title"><i class="bi bi-trophy-fill" style="color:#a855f7"></i> Draft de Temporada — ${league}</div>
            <div class="panel-sub">${sub}</div>
          </div>
          <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
            <span class="pun-badge" style="background:${statusBg};color:${statusColor};border:1px solid ${statusColor}40">${statusLabel}</span>
            <button class="btn-ghost" style="color:#a855f7;border-color:rgba(168,85,247,.3)" onclick="showAdminDraft('${league}')">
              <i class="bi bi-arrow-right-circle me-1"></i> Gerenciar Draft
            </button>
          </div>
        </div>
      </div>`;
    })() : '';

    container.innerHTML = `
      <div class="league-hero">
        <div>
          <div class="league-hero-name">
            <small>Liga</small>
            ${league}
          </div>
        </div>
        <div class="league-hero-stats">
          <div class="league-hero-stat">
            <div class="league-hero-stat-val">${teams.length}</div>
            <div class="league-hero-stat-lbl">Times</div>
          </div>
          <div class="league-hero-stat">
            <div class="league-hero-stat-val" style="font-size:15px;color:var(--text)">${seasonYear}</div>
            <div class="league-hero-stat-lbl">Temp. ${seasonNumber}</div>
          </div>
          <div class="league-hero-stat">
            <div class="league-hero-stat-val" style="color:var(--red)">${seasonNumber}<span style="font-size:13px;font-weight:400;color:var(--text-3)">/${totalSeasons}</span></div>
            <div class="league-hero-stat-lbl">Temporadas</div>
          </div>
        </div>
        <div class="league-hero-tools">
          <div class="league-search-wrap">
            <input type="text" id="leaguePlayerSearch" placeholder="Buscar jogador…">
            <button id="leaguePlayerSearchBtn"><i class="bi bi-search"></i></button>
          </div>
          <button class="btn-ghost" id="copyRosterBtn">
            <i class="bi bi-clipboard"></i> Elencos
          </button>
          <button class="btn-ghost" id="copyPicksBtn">
            <i class="bi bi-calendar2-check"></i> Picks
          </button>
          <button class="btn-ghost" id="copyTradesBtn" title="As trocas aceitas nesta temporada">
            <i class="bi bi-arrow-left-right"></i> Trocas
          </button>
          ${currentSeason
            ? (totalSeasons !== '—' && Number(seasonNumber) >= Number(totalSeasons)
                ? `<button class="btn-ghost" style="color:#ef4444;border-color:rgba(239,68,68,.3)" onclick="showFinalizarSprint('${league}')">
                     <i class="bi bi-flag-fill me-1"></i>Finalizar Sprint
                   </button>`
                : `<button class="btn-ghost" style="color:#10b981;border-color:rgba(16,185,129,.3)" onclick="showAvancarTemporada('${league}')">
                     <i class="bi bi-arrow-right-circle-fill me-1"></i>Avançar Temporada
                   </button>`)
            : `<button class="btn-ghost" style="color:#f97316;border-color:rgba(249,115,22,.3)" onclick="showAvancarTemporada('${league}')">
                 <i class="bi bi-play-circle-fill me-1"></i>Iniciar Sprint
               </button>`
          }
        </div>
      </div>

      <div id="leaguePlayerSearchResults"></div>

      <div id="seasonChecklist"></div>

      ${initDraftCard}
      ${draftCard}

      <div class="action-grid">${actionTiles}</div>

      <div id="leagueConfigInline" class="panel mb-3" style="display:none">
        <div class="panel-header">
          <div class="panel-title"><i class="bi bi-sliders" style="color:#94a3b8"></i> Configurações</div>
          <button class="btn-ghost" style="padding:6px 10px;font-size:12px" id="saveConfigInlineBtn">
            <i class="bi bi-save2 me-1"></i>Salvar
          </button>
        </div>
        <div id="leagueConfigInlineBody"></div>
      </div>

      <div class="panel mb-3" id="irregularesPanel">
        <div class="panel-header">
          <div class="panel-title"><i class="bi bi-exclamation-triangle-fill" style="color:#f59e0b"></i> Times irregulares</div>
          <span id="irrResumo" style="font-size:12px;color:var(--text-3)">carregando…</span>
        </div>
        <div id="irregularesBody"><div style="font-size:12px;color:var(--text-3)">Conferindo elenco e cap de cada time…</div></div>
      </div>

      <div class="panel mb-3" id="leagueQuickSearchPanel">
        <div class="panel-header">
          <div class="panel-title"><i class="bi bi-search" style="color:#94a3b8"></i> Busca Rápida</div>
          <div style="display:flex;gap:6px">
            <button class="btn-ghost" id="srchTabPlayer" style="font-size:12px" onclick="setLeagueSearchType('player')"><i class="bi bi-person-fill me-1"></i>Jogador</button>
            <button class="btn-ghost" id="srchTabPick" style="font-size:12px;color:var(--text-2)" onclick="setLeagueSearchType('pick')"><i class="bi bi-calendar2-check me-1"></i>Pick</button>
          </div>
        </div>
        <div id="srchPlayerPanel">
          <div class="d-flex gap-2">
            <input type="text" id="srchPlayerInput" class="form-control bg-dark text-white" style="border-color:color-mix(in srgb, var(--red) 35%, transparent);font-size:13px" placeholder="Nome do jogador...">
            <button class="btn btn-sm" style="background:var(--red);color:#fff;white-space:nowrap;padding:6px 14px" onclick="runLeaguePlayerSearch()"><i class="bi bi-search"></i></button>
          </div>
          <div id="srchPlayerResults" class="mt-2"></div>
        </div>
        <div id="srchPickPanel" style="display:none">
          <select id="srchPickTeam" class="form-select bg-dark text-white" style="border-color:color-mix(in srgb, var(--red) 35%, transparent);font-size:13px" onchange="runLeaguePickSearch(this.value)">
            <option value="">Selecionar time...</option>
          </select>
          <div id="srchPickResults" class="mt-2"></div>
        </div>
      </div>

      <div class="panel">
        <div class="panel-header">
          <div class="panel-title" style="margin-bottom:0"><i class="bi bi-people-fill"></i> Times</div>
          <div class="d-flex align-items-center gap-2">
            <input type="search" id="buscaTime" placeholder="Buscar time ou GM"
                   autocomplete="off" oninput="filtrarTimes(this.value)"
                   style="background:var(--panel-2);border:1px solid var(--border-md);border-radius:8px;
                          padding:5px 10px;color:var(--text);font-size:12px;width:190px;outline:none">
            <span id="buscaTimeConta" style="font-size:12px;color:var(--text-3);white-space:nowrap">${teams.length} cadastrados</span>
          </div>
        </div>
        <div class="row g-2 mt-1" id="gradeTimes">${teamCards || '<div class="col-12"><p class="empty-state">Nenhum time cadastrado.</p></div>'}</div>
        <p class="empty-state" id="buscaTimeVazio" style="display:none">Nenhum time com esse nome.</p>
      </div>
    `;

    setupLeaguePlayerSearch(league);
    setupLeagueQuickSearch(league);
    document.getElementById('copyRosterBtn')?.addEventListener('click', copyLeagueRosters);
    document.getElementById('copyPicksBtn')?.addEventListener('click', copyLeaguePicks);
    document.getElementById('copyTradesBtn')?.addEventListener('click', copyLeagueTrades);

    if (activeDraft?.status === 'in_progress' && activeDraft?.pick_deadline_ts) {
      _startAdminDraftTimer(Number(activeDraft.pick_deadline_ts), 'admin-draft-pick-timer');
    }

    try {
      const approvalData = await api('user-approval.php');
      const count = (approvalData.users || []).length;
      const badge = document.getElementById('action-badge-approvals');
      if (badge && count > 0) { badge.textContent = count; badge.style.display = 'inline-flex'; }
    } catch (e) {}

    ensureOuvidoriaModal();
    _loadLeagueConfigInline(league);
    _loadSeasonChecklist(league);
    // Depois da aba montar: a conta passa por getTeamCapSummary de time a
    // time, e nao vale a pena segurar a tela inteira por ela.
    carregarIrregulares(league);
  } catch (e) {
    container.innerHTML = '<div class="alert alert-danger">Erro ao carregar liga</div>';
  }
}

// Checklist da temporada: o que ainda falta pra poder virar a temporada.
// Carrega depois da tela montada pra não segurar o render da liga.
// Janela de tática e progresso dos elencos, ao lado das outras chaves da liga.
// Vem do admin-control.php porque são estados calculados, não configuração.
async function _carregarControlesExtras(league) {
  // A TÁTICA VEM DE OUTRA API. O estado dela mora na tactic_edit_windows e
  // quem sabe responder é a admin-control, então os botões dela chegam
  // depois dos outros dois. A linha já existe no HTML de cima — aqui só
  // entram o selo e os botões, no buraco reservado, pra tela não pular.
  const alvo = document.getElementById(`tatCtrl_${league}`);
  const extra = document.getElementById(`ctrlExtra_${league}`);
  if (!alvo) return;
  try {
    const d = await api(`admin-control.php?league=${encodeURIComponent(league)}`);
    const aberta = !!(d.tactic_window || {}).open;

    alvo.innerHTML = `
      <span id="tatBadge_${league}" hidden></span>
      <button class="btn btn-sm ${aberta ? 'btn-success' : 'btn-outline-success'}"
              onclick="toggleTatica('${league}', 1)" id="tatOnBtn_${league}">Aberta</button>
      <button class="btn btn-sm ${!aberta ? 'btn-danger' : 'btn-outline-danger'}"
              onclick="toggleTatica('${league}', 0)" id="tatOffBtn_${league}">Fechada</button>`;

    // Elencos atualizados não é uma janela: não abre nem fecha, é um
    // placar. Fica embaixo do bloco, e só depois do draft, que é quando a
    // conta passa a querer dizer alguma coisa.
    if (extra) {
      const pct = d.teams_total ? Math.round((d.teams_updated / d.teams_total) * 100) : 100;
      extra.innerHTML = d.draft_concluido ? `
        <div style="display:flex;align-items:center;gap:8px;background:var(--panel-2);
                    border:1px solid var(--border);border-radius:var(--radius-sm);padding:7px 12px">
          <i class="bi bi-people-fill" style="color:var(--amber);font-size:13px"></i>
          <span style="font-size:12px;font-weight:600;color:var(--text)">Elencos atualizados</span>
          <span class="lgcfg-selo ${pct === 100 ? 'on' : 'off'}">${d.teams_updated}/${d.teams_total}</span>
        </div>` : '';
    }
  } catch (e) {
    alvo.innerHTML = '<span class="lgcfg-selo off">indisponível</span>';
    if (extra) extra.innerHTML = '';
  }
}

async function _loadSeasonChecklist(league) {
  const wrap = document.getElementById('seasonChecklist');
  if (!wrap) return;
  try {
    const data = await api(`seasons.php?action=season_checklist&league=${encodeURIComponent(league)}`);
    if (!data.season || !(data.itens || []).length) { wrap.innerHTML = ''; return; }

    const obrigatoriosPendentes = data.itens.filter(i => i.obrigatorio && i.feito !== true).length;

    const linhas = data.itens.map(i => {
      const estado = i.feito === true ? 'ok' : (i.feito === null ? 'indef' : 'pend');
      const icone  = i.feito === true ? 'bi-check-circle-fill'
                   : (i.feito === null ? 'bi-question-circle' : 'bi-circle');
      return `
        <div class="ck-item ${estado}">
          <i class="bi ${icone}"></i>
          <div class="ck-txt">
            <div class="ck-titulo">${escapeHtml(i.titulo)}${i.obrigatorio ? '' : ' <span class="ck-opt">opcional</span>'}</div>
            ${i.detalhe ? `<div class="ck-sub">${escapeHtml(i.detalhe)}</div>` : ''}
          </div>
        </div>`;
    }).join('');

    wrap.innerHTML = `
      <div class="panel mt-3">
        <div class="panel-title d-flex align-items-center justify-content-between flex-wrap gap-2">
          <span><i class="bi bi-list-check" style="color:#10b981"></i> Checklist da Temporada ${parseInt(data.season.season_number)}</span>
          <span class="ck-resumo ${data.completo ? 'ok' : ''}">
            ${data.completo
              ? '<i class="bi bi-check-lg"></i> pronta pra avançar'
              : `${obrigatoriosPendentes} ${obrigatoriosPendentes === 1 ? 'item pendente' : 'itens pendentes'}`}
          </span>
        </div>
        <div class="ck-lista">${linhas}</div>
      </div>
      <style>
        .ck-lista { display:grid; grid-template-columns:repeat(auto-fit,minmax(240px,1fr)); gap:10px; padding:4px 0; }
        .ck-item { display:flex; align-items:flex-start; gap:10px; padding:10px 12px;
                   border:1px solid var(--border); border-radius:10px; background:var(--panel-2); }
        .ck-item i { font-size:15px; flex-shrink:0; margin-top:1px; }
        .ck-item.ok    { border-color:rgba(16,185,129,.3); }
        .ck-item.ok i  { color:#10b981; }
        .ck-item.pend i{ color:var(--text-3); }
        .ck-item.indef i { color:#f59e0b; }
        .ck-titulo { font-size:13px; font-weight:700; }
        .ck-item.ok .ck-titulo { color:#10b981; }
        .ck-sub { font-size:11px; color:var(--text-3); margin-top:1px; }
        .ck-opt { font-size:9.5px; font-weight:700; text-transform:uppercase; letter-spacing:.4px;
                  color:var(--text-3); border:1px solid var(--border); border-radius:20px; padding:1px 6px; }
        .ck-resumo { font-size:11px; font-weight:700; color:var(--text-3);
                     background:var(--panel-3); border-radius:20px; padding:3px 10px; }
        .ck-resumo.ok { color:#10b981; background:rgba(16,185,129,.12); }
      </style>`;
  } catch (e) {
    wrap.innerHTML = '';
  }
}

function setupLeaguePlayerSearch(league) {
  const input = document.getElementById('leaguePlayerSearch');
  const button = document.getElementById('leaguePlayerSearchBtn');
  const results = document.getElementById('leaguePlayerSearchResults');
  if (!input || !results) return;

  let debounceTimer = null;

  const runSearch = async () => {
    const term = (input.value || '').trim();
    if (term.length < 2) {
      results.innerHTML = '';
      return;
    }
    results.innerHTML = '<div class="search-results-panel"><div class="spinner-border" style="color:var(--red);width:1.25rem;height:1.25rem" role="status"></div></div>';
    try {
      const data = await api(`admin.php?action=search_players&league=${encodeURIComponent(league)}&query=${encodeURIComponent(term)}`);
      const players = data.players || [];
      if (!players.length) {
        results.innerHTML = '<div class="search-results-panel" style="color:var(--text-3)">Nenhum jogador encontrado.</div>';
        return;
      }
      const rows = players.map(p => {
        const ovr = p.ovr != null ? p.ovr : '-';
        const age = p.age != null ? p.age : '-';
        return `<div class="search-result-row">
          <div>
            <div style="font-size:13px;font-weight:600;color:var(--text)">${escapeHtml(p.name)}</div>
            <div style="font-size:11px;color:var(--text-3)">${p.position || '-'} · OVR ${ovr} · ${age} anos</div>
          </div>
          <div style="font-size:12px;color:var(--text-2);text-align:right">${escapeHtml((p.team_city||'') + ' ' + (p.team_name||''))}</div>
        </div>`;
      }).join('');
      results.innerHTML = `<div class="search-results-panel">${rows}</div>`;
    } catch (e) {
      results.innerHTML = `<div class="search-results-panel" style="color:var(--red)">${e.error || 'Erro ao buscar.'}</div>`;
    }
  };

  input.addEventListener('input', () => {
    if (debounceTimer) clearTimeout(debounceTimer);
    debounceTimer = setTimeout(runSearch, 350);
  });
  button?.addEventListener('click', runSearch);
  input.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') { e.preventDefault(); runSearch(); }
  });
}

// =====================================================================
// League Quick Search
// =====================================================================

const _leagueSearchCache = { players: [], ownedPicks: [], awayPicks: [] };

function setupLeagueQuickSearch(league) {
  const input = document.getElementById('srchPlayerInput');
  if (!input) return;
  input.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') { e.preventDefault(); runLeaguePlayerSearch(); }
  });
}

function setLeagueSearchType(type) {
  const playerPanel = document.getElementById('srchPlayerPanel');
  const pickPanel   = document.getElementById('srchPickPanel');
  const tabPlayer   = document.getElementById('srchTabPlayer');
  const tabPick     = document.getElementById('srchTabPick');
  if (!playerPanel) return;
  if (type === 'player') {
    playerPanel.style.display = '';
    pickPanel.style.display   = 'none';
    tabPlayer.style.color = 'var(--text)';
    tabPick.style.color   = 'var(--text-2)';
  } else {
    playerPanel.style.display = 'none';
    pickPanel.style.display   = '';
    tabPlayer.style.color = 'var(--text-2)';
    tabPick.style.color   = 'var(--text)';
    _populateSrchPickTeams();
  }
}

async function _populateSrchPickTeams() {
  const sel = document.getElementById('srchPickTeam');
  if (!sel || sel.dataset.loaded) return;
  const league = appState.currentLeague;
  try {
    const data = await api(`admin.php?action=teams&league=${encodeURIComponent(league)}`);
    sel.innerHTML = '<option value="">Selecionar time...</option>';
    (data.teams || []).forEach(t => {
      const opt = document.createElement('option');
      opt.value = t.id;
      opt.textContent = `${t.city} ${t.name}`;
      sel.appendChild(opt);
    });
    sel.dataset.loaded = '1';
  } catch (e) {}
}

async function runLeaguePlayerSearch() {
  const input   = document.getElementById('srchPlayerInput');
  const results = document.getElementById('srchPlayerResults');
  if (!input || !results) return;
  const term = input.value.trim();
  if (term.length < 2) { results.innerHTML = ''; return; }
  const league = appState.currentLeague;
  results.innerHTML = '<div style="color:var(--text-2);font-size:13px;padding:6px 0">Buscando...</div>';
  try {
    const data = await api(`admin.php?action=search_players&league=${encodeURIComponent(league)}&query=${encodeURIComponent(term)}`);
    let players = (data.players || []);
    players.sort((a, b) => (Number(b.ovr) || 0) - (Number(a.ovr) || 0));
    players = players.slice(0, 10);
    _leagueSearchCache.players = players;
    if (!players.length) {
      results.innerHTML = '<div style="color:var(--text-3);font-size:13px;padding:6px 0">Nenhum jogador encontrado.</div>';
      return;
    }
    results.innerHTML = players.map(p => `
      <div style="display:flex;align-items:center;justify-content:space-between;padding:8px 0;border-bottom:1px solid rgba(255,255,255,.06)">
        <div>
          <div style="font-size:13px;font-weight:600;color:var(--text)">${escapeHtml(p.name)}</div>
          <div style="font-size:11px;color:var(--text-3)">${p.position||'-'} · OVR ${p.ovr??'-'} · ${p.age??'-'} anos · ${escapeHtml((p.team_city||'') + ' ' + (p.team_name||''))}</div>
        </div>
        <div style="display:flex;gap:4px;flex-shrink:0">
          <button class="btn-ghost" style="padding:4px 8px;font-size:11px" title="Mover time" onclick="srchMovePlayer(${p.id})"><i class="bi bi-arrow-left-right"></i></button>
          <button class="btn-ghost" style="padding:4px 8px;font-size:11px" title="Editar" onclick="srchEditPlayer(${p.id})"><i class="bi bi-pencil"></i></button>
          <button class="btn-ghost" style="padding:4px 8px;font-size:11px;color:var(--red)" title="Deletar" onclick="srchDeletePlayer(${p.id})"><i class="bi bi-trash"></i></button>
        </div>
      </div>`).join('');
  } catch (e) {
    results.innerHTML = `<div style="color:var(--red);font-size:13px;padding:6px 0">Erro: ${e.error || 'Erro ao buscar'}</div>`;
  }
}

async function runLeaguePickSearch(teamId) {
  if (!teamId) teamId = document.getElementById('srchPickTeam')?.value;
  const results = document.getElementById('srchPickResults');
  if (!results || !teamId) return;
  results.innerHTML = '<div style="color:var(--text-2);font-size:13px;padding:6px 0">Buscando...</div>';
  try {
    const data = await api(`picks.php?team_id=${teamId}&include_away=1`);
    const curYear = new Date().getFullYear();
    const owned = (data.picks || []).filter(p => Number(p.round) === 1 && Number(p.season_year) >= curYear);
    const away  = (data.picks_away || []).filter(p => Number(p.round) === 1 && Number(p.season_year) >= curYear);
    _leagueSearchCache.ownedPicks = owned;
    _leagueSearchCache.awayPicks  = away;
    if (!owned.length && !away.length) {
      results.innerHTML = '<div style="color:var(--text-3);font-size:13px;padding:6px 0">Nenhuma pick de 1ª rodada encontrada.</div>';
      return;
    }
    const swBadge = st => st
      ? `<span style="font-size:10px;color:#f59e0b;background:rgba(245,158,11,.12);border:1px solid rgba(245,158,11,.3);border-radius:4px;padding:1px 5px;margin-left:4px">${st}</span>`
      : '';
    const pickRow = (p, isAway) => `
      <div style="display:flex;align-items:center;justify-content:space-between;padding:8px 0;border-bottom:1px solid rgba(255,255,255,.06)">
        <div>
          <div style="font-size:13px;font-weight:600;color:var(--text)">${p.season_year} R${p.round}${swBadge(p.swap_type)}</div>
          <div style="font-size:11px;color:var(--text-3)">${isAway
            ? 'Atual: ' + escapeHtml((p.current_team_city||'') + ' ' + (p.current_team_name||''))
            : 'Original: ' + escapeHtml((p.original_team_city||'') + ' ' + (p.original_team_name||''))
          }</div>
        </div>
        <div style="display:flex;gap:4px;flex-shrink:0">
          <button class="btn-ghost" style="padding:4px 8px;font-size:11px" title="Mover dono" onclick="srchMovePick(${p.id},${isAway})"><i class="bi bi-arrow-left-right"></i></button>
          <button class="btn-ghost" style="padding:4px 8px;font-size:11px;color:#f59e0b" title="SWAP" onclick="srchSwapPick(${p.id},${isAway})">SWAP</button>
        </div>
      </div>`;
    let html = '';
    if (owned.length) {
      html += `<div style="font-size:10px;font-weight:700;color:var(--text-3);text-transform:uppercase;letter-spacing:.08em;padding-top:4px;padding-bottom:2px">Picks que o time possui</div>`;
      html += owned.map(p => pickRow(p, false)).join('');
    }
    if (away.length) {
      html += `<div style="font-size:10px;font-weight:700;color:var(--text-3);text-transform:uppercase;letter-spacing:.08em;padding-top:${owned.length?'12':'4'}px;padding-bottom:2px">Picks originais do time (em outros times)</div>`;
      html += away.map(p => pickRow(p, true)).join('');
    }
    results.innerHTML = html;
  } catch (e) {
    results.innerHTML = `<div style="color:var(--red);font-size:13px;padding:6px 0">Erro: ${e.error || 'Erro ao buscar picks'}</div>`;
  }
}

// --- Player actions from search context ---

function srchMovePlayer(playerId) {
  const p = _leagueSearchCache.players.find(x => x.id == playerId);
  if (!p) return;
  const league = appState.currentLeague;
  const modal = document.createElement('div');
  modal.className = 'modal fade';
  modal.innerHTML = `<div class="modal-dialog"><div class="modal-content bg-dark-panel"><div class="modal-header border-orange">
<h5 class="modal-title text-white">Mover ${escapeHtml(p.name)}</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
<div class="modal-body"><div class="mb-3"><label class="form-label text-light-gray">Time de destino</label>
<select class="form-select bg-dark text-white border-orange" id="srchMovePlayerTeam"><option value="">Carregando...</option></select></div></div>
<div class="modal-footer border-orange"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
<button type="button" class="btn btn-orange" onclick="_applySrchMovePlayer(${playerId})">Mover</button></div></div></div>`;
  document.body.appendChild(modal);
  api(`admin.php?action=teams&league=${encodeURIComponent(league)}`).then(data => {
    const sel = modal.querySelector('#srchMovePlayerTeam');
    sel.innerHTML = '';
    (data.teams || []).filter(t => t.id != p.team_id).forEach(t => {
      const opt = document.createElement('option');
      opt.value = t.id;
      opt.textContent = `${t.city} ${t.name}`;
      sel.appendChild(opt);
    });
  });
  new bootstrap.Modal(modal).show();
  modal.addEventListener('hidden.bs.modal', () => modal.remove());
}

async function _applySrchMovePlayer(playerId) {
  const teamId = parseInt(document.getElementById('srchMovePlayerTeam')?.value);
  if (!teamId) { alert('Selecione o time destino!'); return; }
  try {
    await api('admin.php?action=player', { method: 'PUT', body: JSON.stringify({ player_id: playerId, team_id: teamId }) });
    const m = document.querySelector('.modal.show');
    bootstrap.Modal.getInstance(m)?.hide();
    showAlert('success', 'Jogador movido!');
    runLeaguePlayerSearch();
  } catch (e) { alert('Erro: ' + (e.error || 'Desconhecido')); }
}

function srchEditPlayer(playerId) {
  const p = _leagueSearchCache.players.find(x => x.id == playerId);
  if (!p) return;
  const modal = document.createElement('div');
  modal.className = 'modal fade';
  modal.innerHTML = `<div class="modal-dialog"><div class="modal-content bg-dark-panel"><div class="modal-header border-orange">
<h5 class="modal-title text-white">Editar ${escapeHtml(p.name)}</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
<div class="modal-body">
<div class="mb-3"><label class="form-label text-light-gray">Posição</label>
<input type="text" class="form-control bg-dark text-white border-orange" id="srchEditPos" value="${escapeHtml(p.position||'')}"></div>
<div class="row">
<div class="col-6 mb-3"><label class="form-label text-light-gray">Idade</label>
<input type="number" class="form-control bg-dark text-white border-orange" id="srchEditAge" value="${p.age||''}" min="16" max="60"></div>
<div class="col-6 mb-3"><label class="form-label text-light-gray">OVR</label>
<input type="number" class="form-control bg-dark text-white border-orange" id="srchEditOvr" value="${p.ovr||0}" min="0" max="99"></div>
</div>
<div class="mb-3"><label class="form-label text-light-gray">Papel</label>
<select class="form-select bg-dark text-white border-orange" id="srchEditRole">
<option value="Titular" ${p.role==='Titular'?'selected':''}>Titular</option>
<option value="Banco" ${p.role==='Banco'?'selected':''}>Banco</option>
<option value="Outro" ${p.role==='Outro'?'selected':''}>Outro</option>
<option value="G-League" ${p.role==='G-League'?'selected':''}>G-League</option>
</select></div>
</div>
<div class="modal-footer border-orange"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
<button type="button" class="btn btn-orange" onclick="_applySrchEditPlayer(${playerId})">Salvar</button></div></div></div>`;
  document.body.appendChild(modal);
  new bootstrap.Modal(modal).show();
  modal.addEventListener('hidden.bs.modal', () => modal.remove());
}

async function _applySrchEditPlayer(playerId) {
  const ageVal = parseInt(document.getElementById('srchEditAge')?.value || '', 10);
  const data = {
    player_id: playerId,
    position: document.getElementById('srchEditPos').value,
    ovr: parseInt(document.getElementById('srchEditOvr').value, 10),
    role: document.getElementById('srchEditRole').value,
  };
  if (!Number.isNaN(ageVal)) data.age = ageVal;
  try {
    await api('admin.php?action=player', { method: 'PUT', body: JSON.stringify(data) });
    const m = document.querySelector('.modal.show');
    bootstrap.Modal.getInstance(m)?.hide();
    showAlert('success', 'Jogador atualizado!');
    runLeaguePlayerSearch();
  } catch (e) { alert('Erro: ' + (e.error || 'Desconhecido')); }
}

async function srchDeletePlayer(playerId) {
  if (!await confirmarSite('Deletar jogador?')) return;
  try {
    await api(`admin.php?action=player&id=${playerId}`, { method: 'DELETE' });
    showAlert('success', 'Jogador deletado!');
    runLeaguePlayerSearch();
  } catch (e) { alert('Erro ao deletar jogador'); }
}

// --- Pick actions from search context ---

function srchMovePick(pickId, isAway) {
  const cache = isAway ? _leagueSearchCache.awayPicks : _leagueSearchCache.ownedPicks;
  const p = cache.find(x => x.id == pickId);
  if (!p) return;
  const league = appState.currentLeague;
  const modal = document.createElement('div');
  modal.className = 'modal fade';
  modal.innerHTML = `<div class="modal-dialog"><div class="modal-content bg-dark-panel"><div class="modal-header border-orange">
<h5 class="modal-title text-white">Mover Pick — ${p.season_year} R${p.round}</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
<div class="modal-body">
<p style="font-size:13px;color:var(--text-2);margin-bottom:12px">Original: <strong>${escapeHtml((p.original_team_city||p.current_team_city||'') + ' ' + (p.original_team_name||p.current_team_name||''))}</strong></p>
<div class="mb-3"><label class="form-label text-light-gray">Mover para o time</label>
<select class="form-select bg-dark text-white border-orange" id="srchMovePickTeam"><option value="">Carregando...</option></select></div>
</div>
<div class="modal-footer border-orange"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
<button type="button" class="btn btn-orange" onclick="_applySrchMovePick(${pickId},${isAway})">Mover</button></div></div></div>`;
  document.body.appendChild(modal);
  api(`admin.php?action=teams&league=${encodeURIComponent(league)}`).then(data => {
    const sel = modal.querySelector('#srchMovePickTeam');
    sel.innerHTML = '';
    (data.teams || []).forEach(t => {
      const opt = document.createElement('option');
      opt.value = t.id;
      opt.textContent = `${t.city} ${t.name}`;
      if (t.id == p.team_id) opt.selected = true;
      sel.appendChild(opt);
    });
  });
  new bootstrap.Modal(modal).show();
  modal.addEventListener('hidden.bs.modal', () => modal.remove());
}

async function _applySrchMovePick(pickId, isAway) {
  const cache = isAway ? _leagueSearchCache.awayPicks : _leagueSearchCache.ownedPicks;
  const p = cache.find(x => x.id == pickId);
  if (!p) return;
  const destTeamId = parseInt(document.getElementById('srchMovePickTeam')?.value);
  if (!destTeamId) { alert('Selecione o time destino!'); return; }
  try {
    await api('admin.php?action=pick', { method: 'PUT', body: JSON.stringify({
      pick_id: pickId,
      team_id: destTeamId,
      original_team_id: p.original_team_id,
      season_year: p.season_year,
      round: p.round,
      swap_type: p.swap_type || null,
      notes: p.notes || null
    })});
    const m = document.querySelector('.modal.show');
    bootstrap.Modal.getInstance(m)?.hide();
    showAlert('success', 'Pick movida!');
    const teamId = document.getElementById('srchPickTeam')?.value;
    if (teamId) runLeaguePickSearch(teamId);
  } catch (e) { alert('Erro: ' + (e.error || 'Desconhecido')); }
}

function srchSwapPick(pickId, isAway) {
  const cache = isAway ? _leagueSearchCache.awayPicks : _leagueSearchCache.ownedPicks;
  const p = cache.find(x => x.id == pickId);
  if (!p) return;
  const modal = document.createElement('div');
  modal.className = 'modal fade';
  modal.innerHTML = `<div class="modal-dialog modal-sm"><div class="modal-content bg-dark-panel"><div class="modal-header border-orange">
<h5 class="modal-title text-white" style="font-size:14px">Swap — ${p.season_year} R${p.round}</h5>
<button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
<div class="modal-body"><div class="d-flex flex-column gap-2">
<button type="button" class="btn ${!p.swap_type ? 'btn-orange' : 'btn-secondary'}" onclick="_applySrchSwap(${pickId},${isAway},'')">Nenhum</button>
<button type="button" class="btn ${p.swap_type==='SW' ? 'btn-orange' : 'btn-outline-light'}" onclick="_applySrchSwap(${pickId},${isAway},'SW')">SW — Worst</button>
<button type="button" class="btn ${p.swap_type==='SB' ? 'btn-orange' : 'btn-outline-light'}" onclick="_applySrchSwap(${pickId},${isAway},'SB')">SB — Best</button>
</div></div></div></div>`;
  document.body.appendChild(modal);
  new bootstrap.Modal(modal).show();
  modal.addEventListener('hidden.bs.modal', () => modal.remove());
}

async function _applySrchSwap(pickId, isAway, swapType) {
  const cache = isAway ? _leagueSearchCache.awayPicks : _leagueSearchCache.ownedPicks;
  const p = cache.find(x => x.id == pickId);
  if (!p) return;
  try {
    await api('admin.php?action=pick', { method: 'PUT', body: JSON.stringify({
      pick_id: pickId,
      team_id: p.team_id,
      original_team_id: p.original_team_id,
      season_year: p.season_year,
      round: p.round,
      swap_type: swapType || null,
      notes: p.notes || null
    })});
    const m = document.querySelector('.modal.show');
    bootstrap.Modal.getInstance(m)?.hide();
    showAlert('success', swapType ? `Swap: ${swapType}` : 'Swap removido');
    const teamId = document.getElementById('srchPickTeam')?.value;
    if (teamId) runLeaguePickSearch(teamId);
  } catch (e) { alert('Erro: ' + (e.error || 'Desconhecido')); }
}

/**
 * Filtra os cards de time da aba da liga.
 *
 * Casa contra o TEXTO do card inteiro — cidade, nome e GM já estão lá —
 * em vez de exigir atributos data-* em cada um. Assim procurar pelo dono
 * funciona igual a procurar pelo time, sem markup extra.
 *
 * Esconde por CSS em vez de remontar a lista: os cards têm botões de aviso
 * com estado próprio, e recriá-los a cada tecla perderia o foco e piscaria.
 */
function filtrarTimes(termo) {
  const grade = document.getElementById('gradeTimes');
  if (!grade) return;
  const alvo = (termo || '').trim().toLowerCase();
  let visiveis = 0;

  grade.querySelectorAll('.team-card').forEach(card => {
    const coluna = card.parentElement;
    const casa = !alvo || card.textContent.toLowerCase().includes(alvo);
    coluna.style.display = casa ? '' : 'none';
    if (casa) visiveis++;
  });

  const vazio = document.getElementById('buscaTimeVazio');
  if (vazio) vazio.style.display = visiveis === 0 ? '' : 'none';

  const conta = document.getElementById('buscaTimeConta');
  if (conta) {
    const total = grade.querySelectorAll('.team-card').length;
    conta.textContent = alvo ? `${visiveis} de ${total}` : `${total} cadastrados`;
  }
}

async function showTeam(teamId) {
  const container = document.getElementById('mainContainer');
  container.innerHTML = '<div class="text-center py-5"><div class="spinner-border text-orange"></div></div>';
  try {
    const data = await api(`admin.php?action=team_details&team_id=${teamId}`);
    appState.teamDetails = data.team;
    appState.currentTeam = data.team;
    appState.view = 'team';
    updateBreadcrumb();
    const t = data.team;
    const ovrStyle = ovr => ovr >= 80
      ? 'background:rgba(74,222,128,.15);color:#4ade80;border:1px solid rgba(74,222,128,.3)'
      : ovr >= 70
        ? 'background:rgba(251,191,36,.15);color:#fbbf24;border:1px solid rgba(251,191,36,.3)'
        : 'background:rgba(148,163,184,.15);color:#94a3b8;border:1px solid rgba(148,163,184,.3)';
    container.innerHTML = `
<div class="mb-3"><button class="btn btn-back" onclick="showLeague('${t.league}')"><i class="bi bi-arrow-left"></i> Voltar</button></div>

<div class="panel mb-3">
  <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap">
    <img src="${t.photo_url || '/img/default-team.png'}" alt="logo"
         style="width:72px;height:72px;border-radius:50%;object-fit:cover;border:2px solid var(--red)">
    <div style="flex:1;min-width:0">
      <div style="font-size:20px;font-weight:700;color:var(--text)">${escapeHtml(t.city)} ${escapeHtml(t.name)}</div>
      <div style="font-size:13px;color:var(--text-3);margin-top:2px">${escapeHtml(t.owner_name)}</div>
      <span class="badge bg-gradient-orange mt-1">${t.league}</span>
    </div>
    <button class="btn-ghost" onclick="editTeam(${t.id})"><i class="bi bi-pencil-fill me-1"></i>Editar</button>
  </div>
  <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:16px">
    <div class="pun-card" style="flex:1;min-width:110px;padding:12px 16px;text-align:center">
      <div style="font-size:20px;font-weight:700;color:var(--red)">${t.cap_top8}${t.restricted_bonus > 0 ? ` <small style="color:#f59e0b;font-size:.65em">+${t.restricted_bonus}</small>` : ''}</div>
      <div style="font-size:11px;color:var(--text-3)">CAP Top ${window.__CAP_TOP_N__ || 10}${t.restricted_bonus > 0 ? ` · ${t.restricted_eligible} Franquia${t.restricted_eligible > 1 ? 's' : ''}` : ''}</div>
    </div>
    <div class="pun-card" style="flex:1;min-width:110px;padding:12px 16px;text-align:center;cursor:pointer" onclick="editTeamCounter(${t.id}, 'trades_used', ${parseInt(t.trades_used || 0)})">
      <div style="font-size:20px;font-weight:700;color:#38bdf8" id="tradesUsedDisplay">${parseInt(t.trades_used || 0)}</div>
      <div style="font-size:11px;color:var(--text-3)">Trocas feitas <i class="bi bi-pencil-fill" style="font-size:9px"></i></div>
    </div>
    <div class="pun-card" style="flex:1;min-width:110px;padding:12px 16px;text-align:center;cursor:pointer" onclick="editTeamCounter(${t.id}, 'waivers_used', ${parseInt(t.waivers_used || 0)})">
      <div style="font-size:20px;font-weight:700;color:#4ade80" id="waiversUsedDisplay">${parseInt(t.waivers_used || 0)}</div>
      <div style="font-size:11px;color:var(--text-3)">Dispensas feitas <i class="bi bi-pencil-fill" style="font-size:9px"></i></div>
    </div>
  </div>
</div>

<div class="panel mb-3">
  <div class="panel-header">
    <div class="panel-title"><i class="bi bi-people-fill"></i> Elenco <span style="font-size:12px;color:var(--text-3);font-weight:400">(${t.players.length})</span></div>
    <button class="btn-ghost" onclick="addPlayer(${t.id})"><i class="bi bi-plus-circle me-1"></i>Adicionar</button>
  </div>
  ${t.players.length === 0
    ? '<div style="text-align:center;padding:24px;color:var(--text-3)">Nenhum jogador no elenco</div>'
    : t.players.map(p => {
        // Elegível ao bônus de cap: nunca trocado + OVR>=90 + draftado pela própria franquia.
        // Aproximação local (sem consultar draft_pool) — vale pra qualquer liga.
        const isCapEligible = Number(p.was_traded ?? 1) === 0 && Number(p.drafted_by_team_id) === t.id && Number(p.ovr) >= 90;
        const isLoyal = Number(p.is_loyal ?? (Number(p.was_traded ?? 1) === 0 ? 1 : 0)) === 1;
        const nameColor = isCapEligible ? (p.player_tag_color || '#f59e0b') : '';
        return `<div class="pun-card" style="display:flex;align-items:center;gap:12px">
  <div style="flex:1;min-width:0">
    <span style="font-weight:600${nameColor ? ';color:'+nameColor : ';color:var(--text)'}">${escapeHtml(p.name)}</span>${isLoyal ? ' <span style="background:rgba(6,182,212,.15);color:#06b6d4;border:1px solid rgba(6,182,212,.35);border-radius:999px;font-size:10px;font-weight:700;padding:2px 6px">Leal</span>' : ''}
    <div style="font-size:12px;color:var(--text-3);margin-top:2px">${escapeHtml(p.position)} · ${p.age} anos · ${escapeHtml(p.role)}</div>
  </div>
  <span style="${ovrStyle(p.ovr)};border-radius:6px;padding:3px 8px;font-size:13px;font-weight:700">${p.ovr}</span>
  <div style="display:flex;gap:6px">
    <button class="btn-ghost" style="padding:5px 8px" onclick="editPlayer(${p.id})"><i class="bi bi-pencil-fill"></i></button>
    <button class="btn-ghost" style="padding:5px 8px;color:#ef4444" onclick="deletePlayer(${p.id})"><i class="bi bi-trash-fill"></i></button>
  </div>
</div>`;
      }).join('')}
</div>

${(() => {
    const curYear = new Date().getFullYear();
    const picks = (t.picks || []).filter(p => Number(p.season_year) >= curYear);
    const swapLabel = type => type === 'SW' ? 'SW · Pior' : type === 'SB' ? 'SB · Melhor' : escapeHtml(type);
    const pickRows = !picks.length
      ? '<div style="text-align:center;padding:24px;color:var(--text-3)">Nenhum pick</div>'
      : picks.map(p => {
        const swapPartner = p.swap_type && p.swap_partner_name ? ` <span style="font-size:11px;color:var(--text-3)">c/ ${escapeHtml(p.swap_partner_city||'')} ${escapeHtml(p.swap_partner_name)}</span>` : '';
        return `<div class="pun-card" style="display:flex;align-items:center;gap:10px">
  <div style="flex:1;min-width:0">
    <span style="font-weight:600;color:var(--text)">${p.season_year} · ${p.round}ª rodada</span>${p.swap_type ? ` <span style="background:color-mix(in srgb, var(--red) 12%, transparent);color:var(--red);border:1px solid color-mix(in srgb, var(--red) 25%, transparent);border-radius:6px;padding:2px 6px;font-size:11px;font-weight:700">${swapLabel(p.swap_type)}</span>${swapPartner}` : ''}
    <div style="font-size:12px;color:var(--text-3);margin-top:2px">${escapeHtml(p.city)} ${escapeHtml(p.team_name)}</div>
  </div>
  <div style="display:flex;gap:5px;align-items:center;flex-shrink:0">
    <button class="btn-ghost" style="padding:3px 7px;font-size:10px;font-weight:700;${p.swap_type ? 'color:var(--red);border-color:color-mix(in srgb, var(--red) 25%, transparent)' : 'color:var(--text-3)'}" onclick="quickSwapType(${p.id})" title="Tipo de swap">SWAP?</button>
    <button class="btn-ghost" style="padding:5px 7px" onclick="movePick(${p.id})" title="Mover para outro time"><i class="bi bi-arrow-left-right"></i></button>
    <button class="btn-ghost" style="padding:5px 7px" onclick="editPick(${p.id})"><i class="bi bi-pencil-fill"></i></button>
    <button class="btn-ghost" style="padding:5px 7px;color:#ef4444" onclick="deletePick(${p.id})"><i class="bi bi-trash-fill"></i></button>
  </div>
</div>`;
      }).join('');
    return `<div class="panel mb-3">
  <div class="panel-header">
    <div class="panel-title"><i class="bi bi-calendar-check-fill"></i> Picks <span style="font-size:12px;color:var(--text-3);font-weight:400">(${picks.length})</span></div>
    <button class="btn-ghost" onclick="addPick(${t.id})"><i class="bi bi-plus-circle me-1"></i>Adicionar</button>
  </div>
  ${pickRows}
</div>`;
  })()}`;
  } catch (e) {
    container.innerHTML = '<div class="alert alert-danger">Erro ao carregar time</div>';
  }
}

async function editTeamCounter(teamId, field, currentValue) {
  const labels = { trades_used: 'Trocas feitas', waivers_used: 'Dispensas feitas' };
  const displayIds = { trades_used: 'tradesUsedDisplay', waivers_used: 'waiversUsedDisplay' };
  const label = labels[field] || field;
  const newVal = await perguntarSite(`Novo valor para "${label}" (atual: ${currentValue}):`, currentValue);
  if (newVal === null) return;
  const parsed = parseInt(newVal, 10);
  if (isNaN(parsed) || parsed < 0) return alert('Valor inválido. Informe um número inteiro >= 0.');
  try {
    await api('admin.php?action=team', {
      method: 'PUT',
      body: JSON.stringify({ team_id: teamId, [field]: parsed })
    });
    const el = document.getElementById(displayIds[field]);
    if (el) el.textContent = parsed;
  } catch (e) {
    alert('Erro ao atualizar: ' + (e.error || 'Desconhecido'));
  }
}

async function showTrades() {
  const _wasInTrades = appState.view === 'trades';
  appState.view = 'trades';
  appState.tradeFilters.status = 'accepted';
  if (appState.currentLeague && !_wasInTrades) appState.tradeFilters.league = appState.currentLeague;
  updateBreadcrumb();

  const container = document.getElementById('mainContainer');
  const leagueFilter = (appState.tradeFilters.league || 'ALL').toUpperCase();
  const teamFilter = appState.tradeFilters.teamId || '';
  const seasonYearFilter = appState.tradeFilters.seasonYear || '';

  const leagueOptions = [
    { value: 'ALL', label: 'Todas as ligas' },
    { value: 'ELITE', label: 'ELITE' },
    { value: 'NEXT', label: 'NEXT' },
    { value: 'RISE', label: 'RISE' },
    { value: 'ROOKIE', label: 'ROOKIE' }
  ];

  const _curYear = new Date().getFullYear();
  const seasonYearOptions = [{ value: '', label: 'Todas as temp.' }];
  for (let y = _curYear; y >= _curYear - 6; y--) seasonYearOptions.push({ value: String(y), label: String(y) });

  const _tradeBack = appState.currentLeague ? `showLeague('${appState.currentLeague}')` : 'showHome()';
  container.innerHTML = `
<div class="mb-4"><button class="btn btn-back" onclick="${_tradeBack}"><i class="bi bi-arrow-left"></i> Voltar</button></div>
<div class="panel mb-3">
  <div class="panel-header">
    <div class="panel-title"><i class="bi bi-arrow-left-right"></i> Trades <span id="tradesCountBadge" style="font-size:12px;font-weight:400;color:var(--text-3)"></span></div>
    <div class="d-flex gap-2 align-items-center flex-wrap">
      <select style="background:var(--panel-2);border:1px solid var(--border-md);color:var(--text);border-radius:var(--radius-sm);padding:5px 10px;font-size:12px" onchange="updateTradeFilter({ league: this.value })">
        ${leagueOptions.map(opt => `<option value="${opt.value}" ${opt.value === leagueFilter ? 'selected' : ''}>${opt.label}</option>`).join('')}
      </select>
      <select style="background:var(--panel-2);border:1px solid var(--border-md);color:var(--text);border-radius:var(--radius-sm);padding:5px 10px;font-size:12px" onchange="updateTradeFilter({ seasonYear: this.value })">
        ${seasonYearOptions.map(opt => `<option value="${opt.value}" ${opt.value === seasonYearFilter ? 'selected' : ''}>${opt.label}</option>`).join('')}
      </select>
      <select style="background:var(--panel-2);border:1px solid var(--border-md);color:var(--text);border-radius:var(--radius-sm);padding:5px 10px;font-size:12px;min-width:160px" id="adminTradeTeamFilter" onchange="updateTradeFilter({ teamId: this.value })">
        <option value="">Todos os times</option>
      </select>
    </div>
  </div>
</div>
<div id="tradesListContainer"><div class="text-center py-4"><div class="spinner-border" style="color:var(--red)"></div></div></div>`;
  
  try {
    const teamUrl = leagueFilter && leagueFilter !== 'ALL'
      ? `admin.php?action=teams&league=${encodeURIComponent(leagueFilter)}`
      : 'admin.php?action=teams';
    const teamsData = await api(teamUrl);
    const teams = teamsData.teams || [];

    const teamSelect = document.getElementById('adminTradeTeamFilter');
    if (teamSelect) {
      const previous = teamFilter;
      teamSelect.innerHTML = '<option value="">Todos os times</option>';
      const sortedTeams = [...teams].sort((a, b) => {
        const aLabel = `${a.league || ''} ${a.city || ''} ${a.name || ''}`.trim();
        const bLabel = `${b.league || ''} ${b.city || ''} ${b.name || ''}`.trim();
        return aLabel.localeCompare(bLabel);
      });
      sortedTeams.forEach((team) => {
        const option = document.createElement('option');
        option.value = String(team.id);
        option.textContent = leagueFilter === 'ALL'
          ? `${team.league || '-'} - ${team.city} ${team.name}`
          : `${team.city} ${team.name}`;
        teamSelect.appendChild(option);
      });
      if (previous && sortedTeams.some((team) => String(team.id) === String(previous))) {
        teamSelect.value = String(previous);
      }
    }

    let url = 'admin.php?action=trades&status=accepted';
    if (leagueFilter && leagueFilter !== 'ALL') {
      url += `&league=${encodeURIComponent(leagueFilter)}`;
    }
    if (teamFilter) {
      url += `&team_id=${encodeURIComponent(teamFilter)}`;
    }
    if (seasonYearFilter) {
      url += `&season_year=${encodeURIComponent(seasonYearFilter)}`;
    }
    const data = await api(url);
    const trades = data.trades || [];
    const tc = document.getElementById('tradesListContainer');

    const badge = document.getElementById('tradesCountBadge');
    if (badge) badge.textContent = `(${trades.length})`;

    const filteredTrades = trades;
    
    if (filteredTrades.length === 0) {
      tc.innerHTML = '<div class="text-center py-5 text-light-gray">Nenhuma trade</div>';
      return;
    }
    
    const formatAdminTradePlayer = (player) => {
      if (!player) return '';
      const name = player.name || 'Jogador (dispensado)';
      const position = player.position || '-';
      const ovr = player.ovr ?? '?';
      const age = player.age ?? '?';
      return `${name} (${position}, ${ovr}/${age})`;
    };

    const renderTradeAssets = (players = [], picks = []) => {
      const playerItems = players.map(p =>
        `<div style="font-size:12px;color:var(--text);padding:2px 0"><i class="bi bi-person-fill" style="color:var(--red);margin-right:4px"></i>${formatAdminTradePlayer(p)}</div>`
      ).join('');
      const pickItems = picks.map(pk => {
        const roundNumber = parseInt(pk.round, 10);
        const roundLabel = Number.isNaN(roundNumber) ? `${pk.round}ª rodada` : `${roundNumber}ª rodada`;
        const seasonLabel = pk.season_year ? `${pk.season_year}` : 'Temporada indefinida';
        const originalTeam = `${pk.city} ${pk.team_name}`;
        const swapTag = pk.swap_type ? ` <span style="font-size:10px;color:var(--text-3)">${pk.swap_type}</span>` : '';
        return `<div style="font-size:12px;color:var(--text);padding:2px 0"><i class="bi bi-ticket-detailed" style="color:var(--red);margin-right:4px"></i>${seasonLabel} ${roundLabel} - ${originalTeam}${swapTag}</div>`;
      }).join('');
      const content = playerItems + pickItems;
      return content || '<span style="font-size:12px;color:var(--text-3)">Nada</span>';
    };

    const formatMultiTradeItemDetail = (item) => {
      if (!item) return 'Item';
      if (item.player_id || item.player_name) {
        return formatAdminTradePlayer({
          name: item.player_name,
          position: item.player_position,
          age: item.player_age,
          ovr: item.player_ovr
        });
      }
      if (item.pick_id) {
        const roundNumber = parseInt(item.round, 10);
        const roundLabel = Number.isNaN(roundNumber) ? `${item.round}ª rodada` : `${roundNumber}ª rodada`;
        const seasonLabel = item.season_year ? `${item.season_year}` : 'Temporada indefinida';
        const originalTeam = `${item.original_team_city || ''} ${item.original_team_name || ''}`.trim() || 'Time indefinido';
        return `${seasonLabel} ${roundLabel} - ${originalTeam}`;
      }
      return 'Item';
    };

    const renderMultiTradeCard = (tr) => {
      const statusColor = { pending: '#f59e0b', accepted: '#22c55e', cancelled: '#64748b' }[tr.status] || '#64748b';
      const statusLabel = { pending: 'Pendente', accepted: 'Aceita', cancelled: 'Cancelada' }[tr.status] || tr.status;

      const teamMap = {};
      (tr.teams || []).forEach(team => {
        teamMap[team.id] = `${team.city} ${team.name}`;
      });
      const leagueLabel = tr.league || '-';
      const isAccepted = Number(tr.is_in_game || 0) === 1;

      const teamsLine = (tr.teams || []).map(t => teamMap[t.id] || `Time ${t.id}`).join(' · ');

      // Agrupado por quem ENVIA: ler "o time X manda tal jogador" e mais
      // direto para o admin do que descobrir quem mandou item a item.
      const byTeam = {};
      (tr.items || []).forEach(item => {
        const fromId = String(item.from_team_id);
        if (!byTeam[fromId]) byTeam[fromId] = [];
        byTeam[fromId].push(item);
      });
      const itemsHtml = Object.keys(byTeam).length > 0
        ? Object.entries(byTeam).map(([fromId, teamItems]) => {
            const fromLabel = teamMap[fromId] || `Time ${fromId}`;
            const rows = teamItems.map(item => {
              const detail = formatMultiTradeItemDetail(item);
              const toLabel = teamMap[String(item.to_team_id)];
              const toHtml = toLabel ? `<span style="color:var(--text-3);font-size:11px"> → para ${toLabel}</span>` : '';
              return `<div style="font-size:12px;color:var(--text);padding:2px 0">${detail}${toHtml}</div>`;
            }).join('');
            return `<div style="margin-bottom:8px"><div style="font-size:11px;font-weight:600;color:var(--red);margin-bottom:2px">${fromLabel} envia:</div>${rows}</div>`;
          }).join('')
        : '<span style="color:var(--text-3);font-size:12px">Nenhum item</span>';

      const pendingNote = tr.status === 'pending'
        ? `<span style="font-size:11px;color:#06b6d4">Aceites: ${tr.teams_accepted || 0}/${tr.teams_total || 0}</span>`
        : '';

      return `<div class="pun-card${isAccepted ? ' pun-card-reverted' : ''}" data-trade-id="${tr.id}" style="margin-bottom:10px">
  <div class="pun-card-head">
    <div>
      <div class="pun-card-title">Trade múltipla <span style="font-size:11px;font-weight:400;color:var(--text-3)">${leagueLabel}</span></div>
      <div class="pun-card-sub">${teamsLine}</div>
    </div>
    <div class="d-flex align-items-center gap-2 flex-shrink-0">
      ${pendingNote}
      <span class="pun-badge" style="background:${statusColor}20;color:${statusColor};border-color:${statusColor}40">${statusLabel}</span>
      <label style="display:flex;align-items:center;gap:5px;font-size:11px;color:var(--text-3);cursor:pointer">
        <input type="checkbox" ${isAccepted ? 'checked' : ''} onchange="toggleAdminTradeAccept(${tr.id}, this.checked, true)" style="width:14px;height:14px;cursor:pointer">
        Game
      </label>
      ${tr.status === 'accepted' ? `<button class="btn-ghost" style="padding:3px 8px;font-size:11px" onclick="revertMultiTrade(${tr.id})">Reverter</button>` : ''}
    </div>
  </div>
  <div style="margin-top:10px">${itemsHtml}</div>
  ${tr.notes ? `<div class="pun-card-meta" style="margin-top:8px"><i class="bi bi-chat-left-text me-1"></i>${tr.notes}</div>` : ''}
  <div class="pun-card-meta">${new Date(tr.created_at).toLocaleString('pt-BR')}</div>
</div>`;
    };

    tc.innerHTML = filteredTrades.map(tr => {
      if (tr.is_multi) {
        return renderMultiTradeCard(tr);
      }
      const statusColor = { pending: '#f59e0b', accepted: '#22c55e', rejected: '#ef4444', cancelled: '#64748b', countered: '#06b6d4' }[tr.status] || '#64748b';
      const statusLabel = { pending: 'Pendente', accepted: 'Aceita', rejected: 'Recusada', cancelled: 'Cancelada', countered: 'Counter' }[tr.status] || tr.status;
      const isAccepted = Number(tr.is_in_game || 0) === 1;

      const offerHtml = renderTradeAssets(tr.offer_players || [], tr.offer_picks || []);
      const requestHtml = renderTradeAssets(tr.request_players || [], tr.request_picks || []);

      return `<div class="pun-card${isAccepted ? ' pun-card-reverted' : ''}" data-trade-id="${tr.id}" style="margin-bottom:10px">
  <div class="pun-card-head">
    <div>
      <div class="pun-card-title">${tr.from_city} ${tr.from_name} <i class="bi bi-arrow-right" style="color:var(--red);margin:0 4px"></i> ${tr.to_city} ${tr.to_name}</div>
      <div class="pun-card-sub">${tr.from_league || '-'} · ${new Date(tr.created_at).toLocaleDateString('pt-BR')}</div>
    </div>
    <div class="d-flex align-items-center gap-2 flex-shrink-0">
      <span class="pun-badge" style="background:${statusColor}20;color:${statusColor};border-color:${statusColor}40">${statusLabel}</span>
      <label style="display:flex;align-items:center;gap:5px;font-size:11px;color:var(--text-3);cursor:pointer">
        <input type="checkbox" ${isAccepted ? 'checked' : ''} onchange="toggleAdminTradeAccept(${tr.id}, this.checked, false)" style="width:14px;height:14px;cursor:pointer">
        Game
      </label>
      ${tr.status === 'pending' ? `<button class="btn-ghost" style="padding:3px 8px;font-size:11px;color:#ef4444;border-color:rgba(239,68,68,.3)" onclick="cancelTrade(${tr.id})">Cancelar</button>` : ''}
      ${tr.status === 'accepted' ? `<button class="btn-ghost" style="padding:3px 8px;font-size:11px" onclick="revertTrade(${tr.id})">Reverter</button>` : ''}
    </div>
  </div>
  ${tr.notes ? `<div class="pun-card-meta" style="margin-top:6px"><i class="bi bi-chat-left-text me-1"></i>${tr.notes}</div>` : ''}
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:10px">
    <div>
      <div style="font-size:11px;font-weight:600;color:var(--red);margin-bottom:4px">${tr.from_city} ${tr.from_name} envia:</div>
      ${offerHtml}
    </div>
    <div>
      <div style="font-size:11px;font-weight:600;color:var(--red);margin-bottom:4px">${tr.to_city} ${tr.to_name} envia:</div>
      ${requestHtml}
    </div>
  </div>
</div>`;
    }).join('');
  } catch (e) {
    document.getElementById('tradesListContainer').innerHTML = '<div class="alert alert-danger">Erro</div>';
  }
}

// ========== HALL DA FAMA ==========
let hallOfFameLeague = 'ELITE';

async function showHallOfFame() {
  appState.view = 'halloffame';
  updateBreadcrumb();

  const _hofInitLeague = appState.currentLeague || hallOfFameLeague || 'ELITE';
  const _hofBack = appState.currentLeague ? `showLeague('${appState.currentLeague}')` : 'showHome()';

  const container = document.getElementById('mainContainer');
  container.innerHTML = `
    <div class="mb-4">
      <button class="btn btn-back" onclick="${_hofBack}"><i class="bi bi-arrow-left"></i> Voltar</button>
    </div>

    <div style="display:grid;grid-template-columns:340px 1fr;gap:16px;align-items:start">

      <!-- Formulário -->
      <div class="pun-card">
        <div class="pun-card-head">
          <div class="pun-card-title"><i class="bi bi-award-fill" style="color:var(--amber);margin-right:6px"></i>Adicionar no Hall da Fama</div>
        </div>
        <div style="padding:16px;display:flex;flex-direction:column;gap:12px">

          <div>
            <div style="font-size:11px;font-weight:600;color:var(--text-3);margin-bottom:5px;text-transform:uppercase;letter-spacing:.6px">Tipo</div>
            <select id="hofType" style="width:100%;background:var(--panel-2);border:1px solid var(--border-md);border-radius:8px;padding:8px 10px;color:var(--text);font-size:13px;outline:none">
              <option value="active">Ativo (liga + time)</option>
              <option value="inactive">Inativo (nome + GM)</option>
            </select>
          </div>

          <div id="hofActiveFields">
            <div style="margin-bottom:10px">
              <div style="font-size:11px;font-weight:600;color:var(--text-3);margin-bottom:5px;text-transform:uppercase;letter-spacing:.6px">Liga</div>
              <select id="hofLeague" style="width:100%;background:var(--panel-2);border:1px solid var(--border-md);border-radius:8px;padding:8px 10px;color:var(--text);font-size:13px;outline:none">
                ${_leagues.map(l => `<option value="${l}"${l === _hofInitLeague ? ' selected' : ''}>${l}</option>`).join('')}
              </select>
            </div>
            <div>
              <div style="font-size:11px;font-weight:600;color:var(--text-3);margin-bottom:5px;text-transform:uppercase;letter-spacing:.6px">Time</div>
              <select id="hofTeam" style="width:100%;background:var(--panel-2);border:1px solid var(--border-md);border-radius:8px;padding:8px 10px;color:var(--text);font-size:13px;outline:none"></select>
            </div>
          </div>

          <div id="hofInactiveFields" style="display:none">
            <div style="margin-bottom:10px">
              <div style="font-size:11px;font-weight:600;color:var(--text-3);margin-bottom:5px;text-transform:uppercase;letter-spacing:.6px">Nome do Time (opcional)</div>
              <input type="text" id="hofTeamName" placeholder="Ex: Montreal Saints"
                style="width:100%;background:var(--panel-2);border:1px solid var(--border-md);border-radius:8px;padding:8px 10px;color:var(--text);font-size:13px;outline:none">
            </div>
            <div>
              <div style="font-size:11px;font-weight:600;color:var(--text-3);margin-bottom:5px;text-transform:uppercase;letter-spacing:.6px">Nome do GM</div>
              <input type="text" id="hofGmName" placeholder="Ex: John Doe"
                style="width:100%;background:var(--panel-2);border:1px solid var(--border-md);border-radius:8px;padding:8px 10px;color:var(--text);font-size:13px;outline:none">
            </div>
          </div>

          <div>
            <div style="font-size:11px;font-weight:600;color:var(--text-3);margin-bottom:5px;text-transform:uppercase;letter-spacing:.6px">Títulos</div>
            <input type="number" id="hofTitles" min="0" value="0"
              style="width:100%;background:var(--panel-2);border:1px solid var(--border-md);border-radius:8px;padding:8px 10px;color:var(--text);font-size:13px;outline:none">
          </div>

          <button id="hofAddBtn" class="btn-ghost" style="width:100%;justify-content:center;color:#22c55e;border-color:rgba(34,197,94,.3);padding:9px">
            <i class="bi bi-plus-circle me-1"></i>Adicionar
          </button>
        </div>
      </div>

      <!-- Lista -->
      <div class="pun-card">
        <div class="pun-card-head" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
          <div class="pun-card-title"><i class="bi bi-list-stars" style="color:var(--amber);margin-right:6px"></i>Lista do Hall da Fama</div>
          <!-- Filtro só da lista: não mexe na liga do formulário de adicionar -->
          <select id="hofFiltroLiga" title="Filtrar a lista por liga"
            style="margin-left:auto;background:var(--panel-2);border:1px solid var(--border-md);border-radius:8px;padding:5px 9px;color:var(--text);font-size:12px;font-weight:700;outline:none">
            <option value="">Todas as ligas</option>
            ${['ELITE', 'NEXT', 'RISE', 'ROOKIE'].map(lg => `<option value="${lg}">${lg}</option>`).join('')}
          </select>
          <input id="hofBusca" type="search" placeholder="Buscar GM ou time…" autocomplete="off"
            style="background:var(--panel-2);border:1px solid var(--border-md);border-radius:8px;padding:5px 9px;color:var(--text);font-size:12px;outline:none;min-width:150px;flex:1 1 150px">
        </div>
        <div id="hofList" style="padding:4px 0">
          <div style="text-align:center;padding:32px"><div class="spinner-border" style="width:24px;height:24px;border-width:3px;border-color:var(--border-md);border-top-color:var(--red)"></div></div>
        </div>
      </div>

    </div>
  `;

  document.getElementById('hofType').addEventListener('change', toggleHallOfFameType);
  document.getElementById('hofLeague').addEventListener('change', (e) => {
    hallOfFameLeague = e.target.value;
    loadHallOfFameTeams(hallOfFameLeague);
  });
  document.getElementById('hofAddBtn').addEventListener('click', submitHallOfFameEntry);
  document.getElementById('hofFiltroLiga')?.addEventListener('change', renderHallOfFameList);
  document.getElementById('hofBusca')?.addEventListener('input', renderHallOfFameList);

  hallOfFameLeague = document.getElementById('hofLeague').value || _hofInitLeague;
  loadHallOfFameTeams(hallOfFameLeague);
  loadHallOfFameList();
}

function toggleHallOfFameType() {
  const type = document.getElementById('hofType').value;
  const activeFields = document.getElementById('hofActiveFields');
  const inactiveFields = document.getElementById('hofInactiveFields');
  if (type === 'inactive') {
    activeFields.style.display = 'none';
    inactiveFields.style.display = 'block';
  } else {
    activeFields.style.display = 'block';
    inactiveFields.style.display = 'none';
  }
}

async function loadHallOfFameTeams(league) {
  const select = document.getElementById('hofTeam');
  if (!select) return;
  select.innerHTML = '<option>Carregando...</option>';
  try {
    const data = await api(`admin.php?action=teams&league=${league}`);
    const teams = data.teams || [];
    if (!teams.length) {
      select.innerHTML = '<option value="">Sem times na liga</option>';
      return;
    }
    select.innerHTML = teams
      .map(t => `<option value="${t.id}">${escapeHtml(t.city)} ${escapeHtml(t.name)}</option>`)
      .join('');
  } catch (e) {
    select.innerHTML = '<option value="">Erro ao carregar</option>';
  }
}

async function submitHallOfFameEntry() {
  const type = document.getElementById('hofType').value;
  const titles = parseInt(document.getElementById('hofTitles').value || '0', 10);

  const payload = {
    is_active: type === 'active' ? 1 : 0,
    titles: Number.isNaN(titles) ? 0 : titles
  };

  if (type === 'active') {
    payload.league = document.getElementById('hofLeague').value;
    payload.team_id = parseInt(document.getElementById('hofTeam').value || '0', 10);
  } else {
    payload.gm_name = (document.getElementById('hofGmName').value || '').trim();
    payload.team_name = (document.getElementById('hofTeamName').value || '').trim();
  }

  try {
    await api('admin.php?action=hall_of_fame', {
      method: 'POST',
      body: JSON.stringify(payload)
    });
    document.getElementById('hofTitles').value = 0;
    document.getElementById('hofGmName').value = '';
    document.getElementById('hofTeamName').value = '';
    loadHallOfFameList();
  } catch (e) {
    alert(e.error || 'Erro ao salvar');
  }
}

const HOF_LEAGUE_ORDER = { ELITE: 0, NEXT: 1, RISE: 2, ROOKIE: 3 };

// A lista fica em memória depois do primeiro carregamento: filtrar por liga ou
// buscar por nome é só re-render, sem nova ida ao servidor.
let _hofGroups = [];

async function loadHallOfFameList() {
  const container = document.getElementById('hofList');
  if (!container) return;
  container.innerHTML = '<div class="text-center py-4"><div class="spinner-border text-orange"></div></div>';
  try {
    const data = await api('admin.php?action=hall_of_fame');
    _hofGroups = data.groups || [];
    renderHallOfFameList();
  } catch (e) {
    container.innerHTML = '<p class="empty-state" style="padding:32px;color:#ef4444">Erro ao carregar lista.</p>';
  }
}

function renderHallOfFameList() {
  const container = document.getElementById('hofList');
  if (!container) return;

  const liga = document.getElementById('hofFiltroLiga')?.value || '';
  const termo = (document.getElementById('hofBusca')?.value || '').trim().toLowerCase();

  if (!_hofGroups.length) {
    container.innerHTML = '<p class="empty-state" style="padding:32px">Nenhum registro ainda.</p>';
    return;
  }

  // Filtra as linhas pela liga e o grupo pelo termo; grupo que ficou sem linha sai.
  const groups = _hofGroups.map(g => {
    const rows = (g.rows || []).filter(r => !liga || r.league === liga);
    return { ...g, rows };
  }).filter(g => {
    if (!g.rows.length) return false;
    if (!termo) return true;
    const alvo = ((g.gm_name || '') + ' ' + (g.teams || []).join(' ')).toLowerCase();
    return alvo.includes(termo);
  });

  if (!groups.length) {
    container.innerHTML = `<p class="empty-state" style="padding:32px">Nenhum registro${liga ? ' na ' + liga : ''}${termo ? ' para “' + escapeHtml(termo) + '”' : ''}.</p>`;
    return;
  }

  {
    container.innerHTML = groups.map(g => {
      const sc = g.is_active ? '#22c55e' : 'var(--text-3)';
      const rows = [...(g.rows || [])].sort((a, b) => (HOF_LEAGUE_ORDER[a.league] ?? 9) - (HOF_LEAGUE_ORDER[b.league] ?? 9));
      return `
      <div style="padding:12px 16px;border-bottom:1px solid var(--border)">
        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:8px">
          <div style="font-size:14px;font-weight:700;color:var(--text)">${escapeHtml(g.gm_name || '—')}</div>
          <span style="font-size:10px;font-weight:600;color:${sc}">${g.is_active ? 'Ativo' : 'Inativo'}</span>
          ${g.teams && g.teams.length ? `<span style="font-size:11px;color:var(--text-3)">${escapeHtml(g.teams.join(' / '))}</span>` : ''}
        </div>
        <div style="display:flex;flex-direction:column;gap:6px">
          ${rows.map(row => `
          <div style="display:flex;align-items:center;gap:8px;padding-left:6px">
            <span style="font-size:10px;font-weight:700;background:var(--red-soft);color:var(--red);border:1px solid color-mix(in srgb, var(--red) 20%, transparent);border-radius:999px;padding:1px 7px;min-width:52px;text-align:center">${row.league}${row.league === g.current_league ? ' •' : ''}</span>
            <select data-hof-league="${row.id}"
              style="background:var(--panel-2);border:1px solid var(--border-md);border-radius:7px;padding:5px 6px;color:var(--text);font-size:12px;font-weight:600;outline:none">
              ${['ELITE', 'NEXT', 'RISE', 'ROOKIE'].map(lg => `<option value="${lg}" ${row.league === lg ? 'selected' : ''}>${lg}</option>`).join('')}
            </select>
            <input type="number" min="0" value="${row.titles || 0}" data-hof-title="${row.id}"
              style="width:64px;background:var(--panel-2);border:1px solid var(--border-md);border-radius:7px;padding:5px 8px;color:var(--amber);font-size:13px;font-weight:700;text-align:center;outline:none">
            <button class="btn-ghost" style="padding:5px 8px;color:#22c55e" onclick="saveHallOfFameTitles(${row.id})" title="Salvar">
              <i class="bi bi-floppy"></i>
            </button>
            <button class="btn-ghost" style="padding:5px 8px;color:#ef4444" onclick="deleteHallOfFameEntry(${row.id})" title="Remover">
              <i class="bi bi-trash3"></i>
            </button>
          </div>`).join('')}
        </div>
      </div>`;
    }).join('');
  }
}

async function saveHallOfFameTitles(id) {
  const input = document.querySelector(`[data-hof-title="${id}"]`);
  const leagueSelect = document.querySelector(`[data-hof-league="${id}"]`);
  if (!input) return;
  const titles = parseInt(input.value || '0', 10);
  const payload = { id, titles: Number.isNaN(titles) ? 0 : titles };
  if (leagueSelect) payload.league = leagueSelect.value;
  try {
    await api('admin.php?action=hall_of_fame', {
      method: 'PUT',
      body: JSON.stringify(payload)
    });
    loadHallOfFameList();
  } catch (e) {
    alert(e.error || 'Erro ao salvar');
  }
}

async function deleteHallOfFameEntry(id) {
  if (!await confirmarSite('Remover este registro do Hall da Fama?')) return;
  try {
    await api('admin.php?action=hall_of_fame', {
      method: 'DELETE',
      body: JSON.stringify({ id })
    });
    loadHallOfFameList();
  } catch (e) {
    alert(e.error || 'Erro ao remover');
  }
}

async function toggleAdminTradeAccept(tradeId, checked, isMulti) {
  const card = document.querySelector(`[data-trade-id="${tradeId}"]`);
  if (card) {
    card.classList.toggle('is-accepted', checked);
  }
  try {
    await api('admin.php?action=trade_in_game', {
      method: 'PUT',
      body: JSON.stringify({ trade_id: tradeId, is_in_game: checked ? 1 : 0, is_multi: !!isMulti })
    });
  } catch (e) {
    if (card) {
      card.classList.toggle('is-accepted', !checked);
    }
    alert(e.error || 'Erro ao atualizar status da trade.');
  }
}

// Campo de vídeo de liga: link (YouTube/Vimeo/Drive/.mp4) OU upload direto de
// arquivo. O upload salva na hora (não depende do botão Salvar do card); o
// campo de link só reflete o resultado pra deixar claro o que está valendo.
function videoFieldHtml(league, slot, label, value, small) {
  const inputCls = small ? 'form-control form-control-sm' : 'form-control';
  const uid = `vid_${slot}_${league}`;
  return `
    <div data-video-field style="display:flex;flex-direction:column;gap:4px;${small ? 'flex:1;min-width:150px' : ''}">
      <div style="font-size:11px;font-weight:600;color:var(--text-2)">${label}</div>
      <div style="display:flex;gap:6px;align-items:center">
        <input type="text" class="${inputCls}" placeholder="cole o link do vídeo" value="${(value || '').replace(/"/g, '&quot;')}" data-league="${league}" data-field="${slot === 'progression' ? 'progression_video_url' : slot === 'sistemas' ? 'sistemas_video_url' : 'freeagency_video_url'}" id="${uid}_input" style="flex:1">
        <label class="btn-ghost" style="cursor:pointer;padding:6px 9px;margin:0" title="Enviar arquivo de vídeo (upload)">
          <i class="bi bi-upload"></i>
          <input type="file" accept="video/mp4,video/webm,video/quicktime,video/ogg" style="display:none" onchange="handleLeagueVideoUpload(this,'${league}','${slot}')">
        </label>
      </div>
      <div class="lg-video-upload-status" id="${uid}_status" style="font-size:10.5px;color:var(--text-3);min-height:13px"></div>
    </div>`;
}

async function handleLeagueVideoUpload(fileInput, league, slot) {
  const file = fileInput.files[0];
  if (!file) return;
  const field = fileInput.closest('[data-video-field]');
  const textInput = field?.querySelector('input[type=text]');
  const statusEl = field?.querySelector('.lg-video-upload-status');
  if (statusEl) statusEl.innerHTML = '<span class="spinner-border spinner-border-sm" style="width:10px;height:10px;border-width:1.5px"></span> Enviando...';

  const fd = new FormData();
  fd.append('league', league);
  fd.append('slot', slot);
  fd.append('file', file);

  try {
    const res = await fetch('/api/league-video-upload.php', { method: 'POST', body: fd });
    const data = await res.json();
    if (!data.success) throw new Error(data.error || 'Erro no upload');
    if (textInput) textInput.value = data.url;
    if (statusEl) {
      statusEl.innerHTML = '<i class="bi bi-check2-circle" style="color:#25c677"></i> Enviado e salvo!';
      setTimeout(() => { statusEl.innerHTML = ''; }, 3000);
    }
  } catch (e) {
    alert(e.message || 'Erro ao enviar o vídeo.');
    if (statusEl) statusEl.innerHTML = '';
  } finally {
    fileInput.value = '';
  }
}
window.handleLeagueVideoUpload = handleLeagueVideoUpload;

async function showConfig() {
  appState.view = 'config';
  updateBreadcrumb();

  const _cfgLeague = appState.currentLeague || null;
  const _cfgBack = _cfgLeague ? `showLeague('${_cfgLeague}')` : 'showHome()';

  const container = document.getElementById('mainContainer');
  container.innerHTML = `
<div class="mb-4"><button class="btn btn-back" onclick="${_cfgBack}"><i class="bi bi-arrow-left"></i> Voltar</button></div>
<div id="configContainer"><div class="text-center py-4"><div class="spinner-border" style="color:var(--red)"></div></div></div>`;

  try {
    const [cfgData, seasonData] = await Promise.all([
      api('admin.php?action=leagues'),
      _cfgLeague
        ? api(`seasons.php?action=list_seasons&league=${_cfgLeague}`).catch(() => ({ seasons: [] }))
        : Promise.resolve({ seasons: [] })
    ]);
    const allLeagues = cfgData.leagues || [];
    const filtered = _cfgLeague ? allLeagues.filter(lg => lg.league === _cfgLeague) : allLeagues;
    const seasons = seasonData.seasons || [];
    const currentSeason = seasons.find(s => s.status !== 'completed') || seasons[0] || null;
    const seasonYear = currentSeason
      ? (currentSeason.start_year && currentSeason.season_number
          ? (parseInt(currentSeason.start_year) + parseInt(currentSeason.season_number) - 1)
          : (currentSeason.year || '—'))
      : '—';
    const seasonNumber = currentSeason ? (parseInt(currentSeason.season_number) || '—') : '—';
    const totalSeasons = seasons.length || '—';

    document.getElementById('configContainer').innerHTML = filtered.map(lg => `
<div class="panel mb-4">

  <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:20px">
    <div>
      <div style="font-size:10px;font-weight:700;letter-spacing:.2em;text-transform:uppercase;color:var(--red);margin-bottom:4px">Central da Liga</div>
      <div style="font-size:30px;font-weight:900;color:var(--text);letter-spacing:-.5px;line-height:1">${lg.league}</div>
      <div style="font-size:12px;color:var(--text-3);margin-top:4px">${lg.team_count} ${lg.team_count === 1 ? 'time' : 'times'} cadastrados</div>
    </div>
    <div style="display:flex;gap:20px;flex-wrap:wrap;align-items:center">
      <div style="text-align:center;min-width:48px">
        <div style="font-size:22px;font-weight:800;color:var(--text)">${seasonYear}</div>
        <div style="font-size:10px;color:var(--text-3);font-weight:500;margin-top:1px">Temp. ${seasonNumber}</div>
      </div>
      <div style="width:1px;height:36px;background:var(--border)"></div>
      <div style="text-align:center;min-width:56px">
        <div style="font-size:22px;font-weight:800;color:var(--red)">${seasonNumber}<span style="font-size:13px;font-weight:400;color:var(--text-3)">/${totalSeasons}</span></div>
        <div style="font-size:10px;color:var(--text-3);font-weight:500;margin-top:1px">Temporadas</div>
      </div>
      <div style="width:1px;height:36px;background:var(--border)"></div>
      <div style="text-align:center;min-width:60px">
        <div style="font-size:16px;font-weight:700;color:var(--text)">${lg.cap_min}–${lg.cap_max}</div>
        <div style="font-size:10px;color:var(--text-3);font-weight:500;margin-top:1px">CAP Range</div>
      </div>
    </div>
    <button class="btn-orange" onclick="saveLeagueSettings(this)" style="align-self:flex-start">
      <i class="bi bi-save2 me-1"></i>Salvar
    </button>
  </div>

  <hr style="border-color:var(--border);margin:0 0 20px">

  <div style="font-size:12px;font-weight:700;color:var(--text-3);text-transform:uppercase;letter-spacing:.1em;margin-bottom:12px">Regras e Limites</div>
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:12px;margin-bottom:24px">
    <div>
      <div style="font-size:11px;font-weight:600;color:var(--text-2);margin-bottom:6px">CAP Mínimo</div>
      <input type="number" class="form-control" value="${lg.cap_min}" data-league="${lg.league}" data-field="cap_min" />
    </div>
    <div>
      <div style="font-size:11px;font-weight:600;color:var(--text-2);margin-bottom:6px">CAP Máximo</div>
      <input type="number" class="form-control" value="${lg.cap_max}" data-league="${lg.league}" data-field="cap_max" />
    </div>
    <div>
      <div style="font-size:11px;font-weight:600;color:var(--text-2);margin-bottom:6px">Máx. Trocas/Temp.</div>
      <input type="number" class="form-control" value="${lg.max_trades || 3}" data-league="${lg.league}" data-field="max_trades" />
    </div>
  </div>
  <div style="margin-bottom:24px;background:var(--panel-2);border:1px solid var(--border);border-radius:12px;padding:16px">
    <div style="font-size:12px;font-weight:700;color:var(--text);margin-bottom:6px"><i class="bi bi-calculator me-1"></i>Recálculo automático do CAP</div>
    <p style="font-size:11px;color:var(--text-3);margin-bottom:12px;line-height:1.5">
      A cada 2 temporadas (1, 3, 5...), assim que <b>todos</b> os times da liga atualizarem o elenco, o CAP é recalculado sozinho: soma o CAP de todos os times (${lg.cap_mode === 'salary' ? 'folha salarial' : `OVR top-${window.__CAP_TOP_N__ || 10}`}), tira a média, e aplica a margem abaixo pra cima e pra baixo.
      ${lg.cap_auto_last_season ? `Última vez: temporada ${lg.cap_auto_last_season}.` : 'Ainda não recalculou automaticamente nesta liga.'}
    </p>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:12px">
      <div>
        <div style="font-size:11px;font-weight:600;color:var(--text-2);margin-bottom:6px">Margem (pontos de OVR)</div>
        <input type="number" class="form-control" min="0" value="${lg.cap_auto_margin}" data-league="${lg.league}" data-field="cap_auto_margin" />
      </div>
      <div>
        <div style="font-size:11px;font-weight:600;color:var(--text-2);margin-bottom:6px">Margem (% da folha — ELITE)</div>
        <input type="number" class="form-control" min="0" value="${lg.cap_auto_margin_pct}" data-league="${lg.league}" data-field="cap_auto_margin_pct" />
      </div>
    </div>
    <div id="capHistory_${lg.league}" style="margin-top:12px"></div>
  </div>
  ${lg.league === 'ELITE' ? `
  <div style="margin-bottom:24px;background:var(--panel-2);border:1px solid var(--border);border-radius:12px;padding:16px">
    <div style="font-size:12px;font-weight:700;color:var(--text);margin-bottom:6px"><i class="bi bi-arrows-expand me-1"></i>Cap Flex</div>
    <p style="font-size:11px;color:var(--text-3);margin-bottom:12px;line-height:1.5">
      O Cap Flex serve pra franquia segurar a estrela que ela mesma desenvolveu (+3M / +5M / +8M por faixa de OVR, no máximo 2 jogadores).
      Ele soma por cima do <b>CAP Máximo</b> configurado logo acima${Number(lg.cap_max) > 0
        ? ` — hoje ${Number(lg.cap_max)}M, indo até ${Number(lg.cap_max) + 16}M com as duas vagas cheias` : ''}.
      Na primeira temporada da edição ninguém desenvolveu ninguém ainda, e todo jogador do Draft Inicial fica marcado como "draftado pelo próprio time" —
      então liberar já na estreia daria fôlego de teto de graça pra metade da liga.
      <b>Deixe vazio pra manter o Cap Flex sempre ligado</b>, ou informe a partir de qual temporada ele passa a valer.
    </p>
    <div style="max-width:260px">
      <div style="font-size:11px;font-weight:600;color:var(--text-2);margin-bottom:6px">Vale a partir da temporada nº</div>
      <input type="number" class="form-control" min="0" placeholder="sempre ligado"
             value="${lg.cap_flex_a_partir_da_temporada ?? ''}"
             data-league="${lg.league}" data-field="cap_flex_a_partir_da_temporada" />
    </div>
  </div>
  <div style="margin-bottom:24px;background:var(--panel-2);border:1px solid var(--border);border-radius:12px;padding:16px">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-bottom:6px">
      <div style="font-size:12px;font-weight:700;color:var(--text)"><i class="bi bi-table me-1"></i>Controle de Cap</div>
      <button type="button" class="btn-outline" style="font-size:11px;padding:5px 10px" onclick="carregarCapTabela('${lg.league}')">
        <i class="bi bi-arrow-clockwise me-1"></i>Atualizar
      </button>
    </div>
    <p style="font-size:11px;color:var(--text-3);margin-bottom:12px;line-height:1.5">
      A tabela de salário por OVR com quantos jogadores da liga estão em cada faixa — pra conferir o cap sem abrir time por time.
      Abaixo, as lendas marcadas: cada uma custa no mínimo 40M, e só passa disso quando o OVR chega a 95.
    </p>
    <div id="capTabelaBox_${lg.league}"><div style="font-size:11px;color:var(--text-3)">Carregando...</div></div>
  </div>` : ''}
  <div style="margin-bottom:24px">
    <div style="font-size:11px;font-weight:600;color:var(--text-2);margin-bottom:6px"><i class="bi bi-webhook me-1"></i>Webhook N8N (trades 80+)</div>
    <input type="text" class="form-control" placeholder="https://n8n.exemplo.com/webhook/..." value="${lg.n8n_webhook_url || ''}" data-league="${lg.league}" data-field="n8n_webhook_url" />
    <div style="font-size:11px;color:var(--text-3);margin-top:4px">Disparado automaticamente quando uma trade com jogador OVR 80+ for aceita nesta liga.</div>
  </div>
  <div style="font-size:12px;font-weight:700;color:var(--text-3);text-transform:uppercase;letter-spacing:.1em;margin-bottom:12px"><i class="bi bi-camera-reels me-1"></i>Vídeos</div>
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:12px;margin-bottom:8px">
    ${videoFieldHtml(lg.league, 'progression', 'Progression', lg.progression_video_url, false)}
    ${videoFieldHtml(lg.league, 'sistemas', 'Sistemas', lg.sistemas_video_url, false)}
    ${videoFieldHtml(lg.league, 'freeagency', 'Free Agency', lg.freeagency_video_url, false)}
  </div>
  <div style="font-size:11px;color:var(--text-3);margin-bottom:24px">Cada um aparece como um card no dashboard de todo mundo desta liga, se tiver link ou vídeo enviado. Cole um link do YouTube/Vimeo/Drive, ou clique no ícone de upload pra enviar um arquivo de vídeo direto (até 300MB) — o upload salva na hora, sem precisar clicar em Salvar. Vídeo enviado como arquivo permite capturar o frame parado; links incorporados (YouTube/Vimeo/Drive) usam compartilhamento de tela pra capturar.</div>

  <div style="font-size:12px;font-weight:700;color:var(--text-3);text-transform:uppercase;letter-spacing:.1em;margin-bottom:12px">Status da Liga</div>
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:12px;margin-bottom:24px">
    <div style="background:var(--panel-2);border:1px solid var(--border);border-radius:12px;padding:16px">
      <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px">
        <div style="width:32px;height:32px;border-radius:9px;background:rgba(59,130,246,.12);display:flex;align-items:center;justify-content:center">
          <i class="bi bi-arrow-left-right" style="color:#3b82f6;font-size:14px"></i>
        </div>
        <span style="font-size:13px;font-weight:600;color:var(--text)">Trades</span>
        <span style="margin-left:auto;font-size:10px;font-weight:700;padding:2px 8px;border-radius:999px;${(lg.trades_enabled ?? 1) == 1 ? 'background:rgba(37,198,119,.15);color:#25c677;border:1px solid rgba(37,198,119,.25)' : 'background:color-mix(in srgb, var(--red) 12%, transparent);color:var(--red);border:1px solid var(--border-red)'}">${(lg.trades_enabled ?? 1) == 1 ? 'Ativas' : 'Bloqueadas'}</span>
      </div>
      <div style="display:flex;gap:6px">
        <button class="btn ${(lg.trades_enabled ?? 1) == 1 ? 'btn-success' : 'btn-outline-success'} flex-grow-1"
          style="font-size:12px;padding:7px 10px"
          onclick="toggleTrades('${lg.league}', 1)" id="tradesOnBtn_${lg.league}">
          Ativas
        </button>
        <button class="btn ${(lg.trades_enabled ?? 1) == 0 ? 'btn-danger' : 'btn-outline-danger'} flex-grow-1"
          style="font-size:12px;padding:7px 10px"
          onclick="toggleTrades('${lg.league}', 0)" id="tradesOffBtn_${lg.league}">
          Bloqueadas
        </button>
      </div>
    </div>
    <div style="background:var(--panel-2);border:1px solid var(--border);border-radius:12px;padding:16px">
      <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px">
        <div style="width:32px;height:32px;border-radius:9px;background:rgba(34,197,94,.12);display:flex;align-items:center;justify-content:center">
          <i class="bi bi-coin" style="color:#22c55e;font-size:14px"></i>
        </div>
        <span style="font-size:13px;font-weight:600;color:var(--text)">Free Agency</span>
        <span style="margin-left:auto;font-size:10px;font-weight:700;padding:2px 8px;border-radius:999px;${(lg.fa_enabled ?? 1) == 1 ? 'background:rgba(37,198,119,.15);color:#25c677;border:1px solid rgba(37,198,119,.25)' : 'background:color-mix(in srgb, var(--red) 12%, transparent);color:var(--red);border:1px solid var(--border-red)'}">${(lg.fa_enabled ?? 1) == 1 ? 'Ativa' : 'Bloqueada'}</span>
      </div>
      <div style="display:flex;gap:6px">
        <button class="btn ${(lg.fa_enabled ?? 1) == 1 ? 'btn-success' : 'btn-outline-success'} flex-grow-1"
          style="font-size:12px;padding:7px 10px"
          onclick="toggleFA('${lg.league}', 1)" id="faOnBtn_${lg.league}">
          Ativa
        </button>
        <button class="btn ${(lg.fa_enabled ?? 1) == 0 ? 'btn-danger' : 'btn-outline-danger'} flex-grow-1"
          style="font-size:12px;padding:7px 10px"
          onclick="toggleFA('${lg.league}', 0)" id="faOffBtn_${lg.league}">
          Bloqueada
        </button>
      </div>
    </div>
    <div style="background:var(--panel-2);border:1px solid var(--border);border-radius:12px;padding:16px">
      <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px">
        <div style="width:32px;height:32px;border-radius:9px;background:rgba(245,158,11,.12);display:flex;align-items:center;justify-content:center">
          <i class="bi bi-person-dash" style="color:#f59e0b;font-size:14px"></i>
        </div>
        <span style="font-size:13px;font-weight:600;color:var(--text)">Dispensas</span>
        <span style="margin-left:auto;font-size:10px;font-weight:700;padding:2px 8px;border-radius:999px;${(lg.waivers_enabled ?? 1) == 1 ? 'background:rgba(37,198,119,.15);color:#25c677;border:1px solid rgba(37,198,119,.25)' : 'background:color-mix(in srgb, var(--red) 12%, transparent);color:var(--red);border:1px solid var(--border-red)'}">${(lg.waivers_enabled ?? 1) == 1 ? 'Abertas' : 'Fechadas'}</span>
      </div>
      <div style="display:flex;gap:6px">
        <button class="btn ${(lg.waivers_enabled ?? 1) == 1 ? 'btn-success' : 'btn-outline-success'} flex-grow-1"
          style="font-size:12px;padding:7px 10px"
          onclick="toggleWaivers('${lg.league}', 1)" id="waiversOnBtn_${lg.league}">
          Abertas
        </button>
        <button class="btn ${(lg.waivers_enabled ?? 1) == 0 ? 'btn-danger' : 'btn-outline-danger'} flex-grow-1"
          style="font-size:12px;padding:7px 10px"
          onclick="toggleWaivers('${lg.league}', 0)" id="waiversOffBtn_${lg.league}">
          Fechadas
        </button>
      </div>
      <div style="font-size:11px;color:var(--text-3);margin-top:8px">
        Fechado, ninguém dispensa. Aposentadoria continua liberada — é obrigação do edital.
      </div>
    </div>
  </div>

  <div style="font-size:12px;font-weight:700;color:var(--text-3);text-transform:uppercase;letter-spacing:.1em;margin-bottom:12px">Edital da Liga</div>
  <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
    <input type="file" class="form-control" id="edital_file_${lg.league}" accept=".pdf,.doc,.docx" style="flex:1;min-width:180px" />
    <button class="btn-orange" onclick="uploadEdital('${lg.league}')"><i class="bi bi-upload me-1"></i>Upload</button>
  </div>
  ${lg.edital_file ? `<div style="display:flex;align-items:center;gap:10px;margin-top:10px;padding:10px 12px;background:rgba(37,198,119,.08);border:1px solid rgba(37,198,119,.2);border-radius:10px">
    <i class="bi bi-file-earmark-check" style="color:#25c677;font-size:16px"></i>
    <span style="font-size:12px;color:#25c677;flex:1">${lg.edital_file}</span>
    <a href="/api/edital.php?action=download_edital&league=${lg.league}" class="btn btn-sm btn-outline-light" download target="_blank"><i class="bi bi-download me-1"></i>Baixar</a>
    <button class="btn btn-sm btn-outline-danger" onclick="deleteEdital('${lg.league}')"><i class="bi bi-trash"></i></button>
  </div>` : `<div style="font-size:12px;color:var(--text-3);margin-top:8px"><i class="bi bi-info-circle me-1"></i>Nenhum arquivo enviado</div>`}

</div>`).join('');
    filtered.forEach(lg => {
      loadCapHistory(lg.league);
      if (lg.league === 'ELITE') carregarCapTabela(lg.league);
    });
  } catch (e) {}
}

/**
 * Painel de conferência do cap: tabela OVR→salário com a contagem de jogadores
 * por faixa, e a lista das lendas marcadas.
 */
async function carregarCapTabela(league) {
  const box = document.getElementById(`capTabelaBox_${league}`);
  if (!box) return;
  box.innerHTML = '<div style="font-size:11px;color:var(--text-3)">Carregando...</div>';
  try {
    const d = await api(`admin.php?action=cap_tabela&league=${league}`);
    const linhas = d.linhas || [];
    const lendas = d.lendas || [];

    // Três colunas como no regulamento, pra caber sem rolar.
    const porColuna = Math.ceil(linhas.length / 3);
    const colunas = [0, 1, 2].map(i => linhas.slice(i * porColuna, (i + 1) * porColuna));
    // A contagem vem colada no OVR, e o salário fecha a linha. Com o salário
    // no meio, ler "quantos tenho de 88" obrigava a pular por cima de um
    // número que não era o procurado.
    const celula = (l) => `
      <tr>
        <td style="padding:4px 8px;border-bottom:1px solid var(--border);font-size:11px">${l.ovr}</td>
        <td style="padding:4px 4px 4px 0;border-bottom:1px solid var(--border);font-size:11px;text-align:right;font-variant-numeric:tabular-nums;${l.jogadores ? 'color:var(--text);font-weight:700' : 'color:var(--text-3)'}">${l.jogadores}</td>
        <td style="padding:4px 8px;border-bottom:1px solid var(--border);font-size:11px;font-weight:700;color:#f5c542;text-align:right;font-variant-numeric:tabular-nums">${l.salario}M</td>
      </tr>`;

    const tabela = `
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:14px 34px">
        ${colunas.map(col => `
          <table style="width:100%;border-collapse:collapse">
            <thead><tr>
              <th style="padding:5px 8px;font-size:10px;text-align:left;color:var(--text-2);text-transform:uppercase;letter-spacing:.06em">OVR</th>
              <th style="padding:5px 4px 5px 0;font-size:10px;text-align:right;color:var(--text-2);text-transform:uppercase;letter-spacing:.06em" title="Jogadores ativos da liga com esse OVR">Jog.</th>
              <th style="padding:5px 8px;font-size:10px;text-align:right;color:var(--text-2);text-transform:uppercase;letter-spacing:.06em">Salário</th>
            </tr></thead>
            <tbody>${col.map(celula).join('')}</tbody>
          </table>`).join('')}
      </div>
      <div style="font-size:11px;color:var(--text-3);margin-top:10px">
        ${d.total_jogadores} jogadores ativos em ${d.total_times} times.
      </div>`;

    const listaLendas = !lendas.length
      ? `<div style="font-size:11px;color:var(--text-3)">Nenhuma lenda marcada ainda. Cada franquia pode marcar uma.</div>`
      : `<div style="display:flex;flex-direction:column;gap:6px">
          ${lendas.map(l => `
            <div style="display:flex;align-items:center;gap:10px;background:var(--panel);border:1px solid var(--border);border-radius:9px;padding:7px 10px;flex-wrap:wrap">
              <span style="font-size:12px;font-weight:800;color:#f5c542">${escapeHtml(l.name)}</span>
              <span style="font-size:11px;color:var(--text-3)">${escapeHtml(l.time)}</span>
              <span style="font-size:11px;color:var(--text-2)">OVR ${l.ovr}${l.age ? ` · ${l.age}a` : ''}</span>
              <span style="margin-left:auto;font-size:12px;font-weight:800;color:#f5c542">${l.salario}M</span>
              ${l.acima_do_piso ? `<span style="font-size:9px;font-weight:800;color:var(--text-3)" title="OVR alto o bastante pra tabela pagar mais que o piso de ${d.lenda_minimo}M">TABELA</span>` : `<span style="font-size:9px;font-weight:800;color:var(--text-3)" title="Piso da lenda">PISO</span>`}
            </div>`).join('')}
         </div>`;

    box.innerHTML = `${tabela}
      <div style="font-size:11px;font-weight:700;color:var(--text-2);text-transform:uppercase;letter-spacing:.06em;margin:16px 0 8px">
        <i class="bi bi-star-fill me-1" style="color:#f5c542"></i>Lendas (${lendas.length})
      </div>
      ${listaLendas}`;
  } catch (e) {
    box.innerHTML = '<div style="font-size:11px;color:var(--text-3)">Não deu pra carregar o controle de cap agora.</div>';
  }
}
window.carregarCapTabela = carregarCapTabela;

/**
 * Tela cheia do controle de cap e jogadores.
 *
 * A mesma tabela existia dentro de Configurações, embaixo dos campos de cada
 * liga — quem queria conferir o cap tinha que passar por uma tela de edição
 * pra chegar numa de leitura. Aqui ela tem lugar próprio, com a folha de cada
 * time junto: a pergunta que traz alguém até aqui quase nunca para na
 * distribuição por OVR, é "quem está estourando".
 */
/**
 * Lendas da liga, time a time. Uma lenda custa no mínimo 40M de cap e cada
 * time marca a sua, então a pergunta prática do admin é "quem já usou a tag e
 * quanto ela está pesando" — e não dava pra responder sem abrir time por time.
 */
async function showLendas(league) {
  appState.view = 'lendas';
  const c = document.getElementById('mainContainer');
  c.innerHTML = '<div class="text-center py-5"><div class="spinner-border" style="color:#f5c542"></div></div>';

  let d;
  try {
    d = await api(`admin.php?action=lendas_liga&league=${encodeURIComponent(league)}`);
  } catch (e) {
    c.innerHTML = `<div class="mb-4"><button class="btn btn-back" onclick="showLeague('${league}')"><i class="bi bi-arrow-left"></i> Voltar</button></div>
      <div class="panel" style="padding:18px;color:#ef4444">Não deu pra carregar as lendas: ${escapeHtml(e.message || 'erro')}</div>`;
    return;
  }

  const times = d.times || [];
  const comLenda = times.filter(t => t.lendas.length);
  const semLenda = times.filter(t => !t.lendas.length);
  const folha = comLenda.reduce((a, t) => a + t.lendas.reduce((s, l) => s + l.salario, 0), 0);

  const cardDoTime = (t) => {
    const l = t.lendas[0];
    return `
    <div class="col-12 col-md-6 col-xl-4">
      <div class="panel" style="padding:14px;height:100%;${l ? 'border-color:rgba(245,197,66,.35)' : ''}">
        <div style="display:flex;align-items:center;gap:9px;margin-bottom:${l ? '10px' : '0'}">
          ${t.logo ? `<img src="${escapeHtml(t.logo)}" alt="" style="width:26px;height:26px;object-fit:contain;flex:none">` : ''}
          <div style="font-size:13px;font-weight:700;color:var(--text)">${escapeHtml(t.time)}</div>
          ${l ? '' : '<span style="margin-left:auto;font-size:11px;color:var(--text-3)">sem lenda</span>'}
        </div>
        ${t.lendas.map(l => `
          <div style="display:flex;align-items:center;gap:10px;padding-top:9px;border-top:1px solid var(--border)">
            <i class="bi bi-star-fill" style="color:#f5c542;font-size:15px"></i>
            <div style="min-width:0;flex:1">
              <div style="font-size:13px;font-weight:700;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${escapeHtml(l.name)}</div>
              <div style="font-size:11px;color:var(--text-3)">
                ${escapeHtml(l.position || '')} · ${l.ovr} OVR${l.age !== null ? ' · ' + l.age + ' anos' : ''}
              </div>
            </div>
            <div style="text-align:right;flex:none">
              <div style="font-size:15px;font-weight:800;color:#f5c542;font-variant-numeric:tabular-nums">${l.salario}M</div>
              <div style="font-size:9px;text-transform:uppercase;letter-spacing:.4px;color:var(--text-3)">${l.no_piso ? 'piso da lenda' : 'tabela OVR'}</div>
            </div>
          </div>`).join('')}
      </div>
    </div>`;
  };

  c.innerHTML = `
<div class="mb-4 d-flex align-items-center gap-2 flex-wrap">
  <button class="btn btn-back" onclick="showLeague('${league}')"><i class="bi bi-arrow-left"></i> Voltar</button>
  <h5 class="mb-0" style="color:#f5c542"><i class="bi bi-star-fill me-2"></i>Lendas — ${escapeHtml(league)}</h5>
  <button class="btn-ghost ms-auto" onclick="showLendas('${league}')"><i class="bi bi-arrow-clockwise me-1"></i>Atualizar</button>
</div>

<div class="row g-2 mb-3">
  ${[
    ['Times com lenda', comLenda.length, '#f5c542'],
    ['Sem lenda', semLenda.length, semLenda.length ? 'var(--text)' : 'var(--text-3)'],
    ['Cap somado', folha + 'M', '#f5c542'],
    ['Piso da lenda', (d.piso || 40) + 'M', 'var(--text-3)'],
  ].map(([rot, val, cr]) => `
    <div class="col-6 col-md-3">
      <div class="panel" style="padding:12px 14px;text-align:center">
        <div style="font-size:22px;font-weight:800;color:${cr};font-variant-numeric:tabular-nums">${val}</div>
        <div style="font-size:10px;font-weight:700;letter-spacing:.5px;text-transform:uppercase;color:var(--text-3);margin-top:3px">${rot}</div>
      </div>
    </div>`).join('')}
</div>

${comLenda.length ? `<div class="row g-2 mb-3">${comLenda.map(cardDoTime).join('')}</div>` : `
  <div class="panel" style="padding:18px;font-size:13px;color:var(--text-3)">
    Nenhum time da ${escapeHtml(league)} marcou lenda ainda.
  </div>`}

${semLenda.length ? `
  <div class="panel mb-3">
    <div class="panel-header">
      <div class="panel-title"><i class="bi bi-dash-circle" style="color:#94a3b8"></i> Sem lenda (${semLenda.length})</div>
    </div>
    <div style="padding:12px 14px;display:flex;flex-wrap:wrap;gap:6px">
      ${semLenda.map(t => `<span class="pun-badge" style="background:var(--bg-3);color:var(--text-3)">${escapeHtml(t.time)}</span>`).join('')}
    </div>
  </div>` : ''}`;
}

/**
 * CADEIRAS E PROMOÇÕES.
 *
 * Um GM sai e a fila sobe: quem está na liga de baixo assume o time como ele
 * está — elenco, picks e folha no lugar — e a cadeira que ele deixou vira a
 * próxima vaga, até a ROOKIE, onde entra alguém novo.
 *
 * Um degrau por vez: cada promoção aplicada faz a vaga seguinte aparecer aqui
 * mesmo, e dá pra parar no meio se o de baixo não quiser subir.
 */
async function showCadeiras() {
  appState.view = 'cadeiras';
  const c = document.getElementById('mainContainer');
  c.innerHTML = '<div class="text-center py-5"><div class="spinner-border" style="color:#0ea5e9"></div></div>';

  const back = `<button class="btn btn-back" onclick="showGestao()"><i class="bi bi-arrow-left"></i> Voltar</button>`;
  let d;
  try {
    d = await api('admin.php?action=cadeiras_estado');
  } catch (e) {
    c.innerHTML = `<div class="mb-4">${back}</div>
      <div class="panel" style="padding:18px;color:#ef4444">Não deu pra carregar: ${escapeHtml(e.error || 'erro')}</div>`;
    return;
  }
  window._cadeiras = d;

  const vagas = d.vagas || [];
  const hist = d.historico || [];

  c.innerHTML = `
<div class="mb-4 d-flex align-items-center gap-2 flex-wrap">
  ${back}
  <h5 class="mb-0" style="color:#0ea5e9"><i class="bi bi-arrow-up-square-fill me-2"></i>Cadeiras e promoções</h5>
  <button class="btn-ghost ms-auto" onclick="showCadeiras()"><i class="bi bi-arrow-clockwise me-1"></i>Atualizar</button>
</div>

<div class="panel mb-3" style="padding:14px 18px;font-size:12.5px;color:var(--text-3)">
  O time não é refeito: quem sobe assume o elenco, as picks e a folha como estão —
  e as punições também, se houver. Muda só quem senta na cadeira.
  Comece liberando o time de quem saiu; a vaga aparece aqui embaixo.
</div>

<div class="panel mb-3">
  <div class="panel-header">
    <div class="panel-title"><i class="bi bi-box-arrow-right" style="color:#ef4444"></i> Alguém saiu</div>
  </div>
  <div style="padding:14px 18px;display:flex;gap:8px;flex-wrap:wrap;align-items:center">
    <select id="cadSaiuTime" class="form-select form-select-sm" style="max-width:340px">
      <option value="">Escolha o time de quem desistiu…</option>
    </select>
    <button class="btn-ghost" style="color:#ef4444;border-color:rgba(239,68,68,.35)" onclick="_cadeiraLiberar()">
      <i class="bi bi-box-arrow-right me-1"></i>Liberar a cadeira
    </button>
  </div>
</div>

${vagas.length ? vagas.map(v => _cadeiraCard(v)).join('') : `
  <div class="panel" style="padding:18px;font-size:13px;color:var(--text-3)">
    Nenhuma cadeira aberta agora — todos os times têm GM.
  </div>`}

${hist.length ? `
<div class="panel mb-3">
  <div class="panel-header"><div class="panel-title"><i class="bi bi-clock-history" style="color:#94a3b8"></i> Últimas trocas</div></div>
  <div style="padding:10px 18px 16px">
    ${hist.map(h => `
      <div style="font-size:12.5px;color:var(--text-2);padding:5px 0;border-bottom:1px solid var(--border)">
        <span style="color:var(--text-3)">${escapeHtml((h.criado_em || '').slice(0, 16))}</span> ·
        <b>${escapeHtml(h.time_nome || '—')}</b> (${escapeHtml(h.league || '')}) —
        ${h.gm_novo ? `entrou <b>${escapeHtml(h.gm_novo)}</b>` : `saiu <b>${escapeHtml(h.gm_antigo || '—')}</b>`}
        ${h.motivo ? `<span style="color:var(--text-3)"> · ${escapeHtml(h.motivo)}</span>` : ''}
      </div>`).join('')}
  </div>
</div>` : ''}`;

  // O seletor de quem saiu lista todo time COM dono — a lista de candidatos
  // das vagas já traz esses times, e é dela que ela sai.
  const sel = document.getElementById('cadSaiuTime');
  (d.times_com_gm || [])
    .sort((a, b) => (a.league || '').localeCompare(b.league || '') || (a.gm || '').localeCompare(b.gm || ''))
    .forEach(g => {
      const o = document.createElement('option');
      o.value = g.team_id;
      o.textContent = `${g.league} · ${g.city || ''} ${g.time_nome} — ${g.gm}`;
      sel.appendChild(o);
    });
}

/** O cartão de uma cadeira aberta, com quem pode assumi-la. */
function _cadeiraCard(v) {
  const porLiga = {};
  (v.candidatos || []).forEach(g => (porLiga[g.league] = porLiga[g.league] || []).push(g));

  return `
  <div class="panel mb-3" style="border-color:rgba(14,165,233,.35)">
    <div class="panel-header">
      <div>
        <div class="panel-title">
          <i class="bi bi-person-slash" style="color:#0ea5e9"></i>
          ${escapeHtml(v.city || '')} ${escapeHtml(v.name)} <span style="color:var(--text-3)">· ${escapeHtml(v.league)}</span>
        </div>
        <div class="panel-sub">${v.jogadores} jogadores no elenco · sem GM
          ${v.temporada_rodando ? ` · <span style="color:#f59e0b">temporada ${v.temporada_rodando} em andamento</span>` : ''}</div>
      </div>
    </div>
    <div style="padding:14px 18px">
      ${v.candidatos && v.candidatos.length ? `
        <div style="font-size:12px;color:var(--text-3);margin-bottom:8px">Quem sobe para esta cadeira:</div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:12px">
          <select class="form-select form-select-sm" id="cadGm_${v.id}" style="max-width:360px">
            ${Object.keys(porLiga).map(lg => `
              <optgroup label="${escapeHtml(lg)}">
                ${porLiga[lg].map(g => `<option value="${g.user_id}">${escapeHtml(g.gm)} — ${escapeHtml(g.city || '')} ${escapeHtml(g.time_nome)}</option>`).join('')}
              </optgroup>`).join('')}
          </select>
          <button class="btn-ghost" style="color:#22c55e;border-color:rgba(34,197,94,.35)" onclick="_cadeiraPromover(${v.id})">
            <i class="bi bi-arrow-up-circle me-1"></i>Promover
          </button>
        </div>
        <!-- O time continua o mesmo; só a identidade pode mudar, se o novo
             dono quiser. Em branco = fica como está. -->
        <div class="row g-2" style="max-width:640px">
          <div class="col-12 col-md-4"><input class="form-control form-control-sm" id="cadCidade_${v.id}" placeholder="Nova cidade (opcional)"></div>
          <div class="col-12 col-md-4"><input class="form-control form-control-sm" id="cadNome_${v.id}" placeholder="Novo nome (opcional)"></div>
          <div class="col-12 col-md-4"><input class="form-control form-control-sm" id="cadEscudo_${v.id}" placeholder="URL do escudo (opcional)"></div>
        </div>
      ` : `<div style="font-size:12.5px;color:var(--text-3);margin-bottom:12px">
             Nenhum GM em liga abaixo desta — a cadeira só pode receber alguém novo.
           </div>`}

      <div style="border-top:1px solid var(--border);margin-top:14px;padding-top:12px">
        <div style="font-size:12px;color:var(--text-3);margin-bottom:8px">Ou põe um GM novo direto nesta cadeira:</div>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
          <input class="form-control form-control-sm" id="cadNovoNome_${v.id}" placeholder="Nome" style="max-width:200px">
          <input class="form-control form-control-sm" id="cadNovoEmail_${v.id}" placeholder="E-mail" style="max-width:240px">
          <button class="btn-ghost" onclick="_cadeiraNovoGm(${v.id})">
            <i class="bi bi-person-plus me-1"></i>Entrar como GM novo
          </button>
        </div>
      </div>
    </div>
  </div>`;
}

async function _cadeiraLiberar() {
  const sel = document.getElementById('cadSaiuTime');
  const id = Number(sel?.value || 0);
  if (!id) { showAlert('warning', 'Escolha o time de quem saiu.'); return; }
  const txt = sel.options[sel.selectedIndex].textContent;
  if (!confirm(`Liberar a cadeira de ${txt}?\n\nO time fica sem GM e o elenco não é tocado.`)) return;
  try {
    const r = await api('admin.php?action=liberar_cadeira', { method: 'POST', body: JSON.stringify({ team_id: id }) });
    showAlert('success', r.message);
    showCadeiras();
  } catch (e) { showAlert('danger', e.error || 'Erro'); }
}

async function _cadeiraPromover(teamId) {
  const userId = Number(document.getElementById(`cadGm_${teamId}`)?.value || 0);
  if (!userId) { showAlert('warning', 'Escolha quem sobe.'); return; }
  const sel = document.getElementById(`cadGm_${teamId}`);
  if (!confirm(`Promover ${sel.options[sel.selectedIndex].textContent}?\n\nEle assume o time como está, e a cadeira dele fica aberta.`)) return;
  try {
    const r = await api('admin.php?action=promover_gm', {
      method: 'POST',
      body: JSON.stringify({
        team_id: teamId, user_id: userId,
        nova_cidade: document.getElementById(`cadCidade_${teamId}`)?.value || '',
        novo_nome:   document.getElementById(`cadNome_${teamId}`)?.value || '',
        novo_escudo: document.getElementById(`cadEscudo_${teamId}`)?.value || '',
      }),
    });
    showAlert('success', r.message);
    showCadeiras();   // a vaga que ele deixou já aparece na volta
  } catch (e) { showAlert('danger', e.error || 'Erro'); }
}

async function _cadeiraNovoGm(teamId) {
  const nome  = document.getElementById(`cadNovoNome_${teamId}`)?.value.trim();
  const email = document.getElementById(`cadNovoEmail_${teamId}`)?.value.trim();
  if (!nome || !email) { showAlert('warning', 'Nome e e-mail são obrigatórios.'); return; }
  try {
    const r = await api('admin.php?action=novo_gm_na_cadeira', {
      method: 'POST', body: JSON.stringify({ team_id: teamId, name: nome, email }),
    });
    // A senha só existe aqui: some no refresh, e é o que a pessoa precisa
    // pra entrar da primeira vez.
    showAlert('success', r.message + (r.senha ? ` Senha inicial: ${r.senha}` : ''));
    if (r.senha) alert(`${r.message}\n\nSenha inicial: ${r.senha}\n\nAnote agora — ela não aparece de novo.`);
    showCadeiras();
  } catch (e) { showAlert('danger', e.error || 'Erro'); }
}

async function showControleCap(league) {
  const c = document.getElementById('mainContainer');
  c.innerHTML = `<div class="text-center py-5"><div class="spinner-border" style="color:var(--red)"></div></div>`;

  let d;
  try {
    d = await api(`admin.php?action=cap_tabela&league=${league}&times=1`);
  } catch (e) {
    c.innerHTML = `<div class="alert alert-danger">Erro: ${escapeHtml(e.error || 'Desconhecido')}</div>`;
    return;
  }

  const times = d.times || [];
  const acima = times.filter(t => t.espaco < 0);
  const abaixoDoPiso = times.filter(t => t.folha < (d.cap_piso || 0));
  const folhaTotal = times.reduce((a, t) => a + t.folha, 0);

  const cor = (t) => t.espaco < 0 ? '#ef4444' : (t.folha < (d.cap_piso || 0) ? '#f59e0b' : '#22c55e');

  c.innerHTML = `
<div class="mb-4 d-flex align-items-center gap-2 flex-wrap">
  <button class="btn btn-back" onclick="showLeague('${league}')"><i class="bi bi-arrow-left"></i> Voltar</button>
  <h5 class="mb-0" style="color:#f5c542"><i class="bi bi-cash-coin me-2"></i>Controle CAP / Jogadores — ${escapeHtml(league)}</h5>
  <button class="btn-ghost ms-auto" onclick="showControleCap('${league}')"><i class="bi bi-arrow-clockwise me-1"></i>Atualizar</button>
</div>

<div class="row g-2 mb-3">
  ${[
    ['Jogadores', d.total_jogadores, 'var(--text)'],
    ['Times', d.total_times, 'var(--text)'],
    ['Folha somada', folhaTotal + 'M', '#f5c542'],
    ['Acima do cap', acima.length, acima.length ? '#ef4444' : 'var(--text-3)'],
    ['Abaixo do piso', abaixoDoPiso.length, abaixoDoPiso.length ? '#f59e0b' : 'var(--text-3)'],
  ].map(([rot, val, cr]) => `
    <div class="col-6 col-md">
      <div class="panel" style="padding:12px 14px;text-align:center">
        <div style="font-size:22px;font-weight:800;color:${cr};font-variant-numeric:tabular-nums">${val}</div>
        <div style="font-size:10px;font-weight:700;letter-spacing:.5px;text-transform:uppercase;color:var(--text-3);margin-top:3px">${rot}</div>
      </div>
    </div>`).join('')}
</div>

<div class="panel mb-3">
  <div class="panel-header">
    <div class="panel-title"><i class="bi bi-people-fill"></i> Folha por time</div>
    <span style="font-size:11px;color:var(--text-3)">cap base ${d.cap_base}M · piso ${d.cap_piso}M</span>
  </div>
  <div style="padding:8px 14px 14px;overflow-x:auto">
    ${times.length ? `
    <table class="table table-dark table-hover" style="font-size:12.5px;margin:0">
      <thead><tr>
        <th>Time</th>
        <th style="text-align:right">Jog.</th>
        <th style="text-align:right" title="Calouros na rookie scale nesta temporada">Cal.</th>
        <th style="text-align:right">Folha</th>
        <th style="text-align:right">Cap máx</th>
        <th style="text-align:right">Espaço</th>
      </tr></thead>
      <tbody>${times.slice().sort((a, b) => b.folha - a.folha).map(t => `
        <tr>
          <td style="font-weight:600">${escapeHtml(t.nome)}</td>
          <td style="text-align:right;font-variant-numeric:tabular-nums">${t.jogadores}</td>
          <td style="text-align:right;font-variant-numeric:tabular-nums;color:${t.calouros ? 'var(--text-2)' : 'var(--text-3)'}">${t.calouros}</td>
          <td style="text-align:right;font-variant-numeric:tabular-nums;font-weight:700;color:${cor(t)}">${t.folha}M</td>
          <td style="text-align:right;font-variant-numeric:tabular-nums;color:var(--text-3)">${t.cap_max}M</td>
          <td style="text-align:right;font-variant-numeric:tabular-nums;color:${cor(t)}">${t.espaco > 0 ? '+' : ''}${t.espaco}M</td>
        </tr>`).join('')}</tbody>
    </table>` : '<div class="empty-state" style="padding:20px">Nenhum time nesta liga.</div>'}
  </div>
</div>

${(() => {
  const cal = d.calouros || [];
  if (!cal.length) return `
<div class="panel mb-3">
  <div class="panel-header"><div class="panel-title"><i class="bi bi-person-badge"></i> Rookie scale</div></div>
  <div style="padding:14px 16px">
    <p class="nota-txt" style="margin:0;font-size:12px;color:var(--text-3);line-height:1.6">
      Nenhum calouro na rookie scale nesta temporada. Ela só vale no ano de estreia de quem foi
      draftado na 1ª ou 2ª rodada do draft anual — passado esse ano, todo mundo volta pra tabela por OVR.
    </p>
  </div>
</div>`;

  const economizando = cal.filter(p => p.economia > 0);
  const pagandoCaro  = cal.filter(p => p.economia < 0);
  const saldo = cal.reduce((a, p) => a + p.economia, 0);

  return `
<div class="panel mb-3">
  <div class="panel-header">
    <div class="panel-title"><i class="bi bi-person-badge"></i> Rookie scale — ${cal.length} calouro(s)</div>
    <span style="font-size:11px;color:${saldo >= 0 ? '#22c55e' : '#ef4444'}">
      saldo da liga: ${saldo > 0 ? '+' : ''}${saldo}M</span>
  </div>
  <div style="padding:10px 16px 16px">
    <p style="font-size:11.5px;color:var(--text-3);line-height:1.55;margin-bottom:12px">
      No ano de estreia o calouro paga pela POSIÇÃO em que foi escolhido, não pelo OVR dele.
      A coluna da direita é a diferença: <b style="color:#22c55e">verde</b> é o quanto a escala está
      saindo mais barata que o OVR dele valeria; <b style="color:#ef4444">vermelho</b> é o quanto o time
      está pagando a mais por uma escolha que não rendeu. Ano que vem todos voltam pra tabela por OVR.
    </p>
    <div style="overflow-x:auto">
      <table class="table table-dark table-hover" style="font-size:12.5px;margin:0">
        <thead><tr>
          <th>Jogador</th><th>Time</th>
          <th style="text-align:right">OVR</th>
          <th style="text-align:right">Escolha</th>
          <th style="text-align:right">Paga</th>
          <th style="text-align:right" title="O que ele custaria pela tabela de OVR">Valeria</th>
          <th style="text-align:right">Diferença</th>
        </tr></thead>
        <tbody>${cal.map(p => `
          <tr>
            <td style="font-weight:600">${escapeHtml(p.name)}</td>
            <td style="color:var(--text-2)">${escapeHtml(p.time)}</td>
            <td style="text-align:right;font-variant-numeric:tabular-nums">${p.ovr}</td>
            <td style="text-align:right;color:var(--text-2);white-space:nowrap">${
              p.round >= 2 ? '2ª rodada' : (p.pick ? `#${p.pick}` : '1ª rodada')}</td>
            <td style="text-align:right;font-variant-numeric:tabular-nums;font-weight:700;color:#f5c542">${p.paga}M</td>
            <td style="text-align:right;font-variant-numeric:tabular-nums;color:var(--text-3)">${p.pela_tabela}M</td>
            <td style="text-align:right;font-variant-numeric:tabular-nums;font-weight:700;color:${
              p.economia > 0 ? '#22c55e' : (p.economia < 0 ? '#ef4444' : 'var(--text-3)')}">${
              p.economia > 0 ? '+' : ''}${p.economia}M</td>
          </tr>`).join('')}</tbody>
      </table>
    </div>
    <p style="font-size:11px;color:var(--text-3);margin:10px 0 0">
      ${economizando.length} escolha(s) rendendo mais do que custam${
        pagandoCaro.length ? ` · ${pagandoCaro.length} custando mais do que rendem` : ''}.
    </p>
  </div>
</div>`;
})()}

${(() => {
  const j = d.jovens || [];
  if (!j.length) return '';
  // O critério vem do servidor — é o mesmo que filtrou a lista, então o texto
  // não tem como prometer uma coisa e a tabela mostrar outra.
  const cr = d.jovens_criterio || { idade_min: 19, idade_max: 23, ovr_min: 78, rodadas: 4 };
  const folha = j.reduce((a, p) => a + p.salario, 0);
  const porRodada = Array.from({ length: cr.rodadas }, (_, i) => j.filter(p => p.round === i + 1).length);

  return `
<div class="panel mb-3">
  <div class="panel-header">
    <div class="panel-title"><i class="bi bi-people"></i> Jovens do draft inicial — ${j.length}</div>
    <span style="font-size:11px;color:var(--text-3)">custam ${folha}M somados</span>
  </div>
  <div style="padding:10px 16px 16px">
    <p style="font-size:11.5px;color:var(--text-3);line-height:1.55;margin-bottom:12px">
      Escolhidos nas <b>${cr.rodadas} primeiras rodadas</b> do draft inicial, com
      <b>${cr.idade_min} a ${cr.idade_max} anos</b> e <b>OVR ${cr.ovr_min}+</b> hoje.
      Quem não bate nos três não aparece aqui.
      Eles não estão na rookie scale — o draft inicial não é draft de calouro, então cada um já paga
      pela tabela de OVR. É a safra jovem que a liga tem fora do draft anual.
      <br>Por rodada: ${porRodada.map((n, i) => `${i + 1}ª <b style="color:var(--text-2)">${n}</b>`).join(' · ')}
    </p>
    <div style="overflow-x:auto">
      <table class="table table-dark table-hover" style="font-size:12.5px;margin:0">
        <thead><tr>
          <th>Jogador</th><th>Time</th>
          <th style="text-align:right">Idade</th>
          <th style="text-align:right">OVR</th>
          <th style="text-align:right">Escolha</th>
          <th style="text-align:right">Custa</th>
        </tr></thead>
        <tbody>${j.map(p => `
          <tr>
            <td style="font-weight:600">${escapeHtml(p.name)}</td>
            <td style="color:var(--text-2)">${escapeHtml(p.time)}</td>
            <td style="text-align:right;font-variant-numeric:tabular-nums">${p.idade}</td>
            <td style="text-align:right;font-variant-numeric:tabular-nums;font-weight:700">${p.ovr}</td>
            <td style="text-align:right;color:var(--text-2);white-space:nowrap">${p.round}ª · #${p.pick}</td>
            <td style="text-align:right;font-variant-numeric:tabular-nums;font-weight:700;color:#f5c542">${p.salario}M${
              p.piso ? ' <span style="font-size:9px;font-weight:800;color:var(--text-3)" title="Piso do draft inicial — pela tabela de OVR ele custaria menos">PISO</span>' : ''}</td>
          </tr>`).join('')}</tbody>
      </table>
    </div>
  </div>
</div>`;
})()}

<div class="panel">
  <div class="panel-header"><div class="panel-title"><i class="bi bi-table"></i> Jogadores por OVR</div></div>
  <div style="padding:12px 14px 16px">
    <p style="font-size:11.5px;color:var(--text-3);line-height:1.5;margin-bottom:12px">
      Quanto cada OVR custa e quantos jogadores da liga estão em cada faixa. Calouro de 1ª rodada foge
      desta tabela no ano de estreia — aí vale a rookie scale, pela posição da pick.
    </p>
    <div id="capTabelaBox_${league}"><div style="font-size:11px;color:var(--text-3)">Carregando...</div></div>
  </div>
</div>`;

  carregarCapTabela(league);
}
window.showControleCap = showControleCap;

async function loadCapHistory(league) {
  const box = document.getElementById(`capHistory_${league}`);
  if (!box) return;
  try {
    const data = await api(`admin.php?action=cap_history&league=${league}`);
    const rows = data.history || [];
    if (!rows.length) {
      box.innerHTML = '<div style="font-size:11px;color:var(--text-3)">Nenhum recálculo automático registrado ainda.</div>';
      return;
    }
    box.innerHTML = `
      <div style="font-size:11px;font-weight:600;color:var(--text-2);margin-bottom:6px">Histórico do CAP</div>
      <div style="display:flex;flex-direction:column;gap:6px">
        ${rows.map(r => {
          const dataFmt = r.created_at ? new Date(r.created_at.replace(' ', 'T')).toLocaleDateString('pt-BR') : '';
          const margemTxt = r.cap_mode === 'salary' ? `${r.margin}%` : `${r.margin} pts`;
          return `<div style="display:flex;align-items:center;gap:8px;font-size:11px;color:var(--text-2);background:var(--panel-3);border-radius:8px;padding:6px 10px;flex-wrap:wrap">
            <span style="font-weight:700;color:var(--text)">Temp. ${r.season_number}</span>
            <span>média ${r.avg_value} · margem ${margemTxt}</span>
            <span style="color:var(--red);font-weight:600">${r.cap_min}–${r.cap_max}</span>
            <span>${r.teams_above} acima · ${r.teams_below} abaixo de ${r.teams_total}</span>
            <span style="margin-left:auto;color:var(--text-3)">${dataFmt}</span>
          </div>`;
        }).join('')}
      </div>`;
  } catch (e) {
    box.innerHTML = '';
  }
}

async function saveLeagueSettings(btn) {
  const inputs = document.querySelectorAll('#configContainer input[data-league], #configContainer textarea[data-league]');
  const groups = {};
  inputs.forEach(inp => {
    const lg = inp.dataset.league;
    groups[lg] = groups[lg] || { league: lg };
    const stringFields = ['edital', 'n8n_webhook_url', 'progression_video_url', 'sistemas_video_url', 'freeagency_video_url'];
    const value = stringFields.includes(inp.dataset.field) ? inp.value : parseInt(inp.value);
    groups[lg][inp.dataset.field] = value;
  });

  if (!btn) return;
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Salvando...';
  
  try {
    await Promise.all(Object.values(groups).map(e => api('admin.php?action=league_settings', { method: 'PUT', body: JSON.stringify(e) })));
    btn.classList.add('btn-success');
    btn.innerHTML = '<i class="bi bi-check-lg me-1"></i>Salvo!';
    setTimeout(() => {
      btn.classList.remove('btn-success');
      btn.innerHTML = '<i class="bi bi-save2 me-1"></i>Salvar';
      btn.disabled = false;
    }, 2000);
  } catch (e) {
    alert('Erro ao salvar');
    btn.innerHTML = '<i class="bi bi-save2 me-1"></i>Salvar';
    btn.disabled = false;
  }
}

async function _loadLeagueConfigInline(league) {
  const section = document.getElementById('leagueConfigInline');
  const body = document.getElementById('leagueConfigInlineBody');
  if (!section || !body) return;
  try {
    const data = await api('admin.php?action=leagues');
    const lg = (data.leagues || []).find(l => l.league === league);
    if (!lg) return;
    section.style.display = '';
    const tradesOn = (lg.trades_enabled ?? 1) == 1;
    const faOn = (lg.fa_enabled ?? 1) == 1;
    const waiversOn = (lg.waivers_enabled ?? 1) == 1;
    const badgeStyle = (on) => on
      ? 'background:rgba(37,198,119,.15);color:#25c677;border:1px solid rgba(37,198,119,.25)'
      : 'background:color-mix(in srgb, var(--red) 12%, transparent);color:var(--red);border:1px solid var(--border-red)';
    body.innerHTML = `
      <div class="lgcfg">

        <div class="lgcfg-bloco">
          <div class="lgcfg-titulo"><i class="bi bi-sliders2"></i>Regras</div>
          <div class="lgcfg-nums">
            <div class="lgcfg-campo">
              <label>CAP mínimo</label>
              <input type="number" class="form-control form-control-sm" value="${lg.cap_min}"
                     data-league="${lg.league}" data-field="cap_min">
            </div>
            <div class="lgcfg-campo">
              <label>CAP máximo</label>
              <input type="number" class="form-control form-control-sm" value="${lg.cap_max}"
                     data-league="${lg.league}" data-field="cap_max">
            </div>
            <div class="lgcfg-campo">
              <label>Máx. trocas / temp.</label>
              <input type="number" class="form-control form-control-sm" value="${lg.max_trades || 3}"
                     data-league="${lg.league}" data-field="max_trades">
            </div>
            <div class="lgcfg-campo">
              <label>Temporadas / sprint</label>
              <input type="number" class="form-control form-control-sm" min="1" value="${lg.max_seasons || ''}"
                     data-league="${lg.league}" data-field="max_seasons">
            </div>
            <div class="lgcfg-faixa">
              <b>${lg.cap_min}–${lg.cap_max}</b>
              <span>faixa do CAP</span>
            </div>
          </div>
        </div>

        <div class="lgcfg-bloco">
          <div class="lgcfg-titulo"><i class="bi bi-door-open"></i>Janelas</div>
          <div class="lgcfg-janelas">
            ${janelaLinha({
              liga: lg.league, icone: 'bi-arrow-left-right', cor: 'var(--blue)', nome: 'Trades',
              aberta: tradesOn, rotuloOn: 'Ativas', rotuloOff: 'Bloqueadas',
              idSelo: `tradesBadge_${lg.league}`, idOn: `tradesOnBtn_${lg.league}`,
              idOff: `tradesOffBtn_${lg.league}`, acao: 'toggleTrades',
              campo: 'fechar_trades_em', valor: lg.fechar_trades_em, alvo: 'trades',
            })}
            ${janelaLinha({
              liga: lg.league, icone: 'bi-coin', cor: 'var(--green)', nome: 'Free Agency',
              aberta: faOn, rotuloOn: 'Ativa', rotuloOff: 'Bloqueada',
              idSelo: `faBadge_${lg.league}`, idOn: `faOnBtn_${lg.league}`,
              idOff: `faOffBtn_${lg.league}`, acao: 'toggleFA',
              campo: 'fechar_fa_em', valor: lg.fechar_fa_em, alvo: 'a free agency',
            })}
            <!-- Dispensa sem fechamento agendado: ela é decisão de janela, não
                 de prazo, e um horário aqui sugeriria um automatismo que não
                 existe. Aposentadoria não passa por esta chave. -->
            ${janelaLinha({
              liga: lg.league, icone: 'bi-person-dash', cor: '#f59e0b', nome: 'Dispensas',
              aberta: waiversOn, rotuloOn: 'Abertas', rotuloOff: 'Fechadas',
              idSelo: `waiversBadge_${lg.league}`, idOn: `waiversOnBtn_${lg.league}`,
              idOff: `waiversOffBtn_${lg.league}`, acao: 'toggleWaivers',
              campo: null, valor: null, alvo: 'as dispensas',
            })}
            <!-- A tática vem de outra API (admin-control), então os botões dela
                 chegam depois. A linha e o campo de horário já nascem aqui pra
                 tela não pular quando a resposta cair. -->
            <div class="lgcfg-janela">
              <div class="lgcfg-jn">
                <i class="bi bi-clipboard2-pulse" style="color:#14b8a6"></i><b>Táticas</b>
              </div>
              <div class="lgcfg-acoes" id="tatCtrl_${lg.league}">
                <span class="lgcfg-selo off" style="opacity:.5">carregando</span>
              </div>
              ${agendaCampo(lg.league, 'fechar_taticas_em', lg.fechar_taticas_em, 'as táticas')}
            </div>
          </div>
          <div id="ctrlExtra_${lg.league}" style="display:flex;gap:10px;flex-wrap:wrap"></div>
        </div>

        <div class="lgcfg-bloco">
          <div class="lgcfg-titulo"><i class="bi bi-play-btn"></i>Vídeos</div>
          <div class="lgcfg-videos">
            ${videoFieldHtml(lg.league, 'progression', 'Progression', lg.progression_video_url, true)}
            ${videoFieldHtml(lg.league, 'sistemas', 'Sistemas', lg.sistemas_video_url, true)}
            ${videoFieldHtml(lg.league, 'freeagency', 'Free Agency', lg.freeagency_video_url, true)}
          </div>
        </div>

      </div>`;
    _carregarControlesExtras(league);
    // O "falta quanto" vivo: recalcula ao digitar e de minuto em minuto.
    atualizarFaltaAgenda(body);
    body.querySelectorAll('input[data-agenda-alvo]').forEach(inp =>
      inp.addEventListener('input', () => atualizarFaltaAgenda(body)));
    clearInterval(window.__agendaTimer);
    window.__agendaTimer = setInterval(() => atualizarFaltaAgenda(document), 60000);
    document.getElementById('saveConfigInlineBtn')?.addEventListener('click', async () => {
      const inputs = body.querySelectorAll('input[data-league]');
      const payload = { league };
      inputs.forEach(inp => { payload[inp.dataset.field] = inp.type === 'number' ? parseInt(inp.value) : inp.value; });
      // Campo de horário vazio vai como string vazia, e é assim que o
      // servidor entende "apaga o agendamento". Não dá pra omitir: omitir
      // quer dizer "não mexi", e aí limpar o campo na tela não limparia nada.
      const btn = document.getElementById('saveConfigInlineBtn');
      if (btn) { btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>'; }
      try {
        await api('admin.php?action=league_settings', { method: 'PUT', body: JSON.stringify(payload) });
        showAlert('success', 'Configurações salvas!');
      } catch (e) { alert(e.error || 'Erro ao salvar'); }
      finally { if (btn) { btn.disabled = false; btn.innerHTML = '<i class="bi bi-save2 me-1"></i>Salvar'; } }
    });
  } catch (e) {}
}

/**
 * UMA LINHA DE JANELA: o botão e o horário da mesma coisa, lado a lado.
 *
 * Antes o botão "bloquear trades" e o campo "fechar trades às 18h" ficavam a
 * meia tela um do outro, em blocos diferentes, e eram a MESMA decisão — uma
 * agora e outra depois. Junto, dá pra ler a linha inteira de uma vez: o que
 * é, como está, e quando muda sozinho.
 */
function janelaLinha(o) {
  return `
    <div class="lgcfg-janela">
      <div class="lgcfg-jn">
        <i class="bi ${o.icone}" style="color:${o.cor}"></i><b>${o.nome}</b>
      </div>
      <div class="lgcfg-acoes">
        <!-- Sem selo de estado: o botao ACESO ja e o estado. Com o selo, a
             linha dizia "Trades / Ativas / [Ativas] / [Bloqueadas]" — tres
             chips seguidos pra uma informacao so. O id fica num span
             escondido porque os toggles procuram por ele. -->
        <span id="${o.idSelo}" hidden></span>
        <button class="btn btn-sm ${o.aberta ? 'btn-success' : 'btn-outline-success'}"
                onclick="${o.acao}('${o.liga}', 1)" id="${o.idOn}">${o.rotuloOn}</button>
        <button class="btn btn-sm ${!o.aberta ? 'btn-danger' : 'btn-outline-danger'}"
                onclick="${o.acao}('${o.liga}', 0)" id="${o.idOff}">${o.rotuloOff}</button>
      </div>
      ${o.campo ? agendaCampo(o.liga, o.campo, o.valor, o.alvo) : ''}
    </div>`;
}

/**
 * O horário em que aquela janela fecha sozinha.
 *
 * Campo vazio é o normal e quer dizer "não fecha sozinho". Depois que a hora
 * passa, o servidor fecha e LIMPA o campo, então ele volta a nascer vazio
 * pra próxima janela — sem isso a tela mostraria pra sempre uma data de mês
 * passado e ninguém saberia se ela ainda vale.
 */
function agendaCampo(liga, campo, valor, alvo) {
  return `
    <div class="lgcfg-agenda">
      <div class="lgcfg-agenda-linha">
        <span>fecha sozinho em</span>
        <input type="datetime-local" class="form-control form-control-sm" value="${valor || ''}"
               data-league="${liga}" data-field="${campo}" data-agenda-alvo="${alvo}">
        <button class="btn btn-sm btn-outline-secondary" onclick="limparAgenda(this)"
                title="Limpar — sem horário, não fecha sozinho"><i class="bi bi-x-lg"></i></button>
      </div>
      <div class="lgcfg-falta"></div>
    </div>`;
}

/** Limpa o campo de horário ao lado do botão. */
function limparAgenda(btn) {
  // closest, pelo mesmo motivo do atualizarFaltaAgenda: caminho fixo pelo
  // parentElement quebra calado quando o HTML muda.
  const inp = btn.closest('.lgcfg-agenda')?.querySelector('input[data-agenda-alvo]');
  if (!inp) return;
  inp.value = '';
  inp.dispatchEvent(new Event('input', { bubbles: true }));
}

/**
 * "Fecha daqui a 3h20" embaixo do campo.
 *
 * Existe porque data e hora escritas não dizem quanto falta, e quanto falta
 * é a única coisa que o admin quer saber ao olhar. Também é onde o erro de
 * dedo aparece: marcar ontem sem querer vira um aviso vermelho antes de
 * salvar, e não um "por que a liga fechou?" no dia seguinte.
 */
function atualizarFaltaAgenda(raiz) {
  (raiz || document).querySelectorAll('input[data-agenda-alvo]').forEach(inp => {
    // closest e não parentElement.parentElement: a estrutura da linha mudou
    // no redesenho e o caminho fixo passou a apontar pro nada — o "quanto
    // falta" sumiu da tela sem erro nenhum no console, que é o pior jeito de
    // quebrar. Com closest, mexer no HTML de novo não quebra isto.
    const caixa = inp.closest('.lgcfg-agenda');
    const alvo = caixa && caixa.querySelector('.lgcfg-falta');
    if (!alvo) return;
    alvo.classList.remove('passou');
    if (!inp.value) { alvo.textContent = 'sem horário — não fecha sozinho'; return; }
    const ms = new Date(inp.value).getTime() - Date.now();
    if (isNaN(ms)) { alvo.textContent = ''; return; }
    if (ms <= 0) {
      alvo.textContent = 'esse horário já passou';
      alvo.classList.add('passou');
      return;
    }
    const min = Math.round(ms / 60000);
    const d = Math.floor(min / 1440), h = Math.floor((min % 1440) / 60), m = min % 60;
    const falta = d ? `${d}d ${h}h` : (h ? `${h}h ${m}min` : `${m}min`);
    alvo.textContent = `fecha ${inp.dataset.agendaAlvo} em ${falta}`;
  });
}

function editTeam(teamId) {
  const t = appState.currentTeam;
  if (!t || t.id != teamId) return;
  
  const modal = document.createElement('div');
  modal.className = 'modal fade';
  modal.innerHTML = `<div class="modal-dialog"><div class="modal-content bg-dark-panel"><div class="modal-header border-orange">
<h5 class="modal-title text-white">Editar Time</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
<div class="modal-body"><div class="mb-3"><label class="form-label text-light-gray">Cidade</label>
<input type="text" class="form-control bg-dark text-white border-orange" id="editTeamCity" value="${escapeHtml(t.city)}"></div>
<div class="mb-3"><label class="form-label text-light-gray">Nome</label>
<input type="text" class="form-control bg-dark text-white border-orange" id="editTeamName" value="${escapeHtml(t.name)}"></div>
<div class="mb-3"><label class="form-label text-light-gray">Conferência</label>
<select class="form-select bg-dark text-white border-orange" id="editTeamConference">
<option value="">Sem conferência</option><option value="LESTE" ${t.conference === 'LESTE' ? 'selected' : ''}>LESTE</option>
<option value="OESTE" ${t.conference === 'OESTE' ? 'selected' : ''}>OESTE</option></select></div></div>
<div class="modal-footer border-orange"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
<button type="button" class="btn btn-orange" onclick="saveTeamEdit(${teamId})">Salvar</button></div></div></div>`;
  
  document.body.appendChild(modal);
  new bootstrap.Modal(modal).show();
  modal.addEventListener('hidden.bs.modal', () => modal.remove());
}

async function saveTeamEdit(teamId) {
  /* O MODAL A FECHAR É O DESTE FORMULÁRIO, não o primeiro da página.
     `querySelector('.modal')` pegava o ouvidoriaModal, que fica no HTML
     desde o começo e não tem instância do Bootstrap: o .hide() de null
     estourava DEPOIS do salvamento e caía no catch, então a tela dizia
     "Erro" com a mudança já gravada. */
  const modal = document.getElementById('editTeamConference')?.closest('.modal');
  try {
    await api('admin.php?action=team', {
      method: 'PUT',
      body: JSON.stringify({
        team_id: teamId,
        city: document.getElementById('editTeamCity').value,
        name: document.getElementById('editTeamName').value,
        conference: document.getElementById('editTeamConference').value
      })
    });
    if (modal) bootstrap.Modal.getOrCreateInstance(modal).hide();
    await showTeam(teamId);
    alert('Atualizado!');
  } catch (e) {
    // O erro de verdade em vez de "Erro": sem ele, um problema de permissão
    // e um de conexão dão exatamente a mesma tela.
    alert('Não deu pra salvar: ' + (e.error || e.message || 'erro desconhecido'));
  }
}

function editPlayer(playerId) {
  const p = appState.teamDetails.players.find(p => p.id == playerId);
  if (!p) return;
  
  const modal = document.createElement('div');
  modal.className = 'modal fade';
  modal.innerHTML = `<div class="modal-dialog"><div class="modal-content bg-dark-panel"><div class="modal-header border-orange">
<h5 class="modal-title text-white">Editar ${p.name}</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
<div class="modal-body"><div class="mb-3"><label class="form-label text-light-gray">Nome</label>
<input type="text" class="form-control bg-dark text-white border-orange" id="editPlayerName" value="${escapeHtml(p.name || '')}" maxlength="120"></div>
<div class="mb-3"><label class="form-label text-light-gray">Posição</label>
<input type="text" class="form-control bg-dark text-white border-orange" id="editPlayerPosition" value="${p.position}"></div>
<div class="row">
<div class="col-md-6 mb-3"><label class="form-label text-light-gray">Pos. Secundária</label>
<select class="form-select bg-dark text-white border-orange" id="editPlayerSecondaryPosition">
<option value="" ${!p.secondary_position ? 'selected' : ''}>Sem</option>
<option value="PG" ${p.secondary_position === 'PG' ? 'selected' : ''}>PG</option>
<option value="SG" ${p.secondary_position === 'SG' ? 'selected' : ''}>SG</option>
<option value="SF" ${p.secondary_position === 'SF' ? 'selected' : ''}>SF</option>
<option value="PF" ${p.secondary_position === 'PF' ? 'selected' : ''}>PF</option>
<option value="C" ${p.secondary_position === 'C' ? 'selected' : ''}>C</option>
</select></div>
<div class="col-md-6 mb-3"><label class="form-label text-light-gray">Idade</label>
<input type="number" class="form-control bg-dark text-white border-orange" id="editPlayerAge" value="${p.age || ''}" min="16" max="60"></div>
</div>
<div class="mb-3"><label class="form-label text-light-gray">OVR</label>
<input type="number" class="form-control bg-dark text-white border-orange" id="editPlayerOvr" value="${p.ovr}" min="0" max="99"></div>
<div class="mb-3"><label class="form-label text-light-gray">Papel</label>
<select class="form-select bg-dark text-white border-orange" id="editPlayerRole">
<option value="Titular" ${p.role === 'Titular' ? 'selected' : ''}>Titular</option>
<option value="Banco" ${p.role === 'Banco' ? 'selected' : ''}>Banco</option>
<option value="Outro" ${p.role === 'Outro' ? 'selected' : ''}>Outro</option>
<option value="G-League" ${p.role === 'G-League' ? 'selected' : ''}>G-League</option></select></div>
${appState.currentTeam.league === 'RISE' ? `<div class="mb-3 p-3 rounded" style="background:rgba(245,158,11,.08);border:1px solid rgba(245,158,11,.25)">
<div class="form-check form-switch">
<input class="form-check-input" type="checkbox" role="switch" id="editPlayerFranchise" ${Number(p.is_franchise_player) === 1 ? 'checked' : ''}>
<label class="form-check-label" for="editPlayerFranchise" style="color:#f59e0b;font-weight:600">🏆 Elegível Restricted CAP</label></div>
<small class="text-light-gray d-block mt-1">Override manual: marca o jogador como elegível para bônus de CAP independente das regras automáticas.</small></div>` : ''}
<div class="mb-3 p-3 rounded" style="background:rgba(59,130,246,.08);border:1px solid rgba(59,130,246,.25)">
<div class="form-check form-switch">
<input class="form-check-input" type="checkbox" role="switch" id="editPlayerLoyal" ${Number(p.is_loyal) === 1 ? 'checked' : ''}>
<label class="form-check-label" for="editPlayerLoyal" style="color:#3b82f6;font-weight:600">🤝 Leal</label></div>
<small class="text-light-gray d-block mt-1">Override manual: define se o jogador é "Leal" independente da regra automática (nunca trocado + veio do draft normal do próprio time).</small></div>
<div class="mb-3 p-3 rounded" style="background:rgba(245,197,66,.08);border:1px solid rgba(245,197,66,.3)">
<div class="form-check form-switch">
<input class="form-check-input" type="checkbox" role="switch" id="editPlayerLenda" ${Number(p.is_lenda) === 1 ? 'checked' : ''}>
<label class="form-check-label" for="editPlayerLenda" style="color:#f5c542;font-weight:700">⭐ Lenda da franquia</label></div>
<small class="text-light-gray d-block mt-1">Uma por time — marcar aqui tira a marca do jogador anterior. Nome fica dourado com a tag LENDA${appState.currentTeam.league === 'ELITE' ? ', e no cap ele passa a valer no mínimo 40M (de OVR 95 pra cima volta a tabela)' : ''}.</small></div>
<div class="mb-3"><label class="form-label text-light-gray">Transferir</label>
<select class="form-select bg-dark text-white border-orange" id="editPlayerTeam"><option value="">Manter no time</option></select></div></div>
<div class="modal-footer border-orange"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
<button type="button" class="btn btn-orange" onclick="savePlayerEdit(${playerId})">Salvar</button></div></div></div>`;
  
  document.body.appendChild(modal);
  api('admin.php?action=teams').then(data => {
    const select = modal.querySelector('#editPlayerTeam');
    const currentLeague = appState.currentTeam.league;
    data.teams.forEach(t => {
      // Apenas times da mesma liga, exceto o time atual
      if (t.id != appState.currentTeam.id && t.league === currentLeague) {
        const opt = document.createElement('option');
        opt.value = t.id;
        opt.textContent = `${t.city} ${t.name}`;
        select.appendChild(opt);
      }
    });
  });
  new bootstrap.Modal(modal).show();
  modal.addEventListener('hidden.bs.modal', () => modal.remove());
}

async function savePlayerEdit(playerId) {
  const secondaryPos = document.getElementById('editPlayerSecondaryPosition')?.value;
  // Nome só vai se mudou de verdade: mandar sempre faria toda edição de OVR
  // passar pelo UNIQUE (team_id, name) sem necessidade.
  const nomeAtual = (appState.teamDetails.players.find(x => x.id == playerId)?.name || '').trim();
  const nomeNovo = (document.getElementById('editPlayerName')?.value || '').trim();
  if (nomeNovo === '') { showAlert('danger', 'O nome não pode ficar vazio.'); return; }

  const data = {
    player_id: playerId,
    position: document.getElementById('editPlayerPosition').value,
    secondary_position: (secondaryPos !== undefined) ? (secondaryPos || null) : undefined,
    ovr: parseInt(document.getElementById('editPlayerOvr').value, 10),
    role: document.getElementById('editPlayerRole').value,
    is_franchise_player: document.getElementById('editPlayerFranchise')?.checked ? 1 : 0,
    loyal_override: document.getElementById('editPlayerLoyal')?.checked ? 1 : 0
  };
  if (nomeNovo !== nomeAtual) data.name = nomeNovo;

  const ageVal = parseInt(document.getElementById('editPlayerAge')?.value || '', 10);
  if (!Number.isNaN(ageVal)) data.age = ageVal;
  if (data.secondary_position === undefined) delete data.secondary_position;

  const teamId = document.getElementById('editPlayerTeam').value;
  if (teamId) data.team_id = parseInt(teamId, 10) || teamId;

  try {
    await api('admin.php?action=player', { method: 'PUT', body: JSON.stringify(data) });

    // A lenda vai por ação própria: ela precisa tirar a marca do jogador anterior
    // do time na mesma transação (só pode haver uma por franquia).
    const lendaChk = document.getElementById('editPlayerLenda');
    if (lendaChk) {
      await api('team.php', {
        method: 'POST',
        body: JSON.stringify({ action: 'set_lenda', player_id: playerId, lenda: lendaChk.checked }),
      });
    }

    const modalEl = document.querySelector('.modal.show') || document.querySelector('.modal');
    bootstrap.Modal.getInstance(modalEl)?.hide();
    await showTeam(appState.currentTeam.id);
    showAlert('success', 'Jogador atualizado!');
  } catch (e) {
    showAlert('danger', 'Erro ao salvar: ' + (e.error || e.message || 'Erro desconhecido'));
  }
}

async function deletePlayer(playerId) {
  if (!await confirmarSite('Deletar jogador?')) return;
  try {
    await api(`admin.php?action=player&id=${playerId}`, { method: 'DELETE' });
    await showTeam(appState.currentTeam.id);
    alert('Deletado!');
  } catch (e) { alert('Erro'); }
}

async function cancelTrade(tradeId) {
  if (!await confirmarSite('Cancelar trade?')) return;
  try {
    await api('admin.php?action=cancel_trade', { method: 'PUT', body: JSON.stringify({ trade_id: tradeId }) });
    await showTrades();
    alert('Cancelada!');
  } catch (e) { alert('Erro'); }
}

async function revertTrade(tradeId) {
  if (!await confirmarSite('REVERTER trade? Jogadores voltarão aos times originais.')) return;
  try {
    await api('admin.php?action=revert_trade', { method: 'PUT', body: JSON.stringify({ trade_id: tradeId }) });
    await showTrades();
    alert('Revertida!');
  } catch (e) { alert('Erro'); }
}

async function revertMultiTrade(tradeId) {
  if (!await confirmarSite('REVERTER trade múltipla? Itens voltarão aos times originais.')) return;
  try {
    await api('admin.php?action=revert_multi_trade', { method: 'PUT', body: JSON.stringify({ trade_id: tradeId }) });
    await showTrades();
    alert('Revertida!');
  } catch (e) { alert('Erro'); }
}

function addPlayer(teamId) {
  const modal = document.createElement('div');
  modal.className = 'modal fade';
  modal.id = 'addPlayerModal';
  modal.innerHTML = `<div class="modal-dialog"><div class="modal-content bg-dark-panel"><div class="modal-header border-orange">
<h5 class="modal-title text-white">Adicionar Jogador</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
<div class="modal-body">
<div class="mb-3"><label class="form-label text-light-gray">Nome</label>
<input type="text" class="form-control bg-dark text-white border-orange" id="addPlayerName" placeholder="Nome completo do jogador"></div>
<div class="row">
<div class="col-md-6 mb-3"><label class="form-label text-light-gray">Posição</label>
<select class="form-select bg-dark text-white border-orange" id="addPlayerPosition">
<option value="PG">PG</option>
<option value="SG">SG</option>
<option value="SF">SF</option>
<option value="PF">PF</option>
<option value="C">C</option>
</select></div>
<div class="col-md-6 mb-3"><label class="form-label text-light-gray">Pos. Secundária</label>
<select class="form-select bg-dark text-white border-orange" id="addPlayerSecondaryPosition">
<option value="">Nenhuma</option>
<option value="PG">PG</option>
<option value="SG">SG</option>
<option value="SF">SF</option>
<option value="PF">PF</option>
<option value="C">C</option>
</select></div>
</div>
<div class="row">
<div class="col-md-6 mb-3"><label class="form-label text-light-gray">Idade</label>
<input type="number" class="form-control bg-dark text-white border-orange" id="addPlayerAge" value="25" min="18" max="45"></div>
<div class="col-md-6 mb-3"><label class="form-label text-light-gray">OVR</label>
<input type="number" class="form-control bg-dark text-white border-orange" id="addPlayerOvr" value="70" min="0" max="99"></div>
</div>
<div class="mb-3"><label class="form-label text-light-gray">Papel</label>
<select class="form-select bg-dark text-white border-orange" id="addPlayerRole">
<option value="Titular">Titular</option>
<option value="Banco" selected>Banco</option>
<option value="Outro">Outro</option>
<option value="G-League">G-League</option></select></div>
<div class="mb-3 p-3 rounded" style="background:rgba(59,130,246,.08);border:1px solid rgba(59,130,246,.25)">
<div class="form-check form-switch">
<input class="form-check-input" type="checkbox" role="switch" id="addPlayerLoyal">
<label class="form-check-label" for="addPlayerLoyal" style="color:#3b82f6;font-weight:600">🤝 Leal</label></div>
<small class="text-light-gray d-block mt-1">Jogador cadastrado direto não passa por draft, então a lealdade não tem como ser calculada — marque aqui se ele deve contar como leal.</small></div>
</div>
<div class="modal-footer border-orange"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
<button type="button" class="btn btn-orange" onclick="saveNewPlayer(${teamId})">Adicionar</button></div></div></div>`;

  document.body.appendChild(modal);
  new bootstrap.Modal(modal).show();
  modal.addEventListener('hidden.bs.modal', () => modal.remove());
}

async function saveNewPlayer(teamId) {
  const data = {
    team_id: teamId,
    name: document.getElementById('addPlayerName').value.trim(),
    position: document.getElementById('addPlayerPosition').value,
    secondary_position: document.getElementById('addPlayerSecondaryPosition').value || null,
    age: parseInt(document.getElementById('addPlayerAge').value),
    ovr: parseInt(document.getElementById('addPlayerOvr').value),
    role: document.getElementById('addPlayerRole').value,
    loyal_override: document.getElementById('addPlayerLoyal')?.checked ? 1 : 0
  };
  
  if (!data.name || !data.position) {
    alert('Nome e posição são obrigatórios!');
    return;
  }
  
  try {
    await api('admin.php?action=player', { method: 'POST', body: JSON.stringify(data) });
    const modalEl = document.getElementById('addPlayerModal');
    if (modalEl) bootstrap.Modal.getInstance(modalEl)?.hide();
    await showTeam(teamId);
    alert('Jogador adicionado!');
  } catch (e) {
    alert('Erro ao adicionar jogador: ' + (e.error || 'Desconhecido'));
  }
}

function addPick(teamId) {
  const modal = document.createElement('div');
  modal.className = 'modal fade';
  modal.innerHTML = `<div class="modal-dialog"><div class="modal-content bg-dark-panel"><div class="modal-header border-orange">
<h5 class="modal-title text-white">Adicionar Pick</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
<div class="modal-body">
<div class="mb-3"><label class="form-label text-light-gray">Temporada</label>
<input type="number" class="form-control bg-dark text-white border-orange" id="addPickYear" value="${new Date().getFullYear()}" min="2025"></div>
<div class="mb-3"><label class="form-label text-light-gray">Rodada</label>
<select class="form-select bg-dark text-white border-orange" id="addPickRound">
<option value="1">1ª Rodada</option>
<option value="2">2ª Rodada</option></select></div>
<div class="mb-3"><label class="form-label text-light-gray">Time Original</label>
<select class="form-select bg-dark text-white border-orange" id="addPickOriginalTeam">
<option value="">Carregando...</option></select></div>
<div class="mb-3"><label class="form-label text-light-gray">Observações (opcional)</label>
<textarea class="form-control bg-dark text-white border-orange" id="addPickNotes" rows="2" placeholder="Informações adicionais sobre este pick"></textarea></div>
</div>
<div class="modal-footer border-orange"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
<button type="button" class="btn btn-orange" onclick="saveNewPick(${teamId})">Adicionar</button></div></div></div>`;
  
  document.body.appendChild(modal);
  
  // Carregar times para seleção
  api('admin.php?action=teams').then(data => {
    const select = modal.querySelector('#addPickOriginalTeam');
    select.innerHTML = '<option value="">Selecione o time original</option>';
    data.teams.forEach(t => {
      const opt = document.createElement('option');
      opt.value = t.id;
      opt.textContent = `${t.city} ${t.name} (${t.league})`;
      if (t.id == teamId) opt.selected = true;
      select.appendChild(opt);
    });
  });
  
  new bootstrap.Modal(modal).show();
  modal.addEventListener('hidden.bs.modal', () => modal.remove());
}

async function saveNewPick(teamId) {
  const data = {
    team_id: teamId,
    original_team_id: parseInt(document.getElementById('addPickOriginalTeam').value),
    season_year: parseInt(document.getElementById('addPickYear').value),
    round: document.getElementById('addPickRound').value,
    notes: document.getElementById('addPickNotes').value.trim() || null
  };
  
  if (!data.original_team_id) {
    alert('Selecione o time original!');
    return;
  }
  
  try {
    await api('admin.php?action=pick', { method: 'POST', body: JSON.stringify(data) });
    bootstrap.Modal.getInstance(document.querySelector('.modal')).hide();
    await showTeam(teamId);
    alert('Pick adicionado!');
  } catch (e) { 
    alert('Erro ao adicionar pick: ' + (e.error || 'Desconhecido')); 
  }
}

function editPick(pickId) {
  const p = appState.teamDetails.picks.find(pk => pk.id == pickId);
  if (!p) return;

  const modal = document.createElement('div');
  modal.className = 'modal fade';
  modal.innerHTML = `<div class="modal-dialog"><div class="modal-content bg-dark-panel"><div class="modal-header border-orange">
<h5 class="modal-title text-white">Editar Pick</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
<div class="modal-body">
<div class="mb-3"><label class="form-label text-light-gray">Temporada</label>
<input type="number" class="form-control bg-dark text-white border-orange" id="editPickYear" value="${p.season_year}" min="2025"></div>
<div class="mb-3"><label class="form-label text-light-gray">Rodada</label>
<select class="form-select bg-dark text-white border-orange" id="editPickRound">
<option value="1" ${p.round == 1 ? 'selected' : ''}>1ª Rodada</option>
<option value="2" ${p.round == 2 ? 'selected' : ''}>2ª Rodada</option></select></div>
<div class="mb-3"><label class="form-label text-light-gray">Time Original (da pick)</label>
<select class="form-select bg-dark text-white border-orange" id="editPickOriginalTeam">
<option value="">Carregando...</option></select></div>
<div class="mb-3"><label class="form-label text-light-gray">Dono atual — mover pick</label>
<select class="form-select bg-dark text-white border-orange" id="editPickOwnerTeam">
<option value="">Carregando...</option></select></div>
<div class="mb-3"><label class="form-label text-light-gray">Tipo de Swap</label>
<select class="form-select bg-dark text-white border-orange" id="editPickSwapType">
<option value="" ${!p.swap_type ? 'selected' : ''}>Nenhum</option>
<option value="SW" ${p.swap_type === 'SW' ? 'selected' : ''}>SW — Worst</option>
<option value="SB" ${p.swap_type === 'SB' ? 'selected' : ''}>SB — Best</option>
</select></div>
<div class="mb-3"><label class="form-label text-light-gray">Observações (opcional)</label>
<textarea class="form-control bg-dark text-white border-orange" id="editPickNotes" rows="2">${p.notes || ''}</textarea></div>
</div>
<div class="modal-footer border-orange"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
<button type="button" class="btn btn-orange" onclick="savePickEdit(${pickId})">Salvar</button></div></div></div>`;

  document.body.appendChild(modal);

  api('admin.php?action=teams').then(data => {
    const origSelect = modal.querySelector('#editPickOriginalTeam');
    const ownerSelect = modal.querySelector('#editPickOwnerTeam');
    origSelect.innerHTML = '';
    ownerSelect.innerHTML = '';
    data.teams.forEach(t => {
      const opt1 = document.createElement('option');
      opt1.value = t.id;
      opt1.textContent = `${t.city} ${t.name} (${t.league})`;
      if (t.id == p.original_team_id) opt1.selected = true;
      origSelect.appendChild(opt1);

      const opt2 = opt1.cloneNode(true);
      if (t.id == p.team_id) opt2.selected = true;
      ownerSelect.appendChild(opt2);
    });
  });

  new bootstrap.Modal(modal).show();
  modal.addEventListener('hidden.bs.modal', () => modal.remove());
}

async function savePickEdit(pickId) {
  const ownerTeamId = parseInt(document.getElementById('editPickOwnerTeam')?.value || 0);
  const swapType = document.getElementById('editPickSwapType')?.value || null;
  const data = {
    pick_id: pickId,
    team_id: ownerTeamId || appState.currentTeam.id,
    original_team_id: parseInt(document.getElementById('editPickOriginalTeam').value),
    season_year: parseInt(document.getElementById('editPickYear').value),
    round: document.getElementById('editPickRound').value,
    swap_type: swapType || null,
    notes: document.getElementById('editPickNotes').value.trim() || null
  };

  if (!data.original_team_id) {
    alert('Selecione o time original!');
    return;
  }

  try {
    await api('admin.php?action=pick', { method: 'PUT', body: JSON.stringify(data) });
    bootstrap.Modal.getInstance(document.querySelector('.modal.show'))?.hide();
    await showTeam(appState.currentTeam.id);
    showAlert('success', 'Pick atualizado!');
  } catch (e) {
    alert('Erro ao atualizar pick: ' + (e.error || 'Desconhecido'));
  }
}

async function deletePick(pickId) {
  if (!await confirmarSite('Deletar este pick?')) return;
  try {
    await api(`admin.php?action=pick&id=${pickId}`, { method: 'DELETE' });
    await showTeam(appState.currentTeam.id);
    showAlert('success', 'Pick deletado!');
  } catch (e) { alert('Erro ao deletar pick!'); }
}

function quickSwapType(pickId) {
  const p = appState.teamDetails.picks.find(pk => pk.id == pickId);
  if (!p) return;
  const modal = document.createElement('div');
  modal.className = 'modal fade';
  modal.innerHTML = `<div class="modal-dialog modal-sm"><div class="modal-content bg-dark-panel"><div class="modal-header border-orange">
<h5 class="modal-title text-white" style="font-size:14px">Swap — ${p.season_year} R${p.round}</h5>
<button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
<div class="modal-body">
<div class="d-flex flex-column gap-2">
<button type="button" class="btn ${!p.swap_type ? 'btn-orange' : 'btn-secondary'}" onclick="applySwapType(${pickId}, '')">Nenhum</button>
<button type="button" class="btn ${p.swap_type === 'SW' ? 'btn-orange' : 'btn-outline-light'}" onclick="applySwapType(${pickId}, 'SW')">SW — Worst</button>
<button type="button" class="btn ${p.swap_type === 'SB' ? 'btn-orange' : 'btn-outline-light'}" onclick="applySwapType(${pickId}, 'SB')">SB — Best</button>
</div></div></div></div>`;
  document.body.appendChild(modal);
  new bootstrap.Modal(modal).show();
  modal.addEventListener('hidden.bs.modal', () => modal.remove());
}

async function applySwapType(pickId, swapType) {
  const p = appState.teamDetails.picks.find(pk => pk.id == pickId);
  if (!p) return;
  try {
    await api('admin.php?action=pick', { method: 'PUT', body: JSON.stringify({
      pick_id: pickId,
      team_id: p.team_id,
      original_team_id: p.original_team_id,
      season_year: p.season_year,
      round: p.round,
      swap_type: swapType || null,
      notes: p.notes || null
    })});
    const openModal = document.querySelector('.modal.show');
    if (openModal) bootstrap.Modal.getInstance(openModal)?.hide();
    await showTeam(appState.currentTeam.id);
    showAlert('success', swapType ? `Tipo definido como ${swapType}` : 'Swap type removido');
  } catch (e) {
    alert('Erro: ' + (e.error || 'Desconhecido'));
  }
}

function movePick(pickId) {
  const p = appState.teamDetails.picks.find(pk => pk.id == pickId);
  if (!p) return;
  const modal = document.createElement('div');
  modal.className = 'modal fade';
  modal.innerHTML = `<div class="modal-dialog"><div class="modal-content bg-dark-panel"><div class="modal-header border-orange">
<h5 class="modal-title text-white">Mover Pick — ${p.season_year} R${p.round}</h5>
<button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
<div class="modal-body">
<p style="font-size:13px;color:var(--text-2);margin-bottom:12px">Pick original: <strong>${escapeHtml(p.city || '')} ${escapeHtml(p.team_name || '')}</strong></p>
<div class="mb-3"><label class="form-label text-light-gray">Mover para o time</label>
<select class="form-select bg-dark text-white border-orange" id="movePickDestTeam">
<option value="">Carregando...</option></select></div>
</div>
<div class="modal-footer border-orange">
<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
<button type="button" class="btn btn-orange" onclick="applyMovePick(${pickId})">Mover</button>
</div></div></div>`;
  document.body.appendChild(modal);
  api('admin.php?action=teams').then(data => {
    const select = modal.querySelector('#movePickDestTeam');
    select.innerHTML = '';
    const currentLeague = ((appState.currentTeam?.league) || appState.currentLeague || '').toUpperCase();
    const teams = currentLeague
      ? data.teams.filter(t => (t.league || '').toUpperCase() === currentLeague)
      : data.teams;
    teams.forEach(t => {
      const opt = document.createElement('option');
      opt.value = t.id;
      opt.textContent = `${t.city} ${t.name}`;
      if (t.id == p.team_id) opt.selected = true;
      select.appendChild(opt);
    });
  });
  new bootstrap.Modal(modal).show();
  modal.addEventListener('hidden.bs.modal', () => modal.remove());
}

async function applyMovePick(pickId) {
  const p = appState.teamDetails.picks.find(pk => pk.id == pickId);
  if (!p) return;
  const destTeamId = parseInt(document.getElementById('movePickDestTeam').value);
  if (!destTeamId) { alert('Selecione o time destino!'); return; }
  try {
    await api('admin.php?action=pick', { method: 'PUT', body: JSON.stringify({
      pick_id: pickId,
      team_id: destTeamId,
      original_team_id: p.original_team_id,
      season_year: p.season_year,
      round: p.round,
      swap_type: p.swap_type || null,
      notes: p.notes || null
    })});
    const openModal = document.querySelector('.modal.show');
    if (openModal) bootstrap.Modal.getInstance(openModal)?.hide();
    await showTeam(appState.currentTeam.id);
    showAlert('success', 'Pick movida com sucesso!');
  } catch (e) {
    alert('Erro ao mover pick: ' + (e.error || 'Desconhecido'));
  }
}

// Função para upload de edital
async function uploadEdital(league) {
  const fileInput = document.getElementById(`edital_file_${league}`);
  const file = fileInput.files[0];
  
  if (!file) {
    alert('Selecione um arquivo primeiro!');
    return;
  }
  
  // Validação de tipo de arquivo
  const allowedTypes = ['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
  if (!allowedTypes.includes(file.type)) {
    alert('Apenas arquivos PDF ou Word são permitidos!');
    return;
  }
  
  // Validação de tamanho (10MB)
  if (file.size > 10 * 1024 * 1024) {
    alert('Arquivo muito grande! Máximo: 10MB');
    return;
  }
  
  const formData = new FormData();
  formData.append('file', file);
  formData.append('league', league);
  
  try {
    const response = await fetch('api/edital.php?action=upload_edital', {
      method: 'POST',
      body: formData
    });
    
    const result = await response.json();
    
    if (result.success) {
      alert('Edital enviado com sucesso!');
      showConfig(); // Recarrega para mostrar o arquivo
    } else {
      alert('Erro: ' + (result.error || 'Falha no upload'));
    }
  } catch (e) {
    alert('Erro ao enviar arquivo: ' + e.message);
  }
}

// Função para deletar edital
async function deleteEdital(league) {
  if (!await confirmarSite('Tem certeza que deseja remover o edital desta liga?')) return;
  
  try {
    const response = await fetch('api/edital.php?action=delete_edital', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ league })
    });
    
    const result = await response.json();
    
    if (result.success) {
      alert('Edital removido!');
      showConfig(); // Recarrega
    } else {
      alert('Erro: ' + (result.error || 'Falha ao remover'));
    }
  } catch (e) {
    alert('Erro ao remover arquivo: ' + e.message);
  }
}

document.addEventListener('DOMContentLoaded', init);

// ========== TÁTICA (janela de edição + visão ao vivo) ==========
function formatDirectiveTimestampAdmin(value) {
  if (!value) return '-';
  try {
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return '-';
    return date.toLocaleString('pt-BR');
  } catch (e) {
    return '-';
  }
}

async function showTaticaAdmin() {
  appState.view = 'tatica-admin';
  updateBreadcrumb();

  const league = appState.currentLeague || _leagues[0] || 'ELITE';
  const container = document.getElementById('mainContainer');
  container.innerHTML = '<div class="text-center py-5"><div class="spinner-border text-orange"></div></div>';

  try {
    const [winRes, overviewRes] = await Promise.all([
      api(`tactics.php?action=admin_window&league=${encodeURIComponent(league)}`),
      api(`tactics.php?action=admin_overview&league=${encodeURIComponent(league)}`),
    ]);
    renderTaticaAdmin(league, winRes.window, overviewRes.teams || [], overviewRes.modelos || null,
                      !!overviewRes.fase_offs);
  } catch (e) {
    container.innerHTML = `<div class="alert alert-danger">Erro ao carregar tática: ${escapeHtml(e.error || e.message || 'Desconhecido')}</div>`;
  }
}

/**
 * O retrato dos modelos técnicos da liga.
 *
 * Só os DADOS: quem definiu, com o quê, e quantas das oito vagas gastou.
 * O que fazer com quem não definiu é decisão do admin — o sistema não pune
 * ninguém sozinho.
 */
function _taticaPainelModelos(modelos) {
  if (!modelos || !modelos.times || !modelos.times.length) return '';
  const semModelo = modelos.times.filter(t => !t.modelo);
  const comModelo = modelos.times.filter(t => t.modelo);

  const linha = (t) => `
    <div class="mtd-linha${t.modelo ? '' : ' sem'}">
      ${t.foto
        ? `<img class="mtd-foto" src="${t.foto}" alt="">`
        : '<span class="mtd-foto mtd-vazia"><i class="bi bi-dash"></i></span>'}
      <span class="mtd-time">${escapeHtml(t.nome)}</span>
      <span class="mtd-modelo">${t.modelo ? escapeHtml(t.modelo) : 'não definiu'}</span>
      ${t.sistema ? `<span class="mtd-sis">${escapeHtml(t.sistema)}</span>` : ''}
      <span class="mtd-uso" title="Vagas usadas das ${modelos.limite}">${t.usados}/${modelos.limite}</span>
    </div>`;

  return `
    <div class="panel mb-3">
      <div class="panel-header">
        <div class="panel-title"><i class="bi bi-person-badge" style="color:var(--red)"></i> Modelos técnicos</div>
        <span style="font-size:12px;color:var(--text-3)">
          ${comModelo.length} de ${modelos.times.length} definiram${semModelo.length ? ` · ${semModelo.length} sem modelo` : ''}
        </span>
      </div>
      <div class="panel-body">
        ${semModelo.length ? `
          <div class="mtd-aviso">
            <i class="bi bi-info-circle"></i>
            <span><strong>${semModelo.length}</strong> ${semModelo.length === 1 ? 'time ainda não definiu' : 'times ainda não definiram'} o modelo técnico:
            ${semModelo.map(t => escapeHtml(t.nome)).join(', ')}.</span>
          </div>` : ''}
        <div class="mtd-lista">${modelos.times.map(linha).join('')}</div>
      </div>
    </div>

    <style>
      .mtd-lista{display:flex;flex-direction:column;gap:4px}
      .mtd-linha{display:flex;align-items:center;gap:10px;padding:7px 10px;border-radius:8px;
        background:var(--panel-2);border:1px solid var(--border);font-size:13px}
      .mtd-linha.sem{border-color:rgba(239,68,68,.3);background:rgba(239,68,68,.05)}
      .mtd-foto{width:30px;height:30px;flex:none;border-radius:7px;object-fit:cover;
        object-position:center 22%;background:var(--panel-3)}
      .mtd-vazia{display:flex;align-items:center;justify-content:center;color:var(--text-3);font-size:14px}
      .mtd-time{flex:1;min-width:120px;font-weight:600;color:var(--text);
        white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
      .mtd-modelo{color:var(--text-2);font-size:12.5px;white-space:nowrap}
      .mtd-linha.sem .mtd-modelo{color:#ef4444;font-weight:600}
      .mtd-sis{font-size:10px;color:var(--text-3);text-transform:uppercase;
        letter-spacing:.04em;white-space:nowrap}
      .mtd-uso{font-size:12px;font-weight:700;color:var(--text-2);
        font-variant-numeric:tabular-nums;flex:none;margin-left:auto}
      .mtd-aviso{display:flex;gap:8px;align-items:flex-start;margin-bottom:10px;padding:9px 12px;
        border-radius:8px;font-size:12.5px;line-height:1.5;
        background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.25);color:var(--text-2)}
      @media (max-width:640px){
        .mtd-sis{display:none}
        .mtd-linha{flex-wrap:wrap}
      }
    </style>`;
}

function renderTaticaAdmin(league, win, teams, modelos, faseOffs) {
  const container = document.getElementById('mainContainer');

  // Acordeão: um time por linha, abre e mostra a tática exata dele. O que o
  // time mexeu sai em vermelho — por isso o snapshot existe. Na fase de
  // playoffs a comparação é contra o fim da regular, então o vermelho passa a
  // significar "mudou a tática PRA a série".
  const rows = teams.map(t => {
    const at = t.active_tactic;
    const tid = t.team.id;
    // Eliminado: some do caminho. Fica no fim da lista (a API já ordena) e em
    // cinza, porque com a série pra começar a tática dele não é trabalho.
    const fora = faseOffs && t.nos_offs === false;
    const clsFora = fora ? ' tac-fora' : '';
    const selo = (faseOffs && t.nos_offs)
      ? `<span class="tac-seed" title="Entrou nos playoffs como ${t.seed}º da conferência">${t.seed}º</span>`
      : '';

    if (!at) {
      return `
        <div class="tac-item${clsFora}">
          <div class="tac-head" style="cursor:default">
            <i class="bi bi-dash-circle" style="color:var(--text-3)"></i>
            ${selo}
            <span class="tac-nome">${escapeHtml(t.team.name)}</span>
            <span class="tac-vazio">Nenhuma tática ativa</span>
          </div>
        </div>`;
    }

    const marcado = at.feito_no_jogo;
    /* SÓ CONTA O QUE O CARD MOSTRA.
       O badge somava também os titulares alterados, e o card nunca desenhou
       titular nenhum: o Coyotes aparecia com "2 mudanças" e um único campo
       aceso, e não havia onde procurar a outra. Agora o número bate com os
       campos em vermelho logo abaixo — ainda mais depois que o quinteto saiu
       da tela do GM e deixou de ser uma escolha de alguém. */
    const mudancas = (at.config || []).filter(x => x.mudou).length;

    // O nome em vermelho é o aviso de "este mexeu na tática pros playoffs".
    // Só vale pra quem está nos offs: eliminado mexendo na tática não muda
    // nada, e pintar o nome dele só tiraria a atenção de quem importa.
    const mudouNoOffs = faseOffs && t.nos_offs && mudancas > 0;


    const camposConfig = (at.config || []).filter(c => c.valor !== null);
    const observacao = camposConfig.find(c => c.campo === 'notes') || null;
    const config = camposConfig.filter(c => c.campo !== 'notes').map(c => `
      <div class="tac-campo ${c.mudou ? 'mudou' : ''}">
        <span class="tac-campo-rotulo">${escapeHtml(c.rotulo)}</span>
        <span class="tac-campo-valor">${escapeHtml(String(c.valor))}</span>
      </div>`).join('') || '<div class="tac-vazio">Nenhuma configuração preenchida.</div>';
    const observacaoHtml = observacao ? `
      <div class="tac-secao">Observações</div>
      <div class="tac-obs ${observacao.mudou ? 'mudou' : ''}">${escapeHtml(String(observacao.valor))}</div>` : '';

    return `
      <div class="tac-item${clsFora}">
        <div class="tac-head" onclick="_tacToggle(${tid})">
          <i class="bi bi-chevron-right tac-seta" id="tac-seta-${tid}"></i>
          ${selo}
          <span class="tac-nome${mudouNoOffs ? ' mudou-offs' : ''}">${escapeHtml(t.team.name)}</span>
          <span class="pun-badge" style="background:#14b8a620;color:#14b8a6;border-color:#14b8a640">${escapeHtml(at.slot_label)}</span>
          ${mudancas > 0 ? `<span class="tac-mudou-badge">${mudancas} ${mudancas === 1 ? 'mudança' : 'mudanças'}</span>` : ''}
          <span class="tac-data">${at.updated_at ? formatDirectiveTimestampAdmin(at.updated_at) : '—'}</span>
          <label class="tac-feito" onclick="event.stopPropagation()">
            <input type="checkbox" ${marcado ? 'checked' : ''} onchange="_tacFeito(${tid}, this.checked, this)">
            <span>Feito no jogo</span>
          </label>
        </div>
        <div class="tac-corpo" id="tac-corpo-${tid}">
          ${!at.tem_snapshot ? `
          <div class="tac-aviso">
            <i class="bi bi-info-circle"></i>
            ${faseOffs
              ? 'Esta tática não existia quando a temporada regular fechou — não há com o que comparar, então nada aparece em vermelho.'
              : 'Ainda não houve virada de temporada com esta tática — nada a comparar, então nada aparece em vermelho.'}
          </div>` : ''}
          ${(at.gleague || []).length ? `
            <div class="tac-secao">G-League</div>
            <div class="tac-jogadores">${at.gleague.map(n => `<span class="tac-jog">${escapeHtml(n)}</span>`).join('')}</div>` : ''}
          <div class="tac-secao">Configurações</div>
          <div class="tac-campos">${config}</div>
          ${observacaoHtml}
        </div>
      </div>`;
  }).join('');

  const _dirBack = appState.currentLeague ? `showLeague('${appState.currentLeague}')` : 'showHome()';

  container.innerHTML = `
    <div class="mb-4">
      <button class="btn btn-back" onclick="${_dirBack}"><i class="bi bi-arrow-left"></i> Voltar</button>
    </div>

    <div class="panel">
      <div class="panel-header">
        <div class="panel-title"><i class="bi bi-broadcast"></i> Tática de cada time</div>
        ${faseOffs ? `<span class="tac-fase"><i class="bi bi-trophy-fill"></i> Playoffs</span>` : ''}
      </div>
      ${faseOffs ? `
      <div class="tac-aviso-offs">
        <i class="bi bi-trophy-fill"></i>
        <span>A classificação já foi salva, então a liga está nos <b>playoffs</b>: os
        <b>${teams.filter(t => t.nos_offs).length} classificados</b> vêm primeiro, com a seed ao lado do nome, e os
        eliminados ficam no fim, em cinza. O <b style="color:#ef4444">nome em vermelho</b> é quem mudou a tática
        <b>depois</b> do fim da temporada regular — ou seja, montou tática pros playoffs.
        Ao avançar a temporada, tudo volta a ficar igual.</span>
      </div>` : ''}
      <div style="font-size:11.5px;color:var(--text-3);margin-bottom:12px">
        Abra um time pra ver a tática exata dele. Em <span style="color:#ef4444;font-weight:700">vermelho</span>,
        o que o time mexeu desde ${faseOffs ? 'o fim da temporada regular' : 'a virada da temporada'}.
        O "Feito no jogo" zera sozinho a cada temporada nova.
      </div>
      <div class="tac-lista">${rows || '<div class="tac-vazio">Nenhum time nesta liga.</div>'}</div>
    </div>

    ${_taticaPainelModelos(modelos)}

    <style>
      .tac-lista { display:flex; flex-direction:column; gap:7px; }
      .tac-item { border:1px solid var(--border); border-radius:10px; background:var(--panel-2); overflow:hidden; }
      .tac-head { display:flex; align-items:center; gap:10px; padding:11px 14px; cursor:pointer; flex-wrap:wrap; }
      .tac-head:hover { background:var(--panel-3); }
      .tac-seta { color:var(--text-3); font-size:12px; transition:transform .18s; flex-shrink:0; }
      .tac-seta.aberto { transform:rotate(90deg); }
      .tac-nome { font-size:13.5px; font-weight:700; flex:1; min-width:140px; }
      /* Mexeu na tática depois do fim da regular: montou pros playoffs. */
      .tac-nome.mudou-offs { color:#ef4444; }
      .tac-seed { flex:none; min-width:26px; text-align:center; font-size:10.5px; font-weight:800;
        padding:2px 6px; border-radius:6px; font-variant-numeric:tabular-nums;
        background:rgba(245,158,11,.14); color:#f59e0b; border:1px solid rgba(245,158,11,.3); }
      /* Eliminado: continua clicável, mas sai da frente de quem ainda joga. */
      .tac-item.tac-fora { opacity:.45; }
      .tac-item.tac-fora .tac-nome { color:var(--text-3); font-weight:600; }
      .tac-item.tac-fora:hover { opacity:.75; }
      .tac-fase { font-size:10.5px; font-weight:800; letter-spacing:.6px; text-transform:uppercase;
        padding:3px 10px; border-radius:999px; display:inline-flex; align-items:center; gap:5px;
        background:rgba(245,158,11,.14); color:#f59e0b; border:1px solid rgba(245,158,11,.3); }
      .tac-aviso-offs { display:flex; gap:8px; align-items:flex-start; margin-bottom:12px;
        padding:9px 12px; border-radius:8px; font-size:12px; line-height:1.55; color:var(--text-2);
        background:rgba(245,158,11,.07); border:1px solid rgba(245,158,11,.22); }
      .tac-aviso-offs i { color:#f59e0b; margin-top:2px; }
      .tac-data { font-size:11px; color:var(--text-3); }
      .tac-mudou-badge { font-size:10px; font-weight:700; padding:1px 8px; border-radius:999px;
        background:rgba(239,68,68,.12); color:#ef4444; border:1px solid rgba(239,68,68,.3); }
      .tac-feito { display:flex; align-items:center; gap:6px; font-size:11.5px; font-weight:600;
        color:var(--text-2); cursor:pointer; margin:0; white-space:nowrap; }
      .tac-corpo { display:none; padding:4px 14px 14px; border-top:1px solid var(--border); }
      .tac-corpo.aberto { display:block; }
      .tac-secao { font-size:10px; font-weight:800; letter-spacing:.9px; text-transform:uppercase;
        color:var(--text-3); margin:14px 0 7px; }
      .tac-jogadores { display:flex; flex-wrap:wrap; gap:6px; }
      .tac-jog { font-size:12px; font-weight:600; padding:4px 10px; border-radius:8px;
        background:var(--panel-3); border:1px solid var(--border); }
      .tac-jog.mudou { border-color:rgba(239,68,68,.45); color:#ef4444; background:rgba(239,68,68,.08); }
      .tac-campos { display:grid; grid-template-columns:repeat(auto-fit,minmax(210px,1fr)); gap:7px; }
      .tac-campo { display:flex; align-items:center; justify-content:space-between; gap:10px;
        padding:7px 11px; border-radius:8px; background:var(--panel-3); border:1px solid var(--border); }
      .tac-campo.mudou { border-color:rgba(239,68,68,.45); background:rgba(239,68,68,.08); }
      .tac-campo-rotulo { font-size:11px; color:var(--text-3); }
      .tac-campo.mudou .tac-campo-rotulo { color:#ef4444; }
      .tac-campo-valor { font-size:12px; font-weight:700; text-align:right; }
      .tac-campo.mudou .tac-campo-valor { color:#ef4444; }
      .tac-obs { font-size:12.5px; line-height:1.55; color:var(--text); white-space:pre-wrap;
        word-break:break-word; background:var(--panel-3); border:1px solid var(--border);
        border-radius:8px; padding:10px 12px; min-height:52px; }
      .tac-obs.mudou { border-color:rgba(239,68,68,.45); background:rgba(239,68,68,.08); color:#ef4444; }
      .tac-vazio { font-size:12px; color:var(--text-3); }
      .tac-aviso { font-size:11.5px; color:var(--text-3); background:var(--panel-3);
        border:1px solid var(--border); border-radius:8px; padding:8px 12px; margin-top:12px; }
    </style>
  `;
}

function _tacToggle(teamId) {
  document.getElementById(`tac-corpo-${teamId}`)?.classList.toggle('aberto');
  document.getElementById(`tac-seta-${teamId}`)?.classList.toggle('aberto');
}

async function _tacFeito(teamId, feito, el) {
  el.disabled = true;
  try {
    await api('tactics.php', {
      method: 'POST',
      body: JSON.stringify({ action: 'admin_feito_no_jogo', team_id: teamId, feito }),
    });
  } catch (e) {
    el.checked = !feito;
    showAlert('danger', e.error || e.message || 'Não consegui salvar.');
  } finally {
    el.disabled = false;
  }
}


// ========== FREE AGENCY ADMIN ==========

async function showPunicoes() {
  appState.view = 'punicoes';
  updateBreadcrumb();

  const league = appState.currentLeague || _leagues[0] || 'ELITE';
  const container = document.getElementById('mainContainer');
  container.innerHTML = `
    <div class="mb-4 d-flex align-items-center justify-content-between flex-wrap gap-2">
      <button class="btn btn-back" onclick="showLeague('${league}')"><i class="bi bi-arrow-left"></i> Voltar</button>
      <button class="btn btn-outline-danger btn-sm" onclick="zerarPunicoesEAvisos('${league}')">
        <i class="bi bi-arrow-counterclockwise me-1"></i>Zerar punições e avisos da ${league}
      </button>
    </div>

    <div class="row g-4">
      <div class="col-lg-4">

        <div class="panel">
          <div class="panel-header">
            <div class="panel-title" style="margin-bottom:0"><i class="bi bi-plus-circle-fill"></i> Nova punição</div>
          </div>
          <div class="d-flex flex-column gap-3 mt-1">
            <div>
              <div class="pun-field-label">Motivo</div>
              <select id="punicaoMotive" class="form-select"></select>
            </div>
            <input type="hidden" id="punicaoLeague" value="${league}">
            <div>
              <div class="pun-field-label">Time</div>
              <select id="punicaoTeam" class="form-select"></select>
            </div>
            <div>
              <div class="pun-field-label">Consequência</div>
              <select id="punicaoType" class="form-select"></select>
            </div>
            <div id="punicaoPickRow" style="display:none">
              <div class="pun-field-label">Pick específica</div>
              <select id="punicaoPick" class="form-select"></select>
            </div>
            <div id="punicaoScopeRow" style="display:none">
              <div class="pun-field-label">Temporada</div>
              <select id="punicaoScope" class="form-select">
                <option value="current">Temporada atual</option>
                <option value="next">Próxima temporada</option>
              </select>
            </div>
            <div>
              <div class="pun-field-label">Observações</div>
              <textarea id="punicaoNotes" class="form-control" rows="3" placeholder="Detalhes ou contexto..."></textarea>
            </div>
            <div>
              <div class="pun-field-label">Data da punição</div>
              <input type="datetime-local" id="punicaoDate" class="form-control">
            </div>
            <button id="punicaoSubmit" class="btn-orange" style="width:100%;justify-content:center;padding:10px">
              <i class="bi bi-check2-circle"></i> Registrar punição
            </button>
          </div>
        </div>

        <div class="panel">
          <div class="panel-header">
            <div class="panel-title" style="margin-bottom:0"><i class="bi bi-tag-fill"></i> Cadastrar motivo</div>
          </div>
          <div class="d-flex flex-column gap-3 mt-1">
            <div>
              <div class="pun-field-label">Novo motivo</div>
              <input type="text" id="newMotiveLabel" class="form-control" placeholder="Ex: Diretrizes erradas">
            </div>
            <button class="btn-ghost" style="width:100%;justify-content:center" id="newMotiveBtn">
              <i class="bi bi-plus-circle"></i> Salvar motivo
            </button>
          </div>
        </div>

        <div class="panel">
          <div class="panel-header">
            <div class="panel-title" style="margin-bottom:0"><i class="bi bi-lightning-fill"></i> Cadastrar consequência</div>
          </div>
          <div class="d-flex flex-column gap-3 mt-1">
            <div>
              <div class="pun-field-label">Nova consequência</div>
              <input type="text" id="newPunishmentLabel" class="form-control" placeholder="Ex: Perda de pick específica">
            </div>
            <button class="btn-ghost" style="width:100%;justify-content:center" id="newPunishmentBtn">
              <i class="bi bi-plus-circle"></i> Salvar consequência
            </button>
          </div>
        </div>

      </div>

      <div class="col-lg-8">
        <div class="panel">
          <div class="panel-header">
            <div class="panel-title" style="margin-bottom:0"><i class="bi bi-clock-history"></i> Histórico de punições — ${league}</div>
            <div class="admin-sel">
              <label>Time</label>
              <select id="punicaoHistoryTeam"><option value="">Todos os times</option></select>
            </div>
          </div>
          <input type="hidden" id="punicaoHistoryLeague" value="${league}">
          <div id="punicoesList">
            <p class="empty-state">Selecione uma liga ou time para ver as punições.</p>
          </div>
        </div>
      </div>
    </div>
  `;

  if (typeof window.initPunicoes === 'function') {
    window.initPunicoes(league);
  }
}

async function showFAAdmin() {
  appState.view = 'faadmin';
  updateBreadcrumb();

  const league = appState.currentLeague || _leagues[0] || 'ELITE';
  const container = document.getElementById('mainContainer');
  const leagueOpts = (_leagues || [league]).map(l =>
    `<option value="${l}" ${l === league ? 'selected' : ''}>${l}</option>`
  ).join('');

  container.innerHTML = `
    <div class="mb-4">
      <button class="btn btn-back" onclick="showLeague('${league}')"><i class="bi bi-arrow-left"></i> Voltar</button>
    </div>

    <div class="panel mb-4">
      <div class="panel-header">
        <div class="panel-title"><i class="bi bi-person-check-fill" style="color:var(--red);margin-right:8px;"></i>Solicitações Free Agency — ${league}</div>
      </div>
      <input type="hidden" id="faNewAdminLeague" value="${league}">
      <div id="faNewAdminRequests"><p class="empty-state">Carregando...</p></div>
    </div>

    <div class="panel">
      <div class="panel-header">
        <div>
          <div class="panel-title"><i class="bi bi-people-fill" style="color:#22c55e;margin-right:8px;"></i>Lances Ganhos por Time</div>
          <div class="panel-sub">Jogadores contratados via Free Agency</div>
        </div>
        <div class="d-flex gap-2 align-items-center flex-wrap">
          <select id="adminFaLeagueFilter" class="form-select form-select-sm" style="width:auto" onchange="loadAdminFaHistory()">
            <option value="">Todas as ligas</option>
            ${leagueOpts}
          </select>
          <select id="adminFaSeasonFilter" class="form-select form-select-sm" style="width:auto" onchange="loadAdminFaHistory()">
            <option value="">Todas as temp.</option>
          </select>
        </div>
      </div>
      <div id="adminFaHistoryContainer"><p class="empty-state">Carregando...</p></div>
    </div>

    <div class="modal fade" id="modalFaChangeTeam" tabindex="-1">
      <div class="modal-dialog modal-sm modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header"><h5 class="modal-title">Mudar Time</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
          <div class="modal-body">
            <input type="hidden" id="faChangeReqId">
            <p id="faChangePlayerName" style="font-weight:600;font-size:14px;margin-bottom:12px"></p>
            <label style="font-size:12px;color:var(--text-2);display:block;margin-bottom:5px">Novo time</label>
            <select id="faChangeNewTeam" class="form-select"></select>
          </div>
          <div class="modal-footer">
            <button class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
            <button class="btn btn-warning btn-sm fw-bold" onclick="adminFaChangeTeamConfirm()"><i class="bi bi-arrow-left-right me-1"></i>Confirmar</button>
          </div>
        </div>
      </div>
    </div>
  `;

  if (typeof carregarSolicitacoesNovaFA === 'function') carregarSolicitacoesNovaFA();
  loadAdminFaHistory();
}

async function loadAdminFaHistory() {
  const container = document.getElementById('adminFaHistoryContainer');
  if (!container) return;
  container.innerHTML = '<p class="empty-state">Carregando...</p>';

  const leagueFil = document.getElementById('adminFaLeagueFilter')?.value || '';
  const seasonFil = document.getElementById('adminFaSeasonFilter')?.value || '';

  try {
    const params = new URLSearchParams({ action: 'admin_fa_history' });
    if (leagueFil) params.set('league', leagueFil);
    if (seasonFil) params.set('season_year', seasonFil);

    const data = await fetch(`/api/free-agency.php?${params}`).then(r => r.json());
    if (!data.success) { container.innerHTML = '<p class="empty-state text-danger">Erro ao carregar.</p>'; return; }

    const seasonSel = document.getElementById('adminFaSeasonFilter');
    if (seasonSel && !seasonSel.dataset.loaded && data.seasons?.length) {
      data.seasons.forEach(y => {
        const o = document.createElement('option');
        o.value = y; o.textContent = y;
        seasonSel.appendChild(o);
      });
      seasonSel.dataset.loaded = '1';
    }

    const rows = data.rows || [];
    if (!rows.length) { container.innerHTML = '<p class="empty-state">Nenhum lance ganho encontrado.</p>'; return; }

    const byTeam = {};
    rows.forEach(r => {
      const key = r.team_full_name?.trim() || '—';
      if (!byTeam[key]) byTeam[key] = [];
      byTeam[key].push(r);
    });

    let html = '';
    Object.entries(byTeam).sort(([a],[b]) => a.localeCompare(b)).forEach(([team, players]) => {
      const tRows = players.map(p => {
        const rid = p.request_id || 0;
        const pn = (p.player_name || '').replace(/'/g, "\\'");
        return `<tr>
          <td><strong>${p.player_name}</strong></td>
          <td>${p.position || ''}${p.secondary_position ? `<span style="color:var(--text-3)">/${p.secondary_position}</span>` : ''}</td>
          <td><strong style="color:var(--red)">${p.ovr}</strong></td>
          <td>${p.age}</td>
          <td><span class="badge bg-secondary">${p.league}</span></td>
          <td>${p.season_year || '—'}</td>
          <td style="white-space:nowrap">
            <button class="btn btn-outline-warning btn-sm py-0 px-2 me-1" title="Mudar time" onclick="openFaChangeTeam(${rid},'${pn}','${p.league||''}')"><i class="bi bi-arrow-left-right"></i></button>
            <button class="btn btn-outline-danger btn-sm py-0 px-2" title="Reverter" onclick="adminFaRevertPlayer(${rid},'${pn}')"><i class="bi bi-arrow-counterclockwise"></i></button>
          </td>
        </tr>`;
      }).join('');

      html += `
        <div class="mb-4">
          <div style="font-size:13px;font-weight:700;color:var(--text);padding:7px 0 6px;border-bottom:1px solid var(--border);margin-bottom:8px;display:flex;align-items:center;gap:8px">
            <i class="bi bi-people-fill" style="color:#22c55e"></i>${team}
            <span style="font-size:11px;font-weight:400;color:var(--text-3);margin-left:auto">${players.length} jogador${players.length !== 1 ? 'es' : ''}</span>
          </div>
          <div style="overflow-x:auto">
            <table class="table table-dark table-sm mb-0" style="font-size:12px">
              <thead><tr><th>Jogador</th><th>Pos</th><th>OVR</th><th>Idade</th><th>Liga</th><th>Temp.</th><th></th></tr></thead>
              <tbody>${tRows}</tbody>
            </table>
          </div>
        </div>`;
    });

    container.innerHTML = html;
  } catch(e) {
    container.innerHTML = '<p class="empty-state text-danger">Erro de conexão.</p>';
  }
}

async function adminFaRevertPlayer(requestId, playerName) {
  if (!requestId) { showAlert('danger', 'ID inválido.'); return; }
  if (!await confirmarSite(`Reverter contratação de "${playerName}"?\nO jogador será removido do time e as moedas devolvidas.`)) return;
  try {
    const r = await fetch('/api/free-agency.php', {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'admin_fa_revert', request_id: requestId })
    });
    const d = await r.json();
    if (d.success) { showAlert('success', d.message || 'Revertido.'); loadAdminFaHistory(); }
    else showAlert('danger', d.error || 'Erro ao reverter.');
  } catch(e) { showAlert('danger', 'Erro de conexão.'); }
}

async function openFaChangeTeam(requestId, playerName, league) {
  if (!requestId) { showAlert('danger', 'ID inválido.'); return; }
  document.getElementById('faChangeReqId').value = requestId;
  document.getElementById('faChangePlayerName').textContent = playerName;
  const sel = document.getElementById('faChangeNewTeam');
  sel.innerHTML = '<option>Carregando...</option>';
  try {
    const params = new URLSearchParams({ action: 'teams_by_league' });
    if (league) params.set('league', league);
    const d = await fetch(`/api/free-agency.php?${params}`).then(r => r.json());
    const teams = d.teams || [];
    if (!teams.length) {
      sel.innerHTML = '<option value="">Nenhum time encontrado</option>';
    } else {
      sel.innerHTML = teams.map(t => `<option value="${t.id}">${t.full_name}</option>`).join('');
    }
  } catch(e) { sel.innerHTML = '<option value="">Erro ao carregar times</option>'; }
  bootstrap.Modal.getOrCreateInstance(document.getElementById('modalFaChangeTeam')).show();
}

async function adminFaChangeTeamConfirm() {
  const requestId = parseInt(document.getElementById('faChangeReqId').value);
  const newTeamId = parseInt(document.getElementById('faChangeNewTeam').value);
  if (!requestId || !newTeamId) { showAlert('danger', 'Selecione um time.'); return; }
  try {
    const r = await fetch('/api/free-agency.php', {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'admin_fa_change_team', request_id: requestId, new_team_id: newTeamId })
    });
    const d = await r.json();
    bootstrap.Modal.getOrCreateInstance(document.getElementById('modalFaChangeTeam')).hide();
    if (d.success) { showAlert('success', d.message || 'Time alterado.'); loadAdminFaHistory(); }
    else showAlert('danger', d.error || 'Erro ao mudar time.');
  } catch(e) { showAlert('danger', 'Erro de conexão.'); }
}

// (painel de leilão admin consolidado em leilao.php — ver Bloco 2/Bloco 6)

// ========== MOEDAS ==========
let coinsLeague = 'ELITE';

async function showCoins(league) {
  league = league || appState.currentLeague || 'ELITE';
  coinsLeague = league;
  appState.view = 'coins';
  updateBreadcrumb();

  const container = document.getElementById('mainContainer');
  container.innerHTML = `
<div class="mb-3"><button class="btn btn-back" onclick="showLeague('${league}')"><i class="bi bi-arrow-left"></i> Voltar</button></div>

<div class="panel mb-3">
  <div class="panel-header">
    <div>
      <div class="panel-title" style="margin-bottom:0"><i class="bi bi-coin" style="color:#f59e0b"></i> Moedas — ${league}</div>
      <div class="panel-sub">Free Agency coins dos times da liga</div>
    </div>
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
      <button class="btn-ghost" onclick="openDistStandings()" title="Distribui moedas automaticamente pela classificação da temporada"><i class="bi bi-trophy me-1" style="color:#f59e0b"></i>Distribuir por classificação</button>
      <button class="btn-orange" onclick="saveAllCoins()"><i class="bi bi-save2 me-1"></i>Salvar</button>
    </div>
  </div>
  <div id="coinsContainer">
    <div class="text-center py-4"><div class="spinner-border" style="color:var(--red)"></div></div>
  </div>
</div>

<div class="modal fade" id="addCoinsModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-coin me-2" style="color:#f59e0b"></i>Gerenciar Moedas</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="coinsTeamId">
        <div class="mb-3">
          <label class="pun-field-label">Time</label>
          <input type="text" class="form-control" id="coinsTeamName" readonly>
        </div>
        <div class="mb-3">
          <label class="pun-field-label">Saldo Atual</label>
          <input type="text" class="form-control" id="coinsCurrentBalance" readonly>
        </div>
        <div class="mb-3">
          <label class="pun-field-label">Operação</label>
          <select class="form-select" id="coinsOperation">
            <option value="add">Adicionar</option>
            <option value="remove">Remover</option>
          </select>
        </div>
        <div class="mb-3">
          <label class="pun-field-label">Quantidade</label>
          <input type="number" class="form-control" id="coinsAmount" min="1" value="100">
        </div>
        <div class="mb-3">
          <label class="pun-field-label">Motivo</label>
          <input type="text" class="form-control" id="coinsReason" placeholder="Ex: Prêmio de temporada">
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn-ghost" data-bs-dismiss="modal">Cancelar</button>
        <button type="button" class="btn-orange" onclick="submitCoins()">Confirmar</button>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="distStandingsModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-trophy me-2" style="color:#f59e0b"></i>Distribuir moedas por classificação</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p style="font-size:12px;color:var(--text-3);margin-bottom:14px">Moedas = <b>base + (posição-1) × passo</b>, pela <b>classificação geral</b> da liga (a mesma ordem do ranking). Ex.: base 2, passo 2 → 1º=2, 2º=4, 3º=6… e num grupo de 30 o último fica com 60.</p>
        <div class="row g-2 mb-2">
          <div class="col-4"><label class="pun-field-label">Base</label><input type="number" class="form-control" id="distBase" min="0" value="2"></div>
          <div class="col-4"><label class="pun-field-label">Passo</label><input type="number" class="form-control" id="distStep" min="0" value="2"></div>
          <div class="col-4"><label class="pun-field-label">Quem recebe mais</label>
            <select class="form-select" id="distDirection">
              <option value="worst_most">Pior colocado</option>
              <option value="best_most">Melhor colocado</option>
            </select>
          </div>
        </div>
        <div class="mb-2"><label class="pun-field-label">Motivo</label><input type="text" class="form-control" id="distReason" value="Moedas por classificação"></div>
        <button type="button" class="btn-ghost" onclick="previewDistStandings()"><i class="bi bi-eye me-1"></i>Pré-visualizar</button>
        <div id="distPreview" style="margin-top:12px"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn-ghost" data-bs-dismiss="modal">Cancelar</button>
        <button type="button" class="btn-orange" id="distApplyBtn" onclick="applyDistStandings()" disabled><i class="bi bi-coin me-1"></i>Aplicar distribuição</button>
      </div>
    </div>
  </div>
</div>
`;

  loadCoinsTeams();
}

function openDistStandings() {
  document.getElementById('distPreview').innerHTML = '';
  document.getElementById('distApplyBtn').disabled = true;
  new bootstrap.Modal(document.getElementById('distStandingsModal')).show();
}

function _distParams(apply) {
  return {
    action: 'coins_by_standings',
    league: coinsLeague,
    base: parseInt(document.getElementById('distBase').value || '0', 10),
    step: parseInt(document.getElementById('distStep').value || '0', 10),
    direction: document.getElementById('distDirection').value,
    reason: document.getElementById('distReason').value,
    apply: apply
  };
}

async function previewDistStandings() {
  const box = document.getElementById('distPreview');
  box.innerHTML = '<div class="text-center py-3"><div class="spinner-border spinner-border-sm" style="color:var(--red)"></div></div>';
  try {
    const data = await api('admin.php?action=coins_by_standings', { method: 'POST', body: JSON.stringify(_distParams(false)) });
    const dist = data.distribution || [];
    if (!dist.length) { box.innerHTML = '<div style="color:var(--text-3);font-size:13px">Nada a distribuir.</div>'; return; }
    /* Times empatados em 0 ponto ficam ordenados por nome, e não por mérito.
       Vale distribuir assim, mas o admin precisa ver isso ANTES de aplicar. */
    const zerados = data.zerados || 0;
    const aviso = zerados > 0
      ? `<div style="font-size:12px;color:#f59e0b;margin-bottom:8px">
           <i class="bi bi-exclamation-triangle me-1"></i>${zerados} time${zerados > 1 ? 's' : ''} com 0 ponto no ranking —
           entre eles a ordem sai por nome, não por classificação.
         </div>` : '';
    box.innerHTML = aviso + `
      <div style="max-height:280px;overflow-y:auto;border:1px solid var(--border);border-radius:10px">
        <table class="table table-dark table-sm mb-0" style="font-size:12.5px">
          <thead><tr><th>#</th><th>Time</th><th class="text-end">Pts</th><th class="text-end">Moedas</th><th class="text-end">Novo saldo</th></tr></thead>
          <tbody>${dist.map(d => `<tr>
            <td>${d.rank}º</td>
            <td>${escapeHtml(d.team_name)}</td>
            <td class="text-end" style="color:${d.points ? 'var(--text-2)' : '#f59e0b'}">${d.points ?? '-'}</td>
            <td class="text-end" style="color:#f59e0b;font-weight:700">+${d.amount}</td>
            <td class="text-end">${d.new_balance}</td>
          </tr>`).join('')}</tbody>
        </table>
      </div>`;
    document.getElementById('distApplyBtn').disabled = false;
  } catch (e) {
    box.innerHTML = `<div style="color:#ef4444;font-size:13px">Erro: ${escapeHtml(e.error || 'Desconhecido')}</div>`;
  }
}

async function applyDistStandings() {
  if (!await confirmarSite('Aplicar a distribuição de moedas por classificação? As moedas serão somadas ao saldo atual de cada time.')) return;
  const btn = document.getElementById('distApplyBtn');
  btn.disabled = true;
  try {
    const data = await api('admin.php?action=coins_by_standings', { method: 'POST', body: JSON.stringify(_distParams(true)) });
    showAlert('success', data.message || 'Moedas distribuídas!');
    bootstrap.Modal.getInstance(document.getElementById('distStandingsModal'))?.hide();
    loadCoinsTeams();
  } catch (e) {
    showAlert('danger', e.error || 'Erro ao distribuir moedas');
    btn.disabled = false;
  }
}

async function loadCoinsTeams() {
  const container = document.getElementById('coinsContainer');
  if (!container) return;
  try {
    const data = await api(`admin.php?action=coins&league=${coinsLeague}`);
    const teams = data.teams || [];
    if (teams.length === 0) {
      container.innerHTML = '<div style="text-align:center;padding:32px;color:var(--text-3)">Nenhum time encontrado.</div>';
      return;
    }
    const totalCoins = teams.reduce((sum, t) => sum + parseInt(t.moedas || 0), 0);
    container.innerHTML = `
<div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px">
  <div class="pun-card" style="flex:1;min-width:120px;padding:12px 16px;text-align:center">
    <div style="font-size:20px;font-weight:700;color:#f59e0b"><i class="bi bi-coin me-1"></i><span data-coins-total>${totalCoins.toLocaleString()}</span></div>
    <div style="font-size:11px;color:var(--text-3)">Total na liga</div>
  </div>
  <div class="pun-card" style="flex:1;min-width:120px;padding:12px 16px;text-align:center">
    <div style="font-size:20px;font-weight:700;color:var(--text)">${teams.length}</div>
    <div style="font-size:11px;color:var(--text-3)">Times</div>
  </div>
</div>
${teams.map(t => {
  const coins = parseInt(t.moedas || 0);
  return `<div class="pun-card" style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
  <div style="flex:1;min-width:140px">
    <span style="font-weight:600;color:var(--text)">${escapeHtml(t.city)} ${escapeHtml(t.name)}</span>
    <div style="font-size:12px;color:var(--text-3);margin-top:2px">${escapeHtml(t.owner_name || '')}</div>
  </div>
  <div style="display:flex;align-items:center;gap:6px">
    <i class="bi bi-coin" style="color:#f59e0b;font-size:14px"></i>
    <input type="number" min="0" value="${coins}" data-original="${coins}" autocomplete="off"
           id="coins-input-${t.id}"
           style="width:90px;background:var(--panel-3);border:1px solid var(--border);border-radius:8px;padding:5px 8px;color:var(--text);font-size:13px;font-family:var(--font)">
    <button class="btn-ghost" style="padding:5px 9px" title="Histórico" onclick="showCoinsHistory(${t.id}, '${escapeHtml(t.city + ' ' + t.name)}')"><i class="bi bi-clock-history"></i></button>
  </div>
</div>`;
}).join('')}`;
  } catch (e) {
    container.innerHTML = `<div style="color:#ef4444;padding:16px">Erro: ${e.error || 'Desconhecido'}</div>`;
  }
}

async function saveAllCoins() {
  const container = document.getElementById('coinsContainer');
  if (!container) return;
  const inputs = container.querySelectorAll('input[type="number"][data-original]');
  const changes = [];
  inputs.forEach(el => {
    const newBalance = parseInt(el.value);
    const originalBalance = parseInt(el.dataset.original || 0);
    if (!isNaN(newBalance) && newBalance >= 0 && newBalance !== originalBalance) {
      const teamId = el.id.replace('coins-input-', '');
      const teamName = el.closest('.pun-card')?.querySelector('span')?.textContent || `Time ${teamId}`;
      changes.push({ el, teamId, teamName, newBalance, delta: newBalance - originalBalance });
    }
  });
  if (changes.length === 0) { showAlert('info', 'Nenhuma alteração.'); return; }

  const results = await Promise.allSettled(changes.map(c =>
    api('admin.php?action=coins', {
      method: 'POST',
      body: JSON.stringify({
        team_id: c.teamId,
        operation: c.delta > 0 ? 'add' : 'remove',
        amount: Math.abs(c.delta),
        reason: 'Ajuste administrativo'
      })
    })
  ));

  const failed = [];
  results.forEach((r, i) => {
    if (r.status === 'fulfilled') {
      changes[i].el.dataset.original = String(changes[i].newBalance);
    } else {
      failed.push(`${changes[i].teamName}: ${r.reason?.error || 'erro desconhecido'}`);
    }
  });

  let total = 0;
  inputs.forEach(el => { total += parseInt(el.dataset.original || el.value || 0); });
  const totalEl = container.querySelector('[data-coins-total]');
  if (totalEl) totalEl.textContent = total.toLocaleString();

  const okCount = changes.length - failed.length;
  if (failed.length === 0) {
    showAlert('success', `${okCount} time(s) atualizados!`);
  } else {
    alert(`${okCount} time(s) atualizados. Falha em ${failed.length}:\n\n${failed.join('\n')}`);
  }
}

function openCoinsModal(teamId, teamName, currentBalance) {
  document.getElementById('coinsTeamId').value = teamId;
  document.getElementById('coinsTeamName').value = teamName;
  document.getElementById('coinsCurrentBalance').value = parseInt(currentBalance).toLocaleString();
  document.getElementById('coinsOperation').value = 'add';
  document.getElementById('coinsAmount').value = 100;
  document.getElementById('coinsReason').value = '';
  
  new bootstrap.Modal(document.getElementById('addCoinsModal')).show();
}

async function submitCoins() {
  const teamId = document.getElementById('coinsTeamId').value;
  const operation = document.getElementById('coinsOperation').value;
  const amount = parseInt(document.getElementById('coinsAmount').value);
  const reason = document.getElementById('coinsReason').value.trim() || 'Ajuste administrativo';
  
  if (!teamId || !amount || amount <= 0) {
    alert('Preencha uma quantidade válida.');
    return;
  }
  
  try {
    const result = await api('admin.php?action=coins', {
      method: 'POST',
      body: JSON.stringify({ team_id: teamId, operation, amount, reason })
    });
    
    bootstrap.Modal.getInstance(document.getElementById('addCoinsModal'))?.hide();
    alert(result.message);
    loadCoinsTeams();
  } catch (e) {
    alert('Erro: ' + (e.error || 'Desconhecido'));
  }
}

async function showCoinsHistory(teamId, teamName) {
  const container = document.getElementById('coinsContainer');
  if (!container) return;
  container.innerHTML = '<div class="text-center py-4"><div class="spinner-border" style="color:var(--red)"></div></div>';
  try {
    const data = await api(`admin.php?action=coins_log&team_id=${teamId}`);
    const logs = data.logs || [];
    const typeMap = {
      admin_add:    { label: 'Adição Admin',   color: '#4ade80', bg: 'rgba(74,222,128,.15)',  border: 'rgba(74,222,128,.3)'  },
      admin_remove: { label: 'Remoção Admin',  color: '#ef4444', bg: 'rgba(239,68,68,.15)',   border: 'rgba(239,68,68,.3)'   },
      admin_bulk:   { label: 'Distribuição',   color: '#38bdf8', bg: 'rgba(56,189,248,.15)',  border: 'rgba(56,189,248,.3)'  },
      fa_bid:       { label: 'Lance FA',       color: '#f59e0b', bg: 'rgba(245,158,11,.15)',  border: 'rgba(245,158,11,.3)'  },
      fa_win:       { label: 'Vitória FA',     color: '#a855f7', bg: 'rgba(168,85,247,.15)',  border: 'rgba(168,85,247,.3)'  },
      fa_refund:    { label: 'Reembolso FA',   color: '#94a3b8', bg: 'rgba(148,163,184,.15)', border: 'rgba(148,163,184,.3)' },
    };
    container.innerHTML = `
<div class="mb-3">
  <button class="btn btn-back" onclick="loadCoinsTeams()"><i class="bi bi-arrow-left"></i> Voltar</button>
</div>
<div style="font-size:14px;font-weight:600;color:var(--text);margin-bottom:12px">
  <i class="bi bi-coin me-2" style="color:#f59e0b"></i>Histórico — ${escapeHtml(teamName)}
</div>
${logs.length === 0 ? '<div style="text-align:center;padding:32px;color:var(--text-3)">Nenhum histórico encontrado.</div>' :
  logs.map(log => {
    const date = new Date(log.created_at);
    const dateStr = date.toLocaleDateString('pt-BR') + ' ' + date.toLocaleTimeString('pt-BR', {hour:'2-digit', minute:'2-digit'});
    const t = typeMap[log.type] || { label: log.type, color: '#94a3b8', bg: 'rgba(148,163,184,.15)', border: 'rgba(148,163,184,.3)' };
    const amt = parseInt(log.amount || 0);
    const pos = amt >= 0;
    return `<div class="pun-card" style="display:flex;align-items:center;gap:12px">
  <div style="flex:1;min-width:0">
    <div style="display:flex;align-items:center;gap:8px;margin-bottom:3px">
      <span style="background:${t.bg};color:${t.color};border:1px solid ${t.border};border-radius:999px;font-size:10px;font-weight:700;padding:2px 8px">${t.label}</span>
      <span style="font-size:11px;color:var(--text-3)">${dateStr}</span>
    </div>
    <div style="font-size:12px;color:var(--text-3)">${escapeHtml(log.reason || '-')}</div>
  </div>
  <div style="text-align:right">
    <div style="font-size:14px;font-weight:700;color:${pos ? '#4ade80' : '#ef4444'}">${pos ? '+' : ''}${amt.toLocaleString()}</div>
    <div style="font-size:11px;color:var(--text-3)">Saldo: ${parseInt(log.balance_after || 0).toLocaleString()}</div>
  </div>
</div>`;
  }).join('')}`;
  } catch (e) {
    container.innerHTML = `<div style="color:#ef4444;padding:16px">Erro: ${e.error || 'Desconhecido'}</div>`;
  }
}

// ========== TAPAS ==========
let tapasLeague = 'ELITE';

// A tela e de BADGES. O nome das funcoes segue tapas* porque a tabela e os
// endpoints sao os mesmos — renomear tudo era um commit de ruido, e a fila e
// literalmente a mesma. O que a liga ve, e o que esta tela mostra, e badge.
async function showTapas(league) {
  const _wasInTapas = appState.view === 'tapas';
  // A liga vem do card que chamou; sem ela, a que estiver aberta.
  if (league) tapasLeague = league;
  else if (appState.currentLeague && !_wasInTapas) tapasLeague = appState.currentLeague;
  appState.view = 'tapas';
  updateBreadcrumb();

  const _tapasBack = appState.currentLeague ? `showLeague('${appState.currentLeague}')` : 'showHome()';

  const container = document.getElementById('mainContainer');
  container.innerHTML = `
    <div class="mb-4" style="display:flex;align-items:center;gap:16px;flex-wrap:wrap">
      <button class="btn btn-back" onclick="${_tapasBack}"><i class="bi bi-arrow-left"></i> Voltar</button>
      <span class="text-light-gray" style="font-size:14px;font-weight:600">Badges — ${tapasLeague}</span>
    </div>

    <div id="tapasContainer">
      <div class="text-center py-5"><div class="spinner-border text-orange"></div></div>
    </div>

    <!-- Approval confirm modal -->
    <div style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.7);z-index:1000;align-items:center;justify-content:center" id="tapasApproveOverlay">
      <div style="background:var(--panel-3,#1c1c21);border:1px solid rgba(255,255,255,.12);border-radius:14px;padding:24px;width:100%;max-width:380px;margin:16px">
        <div style="font-size:15px;font-weight:700;color:var(--text,#f0f0f3);margin-bottom:12px">
          <i class="bi bi-check-circle" style="color:#22c55e"></i> Confirmar Aprovação
        </div>
        <div style="font-size:13px;color:var(--text,#f0f0f3);margin-bottom:6px" id="tapasApproveInfo"></div>
        <div style="font-size:12px;color:var(--text-2,#868690);margin-bottom:20px" id="tapasApproveTypeInfo"></div>
        <input type="hidden" id="tapasApproveReqId">
        <div style="display:flex;gap:8px;justify-content:flex-end">
          <button onclick="closeTapasApprove()" style="padding:8px 16px;border-radius:8px;border:1px solid rgba(255,255,255,.1);background:none;color:var(--text-2,#868690);font-weight:600;font-size:13px;cursor:pointer">Cancelar</button>
          <button onclick="submitTapasApprove()" style="padding:8px 18px;border-radius:8px;border:none;background:#22c55e;color:#fff;font-weight:700;font-size:13px;cursor:pointer">Aprovar</button>
        </div>
      </div>
    </div>
  `;

  loadTapasData();
}

function changeTapasLeague(league) {
  tapasLeague = league;
  showTapas();
}

function openTapasApprove(reqId, playerName, teamName, actionType, badgeName) {
  document.getElementById('tapasApproveReqId').value = reqId;
  document.getElementById('tapasApproveInfo').innerHTML =
    `<strong>${escapeHtml(playerName)}</strong> &mdash; ${escapeHtml(teamName)}`;
  const typeLabel = actionType === 'badge'
    ? `<i class="bi bi-award" style="color:#a78bfa"></i> Badge: <strong style="color:#a78bfa">${escapeHtml(badgeName || '')}</strong>`
    : `<i class="bi bi-hand-index-thumb" style="color:#f97316"></i> <strong style="color:#f97316">Tapa</strong>`;
  document.getElementById('tapasApproveTypeInfo').innerHTML = typeLabel;
  document.getElementById('tapasApproveOverlay').style.display = 'flex';
}

function closeTapasApprove() {
  document.getElementById('tapasApproveOverlay').style.display = 'none';
}

async function submitTapasApprove() {
  const reqId = parseInt(document.getElementById('tapasApproveReqId').value);
  try {
    await fetch('/api/tapas.php?action=admin_approve', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ request_id: reqId })
    }).then(r => r.json()).then(d => { if (d.success === false) throw d; });
    closeTapasApprove();
    loadTapasData();
  } catch(e) {
    alert(e.error || 'Erro ao aprovar');
  }
}

async function rejectTapasRequest(reqId) {
  if (!await confirmarSite('Rejeitar esta solicitação?')) return;
  try {
    await fetch('/api/tapas.php?action=admin_reject', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ request_id: reqId })
    }).then(r => r.json()).then(d => { if (d.success === false) throw d; });
    loadTapasData();
  } catch(e) {
    alert(e.error || 'Erro ao rejeitar');
  }
}

async function quickTapasAdminChange(teamId, operation) {
  try {
    await fetch('/api/tapas.php?action=admin_set_tapas', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ team_id: teamId, amount: 1, operation })
    }).then(r => r.json()).then(d => {
      if (d.success === false) throw d;
      const span = document.getElementById(`tapas-val-${teamId}`);
      if (span && d.new_tapas !== undefined) span.textContent = d.new_tapas;
    });
  } catch(e) {
    alert(e.error || 'Erro ao atualizar tapas');
  }
}

async function loadTapasData() {
  const container = document.getElementById('tapasContainer');
  if (!container) return;

  try {
    const data = await fetch(`/api/tapas.php?action=admin_get_all&league=${encodeURIComponent(tapasLeague)}`)
      .then(r => r.json());
    if (data.success === false) throw data;

    const teams    = data.teams    || [];
    const requests = data.requests || [];
    const history  = data.history  || [];

    const totalTapas    = teams.reduce((s, t) => s + parseInt(t.tapas || 0), 0);
    const totalTapasUsed = teams.reduce((s, t) => s + parseInt(t.tapas_used || 0), 0);

    const requestsHtml = requests.length === 0
      ? '<div style="text-align:center;padding:20px;color:var(--text-3)">Nenhuma solicitação pendente.</div>'
      : requests.map(r => {
          const isBadge   = r.action_type === 'badge';
          const typeChip  = isBadge
            ? `<span style="display:inline-flex;align-items:center;gap:4px;background:rgba(139,92,246,.15);color:#a78bfa;border:1px solid rgba(139,92,246,.35);border-radius:999px;font-size:10px;font-weight:700;padding:2px 7px"><i class="bi bi-award"></i> ${escapeHtml(r.badge_name || '')}</span>`
            : `<span style="display:inline-flex;align-items:center;gap:4px;background:rgba(249,115,22,.15);color:#f97316;border:1px solid rgba(249,115,22,.35);border-radius:999px;font-size:10px;font-weight:700;padding:2px 7px"><i class="bi bi-hand-index-thumb"></i> Tapa</span>`;
          const pn = escapeHtml(r.player_name).replace(/'/g,"\\'");
          const tn = escapeHtml(r.team_city+' '+r.team_name).replace(/'/g,"\\'");
          const at = escapeHtml(r.action_type || 'tapa').replace(/'/g,"\\'");
          const bn = escapeHtml(r.badge_name || '').replace(/'/g,"\\'");
          return `
          <div style="display:flex;align-items:center;gap:12px;padding:12px 14px;background:var(--panel-2);border:1px solid rgba(255,255,255,.07);border-radius:10px;margin-bottom:8px">
            <div style="flex:1;min-width:0">
              <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                <span style="font-weight:700;font-size:13px;color:var(--text)">${escapeHtml(r.player_name)}</span>
                ${typeChip}
              </div>
              <div style="font-size:11px;color:var(--text-3);margin-top:3px">
                ${escapeHtml(r.team_city)} ${escapeHtml(r.team_name)}
                &bull; ${escapeHtml(r.owner_name)}
                &bull; ${escapeHtml(r.player_position)} OVR ${r.player_ovr}
              </div>
            </div>
            <div style="display:flex;gap:6px;flex-shrink:0">
              <button onclick="openTapasApprove(${r.id},'${pn}','${tn}','${at}','${bn}')"
                style="padding:6px 12px;border-radius:8px;border:none;background:rgba(34,197,94,.15);color:#22c55e;font-weight:700;font-size:12px;cursor:pointer">
                <i class="bi bi-check-lg"></i> OK
              </button>
              <button onclick="rejectTapasRequest(${r.id})"
                style="padding:6px 12px;border-radius:8px;border:none;background:rgba(239,68,68,.12);color:#ef4444;font-weight:700;font-size:12px;cursor:pointer">
                <i class="bi bi-x-lg"></i>
              </button>
            </div>
          </div>`;
        }).join('');

    container.innerHTML = `
      <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:20px">
        <div style="flex:1;min-width:120px;background:var(--panel-2);border:1px solid rgba(255,255,255,.07);border-radius:10px;padding:14px 18px;text-align:center">
          <div style="font-size:24px;font-weight:800;color:#f97316">${totalTapas}</div>
          <div style="font-size:11px;color:var(--text-3);margin-top:3px;text-transform:uppercase;letter-spacing:.5px">Disponíveis</div>
        </div>
        <div style="flex:1;min-width:120px;background:var(--panel-2);border:1px solid rgba(255,255,255,.07);border-radius:10px;padding:14px 18px;text-align:center">
          <div style="font-size:24px;font-weight:800;color:var(--text-2)">${totalTapasUsed}</div>
          <div style="font-size:11px;color:var(--text-3);margin-top:3px;text-transform:uppercase;letter-spacing:.5px">Usados</div>
        </div>
        <div style="flex:1;min-width:120px;background:var(--panel-2);border:1px solid ${requests.length ? 'rgba(245,158,11,.3)' : 'rgba(255,255,255,.07)'};border-radius:10px;padding:14px 18px;text-align:center">
          <div style="font-size:24px;font-weight:800;color:${requests.length ? '#f59e0b' : 'var(--text-3)'}">${requests.length}</div>
          <div style="font-size:11px;color:var(--text-3);margin-top:3px;text-transform:uppercase;letter-spacing:.5px">Pendentes</div>
        </div>
      </div>

      ${requests.length ? `
      <div style="background:var(--panel-3);border:1px solid rgba(245,158,11,.25);border-radius:12px;padding:16px 18px;margin-bottom:20px">
        <div style="font-size:13px;font-weight:700;color:#f59e0b;margin-bottom:12px"><i class="bi bi-clock-fill"></i> Solicitações Pendentes (${requests.length})</div>
        ${requestsHtml}
      </div>` : ''}

      <div style="background:var(--panel-3);border:1px solid rgba(255,255,255,.07);border-radius:12px;padding:16px 18px">
        <div style="font-size:13px;font-weight:700;color:var(--text);margin-bottom:12px"><i class="bi bi-people-fill" style="color:#f97316"></i> Times — ${tapasLeague}</div>
        ${teams.length === 0
          ? '<div style="text-align:center;padding:20px;color:var(--text-3)">Nenhum time encontrado.</div>'
          : teams.map(t => {
              const tapped = t.tapped_players || [];
              const playersHtml = tapped.length === 0
                ? `<div style="padding:10px 16px;color:var(--text-3);font-size:12px">Nenhum jogador tapado.</div>`
                : tapped.map(p => `
                    <div style="display:flex;align-items:center;gap:10px;padding:9px 16px;border-top:1px solid rgba(255,255,255,.05)">
                      <div style="width:32px;height:32px;border-radius:7px;background:rgba(249,115,22,.15);border:1px solid rgba(249,115,22,.3);display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:800;color:#f97316;flex-shrink:0">${p.ovr ?? '?'}</div>
                      <div style="flex:1;min-width:0">
                        <div style="font-size:13px;font-weight:600;color:var(--text)">${escapeHtml(p.player_name)}</div>
                        <div style="font-size:11px;color:var(--text-3)">${escapeHtml(p.position ?? '')} · OVR ${p.ovr ?? '?'}</div>
                      </div>
                      <span style="display:inline-flex;align-items:center;gap:4px;background:rgba(249,115,22,.15);color:#f97316;border:1px solid rgba(249,115,22,.3);border-radius:999px;font-size:10px;font-weight:700;padding:2px 7px"><i class="bi bi-hand-index-thumb"></i> Tapa</span>
                    </div>`).join('');
              return `
                <div style="background:var(--panel-2);border:1px solid rgba(255,255,255,.06);border-radius:10px;overflow:hidden;margin-bottom:8px">
                  <div style="display:flex;align-items:center;gap:12px;padding:10px 14px;cursor:pointer;user-select:none" onclick="toggleTapasTeam(${t.id})">
                    <div style="flex:1;min-width:0">
                      <div style="font-weight:700;font-size:13px;color:var(--text)">${escapeHtml(t.city)} ${escapeHtml(t.name)}</div>
                      <div style="font-size:11px;color:var(--text-3)">${escapeHtml(t.owner_name)} · <span style="color:#f97316">${tapped.length} tapado(s)</span></div>
                    </div>
                    <div style="display:flex;align-items:center;gap:8px;flex-shrink:0" onclick="event.stopPropagation()">
                      <span style="font-size:11px;color:var(--text-3)">Tapas:</span>
                      <button onclick="quickTapasAdminChange(${t.id},'remove')" style="width:26px;height:26px;border-radius:6px;border:1px solid rgba(255,255,255,.1);background:none;color:var(--text-2);font-size:14px;line-height:1;cursor:pointer;display:flex;align-items:center;justify-content:center">−</button>
                      <span id="tapas-val-${t.id}" style="font-weight:800;font-size:15px;color:#f97316;min-width:24px;text-align:center">${parseInt(t.tapas || 0)}</span>
                      <button onclick="quickTapasAdminChange(${t.id},'add')" style="width:26px;height:26px;border-radius:6px;border:1px solid rgba(255,255,255,.1);background:none;color:var(--text-2);font-size:14px;line-height:1;cursor:pointer;display:flex;align-items:center;justify-content:center">+</button>
                      <span style="font-size:11px;color:var(--text-3)">usados: ${parseInt(t.tapas_used || 0)}</span>
                    </div>
                    <i id="tapas-chevron-${t.id}" class="bi bi-chevron-down" style="color:var(--text-3);font-size:12px;transition:transform .2s;flex-shrink:0"></i>
                  </div>
                  <div id="tapas-acc-${t.id}" style="display:none">${playersHtml}</div>
                </div>`;
            }).join('')}
      </div>

      <div style="background:var(--panel-3);border:1px solid rgba(255,255,255,.07);border-radius:12px;padding:16px 18px;margin-top:16px">
        <div style="font-size:13px;font-weight:700;color:var(--text);margin-bottom:12px"><i class="bi bi-clock-history" style="color:#f97316"></i> Histórico — ${tapasLeague}</div>
        ${history.length === 0
          ? '<div style="text-align:center;padding:20px;color:var(--text-3)">Nenhum registro encontrado.</div>'
          : `<div style="display:grid;gap:6px">
              ${history.map(h => {
                const isBadge  = h.action_type === 'badge';
                const typeChip = isBadge
                  ? `<span style="display:inline-flex;align-items:center;gap:4px;background:rgba(139,92,246,.15);color:#a78bfa;border:1px solid rgba(139,92,246,.35);border-radius:999px;font-size:10px;font-weight:700;padding:2px 7px;flex-shrink:0"><i class="bi bi-award"></i>${escapeHtml(h.badge_name || 'Badge')}</span>`
                  : `<span style="display:inline-flex;align-items:center;gap:4px;background:rgba(249,115,22,.15);color:#f97316;border:1px solid rgba(249,115,22,.35);border-radius:999px;font-size:10px;font-weight:700;padding:2px 7px;flex-shrink:0"><i class="bi bi-hand-index-thumb"></i>Tapa</span>`;
                const date = h.processed_at ? h.processed_at.substring(0, 10) : '';
                return `<div style="display:flex;align-items:center;gap:10px;padding:9px 12px;background:var(--panel-2);border:1px solid rgba(255,255,255,.06);border-radius:8px">
                  <div style="width:32px;height:32px;border-radius:7px;background:rgba(249,115,22,.12);border:1px solid rgba(249,115,22,.25);display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:800;color:#f97316;flex-shrink:0">${h.ovr ?? '?'}</div>
                  <div style="flex:1;min-width:0">
                    <div style="font-size:13px;font-weight:600;color:var(--text)">${escapeHtml(h.player_name)}</div>
                    <div style="font-size:11px;color:var(--text-3)">${escapeHtml((h.team_city || '') + ' ' + (h.team_name || ''))} · ${escapeHtml(h.position ?? '')}</div>
                  </div>
                  ${typeChip}
                  <span style="font-size:11px;color:var(--text-3);flex-shrink:0">${date}</span>
                </div>`;
              }).join('')}
             </div>`}
      </div>
    `;
  } catch(e) {
    container.innerHTML = `<div style="color:#ef4444;padding:16px">Erro ao carregar: ${e.error || 'Desconhecido'}</div>`;
  }
}

function toggleTapasTeam(teamId) {
  const body    = document.getElementById(`tapas-acc-${teamId}`);
  const chevron = document.getElementById(`tapas-chevron-${teamId}`);
  if (!body) return;
  const open = body.style.display !== 'none';
  body.style.display    = open ? 'none' : 'block';
  if (chevron) chevron.style.transform = open ? '' : 'rotate(180deg)';
}

// keep legacy aliases used by action tile
function loadTapasTeams() { loadTapasData(); }
async function quickTapasChange(teamId, teamName, operation) { await quickTapasAdminChange(teamId, operation); }

// ========================================
// APROVAÇÃO DE USUÁRIOS
// ========================================

async function showUserApprovals() {
  appState.view = 'userApprovals';
  updateBreadcrumb();

  const _uaLeague = appState.currentLeague || null;
  const _uaBack = _uaLeague ? `showLeague('${_uaLeague}')` : 'showHome()';

  const container = document.getElementById('mainContainer');
  container.innerHTML = '<div class="text-center p-5"><div class="spinner-border text-orange" role="status"></div></div>';

  try {
    const data = await api('user-approval.php');
    const allUsers = data.users || [];
    const users = _uaLeague
      ? allUsers.filter(u => (u.league || '').toUpperCase() === _uaLeague)
      : allUsers;

    let html = `
      <div class="mb-4"><button class="btn btn-back" onclick="${_uaBack}"><i class="bi bi-arrow-left"></i> Voltar</button></div>
      <div class="row">
        <div class="col-12">
          <h2 class="text-white mb-4">
            <i class="bi bi-person-check text-orange me-2"></i>
            Aprovação de Usuários${_uaLeague ? ` — ${_uaLeague}` : ''}
          </h2>
        </div>
      </div>
    `;

    if (users.length === 0) {
      html += `
        <div class="alert alert-info">
          <i class="bi bi-info-circle me-2"></i>
          Não há usuários aguardando aprovação.
        </div>
      `;
    } else {
      html += `
        <div class="row g-4">
          ${users.map(user => {
            const createdDate = new Date(user.created_at);
            const dateStr = createdDate.toLocaleDateString('pt-BR') + ' ' + 
                          createdDate.toLocaleTimeString('pt-BR', {hour: '2-digit', minute: '2-digit'});
            
            return `
              <div class="col-md-6 col-lg-4">
                <div class="card bg-dark-panel border-orange h-100">
                  <div class="card-body">
                    <div class="d-flex align-items-center mb-3">
                      <div class="bg-gradient-orange rounded-circle d-flex align-items-center justify-content-center" 
                           style="width: 50px; height: 50px; min-width: 50px;">
                        <i class="bi bi-person-fill text-white fs-4"></i>
                      </div>
                      <div class="ms-3 flex-grow-1">
                        <h5 class="text-white mb-1">${escapeHtml(user.name || user.username || user.email || 'Usuário')}</h5>
                        <p class="text-light-gray mb-0 small">
                          <i class="bi bi-clock me-1"></i>${dateStr}
                        </p>
                      </div>
                    </div>
                    
                    <div class="mb-3">
                      <p class="text-light-gray mb-1 small">
                        <i class="bi bi-envelope me-2"></i>${user.email}
                      </p>
                    </div>
                    
                    <div class="d-flex gap-2">
                      <button class="btn btn-success flex-fill" onclick="approveUser(${user.id}, '${escapeHtml(user.name || user.username || '')}')">
                        <i class="bi bi-check-circle me-1"></i>Aprovar
                      </button>
                      <button class="btn btn-danger flex-fill" onclick="rejectUser(${user.id}, '${escapeHtml(user.name || user.username || '')}')">
                        <i class="bi bi-x-circle me-1"></i>Rejeitar
                      </button>
                    </div>
                  </div>
                </div>
              </div>
            `;
          }).join('')}
        </div>
      `;
    }
    
    container.innerHTML = html;
  } catch (e) {
    container.innerHTML = '<div class="alert alert-danger">Erro ao carregar usuários: ' + (e.error || 'Desconhecido') + '</div>';
  }
}

async function toggleTrades(league, enabled) {
  try {
    await api('admin.php?action=league_settings', {
      method: 'PUT',
      body: JSON.stringify({ league, trades_enabled: enabled })
    });
    const onBtn  = document.getElementById(`tradesOnBtn_${league}`);
    const offBtn = document.getElementById(`tradesOffBtn_${league}`);
    const badge  = document.getElementById(`tradesBadge_${league}`);
    const on = enabled == 1;
    if (onBtn)  onBtn.className  = `btn btn-sm ${on ? 'btn-success' : 'btn-outline-success'}`;
    if (offBtn) offBtn.className = `btn btn-sm ${!on ? 'btn-danger' : 'btn-outline-danger'}`;
    if (badge) {
      badge.textContent = on ? 'Ativas' : 'Bloqueadas';
      // Classe, e não cssText: reescrever o style inteiro apagava as regras
      // da folha de estilo e o selo voltava à cara antiga no primeiro clique.
      badge.className = 'lgcfg-selo ' + (on ? 'on' : 'off');
      badge.removeAttribute('style');
    }
    showAlert('success', `Trocas ${on ? 'ativadas' : 'desativadas'} para a liga ${league}!`);
  } catch (e) {
    showAlert('danger', 'Erro ao atualizar status de trades');
  }
}

/**
 * Abre ou fecha a edição de tática da liga, no mesmo lugar de Trades e FA.
 *
 * Liga/desliga e mais nada: aberta = dá pra editar, fechada = não dá.
 *
 * Depois de mudar, RECARREGA a barra do servidor em vez de remendar as
 * classes dos botões na mão. O remendo dava a impressão de ter funcionado
 * mesmo quando a gravação falhava, e qualquer campo que ele esquecesse de
 * atualizar só aparecia certo depois de um F5.
 *
 * Os dois lados zeram o "Feito no jogo" de todos os times: cada vez que a
 * janela vira é uma rodada nova de aplicar tática dentro do jogo.
 */
async function toggleTatica(league, abrir) {
  const on = abrir == 1;
  try {
    await api('tactics.php', {
      method: 'POST',
      body: JSON.stringify({ action: 'admin_window', league, aberta: on }),
    });
    showAlert('success', on ? 'Edição de tática aberta.' : 'Edição de tática fechada.');
    if (typeof carregarBarraTatica === 'function') await carregarBarraTatica(league);
    else if (typeof showConfig === 'function') await showConfig();
  } catch (e) {
    showAlert('danger', e.error || 'Erro ao mudar a janela de tática');
  }
}


async function toggleWaivers(league, enabled) {
  try {
    await api('admin.php?action=league_settings', {
      method: 'PUT',
      body: JSON.stringify({ league, waivers_enabled: enabled })
    });
    const onBtn  = document.getElementById(`waiversOnBtn_${league}`);
    const offBtn = document.getElementById(`waiversOffBtn_${league}`);
    const badge  = document.getElementById(`waiversBadge_${league}`);
    const on = enabled == 1;
    if (onBtn)  onBtn.className  = `btn btn-sm ${on ? 'btn-success' : 'btn-outline-success'}`;
    if (offBtn) offBtn.className = `btn btn-sm ${!on ? 'btn-danger' : 'btn-outline-danger'}`;
    if (badge) {
      badge.textContent = on ? 'Abertas' : 'Fechadas';
      badge.className = 'lgcfg-selo ' + (on ? 'on' : 'off');
      badge.removeAttribute('style');
    }
    showAlert('success', `Dispensas ${on ? 'abertas' : 'fechadas'} para a liga ${league}!`);
  } catch (e) {
    showAlert('danger', 'Erro ao atualizar as dispensas');
  }
}

async function toggleFA(league, enabled) {
  try {
    await api('admin.php?action=league_settings', {
      method: 'PUT',
      body: JSON.stringify({ league, fa_enabled: enabled })
    });
    const onBtn  = document.getElementById(`faOnBtn_${league}`);
    const offBtn = document.getElementById(`faOffBtn_${league}`);
    const badge  = document.getElementById(`faBadge_${league}`);
    const on = enabled == 1;
    if (onBtn)  onBtn.className  = `btn btn-sm ${on ? 'btn-success' : 'btn-outline-success'}`;
    if (offBtn) offBtn.className = `btn btn-sm ${!on ? 'btn-danger' : 'btn-outline-danger'}`;
    if (badge) {
      badge.textContent = on ? 'Ativa' : 'Bloqueada';
      // Mesma razao do selo das trades: classe em vez de cssText.
      badge.className = 'lgcfg-selo ' + (on ? 'on' : 'off');
      badge.removeAttribute('style');
    }
    showAlert('success', `Free Agency ${on ? 'ativada' : 'desativada'} para a liga ${league}!`);
  } catch (e) {
    showAlert('danger', 'Erro ao atualizar status da Free Agency');
  }
}

async function approveUser(userId, username) {
  if (!await confirmarSite(`Deseja aprovar o usuário "${username}"?`)) return;
  
  try {
    const result = await api('user-approval.php', {
      method: 'PUT',
      body: JSON.stringify({
        user_id: userId,
        action: 'approve'
      })
    });
    
    if (result.success) {
      showAlert('success', `Usuário "${username}" aprovado com sucesso!`);
      await showUserApprovals(); // Recarrega a lista
      updatePendingUsersCount(); // Atualiza o badge no home
    }
  } catch (e) {
    showAlert('danger', 'Erro ao aprovar usuário: ' + (e.error || 'Desconhecido'));
  }
}

async function rejectUser(userId, username) {
  if (!await confirmarSite(`Deseja REJEITAR e EXCLUIR o usuário "${username}"?\n\nEsta ação não pode ser desfeita!`)) return;
  
  try {
    const result = await api('user-approval.php', {
      method: 'PUT',
      body: JSON.stringify({
        user_id: userId,
        action: 'reject'
      })
    });
    
    if (result.success) {
      showAlert('success', `Usuário "${username}" rejeitado e removido.`);
      await showUserApprovals(); // Recarrega a lista
      updatePendingUsersCount(); // Atualiza o badge no home
    }
  } catch (e) {
    showAlert('danger', 'Erro ao rejeitar usuário: ' + (e.error || 'Desconhecido'));
  }
}

async function updatePendingUsersCount() {
  try {
    const approvalData = await api('user-approval.php');
    const pendingCount = (approvalData.users || []).length;
    const badge = document.getElementById('pending-users-count');
    if (badge) {
      if (pendingCount > 0) {
        badge.textContent = pendingCount;
        badge.style.display = 'inline-block';
      } else {
        badge.style.display = 'none';
      }
    }
  } catch (e) {
    console.error('Erro ao atualizar contagem de usuários pendentes:', e);
  }
}

// ── Dispensas ─────────────────────────────────────────────────────────────────
let _dispensasCache = [];

async function showDispensas() {
  appState.view = 'dispensas';
  updateBreadcrumb();

  const _dispLeague = appState.currentLeague || null;
  const _dispBack = _dispLeague ? `showLeague('${_dispLeague}')` : 'showHome()';

  const container = document.getElementById('mainContainer');
  container.innerHTML = `
    <div class="mb-4">
      <button class="btn btn-back" onclick="${_dispBack}"><i class="bi bi-arrow-left"></i> Voltar</button>
      <span class="text-light-gray ms-3" style="font-size:14px;font-weight:600">Dispensas — ${_dispLeague || 'Liga'}</span>
    </div>
    <div class="panel mb-4">
      <div class="panel-title"><i class="bi bi-person-dash-fill"></i> Filtrar por Temporada</div>
      <div class="d-flex flex-wrap gap-3 align-items-end">
        <input type="hidden" id="dispensasLeague" value="${_dispLeague || ''}">
        <div>
          <label class="form-label text-light-gray small mb-1">Temporada</label>
          <select class="form-select form-select-sm" id="dispensasSeason" style="min-width:130px">
            <option value="">Todas</option>
          </select>
        </div>
      </div>
    </div>
    <div id="dispensasResult"></div>
  `;

  document.getElementById('dispensasSeason').addEventListener('change', renderDispensasTable);

  await loadDispensas();
}

async function loadDispensas() {
  const league = document.getElementById('dispensasLeague')?.value || 'ELITE';
  const resultEl = document.getElementById('dispensasResult');
  if (!resultEl) return;

  resultEl.innerHTML = '<div class="text-center py-4"><div class="spinner-border text-orange" role="status"></div></div>';

  try {
    const data = await api(`free-agency.php?action=waivers&league=${encodeURIComponent(league)}`);
    _dispensasCache = data.waivers || [];

    // Populate season dropdown from data
    const seasonSel = document.getElementById('dispensasSeason');
    if (seasonSel) {
      const years = [...new Set(_dispensasCache.map(w => w.season_year).filter(Boolean))].sort((a, b) => b - a);
      seasonSel.innerHTML = '<option value="">Todas</option>' + years.map(y => `<option value="${y}">${y}</option>`).join('');
    }

    renderDispensasTable();
  } catch (err) {
    resultEl.innerHTML = `<div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i>Erro ao carregar dispensas: ${escapeHtml(err.error || '')}</div>`;
  }
}

function renderDispensasTable() {
  const resultEl = document.getElementById('dispensasResult');
  if (!resultEl) return;

  const selectedYear = document.getElementById('dispensasSeason')?.value || '';
  const filtered = selectedYear
    ? _dispensasCache.filter(w => String(w.season_year) === selectedYear)
    : _dispensasCache;

  if (!filtered.length) {
    resultEl.innerHTML = '<div class="text-light-gray text-center py-4">Nenhuma dispensa encontrada para os filtros selecionados.</div>';
    return;
  }

  // Group by team, sort teams alphabetically
  const byTeam = {};
  filtered.forEach(w => {
    const team = w.original_team_name || 'Sem time';
    if (!byTeam[team]) byTeam[team] = [];
    byTeam[team].push(w);
  });
  const sortedTeams = Object.keys(byTeam).sort();

  let html = `<div class="panel">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
      <span class="text-light-gray small">${filtered.length} dispensa(s) encontrada(s) em ${sortedTeams.length} time(s)</span>
    </div>`;

  sortedTeams.forEach(team => {
    const players = byTeam[team].sort((a, b) => new Date(b.waived_at) - new Date(a.waived_at));
    html += `
      <div class="mb-4">
        <div class="d-flex align-items-center gap-2 mb-2">
          <span style="font-weight:700;font-size:14px;color:var(--red)"><i class="bi bi-shield-fill me-1"></i>${escapeHtml(team)}</span>
          <span class="badge bg-secondary">${players.length}</span>
        </div>
        <div>
          ${players.map(w => `
            <div style="display:flex;justify-content:space-between;align-items:center;padding:6px 0;border-bottom:1px solid var(--border)">
              <span style="font-size:13px;font-weight:600;color:var(--text)">${escapeHtml(w.name || '-')}</span>
              <span style="font-size:11px;color:var(--text)">${w.season_year || '-'} | ${w.waived_at ? w.waived_at.slice(0,16) : '-'}</span>
            </div>`).join('')}
        </div>
      </div>
    `;
  });

  html += '</div>';
  resultEl.innerHTML = html;
}

// ══════════════════════════════════════════════
// PONTUAÇÃO POR TEMPORADA
// ══════════════════════════════════════════════

// A régua é a mesma de backend/pontuacao_ranking.php. Mudou lá, muda aqui.
const PTS_REGULAR = [
  {v:0, l:'— Nenhum —'},
  {v:5, l:'1° e 2° Lugar (+5 pts)'},
  {v:4, l:'3° e 4° Lugar (+4 pts)'},
  {v:3, l:'5° e 6° Lugar (+3 pts)'},
  {v:2, l:'7° e 8° Lugar (+2 pts)'},
  {v:1, l:'9° e 10° Lugar (+1 pt)'}
];
// Acumulados: passar da 1ª rodada (+1), passar do 2º turno (+2), chegar à
// final (+1) e ganhá-la (+4). Cair na 1ª rodada não pontua.
const PTS_PLAYOFF = [
  {v:0, l:'— Não passou da 1ª rodada —'},
  {v:1, l:'2º turno (+1 pt acum.)'},
  {v:3, l:'Final de Conferência (+3 pts acum.)'},
  {v:4, l:'Vice-Campeão (+4 pts acum.)'},
  {v:7, l:'Campeão (+7 pts acum.)'}
];
const PTS_AWARDS = ['MVP','DPOY','MIP','6° Homem','ROY'];

// ── Séries de playoff: quantos jogos (4 a 7) foi cada série que o time jogou ──
const SERIES_ROUNDS = [
  { key:'r1',  label:'1ª Rod.' },
  { key:'r2',  label:'Semi' },
  { key:'cf',  label:'Final Conf.' },
  { key:'fin', label:'Final' },
];
// Quantas séries o time jogou, a partir do valor do dropdown de playoff (pontos acumulados)
function _seriesCountForPlayoff(v) {
  v = parseInt(v || '0', 10);
  if (v <= 0) return 0;   // não participou
  if (v === 1) return 1;  // caiu na 1ª rodada → 1 série
  if (v === 3) return 2;  // semifinalista → 2 séries
  if (v === 6) return 3;  // final de conferência → 3 séries
  return 4;               // vice (8) ou campeão (11) → 4 séries
}
// (Re)desenha os seletores de jogos por série do time conforme o playoff escolhido
function renderPtsSeries(sid, teamId) {
  const form = document.getElementById(`pts-form-${sid}`) || document.getElementById(`pts-edit-form-${sid}`);
  const container = document.getElementById(`pts-series-${sid}-${teamId}`);
  if (!form || !container) return;
  const playoffSel = form.querySelector(`.pts-play-sel[data-team-id="${teamId}"]`);
  const count = _seriesCountForPlayoff(playoffSel ? playoffSel.value : 0);
  if (!count) { container.style.display = 'none'; container.innerHTML = ''; return; }
  // preserva o que já estava selecionado ao trocar o nível do playoff
  const prev = {};
  container.querySelectorAll('.pts-series-sel').forEach(s => { prev[s.dataset.round] = s.value; });
  const selStyle = 'background:var(--panel-2);border:1px solid var(--border-md);border-radius:6px;padding:2px 4px;color:var(--text);font-size:11px';
  container.style.display = 'block';
  container.innerHTML =
    `<div style="font-size:9px;font-weight:700;letter-spacing:.5px;text-transform:uppercase;color:var(--text-3);margin-bottom:3px">Jogos por série (4–7)</div>
     <div style="display:flex;flex-wrap:wrap;gap:8px">` +
    SERIES_ROUNDS.slice(0, count).map(r => `
       <label style="display:flex;align-items:center;gap:4px;font-size:11px;color:var(--text-2)">${r.label}
         <select class="pts-series-sel" data-team-id="${teamId}" data-round="${r.key}" style="${selStyle}">
           <option value="">–</option><option value="4">4</option><option value="5">5</option><option value="6">6</option><option value="7">7</option>
         </select>
       </label>`).join('') +
    `</div>`;
  container.querySelectorAll('.pts-series-sel').forEach(s => { if (prev[s.dataset.round]) s.value = prev[s.dataset.round]; });
}
// Coleta as séries preenchidas do formulário para enviar ao backend
function collectPtsSeries(sid) {
  const form = document.getElementById(`pts-form-${sid}`) || document.getElementById(`pts-edit-form-${sid}`);
  if (!form) return [];
  return Array.from(form.querySelectorAll('.pts-series-sel'))
    .filter(s => s.value)
    .map(s => ({ team_id: parseInt(s.dataset.teamId, 10), round: s.dataset.round, games: parseInt(s.value, 10) }));
}

function buildPtsForm(seasonId, league, leagueTeams, inputClass) {
  const sid = String(seasonId);
  const isElite = (league||'').toUpperCase() === 'ELITE';
  const sel = 'background:var(--panel-2);border:1px solid var(--border-md);border-radius:7px;padding:3px 5px;color:var(--text);font-size:11px;flex-shrink:0';

  const teamRows = leagueTeams.map(t => `
    <div data-team-name="${(t.team_name||'').toLowerCase()}" style="padding:5px 0;border-bottom:1px solid var(--border)">
      <div style="display:flex;align-items:center;gap:6px">
        <span style="font-size:12px;color:var(--text);flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${escapeHtml(t.team_name||'')}</span>
        <select class="pts-reg-sel" data-team-id="${t.team_id}" onchange="calcPtsPreview('${sid}')" style="${sel};max-width:150px">
          ${PTS_REGULAR.map(o=>`<option value="${o.v}">${o.l}</option>`).join('')}
        </select>
        <select class="pts-play-sel" data-team-id="${t.team_id}" onchange="calcPtsPreview('${sid}');renderPtsSeries('${sid}',${t.team_id})" style="${sel};max-width:190px">
          ${PTS_PLAYOFF.map(o=>`<option value="${o.v}">${o.l}</option>`).join('')}
        </select>
      </div>
      <div class="pts-series" id="pts-series-${sid}-${t.team_id}" style="display:none;margin-top:6px;padding-left:2px"></div>
    </div>`).join('');

  const awardRows = PTS_AWARDS.map(a => `
    <div style="display:flex;align-items:center;justify-content:space-between;padding:5px 0;border-bottom:1px solid var(--border)">
      <span style="font-size:12px;color:var(--text)">${escapeHtml(a)} <span style="font-size:10px;color:var(--text-3)">(+1 pt)</span></span>
      <select class="pts-award-sel" data-award="${escapeHtml(a)}" onchange="calcPtsPreview('${sid}')" style="${sel};max-width:200px">
        <option value="0">— Nenhum —</option>
        ${leagueTeams.map(t=>`<option value="${t.team_id}">${escapeHtml(t.team_name||'')}</option>`).join('')}
      </select>
    </div>`).join('');

  const nbaCupHtml = isElite ? `
    <div style="margin-top:12px;padding:10px;background:rgba(245,158,11,.05);border:1px solid rgba(245,158,11,.2);border-radius:8px">
      <div style="font-size:10px;font-weight:700;letter-spacing:.8px;text-transform:uppercase;color:#f59e0b;margin-bottom:6px">NBA Cup — ELITE</div>
      <div style="display:flex;align-items:center;justify-content:space-between">
        <span style="font-size:12px;color:var(--text)">Campeão NBA Cup <span style="font-size:10px;color:var(--text-3)">(+2 pts)</span></span>
        <select class="pts-nbacup-sel" onchange="calcPtsPreview('${sid}')" style="${sel};max-width:200px">
          <option value="0">— Nenhum —</option>
          ${leagueTeams.map(t=>`<option value="${t.team_id}">${escapeHtml(t.team_name||'')}</option>`).join('')}
        </select>
      </div>
    </div>` : '';

  const hiddenInputs = leagueTeams.map(t =>
    `<input type="hidden" class="${inputClass}" data-team-id="${t.team_id}" value="0">`).join('');

  return `
    <div style="font-size:10px;font-weight:700;letter-spacing:.8px;text-transform:uppercase;color:var(--text-3);margin-bottom:6px">Temporada Regular + Playoffs</div>
    <div style="font-size:10px;color:var(--text-3);display:flex;gap:6px;padding-bottom:4px;border-bottom:1px solid var(--border);margin-bottom:2px">
      <span style="flex:1">Time</span><span style="width:150px;text-align:center">T. Regular</span><span style="width:190px;text-align:center">Playoffs</span>
    </div>
    ${teamRows}
    <div style="margin-top:12px">
      <div style="font-size:10px;font-weight:700;letter-spacing:.8px;text-transform:uppercase;color:var(--text-3);margin-bottom:6px">Prêmios Individuais</div>
      ${awardRows}
    </div>
    ${nbaCupHtml}
    <div style="margin-top:12px;background:var(--panel-3);border-radius:8px;padding:10px">
      <div style="font-size:10px;font-weight:700;letter-spacing:.8px;text-transform:uppercase;color:var(--text-3);margin-bottom:6px">Prévia — Total por Time</div>
      ${leagueTeams.map(t=>`
        <div style="display:flex;justify-content:space-between;padding:2px 0">
          <span style="font-size:11px;color:var(--text-2)">${escapeHtml(t.team_name||'')}</span>
          <span class="pts-pv-val" data-tid="${t.team_id}" style="font-size:11px;font-weight:700;color:var(--text-3)">0 pts</span>
        </div>`).join('')}
    </div>
    ${hiddenInputs}`;
}

function calcPtsPreview(seasonId) {
  const sid = String(seasonId);
  const newForm  = document.getElementById(`pts-form-${sid}`);
  const editForm = document.getElementById(`pts-edit-form-${sid}`);
  const form = (editForm && editForm.style.display !== 'none') ? editForm : newForm;
  if (!form) return;

  const totals = {};
  form.querySelectorAll('.pts-reg-sel').forEach(sel => {
    const tid = sel.dataset.teamId;
    totals[tid] = (totals[tid]||0) + parseInt(sel.value||'0', 10);
  });
  form.querySelectorAll('.pts-play-sel').forEach(sel => {
    const tid = sel.dataset.teamId;
    totals[tid] = (totals[tid]||0) + parseInt(sel.value||'0', 10);
  });
  form.querySelectorAll('.pts-award-sel').forEach(sel => {
    if (sel.value && sel.value !== '0') totals[sel.value] = (totals[sel.value]||0) + 1;
  });
  form.querySelectorAll('.pts-nbacup-sel').forEach(sel => {
    if (sel.value && sel.value !== '0') totals[sel.value] = (totals[sel.value]||0) + 2;
  });

  form.querySelectorAll('input[type="hidden"][data-team-id]').forEach(inp => {
    const tid = inp.dataset.teamId;
    const pts = totals[tid] || 0;
    inp.value = pts;
  });
  form.querySelectorAll('.pts-pv-val').forEach(el => {
    const tid = el.dataset.tid;
    const pts = totals[tid] || 0;
    el.textContent = `${pts} pts`;
    el.style.color = pts > 0 ? 'var(--red)' : 'var(--text-3)';
  });
}

/**
 * Reaplica os pontos de posição sobre a classificação já salva.
 *
 * As temporadas fechadas enquanto o card não gravava esses pontos ficaram
 * com o total sem a parte da campanha. A classificação continua no banco —
 * o que falta é passar a régua nela outra vez. Pode rodar quantas vezes
 * quiser: reescreve pela colocação, não soma.
 */
async function recalcularPontosCampanha(league) {
  if (!confirm(`Recalcular a pontuação de todas as temporadas da sprint atual da ${league}?\n\n`
             + 'Refaz os três blocos — classificação, playoffs e prêmios — a partir do que ficou registrado, '
             + 'usando a régua de pontos atual.\n\n'
             + 'Ajuste manual feito no painel de revisão se perde.')) return;
  try {
    const d = await api('seasons.php?action=recalcular_pontos_campanha', {
      method: 'POST',
      body: JSON.stringify({ league })
    });
    const linhas = (d.temporadas || [])
      .map(t => `Temporada ${t.temporada}: ${t.antes} → ${t.depois} pontos (${t.times} times)`)
      .join('\n');
    alert(`${d.message}\n\n${linhas || 'Nenhuma temporada com classificação lançada.'}`);
    showPointsManagement(league);
  } catch (e) {
    alert('Não deu pra recalcular: ' + (e.error || 'erro desconhecido'));
  }
}

async function showPointsManagement(league) {
  league = league || appState.currentLeague || 'ELITE';
  appState.view = 'pontuacao';
  updateBreadcrumb();
  const container = document.getElementById('mainContainer');
  const _ptsBack = appState.currentLeague ? `showLeague('${appState.currentLeague}')` : 'showHome()';

  container.innerHTML = `
    <div class="mb-4">
      <button class="btn btn-back me-2" onclick="${_ptsBack}"><i class="bi bi-arrow-left"></i> Voltar</button>
      <span class="text-light-gray" style="font-size:14px;font-weight:600">Pontuação por Time — ${league}</span>
      <button class="btn btn-sm btn-outline-warning float-end" onclick="congelarRanking('${league}')"
              title="Salva a classificação atual no histórico, para não se perder quando a pontuação for zerada">
        <i class="bi bi-snow"></i> Congelar classificação
      </button>
      <button class="btn btn-sm btn-outline-info float-end me-2" onclick="recalcularPontosCampanha('${league}')"
              title="Refaz a pontuação de cada temporada desta sprint a partir do que ficou registrado — classificação, onde o time parou no playoff e prêmios — usando a régua atual. Ajuste manual feito na revisão se perde.">
        <i class="bi bi-arrow-repeat"></i> Recalcular pontuação
      </button>
    </div>
    <div id="ptsMgmtContent">
      <div class="text-center py-5"><div class="spinner-border text-orange"></div></div>
    </div>`;

  /* A lista de classificações congeladas saiu desta tela. Quem abre
     "Pontuação por Time" vem mexer em pontuação, e o histórico de
     congelamentos não ajudava nisso — só empurrava a tabela pra baixo.
     Congelar continua no botão do topo; o que sumiu é a listagem. */

  let data;
  try {
    data = await api(`history-points.php?action=get_league_seasons_overview&league=${encodeURIComponent(league)}`);
  } catch (e) {
    document.getElementById('ptsMgmtContent').innerHTML =
      `<div class="alert alert-danger">Erro: ${escapeHtml(e.error || 'Falha ao carregar dados')}</div>`;
    return;
  }

  const seasons     = data.seasons      || [];
  const leagueTeams = data.league_teams || [];

  if (!seasons.length) {
    document.getElementById('ptsMgmtContent').innerHTML =
      `<div class="alert alert-info">Nenhuma temporada encontrada para ${league}.</div>`;
    return;
  }

  const fmtTitle = s => s.year ? String(s.year)
    : ([s.sprint_number ? `Sprint ${s.sprint_number}` : '', s.season_number ? `Temp. ${s.season_number}` : ''].filter(Boolean).join(' · ') || 'Temporada');

  // Mais recente no topo
  const html = [...seasons].reverse().map(s => {
    const title = fmtTitle(s);

    if (s.points_registered) {
      // Inputs de edição pré-preenchidos com valores atuais
      const editInputs = leagueTeams.map(t => {
        const existing = (s.teams || []).find(st => String(st.team_id) === String(t.team_id));
        return `
          <div style="display:flex;justify-content:space-between;align-items:center;padding:5px 0;border-bottom:1px solid var(--border)">
            <span style="font-size:12px;color:var(--text)">${escapeHtml(t.team_name||'')}</span>
            <input type="number" class="form-control form-control-sm pts-edit-input" data-team-id="${t.team_id}"
              value="${existing ? existing.points : 0}" min="0" style="max-width:90px">
          </div>`;
      }).join('');

      return `
        <div class="pun-card mb-2" id="pts-season-${s.season_id}">
          <div class="pun-card-head">
            <div class="pun-card-title">
              <i class="bi bi-trophy-fill" style="color:var(--red);margin-right:6px"></i>${escapeHtml(title)}
            </div>
            <div class="d-flex gap-2 align-items-center flex-shrink-0">
              <span class="pun-badge" style="background:rgba(34,197,94,.1);color:#22c55e;border-color:rgba(34,197,94,.3)">Registrado</span>
              <button class="btn-ghost" style="padding:3px 10px;font-size:11px" onclick="toggleEditPtsForm(${s.season_id})">
                <i class="bi bi-pencil me-1"></i>Editar
              </button>
              <button class="btn-ghost" style="padding:3px 10px;font-size:11px;color:#ef4444"
                onclick="deletePtsMgmt(${s.season_id}, '${escapeHtml(league)}')">
                <i class="bi bi-trash3 me-1"></i>Limpar
              </button>
            </div>
          </div>
          <div id="pts-view-${s.season_id}" style="margin-top:8px">
            ${(s.teams||[]).map((t, ti) => `
              <div style="display:flex;justify-content:space-between;align-items:center;padding:5px 0;border-bottom:1px solid var(--border)">
                <div style="display:flex;align-items:center;gap:8px">
                  <span style="font-size:11px;color:var(--text-3);width:20px;text-align:right">${ti + 1}°</span>
                  <span style="font-size:13px;color:var(--text)">${escapeHtml(t.team_name||'')}</span>
                </div>
                <span style="font-size:13px;font-weight:700;color:var(--red)">${t.points} pts</span>
              </div>`).join('')}
          </div>
          <div id="pts-edit-form-${s.season_id}" style="display:none;margin-top:12px">
            ${editInputs}
            <div class="d-flex gap-2 mt-3">
              <button class="btn-ghost" style="color:#22c55e"
                onclick="saveEditPtsMgmt(${s.season_id}, '${escapeHtml(league)}')">
                <i class="bi bi-save me-1"></i>Salvar
              </button>
              <button class="btn-ghost" onclick="toggleEditPtsForm(${s.season_id})">Cancelar</button>
            </div>
          </div>
        </div>`;
    } else {
      return `
        <div class="pun-card mb-2" id="pts-season-${s.season_id}">
          <div class="pun-card-head">
            <div class="pun-card-title">
              <i class="bi bi-clipboard-check" style="color:var(--text-3);margin-right:6px"></i>${escapeHtml(title)}
            </div>
            <div class="d-flex gap-2 align-items-center flex-shrink-0">
              <span class="pun-badge pun-badge-off">Pendente</span>
              <button class="btn-ghost" style="padding:3px 10px;font-size:11px"
                onclick="togglePtsForm(${s.season_id})">
                <i class="bi bi-plus-circle me-1"></i>Registrar
              </button>
            </div>
          </div>
          <div id="pts-form-${s.season_id}" style="display:none;margin-top:12px">
            ${buildPtsForm(s.season_id, league, leagueTeams, 'pts-mgmt-input')}
            <div class="d-flex gap-2 mt-3">
              <button class="btn-ghost" style="color:#22c55e"
                onclick="savePtsMgmt(${s.season_id}, '${escapeHtml(league)}')">
                <i class="bi bi-save me-1"></i>Salvar
              </button>
              <button class="btn-ghost" onclick="togglePtsForm(${s.season_id})">Cancelar</button>
            </div>
          </div>
        </div>`;
    }
  }).join('');

  document.getElementById('ptsMgmtContent').innerHTML =
    html || '<div class="alert alert-info">Nenhuma temporada encontrada.</div>';
}

function togglePtsForm(seasonId) {
  const form = document.getElementById(`pts-form-${seasonId}`);
  if (form) form.style.display = form.style.display === 'none' ? 'block' : 'none';
}

async function savePtsMgmt(seasonId, league) {
  calcPtsPreview(String(seasonId));
  const card = document.getElementById(`pts-season-${seasonId}`);
  if (!card) return;
  const inputs = card.querySelectorAll('.pts-mgmt-input');
  const team_points = Array.from(inputs).map(inp => ({
    team_id: parseInt(inp.dataset.teamId, 10),
    points:  parseInt(inp.value || '0', 10)
  }));

  const summary = team_points
    .filter(tp => tp.points > 0)
    .map(tp => {
      const nameEl = card.querySelector(`.pts-pv-val[data-tid="${tp.team_id}"]`);
      const name = nameEl ? nameEl.closest('div')?.querySelector('span:first-child')?.textContent?.trim() || tp.team_id : tp.team_id;
      return `${name}: ${tp.points} pts`;
    }).join('\n') || '(todos com 0 pontos)';

  if (!await confirmarSite(`Confirmar registro de pontuação para ${league}?\n\n${summary}\n\nEsta ação não poderá ser desfeita.`)) return;

  try {
    await api('history-points.php', {
      method: 'POST',
      body: JSON.stringify({ action: 'save_season_points', season_id: seasonId, league, team_points, series: collectPtsSeries(String(seasonId)) })
    });
    showAlert('success', 'Pontuação salva com sucesso!');
    showPointsManagement(league);
  } catch (e) {
    showAlert('danger', e.error || 'Erro ao salvar pontuação');
  }
}

function toggleEditPtsForm(seasonId) {
  const view = document.getElementById(`pts-view-${seasonId}`);
  const form = document.getElementById(`pts-edit-form-${seasonId}`);
  if (!view || !form) return;
  const isOpen = form.style.display !== 'none';
  view.style.display = isOpen ? 'block' : 'none';
  form.style.display = isOpen ? 'none' : 'block';
}

async function saveEditPtsMgmt(seasonId, league) {
  const card = document.getElementById(`pts-season-${seasonId}`);
  if (!card) return;
  const inputs = card.querySelectorAll('.pts-edit-input');
  const team_points = Array.from(inputs).map(inp => ({
    team_id: parseInt(inp.dataset.teamId, 10),
    points:  parseInt(inp.value || '0', 10)
  }));

  try {
    await api('history-points.php', {
      method: 'POST',
      body: JSON.stringify({ action: 'edit_season_points', season_id: seasonId, league, team_points })
    });
    showAlert('success', 'Pontuação atualizada com sucesso!');
    showPointsManagement(league);
  } catch (e) {
    showAlert('danger', e.error || 'Erro ao atualizar pontuação');
  }
}

async function deletePtsMgmt(seasonId, league) {
  if (!await confirmarSite(`Tem certeza? Isso irá ZERAR todos os pontos desta temporada para a liga ${league} e liberar os locks. O lock do playoff também será removido, permitindo novo cadastro.`)) return;

  try {
    await api('history-points.php', {
      method: 'POST',
      body: JSON.stringify({ action: 'delete_season_points', season_id: seasonId, league })
    });
    showAlert('success', 'Pontos da temporada zerados. Os locks foram liberados.');
    showPointsManagement(league);
  } catch (e) {
    showAlert('danger', e.error || 'Erro ao zerar pontuação');
  }
}

// ── Registro de Pontuação (formulário inteligente) ───────────────────

// ══════════════════════════════════════════════
// PAINEL DE CONTROLE CENTRALIZADO
// ══════════════════════════════════════════════
let _panelLeague = 'ELITE';

/**
 * OS TIMES FORA DA REGRA, na aba da liga.
 *
 * Duas coisas que o admin conferia abrindo time por time: elenco fora do
 * tamanho permitido e cap estourado. A regua vem do servidor (a MESMA do
 * getTeamCapSummary), pra esta tela nunca discordar da tela do time.
 *
 * TODO time fora da regra aparece pelo nome, com quanto falta ou sobra do
 * lado. Chegou a existir um resumo pro "abaixo do piso" quando ele pegava
 * meia liga — a ideia era evitar trinta nomes de ruido no comeco de
 * temporada. Na pratica virou o contrario: o card dizia "30 times abaixo do
 * piso" e nao dizia QUAIS, que e a unica coisa que o admin precisa dali pra
 * cobrar alguem. Trinta linhas com nome e valor sao mais uteis que uma
 * frase com um numero.
 */
async function carregarIrregulares(league) {
  const body = document.getElementById('irregularesBody');
  const resumo = document.getElementById('irrResumo');
  if (!body) return;

  let d;
  try {
    d = await api(`admin.php?action=irregulares&league=${encodeURIComponent(league)}`);
  } catch (e) {
    body.innerHTML = `<div style="font-size:12px;color:var(--text-3)">Não deu pra conferir: ${escapeHtml(e.error || 'erro')}</div>`;
    if (resumo) resumo.textContent = '';
    return;
  }

  const lista = d.irregulares || [];
  const temMotivo = (t, tipo) => (t.motivos || []).some(m => m.tipo === tipo);

  // Os grupos sao EXCLUSIVOS, do mais grave pro menos: um time com elenco
  // curto E acima do cap aparecia duas vezes, com a mesma frase repetida.
  // Ele entra no grupo mais grave e a linha lista os dois motivos.
  const acima  = lista.filter(t => temMotivo(t, 'cap'));
  const elenco = lista.filter(t => temMotivo(t, 'elenco') && !temMotivo(t, 'cap'));
  const piso   = lista.filter(t => temMotivo(t, 'piso') && !temMotivo(t, 'elenco') && !temMotivo(t, 'cap'));

  if (!lista.length) {
    body.innerHTML = '<div style="font-size:12px;color:#25c677"><i class="bi bi-check-circle-fill me-1"></i>' +
      'Nenhum time fora da regra: todos com elenco entre ' + d.elenco_min + ' e ' + d.elenco_max + ' e dentro do cap.</div>';
    if (resumo) resumo.textContent = `0 de ${d.total_times}`;
    return;
  }

  const linha = (t) => {
    // TODOS os motivos, sempre com o nome do time e o valor do lado. Antes o
    // piso era escondido quando pegava muita gente e virava um "30 times
    // abaixo do piso" — que diz que existe problema e nao diz em quem, que e
    // exatamente o que o admin precisa saber pra cobrar.
    const motivos = (t.motivos || []).map(m => {
      const cor = m.tipo === 'elenco' ? '#f59e0b' : (m.tipo === 'cap' ? '#ef4444' : '#94a3b8');
      return `<span style="color:${cor};font-weight:700">${escapeHtml(m.texto)}</span>` +
             (m.detalhe ? `<span style="color:var(--text-3);font-size:11px"> (${escapeHtml(m.detalhe)})</span>` : '');
    }).join('<span style="color:var(--text-3)"> · </span>');
    return `<div class="irr-linha">
      <span class="irr-nome">${escapeHtml(t.nome)}</span>
      <span class="irr-motivos">${motivos}</span>
    </div>`;
  };

  const bloco = (titulo, itens) => itens.length
    ? `<div class="irr-grupo"><div class="irr-grupo-tit">${titulo}</div>${itens.map(linha).join('')}</div>` : '';

  const contados = lista.length;
  body.innerHTML =
    bloco('Acima do cap', acima) +
    bloco(`Elenco fora de ${d.elenco_min}–${d.elenco_max}`, elenco) +
    bloco('Abaixo do piso', piso) +
    `<style>
      .irr-grupo + .irr-grupo{margin-top:12px}
      .irr-grupo-tit{font-size:10px;font-weight:800;letter-spacing:.9px;text-transform:uppercase;
        color:var(--text-3);margin-bottom:6px}
      .irr-linha{display:flex;align-items:baseline;gap:10px;flex-wrap:wrap;padding:5px 0;font-size:13px}
      .irr-linha + .irr-linha{border-top:1px solid var(--border)}
      .irr-nome{font-weight:600;color:var(--text);min-width:150px}
      .irr-motivos{font-size:12px}
    </style>`;

  if (resumo) {
    resumo.innerHTML = `<b style="color:${contados ? '#f59e0b' : 'var(--text-3)'}">${contados}</b> de ${d.total_times} times`;
  }
}

async function showControlPanel(league) {
  league = league || appState.currentLeague || 'ELITE';
  _panelLeague = league;
  appState.view = 'controlpanel';
  updateBreadcrumb();
  const container = document.getElementById('mainContainer');
  const back = appState.currentLeague ? `showLeague('${appState.currentLeague}')` : 'showHome()';
  container.innerHTML = `
    <div class="mb-4">
      <button class="btn btn-back me-2" onclick="${back}"><i class="bi bi-arrow-left"></i> Voltar</button>
      <span class="text-light-gray" style="font-size:14px;font-weight:600">Painel de Controle — ${escapeHtml(league)}</span>
    </div>
    <div id="panelContent"><div class="text-center py-4"><div class="spinner-border" style="color:var(--red)"></div></div></div>`;
  loadControlPanel();
}

async function loadControlPanel() {
  const league = _panelLeague;
  const box = document.getElementById('panelContent');
  try {
    const d = await api(`admin-control.php?league=${encodeURIComponent(league)}`);
    const tOn = d.trades_enabled == 1;
    const faOn = d.fa_enabled == 1;
    const tw = d.tactic_window;
    const pct = d.teams_total ? Math.round((d.teams_updated / d.teams_total) * 100) : 100;
    const naoAtualizados = d.teams_not_updated || [];

    box.innerHTML = `
      <div class="row g-3">
        <div class="col-md-4">
          <div class="pun-card">
            <div class="d-flex align-items-center justify-content-between mb-2">
              <span style="font-weight:600;color:var(--text)"><i class="bi bi-arrow-left-right me-2"></i>Trades</span>
              <span id="tradesBadge_${league}" style="font-size:10px;font-weight:700;padding:1px 7px;border-radius:999px;white-space:nowrap;${tOn
                ? 'background:rgba(37,198,119,.15);color:#25c677;border:1px solid rgba(37,198,119,.25)'
                : 'background:color-mix(in srgb, var(--red) 12%, transparent);color:var(--red);border:1px solid var(--border-red)'}">${tOn ? 'Ativas' : 'Bloqueadas'}</span>
            </div>
            <div class="d-flex gap-2">
              <button id="tradesOnBtn_${league}" class="btn btn-sm ${tOn ? 'btn-success' : 'btn-outline-success'} flex-grow-1" onclick="toggleTrades('${league}', 1)">Ativas</button>
              <button id="tradesOffBtn_${league}" class="btn btn-sm ${!tOn ? 'btn-danger' : 'btn-outline-danger'} flex-grow-1" onclick="toggleTrades('${league}', 0)">Bloqueadas</button>
            </div>
          </div>
        </div>
        <div class="col-md-4">
          <div class="pun-card">
            <div class="d-flex align-items-center justify-content-between mb-2">
              <span style="font-weight:600;color:var(--text)"><i class="bi bi-people-fill me-2"></i>Free Agency</span>
              <span id="faBadge_${league}" style="font-size:10px;font-weight:700;padding:1px 7px;border-radius:999px;white-space:nowrap;${faOn
                ? 'background:rgba(37,198,119,.15);color:#25c677;border:1px solid rgba(37,198,119,.25)'
                : 'background:color-mix(in srgb, var(--red) 12%, transparent);color:var(--red);border:1px solid var(--border-red)'}">${faOn ? 'Ativa' : 'Bloqueada'}</span>
            </div>
            <div class="d-flex gap-2">
              <button id="faOnBtn_${league}" class="btn btn-sm ${faOn ? 'btn-success' : 'btn-outline-success'} flex-grow-1" onclick="toggleFA('${league}', 1)">Ativa</button>
              <button id="faOffBtn_${league}" class="btn btn-sm ${!faOn ? 'btn-danger' : 'btn-outline-danger'} flex-grow-1" onclick="toggleFA('${league}', 0)">Bloqueada</button>
            </div>
          </div>
        </div>
        <div class="col-md-4">
          <div class="pun-card">
            <div class="d-flex align-items-center justify-content-between mb-2">
              <span style="font-weight:600;color:var(--text)"><i class="bi bi-clipboard-data me-2"></i>Edição de Táticas</span>
              <span style="font-size:10px;font-weight:700;padding:1px 7px;border-radius:999px;white-space:nowrap;${tw.open
                ? 'background:rgba(37,198,119,.15);color:#25c677;border:1px solid rgba(37,198,119,.25)'
                : 'background:color-mix(in srgb, var(--red) 12%, transparent);color:var(--red);border:1px solid var(--border-red)'}">${tw.open ? 'Aberta' : 'Fechada'}</span>
            </div>
            <div style="font-size:11px;color:var(--text-3);margin-bottom:8px">${tw.reason ? escapeHtml(tw.reason) : 'aberta — os times podem editar'}</div>
            <div class="d-flex gap-1 flex-wrap">
              <button class="btn btn-sm btn-outline-success" onclick="panelToggleTatica(true)">Abrir</button>
              <button class="btn btn-sm btn-outline-danger" onclick="panelToggleTatica(false)">Fechar</button>
            </div>
          </div>
        </div>
      </div>

      <div class="panel mt-3">
        <div class="panel-header">
          <div class="panel-title"><i class="bi bi-clipboard2-check me-2"></i>Elencos Atualizados</div>
          ${d.draft_concluido ? `<span style="font-weight:700;color:${pct === 100 ? '#25c677' : 'var(--red)'}">${d.teams_updated}/${d.teams_total} (${pct}%)</span>` : ''}
        </div>
        ${d.draft_concluido ? `<div class="progress mb-2" style="height:6px;background:var(--panel-3)">
          <div class="progress-bar" style="width:${pct}%;background:${pct === 100 ? '#25c677' : 'var(--red)'}"></div>
        </div>` : ''}
        ${!d.draft_concluido
          ? '<div style="font-size:12px;color:var(--text-3)"><i class="bi bi-info-circle me-1"></i>O draft desta temporada ainda não terminou — a cobrança de atualização de elenco ainda não começou.</div>'
          : (naoAtualizados.length
              ? `<div style="font-size:12px;color:var(--text-3);margin-bottom:6px">Ainda não atualizaram:</div>
                 <div class="d-flex flex-wrap gap-2">${naoAtualizados.map(nm => `<span class="badge bg-secondary">${escapeHtml(nm)}</span>`).join('')}</div>`
              : '<div style="font-size:12px;color:#25c677"><i class="bi bi-check-circle-fill me-1"></i>Todos os times já atualizaram o elenco nesta temporada.</div>')}
      </div>

      <div class="d-flex gap-2 mt-3 flex-wrap">
        <button class="btn-ghost" onclick="abrirControleDrafts('${league}')"><i class="bi bi-shuffle me-1"></i>Controle Drafts</button>
        <button class="btn-ghost" onclick="showConfig()"><i class="bi bi-gear me-1"></i>Configurações da Liga</button>
        <button class="btn-ghost" onclick="showTaticaAdmin()"><i class="bi bi-clipboard-data me-1"></i>Tática por Time</button>
      </div>`;
  } catch (e) {
    box.innerHTML = `<div class="alert alert-danger">Erro: ${escapeHtml(e.error || 'Desconhecido')}</div>`;
  }
}

async function panelToggleTatica(abrir) {
  try {
    await api('tactics.php', {
      method: 'POST',
      body: JSON.stringify({ action: 'admin_window', league: _panelLeague, aberta: !!abrir }),
    });
    showAlert('success', abrir ? 'Edição de táticas aberta.' : 'Edição de táticas fechada.');
    loadControlPanel();
  } catch (e) { showAlert('danger', e.error || 'Erro ao mudar a janela'); }
}

// ══════════════════════════════════════════════
/** Abre o controle de drafts já na aba da liga de onde saiu o clique. */
function abrirControleDrafts(league) {
  window.location.href = '/controledrafts.php?league=' + encodeURIComponent(league);
}

/* A cerimônia da loteria daquela liga, com os controles.
   A liga vai na URL porque é por ela que a tela decide se mostra o botão de
   sortear: fora daqui, lottery.php é a loteria de quem está olhando. */
function abrirLoteria(league) {
  window.location.href = '/lottery.php?liga=' + encodeURIComponent(league);
}

/**
 * Recria as picks que faltam na janela de anos da liga.
 *
 * A janela vai do ano corrente aos cinco seguintes, MAIS o ano que o draft
 * aberto está distribuindo. Esse ano é o motivo de o botão existir: a
 * temporada vira antes de o draft acontecer, e o ano dele caía fora da
 * janela — cada time perdia a PRÓPRIA escolha do draft em andamento e ficava
 * só com as que tinha comprado, que escapam por não serem auto-geradas.
 */
async function ajustarPicksDaLiga(league) {
  if (!confirm(`Ajustar as picks da ${league}?\n\n`
             + 'Devolve as escolhas que faltam: as do draft em andamento e as da janela de anos futuros. '
             + 'Picks já negociadas não são tocadas.')) return;
  try {
    const d = await api('seasons.php?action=run_picks', {
      method: 'POST',
      body: JSON.stringify({ league })
    });
    const s = d.stats || {};
    alert(`${d.message || 'Picks ajustadas.'}\n\n`
        + `Anos: ${(d.target_years || []).join(', ')}\n`
        + `Criadas: ${s.created || 0} · Realocadas: ${s.renamed || 0} · `
        + `Removidas: ${s.deleted || 0} · Mantidas: ${s.kept || 0}`);
  } catch (e) {
    alert('Não deu pra ajustar as picks: ' + (e.error || 'erro desconhecido'));
  }
}

// AGENDADOR DE FASES (fechar/abrir trades, fechar FA)
// ══════════════════════════════════════════════
let _schedLeague = 'ELITE';
const SCHED_LABELS = { trades_close:'Fechar Trades', trades_open:'Abrir Trades', fa_close:'Fechar Free Agency', fa_open:'Abrir Free Agency' };

async function showScheduler(league) {
  league = league || appState.currentLeague || 'ELITE';
  _schedLeague = league;
  appState.view = 'scheduler';
  updateBreadcrumb();
  const container = document.getElementById('mainContainer');
  const back = appState.currentLeague ? `showLeague('${appState.currentLeague}')` : 'showHome()';
  container.innerHTML = `
    <div class="mb-4">
      <button class="btn btn-back me-2" onclick="${back}"><i class="bi bi-arrow-left"></i> Voltar</button>
      <span class="text-light-gray" style="font-size:14px;font-weight:600">Agendador de Fases — ${escapeHtml(league)}</span>
    </div>
    <div class="panel mb-3">
      <div style="font-size:12px;color:var(--text-3);margin-bottom:12px"><i class="bi bi-info-circle me-1"></i>Defina o horário e o sistema executa sozinho (via o cron): fechar/abrir trades e fechar a Free Agency (resolve cada oferta pro maior lance, respeitando prioridade, saldo e o limite de 3). As dispensas já resolvem sozinhas nos 12h.</div>
      <div id="schedTradesStatus" style="margin-bottom:14px"></div>
      <div class="row g-2" style="align-items:flex-end">
        <div class="col-auto"><label class="pun-field-label">Ação</label>
          <select class="form-select" id="schedType" style="min-width:180px">
            <option value="trades_close">Fechar Trades</option>
            <option value="trades_open">Abrir Trades</option>
            <option value="fa_close">Fechar Free Agency (resolve ofertas)</option>
            <option value="fa_open">Abrir Free Agency</option>
          </select>
        </div>
        <div class="col-auto"><label class="pun-field-label">Data e hora</label>
          <input type="datetime-local" class="form-control" id="schedRunAt" style="min-width:220px">
        </div>
        <div class="col-auto"><button class="btn-orange" onclick="createSchedEvent()"><i class="bi bi-plus-lg me-1"></i>Agendar</button></div>
      </div>
    </div>
    <div class="d-flex justify-content-between align-items-center" style="margin-bottom:10px">
      <div class="text-light-gray" style="font-size:13px;font-weight:600">Eventos</div>
      <button class="btn btn-sm btn-outline-secondary" onclick="runSchedDue()"><i class="bi bi-play-fill"></i> Rodar vencidos agora</button>
    </div>
    <div id="schedList"><div class="text-center py-4"><div class="spinner-border" style="color:var(--red)"></div></div></div>`;
  loadSchedEvents();
}

async function loadSchedEvents() {
  const box = document.getElementById('schedList');
  try {
    const d = await api(`scheduler.php?league=${encodeURIComponent(_schedLeague)}`);
    const on = Number(d.trades_enabled) === 1;
    document.getElementById('schedTradesStatus').innerHTML =
      `Trades agora: <span class="badge ${on?'bg-success':'bg-danger'}">${on?'Abertas':'Fechadas'}</span>`;
    const ev = d.events || [];
    if (!ev.length) { box.innerHTML = '<div class="alert alert-info">Nenhum evento agendado.</div>'; return; }
    box.innerHTML = ev.map(e => {
      const label = SCHED_LABELS[e.type] || e.type;
      const when = (e.run_at || '').replace('T', ' ').slice(0, 16);
      let st, right = '';
      if (e.status === 'pending') {
        const sl = Number(e.seconds_left);
        const tag = sl > 0 ? `em ${Math.floor(sl/3600)}h${Math.floor((sl%3600)/60)}m` : 'vencido (aguardando cron)';
        st = `<span class="badge bg-warning text-dark">Agendado</span> <span style="font-size:11px;color:var(--text-3)">${tag}</span>`;
        right = `<button class="btn btn-sm btn-outline-danger" onclick="cancelSchedEvent(${e.id})">Cancelar</button>`;
      } else if (e.status === 'done') {
        st = `<span class="badge bg-success">Executado</span> <span style="font-size:11px;color:var(--text-3)">${escapeHtml(e.result||'')}</span>`;
      } else if (e.status === 'failed') {
        st = `<span class="badge bg-danger">Falhou</span> <span style="font-size:11px;color:var(--text-3)">${escapeHtml(e.result||'')}</span>`;
      } else {
        st = `<span class="badge bg-secondary">Cancelado</span>`;
      }
      return `<div class="pun-card mb-2" style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
        <div><div style="font-weight:600;color:var(--text)">${label}</div><div style="font-size:12px;color:var(--text-3)"><i class="bi bi-calendar-event me-1"></i>${when}</div></div>
        <div style="margin-left:auto;text-align:right">${st}</div>
        <div>${right}</div>
      </div>`;
    }).join('');
  } catch (e) {
    box.innerHTML = `<div class="alert alert-danger">Erro: ${escapeHtml(e.error || 'Desconhecido')}</div>`;
  }
}

async function createSchedEvent() {
  const type = document.getElementById('schedType').value;
  const runAt = document.getElementById('schedRunAt').value;
  if (!runAt) { showAlert('warning', 'Escolha a data e hora.'); return; }
  try {
    await api('scheduler.php', { method: 'POST', body: JSON.stringify({ action: 'create', league: _schedLeague, type, run_at: runAt }) });
    showAlert('success', 'Evento agendado.');
    loadSchedEvents();
  } catch (e) { showAlert('danger', e.error || 'Erro ao agendar'); }
}

async function cancelSchedEvent(id) {
  if (!await confirmarSite('Cancelar este evento agendado?')) return;
  try { await api('scheduler.php', { method: 'POST', body: JSON.stringify({ action: 'cancel', id }) }); loadSchedEvents(); }
  catch (e) { showAlert('danger', e.error || 'Erro'); }
}

async function runSchedDue() {
  try {
    const d = await api('scheduler.php', { method: 'POST', body: JSON.stringify({ action: 'run_due' }) });
    showAlert('success', `Executados: ${d.done||0} · falhas: ${d.failed||0}`);
    loadSchedEvents();
  } catch (e) { showAlert('danger', e.error || 'Erro'); }
}

// ══════════════════════════════════════════════
// PRÊMIOS ESTENDIDOS (Finals MVP, All-NBA, All-Defensive)
// ══════════════════════════════════════════════
let _extLeague = 'ELITE', _extSeasons = [], _extTeams = [], _extSeasonId = null;

async function showExtendedAwards(league) {
  league = league || appState.currentLeague || 'ELITE';
  _extLeague = league;
  appState.view = 'extawards';
  updateBreadcrumb();
  const container = document.getElementById('mainContainer');
  const back = appState.currentLeague ? `showLeague('${appState.currentLeague}')` : 'showHome()';
  container.innerHTML = `
    <div class="mb-4">
      <button class="btn btn-back me-2" onclick="${back}"><i class="bi bi-arrow-left"></i> Voltar</button>
      <span class="text-light-gray" style="font-size:14px;font-weight:600">Prêmios Estendidos — ${escapeHtml(league)}</span>
    </div>
    <div class="panel mb-3">
      <div style="font-size:12px;color:var(--text-3);margin-bottom:12px"><i class="bi bi-info-circle me-1"></i>Finals MVP, All-NBA (1º/2º/3º) e All-Defensive (1º/2º). ${league === 'ELITE'
        ? 'Cada bônus vale <b>só na temporada seguinte</b>, somando ao salário base no cap.'
        : 'O salary cap é exclusivo da ELITE, então aqui é só para registro histórico — sem efeito em cap ou salário.'} Preencha time + jogador em cada vaga.</div>
      <div style="max-width:360px">
        <label class="pun-field-label">Temporada</label>
        <select class="form-select" id="extSeasonSel" onchange="loadExtendedAwards()"></select>
      </div>
    </div>
    <div id="extContent"><div class="text-center py-4"><div class="spinner-border" style="color:var(--red)"></div></div></div>`;
  try {
    const data = await api(`extended-awards.php?league=${encodeURIComponent(league)}`);
    _extSeasons = data.seasons || []; _extTeams = data.teams || [];
    const sel = document.getElementById('extSeasonSel');
    sel.innerHTML = _extSeasons.map(s => `<option value="${s.id}">${escapeHtml(s.year || ('T' + s.season_number))} (T${s.season_number})</option>`).join('');
    if (_extSeasons.length) loadExtendedAwards();
    else document.getElementById('extContent').innerHTML = '<div class="alert alert-info">Nenhuma temporada nesta liga.</div>';
  } catch (e) {
    document.getElementById('extContent').innerHTML = `<div class="alert alert-danger">Erro: ${escapeHtml(e.error || 'Desconhecido')}</div>`;
  }
}

async function loadExtendedAwards() {
  const seasonId = document.getElementById('extSeasonSel').value;
  _extSeasonId = seasonId;
  const box = document.getElementById('extContent');
  box.innerHTML = '<div class="text-center py-4"><div class="spinner-border" style="color:var(--red)"></div></div>';
  let existing = [];
  try { const d = await api(`extended-awards.php?league=${encodeURIComponent(_extLeague)}&season_id=${seasonId}`); existing = d.awards || []; } catch (e) {}
  const byType = {};
  existing.forEach(a => { (byType[a.award_type] ||= []).push(a); });
  const teamOpts = sel => `<option value="">— time —</option>` + _extTeams.map(t => `<option value="${t.id}" ${String(sel) === String(t.id) ? 'selected' : ''}>${escapeHtml(t.name)}</option>`).join('');
  const row = (type, idx) => {
    const ex = (byType[type] || [])[idx] || {};
    return `<div class="ext-row" data-type="${type}" style="display:flex;gap:8px;margin-bottom:6px">
      <select class="form-select form-select-sm ext-team" style="max-width:230px">${teamOpts(ex.team_id)}</select>
      <input class="form-control form-control-sm ext-player" placeholder="Nome do jogador" value="${escapeHtml(ex.player_name || '')}">
    </div>`;
  };
  const isElite = _extLeague === 'ELITE';
  // Nas demais ligas não há salary cap, então o bônus (+XM) e a contagem de
  // vagas somem do título — o cadastro vira só registro histórico do prêmio.
  const detail = d => isElite ? ` <span style="color:var(--text-3);font-weight:400;font-size:11px">(${d})</span>` : '';
  const section = (title, type, n) => `
    <div class="panel mb-3">
      <div style="font-size:13px;font-weight:700;color:var(--text);margin-bottom:10px">${title}</div>
      ${Array.from({ length: n }, (_, i) => row(type, i)).join('')}
    </div>`;
  box.innerHTML = `
    ${section('🏆 Finals MVP' + detail('+3M'), 'finals_mvp', 1)}
    ${section('All-NBA — 1º Time' + detail('+3M · 5 jogadores'), 'all_nba_1', 5)}
    ${section('All-NBA — 2º Time' + detail('+2M · 5 jogadores'), 'all_nba_2', 5)}
    ${section('All-NBA — 3º Time' + detail('+1M · 5 jogadores'), 'all_nba_3', 5)}
    ${section('All-Defensive — 1º Time' + detail('+2M · 5 jogadores'), 'all_def_1', 5)}
    ${section('All-Defensive — 2º Time' + detail('+1M · 5 jogadores'), 'all_def_2', 5)}
    <button class="btn-orange" onclick="saveExtendedAwards()"><i class="bi bi-save me-1"></i>Salvar prêmios estendidos</button>`;
}

async function saveExtendedAwards() {
  const awards = [...document.querySelectorAll('.ext-row')]
    .map(r => ({ award_type: r.dataset.type, team_id: r.querySelector('.ext-team').value, player_name: r.querySelector('.ext-player').value.trim() }))
    .filter(a => a.team_id && a.player_name);
  try {
    const d = await api('extended-awards.php', { method: 'POST', body: JSON.stringify({ season_id: _extSeasonId, awards }) });
    showAlert('success', `Prêmios estendidos salvos (${d.saved} registro${d.saved === 1 ? '' : 's'}).`);
  } catch (e) {
    showAlert('danger', e.error || 'Erro ao salvar prêmios');
  }
}

async function showRegistroPontuacao(league) {
  league = league || appState.currentLeague || 'ELITE';
  appState.view = 'pontuacao';
  updateBreadcrumb();

  const container = document.getElementById('mainContainer');
  const back = appState.currentLeague ? `showLeague('${appState.currentLeague}')` : 'showHome()';

  container.innerHTML = `
    <div class="mb-4">
      <button class="btn btn-back me-2" onclick="${back}"><i class="bi bi-arrow-left"></i> Voltar</button>
      <span class="text-light-gray" style="font-size:14px;font-weight:600">Pontuação — ${league}</span>
    </div>
    <div id="regPtsContent"><div class="text-center py-5"><div class="spinner-border text-orange"></div></div></div>`;

  let overviewData;
  try {
    overviewData = await api(`history-points.php?action=get_league_seasons_overview&league=${encodeURIComponent(league)}`);
  } catch(e) {
    document.getElementById('regPtsContent').innerHTML = `<div class="alert alert-danger">Erro ao carregar dados.</div>`;
    return;
  }

  const leagueTeams = overviewData?.league_teams || [];
  const seasons     = overviewData?.seasons     || [];

  // Pega a temporada mais recente ainda não registrada
  const pending = [...seasons].reverse().find(s => !s.points_registered);
  // Também carrega a já registrada mais recente para referência
  const registered = [...seasons].reverse().find(s => s.points_registered);

  if (!leagueTeams.length) {
    document.getElementById('regPtsContent').innerHTML = `<div class="alert alert-warning">Nenhum time encontrado para ${league}.</div>`;
    return;
  }

  const fmtTitle = s => [
    s.sprint_number ? `Sprint ${s.sprint_number}` : '',
    s.season_number ? `Temporada ${s.season_number}` : '',
    s.year          ? String(s.year)                 : ''
  ].filter(Boolean).join(' · ');

  let html = '';

  if (pending) {
    html += `
      <div class="panel mb-3">
        <div class="panel-header">
          <div class="panel-title"><i class="bi bi-clipboard-data-fill" style="color:#10b981"></i> ${escapeHtml(fmtTitle(pending))}</div>
          <span class="pun-badge pun-badge-off">Pendente</span>
        </div>
        <div id="pts-form-${pending.season_id}">
          ${buildPtsForm(pending.season_id, league, leagueTeams, 'reg-save-pts-input')}
        </div>
        <div class="mt-4">
          <button class="btn-orange" onclick="_regPtsSave(${pending.season_id}, '${league}')">
            <i class="bi bi-save me-2"></i>Registrar Pontuação
          </button>
        </div>
      </div>`;
  } else {
    html += `<div class="alert alert-info"><i class="bi bi-check-circle me-2"></i>Todas as temporadas desta liga já têm pontuação registrada. Use "Pontuação por Time" para editar.</div>`;
  }

  // Referência visual das regras
  html += `
    <div class="panel">
      <div class="panel-title"><i class="bi bi-calculator-fill" style="color:var(--text-3)"></i> Sistema de Pontuação</div>
      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:14px;font-size:12px">
        <div>
          <div style="color:#eab308;font-weight:700;margin-bottom:6px">Playoffs</div>
          <div style="color:var(--text-2);line-height:2">
            Campeão: <strong style="color:var(--text)">7 pts</strong><br>
            Vice-Campeão: <strong style="color:var(--text)">4 pts</strong><br>
            Final de Conf.: <strong style="color:var(--text)">3 pts</strong><br>
            2º turno: <strong style="color:var(--text)">1 pt</strong><br>
            <span style="font-size:11px">1ª rodada não pontua · valores acumulados</span>
          </div>
        </div>
        <div>
          <div style="color:#06b6d4;font-weight:700;margin-bottom:6px">Temporada Regular</div>
          <div style="color:var(--text-2);line-height:2">
            1º e 2º: <strong style="color:var(--text)">+5 pts</strong><br>
            3º e 4º: <strong style="color:var(--text)">+4 pts</strong><br>
            5º e 6º: <strong style="color:var(--text)">+3 pts</strong><br>
            7º e 8º: <strong style="color:var(--text)">+2 pts</strong><br>
            9º e 10º: <strong style="color:var(--text)">+1 pt</strong>
          </div>
        </div>
        <div>
          <div style="color:#22c55e;font-weight:700;margin-bottom:6px">Prêmios Individuais</div>
          <div style="color:var(--text-2);line-height:2">
            MVP / DPOY / MIP / 6º Homem / ROY:<br>
            <strong style="color:var(--text)">+1 pt cada</strong>
          </div>
        </div>
        ${league === 'ELITE' ? `
        <div>
          <div style="color:#f59e0b;font-weight:700;margin-bottom:6px">NBA Cup <span style="font-size:10px;color:var(--text-3)">(ELITE)</span></div>
          <div style="color:var(--text-2);line-height:2">
            Campeão: <strong style="color:var(--text)">+2 pts</strong>
          </div>
        </div>` : ''}
      </div>
    </div>`;

  document.getElementById('regPtsContent').innerHTML = html;
  if (pending) calcPtsPreview(String(pending.season_id));
}

function _regPtsRecalcForTeam(teamId) {
  let pts = 0;
  const reg = document.querySelector(`.reg-season-sel[data-team-id="${teamId}"]`);
  if (reg) pts += parseInt(reg.value || '0', 10);
  const po = document.querySelector(`.playoff-sel[data-team-id="${teamId}"]`);
  if (po) pts += parseInt(po.value || '0', 10);
  document.querySelectorAll('.award-sel').forEach(s => {
    if (String(s.value) === String(teamId)) pts += 1;
  });
  const nbaCup = document.getElementById('nbaCupWinner');
  if (nbaCup && String(nbaCup.value) === String(teamId)) pts += 2;
  const el = document.getElementById(`rpt-${teamId}`);
  if (el) el.textContent = pts;
  return pts;
}

function _regPtsRecalc() {
  document.querySelectorAll('.reg-season-sel').forEach(s => _regPtsRecalcForTeam(s.dataset.teamId));
}

async function _regPtsSave(seasonId, league) {
  // Garante que os totais (incluindo prêmios individuais) estejam atualizados nos hidden inputs
  calcPtsPreview(String(seasonId));

  const card = document.getElementById(`pts-form-${seasonId}`);
  if (!card) return;

  const teamPoints = Array.from(card.querySelectorAll('.reg-save-pts-input')).map(inp => ({
    team_id: parseInt(inp.dataset.teamId, 10),
    points:  parseInt(inp.value || '0', 10)
  }));

  const summary = teamPoints
    .filter(tp => tp.points > 0)
    .map(tp => {
      const nameEl = card.querySelector(`.pts-pv-val[data-tid="${tp.team_id}"]`);
      const name = nameEl ? nameEl.closest('div')?.querySelector('span:first-child')?.textContent?.trim() || tp.team_id : tp.team_id;
      return `${name}: ${tp.points} pts`;
    }).join('\n') || '(todos com 0 pontos)';

  if (!await confirmarSite(`Confirmar registro de pontuação para ${league}?\n\n${summary}\n\nEsta ação não poderá ser desfeita.`)) return;

  try {
    await api('history-points.php', {
      method: 'POST',
      body: JSON.stringify({ action: 'save_season_points', season_id: seasonId, league, team_points: teamPoints, series: collectPtsSeries(String(seasonId)) })
    });
    showAlert('success', 'Pontuação registrada com sucesso!');
    showRegistroPontuacao(league);
  } catch(e) {
    showAlert('danger', e.error || 'Erro ao registrar pontuação');
  }
}

// ── FBA SERASA Admin ─────────────────────────────────────────────────

async function showSerasaAdmin() {
  const league = appState.currentLeague || 'ELITE';
  const container = document.getElementById('mainContainer');
  container.innerHTML = '<div class="text-center py-5"><div class="spinner-border" style="color:var(--red)"></div></div>';

  try {
    // Gera avisos automáticos para trades pendentes > 24h
    let novoAvisos = 0;
    try {
      const chk = await api('admin.php?action=check_overdue_trades', {
        method: 'POST',
        body: JSON.stringify({ league })
      });
      novoAvisos = chk.avisos_gerados || 0;
    } catch (_) {}

    const data = await api(`admin.php?action=teams&league=${league}`);
    const teams = (data.teams || []).slice().sort((a, b) => (parseInt(b.avisos_count||0)) - (parseInt(a.avisos_count||0)));

    const getScore = (n) => {
      if (n <= 2) return { label: 'Excelente', color: '#22c55e', bg: 'rgba(34,197,94,.10)',  border: 'rgba(34,197,94,.3)'  };
      if (n <= 4) return { label: 'Bom',       color: '#3b82f6', bg: 'rgba(59,130,246,.10)', border: 'rgba(59,130,246,.3)' };
      if (n <= 6) return { label: 'Regular',   color: '#eab308', bg: 'rgba(234,179,8,.10)',  border: 'rgba(234,179,8,.3)'  };
      if (n <= 8) return { label: 'Ruim',      color: '#f97316', bg: 'rgba(249,115,22,.10)', border: 'rgba(249,115,22,.3)' };
      return              { label: 'Péssimo',  color: '#ef4444', bg: 'rgba(239,68,68,.10)',  border: 'rgba(239,68,68,.3)'  };
    };

    const rows = teams.map(t => {
      const n = parseInt(t.avisos_count || 0);
      const s = getScore(n);
      return `
        <div class="pun-card" style="display:flex;align-items:center;gap:12px;padding:10px 14px" id="serasa-row-${t.id}">
          <img src="${escapeHtml(t.photo_url || '/img/default-team.png')}" style="width:36px;height:36px;border-radius:50%;object-fit:cover;border:1px solid var(--border-md)" onerror="this.src='/img/default-team.png'">
          <div style="flex:1;min-width:0">
            <div style="font-size:13px;font-weight:600;color:var(--text)">${escapeHtml(t.city)} ${escapeHtml(t.name)}</div>
            <div style="font-size:11px;color:var(--text-3)">${escapeHtml(t.owner_name)}</div>
          </div>
          <div style="display:flex;align-items:center;gap:8px;flex-shrink:0" id="serasa-info-${t.id}">
            <span style="font-size:11px;color:var(--text-3)">${n} aviso${n !== 1 ? 's' : ''}</span>
            <span style="padding:2px 9px;border-radius:20px;font-size:11px;font-weight:700;color:${s.color};background:${s.bg};border:1px solid ${s.border}">${s.label}</span>
            <button class="btn-ghost" style="padding:2px 8px;font-size:11px" title="Editar avisos" onclick="_serasaEditAvisos(${t.id}, '${escapeHtml(league)}', ${n})"><i class="bi bi-pencil"></i></button>
          </div>
        </div>`;
    }).join('');

    const legend = [
      ['#22c55e','rgba(34,197,94,.3)','Excelente (0–2)'],
      ['#3b82f6','rgba(59,130,246,.3)','Bom (3–4)'],
      ['#eab308','rgba(234,179,8,.3)','Regular (5–6)'],
      ['#f97316','rgba(249,115,22,.3)','Ruim (7–8)'],
      ['#ef4444','rgba(239,68,68,.3)','Péssimo (9+)'],
    ].map(([c, b, l]) => `<span style="padding:2px 8px;border-radius:20px;font-size:11px;font-weight:600;color:${c};background:${c}1a;border:1px solid ${b}">${l}</span>`).join('');

    const novoAvisoBanner = novoAvisos > 0
      ? `<div style="background:rgba(239,68,68,.10);border:1px solid rgba(239,68,68,.3);border-radius:10px;padding:10px 14px;margin-bottom:14px;font-size:13px;color:#ef4444;display:flex;align-items:center;gap:8px">
          <i class="bi bi-exclamation-triangle-fill"></i>
          <span><strong>${novoAvisos} aviso${novoAvisos > 1 ? 's' : ''}</strong> gerado${novoAvisos > 1 ? 's' : ''} automaticamente por trade${novoAvisos > 1 ? 's' : ''} pendente${novoAvisos > 1 ? 's' : ''} há mais de 24h.</span>
        </div>`
      : '';

    container.innerHTML = `
      <div class="mb-4 d-flex align-items-center justify-content-between flex-wrap gap-2">
        <button class="btn btn-back" onclick="showLeague('${league}')"><i class="bi bi-arrow-left"></i> Voltar</button>
        <button class="btn btn-outline-danger btn-sm" onclick="zerarSerasaDaLiga('${league}')">
          <i class="bi bi-arrow-counterclockwise me-1"></i>Zerar avisos da ${league}
        </button>
      </div>
      ${novoAvisoBanner}
      <div class="panel">
        <div class="panel-header">
          <div class="panel-title"><i class="bi bi-shield-check" style="color:#8b5cf6"></i> FBA SERASA — ${league}</div>
          <span style="font-size:12px;color:var(--text-3)">${teams.length} times</span>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px">${legend}</div>
        <div>${rows || '<p class="empty-state">Nenhum time encontrado.</p>'}</div>
      </div>`;
  } catch (e) {
    container.innerHTML = `<div class="alert alert-danger">Erro: ${escapeHtml(e.error || 'Desconhecido')}</div>`;
  }
}

async function zerarSerasaDaLiga(league) {
  if (typeof window.zerarPunicoesEAvisos !== 'function') return;
  await window.zerarPunicoesEAvisos(league);
  showSerasaAdmin();
}

function _serasaEditAvisos(teamId, league, current) {
  const infoEl = document.getElementById(`serasa-info-${teamId}`);
  if (!infoEl) return;

  infoEl.innerHTML = `
    <input id="_serasaInput_${teamId}" type="number" min="0" max="99" value="${current}"
      style="width:60px;padding:3px 6px;font-size:13px;background:var(--panel-2);border:1px solid var(--border-red);border-radius:8px;color:var(--text);text-align:center">
    <button class="btn-ghost" style="padding:3px 10px;font-size:12px;color:#22c55e;border-color:rgba(34,197,94,.3)"
      onclick="_serasaSaveAvisos(${teamId}, '${league}')"><i class="bi bi-check-lg"></i></button>
    <button class="btn-ghost" style="padding:3px 10px;font-size:12px"
      onclick="showSerasaAdmin()"><i class="bi bi-x-lg"></i></button>`;

  document.getElementById(`_serasaInput_${teamId}`)?.focus();
}

async function _adminCardAvisosAdj(teamId, league, delta, btn) {
  const spanEl = document.getElementById(`avisos-count-${teamId}`);
  const current = parseInt(spanEl?.textContent ?? '0', 10);
  const newCount = Math.max(0, current + delta);
  if (newCount === current) return;
  btn.disabled = true;
  try {
    const res = await api('admin.php?action=set_team_avisos', {
      method: 'POST',
      body: JSON.stringify({ team_id: teamId, league, count: newCount })
    });
    const n = res.count;
    if (spanEl) {
      spanEl.textContent = n;
      spanEl.style.color = n > 0 ? '#f43f5e' : 'var(--text-2)';
    }
  } catch (e) {
    showAlert('danger', e.error || 'Erro ao atualizar avisos');
  } finally {
    btn.disabled = false;
  }
}

async function _serasaSaveAvisos(teamId, league) {
  const input = document.getElementById(`_serasaInput_${teamId}`);
  const count = parseInt(input?.value ?? '-1', 10);
  if (isNaN(count) || count < 0) { showAlert('danger', 'Número inválido'); return; }

  try {
    const res = await api('admin.php?action=set_team_avisos', {
      method: 'POST',
      body: JSON.stringify({ team_id: teamId, league, count })
    });
    const n = res.count;
    const getScore = (n) => {
      if (n <= 2) return { label: 'Excelente', color: '#22c55e', bg: 'rgba(34,197,94,.10)',  border: 'rgba(34,197,94,.3)'  };
      if (n <= 4) return { label: 'Bom',       color: '#3b82f6', bg: 'rgba(59,130,246,.10)', border: 'rgba(59,130,246,.3)' };
      if (n <= 6) return { label: 'Regular',   color: '#eab308', bg: 'rgba(234,179,8,.10)',  border: 'rgba(234,179,8,.3)'  };
      if (n <= 8) return { label: 'Ruim',      color: '#f97316', bg: 'rgba(249,115,22,.10)', border: 'rgba(249,115,22,.3)' };
      return              { label: 'Péssimo',  color: '#ef4444', bg: 'rgba(239,68,68,.10)',  border: 'rgba(239,68,68,.3)'  };
    };
    const s = getScore(n);
    const infoEl = document.getElementById(`serasa-info-${teamId}`);
    if (infoEl) infoEl.innerHTML = `
      <span style="font-size:11px;color:var(--text-3)">${n} aviso${n !== 1 ? 's' : ''}</span>
      <span style="padding:2px 9px;border-radius:20px;font-size:11px;font-weight:700;color:${s.color};background:${s.bg};border:1px solid ${s.border}">${s.label}</span>
      <button class="btn-ghost" style="padding:2px 8px;font-size:11px" title="Editar avisos" onclick="_serasaEditAvisos(${teamId}, '${league}', ${n})"><i class="bi bi-pencil"></i></button>`;
    showAlert('success', 'Avisos atualizados');
  } catch (e) {
    showAlert('danger', e.error || 'Erro ao salvar');
  }
}

// ── Draft Admin ──────────────────────────────────────────────────────

let _adminDraftTimerInterval = null;

function _startAdminDraftTimer(deadlineTs, elId) {
  clearInterval(_adminDraftTimerInterval);
  const update = () => {
    const el = document.getElementById(elId);
    if (!el) { clearInterval(_adminDraftTimerInterval); return; }
    const remaining = deadlineTs - Math.floor(Date.now() / 1000);
    if (remaining <= 0) {
      el.textContent = '⏱ Expirado';
      el.style.color = '#ef4444';
      clearInterval(_adminDraftTimerInterval);
      return;
    }
    const m = String(Math.floor(remaining / 60)).padStart(2, '0');
    const s = String(remaining % 60).padStart(2, '0');
    el.textContent = `⏱ ${m}:${s}`;
    el.style.color = remaining < 300 ? '#f59e0b' : '#22c55e';
    if (el.style.border) el.style.borderColor = remaining < 300 ? 'rgba(245,158,11,.2)' : 'rgba(34,197,94,.2)';
  };
  update();
  _adminDraftTimerInterval = setInterval(update, 1000);
}

let _adminDraftRefreshInterval = null;

async function showAdminDraft(league) {
  league = league || appState.currentLeague;
  appState.view = 'draft';
  updateBreadcrumb();
  if (_adminDraftRefreshInterval) { clearInterval(_adminDraftRefreshInterval); _adminDraftRefreshInterval = null; }

  const container = document.getElementById('mainContainer');
  container.innerHTML = '<div class="text-center py-5"><div class="spinner-border" style="color:var(--red)"></div></div>';

  const back = `<button class="btn btn-back" onclick="showLeague('${league}')"><i class="bi bi-arrow-left"></i> Voltar</button>`;

  try {
    const [seasonData, draftData] = await Promise.all([
      api(`seasons.php?action=current_season&league=${league}`).catch(() => ({ season: null })),
      api(`draft.php?action=active_draft&league=${league}`).catch(() => ({ draft: null }))
    ]);

    const season = seasonData.season;
    const draft = draftData.draft;

    if (!season) {
      container.innerHTML = `
        <div class="mb-4">${back}</div>
        <div class="panel"><div style="padding:20px">
          <p class="empty-state">Nenhuma temporada ativa para ${league}. Crie uma temporada primeiro em Temporadas.</p>
        </div></div>`;
      return;
    }

    let orderData = null;
    if (draft) {
      try { orderData = await api(`draft.php?action=draft_order&draft_session_id=${draft.id}`); } catch(e) {}
    }

    let availablePlayers = [];
    if (draft && (draft.status === 'in_progress' || draft.status === 'setup')) {
      try {
        const pd = await api(`draft.php?action=available_players&season_id=${draft.season_id}`);
        availablePlayers = pd.players || [];
      } catch(e) {}
    }

    let leagueTeams = [];
    if (draft && draft.status === 'setup') {
      try {
        const td = await api(`draft.php?action=league_teams&league=${league}`);
        leagueTeams = td.teams || [];
      } catch(e) {}
    }

    let round2Board = [];
    let round2Deadline = null;
    if (draft && draft.status === 'in_progress' && Number(draft.current_round) === 2) {
      try {
        const rd = await api(`draft.php?action=round2_board&draft_session_id=${draft.id}`);
        round2Board = rd.picks || [];
        round2Deadline = rd.round2_mock_deadline || null;
      } catch(e) {}
    }

    const order = orderData?.order || [];
    const draftStatus = draft?.status || null;
    const currentRound = draft?.current_round || 1;
    const currentPick = draft?.current_pick || 1;

    const statusMap = { setup: ['#f59e0b', 'Configurando'], in_progress: ['#22c55e', 'Em andamento'], completed: ['#94a3b8', 'Concluído'] };
    const [statusColor, statusLabel] = draftStatus ? (statusMap[draftStatus] || ['#94a3b8', draftStatus]) : ['#94a3b8', 'Sem sessão'];

    // Session panel
    let sessionPanel = '';
    if (!draft) {
      sessionPanel = `
        <div class="panel mb-3">
          <div class="panel-header">
            <div class="panel-title"><i class="bi bi-trophy-fill" style="color:#a855f7"></i> Draft — ${escapeHtml(season.league)} T${escapeHtml(String(season.season_number))}</div>
          </div>
          <div style="padding:16px">
            <p style="color:var(--text-2);font-size:13px;margin-bottom:12px">Nenhuma sessão de draft criada para esta temporada.</p>
            <button class="btn-ghost" style="color:#a855f7" onclick="_adminDraftCreateSession('${league}', ${season.id})">
              <i class="bi bi-plus-circle me-1"></i>Criar Sessão de Draft
            </button>
          </div>
        </div>`;
    } else {
      const actionBtns = [];
      if (draftStatus === 'setup') {
        actionBtns.push(`<button class="btn-ghost" style="color:#22c55e" onclick="_adminDraftStart(${draft.id}, '${league}')"><i class="bi bi-play-fill me-1"></i>Iniciar Draft</button>`);
        actionBtns.push(`<button class="btn-ghost" style="color:#ef4444;font-size:11px" onclick="_adminDraftDelete(${draft.id}, '${league}')"><i class="bi bi-trash me-1"></i>Excluir</button>`);
      }
      if (draftStatus === 'in_progress') {
        actionBtns.push(`<button class="btn-ghost" style="color:#ef4444" onclick="_adminDraftFinalize(${draft.id}, '${league}')"><i class="bi bi-check2-all me-1"></i>Finalizar</button>`);
      }
      actionBtns.push(`<button class="btn-ghost" style="color:#a855f7" onclick="_adminDraftAddPlayerModal(${draft.id}, ${draft.season_id}, '${league}')"><i class="bi bi-person-plus me-1"></i>Adicionar Jogador</button>`);

      const currentInfo = draftStatus === 'in_progress' ? `
        <div style="padding:10px 16px;border-top:1px solid var(--border);display:flex;gap:16px;align-items:center;flex-wrap:wrap">
          <span style="font-size:12px;color:var(--text-3)">Rodada: <strong style="color:var(--text)">${currentRound}</strong></span>
          <span style="font-size:12px;color:var(--text-3)">Pick: <strong style="color:var(--text)">${currentPick}</strong></span>
          <span style="font-size:12px;color:var(--text-3)">Rodadas: <strong style="color:var(--text)">${draft.total_rounds || 2}</strong></span>
          ${draft.pick_deadline_ts ? `<span id="admin-draft-detail-timer" style="font-size:13px;font-weight:700;font-variant-numeric:tabular-nums;color:#22c55e;background:rgba(34,197,94,.08);border:1px solid rgba(34,197,94,.2);border-radius:6px;padding:2px 10px">⏱ --:--</span>` : ''}
        </div>` : '';

      // Relógio da 1ª rodada: admin agenda quando o prazo por pick cai de 30min pra 5min
      // (com fallback pra melhor da ordem geral se a fila pessoal do time não resolver).
      // Editável em setup ou em andamento, pra dar tempo do admin agendar antes de iniciar.
      let round1ClockPanel = '';
      if (draftStatus === 'setup' || draftStatus === 'in_progress') {
        const clockRaw = draft.round1_clock_start_at ? String(draft.round1_clock_start_at) : '';
        const clockValueForInput = clockRaw ? clockRaw.slice(0, 16).replace(' ', 'T') : '';
        const clockArmed = clockRaw && new Date(clockRaw.replace(' ', 'T')).getTime() <= Date.now();
        round1ClockPanel = `
          <div style="padding:10px 16px;border-top:1px solid var(--border);display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap">
            <div>
              <label class="pun-field-label" style="font-size:11px;color:var(--text-3);display:block;margin-bottom:3px">Relógio da 1ª rodada (5min/pick a partir daqui)</label>
              <input type="datetime-local" class="form-control form-control-sm" id="round1ClockInput_${draft.id}" value="${clockValueForInput}" style="min-width:200px">
            </div>
            <button class="btn-ghost" style="font-size:12px" onclick="_adminSetRound1Clock(${draft.id}, '${league}')"><i class="bi bi-clock-history me-1"></i>Salvar</button>
            ${clockRaw ? `<button class="btn-ghost" style="font-size:12px;color:#ef4444" onclick="_adminClearRound1Clock(${draft.id}, '${league}')"><i class="bi bi-x-circle me-1"></i>Remover</button>` : ''}
            <span style="font-size:11px;color:var(--text-3)">${clockRaw ? (clockArmed ? 'Ativo — picks agora têm 5min' : 'Agendado — ainda não chegou a hora') : 'Sem relógio definido — prazo de sempre (30min + fila)'}</span>
          </div>`;
      }

      sessionPanel = `
        <div class="panel mb-3">
          <div class="panel-header">
            <div class="panel-title"><i class="bi bi-trophy-fill" style="color:#a855f7"></i> Draft — ${escapeHtml(season.league)} T${escapeHtml(String(season.season_number))}</div>
            <div class="d-flex gap-2 align-items-center flex-wrap">
              <span class="pun-badge" style="background:${statusColor}20;color:${statusColor};border-color:${statusColor}40">${statusLabel}</span>
              ${actionBtns.join('')}
            </div>
          </div>
          ${currentInfo}
          ${round1ClockPanel}
        </div>`;
    }

    // Draft order panel
    let orderPanel = '';
    if (draft && draftStatus !== 'completed') {
      let orderContent = '';

      if (draftStatus === 'setup') {
        const round1 = order.filter(o => parseInt(o.round) === 1);
        const teamsOptions = leagueTeams.map(t => `<option value="${t.id}">${escapeHtml(t.city)} ${escapeHtml(t.name)}</option>`).join('');

        const orderRows = round1.map((o, i) => `
          <div style="display:flex;align-items:center;justify-content:space-between;padding:7px 8px;border-radius:6px;background:var(--panel-2);margin-bottom:5px">
            <div style="display:flex;align-items:center;gap:8px">
              <span style="font-size:11px;color:var(--text-3);width:18px;text-align:right;flex-shrink:0">${i + 1}.</span>
              <span style="font-size:13px;color:var(--text)">${escapeHtml(o.team_city)} ${escapeHtml(o.team_name)}</span>
            </div>
            <button class="btn-ghost" style="padding:2px 7px;font-size:11px;color:#ef4444" onclick="_adminDraftRemoveFromOrder(${o.id}, ${draft.id}, '${league}')">
              <i class="bi bi-x"></i>
            </button>
          </div>`).join('') || `<p style="font-size:13px;color:var(--text-3);text-align:center;padding:12px 0">Nenhum time adicionado ainda.</p>`;

        orderContent = `
          <div style="padding:12px 16px">
            <div class="d-flex gap-2 mb-3 align-items-center flex-wrap">
              <select id="draftOrderTeamSelect" style="background:var(--panel-2);color:var(--text);border:1px solid var(--panel-border);border-radius:6px;padding:6px 10px;font-size:13px;flex:1;min-width:160px">
                <option value="">Selecione o time…</option>
                ${teamsOptions}
              </select>
              <button class="btn-ghost" onclick="_adminDraftAddToOrder(${draft.id}, '${league}')" style="white-space:nowrap"><i class="bi bi-plus me-1"></i>Adicionar</button>
              ${round1.length > 0 ? `<button class="btn-ghost" style="color:#ef4444;font-size:11px" onclick="_adminDraftClearOrder(${draft.id}, '${league}')"><i class="bi bi-trash me-1"></i>Limpar tudo</button>` : ''}
            </div>
            ${orderRows}
          </div>`;

      } else {
        const rounds = [...new Set(order.map(o => parseInt(o.round)))].sort((a, b) => a - b);
        const roundsHtml = rounds.map(r => {
          const roundPicks = order.filter(o => parseInt(o.round) === r);
          const pickRows = roundPicks.map(o => {
            const isCurrent = r === currentRound && parseInt(o.pick_position) === currentPick && !o.picked_player_id;
            const isDone = !!o.picked_player_id;
            const rowBg = isCurrent ? 'background:rgba(168,85,247,.12);border-left:3px solid #a855f7;' : isDone ? 'opacity:.65;' : '';
            return `
              <div style="display:grid;grid-template-columns:22px 1fr auto;gap:8px;align-items:center;padding:7px 8px;border-radius:6px;background:var(--panel-2);margin-bottom:5px;${rowBg}">
                <span style="font-size:11px;color:var(--text-3);text-align:right">${o.pick_position}.</span>
                <div>
                  <span style="font-size:13px;color:var(--text)">${escapeHtml(o.team_city)} ${escapeHtml(o.team_name)}</span>
                  ${isDone ? `<br><span style="font-size:11px;color:#22c55e"><i class="bi bi-check me-1"></i>${escapeHtml(o.player_name || '')}${o.player_position ? ' · ' + o.player_position : ''}${o.player_ovr ? ' · OVR ' + o.player_ovr : ''}</span>` : ''}
                  ${isCurrent ? `<br><span style="font-size:11px;color:#a855f7"><i class="bi bi-cursor-fill me-1"></i>Escolhendo agora…</span>` : ''}
                </div>
                ${!isDone ? `<div style="display:flex;gap:5px;flex-shrink:0">
                  <button class="btn-ghost" style="padding:3px 8px;font-size:11px" onclick="_adminDraftPickModal(${draft.id}, ${o.id}, ${draft.season_id}, '${league}')"><i class="bi bi-person-check me-1"></i>Pick</button>
                  <button class="btn-ghost" style="padding:3px 8px;font-size:11px;color:#f59e0b;border-color:rgba(245,158,11,.3)" title="Trocar dono da pick" onclick="_adminDraftChangeOwnerModal(${draft.id}, ${o.id}, ${o.round}, ${o.pick_position}, ${o.team_id}, '${league}')"><i class="bi bi-arrow-left-right"></i></button>
                </div>` : '<span></span>'}
              </div>`;
          }).join('');
          return `<div style="margin-bottom:14px"><div style="font-size:11px;font-weight:600;color:var(--text-3);text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px">Rodada ${r}</div>${pickRows}</div>`;
        }).join('');

        orderContent = `<div style="padding:12px 16px">${roundsHtml || '<p style="color:var(--text-3);font-size:13px;text-align:center;padding:12px">Sem picks definidos.</p>'}</div>`;
      }

      const round1Count = order.filter(o => parseInt(o.round) === 1).length;
      orderPanel = `
        <div class="panel mb-3">
          <div class="panel-header">
            <div class="panel-title"><i class="bi bi-list-ol" style="color:#94a3b8"></i> Ordem do Draft</div>
            <span style="font-size:11px;color:var(--text-3)">${round1Count} time${round1Count !== 1 ? 's' : ''} · ${draft.total_rounds || 2} rodadas</span>
          </div>
          ${orderContent}
        </div>`;
    }

    // Available players panel
    let playersPanel = '';
    if (draft) {
      const draftSid = draft.id;
      const draftSeasonId = draft.season_id;
      const importBtns = `
        <div class="d-flex gap-2 align-items-center flex-wrap" id="draftPoolBtns_${draftSid}">
          <select id="draftClassBankSelect_${draftSid}"
            style="background:var(--panel-2);border:1px solid var(--border-md);color:var(--text);border-radius:var(--radius-sm);padding:5px 10px;font-size:12px;min-width:180px"
            onchange="">
            <option value="">Carregando classes…</option>
          </select>
          <button class="btn-ghost" style="padding:5px 11px;font-size:12px;color:#a855f7"
            onclick="_adminDraftUseClassBank(${draftSid}, ${draftSeasonId}, '${league}')">
            <i class="bi bi-archive me-1"></i>Usar classe
          </button>
          <button class="btn-ghost" style="padding:5px 11px;font-size:12px;color:#94a3b8"
            onclick="_adminDraftDownloadTemplate()">
            <i class="bi bi-download me-1"></i>Modelo CSV
          </button>
          <button class="btn-ghost" style="padding:5px 11px;font-size:12px;color:#94a3b8"
            onclick="_adminDraftImportModal(${draftSid}, ${draftSeasonId}, '${league}')">
            <i class="bi bi-upload me-1"></i>CSV
          </button>
          ${availablePlayers.length > 0 ? `<button class="btn-ghost" style="padding:5px 11px;font-size:12px;color:#ef4444"
            onclick="_adminDraftClearPool(${draftSeasonId}, '${league}')">
            <i class="bi bi-trash me-1"></i>Apagar todos
          </button>` : ''}
        </div>`;

      if (availablePlayers.length > 0) {
        const playerRows = availablePlayers.slice(0, 60).map(p => `
          <div style="display:flex;align-items:center;justify-content:space-between;padding:6px 0;border-bottom:1px solid var(--border)">
            <div>
              <span style="font-size:13px;color:var(--text)">${escapeHtml(p.name)}</span>
              <span style="font-size:11px;color:var(--text-3);margin-left:6px">${escapeHtml(p.position || '')} · OVR ${p.ovr || '-'} · ${p.age || '-'}a</span>
            </div>
            <button class="btn-ghost" style="padding:3px 7px;color:#ef4444;flex-shrink:0" title="Excluir jogador"
              onclick="_adminDraftDeletePlayer(${p.id}, '${league}')">
              <i class="bi bi-trash" style="font-size:13px"></i>
            </button>
          </div>`).join('');
        const more = availablePlayers.length > 60 ? `<p style="font-size:11px;color:var(--text-3);text-align:center;margin-top:8px">+${availablePlayers.length - 60} jogadores</p>` : '';

        playersPanel = `
          <div class="panel mb-3">
            <div class="panel-header" style="flex-wrap:wrap;gap:10px">
              <div class="panel-title"><i class="bi bi-people-fill" style="color:#94a3b8"></i> Pool de Jogadores
                <span style="font-size:11px;font-weight:400;color:var(--text-3);margin-left:6px">${availablePlayers.length} disponíveis</span>
              </div>
              ${importBtns}
            </div>
            <div style="padding:4px 16px 10px">${playerRows}${more}</div>
          </div>`;
      } else {
        playersPanel = `
          <div class="panel mb-3">
            <div class="panel-header" style="flex-wrap:wrap;gap:10px">
              <div class="panel-title"><i class="bi bi-people-fill" style="color:#94a3b8"></i> Pool de Jogadores</div>
              ${importBtns}
            </div>
            <div style="padding:4px 16px 16px"><p class="empty-state" style="padding:16px 0">Nenhum jogador no pool. Use "Adicionar Jogador" ou importe um CSV.</p></div>
          </div>`;
      }
    }

    // Board da 2ª rodada: cada pick com o mock que o time deixou (admin vê todos); resolve
    // sozinho (lazy) quando o relógio de 20min vence, ou na hora via "Resolver agora".
    let round2BoardPanel = '';
    if (round2Board.length > 0) {
      const pendentes = round2Board.filter(p => !p.picked_player_id);
      const playerOptions = availablePlayers.map(pl => `<option value="${pl.id}">${escapeHtml(pl.name)} (${escapeHtml(pl.position)}) - OVR ${pl.ovr}</option>`).join('');
      const rows = round2Board.map(p => {
        const resolved = !!p.picked_player_id;
        const mockLabel = p.mock_player
          ? `${escapeHtml(p.mock_player.name)} <span style="color:var(--text-3);font-size:11px">(${escapeHtml(p.mock_player.position || '')} · OVR ${p.mock_player.ovr})</span>`
          : '<span style="color:var(--text-3)">sem mock</span>';
        return `
          <div style="padding:6px 8px;border-radius:6px;margin-bottom:4px;${resolved ? 'opacity:.55' : ''};background:var(--panel-2)">
            <div style="display:flex;align-items:center;justify-content:space-between;gap:8px">
              <div style="font-size:13px;color:var(--text)">#${p.pick_position} · ${escapeHtml(p.team_name)}</div>
              <div style="font-size:13px;color:var(--text)">${resolved ? '<span class="pun-badge" style="background:#22c55e20;color:#22c55e;border-color:#22c55e40">Resolvida</span>' : mockLabel}</div>
            </div>
            ${!resolved ? `
              <div style="display:flex;gap:6px;margin-top:6px">
                <select class="form-select form-select-sm" id="_r2AdminMockSelect_${p.draft_order_id}" style="font-size:11px;flex:1">
                  <option value="">Definir/trocar mock…</option>
                  ${playerOptions}
                </select>
                <button class="btn-ghost" style="padding:3px 9px;font-size:11px" onclick="_adminSetRound2Mock(${p.draft_order_id}, '${league}')"><i class="bi bi-check2"></i></button>
              </div>` : ''}
          </div>`;
      }).join('');

      round2BoardPanel = `
        <div class="panel mb-3">
          <div class="panel-header">
            <div class="panel-title"><i class="bi bi-inboxes-fill" style="color:#a855f7"></i> 2ª Rodada — Mocks</div>
            <span style="font-size:11px;color:var(--text-3)">${pendentes.length} pendente(s)${round2Deadline ? ` · relógio até ${escapeHtml(round2Deadline)}` : ''}</span>
          </div>
          <div style="padding:12px 16px">
            ${rows}
            ${pendentes.length ? `<button class="btn-ghost" style="margin-top:8px;color:#ef4444" onclick="_adminResolveRound2Now(${draft.id}, '${league}')"><i class="bi bi-lightning-fill me-1"></i>Resolver agora</button>` : ''}
          </div>
        </div>`;
    }

    container.innerHTML = `
      <div class="mb-4">${back}</div>
      ${round2BoardPanel}
      ${sessionPanel}
      ${orderPanel}
      ${playersPanel}
      <!-- No fim do card de Draft. Deixou de ser rotina desde que o ano do
           draft parou de sair da janela de picks; fica como a saída manual
           pra quando faltar pick com o draft já rolando. -->
      <div class="panel" style="margin-top:24px">
        <div style="padding:16px 18px;display:flex;flex-wrap:wrap;align-items:center;gap:14px">
          <button class="btn-ghost" style="color:#38bdf8;border-color:rgba(56,189,248,.35)"
                  onclick="ajustarPicksDaLiga('${league}')">
            <i class="bi bi-calendar2-plus me-1"></i>Ajustar Picks
          </button>
          <span style="font-size:12.5px;line-height:1.45;color:var(--text-3);max-width:52ch">
            Devolve as escolhas que faltam — as do draft em andamento e as dos anos futuros.
            Picks já negociadas não são tocadas.
          </span>
        </div>
      </div>`;

    // Carrega banco de classes no select da pool (só se há sessão de draft)
    if (draft) {
      const _sid = draft.id;
      api('admin.php?action=draft_class_bank&sub=list' + _ligaClasses()).then(d => {
        const sel = document.getElementById(`draftClassBankSelect_${_sid}`);
        if (!sel) return;
        const tpls = d.templates || [];
        sel.innerHTML = tpls.length
          ? '<option value="">Selecione uma classe…</option>' + tpls.map(t => `<option value="${t.id}">${escapeHtml(t.name)} (${t.player_count})</option>`).join('')
          : '<option value="">Nenhuma classe salva</option>';
      }).catch(() => {});
    }

    if (draftStatus === 'in_progress' && draft?.pick_deadline_ts) {
      _startAdminDraftTimer(Number(draft.pick_deadline_ts), 'admin-draft-detail-timer');
    }

    // Atualiza automaticamente enquanto o draft estiver em andamento, para o admin
    // ver na hora quando um time faz uma pick (evita tentar dar pick numa vaga já escolhida).
    if (draftStatus === 'in_progress') {
      _adminDraftRefreshInterval = setInterval(() => {
        if (appState.view !== 'draft') {
          clearInterval(_adminDraftRefreshInterval);
          _adminDraftRefreshInterval = null;
          return;
        }
        showAdminDraft(league);
      }, 10000);
    }

    // Os drafts que já aconteceram, no fim do card. Anexado depois do render
    // em vez de dentro do template: o painel busca a própria lista e se
    // redesenha a cada edição, sem refazer o card inteiro.
    container.insertAdjacentHTML('beforeend', `
      <div class="panel mt-3" id="draftsPassadosPanel">
        <div class="panel-header">
          <div>
            <div class="panel-title" style="margin-bottom:0">
              <i class="bi bi-clock-history" style="color:#a855f7"></i> Drafts da ${escapeHtml(league)}
            </div>
            <div class="panel-sub">Abrir um draft anterior e corrigir as escolhas</div>
          </div>
        </div>
        <div class="panel-body" id="draftsPassadosBox">
          <div class="text-center py-3"><div class="spinner-border spinner-border-sm" style="color:var(--red)"></div></div>
        </div>
      </div>`);
    _carregarDraftsPassados(league);

  } catch(e) {
    container.innerHTML = `
      <div class="mb-4">${back}</div>
      <div class="alert alert-danger">Erro ao carregar draft: ${escapeHtml(e.error || e.message || 'Desconhecido')}</div>`;
  }
}

/* ── Drafts anteriores da liga: listar, abrir e corrigir ────────────────── */

/** Guarda o draft aberto pra que a lista de jogadores saiba a qual pertence. */
let _draftAberto = null;

async function _carregarDraftsPassados(league) {
  const box = document.getElementById('draftsPassadosBox');
  if (!box) return;
  try {
    const d = await api(`admin.php?action=drafts_da_liga&league=${encodeURIComponent(league)}`);
    const drafts = d.drafts || [];
    if (!drafts.length) {
      box.innerHTML = '<p style="color:var(--text-3);font-size:13px;margin:0">Nenhum draft registrado nesta liga.</p>';
      return;
    }
    const cor = { in_progress: '#22c55e', completed: 'var(--text-3)', setup: '#f59e0b' };
    box.innerHTML = drafts.map(x => `
      <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;padding:9px 4px;border-bottom:1px solid var(--border)">
        <div style="flex:1;min-width:150px">
          <div style="font-size:13.5px;font-weight:600;color:var(--text)">
            Temporada ${escapeHtml(String(x.season_number))}${x.year ? ' · ' + escapeHtml(String(x.year)) : ''}
            ${x.sprint_number ? `<span style="color:var(--text-3);font-weight:500"> · sprint ${escapeHtml(String(x.sprint_number))}</span>` : ''}
          </div>
          <div style="font-size:12px;color:var(--text-3)">
            ${x.feitas} de ${x.vagas} escolhas feitas
            ${x.abertas > 0 ? `<span style="color:#f59e0b"> · ${x.abertas} em aberto</span>` : ''}
          </div>
        </div>
        <span class="pun-badge" style="background:${cor[x.status] || 'var(--panel-2)'}22;color:${cor[x.status] || 'var(--text-3)'};border:1px solid ${cor[x.status] || 'var(--border)'}55">
          ${x.status === 'in_progress' ? 'em andamento' : x.status === 'setup' ? 'montando' : 'concluído'}
        </span>
        <button class="btn-ghost" style="padding:4px 12px;font-size:12.5px"
          onclick="_abrirDraftPassado(${x.id}, '${league}')">
          <i class="bi bi-pencil-square me-1"></i>Abrir
        </button>
      </div>`).join('');
  } catch (e) {
    box.innerHTML = `<p style="color:#ef4444;font-size:13px;margin:0">Erro: ${escapeHtml(e.error || 'desconhecido')}</p>`;
  }
}

/** Abre a ordem de um draft com cada escolha clicável. */
async function _abrirDraftPassado(sessionId, league) {
  const box = document.getElementById('draftsPassadosBox');
  if (!box) return;
  box.innerHTML = '<div class="text-center py-3"><div class="spinner-border spinner-border-sm" style="color:var(--red)"></div></div>';
  try {
    const d = await api(`admin.php?action=draft_ordem&league=${encodeURIComponent(league)}&session_id=${sessionId}`);
    _draftAberto = { sessionId, league, pool: d.pool || [] };
    const ordem = d.ordem || [];

    const porRodada = {};
    ordem.forEach(o => { (porRodada[o.round] = porRodada[o.round] || []).push(o); });

    const card = o => `
      <button class="btn-ghost" style="text-align:left;padding:8px 10px;font-size:12.5px;width:100%;
              border-color:${o.jogador ? 'var(--border)' : 'rgba(245,158,11,.4)'}"
              onclick="_escolherJogadorParaPick(${o.id}, '${escapeHtml(String(o.time || '')).replace(/'/g, "\\'")}', ${o.pick_position}, ${o.round})">
        <div style="color:var(--text-3);font-size:11px">#${o.pick_position} · ${escapeHtml(o.time || '—')}</div>
        <div style="color:${o.jogador ? 'var(--text)' : '#f59e0b'};font-weight:600">
          ${o.jogador ? escapeHtml(o.jogador) : 'em aberto'}
        </div>
      </button>`;

    box.innerHTML = `
      <div class="mb-2">
        <button class="btn-ghost" style="padding:4px 12px;font-size:12.5px" onclick="_carregarDraftsPassados('${league}')">
          <i class="bi bi-arrow-left me-1"></i>Voltar à lista
        </button>
      </div>
      <p style="color:var(--text-3);font-size:12.5px;margin-bottom:10px">
        Clique numa escolha pra trocar o jogador. Se o jogador escolhido estiver em outra pick,
        ele sai de lá — e aquela pick fica em aberto.
      </p>
      ${Object.keys(porRodada).sort().map(r => `
        <div style="margin-bottom:14px">
          <div style="font-size:11.5px;font-weight:800;letter-spacing:.06em;color:var(--text-3);margin-bottom:6px">
            ${r}ª RODADA
          </div>
          <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(165px,1fr));gap:6px">
            ${porRodada[r].map(card).join('')}
          </div>
        </div>`).join('')}`;
  } catch (e) {
    box.innerHTML = `<p style="color:#ef4444;font-size:13px">Erro: ${escapeHtml(e.error || 'desconhecido')}</p>`;
  }
}

/** O seletor de jogador: os livres primeiro, depois os que estão em outro time. */
function _escolherJogadorParaPick(pickId, timeNome, pickPos, round) {
  if (!_draftAberto) return;
  document.getElementById('_modalEscolhaDraft')?.remove();

  const livres = _draftAberto.pool.filter(p => p.draft_status !== 'drafted');
  const presos = _draftAberto.pool.filter(p => p.draft_status === 'drafted');
  const linha = p => `
    <button class="btn-ghost" style="text-align:left;width:100%;padding:7px 10px;font-size:12.5px;margin-bottom:4px"
            onclick="_moverJogador(${pickId}, ${p.id}, '${escapeHtml(String(p.name)).replace(/'/g, "\\'")}')">
      <span style="color:var(--text);font-weight:600">${escapeHtml(p.name)}</span>
      <span style="color:var(--text-3)"> · ${escapeHtml(p.position || '?')} · OVR ${p.ovr ?? '?'}</span>
      ${p.time_atual ? `<div style="color:#f59e0b;font-size:11px">hoje no ${escapeHtml(p.time_atual)}</div>` : ''}
    </button>`;

  document.body.insertAdjacentHTML('beforeend', `
    <div class="modal fade" id="_modalEscolhaDraft" tabindex="-1">
      <div class="modal-dialog modal-dialog-scrollable">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title">#${pickPos} · ${round}ª rodada — ${escapeHtml(timeNome)}</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <input type="text" class="form-control mb-2" id="_buscaJogadorDraft" placeholder="Buscar jogador…" autocomplete="off">
            <div id="_listaJogadorDraft">
              <div style="font-size:11.5px;font-weight:800;color:var(--text-3);margin:6px 0">DISPONÍVEIS (${livres.length})</div>
              ${livres.map(linha).join('') || '<p style="color:var(--text-3);font-size:12.5px">Nenhum livre.</p>'}
              <div style="font-size:11.5px;font-weight:800;color:var(--text-3);margin:12px 0 6px">JÁ ESCOLHIDOS (${presos.length})</div>
              ${presos.map(linha).join('')}
            </div>
          </div>
        </div>
      </div>
    </div>`);

  const modal = new bootstrap.Modal(document.getElementById('_modalEscolhaDraft'));
  modal.show();
  document.getElementById('_buscaJogadorDraft').addEventListener('input', e => {
    const t = e.target.value.trim().toLowerCase();
    document.querySelectorAll('#_listaJogadorDraft button').forEach(b => {
      b.style.display = !t || b.innerText.toLowerCase().includes(t) ? '' : 'none';
    });
  });
}

/** Executa a troca e volta pra ordem já atualizada. */
async function _moverJogador(pickId, playerId, nome) {
  if (!_draftAberto) return;
  if (!await confirmarSite(`Pôr ${nome} nesta escolha?\n\nSe ele estiver em outra pick, sai de lá e aquela fica em aberto.`)) return;
  try {
    /* A acao vai na URL, e nao so no corpo: admin.php le  de \n       inclusive no POST, entao mandar apenas no JSON cai no default e volta
       "Acao invalida". */
    const r = await api('admin.php?action=draft_mover_jogador', { method: 'POST', body: JSON.stringify({
      action: 'draft_mover_jogador', league: _draftAberto.league,
      session_id: _draftAberto.sessionId, pick_id: pickId, player_id: playerId })});
    bootstrap.Modal.getInstance(document.getElementById('_modalEscolhaDraft'))?.hide();
    showAlert('success', r.message || 'Escolha atualizada.');
    _abrirDraftPassado(_draftAberto.sessionId, _draftAberto.league);
  } catch (e) {
    showAlert('danger', e.error || 'Erro ao mover o jogador');
  }
}

async function _adminDraftChangeOwnerModal(draftId, pickId, round, pickPos, currentTeamId, league) {
  document.getElementById('_adminChangeOwnerModal')?.remove();

  const modal = document.createElement('div');
  modal.id = '_adminChangeOwnerModal';
  modal.className = 'modal fade';
  modal.setAttribute('tabindex', '-1');
  modal.innerHTML = `
    <div class="modal-dialog modal-sm">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-arrow-left-right me-2"></i>Trocar Dono da Pick</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <p style="font-size:13px;color:var(--text-2);margin-bottom:12px">Rodada ${round} · Pick #${pickPos}</p>
          <label class="pun-field-label">Novo dono</label>
          <select id="_adminChangeOwnerSelect" class="form-select mt-1">
            <option value="">Carregando…</option>
          </select>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn-ghost" data-bs-dismiss="modal">Cancelar</button>
          <button type="button" class="btn-orange" onclick="_adminDraftConfirmChangeOwner(${draftId}, ${pickId}, ${round}, ${pickPos}, '${league}')">
            <i class="bi bi-check me-1"></i>Confirmar
          </button>
        </div>
      </div>
    </div>`;
  document.body.appendChild(modal);

  const bsModal = new bootstrap.Modal(modal);
  bsModal.show();
  modal.addEventListener('hidden.bs.modal', () => modal.remove());

  const select = document.getElementById('_adminChangeOwnerSelect');
  try {
    const data = await api(`draft.php?action=league_teams&league=${encodeURIComponent(league)}`);
    const teams = data.teams || [];
    select.innerHTML = '<option value="">Selecione o time…</option>';
    teams
      .filter(t => Number(t.id) !== Number(currentTeamId))
      .sort((a, b) => `${a.city} ${a.name}`.localeCompare(`${b.city} ${b.name}`))
      .forEach(t => {
        const opt = document.createElement('option');
        opt.value = t.id;
        opt.textContent = `${t.city} ${t.name}`;
        select.appendChild(opt);
      });
  } catch(e) {
    select.innerHTML = '<option value="">Erro ao carregar times</option>';
  }
}

async function _adminDraftConfirmChangeOwner(draftId, pickId, round, pickPos, league) {
  const select = document.getElementById('_adminChangeOwnerSelect');
  const toTeamId = Number(select?.value || 0);
  if (!toTeamId) { showAlert('danger', 'Selecione o time que vai receber a pick.'); return; }
  try {
    await api('draft.php', {
      method: 'POST',
      body: JSON.stringify({ action: 'trade_pick', draft_session_id: draftId, pick_id: pickId, to_team_id: toTeamId })
    });
    const modal = document.getElementById('_adminChangeOwnerModal');
    if (modal) bootstrap.Modal.getInstance(modal)?.hide();
    showAlert('success', `Pick R${round}·#${pickPos} transferida com sucesso!`);
    showAdminDraft(league);
  } catch(e) {
    showAlert('danger', e.error || 'Erro ao trocar dono da pick');
  }
}

async function _adminDraftCreateSession(league, seasonId) {
  try {
    await api('draft.php', { method: 'POST', body: JSON.stringify({ action: 'create_session', season_id: seasonId }) });
    showAdminDraft(league);
  } catch(e) {
    showAlert('danger', e.error || 'Erro ao criar sessão de draft');
  }
}

async function _adminDraftStart(draftSessionId, league) {
  if (!await confirmarSite('Iniciar o draft? Verifique se a ordem dos times está definida antes de continuar.')) return;
  try {
    await api('draft.php', { method: 'POST', body: JSON.stringify({ action: 'start_draft', draft_session_id: draftSessionId }) });
    showAlert('success', 'Draft iniciado!');
    showAdminDraft(league);
  } catch(e) {
    showAlert('danger', e.error || 'Erro ao iniciar draft');
  }
}

async function _adminSetRound1Clock(draftSessionId, league) {
  const input = document.getElementById(`round1ClockInput_${draftSessionId}`);
  const value = input?.value || '';
  try {
    const result = await api('draft.php', { method: 'POST', body: JSON.stringify({ action: 'set_round1_clock', draft_session_id: draftSessionId, round1_clock_start_at: value }) });
    showAlert('success', result.message || 'Relógio salvo!');
    showAdminDraft(league);
  } catch(e) {
    showAlert('danger', e.error || 'Erro ao salvar o relógio');
  }
}

async function _adminClearRound1Clock(draftSessionId, league) {
  if (!await confirmarSite('Remover o relógio da 1ª rodada? As picks voltam a ter o prazo de sempre (30min + fila).')) return;
  try {
    const result = await api('draft.php', { method: 'POST', body: JSON.stringify({ action: 'set_round1_clock', draft_session_id: draftSessionId, round1_clock_start_at: '' }) });
    showAlert('success', result.message || 'Relógio removido');
    showAdminDraft(league);
  } catch(e) {
    showAlert('danger', e.error || 'Erro ao remover o relógio');
  }
}

async function _adminSetRound2Mock(draftOrderId, league) {
  const sel = document.getElementById(`_r2AdminMockSelect_${draftOrderId}`);
  const playerId = sel?.value;
  if (!playerId) { showAlert('warning', 'Selecione um jogador'); return; }
  try {
    const result = await api('draft.php', { method: 'POST', body: JSON.stringify({ action: 'submit_round2_mock', draft_order_id: draftOrderId, player_id: parseInt(playerId, 10) }) });
    showAlert('success', result.message || 'Mock definido!');
    showAdminDraft(league);
  } catch(e) {
    showAlert('danger', e.error || 'Erro ao definir mock');
  }
}

async function _adminResolveRound2Now(draftSessionId, league) {
  if (!await confirmarSite('Resolver a 2ª rodada agora? Cada pick com mock leva o jogador (se ainda disponível); quem não tem mock fica em aberto. O draft é marcado concluído.')) return;
  try {
    const result = await api('draft.php', { method: 'POST', body: JSON.stringify({ action: 'resolve_round2_now', draft_session_id: draftSessionId }) });
    showAlert('success', result.message || 'Rodada 2 resolvida!');
    showAdminDraft(league);
  } catch(e) {
    showAlert('danger', e.error || 'Erro ao resolver a rodada 2');
  }
}

async function _adminDraftFinalize(draftSessionId, league) {
  if (!await confirmarSite('Finalizar o draft? Isso marca o draft como concluído.')) return;
  try {
    await api('draft.php', { method: 'POST', body: JSON.stringify({ action: 'finalize_draft', draft_session_id: draftSessionId }) });
    showAlert('success', 'Draft finalizado!');
    showAdminDraft(league);
  } catch(e) {
    showAlert('danger', e.error || 'Erro ao finalizar draft');
  }
}

async function _adminDraftDelete(draftSessionId, league) {
  if (!await confirmarSite('Excluir esta sessão de draft? Todos os picks e a ordem serão removidos. Esta ação não pode ser desfeita.')) return;
  try {
    await api('draft.php', { method: 'POST', body: JSON.stringify({ action: 'delete_session', draft_session_id: draftSessionId }) });
    showAlert('success', 'Sessão de draft excluída.');
    showAdminDraft(league);
  } catch(e) {
    showAlert('danger', e.error || 'Erro ao excluir sessão');
  }
}

async function _adminDraftAddToOrder(draftSessionId, league) {
  const sel = document.getElementById('draftOrderTeamSelect');
  const teamId = sel?.value;
  if (!teamId) { showAlert('warning', 'Selecione um time.'); return; }
  try {
    await api('draft.php', { method: 'POST', body: JSON.stringify({ action: 'add_to_order', draft_session_id: draftSessionId, team_id: parseInt(teamId) }) });
    showAdminDraft(league);
  } catch(e) {
    showAlert('danger', e.error || 'Erro ao adicionar time à ordem');
  }
}

async function _adminDraftRemoveFromOrder(pickId, draftSessionId, league) {
  try {
    await api('draft.php', { method: 'POST', body: JSON.stringify({ action: 'remove_from_order', pick_id: pickId, draft_session_id: draftSessionId }) });
    showAdminDraft(league);
  } catch(e) {
    showAlert('danger', e.error || 'Erro ao remover time da ordem');
  }
}

async function _adminDraftClearOrder(draftSessionId, league) {
  if (!await confirmarSite('Limpar toda a ordem do draft?')) return;
  try {
    await api('draft.php', { method: 'POST', body: JSON.stringify({ action: 'clear_order', draft_session_id: draftSessionId }) });
    showAdminDraft(league);
  } catch(e) {
    showAlert('danger', e.error || 'Erro ao limpar ordem');
  }
}

async function _adminDraftDeletePlayer(playerId, league) {
  try {
    await api('seasons.php?action=delete_draft_player', { method: 'POST', body: JSON.stringify({ player_id: playerId }) });
    showAdminDraft(league);
  } catch(e) {
    showAlert('danger', e.error || 'Erro ao excluir jogador');
  }
}

async function _adminDraftClearPool(seasonId, league) {
  if (!await confirmarSite('Apagar todos os jogadores disponíveis do pool? Esta ação não pode ser desfeita.')) return;
  try {
    await api('seasons.php?action=clear_draft_pool', { method: 'POST', body: JSON.stringify({ season_id: seasonId }) });
    showAlert('success', 'Pool de jogadores limpo.');
    showAdminDraft(league);
  } catch(e) {
    showAlert('danger', e.error || 'Erro ao limpar pool');
  }
}

async function _adminDraftAddPlayerModal(draftSessionId, seasonId, league) {
  document.getElementById('adminDraftPlayerModal')?.remove();

  const modal = document.createElement('div');
  modal.id = 'adminDraftPlayerModal';
  modal.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.7);z-index:1100;display:flex;align-items:center;justify-content:center;padding:16px';
  modal.innerHTML = `
    <div class="panel" style="width:100%;max-width:420px;padding:0">
      <div class="panel-header">
        <div class="panel-title"><i class="bi bi-person-plus" style="color:#a855f7"></i> Adicionar Jogador ao Draft</div>
        <button class="btn-ghost" style="padding:4px 8px" onclick="document.getElementById('adminDraftPlayerModal').remove()"><i class="bi bi-x-lg"></i></button>
      </div>
      <div style="padding:16px">
        <div class="row g-2 mb-3">
          <div class="col-12">
            <label style="font-size:11px;color:var(--text-3)">Nome</label>
            <input id="draftPlayerName" type="text" class="form-control form-control-sm" placeholder="Nome completo do jogador">
          </div>
          <div class="col-4">
            <label style="font-size:11px;color:var(--text-3)">Posição</label>
            <input id="draftPlayerPos" type="text" class="form-control form-control-sm" placeholder="PG, SG…">
          </div>
          <div class="col-4">
            <label style="font-size:11px;color:var(--text-3)">OVR</label>
            <input id="draftPlayerOvr" type="number" min="1" max="99" class="form-control form-control-sm" placeholder="75">
          </div>
          <div class="col-4">
            <label style="font-size:11px;color:var(--text-3)">Idade</label>
            <input id="draftPlayerAge" type="number" min="18" max="45" class="form-control form-control-sm" placeholder="22">
          </div>
        </div>
        <div class="d-flex gap-2 justify-content-end">
          <button class="btn-ghost" onclick="document.getElementById('adminDraftPlayerModal').remove()">Cancelar</button>
          <button class="btn-ghost" style="color:#a855f7" onclick="_adminDraftSubmitPlayer(${draftSessionId}, '${league}')">
            <i class="bi bi-plus me-1"></i>Adicionar
          </button>
        </div>
      </div>
    </div>`;
  document.body.appendChild(modal);
  document.getElementById('draftPlayerName')?.focus();
}

async function _adminDraftSubmitPlayer(draftSessionId, league) {
  const name = document.getElementById('draftPlayerName')?.value.trim();
  const position = (document.getElementById('draftPlayerPos')?.value.trim() || '').toUpperCase();
  const ovr = parseInt(document.getElementById('draftPlayerOvr')?.value || '0');
  const age = parseInt(document.getElementById('draftPlayerAge')?.value || '0');

  if (!name || !position || !ovr || !age) { showAlert('warning', 'Preencha todos os campos.'); return; }

  try {
    await api('draft.php', { method: 'POST', body: JSON.stringify({ action: 'add_draft_player', draft_session_id: draftSessionId, name, position, ovr, age }) });
    document.getElementById('adminDraftPlayerModal')?.remove();
    showAlert('success', 'Jogador adicionado ao pool!');
    showAdminDraft(league);
  } catch(e) {
    showAlert('danger', e.error || 'Erro ao adicionar jogador');
  }
}

async function _adminDraftPickModal(draftSessionId, pickId, seasonId, league) {
  let players = [];
  try {
    const pd = await api(`draft.php?action=available_players&season_id=${seasonId}`);
    players = pd.players || [];
  } catch(e) {}

  document.getElementById('adminDraftPickModal')?.remove();

  const playerOptions = players.map(p =>
    `<option value="${p.id}">${escapeHtml(p.name)} · ${escapeHtml(p.position || '?')} · OVR ${p.ovr || '-'} · ${p.age || '-'}a</option>`
  ).join('');

  const modal = document.createElement('div');
  modal.id = 'adminDraftPickModal';
  modal.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.7);z-index:1100;display:flex;align-items:center;justify-content:center;padding:16px';
  modal.innerHTML = `
    <div class="panel" style="width:100%;max-width:440px;padding:0">
      <div class="panel-header">
        <div class="panel-title"><i class="bi bi-person-check" style="color:#a855f7"></i> Fazer Pick</div>
        <button class="btn-ghost" style="padding:4px 8px" onclick="document.getElementById('adminDraftPickModal').remove()"><i class="bi bi-x-lg"></i></button>
      </div>
      <div style="padding:16px">
        <div class="mb-3">
          <label style="font-size:11px;color:var(--text-3);margin-bottom:4px;display:block">Jogador</label>
          <select id="draftPickPlayerSelect" class="form-select form-select-sm" style="background:var(--panel-2);color:var(--text);border:1px solid var(--panel-border)">
            <option value="">Selecione o jogador…</option>
            ${playerOptions}
          </select>
          ${players.length === 0 ? '<p style="font-size:12px;color:#ef4444;margin-top:6px">Nenhum jogador disponível no pool. Adicione jogadores primeiro.</p>' : ''}
        </div>
        <div class="d-flex gap-2 justify-content-end">
          <button class="btn-ghost" onclick="document.getElementById('adminDraftPickModal').remove()">Cancelar</button>
          <button class="btn-ghost" style="color:#22c55e" onclick="_adminDraftSubmitPick(${draftSessionId}, ${pickId}, '${league}')">
            <i class="bi bi-check me-1"></i>Confirmar Pick
          </button>
        </div>
      </div>
    </div>`;
  document.body.appendChild(modal);
}

async function _adminDraftSubmitPick(draftSessionId, pickId, league) {
  const playerId = document.getElementById('draftPickPlayerSelect')?.value;
  if (!playerId) { showAlert('warning', 'Selecione um jogador.'); return; }

  try {
    await api('draft.php', { method: 'POST', body: JSON.stringify({ action: 'make_pick', draft_session_id: draftSessionId, pick_id: pickId, player_id: parseInt(playerId) }) });
    document.getElementById('adminDraftPickModal')?.remove();
    showAlert('success', 'Pick realizado com sucesso!');
    showAdminDraft(league);
  } catch(e) {
    showAlert('danger', e.error || 'Erro ao fazer pick');
  }
}

// ── Draft CSV Import ────────────────────────────────────────────────────────

function _adminDraftDownloadTemplate() {
  const csv = 'name,position,ovr,age,ordem\nLeBron James,SF,97,39,1\nStephen Curry,PG,96,36,2\n';
  const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = 'draft_pool_modelo.csv';
  a.click();
  URL.revokeObjectURL(url);
}

function _adminDraftImportModal(draftSessionId, seasonId, league) {
  document.getElementById('adminDraftImportModal')?.remove();

  const modal = document.createElement('div');
  modal.id = 'adminDraftImportModal';
  modal.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.75);z-index:1100;display:flex;align-items:center;justify-content:center;padding:16px;overflow-y:auto';
  modal.innerHTML = `
    <div class="panel" style="width:100%;max-width:560px;padding:0">
      <div class="panel-header" style="padding:16px 18px 0">
        <div class="panel-title"><i class="bi bi-upload" style="color:#a855f7"></i> Importar Jogadores via CSV</div>
        <button class="btn-ghost" style="padding:4px 8px" onclick="document.getElementById('adminDraftImportModal').remove()"><i class="bi bi-x-lg"></i></button>
      </div>
      <div style="padding:16px 18px">
        <div id="draftImportBankSection" style="margin-bottom:14px">
          <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--text-3);margin-bottom:8px">Usar classe do banco</div>
          <div class="d-flex gap-2 align-items-center">
            <select id="draftImportBankSelect" class="form-select form-select-sm" style="flex:1">
              <option value="">Carregando…</option>
            </select>
            <button class="btn-ghost" style="color:#a855f7;white-space:nowrap" onclick="_adminDraftUseClassFromBank(${draftSessionId},'${league}')">
              <i class="bi bi-archive me-1"></i>Usar esta
            </button>
          </div>
        </div>
        <div style="font-size:11px;color:var(--text-3);margin-bottom:10px;text-align:center">— ou importe um novo CSV —</div>
        <p style="font-size:12px;color:var(--text-3);margin-bottom:12px">
          CSV com colunas: <strong style="color:var(--text)">name, position, ovr, age</strong> e, opcional, <strong style="color:var(--text)">ordem</strong> (posição no board de disponíveis).
          <button class="btn-ghost" style="padding:2px 8px;font-size:11px;margin-left:6px" onclick="_adminDraftDownloadTemplate()">
            <i class="bi bi-download me-1"></i>Baixar modelo
          </button>
        </p>

        <div id="draftImportDropzone"
          style="border:2px dashed var(--border-md);border-radius:var(--radius-sm);padding:28px 16px;text-align:center;cursor:pointer;transition:border-color .2s;margin-bottom:12px"
          onclick="document.getElementById('draftImportFileInput').click()"
          ondragover="event.preventDefault();this.style.borderColor='#a855f7'"
          ondragleave="this.style.borderColor=''"
          ondrop="_adminDraftHandleDrop(event,${draftSessionId},'${league}')">
          <i class="bi bi-file-earmark-text" style="font-size:28px;color:var(--text-3)"></i>
          <p style="font-size:13px;color:var(--text-2);margin-top:8px;margin-bottom:0">Arraste o arquivo CSV aqui ou clique para selecionar</p>
          <p style="font-size:11px;color:var(--text-3);margin-top:4px">Apenas .csv</p>
        </div>
        <input type="file" id="draftImportFileInput" accept=".csv,text/csv" style="display:none"
          onchange="_adminDraftFileSelected(this,${draftSessionId},'${league}')">

        <div id="draftImportPreview" style="display:none">
          <div style="font-size:11px;font-weight:600;color:var(--text-3);text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px">
            Preview — <span id="draftImportCount">0</span> jogadores
          </div>
          <div id="draftImportTable" style="max-height:220px;overflow-y:auto;border:1px solid var(--border);border-radius:var(--radius-sm)"></div>
          <div class="d-flex gap-2 justify-content-end mt-3">
            <button class="btn-ghost" onclick="document.getElementById('adminDraftImportModal').remove()">Cancelar</button>
            <button class="btn-ghost" style="color:#a855f7" id="draftImportConfirmBtn"
              onclick="_adminDraftConfirmImport(${draftSessionId},'${league}')">
              <i class="bi bi-check-lg me-1"></i>Importar todos
            </button>
          </div>
        </div>
      </div>
    </div>`;
  document.body.appendChild(modal);

  // Carregar banco de classes no select
  api('admin.php?action=draft_class_bank&sub=list' + _ligaClasses()).then(d => {
    const sel = document.getElementById('draftImportBankSelect');
    if (!sel) return;
    const templates = d.templates || [];
    if (!templates.length) {
      sel.innerHTML = '<option value="">Nenhuma classe salva</option>';
    } else {
      sel.innerHTML = '<option value="">Selecione uma classe…</option>' +
        templates.map(t => `<option value="${t.id}">${escapeHtml(t.name)} (${t.player_count} jogadores)</option>`).join('');
    }
  }).catch(() => {
    const sel = document.getElementById('draftImportBankSelect');
    if (sel) sel.innerHTML = '<option value="">Banco indisponível</option>';
  });
}

async function _adminDraftUseClassFromBank(draftSessionId, league) {
  const sel = document.getElementById('draftImportBankSelect');
  const tplId = sel?.value;
  if (!tplId) { showAlert('warning', 'Selecione uma classe do banco'); return; }
  try {
    const data = await api(`admin.php?action=draft_class_bank&sub=players&template_id=${tplId}`);
    _draftImportRows = (data.players || []).map(p => ({ name: p.name, position: p.position, ovr: p.ovr, age: p.age, pick_hint: p.pick_hint ?? null }));
    if (!_draftImportRows.length) { showAlert('warning', 'Classe sem jogadores'); return; }
    document.getElementById('draftImportCount').textContent = _draftImportRows.length;
    document.getElementById('draftImportTable').innerHTML = `
      <table style="width:100%;border-collapse:collapse;font-size:12px">
        <thead><tr style="background:var(--panel-2)">
          <th style="padding:6px 10px;text-align:left;color:var(--text-3)">Nome</th>
          <th style="padding:6px 8px;color:var(--text-3);text-align:center">Pos</th>
          <th style="padding:6px 8px;color:var(--text-3);text-align:center">OVR</th>
          <th style="padding:6px 8px;color:var(--text-3);text-align:center">Idade</th>
          <th style="padding:6px 8px;color:var(--text-3);text-align:center">Ordem</th>
        </tr></thead>
        <tbody>${_draftImportRows.slice(0,50).map(p=>`
          <tr style="border-top:1px solid var(--border)">
            <td style="padding:5px 10px;color:var(--text)">${escapeHtml(p.name)}</td>
            <td style="padding:5px 8px;color:var(--text-3);text-align:center">${p.position}</td>
            <td style="padding:5px 8px;color:#a855f7;font-weight:600;text-align:center">${p.ovr}</td>
            <td style="padding:5px 8px;color:var(--text-3);text-align:center">${p.age}</td>
            <td style="padding:5px 8px;color:var(--text-3);text-align:center">${p.pick_hint ?? '—'}</td>
          </tr>`).join('')}
        ${_draftImportRows.length>50?`<tr><td colspan="5" style="padding:5px 10px;color:var(--text-3);text-align:center">+${_draftImportRows.length-50} mais…</td></tr>`:''}
        </tbody></table>`;
    document.getElementById('draftImportPreview').style.display = 'block';
    document.getElementById('draftImportDropzone').style.display = 'none';
    document.getElementById('draftImportBankSection').style.display = 'none';
  } catch(e) { showAlert('danger', e.error || 'Erro ao carregar classe'); }
}

let _draftImportRows = [];

function _adminDraftHandleDrop(event, draftSessionId, league) {
  event.preventDefault();
  document.getElementById('draftImportDropzone').style.borderColor = '';
  const file = event.dataTransfer.files?.[0];
  if (file) _adminDraftParseCSV(file, draftSessionId, league);
}

function _adminDraftFileSelected(input, draftSessionId, league) {
  const file = input.files?.[0];
  if (file) _adminDraftParseCSV(file, draftSessionId, league);
}

function _adminDraftParseCSV(file, draftSessionId, league) {
  const reader = new FileReader();
  reader.onload = (e) => {
    const text = e.target.result;
    const lines = text.split(/\r?\n/).filter(l => l.trim());
    if (lines.length < 2) { showAlert('warning', 'Arquivo vazio ou sem dados.'); return; }

    // Detectar separador (vírgula ou ponto-e-vírgula)
    const sep = lines[0].includes(';') ? ';' : ',';
    const headers = lines[0].split(sep).map(h => h.trim().toLowerCase().replace(/['"]/g, ''));

    const nameIdx = headers.indexOf('name');
    const posIdx  = headers.indexOf('position');
    const ovrIdx  = headers.indexOf('ovr');
    const ageIdx  = headers.indexOf('age');
    const hintIdx = headers.indexOf('ordem') >= 0 ? headers.indexOf('ordem') : headers.indexOf('pick_hint');

    if (nameIdx < 0 || posIdx < 0 || ovrIdx < 0 || ageIdx < 0) {
      showAlert('danger', 'Cabeçalho inválido. Esperado: name, position, ovr, age');
      return;
    }

    _draftImportRows = [];
    const errRows = [];

    for (let i = 1; i < lines.length; i++) {
      const cols = lines[i].split(sep).map(c => c.trim().replace(/^["']|["']$/g, ''));
      const name = cols[nameIdx] || '';
      const pos  = (cols[posIdx] || '').toUpperCase();
      const ovr  = parseInt(cols[ovrIdx], 10);
      const age  = parseInt(cols[ageIdx], 10);
      const hintRaw = hintIdx >= 0 ? cols[hintIdx] : '';
      const pick_hint = hintRaw && parseInt(hintRaw, 10) > 0 ? parseInt(hintRaw, 10) : null;

      if (!name || !pos || isNaN(ovr) || isNaN(age) || ovr <= 0 || age <= 0) {
        errRows.push(i + 1);
        continue;
      }
      _draftImportRows.push({ name, position: pos, ovr, age, pick_hint });
    }

    const preview = document.getElementById('draftImportPreview');
    const countEl = document.getElementById('draftImportCount');
    const tableEl = document.getElementById('draftImportTable');
    if (!preview || !countEl || !tableEl) return;

    if (_draftImportRows.length === 0) {
      showAlert('warning', 'Nenhuma linha válida encontrada no CSV.');
      return;
    }

    countEl.textContent = _draftImportRows.length;
    const warnHtml = errRows.length
      ? `<p style="font-size:11px;color:#f59e0b;margin-bottom:6px"><i class="bi bi-exclamation-triangle me-1"></i>${errRows.length} linha(s) inválida(s) ignorada(s) (linhas: ${errRows.slice(0, 5).join(', ')}${errRows.length > 5 ? '…' : ''})</p>`
      : '';

    tableEl.innerHTML = `
      ${warnHtml}
      <table style="width:100%;border-collapse:collapse;font-size:12px">
        <thead>
          <tr style="background:var(--panel-2)">
            <th style="padding:7px 10px;text-align:left;color:var(--text-3);font-weight:500">Nome</th>
            <th style="padding:7px 10px;color:var(--text-3);font-weight:500">Pos</th>
            <th style="padding:7px 10px;color:var(--text-3);font-weight:500">OVR</th>
            <th style="padding:7px 10px;color:var(--text-3);font-weight:500">Idade</th>
            <th style="padding:7px 10px;color:var(--text-3);font-weight:500">Ordem</th>
          </tr>
        </thead>
        <tbody>
          ${_draftImportRows.slice(0, 50).map(p => `
            <tr style="border-top:1px solid var(--border)">
              <td style="padding:6px 10px;color:var(--text)">${escapeHtml(p.name)}</td>
              <td style="padding:6px 10px;color:var(--text-3);text-align:center">${escapeHtml(p.position)}</td>
              <td style="padding:6px 10px;color:#a855f7;font-weight:600;text-align:center">${p.ovr}</td>
              <td style="padding:6px 10px;color:var(--text-3);text-align:center">${p.age}</td>
              <td style="padding:6px 10px;color:var(--text-3);text-align:center">${p.pick_hint ?? '—'}</td>
            </tr>`).join('')}
          ${_draftImportRows.length > 50 ? `<tr><td colspan="5" style="padding:6px 10px;color:var(--text-3);text-align:center">+${_draftImportRows.length - 50} mais…</td></tr>` : ''}
        </tbody>
      </table>`;

    preview.style.display = 'block';
    document.getElementById('draftImportDropzone').style.display = 'none';
  };
  reader.readAsText(file, 'UTF-8');
}

async function _adminDraftConfirmImport(draftSessionId, league) {
  if (!_draftImportRows.length) { showAlert('warning', 'Nenhum dado para importar.'); return; }

  const btn = document.getElementById('draftImportConfirmBtn');
  if (btn) { btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Importando...'; }

  try {
    const res = await api('draft.php', {
      method: 'POST',
      body: JSON.stringify({ action: 'import_draft_players', draft_session_id: draftSessionId, players: _draftImportRows })
    });
    document.getElementById('adminDraftImportModal')?.remove();
    _draftImportRows = [];
    showAlert('success', res.message || `${res.inserted} jogador(es) importado(s)!`);
    showAdminDraft(league);
  } catch(e) {
    if (btn) { btn.disabled = false; btn.innerHTML = '<i class="bi bi-check-lg me-1"></i>Importar todos'; }
    showAlert('danger', e.error || 'Erro ao importar jogadores');
  }
}

// ── Usar classe do banco direto na pool do draft ──────────────────
async function _adminDraftUseClassBank(draftSid, seasonId, league) {
  const sel = document.getElementById(`draftClassBankSelect_${draftSid}`);
  const tplId = sel?.value;
  if (!tplId) { showAlert('warning', 'Selecione uma classe antes'); return; }

  try {
    const data = await api(`admin.php?action=draft_class_bank&sub=players&template_id=${tplId}`);
    const players = (data.players || []).map(p => ({ name: p.name, position: p.position, ovr: p.ovr, age: p.age, pick_hint: p.pick_hint ?? null }));
    if (!players.length) { showAlert('warning', 'Classe sem jogadores'); return; }

    if (!await confirmarSite(`Importar ${players.length} jogadores da classe para o pool? Os jogadores já existentes no pool serão mantidos.`)) return;

    const res = await api('draft.php', {
      method: 'POST',
      body: JSON.stringify({ action: 'import_draft_players', draft_session_id: draftSid, players })
    });
    showAlert('success', res.message || `${res.inserted ?? players.length} jogadores adicionados ao pool!`);
    showAdminDraft(league);
  } catch(e) {
    showAlert('danger', e.error || 'Erro ao importar classe');
  }
}

// ══════════════════════════════════════════════════════════════════
//  BANCO DE CLASSES DE DRAFT
// ══════════════════════════════════════════════════════════════════

/**
 * O sufixo de liga das chamadas ao banco de classes.
 *
 * Classe cadastrada na ELITE é da ELITE. Sem isto, a aba de cada liga
 * mostrava o bolo de todo mundo — e a roleta de uma liga podia oferecer
 * classe que outra já tinha reservado.
 */
function _ligaClasses() {
  return appState.currentLeague ? '&league=' + encodeURIComponent(appState.currentLeague) : '';
}

async function showDraftClassBank() {
  appState.view = 'draft_class_bank';
  updateBreadcrumb();
  const container = document.getElementById('mainContainer');
  container.innerHTML = '<div class="text-center py-5"><div class="spinner-border text-orange"></div></div>';

  try {
    const data = await api('admin.php?action=draft_class_bank&sub=list' + _ligaClasses());
    const templates = data.templates || [];
    const _back = appState.currentLeague ? `showLeague('${appState.currentLeague}')` : 'showHome()';

    container.innerHTML = `
<div class="mb-4 d-flex align-items-center gap-2 flex-wrap">
  <button class="btn btn-back" onclick="${_back}"><i class="bi bi-arrow-left"></i> Voltar</button>
  <button class="btn-ghost" style="color:#a855f7;margin-left:auto" onclick="_draftClassNewModal()">
    <i class="bi bi-plus-circle me-1"></i>Nova Classe
  </button>
</div>
<div class="panel">
  <div class="panel-header">
    <div class="panel-title"><i class="bi bi-archive-fill" style="color:#a855f7"></i> Banco de Classes de Draft</div>
    <div style="font-size:12px;color:var(--text-3)">${templates.length} classe(s) salva(s)</div>
  </div>
  <div id="draftClassList">
    ${templates.length === 0
      ? '<div class="empty-state">Nenhuma classe salva. Importe um CSV ou crie manualmente.</div>'
      : templates.map(t => `
        <div class="pun-card" id="draftClassCard_${t.id}">
          <div class="pun-card-head">
            <div>
              <div class="pun-card-title">${escapeHtml(t.name)}</div>
              <div class="pun-card-sub">${t.player_count} jogadores · criada em ${new Date(t.created_at).toLocaleDateString('pt-BR')}</div>
            </div>
            <div class="d-flex gap-2 align-items-center flex-wrap">
              <button class="btn-ghost" style="padding:3px 8px;font-size:11px;color:#a855f7" onclick="_draftClassEdit(${t.id}, '${escapeHtml(t.name).replace(/'/g,"\\'")}')">
                <i class="bi bi-pencil me-1"></i>Editar
              </button>
              <button class="btn-ghost" style="padding:3px 8px;font-size:11px;color:#ef4444" onclick="_draftClassDelete(${t.id}, '${escapeHtml(t.name).replace(/'/g,"\\'")}')">
                <i class="bi bi-trash me-1"></i>Excluir
              </button>
            </div>
          </div>
        </div>`).join('')}
  </div>
</div>`;
  } catch(e) {
    container.innerHTML = `<div class="alert alert-danger">Erro: ${e.error || e.message}</div>`;
  }
}

// ── Modal: criar nova classe manualmente ──────────────────────────
function _draftClassNewModal() {
  _draftClassOpenEditModal(null, '');
}

// ── Modal de edição / criação ─────────────────────────────────────
let _dcEditPlayers = [];
let _dcEditTemplateId = null;

async function _draftClassEdit(templateId, name) {
  const data = await api(`admin.php?action=draft_class_bank&sub=players&template_id=${templateId}`);
  _dcEditPlayers = data.players || [];
  _dcEditTemplateId = templateId;
  _draftClassOpenEditModal(templateId, name, _dcEditPlayers);
}

function _draftClassOpenEditModal(templateId, name, players = []) {
  _dcEditTemplateId = templateId;
  _dcEditPlayers = [...players];

  const existing = document.getElementById('_dcEditModal');
  if (existing) existing.remove();

  const modal = document.createElement('div');
  modal.id = '_dcEditModal';
  modal.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.75);z-index:1100;display:flex;align-items:flex-start;justify-content:center;padding:16px;overflow-y:auto';
  modal.innerHTML = `
    <div class="panel" style="width:100%;max-width:700px;padding:0;margin-top:24px">
      <div class="panel-header" style="padding:16px 18px 0">
        <div class="panel-title"><i class="bi bi-archive-fill" style="color:#a855f7"></i> ${templateId ? 'Editar Classe' : 'Nova Classe'}</div>
        <button class="btn-ghost" style="padding:4px 8px" onclick="document.getElementById('_dcEditModal').remove()"><i class="bi bi-x-lg"></i></button>
      </div>
      <div style="padding:16px 18px">
        <div class="mb-3 d-flex gap-2 align-items-center">
          <input type="text" id="_dcNameInput" class="form-control" value="${escapeHtml(name)}" placeholder="Nome da classe (ex: Draft 2040)" style="flex:1">
          ${templateId
            ? `<button class="btn-ghost" style="color:#a855f7;white-space:nowrap" onclick="_dcRenameClass()"><i class="bi bi-check-lg me-1"></i>Renomear</button>`
            : `<button class="btn-ghost" style="color:#a855f7;white-space:nowrap" onclick="_dcSaveNewClass()"><i class="bi bi-check-lg me-1"></i>Criar vazia</button>`}
        </div>

        ${!templateId ? `
        <div style="border-top:1px solid var(--border);padding-top:14px">
          <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px">
            <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--text-3)">Ou crie com CSV</div>
            <button class="btn-ghost" style="padding:2px 8px;font-size:11px" onclick="_adminDraftDownloadTemplate()"><i class="bi bi-download me-1"></i>Baixar modelo</button>
          </div>
          <p style="font-size:12px;color:var(--text-3);margin-bottom:10px">CSV com colunas: <strong style="color:var(--text)">name, position, ovr, age</strong></p>
          <div id="_dcNewClassDropzone"
            style="border:2px dashed var(--border-md);border-radius:var(--radius-sm);padding:20px;text-align:center;cursor:pointer;transition:border-color .2s"
            onclick="document.getElementById('_dcNewClassFile').click()"
            ondragover="event.preventDefault();this.style.borderColor='#a855f7'"
            ondragleave="this.style.borderColor=''"
            ondrop="_dcNewClassFileDrop(event)">
            <i class="bi bi-file-earmark-text" style="font-size:26px;color:var(--text-3)"></i>
            <p style="font-size:13px;color:var(--text-2);margin-top:8px;margin-bottom:0">Arraste o CSV ou clique para selecionar</p>
          </div>
          <input type="file" id="_dcNewClassFile" accept=".csv,text/csv" style="display:none" onchange="_dcNewClassFileSelected(this)">
          <div id="_dcNewClassPreview" style="display:none;margin-top:10px">
            <div style="font-size:11px;color:var(--text-3);margin-bottom:6px"><span id="_dcNewClassCount">0</span> jogadores detectados</div>
            <div id="_dcNewClassTable" style="max-height:200px;overflow-y:auto;border:1px solid var(--border);border-radius:var(--radius-sm)"></div>
            <div class="d-flex gap-2 justify-content-end mt-3">
              <button class="btn-ghost" onclick="document.getElementById('_dcEditModal').remove()">Cancelar</button>
              <button class="btn-ghost" style="color:#a855f7" onclick="_dcSaveNewClassWithCSV()"><i class="bi bi-check-lg me-1"></i>Criar com esses jogadores</button>
            </div>
          </div>
        </div>
        ` : `
        <div style="border-top:1px solid var(--border);padding-top:14px;margin-bottom:14px">
          <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--text-3);margin-bottom:10px">
            Jogadores (${players.length})
          </div>
          <div id="_dcPlayerList" style="max-height:320px;overflow-y:auto;border:1px solid var(--border);border-radius:var(--radius-sm)">
            ${_dcRenderPlayerList()}
          </div>
          <div style="margin-top:12px;padding:12px;background:var(--panel-2);border-radius:var(--radius-sm)">
            <div style="font-size:11px;font-weight:600;color:var(--text-3);text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px">Adicionar jogador</div>
            <div class="d-flex gap-2 flex-wrap">
              <input type="text" id="_dcNewName" class="form-control form-control-sm" placeholder="Nome" style="flex:2;min-width:120px">
              <select id="_dcNewPos" class="form-select form-select-sm" style="flex:1;min-width:80px">
                ${['PG','SG','SF','PF','C'].map(p=>`<option>${p}</option>`).join('')}
              </select>
              <input type="number" id="_dcNewOvr" class="form-control form-control-sm" placeholder="OVR" min="1" max="99" style="flex:1;min-width:60px">
              <input type="number" id="_dcNewAge" class="form-control form-control-sm" placeholder="Idade" min="18" max="45" style="flex:1;min-width:60px">
              <input type="number" id="_dcNewOrdem" class="form-control form-control-sm" placeholder="Ordem (opcional)" min="1" style="flex:1;min-width:90px">
              <button class="btn-ghost" style="color:#22c55e;white-space:nowrap" onclick="_dcAddPlayer()"><i class="bi bi-plus-lg me-1"></i>Add</button>
            </div>
          </div>
        </div>
        <div style="border-top:1px solid var(--border);padding-top:14px">
          <div style="font-size:11px;color:var(--text-2);margin-bottom:8px">${
            // Numa classe vazia, "substituir tudo" descreve mal o que o botão
            // faz — e é o caminho de quem criou primeiro pra importar depois.
            players.length ? 'Substituir todos via CSV:' : 'Importe os jogadores desta classe:'}</div>
          <button class="btn-ghost" style="${players.length ? '' : 'color:#a855f7'}" onclick="_draftClassReplaceCSVModal(${templateId})">
            <i class="bi bi-upload me-1"></i>${players.length ? 'Importar CSV (substituir tudo)' : 'Importar jogadores por CSV'}
          </button>
        </div>`}
      </div>
    </div>`;
  document.body.appendChild(modal);
}

let _dcNewClassRows = [];

function _dcNewClassFileDrop(e) { e.preventDefault(); document.getElementById('_dcNewClassDropzone').style.borderColor=''; const f=e.dataTransfer.files?.[0]; if(f)_dcNewClassParseFile(f); }
function _dcNewClassFileSelected(inp) { const f=inp.files?.[0]; if(f)_dcNewClassParseFile(f); }
function _dcNewClassParseFile(file) {
  const reader = new FileReader();
  reader.onload = e => {
    const rows = _dcParseCSV(e.target.result);
    if (!rows || !rows.length) { showAlert('danger','CSV inválido ou sem dados. Colunas: name, position, ovr, age'); return; }
    _dcNewClassRows = rows;
    document.getElementById('_dcNewClassCount').textContent = rows.length;
    document.getElementById('_dcNewClassTable').innerHTML = _dcPreviewTable(rows);
    document.getElementById('_dcNewClassPreview').style.display = 'block';
    document.getElementById('_dcNewClassDropzone').style.display = 'none';
  };
  reader.readAsText(file,'UTF-8');
}

async function _dcSaveNewClassWithCSV() {
  const name = document.getElementById('_dcNameInput')?.value.trim();
  if (!name) { showAlert('warning','Digite o nome da classe'); return; }
  if (!_dcNewClassRows.length) { showAlert('warning','Nenhum jogador no CSV'); return; }
  try {
    const res = await api('admin.php?action=draft_class_bank', { method:'POST', body: JSON.stringify({ sub:'save', name, league: appState.currentLeague || null, players: _dcNewClassRows }) });
    document.getElementById('_dcEditModal').remove();
    showAlert('success', res.message || 'Classe criada!');
    _dcNewClassRows = [];
    showDraftClassBank();
  } catch(e) { showAlert('danger', e.error||'Erro'); }
}

function _dcRenderPlayerList() {
  if (!_dcEditPlayers.length) return '<div class="empty-state" style="padding:16px">Nenhum jogador</div>';
  return `<table style="width:100%;border-collapse:collapse;font-size:12px">
    <thead><tr style="background:var(--panel-2)">
      <th style="padding:7px 10px;text-align:left;color:var(--text-3);font-weight:500">Nome</th>
      <th style="padding:7px;color:var(--text-3);font-weight:500;text-align:center">Pos</th>
      <th style="padding:7px;color:var(--text-3);font-weight:500;text-align:center">OVR</th>
      <th style="padding:7px;color:var(--text-3);font-weight:500;text-align:center">Idade</th>
      <th style="padding:7px;color:var(--text-3);font-weight:500;text-align:center">Ordem</th>
      <th style="padding:7px;width:60px"></th>
    </tr></thead>
    <tbody>
      ${_dcEditPlayers.map((p, i) => `
        <tr style="border-top:1px solid var(--border)" id="_dcRow_${p.id || i}">
          <td style="padding:5px 10px">
            <input type="text" value="${escapeHtml(p.name)}" class="form-control form-control-sm" style="font-size:11px" onchange="_dcEditPlayerField(${p.id || i}, 'name', this.value)">
          </td>
          <td style="padding:5px 7px;text-align:center">
            <select class="form-select form-select-sm" style="font-size:11px;padding:2px 4px" onchange="_dcEditPlayerField(${p.id || i}, 'position', this.value)">
              ${['PG','SG','SF','PF','C'].map(pos=>`<option ${pos===p.position?'selected':''}>${pos}</option>`).join('')}
            </select>
          </td>
          <td style="padding:5px 7px;text-align:center">
            <input type="number" value="${p.ovr}" min="1" max="99" class="form-control form-control-sm" style="font-size:11px;width:52px" onchange="_dcEditPlayerField(${p.id || i}, 'ovr', this.value)">
          </td>
          <td style="padding:5px 7px;text-align:center">
            <input type="number" value="${p.age}" min="18" max="45" class="form-control form-control-sm" style="font-size:11px;width:52px" onchange="_dcEditPlayerField(${p.id || i}, 'age', this.value)">
          </td>
          <td style="padding:5px 7px;text-align:center">
            <input type="number" value="${p.pick_hint ?? ''}" min="1" placeholder="—" class="form-control form-control-sm" style="font-size:11px;width:52px" onchange="_dcEditPlayerField(${p.id || i}, 'pick_hint', this.value)">
          </td>
          <td style="padding:5px 7px;text-align:center">
            <button class="btn-ghost" style="padding:2px 6px;color:#ef4444" onclick="_dcDeletePlayer(${p.id})"><i class="bi bi-trash"></i></button>
          </td>
        </tr>`).join('')}
    </tbody>
  </table>`;
}

function _dcEditPlayerField(playerId, field, value) {
  const p = _dcEditPlayers.find(x => x.id == playerId);
  if (!p) return;
  if (field === 'pick_hint') {
    p[field] = value === '' ? null : parseInt(value, 10);
  } else {
    p[field] = field === 'ovr' || field === 'age' ? parseInt(value, 10) : value;
  }
  // Salva no backend
  api('admin.php?action=draft_class_bank', {
    method: 'POST',
    body: JSON.stringify({ sub: 'update_player', player_id: playerId, player: { name: p.name, position: p.position, ovr: p.ovr, age: p.age, pick_hint: p.pick_hint ?? null } })
  }).catch(e => showAlert('danger', e.error || 'Erro ao atualizar'));
}

async function _dcAddPlayer() {
  const name = document.getElementById('_dcNewName')?.value.trim();
  const pos  = document.getElementById('_dcNewPos')?.value;
  const ovr  = parseInt(document.getElementById('_dcNewOvr')?.value, 10);
  const age  = parseInt(document.getElementById('_dcNewAge')?.value, 10);
  const ordemRaw = document.getElementById('_dcNewOrdem')?.value;
  const pickHint = ordemRaw ? parseInt(ordemRaw, 10) : null;
  if (!name || !pos || isNaN(ovr) || isNaN(age)) { showAlert('warning', 'Preencha todos os campos'); return; }
  try {
    const res = await api('admin.php?action=draft_class_bank', {
      method: 'POST',
      body: JSON.stringify({ sub: 'add_player', template_id: _dcEditTemplateId, player: { name, position: pos, ovr, age, pick_hint: pickHint } })
    });
    _dcEditPlayers.push({ id: res.id, name, position: pos, ovr, age, pick_hint: pickHint });
    document.getElementById('_dcPlayerList').innerHTML = _dcRenderPlayerList();
    document.getElementById('_dcNewName').value = '';
    document.getElementById('_dcNewOvr').value = '';
    document.getElementById('_dcNewAge').value = '';
    document.getElementById('_dcNewOrdem').value = '';
    showAlert('success', 'Jogador adicionado!');
  } catch(e) { showAlert('danger', e.error || 'Erro'); }
}

async function _dcDeletePlayer(playerId) {
  if (!await confirmarSite('Remover este jogador da classe?')) return;
  try {
    await api('admin.php?action=draft_class_bank', { method: 'POST', body: JSON.stringify({ sub: 'delete_player', player_id: playerId }) });
    _dcEditPlayers = _dcEditPlayers.filter(p => p.id !== playerId);
    document.getElementById('_dcPlayerList').innerHTML = _dcRenderPlayerList();
  } catch(e) { showAlert('danger', e.error || 'Erro'); }
}

async function _dcRenameClass() {
  const name = document.getElementById('_dcNameInput')?.value.trim();
  if (!name) return;
  await api('admin.php?action=draft_class_bank', { method: 'POST', body: JSON.stringify({ sub: 'rename', template_id: _dcEditTemplateId, name }) });
  showAlert('success', 'Renomeada!');
  showDraftClassBank();
}

async function _dcSaveNewClass() {
  const name = document.getElementById('_dcNameInput')?.value.trim();
  if (!name) { showAlert('warning', 'Digite um nome'); return; }
  try {
    // A liga vai junto aqui também. Sem ela, a classe criada vazia caía no
    // "sem liga" e não entrava na roleta de ninguém até alguém atribuir na
    // mão — logo no caminho de quem quer criar primeiro e importar depois.
    const res = await api('admin.php?action=draft_class_bank', {
      method: 'POST',
      body: JSON.stringify({ sub: 'save', name, league: appState.currentLeague || null, players: [] })
    });
    document.getElementById('_dcEditModal').remove();
    showAlert('success', 'Classe criada. Abra ela para importar os jogadores.');
    showDraftClassBank();
  } catch(e) { showAlert('danger', e.error || 'Erro'); }
}

async function _draftClassDelete(templateId, name) {
  if (!await confirmarSite(`Excluir a classe "${name}"? Esta ação não pode ser desfeita.`)) return;
  try {
    await api('admin.php?action=draft_class_bank', { method: 'POST', body: JSON.stringify({ sub: 'delete', template_id: templateId }) });
    showAlert('success', 'Classe excluída.');
    showDraftClassBank();
  } catch(e) { showAlert('danger', e.error || 'Erro'); }
}

// ── Modal: importar CSV para o banco (nova classe) ────────────────
let _dcImportRows = [];

function _draftClassImportModal() {
  _dcImportRows = [];
  const existing = document.getElementById('_dcImportModal');
  if (existing) existing.remove();

  const modal = document.createElement('div');
  modal.id = '_dcImportModal';
  modal.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.75);z-index:1100;display:flex;align-items:center;justify-content:center;padding:16px;overflow-y:auto';
  modal.innerHTML = `
    <div class="panel" style="width:100%;max-width:560px;padding:0">
      <div class="panel-header" style="padding:16px 18px 0">
        <div class="panel-title"><i class="bi bi-upload" style="color:#a855f7"></i> Importar CSV — Nova Classe</div>
        <button class="btn-ghost" style="padding:4px 8px" onclick="document.getElementById('_dcImportModal').remove()"><i class="bi bi-x-lg"></i></button>
      </div>
      <div style="padding:16px 18px">
        <div class="mb-3">
          <label style="font-size:12px;color:var(--text-2);display:block;margin-bottom:4px">Nome da classe</label>
          <input type="text" id="_dcImportName" class="form-control" placeholder="Ex: Draft 2040">
        </div>
        <p style="font-size:12px;color:var(--text-3);margin-bottom:10px">CSV com colunas: <strong style="color:var(--text)">name, position, ovr, age</strong></p>
        <div id="_dcImportDropzone"
          style="border:2px dashed var(--border-md);border-radius:var(--radius-sm);padding:24px;text-align:center;cursor:pointer;transition:border-color .2s;margin-bottom:12px"
          onclick="document.getElementById('_dcImportFile').click()"
          ondragover="event.preventDefault();this.style.borderColor='#a855f7'"
          ondragleave="this.style.borderColor=''"
          ondrop="_dcImportDrop(event)">
          <i class="bi bi-file-earmark-text" style="font-size:28px;color:var(--text-3)"></i>
          <p style="font-size:13px;color:var(--text-2);margin-top:8px;margin-bottom:0">Arraste o CSV ou clique para selecionar</p>
        </div>
        <input type="file" id="_dcImportFile" accept=".csv,text/csv" style="display:none" onchange="_dcImportFileSelected(this)">
        <div id="_dcImportPreview" style="display:none">
          <div style="font-size:11px;font-weight:600;color:var(--text-3);text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px">
            Preview — <span id="_dcImportCount">0</span> jogadores
          </div>
          <div id="_dcImportTable" style="max-height:200px;overflow-y:auto;border:1px solid var(--border);border-radius:var(--radius-sm)"></div>
          <div class="d-flex gap-2 justify-content-end mt-3">
            <button class="btn-ghost" onclick="document.getElementById('_dcImportModal').remove()">Cancelar</button>
            <button class="btn-ghost" style="color:#a855f7" onclick="_dcImportConfirm()"><i class="bi bi-check-lg me-1"></i>Salvar Classe</button>
          </div>
        </div>
      </div>
    </div>`;
  document.body.appendChild(modal);
}

function _draftClassReplaceCSVModal(templateId) {
  _dcImportRows = [];
  _dcEditTemplateId = templateId;
  const existing = document.getElementById('_dcReplaceModal');
  if (existing) existing.remove();

  const modal = document.createElement('div');
  modal.id = '_dcReplaceModal';
  modal.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.75);z-index:1200;display:flex;align-items:center;justify-content:center;padding:16px;overflow-y:auto';
  modal.innerHTML = `
    <div class="panel" style="width:100%;max-width:520px;padding:0">
      <div class="panel-header" style="padding:16px 18px 0">
        <div class="panel-title"><i class="bi bi-upload" style="color:#f59e0b"></i> Substituir via CSV</div>
        <button class="btn-ghost" style="padding:4px 8px" onclick="document.getElementById('_dcReplaceModal').remove()"><i class="bi bi-x-lg"></i></button>
      </div>
      <div style="padding:16px 18px">
        <div class="alert alert-warning mb-3" style="font-size:12px"><i class="bi bi-exclamation-triangle me-1"></i>Isso <strong>apagará todos os jogadores</strong> da classe e substituirá pelo CSV.</div>
        <p style="font-size:12px;color:var(--text-3);margin-bottom:10px">CSV: <strong style="color:var(--text)">name, position, ovr, age</strong></p>
        <div id="_dcReplaceDropzone"
          style="border:2px dashed var(--border-md);border-radius:var(--radius-sm);padding:24px;text-align:center;cursor:pointer;transition:border-color .2s"
          onclick="document.getElementById('_dcReplaceFile').click()"
          ondragover="event.preventDefault();this.style.borderColor='#f59e0b'"
          ondragleave="this.style.borderColor=''"
          ondrop="_dcReplaceFileDrop(event)">
          <i class="bi bi-file-earmark-text" style="font-size:28px;color:var(--text-3)"></i>
          <p style="font-size:13px;color:var(--text-2);margin-top:8px;margin-bottom:0">Arraste ou clique</p>
        </div>
        <input type="file" id="_dcReplaceFile" accept=".csv,text/csv" style="display:none" onchange="_dcReplaceFileSelected(this)">
        <div id="_dcReplacePreview" style="display:none">
          <div style="font-size:11px;font-weight:600;color:var(--text-3);text-transform:uppercase;letter-spacing:.5px;margin:10px 0 6px">
            <span id="_dcReplaceCount">0</span> jogadores
          </div>
          <div id="_dcReplaceTable" style="max-height:180px;overflow-y:auto;border:1px solid var(--border);border-radius:var(--radius-sm)"></div>
          <div class="d-flex gap-2 justify-content-end mt-3">
            <button class="btn-ghost" onclick="document.getElementById('_dcReplaceModal').remove()">Cancelar</button>
            <button class="btn-ghost" style="color:#f59e0b" onclick="_dcReplaceConfirm()"><i class="bi bi-check-lg me-1"></i>Substituir tudo</button>
          </div>
        </div>
      </div>
    </div>`;
  document.body.appendChild(modal);
}

function _dcParseCSV(text) {
  const lines = text.split(/\r?\n/).filter(l => l.trim());
  if (lines.length < 2) return null;
  const sep = lines[0].includes(';') ? ';' : ',';
  const headers = lines[0].split(sep).map(h => h.trim().toLowerCase().replace(/['"]/g,''));
  const ni = headers.indexOf('name'), pi = headers.indexOf('position'), oi = headers.indexOf('ovr'), ai = headers.indexOf('age');
  const hi = headers.indexOf('ordem') >= 0 ? headers.indexOf('ordem') : headers.indexOf('pick_hint');
  if (ni<0||pi<0||oi<0||ai<0) return null;
  const rows = [];
  for (let i=1;i<lines.length;i++) {
    const cols = lines[i].split(sep).map(c => c.trim().replace(/^["']|["']$/g,''));
    const name = cols[ni]||'', pos = (cols[pi]||'').toUpperCase(), ovr=parseInt(cols[oi],10), age=parseInt(cols[ai],10);
    if (!name||!pos||isNaN(ovr)||isNaN(age)||ovr<=0||age<=0) continue;
    const hintRaw = hi >= 0 ? cols[hi] : '';
    const pick_hint = hintRaw && parseInt(hintRaw, 10) > 0 ? parseInt(hintRaw, 10) : null;
    rows.push({ name, position:pos, ovr, age, pick_hint });
  }
  return rows;
}

function _dcPreviewTable(rows) {
  return `<table style="width:100%;border-collapse:collapse;font-size:12px">
    <thead><tr style="background:var(--panel-2)">
      <th style="padding:6px 10px;text-align:left;color:var(--text-3)">Nome</th>
      <th style="padding:6px 8px;color:var(--text-3);text-align:center">Pos</th>
      <th style="padding:6px 8px;color:var(--text-3);text-align:center">OVR</th>
      <th style="padding:6px 8px;color:var(--text-3);text-align:center">Idade</th>
      <th style="padding:6px 8px;color:var(--text-3);text-align:center">Ordem</th>
    </tr></thead>
    <tbody>${rows.slice(0,50).map(p=>`
      <tr style="border-top:1px solid var(--border)">
        <td style="padding:5px 10px;color:var(--text)">${escapeHtml(p.name)}</td>
        <td style="padding:5px 8px;color:var(--text-3);text-align:center">${p.position}</td>
        <td style="padding:5px 8px;color:#a855f7;font-weight:600;text-align:center">${p.ovr}</td>
        <td style="padding:5px 8px;color:var(--text-3);text-align:center">${p.age}</td>
        <td style="padding:5px 8px;color:var(--text-3);text-align:center">${p.pick_hint ?? '—'}</td>
      </tr>`).join('')}
    ${rows.length>50?`<tr><td colspan="5" style="padding:5px 10px;color:var(--text-3);text-align:center">+${rows.length-50} mais…</td></tr>`:''}
    </tbody></table>`;
}

function _dcImportDrop(e) { e.preventDefault(); document.getElementById('_dcImportDropzone').style.borderColor=''; const f=e.dataTransfer.files?.[0]; if(f)_dcImportParseFile(f); }
function _dcImportFileSelected(inp) { const f=inp.files?.[0]; if(f)_dcImportParseFile(f); }
function _dcImportParseFile(file) {
  const reader = new FileReader();
  reader.onload = e => {
    const rows = _dcParseCSV(e.target.result);
    if (!rows) { showAlert('danger','Cabeçalho inválido. Esperado: name, position, ovr, age'); return; }
    _dcImportRows = rows;
    document.getElementById('_dcImportCount').textContent = rows.length;
    document.getElementById('_dcImportTable').innerHTML = _dcPreviewTable(rows);
    document.getElementById('_dcImportPreview').style.display = 'block';
    document.getElementById('_dcImportDropzone').style.display = 'none';
  };
  reader.readAsText(file,'UTF-8');
}

async function _dcImportConfirm() {
  const name = document.getElementById('_dcImportName')?.value.trim();
  if (!name) { showAlert('warning','Digite o nome da classe'); return; }
  if (!_dcImportRows.length) { showAlert('warning','Nenhum jogador'); return; }
  try {
    // A liga tem que ir junto aqui também. Esta é a TERCEIRA rota que cria
    // classe (as outras duas são "Criar vazia" e "Criar com esses jogadores"),
    // e era a única que ainda mandava sem liga — quem criasse a classe por
    // aqui via ela sumir do bolo da liga e reaparecer em "Classes sem liga".
    const res = await api('admin.php?action=draft_class_bank', {
      method:'POST',
      body: JSON.stringify({ sub:'save', name, league: appState.currentLeague || null, players: _dcImportRows })
    });
    document.getElementById('_dcImportModal').remove();
    showAlert('success', res.message || 'Classe salva!');
    showDraftClassBank();
  } catch(e) { showAlert('danger', e.error||'Erro'); }
}

function _dcReplaceFileDrop(e) { e.preventDefault(); document.getElementById('_dcReplaceDropzone').style.borderColor=''; const f=e.dataTransfer.files?.[0]; if(f)_dcReplaceParseFile(f); }
function _dcReplaceFileSelected(inp) { const f=inp.files?.[0]; if(f)_dcReplaceParseFile(f); }
function _dcReplaceParseFile(file) {
  const reader = new FileReader();
  reader.onload = e => {
    const rows = _dcParseCSV(e.target.result);
    if (!rows) { showAlert('danger','Cabeçalho inválido'); return; }
    _dcImportRows = rows;
    document.getElementById('_dcReplaceCount').textContent = rows.length;
    document.getElementById('_dcReplaceTable').innerHTML = _dcPreviewTable(rows);
    document.getElementById('_dcReplacePreview').style.display = 'block';
    document.getElementById('_dcReplaceDropzone').style.display = 'none';
  };
  reader.readAsText(file,'UTF-8');
}

async function _dcReplaceConfirm() {
  if (!_dcImportRows.length) { showAlert('warning','Nenhum jogador'); return; }
  const tplId = _dcEditTemplateId;
  if (!tplId) { showAlert('danger','Template não identificado'); return; }
  try {
    // Deleta todos jogadores atuais e recria via save + delete antigo
    // Abordagem: deletar o template e recriar não é viável (mudaria id)
    // Fazemos: limpar jogadores via sub=replace_players (novo sub)
    await api('admin.php?action=draft_class_bank', { method:'POST', body: JSON.stringify({ sub:'replace_players', template_id: tplId, players: _dcImportRows }) });
    document.getElementById('_dcReplaceModal').remove();
    // Recarrega lista de jogadores no modal de edição
    _dcEditPlayers = _dcImportRows;
    const listEl = document.getElementById('_dcPlayerList');
    if (listEl) {
      // Recarrega do servidor para ter os IDs corretos
      const d = await api(`admin.php?action=draft_class_bank&sub=players&template_id=${tplId}`);
      _dcEditPlayers = d.players || [];
      listEl.innerHTML = _dcRenderPlayerList();
    }
    showAlert('success',`${_dcImportRows.length} jogadores importados!`);
  } catch(e) { showAlert('danger', e.error||'Erro'); }
}

// ── Force Trade (admin) ───────────────────────────────────────────────────────
const _ftState = { teams: [], assets: { players: {}, picks: {} }, itemCount: 0 };

async function _ftLoadAssets(teamId, type) {
  if (_ftState.assets[type][teamId]) return _ftState.assets[type][teamId];
  try {
    const endpoint = type === 'players' ? `players.php?team_id=${teamId}` : `picks.php?team_id=${teamId}`;
    const d = await api(endpoint);
    const list = type === 'players' ? (d.players || []) : (d.picks || []);
    _ftState.assets[type][teamId] = list;
    return list;
  } catch (e) { return []; }
}

function _ftTeamName(teamId) {
  const t = _ftState.teams.find(x => x.id == teamId);
  if (!t) return '#' + teamId;
  return (t.city ? t.city + ' ' : '') + t.name;
}

function _ftRebuildFromSelects() {
  document.querySelectorAll('#ftItemsContainer .ft-item-row').forEach(row => {
    const sel = row.querySelector('.ft-from-select');
    if (sel) _ftUpdateItemType(row.dataset.rowId);
  });
}

function _ftGetCheckedTeams() {
  return Array.from(document.querySelectorAll('#ftTeamsGrid input[type=checkbox]:checked'))
    .map(el => parseInt(el.value)).filter(Number.isFinite);
}

async function _ftUpdateItemType(rowId) {
  const row = document.querySelector(`[data-row-id="${rowId}"]`);
  if (!row) return;
  const fromSel  = row.querySelector('.ft-from-select');
  const typeSel  = row.querySelector('.ft-type-select');
  const itemSel  = row.querySelector('.ft-item-select');
  if (!fromSel || !typeSel || !itemSel) return;

  const fromId = parseInt(fromSel.value);
  const type   = typeSel.value;
  if (!fromId) { itemSel.innerHTML = '<option value="">— escolha origem primeiro —</option>'; return; }

  itemSel.innerHTML = '<option value="">Carregando...</option>';
  itemSel.disabled = true;

  const list = await _ftLoadAssets(fromId, type);
  itemSel.disabled = false;

  if (type === 'players') {
    itemSel.innerHTML = `<option value="">— Jogador —</option>` +
      list.map(p => `<option value="${p.id}">${escapeHtml(p.name)} (${p.position||''} OVR ${p.ovr||p.overall||'?'})</option>`).join('');
  } else {
    itemSel.innerHTML = `<option value="">— Pick —</option>` +
      list.map(p => {
        const orig = p.original_team_name || '';
        return `<option value="${p.id}">${p.season_year} R${p.round}${orig ? ' – ' + escapeHtml(orig) : ''}</option>`;
      }).join('');
  }
}

function _ftAddItem() {
  const checkedIds = _ftGetCheckedTeams();
  if (checkedIds.length < 2) {
    alert('Selecione pelo menos 2 times antes de adicionar itens.'); return;
  }
  const container = document.getElementById('ftItemsContainer');
  const rowId = `ftItem_${_ftState.itemCount++}`;
  const teamOptions = checkedIds.map(id => `<option value="${id}">${escapeHtml(_ftTeamName(id))}</option>`).join('');

  const div = document.createElement('div');
  div.className = 'ft-item-row d-flex align-items-center gap-2 mb-2 flex-wrap';
  div.dataset.rowId = rowId;
  div.innerHTML = `
    <select class="form-select form-select-sm ft-from-select" style="max-width:160px"
      onchange="_ftUpdateItemType('${rowId}')">
      <option value="">De (time)</option>${teamOptions}
    </select>
    <select class="form-select form-select-sm ft-type-select" style="max-width:100px"
      onchange="_ftUpdateItemType('${rowId}')">
      <option value="players">Jogador</option>
      <option value="picks">Pick</option>
    </select>
    <select class="form-select form-select-sm ft-item-select" style="flex:1;min-width:180px">
      <option value="">— escolha origem primeiro —</option>
    </select>
    <select class="form-select form-select-sm ft-to-select" style="max-width:160px">
      <option value="">Para (time)</option>${teamOptions}
    </select>
    <button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('.ft-item-row').remove()"
      style="padding:4px 8px;flex-shrink:0"><i class="bi bi-trash"></i></button>`;
  container.appendChild(div);
}

async function showForceTradeModal(initialLeague) {
  // cleanup state
  _ftState.teams = [];
  _ftState.assets.players = {};
  _ftState.assets.picks = {};
  _ftState.itemCount = 0;

  const modalId = 'forceTradeModal';
  document.getElementById(modalId)?.remove();

  const html = `
  <div class="modal fade" id="${modalId}" tabindex="-1">
    <div class="modal-dialog modal-xl">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">
            <i class="bi bi-lightning-fill me-2" style="color:var(--red)"></i>
            Force Trade — ${initialLeague} <small class="text-muted" style="font-size:12px;font-weight:400">(sem aceite — executa imediatamente)</small>
          </h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="alert alert-warning" style="font-size:13px">
            <i class="bi bi-exclamation-triangle-fill me-1"></i>
            Esta trade será executada <strong>imediatamente</strong>, apenas entre times da liga <strong>${initialLeague}</strong>. Jogadores e picks serão transferidos sem pedido de aceite.
          </div>
          <input type="hidden" id="ftLeagueSelect" value="${initialLeague}">

          <div class="mb-3">
            <div class="d-flex align-items-center justify-content-between mb-2">
              <label class="form-label mb-0">Times participantes</label>
              <span id="ftTeamsStatus" class="text-muted" style="font-size:12px"></span>
            </div>
            <div id="ftTeamsGrid" class="d-flex flex-wrap gap-2">
              <div class="text-muted" style="font-size:13px">Carregando...</div>
            </div>
          </div>

          <div class="mb-3">
            <div class="d-flex align-items-center justify-content-between mb-2">
              <label class="form-label mb-0">Itens da troca</label>
              <button type="button" class="btn btn-sm btn-outline-orange" onclick="_ftAddItem()">
                <i class="bi bi-plus-lg"></i> Adicionar item
              </button>
            </div>
            <div id="ftItemsContainer"></div>
          </div>

          <div class="mb-2">
            <label class="form-label">Observações (opcional)</label>
            <textarea class="form-control" id="ftNotes" rows="2" placeholder="Motivo da force trade..."></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="button" class="btn btn-orange" id="ftSubmitBtn" onclick="ftSubmit()">
            <i class="bi bi-lightning-fill me-1"></i> Executar Force Trade
          </button>
        </div>
      </div>
    </div>
  </div>`;

  document.body.insertAdjacentHTML('beforeend', html);
  const modal = new bootstrap.Modal(document.getElementById(modalId));
  modal.show();
  document.getElementById(modalId).addEventListener('hidden.bs.modal', function() { this.remove(); });

  await ftLoadTeams();
}

async function ftLoadTeams() {
  const league = document.getElementById('ftLeagueSelect')?.value;
  if (!league) return;

  _ftState.assets.players = {};
  _ftState.assets.picks = {};

  const grid = document.getElementById('ftTeamsGrid');
  const status = document.getElementById('ftTeamsStatus');
  if (grid) grid.innerHTML = '<div class="text-muted" style="font-size:13px">Carregando times...</div>';

  try {
    const d = await api(`admin.php?action=teams&league=${league}`);
    _ftState.teams = d.teams || [];

    if (!_ftState.teams.length) {
      grid.innerHTML = '<div class="text-muted" style="font-size:13px">Nenhum time nesta liga.</div>';
      return;
    }

    grid.innerHTML = _ftState.teams.map(t => {
      const name = (t.city ? t.city + ' ' : '') + t.name;
      return `<label style="display:flex;align-items:center;gap:6px;background:var(--panel-2);border:1px solid var(--border);border-radius:8px;padding:6px 10px;cursor:pointer;font-size:13px">
        <input type="checkbox" value="${t.id}" onchange="ftOnTeamChange()" style="width:14px;height:14px">
        ${escapeHtml(name)}
      </label>`;
    }).join('');

    if (status) status.textContent = `${_ftState.teams.length} times na liga`;

    // Clear items when league changes
    const container = document.getElementById('ftItemsContainer');
    if (container) container.innerHTML = '';
  } catch (e) {
    if (grid) grid.innerHTML = `<div class="alert alert-danger" style="font-size:13px">Erro: ${escapeHtml(e.error || 'desconhecido')}</div>`;
  }
}

function ftOnTeamChange() {
  // Clear items when team selection changes, as selects are stale
  const container = document.getElementById('ftItemsContainer');
  if (container) container.innerHTML = '';
  _ftState.assets.players = {};
  _ftState.assets.picks = {};
}

async function ftSubmit() {
  const league = document.getElementById('ftLeagueSelect')?.value;
  const checkedTeams = _ftGetCheckedTeams();
  const notes = document.getElementById('ftNotes')?.value?.trim() || '';

  if (!league) { alert('Selecione uma liga.'); return; }
  if (checkedTeams.length < 2) { alert('Selecione pelo menos 2 times.'); return; }

  const rows = document.querySelectorAll('#ftItemsContainer .ft-item-row');
  if (!rows.length) { alert('Adicione pelo menos um item na troca.'); return; }

  const items = [];
  for (const row of rows) {
    const fromId  = parseInt(row.querySelector('.ft-from-select')?.value || '0');
    const type    = row.querySelector('.ft-type-select')?.value;
    const itemId  = parseInt(row.querySelector('.ft-item-select')?.value || '0');
    const toId    = parseInt(row.querySelector('.ft-to-select')?.value || '0');

    if (!fromId || !itemId || !toId) { alert('Preencha todos os campos de cada item.'); return; }
    if (fromId === toId) { alert('Origem e destino de um item não podem ser o mesmo time.'); return; }

    const entry = { from_team_id: fromId, to_team_id: toId };
    if (type === 'players') entry.player_id = itemId;
    else entry.pick_id = itemId;
    items.push(entry);
  }

  const btn = document.getElementById('ftSubmitBtn');
  if (btn) { btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Executando...'; }

  try {
    const result = await api('trades.php?action=force_trade', {
      method: 'POST',
      body: JSON.stringify({ league, teams: checkedTeams, items, notes })
    });

    bootstrap.Modal.getInstance(document.getElementById('forceTradeModal'))?.hide();
    showAlert('success', `Force Trade #${result.trade_id} executada com sucesso!`);
  } catch (e) {
    showAlert('danger', e.error || 'Erro ao executar force trade');
    if (btn) { btn.disabled = false; btn.innerHTML = '<i class="bi bi-lightning-fill me-1"></i> Executar Force Trade'; }
  }
}


/* ── Congelamento da classificação do ranking ──────────────
   O fim da sprint zera os pontos; congelar antes preserva quem ganhou o
   ciclo e alimenta a variação de posição mostrada em rankings.php.
   O reset de temporada já faz isso sozinho — este botão é para congelar
   num momento escolhido pelo admin. */
async function congelarRanking(league) {
  if (!await confirmarSite(`Congelar a classificação atual da ${league}?\n\n`
    + 'A ordem de hoje fica salva no histórico e passa a ser a referência das setas de variação no ranking.')) return;
  try {
    const rotulo = await perguntarSite('Nome deste congelamento (opcional):', 'Fim da sprint') || '';
    const d = await api(`history-points.php?action=save_ranking_snapshot&league=${encodeURIComponent(league)}&label=${encodeURIComponent(rotulo)}`);
    if (d.success) {
      showAlert('success', `Classificação congelada: ${d.saved} times salvos.`);
    } else {
      showAlert('danger', d.error || 'Não foi possível congelar.');
    }
  } catch (e) {
    showAlert('danger', e.error || 'Erro ao congelar a classificação.');
  }
}

/* carregarSnapshots() saiu junto com a lista que ela desenhava — era a
   única chamadora, e função sem quem chame é peso morto. O endpoint
   list_ranking_snapshots continua no servidor, se um dia a lista voltar. */
