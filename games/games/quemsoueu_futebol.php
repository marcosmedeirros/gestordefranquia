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

// ─── DATASET ────────────────────────────────────────────────────────────────
// n=nome, clube=clube atual/mais famoso, pais=nacionalidade, flag=ISO2,
// pos=posição (GOL/ZAG/LAT/VOL/MEI/ATA), age=idade, gols=gols na carreira (clube)
$QSE_PLAYERS = [
    // ── Ativos internacionais ────────────────────────────────────────────────
    ['id'=>1,  'n'=>'Lionel Messi',         'clube'=>'Inter Miami',      'pais'=>'Argentina',    'flag'=>'ar','pos'=>'ATA','age'=>37,'gols'=>740],
    ['id'=>2,  'n'=>'Cristiano Ronaldo',    'clube'=>'Al Nassr',         'pais'=>'Portugal',     'flag'=>'pt','pos'=>'ATA','age'=>40,'gols'=>750],
    ['id'=>3,  'n'=>'Kylian Mbappé',        'clube'=>'Real Madrid',      'pais'=>'França',       'flag'=>'fr','pos'=>'ATA','age'=>26,'gols'=>305],
    ['id'=>4,  'n'=>'Erling Haaland',       'clube'=>'Man. City',        'pais'=>'Noruega',      'flag'=>'no','pos'=>'ATA','age'=>24,'gols'=>230],
    ['id'=>5,  'n'=>'Mohamed Salah',        'clube'=>'Liverpool',        'pais'=>'Egito',        'flag'=>'eg','pos'=>'ATA','age'=>32,'gols'=>240],
    ['id'=>6,  'n'=>'Karim Benzema',        'clube'=>'Al Ittihad',       'pais'=>'França',       'flag'=>'fr','pos'=>'ATA','age'=>37,'gols'=>432],
    ['id'=>7,  'n'=>'Robert Lewandowski',   'clube'=>'Barcelona',        'pais'=>'Polônia',      'flag'=>'pl','pos'=>'ATA','age'=>36,'gols'=>635],
    ['id'=>8,  'n'=>'Jude Bellingham',      'clube'=>'Real Madrid',      'pais'=>'Inglaterra',   'flag'=>'gb','pos'=>'MEI','age'=>21,'gols'=>64],
    ['id'=>9,  'n'=>'Pedri',                'clube'=>'Barcelona',        'pais'=>'Espanha',      'flag'=>'es','pos'=>'MEI','age'=>22,'gols'=>26],
    ['id'=>10, 'n'=>'Luka Modric',          'clube'=>'Real Madrid',      'pais'=>'Croácia',      'flag'=>'hr','pos'=>'MEI','age'=>39,'gols'=>65],
    ['id'=>11, 'n'=>'Kevin De Bruyne',      'clube'=>'Man. City',        'pais'=>'Bélgica',      'flag'=>'be','pos'=>'MEI','age'=>33,'gols'=>115],
    ['id'=>12, 'n'=>'Toni Kroos',           'clube'=>'Real Madrid',      'pais'=>'Alemanha',     'flag'=>'de','pos'=>'MEI','age'=>34,'gols'=>85],
    ['id'=>13, 'n'=>'Virgil van Dijk',      'clube'=>'Liverpool',        'pais'=>'Holanda',      'flag'=>'nl','pos'=>'ZAG','age'=>33,'gols'=>44],
    ['id'=>14, 'n'=>'Thibaut Courtois',     'clube'=>'Real Madrid',      'pais'=>'Bélgica',      'flag'=>'be','pos'=>'GOL','age'=>32,'gols'=>0],
    ['id'=>15, 'n'=>'Manuel Neuer',         'clube'=>'Bayern Munich',    'pais'=>'Alemanha',     'flag'=>'de','pos'=>'GOL','age'=>38,'gols'=>0],
    ['id'=>16, 'n'=>'Harry Kane',           'clube'=>'Bayern Munich',    'pais'=>'Inglaterra',   'flag'=>'gb','pos'=>'ATA','age'=>31,'gols'=>345],
    ['id'=>17, 'n'=>'Rodri',               'clube'=>'Man. City',        'pais'=>'Espanha',      'flag'=>'es','pos'=>'VOL','age'=>28,'gols'=>29],
    ['id'=>18, 'n'=>'Sergio Ramos',         'clube'=>'Sevilla',          'pais'=>'Espanha',      'flag'=>'es','pos'=>'ZAG','age'=>38,'gols'=>105],

    // ── Ativos brasileiros ───────────────────────────────────────────────────
    ['id'=>20, 'n'=>'Vinicius Jr.',         'clube'=>'Real Madrid',      'pais'=>'Brasil',       'flag'=>'br','pos'=>'ATA','age'=>24,'gols'=>98],
    ['id'=>21, 'n'=>'Rodrygo',              'clube'=>'Real Madrid',      'pais'=>'Brasil',       'flag'=>'br','pos'=>'ATA','age'=>24,'gols'=>74],
    ['id'=>22, 'n'=>'Neymar Jr.',           'clube'=>'Al Hilal',         'pais'=>'Brasil',       'flag'=>'br','pos'=>'ATA','age'=>33,'gols'=>310],
    ['id'=>23, 'n'=>'Alisson Becker',       'clube'=>'Liverpool',        'pais'=>'Brasil',       'flag'=>'br','pos'=>'GOL','age'=>32,'gols'=>0],
    ['id'=>24, 'n'=>'Ederson',              'clube'=>'Man. City',        'pais'=>'Brasil',       'flag'=>'br','pos'=>'GOL','age'=>31,'gols'=>0],
    ['id'=>25, 'n'=>'Marquinhos',           'clube'=>'PSG',              'pais'=>'Brasil',       'flag'=>'br','pos'=>'ZAG','age'=>30,'gols'=>38],
    ['id'=>26, 'n'=>'Thiago Silva',         'clube'=>'Fluminense',       'pais'=>'Brasil',       'flag'=>'br','pos'=>'ZAG','age'=>40,'gols'=>48],
    ['id'=>27, 'n'=>'Casemiro',             'clube'=>'Man. United',      'pais'=>'Brasil',       'flag'=>'br','pos'=>'VOL','age'=>32,'gols'=>38],
    ['id'=>28, 'n'=>'Gabriel Barbosa',      'clube'=>'Flamengo',         'pais'=>'Brasil',       'flag'=>'br','pos'=>'ATA','age'=>28,'gols'=>195],
    ['id'=>29, 'n'=>'Pedro',                'clube'=>'Flamengo',         'pais'=>'Brasil',       'flag'=>'br','pos'=>'ATA','age'=>27,'gols'=>98],
    ['id'=>30, 'n'=>'Richarlison',          'clube'=>'Tottenham',        'pais'=>'Brasil',       'flag'=>'br','pos'=>'ATA','age'=>27,'gols'=>112],
    ['id'=>31, 'n'=>'Gabriel Jesus',        'clube'=>'Arsenal',          'pais'=>'Brasil',       'flag'=>'br','pos'=>'ATA','age'=>27,'gols'=>115],
    ['id'=>32, 'n'=>'Endrick',              'clube'=>'Real Madrid',      'pais'=>'Brasil',       'flag'=>'br','pos'=>'ATA','age'=>18,'gols'=>42],
    ['id'=>33, 'n'=>'Bruno Guimarães',      'clube'=>'Newcastle',        'pais'=>'Brasil',       'flag'=>'br','pos'=>'VOL','age'=>27,'gols'=>30],
    ['id'=>34, 'n'=>'Raphinha',             'clube'=>'Barcelona',        'pais'=>'Brasil',       'flag'=>'br','pos'=>'ATA','age'=>28,'gols'=>86],

    // ── Lendas internacionais ────────────────────────────────────────────────
    ['id'=>40, 'n'=>'Zinedine Zidane',      'clube'=>'Real Madrid',      'pais'=>'França',       'flag'=>'fr','pos'=>'MEI','age'=>52,'gols'=>125],
    ['id'=>41, 'n'=>'Thierry Henry',        'clube'=>'Arsenal',          'pais'=>'França',       'flag'=>'fr','pos'=>'ATA','age'=>47,'gols'=>411],
    ['id'=>42, 'n'=>'David Beckham',        'clube'=>'Man. United',      'pais'=>'Inglaterra',   'flag'=>'gb','pos'=>'MEI','age'=>49,'gols'=>130],
    ['id'=>43, 'n'=>'Ronaldo Nazário',      'clube'=>'Real Madrid',      'pais'=>'Brasil',       'flag'=>'br','pos'=>'ATA','age'=>47,'gols'=>352],
    ['id'=>44, 'n'=>'Diego Maradona',       'clube'=>'Napoli',           'pais'=>'Argentina',    'flag'=>'ar','pos'=>'MEI','age'=>60,'gols'=>312],
    ['id'=>45, 'n'=>'Johan Cruyff',         'clube'=>'Barcelona',        'pais'=>'Holanda',      'flag'=>'nl','pos'=>'ATA','age'=>68,'gols'=>291],
    ['id'=>46, 'n'=>'Ronaldinho',           'clube'=>'Barcelona',        'pais'=>'Brasil',       'flag'=>'br','pos'=>'ATA','age'=>44,'gols'=>198],
    ['id'=>47, 'n'=>'Pelé',                 'clube'=>'Santos',           'pais'=>'Brasil',       'flag'=>'br','pos'=>'ATA','age'=>82,'gols'=>643],
    ['id'=>48, 'n'=>'Romário',              'clube'=>'Barcelona',        'pais'=>'Brasil',       'flag'=>'br','pos'=>'ATA','age'=>58,'gols'=>772],
    ['id'=>49, 'n'=>'Cafu',                 'clube'=>'Roma',             'pais'=>'Brasil',       'flag'=>'br','pos'=>'LAT','age'=>54,'gols'=>29],
    ['id'=>50, 'n'=>'Roberto Carlos',       'clube'=>'Real Madrid',      'pais'=>'Brasil',       'flag'=>'br','pos'=>'LAT','age'=>51,'gols'=>103],
    ['id'=>51, 'n'=>'Rivaldo',              'clube'=>'Barcelona',        'pais'=>'Brasil',       'flag'=>'br','pos'=>'MEI','age'=>52,'gols'=>340],
    ['id'=>52, 'n'=>'Ronaldo de Lima',      'clube'=>'Inter de Milão',   'pais'=>'Brasil',       'flag'=>'br','pos'=>'ATA','age'=>47,'gols'=>247],
    ['id'=>53, 'n'=>'Kaká',                 'clube'=>'AC Milan',         'pais'=>'Brasil',       'flag'=>'br','pos'=>'MEI','age'=>42,'gols'=>194],
    ['id'=>54, 'n'=>'Bebeto',               'clube'=>'Vasco',            'pais'=>'Brasil',       'flag'=>'br','pos'=>'ATA','age'=>60,'gols'=>295],
    ['id'=>55, 'n'=>'Zico',                 'clube'=>'Flamengo',         'pais'=>'Brasil',       'flag'=>'br','pos'=>'MEI','age'=>71,'gols'=>508],
    ['id'=>56, 'n'=>'Garrincha',            'clube'=>'Botafogo',         'pais'=>'Brasil',       'flag'=>'br','pos'=>'ATA','age'=>49,'gols'=>232],
    ['id'=>57, 'n'=>'Xavi Hernández',       'clube'=>'Barcelona',        'pais'=>'Espanha',      'flag'=>'es','pos'=>'MEI','age'=>44,'gols'=>85],
    ['id'=>58, 'n'=>'Andrés Iniesta',       'clube'=>'Barcelona',        'pais'=>'Espanha',      'flag'=>'es','pos'=>'MEI','age'=>40,'gols'=>57],
    ['id'=>59, 'n'=>'Ronaldo (R9)',         'clube'=>'Barcelona',        'pais'=>'Brasil',       'flag'=>'br','pos'=>'ATA','age'=>47,'gols'=>247],
    ['id'=>60, 'n'=>'Paolo Maldini',        'clube'=>'AC Milan',         'pais'=>'Itália',       'flag'=>'it','pos'=>'ZAG','age'=>56,'gols'=>33],
    ['id'=>61, 'n'=>'Ronaldinho Gaúcho',    'clube'=>'PSG',              'pais'=>'Brasil',       'flag'=>'br','pos'=>'ATA','age'=>44,'gols'=>198],
    ['id'=>62, 'n'=>'Gianluigi Buffon',     'clube'=>'Juventus',         'pais'=>'Itália',       'flag'=>'it','pos'=>'GOL','age'=>46,'gols'=>0],
    ['id'=>63, 'n'=>'Filippo Inzaghi',      'clube'=>'AC Milan',         'pais'=>'Itália',       'flag'=>'it','pos'=>'ATA','age'=>51,'gols'=>317],
    ['id'=>64, 'n'=>'Raúl',                 'clube'=>'Real Madrid',      'pais'=>'Espanha',      'flag'=>'es','pos'=>'ATA','age'=>47,'gols'=>323],
    ['id'=>65, 'n'=>'Roberto Baggio',       'clube'=>'Juventus',         'pais'=>'Itália',       'flag'=>'it','pos'=>'ATA','age'=>57,'gols'=>318],
];

