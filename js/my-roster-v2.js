// my-roster-v2.js - Tabela + Quinteto Titular
const api = async (path, options = {}) => {
  const res = await fetch(`/api/${path}`, {
    headers: { 'Content-Type': 'application/json' },
    ...options,
  });
  let body = {};
  try { body = await res.json(); } catch {}
  if (!res.ok) throw body;
  return body;
};

function getOvrColor(ovr) {
  if (ovr >= 95) return '#16a34a';
  if (ovr >= 89) return '#22c55e';
  if (ovr >= 84) return '#ca8a04';
  if (ovr >= 79) return '#d97706';
  if (ovr >= 72) return '#ea580c';
  return '#dc2626';
}

function getPlayerPhotoUrl(player) {
  let customPhoto = (player.foto_adicional || '').toString().trim();
  if (customPhoto) {
    customPhoto = customPhoto.replace(/\\/g, '/');
    if (/^data:image\//i.test(customPhoto) || /^https?:\/\//i.test(customPhoto)) {
      return customPhoto;
    }
    return `/${customPhoto.replace(/^\/+/, '')}`;
  }
  return player.nba_player_id
    ? `https://cdn.nba.com/headshots/nba/latest/1040x760/${player.nba_player_id}.png`
    : `https://ui-avatars.com/api/?name=${encodeURIComponent(player.name)}&background=121212&color=f17507&rounded=true&bold=true`;
}

function convertToBase64(file) {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => resolve(reader.result);
    reader.onerror = reject;
    reader.readAsDataURL(file);
  });
}

function normalizeRoleKey(role) {
  const normalized = (role || '').toString().trim().toLowerCase();
  if (normalized === 'titular') return 'Titular';
  if (normalized === 'banco') return 'Banco';
  if (normalized === 'g-league' || normalized === 'gleague' || normalized === 'g league') return 'G-League';
  return 'Outro';
}

const SKILL_GRADE_FIELDS = [
  { key: 'in', label: 'IN' },
  { key: 'mid', label: 'MID' },
  { key: 'pt3', label: '3PT' },
  { key: 'post_d', label: 'POST D' },
  { key: 'per_d', label: 'PER D' },
  { key: 'play', label: 'PLAY' },
  { key: 'reb', label: 'REB' },
  { key: 'athl', label: 'ATHL' },
  { key: 'iq', label: 'IQ' },
  { key: 'pot', label: 'POT' },
];

const SKILL_GRADE_EDIT_FIELDS = [
  { key: 'in', label: 'IN' },
  { key: 'mid', label: 'MID' },
  { key: 'pt3', label: '3PT' },
  { key: 'post_d', label: 'POST D' },
  { key: 'per_d', label: 'PER D' },
  { key: 'play', label: 'PLAY' },
  { key: 'reb', label: 'REB' },
  { key: 'athl', label: 'ATHL' },
  { key: 'iq', label: 'IQ' },
  { key: 'pot', label: 'POT' },
];

const GRADE_OPTIONS = ['-', 'A+', 'A', 'A-', 'B+', 'B', 'B-', 'C+', 'C', 'C-', 'D+', 'D', 'D-', 'F'];

function parseSkillGrades(raw) {
  if (!raw) return {};
  if (typeof raw === 'object') return raw;
  try {
    const parsed = JSON.parse(raw);
    return parsed && typeof parsed === 'object' ? parsed : {};
  } catch {
    return {};
  }
}

function normalizeSkillGrades(player) {
  const grades = parseSkillGrades(player?.player_skill_grades);
  const columnGrades = {
    in: player?.skill_in,
    mid: player?.skill_mid,
    pt3: player?.skill_3pt,
    post_d: player?.skill_post_d,
    per_d: player?.skill_per_d,
    play: player?.skill_play,
    reb: player?.skill_reb,
    athl: player?.skill_athl,
    iq: player?.skill_iq,
    pot: player?.skill_pot,
  };
  const merged = { ...grades };
  Object.entries(columnGrades).forEach(([key, value]) => {
    if (value !== null && value !== undefined && value !== '') {
      merged[key] = value;
    }
  });
  return merged;
}

function buildSkillGradesHtml(grades) {
  return `
    <div class="skill-grades-grid">
      ${SKILL_GRADE_FIELDS.map(field => {
        const value = grades[field.key] || '-';
        return `<div class="skill-grade-item">
          <div class="skill-grade-label">${field.label}</div>
          <div class="skill-grade-value">${value}</div>
        </div>`;
      }).join('')}
    </div>
  `;
}

function buildSkillGradesEditorHtml(grades) {
  return `
    <div class="skill-edit-grid">
      ${SKILL_GRADE_EDIT_FIELDS.map(field => {
        const value = grades[field.key] || '-';
        return `<label style="display:flex;flex-direction:column;gap:6px;font-size:11px;color:var(--text-2);">
          <span style="text-transform:uppercase;letter-spacing:.08em;font-weight:700;">${field.label}</span>
          <select data-skill-key="${field.key}">
            ${GRADE_OPTIONS.map(opt => `<option value="${opt}"${opt === value ? ' selected' : ''}>${opt}</option>`).join('')}
          </select>
        </label>`;
      }).join('')}
    </div>
  `;
}

function collectSkillGradesFromEditor(container, baseGrades) {
  const nextGrades = { ...baseGrades };
  if (!container) return nextGrades;
  container.querySelectorAll('[data-skill-key]').forEach(sel => {
    const key = sel.getAttribute('data-skill-key');
    if (!key) return;
    nextGrades[key] = sel.value;
  });
  return nextGrades;
}

function normalizePlayerName(name) {
  return (name || '')
    .toString()
    .toLowerCase()
    .replace(/\./g, '')
    .replace(/[^a-z0-9 ]+/g, ' ')
    .replace(/\s+/g, ' ')
    .trim();
}

function buildPlayerLookup(players) {
  const lookup = new Map();
  players.forEach(p => {
    const full = normalizePlayerName(p.name);
    if (full) lookup.set(full, p);
    const parts = full.split(' ').filter(Boolean);
    if (parts.length >= 2) {
      const initialLast = `${parts[0][0]} ${parts[parts.length - 1]}`.trim();
      lookup.set(initialLast, p);
    }
  });
  return lookup;
}

function cleanGradeToken(token) {
  const match = String(token || '').toUpperCase().match(/[ABCDF][+-]?/);
  return match ? match[0] : '';
}

function isGradeToken(token) {
  return cleanGradeToken(token) !== '';
}

function extractSkillRowFromTokens(tokens) {
  const gradeTokens = [];
  tokens.forEach((token, idx) => {
    const cleanToken = cleanGradeToken(token);
    if (cleanToken) {
      gradeTokens.push({ idx, token: cleanToken });
    }
  });
  if (gradeTokens.length < 6) return null;
  const lastGrades = gradeTokens.slice(-10);
  const firstGradeIndex = lastGrades[0]?.idx ?? -1;
  if (firstGradeIndex < 0) return null;
  const skillTokens = lastGrades.map(item => item.token);
  while (skillTokens.length < 10) skillTokens.push('-');

  const headerTokens = tokens.slice(0, firstGradeIndex).filter(Boolean);
  const upperHeader = headerTokens.map(t => t.toUpperCase());
  let posIndex = -1;
  ['PG', 'SG', 'SF', 'PF', 'C'].forEach(pos => {
    const found = upperHeader.lastIndexOf(pos);
    if (found > posIndex) posIndex = found;
  });
  if (posIndex < 0) return null;

  const pos = (headerTokens[posIndex] || '').toUpperCase();
  const numericTokens = headerTokens
    .slice(posIndex + 1)
    .map(t => {
      const match = String(t).match(/(\d{2,3})/);
      return match ? match[1] : null;
    })
    .filter(Boolean);
  const age = numericTokens[0] ? parseInt(numericTokens[0], 10) : null;
  const ovr = numericTokens[1] ? parseInt(numericTokens[1], 10) : null;
  const name = headerTokens.slice(0, posIndex).join(' ').trim();
  if (!name) return null;
  return {
    name,
    age,
    ovr,
    grades: {
      in: skillTokens[0],
      mid: skillTokens[1],
      pt3: skillTokens[2],
      post_d: skillTokens[3],
      per_d: skillTokens[4],
      play: skillTokens[5],
      reb: skillTokens[6],
      athl: skillTokens[7],
      iq: skillTokens[8],
      pot: skillTokens[9],
    }
  };
}

