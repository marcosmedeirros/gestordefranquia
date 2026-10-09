<?php
/**
 * LENDAS DA QUADRA — o RPG de tabuleiro com cartas de jogadores da NBA.
 *
 * Esta página é o hub (jogar, baralho, loja, ranking, desafios) e a mesa.
 * Ela responde por JSON a tudo que a tela pede:
 *
 *   TREINO  — contra a IA, com o estado na sessão. NUNCA dá moeda, ranking
 *             ou desafio (pedido do Victor, 09/10/2026).
 *   ONLINE  — contra outro GM, com o estado no banco (@see lendas_online.php).
 *   CONTA   — coleção, baralho, loja (@see lendas_conta.php).
 *
 * Quem decide é o servidor (@see games/core/lendas_motor.php): o navegador
 * manda a intenção, o motor confere e devolve os eventos, que a cena 3D
 * (lendas.js) anima. As jogadas possíveis também vêm daqui — a tela não
 * reimplementa regra nenhuma, só acende o que o servidor disse que pode.
 */

require '../core/conexao.php';
require_once __DIR__ . '/../core/lendas_ia.php';
require_once __DIR__ . '/../core/lendas_online.php';

if (!isset($_SESSION['user_id'])) { header('Location: /login.php'); exit; }
$user_id = (int)$_SESSION['user_id'];
$meuNome = (string)($_SESSION['nome'] ?? $_SESSION['user_name'] ?? 'Você');
lendasGarantirConta($pdo, $user_id);

const LENDAS_NIVEIS = [1 => 'Iniciante', 2 => 'Normal', 3 => 'Difícil'];

/** As jogadas que o lado tem agora: onde cada carta pode ir, o que cada herói alcança. */
function lendasOpcoes(array $e, string $lado): array
{
    if ($e['fim'] || $e['vez'] !== $lado) return ['cartas' => [], 'unidades' => [], 'feito' => $e['jogadores'][$lado]['feito']];
    $cat = lendasCatalogo();
    $j = $e['jogadores'][$lado];
    $emQuadra = count(array_filter($e['unidades'], fn($u) => $u['dono'] === $lado));
    $cartas = [];
    foreach ($j['mao'] as $i => $id) {
        $c = $cat[$id];
        $op = ['pode' => $c['custo'] <= $j['energia'] && !$j['feito']['carta'], 'alvo' => 'nenhum', 'casas' => [], 'unidades' => []];
        if ($j['feito']['carta']) $op['motivo'] = 'Você já usou uma carta neste turno.';
        elseif ($c['custo'] > $j['energia']) $op['motivo'] = 'Energia insuficiente.';
        if ($c['classe'] === 'heroi') {
            $op['alvo'] = 'casa';
            if ($emQuadra >= LENDAS_EM_QUADRA && $op['pode']) { $op['pode'] = false; $op['motivo'] = 'Já há ' . LENDAS_EM_QUADRA . ' heróis seus em quadra.'; }
            for ($y = 0; $y < LENDAS_ALT; $y++) for ($x = 0; $x < LENDAS_LARG; $x++) {
                if (lendasNaZona($lado, $y) && !lendasOcupada($e, $x, $y)) $op['casas'][] = [$x, $y];
            }
        } elseif (!in_array($c['efeito'], ['roubo', 'torcida', 'apagao'], true)) {
            $op['alvo'] = 'unidade';
            $dono = $c['classe'] === 'ferreiro' ? $lado : lendasOutro($lado);
            foreach ($e['unidades'] as $u) if ($u['dono'] === $dono) $op['unidades'][] = $u['id'];
            if (!$op['unidades'] && $op['pode']) { $op['pode'] = false; $op['motivo'] = $c['classe'] === 'ferreiro' ? 'Você não tem herói pra equipar.' : 'O rival não tem herói em quadra.'; }
        }
        $cartas[$i] = $op;
    }
    $unidades = [];
    foreach ($e['unidades'] as $u) {
        if ($u['dono'] !== $lado) continue;
        $unidades[$u['id']] = ['mover' => lendasAlcanceMov($e, $u), 'alvos' => lendasAlvos($e, $u)];
    }
    return ['cartas' => $cartas, 'unidades' => $unidades, 'feito' => $j['feito']];
}

