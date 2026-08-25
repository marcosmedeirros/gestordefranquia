<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require '../core/conexao.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: /login.php');
    exit;
}

$userId = (int)$_SESSION['user_id'];
$pointsMultiplier = getGamePointsMultiplier($pdo, 'acerteacesta');

/**
 * QUANTAS CESTAS CABEM NUM SEGUNDO, no melhor dos casos.
 *
 * O marcador atravessa a barra e volta; só dá pra pontuar quando ele passa pela
 * zona. No começo isso leva mais de um segundo, e vai encurtando conforme a
 * barra acelera. 3 é folgado de propósito: aqui não é pra medir a habilidade
 * de ninguém, é pra recusar um score que a barra não teria como entregar.
 */
const CESTA_POR_SEGUNDO = 3;

/** De quanto em quanto o cliente reporta progresso, e a folga aceita. */
const CESTA_PASSO_PING  = 5;
const CESTA_FOLGA_PING  = 3;

/**
 * Folga entre o último ping e o score final.
 *
 * Os pings vêm de 5 em 5, então quem erra a segunda vida em 9 tem progresso 5.
 * Isto cobre esse pedaço — e é também o máximo que alguém tira sem mandar ping
 * nenhum, então não pode ser generoso.
 */
const CESTA_FOLGA_PROGRESSO = 8;

/**
 * Quanto vale um score, em moedas. É A ÚNICA conta de prêmio.
 *
 * Uma função só, usada pelo servidor e pela tela — no Flappy essas duas contas
 * moravam em lugares diferentes e passaram meses divergindo: a tela prometia 5
 * e o servidor pagava 1.
 *
 * Mesma ideia do Flappy — o marco vale mais conforme a partida avança — só que
 * na escala daqui, que é a de lá pela METADE:
 *
 *     cestas 1–10   → 5 moedas por marco de 5
 *     cestas 11–25  → 7
 *     cestas 26–40  → 9
 *     cestas 41+    → 11
 *
 * A metade não é enfeite: no Flappy o cano é reflexo puro e uma partida boa
 * passa de 100; aqui a zona verde encolhe até 5% da barra e a velocidade sobe a
 * cada 5 acertos, então a mesma habilidade rende bem menos pontos. Marco de 5 e
 * fronteiras pela metade põem os dois jogos na mesma faixa de moedas por minuto
 * jogado — senão o mais difícil seria o que paga menos.
 *
 * Progressivo por trecho, como lá: chegar à 26ª cesta não recalcula as 25
 * anteriores por 9, então o total nunca dá pulo — só passa a crescer mais
 * rápido. As fronteiras são múltiplas de 5 pra nenhum marco ficar partido.
 *
 * Em números: 10 cestas pagam 10, 25 pagam 31, 40 pagam 58, 50 pagam 80.
 *
 * Sem teto por partida: quem acerta 60 recebe pelos 60. O que impede o farm é
 * o ritmo dos pings, não um limite no prêmio de quem jogou.
 */
function cestaMoedasPorMarco(int $cestaFinal): int
{
    if ($cestaFinal <= 10) return 5;
    if ($cestaFinal <= 25) return 7;
    if ($cestaFinal <= 40) return 9;
    return 11;
}

function cestaMoedasPorScore(int $score): int
{
    $marcos = intdiv(max(0, $score), 5);
    $total = 0;
    for ($m = 1; $m <= $marcos; $m++) {
        $total += cestaMoedasPorMarco($m * 5);
    }
    return $total;
}

