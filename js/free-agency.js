/**
 * Free Agency - JavaScript
 * Propostas com moedas e aprovacao do admin
 */

let faHistoryTeamSort = null;
let faWaiversTeamSort = null;

const esc = s => String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');

function sortIndicator(direction) {
    if (direction === 'asc') return ' <i class="bi bi-caret-up-fill"></i>';
    if (direction === 'desc') return ' <i class="bi bi-caret-down-fill"></i>';
    return '';
}

function sortByTeamName(list, getName, direction) {
    if (!direction) return list;
    const sorted = [...list];
    sorted.sort((a, b) => {
        const nameA = (getName(a) || '').toLowerCase();
        const nameB = (getName(b) || '').toLowerCase();
        if (nameA === nameB) return 0;
        return direction === 'asc' ? nameA.localeCompare(nameB) : nameB.localeCompare(nameA);
    });
    return sorted;
}

function _applyFaTableLabels(root = document) {
    const tables = root.querySelectorAll('.table-responsive table, table.table');
    tables.forEach((table) => {
        const headers = Array.from(table.querySelectorAll('thead th')).map((th) => th.textContent.trim());
        table.querySelectorAll('tbody tr').forEach((tr) => {
            Array.from(tr.children).forEach((cell, idx) => {
                if (cell.tagName !== 'TD') return;
                if (!cell.dataset.label) {
                    cell.dataset.label = headers[idx] || '';
                }
            });
        });
    });
}

window.toggleFaHistoryTeamSort = function() {
    faHistoryTeamSort = faHistoryTeamSort === 'asc' ? 'desc' : 'asc';
    carregarHistoricoNovaFA();
};

window.toggleFaWaiversTeamSort = function() {
    faWaiversTeamSort = faWaiversTeamSort === 'asc' ? 'desc' : 'asc';
    if (window.__faWaiversCache) {
        renderWaiversList(window.__faWaiversCache);
    }
};

document.addEventListener('DOMContentLoaded', () => {
    console.log('🚀 Free Agency JS carregado');
    console.log('🔐 isAdmin:', isAdmin);
    console.log('🏀 userLeague:', userLeague);
    console.log('🎯 defaultAdminLeague:', defaultAdminLeague);
    
    const adminLeagueSelect = document.getElementById('adminLeagueSelect');
    if (adminLeagueSelect && defaultAdminLeague) {
        adminLeagueSelect.value = defaultAdminLeague;
        console.log('✅ adminLeagueSelect configurado com:', defaultAdminLeague);
    }
    const newAdminLeagueSelect = document.getElementById('faNewAdminLeague');
    if (newAdminLeagueSelect && defaultAdminLeague) {
        newAdminLeagueSelect.value = defaultAdminLeague;
    }
    const faLeagueSelect = document.getElementById('faLeague');
    if (faLeagueSelect && defaultAdminLeague) {
        faLeagueSelect.value = defaultAdminLeague;
    }

    initNewFreeAgency();
});

function getActiveLeague() {
    if (userLeague) return userLeague;
    const adminLeagueSelect = document.getElementById('adminLeagueSelect');
    return adminLeagueSelect?.value || defaultAdminLeague || null;
}

function getAdminLeague() {
    const adminLeagueSelect = document.getElementById('adminLeagueSelect');
    if (adminLeagueSelect?.value) {
        return adminLeagueSelect.value;
    }
    const newAdminLeagueSelect = document.getElementById('faNewAdminLeague');
    if (newAdminLeagueSelect?.value) {
        return newAdminLeagueSelect.value;
    }
    return defaultAdminLeague || null;
}

// ── Dispensados da temporada ─────────────────────────────────────────
// A lista chega inteira do servidor (só a temporada corrente, que é dezenas
// e não centenas) e os filtros trabalham em memória — busca por nome não
// deve custar uma ida ao servidor por tecla.
let dispTemporada = [];
let dispEscolhido = null;

// Duas réguas, dois jeitos de escrever: na ELITE o cap é dinheiro ("16M"),
// nas outras é soma de OVR ("9 de OVR"). Colar a unidade no número dava
// "9OVR", que ninguém lê na primeira passada.
const fmtCap = (v, u) => (u === 'M' ? `${v}M` : `${v} de OVR`);

// O espaço vira frase, porque negativo não é "espaço" — é dívida.
const fraseDeEspaco = (espaco, u) => espaco < 0
    ? `seu elenco está <strong>${fmtCap(Math.abs(espaco), u)}</strong> acima do teto`
    : `você tem <strong>${fmtCap(espaco, u)}</strong> de espaço`;

