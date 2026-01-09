<?php
require_once __DIR__ . '/backend/auth.php';
require_once __DIR__ . '/backend/db.php';
requireAuth();

$user = getUserSession();
$pdo = db();

// Buscar time do usuário
$stmtTeam = $pdo->prepare('SELECT * FROM teams WHERE user_id = ? LIMIT 1');
$stmtTeam->execute([$user['id']]);
$team = $stmtTeam->fetch();

if (!$team) {
    header('Location: /onboarding.php');
    exit;
}

$userLeague = $team['league'];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Histórico - GM FBA</title>
  <link rel="icon" type="image/x-icon" href="/img/favicon.ico">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link rel="stylesheet" href="/css/styles.css" />
</head>
<body>
  <button class="sidebar-toggle" id="sidebarToggle">
    <i class="bi bi-list fs-4"></i>
  </button>
  
  <div class="sidebar-overlay" id="sidebarOverlay"></div>

  <div class="dashboard-sidebar" id="sidebar">
    <div class="text-center mb-4">
      <img src="<?= htmlspecialchars($team['photo_url'] ?? '/img/default-team.png') ?>" alt="<?= htmlspecialchars($team['name']) ?>" class="team-avatar">
      <h5 class="text-white mb-1"><?= htmlspecialchars($team['city']) ?></h5>
      <h6 class="text-white mb-1"><?= htmlspecialchars($team['name']) ?></h6>
      <span class="badge bg-gradient-orange"><?= htmlspecialchars($team['league']) ?></span>
    </div>
    <hr style="border-color: var(--fba-border);">
    <ul class="sidebar-menu">
      <li><a href="/dashboard.php"><i class="bi bi-house-door-fill"></i>Dashboard</a></li>
      <li><a href="/teams.php"><i class="bi bi-people-fill"></i>Times</a></li>
      <li><a href="/my-roster.php"><i class="bi bi-person-fill"></i>Meu Elenco</a></li>
      <li><a href="/picks.php"><i class="bi bi-calendar-check-fill"></i>Picks</a></li>
      <li><a href="/trades.php"><i class="bi bi-arrow-left-right"></i>Trades</a></li>
      <li><a href="/drafts.php"><i class="bi bi-trophy"></i>Draft</a></li>
      <li><a href="/rankings.php"><i class="bi bi-bar-chart-fill"></i>Rankings</a></li>
      <li><a href="/history.php" class="active"><i class="bi bi-clock-history"></i>Histórico</a></li>
      <?php if (($user['user_type'] ?? 'jogador') === 'admin'): ?>
      <li><a href="/admin.php"><i class="bi bi-shield-lock-fill"></i>Admin</a></li>
      <li><a href="/temporadas.php"><i class="bi bi-calendar3"></i>Temporadas</a></li>
      <?php endif; ?>
      <li><a href="/settings.php"><i class="bi bi-gear-fill"></i>Configurações</a></li>
    </ul>
    <hr style="border-color: var(--fba-border);">
    <div class="text-center">
      <a href="/logout.php" class="btn btn-outline-danger btn-sm w-100"><i class="bi bi-box-arrow-right me-2"></i>Sair</a>
    </div>
  </div>

  <div class="dashboard-content">
    <div class="d-flex justify-content-between align-items-center mb-4">
      <h1 class="text-white fw-bold mb-0">
        <i class="bi bi-clock-history me-2 text-orange"></i>
        Histórico de Temporadas - Liga <?= htmlspecialchars($userLeague) ?>
      </h1>
    </div>

    <div id="historyContainer">
      <div class="text-center py-5">
        <div class="spinner-border text-orange"></div>
      </div>
    </div>
  </div>

<div id="debugConsole" style="background: #000; color: #0f0; padding: 20px; margin: 20px; border: 2px solid #f00; font-family: monospace; display: none;">
  <h4 style="color: #fff; border-bottom: 1px solid #333; padding-bottom: 5px;">DEBUG LOG (Envie um print disso)</h4>
  <div id="debugLogs"></div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="/js/sidebar.js"></script>
