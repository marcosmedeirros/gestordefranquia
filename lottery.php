<?php
require_once __DIR__ . '/backend/auth.php';
require_once __DIR__ . '/backend/db.php';
require_once __DIR__ . '/backend/helpers.php';
require_once __DIR__ . '/backend/loteria_grupos.php';   // sprint ativa e regras da loteria

/* MODO ENSAIO. Ligado pelo lottery-teste.php, que só existe pra dar um
   endereço limpo a isto. Aqui a cerimônia roda inteira e não conta: o
   sorteio nunca escreveu no banco, e o "Confirmar" — a ação que aplica a
   ordem ao draft — não é montado. Por isso qualquer um pode sortear e
   mexer na ordem: não há o que estragar.

   E por isso o ensaio também dispensa login. A página serve pra explicar o
   modelo pra quem ainda vai entrar na liga, e um link que pede senha antes
   de mostrar qualquer coisa não explica nada a ninguém. O que ela expõe —
   nomes de times e porcentagens — é o mesmo que a liga anuncia no
   comunicado. */
$modoTeste = !empty($MODO_TESTE_LOTERIA);

if (!$modoTeste) requireAuth();
$user = getUserSession() ?: ['id' => 0, 'user_type' => 'visitante', 'name' => 'Visitante', 'league' => ''];
$pdo  = db();
$ehVisitante = empty($user['id']);

$isGlobalAdmin = !$ehVisitante && ($user['user_type'] ?? 'jogador') === 'admin';
$adminLeagues  = $ehVisitante ? [] : getAdminLeagues($pdo, (int)$user['id']);
// Qualquer jogador pode ver a loteria (regras + ordem já sorteada); só quem
// administra ELITE/NEXT/RISE/ROOKIE consegue de fato rodar a cerimônia e confirmar.
$canRunLottery = $isGlobalAdmin || !empty($adminLeagues);

/* O TIME VEM DE timeDaTela, NÃO DE user_id.
   No modo observador, o admin continua sendo dono do time dele — que é de
   outra liga. Buscando por user_id, esta tela via a liga real e ignorava o
   óculos: quem observava a RISE caía na loteria da ELITE. timeDaTela é a
   mesma função que o drafts.php usa e devolve o time da liga observada. */
require_once __DIR__ . '/backend/observador.php';
$team = $ehVisitante ? null : timeDaTela($pdo, (int)$user['id']);

/* QUEM VÊ O QUÊ.
   Quem administra vê as ligas que administra. Quem não administra vê a
   própria — a prévia da loteria (quem entra, em que grupo, com que chance)
   é a mesma informação que o comunicado anuncia, e escondê-la dos GMs só
   fazia a pergunta chegar por mensagem. Editar e sortear seguem sendo do
   admin: quem não é vê a tela inteira sem um único controle. */
$LIGAS_LOTERIA = ['ELITE', 'NEXT', 'RISE', 'ROOKIE'];

/* A loteria oficial é sempre a do draft em CONFIGURAÇÃO — é a única que
   ainda não aconteceu. O ensaio não pode ter essa exigência: fora da janela
   entre uma temporada e outra não existe draft em configuração em liga
   nenhuma, e a página de aprender ficaria em branco justamente nos meses em
   que alguém procuraria por ela. Lá, vale o draft mais recente de cada liga,
   qualquer que seja o estado dele — o que se ensaia é o sorteio, e ele roda
   igual sobre qualquer temporada com classificação lançada. */
$buscarSessoes = function (array $ligas) use ($pdo, $LIGAS_LOTERIA, $modoTeste) {
    $ligas = array_values(array_intersect($LIGAS_LOTERIA, $ligas));
    if (!$ligas) return [];
    $ph = implode(',', array_fill(0, count($ligas), '?'));

    /* SÓ A SPRINT ATUAL. A liga recomeça a cada sprint e as temporadas
       antigas ficam no banco com a numeração que tinham — sem este corte,
       a Temporada 20 de uma sprint encerrada volta a disputar com a atual. */
    $sprints = [];
    foreach ($ligas as $lg) {
        $sp = loteriaSprintAtiva($pdo, $lg);
        if ($sp !== null) $sprints[] = $sp;
    }
    if (!$sprints) return [];
    $phSprint = implode(',', array_fill(0, count($sprints), '?'));

    if (!$modoTeste) {
        /* Uma loteria por liga: a da temporada mais alta em configuração.
           Sem esse corte, uma sessão antiga esquecida nesse estado — e elas
           existem — disputa com a atual e pode vencer, colocando a liga
           diante da loteria de uma temporada que já passou. */
        $st = $pdo->prepare("
            SELECT ds.id, ds.status, ds.league, s.season_number, s.year
            FROM draft_sessions ds
            JOIN seasons s ON s.id = ds.season_id
            WHERE ds.league IN ($ph) AND ds.status = 'setup'
              AND s.sprint_id IN ($phSprint)
            ORDER BY FIELD(ds.league,'ELITE','NEXT','RISE','ROOKIE'), s.season_number DESC, ds.id DESC
        ");
        $st->execute(array_merge($ligas, $sprints));
        $umaPorLiga = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $s) {
            if (!isset($umaPorLiga[$s['league']])) $umaPorLiga[$s['league']] = $s;
        }
        return array_values($umaPorLiga);
    }

    /* Ensaio: a temporada MAIS RECENTE de cada liga, e só isso.
       Preferir a sessão em configuração parecia melhor — é a loteria que a
       liga tem em mente — mas sobra por aí sessão antiga esquecida nesse
       estado, e uma delas venceu a disputa: a página abria na Temporada 1 de
       2025, sem classificação pra sortear, e não mostrava nada. A temporada
       mais alta é a única escolha que não depende de arrumação de status. */
    $st = $pdo->prepare("
        SELECT ds.id, ds.status, ds.league, s.season_number, s.year
        FROM draft_sessions ds
        JOIN seasons s ON s.id = ds.season_id
        WHERE ds.league IN ($ph)
          AND s.sprint_id IN ($phSprint)
          AND EXISTS (
                SELECT 1 FROM season_standings ss
                  JOIN seasons s2 ON s2.id = ss.season_id
                 WHERE s2.league = s.league
                   AND s2.season_number <= s.season_number
              )
        ORDER BY FIELD(ds.league,'ELITE','NEXT','RISE','ROOKIE'),
                 s.season_number DESC, ds.id DESC
    ");
    $st->execute(array_merge($ligas, $sprints));

    $porLiga = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $s) {
        if (!isset($porLiga[$s['league']])) $porLiga[$s['league']] = $s;
    }
    return array_values($porLiga);
};

/* A PÁGINA É DE UMA LIGA POR VEZ, E DÁ PRA TROCAR.
   Antes a liga era imposta: o admin via só as que administra e o GM só a
   própria, sem escolha nenhuma. Quem administra a NEXT e queria conferir a
   loteria da ELITE encontrava a mensagem de "nenhuma sessão encontrada",
   como se nada existisse em lugar nenhum.

   Agora a liga entra na URL e vira aba. As abas são as ligas que a pessoa
   conduz: o admin geral vê as quatro, o admin de uma liga vê a dela, e quem
   não administra fica na própria — que é a única que lhe diz respeito, e
   por isso nem aba tem. */
$minhaLiga = strtoupper((string)($team['league'] ?? $user['league'] ?? ''));
/* ESTA TELA É A LOTERIA DE QUEM ESTÁ OLHANDO — a da liga onde a pessoa
   joga. Sem seletor, sem abas: um GM abre aqui pra acompanhar a cerimônia
   da própria liga, e não pra escolher qual quer ver.

   Quem administra uma liga onde não joga chega por outro caminho: o card
   Loteria dentro daquela liga, no painel de admin, que manda a liga na URL.
   Conduzir o sorteio é ato de administração de uma liga específica, e o
   lugar disso é o painel dela — não uma tela que todos os GMs abrem. */
$ligaPedida = strtoupper((string)($_GET['liga'] ?? ''));
$podeConduzirPedida = in_array($ligaPedida, $LIGAS_LOTERIA, true)
    && ($isGlobalAdmin || in_array($ligaPedida, $adminLeagues, true));

if ($modoTeste) {
    $ligaAtual = in_array($ligaPedida, $LIGAS_LOTERIA, true) ? $ligaPedida : 'ELITE';
} elseif ($podeConduzirPedida) {
    $ligaAtual = $ligaPedida;                                  // veio do painel de admin
} elseif (in_array($minhaLiga, $LIGAS_LOTERIA, true)) {
    $ligaAtual = $minhaLiga;                                   // a liga onde a pessoa joga
} else {
    // Sem franquia: cai na primeira que administra, se administrar alguma.
    $porAdmin  = array_values(array_intersect($LIGAS_LOTERIA, $adminLeagues));
    $ligaAtual = $isGlobalAdmin ? 'ELITE' : ($porAdmin[0] ?? '');
}

/* Conduzir continua sendo de quem administra a liga aberta. Como a tela abre
   na liga de quem olha, na prática só aparece pra quem administra a própria
   liga; nos outros casos, quem chega pelo painel de admin. */
$podeConduzirEstaLiga = $ligaAtual !== ''
    && ($modoTeste || $isGlobalAdmin || in_array($ligaAtual, $adminLeagues, true));

$setupSessions = $ligaAtual ? $buscarSessoes([$ligaAtual]) : [];

