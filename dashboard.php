<?php
require_once __DIR__ . '/backend/auth.php';
require_once __DIR__ . '/backend/db.php';
require_once __DIR__ . '/backend/helpers.php';
require_once __DIR__ . '/backend/salary_cap.php';
require_once __DIR__ . '/backend/pendencias.php';
require_once __DIR__ . '/backend/team-feed-helpers.php'; // FEED_DATA_CORTE + tabelas do feed
require_once __DIR__ . '/backend/queridometro.php';
requireAuth();

$user = getUserSession();
$pdo = db();
// A liga sai do time no banco, não da que ficou na sessão desde o login: quando
// o admin move o time de liga, o GM tem que ver a liga nova sem deslogar. Como
// isto também corrige a sessão, o resto do app já carrega certo em seguida.
$user['league'] = ligaAtualDoUsuario($pdo, $user);
ensureTeamDirectiveProfileColumns($pdo);

$dashboardShortcuts = getUserShortcuts($user['dashboard_shortcuts'] ?? null);

$stmtTeam = $pdo->prepare('
  SELECT t.*, COUNT(p.id) as player_count
  FROM teams t
  LEFT JOIN players p ON p.team_id = t.id
  WHERE t.user_id = ?
  GROUP BY t.id
  ORDER BY player_count DESC, t.id DESC
');
$stmtTeam->execute([$user['id']]);
$team = $stmtTeam->fetch();

// Cobranca da atualizacao de elenco: depois que o draft da temporada fecha, o
// GM precisa atualizar o time (OVR/idade/skills) — e so entao as trades liberam
// para ele. Enquanto nao atualiza, o dashboard cobra a cada carregamento.
$precisaAtualizarElenco = false;
$temporadaPendente = null;
if ($team) {
    $temporadaPendente = temporadaAtivaDaLiga($pdo, (string)($team['league'] ?? ''));
    if ($temporadaPendente) {
        $sid = (int)$temporadaPendente['id'];
        $precisaAtualizarElenco = draftConcluidoNaTemporada($pdo, $sid)
            && !elencoAtualizadoNaTemporada($pdo, (int)$team['id'], $sid);
    }
}

// Cobranca da logo do time: todo time novo entra com a logo padrao do FBA
// (photo_url vazio, cai no fallback getTeamPhoto()) — cobra até o GM subir
// uma de verdade, mesmo padrão do popup de atualizar elenco acima.
// Além do vazio, confere se o arquivo de um caminho local realmente existe —
// times antigos podem ter um photo_url "fantasma" (upload que se perdeu),
// que não é vazio mas também não mostra logo nenhuma de verdade.
$precisaLogo = false;
if ($team) {
    $fotoAtual = trim((string)($team['photo_url'] ?? ''));
    if ($fotoAtual === '') {
        $precisaLogo = true;
    } elseif (str_starts_with($fotoAtual, '/img/') && !is_file(__DIR__ . '/' . ltrim($fotoAtual, '/'))) {
        $precisaLogo = true;
    }
}

// Revisão de dados no início de cada sprint: na primeira vez que o GM entra
// numa sprint nova, cobra uma conferida em nome/cidade/mascote/GM/e-mail/logo.
// O que marca "já revisou" é o id da sprint ativa da liga gravado no time —
// quando finalize_sprint cria a sprint seguinte, o id muda e a cobrança volta
// sozinha, sem precisar resetar flag nenhuma.
$precisaRevisarSprint = false;
$sprintAtual = null;
if ($team) {
    try {
        $stmtColRv = $pdo->prepare("SHOW COLUMNS FROM teams LIKE ?");
        $stmtColRv->execute(['sprint_review_sprint_id']);
        if (!$stmtColRv->fetch()) {
            $pdo->exec("ALTER TABLE teams ADD COLUMN sprint_review_sprint_id INT NULL DEFAULT NULL");
        }
        $stmtSprint = $pdo->prepare("SELECT id, sprint_number FROM sprints WHERE league = ? AND status = 'active' ORDER BY id DESC LIMIT 1");
        $stmtSprint->execute([(string)($team['league'] ?? '')]);
        $sprintAtual = $stmtSprint->fetch();
        if ($sprintAtual) {
            $stmtRv = $pdo->prepare("SELECT sprint_review_sprint_id FROM teams WHERE id = ?");
            $stmtRv->execute([(int)$team['id']]);
            $revisadaEm = $stmtRv->fetchColumn();
            $precisaRevisarSprint = ((int)$revisadaEm !== (int)$sprintAtual['id']);
        }
    } catch (Throwable $e) {
        error_log('[dashboard sprint_review] ' . $e->getMessage());
        $precisaRevisarSprint = false;
    }
}

// Enquanto a revisão da sprint não for feita, ela é a única cobrança na tela
// (é bloqueante) — as outras voltam a aparecer no carregamento seguinte.
if ($precisaRevisarSprint) {
    $precisaLogo = false;
    $precisaAtualizarElenco = false;
}

// A ponte com o antigo banco do games saiu na fusão: agora é tudo um banco
// só e o acesso é a própria sessão do site, então não existe mais
// "conectar ao games" nem sincronização de tapas por carregamento.

// Tudo que tem prazo ou trava alguma coisa, reunido num lugar só — é o
// primeiro bloco da página. Ver backend/pendencias.php.
$pendencias = pendenciasDoGm($pdo, $user, $team ?: null);

// Queridômetro da temporada: MVP, MIP, Fraco, Cobra e Planta, votados
// pelos próprios GMs. É um voto por temporada: o popup aparece uma vez só e
// tanto o voto quanto o placar zeram quando a temporada vira.
$queridoLeague = '';
$queridoSeasonKey = '';
$precisaVotarQuerido = false;
$queridoTimes = [];
$queridoTop3 = [];
if ($team) {
    ensureQuerdometroTable($pdo);
    $queridoLeague = strtoupper((string)($team['league'] ?? ''));
    $queridoSeasonKey = $queridoLeague !== '' ? queridometroSeasonKey($pdo, $queridoLeague) : '';
    $precisaVotarQuerido = $queridoLeague !== '' && !queridometroJaVotou($pdo, $queridoLeague, (int)$team['id'], $queridoSeasonKey);
    // O voto é no GM, não no time — id continua sendo o do time (é o que vai
    // pro banco), mas o que aparece pra escolher é o dono dele.
    $stmtQTimes = $pdo->prepare("
        SELECT t.id, u.name, u.photo_url
        FROM teams t
        INNER JOIN users u ON u.id = t.user_id
        WHERE t.league = ? AND t.id != ?
        ORDER BY u.name
    ");
    $stmtQTimes->execute([$queridoLeague, (int)$team['id']]);
    $queridoTimes = $stmtQTimes->fetchAll(PDO::FETCH_ASSOC);
    $queridoTop3 = queridometroTop3($pdo, $queridoLeague, $queridoSeasonKey);
}
// A revisão de sprint é bloqueante — enquanto ela não sai da tela, o popup
// do Queridômetro espera o próximo carregamento (mesma regra da logo/elenco acima).
if ($precisaRevisarSprint) {
    $precisaVotarQuerido = false;
}

$hasActiveTactic = false;
if ($team) {
    try {
        $stmtActiveTactic = $pdo->prepare('SELECT 1 FROM team_tactics WHERE team_id = ? AND is_active = 1 LIMIT 1');
        $stmtActiveTactic->execute([(int)$team['id']]);
        $hasActiveTactic = (bool)$stmtActiveTactic->fetchColumn();
    } catch (Exception $e) { error_log('dashboard active_tactic: ' . $e->getMessage()); }
}

if (!$team) {
    header('Location: /onboarding.php');
    exit;
}

$stmtPlayers = $pdo->prepare('SELECT COUNT(*) as total, SUM(ovr) as total_ovr FROM players WHERE team_id = ?');
$stmtPlayers->execute([$team['id']]);
$stats = $stmtPlayers->fetch();

$totalPlayers = $stats['total'] ?? 0;
$avgOvr = $totalPlayers > 0 ? round($stats['total_ovr'] / $totalPlayers, 1) : 0;
$minPlayers = 13;
$maxPlayers = 15;
$playersOutOfRange = $totalPlayers < $minPlayers || $totalPlayers > $maxPlayers;

$stmtTitulares = $pdo->prepare("SELECT * FROM players WHERE team_id = ? AND role = 'Titular' ORDER BY ovr DESC");
$stmtTitulares->execute([$team['id']]);
$titulares = $stmtTitulares->fetchAll();

$teamCap = 0;
$stmtCap = $pdo->prepare('SELECT SUM(ovr) as cap FROM (SELECT ovr FROM players WHERE team_id = ? ORDER BY ovr DESC LIMIT 8) as top_eight');
$stmtCap->execute([$team['id']]);
$capData = $stmtCap->fetch();
$teamCap = $capData['cap'] ?? 0;

$capMin = 0; $capMax = 999;
try {
    $stmtCapLimits = $pdo->prepare('SELECT cap_min, cap_max FROM league_settings WHERE league = ?');
    $stmtCapLimits->execute([$team['league']]);
    $capLimits = $stmtCapLimits->fetch();
    if ($capLimits) { $capMin = $capLimits['cap_min'] ?? 0; $capMax = $capLimits['cap_max'] ?? 999; }
} catch (Exception $e) { error_log('dashboard cap_limits: ' . $e->getMessage()); }

$capBonus = restrictedCapBonus($pdo, (int)$team['id']);
$capMaxBase = $capMax;
$capMax = capMaxWithRestrictedBonus($pdo, (int)$team['id'], (int)$capMax);

$capOk = $teamCap >= $capMin && $teamCap <= $capMax;

// Novo Salary Cap (folha em dinheiro) — só para ligas em modo 'salary' (hoje, ELITE).
$salaryCapMode = false;
$salCap = null;
try {
    $stmtCapMode = $pdo->prepare("SELECT cap_mode FROM league_settings WHERE league = ?");
    $stmtCapMode->execute([$team['league']]);
    $salaryCapMode = (($stmtCapMode->fetchColumn() ?: 'ovr_sum') === 'salary');
    if ($salaryCapMode) {
        $salCap = getTeamCapSummary($pdo, (int)$team['id']);
    }
} catch (Exception $e) { $salaryCapMode = false; }

$editalData = null; $hasEdital = false;
try {
    $stmtEdital = $pdo->prepare('SELECT edital, edital_file FROM league_settings WHERE league = ?');
    $stmtEdital->execute([$team['league']]);
    $editalData = $stmtEdital->fetch();
    $hasEdital = $editalData && !empty($editalData['edital_file']);
} catch (Exception $e) { error_log('dashboard edital: ' . $e->getMessage()); }

// Vídeos administrativos da liga: Progression, Sistemas e Free Agency.
// Os 3 espaços aparecem sempre — o que não tiver link ainda mostra "Em breve".
$leagueVideoCards = [
    ['key' => 'progression', 'title' => 'Progression', 'icon' => 'bi-camera-reels-fill', 'url' => null],
    ['key' => 'sistemas',    'title' => 'Sistemas',    'icon' => 'bi-gear-fill',          'url' => null],
    ['key' => 'freeagency',  'title' => 'Free Agency', 'icon' => 'bi-coin',               'url' => null],
];
try {
    $stmtVideos = $pdo->prepare('SELECT progression_video_url, sistemas_video_url, freeagency_video_url FROM league_settings WHERE league = ?');
    $stmtVideos->execute([$team['league']]);
    $videoUrls = $stmtVideos->fetch() ?: [];
    $leagueVideoCards[0]['url'] = $videoUrls['progression_video_url'] ?? null;
    $leagueVideoCards[1]['url'] = $videoUrls['sistemas_video_url'] ?? null;
    $leagueVideoCards[2]['url'] = $videoUrls['freeagency_video_url'] ?? null;
} catch (Exception $e) { error_log('dashboard league videos: ' . $e->getMessage()); }
foreach ($leagueVideoCards as &$vcDef) { $vcDef['embed'] = resolveVideoEmbed($vcDef['url']); }
unset($vcDef);

// Roletas e drafts aleatórios abertos na liga do GM. Só aparece card quando
// existe algo em andamento — não é um atalho fixo, é um aviso de "tem sorteio
// rolando agora". Tudo em best-effort: a tabela pode nem existir no banco.
$eventosSorteio = [];
$ligaDoTime = strtoupper((string)($team['league'] ?? ''));
if ($ligaDoTime !== '') {
    try {
        $stmtRol = $pdo->prepare("
            SELECT r.id, r.titulo,
                   COUNT(rp.id) AS total,
                   SUM(rp.pick_number IS NOT NULL) AS sorteados
            FROM roletas r
            JOIN roleta_participantes rp ON rp.roleta_id = r.id
            WHERE UPPER(r.league) = ?
            GROUP BY r.id
            HAVING total > 0 AND sorteados < total
            ORDER BY r.created_at DESC
            LIMIT 3");
        $stmtRol->execute([$ligaDoTime]);
        foreach ($stmtRol->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $eventosSorteio[] = [
                'tipo'   => 'roleta',
                'titulo' => $r['titulo'],
                'href'   => '/roleta-editar.php?id=' . (int)$r['id'],
                'icone'  => 'bi-dice-5-fill',
                'feitas' => (int)$r['sorteados'],
                'total'  => (int)$r['total'],
                'rotulo' => 'Sorteio em andamento',
            ];
        }
    } catch (Throwable $e) { /* roletas ainda não existe neste banco */ }

    try {
        $stmtDra = $pdo->prepare("
            SELECT d.id, d.titulo,
                   COUNT(dap.id) AS total,
                   SUM(dap.player_name IS NOT NULL OR dap.skipped = 1) AS resolvidas
            FROM drafts_aleatorios d
            JOIN draft_aleatorio_picks dap ON dap.draft_id = d.id
            WHERE UPPER(d.league) = ? AND d.finalizado_em IS NULL
            GROUP BY d.id
            HAVING total > 0 AND resolvidas < total
            ORDER BY d.created_at DESC
            LIMIT 3");
        $stmtDra->execute([$ligaDoTime]);
        foreach ($stmtDra->fetchAll(PDO::FETCH_ASSOC) as $d) {
            $eventosSorteio[] = [
                'tipo'   => 'draft',
                'titulo' => $d['titulo'],
                'href'   => '/draft-aleatorio.php?id=' . (int)$d['id'],
                'icone'  => 'bi-shuffle',
                'feitas' => (int)$d['resolvidas'],
                'total'  => (int)$d['total'],
                'rotulo' => 'Draft aleatório aberto',
            ];
        }
    } catch (Throwable $e) { /* drafts_aleatorios ainda não existe neste banco */ }

    try {
        $stmtLot = $pdo->prepare("
            SELECT l.id, l.titulo,
                   COUNT(lp.id) AS total,
                   SUM(lp.pick_number IS NOT NULL) AS sorteados
            FROM loterias l
            JOIN loteria_participantes lp ON lp.loteria_id = l.id
            WHERE UPPER(l.league) = ?
            GROUP BY l.id
            HAVING total > 0 AND sorteados < total
            ORDER BY l.created_at DESC
            LIMIT 3");
        $stmtLot->execute([$ligaDoTime]);
        foreach ($stmtLot->fetchAll(PDO::FETCH_ASSOC) as $l) {
            $eventosSorteio[] = [
                'tipo'   => 'loteria',
                'titulo' => $l['titulo'],
                'href'   => '/loteria-aleatoria.php?id=' . (int)$l['id'],
                'icone'  => 'bi-dice-3-fill',
                'feitas' => (int)$l['sorteados'],
                'total'  => (int)$l['total'],
                'rotulo' => 'Loteria em andamento',
            ];
        }
    } catch (Throwable $e) { /* loterias ainda não existe neste banco */ }
}

// Último post da Timeline: o mais recente de quem o time segue. Sem ninguém
// seguido (ou sem post de quem é seguido), mostra o mais recente da liga —
// card vazio não convida ninguém a abrir a Timeline.
$ultimoPost = null;
$postDeQuemSigo = false;
$meuTimeId = (int)($team['id'] ?? 0);
$selectPost = "SELECT tp.id, tp.texto, tp.photo_url, tp.created_at,
                      u.name AS autor, u.photo_url AS autor_foto,
                      tm.city AS time_cidade, tm.name AS time_nome, tm.photo_url AS time_foto,
                      (SELECT COUNT(*) FROM team_post_likes WHERE post_id = tp.id) AS curtidas
               FROM team_posts tp
               JOIN users u  ON u.id  = tp.author_user_id
               JOIN teams tm ON tm.id = tp.team_id
               WHERE tp.deleted_at IS NULL AND tp.created_at >= ?";

// Dois try separados de propósito: team_follows é criada sob demanda pela
// Timeline, então num banco onde ninguém abriu a Timeline ainda a 1ª consulta
// explode — e num try só isso levaria embora também o fallback da liga.
if ($meuTimeId) {
    try {
        $stmtPost = $pdo->prepare($selectPost . "
              AND tp.team_id IN (SELECT followed_team_id FROM team_follows WHERE follower_team_id = ?)
              AND tp.team_id <> ?
            ORDER BY tp.created_at DESC LIMIT 1");
        $stmtPost->execute([FEED_DATA_CORTE, $meuTimeId, $meuTimeId]);
        $ultimoPost = $stmtPost->fetch(PDO::FETCH_ASSOC) ?: null;
        $postDeQuemSigo = (bool)$ultimoPost;
    } catch (Throwable $e) {
        error_log('dashboard post de quem sigo: ' . $e->getMessage());
    }
}

if (!$ultimoPost && $ligaDoTime !== '') {
    try {
        $stmtPost = $pdo->prepare($selectPost . " AND tm.league = ? ORDER BY tp.created_at DESC LIMIT 1");
        $stmtPost->execute([FEED_DATA_CORTE, $ligaDoTime]);
        $ultimoPost = $stmtPost->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        error_log('dashboard último post da liga: ' . $e->getMessage());
    }
}

// Tática não tem mais prazo de envio — só avisa aqui quando o admin fechou a
// edição (corte diário ou toggle manual), pra o GM saber que não é hoje.
$tacticEditClosed = false; $tacticEditReopensAt = null;
try {
    $stmtWindow = $pdo->prepare('SELECT * FROM tactic_edit_windows WHERE league = ?');
    $stmtWindow->execute([$team['league']]);
    $windowRow = $stmtWindow->fetch(PDO::FETCH_ASSOC);
    if ($windowRow) {
        $agora = date('Y-m-d H:i:s');
        $manualOpenUntil = $windowRow['manual_open_until'] ?? null;
        if ($manualOpenUntil && $manualOpenUntil > $agora) {
            $tacticEditClosed = false;
        } elseif (!empty($windowRow['manual_closed'])) {
            $tacticEditClosed = true;
        } else {
            $tacticEditClosed = date('H:i:s') >= $windowRow['daily_cutoff_time'];
        }
        $tacticEditReopensAt = substr($windowRow['daily_cutoff_time'], 0, 5);
    }
} catch (Exception $e) { error_log('dashboard tactic_edit_window: ' . $e->getMessage()); }

$currentSeason = null;
try {
    $stmtSeason = $pdo->prepare("SELECT s.season_number, s.year, s.status, sp.sprint_number, sp.start_year FROM seasons s INNER JOIN sprints sp ON s.sprint_id = sp.id WHERE s.league = ? AND (s.status IS NULL OR s.status NOT IN ('completed')) ORDER BY s.created_at DESC LIMIT 1");
    $stmtSeason->execute([$team['league']]);
    $currentSeason = $stmtSeason->fetch();
} catch (Exception $e) { error_log('dashboard current_season: ' . $e->getMessage()); }

$seasonDisplayYear = null;
if ($currentSeason && isset($currentSeason['start_year'], $currentSeason['season_number'])) {
    $seasonDisplayYear = (int)$currentSeason['start_year'] + (int)$currentSeason['season_number'] - 1;
} elseif ($currentSeason && isset($currentSeason['year'])) {
    $seasonDisplayYear = (int)$currentSeason['year'];
}

try {
    $stmtAllPlayers = $pdo->prepare("SELECT id, name, position, role, ovr, age, player_tag, player_tag_color, player_tag_copy FROM players WHERE team_id = ? ORDER BY ovr DESC, name ASC");
    $stmtAllPlayers->execute([$team['id']]);
    $allPlayers = $stmtAllPlayers->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $stmtAllPlayers = $pdo->prepare("SELECT id, name, position, role, ovr, age FROM players WHERE team_id = ? ORDER BY ovr DESC, name ASC");
    $stmtAllPlayers->execute([$team['id']]);
    $allPlayers = $stmtAllPlayers->fetchAll(PDO::FETCH_ASSOC);
}

$stmtPicks = $pdo->prepare("SELECT p.season_year, p.round, orig.city, orig.name AS team_name, p.original_team_id, p.team_id FROM picks p JOIN teams orig ON p.original_team_id = orig.id WHERE p.team_id = ? ORDER BY p.season_year ASC, p.round ASC");
$stmtPicks->execute([$team['id']]);
$teamPicks = $stmtPicks->fetchAll(PDO::FETCH_ASSOC);
$copySeasonYear = !empty($seasonDisplayYear) ? (int)$seasonDisplayYear : (int)date('Y');
$teamPicksForCopy = array_values(array_filter($teamPicks, fn($p) => (int)($p['season_year'] ?? 0) >= $copySeasonYear));
$firstRoundPicksCount = count(array_filter($teamPicks, fn($p) => (int)($p['round'] ?? 0) === 1 && (int)($p['season_year'] ?? 0) >= $copySeasonYear));

function syncTeamTradeCounterDashboard(PDO $pdo, int $teamId): int {
    try {
        $stmt = $pdo->prepare('SELECT current_cycle, trades_cycle, trades_used FROM teams WHERE id = ?');
        $stmt->execute([$teamId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return 0;
        $currentCycle = (int)($row['current_cycle'] ?? 0);
        $tradesCycle = (int)($row['trades_cycle'] ?? 0);
        $tradesUsed = (int)($row['trades_used'] ?? 0);
        if ($currentCycle > 0 && $tradesCycle !== $currentCycle) {
            $pdo->prepare('UPDATE teams SET trades_used = 0, trades_cycle = ? WHERE id = ?')->execute([$currentCycle, $teamId]);
            return 0;
        }
        if ($currentCycle > 0 && $tradesCycle <= 0) $pdo->prepare('UPDATE teams SET trades_cycle = ? WHERE id = ?')->execute([$currentCycle, $teamId]);
        return $tradesUsed;
    } catch (Exception $e) { return 0; }
}
$tradesCount = syncTeamTradeCounterDashboard($pdo, (int)$team['id']);

$lastTrade = null; $lastTradeFromPlayers = []; $lastTradeToPlayers = []; $lastTradeFromPicks = []; $lastTradeToPicks = [];
try {
    $stmtLastTrade = $pdo->prepare("SELECT t.*, t1.city as from_city, t1.name as from_name, t1.photo_url as from_photo, t2.city as to_city, t2.name as to_name, t2.photo_url as to_photo, u1.name as from_owner, u2.name as to_owner FROM trades t JOIN teams t1 ON t.from_team_id = t1.id JOIN teams t2 ON t.to_team_id = t2.id LEFT JOIN users u1 ON t1.user_id = u1.id LEFT JOIN users u2 ON t2.user_id = u2.id WHERE t.status = 'accepted' AND t1.league = ? ORDER BY t.updated_at DESC LIMIT 1");
    $stmtLastTrade->execute([$team['league']]);
    $lastTrade = $stmtLastTrade->fetch();
    if ($lastTrade) {
        $q = fn($col) => $pdo->prepare("SELECT p.name, p.position, p.ovr FROM players p JOIN trade_items ti ON p.id = ti.player_id WHERE ti.trade_id = ? AND ti.from_team = $col AND ti.player_id IS NOT NULL");
        $q2 = fn($col) => $pdo->prepare("SELECT pk.season_year, pk.round FROM picks pk JOIN trade_items ti ON pk.id = ti.pick_id WHERE ti.trade_id = ? AND ti.from_team = $col AND ti.pick_id IS NOT NULL");
        foreach ([['lastTradeFromPlayers','TRUE'],['lastTradeToPlayers','FALSE']] as [$var, $col]) { $s = $q($col); $s->execute([$lastTrade['id']]); $$var = $s->fetchAll(); }
        foreach ([['lastTradeFromPicks','TRUE'],['lastTradeToPicks','FALSE']] as [$var, $col]) { $s = $q2($col); $s->execute([$lastTrade['id']]); $$var = $s->fetchAll(); }
    }
} catch (Exception $e) { error_log('dashboard last_trade: ' . $e->getMessage()); }

$maxTrades = 3; $tradesEnabled = 1;
try {
    $s = $pdo->prepare('SELECT max_trades, trades_enabled FROM league_settings WHERE league = ?');
    $s->execute([$team['league']]); $r = $s->fetch();
    if ($r) { if (isset($r['max_trades'])) $maxTrades = (int)$r['max_trades']; if (isset($r['trades_enabled'])) $tradesEnabled = (int)$r['trades_enabled']; }
} catch (Exception $e) { error_log('dashboard trade_settings: ' . $e->getMessage()); }

$topRanking = [];
try {
    $s = $pdo->prepare("SELECT t.id, t.city, t.name, t.photo_url, t.ranking_points, u.name as owner_name FROM teams t LEFT JOIN users u ON t.user_id = u.id WHERE t.league = ? ORDER BY t.ranking_points DESC LIMIT 5");
    $s->execute([$team['league']]); $topRanking = $s->fetchAll();
} catch (Exception $e) { error_log('dashboard top_ranking: ' . $e->getMessage()); }

// Posição atual no ranking da liga (dado já existente) + última posição registrada por temporada
$leagueRank = null; $leagueTeamCount = null; $lastSeasonPos = null;
try {
    $s = $pdo->prepare("SELECT COUNT(*) FROM teams WHERE league = ?");
    $s->execute([$team['league']]); $leagueTeamCount = (int)$s->fetchColumn();
    $s = $pdo->prepare("SELECT COUNT(*) + 1 FROM teams WHERE league = ? AND ranking_points > ?");
    $s->execute([$team['league'], (int)($team['ranking_points'] ?? 0)]); $leagueRank = (int)$s->fetchColumn();
} catch (Exception $e) { error_log('dashboard league_rank: ' . $e->getMessage()); }
try {
    if ($pdo->query("SHOW TABLES LIKE 'season_standings'")->fetch()) {
        $confCol = $pdo->query("SHOW COLUMNS FROM season_standings LIKE 'conference'")->fetch() ? 'ss.conference' : 'NULL AS conference';
        $s = $pdo->prepare("SELECT ss.position, {$confCol}, se.year FROM season_standings ss JOIN seasons se ON se.id = ss.season_id WHERE ss.team_id = ? ORDER BY se.year DESC, se.season_number DESC LIMIT 1");
        $s->execute([$team['id']]); $lastSeasonPos = $s->fetch(PDO::FETCH_ASSOC) ?: null;
    }
} catch (Exception $e) { error_log('dashboard last_season_position: ' . $e->getMessage()); }

// Watchlist: jogadores favoritados do usuário (tabela player_favorites)
$watchlist = [];
try {
    $s = $pdo->prepare("
        SELECT p.id, p.name, p.position, p.age, p.ovr, p.nba_player_id, p.foto_adicional,
               t.city, t.name AS team_name
        FROM player_favorites pf
        JOIN players p ON p.id = pf.player_id
        LEFT JOIN teams t ON t.id = p.team_id
        WHERE pf.user_id = ?
        ORDER BY p.ovr DESC, p.name ASC
        LIMIT 6
    ");
    $s->execute([$user['id']]);
    $watchlist = $s->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { error_log('dashboard watchlist: ' . $e->getMessage()); }

$latestRumor = null;
try {
    $s = $pdo->prepare('
        SELECT mp.content, mp.created_at, t.city, t.name, t.photo_url, u.name as gm_name
        FROM mercado_feed mp
        JOIN users u ON u.id = mp.user_id
        LEFT JOIN teams t ON t.id = mp.team_id
        WHERE mp.league = ?
        ORDER BY mp.created_at DESC LIMIT 1
    ');
    $s->execute([$team['league']]); $latestRumor = $s->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) { error_log('dashboard latest_rumor: ' . $e->getMessage()); }

// Vencedores da última temporada FECHADA — e só da sprint atual. Antes a busca
// pegava a temporada mais recente da liga inteira, então no começo de uma sprint
// o card ficava exibindo o campeão da sprint anterior como se fosse o atual.
// Sem temporada fechada nesta sprint ainda, o card mostra "em breve".
$lastChampion = null; $lastRunnerUp = null; $lastMVP = null; $lastSprintInfo = null;
try {
    $sprintIdAtual = (int)($sprintAtual['id'] ?? 0);
    if (!$sprintIdAtual) {
        $stmtSpFallback = $pdo->prepare("SELECT id FROM sprints WHERE league = ? ORDER BY sprint_number DESC, id DESC LIMIT 1");
        $stmtSpFallback->execute([$team['league']]);
        $sprintIdAtual = (int)($stmtSpFallback->fetchColumn() ?: 0);
    }

    $s = $pdo->prepare("SELECT sh.*, t1.id as champion_id, t1.city as champion_city, t1.name as champion_name, t1.photo_url as champion_photo, u1.name as champion_owner, t2.id as runner_up_id, t2.city as runner_up_city, t2.name as runner_up_name, t2.photo_url as runner_up_photo, u2.name as runner_up_owner
                        FROM season_history sh
                        INNER JOIN seasons se ON se.id = sh.season_id
                        LEFT JOIN teams t1 ON sh.champion_team_id = t1.id LEFT JOIN users u1 ON t1.user_id = u1.id
                        LEFT JOIN teams t2 ON sh.runner_up_team_id = t2.id LEFT JOIN users u2 ON t2.user_id = u2.id
                        WHERE sh.league = ? AND se.sprint_id = ?
                        ORDER BY sh.season_number DESC, sh.season_id DESC LIMIT 1");
    $s->execute([$team['league'], $sprintIdAtual]);
    $lastSprintInfo = $sprintIdAtual ? $s->fetch() : false;
    if ($lastSprintInfo) {
        if ($lastSprintInfo['champion_id']) $lastChampion = ['id'=>$lastSprintInfo['champion_id'],'city'=>$lastSprintInfo['champion_city'],'name'=>$lastSprintInfo['champion_name'],'photo_url'=>$lastSprintInfo['champion_photo'],'owner_name'=>$lastSprintInfo['champion_owner']];
        if ($lastSprintInfo['runner_up_id']) $lastRunnerUp = ['id'=>$lastSprintInfo['runner_up_id'],'city'=>$lastSprintInfo['runner_up_city'],'name'=>$lastSprintInfo['runner_up_name'],'photo_url'=>$lastSprintInfo['runner_up_photo'],'owner_name'=>$lastSprintInfo['runner_up_owner']];
        if (!empty($lastSprintInfo['mvp_player'])) {
            $lastMVP = ['name'=>$lastSprintInfo['mvp_player'],'position'=>null,'ovr'=>null,'team_city'=>null,'team_name'=>null];
            if (!empty($lastSprintInfo['mvp_team_id'])) { $sm = $pdo->prepare("SELECT city, name FROM teams WHERE id = ?"); $sm->execute([$lastSprintInfo['mvp_team_id']]); $mvpTeam = $sm->fetch(); if ($mvpTeam) { $lastMVP['team_city']=$mvpTeam['city']; $lastMVP['team_name']=$mvpTeam['name']; } }
        }
    }
} catch (Exception $e) { error_log('dashboard last_sprint_info: ' . $e->getMessage()); }

$activeInitDraftSession = null; $currentDraftPick = null; $nextDraftPick = null;
$remainingDraftPicks = 0; $initDraftTeamsPerRound = 0;
try {
    $s = $pdo->prepare("SELECT * FROM initdraft_sessions WHERE league = ? AND status = 'in_progress' ORDER BY id DESC LIMIT 1");
    $s->execute([$team['league']]); $activeInitDraftSession = $s->fetch(PDO::FETCH_ASSOC);
    if ($activeInitDraftSession) {
        $sid = (int)$activeInitDraftSession['id'];
        $s = $pdo->prepare("SELECT io.*, t.city, t.name AS team_name, t.photo_url, u.name AS owner_name FROM initdraft_order io JOIN teams t ON io.team_id = t.id LEFT JOIN users u ON t.user_id = u.id WHERE io.initdraft_session_id = ? AND io.picked_player_id IS NULL ORDER BY io.round ASC, io.pick_position ASC LIMIT 1");
        $s->execute([$sid]); $currentDraftPick = $s->fetch(PDO::FETCH_ASSOC);
        if ($currentDraftPick) {
            $s = $pdo->prepare("SELECT io.*, t.city, t.name AS team_name, t.photo_url FROM initdraft_order io JOIN teams t ON io.team_id = t.id WHERE io.initdraft_session_id = ? AND io.picked_player_id IS NULL ORDER BY io.round ASC, io.pick_position ASC LIMIT 1 OFFSET 1");
            $s->execute([$sid]); $nextDraftPick = $s->fetch(PDO::FETCH_ASSOC);
            $s = $pdo->prepare('SELECT COUNT(*) FROM initdraft_order WHERE initdraft_session_id = ? AND picked_player_id IS NULL'); $s->execute([$sid]); $remainingDraftPicks = (int)$s->fetchColumn();
            $s = $pdo->prepare('SELECT COUNT(*) FROM initdraft_order WHERE initdraft_session_id = ? AND round = 1'); $s->execute([$sid]); $initDraftTeamsPerRound = (int)$s->fetchColumn();
        }
    }
} catch (Exception $e) { error_log('dashboard init_draft_session: ' . $e->getMessage()); }

$activeDraft = $activeInitDraftSession && $currentDraftPick;
$currentDraftOverallNumber = null; $nextDraftOverallNumber = null;
if ($currentDraftPick && $initDraftTeamsPerRound > 0) $currentDraftOverallNumber = (($currentDraftPick['round'] - 1) * $initDraftTeamsPerRound) + $currentDraftPick['pick_position'];
if ($nextDraftPick && $initDraftTeamsPerRound > 0) $nextDraftOverallNumber = (($nextDraftPick['round'] - 1) * $initDraftTeamsPerRound) + $nextDraftPick['pick_position'];

$capPct = $capMax > 0 ? min(100, round(($teamCap / $capMax) * 100)) : 0;
$tradesPct = $maxTrades > 0 ? min(100, round(($tradesCount / $maxTrades) * 100)) : 0;
$playersPct = $maxPlayers > 0 ? min(100, round(($totalPlayers / $maxPlayers) * 100)) : 0;
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <script>document.documentElement.dataset.theme = localStorage.getItem('fba-theme') || 'dark';</script>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <meta name="theme-color" content="#fc0025">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="FBA Manager">
    <link rel="manifest" href="/manifest.json?v=3">
    <link rel="apple-touch-icon" href="/img/fba-logo.png?v=3">
    <title>Dashboard - FBA Manager</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800;900&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/css/styles.css">

    <style>
        /* ── Tokens ──────────────────────────────────── */
        :root {
            --red:        #fc0025;
            --red-2:      color-mix(in srgb, var(--red) 85%, white);
            --red-soft:   color-mix(in srgb, var(--red) 10%, transparent);
            --red-glow:   color-mix(in srgb, var(--red) 18%, transparent);
            --bg:         #07070a;
            --panel:      #101013;
            --panel-2:    #16161a;
            --panel-3:    #1c1c21;
            --border:     rgba(255,255,255,.06);
            --border-md:  rgba(255,255,255,.10);
            --border-red: color-mix(in srgb, var(--red) 22%, transparent);
            --text:       #f0f0f3;
            --text-2:     #868690;
            --text-3:     #7d7d85;
            --green:      #22c55e;
            --amber:      #f59e0b;
            --blue:       #3b82f6;
            --sidebar-w:  260px;
            --font:       'Montserrat', sans-serif;
            --radius:     14px;
            --radius-sm:  10px;
            --radius-xs:  6px;
            --ease:       cubic-bezier(.2,.8,.2,1);
            --t:          200ms;
        }

        :root[data-theme="light"] {
            --bg: #f6f7fb;
            --panel: #ffffff;
            --panel-2: #f2f4f8;
            --panel-3: #e9edf4;
            --border: #e3e6ee;
            --border-md: #d7dbe6;
            --border-red: color-mix(in srgb, var(--red) 18%, transparent);
            --text: #111217;
            --text-2: #5b6270;
            --text-3: #657080;
        }

        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        html, body { height: 100%; }
        body {
            font-family: var(--font);
            background: var(--bg);
            color: var(--text);
            -webkit-font-smoothing: antialiased;
        }

        /* ── Shell ───────────────────────────────────── */
        .app { display: flex; min-height: 100vh; }

        /* ── Sidebar ─────────────────────────────────── */
        .sidebar {
            position: fixed; top: 0; left: 0;
            width: 260px; height: 100vh;
            background: var(--panel);
            border-right: 1px solid var(--border);
            display: flex; flex-direction: column;
            z-index: 300;
            transition: transform var(--t) var(--ease);
            overflow-y: auto;
            scrollbar-width: none;
        }
        .sidebar::-webkit-scrollbar { display: none; }

        .sb-brand {
            padding: 22px 18px 18px;
            border-bottom: 1px solid var(--border);
            display: flex; align-items: center; gap: 12px;
            flex-shrink: 0;
        }
        .sb-logo {
            width: 34px; height: 34px; border-radius: 9px;
            background: var(--red);
            display: flex; align-items: center; justify-content: center;
            font-weight: 800; font-size: 13px; color: #fff;
            flex-shrink: 0;
        }
        .sb-brand-text { font-weight: 700; font-size: 15px; line-height: 1.1; }
        .sb-brand-text span { display: block; font-size: 11px; font-weight: 400; color: var(--text-2); }

        /* Team card in sidebar */
        .sb-team {
            margin: 14px 14px 0;
            background: var(--panel-2);
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            padding: 14px;
            display: flex; align-items: center; gap: 10px;
            flex-shrink: 0;
        }
        .sb-team img { width: 40px; height: 40px; border-radius: 9px; object-fit: cover; border: 1px solid var(--border-md); flex-shrink: 0; }
        .sb-team-name { font-size: 13px; font-weight: 600; color: var(--text); line-height: 1.2; }
        .sb-team-league { font-size: 11px; color: var(--red); font-weight: 600; }

        /* Season badge */
        .sb-season {
            margin: 10px 14px 0;
            background: var(--red-soft);
            border: 1px solid var(--border-red);
            border-radius: 8px;
            padding: 8px 12px;
            display: flex; align-items: center; justify-content: space-between;
            flex-shrink: 0;
        }
        .sb-season-label { font-size: 10px; font-weight: 600; letter-spacing: .8px; text-transform: uppercase; color: var(--text-2); }
        .sb-season-val { font-size: 14px; font-weight: 700; color: var(--red); }

        /* Nav */
        .sb-nav { flex: 1; padding: 12px 10px 8px; }
        .sb-section { font-size: 10px; font-weight: 600; letter-spacing: 1.2px; text-transform: uppercase; color: var(--text-3); padding: 12px 10px 6px; }
        .sb-nav a { font-family:'Inter',sans-serif;
            display: flex; align-items: center; gap: 10px;
            padding: 10px 10px; border-radius: var(--radius-sm);
            color: var(--text-2); font-size: 13px; font-weight: 500;
            text-decoration: none; margin-bottom: 2px;
            transition: all var(--t) var(--ease);
        }
        .sb-nav a i { font-size: 15px; width: 18px; text-align: center; flex-shrink: 0; }
        .sb-nav a:hover { background: var(--panel-2); color: var(--text); }
        .sb-nav a.active { background: var(--red-soft); color: var(--red); font-weight: 600; }
        .sb-nav a.active i { color: var(--red); }

        .sb-theme-toggle {
            margin: 0 14px 12px;
            padding: 8px 10px;
            border-radius: 10px;
            border: 1px solid var(--border);
            background: var(--panel-2);
            color: var(--text);
            display: flex; align-items: center; justify-content: center; gap: 8px;
            font-size: 12px; font-weight: 600;
            cursor: pointer;
            transition: all var(--t) var(--ease);
        }
        .sb-theme-toggle:hover { border-color: var(--border-red); color: var(--red); }

        /* Footer */
        .sb-footer {
            padding: 12px 14px;
            border-top: 1px solid var(--border);
            display: flex; align-items: center; gap: 10px;
            flex-shrink: 0;
        }
        .sb-avatar { width: 30px; height: 30px; border-radius: 50%; object-fit: cover; border: 1px solid var(--border-md); flex-shrink: 0; }
        .sb-username { font-size: 12px; font-weight: 500; color: var(--text); flex: 1; min-width: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .sb-logout {
            width: 26px; height: 26px; border-radius: 7px;
            background: transparent; border: 1px solid var(--border);
            color: var(--text-2); display: flex; align-items: center; justify-content: center;
            font-size: 12px; cursor: pointer; transition: all var(--t) var(--ease);
            text-decoration: none; flex-shrink: 0;
        }
        .sb-logout:hover { background: var(--red-soft); border-color: var(--red); color: var(--red); }

        /* ── Topbar mobile ───────────────────────────── */
        .topbar {
            display: none; position: fixed; top: 0; left: 0; right: 0;
            height: 54px; background: var(--panel);
            border-bottom: 1px solid var(--border);
            align-items: center; padding: 0 16px; gap: 12px; z-index: 240;
        }
        .topbar-title { font-weight: 700; font-size: 15px; flex: 1; }
        .topbar-title em { color: var(--red); font-style: normal; }
        .menu-btn {
            width: 34px; height: 34px; border-radius: 9px;
            background: var(--panel-2); border: 1px solid var(--border);
            color: var(--text); display: flex; align-items: center; justify-content: center;
            cursor: pointer; font-size: 17px;
        }
        .sb-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.65); backdrop-filter: blur(4px); z-index: 250; }
        .sb-overlay.show { display: block; }

        /* ── Main ────────────────────────────────────── */
        .main {
            margin-left: var(--sidebar-w);
            min-height: 100vh;
            width: calc(100% - var(--sidebar-w));
            display: flex; flex-direction: column;
        }

        /* ── Hero header ─────────────────────────────── */
        .dash-hero {
            padding: 32px 32px 0;
            display: flex; align-items: flex-start; justify-content: space-between;
            gap: 16px; flex-wrap: wrap;
        }
        .dash-eyebrow { font-size: 11px; font-weight: 600; letter-spacing: 1.4px; text-transform: uppercase; color: var(--red); margin-bottom: 4px; }
        .dash-title { font-size: 26px; font-weight: 800; line-height: 1.1; }
        .dash-sub { font-size: 13px; color: var(--text-2); margin-top: 4px; }

        .hero-badges { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; padding-top: 4px; }
        .hbadge {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 6px 12px; border-radius: 999px;
            font-size: 12px; font-weight: 600;
            border: 1px solid var(--border-md);
            background: var(--panel);
        }
        .hbadge.red { background: var(--red-soft); border-color: var(--border-red); color: var(--red); }
        .hbadge.green { background: rgba(34,197,94,.10); border-color: rgba(34,197,94,.2); color: var(--green); }
        .hbadge.amber { background: rgba(245,158,11,.10); border-color: rgba(245,158,11,.2); color: var(--amber); }

        /* ── Alert banner (deadline) ─────────────────── */
        .deadline-banner {
            margin: 20px 32px 0;
            background: linear-gradient(90deg, color-mix(in srgb, var(--red) 12%, transparent), color-mix(in srgb, var(--red) 4%, transparent));
            border: 1px solid var(--border-red);
            border-left: 3px solid var(--red);
            border-radius: var(--radius-sm);
            padding: 14px 18px;
            display: flex; align-items: center; justify-content: space-between; gap: 12px;
            flex-wrap: wrap;
        }
        .deadline-left { display: flex; align-items: center; gap: 12px; }
        .deadline-icon { width: 36px; height: 36px; border-radius: 9px; background: var(--red); display: flex; align-items: center; justify-content: center; color: #fff; font-size: 16px; flex-shrink: 0; }
        .deadline-title { font-size: 14px; font-weight: 700; color: var(--text); }
        .deadline-sub { font-size: 12px; color: var(--text-2); }
        .deadline-sub strong { color: var(--red); }
        .deadline-btn {
            padding: 8px 16px; border-radius: 8px;
            background: var(--red); border: none; color: #fff;
            font-family: var(--font); font-size: 12px; font-weight: 600;
            cursor: pointer; transition: filter var(--t) var(--ease);
            text-decoration: none; white-space: nowrap;
            display: inline-flex; align-items: center; gap: 6px;
        }
        .deadline-btn:hover { filter: brightness(1.1); color: #fff; }

        /* ── Draft live banner ───────────────────────── */
        .draft-banner {
            margin: 12px 32px 0;
            background: linear-gradient(90deg, rgba(59,130,246,.12), rgba(59,130,246,.04));
            border: 1px solid rgba(59,130,246,.25);
            border-left: 3px solid var(--blue);
            border-radius: var(--radius-sm);
            padding: 14px 18px;
            display: flex; align-items: center; justify-content: space-between; gap: 12px;
            flex-wrap: wrap;
        }
        .draft-banner-left { display: flex; align-items: center; gap: 12px; }
        .draft-banner-avatar { width: 40px; height: 40px; border-radius: 50%; object-fit: cover; border: 2px solid var(--blue); flex-shrink: 0; }
        .draft-badge { display: inline-flex; align-items: center; gap: 6px; padding: 4px 8px; border-radius: 999px; background: rgba(59,130,246,.15); border: 1px solid rgba(59,130,246,.3); color: #93c5fd; font-size: 10px; font-weight: 700; letter-spacing: .5px; text-transform: uppercase; }
        .draft-banner-title { font-size: 14px; font-weight: 700; }
        .draft-banner-sub { font-size: 12px; color: var(--text-2); }

        /* ── Content grid ────────────────────────────── */
        .content { padding: 20px 32px 40px; flex: 1; }

        /* ── Atalhos ──────────────────────────────────── */
        /* ── Precisa de você ─────────────────────────────────────────── */
        .pend-bloco { margin-bottom: 22px; }
        .pend-titulo { display: flex; align-items: center; gap: 8px; font-size: 11px; font-weight: 800;
            letter-spacing: 1.2px; text-transform: uppercase; color: var(--text-3); margin-bottom: 12px; }
        .pend-titulo i { color: var(--red); font-size: 13px; }
        .pend-contador { background: var(--red); color: #fff; font-size: 10.5px; font-weight: 800;
            border-radius: 20px; padding: 1px 8px; letter-spacing: 0; }
        .pend-lista { display: flex; flex-direction: column; gap: 8px; }
        .pend-item { display: flex; align-items: center; gap: 13px; text-decoration: none;
            background: var(--panel); border: 1px solid var(--border); border-left: 3px solid var(--border-md);
            border-radius: var(--radius-sm); padding: 13px 16px; transition: all var(--t) var(--ease); }
        .pend-item:hover { border-color: var(--border-md); transform: translateX(2px); }
        /* A cor da borda esquerda é o que separa "tem relógio correndo" de
           "resolve quando puder" sem precisar ler nada. */
        .pend-item.alta  { border-left-color: var(--red); }
        .pend-item.media { border-left-color: var(--amber); }
        .pend-item.baixa { border-left-color: var(--text-3); }
        .pend-ico { width: 34px; height: 34px; border-radius: 10px; background: var(--panel-2);
            display: flex; align-items: center; justify-content: center; font-size: 15px; flex-shrink: 0; }
        .pend-item.alta  .pend-ico { color: var(--red);   background: var(--red-soft); }
        .pend-item.media .pend-ico { color: var(--amber); background: rgba(245,158,11,.10); }
        .pend-item.baixa .pend-ico { color: var(--text-2); }
        .pend-txt { flex: 1; min-width: 0; }
        .pend-item-titulo { font-size: 13.5px; font-weight: 700; color: var(--text); }
        .pend-item-sub { font-size: 11.5px; color: var(--text-3); margin-top: 1px; }
        .pend-prazo { display: inline-flex; align-items: center; gap: 5px; flex-shrink: 0;
            font-size: 11px; font-weight: 700; color: var(--amber);
            background: rgba(245,158,11,.10); border: 1px solid rgba(245,158,11,.28);
            border-radius: 20px; padding: 3px 10px; white-space: nowrap; }
        .pend-seta { color: var(--text-3); font-size: 13px; flex-shrink: 0; }
        .pend-vazio { display: flex; align-items: center; gap: 13px; background: var(--panel);
            border: 1px solid rgba(34,197,94,.22); border-left: 3px solid var(--green);
            border-radius: var(--radius-sm); padding: 13px 16px; }
        .pend-vazio > i { color: var(--green); font-size: 18px; flex-shrink: 0; }
        .pend-vazio b { display: block; font-size: 13.5px; font-weight: 700; color: var(--text); }
        .pend-vazio span { font-size: 11.5px; color: var(--text-3); }
        @media (max-width: 640px) {
            .pend-item { padding: 12px 13px; gap: 10px; }
            .pend-prazo { display: none; }
        }

        .shortcuts-row { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-bottom: 20px; }
        .shortcut-tile {
            display: flex; align-items: center; gap: 12px;
            background: var(--panel); border: 1px solid var(--border); border-radius: var(--radius);
            padding: 14px 16px; text-decoration: none; color: var(--text);
            transition: all var(--t) var(--ease);
        }
        .shortcut-tile:hover { border-color: var(--border-red); background: var(--panel-2); transform: translateY(-1px); }
        .shortcut-tile-icon {
            width: 38px; height: 38px; border-radius: 10px; flex-shrink: 0;
            background: var(--red-soft); color: var(--red);
            display: flex; align-items: center; justify-content: center; font-size: 17px;
        }
        .shortcut-tile span { font-size: 13px; font-weight: 600; }
        @media (max-width: 768px) { .shortcuts-row { grid-template-columns: repeat(2, 1fr); } }

        /* ── Banner de posição na liga ───────────────── */
        .rank-banner {
            display: flex; align-items: center; gap: 18px; flex-wrap: wrap;
            background: linear-gradient(135deg, color-mix(in srgb, var(--red) 8%, var(--panel)), var(--panel));
            border: 1px solid var(--border-red); border-radius: var(--radius);
            padding: 16px 20px; margin-bottom: 20px; text-decoration: none; color: var(--text);
            transition: border-color var(--t) var(--ease);
        }
        .rank-banner:hover { border-color: var(--red); }
        .rank-banner-main { display: flex; align-items: center; gap: 14px; }
        .rank-banner-num { font-size: 34px; font-weight: 900; line-height: 1; color: var(--red); font-variant-numeric: tabular-nums; }
        .rank-banner-label { font-size: 11px; font-weight: 700; letter-spacing: 1px; text-transform: uppercase; color: var(--text-3); }
        .rank-banner-sub { font-size: 13px; color: var(--text-2); margin-top: 2px; }
        .rank-banner-sep { width: 1px; align-self: stretch; background: var(--border); }
        .rank-banner-side { display: flex; align-items: center; gap: 10px; }
        .rank-banner-pos { display: flex; flex-direction: column; align-items: center; justify-content: center; min-width: 52px; height: 52px; border-radius: 12px; background: var(--red-soft); border: 1px solid var(--border-red); }
        .rank-banner-pos b { font-size: 18px; font-weight: 800; color: var(--red); line-height: 1; }
        .rank-banner-pos span { font-size: 9px; color: var(--red); opacity: .8; font-weight: 700; }
        .rank-banner-arrow { margin-left: auto; color: var(--text-3); font-size: 18px; }
        @media (max-width: 560px) {
            .rank-banner { gap: 12px; padding: 14px 16px; }
            .rank-banner-sep { display: none; }
            .rank-banner-arrow { display: none; }
        }

        /* ── Stat cards row ──────────────────────────── */
        .stats-row { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-bottom: 20px; }

        .stat-c {
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 18px 20px;
            position: relative;
            overflow: hidden;
            transition: border-color var(--t) var(--ease), transform var(--t) var(--ease);
            text-decoration: none;
            display: block;
        }
        .stat-c::before {
            content: ''; position: absolute; top: 0; left: 0; right: 0; height: 2px;
            background: var(--accent, var(--red));
            opacity: 0; transition: opacity var(--t) var(--ease);
        }
        .stat-c:hover { border-color: var(--border-md); transform: translateY(-2px); }
        .stat-c:hover::before { opacity: 1; }
        .stat-c-top { display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 14px; }
        .stat-c-icon { width: 36px; height: 36px; border-radius: 9px; display: flex; align-items: center; justify-content: center; font-size: 16px; flex-shrink: 0; }
        .stat-c-val { font-size: 28px; font-weight: 800; line-height: 1; color: var(--text); }
        .stat-c-label { font-size: 11px; font-weight: 600; letter-spacing: .5px; text-transform: uppercase; color: var(--text-2); margin-bottom: 6px; }
        .stat-c-note { font-size: 11px; color: var(--text-3); }
        .stat-c-bar { height: 3px; background: var(--panel-3); border-radius: 999px; overflow: hidden; margin-top: 8px; }
        .stat-c-fill { height: 100%; border-radius: 999px; background: var(--accent, var(--red)); transition: width .6s var(--ease); }
        .stat-c.warn .stat-c-val { color: #ef4444; }
        .stat-c.ok .stat-c-val { color: var(--green); }

        /* ── Main bento grid ─────────────────────────── */
        .bento { display: grid; grid-template-columns: 1fr 1fr 1fr; grid-template-rows: auto; gap: 14px; }

        /* card base */
        .bc {
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            overflow: hidden;
            display: flex; flex-direction: column;
        }
        .bc-head {
            padding: 16px 18px 14px;
            border-bottom: 1px solid var(--border);
            display: flex; align-items: center; justify-content: space-between; gap: 8px;
            flex-shrink: 0;
        }
        .bc-title { font-size: 13px; font-weight: 700; display: flex; align-items: center; gap: 8px; }
        .bc-title i { color: var(--red); font-size: 15px; }
        .bc-body { padding: 16px 18px; flex: 1; }
        .bc-foot { padding: 12px 18px; border-top: 1px solid var(--border); flex-shrink: 0; }

        .bc-link {
            font-size: 11px; font-weight: 600; letter-spacing: .3px;
            color: var(--text-2); text-decoration: none;
            display: inline-flex; align-items: center; gap: 4px;
            transition: color var(--t) var(--ease);
        }
        .bc-link:hover { color: var(--red); }

        /* ── Sorteios abertos na liga (roletas / drafts aleatórios) ── */
        .sorteio-lista { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 10px; }
        .sorteio-item {
            display: flex; align-items: center; gap: 12px; text-decoration: none;
            background: var(--panel-2); border: 1px solid var(--border);
            border-radius: var(--radius-sm); padding: 12px 14px;
            transition: border-color var(--t) var(--ease), background var(--t) var(--ease);
        }
        .sorteio-item:hover { border-color: var(--border-red); background: var(--panel-3); }
        .sorteio-icone {
            width: 36px; height: 36px; flex-shrink: 0; border-radius: 10px;
            background: var(--red-soft); color: var(--red);
            display: flex; align-items: center; justify-content: center; font-size: 16px;
        }
        .sorteio-txt { flex: 1; min-width: 0; }
        .sorteio-nome {
            font-size: 13px; font-weight: 700; color: var(--text);
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .sorteio-sub { font-size: 11px; color: var(--text-2); margin-top: 1px; }
        .sorteio-barra { height: 3px; border-radius: 999px; background: var(--panel-3); margin-top: 7px; overflow: hidden; }
        .sorteio-barra > div { height: 100%; background: var(--red); border-radius: 999px; }
        .sorteio-seta { color: var(--text-3); font-size: 14px; flex-shrink: 0; }
        .sorteio-item:hover .sorteio-seta { color: var(--red); }

        /* ── Queridômetro da liga ── */
        .querido-lista { display: flex; flex-direction: column; gap: 10px; }
        .querido-row {
            display: flex; align-items: center; gap: 14px; flex-wrap: wrap;
            padding: 10px 12px; background: var(--panel-2); border: 1px solid var(--border);
            border-radius: var(--radius-sm);
        }
        .querido-cat {
            flex-shrink: 0; width: 92px; font-size: 12px; font-weight: 800;
            padding: 5px 0; text-align: center; border-radius: 999px;
            display: flex; align-items: center; justify-content: center; gap: 4px;
        }
        .querido-cat i { font-size: 12px; opacity: .65; }
        .querido-cat-mvp { color: var(--green); }
        .querido-cat-mip { color: var(--blue); }
        .querido-cat-air_ball { color: #ef4444; }
        .querido-cat-cobra { color: var(--purple); }
        .querido-cat-planta { color: var(--amber); }
        .querido-top3 { flex: 1; min-width: 0; display: flex; flex-wrap: wrap; gap: 8px; }
        .querido-vazio { font-size: 12px; color: var(--text-3); }
        .querido-chip {
            display: flex; align-items: center; gap: 6px; background: var(--panel-3);
            border: 1px solid var(--border); border-radius: 999px; padding: 4px 10px 4px 4px;
        }
        .querido-chip img { width: 20px; height: 20px; border-radius: 50%; object-fit: cover; flex-shrink: 0; }
        .querido-nome { font-size: 11.5px; font-weight: 600; color: var(--text); white-space: nowrap; }
        .querido-votos {
            font-size: 10.5px; font-weight: 800; color: var(--text-2); background: var(--panel);
            border-radius: 999px; padding: 1px 6px;
        }
        .querido-btn {
            background: none; border: 1px solid var(--border-md); color: var(--text-2);
            font-size: 11px; font-weight: 600; letter-spacing: .3px; border-radius: 999px;
            padding: 5px 12px; cursor: pointer; display: inline-flex; align-items: center; gap: 4px;
            transition: color var(--t) var(--ease), border-color var(--t) var(--ease);
        }
        .querido-btn:hover { color: var(--red); border-color: var(--border-red); }

        .quer-overlay { position: fixed; inset: 0; z-index: 5100; background: rgba(0,0,0,.72); backdrop-filter: blur(4px);
            display: flex; align-items: flex-start; justify-content: center; padding: 24px 16px; overflow-y: auto; }
        .quer-box { width: 100%; max-width: 540px; background: var(--panel); border: 1px solid var(--border-md);
            border-radius: var(--radius); padding: 26px 24px; box-shadow: 0 30px 80px -30px rgba(0,0,0,.8); margin: auto; position: relative; }
        .quer-close { position: absolute; top: 14px; right: 14px; width: 30px; height: 30px; border-radius: 50%;
            background: var(--panel-2); border: 1px solid var(--border); color: var(--text-2); cursor: pointer;
            display: flex; align-items: center; justify-content: center; font-size: 14px; }
        .quer-close:hover { color: var(--text); }
        .quer-head { display: flex; align-items: center; gap: 12px; margin-bottom: 6px; }
        .quer-icon { width: 44px; height: 44px; flex-shrink: 0; border-radius: 50%; background: var(--red-soft); color: var(--red);
            display: flex; align-items: center; justify-content: center; font-size: 20px; }
        .quer-box h2 { font-size: 18px; font-weight: 800; margin: 0; }
        .quer-sub { font-size: 13px; color: var(--text-2); line-height: 1.6; margin: 10px 0 18px; }
        .quer-field { display: flex; flex-direction: column; gap: 5px; margin-bottom: 12px; }
        .quer-field label { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; color: var(--text-3); }
        .quer-field select { background: var(--panel-2); border: 1px solid var(--border-md); color: var(--text);
            border-radius: var(--radius-sm); padding: 10px 12px; font-family: var(--font); font-size: 13.5px; width: 100%; }
        .quer-field select:focus { outline: none; border-color: var(--red); }
        .quer-erro { margin-top: 4px; padding: 10px 13px; border-radius: var(--radius-sm); font-size: 12.5px; display: none;
            background: rgba(239,68,68,.10); border: 1px solid rgba(239,68,68,.30); color: #ef4444; }
        .quer-actions { margin-top: 12px; }
        .quer-actions button { width: 100%; padding: 12px 16px; border-radius: var(--radius-sm); background: var(--red); border: none;
            color: #fff; font-family: var(--font); font-size: 14px; font-weight: 700; cursor: pointer; }
        .quer-actions button:disabled { opacity: .6; cursor: not-allowed; }
        .quer-ok { text-align: center; padding: 10px 0 4px; }
        .quer-ok i { font-size: 34px; color: var(--green); margin-bottom: 10px; display: block; }

        /* ── Último post da Timeline ── */
        .tl-post {
            display: flex; align-items: flex-start; gap: 12px; text-decoration: none;
            background: var(--panel-2); border: 1px solid var(--border);
            border-radius: var(--radius-sm); padding: 14px;
            transition: border-color var(--t) var(--ease), background var(--t) var(--ease);
        }
        .tl-post:hover { border-color: var(--border-red); background: var(--panel-3); }
        .tl-post-foto {
            width: 40px; height: 40px; flex-shrink: 0; border-radius: 50%;
            object-fit: cover; border: 1px solid var(--border-md);
        }
        .tl-post-txt { flex: 1; min-width: 0; }
        .tl-post-topo { display: flex; align-items: baseline; gap: 8px; flex-wrap: wrap; }
        .tl-post-autor { font-size: 13px; font-weight: 700; color: var(--text); }
        .tl-post-time { font-size: 11.5px; color: var(--text-2); }
        .tl-post-quando { font-size: 11px; color: var(--text-3); margin-left: auto; flex-shrink: 0; }
        .tl-post-corpo {
            font-size: 13px; line-height: 1.5; color: var(--text-2); margin: 6px 0 0;
            overflow-wrap: anywhere;
        }
        .tl-post-pe { display: flex; align-items: center; gap: 14px; margin-top: 8px; font-size: 11px; color: var(--text-3); }
        .tl-post-pe i { color: var(--red); }
        .tl-post-dica i { color: var(--text-3); }
        .tl-post-img {
            width: 96px; height: 96px; flex-shrink: 0; border-radius: var(--radius-xs);
            object-fit: cover; border: 1px solid var(--border);
        }
        @media (max-width: 560px) {
            .tl-post-img { width: 64px; height: 64px; }
            .tl-post-quando { margin-left: 0; }
        }

        /* ── Vídeos da liga (Progression / Sistemas / Free Agency) ── */
        .prog-video-wrap { position: relative; width: 100%; aspect-ratio: 16/9; border-radius: 10px; overflow: hidden; background: #000; }
        .prog-video-wrap:fullscreen { aspect-ratio: unset; border-radius: 0; }

        /* ── Player próprio por cima do YouTube ──
           O iframe fica no fundo com os controles deles desligados; o .yt-escudo
           cobre a área toda e engole o mouse, então o YouTube nunca recebe hover
           e nunca mostra título, "Mais vídeos" nem a marca. Pausado ou no fim, a
           .yt-capa tampa tudo — é ali que a interface deles apareceria. */
        /* O YouTube escolhe a resolução pelo tamanho em que o player está
           desenhado — num card de ~270px ele entrega 240p e a imagem fica
           borrada. Então o iframe é sempre montado em 1280x720 e reduzido por
           transform: o YouTube vê um player grande e manda HD, e o HD reduzido
           fica bem mais nítido que um SD esticado. --yt-escala é recalculada
           pelo JS a cada mudança de tamanho (inclusive ao abrir o popup). */
        .yt-wrap .yt-mount, .yt-wrap iframe {
            position: absolute; top: 0; left: 0; border: 0;
            width: 1280px; height: 720px;
            transform-origin: 0 0;
            transform: scale(var(--yt-escala, 1));
        }
        .yt-escudo { position: absolute; inset: 0; z-index: 2; cursor: pointer; background: transparent; }
        .yt-capa {
            position: absolute; inset: 0; z-index: 3; display: none;
            align-items: center; justify-content: center;
            background: #000; cursor: pointer;
        }
        .yt-capa.on { display: flex; }
        .yt-play-grande {
            width: 62px; height: 62px; border-radius: 50%; cursor: pointer;
            background: var(--red); border: 0; color: #fff; font-size: 28px;
            display: flex; align-items: center; justify-content: center;
            box-shadow: 0 8px 28px rgba(0,0,0,.5); transition: transform .15s var(--ease);
        }
        .yt-capa:hover .yt-play-grande { transform: scale(1.08); }
        .yt-barra {
            position: absolute; left: 0; right: 0; bottom: 0; z-index: 4;
            display: flex; align-items: center; gap: 10px; padding: 10px 12px;
            background: linear-gradient(to top, rgba(0,0,0,.85), rgba(0,0,0,0));
            opacity: 0; transition: opacity var(--t) var(--ease); pointer-events: none;
        }
        .yt-wrap:hover .yt-barra, .yt-wrap.pausado .yt-barra { opacity: 1; pointer-events: auto; }
        .yt-wrap.carregando .yt-barra { opacity: 0 !important; }
        .yt-btn {
            background: transparent; border: 0; color: #fff; cursor: pointer;
            font-size: 17px; line-height: 1; padding: 2px 4px; flex-shrink: 0;
            display: flex; align-items: center; transition: color .15s;
        }
        .yt-btn:hover { color: var(--red); }
        .yt-trilha {
            position: relative; flex: 1; height: 5px; border-radius: 999px; cursor: pointer;
            background: rgba(255,255,255,.22); overflow: hidden;
        }
        .yt-buffer   { position: absolute; inset: 0 auto 0 0; width: 0; background: rgba(255,255,255,.18); }
        .yt-preenche { position: absolute; inset: 0 auto 0 0; width: 0; background: var(--red); }
        .yt-tempo {
            font-size: 11.5px; font-weight: 600; color: #fff; flex-shrink: 0;
            font-variant-numeric: tabular-nums; letter-spacing: .2px;
        }

        /* Tela cheia como popup: o próprio card do vídeo vira uma janela
           centralizada. Fazer assim, em vez de mover o elemento pra dentro de
           um modal, é o que mantém o iframe do YouTube tocando — trocar o pai
           de um iframe recarrega ele e o vídeo volta pro começo. E como o
           popup É o card, a captura recorta exatamente ele. */
        .vid-pop-fundo { position: fixed; inset: 0; background: rgba(0,0,0,.88); z-index: 900; display: none; }
        .vid-pop-fundo.on { display: block; }
        /* Qualquer ancestral com transform vira o "viewport" de um filho
           position:fixed — e o card .bc tem a animação fadeUp, que deixa um
           transform residual. Sem zerar isso, o popup abria descentralizado
           (ancorado no card, não na tela). */
        .vid-pop-solto { transform: none !important; animation: none !important; filter: none !important; }
        .prog-video-wrap.vid-pop {
            position: fixed; z-index: 901;
            top: 50%; left: 50%; transform: translate(-50%, -50%);
            /* A largura já respeita a altura disponível (78vh × 16/9), então a
               caixa fica exatamente 16:9 — com max-height a altura era cortada
               e sobrava tarja preta dos lados. */
            width: min(94vw, 1280px, calc(78vh * 16 / 9)); aspect-ratio: 16/9;
            border-radius: 12px; box-shadow: 0 30px 90px rgba(0,0,0,.65);
        }
        .vid-pop-barra {
            position: fixed; z-index: 902; left: 50%; transform: translateX(-50%);
            bottom: max(18px, calc(11vh - 46px));
            display: none; align-items: center; gap: 10px;
            background: var(--panel); border: 1px solid var(--border-md);
            border-radius: 999px; padding: 8px 10px; box-shadow: 0 12px 30px rgba(0,0,0,.5);
        }
        .vid-pop-barra.on { display: flex; }
        .vid-pop-barra button {
            display: inline-flex; align-items: center; gap: 7px;
            background: transparent; border: 1px solid var(--border-md); color: var(--text-2);
            border-radius: 999px; padding: 8px 15px; font-family: var(--font);
            font-size: 12.5px; font-weight: 700; cursor: pointer; transition: all .15s;
        }
        .vid-pop-barra button:hover { border-color: var(--red); color: var(--red); background: var(--red-soft); }
        .vid-pop-barra button.ok { border-color: var(--green); color: var(--green); }
        .vid-pop-titulo { font-size: 12px; color: var(--text-3); padding: 0 6px 0 10px; max-width: 240px;
            overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .prog-video { position: absolute; inset: 0; width: 100%; height: 100%; border: 0; }
        video.prog-video { object-fit: contain; }
        .prog-video-actions { display: flex; align-items: center; gap: 8px; margin-top: 10px; }
        .vid-action-btn {
            width: 34px; height: 34px; flex-shrink: 0;
            display: flex; align-items: center; justify-content: center;
            background: var(--panel-2); border: 1px solid var(--border-md); border-radius: 9px;
            color: var(--text-2); font-size: 14px; cursor: pointer; text-decoration: none;
            transition: all var(--t) var(--ease);
        }
        .vid-action-btn:hover { border-color: var(--red); color: var(--red); background: var(--panel-3); }
        .vid-action-btn.copied { background: var(--green); border-color: var(--green); color: #fff; }
        /* No celular o print sai fora: getDisplayMedia não existe em navegador
           móvel, então o botão só levaria a um erro. Print de tela nativo
           resolve melhor ali. */
        @media (max-width: 768px), (pointer: coarse) {
            .vid-action-btn[data-action="capture"], #vidPopCapturar { display: none !important; }
        }
        .prog-video-placeholder { background: var(--panel-2); border: 1px dashed var(--border-md); }
        .prog-placeholder-inner { position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 8px; color: var(--text-3); }
        .prog-placeholder-inner i { font-size: 26px; }
        .prog-placeholder-inner span { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .1em; }

        /* ── Watchlist (De olho em...) ───────────────── */
        .watch-row { display: flex; align-items: center; gap: 10px; padding: 8px 0; border-bottom: 1px solid var(--border); text-decoration: none; color: var(--text); transition: background var(--t) var(--ease); }
        .watch-row:last-child { border-bottom: none; }
        .watch-row:hover { background: var(--panel-2); }
        .watch-photo { width: 34px; height: 34px; border-radius: 50%; object-fit: cover; border: 1px solid var(--border-md); background: var(--panel-3); flex-shrink: 0; }
        .watch-name { font-size: 13px; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .watch-meta { font-size: 11px; color: var(--text-2); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .watch-ovr { font-family: 'Oswald', 'Montserrat', sans-serif; font-size: 16px; font-weight: 700; color: var(--red); flex-shrink: 0; }

        /* ── Starters card (full width) ──────────────── */
        .span-3 { grid-column: span 3; }
        .span-2 { grid-column: span 2; }

        .starters-grid { display: flex; gap: 12px; flex-wrap: wrap; }
        .starter-chip {
            background: var(--panel-2);
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            padding: 12px 14px;
            display: flex; align-items: center; gap: 12px;
            flex: 1; min-width: 160px;
            transition: border-color var(--t) var(--ease);
        }
        .starter-chip:hover { border-color: var(--border-red); }
        .starter-photo { width: 44px; height: 44px; border-radius: 50%; object-fit: cover; border: 2px solid var(--border-md); background: var(--panel-3); flex-shrink: 0; }
        .starter-pos { display: inline-flex; align-items: center; justify-content: center; width: 24px; height: 18px; border-radius: 4px; background: var(--red-soft); color: var(--red); font-size: 9px; font-weight: 800; letter-spacing: .3px; text-transform: uppercase; }
        .starter-name { font-size: 13px; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .starter-ovr { font-size: 12px; color: var(--text-2); }

        /* ── Ranking list ────────────────────────────── */
        .rank-row {
            display: flex; align-items: center; gap: 10px;
            padding: 10px 0;
            border-bottom: 1px solid var(--border);
            transition: all var(--t) var(--ease);
        }
        .rank-row:last-child { border-bottom: none; }
        .rank-row:hover { transform: translateX(3px); }
        .rank-num { width: 22px; font-size: 12px; font-weight: 800; color: var(--text-3); text-align: center; flex-shrink: 0; }
        .rank-num.gold { color: #f59e0b; }
        .rank-num.silver { color: #94a3b8; }
        .rank-num.bronze { color: #cd7c4a; }
        .rank-logo { width: 28px; height: 28px; border-radius: 7px; object-fit: cover; border: 1px solid var(--border-md); flex-shrink: 0; }
        .rank-name { flex: 1; min-width: 0; }
        .rank-team { font-size: 12px; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .rank-owner { font-size: 11px; color: var(--text-2); }
        .rank-pts { font-size: 14px; font-weight: 800; color: var(--red); }
        .rank-pts-label { font-size: 10px; color: var(--text-3); }
        .rank-row.me { background: var(--red-soft); border-radius: 8px; padding: 10px 8px; margin: 0 -8px; border-bottom: none; }

        /* ── Rumor card ──────────────────────────────── */
        .rumor-bubble {
            background: var(--panel-2);
            border: 1px solid var(--border);
            border-radius: 14px 14px 14px 4px;
            padding: 14px 16px;
            font-size: 13px; line-height: 1.55; color: var(--text);
        }
        .rumor-meta { display: flex; align-items: center; gap: 8px; margin-bottom: 12px; }
        .rumor-avatar { width: 32px; height: 32px; border-radius: 50%; object-fit: cover; border: 1px solid var(--border-md); flex-shrink: 0; }
        .rumor-team { font-size: 13px; font-weight: 600; }
        .rumor-gm { font-size: 11px; color: var(--text-2); }
        .rumor-date { margin-top: 10px; font-size: 11px; color: var(--text-3); display: flex; align-items: center; gap: 4px; }

        /* ── Trade card ──────────────────────────────── */
        .trade-teams { display: flex; align-items: center; gap: 8px; margin-bottom: 14px; }
        .trade-team { flex: 1; text-align: center; }
        .trade-team img { width: 42px; height: 42px; border-radius: 50%; object-fit: cover; border: 2px solid var(--border-md); display: block; margin: 0 auto 4px; }
        .trade-team-name { font-size: 11px; font-weight: 600; color: var(--text); }
        .trade-arrow { font-size: 18px; color: var(--red); flex-shrink: 0; }
        .trade-cols { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
        .trade-col { background: var(--panel-2); border: 1px solid var(--border); border-radius: 9px; padding: 10px 12px; }
        .trade-col-label { font-size: 10px; font-weight: 700; letter-spacing: .5px; text-transform: uppercase; color: var(--text-3); margin-bottom: 8px; }
        .trade-item { display: flex; align-items: center; gap: 6px; font-size: 11px; color: var(--text-2); margin-bottom: 6px; }
        .trade-item i { color: var(--red); font-size: 11px; flex-shrink: 0; }
        .trade-item strong { color: var(--text); }
        .trade-date { font-size: 11px; color: var(--text-3); text-align: center; margin-top: 10px; }

        /* ── League info card ────────────────────────── */
        .league-logo-img { width: 64px; height: 64px; object-fit: contain; display: block; margin: 0 auto 10px; }
        .league-stat-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-top: 12px; }
        .league-stat { background: var(--panel-2); border: 1px solid var(--border); border-radius: 9px; padding: 10px 12px; }
        .league-stat-label { font-size: 10px; font-weight: 600; letter-spacing: .5px; text-transform: uppercase; color: var(--text-3); margin-bottom: 4px; }
        .league-stat-val { font-size: 15px; font-weight: 700; color: var(--text); }

        /* ── Winners card ────────────────────────────── */
        .winner-row {
            display: flex; align-items: center; gap: 10px;
            padding: 10px 12px; border-radius: 9px;
            background: var(--panel-2); border: 1px solid var(--border);
            margin-bottom: 8px;
            transition: all var(--t) var(--ease);
        }
        .winner-row:last-child { margin-bottom: 0; }
        .winner-row:hover { transform: scale(1.01); }
        .winner-row.gold { border-color: rgba(245,158,11,.3); background: rgba(245,158,11,.06); }
        .winner-row.silver { border-color: rgba(148,163,184,.3); }
        .winner-row.mvp { border-color: var(--border-red); background: var(--red-soft); }
        .winner-icon { font-size: 18px; flex-shrink: 0; }
        .winner-logo { width: 32px; height: 32px; border-radius: 50%; object-fit: cover; border: 1px solid var(--border-md); flex-shrink: 0; }
        .winner-title { font-size: 10px; font-weight: 700; letter-spacing: .5px; text-transform: uppercase; color: var(--text-3); }
        .winner-name { font-size: 12px; font-weight: 600; color: var(--text); }
        .winner-owner { font-size: 11px; color: var(--text-2); }

        /* ── Quick actions ───────────────────────────── */
        .quick-grid { display: grid; grid-template-columns: repeat(6, 1fr); gap: 8px; }
        .quick-btn {
            background: var(--panel-2);
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            padding: 14px 12px;
            text-align: center; cursor: pointer;
            text-decoration: none; color: var(--text);
            transition: all var(--t) var(--ease);
            display: block;
        }
        .quick-btn:hover { border-color: var(--border-red); background: var(--red-soft); color: var(--text); transform: translateY(-2px); }
        .quick-btn i { font-size: 20px; color: var(--red); display: block; margin-bottom: 6px; }
        .quick-btn-label { font-size: 11px; font-weight: 600; }

        /* ── Empty states ────────────────────────────── */
        .empty { padding: 24px 16px; text-align: center; color: var(--text-3); }
        .empty i { font-size: 28px; display: block; margin-bottom: 8px; }
        .empty p { font-size: 12px; }

        /* ── Footer strip ────────────────────────────── */
        .footer-strip {
            margin: 0 32px 32px;
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            padding: 12px 18px;
            display: flex; gap: 20px; flex-wrap: wrap; align-items: center;
        }
        .footer-item { font-size: 12px; color: var(--text-2); }
        .footer-item strong { color: var(--text); font-weight: 600; }

        /* ── Animations ──────────────────────────────── */
        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(12px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        .stat-c, .bc { animation: fadeUp .4s var(--ease) both; }

        /* ── Responsive ──────────────────────────────── */
        @media (max-width: 1100px) {
            .stats-row { grid-template-columns: repeat(2, 1fr); }
            .bento { grid-template-columns: 1fr 1fr; }
            .span-3 { grid-column: span 2; }
        }
        @media (max-width: 992px) {
            :root { --sidebar-w: 0px; }
            .sidebar { transform: translateX(-260px); }
            .sidebar.open { transform: translateX(0); }
            .main { margin-left: 0; width: 100%; padding-top: 54px; }
            .topbar { display: flex; }
            .dash-hero, .deadline-banner, .draft-banner, .content, .footer-strip { padding-left: 16px; padding-right: 16px; }
            .footer-strip { margin-left: 16px; margin-right: 16px; }
            .bento { grid-template-columns: 1fr; }
            .span-2, .span-3 { grid-column: span 1; }
            .stats-row { grid-template-columns: 1fr 1fr; }
            .quick-grid { grid-template-columns: repeat(3, 1fr); }
            .dash-hero { padding-top: 18px; }
            /* Queridômetro: em tela estreita o nome não cabe do lado do rótulo
               da categoria, então empilha — rótulo em cima, GMs embaixo. */
            .querido-row { flex-direction: column; align-items: stretch; gap: 8px; }
            .querido-cat { width: auto; justify-content: flex-start; padding: 0; }
            .querido-top3 { width: 100%; }
            .querido-chip { flex: 1 1 auto; min-width: 0; }
            .querido-nome { overflow: hidden; text-overflow: ellipsis; }
        }
        @media (max-width: 480px) {
            .stats-row { grid-template-columns: 1fr 1fr; }
            .starters-grid { gap: 8px; }
            .starter-chip { min-width: calc(50% - 4px); }
        }

        /* Override bootstrap conflicts */
        .badge { font-family: var(--font); }
        a { color: inherit; }

        @media (prefers-reduced-motion: reduce) { *, *::before, *::after { animation-duration: 0.01ms !important; animation-delay: 0ms !important; animation-iteration-count: 1 !important; transition-duration: 0.01ms !important; transition-delay: 0ms !important; scroll-behavior: auto !important; } }
    <?php include __DIR__ . '/includes/accent-color.php'; ?>
    </style>
</head>
<body>
<div class="app">

    <!-- ══════════════════════════════════════════════
         SIDEBAR
    ══════════════════════════════════════════════ -->
    <?php include __DIR__ . '/includes/sidebar.php'; ?>

    <!-- Overlay mobile -->
    <div class="sb-overlay" id="sbOverlay"></div>

    <!-- Topbar mobile -->
    <header class="topbar">
        <button class="menu-btn" id="menuBtn"><i class="bi bi-list"></i></button>
        <div class="topbar-title">FBA <em>Manager</em></div>
        <?php if ($currentSeason): ?>
        <span style="font-size:11px;font-weight:700;color:var(--red)"><?= $seasonDisplayYear ?></span>
        <?php endif; ?>
    </header>

    <!-- ══════════════════════════════════════════════
         MAIN
    ══════════════════════════════════════════════ -->
    <main class="main">

        <!-- Hero header -->
        <div class="dash-hero">
            <div>
                <div class="dash-eyebrow">Dashboard · <?= htmlspecialchars($user['league']) ?></div>
                <h1 class="dash-title">Bem-vindo, <?= htmlspecialchars(explode(' ', $user['name'])[0]) ?> 👋</h1>
                <p class="dash-sub"><?= htmlspecialchars($team['city'] . ' ' . $team['name']) ?></p>
            </div>
            <div class="hero-badges">
                <span class="hbadge green">
                    <i class="bi bi-star-fill" style="font-size:10px"></i>
                    <?= (int)($team['ranking_points'] ?? 0) ?> pts
                </span>
                <span class="hbadge amber">
                    <i class="bi bi-coin" style="font-size:10px"></i>
                    <?= (int)($team['moedas'] ?? 0) ?> moedas
                </span>
                <button id="copyTeamBtn" class="hbadge" style="cursor:pointer;background:var(--panel-2);border-color:var(--border-md)">
                    <i class="bi bi-clipboard-check" style="font-size:10px"></i> Copiar time
                </button>
            </div>
        </div>

        <!-- Aviso de janela de tática fechada -->
        <?php if ($tacticEditClosed): ?>
        <a href="/tatica.php" class="deadline-banner text-decoration-none">
            <div class="deadline-left">
                <div class="deadline-icon"><i class="bi bi-lock-fill"></i></div>
                <div>
                    <div class="deadline-title">Edição de tática fechada</div>
                    <div class="deadline-sub">
                        Reabre às <strong><?= htmlspecialchars($tacticEditReopensAt ?? '') ?></strong> ou quando o admin liberar.
                    </div>
                </div>
            </div>
            <div class="deadline-btn"><i class="bi bi-eye"></i> Ver tática</div>
        </a>
        <?php endif; ?>

        <!-- Draft live banner -->
        <?php if ($activeDraft && $currentDraftPick): ?>
        <div class="draft-banner">
            <div class="draft-banner-left">
                <img class="draft-banner-avatar"
                     src="<?= htmlspecialchars($currentDraftPick['photo_url'] ?? '/img/default-team.png') ?>"
                     alt="" onerror="this.src='/img/default-team.png'">
                <div>
                    <div style="margin-bottom:4px"><span class="draft-badge"><i class="bi bi-broadcast-pin"></i> Draft ao vivo</span></div>
                    <div class="draft-banner-title"><?= htmlspecialchars($currentDraftPick['city'] . ' ' . $currentDraftPick['team_name']) ?> está na vez</div>
                    <div class="draft-banner-sub">
                        <?php if ($currentDraftOverallNumber): ?>Pick #<?= $currentDraftOverallNumber ?> · <?php endif; ?>
                        R<?= (int)$currentDraftPick['round'] ?> · Pick <?= (int)$currentDraftPick['pick_position'] ?> · <?= $remainingDraftPicks ?> restantes
                    </div>
                </div>
            </div>
            <?php if ($activeInitDraftSession && !empty($activeInitDraftSession['access_token'])): ?>
            <a href="/initdraftselecao.php?token=<?= htmlspecialchars($activeInitDraftSession['access_token']) ?>"
               style="padding:8px 14px;border-radius:8px;background:rgba(59,130,246,.15);border:1px solid rgba(59,130,246,.3);color:#93c5fd;font-size:12px;font-weight:600;text-decoration:none;display:inline-flex;align-items:center;gap:6px">
                <i class="bi bi-trophy"></i> Sala do Draft
            </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- ── Content ── -->
        <div class="content">

            <!-- Precisa de você: tudo que tem prazo ou trava alguma coisa,
                 reunido num lugar só. Vem antes de qualquer outra coisa.
                 Aparece mesmo sem pendência: sem isso a pessoa que está em dia
                 nunca descobre que o painel existe, e fica sem a confirmação
                 de que não há nada esperando por ela. -->
            <?php if ($team): ?>
            <div class="pend-bloco">
                <div class="pend-titulo">
                    <i class="bi bi-bell-fill"></i> Precisa de você
                    <?php if (!empty($pendencias)): ?>
                    <span class="pend-contador"><?= count($pendencias) ?></span>
                    <?php endif; ?>
                </div>
                <?php if (empty($pendencias)): ?>
                <div class="pend-vazio">
                    <i class="bi bi-check-circle-fill"></i>
                    <div>
                        <b>Tudo em dia</b>
                        <span>Nenhuma proposta esperando, nenhum prazo correndo e nada travado no seu time.</span>
                    </div>
                </div>
                <?php else: ?>
                <div class="pend-lista">
                    <?php foreach ($pendencias as $p): ?>
                    <a class="pend-item <?= htmlspecialchars($p['urgencia']) ?>" href="<?= htmlspecialchars($p['url']) ?>">
                        <div class="pend-ico"><i class="bi <?= htmlspecialchars($p['icone']) ?>"></i></div>
                        <div class="pend-txt">
                            <div class="pend-item-titulo"><?= htmlspecialchars($p['titulo']) ?></div>
                            <?php if (!empty($p['detalhe'])): ?>
                            <div class="pend-item-sub"><?= htmlspecialchars($p['detalhe']) ?></div>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($p['prazo'])): ?>
                        <span class="pend-prazo"><i class="bi bi-clock"></i><?= htmlspecialchars($p['prazo']) ?></span>
                        <?php endif; ?>
                        <i class="bi bi-chevron-right pend-seta"></i>
                    </a>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- Atalhos -->
            <?php if ($dashboardShortcuts): ?>
            <div class="shortcuts-row">
                <?php foreach ($dashboardShortcuts as $sc): ?>
                <a href="<?= htmlspecialchars($sc['href']) ?>" class="shortcut-tile">
                    <div class="shortcut-tile-icon"><i class="bi <?= htmlspecialchars($sc['icon']) ?>"></i></div>
                    <span><?= htmlspecialchars($sc['label']) ?></span>
                </a>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <!-- Posição na liga -->
            <?php if ($leagueRank && $leagueTeamCount): ?>
            <a href="/rankings.php" class="rank-banner">
                <div class="rank-banner-main">
                    <div class="rank-banner-num"><?= $leagueRank ?>º</div>
                    <div>
                        <div class="rank-banner-label">Sua posição na liga <?= htmlspecialchars($team['league']) ?></div>
                        <div class="rank-banner-sub"><?= (int)($team['ranking_points'] ?? 0) ?> pts · de <?= $leagueTeamCount ?> times</div>
                    </div>
                </div>
                <?php if ($lastSeasonPos): ?>
                <div class="rank-banner-sep"></div>
                <div class="rank-banner-side">
                    <div class="rank-banner-pos">
                        <b><?= (int)$lastSeasonPos['position'] ?>º</b>
                        <?php if (!empty($lastSeasonPos['conference'])): ?><span><?= $lastSeasonPos['conference'] === 'LESTE' ? 'LESTE' : 'OESTE' ?></span><?php endif; ?>
                    </div>
                    <div>
                        <div class="rank-banner-label">Última temporada registrada</div>
                        <div class="rank-banner-sub"><?= $lastSeasonPos['year'] ? htmlspecialchars((string)$lastSeasonPos['year']) : '' ?> · classificação final</div>
                    </div>
                </div>
                <?php endif; ?>
                <i class="bi bi-chevron-right rank-banner-arrow"></i>
            </a>
            <?php endif; ?>

            <!-- Stat cards -->
            <div class="stats-row">
                <a href="/my-roster.php" class="stat-c <?= $playersOutOfRange ? 'warn' : '' ?>"
                   style="--accent:<?= $playersOutOfRange ? '#ef4444' : 'var(--red)' ?>; animation-delay:.05s">
                    <div class="stat-c-top">
                        <div>
                            <div class="stat-c-label">Jogadores</div>
                            <div class="stat-c-val"><?= $totalPlayers ?></div>
                        </div>
                        <div class="stat-c-icon" style="background:color-mix(in srgb, var(--red) 10%, transparent)">
                            <i class="bi bi-people-fill" style="color:var(--red)"></i>
                        </div>
                    </div>
                    <div class="stat-c-note">Min <?= $minPlayers ?> · Max <?= $maxPlayers ?></div>
                    <div class="stat-c-bar"><div class="stat-c-fill" style="width:<?= $playersPct ?>%"></div></div>
                </a>

                <?php if ($salaryCapMode && $salCap):
                    $salColor = $salCap['status'] === 'over_the_cap' ? '#ef4444' : ($salCap['status'] === 'abaixo_do_piso' ? 'var(--amber)' : 'var(--green)');
                    $salStatusTxt = $salCap['status'] === 'over_the_cap' ? 'Acima do teto' : ($salCap['status'] === 'abaixo_do_piso' ? 'Abaixo do piso' : 'Dentro do cap');
                    $salPct = $salCap['cap_max'] > 0 ? min(100, round($salCap['payroll'] / $salCap['cap_max'] * 100)) : 0;
                ?>
                <a href="/cap.php" class="stat-c" style="--accent:<?= $salColor ?>;animation-delay:.1s;text-decoration:none">
                    <div class="stat-c-top">
                        <div>
                            <div class="stat-c-label">Salary Cap</div>
                            <div class="stat-c-val" style="color:<?= $salColor ?>"><?= (int)$salCap['payroll'] ?>M</div>
                        </div>
                        <div class="stat-c-icon" style="background:color-mix(in srgb, <?= $salColor ?> 12%, transparent)">
                            <i class="bi bi-cash-stack" style="color:<?= $salColor ?>"></i>
                        </div>
                    </div>
                    <div class="stat-c-note">Folha <?= (int)$salCap['payroll'] ?>M / <?= (int)$salCap['cap_max'] ?>M · <?= $salStatusTxt ?></div>
                    <div class="stat-c-bar"><div class="stat-c-fill" style="width:<?= $salPct ?>%;background:<?= $salColor ?>"></div></div>
                </a>
                <?php else: ?>
                <div class="stat-c <?= $capOk ? 'ok' : 'warn' ?>"
                     style="--accent:<?= $capOk ? 'var(--green)' : '#ef4444' ?>; animation-delay:.1s">
                    <div class="stat-c-top">
                        <div>
                            <div class="stat-c-label">CAP Top 8</div>
                            <div class="stat-c-val" style="color:<?= $capOk ? 'var(--green)' : '#ef4444' ?>"><?= $teamCap ?></div>
                        </div>
                        <div class="stat-c-icon" style="background:<?= $capOk ? 'rgba(34,197,94,.10)' : 'rgba(239,68,68,.10)' ?>">
                            <i class="bi bi-cash-stack" style="color:<?= $capOk ? 'var(--green)' : '#ef4444' ?>"></i>
                        </div>
                    </div>
                    <div class="stat-c-note">Faixa: <?= $capMin ?> – <?= $capMax ?></div>
                    <div class="stat-c-bar"><div class="stat-c-fill" style="width:<?= $capPct ?>%"></div></div>
                </div>
                <?php endif; ?>

                <a href="/picks.php" class="stat-c" style="--accent:var(--green);animation-delay:.15s">
                    <div class="stat-c-top">
                        <div>
                            <div class="stat-c-label">Picks 1ª Rodada</div>
                            <div class="stat-c-val"><?= $firstRoundPicksCount ?></div>
                        </div>
                        <div class="stat-c-icon" style="background:rgba(34,197,94,.10)">
                            <i class="bi bi-calendar-check-fill" style="color:var(--green)"></i>
                        </div>
                    </div>
                    <div class="stat-c-note"><?= $copySeasonYear ?> em diante</div>
                    <div class="stat-c-bar"><div class="stat-c-fill" style="width:<?= min(100, $firstRoundPicksCount * 20) ?>%"></div></div>
                </a>

                <a href="/trades.php" class="stat-c" style="--accent:var(--blue);animation-delay:.2s">
                    <div class="stat-c-top">
                        <div>
                            <div class="stat-c-label">Trades</div>
                            <div class="stat-c-val"><?= $tradesCount ?><span style="font-size:16px;color:var(--text-3);font-weight:400">/<?= $maxTrades ?></span></div>
                        </div>
                        <div class="stat-c-icon" style="background:rgba(59,130,246,.10)">
                            <i class="bi bi-arrow-left-right" style="color:var(--blue)"></i>
                        </div>
                    </div>
                    <div class="stat-c-note"><?= $tradesEnabled ? 'Trocas ativas' : '<span style="color:#ef4444">Bloqueadas</span>' ?></div>
                    <div class="stat-c-bar"><div class="stat-c-fill" style="width:<?= $tradesPct ?>%"></div></div>
                </a>
            </div>

            <!-- Bento grid -->
            <div class="bento">

                <?php if ($eventosSorteio): ?>
                <!-- ── Sorteios abertos na liga ── -->
                <div class="bc span-3" style="animation-delay:.22s">
                    <div class="bc-head">
                        <div class="bc-title"><i class="bi bi-dice-5-fill"></i> Rolando na <?= htmlspecialchars($ligaDoTime) ?></div>
                    </div>
                    <div class="bc-body">
                        <div class="sorteio-lista">
                            <?php foreach ($eventosSorteio as $ev):
                                $pct = $ev['total'] ? round(($ev['feitas'] / $ev['total']) * 100) : 0; ?>
                            <a class="sorteio-item" href="<?= htmlspecialchars($ev['href']) ?>">
                                <div class="sorteio-icone"><i class="bi <?= $ev['icone'] ?>"></i></div>
                                <div class="sorteio-txt">
                                    <div class="sorteio-nome"><?= htmlspecialchars($ev['titulo']) ?></div>
                                    <div class="sorteio-sub"><?= htmlspecialchars($ev['rotulo']) ?> · <?= $ev['feitas'] ?>/<?= $ev['total'] ?></div>
                                    <div class="sorteio-barra"><div style="width:<?= $pct ?>%"></div></div>
                                </div>
                                <i class="bi bi-arrow-right sorteio-seta"></i>
                            </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($ultimoPost): ?>
                <!-- ── Último post da Timeline ── -->
                <div class="bc span-3" style="animation-delay:.24s">
                    <div class="bc-head">
                        <div class="bc-title"><i class="bi bi-chat-quote-fill"></i> <?= $postDeQuemSigo ? 'Da sua Timeline' : 'Movimento na ' . htmlspecialchars($ligaDoTime) ?></div>
                        <a href="/timeline.php" class="bc-link">Ver timeline <i class="bi bi-arrow-right"></i></a>
                    </div>
                    <div class="bc-body">
                        <a class="tl-post" href="/timeline.php">
                            <img class="tl-post-foto" src="<?= htmlspecialchars($ultimoPost['autor_foto'] ?: ($ultimoPost['time_foto'] ?: '/img/default-team.png')) ?>" alt="">
                            <div class="tl-post-txt">
                                <div class="tl-post-topo">
                                    <span class="tl-post-autor"><?= htmlspecialchars($ultimoPost['autor']) ?></span>
                                    <span class="tl-post-time"><?= htmlspecialchars(trim($ultimoPost['time_cidade'] . ' ' . $ultimoPost['time_nome'])) ?></span>
                                    <span class="tl-post-quando"><?= htmlspecialchars(tempoRelativoCurto($ultimoPost['created_at'])) ?></span>
                                </div>
                                <?php if (trim((string)$ultimoPost['texto']) !== ''): ?>
                                <p class="tl-post-corpo"><?= nl2br(htmlspecialchars(mb_strimwidth(trim($ultimoPost['texto']), 0, 240, '…'))) ?></p>
                                <?php endif; ?>
                                <div class="tl-post-pe">
                                    <?php if ((int)$ultimoPost['curtidas'] > 0): ?>
                                    <span><i class="bi bi-heart-fill"></i> <?= (int)$ultimoPost['curtidas'] ?></span>
                                    <?php endif; ?>
                                    <?php if (!$postDeQuemSigo): ?>
                                    <span class="tl-post-dica"><i class="bi bi-person-plus"></i> Siga times na Timeline pra ver o feed deles aqui</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php if (!empty($ultimoPost['photo_url'])): ?>
                            <img class="tl-post-img" src="<?= htmlspecialchars($ultimoPost['photo_url']) ?>" alt="">
                            <?php endif; ?>
                        </a>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($team && $queridoLeague !== ''): ?>
                <!-- ── Queridômetro da liga ── -->
                <div class="bc span-3" style="animation-delay:.25s">
                    <div class="bc-head">
                        <div class="bc-title"><i class="bi bi-emoji-heart-eyes-fill"></i> Queridômetro da <?= htmlspecialchars($queridoLeague) ?></div>
                        <button type="button" class="querido-btn" onclick="queridoAbrirModal()">
                            <?= $precisaVotarQuerido ? 'Votar' : 'Ver / votar' ?> <i class="bi bi-arrow-right"></i>
                        </button>
                    </div>
                    <div class="bc-body">
                        <div class="querido-lista">
                            <?php $queridoDescricoes = queridometroDescricoes();
                            foreach (queridometroCategorias() as $catKey => $catLabel):
                                $top = $queridoTop3[$catKey] ?? []; ?>
                            <div class="querido-row">
                                <div class="querido-cat querido-cat-<?= strtolower($catKey) ?>">
                                    <?= htmlspecialchars($catLabel) ?>
                                    <span tabindex="0" data-bs-toggle="tooltip" data-bs-placement="top"
                                          title="<?= htmlspecialchars($queridoDescricoes[$catKey] ?? '') ?>"
                                          style="cursor:help"><i class="bi bi-info-circle"></i></span>
                                </div>
                                <div class="querido-top3" id="querido-top3-<?= $catKey ?>">
                                    <?php if (!$top): ?>
                                    <span class="querido-vazio">Sem votos ainda essa temporada</span>
                                    <?php else: foreach ($top as $tt): ?>
                                    <div class="querido-chip">
                                        <img src="<?= htmlspecialchars(getUserPhoto($tt['photo_url'] ?? null)) ?>" alt="" onerror="this.src='/img/default-avatar.png'">
                                        <span class="querido-nome"><?= htmlspecialchars((string)($tt['name'] ?? '')) ?></span>
                                        <span class="querido-votos"><?= (int)$tt['votos'] ?></span>
                                    </div>
                                    <?php endforeach; endif; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <?php foreach ($leagueVideoCards as $vi => $vc): $embed = $vc['embed']; $key = $vc['key']; ?>
                <!-- ── Vídeo: <?= htmlspecialchars($vc['title']) ?> ── -->
                <div class="bc" style="animation-delay:<?= .36 + $vi * .03 ?>s">
                    <div class="bc-head">
                        <div class="bc-title"><i class="bi <?= $vc['icon'] ?>"></i> <?= htmlspecialchars($vc['title']) ?></div>
                    </div>
                    <div class="bc-body">
                        <?php if (!$embed): ?>
                        <div class="prog-video-wrap prog-video-placeholder">
                            <div class="prog-placeholder-inner">
                                <i class="bi bi-hourglass-split"></i>
                                <span>Em breve</span>
                            </div>
                        </div>
                        <?php elseif ($embed['type'] === 'direct'): ?>
                        <div class="prog-video-wrap" id="wrap_<?= $key ?>">
                            <video id="vid_<?= $key ?>" class="prog-video" controls playsinline preload="metadata" crossorigin="anonymous">
                                <source src="<?= htmlspecialchars($embed['embed_url']) ?>">
                            </video>
                        </div>
                        <?php elseif ($embed['type'] === 'youtube'): ?>
                        <?php /* Player próprio: o iframe fica atrás de um escudo que
                                come todo o mouse, então a interface do YouTube nunca
                                é acionada. Controles, progresso e capa são nossos. */ ?>
                        <div class="prog-video-wrap yt-wrap" id="wrap_<?= $key ?>"
                             data-yt="<?= htmlspecialchars($embed['video_id'], ENT_QUOTES, 'UTF-8') ?>"
                             data-key="<?= $key ?>">
                            <div class="yt-mount" id="ytmount_<?= $key ?>"></div>
                            <div class="yt-escudo"></div>
                            <div class="yt-capa on">
                                <button type="button" class="yt-play-grande" aria-label="Reproduzir"><i class="bi bi-play-fill"></i></button>
                            </div>
                            <div class="yt-barra">
                                <button type="button" class="yt-btn yt-toggle" aria-label="Reproduzir"><i class="bi bi-play-fill"></i></button>
                                <div class="yt-trilha"><div class="yt-buffer"></div><div class="yt-preenche"></div></div>
                                <span class="yt-tempo">0:00 / 0:00</span>
                                <button type="button" class="yt-btn yt-mudo" aria-label="Silenciar"><i class="bi bi-volume-up-fill"></i></button>
                            </div>
                        </div>
                        <?php elseif ($embed['type'] === 'iframe'): ?>
                        <div class="prog-video-wrap" id="wrap_<?= $key ?>">
                            <iframe class="prog-video" src="<?= htmlspecialchars($embed['embed_url']) ?>" allow="accelerometer; autoplay; encrypted-media; gyroscope; picture-in-picture" allowfullscreen loading="lazy" title="<?= htmlspecialchars($vc['title']) ?>"></iframe>
                        </div>
                        <?php endif; ?>

                        <?php if ($embed && in_array($embed['type'], ['direct', 'iframe', 'youtube'], true)): ?>
                        <div class="prog-video-actions">
                            <button type="button" class="vid-action-btn" data-action="fullscreen" data-key="<?= $key ?>" data-titulo="<?= htmlspecialchars($vc['title'], ENT_QUOTES, 'UTF-8') ?>" title="Abrir grande"><i class="bi bi-arrows-fullscreen"></i></button>
                            <button type="button" class="vid-action-btn" data-action="capture" data-key="<?= $key ?>" title="Tirar print do vídeo"><i class="bi bi-camera-fill"></i></button>
                            <a class="vid-action-btn" href="<?= htmlspecialchars($embed['raw_url']) ?>" target="_blank" rel="noopener" title="Abrir em nova aba"><i class="bi bi-box-arrow-up-right"></i></a>
                        </div>
                        <?php elseif ($embed && $embed['type'] === 'link'): ?>
                        <div class="empty">
                            <i class="bi bi-play-circle" style="font-size:26px"></i>
                            <p style="margin:8px 0 12px">Vídeo disponível como link externo.</p>
                            <a href="<?= htmlspecialchars($embed['raw_url']) ?>" target="_blank" rel="noopener" class="btn-orange" style="display:inline-flex">Assistir</a>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>

                <!-- ── Starters ── (full width) -->
                <div class="bc span-3" style="animation-delay:.25s">
                    <div class="bc-head">
                        <div class="bc-title"><i class="bi bi-trophy"></i> Quinteto Titular</div>
                        <a href="/my-roster.php" class="bc-link">Gerenciar elenco <i class="bi bi-arrow-right"></i></a>
                    </div>
                    <div class="bc-body">
                        <?php if (count($titulares) > 0): ?>
                        <div class="starters-grid">
                            <?php foreach ($titulares as $i => $player):
                                $pn = $player['name'] ?? '';
                                $cp = trim((string)($player['foto_adicional'] ?? ''));
                                if ($cp && !preg_match('#^https?://#i', $cp)) $cp = '/' . ltrim($cp, '/');
                                $nbId = $player['nba_player_id'] ?? null;
                                $photo = $cp ?: ($nbId ? "https://cdn.nba.com/headshots/nba/latest/1040x760/{$nbId}.png" : "https://ui-avatars.com/api/?name=" . rawurlencode($pn) . "&background=1c1c21&color=" . accentColorHex($user['accent_color'] ?? null) . "&rounded=true&bold=true");
                            ?>
                            <div class="starter-chip" style="animation-delay:<?= .28 + $i * .04 ?>s">
                                <img class="starter-photo" src="<?= htmlspecialchars($photo) ?>"
                                     alt="<?= htmlspecialchars($pn) ?>"
                                     onerror="this.src='https://ui-avatars.com/api/?name=<?= rawurlencode($pn) ?>&background=1c1c21&color=<?= accentColorHex($user['accent_color'] ?? null) ?>&rounded=true&bold=true'">
                                <div style="min-width:0">
                                    <div style="margin-bottom:4px"><span class="starter-pos"><?= htmlspecialchars($player['position']) ?></span></div>
                                    <div class="starter-name"><?= htmlspecialchars($pn) ?></div>
                                    <div class="starter-ovr">OVR <?= $player['ovr'] ?> · <?= $player['age'] ?>y</div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php else: ?>
                        <div class="empty">
                            <i class="bi bi-exclamation-circle"></i>
                            <p>Nenhum titular definido. <a href="/my-roster.php" style="color:var(--red)">Adicionar jogadores</a></p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- ── De olho em... (watchlist) ── -->
                <div class="bc span-<?= $watchlist ? '2' : '1' ?>" style="animation-delay:.28s">
                    <div class="bc-head">
                        <div class="bc-title"><i class="bi bi-star-fill"></i> De olho em...</div>
                        <a href="/players.php" class="bc-link">Jogadores <i class="bi bi-arrow-right"></i></a>
                    </div>
                    <div class="bc-body" style="padding-top:8px;padding-bottom:8px">
                        <?php if ($watchlist): ?>
                        <?php foreach ($watchlist as $w):
                            $wPhoto = trim((string)($w['foto_adicional'] ?? ''));
                            if ($wPhoto && !preg_match('#^https?://#i', $wPhoto)) $wPhoto = '/' . ltrim($wPhoto, '/');
                            if (!$wPhoto) $wPhoto = $w['nba_player_id']
                                ? "https://cdn.nba.com/headshots/nba/latest/1040x760/{$w['nba_player_id']}.png"
                                : "https://ui-avatars.com/api/?name=" . rawurlencode($w['name']) . "&background=1c1c21&color=" . accentColorHex($user['accent_color'] ?? null) . "&rounded=true&bold=true";
                            $wOvr = (int)($w['ovr'] ?? 0);
                        ?>
                        <a href="/player.php?id=<?= (int)$w['id'] ?>" class="watch-row">
                            <img class="watch-photo" src="<?= htmlspecialchars($wPhoto) ?>" alt="" onerror="this.src='https://ui-avatars.com/api/?name=<?= rawurlencode($w['name']) ?>&background=1c1c21&color=<?= accentColorHex($user['accent_color'] ?? null) ?>&rounded=true&bold=true'">
                            <div style="min-width:0;flex:1">
                                <div class="watch-name"><?= htmlspecialchars($w['name']) ?></div>
                                <div class="watch-meta"><?= htmlspecialchars($w['position'] ?? '-') ?> · <?= (int)($w['age'] ?? 0) ?>a · <?= htmlspecialchars(trim(($w['city'] ?? '') . ' ' . ($w['team_name'] ?? ''))) ?: '—' ?></div>
                            </div>
                            <span class="watch-ovr"><?= $wOvr ?></span>
                        </a>
                        <?php endforeach; ?>
                        <?php else: ?>
                        <div class="empty" style="padding:20px 12px;text-align:center">
                            <i class="bi bi-star" style="font-size:26px;color:var(--text-3)"></i>
                            <p style="font-size:12px;color:var(--text-2);margin:8px 0 0">Favorite jogadores em <a href="/players.php" style="color:var(--red)">Jogadores</a> pra acompanhar seus alvos de troca aqui.</p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- ── Ranking ── -->
                <div class="bc" style="animation-delay:.3s">
                    <div class="bc-head">
                        <div class="bc-title"><i class="bi bi-trophy-fill"></i> Top 5 Ranking</div>
                        <a href="/rankings.php" class="bc-link">Ver todos <i class="bi bi-arrow-right"></i></a>
                    </div>
                    <div class="bc-body" style="padding-top:8px;padding-bottom:8px">
                        <?php if (count($topRanking) > 0): ?>
                        <?php foreach ($topRanking as $idx => $rt): ?>
                        <div class="rank-row <?= $rt['id'] == $team['id'] ? 'me' : '' ?>">
                            <div class="rank-num <?= $idx === 0 ? 'gold' : ($idx === 1 ? 'silver' : ($idx === 2 ? 'bronze' : '')) ?>">
                                <?= $idx + 1 ?>
                            </div>
                            <img class="rank-logo"
                                 src="<?= htmlspecialchars($rt['photo_url'] ?? '/img/default-team.png') ?>"
                                 alt="" onerror="this.src='/img/default-team.png'">
                            <div class="rank-name">
                                <div class="rank-team"><?= htmlspecialchars($rt['city'] . ' ' . $rt['name']) ?></div>
                                <div class="rank-owner"><?= htmlspecialchars($rt['owner_name'] ?? '') ?></div>
                            </div>
                            <div style="text-align:right;flex-shrink:0">
                                <div class="rank-pts"><?= (int)$rt['ranking_points'] ?></div>
                                <div class="rank-pts-label">pts</div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        <?php else: ?>
                        <div class="empty"><i class="bi bi-trophy"></i><p>Ranking em breve</p></div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- ── Última Temporada (só da sprint atual) ── -->
                <div class="bc" style="animation-delay:.34s">
                    <div class="bc-head">
                        <div class="bc-title"><i class="bi bi-award-fill"></i> Última Temporada</div>
                        <?php if ($lastSprintInfo): ?>
                        <span style="font-size:11px;color:var(--text-2)">Temporada <?= (int)($lastSprintInfo['season_number'] ?? 0) ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="bc-body">
                        <?php if ($lastChampion || $lastRunnerUp || $lastMVP): ?>
                        <?php if ($lastChampion): ?>
                        <div class="winner-row gold">
                            <i class="bi bi-trophy-fill winner-icon" style="color:var(--amber)"></i>
                            <img class="winner-logo" src="<?= htmlspecialchars($lastChampion['photo_url'] ?? '/img/default-team.png') ?>" alt="" onerror="this.src='/img/default-team.png'">
                            <div>
                                <div class="winner-title">Campeão</div>
                                <div class="winner-name"><?= htmlspecialchars($lastChampion['city'] . ' ' . $lastChampion['name']) ?></div>
                                <div class="winner-owner"><?= htmlspecialchars($lastChampion['owner_name'] ?? '') ?></div>
                            </div>
                        </div>
                        <?php endif; ?>
                        <?php if ($lastRunnerUp): ?>
                        <div class="winner-row silver">
                            <i class="bi bi-award winner-icon" style="color:#94a3b8"></i>
                            <img class="winner-logo" src="<?= htmlspecialchars($lastRunnerUp['photo_url'] ?? '/img/default-team.png') ?>" alt="" onerror="this.src='/img/default-team.png'">
                            <div>
                                <div class="winner-title">Vice-Campeão</div>
                                <div class="winner-name"><?= htmlspecialchars($lastRunnerUp['city'] . ' ' . $lastRunnerUp['name']) ?></div>
                                <div class="winner-owner"><?= htmlspecialchars($lastRunnerUp['owner_name'] ?? '') ?></div>
                            </div>
                        </div>
                        <?php endif; ?>
                        <?php if ($lastMVP): ?>
                        <div class="winner-row mvp">
                            <i class="bi bi-star-fill winner-icon" style="color:var(--red)"></i>
                            <div>
                                <div class="winner-title">MVP</div>
                                <div class="winner-name"><?= htmlspecialchars($lastMVP['name']) ?></div>
                                <?php if (!empty($lastMVP['team_city'])): ?>
                                <div class="winner-owner"><?= htmlspecialchars($lastMVP['team_city'] . ' ' . $lastMVP['team_name']) ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                        <?php else: ?>
                        <div class="empty"><i class="bi bi-hourglass-split"></i><p>Em breve</p>
                            <span style="font-size:11px;color:var(--text-3)">Os vencedores aparecem quando a primeira temporada desta sprint fechar.</span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>


                <!-- ── Info da Liga ── -->
                <div class="bc" style="animation-delay:.38s">
                    <div class="bc-head">
                        <div class="bc-title"><i class="bi bi-info-circle-fill"></i> Liga</div>
                        <?php if ($hasEdital): ?>
                        <a href="/api/edital.php?action=download_edital&league=<?= urlencode($team['league']) ?>"
                           class="bc-link" download><i class="bi bi-download me-1"></i>Edital</a>
                        <?php endif; ?>
                    </div>
                    <div class="bc-body">
                        <img src="/img/logo-<?= strtolower($user['league']) ?>.png"
                             alt="<?= htmlspecialchars($user['league']) ?>"
                             class="league-logo-img"
                             onerror="this.style.display='none'">
                        <div style="text-align:center;font-size:16px;font-weight:800;color:var(--red);margin-bottom:4px"><?= htmlspecialchars($user['league']) ?></div>
                        <div class="league-stat-grid">
                            <div class="league-stat">
                                <div class="league-stat-label">Ranking</div>
                                <div class="league-stat-val"><?= (int)($team['ranking_points'] ?? 0) ?></div>
                            </div>
                            <?php if ($currentSeason): ?>
                            <div class="league-stat">
                                <div class="league-stat-label">Temporada</div>
                                <div class="league-stat-val"><?= $seasonDisplayYear ?></div>
                            </div>
                            <div class="league-stat">
                                <div class="league-stat-label">Sprint</div>
                                <div class="league-stat-val"><?= (int)($currentSeason['sprint_number'] ?? 1) ?></div>
                            </div>
                            <?php endif; ?>
                            <div class="league-stat">
                                <?php if ($salaryCapMode && $salCap): ?>
                                <div class="league-stat-label">Teto Salarial</div>
                                <div class="league-stat-val" style="font-size:12px"><?= (int)$salCap['cap_max'] ?>M</div>
                                <?php else: ?>
                                <div class="league-stat-label">CAP Faixa</div>
                                <div class="league-stat-val" style="font-size:12px"><?= $capMin ?>–<?= $capMax ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ── Último Rumor ── -->
                <div class="bc" style="animation-delay:.42s">
                    <div class="bc-head">
                        <div class="bc-title"><i class="bi bi-chat-left-text"></i> Último Rumor</div>
                        <a href="/mercado.php" class="bc-link">Ver feed <i class="bi bi-arrow-right"></i></a>
                    </div>
                    <div class="bc-body">
                        <?php if ($latestRumor): ?>
                        <div class="rumor-meta">
                            <img class="rumor-avatar"
                                 src="<?= htmlspecialchars($latestRumor['photo_url'] ?? '/img/default-team.png') ?>"
                                 alt="" onerror="this.src='/img/default-team.png'">
                            <div>
                                <div class="rumor-team"><?= htmlspecialchars(($latestRumor['city'] ?? '') . ' ' . ($latestRumor['name'] ?? '')) ?></div>
                                <div class="rumor-gm"><?= !empty($latestRumor['gm_name']) ? 'GM: ' . htmlspecialchars($latestRumor['gm_name']) : 'GM não informado' ?></div>
                            </div>
                        </div>
                        <div class="rumor-bubble"><?= nl2br(htmlspecialchars($latestRumor['content'])) ?></div>
                        <?php if (!empty($latestRumor['created_at'])): ?>
                        <div class="rumor-date"><i class="bi bi-clock"></i> <?= date('d/m/Y H:i', strtotime($latestRumor['created_at'])) ?></div>
                        <?php endif; ?>
                        <?php else: ?>
                        <div class="empty"><i class="bi bi-chat-left"></i><p>Nenhum rumor ainda</p></div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- ── Última Trade ── -->
                <div class="bc span-2" style="animation-delay:.46s">
                    <div class="bc-head">
                        <div class="bc-title"><i class="bi bi-arrow-left-right"></i> Última Trade</div>
                        <a href="/trades.php" class="bc-link">Ver todas <i class="bi bi-arrow-right"></i></a>
                    </div>
                    <div class="bc-body">
                        <?php if ($tradesEnabled == 0): ?>
                        <div class="empty" style="color:#ef4444">
                            <i class="bi bi-x-circle-fill"></i>
                            <p>Trades desativadas pelo administrador</p>
                        </div>
                        <?php elseif ($lastTrade): ?>
                        <div class="trade-teams">
                            <div class="trade-team">
                                <img src="<?= htmlspecialchars($lastTrade['from_photo'] ?? '/img/default-team.png') ?>" alt="" onerror="this.src='/img/default-team.png'">
                                <div class="trade-team-name"><?= htmlspecialchars($lastTrade['from_city'] . ' ' . $lastTrade['from_name']) ?></div>
                            </div>
                            <i class="bi bi-arrow-left-right trade-arrow"></i>
                            <div class="trade-team">
                                <img src="<?= htmlspecialchars($lastTrade['to_photo'] ?? '/img/default-team.png') ?>" alt="" onerror="this.src='/img/default-team.png'">
                                <div class="trade-team-name"><?= htmlspecialchars($lastTrade['to_city'] . ' ' . $lastTrade['to_name']) ?></div>
                            </div>
                        </div>
                        <div class="trade-cols">
                            <div class="trade-col">
                                <div class="trade-col-label">Enviou</div>
                                <?php foreach ($lastTradeFromPlayers as $p): ?>
                                <div class="trade-item"><i class="bi bi-person-fill"></i><div><strong><?= htmlspecialchars($p['name']) ?></strong> <span>(<?= $p['position'] ?> · <?= $p['ovr'] ?>)</span></div></div>
                                <?php endforeach; ?>
                                <?php foreach ($lastTradeFromPicks as $p): ?>
                                <div class="trade-item"><i class="bi bi-calendar-check"></i>Pick <?= $p['season_year'] ?> R<?= $p['round'] ?></div>
                                <?php endforeach; ?>
                                <?php if (!$lastTradeFromPlayers && !$lastTradeFromPicks): ?><div class="trade-item" style="color:var(--text-3)">—</div><?php endif; ?>
                            </div>
                            <div class="trade-col">
                                <div class="trade-col-label">Recebeu</div>
                                <?php foreach ($lastTradeToPlayers as $p): ?>
                                <div class="trade-item"><i class="bi bi-person-fill"></i><div><strong><?= htmlspecialchars($p['name']) ?></strong> <span>(<?= $p['position'] ?> · <?= $p['ovr'] ?>)</span></div></div>
                                <?php endforeach; ?>
                                <?php foreach ($lastTradeToPicks as $p): ?>
                                <div class="trade-item"><i class="bi bi-calendar-check"></i>Pick <?= $p['season_year'] ?> R<?= $p['round'] ?></div>
                                <?php endforeach; ?>
                                <?php if (!$lastTradeToPlayers && !$lastTradeToPicks): ?><div class="trade-item" style="color:var(--text-3)">—</div><?php endif; ?>
                            </div>
                        </div>
                        <?php if (!empty($lastTrade['updated_at'])): ?>
                        <div class="trade-date">
                            <i class="bi bi-clock" style="margin-right:4px"></i>
                            <?php
                                $d = new DateTime($lastTrade['updated_at']);
                                $diff = (new DateTime())->diff($d);
                                if ($diff->days == 0) echo 'Hoje';
                                elseif ($diff->days == 1) echo 'Ontem';
                                elseif ($diff->days < 7) echo $diff->days . ' dias atrás';
                                else echo $d->format('d/m/Y');
                            ?>
                        </div>
                        <?php endif; ?>
                        <?php else: ?>
                        <div class="empty"><i class="bi bi-arrow-left-right"></i><p>Nenhuma trade realizada ainda</p></div>
                        <?php endif; ?>
                    </div>
                </div>


            </div><!-- /bento -->
        </div><!-- /content -->


    </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="/js/pwa.js"></script>
<script>
    /* ── Sidebar mobile ──────────────────────────── */
        const themeToggle = document.getElementById('themeToggle');
        const themeKey = 'fba-theme';

        const applyTheme = (theme) => {
            if (theme === 'light') {
                document.documentElement.setAttribute('data-theme', 'light');
                if (themeToggle) {
                    themeToggle.innerHTML = '<i class="bi bi-sun"></i><span>Modo claro</span>';
                }
                return;
            }
            document.documentElement.removeAttribute('data-theme');
            if (themeToggle) {
                themeToggle.innerHTML = '<i class="bi bi-moon"></i><span>Modo escuro</span>';
            }
        };

        const savedTheme = localStorage.getItem(themeKey);
        applyTheme(savedTheme || 'dark');

        if (themeToggle) {
            themeToggle.addEventListener('click', () => {
                const current = document.documentElement.getAttribute('data-theme') === 'light' ? 'light' : 'dark';
                const next = current === 'light' ? 'dark' : 'light';
                localStorage.setItem(themeKey, next);
                applyTheme(next);
            });
        }

        const sidebar = document.getElementById('sidebar');
        const menuBtn = document.getElementById('menuBtn');
        const sbOverlay = document.getElementById('sbOverlay');
    const closeSidebar = () => {
        sidebar.classList.remove('open');
        sbOverlay.classList.remove('show');
    };
    menuBtn?.addEventListener('click', () => {
        const willOpen = !sidebar.classList.contains('open');
        sidebar.classList.toggle('open');
        sbOverlay.classList.toggle('show', willOpen);
    });
    sbOverlay?.addEventListener('click', closeSidebar);
    if (window.innerWidth <= 860) {
        document.querySelectorAll('.sb-nav a').forEach((link) => {
            link.addEventListener('click', closeSidebar);
        });
    }

    /* ── Stagger animation delays ────────────────── */
    document.querySelectorAll('.stat-c').forEach((el, i) => el.style.animationDelay = (i * 0.05 + 0.05) + 's');
    document.querySelectorAll('.bc').forEach((el, i) => el.style.animationDelay = (i * 0.04 + 0.25) + 's');

    /* ── Copy team ───────────────────────────────── */
    const rosterData = <?= json_encode($allPlayers) ?>;
    const picksData  = <?= json_encode($teamPicksForCopy) ?>;
    const teamMeta   = {
        name: <?= json_encode($team['city'] . ' ' . $team['name']) ?>,
        userName: <?= json_encode($user['name']) ?>,
        cap: <?= (int)$teamCap ?>,
        capMin: <?= (int)$capMin ?>,
        capMax: <?= (int)$capMax ?>,
        trades: <?= (int)$tradesCount ?>,
        maxTrades: <?= (int)$maxTrades ?>,
        customHeader: <?= json_encode($team['custom_header'] ?? '') ?>,
        useCustomHeader: <?= !empty($team['use_custom_header']) ? 'true' : 'false' ?>,
        league: <?= json_encode($team['league'] ?? '') ?>,
        // Liga no salary cap (ELITE) copia a folha, não a soma de OVR do Top 8.
        salary: <?= ($salaryCapMode && $salCap) ? json_encode([
            'payroll'   => (int)$salCap['payroll'],
            'cap_max'   => (int)$salCap['cap_max'],
            'cap_floor' => (int)$salCap['cap_floor'],
            'space'     => (int)$salCap['space'],
            'status'    => $salCap['status'],
        ]) : 'null' ?>
    };

    /** Linha do CAP no texto copiado — salary cap na ELITE, Top 8 nas demais. */
    function linhaCap(meta) {
        if (!meta.salary) return `_CAP_: ${meta.capMin} / *${meta.cap}* / ${meta.capMax}`;
        const s = meta.salary;
        const rotulo = s.status === 'over_the_cap' ? 'acima do teto'
                     : s.status === 'abaixo_do_piso' ? 'abaixo do piso'
                     : 'dentro do cap';
        const sobra = s.space >= 0 ? `${s.space}M livres` : `${Math.abs(s.space)}M acima`;
        return `_Salary Cap_: *${s.payroll}M* / ${s.cap_max}M — ${sobra} (${rotulo})`;
    }

    function buildTeamSummary() {
        const positions = ['PG','SG','SF','PF','C'];
        const startersMap = {};
        positions.forEach(p => startersMap[p] = null);
        const fmt = age => (Number.isFinite(age) && age > 0) ? `${age}y` : '-';
        const fmtTag = p => (p && p.player_tag && Number(p.player_tag_copy) === 1) ? ` - ${p.player_tag}` : '';
        const fmtLine = (label, p) => p ? `${label}: ${p.name}${fmtTag(p)} - ${p.ovr ?? '-'} | ${fmt(p.age)}` : `${label}: -`;
        const fmtPlayer = p => `${p.position}: ${p.name}${fmtTag(p)} - ${p.ovr??'-'} | ${fmt(p.age)}`;

        rosterData.filter(p => p.role === 'Titular').forEach(p => { if (positions.includes(p.position) && !startersMap[p.position]) startersMap[p.position] = p; });
        const bench   = rosterData.filter(p => p.role === 'Banco');
        const others  = rosterData.filter(p => p.role === 'Outro');
        const gleague = rosterData.filter(p => (p.role||'').toLowerCase() === 'g-league');
        const isElite = (teamMeta.league||'').toUpperCase() === 'ELITE';
        const r1 = picksData.filter(pk => pk.round == 1).map(pk => `-${pk.season_year}${pk.original_team_id != pk.team_id ? ` (via ${pk.city} ${pk.team_name})` : ''} `);
        const r2 = picksData.filter(pk => pk.round == 2).map(pk => `-${pk.season_year}${pk.original_team_id != pk.team_id ? ` (via ${pk.city} ${pk.team_name})` : ''} `);

        const headerLines = (teamMeta.useCustomHeader && teamMeta.customHeader.trim())
            ? teamMeta.customHeader.trim().split('\n')
            : [`*${teamMeta.name}*`, teamMeta.userName];

        const lines = [
            ...headerLines, '',
            '_Starters_', ...positions.map(p => fmtLine(p, startersMap[p])), '',
            '_Bench_', ...(bench.length ? bench.map(fmtPlayer) : ['-']), '',
            '_Others_', ...(others.length ? others.map(fmtPlayer) : ['-']), '',
        ];
        if (isElite) lines.push('_G-League_', ...(gleague.length ? gleague.map(fmtPlayer) : ['-']), '');
        lines.push(
            '_Picks 1º round_:', ...(r1.length ? r1 : ['-']), '',
            '_Picks 2º round_:', ...(r2.length ? r2 : ['-']), '',
            linhaCap(teamMeta),
            `_Trades_: ${teamMeta.trades} / ${teamMeta.maxTrades}`
        );
        return lines.join('\n');
    }

    document.getElementById('copyTeamBtn')?.addEventListener('click', async () => {
        const text = buildTeamSummary();
        try { await navigator.clipboard.writeText(text); alert('Time copiado!'); }
        catch { const t = document.createElement('textarea'); t.value = text; document.body.appendChild(t); t.select(); document.execCommand('copy'); document.body.removeChild(t); alert('Time copiado!'); }
    });

    // Vídeos da liga (Progression / Sistemas / Free Agency): tela cheia e
    // captura de frame pra área de transferência.
    //
    // Captura tem dois caminhos:
    // - <video> nativo (link direto de arquivo): desenha o frame atual num
    //   canvas — simples, sem prompt de permissão.
    // - <iframe> (YouTube/Vimeo/Drive): cross-origin, então o canvas não
    //   consegue ler o conteúdo. O único jeito honesto de capturar o que
    //   está na tela é pedir compartilhamento de tela (getDisplayMedia) e
    //   recortar a área do card — só funciona se a pessoa escolher
    //   "Esta guia/aba" no prompt do navegador.
    async function flashCopiedBlob(btn, blob) {
        try {
            await navigator.clipboard.write([new ClipboardItem({ 'image/png': blob })]);
            const original = btn.innerHTML;
            btn.classList.add('copied');
            btn.innerHTML = '<i class="bi bi-check2"></i>';
            setTimeout(() => { btn.classList.remove('copied'); btn.innerHTML = original; }, 2000);
        } catch (e) {
            const link = document.createElement('a');
            link.href = URL.createObjectURL(blob);
            link.download = 'video-frame.png';
            link.click();
            alert('Seu navegador não deixa copiar imagem direto pra área de transferência — baixei o print pra você.');
        }
    }

    function captureFromVideoEl(video, btn) {
        video.pause();
        try {
            const canvas = document.createElement('canvas');
            canvas.width = video.videoWidth || 1280;
            canvas.height = video.videoHeight || 720;
            canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);
            canvas.toBlob((blob) => {
                if (!blob) { alert('Não consegui capturar esse frame.'); return; }
                flashCopiedBlob(btn, blob);
            }, 'image/png');
        } catch (e) {
            alert('Não consegui capturar esse frame — o servidor do vídeo provavelmente bloqueia captura entre origens (CORS).');
        }
    }

    /**
     * Captura só o retângulo de um elemento.
     *
     * O caminho bom é o Region Capture (CropTarget + track.cropTo): o próprio
     * navegador recorta a transmissão no elemento, então sai exatamente o
     * vídeo, não importa o que a pessoa escolheu compartilhar nem o zoom da
     * página. Antes disso a conta era manual (retângulo do elemento × escala
     * da janela) e só acertava se a pessoa escolhesse "Esta guia" — escolhendo
     * a tela inteira ou outra janela, a imagem saía cortada no lugar errado.
     * O caminho manual fica de reserva pra quem não tem a API.
     */
    async function captureViaScreenShare(wrap, btn) {
        if (!navigator.mediaDevices?.getDisplayMedia) {
            alert('Seu navegador não suporta captura de tela. Tente um print manual.');
            return;
        }
        let stream;
        try {
            stream = await navigator.mediaDevices.getDisplayMedia({
                video: true,
                preferCurrentTab: true,
                selfBrowserSurface: 'include',
            });
        } catch (e) {
            return; // usuário cancelou o prompt
        }
        try {
            const track = stream.getVideoTracks()[0];

            let recortadoPeloNavegador = false;
            if (window.CropTarget?.fromElement && typeof track.cropTo === 'function') {
                try {
                    await track.cropTo(await CropTarget.fromElement(wrap));
                    recortadoPeloNavegador = true;
                } catch (e) {
                    // segue no recorte manual
                }
            }

            const tempVideo = document.createElement('video');
            tempVideo.muted = true;
            tempVideo.srcObject = stream;
            await tempVideo.play();
            await new Promise(r => setTimeout(r, 250)); // deixa um frame real renderizar

            const canvas = document.createElement('canvas');
            const ctx = canvas.getContext('2d');

            if (recortadoPeloNavegador) {
                canvas.width = tempVideo.videoWidth;
                canvas.height = tempVideo.videoHeight;
                ctx.drawImage(tempVideo, 0, 0);
            } else {
                const rect = wrap.getBoundingClientRect();
                const scaleX = tempVideo.videoWidth / window.innerWidth;
                const scaleY = tempVideo.videoHeight / window.innerHeight;
                canvas.width = Math.max(1, Math.round(rect.width * scaleX));
                canvas.height = Math.max(1, Math.round(rect.height * scaleY));
                ctx.drawImage(
                    tempVideo,
                    rect.left * scaleX, rect.top * scaleY, rect.width * scaleX, rect.height * scaleY,
                    0, 0, canvas.width, canvas.height
                );
            }

            canvas.toBlob((blob) => {
                if (!blob) { alert('Não consegui gerar a imagem capturada.'); return; }
                flashCopiedBlob(btn, blob);
            }, 'image/png');
        } catch (e) {
            alert('Não consegui capturar a tela.');
        } finally {
            stream.getTracks().forEach(t => t.stop());
        }
    }

    // ── Player próprio pros vídeos do YouTube ───────────────────────────────
    // A API do YouTube é usada só como motor: controles deles desligados, um
    // escudo transparente por cima pra nenhum evento de mouse chegar no iframe
    // (é o hover que faz aparecer título, "Mais vídeos" e a marca), e capa preta
    // sempre que o vídeo não está tocando — que é justamente quando a interface
    // deles apareceria. O resultado é um player com a cara do app.
    const ytPlayers = {};

    function ytFormatarTempo(seg) {
        seg = Math.max(0, Math.floor(seg || 0));
        const m = Math.floor(seg / 60), s = seg % 60;
        return m + ':' + String(s).padStart(2, '0');
    }

    function ytMontarUm(wrap) {
        const key     = wrap.dataset.key;
        const videoId = wrap.dataset.yt;
        const capa     = wrap.querySelector('.yt-capa');
        const escudo   = wrap.querySelector('.yt-escudo');
        const btn      = wrap.querySelector('.yt-toggle');
        const btnMudo  = wrap.querySelector('.yt-mudo');
        const trilha   = wrap.querySelector('.yt-trilha');
        const preenche = wrap.querySelector('.yt-preenche');
        const buffer   = wrap.querySelector('.yt-buffer');
        const tempo    = wrap.querySelector('.yt-tempo');

        wrap.classList.add('carregando', 'pausado');

        // Mantém o iframe de 1280x720 encaixado na caixa visível. Roda também
        // quando o card vira popup, que muda de tamanho sem recarregar a página.
        const ajustarEscala = () => {
            const largura = wrap.clientWidth;
            if (largura > 0) wrap.style.setProperty('--yt-escala', largura / 1280);
        };
        ajustarEscala();
        // O popup muda o tamanho do card na mesma tacada em que troca a classe;
        // guardar a função aqui deixa o vidPopAbrir/Fechar reajustar na hora,
        // sem depender do momento em que o ResizeObserver resolve entregar.
        wrap._ytAjustarEscala = ajustarEscala;
        if (window.ResizeObserver) new ResizeObserver(ajustarEscala).observe(wrap);
        else window.addEventListener('resize', ajustarEscala);

        const player = new YT.Player('ytmount_' + key, {
            videoId,
            playerVars: {
                controls: 0, disablekb: 1, modestbranding: 1, rel: 0,
                iv_load_policy: 3, playsinline: 1, fs: 0, showinfo: 0,
                vq: 'hd1080',
            },
            events: {
                onReady: () => {
                    wrap.classList.remove('carregando');
                    pedirAltaQualidade();
                    atualizar();
                },
                onStateChange: (e) => {
                    if (e.data === YT.PlayerState.PLAYING) pedirAltaQualidade();
                    const tocando = e.data === YT.PlayerState.PLAYING;
                    wrap.classList.toggle('pausado', !tocando);
                    // Capa só sai quando está de fato tocando: no BUFFERING o
                    // YouTube desenha o spinner e o título dele por baixo.
                    capa.classList.toggle('on', !tocando);
                    btn.innerHTML = tocando ? '<i class="bi bi-pause-fill"></i>' : '<i class="bi bi-play-fill"></i>';
                    btn.setAttribute('aria-label', tocando ? 'Pausar' : 'Reproduzir');
                    if (e.data === YT.PlayerState.ENDED) { preenche.style.width = '100%'; }
                },
            },
        });
        ytPlayers[key] = player;

        /**
         * Pede a melhor resolução disponível.
         *
         * O YouTube decide a qualidade quase toda pelo tamanho em que o player
         * está desenhado, e ignora o pedido quando não pode atender — por isso
         * a chamada é só um empurrão, e o que garante HD de verdade é o card
         * ocupar a largura toda (e o popup abrir em 16:9 grande).
         */
        function pedirAltaQualidade() {
            try {
                const niveis = player.getAvailableQualityLevels?.() || [];
                const preferida = ['highres', 'hd2160', 'hd1440', 'hd1080', 'hd720']
                    .find(q => niveis.includes(q));
                if (preferida) player.setPlaybackQuality(preferida);
            } catch (e) { /* API mudou ou ainda não respondeu — segue no automático */ }
        }

        function alternar() {
            const st = player.getPlayerState?.();
            if (st === YT.PlayerState.PLAYING) player.pauseVideo();
            else player.playVideo();
        }
        escudo.addEventListener('click', alternar);
        capa.addEventListener('click', alternar);
        btn.addEventListener('click', alternar);

        btnMudo.addEventListener('click', () => {
            const mudo = player.isMuted?.();
            if (mudo) player.unMute(); else player.mute();
            btnMudo.innerHTML = mudo ? '<i class="bi bi-volume-up-fill"></i>' : '<i class="bi bi-volume-mute-fill"></i>';
        });

        function buscarPor(ev) {
            const dur = player.getDuration?.() || 0;
            if (!dur) return;
            const r = trilha.getBoundingClientRect();
            const frac = Math.min(1, Math.max(0, (ev.clientX - r.left) / r.width));
            player.seekTo(frac * dur, true);
            preenche.style.width = (frac * 100) + '%';
        }
        trilha.addEventListener('click', buscarPor);

        function atualizar() {
            const dur = player.getDuration?.() || 0;
            const cur = player.getCurrentTime?.() || 0;
            if (dur > 0) {
                preenche.style.width = ((cur / dur) * 100) + '%';
                buffer.style.width = ((player.getVideoLoadedFraction?.() || 0) * 100) + '%';
            }
            tempo.textContent = ytFormatarTempo(cur) + ' / ' + ytFormatarTempo(dur);
        }
        setInterval(atualizar, 500);
    }

    (function ytIniciar() {
        const wraps = document.querySelectorAll('.yt-wrap[data-yt]');
        if (!wraps.length) return;

        function montarTodos() { wraps.forEach(ytMontarUm); }

        if (window.YT && window.YT.Player) { montarTodos(); return; }
        // A API chama um callback global quando termina de carregar; encadeia
        // com o que já existir pra não atropelar outro script da página.
        const anterior = window.onYouTubeIframeAPIReady;
        window.onYouTubeIframeAPIReady = function () {
            if (typeof anterior === 'function') anterior();
            montarTodos();
        };
        if (!document.querySelector('script[src*="youtube.com/iframe_api"]')) {
            const s = document.createElement('script');
            s.src = 'https://www.youtube.com/iframe_api';
            document.head.appendChild(s);
        }
    })();

    // ── Popup do vídeo ──────────────────────────────────────────────────────
    // O card do vídeo vira uma janela centralizada (sem sair do lugar no DOM,
    // pra não recarregar o iframe) e ganha uma barra com capturar e fechar.
    const vidPop = {
        fundo: null,
        barra: null,
        wrap: null,
        placeholder: null,
    };

    function vidPopMontar() {
        if (vidPop.fundo) return;

        vidPop.fundo = document.createElement('div');
        vidPop.fundo.className = 'vid-pop-fundo';
        vidPop.fundo.addEventListener('click', vidPopFechar);
        document.body.appendChild(vidPop.fundo);

        vidPop.barra = document.createElement('div');
        vidPop.barra.className = 'vid-pop-barra';
        vidPop.barra.innerHTML = `
            <span class="vid-pop-titulo" id="vidPopTitulo"></span>
            <button type="button" id="vidPopCapturar" title="Tira um print do vídeo e copia"><i class="bi bi-camera-fill"></i> Tirar print</button>
            <button type="button" id="vidPopFechar"><i class="bi bi-x-lg"></i> Fechar</button>`;
        document.body.appendChild(vidPop.barra);

        vidPop.barra.querySelector('#vidPopFechar').addEventListener('click', vidPopFechar);
        vidPop.barra.querySelector('#vidPopCapturar').addEventListener('click', (ev) => {
            if (!vidPop.wrap) return;
            const btn = ev.currentTarget;
            const video = vidPop.wrap.querySelector('video.prog-video');
            if (video) captureFromVideoEl(video, btn);
            else captureViaScreenShare(vidPop.wrap, btn);
        });
    }

    function vidPopAbrir(wrap, titulo) {
        vidPopMontar();
        if (vidPop.wrap) vidPopFechar();

        // Guarda o lugar do card pra devolver do jeito que estava.
        vidPop.placeholder = document.createElement('div');
        vidPop.placeholder.style.cssText = 'width:100%;aspect-ratio:16/9;border-radius:10px;background:var(--panel-2)';
        wrap.parentNode.insertBefore(vidPop.placeholder, wrap);

        // Solta o popup de qualquer ancestral com transform/filter, senão o
        // position:fixed passa a ser relativo a ele em vez de à tela.
        vidPop.ancestrais = [];
        for (let p = wrap.parentElement; p && p !== document.body; p = p.parentElement) {
            const cs = getComputedStyle(p);
            if (cs.transform !== 'none' || cs.filter !== 'none' || cs.perspective !== 'none') {
                p.classList.add('vid-pop-solto');
                vidPop.ancestrais.push(p);
            }
        }

        vidPop.wrap = wrap;
        wrap.classList.add('vid-pop');
        vidPop.fundo.classList.add('on');
        vidPop.barra.classList.add('on');
        document.getElementById('vidPopTitulo').textContent = titulo || '';
        document.body.style.overflow = 'hidden';
        wrap._ytAjustarEscala?.();
    }

    function vidPopFechar() {
        if (!vidPop.wrap) return;
        const oQueFecha = vidPop.wrap;
        vidPop.wrap.classList.remove('vid-pop');
        (vidPop.ancestrais || []).forEach(p => p.classList.remove('vid-pop-solto'));
        vidPop.ancestrais = [];
        vidPop.placeholder?.remove();
        vidPop.placeholder = null;
        vidPop.wrap = null;
        vidPop.fundo.classList.remove('on');
        vidPop.barra.classList.remove('on');
        document.body.style.overflow = '';
        oQueFecha._ytAjustarEscala?.();
    }

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && vidPop.wrap) vidPopFechar();
    });

    document.querySelectorAll('.vid-action-btn[data-action]').forEach(btn => {
        btn.addEventListener('click', () => {
            const key = btn.dataset.key;
            const wrap = document.getElementById('wrap_' + key);
            if (!wrap) return;

            if (btn.dataset.action === 'fullscreen') {
                vidPopAbrir(wrap, btn.dataset.titulo);
                return;
            }

            if (btn.dataset.action === 'capture') {
                const video = document.getElementById('vid_' + key);
                if (video) captureFromVideoEl(video, btn);
                else captureViaScreenShare(wrap, btn);
            }
        });
    });

</script>

<?php if ($precisaRevisarSprint): ?>
<!-- Revisão obrigatória de início de sprint: não dá pra fechar sem salvar. -->
<style>
.rev-overlay{position:fixed;inset:0;z-index:5200;background:rgba(0,0,0,.82);backdrop-filter:blur(5px);
  display:flex;align-items:flex-start;justify-content:center;padding:24px 16px;overflow-y:auto}
.rev-box{width:100%;max-width:520px;background:var(--panel);border:1px solid var(--border-md);
  border-radius:var(--radius);padding:26px 24px;box-shadow:0 30px 80px -30px rgba(0,0,0,.8);margin:auto}
.rev-head{display:flex;align-items:center;gap:12px;margin-bottom:6px}
.rev-icon{width:44px;height:44px;flex-shrink:0;border-radius:50%;background:var(--red-soft);color:var(--red);
  display:flex;align-items:center;justify-content:center;font-size:20px}
.rev-box h2{font-size:18px;font-weight:800;margin:0}
.rev-sub{font-size:13px;color:var(--text-2);line-height:1.6;margin:10px 0 18px}
.rev-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.rev-field{display:flex;flex-direction:column;gap:5px}
.rev-field.full{grid-column:1 / -1}
.rev-field label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--text-3)}
.rev-field input{background:var(--panel-2);border:1px solid var(--border-md);color:var(--text);
  border-radius:var(--radius-sm);padding:10px 12px;font-family:var(--font);font-size:13.5px;width:100%}
.rev-field input:focus{outline:none;border-color:var(--red)}
.rev-logo{display:flex;align-items:center;gap:14px;margin-top:16px;padding:14px;border-radius:var(--radius-sm);
  border:1px dashed var(--border-md)}
.rev-logo img{width:56px;height:56px;object-fit:contain;border-radius:8px;background:var(--panel-2);flex-shrink:0}
.rev-logo-txt{flex:1;min-width:0}
.rev-logo-txt b{display:block;font-size:13px;margin-bottom:3px}
.rev-logo-txt span{font-size:11.5px;color:var(--text-3);line-height:1.5}
.rev-logo-btn{padding:8px 12px;border-radius:var(--radius-sm);border:1px solid var(--border-md);
  background:transparent;color:var(--text-2);font-size:12.5px;font-weight:700;cursor:pointer;white-space:nowrap}
.rev-logo-btn:hover{color:var(--text);border-color:var(--red)}
.rev-erro{margin-top:14px;padding:10px 13px;border-radius:var(--radius-sm);font-size:12.5px;display:none;
  background:rgba(239,68,68,.10);border:1px solid rgba(239,68,68,.30);color:#ef4444}
.rev-cor{margin-top:16px;padding:14px 15px;border-radius:var(--radius-sm);
  border:1px solid var(--border-md);background:var(--panel-2)}
.rev-cor-topo b{display:block;font-size:13px;font-weight:800;margin-bottom:3px}
.rev-cor-topo b i{color:var(--red);margin-right:6px}
.rev-cor-topo span{display:block;font-size:11.5px;color:var(--text-3);line-height:1.45}
.rev-cor-lista{display:flex;flex-wrap:wrap;gap:9px;margin:12px 0 9px}
.rev-cor-bola{width:30px;height:30px;border-radius:50%;border:2px solid transparent;cursor:pointer;
  padding:0;transition:transform .12s,border-color .12s;box-shadow:0 0 0 1px rgba(255,255,255,.10) inset}
.rev-cor-bola:hover{transform:scale(1.12)}
.rev-cor-bola.ativa{border-color:var(--text);transform:scale(1.12)}
.rev-cor-custom{width:30px;height:30px;border-radius:50%;border:1px dashed var(--border-md);
  display:flex;align-items:center;justify-content:center;cursor:pointer;color:var(--text-3);font-size:13px;position:relative}
.rev-cor-custom:hover{border-color:var(--red);color:var(--red)}
.rev-cor-custom input{position:absolute;inset:0;opacity:0;cursor:pointer;border:0;padding:0}
.rev-cor-nota{font-size:11.5px;color:var(--text-3)}
.rev-cor-nota b{color:var(--text-2)}
.rev-actions{margin-top:20px}
.rev-actions button{width:100%;padding:12px 16px;border-radius:var(--radius-sm);background:var(--red);border:none;
  color:#fff;font-family:var(--font);font-size:14px;font-weight:700;cursor:pointer}
.rev-actions button:disabled{opacity:.6;cursor:not-allowed}
@media (max-width:520px){ .rev-grid{grid-template-columns:1fr} }
</style>
<div class="rev-overlay" id="revOverlay" role="dialog" aria-modal="true" aria-labelledby="revTitle">
  <div class="rev-box">
    <div class="rev-head">
      <div class="rev-icon"><i class="bi bi-clipboard-check"></i></div>
      <h2 id="revTitle">Confira os dados do seu time</h2>
    </div>
    <p class="rev-sub">Começou a Sprint <?= (int)($sprintAtual['sprint_number'] ?? 0) ?> da <?= htmlspecialchars((string)($team['league'] ?? ''), ENT_QUOTES, 'UTF-8') ?>. Antes de seguir, revise as informações abaixo e corrija o que estiver desatualizado.</p>

    <div class="rev-grid">
      <div class="rev-field full">
        <label for="revTeamName">Nome do time</label>
        <input type="text" id="revTeamName" maxlength="100" value="<?= htmlspecialchars((string)($team['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
      </div>
      <div class="rev-field">
        <label for="revCity">Cidade</label>
        <input type="text" id="revCity" maxlength="100" value="<?= htmlspecialchars((string)($team['city'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
      </div>
      <div class="rev-field">
        <label for="revMascot">Mascote</label>
        <input type="text" id="revMascot" maxlength="100" value="<?= htmlspecialchars((string)($team['mascot'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
      </div>
      <div class="rev-field">
        <label for="revGm">Nome do GM</label>
        <input type="text" id="revGm" maxlength="100" value="<?= htmlspecialchars((string)($user['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
      </div>
      <div class="rev-field">
        <label for="revEmail">E-mail</label>
        <input type="email" id="revEmail" maxlength="150" value="<?= htmlspecialchars((string)($user['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
      </div>
    </div>

    <div class="rev-logo">
      <img id="revLogoPreview" src="<?= htmlspecialchars(getTeamPhoto($team['photo_url'] ?? null), ENT_QUOTES, 'UTF-8') ?>" alt="Logo do time">
      <div class="rev-logo-txt">
        <b>Escudo do time</b>
        <span>PNG, JPG ou WEBP. Se não trocar agora, a gente continua cobrando depois.</span>
      </div>
      <label class="rev-logo-btn">
        Trocar
        <input type="file" id="revLogoInput" accept="image/png,image/jpeg,image/webp" style="display:none">
      </label>
    </div>

    <!-- Cor de destaque: aproveita o popup pra mostrar que o recurso existe.
         Clicar já recolore o app atrás do popup, então a pessoa vê o efeito
         antes de confirmar. -->
    <div class="rev-cor">
      <div class="rev-cor-topo">
        <b><i class="bi bi-palette-fill"></i> A cor do app é sua</b>
        <span>Escolha o tom dos detalhes — menus, botões e destaques mudam junto.</span>
      </div>
      <div class="rev-cor-lista" id="revCores">
        <?php
        $accentAtual = strtolower((string)($user['accent_color'] ?? '#fc0025'));
        $accentOpcoes = [
            '#fc0025' => 'Vermelho FBA', '#f97316' => 'Laranja', '#eab308' => 'Dourado',
            '#22c55e' => 'Verde',        '#06b6d4' => 'Ciano',   '#3b82f6' => 'Azul',
            '#a855f7' => 'Roxo',         '#ec4899' => 'Rosa',
        ];
        foreach ($accentOpcoes as $hex => $nome):
            $ativa = $accentAtual === $hex ? ' ativa' : '';
        ?>
        <button type="button" class="rev-cor-bola<?= $ativa ?>" data-cor="<?= $hex ?>"
                style="background:<?= $hex ?>" title="<?= htmlspecialchars($nome) ?>" aria-label="<?= htmlspecialchars($nome) ?>"></button>
        <?php endforeach; ?>
        <label class="rev-cor-custom" title="Escolher outra cor">
          <i class="bi bi-eyedropper"></i>
          <input type="color" id="revCorCustom" value="<?= htmlspecialchars($accentAtual) ?>">
        </label>
      </div>
      <span class="rev-cor-nota">Dá pra trocar quando quiser em <b>Minha Conta</b>.</span>
    </div>

    <div class="rev-erro" id="revErro"></div>
    <div class="rev-actions">
      <button type="button" id="revSalvar"><i class="bi bi-check-lg me-1"></i>Confirmar e continuar</button>
    </div>
  </div>
</div>
<script>
(function () {
  let revFotoBase64 = '';
  const erro = document.getElementById('revErro');
  const btn = document.getElementById('revSalvar');

  // Cor de destaque: aplica na hora em --red. Todo o resto do tema deriva
  // dessa variável, então a página inteira já muda por trás do popup — é o
  // jeito de mostrar o recurso em vez de só descrever.
  const corPadrao = '<?= htmlspecialchars(strtolower((string)($user['accent_color'] ?? '#fc0025')), ENT_QUOTES, 'UTF-8') ?>';
  let revCor = corPadrao;

  function revAplicarCor(hex) {
    revCor = hex;
    document.documentElement.style.setProperty('--red', hex);
    document.querySelectorAll('.rev-cor-bola').forEach(b => {
      b.classList.toggle('ativa', b.dataset.cor === hex);
    });
  }

  document.getElementById('revCores')?.addEventListener('click', (e) => {
    const bola = e.target.closest('.rev-cor-bola');
    if (bola) revAplicarCor(bola.dataset.cor);
  });
  document.getElementById('revCorCustom')?.addEventListener('input', (e) => {
    revAplicarCor(e.target.value.toLowerCase());
  });

  // Trava o scroll do dashboard atrás do modal (o modal rola sozinho).
  document.body.style.overflow = 'hidden';

  document.getElementById('revLogoInput')?.addEventListener('change', (e) => {
    const file = e.target.files && e.target.files[0];
    if (!file) return;
    if (file.size > 3 * 1024 * 1024) {
      erro.textContent = 'A imagem precisa ter no máximo 3 MB.';
      erro.style.display = 'block';
      e.target.value = '';
      return;
    }
    const reader = new FileReader();
    reader.onload = (ev) => {
      revFotoBase64 = ev.target.result;
      document.getElementById('revLogoPreview').src = revFotoBase64;
      erro.style.display = 'none';
    };
    reader.readAsDataURL(file);
  });

  btn?.addEventListener('click', async () => {
    erro.style.display = 'none';
    const payload = {
      action: 'sprint_review',
      name: document.getElementById('revTeamName').value.trim(),
      city: document.getElementById('revCity').value.trim(),
      mascot: document.getElementById('revMascot').value.trim(),
      gm_name: document.getElementById('revGm').value.trim(),
      email: document.getElementById('revEmail').value.trim(),
      photo_url: revFotoBase64,
    };

    if (!payload.name || !payload.city || !payload.mascot || !payload.gm_name || !payload.email) {
      erro.textContent = 'Preencha todos os campos antes de continuar.';
      erro.style.display = 'block';
      return;
    }
    btn.disabled = true;
    try {
      const res = await fetch('/api/team.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
      });
      const data = await res.json();
      if (!res.ok || data.error) throw new Error(data.error || 'Erro ao salvar.');

      // A cor é do usuário, não do time — vai por outra rota. Se falhar, não
      // segura a revisão: os dados do time já foram salvos e a cor pode ser
      // ajustada depois em Minha Conta.
      if (revCor !== corPadrao) {
        try {
          await fetch('/api/user.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ accent_color: revCor }),
          });
        } catch (e) { /* silencioso de propósito */ }
      }

      window.location.reload();
    } catch (e) {
      erro.textContent = e.message;
      erro.style.display = 'block';
      btn.disabled = false;
    }
  });
})();
</script>
<?php endif; ?>

<?php if ($precisaAtualizarElenco || $precisaLogo): ?>
<!-- Cobrancas que aparecem a cada carregamento ate o GM resolver (o servidor
     decide, "Agora não" só fecha aquela visita) -->
<style>
.upd-overlay{position:fixed;inset:0;z-index:5000;background:rgba(0,0,0,.72);backdrop-filter:blur(4px);
  display:flex;align-items:center;justify-content:center;padding:20px}
.upd-box{width:100%;max-width:440px;background:var(--panel);border:1px solid var(--border-md);
  border-radius:var(--radius);padding:28px 26px;text-align:center;box-shadow:0 30px 80px -30px rgba(0,0,0,.7)}
.upd-icon{width:60px;height:60px;border-radius:50%;background:var(--red-soft);color:var(--red);
  display:flex;align-items:center;justify-content:center;font-size:26px;margin:0 auto 16px}
.upd-box h2{font-size:19px;font-weight:800;margin-bottom:8px}
.upd-box p{font-size:13.5px;color:var(--text-2);line-height:1.6;margin-bottom:6px}
.upd-warn{margin:14px 0 18px;padding:10px 14px;border-radius:var(--radius-sm);font-size:12.5px;
  background:rgba(245,158,11,.10);border:1px solid rgba(245,158,11,.30);color:#f59e0b}
.upd-actions{display:flex;gap:10px;flex-wrap:wrap}
.upd-actions a,.upd-actions button{flex:1;min-width:130px;padding:11px 16px;border-radius:var(--radius-sm);
  font-family:var(--font);font-size:13.5px;font-weight:700;cursor:pointer;text-decoration:none;
  display:inline-flex;align-items:center;justify-content:center;gap:7px}
.upd-go{background:var(--red);border:none;color:#fff}
.upd-later{background:transparent;border:1px solid var(--border-md);color:var(--text-2)}
.upd-later:hover{color:var(--text)}
</style>
<?php if ($precisaLogo): ?>
<div class="upd-overlay" id="logoOverlay" role="dialog" aria-modal="true" aria-labelledby="logoTitle">
  <div class="upd-box">
    <div class="upd-icon"><i class="bi bi-image"></i></div>
    <h2 id="logoTitle">Coloque a logo do seu time</h2>
    <p>Seu time ainda está com a logo padrão do FBA. Suba o escudo de verdade pra aparecer certo pra todo mundo — no Timeline, nos confrontos, em todo lugar.</p>
    <div class="upd-actions">
      <a href="/settings.php#sec-time" class="upd-go"><i class="bi bi-arrow-right-circle"></i>Colocar logo agora</a>
      <button type="button" class="upd-later" id="logoLater">Agora não</button>
    </div>
  </div>
</div>
<script>
  document.getElementById('logoLater')?.addEventListener('click', () => {
    document.getElementById('logoOverlay')?.remove();
  });
</script>
<?php endif; ?>
<?php if ($precisaAtualizarElenco): ?>
<div class="upd-overlay" id="updOverlay" role="dialog" aria-modal="true" aria-labelledby="updTitle">
  <div class="upd-box">
    <div class="upd-icon"><i class="bi bi-clipboard-data"></i></div>
    <h2 id="updTitle">Atualize seu elenco</h2>
    <p>O draft da temporada <?= (int)($temporadaPendente['season_number'] ?? 0) ?> acabou. Antes de seguir, atualize OVR, idade e skills do seu time.</p>
    <div class="upd-warn"><i class="bi bi-exclamation-triangle-fill me-1"></i>Enquanto não atualizar, você não consegue enviar nem receber propostas de trade.</div>
    <div class="upd-actions">
      <a href="/atualizar-elenco.php" class="upd-go"><i class="bi bi-arrow-right-circle"></i>Atualizar agora</a>
      <button type="button" class="upd-later" id="updLater">Agora não</button>
    </div>
  </div>
</div>
<script>
  // "Agora não" só fecha nesta visita — a cobrança volta no próximo carregamento,
  // porque o que decide é o servidor (o elenco ainda não foi atualizado).
  document.getElementById('updLater')?.addEventListener('click', () => {
    document.getElementById('updOverlay')?.remove();
  });
</script>
<?php endif; ?>
<?php endif; ?>

<?php if ($team && $queridoLeague !== ''): ?>
<!-- ── Queridômetro: popup de voto da temporada ── -->
<div class="quer-overlay" id="queridoOverlay" role="dialog" aria-modal="true" aria-labelledby="queridoTitle" style="display:none">
  <div class="quer-box">
    <button type="button" class="quer-close" id="queridoClose" aria-label="Fechar"><i class="bi bi-x-lg"></i></button>
    <div class="quer-head">
      <div class="quer-icon"><i class="bi bi-emoji-heart-eyes-fill"></i></div>
      <h2 id="queridoTitle">Queridômetro da <?= htmlspecialchars($queridoLeague) ?></h2>
    </div>

    <?php if ($precisaVotarQuerido): ?>
    <p class="quer-sub">Escolha um GM diferente pra cada categoria — não dá pra repetir nem votar em você mesmo. É um voto por temporada, então escolha com calma.</p>
    <div>
      <?php foreach (queridometroCategorias() as $catKey => $catLabel): ?>
      <div class="quer-field">
        <label for="quer-<?= $catKey ?>">
          <?= htmlspecialchars($catLabel) ?>
          <span tabindex="0" data-bs-toggle="tooltip" data-bs-placement="top"
                title="<?= htmlspecialchars(queridometroDescricoes()[$catKey] ?? '') ?>"
                style="cursor:help;text-transform:none;letter-spacing:0"><i class="bi bi-info-circle"></i></span>
        </label>
        <select id="quer-<?= $catKey ?>" data-cat="<?= $catKey ?>">
          <option value="">Escolha um GM...</option>
          <?php foreach ($queridoTimes as $tt): ?>
          <option value="<?= (int)$tt['id'] ?>"><?= htmlspecialchars((string)($tt['name'] ?? '')) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endforeach; ?>
      <div class="quer-erro" id="queridoErro"></div>
      <div class="quer-actions">
        <button type="button" id="queridoSalvar"><i class="bi bi-check-lg me-1"></i>Confirmar votos</button>
      </div>
    </div>
    <?php else: ?>
    <div class="quer-ok">
      <i class="bi bi-check-circle-fill"></i>
      <p class="quer-sub" style="margin-top:0">Você já votou nesta temporada. O placar no card mostra o Top 3 de cada categoria — o voto reabre quando a temporada virar.</p>
    </div>
    <?php endif; ?>
  </div>
</div>
<script>
(function () {
  const overlay = document.getElementById('queridoOverlay');
  const jaVotou = <?= json_encode(!$precisaVotarQuerido) ?>;

  window.queridoAbrirModal = function () {
    if (overlay) overlay.style.display = 'flex';
  };
  function queridoFechar() {
    if (overlay) overlay.style.display = 'none';
  }
  document.getElementById('queridoClose')?.addEventListener('click', queridoFechar);
  overlay?.addEventListener('click', (e) => { if (e.target === overlay) queridoFechar(); });

  // Placar ao vivo: atualiza o Top 3 do card sozinho conforme os votos vão
  // chegando, sem precisar recarregar a página. Roda pra todo mundo, tenha
  // ou não votado nesta temporada.
  function queridoEscapeHtml(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }
  function queridoRenderTop3(cat, lista) {
    const el = document.getElementById('querido-top3-' + cat);
    if (!el) return;
    if (!lista || !lista.length) {
      el.innerHTML = '<span class="querido-vazio">Sem votos ainda essa temporada</span>';
      return;
    }
    el.innerHTML = lista.map(t => {
      const nome = queridoEscapeHtml((t.name || '').trim());
      const foto = t.photo_url && String(t.photo_url).trim() !== '' ? t.photo_url : '/img/default-avatar.png';
      return '<div class="querido-chip">'
        + '<img src="' + queridoEscapeHtml(foto) + '" alt="" onerror="this.src=\'/img/default-avatar.png\'">'
        + '<span class="querido-nome">' + nome + '</span>'
        + '<span class="querido-votos">' + (parseInt(t.votos, 10) || 0) + '</span>'
        + '</div>';
    }).join('');
  }
  async function queridoAtualizarPlacar() {
    try {
      const res = await fetch('/api/queridometro.php?action=estado');
      const data = await res.json();
      if (!data.success) return;
      Object.keys(data.top3 || {}).forEach(cat => queridoRenderTop3(cat, data.top3[cat]));
    } catch (e) { /* silencioso: só não atualiza dessa vez, tenta de novo no próximo ciclo */ }
  }
  setInterval(queridoAtualizarPlacar, 20000);

  document.querySelectorAll('.querido-cat [data-bs-toggle="tooltip"], .quer-field [data-bs-toggle="tooltip"]')
    .forEach(el => bootstrap.Tooltip.getOrCreateInstance(el, { trigger: 'hover focus' }));

  if (!jaVotou) {
    const categorias = <?= json_encode(array_keys(queridometroCategorias())) ?>;

    function queridoAtualizarDisponibilidade() {
      const selecionados = categorias.map(c => document.getElementById('quer-' + c)?.value).filter(v => v);
      categorias.forEach(c => {
        const sel = document.getElementById('quer-' + c);
        if (!sel) return;
        Array.from(sel.options).forEach(opt => {
          if (!opt.value) return;
          opt.disabled = selecionados.includes(opt.value) && sel.value !== opt.value;
        });
      });
    }
    categorias.forEach(c => {
      document.getElementById('quer-' + c)?.addEventListener('change', queridoAtualizarDisponibilidade);
    });

    const erro = document.getElementById('queridoErro');
    const btn = document.getElementById('queridoSalvar');
    btn?.addEventListener('click', async () => {
      erro.style.display = 'none';
      const votos = {};
      for (const c of categorias) {
        const v = document.getElementById('quer-' + c)?.value;
        if (!v) {
          erro.textContent = 'Escolha um GM pra todas as categorias.';
          erro.style.display = 'block';
          return;
        }
        votos[c] = parseInt(v, 10);
      }
      btn.disabled = true;
      try {
        const res = await fetch('/api/queridometro.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ action: 'votar', votos }),
        });
        const data = await res.json();
        if (!res.ok || !data.success) throw new Error(data.error || 'Erro ao votar.');
        window.location.reload();
      } catch (e) {
        erro.textContent = e.message;
        erro.style.display = 'block';
        btn.disabled = false;
      }
    });

    // Popup automático ao entrar — uma vez por temporada, até o time votar.
    window.queridoAbrirModal();
  }
})();
</script>
<?php endif; ?>
</body>
</html>