function extractSkillRowsFromText(text) {
  const lines = (text || '').split(/\r?\n/).map(l => l.trim()).filter(Boolean);
  const rows = [];
  lines.forEach(line => {
    if (/player name|view list|detailed|tendency|badge|player list/i.test(line)) return;
    const cleanLine = line.replace(/[|]/g, ' ').replace(/[^A-Za-z0-9+\-\. ]+/g, ' ').trim();
    const tokens = cleanLine.split(/\s+/).filter(Boolean);
    if (tokens.length < 10) return;
    const row = extractSkillRowFromTokens(tokens);
    if (row) rows.push(row);
  });
  return rows;
}

const roleOrder = { 'Titular': 0, 'Banco': 1, 'Outro': 2, 'G-League': 3 };
const starterPositionOrder = { PG: 0, SG: 1, SF: 2, PF: 3, C: 4 };

// ── Scout IA ─────────────────────────────────────────────────────────────────
const SCOUT_CONFIG = {
  THRESHOLDS: { STARTER_ELITE: 86, PLAYOFF_CONTENDER: 82, REBUILD_CEILING: 78, FRANCHISE_PLAYER: 89, WEAK_STARTER: 80 },
  AGE: { VETERAN: 33, YOUNG_PROSPECT: 23, YOUNG_TEAM_AVG: 25 }
};

const TAG_META = {
  Contending: { label: '🏆 Contending', color: '#10b981', bg: 'rgba(16,185,129,.12)', desc: 'Time candidato ao título. Janela de contenda aberta.' },
  Buying:     { label: '📈 Buying',     color: '#3b82f6', bg: 'rgba(59,130,246,.12)', desc: 'Time competitivo buscando reforços para disputar o playoff.' },
  Selling:    { label: '📦 Selling',    color: '#f97316', bg: 'rgba(249,115,22,.12)', desc: 'Time em transição — trocando veteranos por futuro.' },
  Rebuilding: { label: '🔧 Rebuilding', color: '#64748b', bg: 'rgba(100,116,139,.12)', desc: 'Time em reconstrução. Foco no desenvolvimento jovem.' },
};

function computeAiTag(players) {
  if (!players || players.length < 5) return null;
  const starters = players.filter(p => normalizeRoleKey(p.role) === 'Titular');
  if (!starters.length) return null;
  const top5 = [...starters].sort((a, b) => Number(b.ovr) - Number(a.ovr)).slice(0, 5);
  const avgOvr = top5.reduce((s, p) => s + Number(p.ovr), 0) / top5.length;
  const maxOvr = Math.max(...top5.map(p => Number(p.ovr)));
  const avgAge = players.reduce((s, p) => s + Number(p.age || 25), 0) / players.length;
  const hasFranchise = starters.some(p => Number(p.ovr) >= SCOUT_CONFIG.THRESHOLDS.FRANCHISE_PLAYER);

  if (avgOvr >= SCOUT_CONFIG.THRESHOLDS.STARTER_ELITE && hasFranchise) return 'Contending';
  if (avgOvr >= SCOUT_CONFIG.THRESHOLDS.PLAYOFF_CONTENDER) return 'Buying';
  if (avgAge >= SCOUT_CONFIG.AGE.VETERAN - 2 || avgOvr < SCOUT_CONFIG.THRESHOLDS.REBUILD_CEILING) return 'Rebuilding';
  return 'Selling';
}

function renderPlayerTagBadge(p) {
  if (!p.player_tag) return '';
  const color = p.player_tag_color || '#3b82f6';
  return `<span style="display:inline-flex;align-items:center;padding:1px 7px;border-radius:999px;font-size:10px;font-weight:700;border:1px solid ${color}55;background:${color}18;color:${color};margin-left:4px;white-space:nowrap;">${p.player_tag}</span>`;
}

function renderTeamTag(tag) {
  const bar = document.getElementById('franchise-tag-bar');
  if (!bar) return;
  if (!tag || !TAG_META[tag]) { bar.style.display = 'none'; return; }
  const m = TAG_META[tag];
  bar.style.display = 'block';
  bar.innerHTML = `
    <div style="display:inline-flex;align-items:center;gap:10px;background:${m.bg};border:1px solid ${m.color}44;border-radius:10px;padding:10px 16px;">
      <span style="font-size:15px;font-weight:800;color:${m.color}">${m.label}</span>
      <span style="font-size:12px;color:var(--text-2)">${m.desc}</span>
      <span style="font-size:10px;color:var(--text-3);margin-left:4px;">· IA</span>
    </div>`;
}

async function checkAndApplyAiTag() {
  const teamId = window.__TEAM_ID__;
  if (!teamId || allPlayers.length < 5) return;

  const aiTag = computeAiTag(allPlayers);
  if (!aiTag) return;

  const currentSeason = window.__CURRENT_SEASON__ || 1;
  const lastAiSeason = window.__TEAM_TAG_AI_SEASON__;
  const shouldApply = lastAiSeason == null || (currentSeason - lastAiSeason) >= 2;

  if (shouldApply) {
    try {
      await fetch('/api/team.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'save_ai_tag', tag: aiTag, season: currentSeason }),
      });
      window.__TEAM_TAG__ = aiTag;
      window.__TEAM_TAG_AI_SEASON__ = currentSeason;
    } catch (e) {}
  }

  renderTeamTag(window.__TEAM_TAG__ || aiTag);
}

let allPlayers = [];
let currentSort = { field: 'role', ascending: true };
let currentSearch = '';
let currentRoleFilter = '';
let editPhotoFile = null;
let pendingWaivePlayerId = null;
let pendingSkillUpdates = [];

const DEFAULT_FA_LIMITS = { waiversUsed: 0, waiversMax: 3, signingsUsed: 0, signingsMax: 3 };
let currentFALimits = { ...DEFAULT_FA_LIMITS };

// --- NBA REAL SPOILER (balldontlie.io) ---
const BDL_API_KEY = ''; // ← Cole aqui sua chave gratuita de api.balldontlie.io
const BDL_BASE_URL = 'https://api.balldontlie.io/nba/v1';

async function getRealSpoiler(playerName) {
  if (!BDL_API_KEY || !playerName || !playerName.trim()) return null;

  const cacheKey = `nba_spoiler_${playerName.toLowerCase().replace(/[^a-z0-9]/g, '')}`;
  try {
    const cached = localStorage.getItem(cacheKey);
    if (cached) {
      const { spoiler, timestamp } = JSON.parse(cached);
      if (Date.now() - timestamp < 30 * 24 * 60 * 60 * 1000) return spoiler;
    }
  } catch (_) {}

  try {
    const searchRes = await fetch(
      `${BDL_BASE_URL}/players?search=${encodeURIComponent(playerName)}&per_page=1`,
      { headers: { 'Authorization': BDL_API_KEY } }
    );
    if (!searchRes.ok) return null;
    const searchData = await searchRes.json();
    if (!searchData.data || !searchData.data.length) return null;
    const p = searchData.data[0];

    let careerPPG = '—', careerRPG = '—', careerAPG = '—';
    let gamesPlayed = 0, seasonsPlayed = 0, hasFullStats = false;

    try {
      const statsRes = await fetch(
        `${BDL_BASE_URL}/stats?player_ids[]=${p.id}&per_page=100`,
        { headers: { 'Authorization': BDL_API_KEY } }
      );
      if (statsRes.ok) {
        const statsData = await statsRes.json();
        const games = statsData.data || [];
        let totalPts = 0, totalReb = 0, totalAst = 0;
        const seasonSet = new Set();
        games.forEach(g => {
          totalPts += Number(g.pts || 0);
          totalReb += Number(g.reb || 0);
          totalAst += Number(g.ast || 0);
          if (g.season) seasonSet.add(g.season);
        });
        gamesPlayed = games.length;
        seasonsPlayed = seasonSet.size;
        if (gamesPlayed > 0) {
          careerPPG = (totalPts / gamesPlayed).toFixed(1);
          careerRPG = (totalReb / gamesPlayed).toFixed(1);
          careerAPG = (totalAst / gamesPlayed).toFixed(1);
          hasFullStats = true;
        }
      }
    } catch (_) {}

    const ppgNum = parseFloat(careerPPG);
    let note;
    if (!hasFullStats) {
      note = `Draft ${p.draft_year || '—'}, R${p.draft_round || '—'}, Pick ${p.draft_number || '—'}. Sem estatísticas completas disponíveis ainda.`;
    } else if (gamesPlayed === 0) {
      note = `Sem dados de carreira NBA ainda. Provável rookie — monitore o desenvolvimento.`;
    } else if (seasonsPlayed < 3 || ppgNum < 8) {
      note = `Pode ser um bom role player, mas não comprometa Cap Space com ele.<br><small style="color:var(--text-3)">${careerPPG} PPG · ${careerRPG} RPG · ${careerAPG} APG — ${seasonsPlayed} temp. (${gamesPlayed} jogos)</small>`;
    } else if (ppgNum >= 15) {
      note = `Potencial histórico de All-Star. Lapide forte!<br><small style="color:var(--text-3)">${careerPPG} PPG · ${careerRPG} RPG · ${careerAPG} APG — ${seasonsPlayed} temp. (${gamesPlayed} jogos)</small>`;
    } else if (ppgNum >= 10) {
      note = `Sólido potencial como starter. Vale lapidar com moderação.<br><small style="color:var(--text-3)">${careerPPG} PPG · ${careerRPG} RPG · ${careerAPG} APG — ${seasonsPlayed} temp. (${gamesPlayed} jogos)</small>`;
    } else {
      note = `Role player decente com upside. Avalie com cuidado.<br><small style="color:var(--text-3)">${careerPPG} PPG · ${careerRPG} RPG · ${careerAPG} APG — ${seasonsPlayed} temp. (${gamesPlayed} jogos)</small>`;
    }

    const spoiler = {
      realName: `${p.first_name} ${p.last_name}`,
      draftYear: p.draft_year || '—',
      note
    };

    try {
      localStorage.setItem(cacheKey, JSON.stringify({ spoiler, timestamp: Date.now() }));
    } catch (_) {}

    return spoiler;
  } catch (e) {
    console.warn('[BDL] Erro ao buscar spoiler:', e);
    return null;
  }
}

