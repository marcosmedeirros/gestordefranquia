<?php
session_start();
require '../core/conexao.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit;
}

$user_id        = (int)$_SESSION['user_id'];
$hoje           = date('Y-m-d');
$MAX_TENTATIVAS = 8;

// n=nome, nba_id=ID NBA.com para foto (cdn.nba.com/headshots/nba/latest/1040x760/{id}.png)
// t=time(sigla), conf=EAST|WEST, pos=PG|SG|SF|PF|C, age=idade, ppg=média pontos carreira
$QSB_PLAYERS = [
    // ── Ativos ──────────────────────────────────────────────────────────────────
    ['id'=>1,  'n'=>'LeBron James',            'nba_id'=>2544,    't'=>'LAL','conf'=>'WEST','pos'=>'SF','age'=>40,'ppg'=>27.2],
    ['id'=>2,  'n'=>'Stephen Curry',           'nba_id'=>201939,  't'=>'GSW','conf'=>'WEST','pos'=>'PG','age'=>37,'ppg'=>24.8],
    ['id'=>3,  'n'=>'Kevin Durant',            'nba_id'=>201142,  't'=>'PHX','conf'=>'WEST','pos'=>'SF','age'=>36,'ppg'=>27.3],
    ['id'=>4,  'n'=>'Giannis Antetokounmpo',   'nba_id'=>203507,  't'=>'MIL','conf'=>'EAST','pos'=>'PF','age'=>30,'ppg'=>22.9],
    ['id'=>5,  'n'=>'Nikola Jokic',            'nba_id'=>203999,  't'=>'DEN','conf'=>'WEST','pos'=>'C', 'age'=>30,'ppg'=>20.3],
    ['id'=>6,  'n'=>'Luka Doncic',             'nba_id'=>1629029, 't'=>'LAL','conf'=>'WEST','pos'=>'PG','age'=>25,'ppg'=>28.7],
    ['id'=>7,  'n'=>'Joel Embiid',             'nba_id'=>203954,  't'=>'PHI','conf'=>'EAST','pos'=>'C', 'age'=>30,'ppg'=>27.6],
    ['id'=>8,  'n'=>'Jayson Tatum',            'nba_id'=>1628369, 't'=>'BOS','conf'=>'EAST','pos'=>'SF','age'=>26,'ppg'=>23.6],
    ['id'=>9,  'n'=>'Shai Gilgeous-Alexander', 'nba_id'=>1628983, 't'=>'OKC','conf'=>'WEST','pos'=>'SG','age'=>26,'ppg'=>23.7],
    ['id'=>10, 'n'=>'Anthony Davis',           'nba_id'=>203076,  't'=>'LAL','conf'=>'WEST','pos'=>'PF','age'=>31,'ppg'=>23.6],
    ['id'=>11, 'n'=>'Kawhi Leonard',           'nba_id'=>202695,  't'=>'LAC','conf'=>'WEST','pos'=>'SF','age'=>33,'ppg'=>19.8],
    ['id'=>12, 'n'=>'Jimmy Butler',            'nba_id'=>202710,  't'=>'GSW','conf'=>'WEST','pos'=>'SF','age'=>35,'ppg'=>19.7],
    ['id'=>13, 'n'=>'Damian Lillard',          'nba_id'=>203081,  't'=>'MIL','conf'=>'EAST','pos'=>'PG','age'=>34,'ppg'=>24.7],
    ['id'=>14, 'n'=>'Kyrie Irving',            'nba_id'=>202681,  't'=>'DAL','conf'=>'WEST','pos'=>'PG','age'=>32,'ppg'=>22.8],
    ['id'=>15, 'n'=>'James Harden',            'nba_id'=>201935,  't'=>'LAC','conf'=>'WEST','pos'=>'PG','age'=>35,'ppg'=>24.2],
    ['id'=>16, 'n'=>'Devin Booker',            'nba_id'=>1626164, 't'=>'PHX','conf'=>'WEST','pos'=>'SG','age'=>28,'ppg'=>23.0],
    ['id'=>17, 'n'=>'Trae Young',              'nba_id'=>1629027, 't'=>'ATL','conf'=>'EAST','pos'=>'PG','age'=>26,'ppg'=>25.3],
    ['id'=>18, 'n'=>'Zion Williamson',         'nba_id'=>1629627, 't'=>'NOP','conf'=>'WEST','pos'=>'PF','age'=>24,'ppg'=>23.5],
    ['id'=>19, 'n'=>'Donovan Mitchell',        'nba_id'=>1628378, 't'=>'CLE','conf'=>'EAST','pos'=>'SG','age'=>28,'ppg'=>24.7],
    ['id'=>20, 'n'=>'Bam Adebayo',             'nba_id'=>1628389, 't'=>'MIA','conf'=>'EAST','pos'=>'C', 'age'=>27,'ppg'=>18.4],
    ['id'=>21, 'n'=>'Ja Morant',               'nba_id'=>1629630, 't'=>'MEM','conf'=>'WEST','pos'=>'PG','age'=>25,'ppg'=>22.5],
    ['id'=>22, 'n'=>'Karl-Anthony Towns',      'nba_id'=>1626157, 't'=>'NYK','conf'=>'EAST','pos'=>'C', 'age'=>29,'ppg'=>22.3],
    ['id'=>23, 'n'=>'Jaylen Brown',            'nba_id'=>1627759, 't'=>'BOS','conf'=>'EAST','pos'=>'SG','age'=>28,'ppg'=>19.6],
    ['id'=>24, 'n'=>'Tyrese Haliburton',       'nba_id'=>1630169, 't'=>'IND','conf'=>'EAST','pos'=>'PG','age'=>24,'ppg'=>19.2],
    ['id'=>25, 'n'=>'Anthony Edwards',         'nba_id'=>1630162, 't'=>'MIN','conf'=>'WEST','pos'=>'SG','age'=>23,'ppg'=>22.0],
    ['id'=>26, 'n'=>'Victor Wembanyama',       'nba_id'=>1641705, 't'=>'SAS','conf'=>'WEST','pos'=>'C', 'age'=>21,'ppg'=>21.4],
    ['id'=>27, 'n'=>'Paolo Banchero',          'nba_id'=>1631094, 't'=>'ORL','conf'=>'EAST','pos'=>'PF','age'=>22,'ppg'=>20.4],
    ['id'=>28, 'n'=>'Evan Mobley',             'nba_id'=>1630596, 't'=>'CLE','conf'=>'EAST','pos'=>'PF','age'=>23,'ppg'=>15.5],
    ['id'=>29, 'n'=>'Chet Holmgren',           'nba_id'=>1631096, 't'=>'OKC','conf'=>'WEST','pos'=>'C', 'age'=>23,'ppg'=>16.5],
    ['id'=>30, 'n'=>"De'Aaron Fox",            'nba_id'=>1628368, 't'=>'SAS','conf'=>'WEST','pos'=>'PG','age'=>27,'ppg'=>21.1],
    ['id'=>31, 'n'=>'Alperen Sengun',          'nba_id'=>1630578, 't'=>'HOU','conf'=>'WEST','pos'=>'C', 'age'=>22,'ppg'=>16.4],
    ['id'=>32, 'n'=>'Franz Wagner',            'nba_id'=>1630532, 't'=>'ORL','conf'=>'EAST','pos'=>'SF','age'=>23,'ppg'=>19.7],
    ['id'=>33, 'n'=>'Scottie Barnes',          'nba_id'=>1630567, 't'=>'TOR','conf'=>'EAST','pos'=>'PF','age'=>23,'ppg'=>18.0],
    ['id'=>34, 'n'=>'Tyrese Maxey',            'nba_id'=>1630178, 't'=>'PHI','conf'=>'EAST','pos'=>'PG','age'=>24,'ppg'=>18.0],
    ['id'=>35, 'n'=>'Domantas Sabonis',        'nba_id'=>1627734, 't'=>'SAC','conf'=>'WEST','pos'=>'C', 'age'=>28,'ppg'=>16.6],
    // ── Lendas ──────────────────────────────────────────────────────────────────
    ['id'=>40, 'n'=>'Kobe Bryant',             'nba_id'=>977,     't'=>'LAL','conf'=>'WEST','pos'=>'SG','age'=>33,'ppg'=>25.0],
    ['id'=>41, 'n'=>'Tim Duncan',              'nba_id'=>1495,    't'=>'SAS','conf'=>'WEST','pos'=>'PF','age'=>36,'ppg'=>19.0],
    ['id'=>42, 'n'=>'Dirk Nowitzki',           'nba_id'=>1717,    't'=>'DAL','conf'=>'WEST','pos'=>'PF','age'=>37,'ppg'=>20.7],
    ['id'=>43, 'n'=>"Shaquille O'Neal",        'nba_id'=>406,     't'=>'LAL','conf'=>'WEST','pos'=>'C', 'age'=>31,'ppg'=>23.7],
    ['id'=>44, 'n'=>'Allen Iverson',           'nba_id'=>947,     't'=>'PHI','conf'=>'EAST','pos'=>'SG','age'=>31,'ppg'=>26.7],
    ['id'=>45, 'n'=>'Dwyane Wade',             'nba_id'=>2548,    't'=>'MIA','conf'=>'EAST','pos'=>'SG','age'=>27,'ppg'=>22.0],
    ['id'=>46, 'n'=>'Kevin Garnett',           'nba_id'=>708,     't'=>'BOS','conf'=>'EAST','pos'=>'PF','age'=>32,'ppg'=>17.8],
    ['id'=>47, 'n'=>'Russell Westbrook',       'nba_id'=>201566,  't'=>'OKC','conf'=>'WEST','pos'=>'PG','age'=>23,'ppg'=>23.2],
    ['id'=>48, 'n'=>'Chris Paul',              'nba_id'=>101108,  't'=>'PHX','conf'=>'WEST','pos'=>'PG','age'=>39,'ppg'=>17.3],
    ['id'=>49, 'n'=>'Carmelo Anthony',         'nba_id'=>2546,    't'=>'NYK','conf'=>'EAST','pos'=>'SF','age'=>28,'ppg'=>22.8],
    ['id'=>50, 'n'=>'Vince Carter',            'nba_id'=>101202,  't'=>'TOR','conf'=>'EAST','pos'=>'SG','age'=>23,'ppg'=>16.7],
    ['id'=>51, 'n'=>'Tracy McGrady',           'nba_id'=>1503,    't'=>'ORL','conf'=>'EAST','pos'=>'SF','age'=>24,'ppg'=>19.6],
    ['id'=>52, 'n'=>'Dwight Howard',           'nba_id'=>2730,    't'=>'ORL','conf'=>'EAST','pos'=>'C', 'age'=>28,'ppg'=>16.9],
    ['id'=>53, 'n'=>'Steve Nash',              'nba_id'=>959,     't'=>'PHX','conf'=>'WEST','pos'=>'PG','age'=>38,'ppg'=>14.3],
    ['id'=>54, 'n'=>'Ray Allen',               'nba_id'=>951,     't'=>'MIA','conf'=>'EAST','pos'=>'SG','age'=>36,'ppg'=>18.9],
    ['id'=>55, 'n'=>'Paul Pierce',             'nba_id'=>1718,    't'=>'BOS','conf'=>'EAST','pos'=>'SF','age'=>31,'ppg'=>19.7],
    ['id'=>56, 'n'=>'Michael Jordan',          'nba_id'=>893,     't'=>'CHI','conf'=>'EAST','pos'=>'SG','age'=>31,'ppg'=>30.1],
    ['id'=>57, 'n'=>'Yao Ming',                'nba_id'=>2397,    't'=>'HOU','conf'=>'WEST','pos'=>'C', 'age'=>28,'ppg'=>19.0],
    ['id'=>58, 'n'=>'Manu Ginobili',           'nba_id'=>1938,    't'=>'SAS','conf'=>'WEST','pos'=>'SG','age'=>35,'ppg'=>13.3],
    ['id'=>59, 'n'=>'Paul George',             'nba_id'=>202331,  't'=>'PHI','conf'=>'EAST','pos'=>'SF','age'=>34,'ppg'=>20.5],
    ['id'=>60, 'n'=>'Klay Thompson',           'nba_id'=>202691,  't'=>'DAL','conf'=>'WEST','pos'=>'SG','age'=>34,'ppg'=>19.7],
    ['id'=>61, 'n'=>'Jamal Murray',            'nba_id'=>1627750, 't'=>'DEN','conf'=>'WEST','pos'=>'PG','age'=>27,'ppg'=>18.5],
    ['id'=>62, 'n'=>'Rudy Gobert',             'nba_id'=>203497,  't'=>'MIN','conf'=>'WEST','pos'=>'C', 'age'=>32,'ppg'=>12.3],
    ['id'=>63, 'n'=>'Brandon Ingram',          'nba_id'=>1627742, 't'=>'NOP','conf'=>'WEST','pos'=>'SF','age'=>27,'ppg'=>20.4],
    ['id'=>64, 'n'=>'Draymond Green',          'nba_id'=>203110,  't'=>'GSW','conf'=>'WEST','pos'=>'PF','age'=>34,'ppg'=>8.5],
    ['id'=>65, 'n'=>'Tyler Herro',             'nba_id'=>1629625, 't'=>'MIA','conf'=>'EAST','pos'=>'SG','age'=>24,'ppg'=>18.9],
];

