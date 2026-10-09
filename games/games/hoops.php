<?php
/**
 * FBA HOOPS — o "Ultimate Team" do basquete.
 *
 * Doze vagas; em cada uma um pacote abre com cinco cartas e você fica com
 * uma. Com o elenco pronto, o jogo simula uma temporada inteira da NBA —
 * 82 jogos, play-in e playoffs — e paga moedas pela fase alcançada.
 *
 * ── QUEM DECIDE É O SERVIDOR, O NAVEGADOR SÓ ASSISTE ─────────────────
 *
 * As cinco cartas de cada pacote são sorteadas aqui e ficam na sessão até
 * a escolha: um F5 mostra as MESMAS cinco, senão o pacote virava roleta. A
 * temporada é simulada inteira no clique de "Simular", gravada e paga ANTES
 * da animação começar — a tela recebe o resultado pronto e só conta a
 * história. Fechar a aba no meio não muda nada; o prêmio já está na conta.
 *
 * Depois de simulada, a temporada leva o draft embora. O mesmo time não
 * pode ser simulado de novo até a sorte dar um título.
 *
 * @see games/core/hoops.php            pacote, química e força
 * @see games/core/hoops_temporada.php  os 82 jogos e os playoffs
 * @see games/core/hoops_premios.php    quanto paga cada fase
 */

require '../core/conexao.php';
require_once __DIR__ . '/../core/hoops_temporada.php';
require_once __DIR__ . '/../core/hoops_premios.php';

if (!isset($_SESSION['user_id'])) { header('Location: /login.php'); exit; }
$user_id = (int)$_SESSION['user_id'];

