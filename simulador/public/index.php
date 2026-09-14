<?php
declare(strict_types=1);
error_reporting(0);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

require_once dirname(__DIR__) . '/src/Accounts.php';
require_once dirname(__DIR__) . '/src/helpers.php';
require_once dirname(__DIR__) . '/src/Installer.php';

Accounts::startSession();

$action = $_GET['action'] ?? null;

// ============ LOGIN: é o do site (o simulador é um jogo do FBA Games) ============
// Quem não está logado vai pro login da FBA e volta direto pro jogo (?next=).
if (in_array($action, ['login', 'register', 'logout'], true)) {
    header('Location: ' . ($action === 'logout' ? '/games.php' : '/login.php?next=' . rawurlencode(APP_BASE . '/')));
    exit;
}
if (!Accounts::userId()) {
    header('Location: /login.php?next=' . rawurlencode(APP_BASE . '/'));
    exit;
}
if ((int) ($_SESSION['user_approved'] ?? 1) === 0) { header('Location: /pending-approval.php'); exit; }

// ============ AÇÕES DE SAVE ============
if ($action === 'create-save') {
    try {
        $r = Accounts::createSave(
            Accounts::userId(),
            (int) ($_POST['slot'] ?? 0),
            $_POST['name'] ?? '',
            $_POST['gm_name'] ?? '',
            $_POST['team'] ?? '',
            $_POST['era'] ?? 'modern',
            $_POST['coach_style'] ?? 'equilibrado',
            $_POST['difficulty'] ?? 'normal',
            $_POST['potential_type'] ?? 'real'
        );
        if (isset($r['error'])) { header('Location: ' . url('saves', ['err' => $r['error']])); exit; }
        header('Location: ' . url('home', ['newsave' => 1])); exit;
    } catch (Throwable $e) {
        header('Location: ' . url('saves', ['err' => 'Erro ao criar save: ' . $e->getMessage()])); exit;
    }
}
if ($action === 'load-save') {
    $r = Accounts::activate((int) ($_GET['save'] ?? 0));
    if (isset($r['error'])) { header('Location: ' . url('saves', ['err' => $r['error']])); exit; }
    header('Location: ' . url('home')); exit;
}
if ($action === 'delete-save') {
    Accounts::deleteSave((int) ($_GET['save'] ?? 0));
    header('Location: ' . url('saves')); exit;
}

// ============ RESOLVE O SAVE ATIVO ============
$saveId = Accounts::activeSaveId();
if ($saveId) {
    $act = Accounts::activate($saveId);
    if (isset($act['error'])) { Accounts::forgetActiveSave(); $saveId = null; }
}
if (!Accounts::activeSaveId()) {
    $page = $_GET['p'] ?? 'saves';
    if ($page !== 'saves') { header('Location: ' . url('saves')); exit; }
    require dirname(__DIR__) . '/src/views/saves.php';
    exit;
}