$playerById = [];
foreach ($QSB_PLAYERS as $p) { $playerById[$p['id']] = $p; }

function qsb_cmp($guessed, $target): string {
    if ($guessed === $target) return 'correct';
    if (is_int($guessed) || is_float($guessed)) return $target > $guessed ? 'up' : 'down';
    return 'wrong';
}

$dayHash      = abs(crc32($hoje . 'qsb_basquete'));
$targetPlayer = $QSB_PLAYERS[$dayHash % count($QSB_PLAYERS)];

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS quemsoueu_basquete (
        id            INT AUTO_INCREMENT PRIMARY KEY,
        id_usuario    INT NOT NULL,
        data_jogo     DATE NOT NULL,
        tentativas    INT DEFAULT 0,
        resolvido     TINYINT DEFAULT 0,
        pontos_ganhos INT DEFAULT 0,
        tentativas_json TEXT DEFAULT '[]',
        jogador_id    INT NOT NULL,
        concluido_em  DATETIME DEFAULT NULL,
        UNIQUE KEY uk_qsb_user_date (id_usuario, data_jogo)
    ) DEFAULT CHARSET=utf8mb4");
} catch (PDOException $e) {}

$partida = null;
try {
    $stmt = $pdo->prepare("SELECT * FROM quemsoueu_basquete WHERE id_usuario = ? AND data_jogo = ?");
    $stmt->execute([$user_id, $hoje]);
    $partida = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$partida) {
        $pdo->prepare("INSERT INTO quemsoueu_basquete (id_usuario, data_jogo, jogador_id, tentativas_json) VALUES (?,?,?,'[]')")
            ->execute([$user_id, $hoje, $targetPlayer['id']]);
        $partida = ['tentativas'=>0,'resolvido'=>0,'pontos_ganhos'=>0,'tentativas_json'=>'[]','concluido_em'=>null];
    }
} catch (PDOException $e) {
    $partida = ['tentativas'=>0,'resolvido'=>0,'pontos_ganhos'=>0,'tentativas_json'=>'[]','concluido_em'=>null];
}

