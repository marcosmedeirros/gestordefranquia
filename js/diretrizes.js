const api = async (path, options = {}) => {
  const doFetch = async (url) => {
    const res = await fetch(url, {
      headers: { 'Content-Type': 'application/json' },
      ...options,
    });
    let body = {};
    try { body = await res.json(); } catch { body = {}; }
    return { res, body };
  };
  let { res, body } = await doFetch(`/api/${path}`);
  if (res.status === 404) ({ res, body } = await doFetch(`/public/api/${path}`));
  if (!res.ok) throw body;
  return body;
};

// Armazenar lista de jogadores para referência
let allPlayersData = [];
let playersById = {};
let currentDirective = null; // manter dados carregados para re-render confiável
const STARTER_LABELS = ['PG', 'SG', 'SF', 'PF', 'C'];

// Buscar todos os jogadores do time para renderizar campos de minutagem
async function loadPlayersData() {
  try {
    const data = await api('team-players.php');
    if (data.players) {
      allPlayersData = data.players;
      playersById = {};
      allPlayersData.forEach(p => { playersById[p.id] = p; });
    }
  } catch (err) {
    console.error('Erro ao carregar jogadores:', err);
  }
}

// Renderizar campos de minutagem para cada jogador
function renderPlayerMinutes() {
  const container = document.getElementById('player-minutes-container');
  if (!container) return;

  // Determinar limite máximo conforme fase do prazo (definido pelo admin)
  const deadlinePhase = window.__DEADLINE_PHASE__ || 'regular';
  const maxMinutes = deadlinePhase === 'playoffs' ? 45 : 40;

  // Limpar container
  container.innerHTML = '';

  // Helpers para coletar jogadores selecionados nos selects
  const getSelectedIds = (prefix, count) => {
    const ids = [];
    for (let i = 1; i <= count; i++) {
      const sel = document.querySelector(`select[name="${prefix}_${i}_id"]`);
      const val = sel ? parseInt(sel.value) : NaN;
      if (!isNaN(val) && val > 0) ids.push(val);
    }
    return ids;
  };

  // Coletar jogadores do banco via checkboxes
  const getBenchIds = () => {
    const ids = [];
    document.querySelectorAll('.bench-player-checkbox:checked').forEach(cb => {
      const val = parseInt(cb.value);
      if (!isNaN(val) && val > 0) ids.push(val);
    });
    return ids;
  };

  let starters = getSelectedIds('starter', 5);
  let bench = getBenchIds();

  // Fallback: se não houver seleção ainda, usar dados da diretriz existente
  if (starters.length === 0 && bench.length === 0 && currentDirective) {
    starters = [];
    bench = [];
    for (let i = 1; i <= 5; i++) {
      const sid = parseInt(currentDirective[`starter_${i}_id`]);
      if (!isNaN(sid) && sid > 0) starters.push(sid);
    }
    // Pegar banco dos player_minutes que não são titulares
    if (currentDirective.player_minutes) {
      Object.keys(currentDirective.player_minutes).forEach(pid => {
        const id = parseInt(pid);
        if (!starters.includes(id)) bench.push(id);
      });
    }
  }

  // Atualizar contador de banco
  const benchCount = document.getElementById('bench-count');
  if (benchCount) benchCount.textContent = bench.length;

  // Render seção Titulares
  if (starters.length > 0) {
    const title = document.createElement('div');
    title.className = 'col-12 mb-2';
    title.innerHTML = `<h6 class="text-orange mb-2"><i class="bi bi-trophy me-2"></i>Quinteto Titular</h6>`;
    container.appendChild(title);

    starters.forEach((id, idx) => {
      const player = playersById[id];
      if (!player) return;
  const slotLabel = STARTER_LABELS[idx] || `${idx + 1}`;
      const row = document.createElement('div');
      row.className = 'col-12';
      row.innerHTML = `
        <div class="form-group mb-2">
          <div class="d-flex align-items-center justify-content-between gap-3">
            <span class="text-white small">Titular ${slotLabel}: ${player.name}</span>
            <div class="input-group input-group-sm" style="max-width: 130px;">
              <input type="number" class="form-control bg-dark text-white border-orange player-minutes-input"
                     name="minutes_player_${player.id}"
                     data-player-id="${player.id}" data-player-name="${player.name}"
                     min="5" max="${maxMinutes}" value="${(currentDirective && currentDirective.player_minutes && currentDirective.player_minutes[player.id]) ? currentDirective.player_minutes[player.id] : 20}" placeholder="Minutos">
              <span class="input-group-text bg-dark text-orange border-orange">min</span>
            </div>
          </div>
          <small class="text-light-gray d-block">Min: 5 | Max: ${maxMinutes} (${deadlinePhase === 'playoffs' ? 'playoffs' : 'regular'})</small>
        </div>
      `;
      container.appendChild(row);
    });
  }

  // Render seção Banco
  if (bench.length > 0) {
    const titleB = document.createElement('div');
    titleB.className = 'col-12 mb-2 mt-2';
    titleB.innerHTML = `<h6 class="text-orange mb-2"><i class="bi bi-people me-2"></i>Banco (${bench.length} jogadores)</h6>`;
    container.appendChild(titleB);

    bench.forEach((id, idx) => {
      const player = playersById[id];
      if (!player) return;
      const row = document.createElement('div');
      row.className = 'col-12';
      row.innerHTML = `
        <div class="form-group mb-2">
          <div class="d-flex align-items-center justify-content-between gap-3">
            <span class="text-white small">Banco ${idx + 1}: ${player.name}</span>
            <div class="input-group input-group-sm" style="max-width: 130px;">
              <input type="number" class="form-control bg-dark text-white border-orange player-minutes-input"
                     name="minutes_player_${player.id}"
                     data-player-id="${player.id}" data-player-name="${player.name}"
                     min="5" max="${maxMinutes}" value="${(currentDirective && currentDirective.player_minutes && currentDirective.player_minutes[player.id]) ? currentDirective.player_minutes[player.id] : 20}" placeholder="Minutos">
              <span class="input-group-text bg-dark text-orange border-orange">min</span>
            </div>
          </div>
          <small class="text-light-gray d-block">Min: 5 | Max: ${maxMinutes} (${deadlinePhase === 'playoffs' ? 'playoffs' : 'regular'})</small>
        </div>
      `;
      container.appendChild(row);
    });
  }

  // Mensagem se não houver seleção
  if (starters.length === 0 && bench.length === 0) {
    const msg = document.createElement('div');
    msg.className = 'col-12';
    msg.innerHTML = `<p class="text-light-gray">Selecione os titulares e jogadores do banco para configurar a minutagem.</p>`;
    container.appendChild(msg);
  }

  // Adicionar contador de minutos totais
  if (starters.length > 0 || bench.length > 0) {
    const totalRow = document.createElement('div');
    totalRow.className = 'col-12 mt-3';
    totalRow.innerHTML = `
      <div class="alert alert-dark border-orange mb-0">
        <div class="d-flex justify-content-between align-items-center">
          <span class="text-white">
            <i class="bi bi-stopwatch me-2"></i>
            <strong>Total de Minutos:</strong>
          </span>
          <span id="total-minutes-display" class="badge bg-secondary fs-6">0 / 240</span>
        </div>
        <small class="text-light-gray d-block mt-1">A soma dos minutos de todos os jogadores deve ser exatamente 240 minutos.</small>
      </div>
    `;
    container.appendChild(totalRow);
    
    // Adicionar listeners para atualizar o total
    setTimeout(() => {
      updateTotalMinutesDisplay();
      document.querySelectorAll('.player-minutes-input').forEach(input => {
        input.addEventListener('input', updateTotalMinutesDisplay);
        input.addEventListener('change', updateTotalMinutesDisplay);
      });
    }, 10);
  }
}

