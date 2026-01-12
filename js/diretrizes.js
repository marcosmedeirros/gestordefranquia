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

// Buscar todos os jogadores do time para renderizar campos de minutagem
async function loadPlayersData() {
  try {
    const data = await api('team-players.php');
    if (data.players) {
      allPlayersData = data.players;
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

  // Agrupar jogadores por posição
  const playersByPosition = {};
  allPlayersData.forEach(player => {
    if (!playersByPosition[player.position]) {
      playersByPosition[player.position] = [];
    }
    playersByPosition[player.position].push(player);
  });

  // Renderizar por posição
  Object.keys(playersByPosition).sort().forEach(position => {
    const positionPlayers = playersByPosition[position];

    const positionDiv = document.createElement('div');
    positionDiv.className = 'col-12 mb-2';
    positionDiv.innerHTML = `
      <h6 class="text-orange mb-2"><i class="bi bi-people me-2"></i>${position}</h6>
    `;
    container.appendChild(positionDiv);

    positionPlayers.forEach(player => {
      const playerDiv = document.createElement('div');
      playerDiv.className = 'col-12';
      playerDiv.innerHTML = `
        <div class="form-group mb-2">
          <div class="d-flex align-items-center justify-content-between gap-3">
            <span class="text-white small">${player.name}</span>
            <div class="input-group input-group-sm" style="max-width: 130px;">
              <input type="number"
                     class="form-control bg-dark text-white border-orange player-minutes-input"
                     name="minutes_player_${player.id}"
                     data-player-id="${player.id}"
                     data-player-name="${player.name}"
                     min="5" max="${maxMinutes}" value="20"
                     placeholder="Minutos">
              <span class="input-group-text bg-dark text-orange border-orange">min</span>
            </div>
          </div>
          <small class="text-light-gray d-block">Min: 5 | Max: ${maxMinutes} (${deadlinePhase === 'playoffs' ? 'playoffs' : 'regular'})</small>
        </div>
      `;
      container.appendChild(playerDiv);
    });
  });
}

// Atualizar visibilidade dos campos de rotação automática
function updateRotationFieldsVisibility() {
  const rotationStyle = document.querySelector('select[name="rotation_style"]');
  const rotationPlayersField = document.getElementById('rotation-players-field');
  const veteranFocusField = document.getElementById('veteran-focus-field');

  if (!rotationStyle) return;

  const isManualRotation = rotationStyle.value === 'manual';

  // Mostrar campos SOMENTE quando rotação for manual; esconder quando automática
  if (rotationPlayersField) {
    rotationPlayersField.style.display = isManualRotation ? 'block' : 'none';
  }
  if (veteranFocusField) {
    veteranFocusField.style.display = isManualRotation ? 'block' : 'none';
  }
  // Minutagem por jogador é sempre exibida
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
});

// Carregar diretriz existente
async function loadExistingDirective() {
  const deadlineId = window.__DEADLINE_ID__;
  if (!deadlineId) return;
  
  try {
    const data = await api(`diretrizes.php?action=my_directive&deadline_id=${deadlineId}`);
    if (data.directive) {
      const d = data.directive;
      
      // Preencher titulares
      for (let i = 1; i <= 5; i++) {
        const select = document.querySelector(`select[name="starter_${i}_id"]`);
        if (select && d[`starter_${i}_id`]) {
          select.value = d[`starter_${i}_id`];
        }
      }
      
      // Preencher banco
      for (let i = 1; i <= 3; i++) {
        const select = document.querySelector(`select[name="bench_${i}_id"]`);
        if (select && d[`bench_${i}_id`]) {
          select.value = d[`bench_${i}_id`];
        }
      }
      
      // Preencher estilos (selects)
      ['pace', 'offensive_rebound', 'offensive_aggression', 'defensive_rebound', 
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
      
      // Preencher minutagem dos jogadores
      if (d.player_minutes && Object.keys(d.player_minutes).length > 0) {
        setTimeout(() => {
          Object.keys(d.player_minutes).forEach(playerId => {
            const input = document.querySelector(`input[name="minutes_player_${playerId}"]`);
            if (input) {
              input.value = d.player_minutes[playerId];
            }
          });
        }, 100); // Aguardar para garantir que os inputs foram renderizados
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
  
  // Validar jogadores únicos
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
  
  for (let i = 1; i <= 3; i++) {
    const playerId = fd.get(`bench_${i}_id`);
    if (!playerId) {
      alert(`Selecione o Banco ${i}`);
      return;
    }
    if (allPlayers.includes(playerId)) {
      alert('Não pode selecionar o mesmo jogador mais de uma vez');
      return;
    }
    allPlayers.push(playerId);
  }
  
  // Validar G-League (não pode ser titular/banco)
  const gleague1 = fd.get('gleague_1_id');
  const gleague2 = fd.get('gleague_2_id');
  
  if (gleague1 && allPlayers.includes(gleague1)) {
    alert('Jogador da G-League não pode estar no quinteto titular ou banco');
    return;
  }
  if (gleague2 && allPlayers.includes(gleague2)) {
    alert('Jogador da G-League não pode estar no quinteto titular ou banco');
    return;
  }
  if (gleague1 && gleague2 && gleague1 === gleague2) {
    alert('Não pode selecionar o mesmo jogador duas vezes para G-League');
    return;
  }
  
  // Validar minutagem por jogador (sempre)
  const playerMinutes = {};
  {
    const deadlinePhase = window.__DEADLINE_PHASE__ || 'regular';
    const maxMinutes = deadlinePhase === 'playoffs' ? 45 : 40;
    const minutesInputs = document.querySelectorAll('.player-minutes-input');
    minutesInputs.forEach(input => {
      const minutes = parseInt(input.value) || 0;
      const playerId = input.getAttribute('data-player-id');
      const playerName = input.getAttribute('data-player-name');
      
      if (minutes < 5 || minutes > maxMinutes) {
        alert(`${playerName}: minutos devem estar entre 5 e ${maxMinutes}`);
        throw new Error('Validação de minutos falhou');
      }
      playerMinutes[playerId] = minutes;
    });
  }
  
  const payload = {
    action: 'submit_directive',
    deadline_id: deadlineId,
    starter_1_id: parseInt(fd.get('starter_1_id')),
    starter_2_id: parseInt(fd.get('starter_2_id')),
    starter_3_id: parseInt(fd.get('starter_3_id')),
    starter_4_id: parseInt(fd.get('starter_4_id')),
    starter_5_id: parseInt(fd.get('starter_5_id')),
    bench_1_id: parseInt(fd.get('bench_1_id')),
    bench_2_id: parseInt(fd.get('bench_2_id')),
    bench_3_id: parseInt(fd.get('bench_3_id')),
    pace: fd.get('pace'),
    offensive_rebound: fd.get('offensive_rebound'),
    offensive_aggression: fd.get('offensive_aggression'),
    defensive_rebound: fd.get('defensive_rebound'),
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
    alert(err.error || 'Erro ao enviar diretrizes');
  }
});

// Carregar diretriz ao iniciar
document.addEventListener('DOMContentLoaded', async () => {
  await loadPlayersData();
  renderPlayerMinutes();
  loadExistingDirective();
});