$tentativas     = json_decode($partida['tentativas_json'] ?: '[]', true) ?: [];
$jogo_resolvido = (bool)$partida['resolvido'];
$jogo_fim       = $jogo_resolvido || count($tentativas) >= $MAX_TENTATIVAS;

// ─── AJAX ────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'palpite') {
    header('Content-Type: application/json');
    if ($jogo_fim) { echo json_encode(['ok'=>false,'msg'=>'Partida já encerrada']); exit; }

    $guessedId     = (int)($_POST['player_id'] ?? 0);
    $guessedPlayer = $playerById[$guessedId] ?? null;
    if (!$guessedPlayer) { echo json_encode(['ok'=>false,'msg'=>'Jogador inválido']); exit; }

    foreach ($tentativas as $t) {
        if ((int)($t['id'] ?? 0) === $guessedId) { echo json_encode(['ok'=>false,'msg'=>'Já tentado']); exit; }
    }

    $result = [
        'id'   => $guessedId,
        'n'    => $guessedPlayer['n'],
        't'    => ['val'=>$guessedPlayer['t'],    'r'=>qsb_cmp($guessedPlayer['t'],    $targetPlayer['t'])],
        'conf' => ['val'=>$guessedPlayer['conf'], 'r'=>qsb_cmp($guessedPlayer['conf'], $targetPlayer['conf'])],
        'pos'  => ['val'=>$guessedPlayer['pos'],  'r'=>qsb_cmp($guessedPlayer['pos'],  $targetPlayer['pos'])],
        'age'  => ['val'=>$guessedPlayer['age'],  'r'=>qsb_cmp($guessedPlayer['age'],  $targetPlayer['age'])],
        'ppg'  => ['val'=>$guessedPlayer['ppg'],  'r'=>qsb_cmp($guessedPlayer['ppg'],  $targetPlayer['ppg'])],
    ];

    $tentativas[] = $result;
    $acertou      = ($guessedId === $targetPlayer['id']);
    $fim          = $acertou || count($tentativas) >= $MAX_TENTATIVAS;
    $pontosGanhos = $acertou ? 200 : 0;

    try {
        $pdo->prepare("UPDATE quemsoueu_basquete
            SET tentativas=?, resolvido=?, pontos_ganhos=?, tentativas_json=?,
                concluido_em=" . ($fim ? 'NOW()' : 'concluido_em') . "
            WHERE id_usuario=? AND data_jogo=?")
            ->execute([count($tentativas), $acertou?1:0, $pontosGanhos, json_encode($tentativas, JSON_UNESCAPED_UNICODE), $user_id, $hoje]);
        if ($pontosGanhos > 0) {
            $pdo->prepare("UPDATE usuarios SET pontos=pontos+? WHERE id=?")->execute([$pontosGanhos, $user_id]);
        }
    } catch (PDOException $e) {}

    echo json_encode([
        'ok'          => true,
        'result'      => $result,
        'acertou'     => $acertou,
        'fim'         => $fim,
        'tentativas'  => count($tentativas),
        'max'         => $MAX_TENTATIVAS,
        'pontos'      => $pontosGanhos,
        'jogador_real'=> $fim ? $targetPlayer['n']  : null,
        'target_team' => $fim ? $targetPlayer['t']  : null,
    ]);
    exit;
}

