<?php
// LIGA O MOSTRADOR DE ERROS (Remova em produção)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// termo.php - O JOGO DIÁRIO DA FIRMA (DARK MODE 🧩🌙)
// session_start já foi chamado em games/index.php
require '../core/conexao.php';

// --- CONFIGURACOES ---
$BASE_PONTOS_VITORIA = 100;
$pointsMultiplier = getGamePointsMultiplier($pdo, 'termo');
$PONTOS_VITORIA = $BASE_PONTOS_VITORIA * $pointsMultiplier;
$MAX_TENTATIVAS = 6;

// Garantir colunas de sequência
try {
    $hasStreak = $pdo->query("SHOW COLUMNS FROM termo_historico LIKE 'streak_count'")->rowCount() > 0;
    if (!$hasStreak) {
        $pdo->exec("ALTER TABLE termo_historico ADD COLUMN streak_count INT DEFAULT 0 AFTER pontos_ganhos");
    }
} catch (Exception $e) {
}

// 1. Segurança
if (!isset($_SESSION['user_id'])) { header("Location: ../auth/login.php"); exit; }
$user_id = $_SESSION['user_id'];


// --- 2. DADOS DO USUÁRIO (PARA O HEADER) ---
try {
    $stmtMe = $pdo->prepare("SELECT nome, pontos, is_admin FROM usuarios WHERE id = :id");
    $stmtMe->execute([':id' => $user_id]);
    $meu_perfil = $stmtMe->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Erro perfil: " . $e->getMessage());
}

// --- FUNÇÃO AUXILIAR ---
function removerAcentos($string) {
    $s = mb_strtoupper($string, 'UTF-8');
    $map = [
        'Á'=>'A','À'=>'A','Â'=>'A','Ã'=>'A','Ä'=>'A',
        'É'=>'E','È'=>'E','Ê'=>'E','Ë'=>'E',
        'Í'=>'I','Ì'=>'I','Î'=>'I','Ï'=>'I',
        'Ó'=>'O','Ò'=>'O','Ô'=>'O','Õ'=>'O','Ö'=>'O',
        'Ú'=>'U','Ù'=>'U','Û'=>'U','Ü'=>'U',
        'Ç'=>'C'
    ];
    return strtr($s, $map);
}

// --- LÓGICA DO DIA ---
$dicionario = [
    'PRATO', 'METAS', 'LUCRO', 'PRAZO', 'DADOS', 'IDEIA', 'PODER', 'NIVEL', 'ATIVO', 
    'CRISE', 'RISCO', 'ETICA', 'CLUBE', 'HONRA', 'LIDER', 'MORAL', 'GRUPO', 
    'AJUDA', 'LABOR', 'TEMPO', 'CAIXA', 'VENDA', 'CUSTO', 'VALOR', 'JUROS', 
    'RENDA', 'PRECO', 'SOCIO', 'ACOES', 'BONUS', 'MARCA', 'MIDIA', 'EMAIL', 
    'VIDEO', 'AUDIO', 'TEXTO', 'LISTA', 'MAPAS', 'TESTE', 'LOGIN', 'SENHA', 
    'SALDO', 'CONTA', 'BANCO', 'PAGAR', 'BAIXA', 'CHEFE', 'SETOR', 'CARGO', 
    'GERIR', 'FOCAR', 'PLANO', 'AUTOR', 'VIGOR', 'EXITO', 'MUITO', 'REGRA', 
    'NORMA', 'PAPEL', 'NUVEM', 'PAUTA', 'NOBRE', 'SENSO', 'VISAO', 'UNIAO', 
    'FATOR', 'JUSTO', 'CERTO', 'FALSO', 'CLARO', 'NOVOS', 'VELHO', 'FORTE', 
    'FRACO', 'GRATO', 'FAVOR', 'FELIZ', 'AMIGO', 'SABIO', 'DIGNO', 'CAPAZ', 
    'BRAVO', 'CALMO', 'DOCIL', 'DOIDO', 'DURO', 'FIRME', 'GERAL', 'HABIL', 
    'IDEAL', 'IGUAL', 'JOVEM', 'LEGAL', 'LENTO', 'LIVRE', 'MAIOR', 'MENOR', 
    'NATAL', 'OTIMO', 'POBRE', 'RICOS', 'SANTO', 'SERIO', 'SUTIL', 'TENSO', 
    'TOTAL', 'UNICO', 'VAZIO', 'QUACK', 'VITAL', 'VORAZ', 'USUAL', 'VAGAS'
];