// --- LOGICA DA IA DE MELHORIAS ---
async function generateAIAnalysis() {
  if (allPlayers.length === 0) {
    alert('Voce precisa ter jogadores no elenco para a IA analisar!');
    return;
  }

  const aiModalEl = document.getElementById('aiAnalysisModal');
  if (!aiModalEl) return;
  const aiModal = new bootstrap.Modal(aiModalEl);

  const loadingEl = document.getElementById('ai-loading');
  const resultsEl = document.getElementById('ai-results');
  if (loadingEl) loadingEl.style.display = 'block';
  if (resultsEl) resultsEl.style.display = 'none';

  aiModal.show();

  await new Promise(r => setTimeout(r, 1200));

  const strengths = [];
  const weaknesses = [];

  const starters = allPlayers.filter(p => normalizeRoleKey(p.role) === 'Titular');

  const positionCounts = { PG: 0, SG: 0, SF: 0, PF: 0, C: 0 };
  allPlayers.forEach(p => {
    if (positionCounts[p.position] !== undefined) positionCounts[p.position]++;
  });

  const missingPositions = [];
  const overloadedPositions = [];

  Object.entries(positionCounts).forEach(([pos, count]) => {
    if (count < 2) missingPositions.push(pos);
    else if (count > 4) overloadedPositions.push(pos);
  });

  if (missingPositions.length > 0) {
    weaknesses.push(`<strong>Garrafao ou Perimetro Desfalcado:</strong> Falta profundidade nas posicoes <b>${missingPositions.join(', ')}</b> (menos de 2). Busque reforcos.`);
  }
  if (overloadedPositions.length > 0) {
    weaknesses.push(`<strong>Congestionamento:</strong> Excesso de jogadores nas posicoes <b>${overloadedPositions.join(', ')}</b>. Considere usar alguns como moeda de troca.`);
  }
  if (missingPositions.length === 0 && overloadedPositions.length === 0 && allPlayers.length >= 10) {
    strengths.push('<strong>Rotacao Equilibrada:</strong> Seu elenco tem excelente profundidade tatica nas 5 posicoes da quadra.');
  }

  const bestPlayer = [...allPlayers].sort((a, b) => Number(b.ovr) - Number(a.ovr))[0];
  if (bestPlayer && Number(bestPlayer.ovr) >= 89) {
    strengths.push(`<strong>Estrela da Franquia:</strong> ${bestPlayer.name} (${bestPlayer.ovr} OVR) e um jogador de elite para carregar a equipe.`);
  } else if (bestPlayer) {
    weaknesses.push(`<strong>Falta de um Astro:</strong> Seu melhor jogador e ${bestPlayer.name} (${bestPlayer.ovr} OVR). O time precisa de um Franchise Player (89+).`);
  }

  if (starters.length > 0) {
    const weakStarter = [...starters].sort((a, b) => Number(a.ovr) - Number(b.ovr))[0];
    if (weakStarter && Number(weakStarter.ovr) < 80) {
      weaknesses.push(`<strong>Ponto Fraco no Quinteto:</strong> A posicao ${weakStarter.position} com ${weakStarter.name} (${weakStarter.ovr} OVR) e o elo mais fraco dos titulares.`);
    } else {
      strengths.push('<strong>Quinteto Solido:</strong> Todos os seus titulares tem 80+ de OVR. A fundacao do time e muito forte!');
    }
  } else {
    weaknesses.push('<strong>Rotacao Indefinida:</strong> Voce nao definiu seus titulares corretamente.');
  }

  const agingPlayers = allPlayers.filter(p => Number(p.age) >= 33);
  const youngTalents = allPlayers.filter(p => Number(p.age) <= 23 && Number(p.ovr) >= 79);

  if (agingPlayers.length >= 3) {
    weaknesses.push(`<strong>Elenco Envelhecido:</strong> Voce tem ${agingPlayers.length} jogadores com 33+ anos. Cuidado com a queda drastica de OVR na proxima temporada.`);
  } else if (agingPlayers.length > 0) {
    const bestVet = [...agingPlayers].sort((a, b) => Number(b.ovr) - Number(a.ovr))[0];
    if (bestVet) {
      weaknesses.push(`<strong>Risco de Regressao:</strong> Fique de olho em veteranos como ${bestVet.name} (${bestVet.age} anos). Eles tendem a perder atributos.`);
    }
  }

  // Spoilers NBA reais para jogadores < 25 anos (sequencial com delay para evitar rate limit)
  if (BDL_API_KEY) {
    const youngForSpoiler = allPlayers.filter(p => Number(p.age) < 25);
    for (const talent of youngForSpoiler) {
      const spoiler = await getRealSpoiler(talent.name);
      if (spoiler) {
        strengths.push(`<strong>🔮 Spoiler NBA — ${talent.name} (${talent.age} anos):</strong> ${spoiler.note}`);
      }
      await new Promise(r => setTimeout(r, 500));
    }
  }

  if (youngTalents.length > 0) {
    const topYoung = youngTalents[0];
    strengths.push(`<strong>Futuro Garantido:</strong> ${topYoung.name} (${topYoung.age} anos, ${topYoung.ovr} OVR) tem enorme potencial de evolucao.`);
  }

  const strengthsHtml = strengths.length > 0
    ? strengths.map(s => `<li class="mb-2">${s}</li>`).join('')
    : '<li>Nenhum destaque claro encontrado.</li>';
  const weaknessesHtml = weaknesses.length > 0
    ? weaknesses.map(w => `<li class="mb-2">${w}</li>`).join('')
    : '<li>Seu time esta perfeito!</li>';

  const strengthsEl = document.getElementById('ai-strengths');
  const weaknessesEl = document.getElementById('ai-weaknesses');
  if (strengthsEl) strengthsEl.innerHTML = strengthsHtml;
  if (weaknessesEl) weaknessesEl.innerHTML = weaknessesHtml;

  // Card de status da franquia
  const aiTag = computeAiTag(allPlayers);
  const statusContainer = document.getElementById('ai-status-container');
  if (statusContainer && aiTag && TAG_META[aiTag]) {
    const m = TAG_META[aiTag];
    const starters2 = allPlayers.filter(p => normalizeRoleKey(p.role) === 'Titular');
    const top5 = [...starters2].sort((a,b)=>Number(b.ovr)-Number(a.ovr)).slice(0,5);
    const avgOvr = top5.length ? (top5.reduce((s,p)=>s+Number(p.ovr),0)/top5.length).toFixed(1) : '—';
    const avgAge = (allPlayers.reduce((s,p)=>s+Number(p.age||25),0)/allPlayers.length).toFixed(1);
    statusContainer.innerHTML = `
      <div style="background:${m.bg};border:1px solid ${m.color}55;border-radius:12px;padding:16px 20px;display:flex;align-items:center;gap:16px;flex-wrap:wrap;">
        <div style="flex:1;min-width:180px;">
          <div style="font-size:11px;font-weight:700;letter-spacing:.8px;text-transform:uppercase;color:var(--text-3);margin-bottom:4px;">Status da Franquia · IA</div>
          <div style="font-size:20px;font-weight:800;color:${m.color}">${m.label}</div>
          <div style="font-size:12px;color:var(--text-2);margin-top:3px;">${m.desc}</div>
        </div>
        <div style="display:flex;gap:20px;flex-shrink:0;">
          <div style="text-align:center;">
            <div style="font-size:18px;font-weight:800;color:${m.color}">${avgOvr}</div>
            <div style="font-size:10px;color:var(--text-3);text-transform:uppercase;letter-spacing:.5px">OVR Médio</div>
          </div>
          <div style="text-align:center;">
            <div style="font-size:18px;font-weight:800;color:var(--text)">${avgAge}</div>
            <div style="font-size:10px;color:var(--text-3);text-transform:uppercase;letter-spacing:.5px">Idade Média</div>
          </div>
        </div>
      </div>`;
  }

  if (loadingEl) loadingEl.style.display = 'none';
  if (resultsEl) resultsEl.style.display = 'block';
}