// ============ AÇÕES DO JOGO (save ativo) ============
if ($action) {
    switch ($action) {
        case 'advance':
            $watermark = League::inboxWatermark();
            $label = League::dateLabel(League::currentDay());
            $r = League::advanceDay();
            if (!empty($r['fired'])) { header('Location: ' . url('gmselect')); exit; }
            if (!empty($r['blocked'])) { header('Location: ' . url('cap', ['err' => $r['msg']])); exit; }
            if (!empty($r['gm_game_pending'])) { header('Location: ' . url('game', ['id' => $r['game_id'], 'live' => 1])); exit; }
            // Auto-save a cada 5 dias de jogo
            $autoSaved = (League::currentDay() % 5 === 0);
            if ($autoSaved) Accounts::touch((int) Accounts::activeSaveId());
            $recapParams = ['since' => $watermark, 'label' => $label, 'back' => $_GET['back'] ?? url('home')];
            if ($autoSaved) $recapParams['autosaved'] = '1';
            header('Location: ' . url('recap', $recapParams));
            exit;
        case 'sim-season':
            $watermark = League::inboxWatermark();
            $r = League::simulateToEnd();
            Accounts::touch((int) Accounts::activeSaveId());
            if (!empty($r['fired'])) { header('Location: ' . url('gmselect')); exit; }
            if (!empty($r['blocked'])) { header('Location: ' . url('cap', ['err' => $r['msg']])); exit; }
            header('Location: ' . url('recap', ['since' => $watermark, 'label' => 'Temporada regular simulada', 'back' => url('standings'), 'autosaved' => '1']));
            exit;
        case 'sim-days':
            $watermark = League::inboxWatermark();
            $n = max(1, min(30, (int) ($_GET['n'] ?? 7)));
            $r = League::simulateDays($n);
            Accounts::touch((int) Accounts::activeSaveId());
            if (!empty($r['fired'])) { header('Location: ' . url('gmselect')); exit; }
            if (!empty($r['blocked'])) { header('Location: ' . url('cap', ['err' => $r['msg']])); exit; }
            header('Location: ' . url('recap', ['since' => $watermark, 'label' => "$n dias simulados", 'back' => url('home'), 'autosaved' => '1']));
            exit;
        case 'sim-round':
            $watermark = League::inboxWatermark();
            League::simulatePlayoffRound();
            Accounts::touch((int) Accounts::activeSaveId());
            header('Location: ' . url('recap', ['since' => $watermark, 'label' => 'Rodada dos playoffs simulada', 'back' => url('playoffs'), 'autosaved' => '1']));
            exit;
        case 'release':
            $gm = League::gmTeam();
            $r = $gm ? Cap::release($gm, (int) ($_GET['pid'] ?? 0), 'gm') : ['error' => 'Sem franquia.'];
            $back = in_array($_GET['back'] ?? '', ['cap', 'lineup', 'manage'], true) ? $_GET['back'] : 'cap';
            header('Location: ' . url($back, isset($r['error']) ? ['err' => $r['error']] : ['msg' => '🚪 ' . $r['desc'] . '.']));
            exit;
        case 'preseason-advance':
            $watermark = League::inboxWatermark();
            $label = 'Dia ' . League::preseasonDay() . '/' . League::PRESEASON_DAYS . ' da pré-temporada';
            $r = League::advancePreseasonDay();
            if (!empty($r['blocked'])) { header('Location: ' . url('cap', ['err' => $r['msg']])); exit; }
            $autoSaved = (League::preseasonDay() % 5 === 0);
            if ($autoSaved) Accounts::touch((int) Accounts::activeSaveId());
            $recapParams = ['since' => $watermark, 'label' => $label, 'back' => url('preseason')];
            if ($autoSaved) $recapParams['autosaved'] = '1';
            header('Location: ' . url('recap', $recapParams));
            exit;
        case 'preseason-finish':
            // pula direto para a temporada (encerra a janela) — resumo mostra tudo que rolou
            $watermark = League::inboxWatermark();
            $guard = 0;
            while (League::phase() === 'preseason' && $guard++ < 40) {
                $r = League::advancePreseasonDay();
                if (!empty($r['blocked'])) { header('Location: ' . url('cap', ['err' => $r['msg']])); exit; }
            }
            Accounts::touch((int) Accounts::activeSaveId());
            header('Location: ' . url('recap', ['since' => $watermark, 'label' => 'Fim da pré-temporada', 'back' => url('home'), 'autosaved' => '1']));
            exit;
        case 'guide-skip':
            Database::setMeta('guide_done', '1');
            header('Location: ' . url('home'));
            exit;
        case 'inbox-read':
            League::inboxMarkRead();
            header('Location: ' . url('inbox'));
            exit;
        case 'resign':
            $r = League::resignPlayer((int) ($_GET['pid'] ?? 0), ($_GET['choice'] ?? '') === 'accept');
            header('Location: ' . url($_GET['back'] ?? 'inbox', ['msg' => $r['msg'] ?? $r['error'] ?? '']));
            exit;
        case 'set-gm':
            $r = League::hireAt((int) ($_GET['team'] ?? 0));
            if (isset($r['error'])) { header('Location: ' . url('gmselect', ['err' => $r['error']])); exit; }
            Accounts::conn()->prepare("UPDATE saves SET team_abbr=? WHERE id=?")->execute([$r['team']['abbr'], (int) Accounts::activeSaveId()]);
            header('Location: ' . url('home', ['dmsg' => '🤝 Você assumiu o ' . $r['team']['city'] . ' ' . $r['team']['name'] . '. Boa sorte na nova casa!']));
            exit;
        case 'dev-focus':
        case 'trade-block':
            $r = $action === 'dev-focus'
                ? League::toggleDevFocus((int) ($_GET['pid'] ?? 0))
                : League::toggleTradeBlock((int) ($_GET['pid'] ?? 0));
            $back = in_array($_GET['back'] ?? '', ['lineup', 'trades', 'manage'], true) ? $_GET['back'] : 'lineup';
            header('Location: ' . url($back, ['msg' => $r['msg'] ?? ('⚠️ ' . $r['error'])]));
            exit;
        case 'next-season':
            $watermark = League::inboxWatermark();
            $r = League::nextSeason();
            if (!empty($r['fired'])) { header('Location: ' . url('gmselect')); exit; }
            Accounts::touch((int) Accounts::activeSaveId());
            header('Location: ' . url('recap', ['since' => $watermark, 'label' => 'Entressafra', 'back' => url('home'), 'autosaved' => '1']));
            exit;
        case 'start-draft':
            require_once dirname(__DIR__) . '/src/Offseason.php';
            $r = Offseason::startDraftFromLottery();
            if (($r['phase'] ?? '') === 'freeagency') { header('Location: ' . url('freeagency')); exit; }
            header('Location: ' . url('draftroom'));
            exit;
        case 'draft-pick':
            require_once dirname(__DIR__) . '/src/Offseason.php';
            $r = Offseason::userPick((int) ($_GET['prospect'] ?? 0));
            if (($r['phase'] ?? '') === 'freeagency') { header('Location: ' . url('freeagency')); exit; }
            if (($r['phase'] ?? '') === 'regular' || ($r['done'] ?? false)) { header('Location: ' . url('home', ['newseason' => 1])); exit; }
            header('Location: ' . url('draftroom', isset($r['error']) ? ['err' => $r['error']] : []));
            exit;
        case 'sign-fa':
            require_once dirname(__DIR__) . '/src/Offseason.php';
            $gm = League::gmTeam();
            $season = League::phase() === 'freeagency' ? (int) Database::meta('fa_season', League::season() + 1) : League::season();
            if (!Cap::signingOpen()) $res = ['error' => 'Contratações fechadas nesta fase.'];
            else $res = $gm ? Offseason::signFreeAgent($gm, (int) ($_GET['fa'] ?? 0), $season) : ['error' => 'Sem franquia.'];
            header('Location: ' . url('freeagency', ['msg' => $res['error'] ?? $res['msg'] ?? '✅ Contratação concluída.']));
            exit;
        case 'finish-fa':
            require_once dirname(__DIR__) . '/src/Offseason.php';
            if ($block = Cap::gmBlockMessage('começar a temporada', false)) { header('Location: ' . url('cap', ['err' => $block])); exit; }
            $watermark = League::inboxWatermark();
            Offseason::finishFreeAgency();
            header('Location: ' . url('recap', ['since' => $watermark, 'label' => 'Início da temporada', 'back' => url('home')]));
            exit;
        case 'sim-game-ai':
            League::simulateGame((int) ($_GET['id'] ?? 0));
            header('Location: ' . url('game', ['id' => (int) ($_GET['id'] ?? 0)]));
            exit;
        case 'sim-game':
            League::simulateGame((int) ($_GET['id'] ?? 0));
            header('Location: ' . url('game', ['id' => (int) ($_GET['id'] ?? 0)]));
            exit;
        case 'save-scheme':
            League::setScheme((int) $_POST['team'], $_POST['scheme_off'] ?? '', $_POST['scheme_def'] ?? '');
            header('Location: ' . url('manage', ['saved' => 'scheme']));
            exit;
        case 'save-rotation':
            $minutes = [];
            foreach (($_POST['min'] ?? []) as $pid => $m) { $minutes[(int) $pid] = (int) $m; }
            $starters = array_map('intval', $_POST['starter'] ?? []);
            $res = League::setRotation((int) $_POST['team'], $minutes, $starters);
            header('Location: ' . url('manage', ['saved' => 'rotation', 'w' => implode('|', $res['warnings'])]));
            exit;
        case 'decide':
            $res = League::resolveDecision((int) ($_GET['id'] ?? 0), $_GET['choice'] ?? '');
            $msg = $res['msg'] ?? ($res['error'] ?? '');
            header('Location: ' . ($_GET['back'] ?? url('home', ['dmsg' => $msg])));
            exit;
        case 'boost-morale':
            // Conversar com jogador: +8 morale, max 95
            $pid = (int)($_GET['pid'] ?? 0);
            if ($pid) {
                Database::conn()->prepare(
                    "UPDATE players SET morale = MIN(95, morale + 8) WHERE id=?"
                )->execute([$pid]);
            }
            header('Location: ' . url('lineup', ['msg' => '💬 Conversa motivacional realizada!']));
            exit;
        case 'rest-player':
            // Descansar jogador: +2 dias de rest, remove da rotação temporariamente
            $pid = (int)($_GET['pid'] ?? 0);
            if ($pid) {
                Database::conn()->prepare(
                    "UPDATE players SET rest_games = MIN(5, rest_games + 2), min_target = MAX(0, min_target - 5) WHERE id=?"
                )->execute([$pid]);
            }
            header('Location: ' . url('lineup', ['msg' => '😴 Jogador descansado.']));
            exit;
        case 'save-coach':
            // Só o nome: os atributos do técnico agora pesam no jogo e vêm do estilo escolhido no save.
            $coach = League::gmCoach();
            if ($coach) {
                League::saveCoach((int)$coach['id'], trim($_POST['coach_name'] ?? $coach['name']) ?: $coach['name'], [
                    'ofensivo' => $coach['ofensivo'], 'defensivo' => $coach['defensivo'], 'desenvolvimento' => $coach['desenvolvimento'],
                    'gestao' => $coach['gestao'], 'intensidade' => $coach['intensidade'],
                ]);
            }
            header('Location: ' . url('manage', ['saved' => 'coach']));
            exit;
        case 'propose-trade':
            $gm = League::gmTeam() ?? 0;
            $give = array_map('intval', $_POST['give'] ?? []);
            $get = array_map('intval', $_POST['get'] ?? []);
            $givePk = array_map('intval', $_POST['give_pick'] ?? []);
            $getPk = array_map('intval', $_POST['get_pick'] ?? []);
            $ai = (int) ($_POST['ai_team'] ?? 0);
            $r = League::executeProposedTrade($gm, $give, $ai, $get, $givePk, $getPk);
            $msg = ($r['accept'] ?? false ? '✅ ' : '❌ ') . ($r['reason'] ?? 'Erro');
            $params = ['ai' => $ai, 'msg' => $msg];
            // Se recusou e tem contraproposta, passa pela URL
            if (!($r['accept'] ?? false) && !empty($r['counter'])) {
                $params['counter'] = implode(',', $r['counter']);
                $params['cgive']   = implode(',', $r['counter_give'] ?? []);
            }
            header('Location: ' . url('trades', $params));
            exit;
    }
}

