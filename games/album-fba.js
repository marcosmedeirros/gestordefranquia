const API = 'album-fba-api.php';
let state = {
    user: null,
    master: [],
    collection: {},
    myTeam: [null, null, null, null, null],
    ranking: [],
    packTypes: {},
    redeemedCollections: [],
    market: {
        listings: [],
        myListings: [],
        priceCaps: { comum: 20, rara: 40, epico: 60, lendario: 100 }
    },
    adminPackCollections: []
};
let currentSlot = null;
let lastPackType = null;
const slotPositions = ['PG', 'SG', 'SF', 'PF', 'C'];

const rarityClass = (r) => ({ comum: 'rarity-comum', rara: 'rarity-rara', epico: 'rarity-epico', lendario: 'rarity-lendario' }[r] || 'rarity-comum');
const hasCard = (id) => Number(state.collection[id] || 0) > 0;
const rarityLabel = (r) => ({ comum: 'Comum', rara: 'Rara', epico: 'Epica', lendario: 'Lendaria' }[r] || r);

function showPackOdds(type) {
    const cfg = state.packTypes?.[type];
    if (!cfg || !cfg.rates) {
        alert('Probabilidades indisponiveis no momento.');
        return;
    }
    const order = ['lendario', 'epico', 'rara', 'comum'];
    const lines = order
        .filter((r) => cfg.rates[r] !== undefined)
        .map((r) => `${rarityLabel(r)}: ${Number(cfg.rates[r])}%`);
    const title = String(type || '').toUpperCase();
    alert(`Probabilidades do pacote ${title}:\n${lines.join('\n')}`);
}
window.showPackOdds = showPackOdds;

async function parseApiResponse(response) {
    const raw = await response.text();
    try {
        return JSON.parse(raw || '{}');
    } catch (err) {
        const preview = (raw || '').slice(0, 180).trim();
        throw new Error(preview ? `Resposta invÃ¡lida do servidor: ${preview}` : 'Resposta invÃ¡lida do servidor.');
    }
}

async function post(action, payload = {}) {
    const response = await fetch(`${API}?action=${action}`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    });
    return parseApiResponse(response);
}

async function postForm(action, formData) {
    const response = await fetch(`${API}?action=${action}`, { method: 'POST', body: formData });
    return parseApiResponse(response);
}

async function get(action) {
    const response = await fetch(`${API}?action=${action}`);
    return parseApiResponse(response);
}

async function getWithParams(action, params = {}) {
    const query = new URLSearchParams({ action, ...params }).toString();
    const response = await fetch(`${API}?${query}`);
    return parseApiResponse(response);
}

async function bootstrap() {
    const res = await get('bootstrap');
    if (!res.ok) {
        alert(res.message || 'Erro ao carregar Ã¡lbum');
        return;
    }
    state.user = res.user;
    state.master = Array.isArray(res.master_data) ? res.master_data : [];
    state.collection = res.collection || {};
    state.myTeam = Array.isArray(res.my_team) ? res.my_team : [null, null, null, null, null];
    state.ranking = Array.isArray(res.ranking) ? res.ranking : [];
    state.packTypes = res.pack_types || {};
    state.redeemedCollections = Array.isArray(res.redeemed_collections) ? res.redeemed_collections : [];

    document.getElementById('coin-count').innerText = state.user.coins || 0;
    if (state.user.is_admin) document.getElementById('tab-admin')?.classList.remove('hidden');

    renderAlbum();
    renderCourt();
    renderMarket();
    if (state.user.is_admin) {
        renderAdminCards();
        await loadAdminPackCollections();
    }
    switchTab('album');
}

function switchTab(tab) {
    const all = ['album', 'team', 'ranking', 'market', 'store'];
    if (state.user?.is_admin) all.push('admin');
    all.forEach((t) => {
        const section = document.getElementById('section-' + t);
        if (section) {
            section.classList.add('hidden');
            section.classList.remove('block');
            section.style.display = 'none';
            section.setAttribute('aria-hidden', 'true');
        }
        const b = document.getElementById('tab-' + t);
        if (b) b.className = 'px-4 md:px-6 py-2 rounded-t-lg bg-zinc-900 text-zinc-300 font-bold fba-title hover:bg-zinc-800';
    });
    const activeSection = document.getElementById('section-' + tab);
    if (activeSection) {
        activeSection.classList.remove('hidden');
        activeSection.classList.add('block');
        activeSection.style.display = '';
        activeSection.setAttribute('aria-hidden', 'false');
    }
    const active = document.getElementById('tab-' + tab);
    if (active) active.className = 'px-4 md:px-6 py-2 rounded-t-lg bg-red-700 font-bold fba-title text-white';
    if (tab === 'album') renderAlbum();
    if (tab === 'team') renderCourt();
    if (tab === 'ranking') renderRanking();
    if (tab === 'market') renderMarket();
    if (tab === 'admin') renderAdminCards();
}
window.switchTab = switchTab;