async function loadFreeAgencyLimits() {
  if (!window.__TEAM_ID__) return;
  try {
    const data = await api('free-agency.php?action=limits');
    currentFALimits = {
      waiversUsed: Number.isFinite(data.waivers_used) ? data.waivers_used : 0,
      waiversMax: Number.isFinite(data.waivers_max) && data.waivers_max > 0 ? data.waivers_max : DEFAULT_FA_LIMITS.waiversMax,
      signingsUsed: Number.isFinite(data.signings_used) ? data.signings_used : 0,
      signingsMax: Number.isFinite(data.signings_max) && data.signings_max > 0 ? data.signings_max : DEFAULT_FA_LIMITS.signingsMax,
    };
  } catch (err) {
    console.warn('Não foi possível carregar limites de FA:', err);
    currentFALimits = { ...DEFAULT_FA_LIMITS };
  }
  updateFreeAgencyCounters();
}

function updateFreeAgencyCounters() {
  const waiversEl = document.getElementById('waivers-count');
  const signingsEl = document.getElementById('signings-count');
  if (waiversEl) {
    waiversEl.textContent = `${currentFALimits.waiversUsed} / ${currentFALimits.waiversMax}`;
    waiversEl.classList.toggle('text-danger', currentFALimits.waiversMax && currentFALimits.waiversUsed >= currentFALimits.waiversMax);
  }
  if (signingsEl) {
    signingsEl.textContent = `${currentFALimits.signingsUsed} / ${currentFALimits.signingsMax}`;
    signingsEl.classList.toggle('text-danger', currentFALimits.signingsMax && currentFALimits.signingsUsed >= currentFALimits.signingsMax);
  }
}

function calculateCapTop8(players) {
  return players
    .slice()
    .sort((a, b) => Number(b.ovr) - Number(a.ovr))
    .slice(0, 8)
    .reduce((sum, p) => sum + Number(p.ovr), 0);
}

function isLoyalPlayer(player) {
  return window.__LEAGUE__ === 'RISE' && Number(player?.was_traded ?? 1) === 0;
}

function isFranchiseEligible(player) {
  if (window.__LEAGUE__ !== 'RISE') return false;
  return Number(player.cap_bonus_eligible) === 1;
}

function getRestrictedBonus(players) {
  return players.filter(isFranchiseEligible).length * 2;
}

function getCapMaxAdjusted(players) {
  const capMax = Number(window.__CAP_MAX__);
  if (!Number.isFinite(capMax)) return capMax;
  return capMax + getRestrictedBonus(players);
}

function getCapAfterRemoval(playerId) {
  const remaining = allPlayers.filter((p) => String(p.id) !== String(playerId));
  return calculateCapTop8(remaining);
}

function getCapStatusText(newCap, playersForBonus = allPlayers) {
  const capMin = Number(window.__CAP_MIN__);
  const capMax = getCapMaxAdjusted(playersForBonus);
  if (Number.isFinite(capMin) && Number.isFinite(capMax)) {
    if (newCap < capMin) return 'Voce vai ficar abaixo do cap.';
    if (newCap > capMax) return 'Voce vai ficar acima do cap.';
  }
  return 'Voce vai ficar dentro do cap.';
}

function openWaiveModal(player) {
  if (!player) return;
  pendingWaivePlayerId = player.id;
  const nameEl = document.getElementById('waive-player-name');
  const capEl = document.getElementById('waive-player-cap');
  const statusEl = document.getElementById('waive-cap-status');
  if (nameEl) nameEl.textContent = player.name || 'jogador';
  const newCap = getCapAfterRemoval(player.id);
  if (capEl) capEl.textContent = newCap;
  const remaining = allPlayers.filter((p) => String(p.id) !== String(player.id));
  if (statusEl) statusEl.textContent = getCapStatusText(newCap, remaining);
  const modalEl = document.getElementById('waivePlayerModal');
  if (modalEl) {
    new bootstrap.Modal(modalEl).show();
  }
}

async function performWaivePlayer(playerId) {
  if (!playerId) return;
  try {
    const res = await api('players.php', { method: 'DELETE', body: JSON.stringify({ id: playerId }) });
    alert(res.message || 'Jogador dispensado e enviado para a Free Agency!');
    loadPlayers();
    loadFreeAgencyLimits();
  } catch (err) {
    alert('Erro: ' + (err.error || 'Desconhecido'));
  }
}

function applyFilters(players) {
  const term = currentSearch.trim().toLowerCase();
  const roleFilter = currentRoleFilter;
  return players.filter(p => {
    const roleOk = !roleFilter || normalizeRoleKey(p.role) === normalizeRoleKey(roleFilter);
    if (!term) return roleOk;
    const hay = `${p.name} ${p.position} ${p.secondary_position || ''}`.toLowerCase();
    return roleOk && hay.includes(term);
  });
}

function sortPlayers(field) {
  if (currentSort.field === field) {
    currentSort.ascending = !currentSort.ascending;
  } else {
    currentSort.field = field;
    currentSort.ascending = field !== 'role';
  }
  renderPlayers(allPlayers);
}