// Função para atualizar o display de minutos totais
function updateTotalMinutesDisplay() {
  const display = document.getElementById('total-minutes-display');
  if (!display) return;
  
  let total = 0;
  document.querySelectorAll('.player-minutes-input').forEach(input => {
    total += parseInt(input.value) || 0;
  });
  
  display.textContent = `${total} / 240`;
  
  // Mudar cor baseado no total
  display.classList.remove('bg-success', 'bg-danger', 'bg-warning', 'bg-secondary');
  if (total === 240) {
    display.classList.add('bg-success');
  } else if (total > 240) {
    display.classList.add('bg-danger');
  } else if (total >= 200) {
    display.classList.add('bg-warning');
  } else {
    display.classList.add('bg-secondary');
  }
}

// Atualizar visibilidade dos campos de rotação automática
function updateRotationFieldsVisibility() {
  const rotationStyle = document.querySelector('select[name="rotation_style"]');
  const rotationPlayersField = document.getElementById('rotation-players-field');
  const veteranFocusField = document.getElementById('veteran-focus-field');
  const playerMinutesCard = document.getElementById('player-minutes-card');

  if (!rotationStyle) return;

  const isManualRotation = rotationStyle.value === 'manual';

  // Mostrar campos SOMENTE quando rotação for manual; esconder quando automática
  if (rotationPlayersField) {
    rotationPlayersField.style.display = isManualRotation ? 'block' : 'none';
  }
  if (veteranFocusField) {
    veteranFocusField.style.display = isManualRotation ? 'block' : 'none';
  }
  // Minutagem por jogador só aparece quando rotação é manual
  if (playerMinutesCard) {
    playerMinutesCard.style.display = isManualRotation ? 'block' : 'none';
  }
}