$playerById = [];
foreach ($QSE_PLAYERS as $p) { $playerById[$p['id']] = $p; }

// Comparação: retorna 'correct' | 'up' | 'down' | 'wrong'
function qsf_cmp($guessed, $target): string {
    if ($guessed === $target) return 'correct';
    if (is_int($guessed) && is_int($target)) return $target > $guessed ? 'up' : 'down';
    return 'wrong';
}

// Jogador do dia (mesmo para todos)
$dayHash      = abs(crc32($hoje . 'qsf_futebol'));
$targetPlayer = $QSE_PLAYERS[$dayHash % count($QSE_PLAYERS)];

// Criar tabela
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS quemsoueu_futebol (
        id            INT AUTO_INCREMENT PRIMARY KEY,
        id_usuario    INT NOT NULL,
        data_jogo     DATE NOT NULL,
        tentativas    INT DEFAULT 0,
        resolvido     TINYINT DEFAULT 0,
        pontos_ganhos INT DEFAULT 0,
        tentativas_json TEXT DEFAULT '[]',
        jogador_id    INT NOT NULL,
        concluido_em  DATETIME DEFAULT NULL,
        UNIQUE KEY uk_qsf_user_date (id_usuario, data_jogo)
    ) DEFAULT CHARSET=utf8mb4");
} catch (PDOException $e) {}