function renderPlayers(players) {
  let sorted = applyFilters([...players]);
  sorted.sort((a, b) => {
    let aVal = a[currentSort.field];
    let bVal = b[currentSort.field];

    if (currentSort.field === 'role') {
      aVal = roleOrder[aVal] ?? 999;
      bVal = roleOrder[bVal] ?? 999;
    }
    if (currentSort.field === 'trade') {
      aVal = a.available_for_trade ? 1 : 0;
      bVal = b.available_for_trade ? 1 : 0;
    }
    if (['ovr', 'age', 'seasons_in_league'].includes(currentSort.field)) {
      aVal = Number(aVal);
      bVal = Number(bVal);
    }

    if (aVal < bVal) return currentSort.ascending ? -1 : 1;
    if (aVal > bVal) return currentSort.ascending ? 1 : -1;

    // Em caso de empate por função, ordenar por posição de armador a pivô
    if (currentSort.field === 'role' && a.role === 'Titular' && b.role === 'Titular') {
      const aPos = starterPositionOrder[a.position] ?? 999;
      const bPos = starterPositionOrder[b.position] ?? 999;
      if (aPos !== bPos) {
        return currentSort.ascending ? aPos - bPos : bPos - aPos;
      }
    }
    return 0;
  });

  // Renderizar Quinteto Titular (grid) + Banco (lista lateral)
  const grid = document.getElementById('players-grid');
  if (grid) {
    grid.innerHTML = '';
    const titulares = sorted.filter(p => normalizeRoleKey(p.role) === 'Titular');
    titulares.sort((a, b) => {
      const pa = starterPositionOrder[a.position] ?? 999;
      const pb = starterPositionOrder[b.position] ?? 999;
      if (pa !== pb) return pa - pb;
      return Number(b.ovr) - Number(a.ovr);
    });
    const starters = titulares.slice(0, 5);
    const bench = sorted
      .filter(p => normalizeRoleKey(p.role) === 'Banco')
      .sort((a, b) => Number(b.ovr) - Number(a.ovr));

    const row = document.createElement('div');
    row.className = 'row g-3';

    const colLeft = document.createElement('div');
    colLeft.className = 'col-12 col-lg-8';
    const startersSection = document.createElement('div');
    startersSection.className = 'roster-section';
    startersSection.innerHTML = '<h5>Quinteto Titular</h5>';
    if (starters.length === 0) {
      startersSection.innerHTML += '<div class="text-center text-light-gray">Sem jogadores marcados como Titular.</div>';
    } else {
      const list = document.createElement('div');
      list.className = 'row g-3';
      starters.forEach(p => {
        const ovrColor = getOvrColor(p.ovr);
        const photoUrl = getPlayerPhotoUrl(p);
        const loyalBadge = isLoyalPlayer(p) ? '<span class="badge loyal-badge">Leal</span>' : '';
        const tagBadgeStarter = renderPlayerTagBadge(p);
        const col = document.createElement('div');
        col.className = 'col-12 col-sm-6 col-md-4';
        const card = document.createElement('div');
        const isFE = isFranchiseEligible(p);
        card.className = 'card border-orange h-100 roster-card text-center' + (isFE ? ' franchise-player-card' : '');
        card.innerHTML = `
          <div class="card-body p-3 d-flex flex-column gap-3 align-items-center">
            <img src="${photoUrl}" alt="${p.name}" style="width: 72px; height: 72px; object-fit: cover; border-radius: 50%; border: 2px solid var(--fba-orange); background: #1a1a1a;" onerror="this.src='https://ui-avatars.com/api/?name=${encodeURIComponent(p.name)}&background=121212&color=f17507&rounded=true&bold=true'">
            <div class="text-center">
              <h6 class="mb-1 fw-bold" style="font-size: 1.05rem; color:var(--text);">${p.name}</h6>
              <div class="d-flex justify-content-center gap-2 flex-wrap small">
                <span class="badge bg-secondary">${p.position}${p.secondary_position ? '/' + p.secondary_position : ''}</span>
                ${loyalBadge}${tagBadgeStarter}
              </div>
            </div>
            <div class="text-center">
              <div class="fw-bold" style="font-size: 1.8rem; line-height: 1; color: ${ovrColor};">${p.ovr}</div>
              <small class="text-light-gray">${p.age} anos</small>
            </div>
          </div>`;
        col.appendChild(card);
        list.appendChild(col);
      });
      startersSection.appendChild(list);
    }
    colLeft.appendChild(startersSection);

    const colRight = document.createElement('div');
    colRight.className = 'col-12 col-lg-4';
    const benchSection = document.createElement('div');
    benchSection.className = 'roster-section';
    benchSection.innerHTML = '<h5>Banco</h5>';
    if (bench.length === 0) {
      benchSection.innerHTML += '<div class="text-center text-light-gray">Sem jogadores no banco.</div>';
    } else {
      const ul = document.createElement('ul');
      ul.className = 'list-group list-group-flush';
      bench.forEach(p => {
        const loyalBadge = isLoyalPlayer(p) ? '<span class="badge loyal-badge ms-1">Leal</span>' : '';
        const franchiseBadge = isFranchiseEligible(p) ? '<span class="badge franchise-badge ms-1">🏆 Franquia</span>' : '';
        const tagBadgeBench = renderPlayerTagBadge(p);
        const li = document.createElement('li');
        li.className = 'list-group-item bg-transparent text-white d-flex justify-content-between align-items-center px-0'
          + (isFranchiseEligible(p) ? ' franchise-player-li' : '');
        li.innerHTML = `
          <span>${p.name} ${loyalBadge}${franchiseBadge}${tagBadgeBench} <small class="text-light-gray">(${p.position}${p.secondary_position ? '/' + p.secondary_position : ''})</small></span>
          <span class=\"fw-bold\" style=\"color:${getOvrColor(p.ovr)}\">${p.ovr}</span>`;
        ul.appendChild(li);
      });
      benchSection.appendChild(ul);
    }
    colRight.appendChild(benchSection);

    row.appendChild(colLeft);
    row.appendChild(colRight);
    grid.appendChild(row);

    document.getElementById('players-status').style.display = 'none';
    grid.style.display = '';
  }

  renderPlayersMobileCards(sorted);

  const statusEl = document.getElementById('players-status');
  if (statusEl) {
    statusEl.style.display = 'none';
  }

  updateRosterStats();
  try {
    renderPlayersTable(sorted);
  } catch (e) {
    console.warn('Falha ao renderizar tabela:', e);
  }
}

function renderPlayersMobileCards(players) {
  const container = document.getElementById('players-mobile-cards');
  if (!container) return;
  container.innerHTML = '';
  container.style.display = '';
  if (!players || players.length === 0) {
    container.innerHTML = '<div class="text-center text-light-gray">Nenhum jogador encontrado.</div>';
    return;
  }

  players.forEach(p => {
    const canRetire = Number(p.age) >= 35;
    const photoUrl = getPlayerPhotoUrl(p);
    const loyalBadge = isLoyalPlayer(p) ? '<span class="badge loyal-badge">Leal</span>' : '';
    const franchiseBadge = isFranchiseEligible(p) ? '<span class="badge franchise-badge">🏆 Franquia</span>' : '';
    const tagBadgeMobile = renderPlayerTagBadge(p);
    const card = document.createElement('div');
    card.className = 'roster-mobile-card' + (isFranchiseEligible(p) ? ' franchise-player-card' : '');
    card.innerHTML = `
      <div class="d-flex justify-content-between align-items-start gap-2">
        <div class="d-flex align-items-center gap-2">
          <img src="${photoUrl}" alt="${p.name}"
               style="width: 44px; height: 44px; object-fit: cover; border-radius: 50%; border: 1px solid var(--fba-orange); background: #1a1a1a;"
               onerror="this.src='https://ui-avatars.com/api/?name=${encodeURIComponent(p.name)}&background=121212&color=f17507&rounded=true&bold=true'">
          <div>
            <div class="fw-bold" style="color:var(--text);">${p.name} ${loyalBadge}${franchiseBadge}${tagBadgeMobile}</div>
            <div class="text-light-gray small">${p.position}${p.secondary_position ? '/' + p.secondary_position : ''} • ${normalizeRoleKey(p.role)}</div>
          </div>
        </div>
        <div class="text-end">
          <div class="fw-bold" style="color:${getOvrColor(p.ovr)}; font-size: 1.2rem;">${p.ovr}${(p.ovr_delta > 0) ? `<span style="font-size:10px;color:#22c55e;font-weight:700;margin-left:4px">+${p.ovr_delta}</span>` : (p.ovr_delta < 0) ? `<span style="font-size:10px;color:#ef4444;font-weight:700;margin-left:4px">${p.ovr_delta}</span>` : ''}</div>
          <small class="text-light-gray">${p.age} anos</small>
        </div>
      </div>
      <div class="mt-2">
        ${p.available_for_trade ? '<span class="badge bg-success">Disponível</span>' : '<span class="badge bg-secondary">Indisp.</span>'}
      </div>
      <div class="roster-mobile-actions mt-3">
        <button class="btn btn-outline-info btn-sm btn-details-player" data-id="${p.id}" title="Detalhes"><i class="bi bi-info-circle"></i></button>
        <button class="btn btn-outline-secondary btn-sm btn-copy-player" data-copy-name="${p.name}" data-copy-ovr="${p.ovr}" data-copy-age="${p.age}" title="Copiar"><i class="bi bi-clipboard"></i></button>
        <button class="btn btn-outline-light btn-sm btn-edit-player" data-id="${p.id}" title="Editar"><i class="bi bi-pencil"></i></button>
        <button class="btn btn-outline-warning btn-sm btn-waive-player" data-id="${p.id}" data-name="${p.name}" title="Dispensar"><i class="bi bi-hand-thumbs-down"></i></button>
        ${canRetire ? `<button class="btn btn-outline-danger btn-sm btn-retire-player" data-id="${p.id}" data-name="${p.name}" title="Aposentar"><i class="bi bi-box-arrow-right"></i></button>` : ''}
        <button class="btn btn-sm ${p.available_for_trade ? 'btn-outline-success' : 'btn-outline-danger'} btn-toggle-trade" data-id="${p.id}" data-trade="${p.available_for_trade}" title="Disponibilidade para Troca">
          <i class="bi ${p.available_for_trade ? 'bi-check-circle' : 'bi-x-circle'}"></i>
        </button>
      </div>
    `;
    container.appendChild(card);
  });
}