// Atualizar visibilidade dos checkboxes do banco (esconder titulares)
function updateBenchCheckboxesVisibility() {
  const starters = [];
  for (let i = 1; i <= 5; i++) {
    const sel = document.querySelector(`select[name="starter_${i}_id"]`);
    if (sel && sel.value) starters.push(parseInt(sel.value));
  }
  
  document.querySelectorAll('.bench-player-checkbox').forEach(checkbox => {
    const playerId = parseInt(checkbox.value);
    const item = checkbox.closest('.bench-player-item');
    const colWrapper = item ? item.closest('.col-md-4, .col-lg-3, [class*="col-"]') : null;
    
    if (starters.includes(playerId)) {
      // Esconder completamente jogadores que são titulares
      checkbox.checked = false;
      if (colWrapper) colWrapper.style.display = 'none';
    } else {
      // Mostrar jogadores que não são titulares
      if (colWrapper) colWrapper.style.display = '';
    }
  });
  
  // Atualizar contador
  const benchCount = document.getElementById('bench-count');
  if (benchCount) {
    const checked = document.querySelectorAll('.bench-player-checkbox:checked').length;
    benchCount.textContent = checked;
  }
  
  // Re-renderizar minutagem
  renderPlayerMinutes();
}

// Atualizar valores dos ranges
document.querySelectorAll('input[type="range"]').forEach(range => {
  const valueSpan = document.getElementById(`${range.name}-value`);
  if (valueSpan) {
    range.addEventListener('input', () => {
      valueSpan.textContent = range.value + '%';
    });
  }
});

// Adicionar listener ao campo de rotação
document.addEventListener('DOMContentLoaded', () => {
  const rotationStyle = document.querySelector('select[name="rotation_style"]');
  if (rotationStyle) {
    rotationStyle.addEventListener('change', updateRotationFieldsVisibility);
  }
  
  // Chamar ao iniciar
  updateRotationFieldsVisibility();

  // Redesenhar minutagem e atualizar banco quando os selects de titulares mudarem
  for (let i = 1; i <= 5; i++) {
    const sel = document.querySelector(`select[name="starter_${i}_id"]`);
    if (sel) {
      sel.addEventListener('change', () => {
        updateBenchCheckboxesVisibility();
      });
    }
  }
  
  // Redesenhar minutagem quando checkboxes do banco mudarem
  document.querySelectorAll('.bench-player-checkbox').forEach(cb => {
    cb.addEventListener('change', () => {
      // Atualizar contador e minutagem
      const benchCount = document.getElementById('bench-count');
      if (benchCount) {
        const checked = document.querySelectorAll('.bench-player-checkbox:checked').length;
        benchCount.textContent = checked;
      }
      renderPlayerMinutes();
    });
  });
  
  // Inicializar visibilidade dos checkboxes do banco
  updateBenchCheckboxesVisibility();
});