function renderAlbum() {
    const c = document.getElementById('album-container');
    c.innerHTML = '';
    const totalCollected = state.master.reduce((sum, card) => sum + (hasCard(card.id) ? 1 : 0), 0);
    const filterEl = document.getElementById('album-collection-filter');
    const query = String(filterEl?.value || '').trim().toLowerCase();
    const collectionDates = state.master.reduce((acc, card) => {
        const name = card.collection || 'Geral';
        const raw = card.created_at || card.createdAt || '';
        const ts = Date.parse(raw);
        if (!Number.isNaN(ts)) {
            acc[name] = acc[name] ? Math.min(acc[name], ts) : ts;
        }
        return acc;
    }, {});
    const collections = [...new Set(state.master.map((x) => x.collection || 'Geral'))]
        .filter((name) => !query || String(name).toLowerCase().includes(query))
        .sort((a, b) => {
            const aDate = collectionDates[a];
            const bDate = collectionDates[b];
            if (typeof aDate === 'number' && typeof bDate === 'number' && aDate !== bDate) {
                return bDate - aDate;
            }
            return String(a).localeCompare(String(b));
        });

    collections.forEach((collectionName) => {
        const cards = state.master.filter((x) => (x.collection || 'Geral') === collectionName);
        const section = document.createElement('div');
        section.className = 'bg-zinc-950/60 p-4 rounded-xl border border-zinc-700';
        section.innerHTML = `<h3 class="text-xl font-bold fba-title mb-4 border-b border-zinc-700 pb-2 text-red-400">${collectionName}</h3>`;
        const grid = document.createElement('div');
        grid.className = 'grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-4';
        cards.forEach((card) => {
            const got = hasCard(card.id);
            const slot = document.createElement('div');
            slot.className = `album-slot rounded-xl overflow-hidden relative flex items-center justify-center ${got ? 'collected ' + rarityClass(card.rarity) : 'p-2'}`;
            slot.innerHTML = got
                ? `<img src="${card.img}" class="w-full h-full object-cover"><div class="absolute top-1 right-1 bg-black/80 px-1.5 py-0.5 rounded text-[0.6rem] font-bold text-white">#${card.id}</div><div class="absolute bottom-1 left-1 bg-black/80 px-1.5 py-0.5 rounded text-[0.6rem] font-bold text-red-300">x${state.collection[card.id]}</div>`
                : `<div class="opacity-30 text-[0.65rem] font-bold text-center">${card.name}<br>#${card.id}</div>`;
            if (got) {
                slot.classList.add('cursor-pointer', 'hover:scale-[1.02]', 'transition-transform');
                slot.onclick = () => openAlbumCardModal(card, Number(state.collection[card.id] || 1));
            }
            grid.appendChild(slot);
        });
        section.appendChild(grid);
        c.appendChild(section);
    });
    if (!collections.length) {
        c.innerHTML = '<div class="text-zinc-400">Nenhuma coleção encontrada para o filtro informado.</div>';
    }
    document.getElementById('album-progress').innerText = `Progresso: ${totalCollected} / ${state.master.length} figurinhas`;
    document.getElementById('coin-count').innerText = state.user.coins || 0;
    renderCollectionRewards();
}

function collectionProgressData() {
    const totals = {};
    const owned = {};
    state.master.forEach((card) => {
        const name = card.collection || 'Geral';
        totals[name] = (totals[name] || 0) + 1;
        if (hasCard(card.id)) {
            owned[name] = (owned[name] || 0) + 1;
        }
    });
    return Object.keys(totals).map((name) => {
        const total = totals[name] || 0;
        const have = owned[name] || 0;
        const percent = total > 0 ? Math.round((have / total) * 100) : 0;
        const redeemed = state.redeemedCollections.includes(name);
        return { name, owned: have, total, percent, redeemed };
    }).sort((a, b) => {
        if (a.percent !== b.percent) return b.percent - a.percent;
        return a.name.localeCompare(b.name);
    });
}

async function redeemCollectionReward(collectionName) {
    const res = await post('redeem_collection', { collection: collectionName });
    if (!res.ok) {
        alert(res.message || 'Erro ao resgatar coleÃ§Ã£o');
        return;
    }
    if (!state.redeemedCollections.includes(collectionName)) {
        state.redeemedCollections.push(collectionName);
    }
    if (typeof res.fba_points === 'number') {
        state.user.fba_points = res.fba_points;
    }
    renderCollectionRewards();
    alert('Resgate realizado! +500 FBA Points');
}
window.redeemCollectionReward = redeemCollectionReward;

function renderCollectionRewards() {
    const wrap = document.getElementById('collection-rewards');
    if (!wrap) return;
    const data = collectionProgressData();
    if (!data.length) {
        wrap.innerHTML = '<div class="text-zinc-500 text-sm">Nenhuma colecao encontrada.</div>';
        return;
    }
    wrap.innerHTML = '';
    data.forEach((item) => {
        const card = document.createElement('div');
        card.className = 'min-w-[230px] bg-zinc-900/80 border border-zinc-700 rounded-xl p-4 flex flex-col gap-3';
        const isComplete = item.total > 0 && item.owned >= item.total;
        const status = item.redeemed
            ? '<span class="text-xs bg-zinc-700 text-zinc-200 px-2 py-1 rounded-full">Resgatado</span>'
            : isComplete
                ? '<span class="text-xs bg-emerald-600 text-white px-2 py-1 rounded-full">Completo</span>'
                : '<span class="text-xs bg-zinc-800 text-zinc-300 px-2 py-1 rounded-full">Em progresso</span>';
        card.innerHTML = `
            <div class="flex items-start justify-between gap-2">
                <div class="font-bold text-red-300">${item.name}</div>
                ${status}
            </div>
            <div class="text-sm text-zinc-300">${item.owned}/${item.total} figurinhas</div>
            <div class="w-full bg-zinc-800 rounded-full h-2">
                <div class="h-2 rounded-full bg-red-600" style="width:${item.percent}%"></div>
            </div>
            <div class="text-xs text-zinc-400">${item.percent}% completo</div>
        `;
        if (isComplete && !item.redeemed) {
            const btn = document.createElement('button');
            btn.className = 'mt-1 bg-red-700 hover:bg-red-600 rounded-lg px-3 py-2 text-sm font-bold';
            btn.textContent = 'Resgatar 500 FBA Points';
            btn.onclick = () => redeemCollectionReward(item.name);
            card.appendChild(btn);
        }
        wrap.appendChild(card);
    });
}

function teamOVR() {
    return state.myTeam.reduce((sum, id) => {
        if (!id) return sum;
        const card = state.master.find((x) => x.id === id);
        return sum + (card ? Number(card.ovr || 0) : 0);
    }, 0);
}

