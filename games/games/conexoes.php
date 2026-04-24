<?php
// conexoes.php — Conexões NBA: descubra os 4 grupos ocultos
// session_start() já chamado no index.php se acessado por ele; chamamos aqui para acesso direto também
if (session_status() === PHP_SESSION_NONE) session_start();
require '../core/conexao.php';

if (!isset($_SESSION['user_id'])) { header("Location: ../auth/login.php"); exit; }
$user_id = (int)$_SESSION['user_id'];
$PONTOS_VITORIA = 120 * getGamePointsMultiplier($pdo, 'conexoes');

// Cria tabela
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS conexoes_historico (
        id INT AUTO_INCREMENT PRIMARY KEY,
        id_usuario INT NOT NULL,
        data_jogo DATE NOT NULL,
        puzzle_idx INT DEFAULT 0,
        grupos_encontrados TEXT DEFAULT '[]',
        vidas_restantes INT DEFAULT 4,
        pontos_ganhos INT DEFAULT 0,
        concluido TINYINT DEFAULT 0,
        desistiu TINYINT DEFAULT 0,
        UNIQUE KEY uq (id_usuario, data_jogo)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (PDOException $e) {}

try {
    $pdo->exec("INSERT IGNORE INTO fba_game_controls (game_key, is_double) VALUES ('conexoes', 0)");
} catch (PDOException $e) {}

// ── PUZZLES DIÁRIOS ──────────────────────────────────────────────────────────
$PUZZLES = [
    // 0
    [
        ['cor'=>'green',  'label'=>'Brasileiros na NBA',
         'dica'=>'Jogadores nascidos no Brasil',
         'jogadores'=>['Nenê','Leandro Barbosa','Anderson Varejão','Tiago Splitter']],
        ['cor'=>'yellow', 'label'=>'Calouro do Ano (2011–2016)',
         'dica'=>'Venceram o prêmio Rookie of the Year entre 2011 e 2016',
         'jogadores'=>['Blake Griffin','Kyrie Irving','Damian Lillard','Karl-Anthony Towns']],
        ['cor'=>'red',    'label'=>'Melhor Defensor (DPOY)',
         'dica'=>'Venceram o Defensive Player of the Year',
         'jogadores'=>['Kawhi Leonard','Dwight Howard','Dikembe Mutombo','Ben Wallace']],
        ['cor'=>'purple', 'label'=>'MVP sem nunca ganhar o título',
         'dica'=>'Foram MVP da temporada mas jamais conquistaram um anel',
         'jogadores'=>['Karl Malone','Charles Barkley','Steve Nash','Allen Iverson']],
    ],
    // 1
    [
        ['cor'=>'green',  'label'=>'Franceses na NBA',
         'dica'=>'Jogadores nascidos na França',
         'jogadores'=>['Tony Parker','Rudy Gobert','Nicolas Batum','Boris Diaw']],
        ['cor'=>'yellow', 'label'=>'Draft Class de 2003',
         'dica'=>'Foram selecionados no Draft de 2003 entre os primeiros escolhidos',
         'jogadores'=>['LeBron James','Carmelo Anthony','Chris Bosh','Dwyane Wade']],
        ['cor'=>'red',    'label'=>'Campeões Celtics 2024',
         'dica'=>'Jogaram e venceram com o Boston Celtics em 2024',
         'jogadores'=>['Jayson Tatum','Jaylen Brown','Jrue Holiday','Al Horford']],
        ['cor'=>'purple', 'label'=>'Sexto Homem do Ano',
         'dica'=>'Venceram o prêmio de Sixth Man of the Year',
         'jogadores'=>['Manu Ginobili','Leandro Barbosa','Dennis Schroder','Jamal Crawford']],
    ],
    // 2
    [
        ['cor'=>'green',  'label'=>'Canadenses na NBA',
         'dica'=>'Jogadores nascidos no Canadá',
         'jogadores'=>['Steve Nash','Andrew Wiggins','Jamal Murray','Shai Gilgeous-Alexander']],
        ['cor'=>'yellow', 'label'=>'Dynasty Warriors (2015–2019)',
         'dica'=>'Venceram o título com o Golden State Warriors entre 2015 e 2019',
         'jogadores'=>['Stephen Curry','Klay Thompson','Draymond Green','Kevin Durant']],
        ['cor'=>'red',    'label'=>'MVP + Finals MVP na mesma temporada',
         'dica'=>'Foram premiados como MVP da temporada E MVP das Finais no mesmo ano',
         'jogadores'=>["Shaquille O'Neal",'LeBron James','Giannis Antetokounmpo','Nikola Jokic']],
        ['cor'=>'purple', 'label'=>'Artilheiros históricos da NBA',
         'dica'=>'Venceram o título de artilheiro (Scoring Champion) múltiplas vezes',
         'jogadores'=>['Michael Jordan','Wilt Chamberlain','Kobe Bryant','James Harden']],
    ],
    // 3
    [
        ['cor'=>'green',  'label'=>'Sérvios na NBA',
         'dica'=>'Jogadores nascidos na Sérvia',
         'jogadores'=>['Nikola Jokic','Nikola Vucevic','Vlade Divac','Bogdan Bogdanovic']],
        ['cor'=>'yellow', 'label'=>'Bulls three-peat (1996–98)',
         'dica'=>'Fizeram parte do tricampeonato do Chicago Bulls entre 1996 e 1998',
         'jogadores'=>['Michael Jordan','Scottie Pippen','Dennis Rodman','Steve Kerr']],
        ['cor'=>'red',    'label'=>'MVPs internacionais (não-americanos)',
         'dica'=>'Venceram o MVP da temporada sendo nascidos fora dos EUA',
         'jogadores'=>['Giannis Antetokounmpo','Dirk Nowitzki','Steve Nash','Hakeem Olajuwon']],
        ['cor'=>'purple', 'label'=>'Artilheiros (Scoring Champion) múltiplos',
         'dica'=>'Venceram o título de artilheiro da temporada mais de uma vez',
         'jogadores'=>['Kobe Bryant','Allen Iverson','Kevin Durant','Russell Westbrook']],
    ],
    // 4
    [
        ['cor'=>'green',  'label'=>'Trio do OKC Thunder + 1',
         'dica'=>'Jogaram juntos no Oklahoma City Thunder na era 2009–2012',
         'jogadores'=>['Kevin Durant','Russell Westbrook','James Harden','Serge Ibaka']],
        ['cor'=>'yellow', 'label'=>'Lendas do San Antonio Spurs',
         'dica'=>'Jogadores que definiram a dinastia do San Antonio Spurs',
         'jogadores'=>['Tim Duncan','Tony Parker','Manu Ginobili','David Robinson']],
        ['cor'=>'red',    'label'=>'Calouros do Ano (2019–2022)',
         'dica'=>'Venceram o Rookie of the Year entre 2019 e 2022',
         'jogadores'=>['Luka Doncic','Zion Williamson','LaMelo Ball','Scottie Barnes']],
        ['cor'=>'purple', 'label'=>'Lendas dos Toronto Raptors',
         'dica'=>'Jogadores icônicos da história do Toronto Raptors',
         'jogadores'=>['Vince Carter','Chris Bosh','DeMar DeRozan','Kyle Lowry']],
    ],
    // 5
    [
        ['cor'=>'green',  'label'=>'Estrelas do Miami Heat',
         'dica'=>'Jogadores All-Star que definiram eras diferentes no Miami Heat',
         'jogadores'=>['Dwyane Wade','LeBron James','Jimmy Butler','Bam Adebayo']],
        ['cor'=>'yellow', 'label'=>'Celtics Big 3 era (2008–2013)',
         'dica'=>'Formaram o núcleo do Boston Celtics campeão de 2008',
         'jogadores'=>['Kevin Garnett','Paul Pierce','Ray Allen','Rajon Rondo']],
        ['cor'=>'red',    'label'=>'Pick #1 do Draft (2019–2022)',
         'dica'=>'Foram selecionados como primeira escolha geral do Draft entre 2019 e 2022',
         'jogadores'=>['Zion Williamson','Anthony Edwards','Cade Cunningham','Paolo Banchero']],
        ['cor'=>'purple', 'label'=>'Filhos de ex-jogadores da NBA',
         'dica'=>'Seus pais também jogaram na NBA',
         'jogadores'=>['Stephen Curry','Klay Thompson','Gary Payton II','Tim Hardaway Jr.']],
    ],
    // 6
    [
        ['cor'=>'green',  'label'=>'Denver Nuggets ícones',
         'dica'=>'Jogadores marcantes da história do Denver Nuggets',
         'jogadores'=>['Dikembe Mutombo','Carmelo Anthony','Nikola Jokic','Jamal Murray']],
        ['cor'=>'yellow', 'label'=>'Campeões Bucks 2021',
         'dica'=>'Venceram o título com Milwaukee Bucks em 2021',
         'jogadores'=>['Giannis Antetokounmpo','Khris Middleton','Jrue Holiday','Brook Lopez']],
        ['cor'=>'red',    'label'=>'Los Angeles Clippers (era Chris Paul / Blake Griffin)',
         'dica'=>'Jogaram no Los Angeles Clippers na era 2011–2019',
         'jogadores'=>['Chris Paul','Blake Griffin','DeAndre Jordan','Jamal Crawford']],
        ['cor'=>'purple', 'label'=>'New York Knicks recentes (2017–2024)',
         'dica'=>'Jogadores notáveis dos Knicks nos últimos anos',
         'jogadores'=>['Kristaps Porzingis','Julius Randle','RJ Barrett','Immanuel Quickley']],
    ],
    // 7
    [
        ['cor'=>'green',  'label'=>'Cavaliers campeões 2016',
         'dica'=>'Fizeram parte do Cleveland Cavaliers campeão de 2016',
         'jogadores'=>['LeBron James','Kyrie Irving','Kevin Love','J.R. Smith']],
        ['cor'=>'yellow', 'label'=>'Warriors campeões 2022',
         'dica'=>'Venceram o título com o Golden State Warriors em 2022',
         'jogadores'=>['Stephen Curry','Klay Thompson','Draymond Green','Andrew Wiggins']],
        ['cor'=>'red',    'label'=>'Ganharam MVP + DPOY + Título',
         'dica'=>'Conquistaram o MVP da temporada, o DPOY e um anel na carreira',
         'jogadores'=>['Michael Jordan','Tim Duncan','Giannis Antetokounmpo','Hakeem Olajuwon']],
        ['cor'=>'purple', 'label'=>'Grandes pontuadores sem anel',
         'dica'=>'Estão entre os maiores pontuadores da história mas nunca conquistaram um título',
         'jogadores'=>['Allen Iverson','Carmelo Anthony','Reggie Miller','Karl Malone']],
    ],
    // 8
    [
        ['cor'=>'green',  'label'=>'Argentinos na NBA',
         'dica'=>'Jogadores nascidos na Argentina',
         'jogadores'=>['Manu Ginobili','Luis Scola','Carlos Delfino','Andres Nocioni']],
        ['cor'=>'yellow', 'label'=>'Heat campeões 2006 (destaques)',
         'dica'=>'Jogaram no Miami Heat que venceu o título em 2006',
         'jogadores'=>['Dwyane Wade',"Shaquille O'Neal",'Gary Payton','Alonzo Mourning']],
        ['cor'=>'red',    'label'=>'Portland Trail Blazers lendas',
         'dica'=>'Jogadores icônicos da história do Portland Trail Blazers',
         'jogadores'=>['Clyde Drexler','Bill Walton','Damian Lillard','Brandon Roy']],
        ['cor'=>'purple', 'label'=>'Debate do GOAT',
         'dica'=>'Frequentemente citados nas discussões sobre o melhor de todos os tempos',
         'jogadores'=>['Wilt Chamberlain','Michael Jordan','LeBron James','Kareem Abdul-Jabbar']],
    ],
    // 9
    [
        ['cor'=>'green',  'label'=>'Africanos na NBA',
         'dica'=>'Jogadores nascidos no continente africano',
         'jogadores'=>['Hakeem Olajuwon','Dikembe Mutombo','Joel Embiid','Pascal Siakam']],
        ['cor'=>'yellow', 'label'=>'Nuggets campeões 2023',
         'dica'=>'Jogaram e venceram o título com o Denver Nuggets em 2023',
         'jogadores'=>['Nikola Jokic','Jamal Murray','Michael Porter Jr.','Aaron Gordon']],
        ['cor'=>'red',    'label'=>'Venceram título com 2+ times diferentes',
         'dica'=>'Conquistaram pelo menos um anel com dois times diferentes na carreira',
         'jogadores'=>['LeBron James','Kawhi Leonard',"Shaquille O'Neal",'Rajon Rondo']],
        ['cor'=>'purple', 'label'=>'MVP sem nunca ganhar o título (versão 2)',
         'dica'=>'Outros grandes MVPs que aposentaram sem anel',
         'jogadores'=>['Charles Barkley','Chris Paul','Giannis... no — Dirk Nowitzki',"didn't win? Actually he did — let me fix"]],
    ],
    // 10
    [
        ['cor'=>'green',  'label'=>'Armadores históricos',
         'dica'=>'Considerados os melhores armadores (PG) de todos os tempos',
         'jogadores'=>['Magic Johnson','John Stockton','Chris Paul','Isiah Thomas']],
        ['cor'=>'yellow', 'label'=>'Ala-pivôs históricos',
         'dica'=>'Considerados os melhores ala-pivôs (PF) de todos os tempos',
         'jogadores'=>['Giannis Antetokounmpo','Charles Barkley','Karl Malone','Kevin Garnett']],
        ['cor'=>'red',    'label'=>'Pivôs históricos',
         'dica'=>'Considerados os melhores pivôs (C) de todos os tempos',
         'jogadores'=>["Shaquille O'Neal",'Kareem Abdul-Jabbar','Wilt Chamberlain','Hakeem Olajuwon']],
        ['cor'=>'purple', 'label'=>'Alas-armadores históricos',
         'dica'=>'Considerados os melhores alas-armadores (SG) de todos os tempos',
         'jogadores'=>['Michael Jordan','Kobe Bryant','Allen Iverson','Dwyane Wade']],
    ],
];

// Fix puzzle 9 that had a placeholder error:
$PUZZLES[9] = [
    ['cor'=>'green',  'label'=>'Africanos na NBA',
     'dica'=>'Jogadores nascidos no continente africano',
     'jogadores'=>['Hakeem Olajuwon','Dikembe Mutombo','Joel Embiid','Pascal Siakam']],
    ['cor'=>'yellow', 'label'=>'Nuggets campeões 2023',
     'dica'=>'Venceram o título com o Denver Nuggets em 2023',
     'jogadores'=>['Nikola Jokic','Jamal Murray','Michael Porter Jr.','Aaron Gordon']],
    ['cor'=>'red',    'label'=>'Venceram título com 2+ times diferentes',
     'dica'=>'Conquistaram pelo menos um anel em dois times diferentes',
     'jogadores'=>['LeBron James','Kawhi Leonard',"Shaquille O'Neal",'Rajon Rondo']],
    ['cor'=>'purple', 'label'=>'Ganharam mais de 2 títulos da NBA',
     'dica'=>'Jogadores que conquistaram 3 ou mais anéis de campeão',
     'jogadores'=>['Bill Russell','Kareem Abdul-Jabbar','Robert Horry','Derek Fisher']],
];

// Seleciona puzzle do dia
$seed_day  = (int)floor(time() / 86400);
$puzzle_idx = $seed_day % count($PUZZLES);
$puzzle     = $PUZZLES[$puzzle_idx];

// Embaralha os 16 jogadores com seed do dia (mesmo para todos os usuários)
srand($seed_day);
$all_tiles = [];
foreach ($puzzle as $gi => $grupo) {
    foreach ($grupo['jogadores'] as $j) {
        $all_tiles[] = ['name' => $j, 'grupo_cor' => $grupo['cor'], 'grupo_idx' => $gi];
    }
}
shuffle($all_tiles);

// Mapa de respostas: nome_lower -> grupo_idx
$answer_map = [];
foreach ($puzzle as $gi => $grupo) {
    foreach ($grupo['jogadores'] as $j) {
        $answer_map[mb_strtolower($j)] = $gi;
    }
}

// Grupo idx -> meta
$grupo_meta = [];
foreach ($puzzle as $gi => $grupo) {
    $grupo_meta[$gi] = ['cor' => $grupo['cor'], 'label' => $grupo['label'], 'dica' => $grupo['dica']];
}

// ── AJAX ─────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    header('Content-Type: application/json');
    if ($action === 'save') {
        $grupos_enc  = json_encode(json_decode($_POST['grupos_encontrados'] ?? '[]', true) ?: []);
        $vidas       = (int)($_POST['vidas_restantes'] ?? 4);
        $concluido   = (int)($_POST['concluido'] ?? 0);
        $desistiu    = (int)($_POST['desistiu'] ?? 0);
        $pontos      = (int)($_POST['pontos'] ?? 0);
        $hoje        = date('Y-m-d');
        try {
            $stmt = $pdo->prepare("SELECT id, pontos_ganhos FROM conexoes_historico WHERE id_usuario=? AND data_jogo=?");
            $stmt->execute([$user_id, $hoje]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $pdo->prepare("UPDATE conexoes_historico SET grupos_encontrados=?,vidas_restantes=?,concluido=?,desistiu=?,pontos_ganhos=? WHERE id=?")
                    ->execute([$grupos_enc,$vidas,$concluido,$desistiu,$pontos,$row['id']]);
                if ($concluido && $row['pontos_ganhos'] == 0 && $pontos > 0)
                    $pdo->prepare("UPDATE usuarios SET pontos=pontos+? WHERE id=?")->execute([$pontos,$user_id]);
            } else {
                $pdo->prepare("INSERT INTO conexoes_historico (id_usuario,data_jogo,puzzle_idx,grupos_encontrados,vidas_restantes,concluido,desistiu,pontos_ganhos) VALUES(?,?,?,?,?,?,?,?)")
                    ->execute([$user_id,$hoje,$puzzle_idx,$grupos_enc,$vidas,$concluido,$desistiu,$pontos]);
                if ($concluido && $pontos > 0)
                    $pdo->prepare("UPDATE usuarios SET pontos=pontos+? WHERE id=?")->execute([$pontos,$user_id]);
            }
            echo json_encode(['ok'=>true]);
        } catch (PDOException $e) { echo json_encode(['ok'=>false]); }
        exit;
    }
    echo json_encode(['ok'=>false]); exit;
}

