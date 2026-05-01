<?php
/**
 * quemsoueu.php — Quem Sou Eu? NBA
 * Adivinhe o jogador NBA em até 8 tentativas.
 * Incluído por games/games/index.php — $pdo e $_SESSION já disponíveis.
 */

$user_id        = (int)$_SESSION['user_id'];
$hoje           = date('Y-m-d');
$MAX_TENTATIVAS = 8;

// ─── DATASET ────────────────────────────────────────────────────────────────
// n=nome, t=time(sigla), pos=posição, h=altura(polegadas), age=idade, num=camisa
$QSE_PLAYERS = [
    ['id'=>1,  'n'=>'LeBron James',             't'=>'LAL','pos'=>'SF','h'=>81,'age'=>40,'num'=>23],
    ['id'=>2,  'n'=>'Stephen Curry',            't'=>'GSW','pos'=>'PG','h'=>74,'age'=>37,'num'=>30],
    ['id'=>3,  'n'=>'Kevin Durant',             't'=>'PHX','pos'=>'SF','h'=>83,'age'=>36,'num'=>35],
    ['id'=>4,  'n'=>'Giannis Antetokounmpo',    't'=>'MIL','pos'=>'PF','h'=>83,'age'=>30,'num'=>34],
    ['id'=>5,  'n'=>'Nikola Jokic',             't'=>'DEN','pos'=>'C', 'h'=>83,'age'=>30,'num'=>15],
    ['id'=>6,  'n'=>'Luka Doncic',              't'=>'LAL','pos'=>'PG','h'=>79,'age'=>25,'num'=>7],
    ['id'=>7,  'n'=>'Joel Embiid',              't'=>'PHI','pos'=>'C', 'h'=>84,'age'=>30,'num'=>21],
    ['id'=>8,  'n'=>'Jayson Tatum',             't'=>'BOS','pos'=>'SF','h'=>80,'age'=>26,'num'=>0],
    ['id'=>9,  'n'=>'Shai Gilgeous-Alexander',  't'=>'OKC','pos'=>'SG','h'=>79,'age'=>26,'num'=>2],
    ['id'=>10, 'n'=>'Anthony Davis',            't'=>'LAL','pos'=>'PF','h'=>82,'age'=>31,'num'=>3],
    ['id'=>11, 'n'=>'Kawhi Leonard',            't'=>'LAC','pos'=>'SF','h'=>79,'age'=>33,'num'=>2],
    ['id'=>12, 'n'=>'Jimmy Butler',             't'=>'GSW','pos'=>'SF','h'=>79,'age'=>35,'num'=>4],
    ['id'=>13, 'n'=>'Damian Lillard',           't'=>'MIL','pos'=>'PG','h'=>75,'age'=>34,'num'=>0],
    ['id'=>14, 'n'=>'Kyrie Irving',             't'=>'DAL','pos'=>'PG','h'=>75,'age'=>32,'num'=>11],
    ['id'=>15, 'n'=>'James Harden',             't'=>'LAC','pos'=>'PG','h'=>77,'age'=>35,'num'=>1],
    ['id'=>16, 'n'=>'Devin Booker',             't'=>'PHX','pos'=>'SG','h'=>78,'age'=>28,'num'=>1],
    ['id'=>17, 'n'=>'Trae Young',               't'=>'ATL','pos'=>'PG','h'=>73,'age'=>26,'num'=>11],
    ['id'=>18, 'n'=>'Zion Williamson',          't'=>'NOP','pos'=>'PF','h'=>77,'age'=>24,'num'=>1],
    ['id'=>19, 'n'=>'Donovan Mitchell',         't'=>'CLE','pos'=>'SG','h'=>74,'age'=>28,'num'=>45],
    ['id'=>20, 'n'=>'Bam Adebayo',              't'=>'MIA','pos'=>'C', 'h'=>81,'age'=>27,'num'=>13],
    ['id'=>21, 'n'=>'Ja Morant',                't'=>'MEM','pos'=>'PG','h'=>76,'age'=>25,'num'=>12],
    ['id'=>22, 'n'=>'Karl-Anthony Towns',       't'=>'NYK','pos'=>'C', 'h'=>84,'age'=>29,'num'=>32],
    ['id'=>23, 'n'=>'Jaylen Brown',             't'=>'BOS','pos'=>'SG','h'=>78,'age'=>28,'num'=>7],
    ['id'=>24, 'n'=>'Tyrese Haliburton',        't'=>'IND','pos'=>'PG','h'=>77,'age'=>24,'num'=>0],
    ['id'=>25, 'n'=>'Domantas Sabonis',         't'=>'SAC','pos'=>'C', 'h'=>81,'age'=>28,'num'=>11],
    ['id'=>26, 'n'=>'Lauri Markkanen',          't'=>'UTA','pos'=>'PF','h'=>84,'age'=>27,'num'=>23],
    ['id'=>27, 'n'=>'Evan Mobley',              't'=>'CLE','pos'=>'PF','h'=>84,'age'=>23,'num'=>4],
    ['id'=>28, 'n'=>'Chet Holmgren',            't'=>'OKC','pos'=>'C', 'h'=>85,'age'=>23,'num'=>7],
    ['id'=>29, 'n'=>'Victor Wembanyama',        't'=>'SAS','pos'=>'C', 'h'=>87,'age'=>21,'num'=>1],
    ['id'=>30, 'n'=>'Paolo Banchero',           't'=>'ORL','pos'=>'PF','h'=>82,'age'=>22,'num'=>5],
    ['id'=>31, 'n'=>'Franz Wagner',             't'=>'ORL','pos'=>'SF','h'=>81,'age'=>23,'num'=>21],
    ['id'=>32, 'n'=>'Darius Garland',           't'=>'CLE','pos'=>'PG','h'=>73,'age'=>24,'num'=>10],
    ['id'=>33, 'n'=>'Mikal Bridges',            't'=>'NYK','pos'=>'SF','h'=>79,'age'=>28,'num'=>25],
    ['id'=>34, 'n'=>'OG Anunoby',               't'=>'NYK','pos'=>'SF','h'=>79,'age'=>27,'num'=>8],
    ['id'=>35, 'n'=>'Jaren Jackson Jr.',        't'=>'MEM','pos'=>'PF','h'=>83,'age'=>25,'num'=>13],
    ['id'=>36, 'n'=>'De\'Aaron Fox',            't'=>'SAC','pos'=>'PG','h'=>75,'age'=>27,'num'=>5],
    ['id'=>37, 'n'=>'Anthony Edwards',          't'=>'MIN','pos'=>'SG','h'=>76,'age'=>23,'num'=>5],
    ['id'=>38, 'n'=>'Klay Thompson',            't'=>'DAL','pos'=>'SG','h'=>79,'age'=>34,'num'=>31],
    ['id'=>39, 'n'=>'Draymond Green',           't'=>'GSW','pos'=>'PF','h'=>79,'age'=>34,'num'=>23],
    ['id'=>40, 'n'=>'Chris Paul',               't'=>'SAS','pos'=>'PG','h'=>72,'age'=>39,'num'=>3],
    ['id'=>41, 'n'=>'Paul George',              't'=>'PHI','pos'=>'SF','h'=>80,'age'=>34,'num'=>8],
    ['id'=>42, 'n'=>'Khris Middleton',          't'=>'MIL','pos'=>'SF','h'=>80,'age'=>33,'num'=>22],
    ['id'=>43, 'n'=>'Jrue Holiday',             't'=>'BOS','pos'=>'SG','h'=>76,'age'=>34,'num'=>4],
    ['id'=>44, 'n'=>'Rudy Gobert',              't'=>'MIN','pos'=>'C', 'h'=>85,'age'=>32,'num'=>27],
    ['id'=>45, 'n'=>'Julius Randle',            't'=>'MIN','pos'=>'PF','h'=>81,'age'=>30,'num'=>30],
    ['id'=>46, 'n'=>'CJ McCollum',              't'=>'NOP','pos'=>'SG','h'=>75,'age'=>33,'num'=>3],
    ['id'=>47, 'n'=>'Jamal Murray',             't'=>'DEN','pos'=>'PG','h'=>76,'age'=>27,'num'=>27],
    ['id'=>48, 'n'=>'Michael Porter Jr.',       't'=>'DEN','pos'=>'SF','h'=>82,'age'=>26,'num'=>1],
    ['id'=>49, 'n'=>'Deandre Ayton',            't'=>'POR','pos'=>'C', 'h'=>84,'age'=>26,'num'=>2],
    ['id'=>50, 'n'=>'Bradley Beal',             't'=>'PHX','pos'=>'SG','h'=>77,'age'=>31,'num'=>3],
    ['id'=>51, 'n'=>'Tyler Herro',              't'=>'MIA','pos'=>'SG','h'=>76,'age'=>24,'num'=>14],
    ['id'=>52, 'n'=>'Scottie Barnes',           't'=>'TOR','pos'=>'PF','h'=>81,'age'=>23,'num'=>4],
    ['id'=>53, 'n'=>'Brandon Ingram',           't'=>'NOP','pos'=>'SF','h'=>81,'age'=>27,'num'=>14],
    ['id'=>54, 'n'=>'Desmond Bane',             't'=>'MEM','pos'=>'SG','h'=>77,'age'=>26,'num'=>22],
    ['id'=>55, 'n'=>'Alperen Sengun',           't'=>'HOU','pos'=>'C', 'h'=>82,'age'=>22,'num'=>28],
    ['id'=>56, 'n'=>'Kobe Bryant',              't'=>'LAL','pos'=>'SG','h'=>78,'age'=>33,'num'=>24],
    ['id'=>57, 'n'=>'Tim Duncan',               't'=>'SAS','pos'=>'PF','h'=>84,'age'=>36,'num'=>21],
    ['id'=>58, 'n'=>'Dirk Nowitzki',            't'=>'DAL','pos'=>'PF','h'=>84,'age'=>37,'num'=>41],
    ['id'=>59, 'n'=>'Shaquille O\'Neal',        't'=>'LAL','pos'=>'C', 'h'=>85,'age'=>31,'num'=>34],
    ['id'=>60, 'n'=>'Allen Iverson',            't'=>'PHI','pos'=>'SG','h'=>71,'age'=>31,'num'=>3],
    ['id'=>61, 'n'=>'Kevin Garnett',            't'=>'BOS','pos'=>'PF','h'=>83,'age'=>32,'num'=>5],
    ['id'=>62, 'n'=>'Carmelo Anthony',          't'=>'NYK','pos'=>'SF','h'=>80,'age'=>28,'num'=>7],
    ['id'=>63, 'n'=>'Dwyane Wade',              't'=>'MIA','pos'=>'SG','h'=>77,'age'=>27,'num'=>3],
    ['id'=>64, 'n'=>'Russell Westbrook',        't'=>'OKC','pos'=>'PG','h'=>75,'age'=>23,'num'=>0],
    ['id'=>65, 'n'=>'Vince Carter',             't'=>'TOR','pos'=>'SG','h'=>78,'age'=>23,'num'=>15],
];