function cestaTabela(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS acerteacesta_historico (
        id INT AUTO_INCREMENT PRIMARY KEY,
        id_usuario INT NOT NULL,
        pontuacao INT NOT NULL,
        data_jogo DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_ac_user (id_usuario),
        INDEX idx_ac_score (pontuacao)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

// ── Ações do jogo ────────────────────────────────────────────────────────────
// Mesmo desenho do Flappy: o servidor abre a partida, acompanha o progresso e
// só ele paga. O cliente nunca diz quanto ganhou.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao'])) {
    header('Content-Type: application/json; charset=utf-8');
    cestaTabela($pdo);

    $ativa  = ($_SESSION['cesta_run_ativa'] ?? false) === true;
    $inicio = (float)($_SESSION['cesta_run_inicio'] ?? 0);

    $validar = function (int $score) use ($ativa, $inicio) {
        if (!$ativa || $inicio <= 0) throw new Exception('Partida não está aberta.');
        $decorrido = max(0, microtime(true) - $inicio);
        $teto = (int)($decorrido * CESTA_POR_SEGUNDO) + 1;
        if ($score < 0)     throw new Exception('Pontuação inválida.');
        if ($score > $teto) throw new Exception('Pontuação acima do que a barra entrega.');
    };

    try {
        if ($_POST['acao'] === 'iniciar') {
            // Sem esta pausa, dois cliques no Reset abrem duas partidas no mesmo
            // instante e a segunda herda o relógio da primeira.
            if ($inicio > 0 && (microtime(true) - $inicio) < 1.0) {
                echo json_encode(['erro' => 'Espere um instante antes de recomeçar.']);
                exit;
            }
            $_SESSION['cesta_run_ativa']   = true;
            $_SESSION['cesta_run_inicio']  = microtime(true);
            $_SESSION['cesta_progresso']   = 0;
            $_SESSION['cesta_pagas']       = 0;
            $_SESSION['cesta_ping_t']      = microtime(true);
            $_SESSION['cesta_ping_s']      = 0;
            echo json_encode(['sucesso' => true]);
            exit;
        }

        if ($_POST['acao'] === 'progresso') {
            $score = (int)($_POST['score'] ?? 0);
            $validar($score);

            // Duas travas, porque sozinha nenhuma segura: pelo PASSO, um ping não
            // avança muito mais que as 5 cestas que o cliente reporta; pelo TEMPO
            // desde o ping anterior, pra não dar pra esperar e despejar todos de
            // uma vez. Foi assim que fechei o mesmo buraco no Flappy.
            $ping_t = (float)($_SESSION['cesta_ping_t'] ?? $inicio);
            $ping_s = (int)($_SESSION['cesta_ping_s'] ?? 0);
            $janela = max(0, microtime(true) - $ping_t);
            $maximo = $ping_s + min(
                (int)($janela * CESTA_POR_SEGUNDO) + CESTA_FOLGA_PING,
                CESTA_PASSO_PING + CESTA_FOLGA_PING
            );
            if ($score > $maximo) {
                error_log("[cesta] ping $score recusado (max $maximo, anterior $ping_s em {$janela}s, user=$userId)");
                throw new Exception('Progresso acima do ritmo do jogo.');
            }

            $_SESSION['cesta_ping_t']    = microtime(true);
            $_SESSION['cesta_ping_s']    = max($ping_s, $score);
            $_SESSION['cesta_progresso'] = max((int)($_SESSION['cesta_progresso'] ?? 0), $score);

            // O ping devolve quanto a partida já vale, e é assim que a tela
            // mostra o prêmio subindo sem precisar da fórmula do lado dela. No
            // Flappy as duas contas viviam separadas e divergiram; aqui só
            // existe uma, e o que aparece na tela é literalmente o que o
            // servidor pagaria se a partida acabasse agora.
            echo json_encode([
                'sucesso' => true,
                'moedas'  => cestaMoedasPorScore((int)$_SESSION['cesta_progresso']) * $pointsMultiplier,
                'proximo' => cestaMoedasPorMarco((int)$_SESSION['cesta_progresso'] + 5) * $pointsMultiplier,
            ]);
            exit;
        }

        if ($_POST['acao'] === 'salvar') {
            $score = (int)($_POST['score'] ?? 0);
            $validar($score);

            // Amarra o score ao que foi acompanhado durante a partida.
            $progresso = (int)($_SESSION['cesta_progresso'] ?? 0);
            $limite = $progresso + CESTA_FOLGA_PROGRESSO;
            if ($score > $limite) {
                error_log("[cesta] score $score limitado a $limite (progresso=$progresso, user=$userId)");
                $score = $limite;
            }

            $devido = cestaMoedasPorScore($score) * $pointsMultiplier;
            // O que já foi pago nesta partida não é pago de novo — o Flappy
            // pagava o trecho inicial duas vezes quando dava pra reviver.
            $pagas  = (int)($_SESSION['cesta_pagas'] ?? 0);
            $ganhou = max(0, $devido - $pagas);

            $pdo->beginTransaction();
            $pdo->prepare("INSERT INTO acerteacesta_historico (id_usuario, pontuacao) VALUES (?,?)")
                ->execute([$userId, $score]);
            if ($ganhou > 0) {
                $pdo->prepare("UPDATE games_usuarios SET pontos = COALESCE(pontos,0) + ? WHERE id = ?")
                    ->execute([$ganhou, $userId]);
            }
            $st = $pdo->prepare("SELECT COALESCE(pontos,0) FROM games_usuarios WHERE id = ?");
            $st->execute([$userId]);
            $saldo = (int)$st->fetchColumn();
            $pdo->commit();

            $_SESSION['cesta_run_ativa'] = false;
            $_SESSION['cesta_pagas']     = $pagas + $ganhou;

            $st = $pdo->prepare("SELECT MAX(pontuacao) FROM acerteacesta_historico WHERE id_usuario = ?");
            $st->execute([$userId]);

            echo json_encode(['sucesso' => true, 'score' => $score, 'moedas' => $ganhou,
                              'saldo' => $saldo, 'recorde' => (int)$st->fetchColumn()]);
            exit;
        }

        echo json_encode(['erro' => 'Ação desconhecida.']);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['erro' => $e->getMessage()]);
    }
    exit;
}

