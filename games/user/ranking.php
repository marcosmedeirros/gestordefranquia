<?php
// ranking.php - CLASSIFICAÇÃO GERAL (DARK MODE 🏆🌑)
// VERSÃO: SEM TRAVAS DE SEGURANÇA
session_start();
require '../core/conexao.php';
require '../core/avatar.php';
require '../core/sequencia_dias.php';

// 1. Segurança básica
if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit;
}

$user_id = $_SESSION['user_id'];

// 2. Busca dados do usuário logado
try {
    $stmtMe = $pdo->prepare("SELECT nome, pontos, is_admin FROM usuarios WHERE id = :id");
    $stmtMe->execute([':id' => $user_id]);
    $meu_perfil = $stmtMe->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Erro ao carregar perfil: " . $e->getMessage());
}

// 3. IDENTIFICA OS "REIS" DOS JOGOS PARA AS TAGS 👑
$id_rei_xadrez = null;
$id_rei_pinguim = null;
$id_rei_flappy = null;
$id_rei_pnip = null;

try {
    // Rei do Xadrez: Quem tem mais vitórias
    $stmtChess = $pdo->query("SELECT vencedor FROM xadrez_partidas WHERE status = 'finalizada' GROUP BY vencedor ORDER BY COUNT(*) DESC LIMIT 1");
    $id_rei_xadrez = $stmtChess->fetchColumn();

    // Rei do Pinguim: Quem tem o maior recorde
    $stmtDino = $pdo->query("SELECT id_usuario FROM dino_historico GROUP BY id_usuario ORDER BY MAX(pontuacao_final) DESC LIMIT 1");
    $id_rei_pinguim = $stmtDino->fetchColumn();

    // Rei do Flappy: Quem tem o maior recorde (NOVO)
    $stmtFlappy = $pdo->query("SELECT id_usuario FROM flappy_historico ORDER BY pontuacao DESC LIMIT 1");
    $id_rei_flappy = $stmtFlappy->fetchColumn();

    // Rei do PNIPNAVAL: Quem tem mais vitórias em Batalha Naval
    $stmtPNIP = $pdo->query("SELECT vencedor_id FROM naval_salas WHERE status = 'fim' AND vencedor_id IS NOT NULL GROUP BY vencedor_id ORDER BY COUNT(*) DESC LIMIT 1");
    $id_rei_pnip = $stmtPNIP->fetchColumn();

} catch (Exception $e) {
    // Silencia erros caso as tabelas ainda não existam ou estejam vazias
}

// 3.5. BUSCA SEQUÊNCIAS DE TERMO E MEMÓRIA PARA TODOS OS USUÁRIOS
$sequencias_usuario = []; // user_id => ['termo' => x, 'memoria' => y]