const escHtml = (t) => String(t ?? '').replace(/[&<>"']/g, c => (
    { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

async function carregarDispensadosDaTemporada() {
    const painel = document.getElementById('dispPanel');
    if (!painel) return;
    try {
        const d = await fetch('api/free-agency.php?action=dispensados').then(r => r.json());
        dispTemporada = d?.jogadores || [];
        const ano = d?.temporada?.year;
        const sub = document.getElementById('dispSub');
        if (sub) {
            sub.textContent = dispTemporada.length
                ? `${dispTemporada.length} jogador${dispTemporada.length === 1 ? '' : 'es'} sem time${ano ? ' na temporada ' + ano : ''} — dispensado de temporada passada não aparece, pode já ter se aposentado.`
                : `Ninguém foi dispensado${ano ? ' na temporada ' + ano : ' nesta temporada'} ainda.`;
        }
    } catch (e) {
        dispTemporada = [];
        const sub = document.getElementById('dispSub');
        if (sub) sub.textContent = 'Não foi possível carregar a lista.';
    }
    renderDispensadosDaTemporada();
}

function renderDispensadosDaTemporada() {
    const alvo = document.getElementById('dispLista');
    if (!alvo) return;

    const busca = (document.getElementById('dispBusca')?.value || '').trim().toLowerCase();
    const pos = document.getElementById('dispPos')?.value || '';
    // O filtro "só os que cabem" só existe onde existe cap. Fora da ELITE o
    // servidor manda cap_cabe true em todo mundo, e a caixinha viraria um
    // controle que não muda nada — pior que não ter.
    const temCap = dispTemporada.some(j => j.cap_custo != null);
    const caixaCabe = document.getElementById('dispSoCabe')?.closest('label');
    if (caixaCabe) caixaCabe.hidden = !temCap;
    const soCabe = temCap && document.getElementById('dispSoCabe')?.checked;

    const lista = dispTemporada.filter(j =>
        (!busca || j.name.toLowerCase().includes(busca)) &&
        (!pos || j.position === pos || j.secondary_position === pos) &&
        (!soCabe || j.cap_cabe !== false));

    if (!lista.length) {
        alvo.innerHTML = `<p class="empty-state">${dispTemporada.length ? 'Nenhum dispensado com esses filtros.' : 'Nenhum jogador dispensado nesta temporada.'}</p>`;
        return;
    }

    alvo.innerHTML = '<div class="disp-grid">' + lista.map(j => {
        const u = j.cap_unidade || 'M';
        const naoCabe = j.cap_cabe === false;
        const posTxt = [j.position, j.secondary_position].filter(Boolean).join('/');
        // Custo zero é o reserva que não entra no top: dizer "0 de OVR" confunde.
        const custo = j.cap_custo == null ? ''
            : j.cap_custo === 0 ? '<span>não mexe no seu cap</span>'
            : `<span class="${naoCabe ? 'custo-nao-cabe' : ''}">${fmtCap(j.cap_custo, u)} no cap</span>`;
        const origem = j.original_team_name ? ' · ex-' + escHtml(j.original_team_name) : '';
        const botao = naoCabe
            ? '<button class="disp-btn" disabled title="Não cabe no seu cap">Não cabe</button>'
            : j.minha_proposta != null
                ? `<button class="disp-btn tem-proposta" data-disp="${j.id}">Sua: ${j.minha_proposta}</button>`
                : `<button class="disp-btn" data-disp="${j.id}">Propor</button>`;
        return `<div class="disp-card ${naoCabe ? 'nao-cabe' : ''}">
            <div class="disp-pos">${escHtml(posTxt || '?')}</div>
            <div class="disp-meio">
                <div class="disp-nome" title="${escHtml(j.name)}">${escHtml(j.name)}</div>
                <div class="disp-sub">${j.age} anos${origem ? '' : ''}${custo ? ' · ' + custo : ''}${origem}</div>
            </div>
            <div class="disp-ovr"><b>${j.ovr}</b><span>OVR</span></div>
            ${botao}
        </div>`;
    }).join('') + '</div>';

    alvo.querySelectorAll('[data-disp]').forEach(b => {
        b.addEventListener('click', () => abrirModalDispensado(Number(b.dataset.disp)));
    });
}

function abrirModalDispensado(id) {
    const j = dispTemporada.find(x => x.id === id);
    if (!j) return;
    dispEscolhido = j;

    const u = j.cap_unidade || 'M';
    const posTxt = [j.position, j.secondary_position].filter(Boolean).join('/');
    document.getElementById('dispModalFicha').innerHTML =
        `<strong>${escHtml(j.name)}</strong> · ${escHtml(posTxt)} · ${j.age} anos · <strong>${j.ovr} OVR</strong>` +
        (j.original_team_name ? `<br><span style="color:var(--text-2);font-size:12.5px">Dispensado por ${escHtml(j.original_team_name)}</span>` : '');
    // Mesma regra do card: "custa 0" confunde quem não sabe que o reserva
    // fora do top não entra na conta.
    const oCusto = j.cap_custo === 0
        ? 'Ele <strong>não mexe no seu cap</strong>'
        : `Ele custa <strong>${fmtCap(j.cap_custo, u)}</strong> no seu cap`;
    document.getElementById('dispModalCap').innerHTML = j.cap_custo == null ? ''
        : oCusto +
          (capDoMeuTime ? `, e ${fraseDeEspaco(capDoMeuTime.espaco, u)}` : '') + '.';
    document.getElementById('dispModalMoedas').value = j.minha_proposta ?? 1;
    new bootstrap.Modal(document.getElementById('modalDispensado')).show();
}

async function enviarPropostaDispensado() {
    if (!dispEscolhido) return;
    const j = dispEscolhido;
    const amount = parseInt(document.getElementById('dispModalMoedas')?.value, 10);
    const priority = parseInt(document.getElementById('dispModalPrioridade')?.value, 10) || 2;
    if (!Number.isFinite(amount) || amount < 0) {
        alert('Informe uma quantidade válida de moedas (0 ou mais).');
        return;
    }
    const botao = document.getElementById('dispModalEnviar');
    if (botao) botao.disabled = true;
    try {
        const d = await fetch('api/free-agency.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'request_player',
                league: getActiveLeague() || defaultAdminLeague,
                name: j.name,
                position: j.position,
                secondary_position: j.secondary_position || null,
                age: j.age,
                ovr: j.ovr,
                amount,
                priority
            })
        }).then(r => r.json());
        if (!d.success) {
            alert(d.error || 'Erro ao enviar proposta.');
            return;
        }
        bootstrap.Modal.getInstance(document.getElementById('modalDispensado'))?.hide();
        alert('Proposta enviada!');
        carregarMinhasPropostasNovaFA();
        carregarDispensadosDaTemporada();
    } catch (e) {
        alert('Erro ao enviar proposta.');
    } finally {
        if (botao) botao.disabled = false;
    }
}

// O cap do meu time: espaço disponível e quanto cada OVR custaria nele.
// Vem inteiro do servidor (40 a 99) pra que digitar no campo de OVR atualize
// o aviso na hora, sem uma ida ao servidor por tecla.
let capDoMeuTime = null;

async function carregarCapDoMeuTime() {
    try {
        const r = await fetch('api/free-agency.php?action=cap_espaco');
        const d = await r.json();
        capDoMeuTime = d?.success && d.espaco !== null ? d : null;
    } catch (e) {
        capDoMeuTime = null;   // sem o cap, o formulário só não avisa nada
    }
    atualizarAvisoDeCap();
}