function renderCourt() {
    for (let i = 0; i < 5; i++) {
        const el = document.getElementById(`court-slot-${i}`);
        if (!el) continue;
        const id = state.myTeam[i];
        if (id) {
            const card = state.master.find((x) => x.id === id);
            if (!card) continue;
            el.innerHTML = `<img src="${card.img}"><div class="absolute -bottom-2 bg-black/80 px-2 py-0.5 rounded text-[0.6rem] font-bold text-white border border-red-500/50">OVR ${card.ovr}</div>`;
            el.className = el.className.replace(/rarity-\w+/g, '');
            el.classList.add(rarityClass(card.rarity));
            el.style.borderStyle = 'solid';
        } else {
            el.innerHTML = '+';
            el.className = el.className.replace(/rarity-\w+/g, '');
            el.style.borderStyle = 'dashed';
        }
    }
    document.getElementById('team-ovr-display').innerText = teamOVR();
}

function openSelectModal(slot) {
    currentSlot = slot;
    const m = document.getElementById('select-modal');
    const c = document.getElementById('select-cards-container');
    c.innerHTML = '';
    const requiredPosition = slotPositions[slot] || null;
    const cards = state.master.filter((x) => hasCard(x.id) && (!requiredPosition || String(x.position).toUpperCase() === requiredPosition));
    if (!cards.length) {
        c.innerHTML = `<p class="text-zinc-400 col-span-full text-center py-8">Voce nao tem carta disponivel para a posicao ${requiredPosition || '-'}.<\/p>`;
    }
    cards.forEach((card) => {
        const used = state.myTeam.some((id, idx) => idx !== slot && id === card.id);
        const el = document.createElement('div');
        el.className = `aspect-[2.5/3.5] rounded-lg overflow-hidden relative border-2 ${rarityClass(card.rarity)} ${used ? 'opacity-30 cursor-not-allowed' : 'cursor-pointer hover:scale-105'} transition-transform`;
        el.innerHTML = `<img src="${card.img}" class="w-full h-full object-cover"><div class="absolute top-1 left-1 bg-black/80 px-1.5 py-0.5 rounded text-[0.7rem] font-bold text-white border border-red-500/40">OVR ${card.ovr}</div>${used ? '<div class="absolute inset-0 bg-black/60 flex items-center justify-center font-bold text-sm text-center p-2">EM USO</div>' : ''}`;
        if (!used) el.onclick = () => selectCard(card.id);
        c.appendChild(el);
    });
    if (state.myTeam[slot] !== null) {
        const r = document.createElement('div');
        r.className = 'aspect-[2.5/3.5] rounded-lg border-2 border-red-500 bg-red-500/20 flex flex-col items-center justify-center cursor-pointer hover:bg-red-500/40 text-red-300 font-bold text-sm text-center p-2';
        r.innerHTML = 'âœ–<br>Remover';
        r.onclick = () => selectCard(null);
        c.prepend(r);
    }
    m.classList.remove('hidden');
    m.classList.add('flex');
}
window.openSelectModal = openSelectModal;

async function selectCard(cardId) {
    const backup = [...state.myTeam];
    state.myTeam[currentSlot] = cardId;
    const res = await post('save_team', { team: state.myTeam });
    if (!res.ok) {
        state.myTeam = backup;
        alert(res.message || 'Erro ao salvar quinteto');
        return;
    }
    renderCourt();
    closeSelectModal();
}

async function clearTeam() {
    const res = await post('clear_team');
    if (!res.ok) {
        alert(res.message || 'Erro ao limpar escalação');
        return;
    }
    state.myTeam = [null, null, null, null, null];
    renderCourt();
}
window.clearTeam = clearTeam;

function closeSelectModal() {
    const m = document.getElementById('select-modal');
    m.classList.add('hidden');
    m.classList.remove('flex');
}
window.closeSelectModal = closeSelectModal;

function openAlbumCardModal(card, qty) {
    const modal = document.getElementById('album-card-modal');
    if (!modal || !card) return;
    const img = document.getElementById('album-card-modal-img');
    const name = document.getElementById('album-card-modal-name');
    const meta = document.getElementById('album-card-modal-meta');
    const count = document.getElementById('album-card-modal-count');

    if (img) img.src = card.img;
    if (name) name.textContent = card.name;
    if (meta) meta.textContent = `${card.collection || 'Geral'} • ${card.team} • ${card.position} • OVR ${card.ovr} • ${String(card.rarity || '').toUpperCase()}`;
    if (count) count.textContent = `Quantidade: x${qty || 1}`;

    modal.classList.remove('hidden');
    modal.classList.add('flex');
}

function closeAlbumCardModal() {
    const modal = document.getElementById('album-card-modal');
    if (!modal) return;
    modal.classList.add('hidden');
    modal.classList.remove('flex');
}
window.closeAlbumCardModal = closeAlbumCardModal;

function openRankingTeamModal(teamData) {
    const modal = document.getElementById('ranking-team-modal');
    if (!modal || !teamData) return;
    const title = document.getElementById('ranking-team-modal-title');
    const ovr = document.getElementById('ranking-team-modal-ovr');
    const grid = document.getElementById('ranking-team-modal-grid');
    if (!grid) return;

    if (title) title.textContent = `Quinteto de ${teamData.name || 'Jogador'}`;
    if (ovr) ovr.textContent = Number(teamData.ovr || 0);
    const lineup = Array.isArray(teamData.lineup) ? teamData.lineup : [];
    grid.innerHTML = '';
    lineup.forEach((slotData) => {
        const pos = slotData?.slot || '-';
        const card = slotData?.card || null;
        const item = document.createElement('div');
        item.className = `rounded-lg border ${card ? rarityClass(card.rarity) : 'border-zinc-700'} bg-zinc-900/60 p-2`;
        item.innerHTML = card
            ? `<div class="text-[0.7rem] font-bold text-zinc-200 mb-1">${pos}</div><div class="aspect-[2.5/3.5] rounded overflow-hidden relative bg-black"><img src="${card.img}" class="w-full h-full object-contain"><div class="absolute bottom-0 left-0 right-0 bg-black/75 px-1 py-1"><div class="text-[0.65rem] font-bold leading-tight">${card.name}</div><div class="text-[0.6rem] text-zinc-300">${card.team} • OVR ${card.ovr}</div></div></div>`
            : `<div class="text-[0.7rem] font-bold text-zinc-200 mb-1">${pos}</div><div class="aspect-[2.5/3.5] rounded border border-dashed border-zinc-600 bg-zinc-800/60 flex items-center justify-center text-zinc-400 text-xs font-bold text-center px-2">Sem carta</div>`;
        grid.appendChild(item);
    });
    modal.classList.remove('hidden');
    modal.classList.add('flex');
}

