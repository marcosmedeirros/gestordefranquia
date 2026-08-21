<?php
/**
 * MODO OBSERVADOR — ver o site pelos olhos de qualquer liga, sem ter time nela.
 *
 * Existe por um motivo prático: quando um GM diz "não aparece nada na RISE", o
 * admin da ELITE não tem como olhar. Ele teria que virar time da RISE, o que
 * ocupa vaga e mexe no elenco de alguém. Aqui ele só troca o que está vendo.
 *
 * COMO FUNCIONA. Quase toda página lê a liga de `$user['league']`, que vem da
 * sessão. O observador reescreve esse valor — e por isso as páginas obedecem
 * sem precisar saber que o modo existe. A liga de verdade fica guardada à
 * parte e volta ao lugar quando o modo é desligado.
 *
 * O QUE ELE NÃO É. Não é login como outra pessoa: o id do usuário continua o
 * dele, e o que ele fizer sai no nome dele. É só um óculos.
 *
 * Só admin liga. Para qualquer outra pessoa as funções aqui não fazem nada.
 */

const OBS_LIGAS = ['ELITE', 'NEXT', 'RISE', 'ROOKIE'];

/** A liga que está sendo observada agora, ou null se o modo está desligado. */
function observadorLiga(): ?string
{
    $l = $_SESSION['obs_liga'] ?? null;
    return (is_string($l) && in_array($l, OBS_LIGAS, true)) ? $l : null;
}

/** O modo está ligado? */
function observadorAtivo(): bool
{
    return observadorLiga() !== null;
}

/**
 * O time que o admin escolheu inspecionar, se escolheu algum.
 *
 * As telas de GM (elenco, cap, picks) precisam de um time pra ter o que
 * mostrar. Sem escolha, elas seguem com o time do próprio admin — que pode
 * ser de outra liga, e é por isso que a barra avisa.
 */
function observadorTimeId(): ?int
{
    if (!observadorAtivo()) return null;
    $id = (int)($_SESSION['obs_time_id'] ?? 0);
    return $id > 0 ? $id : null;
}

/** Liga o modo. A liga real do admin fica guardada pra voltar depois. */
function observadorEntrar(string $liga): void
{
    $liga = strtoupper(trim($liga));
    if (!in_array($liga, OBS_LIGAS, true)) return;

    if (!isset($_SESSION['obs_liga_real'])) {
        $_SESSION['obs_liga_real'] = $_SESSION['user_league'] ?? null;
    }
    $_SESSION['obs_liga'] = $liga;
    $_SESSION['user_league'] = $liga;   // é isto que faz as páginas obedecerem
    unset($_SESSION['obs_time_id']);    // time de outra liga não serve
}

/** Desliga e devolve o admin pra própria liga. */
function observadorSair(): void
{
    if (array_key_exists('obs_liga_real', $_SESSION)) {
        if ($_SESSION['obs_liga_real'] !== null) {
            $_SESSION['user_league'] = $_SESSION['obs_liga_real'];
        } else {
            unset($_SESSION['user_league']);
        }
    }
    unset($_SESSION['obs_liga'], $_SESSION['obs_liga_real'], $_SESSION['obs_time_id']);
}

/** Escolhe o time a inspecionar. 0 volta pro time do próprio admin. */
function observadorVerTime(int $teamId): void
{
    if (!observadorAtivo()) return;
    if ($teamId <= 0) { unset($_SESSION['obs_time_id']); return; }
    $_SESSION['obs_time_id'] = $teamId;
}

/**
 * Lê `?obs=` da URL e aplica. Chamado no auth, então vale em toda página.
 *
 * Aceita: `?obs=NEXT` (liga), `?obs=off` (sair), `?obs_time=12` (time).
 * Depois de aplicar, redireciona pra MESMA página sem o parâmetro — senão o
 * modo gruda na URL e volta a ligar sozinho num F5 depois de sair.
 */