// Carregar diretriz existente
async function loadExistingDirective() {
  const deadlineId = window.__DEADLINE_ID__;
  if (!deadlineId) return;
  
  try {
    const data = await api(`diretrizes.php?action=my_directive&deadline_id=${deadlineId}`);
    if (data.directive) {
      const d = data.directive;
      currentDirective = d;
      
      // Preencher titulares
      for (let i = 1; i <= 5; i++) {
        const select = document.querySelector(`select[name="starter_${i}_id"]`);
        if (select && d[`starter_${i}_id`]) {
          select.value = d[`starter_${i}_id`];
        }
      }
      
      // Coletar IDs dos titulares para excluir do banco
      const starterIds = [];
      for (let i = 1; i <= 5; i++) {
        const sid = parseInt(d[`starter_${i}_id`]);
        if (!isNaN(sid) && sid > 0) starterIds.push(sid);
      }
      
      // Atualizar visibilidade dos checkboxes (esconder titulares)
      updateBenchCheckboxesVisibility();
      
      // Preencher banco via checkboxes (baseado nos player_minutes que não são titulares)
      if (d.player_minutes) {
        Object.keys(d.player_minutes).forEach(playerId => {
          const id = parseInt(playerId);
          if (!starterIds.includes(id)) {
            const checkbox = document.getElementById(`bench_${id}`);
            if (checkbox) checkbox.checked = true;
          }
        });
      }
      
      // Preencher estilos (selects)
      ['pace', 'offensive_rebound', 'offensive_aggression', 'defensive_rebound', 'defensive_focus',
       'rotation_style', 'game_style', 'offense_style', 'rotation_players'].forEach(field => {
        const select = document.querySelector(`select[name="${field}"]`);
        if (select && d[field]) {
          select.value = d[field];
        }
      });
      
      // Preencher slider veteran_focus
      const veteranInput = document.querySelector('input[name="veteran_focus"]');
      if (veteranInput && d.veteran_focus !== undefined) {
        veteranInput.value = d.veteran_focus;
        const valueSpan = document.getElementById('veteran_focus-value');
        if (valueSpan) valueSpan.textContent = d.veteran_focus + '%';
      }
      
      // Preencher G-League
      ['gleague_1_id', 'gleague_2_id'].forEach(field => {
        const select = document.querySelector(`select[name="${field}"]`);
        if (select && d[field]) {
          select.value = d[field];
        }
      });
      
      // Preencher observações
      const notesField = document.querySelector('textarea[name="notes"]');
      if (notesField && d.notes) {
        notesField.value = d.notes;
      }
      
      // Render e preencher minutagem dos jogadores (após selects definidos)
      renderPlayerMinutes();
      if (d.player_minutes && Object.keys(d.player_minutes).length > 0) {
        setTimeout(() => {
          Object.keys(d.player_minutes).forEach(playerId => {
            const input = document.querySelector(`input[name="minutes_player_${playerId}"]`);
            if (input) input.value = d.player_minutes[playerId];
          });
        }, 50);
      }
      
      // Atualizar contador do banco
      const benchCount = document.getElementById('bench-count');
      if (benchCount) {
        const checked = document.querySelectorAll('.bench-player-checkbox:checked').length;
        benchCount.textContent = checked;
      }
      
      // Atualizar visibilidade após carregar dados
      updateRotationFieldsVisibility();
    }
  } catch (err) {
    console.error('Erro ao carregar diretriz:', err);
  }
}

