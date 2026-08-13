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

const CAMINHO_LEGADO_MAX = 230;
const CAMINHO_MAX_TEMPORADAS = 26;

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

    return (int)min(CAMINHO_LEGADO_MAX, round(pow(max(0,$bruto), 0.78) * 1.8));
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
                               encerrada=1, encerrado_em=NOW(), moedas_pagas=? WHERE id=? AND encerrada=0")
                    ->execute([$json, $nome, $pos, $legado, $temporadas, $legado, $ativa['id']]);
                $mexeu = true;
            } else {
                $pdo->prepare("INSERT INTO caminho_carreiras (id_usuario, nome, posicao, estado, legado, temporadas, encerrada, encerrado_em, moedas_pagas)
                               VALUES (?,?,?,?,?,?,1,NOW(),?)")
                    ->execute([$idUsuario, $nome, $pos, $json, $legado, $temporadas, $legado]);
                $mexeu = true;
            }
            // Moedas = legado, creditadas uma vez só. O WHERE encerrada=0 acima
            // é o que garante isso: um segundo POST não acha linha pra fechar.
            if ($mexeu && $legado > 0) {
                $pdo->prepare("UPDATE games_usuarios SET pontos = pontos + ? WHERE id = ?")
                    ->execute([$legado, $idUsuario]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('[caminho] encerrar: ' . $e->getMessage());
            echo json_encode(['ok' => false, 'erro' => 'falha ao encerrar']);
            exit;
        }
        echo json_encode(['ok' => true, 'legado' => $legado, 'moedas' => $legado]);
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
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700;800;900&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
/* ── Padrão visual dos jogos da FBA (base: buildplayer.php) ───────────
   Mesmos tokens, mesma topbar, mesmo card, mesmo vermelho. Dark-only,
   como o resto de games/.

   Uma diferença forçada: Poppins e Bootstrap Icons vêm de CDN, e a CSP
   do artifact bloqueia. Aqui uso a pilha do sistema e SVG inline; ao
   entrar no site, é só voltar a var(--font) do padrão e os <i class="bi">.
   ─────────────────────────────────────────────────────────────────── */
:root{
  --bg:#07070a;--panel:#101013;--panel2:#16161a;--panel3:#1c1c21;
  --border:rgba(255,255,255,.07);--border2:rgba(255,255,255,.14);
  --red:#fc0025;--red-soft:rgba(252,0,37,.12);--red-glow:rgba(252,0,37,.25);
  --text:#f0f0f3;--text2:#868690;--text3:#3c3c44;
  --green:#22c55e;--green-soft:rgba(34,197,94,.12);
  --amber:#f59e0b;--amber-soft:rgba(245,158,11,.12);
  --blue:#3b82f6;--blue-soft:rgba(59,130,246,.12);
  --radius:14px;
  --font:'Poppins',sans-serif;
  --num:ui-monospace,SFMono-Regular,"SF Mono",Menlo,Consolas,monospace;
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
.topbar-right{display:flex;align-items:center;gap:6px}
.chip{display:flex;align-items:center;gap:4px;padding:4px 10px;border-radius:20px;background:var(--panel2);
  border:1px solid var(--border);font-size:11px;font-weight:700;color:var(--text);white-space:nowrap}
.chip b{font-family:var(--num);font-variant-numeric:tabular-nums;font-weight:700}

.main{max-width:620px;margin:0 auto;padding:16px 12px 60px}

/* DUAS COLUNAS — só a partir de 940px.
   Abaixo disso o grid vira uma coluna e tudo empilha na ordem do HTML, que
   já é a ordem certa de leitura: o que importa primeiro vem primeiro. Por
   isso o lado principal vem antes no markup, e não porque está à esquerda. */
.colunas{display:grid;gap:14px}
@media (min-width:940px){
  .main{max-width:1040px;padding:14px 20px 24px}
  .colunas{grid-template-columns:minmax(0,1fr) 350px;align-items:start;gap:18px}
  /* A lateral acompanha a rolagem: numa temporada longa a súmula fica
     comprida, e sem isto o ranking sumia lá em cima. */
  .col-lado{position:sticky;top:76px}
  .intro,.intro p{max-width:none}
  h1{font-size:26px}
}
/* Numa coluna só, o respiro entre os dois blocos já vem do gap. */
.col-lado h2:first-child{margin-top:0}

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
input,select{width:100%;background:var(--panel2);border:1.5px solid var(--border);border-radius:10px;
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
.tipo{background:var(--panel2);border:1.5px solid var(--border);border-radius:var(--radius);
  padding:14px 12px;text-align:left;cursor:pointer;transition:.2s;color:var(--text);font-family:var(--font)}
.tipo:hover{border-color:var(--red);background:var(--red-soft);transform:translateY(-2px)}
.tipo.on{border-color:var(--red);background:var(--red-soft)}
.tipo b{display:block;font-size:14px;font-weight:900;letter-spacing:.2px;color:var(--text);margin-bottom:3px}
.tipo span{font-size:10.5px;color:var(--text2);line-height:1.45;display:block}

/* BOTÕES — .spin-btn e .reroll-btn do padrão */
.btn{width:100%;background:var(--red);color:#fff;border:0;border-radius:12px;padding:15px;
  font-family:var(--font);font-size:15px;font-weight:800;letter-spacing:.3px;cursor:pointer;
  transition:.15s;display:block;margin-top:4px}
.btn:hover:not(:disabled){filter:brightness(1.12)}
.btn:active:not(:disabled){transform:scale(.985)}
.btn:disabled{background:var(--panel3);color:var(--text3);cursor:not-allowed}
.btn2{background:transparent;border:1.5px solid var(--border2);color:var(--text2);border-radius:11px;
  padding:11px;font-size:12.5px;font-weight:700;margin-top:9px}
.btn2:hover{border-color:var(--blue);color:var(--blue);background:var(--blue-soft);filter:none}

/* OPÇÃO DE DECISÃO — mesma linguagem do .nota do build */
/* OPÇÃO — o botão É a decisão, então carrega o peso visual da tela. */
.op{display:block;width:100%;text-align:left;background:var(--panel2);color:var(--text);
  border:1.5px solid var(--border2);border-radius:12px;padding:10px 13px;margin-bottom:7px;
  font-family:var(--font);font-size:13px;font-weight:600;cursor:pointer;transition:.15s;line-height:1.4}
.op:hover{border-color:var(--red);background:var(--red-soft);transform:translateY(-1px);
  box-shadow:0 4px 14px rgba(0,0,0,.28)}
.op:active{transform:translateY(0)}
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

/* PLACAR DA TEMPORADA */
.placar{background:var(--panel);border:1px solid var(--border);border-radius:var(--radius);
  overflow:hidden;margin-bottom:14px}
.placar-topo{display:flex;align-items:center;gap:10px;padding:11px 14px;border-bottom:1px solid var(--border);
  background:var(--panel2)}
.ano{font-family:var(--num);font-size:14px;font-weight:700;color:var(--red);letter-spacing:.4px}
.placar-time{font-size:12.5px;font-weight:700;margin-left:auto;text-align:right;line-height:1.25;color:var(--text)}
.placar-time small{display:block;font-size:9.5px;font-weight:600;color:var(--text2);letter-spacing:.3px}

.linha-stats{display:grid;grid-template-columns:repeat(3,1fr);border-bottom:1px solid var(--border)}
.st{padding:10px 8px;text-align:center;border-right:1px solid var(--border)}
.st:last-child{border-right:none}
.st b{display:block;font-family:var(--num);font-size:22px;font-weight:900;letter-spacing:-1px;
  font-variant-numeric:tabular-nums;line-height:1;color:var(--text)}
.st span{display:block;font-size:8.5px;font-weight:700;letter-spacing:1.2px;text-transform:uppercase;
  color:var(--text);margin-top:5px}
.linha-mini{display:flex;border-bottom:1px solid var(--border)}
.mini{flex:1;padding:7px 6px;text-align:center;border-right:1px solid var(--border)}
.mini:last-child{border-right:none}
.mini b{font-family:var(--num);font-size:13px;font-weight:700;font-variant-numeric:tabular-nums;color:var(--text)}
.mini span{display:block;font-size:8px;font-weight:700;letter-spacing:1px;text-transform:uppercase;
  color:var(--text);margin-top:2px}
.campanha{padding:8px 14px;font-size:12.5px;color:var(--text2)}
.campanha b{color:var(--text);font-family:var(--num);font-variant-numeric:tabular-nums}

.premios{display:flex;flex-wrap:wrap;gap:5px;padding:0 14px 10px}
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
.ct-pe{font-family:var(--num);font-size:9.5px;color:rgba(255,255,255,.42);margin-top:16px;letter-spacing:.3px}

/* Grade densa: números da carreira e prêmios cabem sem virar lista longa. */
.grade-num{display:grid;grid-template-columns:repeat(3,1fr);gap:7px}
.gn{background:var(--panel2);border:1px solid var(--border);border-radius:9px;padding:10px 4px;text-align:center}
.gn b{display:block;font-family:var(--num);font-size:19px;font-weight:900;line-height:1;
  color:var(--text);font-variant-numeric:tabular-nums}
.gn span{display:block;font-size:8.5px;font-weight:700;letter-spacing:.6px;text-transform:uppercase;
  color:var(--text2);margin-top:5px}
.gn.zero b{color:var(--text3)}

/* A escada: o tier sozinho não diz se faltou pouco ou muito pro degrau de
   cima. Ver os oito de uma vez transforma "Lenda" em posição, não rótulo. */
.escada{display:flex;flex-direction:column-reverse;gap:2px}
.deg{display:flex;align-items:center;gap:9px;padding:6px 9px;border-radius:7px;
  font-size:12px;color:var(--text2)}
.deg .n{font-family:var(--num);font-size:10px;width:14px;color:var(--text3);flex:none}
.deg .req{margin-left:auto;font-family:var(--num);font-size:10px;color:var(--text3)}
.deg.voce{background:var(--red-soft);color:var(--text);font-weight:700}
.deg.voce .n,.deg.voce .req{color:var(--red)}
.deg.passou{color:var(--text2)}

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

const ATRIBUTOS = {tres:"3 pontos",fin:"Finalização",pas:"Passe",dri:"Drible",def:"Defesa",fis:"Físico",iq:"QI de jogo",cl:"Clutch"};

// Cortes tirados dos percentis de 200 carreiras simuladas, não do olho:
// cada faixa é a fatia que ela deve pegar. O topo tem que ser raro pra
// significar alguma coisa — se metade do grupo vira GOAT, ninguém é.
// Cortes na escala de 0 a 230, tirados dos percentis de 2500 carreiras.
// O topo é pra ser lenda urbana: 230 exige mais troféus do que apareceu em
// duas mil e quinhentas simulações.
const TIERS = [
  [0,   "Passou pelo basquete"],
  [25,  "Jogador de rotação"],
  [55,  "Titular respeitado"],
  [90,  "Astro da liga"],
  [120, "Um dos melhores da sua posição"],
  [155, "Lenda"],
  [180, "Top 10 de todos os tempos"],
  [205, "Conversa de GOAT"],
];

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
    idade:16, ano:2026,
    // Potencial: a maioria chega a ser gente boa de liga, mas o bust
    // continua existindo. Antes 22% eram bust e só 20% passavam de 88 —
    // dava carreira medíocre demais. Agora o bust é 1 em 8 e a estrela é
    // quase 1 em 3: a CARREIRA fica boa com frequência, e o que continua
    // difícil é o LEGADO, que é onde a raridade deve morar.
    // 3%: o fenômeno geracional. Só aparece na noite do draft.
    prodigio: Math.random() < 0.03,
    destaque: null, comparacao: null,
    rival: null, marcasBatidas: [], anosDivisao: 0, ultimaAposta: null,
    A, pot: (()=>{ const r = Math.random();
                   return r < 0.06 ? ri(72,81) : r < 0.55 ? ri(82,92) : ri(93,99); })(),
    fase:"base",            // base · college · fora · draft · liga · fim
    anoFase:0,
    college:null, ligaFora:null,
    time:null, gm:null, liga:null,
    pickDraft:null,
    hype:50,                // o quanto os olheiros te enxergam
    confianca:50,           // confiança do treinador → minutos
    dinheiro:0, salario:0, contrato:0,
    temporadas:[],
    trofeus:{mvp:0,titulo:0,fmvp:0,allstar:0,dpoy:0,mip:0,roy:0,cesta:0,ouro:0,euro:0},
    ultimo:null, decisaoId:null, aguardando:false, mensagem:null, resultado:null,
    finais:null, mercado:null, ofertaEscolhida:null, ovrAnterior:null, efeitoDecisao:0, decisoesUsadas:[], papel:"titular", ultimoOvr:null, picoOvr:null, ultimaVit:null,
    afastado:null,          // {tipo,anos,motivo} enquanto estiver fora
    // Perder temporada é o preço mais caro do jogo, então nem toda carreira
    // chega perto dele: o sorteio aqui decide se ESTA vai encarar uma
    // encruzilhada dessas, e riscoUsado garante que seja no máximo uma.
    riscoDaCarreira: Math.random() < 0.16, riscoUsado:false,
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
  const base = (o - 55) * 0.62 + (S.confianca - 50) * 0.30 + 20;
  return clamp(Math.round(base + ri(-3, 3)), 6, 38);
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
  const estrela = o >= 89;
  // MVP exige nível ALTO e time vencedor. É o corte que torna "ser bom em
  // time ruim" uma tragédia jogável, em vez de só um número menor.
  if (o >= 94 && vit >= 55 && ri(0,100) < 42){ out.push({t:"MVP", k:"ouro"}); S.trofeus.mvp++; }
  if (estrela && ri(0,100) < 75){ out.push({t:"All-Star", k:"normal"}); S.trofeus.allstar++; }
  if (S.A.def >= 89 && ri(0,100) < 28){ out.push({t:"Defensor do Ano", k:"ouro"}); S.trofeus.dpoy++; }
  if (st.pts >= 26 && ri(0,100) < 26){ out.push({t:"Cestinha da liga", k:"ouro"}); S.trofeus.cesta++; }
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
// nenhuma. Agora só existem três moedas de troca, todas visíveis:
//
//   ovr:  +N / -N no nível do jogador
//   time: "melhor" ou "pior" — muda de time
//   fora: temporada(s) perdida(s) por lesão ou suspensão
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

/** Aplica um efeito de decisão e devolve quanto o OVR andou. */
function aplicarEfeito(ef){
  const d = ef.ovr ? mexerOvr(ef.ovr) : 0;
  if (ef.time) trocarDeTime(ef.time === "melhor");
  if (ef.fora) afastar(ef.fora[0], ef.fora[1], ef.fora[2]);
  return d;
}

/** Descreve um efeito em duas ou três palavras, pra etiqueta do botão. */
function dizEfeito(ef){
  const p = [];
  if (ef.ovr) p.push((ef.ovr > 0 ? "+" : "−") + Math.abs(ef.ovr) + " OVR");
  if (ef.time) p.push(ef.time === "melhor" ? "troca por time melhor" : "troca por time pior");
  if (ef.fora) p.push(ef.fora[1] + (ef.fora[1] > 1 ? " temporadas fora" : " temporada fora"));
  return p.join(" e ") || "nada muda";
}

/** A aposta inteira, do jeito que aparece embaixo do botão. */
function etiquetaAposta(op){
  const c = op.chance ?? 100;
  if (c >= 100) return dizEfeito(op.bom);
  return `${c}% ${dizEfeito(op.bom)} · ${100 - c}% ${dizEfeito(op.ruim || {})}`;
}

const DECISOES = [
  // ── Corpo e saúde ────────────────────────────────────────────────────
  {id:"lesao", quando:s => s.fase === "liga" && s.ultimo && s.ultimo.jogos < 66,
   t:()=>`Você desfalcou o ${S.time} em ${82 - S.ultimo.jogos} jogos. O departamento médico quer cautela; o treinador quer você em quadra.`,
   ops:[
     {l:"Voltar antes da hora", chance:78,
      bom:{ovr:-1, txt:"Você voltou mancando e jogou assim mesmo. O vestiário te respeita — e o corpo cobrou um pedaço."},
      ruim:{fora:["lesao",1,"recaída por voltar cedo"], txt:"Você voltou antes do prazo e durou onze minutos. A recaída levou a temporada inteira."}},
     {l:"Seguir o protocolo", chance:60,
      bom:{ovr:+2, txt:"Você ficou fora até estar 100%. O treinador reclamou nos bastidores, mas você voltou inteiro — e melhor."},
      ruim:{ovr:-1, txt:"Você respeitou cada prazo e mesmo assim voltou diferente. Nem tudo o protocolo resolve."}},
   ]},

  {id:"carga", quando:s => s.fase === "liga" && s.idade >= 29,
   t:()=>`Trinta e poucos anos e um calendário de 82 jogos. A comissão propõe poupar você em back-to-backs.`,
   ops:[
     {l:"Poupar nos back-to-backs", chance:65,
      bom:{ovr:+2, txt:"Você chegou aos playoffs inteiro pela primeira vez em anos."},
      ruim:{ovr:-2, txt:"Você poupou, perdeu ritmo, e chegou aos playoffs descansado e frio."}},
     {l:"Jogar tudo", chance:45,
      bom:{ovr:+3, txt:"Oitenta e dois jogos, todos eles. O corpo aguentou e o rendimento explodiu."},
      ruim:{ovr:-3, txt:"O corpo cobrou em março. Você terminou a temporada arrastando a perna."}},
   ]},

  {id:"joelho", risco:true, quando:s => s.fase === "liga" && s.idade >= 24 && s.ultimo && s.ultimo.jogos < 76,
   t:()=>`O joelho travou de novo, e desta vez a ressonância veio feia. O cirurgião quer operar agora; o departamento médico ainda acha que dá pra tratar.`,
   ops:[
     {l:"Operar agora", chance:100,
      bom:{fora:["lesao",1,"cirurgia no joelho"], txt:"Você entrou na sala de cirurgia em outubro. A temporada acabou ali — mas o joelho volta inteiro."}},
     {l:"Tratar sem cirurgia", chance:58,
      bom:{ovr:-2, txt:"Segurou. Você jogou o ano inteiro com dor e quase ninguém percebeu."},
      ruim:{fora:["lesao",2,"ruptura no joelho"], txt:"Cedeu em dezembro, na frente de todo mundo. Ruptura completa: dois anos fora."}},
   ]},

  {id:"tendao", risco:true, quando:s => s.fase === "liga" && s.idade >= 27,
   t:()=>`Estalou no aquecimento. O tendão de aquiles não é dúvida, é diagnóstico. A pergunta é o que fazer com o tempo que vem.`,
   ops:[
     {l:"Reabilitação completa", chance:100,
      bom:{fora:["lesao",2,"ruptura do tendão de aquiles"], txt:"Você aceitou o calendário longo dos médicos: dois anos de trabalho invisível, longe de tudo."}},
     {l:"Programa acelerado", chance:55,
      bom:{fora:["lesao",1,"aquiles · recuperação acelerada"], txt:"Deu certo: você voltou em doze meses. Mais lento que antes — mas voltou."},
      ruim:{fora:["lesao",2,"aquiles · recuperação rompida"], txt:"O tendão não aguentou o atalho. De volta à estaca zero: dois anos."}},
   ]},

  // ── Disciplina ───────────────────────────────────────────────────────
  {id:"antidoping", risco:true, quando:s => s.fase === "liga" && s.idade >= 25,
   t:()=>`Um suplemento que você toma há anos apareceu com substância proibida no exame. A liga quer uma resposta em 72 horas.`,
   ops:[
     {l:"Assumir e colaborar", chance:100,
      bom:{fora:["suspensao",1,"exame antidoping"], txt:"Você admitiu o erro, entregou o fornecedor e aceitou a pena mínima: um ano."}},
     {l:"Brigar na justiça", chance:45,
      bom:{txt:"Seus advogados provaram contaminação no lote. Você foi absolvido e a liga engoliu em seco."},
      ruim:{fora:["suspensao",2,"antidoping · pena agravada"], txt:"Você perdeu, e o tribunal tratou a defesa como má-fé. Dois anos."}},
   ]},

  {id:"tunel", risco:true, quando:s => s.fase === "liga" && s.confianca < 55,
   t:()=>`A discussão no túnel virou empurrão, e o empurrão virou vídeo. A liga abriu processo disciplinar.`,
   ops:[
     {l:"Pedir desculpas em público", chance:100,
      bom:{ovr:-1, txt:"Você leu um pedido de desculpas escrito pela assessoria. Foi humilhante, e jogou o ano inteiro com aquilo nas costas."}},
     {l:"Não recuar", chance:50,
      bom:{ovr:+2, txt:"Você bancou. Levou multa alta, e o vestiário inteiro passou a te seguir."},
      ruim:{fora:["suspensao",1,"conduta antidesportiva"], txt:"A liga fez de você o exemplo: suspenso por uma temporada."}},
   ]},

  {id:"apostas", risco:true, quando:s => s.fase === "liga" && s.idade >= 26,
   t:()=>`Uma reportagem liga seu nome a apostas em jogos da liga. Você sabe que não apostou — mas seu primo usou seu cadastro.`,
   ops:[
     {l:"Entregar tudo à investigação", chance:100,
      bom:{fora:["suspensao",1,"investigação de apostas"], txt:"Você abriu tudo. A liga reconheceu a colaboração e aplicou um ano, o mínimo previsto."}},
     {l:"Negar e proteger a família", chance:42,
      bom:{txt:"A investigação não chegou a você. Seu primo sumiu do mapa e o assunto morreu."},
      ruim:{fora:["suspensao",2,"apostas · omissão"], txt:"Encontraram os registros. Omitir custou o dobro: duas temporadas."}},
   ]},

  // ── Time ─────────────────────────────────────────────────────────────
  {id:"troca", quando:s => s.fase === "liga" && s.anoFase >= 2 && s.forcaBase < 52,
   t:()=>`O ${S.time} vai mal e não tem plano. Seu empresário diz que dá pra forçar uma troca pra um candidato ao título.`,
   ops:[
     {l:"Pedir troca", chance:70,
      bom:{time:"melhor", txt:"Saiu na semana do prazo. Você desembarcou num vestiário que joga por alguma coisa."},
      ruim:{time:"pior", ovr:-1, txt:"Forçaram sua saída pro primeiro time que aceitou pagar. Foi pior que o anterior."}},
     {l:"Ficar e puxar o time", chance:55,
      bom:{ovr:+3, txt:"Você virou a referência de uma reconstrução. Cresceu carregando gente nas costas."},
      ruim:{ovr:-3, txt:"Você ficou, o time seguiu ruim, e mais um ano seu foi embora sem nada."}},
   ]},

  {id:"superstime", quando:s => s.fase === "liga" && s.anoFase >= 3 && ovr(s.A, s.pos) >= 82,
   t:()=>`Um candidato ao título quer você — mas como terceira opção, não como o cara. Menos bola, mais chance de anel.`,
   ops:[
     {l:"Ir pro superteam", chance:100,
      bom:{time:"melhor", ovr:-2, txt:"Você trocou volume por chance de anel. Seus números caíram na hora."}},
     {l:"Ficar sendo o cara", chance:55,
      bom:{ovr:+3, txt:"Você ficou, assumiu tudo e provou que o time era seu."},
      ruim:{ovr:-3, txt:"Você ficou e afundou junto. A janela passou e ninguém voltou a ligar."}},
   ]},

  {id:"tecnico", quando:s => s.fase === "liga" && s.anoFase >= 2,
   t:()=>`Treinador novo, sistema novo. Ele quer você fazendo o que não é o seu forte.`,
   ops:[
     {l:"Aprender o sistema dele", chance:58,
      bom:{ovr:+3, txt:"Você virou outro jogador dentro do sistema. Ninguém mais te chama de unidimensional."},
      ruim:{ovr:-3, txt:"Você nunca se achou naquilo. Um ano jogando errado é um ano perdido."}},
     {l:"Jogar do seu jeito", chance:50,
      bom:{ovr:+2, txt:"Você bateu o pé, produziu, e o treinador reescreveu o sistema em volta de você."},
      ruim:{time:"pior", txt:"O treinador ganhou o braço de ferro e pediu sua cabeça na diretoria. Você foi negociado."}},
   ]},

  {id:"banco", quando:s => s.fase === "liga" && s.confianca < 35,
   t:()=>`O treinador te tirou do quinteto. A imprensa quer saber se você pediu pra sair.`,
   ops:[
     {l:"Aceitar e trabalhar calado", chance:60,
      bom:{ovr:+2, txt:"Você engoliu e treinou. Em três meses o treinador te devolveu a vaga — e você voltou melhor."},
      ruim:{ovr:-2, txt:"Você engoliu, treinou, e o ano inteiro passou no banco — enferrujando."}},
     {l:"Cobrar publicamente", chance:45,
      bom:{ovr:+2, txt:"Deu certo: a torcida comprou sua briga e o treinador cedeu."},
      ruim:{time:"pior", txt:"Saiu pela culatra. Você virou 'problema de vestiário' e foi despachado pro primeiro time que aceitou."}},
   ]},

  {id:"lider", quando:s => s.fase === "liga" && s.anoFase >= 3 && ovr(s.A, s.pos) >= 76,
   t:()=>`O capitão saiu. O vestiário está olhando pra você.`,
   ops:[
     {l:"Assumir a braçadeira", chance:70,
      bom:{ovr:+2, txt:"Você virou a voz do vestiário e cresceu no papel."},
      ruim:{ovr:-2, txt:"O peso pesou. Liderar drenou o que você tinha de melhor em quadra."}},
     {l:"Liderar sem o título", chance:60,
      bom:{ovr:+2, txt:"Você preferiu o exemplo ao discurso. Funcionou, à sua maneira."},
      ruim:{ovr:-1, txt:"Sem a braçadeira, sua palavra não pesou. O vestiário seguiu outro."}},
   ]},

  // ── Desenvolvimento ──────────────────────────────────────────────────
  {id:"treino", quando:s => true,
   t:()=>`Entressafra. Você tem um verão inteiro pra escolher o que fazer com ele.`,
   ops:[
     {l:"Treinar pesado o verão inteiro", chance:62,
      bom:{ovr:+3, txt:"Três meses de academia às seis da manhã. Você voltou outro jogador."},
      ruim:{ovr:-3, txt:"Você exagerou na carga e chegou à pré-temporada gasto."}},
     {l:"Trabalho técnico com especialista", chance:70,
      bom:{ovr:+2, txt:"Você contratou um treinador só seu e refinou o que já sabia fazer."},
      ruim:{ovr:-2, txt:"O especialista mexeu no que não estava quebrado. Você passou o ano desaprendendo."}},
     {l:"Descansar de verdade", chance:40,
      bom:{ovr:+2, txt:"Você sumiu do mapa por três meses e voltou leve, inteiro e faminto."},
      ruim:{ovr:-1, txt:"Você voltou descansado e enferrujado. Levou meia temporada pra achar o ritmo."}},
   ]},

  {id:"posicao", quando:s => s.fase === "liga" && s.anoFase >= 2 && s.anoFase <= 8,
   t:()=>`A comissão acha que seu corpo pede outra posição. Mudar agora é reaprender o jogo.`,
   ops:[
     {l:"Mudar de posição", chance:55,
      bom:{ovr:+4, txt:"Deu certo demais. Você achou o lugar onde sempre devia ter jogado."},
      ruim:{ovr:-3, txt:"Não era pra ser. Você passou o ano parecendo um estranho em quadra."}},
     {l:"Continuar onde está", chance:55,
      bom:{ovr:+2, txt:"Você recusou e dobrou a aposta no que já fazia bem."},
      ruim:{ovr:-1, txt:"Você recusou, e a liga seguiu andando pro lado que você não quis ir."}},
   ]},

  {id:"veterano", quando:s => s.fase === "liga" && s.anoFase <= 4,
   t:()=>`Um veterano de 36 anos, dois anéis, se ofereceu pra te ensinar. Custa suas manhãs de folga.`,
   ops:[
     {l:"Aceitar a mentoria", chance:60,
      bom:{ovr:+3, txt:"Ele te ensinou o que nenhum treinador ensina. Você pulou dois anos de aprendizado."},
      ruim:{ovr:-3, txt:"Ele quis te transformar nele. Você passou um ano jogando um basquete que não era o seu."}},
     {l:"Seguir sozinho", chance:45,
      bom:{ovr:+2, txt:"Você aprendeu apanhando, do seu jeito — e o que ficou, ficou pra sempre."},
      ruim:{ovr:-1, txt:"Você repetiu os mesmos erros o ano inteiro sem ninguém pra apontar."}},
   ]},

  {id:"jovem", quando:s => s.fase === "liga" && s.idade >= 30,
   t:()=>`O time draftou um garoto na sua posição. Ele te procura pra aprender.`,
   ops:[
     {l:"Ensinar tudo que sabe", chance:55,
      bom:{ovr:+2, txt:"Você ensinou. Ele virou titular em dois anos, e disse seu nome em toda entrevista."},
      ruim:{ovr:-1, txt:"Você ensinou bem demais. Ele tomou sua vaga em uma temporada."}},
     {l:"Segurar a vaga", chance:55,
      bom:{ovr:+2, txt:"Você não deu um palmo. Jogou o melhor basquete em anos só pra provar um ponto."},
      ruim:{ovr:-2, txt:"A briga pela vaga consumiu você. O garoto passou por cima mesmo assim."}},
   ]},

  {id:"investir", quando:s => s.fase === "liga" && s.anoFase >= 4,
   t:()=>`Seu preparador quer redesenhar seu jogo do zero na entressafra. Um ano de desconforto por algo novo.`,
   ops:[
     {l:"Reconstruir o jogo", chance:45,
      bom:{ovr:+5, txt:"Você apagou o que era e desenhou de novo. Deu MUITO certo."},
      ruim:{ovr:-3, txt:"Você virou um híbrido que não fazia bem nem o novo nem o velho."}},
     {l:"Afinar o que já funciona", chance:70,
      bom:{ovr:+2, txt:"Nada de revolução: você lixou as arestas do que já era bom."},
      ruim:{ovr:-2, txt:"Um verão inteiro de retoque e você saiu pior do que entrou."}},
   ]},

  // ── Fora de quadra ───────────────────────────────────────────────────
  {id:"patrocinio", quando:s => s.fase === "liga" && s.anoFase >= 2,
   t:()=>`Uma marca grande quer você. O contrato pede quinze dias de agenda na pré-temporada.`,
   ops:[
     {l:"Assinar", chance:40,
      bom:{ovr:+1, txt:"Deu pra conciliar. Você virou rosto de campanha sem perder um treino."},
      ruim:{ovr:-2, txt:"A agenda comeu sua pré-temporada. Você começou o ano atrás de todo mundo."}},
     {l:"Recusar e focar", chance:60,
      bom:{ovr:+2, txt:"Você recusou o dinheiro e passou a pré-temporada inteira em quadra."},
      ruim:{ovr:-1, txt:"Você recusou, treinou sozinho e não evoluiu nada. Só perdeu o cheque."}},
   ]},

  {id:"imprensa", quando:s => s.fase === "liga" && s.anoFase >= 2,
   t:()=>`Um jornalista publicou que você é superestimado. O microfone está na sua frente.`,
   ops:[
     {l:"Responder na quadra", chance:65,
      bom:{ovr:+2, txt:"Você não disse uma palavra e jogou o melhor basquete da vida por dois meses."},
      ruim:{ovr:-2, txt:"Você calou e engoliu. A pauta continuou lá, e você jogou com ela na cabeça."}},
     {l:"Responder no microfone", chance:35,
      bom:{ovr:+2, txt:"A resposta viralizou e virou combustível. Você jogou com raiva o ano inteiro."},
      ruim:{ovr:-2, txt:"Virou novela. Cada erro seu passou a ser manchete e você jogou travado."}},
   ]},

  {id:"familia", quando:s => s.fase === "liga" && s.idade >= 26,
   t:()=>`Nasceu seu primeiro filho na semana da viagem mais longa da temporada.`,
   ops:[
     {l:"Ficar em casa alguns jogos", chance:60,
      bom:{ovr:+2, txt:"Você perdeu quatro jogos e ganhou algo que não cabe em estatística. Voltou leve."},
      ruim:{ovr:-1, txt:"Você ficou, e voltou fora de ritmo. Levou o resto do ano pra recuperar a forma."}},
     {l:"Viajar com o time", chance:45,
      bom:{ovr:+2, txt:"Você jogou por dois. Foi a melhor sequência da sua carreira."},
      ruim:{ovr:-2, txt:"Você estava em quadra e a cabeça em casa. Passou o mês inteiro assim."}},
   ]},

  {id:"camisa", quando:s => s.fase === "liga" && s.anoFase >= 5 && ovr(s.A, s.pos) >= 80,
   t:()=>`O clube quer estender seu contrato e transformar você em símbolo da franquia — mesmo sem chance de título.`,
   ops:[
     {l:"Virar símbolo da casa", chance:55,
      bom:{ovr:+2, txt:"Você abriu mão de perseguir anel pra ser o cara de um lugar só. A cidade te adotou."},
      ruim:{ovr:-1, txt:"Você ficou por amor e o time não retribuiu. Sua carreira parou junto com o projeto."}},
     {l:"Buscar título em outro lugar", chance:100,
      bom:{time:"melhor", ovr:-1, txt:"Você saiu atrás do anel. A torcida queimou sua camisa — e você foi disputar alguma coisa."}},
   ]},

  {id:"polemica", quando:s => s.fase === "liga" && s.anoFase >= 3,
   t:()=>`Uma declaração sua de três anos atrás voltou a circular, fora de contexto.`,
   ops:[
     {l:"Explicar publicamente", chance:65,
      bom:{txt:"Você explicou com calma e o assunto morreu em dois dias."},
      ruim:{ovr:-2, txt:"Explicar deu combustível. Rendeu duas semanas e te tirou do sério."}},
     {l:"Ignorar", chance:55,
      bom:{ovr:+1, txt:"Você não deu bola e ninguém mais deu. Seguiu jogando."},
      ruim:{ovr:-2, txt:"O silêncio virou culpa aos olhos de todo mundo. Você jogou sob vaia o ano inteiro."}},
   ]},

  // ── Momentos grandes ─────────────────────────────────────────────────
  {id:"selecao", quando:s => s.fase === "liga" && s.anoFase >= 2 && ovr(s.A, s.pos) >= 78,
   t:()=>`A seleção te convocou pro verão. É orgulho e é desgaste — e é o verão inteiro.`,
   ops:[
     {l:"Vestir a camisa", chance:55,
      bom:{ovr:+3, txt:"Jogar contra os melhores do mundo por um verão te fez subir de patamar."},
      ruim:{ovr:-2, txt:"Você voltou da seleção sem pernas e começou a temporada atrás."}},
     {l:"Passar esse verão", chance:65,
      bom:{ovr:+2, txt:"Você recusou a convocação e passou o verão trabalhando o seu jogo."},
      ruim:{ovr:-2, txt:"O verão livre virou verão perdido. Você voltou igual, ou pior."}},
   ]},

  {id:"ultimaposse", quando:s => s.fase === "liga" && s.anoFase >= 1,
   t:()=>`Jogo decisivo, dois pontos atrás, quatro segundos. A jogada é sua ou dele.`,
   ops:[
     {l:"A bola é minha", chance:50,
      bom:{ovr:+3, txt:"Você pegou a bola, subiu em cima de dois e afundou o arremesso. A arena veio abaixo."},
      ruim:{ovr:-2, txt:"Você forçou, errou feio, e o clipe rodou o mundo até junho."}},
     {l:"Passar pro melhor posicionado", chance:70,
      bom:{ovr:+1, txt:"Você achou o companheiro livre e ele converteu. Jogada certa, crédito dividido."},
      ruim:{ovr:-1, txt:"Você passou, ele errou, e a imprensa perguntou por que você não arriscou."}},
   ]},

  {id:"allstarfds", quando:s => s.fase === "liga" && ovr(s.A, s.pos) >= 80,
   t:()=>`Fim de semana de All-Star. Te chamaram pro torneio de três pontos e pro jogo das estrelas.`,
   ops:[
     {l:"Entrar em tudo", chance:55,
      bom:{ovr:+2, txt:"Você brilhou no fim de semana inteiro e voltou embalado."},
      ruim:{ovr:-2, txt:"Você torceu o tornozelo numa exibição. Numa EXIBIÇÃO."}},
     {l:"Só o jogo das estrelas", chance:85,
      bom:{ovr:+1, txt:"Você fez o mínimo, apareceu, e descansou os quatro dias."},
      ruim:{txt:"Fim de semana morno. Passou sem deixar marca."}},
   ]},

  {id:"amistoso", quando:s => s.fase === "liga" && s.anoFase >= 1,
   t:()=>`Pré-temporada na Ásia: dez dias de avião e três amistosos. O clube deixa você escolher.`,
   ops:[
     {l:"Viajar com o time", chance:60,
      bom:{ovr:+2, txt:"Dez dias grudado no elenco valeram por dois meses de entrosamento."},
      ruim:{ovr:-1, txt:"Você voltou com o fuso destruído e levou três semanas pra normalizar."}},
     {l:"Ficar treinando", chance:60,
      bom:{ovr:+2, txt:"Você ficou, treinou sozinho no ginásio vazio e chegou pronto."},
      ruim:{ovr:-1, txt:"Você ficou e o time voltou entrosado sem você. Levou meses pra encaixar."}},
   ]},

  {id:"rival", quando:s => s.fase === "liga" && s.anoFase >= 2,
   t:()=>`Um jogador da sua posição, draftado no mesmo ano, virou o assunto. Toda entrevista compara vocês dois.`,
   ops:[
     {l:"Comprar a rivalidade", chance:55,
      bom:{ovr:+3, txt:"A rivalidade virou o motor da sua carreira. Você jogava diferente contra ele — e melhor contra todo mundo."},
      ruim:{ovr:-3, txt:"Você se obcecou por ele. Jogou pensando na comparação e esqueceu do seu jogo."}},
     {l:"Ignorar a comparação", chance:55,
      bom:{ovr:+2, txt:"Você não entrou na dança e seguiu no seu ritmo."},
      ruim:{ovr:-1, txt:"Você ignorou, e ele passou por cima de você em silêncio."}},
   ]},

  {id:"jovemastro", quando:s => s.fase === "liga" && s.anoFase <= 2 && ovr(s.A, s.pos) >= 78,
   t:()=>`Dois anos de liga e já te chamam de futuro da posição. A pressão veio junto.`,
   ops:[
     {l:"Abraçar o rótulo", chance:55,
      bom:{ovr:+3, txt:"Você virou o rosto da liga e correspondeu a cada palavra."},
      ruim:{ovr:-3, txt:"O rótulo virou peso. Cada jogo ruim virou 'decepção' e você encolheu."}},
     {l:"Baixar a bola", chance:65,
      bom:{ovr:+2, txt:"Você pediu calma e foi trabalhar longe do barulho. Evoluiu em silêncio."},
      ruim:{ovr:-2, txt:"Você sumiu do radar e ninguém foi te procurar. O ano passou por cima."}},
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
  // Nem todo ano tem. Menos frequente no começo, quando cada escolha ainda
  // está montando o jogador, e mais comum depois que a carreira se define.
  const chanceDeTer = S.anoFase <= 2 ? 0.85 : 0.62;
  if (Math.random() > chanceDeTer) return null;

  const recentes = S.decisoesUsadas || [];
  const cabe = d => {
    // Decisão de risco só concorre na carreira sorteada pra isso, e some
    // depois que uma sai. Sem esse corte, três das quatro apareciam em
    // quase toda carreira e 80% delas perdiam temporada.
    if (d.risco && (!S.riscoDaCarreira || S.riscoUsado)) return false;
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

  if (!candidatas.length) return null;
  const escolhida = ineditas.length ? pick(ineditas) : candidatas[0];

  if (escolhida.risco) S.riscoUsado = true;
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
  const lista = timesDaLiga();
  let novo = S.time;
  while (novo === S.time) novo = pick(lista);
  S.time = novo;
  if (S.modo === "fba"){ S.gm = gmDoTime(novo); }
  S.forcaBase = melhor ? ri(62, 88) : ri(40, 80);
  S.confianca = 50;
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

function clubesDoPais(nac){
  return (CLUBES_FORA[nac] || []).concat(CLUBES_GLOBAIS);
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

function gerarOfertas(){
  const v = valorDeMercado();
  const lista = timesDaLiga().filter(t => t !== S.time);
  const ofertas = [];
  const timeAleatorio = () => pick(lista);

  // O prazo vem DENTRO da proposta, junto do time e do salário. Antes era
  // uma segunda tela ("por quantos anos?"), o que picava uma decisão só em
  // duas e escondia o que estava sendo comparado: o pacote inteiro.
  const anosPor = {renovar:[3,4], grana:[4,5], contender:[2,3], banco:[1,2], exterior:[2,3], provar:[1,1]};

  // Renovar onde está: sempre existe, mas o valor depende da confiança.
  const fator = 0.8 + (S.confianca/100) * 0.5;
  ofertas.push({tipo:"renovar", time:S.time, salario:Math.max(1,Math.round(v*fator)),
                forca:S.forcaBase, papel:"titular",
                nota:"O time que te conhece. Sem mudança, sem surpresa."});

  // A aposta em si mesmo: um ano, pagando mais, pra voltar ao mercado
  // valendo o que você provar. É o contrapeso curto das ofertas longas.
  if (v >= 6){
    ofertas.push({tipo:"provar", time:S.time, salario:Math.max(2,Math.round(v*1.18)),
                  forca:S.forcaBase, papel:"titular",
                  nota:"Um ano só, pagando bem. Você aposta que vale mais no ano que vem."});
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
  if (S.modo === "fba"){ S.gm = gmDoTime(oferta.time); }
  if (oferta.tipo !== "renovar" && oferta.tipo !== "provar"){
    S.forcaBase = oferta.forca;
    S.confianca = oferta.papel === "reserva" ? 34 : 52;
  }
  // O desconto do prazo já entrou no salário quando a oferta foi gerada:
  // aplicar de novo aqui cobraria a mesma conta duas vezes.
  S.salario = Math.max(1, oferta.salario);
  S.contrato = oferta.anos;
  S.papel = oferta.papel;
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
let LOGOS = null;
function logoDoTime(nome){
  if (!LOGOS){
    LOGOS = {};
    (window.__TIMES_FBA__ || FBA_TIMES).forEach(t => { if (t[1]) LOGOS[t[0]] = t[1]; });
    (window.__TIMES_NBA__ || NBA).forEach(t => { if (t[1]) LOGOS[t[0]] = t[1]; });
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

function topo(){
  const chips = [];
  if (S && !S.encerrada && S.fase === "liga"){
    chips.push(`<div class="chip">OVR <b>${ovr(S.A,S.pos)}</b></div>`);
    chips.push(`<div class="chip">${S.idade} anos</div>`);
    chips.push(`<div class="chip" style="color:var(--amber)">$<b>${S.dinheiro}</b>M</div>`);
  }
  return `<div class="topbar">
    <div class="topbar-left">
      <a href="../index.php" class="back-btn" title="Voltar">${SETA}</a>
      <span class="game-title">O <span>Caminho</span><span class="daily-badge">carreira</span></span>
    </div>
    <div class="topbar-right">${chips.join("")}<div class="chip" style="color:var(--amber)">${window.__MOEDAS__ ?? 0}</div></div>
  </div><div class="main">`;
}

function render(){
  if (!S) return telaInicio();
  if (S.encerrada) return telaFim();
  if (S.fase === "base")   return telaCriar();
  if (S.fase === "escolha")return telaCaminho();
  if (S.fase === "college" || S.fase === "fora") return telaFormacao();
  if (S.finais)            return telaFinais();
  if (S.mercado)           return telaMercado();
  if (S.fase === "draft")  return telaDraft();
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
      <button class="btn" onclick="iniciar()">Começar</button>`}
    `;
  app().innerHTML = topo() + colunas(principal, ranking()) + `</div>`;
}

// O nome vem da última carreira desta conta, impresso pelo PHP. Vem do
// BANCO e não do localStorage de propósito: quem joga do celular e do
// computador é a mesma pessoa, e digitar o nome de novo a cada carreira é
// atrito sem motivo.
let rascunho = {nome: window.__ULTIMO_NOME__ || "", pos:"SG", arq:"atirador", nac:"BRA", modo:"nba"};

function iniciar(){ S = null; apagar(); telaCriar(true); }
function continuar(){ S = carregar(); render(); }

function telaCriar(){
  const cartoes = (obj, campo, chaves) => chaves.map(k => {
    const o = obj[k];
    return `<button class="tipo ${rascunho[campo]===k?'on':''}" onclick="rascunho.${campo}='${k}';telaCriar()">
      <b>${esc(o.n)}</b><span>${esc(o.d)}</span></button>`;
  }).join("");

  app().innerHTML = topo() + `
    <h1>Quem é você?</h1>
    <p class="lead">Isso define seu ponto de partida — não o seu teto.</p>

    <label>Nome</label>
    <input id="nm" value="${esc(rascunho.nome)}" placeholder="Seu nome de jogador" maxlength="26"
           oninput="rascunho.nome=this.value">

    <label>Nacionalidade</label>
    <select onchange="rascunho.nac=this.value">
      ${NACOES.map(n=>`<option value="${n[0]}" ${rascunho.nac===n[0]?"selected":""}>${esc(n[1])}</option>`).join("")}
    </select>

    <label>Posição</label>
    <div class="grade">${cartoes(POSICOES,"pos",Object.keys(POSICOES))}</div>

    <label>Jeito de jogar</label>
    <div class="grade">${cartoes(ARQUETIPOS,"arq",Object.keys(ARQUETIPOS))}</div>

    <label>Onde você quer chegar</label>
    <div class="grade">
      <button class="tipo ${rascunho.modo==='nba'?'on':''}" onclick="rascunho.modo='nba';telaCriar()">
        <b>NBA</b><span>O caminho clássico. Times reais.</span></button>
      <button class="tipo ${rascunho.modo==='fba'?'on':''}" onclick="rascunho.modo='fba';telaCriar()">
        <b>FBA</b><span>RISE → NEXT → ELITE. Times e GMs da liga de verdade.</span></button>
    </div>

    <button class="btn" style="margin-top:18px" onclick="criar()">Começar aos 16 anos</button>`;

  const i = document.getElementById("nm");
  if (i) i.oninput = e => rascunho.nome = e.target.value;
}

function criar(){
  const nome = (rascunho.nome || "").trim() || "Jogador Sem Nome";
  S = novaCarreira(nome, rascunho.pos, rascunho.arq, rascunho.nac, rascunho.modo);
  S.fase = "escolha";
  salvar(); render();
}

function telaCaminho(){
  const o = ovr(S.A, S.pos);
  app().innerHTML = topo() + `
    <h1>18 anos.<br>Pra onde agora?</h1>
    <p class="lead">Você é <b style="color:var(--text)">${o} de nível</b> e os olheiros te veem como <b style="color:var(--red)">${faixaPotencial(S.pot).toLowerCase()}</b>. O caminho muda o que você vira.</p>

    <h2>Universidade nos Estados Unidos</h2>
    <div class="grade">
      ${COLLEGES.map((c,i)=>`<button class="tipo" onclick="escolherCollege(${i})">
        <b>${esc(c.n)}</b><span><b style="color:var(--red);font-size:9.5px;letter-spacing:.6px">${esc(c.f.toUpperCase())}</b><br>${esc(c.d)}</span></button>`).join("")}
    </div>

    <h2>${S.nac !== "USA" ? "Ou virar profissional agora" : "Ou pular a faculdade"}</h2>
    <div class="grade">
      ${caminhosProfissionais().map((l,i)=>`<button class="tipo" onclick="escolherFora(${i})">
        <b>${esc(l.n)}</b><span>${l.casa ? `<b style="color:var(--green);font-size:9.5px;letter-spacing:.6px">EM CASA</b><br>` : ""}${esc(l.d)}</span></button>`).join("")}
    </div>
    <p class="nota-txt">Em qualquer caminho você decide, ano a ano, quando entrar no draft.${S.nac !== "USA" ? " Ou não entrar nunca — dá pra fazer a carreira inteira fora." : ""}</p>`;
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
  const desempenho = (st.pts*1.6 + st.reb*0.9 + st.ast*1.1) + (o-60);
  S.hype = clamp(S.hype + Math.round((desempenho - 34) * 0.7), 5, 99);

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
      && ovr(S.A, S.pos) >= 74 && ri(0,100) < 16){
    S.trofeus.euro++; S.destaque = "euroliga";
    S.hype = clamp(S.hype + 14, 5, 99);
    premios.push("Campeão da Euroliga");
    S.mensagem = "Você levantou a Euroliga. Os olheiros da NBA pararam de te tratar como aposta.";
  }

  S.temporadas.push({ano:S.ano, idade:S.idade, time:S.time, ...st, premios, campanha:null, formacao:true});
  salvar(); telaFormacao();
}

function telaFormacao(){
  const st = S.ultimo;
  const o = ovr(S.A, S.pos);
  const projecao = S.hype >= 85 ? "top 5 do draft" : S.hype >= 70 ? "1ª rodada" : S.hype >= 52 ? "fim da 1ª rodada" : S.hype >= 35 ? "2ª rodada" : "fora do draft";
  const ultimoAno = S.anoFase >= 4;

  app().innerHTML = topo() + `
    ${barra()}
    ${placar(st, `${S.anoFase}º ano`, S.time, null, [])}
    <div class="bpcard">
      <div class="bpcard-title">Projeção de draft</div>
      <p class="dec-txt">Os olheiros hoje te colocam como <b style="color:var(--red)">${projecao}</b>.
      ${ultimoAno ? "Acabou seu tempo de universidade — é hora." : "Mais um ano pode te valorizar. Ou te machucar."}</p>
      ${ultimoAno ? `
        <button class="btn" onclick="irAoDraft()">Entrar no draft</button>` : `
        <button class="op" onclick="irAoDraft()">Sair para o draft agora
          <small>Pega o que estiver na mesa. ${S.hype >= 70 ? "E está bom." : "E pode não ser muito."}</small></button>
        <button class="op" onclick="avancarFormacao()">Ficar mais um ano
          <small>Mais tempo pra crescer — e pra dar errado.</small></button>`}
    </div>`;
}

function irAoDraft(){ S.fase = "draft"; salvar(); telaDraft(); }

function telaDraft(){
  if (!S.pickDraft){
    // Prodígio só vira prodígio se a formação confirmou: fenômeno sorteado
    // que jogou mal a universidade inteira não pode entrar como top 3.
    if (S.prodigio && S.hype >= 72) S.destaque = "prodigio";

    // A posição sai do hype com ruído: você colhe o que plantou, mas a
    // noite do draft nunca é totalmente previsível.
    const base = 61 - Math.round(S.hype * 0.62);
    S.pickDraft = S.destaque === "prodigio" ? ri(1, 3)
                : S.destaque === "euroliga" ? clamp(base + ri(-9, 2), 1, 40)
                : clamp(base + ri(-7, 9), 1, 61);
    const lista = timesDaLiga();
    S.time = pick(lista);
    if (S.modo === "fba"){ S.gm = gmDoTime(S.time); }
    S.liga = S.modo === "fba" ? "RISE" : "NBA";
    S.fase = "liga"; S.anoFase = 0;
    S.forcaBase = ri(30, 85);
    S.confianca = Math.round(clamp(70 - S.pickDraft*0.6, 25, 85));
    S.salario = Math.max(1, Math.round(Math.pow((62 - S.pickDraft)/61, 2) * 26) + 1);
    S.contrato = 4;
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
    salvar();
  }
  const naoDraftado = S.pickDraft > 60;
  const manchete = S.destaque === "prodigio" ? "Chamaram seu nome primeiro."
                 : S.destaque === "euroliga" ? "Você chegou com currículo."
                 : naoDraftado ? "Ninguém chamou seu nome." : "Noite do draft.";
  app().innerHTML = topo() + `
    <h1>${manchete}</h1>
    <div class="bpcard centro">
      ${naoDraftado ? `<p>Você não foi draftado. Um time te ofereceu contrato de teste — é isso ou o exterior.</p>`
        : `<div class="bpcard-title">Escolha nº</div>
           <div class="grande">${S.pickDraft}</div>
           <div style="margin:10px 0 4px">${marca(S.time, 46)}</div>
           <p style="margin:8px 0 0"><b style="color:var(--text);font-size:16px">${esc(S.time)}</b>
           ${S.gm ? `<br><span style="font-size:12px;color:var(--text2)">GM: ${esc(S.gm)}</span>` : ""}
           ${S.liga ? `<br><span style="font-size:12px;color:var(--text2)">${esc(S.liga)} · $${S.salario}M por ano</span>` : ""}</p>`}
    </div>
    ${naoDraftado ? "" : `
    <div class="bpcard">
      <div class="bpcard-title">Ficha dos olheiros</div>
      <p class="dec-txt" style="margin:0">Comparam você a <b style="color:var(--red)">${esc(comparacaoDeDraft())}</b>.
      ${S.destaque === "prodigio" ? "A liga inteira já sabia seu nome antes do primeiro jogo."
        : S.destaque === "euroliga" ? "Um título europeu no currículo vale mais que qualquer treino fechado."
        : "É a promessa. Cumprir é com você."}</p>
    </div>`}
    <button class="btn" onclick="jogarAno()">Primeira temporada</button>`;
}

function barra(){
  const p = clamp(((S.idade - 16) / 22) * 100, 0, 100);
  return `<div class="barra-topo"><i style="width:${p}%"></i></div>`;
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
  if (ef.time === "melhor") return "bom";
  if (ef.time === "pior") return "ruim";
  if ((ef.ovr || 0) > 0) return "bom";
  if ((ef.ovr || 0) < 0) return "ruim";
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

function placar(st, rotuloAno, time, campanha, premios){
  const {o, d} = deltaOvr();
  const faixa = faixaOvr(o);
  const cor = d > 0 ? "var(--green)" : d < 0 ? "var(--red)" : "var(--text3)";
  const sinal = d > 0 ? `+${d}` : d < 0 ? `${d}` : "=";
  return `<div class="placar">
    <div class="placar-topo">
      ${marca(time.split(" · ")[0], 30)}
      <span class="ano">${esc(rotuloAno)}</span>
      <span class="placar-time">${esc(time)}<small>${S.idade} anos</small></span>
    </div>
    <div class="ovr-linha" style="--cor:${faixa[1]}">
      <span class="ovr-esq"><span class="ovr-rot">Overall</span><span class="ovr-faixa">${faixa[2]}</span></span>
      <span class="ovr-val">${o}</span>
      <span class="ovr-delta" style="color:${cor}">${sinal}</span>
      <span class="ovr-barra"><i style="width:${clamp(o,0,99)}%"></i></span>
    </div>
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
    ${campanha ? `<div class="campanha">${campanha}</div>` : ""}
    ${premios.length ? `<div class="premios">${premios.map(p=>`<span class="pr ${p.k}">${esc(p.t)}</span>`).join("")}</div>` : ""}
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
  S.confianca = clamp(S.confianca - (lesao ? 14 : 24), 5, 99);
  S.hype      = clamp(S.hype      - (lesao ? 10 : 20), 5, 99);

  S.temporadas.push({ano:S.ano, idade:S.idade, time:S.time, pts:0, reb:0, ast:0, min:0, jogos:0,
                     vit:0, premios:[], campeao:false, perdida:af.tipo, motivo:af.motivo});

  S.ultimo = {pts:0, reb:0, ast:0, min:0, jogos:0};
  S.ultimoOvr = ovr(S.A, S.pos);
  S.picoOvr = Math.max(S.picoOvr || 0, S.ultimoOvr);
  S.ultimosPremios = [];
  S.ultimaCampanha = `<b>${lesao ? "Temporada perdida" : "Suspenso"}</b> · ${esc(af.motivo)}`;

  af.anos--;
  const liberado = af.anos <= 0;
  if (liberado) S.afastado = null;

  S.mensagem = liberado
    ? (lesao ? "Você passou o ano na sala de fisioterapia. Está liberado — e agora precisa provar que ainda é você."
             : "Suspensão cumprida. A liga te devolveu a quadra; o vestiário ainda não decidiu se te devolve o respeito.")
    : (lesao ? "A recuperação não fechou no prazo. Mais uma temporada fora."
             : "A punição não acabou. Mais uma temporada assistindo.");

  S.resultado = null; S.efeitoDecisao = 0;
  S.aguardando = false; S.decisaoId = null;
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
  S.dinheiro += S.salario;
  S.idade++; S.ano++; S.anoFase++; S.contrato--;
  S.confianca = clamp(S.confianca + (st.pts > 14 ? 6 : -4), 5, 99);

  // Chegar à final é raro e é o único momento que vale jogar na mão. O
  // resto do playoff continua resolvido de uma vez — parar pra clicar em
  // toda série mataria o ritmo que o jogo depende.
  const chegaFinal = playoff && ri(0,100) < clamp((vit-46)*2.2 + (o-84)*1.4, 3, 32);
  if (chegaFinal){
    S.finais = {v:0, d:0, jogos:[], adversario: pick(timesDaLiga().filter(t=>t!==S.time))};
    salvar(); return telaFinais();
  }

  S.ultimaCampanha = `<b>${vit}-${82-vit}</b> · ${playoff ? "caiu nos playoffs" : "fora dos playoffs"}`;
  fecharAno(false, vit, o, st);
}

function fecharAno(campeao, vit, o, st){
  const premios = premiosDoAno(o, st, vit, campeao);
  if (S.anoFase === 1 && o >= 78 && ri(0,100) < 40){ premios.push({t:"Calouro do Ano", k:"ouro"}); S.trofeus.roy++; }
  S.ultimosPremios = premios;

  S.temporadas.push({ano:S.ano, idade:S.idade, time:S.time, ...st, vit,
                     premios:premios.map(p=>p.t), campeao});

  S.efeitoDecisao = 0;
  S.ultimaAposta = null;   // a aposta pertence ao ano que passou
  const estouro = evoluir();
  S.mensagem = estouro ? "Você estourou. De uma temporada pra outra, virou outro jogador." : null;

  const subiu = tentarSubirDivisao(o, st, premios);
  if (subiu){
    S.mensagem = `A ${subiu} veio buscar você. O ${S.time} pagou pra tirar você da divisão de baixo.`;
  }

  anoDoRival();

  // Marca histórica come a mensagem do ano: passar dos 20 mil pontos é
  // mais importante que qualquer outro recado que estivesse ali.
  const marcas = marcasNovas();
  if (marcas.length) S.mensagem = marcas[marcas.length - 1] + ".";

  // Contrato acabando manda pro mercado ANTES da decisão do ano: é a
  // decisão mais pesada que existe, não faz sentido dividir espaço.
  if (S.contrato <= 0){
    S.mercado = gerarOfertas();
    S.aguardando = false; S.decisaoId = null;
    salvar(); return telaMercado();
  }

  S.decisaoId = decisaoDoAno();
  S.aguardando = S.decisaoId !== null;
  salvar(); telaTemporada();
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
  if (f.v === 4 || f.d === 4) return encerrarFinais();
  telaFinais();
}

function encerrarFinais(){
  const f = S.finais, campeao = f.v === 4;
  S.ultimaCampanha = `<b>${S.ultimaVit}-${82-S.ultimaVit}</b> · ${campeao
    ? `CAMPEÃO — ${f.v}-${f.d} nas finais`
    : `vice — perdeu as finais por ${f.d}-${f.v}`}`;
  const st = S.ultimo, o = S.ultimoOvr;
  S.finais = null;
  fecharAno(campeao, S.ultimaVit, o, st);
}

function telaFinais(){
  const f = S.finais;
  const linhas = f.jogos.map(j => `
    <div class="jogo ${j.venceu?'v':'d'}">
      <span class="jogo-n">Jogo ${j.n}</span>
      <span class="jogo-r">${j.venceu ? "VITÓRIA" : "derrota"}</span>
      <span class="jogo-p">${j.meus} pts seus</span>
    </div>`).join("");

  app().innerHTML = topo() + barra() + `
    <h1>Finais.</h1>
    <p class="lead">${esc(S.time)} contra ${esc(f.adversario)}. Quem chegar a 4 primeiro leva.</p>
    <div class="placar">
      <div class="placar-topo">
        ${marca(S.time, 30)}
        <span class="ano" style="font-size:19px">${f.v} <span style="color:var(--text2)">×</span> ${f.d}</span>
        ${marca(f.adversario, 30)}
        <span class="placar-time">série<small>melhor de 7</small></span>
      </div>
      ${linhas ? `<div class="jogos">${linhas}</div>` : ""}
    </div>
    <button class="btn" onclick="simularJogoFinal()">Jogar o jogo ${f.jogos.length+1}</button>`;
}

// ── Mercado ────────────────────────────────────────────────────────────
function telaMercado(){
  const v = valorDeMercado();
  const ofertas = S.mercado || [];
  app().innerHTML = topo() + barra() + `
    <h1>Seu contrato acabou.</h1>
    <p class="lead">${ofertas.length === 1
      ? "Só apareceu uma proposta. O mercado não é gentil com quem não produz."
      : `${ofertas.length} propostas na mesa. Cada uma vem com o prazo dela.`}</p>
    ${ofertas.map((of,i)=>`
      <button class="op" onclick="escolherOferta(${i})" style="display:flex;gap:12px;align-items:flex-start">
        ${marca(of.time, 38)}
        <span style="flex:1;min-width:0">
          ${esc(of.time)}
          <span class="proposta">$${of.salario}M/ano · ${of.anos} ${of.anos === 1 ? "ano" : "anos"}</span>
          <small>${esc(of.nota)}<br>Papel: ${esc(of.papel)} · elenco ${of.forca >= 70 ? "forte" : of.forca >= 50 ? "mediano" : "fraco"}
          · total $${of.salario * of.anos}M</small>
        </span>
      </button>`).join("")}
    <p class="nota-txt">Seu valor de mercado hoje: <b style="color:var(--text)">${v}</b>. Ele sobe com produção e desce com a idade.</p>`;
}

function escolherOferta(i){
  const of = S.mercado[i];
  const mudou = assinar(of);
  S.resultado = mudou
    ? `Você assinou com o ${of.time} por ${of.anos} ${of.anos === 1 ? "ano" : "anos"}, $${of.salario}M por ano. Malas prontas.`
    : `Você renovou com o ${of.time}: ${of.anos} ${of.anos === 1 ? "ano" : "anos"}, $${of.salario}M por ano.`;
  S.mercado = null; S.ofertaEscolhida = null;
  S.decisaoId = decisaoDoAno();
  S.aguardando = S.decisaoId !== null;
  salvar(); telaTemporada();
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
  S.resultado = mudou
    ? `Você assinou por ${anos} anos com o ${of.time}. Malas prontas.`
    : `Você renovou com o ${of.time} por ${anos} anos.`;
  S.mercado = null; S.ofertaEscolhida = null;
  S.decisaoId = decisaoDoAno();
  S.aguardando = S.decisaoId !== null;
  salvar(); telaTemporada();
}

function telaTemporada(){
  const st = S.ultimo;
  if (!st) return telaDraft();
  const d = S.aguardando ? decisaoAtual() : null;
  const aposentar = S.idade >= 39 || (S.idade >= 33 && ovr(S.A,S.pos) < 68);

  const principal = barra() +
    placar(st, String(S.ano), S.time + (S.gm ? ` · ${S.gm}` : ""), S.ultimaCampanha, S.ultimosPremios || []) +
    (S.mensagem ? `<div class="bpcard"><p class="dec-txt" style="margin:0">${S.mensagem}</p></div>` : "") +
    (S.resultado ? `<div class="bpcard">
      <div class="bpcard-title">O que aconteceu${S.efeitoDecisao ? `<span style="color:${S.efeitoDecisao>0?"var(--green)":"var(--red)"}">${S.efeitoDecisao>0?"+":""}${S.efeitoDecisao} OVR</span>` : ""}</div>
      ${S.ultimaAposta ? `<div class="op" style="cursor:default;margin-bottom:11px" onclick="return false">
        <span class="op-titulo">${esc(S.ultimaAposta.l)}</span>
        ${chipsDaAposta(S.ultimaAposta, S.ultimaAposta.caiu)}
      </div>` : ""}
      <p class="dec-txt" style="margin:0">${S.resultado}</p></div>` : "") +
    (S.aguardando && d ? `
      <div class="bpcard">
        <div class="bpcard-title">Sua decisão</div>
        <p class="dec-txt">${d.t()}</p>
        ${d.ops.map((o,i)=>`<button class="op" onclick="decidir(${i})"><span class="op-titulo">${esc(o.l)}</span>${chipsDaAposta(o, null)}</button>`).join("")}
      </div>` : `
      ${aposentar ? `<button class="btn" onclick="encerrar()">Pendurar as chuteiras</button>`
                  : `<button class="btn" onclick="jogarAno()">Próxima temporada</button>`}
      ${!aposentar && S.idade >= 33 ? `<button class="btn btn2" style="margin-top:8px" onclick="encerrar()">Ou parar por aqui</button>` : ""}`);

  // A súmula vai pro lado: ela cresce a cada temporada e, embaixo do botão
  // de avançar, empurrava a decisão pra fora da tela no desktop.
  app().innerHTML = topo() + colunas(principal, sumula());
}

function decidir(i){
  const d = decisaoAtual();
  const op = d.ops[i];
  // O sorteio usa a MESMA chance que a etiqueta mostrou embaixo do botão.
  // É por isso que a etiqueta é gerada do dado em vez de escrita à mão:
  // texto e código escritos separados divergem na primeira alteração.
  const deuCerto = ri(1, 100) <= (op.chance ?? 100);
  const ef = deuCerto ? op.bom : (op.ruim || {txt:"No fim não deu em nada."});
  S.efeitoDecisao = aplicarEfeito(ef);
  S.resultado = ef.txt;

  // Só DADO, nunca o objeto da opção: ele tem função dentro e o
  // JSON.stringify do save as descarta calado, o que traria a carreira de
  // volta com uma aposta sem desfecho nenhum.
  const soDado = (e) => e ? {ovr: e.ovr || 0, time: e.time || null, fora: e.fora || null} : null;
  S.ultimaAposta = {
    l: op.l, chance: op.chance ?? 100,
    bom: soDado(op.bom), ruim: soDado(op.ruim),
    caiu: deuCerto ? "bom" : "ruim",
  };
  S.aguardando = false; S.decisaoId = null; S.mensagem = null;
  salvar(); telaTemporada();
}

/**
 * O que a carreira rendeu, em cima da lista ano a ano.
 *
 * A súmula conta a história linha a linha; isto é o placar dela. Sem esse
 * resumo, saber quantos MVPs você tem exigia varrer a coluna de prêmios
 * temporada por temporada.
 */
function resumoDeTrofeus(){
  const t = S.trofeus || {};
  const ordem = [
    ["titulo","Título","Títulos","titulo"],
    ["mvp","MVP","MVPs","ouro"],
    ["fmvp","MVP das Finais","MVPs das Finais","titulo"],
    ["euro","Euroliga","Euroligas","ouro"],
    ["dpoy","Defensor do Ano","Defensores do Ano","ouro"],
    ["cesta","Cestinha","Cestinhas","ouro"],
    ["ouro","Ouro olímpico","Ouros olímpicos","ouro"],
    ["roy","Calouro do Ano","Calouro do Ano","ouro"],
    ["allstar","All-Star","All-Stars","normal"],
  ];
  const chips = ordem
    .map(([k, um, varios, cor]) => {
      const n = Math.max(0, Number(t[k]) || 0);
      return n ? `<span class="pr ${cor}">${n}× ${esc(n === 1 ? um : varios)}</span>` : "";
    })
    .filter(Boolean);
  if (!chips.length) return "";
  return `<div class="trofeus-resumo">${chips.join("")}</div>`;
}

function sumula(){
  if (!S.temporadas.length) return "";
  const linhas = S.temporadas.slice().reverse().map(t => t.perdida ? `
    <tr>
      <td class="txt">${t.ano}</td>
      <td class="txt" style="font-weight:500;color:var(--text2)">${esc(String(t.time||"").slice(0,16))}</td>
      <td>—</td><td>—</td><td>—</td>
      <td class="txt" style="color:var(--text2);font-size:11px">${t.perdida==="lesao"?"Lesão":"Suspensão"} · ${esc(t.motivo||"")}</td>
    </tr>` : `
    <tr class="${t.campeao?'tit':''}">
      <td class="txt">${t.ano}</td>
      <td class="txt" style="font-weight:500;color:var(--text2)">${esc(String(t.time).slice(0,16))}</td>
      <td>${t.pts}</td><td>${t.reb}</td><td>${t.ast}</td>
      <td class="txt" style="color:var(--red);font-size:11px">${(t.premios||[]).length ? esc(t.premios.join(" · ")) : ""}</td>
    </tr>`).join("");
  return `<h2>Súmula da carreira</h2>` + resumoDeTrofeus() + `
    <div class="sumula"><table>
      <thead><tr><th>Ano</th><th>Time</th><th>PTS</th><th>REB</th><th>AST</th><th>Prêmios</th></tr></thead>
      <tbody>${linhas}</tbody></table></div>`;
}


// ── Ranking entre os GMs ───────────────────────────────────────────────
// O legado vira moeda, então o placar é o próprio pagamento exposto.
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
    <p class="nota-txt">O legado da carreira encerrada vira moeda, na mesma quantidade. Uma carreira ativa por vez — ao se aposentar, começa outra.</p>`;
}
function encerrar(){
  S.encerrada = true;
  // Fecha no SERVIDOR: é lá que o legado é recalculado e as moedas
  // creditadas. O cliente só avisa que acabou.
  fetch(location.pathname, {
    method:"POST", headers:{"Content-Type":"application/x-www-form-urlencoded"},
    body:"acao=encerrar&estado=" + encodeURIComponent(JSON.stringify(S)),
  }).then(r=>r.json()).then(d=>{
    if (d && d.ok){ S.moedasGanhas = d.moedas; }
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
  return n('mvp')*22 + n('titulo')*16 + n('fmvp')*10 + n('dpoy')*8 + n('euro')*7
       + n('cesta')*6 + n('allstar')*4 + n('ouro')*5 + n('roy')*3 + Math.round(anos*0.8);
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
  return Math.min(LEGADO_MAXIMO, Math.round(Math.pow(legadoBruto(), 0.78) * 1.8));
}

function telaFim(){
  const pts = pontuacaoLegado();
  const tot = totaisDeCarreira();
  let tier = TIERS[0][1];
  TIERS.forEach(([min,nome]) => { if (pts >= min) tier = nome; });
  const anos = S.temporadas.filter(x=>!x.formacao);
  const somas = anos.reduce((a,t)=>({p:a.p+t.pts, r:a.r+t.reb, s:a.s+t.ast}), {p:0,r:0,s:0});
  const med = (v) => anos.length ? (Math.round(v/anos.length*10)/10) : 0;
  const t = S.trofeus;
  const tro = [
    [t.titulo,"Títulos"],[t.mvp,"MVP"],[t.fmvp,"MVP das Finais"],[t.allstar,"All-Star"],
    [t.dpoy,"Defensor do Ano"],[t.cesta,"Cestinha"],[t.roy,"Calouro do Ano"],[t.ouro,"Ouro com a seleção"],
  ].filter(x=>x[0]>0);

  app().innerHTML = topo() + `
    <h1>${esc(S.nome)}</h1>
    <p class="lead">${marca(S.time,24)} ${anos.length} temporadas · ${esc(S.pos)} · aposentado aos ${S.idade}</p>

    ${cartaoDeCarreira(pts, tier, tot, anos)}

    ${S.moedasGanhas != null ? `<div class="bpcard centro" style="border-color:var(--amber)">
      <div class="bpcard-title">Moedas ganhas</div>
      <div class="grande" style="color:var(--amber)">+${S.moedasGanhas}</div>
      <p style="margin:0;font-size:11.5px;color:var(--text2)">creditadas na sua conta</p>
    </div>` : ""}

    <div class="acoes-fim">
      <button class="btn" onclick="copiar(this)">Copiar pra mandar no grupo</button>
      <button class="btn btn2" onclick="apagar();S=null;render()">Nova carreira</button>
    </div>

    <h2>Números da carreira</h2>
    <div class="bpcard">
      <div class="grade-num">
        ${caixa(med(somas.p), "pts por jogo")}
        ${caixa(med(somas.r), "reb por jogo")}
        ${caixa(med(somas.s), "ast por jogo")}
        ${caixa(tot.pts.toLocaleString("pt-BR"), "pontos")}
        ${caixa(tot.reb.toLocaleString("pt-BR"), "rebotes")}
        ${caixa(tot.ast.toLocaleString("pt-BR"), "assistências")}
        ${caixa(tot.jogos.toLocaleString("pt-BR"), "jogos")}
        ${caixa(anos.length, "temporadas")}
        ${caixa(S.picoOvr || S.ultimoOvr || 0, "pico de OVR")}
      </div>
    </div>

    <h2>Prêmios da carreira</h2>
    <div class="bpcard">
      <div class="grade-num">${gradeDeTrofeus()}</div>
      ${tro.length ? "" : `<p class="nota-txt">Sem troféus. Nem todo mundo levanta taça.</p>`}
    </div>

    ${trajetoria()}

    <h2>Onde você fica na história</h2>
    <div class="bpcard">
      ${escadaDeTiers(pts)}
      <p class="nota-txt">${degrauSeguinte(pts)}</p>
    </div>

    ${sumula()}${ranking("Como você ficou entre os GMs")}`;
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
function cartaoDeCarreira(pts, tier, tot, anos){
  const h = hashNome(S.time || "?");
  const mat = h % 360, comp = (mat + 150 + (h % 60)) % 360;
  const t = S.trofeus || {};
  const ovrPico = S.picoOvr || S.ultimoOvr || 0;

  // Só o que tem valor não-zero, e no máximo seis: cartão com "0× MVP" é
  // ruído, e mais de seis números quebram a linha no celular.
  const nums = [
    [t.titulo, "Títulos"], [t.mvp, "MVP"], [t.fmvp, "MVP Finais"],
    [t.allstar, "All-Star"], [t.dpoy, "DPOY"], [t.cesta, "Cestinha"],
    [t.ouro, "Ouro"], [t.roy, "Calouro"],
  ].filter(x => x[0] > 0).slice(0, 6);

  return `<div class="cartao" style="--c1:hsl(${mat} 55% 26%);--c2:hsl(${comp} 45% 12%)">
    <div class="ct-topo">
      <div>
        <div class="ct-ovr">${ovrPico || "—"}</div>
        <div class="ct-rot">pico de overall</div>
      </div>
      <div class="ct-dir">${esc(S.pos)}<br>${esc(String(S.time||"").slice(0,18))}<br>${anos.length} temporadas</div>
    </div>
    <div class="ct-tier">${esc(tier)}</div>
    <div class="ct-legado">${pts} pontos de legado</div>
    <div class="ct-nums">
      ${nums.map(([n,rot])=>`<div><b>${n}</b><span>${esc(rot)}</span></div>`).join("")}
      <div><b>${tot.pts.toLocaleString("pt-BR")}</b><span>pontos</span></div>
    </div>
    <div class="ct-pe">FBA Games · Caminho até a NBA · ${esc(S.nome)}</div>
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

/** Os oito degraus, com o seu aceso. Vira posição em vez de rótulo solto. */
function escadaDeTiers(pts){
  return `<div class="escada">${TIERS.map(([min, nome], i) => {
    const proximo = TIERS[i + 1];
    const meu = pts >= min && (!proximo || pts < proximo[0]);
    return `<div class="deg ${meu ? "voce" : (pts >= min ? "passou" : "")}">
      <span class="n">${i + 1}</span><span>${esc(nome)}</span>
      <span class="req">${min}</span>
    </div>`;
  }).join("")}</div>`;
}

/** Quanto faltou pro degrau de cima — o número que dá vontade de jogar de novo. */
function degrauSeguinte(pts){
  const acima = TIERS.find(([min]) => pts < min);
  if (!acima) return "Não existe degrau acima deste. Você chegou no topo da escada.";
  return `Faltaram <b>${acima[0] - pts}</b> pontos de legado pra chegar em “${esc(acima[1])}”.`;
}

function copiar(botao){
  const pts = pontuacaoLegado();
  let tier = TIERS[0][1];
  TIERS.forEach(([min,nome]) => { if (pts >= min) tier = nome; });
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
</body>
</html>