// ============ SEGURANÇA: save sem schema (não deveria ocorrer) ============
if (!Database::isInstalled()) {
    Installer::run();
    $t = League::allTeams();
    // mantém a franquia registrada no save, se houver
    $s = Accounts::save((int) Accounts::activeSaveId());
    if ($s && $s['team_abbr'] && ($tm = League::teamByAbbr($s['team_abbr']))) League::setGmTeam((int) $tm['id']);
}

// ============ ROTEAMENTO DE PÁGINAS ============
$page = $_GET['p'] ?? 'home';
// Guia do save novo: abrir a escalação e a folha conta como passo cumprido.
if (in_array($page, ['lineup', 'cap'], true) && League::phase() === 'preseason' && Database::meta('guide_done') !== '1') {
    Database::setMeta($page === 'lineup' ? 'guide_lineup' : 'guide_cap', '1');
}
$views = dirname(__DIR__) . '/src/views/';
$map = [
    'home' => 'home.php',
    'standings' => 'standings.php',
    'schedule' => 'schedule.php',
    'teams' => 'teams.php',
    'team' => 'team.php',
    'player' => 'player.php',
    'game' => 'game.php',
    'leaders' => 'leaders.php',
    'cap' => 'cap.php',
    'playoffs' => 'playoffs.php',
    'history' => 'history.php',
    'draft' => 'draft.php',
    'manage' => 'manage.php',
    'lineup' => 'lineup.php',
    'trades' => 'trades.php',
    'gmselect' => 'gmselect.php',
    'draftroom' => 'draftroom.php',
    'power' => 'power.php',
    'freeagency' => 'freeagency.php',
    'lottery' => 'lottery.php',
    'preseason' => 'preseason.php',
    'inbox' => 'inbox.php',
    'recap' => 'recap.php',
    'awards' => 'awards.php',
    'search' => 'search.php',
    'saves' => 'saves.php',
];
$file = $views . ($map[$page] ?? 'home.php');
if (!file_exists($file)) $file = $views . 'home.php';
require $file;