$jsPlayers = array_map(fn($p) => ['id'=>$p['id'],'n'=>$p['n']], $QSB_PLAYERS);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Quem Sou Eu? Basquete — FBA Games</title>
	<link rel="icon" type="image/png" href="/img/fbagames.png">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
:root{
  --red:#fc0025;--red-soft:rgba(252,0,37,.10);--red-glow:rgba(252,0,37,.18);
  --bg:#07070a;--panel:#101013;--panel-2:#16161a;--panel-3:#1c1c21;
  --border:rgba(255,255,255,.06);--border-md:rgba(255,255,255,.10);--border-red:rgba(252,0,37,.22);
  --text:#f0f0f3;--text-2:#868690;--text-3:#48484f;
  --green:#22c55e;--amber:#f59e0b;--purple:#818cf8;
  --font:'Poppins',system-ui,sans-serif;
  --radius:14px;--radius-sm:10px;--t:200ms;--ease:cubic-bezier(.2,.8,.2,1);
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:var(--font);background:var(--bg);color:var(--text);min-height:100vh;-webkit-font-smoothing:antialiased}

/* ── Topbar ── */
.topbar{position:sticky;top:0;z-index:200;height:52px;background:var(--panel);border-bottom:1px solid var(--border);
        display:flex;align-items:center;gap:12px;padding:0 16px}
.topbar-back{width:32px;height:32px;border-radius:8px;border:1px solid var(--border);background:transparent;
             color:var(--text-2);display:flex;align-items:center;justify-content:center;font-size:15px;
             text-decoration:none;transition:all var(--t) var(--ease);flex-shrink:0}
.topbar-back:hover{background:var(--red-soft);border-color:var(--border-red);color:var(--red)}
.topbar-title{font-size:14px;font-weight:800;color:var(--text);flex:1}
.topbar-title span{color:var(--red)}
.topbar-chip{display:flex;align-items:center;gap:5px;background:var(--panel-2);border:1px solid var(--border);
             border-radius:999px;padding:4px 11px;font-size:11px;font-weight:700;color:var(--text-2);flex-shrink:0}
.topbar-chip i{font-size:10px;color:var(--red)}

/* ── Page wrap ── */
.qsb-page{display:flex;flex-direction:column;align-items:center;padding:20px 16px 64px}