// Ordem já confirmada (via "Confirmar e aplicar ao draft") da liga aberta na
// tela — é o que se mostra quando não há loteria em curso.
$myViewLeague = $ligaAtual;
$confirmedOrder = [];
$confirmedSessionInfo = null;
if (in_array($myViewLeague, $LIGAS_LOTERIA, true)) {
    // Também só da sprint atual: a ordem de um draft de sprint encerrada não
    // é "a ordem desta temporada" pra ninguém que esteja jogando hoje.
    $stmtLastSession = $pdo->prepare("
        SELECT ds.id, s.season_number, s.year
        FROM draft_sessions ds
        JOIN seasons s ON s.id = ds.season_id
        WHERE ds.league = ? AND s.sprint_id = ?
        ORDER BY s.season_number DESC, ds.id DESC
        LIMIT 1
    ");
    $stmtLastSession->execute([$myViewLeague, loteriaSprintAtiva($pdo, $myViewLeague) ?? 0]);
    $lastSession = $stmtLastSession->fetch(PDO::FETCH_ASSOC);
    if ($lastSession) {
        $stmtOrder = $pdo->prepare("
            SELECT do.pick_position, CONCAT(t.city,' ',t.name) AS team_name, t.photo_url, t.conference
            FROM draft_order do
            JOIN teams t ON t.id = do.team_id
            WHERE do.draft_session_id = ? AND do.round = 1
            ORDER BY do.pick_position ASC
        ");
        $stmtOrder->execute([(int)$lastSession['id']]);
        $confirmedOrder = $stmtOrder->fetchAll(PDO::FETCH_ASSOC);
        if ($confirmedOrder) {
            $confirmedSessionInfo = $lastSession;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<script>document.documentElement.dataset.theme = localStorage.getItem('fba-theme') || 'dark';</script>
<?php include __DIR__ . '/includes/head-pwa.php'; ?>
<meta name="theme-color" content="#fc0025">
<title>Loteria do Draft · FBA Manager</title>
<link rel="icon" type="image/png" href="/games/fbagames.png">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800;900&family=Inter:wght@400;500;600;700&family=Oswald:wght@400;600;700&display=swap" rel="stylesheet">
<style>
:root{--red:#fc0025;--red-soft:color-mix(in srgb, var(--red) 10%, transparent);--red-glow:color-mix(in srgb, var(--red) 30%, transparent);--bg:#07070a;--panel:#101013;--panel-2:#16161a;--panel-3:#1c1c21;--border:rgba(255,255,255,.06);--border-md:rgba(255,255,255,.10);--text:#f0f0f3;--text-2:#868690;--text-3:#7d7d85;--amber:#f59e0b;--green:#22c55e;--font:'Montserrat', sans-serif;--radius:14px;--radius-sm:10px;--sidebar-w:260px;--ease:cubic-bezier(.2,.8,.2,1);--t:200ms;--border-red:color-mix(in srgb, var(--red) 22%, transparent)}
:root[data-theme="light"]{--bg:#f6f7fb;--panel:#ffffff;--panel-2:#f2f4f8;--panel-3:#e9edf4;--border:#e3e6ee;--border-md:#d7dbe6;--text:#111217;--text-2:#5b6270;--text-3:#657080}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:var(--font);background:var(--bg);color:var(--text);-webkit-font-smoothing:antialiased}
.icon-btn{width:34px;height:34px;border-radius:10px;background:transparent;border:1px solid var(--border);color:var(--text-2);display:flex;align-items:center;justify-content:center;font-size:15px;cursor:pointer;text-decoration:none;transition:all .2s}
.icon-btn:hover{background:var(--red-soft);border-color:var(--red);color:var(--red)}
.content{padding:20px 32px 40px;width:100%}
/* Cabeçalho padrão do site (mesmo de trades.php) — plano, sem card.
   Fica dentro de .content, então já herda o max-width/padding lateral da coluna. */
.page-hero{display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:14px;margin-bottom:24px}
.page-hero-eyebrow{font-size:11px;font-weight:600;letter-spacing:1.4px;text-transform:uppercase;color:var(--red);margin-bottom:4px}
.page-hero-title{font-size:26px;font-weight:800;color:var(--text);line-height:1.1}
.page-hero-sub{font-size:13px;color:var(--text-2);margin-top:4px;line-height:1.5}
.page-hero-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.section-title{font-family:'Oswald',sans-serif;font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:var(--text-2);margin:22px 0 12px;display:flex;align-items:center;gap:8px}
.section-title i{color:var(--red)}
.info-hint{color:var(--text-3);font-size:12px;cursor:help;margin-left:4px}
.info-hint:hover{color:var(--red)}
.panel{background:var(--panel);border:1px solid var(--border);border-radius:var(--radius);padding:18px 20px;margin-bottom:16px}
.form-row{display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end}
.form-field{flex:1;min-width:200px}
.form-field label{font-size:12px;color:var(--text-2);margin-bottom:6px;display:block}
.form-field select{width:100%;background:var(--panel-3);border:1px solid var(--border);border-radius:8px;padding:9px 10px;color:var(--text);font-size:13px}
.btn-red{background:var(--red);border:none;border-radius:12px;padding:11px 20px;color:#fff;font-family:var(--font);font-weight:700;font-size:13px;cursor:pointer;display:inline-flex;align-items:center;gap:8px;transition:opacity .2s,transform .1s}
.btn-red:hover{opacity:.9}
.btn-red:active{transform:scale(.98)}
.btn-red:disabled{opacity:.45;cursor:not-allowed}
.btn-ghost2{background:transparent;border:1px solid var(--border);border-radius:12px;padding:11px 20px;color:var(--text-2);font-family:var(--font);font-weight:600;font-size:13px;cursor:pointer}
.btn-ghost2:hover{border-color:var(--border-red);color:var(--red)}
.empty{text-align:center;padding:24px;color:var(--text-3);font-size:13px}

/* Palco de revelação */
.reveal-stage{background:linear-gradient(160deg,var(--panel-2),var(--panel));border:1px solid var(--border-md);border-radius:18px;padding:30px 22px;text-align:center;position:relative;overflow:hidden}
.reveal-stage.armed{border-color:var(--border-red);box-shadow:0 0 0 1px var(--red-soft),0 20px 60px -20px var(--red-glow)}
/* camada de efeitos */
.reveal-fx{position:absolute;inset:0;pointer-events:none;z-index:0;overflow:hidden}
.reveal-stage > .reveal-pick,.reveal-stage > .reveal-card,.reveal-stage > .reveal-actions,.reveal-stage > .reveal-hint{position:relative;z-index:1}
/* burst radial ao assentar */
.reveal-stage.flash::after{content:'';position:absolute;left:50%;top:44%;width:16px;height:16px;border-radius:50%;transform:translate(-50%,-50%);pointer-events:none;z-index:0;animation:burstRing .75s ease-out forwards}
@keyframes burstRing{0%{box-shadow:0 0 0 0 var(--red-glow);opacity:.85}100%{box-shadow:0 0 0 380px transparent;opacity:0}}
/* subiu: brilho verde ambiente */
.reveal-stage.rise{animation:riseGlow 1.15s ease-out}
@keyframes riseGlow{0%{box-shadow:0 0 0 2px rgba(34,197,94,.55),0 0 70px -4px rgba(34,197,94,.65)}100%{box-shadow:0 0 0 1px var(--red-soft),0 20px 60px -20px var(--red-glow)}}
/* finale (#1): brilho dourado */
.reveal-stage.finale{animation:finaleGlow 1.4s ease-out}
@keyframes finaleGlow{0%{box-shadow:0 0 0 2px rgba(245,158,11,.6),0 0 90px 0 rgba(245,158,11,.55)}100%{box-shadow:0 0 0 1px var(--red-soft),0 20px 60px -20px var(--red-glow)}}
/* partículas subindo */
.fx-particle{position:absolute;bottom:34%;font-size:20px;font-weight:900;opacity:0;will-change:transform,opacity;animation:floatUp 1.15s ease-out forwards;z-index:0;text-shadow:0 0 8px currentColor}
@keyframes floatUp{0%{opacity:0;transform:translateY(10px) scale(.5)}15%{opacity:1}100%{opacity:0;transform:translateY(-160px) scale(1.25)}}
/* punch no número */
.reveal-number.punch{animation:numPunch .55s cubic-bezier(.2,1.3,.4,1)}
@keyframes numPunch{0%{transform:scale(.55);opacity:.2}55%{transform:scale(1.22)}100%{transform:scale(1)}}
.reveal-pick{font-family:'Oswald',sans-serif;font-size:14px;font-weight:700;letter-spacing:2px;text-transform:uppercase;color:var(--red)}
.reveal-card{margin:16px auto 4px;min-height:200px;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:8px}
.reveal-logo{width:120px;height:120px;border-radius:20px;object-fit:contain;background:var(--panel-3);border:1px solid var(--border-md);padding:8px;transition:opacity .3s,transform .3s}
.reveal-logo.pop{animation:logoPop .5s var(--ease)}
@keyframes logoPop{0%{transform:scale(.7);opacity:.3}60%{transform:scale(1.08)}100%{transform:scale(1)}}
.reveal-number{font-family:'Oswald',sans-serif;font-size:40px;font-weight:800;line-height:1;color:var(--text);opacity:.18}
.reveal-number.on{opacity:1;color:var(--red)}
.reveal-team{font-family:'Oswald',sans-serif;font-size:34px;font-weight:800;line-height:1.1;min-height:38px}
.reveal-team.shuffling{color:var(--text-2);filter:blur(.4px)}
.reveal-team.landed{animation:landPop .4s var(--ease)}
@keyframes landPop{0%{transform:scale(.82) translateY(6px);opacity:.35}55%{transform:scale(1.11) translateY(0)}78%{transform:scale(.97)}100%{transform:scale(1)}}
.reveal-move.up.show{animation:moveUpPop .6s cubic-bezier(.2,1.5,.4,1)}
@keyframes moveUpPop{0%{transform:scale(.5) translateY(18px);opacity:0}50%{transform:scale(1.22) translateY(-5px);opacity:1}100%{transform:scale(1) translateY(0)}}
.reveal-conf{font-size:12px;font-weight:700;letter-spacing:.5px;color:var(--text-3);text-transform:uppercase}
.reveal-move{margin-top:8px;display:inline-flex;align-items:center;gap:6px;font-size:14px;font-weight:800;padding:7px 18px;border-radius:999px;opacity:0;transition:opacity .3s}
.reveal-move.show{opacity:1}
.reveal-move.up{background:rgba(34,197,94,.14);color:var(--green);border:1px solid rgba(34,197,94,.35)}
.reveal-move.down{background:rgba(239,68,68,.14);color:#ef4444;border:1px solid rgba(239,68,68,.35)}
.reveal-move.same{background:var(--panel-3);color:var(--text-2);border:1px solid var(--border-md)}
.reveal-passed{margin-top:10px;display:none;flex-direction:column;align-items:center;gap:6px}
.reveal-passed.show{display:flex}
.reveal-passed-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:var(--text-3)}
.reveal-passed-teams{display:flex;gap:8px;flex-wrap:wrap;justify-content:center}
.reveal-passed-team{display:inline-flex;align-items:center;gap:6px;background:rgba(34,197,94,.10);border:1px solid rgba(34,197,94,.30);border-radius:999px;padding:4px 10px 4px 4px;font-size:12px;font-weight:700;color:var(--green)}
.reveal-passed-team img{width:20px;height:20px;border-radius:6px;object-fit:contain;background:var(--panel-3)}
.reveal-actions{margin-top:18px;display:flex;gap:10px;justify-content:center;flex-wrap:wrap}
.reveal-hint{font-size:11px;color:var(--text-3);margin-top:10px}

/* Globo de bolinhas — sorteio de cada pick */
.ball-machine{position:relative;width:min(210px,62vw);height:min(210px,62vw);margin:0 auto;display:none}
.ball-machine.on{display:block}
.ball-globe{position:absolute;inset:0;border-radius:50%;border:3px solid var(--border-md);overflow:hidden;
  background:radial-gradient(circle at 32% 26%, rgba(255,255,255,.10), transparent 58%), var(--panel-3);
  box-shadow:inset 0 0 42px rgba(0,0,0,.55), 0 0 0 1px var(--red-soft)}
.ball-globe::after{content:'';position:absolute;left:14%;top:10%;width:34%;height:22%;border-radius:50%;
  background:rgba(255,255,255,.09);filter:blur(6px);pointer-events:none}
.lottery-ball{position:absolute;left:50%;top:50%;width:42px;height:42px;margin:-21px 0 0 -21px;border-radius:50%;
  background:var(--panel);border:2px solid var(--border-md);display:flex;align-items:center;justify-content:center;
  overflow:hidden;box-shadow:0 2px 6px rgba(0,0,0,.45);
  animation-name:ballTumble;animation-timing-function:linear;animation-iteration-count:infinite;
  transition:opacity .45s var(--ease)}
.lottery-ball img{width:30px;height:30px;object-fit:contain;pointer-events:none}
@keyframes ballTumble{
  from{transform:rotate(0deg) translateX(var(--r)) rotate(0deg)}
  to{transform:rotate(360deg) translateX(var(--r)) rotate(-360deg)}
}
.ball-globe.slowing .lottery-ball{opacity:.22;animation-duration:3.6s!important}
@keyframes ballDrawn{
  0%{transform:scale(.65);opacity:.35}
  55%{transform:scale(2.15)}
  100%{transform:scale(1.95);opacity:1}
}
.lottery-ball.drawn{z-index:5;opacity:1!important;border-color:var(--red);
  box-shadow:0 0 0 4px var(--red-soft),0 0 34px var(--red-glow);
  animation:ballDrawn .78s cubic-bezier(.2,1.25,.4,1) forwards!important}
@media(prefers-reduced-motion:reduce){
  .lottery-ball{animation:none!important}
  .lottery-ball.drawn{animation:none!important;transform:scale(1.9)}
}

/* Urna — quem ainda está concorrendo */
.bowl-head{display:flex;align-items:baseline;gap:8px;flex-wrap:wrap}
.bowl-count{font-family:'Oswald',sans-serif;font-size:13px;font-weight:800;color:var(--red)}
.bowl{display:grid;grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:10px}
.bowl-tile{display:flex;flex-direction:column;align-items:center;gap:6px;background:var(--panel-2);border:1px solid var(--border);border-radius:12px;padding:12px 8px;text-align:center;transition:all .35s var(--ease)}
.bowl-tile.leaving{opacity:0;transform:scale(.6);filter:grayscale(1)}
.bowl-logo{width:52px;height:52px;border-radius:12px;object-fit:contain;background:var(--panel-3);border:1px solid var(--border-md);padding:4px}
.bowl-name{font-size:11px;font-weight:700;line-height:1.15;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:100%}
.bowl-odds{font-family:'Oswald',sans-serif;font-size:15px;font-weight:800;color:var(--red)}
.bowl-pos{font-size:9px;color:var(--text-3);font-weight:700}
.bowl-empty{color:var(--text-3);font-size:13px;text-align:center;padding:10px}

/* Quadro da ordem */
.board{display:grid;grid-template-columns:repeat(2,1fr);gap:8px}
/* min-width:0 porque o slot é item de grid: sem ele o grid respeita a
   largura mínima do conteúdo e o nome de um time comprido estufa a coluna
   pra fora da tela em vez de truncar. */
.board-slot{display:flex;align-items:center;gap:10px;background:var(--panel-2);border:1px solid var(--border);border-radius:10px;padding:9px 12px;transition:all .3s var(--ease);min-width:0}
.board-slot.pending{opacity:.5;border-style:dashed}
/* Slot editável (só na prévia): opaco, porque aqui o time já é conhecido —
   o "aguardando" tracejado é pra depois do sorteio, antes de revelar. */
.board-slot.editavel{opacity:1;border-style:solid}
/* Com as setas ocupando a direita, o nome é quem tem que ceder — sem o
   min-width:0 ele se recusa a encolher e empurra o slot para fora da tela. */
.board-slot.editavel .board-team{color:var(--text-1);min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.board-move{display:flex;flex-direction:column;gap:2px;margin-left:auto}
.board-move button{width:22px;height:16px;display:flex;align-items:center;justify-content:center;background:var(--panel);border:1px solid var(--border);border-radius:4px;color:var(--text-3);cursor:pointer;font-size:9px;padding:0;line-height:1}
.board-move button:hover:not(:disabled){color:var(--text-1);border-color:var(--red)}
.board-move button:disabled{opacity:.25;cursor:default}
.board-slot.movido{border-color:var(--red);box-shadow:0 0 0 1px var(--red-soft)}
/* Sticky: a tabela de chances tem dezesseis linhas e o quadro trinta e duas.
   Um aviso que rola pra fora da tela é um aviso que ninguém lê — e o preço
   de não lê-lo é perder a edição inteira ao trocar de página. */
.ordem-bar{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;
  position:sticky;top:8px;z-index:30;
  margin-bottom:10px;padding:9px 12px;border-radius:10px;font-size:12px;
  /* Duas camadas: o tom de alerta é translúcido de propósito, então precisa
     de um fundo opaco atrás — grudada no topo, ela passa por cima da tabela,
     e o texto das linhas atravessando o aviso o torna ilegível. */
  background:linear-gradient(var(--red-soft),var(--red-soft)),var(--panel-2);
  border:1px solid var(--border-red);color:var(--text-1);
  box-shadow:0 4px 14px rgba(0,0,0,.35)}
.ordem-bar-acoes{display:flex;gap:8px;flex-shrink:0}
@media(max-width:640px){
  .ordem-bar{font-size:11px}.ordem-bar-acoes{width:100%}.ordem-bar-acoes button{flex:1}
  /* No dedo, 16px de altura é alvo de errar: as setas crescem. */
  .board-move button{width:34px;height:24px;font-size:11px}
  /* No celular o selo não cabe junto das setas, e a posição 17 em diante já
     diz que dali pra baixo é playoff. */
  .board-slot.editavel .board-tag.playoff{display:none}
  /* A tabela de chances rola dentro do próprio painel; um seletor de 230px
     empurra as colunas de porcentagem pra longe demais desse scroll. */
  .balls-table .grupo-sel{max-width:132px}
}
.board-slot.locked{opacity:.62}
.board-slot.just{border-color:var(--red);box-shadow:0 0 0 1px var(--red-soft);animation:slotIn .45s var(--ease)}
@keyframes slotIn{0%{transform:translateY(6px);opacity:.3}100%{transform:translateY(0);opacity:1}}
.board-pos{font-family:'Oswald',sans-serif;font-size:16px;font-weight:800;color:var(--red);width:26px;text-align:center;flex-shrink:0}
.board-slot.locked .board-pos{color:var(--text-3)}
.board-logo{width:26px;height:26px;border-radius:7px;object-fit:contain;background:var(--panel-3);border:1px solid var(--border-md);flex-shrink:0;padding:2px}
.board-team{flex:1;min-width:0;font-size:13px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;display:flex;align-items:center;gap:6px}
.board-team.q{color:var(--text-3);font-weight:400}
.board-tag{font-size:9px;font-weight:800;padding:2px 7px;border-radius:999px;flex-shrink:0;text-transform:uppercase;letter-spacing:.4px}
.board-tag.lottery{background:var(--red-soft);color:var(--red);border:1px solid var(--border-red)}
.board-tag.playoff{background:rgba(34,197,94,.10);color:var(--green);border:1px solid rgba(34,197,94,.28)}
.board-move{font-size:10px;font-weight:800;flex-shrink:0}
.board-move.up{color:var(--green)}.board-move.down{color:#ef4444}.board-move.same{color:var(--text-3)}

/* Chances */
.balls-table{width:100%;border-collapse:collapse;font-size:12px}
.balls-table th{padding:7px 8px;text-align:left;color:var(--text-3);font-weight:600;border-bottom:1px solid var(--border);font-size:10px;text-transform:uppercase}
.balls-table td{padding:7px 8px;border-bottom:1px solid var(--border)}
.balls-table tr:last-child td{border-bottom:none}
.balls-table td.num,.balls-table th.num{text-align:right;font-family:'Oswald',sans-serif;font-weight:700;font-variant-numeric:tabular-nums}
.balls-rodape{margin-top:10px;padding-top:9px;border-top:1px solid var(--border);font-size:11px;color:var(--text-3);line-height:1.5}
/* A faixa do ensaio usa listra de obra, não o vermelho da liga: quem chega
   por link precisa saber em dois segundos que não está na loteria oficial. */
.faixa-ensaio{display:flex;align-items:flex-start;gap:12px;margin-bottom:16px;padding:13px 16px;border-radius:12px;
  font-size:13px;line-height:1.55;color:var(--text);
  background:repeating-linear-gradient(135deg,rgba(245,158,11,.10) 0 12px,rgba(245,158,11,.05) 12px 24px);
  border:1px solid rgba(245,158,11,.42)}
.faixa-ensaio > i{color:var(--amber);font-size:18px;line-height:1.2;flex-shrink:0}
.btn-teste{display:inline-flex;align-items:center;gap:7px;flex-shrink:0;padding:9px 18px;border-radius:999px;
  border:1px solid rgba(245,158,11,.42);background:rgba(245,158,11,.08);color:var(--amber);
  font-size:12px;font-weight:700;text-decoration:none;transition:all var(--t) var(--ease)}
.btn-teste:hover{background:rgba(245,158,11,.16);border-color:var(--amber)}
.liga-vinda{display:flex;align-items:center;gap:9px;flex-wrap:wrap;margin-bottom:14px;padding:10px 14px;
  border-radius:10px;font-size:12.5px;background:var(--panel);border:1px solid var(--border-md);color:var(--text-2)}
.liga-vinda i{color:var(--red)}
.liga-vinda b{color:var(--text)}
.liga-vinda a{margin-left:auto;color:var(--text-3);font-size:12px}
/* Dezessete colunas de números: tudo encolhe, e a coluna do time gruda na
   esquerda pra não sumir quando a tabela rola de lado. */
.matriz-table{font-size:10px;min-width:840px}
.matriz-table th,.matriz-table td{padding:4px 5px;white-space:nowrap}
.matriz-table .celula{text-align:right;font-family:'Oswald',sans-serif;font-variant-numeric:tabular-nums;color:var(--text-2)}
.matriz-table .time-col{position:sticky;left:0;z-index:2;background:var(--panel);max-width:150px;overflow:hidden;text-overflow:ellipsis}
.matriz-table thead th{position:sticky;top:0;background:var(--panel);z-index:1}
.matriz-table thead th.time-col{z-index:3}
.matriz-table .total-col{border-left:1px solid var(--border);color:var(--text-1);font-weight:700}
.matriz-table tfoot td{border-top:1px solid var(--border);color:var(--text-1);font-weight:700;font-size:10px}
/* Quanto mais escura a célula, maior a chance — a matriz inteira é número,
   e sem isso o olho não acha onde cada grupo se concentra. */
.matriz-table .q1{background:rgba(16,185,129,.06)}
.matriz-table .q2{background:rgba(16,185,129,.14)}
.matriz-table .q3{background:rgba(16,185,129,.24);color:var(--text-1)}
.matriz-table .q4{background:rgba(16,185,129,.38);color:#fff}
.matriz-table .zero{color:var(--text-3);opacity:.35}
/* UMA COR POR GRUPO. São quatro grupos com quatro chances diferentes, e a
   tabela inteira em cinza obriga a ler o rótulo linha a linha pra saber
   quem está com quem. A cor identifica o grupo, não a qualidade dele. */
.cor-g1{--g:#a5b4fc}   /* 3 piores */
.cor-g2{--g:#6ee7b7}   /* fora do play-in */
.cor-g3{--g:#fcd34d}   /* eliminados no play-in */
.cor-g4{--g:#fca5a5}   /* derrotados no 7x8 */
/* Os mesmos quatro tons, escurecidos pro tema claro: os de cima são feitos
   pra brilhar sobre fundo escuro e somem sobre branco. */
:root[data-theme="light"] .cor-g1{--g:#4338ca}
:root[data-theme="light"] .cor-g2{--g:#047857}
:root[data-theme="light"] .cor-g3{--g:#a16207}
:root[data-theme="light"] .cor-g4{--g:#b91c1c}
.conf-chip.cor-g1,.conf-chip.cor-g2,.conf-chip.cor-g3,.conf-chip.cor-g4{
  color:var(--g);border-color:color-mix(in srgb, var(--g) 34%, transparent);
  background:color-mix(in srgb, var(--g) 11%, transparent)}
.bolinhas{display:inline-flex;gap:3px;vertical-align:middle}
.bolinha{width:9px;height:9px;border-radius:50%;background:var(--g,var(--red));
  box-shadow:0 0 5px color-mix(in srgb, var(--g,var(--red)) 55%, transparent)}
.grupo-sel.cor-g1,.grupo-sel.cor-g2,.grupo-sel.cor-g3,.grupo-sel.cor-g4{
  color:var(--g);border-color:color-mix(in srgb, var(--g) 30%, transparent)}
.grupo-sel{background:var(--panel-2);border:1px solid var(--border);border-radius:6px;color:var(--text-2);
  font-size:11px;font-family:inherit;padding:4px 6px;max-width:230px;cursor:pointer}
.grupo-sel:hover{border-color:var(--red)}
/* Marcado pelo admin (fato de jogo) x deduzido da ordem — a diferença
   precisa se ver, senão ninguém sabe o que já foi conferido. */
.grupo-sel.marcado{border-color:var(--border-red);color:var(--text-1);background:var(--red-soft)}
.conf-chip{font-size:9px;font-weight:800;padding:1px 6px;border-radius:999px;background:var(--panel-3);border:1px solid var(--border-md);color:var(--text-3);margin-left:6px}
.adjustments{display:flex;flex-direction:column;gap:8px}
/* Acordos resolvidos pela ordem: proteção e swap. Verde quando a pick passou
   pro credor, âmbar quando a proteção segurou, azul pro swap — o admin lê a
   lista de relance e o que importa é distinguir "mudou de dono" de "ficou". */
.ev{display:flex;gap:10px;align-items:flex-start;padding:9px 11px;border-radius:8px;
  border:1px solid var(--border);background:var(--panel-3);margin-bottom:7px}
.ev-tag{flex-shrink:0;font-size:9px;font-weight:800;letter-spacing:.04em;padding:2px 7px;
  border-radius:999px;background:rgba(34,197,94,.15);color:#22c55e;border:1px solid rgba(34,197,94,.35)}
.ev.barrado .ev-tag{background:rgba(245,158,11,.15);color:#f59e0b;border-color:rgba(245,158,11,.35)}
.ev.swap .ev-tag{background:rgba(96,165,250,.15);color:#60a5fa;border-color:rgba(96,165,250,.35)}
.ev-txt{font-size:12.5px;line-height:1.45;color:var(--text)}
.ev-extra{font-size:11.5px;color:var(--text-2);margin-top:3px}
.adjustment-item{display:flex;gap:8px;background:rgba(245,158,11,.08);border:1px solid rgba(245,158,11,.25);border-radius:10px;padding:10px 12px;font-size:12px;color:var(--text-2)}
.adjustment-item i{color:var(--amber);flex-shrink:0}
.rules-panel details{margin-bottom:8px}
.rules-panel summary{cursor:pointer;font-size:13px;font-weight:600;color:var(--text);padding:6px 0}
.rules-panel summary:hover{color:var(--red)}
.rules-panel .rules-body{font-size:12px;color:var(--text-2);padding:6px 0 10px 4px;line-height:1.6}
@media(max-width:640px){
  .content{padding:20px 16px 40px}
  .panel{padding:16px 14px}
  .form-row{flex-direction:column;align-items:stretch}
  .board{grid-template-columns:1fr}
  .reveal-team{font-size:22px}
}

/* -- Layout com menu lateral -- */
.app { display: flex; min-height: 100vh; }
.sidebar { position: fixed; top: 0; left: 0; width: 260px; height: 100vh; background: var(--panel); border-right: 1px solid var(--border); display: flex; flex-direction: column; z-index: 300; transition: transform var(--t) var(--ease); overflow-y: auto; scrollbar-width: none; }
.sidebar::-webkit-scrollbar { display: none; }
.sb-brand { padding: 22px 18px 18px; border-bottom: 1px solid var(--border); display: flex; align-items: center; gap: 12px; flex-shrink: 0; }
.sb-logo { width: 34px; height: 34px; border-radius: 9px; background: var(--red); display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 13px; color: #fff; flex-shrink: 0; }
.sb-brand-text { font-weight: 700; font-size: 15px; line-height: 1.1; }
.sb-brand-text span { display: block; font-size: 11px; font-weight: 400; color: var(--text-2); }
.sb-team { margin: 14px 14px 0; background: var(--panel-2); border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 14px; display: flex; align-items: center; gap: 10px; flex-shrink: 0; }
.sb-team img { width: 40px; height: 40px; border-radius: 9px; object-fit: cover; border: 1px solid var(--border-md); flex-shrink: 0; }
.sb-team-name { font-size: 13px; font-weight: 600; color: var(--text); line-height: 1.2; }
.sb-team-league { font-size: 11px; color: var(--red); font-weight: 600; }
.sb-nav { flex: 1; padding: 12px 10px 8px; }
.sb-section { font-size: 10px; font-weight: 600; letter-spacing: 1.2px; text-transform: uppercase; color: var(--text-3); padding: 12px 10px 6px; }
.sb-nav a { font-family:'Inter',sans-serif; display: flex; align-items: center; gap: 10px; padding: 10px 10px; border-radius: var(--radius-sm); color: var(--text-2); font-size: 13px; font-weight: 500; text-decoration: none; margin-bottom: 2px; transition: all var(--t) var(--ease); }
.sb-nav a i { font-size: 15px; width: 18px; text-align: center; flex-shrink: 0; }
.sb-nav a:hover { background: var(--panel-2); color: var(--text); }
.sb-nav a.active { background: var(--red-soft); color: var(--red); font-weight: 600; }
.sb-nav a.active i { color: var(--red); }
.sb-theme-toggle{margin:10px 14px;display:flex;align-items:center;gap:8px;background:var(--panel-2);border:1px solid var(--border);border-radius:var(--radius-sm);padding:9px 12px;color:var(--text-2);font-family:var(--font);font-size:12px;font-weight:600;cursor:pointer;flex-shrink:0}
.sb-theme-toggle:hover{color:var(--text)}
.sb-footer { padding: 12px 14px; border-top: 1px solid var(--border); display: flex; align-items: center; gap: 10px; flex-shrink: 0; }
.sb-avatar { width: 30px; height: 30px; border-radius: 50%; object-fit: cover; border: 1px solid var(--border-md); flex-shrink: 0; }
.sb-username { font-size: 12px; font-weight: 500; color: var(--text); flex: 1; min-width: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.sb-logout { width: 26px; height: 26px; border-radius: 7px; background: transparent; border: 1px solid var(--border); color: var(--text-2); display: flex; align-items: center; justify-content: center; font-size: 12px; cursor: pointer; transition: all var(--t) var(--ease); text-decoration: none; flex-shrink: 0; }
.sb-logout:hover { background: var(--red-soft); border-color: var(--red); color: var(--red); }
.topbar { display: none; position: fixed; top: 0; left: 0; right: 0; height: 54px; background: var(--panel); border-bottom: 1px solid var(--border); align-items: center; padding: 0 16px; gap: 12px; z-index: 260; }
.topbar-title { font-weight: 700; font-size: 15px; flex: 1; }
.topbar-title em { color: var(--red); font-style: normal; }
.menu-btn { width: 34px; height: 34px; border-radius: 9px; background: var(--panel-2); border: 1px solid var(--border); color: var(--text); display: flex; align-items: center; justify-content: center; cursor: pointer; font-size: 17px; }
.sb-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.65); backdrop-filter: blur(4px); z-index: 250; }
.sb-overlay.show { display: block; }
.main { margin-left: var(--sidebar-w); min-height: 100vh; width: calc(100% - var(--sidebar-w)); display: flex; flex-direction: column; }
/* Sem menu lateral (visitante do ensaio), a coluna reservada a ele viraria
   uma faixa vazia à esquerda de tudo. */
body.sem-menu .main { margin-left: 0; width: 100%; }
body.sem-menu .menu-btn { display: none; }
@media (max-width: 992px) {
  :root { --sidebar-w: 0px; }
  .sidebar { transform: translateX(-260px); }
  .sidebar.open { transform: translateX(0); }
  .main { margin-left: 0; width: 100%; padding-top: 54px; }
  .topbar { display: flex; }
  .content { padding-left: 16px; padding-right: 16px; }
  .page-hero-title { font-size: 18px; }
}
/* selo de swap: pick que veio de outro time */
.via-badge{display:inline-flex;align-items:center;gap:3px;font-size:9px;font-weight:800;letter-spacing:.5px;padding:2px 7px;border-radius:999px;background:rgba(168,85,247,.14);border:1px solid rgba(168,85,247,.38);color:#a855f7;text-transform:uppercase;white-space:nowrap;flex-shrink:0}
.reveal-via{margin-top:6px}
.reveal-via .via-badge{font-size:12px;padding:4px 12px}
.podium-via{margin-top:6px}

/* ── Pódio do top-3 (aparece quando o sorteio termina) ── */
.podium{display:none;grid-template-columns:1fr 1.18fr 1fr;gap:14px;align-items:end;margin-bottom:16px}
body.bc-complete .podium{display:grid}
.podium-item{background:linear-gradient(180deg,var(--panel-2),var(--panel));border:1px solid var(--border-md);border-radius:16px;padding:18px 14px 20px;text-align:center;position:relative;overflow:hidden;animation:podiumIn .6s cubic-bezier(.2,1.2,.4,1) backwards}
.podium-item:nth-child(1){animation-delay:.05s}
.podium-item:nth-child(2){animation-delay:.2s}
.podium-item:nth-child(3){animation-delay:.35s}
@keyframes podiumIn{0%{opacity:0;transform:translateY(26px) scale(.94)}100%{opacity:1;transform:translateY(0) scale(1)}}
.podium-logo{width:92px;height:92px;border-radius:18px;object-fit:contain;background:var(--panel-3);border:1px solid var(--border-md);padding:6px;margin:0 auto 10px;display:block}
.podium-pos{font-family:'Oswald',sans-serif;font-size:34px;font-weight:800;line-height:1}
.podium-name{font-family:'Oswald',sans-serif;font-size:19px;font-weight:700;margin-top:6px;line-height:1.15}
.podium-conf{font-size:11px;color:var(--text-3);text-transform:uppercase;letter-spacing:.5px;margin-top:3px}
.podium-move{margin-top:8px;display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:800;padding:3px 10px;border-radius:999px}
.podium-move.up{background:rgba(34,197,94,.14);color:var(--green);border:1px solid rgba(34,197,94,.35)}
.podium-move.down{background:rgba(239,68,68,.14);color:#ef4444;border:1px solid rgba(239,68,68,.35)}
.podium-move.same{background:var(--panel-3);color:var(--text-2);border:1px solid var(--border-md)}
/* medalhas */
.podium-item.gold{border-color:rgba(245,158,11,.55);box-shadow:0 0 0 1px rgba(245,158,11,.22),0 0 46px -12px rgba(245,158,11,.55);padding-top:28px;padding-bottom:30px}
.podium-item.gold .podium-pos{color:#f59e0b;font-size:42px}
.podium-item.gold .podium-logo{width:118px;height:118px}
.podium-item.gold .podium-name{font-size:23px}
.podium-item.silver{border-color:rgba(203,213,225,.42)}
.podium-item.silver .podium-pos{color:#cbd5e1}
.podium-item.bronze{border-color:rgba(217,119,6,.45)}
.podium-item.bronze .podium-pos{color:#d97706}
@media(max-width:700px){.podium{grid-template-columns:1fr;align-items:stretch}}

/* Destaque do top-4 (vale sempre; brilha mais na transmissão) */
.board-slot.top4{position:relative;border-color:rgba(245,158,11,.45);background:linear-gradient(180deg,rgba(245,158,11,.10),transparent)}
.board-slot.top4 .board-pos{color:var(--amber)}
.board-slot.top4::after{content:'★';position:absolute;top:-7px;right:-6px;font-size:12px;color:var(--amber);text-shadow:0 0 6px rgba(245,158,11,.6)}

<?php include __DIR__ . '/includes/accent-color.php'; ?>
</style>
</head>
<body<?= $ehVisitante ? ' class="sem-menu"' : '' ?>>
<div class="app">

<?php /* O menu lateral é o painel de quem tem conta. Um visitante que chegou
         pelo link do ensaio não tem o que navegar ali — e cada item levaria
         a uma tela que o manda fazer login. */ ?>
<?php if (!$ehVisitante) include __DIR__ . '/includes/sidebar.php'; ?>
<div class="sb-overlay" id="sbOverlay"></div>

<header class="topbar">
  <button class="menu-btn" id="menuBtn"><i class="bi bi-list"></i></button>
  <div class="topbar-title">Loteria do <em>Draft</em></div>
  <a href="admin.php" class="icon-btn"><i class="bi bi-arrow-left"></i></a>
</header>

<main class="main">
 <div class="content">

  <div class="page-hero">
    <div>
      <div class="page-hero-eyebrow">Liga · Loteria</div>
      <h1 class="page-hero-title"><i class="bi bi-shuffle" style="color:var(--red);margin-right:8px"></i>Loteria do Draft</h1>
      <p class="page-hero-sub">Modelo 3-2-1 anti-tanking: os 16 times fora do playoff disputam as primeiras picks em 4 grupos com chances diferentes.</p>
    </div>
    <?php /* No canto, e não embaixo do texto: a loteria acontece uma vez por
             ano e decide o draft inteiro, mas quem abre esta tela vem ver a
             cerimônia — o ensaio é uma saída, não o assunto.

             Leva pra MESMA liga que está aberta aqui: ensaiar a loteria de
             outra liga não ensina nada sobre a sua. */ ?>
    <a href="/lottery-teste.php?liga=<?= urlencode($ligaAtual) ?>" class="btn-teste">
      <i class="bi bi-dice-5"></i> Teste a loteria
    </a>
  </div>

  <?php if ($modoTeste): ?>
  <div class="faixa-ensaio">
    <i class="bi bi-cone-striped"></i>
    <div>
      <b>Modo de ensaio.</b> Esta é a loteria de verdade, sorteando de verdade — e nada do que acontecer aqui
      é gravado. Sorteie quantas vezes quiser, mude a ordem e os grupos à vontade: some tudo ao fechar a página,
      e a loteria oficial continua exatamente como está.
    </div>
  </div>
  <?php endif; ?>

  <?php /* Quem chegou pelo painel de admin está numa liga que não é a dele.
           Sem isso, nada na tela diria de qual liga é a loteria que ele está
           prestes a sortear. */ ?>
  <?php if ($podeConduzirPedida && $ligaAtual !== $minhaLiga): ?>
  <div class="liga-vinda">
    <i class="bi bi-shield-lock"></i>
    <span>Você está na loteria da <b><?= htmlspecialchars($ligaAtual) ?></b>, que administra.</span>
    <a href="/admin.php">Voltar ao painel</a>
  </div>
  <?php endif; ?>

  <?php /* A ordem já confirmada só interessa quando não há loteria em curso:
           havendo sessão em configuração, o que a tela mostra abaixo é a
           desta temporada, e repetir a do ano passado no topo confundiria. */ ?>
  <?php if (!$setupSessions): ?>
  <div class="section-title"><i class="bi bi-list-ol"></i> Ordem do draft<?= $ligaAtual ? ' · ' . htmlspecialchars($ligaAtual) : '' ?><?= $confirmedSessionInfo ? ' — Temporada ' . (int)$confirmedSessionInfo['season_number'] : '' ?></div>
  <div class="panel">
    <?php if (!$confirmedOrder): ?>
    <div class="empty"><i class="bi bi-hourglass-split" style="font-size:22px;display:block;margin-bottom:8px"></i>
      <?= $ligaAtual
        ? 'A ' . htmlspecialchars($ligaAtual) . ' ainda não sorteou a ordem do draft desta temporada.'
        : 'A loteria é de cada liga, e você ainda não tem franquia em nenhuma. Assim que estiver numa, ela aparece aqui.' ?>
      <?php /* O caminho pra destravar só vale pra quem pode percorrê-lo —
               e no ensaio ninguém está aqui pra destravar nada. */ ?>
      <?php if ($podeConduzirEstaLiga && !$modoTeste): ?>
      <div style="font-size:11px;color:var(--text-3);margin-top:8px">
        A loteria aparece aqui quando existir uma sessão de draft em configuração — ela é criada na tela de Draft.
      </div>
      <?php elseif ($modoTeste): ?>
      <div style="font-size:11px;color:var(--text-3);margin-top:8px">
        Escolha outra liga nas abas acima para ensaiar o sorteio dela.
      </div>
      <?php endif; ?>
    </div>
    <?php else: ?>
    <div class="board">
      <?php foreach ($confirmedOrder as $o): ?>
      <div class="board-slot">
        <span class="board-pos"><?= (int)$o['pick_position'] ?></span>
        <img class="board-logo" src="<?= htmlspecialchars($o['photo_url'] ?: '/img/default-team.png') ?>" alt="" onerror="this.src='/img/default-team.png'">
        <span class="board-team"><?= htmlspecialchars($o['team_name']) ?></span>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <?php if ($setupSessions): ?>

  <?php
    /* COM UM DRAFT SÓ, NÃO HÁ O QUE ESCOLHER.
       A loteria é sempre do draft que está em configuração. Quando existe um
       só — que é o caso normal, e sempre o caso de quem administra uma liga —
       o passo "escolha a sessão" era uma pergunta de resposta única: o admin
       tinha que confirmar no seletor aquilo que a tela já sabia.

       O seletor continua existindo (escondido) porque o resto da página lê o
       id dele. E ele volta a aparecer quando há mais de um draft aberto, que
       é quando a pergunta passa a ser de verdade — o admin de várias ligas
       precisa dizer de qual delas é a loteria. */
    $umDraftSo = count($setupSessions) === 1;
    $draftUnico = $umDraftSo ? $setupSessions[0] : null;
  ?>

  <?php if ($umDraftSo): ?>
  <div class="panel" style="display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap">
    <div>
      <div style="font-size:11px;font-weight:700;letter-spacing:.5px;text-transform:uppercase;color:var(--text-3)">
        Draft em configuração
      </div>
      <div style="font-size:16px;font-weight:800;margin-top:2px">
        <?php /* Sem o ano entre parênteses: o ano do jogo é de outra contagem
                 que a do calendário e, ao lado do número da temporada, confunde
                 mais do que informa. O que identifica o draft aqui é a liga e a
                 temporada. */ ?>
        <?= htmlspecialchars($draftUnico['league']) ?> · Temporada <?= (int)$draftUnico['season_number'] ?>
      </div>
    </div>
    <?php if ($podeConduzirEstaLiga): ?>
    <button class="btn-red" id="btnPrepare"><i class="bi bi-dice-5-fill"></i> Sortear a loteria</button>
    <?php endif; ?>
  </div>
  <select id="sessionSelect" style="display:none">
    <option value="<?= (int)$draftUnico['id'] ?>" selected></option>
  </select>

  <?php else: ?>
  <div class="section-title"><i class="bi bi-calendar2-check"></i> 1. Escolha a sessão de draft</div>
  <div class="panel">
    <div class="form-row">
      <div class="form-field">
        <label>Sessão de draft</label>
        <select id="sessionSelect">
          <?php foreach ($setupSessions as $s): ?>
          <option value="<?= (int)$s['id'] ?>">[<?= htmlspecialchars($s['league']) ?>] Temporada <?= (int)$s['season_number'] ?><?= $s['year'] ? ' (' . htmlspecialchars($s['year']) . ')' : '' ?> — sessão #<?= (int)$s['id'] ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php if ($podeConduzirEstaLiga): ?>
      <button class="btn-red" id="btnPrepare"><i class="bi bi-dice-5-fill"></i> Sortear a loteria</button>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>
  <?php endif; ?>

  <?php /* Este aviso mora FORA do bloco de resultado. O de dentro nunca era
           lido quando mais importava: se a prévia não vem, o bloco inteiro
           fica oculto e leva o aviso junto — a pessoa via a página muda e
           não tinha como saber por quê. */ ?>
  <div id="semLoteria" class="panel" style="display:none;border-color:rgba(245,158,11,.42)">
    <i class="bi bi-info-circle" style="color:var(--amber)"></i>
    <span id="semLoteriaTexto"></span>
  </div>

  <div id="resultSection" style="display:none">

    <?php /* Sai da tela no instante em que o sorteio de verdade acontece —
             ver setupBoardAndOdds. Enquanto está aqui, o que se vê embaixo é
             a ordem da campanha, não um resultado. */ ?>
    <div id="previaAviso" class="panel" style="display:none;border-color:var(--border-red)">
      <i class="bi bi-eye" style="color:var(--red)"></i>
      <b>Prévia</b> — estes são os times que entram na loteria, com o grupo e as chances de cada um.
      A ordem abaixo é a da <b>campanha</b>; nada foi sorteado ainda.
      <?php /* Quem não sorteia não deve ser mandado clicar num botão que a
               tela dele não tem. */ ?>
      <?= $podeConduzirEstaLiga
        ? 'Clique em <b>Sortear a loteria</b> quando estiver tudo certo.'
        : 'A cerimônia do sorteio é feita pela administração da liga.' ?>
    </div>

    <?php /* A BARRA DE SALVAR fica aqui em cima, grudada no topo, porque a
             edição acontece em dois lugares distantes um do outro: a tag do
             grupo é trocada nesta tabela e a ordem no quadro, lá embaixo.
             Enquanto ela morava só no quadro, dava pra editar as chances,
             sair da página e perder tudo sem nunca ver o aviso. */ ?>
    <?php if ($podeConduzirEstaLiga): ?>
    <div id="ordemBar" class="ordem-bar" style="display:none">
      <span id="ordemBarTexto"></span>
      <span class="ordem-bar-acoes">
        <button class="btn-ghost2" id="btnOrdemDesfazer" type="button"><i class="bi bi-arrow-counterclockwise"></i> Desfazer</button>
        <?php if (!$modoTeste): ?>
        <button class="btn-red" id="btnOrdemSalvar" type="button"><i class="bi bi-check-lg"></i> Salvar</button>
        <?php endif; ?>
      </span>
    </div>
    <?php endif; ?>

    <div class="section-title"><i class="bi bi-percent"></i> Chances da loteria (3-2-1)<i class="bi bi-question-circle info-hint" title="Os 16 times fora do playoff entram em 4 grupos. Cada grupo tem uma chance própria de conseguir uma pick no Top 3 e no Top 5. Mostrado ANTES de revelar, pra todos saberem as probabilidades."></i></div>
    <div class="panel">
      <div style="overflow-x:auto">
        <table class="balls-table" id="ballsTable">
          <thead><tr><th>Time</th><th>Grupo</th><th>Bolinhas</th><th class="num">Pos</th><th class="num">Nº 1</th><th class="num">Top 3</th><th class="num">Top 5</th></tr></thead>
          <tbody id="ballsBody"></tbody>
        </table>
      </div>
      <div id="ballsRodape" class="balls-rodape"></div>
    </div>

    <?php /* A MATRIZ. Top 3 e Top 5 respondem "com que chance eu pego uma
             escolha boa", mas somam 300% e 500% entre os times — três e cinco
             escolhas sendo distribuídas. Quem procura um total de 100% não
             acha, e conclui que a conta está errada. Aqui cada linha soma
             100% (o time termina em alguma pick) e cada coluna também (a pick
             vai pra alguém). */ ?>
    <div class="section-title" id="matrizTitulo" style="display:none">
      <i class="bi bi-grid-3x3"></i> Chance de cair em cada escolha<i class="bi bi-question-circle info-hint" title="Cada linha é um time e soma 100%: ele termina em alguma das escolhas. Cada coluna é uma escolha e também soma 100%: ela vai pra alguém. As diferenças de 0,1 são arredondamento."></i>
    </div>
    <div class="panel" id="matrizPainel" style="display:none">
      <div style="overflow-x:auto">
        <table class="balls-table matriz-table" id="matrizTable">
          <thead id="matrizHead"></thead>
          <tbody id="matrizBody"></tbody>
          <tfoot id="matrizFoot"></tfoot>
        </table>
      </div>
      <div class="balls-rodape" id="matrizRodape"></div>
    </div>

    <?php /* O PALCO É DE TODOS; OS BOTÕES, NÃO.
             A cerimônia acontece uma vez por ano e a liga inteira quer ver.
             Enquanto o palco existia só pra quem conduz, o resto olhava uma
             tela parada enquanto as picks saíam. Agora ele aparece pra todo
             mundo e acompanha o que o admin revela; só os botões de revelar
             e confirmar seguem sendo de quem conduz. */ ?>
    <div class="section-title"><i class="bi bi-stars"></i> 2. Revelação<i class="bi bi-question-circle info-hint" title="Clique em 'Revelar próxima' para revelar uma pick de cada vez, da última até a #1. O badge mostra se o time subiu ou caiu em relação à posição que teria só pela campanha."></i></div>
    <div class="reveal-stage" id="revealStage">
      <div class="reveal-fx" id="revealFx" aria-hidden="true"></div>
      <div class="reveal-pick" id="revealPickLabel">Pronto para começar</div>
      <div class="reveal-card">
        <div class="ball-machine" id="ballMachine" aria-hidden="true">
          <div class="ball-globe" id="ballGlobe"></div>
        </div>
        <img class="reveal-logo" id="revealLogo" src="/img/default-team.png" alt="" style="visibility:hidden" onerror="this.src='/img/default-team.png'">
        <div class="reveal-number" id="revealNumber">#?</div>
        <div class="reveal-team q" id="revealTeam">—</div>
        <div class="reveal-conf" id="revealConf"></div>
        <div class="reveal-via" id="revealVia"></div>
        <div class="reveal-move" id="revealMove"></div>
        <div class="reveal-passed" id="revealPassed"></div>
      </div>
      <div class="reveal-actions">
        <?php if ($podeConduzirEstaLiga): ?>
        <button class="btn-red" id="btnReveal"><i class="bi bi-caret-right-fill"></i> Revelar próxima escolha</button>
        <?php endif; ?>
      </div>
      <div class="reveal-hint" id="revealHint">A revelação começa pela última pick e sobe até a #1.</div>
    </div>

    <div class="section-title"><i class="bi bi-collection-fill"></i> Ainda na urna <span class="bowl-count" id="bowlCount"></span><i class="bi bi-question-circle info-hint" title="Times que ainda não foram revelados — qualquer um deles ainda pode pegar as melhores picks. A urna esvazia a cada revelação."></i></div>
    <div class="panel">
      <div class="bowl" id="bowl"></div>
    </div>

    <div class="section-title bc-podium-title" id="podiumTitle" style="display:none"><i class="bi bi-trophy-fill"></i> Pódio da loteria</div>
    <div class="podium" id="podium"></div>

    <div class="section-title"><i class="bi bi-list-ol"></i> Ordem do draft<i class="bi bi-question-circle info-hint" id="boardHint" title="Antes do sorteio dá pra corrigir a ordem com as setas. Em cima estão os times de loteria, na ordem da campanha — é dela que saem os grupos de bolinhas. Embaixo, os times de playoff na ordem em que escolhem: quem foi menos longe pica antes, e o campeão por último."></i></div>
    <div class="panel">
      <div class="board" id="board"></div>
    </div>

    <div id="adjustmentsSection" style="display:none">
      <div class="section-title"><i class="bi bi-shield-exclamation"></i> Ajustes anti-tanking aplicados</div>
      <div class="panel"><div class="adjustments" id="adjustmentsList"></div></div>
    </div>

    <?php if ($podeConduzirEstaLiga && !$modoTeste): ?>
    <div class="panel" id="confirmPanel" style="display:none">
      <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
        <button class="btn-red" id="btnConfirm"><i class="bi bi-check-lg"></i> Confirmar e aplicar ao draft</button>
        <button class="btn-ghost2" id="btnRedo"><i class="bi bi-arrow-repeat"></i> Sortear de novo</button>
        <span style="font-size:11px;color:var(--text-3)">Aplica esta ordem nas duas rodadas do draft (a 2ª reaproveita a mesma ordem).</span>
      </div>
    </div>
    <?php endif; ?>

    <!-- O que a ordem decidiu além das posições: proteção que passou ou não,
         e onde cada swap parou. Só aparece quando houve algum caso. -->
    <div class="panel" id="painelEventos" style="display:none">
      <div style="font-weight:800;font-size:13px;margin-bottom:10px">
        <i class="bi bi-shield-check"></i> Acordos resolvidos por esta ordem
      </div>
      <div id="eventosCorpo"></div>
      <div style="font-size:11px;color:var(--text-3);margin-top:10px">
        Proteção e swap só se resolvem quando a ordem sai — as picks já foram movidas.
      </div>
    </div>
  </div>

  <div class="section-title"><i class="bi bi-journal-text"></i> Como funciona</div>
  <div class="panel rules-panel">
    <details>
      <summary><i class="bi bi-diagram-2"></i> Quem entra na loteria</summary>
      <div class="rules-body">Playoff = os 8 melhores de cada conferência (16 no total). Todos os outros — os 16 times
      fora do playoff (posições 9 a 16 de cada conferência) — entram na loteria e disputam as 16 primeiras picks. Os 16
      do playoff pegam as últimas picks, em ordem inversa do quão longe foram (o campeão escolhe por último).</div>
    </details>
    <details>
      <summary><i class="bi bi-circle-half"></i> Os 4 grupos e as chances</summary>
      <div class="rules-body">Os 16 times de loteria são divididos em 4 grupos (modelo 3-2-1), cada um com bolinhas e
      chances próprias:<br>
      • <strong>3 piores recordes da liga</strong> — 2 bolinhas · 16% Top 3 / 28% Top 5<br>
      • <strong>4º ao 10º pior recorde (fora do play-in)</strong> — 3 bolinhas · 24% / 39% (a <em>maior</em> chance)<br>
      • <strong>Eliminados no play-in (9º e 10º de cada conferência)</strong> — 2 bolinhas · 16% / 28%<br>
      • <strong>Derrotados no 7x8 (os 2 menos ruins)</strong> — 1 bolinha · 8% / 15% (a <em>menor</em> chance)<br>
      Assim o pior time deixa de ser o favorito à Pick 1 — quem tentou competir até o fim é mais premiado.</div>
    </details>
    <details>
      <summary><i class="bi bi-shield-exclamation"></i> Piso de proteção</summary>
      <div class="rules-body">Os 3 piores times não podem cair além da Pick 12; os demais times da loteria podem cair
      até a Pick 16. Se o sorteio esbarrar nessa trava, o ajuste é aplicado e aparece listado.</div>
    </details>
    <details>
      <summary><i class="bi bi-shuffle"></i> Como o sorteio e a revelação acontecem</summary>
      <div class="rules-body">O modelo 3-2-1 anti-tanking vale para as quatro ligas (ELITE, NEXT, RISE e ROOKIE): o pior time
      deixa de ser o favorito à Pick 1. A ordem é sorteada de uma vez no servidor e você revela pick por pick no clique.
      Os times do playoff (8 de cada conferência) ficam travados no fim da ordem.</div>
    </details>
  </div>
 </div>
</main>
</div>

<script>
(function(){
  const sidebar = document.getElementById('sidebar');
  const overlay = document.getElementById('sbOverlay');
  const menuBtn = document.getElementById('menuBtn');
  if (menuBtn) menuBtn.addEventListener('click', () => { sidebar?.classList.add('open'); overlay?.classList.add('show'); });
  if (overlay) overlay.addEventListener('click', () => { sidebar?.classList.remove('open'); overlay.classList.remove('show'); });

  const themeToggle = document.getElementById('themeToggle');
  const themeKey = 'fba-theme';
  const applyTheme = (theme) => {
    if (theme === 'light') {
      document.documentElement.setAttribute('data-theme', 'light');
      if (themeToggle) themeToggle.innerHTML = '<i class="bi bi-sun"></i><span>Modo claro</span>';
      return;
    }
    document.documentElement.removeAttribute('data-theme');
    if (themeToggle) themeToggle.innerHTML = '<i class="bi bi-moon"></i><span>Modo escuro</span>';
  };
  applyTheme(localStorage.getItem(themeKey) || 'dark');
  if (themeToggle) {
    themeToggle.addEventListener('click', () => {
      const current = document.documentElement.getAttribute('data-theme') === 'light' ? 'light' : 'dark';
      const next = current === 'light' ? 'dark' : 'light';
      localStorage.setItem(themeKey, next);
      applyTheme(next);
    });
  }
})();

function esc(s){ if(!s) return ''; return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

/* selo "via XXX" quando a pick veio de outro time (swap/troca) */
function viaTag(o){
  return (o && o.is_swap && o.origin_abbr)
    ? `<span class="via-badge" title="Pick originalmente do ${esc(o.origin_name || '')}">via ${esc(o.origin_abbr)}</span>`
    : '';
}

/* pódio do top-3 (montado quando o sorteio termina) */
function renderPodium(){
  if (!result) return;
  const byPos = p => result.order.find(o => o.position === p);
  const p1 = byPos(1), p2 = byPos(2), p3 = byPos(3);
  if (!p1) return;
  const item = (o, cls) => {
    if (!o) return '';
    const d = o.delta || 0;
    const mv = d > 0 ? `<span class="podium-move up"><i class="bi bi-arrow-up-short"></i> Subiu ${d}</span>`
             : d < 0 ? `<span class="podium-move down"><i class="bi bi-arrow-down-short"></i> Caiu ${Math.abs(d)}</span>`
             : `<span class="podium-move same"><i class="bi bi-dash"></i> Manteve</span>`;
    const src = o.photo_url || LOGO_FALLBACK;
    return `<div class="podium-item ${cls}">
      <img class="podium-logo" src="${esc(src)}" alt="" loading="eager" onerror="this.src='${LOGO_FALLBACK}'">
      <div class="podium-pos">#${o.position}</div>
      <div class="podium-name">${esc(o.team_name)}</div>
      <div class="podium-conf">${esc(o.conference || '')}</div>
      ${o.is_swap ? `<div class="podium-via">${viaTag(o)}</div>` : ''}
      ${mv}
    </div>`;
  };
  // pódio clássico: 2º à esquerda, 1º ao centro (maior), 3º à direita
  $('podium').innerHTML = item(p2, 'silver') + item(p1, 'gold') + item(p3, 'bronze');
  $('podiumTitle').style.display = 'flex';
}

/* partículas que sobem no palco de revelação */
function spawnParticles(symbol, color, count){
  const fx = document.getElementById('revealFx');
  if (!fx) return;
  for (let i = 0; i < count; i++){
    const p = document.createElement('span');
    p.className = 'fx-particle';
    p.textContent = symbol;
    p.style.color = color;
    p.style.left = (6 + Math.random() * 88) + '%';
    p.style.bottom = (26 + Math.random() * 18) + '%';
    p.style.fontSize = (14 + Math.random() * 16) + 'px';
    p.style.animationDelay = (Math.random() * 0.3) + 's';
    fx.appendChild(p);
    setTimeout(() => p.remove(), 1600);
  }
}

let result = null;       // resposta do run_lottery
let revealQueue = [];    // posições de loteria a revelar (da última pra #1)
let revealed = new Set();
let busy = false;

const $ = (id) => document.getElementById(id);

async function prepare(){
  const sel = $('sessionSelect');
  const sessionId = sel ? sel.value : '';
  const btn = $('btnPrepare');
  const label = '<i class="bi bi-dice-5-fill"></i> Sortear a loteria';

  if (!sessionId) { alert('Escolha uma sessão de draft.'); return; }

  btn.disabled = true;
  btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Sorteando...';
  try {
    const payload = { action: 'run_lottery', draft_session_id: parseInt(sessionId, 10) };
    if (MODO_TESTE) {
      payload.simulacao = true;
      // No ensaio nada foi gravado, então o que está na tela é o que vale:
      // a ordem e os grupos vão junto pra que o sorteio use exatamente o
      // cenário que a pessoa montou.
      payload.ordem = ordemLoteria;
      payload.ordem_playoff = ordemPlayoff;
      payload.grupos = Object.fromEntries(ordemLoteria.map(t => [t, gruposEditados[t] || 0]));
    }
    const res = await fetch('/api/draft.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    });
    const data = await res.json();
    if (!data.success) { alert(data.error || 'Erro ao sortear a loteria.'); return; }
    result = data;
    setupBoardAndOdds(data);
    // Coloca a cerimônia no ar antes de revelar a primeira: quem estiver
    // com a página aberta passa a ver o quadro desta ordem.
    transmitirSorteio(data);
    // No ensaio o botão de aplicar ao draft não existe.
    if ($('btnConfirm')) $('btnConfirm').style.display = '';
    $('resultSection').style.display = 'block';
  } catch (e) {
    alert('Erro ao sortear a loteria.');
  } finally {
    btn.disabled = false;
    // O rótulo depende de já ter sorteado ou não — e o setupBoardAndOdds já
    // decidiu isso. Restaurar o texto fixo aqui desfazia a troca dele.
    const jaSorteou = result && result.preview === false;
    btn.innerHTML = jaSorteou
      ? '<i class="bi bi-arrow-repeat"></i> Sortear de novo'
      : label;
  }
}

const LOGO_FALLBACK = '/img/default-team.png';
let photoById = {};
function logo(url, cls){
  const src = url || LOGO_FALLBACK;
  return `<img class="${cls}" src="${esc(src)}" alt="" loading="lazy" onerror="this.src='${LOGO_FALLBACK}'">`;
}

/* ─── ORDEM DA CAMPANHA, EDITÁVEL NO QUADRO ───────────────────────────────
   Os grupos de bolinhas saem de quem terminou atrás de quem. Quando essa
   lista chega errada do registro da temporada, o time aparece no grupo
   errado — e antes disso só dava pra corrigir voltando ao card Pontuação,
   longe da tela onde o erro é visto.

   Cada movimento vai ao servidor como ordem PROVISÓRIA: ele devolve os
   grupos e as chances recalculados, sem gravar nada. Quem grava é o botão
   Salvar. Enquanto houver mudança pendente o sorteio fica bloqueado, porque
   ele sortearia pela ordem gravada, não pela que está na tela. */
const PODE_EDITAR_ORDEM = <?= $podeConduzirEstaLiga ? 'true' : 'false' ?>;
/* No ensaio o sorteio vai marcado, e o servidor devolve a ordem sem gravar
   nada — nem aqui nem na loteria oficial, que é a mesma chamada. */
const MODO_TESTE = <?= $modoTeste ? 'true' : 'false' ?>;
const LIGA_ATUAL = <?= json_encode($ligaAtual) ?>;
let ordemLoteria = [];    // origin_team_id na ordem do quadro (pior primeiro)
let ordemSalvaRef = [];   // como estava na última vez que gravou
/* A cauda de playoff também se edita, por um motivo diferente: quem foi
   eliminado na mesma fase empata em playoff_results, e esse empate é
   exatamente a diferença entre a pick 31 e a 32. */
let ordemPlayoff = [];
let ordemPlayoffSalvaRef = [];
let ordemPendente = false;

/* GRUPO DECLARADO. Os rótulos ficam aqui e as bolinhas vêm do servidor em
   group_meta, pra tela não ter a própria cópia dos pesos. */
let GRUPOS_OPCOES = [];
let gruposEditados = {};   // {team_id: 1..4}, ou 0 pra voltar ao automático

function mudarGrupo(teamId, valor){
  gruposEditados[teamId] = parseInt(valor, 10) || 0;
  ordemPendente = true;
  carregarPrevia(ordemLoteria, ordemPlayoff, gruposEditados);
}

function atualizarBarraOrdem(){
  const bar = $('ordemBar');
  if (!bar) return;
  bar.style.display = ordemPendente ? '' : 'none';
  const txt = $('ordemBarTexto');
  if (txt) txt.innerHTML = MODO_TESTE
    ? '<i class="bi bi-cone-striped"></i> <b>Cenário alterado.</b> O sorteio abaixo vai usar esta ordem. '
      + 'Nada disso é gravado — o Desfazer devolve o cenário oficial.'
    : '<i class="bi bi-exclamation-triangle-fill"></i> '
      + '<b>Alterações não salvas.</b> As chances abaixo já refletem a mudança, mas ela ainda não foi gravada — '
      + 'sair da página agora perde a edição.';
  const btnSortear = $('btnPrepare');
  if (btnSortear) {
    // No ensaio não há o que salvar, então nada trava o sorteio.
    btnSortear.disabled = ordemPendente && !MODO_TESTE;
    btnSortear.title = (ordemPendente && !MODO_TESTE) ? 'Salve a ordem antes de sortear' : '';
  }
}

function moverOrdem(bloco, i, dir){
  const lista = bloco === 'playoff' ? ordemPlayoff : ordemLoteria;
  const j = i + dir;
  if (i < 0 || j < 0 || j >= lista.length) return;
  const nova = lista.slice();
  [nova[i], nova[j]] = [nova[j], nova[i]];
  ordemPendente = true;
  // O outro bloco vai junto: sem ele, o servidor releria do banco e desfaria
  // uma edição anterior que ainda não foi salva.
  carregarPrevia(
    bloco === 'playoff' ? ordemLoteria : nova,
    bloco === 'playoff' ? nova : ordemPlayoff,
    gruposEditados
  );
}

function desfazerOrdem(){
  ordemPendente = false;
  carregarPrevia();   // sem ordem provisória: volta ao que está gravado
}

async function salvarOrdem(){
  if (!result || !result.standings_season_id) return;
  const btn = $('btnOrdemSalvar');
  const rotulo = btn.innerHTML;
  btn.disabled = true;
  btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Salvando...';
  try {
    const res = await fetch('/api/draft.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        action: 'save_lottery_order',
        season_id: result.standings_season_id,
        ordem: ordemLoteria,
        ordem_playoff: ordemPlayoff,
        // Vai completo, com 0 pra quem voltou ao automático: uma lista só dos
        // marcados não teria como dizer "este aqui eu desmarquei".
        grupos: Object.fromEntries(ordemLoteria.map(t => [t, gruposEditados[t] || 0]))
      })
    });
    const data = await res.json();
    if (!data.success) { alert(data.error || 'Não foi possível gravar a ordem.'); return; }
    ordemPendente = false;
    await carregarPrevia();   // relê do banco: o que a tela mostra é o que está gravado
  } catch (e) {
    alert('Não foi possível gravar a ordem.');
  } finally {
    btn.disabled = false;
    btn.innerHTML = rotulo;
  }
}

/**
 * A MATRIZ: cada time contra cada escolha.
 *
 * Sai da simulação do sorteio, com o piso de proteção incluído — por isso os
 * 3 piores aparecem zerados nas quatro últimas colunas e acumulados na 12ª.
 * Os totais são somados dos valores EXIBIDOS, não recalculados: se der 99,9
 * ou 100,1 é o arredondamento das casas mostradas, e escondê-lo com um 100,0
 * fixo seria mentir sobre a conta que está na tela.
 */
/* AS BOLINHAS DESENHADAS.
   O número diz a mesma coisa, mas é vendo três bolinhas contra uma que se
   entende o modelo sem ler a regra — e a loteria existe pra ser entendida
   por quem está nela, não só conferida. */
function bolinhasDe(n, grupo){
  return '<span class="bolinhas">'
    + `<span class="bolinha cor-g${grupo}"></span>`.repeat(Math.max(0, n))
    + '</span>';
}

function renderMatriz(data){
  const titulo = $('matrizTitulo'), painel = $('matrizPainel');
  if (!titulo || !painel) return;
  const m = data.matriz;
  if (!m || !data.balls || !data.balls.length) {
    titulo.style.display = 'none'; painel.style.display = 'none';
    return;
  }
  titulo.style.display = ''; painel.style.display = '';

  const nPicks = data.balls.length;
  const picks = Array.from({length: nPicks}, (_, i) => i + 1);

  $('matrizHead').innerHTML = `<tr>
    <th class="time-col">Time</th>
    ${picks.map(p => `<th class="num">${p}</th>`).join('')}
    <th class="num total-col">Total</th>
  </tr>`;

  // Faixas pela maior célula da tabela — uma escala fixa deixaria a matriz
  // toda clara quando as chances são baixas e espalhadas.
  let maior = 0;
  data.balls.forEach(b => picks.forEach(p => { const v = (m[b.team_id] || {})[p] || 0; if (v > maior) maior = v; }));
  const faixa = (v) => {
    if (!v) return 'zero';
    const r = v / (maior || 1);
    return r > .55 ? 'q4' : r > .3 ? 'q3' : r > .12 ? 'q2' : 'q1';
  };

  $('matrizBody').innerHTML = data.balls.map(b => {
    const linha = m[b.team_id] || {};
    const total = picks.reduce((a, p) => a + (linha[p] || 0), 0);
    return `<tr>
      <td class="time-col" title="${esc(b.group_label || '')}">${esc(b.team_name)}</td>
      ${picks.map(p => {
        const v = linha[p] || 0;
        return `<td class="celula ${faixa(v)}">${v ? v.toFixed(2) : '—'}</td>`;
      }).join('')}
      <td class="celula total-col">${total.toFixed(2)}</td>
    </tr>`;
  }).join('');

  $('matrizFoot').innerHTML = `<tr>
    <td class="time-col">Soma da escolha</td>
    ${picks.map(p => {
      const s = data.balls.reduce((a, b) => a + ((m[b.team_id] || {})[p] || 0), 0);
      return `<td class="celula">${s.toFixed(2)}</td>`;
    }).join('')}
    <td class="celula total-col">—</td>
  </tr>`;

  $('matrizRodape').innerHTML = 'Cada <b>linha</b> fecha 100%: o time termina em alguma escolha. '
    + 'Cada <b>coluna</b> também: a escolha vai pra alguém. '
    + 'Os 3 piores ficam vazios da 13ª em diante porque o piso não deixa eles caírem além da 12ª — '
    + 'é daí que vem a coluna 12 tão alta deles.';
}

function setupBoardAndOdds(data){
  revealed = new Set();
  busy = false;
  photoById = {};
  // Vale pra tabela de chances e pro quadro, e a tabela vem primeiro.
  const editandoOrdem = !!data.preview && PODE_EDITAR_ORDEM;
  if (data.group_meta) {
    GRUPOS_OPCOES = Object.entries(data.group_meta)
      .map(([g, m]) => [parseInt(g, 10), m.label, m.balls + (m.balls === 1 ? ' bolinha' : ' bolinhas')]);
  }
  // O que o servidor devolve é a verdade: os marcados são os que vieram
  // marcados de lá, não o que a tela lembra de ter clicado.
  if (data.preview && !ordemPendente) {
    gruposEditados = {};
    data.balls.forEach(b => { if (b.group_declarado) gruposEditados[b.team_id] = b.group; });
  }
  /* Sorteou de verdade: o aviso de prévia sai e o botão do topo muda de
     nome. Ele NÃO some: o "Sortear de novo" só aparece quando a revelação
     termina, e esconder os dois deixaria sem saída quem sorteou a sessão
     errada e quer refazer no ato. Some do botão só a inocência — a partir
     daqui ele pergunta antes, porque jogar fora um sorteio que a liga já
     está vendo revelar não pode acontecer por um clique distraído. */
  const avisoPrevia = $('previaAviso');
  if (avisoPrevia) avisoPrevia.style.display = data.preview ? '' : 'none';
  const btnSortear = $('btnPrepare');
  if (btnSortear) {
    btnSortear.innerHTML = data.preview
      ? '<i class="bi bi-dice-5-fill"></i> Sortear a loteria'
      : '<i class="bi bi-arrow-repeat"></i> Sortear de novo';
  }
  data.order.forEach(o => { photoById[o.team_id] = o.photo_url; });

  /* Chances. O grupo vira um seletor enquanto a loteria é prévia: quem caiu
     no play-in e quem perdeu o 7x8 é resultado de jogo, e nenhuma ordenação
     da tabela revela isso — antes o sistema deduzia pela posição e acabava
     pendurando "derrotado no 7x8" em times que nem jogaram o play-in.

     Trocar uma tag muda o tamanho do grupo, o total de bolinhas e portanto a
     chance de todo mundo, então quem recalcula é o servidor. */
  const totalBolas = data.total_balls || 0;
  $('ballsBody').innerHTML = data.balls.map(b => `
    <tr>
      <td><span style="display:inline-flex;align-items:center;gap:8px">${logo(b.photo_url,'board-logo')}${esc(b.team_name)}${b.conference ? `<span class="conf-chip">${esc(b.conference)}</span>` : ''}</span></td>
      <td>${editandoOrdem
        ? `<select class="grupo-sel cor-g${b.group}${b.group_declarado ? ' marcado' : ''}" title="${b.balls} bolinha(s)" onchange="mudarGrupo(${b.team_id}, this.value)">
             <option value="0"${b.group_declarado ? '' : ' selected'}>Automático — ${esc(b.group_label || '')}</option>
             ${GRUPOS_OPCOES.map(([g, rotulo, bolas]) =>
               `<option value="${g}"${b.group_declarado && b.group === g ? ' selected' : ''}>${esc(rotulo)} (${bolas})</option>`).join('')}
           </select>`
        : `<span class="conf-chip cor-g${b.group}" title="${b.balls} bolinha(s)">${esc(b.group_label || '')}</span>`}</td>
      <td>${bolinhasDe(b.balls, b.group)} <span style="color:var(--text-3);font-size:10px">${b.balls}</span></td>
      <td class="num">${b.position_anterior}º</td>
      <td class="num">${b.top1_pct}%</td>
      <td class="num">${b.top3_pct}%</td>
      <td class="num">${b.top5_pct}%</td>
    </tr>
  `).join('');
  renderMatriz(data);
  const rodape = $('ballsRodape');
  if (rodape) rodape.innerHTML = totalBolas
    ? `<b>${totalBolas} bolinhas</b> na urna. A coluna <b>Nº 1</b> é a única que soma 100% entre os times — `
      + `Top 3 soma 300% e Top 5 soma 500%, porque são três e cinco escolhas sendo distribuídas.`
    : '';

  // Ajustes anti-tanking
  const adjSection = $('adjustmentsSection');
  if (data.adjustments && data.adjustments.length) {
    adjSection.style.display = 'block';
    $('adjustmentsList').innerHTML = data.adjustments.map(a => `
      <div class="adjustment-item"><i class="bi bi-exclamation-triangle-fill"></i> ${esc(a)}</div>
    `).join('');
  } else {
    adjSection.style.display = 'none';
  }

  /* A URNA E O PALCO SÓ EXISTEM PRA QUEM CONDUZ.
     Quem não administra recebe a página sem esses elementos, e o resto
     desta função continua valendo pra ele — as chances, a matriz e o
     quadro. Sem esta guarda, o primeiro innerHTML num elemento que não
     existe interrompia a montagem no meio e o quadro ficava vazio. */
  const temPalco = !!$('revealStage');

  // Urna: times de loteria ainda concorrendo (esvazia a cada revelação)
  const lotteryTeams = data.balls.slice(); // já vem do pior pro "menos pior"
  if (temPalco) {
  $('bowl').innerHTML = lotteryTeams.map(b => `
    <div class="bowl-tile" id="bowl-${b.team_id}">
      ${logo(b.photo_url,'bowl-logo')}
      <div class="bowl-name">${esc(b.team_name)}</div>
      <div class="bowl-odds" title="Chance de Top 5: ${b.top5_pct}%">${b.top3_pct}% <span style="font-size:9px;color:var(--text-3);font-weight:600">Top 3</span></div>
      <div title="${b.balls} bolinha(s) na urna" style="margin-top:3px">${bolinhasDe(b.balls, b.group)}</div>
      <div class="bowl-pos">${b.position_anterior}º ${esc(b.conference || '')}</div>
    </div>
  `).join('');
  updateBowlCount(lotteryTeams.length);
  }

  /* QUADRO. Antes do sorteio ele mostra a ordem da CAMPANHA e deixa o admin
     corrigi-la, porque é dela que saem os grupos de bolinhas: um time no
     lugar errado aqui recebe a chance errada lá em cima. Depois do sorteio a
     ordem é resultado, e volta a ser o quadro de sempre — loteria oculta até
     a revelação, playoff travado embaixo. */
  if (data.preview) {
    ordemLoteria = data.order.filter(o => o.source !== 'playoff').map(o => o.origin_team_id);
    ordemPlayoff = data.order.filter(o => o.source === 'playoff').map(o => o.origin_team_id);
    if (!ordemPendente) {
      ordemSalvaRef = ordemLoteria.slice();
      ordemPlayoffSalvaRef = ordemPlayoff.slice();
    }
  } else {
    // Sorteado: a ordem virou resultado e não se edita mais.
    ordemPendente = false;
  }
  atualizarBarraOrdem();

  $('board').innerHTML = data.order.map(o => {
    const isPlayoff = o.source === 'playoff';
    const top4 = o.position <= 4 ? ' top4' : '';
    /* Os dois blocos se reordenam por dentro, nunca entre si: um time do
       playoff não vira time de loteria por causa de uma seta. */
    if (editandoOrdem) {
      const lista = isPlayoff ? ordemPlayoff : ordemLoteria;
      const ref   = isPlayoff ? ordemPlayoffSalvaRef : ordemSalvaRef;
      const bloco = isPlayoff ? 'playoff' : 'loteria';
      const i = lista.indexOf(o.origin_team_id);
      const mudou = ref[i] !== undefined && ref[i] !== o.origin_team_id;
      const titulos = isPlayoff
        ? ['Subir (escolhe antes)', 'Descer (escolhe depois)']
        : ['Subir (campanha pior)', 'Descer (campanha melhor)'];
      return `<div class="board-slot editavel${top4}${mudou ? ' movido' : ''}" id="board-slot-${o.position}">
        <span class="board-pos">${o.position}</span>
        ${logo(o.photo_url,'board-logo')}
        <span class="board-team">${esc(o.team_name)}${o.is_swap ? ' ' + viaTag(o) : ''}</span>
        ${isPlayoff ? '<span class="board-tag playoff"><i class="bi bi-lock-fill"></i> Playoff</span>' : ''}
        <span class="board-move">
          <button type="button" title="${titulos[0]}" onclick="moverOrdem('${bloco}',${i},-1)"${i === 0 ? ' disabled' : ''}><i class="bi bi-caret-up-fill"></i></button>
          <button type="button" title="${titulos[1]}" onclick="moverOrdem('${bloco}',${i},1)"${i === lista.length - 1 ? ' disabled' : ''}><i class="bi bi-caret-down-fill"></i></button>
        </span>
      </div>`;
    }
    if (isPlayoff) {
      return `<div class="board-slot locked${top4}" id="board-slot-${o.position}">
        <span class="board-pos">${o.position}</span>
        ${logo(o.photo_url,'board-logo')}
        <span class="board-team">${esc(o.team_name)}${o.is_swap ? ' ' + viaTag(o) : ''}</span>
        <span class="board-tag playoff"><i class="bi bi-lock-fill"></i> Playoff</span>
      </div>`;
    }
    return `<div class="board-slot pending${top4}" id="board-slot-${o.position}">
      <span class="board-pos">${o.position}</span>
      <img class="board-logo" id="board-logo-${o.position}" src="${LOGO_FALLBACK}" alt="" style="visibility:hidden" onerror="this.src='${LOGO_FALLBACK}'">
      <span class="board-team q" id="board-team-${o.position}">Aguardando...</span>
      <span class="board-tag lottery" style="visibility:hidden" id="board-tag-${o.position}">Loteria</span>
    </div>`;
  }).join('');

  // Fila de revelação: só picks de loteria, da última (maior posição) até a #1
  revealQueue = data.order
    .filter(o => o.source !== 'playoff')
    .sort((a, b) => b.position - a.position)
    .map(o => o.position);

  // Estado do palco
  if (!temPalco) return;
  // O painel de aplicar ao draft não existe pra quem só assiste.
  if ($('confirmPanel')) $('confirmPanel').style.display = 'none';
  $('revealStage').classList.remove('armed');
  $('ballMachine')?.classList.remove('on');
  $('revealLogo').style.display = '';
  $('revealLogo').style.visibility = 'hidden';
  $('revealLogo').className = 'reveal-logo';
  $('revealNumber').className = 'reveal-number';
  $('revealNumber').textContent = '#?';
  $('revealTeam').className = 'reveal-team q';
  $('revealTeam').textContent = '—';
  $('revealConf').textContent = '';
  $('revealMove').className = 'reveal-move';
  $('revealMove').textContent = '';
  $('revealPassed').className = 'reveal-passed';
  $('revealPassed').innerHTML = '';
  $('revealVia').innerHTML = '';
  $('podium').innerHTML = '';
  $('podiumTitle').style.display = 'none';
  updateRevealButton();
}

function updateBowlCount(n){
  $('bowlCount').textContent = n > 0 ? `${n} ${n === 1 ? 'time concorrendo' : 'times concorrendo'}` : 'urna vazia';
}

let jaAplicouAoDraft = false;

function updateRevealButton(){
  // Quem assiste tem o palco, mas não o botão: aqui ele pode não existir.
  const btn = $('btnReveal');
  if (!revealQueue.length) {
    if (btn) btn.style.display = 'none';
    $('revealPickLabel').textContent = 'Sorteio completo';
    $('revealHint').textContent = PODE_EDITAR_ORDEM
      ? 'Todas as picks reveladas. A ordem já foi para o draft.'
      : 'Todas as picks reveladas.';
    if ($('confirmPanel')) $('confirmPanel').style.display = 'block';
    $('revealStage').classList.remove('armed');
    document.body.classList.add('bc-complete'); // esconde revelação/urna na transmissão
    renderPodium();                             // mostra o pódio do top-3

    /* A última pick saiu: a ordem do draft é esta, e não há mais nada a
       decidir. Vai sozinha, uma vez só — a revelação passa por aqui várias
       vezes até a tela assentar. */
    if (PODE_EDITAR_ORDEM && !MODO_TESTE && !jaAplicouAoDraft && result && result.preview === false) {
      jaAplicouAoDraft = true;
      aplicarAoDraft(false);
    }
    return;
  }
  // Sorteou de novo: há o que aplicar outra vez, e o painel volta ao começo.
  if (jaAplicouAoDraft) restaurarConfirmPanel();
  jaAplicouAoDraft = false;
  document.body.classList.remove('bc-complete');
  const nextPos = revealQueue[0];
  if (btn) {
    btn.style.display = 'inline-flex';
    btn.disabled = false;
    btn.innerHTML = `<i class="bi bi-caret-right-fill"></i> Revelar pick #${nextPos}`;
  }
  $('revealPickLabel').textContent = revealQueue.length === 1 ? 'A escolha nº 1 — grande final' : `Faltam ${revealQueue.length} escolhas`;
}

const REDUCED_MOTION = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

/**
 * Cerimônia da bolinha: joga no globo uma bolinha por time ainda na urna,
 * gira todas, desacelera e "puxa" a do time sorteado. O resultado já veio
 * pronto do servidor — isto é só a encenação. Chama onDone() no fim.
 */
function spinBalls(pool, entry, onDone){
  const machine = $('ballMachine'), globe = $('ballGlobe');
  if (!machine || !globe || REDUCED_MOTION) { onDone(); return; }

  globe.classList.remove('slowing');
  globe.innerHTML = '';
  machine.classList.add('on'); // precisa estar visível antes de medir o raio

  const raio = Math.max(Math.min(machine.clientWidth, machine.clientHeight) / 2 - 26, 20);
  globe.innerHTML = pool.map(o => {
    // Órbitas e velocidades diferentes por bolinha = movimento de urna, não de carrossel.
    const r = Math.round(10 + Math.random() * raio);
    const dur = (1.0 + Math.random() * 0.9).toFixed(2);
    const delay = (-Math.random() * 2).toFixed(2);
    const dir = Math.random() < 0.5 ? 'normal' : 'reverse';
    return `<div class="lottery-ball" id="lb-${o.team_id}" style="--r:${r}px;animation-duration:${dur}s;animation-delay:${delay}s;animation-direction:${dir}">
      <img src="${esc(o.photo_url || LOGO_FALLBACK)}" alt="" onerror="this.src='${LOGO_FALLBACK}'">
    </div>`;
  }).join('');

  const giro = 1600 + Math.random() * 700;
  setTimeout(() => {
    globe.classList.add('slowing');
    const bola = document.getElementById('lb-' + entry.team_id);
    if (bola) bola.classList.add('drawn');
    setTimeout(() => {
      machine.classList.remove('on');
      onDone();
    }, bola ? 820 : 200);
  }, giro);
}

/* ─── A CERIMÔNIA AO VIVO ──────────────────────────────────────────────
   O sorteio vivia no navegador de quem apertou o botão: quem conduzia via
   as picks saírem e a liga inteira olhava uma tela parada. Agora quem
   conduz publica a ordem ao sortear e avisa a cada revelação; quem assiste
   pergunta de poucos em poucos segundos e vê a mesma bolinha girar.

   Nada disso aplica a ordem ao draft — quem faz isso é o Confirmar. */
const SESSAO_ID = () => parseInt(($('sessionSelect') || {}).value || 0, 10);

async function transmitirSorteio(data){
  if (!PODE_EDITAR_ORDEM || MODO_TESTE || !data || data.preview !== false) return;
  try {
    await fetch('/api/draft.php', {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        action: 'lottery_transmitir',
        draft_session_id: SESSAO_ID(),
        ordem: data.order,
        ajustes: data.adjustments || [],
      })
    });
  } catch (e) { /* a cerimônia continua na tela de quem conduz */ }
}

function transmitirRevelada(pos){
  if (!PODE_EDITAR_ORDEM || MODO_TESTE) return;
  fetch('/api/draft.php', {
    method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ action: 'lottery_revelar', draft_session_id: SESSAO_ID(), position: pos })
  }).catch(() => {});
}

let acompanhandoEm = null;   // carimbo da última mudança já aplicada
let acompanhandoFila = [];

/* Quem assiste. Só entra aqui quem não conduz: pra quem conduz, o servidor
   é o eco do que ele mesmo acabou de fazer. */
/**
 * Pega o que está no ar e coloca na tela.
 *
 * Vale pra quem assiste, de três em três segundos, E pra quem conduz, uma
 * vez ao abrir a página: a ordem sorteada não mora mais no navegador de
 * ninguém, então recarregar deixava o próprio admin diante de uma tela
 * dizendo que nada foi sorteado — com a cerimônia no ar e metade da liga
 * olhando as picks saírem.
 */
async function acompanharCerimonia(){
  if (MODO_TESTE) return;
  const sid = SESSAO_ID();
  if (!sid) return;
  try {
    const res = await fetch(`/api/draft.php?action=lottery_transmissao&draft_session_id=${sid}`);
    const d = await res.json();
    if (!d.success || !d.no_ar) return;
    if (d.em === acompanhandoEm) return;      // nada mudou desde a última olhada
    /* Nunca no meio de uma bolinha girando. Redesenhar o quadro agora
       cortaria a revelação em curso pela metade — e a próxima olhada, três
       segundos depois, encontra a mesma novidade esperando. */
    if (busy) return;
    const primeiraVez = acompanhandoEm === null;
    acompanhandoEm = d.em;

    // A ordem sorteada substitui a prévia: daqui pra frente o quadro é
    // resultado, não retrato da campanha.
    result = Object.assign({}, result, { order: d.ordem, adjustments: d.ajustes, preview: false });
    const jaVistas = new Set(revealed);
    setupBoardAndOdds(result);
    revealQueue = d.ordem.filter(o => o.source !== 'playoff')
                         .map(o => o.position).sort((a, b) => b - a);

    /* Quem chega no meio recebe tudo de uma vez, sem encenação: a bolinha
       girando dezesseis vezes seguidas não é cerimônia, é espera. O que
       chega DEPOIS que a pessoa já está olhando, aí sim, é revelado. */
    d.reveladas.forEach(pos => {
      const novaAgora = !primeiraVez && !jaVistas.has(pos);
      revealQueue = revealQueue.filter(p => p !== pos);
      if (novaAgora) acompanhandoFila.push(pos);
      else aplicarRevelacao(pos, false);
    });
    escoarFilaDeQuemAssiste();

    /* A tela sai do estado de prévia: o que está embaixo é resultado.
       Sem isso, quem conduz recarregava a página e encontrava o aviso de
       "nada foi sorteado ainda" por cima da própria cerimônia. */
    const aviso = $('previaAviso');
    if (aviso) aviso.style.display = 'none';
    $('resultSection').style.display = 'block';
    const btnSortear = $('btnPrepare');
    if (btnSortear) btnSortear.innerHTML = '<i class="bi bi-arrow-repeat"></i> Sortear de novo';
  } catch (e) { /* próxima olhada tenta de novo */ }
}

function escoarFilaDeQuemAssiste(){
  if (busy || !acompanhandoFila.length) return;
  const pos = acompanhandoFila.shift();
  aplicarRevelacao(pos, true);
}

function revealNext(){
  if (busy || !revealQueue.length) return;
  const pos = revealQueue[0];
  // Quem conduz avisa o servidor ANTES da animação: quem assiste tem os
  // mesmos segundos de bolinha girando, não o resultado já pronto.
  transmitirRevelada(pos);
  aplicarRevelacao(pos, true);
}

/**
 * REVELA UMA ESCOLHA — a mesma função pra quem conduz e pra quem assiste.
 *
 * Com encenação, é a bolinha girando. Sem, o quadro simplesmente aparece
 * preenchido: é o caso de quem abre a página no meio da cerimônia, que não
 * ganharia nada assistindo a dezesseis giros seguidos de uma vez.
 */
function aplicarRevelacao(pos, comEncenacao){
  if (revealed.has(pos)) return;
  const entry = (result.order || []).find(o => o.position === pos);
  if (!entry) return;
  busy = true;
  revealQueue = revealQueue.filter(p => p !== pos);
  const btn = $('btnReveal');
  if (btn) btn.disabled = true;
  $('revealStage').classList.add('armed');

  // decoys = times de loteria ainda não revelados (as outras bolinhas do globo)
  const decoys = result.order
    .filter(o => o.source !== 'playoff' && !revealed.has(o.position) && o.position !== pos);

  const numEl = $('revealNumber'), teamEl = $('revealTeam'), confEl = $('revealConf'), moveEl = $('revealMove'), logoEl = $('revealLogo'), passedEl = $('revealPassed');
  numEl.className = 'reveal-number on';
  numEl.textContent = '#' + pos;
  confEl.textContent = '';
  moveEl.className = 'reveal-move';
  moveEl.textContent = '';
  passedEl.className = 'reveal-passed';
  passedEl.innerHTML = '';
  $('revealVia').innerHTML = '';
  // O nome e o escudo só aparecem depois que a bolinha sai do globo. Aqui é
  // display:none (e não visibility) para o escudo não deixar um vão de 120px
  // entre o globo e o número da pick enquanto as bolinhas giram.
  teamEl.className = 'reveal-team q';
  teamEl.textContent = '';
  logoEl.className = 'reveal-logo';
  logoEl.style.display = 'none';

  // Todas as bolinhas que ainda estão na urna, com a sorteada entre elas.
  if (comEncenacao) spinBalls([entry].concat(decoys), entry, land);
  else land();

  function land(){
    logoEl.style.display = '';
    logoEl.style.visibility = 'visible';
    teamEl.className = 'reveal-team landed';
    teamEl.textContent = entry.team_name;
    confEl.textContent = entry.conference || '';
    logoEl.src = entry.photo_url || LOGO_FALLBACK;
    logoEl.className = 'reveal-logo pop';
    $('revealVia').innerHTML = viaTag(entry);

    // ── Efeitos da revelação ──
    const stage = $('revealStage');
    const isFinale = pos === 1;
    stage.classList.remove('flash','rise','finale');
    void stage.offsetWidth; // reinicia animações
    stage.classList.add(isFinale ? 'finale' : 'flash');
    setTimeout(() => stage.classList.remove('flash','finale'), 1500);
    numEl.classList.remove('punch'); void numEl.offsetWidth; numEl.classList.add('punch');

    // movimento
    const d = entry.delta || 0;
    if (d > 0) { moveEl.className = 'reveal-move up show'; moveEl.innerHTML = `<i class="bi bi-arrow-up-short"></i> Subiu ${d} ${d===1?'posição':'posições'}`; }
    else if (d < 0) { moveEl.className = 'reveal-move down show'; moveEl.innerHTML = `<i class="bi bi-arrow-down-short"></i> Caiu ${Math.abs(d)} ${Math.abs(d)===1?'posição':'posições'}`; }
    else { moveEl.className = 'reveal-move same show'; moveEl.innerHTML = `<i class="bi bi-dash"></i> Manteve a posição`; }

    // Subiu: brilho verde + partículas ▲ (mais intensas quanto maior o salto)
    if (d > 0) {
      stage.classList.add('rise');
      setTimeout(() => stage.classList.remove('rise'), 1200);
      spawnParticles('▲', '#22c55e', Math.min(4 + d, 14));
    }
    // Finale (#1): chuva dourada de estrelas
    if (isFinale) spawnParticles('★', '#f59e0b', 16);

    // Quem passou na frente (só quando caiu)
    const passed = entry.passed_by || [];
    if (d < 0 && passed.length) {
      passedEl.innerHTML = `<div class="reveal-passed-label"><i class="bi bi-arrow-up"></i> Ultrapassado por</div>
        <div class="reveal-passed-teams">${passed.map(t => `<span class="reveal-passed-team">${logo(t.photo_url,'')}${esc(t.team_name)}</span>`).join('')}</div>`;
      passedEl.className = 'reveal-passed show';
    }

    // tira o time da urna (esvazia)
    const tile = $('bowl-' + entry.team_id);
    if (tile) { tile.classList.add('leaving'); setTimeout(() => tile.remove(), 360); }
    updateBowlCount(revealQueue.length); // quantos ainda faltam revelar = ainda na urna

    // preenche o quadro
    const slot = $('board-slot-' + pos);
    const teamSlot = $('board-team-' + pos);
    const tagSlot = $('board-tag-' + pos);
    const logoSlot = $('board-logo-' + pos);
    if (slot) { slot.classList.remove('pending'); slot.classList.add('just'); setTimeout(()=>slot.classList.remove('just'), 700); }
    if (logoSlot) { logoSlot.src = entry.photo_url || LOGO_FALLBACK; logoSlot.style.visibility = 'visible'; }
    if (teamSlot) {
      teamSlot.classList.remove('q');
      const badge = d > 0 ? ` <span class="board-move up">▲${d}</span>` : (d < 0 ? ` <span class="board-move down">▼${Math.abs(d)}</span>` : '');
      teamSlot.innerHTML = esc(entry.team_name) + (entry.is_swap ? ' ' + viaTag(entry) : '') + badge;
    }
    if (tagSlot) tagSlot.style.visibility = 'visible';

    revealed.add(pos);
    busy = false;
    updateRevealButton();
    // Quem assiste pode ter recebido mais de uma escolha enquanto esta rodava.
    if (typeof escoarFilaDeQuemAssiste === 'function') escoarFilaDeQuemAssiste();
  }
}

async function confirmOrder(){
  if (!result) return;
  if (!await confirmarSite('Confirmar essa ordem e aplicar ao draft? Isso substitui qualquer ordem já definida para as duas rodadas dessa sessão.')) return;
  aplicarAoDraft(true);
}

/**
 * GRAVA A ORDEM NO DRAFT.
 *
 * Quando a última pick sai, a loteria já disse tudo que tinha a dizer: a
 * ordem do draft é aquela. Pedir mais um clique depois disso era só um
 * passo a mais entre a cerimônia e o draft aberto — e um jeito de a liga
 * ficar com a ordem revelada na tela e nenhuma ordem gravada.
 *
 * Sortear de novo depois substitui o que foi gravado, então nada fica preso.
 */
async function aplicarAoDraft(comBotao){
  if (!result || !PODE_EDITAR_ORDEM || MODO_TESTE) return;
  const btn = comBotao ? $('btnConfirm') : null;
  if (btn) {
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Aplicando...';
  }
  try {
    /* VAI O TIME DE ORIGEM, NÃO QUEM ESCOLHE.
       O slot pertence a quem fez a campanha; quem escolhe pode ser outro,
       se a pick foi trocada. O servidor grava a ordem pelas origens e só
       então resolve dono atual, swap e proteção — mandando o dono já
       resolvido, ele resolvia de novo sobre o resultado, e a pick comprada
       voltava pro time de origem. */
    const teamOrder = result.order.map(o => o.origin_team_id ?? o.team_id);
    const res = await fetch('/api/draft.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'set_draft_order', draft_session_id: result.draft_session_id, team_order: teamOrder })
    });
    const data = await res.json();
    if (!data.success) {
      if (comBotao) alert(data.error || 'Erro ao aplicar a ordem.');
      else avisarAplicada(false, data.error);
      return;
    }
    mostrarEventos(data.eventos || []);
    if (!comBotao) avisarAplicada(true);
  } catch (e) {
    if (comBotao) alert('Erro ao aplicar a ordem.');
    else avisarAplicada(false);
  } finally {
    if (btn) {
      btn.disabled = false;
      btn.innerHTML = '<i class="bi bi-check-lg"></i> Confirmar e aplicar ao draft';
    }
  }
}

/* O aviso fica onde estava o botão de confirmar: é a resposta à pergunta
   "e agora?" que a pessoa faz quando a última pick sai. O conteúdo original
   é guardado antes de ser trocado — sortear de novo devolve o painel ao
   começo, senão a tela continua dizendo "ordem aplicada" sobre a cerimônia
   nova, que ainda não terminou. */
let confirmPanelOriginal = null;
function restaurarConfirmPanel(){
  const painel = $('confirmPanel');
  if (!painel || confirmPanelOriginal === null) return;
  painel.innerHTML = confirmPanelOriginal;
  const btn = $('btnConfirm');
  if (btn) btn.addEventListener('click', confirmOrder);
}

function avisarAplicada(ok, erro){
  const painel = $('confirmPanel');
  if (!painel) return;
  if (confirmPanelOriginal === null) confirmPanelOriginal = painel.innerHTML;
  painel.style.display = 'block';
  painel.innerHTML = ok
    ? `<div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
         <i class="bi bi-check-circle-fill" style="color:var(--green);font-size:20px"></i>
         <div style="flex:1;min-width:200px">
           <b>Ordem aplicada ao draft.</b>
           <div style="font-size:11px;color:var(--text-3)">Vale para as duas rodadas. Sortear de novo substitui.</div>
         </div>
         <a class="btn-ghost2" href="/controledrafts.php?league=${encodeURIComponent(LIGA_ATUAL)}"><i class="bi bi-box-arrow-up-right"></i> Ir para o Controle de Drafts</a>
       </div>`
    : `<div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
         <i class="bi bi-exclamation-triangle-fill" style="color:var(--amber);font-size:20px"></i>
         <div style="flex:1;min-width:200px">
           <b>A ordem não foi gravada no draft.</b>
           <div style="font-size:11px;color:var(--text-3)">${esc(erro || 'Tente pelo botão abaixo.')}</div>
         </div>
         <button class="btn-red" onclick="aplicarAoDraft(true)"><i class="bi bi-check-lg"></i> Aplicar ao draft</button>
       </div>`;
}

/**
 * O que a loteria decidiu além da ordem.
 *
 * Proteção e swap só se resolvem QUANDO a ordem sai — antes disso são
 * apostas. Se isso não for mostrado agora, ninguém mais vai saber que
 * houve acordo: a ordem final mostra o resultado sem a explicação, e a
 * pergunta "por que o Coyotes escolhe na vaga do Kings?" fica sem resposta.
 *
 * Sem nada a dizer, volta o alerta simples de sempre.
 */
function mostrarEventos(eventos) {
  if (!eventos.length) { alert('Ordem aplicada com sucesso ao draft!'); return; }

  const cx = document.getElementById('painelEventos');
  const corpo = document.getElementById('eventosCorpo');
  if (!cx || !corpo) {
    alert('Ordem aplicada!\n\n' + eventos.map(e => '• ' + e.texto + (e.extra ? '\n  ' + e.extra : '')).join('\n'));
    return;
  }

  const escE = (s) => String(s ?? '').replace(/[&<>"']/g, (c) =>
    ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  corpo.innerHTML = eventos.map((e) => `
    <div class="ev ${e.tipo}${e.passou ? '' : ' barrado'}">
      <div class="ev-tag">${e.tipo === 'swap' ? 'SWAP' : (e.passou ? 'PASSOU' : 'PROTEGIDA')}</div>
      <div>
        <div class="ev-txt">${escE(e.texto)}</div>
        ${e.extra ? `<div class="ev-extra">${escE(e.extra)}</div>` : ''}
      </div>
    </div>`).join('');
  cx.style.display = 'block';
  cx.scrollIntoView({ behavior: 'smooth', block: 'center' });
}

// Quem não administra loteria nenhuma não tem esses controles na página
// (só vê a ordem já confirmada), então todos os binds ficam guardados.
// A partir do segundo sorteio o clique descarta um resultado que já existe —
// e que pode estar sendo revelado na frente da liga. Pergunta antes.
if ($('btnPrepare')) $('btnPrepare').addEventListener('click', () => {
  const jaSorteou = result && result.preview === false;
  if (jaSorteou && !confirm('Sortear de novo? A ordem que está na tela é descartada e uma nova é sorteada do zero.')) return;
  prepare();
});
if ($('btnReveal')) $('btnReveal').addEventListener('click', revealNext);
if ($('btnConfirm')) $('btnConfirm').addEventListener('click', confirmOrder);
// Refazer joga fora um sorteio que já aconteceu — e que a liga pode já ter
// visto sendo revelado. Pergunta antes.
if ($('btnRedo')) $('btnRedo').addEventListener('click', () => {
  if (confirm('Sortear de novo? A ordem que está na tela é descartada e uma nova é sorteada do zero.')) prepare();
});

/* A PRÉVIA CARREGA SOZINHA.
   Quem abre esta tela quer ver quem entra na loteria, em que grupo e com
   quantas bolinhas — e até agora isso só aparecia depois de apertar o botão,
   que já é o sorteio. Então o quadro vem montado de saída, com a ordem
   natural (pior campanha primeiro) e as chances de cada um.

   É PRÉVIA, não sorteio: pedir o sorteio pra preencher a tela faria sair uma
   ordem nova a cada vez que a página abrisse, e a que vale seria a última —
   o admin veria um resultado que não é o resultado. */
async function carregarPrevia(ordemProvisoria, caudaProvisoria, gruposProvisorios){
  const sel = $('sessionSelect');
  if (!sel || !sel.value) return;
  try {
    const corpo = { action: 'run_lottery', draft_session_id: parseInt(sel.value, 10), preview: true };
    // No ensaio até a prévia vai marcada: é o que permite a página funcionar
    // pra quem chegou pelo link sem ter conta.
    if (MODO_TESTE) corpo.simulacao = true;
    // Ordem ainda não gravada: o servidor recalcula os grupos com ela e
    // devolve as chances de verdade, em vez de a tela adivinhar a regra.
    if (Array.isArray(ordemProvisoria)) corpo.ordem = ordemProvisoria;
    if (Array.isArray(caudaProvisoria)) corpo.ordem_playoff = caudaProvisoria;
    if (gruposProvisorios) corpo.grupos = gruposProvisorios;
    const res = await fetch('/api/draft.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(corpo)
    });
    const data = await res.json();
    if (!data.success) {
      /* A pessoa escolheu a liga clicando numa aba e merece saber por que
         não veio nada — calar aqui deixa a tela em branco sem explicação. */
      const box = $('semLoteria'), txt = $('semLoteriaTexto');
      if (box && txt) {
        txt.textContent = data.error || 'Esta liga não tem uma loteria pra mostrar agora.';
        box.style.display = '';
      }
      $('resultSection').style.display = 'none';
      return;
    }
    if ($('semLoteria')) $('semLoteria').style.display = 'none';
    result = data;
    setupBoardAndOdds(data);
    $('resultSection').style.display = 'block';
    // Confirmar só existe depois de sortear de verdade.
    if ($('btnConfirm')) $('btnConfirm').style.display = 'none';
    const aviso = $('previaAviso');
    if (aviso) aviso.style.display = '';
  } catch (e) { /* a tela continua servindo pelo botão */ }
}
// Trocar de sessão zera a edição pendente: a ordem é de outra temporada.
if ($('sessionSelect')) $('sessionSelect').addEventListener('change', () => { ordemPendente = false; carregarPrevia(); });
$('btnOrdemSalvar')?.addEventListener('click', salvarOrdem);
$('btnOrdemDesfazer')?.addEventListener('click', desfazerOrdem);
/* Última barreira antes de perder o trabalho. A barra já avisa na tela, mas
   quem trocou uma tag e foi conferir outra coisa sai sem olhar pra cima. */
window.addEventListener('beforeunload', (e) => {
  if (!ordemPendente || MODO_TESTE) return;   // no ensaio não há nada a perder
  e.preventDefault();
  e.returnValue = '';
});
carregarPrevia().then(() => {
  if (MODO_TESTE) return;
  /* TODOS na mesma cerimônia, o tempo todo.
     Ao abrir, a tela retoma o que está no ar — inclusive a de quem conduz,
     que senão recarrega e encontra a própria cerimônia sumida. E todos
     continuam perguntando: quando a liga tem mais de um admin, quem não
     está sorteando acompanha igual a qualquer GM.

     Três segundos é rápido o bastante pra parecer ao vivo e espaçado o
     bastante pra dezenas de páginas abertas não virarem carga. A resposta é
     minúscula, e a tela só é redesenhada quando o carimbo de hora muda. */
  acompanharCerimonia();
  setInterval(acompanharCerimonia, 3000);
});
</script>
</body>
</html>