function renderPlayersTable(players) {
  const wrapper = document.getElementById('players-table-wrapper');
  const tbody = document.getElementById('players-table-body');
  if (!wrapper || !tbody) return;
  tbody.innerHTML = '';
  if (!players || players.length === 0) {
    tbody.innerHTML = '<tr><td colspan="7" class="text-center text-light-gray">Nenhum jogador encontrado.</td></tr>';
    wrapper.style.display = '';
    return;
  }
  players.forEach(p => {
    const canRetire = Number(p.age) >= 35;
    const photoUrl = getPlayerPhotoUrl(p);
    const loyalBadge = isLoyalPlayer(p) ? '<span class="badge loyal-badge ms-1">Leal</span>' : '';
    const franchiseBadge = isFranchiseEligible(p) ? '<span class="badge franchise-badge ms-1">🏆 Franquia</span>' : '';
    const tagBadge = renderPlayerTagBadge(p);
    const tr = document.createElement('tr');
    if (isFranchiseEligible(p)) tr.classList.add('franchise-player-row');
    tr.innerHTML = `
      <td>
        <div class="d-flex align-items-center gap-2">
          <img src="${photoUrl}" alt="${p.name}"
               style="width: 36px; height: 36px; object-fit: cover; border-radius: 50%; border: 1px solid var(--fba-orange); background: #1a1a1a;"
               onerror="this.src='https://ui-avatars.com/api/?name=${encodeURIComponent(p.name)}&background=121212&color=f17507&rounded=true&bold=true'">
          <div class="d-flex flex-column">
            <span class="fw-semibold">${p.name} ${loyalBadge}${franchiseBadge}${tagBadge}</span>
            <small class="text-light-gray">${p.position}${p.secondary_position ? '/' + p.secondary_position : ''}</small>
          </div>
        </div>
      </td>
      <td>${p.position}${p.secondary_position ? '/' + p.secondary_position : ''}</td>
      <td><span style="color:${getOvrColor(p.ovr)};" class="fw-bold">${p.ovr}</span>${(p.ovr_delta > 0) ? `<span style="font-size:10px;color:#22c55e;font-weight:700;margin-left:4px">+${p.ovr_delta}</span>` : (p.ovr_delta < 0) ? `<span style="font-size:10px;color:#ef4444;font-weight:700;margin-left:4px">${p.ovr_delta}</span>` : ''}</td>
      <td>${p.age}</td>
      <td>${normalizeRoleKey(p.role)}</td>
      <td>
        ${p.available_for_trade ? '<span class="badge bg-success">Disponível</span>' : '<span class="badge bg-secondary">Indisp.</span>'}
      </td>
      <td class="text-end">
        <button class="btn btn-sm btn-outline-info btn-details-player" data-id="${p.id}" title="Detalhes"><i class="bi bi-info-circle"></i></button>
        <button class="btn btn-sm btn-outline-secondary btn-copy-player" data-copy-name="${p.name}" data-copy-ovr="${p.ovr}" data-copy-age="${p.age}" title="Copiar"><i class="bi bi-clipboard"></i></button>
        <button class="btn btn-sm btn-outline-light btn-edit-player" data-id="${p.id}" title="Editar"><i class="bi bi-pencil"></i></button>
        <button class="btn btn-sm btn-outline-warning btn-waive-player" data-id="${p.id}" data-name="${p.name}" title="Dispensar"><i class="bi bi-hand-thumbs-down"></i></button>
        ${canRetire ? `<button class="btn btn-sm btn-outline-danger btn-retire-player" data-id="${p.id}" data-name="${p.name}" title="Aposentar"><i class="bi bi-box-arrow-right"></i></button>` : ''}
        <button class="btn btn-sm ${p.available_for_trade ? 'btn-outline-success' : 'btn-outline-danger'} btn-toggle-trade" data-id="${p.id}" data-trade="${p.available_for_trade}" title="Disponibilidade para Troca">
          <i class="bi ${p.available_for_trade ? 'bi-check-circle' : 'bi-x-circle'}"></i>
        </button>
      </td>`;
    tbody.appendChild(tr);
  });
  wrapper.style.display = '';
}

function updateRosterStats() {
  const totalPlayers = allPlayers.length;
  const topEight = calculateCapTop8(allPlayers);
  const capMaxAdjusted = getCapMaxAdjusted(allPlayers);
  const bonus = getRestrictedBonus(allPlayers);
  const capMin = Number(window.__CAP_MIN__);
  document.getElementById('total-players').textContent = totalPlayers;
  const capEl = document.getElementById('cap-top8');
  if (capEl) {
    const showFull = Number.isFinite(capMin) && Number.isFinite(capMaxAdjusted)
      && capMin > 0 && Number(window.__CAP_MAX__) > 0;
    if (showFull) {
      capEl.style.fontSize = '14px';
      capEl.innerHTML = `<span style="color:var(--text-3);font-weight:500">${capMin}</span>`
        + ` <span style="color:var(--text-3)">/</span> `
        + `${topEight}`
        + ` <span style="color:var(--text-3)">/</span> `
        + `<span style="color:var(--text-3);font-weight:500">${capMaxAdjusted}</span>`;
    } else {
      capEl.style.fontSize = '';
      capEl.textContent = topEight;
    }
  }
  const bonusLabel = document.getElementById('cap-bonus-label');
  if (bonusLabel) {
    bonusLabel.textContent = bonus > 0 ? `+${bonus}` : '';
  }
  const capRangeEl = document.getElementById('cap-range');
  if (capRangeEl) capRangeEl.textContent = '';

  // Banner de aviso — jogadores Restricted OVR Cap
  const bannerEl = document.getElementById('franchise-bonus-banner');
  if (bannerEl) {
    const eligible = allPlayers.filter(isFranchiseEligible);
    if (eligible.length > 0) {
      const names = eligible.map(p => `<strong>${p.name}</strong> (${p.ovr} OVR)`).join(', ');
      bannerEl.innerHTML = `
        <i class="bi bi-trophy-fill me-2" style="color:#f59e0b"></i>
        <span><strong style="color:#f59e0b">Restricted OVR Cap ativo:</strong>
        ${names} — bônus de <strong style="color:#f59e0b">+${bonus} pontos</strong> no CAP desta temporada.</span>`;
      bannerEl.style.display = 'flex';
    } else {
      bannerEl.style.display = 'none';
    }
  }
}

function copyPlayerSummary(btn) {
  const text = `${btn.dataset.copyName} - ${btn.dataset.copyOvr} | ${btn.dataset.copyAge}y`;
  navigator.clipboard.writeText(text).then(() => {
    const icon = btn.querySelector('i');
    if (icon) {
      icon.className = 'bi bi-clipboard-check';
      setTimeout(() => {
        icon.className = 'bi bi-clipboard';
      }, 1500);
    }
  });
}


async function loadPlayers() {
  const teamId = window.__TEAM_ID__;
  const statusEl = document.getElementById('players-status');
  const gridEl = document.getElementById('players-grid');
  const mobileCardsEl = document.getElementById('players-mobile-cards');
  if (!teamId) {
    if (statusEl) {
      statusEl.innerHTML = '<div class="alert alert-warning text-center"><i class="bi bi-exclamation-triangle me-2"></i>Você ainda não possui um time.</div>';
      statusEl.style.display = 'block';
    }
    if (gridEl) gridEl.style.display = 'none';
    if (mobileCardsEl) mobileCardsEl.style.display = 'none';
    return;
  }
  if (statusEl) {
    statusEl.innerHTML = '<div class="spinner-border text-orange" role="status"></div><p class="text-light-gray mt-2">Carregando jogadores...</p>';
    statusEl.style.display = 'block';
  }
  if (gridEl) gridEl.style.display = 'none';
  if (mobileCardsEl) mobileCardsEl.style.display = 'none';
  try {
    const data = await api(`players.php?team_id=${teamId}`);
    allPlayers = data.players || [];
    currentSort = { field: 'role', ascending: true };
    renderPlayers(allPlayers);
    if (statusEl) statusEl.style.display = 'none';
  } catch (err) {
    console.error('Erro ao carregar:', err);
    if (statusEl) {
      statusEl.innerHTML = `<div class="alert alert-danger text-center"><i class="bi bi-x-circle me-2"></i>Erro ao carregar jogadores: ${err.error || 'Desconhecido'}</div>`;
      statusEl.style.display = 'block';
    }
  }
}