function closeRankingTeamModal() {
    const modal = document.getElementById('ranking-team-modal');
    if (!modal) return;
    modal.classList.add('hidden');
    modal.classList.remove('flex');
}
window.closeRankingTeamModal = closeRankingTeamModal;

document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') return;
    const albumModal = document.getElementById('album-card-modal');
    if (albumModal && !albumModal.classList.contains('hidden')) {
        closeAlbumCardModal();
        return;
    }
    const rankingModal = document.getElementById('ranking-team-modal');
    if (rankingModal && !rankingModal.classList.contains('hidden')) {
        closeRankingTeamModal();
    }
});

async function renderRanking() {
    const tb = document.getElementById('ranking-tbody');
    tb.innerHTML = '<tr><td colspan="3" class="p-4 text-center text-zinc-400">Carregando...</td></tr>';
    const res = await get('ranking');
    if (res.ok && Array.isArray(res.ranking)) state.ranking = res.ranking;
    const board = [...state.ranking];
    const meIdx = board.findIndex((x) => x.name === state.user.name);
    const my = teamOVR();
    if (meIdx >= 0) board[meIdx].ovr = my;
    else board.push({ user_id: state.user.id, name: state.user.name, ovr: my, isUser: true });
    board.sort((a, b) => b.ovr - a.ovr);
    tb.innerHTML = '';
    board.forEach((p, i) => {
        const mine = p.name === state.user.name || p.isUser;
        const pos = String(i + 1);
        const tr = document.createElement('tr');
        tr.className = mine ? 'bg-red-900/40 border-b border-red-500/30 font-bold text-white cursor-pointer hover:bg-red-900/60' : 'border-b border-zinc-700/50 cursor-pointer hover:bg-zinc-800/70';
        tr.innerHTML = `<td class="p-4 text-center text-xl">${pos}</td><td class="p-4">${p.name}${mine ? ' (Voce)' : ''}</td><td class="p-4 text-center font-black text-white">${Number(p.ovr || 0)}</td>`;
        tr.onclick = async () => {
            if (!p.user_id) return;
            const loading = document.getElementById('ranking-team-modal-loading');
            if (loading) loading.classList.remove('hidden');
            openRankingTeamModal({ name: p.name, ovr: p.ovr, lineup: [] });
            const preview = await getWithParams('ranking_team', { user_id: p.user_id });
            if (loading) loading.classList.add('hidden');
            if (!preview.ok || !preview.team) {
                alert(preview.message || 'Nao foi possivel carregar o quinteto.');
                closeRankingTeamModal();
                return;
            }
            openRankingTeamModal(preview.team);
        };
        tb.appendChild(tr);
    });
}

function marketCardById(cardId) {
    return state.master.find((c) => Number(c.id) === Number(cardId)) || null;
}

function marketSellOptions() {
    return state.master
        .filter((card) => Number(state.collection[card.id] || 0) > 1)
        .sort((a, b) => String(a.name).localeCompare(String(b.name)));
}

function updateMarketSellHint() {
    const sellSelect = document.getElementById('market-sell-card');
    const hint = document.getElementById('market-sell-hint');
    const priceInput = document.getElementById('market-sell-price');
    if (!sellSelect || !hint || !priceInput) return;
    const card = marketCardById(Number(sellSelect.value || 0));
    if (!card) {
        hint.textContent = 'Voce precisa ter cartas duplicadas para anunciar.';
        priceInput.max = '1';
        priceInput.value = '1';
        return;
    }
    const max = Number(state.market.priceCaps[card.rarity] || 1);
    const rarity = rarityLabel(card.rarity);
    priceInput.max = String(max);
    let current = Number(priceInput.value || 1);
    if (current < 1) current = 1;
    if (current > max) current = max;
    priceInput.value = String(current);
    hint.textContent = `Maximo para ${rarity}: ${max} pontos.`;
}

function renderMarketMine() {
    const mineList = document.getElementById('market-mine-list');
    if (!mineList) return;
    const mine = Array.isArray(state.market.myListings) ? state.market.myListings : [];
    if (!mine.length) {
        mineList.innerHTML = '<div class="text-zinc-400">Voce nao tem cartas a venda.</div>';
        return;
    }
    mineList.innerHTML = mine.map((item) => `
        <div class="bg-zinc-900 border border-zinc-700 rounded-lg p-3">
            <div class="flex items-start justify-between gap-2">
                <div>
                    <div class="font-bold">${item.card_name}</div>
                    <div class="text-xs text-zinc-400">${item.card_collection} • ${rarityLabel(item.card_rarity)}</div>
                </div>
                <button type="button" class="bg-red-800 hover:bg-red-700 rounded w-8 h-8 font-bold" data-market-cancel="${item.id}" title="Cancelar venda">X</button>
            </div>
            <div class="mt-2 bg-zinc-950 rounded border border-zinc-700 p-1">
                <img src="${item.card_img}" alt="${item.card_name}" class="w-full h-36 object-contain rounded" onerror="this.style.display='none'">
            </div>
            <div class="text-sm text-red-300 font-bold mt-2">${Number(item.price_points)} pts</div>
        </div>
    `).join('');
}