<script>
  // 1. Configuração do Debugger na Tela
  const debugMode = true;
  const debugConsole = document.getElementById('debugConsole');
  if (debugMode) debugConsole.style.display = 'block';

  function log(msg, data = null) {
    console.log(msg, data || '');
    const logLine = document.createElement('div');
    logLine.style.marginBottom = '5px';
    logLine.style.borderBottom = '1px solid #333';
    
    // Formata o dado se existir
    let dataStr = '';
    if (data) {
      try {
        dataStr = typeof data === 'object' ? JSON.stringify(data, null, 2) : String(data);
      } catch (e) { dataStr = '[Objeto Circular ou Erro ao converter]'; }
    }

    logLine.innerHTML = `<strong style="color: #fff;">[${new Date().toLocaleTimeString()}]</strong> ${msg} <br> <span style="color: #bbb; font-size: 0.9em;">${dataStr}</span>`;
    document.getElementById('debugLogs').appendChild(logLine);
  }

  // --- Início do Script Real ---

  const userLeague = '<?= $userLeague ?>';
  log('1. Script Iniciado. Liga do usuário:', userLeague);

  // Função auxiliar para chamar API com Debug e Timeout
  const api = async (path) => {
    log(`2. Chamando API: /api/${path}`);
    
    // Criar timeout de 15 segundos
    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), 15000);
    
    try {
      const res = await fetch(`/api/${path}`, { 
        headers: { 'Content-Type': 'application/json' },
        signal: controller.signal 
      });
      clearTimeout(timeoutId);
      
      log(`3. Resposta API Status:`, res.status);
      
      if (!res.ok) {
        throw new Error(`HTTP ${res.status}: ${res.statusText}`);
      }
      
      const text = await res.text(); // Pega como texto primeiro pra ver se não é erro de PHP
      log(`4. Resposta Raw (Primeiros 200 chars):`, text.substring(0, 200));

      if (!text) {
        throw new Error('Resposta vazia do servidor');
      }

      try {
        const json = JSON.parse(text);
        return json;
      } catch (errJson) {
        throw new Error(`Erro ao converter JSON. O servidor retornou HTML ou erro? Resp: ${text.substring(0, 100)}...`);
      }
    } catch (err) {
      clearTimeout(timeoutId);
      log('ERRO NA REQUISIÇÃO:', err.message);
      throw err;
    }
  };

  function gerarHtmlPremios(awards) {
    if (!awards || !Array.isArray(awards)) return '';
    // Mapeamento simples
    return awards.map(a => `<div>${a.type}: ${a.player}</div>`).join(''); 
    // Simplifiquei aqui só pra testar o carregamento, depois voltamos o visual
  }

  async function loadHistory() {
    const container = document.getElementById('historyContainer');
    
    try {
      if (!userLeague) throw new Error('A variável userLeague está vazia.');

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

      // Renderização
      log('8. Iniciando renderização do HTML...');
      
      let html = '<div class="row g-4">';
      
      seasons.forEach((s, index) => {
        log(`Renderizando temporada ${index + 1} (ID: ${s.id})...`);
        
        // Proteção contra nulos
        const champName = s.champion ? `${s.champion.city} ${s.champion.name}` : 'N/A';
        const runnerName = s.runner_up ? `${s.runner_up.city} ${s.runner_up.name}` : 'N/A';
        
        // Gerando prêmios
        let awardsHtml = '';
        if (s.awards && s.awards.length > 0) {
           awardsHtml = `<div class="mt-3 border-top pt-2"><small>Prêmios:</small><br>` + 
                        s.awards.map(a => `<span class="badge bg-dark border border-secondary me-1 mb-1">${a.type}: ${a.player}</span>`).join('') +
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

  // Força o carregamento assim que ler o script
  loadHistory();
</script>
</body>
</html>