/**
 * Diz quanto o jogador digitado custaria e trava o envio se não couber.
 * Sem dado de cap, some da tela — melhor nada do que um número inventado.
 */
function atualizarAvisoDeCap() {
    const caixa = document.getElementById('faCapAviso');
    const texto = document.getElementById('faCapTexto');
    const botao = document.getElementById('faNewSubmitBtn');
    if (!caixa || !texto) return;

    const ovr = parseInt(document.getElementById('faNewOvr')?.value, 10);
    if (!capDoMeuTime || !Number.isFinite(ovr)) {
        caixa.hidden = true;
        if (botao) { botao.disabled = false; botao.style.opacity = ''; }
        return;
    }

    const u = capDoMeuTime.unidade || 'M';
    const custo = capDoMeuTime.custo_por_ovr?.[ovr] ?? 0;
    const espaco = capDoMeuTime.espaco;
    const cabe = custo <= Math.max(0, espaco);

    caixa.hidden = false;
    const oCusto = custo === 0
        ? 'não mexe no seu cap'
        : `custa <strong>${fmtCap(custo, u)}</strong> no seu cap`;
    texto.innerHTML = cabe
        ? `Um jogador de <strong>${ovr} OVR</strong> ${oCusto}. E ${fraseDeEspaco(espaco, u)}.`
        : `<strong>Não cabe no seu cap.</strong> Um jogador de ${ovr} OVR ${oCusto}, e ${fraseDeEspaco(espaco, u)}. Libere espaço ou mire num OVR menor.`;
    texto.style.color = cabe ? '' : 'var(--red)';
    if (botao) {
        botao.disabled = !cabe;
        botao.style.opacity = cabe ? '' : '.5';
    }
}

function initNewFreeAgency() {
    const form = document.getElementById('faNewRequestForm');
    if (form) {
        form.addEventListener('submit', (event) => {
            event.preventDefault();
            submitNewFaRequest();
        });
    }
    const submitBtn = document.getElementById('faNewSubmitBtn');
    if (submitBtn) {
        submitBtn.addEventListener('click', (event) => {
            event.preventDefault();
            submitNewFaRequest();
        });
    }

    document.getElementById('faNewOvr')?.addEventListener('input', atualizarAvisoDeCap);
    carregarCapDoMeuTime();

    ['dispBusca', 'dispPos', 'dispSoCabe'].forEach(id => {
        const el = document.getElementById(id);
        el?.addEventListener(el.tagName === 'INPUT' && el.type !== 'checkbox' ? 'input' : 'change', renderDispensadosDaTemporada);
    });
    document.getElementById('dispModalEnviar')?.addEventListener('click', enviarPropostaDispensado);
    carregarDispensadosDaTemporada();

    const approvedBtn = document.getElementById('faViewApprovedBtn');
    if (approvedBtn) {
        approvedBtn.addEventListener('click', () => {
            openFaApprovedModal();
        });
    }

    const inlineEl = document.getElementById('faApprovedInline');
    if (inlineEl) {
        renderAdminRequests(inlineEl);
    }

        carregarLimitesNovaFA();
    carregarMinhasPropostasNovaFA();
    carregarHistoricoNovaFA();

    const historyTab = document.getElementById('fa-history-tab');
    if (historyTab) {
        historyTab.addEventListener('shown.bs.tab', () => {
            carregarHistoricoNovaFA();
            carregarDispensados();
        });
    }

    if (isAdmin) {
        const newLeagueSelect = document.getElementById('faNewAdminLeague');
        if (newLeagueSelect) {
            newLeagueSelect.addEventListener('change', () => {
                carregarSolicitacoesNovaFA();
            });
        }
        carregarSolicitacoesNovaFA();
        const faAdminTab = document.getElementById('fa-admin-tab');
        if (faAdminTab) {
            faAdminTab.addEventListener('shown.bs.tab', () => {
                carregarSolicitacoesNovaFA();
            });
        }
    }

}

async function openFaApprovedModal() {
    const listEl = document.getElementById('faApprovedList');
    if (listEl) {
        listEl.innerHTML = '<div class="text-center py-3"><div class="spinner-border text-orange"></div></div>';
    }

    const modalEl = document.getElementById('faApprovedModal');
    if (modalEl) {
        const modal = new bootstrap.Modal(modalEl);
        modal.show();
    }

    await renderAdminRequests(listEl);
}