function observadorProcessarUrl(PDO $pdo, ?array $user): void
{
    $pedido = $_GET['obs'] ?? ($_GET['obs_time'] ?? null);
    if ($pedido === null) return;
    if (!$user || empty($user['id']) || !hasAdminAccess($pdo, (int)$user['id'])) return;

    if (isset($_GET['obs'])) {
        $v = strtoupper(trim((string)$_GET['obs']));
        if ($v === 'OFF') observadorSair();
        else observadorEntrar($v);
    }
    if (isset($_GET['obs_time'])) {
        observadorVerTime((int)$_GET['obs_time']);
    }

    // Volta pra própria página sem os parâmetros do observador.
    $url = strtok((string)($_SERVER['REQUEST_URI'] ?? '/'), '?');
    $q = $_GET;
    unset($q['obs'], $q['obs_time']);
    if ($q) $url .= '?' . http_build_query($q);
    header('Location: ' . $url);
    exit;
}

/**
 * O time que a página deve mostrar como "o meu".
 *
 * Fora do modo observador é o time do usuário, e nada muda. Dentro dele, é o
 * time escolhido na barra — e se nenhum foi escolhido, o primeiro da liga,
 * pra que a tela tenha o que mostrar em vez de mandar o admin pro dashboard.
 */
function timeDaTela(PDO $pdo, int $userId): ?array
{
    $st = $pdo->prepare('SELECT * FROM teams WHERE user_id = ? LIMIT 1');
    $st->execute([$userId]);
    $meu = $st->fetch(PDO::FETCH_ASSOC) ?: null;

    $liga = observadorLiga();
    if ($liga === null) return $meu;

    // No modo observador o time tem que ser da liga que está na tela: o time
    // do próprio admin, de outra liga, daria uma página inteira de dados que
    // não são daquela liga.
    $escolhido = observadorTimeId();
    if ($escolhido) {
        $st2 = $pdo->prepare('SELECT * FROM teams WHERE id = ? AND league = ? LIMIT 1');
        $st2->execute([$escolhido, $liga]);
        if ($t = $st2->fetch(PDO::FETCH_ASSOC)) return $t;
    }
    if ($meu && strtoupper((string)($meu['league'] ?? '')) === $liga) return $meu;

    $st3 = $pdo->prepare('SELECT * FROM teams WHERE league = ? ORDER BY city, name LIMIT 1');
    $st3->execute([$liga]);
    return $st3->fetch(PDO::FETCH_ASSOC) ?: $meu;
}

/**
 * A barra do observador, pra colar no topo de qualquer página.
 *
 * Fica sempre visível enquanto o modo está ligado — é o que impede o admin de
 * esquecer que está vendo outra liga e reportar um "bug" que é só o óculos.
 */