$seed = floor(time() / 86400); 
srand($seed);
$indice_do_dia = rand(0, count($dicionario) - 1);
$PALAVRA_DO_DIA = $dicionario[$indice_do_dia]; 

// --- VERIFICAÇÃO DE ESTADO ---
$hoje = date('Y-m-d');
try {
    $stmtStatus = $pdo->prepare("SELECT * FROM termo_historico WHERE id_usuario = :uid AND data_jogo = :dt");
    $stmtStatus->execute([':uid' => $user_id, ':dt' => $hoje]);
    $dados_jogo = $stmtStatus->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("<div class='alert alert-danger'>Erro Crítico: Tabela 'termo_historico' incompleta.</div>");
}

$chutes_realizados = [];
if ($dados_jogo && !empty($dados_jogo['palavras_tentadas'])) {
    $chutes_realizados = json_decode($dados_jogo['palavras_tentadas'], true) ?? [];
}

$streak_atual = 0;
try {
    $stmtStreak = $pdo->prepare("SELECT data_jogo, streak_count FROM termo_historico WHERE id_usuario = :uid ORDER BY data_jogo DESC LIMIT 1");
    $stmtStreak->execute([':uid' => $user_id]);
    $rowStreak = $stmtStreak->fetch(PDO::FETCH_ASSOC);
    if ($rowStreak) {
        $streak_atual = (int)($rowStreak['streak_count'] ?? 0);
    }
} catch (PDOException $e) {
    $streak_atual = 0;
}

$update_streak = function () use ($pdo, $user_id, $hoje, &$streak_atual) {
    $stmtToday = $pdo->prepare("SELECT streak_count FROM termo_historico WHERE id_usuario = :uid AND data_jogo = :hoje LIMIT 1");
    $stmtToday->execute([':uid' => $user_id, ':hoje' => $hoje]);
    $todayRow = $stmtToday->fetch(PDO::FETCH_ASSOC);
    if ($todayRow && (int)($todayRow['streak_count'] ?? 0) > 0) {
        $streak_atual = (int)$todayRow['streak_count'];
        return;
    }

    $stmtPrev = $pdo->prepare("SELECT data_jogo, streak_count FROM termo_historico WHERE id_usuario = :uid AND data_jogo < :hoje ORDER BY data_jogo DESC LIMIT 1");
    $stmtPrev->execute([':uid' => $user_id, ':hoje' => $hoje]);
    $prev = $stmtPrev->fetch(PDO::FETCH_ASSOC);
    $yesterday = date('Y-m-d', strtotime($hoje . ' -1 day'));
    $nova_streak = ($prev && $prev['data_jogo'] === $yesterday) ? ((int)$prev['streak_count'] + 1) : 1;
    $pdo->prepare("UPDATE termo_historico SET streak_count = :streak WHERE id_usuario = :uid AND data_jogo = :hoje")
        ->execute([':streak' => $nova_streak, ':uid' => $user_id, ':hoje' => $hoje]);
    $streak_atual = $nova_streak;
};

$jogo_finalizado = false;
$venceu_hoje = false;

if ($dados_jogo) {
    if ($dados_jogo['ganhou'] == 1) {
        $jogo_finalizado = true;
        $venceu_hoje = true;
    } elseif (count($chutes_realizados) >= $MAX_TENTATIVAS) {
        $jogo_finalizado = true;
        $venceu_hoje = false;
    }
}