function filteredMarketListings() {
    const nameQuery = String(document.getElementById('market-filter-name')?.value || '').trim().toLowerCase();
    const collectionFilter = String(document.getElementById('market-filter-collection')?.value || '');
    const rarityFilter = String(document.getElementById('market-filter-rarity')?.value || '');
    return (Array.isArray(state.market.listings) ? state.market.listings : []).filter((item) => {
        const nameOk = !nameQuery || String(item.card_name || '').toLowerCase().includes(nameQuery);
        const collectionOk = !collectionFilter || String(item.card_collection || '') === collectionFilter;
        const rarityOk = !rarityFilter || String(item.card_rarity || '') === rarityFilter;
        return nameOk && collectionOk && rarityOk;
    });
}

function renderMarketListings() {
    const list = document.getElementById('market-list');
    if (!list) return;
    const rows = filteredMarketListings();
    if (!rows.length) {
        list.innerHTML = '<div class="text-zinc-400">Nenhuma carta encontrada com esses filtros.</div>';
        return;
    }
    list.innerHTML = rows.map((item) => {
        const mine = Number(item.seller_user_id) === Number(state.user?.id || 0);
        return `
            <div class="bg-zinc-900 border border-zinc-700 rounded-lg p-3">
                <div class="flex items-start justify-between gap-2">
                    <div>
                        <div class="font-bold">${item.card_name}</div>
                        <div class="text-xs text-zinc-400">${item.card_collection} • ${rarityLabel(item.card_rarity)} • ${item.seller_name}</div>
                    </div>
                    <div class="text-red-300 font-black">${Number(item.price_points)} pts</div>
                </div>
                <div class="mt-2 bg-zinc-950 rounded border border-zinc-700 p-1">
                    <img src="${item.card_img}" alt="${item.card_name}" class="w-full h-36 object-contain rounded" onerror="this.style.display='none'">
                </div>
                <div class="mt-3">
                    ${mine
                        ? '<span class="text-xs px-2 py-1 rounded bg-zinc-700 text-zinc-200">Seu anuncio</span>'
                        : `<button type="button" class="bg-red-700 hover:bg-red-600 rounded px-3 py-2 text-sm font-bold" data-market-buy="${item.id}">Comprar</button>`}
                </div>
            </div>
        `;
    }).join('');
}

function renderMarketCollectionFilter() {
    const select = document.getElementById('market-filter-collection');
    if (!select) return;
    const current = String(select.value || '');
    const collections = [...new Set(state.master.map((c) => c.collection || 'Geral'))].sort((a, b) => String(a).localeCompare(String(b)));
    select.innerHTML = '<option value="">Todas as colecoes</option>' + collections.map((c) => `<option value="${c}">${c}</option>`).join('');
    if (collections.includes(current)) {
        select.value = current;
    }
}

function renderMarketSellSelect() {
    const sellSelect = document.getElementById('market-sell-card');
    const sellBtn = document.getElementById('market-sell-btn');
    if (!sellSelect || !sellBtn) return;
    const cards = marketSellOptions();
    if (!cards.length) {
        sellSelect.innerHTML = '<option value="">Sem duplicadas disponiveis</option>';
        sellSelect.disabled = true;
        sellBtn.disabled = true;
        updateMarketSellHint();
        return;
    }
    const prev = String(sellSelect.value || '');
    sellSelect.innerHTML = cards.map((card) => {
        const dup = Math.max(Number(state.collection[card.id] || 0) - 1, 0);
        return `<option value="${card.id}">${card.name} (${card.collection} • dup ${dup})</option>`;
    }).join('');
    if (cards.some((c) => String(c.id) === prev)) {
        sellSelect.value = prev;
    }
    sellSelect.disabled = false;
    sellBtn.disabled = false;
    updateMarketSellHint();
}

async function refreshMarketState() {
    const res = await get('market_state');
    if (!res.ok) {
        throw new Error(res.message || 'Erro ao carregar mercado');
    }
    state.market.listings = Array.isArray(res.listings) ? res.listings : [];
    state.market.myListings = Array.isArray(res.my_listings) ? res.my_listings : [];
    state.market.priceCaps = res.price_caps || state.market.priceCaps;
    state.collection = res.collection || state.collection;
    state.user.coins = Number(res.coins || state.user.coins || 0);
}

async function renderMarket() {
    const fb = document.getElementById('market-feedback');
    try {
        await refreshMarketState();
        renderMarketCollectionFilter();
        renderMarketSellSelect();
        renderMarketMine();
        renderMarketListings();
        document.getElementById('coin-count').innerText = state.user.coins || 0;
        if (fb) fb.textContent = '';
    } catch (err) {
        if (fb) fb.textContent = err.message || 'Erro ao carregar mercado.';
    }
}

async function openPack(type) {
    const cfg = state.packTypes[type];
    if (!cfg) return;
    if (state.user.coins < Number(cfg.price)) {
        alert('Moedas insuficientes');
        return;
    }
    lastPackType = type;
    const p = document.getElementById(`pack-${type}`);
    p.classList.add('shaking');
    setTimeout(async () => {
        p.classList.remove('shaking');
        const res = await post('buy_pack', { packType: type });
        if (!res.ok) {
            alert(res.message || 'Erro ao abrir pacote');
            return;
        }
        state.user.coins = Number(res.coins || state.user.coins);
        state.collection = res.collection || state.collection;
        document.getElementById('coin-count').innerText = state.user.coins;
        renderCollectionRewards();
        showRevealModal(Array.isArray(res.cards) ? res.cards : [], Number(res.bonus_points || 0));
    }, 1000);
}
window.openPack = openPack;