$playerById = [];
foreach ($QSE_PLAYERS as $p) {
    $playerById[$p['id']] = $p;
}

// Altura em polegadas → string "X'Y\""
function qse_h(int $in): string {
    return floor($in / 12) . "'" . ($in % 12) . '"';
}

// Comparação: retorna 'correct' | 'up' | 'down' | 'wrong'
function qse_cmp($guessed, $target): string {
    if ($guessed === $target) return 'correct';
    if (is_int($guessed) && is_int($target)) {
        return $target > $guessed ? 'up' : 'down';
    }
    return 'wrong';
}

// Selecionar jogador do dia (mesmo para todos os usuários)
$dayHash       = abs(crc32($hoje . 'qse'));
$targetPlayer  = $QSE_PLAYERS[$dayHash % count($QSE_PLAYERS)];

// Criar tabela
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS quemsoueu_partidas (
        id            INT AUTO_INCREMENT PRIMARY KEY,
        id_usuario    INT NOT NULL,
        data_jogo     DATE NOT NULL,
        tentativas    INT DEFAULT 0,
        resolvido     TINYINT DEFAULT 0,
        pontos_ganhos INT DEFAULT 0,
        tentativas_json TEXT DEFAULT '[]',
        jogador_id    INT NOT NULL,
        concluido_em  DATETIME DEFAULT NULL,
        UNIQUE KEY uk_qse_user_date (id_usuario, data_jogo)
    ) DEFAULT CHARSET=utf8mb4");
} catch (PDOException $e) {}