// Event Listeners
document.addEventListener('DOMContentLoaded', () => {
  loadPlayers();
  loadFreeAgencyLimits();

  document.getElementById('btn-ai-analysis')?.addEventListener('click', generateAIAnalysis);

  document.getElementById('btn-refresh-players')?.addEventListener('click', loadPlayers);


  document.getElementById('sort-select')?.addEventListener('change', (e) => sortPlayers(e.target.value));
  document.getElementById('players-search')?.addEventListener('input', (e) => {
    currentSearch = (e.target.value || '').toLowerCase();
    renderPlayers(allPlayers);
  });
  document.getElementById('players-role-filter')?.addEventListener('change', (e) => {
    currentRoleFilter = e.target.value || '';
    renderPlayers(allPlayers);
  });
  document.querySelector('#players-table thead')?.addEventListener('click', (e) => {
    const th = e.target.closest('th.sortable');
    if (th && th.dataset.sort) sortPlayers(th.dataset.sort);
  });

  const editPhotoInput = document.getElementById('edit-foto-adicional');
  editPhotoInput?.addEventListener('change', (e) => {
    const file = e.target.files?.[0];
    if (!file) return;
    editPhotoFile = file;
    const preview = document.getElementById('edit-foto-preview');
    if (!preview) return;
    if (preview.dataset.objectUrl) {
      URL.revokeObjectURL(preview.dataset.objectUrl);
      delete preview.dataset.objectUrl;
    }
    if (window.URL && URL.createObjectURL) {
      const objectUrl = URL.createObjectURL(file);
      preview.src = objectUrl;
      preview.dataset.objectUrl = objectUrl;
      return;
    }
    const reader = new FileReader();
    reader.onload = (ev) => {
      preview.src = ev.target.result;
    };
    reader.readAsDataURL(file);
  });

  const formPlayer = document.getElementById('form-player');
  const handleAddPlayer = async () => {
    const form = formPlayer;
    if (!form) return;
    const teamId = window.__TEAM_ID__;
    if (!teamId) {
      alert('Você ainda não possui um time.');
      return;
    }
    const formData = new FormData(form);
    const payload = {
      team_id: teamId,
      name: (formData.get('name') || '').toString().trim(),
      age: parseInt(formData.get('age') || '0', 10),
      position: (formData.get('position') || '').toString().trim(),
      secondary_position: (formData.get('secondary_position') || '').toString().trim() || null,
      role: (formData.get('role') || 'Titular').toString(),
      ovr: parseInt(formData.get('ovr') || '0', 10),
      available_for_trade: formData.get('available_for_trade') ? 1 : 0
    };

    if (!payload.name || !payload.age || !payload.position || !payload.ovr) {
      alert('Preencha nome, idade, posição e OVR.');
      return;
    }

    const btn = document.getElementById('btn-add-player');
    if (btn) {
      btn.disabled = true;
      btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Enviando...';
    }

    try {
      const res = await api('players.php', { method: 'POST', body: JSON.stringify(payload) });
      alert(res.message || 'Jogador adicionado.');
      form.reset();
      document.getElementById('available_for_trade').checked = true;
      loadPlayers();
    } catch (err) {
      alert('Erro ao cadastrar jogador: ' + (err.error || err.message || 'Desconhecido'));
    } finally {
      if (btn) {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-cloud-upload me-1"></i>Cadastrar Jogador';
      }
    }
  };

  formPlayer?.addEventListener('submit', async (e) => {
    e.preventDefault();
    handleAddPlayer();
  });

  // Delegação para ações da tabela
  document.getElementById('players-table-body')?.addEventListener('click', async (e) => {
    const btn = e.target.closest('button');
    if (!btn) return;
    if (btn.classList.contains('btn-copy-player')) {
      copyPlayerSummary(btn);
      return;
    }
    if (btn.classList.contains('btn-details-player')) {
      openPlayerDetails(parseInt(btn.dataset.id, 10));
      return;
    }
    if (btn.classList.contains('btn-toggle-trade')) {
      const playerId = btn.dataset.id;
      const currentStatus = (() => {
        const raw = String(btn.dataset.trade || '').toLowerCase();
        return raw === 'true' || raw === '1' || raw === 'yes';
      })();
      const newStatus = currentStatus ? 0 : 1;
      try {
        await api('players.php', { method: 'PUT', body: JSON.stringify({ id: playerId, available_for_trade: newStatus }) });
        loadPlayers();
      } catch (err) {
        alert('Erro ao atualizar: ' + (err.error || 'Desconhecido'));
      }
      return;
    }
    if (btn.classList.contains('btn-edit-player')) {
      const playerId = btn.dataset.id;
      const player = allPlayers.find(p => p.id == playerId);
      if (player) {
        document.getElementById('edit-player-id').value = player.id;
        document.getElementById('edit-name').value = player.name;
        editPhotoFile = null;
        const editPhotoField = document.getElementById('edit-foto-adicional');
        if (editPhotoField) editPhotoField.value = '';
        const editPreview = document.getElementById('edit-foto-preview');
        if (editPreview) editPreview.src = getPlayerPhotoUrl(player);
        document.getElementById('edit-age').value = player.age;
        document.getElementById('edit-position').value = player.position;
        document.getElementById('edit-secondary-position').value = player.secondary_position || '';
        document.getElementById('edit-badges-count').value = (player.badges_count ?? '') === null ? '' : (player.badges_count ?? '');
        document.getElementById('edit-ovr').value = player.ovr;
        document.getElementById('edit-role').value = player.role;
        document.getElementById('edit-available').checked = !!player.available_for_trade;
        const editTagEl = document.getElementById('edit-tag');
        if (editTagEl) editTagEl.value = player.player_tag || '';
        const editTagColorEl = document.getElementById('edit-tag-color');
        if (editTagColorEl) editTagColorEl.value = player.player_tag_color || '#3b82f6';
        const editTagCopyEl = document.getElementById('edit-tag-copy');
        if (editTagCopyEl) editTagCopyEl.checked = !!Number(player.player_tag_copy);
        new bootstrap.Modal(document.getElementById('editPlayerModal')).show();
      }
      return;
    }
    if (btn.classList.contains('btn-waive-player')) {
      const playerId = btn.dataset.id;
      const player = allPlayers.find(p => p.id == playerId);
      openWaiveModal(player);
      return;
    }
    if (btn.classList.contains('btn-retire-player')) {
      const playerId = btn.dataset.id;
      const playerName = btn.dataset.name;
      if (confirm(`Aposentar ${playerName}?`)) {
        try {
          const res = await api('players.php', { method: 'DELETE', body: JSON.stringify({ id: playerId, retirement: true }) });
          alert(res.message || 'Jogador aposentado!');
          loadPlayers();
        } catch (err) {
          alert('Erro: ' + (err.error || 'Desconhecido'));
        }
      }
    }
  });

  // Delegação para ações nos cards mobile
  document.getElementById('players-mobile-cards')?.addEventListener('click', async (e) => {
    const btn = e.target.closest('button');
    if (!btn) return;
    if (btn.classList.contains('btn-copy-player')) {
      copyPlayerSummary(btn);
      return;
    }
    if (btn.classList.contains('btn-details-player')) {
      openPlayerDetails(parseInt(btn.dataset.id, 10));
      return;
    }
    if (btn.classList.contains('btn-toggle-trade')) {
      const playerId = btn.dataset.id;
      const currentStatus = (() => {
        const raw = String(btn.dataset.trade || '').toLowerCase();
        return raw === 'true' || raw === '1' || raw === 'yes';
      })();
      const newStatus = currentStatus ? 0 : 1;
      try {
        await api('players.php', { method: 'PUT', body: JSON.stringify({ id: playerId, available_for_trade: newStatus }) });
        loadPlayers();
      } catch (err) {
        alert('Erro ao atualizar: ' + (err.error || 'Desconhecido'));
      }
      return;
    }
    if (btn.classList.contains('btn-details-player')) {
      openPlayerDetails(parseInt(btn.dataset.id, 10));
      return;
    }
    if (btn.classList.contains('btn-edit-player')) {
      const playerId = btn.dataset.id;
      const player = allPlayers.find(p => p.id == playerId);
      if (player) {
        document.getElementById('edit-player-id').value = player.id;
        document.getElementById('edit-name').value = player.name;
        editPhotoFile = null;
        const editPhotoField = document.getElementById('edit-foto-adicional');
        if (editPhotoField) editPhotoField.value = '';
        const editPreview = document.getElementById('edit-foto-preview');
        if (editPreview) editPreview.src = getPlayerPhotoUrl(player);
        document.getElementById('edit-age').value = player.age;
        document.getElementById('edit-position').value = player.position;
        document.getElementById('edit-secondary-position').value = player.secondary_position || '';
        document.getElementById('edit-badges-count').value = (player.badges_count ?? '') === null ? '' : (player.badges_count ?? '');
        document.getElementById('edit-ovr').value = player.ovr;
        document.getElementById('edit-role').value = player.role;
        document.getElementById('edit-available').checked = !!player.available_for_trade;
        const editTagEl = document.getElementById('edit-tag');
        if (editTagEl) editTagEl.value = player.player_tag || '';
        const editTagColorEl = document.getElementById('edit-tag-color');
        if (editTagColorEl) editTagColorEl.value = player.player_tag_color || '#3b82f6';
        const editTagCopyEl = document.getElementById('edit-tag-copy');
        if (editTagCopyEl) editTagCopyEl.checked = !!Number(player.player_tag_copy);
        new bootstrap.Modal(document.getElementById('editPlayerModal')).show();
      }
      return;
    }
    if (btn.classList.contains('btn-waive-player')) {
      const playerId = btn.dataset.id;
      const player = allPlayers.find(p => p.id == playerId);
      openWaiveModal(player);
      return;
    }
    if (btn.classList.contains('btn-retire-player')) {
      const playerId = btn.dataset.id;
      const playerName = btn.dataset.name;
      if (confirm(`Aposentar ${playerName}?`)) {
        try {
          const res = await api('players.php', { method: 'DELETE', body: JSON.stringify({ id: playerId, retirement: true }) });
          alert(res.message || 'Jogador aposentado!');
          loadPlayers();
        } catch (err) {
          alert('Erro: ' + (err.error || 'Desconhecido'));
        }
      }
    }
  });

  // Salvar edição
  document.getElementById('btn-save-edit')?.addEventListener('click', async () => {
    const tagVal = (document.getElementById('edit-tag')?.value || '').trim().slice(0, 25);
    const ageVal = parseInt(document.getElementById('edit-age').value, 10);
    const ovrVal = parseInt(document.getElementById('edit-ovr').value, 10);

    if (Number.isNaN(ageVal) || ageVal < 16 || ageVal > 50) {
      alert('Idade inválida. Informe um valor entre 16 e 50.');
      return;
    }
    if (Number.isNaN(ovrVal) || ovrVal < 40 || ovrVal > 99) {
      alert('OVR inválido. Informe um valor entre 40 e 99.');
      return;
    }

    const badgesValRaw = document.getElementById('edit-badges-count')?.value;
    const badgesValNum = badgesValRaw === '' || badgesValRaw === null || typeof badgesValRaw === 'undefined'
      ? null
      : parseInt(badgesValRaw, 10);

    const data = {
      id: document.getElementById('edit-player-id').value,
      name: document.getElementById('edit-name').value,
      age: ageVal,
      position: document.getElementById('edit-position').value,
      secondary_position: document.getElementById('edit-secondary-position').value || null,
      badges_count: Number.isNaN(badgesValNum) ? null : badgesValNum,
      ovr: ovrVal,
      role: document.getElementById('edit-role').value,
      available_for_trade: document.getElementById('edit-available').checked ? 1 : 0,
      player_tag: tagVal || null,
      player_tag_color: tagVal ? (document.getElementById('edit-tag-color')?.value || '#3b82f6') : null,
      player_tag_copy: (document.getElementById('edit-tag-copy')?.checked && tagVal) ? 1 : 0,
    };
    if (editPhotoFile) {
      data.foto_adicional = await convertToBase64(editPhotoFile);
    }
    try {
      await api('players.php', { method: 'PUT', body: JSON.stringify(data) });
      bootstrap.Modal.getInstance(document.getElementById('editPlayerModal'))?.hide();
      loadPlayers();
    } catch (err) {
      alert('Erro ao salvar: ' + (err.error || err.message || 'Desconhecido'));
    }
  });

  // Detalhes do jogador
  window.openPlayerDetails = async function(playerId) {
    const modalEl = document.getElementById('playerDetailsModal');
    if (!modalEl) return;
    const content = document.getElementById('playerDetailsContent');
    const titleEl = document.getElementById('playerDetailsTitle');
    if (content) content.innerHTML = '<div style="display:flex;align-items:center;justify-content:center;padding:48px"><div class="spinner-border" role="status" style="color:var(--red);width:2rem;height:2rem;"></div></div>';
    if (titleEl) titleEl.textContent = 'Detalhes';
    new bootstrap.Modal(modalEl).show();
    try {
      const data = await api(`team.php?action=player_details&player_id=${playerId}`);
      const player = data.player || {};
      if (titleEl) titleEl.textContent = player.name || 'Detalhes';
      const transfers = Array.isArray(data.transfers) ? data.transfers : [];
      const seasonLog = Array.isArray(data.season_log) ? data.season_log : [];

      const latestDelta = seasonLog.length >= 2
        ? (parseInt(seasonLog[seasonLog.length-1].ovr)||0) - (parseInt(seasonLog[seasonLog.length-2].ovr)||0)
        : 0;
      const deltaHtml = latestDelta > 0
        ? `<span style="font-size:11px;color:#22c55e;font-weight:700;background:rgba(34,197,94,.12);border:1px solid rgba(34,197,94,.3);padding:2px 8px;border-radius:999px;margin-left:8px">+${latestDelta}</span>`
        : latestDelta < 0
          ? `<span style="font-size:11px;color:#ef4444;font-weight:700;background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.3);padding:2px 8px;border-radius:999px;margin-left:8px">${latestDelta}</span>`
          : '';

      const seasonLogHtml = seasonLog.length
        ? seasonLog.map((s, si) => {
            const sp = s.sprint_number ? `Sprint ${s.sprint_number}` : '';
            const tm = s.season_number ? `Temp ${s.season_number}` : '';
            const yr = s.year ? ` · ${s.year}` : '';
            const label = [sp, tm].filter(Boolean).join(' · ') + yr || `Temporada ${si+1}`;
            const d = si > 0 ? ((parseInt(s.ovr)||0) - (parseInt(seasonLog[si-1].ovr)||0)) : 0;
            const dHtml = d > 0 && si > 0
              ? `<span style="font-size:10px;color:#22c55e;font-weight:700;margin-left:6px">+${d}</span>`
              : d < 0 && si > 0 ? `<span style="font-size:10px;color:#ef4444;font-weight:700;margin-left:6px">${d}</span>` : '';
            return `<div style="display:flex;align-items:center;justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--border)">
              <div><div style="font-size:12px;font-weight:600">${label}</div><div style="font-size:11px;color:var(--text-2)">${s.team_name||'-'} · ${s.age??'-'}a</div></div>
              <div style="display:flex;align-items:center"><span style="color:var(--red);font-weight:800;font-size:15px">${s.ovr??'-'}</span>${dHtml}</div>
            </div>`;
          }).join('')
        : '<div style="font-size:13px;color:var(--text-3);padding:8px 0">Nenhum snapshot registrado ainda.</div>';

      const transferHtml = transfers.length
        ? transfers.map(t => `<div style="padding:8px 0;border-bottom:1px solid var(--border)">
            <div style="font-size:12px;font-weight:600">${t.from_team} <span style="color:var(--text-3)">→</span> ${t.to_team}</div>
            ${t.year ? `<div style="font-size:11px;color:var(--text-3)">${t.year}</div>` : ''}
          </div>`).join('')
        : '<div style="font-size:13px;color:var(--text-3);padding:8px 0">Nenhuma trade encontrada.</div>';

      const photoUrl = getPlayerPhotoUrl(player);
      if (content) content.innerHTML = `
        <div style="background:var(--panel-2);padding:20px 22px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:16px">
          <img src="${photoUrl}" alt="${player.name||''}"
               style="width:72px;height:72px;border-radius:50%;object-fit:cover;border:3px solid var(--border-red);flex-shrink:0;background:var(--panel-3)"
               onerror="this.src='https://ui-avatars.com/api/?name=${encodeURIComponent(player.name||'P')}&background=121212&color=fc0025&rounded=true&bold=true'">
          <div style="flex:1;min-width:0">
            <div style="font-size:18px;font-weight:800;line-height:1.2">${player.name||'-'}</div>
            <div style="font-size:12px;color:var(--text-2);margin-top:2px">${player.team_name||'-'}</div>
            <div style="display:flex;align-items:center;margin-top:6px">
              <span style="font-size:30px;font-weight:900;color:var(--red);line-height:1">${player.ovr??'-'}</span>
              ${deltaHtml}
            </div>
          </div>
        </div>
        <div style="display:grid;grid-template-columns:repeat(3,1fr);border-bottom:1px solid var(--border)">
          ${[['Idade',player.age??'-'],['Posição',player.position??'-'],['Pos. Sec.',player.secondary_position||'-']]
            .map(([l,v])=>`<div style="padding:12px 8px;text-align:center;border-right:1px solid var(--border)"><div style="font-size:15px;font-weight:800">${v}</div><div style="font-size:10px;color:var(--text-2);text-transform:uppercase;letter-spacing:.7px;font-weight:600">${l}</div></div>`).join('')}
        </div>
        <div style="padding:16px 22px">
          <div style="font-size:11px;font-weight:700;letter-spacing:.8px;text-transform:uppercase;color:var(--text-3);margin-bottom:10px">Evolução por Temporada</div>
          ${seasonLogHtml}
        </div>
        <div style="padding:0 22px 22px">
          <div style="font-size:11px;font-weight:700;letter-spacing:.8px;text-transform:uppercase;color:var(--text-3);margin-bottom:10px">Transferências</div>
          ${transferHtml}
        </div>`;
    } catch (err) {
      if (content) content.innerHTML = '<div style="padding:20px;color:var(--red)">Erro ao carregar detalhes.</div>';
    }
  };

  document.getElementById('btn-confirm-waive')?.addEventListener('click', async () => {
    const modalEl = document.getElementById('waivePlayerModal');
    const playerId = pendingWaivePlayerId;
    pendingWaivePlayerId = null;
    if (modalEl) {
      const instance = bootstrap.Modal.getInstance(modalEl);
      instance && instance.hide();
    }
    await performWaivePlayer(playerId);
  });
});