async function renderAdminRequests(targetEl) {
    if (!targetEl) return;
    try {
        if (!isAdmin) {
            targetEl.innerHTML = '<div class="text-light-gray">Somente administradores podem ver essas solicitações.</div>';
            return;
        }
        let league = getActiveLeague();
        if (!league && defaultAdminLeague) league = defaultAdminLeague;
        if (!league) {
            targetEl.innerHTML = '<div class="text-light-gray">Nenhuma liga selecionada.</div>';
            return;
        }

        const response = await fetch(`api/free-agency.php?action=admin_new_fa_requests&league=${encodeURIComponent(league)}`);
        const data = await response.json();
        if (!data.success || !Array.isArray(data.requests)) {
            targetEl.innerHTML = '<div class="text-danger">Erro ao carregar solicitações.</div>';
            return;
        }
        if (!data.requests.length) {
            targetEl.innerHTML = '<div class="text-light-gray">Nenhuma solicitação pendente.</div>';
            return;
        }

        const priorityBadge = p => {
            const cfg = { 1: ['#22c55e','Alta'], 2: ['#f59e0b','Média'], 3: ['#94a3b8','Baixa'] };
            const [c, l] = cfg[p] || cfg[2];
            return `<span style="font-size:10px;font-weight:700;padding:2px 7px;border-radius:999px;background:${c}18;border:1px solid ${c}44;color:${c};white-space:nowrap">P${p} ${l}</span>`;
        };

        let html = '';
        data.requests.forEach(group => {
            const req = group.request || {};
            const offers = Array.isArray(group.offers) ? group.offers : [];
            const suggested = offers.find(o => o.team_coins >= o.amount && o.roster_count < 15) || offers[0] || null;

            html += `<div style="background:var(--panel);border:1px solid var(--border);border-radius:var(--radius-sm);padding:16px 18px;margin-bottom:12px">`;

            html += `<div style="display:flex;align-items:flex-start;justify-content:space-between;gap:10px;margin-bottom:12px;flex-wrap:wrap">
                <div>
                    <div style="font-weight:700;font-size:15px;color:var(--text)">${esc(req.player_name || 'Jogador')}</div>
                    <div style="font-size:12px;color:var(--text-3);margin-top:2px">
                        ${esc(req.position || '')}${req.secondary_position ? '/' + esc(req.secondary_position) : ''} &bull; OVR <strong style="color:var(--red)">${req.ovr || '?'}</strong>${req.age ? ` &bull; ${req.age} anos` : ''}
                    </div>
                </div>
                <span style="font-size:11px;font-weight:600;padding:3px 9px;border-radius:999px;background:rgba(59,130,246,.1);color:#3b82f6;border:1px solid rgba(59,130,246,.25)">${offers.length} proposta${offers.length !== 1 ? 's' : ''}</span>
            </div>`;

            html += `<div style="display:flex;flex-direction:column;gap:7px;margin-bottom:14px">`;
            offers.forEach(offer => {
                const canAfford = offer.team_coins >= offer.amount;
                const hasSpace  = offer.roster_count < 15;
                const isSugg    = suggested && offer.id === suggested.id;
                const border    = isSugg ? 'rgba(34,197,94,.4)' : 'var(--border)';
                const warnCoins  = !canAfford ? `<span style="font-size:10px;color:#ef4444;font-weight:600"><i class="bi bi-exclamation-triangle me-1"></i>Moedas insuf. (${offer.team_coins} disp.)</span>` : '';
                const warnRoster = !hasSpace  ? `<span style="font-size:10px;color:#ef4444;font-weight:600"><i class="bi bi-exclamation-triangle me-1"></i>Elenco cheio</span>` : '';
                html += `<div style="background:var(--panel-2);border:1px solid ${border};border-radius:8px;padding:10px 12px;display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap">
                    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                        ${priorityBadge(offer.priority)}
                        <span style="font-size:13px;font-weight:600;color:var(--text)">${esc(offer.team_name)}</span>
                        ${warnCoins}${warnRoster}
                    </div>
                    <div style="display:flex;align-items:center;gap:10px;flex-shrink:0">
                        <span style="font-size:14px;font-weight:700;color:${canAfford ? 'var(--text)' : '#ef4444'}">${offer.amount} <span style="font-size:11px;font-weight:400;color:var(--text-3)">moedas</span></span>
                        ${isSugg ? `<span style="font-size:10px;font-weight:700;padding:2px 8px;border-radius:999px;background:rgba(34,197,94,.12);border:1px solid rgba(34,197,94,.35);color:#22c55e;white-space:nowrap"><i class="bi bi-trophy-fill me-1"></i>Vencedor</span>` : ''}
                    </div>
                </div>`;
            });
            html += `</div>`;

            if (suggested) {
                const ok = suggested.team_coins >= suggested.amount && suggested.roster_count < 15;
                const note = ok
                    ? `<i class="bi bi-check-circle-fill me-1" style="color:#22c55e"></i>Sugerido: <strong>${esc(suggested.team_name)}</strong> — ${suggested.amount} moedas (P${suggested.priority})`
                    : `<i class="bi bi-exclamation-triangle me-1" style="color:#f59e0b"></i>Atenção: todos os lances têm restrições. Revise antes de aprovar.`;
                html += `<div style="font-size:12px;color:var(--text-2);padding:8px 10px;background:var(--panel-2);border-radius:7px;margin-bottom:12px">${note}</div>`;
            }

            html += `<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                <select id="newFaOfferSelect-${req.id}" style="flex:1;min-width:160px;background:var(--panel-2);border:1px solid var(--border);border-radius:8px;padding:7px 10px;color:var(--text);font-size:13px;font-family:var(--font)">
                    <option value="">Selecione...</option>
                    ${offers.map(o => `<option value="${o.id}" ${suggested && o.id === suggested.id ? 'selected' : ''}>${esc(o.team_name)} — ${o.amount} moedas (P${o.priority})</option>`).join('')}
                </select>
                <button onclick="confirmarNovaFA(${req.id})"
                    style="padding:8px 16px;background:#22c55e;border:none;border-radius:8px;color:#fff;font-size:13px;font-weight:600;cursor:pointer;font-family:var(--font);white-space:nowrap">
                    <i class="bi bi-check-lg me-1"></i>Confirmar
                </button>
            </div>`;

            html += `</div>`;
        });

        targetEl.innerHTML = html;
    } catch (error) {
        targetEl.innerHTML = '<div class="text-danger">Erro ao carregar solicitações.</div>';
    }
}

