<?php
// LIGA O MOSTRADOR DE ERROS (Remova em produção)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// termo.php - O JOGO DIÁRIO DA FIRMA (DARK MODE 🧩🌙)
// session_start já foi chamado em games/index.php
require '../core/conexao.php';

// --- CONFIGURAÇÕES ---
$PONTOS_VITORIA = 10;
$MAX_TENTATIVAS = 6;

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
    'TOTAL', 'UNICO', 'VAZIO', 'VERAZ', 'VITAL', 'VORAZ', 'USUAL', 'VAGAS'
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

            $pdo->commit();
        } catch (Exception $e) { $pdo->rollBack(); }
    }

    $acabou = ($ganhou_rodada || $num_tentativas >= $MAX_TENTATIVAS);

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
<html lang="pt-br" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Termo - FBA games</title>
    
    <!-- Favicon -->
    <link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>🧩</text></svg>">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        /* PADRÃO DARK MODE */
        body { background-color: #121212; color: #e0e0e0; font-family: 'Segoe UI', sans-serif; }
        
        /* Navbar Padronizada */
        .navbar-custom { 
            background: linear-gradient(180deg, #1e1e1e 0%, #121212 100%);
            border-bottom: 1px solid #333;
            padding: 15px; 
        }
        .saldo-badge { 
            background-color: #FC082B; color: #000; padding: 8px 15px; 
            border-radius: 20px; font-weight: 800; font-size: 1.1em;
            box-shadow: 0 0 10px rgba(252, 8, 43, 0.3);
        }
        .admin-btn { 
            background-color: #ff6d00; color: white; padding: 5px 15px; 
            border-radius: 20px; text-decoration: none; font-weight: bold; font-size: 0.9em; transition: 0.3s; 
        }
        .admin-btn:hover { background-color: #e65100; color: white; box-shadow: 0 0 8px #ff6d00; }

        /* JOGO */
        .board { display: grid; grid-template-rows: repeat(6, 1fr); gap: 5px; width: 320px; margin: 30px auto; }
        .row-termo { display: grid; grid-template-columns: repeat(5, 1fr); gap: 5px; }
        .tile {
            width: 100%; aspect-ratio: 1;
            border: 2px solid #333; background-color: #1e1e1e;
            display: flex; justify-content: center; align-items: center;
            font-size: 1.8rem; font-weight: 700; text-transform: uppercase;
            user-select: none; color: #fff;
        }
        .tile.active  { border-color: #818384; }
        .tile.correct { background-color: #538d4e; border-color: #538d4e; }
        .tile.present { background-color: #b59f3b; border-color: #b59f3b; }
        .tile.absent  { background-color: #3a3a3c; border-color: #3a3a3c; }
        
        .keyboard { width: 100%; max-width: 500px; margin: 20px auto; display: flex; flex-direction: column; gap: 8px; padding: 0 10px; }
        .key-row { display: flex; justify-content: center; gap: 6px; }
        .key {
            background-color: #818384; color: white;
            border-radius: 4px; border: none;
            height: 58px; min-width: 30px; flex: 1;
            font-weight: bold; cursor: pointer; text-transform: uppercase;
            transition: background-color 0.2s;
        }
        .key:hover { opacity: 0.9; }
        .key-enter { flex: 1.5; font-size: 0.7em; }
        .key-back { flex: 1.5; font-size: 1.2em; }
        .key.correct { background-color: #538d4e !important; }
        .key.present { background-color: #b59f3b !important; }
        .key.absent  { background-color: #3a3a3c !important; opacity: 0.5; }
    </style>
</head>
<body>

<!-- Header Padronizado -->
<div class="navbar-custom d-flex justify-content-between align-items-center shadow-lg sticky-top">
    <div class="d-flex align-items-center gap-3">
        <span class="fs-5">Olá, <strong><?= htmlspecialchars($meu_perfil['nome']) ?></strong></span>
        <?php if (!empty($meu_perfil['is_admin']) && $meu_perfil['is_admin'] == 1): ?>
            <a href="../admin/dashboard.php" class="admin-btn"><i class="bi bi-gear-fill me-1"></i> Admin</a>
        <?php endif; ?>
    </div>
    
    <div class="d-flex align-items-center gap-3">
        <a href="../index.php" class="btn btn-outline-secondary btn-sm border-0"><i class="bi bi-arrow-left"></i> Voltar ao Painel</a>
        <span class="saldo-badge me-2"><?= number_format($meu_perfil['pontos'], 0, ',', '.') ?> pts</span>
    </div>
</div>

<div class="container text-center mt-4">
    
    <h3 class="mb-4 text-success fw-bold"><i class="bi bi-grid-3x3-gap-fill me-2"></i>TERMO</h3>
    
    <div id="msg-area" class="alert alert-dark d-none"></div>

    <?php if($jogo_finalizado): ?>
        <div class="alert <?= $venceu_hoje ? 'alert-success' : 'alert-secondary' ?> mt-5 shadow-sm border-0">
            <?php if($venceu_hoje): ?>
                <h2 class="display-4">🎉</h2>
                <h4>Parabéns! Pontos garantidos.</h4>
                <p>Você acertou em <strong><?= count($chutes_realizados) ?></strong> tentativas.</p>
            <?php else: ?>
                <h2>😢</h2>
                <h4>Não foi dessa vez!</h4>
                <p>A palavra era: <strong><?= $PALAVRA_DO_DIA ?></strong></p>
            <?php endif; ?>
            
            <div class="d-flex justify-content-center mt-3 mb-3">
                <div style="display: grid; gap: 4px;">
                    <?php 
                    // Resumo Visual
                    foreach($chutes_realizados as $chute) {
                        echo "<div style='display:flex; gap:4px;'>";
                        $l_correto = str_split(removerAcentos($PALAVRA_DO_DIA));
                        $l_chute = str_split($chute);
                        $cont = array_count_values($l_correto);
                        $res = array_fill(0, 5, 'absent');
                        for($i=0; $i<5; $i++) {
                            if($l_chute[$i] == $l_correto[$i]) {
                                $res[$i] = 'correct';
                                $cont[$l_chute[$i]]--;
                                $l_chute[$i] = null;
                            }
                        }
                        for($i=0; $i<5; $i++) {
                            if($l_chute[$i] !== null && strpos(removerAcentos($PALAVRA_DO_DIA), $l_chute[$i])!==false && ($cont[$l_chute[$i]]??0)>0) {
                                $res[$i] = 'present';
                                $cont[$l_chute[$i]]--;
                            }
                        }
                        foreach($res as $r) {
                            $color = ($r=='correct')?'#538d4e':(($r=='present')?'#b59f3b':'#3a3a3c');
                            echo "<div style='width:30px; height:30px; background:$color; border-radius:4px;'></div>";
                        }
                        echo "</div>";
                    }
                    ?>
                </div>
            </div>
            <a href="../index.php" class="btn btn-outline-light mt-3">Voltar ao Painel</a>
        </div>
    <?php else: ?>

        <div class="board" id="board">
            <?php for($r=0; $r<6; $r++): ?>
                <div class="row-termo" id="row-<?= $r ?>">
                    <?php for($c=0; $c<5; $c++): ?>
                        <div class="tile" id="tile-<?= $r ?>-<?= $c ?>"></div>
                    <?php endfor; ?>
                </div>
            <?php endfor; ?>
        </div>

        <div class="keyboard">
            <div class="key-row">
                <button class="key">Q</button><button class="key">W</button><button class="key">E</button><button class="key">R</button><button class="key">T</button><button class="key">Y</button><button class="key">U</button><button class="key">I</button><button class="key">O</button><button class="key">P</button>
            </div>
            <div class="key-row">
                <button class="key">A</button><button class="key">S</button><button class="key">D</button><button class="key">F</button><button class="key">G</button><button class="key">H</button><button class="key">J</button><button class="key">K</button><button class="key">L</button>
                <button class="key">Ç</button>
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
    const maxRows = 6;
    const maxTiles = 5;
    let gameOver = false;
    let guess = "";

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
                    if(data.cores[i] === 'G') tile.classList.add("correct");
                    else if(data.cores[i] === 'Y') tile.classList.add("present");
                    else tile.classList.add("absent");
                }
                updateKeyboard(palavra, data.cores);
            });
        });
    }

    if(historicoChutes.length > 0) { restoreState(); }

    document.addEventListener("keydown", (e) => {
        if(gameOver) return;
        const key = e.key.toUpperCase();
        if (key === "ENTER") submitGuess();
        else if (key === "BACKSPACE") deleteLetter();
        else if (key.length === 1 && /^[A-ZÃ‡]$/.test(key)) addLetter(key);
    });

    document.querySelectorAll(".key").forEach(btn => {
        btn.addEventListener("click", () => {
            if(gameOver) return;
            const key = btn.innerText;
            if (key === "ENTER") submitGuess();
            else if (key === "âŒ«") deleteLetter();
            else addLetter(key);
        });
    });

    function addLetter(letter) {
        if (currentTile < maxTiles) {
            const tile = document.getElementById(`tile-${currentRow}-${currentTile}`);
            tile.innerText = letter;
            tile.classList.add("active");
            guess += letter;
            currentTile++;
        }
    }

    function deleteLetter() {
        if (currentTile > 0) {
            currentTile--;
            const tile = document.getElementById(`tile-${currentRow}-${currentTile}`);
            tile.innerText = "";
            tile.classList.remove("active");
            guess = guess.slice(0, -1);
        }
    }

    function submitGuess() {
        if (guess.length !== 5) {
            showMessage("A palavra precisa de 5 letras!");
            return;
        }
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
                let msg = data.ganhou ? `PARABÉNS! +${data.pontos} PONTOS! 🚀` : `Fim de jogo! A palavra era: ${data.palavra_correta}`;
                let type = data.ganhou ? 'success' : 'danger';
                showMessage(msg, type);
                setTimeout(() => location.reload(), 4000);
            } else {
                currentRow++; currentTile = 0; guess = "";
            }
        });
    }

    function updateBoard(cores) {
        for (let i = 0; i < 5; i++) {
            const tile = document.getElementById(`tile-${currentRow}-${i}`);
            tile.classList.remove("active");
            setTimeout(() => {
                if(cores[i] === 'G') tile.classList.add("correct");
                else if(cores[i] === 'Y') tile.classList.add("present");
                else tile.classList.add("absent");
            }, i * 200);
        }
    }

    function updateKeyboard(chuteAtual, cores) {
        setTimeout(() => {
            for (let i = 0; i < 5; i++) {
                let letra = chuteAtual[i];
                let cor = cores[i];
                let keyBtn = Array.from(document.querySelectorAll(".key")).find(k => k.innerText === letra);
                if (keyBtn) {
                    if (cor === 'G') {
                        keyBtn.classList.add('correct'); keyBtn.classList.remove('present');
                    } else if (cor === 'Y' && !keyBtn.classList.contains('correct')) {
                        keyBtn.classList.add('present');
                    } else if (cor === 'X' && !keyBtn.classList.contains('correct') && !keyBtn.classList.contains('present')) {
                        keyBtn.classList.add('absent');
                    }
                }
            }
        }, 500);
    }

    function showMessage(msg, type='warning') {
        const area = document.getElementById('msg-area');
        area.className = `alert alert-${type} mt-3`;
        area.innerText = msg;
        area.classList.remove('d-none');
        setTimeout(() => area.classList.add('d-none'), 3500);
    }
</script>
<?php endif; ?>

</body>
</html>
