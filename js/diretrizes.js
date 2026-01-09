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

// Atualizar valores dos ranges
document.querySelectorAll('input[type="range"]').forEach(range => {
  const valueSpan = document.getElementById(`${range.name}-value`);
  if (valueSpan) {
    range.addEventListener('input', () => {
      valueSpan.textContent = range.value;
    });
  }
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
      
      // Preencher estratégias
      ['pace', 'offensive_rebound', 'offensive_aggression', 'defensive_rebound'].forEach(field => {
        const input = document.querySelector(`input[name="${field}"]`);
        if (input && d[field]) {
          input.value = d[field];
          const valueSpan = document.getElementById(`${field}-value`);
          if (valueSpan) valueSpan.textContent = d[field];
        }
      });
      
      // Preencher estilos
      ['rotation_style', 'game_style', 'offense_style', 'defense_style'].forEach(field => {
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
    pace: parseInt(fd.get('pace')),
    offensive_rebound: parseInt(fd.get('offensive_rebound')),
    offensive_aggression: parseInt(fd.get('offensive_aggression')),
    defensive_rebound: parseInt(fd.get('defensive_rebound')),
    rotation_style: fd.get('rotation_style'),
    game_style: fd.get('game_style'),
    offense_style: fd.get('offense_style'),
    defense_style: fd.get('defense_style'),
    notes: fd.get('notes')
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
loadExistingDirective();