async function confirmarNovaFA(requestId) {
    const select = document.getElementById(`newFaOfferSelect-${requestId}`);
    const offerId = select?.value;
    if (!offerId) {
        alert('Selecione uma proposta antes de confirmar.');
        return;
    }
    if (!confirm('Confirmar esta proposta?')) return;

    try {
        const response = await fetch('api/free-agency.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'admin_assign_request', offer_id: parseInt(offerId, 10) })
        });
        const data = await response.json();
        if (!data.success) {
            alert(data.error || 'Erro ao confirmar.');
            return;
        }
        alert('Jogador aprovado com sucesso!');
        const inlineEl = document.getElementById('faApprovedInline');
        if (inlineEl) renderAdminRequests(inlineEl);
        carregarSolicitacoesNovaFA();
    } catch (e) {
        alert('Erro ao confirmar proposta.');
    }
}

    async function carregarLimitesNovaFA() {
        const badge = document.getElementById('faNewMyCount');
        const form = document.getElementById('faNewRequestForm');
        if (!badge && !form) return;

        try {
            const response = await fetch('api/free-agency.php?action=new_fa_limits');
            const data = await response.json();
            if (!data.success) return;
            const remaining = data.remaining ?? 0;
            const limit = data.limit ?? 3;

            const counter = document.createElement('span');
            const isBlocked = remaining <= 0;
            counter.className = isBlocked ? 'badge bg-danger' : 'badge bg-success';
            counter.textContent = isBlocked
                ? `Limite de contratações atingido (${remaining}/${limit})`
                : `Contratacoes restantes: ${remaining}/${limit}`;

            const existing = document.getElementById('faNewRemainingBadge');
            if (existing) {
                existing.textContent = counter.textContent;
                existing.className = counter.className;
            } else if (form) {
                counter.id = 'faNewRemainingBadge';
                const wrapper = document.createElement('div');
                wrapper.className = 'col-12';
                wrapper.appendChild(counter);
                form.appendChild(wrapper);
            }

            window.__faRemainingSignings = remaining;

            if (form) {
                const inputs = form.querySelectorAll('input, select, button');
                inputs.forEach((input) => {
                    if (input.id === 'faNewRemainingBadge') return;
                    if (input.type === 'submit' || input.id === 'faNewSubmitBtn' || input.tagName === 'INPUT' || input.tagName === 'SELECT') {
                        input.disabled = isBlocked;
                    }
                });
            }
        } catch (error) {
            // silencioso
        }
    }