function showRevealModal(cards, bonusPoints = 0) {
    const m = document.getElementById('reveal-modal');
    const c = document.getElementById('revealed-cards-container');
    const b = document.getElementById('btn-close-modal');
    const reopen = document.getElementById('btn-open-again');
    const t = document.getElementById('reveal-title');
    c.innerHTML = '';
    b.classList.add('hidden');
    reopen?.classList.add('hidden');
    t.innerText = 'Toque nas cartas para revelar!';
    m.classList.remove('hidden');
    m.classList.add('flex');
    let flipped = 0;
    cards.forEach((card, i) => {
        const el = document.createElement('div');
        el.className = 'revealed-card card-container w-56 h-80';
        el.innerHTML = `<div class="card-inner shadow-2xl"><div class="card-back flex flex-col justify-center items-center"><h3 class="text-4xl font-black text-zinc-500 italic">FBA</h3><div class="mt-4 bg-zinc-800 px-3 py-1 rounded text-xs font-bold text-zinc-300 border border-zinc-700 animate-pulse">TOCAR</div></div><div class="card-front border-4 ${rarityClass(card.rarity)}"><img src="${card.img}" class="w-full h-full object-cover"><div class="absolute bottom-0 left-0 right-0 bg-black/70 px-2 py-2"><div class="text-sm font-bold">${card.name}</div><div class="text-xs text-zinc-300">${card.collection || 'Geral'} • ${card.team} • ${card.position} • OVR ${card.ovr}</div></div></div></div>`;
        el.onclick = function () {
            if (!this.classList.contains('flipped')) {
                this.classList.add('flipped');
                flipped++;
                if (flipped === cards.length) setTimeout(() => {
                    t.innerText = bonusPoints > 0
                        ? `Cartas adicionadas ao Ãlbum! Bônus de repetidas: +${bonusPoints} pontos`
                        : 'Cartas adicionadas ao Ãlbum!';
                    b.classList.remove('hidden');
                    if (reopen) {
                        const cfg = lastPackType ? state.packTypes?.[lastPackType] : null;
                        const price = cfg ? Number(cfg.price || 0) : 0;
                        reopen.textContent = price > 0
                            ? `Abrir novamente (${price} moedas)`
                            : 'Abrir novamente';
                        reopen.classList.remove('hidden');
                        reopen.disabled = !lastPackType;
                    }
                }, 600);
            }
        };
        c.appendChild(el);
        setTimeout(() => el.classList.add('show'), 400 + (i * 300));
    });
}

function closeRevealModal() {
    const m = document.getElementById('reveal-modal');
    m.classList.add('hidden');
    m.classList.remove('flex');
}
window.closeRevealModal = closeRevealModal;

function openPackAgain() {
    if (!lastPackType) return;
    closeRevealModal();
    openPack(lastPackType);
}
window.openPackAgain = openPackAgain;

function renderAdminCards() {
    const list = document.getElementById('admin-cards-list');
    if (!list || !state.user?.is_admin) return;

    const collectionFilter = document.getElementById('admin-filter-collection');
    const rarityFilter = document.getElementById('admin-filter-rarity');

    const collections = [...new Set(state.master.map((c) => c.collection || 'Geral').filter(Boolean))].sort((a, b) => a.localeCompare(b));
    if (collectionFilter) {
        const selected = collectionFilter.value;
        collectionFilter.innerHTML = '<option value="">Todas as coleções</option>';
        collections.forEach((collectionName) => {
            const option = document.createElement('option');
            option.value = collectionName;
            option.textContent = collectionName;
            collectionFilter.appendChild(option);
        });
        collectionFilter.value = collections.includes(selected) ? selected : '';
    }

    const selectedCollection = collectionFilter?.value || '';
    const selectedRarity = rarityFilter?.value || '';
    const filtered = state.master.filter((card) => {
        if (selectedCollection && (card.collection || 'Geral') !== selectedCollection) return false;
        if (selectedRarity && card.rarity !== selectedRarity) return false;
        return true;
    });

    const latest = [...filtered].slice(-100).reverse();
    list.innerHTML = latest.length
        ? latest.map((c) => `
            <div class="bg-zinc-900 rounded-lg p-3 border border-zinc-700 flex justify-between gap-2">
                <div>
                    <div class="font-bold">${c.name}</div>
                    <div class="text-xs text-zinc-400">${c.collection || 'Geral'} • ${c.team} • ${c.position} • ${c.rarity.toUpperCase()}</div>
                </div>
                <div class="text-end">
                    <div class="text-white font-black">${c.ovr}</div>
                    <div class="flex gap-1 mt-2">
                        <button class="px-2 py-1 text-xs bg-zinc-700 hover:bg-zinc-600 rounded" onclick="startEditCard(${c.id})">Editar</button>
                        <button class="px-2 py-1 text-xs bg-red-800 hover:bg-red-700 rounded" onclick="deleteCardById(${c.id})">Excluir</button>
                    </div>
                </div>
            </div>
        `).join('')
        : '<p class="text-zinc-400">Nenhuma carta encontrada com os filtros.</p>';
}

async function loadAdminPackCollections() {
    const container = document.getElementById('admin-pack-collections');
    if (!container || !state.user?.is_admin) return;
    container.innerHTML = '<span class="text-zinc-400">Carregando...</span>';
    try {
        const res = await get('admin_pack_collections');
        if (!res.ok) {
            container.innerHTML = '<span class="text-zinc-400">Falha ao carregar colecoes.</span>';
            return;
        }
        state.adminPackCollections = Array.isArray(res.collections) ? res.collections : [];
        renderAdminPackCollections();
    } catch (err) {
        container.innerHTML = '<span class="text-zinc-400">Erro ao carregar colecoes.</span>';
    }
}