// Carregar/criar partida de hoje
$partida = null;
try {
    $stmt = $pdo->prepare("SELECT * FROM quemsoueu_futebol WHERE id_usuario = ? AND data_jogo = ?");
    $stmt->execute([$user_id, $hoje]);
    $partida = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$partida) {
        $pdo->prepare("INSERT INTO quemsoueu_futebol (id_usuario, data_jogo, jogador_id, tentativas_json) VALUES (?,?,?,'[]')")
            ->execute([$user_id, $hoje, $targetPlayer['id']]);
        $partida = ['tentativas'=>0,'resolvido'=>0,'pontos_ganhos'=>0,'tentativas_json'=>'[]','concluido_em'=>null];
    }
} catch (PDOException $e) {
    $partida = ['tentativas'=>0,'resolvido'=>0,'pontos_ganhos'=>0,'tentativas_json'=>'[]','concluido_em'=>null];
}

$tentativas     = json_decode($partida['tentativas_json'] ?: '[]', true) ?: [];
$jogo_resolvido = (bool)$partida['resolvido'];
$jogo_fim       = $jogo_resolvido || count($tentativas) >= $MAX_TENTATIVAS;

// ─── AJAX: processar palpite ─────────────────────────────────────────────────
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
        'id'    => $guessedId,
        'n'     => $guessedPlayer['n'],
        'clube' => ['val'=>$guessedPlayer['clube'], 'r'=>qsf_cmp($guessedPlayer['clube'],  $targetPlayer['clube'])],
        'pais'  => ['val'=>$guessedPlayer['pais'],  'flag'=>$guessedPlayer['flag'], 'r'=>qsf_cmp($guessedPlayer['pais'],   $targetPlayer['pais'])],
        'pos'   => ['val'=>$guessedPlayer['pos'],   'r'=>qsf_cmp($guessedPlayer['pos'],    $targetPlayer['pos'])],
        'age'   => ['val'=>$guessedPlayer['age'],   'r'=>qsf_cmp($guessedPlayer['age'],    $targetPlayer['age'])],
        'gols'  => ['val'=>$guessedPlayer['gols'],  'r'=>qsf_cmp($guessedPlayer['gols'],   $targetPlayer['gols'])],
    ];

    $tentativas[] = $result;
    $acertou      = ($guessedId === $targetPlayer['id']);
    $fim          = $acertou || count($tentativas) >= $MAX_TENTATIVAS;
    $pontosGanhos = $acertou ? 200 : 0;

    try {
        $pdo->prepare("UPDATE quemsoueu_futebol
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
        'jogador_real'=> $fim ? $targetPlayer['n']    : null,
        'target_flag' => $fim ? $targetPlayer['flag'] : null,
        'target_pais' => $fim ? $targetPlayer['pais'] : null,
    ]);
    exit;
}

