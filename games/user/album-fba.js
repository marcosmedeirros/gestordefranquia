const API = (() => {
    const path = window.location.pathname.replace(/\/$/, '').replace(/\/[^/]*$/, '');
    return `${window.location.origin}${path}/album-fba-api.php`;
})();
let state = { user: null, master: [], collection: {}, myTeam: [null, null, null, null, null], ranking: [], packTypes: {} };
state.redeemedCollections = [];
let currentSlot = null;
let lastPackType = null;
const slotPositions = ['PG', 'SG', 'SF', 'PF', 'C'];

const rarityClass = (r) => ({ comum: 'rarity-comum', rara: 'rarity-rara', epico: 'rarity-epico', lendario: 'rarity-lendario' }[r] || 'rarity-comum');
const hasCard = (id) => Number(state.collection[id] || 0) > 0;
const rarityLabel = (r) => ({ comum: 'Comum', rara: 'Rara', epico: 'Epica', lendario: 'Lendaria' }[r] || r);
const post = (action, payload = {}) => fetch(`${API}?action=${action}`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload), credentials: 'same-origin' }).then((r) => r.json());
const get = (action) => fetch(`${API}?action=${action}`, { cache: 'no-store', credentials: 'same-origin' }).then((r) => r.json());
const getWithParams = (action, params = {}) => {
    const query = new URLSearchParams({ action, ...params }).toString();
    return fetch(`${API}?${query}`, { cache: 'no-store', credentials: 'same-origin' }).then((r) => r.json());
};

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