try {
    // Buscar todas as sequências
    $stmt = $pdo->query("
        SELECT user_id, jogo, sequencia_atual 
        FROM usuario_sequencias_dias
        WHERE sequencia_atual > 0
    ");
    $todas_sequencias = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach($todas_sequencias as $seq) {
        $uid = $seq['user_id'];
        if(!isset($sequencias_usuario[$uid])) {
            $sequencias_usuario[$uid] = [];
        }
        $sequencias_usuario[$uid][$seq['jogo']] = $seq['sequencia_atual'];
    }
} catch (Exception $e) {
    // Silencia erros caso a tabela não exista
    $sequencias_usuario = [];
}

// 3.6. BUSCA USUÁRIO COM MAIS CAFÉS FEITOS
$maior_cafe = null;

try {
    $stmt = $pdo->query("
        SELECT id, nome, cafes_feitos 
        FROM usuarios 
        WHERE cafes_feitos > 0 
        ORDER BY cafes_feitos DESC 
        LIMIT 1
    ");
    $maior_cafe = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $maior_cafe = null;
}

// 4. BUSCA RANKING (LUCRO LÍQUIDO + SALDO) 🧠
try {
    $sql = "
    SELECT 
        u.id, 
        u.nome, 
        u.pontos,
        (u.pontos - 50) as lucro_liquido
    FROM usuarios u 
    ORDER BY lucro_liquido DESC
    ";
    
    $stmt = $pdo->query($sql);
    $usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    die("Erro ao carregar ranking: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="pt-br" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ranking - Pikafumo Games</title>
    
    <!-- Favicon -->
    <link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>🏆</text></svg>">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    
    <style>
        /* --- ESTILO DARK PREMIUM --- */
        body { 
            background-color: #121212; 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
            color: #e0e0e0;
        }

        /* Navbar */
        .navbar-custom { 
            background: linear-gradient(180deg, #1e1e1e 0%, #121212 100%);
            border-bottom: 1px solid #333;
            padding: 15px; 
        }

        .saldo-badge { 
            background-color: #00e676; 
            color: #000;
            padding: 8px 15px; 
            border-radius: 20px; 
            font-weight: 800; 
            font-size: 1.1em;
            box-shadow: 0 0 10px rgba(0, 230, 118, 0.3);
        }

        .admin-btn { 
            background-color: #ff6d00; color: white; padding: 5px 15px; border-radius: 20px; 
            text-decoration: none; font-weight: bold; font-size: 0.9em; transition: 0.3s; 
        }
        .admin-btn:hover { background-color: #e65100; color: white; box-shadow: 0 0 8px #ff6d00; }

        /* --- RANKING CARD --- */
        .ranking-card { 
            border: 1px solid #333; 
            border-radius: 15px; 
            background-color: #1e1e1e;
            overflow: hidden; 
            box-shadow: 0 4px 20px rgba(0,0,0,0.5);
        }
        
        .ranking-header { 
            background: linear-gradient(45deg, #f1c40f, #e67e22); 
            color: #000;
            padding: 30px 20px; 
            text-align: center;
            border-bottom: 1px solid #b7950b;
        }
        
        .user-row { 
            transition: transform 0.2s, background-color 0.2s; 
            border-bottom: 1px solid #333;
            background-color: #1e1e1e;
            color: #e0e0e0;
        }
        .user-row:hover { 
            transform: scale(1.01); z-index: 10; background-color: #2c2c2c;
            box-shadow: 0 4px 10px rgba(0,0,0,0.3); 
        }
        
        /* Colunas de Pontos */
        .col-ganhos { font-weight: 800; font-size: 1.1em; }
        .col-saldo { font-weight: 600; font-size: 1em; opacity: 0.9; }
        
        .text-lucro { color: #00e676; } /* Verde para lucro */
        .text-preju { color: #ff5252; } /* Vermelho para prejuízo */
        .text-neutro { color: #aaa; }   /* Cinza para zero a zero */

        /* Pódio e Numeração */
        .medal-icon { 
            font-size: 1.5em; 
            min-width: 50px; 
            text-align: center; 
            margin-right: 10px; 
        }
        .rank-number { 
            font-size: 1.1em; 
            font-weight: bold; 
            color: #666; 
            min-width: 50px; 
            text-align: center; 
            margin-right: 10px; 
            white-space: nowrap; 
        }
        
        .pos-1 { background: linear-gradient(90deg, rgba(241, 196, 15, 0.15), transparent) !important; border-left: 4px solid #f1c40f; } 
        .pos-2 { background: linear-gradient(90deg, rgba(189, 195, 199, 0.15), transparent) !important; border-left: 4px solid #bdc3c7; } 
        .pos-3 { background: linear-gradient(90deg, rgba(230, 126, 34, 0.15), transparent) !important; border-left: 4px solid #e67e22; } 
        
        .me-row { background-color: rgba(0, 230, 118, 0.1) !important; border: 1px solid #00e676; font-weight: bold; }

        /* TAGS ESPECIAIS */
        .tag-badge { 
            font-size: 0.65em; 
            padding: 4px 10px; 
            border-radius: 12px; 
            font-weight: 700; 
            box-shadow: 0 2px 8px rgba(0,0,0,0.3);
        }
        .tag-xadrez { background: linear-gradient(135deg, #9c27b0, #673ab7); color: white; border: 1px solid rgba(255,255,255,0.2); }
        .tag-pinguim { background: linear-gradient(135deg, #00acc1, #0097a7); color: white; border: 1px solid rgba(255,255,255,0.2); }
        .tag-flappy { background: linear-gradient(135deg, #ff9800, #f57c00); color: white; border: 1px solid rgba(255,255,255,0.2); }
        .tag-pnip { background: linear-gradient(135deg, #0277bd, #01579b); color: white; border: 1px solid rgba(255,255,255,0.2); box-shadow: 0 2px 8px rgba(2, 119, 189, 0.4); }

        /* TAGS ESPECIAIS 🏷️ */
        .tag-badge { font-size: 0.65em; padding: 4px 8px; border-radius: 12px; vertical-align: middle; font-weight: 700; letter-spacing: 0.5px; }
        .tag-xadrez { background-color: #ffc107; color: #000; box-shadow: 0 0 5px rgba(255, 193, 7, 0.4); }
        .tag-pinguim { background-color: #0dcaf0; color: #000; box-shadow: 0 0 5px rgba(13, 202, 240, 0.4); }
        .tag-flappy { background-color: #ff9800; color: #fff; box-shadow: 0 0 5px rgba(255, 152, 0, 0.4); }
    </style>
</head>
<body>

    <!-- Header -->
    <div class="navbar-custom d-flex justify-content-between align-items-center shadow-lg sticky-top">
        <div class="d-flex align-items-center gap-3">
            <?php $meu_avatar = obterCustomizacaoAvatar($pdo, $user_id); ?>
            <div style="display:flex; align-items:center; gap:10px;">
                <?= avatarHTML($meu_avatar, 'micro') ?>
                <span class="fs-5">Olá, <strong><?= htmlspecialchars($meu_perfil['nome']) ?></strong></span>
            </div>
            <?php if (!empty($meu_perfil['is_admin']) && $meu_perfil['is_admin'] == 1): ?>
                <a href="../admin/dashboard.php" class="admin-btn"><i class="bi bi-gear-fill me-1"></i> Admin</a>
            <?php endif; ?>
        </div>
        <div class="d-flex align-items-center gap-3">
            <a href="../index.php" class="btn btn-outline-secondary btn-sm border-0"><i class="bi bi-arrow-left"></i> Voltar ao Painel</a>
            <span class="saldo-badge me-2"><?= number_format($meu_perfil['pontos'], 0, ',', '.') ?> pts</span>
        </div>
    </div>

    <div class="container mt-5 mb-5">
        <div class="row justify-content-center">
            <div class="col-md-10 col-lg-8">
                
                <div class="card ranking-card">
                    <div class="ranking-header">
                        <h2 class="fw-bold mb-0 text-dark"><i class="bi bi-trophy-fill me-2"></i>Ranking Global</h2>
                        <p class="text-dark opacity-75 fw-bold m-0 small">Lucro acumulado (Base 50 pts)</p>
                    </div>

                    <!-- Cabeçalho da Tabela -->
                    <div class="d-flex bg-dark text-secondary px-3 py-3 border-bottom border-secondary small text-uppercase fw-bold">
                        <div class="flex-grow-1 ps-2">Jogador</div>
                        <div style="width: 120px; text-align: right;">Saldo Atual</div>
                        <div style="width: 120px; text-align: right;">Lucro Geral</div>
                    </div>

                    <div class="list-group list-group-flush">
                        <?php 
                        $posicao = 1;
                        foreach($usuarios as $user): 
                            $classe_linha = "list-group-item d-flex align-items-center px-3 py-3 user-row";
                            $icone = "";
                            
                            // Define ícone do ranking
                            if($posicao == 1) { $classe_linha .= " pos-1"; $icone = "<div class='medal-icon'>🥇</div>"; }
                            elseif($posicao == 2) { $classe_linha .= " pos-2"; $icone = "<div class='medal-icon'>🥈</div>"; }
                            elseif($posicao == 3) { $classe_linha .= " pos-3"; $icone = "<div class='medal-icon'>🥉</div>"; }
                            else { $icone = "<div class='rank-number'>#$posicao</div>"; }

                            if($user['id'] == $user_id) { $classe_linha .= " me-row"; }

                            // Define cor do lucro
                            $lucro = $user['lucro_liquido'];
                            $cor_lucro = 'text-neutro';
                            $sinal = '';
                            if ($lucro > 0) {
                                $cor_lucro = 'text-lucro';
                                $sinal = '+';
                            } elseif ($lucro < 0) {
                                $cor_lucro = 'text-preju';
                            }
                        ?>
                            <div class="<?= $classe_linha ?>">
                                <!-- Coluna Jogador -->
                                <div class="d-flex align-items-center flex-grow-1 overflow-hidden" style="gap:10px;">
                                    <?= $icone ?>
                                    <?php $avatar_jogador = obterCustomizacaoAvatar($pdo, $user['id']); echo avatarHTML($avatar_jogador, 'mini'); ?>
                                    <span class="fs-6 text-white text-truncate">
                                        <?= htmlspecialchars($user['nome']) ?>
                                        
                                        <!-- TAGS ESPECIAIS -->
                                        <?php if($user['id'] == $id_rei_xadrez): ?>
                                            <span class="badge tag-badge tag-xadrez ms-2" title="Mestre do Xadrez (Mais vitórias)">♟️ KING</span>
                                        <?php endif; ?>

                                        <?php if($user['id'] == $id_rei_pinguim): ?>
                                            <span class="badge tag-badge tag-pinguim ms-2" title="Lenda do Pinguim (Maior Recorde)">🐧 PRO</span>
                                        <?php endif; ?>

                                        <?php if($user['id'] == $id_rei_flappy): ?>
                                            <span class="badge tag-badge tag-flappy ms-2" title="Mestre do Flappy (Maior Recorde)">🐦 FLY</span>
                                        <?php endif; ?>

                                        <?php if($user['id'] == $id_rei_pnip): ?>
                                            <span class="badge tag-badge tag-pnip ms-2" title="Almirante da Batalha Naval (Mais vitórias)">🚢 PNIP</span>
                                        <?php endif; ?>

                                        <?php if(isset($sequencias_usuario[$user['id']]['termo']) && $sequencias_usuario[$user['id']]['termo'] > 0): ?>
                                            <span style="background: linear-gradient(135deg, #ff006e, #8338ec); color: white; padding: 2px 8px; border-radius: 12px; font-size: 0.75rem; font-weight: bold; margin-left: 6px; display: inline-block;">
                                                📝 Termo x<?= $sequencias_usuario[$user['id']]['termo'] ?>
                                            </span>
                                        <?php endif; ?>

                                        <?php if(isset($sequencias_usuario[$user['id']]['memoria']) && $sequencias_usuario[$user['id']]['memoria'] > 0): ?>
                                            <span style="background: linear-gradient(135deg, #00d4ff, #0099ff); color: white; padding: 2px 8px; border-radius: 12px; font-size: 0.75rem; font-weight: bold; margin-left: 6px; display: inline-block;">
                                                🧠 Memória x<?= $sequencias_usuario[$user['id']]['memoria'] ?>
                                            </span>
                                        <?php endif; ?>

                                        <?php if($maior_cafe && $maior_cafe['id'] == $user['id'] && $maior_cafe['cafes_feitos'] > 0): ?>
                                            <span style="background: linear-gradient(135deg, #8B4513, #D2691E); color: white; padding: 2px 8px; border-radius: 12px; font-size: 0.75rem; font-weight: bold; margin-left: 6px; display: inline-block;">
                                                ☕ Café x<?= $maior_cafe['cafes_feitos'] ?>
                                            </span>
                                        <?php endif; ?>

                                        <?php if($user['id'] == $user_id): ?>
                                            <span class="badge bg-success ms-2 text-dark" style="font-size: 0.55em;">VOCÊ</span>
                                        <?php endif; ?>
                                    </span>
                                </div>
                                
                                <!-- Coluna Saldo Atual (Carteira) -->
                                <div style="width: 120px; text-align: right;">
                                    <span class="col-saldo text-white-50">
                                        $ <?= number_format($user['pontos'], 0, ',', '.') ?>
                                    </span>
                                </div>

                                <!-- Coluna Lucro Geral (Desempenho) -->
                                <div style="width: 120px; text-align: right;">
                                    <span class="col-ganhos <?= $cor_lucro ?>">
                                        <?= $sinal . number_format($lucro, 0, ',', '.') ?>
                                    </span>
                                </div>
                            </div>
                        <?php 
                            $posicao++;
                        endforeach; 
                        ?>
                    </div>
                    
                    <?php if(count($usuarios) == 0): ?>
                        <div class="text-center p-5 text-secondary">
                            <i class="bi bi-ghost fs-1"></i>
                            <p class="mt-2">Ainda não há jogadores no ranking.</p>
                        </div>
                    <?php endif; ?>
                </div>

            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Inicializa Tooltips do Bootstrap
        var tooltipTriggerList = [].slice.call(document.querySelectorAll('[title]'))
        var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
          return new bootstrap.Tooltip(tooltipTriggerEl)
        })
    </script>
</body>
</html>