// Carrega estado salvo
$hoje      = date('Y-m-d');
$dadosHoje = null;
try {
    $stmt = $pdo->prepare("SELECT * FROM conexoes_historico WHERE id_usuario=? AND data_jogo=?");
    $stmt->execute([$user_id, $hoje]);
    $dadosHoje = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

$gruposEncontradosIniciais = $dadosHoje ? (json_decode($dadosHoje['grupos_encontrados'], true) ?: []) : [];
$vidasIniciais             = $dadosHoje ? (int)$dadosHoje['vidas_restantes'] : 4;
$jaTerminou                = $dadosHoje && ($dadosHoje['concluido'] || $dadosHoje['desistiu']);

// Cores CSS
$COR_CSS = [
    'green'  => ['bg'=>'#16a34a','border'=>'#22c55e','text'=>'#fff'],
    'yellow' => ['bg'=>'#d97706','border'=>'#f59e0b','text'=>'#fff'],
    'red'    => ['bg'=>'#dc2626','border'=>'#f87171','text'=>'#fff'],
    'purple' => ['bg'=>'#7c3aed','border'=>'#a78bfa','text'=>'#fff'],
];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no">
<title>Conexões NBA</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700;800&display=swap" rel="stylesheet">
<style>
:root{
  --bg:#07070a;--panel:#101013;--panel2:#16161a;--panel3:#1c1c21;
  --border:rgba(255,255,255,.07);--border2:rgba(255,255,255,.14);
  --red:#fc0025;--red-soft:rgba(252,0,37,.12);--red-glow:rgba(252,0,37,.22);
  --text:#f0f0f3;--text2:#868690;--text3:#3c3c44;
  --font:'Poppins',sans-serif;--radius:12px;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:var(--font);background:var(--bg);color:var(--text);min-height:100vh;-webkit-font-smoothing:antialiased}

/* TOPBAR */
.topbar{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:10px 14px;background:var(--panel);border-bottom:1px solid var(--border);position:sticky;top:0;z-index:50}
.back-btn{display:flex;align-items:center;justify-content:center;width:32px;height:32px;border-radius:9px;border:1px solid var(--border);background:transparent;color:var(--text2);text-decoration:none;font-size:14px;transition:.2s;flex-shrink:0}
.back-btn:hover{border-color:var(--red);color:var(--red);background:var(--red-soft)}
.game-title{font-size:15px;font-weight:800}
.game-title span{color:var(--red)}
.daily-badge{display:inline-flex;align-items:center;gap:4px;font-size:8px;font-weight:700;letter-spacing:.8px;text-transform:uppercase;padding:2px 8px;border-radius:999px;background:var(--red-soft);border:1px solid var(--red-glow);color:var(--red);margin-left:6px}
.topbar-right{display:flex;align-items:center;gap:8px}
.lives-wrap{display:flex;align-items:center;gap:4px}
.heart{font-size:16px;transition:transform .2s}
.heart.lost{filter:grayscale(1);opacity:.3;transform:scale(.85)}
.score-chip{display:flex;align-items:center;gap:4px;padding:4px 10px;border-radius:20px;background:var(--panel2);border:1px solid var(--border);font-size:11px;font-weight:700;color:#f59e0b}

/* MAIN */
.main{max-width:540px;margin:0 auto;padding:14px 12px 60px}

/* FOUND GROUPS */
.found-groups{display:flex;flex-direction:column;gap:6px;margin-bottom:10px}
.found-group{border-radius:12px;padding:14px 16px;display:flex;flex-direction:column;gap:4px;animation:popIn .3s ease}
.found-group-label{font-size:13px;font-weight:800;color:#fff}
.found-group-players{font-size:11px;font-weight:600;color:rgba(255,255,255,.75)}
@keyframes popIn{from{opacity:0;transform:scale(.95)}to{opacity:1;transform:scale(1)}}

/* GRID */
.tile-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:6px;margin-bottom:12px}
.tile{background:var(--panel2);border:2px solid var(--border);border-radius:10px;padding:10px 6px;text-align:center;cursor:pointer;transition:all .18s;min-height:64px;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;color:var(--text);line-height:1.3;user-select:none;-webkit-tap-highlight-color:transparent;position:relative}
.tile:hover:not(.selected):not(.found-tile){background:var(--panel3);border-color:var(--border2)}
.tile.selected{background:rgba(252,0,37,.12);border-color:rgba(252,0,37,.5);color:var(--red)}
.tile.found-tile{opacity:0;pointer-events:none;min-height:0;padding:0;border:none;height:0}
.tile.shake{animation:shake .45s ease}
.tile.bounce{animation:bounce .35s ease}
@keyframes shake{0%,100%{transform:translateX(0)}20%{transform:translateX(-6px)}40%{transform:translateX(6px)}60%{transform:translateX(-4px)}80%{transform:translateX(4px)}}
@keyframes bounce{0%,100%{transform:scale(1)}50%{transform:scale(1.08)}}

/* ACTION BAR */
.action-bar{display:flex;gap:8px;margin-bottom:12px}
.btn-action{flex:1;padding:11px 8px;border-radius:10px;font-family:var(--font);font-size:13px;font-weight:700;border:1px solid var(--border);background:var(--panel2);color:var(--text2);cursor:pointer;transition:all .18s;display:flex;align-items:center;justify-content:center;gap:6px}
.btn-action:hover{border-color:var(--border2);color:var(--text)}
.btn-submit{background:var(--red);color:#fff;border-color:var(--red);opacity:.4;pointer-events:none;flex:2}
.btn-submit.ready{opacity:1;pointer-events:auto}
.btn-submit.ready:hover{opacity:.85}

/* ONE-AWAY TOAST */
.toast-msg{position:fixed;bottom:80px;left:50%;transform:translateX(-50%);background:var(--panel3);border:1px solid var(--border2);border-radius:10px;padding:10px 18px;font-size:12px;font-weight:700;color:var(--text);z-index:100;white-space:nowrap;animation:toastIn .25s ease;pointer-events:none}
.toast-msg.hide{animation:toastOut .25s ease forwards}
@keyframes toastIn{from{opacity:0;transform:translateX(-50%) translateY(10px)}to{opacity:1;transform:translateX(-50%) translateY(0)}}
@keyframes toastOut{to{opacity:0;transform:translateX(-50%) translateY(8px)}}

/* RESULT OVERLAY */
.result-overlay{position:fixed;inset:0;background:rgba(0,0,0,.85);backdrop-filter:blur(8px);z-index:300;display:flex;align-items:center;justify-content:center;padding:20px}
.result-overlay.hidden{display:none}
.result-card{background:var(--panel);border:1px solid var(--border2);border-radius:22px;padding:28px 22px;max-width:420px;width:100%;text-align:center;box-shadow:0 24px 80px rgba(0,0,0,.7)}
.result-icon{font-size:3rem;margin-bottom:10px;display:block}
.result-title{font-size:20px;font-weight:800;margin-bottom:6px}
.result-subtitle{font-size:13px;color:var(--text2);line-height:1.6;margin-bottom:18px}
.result-groups{display:flex;flex-direction:column;gap:6px;margin-bottom:18px;text-align:left}
.result-group-row{border-radius:10px;padding:10px 14px;display:flex;align-items:center;gap:10px}
.result-group-dot{width:10px;height:10px;border-radius:50%;flex-shrink:0}
.result-group-info{}
.result-group-lbl{font-size:12px;font-weight:800;color:#fff}
.result-group-pls{font-size:10px;font-weight:600;color:rgba(255,255,255,.7)}
.result-stats{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:18px}
.result-stat{background:var(--panel2);border:1px solid var(--border);border-radius:10px;padding:12px}
.result-stat-val{font-size:20px;font-weight:800}
.result-stat-lbl{font-size:9px;color:var(--text2);font-weight:600;letter-spacing:.4px;text-transform:uppercase;margin-top:2px}
.btn-red{display:flex;align-items:center;justify-content:center;gap:8px;width:100%;padding:12px;border-radius:12px;background:var(--red);color:#fff;border:none;font-family:var(--font);font-size:14px;font-weight:700;cursor:pointer;transition:opacity .2s}
.btn-red:hover{opacity:.85}

/* Dica */
.hint-row{text-align:center;font-size:11px;color:var(--text3);margin-bottom:10px}
.hint-row b{color:var(--text2)}
</style>
</head>
<body>

<div class="topbar">
  <div style="display:flex;align-items:center;gap:10px">
    <a href="../games.php" class="back-btn"><i class="bi bi-arrow-left"></i></a>
    <span class="game-title">Cone<span>xões</span> NBA<span class="daily-badge"><i class="bi bi-calendar3"></i>Diário</span></span>
  </div>
  <div class="topbar-right">
    <div class="lives-wrap" id="livesWrap">
      <span class="heart" id="h3">❤️</span>
      <span class="heart" id="h2">❤️</span>
      <span class="heart" id="h1">❤️</span>
      <span class="heart" id="h0">❤️</span>
    </div>
    <div class="score-chip"><i class="bi bi-coin"></i><span id="scoreVal">0</span></div>
  </div>
</div>

<!-- Result overlay -->
<div class="result-overlay hidden" id="resultOverlay">
  <div class="result-card">
    <span class="result-icon" id="rIcon">🏆</span>
    <div class="result-title" id="rTitle"></div>
    <div class="result-subtitle" id="rSubtitle"></div>
    <div class="result-groups" id="rGroups"></div>
    <div class="result-stats">
      <div class="result-stat"><div class="result-stat-val" id="rGrups">0/4</div><div class="result-stat-lbl">Grupos</div></div>
      <div class="result-stat"><div class="result-stat-val" id="rCoins" style="color:#f59e0b">0</div><div class="result-stat-lbl">Moedas</div></div>
    </div>
    <button class="btn-red" onclick="document.getElementById('resultOverlay').classList.add('hidden')">
      <i class="bi bi-check-lg"></i>Ver resultado
    </button>
  </div>
</div>

<div class="main">
  <div class="hint-row">Selecione <b>4 jogadores</b> que pertencem ao mesmo grupo</div>

  <!-- Found groups (populated by JS) -->
  <div class="found-groups" id="foundGroups"></div>

  <!-- Tile grid -->
  <div class="tile-grid" id="tileGrid"></div>

  <!-- Action bar -->
  <div class="action-bar">
    <button class="btn-action" id="btnShuffle" onclick="shuffleTiles()"><i class="bi bi-shuffle"></i>Embaralhar</button>
    <button class="btn-action" id="btnDeselect" onclick="clearSelection()"><i class="bi bi-x-lg"></i>Limpar</button>
    <button class="btn-action btn-submit" id="btnSubmit" onclick="submitGuess()"><i class="bi bi-check-lg"></i>Verificar</button>
  </div>
</div>

<script>
// ── DADOS ────────────────────────────────────────────────────────────────────
const GRUPOS = <?= json_encode(array_values($puzzle)) ?>;
const ALL_TILES_ORDERED = <?= json_encode(array_map(fn($t) => $t['name'], $all_tiles)) ?>;
const COR_CSS = <?= json_encode($COR_CSS) ?>;
const PONTOS_BASE = <?= (int)$PONTOS_VITORIA ?>;
const JA_TERMINOU = <?= $jaTerminou ? 'true' : 'false' ?>;

// ── STATE ────────────────────────────────────────────────────────────────────
let tiles = [...ALL_TILES_ORDERED]; // current order
let selected = new Set(); // tile names currently selected
let foundIdxs = new Set(<?= json_encode($gruposEncontradosIniciais) ?>); // grupo indices already found
let lives = <?= $vidasIniciais ?>;
let gameOver = JA_TERMINOU;

// ── RENDER ───────────────────────────────────────────────────────────────────
function render() {
  const grid = document.getElementById('tileGrid');
  grid.innerHTML = '';

  tiles.forEach(name => {
    // find group idx for this tile
    const gi = GRUPOS.findIndex(g => g.jogadores.map(j => j.toLowerCase()).includes(name.toLowerCase()));
    const isFnd = foundIdxs.has(gi);
    const isSel = selected.has(name);

    const div = document.createElement('div');
    div.className = 'tile' + (isFnd ? ' found-tile' : '') + (isSel ? ' selected' : '');
    div.textContent = name;
    div.dataset.name = name;
    if (!isFnd && !gameOver) div.addEventListener('click', () => toggleTile(name));
    grid.appendChild(div);
  });

  renderFoundGroups();
  renderLives();
  updateActionBar();
  document.getElementById('scoreVal').textContent = calcScore();
}

function renderFoundGroups() {
  const box = document.getElementById('foundGroups');
  box.innerHTML = '';
  GRUPOS.forEach((g, gi) => {
    if (!foundIdxs.has(gi)) return;
    const css = COR_CSS[g.cor];
    const div = document.createElement('div');
    div.className = 'found-group';
    div.style.background = css.bg;
    div.style.borderColor = css.border;
    div.style.border = `1px solid ${css.border}`;
    div.innerHTML = `<div class="found-group-label">${g.label}</div><div class="found-group-players">${g.jogadores.join(' · ')}</div>`;
    box.appendChild(div);
  });
}

function renderLives() {
  for (let i = 0; i < 4; i++) {
    const el = document.getElementById('h' + i);
    if (!el) continue;
    el.classList.toggle('lost', i >= lives);
  }
}

function updateActionBar() {
  const btn = document.getElementById('btnSubmit');
  if (selected.size === 4) btn.classList.add('ready');
  else btn.classList.remove('ready');
}

function calcScore() {
  const found = foundIdxs.size;
  if (found === 0) return 0;
  const base = Math.round(PONTOS_BASE * found / 4);
  const lifeBonus = lives * 8;
  return base + (found === 4 ? lifeBonus : 0);
}

// ── INTERACTION ──────────────────────────────────────────────────────────────
function toggleTile(name) {
  if (gameOver) return;
  if (selected.has(name)) {
    selected.delete(name);
  } else {
    if (selected.size >= 4) return;
    selected.add(name);
  }
  render();
}

function clearSelection() {
  selected.clear();
  render();
}

function shuffleTiles() {
  // Shuffle only unfound tiles, keep found at end (they're hidden anyway)
  const unfound = tiles.filter(n => {
    const gi = GRUPOS.findIndex(g => g.jogadores.map(j => j.toLowerCase()).includes(n.toLowerCase()));
    return !foundIdxs.has(gi);
  });
  for (let i = unfound.length - 1; i > 0; i--) {
    const j = Math.floor(Math.random() * (i + 1));
    [unfound[i], unfound[j]] = [unfound[j], unfound[i]];
  }
  // Rebuild tiles array: unfound first, found at end
  const found = tiles.filter(n => {
    const gi = GRUPOS.findIndex(g => g.jogadores.map(j => j.toLowerCase()).includes(n.toLowerCase()));
    return foundIdxs.has(gi);
  });
  tiles = [...unfound, ...found];
  render();
}

function submitGuess() {
  if (selected.size !== 4 || gameOver) return;

  const selArr = [...selected];

  // Count how many in each group
  const counts = new Array(GRUPOS.length).fill(0);
  selArr.forEach(name => {
    const gi = GRUPOS.findIndex(g => g.jogadores.map(j => j.toLowerCase()).includes(name.toLowerCase()));
    if (gi >= 0) counts[gi]++;
  });

  // Find if any group has exactly 4
  const correctGi = counts.findIndex(c => c === 4);
  if (correctGi >= 0 && !foundIdxs.has(correctGi)) {
    // Correct!
    selArr.forEach(name => {
      const el = document.querySelector(`.tile[data-name="${CSS.escape(name)}"]`);
      if (el) el.classList.add('bounce');
    });
    setTimeout(() => {
      foundIdxs.add(correctGi);
      selected.clear();
      saveState();
      render();

      if (foundIdxs.size === 4) {
        gameOver = true;
        setTimeout(() => showResult(true), 500);
        saveState(true, false);
      }
    }, 350);
  } else {
    // Wrong
    lives = Math.max(0, lives - 1);

    // Shake animation
    selArr.forEach(name => {
      const el = document.querySelector(`.tile[data-name="${CSS.escape(name)}"]`);
      if (el) { el.classList.add('shake'); setTimeout(() => el.classList.remove('shake'), 450); }
    });

    // One-away check
    const maxCount = Math.max(...counts);
    if (maxCount === 3) showToast('Quase lá! Um jogador a mais 🔥');
    else showToast('Combinação incorreta ✗');

    renderLives();
    document.getElementById('scoreVal').textContent = calcScore();
    selected.clear();
    updateActionBar();
    saveState();

    if (lives === 0) {
      gameOver = true;
      setTimeout(() => showResult(false), 600);
      saveState(false, true);
    }
  }
}

function showResult(won) {
  const found = foundIdxs.size;
  document.getElementById('rIcon').textContent = won ? '🏆' : found >= 3 ? '🏅' : found >= 2 ? '🙁' : '😔';
  document.getElementById('rTitle').textContent = won ? 'Parabéns!' : found >= 3 ? 'Quase!' : 'Fim de jogo';
  document.getElementById('rSubtitle').textContent = won
    ? `Você encontrou todos os 4 grupos! (${4 - lives} erro${4-lives===1?'':'s'})`
    : `Você encontrou ${found} de 4 grupos.`;

  // Groups
  let html = '';
  GRUPOS.forEach((g, gi) => {
    const css = COR_CSS[g.cor];
    const fnd = foundIdxs.has(gi);
    html += `<div class="result-group-row" style="background:${css.bg}20;border:1px solid ${css.bg}44">
      <div class="result-group-dot" style="background:${css.bg}"></div>
      <div class="result-group-info">
        <div class="result-group-lbl" style="color:${fnd ? '#fff' : 'rgba(255,255,255,.4)'}">${fnd ? '✓' : '✗'} ${g.label}</div>
        <div class="result-group-pls" style="color:${fnd ? 'rgba(255,255,255,.7)' : 'rgba(255,255,255,.3)'}">${g.jogadores.join(' · ')}</div>
      </div>
    </div>`;
  });
  document.getElementById('rGroups').innerHTML = html;
  document.getElementById('rGrups').textContent = found + '/4';
  document.getElementById('rCoins').textContent = calcScore();
  document.getElementById('resultOverlay').classList.remove('hidden');
}

// ── TOAST ────────────────────────────────────────────────────────────────────
let toastTimer = null;
function showToast(msg) {
  let el = document.getElementById('toastEl');
  if (el) el.remove();
  el = document.createElement('div');
  el.id = 'toastEl';
  el.className = 'toast-msg';
  el.textContent = msg;
  document.body.appendChild(el);
  if (toastTimer) clearTimeout(toastTimer);
  toastTimer = setTimeout(() => {
    el.classList.add('hide');
    setTimeout(() => el.remove(), 250);
  }, 2000);
}

// ── SAVE ─────────────────────────────────────────────────────────────────────
function saveState(concluido = false, desistiu = false) {
  const body = new FormData();
  body.append('action', 'save');
  body.append('grupos_encontrados', JSON.stringify([...foundIdxs]));
  body.append('vidas_restantes', lives);
  body.append('concluido', concluido ? 1 : 0);
  body.append('desistiu', desistiu ? 1 : 0);
  body.append('pontos', calcScore());
  fetch('', { method: 'POST', body });
}

// ── INIT ─────────────────────────────────────────────────────────────────────
render();
if (JA_TERMINOU) {
  const won = foundIdxs.size === 4;
  setTimeout(() => showResult(won), 400);
}
setInterval(() => { if (!gameOver) saveState(); }, 60000);
</script>
</body>
</html>