// Enviar diretrizes
document.getElementById('form-diretrizes')?.addEventListener('submit', async (e) => {
  e.preventDefault();
  
  const deadlineId = window.__DEADLINE_ID__;
  if (!deadlineId) {
    alert('Prazo não definido');
    return;
  }
  
  const form = e.target;
  const fd = new FormData(form);
  
  // Validar jogadores únicos - titulares
  const allPlayers = [];
  for (let i = 1; i <= 5; i++) {
    const playerId = fd.get(`starter_${i}_id`);
    if (!playerId) {
      alert(`Selecione o Titular ${i}`);
      return;
    }
    if (allPlayers.includes(playerId)) {
      alert('Não pode selecionar o mesmo jogador mais de uma vez');
      return;
    }
    allPlayers.push(playerId);
  }
  
  // Coletar jogadores do banco via checkboxes
  const benchPlayers = [];
  document.querySelectorAll('.bench-player-checkbox:checked').forEach(cb => {
    const val = cb.value;
    if (val && !allPlayers.includes(val)) {
      benchPlayers.push(parseInt(val));
      allPlayers.push(val);
    }
  });
  
  // Validar G-League (não pode ser titular/banco)
  const gleague1 = fd.get('gleague_1_id');
  const gleague2 = fd.get('gleague_2_id');
  
  if (gleague1 && allPlayers.includes(gleague1)) {
    showValidationError(['Jogador da G-League não pode estar no quinteto titular ou banco']);
    return;
  }
  if (gleague2 && allPlayers.includes(gleague2)) {
    showValidationError(['Jogador da G-League não pode estar no quinteto titular ou banco']);
    return;
  }
  if (gleague1 && gleague2 && gleague1 === gleague2) {
    showValidationError(['Não pode selecionar o mesmo jogador duas vezes para G-League']);
    return;
  }
  
  // VALIDAÇÃO COMPLETA DE MINUTAGEM
  const validationErrors = [];
  const playerMinutes = {};
  let totalMinutes = 0;
  const rotationStyle = fd.get('rotation_style');
  const deadlinePhase = window.__DEADLINE_PHASE__ || 'regular';
  const maxMinutes = deadlinePhase === 'playoffs' ? 45 : 40;
  
  // Coletar todos os jogadores selecionados com seus IDs
  const starters = [];
  for (let i = 1; i <= 5; i++) {
    const starterId = parseInt(fd.get(`starter_${i}_id`));
    if (!isNaN(starterId) && starterId > 0) {
      starters.push(starterId);
    }
  }
  
  // Buscar OVRs dos jogadores selecionados
  const selectedPlayers = [...starters, ...benchPlayers];
  const playersWithOvr = selectedPlayers.map(id => playersById[id]).filter(Boolean);
  
  // Ordenar por OVR decrescente
  playersWithOvr.sort((a, b) => b.ovr - a.ovr);
  
  // Contar jogadores 85+
  const count85Plus = playersWithOvr.filter(p => p.ovr >= 85).length;
  
  // Identificar top 5 OVRs se não tiver 3 jogadores 85+
  const top5Ids = [];
  if (count85Plus < 3) {
    const top5 = playersWithOvr.slice(0, 5);
    top5Ids.push(...top5.map(p => p.id));
  }
  
  // Validar minutagem
  const minutesInputs = document.querySelectorAll('.player-minutes-input');
  
  if (minutesInputs.length === 0 && selectedPlayers.length > 0) {
    validationErrors.push('Erro: Nenhuma minutagem configurada. Atualize a página e tente novamente.');
  }
  
  minutesInputs.forEach(input => {
    const minutes = parseInt(input.value) || 0;
    const playerId = parseInt(input.getAttribute('data-player-id'));
    const playerName = input.getAttribute('data-player-name');
    const player = playersById[playerId];
    
    if (!player) return;
    
    const isStarter = starters.includes(playerId);
    const isTop5 = top5Ids.includes(playerId);
    
    // Regra 1: Titulares precisam de 25+ minutos
    if (isStarter && minutes < 25) {
      validationErrors.push(`⚠️ ${playerName} é TITULAR e deve jogar no mínimo 25 minutos (atual: ${minutes}min)`);
    }
    
    // Regra 2: Reservas precisam de 5+ minutos
    if (!isStarter && minutes < 5) {
      validationErrors.push(`⚠️ ${playerName} é RESERVA e deve jogar no mínimo 5 minutos (atual: ${minutes}min)`);
    }
    
    // Regra 3: Se não tem 3 jogadores 85+, top 5 OVRs precisam de 25+ minutos
    if (isTop5 && minutes < 25) {
      validationErrors.push(`⚠️ ${playerName} está entre os 5 MAIORES OVRs (${player.ovr}) e deve jogar no mínimo 25 minutos (atual: ${minutes}min). Seu time não tem 3 jogadores 85+.`);
    }
    
    // Regra 4: Máximo de minutos
    if (minutes > maxMinutes) {
      validationErrors.push(`⚠️ ${playerName} não pode jogar mais de ${maxMinutes} minutos (atual: ${minutes}min)`);
    }
    
    playerMinutes[playerId] = minutes;
    totalMinutes += minutes;
  });
  
  // Regra 5: Rotação manual deve ter exatamente 240 minutos
  if (rotationStyle === 'manual' && totalMinutes !== 240) {
    validationErrors.push(`⚠️ Rotação MANUAL: A soma dos minutos deve ser exatamente 240. Atual: ${totalMinutes} minutos.`);
  }
  
  // Se houver erros, mostrar modal
  if (validationErrors.length > 0) {
    showValidationError(validationErrors);
    return;
  }
  
  const payload = {
    action: 'submit_directive',
    deadline_id: deadlineId,
    starter_1_id: parseInt(fd.get('starter_1_id')),
    starter_2_id: parseInt(fd.get('starter_2_id')),
    starter_3_id: parseInt(fd.get('starter_3_id')),
    starter_4_id: parseInt(fd.get('starter_4_id')),
    starter_5_id: parseInt(fd.get('starter_5_id')),
    bench_players: benchPlayers,
    pace: fd.get('pace'),
    offensive_rebound: fd.get('offensive_rebound'),
    offensive_aggression: fd.get('offensive_aggression'),
    defensive_rebound: fd.get('defensive_rebound'),
    defensive_focus: fd.get('defensive_focus'),
    rotation_style: fd.get('rotation_style'),
    game_style: fd.get('game_style'),
    offense_style: fd.get('offense_style'),
    rotation_players: parseInt(fd.get('rotation_players')) || 10,
    veteran_focus: parseInt(fd.get('veteran_focus')) || 50,
    gleague_1_id: gleague1 ? parseInt(gleague1) : null,
    gleague_2_id: gleague2 ? parseInt(gleague2) : null,
    notes: fd.get('notes'),
    player_minutes: playerMinutes
  };
  
  try {
    const res = await api('diretrizes.php', { 
      method: 'POST', 
      body: JSON.stringify(payload) 
    });
    alert('Diretrizes enviadas com sucesso!');
    window.location.href = '/dashboard.php';
  } catch (err) {
    // Se erro do backend, mostrar em modal também
    const errorMsg = err.error || 'Erro ao enviar diretrizes';
    showValidationError([errorMsg]);
  }
});