cestaTabela($pdo);
$usuario = ['nome' => 'Coach', 'pontos' => 0];
try {
    $stmt = $pdo->prepare('SELECT nome, pontos FROM games_usuarios WHERE id = :id');
    $stmt->execute([':id' => $userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $usuario['nome'] = $row['nome'];
        $usuario['pontos'] = (int)$row['pontos'];
    }
} catch (PDOException $e) {
    // Silencia falha de leitura, segue com defaults
}

// O recorde vinha de uma variável do navegador: recarregou a página, zerou.
$recorde = 0;
$ranking = [];
try {
    $st = $pdo->prepare('SELECT MAX(pontuacao) FROM acerteacesta_historico WHERE id_usuario = ?');
    $st->execute([$userId]);
    $recorde = (int)$st->fetchColumn();

    $st = $pdo->prepare("SELECT u.nome, MAX(h.pontuacao) AS recorde
                           FROM acerteacesta_historico h
                           JOIN games_usuarios u ON u.id = h.id_usuario
                          WHERE LOWER(u.email) <> ?
                       GROUP BY h.id_usuario, u.nome
                       ORDER BY recorde DESC, u.nome LIMIT 5");
    $st->execute(['medeirros99@gmail.com']);
    $ranking = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Ranking é enfeite: se falhar, o jogo continua de pé.
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>O Lance Livre Infinito - FBA games</title>
	<link rel="icon" type="image/png" href="/games/fbagames.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        :root {
            --bg: #0f0f12;
            --panel: #16171c;
            --panel-2: #1d1f27;
            --border: #252734;
            --accent: #fc0025;
            --accent-2: #ff7043;
            --text: #e9eaee;
            --muted: #9aa0b5;
        }

        body {
            background: radial-gradient(circle at 20% 20%, rgba(252,0,37,0.08), transparent 40%),
                        radial-gradient(circle at 80% 0%, rgba(255,255,255,0.05), transparent 45%),
                        #0b0c0f;
            color: var(--text);
            min-height: 100vh;
            font-family: 'Segoe UI', Arial, sans-serif;
        }

        .navbar-custom {
            background: linear-gradient(180deg, #181921 0%, #0f1015 100%);
            border-bottom: 1px solid var(--border);
            padding: 14px 18px;
            box-shadow: 0 12px 30px rgba(0,0,0,0.45);
        }

        .brand-name {
            font-weight: 900;
            letter-spacing: 0.4px;
            color: var(--text);
            text-decoration: none;
        }

        .saldo-badge {
            background: var(--accent);
            color: #fff;
            padding: 8px 14px;
            border-radius: 999px;
            font-weight: 800;
            box-shadow: 0 6px 18px rgba(252,0,37,0.3);
        }

        .container-main { max-width: 1180px; padding: 26px 18px 60px; margin: 0 auto; }

        .hero {
            background: linear-gradient(135deg, rgba(22,23,28,0.92), rgba(18,19,26,0.94));
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 22px;
            box-shadow: 0 14px 34px rgba(0,0,0,0.45);
            position: relative;
            overflow: hidden;
        }

        .hero::after {
            content: '';
            position: absolute;
            inset: 0;
            background: radial-gradient(circle at 70% 20%, rgba(252,0,37,0.14), transparent 45%);
            pointer-events: none;
        }

        .game-panel {
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 18px;
            height: 100%;
            box-shadow: 0 12px 24px rgba(0,0,0,0.35);
        }

        .court {
            position: relative;
            background: radial-gradient(circle at 50% 15%, rgba(255,255,255,0.05), transparent 55%),
                        linear-gradient(180deg, #11121a 0%, #0c0d14 100%);
            border: 1px solid var(--border);
            border-radius: 16px;
            min-height: 320px;
            overflow: hidden;
        }

        .court-grid {
            position: absolute;
            inset: 0;
            background: linear-gradient(90deg, rgba(255,255,255,0.04) 1px, transparent 1px),
                        linear-gradient(0deg, rgba(255,255,255,0.04) 1px, transparent 1px);
            background-size: 60px 60px;
            opacity: 0.5;
            pointer-events: none;
        }

        .hoop { position: absolute; top: 38px; right: 14px; width: 92px; height: 70px; }
        .backboard {
            position: absolute; top: 0; right: 10px; width: 72px; height: 54px;
            border: 2px solid rgba(255,255,255,0.35);
            border-radius: 8px;
            background: linear-gradient(180deg, rgba(255,255,255,0.08), rgba(255,255,255,0.02));
            box-shadow: 0 12px 26px rgba(0,0,0,0.35);
        }
        .rim {
            position: absolute; bottom: 6px; right: 0; width: 92px; height: 10px;
            border-radius: 10px;
            background: linear-gradient(90deg, #ff8748, #ff512f);
            box-shadow: 0 10px 20px rgba(255,81,47,0.35);
        }
        .net {
            position: absolute; bottom: -28px; right: 18px; width: 58px; height: 44px;
            background: repeating-linear-gradient(135deg, rgba(255,255,255,0.82) 0 6px, transparent 6px 12px),
                        repeating-linear-gradient(45deg, rgba(255,255,255,0.82) 0 6px, transparent 6px 12px);
            background-size: 12px 12px;
            transform: perspective(200px) rotateX(40deg);
            opacity: 0.9;
            filter: drop-shadow(0 6px 8px rgba(0,0,0,0.35));
        }

        .player-img { position: absolute; bottom: 0; left: 24px; width: clamp(120px, 18vw, 210px); filter: drop-shadow(0 16px 28px rgba(0,0,0,0.5)); }

        .ball {
            position: absolute; bottom: 28px; left: 50%; width: 36px; height: 36px;
            margin-left: -18px; border-radius: 50%;
            background: radial-gradient(circle at 30% 30%, #ffdb9d, #ff8b38 55%, #f05a24 100%);
            box-shadow: 0 10px 22px rgba(0,0,0,0.35);
            z-index: 2;
        }
        /* O arremesso mira no aro DE VERDADE.
           Antes o destino era fixo — translate(170px, -240px) — mas o aro está
           ancorado na direita da quadra, que muda de largura com a tela. Num
           monitor largo a bola parava no meio do ar, bem antes da cesta; no
           celular passava do outro lado. Era esse o "a animação não funciona".
           Agora o JS mede onde o aro está e escreve em --dx/--dy antes de cada
           arremesso, então o alvo é o mesmo em qualquer tamanho. */
        .ball.shoot-success { animation: shotSuccess 0.9s cubic-bezier(.3,.7,.4,1) forwards; }
        .ball.shoot-miss    { animation: shotMiss 0.75s ease-in forwards; }

        /* Sem `-50%` nos keyframes: a bola já é centralizada pelo margin-left, e
           somar os dois deslocava o alvo em 18px — a bola passava raspando ao
           lado do aro em vez de entrar. --dx/--dy são medidos a partir da
           posição parada, então o destino tem que ser exatamente eles. */
        @keyframes shotSuccess {
            0%   { transform: translate(0, 0) scale(1); opacity: 1; }
            /* Ápice acima do aro: sem passar por cima, o arremesso vira um
               passe reto e não parece uma bola de basquete. */
            45%  { transform: translate(calc(var(--dx) * 0.58), calc(var(--dy) - 62px)) scale(0.93); opacity: 1; }
            72%  { transform: translate(var(--dx), var(--dy)) scale(0.86); opacity: 1; }
            100% { transform: translate(var(--dx), calc(var(--dy) + 52px)) scale(0.8); opacity: 0; }
        }
        @keyframes shotMiss {
            0%   { transform: translate(0, 0) scale(1); opacity: 1; }
            40%  { transform: translate(calc(var(--dx) * 0.72), calc(var(--dy) - 34px)) scale(0.9); opacity: 1; }
            /* Bate na frente do aro e volta: o erro precisa ser visível, senão
               a única diferença entre acertar e errar é o texto mudando. */
            58%  { transform: translate(calc(var(--dx) * 0.82), calc(var(--dy) - 6px)) scale(0.88) rotate(-12deg); opacity: 1; }
            100% { transform: translate(calc(var(--dx) * 0.45), 24px) scale(0.84) rotate(-26deg); opacity: 0; }
        }

        .meter {
            position: relative;
            background: linear-gradient(90deg, rgba(252,0,37,0.16), rgba(255,255,255,0.08));
            border: 1px solid rgba(255,255,255,0.12);
            height: 24px;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: inset 0 0 18px rgba(0,0,0,0.45), 0 6px 14px rgba(0,0,0,0.28);
        }
        .meter .sweet { position: absolute; top: 0; height: 100%; background: linear-gradient(90deg, rgba(20,170,115,0.24), rgba(48,211,150,0.34)); border-left: 2px solid rgba(48,211,150,0.8); border-right: 2px solid rgba(48,211,150,0.8); box-shadow: inset 0 0 14px rgba(48,211,150,0.55); }
        .meter .marker { position: absolute; top: -6px; width: 6px; height: 36px; background: linear-gradient(180deg, #ffb347, #ff6a00); border-radius: 8px; box-shadow: 0 8px 18px rgba(255,106,0,0.4); }
        .meter.shake { animation: meterShake 0.4s ease; }
        @keyframes meterShake { 0% { transform: translateX(0); } 25% { transform: translateX(-6px);} 50% { transform: translateX(6px);} 75% { transform: translateX(-4px);} 100% { transform: translateX(0);} }

        .stat-card { background: var(--panel-2); border: 1px solid var(--border); border-radius: 14px; padding: 14px; }
        .stat-label { color: var(--muted); font-weight: 600; font-size: 0.9rem; }
        .stat-value { font-size: 1.6rem; font-weight: 800; }

        .btn-accent { background: linear-gradient(135deg, var(--accent), var(--accent-2)); border: none; color: #fff; font-weight: 700; }
        .btn-accent:hover { filter: brightness(1.06); }
        .btn-ghost { border: 1px solid var(--border); color: var(--text); }

        .overlay { position: absolute; inset: 0; display: none; align-items: center; justify-content: center; background: rgba(0,0,0,0.65); backdrop-filter: blur(4px); border-radius: 16px; z-index: 5; text-align: center; }
        .overlay.active { display: flex; }
        .overlay-card { background: #13141b; padding: 26px; border-radius: 16px; border: 1px solid var(--border); box-shadow: 0 12px 30px rgba(0,0,0,0.45); min-width: 280px; }

        .tag { display: inline-flex; align-items: center; gap: 6px; padding: 6px 10px; border-radius: 12px; background: rgba(255,255,255,0.06); border: 1px solid var(--border); font-weight: 700; color: #fff; }
    </style>
</head>
<body>

<div class="navbar-custom d-flex justify-content-between align-items-center sticky-top">
    <div class="d-flex align-items-center gap-3">
        <a class="brand-name" href="/games.php">🎮 FBA games</a>
        <span class="text-secondary small">🏀 O Lance Livre Infinito</span>
    </div>
    <div class="d-flex align-items-center gap-2">
        <span class="text-secondary d-none d-md-inline">Olá, <strong class="text-white"><?= htmlspecialchars($usuario['nome']) ?></strong></span>
        <span class="saldo-badge" id="saldoDisplay"><img src="../moeda.png" style="width:14px;height:14px;object-fit:contain;vertical-align:middle"> <?= number_format($usuario['pontos'], 0, ',', '.') ?> pts</span>
        <a href="/games.php" class="btn btn-outline-secondary btn-sm border-0"><i class="bi bi-arrow-left"></i> Voltar</a>
    </div>
</div>

<div class="container-main">
    <div class="hero mb-4">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 position-relative" style="z-index:1;">
            <div>
                <div class="tag mb-2"><i class="bi bi-lightning-charge"></i><span>Timing puro</span></div>
                <h2 class="mb-1">O Lance Livre Infinito</h2>
                <p class="mb-0 text-secondary">Acerte o marcador no verde. Cada cesta aumenta a velocidade. Duas vidas apenas.</p>
            </div>
            <img src="/games/lebron.png" alt="Jogador" class="img-fluid" style="max-height: 160px; filter: drop-shadow(0 16px 28px rgba(0,0,0,0.4));">
        </div>
    </div>

    <div class="row g-3">
        <div class="col-lg-8">
            <div class="game-panel position-relative">
                <div class="court mb-3">
                    <div class="court-grid"></div>
                    <div class="hoop">
                        <div class="backboard"></div>
                        <div class="rim"></div>
                        <div class="net"></div>
                    </div>
                    <img src="/games/lebron.png" alt="Jogador" class="player-img">
                    <div class="ball" id="ball"></div>
                    <div class="overlay" id="overlay">
                        <div class="overlay-card">
                            <h4 class="mb-2" id="overlayTitle">Pronto?</h4>
                            <p class="text-secondary mb-3" id="overlayText">Clique no verde para marcar.</p>
                            <button class="btn btn-accent w-100" id="overlayButton">Começar</button>
                        </div>
                    </div>
                </div>

                <div class="meter" id="meter">
                    <div class="sweet" id="sweet"></div>
                    <div class="marker" id="marker"></div>
                </div>
                <div class="d-flex align-items-center justify-content-between mt-2 flex-wrap gap-2">
                    <div class="text-secondary" id="feedback">Clique ou use Espaço quando o marcador entrar na faixa verde.</div>
                    <small class="text-secondary">Controles: clique / Espaço</small>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="game-panel h-100">
                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <div class="stat-card text-center">
                            <div class="stat-label">Pontuação</div>
                            <div class="stat-value" id="score">0</div>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="stat-card text-center">
                            <div class="stat-label">Recorde</div>
                            <div class="stat-value" id="best"><?= (int)$recorde ?></div>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="stat-card text-center">
                            <div class="stat-label">Vidas</div>
                            <div class="stat-value" id="lives"></div>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="stat-card text-center">
                            <div class="stat-label">Nível</div>
                            <div class="stat-value" id="speed" title="a barra acelera e o verde encolhe a cada 5 cestas">1</div>
                        </div>
                    </div>
                </div>
                <div class="d-flex gap-2">
                    <button class="btn btn-accent w-100" id="shootBtn"><i class="bi bi-basket2-fill me-1"></i>Arremessar</button>
                    <button class="btn btn-ghost w-50" id="resetBtn"><i class="bi bi-arrow-repeat me-1"></i>Reset</button>
                </div>
                <div class="mt-3 text-secondary small">
                    <ul class="mb-0 ps-3">
                        <li><b>A cada 5 cestas</b> sobe um nível: a barra acelera e o verde encolhe.</li>
                        <li>Cada 5 cestas também valem <b>5 moedas</b>, sem teto por partida.</li>
                        <li>Duas vidas: errou duas vezes, fim de jogo.</li>
                    </ul>
                </div>

                <?php if ($ranking): ?>
                <div class="mt-3 pt-3" style="border-top:1px solid var(--border)">
                    <div class="stat-label mb-2"><i class="bi bi-trophy-fill" style="color:#f5c542"></i> Melhores marcas</div>
                    <?php foreach ($ranking as $i => $r): ?>
                    <div class="d-flex justify-content-between align-items-center small py-1">
                        <span class="text-secondary text-truncate" style="max-width:70%">
                            <b style="color:<?= $i === 0 ? '#f5c542' : 'var(--text-2, #8b8b95)' ?>"><?= $i + 1 ?>º</b>
                            <?= htmlspecialchars($r['nome']) ?>
                        </span>
                        <b><?= (int)$r['recorde'] ?></b>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
(() => {
    const marker = document.getElementById('marker');
    const sweetEl = document.getElementById('sweet');
    const meter = document.getElementById('meter');
    const ball = document.getElementById('ball');
    const feedback = document.getElementById('feedback');
    const overlay = document.getElementById('overlay');
    const overlayBtn = document.getElementById('overlayButton');
    const overlayTitle = document.getElementById('overlayTitle');
    const overlayText = document.getElementById('overlayText');
    const scoreEl = document.getElementById('score');
    const bestEl = document.getElementById('best');
    const livesEl = document.getElementById('lives');
    const speedEl = document.getElementById('speed');
    const saldoEl = document.getElementById('saldoDisplay');
    const moedaIcone = '<img src="../moeda.png" style="width:14px;height:14px;object-fit:contain;vertical-align:middle">';
    const shootBtn = document.getElementById('shootBtn');
    const resetBtn = document.getElementById('resetBtn');

    let progress = 0.5;
    let direction = 1;
    let lastTime = null;
    let score = 0;
    let best = <?= (int)$recorde ?>;   // vem do banco: antes era do navegador e sumia no F5
    let lives = 2;
    let isRunning = false;
    let isGameOver = false;

    /* ── PROGRESSÃO ────────────────────────────────────────────────────────
       A dificuldade anda em DEGRAUS de 5 cestas, não a cada acerto.

       Antes subia ponto a ponto (velocidade +0.09, zona -0.012): a zona batia
       no mínimo lá pelo 12º arremesso e a velocidade seguia crescendo sem teto,
       então em pouco tempo o jogo virava sorteio — acertar deixava de depender
       de reflexo e passava a depender de soltar em qualquer lugar e torcer.

       Agora cada degrau é um degrau de verdade, e é o MESMO 5 do prêmio e do
       ping: "mais cinco cestas" significa subir de nível, ganhar 5 moedas e
       mandar progresso. Um número só pra pessoa guardar.

       A velocidade tem teto (2.2x, no nível 21) e a zona tem piso (5% da barra,
       no nível 9). Depois disso o jogo para de endurecer: quem chegou lá está
       jogando no limite da mão, e apertar mais só transformaria a partida em
       moeda ao ar. */
    const CESTAS_POR_NIVEL = 5;
    const baseSpeed = 0.5;   // voltas por segundo no nível 0
    const speedStep = 0.08;  // por nível
    const maxSpeed  = 2.2;
    const maxZone   = 0.22;  // fração da barra no nível 0
    const zoneStep  = 0.02;  // por nível
    const minZone   = 0.05;

    const nivelDe = (s) => Math.floor(s / CESTAS_POR_NIVEL);
    const velocidadeDe = (s) => Math.min(maxSpeed, baseSpeed + nivelDe(s) * speedStep);
    const zonaDe = (s) => Math.max(minZone, maxZone - nivelDe(s) * zoneStep);

    const updateLives = () => {
        livesEl.innerHTML = '';
        for (let i = 0; i < 2; i += 1) {
            const icon = document.createElement('i');
            icon.className = i < lives ? 'bi bi-heart-fill text-danger' : 'bi bi-heart text-secondary';
            livesEl.appendChild(icon);
            if (i === 0) {
                livesEl.appendChild(document.createTextNode(' '));
            }
        }
    };

    const updateSpeed = () => {
        const nivel = nivelDe(score);
        speedEl.textContent = nivel + 1;
        speedEl.title = velocidadeDe(score).toFixed(2) + "x · zona " + Math.round(zonaDe(score) * 100) + "%";
    };

    const updateSweet = (randomize = false) => {
        const width = zonaDe(score);
        let start = 0.5 - width / 2;
        if (randomize) {
            const margin = 0.04;
            const maxStart = 1 - width - margin;
            const minStart = margin;
            start = Math.random() * (maxStart - minStart) + minStart;
        }
        sweetEl.style.left = `${start * 100}%`;
        sweetEl.style.width = `${width * 100}%`;
    };

    const resetBallAnim = () => {
        ball.classList.remove('shoot-success', 'shoot-miss');
        void ball.offsetWidth;
    };

    /**
     * Mede onde está o aro e diz isso pra animação.
     *
     * É o que faz a bola chegar na cesta em qualquer largura de tela: as duas
     * posições são lidas na hora do arremesso, então redimensionar a janela ou
     * virar o celular não desalinha nada.
     */
    const mirarNoAro = () => {
        const aro = document.querySelector('.rim');
        if (!aro) return;
        const b = ball.getBoundingClientRect();
        const a = aro.getBoundingClientRect();
        ball.style.setProperty('--dx', ((a.left + a.width / 2) - (b.left + b.width / 2)) + 'px');
        ball.style.setProperty('--dy', ((a.top + a.height / 2) - (b.top + b.height / 2)) + 'px');
    };

    // ── Conversa com o servidor ────────────────────────────────────────────
    // Quem conta os pontos é a tela, mas quem PAGA é o servidor: ele abre a
    // partida, acompanha o ritmo e decide o prêmio. Mandar o total no fim sem
    // nada no meio seria acreditar em qualquer número que chegasse.
    const enviar = (acao, extra = {}) => {
        const fd = new FormData();
        fd.append('acao', acao);
        Object.entries(extra).forEach(([k, v]) => fd.append(k, v));
        return fetch(location.href, { method: 'POST', body: fd })
            .then(r => r.json())
            .catch(() => ({ erro: 'sem resposta' }));
    };

    let ultimoPing = 0;
    const reportarProgresso = () => {
        if (score - ultimoPing < 5) return;
        ultimoPing = score;
        enviar('progresso', { score }).then(d => {
            if (!d || !d.sucesso) return;
            // O prêmio aparece na tela vindo do servidor, nunca calculado aqui.
            setFeedback(`${score} cestas · ${d.moedas} moedas garantidas · próximas 5 valem ${d.proximo}`, true);
        });
    };

    const setFeedback = (text, positive = true) => {
        feedback.textContent = text;
        if (!positive) {
            meter.classList.add('shake');
            setTimeout(() => meter.classList.remove('shake'), 360);
        }
    };

    const animate = (timestamp) => {
        if (!isRunning) return;

        if (!lastTime) {
            lastTime = timestamp;
        }
        const delta = (timestamp - lastTime) / 1000;
        lastTime = timestamp;

        progress += direction * velocidadeDe(score) * delta;

        if (progress >= 1) {
            progress = 1;
            direction = -1;
        } else if (progress <= 0) {
            progress = 0;
            direction = 1;
        }

        marker.style.left = `${progress * 100}%`;
        requestAnimationFrame(animate);
    };

    const shoot = () => {
        if (!isRunning || isGameOver) return;

        const width = parseFloat(sweetEl.style.width) / 100 || 0.16;
        const start = parseFloat(sweetEl.style.left) / 100 || 0.42;
        const end = start + width;

        resetBallAnim();
        mirarNoAro();

        if (progress >= start && progress <= end) {
            const nivelAntes = nivelDe(score);
            score += 1;
            best = Math.max(best, score);
            scoreEl.textContent = score;
            bestEl.textContent = best;
            // Subir de nível é o único momento em que o jogo muda de verdade;
            // sem avisar, a barra simplesmente acelera e parece bug.
            setFeedback(nivelDe(score) > nivelAntes
                ? `Cesta! Nível ${nivelDe(score) + 1} — barra mais rápida, verde menor`
                : 'Cesta! +1 ponto', true);
            ball.classList.add('shoot-success');
            reportarProgresso();
            updateSweet(true);
        } else {
            lives -= 1;
            updateLives();
            setFeedback('Errou! -1 vida', false);
            ball.classList.add('shoot-miss');

            if (lives <= 0) {
                isGameOver = true;
                isRunning = false;
                overlay.classList.add('active');
                overlayTitle.textContent = 'Fim de jogo';
                overlayText.textContent = 'Apurando as moedas…';
                overlayBtn.textContent = 'Jogar de novo';

                enviar('salvar', { score }).then(d => {
                    if (!d || !d.sucesso) {
                        // Falhar calado faria a pessoa achar que o jogo comeu as
                        // moedas dela — o placar aparece, o prêmio não, e não há
                        // como saber se foi assim mesmo.
                        overlayText.textContent = 'Não deu pra registrar esta partida. O placar valeu, as moedas não.';
                        return;
                    }
                    saldoEl.innerHTML = moedaIcone + ' ' + d.saldo.toLocaleString('pt-BR') + ' pts';
                    if (d.recorde > best) { best = d.recorde; }
                    bestEl.textContent = best;
                    // Duas causas bem diferentes pra zero moedas, e dizer a errada
                    // parece defeito: quem não fechou as 5 primeiras precisa saber
                    // quanto falta.
                    overlayText.textContent = d.moedas > 0
                        ? `${d.score} cestas · +${d.moedas} moedas`
                        : `${d.score} cestas · chegue a 5 pra ganhar as primeiras 5 moedas`;
                });
                return;
            }
        }

        updateSpeed();
        if (!isGameOver) {
            updateSweet(true);
        }
    };

    const resetGame = () => {
        score = 0;
        lives = 2;
        progress = 0.5;
        direction = 1;
        isRunning = true;
        isGameOver = false;
        lastTime = null;
        ultimoPing = 0;
        // Abre a partida no servidor. Sem isto ele recusa o placar no fim —
        // e é o que impede mandar um score sem ter jogado.
        enviar('iniciar');
        scoreEl.textContent = '0';
        setFeedback('Clique ou Espaço no verde', true);
        updateLives();
        updateSpeed();
        updateSweet(true);
        resetBallAnim();
        overlay.classList.remove('active');
        requestAnimationFrame(animate);
    };

    // Um caminho só pra começar a jogar. Antes o primeiro clique tinha um
    // atalho próprio que só escondia o overlay — e esse atalho não abria a
    // partida no servidor, então a primeira partida de cada visita terminava
    // com o placar na tela e o prêmio recusado.
    overlayBtn.addEventListener('click', resetGame);

    shootBtn.addEventListener('click', shoot);
    resetBtn.addEventListener('click', resetGame);
    meter.addEventListener('click', shoot);

    document.addEventListener('keydown', (ev) => {
        if (ev.code === 'Space') {
            ev.preventDefault();
            shoot();
        }
        if (ev.code === 'KeyR') {
            resetGame();
        }
    });

    updateLives();
    updateSpeed();
    updateSweet();
    overlay.classList.add('active');
})();
</script>
</body>
</html>