async function submitNewFaRequest() {
    let league = getActiveLeague();
    if (!league) {
        league = document.getElementById('faNewAdminLeague')?.value
            || document.getElementById('faLeague')?.value
            || null;
    }
    if (!league) {
        alert('Nenhuma liga definida.');
        return;
    }

    const name = document.getElementById('faNewPlayerName')?.value.trim();
    const position = document.getElementById('faNewPosition')?.value || 'PG';
    const secondary = document.getElementById('faNewSecondary')?.value.trim();
    const age = parseInt(document.getElementById('faNewAge')?.value, 10);
    const ovr = parseInt(document.getElementById('faNewOvr')?.value, 10);
    const amount = parseInt(document.getElementById('faNewOffer')?.value, 10);
    const priority = parseInt(document.getElementById('faNewPriority')?.value, 10) || 2;

    if (!name) {
        alert('Informe o nome do jogador.');
        return;
    }

    if (!Number.isFinite(amount) || amount < 0) {
        alert('Informe uma quantidade válida de moedas (0 ou mais).');
        return;
    }

    const payload = {
        action: 'request_player',
        league,
        name,
        position,
        secondary_position: secondary || null,
        age: Number.isFinite(age) ? age : 24,
        ovr: Number.isFinite(ovr) ? ovr : 70,
        amount,
        priority
    };

    try {
        const response = await fetch('api/free-agency.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const data = await response.json();
        if (!data.success) {
            alert(data.error || 'Erro ao enviar proposta.');
            return;
        }

        alert('Proposta enviada!');
        document.getElementById('faNewRequestForm')?.reset();
        document.getElementById('faNewOffer').value = '1';
        atualizarAvisoDeCap();
        carregarDispensadosDaTemporada();
        carregarMinhasPropostasNovaFA();
        if (isAdmin) {
            carregarSolicitacoesNovaFA();
        }
    } catch (error) {
        console.error('Erro ao enviar proposta:', error);
        alert('Erro ao enviar proposta.');
    }
}

async function carregarMinhasPropostasNovaFA() {
    const container = document.getElementById('faNewMyRequests');
    const countBadge = document.getElementById('faNewMyCount');
    if (!container) return;

    try {
        const response = await fetch('api/free-agency.php?action=my_fa_requests');
        const data = await response.json();
        if (!data.success || !Array.isArray(data.requests)) {
            container.innerHTML = '<p class="text-light-gray">Nenhuma proposta registrada.</p>';
            if (countBadge) countBadge.textContent = '0';
            return;
        }

        const requests = data.requests;
        if (countBadge) countBadge.textContent = String(requests.length);
        if (requests.length === 0) {
            container.innerHTML = '<p class="text-light-gray">Nenhuma proposta registrada.</p>';
            return;
        }

        const priorityBadge = p => {
            const cfg = { 1: ['#22c55e','Alta'], 2: ['#f59e0b','Média'], 3: ['#94a3b8','Baixa'] };
            const [c, l] = cfg[p] || cfg[2];
            return `<span style="font-size:10px;font-weight:700;padding:2px 7px;border-radius:999px;background:${c}18;border:1px solid ${c}44;color:${c};white-space:nowrap">P${p} ${l}</span>`;
        };

        let html = '<div class="table-responsive"><table class="table table-dark table-hover mb-0">';
        html += '<thead><tr><th>Jogador</th><th>OVR</th><th>Proposta</th><th>Status</th><th>Temporada</th><th>Acoes</th></tr></thead><tbody>';
        requests.forEach(item => {
            const statusLabel = formatNewFaStatus(item.status);
            const season = item.season_year ? `Temp ${item.season_year}` : '-';
            const remaining = typeof window.__faRemainingSignings === 'number' ? window.__faRemainingSignings : null;
            const isBlocked = remaining !== null && remaining <= 0;
            const isPending = item.status === 'pending' && !isBlocked;
            html += `<tr>
                <td><strong class="text-orange">${esc(item.player_name)}</strong><div class="small text-light-gray">${esc(item.position)}${item.secondary_position ? '/' + esc(item.secondary_position) : ''}</div></td>
                <td>${item.ovr ?? '-'}</td>
                <td>${item.amount ?? 0} moedas ${priorityBadge(item.priority ?? 2)}</td>
                <td>${statusLabel}</td>
                <td>${season}</td>
                <td>
                    ${isPending ? `
                        <div class=\"d-flex gap-2 flex-wrap\">
                            <button class=\"btn btn-sm btn-outline-light\" onclick=\"editarPropostaNovaFA(${item.offer_id}, ${item.amount}, ${item.priority ?? 2})\">
                                <i class=\"bi bi-pencil\"></i>
                            </button>
                            <button class=\"btn btn-sm btn-outline-danger\" onclick=\"excluirPropostaNovaFA(${item.offer_id})\">
                                <i class=\"bi bi-trash\"></i>
                            </button>
                        </div>
                    ` : '<span class="text-light-gray">-</span>'}
                </td>
            </tr>`;
        });
        html += '</tbody></table></div>';
        container.innerHTML = html;
        _applyFaTableLabels(container);
        carregarLimitesNovaFA();
    } catch (error) {
        container.innerHTML = '<p class="text-danger">Erro ao carregar propostas.</p>';
    }
}

async function carregarHistoricoNovaFA() {
    const container = document.getElementById('faHistoryContainer');
    if (!container) return;

    const league = getActiveLeague();
    if (!league) {
        container.innerHTML = '<p class="text-muted">Nenhuma liga definida.</p>';
        return;
    }

    const seasonFilter = document.getElementById('faHistorySeasonFilter');
    const seasonValue = seasonFilter?.value || '';
    const query = new URLSearchParams({
        action: 'new_fa_history',
        league
    });
    if (seasonValue) {
        query.append('season_year', seasonValue);
    }

    try {
        const response = await fetch(`api/free-agency.php?${query.toString()}`);
        const data = await response.json();
        if (!data.success || !Array.isArray(data.history) || data.history.length === 0) {
            container.innerHTML = '<p class="text-light-gray">Nenhuma contratacao registrada.</p>';
            return;
        }

        if (seasonFilter && !seasonFilter.dataset.loaded) {
            const seasons = [...new Set(data.history.map(item => item.season_year).filter(Boolean))].sort((a, b) => b - a);
            seasons.forEach(season => {
                const option = document.createElement('option');
                option.value = season;
                option.textContent = `Temp ${season}`;
                seasonFilter.appendChild(option);
            });
            seasonFilter.dataset.loaded = '1';
            seasonFilter.addEventListener('change', () => carregarHistoricoNovaFA());
        }

        const sortedHistory = sortByTeamName(
            data.history,
            (item) => item.team_name ? `${item.team_city} ${item.team_name}` : '',
            faHistoryTeamSort
        );

        let html = '<div class="table-responsive"><table class="table table-dark table-hover mb-0">';
        html += '<thead><tr><th>Jogador</th><th>OVR</th><th><button type="button" class="btn btn-link p-0 text-white" onclick="toggleFaHistoryTeamSort()">Time' + sortIndicator(faHistoryTeamSort) + '</button></th><th>Temporada</th></tr></thead><tbody>';
        sortedHistory.forEach(item => {
            const teamName = item.team_name ? esc(`${item.team_city} ${item.team_name}`) : '-';
            const seasonLabel = item.season_year ? `Temp ${item.season_year}` : '-';
            html += `<tr>
                <td><strong class="text-orange">${esc(item.player_name)}</strong></td>
                <td>${item.ovr ?? '-'}</td>
                <td>${teamName}</td>
                <td>${seasonLabel}</td>
            </tr>`;
        });
        html += '</tbody></table></div>';
        container.innerHTML = html;
        _applyFaTableLabels(container);
    } catch (error) {
        container.innerHTML = '<p class="text-danger">Erro ao carregar historico.</p>';
    }
}

async function carregarSolicitacoesNovaFA() {
    const container = document.getElementById('faNewAdminRequests');
    if (!container) return;

    const league = getAdminLeague();
    if (!league) {
        container.innerHTML = '<p class="text-muted">Selecione uma liga.</p>';
        return;
    }

    try {
        const response = await fetch(`api/free-agency.php?action=admin_new_fa_requests&league=${encodeURIComponent(league)}`);
        const data = await response.json();
        if (!data.success || !Array.isArray(data.requests) || data.requests.length === 0) {
            container.innerHTML = '<p class="text-white">Nenhuma solicitacao pendente.</p>';
            return;
        }

        const priorityBadge = p => {
            const cfg = { 1: ['#22c55e','Alta'], 2: ['#f59e0b','Média'], 3: ['#94a3b8','Baixa'] };
            const [c, l] = cfg[p] || cfg[2];
            return `<span style="font-size:10px;font-weight:700;padding:2px 7px;border-radius:999px;background:${c}18;border:1px solid ${c}44;color:${c};white-space:nowrap">P${p} ${l}</span>`;
        };

        let html = '';
        data.requests.forEach(group => {
            const req = group.request || {};
            const offers = group.offers || [];
            const suggested = offers.find(o => o.team_coins >= o.amount && o.roster_count < 15) || offers[0] || null;

            html += `<div style="background:var(--panel);border:1px solid var(--border);border-radius:var(--radius-sm);padding:16px 18px;margin-bottom:12px">`;

            html += `<div style="display:flex;align-items:flex-start;justify-content:space-between;gap:10px;margin-bottom:12px;flex-wrap:wrap">
                <div>
                    <div style="font-weight:700;font-size:15px;color:var(--text)">${esc(req.player_name || 'Jogador')}</div>
                    <div style="font-size:12px;color:var(--text-3);margin-top:2px">
                        ${esc(req.position || '')}${req.secondary_position ? '/' + esc(req.secondary_position) : ''} &bull; OVR <strong style="color:var(--red)">${req.ovr || '?'}</strong>${req.age ? ` &bull; ${req.age} anos` : ''}
                    </div>
                </div>
                <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
                    <span style="font-size:11px;font-weight:600;padding:3px 9px;border-radius:999px;background:rgba(59,130,246,.1);color:#3b82f6;border:1px solid rgba(59,130,246,.25)">${offers.length} proposta${offers.length !== 1 ? 's' : ''}</span>
                    <button onclick="recusarSolicitacaoNovaFA(${req.id})"
                        style="padding:4px 10px;background:transparent;border:1px solid rgba(239,68,68,.35);border-radius:7px;color:#ef4444;font-size:11px;font-weight:600;cursor:pointer;font-family:var(--font)">
                        <i class="bi bi-x-lg me-1"></i>Recusar todas
                    </button>
                </div>
            </div>`;

            html += `<div style="display:flex;flex-direction:column;gap:7px;margin-bottom:14px">`;
            offers.forEach(offer => {
                const canAfford = offer.team_coins >= offer.amount;
                const hasSpace  = offer.roster_count < 15;
                const isSugg    = suggested && offer.id === suggested.id;
                const border    = isSugg ? 'rgba(34,197,94,.4)' : 'var(--border)';
                const warnCoins  = !canAfford ? `<span style="font-size:10px;color:#ef4444;font-weight:600"><i class="bi bi-exclamation-triangle me-1"></i>Moedas insuf. (${offer.team_coins} disp.)</span>` : '';
                const warnRoster = !hasSpace  ? `<span style="font-size:10px;color:#ef4444;font-weight:600"><i class="bi bi-exclamation-triangle me-1"></i>Elenco cheio</span>` : '';
                html += `<div style="background:var(--panel-2);border:1px solid ${border};border-radius:8px;padding:10px 12px;display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap">
                    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                        ${priorityBadge(offer.priority)}
                        <span style="font-size:13px;font-weight:600;color:var(--text)">${esc(offer.team_name)}</span>
                        ${warnCoins}${warnRoster}
                    </div>
                    <div style="display:flex;align-items:center;gap:10px;flex-shrink:0">
                        <span style="font-size:14px;font-weight:700;color:${canAfford ? 'var(--text)' : '#ef4444'}">${offer.amount} <span style="font-size:11px;font-weight:400;color:var(--text-3)">moedas</span></span>
                        ${isSugg ? `<span style="font-size:10px;font-weight:700;padding:2px 8px;border-radius:999px;background:rgba(34,197,94,.12);border:1px solid rgba(34,197,94,.35);color:#22c55e;white-space:nowrap"><i class="bi bi-trophy-fill me-1"></i>Vencedor</span>` : ''}
                    </div>
                </div>`;
            });
            html += `</div>`;

            if (suggested) {
                const ok = suggested.team_coins >= suggested.amount && suggested.roster_count < 15;
                const note = ok
                    ? `<i class="bi bi-check-circle-fill me-1" style="color:#22c55e"></i>Sugerido: <strong>${esc(suggested.team_name)}</strong> — ${suggested.amount} moedas (P${suggested.priority})`
                    : `<i class="bi bi-exclamation-triangle me-1" style="color:#f59e0b"></i>Atenção: todos os lances têm restrições. Revise antes de aprovar.`;
                html += `<div style="font-size:12px;color:var(--text-2);padding:8px 10px;background:var(--panel-2);border-radius:7px;margin-bottom:12px">${note}</div>`;
            }

            html += `<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                <select id="faNewOfferSelect-${req.id}" style="flex:1;min-width:160px;background:var(--panel-2);border:1px solid var(--border);border-radius:8px;padding:7px 10px;color:var(--text);font-size:13px;font-family:var(--font)">
                    <option value="">Selecione...</option>
                    ${offers.map(o => `<option value="${o.id}" ${suggested && o.id === suggested.id ? 'selected' : ''}>${esc(o.team_name)} — ${o.amount} moedas (P${o.priority})</option>`).join('')}
                </select>
                <button onclick="aprovarSolicitacaoNovaFA(${req.id})"
                    style="padding:8px 16px;background:#22c55e;border:none;border-radius:8px;color:#fff;font-size:13px;font-weight:600;cursor:pointer;font-family:var(--font);white-space:nowrap">
                    <i class="bi bi-check-lg me-1"></i>Aprovar
                </button>
            </div>`;

            html += `</div>`;
        });
        container.innerHTML = html;
    } catch (error) {
        container.innerHTML = '<p class="text-danger">Erro ao carregar solicitacoes.</p>';
    }
}

window.aprovarSolicitacaoNovaFA = async function(requestId) {
    const select = document.getElementById(`faNewOfferSelect-${requestId}`);
    const offerId = select?.value;
    if (!offerId) {
        alert('Selecione uma proposta.');
        return;
    }

    try {
        const response = await fetch('api/free-agency.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'admin_assign_request', offer_id: Number(offerId) })
        });
        const data = await response.json();
        if (!data.success) {
            alert(data.error || 'Erro ao aprovar.');
            return;
        }
        alert(data.message || 'Contratacao realizada.');
        carregarSolicitacoesNovaFA();
        carregarHistoricoNovaFA();
        carregarMinhasPropostasNovaFA();
        carregarLimitesNovaFA();
    } catch (error) {
        alert('Erro ao aprovar.');
    }
};