// Carregar / criar partida de hoje
$partida = null;
try {
    $stmt = $pdo->prepare("SELECT * FROM quemsoueu_partidas WHERE id_usuario = ? AND data_jogo = ?");
    $stmt->execute([$user_id, $hoje]);
    $partida = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$partida) {
        $pdo->prepare("INSERT INTO quemsoueu_partidas (id_usuario, data_jogo, jogador_id, tentativas_json) VALUES (?,?,?,'[]')")
            ->execute([$user_id, $hoje, $targetPlayer['id']]);
        $partida = ['tentativas'=>0,'resolvido'=>0,'pontos_ganhos'=>0,'tentativas_json'=>'[]','concluido_em'=>null];
    }
} catch (PDOException $e) {
    $partida = ['tentativas'=>0,'resolvido'=>0,'pontos_ganhos'=>0,'tentativas_json'=>'[]','concluido_em'=>null];
}

$tentativas     = json_decode($partida['tentativas_json'] ?: '[]', true) ?: [];
$jogo_resolvido = (bool)$partida['resolvido'];
$jogo_fim       = $jogo_resolvido || count($tentativas) >= $MAX_TENTATIVAS;

// ─── AJAX: processar palpite ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'palpite') {
    header('Content-Type: application/json');

    if ($jogo_fim) {
        echo json_encode(['ok'=>false,'msg'=>'Partida já encerrada']);
        exit;
    }

    $guessedId     = (int)($_POST['player_id'] ?? 0);
    $guessedPlayer = $playerById[$guessedId] ?? null;

    if (!$guessedPlayer) {
        echo json_encode(['ok'=>false,'msg'=>'Jogador inválido']);
        exit;
    }

    // Não repetir palpite
    foreach ($tentativas as $t) {
        if ((int)($t['id'] ?? 0) === $guessedId) {
            echo json_encode(['ok'=>false,'msg'=>'Jogador já tentado']);
            exit;
        }
    }

    $result = [
        'id'  => $guessedId,
        'n'   => $guessedPlayer['n'],
        't'   => ['val'=>$guessedPlayer['t'],   'r'=>qse_cmp($guessedPlayer['t'],   $targetPlayer['t'])],
        'pos' => ['val'=>$guessedPlayer['pos'], 'r'=>qse_cmp($guessedPlayer['pos'], $targetPlayer['pos'])],
        'h'   => ['val'=>$guessedPlayer['h'],   'r'=>qse_cmp($guessedPlayer['h'],   $targetPlayer['h'])],
        'age' => ['val'=>$guessedPlayer['age'], 'r'=>qse_cmp($guessedPlayer['age'], $targetPlayer['age'])],
        'num' => ['val'=>$guessedPlayer['num'], 'r'=>qse_cmp($guessedPlayer['num'], $targetPlayer['num'])],
    ];

    $tentativas[]  = $result;
    $acertou       = ($guessedId === $targetPlayer['id']);
    $fim           = $acertou || count($tentativas) >= $MAX_TENTATIVAS;

    $pontosGanhos = $acertou ? 200 : 0;

    try {
        $pdo->prepare("UPDATE quemsoueu_partidas
            SET tentativas=?, resolvido=?, pontos_ganhos=?, tentativas_json=?,
                concluido_em=" . ($fim ? 'NOW()' : 'concluido_em') . "
            WHERE id_usuario=? AND data_jogo=?")
            ->execute([count($tentativas), $acertou?1:0, $pontosGanhos, json_encode($tentativas), $user_id, $hoje]);

        if ($pontosGanhos > 0) {
            $pdo->prepare("UPDATE usuarios SET pontos=pontos+? WHERE id=?")
                ->execute([$pontosGanhos, $user_id]);
        }
    } catch (PDOException $e) {}

    echo json_encode([
        'ok'         => true,
        'result'     => $result,
        'acertou'    => $acertou,
        'fim'        => $fim,
        'tentativas' => count($tentativas),
        'max'        => $MAX_TENTATIVAS,
        'pontos'     => $pontosGanhos,
        'jogador_real'=> $fim ? $targetPlayer['n'] : null,
        'target_team' => $fim ? $targetPlayer['t'] : null,
    ]);
    exit;
}