function observadorBarra(PDO $pdo, ?array $user): string
{
    if (!$user || empty($user['id']) || !hasAdminAccess($pdo, (int)$user['id'])) return '';

    $liga = observadorLiga();
    $ligaReal = strtoupper((string)($_SESSION['obs_liga_real'] ?? $_SESSION['user_league'] ?? ''));
    if ($liga === null) return '';

    $times = [];
    try {
        $st = $pdo->prepare("SELECT id, CONCAT(city,' ',name) AS nome FROM teams WHERE league = ? ORDER BY city, name");
        $st->execute([$liga]);
        $times = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) { /* a barra vale sem a lista de times */ }

    $timeAtual = observadorTimeId();
    $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

    $abas = '';
    foreach (OBS_LIGAS as $l) {
        $on = $l === $liga ? ' on' : '';
        $abas .= '<a class="obs-aba' . $on . '" href="?obs=' . $l . '">' . $l . '</a>';
    }

    $opcoes = '<option value="0">Time do observador (o primeiro da liga)</option>';
    foreach ($times as $t) {
        $sel = ((int)$t['id'] === (int)$timeAtual) ? ' selected' : '';
        $opcoes .= '<option value="' . (int)$t['id'] . '"' . $sel . '>' . $h($t['nome']) . '</option>';
    }

    return '<div class="obs-barra">
        <span class="obs-selo"><i class="bi bi-eye-fill"></i> Observando</span>
        <div class="obs-abas">' . $abas . '</div>
        <select class="obs-time" onchange="location.href=\'?obs_time=\'+this.value">' . $opcoes . '</select>
        <span class="obs-nota">Você não é GM aqui — é só visualização.</span>
        <a class="obs-sair" href="?obs=off">Sair' . ($ligaReal ? ' pra ' . $h($ligaReal) : '') . '</a>
    </div>
    <style>
      .obs-barra{position:fixed;top:0;left:0;right:0;z-index:1200;
        display:flex;align-items:center;gap:10px;flex-wrap:wrap;
        padding:8px 14px;background:#7c3aed;color:#fff;font-size:12.5px;font-weight:600;
        box-shadow:0 2px 10px rgba(0,0,0,.35)}
      /* Quem estava colado no topo desce a altura da barra: o menu lateral,
         a barra de título do celular e o corpo da página. */
      body{padding-top:var(--obs-h,0)}
      .sidebar{top:var(--obs-h,0)!important;height:calc(100vh - var(--obs-h,0))!important}
      .topbar{top:var(--obs-h,0)!important}
      .obs-selo{display:inline-flex;align-items:center;gap:6px;font-weight:800;letter-spacing:.4px;
        text-transform:uppercase;font-size:11px}
      .obs-abas{display:flex;gap:4px}
      .obs-aba{padding:4px 11px;border-radius:999px;color:#e9d5ff;text-decoration:none;
        border:1px solid rgba(255,255,255,.28);font-weight:700;font-size:11.5px;white-space:nowrap}
      .obs-aba:hover{background:rgba(255,255,255,.16);color:#fff}
      .obs-aba.on{background:#fff;color:#6d28d9;border-color:#fff}
      .obs-time{background:rgba(0,0,0,.22);border:1px solid rgba(255,255,255,.28);border-radius:8px;
        color:#fff;font-family:inherit;font-size:12px;padding:5px 8px;max-width:230px}
      .obs-time option{color:#111}
      .obs-nota{color:#e9d5ff;font-weight:500}
      .obs-sair{margin-left:auto;color:#fff;text-decoration:none;font-weight:800;
        border:1px solid rgba(255,255,255,.5);border-radius:999px;padding:4px 13px;white-space:nowrap}
      .obs-sair:hover{background:#fff;color:#6d28d9}
      @media (max-width:640px){
        /* Duas linhas no celular: as ligas em cima, o time e a saida embaixo.
           Cada pixel aqui e conteudo empurrado pra baixo em TODA pagina. */
        .obs-barra{gap:6px;padding:6px 9px;font-size:11px}
        .obs-nota{display:none}
        .obs-selo{font-size:10px}
        .obs-aba{padding:3px 9px;font-size:11px}
        .obs-time{flex:1;min-width:0;max-width:none;padding:4px 7px;font-size:11.5px}
        .obs-sair{margin-left:0;padding:4px 11px;font-size:11px}
      }
    </style>
    <script>
    // A altura da barra vira uma variável CSS: no celular ela quebra em duas
    // linhas, e um valor fixo deixaria o menu por baixo dela ou um vão roxo
    // sobrando. Recalcula ao virar a tela.
    (function(){
      var b = document.querySelector(".obs-barra");
      if (!b) return;
      var medir = function(){
        document.documentElement.style.setProperty("--obs-h", b.offsetHeight + "px");
      };
      medir();
      addEventListener("resize", medir);
      addEventListener("load", medir);
    })();
    </script>';
}

/**
 * O ID do time que a página deve tratar como "o meu".
 *
 * Irmã de timeDaTela(), pra quando a página tem uma consulta própria (com
 * JOIN, COUNT, colunas escolhidas a dedo) e só precisa saber DE QUEM. Aí ela
 * troca `WHERE t.user_id = ?` por `WHERE t.id = ?` e o resto continua igual.
 */
function idDoTimeDaTela(PDO $pdo, int $userId): ?int
{
    $t = timeDaTela($pdo, $userId);
    return $t ? (int)$t['id'] : null;
}