window.recusarSolicitacaoNovaFA = async function(requestId) {
    if (!confirm('Recusar todas as propostas para este jogador?')) return;
    try {
        const response = await fetch('api/free-agency.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'admin_reject_request', request_id: Number(requestId) })
        });
        const data = await response.json();
        if (!data.success) {
            alert(data.error || 'Erro ao recusar.');
            return;
        }
        carregarSolicitacoesNovaFA();
        carregarMinhasPropostasNovaFA();
    } catch (error) {
        alert('Erro ao recusar.');
    }
};

window.editarPropostaNovaFA = function(offerId, currentAmount, currentPriority) {
    const modalEl = document.getElementById('faEditOfferModal');
    if (!modalEl) return;

    document.getElementById('editOfferModalId').value = offerId;
    document.getElementById('editOfferAmount').value = currentAmount;
    document.getElementById('editOfferPriority').value = String(currentPriority || 2);

    const saveBtn = document.getElementById('editOfferSaveBtn');
    const newBtn = saveBtn.cloneNode(true);
    saveBtn.parentNode.replaceChild(newBtn, saveBtn);

    newBtn.addEventListener('click', async function() {
        const amount = parseInt(document.getElementById('editOfferAmount').value, 10);
        const priority = parseInt(document.getElementById('editOfferPriority').value, 10) || 2;
        if (!Number.isFinite(amount) || amount <= 0) {
            alert('Valor inválido.');
            return;
        }
        try {
            const response = await fetch('api/free-agency.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'update_request_offer', offer_id: Number(offerId), amount, priority })
            });
            const data = await response.json();
            if (!data.success) {
                alert(data.error || 'Erro ao atualizar proposta.');
                return;
            }
            bootstrap.Modal.getInstance(modalEl)?.hide();
            carregarMinhasPropostasNovaFA();
            if (isAdmin) carregarSolicitacoesNovaFA();
        } catch (error) {
            alert('Erro ao atualizar proposta.');
        }
    });

    new bootstrap.Modal(modalEl).show();
};