// ─── Dados para o JS ────────────────────────────────────────────────────────
$jsPlayers = array_map(fn($p) => ['id'=>$p['id'],'n'=>$p['n']], $QSE_PLAYERS);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Quem Sou Eu? NBA — FBA Games</title>
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
.qse-page{display:flex;flex-direction:column;align-items:center;padding:20px 16px 56px}

/* ── Attempt counter ── */
.qse-counter-row{width:100%;max-width:520px;display:flex;align-items:center;justify-content:space-between;margin-bottom:14px}
.qse-guess-count{font-size:11px;font-weight:700;letter-spacing:1.2px;text-transform:uppercase;color:var(--text-3)}
.qse-pips{display:flex;gap:5px}
.qse-pip{width:10px;height:10px;border-radius:50%;background:var(--panel-3);border:1px solid var(--border-md);transition:all .3s}
.qse-pip.used{background:var(--text-3)}
.qse-pip.win{background:var(--green);box-shadow:0 0 6px rgba(34,197,94,.5)}
.qse-pip.lose{background:var(--red);box-shadow:0 0 6px rgba(252,0,37,.4)}

/* ── Silhouette card ── */
.qse-card{background:var(--panel);border:1px solid var(--border);border-radius:var(--radius);
          padding:20px;width:100%;max-width:520px;display:flex;flex-direction:column;align-items:center;
          gap:10px;margin-bottom:16px;transition:border-color .4s}
.qse-card.solved{border-color:rgba(34,197,94,.35)}
.qse-card.failed{border-color:var(--border-red)}
.qse-sil{width:96px;height:96px;border-radius:50%;background:var(--panel-2);border:2px solid var(--border-md);
         display:flex;align-items:center;justify-content:center;position:relative;overflow:hidden;transition:all .4s}