// Função para mostrar modal de erro de validação
function showValidationError(errors) {
  // Criar modal se não existir
  let modal = document.getElementById('validation-error-modal');
  if (!modal) {
    modal = document.createElement('div');
    modal.id = 'validation-error-modal';
    modal.className = 'modal fade';
    modal.innerHTML = `
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content bg-dark border-danger">
          <div class="modal-header border-danger">
            <h5 class="modal-title text-danger">
              <i class="bi bi-exclamation-triangle-fill me-2"></i>Você está fora das regras - Revise!
            </h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <div class="alert alert-danger mb-3">
              <strong>Não é possível enviar as diretrizes.</strong><br>
              Por favor, corrija os problemas abaixo:
            </div>
            <ul class="text-white mb-0" id="validation-error-list"></ul>
          </div>
          <div class="modal-footer border-danger">
            <button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">
              Fechar e Revisar
            </button>
          </div>
        </div>
      </div>
    `;
    document.body.appendChild(modal);
  }
  
  // Preencher lista de erros
  const errorList = document.getElementById('validation-error-list');
  errorList.innerHTML = errors.map(err => `<li class="mb-2">${err}</li>`).join('');
  
  // Mostrar modal
  const bsModal = new bootstrap.Modal(modal);
  bsModal.show();
}

// Carregar diretriz ao iniciar
document.addEventListener('DOMContentLoaded', async () => {
  await loadPlayersData();
  renderPlayerMinutes();
  loadExistingDirective();
});