$jsPlayers = array_map(fn($p) => ['id'=>$p['id'],'n'=>$p['n']], $QSE_PLAYERS);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Quem Sou Eu? Futebol — FBA Games</title>
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
.qsf-page{display:flex;flex-direction:column;align-items:center;padding:20px 16px 64px}

/* ── Attempt pips ── */
.qsf-counter-row{width:100%;max-width:540px;display:flex;align-items:center;justify-content:space-between;margin-bottom:14px}
.qsf-guess-count{font-size:11px;font-weight:700;letter-spacing:1.2px;text-transform:uppercase;color:var(--text-3)}
.qsf-pips{display:flex;gap:5px}
.qsf-pip{width:10px;height:10px;border-radius:50%;background:var(--panel-3);border:1px solid var(--border-md);transition:all .3s}
.qsf-pip.used{background:var(--text-3)}
.qsf-pip.win{background:var(--green);box-shadow:0 0 6px rgba(34,197,94,.5)}
.qsf-pip.lose{background:var(--red);box-shadow:0 0 6px rgba(252,0,37,.4)}

/* ── Hero card ── */
.qsf-card{background:var(--panel);border:1px solid var(--border);border-radius:var(--radius);
          padding:20px;width:100%;max-width:540px;display:flex;flex-direction:column;align-items:center;
          gap:10px;margin-bottom:16px;transition:border-color .4s}