.qse-sil.solved{border-color:var(--green);box-shadow:0 0 20px rgba(34,197,94,.25)}
.qse-sil-img{width:100%;height:100%;object-fit:cover;border-radius:50%;display:none}
.qse-sil-q{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;
           font-size:54px;color:var(--red);font-weight:900;text-shadow:0 0 24px var(--red-glow)}
.qse-result-msg{font-size:16px;font-weight:700;text-align:center;display:none}
.qse-result-msg.win{color:var(--green)}
.qse-result-msg.lose{color:#ff6680}
.qse-points-badge{background:rgba(34,197,94,.1);border:1px solid rgba(34,197,94,.25);color:var(--green);
                  border-radius:999px;padding:4px 14px;font-size:12px;font-weight:700;display:none}

/* ── Legend ── */
.qse-legend{display:flex;gap:14px;flex-wrap:wrap;justify-content:center;margin-bottom:12px;width:100%;max-width:520px}
.qse-legend span{display:flex;align-items:center;gap:5px;font-size:10px;font-weight:600;color:var(--text-3)}
.qse-legend-dot{width:9px;height:9px;border-radius:3px;flex-shrink:0}

/* ── Column labels ── */
.qse-cols{display:grid;grid-template-columns:1fr 56px 52px 74px 52px 48px;gap:5px;
          width:100%;max-width:520px;padding:0 2px;margin-bottom:4px}
.qse-col-lbl{text-align:center;font-size:9px;font-weight:700;letter-spacing:.8px;text-transform:uppercase;color:var(--text-3)}
.qse-col-lbl:first-child{text-align:left}

/* ── Guess rows ── */
.qse-rows{width:100%;max-width:520px;display:flex;flex-direction:column;gap:5px;margin-bottom:16px}
.qse-row{display:grid;grid-template-columns:1fr 56px 52px 74px 52px 48px;gap:5px;animation:rowIn .3s ease both}
@keyframes rowIn{from{opacity:0;transform:translateY(-6px)}to{opacity:1;transform:translateY(0)}}
.qse-row-name{background:var(--panel-2);border:1px solid var(--border-md);border-radius:9px;
              padding:8px 10px;font-size:12px;font-weight:600;color:var(--text);
              white-space:nowrap;overflow:hidden;text-overflow:ellipsis;display:flex;align-items:center}
.qse-cell{border-radius:9px;display:flex;flex-direction:column;align-items:center;justify-content:center;
          padding:5px 3px;min-height:48px;font-size:10px;font-weight:700;gap:2px;transition:background .2s}
.qse-cell.correct{background:rgba(34,197,94,.12);border:1px solid rgba(34,197,94,.35);color:var(--green)}
.qse-cell.up,.qse-cell.down{background:var(--panel-2);border:1px solid var(--border-md);color:var(--text-2)}
.qse-cell.wrong{background:var(--panel-2);border:1px solid var(--border);color:var(--text-3)}
.qse-cell-logo{width:28px;height:28px;object-fit:contain}
.qse-cell-arrow{font-size:13px;line-height:1}
.qse-cell.up .qse-cell-arrow{color:var(--amber)}
.qse-cell.down .qse-cell-arrow{color:var(--purple)}

/* ── Input area ── */
.qse-input-wrap{width:100%;max-width:520px;position:relative;margin-bottom:8px}
.qse-input{width:100%;background:var(--panel-2);border:1.5px solid var(--border-red);border-radius:var(--radius-sm);
           padding:12px 16px;font-size:14px;color:var(--text);outline:none;
           transition:border-color var(--t) var(--ease);font-family:var(--font)}
.qse-input:disabled{opacity:.35;cursor:not-allowed;border-color:var(--border)}
.qse-input::placeholder{color:var(--text-3)}
.qse-input:focus{border-color:var(--red)}
.qse-suggestions{position:absolute;top:calc(100% + 4px);left:0;right:0;background:var(--panel);
                 border:1px solid var(--border-md);border-radius:var(--radius-sm);overflow:hidden;
                 z-index:100;display:none;max-height:220px;overflow-y:auto;
                 box-shadow:0 8px 24px rgba(0,0,0,.5)}
.qse-sug-item{padding:10px 16px;font-size:13px;cursor:pointer;transition:background .15s;
              border-bottom:1px solid var(--border);font-family:var(--font)}
.qse-sug-item:last-child{border-bottom:none}
.qse-sug-item:hover,.qse-sug-item.active{background:var(--panel-2);color:var(--red)}
.qse-sug-item em{color:var(--red);font-style:normal;font-weight:700}

/* ── Share btn ── */
.qse-share-btn{display:none;background:var(--panel-2);border:1px solid var(--border-md);border-radius:var(--radius-sm);
               padding:9px 22px;font-size:12px;font-weight:600;color:var(--text-2);cursor:pointer;
               transition:all var(--t) var(--ease);font-family:var(--font);margin-top:4px}
.qse-share-btn:hover{border-color:var(--border-red);color:var(--red)}

@media(max-width:480px){
  .qse-cols,.qse-row{grid-template-columns:1fr 48px 46px 66px 46px 42px;gap:4px}
  .qse-row-name{font-size:10px;padding:7px 8px}
  .qse-cell{min-height:44px;font-size:9px}
  .qse-cell-logo{width:24px;height:24px}
}
</style>
</head>
<body>

<!-- Topbar -->
<div class="topbar">
  <a href="../games.php" class="topbar-back"><i class="bi bi-arrow-left"></i></a>
  <div class="topbar-title">Quem Sou Eu? <span>NBA</span></div>
  <div class="topbar-chip"><i class="bi bi-calendar3"></i><?= date('d/m/Y') ?></div>
</div>

<div class="qse-page">

<!-- Tentativas (pips) -->
<div class="qse-counter-row">
  <div class="qse-guess-count" id="qseCounter">Tentativa <?= count($tentativas)+1 ?> de <?= $MAX_TENTATIVAS ?></div>
  <div class="qse-pips" id="qsePips">
    <?php for($i=0;$i<$MAX_TENTATIVAS;$i++): ?>
    <div class="qse-pip<?= $i < count($tentativas) ? ' used' : '' ?>" data-pip="<?= $i ?>"></div>
    <?php endfor; ?>
  </div>
</div>

<!-- Silhouette card -->
<div class="qse-card" id="qseCard">
  <div class="qse-sil" id="qseSil">
    <div class="qse-sil-q" id="qseQ">?</div>
  </div>
  <div class="qse-result-msg" id="qseResultMsg"></div>
  <div class="qse-points-badge" id="qsePointsBadge"></div>
</div>

<!-- Legend -->
<div class="qse-legend">
  <span><div class="qse-legend-dot" style="background:rgba(34,197,94,.15);border:1px solid rgba(34,197,94,.4)"></div>Correto</span>
  <span><div class="qse-legend-dot" style="background:var(--panel-2);border:1px solid var(--border-md)"></div>Errado &nbsp;↑ maior &nbsp;↓ menor</span>
</div>

<!-- Column headers -->
<div class="qse-cols">
  <div class="qse-col-lbl">Jogador</div>
  <div class="qse-col-lbl">Time</div>
  <div class="qse-col-lbl">Pos</div>
  <div class="qse-col-lbl">Altura</div>
  <div class="qse-col-lbl">Idade</div>
  <div class="qse-col-lbl">#</div>
</div>

<!-- Guess rows -->
<div class="qse-rows" id="qseRows"></div>

<!-- Input -->
<div class="qse-input-wrap">
  <input type="text" id="qseInput" class="qse-input" placeholder="Digite o nome do jogador..." autocomplete="off" <?= $jogo_fim ? 'disabled' : '' ?>>
  <div class="qse-suggestions" id="qseSuggestions"></div>
</div>
<button class="qse-share-btn" id="qseShareBtn" onclick="qseShare()"><i class="bi bi-share-fill" style="margin-right:6px"></i>Compartilhar resultado</button>

</div><!-- /qse-page -->

<script>
const QSE_PLAYERS   = <?= json_encode($jsPlayers, JSON_UNESCAPED_UNICODE) ?>;
const MAX_TENT      = <?= $MAX_TENTATIVAS ?>;
const GAME_DONE     = <?= $jogo_fim ? 'true' : 'false' ?>;
const GAME_WIN      = <?= $jogo_resolvido ? 'true' : 'false' ?>;
const INITIAL_STATE = <?= json_encode($tentativas, JSON_UNESCAPED_UNICODE) ?>;
const INITIAL_PONTOS= <?= (int)($partida['pontos_ganhos'] ?? 0) ?>;
const TARGET_TEAM   = <?= $jogo_fim ? json_encode($targetPlayer['t']) : 'null' ?>;
const TARGET_NAME   = <?= $jogo_fim ? json_encode($targetPlayer['n'], JSON_UNESCAPED_UNICODE) : 'null' ?>;

// State
let tentativas   = [...INITIAL_STATE];
let gameDone     = GAME_DONE;
let gameWin      = GAME_WIN;
let selectedId   = null;

// ── Logo helper ─────────────────────────────────────────────────────────────
function teamLogo(abbr) {
  return `https://a.espncdn.com/i/teamlogos/nba/500/${abbr.toLowerCase()}.png`;
}

// ── Height helper ────────────────────────────────────────────────────────────
function fmtH(inches) {
  return Math.floor(inches/12) + "'" + (inches%12) + '"';
}

// ── Render a guess row ───────────────────────────────────────────────────────
function makeRow(t) {
  const arrow = {up:'↑', down:'↓', correct:'', wrong:''};
  const cls   = r => r === 'correct' ? 'correct' : (r === 'up' ? 'up' : (r === 'down' ? 'down' : 'wrong'));

  const teamCell = `
    <div class="qse-cell ${cls(t.t.r)}">
      <img class="qse-cell-logo" src="${teamLogo(t.t.val)}" onerror="this.style.display='none';this.nextSibling.style.display='block'">
      <span style="display:none;font-size:10px">${t.t.val}</span>
      ${t.t.r !== 'correct' ? '' : ''}
    </div>`;

  const posCell = `<div class="qse-cell ${cls(t.pos.r)}">${t.pos.val}</div>`;

  const hCell = `
    <div class="qse-cell ${cls(t.h.r)}">
      <span>${fmtH(t.h.val)}</span>
      ${t.h.r !== 'correct' ? `<span class="qse-cell-arrow">${arrow[t.h.r]}</span>` : ''}
    </div>`;

  const ageCell = `
    <div class="qse-cell ${cls(t.age.r)}">
      <span>${t.age.val}</span>
      ${t.age.r !== 'correct' ? `<span class="qse-cell-arrow">${arrow[t.age.r]}</span>` : ''}
    </div>`;

  const numCell = `
    <div class="qse-cell ${cls(t.num.r)}">
      <span>${t.num.val}</span>
      ${t.num.r !== 'correct' ? `<span class="qse-cell-arrow">${arrow[t.num.r]}</span>` : ''}
    </div>`;

  const row = document.createElement('div');
  row.className = 'qse-row';
  row.innerHTML = `<div class="qse-row-name">${t.n}</div>${teamCell}${posCell}${hCell}${ageCell}${numCell}`;
  return row;
}

// ── Render all rows ──────────────────────────────────────────────────────────
function renderRows() {
  const container = document.getElementById('qseRows');
  container.innerHTML = '';
  tentativas.forEach(t => container.appendChild(makeRow(t)));
}

// ── Update counter + pips ────────────────────────────────────────────────────
function updateCounter() {
  const el = document.getElementById('qseCounter');
  if (gameDone) {
    el.style.display = 'none';
  } else {
    el.textContent = `Tentativa ${tentativas.length + 1} de ${MAX_TENT}`;
  }
  // pips
  document.querySelectorAll('.qse-pip').forEach((pip, i) => {
    pip.classList.remove('used','win','lose');
    if (i < tentativas.length) pip.classList.add(gameDone && i === tentativas.length - 1 ? (gameWin ? 'win' : 'lose') : 'used');
  });
}

// ── Show finish state ────────────────────────────────────────────────────────
function showFinish(win, pontos, teamAbbr, playerName) {
  gameDone = true; gameWin = win;
  document.getElementById('qseInput').disabled = true;
  document.getElementById('qseCounter').style.display = 'none';

  // Card state
  const card = document.getElementById('qseCard');
  card.classList.add(win ? 'solved' : 'failed');

  // Pips final state
  document.querySelectorAll('.qse-pip').forEach((pip, i) => {
    pip.classList.remove('used','win','lose');
    if (i < tentativas.length - 1) pip.classList.add('used');
    else if (i === tentativas.length - 1) pip.classList.add(win ? 'win' : 'lose');
  });

  // Silhouette
  const sil = document.getElementById('qseSil');
  const q   = document.getElementById('qseQ');
  if (win) {
    if (teamAbbr) {
      const img = document.createElement('img');
      img.className = 'qse-sil-img';
      img.src = teamLogo(teamAbbr);
      img.style.cssText = 'display:block;border-radius:0;width:76%;height:76%;object-fit:contain;padding:6px';
      sil.appendChild(img);
      q.style.display = 'none';
      sil.classList.add('solved');
    }
  }

  const msg = document.getElementById('qseResultMsg');
  msg.style.display = 'block';
  if (win) {
    msg.className = 'qse-result-msg win';
    msg.textContent = '🏀 Acertou! ' + (playerName || '');
    if (pontos > 0) {
      const pb = document.getElementById('qsePointsBadge');
      pb.style.display = 'block';
      pb.textContent = `+${pontos} pontos`;
    }
  } else {
    msg.className = 'qse-result-msg lose';
    msg.textContent = '😔 Era ' + (playerName || 'o jogador de hoje');
  }

  document.getElementById('qseShareBtn').style.display = 'inline-flex';
}

// ── Autocomplete ─────────────────────────────────────────────────────────────
const input    = document.getElementById('qseInput');
const sugBox   = document.getElementById('qseSuggestions');
const guessedIds = new Set(tentativas.map(t => t.id));
let activeIdx  = -1;

function highlight(text, query) {
  const i = text.toLowerCase().indexOf(query.toLowerCase());
  if (i < 0) return text;
  return text.slice(0,i) + '<em>' + text.slice(i, i+query.length) + '</em>' + text.slice(i+query.length);
}

function showSuggestions(query) {
  if (!query || query.length < 2) { sugBox.style.display = 'none'; return; }
  const matches = QSE_PLAYERS
    .filter(p => !guessedIds.has(p.id) && p.n.toLowerCase().includes(query.toLowerCase()))
    .slice(0, 8);
  if (!matches.length) { sugBox.style.display = 'none'; return; }
  sugBox.innerHTML = matches.map((p,i) =>
    `<div class="qse-sug-item" data-id="${p.id}" data-idx="${i}">${highlight(p.n, query)}</div>`
  ).join('');
  sugBox.style.display = 'block';
  activeIdx = -1;

  sugBox.querySelectorAll('.qse-sug-item').forEach(el => {
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
  const items = sugBox.querySelectorAll('.qse-sug-item');
  if (e.key === 'ArrowDown') {
    e.preventDefault();
    activeIdx = Math.min(activeIdx + 1, items.length - 1);
    items.forEach((el, i) => el.classList.toggle('active', i === activeIdx));
    return;
  }
  if (e.key === 'ArrowUp') {
    e.preventDefault();
    activeIdx = Math.max(activeIdx - 1, 0);
    items.forEach((el, i) => el.classList.toggle('active', i === activeIdx));
    return;
  }
  if (e.key === 'Enter') {
    e.preventDefault();
    const sugVisible = sugBox.style.display !== 'none' && items.length > 0;
    if (sugVisible) {
      // Seleciona da lista (1º Enter = selecionar, 2º Enter = confirmar)
      const target = activeIdx >= 0 ? items[activeIdx] : items[0];
      selectPlayer(+target.dataset.id, target.textContent);
    } else if (selectedId) {
      submitGuess();
    }
    return;
  }
  if (e.key === 'Escape') { sugBox.style.display = 'none'; }
});
document.addEventListener('click', e => { if (!e.target.closest('.qse-input-wrap')) sugBox.style.display = 'none'; });

// ── Submit guess ─────────────────────────────────────────────────────────────
async function submitGuess() {
  if (!selectedId || gameDone) return;
  input.disabled = true;

  const fd = new FormData();
  fd.append('action', 'palpite');
  fd.append('player_id', selectedId);

  try {
    const res  = await fetch(window.location.href, {method:'POST', body:fd});
    const data = await res.json();

    if (!data.ok) {
      alert(data.msg || 'Erro');
      input.disabled = false;
      return;
    }

    tentativas.push(data.result);
    guessedIds.add(data.result.id);
    renderRows();
    updateCounter();

    input.value = '';
    selectedId  = null;
    input.disabled = false;

    if (data.fim) {
      showFinish(data.acertou, data.pontos, data.target_team, data.jogador_real);
    }
  } catch(err) {
    input.disabled = false;
    alert('Erro ao enviar palpite');
  }
}


// ── Share ────────────────────────────────────────────────────────────────────
function qseShare() {
  const emojiMap = {correct:'🟩', wrong:'⬜', up:'🟨', down:'🟦'};
  const lines = [`🏀 Quem Sou Eu? NBA — ${new Date().toLocaleDateString('pt-BR')}`];
  lines.push(`${gameWin ? '✅' : '❌'} ${tentativas.length}/${MAX_TENT} tentativas`);
  lines.push('');
  tentativas.forEach(t => {
    const row = [t.t.r, t.pos.r, t.h.r, t.age.r, t.num.r].map(r=>emojiMap[r]||'⬜').join('');
    lines.push(row);
  });
  const text = lines.join('\n');
  navigator.clipboard.writeText(text).then(() => alert('Resultado copiado!')).catch(() => prompt('Copie:', text));
}

// ── Init ─────────────────────────────────────────────────────────────────────
renderRows();
updateCounter();

if (GAME_DONE) {
  showFinish(GAME_WIN, INITIAL_PONTOS, TARGET_TEAM, TARGET_NAME);
} else {
  input.focus();
}
</script>
</body>
</html>