/* ── Pips ── */
.qsb-counter-row{width:100%;max-width:540px;display:flex;align-items:center;justify-content:space-between;margin-bottom:14px}
.qsb-guess-count{font-size:11px;font-weight:700;letter-spacing:1.2px;text-transform:uppercase;color:var(--text-3)}
.qsb-pips{display:flex;gap:5px}
.qsb-pip{width:10px;height:10px;border-radius:50%;background:var(--panel-3);border:1px solid var(--border-md);transition:all .3s}
.qsb-pip.used{background:var(--text-3)}
.qsb-pip.win{background:var(--green);box-shadow:0 0 6px rgba(34,197,94,.5)}
.qsb-pip.lose{background:var(--red);box-shadow:0 0 6px rgba(252,0,37,.4)}

/* ── Hero card ── */
.qsb-card{background:var(--panel);border:1px solid var(--border);border-radius:var(--radius);
          padding:20px;width:100%;max-width:540px;display:flex;flex-direction:column;align-items:center;
          gap:10px;margin-bottom:16px;transition:border-color .4s}
.qsb-card.solved{border-color:rgba(34,197,94,.35)}
.qsb-card.failed{border-color:var(--border-red)}

/* Photo blur reveal */
.qsb-photo-wrap{width:120px;height:120px;border-radius:50%;overflow:hidden;
                border:2px solid var(--border-md);background:var(--panel-2);position:relative;
                flex-shrink:0;transition:border-color .5s,box-shadow .5s}
.qsb-photo-wrap.win{border-color:var(--green);box-shadow:0 0 24px rgba(34,197,94,.3)}
.qsb-photo-wrap.lose{border-color:var(--border-red)}
.qsb-photo{width:100%;height:100%;object-fit:cover;object-position:top center;
           display:block;transition:filter .6s ease}
.qsb-photo-fb{display:none;width:100%;height:100%;align-items:center;justify-content:center;
              font-size:52px;background:var(--panel-2)}
.qsb-blur-hint{font-size:9px;font-weight:700;letter-spacing:.8px;text-transform:uppercase;
               color:var(--text-3);margin-top:-2px}

