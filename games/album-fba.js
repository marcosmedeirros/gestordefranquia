const API = 'album-fba-api.php';
let state = { user: null, master: [], collection: {}, myTeam: [null, null, null, null, null], ranking: [], packTypes: {} };
let currentSlot = null;
const slotPositions = ['PG', 'SG', 'SF', 'PF', 'C'];

const rarityClass = (r) => ({ comum: 'rarity-comum', rara: 'rarity-rara', epico: 'rarity-epico', lendario: 'rarity-lendario' }[r] || 'rarity-comum');
const hasCard = (id) => Number(state.collection[id] || 0) > 0;

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
        if (b) b.className = 'px-4 md:px-6 py-2 rounded-t-lg bg-zinc-900 text-zinc-300 font-bold fba-title hover:bg-zinc-800';
    });
    document.getElementById('section-' + tab)?.classList.remove('hidden');
    const active = document.getElementById('tab-' + tab);
    if (active) active.className = 'px-4 md:px-6 py-2 rounded-t-lg bg-red-700 font-bold fba-title text-white';
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
        section.className = 'bg-zinc-950/60 p-4 rounded-xl border border-zinc-700';
        section.innerHTML = `<h3 class="text-xl font-bold fba-title mb-4 border-b border-zinc-700 pb-2 text-red-400">${team}</h3>`;
        const grid = document.createElement('div');
        grid.className = 'grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-4';
        cards.forEach((card) => {
            const got = hasCard(card.id);
            if (got) count++;
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
    document.getElementById('album-progress').innerText = `Progresso: ${count} / ${state.master.length} figurinhas`;
    document.getElementById('coin-count').innerText = state.user.coins || 0;
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
        c.innerHTML = `<p class="text-zinc-400 col-span-full text-center py-8">VocÃª nÃ£o tem carta disponÃ­vel para a posiÃ§Ã£o ${requiredPosition || '-'}.<\/p>`;
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
    if (meta) meta.textContent = `${card.team} â€¢ ${card.position} â€¢ OVR ${card.ovr} â€¢ ${String(card.rarity || '').toUpperCase()}`;
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
document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') return;
    const modal = document.getElementById('album-card-modal');
    if (modal && !modal.classList.contains('hidden')) {
        closeAlbumCardModal();
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
    else board.push({ name: state.user.name, ovr: my, isUser: true });
    board.sort((a, b) => b.ovr - a.ovr);
    tb.innerHTML = '';
    board.forEach((p, i) => {
        const mine = p.name === state.user.name || p.isUser;
        const pos = i === 0 ? 'ðŸ¥‡' : i === 1 ? 'ðŸ¥ˆ' : i === 2 ? 'ðŸ¥‰' : String(i + 1);
        const tr = document.createElement('tr');
        tr.className = mine ? 'bg-red-900/40 border-b border-red-500/30 font-bold text-white' : 'border-b border-zinc-700/50';
        tr.innerHTML = `<td class="p-4 text-center text-xl">${pos}</td><td class="p-4">${p.name}${mine ? ' (VocÃª)' : ''}</td><td class="p-4 text-center font-black text-white">${Number(p.ovr || 0)}</td>`;
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
        showRevealModal(Array.isArray(res.cards) ? res.cards : []);
    }, 1000);
}
window.openPack = openPack;

function showRevealModal(cards) {
    const m = document.getElementById('reveal-modal');
    const c = document.getElementById('revealed-cards-container');
    const b = document.getElementById('btn-close-modal');
    const t = document.getElementById('reveal-title');
    c.innerHTML = '';
    b.classList.add('hidden');
    t.innerText = 'Toque nas cartas para revelar!';
    m.classList.remove('hidden');
    m.classList.add('flex');
    let flipped = 0;
    cards.forEach((card, i) => {
        const el = document.createElement('div');
        el.className = 'revealed-card card-container w-56 h-80';
        el.innerHTML = `<div class="card-inner shadow-2xl"><div class="card-back flex flex-col justify-center items-center"><h3 class="text-4xl font-black text-zinc-500 italic">FBA</h3><div class="mt-4 bg-zinc-800 px-3 py-1 rounded text-xs font-bold text-zinc-300 border border-zinc-700 animate-pulse">TOCAR</div></div><div class="card-front border-4 ${rarityClass(card.rarity)}"><img src="${card.img}" class="w-full h-full object-cover"><div class="absolute bottom-0 left-0 right-0 bg-black/70 px-2 py-2"><div class="text-sm font-bold">${card.name}</div><div class="text-xs text-zinc-300">${card.team} â€¢ ${card.position} â€¢ OVR ${card.ovr}</div></div></div></div>`;
        el.onclick = function () {
            if (!this.classList.contains('flipped')) {
                this.classList.add('flipped');
                flipped++;
                if (flipped === cards.length) setTimeout(() => { t.innerText = 'Cartas adicionadas ao Ãlbum!'; b.classList.remove('hidden'); }, 600);
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
    switchTab('album');
}
window.closeRevealModal = closeRevealModal;

function renderAdminCards() {
    const list = document.getElementById('admin-cards-list');
    if (!list || !state.user?.is_admin) return;

    const teamFilter = document.getElementById('admin-filter-team');
    const rarityFilter = document.getElementById('admin-filter-rarity');

    const teams = [...new Set(state.master.map((c) => c.team).filter(Boolean))].sort((a, b) => a.localeCompare(b));
    if (teamFilter && !teamFilter.dataset.loaded) {
        teams.forEach((team) => {
            const option = document.createElement('option');
            option.value = team;
            option.textContent = team;
            teamFilter.appendChild(option);
        });
        teamFilter.dataset.loaded = '1';
    }

    const selectedTeam = teamFilter?.value || '';
    const selectedRarity = rarityFilter?.value || '';
    const filtered = state.master.filter((card) => {
        if (selectedTeam && card.team !== selectedTeam) return false;
        if (selectedRarity && card.rarity !== selectedRarity) return false;
        return true;
    });

    const latest = [...filtered].slice(-100).reverse();
    list.innerHTML = latest.length
        ? latest.map((c) => `
            <div class="bg-zinc-900 rounded-lg p-3 border border-zinc-700 flex justify-between gap-2">
                <div>
                    <div class="font-bold">${c.name}</div>
                    <div class="text-xs text-zinc-400">${c.team} • ${c.position} • ${c.rarity.toUpperCase()}</div>
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

function resetAdminForm() {
    const form = document.getElementById('admin-card-form');
    if (form) form.reset();
    const idInput = document.getElementById('admin-card-id');
    const saveBtn = document.getElementById('admin-save-btn');
    const cancelBtn = document.getElementById('admin-cancel-edit-btn');
    const deleteBtn = document.getElementById('admin-delete-btn');
    if (idInput) idInput.value = '';
    if (saveBtn) saveBtn.textContent = 'Cadastrar Carta';
    if (cancelBtn) cancelBtn.classList.add('hidden');
    if (deleteBtn) deleteBtn.classList.add('hidden');
}

window.startEditCard = function(cardId) {
    const card = state.master.find((c) => Number(c.id) === Number(cardId));
    if (!card) return;
    document.getElementById('admin-card-id').value = String(card.id);
    document.getElementById('admin-team').value = card.team || '';
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

document.getElementById('admin-filter-team')?.addEventListener('change', renderAdminCards);
document.getElementById('admin-filter-rarity')?.addEventListener('change', renderAdminCards);
document.getElementById('admin-filter-clear')?.addEventListener('click', () => {
    const team = document.getElementById('admin-filter-team');
    const rarity = document.getElementById('admin-filter-rarity');
    if (team) team.value = '';
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
    const form = new FormData();
    form.append('team_name', document.getElementById('admin-team').value.trim());
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
        resetAdminForm();
        renderAdminCards();
        renderAlbum();
    } catch (err) {
        fb.textContent = err.message || 'Falha de comunicação com o servidor.';
        fb.className = 'mt-3 text-sm text-red-300';
    }
});
bootstrap();