/** O pacote que vai pro navegador: o que eu vejo, o que posso fazer, e as cartas citadas. */
function lendasPacote(array $e, string $lado, int $desde = 0, array $extra = []): array
{
    $visao = lendasVisao($e, $lado);
    $eventos = array_values(array_filter($e['eventos'], fn($ev) => $ev['seq'] > $desde));
    unset($visao['eventos'], $visao['resultado']);
    $ids = $e['jogadores'][$lado]['mao'];
    foreach ($e['unidades'] as $u) { $ids[] = $u['carta']; foreach ($u['equipamentos'] as $q) $ids[] = $q; }
    foreach ($eventos as $ev) if (isset($ev['carta'])) $ids[] = $ev['carta'];
    $cat = lendasCatalogo();
    $cartas = [];
    foreach (array_unique($ids) as $id) if (isset($cat[$id])) $cartas[$id] = $cat[$id];
    return ['estado' => $visao, 'eventos' => $eventos, 'opcoes' => lendasOpcoes($e, $lado), 'cartas' => $cartas, 'eu' => $lado] + $extra;
}

/** O pacote de uma partida online, com o relógio e o resultado (se acabou). */
function lendasPacoteOnline(array $r, int $uid, int $desde): array
{
    $linha = $r['linha'];
    $extra = ['modo' => 'online', 'partida' => (int)$linha['id'],
              'restante' => $linha['prazo'] && $linha['status'] === 'jogando' ? max(0, strtotime($linha['prazo']) - time()) : 0];
    if (!empty($r['e']['resultado'][$uid])) $extra['resultado'] = $r['e']['resultado'][$uid];
    return lendasPacote($r['e'], $r['lado'], $desde, $extra);
}

/** O que o hub mostra sobre o online: esperando, jogando, ou nada. */
function lendasStatusOnline(PDO $pdo, int $uid): array
{
    $m = lendasOnMinha($pdo, $uid);
    if (!$m) return ['status' => 'livre'];
    if ($m['status'] === 'aguardando') {
        return ['status' => 'aguardando', 'modo' => $m['modo'], 'codigo' => $m['codigo'], 'id' => (int)$m['id'],
                'poder' => (int)$m['poder_a'], 'espera' => time() - strtotime($m['criado'])];
    }
    return ['status' => 'jogando', 'id' => (int)$m['id']];
}

$t = &$_SESSION['lendas_treino'];