.qsf-card.solved{border-color:rgba(34,197,94,.35)}
.qsf-card.failed{border-color:var(--border-red)}
.qsf-ball{width:88px;height:88px;border-radius:50%;background:var(--panel-2);border:2px solid var(--border-md);
          display:flex;align-items:center;justify-content:center;font-size:48px;transition:all .4s;position:relative}
.qsf-ball.solved{border-color:var(--green);box-shadow:0 0 20px rgba(34,197,94,.25)}
.qsf-result-msg{font-size:16px;font-weight:700;text-align:center;display:none}
.qsf-result-msg.win{color:var(--green)}
.qsf-result-msg.lose{color:#ff6680}
.qsf-points-badge{background:rgba(34,197,94,.1);border:1px solid rgba(34,197,94,.25);color:var(--green);
                  border-radius:999px;padding:4px 14px;font-size:12px;font-weight:700;display:none}

/* ── Legend ── */
.qsf-legend{display:flex;gap:14px;flex-wrap:wrap;justify-content:center;margin-bottom:12px;width:100%;max-width:540px}
.qsf-legend span{display:flex;align-items:center;gap:5px;font-size:10px;font-weight:600;color:var(--text-3)}
.qsf-legend-dot{width:9px;height:9px;border-radius:3px;flex-shrink:0}

/* ── Column headers ── */
.qsf-cols{display:grid;grid-template-columns:1fr 90px 72px 46px 46px 52px;gap:5px;
          width:100%;max-width:540px;padding:0 2px;margin-bottom:4px}
.qsf-col-lbl{text-align:center;font-size:9px;font-weight:700;letter-spacing:.8px;text-transform:uppercase;color:var(--text-3)}
.qsf-col-lbl:first-child{text-align:left}

/* ── Guess rows ── */
.qsf-rows{width:100%;max-width:540px;display:flex;flex-direction:column;gap:5px;margin-bottom:16px}
.qsf-row{display:grid;grid-template-columns:1fr 90px 72px 46px 46px 52px;gap:5px;animation:rowIn .3s ease both}
@keyframes rowIn{from{opacity:0;transform:translateY(-6px)}to{opacity:1;transform:translateY(0)}}
.qsf-row-name{background:var(--panel-2);border:1px solid var(--border-md);border-radius:9px;
              padding:8px 10px;font-size:11px;font-weight:600;color:var(--text);
              white-space:nowrap;overflow:hidden;text-overflow:ellipsis;display:flex;align-items:center}
.qsf-cell{border-radius:9px;display:flex;flex-direction:column;align-items:center;justify-content:center;
          padding:5px 3px;min-height:50px;font-size:9px;font-weight:700;gap:1px;transition:background .2s;text-align:center;line-height:1.3}
.qsf-cell.correct{background:rgba(34,197,94,.12);border:1px solid rgba(34,197,94,.35);color:var(--green)}
.qsf-cell.up,.qsf-cell.down{background:var(--panel-2);border:1px solid var(--border-md);color:var(--text-2)}
.qsf-cell.wrong{background:var(--panel-2);border:1px solid var(--border);color:var(--text-3)}
.qsf-cell-arrow{font-size:12px;line-height:1}
.qsf-cell.up .qsf-cell-arrow{color:var(--amber)}
.qsf-cell.down .qsf-cell-arrow{color:var(--purple)}
.qsf-cell-flag{font-size:16px;line-height:1}
.qsf-cell-val{font-size:9px;font-weight:700;word-break:break-word;max-width:100%}

/* ── Input area ── */
.qsf-input-wrap{width:100%;max-width:540px;position:relative;margin-bottom:8px}
.qsf-input{width:100%;background:var(--panel-2);border:1.5px solid var(--border-red);border-radius:var(--radius-sm);
           padding:12px 16px;font-size:14px;color:var(--text);outline:none;
           transition:border-color var(--t) var(--ease);font-family:var(--font)}
.qsf-input:disabled{opacity:.35;cursor:not-allowed;border-color:var(--border)}
.qsf-input::placeholder{color:var(--text-3)}
.qsf-input:focus{border-color:var(--red)}
.qsf-suggestions{position:absolute;top:calc(100% + 4px);left:0;right:0;background:var(--panel);
                 border:1px solid var(--border-md);border-radius:var(--radius-sm);overflow:hidden;
                 z-index:100;display:none;max-height:220px;overflow-y:auto;box-shadow:0 8px 24px rgba(0,0,0,.5)}
.qsf-sug-item{padding:10px 16px;font-size:13px;cursor:pointer;transition:background .15s;
              border-bottom:1px solid var(--border);font-family:var(--font)}
.qsf-sug-item:last-child{border-bottom:none}
.qsf-sug-item:hover,.qsf-sug-item.active{background:var(--panel-2);color:var(--red)}
.qsf-sug-item em{color:var(--red);font-style:normal;font-weight:700}

/* ── Share btn ── */
.qsf-share-btn{display:none;background:var(--panel-2);border:1px solid var(--border-md);border-radius:var(--radius-sm);
               padding:9px 22px;font-size:12px;font-weight:600;color:var(--text-2);cursor:pointer;
               transition:all var(--t) var(--ease);font-family:var(--font);margin-top:4px}
.qsf-share-btn:hover{border-color:var(--border-red);color:var(--red)}

@media(max-width:480px){
  .qsf-cols,.qsf-row{grid-template-columns:1fr 76px 62px 40px 40px 46px;gap:4px}
  .qsf-row-name{font-size:9px;padding:6px 8px}
  .qsf-cell{min-height:46px;font-size:8px}
}
</style>
</head>
<body>

<div class="topbar">
  <a href="../games.php" class="topbar-back"><i class="bi bi-arrow-left"></i></a>
  <div class="topbar-title">Quem Sou Eu? <span>Futebol</span></div>
  <div class="topbar-chip"><i class="bi bi-calendar3"></i><?= date('d/m/Y') ?></div>
</div>

<div class="qsf-page">

<!-- Pips -->
<div class="qsf-counter-row">
  <div class="qsf-guess-count" id="qsfCounter">Tentativa <?= count($tentativas)+1 ?> de <?= $MAX_TENTATIVAS ?></div>
  <div class="qsf-pips" id="qsfPips">
    <?php for($i=0;$i<$MAX_TENTATIVAS;$i++): ?>
    <div class="qsf-pip<?= $i < count($tentativas) ? ' used' : '' ?>" data-pip="<?= $i ?>"></div>
    <?php endfor; ?>
  </div>
</div>

<!-- Card principal -->
<div class="qsf-card" id="qsfCard">
  <div class="qsf-ball" id="qsfBall">⚽</div>
  <div class="qsf-result-msg" id="qsfResultMsg"></div>
  <div class="qsf-points-badge" id="qsfPointsBadge"></div>
</div>

<!-- Legenda -->
<div class="qsf-legend">
  <span><div class="qsf-legend-dot" style="background:rgba(34,197,94,.15);border:1px solid rgba(34,197,94,.4)"></div>Correto</span>
  <span><div class="qsf-legend-dot" style="background:var(--panel-2);border:1px solid var(--border-md)"></div>Errado &nbsp;↑ maior &nbsp;↓ menor</span>
</div>

<!-- Cabeçalho colunas -->
<div class="qsf-cols">
  <div class="qsf-col-lbl">Jogador</div>
  <div class="qsf-col-lbl">Clube</div>
  <div class="qsf-col-lbl">País</div>
  <div class="qsf-col-lbl">Pos</div>
  <div class="qsf-col-lbl">Idade</div>
  <div class="qsf-col-lbl">Gols</div>
</div>

<!-- Linhas de tentativas -->
<div class="qsf-rows" id="qsfRows"></div>

<!-- Input -->
<div class="qsf-input-wrap">
  <input type="text" id="qsfInput" class="qsf-input" placeholder="Digite o nome do jogador..." autocomplete="off" <?= $jogo_fim ? 'disabled' : '' ?>>
  <div class="qsf-suggestions" id="qsfSuggestions"></div>
</div>
<button class="qsf-share-btn" id="qsfShareBtn" onclick="qsfShare()"><i class="bi bi-share-fill" style="margin-right:6px"></i>Compartilhar resultado</button>

</div><!-- /qsf-page -->

<script>
const QSF_PLAYERS   = <?= json_encode($jsPlayers, JSON_UNESCAPED_UNICODE) ?>;
const MAX_TENT      = <?= $MAX_TENTATIVAS ?>;
const GAME_DONE     = <?= $jogo_fim ? 'true' : 'false' ?>;
const GAME_WIN      = <?= $jogo_resolvido ? 'true' : 'false' ?>;
const INITIAL_STATE = <?= json_encode($tentativas, JSON_UNESCAPED_UNICODE) ?>;
const INITIAL_PONTOS= <?= (int)($partida['pontos_ganhos'] ?? 0) ?>;
const TARGET_FLAG   = <?= $jogo_fim ? json_encode($targetPlayer['flag']) : 'null' ?>;
const TARGET_PAIS   = <?= $jogo_fim ? json_encode($targetPlayer['pais'], JSON_UNESCAPED_UNICODE) : 'null' ?>;
const TARGET_NAME   = <?= $jogo_fim ? json_encode($targetPlayer['n'], JSON_UNESCAPED_UNICODE) : 'null' ?>;

let tentativas  = [...INITIAL_STATE];
let gameDone    = GAME_DONE;
let gameWin     = GAME_WIN;
let selectedId  = null;

// Converte ISO2 em emoji de bandeira
function flagEmoji(code) {
  if (!code) return '🏳';
  return [...code.toUpperCase()].map(c => String.fromCodePoint(127397 + c.charCodeAt(0))).join('');
}

// Renderiza uma linha de tentativa
function makeRow(t) {
  const arrow = {up:'↑', down:'↓', correct:'', wrong:''};
  const cls   = r => r === 'correct' ? 'correct' : r === 'up' ? 'up' : r === 'down' ? 'down' : 'wrong';

  // Clube — nome curto
  const clubeShort = t.clube.val.length > 10 ? t.clube.val.split(' ')[0] : t.clube.val;
  const clubeCell = `<div class="qsf-cell ${cls(t.clube.r)}">
    <span class="qsf-cell-val">${clubeShort}</span>
  </div>`;

  const paisCell = `<div class="qsf-cell ${cls(t.pais.r)}">
    <span class="qsf-cell-flag">${flagEmoji(t.pais.flag)}</span>
    <span class="qsf-cell-val" style="font-size:7px">${t.pais.val.split(' ')[0]}</span>
  </div>`;

  const posCell = `<div class="qsf-cell ${cls(t.pos.r)}">${t.pos.val}</div>`;

  const ageCell = `<div class="qsf-cell ${cls(t.age.r)}">
    <span>${t.age.val}</span>
    ${t.age.r !== 'correct' ? `<span class="qsf-cell-arrow">${arrow[t.age.r]}</span>` : ''}
  </div>`;

  const golsCell = `<div class="qsf-cell ${cls(t.gols.r)}">
    <span>${t.gols.val}</span>
    ${t.gols.r !== 'correct' ? `<span class="qsf-cell-arrow">${arrow[t.gols.r]}</span>` : ''}
  </div>`;

  const row = document.createElement('div');
  row.className = 'qsf-row';
  row.innerHTML = `<div class="qsf-row-name">${t.n}</div>${clubeCell}${paisCell}${posCell}${ageCell}${golsCell}`;
  return row;
}

function renderRows() {
  const c = document.getElementById('qsfRows');
  c.innerHTML = '';
  tentativas.forEach(t => c.appendChild(makeRow(t)));
}

function updateCounter() {
  const el = document.getElementById('qsfCounter');
  if (gameDone) { el.style.display = 'none'; return; }
  el.textContent = `Tentativa ${tentativas.length + 1} de ${MAX_TENT}`;
  document.querySelectorAll('.qsf-pip').forEach((pip, i) => {
    pip.classList.remove('used','win','lose');
    if (i < tentativas.length) pip.classList.add(
      gameDone && i === tentativas.length - 1 ? (gameWin ? 'win' : 'lose') : 'used'
    );
  });
}

function showFinish(win, pontos, flag, pais, playerName) {
  gameDone = true; gameWin = win;
  document.getElementById('qsfInput').disabled = true;
  document.getElementById('qsfCounter').style.display = 'none';

  const card = document.getElementById('qsfCard');
  card.classList.add(win ? 'solved' : 'failed');

  document.querySelectorAll('.qsf-pip').forEach((pip, i) => {
    pip.classList.remove('used','win','lose');
    if (i < tentativas.length - 1) pip.classList.add('used');
    else if (i === tentativas.length - 1) pip.classList.add(win ? 'win' : 'lose');
  });

  const ball = document.getElementById('qsfBall');
  if (win && flag) {
    ball.textContent = flagEmoji(flag);
    ball.style.fontSize = '52px';
    ball.classList.add('solved');
  }

  const msg = document.getElementById('qsfResultMsg');
  msg.style.display = 'block';
  if (win) {
    msg.className = 'qsf-result-msg win';
    msg.textContent = '⚽ Acertou! ' + (playerName || '');
    if (pontos > 0) {
      const pb = document.getElementById('qsfPointsBadge');
      pb.style.display = 'block';
      pb.textContent = `+${pontos} pontos`;
    }
  } else {
    msg.className = 'qsf-result-msg lose';
    msg.textContent = '😔 Era ' + (playerName || 'o jogador de hoje');
  }
  document.getElementById('qsfShareBtn').style.display = 'inline-flex';
}

// ── Autocomplete ─────────────────────────────────────────────────────────────
const input    = document.getElementById('qsfInput');
const sugBox   = document.getElementById('qsfSuggestions');
const guessedIds = new Set(tentativas.map(t => t.id));
let activeIdx  = -1;

function highlight(text, query) {
  const i = text.toLowerCase().indexOf(query.toLowerCase());
  if (i < 0) return text;
  return text.slice(0,i) + '<em>' + text.slice(i, i+query.length) + '</em>' + text.slice(i+query.length);
}

function showSuggestions(q) {
  if (!q || q.length < 2) { sugBox.style.display = 'none'; return; }
  const matches = QSF_PLAYERS
    .filter(p => !guessedIds.has(p.id) && p.n.toLowerCase().includes(q.toLowerCase()))
    .slice(0, 8);
  if (!matches.length) { sugBox.style.display = 'none'; return; }
  sugBox.innerHTML = matches.map((p,i) =>
    `<div class="qsf-sug-item" data-id="${p.id}" data-idx="${i}">${highlight(p.n, q)}</div>`
  ).join('');
  sugBox.style.display = 'block';
  activeIdx = -1;
  sugBox.querySelectorAll('.qsf-sug-item').forEach(el => {
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
  const items = sugBox.querySelectorAll('.qsf-sug-item');
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
document.addEventListener('click', e => { if (!e.target.closest('.qsf-input-wrap')) sugBox.style.display = 'none'; });

// ── Submit guess ──────────────────────────────────────────────────────────────
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
    input.value = '';
    selectedId  = null;
    input.disabled = false;

    if (data.fim) showFinish(data.acertou, data.pontos, data.target_flag, data.target_pais, data.jogador_real);
  } catch(err) { input.disabled = false; alert('Erro ao enviar palpite'); }
}

// ── Share ─────────────────────────────────────────────────────────────────────
function qsfShare() {
  const emojiMap = {correct:'🟩', wrong:'⬜', up:'🟨', down:'🟦'};
  const lines = [`⚽ Quem Sou Eu? Futebol — ${new Date().toLocaleDateString('pt-BR')}`];
  lines.push(`${gameWin ? '✅' : '❌'} ${tentativas.length}/${MAX_TENT} tentativas`);
  lines.push('');
  tentativas.forEach(t => {
    const row = [t.clube.r, t.pais.r, t.pos.r, t.age.r, t.gols.r].map(r => emojiMap[r]||'⬜').join('');
    lines.push(row);
  });
  const text = lines.join('\n');
  navigator.clipboard.writeText(text).then(() => alert('Resultado copiado!')).catch(() => prompt('Copie:', text));
}

// ── Init ──────────────────────────────────────────────────────────────────────
renderRows();
updateCounter();
if (GAME_DONE) showFinish(GAME_WIN, INITIAL_PONTOS, TARGET_FLAG, TARGET_PAIS, TARGET_NAME);
else input.focus();
</script>
</body>
</html>