.qsb-result-msg{font-size:16px;font-weight:700;text-align:center;display:none}
.qsb-result-msg.win{color:var(--green)}
.qsb-result-msg.lose{color:#ff6680}
.qsb-points-badge{background:rgba(34,197,94,.1);border:1px solid rgba(34,197,94,.25);color:var(--green);
                  border-radius:999px;padding:4px 14px;font-size:12px;font-weight:700;display:none}

/* ── Legend ── */
.qsb-legend{display:flex;gap:14px;flex-wrap:wrap;justify-content:center;margin-bottom:12px;width:100%;max-width:540px}
.qsb-legend span{display:flex;align-items:center;gap:5px;font-size:10px;font-weight:600;color:var(--text-3)}
.qsb-legend-dot{width:9px;height:9px;border-radius:3px;flex-shrink:0}

/* ── Columns ── */
.qsb-cols{display:grid;grid-template-columns:1fr 56px 56px 46px 46px 52px;gap:5px;
          width:100%;max-width:540px;padding:0 2px;margin-bottom:4px}
.qsb-col-lbl{text-align:center;font-size:9px;font-weight:700;letter-spacing:.8px;text-transform:uppercase;color:var(--text-3)}
.qsb-col-lbl:first-child{text-align:left}

/* ── Rows ── */
.qsb-rows{width:100%;max-width:540px;display:flex;flex-direction:column;gap:5px;margin-bottom:16px}
.qsb-row{display:grid;grid-template-columns:1fr 56px 56px 46px 46px 52px;gap:5px;animation:rowIn .3s ease both}
@keyframes rowIn{from{opacity:0;transform:translateY(-6px)}to{opacity:1;transform:translateY(0)}}
.qsb-row-name{background:var(--panel-2);border:1px solid var(--border-md);border-radius:9px;
              padding:8px 10px;font-size:11px;font-weight:600;color:var(--text);
              white-space:nowrap;overflow:hidden;text-overflow:ellipsis;display:flex;align-items:center}
.qsb-cell{border-radius:9px;display:flex;flex-direction:column;align-items:center;justify-content:center;
          padding:5px 3px;min-height:50px;font-size:9px;font-weight:700;gap:1px;transition:background .2s;text-align:center;line-height:1.3}
.qsb-cell.correct{background:rgba(34,197,94,.12);border:1px solid rgba(34,197,94,.35);color:var(--green)}
.qsb-cell.up,.qsb-cell.down{background:var(--panel-2);border:1px solid var(--border-md);color:var(--text-2)}
.qsb-cell.wrong{background:var(--panel-2);border:1px solid var(--border);color:var(--text-3)}
.qsb-cell-logo{width:28px;height:28px;object-fit:contain}
.qsb-cell-arrow{font-size:12px;line-height:1}
.qsb-cell.up .qsb-cell-arrow{color:var(--amber)}
.qsb-cell.down .qsb-cell-arrow{color:var(--purple)}

/* ── Input ── */
.qsb-input-wrap{width:100%;max-width:540px;position:relative;margin-bottom:8px}
.qsb-input{width:100%;background:var(--panel-2);border:1.5px solid var(--border-red);border-radius:var(--radius-sm);
           padding:12px 16px;font-size:14px;color:var(--text);outline:none;
           transition:border-color var(--t) var(--ease);font-family:var(--font)}
.qsb-input:disabled{opacity:.35;cursor:not-allowed;border-color:var(--border)}
.qsb-input::placeholder{color:var(--text-3)}
.qsb-input:focus{border-color:var(--red)}
.qsb-suggestions{position:absolute;top:calc(100% + 4px);left:0;right:0;background:var(--panel);
                 border:1px solid var(--border-md);border-radius:var(--radius-sm);overflow:hidden;
                 z-index:100;display:none;max-height:220px;overflow-y:auto;box-shadow:0 8px 24px rgba(0,0,0,.5)}
.qsb-sug-item{padding:10px 16px;font-size:13px;cursor:pointer;transition:background .15s;
              border-bottom:1px solid var(--border);font-family:var(--font)}
.qsb-sug-item:last-child{border-bottom:none}
.qsb-sug-item:hover,.qsb-sug-item.active{background:var(--panel-2);color:var(--red)}
.qsb-sug-item em{color:var(--red);font-style:normal;font-weight:700}

/* ── Share btn ── */
.qsb-share-btn{display:none;background:var(--panel-2);border:1px solid var(--border-md);border-radius:var(--radius-sm);
               padding:9px 22px;font-size:12px;font-weight:600;color:var(--text-2);cursor:pointer;
               transition:all var(--t) var(--ease);font-family:var(--font);margin-top:4px}
.qsb-share-btn:hover{border-color:var(--border-red);color:var(--red)}

@media(max-width:480px){
  .qsb-cols,.qsb-row{grid-template-columns:1fr 48px 50px 40px 40px 46px;gap:4px}
  .qsb-row-name{font-size:9px;padding:6px 8px}
  .qsb-cell{min-height:46px;font-size:8px}
  .qsb-cell-logo{width:24px;height:24px}
}
</style>
</head>
<body>

<div class="topbar">
  <a href="../games.php" class="topbar-back"><i class="bi bi-arrow-left"></i></a>
  <div class="topbar-title">Quem Sou Eu? <span>Basquete</span></div>
  <div class="topbar-chip"><i class="bi bi-calendar3"></i><?= date('d/m/Y') ?></div>
</div>

<div class="qsb-page">

<div class="qsb-counter-row">
  <div class="qsb-guess-count" id="qsbCounter">Tentativa <?= count($tentativas)+1 ?> de <?= $MAX_TENTATIVAS ?></div>
  <div class="qsb-pips" id="qsbPips">
    <?php for($i=0;$i<$MAX_TENTATIVAS;$i++): ?>
    <div class="qsb-pip<?= $i < count($tentativas) ? ' used' : '' ?>" data-pip="<?= $i ?>"></div>
    <?php endfor; ?>
  </div>
</div>

<div class="qsb-card" id="qsbCard">
  <div class="qsb-photo-wrap" id="qsbPhotoWrap">
    <img id="qsbPhoto"
         src="https://cdn.nba.com/headshots/nba/latest/1040x760/<?= (int)$targetPlayer['nba_id'] ?>.png"
         class="qsb-photo"
         onerror="this.style.display='none';document.getElementById('qsbPhotoFb').style.display='flex'">
    <div id="qsbPhotoFb" class="qsb-photo-fb">🏀</div>
  </div>
  <div class="qsb-blur-hint" id="qsbBlurHint">Adivinhe o jogador</div>
  <div class="qsb-result-msg" id="qsbResultMsg"></div>
  <div class="qsb-points-badge" id="qsbPointsBadge"></div>
</div>

<div class="qsb-legend">
  <span><div class="qsb-legend-dot" style="background:rgba(34,197,94,.15);border:1px solid rgba(34,197,94,.4)"></div>Correto</span>
  <span><div class="qsb-legend-dot" style="background:var(--panel-2);border:1px solid var(--border-md)"></div>Errado &nbsp;↑ maior &nbsp;↓ menor</span>
</div>

<div class="qsb-cols">
  <div class="qsb-col-lbl">Jogador</div>
  <div class="qsb-col-lbl">Time</div>
  <div class="qsb-col-lbl">Conf</div>
  <div class="qsb-col-lbl">Pos</div>
  <div class="qsb-col-lbl">Idade</div>
  <div class="qsb-col-lbl">PPG</div>
</div>

<div class="qsb-rows" id="qsbRows"></div>

<div class="qsb-input-wrap">
  <input type="text" id="qsbInput" class="qsb-input" placeholder="Digite o nome do jogador..." autocomplete="off" <?= $jogo_fim ? 'disabled' : '' ?>>
  <div class="qsb-suggestions" id="qsbSuggestions"></div>
</div>
<button class="qsb-share-btn" id="qsbShareBtn" onclick="qsbShare()"><i class="bi bi-share-fill" style="margin-right:6px"></i>Compartilhar resultado</button>

</div>

<script>
const QSB_PLAYERS    = <?= json_encode($jsPlayers, JSON_UNESCAPED_UNICODE) ?>;
const MAX_TENT       = <?= $MAX_TENTATIVAS ?>;
const GAME_DONE      = <?= $jogo_fim ? 'true' : 'false' ?>;
const GAME_WIN       = <?= $jogo_resolvido ? 'true' : 'false' ?>;
const INITIAL_STATE  = <?= json_encode($tentativas, JSON_UNESCAPED_UNICODE) ?>;
const INITIAL_PONTOS = <?= (int)($partida['pontos_ganhos'] ?? 0) ?>;
const TARGET_TEAM    = <?= $jogo_fim ? json_encode($targetPlayer['t']) : 'null' ?>;
const TARGET_NAME    = <?= $jogo_fim ? json_encode($targetPlayer['n'], JSON_UNESCAPED_UNICODE) : 'null' ?>;
const NBA_PLAYER_ID  = <?= (int)$targetPlayer['nba_id'] ?>;

let tentativas = [...INITIAL_STATE];
let gameDone   = GAME_DONE;
let gameWin    = GAME_WIN;
let selectedId = null;

// Blur steps: index = attempts made (0–8)
const BLUR_STEPS    = [28, 24, 20, 16, 12, 8, 4, 2, 0];
const BRIGHT_STEPS  = [0.30, 0.40, 0.50, 0.60, 0.70, 0.82, 0.90, 0.96, 1.0];
const BLUR_HINTS    = [
  'Foto muito borrada…', 'Ainda bem borrado…', 'Dá pra ver algo…',
  'Ficando mais claro…', 'Quase reconhecível…', 'Quase lá!',
  'Última chance!', 'Adivinhe o jogador', ''
];

function updatePhoto(n, done, win) {
  const img  = document.getElementById('qsbPhoto');
  const wrap = document.getElementById('qsbPhotoWrap');
  const hint = document.getElementById('qsbBlurHint');
  if (!img) return;
  if (done && win) {
    img.style.filter = 'none';
    wrap.classList.add('win');
    if (hint) hint.style.display = 'none';
  } else if (done && !win) {
    img.style.filter = 'grayscale(75%) brightness(0.65)';
    wrap.classList.add('lose');
    if (hint) hint.style.display = 'none';
  } else {
    const blur   = BLUR_STEPS[Math.min(n, BLUR_STEPS.length - 1)];
    const bright = BRIGHT_STEPS[Math.min(n, BRIGHT_STEPS.length - 1)];
    img.style.filter = `blur(${blur}px) brightness(${bright})`;
    if (hint) hint.textContent = BLUR_HINTS[Math.min(n, BLUR_HINTS.length - 1)];
  }
}

function teamLogo(abbr) {
  return `https://a.espncdn.com/i/teamlogos/nba/500/${abbr.toLowerCase()}.png`;
}

function makeRow(t) {
  const arrow = {up:'↑', down:'↓', correct:'', wrong:''};
  const cls   = r => r === 'correct' ? 'correct' : r === 'up' ? 'up' : r === 'down' ? 'down' : 'wrong';

  const teamCell = `<div class="qsb-cell ${cls(t.t.r)}">
    <img class="qsb-cell-logo" src="${teamLogo(t.t.val)}"
         onerror="this.style.display='none';this.nextSibling.style.display='block'">
    <span style="display:none;font-size:9px">${t.t.val}</span>
  </div>`;

  const confCell = `<div class="qsb-cell ${cls(t.conf.r)}">
    <span style="font-size:8px">${t.conf.val}</span>
  </div>`;

  const posCell = `<div class="qsb-cell ${cls(t.pos.r)}">${t.pos.val}</div>`;

  const ageCell = `<div class="qsb-cell ${cls(t.age.r)}">
    <span>${t.age.val}</span>
    ${t.age.r !== 'correct' ? `<span class="qsb-cell-arrow">${arrow[t.age.r]}</span>` : ''}
  </div>`;

  const ppgCell = `<div class="qsb-cell ${cls(t.ppg.r)}">
    <span>${(+t.ppg.val).toFixed(1)}</span>
    ${t.ppg.r !== 'correct' ? `<span class="qsb-cell-arrow">${arrow[t.ppg.r]}</span>` : ''}
  </div>`;

  const row = document.createElement('div');
  row.className = 'qsb-row';
  row.innerHTML = `<div class="qsb-row-name">${t.n}</div>${teamCell}${confCell}${posCell}${ageCell}${ppgCell}`;
  return row;
}

function renderRows() {
  const c = document.getElementById('qsbRows');
  c.innerHTML = '';
  tentativas.forEach(t => c.appendChild(makeRow(t)));
}

function updateCounter() {
  const el = document.getElementById('qsbCounter');
  if (gameDone) { el.style.display = 'none'; return; }
  el.textContent = `Tentativa ${tentativas.length + 1} de ${MAX_TENT}`;
  document.querySelectorAll('.qsb-pip').forEach((pip, i) => {
    pip.classList.remove('used','win','lose');
    if (i < tentativas.length) pip.classList.add(
      gameDone && i === tentativas.length - 1 ? (gameWin ? 'win' : 'lose') : 'used'
    );
  });
}

function showFinish(win, pontos, teamAbbr, playerName) {
  gameDone = true; gameWin = win;
  document.getElementById('qsbInput').disabled = true;
  document.getElementById('qsbCounter').style.display = 'none';

  const card = document.getElementById('qsbCard');
  card.classList.add(win ? 'solved' : 'failed');

  document.querySelectorAll('.qsb-pip').forEach((pip, i) => {
    pip.classList.remove('used','win','lose');
    if (i < tentativas.length - 1) pip.classList.add('used');
    else if (i === tentativas.length - 1) pip.classList.add(win ? 'win' : 'lose');
  });

  updatePhoto(tentativas.length, true, win);

  const msg = document.getElementById('qsbResultMsg');
  msg.style.display = 'block';
  if (win) {
    msg.className = 'qsb-result-msg win';
    msg.textContent = '🏀 Acertou! ' + (playerName || '');
    if (pontos > 0) {
      const pb = document.getElementById('qsbPointsBadge');
      pb.style.display = 'block';
      pb.textContent = `+${pontos} pontos`;
    }
  } else {
    msg.className = 'qsb-result-msg lose';
    msg.textContent = '😔 Era ' + (playerName || 'o jogador de hoje');
  }
  document.getElementById('qsbShareBtn').style.display = 'inline-flex';
}

// ── Autocomplete ──────────────────────────────────────────────────────────────
const input      = document.getElementById('qsbInput');
const sugBox     = document.getElementById('qsbSuggestions');
const guessedIds = new Set(tentativas.map(t => t.id));
let activeIdx    = -1;

function highlight(text, query) {
  const i = text.toLowerCase().indexOf(query.toLowerCase());
  if (i < 0) return text;
  return text.slice(0,i) + '<em>' + text.slice(i, i+query.length) + '</em>' + text.slice(i+query.length);
}

function showSuggestions(q) {
  if (!q || q.length < 2) { sugBox.style.display = 'none'; return; }
  const matches = QSB_PLAYERS
    .filter(p => !guessedIds.has(p.id) && p.n.toLowerCase().includes(q.toLowerCase()))
    .slice(0, 8);
  if (!matches.length) { sugBox.style.display = 'none'; return; }
  sugBox.innerHTML = matches.map((p,i) =>
    `<div class="qsb-sug-item" data-id="${p.id}" data-idx="${i}">${highlight(p.n, q)}</div>`
  ).join('');
  sugBox.style.display = 'block';
  activeIdx = -1;
  sugBox.querySelectorAll('.qsb-sug-item').forEach(el => {
    el.addEventListener('mousedown', e => { e.preventDefault(); selectPlayer(+el.dataset.id, el.textContent); });
  });
}

function selectPlayer(id, name) {
  selectedId = id;
  input.value = name.replace(/<[^>]+>/g,'');
  sugBox.style.display = 'none';
}

input.addEventListener('input', () => { selectedId = null; showSuggestions(input.value); });
input.addEventListener('keydown', e => {
  const items = sugBox.querySelectorAll('.qsb-sug-item');
  if (e.key === 'ArrowDown') { e.preventDefault(); activeIdx = Math.min(activeIdx+1, items.length-1); items.forEach((el,i)=>el.classList.toggle('active',i===activeIdx)); return; }
  if (e.key === 'ArrowUp')   { e.preventDefault(); activeIdx = Math.max(activeIdx-1, 0); items.forEach((el,i)=>el.classList.toggle('active',i===activeIdx)); return; }
  if (e.key === 'Enter') {
    e.preventDefault();
    const vis = sugBox.style.display !== 'none' && items.length > 0;
    if (vis) { const t = activeIdx >= 0 ? items[activeIdx] : items[0]; selectPlayer(+t.dataset.id, t.textContent); }
    else if (selectedId) submitGuess();
    return;
  }
  if (e.key === 'Escape') sugBox.style.display = 'none';
});
document.addEventListener('click', e => { if (!e.target.closest('.qsb-input-wrap')) sugBox.style.display = 'none'; });

// ── Submit ────────────────────────────────────────────────────────────────────
async function submitGuess() {
  if (!selectedId || gameDone) return;
  input.disabled = true;
  const fd = new FormData();
  fd.append('action', 'palpite');
  fd.append('player_id', selectedId);
  try {
    const res  = await fetch(window.location.href, {method:'POST', body:fd});
    const data = await res.json();
    if (!data.ok) { alert(data.msg || 'Erro'); input.disabled = false; return; }

    tentativas.push(data.result);
    guessedIds.add(data.result.id);
    renderRows();
    updateCounter();
    updatePhoto(tentativas.length, data.fim, data.acertou);
    input.value = '';
    selectedId  = null;
    input.disabled = false;
    if (data.fim) showFinish(data.acertou, data.pontos, data.target_team, data.jogador_real);
  } catch(err) { input.disabled = false; alert('Erro ao enviar palpite'); }
}

// ── Share ─────────────────────────────────────────────────────────────────────
function qsbShare() {
  const emojiMap = {correct:'🟩', wrong:'⬜', up:'🟨', down:'🟦'};
  const lines = [`🏀 Quem Sou Eu? Basquete — ${new Date().toLocaleDateString('pt-BR')}`];
  lines.push(`${gameWin ? '✅' : '❌'} ${tentativas.length}/${MAX_TENT} tentativas`);
  lines.push('');
  tentativas.forEach(t => {
    const row = [t.t.r, t.conf.r, t.pos.r, t.age.r, t.ppg.r].map(r => emojiMap[r]||'⬜').join('');
    lines.push(row);
  });
  const text = lines.join('\n');
  navigator.clipboard.writeText(text).then(() => alert('Resultado copiado!')).catch(() => prompt('Copie:', text));
}

// ── Init ──────────────────────────────────────────────────────────────────────
renderRows();
updateCounter();
updatePhoto(tentativas.length, GAME_DONE, GAME_WIN);
if (GAME_DONE) showFinish(GAME_WIN, INITIAL_PONTOS, TARGET_TEAM, TARGET_NAME);
else input.focus();
</script>
</body>
</html>