async function bootstrap() {
    const res = await get('bootstrap');
    if (!res.ok) {
        alert(res.message || 'Erro ao carregar álbum');
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
    if (state.user.is_admin) renderAdminCards();
}

function switchTab(tab) {
    const all = ['album', 'team', 'ranking', 'store'];
    if (state.user?.is_admin) all.push('admin');
    all.forEach((t) => {
        document.getElementById('section-' + t)?.classList.add('hidden');
        const b = document.getElementById('tab-' + t);
        if (b) b.className = 'px-4 md:px-6 py-2 rounded-t-lg bg-slate-800 text-slate-400 font-bold fba-title hover:bg-slate-700';
    });
    document.getElementById('section-' + tab)?.classList.remove('hidden');
    const active = document.getElementById('tab-' + tab);
    if (active) active.className = 'px-4 md:px-6 py-2 rounded-t-lg bg-blue-600 font-bold fba-title text-white';
    if (tab === 'album') renderAlbum();
    if (tab === 'team') renderCourt();
    if (tab === 'ranking') renderRanking();
    if (tab === 'admin') renderAdminCards();
}
window.switchTab = switchTab;

function renderAlbum() {
    const c = document.getElementById('album-container');
    c.innerHTML = '';
    let count = 0;
    const teams = [...new Set(state.master.map((x) => x.team))];
    teams.forEach((team) => {
        const cards = state.master.filter((x) => x.team === team);
        const section = document.createElement('div');
        section.className = 'bg-slate-800/30 p-4 rounded-xl border border-slate-700/50';
        section.innerHTML = `<h3 class="text-xl font-bold fba-title mb-4 border-b border-slate-600 pb-2 text-emerald-400">${team}</h3>`;
        const grid = document.createElement('div');
        grid.className = 'grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-4';
        cards.forEach((card) => {
            const got = hasCard(card.id);
            if (got) count++;
            const slot = document.createElement('div');
            slot.className = `album-slot rounded-xl overflow-hidden relative flex items-center justify-center ${got ? 'collected ' + rarityClass(card.rarity) : 'p-2'}`;
            slot.innerHTML = got
                ? `<img src="${card.img}" class="w-full h-full object-cover"><div class="absolute top-1 right-1 bg-black/80 px-1.5 py-0.5 rounded text-[0.6rem] font-bold text-white">#${card.id}</div><div class="absolute bottom-1 left-1 bg-black/80 px-1.5 py-0.5 rounded text-[0.6rem] font-bold text-emerald-300">x${state.collection[card.id]}</div>`
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
    document.getElementById('album-progress').innerText = `Progresso: ${count} / ${state.master.length} figurinhas`;
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
        wrap.innerHTML = '<div class="text-slate-500 text-sm">Nenhuma colecao encontrada.</div>';
        return;
    }
    wrap.innerHTML = '';
    data.forEach((item) => {
        const card = document.createElement('div');
        card.className = 'min-w-[230px] bg-slate-900/80 border border-slate-700 rounded-xl p-4 flex flex-col gap-3';
        const isComplete = item.total > 0 && item.owned >= item.total;
        const status = item.redeemed
            ? '<span class="text-xs bg-slate-700 text-slate-200 px-2 py-1 rounded-full">Resgatado</span>'
            : isComplete
                ? '<span class="text-xs bg-emerald-600 text-white px-2 py-1 rounded-full">Completo</span>'
                : '<span class="text-xs bg-slate-800 text-slate-300 px-2 py-1 rounded-full">Em progresso</span>';
        card.innerHTML = `
            <div class="flex items-start justify-between gap-2">
                <div class="font-bold text-emerald-300">${item.name}</div>
                ${status}
            </div>
            <div class="text-sm text-slate-300">${item.owned}/${item.total} figurinhas</div>
            <div class="w-full bg-slate-800 rounded-full h-2">
                <div class="h-2 rounded-full bg-emerald-500" style="width:${item.percent}%"></div>
            </div>
            <div class="text-xs text-slate-400">${item.percent}% completo</div>
        `;
        if (isComplete && !item.redeemed) {
            const btn = document.createElement('button');
            btn.className = 'mt-1 bg-emerald-600 hover:bg-emerald-500 rounded-lg px-3 py-2 text-sm font-bold text-black';
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
            el.innerHTML = `<img src="${card.img}"><div class="absolute -bottom-2 bg-black/80 px-2 py-0.5 rounded text-[0.6rem] font-bold text-yellow-400 border border-yellow-500/50">OVR ${card.ovr}</div>`;
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
    if (!cards.length) c.innerHTML = `<p class="text-slate-400 col-span-full text-center py-8">Você não tem carta disponível para a posição ${requiredPosition || '-'}.<\/p>`;
    cards.forEach((card) => {
        const used = state.myTeam.some((id, idx) => idx !== slot && id === card.id);
        const el = document.createElement('div');
        el.className = `aspect-[2.5/3.5] rounded-lg overflow-hidden relative border-2 ${rarityClass(card.rarity)} ${used ? 'opacity-30 cursor-not-allowed' : 'cursor-pointer hover:scale-105'} transition-transform`;
        el.innerHTML = `<img src="${card.img}" class="w-full h-full object-cover"><div class="absolute top-1 left-1 bg-black/80 px-1.5 py-0.5 rounded text-[0.7rem] font-bold text-yellow-400 border border-yellow-500/30">OVR ${card.ovr}</div>${used ? '<div class="absolute inset-0 bg-black/60 flex items-center justify-center font-bold text-sm text-center p-2">EM USO</div>' : ''}`;
        if (!used) el.onclick = () => selectCard(card.id);
        c.appendChild(el);
    });
    if (state.myTeam[slot] !== null) {
        const r = document.createElement('div');
        r.className = 'aspect-[2.5/3.5] rounded-lg border-2 border-red-500 bg-red-500/20 flex flex-col items-center justify-center cursor-pointer hover:bg-red-500/40 text-red-300 font-bold text-sm text-center p-2';
        r.innerHTML = '✖<br>Remover';
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
    if (meta) meta.textContent = `${card.team} • ${card.position} • OVR ${card.ovr} • ${String(card.rarity || '').toUpperCase()}`;
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
        item.className = `rounded-lg border ${card ? rarityClass(card.rarity) : 'border-slate-700'} bg-slate-900/60 p-2`;
        item.innerHTML = card
            ? `<div class="text-[0.7rem] font-bold text-slate-200 mb-1">${pos}</div><div class="aspect-[2.5/3.5] rounded overflow-hidden relative bg-slate-950"><img src="${card.img}" class="w-full h-full object-contain"><div class="absolute bottom-0 left-0 right-0 bg-black/75 px-1 py-1"><div class="text-[0.65rem] font-bold leading-tight">${card.name}</div><div class="text-[0.6rem] text-slate-300">${card.team} • OVR ${card.ovr}</div></div></div>`
            : `<div class="text-[0.7rem] font-bold text-slate-200 mb-1">${pos}</div><div class="aspect-[2.5/3.5] rounded border border-dashed border-slate-600 bg-slate-800/60 flex items-center justify-center text-slate-400 text-xs font-bold text-center px-2">Sem carta</div>`;
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
    tb.innerHTML = '<tr><td colspan="3" class="p-4 text-center text-slate-400">Carregando...</td></tr>';
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
        tr.className = mine ? 'bg-blue-900/40 border-b border-blue-500/30 font-bold text-white cursor-pointer hover:bg-blue-900/60' : 'border-b border-slate-700/50 cursor-pointer hover:bg-slate-800/70';
        tr.innerHTML = `<td class="p-4 text-center text-xl">${pos}</td><td class="p-4">${p.name}${mine ? ' (Voce)' : ''}</td><td class="p-4 text-center font-black text-yellow-400">${Number(p.ovr || 0)}</td>`;
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
        el.innerHTML = `<div class="card-inner shadow-2xl"><div class="card-back flex flex-col justify-center items-center"><h3 class="text-4xl font-black text-slate-500 italic">FBA</h3><div class="mt-4 bg-slate-800 px-3 py-1 rounded text-xs font-bold text-slate-400 border border-slate-700 animate-pulse">TOCAR</div></div><div class="card-front border-4 ${rarityClass(card.rarity)}"><img src="${card.img}" class="w-full h-full object-cover"><div class="absolute bottom-0 left-0 right-0 bg-black/70 px-2 py-2"><div class="text-sm font-bold">${card.name}</div><div class="text-xs text-slate-300">${card.team} • ${card.position} • OVR ${card.ovr}</div></div></div></div>`;
        el.onclick = function () {
            if (!this.classList.contains('flipped')) {
                this.classList.add('flipped');
                flipped++;
                if (flipped === cards.length) setTimeout(() => {
                    t.innerText = bonusPoints > 0
                        ? `Cartas adicionadas ao Álbum! Bônus de repetidas: +${bonusPoints} pontos`
                        : 'Cartas adicionadas ao Álbum!';
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
    const latest = [...state.master].slice(-20).reverse();
    list.innerHTML = latest.length ? latest.map((c) => `<div class="bg-slate-800 rounded-lg p-3 border border-slate-700 flex justify-between gap-2"><div><div class="font-bold">${c.name}</div><div class="text-xs text-slate-400">${c.team} • ${c.position} • ${c.rarity.toUpperCase()}</div></div><div class="text-yellow-400 font-black">${c.ovr}</div></div>`).join('') : '<p class="text-slate-400">Nenhuma carta cadastrada.</p>';
}

document.getElementById('admin-card-form')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const payload = {
        team_name: document.getElementById('admin-team').value.trim(),
        card_name: document.getElementById('admin-name').value.trim(),
        position: document.getElementById('admin-position').value,
        rarity: document.getElementById('admin-rarity').value,
        ovr: Number(document.getElementById('admin-ovr').value),
        img_url: document.getElementById('admin-img').value.trim(),
    };
    const fb = document.getElementById('admin-feedback');
    fb.textContent = 'Salvando...';
    const res = await post('admin_create_card', payload);
    if (!res.ok) {
        fb.textContent = res.message || 'Erro ao cadastrar';
        fb.className = 'mt-3 text-sm text-red-300';
        return;
    }
    state.master.push(res.card);
    fb.textContent = 'Carta cadastrada com sucesso.';
    fb.className = 'mt-3 text-sm text-emerald-300';
    document.getElementById('admin-card-form').reset();
    renderAdminCards();
    renderAlbum();
});

bootstrap();