window.excluirPropostaNovaFA = async function(offerId) {
    try {
        const response = await fetch('api/free-agency.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'cancel_request_offer', offer_id: Number(offerId) })
        });
        const data = await response.json();
        if (!data.success) {
            alert(data.error || 'Erro ao excluir proposta.');
            return;
        }
        carregarMinhasPropostasNovaFA();
        if (isAdmin) {
            carregarSolicitacoesNovaFA();
        }
    } catch (error) {
        alert('Erro ao excluir proposta.');
    }
};

function formatNewFaStatus(status) {
    switch (status) {
        case 'accepted':
        case 'assigned':
            return '<span class="badge bg-success">Contratado</span>';
        case 'rejected':
            return '<span class="badge bg-danger">Recusado</span>';
        default:
            return '<span class="badge bg-warning text-dark">Pendente</span>';
    }
}

async function carregarDispensados() {
    const container = document.getElementById('faWaiversContainer');
    if (!container) return;

    const league = getActiveLeague();
    if (!league) {
        container.innerHTML = '<p class="text-muted">Nenhuma liga definida.</p>';
        return;
    }

    try {
        const response = await fetch(`api/free-agency.php?action=waivers&league=${encodeURIComponent(league)}`);
        const data = await response.json();
        if (!data.success) {
            container.innerHTML = '<p class="text-danger">Erro ao carregar dispensas.</p>';
            return;
        }
        const waivers = data.waivers || [];
        if (!waivers.length) {
            container.innerHTML = '<p class="text-light-gray">Nenhum jogador dispensado recentemente.</p>';
            return;
        }

        const seasonFilter = document.getElementById('faWaiversSeasonFilter');
        const teamFilter = document.getElementById('faWaiversTeamFilter');

        if (seasonFilter && !seasonFilter.dataset.loaded) {
            const seasons = [...new Set(waivers.map(item => item.season_year).filter(Boolean))].sort((a, b) => b - a);
            seasons.forEach(season => {
                const option = document.createElement('option');
                option.value = season;
                option.textContent = `Temp ${season}`;
                seasonFilter.appendChild(option);
            });
            seasonFilter.dataset.loaded = '1';
            seasonFilter.addEventListener('change', () => renderWaiversList(waivers));
        }

        if (teamFilter && !teamFilter.dataset.loaded) {
            const teams = [...new Set(waivers.map(item => item.original_team_name).filter(Boolean))].sort();
            teams.forEach(team => {
                const option = document.createElement('option');
                option.value = team;
                option.textContent = team;
                teamFilter.appendChild(option);
            });
            teamFilter.dataset.loaded = '1';
            teamFilter.addEventListener('change', () => renderWaiversList(waivers));
        }

        window.__faWaiversCache = waivers;
        renderWaiversList(waivers);
    } catch (error) {
        container.innerHTML = '<p class="text-danger">Erro ao carregar dispensas.</p>';
    }
}

function renderWaiversList(waivers) {
    const container = document.getElementById('faWaiversContainer');
    if (!container) return;
    const seasonFilter = document.getElementById('faWaiversSeasonFilter');
    const teamFilter = document.getElementById('faWaiversTeamFilter');
    const seasonValue = seasonFilter?.value || '';
    const teamValue = teamFilter?.value || '';

    const filtered = waivers.filter(item => {
        if (seasonValue && String(item.season_year) !== seasonValue) return false;
        if (teamValue && item.original_team_name !== teamValue) return false;
        return true;
    });

    if (!filtered.length) {
        container.innerHTML = '<p class="text-light-gray">Nenhum jogador dispensado encontrado.</p>';
        return;
    }

    const sorted = sortByTeamName(
        filtered,
        (item) => item.original_team_name || '',
        faWaiversTeamSort
    );

    let html = '<div class="table-responsive"><table class="table table-dark table-hover mb-0">';
    html += '<thead><tr><th>Jogador</th><th>Temporada</th><th><button type="button" class="btn btn-link p-0 text-white" onclick="toggleFaWaiversTeamSort()">Time' + sortIndicator(faWaiversTeamSort) + '</button></th><th>Dispensado em</th></tr></thead><tbody>';
    sorted.forEach(item => {
        const teamName = esc(item.original_team_name || '-');
        let seasonLabel = '-';
        if (item.season_number) {
            seasonLabel = `Temp #${item.season_number}`;
            if (item.season_year) {
                seasonLabel += ` (${item.season_year})`;
            }
        } else if (item.season_year) {
            seasonLabel = `Temp ${item.season_year}`;
        }
        const waivedLabel = item.waived_at ? item.waived_at.slice(0, 16) : '-';
        html += `<tr>
            <td><strong class="text-orange">${esc(item.name)}</strong></td>
            <td>${seasonLabel}</td>
            <td>${teamName}</td>
            <td style="white-space:nowrap;color:var(--text-2);font-size:12px">${waivedLabel}</td>
        </tr>`;
    });
    html += '</tbody></table></div>';
    container.innerHTML = html;
    _applyFaTableLabels(container);
}