function renderAdminPackCollections() {
    const container = document.getElementById('admin-pack-collections');
    if (!container || !state.user?.is_admin) return;
    if (!state.adminPackCollections.length) {
        container.innerHTML = '<span class="text-zinc-400">Nenhuma colecao encontrada.</span>';
        return;
    }
    container.innerHTML = '';
    state.adminPackCollections.forEach((item) => {
        const label = document.createElement('label');
        label.className = 'flex items-center gap-2 px-3 py-2 rounded-full border border-zinc-700 bg-zinc-900';

        const checkbox = document.createElement('input');
        checkbox.type = 'checkbox';
        checkbox.checked = Number(item.in_pack || 0) === 1;
        checkbox.addEventListener('change', async () => {
            checkbox.disabled = true;
            try {
                const res = await post('admin_pack_collections', {
                    collection: item.collection_name,
                    enabled: checkbox.checked ? 1 : 0
                });
                if (!res.ok) {
                    checkbox.checked = !checkbox.checked;
                    alert(res.message || 'Erro ao atualizar colecao.');
                }
            } catch (err) {
                checkbox.checked = !checkbox.checked;
                alert(err.message || 'Erro ao atualizar colecao.');
            } finally {
                checkbox.disabled = false;
            }
        });

        const text = document.createElement('span');
        text.textContent = item.collection_name;

        label.appendChild(checkbox);
        label.appendChild(text);
        container.appendChild(label);
    });
}

function resetAdminForm() {
    const form = document.getElementById('admin-card-form');
    if (form) form.reset();
    const teamOther = document.getElementById('admin-team-other');
    const idInput = document.getElementById('admin-card-id');
    const saveBtn = document.getElementById('admin-save-btn');
    const cancelBtn = document.getElementById('admin-cancel-edit-btn');
    const deleteBtn = document.getElementById('admin-delete-btn');
    if (idInput) idInput.value = '';
    if (teamOther) {
        teamOther.value = '';
        teamOther.classList.add('hidden');
    }
    if (saveBtn) saveBtn.textContent = 'Cadastrar Carta';
    if (cancelBtn) cancelBtn.classList.add('hidden');
    if (deleteBtn) deleteBtn.classList.add('hidden');
}

window.startEditCard = function(cardId) {
    const card = state.master.find((c) => Number(c.id) === Number(cardId));
    if (!card) return;
    const teamSelect = document.getElementById('admin-team');
    const teamOther = document.getElementById('admin-team-other');
    document.getElementById('admin-card-id').value = String(card.id);
    document.getElementById('admin-collection').value = card.collection || 'Geral';
    if (teamSelect) {
        const hasOption = Array.from(teamSelect.options).some((opt) => opt.value === (card.team || ''));
        teamSelect.value = hasOption ? (card.team || '') : '__other__';
    }
    if (teamOther) {
        teamOther.value = (teamSelect && teamSelect.value === '__other__') ? (card.team || '') : '';
        teamOther.classList.toggle('hidden', !(teamSelect && teamSelect.value === '__other__'));
    }
    document.getElementById('admin-name').value = card.name || '';
    document.getElementById('admin-position').value = card.position || 'PG';
    document.getElementById('admin-rarity').value = card.rarity || 'comum';
    document.getElementById('admin-ovr').value = card.ovr || 70;
    const saveBtn = document.getElementById('admin-save-btn');
    const cancelBtn = document.getElementById('admin-cancel-edit-btn');
    const deleteBtn = document.getElementById('admin-delete-btn');
    if (saveBtn) saveBtn.textContent = 'Salvar Alterações';
    if (cancelBtn) cancelBtn.classList.remove('hidden');
    if (deleteBtn) deleteBtn.classList.remove('hidden');
};

function toggleAdminTeamOtherField() {
    const teamSelect = document.getElementById('admin-team');
    const teamOther = document.getElementById('admin-team-other');
    if (!teamSelect || !teamOther) return;
    const isOther = teamSelect.value === '__other__';
    teamOther.classList.toggle('hidden', !isOther);
    if (!isOther) teamOther.value = '';
}

function getAdminTeamName() {
    const teamSelect = document.getElementById('admin-team');
    const teamOther = document.getElementById('admin-team-other');
    if (!teamSelect) return '';
    if (teamSelect.value === '__other__') {
        return String(teamOther?.value || '').trim();
    }
    return String(teamSelect.value || '').trim();
}

window.deleteCardById = async function(cardId) {
    if (!confirm('Tem certeza que deseja excluir esta carta?')) return;
    const fb = document.getElementById('admin-feedback');
    if (fb) {
        fb.textContent = 'Excluindo carta...';
        fb.className = 'mt-3 text-sm text-zinc-300';
    }
    try {
        const res = await post('admin_delete_card', { card_id: Number(cardId) });
        if (!res.ok) {
            if (fb) {
                fb.textContent = res.message || 'Erro ao excluir';
                fb.className = 'mt-3 text-sm text-red-300';
            }
            return;
        }
        state.master = state.master.filter((c) => Number(c.id) !== Number(cardId));
        resetAdminForm();
        renderAdminCards();
        renderAlbum();
        if (fb) {
            fb.textContent = 'Carta excluída com sucesso.';
            fb.className = 'mt-3 text-sm text-emerald-300';
        }
    } catch (err) {
        if (fb) {
            fb.textContent = err.message || 'Falha de comunicação ao excluir.';
            fb.className = 'mt-3 text-sm text-red-300';
        }
    }
};

document.getElementById('admin-cancel-edit-btn')?.addEventListener('click', () => {
    resetAdminForm();
});