/* ═══════════════════════════ AÇÕES (JSON) ═══════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $acao = (string)($_POST['acao'] ?? '');
    $desde = (int)($_POST['desde'] ?? 0);
    $resp = fn(array $x) => exit(json_encode($x, JSON_UNESCAPED_UNICODE));

    // ── conta: coleção, baralho, loja, ranking ──────────────────────
    if ($acao === 'colecao') {
        $resp(['ok' => true, 'colecao' => lendasColecao($pdo, $user_id), 'baralho' => lendasBaralho($pdo, $user_id),
               'catalogo' => array_values(lendasCatalogo())]);
    }
    if ($acao === 'salvar_baralho') {
        $ids = json_decode((string)($_POST['cartas'] ?? '[]'), true);
        $erro = is_array($ids) ? lendasSalvarBaralho($pdo, $user_id, $ids) : 'Baralho inválido.';
        $resp($erro ? ['ok' => false, 'erro' => $erro] : ['ok' => true, 'poder' => lendasPoder($ids)]);
    }
    if ($acao === 'pacote') {
        $r = lendasAbrirPacote($pdo, $user_id, (string)($_POST['tipo'] ?? ''));
        if (is_string($r)) $resp(['ok' => false, 'erro' => $r]);
        $cat = lendasCatalogo();
        foreach ($r['cartas'] as &$c) $c['carta'] = $cat[$c['id']];
        unset($c);
        $resp(['ok' => true] + $r);
    }
    if ($acao === 'ranking') $resp(['ok' => true] + lendasRanking($pdo, $user_id));
    if ($acao === 'desafios') $resp(['ok' => true, 'feitos' => lendasDesafiosFeitos($pdo, $user_id)]);

    // ── treino ──────────────────────────────────────────────────────
    if ($acao === 'treino_novo') {
        $nivel = max(1, min(3, (int)($_POST['nivel'] ?? 2)));
        $iaSemente = random_int(100000, 999999);
        $e = lendasNovaPartida(
            ['uid' => $user_id, 'nome' => $meuNome, 'baralho' => lendasBaralho($pdo, $user_id)],
            ['uid' => 0, 'nome' => 'IA · ' . LENDAS_NIVEIS[$nivel], 'baralho' => lendasBaralhoInicial($iaSemente), 'ia' => true],
            random_int(1, 2000000000)
        );
        $t = ['estado' => $e, 'nivel' => $nivel];
        $resp(['ok' => true, 'modo' => 'treino'] + lendasPacote($e, 'A'));
    }
    if (in_array($acao, ['estado', 'agir', 'sair'], true)) {
        if (!$t) $resp(['ok' => false, 'erro' => 'Nenhum treino em andamento.']);
        $e = &$t['estado'];
        if ($acao === 'sair') { $t = null; $resp(['ok' => true]); }
        if ($acao === 'estado') $resp(['ok' => true, 'modo' => 'treino'] + lendasPacote($e, 'A', $desde));
        $a = json_decode((string)($_POST['jogada'] ?? ''), true);
        if (!is_array($a) || !isset($a['tipo'])) $resp(['ok' => false, 'erro' => 'Jogada inválida.']);
        $erro = lendasAgir($e, 'A', $a);
        if ($erro !== null) $resp(['ok' => false, 'erro' => $erro, 'modo' => 'treino'] + lendasPacote($e, 'A', $desde));
        // A vez passou pra IA: ela joga o turno inteiro aqui mesmo.
        $guarda = 0;
        while (!$e['fim'] && $e['vez'] === 'B' && $guarda++ < 3) lendasIaTurno($e, (int)$t['nivel']);
        $resp(['ok' => true, 'modo' => 'treino'] + lendasPacote($e, 'A', $desde));
    }

    // ── online ──────────────────────────────────────────────────────
    if ($acao === 'on_status') $resp(['ok' => true] + lendasStatusOnline($pdo, $user_id));
    if ($acao === 'on_procurar') {
        $modo = ($_POST['modo'] ?? '') === 'codigo' ? 'codigo' : 'fila';
        lendasOnProcurar($pdo, $user_id, $meuNome, $modo);
        $resp(['ok' => true] + lendasStatusOnline($pdo, $user_id));
    }
    if ($acao === 'on_entrar') {
        $r = lendasOnEntrar($pdo, $user_id, $meuNome, (string)($_POST['codigo'] ?? ''));
        if (is_string($r)) $resp(['ok' => false, 'erro' => $r]);
        $resp(['ok' => true] + lendasStatusOnline($pdo, $user_id));
    }
    if ($acao === 'on_cancelar') { lendasOnCancelar($pdo, $user_id); $resp(['ok' => true, 'status' => 'livre']); }
    if (in_array($acao, ['on_estado', 'on_agir'], true)) {
        $id = (int)($_POST['partida'] ?? 0);
        $mexer = null;
        if ($acao === 'on_agir') {
            $a = json_decode((string)($_POST['jogada'] ?? ''), true);
            if (!is_array($a) || !isset($a['tipo'])) $resp(['ok' => false, 'erro' => 'Jogada inválida.']);
            $mexer = fn(array &$e, string $lado) => lendasAgir($e, $lado, $a);
        }
        $r = lendasOnMexer($pdo, $user_id, $id, $mexer);
        if (!$r['e']) $resp(['ok' => false, 'erro' => $r['erro'] ?? 'Partida não encontrada.', 'status' => $r['linha']['status'] ?? null]);
        $p = lendasPacoteOnline($r, $user_id, $desde);
        $resp($r['erro'] ? ['ok' => false, 'erro' => $r['erro']] + $p : ['ok' => true] + $p);
    }

    $resp(['ok' => false, 'erro' => 'Ação desconhecida.']);
}

$inicial = [
    'treino'    => $t ? ['modo' => 'treino'] + lendasPacote($t['estado'], 'A') : null,
    'online'    => lendasStatusOnline($pdo, $user_id),
    'niveis'    => LENDAS_NIVEIS,
    'larg' => LENDAS_LARG, 'alt' => LENDAS_ALT, 'fortaleza' => LENDAS_FORTALEZA_VIDA, 'turnos_max' => LENDAS_TURNOS_MAX,
    'segundos_turno' => LENDAS_SEGUNDOS_TURNO, 'em_quadra' => LENDAS_EM_QUADRA, 'pressao' => LENDAS_PRESSAO,
    'arquetipos' => LENDAS_ARQUETIPOS,
    'pacotes'   => LENDAS_PACOTES,
    'reembolso' => LENDAS_REEMBOLSO,
    'desafios'  => LENDAS_DESAFIOS,
    'premios'   => ['vitoria' => LENDAS_PREMIO_VITORIA, 'derrota' => LENDAS_PREMIO_DERROTA, 'por_dia' => LENDAS_PREMIADAS_POR_DIA,
                    'turnos_min' => LENDAS_TURNOS_MIN_PREMIO],
    'tam_baralho' => LENDAS_TAM_BARALHO, 'copias' => LENDAS_COPIAS,
    'saldo'     => lendasSaldo($pdo, $user_id),
    'uid'       => $user_id,
    'codigo_url' => isset($_GET['codigo']) ? preg_replace('/[^A-Z0-9]/', '', strtoupper((string)$_GET['codigo'])) : null,
];
?>
<!DOCTYPE html>
<html lang="pt-BR" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
<title>Lendas da Quadra</title>
<link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@600;800&family=Montserrat:wght@500;700;800&family=Oswald:wght@500;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<link rel="stylesheet" href="lendas.css?v=5">
</head>
<body>

<!-- ── O HUB ─────────────────────────────────────────────────────────── -->
<section id="tela-menu" class="tela-menu">
  <a href="/games.php" class="voltar" title="Voltar aos games"><i class="bi bi-arrow-left"></i></a>
  <div class="saldo-hub"><i class="bi bi-coin"></i> <b id="saldo">0</b></div>
  <div class="hub">
    <header class="hub-topo">
      <div class="menu-selo">RPG de tabuleiro</div>
      <h1>Lendas <span>da Quadra</span></h1>
    </header>
    <nav class="abas" id="abas">
      <button data-aba="jogar" class="ativa"><i class="bi bi-play-fill"></i> Jogar</button>
      <button data-aba="baralho"><i class="bi bi-collection-fill"></i> Baralho</button>
      <button data-aba="loja"><i class="bi bi-bag-fill"></i> Loja</button>
      <button data-aba="ranking"><i class="bi bi-trophy-fill"></i> Ranking</button>
      <button data-aba="desafios"><i class="bi bi-flag-fill"></i> Desafios</button>
    </nav>

    <div class="painel" id="aba-jogar">
      <div class="menu-modos">
        <div class="modo">
          <h3><i class="bi bi-robot"></i> Treino</h3>
          <p>Contra a IA, com o seu baralho. Para aprender e testar — <b>não vale moedas, ranking nem desafios</b>.</p>
          <div class="niveis" id="niveis"></div>
          <button class="bt-sec" id="bt-continuar" hidden><i class="bi bi-play-fill"></i> Continuar o treino</button>
        </div>
        <div class="modo" id="modo-online">
          <h3><i class="bi bi-people-fill"></i> Duelo online</h3>
          <p>Contra outro GM, com relógio de turno. Vitória vale <b id="p-vit"></b> moedas, derrota <b id="p-der"></b> (até <span id="p-dia"></span> partidas premiadas por dia).</p>
          <div id="online-livre">
            <div class="niveis">
              <button id="bt-fila"><i class="bi bi-search"></i> Procurar adversário</button>
              <button id="bt-codigo"><i class="bi bi-link-45deg"></i> Criar código</button>
            </div>
            <div class="entrar-codigo"><input id="in-codigo" maxlength="6" placeholder="CÓDIGO" autocomplete="off"><button id="bt-entrar">Entrar</button></div>
          </div>
          <div id="online-espera" hidden></div>
          <button class="bt-sec" id="bt-voltar-partida" hidden><i class="bi bi-play-fill"></i> Voltar à partida</button>
        </div>
      </div>
      <details class="regras-hub">
        <summary>Como se joga</summary>
        <ul class="lista-regras"></ul>
      </details>
    </div>

    <div class="painel" id="aba-baralho" hidden>
      <div class="baralho-topo">
        <div><b id="b-conta">0/30</b> cartas · força <b id="b-poder">0</b> · <span id="b-herois"></span></div>
        <div class="baralho-acoes"><span id="b-erro"></span><button class="bt-sec" id="bt-salvar" disabled><i class="bi bi-check-lg"></i> Salvar baralho</button></div>
      </div>
      <div class="baralho-grid">
        <div class="baralho-lista" id="b-lista"></div>
        <div class="colecao">
          <div class="filtros">
            <input id="f-busca" placeholder="Buscar jogador ou carta…">
            <select id="f-classe"><option value="">Todas as classes</option><option value="heroi">Heróis</option><option value="ferreiro">Ferreiros</option><option value="vilao">Vilões</option></select>
            <select id="f-rar"><option value="">Todas as raridades</option><option value="comum">Comum</option><option value="rara">Rara</option><option value="epica">Épica</option><option value="lendaria">Lendária</option></select>
            <label><input type="checkbox" id="f-tenho" checked> Só as que tenho</label>
          </div>
          <div class="colecao-grid" id="c-grid"></div>
        </div>
      </div>
    </div>

    <div class="painel" id="aba-loja" hidden>
      <div class="pacotes" id="pacotes"></div>
      <p class="nota-loja">Carta repetida que não cabe mais no baralho (herói que você já tem, efeito além de 2 cópias) vira moeda na hora.</p>
    </div>

    <div class="painel" id="aba-ranking" hidden><div id="ranking"></div></div>
    <div class="painel" id="aba-desafios" hidden><div class="desafios" id="desafios"></div></div>
  </div>
</section>

<!-- ── A ABERTURA DE PACOTE ──────────────────────────────────────────── -->
<div class="abre-pacote" id="abre-pacote" hidden>
  <div class="ap-cartas" id="ap-cartas"></div>
  <div class="ap-rodape" id="ap-rodape"></div>
</div>

<!-- ── A PARTIDA ─────────────────────────────────────────────────────── -->
<section id="tela-jogo" class="tela-jogo" hidden>
  <canvas id="cena"></canvas>
  <div class="carregando" id="carregando"><div class="anel-carga"></div><span>Acendendo os braseiros…</span></div>

  <div class="hud-topo">
    <div class="lado lado-b">
      <div class="lado-nome" id="nome-b">IA</div>
      <div class="fort"><i class="bi bi-bricks"></i><div class="fort-barra"><div id="fort-b"></div></div><b id="fort-b-n">20</b></div>
      <div class="lado-info"><span id="mao-b">5</span> na mão · <span id="baralho-b">25</span> no baralho</div>
    </div>
    <div class="turno-caixa">
      <div class="turno-n" id="turno-n">Turno 1</div>
      <div class="turno-vez" id="turno-vez">Sua vez</div>
      <div class="relogio" id="relogio" hidden></div>
    </div>
    <div class="lado lado-a">
      <div class="lado-nome" id="nome-a">Você</div>
      <div class="fort"><i class="bi bi-bricks"></i><div class="fort-barra"><div id="fort-a"></div></div><b id="fort-a-n">20</b></div>
      <div class="lado-info"><span id="baralho-a">25</span> no baralho</div>
    </div>
  </div>

  <div class="menu-jogo">
    <button id="bt-sair" title="Sair"><i class="bi bi-x-lg"></i></button>
    <button id="bt-ajuda" title="Como jogar"><i class="bi bi-question-lg"></i></button>
    <button id="bt-camera" title="Voltar a câmera"><i class="bi bi-camera-video"></i></button>
  </div>

  <div class="acoes-turno" id="acoes-turno"></div>
  <div class="dica" id="dica"></div>
  <div class="detalhe" id="detalhe" hidden></div>

  <div class="hud-baixo">
    <div class="energia" id="energia" title="Energia"></div>
    <div class="mao" id="mao"></div>
    <button class="bt-passar" id="bt-passar"><span>Encerrar turno</span><i class="bi bi-hourglass-split"></i></button>
  </div>

  <div class="log" id="log"></div>
  <div class="d20" id="d20" hidden><b></b><small></small></div>
  <div class="aviso" id="aviso" hidden></div>
  <div class="fim" id="fim" hidden></div>

  <div class="ajuda" id="ajuda" hidden>
    <div class="ajuda-caixa">
      <h2>Como jogar</h2>
      <ul class="lista-regras"></ul>
      <button class="bt-sec" id="bt-fechar-ajuda">Entendi</button>
    </div>
  </div>
</section>

<script>window.LENDAS = <?= json_encode($inicial, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;</script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/three.js/r128/three.min.js"></script>
<script src="lendas.js?v=7"></script>
<script src="lendas_hub.js?v=2"></script>
</body>
</html>