$pdo->exec("CREATE TABLE IF NOT EXISTS hoops_temporadas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_usuario INT NOT NULL,
    nome_time VARCHAR(60) NOT NULL,
    elenco_json TEXT NOT NULL,
    forca DECIMAL(5,1) NOT NULL,
    quimica INT NOT NULL,
    semente INT NOT NULL,
    vitorias INT NOT NULL,
    derrotas INT NOT NULL,
    posicao INT NOT NULL,
    conf CHAR(1) NOT NULL,
    fase VARCHAR(10) NOT NULL,
    moedas INT NOT NULL DEFAULT 0,
    premiada TINYINT(1) NOT NULL DEFAULT 0,
    mestre TINYINT(1) NOT NULL DEFAULT 0,
    replay_json MEDIUMTEXT NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_hoops_user (id_usuario, criado_em),
    INDEX idx_hoops_fase (fase, vitorias)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

/**
 * ── O CÓDIGO MESTRE ──────────────────────────────────────────────────
 *
 * Pedido do Victor (08/10/2026): um código que, digitado com o jogo
 * aberto, dá o título em 100% das temporadas — pra testar e mostrar a
 * comemoração sem depender de 1% de sorte.
 *
 * Ele NUNCA vai pro navegador: o JavaScript só tem o sha256 dele, e quem
 * confere o texto de verdade é esta página. Temporada com o código ligado
 * não paga moeda, não gasta uma das premiadas do dia e não entra no Salão
 * da fama — título de código não pode ficar ao lado de título de verdade.
 * Digitar de novo desliga.
 */
const HOOPS_CODIGO_MESTRE = 'dirk41';
/** Força extra do time com o código — e a temporada é refeita até sair o título. */
const HOOPS_MESTRE_BONUS = 12.0;

/** A ordem das fases, pro ranking: campeão em cima. */
const HOOPS_ORDEM_FASE = ['fora' => 0, 'playin' => 1, 'r1' => 2, 'r2' => 3, 'cf' => 4, 'final' => 5, 'campeao' => 6];

function hoopsSaldo(PDO $pdo, int $uid): int
{
    $st = $pdo->prepare('SELECT pontos FROM games_usuarios WHERE id = ?');
    $st->execute([$uid]);
    return (int)$st->fetchColumn();
}

function hoopsPremiadasHoje(PDO $pdo, int $uid): int
{
    $st = $pdo->prepare('SELECT COUNT(*) FROM hoops_temporadas WHERE id_usuario = ? AND premiada = 1 AND criado_em >= CURDATE()');
    $st->execute([$uid]);
    return (int)$st->fetchColumn();
}

/** O nome da franquia do GM no site ("Las Vegas Coyotes"). */
function hoopsNomeDoTime(PDO $pdo, int $uid): string
{
    try {
        $st = $pdo->prepare('SELECT city, name FROM teams WHERE user_id = ? LIMIT 1');
        $st->execute([$uid]);
        if ($t = $st->fetch(PDO::FETCH_ASSOC)) {
            $nome = trim(trim((string)$t['city']) . ' ' . trim((string)$t['name']));
            if ($nome !== '') return mb_substr($nome, 0, 60);
        }
    } catch (Throwable $e) {}
    $n = trim((string)($_SESSION['nome'] ?? $_SESSION['user_name'] ?? ''));
    return $n !== '' ? mb_substr('Time de ' . $n, 0, 60) : 'Seu time';
}

/** O draft em andamento, do jeito que a tela precisa. */
function hoopsEstado(?array $d): array
{
    if (!$d) return ['ativo' => false];
    $time = $d['time'];
    $q = hoopsQuimica($time);
    $f = hoopsForca($time, $q['jogadores']);

    $opcoes = null;
    if ($d['opcoes'] !== null) {
        // Pra cada carta do pacote: como ficariam a força e a química se
        // ela fosse a escolhida. É o que o FIFA mostra, e é o que faz a
        // escolha ser escolha e não só "a de OVR maior".
        $opcoes = [];
        foreach ($d['opcoes'] as $c) {
            $t = $time; $t[$d['aberta']] = $c;
            $qq = hoopsQuimica($t);
            $opcoes[] = hoopsCartaParaTela($c) + [
                'sim_forca'   => hoopsForca($t, $qq['jogadores'])['forca'],
                'sim_quimica' => $qq['total'],
            ];
        }
    }
    return [
        'ativo'   => true,
        'time'    => array_map(fn($c) => $c ? hoopsCartaParaTela($c) : null, $time),
        'quimica' => $q,
        'forca'   => $f,
        'aberta'  => $d['aberta'],
        'opcoes'  => $opcoes,
        'cheio'   => count(array_filter($time)) === HOOPS_VAGAS,
    ];
}

/** O resultado da temporada, enxuto, pra animação. */
function hoopsReplay(array $r, array $time, int $premio, bool $premiada, bool $mestre = false): array
{
    $times = [];
    foreach ($r['times'] as $s => $t) $times[$s] = [$t['nome'], $t['curto'], $t['logo'], $t['cor'], $t['conf'], $t['forca']];
    // Do jogo do GM, só o destaque — quem mais pontuou.
    $destaques = [];
    foreach ($r['linhas'] as $i => $porVaga) {
        $melhor = null;
        foreach ($porVaga as $v => $l) if (!$melhor || $l['pts'] > $melhor[1]) $melhor = [$v, $l['pts'], $l['reb'], $l['ast']];
        $destaques[$i] = $melhor;
    }
    return [
        'times'     => $times,
        'conf'      => $r['conf'],
        'cedeu'     => $r['cedeu'],
        'forca'     => $r['forca']['forca'],
        'jogos'     => $r['jogos'],
        'destaques' => $destaques,
        'ordem'     => $r['ordem'],
        'tabela'    => $r['tabela'],
        'playin'    => $r['playin'],
        'chave'     => $r['chave'],
        'final'     => $r['final'],
        'campeao'   => $r['campeao'],
        'fase'      => $r['fase'],
        'fase_nome' => HOOPS_FASES[$r['fase']],
        'v'         => $r['v'],
        'd'         => $r['d'],
        'posicao'   => $r['posicao'],
        'premio'    => $premio,
        'premiada'  => $premiada,
        'mestre'    => $mestre,
        'elenco'    => array_map(fn($c) => $c ? hoopsCartaParaTela($c) : null, $time),
        'medias'    => $r['medias'],
    ];
}

$d = &$_SESSION['hoops'];

/* ═══════════════════════════ AÇÕES (JSON) ═══════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $acao = (string)($_POST['acao'] ?? '');
    $resp = fn(array $x) => exit(json_encode($x, JSON_UNESCAPED_UNICODE));

    if ($acao === 'novo') {
        $d = ['time' => array_fill(0, HOOPS_VAGAS, null), 'aberta' => null, 'opcoes' => null];
        $resp(['ok' => true, 'estado' => hoopsEstado($d)]);
    }

    // Liga/desliga o código mestre. Quem compara é o servidor.
    if ($acao === 'codigo') {
        if (!hash_equals(HOOPS_CODIGO_MESTRE, strtolower(trim((string)($_POST['codigo'] ?? ''))))) {
            $resp(['ok' => false]);
        }
        $_SESSION['hoops_mestre'] = empty($_SESSION['hoops_mestre']);
        $resp(['ok' => true, 'mestre' => $_SESSION['hoops_mestre']]);
    }

    if ($acao === 'desistir') {
        $d = null;
        $resp(['ok' => true, 'estado' => hoopsEstado(null)]);
    }

    if (!$d) $resp(['ok' => false, 'erro' => 'Nenhum draft em andamento.']);

    /* ABRIR O PACOTE DE UMA VAGA. Se já houver um aberto, devolve O MESMO
       — inclusive pra outra vaga: só se abre o próximo depois de escolher.
       É o que impede abrir as doze vagas, olhar tudo e escolher a dedo. */
    if ($acao === 'abrir') {
        $v = (int)($_POST['vaga'] ?? -1);
        if ($d['opcoes'] === null) {
            if ($v < 0 || $v >= HOOPS_VAGAS || $d['time'][$v]) $resp(['ok' => false, 'erro' => 'Vaga inválida.']);
            $usados = array_column(array_filter($d['time']), 'id');
            $d['aberta'] = $v;
            $d['opcoes'] = hoopsAbrirPacote($v, $usados);
        }
        $resp(['ok' => true, 'estado' => hoopsEstado($d)]);
    }

    if ($acao === 'escolher') {
        $i = (int)($_POST['carta'] ?? -1);
        if ($d['opcoes'] === null || !isset($d['opcoes'][$i])) $resp(['ok' => false, 'erro' => 'Carta inválida.']);
        $d['time'][$d['aberta']] = $d['opcoes'][$i];
        $d['aberta'] = null;
        $d['opcoes'] = null;
        $resp(['ok' => true, 'estado' => hoopsEstado($d)]);
    }

    /* TROCAR DUAS VAGAS DE LUGAR — é o escalar. Vale entre quinteto e
       banco, e é o que conserta um titular fora de posição. */
    if ($acao === 'trocar') {
        $a = (int)($_POST['a'] ?? -1); $b = (int)($_POST['b'] ?? -1);
        if ($a >= 0 && $b >= 0 && $a < HOOPS_VAGAS && $b < HOOPS_VAGAS && $a !== $b && $d['opcoes'] === null) {
            [$d['time'][$a], $d['time'][$b]] = [$d['time'][$b], $d['time'][$a]];
        }
        $resp(['ok' => true, 'estado' => hoopsEstado($d)]);
    }

    /* SIMULAR. Tudo numa transação: a temporada é gravada e o prêmio cai
       juntos, ou nada acontece. O FOR UPDATE na linha do GM enfileira duas
       simulações simultâneas (duas abas) — sem ele as duas contariam como
       a terceira premiada do dia e as duas pagariam. */
    if ($acao === 'jogar') {
        if (count(array_filter($d['time'])) !== HOOPS_VAGAS) $resp(['ok' => false, 'erro' => 'Complete as doze vagas.']);
        $time = $d['time'];
        $nome = hoopsNomeDoTime($pdo, $user_id);
        $mestre = !empty($_SESSION['hoops_mestre']);
        /* Com o código, o time joga com HOOPS_MESTRE_BONUS a mais e a
           temporada é refeita (outra semente) até sair o título. Com o bônus
           o título vem quase sempre na primeira; o teto de 400 é só pra uma
           sequência de azar absurda não prender a requisição. */
        for ($tentativa = 0; $tentativa < ($mestre ? 400 : 1); $tentativa++) {
            $semente = random_int(1, 2000000000);
            $r = hoopsTemporada($time, $semente, $nome, false, $mestre ? HOOPS_MESTRE_BONUS : 0);
            if ($r['fase'] === 'campeao') break;
        }

        try {
            $pdo->beginTransaction();
            $pdo->prepare('SELECT pontos FROM games_usuarios WHERE id = ? FOR UPDATE')->execute([$user_id]);
            $premiada = !$mestre && hoopsPremiadasHoje($pdo, $user_id) < HOOPS_PREMIADAS_POR_DIA;
            $premio = $premiada ? HOOPS_PREMIO[$r['fase']] * getGamePointsMultiplier($pdo, 'hoops') : 0;
            $replay = hoopsReplay($r, $time, $premio, $premiada, $mestre);
            $pdo->prepare('INSERT INTO hoops_temporadas
                (id_usuario, nome_time, elenco_json, forca, quimica, semente, vitorias, derrotas, posicao, conf, fase, moedas, premiada, mestre, replay_json)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$user_id, $nome, json_encode(array_column($time, 'id')), $r['forca']['forca'],
                           hoopsQuimica($time)['total'], $semente, $r['v'], $r['d'], $r['posicao'], $r['conf'],
                           $r['fase'], $premio, $premiada ? 1 : 0, $mestre ? 1 : 0, json_encode($replay, JSON_UNESCAPED_UNICODE)]);
            $id = (int)$pdo->lastInsertId();
            if ($premio > 0) {
                $pdo->prepare('UPDATE games_usuarios SET pontos = pontos + ? WHERE id = ?')->execute([$premio, $user_id]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('[hoops] jogar: ' . $e->getMessage());
            $resp(['ok' => false, 'erro' => 'Não deu pra simular agora. Tente de novo.']);
        }
        $d = null;
        $resp(['ok' => true, 'id' => $id, 'replay' => $replay, 'saldo' => hoopsSaldo($pdo, $user_id)]);
    }

    $resp(['ok' => false, 'erro' => 'Ação desconhecida.']);
}

/* ═══════════════════════════ A PÁGINA ═══════════════════════════════ */

// Rever uma temporada já jogada (o link do salão).
$rever = null;
if (isset($_GET['t'])) {
    $st = $pdo->prepare('SELECT replay_json FROM hoops_temporadas WHERE id = ?');
    $st->execute([(int)$_GET['t']]);
    $json = $st->fetchColumn();
    if ($json) $rever = json_decode($json, true);
}

$salao = $pdo->query("SELECT t.id, t.nome_time, t.vitorias, t.derrotas, t.fase, t.forca, t.criado_em, u.nome AS gm
    FROM hoops_temporadas t JOIN games_usuarios u ON u.id = t.id_usuario
    WHERE t.mestre = 0
    ORDER BY FIELD(t.fase,'campeao','final','cf','r2','r1','playin','fora'), t.vitorias DESC, t.id ASC
    LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
$stMinhas = $pdo->prepare('SELECT id, vitorias, derrotas, fase, moedas, premiada, mestre, criado_em
    FROM hoops_temporadas WHERE id_usuario = ? ORDER BY id DESC LIMIT 8');
$stMinhas->execute([$user_id]);
$minhas = $stMinhas->fetchAll(PDO::FETCH_ASSOC);

// A régua pra quem está montando: de quanto é a força dos times de verdade.
$forcasNba = array_column(hoopsTimesNba(), 'forca');
sort($forcasNba);

$inicial = [
    'nba_forca' => [round(reset($forcasNba)), $forcasNba[intdiv(count($forcasNba), 2)], round(end($forcasNba))],
    'estado'    => hoopsEstado($d),
    'saldo'     => hoopsSaldo($pdo, $user_id),
    'restantes' => max(0, HOOPS_PREMIADAS_POR_DIA - hoopsPremiadasHoje($pdo, $user_id)),
    'premios'   => HOOPS_PREMIO,
    'fases'     => HOOPS_FASES,
    'dobro'     => getGamePointsMultiplier($pdo, 'hoops'),
    'atributos' => HOOPS_ATRIBUTOS,
    'titulares' => HOOPS_TITULARES,
    'minutos'   => HOOPS_MINUTOS,
    'rever'     => $rever,
    'mestre'    => !empty($_SESSION['hoops_mestre']),
    'mestre_hash' => hash('sha256', HOOPS_CODIGO_MESTRE),
    'mestre_len'  => strlen(HOOPS_CODIGO_MESTRE),
    'gerado_em' => hoopsDados()['gerado_em'] ?? null,
];
$e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="pt-BR" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>FBA Hoops</title>
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700;800;900&family=Oswald:wght@500;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<link rel="stylesheet" href="hoops.css?v=12">
</head>
<body>

<div class="topo">
  <div class="topo-esq">
    <a href="/games.php" class="voltar" title="Voltar aos games"><i class="bi bi-arrow-left"></i></a>
    <h1>FBA <span>HOOPS</span></h1>
  </div>
  <div class="saldo"><i class="bi bi-coin"></i> <b id="saldo"><?= number_format($inicial['saldo'], 0, ',', '.') ?></b></div>
</div>

<main class="wrap" id="app">
  <!-- ── SALÃO ─────────────────────────────────────────────────────── -->
  <section id="tela-salao" class="tela">
    <div class="hero">
      <div class="hero-txt">
        <h2>Monte doze. Jogue 82.</h2>
        <p>Abra um pacote por vaga, fique com uma de cinco cartas, e veja até onde o seu time chega numa temporada inteira da NBA — contra os elencos de verdade.</p>
        <div class="hero-acoes">
          <button class="btn pri grande" id="bt-comecar"><i class="bi bi-box-seam"></i> <span>Começar draft</span></button>
        </div>
        <p class="hero-nota" id="nota-premiadas"></p>
      </div>
      <div class="hero-pacote" aria-hidden="true"><div class="pacote mini"><div class="pacote-brilho"></div><div class="pacote-logo">FBA<br><b>HOOPS</b></div></div></div>
    </div>

    <div class="colunas">
      <div class="bloco">
        <h3><i class="bi bi-trophy-fill"></i> Prêmios por fase</h3>
        <table class="premios" id="tabela-premios"></table>
        <p class="sub">Até <?= HOOPS_PREMIADAS_POR_DIA ?> temporadas premiadas por dia. Depois disso dá pra continuar jogando, valendo só pro salão.</p>
      </div>
      <div class="bloco">
        <h3><i class="bi bi-stars"></i> Salão da fama</h3>
        <?php if (!$salao): ?>
          <p class="sub">Ninguém jogou ainda. O primeiro título é seu pra levar.</p>
        <?php else: ?>
          <ol class="salao">
          <?php foreach ($salao as $s): ?>
            <li><a href="?t=<?= (int)$s['id'] ?>">
              <span class="s-fase f-<?= $e($s['fase']) ?>"><?= $e(HOOPS_FASES[$s['fase']] ?? $s['fase']) ?></span>
              <b><?= $e($s['nome_time']) ?></b>
              <small><?= $e($s['gm']) ?> · <?= (int)$s['vitorias'] ?>-<?= (int)$s['derrotas'] ?></small>
            </a></li>
          <?php endforeach; ?>
          </ol>
        <?php endif; ?>
        <?php if ($minhas): ?>
          <h3 class="h3-2"><i class="bi bi-clock-history"></i> Suas temporadas</h3>
          <ul class="minhas">
          <?php foreach ($minhas as $m): ?>
            <li><a href="?t=<?= (int)$m['id'] ?>">
              <span class="s-fase f-<?= $e($m['fase']) ?>"><?= $e(HOOPS_FASES[$m['fase']] ?? $m['fase']) ?></span>
              <span><?= (int)$m['vitorias'] ?>-<?= (int)$m['derrotas'] ?></span>
              <span class="m-moedas"><?= $m['mestre'] ? '<span class="selo-mestre">código</span>' : ($m['premiada'] ? '+' . (int)$m['moedas'] : '—') ?></span>
            </a></li>
          <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    </div>
  </section>

  <!-- ── DRAFT ─────────────────────────────────────────────────────── -->
  <section id="tela-draft" class="tela" hidden>
    <!-- A HUD: o que o time é agora, sempre à vista. -->
    <div class="hud">
      <div class="hud-bloco hud-elenco">
        <span class="hud-rot">Elenco</span>
        <b><span id="m-elenco">0</span><small>/12</small></b>
        <div class="pips" id="m-pips"></div>
      </div>
      <div class="hud-bloco hud-forca">
        <span class="hud-rot">Força do time</span>
        <b id="m-forca">—</b>
        <div class="regua" title="Onde a sua força fica entre os times da NBA">
          <i class="regua-nba" id="m-regua-nba"></i><i class="regua-eu" id="m-regua-eu"></i>
        </div>
        <small id="m-forca-sub"></small>
      </div>
      <div class="hud-bloco hud-quim">
        <div class="anel" id="m-anel"><span id="m-quim">0</span></div>
        <div><span class="hud-rot">Química</span><small>de 36</small></div>
      </div>
      <button class="btn-simular" id="bt-jogar" disabled><i class="bi bi-play-circle-fill"></i><span>Simular temporada</span></button>
    </div>

    <div class="draft">
      <div class="quadra-col">
        <div class="quadra" id="quadra">
          <svg class="quadra-linhas" viewBox="0 0 1000 560" preserveAspectRatio="none" aria-hidden="true">
            <rect class="pintura" x="380" y="0" width="240" height="230"/>
            <path d="M380 0V230H620V0"/>
            <path d="M420 230a80 80 0 0 0 160 0"/>
            <path class="tracejado" d="M420 230a80 80 0 0 1 160 0"/>
            <path d="M90 0V140A419 419 0 0 0 910 140V0"/>
            <path d="M460 52a40 40 0 0 0 80 0"/>
            <path class="tabela" d="M468 34H532"/>
            <circle class="aro" cx="500" cy="52" r="11"/>
            <path d="M420 560a80 80 0 0 1 160 0"/>
          </svg>
          <div class="quadra-marca" aria-hidden="true">FBA<b>HOOPS</b></div>
        </div>
        <div class="banco-caixa">
          <div class="banco-titulo"><span><i class="bi bi-people-fill"></i> Banco</span><small>A ordem importa: o 6º homem joga 22 minutos, o 12º quase nada</small></div>
          <div class="banco" id="banco"></div>
        </div>
      </div>
      <aside class="painel">
        <div class="vidro">
          <h4>Equilíbrio do quinteto</h4>
          <div class="equilibrio" id="m-equilibrio"></div>
        </div>
        <p class="dica-troca" id="dica-troca"></p>
        <details class="ajuda vidro">
          <summary>Como a química e a força funcionam</summary>
          <p><b>Química (0–3 por jogador):</b> jogadores do mesmo time (2, 3 ou 4 no elenco) e da mesma liga (3, 5 ou 8) somam pontos. Titular fora de posição fica com 0 e não conta pros outros.</p>
          <p><b>Força:</b> o OVR de cada um, ajustado pela química, pesado pelos minutos que ele joga — mais o equilíbrio do quinteto.</p>
          <p>Os adversários são os 29 elencos reais da NBA. Quem você draftar sai do time de verdade dele.</p>
          <p><b>Playoffs:</b> os times reais jogam juntos há anos e ganham <b>+<?= HOOPS_ENTROSAMENTO_PLAYOFF ?> de força</b> nos mata-matas. O seu time precisa ser melhor que eles pra levar o título.</p>
        </details>
        <button class="desistir" id="bt-desistir"><i class="bi bi-x-circle"></i> Desistir do draft</button>
      </aside>
    </div>
  </section>

  <!-- ── TEMPORADA ─────────────────────────────────────────────────── -->
  <section id="tela-temporada" class="tela" hidden>
    <div class="placar-temp">
      <div class="pt-time"><div class="pt-escudo" id="t-escudo"></div><div><b id="t-nome"></b><small id="t-conf"></small></div></div>
      <div class="pt-recorde"><span id="t-v">0</span><i>-</i><span id="t-d">0</span></div>
      <div class="pt-fase" id="t-fase">Temporada regular</div>
    </div>
    <div class="progresso" id="t-barra"><div id="t-progresso"></div><span id="t-jogo">Jogo 0 de 82</span></div>
    <div class="controles">
      <button class="btn" data-vel="1"><i class="bi bi-play-fill"></i> 1x</button>
      <button class="btn" data-vel="3"><i class="bi bi-fast-forward-fill"></i> 3x</button>
      <button class="btn" data-vel="10"><i class="bi bi-skip-forward-fill"></i> 10x</button>
      <button class="btn" id="bt-pular"><i class="bi bi-skip-end-fill"></i> <span>Pular fase</span></button>
    </div>
    <div class="temp-grid" id="t-regular">
      <div class="bloco">
        <div class="ticker" id="t-ticker"></div>
        <div class="marco" id="t-marco" hidden></div>
      </div>
      <div class="bloco">
        <h3 id="t-tabela-tit">Classificação</h3>
        <div class="tabela" id="t-tabela"></div>
      </div>
    </div>

    <!-- Os playoffs têm palco próprio: a chave inteira, rodada a rodada. -->
    <div id="t-po" hidden>
      <div class="po-minha" id="po-minha"></div>
      <div class="bloco po-playin" id="po-playin" hidden>
        <h3><i class="bi bi-lightning-charge-fill"></i> Play-in</h3>
        <div class="pi-grid" id="pi-grid"></div>
      </div>
      <div class="bloco">
        <div class="chave-scroll" id="po-scroll"><div class="chave" id="po-chave"></div></div>
      </div>
    </div>
    <div class="po-banner" id="po-banner" hidden><span></span></div>
  </section>

  <!-- ── RESULTADO ─────────────────────────────────────────────────── -->
  <section id="tela-fim" class="tela" hidden>
    <div class="fim" id="fim"></div>
  </section>
</main>

<!-- ── O PACOTE ───────────────────────────────────────────────────── -->
<div class="abertura" id="abertura" hidden>
  <div class="ab-luz" aria-hidden="true"></div>
  <div class="ab-topo"><span class="ab-chip" id="ab-vaga"></span><small>Toque numa carta pra ver as estatísticas</small></div>
  <div class="ab-palco" id="ab-palco"></div>
  <div class="ab-chao" aria-hidden="true"></div>
</div>

<div class="toast" id="toast" hidden></div>
<div class="canto-mestre" id="canto-mestre" title="Código mestre ativo: título garantido, sem moedas" hidden><i class="bi bi-trophy-fill"></i></div>

<script>window.HOOPS = <?= json_encode($inicial, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;</script>
<script src="hoops_campeao.js?v=7"></script>
<script src="hoops.js?v=10"></script>
</body>
</html>