document.getElementById('admin-delete-btn')?.addEventListener('click', async () => {
    const id = Number(document.getElementById('admin-card-id')?.value || 0);
    if (id > 0) {
        await window.deleteCardById(id);
    }
});
document.getElementById('admin-team')?.addEventListener('change', toggleAdminTeamOtherField);
document.getElementById('album-collection-filter')?.addEventListener('input', renderAlbum);
document.getElementById('market-sell-card')?.addEventListener('change', updateMarketSellHint);
document.getElementById('market-filter-name')?.addEventListener('input', renderMarketListings);
document.getElementById('market-filter-collection')?.addEventListener('change', renderMarketListings);
document.getElementById('market-filter-rarity')?.addEventListener('change', renderMarketListings);
document.getElementById('market-toggle-mine')?.addEventListener('click', () => {
    const wrap = document.getElementById('market-mine-wrap');
    const btn = document.getElementById('market-toggle-mine');
    if (!wrap || !btn) return;
    wrap.classList.toggle('hidden');
    btn.textContent = wrap.classList.contains('hidden') ? 'Ver minhas cartas a venda' : 'Ocultar minhas cartas a venda';
});

document.getElementById('market-sell-btn')?.addEventListener('click', async () => {
    const cardId = Number(document.getElementById('market-sell-card')?.value || 0);
    const price = Number(document.getElementById('market-sell-price')?.value || 0);
    const fb = document.getElementById('market-feedback');
    if (!cardId || !price) {
        if (fb) fb.textContent = 'Selecione uma carta e informe o preco.';
        return;
    }
    try {
        const res = await post('market_create_listing', { card_id: cardId, price_points: price });
        if (!res.ok) {
            if (fb) fb.textContent = res.message || 'Erro ao criar anuncio.';
            return;
        }
        await renderMarket();
        renderAlbum();
        if (fb) fb.textContent = 'Carta anunciada com sucesso.';
    } catch (err) {
        if (fb) fb.textContent = err.message || 'Erro ao criar anuncio.';
    }
});

document.getElementById('market-list')?.addEventListener('click', async (event) => {
    const btn = event.target.closest('[data-market-buy]');
    if (!btn) return;
    const listingId = Number(btn.getAttribute('data-market-buy') || 0);
    const fb = document.getElementById('market-feedback');
    if (!listingId) return;
    try {
        const res = await post('market_buy_listing', { listing_id: listingId });
        if (!res.ok) {
            if (fb) fb.textContent = res.message || 'Erro ao comprar carta.';
            return;
        }
        await renderMarket();
        renderAlbum();
        if (fb) fb.textContent = 'Compra concluida com sucesso.';
    } catch (err) {
        if (fb) fb.textContent = err.message || 'Erro ao comprar carta.';
    }
});

document.getElementById('market-mine-list')?.addEventListener('click', async (event) => {
    const btn = event.target.closest('[data-market-cancel]');
    if (!btn) return;
    const listingId = Number(btn.getAttribute('data-market-cancel') || 0);
    const fb = document.getElementById('market-feedback');
    if (!listingId) return;
    try {
        const res = await post('market_cancel_listing', { listing_id: listingId });
        if (!res.ok) {
            if (fb) fb.textContent = res.message || 'Erro ao cancelar anuncio.';
            return;
        }
        await renderMarket();
        renderAlbum();
        if (fb) fb.textContent = 'Anuncio cancelado e carta devolvida.';
    } catch (err) {
        if (fb) fb.textContent = err.message || 'Erro ao cancelar anuncio.';
    }
});

document.getElementById('admin-filter-collection')?.addEventListener('change', renderAdminCards);
document.getElementById('admin-filter-rarity')?.addEventListener('change', renderAdminCards);
document.getElementById('admin-filter-clear')?.addEventListener('click', () => {
    const collection = document.getElementById('admin-filter-collection');
    const rarity = document.getElementById('admin-filter-rarity');
    if (collection) collection.value = '';
    if (rarity) rarity.value = '';
    renderAdminCards();
});

document.getElementById('admin-card-form')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const cardId = Number(document.getElementById('admin-card-id')?.value || 0);
    const fileInput = document.getElementById('admin-image-file');
    const file = fileInput?.files?.[0];
    if (!cardId && !file) {
        alert('Selecione uma imagem da figurinha.');
        return;
    }
    const teamName = getAdminTeamName();
    const collectionName = document.getElementById('admin-collection')?.value.trim() || '';
    if (!collectionName) {
        alert('Informe o nome da coleção.');
        return;
    }
    if (!teamName) {
        alert('Selecione um time ou informe no campo "Outro".');
        return;
    }
    const form = new FormData();
    form.append('collection_name', collectionName);
    form.append('team_name', teamName);
    form.append('card_name', document.getElementById('admin-name').value.trim());
    form.append('position', document.getElementById('admin-position').value);
    form.append('rarity', document.getElementById('admin-rarity').value);
    form.append('ovr', String(Number(document.getElementById('admin-ovr').value)));
    if (cardId) {
        form.append('card_id', String(cardId));
    }
    if (file) {
        form.append('card_image', file);
    }

    const fb = document.getElementById('admin-feedback');
    fb.textContent = 'Salvando...';
    try {
        const action = cardId ? 'admin_update_card' : 'admin_create_card';
        const res = await postForm(action, form);
        if (!res.ok) {
            fb.textContent = res.message || 'Erro ao cadastrar';
            fb.className = 'mt-3 text-sm text-red-300';
            return;
        }
        if (cardId) {
            const idx = state.master.findIndex((c) => Number(c.id) === Number(cardId));
            if (idx >= 0) state.master[idx] = res.card;
            else state.master.push(res.card);
            fb.textContent = 'Carta atualizada com sucesso.';
        } else {
            state.master.push(res.card);
            fb.textContent = 'Carta cadastrada com sucesso.';
        }
        fb.className = 'mt-3 text-sm text-emerald-300';
        renderAdminCards();
        renderAlbum();
        if (cardId) {
            if (fileInput) fileInput.value = '';
            window.startEditCard(res.card?.id || cardId);
        } else {
            resetAdminForm();
        }
    } catch (err) {
        fb.textContent = err.message || 'Falha de comunicação com o servidor.';
        fb.className = 'mt-3 text-sm text-red-300';
    }
});
toggleAdminTeamOtherField();
bootstrap();

