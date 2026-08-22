<?php
/**
 * O CAMINHO — simulador de carreira no basquete.
 *
 * Ainda NÃO está na lista de games/index.php: acessa só quem tem o link.
 *
 * A carreira mora no banco, não no navegador. É isso que permite o ranking
 * entre os GMs e o pagamento em moedas — e que a pessoa continue de outro
 * aparelho.
 *
 * SOBRE CONFIAR NO CLIENTE: a simulação roda no navegador, então o estado
 * que chega aqui é, em tese, forjável. Simular no servidor seria a defesa
 * de verdade, mas exigiria reescrever o motor inteiro em PHP. Enquanto
 * isso, o servidor RECALCULA o legado a partir dos troféus (nunca aceita o
 * número do cliente) e aplica limites que tornam a fraude pouco atraente:
 * troféu não pode passar do número de temporadas, temporada tem teto, e o
 * pagamento é uma vez só por carreira. Mesma postura dos tetos de moeda do
 * Flappy e do Pinguim.
 */

require '../core/conexao.php';
require_once __DIR__ . '/../../backend/nba_teams.php';   // nome curto + logo do cdn.nba.com
require_once __DIR__ . '/../core/cartao.php';              // o cartao em imagem, compartilhado com o build e o 5x5

$idUsuario = (int)($_SESSION['user_id'] ?? 0);
if ($idUsuario <= 0) { header('Location: /login.php'); exit; }

// ── Tabela ─────────────────────────────────────────────────────────────
$pdo->exec("CREATE TABLE IF NOT EXISTS caminho_carreiras (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_usuario INT NOT NULL,
    nome VARCHAR(40) NULL,
    posicao VARCHAR(4) NULL,
    estado LONGTEXT NOT NULL,
    legado INT NOT NULL DEFAULT 0,
    temporadas INT NOT NULL DEFAULT 0,
    moedas_pagas INT NOT NULL DEFAULT 0,
    encerrada TINYINT(1) NOT NULL DEFAULT 0,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    encerrado_em DATETIME NULL,
    INDEX idx_cc_user (id_usuario),
    INDEX idx_cc_ranking (encerrada, legado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Os desafios são do JOGADOR, não da carreira: uma tabela própria, fora do
// estado, pra que a conquista continue lá depois da aposentadoria.
$pdo->exec("CREATE TABLE IF NOT EXISTS caminho_desafios (
    id_usuario INT NOT NULL,
    desafio VARCHAR(40) NOT NULL,
    conquistado_em DATETIME NOT NULL,
    PRIMARY KEY (id_usuario, desafio)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

const CAMINHO_LEGADO_MAX = 230;
const CAMINHO_MAX_TEMPORADAS = 26;
// Teto de gravação por requisição: o cliente diz quais caíram, e uma lista
// absurda é sinal de estado forjado, não de carreira boa.
const CAMINHO_MAX_DESAFIOS_POR_VEZ = 12;

/**
 * Quanto cada nível de desafio paga em moeda.
 *
 * Mora AQUI, no servidor, e não no JavaScript: o pagamento é a única parte
 * do jogo que vira dinheiro de verdade na conta, e um número que o cliente
 * manda é um número que o cliente escolhe. O JS recebe esta tabela só pra
 * mostrar na tela.
 */
const CAMINHO_NIVEIS = ['facil' => 50, 'medio' => 100, 'dificil' => 150, 'impossivel' => 200];

/**
 * O catálogo: id do desafio => nível.
 *
 * Id que não estiver aqui não é gravado nem pago. É o que garante que
 * inventar um nome no cliente não vira moeda.
 */
const CAMINHO_DESAFIOS = [
  // Fáceis — o primeiro de cada coisa. Ganhar UM título, UM MVP, UM DPOY ou
  // UM ouro é o marco de entrada de cada prêmio; o que exige carreira boa é
  // repetir (três MVPs, três títulos), e isso já está nos difíceis.
  'roy'         => 'dificil',
  'top5'        => 'medio',
  'pick1'       => 'dificil',
  'chamado'     => 'facil',
  'ringless'    => 'facil',
  'anel'        => 'facil',
  'mvp'         => 'medio',
  'dpoy'        => 'facil',
  'selecao'     => 'facil',
  // Médios — exigem uma carreira boa.
  'fmvp'        => 'medio',
  'allstar5'    => 'medio',
  'estreia'     => 'medio',
  'euroliga'    => 'dificil',
  'nomade'      => 'medio',
  'lenda_clube' => 'facil',
  'de_pe'       => 'medio',
  // Difíceis — carreira longa e muito acima da média.
  'tricampeao'  => 'dificil',
  'mvp3'        => 'dificil',
  'pts30k'      => 'medio',
  'duplo20k'    => 'medio',
  'ovr99'       => 'dificil',
  'ferro'       => 'facil',
  'ano_perfeito'=> 'dificil',
  // Impossíveis — precisam de várias coisas raras na MESMA carreira.
  // A régua: nada aqui passou de 2% em 1.320 carreiras completas simuladas,
  // e nada é 0% — impossível quer dizer "quase ninguém", não "ninguém".
  'porta_fundos'   => 'impossivel',   // 0,1%
  'imortal'        => 'impossivel',   // 2,1%
  'goat'           => 'impossivel',   // 0,9%
  'dinastia_solo'  => 'impossivel',   // 1,9%
  'pico_e_anel'    => 'impossivel',   // 1,5%
  'trinta_no_ano'  => 'impossivel',   // 1,3%
  'duplo_completo' => 'impossivel',   // 1,4%
  'so_uma_camisa'  => 'impossivel',   // 3,0%
  'campeao_4'      => 'impossivel',   // 1,5%
  'mala_pronta'    => 'impossivel',   // 0,3%
  'dono_defesa'    => 'impossivel',   // 1,4%
  'presenca12'     => 'impossivel',   // 1,0%
  'patria'         => 'impossivel',   // 1,7%
  'lenda_viva'     => 'impossivel',   // 0,5%
  'quarenta_mil'   => 'impossivel',   // 0,1%
  // Lendários — a carreira de um jogador específico, refeita inteira. Não
  // pagam moeda: pagam FBA points, que valem no site e não só nos games.
  'proj_jordan'    => 'lendario',
  'proj_russell'   => 'lendario',
  'proj_lebron'    => 'lendario',
  'proj_duncan'    => 'lendario',
  'proj_curry'     => 'lendario',
  'proj_kobe'      => 'lendario',
];

/**
 * Quanto cada nível paga em FBA points.
 *
 * Separado de CAMINHO_NIVEIS porque são moedas diferentes: moeda de games
 * (games_usuarios.pontos) circula só nos jogos, FBA point circula no site.
 * Nível que não estiver aqui paga zero — é o caso de todos os outros.
 */
const CAMINHO_FBA = ['lendario' => 50];

/**
 * Legado recalculado AQUI, a partir dos troféus — o número que o cliente
 * mandar é ignorado. Mesma fórmula do JS; se uma mudar, a outra tem que
 * mudar junto.
 */
function caminhoLegado(array $estado): int
{
    // Os dois campos vêm do cliente, então nada garante que sejam array —
    // um estado forjado com "temporadas":"x" derrubava o foreach em warning
    // no meio do JSON da resposta.
    $t = is_array($estado['trofeus'] ?? null) ? $estado['trofeus'] : [];
    $lista = is_array($estado['temporadas'] ?? null) ? $estado['temporadas'] : [];

    $temporadas = 0;
    foreach ($lista as $x) {
        if (is_array($x) && empty($x['formacao']) && empty($x['perdida'])) $temporadas++;
    }
    $temporadas = max(0, min(CAMINHO_MAX_TEMPORADAS, $temporadas));

    // Nenhum troféu pode passar do número de temporadas jogadas: é o teto
    // que impede um estado forjado de valer 20 MVPs em 3 anos.
    $n = fn(string $k) => max(0, min($temporadas, (int)($t[$k] ?? 0)));

    $bruto = $n('mvp')*22 + $n('titulo')*16 + $n('fmvp')*10 + $n('dpoy')*8
           + $n('euro')*7 + $n('cesta')*6 + $n('allstar')*4 + $n('ouro')*5
           + $n('roy')*3 + (int)round($temporadas * 0.8);

    // 2.3 e nao 1.8: com 1.8, a carreira do Jordan (6 titulos, 6 MVPs das
    // Finais, 5 MVPs) dava 161 e o teto de 230 pedia um bruto que nao cabe
    // em 26 temporadas. Os degraus de cima existiam sem ninguem alcancar.
    // Se este numero mudar, o do JS muda junto — sao a mesma formula.
    return (int)min(CAMINHO_LEGADO_MAX, round(pow(max(0,$bruto), 0.78) * 2.3));
}

function caminhoCarreiraAtiva(PDO $pdo, int $uid): ?array
{
    $st = $pdo->prepare("SELECT * FROM caminho_carreiras WHERE id_usuario = ? AND encerrada = 0 ORDER BY id DESC LIMIT 1");
    $st->execute([$uid]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

// ── Ações ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $acao = $_POST['acao'] ?? '';

    if ($acao === 'abandonar') {
        $pdo->prepare("DELETE FROM caminho_carreiras WHERE id_usuario = ? AND encerrada = 0")->execute([$idUsuario]);
        echo json_encode(['ok' => true]);
        exit;
    }

    // Desafios conquistados. INSERT IGNORE: reconquistar não reescreve a data
    // — a primeira vez é a que conta, e é ela que aparece no cartão.
    if ($acao === 'desafios') {
        $ids = json_decode((string)($_POST['ids'] ?? ''), true);
        if (!is_array($ids)) { echo json_encode(['ok' => false, 'erro' => 'lista inválida']); exit; }
        // Só id que existe no catálogo entra. Nome inventado no cliente não
        // vira linha no banco nem moeda na conta.
        $ids = array_slice(array_values(array_unique(array_filter(
            array_map(fn($x) => preg_replace('/[^a-z0-9_]/', '', mb_substr((string)$x, 0, 40)), $ids),
            fn($id) => isset(CAMINHO_DESAFIOS[$id])
        ))), 0, CAMINHO_MAX_DESAFIOS_POR_VEZ);
        if (!$ids) { echo json_encode(['ok' => true, 'gravados' => 0, 'moedas' => 0, 'fba' => 0]); exit; }

        // O prêmio sai do rowCount, não da lista: só paga a linha que ENTROU
        // agora. Reenviar a mesma conquista dez vezes grava zero e paga zero.
        $st = $pdo->prepare("INSERT IGNORE INTO caminho_desafios (id_usuario, desafio, conquistado_em) VALUES (?,?,NOW())");
        $gravados = 0; $moedas = 0; $fba = 0;
        foreach ($ids as $id) {
            $st->execute([$idUsuario, $id]);
            if ($st->rowCount() > 0) {
                $gravados++;
                $nivel   = CAMINHO_DESAFIOS[$id];
                $moedas += CAMINHO_NIVEIS[$nivel] ?? 0;
                $fba    += CAMINHO_FBA[$nivel]    ?? 0;
            }
        }
        // As duas moedas num UPDATE só: se o pagamento falhar, falha
        // inteiro, e nao sobra conquista paga pela metade.
        if ($moedas > 0 || $fba > 0) {
            try {
                // A conta do FBA Games nasce quando a pessoa abre o /games.php.
                // Quem entrou direto na URL do jogo nao tem linha, e o UPDATE
                // acertaria zero: o desafio ficava gravado e a moeda nao caia,
                // sem erro nenhum no log. O Copero ja fazia isso; aqui faltava.
                $pdo->prepare("INSERT IGNORE INTO games_usuarios (id, nome, email, league)
                               SELECT id, name, email, COALESCE(league,'ROOKIE') FROM users WHERE id = ?")
                    ->execute([$idUsuario]);
                // COALESCE no pontos: a coluna aceita NULL, e NULL + 100 e
                // NULL — o saldo inteiro sumiria em vez de crescer.
                $pdo->prepare("UPDATE games_usuarios
                               SET pontos = COALESCE(pontos,0) + ?, fba_points = COALESCE(fba_points,0) + ?
                               WHERE id = ?")
                    ->execute([$moedas, $fba, $idUsuario]);
            } catch (Throwable $e) {
                error_log('[caminho] pagar desafios: ' . $e->getMessage());
                $moedas = 0; $fba = 0;
            }
        }
        echo json_encode(['ok' => true, 'gravados' => $gravados, 'moedas' => $moedas, 'fba' => $fba]);
        exit;
    }

    $estado = json_decode((string)($_POST['estado'] ?? ''), true);
    if (!is_array($estado)) { echo json_encode(['ok' => false, 'erro' => 'estado inválido']); exit; }

    $nome = mb_substr(trim((string)($estado['nome'] ?? '')), 0, 40);
    $pos  = mb_substr((string)($estado['pos'] ?? ''), 0, 4);
    $temporadas = count(array_filter($estado['temporadas'] ?? [], fn($x) => empty($x['formacao'])));
    $legado = caminhoLegado($estado);
    $json = json_encode($estado, JSON_UNESCAPED_UNICODE);

    // Guarda-chuva de tamanho: estado gigante é sinal de coisa errada e não
    // cabe num LONGTEXT sem custo.
    if (strlen($json) > 400000) { echo json_encode(['ok' => false, 'erro' => 'estado grande demais']); exit; }

    $ativa = caminhoCarreiraAtiva($pdo, $idUsuario);

    if ($acao === 'salvar') {
        if ($ativa) {
            $pdo->prepare("UPDATE caminho_carreiras SET estado=?, nome=?, posicao=?, legado=?, temporadas=? WHERE id=?")
                ->execute([$json, $nome, $pos, $legado, $temporadas, $ativa['id']]);
        } else {
            $pdo->prepare("INSERT INTO caminho_carreiras (id_usuario, nome, posicao, estado, legado, temporadas) VALUES (?,?,?,?,?,?)")
                ->execute([$idUsuario, $nome, $pos, $json, $legado, $temporadas]);
        }
        echo json_encode(['ok' => true, 'legado' => $legado]);
        exit;
    }

    if ($acao === 'encerrar') {
        // Uma carreira precisa ter sido jogada pra pagar. Sem isto, dava pra
        // criar e encerrar em looping colhendo moedas.
        if ($temporadas < 3) { echo json_encode(['ok' => false, 'erro' => 'carreira curta demais']); exit; }

        $pdo->beginTransaction();
        try {
            if ($ativa) {
                $pdo->prepare("UPDATE caminho_carreiras SET estado=?, nome=?, posicao=?, legado=?, temporadas=?,
                               encerrada=1, encerrado_em=NOW(), moedas_pagas=0 WHERE id=? AND encerrada=0")
                    ->execute([$json, $nome, $pos, $legado, $temporadas, $ativa['id']]);
                $mexeu = true;
            } else {
                $pdo->prepare("INSERT INTO caminho_carreiras (id_usuario, nome, posicao, estado, legado, temporadas, encerrada, encerrado_em, moedas_pagas)
                               VALUES (?,?,?,?,?,?,1,NOW(),0)")
                    ->execute([$idUsuario, $nome, $pos, $json, $legado, $temporadas]);
                $mexeu = true;
            }
            // A carreira encerrada NÃO paga moeda. Ela vale o ranking e a
            // vitrine; moeda sai só de desafio, que é onde a conquista é de
            // fato uma conquista e não o resultado de ter jogado bastante.
            // ($mexeu segue marcado pra manter o registro coerente.)
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('[caminho] encerrar: ' . $e->getMessage());
            echo json_encode(['ok' => false, 'erro' => 'falha ao encerrar']);
            exit;
        }
        echo json_encode(['ok' => true, 'legado' => $legado, 'moedas' => 0]);
        exit;
    }

    echo json_encode(['ok' => false, 'erro' => 'ação desconhecida']);
    exit;
}

// ── Página ─────────────────────────────────────────────────────────────
$ativa = caminhoCarreiraAtiva($pdo, $idUsuario);
$estadoInicial = $ativa ? $ativa['estado'] : 'null';

// Último nome usado por esta conta, pra já vir preenchido na criação.
$stNome = $pdo->prepare("SELECT nome FROM caminho_carreiras
                        WHERE id_usuario = ? AND nome IS NOT NULL AND nome <> ''
                        ORDER BY id DESC LIMIT 1");
$stNome->execute([$idUsuario]);
$ultimoNome = (string)($stNome->fetchColumn() ?: '');

$stPontos = $pdo->prepare("SELECT pontos FROM games_usuarios WHERE id = ?");
$stPontos->execute([$idUsuario]);
$pontosUsuario = (int)($stPontos->fetchColumn() ?: 0);

// Desafios já conquistados por esta conta, com a data — é ela que aparece no
// canto do card, igual à noite em que a coisa aconteceu.
$desafiosFeitos = [];
try {
    $st = $pdo->prepare("SELECT desafio, DATE_FORMAT(conquistado_em, '%d/%m %H:%i') AS quando
                         FROM caminho_desafios WHERE id_usuario = ?");
    $st->execute([$idUsuario]);
    foreach ($st as $r) $desafiosFeitos[$r['desafio']] = $r['quando'];
} catch (Throwable $e) {
    error_log('[caminho] desafios: ' . $e->getMessage());
}

// Times de verdade, com o logo que cada um cadastrou. Lidos AQUI em vez de
// embutidos no JS: time novo, troca de dono ou logo atualizado aparecem no
// jogo sem ninguém mexer em código.
//
// ROOKIE fica de fora porque lá os times são da NBA — dentro do modo FBA
// eles confundiriam com o modo NBA.
$timesFba = [];
try {
    $st = $pdo->query("
        SELECT t.name, COALESCE(t.photo_url, '') AS logo, COALESCE(u.name, '') AS gm, t.league
        FROM teams t LEFT JOIN users u ON u.id = t.user_id
        WHERE t.league IN ('RISE','NEXT','ELITE')
        ORDER BY t.league, t.name
    ");
    foreach ($st as $r) $timesFba[] = [$r['name'], $r['logo'], $r['gm'], $r['league']];
} catch (Throwable $e) {
    error_log('[caminho] times da FBA: ' . $e->getMessage());
}

$timesNba = array_map(fn($t) => [$t['name'], nbaTeamLogoUrl($t['id'])], nbaTeams());

// Ranking: as melhores carreiras encerradas, uma por GM.
$ranking = $pdo->query("
    SELECT c.nome, c.posicao, c.legado, c.temporadas, u.name AS gm
    FROM caminho_carreiras c
    JOIN (SELECT id_usuario, MAX(legado) AS melhor FROM caminho_carreiras WHERE encerrada = 1 GROUP BY id_usuario) m
      ON m.id_usuario = c.id_usuario AND m.melhor = c.legado
    JOIN users u ON u.id = c.id_usuario
    WHERE c.encerrada = 1
    GROUP BY c.id_usuario
    ORDER BY c.legado DESC
    LIMIT 20
")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
<title>O Caminho — FBA Games</title>
<meta name="robots" content="noindex, nofollow">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800;900&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
/* ── Padrão visual dos jogos da FBA (base: buildplayer.php) ───────────
   Mesmos tokens, mesma topbar, mesmo card, mesmo vermelho. Dark-only,
   como o resto de games/.

   Uma diferença forçada: a fonte e os Bootstrap Icons vêm de CDN, e a CSP
   do artifact bloqueia. Aqui uso a pilha do sistema e SVG inline; ao
   entrar no site, é só voltar a var(--font) do padrão e os <i class="bi">.
   ─────────────────────────────────────────────────────────────────── */
/* A escala é a mesma do Copero, e de propósito: os dois são jogos de
   carreira do mesmo site, e antes um parecia mais escuro e apagado que o
   outro sem que nada justificasse. O que NÃO segue o Copero é o acento —
   ali é verde porque é futebol, aqui é o vermelho da FBA, que é a marca.

   Duas mudanças fazem quase toda a diferença de leitura: as bordas viram
   sólidas (em rgba elas somem sobre painel escuro) e o texto terciário
   clareia de #3c3c44 pra #71717a, que é o mínimo pra rótulo pequeno ser
   legível em cima do fundo. */
:root{
  --bg:#0a0a0c;--panel:#131316;--panel2:#1a1a1f;--panel3:#212127;
  --border:#26262d;--border2:#33333c;
  --red:#fc0025;--red-soft:rgba(252,0,37,.12);--red-glow:rgba(252,0,37,.25);
  --text:#f4f4f5;--text2:#a1a1aa;--text3:#71717a;
  --green:#22c55e;--green-soft:rgba(34,197,94,.12);
  --amber:#f59e0b;--amber-soft:rgba(245,158,11,.12);
  --blue:#3b82f6;--blue-soft:rgba(59,130,246,.12);
  --radius:16px;
  --font:'Inter',system-ui,-apple-system,sans-serif;
  --num:'Inter',system-ui,sans-serif;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:var(--font);background:var(--bg);color:var(--text);min-height:100vh;
  -webkit-font-smoothing:antialiased;overflow-x:hidden}

/* TOP BAR — a mesma dos outros jogos */
.topbar{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:10px 14px;
  background:var(--panel);border-bottom:1px solid var(--border);position:sticky;top:0;z-index:50}
.topbar-left{display:flex;align-items:center;gap:10px}
.back-btn{display:flex;align-items:center;justify-content:center;width:32px;height:32px;border-radius:9px;
  border:1px solid var(--border);background:transparent;color:var(--text2);text-decoration:none;
  font-size:14px;transition:.2s;flex-shrink:0;cursor:pointer}
.back-btn:hover{border-color:var(--red);color:var(--red);background:var(--red-soft)}
.game-title{font-size:15px;font-weight:800;color:var(--text)}
.game-title span{color:var(--red)}
.daily-badge{display:inline-flex;align-items:center;gap:4px;font-size:8px;font-weight:700;letter-spacing:.8px;
  text-transform:uppercase;padding:2px 8px;border-radius:999px;background:var(--red-soft);
  border:1px solid var(--red-glow);color:var(--red);margin-left:6px}
.topbar-right{display:flex;align-items:center;gap:6px;flex-wrap:wrap;justify-content:flex-end}
.chip{display:flex;align-items:center;gap:4px;padding:4px 10px;border-radius:20px;background:var(--panel2);
  border:1px solid var(--border);font-size:11px;font-weight:700;color:var(--text);white-space:nowrap}
.chip b{font-family:var(--num);font-variant-numeric:tabular-nums;font-weight:700}
/* No celular a barra tem cinco fichas (OVR, idade, dinheiro, troféu, moeda)
   e o nome do jogo do outro lado: 281px de fichas em 375px de tela furavam
   a página inteira pra direita. O selo "carreira" some, as fichas encolhem
   e o que sobra pode quebrar linha em vez de empurrar. */
@media (max-width:520px){
  .topbar{gap:8px;padding:9px 10px}
  .daily-badge{display:none}
  .game-title{font-size:13.5px}
  /* A ficha do trofeu e BOTAO (abre os desafios) e nasce com 20px de altura:
     alvo de toque de 20px erra mais do que acerta, e no celular ela e a
     unica porta pros desafios. O min-height so cresce o que e clicavel; as
     outras fichas continuam finas. */
  .chip{padding:3px 8px;font-size:10.5px;gap:3px}
  .chip-btn{min-height:30px;padding-left:11px;padding-right:11px}
  .topbar-right{gap:4px;flex-wrap:nowrap}
  /* OVR e idade saem: os dois estão logo abaixo, no cartão, em letra bem
     maior. Repetidos aqui em cima eles quebravam a barra em duas linhas e
     partiam o nome do jogo no meio. */
  .chip-espelho{display:none}

  /* Nome do jogo no centro: `display:contents` dissolve o grupo da esquerda
     e entrega a seta e o título direto à grade, que é o que permite dar
     colunas laterais IGUAIS aos dois lados — sem isso o título fica
     centrado no que sobra, e sobra sempre mais de um lado. */
  .topbar{display:grid;grid-template-columns:minmax(0,1fr) auto minmax(0,1fr);align-items:center}
  .topbar-left{display:contents}
  .back-btn{justify-self:start}
  .game-title{justify-self:center;text-align:center;white-space:nowrap}
  .topbar-right{justify-self:end}
}

.main{max-width:620px;margin:0 auto;padding:16px 12px 60px}

/* ── A CAIXA DA DECISÃO ────────────────────────────────────────────────
   No desktop ela nao e nada: fica no fluxo, no fim da coluna, onde sempre
   esteve. No celular ela GRUDA no rodape (media query mais abaixo) — a
   escolha do ano fica a mao em qualquer altura da rolagem, do mesmo jeito
   que no Copero. Vazia ela some: ha telas sem decisao e sem acao, e um
   retangulo com borda e sombra no pe da tela nao diz nada. */
.dec-caixa:empty{display:none}
.rf-acoes{display:flex;gap:8px}
.rf-acoes:empty{display:none}
.rf-acoes > *{flex:1 1 0;min-width:0}
.dec-espaco{display:none}

/* DUAS COLUNAS — só a partir de 940px.
   Abaixo disso o grid vira uma coluna e tudo empilha na ordem do HTML, que
   já é a ordem certa de leitura: o que importa primeiro vem primeiro. Por
   isso o lado principal vem antes no markup, e não porque está à esquerda. */
.colunas{display:grid;gap:14px}
/* min-width:0 nas duas colunas: item de grid nasce com min-width:auto, e
   qualquer conteúdo que não encolhe (o carrossel, uma tabela) empurra a
   coluna pra fora da tela levando a página inteira junto. */
.col-principal,.col-lado{min-width:0}
@media (min-width:980px){
  .main{max-width:1040px;padding:14px 20px 24px}
  /* Ficha à esquerda, trajetória à direita e larga: é o mesmo desenho do
     Copero, e é o que faz a linha do tempo caber sem apertar o nome dos
     clubes numa coluna de 350px. */
  .colunas{grid-template-columns:minmax(0,430px) minmax(0,1fr);align-items:start;gap:18px}
  /* A lateral acompanha a rolagem: numa temporada longa a súmula fica
     comprida, e sem isto o ranking sumia lá em cima. */
  .col-lado{position:sticky;top:76px}
  .intro,.intro p{max-width:none}
  h1{font-size:26px}
}
/* Numa coluna só, o respiro entre os dois blocos já vem do gap. */
.col-lado h2:first-child{margin-top:0}

/* A TELA DO ANO EM TRÊS BLOCOS — ficha, súmula, decisão.
   No desktop a súmula está na coluna da direita e os dois blocos da
   esquerda são só um em cima do outro. No celular tudo empilha, e aí a
   ordem importa: `display:contents` dissolve a coluna principal e entrega
   ficha e decisão direto ao grid, que é a única forma de a súmula passar
   ENTRE elas — presas no mesmo filho, nenhum `order` as separa. */
.bl-ficha + .bl-decisao{margin-top:11px}

/* ── A FICHA QUE NÃO SOME ─────────────────────────────────────────────
   O cartão de OVR abre a tela e sumia na primeira rolada — justamente
   quando saber em que nível você está é o que muda a decisão. Esta faixa é
   o resumo dele, grudada embaixo da barra de cima, e só acende depois que o
   cartão de verdade sai de vista (vigiarFicha). */
.ficha-fixa{position:sticky;top:var(--topo-h,0px);z-index:45;display:flex;align-items:center;gap:9px;
  padding:7px 12px;background:color-mix(in srgb, var(--panel) 92%, transparent);
  backdrop-filter:blur(9px);border-bottom:1px solid var(--border);
  margin:0 -12px 11px;animation:ffEntra .18s ease}
.ficha-fixa[hidden]{display:none}
@keyframes ffEntra{from{opacity:0;transform:translateY(-6px)}to{opacity:1;transform:none}}
@media (prefers-reduced-motion:reduce){.ficha-fixa{animation:none}}
.ff-ovr{font-family:var(--num);font-size:16px;font-weight:900;line-height:1;
  padding:5px 9px;border-radius:8px;background:var(--cor);color:#0b0b0d;
  font-variant-numeric:tabular-nums;flex:none}
.ff-delta{font-style:normal;font-family:var(--num);font-size:12px;font-weight:900;flex:none}
.ff-clube{display:flex;align-items:center;gap:6px;min-width:0;flex:1}
.ff-clube b{font-size:13px;font-weight:800;letter-spacing:-.2px;
  white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.ff-idade{font-size:11.5px;font-weight:700;color:var(--text2);flex:none}
/* No desktop a barra de cima não é grudada, então a faixa gruda no zero e
   ganha o respiro que a coluna tem. */
@media (min-width:980px){.ficha-fixa{margin:0 0 14px;border-radius:0 0 10px 10px}}

/* ── O QUE A DECISÃO DEU ──────────────────────────────────────────────
   Substitui a tela de "Deu certo." / "Não foi dessa vez.". Uma linha
   colorida na lateral já diz de que lado caiu; o texto conta o quê. */
.nota-deu{display:flex;align-items:flex-start;gap:11px;padding:12px 13px}
.nota-deu-marca{width:3px;align-self:stretch;border-radius:99px;flex:none;min-height:34px}
.nota-deu-txt{flex:1;min-width:0}
.nota-deu-txt b{display:block;font-size:12px;font-weight:800;letter-spacing:-.1px;
  color:var(--text2);margin-bottom:3px}
.nota-deu-txt p{margin:0;font-size:13.5px;line-height:1.5;color:var(--text)}
.nota-deu-ovr{font-style:normal;font-family:var(--num);font-size:13px;font-weight:900;
  flex:none;font-variant-numeric:tabular-nums;padding-top:1px}
@media (max-width:979px){
  /* ── A TELA DO JOGO NO CELULAR ────────────────────────────────────
     A barra de cima sai e vira barra de baixo: os mesmos atalhos, mais a
     ação do momento, colados no rodapé. Assim o botão do ano fica à mão
     em qualquer altura da rolagem — e os 64px do alto da tela, que só
     repetiam o nome do jogo, voltam pra ficha. */
  /* A barra de cima FICA, e vira grudada. Ela sumia pra dar lugar a uma
     barra de baixo com os MESMOS atalhos — trocar uma pela outra nao
     devolvia espaco, so mudava os botoes de lugar. Agora o rodape e so da
     acao do momento. */
  .topbar{position:sticky;top:0;z-index:50}

  /* A DECISAO INTEIRA GRUDA NO RODAPE — titulo, texto e cartas, nao so os
     botoes. Antes so a acao descia, e a pergunta ficava no meio da rolagem:
     dava pra clicar sem ler. Ela sai do fluxo, entao o .dec-espaco segura o
     buraco, e a altura vem do JS (--dec-h) porque muda com o texto e com o
     numero de cartas. */
  .dec-caixa{position:fixed;left:0;right:0;bottom:0;z-index:60;
    padding:11px 12px calc(11px + env(safe-area-inset-bottom,0px));
    background:var(--panel);border-top:1px solid var(--border);
    border-radius:16px 16px 0 0;max-height:58vh;overflow-y:auto;
    box-shadow:0 -10px 26px rgba(0,0,0,.5)}
  .dec-espaco{display:block;height:var(--dec-h,190px)}
  .dec-caixa .dec-tit{font-size:17px;margin:0 0 3px}
  /* O texto de contexto fica, curto: e o unico que explica o que esta em
     jogo antes de escolher. Duas linhas com reticencias em vez de quatro. */
  .dec-caixa .dec-sub{font-size:11.5px;line-height:1.4;margin-bottom:9px;
    display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
  .dec-caixa .carr{margin-top:0}
  .dec-caixa .rf-acoes{margin-top:9px}
  /* `width:100%` mais o `min-width:auto` que todo item de flex traz de
     fabrica: os dois botoes se recusavam a encolher e o segundo saia da
     tela — em 375px ele comecava no pixel 371. Aqui a largura vem do flex,
     e o min-width:0 deixa o texto quebrar antes de empurrar. */
  .dec-caixa .rf-acoes .btn{margin:0;flex:1 1 0;width:auto;min-width:0;padding:12px 10px;
    font-size:14px;line-height:1.15}
  /* No rodape os dois ficam LADO A LADO mesmo em tela estreita: em coluna
     a barra dobrava de altura e comia a tela que ela veio devolver. */
  .dec-caixa .rf-acoes .acoes-ano{flex:1 1 0;min-width:0;margin:0;flex-direction:row;gap:6px}
  /* O secundario e `nowrap`: se pudesse encolher viraria "Parar por a…"
     cortado em 46px. Ele fica do tamanho do proprio texto e quem cede e o
     principal, que tem folga. */
  .dec-caixa .rf-acoes .acoes-ano .btn2{flex:0 0 auto;font-size:12.5px;padding:12px 11px}
  .dec-caixa .op-parar{margin-top:8px;padding:8px 10px;font-size:12px}
  /* Tres cartas em 375px dao 113px de largura cada: no tamanho de desktop a
     caixa passava de 285px, um terco da tela so pra escolher. Apertada ela
     fica em ~215 e sobra tela pra ficha e pra trajetoria — que e o que se
     olha ANTES de decidir. */
  .dec-caixa .dec-card{padding:10px 7px 9px;gap:7px;border-radius:12px}
  .dec-caixa .dec-card-tit{font-size:12px;letter-spacing:-.1px;line-height:1.2;min-height:2.4em}
  .dec-caixa .dec-card-res{gap:4px}
  .dec-caixa .dec-res{padding:5px 6px;gap:4px;border-radius:7px}
  .dec-caixa .dec-res i{font-size:10px}
  .dec-caixa .dec-res em{font-size:9.5px;line-height:1.2}
  .dec-caixa .dec-res b{font-size:10.5px}
  /* A carta da proposta segue a mesma dieta: escudo menor e nome de 11.5px
     e o que faz "Rio Grande Valley Vipers" caber em 113px sem virar
     "Rio Gran…" ja na primeira palavra. */
  .dec-caixa .oferta-linha{padding:9px 5px;gap:5px}
  .dec-caixa .ol-txt b{font-size:11.5px}

  .colunas-ano .col-principal{display:contents}
  .colunas-ano .bl-ficha{order:1}
  .colunas-ano .col-lado{order:2}
  .colunas-ano .bl-decisao{order:3;margin-top:0}
}

/* CARD — .bpcard do padrão (nunca .card, que o Bootstrap sequestra) */
.bpcard{background:var(--panel);border:1px solid var(--border);border-radius:var(--radius);
  padding:13px 14px;margin-bottom:11px;color:var(--text)}
.bpcard-title{font-size:10px;font-weight:700;letter-spacing:1px;text-transform:uppercase;
  color:var(--text2);margin-bottom:12px;display:flex;align-items:center;justify-content:space-between;gap:8px}

.intro{text-align:center;padding:8px 0 18px}
.intro h1{font-size:22px;font-weight:900;letter-spacing:-.4px;margin-bottom:8px;color:var(--text)}
.intro p{font-size:13px;color:var(--text2);line-height:1.55;max-width:440px;margin:0 auto}
h1{font-size:22px;font-weight:900;letter-spacing:-.4px;margin-bottom:8px;color:var(--text)}
.lead{font-size:13px;color:var(--text2);line-height:1.55;margin-bottom:16px}
p{margin-bottom:10px;color:var(--text2);font-size:13px;line-height:1.55}
h2{font-size:10px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:var(--text2);margin:18px 0 8px}

.campo label,label{display:block;font-size:9px;font-weight:700;letter-spacing:1px;text-transform:uppercase;
  color:var(--text2);margin:12px 0 5px}
input,select{width:100%;background:var(--panel2);border:1px solid var(--border);border-radius:10px;
  padding:11px 12px;font-family:var(--font);font-size:14px;font-weight:700;color:var(--text);
  outline:none;transition:.15s}
/* O foco muda so a borda. Antes pintava o fundo com --red-soft, que e
   translucido: no <select> aberto isso ficava por cima do branco padrao do
   navegador e virava rosa, com o texto claro sumindo dentro. */
input:focus{border-color:var(--red);background:var(--red-soft)}
select:focus{border-color:var(--red);background:var(--panel2)}

/* A lista aberta do <select> e desenhada pelo sistema e nao herda o tema —
   sem pintar a <option> explicitamente ela nasce branca, e o texto claro
   fica ilegivel. */
select option{background:var(--panel2);color:var(--text)}
/* A opcao marcada se distingue pelo fundo mais escuro e pelo peso, nao
   pela cor: vermelho sobre o painel da 4,17:1, abaixo do minimo legivel. */
select option:checked{background:var(--panel3);color:var(--text);font-weight:800}
input::placeholder{color:var(--text3);font-weight:500}

/* ESCOLHAS — .tipo do padrão */
.grade{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin-top:6px}
.tipo{background:var(--panel2);border:1px solid var(--border);border-radius:var(--radius);
  padding:14px 12px;text-align:left;cursor:pointer;transition:.2s;color:var(--text);font-family:var(--font)}
.tipo:hover{border-color:var(--border2);background:var(--panel3)}
.tipo.on{border-color:var(--red);background:var(--red-soft)}
.tipo b{display:block;font-size:14px;font-weight:900;letter-spacing:.2px;color:var(--text);margin-bottom:3px}
.tipo span{font-size:10.5px;color:var(--text2);line-height:1.45;display:block}

/* ── ENTRADA ─────────────────────────────────────────────────────────── */
/* Uma coluna centrada, como no Copero: o título grande, a decisão, e o
   ranking dos outros só depois de tudo que a pessoa veio fazer. */
.inicio{max-width:640px;margin:5vh auto 0;text-align:center}
.inicio h1{font-size:36px;line-height:1.1;margin-bottom:10px}
.inicio p.lead{font-size:15px;margin:0 0 24px}
.inicio h2{text-align:left;margin-top:30px}
.inicio .bpcard,.inicio .nota-txt{text-align:left}
@media (max-width:560px){.inicio{margin-top:2vh}.inicio h1{font-size:28px}}

/* ── ESCOLHAS DA ENTRADA ─────────────────────────────────────────────── */
/* Liga e ritmo saíram da tela de identidade e vieram pra cá, como no
   Copero: são decisões sobre a PARTIDA, não sobre o jogador. */
.pre-escolhas{display:grid;grid-template-columns:1fr 1fr;gap:11px;margin:2px 0 14px}
.pre-rot{display:block;font-size:9.5px;font-weight:800;letter-spacing:1.1px;text-transform:uppercase;
  color:var(--text3);margin-bottom:7px}
.pre-duo{display:grid;grid-template-columns:1fr 1fr;gap:7px}
.pre-duo button{background:var(--panel2);border:1px solid var(--border);border-radius:12px;
  padding:11px 9px;text-align:left;cursor:pointer;color:var(--text);font-family:var(--font);transition:.16s}
.pre-duo button:hover{border-color:var(--border2);background:var(--panel3)}
.pre-duo button.on{border-color:var(--red);background:var(--red-soft)}
.pre-duo button b{display:block;font-size:13px;font-weight:900;letter-spacing:.2px}
.pre-duo button small{display:block;font-size:9.5px;color:var(--text2);line-height:1.35;margin-top:2px}
@media (max-width:560px){.pre-escolhas{grid-template-columns:1fr}}

/* BOTÕES — .spin-btn e .reroll-btn do padrão */
.btn{width:100%;background:var(--red);color:#fff;border:0;border-radius:12px;padding:15px;
  font-family:var(--font);font-size:15px;font-weight:800;letter-spacing:.3px;cursor:pointer;
  transition:.15s;display:block;margin-top:4px}
.btn:hover:not(:disabled){filter:brightness(1.12)}
.btn:active:not(:disabled){transform:scale(.985)}
.btn:disabled{background:var(--panel3);color:var(--text3);cursor:not-allowed}
.btn2{background:transparent;border:1px solid var(--border2);color:var(--text2);border-radius:11px;
  padding:11px;font-size:12.5px;font-weight:700;margin-top:9px}
.btn2:hover{border-color:var(--blue);color:var(--blue);background:var(--blue-soft);filter:none}

/* OPÇÃO DE DECISÃO — mesma linguagem do .nota do build */
/* OPÇÃO — o botão É a decisão, então carrega o peso visual da tela. */
/* Borda de 1px e hover que só acende a borda: com 1.5px e o card subindo
   com sombra a cada passada de mouse, a tela toda tremia. A decisão fica
   igualmente clara, e a atenção sobra pro texto da escolha. */
.op{display:block;width:100%;text-align:left;background:var(--panel2);color:var(--text);
  border:1px solid var(--border);border-radius:13px;padding:11px 14px;margin-bottom:8px;
  font-family:var(--font);font-size:13px;font-weight:600;cursor:pointer;
  transition:border-color .12s,background .12s;line-height:1.45}
.op:hover{border-color:var(--border2);background:var(--panel3)}
.op:active{border-color:var(--red)}
.op-titulo{display:block;font-size:13.5px;font-weight:800;letter-spacing:-.1px;color:var(--text)}

/* As duas pontas, lado a lado. A cor diz se AQUELE desfecho ajuda ou
   atrapalha — não qual é o "lado bom" da aposta: operar o joelho é o lado
   seguro e mesmo assim custa uma temporada. */
.op-chips{display:flex;gap:6px;margin-top:7px}
/* Porcentagem e efeito na MESMA linha. Empilhados, os três botões de
   decisão empurravam a última opção pra fora de uma tela de 720px — a
   informação é a mesma, ocupando metade da altura. */
.chip-ap{flex:1;min-width:0;border-radius:9px;padding:5px 8px;border:1px solid;
  background:var(--panel3);transition:.15s;display:flex;align-items:baseline;gap:6px}
.chip-ap b{font-family:var(--num);font-size:13px;font-weight:900;line-height:1.2;
  font-variant-numeric:tabular-nums;flex:none}
.chip-ap i{font-style:normal;font-size:10px;font-weight:700;color:var(--text2);
  line-height:1.2;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.chip-bom{color:var(--green);border-color:rgba(34,197,94,.35);background:var(--green-soft)}
.chip-ruim{color:var(--red);border-color:var(--red-glow);background:var(--red-soft)}
.chip-neutro{color:var(--text2);border-color:var(--border)}

/* Depois do sorteio: o que saiu fica aceso, o que não saiu apaga. */
.chip-ap.caiu{box-shadow:0 0 0 2px currentColor inset}
.chip-ap.caiu i{color:currentColor}
.chip-ap.apagado{opacity:.32;filter:grayscale(.7)}

/* DESISTIR — existe, mas não disputa atenção com o botão de avançar.
   Quem procura, acha; quem não procura, não esbarra sem querer. */
.btn-desistir{display:block;width:100%;margin-top:18px;background:none;border:none;
  font-family:var(--font);font-size:11.5px;font-weight:700;color:var(--text3);
  letter-spacing:.3px;cursor:pointer;padding:8px;border-radius:9px;transition:.15s}
.btn-desistir:hover{color:var(--red);background:var(--red-soft)}

/* PLACAR DA TEMPORADA */
.placar{background:var(--panel);border:1px solid var(--border);border-radius:var(--radius);
  overflow:hidden;margin-bottom:14px}
.placar-topo{display:flex;align-items:center;gap:10px;padding:11px 14px;border-bottom:1px solid var(--border);
  background:var(--panel2)}
.ano{font-family:var(--num);font-size:14px;font-weight:700;color:var(--red);letter-spacing:.4px}
.placar-time{font-size:12.5px;font-weight:700;margin-left:auto;text-align:right;line-height:1.25;color:var(--text)}
.placar-time small{display:block;font-size:9.5px;font-weight:600;color:var(--text2);letter-spacing:.3px}

.linha-stats{display:grid;grid-template-columns:repeat(3,1fr);border-bottom:1px solid var(--border)}
.st{padding:7px 6px;text-align:center;border-right:1px solid var(--border)}
.st:last-child{border-right:none}
.st b{display:block;font-family:var(--num);font-size:19px;font-weight:900;letter-spacing:-.8px;
  font-variant-numeric:tabular-nums;line-height:1;color:var(--text)}
.st span{display:block;font-size:8px;font-weight:700;letter-spacing:1.1px;text-transform:uppercase;
  color:var(--text2);margin-top:3px}
.linha-mini{display:flex;border-bottom:1px solid var(--border)}
.mini{flex:1;padding:5px 6px;text-align:center;border-right:1px solid var(--border)}
.mini:last-child{border-right:none}
.mini b{font-family:var(--num);font-size:13px;font-weight:700;font-variant-numeric:tabular-nums;color:var(--text)}
.mini span{display:block;font-size:7.5px;font-weight:700;letter-spacing:.9px;text-transform:uppercase;
  color:var(--text2);margin-top:1px}
/* O fecho do ano: o recorde como número, o desfecho como etiqueta e as
   medalhas na mesma faixa. Era texto corrido com o placar em negrito e as
   medalhas soltas embaixo, sem relação com ele. */
.ano-fecho{border-top:1px solid var(--border);margin-top:9px;padding-top:9px;
  display:flex;flex-direction:column;gap:7px}
.campanha{display:flex;align-items:center;gap:8px;flex-wrap:wrap;font-size:12px;color:var(--text2)}
.campanha b{font-family:var(--num);font-size:15px;font-weight:900;letter-spacing:-.5px;
  color:var(--text);font-variant-numeric:tabular-nums}
.campanha em{font-style:normal;font-size:11px;color:var(--text3)}
.camp-selo{font-style:normal;font-size:9.5px;font-weight:800;letter-spacing:.8px;
  text-transform:uppercase;padding:3px 9px;border-radius:999px;border:1px solid}
.camp-titulo{color:var(--red);border-color:var(--red-glow);background:var(--red-soft)}
.camp-vice{color:var(--amber);border-color:rgba(245,158,11,.35);background:var(--amber-soft)}
.camp-playoff{color:var(--green);border-color:rgba(34,197,94,.35);background:var(--green-soft)}
.camp-fora{color:var(--text3);border-color:var(--border2);background:var(--panel3)}
.premios{display:flex;flex-wrap:wrap;gap:5px}
.pr{font-size:10px;font-weight:800;letter-spacing:.4px;padding:4px 9px;border-radius:999px;
  border:1px solid;white-space:nowrap}
.pr.ouro{color:var(--amber);border-color:rgba(245,158,11,.35);background:var(--amber-soft)}
.pr.normal{color:var(--text2);border-color:var(--border);background:var(--panel2)}
.pr.titulo{color:var(--red);border-color:var(--red-glow);background:var(--red-soft)}

/* MARCA DO TIME */
.marca-logo{border-radius:7px;object-fit:contain;flex:none;background:var(--panel2);padding:2px}
.marca-time{display:inline-flex;align-items:center;justify-content:center;border-radius:9px;
  font-weight:900;letter-spacing:-.5px;color:#fff;flex:none;text-shadow:0 1px 2px rgba(0,0,0,.45)}

/* FINAIS */
.jogos{display:flex;flex-direction:column}
.jogo{display:flex;align-items:center;gap:10px;padding:9px 14px;border-bottom:1px solid var(--border);font-size:12px}
.jogo:last-child{border-bottom:none}
.jogo-n{font-family:var(--num);color:var(--text2);width:50px;flex:none}
.jogo-r{font-weight:800;letter-spacing:.4px;font-size:10.5px}
.jogo.v .jogo-r{color:var(--green)} .jogo.d .jogo-r{color:var(--red)}
.jogo-p{margin-left:auto;font-family:var(--num);color:var(--text2);font-variant-numeric:tabular-nums}

/* ATRIBUTOS */
.atr{display:flex;align-items:center;gap:9px;margin-bottom:6px;font-size:11.5px}
.atr-n{width:76px;flex:none;color:var(--text2)}
.atr-b{flex:1;height:6px;background:var(--panel3);border-radius:99px;overflow:hidden}
.atr-f{height:100%;background:var(--red);border-radius:99px;transition:width .4s}
.atr-v{width:22px;flex:none;text-align:right;font-family:var(--num);font-weight:700;
  font-variant-numeric:tabular-nums;font-size:11.5px;color:var(--text)}

/* SÚMULA */
.sumula{overflow-x:auto;border:1px solid var(--border);border-radius:12px;background:var(--panel)}
table{width:100%;border-collapse:collapse;font-size:11.5px;min-width:420px}
th{text-align:right;padding:8px 9px;font-size:8px;font-weight:700;letter-spacing:1px;text-transform:uppercase;
  color:var(--text2);background:var(--panel2);white-space:nowrap}
th:first-child,td:first-child{text-align:left}
td{padding:8px 9px;border-top:1px solid var(--border);color:var(--text2);text-align:right;
  font-family:var(--num);font-variant-numeric:tabular-nums;white-space:nowrap}
td:first-child,td.txt{font-family:var(--font);color:var(--text);font-weight:600}
tr.tit td{color:var(--red)}

.ovr-box{text-align:center;padding:14px 0 2px;margin-top:10px;border-top:1px solid var(--border)}
.grande{font-family:var(--num);font-size:38px;font-weight:900;line-height:1;letter-spacing:-1.5px;
  font-variant-numeric:tabular-nums;color:var(--text)}
.tier{font-size:16px;font-weight:900;letter-spacing:-.3px;margin-bottom:4px;color:var(--red)}
.nota-txt{font-size:11.5px;color:var(--text2);line-height:1.55;margin-top:10px}
.centro{text-align:center}

/* ── IDENTIDADE ────────────────────────────────────────────────────────
   Três colunas: quem você é, de onde vem, onde joga. A camisa e a quadra
   existem porque escolher posição num <select> é preencher formulário —
   e a primeira tela do jogo é a que decide se a pessoa continua. */
.id-grade{display:grid;grid-template-columns:minmax(0,.85fr) minmax(0,1.1fr) minmax(0,1fr);
  gap:22px;margin-bottom:18px}
/* A barra de etapa e a linha de acao só existem no celular — no desktop as
   tres colunas estao todas na tela e nao ha etapa pra avancar. */
.etapa-cab{display:none}
.etapa-pe{display:none}
@media(max-width:860px){
  .id-grade{grid-template-columns:1fr;gap:26px}

  /* Uma etapa por vez. O :nth-child casa com a ordem das colunas no HTML:
     1 identidade, 2 nacionalidade, 3 posição. */
  .id-grade .id-col{display:none}
  .id-grade[data-etapa="1"] .id-col:nth-child(1),
  .id-grade[data-etapa="2"] .id-col:nth-child(2),
  .id-grade[data-etapa="3"] .id-col:nth-child(3){display:block}
  /* O título da coluna já está na barra de etapa. */
  .id-grade .id-col-tit{display:none}

  .etapa-cab{display:block;margin-bottom:16px}
  .etapa-tit{font-size:18px;font-weight:900;letter-spacing:-.3px;margin-bottom:9px}
  .etapa-barra{height:5px;border-radius:999px;background:var(--panel2);overflow:hidden}
  .etapa-barra i{display:block;height:100%;background:var(--red);border-radius:999px;
    transition:width .25s ease}
  .etapa-pe{display:flex;gap:10px;margin:0 0 18px;padding-bottom:16px;
    border-bottom:1px solid var(--border)}
  .etapa-pe .btn{flex:1;width:auto;margin:0}

}
.id-col-tit{font-size:12px;font-weight:800;letter-spacing:.4px;text-align:center;
  color:var(--text);margin-bottom:14px}

/* A camisa: o nome e o número aparecem nela enquanto se digita. */
.camisa{position:relative;width:172px;margin:0 auto 16px;aspect-ratio:1/1.06}
.camisa svg{width:100%;height:100%;display:block;filter:drop-shadow(0 10px 24px rgba(0,0,0,.45))}
.camisa-nome{position:absolute;top:30%;left:0;right:0;text-align:center;font-size:13px;
  font-weight:800;letter-spacing:1px;color:#fff;text-transform:uppercase;
  white-space:nowrap;overflow:hidden;text-overflow:ellipsis;padding:0 34px}
.camisa-num{position:absolute;top:39%;left:0;right:0;text-align:center;font-family:var(--num);
  font-size:52px;font-weight:900;line-height:1;color:#fff;letter-spacing:-2px}
.id-campos{display:grid;grid-template-columns:1fr 84px;gap:9px}
.id-campo label{display:block;font-size:9.5px;font-weight:800;letter-spacing:.8px;
  text-transform:uppercase;color:var(--text2);margin-bottom:5px;text-align:center}
.id-campo input{width:100%;background:var(--panel2);border:1px solid var(--border);
  border-radius:10px;padding:11px 12px;font-family:var(--font);font-size:14px;font-weight:700;
  color:var(--text);outline:none;text-align:center}
.id-campo input:focus{border-color:var(--red)}
.id-duo{display:grid;grid-template-columns:1fr 1fr;gap:0;margin-top:11px;
  background:var(--panel2);border:1px solid var(--border);border-radius:10px;overflow:hidden}
.id-duo button{background:transparent;border:0;padding:11px 6px;font-family:var(--font);
  font-size:13px;font-weight:700;color:var(--text2);cursor:pointer;transition:.15s}
.id-duo button.on{background:var(--text);color:var(--bg)}

/* País: busca em cima, lista rolável embaixo, duas colunas. */
.nac-busca{position:relative;margin-bottom:11px}
.nac-busca input{width:100%;background:var(--panel2);border:1px solid var(--border);
  border-radius:10px;padding:11px 12px 11px 34px;font-family:var(--font);font-size:13px;
  color:var(--text);outline:none}
.nac-busca input:focus{border-color:var(--red)}
.nac-busca i{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--text3);font-size:13px}
.nac-lista{display:grid;grid-template-columns:1fr 1fr;gap:4px;max-height:264px;overflow-y:auto;
  background:var(--panel2);border:1px solid var(--border);border-radius:12px;padding:8px}
@media(max-width:520px){.nac-lista{grid-template-columns:1fr}}
.nac-item{display:flex;align-items:center;gap:9px;padding:8px 9px;border-radius:8px;
  background:transparent;border:0;cursor:pointer;text-align:left;transition:.12s;width:100%}
.nac-item:hover{background:var(--panel3)}
.nac-item.on{background:var(--red-soft);outline:1px solid var(--red)}
.nac-flag{line-height:1;flex:none;display:inline-flex;align-items:center}
/* 3:2 é a proporção da maioria das bandeiras; as que não são ficam com a
   altura fixa e a largura que couber, sem esticar. */
.band{height:14px;width:21px;border-radius:2px;display:block;flex:none;
      box-shadow:0 0 0 1px rgba(255,255,255,.16)}
.band-sem{font-size:10px;font-weight:700;letter-spacing:.04em;color:var(--text3)}
.tag .band{display:inline-block;vertical-align:-2px;margin-right:3px}
.nac-nome{font-size:12.5px;font-weight:700;color:var(--text2);white-space:nowrap;
  overflow:hidden;text-overflow:ellipsis}
.nac-item.on .nac-nome{color:var(--text)}
.nac-vazio{grid-column:1/-1;text-align:center;font-size:12px;color:var(--text3);padding:18px 0}

/* Quadra: cada posição no lugar onde ela joga. */
.quadra{position:relative;aspect-ratio:1/1.18;border-radius:12px;overflow:hidden;
  background:linear-gradient(170deg,#1d2a1f,#141c16);border:2px solid rgba(255,255,255,.14)}
.quadra i.linha{position:absolute;border:2px solid rgba(255,255,255,.16);display:block}
.q-garrafao{left:33%;right:33%;bottom:0;height:34%;border-bottom:0}
.q-circulo{left:38%;right:38%;bottom:28%;aspect-ratio:1;border-radius:50%}
.q-arco{left:8%;right:8%;bottom:0;height:56%;border-radius:50% 50% 0 0/100% 100% 0 0;border-bottom:0}
.q-meio{left:0;right:0;top:0;height:0;border-width:0 0 2px 0}
.pos-chip{position:absolute;transform:translate(-50%,-50%);background:rgba(10,14,11,.82);
  border:1.5px solid rgba(255,255,255,.18);color:#e7ece8;border-radius:999px;
  padding:7px 13px;font-family:var(--font);font-size:12px;font-weight:800;letter-spacing:.4px;
  cursor:pointer;transition:.15s;white-space:nowrap}
.pos-chip:hover{border-color:#fff}
.pos-chip.on{background:#fff;color:#12160f;border-color:#fff;transform:translate(-50%,-50%) scale(1.14);
  box-shadow:0 6px 18px rgba(0,0,0,.5)}
.pos-desc{font-size:11.5px;color:var(--text3);line-height:1.5;text-align:center;margin-top:11px;min-height:34px}
.pos-desc b{color:var(--text2)}
.pos-tende{display:block;margin-top:5px;font-size:10.5px;color:var(--text3);opacity:.85}

/* O cabecalho da criacao: titulo a esquerda, acoes a direita. No celular
   as acoes somem daqui — quem manda la e a linha de etapa. */
.id-cab{display:flex;align-items:flex-end;justify-content:space-between;gap:16px;flex-wrap:wrap;
  margin-bottom:22px;padding-bottom:16px;border-bottom:1px solid var(--border)}
.id-cab-txt{min-width:0;flex:1 1 260px}
.id-cab-txt h1{margin-bottom:6px}
.id-cab-txt .lead{margin:0}
.id-cab-acoes{display:flex;align-items:center;gap:9px;flex:0 0 auto}
.id-cab-acoes .btn{width:auto;margin:0;padding:12px 22px;font-size:14px}
/* No celular as ações do cabeçalho SOMEM: quem manda lá é a linha de etapa,
   logo abaixo da barra de progresso.
   Esta regra tem que morar AQUI, depois do `display:flex` de cima. Ela
   existia desde sempre — só que lá no bloco de celular, ANTES desta linha —,
   e media query não adiciona especificidade nenhuma: com o mesmo peso, quem
   vale é a de baixo. Resultado: no celular a pessoa via "Voltar / Confirmar
   identidade" no topo E "Voltar / Continuar" no pé, a mesma ação duas vezes.
   Foi exatamente esse o botão duplicado que apareceu jogando. */
@media (max-width:860px){
  .id-cab-acoes{display:none}
}
@media (max-width:640px){
  .id-cab{flex-direction:column;align-items:stretch;gap:12px}
  /* Em coluna a base do flex vira altura — e 260px de base viravam 260px
     de vão embaixo do título. */
  .id-cab-txt{flex:0 0 auto}
}
/* ── FIM DE CARREIRA ───────────────────────────────────────────────────
   O cartão, a escada e as grades existem pra uma coisa só: a tela final é
   a que o pessoal manda no grupo. Ela precisa caber num print e dizer a
   carreira inteira sem rolagem. */

/* A cor sai do nome do time, mesma conta do monograma — assim o cartão de
   quem jogou no Envood é sempre o mesmo verde, print após print. */
.cartao{position:relative;overflow:hidden;border-radius:16px;padding:20px 18px;margin-bottom:14px;
  background:linear-gradient(155deg,var(--c1,#2a2a31),var(--c2,#131317));
  border:1px solid rgba(255,255,255,.14)}
.cartao::before{content:"";position:absolute;inset:0;pointer-events:none;
  background:repeating-linear-gradient(135deg,rgba(255,255,255,.035) 0 9px,transparent 9px 18px)}
.cartao > *{position:relative;z-index:1}
.ct-topo{display:flex;align-items:flex-start;justify-content:space-between;gap:12px}
.ct-ovr{font-family:var(--num);font-size:46px;font-weight:900;line-height:.9;letter-spacing:-3px;
  color:#fff;font-variant-numeric:tabular-nums}
.ct-rot{font-size:9px;font-weight:800;letter-spacing:1.2px;text-transform:uppercase;
  color:rgba(255,255,255,.65);margin-top:5px}
.ct-dir{text-align:right;font-size:12px;font-weight:700;color:rgba(255,255,255,.9);line-height:1.5}
.ct-tier{font-size:19px;font-weight:900;letter-spacing:-.4px;color:#fff;margin:14px 0 2px}
.ct-legado{font-size:11.5px;color:rgba(255,255,255,.68)}
.ct-nums{display:flex;flex-wrap:wrap;gap:14px 18px;margin-top:15px}
.ct-nums div{text-align:center;min-width:44px}
.ct-nums b{display:block;font-family:var(--num);font-size:21px;font-weight:900;line-height:1;
  color:#fff;font-variant-numeric:tabular-nums}
.ct-nums span{display:block;font-size:8.5px;font-weight:700;letter-spacing:.7px;text-transform:uppercase;
  color:rgba(255,255,255,.6);margin-top:4px}
/* Clubes e médias, no mesmo formato do cartão em imagem — o que aparece na
   tela é o que sai no print, sem surpresa na hora de compartilhar. */
.ct-listas{display:grid;grid-template-columns:1fr 1fr;gap:12px 16px;margin-top:16px}
/* Os clubes viraram escudo: cinco nomes empilhados eram a metade do cartão
   escrita em letra miúda, e o escudo diz a mesma coisa de relance. */
.ct-clubes{margin-top:16px}
.ct-escudos{display:flex;flex-wrap:wrap;align-items:center;gap:7px;margin-top:6px}
.ct-escudos .marca-logo,.ct-escudos .marca-time{border-radius:6px;background:rgba(255,255,255,.07)}
.ct-mais{font-size:11px;font-weight:800;color:rgba(255,255,255,.55)}
.ct-medias{margin-top:14px;gap:12px 16px}
.ct-col{min-width:0;display:flex;flex-direction:column;gap:3px}
.ct-col-tit{font-size:8.5px;font-weight:800;letter-spacing:.9px;text-transform:uppercase;
  color:rgba(255,255,255,.55);margin-bottom:2px}
.ct-col-item{font-size:12.5px;font-weight:700;color:#fff;white-space:nowrap;overflow:hidden;
  text-overflow:ellipsis}
.ct-pe{font-family:var(--num);font-size:9.5px;color:rgba(255,255,255,.42);margin-top:16px;letter-spacing:.3px}

/* Grade densa: números da carreira e prêmios cabem sem virar lista longa. */
.grade-num{display:grid;grid-template-columns:repeat(3,1fr);gap:7px}
.gn{background:var(--panel2);border:1px solid var(--border);border-radius:9px;padding:10px 4px;text-align:center}
.gn b{display:block;font-family:var(--num);font-size:19px;font-weight:900;line-height:1;
  color:var(--text);font-variant-numeric:tabular-nums}
.gn span{display:block;font-size:8.5px;font-weight:700;letter-spacing:.6px;text-transform:uppercase;
  color:var(--text2);margin-top:5px}
.gn.zero b{color:var(--text3)}


/* Trajetória: um time por bloco, com os anos e quantas temporadas. */
.traj{display:flex;flex-direction:column;gap:7px}
.tj{display:flex;align-items:center;gap:11px;padding:9px 11px;background:var(--panel2);
  border:1px solid var(--border);border-radius:10px;position:relative}
.tj:not(:last-child)::after{content:"";position:absolute;left:29px;bottom:-8px;width:2px;height:7px;
  background:var(--border2)}
.tj-info{flex:1;min-width:0}
.tj-time{font-size:13.5px;font-weight:800;color:var(--text);line-height:1.2}
.tj-anos{font-family:var(--num);font-size:10.5px;color:var(--text2);margin-top:2px}
.tj-qtd{flex:none;font-family:var(--num);font-size:10.5px;color:var(--red);text-align:right;white-space:nowrap}
.dec-txt{font-size:13px;color:var(--text2);margin-bottom:12px;line-height:1.55}
.dec-txt b{color:var(--text)}
.barra-topo{height:3px;background:var(--panel3);border-radius:99px;overflow:hidden;margin-bottom:14px}
.barra-topo i{display:block;height:100%;background:var(--red);transition:width .5s}
/* CARD DO OVR — a cor É a informação. O número sozinho não dizia se 74
   era bom ou ruim; a faixa de cor responde isso antes de você ler. */
.ovr-linha{display:flex;align-items:center;gap:11px;padding:13px 14px;border-bottom:1px solid var(--border);
  background:linear-gradient(90deg,color-mix(in srgb,var(--cor) 16%,var(--panel2)),var(--panel2) 70%);
  border-left:3px solid var(--cor)}
.ovr-esq{display:flex;flex-direction:column;gap:2px;flex:none}
.ovr-rot{font-size:8.5px;font-weight:700;letter-spacing:1.2px;text-transform:uppercase;color:var(--text2)}
.ovr-faixa{font-size:9.5px;font-weight:800;letter-spacing:.5px;text-transform:uppercase;color:var(--cor)}
.ovr-val{font-family:var(--num);font-size:30px;font-weight:900;letter-spacing:-1.5px;line-height:1;
  color:var(--cor);font-variant-numeric:tabular-nums}
.ovr-delta{font-family:var(--num);font-size:12px;font-weight:700;font-variant-numeric:tabular-nums}
.ovr-barra{flex:1;height:7px;background:var(--panel3);border-radius:99px;overflow:hidden}
.ovr-barra i{display:block;height:100%;background:var(--cor);border-radius:99px;transition:width .5s}

/* ═══ A FICHA — mesmo molde do Copero ════════════════════════════════
   Uma caixa só: OVR grande, clube, números da vida, etiquetas, vitrine,
   totais e o boletim do ano. Os dois jogos leem igual; o que muda é o
   esporte dentro. */
.caixa{background:var(--panel);border:1px solid var(--border);border-radius:16px}
.ficha{padding:16px;position:relative;overflow:hidden}
/* O escudo do time como marca d'água: grande, cortado pela borda e quase
   apagado — dá peso ao clube sem disputar com o texto. */
.ficha-marca{position:absolute;right:-30px;top:44px;opacity:.085;pointer-events:none;z-index:0}
.ficha-marca .marca-logo,.ficha-marca .marca-time{width:140px!important;height:140px!important;
  border-radius:0;box-shadow:none}
.ficha > *:not(.ficha-marca){position:relative;z-index:1}

.ficha-topo{display:flex;align-items:center;gap:13px;margin-bottom:11px}
/* O corpo da ficha é uma GRADE NOMEADA, e é ela que troca de desenho entre
   desktop e celular sem que o HTML mude uma linha:

     desktop            celular
     clube idade salario  tags   idade
     tags tags tags       clube  salario

   No celular as etiquetas sobem pro lado da idade e o clube divide a
   segunda linha com o salário — o cartão compacto do Copero. */
.ficha-corpo{flex:1 1 90px;min-width:0;display:grid;align-items:center;gap:8px 13px;
  grid-template-columns:minmax(0,1fr) auto auto;
  grid-template-areas:"info idade salario" "tags tags tags"}
.n-idade{grid-area:idade}
.n-salario{grid-area:salario}
.ovr-caixa{width:82px;height:82px;border-radius:14px;display:flex;flex-direction:column;
  align-items:center;justify-content:center;gap:2px;flex:none;color:#fff;
  background:linear-gradient(160deg,color-mix(in srgb,var(--cor) 90%,#000),color-mix(in srgb,var(--cor) 52%,#000))}
.ovr-caixa small{font-size:9px;font-weight:800;letter-spacing:1px;opacity:.8}
.ovr-caixa b{font-family:var(--num);font-size:33px;font-weight:900;line-height:1;letter-spacing:-1.5px;
  font-variant-numeric:tabular-nums}
.ovr-caixa i{font-family:var(--num);font-style:normal;font-size:10.5px;font-weight:800;
  background:rgba(0,0,0,.3);border-radius:99px;padding:1px 7px}
.ficha-info{grid-area:info;min-width:0;display:flex;flex-direction:column;gap:3px}
.ficha-clube{display:flex;align-items:center;gap:9px;font-size:19px;font-weight:900;letter-spacing:-.5px;
  white-space:nowrap;overflow:hidden;min-width:0}
.ficha-clube span{overflow:hidden;text-overflow:ellipsis;min-width:0}
.ficha-liga{font-size:11px;color:var(--text3);font-weight:700;letter-spacing:.3px;
  white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.ficha-num{text-align:right;font-size:9.5px;color:var(--text3);text-transform:uppercase;white-space:nowrap;
  font-weight:800;letter-spacing:.5px}
.ficha-num b{display:block;font-family:var(--num);font-size:18px;color:var(--text);letter-spacing:-.5px;
  font-variant-numeric:tabular-nums}

.ficha-tags{grid-area:tags;display:flex;align-items:center;gap:6px;flex-wrap:wrap;min-width:0}
.tag{display:inline-flex;align-items:center;gap:5px;background:var(--panel3);border-radius:6px;
  padding:4px 9px;font-size:10.5px;font-weight:800;white-space:nowrap;color:var(--text)}
.tag svg{width:16px;height:11px;border-radius:2px;flex:none;display:block}
.tag.idolo{background:var(--blue-soft);color:#bfdbfe}

.ficha-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));
  border-top:1px solid var(--border);border-bottom:1px solid var(--border);padding:9px 0;margin-bottom:9px}
.ficha-stats div{text-align:center;min-width:0}
.ficha-stats span{display:block;font-size:9px;font-weight:800;letter-spacing:.8px;color:var(--text3);
  text-transform:uppercase;margin-bottom:3px}
.ficha-stats b{font-family:var(--num);font-size:19px;font-weight:900;letter-spacing:-.6px;
  font-variant-numeric:tabular-nums}

/* A caixa da trajetória: sem padding, porque a lista já tem o dela. */
.caixa.linha{padding:0;overflow:hidden}
@media (max-width:979px){
  /* O gradiente é o aviso de que a lista continua: cortada em linha reta
     ela parece uma carreira que acabou ali. */
  /* No celular a janela encolhe junto com a linha. */
  .tj-anos{--tj-h:25px}
}
.caixa.linha .trajeto{border:none;border-radius:0;background:transparent}
.ficha-ano{background:var(--panel2);border:1px solid var(--border);border-radius:12px;padding:8px 10px}
.ficha-ano-cab{font-size:9px;font-weight:800;letter-spacing:1.1px;text-transform:uppercase;
  color:var(--text3);margin-bottom:6px}
/* ── A TELA DO JOGO NO CELULAR ────────────────────────────────────────
   Cada bloco que encolhe aqui é uma linha da carreira que aparece sem
   rolar. O desenho é o do Copero: ficha de duas linhas ao lado do OVR,
   súmula logo abaixo, decisão por último. */
@media(max-width:760px){
  .ficha{padding:12px}
  .ficha-topo{gap:10px;margin-bottom:9px}
  .ovr-caixa{width:70px;height:70px;border-radius:12px}
  .ovr-caixa b{font-size:28px}
  .ficha-corpo{grid-template-columns:minmax(0,1fr) auto;
    grid-template-areas:"tags idade" "info salario";
    gap:7px 10px;background:var(--panel2);border-radius:12px;padding:9px 11px}
  .ficha-clube{font-size:16.5px;gap:7px}
  .ficha-liga{font-size:10.5px}
  .ficha-num b{font-size:15px}

  /* A faixa de nível vira uma linha fina: o rótulo e a barra dizem a mesma
     coisa em metade da altura. */
  .ovr-linha{padding:8px 11px;gap:9px}

  .st{padding:6px 5px}
  .st b{font-size:17px}
  .mini{padding:4px 5px}

  /* O paragrafo de contexto SAI no celular. Ele explica o que o titulo ja
     diz e o que as proprias cartas dizem melhor — e custava quatro linhas
     de tela em cima da decisao. No desktop fica: la o espaco nao esta em
     disputa. */
  .dec-sub{display:none}
  .bpcard .dec-txt{font-size:12px;line-height:1.45;margin-bottom:0}

  /* A trajetoria encolhe: sao ate vinte linhas, e cada pixel de altura
     aqui e multiplicado por vinte.

     `.trajeto .tj` e nao `.tj`: existe outra regra `.tj` mais ABAIXO no
     arquivo, e media query nao aumenta especificidade — a de baixo ganhava
     e este bloco inteiro nao valia nada. */
  .trajeto .tj{padding:3px 8px;font-size:11px;gap:5px}
  .trajeto .tj-cab{font-size:8px}
  .trajeto .tj-idade{width:23px;height:17px;font-size:10.5px}
  .trajeto .tj-ovr{font-size:10.5px;padding:1px 4px}
  .trajeto .tj-n{font-size:10.5px}
  .trajeto .tj-clube b{font-size:11.5px}
  .trajeto .tj-clube em{font-size:9.5px}
}
@media(max-width:440px){
  .ficha{padding:11px}
  .ficha-clube{font-size:15.5px}
  .ficha-stats b{font-size:17px}
  /* O corte das taças entra só aqui, que é onde a célula do clube aperta
     de verdade — até quatro cabem, da quinta em diante o nome do time
     começaria a truncar. O :not() existe porque o "+N" também é <i>. */
  .tj-trofeus.corta > i:nth-child(n+4):not(.tj-mais){display:none}
  .tj-trofeus.corta .tj-mais{display:inline-flex}
}


/* ═══ TRAJETÓRIA POR IDADE ═══════════════════════════════════════════
   Lista, não tabela: no celular uma tabela de 6 colunas ou rola de lado
   ou espreme tudo. Aqui as três informações que importam cabem em uma
   linha em qualquer largura. */
/* container-type: quem aperta a linha é a LARGURA DA CAIXA, não a da tela.
   No desktop a trajetória vive numa coluna de 350px — media query de
   viewport não enxerga isso e deixava "Los Angeles Lakers" em 80px. */
.trajeto{border:1px solid var(--border);border-radius:12px;background:var(--panel);overflow:hidden;
  container-type:inline-size}
/* A JANELA DE DOZE ANOS. A lista sobe do primeiro ano pro de agora, e a
   janela rola sozinha pra deixar a idade atual NO MEIO (focarIdadeAtual):
   o ano que interessa esta sempre a vista, com o passado em cima dele.
   O cabeçalho e a linha da seleção ficam FORA dela, ancorados. Substitui o corte com máscara + botão "ver a carreira
   inteira": a informação era a mesma, mas exigia um toque pra chegar nela. */
.tj-anos{--tj-h:28px;max-height:calc(var(--tj-h) * 12);overflow-y:auto;position:relative;
  overscroll-behavior:contain;scrollbar-width:thin;scrollbar-color:var(--border2,#33333c) transparent}
.tj-anos::-webkit-scrollbar{width:5px}
.tj-anos::-webkit-scrollbar-thumb{background:var(--border2,#33333c);border-radius:99px}
/* Cabecalho e linha da selecao vivem FORA da janela que rola, entao
   ganham a mesma largura de barra que ela come — senao as colunas de
   numero ficam deslocadas entre eles e as linhas de ano. */
.trajeto .tj-cab,.trajeto .tj-selecao{margin-right:var(--tj-barra,0px)}
.tj{display:flex;align-items:center;gap:7px;padding:5px 10px;border-bottom:1px solid var(--border);
  font-size:12px}
.tj:last-child{border-bottom:none}
.tj-cab{background:var(--panel2);font-size:8.5px;font-weight:800;letter-spacing:1px;
  text-transform:uppercase;color:var(--text3);padding-top:7px;padding-bottom:7px}
/* O .forte vem depois e tem a mesma especificidade, então precisa ser
   citado aqui — senão o "Pts" do cabeçalho sai em tamanho de número. */
.tj-cab .tj-idade,.tj-cab .tj-ovr,.tj-cab .tj-n,.tj-cab .tj-n.forte{background:none;border:none;
  color:var(--text3);font-family:var(--font);font-size:8.5px;font-weight:800;box-shadow:none;padding:0}
/* "Idade" é a palavra mais longa do cabeçalho e a coluna é a mais estreita:
   sem apertar a letra, o "e" ficava do lado de fora da caixa de 26px. */
.tj-cab .tj-idade{font-size:8px;letter-spacing:0}

/* A idade é um selo, não um número solto: verde quando a temporada
   aconteceu, vermelho no ano do título, apagado no que ainda não veio.
   A cor responde "o que rolou nesse ano?" antes da linha ser lida. */
/* Os totais da carreira, embaixo da tabela e FORA da grade dela: as colunas
   são estreitas de propósito (médias de uma casa) e "15.815" ao lado de
   "5.372" encostavam um no outro. */
.tot-faixa{margin-top:10px}
.tot-cab{font-size:9px;font-weight:800;letter-spacing:1.1px;text-transform:uppercase;
  color:var(--text3);margin-bottom:7px;padding:0 2px}
.tot-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(76px,1fr));gap:7px}
.tot-card{background:var(--panel2);border:1px solid var(--border);border-radius:11px;
  padding:9px 8px;text-align:center;min-width:0}
.tot-card b{display:block;font-family:var(--num);font-size:18px;font-weight:900;letter-spacing:-.7px;
  font-variant-numeric:tabular-nums;line-height:1;color:var(--text)}
.tot-card span{display:block;font-size:8.5px;font-weight:800;letter-spacing:1px;
  text-transform:uppercase;color:var(--text3);margin-top:4px}
.tot-card.forte b{color:var(--red)}

.tj-idade{flex:none;width:26px;height:20px;display:flex;align-items:center;justify-content:center;
  border-radius:6px;font-family:var(--num);font-size:11.5px;font-weight:800;
  font-variant-numeric:tabular-nums;background:var(--panel3);color:var(--text2)}
.sel-jogada{background:#16a34a;color:#fff}
.sel-titulo{background:var(--red);color:#fff}
/* A cor sai do CLUBE (inline); o anel vermelho é o que marca o ano de
   título, pra informação não se perder junto com a cor. */
.sel-clube{color:#fff}
.sel-campeao{color:#fff;box-shadow:inset 0 0 0 2px var(--red)}
.tj-n.pronta{animation:tjCrava .3s ease-out}
@keyframes tjCrava{0%{transform:scale(1.45);color:#fff}100%{transform:scale(1)}}
.ovr-caixa b.cravou{animation:ovrCrava .4s ease-out}
@keyframes ovrCrava{0%{transform:scale(1.28)}60%{transform:scale(.97)}100%{transform:scale(1)}}
.sel-formacao{background:var(--panel3);color:var(--text2)}
.sel-perdida{background:#3f3f46;color:var(--text2)}
/* text2 e não text3: a linha vazia já leva opacity, e as duas coisas
   juntas sumiam com o número — os anos que faltam precisam ser lidos. */
.sel-vazio{background:transparent;color:var(--text2);font-weight:700}
.sel-agora{background:transparent;color:var(--red);box-shadow:inset 0 0 0 1.5px var(--red)}

.tj-clube{flex:1;min-width:0;display:flex;align-items:center;gap:6px;font-weight:700;color:var(--text)}
.tj-clube b{font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;min-width:0}
.tj-clube em{font-style:normal;font-size:10px;font-weight:600;color:var(--text3);flex:none}
.tj-clube .marca-time,.tj-clube .marca-logo{flex:none}
.tj-escudo{flex:none;width:18px;height:18px;border-radius:5px;background:var(--panel3);
  display:flex;align-items:center;justify-content:center;font-size:10px;color:var(--text3);font-weight:800}
.tj-trofeus{flex:none;display:flex;align-items:center;gap:2px;margin-left:auto;line-height:1}
.tj-trofeus i{font-style:normal;cursor:help;display:inline-flex;line-height:0}
.tj-trofeus .tj-mais{display:none;font-family:var(--num);font-size:9px;font-weight:800;
  color:var(--text3);background:var(--panel3);border-radius:5px;padding:1px 4px}
.taca-nba{filter:drop-shadow(0 1px 2px rgba(0,0,0,.5))}

/* O OVR do ano, na cor da faixa dele — a curva do jogador aparece na
   coluna inteira sem precisar de gráfico nenhum. */
.tj-ovr{flex:none;width:30px;height:19px;display:flex;align-items:center;justify-content:center;
  border-radius:5px;font-family:var(--num);font-size:11.5px;font-weight:800;
  font-variant-numeric:tabular-nums;color:#fff;
  background:linear-gradient(160deg,color-mix(in srgb,var(--cor) 92%,#000),color-mix(in srgb,var(--cor) 62%,#000))}
.tj-ovr-vazio{background:transparent;color:var(--text3);font-weight:700}

.tj-n{flex:none;width:26px;text-align:right;font-family:var(--num);font-size:11px;font-weight:600;
  font-variant-numeric:tabular-nums;color:var(--text2)}
.tj-n.forte{font-size:12.5px;font-weight:800;color:var(--text)}
.tj-vazia{opacity:.62}
/* A linha da seleção fecha a trajetória: a bandeira ocupa a coluna que
   nas outras é a idade, porque pela seleção você joga a carreira inteira
   e não um ano. */
.tj-selecao{background:color-mix(in srgb, var(--verde,#22c55e) 12%, transparent)}
.tj-selecao .sel-selecao{background:none;padding:0;display:inline-flex;align-items:center;justify-content:center}
.tj-selecao .sel-selecao .band{width:24px;height:16px;border-radius:3px;box-shadow:0 0 0 1px rgba(255,255,255,.16)}
.tj-perdida .tj-clube b{color:var(--text2)}
.tj-titulo{background:var(--red-soft)}
.tj-agora{background:var(--panel2)}
.tj-agora .tj-clube em{color:var(--text2)}

/* Abaixo de 380px o Reb sai: quatro colunas de número espremiam o nome do
   clube até virar reticências, e o clube é o que dá sentido à linha. */
/* nth-of-type conta TODOS os spans da linha, não só os .tj-n: idade,
   clube e ovr vêm antes, então Reb é o 6º. Some quando a caixa aperta —
   o nome do clube é o que dá sentido à linha, quatro colunas de número
   não são. */
@container (max-width:370px){
  .tj > .tj-n:nth-of-type(6){display:none}
  .tj{gap:5px;padding-left:8px;padding-right:8px}
}
/* Navegador sem container query: pelo menos o celular estreito continua
   legível. */
@supports not (container-type:inline-size){
  @media(max-width:379px){
    .tj > .tj-n:nth-of-type(6){display:none}
    .tj{gap:5px;padding-left:8px;padding-right:8px}
  }
}

/* ═══ JANELA DE TRANSFERÊNCIAS ═══════════════════════════════════════
   Cartões lado a lado, do mesmo tamanho: nenhuma proposta é "a certa", e
   dar destaque visual a uma delas já seria escolher pela pessoa. */
.ofertas-grade{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:11px;margin-top:4px}
.oferta-card{display:flex;flex-direction:column;align-items:center;gap:6px;text-align:center;
  background:var(--panel2);border:1px solid var(--border2);border-radius:14px;padding:16px 13px;
  color:var(--text);font-family:var(--font);cursor:pointer;transition:.15s}
.oferta-card:hover{border-color:var(--border2);background:var(--panel3)}
.oferta-topo{font-size:9px;font-weight:800;letter-spacing:1.2px;text-transform:uppercase;color:var(--text3)}
.oferta-time{font-size:15px;font-weight:800;letter-spacing:-.2px;line-height:1.2}
.oferta-marca{margin:4px 0}
.oferta-liga{font-size:10px;font-weight:700;color:var(--text2);letter-spacing:.3px}
.oferta-num{font-family:var(--num);font-size:19px;font-weight:900;color:var(--red);letter-spacing:-.5px;
  display:flex;flex-direction:column;align-items:center;gap:2px;margin-top:2px}
.oferta-num small{font-family:var(--font);font-size:9.5px;font-weight:700;color:var(--text2);letter-spacing:.3px}
.oferta-papel{font-size:10px;font-weight:700;color:var(--text3);letter-spacing:.3px;text-transform:uppercase}
.oferta-nota{font-size:11px;font-weight:500;line-height:1.45;color:var(--text2);margin-top:2px}

/* ═══ DECISÃO EM CARTAS ══════════════════════════════════════════════
   Cartas do mesmo tamanho, uma por opção, com os desfechos listados
   embaixo. Antes eram botões de texto empilhados e a única diferença
   visível entre duas escolhas era a frase. */
.dec-tit{margin:2px 0 4px}
.dec-sub{margin-bottom:12px}
.dec-grade{display:grid;grid-template-columns:repeat(var(--cols,2),minmax(0,1fr));gap:11px}
.dec-card{display:flex;flex-direction:column;gap:9px;background:var(--panel2);
  border:1px solid var(--border2);border-radius:15px;padding:14px 13px 13px;cursor:pointer;
  font-family:var(--font);color:var(--text);text-align:center;transition:.15s}
.dec-card:hover{border-color:var(--border2);background:var(--panel3)}
.dec-card:active{transform:translateY(0)}
/* Altura de duas linhas mesmo com título de uma: sem isso, cartas de nome
   curto e longo ficam de alturas diferentes lado a lado. */
.dec-card-tit{font-size:14.5px;font-weight:800;letter-spacing:-.2px;line-height:1.25;
  min-height:2.5em;display:flex;flex-direction:column;align-items:center;justify-content:center}
.dec-card-res{display:flex;flex-direction:column;gap:5px}
.dec-res{display:flex;align-items:center;gap:6px;border-radius:9px;padding:6px 9px;
  border:1px solid;background:var(--panel3);text-align:left}
.dec-res i{font-style:normal;font-size:11px;font-weight:900;flex:none}
/* Quebra em vez de reticências: "rotação baixa" cortado no meio não diz
   nada, e uma segunda linha custa 14px. */
.dec-res em{flex:1;min-width:0;font-style:normal;font-size:11px;font-weight:700;line-height:1.25}
.dec-res b{flex:none;font-family:var(--num);font-size:11.5px;font-weight:900;
  font-variant-numeric:tabular-nums}
.dec-bom{color:var(--green);border-color:rgba(34,197,94,.3);background:var(--green-soft)}
.dec-ruim{color:var(--red);border-color:var(--red-glow);background:var(--red-soft)}
.dec-neutro{color:var(--text2);border-color:var(--border)}

/* O SORTEIO, NA PRÓPRIA CARTA
   A luz pula entre os dois desfechos da carta escolhida enquanto as outras
   saem de cena. Sem trocar de tela: o sorteio dura menos de um segundo, e
   duas navegações pra mostrar um segundo de animação tiravam a pessoa de
   onde ela estava olhando. */
.dec-fora{opacity:.25;filter:grayscale(.8);pointer-events:none;transition:.2s}
.dec-sorteando{border-color:var(--red)}
.dec-sorteando .dec-res{opacity:.4;transition:opacity .08s,transform .08s,box-shadow .08s}
.dec-res.piscando{opacity:1;box-shadow:0 0 0 2px currentColor inset;transform:scale(1.03)}
.dec-res.caiu{opacity:1;box-shadow:0 0 0 2px currentColor inset;transform:scale(1.03)}
.dec-res.apagado{opacity:.3;filter:grayscale(.6)}

/* O desfecho: uma carta só, a que foi escolhida, e o texto embaixo. */
.dec-grade-um{grid-template-columns:minmax(0,232px)}
.dec-card.dec-sorteando{cursor:default}
.dec-delta{display:block;font-family:var(--num);font-size:11.5px;font-weight:900;margin-top:3px;
  font-variant-numeric:tabular-nums}
.dec-desfecho-txt{margin:12px 0 4px;font-size:13.5px;line-height:1.55;color:var(--text2)}

/* Avançar e parar lado a lado, a partir dos 33. Empilhados, "parar por aqui"
   ficava logo abaixo do botão grande e vermelho — perto demais de um clique
   que não tem volta. Lado a lado, avançar continua sendo o maior. */
.acoes-ano{display:flex;gap:8px;align-items:stretch}
.acoes-ano .btn{flex:2;margin-top:4px}
.acoes-ano .btn2{flex:1;margin-top:4px;white-space:nowrap}
@media(max-width:440px){
  .acoes-ano{flex-direction:column;gap:6px}
  .acoes-ano .btn,.acoes-ano .btn2{flex:none}
}


/* ═══ CONQUISTAS ═════════════════════════════════════════════════════ */
.chip-btn{border:none;cursor:pointer;font-family:var(--font);transition:.15s}
.chip-btn:hover{background:var(--panel3);color:var(--amber)}
/* O botão de conquistas da tela de entrada: rótulo à esquerda, placar à
   direita. O número fica visível sem abrir — é ele que dá vontade de abrir. */
.btn-conquistas{display:flex;align-items:center;gap:9px;text-align:left}
.btn-conquistas b{margin-left:auto;font-family:var(--num);font-size:13px;font-weight:900;
  color:var(--amber);font-variant-numeric:tabular-nums}
.btn-conquistas small{font-size:10px;font-weight:700;color:var(--text3);letter-spacing:.3px}

.desafios-topo{display:flex;align-items:baseline;gap:12px;flex-wrap:wrap;margin-bottom:8px}
.desafios-conta{font-family:var(--num);font-size:13px;font-weight:800;color:var(--amber);
  font-variant-numeric:tabular-nums}
.desafios-barra{height:5px;background:var(--panel3);border-radius:99px;overflow:hidden;margin-bottom:10px}
.desafios-barra i{display:block;height:100%;background:var(--amber);border-radius:99px;transition:width .5s}
.desafios-grade{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:9px}
.desafio{display:flex;align-items:center;gap:11px;background:var(--panel);border:1px solid var(--border);
  border-radius:12px;padding:11px 12px;position:relative;min-width:0}
.desafio-icone{flex:none;width:44px;height:44px;border-radius:11px;display:flex;align-items:center;
  justify-content:center;font-size:20px;background:var(--panel3);filter:grayscale(1);opacity:.45}
.desafio-txt{flex:1;min-width:0;display:flex;flex-direction:column;gap:2px}
.desafio-txt b{font-size:13.5px;font-weight:800;letter-spacing:-.1px;color:var(--text2)}
.desafio-txt small{font-size:11px;font-weight:500;line-height:1.4;color:var(--text3)}
.desafio.feito{border-color:var(--border2);background:var(--panel2)}
.desafio.feito .desafio-icone{filter:none;opacity:1;
  background:linear-gradient(150deg,var(--amber),var(--red))}
/* A data fica no canto: o título precisa parar antes dela, senão passa por
   baixo do selo em card estreito. */
.desafio.feito .desafio-txt b{color:var(--text);padding-right:62px}
.desafio.feito .desafio-txt small{color:var(--text2)}
.desafio-data{position:absolute;top:7px;right:9px;font-size:9px;font-weight:700;letter-spacing:.3px;
  color:var(--text3);background:var(--panel3);border-radius:99px;padding:2px 7px;font-family:var(--num)}

/* Nível e prêmio no pé do card. A cor do nível é a mesma escada dos
   troféus: verde é o que dá pra buscar hoje, roxo é o que talvez nunca. */
.desafio-pe{display:flex;align-items:center;gap:6px;margin-top:5px;flex-wrap:wrap}
.desafio-nivel{font-style:normal;font-size:8.5px;font-weight:800;letter-spacing:.8px;
  text-transform:uppercase;border-radius:99px;padding:2px 7px;border:1px solid}
.desafio-moeda{font-style:normal;font-family:var(--num);font-size:10px;font-weight:800;
  color:var(--amber);font-variant-numeric:tabular-nums}
.nv-facil      .desafio-nivel{color:#22c55e;border-color:rgba(34,197,94,.3);background:rgba(34,197,94,.1)}
.nv-medio      .desafio-nivel{color:#3b82f6;border-color:rgba(59,130,246,.3);background:rgba(59,130,246,.1)}
.nv-dificil    .desafio-nivel{color:var(--amber);border-color:rgba(245,158,11,.32);background:var(--amber-soft)}
.nv-impossivel .desafio-nivel{color:#a855f7;border-color:rgba(168,85,247,.35);background:rgba(168,85,247,.12)}
.nv-impossivel.feito .desafio-icone{background:linear-gradient(150deg,#a855f7,#4c1d95)}
.nv-lendario .desafio-nivel{color:#e0b341;border-color:rgba(224,179,65,.4);background:rgba(224,179,65,.12)}
.nv-lendario .desafio-moeda{color:#e0b341}
.nv-lendario.feito .desafio-icone{background:linear-gradient(150deg,#e0b341,#7a5a10)}
.saldo-fba{color:#e0b341}

.desafios-saldo{display:flex;gap:10px;margin-bottom:12px;flex-wrap:wrap}
.desafios-saldo span{flex:1;min-width:120px;background:var(--panel);border:1px solid var(--border);
  border-radius:11px;padding:9px 12px;font-size:9px;font-weight:800;letter-spacing:.9px;
  text-transform:uppercase;color:var(--text3);display:flex;flex-direction:column;gap:2px}
.desafios-saldo b{font-family:var(--num);font-size:19px;font-weight:900;color:var(--amber);
  letter-spacing:-.5px;font-variant-numeric:tabular-nums}
.conquista-moeda{margin-left:auto;font-style:normal;font-family:var(--num);font-size:12px;
  font-weight:900;color:var(--amber)}
.conquista-aviso{border-color:rgba(245,158,11,.4)!important;background:var(--amber-soft)!important}
.conquista-linha{display:flex;align-items:center;gap:9px;padding:3px 0;font-size:13px}
.conquista-linha span{font-size:18px;line-height:1}
.conquista-linha b{font-weight:800;color:var(--text)}
@media(max-width:520px){
  .desafios-grade{grid-template-columns:1fr}
  .desafio-data{position:static;align-self:flex-start;margin-left:auto}
}
/* VOCÊ × ELE — duas colunas espelhadas, o rótulo no meio. */
.rv-linha{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:3px 0}
.rv-n{font-family:var(--num);font-size:13.5px;font-weight:700;font-variant-numeric:tabular-nums;
  color:var(--text);flex:none;width:44px}
.rv-linha .rv-n:last-child{text-align:right}
.rv-rot{flex:1;text-align:center;font-size:9px;font-weight:700;letter-spacing:1px;
  text-transform:uppercase;color:var(--text2)}

/* Dinheiro e prazo juntos: é o pacote que se compara, não cada metade. */
.proposta{display:inline-block;font-family:var(--num);font-size:12.5px;font-weight:800;
  color:var(--red);background:var(--red-soft);border:1px solid var(--red-glow);
  border-radius:20px;padding:1px 9px;margin-left:6px;white-space:nowrap}

/* A etiqueta de probabilidade quer ser lida como número, não como frase. */
.op small.odds{font-family:var(--num);font-size:10.5px;font-weight:700;letter-spacing:.2px;color:var(--text2)}
@media (prefers-reduced-motion: reduce){*{transition:none!important;animation:none!important}}
/* Os troféus da carreira, acima da lista ano a ano. */
.trofeus-resumo{display:flex;flex-wrap:wrap;gap:5px;margin-bottom:10px}

/* ── POPUP DAS FINAIS ────────────────────────────────────────────────── */
.modal-fundo{position:fixed;inset:0;background:rgba(6,6,9,.82);backdrop-filter:blur(3px);
  z-index:80;display:flex;align-items:center;justify-content:center;padding:16px}
.modal-cx{background:var(--panel);border:1px solid var(--border);border-radius:16px;
  width:min(440px,100%);max-height:88vh;overflow-y:auto;padding:18px}
.fin-cab{text-align:center;margin-bottom:14px}
.fin-tit{display:block;font-size:19px;font-weight:900;letter-spacing:-.5px}
.fin-cab small{color:var(--text2);font-size:11.5px;font-weight:600}
.fin-placar{display:flex;align-items:center;justify-content:center;gap:12px;margin-bottom:14px}
.fin-placar b{font-family:var(--num);font-size:32px;font-weight:900;color:var(--text3);
  font-variant-numeric:tabular-nums;line-height:1}
.fin-placar b.na-frente{color:var(--text)}
.fin-placar span{color:var(--text3);font-size:17px}
.fin-vazio{text-align:center;color:var(--text2);font-size:12.5px;margin:14px 0}
/* O mercado cabe mais gente que as finais: até três propostas lado a lado. */
/* ── AS OPÇÕES DE UMA DECISÃO ─────────────────────────────────────────
   Lado a lado, todas à vista. Já foi carrossel: ficava bonito e cobrava dois
   cliques pra ver o que cabe na tela sem clique nenhum. Quando não cabem —
   celular, ou muitas opções —, o próprio grid empilha. */
.carr{max-width:100%;margin-top:6px}
.carr-pista{display:grid;grid-template-columns:repeat(var(--cols,2),minmax(0,1fr));gap:8px;
  align-items:stretch}
.carr-pista > *{min-width:0}
@media (max-width:560px){
  /* Continua em LINHA, so mais apertada. Empilhar era o que fazia a terceira
     porta cair pra fora da dobra — e comparar duas coisas empilhadas custa
     uma rolagem que a decisao nao deveria cobrar. */
  .carr-pista{gap:6px}
}

/* ── JANELA DE TRANSFERÊNCIAS ────────────────────────────────────────── */
.ofertas-lista{display:flex;flex-direction:column;gap:8px;margin-top:8px}
/* A carta da oferta: escudo em cima, nome e liga embaixo — a mesma do
   Copero. Empilhada (celular), ela deita pra ocupar menos altura. */
.oferta-linha{display:flex;flex-direction:column;align-items:center;gap:8px;width:100%;
  background:var(--panel2);border:1px solid var(--border);border-radius:13px;padding:13px 10px;
  text-align:center;cursor:pointer;color:var(--text);font-family:var(--font);transition:.16s}
.oferta-linha:hover{border-color:var(--red);background:var(--panel3)}
.ol-marca{flex:none;display:flex}
.ol-txt{min-width:0;width:100%}
.ol-txt b{display:block;font-size:13.5px;font-weight:900;letter-spacing:-.2px;
  white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.ol-txt small{display:block;font-size:10px;color:var(--text3);margin-top:3px;font-weight:700;
  white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
@media (max-width:560px){
  /* Em pe, nao deitada: deitada (escudo ao lado do nome) so cabia uma
     proposta por linha, e agora sao ate tres lado a lado. */
  .oferta-linha{gap:6px;padding:10px 6px;border-radius:12px}
  .ol-txt b{font-size:12px}
  .ol-txt small{font-size:9px;margin-top:2px}
}
.op-parar{margin-top:10px;border-style:dashed;color:var(--text2)}
.op-parar:hover{border-color:var(--red);color:var(--text)}

/* ── CONVITES (pra onde ir) ──────────────────────────────────────────── */
.conv-lista{display:flex;flex-direction:column;gap:8px}
.conv{display:flex;align-items:flex-start;gap:11px;width:100%;background:var(--panel2);
  border:1px solid var(--border);border-radius:13px;padding:12px 13px;text-align:left;
  cursor:pointer;color:var(--text);font-family:var(--font);transition:.16s}
.conv:hover{border-color:var(--red);background:var(--panel3)}
.conv-ico{flex:none;width:34px;height:34px;border-radius:10px;display:grid;place-items:center;
  background:var(--panel3);color:var(--text2);font-size:15px}
.conv.univ .conv-ico{background:var(--blue-soft);color:var(--blue)}
.conv.pro .conv-ico{background:var(--amber-soft);color:var(--amber)}
.conv.casa .conv-ico{background:var(--green-soft);color:var(--green)}
.conv-txt{flex:1;min-width:0}
.conv-txt b{display:block;font-size:14px;font-weight:900;letter-spacing:-.1px}
.conv-tag{display:inline-block;font-size:8.5px;font-weight:800;letter-spacing:.7px;
  text-transform:uppercase;color:var(--text3);margin:2px 0 3px}
.conv.casa .conv-tag{color:var(--green)}
.conv-txt small{display:block;font-size:10.5px;color:var(--text2);line-height:1.45}

/* ── POPUP DO DRAFT ──────────────────────────────────────────────────── */
/* overflow-x contido: o número crava com um scale(1.28) que, num celular,
   passaria da borda da tela e criaria rolagem lateral por meio segundo. */
.modal-draft{width:min(400px,100%);text-align:center;overflow-x:clip}
.dr-eyebrow{font-size:9.5px;font-weight:800;letter-spacing:1.4px;text-transform:uppercase;color:var(--text3)}
.dr-num{font-family:var(--num);font-size:74px;font-weight:900;line-height:1;letter-spacing:-4px;
  font-variant-numeric:tabular-nums;margin:6px 0 2px}
.dr-num.rolando{color:var(--text3)}
.dr-num.parou{color:var(--red);animation:drCravou .5s ease-out}
@keyframes drCravou{0%{transform:scale(1.28);filter:blur(5px)}60%{transform:scale(.97);filter:blur(0)}100%{transform:scale(1)}}
.dr-time{opacity:0;animation:drEntra .45s ease-out .12s forwards}
@keyframes drEntra{from{opacity:0;transform:translateY(9px)}to{opacity:1;transform:none}}
.dr-time b{display:block;font-size:19px;font-weight:900;letter-spacing:-.5px;margin-top:8px}
.dr-time span{display:block;font-size:11.5px;color:var(--text2);margin-top:3px}
.dr-espera{font-size:12.5px;color:var(--text2);min-height:18px;margin-top:4px}
.dr-ficha{display:block;font-size:11px;color:var(--text3);margin-top:9px;line-height:1.45}
.dr-ficha b{color:var(--red);font-weight:800}
@media (max-width:520px){.dr-num{font-size:60px;letter-spacing:-3px}.dr-time b{font-size:17px}}

/* ── RESUMO DE FIM — o molde do Copero ───────────────────────────────── */
.fim-topo{text-align:center}
.fim-topo .acoes-fim{display:flex;flex-wrap:wrap;gap:8px;justify-content:center}
.fim-topo .btn{width:auto;flex:1 1 168px;margin-top:0;padding:12px 18px;font-size:13.5px}
.resumo-topo{display:grid;grid-template-columns:1.55fr 1fr;gap:11px}
.fim-ident{display:flex;align-items:center;gap:13px;margin-bottom:13px}
.fim-nome{flex:1;min-width:0}
.fim-nome b{display:block;font-size:24px;font-weight:900;letter-spacing:-.8px;
  white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.fim-tags{display:flex;flex-wrap:wrap;gap:6px;margin-top:6px}
.tagx{display:inline-flex;align-items:center;gap:4px;background:var(--panel3);border-radius:6px;
  padding:3px 8px;font-size:10.5px;font-weight:800;color:var(--text2)}
.tagx.pos{background:var(--red-soft);color:var(--red)}
.fim-ovr{width:66px;height:66px;border-radius:14px;color:#0a0a0c;flex:none;
  background:linear-gradient(160deg,color-mix(in srgb,var(--cor) 74%,#fff),var(--cor));
  display:flex;flex-direction:column;align-items:center;justify-content:center}
.fim-ovr small{font-size:8px;font-weight:800;letter-spacing:1px;opacity:.7}
.fim-ovr b{font-size:27px;font-weight:900;line-height:1;letter-spacing:-1.5px;font-family:var(--num)}
.fim-nums{display:grid;grid-template-columns:repeat(3,1fr);border-top:1px solid var(--border);padding-top:11px}
.fim-nums div{text-align:center}
.fim-nums span{display:block;font-size:8.5px;font-weight:800;letter-spacing:.6px;
  text-transform:uppercase;color:var(--text3)}
.fim-nums b{font-size:19px;font-weight:900;font-family:var(--num)}
.fim-legado{font-size:38px;font-weight:900;letter-spacing:-2px;line-height:1;margin:6px 0 8px;
  font-family:var(--num);color:var(--red)}
.fim-moedas{margin-top:10px;display:inline-block;background:var(--amber-soft);color:var(--amber);
  border-radius:8px;padding:4px 11px;font-size:12px;font-weight:800}
.clubes-grade{display:grid;grid-template-columns:repeat(auto-fill,minmax(148px,1fr));gap:10px;margin:12px 0}
.clube-card{background:var(--panel);border:1px solid var(--border);border-radius:14px;padding:13px 11px;
  display:flex;flex-direction:column;align-items:center;gap:7px;text-align:center}
.clube-card b{font-size:12.5px;font-weight:800;line-height:1.25}
.clube-card .cc-nums{display:grid;grid-template-columns:repeat(3,1fr);gap:4px;font-size:11.5px;
  width:100%;font-family:var(--num)}
.clube-card .cc-nums span{display:block;font-size:8px;color:var(--text3);font-weight:800;
  letter-spacing:.5px;text-transform:uppercase;font-family:var(--font)}
@media (max-width:640px){ .resumo-topo{grid-template-columns:1fr} }

/* ── SALA DE TROFÉUS — a mesma do Copero ─────────────────────────────── */
.sala{padding:15px 16px}
.sala-cab{display:flex;align-items:center;gap:9px;font-size:9.5px;font-weight:800;letter-spacing:1px;
  color:var(--text3);text-transform:uppercase;margin-bottom:13px}
.sala-cab b{background:var(--panel3);color:var(--text);border-radius:6px;padding:2px 8px;font-size:12px;
  font-family:var(--num);font-variant-numeric:tabular-nums}
.sala-grade{display:grid;grid-template-columns:repeat(auto-fill,minmax(86px,1fr));gap:14px 8px}
.sala-item{display:flex;flex-direction:column;align-items:center;gap:6px;text-align:center;min-width:0}
.sala-item span{font-size:10px;font-weight:700;color:var(--text2);line-height:1.25}
.sala-taca{position:relative;display:inline-flex}
.sala-taca i{position:absolute;right:-7px;bottom:-2px;background:var(--panel3);border-radius:6px;
  padding:1px 5px;font-size:9.5px;font-weight:900;font-style:normal;color:var(--text);
  font-family:var(--num)}
.taca-nba{filter:drop-shadow(0 2px 4px rgba(0,0,0,.5))}

/* Os dois fecham a carreira juntos: copiar pro grupo e recomeçar são
   irmãos, não um acima do outro. No celular voltam a empilhar. */
.acoes-fim{display:flex;gap:9px;margin-top:4px}
.acoes-fim .btn{margin-top:0;flex:1}
@media (max-width:520px){ .acoes-fim{flex-direction:column;gap:8px} }

/* RANKING — só existe na versão do site */
.rk{display:flex;align-items:center;gap:10px;padding:9px 0;border-bottom:1px solid var(--border);font-size:12.5px}
.rk:last-child{border-bottom:none}
.rk-pos{font-family:var(--num);font-size:12px;font-weight:700;color:var(--text3);width:24px;flex:none}
.rk.eu .rk-pos,.rk.eu .rk-gm{color:var(--red)}
.rk-gm{flex:1;min-width:0;font-weight:700;color:var(--text);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.rk-gm small{display:block;font-weight:500;font-size:10.5px;color:var(--text2)}
.rk-pts{font-family:var(--num);font-weight:900;color:var(--amber);font-variant-numeric:tabular-nums}
</style>
</head>
<body>
<div id="app"></div>

<script>
// Impresso pelo PHP: a primeira tela não espera requisição nenhuma.
window.__CARREIRA__  = <?= $estadoInicial ?: 'null' ?>;
window.__TIMES_FBA__ = <?= json_encode($timesFba, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
window.__TIMES_NBA__ = <?= json_encode($timesNba, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
window.__RANKING__   = <?= json_encode(array_map(fn($r) => [
    'gm' => $r['gm'], 'nome' => $r['nome'], 'pos' => $r['posicao'],
    'legado' => (int)$r['legado'], 'temporadas' => (int)$r['temporadas'],
], $ranking), JSON_UNESCAPED_UNICODE) ?>;
window.__EU__     = <?= json_encode($_SESSION['user_name'] ?? '', JSON_UNESCAPED_UNICODE) ?>;
window.__ULTIMO_NOME__ = <?= json_encode($ultimoNome, JSON_UNESCAPED_UNICODE) ?>;
window.__MOEDAS__ = <?= $pontosUsuario ?>;
window.__DESAFIOS__ = <?= json_encode((object)$desafiosFeitos, JSON_UNESCAPED_UNICODE) ?>;
// Nível e prêmio vêm do servidor: é lá que a moeda é creditada, e a tela
// não pode prometer um número diferente do que vai ser pago.
window.__NIVEL_DO_DESAFIO__ = <?= json_encode(CAMINHO_DESAFIOS) ?>;
window.__FBA_DO_NIVEL__   = <?= json_encode(CAMINHO_FBA) ?>;
window.__MOEDA_DO_NIVEL__   = <?= json_encode(CAMINHO_NIVEIS) ?>;
</script>
<script>
// ═══════════════════════════════════════════════════════════════════════
// DADOS — jogadores e times reais. Os elencos são sorteados sem lógica de
// mercado, como combinado: importa que os nomes existam, não que façam
// sentido no time em que caíram.
// ═══════════════════════════════════════════════════════════════════════
const ATLETAS = {"PG":[["Tyrese Haliburton",96],["Russell Westbrook",94],["Kyle Lowry",94],["Cade Cunningham",94],["Luka Doncic",94],["Rajon Rondo",94],["Tyrese Maxey",93],["Scoot Henderson",92],["Jordan Farmar",92],["LaMelo Ball",92],["Derrick Rose",92],["Jeremiah Fears",92],["Victor Oladipo",92],["Jalen Brunson",91],["Trae Young",91],["Chris Paul",91],["Ja Morant",90],["Damian Lillard",90],["Stephen Curry",90],["Jamal Murray",89],["Dejounte Murray",89],["De'Aaron Fox",89],["Darius Garland",88],["Jrue Holiday",88],["Fred VanVleet",87],["Coby White",87],["Immanuel Quickley",86],["Tyus Jones",86],["Mike Conley",85],["Reed Sheppard",85],["Dennis Schroder",85],["Malcolm Brogdon",84],["Collin Sexton",84],["Monte Morris",83],["Cole Anthony",83],["Davion Mitchell",82],["Payton Pritchard",82],["T.J. McConnell",81],["Jose Alvarado",80],["Isaiah Thomas",80]],
"SG":[["VJ Edgecombe",97],["Anthony Edwards",95],["Devin Booker",94],["Jalen Green",93],["Donovan Mitchell",93],["Bradley Beal",92],["Zach LaVine",92],["Klay Thompson",92],["Austin Reaves",91],["Jaylen Brown",91],["Ray Allen",91],["Manu Ginobili",90],["Anfernee Simons",90],["CJ McCollum",89],["Tyler Herro",89],["Desmond Bane",89],["Bogdan Bogdanovic",88],["Norman Powell",88],["Malik Monk",87],["Buddy Hield",87],["Josh Giddey",87],["Cam Thomas",86],["Bennedict Mathurin",86],["Gary Trent Jr.",85],["Jordan Poole",85],["Keegan Murray",85],["Alex Caruso",84],["Gradey Dick",84],["Max Strus",83],["Duncan Robinson",83],["Luke Kennard",82],["Donte DiVincenzo",82],["Kentavious Caldwell-Pope",81],["Grayson Allen",81],["Sam Hauser",80],["Corey Kispert",80],["Jaden Ivey",80],["Shaedon Sharpe",80],["Ochai Agbaji",79],["Quentin Grimes",79]],
"SF":[["LeBron James",97],["Kevin Durant",95],["Jayson Tatum",95],["Kawhi Leonard",94],["Scottie Barnes",93],["Jimmy Butler",93],["Paul George",92],["Franz Wagner",92],["Brandon Ingram",91],["DeMar DeRozan",91],["Mikal Bridges",90],["Amen Thompson",90],["OG Anunoby",90],["Jalen Williams",89],["Michael Porter Jr.",89],["Andrew Wiggins",88],["RJ Barrett",88],["Deni Avdija",87],["Kyle Kuzma",87],["Harrison Barnes",86],["Cam Whitmore",86],["Jabari Smith Jr.",85],["Herbert Jones",85],["Aaron Gordon",85],["Dorian Finney-Smith",84],["Trey Murphy III",84],["Jaden McDaniels",83],["Peyton Watson",83],["Keyonte George",82],["Ausar Thompson",82],["Toumani Camara",81],["Christian Braun",81],["Cason Wallace",80],["Jonathan Kuminga",80],["Nicolas Batum",79],["Kelly Oubre Jr.",79],["Bruce Brown",78],["Josh Hart",78],["Naji Marshall",77],["Royce O'Neale",77]],
"PF":[["Victor Wembanyama",98],["Cooper Flagg",97],["Charles Barkley",96],["Giannis Antetokounmpo",95],["Anthony Davis",94],["Paolo Banchero",93],["Evan Mobley",92],["Julius Randle",91],["Pascal Siakam",91],["Lauri Markkanen",90],["Chet Holmgren",90],["Karl-Anthony Towns",90],["Jaren Jackson Jr.",89],["Draymond Green",89],["John Collins",88],["Jerami Grant",88],["Tobias Harris",87],["Bobby Portis",87],["P.J. Washington",86],["Kris Murray",85],["Grant Williams",85],["Obi Toppin",84],["Santi Aldama",84],["Jalen Johnson",84],["Kevin Love",83],["Zion Williamson",83],["Rui Hachimura",82],["Precious Achiuwa",82],["Jabari Walker",81],["Taylor Hendricks",81],["Marvin Bagley III",80],["Isaiah Jackson",80],["Trayce Jackson-Davis",79],["Jeremy Sochan",79],["Dean Wade",78],["Xavier Tillman",78],["JT Thor",77],["Drew Eubanks",77],["Robert Covington",76],["Marcus Morris",76]],
"C":[["Nikola Jokic",96],["Joel Embiid",95],["Bam Adebayo",92],["Alperen Sengun",92],["Domantas Sabonis",91],["Rudy Gobert",90],["Jarrett Allen",89],["Deandre Ayton",88],["Myles Turner",88],["Brook Lopez",87],["Clint Capela",86],["Jusuf Nurkic",86],["Onyeka Okongwu",85],["Nic Claxton",85],["Walker Kessler",85],["Ivica Zubac",84],["Daniel Gafford",84],["Jakob Poeltl",83],["Isaiah Hartenstein",83],["Naz Reid",83],["Mark Williams",82],["Jalen Duren",82],["Robert Williams III",82],["Wendell Carter Jr.",81],["Nick Richards",81],["Andre Drummond",81],["Zach Edey",81],["Donovan Clingan",80],["Nikola Vucevic",80],["Yves Missi",80],["Mitchell Robinson",80],["Greg Oden",80],["Steven Adams",79],["Jay Huff",79],["Day'Ron Sharpe",78],["Moritz Wagner",78],["Bismack Biyombo",77],["Thomas Bryant",77],["Kevon Looney",76],["Jericho Sims",76]]};

// Reserva: no site, caminho.php injeta os times de verdade com o logo
// que cada um cadastrou. [nome curto, logo, GM]
const FBA_TIMES = [["Envood","","Caio Fonseca"],["Olympics","","Kleberson Costa"],["Panthers","","Ian Barbosa"],["Blackouts","","Pedro Fava"],["Mooses","","Pedro Cardoso"],["Dope","","Thales Gonzalez"],["Frostborn","","Matheus Muniz"],["Blues","","Mateus Maia"],["Guerreros","","Remerson Barboza"],["Heatwave","","Guilherme Faleiro"],["Parfums","","Kenderson Freitas"],["Swifties","","Caio Gomes"],["Cavalinhos","","Leonardo Cardoso"],["Celestials","","Lázaro Resende"],["Souks","","Henrick Taufner"],["Shuffle","","Gabriel Matos"],["Catrinas","","Jose Cortez"],["Sunsets","","Caio Motta"],["Beezz","","Vinicius Rocha"],["Reapers","","Lucas Rodrigues"],["Mafia","","Murilo Toledo"],["Blue Foxes","","Bruno Coelho"],["Gunslingers","","Lucas Monteiro"],["Puddles","","Daniel Dias"],["Black Lions","","Anderson Silva"],["Devils","","Matheus Sampaio"],["Phantoms","","Ágata Máximo"],["Vultures","","Kevyn Martins"],["JoyBoys","","Yan Simão"],["Carpinteros","","Lennon Herman"],["Archers","","Eduardo Antunes"],["Peacemakers","","Victor Simoes"]];

// Os 30 times, com o nome curto que todo mundo usa ("Lakers", não
// "Los Angeles Lakers") e o logo direto do cdn.nba.com — mesma fonte
// que o resto do site já usa, sem hospedar imagem nenhuma.
const NBA = [["Celtics","https://cdn.nba.com/logos/nba/1610612738/global/L/logo.svg"],["Nets","https://cdn.nba.com/logos/nba/1610612751/global/L/logo.svg"],["Knicks","https://cdn.nba.com/logos/nba/1610612752/global/L/logo.svg"],["76ers","https://cdn.nba.com/logos/nba/1610612755/global/L/logo.svg"],["Raptors","https://cdn.nba.com/logos/nba/1610612761/global/L/logo.svg"],["Bulls","https://cdn.nba.com/logos/nba/1610612741/global/L/logo.svg"],["Cavaliers","https://cdn.nba.com/logos/nba/1610612739/global/L/logo.svg"],["Pistons","https://cdn.nba.com/logos/nba/1610612765/global/L/logo.svg"],["Pacers","https://cdn.nba.com/logos/nba/1610612754/global/L/logo.svg"],["Bucks","https://cdn.nba.com/logos/nba/1610612749/global/L/logo.svg"],["Hawks","https://cdn.nba.com/logos/nba/1610612737/global/L/logo.svg"],["Hornets","https://cdn.nba.com/logos/nba/1610612766/global/L/logo.svg"],["Heat","https://cdn.nba.com/logos/nba/1610612748/global/L/logo.svg"],["Magic","https://cdn.nba.com/logos/nba/1610612753/global/L/logo.svg"],["Wizards","https://cdn.nba.com/logos/nba/1610612764/global/L/logo.svg"],["Nuggets","https://cdn.nba.com/logos/nba/1610612743/global/L/logo.svg"],["Timberwolves","https://cdn.nba.com/logos/nba/1610612750/global/L/logo.svg"],["Thunder","https://cdn.nba.com/logos/nba/1610612760/global/L/logo.svg"],["Trail Blazers","https://cdn.nba.com/logos/nba/1610612757/global/L/logo.svg"],["Jazz","https://cdn.nba.com/logos/nba/1610612762/global/L/logo.svg"],["Warriors","https://cdn.nba.com/logos/nba/1610612744/global/L/logo.svg"],["Clippers","https://cdn.nba.com/logos/nba/1610612746/global/L/logo.svg"],["Lakers","https://cdn.nba.com/logos/nba/1610612747/global/L/logo.svg"],["Suns","https://cdn.nba.com/logos/nba/1610612756/global/L/logo.svg"],["Kings","https://cdn.nba.com/logos/nba/1610612758/global/L/logo.svg"],["Mavericks","https://cdn.nba.com/logos/nba/1610612742/global/L/logo.svg"],["Rockets","https://cdn.nba.com/logos/nba/1610612745/global/L/logo.svg"],["Grizzlies","https://cdn.nba.com/logos/nba/1610612763/global/L/logo.svg"],["Pelicans","https://cdn.nba.com/logos/nba/1610612740/global/L/logo.svg"],["Spurs","https://cdn.nba.com/logos/nba/1610612759/global/L/logo.svg"]];

// Colleges reais, cada um com uma promessa diferente — a escolha é
// "minutos agora ou vitrine depois?", não uma lista de nomes bonitos.
const COLLEGES = [
  {n:"Duke",            f:"Vitrine nacional", d:"Jogo em rede toda semana. Você aparece — se jogar.", min:-2, hype:+3, dev:+1},
  {n:"Kentucky",        f:"Fábrica de calouro",d:"Calouro joga. Muito. E sai no fim do ano.",        min:+3, hype:+2, dev:+1},
  {n:"Kansas",          f:"Tradição",         d:"Programa sério, treino duro, evolução consistente.", min:0,  hype:+1, dev:+2},
  {n:"Gonzaga",         f:"Ataque livre",     d:"Sistema que solta o arremessador. Menos holofote.",  min:+2, hype:0,  dev:+2},
  {n:"UConn",           f:"Defesa em primeiro",d:"Você vai aprender a defender. Ou vai sentar.",      min:0,  hype:+1, dev:+2},
  {n:"Baylor",          f:"Minutos garantidos",d:"Elenco curto. Você joga desde o primeiro jogo.",    min:+4, hype:-1, dev:+1},
  {n:"Michigan State",  f:"Escola de armador",d:"Melhor lugar do país pra aprender a comandar.",      min:+1, hype:0,  dev:+2},
  {n:"Arizona",         f:"Ritmo alto",       d:"Corre o tempo todo. Estatística infla, defesa não.", min:+2, hype:+1, dev:0},
  {n:"UCLA",            f:"Costa oeste",      d:"Equilíbrio entre visibilidade e desenvolvimento.",   min:+1, hype:+2, dev:+1},
  {n:"Villanova",       f:"Time acima de tudo",d:"Você ganha muito. Seus números não impressionam.",  min:0,  hype:0,  dev:+2},
];

// Caminho alternativo: quem não quer college. Cada liga tem um preço.
const LIGAS_FORA = [
  {n:"NBB (Brasil)",       d:"Perto de casa e da seleção. O olheiro americano demora a chegar.", min:+3, hype:0,  dev:+1, $:1},
  {n:"NBL (Austrália)",    d:"Liga física e profissional. Rota conhecida de quem vira pick.",     min:+2, hype:+2, dev:+2, $:2},
  {n:"CBA (China)",        d:"Paga muito bem. Ninguém desenvolve ninguém lá.",                    min:+4, hype:-1, dev:0,  $:5},
  {n:"Liga ACB (Espanha)", d:"O basquete mais tático fora da NBA. Você vai apanhar e aprender.",  min:-1, hype:+1, dev:+3, $:2},
  {n:"EuroLeague",         d:"Nível altíssimo. Calouro senta, mas quem sobrevive chega pronto.",   min:-3, hype:+2, dev:+3, $:3},
];

const NACOES = [["BRA","Brasil"],["USA","Estados Unidos"],["CAN","Canadá"],["ESP","Espanha"],["FRA","França"],["SRB","Sérvia"],["ARG","Argentina"],["GER","Alemanha"],["AUS","Austrália"],["NGR","Nigéria"],["LTU","Lituânia"],["GRE","Grécia"]];

const POSICOES = {
  PG:{n:"Armador",    d:"Comanda o jogo. Passe e drible acima de tudo.", w:{tres:.18,fin:.08,pas:.28,dri:.20,def:.10,fis:.04,iq:.06,cl:.06}},
  SG:{n:"Ala-armador",d:"Pontuador de perímetro. Arremesso e sangue frio.",w:{tres:.24,fin:.16,pas:.10,dri:.16,def:.12,fis:.06,iq:.08,cl:.08}},
  SF:{n:"Ala",        d:"Faz de tudo um pouco. O corpo mais versátil.",   w:{tres:.18,fin:.18,pas:.12,dri:.12,def:.16,fis:.12,iq:.06,cl:.06}},
  PF:{n:"Ala-pivô",   d:"Força por dentro com alcance por fora.",         w:{tres:.12,fin:.24,pas:.08,dri:.06,def:.20,fis:.22,iq:.04,cl:.04}},
  C: {n:"Pivô",       d:"Domina o garrafão. Defesa e físico mandam.",     w:{tres:.06,fin:.26,pas:.08,dri:.04,def:.26,fis:.24,iq:.04,cl:.02}},
};

const ARQUETIPOS = {
  atirador:{n:"Sharpshooter", d:"Arremesso puro. Espaça a quadra sozinho.",  forte:["tres","cl"],    cresce:"tres"},
  penetra: {n:"Slasher",      d:"Vive no garrafão. Ninguém segura em 1x1.",  forte:["fin","fis"],    cresce:"fin"},
  armador: {n:"Playmaker",    d:"Enxerga o passe antes de todo mundo.",       forte:["pas","dri","iq"],cresce:"pas"},
  marcador:{n:"Lockdown",     d:"Anula o melhor do outro time. Todo jogo.",   forte:["def","fis"],    cresce:"def"},
  garrafao:{n:"Glass Cleaner",d:"Domina o rebote e protege o aro.",           forte:["fis","def"],    cresce:"fis"},
};

/**
 * O jeito de jogar SAI da posição — não é mais uma escolha.
 *
 * Escolher os dois separadamente permitia um pivô Sharpshooter e um armador
 * Glass Cleaner, e transformava a criação num formulário de duas perguntas
 * que na prática eram uma. A posição já diz quase tudo; o que sobra de
 * incerteza vira sorteio com peso, então dois armadores não nascem iguais e
 * ninguém precisa decidir nada sobre isso.
 *
 * Os pesos somam 100 em cada posição só pra facilitar a leitura.
 */
const ARQ_DA_POSICAO = {
  PG:[["armador",55],["atirador",25],["marcador",12],["penetra",8]],
  SG:[["atirador",45],["penetra",25],["marcador",18],["armador",12]],
  SF:[["penetra",32],["atirador",26],["marcador",26],["armador",8],["garrafao",8]],
  PF:[["garrafao",38],["marcador",28],["penetra",22],["atirador",12]],
  C: [["garrafao",50],["marcador",32],["penetra",18]],
};

function sortearArquetipo(pos){
  const tab = ARQ_DA_POSICAO[pos] || ARQ_DA_POSICAO.SF;
  let r = Math.random() * tab.reduce((s,x) => s + x[1], 0);
  for (const [id, peso] of tab){ if ((r -= peso) <= 0) return id; }
  return tab[0][0];
}

const ATRIBUTOS = {tres:"3 pontos",fin:"Finalização",pas:"Passe",dri:"Drible",def:"Defesa",fis:"Físico",iq:"QI de jogo",cl:"Clutch"};

// Cortes tirados dos percentis de 200 carreiras simuladas, não do olho:
// cada faixa é a fatia que ela deve pegar. O topo tem que ser raro pra
// significar alguma coisa — se metade do grupo vira GOAT, ninguém é.
// Cortes na escala de 0 a 230, tirados dos percentis de 2500 carreiras.
// O topo é pra ser lenda urbana: 230 exige mais troféus do que apareceu em
// duas mil e quinhentas simulações.

// ═══════════════════════════════════════════════════════════════════════
// ESTADO
// ═══════════════════════════════════════════════════════════════════════
let S = null;
let salvando = false, pendente = false;

// A carreira mora no banco, não no navegador: é o que permite o ranking
// entre os GMs e o pagamento em moedas. O estado inicial vem impresso na
// página pelo PHP, então a primeira tela não espera requisição nenhuma.
//
// Só uma requisição por vez, mas nunca perde a última: se pedirem save
// enquanto outro está no ar, marca pendente e reenvia ao terminar. Sem
// isso, uma resposta lenta engoliria a jogada seguinte.
function salvar(){
  if (!S) return;
  if (salvando){ pendente = true; return; }
  salvando = true;
  fetch(location.pathname, {
    method: "POST",
    headers: {"Content-Type": "application/x-www-form-urlencoded"},
    body: "acao=salvar&estado=" + encodeURIComponent(JSON.stringify(S)),
  }).catch(()=>{}).finally(()=>{
    salvando = false;
    if (pendente){ pendente = false; salvar(); }
  });
}
function carregar(){ return window.__CARREIRA__ || null; }
function apagar(){
  window.__CARREIRA__ = null;
  fetch(location.pathname, {method:"POST", headers:{"Content-Type":"application/x-www-form-urlencoded"},
    body:"acao=abandonar"}).catch(()=>{});
}

const ri  = (a,b) => a + Math.floor(Math.random()*(b-a+1));
const pick = (a) => a[Math.floor(Math.random()*a.length)];
const clamp = (v,a,b) => Math.max(a, Math.min(b, v));
const esc = (s) => String(s).replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));



// ═══════════════════════════════════════════════════════════════════════
// MOTOR
// ═══════════════════════════════════════════════════════════════════════
function ovr(a, pos){
  const w = POSICOES[pos].w;
  let t = 0;
  for (const k in w) t += (a[k]||0) * w[k];
  return Math.round(t);
}

// O potencial é sorteado uma vez e NUNCA aparece como número. A pessoa só
// vê a faixa — é o que faz duas carreiras iguais no papel terminarem
// diferentes, e o que dá sentido a insistir num jogador que ainda não
// estourou.
function faixaPotencial(p){
  if (p >= 96) return "Talento geracional";
  if (p >= 91) return "Futura estrela";
  if (p >= 86) return "Titular de time bom";
  if (p >= 80) return "Rotação sólida";
  return "Vai ter que suar";
}

function novaCarreira(nome, pos, arq, nac, modo){
  const A = {};
  for (const k in ATRIBUTOS) A[k] = ri(30, 42);
  ARQUETIPOS[arq].forte.forEach(k => A[k] = clamp(A[k] + ri(10, 18), 0, 99));

  return {
    v:1, modo, nome, pos, arq, nac,
    // Ritmo da carreira: "normal" para uma temporada por clique, "rapido"
    // para duas. Muda quantas vezes o ano roda antes de parar — não muda a
    // simulação em si, então uma carreira rápida vale exatamente o mesmo
    // no ranking. Dá pra trocar no meio, a qualquer momento.
    ritmo:"normal",
    // Só identidade: aparecem na camisa e no cartão do fim, não entram em
    // conta nenhuma. Quem preenche é o criar(), depois desta função.
    numero:null, mao:"D",
    idade:16, ano:2026,
    // Potencial, em cinco degraus com o formato da liga de verdade.
    //
    // A régua anterior punha 45% dos jogadores em 93-99, e o resultado
    // medido foi que metade das carreiras chegava a 90+ de overall. Num
    // elenco de verdade, 93 é MVP: são dois ou três num ano inteiro, entre
    // quatrocentos e cinquenta jogadores. Ser bom tinha virado o normal, e
    // por isso não valia nada ser bom.
    //
    // Agora: 12% não passam de rotação, 40% viram titular de time médio,
    // 33% chegam a All-Star, 12% a superstar e 3% são geracionais. É a
    // pirâmide da liga, e ela é o que faz o topo significar alguma coisa.
    // 3%: o fenômeno geracional. Só aparece na noite do draft.
    prodigio: Math.random() < 0.03,
    destaque: null, comparacao: null,
    rival: null, marcasBatidas: [], anosDivisao: 0, desfecho: null, anosNoClube: 0,
    A, pot: (()=>{ const r = Math.random();
                   return r < 0.12 ? ri(68,78)      // rotação
                        : r < 0.52 ? ri(79,87)      // titular
                        : r < 0.85 ? ri(88,93)      // All-Star
                        : r < 0.97 ? ri(94,97)      // superstar
                                   : ri(98,99); })(),  // geracional
    fase:"base",            // base · college · fora · draft · liga · fim
    anoFase:0,
    college:null, ligaFora:null,
    time:null, gm:null, liga:null,
    pickDraft:null,
    hype:50,                // o quanto os olheiros te enxergam
    confianca:50,           // confiança do treinador → minutos
    dinheiro:0, salario:0, contrato:0,
    temporadas:[],
    trofeus:{mvp:0,titulo:0,fmvp:0,allstar:0,dpoy:0,mip:0,roy:0,cesta:0,euro:0,
             copaNBA:0, ouro:0, prata:0, bronze:0, ouroCopa:0, prataCopa:0, bronzeCopa:0},
    convocacoes:0,
    ultimo:null, decisaoId:null, aguardando:false, mensagem:null, resultado:null,
    finais:null, mercado:null, ofertaEscolhida:null, ovrAnterior:null, efeitoDecisao:0, decisoesUsadas:[], papel:"titular", ultimoOvr:null, picoOvr:null, picoTres:0, ultimaVit:null,
    afastado:null,          // {tipo,anos,motivo} enquanto estiver fora
    // Perder temporada é o preço mais caro do jogo, então nem toda carreira
    // chega perto dele: o sorteio aqui decide se ESTA vai encarar uma
    // encruzilhada dessas, e riscoUsado é o contador que segura o resto.
    //
    // Medido no catálogo antigo: 16% das carreiras podiam ver UMA decisão de
    // risco, e mesmo essas ainda precisavam sorteá-la no meio de 29 — na
    // prática perder uma temporada acontecia em algo como 5% das carreiras,
    // num jogo em que perder a temporada era pra ser o preço mais caro da
    // mesa. Agora 45% das carreiras podem encarar uma encruzilhada dessas, e
    // 12% dessas 45% (ou seja, 5,4% do total) podem encarar duas.
    //
    // Carreira SALVA antes desta mudança mantém o sorteio antigo de 16%: o
    // campo já está no localStorage e nada o reescreve. O portão novo só
    // vale pra carreira nova.
    riscoDaCarreira: Math.random() < 0.45, riscoUsado:0,
    riscoMax: Math.random() < 0.12 ? 2 : 1,
    // Saída forçada pendente: o evento rasgou o contrato e o mercado abre
    // assim que o desfecho sair da tela. Só o seguir() apaga.
    dispensaPendente:false,
    // Foi CORTADO (não é contrato vencido): o clube que rasgou o contrato
    // não pode aparecer oferecendo renovação na tela seguinte. Só assinar()
    // apaga.
    cortado:false,
    // O desfecho prometeu que o telefone tocou: o mercado tem que ter pelo
    // menos uma proposta da liga, custe o que custar. Só o seguir() apaga.
    resgatePendente:false,
    encerrada:false,
  };
}

// Elenco sorteado: nomes reais, sem lógica de mercado. Serve pra dar
// companhia e adversário com cara de gente.
function sorteiaCompanheiro(pos){
  const l = ATLETAS[pos] || ATLETAS.SF;
  return pick(l);
}

function forcaDoTime(){
  // Um número 0-100 que resume o elenco em volta. Não simulo 15 jogadores:
  // pro ritmo que o jogo quer, o time é UM número com ruído por ano.
  if (S.forcaBase == null) S.forcaBase = ri(35, 80);
  return clamp(S.forcaBase + ri(-8, 8), 20, 95);
}

function minutosDoAno(o){
  // Minutos saem da confiança do treinador, não só do talento — é o que
  // faz "pedir troca" e "furar o protocolo médico" doerem de verdade.
  //
  // A curva antiga dava 29 minutos a um jogador de 70 de overall — que numa
  // rotação de verdade é o nono ou décimo homem, de 12 a 15 minutos. Como
  // todo mundo jogava muito, todo mundo produzia, e por isso ninguém era
  // dispensado: a carreira de quem nunca ganhou espaço não existia no jogo.
  // Agora a reta é mais inclinada: 65 senta, 75 é rotação, 85 é titular de
  // 33 minutos.
  const base = (o - 60) * 1.15 + (S.confianca - 50) * 0.28 + 4;
  return clamp(Math.round(base + ri(-3, 3)), 3, 38);
}

function statsDoAno(o, min, forca){
  const p = POSICOES[S.pos].w;
  const uso = clamp((o - 60) / 40, 0, 1);
  const f = min / 32;
  const pts = clamp((4.5 + uso*16 + (p.tres+p.fin)*11) * f + ri(-2,2), 1.5, 34);
  const reb = clamp((1.5 + (p.fis+p.def)*13) * f + ri(-1,1), 0.5, 17);
  const ast = clamp((0.8 + p.pas*22) * f + ri(-1,1), 0.2, 13);
  const n1 = (x) => Math.round(x*10)/10;
  return {pts:n1(pts), reb:n1(reb), ast:n1(ast), min:Math.round(min), jogos: ri(0,100) < 12 ? ri(38,64) : ri(68,82)};
}

function premiosDoAno(o, st, vit, campeao){
  const out = [];
  // Os cortes acompanham a pirâmide de potencial: com 93 de overall virando
  // coisa de 13% das carreiras (e não de metade), os limiares antigos
  // apagavam os prêmios em vez de raread-los.
  const estrela = o >= 86;
  // MVP exige nível ALTO e time vencedor. É o corte que torna "ser bom em
  // time ruim" uma tragédia jogável, em vez de só um número menor.
  if (o >= 92 && vit >= 55 && ri(0,100) < 40){ out.push({t:"MVP", k:"ouro"}); S.trofeus.mvp++; }
  if (estrela && ri(0,100) < 70){ out.push({t:"All-Star", k:"normal"}); S.trofeus.allstar++; }
  // O Defensor do Ano é UM por ano, como o MVP — e ia pra 51% das carreiras
  // contra 12% do MVP, porque só olhava o atributo de defesa e ignorava o
  // resto. Na liga o prêmio vai pra estrela defensiva de time que ganha,
  // não pro reserva que marca bem: agora pede o atributo mais alto, nível
  // de titular e um time que vence.
  if (S.A.def >= 92 && o >= 84 && vit >= 45 && ri(0,100) < 30){
    out.push({t:"Defensor do Ano", k:"ouro"}); S.trofeus.dpoy++;
  }
  if (st.pts >= 26 && ri(0,100) < 26){ out.push({t:"Cestinha da liga", k:"ouro"}); S.trofeus.cesta++; }
  // A COPA NBA é disputada no meio da temporada e não depende de chegar às
  // finais: dá título a time bom que não foi campeão, que é justamente o
  // buraco que ela preenche no calendário de verdade.
  if (vit >= 44 && ri(0,100) < 14 + Math.max(0, o - 84)){
    out.push({t:"Copa NBA", k:"ouro"}); S.trofeus.copaNBA = (S.trofeus.copaNBA || 0) + 1;
  }
  if (campeao){
    out.push({t:"CAMPEÃO", k:"titulo"}); S.trofeus.titulo++;
    if (o >= 91 && ri(0,100) < 52){ out.push({t:"MVP das Finais", k:"titulo"}); S.trofeus.fmvp++; }
  }
  return out;
}

function evoluir(){
  // Guardado ANTES de mexer nos atributos: sem isso não dá pra dizer se o
  // ano somou ou tirou nível, que é o retorno que a pessoa procura.
  S.ovrAnterior = ovr(S.A, S.pos);
  const falta = S.pot - ovr(S.A, S.pos);
  let d;

  // O crescimento é proporcional ao que FALTA pro potencial, sem piso fixo.
  // Antes eu somava ri(2,6) sempre — então o jogador continuava subindo
  // depois de estourar o próprio teto, e toda carreira virava GOAT. O
  // potencial só significa alguma coisa se ele efetivamente frear.
  // Jovem sobe DEVAGAR: e o que segura o OVR do draft na faixa dos 70.
  // O ganho de verdade vem dos 24 aos 27, que e onde a carreira paga o
  // potencial alto — "vai ganhando com o tempo" em vez de chegar pronto.
  if (S.idade <= 23)      d = falta > 0 ? ri(0,1) + Math.round(falta*0.10) : 0;
  else if (S.idade <= 27) d = falta > 0 ? ri(0,2) + Math.round(falta*0.24) : 0;
  else if (S.idade <= 31) d = falta > 2 ? Math.round(falta*0.07) : ri(-1,0);
  else                    d = -ri(2, 2 + Math.floor((S.idade-31)*0.9));

  let estouro = false;
  if (S.idade <= 23 && falta > 8 && ri(0,100) < 14){ d += ri(3,6); estouro = true; }

  // O atributo do arquétipo cresce mais: é o que faz o Sharpshooter virar
  // um arremessador de verdade, e não uma etiqueta na tela de criação.
  const chave = ARQUETIPOS[S.arq].cresce;
  for (const k in S.A){
    const bonus = (k === chave) ? Math.max(1, Math.round(d*0.4)) : 0;
    S.A[k] = clamp(S.A[k] + Math.round(d * (0.6 + Math.random()*0.8)) + bonus, 25, 99);
  }
  return estouro;
}

// ═══════════════════════════════════════════════════════════════════════
// DECISÕES — cada opção é uma APOSTA DECLARADA
//
// Antes cada opção mexia em confiança, moral e hype: números que a pessoa
// não vê e não entende, então a escolha não parecia ter consequência
// nenhuma. Hoje toda moeda é visível na etiqueta do botão, e toda etiqueta
// é gerada da mesma chave que o código aplica:
//
//   ovr:      +N / -N no nível do jogador
//   pot:      -N no TETO da carreira (só desce; nada devolve)
//   time:     "melhor", "pior" ou "qualquer" — muda de time dentro da liga
//   queda:    true — cai um degrau da escada, ou sai da liga
//   dispensa: true — o clube rasga o contrato e o mercado abre agora
//   resgate:  true — acompanha a dispensa: garante proposta da liga na mesa
//   fora:     temporada(s) perdida(s) por lesão ou suspensão
//   conf:     +N / -N de crédito com o treinador (vira minutos)
//   hype:     +N / -N de mercado (vira salário e número de propostas)
//   elenco:   +N / -N na força do time em volta (vira vitórias e playoff)
//   salario:  ±N% do salário de hoje
//   grana:    ±N milhões já ganhos
//   granaPct: ±N% do dinheiro já ganho
//   contrato: +N anos de contrato
//
// E a aposta é escrita no dado, não no texto:
//
//   {l:"rótulo", chance:70, bom:{ovr:+2,txt:"deu certo"}, ruim:{ovr:-2,txt:"não deu"}}
//
// A etiqueta "70% +2 OVR · 30% −2 OVR" que aparece embaixo do botão é
// GERADA a partir desses mesmos números. É de propósito: escrever a
// probabilidade à mão em algum momento ia divergir do que o código faz.
//
// Sem `chance`, a opção é certeza. Sem `ruim`, o lado ruim é não acontecer
// nada além do texto.
// ═══════════════════════════════════════════════════════════════════════

/**
 * Move o OVR em exatamente `d` pontos.
 *
 * Os pesos de cada posição somam 1, então somar d em TODOS os atributos
 * move a média em d — é o que permite prometer "+3" na tela e cumprir. O
 * laço depois existe pro caso de algum atributo bater no teto ou no piso:
 * aí a média não anda tudo o que devia, e eu completo pelo que ainda cabe.
 */
function mexerOvr(d){
  if (!d) return 0;
  const antes = ovr(S.A, S.pos);

  // Pra CIMA o potencial vale, com três pontos de folga. Sem esse teto as
  // decisões furavam o limite que o evoluir() respeita, e 30% das
  // carreiras terminavam em 95+ — a faixa "lendário" virava lugar-comum.
  // A folga de um ponto existe pra que decidir bem renda um pouco mais do que
  // os olheiros projetaram; ela é o prêmio de acertar as apostas.
  // Pra BAIXO não há piso nenhum: decidir mal tem que doer sempre.
  const teto = d > 0 ? Math.min(99, Math.max(antes, S.pot + 2)) : 99;
  const alvo = clamp(antes + d, 25, teto);
  for (const k in S.A) S.A[k] = clamp(S.A[k] + d, 25, 99);

  const chave = ARQUETIPOS[S.arq].cresce;
  for (let volta = 0; volta < 60 && ovr(S.A, S.pos) !== alvo; volta++){
    const passo = alvo > ovr(S.A, S.pos) ? 1 : -1;
    const cabem = Object.keys(S.A).filter(k => passo > 0 ? S.A[k] < 99 : S.A[k] > 25);
    if (!cabem.length) break;
    // Sobe primeiro pelo que o arquétipo já faz bem; desce pelo resto,
    // pra que ganhar nível reforce a identidade e perder não a apague.
    const k = (passo > 0 && cabem.includes(chave)) ? chave : cabem[0];
    S.A[k] = clamp(S.A[k] + passo, 25, 99);
  }
  return ovr(S.A, S.pos) - antes;
}

/**
 * Quanto de `d` o mexerOvr consegue MESMO entregar, com o estado de agora.
 *
 * Existe por causa de uma mentira antiga: quando o jogador encosta no teto
 * (S.pot + 2), mexerOvr devolve 0 e a etiqueta continua escrita "+3 OVR".
 * A carta prometia e nada acontecia. Agora a etiqueta pergunta aqui antes
 * de escrever, e o que ela diz é o que vai acontecer.
 *
 * Pra baixo não há piso: decidir mal sempre entrega.
 */
function ovrRealizavel(d){
  if (!d || d < 0) return d || 0;
  const antes = ovr(S.A, S.pos);
  const teto = Math.min(99, Math.max(antes, (S.pot == null ? 99 : S.pot) + 2));
  return Math.max(0, Math.min(d, teto - antes));
}

/**
 * O piso do teto da carreira.
 *
 * Uma lesão feia derruba S.pot, e derrubar S.pot abaixo do OVR de hoje
 * travaria TODA carta de OVR positivo do resto da carreira em zero — que é
 * a mesma mentira de novo, agora espalhada por meio catálogo. O teto para
 * dois pontos acima de quem você é hoje: o crescimento futuro morre (o
 * evoluir() olha `pot - ovr`), mas as decisões continuam entregando o que
 * a etiqueta promete.
 */
function pisoDoPot(){ return Math.max(60, ovr(S.A, S.pos) + 2); }

/** Quanto o teto REALMENTE anda com `d`. Efeito negativo nunca sobe o teto. */
function potRealizavel(d){
  if (!d) return 0;
  const atual = S.pot == null ? 99 : S.pot;
  const piso = Math.min(atual, pisoDoPot());
  return clamp(atual + d, piso, 99) - atual;
}

/**
 * Aplica um efeito de decisão e devolve quanto o OVR andou.
 *
 * A ORDEM aqui não é decorativa. O jogo pagava tudo em overall, e por isso
 * a carreira inteira virava uma soma de +2 e −2; agora existem várias
 * moedas, e três delas MUDAM DE LUGAR (troca de time, queda de degrau,
 * dispensa). As três reescrevem confiança, elenco e salário do zero, então
 * rodam ANTES dos deltas escalares — senão um "+18 confiança" prometido na
 * etiqueta é apagado meio milissegundo depois pelo reset do clube novo.
 */
function aplicarEfeito(ef){
  const d = ef.ovr ? mexerOvr(ef.ovr) : 0;

  // Teto da carreira. Só desce, e só a lesão feia mexe aqui. Vem DEPOIS do
  // mexerOvr acima porque mexerOvr usa S.pot como limite: invertido, a
  // mesma carta derrubaria o teto e depois cobraria por ele.
  if (ef.pot) S.pot = (S.pot == null ? 99 : S.pot) + potRealizavel(ef.pot);

  // ── Os que mudam de lugar ──────────────────────────────────────────
  if (ef.time) trocarDeTime(ef.time === "melhor");
  if (ef.queda) cairDeDivisao();
  // Saída forçada: o contrato é rasgado AGORA. O mercado não abre aqui
  // porque o desfecho ainda está na tela; quem abre é o seguir().
  // `cortado` é o que impede o clube que acabou de te cortar de aparecer
  // na tela seguinte oferecendo renovação.
  if (ef.dispensa){ S.contrato = 0; S.dispensaPendente = true; S.cortado = true; }
  // Resgate: o texto do desfecho diz que o telefone tocou, então o mercado
  // é obrigado a ter uma proposta da liga. Sem isto o lado BOM da carta
  // podia cair na tela de "Acabou".
  if (ef.resgate) S.resgatePendente = true;
  if (ef.fora) afastar(ef.fora[0], ef.fora[1], ef.fora[2]);

  // ── E agora os deltas, em cima do estado novo ──────────────────────
  // Confiança: alguns desfechos mexem no crédito com o treinador sem mexer
  // no OVR. É o que faz "ficar" e "pedir troca" terem consequência mesmo
  // quando nenhum atributo muda.
  if (ef.conf) S.confianca = clamp((S.confianca || 50) + ef.conf, 5, 99);
  // Hype: o quanto o mercado te enxerga. Entra em valorDeMercado(), então
  // sai no salário e no número de propostas da próxima janela.
  if (ef.hype) S.hype = clamp((S.hype || 50) + ef.hype, 5, 99);
  // Elenco em volta: muda vitórias, playoffs e título sem tocar em você.
  if (ef.elenco) S.forcaBase = clamp((S.forcaBase == null ? 55 : S.forcaBase) + ef.elenco, 20, 95);
  // Salário, em porcentagem do de hoje. Piso de 1: $0M/ano quebraria a
  // conta do dinheiro e a régua do mercado.
  if (ef.salario) S.salario = Math.max(1, Math.round(S.salario * (1 + ef.salario/100)));
  // Dinheiro já ganho, em milhões. Nunca fica negativo.
  if (ef.grana) S.dinheiro = Math.max(0, Math.round(S.dinheiro + ef.grana));
  // Dinheiro já ganho, em PORCENTAGEM. É a chave do rombo do contador: o
  // texto fala em 35% do acumulado, e é 35% do acumulado que sai daqui.
  if (ef.granaPct) S.dinheiro = Math.max(0, Math.round(S.dinheiro * (1 + ef.granaPct/100)));
  // Anos de contrato. Adiar o mercado é um prêmio de verdade pra quem está
  // em queda — é a tela mais perigosa do jogo.
  if (ef.contrato) S.contrato = Math.max(0, S.contrato + ef.contrato);

  return d;
}

/**
 * Um degrau abaixo, sem passar pelo mercado.
 *
 * A ESCADA_FBA só subia (tentarSubirDivisao), e sair da liga só acontecia
 * assinando com clube de fora na janela — ou seja, o mundo tinha uma
 * descida e nenhum evento a alcançava. Na FBA o caminho é literal:
 * ELITE → NEXT → RISE. Na NBA não existe divisão de baixo dentro da liga,
 * então cair é SAIR: G League pra quem ainda é jovem, exterior pra quem
 * não é. Nos dois casos o salário despenca — é isso que faz a queda doer
 * sem tirar um ponto de overall.
 */
function cairDeDivisao(){
  if (S.modo === "fba"){
    const i = ESCADA_FBA.indexOf(String(S.liga || "").toUpperCase());
    if (i > 0){
      S.liga = ESCADA_FBA[i - 1];
      S.anosDivisao = 0;
      S.time = pick(timesDaLiga(S.liga));
      S.anosNoClube = 0;
      S.gm = gmDoTime(S.time);
      S.forcaBase = ri(38, 70);
      S.confianca = 46;
      S.salario = Math.max(1, Math.round(S.salario * 0.55));
      return;
    }
  }
  // Já está no degrau de baixo, ou é NBA: a saída é pela porta de fora.
  const g = S.idade <= 27 ? pick(G_LEAGUE) : pick(CLUBES_GLOBAIS);
  S.foraDaLiga = true;
  S.perto = S.idade <= 27;
  S.liga = g[1];
  S.time = g[0];
  S.anosNoClube = 0;
  S.gm = null;
  S.anosDivisao = 0;
  S.forcaBase = ri(38, 68);
  S.confianca = 45;
  S.salario = Math.max(1, Math.round(S.salario * 0.4));
}

/**
 * Descreve um efeito em duas ou três palavras, pra etiqueta do botão.
 *
 * A ORDEM da lista é a ordem de importância, porque o catálogo nunca põe
 * mais de duas chaves na mesma carta: com três, o "e" no meio estoura os
 * 160px e a etiqueta vira reticências. O que pesa mais aparece primeiro.
 *
 * OVR e teto passam por ovrRealizavel/potRealizavel: se o jogador está
 * encostado no teto, a etiqueta diz "nível no teto" em vez de prometer um
 * "+3 OVR" que o motor devolveria como zero. `ovrReal`/`potReal` chegam
 * prontos quando o desfecho é redesenhado do save — aí valem os números do
 * momento em que a carta foi jogada, não os de agora.
 */
function dizEfeito(ef){
  const p = [];
  const dOvr = ef.ovrReal == null ? ovrRealizavel(ef.ovr || 0) : ef.ovrReal;
  const dPot = ef.potReal == null ? potRealizavel(ef.pot || 0) : ef.potReal;
  if (ef.fora) p.push(ef.fora[1] + (ef.fora[1] > 1 ? " temporadas fora" : " temporada fora"));
  if (ef.dispensa) p.push("fora do clube");
  if (ef.resgate) p.push("com proposta na mesa");
  if (ef.queda) p.push("cai de divisão");
  if (dOvr) p.push((dOvr > 0 ? "+" : "−") + Math.abs(dOvr) + " OVR");
  else if ((ef.ovr || 0) > 0) p.push("nível no teto");
  if (dPot) p.push((dPot > 0 ? "+" : "−") + Math.abs(dPot) + " de teto");
  // Curto de propósito: isto cabe numa carta de 160px de largura, e
  // "troca por time melhor" virava reticências antes de dizer o que era.
  if (ef.time) p.push(ef.time === "melhor" ? "time melhor"
                    : ef.time === "pior"   ? "time pior"
                    : "muda de time");
  if (ef.salario) p.push((ef.salario > 0 ? "+" : "−") + Math.abs(ef.salario) + "% de salário");
  if (ef.grana) p.push((ef.grana > 0 ? "+" : "−") + "$" + Math.abs(ef.grana) + "M");
  if (ef.granaPct) p.push((ef.granaPct > 0 ? "+" : "−") + Math.abs(ef.granaPct) + "% da grana");
  if (ef.contrato) p.push("+" + ef.contrato + (ef.contrato > 1 ? " anos" : " ano") + " de contrato");
  if (ef.elenco) p.push(ef.elenco > 0 ? "elenco reforçado" : "elenco desfalcado");
  if (ef.hype) p.push((ef.hype > 0 ? "+" : "−") + Math.abs(ef.hype) + " de mercado");
  if (ef.conf) p.push((ef.conf > 0 ? "+" : "−") + Math.abs(ef.conf) + " confiança");
  return p.join(" e ") || "nada muda";
}

/** A aposta inteira, do jeito que aparece embaixo do botão. */
function etiquetaAposta(op){
  const c = op.chance ?? 100;
  if (c >= 100) return dizEfeito(op.bom);
  return `${c}% ${dizEfeito(op.bom)} · ${100 - c}% ${dizEfeito(op.ruim || {})}`;
}

const DECISOES = [
  // ═════ 1. ONDE VOCÊ JOGA ═══════════════════════════════════════════
  //
  // Quatro decisões que mexem no ENDEREÇO da carreira, não no jogador.
  // Antes só duas faziam isso e as duas eram "pedir troca": a saída era
  // sempre um pedido seu. Agora existe a que você não pediu (crise) e o
  // degrau pra baixo (degrau), que nenhum evento alcançava.
  //
  // TODA carta que resolve em `time:` ou `queda:` checa `!s.foraDaLiga` no
  // `quando`. Sem isso, trocarDeTime() teletransportaria pra um time da
  // liga alguém que está na G League ou no exterior, e o estado ficava
  // "Lakers · fora da liga" — time de uma liga em que o jogador não está.

  {id:"clube", quando:s => s.fase === "liga" && !s.foraDaLiga && (s.contrato || 0) >= 1 && (s.anosNoClube || 0) >= 1,
   t:()=>`Você está no ${S.time} há ${S.anosNoClube || 1} ${(S.anosNoClube || 1) === 1 ? "temporada" : "temporadas"}. ${
        (S.forcaBase || 50) >= 70 ? "O elenco é forte e o projeto é claro."
      : (S.forcaBase || 50) >= 50 ? "O time é mediano e o projeto, mais ou menos."
      : "O elenco é fraco e não há sinal de reforço."} Seu empresário pergunta o que você quer.`,
   ops:[
     {l:"Ficar e brigar por aqui", chance:74,
      bom:{conf:+10, txt:"Você bateu o pé e ficou. O clube leu como lealdade — e a torcida, como identidade."},
      ruim:{conf:-6, txt:"Você ficou e o ano foi igual ao anterior. Ninguém reclamou; ninguém evoluiu."}},
     {l:"Pedir troca", chance:58,
      bom:{time:"melhor", conf:-8, txt:"O pedido vazou, o mercado se mexeu e você caiu num time melhor do que o que deixou."},
      ruim:{time:"qualquer", conf:-14, txt:"Você forçou a saída e foi parar onde deu. O vestiário novo te recebeu de braços cruzados."}},
   ]},

  // A crise financeira que te tira do clube. Ficar CUSTA — em salário — e
  // sair é certeza dos dois lados; o que o dado decide é o mercado que te
  // espera do lado de fora. O ramo bom leva `resgate`, e é ele que obriga
  // o mercado a ter proposta da liga: sem isso o desfecho dizia que três
  // GMs ligaram e a tela seguinte podia ser a de "Acabou".
  {id:"crise", quando:s => s.fase === "liga" && !s.foraDaLiga && (s.contrato || 0) >= 1 && s.salario >= 5,
   t:()=>`O dono do ${S.time} vendeu a franquia no meio da temporada e a folha não fecha. O grupo novo te dá 48 horas: ou você abre mão de parte do salário, ou eles cortam você pra caber no teto.`,
   ops:[
     {l:"Abrir mão de parte do salário", chance:62,
      bom:{salario:-25, conf:+14, txt:"Você assinou o corte de um quarto e ficou. O vestiário viu, a cidade viu, e a diretoria passou a te dever uma."},
      ruim:{salario:-35, elenco:-14, txt:"Você abriu mão de um terço e mesmo assim eles venderam os dois titulares em volta de você."}},
     {l:"Não abrir mão de nada", chance:45,
      bom:{dispensa:true, resgate:true, txt:"Rasgaram seu contrato no mesmo dia. Três GMs ligaram antes de o avião pousar."},
      ruim:{dispensa:true, hype:-14, txt:"Rasgaram seu contrato e a liga leu como jogador difícil de lidar. O telefone demorou a tocar."}},
   ]},

  {id:"troca", quando:s => s.fase === "liga" && !s.foraDaLiga && s.anoFase >= 2 && (s.forcaBase || 50) < 52,
   t:()=>`O ${S.time} perdeu cinquenta jogos e trocou os dois titulares mais velhos por escolhas de segunda rodada. Seu empresário diz que dá pra forçar uma saída antes do prazo de trocas.`,
   ops:[
     {l:"Forçar a saída antes do prazo", chance:66,
      bom:{time:"melhor", txt:"Saiu na semana do prazo. Você desembarcou num vestiário que joga por alguma coisa."},
      ruim:{time:"pior", hype:-10, txt:"Foi pro primeiro time que aceitou o salário. Pior que o anterior, e a liga leu como fuga."}},
     // Ficar deixou de ser aposta de OVR e virou aposta no ELENCO: o que
     // muda quando você banca a reconstrução não é o seu nível, é quem o
     // GM põe em volta de você — e isso aparece nas vitórias e no playoff.
     {l:"Ficar e puxar a reconstrução", chance:52,
      bom:{elenco:+16, conf:+12, txt:"Você virou a referência do projeto, e o GM gastou tudo que tinha pra te cercar de gente."},
      ruim:{elenco:-10, conf:-8, txt:"Você ficou e eles seguiram desmontando. Mais um ano seu foi embora sem nada em volta."}},
   ]},

  {id:"degrau", quando:s => s.fase === "liga" && !s.foraDaLiga && s.anoFase >= 3
     && ((s.confianca || 50) < 48 || (s.ultimo && s.ultimo.min < 18)),
   t:()=>`Você virou o décimo homem da rotação do ${S.time}. O GM foi direto: tem uma vaga de titular te esperando um degrau abaixo, ou você fica aqui esperando alguém se machucar.`,
   ops:[
     {l:"Descer e jogar 30 minutos", chance:100,
      bom:{queda:true, conf:+18, txt:"Você desceu. Ginásio menor, cheque menor, e a bola na sua mão em toda posse — a primeira vez em anos."}},
     {l:"Ficar no fim do banco", chance:40,
      bom:{conf:+16, ovr:+2, txt:"Você esperou, e a chance veio em janeiro com uma torção alheia. Nunca mais saiu do quinteto."},
      ruim:{conf:-14, hype:-14, txt:"Você ficou. Nove minutos por noite o ano inteiro, e o mercado te esqueceu."}},
   ]},

  // ═════ 2. O CORPO — a temporada que não acontece ═══════════════════
  //
  // Quatro são `risco:true` e dividem a mesma cota da carreira (riscoMax):
  // no máximo uma, ou duas em 12% das carreiras sorteadas. As outras duas —
  // lesao e carga — NÃO são de risco, e é por elas que perder um ano deixa
  // de ser privilégio de carreira sorteada e vira coisa que acontece.
  //
  // Nenhuma das quatro de risco fecha as duas portas na mesma temporada
  // perdida: em todas existe um lado que te devolve à quadra no mesmo ano,
  // custe o que custar em nível ou confiança. Perder o ano é escolha
  // declarada, não cutscene com dois botões.

  {id:"lesao", quando:s => s.fase === "liga" && s.ultimo && s.ultimo.jogos < 66,
   t:()=>`Você desfalcou o ${S.time} em ${82 - S.ultimo.jogos} jogos. O departamento médico quer cautela; o treinador quer você em quadra na quarta.`,
   ops:[
     {l:"Voltar antes da hora", chance:78,
      bom:{ovr:-1, conf:+12, txt:"Você voltou mancando e jogou assim mesmo. O vestiário te respeita — e o corpo cobrou um pedaço."},
      ruim:{fora:["lesao",1,"recaída por voltar cedo"], txt:"Você voltou antes do prazo e durou onze minutos. A recaída levou a temporada inteira."}},
     {l:"Seguir o protocolo", chance:60,
      bom:{ovr:+2, txt:"Você ficou fora até estar 100%. O treinador reclamou nos bastidores, mas você voltou inteiro — e melhor."},
      ruim:{ovr:-1, conf:-10, txt:"Você respeitou cada prazo e mesmo assim voltou diferente. Nem tudo o protocolo resolve."}},
   ]},

  // Gestão de carga era só +2/−2. Agora o lado ruim de jogar os 82 é a
  // fratura por estresse — e como ela não é risco:true, é a porta pela qual
  // qualquer veterano de 30+ pode perder um ano.
  {id:"carga", quando:s => s.fase === "liga" && s.idade >= 30,
   t:()=>`Quatro back-to-backs em duas semanas e ${S.idade} anos nas pernas. A comissão quer te poupar; o treinador quer você nos 82.`,
   ops:[
     {l:"Poupar nos back-to-backs", chance:66,
      bom:{ovr:+2, conf:-8, txt:"Você chegou aos playoffs inteiro pela primeira vez em anos. O treinador nunca engoliu."},
      ruim:{ovr:-2, conf:-12, txt:"Você poupou, perdeu ritmo, e chegou aos playoffs descansado e frio."}},
     {l:"Jogar os 82", chance:58,
      bom:{ovr:+3, conf:+12, txt:"Oitenta e dois jogos, todos eles. O corpo aguentou e o ginásio inteiro viu."},
      ruim:{fora:["lesao",1,"fratura por estresse no pé"], txt:"O pé cedeu numa bandeja banal em março. Fratura por estresse: a temporada acabou ali."}},
   ]},

  {id:"joelho", risco:true, quando:s => s.fase === "liga" && s.idade >= 24 && s.ultimo && s.ultimo.jogos < 76,
   t:()=>`O joelho travou de novo numa mudança de direção, e desta vez a ressonância veio feia. O cirurgião quer operar agora; o departamento médico ainda acha que dá pra tratar.`,
   ops:[
     {l:"Operar agora", chance:100,
      bom:{fora:["lesao",1,"cirurgia no joelho"], pot:-3, txt:"Você entrou na sala de cirurgia em outubro. A temporada acabou ali — e o joelho nunca mais foi o mesmo dos vinte e dois anos."}},
     {l:"Tratar sem cirurgia", chance:58,
      bom:{ovr:-2, txt:"Segurou. Você jogou o ano inteiro com dor e quase ninguém percebeu."},
      ruim:{fora:["lesao",2,"ruptura de ligamento"], pot:-4, txt:"Cedeu em dezembro, na frente de dezoito mil pessoas. Ruptura completa: dois anos, e um teto mais baixo pra sempre."}},
   ]},

  // O tendão tinha as duas portas terminando na mesma parede: reabilitação
  // completa era 100% de dois anos fora, e o programa acelerado perdia o
  // ano nos dois ramos. Agora o atalho pode dar certo dentro da temporada —
  // e é isso que transforma a carta numa aposta em vez de um aviso.
  {id:"tendao", risco:true, quando:s => s.fase === "liga" && s.idade >= 27,
   t:()=>`Estalou no aquecimento, sem contato. O tendão de aquiles não é dúvida, é diagnóstico. A pergunta é o que fazer com o tempo que vem.`,
   ops:[
     {l:"Reabilitação completa", chance:100,
      bom:{fora:["lesao",2,"ruptura do tendão de aquiles"], pot:-4, txt:"Você aceitou o calendário longo dos médicos: dois anos de trabalho invisível, longe de tudo."}},
     {l:"Programa acelerado", chance:45,
      bom:{ovr:-3, conf:-10, txt:"O tendão respondeu ao atalho. Você voltou na virada do ano, mais lento e inteiro."},
      ruim:{fora:["lesao",2,"aquiles · recuperação rompida"], pot:-4, txt:"O tendão não aguentou o atalho. De volta à estaca zero, e com um teto que ninguém devolve."}},
   ]},

  {id:"aterrissagem", risco:true, quando:s => s.fase === "liga" && s.anoFase >= 1 && ovr(s.A, s.pos) >= 70,
   t:()=>`Você subiu pra enterrar, o pivô adversário cortou embaixo e você caiu com o pé torto no garrafão. O ginásio ficou em silêncio. Fratura exposta no tornozelo.`,
   ops:[
     {l:"Placa e parafuso, e um ano fora", chance:100,
      bom:{fora:["lesao",1,"fratura no tornozelo"], pot:-2, txt:"Cirurgia na mesma noite. Um ano inteiro de sala de fisioterapia — e o clube segurou sua vaga."}},
     {l:"Tratamento conservador, sem placa", chance:38,
      bom:{ovr:-2, conf:+8, txt:"Consolidou sozinho e você voltou em março, mancando, com o tornozelo que era seu."},
      ruim:{fora:["lesao",2,"tornozelo · consolidação viciosa"], pot:-4, txt:"Consolidou torto. Refizeram tudo do zero em maio: dois anos, e um pé que nunca mais girou igual."}},
   ]},

  {id:"apostas", risco:true, quando:s => s.fase === "liga" && s.idade >= 26,
   t:()=>`Uma reportagem liga seu nome a apostas em jogos da liga — inclusive em quantos rebotes você pegaria. Você sabe que não apostou. Seu primo usou seu cadastro.`,
   ops:[
     {l:"Entregar tudo à investigação", chance:100,
      bom:{fora:["suspensao",1,"investigação de apostas"], grana:-6, txt:"Você abriu tudo. A liga reconheceu a colaboração e aplicou um ano, o mínimo previsto."}},
     {l:"Negar e proteger a família", chance:42,
      bom:{grana:-12, hype:-8, txt:"A investigação não chegou a você. Custou caro em advogado e o assunto morreu."},
      ruim:{fora:["suspensao",2,"apostas · omissão"], hype:-18, txt:"Encontraram os registros. Omitir custou o dobro: duas temporadas, e um nome que a liga passou a evitar."}},
   ]},

  // ═════ 3. DENTRO DO VESTIÁRIO ══════════════════════════════════════

  {id:"superstime", quando:s => s.fase === "liga" && !s.foraDaLiga && s.anoFase >= 3 && ovr(s.A, s.pos) >= 82,
   t:()=>`Um candidato ao título quer você — mas como terceira opção de ataque, não como o cara. Menos bola, mais chance de anel.`,
   ops:[
     {l:"Ir pro superteam", chance:100,
      bom:{time:"melhor", ovr:-2, txt:"Você trocou volume por chance de anel. Seus números caíram na primeira semana."}},
     {l:"Ficar sendo o cara", chance:55,
      bom:{ovr:+3, conf:+10, txt:"Você ficou, assumiu tudo e provou que o time era seu."},
      ruim:{ovr:-3, elenco:-8, txt:"Você ficou e afundou junto. A janela passou e ninguém voltou a ligar."}},
   ]},

  {id:"tecnico", quando:s => s.fase === "liga" && !s.foraDaLiga && s.anoFase >= 2,
   t:()=>`Treinador novo, sistema novo. Ele quer você fora do garrafão e arremessando de canto — que é justamente o que você não faz.`,
   ops:[
     {l:"Aprender o sistema dele", chance:58,
      bom:{ovr:+3, conf:+12, txt:"Você virou outro jogador dentro do sistema. Ninguém mais te chama de unidimensional."},
      ruim:{ovr:-3, conf:-10, txt:"Você nunca se achou naquilo. Um ano jogando errado é um ano perdido."}},
     {l:"Jogar do seu jeito", chance:50,
      bom:{ovr:+2, conf:+14, txt:"Você bateu o pé, produziu, e o treinador reescreveu o sistema em volta de você."},
      ruim:{time:"pior", txt:"O treinador ganhou o braço de ferro e pediu sua cabeça na diretoria. Você foi negociado em fevereiro."}},
   ]},

  {id:"banco", quando:s => s.fase === "liga" && !s.foraDaLiga && (s.confianca || 50) < 38,
   t:()=>`O treinador te tirou do quinteto e você descobriu pelo telão do aquecimento. A imprensa quer saber se você pediu pra sair.`,
   ops:[
     {l:"Aceitar e trabalhar calado", chance:58,
      bom:{conf:+24, txt:"Você engoliu e treinou. Em três meses ele te devolveu a vaga, e desta vez sem discussão."},
      ruim:{conf:-10, hype:-12, txt:"Você engoliu, treinou, e o ano inteiro passou no banco. O mercado só olha quem joga."}},
     {l:"Cobrar no microfone", chance:42,
      bom:{conf:+18, hype:+8, txt:"A torcida comprou sua briga e o treinador cedeu na semana seguinte."},
      ruim:{time:"pior", hype:-10, txt:"Você virou problema de vestiário e foi despachado pro primeiro time que aceitou o salário."}},
   ]},

  // ═════ 4. FORA DE QUADRA — o que se paga em dinheiro ═══════════════
  //
  // O $ da barra do topo estava lá desde o começo e nunca era cobrado por
  // nada. Estas duas são as únicas que mexem nele — e por isso são as
  // únicas em que o preço da escolha aparece num lugar diferente do OVR.

  {id:"patrocinio", quando:s => s.fase === "liga" && s.anoFase >= 2 && ovr(s.A, s.pos) >= 74,
   t:()=>`Uma marca quer seu nome numa linha de tênis. São quinze dias de agenda na pré-temporada e um cheque maior que o seu contrato.`,
   ops:[
     {l:"Assinar a linha de tênis", chance:55,
      bom:{grana:+14, hype:+10, txt:"O tênis vendeu. Você virou rosto de campanha sem perder um treino sequer."},
      ruim:{grana:+9, ovr:-2, txt:"A agenda comeu sua pré-temporada. Você começou o ano atrás de todo mundo — com o cheque no bolso."}},
     {l:"Recusar e fazer a pré-temporada", chance:64,
      bom:{ovr:+2, conf:+8, txt:"Você recusou o dinheiro e apareceu no primeiro dia de treino. O treinador não esqueceu."},
      ruim:{ovr:-1, txt:"Você recusou, treinou sozinho e não evoluiu nada. Só perdeu o cheque."}},
   ]},

  // O número do rombo sai do PRÓPRIO dinheiro, nunca de ri(): t() é
  // reavaliada a cada redesenho da tela, e um sorteio aqui faria a mesma
  // decisão mudar de valor entre um render e o seguinte.
  //
  // E o rombo é cobrado de verdade: `granaPct:-35` tira do acumulado a
  // mesma porcentagem que o texto anuncia. Antes o buraco era enunciado na
  // pergunta e nenhuma chave o descontava — a carta contava uma história
  // que o código não executava.
  {id:"contador", quando:s => s.fase === "liga" && s.idade >= 26 && (s.dinheiro || 0) >= 30,
   t:()=>`Seu contador de dez anos sumiu com $${Math.max(1, Math.round((S.dinheiro || 0) * 0.35))}M numa sociedade que nunca existiu. A Receita quer explicação e os advogados querem adiantamento.`,
   ops:[
     {l:"Processar e ir até o fim", chance:48,
      bom:{granaPct:-8, txt:"Três anos de processo e você recuperou quase tudo. Ficou pra trás o que os advogados cobraram."},
      ruim:{granaPct:-35, ovr:-2, txt:"Você perdeu o processo e o dinheiro foi junto. Passou a temporada em audiência por vídeo, de hotel em hotel."}},
     {l:"Pedir adiantamento ao clube", chance:100,
      bom:{contrato:+2, salario:-18, txt:"O clube cobriu o rombo e você assinou dois anos a mais por menos por ano. Estabilidade comprada a prazo."}},
   ]},

  // ═════ 5. A COTA DE OVR — cinco de vinte, e nada mais ══════════════
  //
  // Estas cinco são as que ainda pagam principalmente em nível. Eram
  // dezessete de vinte e nove, e era exatamente esse o problema: a carreira
  // inteira virava uma soma de +2 e −2. Sobreviveram as que não têm como
  // ser pagas em outra moeda sem virar outra coisa — e mesmo nelas três
  // ramos passaram a cobrar em confiança ou em mercado, pra que a conta por
  // desfecho fique abaixo de um terço em vez de raspar a linha.

  {id:"treino", quando:s => s.fase === "liga",
   t:()=>`Entressafra. Você tem um verão inteiro e ninguém pra mandar em você.`,
   ops:[
     {l:"Treinar pesado o verão inteiro", chance:62,
      bom:{ovr:+3, txt:"Três meses de academia às seis da manhã. Você voltou outro jogador."},
      ruim:{ovr:-3, txt:"Você exagerou na carga e chegou à pré-temporada gasto."}},
     {l:"Trabalho técnico com especialista", chance:70,
      bom:{ovr:+2, txt:"Você contratou um treinador de arremesso só seu e refinou o que já sabia fazer."},
      ruim:{ovr:-2, txt:"O especialista mexeu na sua mecânica e ela não voltou. Você passou o ano desaprendendo."}},
     {l:"Descansar de verdade", chance:40,
      bom:{ovr:+2, txt:"Você sumiu do mapa por três meses e voltou leve, inteiro e faminto."},
      ruim:{ovr:-1, txt:"Você voltou descansado e enferrujado. Levou meia temporada pra achar o ritmo."}},
   ]},

  {id:"posicao", quando:s => s.fase === "liga" && s.anoFase >= 2 && s.anoFase <= 8,
   t:()=>`A comissão acha que seu corpo pede outra posição — mais garrafão, menos perímetro. Mudar agora é reaprender o jogo aos ${S.idade}.`,
   ops:[
     {l:"Mudar de posição", chance:55,
      bom:{ovr:+4, txt:"Deu certo demais. Você achou o lugar onde sempre devia ter jogado."},
      ruim:{ovr:-3, txt:"Não era pra ser. Você passou o ano parecendo um estranho em quadra."}},
     {l:"Continuar onde está", chance:55,
      bom:{ovr:+2, txt:"Você recusou e dobrou a aposta no que já fazia bem."},
      ruim:{ovr:-1, txt:"Você recusou, e a liga seguiu andando pro lado que você não quis ir."}},
   ]},

  {id:"ultimaposse", quando:s => s.fase === "liga" && s.anoFase >= 1,
   t:()=>`Jogo decisivo, dois pontos atrás, quatro segundos, e o treinador desenhando na prancheta. A última posse é sua ou dele.`,
   ops:[
     // Errar o arremesso decisivo não custa só nível: custa o crédito que o
     // treinador te dá na posse seguinte, e é isso que a confiança guarda.
     {l:"A bola é minha", chance:50,
      bom:{ovr:+3, txt:"Você pegou a bola, subiu em cima de dois e afundou. O ginásio veio abaixo."},
      ruim:{ovr:-2, conf:-10, txt:"Você forçou por cima do trio de marcação, errou feio, e o clipe rodou o mundo até junho."}},
     {l:"Passar pro melhor posicionado", chance:70,
      bom:{ovr:+1, txt:"Você achou o companheiro livre no canto e ele converteu. Jogada certa, crédito dividido."},
      ruim:{ovr:-1, txt:"Você passou, ele errou o arremesso, e a imprensa perguntou por que você não arriscou."}},
   ]},

  {id:"veterano", quando:s => s.fase === "liga" && s.anoFase <= 4,
   t:()=>`Um veterano de 36 anos e dois anéis se ofereceu pra te ensinar a ler a defesa. Custa suas manhãs de folga.`,
   ops:[
     {l:"Aceitar a mentoria", chance:60,
      bom:{ovr:+3, conf:+8, txt:"Ele te ensinou o que nenhum treinador ensina, e o banco inteiro viu você aprender."},
      ruim:{ovr:-3, txt:"Ele quis te transformar nele. Você passou um ano jogando um basquete que não era o seu."}},
     {l:"Seguir sozinho", chance:45,
      bom:{ovr:+2, txt:"Você aprendeu apanhando, do seu jeito — e o que ficou, ficou pra sempre."},
      ruim:{ovr:-1, txt:"Você repetiu os mesmos erros o ano inteiro sem ninguém pra apontar."}},
   ]},

  // Único evento que fala com um sistema que já existe: S.rival nasce em
  // criarRival() e vive em anoDoRival(). O `s.rival` no quando protege a
  // carreira que caiu na porta dos fundos sem rival sorteado.
  {id:"rival", quando:s => s.fase === "liga" && s.anoFase >= 2 && s.rival,
   t:()=>`${S.rival ? S.rival.nome : "Um cara da sua posição"}, draftado no mesmo ano que você, virou o assunto da liga. Toda entrevista compara vocês dois.`,
   ops:[
     // Obcecar rende manchete: o jogo cai, o nome cresce. É o único lugar
     // do catálogo em que perder nível vem com mercado subindo junto.
     {l:"Comprar a rivalidade", chance:55,
      bom:{ovr:+3, txt:"A rivalidade virou o motor da carreira. Você jogava diferente contra ele — e melhor contra todo mundo."},
      ruim:{ovr:-3, hype:+6, txt:"Você se obcecou. Jogou o ano pensando na comparação, virou assunto de programa esportivo e esqueceu do seu jogo."}},
     {l:"Ignorar a comparação", chance:55,
      bom:{ovr:+2, txt:"Você não entrou na dança e seguiu no seu ritmo."},
      ruim:{ovr:-1, txt:"Você ignorou, e ele passou por cima de você em silêncio."}},
   ]},
];

// Guardo só o ID da decisão, nunca o objeto: ele tem funções dentro, e
// JSON.stringify as descarta calado. Salvo com o objeto, a carreira
// voltava do save com uma decisão sem opções e quebrava ao desenhar.
//
// Retorna null quando o ano não tem decisão — e isso é de propósito.
// Toda temporada trazer uma escolha transformava a decisão em formulário;
// um ano que passa só com os números faz o ano seguinte pesar mais.
function decisaoDoAno(){
  // TODO ANO TEM DECISÃO, como no Copero. Antes eram 60% no começo e 45%
  // depois, e nos anos sorteados de fora sobrava um botão "Próxima
  // temporada" — que não é escolha nenhuma, é um clique pra passar o tempo.
  // O jogo é sobre decidir; um ano sem decisão é um ano sem jogo.
  //
  // Se nenhuma decisão couber nas condições (todas já usadas, jogador fora
  // da liga, cota de risco esgotada), a função ainda devolve null e o botão
  // volta como saída de emergência — melhor um botão do que uma tela morta.
  const recentes = S.decisoesUsadas || [];

  // A do clube ("fica ou pede troca") entra por fora do sorteio comum e
  // divide o espaço com as outras: numas temporadas o jogo pergunta onde
  // você quer estar, noutras o que você quer fazer. Nunca duas seguidas —
  // perguntar isso todo ano transformaria a escolha em ritual.
  const clube = DECISOES.find(d => d.id === "clube");
  const clubeCabe = clube && !recentes.slice(0, 2).includes("clube")
                 && (() => { try { return clube.quando(S); } catch(e){ return false; } })();
  if (clubeCabe && Math.random() < 0.45){
    S.decisoesUsadas = ["clube", ...recentes].slice(0, 8);
    return "clube";
  }

  const cabe = d => {
    if (d.id === "clube") return false;   // já teve a vez dela acima
    // Decisão de risco só concorre na carreira sorteada pra isso, e some
    // depois que a cota acaba. Sem esse corte, três das quatro apareciam em
    // quase toda carreira e 80% delas perdiam temporada.
    //
    // riscoUsado virou CONTADOR: carreira salva no formato antigo traz
    // `false`, e (false || 0) === 0, então ela continua com direito a uma —
    // que era exatamente o comportamento antigo. Sem riscoMax no save, o
    // (S.riscoMax || 1) devolve 1.
    if (d.risco && (!S.riscoDaCarreira || (S.riscoUsado || 0) >= (S.riscoMax || 1))) return false;
    try { return d.quando(S); } catch(e){ return false; }
  };

  // Nunca repete o que saiu nas últimas 8 temporadas. Sem essa memória, as
  // mesmas duas ou três decisões voltavam ano após ano — que foi
  // exatamente o que apareceu jogando.
  const aplicaveis = DECISOES.filter(cabe);
  const ineditas = aplicaveis.filter(d => !recentes.includes(d.id));

  // Se tudo já saiu recentemente, prefere o que saiu há mais tempo em vez
  // de sortear entre repetidas.
  const candidatas = ineditas.length ? ineditas
    : aplicaveis.slice().sort((a,b) => recentes.indexOf(a.id) - recentes.indexOf(b.id));

  // Ultima linha antes de o ano ficar sem pergunta: se nada mais couber, a
  // do clube volta mesmo tendo saido ha pouco. Ela e a unica que cabe em
  // qualquer situacao de quem esta na liga, e um "Proxima temporada"
  // sozinho na tela nao e escolha nenhuma.
  if (!candidatas.length){
    const cabeAgora = clube && (() => { try { return clube.quando(S); } catch(e){ return false; } })();
    if (!cabeAgora) return null;
    S.decisoesUsadas = ["clube", ...recentes].slice(0, 8);
    return "clube";
  }
  const escolhida = ineditas.length ? pick(ineditas) : candidatas[0];

  if (escolhida.risco) S.riscoUsado = (S.riscoUsado || 0) + 1;
  S.decisoesUsadas = [escolhida.id, ...recentes].slice(0, 8);
  return escolhida.id;
}

function decisaoAtual(){
  // Sem id, o ano nao tem decisao. Cair no "treino" aqui era o que fazia
  // a mesma escolha aparecer temporada apos temporada.
  if (!S.decisaoId) return null;
  return DECISOES.find(d => d.id === S.decisaoId) || null;
}

function trocarDeTime(melhor){
  // Cinto de segurança: timesDaLiga() devolve times da NBA/FBA, e quem está
  // na G League ou no exterior não pode ser mandado pra um deles sem sair
  // do estado de fora da liga — o resultado era "Lakers · fora da liga".
  // Todo evento com efeito `time:` já checa !s.foraDaLiga no `quando`, então
  // isto nunca dispara hoje; existe pro evento que alguém escrever amanhã.
  if (S.foraDaLiga) return;
  const lista = timesDaLiga();
  let novo = S.time;
  while (novo === S.time) novo = pick(lista);
  S.time = novo;
  if (S.modo === "fba"){ S.gm = gmDoTime(novo); }
  S.forcaBase = melhor ? ri(62, 88) : ri(40, 80);
  S.confianca = 50;
  S.anosNoClube = 0;   // chegou agora; o relógio de casa recomeça
}

// ═══════════════════════════════════════════════════════════════════════
// CONTRATO E MERCADO
// O contrato acabando é o único momento em que a carreira muda de rumo
// por decisão sua. Sem isso o jogador morria no mesmo time — que foi
// exatamente o que apareceu jogando.
// ═══════════════════════════════════════════════════════════════════════

/** O que o mercado acha de você AGORA: nível, últimos números, idade. */
function valorDeMercado(){
  const o = ovr(S.A, S.pos);
  const ult = S.temporadas.filter(t=>!t.formacao).slice(-2);
  const prod = ult.length ? ult.reduce((a,t)=>a + t.pts + t.reb*0.6 + t.ast*0.8, 0)/ult.length : 10;
  let v = (o - 62) * 1.5 + prod * 0.55 + (S.hype - 50) * 0.10;
  if (S.idade >= 32) v *= 0.72;          // veterano vale menos, por mais que jogue
  if (S.idade >= 35) v *= 0.62;
  if (S.idade <= 24) v *= 1.12;          // e jovem vale mais, por menos que jogue
  return clamp(Math.round(v), 1, 58);
}

// Times de fora, por país. Quem não vinga na NBA ou envelhece recebe
// proposta daqui — e pra quem não é americano, é também o caminho de
// começar em casa em vez de encarar o draft.
const LIGAS_EUROPEIAS = ["ACB", "LNB", "Lega", "BBL", "Euroliga", "Espanha", "França", "Itália", "Alemanha", "Grécia", "Sérvia", "Turquia"];

const CLUBES_FORA = {
  BRA:[["Flamengo","NBB · Brasil"],["Franca","NBB · Brasil"],["Minas","NBB · Brasil"],["São Paulo","NBB · Brasil"]],
  ESP:[["Real Madrid","ACB · Espanha"],["Barcelona","ACB · Espanha"],["Baskonia","ACB · Espanha"]],
  FRA:[["ASVEL","LNB · França"],["Monaco","LNB · França"],["Paris Basketball","LNB · França"]],
  SRB:[["Estrela Vermelha","ABA · Sérvia"],["Partizan","ABA · Sérvia"]],
  GRE:[["Panathinaikos","Grécia"],["Olympiacos","Grécia"]],
  LTU:[["Zalgiris","Lituânia"],["Rytas","Lituânia"]],
  ARG:[["Boca Juniors","Liga Nacional · Argentina"],["Instituto","Liga Nacional · Argentina"]],
  GER:[["Bayern Munique","BBL · Alemanha"],["Alba Berlim","BBL · Alemanha"]],
  AUS:[["Sydney Kings","NBL · Austrália"],["Melbourne United","NBL · Austrália"]],
  CAN:[["Niagara River Lions","CEBL · Canadá"],["Scarborough Shooting Stars","CEBL · Canadá"]],
  NGR:[["Rivers Hoopers","BAL · África"],["APR","BAL · África"]],
  USA:[],
};
// Sempre disponíveis, seja qual for o passaporte.
const CLUBES_GLOBAIS = [
  ["Olympiacos","EuroLeague"],["Fenerbahçe","EuroLeague · Turquia"],["Maccabi Tel Aviv","EuroLeague · Israel"],
  ["Shanghai Sharks","CBA · China"],["Guangdong","CBA · China"],["Al Riyadi","Líbano"],
];

// A porta dos fundos: quem não foi draftado começa aqui, não na liga.
const G_LEAGUE = [
  ["Santa Cruz Warriors","G League"],["Rio Grande Valley Vipers","G League"],
  ["Long Island Nets","G League"],["Sioux Falls Skyforce","G League"],
  ["Austin Spurs","G League"],["Delaware Blue Coats","G League"],
  ["Maine Celtics","G League"],["Stockton Kings","G League"],
];

function clubesDoPais(nac){
  return (CLUBES_FORA[nac] || []).concat(CLUBES_GLOBAIS);
}

/**
 * As propostas de quem não foi draftado.
 *
 * Antes o não-draftado caía num time da liga do mesmo jeito — o texto dizia
 * "é isso ou o exterior" e não existia exterior nenhum. Não ser chamado no
 * draft passou a ter consequência de verdade: a liga não te quer AINDA, e o
 * caminho de volta é jogar em outro lugar até ela querer.
 *
 * Três portas, cada uma com um preço: perto do sonho e ganhando mal, longe e
 * ganhando bem, ou o meio do caminho com a torcida do seu país.
 */
function ofertasSemDraft(){
  const base = Math.max(1, Math.round(ovr(S.A, S.pos) / 12));
  const doPais = (CLUBES_FORA[S.nac] || []).slice();
  const g = pick(G_LEAGUE);

  const ofertas = [{
    tipo:"gleague", time:g[0], liga:g[1], salario:Math.max(1, base - 1), anos:1,
    forca:ri(40, 62), papel:"rotação", perto:true,
    nota:"Vitrine da liga. Paga pouco, mas os olheiros vêm aqui toda semana.",
  }];

  if (doPais.length){
    const c = pick(doPais);
    ofertas.push({tipo:"casa", time:c[0], liga:c[1], salario:base + 2, anos:2,
                  forca:ri(48, 76), papel:"titular",
                  nota:"Em casa, jogando todo dia, com a sua torcida vendo."});
  }

  const f = pick(CLUBES_GLOBAIS);
  ofertas.push({tipo:"exterior", time:f[0], liga:f[1], salario:base + 5, anos:2,
                forca:ri(55, 85), papel:"titular",
                nota:"Paga o dobro e você joga alto nível. Longe de todo mundo que te conhece."});

  return ofertas;
}

/** Assina a primeira casa de quem não foi draftado e começa a carreira. */
function assinarSemDraft(i){
  const of = (S.semDraft || [])[i];
  if (!of) return;
  S.time = of.time;
  S.anosNoClube = 0;
  S.liga = of.liga;
  S.foraDaLiga = true;
  S.perto = !!of.perto;            // G League: a liga está do lado, não do outro lado do mundo
  S.forcaBase = of.forca;
  S.confianca = of.perto ? 40 : 55;
  S.salario = of.salario;
  S.contrato = of.anos;
  S.papel = of.papel;
  S.gm = null;
  S.semDraft = null;
  S.fase = "liga"; S.anoFase = 0;
  criarRival();
  salvar();
  jogarAno();
}

/**
 * A liga te chama de volta? Só com número na mão.
 *
 * Quem está na G League tem a porta mais perto — mas nenhuma das duas abre
 * de graça, senão o draft não teria servido pra nada.
 */
function chamadoDaLiga(o, st){
  if (!S.foraDaLiga) return null;
  const nivelOvr = S.perto ? 74 : 79;
  const nivelPts = S.perto ? 15 : 19;
  if (o < nivelOvr || st.pts < nivelPts) return null;
  if (ri(0, 100) >= (S.perto ? 55 : 34)) return null;

  S.foraDaLiga = false; S.perto = false;
  S.jaFoiChamado = true;
  S.liga = S.modo === "fba" ? "RISE" : "NBA";
  S.anosDivisao = 0;
  S.time = pick(timesDaLiga(S.liga));
  S.anosNoClube = 0;
  S.gm = S.modo === "fba" ? gmDoTime(S.time) : null;
  S.forcaBase = ri(35, 75);
  S.confianca = 60;
  S.contrato = Math.max(S.contrato, 2);
  S.salario = Math.max(2, Math.round(S.salario * 1.6));
  return S.liga;
}

/**
 * Ofertas da free agency. A pior carreira precisa ter um mercado pior —
 * quem foi mal recebe menos propostas, e às vezes só a de banco. É isso
 * que faz "ir bem" ter consequência.
 */
/**
 * Contrato longo paga menos por ano. É o que faz o prazo ser uma escolha
 * e não um detalhe: segurança tem preço.
 */
function descontoDoPrazo(anos){
  return anos >= 5 ? 0.88 : anos >= 4 ? 0.94 : anos <= 2 ? 1.10 : 1;
}

/**
 * A liga ainda te quer?
 *
 * Esta é a pergunta que faltava no jogo. Antes toda carreira durava as 22
 * temporadas até a aposentadoria por idade, porque a proposta de renovar
 * SEMPRE existia — inclusive pra quem tinha 34 anos e 68 de overall. Numa
 * liga de verdade o telefone simplesmente para de tocar, e a decisão vira
 * outra: insistir lá fora ou pendurar.
 *
 * Os três casos são os três jeitos de acabar: o nível caiu abaixo do que a
 * liga usa, a idade chegou sem o nível pra compensar, ou ninguém paga nada
 * por você.
 */
function ligaAindaQuer(){
  if (S.foraDaLiga) return false;      // já está fora; quem decide é o chamado
  const o = ovr(S.A, S.pos);
  const v = valorDeMercado();
  // O piso de nível que a liga aceita SOBE com a idade. Aos 22 ela ainda
  // está pagando pra ver o que você vira; aos 30 ela quer o que você entrega
  // hoje; aos 34 só fica quem ainda é titular de verdade. É essa escada que
  // faz a carreira mediana durar o que ela dura na vida real, em vez de
  // esticar até os 39 como se todo mundo fosse titular pra sempre.
  const piso = S.idade <= 24 ? 62
             : S.idade <= 28 ? 70
             : S.idade <= 32 ? 75
             : S.idade <= 35 ? 79
                             : 83;
  if (o < piso) return false;

  // Não basta o nível: a liga renova quem JOGA. Quem passou a temporada no
  // fim do banco depois dos 25 não recebe proposta, tenha o overall que
  // tiver — é assim que a carreira de quem nunca ganhou minutos acaba no
  // meio, e não aos 38.
  const ptsAno = (S.ultimo && S.ultimo.pts) || 0;
  if (S.idade >= 25 && ptsAno < 6.5 && o < 80) return false;

  // E mesmo dentro do piso o lugar não é garantido: todo time drafta gente
  // nova todo ano, e o nono homem é o primeiro a ser cortado. Depois dos 25,
  // quem está abaixo da média da liga tem uma chance real de não receber
  // proposta nenhuma — é o que faz existir a carreira de sete anos, entre a
  // que não vingou e a que durou até os 38.
  if (S.idade >= 25 && o < 78 && ri(0, 100) < 32) return false;

  if (v <= 2) return false;                        // ninguém paga nada
  return true;
}

function gerarOfertas(){
  const v = valorDeMercado();
  const lista = timesDaLiga().filter(t => t !== S.time);
  const ofertas = [];
  const timeAleatorio = () => pick(lista);

  // Dispensado: a liga não faz proposta nenhuma. Sobram as portas de fora —
  // e a de pendurar as chuteiras, que a tela do mercado sempre oferece
  // quando é este o caso.
  // A dispensa é pra quem ESTAVA na liga e caiu. Quem já está fora tem o
  // mercado de fora logo abaixo — sem isso, o garoto de 19 anos na G League
  // via 'a liga não ligou' e a sugestão de pendurar as chuteiras no primeiro
  // ano de carreira.
  if (!S.foraDaLiga && !ligaAindaQuer()){
    S.dispensado = true;
    // Fim de linha de verdade: com essa idade e esse nível não existe mais
    // clube nenhum, nem na G League. A tela do mercado vira a despedida.
    const oAtual = ovr(S.A, S.pos);
    if ((S.idade >= 36 && oAtual < 72) || (S.idade >= 39) || oAtual < 58) return [];
    const g = pick(G_LEAGUE);
    const fora = [{tipo:"gleague", time:g[0], liga:g[1], anos:1,
                   salario:Math.max(1, Math.round(v*0.5) + 1), forca:ri(38,62), papel:"rotação",
                   nota:"A vitrine. Paga pouco e os olheiros passam por aqui."}];
    const doPais = CLUBES_FORA[S.nac] || [];
    if (doPais.length){
      const c = pick(doPais);
      fora.push({tipo:"exterior", time:c[0], liga:c[1], anos:2,
                 salario:Math.max(2, Math.round(Math.max(v,3) * 1.3)), forca:ri(45,78), papel:"titular",
                 nota:"Em casa, jogando todo dia, com a sua torcida vendo."});
    }
    const f = pick(CLUBES_GLOBAIS);
    fora.push({tipo:"exterior", time:f[0], liga:f[1], anos:2,
               salario:Math.max(2, Math.round(Math.max(v,3) * 1.6)), forca:ri(50,84), papel:"titular",
               nota:"Longe de tudo, pagando bem, e você volta a ser o cara do time."});
    return fora;
  }
  if (!S.foraDaLiga) S.dispensado = false;

  // Quem está fora da liga não recebe proposta dela como se nada tivesse
  // acontecido: o mercado de lá é outro. Ou renova, ou troca de clube no
  // exterior — e só entra na liga se o número justificar.
  if (S.foraDaLiga){
    // Fora da liga também acaba. O clube de fora paga por quem joga, e o
    // veterano que caiu da liga e não rende mais fica sem proposta nenhuma —
    // é o mesmo fim de linha de dentro, e é onde a maioria das carreiras que
    // desceram um degrau termina.
    const oFora = ovr(S.A, S.pos);
    const ptsFora = (S.ultimo && S.ultimo.pts) || 0;
    const pisoFora = S.idade <= 24 ? 52 : S.idade <= 28 ? 60 : S.idade <= 32 ? 66 : 72;
    if (oFora < pisoFora || (S.idade >= 29 && ptsFora < 9)) return [];

    ofertas.push({tipo:"renovar", time:S.time, liga:S.liga, salario:Math.max(1,Math.round(v*0.9)),
                  forca:S.forcaBase, papel:S.papel || "titular",
                  nota:"Ficar onde você já é titular."});
    const c = pick(S.perto ? G_LEAGUE : clubesDoPais(S.nac));
    ofertas.push({tipo:"exterior", time:c[0], liga:c[1],
                  salario:Math.max(2, Math.round(Math.max(v,3) * 1.3)),
                  forca:ri(50,84), papel:"estrela",
                  nota:`${c[1]}. Outro clube, mais dinheiro, mesma distância da liga.`});
    if (v >= 9){
      const t = timeAleatorio();
      ofertas.push({tipo:"chamado", time:t, liga:S.modo === "fba" ? "RISE" : "NBA",
                    salario:Math.max(2, Math.round(v*0.8)), forca:ri(35,78), papel:"rotação",
                    nota:"A liga te viu. Ganha menos que lá fora e briga por minutos — mas é a liga."});
    }
    ofertas.forEach(o => { o.anos = o.tipo === "chamado" ? 2 : ri(1,3);
                           o.salario = Math.max(1, Math.round(o.salario * descontoDoPrazo(o.anos))); });
    return ofertas;
  }

  // O prazo vem DENTRO da proposta, junto do time e do salário. Antes era
  // uma segunda tela ("por quantos anos?"), o que picava uma decisão só em
  // duas e escondia o que estava sendo comparado: o pacote inteiro.
  // Contrato CURTO é o que faz a carreira andar entre clubes. Era 3-4 pra
  // renovar e 4-5 pra grana: uma carreira de vinte anos cabia em quatro ou
  // cinco janelas, e quem renovava duas vezes morria no mesmo time. Agora a
  // janela chega de dois em dois anos na maioria dos casos — perto do que
  // acontece de verdade, e o bastante pra você trocar de camisa algumas
  // vezes numa carreira.
  //
  // O piso continua sendo DOIS: contrato de um ano transformaria a janela
  // numa pergunta anual, que é o outro extremo e cansa igual.
  const anosPor = {renovar:[2,3], grana:[3,4], contender:[2,3], banco:[2,2], exterior:[2,3]};

  // Renovar onde está: existe quase sempre, mas NÃO quando o clube acabou
  // de rasgar o contrato dentro de um evento. Ler "Rasgaram seu contrato no
  // mesmo dia" e, na tela seguinte, receber uma proposta de renovação do
  // mesmo clube era a contradição mais visível do fluxo. O valor depende da
  // confiança.
  // O CLUBE NEM SEMPRE QUER VOCÊ DE VOLTA. Antes a renovação estava lá em
  // toda janela, e ficar era sempre uma opção — então a carreira inteira
  // podia ser passada num time só, por inércia. Agora o clube decide: com
  // confiança baixa, ou depois de muitos anos de casa com você já velho, ele
  // deixa você ir. Quando isso acontece, sair não é escolha — é o que tem.
  const querRenovar = !S.cortado && (() => {
    if ((S.confianca || 50) < 30) return ri(0, 100) < 35;
    if (S.idade >= 34 && (S.anosNoClube || 0) >= 5) return ri(0, 100) < 55;
    if ((S.confianca || 50) < 45) return ri(0, 100) < 75;
    return true;
  })();
  if (querRenovar){
    const fator = 0.8 + (S.confianca/100) * 0.5;
    ofertas.push({tipo:"renovar", time:S.time, salario:Math.max(1,Math.round(v*fator)),
                  forca:S.forcaBase, papel:"titular",
                  nota:"O time que te conhece. Sem mudança, sem surpresa."});
  }

  if (v >= 12){
    const t = timeAleatorio();
    ofertas.push({tipo:"grana", time:t, salario:Math.round(v*1.35),
                  forca:ri(28,58), papel:"titular",
                  nota:"Paga mais que todo mundo. Só que o time é ruim e vai continuar ruim."});
  }
  if (v >= 8){
    const t = timeAleatorio();
    ofertas.push({tipo:"contender", time:t, salario:Math.max(1,Math.round(v*0.62)),
                  forca:ri(66,90), papel: v >= 26 ? "titular" : "rotação",
                  nota:"Briga por título agora. Você ganha menos e divide a bola."});
  }
  if (v < 10 || S.idade >= 34){
    const t = timeAleatorio();
    ofertas.push({tipo:"banco", time:t, salario:Math.max(1,Math.round(v*0.55)+1),
                  forca:ri(45,80), papel:"reserva",
                  nota:"Saindo do banco. É o que apareceu."});
  }
  // Proposta de fora: chega pra quem não está jogando bem ou já está
  // velho pra liga. Paga bem e devolve o protagonismo — o preço é sair do
  // radar de vez. É a saída digna de quem não tem mais mercado aqui.
  if (v <= 14 || S.idade >= 32){
    const c = pick(clubesDoPais(S.nac));
    const generoso = 1.4 + (S.hype/100) * 0.9;   // nome conhecido vale mais lá fora
    ofertas.push({tipo:"exterior", time:c[0], liga:c[1],
                  salario:Math.max(3, Math.round(Math.max(v,4) * generoso)),
                  forca:ri(55,85), papel:"estrela",
                  nota:`${c[1]}. Paga bem e você é o cara — mas some do radar da liga.`});
  }

  // Mercado seco: quem não tem valor nenhum pode não ter para onde ir.
  // Nunca corta a de fora: ela é o piso que evita "não tenho para onde ir".
  if (v <= 3 && ofertas.length > 2 && ri(0,100) < 55){
    const fora = ofertas.filter(o => o.tipo === "exterior");
    ofertas.splice(1, ofertas.length, ...fora);
  }

  // Três no máximo: ficar, e mais duas. Sete cartões para comparar viravam
  // um catálogo — e a decisão que importa (fico ou vou?) sumia no meio.
  if (ofertas.length > 3){
    const ficar = ofertas.filter(o => o.time === S.time);
    const sair  = ofertas.filter(o => o.time !== S.time);
    for (let i = sair.length - 1; i > 0; i--){ const j = ri(0, i); [sair[i], sair[j]] = [sair[j], sair[i]]; }
    ofertas.splice(0, ofertas.length, ...ficar.slice(0, 1), ...sair.slice(0, 3 - Math.min(1, ficar.length)));
  }

  // Cada tipo tem um prazo natural, e o salário já sai com o desconto do
  // prazo aplicado — o número na tela é o que vai ser pago.
  ofertas.forEach(o => {
    const faixa = anosPor[o.tipo] || [2,3];
    o.anos = ri(faixa[0], faixa[1]);
    o.salario = Math.max(1, Math.round(o.salario * descontoDoPrazo(o.anos)));
  });
  return ofertas;
}

function assinar(oferta){
  const antigo = S.time;
  S.time = oferta.time;
  if (antigo !== oferta.time) S.anosNoClube = 0;
  // Assinar com um clube de fora te tira da liga; o chamado te devolve.
  if (oferta.tipo === "chamado"){
    S.foraDaLiga = false; S.perto = false; S.liga = oferta.liga; S.anosDivisao = 0;
  } else if (oferta.tipo === "exterior" || oferta.tipo === "gleague"){
    S.foraDaLiga = true; S.perto = oferta.tipo === "gleague" || oferta.liga === "G League";
    S.liga = oferta.liga || S.liga;
  }
  if (S.modo === "fba" && !S.foraDaLiga){ S.gm = gmDoTime(oferta.time); }
  else if (S.foraDaLiga) { S.gm = null; }
  if (oferta.tipo !== "renovar" && oferta.tipo !== "provar"){
    S.forcaBase = oferta.forca;
    S.confianca = oferta.papel === "reserva" ? 34 : 52;
  }
  // O desconto do prazo já entrou no salário quando a oferta foi gerada:
  // aplicar de novo aqui cobraria a mesma conta duas vezes.
  S.salario = Math.max(1, oferta.salario);
  S.contrato = oferta.anos;
  S.papel = oferta.papel;
  // Assinou: a marca de "fui cortado" morre aqui. É o único lugar que a
  // apaga — sem isso, a próxima janela de transferências continuaria sem a
  // proposta de renovação do clube em que a pessoa acabou de assinar.
  S.cortado = false;
  return antigo !== S.time;
}

// ═══════════════════════════════════════════════════════════════════════
// MARCA DO TIME
// Logo de verdade exigiria data URI — a CSP do artifact bloqueia imagem
// externa, e 60 logos embutidos somariam centenas de KB num arquivo que
// precisa abrir rápido no celular. O monograma dá identidade visual a
// custo zero, funciona pra time que ainda nem existe, e nunca desatualiza.
// ═══════════════════════════════════════════════════════════════════════
function hashNome(s){
  let h = 0;
  for (let i=0;i<s.length;i++) h = (h*31 + s.charCodeAt(i)) | 0;
  return Math.abs(h);
}
function iniciais(nome){
  const p = String(nome).replace(/[^\p{L}\s]/gu,"").split(/\s+/).filter(Boolean);
  if (p.length === 1) return p[0].slice(0,2).toUpperCase();
  return (p[p.length-2][0] + p[p.length-1][0]).toUpperCase();
}
/**
 * A lista de times da liga escolhida. No site, caminho.php injeta os da
 * FBA direto do banco — com os nomes e logos atuais, sem cópia congelada.
 */
const ESCADA_FBA = ["RISE", "NEXT", "ELITE"];

/**
 * Times disponíveis na divisão em que você está.
 *
 * Antes devolvia a FBA inteira misturada, e por isso subir de divisão não
 * significava nada: os mesmos times apareciam na RISE e na ELITE. Time sem
 * liga cadastrada entra em qualquer divisão — melhor um time a mais que
 * uma divisão vazia.
 */
function timesDaLiga(liga){
  if (S.modo !== "fba") return (window.__TIMES_NBA__ || NBA).map(t => t[0]);
  const l = window.__TIMES_FBA__ || FBA_TIMES;
  const alvo = String(liga || S.liga || "RISE").toUpperCase();
  const doNivel = l.filter(t => !t[3] || String(t[3]).toUpperCase() === alvo);
  return (doNivel.length ? doNivel : l).map(t => t[0]);
}

/**
 * Sobe uma divisão depois de uma temporada de destaque.
 *
 * O draft joga todo mundo na RISE e a carreira morria lá — a escada que o
 * modo FBA promete nunca acontecia. A promoção é por mérito INDIVIDUAL, e
 * não do time, porque o que o jogador controla é o próprio desempenho.
 * A régua sobe junto com a divisão: destacar na NEXT é mais difícil.
 */
function tentarSubirDivisao(o, st, premios){
  if (S.modo !== "fba") return null;
  const i = ESCADA_FBA.indexOf(String(S.liga || "").toUpperCase());
  if (i < 0 || i >= ESCADA_FBA.length - 1) return null;

  // Medido: com a regua frouxa, 58% das carreiras terminavam na ELITE —
  // isso e escada rolante, nao escada. Tres freios: nivel mais alto, um
  // sorteio bem menor, e um minimo de duas temporadas na divisao antes de
  // poder subir (subir todo ano tirava o peso de ter subido).
  S.anosDivisao = (S.anosDivisao || 0) + 1;
  if (S.anosDivisao < 2) return null;

  const nivelOvr = i === 0 ? 78 : 87;
  const nivelPts = i === 0 ? 16 : 20;
  if (o < nivelOvr || st.pts < nivelPts) return null;
  if (ri(0, 100) >= (premios.length ? 34 : 18)) return null;

  const nova = ESCADA_FBA[i + 1];
  S.liga = nova;
  S.anosDivisao = 0;
  S.time = pick(timesDaLiga(nova));
  S.anosNoClube = 0;
  S.gm = gmDoTime(S.time);
  S.forcaBase = ri(45, 82);
  S.confianca = 58;
  S.contrato = Math.max(S.contrato, 2);
  S.salario = Math.max(1, Math.round(S.salario * 1.7));
  return nova;
}
function gmDoTime(nome){
  const l = window.__TIMES_FBA__ || FBA_TIMES;
  const t = l.find(x => x[0] === nome);
  return (t && t[2]) ? t[2] : null;
}

/** Logo do time, se houver. Mapa montado uma vez a partir das duas listas. */
let LOGOS = null, LOGOS_MODO = null;
/**
 * Escudo dos clubes de fora da NBA, do TheSportsDB.
 *
 * Cada um foi resolvido pelo nome e conferido: as 34 URLs sao distintas
 * (nenhum clube herdou o escudo de outro), todas devolvem imagem 512x512,
 * e o nome que a base devolveu bate com o do jogo.
 *
 * Cinco continuam no monograma porque nao existem na base: Minas,
 * Instituto, Rivers Hoopers, Fenerbahce e Rytas. Monograma nao erra e nao
 * some — melhor que apontar pra um escudo que talvez seja de outro time.
 *
 * Se algum vier errado, apagar a linha resolve: marca() cai no monograma
 * sozinha, e o onerror do <img> ja cobre link que morrer no futuro.
 */
const LOGOS_CLUBE = {
  "APR": "https://r2.thesportsdb.com/images/media/team/badge/fye4pf1757058873.png",
  "ASVEL": "https://r2.thesportsdb.com/images/media/team/badge/qbaoia1602706639.png",
  "Al Riyadi": "https://r2.thesportsdb.com/images/media/team/badge/bomga61694262650.png",
  "Alba Berlim": "https://r2.thesportsdb.com/images/media/team/badge/58osvu1545866407.png",
  "Austin Spurs": "https://r2.thesportsdb.com/images/media/team/badge/uprptt1423351492.png",
  "Barcelona": "https://r2.thesportsdb.com/images/media/team/badge/0tz26j1729097443.png",
  "Baskonia": "https://r2.thesportsdb.com/images/media/team/badge/p4x3o61767366090.png",
  "Bayern Munique": "https://r2.thesportsdb.com/images/media/team/badge/z2r3eh1678017187.png",
  "Boca Juniors": "https://r2.thesportsdb.com/images/media/team/badge/f3ckve1769678204.png",
  "Delaware Blue Coats": "https://r2.thesportsdb.com/images/media/team/badge/z0zioq1642560326.png",
  "Estrela Vermelha": "https://r2.thesportsdb.com/images/media/team/badge/5tlez31767366440.png",
  "Flamengo": "https://r2.thesportsdb.com/images/media/team/badge/japu7e1593945465.png",
  "Franca": "https://r2.thesportsdb.com/images/media/team/badge/x5fe471645367586.png",
  "Guangdong": "https://r2.thesportsdb.com/images/media/team/badge/he2jgc1524997963.png",
  "Long Island Nets": "https://r2.thesportsdb.com/images/media/team/badge/04a62j1487267932.png",
  "Maccabi Tel Aviv": "https://r2.thesportsdb.com/images/media/team/badge/io01a91521148756.png",
  "Maine Celtics": "https://r2.thesportsdb.com/images/media/team/badge/a9ak9v1629981849.png",
  "Melbourne United": "https://r2.thesportsdb.com/images/media/team/badge/adpuke1755702629.png",
  "Monaco": "https://r2.thesportsdb.com/images/media/team/badge/fl2ti01649168915.png",
  "Niagara River Lions": "https://r2.thesportsdb.com/images/media/team/badge/6mi8zs1648907391.png",
  "Olympiacos": "https://r2.thesportsdb.com/images/media/team/badge/4s5lug1676581220.png",
  "Panathinaikos": "https://r2.thesportsdb.com/images/media/team/badge/7cdjwz1767366987.png",
  "Paris Basketball": "https://r2.thesportsdb.com/images/media/team/badge/9q0d6x1726681476.png",
  "Partizan": "https://r2.thesportsdb.com/images/media/team/badge/us0e1z1767366567.png",
  "Real Madrid": "https://r2.thesportsdb.com/images/media/team/badge/g4ev2c1522175902.png",
  "Rio Grande Valley Vipers": "https://r2.thesportsdb.com/images/media/team/badge/xxwsyp1425590343.png",
  "Santa Cruz Warriors": "https://r2.thesportsdb.com/images/media/team/badge/8lwky91574003946.png",
  "Scarborough Shooting Stars": "https://r2.thesportsdb.com/images/media/team/badge/h55jf71648907414.png",
  "Shanghai Sharks": "https://r2.thesportsdb.com/images/media/team/badge/urfigb1700048926.png",
  "Sioux Falls Skyforce": "https://r2.thesportsdb.com/images/media/team/badge/arsbhp1677250166.png",
  "Stockton Kings": "https://r2.thesportsdb.com/images/media/team/badge/1u81ny1586422867.png",
  "Sydney Kings": "https://r2.thesportsdb.com/images/media/team/badge/gsfe5x1550073324.png",
  "São Paulo": "https://r2.thesportsdb.com/images/media/team/badge/youecm1649793339.png",
  "Zalgiris": "https://r2.thesportsdb.com/images/media/team/badge/dn7ouv1703960565.png",
  // As LIGAS entram aqui junto com os clubes porque, na fase de formação, é o
  // nome da liga que aparece como "onde você joga" — o NBB e a Liga ACB são o
  // time da pessoa naquele ano. O escudo delas vem da mesma fonte dos clubes,
  // que já passa pelo proxy.
  "Liga ACB": "https://r2.thesportsdb.com/images/media/league/badge/4n3h6z1572778356.png",
  "Liga ACB (Espanha)": "https://r2.thesportsdb.com/images/media/league/badge/4n3h6z1572778356.png",
  "LNB": "https://r2.thesportsdb.com/images/media/league/badge/60detm1757608684.png",
  "BBL": "https://r2.thesportsdb.com/images/media/league/badge/ssgkzq1550038219.png",
  "Liga Grega": "https://r2.thesportsdb.com/images/media/league/badge/z39ouv1667032625.png",
  "LKL": "https://r2.thesportsdb.com/images/media/league/badge/7nrqn21745083569.png",
  "Liga ABA": "https://r2.thesportsdb.com/images/media/league/badge/stce0n1758213648.png",
  "CBA (China)": "https://r2.thesportsdb.com/images/media/league/badge/peygv31522257103.png",
  "CEBL": "https://r2.thesportsdb.com/images/media/league/badge/pisb3r1778780983.png",
  "Liga Nacional": "https://r2.thesportsdb.com/images/media/league/badge/exea481740429840.png",
  "NBL": "https://r2.thesportsdb.com/images/media/league/badge/gvz6vb1726086476.png",
  "NBL (Austrália)": "https://r2.thesportsdb.com/images/media/league/badge/gvz6vb1726086476.png",
  "Rytas": "https://r2.thesportsdb.com/images/media/team/badge/srfue01713948964.png",
  // Estes oito o catálogo do TheSportsDB não tem, então moram aqui mesmo,
  // recortados e normalizados em 256x256 com fundo transparente. Sendo do
  // nosso domínio, eles não precisam do proxy nem contaminam o canvas do
  // cartão de compartilhar.
  "NBB": "/games/img/escudos/nbb.png",
  "NBB (Brasil)": "/games/img/escudos/nbb.png",
  "EuroLeague": "/games/img/escudos/euroliga.png",
  "BAL": "/games/img/escudos/bal.png",
  "Fenerbahçe": "/games/img/escudos/fenerbahce.png",
  "Instituto": "/games/img/escudos/instituto.png",
  "Minas": "/games/img/escudos/minas.png",
  "Rivers Hoopers": "/games/img/escudos/rivershoopers.png",
};

/**
 * Escudo das universidades, do CDN da ESPN.
 *
 * Os dez ids foram conferidos um a um contra a imagem que devolvem — id
 * errado aqui não dá erro, dá o brasão de outra faculdade, que é pior.
 *
 * Clube de fora e time da G League continuam no monograma: não existe
 * fonte única e estável pros escudos deles, e uma URL chutada devolveria
 * 404 ou, pior, o escudo errado. O monograma nunca erra e nunca some.
 */
const LOGOS_COLLEGE = {
  "Duke":150, "Kentucky":96, "Kansas":2305, "Gonzaga":2250, "UConn":41,
  "Baylor":239, "Michigan State":127, "Arizona":12, "UCLA":26, "Villanova":222,
};

function logoDoTime(nome){
  const modo = (S && S.modo) || "nba";
  // O cache é por MODO: trocar de NBA pra FBA sem recarregar a página
  // deixaria a tabela montada com a prioridade da liga anterior.
  if (!LOGOS || LOGOS_MODO !== modo){
    LOGOS_MODO = modo;
    LOGOS = {};
    // A ordem IMPORTA, e a lista da liga em que se joga entra por último.
    //
    // Um time da FBA e um da NBA podem ter o mesmo nome, e quem escreve por
    // último ganha. Antes o catálogo de clubes internacionais sobrescrevia
    // as duas listas, então nome repetido levava o escudo errado pra
    // trajetória e pro cartão do fim. Agora quem manda é a liga da carreira.
    //
    // O teste de .length no lugar do || é de propósito: lista vazia é truthy
    // em JS, e um window.__TIMES_NBA__ = [] (banco fora do ar na hora de
    // montar a página) fazia o fallback NUNCA entrar — trinta times sem
    // escudo nenhum, e sem erro em lugar algum pra denunciar.
    const fba = (window.__TIMES_FBA__ || []).length ? window.__TIMES_FBA__ : FBA_TIMES;
    const nba = (window.__TIMES_NBA__ || []).length ? window.__TIMES_NBA__ : NBA;
    const daLiga = modo === "fba" ? fba : nba;
    const aOutra = modo === "fba" ? nba : fba;

    Object.entries(LOGOS_CLUBE).forEach(([n, url]) => { LOGOS[n] = url; });
    Object.entries(LOGOS_COLLEGE).forEach(([n, id]) => {
      LOGOS[n] = `https://a.espncdn.com/i/teamlogos/ncaa/500/${id}.png`;
    });
    aOutra.forEach(t => { if (t[1]) LOGOS[t[0]] = t[1]; });
    daLiga.forEach(t => { if (t[1]) LOGOS[t[0]] = t[1]; });
  }
  return LOGOS[nome] || null;
}

/**
 * A marca do time: logo de verdade quando existe, monograma quando não.
 *
 * O onerror é o que faz isso funcionar nos dois lugares. No site o logo
 * carrega normalmente; no protótipo hospedado a CSP bloqueia imagem
 * externa, e sem o fallback ficaria um ícone quebrado em toda tela. Assim
 * a mesma linha serve pros dois, e time sem logo cadastrado também.
 */
/**
 * A cor de um time, do mesmo hash que pinta o monograma.
 *
 * Serve pra faixa de idade da trajetória: a coluna da esquerda vira a
 * carreira lida de relance — seis anos de uma cor, três de outra — e dá pra
 * ver quando se mudou de casa sem ler nome nenhum.
 */
function corDoTime(nome){
  const h = hashNome(nome || "?");
  return `hsl(${h % 360} 52% 30%)`;
}

function marca(nome, tam){
  const t = tam || 34;
  const h = hashNome(nome||"?");
  const mat = h % 360, comp = (mat + 150 + (h % 60)) % 360;
  const fundo = `background:linear-gradient(140deg,hsl(${mat} 62% 42%),hsl(${comp} 58% 28%));
    box-shadow:inset 0 0 0 1px hsl(${mat} 62% 58% / .5)`;
  const monograma = `<span class="marca-time" style="width:${t}px;height:${t}px;
    font-size:${Math.round(t*0.36)}px;${fundo}">${esc(iniciais(nome))}</span>`;

  const url = logoDoTime(nome);
  if (!url) return monograma;

  return `<img class="marca-logo" src="${esc(url)}" alt="${esc(nome)}"
    style="width:${t}px;height:${t}px"
    onerror="this.outerHTML=this.dataset.reserva"
    data-reserva="${esc(monograma)}">`;
}

// ═══════════════════════════════════════════════════════════════════════
// TELAS
// ═══════════════════════════════════════════════════════════════════════
const app = () => document.getElementById("app");

// Topbar do padrão de games/. O ícone é SVG inline porque a CSP do
// artifact bloqueia o Bootstrap Icons — no site, volta a ser <i class="bi">.
const SETA = `<svg viewBox="0 0 16 16" width="14" height="14" fill="currentColor"><path d="M8.5 3.5 4 8l4.5 4.5.9-.9L6.3 8.6H12v-1.2H6.3l3.1-3z"/></svg>`;

/**
 * A barra do topo.
 *
 * `some` marca as telas de JOGO: no celular elas trocam a barra de cima por
 * uma de baixo, com os mesmos atalhos e a ação do momento junto. Sessenta
 * pixels no alto da tela pra repetir o nome do jogo é caro demais quando a
 * ficha, a carreira e a decisão disputam o mesmo espaço.
 */
function topo(){
  const chips = [];
  if (S && !S.encerrada && S.fase === "liga"){
    chips.push(`<div class="chip chip-espelho">OVR <b>${ovr(S.A,S.pos)}</b></div>`);
    chips.push(`<div class="chip chip-espelho">${S.idade} anos</div>`);
    chips.push(`<div class="chip" style="color:var(--amber)">$<b>${S.dinheiro}</b>M</div>`);
  }
  return `<div class="topbar">
    <div class="topbar-left">
      <a href="/games.php" class="back-btn" title="Voltar">${SETA}</a>
      <span class="game-title">O <span>Caminho</span><span class="daily-badge">carreira</span></span>
    </div>
    <div class="topbar-right">${chips.join("")}
      <button class="chip chip-btn" onclick="telaDesafios()" title="Conquistas da carreira">🏆 <b>${
        DESAFIOS.filter(d => (window.__DESAFIOS__||{})[d.id]).length}</b></button>
      <div class="chip" style="color:var(--amber)">${window.__MOEDAS__ ?? 0}</div></div>
  </div><div class="main">`;
}

/**
 * O rodapé com a ação do momento.
 *
 * No DESKTOP ele não existe: `display:contents` dissolve a caixa e os botões
 * ficam exatamente onde sempre estiveram, no fim da decisão. No CELULAR ele
 * cola embaixo da tela — a ação fica à mão em qualquer altura da rolagem, e
 * os atalhos que estavam na barra de cima (voltar, conquistas, moedas) vêm
 * junto, já que lá em cima não tem mais barra.
 */
/**
 * O rodapé fixo, agora SÓ com a ação do momento.
 *
 * Ele levava também voltar, conquistas e moedas — os mesmos atalhos que a
 * barra de cima tem — e por isso a barra de cima era escondida na tela de
 * jogo. Trocar uma barra por outra não devolvia espaço nenhum: devolvia os
 * mesmos botões noutro lugar. Agora a de cima fica (grudada) com os
 * atalhos, e aqui embaixo mora só o que se decide.
 */
function rodapeDeAcoes(acoes){
  if (!acoes) return '';
  return `<div class="rf-acoes">${acoes}</div>`;
}

/** O espaço que a barra fixa ocupa. Vai por ÚLTIMO na página: é ele que
 *  garante que o fim do conteúdo não fique embaixo dela. */
const espacoDaBarra = () => `<div class="dec-espaco" aria-hidden="true"></div>`;

function render(){
  // O overlay e filho do <body>, nao do app: trocar a tela nao leva ele
  // junto. Um popup do draft ou das finais que sobrasse aqui ficaria por
  // cima de tudo, escuro, sem botao pra fechar — o jogo parecia travado e
  // so recarregar resolvia. Quem precisa dele recria ao desenhar: telaDraft
  // chama abrirDraft, e a tela das finais chama abrirFinais.
  document.querySelector('.modal-fundo')?.remove();
  const r = renderTela();
  ajustarTela();
  return r;
}

function renderTela(){
  if (!S) return telaInicio();
  if (S.encerrada) return telaFim();
  if (S.fase === "base")   return telaCriar();
  if (S.fase === "escolha")return telaCaminho();
  if (S.fase === "college" || S.fase === "fora") return telaFormacao();

  // As finais são um POPUP por cima da temporada, e não uma tela própria:
  // são até sete jogos, e cada um redesenhava a tela inteira — a pessoa saía
  // do contexto do ano sete vezes pra ver um placar mudar de 2-1 pra 3-1.
  if (S.finais) { telaTemporada(); return abrirFinais(); }

  if (S.mercado) return telaTemporada();
  if (S.fase === "draft")  return telaDraft();
  if (S.fase === "semdraft") return telaSemDraft();
  return telaTemporada();
}

/**
 * Duas colunas no desktop, uma no celular.
 *
 * `principal` é o que a pessoa veio fazer; `lado` é o contexto — ranking,
 * histórico. No celular o lado cai embaixo, que é onde ele deve estar: o
 * grid não reordena nada, só muda de uma pra duas colunas.
 */
function colunas(principal, lado){
  if (!lado) return principal;
  return `<div class="colunas"><div class="col-principal">${principal}</div>
    <div class="col-lado">${lado}</div></div>`;
}

/**
 * A entrada, no molde do Copero: uma coluna centrada, título grande, as
 * decisões da partida e os botões um embaixo do outro.
 *
 * Era duas colunas com o ranking do lado, e no celular isso empurrava o
 * botão de começar pra baixo do ranking de outra gente. Aqui o ranking vem
 * DEPOIS de tudo que a pessoa veio fazer, que é onde ele pertence.
 */
function telaInicio(){
  const salvo = carregar();
  const principal = `
    <h1>Uma carreira inteira,<br>em alguns minutos.</h1>
    <p class="lead">Você escolhe a posição e o jeito de jogar. O resto é decisão, ano a ano — e sorte, como na vida real.</p>
    ${salvo && !salvo.encerrada ? `
      <div class="bpcard">
        <div class="bpcard-title">Carreira em andamento</div>
        <p style="margin-bottom:12px"><b style="color:var(--text)">${esc(salvo.nome)}</b> · ${salvo.idade} anos · ${esc(salvo.time || salvo.college || "sem time")}</p>
        <button class="btn" onclick="continuar()">Continuar</button>
        <button class="btn btn2" style="margin-top:8px" onclick="if(confirm('Abandonar a carreira atual? Ela não volta.')){apagar();render()}">Começar outra</button>
      </div>` : `
      <div class="pre-escolhas">
        <div>
          <span class="pre-rot">Onde você quer chegar</span>
          <div class="pre-duo">
            <button class="${rascunho.modo !== 'fba' ? 'on' : ''}" onclick="rascunho.modo='nba';telaInicio()">
              <b>NBA</b><small>O caminho clássico. Times reais.</small></button>
            <button class="${rascunho.modo === 'fba' ? 'on' : ''}" onclick="rascunho.modo='fba';telaInicio()">
              <b>FBA</b><small>RISE → NEXT → ELITE, com os GMs da liga.</small></button>
          </div>
        </div>
        <div>
          <span class="pre-rot">Ritmo da carreira</span>
          <div class="pre-duo">
            <button class="${rascunho.ritmo !== 'rapido' ? 'on' : ''}" onclick="rascunho.ritmo='normal';telaInicio()">
              <b>Clássico</b><small>Ano a ano, cada decisão conta.</small></button>
            <button class="${rascunho.ritmo === 'rapido' ? 'on' : ''}" onclick="rascunho.ritmo='rapido';telaInicio()">
              <b>Rápido</b><small>De dois em dois, carreira em metade do tempo.</small></button>
          </div>
        </div>
      </div>
      <button class="btn" onclick="iniciar()">Começar carreira</button>`}

    ${(() => {
      // As conquistas na tela de entrada, com o placar à vista: elas são do
      // jogador e não da carreira, então o lugar delas é aqui — antes de
      // escolher se começa outra. O troféu da barra de cima continua
      // levando pro mesmo lugar; este é o caminho que se acha sem procurar.
      const feitos = window.__DESAFIOS__ || {};
      const n = DESAFIOS.filter(d => feitos[d.id]).length;
      const falta = DESAFIOS.length - n;
      return `<button class="btn btn2 btn-conquistas" style="margin-top:10px" onclick="telaDesafios()">
        <span>🏆 Conquistas</span>
        <b>${n} de ${DESAFIOS.length}</b>
        <small>${falta ? `faltam ${falta}` : "todas"}</small>
      </button>`;
    })()}
    `;
  app().innerHTML = topo() + `<div class="inicio">${principal}${ranking()}</div></div>`;
}

// O nome vem da última carreira desta conta, impresso pelo PHP. Vem do
// BANCO e não do localStorage de propósito: quem joga do celular e do
// computador é a mesma pessoa, e digitar o nome de novo a cada carreira é
// atrito sem motivo.
let rascunho = {nome: window.__ULTIMO_NOME__ || "", numero: "", mao: "D",
                pos:"SG", arq:"atirador", nac:"BRA", modo:"nba", ritmo:"normal", busca:"",
                // Em que etapa da criação o celular está. O desktop ignora:
                // lá as três colunas cabem lado a lado.
                etapa:1};

/** Código de 3 letras → bandeira. O emoji sai das duas letras do país. */
const PAIS_ISO = {BRA:"BR", USA:"US", CAN:"CA", ESP:"ES", FRA:"FR", SRB:"RS",
                  ARG:"AR", GER:"DE", AUS:"AU", NGR:"NG", LTU:"LT", GRE:"GR"};
/**
 * As doze bandeiras, desenhadas aqui dentro.
 *
 * Era emoji (🇧🇷, montado com os regional indicators do ISO). Emoji de
 * bandeira só aparece onde a fonte do sistema tem: no Android e no iPhone
 * sim, no Chrome do Windows não — e lá o 🇧🇷 vira o par de letras "BR". Era
 * isso que a tela de nacionalidade mostrava: uma coluna de siglas.
 *
 * Desenhadas em SVG e não puxadas de um CDN de bandeiras porque assim elas
 * aparecem igual em todo aparelho, sem rede e sem terceiro no meio. São doze
 * — não vale uma dependência.
 *
 * A 14px de altura, detalhe não se lê: brasão de Espanha e Sérvia, as 50
 * estrelas dos EUA e a Union Jack da Austrália viram mancha. Ficaram as
 * formas que identificam cada uma de longe.
 */
const BANDEIRAS = {
  BR: `<rect width="30" height="20" fill="#009b3a"/>
       <path d="M15 2.4 27.6 10 15 17.6 2.4 10Z" fill="#fedf00"/>
       <circle cx="15" cy="10" r="4.3" fill="#002776"/>
       <path d="M10.9 8.6a12 12 0 0 1 8.4 2.9" stroke="#fff" stroke-width="1.1" fill="none"/>`,

  // Sete listras, não treze: a 14px de altura, treze dão 1px cada e a
  // bandeira vira uma mancha rosa. Sete se leem, e continua sendo ela.
  US: `<rect width="30" height="20" fill="#b22234"/>
       ${[1,3,5].map(i => `<rect y="${i*20/7}" width="30" height="${20/7}" fill="#fff"/>`).join('')}
       <rect width="12.6" height="${20*4/7}" fill="#3c3b6e"/>
       ${[0,1,2].map(c => [0,1].map(l =>
         `<circle cx="${2.6+c*3.7}" cy="${3.2+l*4}" r=".8" fill="#fff"/>`).join('')).join('')}`,

  CA: `<rect width="30" height="20" fill="#fff"/>
       <rect width="7.5" height="20" fill="#d52b1e"/><rect x="22.5" width="7.5" height="20" fill="#d52b1e"/>
       <path fill="#d52b1e" d="M15 3.4l.9 2.6 2.1-1-.7 2.9 2.4-.4-1.7 2.1 1.1.6-3.3 2.5.5 1.4-2-.3.1 3h-.8l.1-3-2 .3.5-1.4-3.3-2.5 1.1-.6L8.3 7.5l2.4.4-.7-2.9 2.1 1z"/>`,

  ES: `<rect width="30" height="20" fill="#c60b1e"/><rect y="5" width="30" height="10" fill="#ffc400"/>`,

  FR: `<rect width="30" height="20" fill="#fff"/>
       <rect width="10" height="20" fill="#002395"/><rect x="20" width="10" height="20" fill="#ed2939"/>`,

  RS: `<rect width="30" height="20" fill="#c6363c"/>
       <rect y="6.67" width="30" height="6.67" fill="#0c4076"/>
       <rect y="13.33" width="30" height="6.67" fill="#fff"/>`,

  AR: `<rect width="30" height="20" fill="#74acdf"/>
       <rect y="6.67" width="30" height="6.67" fill="#fff"/>
       <circle cx="15" cy="10" r="2.5" fill="#f6b40e"/>`,

  DE: `<rect width="30" height="20" fill="#000"/>
       <rect y="6.67" width="30" height="6.67" fill="#dd0000"/>
       <rect y="13.33" width="30" height="6.67" fill="#ffce00"/>`,

  AU: `<rect width="30" height="20" fill="#012169"/>
       <rect width="15" height="10" fill="#012169"/>
       <path d="M0 0l15 10M15 0L0 10" stroke="#fff" stroke-width="1.7"/>
       <path d="M7.5 0v10M0 5h15" stroke="#fff" stroke-width="2.8"/>
       <path d="M7.5 0v10M0 5h15" stroke="#e4002b" stroke-width="1.5"/>
       <circle cx="7.5" cy="15.5" r="1.3" fill="#fff"/>
       ${[[21,4.5],[25,8],[21.5,12],[26,14],[23,7.5]].map(([x,y]) =>
         `<circle cx="${x}" cy="${y}" r=".95" fill="#fff"/>`).join('')}`,

  NG: `<rect width="30" height="20" fill="#fff"/>
       <rect width="10" height="20" fill="#008751"/><rect x="20" width="10" height="20" fill="#008751"/>`,

  LT: `<rect width="30" height="20" fill="#fdb913"/>
       <rect y="6.67" width="30" height="6.67" fill="#006a44"/>
       <rect y="13.33" width="30" height="6.67" fill="#c1272d"/>`,

  GR: `<rect width="30" height="20" fill="#fff"/>
       ${[0,2,4,6,8].map(i => `<rect y="${i*20/9}" width="30" height="${20/9}" fill="#0d5eaf"/>`).join('')}
       <rect width="${20*5/9}" height="${20*5/9}" fill="#0d5eaf"/>
       <path d="M0 ${20*2.5/9}h${20*5/9}M${20*2.5/9} 0v${20*5/9}" stroke="#fff" stroke-width="2.1"/>`,
};

/**
 * A bandeira do país, pronta pra colar no HTML.
 *
 * País sem desenho cai na sigla, que é o que a tela mostrava antes — o pior
 * caso é o que já existia.
 */
function bandeira(codigo){
  const iso = PAIS_ISO[codigo];
  const svg = iso && BANDEIRAS[iso];
  if (!svg) return `<span class="band-sem">${esc(iso || codigo || "—")}</span>`;
  return `<svg class="band" viewBox="0 0 30 20" role="img" aria-label="${esc(iso)}">${svg}</svg>`;
}

/** Onde cada posição fica na quadra, em % da caixa. */
const POS_NA_QUADRA = {PG:[50,14], SG:[16,34], SF:[84,34], PF:[26,66], C:[50,80]};

function iniciar(){ S = null; apagar(); telaCriar(true); }
function continuar(){ S = carregar(); render(); }

/**
 * A tela de identidade: camisa, país e quadra, lado a lado.
 *
 * O redesenho veio de um pedido com referência — a ideia é que a primeira
 * tela pareça o começo de uma carreira, não um cadastro. Por isso o nome e o
 * número aparecem na camisa enquanto se digita, e a posição é escolhida no
 * lugar da quadra onde ela joga, e não numa lista.
 *
 * O sobrenome na camisa é a última palavra do nome, como no uniforme de
 * verdade: quem digita "Marcos Silva" vê SILVA nas costas.
 */
/** Anda entre as três etapas da criação (só o celular usa). */
function irParaEtapa(n){
  rascunho.etapa = Math.min(3, Math.max(1, n));
  telaCriar();
  // Sem isto a etapa nova abre na altura em que a anterior estava rolada.
  window.scrollTo({top: 0, behavior: 'auto'});
}

function telaCriar(reinicio){
  // A etapa nasce em 1 a cada carreira NOVA. rascunho vive na pagina, nao na
  // carreira: quem criava a segunda sem recarregar caia direto na etapa 3
  // (a ultima em que tinha estado), com a barra cheia e o botao ja escrito
  // "Confirmar identidade". iniciar() sempre passou `true` aqui; o argumento
  // e que nunca tinha sido lido.
  if (reinicio) rascunho.etapa = 1;

  const nome = (rascunho.nome || "").trim();
  const sobrenome = nome ? nome.split(/\s+/).slice(-1)[0].toUpperCase() : "SEU NOME";
  const busca = (rascunho.busca || "").trim().toLowerCase();
  const paises = NACOES.filter(n => !busca || n[1].toLowerCase().includes(busca));
  const p = POSICOES[rascunho.pos];

  // No celular a criação vira três etapas — identidade, país, posição — com
  // uma barra dizendo onde você está. Empilhadas, as três colunas davam uma
  // página de rolar cinco vezes com o botão de confirmar lá no topo, longe
  // da última escolha.
  //
  // No desktop nada disso aparece: lá elas cabem lado a lado e ver tudo de
  // uma vez é melhor que navegar. Quem decide é o CSS; o HTML é o mesmo.
  const etapa = Math.min(3, Math.max(1, rascunho.etapa || 1));
  const ETAPAS = ["Identidade", "Nacionalidade", "Posição"];

  app().innerHTML = topo() + `
    <div class="id-cab">
      <div class="id-cab-txt">
        <h1>Defina sua identidade</h1>
        <p class="lead">Isso define seu ponto de partida — não o seu teto.</p>
      </div>
      <div class="id-cab-acoes">
        <button class="btn btn2" onclick="render()">Voltar</button>
        <button class="btn" onclick="criar()">Confirmar identidade</button>
      </div>
    </div>

    <div class="etapa-cab">
      <div class="etapa-tit">${ETAPAS[etapa - 1]}</div>
      <div class="etapa-barra"><i style="width:${(etapa / 3) * 100}%"></i></div>
    </div>

    <!-- Voltar e Continuar logo abaixo da barra de etapa, e nao no pe: a
         coluna da vez tem lista de pais e quadra de posicao, e o botao no fim
         obrigava a rolar ate embaixo pra avancar. Esta linha e display:none
         no desktop, entao subir ela nao mexe em nada la. -->
    <div class="etapa-pe">
      <button class="btn btn2" onclick="${etapa === 1 ? 'render()' : 'irParaEtapa(' + (etapa - 1) + ')'}">Voltar</button>
      ${etapa < 3
        ? `<button class="btn" onclick="irParaEtapa(${etapa + 1})">Continuar</button>`
        : `<button class="btn" onclick="criar()">Confirmar identidade</button>`}
    </div>

    <div class="id-grade" data-etapa="${etapa}">

      <div class="id-col">
        <div class="id-col-tit">Identidade</div>
        <div class="camisa">
          <svg viewBox="0 0 100 106" aria-hidden="true">
            <path d="M32 6 L20 12 L8 26 L18 38 L26 32 L26 100 L74 100 L74 32 L82 38 L92 26 L80 12 L68 6
                     C64 14 56 17 50 17 C44 17 36 14 32 6 Z"
                  fill="var(--red)" stroke="rgba(255,255,255,.28)" stroke-width="1.5"/>
            <path d="M32 6 C36 14 44 17 50 17 C56 17 64 14 68 6"
                  fill="none" stroke="rgba(255,255,255,.5)" stroke-width="2"/>
          </svg>
          <div class="camisa-nome">${esc(sobrenome)}</div>
          <div class="camisa-num">${esc((rascunho.numero || "0").slice(0, 2))}</div>
        </div>

        <div class="id-campos">
          <div class="id-campo">
            <label for="idNome">Nome</label>
            <input type="text" id="idNome" maxlength="26" placeholder="Marcos Silva"
                   value="${esc(rascunho.nome)}">
          </div>
          <div class="id-campo">
            <label for="idNum">Número</label>
            <input type="text" id="idNum" maxlength="2" inputmode="numeric" placeholder="10"
                   value="${esc(rascunho.numero)}">
          </div>
        </div>

        <div class="id-campo" style="margin-top:11px">
          <label>Mão dominante</label>
          <div class="id-duo">
            <button class="${rascunho.mao === 'E' ? 'on' : ''}" onclick="rascunho.mao='E';telaCriar()">Esquerda</button>
            <button class="${rascunho.mao === 'D' ? 'on' : ''}" onclick="rascunho.mao='D';telaCriar()">Direita</button>
          </div>
        </div>
      </div>

      <div class="id-col">
        <div class="id-col-tit">Nacionalidade</div>
        <div class="nac-busca">
          <i class="bi bi-search"></i>
          <input type="text" id="idBusca" placeholder="Buscar país" value="${esc(rascunho.busca)}">
        </div>
        <div class="nac-lista">
          ${paises.length ? paises.map(n => `
            <button class="nac-item ${rascunho.nac === n[0] ? 'on' : ''}" onclick="rascunho.nac='${n[0]}';telaCriar()">
              <span class="nac-flag">${bandeira(n[0])}</span>
              <span class="nac-nome">${esc(n[1])}</span>
            </button>`).join("")
            : `<div class="nac-vazio">Nenhum país com esse nome.</div>`}
        </div>
      </div>

      <div class="id-col">
        <div class="id-col-tit">Posição</div>
        <div class="quadra">
          <i class="linha q-arco"></i>
          <i class="linha q-garrafao"></i>
          <i class="linha q-circulo"></i>
          ${Object.keys(POSICOES).map(k => {
            const [x, y] = POS_NA_QUADRA[k];
            return `<button class="pos-chip ${rascunho.pos === k ? 'on' : ''}"
                            style="left:${x}%;top:${y}%"
                            onclick="rascunho.pos='${k}';telaCriar()">${k}</button>`;
          }).join("")}
        </div>
        <div class="pos-desc"><b>${esc(p.n)}</b> — ${esc(p.d)}
          <span class="pos-tende">Costuma virar ${(ARQ_DA_POSICAO[rascunho.pos] || [])
            .slice(0, 2).map(a => esc(ARQUETIPOS[a[0]].n)).join(" ou ")} — mas quem decide é o sorteio.</span>
        </div>
      </div>

    </div>

    <p class="nota-txt" style="margin-top:12px">Você vai jogar
    <b style="color:var(--text)">${rascunho.modo === 'fba' ? 'na FBA' : 'na NBA'}</b>, no ritmo
    <b style="color:var(--text)">${rascunho.ritmo === 'rapido' ? 'rápido (de dois em dois anos)' : 'clássico (ano a ano)'}</b>.
    Dá pra trocar voltando à tela anterior.</p>

    `;

  // Os campos de texto guardam o valor a cada tecla, mas NÃO redesenham a
  // tela: redesenhar tira o foco do input e o cursor volta pro começo. Quem
  // atualiza a camisa é este mesmo laço, direto no elemento.
  const nomeEl = document.getElementById("idNome");
  const numEl  = document.getElementById("idNum");
  const buscaEl = document.getElementById("idBusca");

  nomeEl.oninput = () => {
    rascunho.nome = nomeEl.value;
    const t = nomeEl.value.trim();
    document.querySelector(".camisa-nome").textContent =
      t ? t.split(/\s+/).slice(-1)[0].toUpperCase() : "SEU NOME";
  };
  numEl.oninput = () => {
    numEl.value = numEl.value.replace(/\D/g, "").slice(0, 2);
    rascunho.numero = numEl.value;
    document.querySelector(".camisa-num").textContent = numEl.value || "0";
  };
  // A busca precisa redesenhar (a lista muda), então devolve o foco e o
  // cursor pro fim depois.
  buscaEl.oninput = () => {
    rascunho.busca = buscaEl.value;
    telaCriar();
    const novo = document.getElementById("idBusca");
    novo.focus();
    novo.setSelectionRange(novo.value.length, novo.value.length);
  };
}

function criar(){
  const nome = (rascunho.nome || "").trim() || "Jogador Sem Nome";
  S = novaCarreira(nome, rascunho.pos, sortearArquetipo(rascunho.pos), rascunho.nac, rascunho.modo);
  S.numero = rascunho.numero || String(ri(0, 99));
  S.mao = rascunho.mao;
  S.ritmo = rascunho.ritmo === "rapido" ? "rapido" : "normal";
  S.fase = "escolha";
  salvar(); render();
}

/**
 * Os 18 anos: a tela é só o retrato, a decisão é um popup.
 *
 * Antes esta tela despejava as dez universidades e as cinco ligas de uma
 * vez — quinze cartões, e a escolha virava um catálogo. Agora chegam CINCO
 * convites, sorteados, e o resto do mundo não chegou pra você. É a mesma
 * decisão com a informação que um garoto de 18 anos realmente tem: quem
 * ligou.
 *
 * O sorteio não é cego. Vitrine grande (Duke, Kentucky, UCLA) só liga pra
 * quem tem nome; quem ninguém viu recebe convite de programa que precisa de
 * gente pra jogar. E a liga de casa, pra quem não é americano, quase sempre
 * está na lista — ela é a porta que ninguém fecha.
 */
function sortearConvites(){
  const o = ovr(S.A, S.pos);
  const aval = o + (S.pot - o) * 0.35;
  // A avaliação aos 18 vive entre 50 e 63 (medido em 4 mil carreiras), com a
  // mediana em 58 -- por isso o centro é 58 e a régua é curta: dividir por 9
  // deixava todo mundo no meio e o sorteio virava uniforme.
  const t = clamp((aval - 58) / 3.5, -1.6, 1.6);

  const univ = COLLEGES.map((c, i) => ({
    tipo:"univ", i, n:c.n, f:c.f, d:c.d,
    peso: Math.max(0.06, 1 + t * c.hype * 0.6),
  }));

  const pros = caminhosProfissionais().map((l, i) => ({
    tipo: l.casa ? "casa" : "pro", i, n:l.n, f: l.casa ? "Em casa" : "Profissional", d:l.d,
    peso: l.casa ? 5 : 1,
  }));

  S.convites = sortearPesado(univ, 3).concat(sortearPesado(pros, 2));
  salvar();
}

// Sorteio ponderado sem repetição: tira um, remove da urna, repete.
function sortearPesado(urna, n){
  const resto = urna.slice(), saiu = [];
  while (saiu.length < n && resto.length){
    const total = resto.reduce((s, x) => s + x.peso, 0);
    let r = Math.random() * total, k = 0;
    while (k < resto.length - 1 && (r -= resto[k].peso) > 0) k++;
    saiu.push(resto.splice(k, 1)[0]);
  }
  return saiu;
}

function telaCaminho(){
  const o = ovr(S.A, S.pos);
  if (!S.convites || !S.convites.length) sortearConvites();
  app().innerHTML = topo() + `
    <h1>18 anos.<br>Pra onde agora?</h1>
    <p class="lead">Você é <b style="color:var(--text)">${o} de nível</b> e os olheiros te veem como <b style="color:var(--red)">${faixaPotencial(S.pot).toLowerCase()}</b>. O caminho muda o que você vira.</p>

    <h2>Quem ligou pra você</h2>
    <p class="nota-txt" style="margin:-2px 0 10px">${S.convites.filter(c => c.tipo === "univ").length} universidades e
      ${S.convites.filter(c => c.tipo !== "univ").length} portas do basquete profissional.
      Nem todo mundo te viu — estes viram.</p>
    <div class="conv-lista">${convitesHTML()}</div>
    <p class="nota-txt">Em qualquer caminho você decide, ano a ano, quando entrar no draft — até o quarto ano, quando a decisão deixa de ser sua.</p>`;
}

function convitesHTML(){
  return `${S.convites.map((c, k) => `
        <button class="conv ${c.tipo}" onclick="escolherConvite(${k})">
          <span class="conv-ico"><i class="bi bi-${c.tipo === "univ" ? "mortarboard-fill" : c.tipo === "casa" ? "house-heart-fill" : "globe-americas"}"></i></span>
          <span class="conv-txt">
            <b>${esc(c.n)}</b>
            <span class="conv-tag">${esc(c.f)}</span>
            <small>${esc(c.d)}</small>
          </span>
        </button>`).join("")}`;
}

function escolherConvite(k){
  const c = S.convites[k];
  if (!c) return;
  if (c.tipo === "univ") escolherCollege(c.i); else escolherFora(c.i);
}

/**
 * Os caminhos profissionais, com o do país do jogador na frente.
 *
 * Quem não é americano tem uma escolha que o americano não tem: começar
 * em casa, jogando de verdade aos 18 em vez de sentar num banco
 * universitário. O draft continua aberto — só deixa de ser obrigatório.
 */
// Nome real da liga de cada país. Sem isto o brasileiro via "Liga do
// Brasil" E "NBB (Brasil)" na mesma tela — duas entradas pra mesma liga.
const LIGA_DO_PAIS = {
  BRA:"NBB", ESP:"Liga ACB", FRA:"LNB", SRB:"Liga ABA", GRE:"Liga Grega",
  LTU:"LKL", ARG:"Liga Nacional", GER:"BBL", AUS:"NBL", CAN:"CEBL", NGR:"BAL",
};

function caminhosProfissionais(){
  const locais = CLUBES_FORA[S.nac] || [];
  const ligaLocal = LIGA_DO_PAIS[S.nac];
  const lista = [];

  if (locais.length && ligaLocal){
    lista.push({n:ligaLocal, casa:true,
      d:`Jogar em casa, em clube como ${locais[0][0]}. Minutos desde já e a seleção te vendo de perto.`,
      min:+3, hype:0, dev:+1, $:1});
  }
  // Tira da lista genérica a liga que já entrou como "em casa".
  const semRepetir = LIGAS_FORA.filter(l => !ligaLocal || !l.n.startsWith(ligaLocal));
  return lista.concat(semRepetir);
}

function escolherCollege(i){
  const c = COLLEGES[i];
  S.college = c.n; S.time = c.n; S.fase = "college"; S.anoFase = 0;
  S.confianca = Math.round(clamp(50 + c.min*4, 10, 95));
  S.hype = Math.round(clamp(50 + c.hype*6, 5, 95));
  S.bonusDev = c.dev;
  salvar(); avancarFormacao();
}

function escolherFora(i){
  const l = caminhosProfissionais()[i];
  S.ligaFora = l.n; S.time = l.n; S.fase = "fora"; S.anoFase = 0;
  S.confianca = Math.round(clamp(50 + l.min*4, 10, 95));
  S.hype = Math.round(clamp(50 + l.hype*6, 5, 95));
  S.bonusDev = l.dev; S.paga = l.$;
  salvar(); avancarFormacao();
}

// Um ano de formação: mesma engine da temporada, com peso menor e a
// decisão trocada por "sair ou ficar".
function avancarFormacao(){
  const o = ovr(S.A, S.pos);
  const min = minutosDoAno(o);
  const st = statsDoAno(o, min, 60);
  S.ultimo = st;
  S.anoFase++; S.idade++; S.ano++;

  // Desempenho move o hype: é assim que o draft deixa de ser sorteio.
  //
  // A régua é MÓVEL porque o esperado muda: em 900 carreiras medidas, a
  // produção mediana da formação vai de -2,7 no primeiro ano a +28,4 no
  // quarto, e a dispersão quadruplica junto. O baseline fixo de 34 que
  // estava aqui cobrava do calouro o que só o veterano entrega — a mediana
  // perdia 25 de hype POR ANO, todo mundo chegava ao draft no piso 5, a
  // posição virava sorteio puro e 30% dos jogadores ficavam fora das 60
  // escolhas. Dividir pela escala do ano deixa um ano bom valendo o mesmo
  // (+10 de hype) seja ele o primeiro ou o último.
  const desempenho = (st.pts*1.6 + st.reb*0.9 + st.ast*1.1) + (o-60);
  // Remedido depois que a curva de minutos mudou: a produção mediana da
  // formação virou -5,7 / +2,1 / +8,7 / +16,7 do primeiro ao quarto ano
  // (antes era -2,7 / +9,2 / +19,1 / +28,4). Com a régua velha, todo mundo
  // ficava abaixo do esperado e o hype voltava a cair ano após ano — o que
  // apagava as escolhas de topo do draft.
  const esperado = -12 + 6.5 * (S.anoFase - 1);
  const escala   =  8 +  10  * (S.anoFase - 1);
  S.hype = clamp(S.hype + Math.round((desempenho - esperado) / escala * 11), 5, 99);

  // O fenômeno é notado antes de jogar bem: é o que ele É, não o que ele
  // fez. Sem este empurrão o portão do draft (hype 72) quase nunca abria
  // e o prodígio aparecia em 0,3% das carreiras — raro demais pra existir.
  if (S.prodigio) S.hype = clamp(S.hype + ri(9, 16), 5, 99);
  for (let i=0;i<(S.bonusDev||1);i++) evoluir();
  if (S.paga) S.dinheiro += S.paga;

  // Título europeu: raro, e só pra quem foi jogar lá e jogou bem. É a
  // porta de entrada "cheguei com nome" — quem vem por aqui desembarca
  // na NBA valendo mais do que a posição de draft diria.
  const premios = [];
  if (S.fase === "fora" && LIGAS_EUROPEIAS.some(x => String(S.ligaFora||"").includes(x))
      && ovr(S.A, S.pos) >= 58 && ri(0,100) < 30){
    S.trofeus.euro++; S.destaque = "euroliga";
    S.hype = clamp(S.hype + 14, 5, 99);
    premios.push("Campeão da Euroliga");
    S.mensagem = "Você levantou a Euroliga. Os olheiros da NBA pararam de te tratar como aposta.";
  }

  S.temporadas.push({ano:S.ano, idade:S.idade, time:S.time, ...st, premios, campanha:null, formacao:true});
  const ovrAntes = S.ovrAnterior;
  S.linhaNova = S.idade;
  salvar(); telaFormacao();
  S.linhaNova = null;
  animarAno(ovrAntes, ovr(S.A, S.pos), S.idade);
}

/**
 * A formação usa a MESMA tela da temporada — porque é a mesma coisa.
 *
 * Antes era uma página à parte, com a decisão num bloco próprio e sem a
 * trajetória do lado. Mas um ano de universidade é um ano de carreira: tem
 * placar, tem evolução, e termina com uma escolha. Agora ele mora no mesmo
 * lugar, com a mesma súmula à direita e a decisão no mesmo formato de cartas
 * do resto do jogo — sair pro draft ou ficar mais um ano.
 */
function telaFormacao(){
  const st = S.ultimo;
  const o = ovr(S.A, S.pos);
  const projecao = S.hype >= 85 ? "top 5 do draft" : S.hype >= 70 ? "1ª rodada"
                 : S.hype >= 52 ? "fim da 1ª rodada" : S.hype >= 35 ? "2ª rodada" : "fora do draft";
  const ultimoAno = S.anoFase >= 4;
  const faltam = 4 - S.anoFase;

  // No último ano a única saída é o draft, e o botão vai pro rodapé fixo.
  const acoes = ultimoAno ? `<button class="btn" onclick="irAoDraft()">Entrar no draft</button>` : "";

  const decisao = ultimoAno ? `
    <h1 class="dec-tit">Acabou o seu tempo aqui</h1>
    <p class="lead dec-sub">Quatro anos ${S.fase === "college" ? "de universidade" : "de formação"} e a
      porta se fecha atrás de você. Os olheiros te colocam como
      <b style="color:var(--red)">${projecao}</b>.</p>` : `
    <h1 class="dec-tit">Sair ou ficar</h1>
    <p class="lead dec-sub">Os olheiros hoje te colocam como <b style="color:var(--red)">${projecao}</b>.
      Sair agora é levar o que está na mesa; ficar são mais
      ${faltam} ${faltam === 1 ? "ano possível" : "anos possíveis"} pra crescer — e pra dar errado.</p>
    <div class="dec-grade">
      <button class="dec-card" onclick="irAoDraft()">
        <span class="dec-card-tit">Entrar no draft agora</span>
        <span class="dec-card-res">
          <span class="dec-res dec-bom"><i>↗</i><em>começa a carreira ${faltam} ano${faltam === 1 ? "" : "s"} antes</em></span>
          <span class="dec-res dec-${S.hype >= 70 ? "bom" : "ruim"}"><i>${S.hype >= 70 ? "↗" : "↘"}</i>
            <em>${S.hype >= 70 ? "e a projeção já está boa" : "e pega o que estiver na mesa"}</em></span>
        </span>
      </button>
      <button class="dec-card" onclick="avancarFormacao()">
        <span class="dec-card-tit">Ficar mais um ano</span>
        <span class="dec-card-res">
          <span class="dec-res dec-bom"><i>↗</i><em>vai pro ${S.anoFase + 1}º ano e evolui de novo</em></span>
          <span class="dec-res dec-ruim"><i>↘</i><em>um ano ruim derruba a projeção</em></span>
        </span>
      </button>
    </div>`;

  const blocoFicha = barra() + placar(st, `${S.anoFase}º ano de 4`, S.time, null, []);

  const blocoDecisao =
    (S.mensagem ? `<div class="bpcard"><p class="dec-txt" style="margin:0">${S.mensagem}</p></div>` : "") +
    `<div class="dec-caixa">${decisao}${rodapeDeAcoes(acoes)}</div>` +
    `<button class="btn-desistir" onclick="desistir()">Desistir da carreira</button>` +
    espacoDaBarra();

  app().innerHTML = topo() + `<div class="colunas colunas-ano">
    <div class="col-principal">
      <div class="bl-ficha">${blocoFicha}</div>
      <div class="bl-decisao">${blocoDecisao}</div>
    </div>
    <div class="col-lado">${sumula()}</div>
  </div>`;
  ajustarTela();
}

function irAoDraft(){ S.fase = "draft"; S.draftRevelado = false; salvar(); telaDraft(); }

function telaDraft(){
  if (!S.pickDraft){
    // Prodígio só vira prodígio se a formação confirmou: fenômeno sorteado
    // que jogou mal a universidade inteira não pode entrar como top 3.
    if (S.prodigio && S.hype >= 72) S.destaque = "prodigio";

    // A posição sai do hype E do nível, com ruído: você colhe o que plantou,
    // mas a noite do draft nunca é totalmente previsível.
    //
    // Os dois pesam porque medem coisas diferentes (correlação de 0,56 em
    // 1.500 carreiras): o hype é o que falaram de você, o nível é o que você
    // é. Só com hype, o calouro que não evoluiu saía na mesma faixa do
    // veterano pronto; só com nível, quem estourou num ano não subia.
    const nivel = clamp(ovr(S.A, S.pos) - 54, -12, 26);
    const base = 61 - Math.round(S.hype * 0.45) - Math.round(nivel * 0.85);
    const ruido = base >= 42 ? ri(-8, 22) : ri(-7, 9);
    S.pickDraft = S.destaque === "prodigio" ? ri(1, 3)
                : S.destaque === "euroliga" ? clamp(base + ri(-9, 2), 1, 40)
                : clamp(base + ruido, 1, 61);
    // Não-draftado não recebe time da liga: a liga não o chamou. Ele escolhe
    // onde vai jogar entre as portas que existem fora dela. A fase só muda
    // depois da revelação — até lá a noite do draft ainda está acontecendo.
    if (S.pickDraft > 60){
      S.semDraft = ofertasSemDraft();
    } else {
      const lista = timesDaLiga();
      S.time = pick(lista);
      if (S.modo === "fba"){ S.gm = gmDoTime(S.time); }
      S.liga = S.modo === "fba" ? "RISE" : "NBA";
      // A fase só vira "liga" depois da revelação: recarregar a página no
      // meio do popup tem que devolver a noite do draft, não a temporada.
      S.anoFase = 0;
      S.forcaBase = ri(30, 85);
      S.confianca = Math.round(clamp(70 - S.pickDraft*0.6, 25, 85));
      S.salario = Math.max(1, Math.round(Math.pow((62 - S.pickDraft)/61, 2) * 26) + 1);
      // Contrato de calouro: três anos. Eram quatro, e com a janela mais
      // curta do resto da carreira o começo virava o trecho mais parado.
      S.contrato = 3;
      criarRival();

      // Quem chega assim chega PRONTO — é o ponto de ter uma entrada rara.
      // O prodígio já desembarca em nível de titular; o campeão europeu não
      // vem tão forte, mas vem com a confiança e o contrato de quem já
      // ganhou alguma coisa na vida adulta.
      if (S.destaque === "prodigio"){
        mexerOvr(Math.max(0, ri(6, 10)));
        S.confianca = 92; S.forcaBase = ri(45, 80); S.salario += 6;
      } else if (S.destaque === "euroliga"){
        mexerOvr(Math.max(0, ri(2, 5)));
        S.confianca = clamp(S.confianca + 15, 25, 95); S.salario += 3;
      }
    }
    S.draftRevelado = false;
    salvar();
  }

  // Antes da revelação a tela de fundo é só a sala de espera — o resultado
  // fica no popup, e ninguém lê o desfecho por cima do vidro fosco.
  if (!S.draftRevelado){
    app().innerHTML = topo() + `
      <h1>Noite do draft.</h1>
      <p class="lead">Seu nome está na lista. Agora é sentar e ouvir.</p>
      <div class="bpcard">
        <div class="bpcard-title">Ficha dos olheiros</div>
        <p class="dec-txt" style="margin:0">Comparam você a <b style="color:var(--red)">${esc(comparacaoDeDraft())}</b>.</p>
      </div>`;
    return abrirDraft();
  }

  if (S.pickDraft > 60){ S.fase = "semdraft"; salvar(); return telaSemDraft(); }
  // Fechar o popup já entra em quadra: a tela de resultado repetia o que o
  // popup acabou de mostrar e pedia mais um clique pra nada.
  S.fase = "liga"; salvar();
  return jogarAno();
}

/**
 * A revelação do draft, num popup com o número rolando.
 *
 * A posição já está decidida quando este popup abre — a rolagem é teatro, e
 * é de propósito: o que faz a noite do draft valer alguma coisa é o segundo
 * antes de ouvir o número, não o número. Quem não quiser esperar clica no
 * número e ele crava na hora.
 *
 * O timer é curto e o botão de continuar aparece junto com o resultado, sem
 * depender de um segundo temporizador: aba em segundo plano estrangula
 * setTimeout pra um por segundo, e uma animação que não termina vira um jogo
 * travado.
 */
function abrirDraft(){
  document.querySelector('.modal-fundo')?.remove();
  const cx = document.createElement('div');
  cx.className = 'modal-fundo';
  cx.innerHTML = `<div class="modal-cx modal-draft">
    <div class="dr-eyebrow">Escolha nº</div>
    <div class="dr-num rolando" id="drNum">--</div>
    <p class="dr-espera" id="drEspera">O comissário está com o cartão na mão.</p>
    <div id="drCorpo"></div>
  </div>`;
  document.body.appendChild(cx);

  const num = document.getElementById('drNum');
  const semAnimacao = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  let giros = 0, timer = null;

  const cravar = () => {
    if (timer){ clearInterval(timer); timer = null; }
    num.onclick = null;
    num.textContent = S.pickDraft > 60 ? "--" : S.pickDraft;
    num.className = 'dr-num parou';
    document.getElementById('drEspera').textContent =
      S.pickDraft > 60 ? "As 60 escolhas passaram."
      : S.destaque === "prodigio" ? "A liga inteira já sabia seu nome."
      : S.destaque === "euroliga" ? "Um título europeu no currículo pesou."
      : "Chamaram seu nome.";
    document.getElementById('drCorpo').innerHTML = S.pickDraft > 60 ? `
      <div class="dr-time">
        <b>Ninguém chamou seu nome.</b>
        <span>Isso não quer dizer que não vão chamar.</span>
      </div>
      <button class="btn" onclick="fecharDraft()">Ver as propostas</button>` : `
      <div class="dr-time">
        ${marca(S.time, 54)}
        <b>${esc(S.time)}</b>
        <span>${S.gm ? `GM: ${esc(S.gm)} · ` : ""}${esc(S.liga || "")} · $${S.salario}M por ano</span>
        <span class="dr-ficha">Os olheiros comparam você a <b>${esc(comparacaoDeDraft())}</b>.</span>
      </div>
      <button class="btn" onclick="fecharDraft()">Primeira temporada</button>`;
  };

  if (semAnimacao) return cravar();
  num.style.cursor = 'pointer';
  num.title = 'Clique pra pular';
  num.onclick = cravar;
  timer = setInterval(() => {
    num.textContent = ri(1, 60);
    if (++giros >= 22) cravar();
  }, 65);
}

function fecharDraft(){
  document.querySelector('.modal-fundo')?.remove();
  S.draftRevelado = true;
  salvar();
  telaDraft();
}

/**
 * A janela de quem ficou de fora: três clubes, um cartão cada, sem hierarquia
 * visual entre eles — a escolha é de verdade e nenhuma opção é "a certa".
 */
function telaSemDraft(){
  if (!S.semDraft) { S.semDraft = ofertasSemDraft(); salvar(); }
  const ofertas = S.semDraft;
  app().innerHTML = topo() + `
    <h1>Ninguém chamou seu nome.</h1>
    <p class="lead">As 60 escolhas passaram e você continuou sentado. A liga não te quis agora —
    isso não quer dizer que não vá querer. Escolha onde jogar até ela olhar de novo.</p>
    <div class="bpcard">
      <div class="bpcard-title">Janela de transferências</div>
      <p class="dec-txt" style="margin:0 0 4px">Chegaram propostas de fora da liga. Você aceita uma delas.</p>
    </div>
    <div class="ofertas-grade">
      ${ofertas.map((of,i)=>`
        <button class="oferta-card" onclick="assinarSemDraft(${i})">
          <span class="oferta-topo">Assinar com</span>
          <span class="oferta-time">${esc(of.time)}</span>
          <span class="oferta-marca">${marca(of.time, 54)}</span>
          <span class="oferta-liga">${esc(of.liga)}</span>
          <span class="oferta-num">$${of.salario}M<small>por ano · ${of.anos} ${of.anos === 1 ? "ano" : "anos"}</small></span>
          <span class="oferta-nota">${esc(of.nota)}</span>
        </button>`).join("")}
    </div>
    <p class="nota-txt">Jogando bem lá fora, a liga chama. Da G League o caminho é mais curto —
    e o salário, menor.</p>`;
}

function barra(){
  const p = clamp(((S.idade - 16) / 22) * 100, 0, 100);
  return `<div class="barra-topo"><i style="width:${p}%"></i></div>`;
}

/**
 * A ficha em miniatura, grudada no alto da tela.
 *
 * O cartão de OVR fica no começo da página e sumia assim que a pessoa
 * descia pra ler a trajetória ou pra decidir — justamente quando saber em
 * que nível você está é o que muda a escolha. Esta faixa é o resumo dele:
 * OVR, o que mudou no ano, o clube e a idade.
 *
 * Ela só aparece DEPOIS que o cartão de verdade sai de vista (ver
 * vigiarFicha). Mostrar as duas coisas ao mesmo tempo seria dizer o mesmo
 * número duas vezes na mesma tela.
 */
function fichaFixa(){
  const {o, d} = deltaOvr();
  const faixa = faixaOvr(o);
  const cor = d > 0 ? "var(--green)" : d < 0 ? "var(--red)" : "var(--text3)";
  const clube = String(S.time || "").split(" · ")[0];
  return `<div class="ficha-fixa" id="fichaFixa" hidden>
    <span class="ff-ovr" style="--cor:${faixa[1]}">${o}</span>
    ${d ? `<i class="ff-delta" style="color:${cor}">${d > 0 ? "+" : ""}${d}</i>` : ""}
    <span class="ff-clube">${marca(clube, 18)}<b>${esc(clube)}</b></span>
    <span class="ff-idade">${S.idade} anos</span>
  </div>`;
}

/**
 * Acende a faixa quando o cartão de verdade sai da tela.
 *
 * IntersectionObserver e não scroll: o navegador avisa quando o elemento
 * cruza a borda, sem um listener rodando a cada pixel rolado.
 */
let _olhoDaFicha = null;
function vigiarFicha(){
  if (_olhoDaFicha) { _olhoDaFicha.disconnect(); _olhoDaFicha = null; }
  const fixa = document.getElementById("fichaFixa");
  const real = document.querySelector(".bl-ficha .ficha");
  if (!fixa || !real || typeof IntersectionObserver === "undefined") return;

  // rootMargin negativo no topo: a faixa entra quando o cartão passa da
  // altura da barra de cima, não quando ele some da tela inteira.
  // A barra de cima também é grudada no celular, e as duas em top:0 se
  // sobrepõem — a faixa nascia ATRÁS dela, invisível. A altura vem medida
  // porque muda com a fonte do sistema, e no desktop a barra não gruda:
  // lá o valor é zero e a faixa encosta no alto da coluna.
  const barraDeCima = document.querySelector(".topbar");
  const alturaDoTopo = barraDeCima && getComputedStyle(barraDeCima).position === "sticky"
    ? Math.round(barraDeCima.getBoundingClientRect().height) : 0;
  document.documentElement.style.setProperty("--topo-h", alturaDoTopo + "px");

  _olhoDaFicha = new IntersectionObserver(
    ([e]) => { fixa.hidden = e.isIntersecting; },
    {threshold: 0, rootMargin: `-${alturaDoTopo + 4}px 0px 0px 0px`}
  );
  _olhoDaFicha.observe(real);
}

// ═══════════════════════════════════════════════════════════════════════
// O RIVAL
//
// Um jogador da sua posição, draftado no mesmo ano, cuja temporada aparece
// do lado da sua todo ano. É o que dá espinha a uma carreira: sem ele os
// anos são uma lista de números soltos; com ele existe alguém pra passar.
//
// Ele não é simulado em detalhe — tem OVR, potencial e uma linha por ano.
// Simular o rival como um segundo jogador completo custaria caro e não
// mudaria nada do que aparece na tela.
// ═══════════════════════════════════════════════════════════════════════

function criarRival(){
  const pool = (ATLETAS[S.pos] || ATLETAS.SF).filter(p => p[0] !== S.nome);
  const p = pick(pool);
  const meu = ovr(S.A, S.pos);
  S.rival = {
    nome: p[0],
    // Nasce coladinho em você: a graça é a disputa, não a goleada.
    ovr: clamp(meu + ri(-3, 4), 40, 92),
    pot: clamp(S.pot + ri(-7, 7), 62, 99),
    trofeus: {titulo:0, mvp:0, allstar:0},
    ult: null, totalPts: 0, anos: 0,
  };
}

/** A linha do rival no ano. Mesmos pesos de posição, sem os atributos. */
function statsDoRival(o, min){
  const p = POSICOES[S.pos].w, f = min / 32, uso = clamp((o - 60) / 40, 0, 1);
  const n1 = x => Math.round(x * 10) / 10;
  return {
    pts: n1(clamp((4.5 + uso*16 + (p.tres+p.fin)*11) * f + ri(-2,2), 1.5, 34)),
    reb: n1(clamp((1.5 + (p.fis+p.def)*13) * f + ri(-1,1), 0.5, 17)),
    ast: n1(clamp((0.8 + p.pas*22) * f + ri(-1,1), 0.2, 13)),
    jogos: ri(0,100) < 12 ? ri(38,64) : ri(68,82),
  };
}

/** Um ano na vida dele. Cresce e envelhece pela mesma régua que você. */
function anoDoRival(){
  const r = S.rival;
  if (!r) return;
  const falta = r.pot - r.ovr;
  const d = S.idade <= 23 ? (falta > 0 ? ri(0,2) + Math.round(falta*0.26) : 0)
          : S.idade <= 27 ? (falta > 0 ? ri(0,1) + Math.round(falta*0.16) : 0)
          : S.idade <= 31 ? (falta > 2 ? Math.round(falta*0.07) : -ri(0,1))
          : -ri(2, 2 + Math.floor((S.idade-31)*0.9));
  r.ovr = clamp(r.ovr + d, 35, 99);

  const min = clamp((r.ovr - 55) * 0.62 + 22, 12, 38);
  r.ult = statsDoRival(r.ovr, min);
  r.totalPts += Math.round(r.ult.pts * r.ult.jogos);
  r.anos++;

  if (r.ovr >= 82 && ri(0,100) < 32) r.trofeus.allstar++;
  if (r.ovr >= 91 && ri(0,100) < 13) r.trofeus.mvp++;
  if (ri(0,100) < 7 + Math.max(0, r.ovr - 86)) r.trofeus.titulo++;
}

// ═══════════════════════════════════════════════════════════════════════
// TOTAIS E MARCAS
//
// Média por jogo diz como você jogava; total diz quanto tempo você durou.
// São as duas metades de uma carreira longa, e só a primeira existia.
// ═══════════════════════════════════════════════════════════════════════

function totaisDeCarreira(){
  return S.temporadas.filter(t => !t.formacao && !t.perdida).reduce((a, t) => ({
    pts:   a.pts   + Math.round((t.pts||0) * (t.jogos||0)),
    reb:   a.reb   + Math.round((t.reb||0) * (t.jogos||0)),
    ast:   a.ast   + Math.round((t.ast||0) * (t.jogos||0)),
    jogos: a.jogos + (t.jogos||0),
  }), {pts:0, reb:0, ast:0, jogos:0});
}

const MARCAS = [
  ["pts",  5000, "5 mil pontos na carreira"],
  ["pts", 10000, "10 mil pontos — você virou nome de tabela"],
  ["pts", 15000, "15 mil pontos"],
  ["pts", 20000, "20 mil pontos. Esse é o clube dos imortais"],
  ["pts", 25000, "25 mil pontos"],
  ["pts", 30000, "30 mil pontos. Um punhado de gente chegou aqui, e agora você"],
  ["reb",  3000, "3 mil rebotes"],
  ["reb",  6000, "6 mil rebotes"],
  ["reb", 10000, "10 mil rebotes — território de pivô lendário"],
  ["ast",  2500, "2.500 assistências"],
  ["ast",  5000, "5 mil assistências"],
  ["ast",  8000, "8 mil assistências — top 10 da história"],
  ["jogos", 500, "500 jogos"],
  ["jogos",1000, "1.000 jogos. Durar também é talento"],
  ["jogos",1300, "1.300 jogos. Quase ninguém aguenta tanto"],
];

/** Marcas cruzadas nesta temporada, na ordem em que caem. */
function marcasNovas(){
  const t = totaisDeCarreira();
  const ja = S.marcasBatidas || (S.marcasBatidas = []);
  const novas = [];
  MARCAS.forEach(([campo, alvo, texto], i) => {
    if (ja.includes(i) || t[campo] < alvo) return;
    ja.push(i);
    novas.push(texto);
  });
  return novas;
}

// ═══════════════════════════════════════════════════════════════════════
// DESAFIOS
//
// Diferente das MARCAS: marca é um número que cai no meio da temporada e
// vira recado; desafio é do JOGADOR e fica guardado no banco, atravessando
// carreiras. É o que dá motivo pra começar a próxima.
//
// Cada um testa o estado inteiro (S) e devolve true/false. Sem efeito
// colateral nenhum dentro do teste: eles rodam várias vezes por partida.
// ═══════════════════════════════════════════════════════════════════════
const DESAFIOS = [
  {id:"anel",        i:"🏆", n:"Anel no dedo",       d:"Ganhe um título."},
  {id:"tricampeao",  i:"👑", n:"Dinastia",           d:"Ganhe três títulos na mesma carreira."},
  {id:"mvp",         i:"⭐", n:"O melhor do mundo",  d:"Ganhe um MVP da temporada."},
  {id:"mvp3",        i:"🔥", n:"Reinado",            d:"Ganhe três MVPs."},
  {id:"fmvp",        i:"💍", n:"Dono das finais",    d:"Ganhe o MVP das Finais."},
  {id:"dpoy",        i:"🛡️", n:"Muralha",            d:"Ganhe o Defensor do Ano."},
  {id:"roy",         i:"🌱", n:"Chegou pronto",      d:"Ganhe o Calouro do Ano."},
  {id:"top5",        i:"🎖️", n:"Top 5 do draft",     d:"Seja escolhido entre os cinco primeiros."},
  {id:"pick1",       i:"🥇", n:"Primeira escolha",   d:"Seja a escolha número 1 do draft."},
  {id:"allstar5",    i:"🎪", n:"Presença garantida", d:"Seja All-Star cinco vezes."},
  {id:"pts30k",      i:"🎯", n:"Vinte e dois mil",   d:"Marque 22 mil pontos na carreira."},
  {id:"duplo20k",    i:"📊", n:"Números redondos",   d:"15 mil pontos e 7 mil rebotes na mesma carreira."},
  {id:"ovr99",       i:"💯", n:"Teto",               d:"Chegue a 97 de overall."},
  {id:"porta_fundos",i:"🚪", n:"Pela porta dos fundos", d:"Ganhe um título sem ter sido draftado — saia no primeiro ano pra ficar fora das 60."},
  {id:"chamado",     i:"📞", n:"A liga ligou",       d:"Seja chamado pela liga vindo de fora dela."},
  {id:"lenda_clube", i:"♾️", n:"Lenda do clube",     d:"Jogue dez temporadas ou mais em um clube só."},
  {id:"nomade",      i:"🧳", n:"Nômade",             d:"Jogue por seis clubes diferentes."},
  {id:"selecao",     i:"🏅", n:"Herói nacional",     d:"Ganhe um ouro pela seleção."},
  {id:"euroliga",    i:"🌍", n:"Rei da Europa",      d:"Ganhe uma Euroliga."},
  {id:"ferro",       i:"🦾", n:"Ferro",              d:"Jogue vinte temporadas."},
  {id:"de_pe",       i:"🩹", n:"De pé",              d:"Perca uma temporada inteira e volte a ganhar prêmio."},
  {id:"estreia",     i:"🚀", n:"Chegou chegando",    d:"Média de 20 pontos na temporada de estreia."},
  {id:"trinta_no_ano",i:"🔥", n:"Trinta por noite",  d:"Média de 27 pontos numa temporada."},
  {id:"ano_perfeito",i:"✨", n:"O ano perfeito",     d:"Seja MVP e campeão na mesma temporada."},
  {id:"ringless",    i:"🕳️", n:"Ringless",           d:"Encerre uma carreira de dez temporadas sem nenhum título."},
  {id:"imortal",     i:"🗿", n:"Imortal",            d:"Encerre uma carreira com 120 de legado ou mais."},
  // Os impossíveis exigem várias coisas raras na MESMA carreira — é isso
  // que os separa dos difíceis, que pedem uma coisa rara só.
  {id:"goat",          i:"🐐", n:"O maior de todos",  d:"Na mesma carreira: 4 títulos, 2 MVPs, 20 mil pontos e um ouro pela seleção."},
  {id:"quarenta_mil",  i:"🏹", n:"Trinta mil",         d:"Marque 30 mil pontos na carreira."},
  {id:"duplo_completo",i:"🧮", n:"Números completos", d:"18 mil pontos, 8 mil rebotes e 4 mil assistências na mesma carreira."},
  {id:"so_uma_camisa", i:"👕", n:"Uma camisa só",     d:"Catorze temporadas no mesmo clube."},
  {id:"campeao_4",     i:"🧿", n:"Campeão por toda parte", d:"Quatro títulos, por três franquias diferentes."},
  {id:"mala_pronta",   i:"✈️", n:"Mala sempre pronta", d:"Jogue por dez clubes diferentes."},
  {id:"dono_defesa",   i:"🚧", n:"Dono da defesa",    d:"Ganhe quatro vezes o Defensor do Ano."},
  {id:"presenca12",    i:"🎟️", n:"Cadeira cativa",    d:"Seja All-Star dez vezes."},
  {id:"patria",        i:"🇧🇷", n:"Pela pátria",       d:"Ganhe três ouros olímpicos pela seleção."},
  {id:"lenda_viva",    i:"🏛️", n:"Lenda viva",        d:"Encerre uma carreira com 160 de legado — o teto é 230."},
  {id:"dinastia_solo", i:"🏛️", n:"Dono da franquia",  d:"Doze temporadas num clube só, com 3 títulos por ele."},
  {id:"pico_e_anel",   i:"👑", n:"Teto com anel",     d:"Chegue a 97 de overall e ganhe um título na mesma carreira."},
  // Lendários — refazer a carreira de um nome específico, inteira. São os
  // únicos que pagam FBA points em vez de moeda de games.
  {id:"proj_jordan",  i:"🔱", n:"Projeto Jordan",  d:"4 títulos, 3 MVPs das Finais e 2 MVPs na mesma carreira."},
  {id:"proj_russell", i:"🎖️", n:"Projeto Russell", d:"Encerre a carreira com seis títulos."},
  {id:"proj_lebron",  i:"🧭", n:"Projeto LeBron",  d:"Campeão por três franquias diferentes, com um MVP no currículo."},
  {id:"proj_duncan",  i:"🧱", n:"Projeto Duncan",  d:"Quatro títulos por uma franquia só."},
  {id:"proj_curry",   i:"🏹", n:"Projeto Curry",   d:"3 títulos, o arremesso de 3 no teto (97) e dois prêmios de cestinha."},
  {id:"proj_kobe",    i:"🐍", n:"Projeto Kobe",    d:"3 títulos e 13 temporadas na mesma franquia."},
];

/** Nível e prêmio de um desafio, direto do catálogo do servidor. */
function nivelDoDesafio(id){ return (window.__NIVEL_DO_DESAFIO__ || {})[id] || "medio"; }
function moedaDoDesafio(id){ return (window.__MOEDA_DO_NIVEL__ || {})[nivelDoDesafio(id)] || 0; }
function fbaDoDesafio(id){   return (window.__FBA_DO_NIVEL__   || {})[nivelDoDesafio(id)] || 0; }
/** O prêmio escrito: FBA point onde houver, moeda no resto. */
function premioDoDesafio(id){
  const fba = fbaDoDesafio(id);
  return fba ? `${fba} FBA points` : `${moedaDoDesafio(id)} moedas`;
}
const ROTULO_NIVEL = {facil:"Fácil", medio:"Médio", dificil:"Difícil", impossivel:"Impossível", lendario:"Lendário"};

/** Só temporadas de verdade — formação e ano perdido não contam. */
function temporadasJogadas(){
  return (S.temporadas || []).filter(t => !t.formacao && !t.perdida);
}

/**
 * Quantos títulos a carreira ganhou POR clube.
 *
 * O contador de troféus só guarda o total; quem ganhou por quem está
 * nas temporadas, em campeao + time. Sem isto não dá pra separar
 * "5 títulos" de "5 títulos pelo mesmo time".
 */
function titulosPorClube(){
  const out = {};
  temporadasJogadas().forEach(x => {
    if (x.campeao && x.time) out[x.time] = (out[x.time] || 0) + 1;
  });
  return out;
}

function testarDesafio(id, fim){
  const t = S.trofeus || {};
  const tot = totaisDeCarreira();
  const jogadas = temporadasJogadas();
  const clubes = new Set(jogadas.map(x => x.time).filter(Boolean));

  switch (id){
    case "anel":        return (t.titulo||0) >= 1;
    case "tricampeao":  return (t.titulo||0) >= 3;
    case "mvp":         return (t.mvp||0) >= 1;
    case "mvp3":        return (t.mvp||0) >= 3;
    case "fmvp":        return (t.fmvp||0) >= 1;
    case "dpoy":        return (t.dpoy||0) >= 1;
    case "roy":         return (t.roy||0) >= 1;
    case "top5":        return (S.pickDraft||99) <= 5;
    case "pick1":       return (S.pickDraft||99) === 1;
    case "allstar5":    return (t.allstar||0) >= 5;
    // Os alvos saem de 1.320 carreiras completas simuladas, e não do olho.
    // Os antigos (20 mil pontos, 10 mil + 5 mil) caíam em 90% e 94% das
    // carreiras: não eram desafio, eram o caminho.
    case "pts30k":      return tot.pts >= 22000;
    case "duplo20k":    return tot.pts >= 15000 && tot.reb >= 7000;
    case "quarenta_mil":return tot.pts >= 30000;
    case "ovr99":       return ovr(S.A, S.pos) >= 97;
    case "porta_fundos":return (S.pickDraft||0) > 60 && (t.titulo||0) >= 1;
    case "chamado":     return !!S.jaFoiChamado;
    case "nomade":      return clubes.size >= 6;
    case "selecao":     return (t.ouro||0) >= 1 || (t.ouroCopa||0) >= 1;
    case "euroliga":    return (t.euro||0) >= 1;
    case "ferro":       return jogadas.length >= 20;
    case "de_pe":       return !!S.voltouComPremio;
    case "estreia":     return jogadas.length >= 1 && (jogadas[0].pts||0) >= 20;
    // O 20/8/8 numa temporada não existia neste motor: o teto medido de
    // assistências por ano é 9,3, e só pra armador. Virou o que o jogo de
    // fato permite e ainda é raro — média de 30 pontos num ano (1,3%).
    case "trinta_no_ano": return jogadas.some(x => (x.pts||0) >= 27);
    case "duplo_completo":return tot.pts >= 18000 && tot.reb >= 8000 && tot.ast >= 4000;
    case "ano_perfeito":  return jogadas.some(x => x.campeao &&
                            (x.premios||[]).some(q => String(q && q.t ? q.t : q) === "MVP"));
    case "dono_defesa":   return (t.dpoy||0) >= 4;
    case "presenca12":    return (t.allstar||0) >= 10;
    case "patria":        return (t.ouro||0) >= 3;
    case "mala_pronta":   return clubes.size >= 10;
    case "campeao_4":     return Object.keys(titulosPorClube()).length >= 3 && (t.titulo||0) >= 4;
    case "lenda_clube": {
      const porClube = {};
      jogadas.forEach(x => { if (x.time) porClube[x.time] = (porClube[x.time]||0) + 1; });
      return Object.values(porClube).some(n => n >= 10);
    }
    case "pico_e_anel": return (S.picoOvr || 0) >= 97 && (t.titulo||0) >= 1;
    case "goat":        return (t.titulo||0) >= 4 && (t.mvp||0) >= 2 && tot.pts >= 20000
                            && ((t.ouro||0) >= 1 || (t.ouroCopa||0) >= 1);
    case "dinastia_solo": {
      const porClube = {};
      jogadas.forEach(x => { if (x.time) porClube[x.time] = (porClube[x.time]||0) + 1; });
      // O título tem que ser POR ele: quinze anos de casa e três anéis na
      // carreira, sem ter saído pra ganhar em outro lugar.
      return Object.values(porClube).some(n => n >= 12) && (t.titulo||0) >= 3
          && Object.keys(porClube).length <= 2;
    }
    // Os dois de encerramento só valem no fim: no meio da carreira ainda dá
    // tempo de ganhar o título que falta.
    case "ringless":    return fim && jogadas.length >= 10 && (t.titulo||0) === 0;
    case "imortal":     return fim && pontuacaoLegado() >= 120;
    case "lenda_viva":  return fim && pontuacaoLegado() >= 160;
    // ── Lendários ──────────────────────────────────────────────────────
    // Todos olham título POR FRANQUIA, que sai de temporadas[].campeao +
    // temporadas[].time — o contador t.titulo só sabe o total da carreira.
    // Os números caíram pro que o motor alcança: 6 títulos + 6 MVPs das
    // Finais + 5 MVPs e 11 títulos NUNCA saíram em 1.320 carreiras (o
    // recorde medido é 8 títulos, 5 FMVPs e 6 MVPs, e nunca juntos).
    case "proj_jordan":  return (t.titulo||0) >= 4 && (t.fmvp||0) >= 3 && (t.mvp||0) >= 2;
    // Só no fim: no meio da carreira ainda dá tempo de chegar aos sete.
    case "proj_russell": return fim && (t.titulo||0) >= 6;
    case "proj_lebron":  return Object.keys(titulosPorClube()).length >= 3 && (t.mvp||0) >= 1;
    case "proj_duncan":  return Object.values(titulosPorClube()).some(n => n >= 4);
    // A carreira não conta bolas de 3 convertidas — o que existe é o
    // atributo. "Recorde histórico" vira o teto do atributo: 99 de arremesso
    // de 3 em algum momento, que é o máximo que a régua do jogo alcança.
    case "proj_curry":   return (t.titulo||0) >= 3 && (S.picoTres || 0) >= 97 && (t.cesta||0) >= 2;
    case "proj_kobe": {
      const porClube = {};
      jogadas.forEach(x => { if (x.time) porClube[x.time] = (porClube[x.time]||0) + 1; });
      return (t.titulo||0) >= 3 && Object.values(porClube).some(n => n >= 13);
    }
    case "so_uma_camisa": {
      const porClube = {};
      jogadas.forEach(x => { if (x.time) porClube[x.time] = (porClube[x.time]||0) + 1; });
      return Object.values(porClube).some(n => n >= 14);
    }
  }
  return false;
}

/**
 * Roda a lista, guarda o que caiu e avisa o servidor.
 *
 * O mapa de conquistados vive em window.__DESAFIOS__ (veio do banco) e é
 * atualizado aqui na hora — a tela mostra a conquista antes do POST voltar,
 * e se o POST falhar a próxima temporada tenta de novo.
 */
function checarDesafios(fim){
  const feitos = window.__DESAFIOS__ || (window.__DESAFIOS__ = {});
  const novos = [];
  DESAFIOS.forEach(d => {
    if (feitos[d.id]) return;
    let caiu = false;
    try { caiu = testarDesafio(d.id, !!fim); } catch (e) { caiu = false; }
    if (!caiu) return;
    const agora = new Date();
    feitos[d.id] = `${String(agora.getDate()).padStart(2,"0")}/${String(agora.getMonth()+1).padStart(2,"0")} ` +
                   `${String(agora.getHours()).padStart(2,"0")}:${String(agora.getMinutes()).padStart(2,"0")}`;
    novos.push(d);
  });
  if (!novos.length) return [];

  const corpo = new URLSearchParams();
  corpo.set("acao", "desafios");
  corpo.set("ids", JSON.stringify(novos.map(d => d.id)));
  fetch(location.pathname, {method:"POST", headers:{"Content-Type":"application/x-www-form-urlencoded"}, body:corpo})
    .catch(() => {});
  return novos;
}

/** Grade de conquistas — as suas e as que faltam, na mesma lista. */
function telaDesafios(){
  const feitos = window.__DESAFIOS__ || {};
  const total = DESAFIOS.length;
  const n = DESAFIOS.filter(d => feitos[d.id]).length;
  const ganho = DESAFIOS.filter(d => feitos[d.id]).reduce((a, d) => a + moedaDoDesafio(d.id), 0);
  const aGanhar = DESAFIOS.filter(d => !feitos[d.id]).reduce((a, d) => a + moedaDoDesafio(d.id), 0);
  // FBA point é outra moeda: circula no site, não nos games. Somar com as
  // moedas num total só daria um número que não existe em lugar nenhum.
  const fbaGanho   = DESAFIOS.filter(d =>  feitos[d.id]).reduce((a, d) => a + fbaDoDesafio(d.id), 0);
  const fbaNaMesa  = DESAFIOS.filter(d => !feitos[d.id]).reduce((a, d) => a + fbaDoDesafio(d.id), 0);

  // Do mais fácil pro mais difícil: quem abre a tela quer saber o que dá
  // pra buscar agora, não o que talvez nunca aconteça.
  const ordem = {facil:0, medio:1, dificil:2, impossivel:3, lendario:4};
  const lista = DESAFIOS.slice().sort((a, b) =>
    (ordem[nivelDoDesafio(a.id)] - ordem[nivelDoDesafio(b.id)])
    || (!!feitos[b.id] - !!feitos[a.id]));

  app().innerHTML = topo() + `
    <div class="desafios-topo">
      <h1 style="margin:0">Conquistas da carreira</h1>
      <span class="desafios-conta">${n} de ${total}</span>
    </div>
    <div class="desafios-barra"><i style="width:${Math.round(n/total*100)}%"></i></div>
    <p class="lead">Elas são suas, não da carreira: ficam guardadas depois que você pendura as chuteiras.
    Cada uma paga moeda uma vez só, na primeira vez que cai.</p>
    <div class="desafios-saldo">
      <span><b>${ganho}</b>moedas ganhas</span>
      <span><b>${aGanhar}</b>ainda na mesa</span>
      ${fbaGanho || fbaNaMesa ? `<span class="saldo-fba"><b>${fbaGanho}</b>FBA points · ${fbaNaMesa} na mesa</span>` : ""}
    </div>
    <div class="desafios-grade">
      ${lista.map(d => {
        const q = feitos[d.id];
        const nivel = nivelDoDesafio(d.id);
        return `<div class="desafio ${q ? "feito" : ""} nv-${nivel}">
          <span class="desafio-icone" data-id="${d.id}">${d.i}</span>
          <span class="desafio-txt">
            <b>${esc(d.n)}</b>
            <small>${esc(d.d)}</small>
            <span class="desafio-pe">
              <em class="desafio-nivel">${ROTULO_NIVEL[nivel] || nivel}</em>
              <em class="desafio-moeda">${premioDoDesafio(d.id)}</em>
            </span>
          </span>
          ${q ? `<span class="desafio-data">${esc(q)}</span>` : ""}
        </div>`;
      }).join("")}
    </div>
    <button class="btn btn2" style="margin-top:16px" onclick="render()">Voltar</button>`;
}

/**
 * Um desfecho ajuda, atrapalha, ou nenhum dos dois?
 *
 * A cor sai DAQUI e não de "é o lado bom da aposta": em joelho, o lado
 * seguro é operar, e operar custa uma temporada. Pintar de verde porque é
 * a opção cautelosa mentiria sobre o que vai acontecer.
 */
function corDoEfeito(ef){
  if (!ef) return "neutro";
  if (ef.fora) return "ruim";
  // Perder o degrau é a notícia mais pesada que uma carta pode dar. Vem
  // antes de tudo, inclusive de um OVR positivo na mesma carta: "+2 OVR e
  // cai de divisão" não é um desfecho verde.
  if (ef.queda) return "ruim";
  // Ser cortado com proposta na mesa não é a mesma notícia que ser cortado
  // sem nenhuma. Pintar as duas de vermelho apagava a aposta 45/55 da
  // crise: os dois chips saíam iguais e a escolha parecia não ter lado.
  if (ef.dispensa) return ef.resgate ? "neutro" : "ruim";
  // O teto só desce, e um teto que desce é sempre notícia ruim.
  const dPot = ef.potReal == null ? potRealizavel(ef.pot || 0) : ef.potReal;
  if (dPot < 0) return "ruim";
  if (ef.time === "melhor") return "bom";
  if (ef.time === "pior") return "ruim";
  // O OVR pesa pelo que vai MESMO acontecer: encostado no teto, um "+3"
  // não move nada e não pode sair verde.
  const dOvr = ef.ovrReal == null ? ovrRealizavel(ef.ovr || 0) : ef.ovrReal;
  if (dOvr > 0) return "bom";
  if (dOvr < 0) return "ruim";
  if ((ef.granaPct || 0) < 0 || (ef.grana || 0) < 0 || (ef.salario || 0) < 0) return "ruim";
  if ((ef.granaPct || 0) > 0 || (ef.grana || 0) > 0 || (ef.salario || 0) > 0) return "bom";
  if ((ef.elenco || 0) !== 0) return ef.elenco > 0 ? "bom" : "ruim";
  if ((ef.contrato || 0) > 0) return "bom";
  if ((ef.hype || 0) !== 0) return ef.hype > 0 ? "bom" : "ruim";
  // Confiança é o último critério: ela sozinha não muda o jogador, mas muda
  // os minutos — e um desfecho que só mexe nela precisa de cor, senão a
  // decisão de ficar ou pedir troca sai cinza dos dois lados.
  if ((ef.conf || 0) > 0) return "bom";
  if ((ef.conf || 0) < 0) return "ruim";
  return "neutro";
}

/**
 * As duas pontas da aposta, cada uma no seu cartão.
 *
 * `caiu` é "bom" ou "ruim" depois do sorteio, e null antes dele: enquanto
 * a pessoa não escolheu, os dois cartões valem o mesmo.
 */
function chipsDaAposta(op, caiu){
  const c = op.chance ?? 100;
  const cartao = (ef, pct, lado) => {
    if (!ef) return "";
    const marca = caiu == null ? "" : (caiu === lado ? " caiu" : " apagado");
    return `<span class="chip-ap chip-${corDoEfeito(ef)}${marca}">
      <b>${pct}%</b><i>${esc(dizEfeito(ef))}</i></span>`;
  };
  return `<span class="op-chips">${cartao(op.bom, c, "bom")}`
       + (c >= 100 ? "" : cartao(op.ruim, 100 - c, "ruim")) + `</span>`;
}

/**
 * Faixas de OVR: vermelho embaixo, verde subindo, roxo no topo.
 *
 * A cor existe porque o número sozinho não informa. "74" não diz se a
 * carreira vai bem; "74 titular" em amarelo diz na hora, sem ler nada.
 * Da maior pra menor, que é como o find() abaixo depende.
 */
const FAIXAS_OVR = [
  [96, "#a855f7", "lendário"],
  [90, "#22c55e", "elite"],
  [84, "#4ade80", "estrela"],
  [76, "#eab308", "titular"],
  [60, "#f97316", "rotação"],
  [ 0, "#ef4444", "reserva"],
];
function faixaOvr(o){ return FAIXAS_OVR.find(([min]) => o >= min) || FAIXAS_OVR[5]; }

/**
 * Com quem os olheiros te comparam na noite do draft.
 *
 * É o único momento em que o jogo diz, em uma frase, que tipo de jogador
 * ele acha que você é — e o teto de cada comparação é uma promessa que a
 * carreira pode ou não cumprir.
 */
const COMPARACOES = {
  PG:{alto:["Magic Johnson","Luka Dončić","Stephen Curry"], medio:["Jrue Holiday","Mike Conley","Darius Garland"], baixo:["T.J. McConnell","José Alvarado","Tyus Jones"]},
  SG:{alto:["Michael Jordan","Dwyane Wade","Devin Booker"],  medio:["Bradley Beal","Jaylen Brown","Anfernee Simons"], baixo:["Gary Harris","Alec Burks","Malik Beasley"]},
  SF:{alto:["LeBron James","Kevin Durant","Kawhi Leonard"],  medio:["Mikal Bridges","OG Anunoby","Michael Porter Jr."], baixo:["Torrey Craig","Josh Green","Kelly Oubre"]},
  PF:{alto:["Tim Duncan","Giannis Antetokounmpo","Dirk Nowitzki"], medio:["Julius Randle","Tobias Harris","John Collins"], baixo:["Jalen McDaniels","Grant Williams","Precious Achiuwa"]},
  C: {alto:["Nikola Jokić","Hakeem Olajuwon","Joel Embiid"], medio:["Jarrett Allen","Myles Turner","Nic Claxton"],  baixo:["Mason Plumlee","Daniel Gafford","Isaiah Hartenstein"]},
};
function comparacaoDeDraft(){
  if (S.comparacao) return S.comparacao;
  const t = COMPARACOES[S.pos] || COMPARACOES.SF;
  const faixa = S.pot >= 92 ? t.alto : S.pot >= 80 ? t.medio : t.baixo;
  S.comparacao = pick(faixa);
  return S.comparacao;
}

/** Variação de OVR desde o ano anterior, com sinal. */
function deltaOvr(){
  const o = ovr(S.A, S.pos);
  if (S.ovrAnterior == null) return {o, d:0};
  return {o, d: o - S.ovrAnterior};
}

const dormir = (ms) => new Promise(r => setTimeout(r, ms));
const semAnimacao = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches;

/**
 * O ano ACONTECE, em vez de aparecer pronto.
 *
 * Três coisas em sequência, na ordem em que se lê um boletim: o overall
 * corre até o número novo e FREIA nele; os números da temporada entram um a
 * um; e a linha da trajetória se preenche por último. Sem isso a temporada
 * inteira surgia de uma vez e não havia momento nenhum entre jogar o ano e
 * saber como ele foi.
 *
 * Roda solta (sem await de quem chama): é enfeite, e travar o jogo por causa
 * de enfeite seria pior que não ter enfeite nenhum.
 */
async function animarAno(de, para, idadeLinha){
  if (semAnimacao()) return;
  const num = document.querySelector('.ovr-caixa b');
  if (num && de != null && de !== para){
    const d = para - de;
    const passos = Math.min(Math.abs(d), 12);
    for (let i = 1; i <= passos; i++){
      num.textContent = Math.round(de + d * i / passos);
      // Rápido no começo, freando nos últimos: o que interessa é onde ele
      // para, não o caminho até lá.
      await dormir(80 + Math.round(340 * Math.pow(i / passos, 3)));
    }
    num.textContent = para;
    num.classList.add('cravou');
  }
  await preencherNumeros('.linha-stats .st b, .linha-mini .mini b', 26);
  await preencherLinha(idadeLinha);
}

/** Conta cada número de zero até o valor, um depois do outro. */
async function preencherNumeros(seletor, espera){
  const alvos = [...document.querySelectorAll(seletor)]
    .filter(e => /^[d.,]+$/.test((e.textContent||'').trim()));
  for (const e of alvos){
    const bruto = (e.textContent||'').trim();
    const alvo = Number(bruto.replace(',', '.'));
    if (!isFinite(alvo) || alvo <= 0) continue;
    const dec = bruto.includes(',') ? 1 : 0;
    const passos = 7;
    for (let i = 1; i <= passos; i++){
      const v = alvo * i / passos;
      e.textContent = dec ? v.toFixed(1).replace('.', ',') : String(Math.round(v));
      await dormir(espera);
    }
    e.textContent = bruto;
  }
}

/** A linha da trajetória do ano novo, coluna por coluna. */
async function preencherLinha(idade){
  const linha = document.querySelector(`.tj[data-idade="${idade}"]`);
  if (!linha) return;
  for (const c of [...linha.querySelectorAll('.tj-n[data-alvo]')]){
    const alvo = Number(c.dataset.alvo) || 0;
    const dec = String(c.dataset.alvo).includes('.');
    const passos = Math.min(Math.max(1, Math.round(alvo)), 8);
    for (let i = 1; i <= passos; i++){
      const v = alvo * i / passos;
      c.textContent = dec ? v.toFixed(1) : String(Math.round(v));
      await dormir(30);
    }
    c.textContent = c.dataset.alvo;
    c.classList.add('pronta');
    await dormir(60);
  }
}

/**
 * A ficha da carreira, no mesmo molde do Copero.
 *
 * Uma caixa só, lida de cima pra baixo: o overall grande à esquerda, o clube
 * e os números da vida ao lado, as etiquetas de identidade embaixo, a sala de
 * troféus, os totais de carreira e — por último — o boletim da temporada que
 * acabou. Era um empilhado de blocos soltos, cada um com uma régua; agora é a
 * mesma peça dos dois jogos, e o que muda entre eles é o esporte.
 */
function placar(st, rotuloAno, time, campanha, premios){
  const {o, d} = deltaOvr();
  const faixa = faixaOvr(o);
  const cor = d > 0 ? "var(--green)" : d < 0 ? "var(--red)" : "var(--text3)";
  const sinal = d > 0 ? "+" + d : d < 0 ? String(d) : "=";
  const clube = String(time || "").split(" · ")[0];
  const anos = temporadasJogadas().length;

  return `<div class="caixa ficha">
    <div class="ficha-marca" aria-hidden="true">${marca(clube, 140)}</div>
    <div class="ficha-topo">
      <div class="ovr-caixa" style="--cor:${faixa[1]}">
        <small>OVR</small><b>${o}</b>
        <i style="color:${cor}">${sinal}</i>
      </div>
      <div class="ficha-corpo">
        <div class="ficha-tags">
          <span class="tag">${bandeira(S.nac)} ${esc(S.nac)}</span>
          <span class="tag pos">${S.numero ? `#${esc(S.numero)} ` : ""}${esc(S.pos)}</span>
          ${(S.anosNoClube || 0) >= 6 ? `<span class="tag idolo">Ídolo da casa</span>` : ""}
        </div>
        <div class="ficha-info">
          <div class="ficha-clube" title="${esc(clube)}">
            ${marca(clube, 24)}<span>${esc(clube)}</span>
          </div>
          ${S.liga ? `<div class="ficha-liga">${esc(S.liga)}${
            S.foraDaLiga ? " · fora da liga" : ""}${S.gm ? ` · GM ${esc(S.gm)}` : ""}</div>` : ""}
        </div>
        <div class="ficha-num n-idade">Idade<b>${S.idade}</b></div>
        <div class="ficha-num n-salario">Salário<b>$${S.salario}M</b></div>
      </div>
    </div>

    <div class="ovr-linha" style="--cor:${faixa[1]}">
      <span class="ovr-esq"><span class="ovr-rot">Nível</span><span class="ovr-faixa">${faixa[2]}</span></span>
      <span class="ovr-barra"><i style="width:${clamp(o,0,99)}%"></i></span>
    </div>


    <div class="ficha-ano">
      <div class="ficha-ano-cab">${esc(rotuloAno)}${anos ? ` · ${anos}ª temporada` : ""}</div>
      <div class="linha-stats">
        <div class="st"><b>${st.pts}</b><span>pontos</span></div>
        <div class="st"><b>${st.reb}</b><span>rebotes</span></div>
        <div class="st"><b>${st.ast}</b><span>assist.</span></div>
      </div>
      <div class="linha-mini">
        <div class="mini"><b>${st.min}</b><span>minutos</span></div>
        <div class="mini"><b>${st.jogos}</b><span>jogos</span></div>
        <div class="mini"><b>${S.confianca}</b><span>confiança</span></div>
      </div>
      ${campanha || premios.length ? `<div class="ano-fecho">
        ${campanha ? `<div class="campanha">${campanha}</div>` : ""}
        ${premios.length ? `<div class="premios">${premios.map(p=>`<span class="pr ${p.k}">${esc(p.t)}</span>`).join("")}</div>` : ""}
      </div>` : ""}
    </div>
  </div>`;
}


// ═══════════════════════════════════════════════════════════════════════
// AFASTAMENTO — lesão grave e suspensão
// As duas custam o mesmo: a temporada inteira. O que muda é o que cobram.
// A lesão come o corpo; a suspensão come a reputação, e é ela que faz o
// mercado te tratar como problema quando você volta.
// ═══════════════════════════════════════════════════════════════════════

/** Tira o jogador de circulação por `anos` temporadas. Só decisões chamam. */
function afastar(tipo, anos, motivo){
  S.afastado = {tipo, anos, motivo};
}

/**
 * Uma temporada que não aconteceu: sem jogos, sem prêmios, sem número.
 *
 * O ano entra na súmula marcado com `perdida` e fica FORA da conta do
 * legado — um ano parado não pode pagar a longevidade que um ano jogado
 * paga. E o contrato não corre aqui: deixá-lo correr jogava um suspenso
 * no mercado, e nenhum time negocia com quem não pode entrar em quadra.
 */
function perderAno(){
  const af = S.afastado;
  const lesao = af.tipo === "lesao";

  // Antes de mexer nos atributos, senão o delta de OVR na tela mente.
  S.ovrAnterior = ovr(S.A, S.pos);

  S.idade++; S.ano++; S.anoFase++;
  S.dinheiro += lesao ? S.salario : 0;   // suspensão não recebe

  for (const k in S.A) S.A[k] = clamp(S.A[k] - ri(lesao?2:1, lesao?6:4), 25, 99);
  // A lesão custava −14 de confiança por temporada perdida. Com duas
  // temporadas seguidas isso levava a confiança pra faixa dos 20, e a reta
  // de minutosDoAno devolvia 17 minutos: os pontos caíam abaixo do corte de
  // ligaAindaQuer e a carreira acabava na renovação seguinte. Ou seja: a
  // carta prometia "1 temporada fora" e entregava o fim. −10 mantém a dor
  // sem transformar a volta num corte automático. Suspensão continua
  // custando mais: o problema dela é reputação, e é isso que ela cobra.
  S.confianca = clamp(S.confianca - (lesao ? 10 : 24), 5, 99);
  S.hype      = clamp(S.hype      - (lesao ? 10 : 20), 5, 99);

  S.temporadas.push({ano:S.ano, idade:S.idade, time:S.time, pts:0, reb:0, ast:0, min:0, jogos:0,
                     vit:0, ovr:ovr(S.A, S.pos), premios:[], campeao:false,
                     perdida:af.tipo, motivo:af.motivo});

  S.ultimo = {pts:0, reb:0, ast:0, min:0, jogos:0};
  S.ultimoOvr = ovr(S.A, S.pos);
  // O pico do arremesso de 3 anda junto com o do OVR: o atributo cai com
  // a idade, e o Projeto Curry pergunta pelo teto que a carreira alcançou,
  // não pelo que sobrou no fim dela.
  S.picoOvr = Math.max(S.picoOvr || 0, S.ultimoOvr);
  S.picoTres = Math.max(S.picoTres || 0, (S.A && S.A.tres) || 0);
  S.ultimosPremios = [];
  S.ultimaCampanha = `<i class="camp-selo camp-fora">${lesao ? "Temporada perdida" : "Suspenso"}</i><em>${esc(af.motivo)}</em>`;

  af.anos--;
  const liberado = af.anos <= 0;
  if (liberado) S.afastado = null;

  S.mensagem = liberado
    ? (lesao ? "Você passou o ano na sala de fisioterapia. Está liberado — e agora precisa provar que ainda é você."
             : "Suspensão cumprida. A liga te devolveu a quadra; o vestiário ainda não decidiu se te devolve o respeito.")
    : (lesao ? "A recuperação não fechou no prazo. Mais uma temporada fora."
             : "A punição não acabou. Mais uma temporada assistindo.");

  S.desfecho = null; S.efeitoDecisao = 0;
  S.aguardando = false; S.decisaoId = null;
  // Ano perdido corta o par do modo rápido: ficar fora é a notícia da
  // temporada e merece a tela inteira, não meio clique.
  S.parDoRitmo = 0;
  salvar(); telaTemporada();
}

function jogarAno(){
  if (S.afastado && S.afastado.anos > 0) return perderAno();

  const o = ovr(S.A, S.pos);
  const forca = forcaDoTime();
  const min = minutosDoAno(o);
  const st = statsDoAno(o, min, forca);

  // A campanha mistura o time e você — carregar time ruim é possível, e é
  // exatamente a história que dá gosto de contar.
  const vit = clamp(Math.round(forca*0.55 + (o-60)*0.55 + ri(-6,6)), 9, 73);
  const playoff = vit >= 41;

  S.ultimo = st; S.ultimoOvr = o; S.ultimaVit = vit;
  // O pico e o numero do cartao final: o OVR do ultimo ano nao conta a
  // historia de quem foi 92 aos 27 e se aposentou aos 38 com 74.
  S.picoOvr = Math.max(S.picoOvr || 0, o || 0);
  S.picoTres = Math.max(S.picoTres || 0, (S.A && S.A.tres) || 0);
  S.dinheiro += S.salario;
  S.idade++; S.ano++; S.anoFase++; S.contrato--;
  S.confianca = clamp(S.confianca + (st.pts > 14 ? 6 : -4), 5, 99);

  // Chegar à final é raro e é o único momento que vale jogar na mão. O
  // resto do playoff continua resolvido de uma vez — parar pra clicar em
  // toda série mataria o ritmo que o jogo depende.
  const chegaFinal = playoff && ri(0,100) < clamp((vit-46)*2.2 + (o-84)*1.4, 3, 32);
  if (chegaFinal){
    S.finais = {v:0, d:0, jogos:[], adversario: pick(timesDaLiga().filter(t=>t!==S.time))};
    salvar(); telaTemporada(); return abrirFinais();
  }

  S.ultimaCampanha = `<b>${vit}-${82-vit}</b><i class="camp-selo ${
    playoff ? "camp-playoff" : "camp-fora"}">${playoff ? "Playoffs" : "Fora"}</i>${
    playoff ? '<em>caiu antes das finais</em>' : ''}`;
  fecharAno(false, vit, o, st);
}

// ── SELEÇÃO NACIONAL ───────────────────────────────────────────────────
// Uma trilha de legado que corre em paralelo à liga, e que não depende do
// time em que você caiu no draft. Quem passou a carreira num time ruim tem
// aqui a chance de ter uma história — e é justamente por isso que ela vale
// menos que título: se pagasse igual, o caminho fácil seria não se importar
// com a liga.

/**
 * Ser trocado sem ter pedido.
 *
 * Só com contrato em vigor (sem contrato, quem decide é o mercado) e depois
 * de pelo menos uma temporada na casa. A chance sai do que de fato move uma
 * troca na liga: time que está perdendo e vai reconstruir, salário grande
 * demais pro que você entrega, e idade — veterano caro é o primeiro nome na
 * mesa. Fica em torno de 9% ao ano, que é a taxa da liga de verdade.
 *
 * Não é uma decisão: é um aviso. É esse o ponto.
 */
function tentarTroca(o){
  if (S.foraDaLiga || S.contrato <= 0) return null;
  if ((S.anosNoClube || 0) < 1) return null;
  if (S.temporadas.filter(x => !x.formacao).length < 2) return null;

  let ch = 4;
  if (S.forcaBase < 45) ch += 6;                       // time em reconstrução
  if (S.salario >= 22 && o < 86) ch += 5;              // contrato pesado demais
  if (S.idade >= 32) ch += 4;                          // veterano vira moeda
  if (S.confianca < 40) ch += 4;                       // relação desgastada
  if (o >= 90) ch -= 6;                                // ninguém troca o astro
  if (ri(0, 100) >= clamp(ch, 1, 30)) return null;

  const antigo = S.time;
  const novo = pick(timesDaLiga().filter(x => x !== S.time));
  if (!novo) return null;
  S.time = novo;
  S.anosNoClube = 0;
  S.forcaBase = ri(28, 88);
  S.confianca = clamp(S.confianca - 8, 5, 99);
  if (S.modo === "fba") S.gm = gmDoTime(novo);
  S.trocasSofridas = (S.trocasSofridas || 0) + 1;
  return `Você foi trocado. O ${antigo} te mandou pro ${novo} e ninguém te perguntou nada.`;
}

/** Força do elenco em volta na seleção. Decide quanto o time te ajuda. */
const SELECOES = {
  USA:94, ESP:82, SRB:80, FRA:78, CAN:77, AUS:74, GER:74, GRE:71,
  LTU:70, ARG:68, BRA:65, NGR:57,
};

/**
 * Que torneio cai neste ano.
 *
 * Olimpíada de 4 em 4, Copa do Mundo no meio do ciclo — o calendário real.
 * Nos outros dois anos não há convocação, e é isso que faz a medalha ser
 * rara sem precisar de sorteio baixo: só existem duas chances por ciclo.
 */
function torneioDoAno(ano){
  if (ano % 4 === 0) return {k:"oly",  nome:"Olimpíada",     peso:1.0};
  if (ano % 4 === 2) return {k:"copa", nome:"Copa do Mundo", peso:0.8};
  return null;
}

/**
 * A seleção te chama?
 *
 * O corte é relativo à força do país: os Estados Unidos só olham pra quem é
 * estrela, e uma seleção fraca chama quem for o melhor que ela tem. Isso faz
 * a mesma carreira ter destinos diferentes conforme a bandeira — que é o
 * ponto de a nacionalidade existir.
 */
function convocado(o){
  const forca = SELECOES[S.nac] ?? 65;
  const corte = 62 + Math.round((forca - 57) * 0.62);   // NGR ~62, USA ~85
  return o >= corte;
}

/**
 * Joga o torneio e devolve a medalha, ou null.
 *
 * Sua contribuição pesa menos que o elenco em volta, de propósito: um
 * jogador não ganha Olimpíada sozinho, e fingir que ganha tiraria o sentido
 * de escolher a bandeira lá no começo.
 */
function jogarTorneio(o, t){
  const forca = SELECOES[S.nac] ?? 65;
  const nota = forca * 0.72 + (o - 72) * 1.1 + ri(-14, 14);

  if (nota >= 78) return {m:"ouro",   rot:`Ouro na ${t.nome}`,   k:"titulo"};
  if (nota >= 70) return {m:"prata",  rot:`Prata na ${t.nome}`,  k:"ouro"};
  if (nota >= 63) return {m:"bronze", rot:`Bronze na ${t.nome}`, k:"ouro"};
  return null;
}

/**
 * O boletim do torneio pela seleção.
 *
 * São poucos jogos — a Olimpíada tem 6 e a Copa 8 — e é por isso que a
 * linha da seleção cresce devagar mesmo pra quem é convocado sempre. O
 * rendimento sai do mesmo `rendimento()` do clube, porque quem pontua lá
 * pontua aqui, com um freio: contra seleção não existe adversário fraco
 * pra explorar, e minutos de torneio são divididos entre mais gente.
 */
function numerosDoTorneio(o, t){
  const jogos = t.k === "oly" ? ri(5, 6) : ri(7, 8);
  // O freio de 0.82 nos minutos é o que segura a linha da seleção: num
  // torneio o elenco é o melhor do país inteiro, e ninguém joga 36 minutos
  // como joga no clube. statsDoAno já converte minutos em produção.
  const min = clamp(Math.round(minutosDoAno(o) * 0.82), 8, 32);
  const st = statsDoAno(o, min, SELECOES[S.nac] ?? 65);
  return {selJogos: jogos, selPts: st.pts, selReb: st.reb, selAst: st.ast};
}

/** Guarda a medalha em trofeus e devolve o prêmio pra linha do ano. */
function anoDeSelecao(o){
  const t = torneioDoAno(S.ano);
  if (!t || !convocado(o)) return null;

  S.convocacoes = (S.convocacoes || 0) + 1;
  // Os números do torneio ficam na temporada, e é a soma deles que vira a
  // linha da seleção no pé da trajetória.
  S.numerosSelecao = numerosDoTorneio(o, t);
  const r = jogarTorneio(o, t);
  if (!r){
    // Convocação sem medalha ainda é linha na súmula: some do resumo, mas
    // conta a história de quem foi seis vezes e nunca subiu no pódio.
    return {t:`${t.nome} — sem medalha`, k:"normal"};
  }

  // Olimpíada e Copa contam separado: ouro olímpico não pode valer o mesmo
  // que ouro de Copa, e somar os dois num campo só apagaria a diferença.
  const campo = t.k === "oly" ? r.m : r.m + "Copa";
  S.trofeus[campo] = (S.trofeus[campo] || 0) + 1;
  return {t: r.rot, k: r.k};
}

function fecharAno(campeao, vit, o, st){
  const premios = premiosDoAno(o, st, vit, campeao);
  if (S.anoFase === 1 && o >= 78 && ri(0,100) < 40){ premios.push({t:"Calouro do Ano", k:"ouro"}); S.trofeus.roy++; }

  const sel = anoDeSelecao(o);
  if (sel) premios.push(sel);

  S.ultimosPremios = premios;

  // Voltar de uma temporada perdida e ganhar prêmio é uma história por si só —
  // marcada aqui, enquanto ainda dá pra ver que o ano anterior não aconteceu.
  const anterior = S.temporadas[S.temporadas.length - 1];
  if (anterior && anterior.perdida && premios.length) S.voltouComPremio = true;

  // O OVR do ano vai junto: a trajetória mostra a curva do jogador, e sem
  // guardar aqui só sobraria o OVR de hoje, igual em todas as linhas.
  S.temporadas.push({ano:S.ano, idade:S.idade, time:S.time, ...st, vit, ovr:o,
                     premios:premios.map(p=>p.t), campeao,
                     ...(S.numerosSelecao || {})});
  S.numerosSelecao = null;   // valem pro ano que acabou, e só pra ele

  S.efeitoDecisao = 0;
  S.desfecho = null;       // o desfecho pertence ao ano que passou
  S.anosNoClube = (S.anosNoClube || 0) + 1;
  const estouro = evoluir();
  S.mensagem = estouro ? "Você estourou. De uma temporada pra outra, virou outro jogador." : null;

  // Quem está fora da liga tenta o chamado; quem já está dentro tenta subir
  // de divisão. Nunca os dois no mesmo ano — são o mesmo degrau, um antes do
  // outro.
  const chamou = chamadoDaLiga(o, st);
  if (chamou){
    S.mensagem = `O ${S.time} te chamou. Você entrou na ${chamou} pela porta dos fundos — mas entrou.`;
  } else {
    const subiu = tentarSubirDivisao(o, st, premios);
    if (subiu){
      S.mensagem = `A ${subiu} veio buscar você. O ${S.time} pagou pra tirar você da divisão de baixo.`;
    }
  }

  // A troca chega por telefone, e contrato em dia não protege ninguém. É a
  // coisa mais comum da liga que o jogo não tinha: até aqui, você só saía
  // de um clube se QUISESSE sair. Agora o time reconstrói, o contrato pesa,
  // o veterano vira moeda de troca — e você fica sabendo pela TV.
  const trocado = tentarTroca(o);
  if (trocado) S.mensagem = trocado;

  anoDoRival();

  // Marca histórica come a mensagem do ano: passar dos 20 mil pontos é
  // mais importante que qualquer outro recado que estivesse ali.
  const marcas = marcasNovas();
  if (marcas.length) S.mensagem = marcas[marcas.length - 1] + ".";

  // Desafios do ano: ficam guardados no estado só pra aparecer na tela da
  // temporada. Quem manda no que está conquistado é o banco.
  S.desafiosDoAno = checarDesafios(false).map(d => ({id:d.id, i:d.i, n:d.n}));

  // Contrato acabando manda pro mercado ANTES da decisão do ano: é a
  // decisão mais pesada que existe, não faz sentido dividir espaço.
  if (S.contrato <= 0){
    S.parDoRitmo = 0;
    S.mercado = gerarOfertas();
    S.aguardando = false; S.decisaoId = null;
    salvar(); return telaTemporada();
  }

  // ── Modo rápido: duas temporadas por clique ────────────────────────
  //
  // A segunda roda direto, sem decisão no meio — é isso que faz o modo ser
  // rápido de verdade. Guardando a metade do caminho em S.parDoRitmo em vez
  // de num laço, o encadeamento sobrevive ao salvar/carregar: fechar o
  // navegador entre as duas não deixa a carreira num estado impossível.
  //
  // Mercado, finais e aposentadoria sempre cortam o par: são momentos em
  // que a pessoa precisa decidir, e passar por cima deles seria jogar no
  // lugar dela.
  const podeAposentar = S.idade >= 39 || (S.idade >= 33 && ovr(S.A, S.pos) < 68);
  if (S.ritmo === "rapido" && !S.parDoRitmo && !S.encerrada && !podeAposentar && !S.afastado){
    S.parDoRitmo = 1;
    S.decisaoId = null; S.aguardando = false;
    salvar();
    return jogarAno();
  }
  S.parDoRitmo = 0;

  S.decisaoId = decisaoDoAno();
  S.aguardando = S.decisaoId !== null;
  // A linha do ano que acabou entra vazia; animarAno() a preenche, junto
  // com o overall e os números do placar.
  S.linhaNova = (S.temporadas[S.temporadas.length - 1] || {}).idade;
  const ovrAntes = S.ovrAnterior;
  salvar(); telaTemporada();
  S.linhaNova = null;
  animarAno(ovrAntes, ovr(S.A, S.pos), (S.temporadas[S.temporadas.length - 1] || {}).idade);
}

// ── Finais, jogo a jogo ────────────────────────────────────────────────
function simularJogoFinal(){
  const f = S.finais;
  const o = S.ultimoOvr;
  // Sua atuação pesa, mas não decide sozinha — é uma série, não um 1x1.
  const chance = clamp(46 + (o-84)*1.6 + (S.forcaBase-58)*0.4 + (S.A.cl-55)*0.15, 22, 76);
  const venceu = ri(0,100) < chance;
  const meus = clamp(Math.round(S.ultimo.pts + ri(-8,10)), 2, 55);
  const deles = clamp(Math.round(S.ultimo.pts + ri(-9,9)), 2, 55);
  if (venceu) f.v++; else f.d++;
  f.jogos.push({n:f.jogos.length+1, venceu, meus, deles});
  salvar();
  // O popup se redesenha sozinho: só o placar muda, e redesenhar a tela
  // inteira a cada jogo era o que tirava a pessoa do contexto do ano.
  desenharFinais();
  if (f.v === 4 || f.d === 4) {
    setTimeout(() => { document.querySelector('.modal-fundo')?.remove(); encerrarFinais(); }, 1100);
  }
}

function encerrarFinais(){
  const f = S.finais, campeao = f.v === 4;
  S.ultimaCampanha = campeao
    ? `<b>${S.ultimaVit}-${82-S.ultimaVit}</b><i class="camp-selo camp-titulo">Campeão</i><em>${f.v}-${f.d} nas finais</em>`
    : `<b>${S.ultimaVit}-${82-S.ultimaVit}</b><i class="camp-selo camp-vice">Vice</i><em>perdeu por ${f.d}-${f.v}</em>`;
  const st = S.ultimo, o = S.ultimoOvr;
  S.finais = null;
  fecharAno(campeao, S.ultimaVit, o, st);
}

/**
 * As finais, num popup por cima da temporada.
 *
 * Sem tela própria e sem botão de fechar: a série começou, e ela termina em
 * quatro vitórias de alguém. Sair no meio deixaria o ano pendurado.
 */
function abrirFinais(){
  document.querySelector('.modal-fundo')?.remove();
  const cx = document.createElement('div');
  cx.className = 'modal-fundo';
  cx.innerHTML = `<div class="modal-cx modal-finais"><div id="finaisCorpo"></div></div>`;
  document.body.appendChild(cx);
  desenharFinais();
}

function desenharFinais(){
  const f = S.finais, alvo = document.getElementById('finaisCorpo');
  if (!f || !alvo) return;
  const decidido = f.v === 4 || f.d === 4;
  const linhas = f.jogos.map(j => `
    <div class="jogo ${j.venceu?'v':'d'}">
      <span class="jogo-n">Jogo ${j.n}</span>
      <span class="jogo-r">${j.venceu ? "VITÓRIA" : "derrota"}</span>
      <span class="jogo-p">${j.meus} pts seus</span>
    </div>`).join("");

  alvo.innerHTML = `
    <div class="fin-cab">
      <span class="fin-tit">Finais</span>
      <small>melhor de sete · ${esc(S.time)} × ${esc(f.adversario)}</small>
    </div>
    <div class="fin-placar">
      ${marca(S.time, 34)}
      <b class="${f.v > f.d ? 'na-frente' : ''}">${f.v}</b>
      <span>×</span>
      <b class="${f.d > f.v ? 'na-frente' : ''}">${f.d}</b>
      ${marca(f.adversario, 34)}
    </div>
    ${linhas ? `<div class="jogos">${linhas}</div>` : `<p class="fin-vazio">A série começa agora.</p>`}
    ${decidido ? '' : `<button class="btn" onclick="simularJogoFinal()">Jogar o jogo ${f.jogos.length+1}</button>`}`;
}

// ── Mercado ────────────────────────────────────────────────────────────
/**
 * O contrato, num popup por cima da temporada.
 *
 * Pelo mesmo motivo das finais: a proposta é uma decisão dentro do ano, e
 * mandar a pessoa pra outra tela fazia perder de vista o que ela tem — o
 * clube atual, o overall, o dinheiro — bem na hora de decidir com base nisso.
 *
 * Sem botão de fechar: o contrato acabou, e escolher não é opcional.
 */
/**
 * A janela de transferências, no molde do Copero: uma lista, não um catálogo.
 *
 * Era um popup com até sete cartões grandes lado a lado, cada um com escudo,
 * salário, papel e uma frase — bonito e ilegível, porque a decisão de verdade
 * ("fico ou vou?") ficava diluída em sete comparações. Agora são três linhas
 * na própria tela, com o essencial de cada proposta na mesma ordem, e o resto
 * da tela continua à vista pra decidir com contexto.
 */
function mercadoHTML(){
  const ofertas = S.mercado || [];
  // Pendurar as chuteiras é uma OPÇÃO do mercado, e não só um botão que
  // aparece aos 39 anos. É aqui que a pessoa decide se insiste — e insistir
  // custa: a G League paga mal e o exterior tira você do radar.
  const podeParar = S.dispensado || S.cortado || S.foraDaLiga || S.idade >= 30 || !ofertas.length;
  return `
    <h1 class="dec-tit">${!ofertas.length ? "Acabou"
      : S.cortado ? "Você foi cortado"
      : S.dispensado ? "A liga não ligou" : "Janela de transferências"}</h1>
    <p class="lead dec-sub">${!ofertas.length
      ? "Nenhuma proposta, de lugar nenhum."
      : S.cortado
      ? "O clube rasgou seu contrato no meio da temporada. O que existe agora é o que está aqui."
      : S.dispensado
      ? `Seu contrato acabou e nenhum time da ${esc(S.modo === "fba" ? "FBA" : "NBA")} fez proposta.
         O que existe é o que está aqui — ou parar por aqui.`
      : ofertas.length === 1
      ? "Só apareceu uma proposta. O mercado não é gentil com quem não produz."
      : "Seu contrato acabou. Você pode aceitar uma delas ou ficar no clube."}</p>
    ${!ofertas.length ? `<div class="bpcard">
      <p class="dec-txt" style="margin:0">Você ligou pros empresários e ninguém retornou. Não existe
      mais clube atrás de você — nem aqui, nem lá fora. É isso.</p>
    </div>` : ""}
    ${gradeDeOpcoes(ofertas.map((of,i)=>`
        <button class="oferta-linha" onclick="escolherOferta(${i})">
          <span class="ol-marca">${marca(of.time, 34)}</span>
          <span class="ol-txt">
            <b>${of.time === S.time ? "Ficar no " + esc(of.time) : esc(of.time)}</b>
            <small>${esc(of.liga || S.liga || "")}</small>
          </span>
        </button>`).join(""), "", ofertas.length)}
    ${podeParar ? `<button class="op op-parar" onclick="pendurar()">Pendurar as chuteiras
      <small>Encerra a carreira agora, com ${temporadasJogadas().length} temporadas e o que você já ganhou.</small>
    </button>` : ""}
    `;
}

/** Encerrar de dentro do mercado, com confirmação — não tem volta. */
function pendurar(){
  if (!confirm("Encerrar a carreira agora? Não tem volta.")) return;
  S.mercado = null; S.ofertaEscolhida = null;
  salvar(); encerrar();
}

function escolherOferta(i){
  const of = S.mercado[i];
  const mudou = assinar(of);
  S.mensagem = mudou
    ? `Você assinou com o ${of.time} por ${of.anos} ${of.anos === 1 ? "ano" : "anos"}, $${of.salario}M por ano. Malas prontas.`
    : `Você renovou com o ${of.time}: ${of.anos} ${of.anos === 1 ? "ano" : "anos"}, $${of.salario}M por ano.`;
  S.mercado = null; S.ofertaEscolhida = null;
  S.decisaoId = decisaoDoAno();
  S.aguardando = S.decisaoId !== null;
  salvar();
  if (S.aguardando) return telaTemporada();
  jogarAno();
}

// A tela de prazo separada saiu: o prazo agora vem dentro da proposta.
function escolherOfertaAntigo(i){
  S.ofertaEscolhida = i;
  const of = S.mercado[i];
  app().innerHTML = topo() + `
    <h1>Por quantos anos?</h1>
    <p class="lead">${marca(of.time,26)} <b style="color:var(--text)">${esc(of.time)}</b> — contrato longo paga menos por ano, mas te protege se você cair de produção.</p>
    ${[2,3,4,5].map(a=>{
      const desconto = a >= 5 ? 0.88 : a >= 4 ? 0.94 : a <= 2 ? 1.10 : 1;
      const sal = Math.max(1, Math.round(of.salario * desconto));
      const nota = a <= 2 ? "Aposta em você mesmo. Volta ao mercado logo."
                 : a === 3 ? "O meio do caminho."
                 : a === 4 ? "Segurança com um desconto pequeno."
                 : "Travado por muito tempo. Se cair de nível, o time paga mesmo assim.";
      return `<button class="op" onclick="fecharContrato(${a})">${a} anos ·
        <span style="color:var(--red);font-family:var(--num)">$${sal}M/ano</span>
        <small>${nota}</small></button>`;
    }).join("")}`;
}

function fecharContrato(anos){
  const of = S.mercado[S.ofertaEscolhida];
  const mudou = assinar(of, anos);
  S.mensagem = mudou
    ? `Você assinou por ${anos} anos com o ${of.time}. Malas prontas.`
    : `Você renovou com o ${of.time} por ${anos} anos.`;
  S.mercado = null; S.ofertaEscolhida = null;
  S.decisaoId = decisaoDoAno();
  S.aguardando = S.decisaoId !== null;
  salvar(); telaTemporada();
}

// ═══════════════════════════════════════════════════════════════════════
// A DECISÃO EM CARTAS
//
// Cada opção vira uma carta do tamanho das outras, com os dois desfechos
// listados embaixo e a chance de cada um à direita. É o formato dos jogos
// de carreira que a pessoa já conhece — e resolve o problema do formato
// antigo, em que as opções eram três botões de texto empilhados e a única
// diferença visível entre "descansar" e "operar o joelho" era a frase.
//
// Sem ilustração: teve um bloco colorido com emoji por carta, e ele caiu.
// O emoji vinha de palavra-chave no rótulo, e a maioria das opções não bate
// com nenhuma — "Assumir a braçadeira" e "Liderar sem o título" saíam as
// duas com a mesma bola. Setenta e oito pixels de altura por carta pra não
// dizer nada, e empurrando o desfecho pra fora da tela.
// ═══════════════════════════════════════════════════════════════════════

/**
 * Um desfecho da carta: seta, o que muda, e a chance.
 *
 * `marca` fica vazia enquanto ninguém escolheu; depois do sorteio vira
 * "caiu" ou "apagado" — os mesmos estados que a animação usa, pra que a
 * tela redesenhada continue exatamente como o sorteio parou.
 */
function linhaDeDesfecho(ef, pct, marca){
  if (!ef) return "";
  const cor = corDoEfeito(ef);
  const seta = cor === "bom" ? "↗" : cor === "ruim" ? "↘" : "→";
  return `<span class="dec-res dec-${cor}${marca ? " " + marca : ""}">
    <i>${seta}</i><em>${esc(dizEfeito(ef))}</em><b>${pct}%</b></span>`;
}

/**
 * O título da decisão, curto, acima do texto.
 *
 * Num mapa em vez de um campo em cada entrada: são trinta decisões, e
 * espalhar o título por todas elas seria trinta edições pra ganhar a mesma
 * coisa. Quem não estiver aqui cai no genérico, que continua honesto.
 */
const TITULOS_DECISAO = {
  // Onde você joga
  clube:"Ficar ou pedir troca", crise:"A franquia quebrou",
  troca:"Prazo de trocas", degrau:"Um degrau abaixo",
  // O corpo
  lesao:"Volta da lesão", carga:"Gestão de carga", joelho:"O joelho",
  tendao:"O tendão", aterrissagem:"A queda no garrafão",
  apostas:"Investigação de apostas",
  // O vestiário
  superstime:"Superequipe", tecnico:"Atrito com o técnico", banco:"Vaga no quinteto",
  // Fora de quadra
  patrocinio:"A linha de tênis", contador:"O contador sumiu",
  // A cota de OVR
  treino:"Concentração extra", posicao:"Mudança de posição",
  ultimaposse:"A última posse", veterano:"O veterano do elenco",
  rival:"O rival de sempre",
};

/**
 * Envolve as opções de uma escolha numa pista.
 *
 * `cls` é o feitio da pista no celular. Sem ela, uma opção por linha — é o
 * certo pras ofertas de clube, que são linhas de escudo e nome. Com "duas",
 * as opções ficam lado a lado: uma decisão se toma COMPARANDO as portas, e
 * empilhadas elas custavam meia tela pra mostrar duas.
 */
function gradeDeOpcoes(cartasHtml, cls, n){
  // SEMPRE em linha, nunca uma embaixo da outra. O auto-fit com minmax
  // dobrava pra baixo em tela estreita, e era exatamente o empilhamento que
  // a linha veio tirar: duas ou tres portas lado a lado e o que deixa
  // comparar num olhar so. As colunas vem do numero de opcoes — no maximo
  // tres, que ja e o teto tanto das decisoes quanto das propostas.
  const cols = Math.min(3, Math.max(1, n || 0));
  return `<div class="carr"><div class="carr-pista${cls ? " " + cls : ""}" style="--cols:${cols}">${cartasHtml}</div></div>`;
}

function cartasDaDecisao(d){
  const h = (r) => hashNome(r) % 360;
  const titulo = TITULOS_DECISAO[d.id] || "Sua decisão";
  return `<h1 class="dec-tit">${esc(titulo)}</h1>
    <p class="lead dec-sub">${d.t()}</p>
    ${gradeDeOpcoes(d.ops.map((o, i) => {
        const c = (o.chance ?? 100);
        return `<button class="dec-card" onclick="decidir(${i})">
          <span class="dec-card-tit">${esc(o.l)}</span>
          <span class="dec-card-res">
            ${linhaDeDesfecho(o.bom, c, "")}
            ${c >= 100 ? "" : linhaDeDesfecho(o.ruim, 100 - c, "")}
          </span>
        </button>`;
      }).join(""), "duas", d.ops.length)}`;
}

/**
 * A nota do que aconteceu na decisão do ano.
 *
 * Fica no alto da tela seguinte, acompanhando a temporada que a escolha
 * gerou — e não numa tela própria antes dela. É uma linha e um número: o que
 * mudou, e quanto. Some sozinha quando a decisão seguinte é tomada.
 */
function notaDoQueDeu(){
  const q = S.oQueDeu;
  if (!q || !String(q.txt || "").trim()) return "";
  const d = q.ovr || 0;
  const cor = d > 0 ? "var(--green)" : d < 0 ? "var(--red)" : "var(--text3)";
  return `<div class="bpcard nota-deu">
    <span class="nota-deu-marca" style="background:${q.bom ? "var(--green)" : "var(--red)"}"></span>
    <div class="nota-deu-txt">
      <b>${esc(q.l || "")}</b>
      <p>${esc(q.txt)}</p>
    </div>
    ${d ? `<em class="nota-deu-ovr" style="color:${cor}">${d > 0 ? "+" : ""}${d} OVR</em>` : ""}
  </div>`;
}

function telaTemporada(){
  const st = S.ultimo;
  if (!st) return telaDraft();

  // Carreira salva no formato antigo pode chegar aqui parada num desfecho —
  // uma tela que não existe mais e não tinha botão de sair. Vira nota e o
  // jogo segue, em vez de a pessoa abrir o save e encontrar o fim do mundo.
  if (S.desfecho){
    S.oQueDeu = {txt: S.desfecho.txt || "", ovr: S.desfecho.ovr || 0,
                 l: S.desfecho.l || "", bom: S.desfecho.caiu === "bom"};
    S.desfecho = null;
    salvar();
  }

  // REDE DE SEGURANCA: nenhum ano chega a tela sem pergunta.
  //
  // jogarAno() sorteia a decisao, mas ele nao e a unica porta pra ca —
  // renovacao de contrato, volta de lesao e o par do modo rapido tambem
  // desembocam em telaTemporada, e por esses caminhos o ano nascia com um
  // "Proxima temporada" solitario, que nao e escolha nenhuma. Aqui a
  // decisao e sorteada na hora. So dispara uma vez: assim que aguardando
  // fica true, o proximo desenho passa reto.
  //
  // Ano perdido (afastado) fica de fora de proposito — quem esta fora nao
  // tem o que decidir, e a tela dele e justamente sobre isso.
  if (!S.aguardando && !S.mercado && !S.desfecho && !S.afastado
      && !S.encerrada && S.fase === "liga"){
    S.decisaoId = decisaoDoAno();
    S.aguardando = S.decisaoId !== null;
    if (S.aguardando) salvar();
  }

  const d = S.aguardando ? decisaoAtual() : null;
  const aposentar = S.idade >= 39 || (S.idade >= 33 && ovr(S.A,S.pos) < 68);

  // Ficha e decisão em dois blocos: no celular a súmula entra ENTRE eles
  // (o `order` está no CSS), que é a ordem do Copero — quem você é, a
  // carreira até aqui, e só então a escolha do ano.
  const blocoFicha = barra() +
    placar(st, String(S.ano), S.time + (S.gm ? ` · ${S.gm}` : ""), S.ultimaCampanha, S.ultimosPremios || []);

  // A ação do ano fica separada do resto da decisão: é ela que vai pro
  // rodapé fixo no celular. Quando a escolha é por cartas não há ação
  // nenhuma aqui — o que se faz é clicar numa carta.
  // Pendurar as chuteiras e a UNICA acao que convive com a decisao do ano.
  // Agora que todo ano tem uma, "com decisao = sem botao" trancava a saida:
  // aos 39 a carreira so acabaria pelo mercado. Ela fica embaixo das cartas,
  // discreta, e o "Proxima temporada" volta a ser o que sempre devia ter
  // sido — a saida de emergencia dos anos em que nada coube.
  const acoes = (S.mercado || S.desfecho) ? "" : (
    aposentar ? `<button class="btn${S.aguardando && d ? " btn2" : ""}" onclick="encerrar()">Pendurar as chuteiras</button>`
    : (S.aguardando && d) ? ""
    : S.idade >= 33 ? `<div class="acoes-ano">
        <button class="btn" onclick="jogarAno()">${
          S.ritmo === "rapido" ? "Próximas duas temporadas" : "Próxima temporada"}</button>
        <button class="btn btn2" onclick="encerrar()">Parar por aqui</button>
      </div>`
    : `<button class="btn" onclick="jogarAno()">${
        S.ritmo === "rapido" ? "Próximas duas temporadas" : "Próxima temporada"}</button>`);

  const blocoDecisao =
    notaDoQueDeu() +
    (S.mensagem ? `<div class="bpcard"><p class="dec-txt" style="margin:0">${S.mensagem}</p></div>` : "") +
    ((S.desafiosDoAno || []).length ? `<div class="bpcard conquista-aviso">
      <div class="bpcard-title">Conquista${S.desafiosDoAno.length > 1 ? "s" : ""} desbloqueada${S.desafiosDoAno.length > 1 ? "s" : ""}</div>
      ${S.desafiosDoAno.map(d => `<div class="conquista-linha"><span>${d.i}</span><b>${esc(d.n)}</b>
        <em class="conquista-moeda">+${fbaDoDesafio(d.id) || moedaDoDesafio(d.id)}${fbaDoDesafio(d.id) ? " FBA" : ""}</em></div>`).join("")}
      <button class="btn btn2" style="margin-top:10px" onclick="telaDesafios()">Ver todas</button>
    </div>` : "") +
    // O desfecho aparece NO LUGAR da decisão, não acima dela: enquanto ele
    // está na tela, a decisão do ano já foi tomada e não há o que escolher.
    // Era isso que o bloco "O que aconteceu" fazia errado — ficava por cima
    // da pergunta seguinte e empurrava ela pra fora da dobra.
    `<div class="dec-caixa">`
    + (S.mercado ? mercadoHTML() : S.aguardando && d ? cartasDaDecisao(d) : "")
    + rodapeDeAcoes(acoes)
    + `</div>`
    + `<button class="btn-desistir" onclick="desistir()">Desistir da carreira</button>`
    + espacoDaBarra();

  // A súmula vai pro lado no desktop: ela cresce a cada temporada e,
  // embaixo do botão de avançar, empurrava a decisão pra fora da tela.
  app().innerHTML = topo() + fichaFixa() + `<div class="colunas colunas-ano">
    <div class="col-principal">
      <div class="bl-ficha">${blocoFicha}</div>
      <div class="bl-decisao">${blocoDecisao}</div>
    </div>
    <div class="col-lado">${sumula()}</div>
  </div>`;
  ajustarTela();
}

function decidir(i){
  const d = decisaoAtual();
  const op = d.ops[i];
  // A nota da decisão ANTERIOR sai de cena agora: ela contava o que a
  // escolha passada rendeu, e a passada acabou de virar história.
  S.oQueDeu = null;
  // O sorteio usa a MESMA chance que a etiqueta mostrou embaixo do botão.
  // É por isso que a etiqueta é gerada do dado em vez de escrita à mão:
  // texto e código escritos separados divergem na primeira alteração.
  const deuCerto = ri(1, 100) <= (op.chance ?? 100);
  const ef = deuCerto ? op.bom : (op.ruim || {txt:"No fim não deu em nada."});

  // Só DADO, nunca o objeto da opção: ele tem função dentro e o
  // JSON.stringify do save as descarta calado, o que traria a carreira de
  // volta com uma aposta sem desfecho nenhum.
  // `conf` entra aqui junto com o resto: sem ela, o desfecho redesenhado
  // perdia a cor e o rótulo das decisões que só mexem em confiança — a de
  // ficar ou pedir troca saía cinza e escrita "nada muda".
  //
  // Congelado ANTES de aplicarEfeito, e com `ovrReal`/`potReal` calculados
  // aqui: a etiqueta que a pessoa leu antes de clicar foi feita com o
  // estado deste instante, e é essa que o desfecho tem que repetir. Lido
  // depois, um "+3 OVR" que entregou +3 podia voltar escrito "nível no
  // teto" só porque o próprio +3 encostou o jogador no limite.
  const soDado = (e) => e ? {ovr: e.ovr || 0, ovrReal: ovrRealizavel(e.ovr || 0),
                             pot: e.pot || 0, potReal: potRealizavel(e.pot || 0),
                             time: e.time || null, fora: e.fora || null, conf: e.conf || 0,
                             dispensa: !!e.dispensa, resgate: !!e.resgate, queda: !!e.queda,
                             hype: e.hype || 0, elenco: e.elenco || 0, salario: e.salario || 0,
                             grana: e.grana || 0, granaPct: e.granaPct || 0,
                             contrato: e.contrato || 0} : null;
  const dadoBom = soDado(op.bom), dadoRuim = soDado(op.ruim);

  S.efeitoDecisao = aplicarEfeito(ef);

  // O QUE ACONTECEU, e só. Antes isto virava uma tela inteira com "Deu
  // certo." ou "Não foi dessa vez." em cima — um veredito sobre a sorte, que
  // a própria carta já conta acendendo o desfecho que saiu. O que a tela
  // dizia de novo era o texto; então é o texto que fica, numa nota que
  // acompanha a temporada seguinte. O resto do tempo o jogo faz o que a
  // pessoa clicou pra ver: joga o ano.
  S.oQueDeu = {txt: ef.txt || "", ovr: S.efeitoDecisao, l: op.l, bom: deuCerto};
  S.desfecho = null;
  S.aguardando = false; S.decisaoId = null; S.mensagem = null;

  // Salva ANTES da animação, não depois. O resultado já está decidido; se a
  // pessoa recarregar no meio do sorteio, ela cai com a escolha feita — em
  // vez de voltar pra decisão e sortear de novo.
  salvar();

  // Aposta de 100% não tem dois lados pra alternar, e quem pediu menos
  // movimento no sistema não quer nada piscando: nesses casos o ano toca
  // direto.
  const doisLados = (op.chance ?? 100) < 100 && op.ruim;
  if (!doisLados || matchMedia("(prefers-reduced-motion: reduce)").matches) return seguir();
  sortearNaCarta(i, deuCerto);
}


/**
 * O sorteio acontece na carta clicada, sem trocar de tela.
 *
 * Tela própria pro sorteio custava duas navegações pra mostrar um segundo
 * de animação — e tirava a pessoa de onde ela estava olhando. Aqui a luz
 * pula entre os dois desfechos da própria carta, rápido, e quando para a
 * tela se redesenha já com o resultado.
 */
function sortearNaCarta(i, deuCerto){
  const cartas = [...document.querySelectorAll(".dec-card")];
  const carta = cartas[i];
  const linhas = carta ? [...carta.querySelectorAll(".dec-res")] : [];
  if (linhas.length < 2) return telaTemporada();

  // As outras cartas saem de cena: a decisão já foi tomada, e piscar ao
  // lado de opções ainda acesas confunde o que está sendo sorteado.
  cartas.forEach((c, k) => { if (k !== i) c.classList.add("dec-fora"); });
  carta.classList.add("dec-sorteando");

  const alvo = deuCerto ? 0 : 1;
  let n = 0, aceso = 0;
  const passo = () => {
    // Se a tela mudou embaixo do sorteio, some daqui em vez de escrever
    // num nó que não está mais na página.
    if (!carta.isConnected) return;

    linhas.forEach((l, k) => l.classList.toggle("piscando", k === aceso));
    n++;

    if (n >= 9 && aceso === alvo){
      linhas.forEach((l, k) => {
        l.classList.remove("piscando");
        l.classList.add(k === alvo ? "caiu" : "apagado");
      });
      // 700ms com o desfecho aceso na carta: o bastante pra ver de que lado
      // a luz parou, curto o bastante pra não virar uma tela de espera. Aí o
      // ano toca — que é o que a pessoa clicou pra ver acontecer.
      return setTimeout(() => { if (carta.isConnected) seguir(); }, 700);
    }

    aceso = 1 - aceso;
    setTimeout(passo, 55 + Math.max(0, n - 4) * 26);   // rápido, freando no fim
  };
  passo();
}

/**
 * Fecha o desfecho e toca o ano seguinte.
 *
 * Chamada sozinha depois que o desfecho aparece: a decisão já foi tomada e
 * ficar esperando um clique de "seguir" só atrasa o que vai acontecer de
 * qualquer jeito. Quem está em fim de carreira é a exceção — aí a tela para,
 * porque continuar ou parar É a decisão seguinte.
 */
function seguir(){
  S.desfecho = null; S.efeitoDecisao = 0;

  // Saída forçada: o evento rasgou o contrato e o mercado abre AGORA, antes
  // de tocar o ano seguinte. É a única coisa que separa "fui dispensado" de
  // "joguei mais um ano pelo time que me dispensou". O par do modo rápido
  // cai junto: ser cortado é a notícia da temporada e merece a tela inteira.
  if (S.dispensaPendente){
    S.dispensaPendente = false;
    S.parDoRitmo = 0;
    S.mercado = gerarOfertas() || [];

    // O desfecho prometeu que os GMs ligaram. gerarOfertas() não sabe disso:
    // ela pergunta a ligaAindaQuer(), que só olha nível e idade, e podia
    // devolver só G League/exterior — ou nada, e a tela vira "Acabou". Aqui
    // o texto da carta vira contrato: se não sobrou nenhuma proposta da
    // liga, entra uma, pior que a anterior, mas da liga.
    if (S.resgatePendente){
      S.resgatePendente = false;
      const daLiga = S.mercado.some(o => o.tipo !== "exterior" && o.tipo !== "gleague");
      if (!daLiga){
        const outros = timesDaLiga().filter(t => t !== S.time);
        if (outros.length){
          S.mercado.unshift({tipo:"contender", time: pick(outros),
            liga: S.modo === "fba" ? (S.liga || "RISE") : "NBA",
            salario: Math.max(1, Math.round(valorDeMercado() * 0.7)), anos: 2,
            forca: ri(50, 80), papel: "rotação",
            nota: "Ligaram no mesmo dia. Menos dinheiro, mas é a liga."});
          S.dispensado = false;
        }
      }
    }

    S.aguardando = false; S.decisaoId = null;
    salvar(); return telaTemporada();
  }
  S.resgatePendente = false;

  salvar();
  const podeParar = S.idade >= 39 || (S.idade >= 33 && ovr(S.A, S.pos) < 68) || S.idade >= 33;
  if (podeParar) return telaTemporada();
  jogarAno();
}

/**
 * O desfecho, no mesmo lugar onde a decisão estava.
 *
 * Mesma carta, mesmos dois desfechos, agora com um aceso e o outro
 * apagado — a pessoa vê onde a luz parou sem precisar traduzir nada. O
 * texto embaixo conta o que aconteceu.
 */


/**
 * Desistir: apaga a carreira e volta pro começo.
 *
 * Diferente de se aposentar — aposentar paga o legado em moeda, desistir
 * não paga nada. O aviso diz isso antes, porque não dá pra desfazer.
 */
function desistir(){
  if (!confirm("Desistir desta carreira? Ela não volta, e não entra no ranking.")) return;
  apagar();
  S = null;
  telaInicio();
}

/**
 * O que a carreira rendeu, em cima da lista ano a ano.
 *
 * A súmula conta a história linha a linha; isto é o placar dela. Sem esse
 * resumo, saber quantos MVPs você tem exigia varrer a coluna de prêmios
 * temporada por temporada.
 */
/**
 * As taças, desenhadas em SVG.
 *
 * Foto de troféu de verdade é marca de terceiro e o projeto não hospeda isso
 * — e desenho vetorial aparece igual em qualquer aparelho, sem depender de
 * rede, como já vale para as bandeiras e para as taças do Copero.
 *
 * Cada uma tem que se distinguir de RELANCE, sem ler a legenda: o Larry
 * O'Brien é a bola sobre a haste, a Copa NBA é a taça de alças, o MVP é a
 * figura, as medalhas são o disco na fita. `id => [cor, desenho]`.
 */
const TACAS_NBA = {
  // Larry O'Brien: a bola em cima da rede, sobre a base cilíndrica.
  titulo: ['#e5b45c',
    '<circle cx="32" cy="15" r="9" fill="currentColor"/>'
  + '<path d="M23 15h18M32 6v18M25 9.5c4 3.5 10 3.5 14 0M25 20.5c4-3.5 10-3.5 14 0" '
  + 'stroke="#5b3d12" stroke-width="1.1" fill="none" opacity=".55"/>'
  + '<path d="M26 24h12l-2 12H28z" fill="currentColor"/>'
  + '<rect x="27" y="36" width="10" height="5" fill="currentColor" opacity=".85"/>'
  + '<rect x="21" y="41" width="22" height="8" rx="2" fill="currentColor"/>'],
  // Copa NBA: taça de alças abertas, prateada — a do meio de temporada.
  copaNBA: ['#c7ccd4',
    '<path d="M23 9h18v13c0 6.5-3.5 10.5-9 12.5-5.5-2-9-6-9-12.5z" fill="currentColor"/>'
  + '<path d="M23 12h-6v5c0 4.5 2.5 7.5 6 8.5M41 12h6v5c0 4.5-2.5 7.5-6 8.5" '
  + 'stroke="currentColor" stroke-width="2.4" fill="none"/>'
  + '<rect x="29.5" y="34" width="5" height="8" fill="currentColor"/>'
  + '<path d="M22 42h20l2 7H20z" fill="currentColor"/>'],
  // MVP: a figura recortada, que é como o troféu de verdade se reconhece.
  mvp: ['#eab308',
    '<circle cx="32" cy="12" r="5" fill="currentColor"/>'
  + '<path d="M32 18c-6 0-9 4-9 10v8h18v-8c0-6-3-10-9-10z" fill="currentColor"/>'
  + '<path d="M23 24l-5 6M41 24l5 6" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>'
  + '<rect x="28" y="38" width="8" height="5" fill="currentColor"/>'
  + '<rect x="20" y="43" width="24" height="7" rx="2" fill="currentColor"/>'],
  // MVP das Finais: a bola sobre o pedestal alto.
  fmvp: ['#d4af37',
    '<circle cx="32" cy="17" r="10" fill="currentColor"/>'
  + '<path d="M22 17h20M32 7v20" stroke="#5b3d12" stroke-width="1.2" opacity=".5"/>'
  + '<rect x="29" y="29" width="6" height="12" fill="currentColor"/>'
  + '<path d="M23 41h18l3 8H20z" fill="currentColor"/>'],
  // Defensor do ano: o escudo.
  dpoy: ['#60a5fa',
    '<path d="M32 8l16 5v13c0 10-7 17-16 20-9-3-16-10-16-20V13z" fill="currentColor"/>'
  + '<path d="M25 27l5 5 10-11" stroke="#0a1a30" stroke-width="3" fill="none" '
  + 'stroke-linecap="round" stroke-linejoin="round"/>'],
  // Cestinha: a bola entrando na rede.
  cesta: ['#f97316',
    '<rect x="14" y="10" width="36" height="4" rx="1.5" fill="currentColor"/>'
  + '<path d="M18 14l3 11h22l3-11" stroke="currentColor" stroke-width="2.4" fill="none"/>'
  + '<path d="M21 25l4 9h14l4-9" stroke="currentColor" stroke-width="1.8" fill="none" opacity=".6"/>'
  + '<circle cx="32" cy="43" r="8" fill="currentColor"/>'
  + '<path d="M24 43h16M32 35v16" stroke="#7c2d12" stroke-width="1.2" opacity=".7"/>'],
  // Calouro do ano: a estrela nascendo.
  roy: ['#34d399',
    '<path d="M32 10l4.5 9.5L47 21l-7.5 7.3 1.8 10.4L32 33.8l-9.3 4.9 1.8-10.4L17 21l10.5-1.5z" '
  + 'fill="currentColor"/>'
  + '<path d="M18 46h28" stroke="currentColor" stroke-width="3" stroke-linecap="round" opacity=".55"/>'],
  // All-Star: a estrela cheia.
  allstar: ['#a78bfa',
    '<path d="M32 8l6 12.5L52 22l-10 9.7 2.4 13.8L32 39l-12.4 6.5L22 31.7 12 22l14-1.5z" '
  + 'fill="currentColor"/>'],
  // Euroliga: a taça continental.
  euro: ['#38bdf8',
    '<path d="M24 10h16v12c0 6-3 10-8 12-5-2-8-6-8-12z" fill="currentColor"/>'
  + '<path d="M24 13h-6v5c0 5 3 8 6 9M40 13h6v5c0 5-3 8-6 9" '
  + 'stroke="currentColor" stroke-width="2.4" fill="none"/>'
  + '<rect x="30" y="34" width="4" height="9" fill="currentColor"/>'
  + '<path d="M23 43h18l2 7H21z" fill="currentColor"/>'],
  // As medalhas: mesma forma, cor diferente — é assim que pódio funciona.
  ouro:   ['#fbbf24', MEDALHA_SVG()],
  prata:  ['#cbd5e1', MEDALHA_SVG()],
  bronze: ['#c2825a', MEDALHA_SVG()],
};
// Copa do Mundo usa a mesma medalha do pódio olímpico.
TACAS_NBA.ouroCopa   = ['#fbbf24', MEDALHA_SVG(true)];
TACAS_NBA.prataCopa  = ['#cbd5e1', MEDALHA_SVG(true)];
TACAS_NBA.bronzeCopa = ['#c2825a', MEDALHA_SVG(true)];

/** O disco na fita. `comBola` marca as da Copa, pra não confundir com o pódio. */
function MEDALHA_SVG(comBola){
  return '<path d="M22 6l7 18M42 6l-7 18" stroke="currentColor" stroke-width="4" '
       + 'stroke-linecap="round" opacity=".55"/>'
       + '<circle cx="32" cy="36" r="14" fill="currentColor"/>'
       + (comBola
          ? '<circle cx="32" cy="36" r="7.5" fill="none" stroke="#3b2a08" stroke-width="1.6" opacity=".6"/>'
          + '<path d="M24.5 36h15M32 28.5v15" stroke="#3b2a08" stroke-width="1.4" opacity=".6"/>'
          : '<circle cx="32" cy="36" r="8.5" fill="none" stroke="#3b2a08" stroke-width="1.8" opacity=".45"/>');
}

/** A taça desenhada, do tamanho pedido. Id desconhecido não desenha nada. */
function tacaNBA(id, tam){
  const t = TACAS_NBA[id];
  if (!t) return '';
  return `<svg class="taca-nba" viewBox="0 0 64 56" width="${tam}" height="${tam}"
    style="color:${t[0]}" role="img" aria-label="${esc(id)}">${t[1]}</svg>`;
}

function resumoDeTrofeus(){
  const t = S.trofeus || {};
  const ordem = [
    ["titulo","Título","Títulos","titulo"],
    ["mvp","MVP","MVPs","ouro"],
    ["fmvp","MVP das Finais","MVPs das Finais","titulo"],
    ["copaNBA","Copa NBA","Copas NBA","ouro"],
    ["euro","Euroliga","Euroligas","ouro"],
    ["dpoy","Defensor do Ano","Defensores do Ano","ouro"],
    ["cesta","Cestinha","Cestinhas","ouro"],
    ["ouro","Ouro olímpico","Ouros olímpicos","ouro"],
    ["ouroCopa","Ouro na Copa","Ouros na Copa","ouro"],
    ["prata","Prata olímpica","Pratas olímpicas","normal"],
    ["prataCopa","Prata na Copa","Pratas na Copa","normal"],
    ["bronze","Bronze olímpico","Bronzes olímpicos","normal"],
    ["bronzeCopa","Bronze na Copa","Bronzes na Copa","normal"],
    ["roy","Calouro do Ano","Calouro do Ano","ouro"],
    ["allstar","All-Star","All-Stars","normal"],
  ];
  // A galeria mostra a TAÇA, não o nome dela. Quinze linhas de "3× MVP" é
  // uma lista; quinze taças desenhadas é uma sala de troféus, e é ela que
  // faz a carreira parecer uma carreira.
  const itens = ordem
    .map(([k, um, varios]) => {
      const n = Math.max(0, Number(t[k]) || 0);
      if (!n) return "";
      const svg = tacaNBA(k, 44);
      if (!svg) return `<span class="pr">${n}× ${esc(n === 1 ? um : varios)}</span>`;
      return `<div class="sala-item" title="${esc(n + '× ' + (n === 1 ? um : varios))}">
        <div class="sala-taca">${svg}${n > 1 ? `<i>×${n}</i>` : ""}</div>
        <span>${esc(n === 1 ? um : varios)}</span>
      </div>`;
    })
    .filter(Boolean);
  if (!itens.length) return "";
  const total = ordem.reduce((s, [k]) => s + Math.max(0, Number(t[k]) || 0), 0);
  return `<div class="bpcard sala">
    <div class="sala-cab">Sala de troféus<b>${total}</b></div>
    <div class="sala-grade">${itens.join("")}</div>
  </div>`;
}

/**
 * Um ícone por conquista da temporada, em vez da lista de nomes.
 *
 * A linha antiga escrevia "All-Star · MVP das Finais" embaixo do clube e
 * empurrava a altura da linha pra o dobro. O ícone cabe na mesma linha do
 * nome e o `title` continua dizendo o que é — a informação não some, só
 * para de ocupar espaço que a lista de idades precisa.
 */
/**
 * De que TAÇA é cada prêmio do ano.
 *
 * A ordem importa e é armadilha: /final/ tem que vir DEPOIS de /mvp/,
 * senão "MVP das Finais" cai no anel de campeão em vez do troféu de MVP
 * das finais — que é justamente o prêmio mais raro da lista. E /ouro/
 * separado de /copa/ porque ouro olímpico e ouro de Copa são medalhas
 * diferentes no motor (S.trofeus guarda em campos separados).
 */
const TACA_DO_PREMIO = [
  [/mvp.*final|final.*mvp/i, "fmvp"], [/mvp/i,           "mvp"],
  [/defensor|dpoy/i,         "dpoy"], [/calouro|roy/i,   "roy"],
  [/cestinha/i,              "cesta"], [/all[- ]?star/i, "allstar"],
  [/euroliga/i,              "euro"],  [/copa.*ouro|ouro.*copa/i, "ouro"],
  [/ouro/i,                  "ouro"],  [/prata/i,        "prata"],
  [/bronze/i,                "bronze"],
];

/**
 * As taças do ano, dentro da célula do clube.
 *
 * Eram emoji — 🏆⭐🛡️ — e emoji de sistema muda de desenho a cada
 * aparelho, some em fonte que não tem, e não combina com nada do resto do
 * jogo. Agora usa as mesmas taças SVG da sala de troféus, no tamanho da
 * linha. Prêmio que não casa com taça nenhuma cai no troféu de título,
 * que é o genérico da casa.
 */
function trofeusDaTemporada(t){
  const ids = [];
  if (t.campeao) ids.push(["titulo", "Campeão"]);
  (t.premios || []).forEach(p => {
    const achado = TACA_DO_PREMIO.find(([re]) => re.test(p));
    ids.push([achado ? achado[1] : "titulo", p]);
  });
  if (!ids.length) return "";
  // Até quatro aparecem; da quinta em diante viram "+N", porque a célula
  // do clube tem o nome do time pra caber junto.
  const corta = ids.length > 4;
  const vistos = ids.map(([id, nome]) => `<i title="${esc(nome)}">${tacaNBA(id, 13)}</i>`).join("");
  const extra = corta
    ? `<i class="tj-mais" title="${esc(ids.slice(3).map(([,n]) => n).join(" · "))}">+${ids.length - 3}</i>`
    : "";
  return `<span class="tj-trofeus${corta ? " corta" : ""}">${vistos}${extra}</span>`;
}

/**
 * A carreira por IDADE, não por ano.
 *
 * A tabela antiga era um extrato: linha por temporada jogada, e só. Por
 * idade a lista vira uma régua — dá pra ver de relance quantos anos ainda
 * existem pela frente, e é isso que faz uma decisão aos 29 pesar diferente
 * de uma aos 22. As idades vazias ficam ali de propósito.
 */
function trajetoPorIdade(){
  const jogadas = S.temporadas || [];
  if (!jogadas.length) return "";
  const inicio = Math.min(...jogadas.map(t => t.idade || S.idade));
  // A lista desce do ano de agora pro primeiro — então ano NENHUM à frente
  // faz sentido aqui: eram doze linhas vazias EM CIMA da linha que
  // interessa, empurrando a carreira inteira pra fora da tela pra dizer
  // que ainda faltam doze anos.
  const fim = Math.max(S.idade, ...jogadas.map(t => t.idade || 0));
  const porIdade = {};
  jogadas.forEach(t => { porIdade[t.idade] = t; });

  // A cor vem do CLUBE; o anel vermelho é que marca o ano de título. Antes a
  // cor sozinha dizia "jogada/campeã" e a trajetória inteira era verde com
  // um vermelho no meio — agora ela conta em que clube cada ano foi passado,
  // que é a informação que não está em mais lugar nenhum da coluna.
  const selo = (i, estado, time) => `<span class="tj-idade ${estado}"${
    time ? ` style="background:${corDoTime(time)};color:#fff"` : ""}>${i}</span>`;
  const ovrSelo = (o) => {
    if (!o) return `<span class="tj-ovr tj-ovr-vazio">—</span>`;
    return `<span class="tj-ovr" style="--cor:${faixaOvr(o)[1]}">${o}</span>`;
  };
  const nums = (t) => `<span class="tj-n">${t.jogos}</span>
    <span class="tj-n forte">${t.pts}</span><span class="tj-n">${t.reb}</span><span class="tj-n">${t.ast}</span>`;
  // A linha do ano que acabou de acontecer nasce sem números: quem os põe,
  // um a um, é preencherLinha().
  const numsVazios = (t) => `<span class="tj-n" data-alvo="${t.jogos}"></span>
    <span class="tj-n forte" data-alvo="${t.pts}"></span>
    <span class="tj-n" data-alvo="${t.reb}"></span>
    <span class="tj-n" data-alvo="${t.ast}"></span>`;
  const vazios = `<span class="tj-n"></span><span class="tj-n"></span><span class="tj-n"></span><span class="tj-n"></span>`;

  // De cima pra baixo, do PRIMEIRO ano pro de agora — a carreira lida na
  // ordem em que aconteceu. Ja foi ao contrario, e por um motivo bom: o ano
  // recem-jogado ficava no fim de uma lista de vinte linhas. O que resolve
  // isso e a janela rolar sozinha ate ele (focarIdadeAtual), nao inverter a
  // leitura da carreira — e assim os dois jogos contam a historia igual.
  const linhas = [];
  for (let i = inicio; i <= Math.min(fim, 41); i++){
    const t = porIdade[i];
    const agora = i === S.idade && !S.encerrada;

    if (!t){
      linhas.push(`<div class="tj ${agora ? "tj-agora" : "tj-vazia"}">
        ${selo(i, agora ? "sel-agora" : "sel-vazio")}
        <span class="tj-clube">${agora ? `<span class="tj-escudo">?</span><em>Escolhendo clube…</em>` : ""}</span>
        <span class="tj-ovr tj-ovr-vazio">${agora ? ovr(S.A, S.pos) : ""}</span>${vazios}</div>`);
      continue;
    }

    if (t.formacao){
      linhas.push(`<div class="tj" data-idade="${i}">
        ${selo(i, "sel-formacao")}
        <span class="tj-clube">${marca(String(t.time||"Formação"), 18)}
          <b>${esc(String(t.time||"Formação"))}</b><em>formação</em></span>
        ${ovrSelo(t.ovr)}${S.linhaNova === i ? numsVazios(t) : nums(t)}</div>`);
      continue;
    }

    if (t.perdida){
      linhas.push(`<div class="tj tj-perdida">
        ${selo(i, "sel-perdida")}
        <span class="tj-clube">${marca(String(t.time||"?"), 18)}
          <b>${esc(String(t.time||""))}</b><em>${t.perdida === "lesao" ? "Lesão" : "Suspensão"}</em></span>
        ${ovrSelo(t.ovr)}${vazios}</div>`);
      continue;
    }

    // A lavagem da cor do time no fundo cria bandas por trecho da
    // carreira: bate o olho e se ve onde voce ficou e onde rodou.
    linhas.push(`<div class="tj ${t.campeao ? "tj-titulo" : ""}" data-idade="${i}"
      style="background:color-mix(in srgb, ${corDoTime(t.time)} 8%, transparent)">
      ${selo(i, t.campeao ? "sel-campeao" : "sel-clube", t.time)}
      <span class="tj-clube">${marca(String(t.time||"?"), 18)}
        <b>${esc(String(t.time||""))}</b>${trofeusDaTemporada(t)}</span>
      ${ovrSelo(t.ovr)}${S.linhaNova === i ? numsVazios(t) : nums(t)}</div>`);
  }

  return `<div class="trajeto">
    <div class="tj tj-cab">
      <span class="tj-idade">Idade</span>
      <span class="tj-clube">Clube</span>
      <span class="tj-ovr">OVR</span>
      <span class="tj-n">Jg</span><span class="tj-n forte">Pts</span>
      <span class="tj-n">Reb</span><span class="tj-n">Ast</span>
    </div>
    <div class="tj-anos">${linhas.join("")}</div>
    ${linhaDaSelecao()}
  </div>${rodapeDeTotais()}`;
}

/**
 * A carreira pela SELEÇÃO, numa linha só no pé da trajetória.
 *
 * O jogo já contava convocações e medalhas, mas nunca mostrou quanto você
 * JOGOU pelo país — e as medalhas ficavam soltas na sala de troféus, sem
 * nada dizendo que vieram de outra camisa. A linha é irmã da do clube de
 * propósito: são duas carreiras paralelas, e somar as duas esconderia as
 * duas. Pontos, rebotes e assistências saem como MÉDIA, igual às linhas de
 * ano acima — total de pontos numa linha de médias faria a coluna mentir.
 */
function linhaDaSelecao(){
  const anos = (S.temporadas || []).filter(t => t.selJogos);
  if (!anos.length && !S.convocacoes) return "";

  const jogos = anos.reduce((a, t) => a + (t.selJogos || 0), 0);
  const med = (k) => {
    if (!jogos) return "0.0";
    const soma = anos.reduce((a, t) => a + (t[k] || 0) * (t.selJogos || 0), 0);
    return (Math.round(soma / jogos * 10) / 10).toFixed(1);
  };
  // NACOES é lista de pares [codigo, nome], não mapa — procurar, não indexar.
  const par = NACOES.find(([c]) => c === S.nac);
  const pais = par ? par[1] : S.nac;
  return `<div class="tj tj-selecao" title="Sua carreira pela seleção ${esc(pais)}">
    <span class="tj-idade sel-selecao">${bandeira(S.nac)}</span>
    <span class="tj-clube"><b>${esc(pais)}</b><em>seleção</em></span>
    <span class="tj-ovr tj-ovr-vazio">—</span>
    <span class="tj-n">${jogos}</span>
    <span class="tj-n forte">${med("selPts")}</span>
    <span class="tj-n">${med("selReb")}</span>
    <span class="tj-n">${med("selAst")}</span>
  </div>`;
}

/**
 * Os totais da carreira como última linha da tabela.
 *
 * Eles moravam na ficha, entre a vitrine e o boletim do ano, e empurravam a
 * decisão pra baixo da dobra. Aqui embaixo eles ficam na MESMA grade das
 * colunas que somam — jogos, pontos, rebotes, assistências ficam alinhados
 * com a coluna de cada um, e a soma se lê no eixo, como em qualquer planilha.
 */
function rodapeDeTotais(){
  const tot = totaisDeCarreira();
  if (!tot.jogos) return "";
  const n = (v) => Number(v || 0).toLocaleString("pt-BR");
  const anos = temporadasJogadas().length;
  return `<div class="tot-faixa">
    <div class="tot-cab">Carreira · ${anos} ${anos === 1 ? "temporada" : "temporadas"}</div>
    <div class="tot-cards">
      <div class="tot-card"><b>${n(tot.jogos)}</b><span>Jogos</span></div>
      <div class="tot-card forte"><b>${n(tot.pts)}</b><span>Pontos</span></div>
      <div class="tot-card"><b>${n(tot.reb)}</b><span>Rebotes</span></div>
      <div class="tot-card"><b>${n(tot.ast)}</b><span>Assist.</span></div>
    </div>
  </div>`;
}

/**
 * Trava a janela da trajetória em doze linhas, MEDINDO a linha.
 *
 * A altura de uma linha muda com o breakpoint, com a fonte do sistema e
 * com a borda entre linhas — escrever o número no CSS deixava a janela com
 * 13,4 linhas em vez de 12. Aqui o passo sai da distância entre as duas
 * primeiras linhas de verdade.
 */
/**
 * Deixa a idade de agora no MEIO da janela de doze anos.
 *
 * A janela mostra doze linhas de uma carreira que chega a vinte e cinco.
 * Sem isto ela nasce no primeiro ano e, aos trinta, a linha que acabou de
 * ser preenchida esta quatorze linhas abaixo — fora da tela, e so quem
 * rolasse atras dela veria os numeros da temporada aparecerem.
 *
 * No meio, e nao no fim: encostada embaixo, a idade atual perde o contexto
 * dos anos seguintes que ainda vao acontecer, e a janela parece travada.
 */
function focarIdadeAtual(){
  const cx = document.querySelector('.tj-anos');
  if (!cx) return;
  // .tj-agora e a linha do ano em curso ainda sem clube; a com data-idade e
  // a do ano ja jogado. Uma das duas existe, nunca as duas.
  const alvo = cx.querySelector('.tj-agora')
            || (S ? cx.querySelector(`.tj[data-idade="${S.idade}"]`) : null)
            || cx.lastElementChild;
  if (!alvo) return;
  const destino = alvo.offsetTop + alvo.offsetHeight / 2 - cx.clientHeight / 2;
  cx.scrollTop = Math.max(0, Math.min(cx.scrollHeight - cx.clientHeight, destino));

  // E a PAGINA desce o quanto precisar pra ela nao ficar atras da caixa de
  // decisao. Centralizar dentro da janela nao bastava: numa carreira curta a
  // lista inteira cabe nas doze linhas, o scroll interno fica em zero e a
  // linha do ano — que e a ultima — nascia justamente embaixo da caixa que
  // flutua no rodape. "Na janela" e "visivel" nao sao a mesma coisa.
  //
  // So no celular: no desktop a caixa esta no fluxo e a trajetoria fica numa
  // coluna grudada, entao nao ha nada tapando e mexer na rolagem da pagina
  // so tiraria a pessoa de onde ela estava.
  const caixa = document.querySelector('.dec-caixa');
  if (!caixa || getComputedStyle(caixa).position !== 'fixed') return;
  const barra = document.querySelector('.topbar');
  const teto = barra ? barra.getBoundingClientRect().bottom : 0;
  const piso = caixa.getBoundingClientRect().top;
  const r = alvo.getBoundingClientRect();
  if (r.bottom > piso - 4) scrollBy(0, r.bottom - piso + 10);
  else if (r.top < teto + 4) scrollBy(0, r.top - teto - 10);
}

/**
 * Mede a altura da caixa de decisao e devolve pro CSS.
 *
 * No celular ela e `position:fixed`, entao sai do fluxo e nao empurra nada
 * — o .dec-espaco e quem segura o buraco, e so o JS sabe a altura, porque
 * ela muda com o texto e com o numero de cartas.
 */
function medirDecisao(){
  const caixa = document.querySelector('.dec-caixa');
  const raiz = document.documentElement;
  if (!caixa) { raiz.style.removeProperty('--dec-h'); return; }
  // No desktop a caixa esta no fluxo normal e o espacador e display:none;
  // medir ali so encheria a variavel de um valor que ninguem le.
  if (getComputedStyle(caixa).position !== 'fixed'){
    raiz.style.removeProperty('--dec-h');
    return;
  }
  raiz.style.setProperty('--dec-h', Math.ceil(caixa.getBoundingClientRect().height) + 'px');
}

/**
 * Os tres ajustes que so dao pra fazer com a arvore ja desenhada.
 *
 * A ordem importa: focar depende do --tj-h que a medicao acaba de gravar, e
 * medirDecisao precisa da caixa ja montada com as cartas dentro. Chamada
 * tambem de dentro de telaTemporada/telaFormacao porque metade das telas do
 * ano chega ali direto, sem passar por render().
 */
function ajustarTela(){
  medirJanelaDaTrajetoria();
  medirDecisao();
  focarIdadeAtual();
  vigiarFicha();
}

function medirJanelaDaTrajetoria(){
  const cx = document.querySelector('.tj-anos');
  if (!cx || cx.children.length < 2) return;
  const passo = cx.children[1].offsetTop - cx.children[0].offsetTop;
  if (passo > 0) cx.style.setProperty('--tj-h', passo + 'px');

  // A BARRA DE ROLAGEM COME LARGURA das linhas de ano, e o cabeçalho e a
  // linha da seleção estão FORA da janela — sem compensar, as colunas de
  // número saem 10px deslocadas entre eles. O valor é medido porque muda
  // com o sistema: no celular a barra sobrepõe e não come nada.
  const barra = cx.offsetWidth - cx.clientWidth;
  const traj = cx.closest('.trajeto');
  if (traj) traj.style.setProperty('--tj-barra', barra + 'px');
}

function sumula(){
  if (!S.temporadas.length) return "";
  // A lista tem altura fixa de doze anos e rola por dentro (.tj-anos). Antes
  // ela nascia CORTADA com máscara e um botão "ver a carreira inteira": a
  // informação era a mesma, mas custava um toque pra chegar nela, e depois
  // de aberta voltava a empurrar a decisão pra fora da tela. A janela
  // resolve os dois — nunca cresce, e a carreira inteira está a um scroll.
  return `<div class="caixa linha">${trajetoPorIdade()}</div>`;
}


// ── Ranking entre os GMs ───────────────────────────────────────────────
// O legado é o placar do ranking — não paga moeda, e é justamente isso
// que o deixa ser um placar honesto: ninguém joga por ele, joga com ele.
function ranking(titulo){
  const r = window.__RANKING__ || [];
  if (!r.length) return "";
  const eu = window.__EU__ || "";
  return `<h2>${titulo || "Melhores carreiras da FBA"}</h2>
    <div class="bpcard">
      ${r.map((x,i)=>`<div class="rk ${x.gm===eu?'eu':''}">
        <span class="rk-pos">${i+1}</span>
        <span class="rk-gm">${esc(x.gm)}<small>${esc(x.nome||"—")} · ${esc(x.pos||"")} · ${x.temporadas} temporadas</small></span>
        <span class="rk-pts">${x.legado}</span>
      </div>`).join("")}
    </div>
    <p class="nota-txt">O legado é a nota da carreira encerrada e vale o lugar no ranking. Moeda sai dos desafios. Uma carreira ativa por vez — ao se aposentar, começa outra.</p>`;
}
function encerrar(){
  S.encerrada = true;
  // Última chamada dos desafios: dois deles (Ringless, Imortal) só podem ser
  // julgados com a carreira fechada.
  S.desafiosDoAno = checarDesafios(true).map(d => ({id:d.id, i:d.i, n:d.n}));
  // Fecha no SERVIDOR: é lá que o legado é recalculado e as moedas
  // creditadas. O cliente só avisa que acabou.
  fetch(location.pathname, {
    method:"POST", headers:{"Content-Type":"application/x-www-form-urlencoded"},
    body:"acao=encerrar&estado=" + encodeURIComponent(JSON.stringify(S)),
  }).then(r=>r.json()).then(d=>{
    if (d && d.ok){ S.legadoFinal = d.legado; }
    telaFim();
  }).catch(()=>telaFim());
  telaFim();
}

const LEGADO_MAXIMO = 230;

/**
 * Soma crua das conquistas. Cresce sem limite; a curva é que segura.
 *
 * Cada troféu passa por (|| 0) porque um estado sem a chave — save antigo,
 * carreira vinda pela metade — fazia a conta inteira virar NaN, e o número
 * na tela sumia. O servidor já era defensivo assim; os dois lados precisam
 * dar o MESMO resultado pro mesmo estado, senão a pessoa vê um valor e
 * recebe outro em moedas.
 */
function legadoBruto(){
  const t = S.trofeus || {};
  const n = (k) => Math.max(0, Number(t[k]) || 0);
  const anos = (S.temporadas || []).filter(x => x && !x.formacao && !x.perdida).length;
  // O pódio internacional pesa menos que título de liga de propósito: ele
  // existe pra dar história a quem caiu num time ruim, não pra ser o
  // caminho mais barato até o topo da escada.
  const selecao = n('ouro')*5 + n('prata')*3 + n('bronze')*2
                + n('ouroCopa')*4 + n('prataCopa')*2 + n('bronzeCopa')*1;
  return n('mvp')*22 + n('titulo')*16 + n('fmvp')*10 + n('dpoy')*8 + n('euro')*7
       + n('cesta')*6 + n('allstar')*4 + n('roy')*3 + selecao + Math.round(anos*0.8);
}

/**
 * Legado de 0 a 230 — e 230 é pra ser lenda urbana.
 *
 * A soma crua é linear: cada troféu vale sempre a mesma coisa, então quem
 * ganha muito dispara e o teto vira decoração. A curva de potência achata
 * o topo: dobrar os troféus perto do fim quase não move o número.
 *
 * Na prática isso significa que os últimos 30 pontos custam mais que os
 * primeiros 150 — que é o que faz "passar de 200" valer alguma coisa.
 */
function pontuacaoLegado(){
  // Mesmo 2.3 do servidor (caminhoLegado, no topo do arquivo).
  return Math.min(LEGADO_MAXIMO, Math.round(Math.pow(legadoBruto(), 0.78) * 2.3));
}

function telaFim(){
  const pts = pontuacaoLegado();
  const tot = totaisDeCarreira();
  const anos = S.temporadas.filter(x=>!x.formacao);
  const somas = anos.reduce((a,t)=>({p:a.p+t.pts, r:a.r+t.reb, s:a.s+t.ast}), {p:0,r:0,s:0});
  const med = (v) => anos.length ? (Math.round(v/anos.length*10)/10) : 0;
  const t = S.trofeus;
  const tro = [
    [t.titulo,"Títulos"],[t.mvp,"MVP"],[t.fmvp,"MVP das Finais"],[t.allstar,"All-Star"],
    [t.dpoy,"Defensor do Ano"],[t.cesta,"Cestinha"],[t.roy,"Calouro do Ano"],[t.ouro,"Ouro com a seleção"],
  ].filter(x=>x[0]>0);

  // O resumo segue o molde do Copero: primeiro o encerramento e as duas
  // saídas da imagem, depois a identidade com o pico, a sala de troféus, os
  // clubes e só então o detalhe. A versão anterior abria com uma lista de
  // nove blocos e enterrava o que a pessoa queria ver — quem ela virou.
  const porTime = {};
  anos.forEach(t => {
    if (!porTime[t.time]) porTime[t.time] = {jogos:0, pts:0, reb:0, ast:0, temporadas:0};
    const x = porTime[t.time];
    x.jogos += (t.jogos||0); x.pts += (t.pts||0)*(t.jogos||0);
    x.reb += (t.reb||0)*(t.jogos||0); x.ast += (t.ast||0)*(t.jogos||0); x.temporadas++;
  });
  const m1 = (v, j) => j ? (Math.round(v/j*10)/10) : 0;
  const faixa = faixaOvr(S.picoOvr || S.ultimoOvr || 0);

  app().innerHTML = topo() + `
    <div class="bpcard fim-topo">
      <h2 style="margin:0 0 4px">Sua carreira chegou ao fim</h2>
      <p class="nota-txt" style="margin:0 0 14px">${anos.length} temporadas · aposentado aos ${S.idade}</p>
      <div class="acoes-fim">
        <button class="btn" onclick="compartilharCartao(this,'baixar')">
          <i class="bi bi-download"></i> Baixar imagem</button>
        <button class="btn" onclick="compartilharCartao(this,'copiar')">
          <i class="bi bi-clipboard"></i> Copiar imagem</button>
        <button class="btn btn2" onclick="copiar(this)">Copiar como texto</button>
        <button class="btn btn2" onclick="apagar();S=null;render()">Nova carreira</button>
      </div>
    </div>

    <div class="resumo-topo">
      <div class="bpcard">
        <div class="bpcard-title">Carreira encerrada</div>
        <div class="fim-ident">
          <div class="fim-nome">
            <b>${esc(S.nome)}</b>
            <div class="fim-tags">
              ${S.numero ? `<span class="tagx">#${esc(S.numero)}</span>` : ""}
              <span class="tagx pos">${esc(S.pos)}</span>
              <span class="tagx">${bandeira(S.nac)} ${esc(S.nac)}</span>
            </div>
          </div>
          <div class="fim-ovr" style="--cor:${faixa[1]}">
            <small>PICO</small><b>${S.picoOvr || S.ultimoOvr || 0}</b>
          </div>
        </div>
        <div class="fim-nums">
          <div><span>Pts/jogo</span><b>${med(somas.p)}</b></div>
          <div><span>Reb/jogo</span><b>${med(somas.r)}</b></div>
          <div><span>Ast/jogo</span><b>${med(somas.s)}</b></div>
        </div>
      </div>
      <div class="bpcard centro">
        <div class="bpcard-title" style="justify-content:center">Legado</div>
        <div class="fim-legado">${pts}</div>
        <p class="nota-txt" style="margin:0">${tot.jogos.toLocaleString("pt-BR")} jogos ·
          ${Object.keys(porTime).length} ${Object.keys(porTime).length === 1 ? "clube" : "clubes"}</p>
        ${S.legadoFinal != null ? `<div class="fim-moedas">${S.legadoFinal} de legado no ranking</div>` : ""}
      </div>
    </div>

    ${resumoDeTrofeus()}

    <div class="clubes-grade">
      ${Object.entries(porTime).map(([nome, x]) => `
        <div class="clube-card">${marca(nome, 44)}<b>${esc(nome)}</b>
          <div class="cc-nums">
            <div><span>Jogos</span>${x.jogos}</div>
            <div><span>Pts</span>${m1(x.pts, x.jogos)}</div>
            <div><span>Reb</span>${m1(x.reb, x.jogos)}</div>
          </div></div>`).join("")}
    </div>

    <h2>Números da carreira</h2>
    <div class="bpcard">
      <div class="grade-num">
        ${caixa(tot.pts.toLocaleString("pt-BR"), "pontos")}
        ${caixa(tot.reb.toLocaleString("pt-BR"), "rebotes")}
        ${caixa(tot.ast.toLocaleString("pt-BR"), "assistências")}
        ${caixa(tot.jogos.toLocaleString("pt-BR"), "jogos")}
      </div>
    </div>

    `;
  // A página do resumo acaba nos números. Seleção, trajetória na liga, súmula
  // ano a ano e ranking saíram: são quatro blocos longos DEPOIS do que a
  // pessoa veio ver, e a tela do fim é a que vira print — ela precisa caber
  // numa olhada, não ser um relatório com anexos.
}

/**
 * O cartão que vai virar print no grupo.
 *
 * Tudo que importa numa tela só: pico de OVR, tier, e os números que as
 * pessoas de fato comparam. O resto da página é pra quem quer detalhe — este
 * bloco é pra quem vai receber a imagem e entender a carreira em dois
 * segundos.
 *
 * A cor sai do nome do time, pela mesma conta do monograma: assim o cartão de
 * quem jogou no Envood é sempre o mesmo verde, e dá pra reconhecer o time
 * antes de ler.
 */
/**
 * Os dados do cartão, num lugar só.
 *
 * O cartão existe em duas formas — o HTML da tela e o PNG que vai pro grupo —
 * e as duas precisam dizer exatamente a mesma coisa. Separando os NÚMEROS do
 * DESENHO, sobra só o desenho pra ser feito duas vezes; um ajuste de conteúdo
 * cai nas duas de uma vez.
 */
/**
 * Todos os clubes por onde a carreira passou, na ordem em que passou.
 *
 * Sem repetir: voltar pro mesmo time depois de dois anos fora não vira
 * duas linhas. A universidade entra — ela também foi um lugar onde se
 * jogou, e é ela que explica o primeiro salto de OVR da lista.
 */
function clubesDaCarreira(){
  const vistos = [];
  (S.temporadas || []).forEach(t => {
    const n = String(t.time || "").trim();
    if (n && !vistos.includes(n)) vistos.push(n);
  });
  return vistos;
}

/**
 * As médias da carreira inteira, ponderadas por jogo.
 *
 * Média de médias mentiria: uma temporada de 12 jogos com 30 pontos pesaria
 * o mesmo que uma de 80 com 18. Aqui é total dividido por jogos, que é a
 * conta que qualquer almanaque faz.
 */
function mediasDaCarreira(){
  const tot = totaisDeCarreira();
  if (!tot.jogos) return null;
  const um = (v) => (v / tot.jogos).toFixed(1).replace(".", ",");
  return {pts: um(tot.pts), reb: um(tot.reb), ast: um(tot.ast), jogos: tot.jogos};
}

function dadosDoCartao(pts, tot, anos){
  const t = S.trofeus || {};

  // Só o que tem valor não-zero, e no máximo seis: cartão com "0× MVP" é
  // ruído, e mais de seis números quebram a linha no celular.
  const nums = [
    [t.titulo, "Títulos"], [t.mvp, "MVP"], [t.fmvp, "MVP Finais"],
    [t.allstar, "All-Star"], [t.dpoy, "DPOY"], [t.cesta, "Cestinha"],
    [t.ouro, "Ouro Oly"], [t.roy, "Calouro"],
  ].filter(x => x[0] > 0).slice(0, 3)
   .map(([n, rot]) => [String(n), rot]);
  nums.push([tot.pts.toLocaleString("pt-BR"), "Pontos"]);

  // Clubes e médias entram como as duas colunas do cartão. Os troféus
  // encolheram pra três + pontos justamente pra caberem os dois juntos:
  // um cartão de fim de carreira sem dizer onde se jogou e quanto se
  // produziu é um placar sem jogo.
  const md = mediasDaCarreira();
  const clubes = clubesDaCarreira();
  const listaClubes = clubes.slice(0, 5);
  if (clubes.length > 5) listaClubes[4] = `+${clubes.length - 4} outros`;

  const listas = [];
  if (listaClubes.length) listas.push({titulo: `Clubes (${clubes.length})`, itens: listaClubes});
  if (md) listas.push({titulo: "Médias", itens: [
    `${md.pts} pontos`, `${md.reb} rebotes`, `${md.ast} assist.`,
    `${md.jogos.toLocaleString("pt-BR")} jogos`,
  ]});

  return {
    // A cor saía de um hash do nome do time: dava um par de matizes
    // sorteado, que não é a cor de time nenhum nem a do jogo — um cartão
    // rosa-e-verde no fim de uma carreira. Agora é a paleta da casa:
    // grafite com o vermelho da FBA, igual ao resto do site.
    c1: "#1a1216", c2: "#0a0a0d",
    ovr: S.picoOvr || S.ultimoOvr || 0,
    pts, nums, listas, clubes, medias: md,
    pos: S.pos, time: String(S.time || "").slice(0, 18),
    // O número entra no nome do cartão: é o que amarra a imagem final à
    // camisa que a pessoa montou na primeira tela.
    temporadas: anos.length,
    nome: S.numero ? `#${S.numero} ${S.nome}` : S.nome,
  };
}

function cartaoDeCarreira(pts, tot, anos){
  const d = dadosDoCartao(pts, tot, anos);
  return `<div class="cartao" style="--c1:${d.c1};--c2:${d.c2}">
    <div class="ct-topo">
      <div>
        <div class="ct-ovr">${d.ovr || "—"}</div>
        <div class="ct-rot">pico de overall</div>
      </div>
      <div class="ct-dir">${esc(d.pos)}<br>${esc(d.time)}<br>${d.temporadas} temporadas</div>
    </div>
    <div class="ct-tier">${d.pts} <span>de legado</span></div>
    <div class="ct-nums">
      ${d.nums.map(([n,rot])=>`<div><b>${n}</b><span>${esc(rot)}</span></div>`).join("")}
    </div>
    ${d.clubes.length ? `<div class="ct-clubes">
      <span class="ct-col-tit">Onde jogou (${d.clubes.length})</span>
      <div class="ct-escudos">${d.clubes.slice(0, 8).map(c => marca(c, 26)).join("")}
        ${d.clubes.length > 8 ? `<span class="ct-mais">+${d.clubes.length - 8}</span>` : ""}</div>
    </div>` : ""}
    ${d.medias ? `<div class="ct-nums ct-medias">
      <div><b>${d.medias.pts}</b><span>pts/jogo</span></div>
      <div><b>${d.medias.reb}</b><span>reb/jogo</span></div>
      <div><b>${d.medias.ast}</b><span>ast/jogo</span></div>
      <div><b>${d.medias.jogos.toLocaleString("pt-BR")}</b><span>jogos</span></div>
    </div>` : ""}
    <div class="ct-pe">FBA Games · Caminho até a NBA · ${esc(d.nome)}</div>
  </div>`;
}

/** Manda a carreira como imagem. O desenho é o de games/core/cartao.php. */
function compartilharCartao(botao, modo){
  const pts = pontuacaoLegado();
  const anos = S.temporadas.filter(x => !x.formacao);
  const d = dadosDoCartao(pts, totaisDeCarreira(), anos);

  // Os logos agora VÃO no PNG. Antes iam por nome, porque imagem de outro
  // domínio suja o canvas e impede de salvar — hoje elas passam por
  // /api/foto-proxy.php e chegam como mesma origem. Logo que não carregar
  // cai nas iniciais sozinho, então o cartão nunca fica com buraco.
  const md = d.medias;

  // SVG virado imagem: como data URI ele conta como mesma origem e não
  // contamina o canvas. É por isso que a bandeira e as taças entram assim, e
  // o logo de time, que vem de CDN, precisa do proxy.
  const svgImagem = (svg) => 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(svg);
  const iso = PAIS_ISO[S.nac];
  const band = iso && BANDEIRAS[iso]
    ? svgImagem(`<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 30 20"
                 width="120" height="80">${BANDEIRAS[iso]}</svg>`)
    : '';

  // A segunda faixa mostra as TAÇAS, e não mais as médias em texto. As médias
  // foram pra régua de números em cima: elas são para comparar, e comparar se
  // faz com número. Taça é para reconhecer, e reconhece-se pelo desenho.
  const t = S.trofeus || {};
  const ordemT = ["titulo","copaNBA","mvp","fmvp","euro","dpoy","cesta","roy","allstar",
                  "ouro","ouroCopa","prata","prataCopa","bronze","bronzeCopa"];
  const titulos = ordemT
    .map(k => {
      const n = Math.max(0, Number(t[k]) || 0);
      if (!n || !TACAS_NBA[k]) return null;
      return {img: svgImagem(`<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 56"
                width="140" height="122" style="color:${TACAS_NBA[k][0]}">${TACAS_NBA[k][1]}</svg>`),
              contagem: n};
    })
    .filter(Boolean).slice(0, 6);

  // Duas saídas, como no Copero: baixar o arquivo ou copiar pra colar na
  // conversa. A folha do sistema ficava de fora porque no computador ela nem
  // existe pra arquivo, e o botão se comportava diferente em cada aparelho.
  (modo === 'copiar' ? fbaCopiar : fbaBaixar)({
    c1: d.c1, c2: d.c2,
    numero: d.ovr || "—", rotulo: "OVR",
    pilulas: [
      band ? {img: band} : {texto: iso || S.nac || "—"},
      {rotulo: "Legado", texto: d.pts},
      {texto: d.pos},
      {rotulo: "Temporadas", texto: d.temporadas},
    ],
    stats: md
      ? [[md.pts, "Pts/jogo"], [md.reb, "Reb/jogo"], [md.ast, "Ast/jogo"],
         [md.jogos.toLocaleString("pt-BR"), "Jogos"]]
      : d.nums,
    faixas: [
      {titulo: `Clubes (${d.clubes.length})`,
       itens: d.clubes.slice(0, 6).map(n => ({img: logoDoTime(n) || "", texto: iniciais(n)}))},
      {titulo: "Troféus",
       itens: titulos.length ? titulos : [{texto: "—", legenda: "sem troféus"}]},
    ],
    nome: d.nome, jogo: "Caminho até a NBA",
  }, botao);
}

/**
 * O que a carreira rendeu de seleção.
 *
 * Só aparece pra quem foi convocado alguma vez — numa carreira que nunca
 * vestiu a camisa do país, seis caixas zeradas seriam só uma pergunta sem
 * resposta na tela.
 */
function legadoInternacional(){
  const t = S.trofeus || {};
  const campos = [
    ["ouro","Ouro Oly"], ["prata","Prata Oly"], ["bronze","Bronze Oly"],
    ["ouroCopa","Ouro Copa"], ["prataCopa","Prata Copa"], ["bronzeCopa","Bronze Copa"],
  ];
  const total = campos.reduce((a,[k]) => a + (Number(t[k]) || 0), 0);
  if (!total && !S.convocacoes) return "";

  const pais = (NACOES.find(n => n[0] === S.nac) || [null, S.nac])[1];
  return `<h2>Seleção ${esc(pais)}</h2>
    <div class="bpcard">
      <div class="grade-num">
        ${campos.map(([k, rot]) => caixa(Math.max(0, Number(t[k]) || 0), rot)).join("")}
      </div>
      <p class="nota-txt">${S.convocacoes || 0} convocação(ões) · ${total} medalha(s).
      ${total ? "" : "Foi chamado, mas o pódio não veio."}</p>
    </div>`;
}

/** Uma casinha da grade. Zerada fica apagada em vez de sumir — a ausência
 *  também é informação: dá pra ver o que faltou. */
function caixa(valor, rotulo){
  const zero = !valor || valor === "0";
  return `<div class="gn${zero ? " zero" : ""}"><b>${valor || 0}</b><span>${esc(rotulo)}</span></div>`;
}

function gradeDeTrofeus(){
  const t = S.trofeus || {};
  return [
    ["titulo","Títulos"], ["mvp","MVP"], ["fmvp","MVP Finais"],
    ["allstar","All-Star"], ["dpoy","Defensor"], ["cesta","Cestinha"],
    ["roy","Calouro"], ["ouro","Ouro olímpico"], ["euro","Euroliga"],
  ].map(([k, rot]) => caixa(Math.max(0, Number(t[k]) || 0), rot)).join("");
}

/**
 * Por quais times passou, com os anos e quantas temporadas em cada.
 *
 * Agrupa passagens SEGUIDAS: quem saiu e voltou aparece duas vezes, porque
 * foram duas passagens diferentes e juntar as duas apagaria a saída.
 */
function trajetoria(){
  const anos = S.temporadas.filter(t => !t.formacao);
  if (!anos.length) return "";

  const passagens = [];
  anos.forEach(t => {
    const ult = passagens[passagens.length - 1];
    if (ult && ult.time === t.time) { ult.fim = t.ano; ult.n++; }
    else passagens.push({time: t.time, ini: t.ano, fim: t.ano, n: 1});
  });

  return `<h2>Trajetória na liga</h2>
    <div class="traj">${passagens.map(p => `
      <div class="tj">
        ${marca(p.time, 38)}
        <div class="tj-info">
          <div class="tj-time">${esc(String(p.time||"").slice(0,22))}</div>
          <div class="tj-anos">${p.ini}${p.fim !== p.ini ? "–" + p.fim : ""}</div>
        </div>
        <div class="tj-qtd">${p.n} ${p.n === 1 ? "temp." : "temps."}</div>
      </div>`).join("")}</div>`;
}

function copiar(botao){
  const pts = pontuacaoLegado();
  const anos = S.temporadas.filter(x=>!x.formacao);
  const somas = anos.reduce((a,t)=>({p:a.p+t.pts,r:a.r+t.reb,s:a.s+t.ast}),{p:0,r:0,s:0});
  const med = v => anos.length ? Math.round(v/anos.length*10)/10 : 0;
  const t = S.trofeus;
  const tro = [[t.titulo,"títulos"],[t.mvp,"MVP"],[t.allstar,"All-Star"],[t.ouro,"ouro"]]
    .filter(x=>x[0]>0).map(x=>`${x[0]}× ${x[1]}`).join(" · ");

  const txt = `🏀 *${S.nome}* — ${S.pos}\n${tier}\n\n`
    + `${anos.length} temporadas · ${med(somas.p)} pts · ${med(somas.r)} reb · ${med(somas.s)} ast\n`
    + (tro ? `${tro}\n` : "")
    + `\n_${pts} pontos de legado_`;

  navigator.clipboard?.writeText(txt).catch(()=>{
    const ta = document.createElement("textarea");
    ta.value = txt; ta.style.cssText = "position:fixed;opacity:0";
    document.body.appendChild(ta); ta.select(); document.execCommand("copy"); ta.remove();
  });
  botao.textContent = "Copiado ✓";
  setTimeout(()=>{ botao.textContent = "Copiar pra mandar no grupo"; }, 1800);
}

function trocarTema(){
  const r = document.documentElement;
  const atual = r.getAttribute("data-theme")
    || (matchMedia("(prefers-color-scheme: dark)").matches ? "dark" : "light");
  r.setAttribute("data-theme", atual === "dark" ? "light" : "dark");
}

S = carregar();
render();
</script>
<?= cartaoScript() ?>
</body>
</html>