// --- API DE VALIDAÇÃO ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['chute'])) {
    header('Content-Type: application/json');
    
    $apenas_validar = isset($_POST['validar_somente']);

    if ($jogo_finalizado && !$apenas_validar) {
        echo json_encode(['erro' => 'Jogo finalizado para hoje.']);
        exit;
    }

    $chute_cru = $_POST['chute'];
    $chute = removerAcentos($chute_cru);
    $correto = removerAcentos($PALAVRA_DO_DIA);
    
    if (strlen($chute) != 5) {
        echo json_encode(['erro' => 'A palavra deve ter 5 letras.']);
        exit;
    }

    // Lógica de Cores
    $resultado = array_fill(0, 5, '');
    $letras_correto = str_split($correto);
    $letras_chute = str_split($chute);
    $contagem = array_count_values($letras_correto);
    
    for ($i = 0; $i < 5; $i++) {
        if ($letras_chute[$i] == $letras_correto[$i]) {
            $resultado[$i] = 'G';
            $contagem[$letras_chute[$i]]--; 
            $letras_chute[$i] = null; 
        }
    }
    for ($i = 0; $i < 5; $i++) {
        if ($letras_chute[$i] === null) continue; 
        if (strpos($correto, $letras_chute[$i]) !== false && ($contagem[$letras_chute[$i]] ?? 0) > 0) {
            $resultado[$i] = 'Y';
            $contagem[$letras_chute[$i]]--;
        } else {
            $resultado[$i] = 'X';
        }
    }

    if ($apenas_validar) {
        echo json_encode([
            'cores' => $resultado,
            'ganhou' => ($chute === $correto),
            'fim_jogo' => false,
            'pontos' => 0
        ]);
        exit;
    }

    // --- SALVAMENTO NO BANCO ---
    $ganhou_rodada = ($chute === $correto);
    $chutes_realizados[] = $chute;
    $json_chutes = json_encode($chutes_realizados);
    $num_tentativas = count($chutes_realizados);

    if (!$dados_jogo) {
        $stmt = $pdo->prepare("INSERT INTO termo_historico (id_usuario, data_jogo, ganhou, tentativas, pontos_ganhos, palavras_tentadas) VALUES (:uid, :dt, 0, :t, 0, :json)");
        $stmt->execute([':uid' => $user_id, ':dt' => $hoje, ':t' => $num_tentativas, ':json' => $json_chutes]);
    } else {
        $stmt = $pdo->prepare("UPDATE termo_historico SET tentativas = :t, palavras_tentadas = :json WHERE id = :id");
        $stmt->execute([':t' => $num_tentativas, ':json' => $json_chutes, ':id' => $dados_jogo['id']]);
    }

    if ($ganhou_rodada) {
        try {
            $pdo->beginTransaction();

            $pdo->prepare("UPDATE termo_historico SET ganhou = 1, pontos_ganhos = :pts WHERE id_usuario = :uid AND data_jogo = :dt")
                ->execute([':pts' => $PONTOS_VITORIA, ':uid' => $user_id, ':dt' => $hoje]);

            $pdo->prepare("UPDATE usuarios SET pontos = pontos + :pts WHERE id = :uid")
                ->execute([':pts' => $PONTOS_VITORIA, ':uid' => $user_id]);

            // Atualizar sequência quando finaliza o dia
            $update_streak();

            $pdo->commit();
        } catch (Exception $e) { $pdo->rollBack(); }
    }

    $acabou = ($ganhou_rodada || $num_tentativas >= $MAX_TENTATIVAS);

    if ($acabou && !$ganhou_rodada) {
        try {
            $update_streak();
        } catch (Exception $e) {
        }
    }

    echo json_encode([
        'cores' => $resultado,
        'ganhou' => $ganhou_rodada,
        'fim_jogo' => $acabou,
        'pontos' => $ganhou_rodada ? $PONTOS_VITORIA : 0,
        'palavra_correta' => $acabou ? $PALAVRA_DO_DIA : null
    ]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no">
<title>Termo · FBA</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700;800&display=swap" rel="stylesheet">
<style>
:root{
  --bg:#07070a;--panel:#101013;--panel2:#16161a;--panel3:#1c1c21;
  --border:rgba(255,255,255,.07);--border2:rgba(255,255,255,.14);
  --red:#fc0025;--red-soft:rgba(252,0,37,.12);--red-glow:rgba(252,0,37,.25);
  --text:#f0f0f3;--text2:#868690;--text3:#3c3c44;
  --amber:#f59e0b;--font:'Poppins',sans-serif;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:var(--font);background:var(--bg);color:var(--text);min-height:100vh;-webkit-font-smoothing:antialiased;overflow-x:hidden}

.topbar{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:10px 14px;background:var(--panel);border-bottom:1px solid var(--border);position:sticky;top:0;z-index:50}
.topbar-left{display:flex;align-items:center;gap:10px}
.back-btn{display:flex;align-items:center;justify-content:center;width:32px;height:32px;border-radius:9px;border:1px solid var(--border);background:transparent;color:var(--text2);text-decoration:none;font-size:14px;transition:.2s;flex-shrink:0}
.back-btn:hover{border-color:var(--red);color:var(--red);background:var(--red-soft)}
.game-title{font-size:15px;font-weight:800;color:var(--text)}
.game-title span{color:var(--red)}
.daily-badge{display:inline-flex;align-items:center;gap:4px;font-size:8px;font-weight:700;letter-spacing:.8px;text-transform:uppercase;padding:2px 8px;border-radius:999px;background:var(--red-soft);border:1px solid var(--red-glow);color:var(--red);margin-left:6px}
.topbar-right{display:flex;align-items:center;gap:6px}
.chip{display:flex;align-items:center;gap:4px;padding:4px 10px;border-radius:20px;background:var(--panel2);border:1px solid var(--border);font-size:11px;font-weight:700;color:var(--text);white-space:nowrap}
.chip.fire{border-color:rgba(245,158,11,.3)!important;color:var(--amber)!important}

.main{max-width:520px;margin:0 auto;padding:16px 12px 60px}

.msg-area{padding:10px 14px;border-radius:10px;font-size:13px;font-weight:600;text-align:center;margin-bottom:10px;transition:.2s}
.msg-area.hidden{display:none}
.msg-area.warning{background:rgba(245,158,11,.12);border:1px solid rgba(245,158,11,.3);color:var(--amber)}
.msg-area.success{background:rgba(34,197,94,.12);border:1px solid rgba(34,197,94,.3);color:#4ade80}
.msg-area.danger{background:var(--red-soft);border:1px solid var(--red-glow);color:#f87171}

.board{display:grid;grid-template-rows:repeat(6,1fr);gap:5px;width:min(310px,90vw);margin:20px auto}
.row-termo{display:grid;grid-template-columns:repeat(5,1fr);gap:5px}
.tile{width:100%;aspect-ratio:1;border:2px solid var(--border2);background:var(--panel2);display:flex;justify-content:center;align-items:center;font-size:clamp(1.3rem,6vw,1.8rem);font-weight:800;color:var(--text);font-family:var(--font);border-radius:4px;user-select:none}
.tile.active{border-color:rgba(255,255,255,.3)}
.tile.correct{background:#538d4e;border-color:#538d4e;color:#fff}
.tile.present{background:#b59f3b;border-color:#b59f3b;color:#fff}
.tile.absent{background:#3a3a3c;border-color:#3a3a3c;color:#fff}

.keyboard{width:100%;max-width:500px;margin:12px auto 0;display:flex;flex-direction:column;gap:6px;padding:0 8px}
.key-row{display:flex;justify-content:center;gap:5px}
.key{background:var(--panel2);border:1px solid var(--border2);color:var(--text);border-radius:6px;height:50px;min-width:0;flex:1;font-family:var(--font);font-weight:700;font-size:13px;cursor:pointer;transition:background .15s}
.key:active{opacity:.7}
.key-enter,.key-back{flex:1.5;font-size:10px}
.key.correct{background:#538d4e!important;border-color:#538d4e!important;color:#fff!important}
.key.present{background:#b59f3b!important;border-color:#b59f3b!important;color:#fff!important}
.key.absent{background:#3a3a3c!important;border-color:#3a3a3c!important;color:#fff!important}

.result-card{background:var(--panel);border:1px solid var(--border2);border-radius:18px;padding:28px 20px;text-align:center;margin:16px 0}
.result-icon{font-size:3.2rem;display:block;margin-bottom:10px}
.result-title{font-size:20px;font-weight:800;margin-bottom:6px}
.result-sub{font-size:13px;color:var(--text2);margin-bottom:16px;line-height:1.5}
.result-grid{display:flex;flex-direction:column;gap:4px;align-items:center;margin-bottom:20px}
.result-row{display:flex;gap:4px}
.result-sq{width:26px;height:26px;border-radius:4px}
.btn-back{display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:11px 24px;border-radius:12px;background:var(--panel2);border:1px solid var(--border2);color:var(--text2);font-family:var(--font);font-size:13px;font-weight:700;text-decoration:none;transition:all .2s;width:100%}
.btn-back:hover{border-color:var(--red);color:var(--red);background:var(--red-soft)}

::-webkit-scrollbar{width:4px}::-webkit-scrollbar-track{background:transparent}::-webkit-scrollbar-thumb{background:var(--border2);border-radius:2px}
</style>
</head>
<body>

<div class="topbar">
  <div class="topbar-left">
    <a href="../games.php" class="back-btn"><i class="bi bi-arrow-left"></i></a>
    <span class="game-title">📝 <span>Termo</span><span class="daily-badge"><i class="bi bi-calendar3"></i>Diário</span></span>
  </div>
  <div class="topbar-right">
    <?php if ($streak_atual > 0): ?>
    <div class="chip fire"><i class="bi bi-fire"></i><?= $streak_atual ?></div>
    <?php endif; ?>
    <div class="chip"><i class="bi bi-coin" style="color:var(--amber)"></i><?= number_format($meu_perfil['pontos'],0,',','.') ?></div>
  </div>
</div>

<div class="main">
  <div id="msg-area" class="msg-area hidden"></div>

  <?php if($jogo_finalizado): ?>
  <div class="result-card" style="border-color:<?= $venceu_hoje ? 'rgba(83,141,78,.4)' : 'rgba(255,255,255,.1)' ?>">
    <span class="result-icon"><?= $venceu_hoje ? '🎉' : '😔' ?></span>
    <div class="result-title" style="color:<?= $venceu_hoje ? '#4ade80' : 'var(--text2)' ?>"><?= $venceu_hoje ? 'Acertou!' : 'Não foi dessa vez' ?></div>
    <div class="result-sub">
      <?php if($venceu_hoje): ?>
        Acertou em <strong style="color:var(--text)"><?= count($chutes_realizados) ?></strong> tentativa<?= count($chutes_realizados) !== 1 ? 's' : '' ?> · <strong style="color:#4ade80">+<?= $PONTOS_VITORIA ?> moedas</strong>
      <?php else: ?>
        A palavra era: <strong style="color:var(--text);font-size:15px"><?= $PALAVRA_DO_DIA ?></strong>
      <?php endif; ?>
    </div>
    <div class="result-grid">
      <?php foreach($chutes_realizados as $chute):
        $lc=str_split(removerAcentos($PALAVRA_DO_DIA));$lch=str_split($chute);
        $cnt=array_count_values($lc);$res=array_fill(0,5,'absent');
        for($i=0;$i<5;$i++){if($lch[$i]==$lc[$i]){$res[$i]='correct';$cnt[$lch[$i]]--;$lch[$i]=null;}}
        for($i=0;$i<5;$i++){if($lch[$i]!==null&&strpos(removerAcentos($PALAVRA_DO_DIA),$lch[$i])!==false&&($cnt[$lch[$i]]??0)>0){$res[$i]='present';$cnt[$lch[$i]]--;}}
      ?><div class="result-row"><?php foreach($res as $r):$c=($r=='correct')?'#538d4e':(($r=='present')?'#b59f3b':'#3a3a3c');?><div class="result-sq" style="background:<?=$c?>"></div><?php endforeach;?></div><?php endforeach;?>
    </div>
    <a href="../games.php" class="btn-back"><i class="bi bi-arrow-left"></i>Voltar aos Jogos</a>
  </div>
  <?php else: ?>

  <div class="board" id="board">
    <?php for($r=0;$r<6;$r++): ?>
    <div class="row-termo" id="row-<?=$r?>">
      <?php for($c=0;$c<5;$c++): ?><div class="tile" id="tile-<?=$r?>-<?=$c?>"></div><?php endfor; ?>
    </div>
    <?php endfor; ?>
  </div>

  <div class="keyboard">
    <div class="key-row">
      <button class="key">Q</button><button class="key">W</button><button class="key">E</button><button class="key">R</button><button class="key">T</button><button class="key">Y</button><button class="key">U</button><button class="key">I</button><button class="key">O</button><button class="key">P</button>
    </div>
    <div class="key-row">
      <button class="key">A</button><button class="key">S</button><button class="key">D</button><button class="key">F</button><button class="key">G</button><button class="key">H</button><button class="key">J</button><button class="key">K</button><button class="key">L</button><button class="key">Ç</button>
    </div>
    <div class="key-row">
      <button class="key key-enter" id="enter-btn">ENTER</button>
      <button class="key">Z</button><button class="key">X</button><button class="key">C</button><button class="key">V</button><button class="key">B</button><button class="key">N</button><button class="key">M</button>
      <button class="key key-back" id="back-btn">⌫</button>
    </div>
  </div>

  <?php endif; ?>
</div>

<?php if(!$jogo_finalizado): ?>
<script>
    const historicoChutes = <?= json_encode($chutes_realizados) ?>;
    let currentRow = historicoChutes.length;
    let currentTile = 0;
    const maxRows = 6, maxTiles = 5;
    let gameOver = false, guess = "";

    function restoreState() {
        historicoChutes.forEach((palavra, index) => {
            fetch('index.php?game=termo', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `chute=${palavra}&tentativa=${index + 1}&validar_somente=1`
            })
            .then(res => res.json())
            .then(data => {
                for (let i = 0; i < 5; i++) {
                    const tile = document.getElementById(`tile-${index}-${i}`);
                    tile.innerText = palavra[i];
                    tile.classList.remove("active");
                    if(data.cores[i]==='G') tile.classList.add("correct");
                    else if(data.cores[i]==='Y') tile.classList.add("present");
                    else tile.classList.add("absent");
                }
                updateKeyboard(palavra, data.cores);
            });
        });
    }
    if(historicoChutes.length > 0) restoreState();

    document.addEventListener("keydown", e => {
        if(gameOver) return;
        const key = e.key.toUpperCase();
        if(key === "ENTER") submitGuess();
        else if(key === "BACKSPACE") deleteLetter();
        else if(key.length === 1 && /^[A-ZÇ]$/.test(key)) addLetter(key);
    });

    document.querySelectorAll(".key").forEach(btn => {
        btn.addEventListener("click", () => {
            if(gameOver) return;
            if(btn.id === "enter-btn") { submitGuess(); return; }
            if(btn.id === "back-btn") { deleteLetter(); return; }
            addLetter(btn.innerText);
        });
    });

    function addLetter(letter) {
        if(currentTile < maxTiles) {
            const tile = document.getElementById(`tile-${currentRow}-${currentTile}`);
            tile.innerText = letter; tile.classList.add("active");
            guess += letter; currentTile++;
        }
    }

    function deleteLetter() {
        if(currentTile > 0) {
            currentTile--;
            const tile = document.getElementById(`tile-${currentRow}-${currentTile}`);
            tile.innerText = ""; tile.classList.remove("active");
            guess = guess.slice(0, -1);
        }
    }

    function submitGuess() {
        if(guess.length !== 5) { showMessage("A palavra precisa de 5 letras!"); return; }
        fetch('index.php?game=termo', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `chute=${guess}&tentativa=${currentRow + 1}`
        })
        .then(res => res.json())
        .then(data => {
            if(data.erro) { showMessage(data.erro); return; }
            updateBoard(data.cores);
            updateKeyboard(guess, data.cores);
            if(data.fim_jogo) {
                gameOver = true;
                const msg = data.ganhou ? `Parabéns! +${data.pontos} moedas 🎉` : `A palavra era: ${data.palavra_correta}`;
                showMessage(msg, data.ganhou ? 'success' : 'danger');
                setTimeout(() => location.reload(), 3200);
            } else {
                currentRow++; currentTile = 0; guess = "";
            }
        });
    }

    function updateBoard(cores) {
        for(let i = 0; i < 5; i++) {
            const tile = document.getElementById(`tile-${currentRow}-${i}`);
            tile.classList.remove("active");
            setTimeout(() => {
                if(cores[i]==='G') tile.classList.add("correct");
                else if(cores[i]==='Y') tile.classList.add("present");
                else tile.classList.add("absent");
            }, i * 150);
        }
    }

    function updateKeyboard(chuteAtual, cores) {
        setTimeout(() => {
            for(let i = 0; i < 5; i++) {
                const letra = chuteAtual[i], cor = cores[i];
                const keyBtn = Array.from(document.querySelectorAll(".key")).find(k => k.innerText === letra);
                if(keyBtn) {
                    if(cor==='G') { keyBtn.classList.add('correct'); keyBtn.classList.remove('present'); }
                    else if(cor==='Y' && !keyBtn.classList.contains('correct')) keyBtn.classList.add('present');
                    else if(cor==='X' && !keyBtn.classList.contains('correct') && !keyBtn.classList.contains('present')) keyBtn.classList.add('absent');
                }
            }
        }, 500);
    }

    function showMessage(msg, type='warning') {
        const area = document.getElementById('msg-area');
        area.className = `msg-area ${type}`;
        area.innerText = msg;
        setTimeout(() => area.classList.add('hidden'), 3500);
    }
</script>
<?php endif; ?>
</body>
</html>
