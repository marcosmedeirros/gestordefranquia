'use strict';

// Debug console toggling
const debugMode = true;
const debugConsole = document.getElementById('debugConsole');
if (debugMode && debugConsole) debugConsole.style.display = 'block';

function log(msg, data = null) {
  try {
    console.log(msg, data || '');
  } catch (_) {}
  if (!debugConsole) return;
  const logLine = document.createElement('div');
  logLine.style.marginBottom = '5px';
  logLine.style.borderBottom = '1px solid #333';
  
  let dataStr = '';
  if (data !== null && data !== undefined) {
    try {
      dataStr = (typeof data === 'object') ? JSON.stringify(data, null, 2) : String(data);
    } catch (e) {
      dataStr = '[Objeto Circular ou Erro ao converter]';
    }
  }

  logLine.innerHTML = `<strong style="color: #fff;">[${new Date().toLocaleTimeString()}]</strong> ${msg} <br> <span style="color: #bbb; font-size: 0.9em;">${dataStr}</span>`;
  const logsEl = document.getElementById('debugLogs');
  if (logsEl) logsEl.appendChild(logLine);
}

// Helper API with timeout and raw logging
async function api(path) {
  log(`2. Chamando API: /api/${path}`);
  const controller = new AbortController();
  const timeoutId = setTimeout(() => controller.abort(), 15000);

  try {
    const res = await fetch(`/api/${path}`, {
      headers: { 'Content-Type': 'application/json' },
      signal: controller.signal
    });
    clearTimeout(timeoutId);
    log('3. Resposta API Status:', res.status);

    if (!res.ok) {
      throw new Error(`HTTP ${res.status}: ${res.statusText}`);
    }

    const text = await res.text();
    log('4. Resposta Raw (Primeiros 200 chars):', text.substring(0, 200));

    if (!text) throw new Error('Resposta vazia do servidor');

    try {
      return JSON.parse(text);
    } catch (errJson) {
      throw new Error(`Erro ao converter JSON. O servidor retornou HTML ou erro? Resp: ${text.substring(0, 100)}...`);
    }
  } catch (err) {
    clearTimeout(timeoutId);
    log('ERRO NA REQUISIÇÃO:', err.message);
    throw err;
  }
}

function gerarHtmlPremios(awards) {
  if (!awards || !Array.isArray(awards)) return '';
  const label = {
    mvp: 'MVP',
    dpoy: 'DPOY',
    mip: 'MIP',
    '6th_man': '6º Homem',
    champion: 'Campeão',
    runner_up: 'Vice'
  };
  return awards.map(a => `<div>${label[a.type] || a.type}: ${a.player}</div>`).join('');
}

async function loadHistory() {
  const container = document.getElementById('historyContainer');
  const userLeague = container?.dataset?.league || '';

  try {
    if (!userLeague) throw new Error('A variável userLeague está vazia.');
    log('1. Script Iniciado. Liga do usuário:', userLeague);

    log('5. Iniciando busca de histórico...');
    const apiUrl = 'seasons.php?action=full_history&league=' + encodeURIComponent(userLeague);
    log('URL da API:', apiUrl);
    const data = await api(apiUrl);

    log('6. JSON recebido com sucesso:', data);

    if (!data || typeof data !== 'object') {
      throw new Error('Resposta inválida: não é um objeto JSON válido');
    }
    if (data.success === false) {
      throw new Error(data.error || 'API retornou success: false');
    }

    const seasons = data.history || [];
    log(`7. Total de temporadas encontradas: ${seasons.length}`);

    if (seasons.length === 0) {
      container.innerHTML = '<div class="alert alert-info">Nenhuma temporada encontrada no histórico (JSON vazio).</div>';
      log('8. Finalizado (Sem dados).');
      return;
    }

    log('8. Iniciando renderização do HTML...');
    let html = '<div class="row g-4">';

    seasons.forEach((s, index) => {
      log(`Renderizando temporada ${index + 1} (ID: ${s.id})...`);
      const champName = (s.champion && s.champion.city && s.champion.name) ? `${s.champion.city} ${s.champion.name}` : 'N/A';
      const runnerName = (s.runner_up && s.runner_up.city && s.runner_up.name) ? `${s.runner_up.city} ${s.runner_up.name}` : 'N/A';

      let awardsHtml = '';
      if (Array.isArray(s.awards) && s.awards.length > 0) {
  const label = { mvp: 'MVP', dpoy: 'DPOY', mip: 'MIP', '6th_man': '6º Homem' };
  awardsHtml = `<div class="mt-3 border-top pt-2"><small>Prêmios:</small><br>` +
         s.awards.map(a => `<span class="badge bg-dark border border-secondary me-1 mb-1">${label[a.type] || a.type}: ${a.player}</span>`).join('') +
                     `</div>`;
      }

      html += `
        <div class="col-md-6 col-lg-4">
          <div class="card bg-dark text-white border-warning mb-3">
            <div class="card-header bg-warning text-dark fw-bold d-flex justify-content-between">
              <span>Temp ${s.number}</span>
              <span>${s.year}</span>
            </div>
            <div class="card-body">
              <p class="mb-1">🏆 <strong>Campeão:</strong> <br>${champName}</p>
              <p class="mb-1">🥈 <strong>Vice:</strong> <br>${runnerName}</p>
              ${awardsHtml}
            </div>
          </div>
        </div>
      `;
    });

    html += '</div>';
    container.innerHTML = html;
    log('9. Renderização CONCLUÍDA com sucesso!');
  } catch (e) {
    console.error(e);
    log('ERRO FATAL NO PROCESSO:', e.message);
    if (container) {
      container.innerHTML = `
        <div class="alert alert-danger">
          <h4>Erro ao Carregar Histórico</h4>
          <p><strong>Erro:</strong> ${e.message}</p>
          <hr>
          <small>Verifique o console do navegador (F12) e o log preto abaixo para detalhes técnicos.</small>
        </div>
      `;
    }
  }
}

// Start
document.addEventListener('DOMContentLoaded', loadHistory);
