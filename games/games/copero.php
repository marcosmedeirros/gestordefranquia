<?php
/**
 * COPERO — simulador de carreira de futebol.
 *
 * Você nasce aos 16 sem clube e joga até pendurar as chuteiras. Cada ano traz
 * uma decisão com o efeito e a PROBABILIDADE na cara — é isso que separa o
 * jogo de um gerador de números: a escolha é uma aposta informada.
 *
 * O motor (progressão, valor, eventos) vive em games/core/copero_motor.php e
 * o catálogo de clubes em games/core/copero_clubes.php. Aqui é só a tela e o
 * laço da carreira.
 *
 * Os nomes de clube servem pra identificar dentro da simulação. O jogo não é
 * afiliado, patrocinado nem endossado por nenhum deles, e não hospeda escudo:
 * clube sem imagem aparece como monograma.
 */

session_start();
require_once __DIR__ . '/../../backend/db.php';
require_once __DIR__ . '/../core/copero_motor.php';

$idUsuario = (int)($_SESSION['user_id'] ?? 0);
$pdo = db();

/** Guarda a carreira encerrada, pro ranking e pro histórico. */
function coperoGarantirTabela(PDO $pdo): void
{
    static $ok = false;
    if ($ok) return;
    $ok = true;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS copero_carreiras (
            id INT AUTO_INCREMENT PRIMARY KEY,
            id_usuario INT NOT NULL,
            nome VARCHAR(40) NOT NULL,
            numero TINYINT NULL,
            posicao VARCHAR(4) NOT NULL,
            pais VARCHAR(4) NOT NULL,
            pico_ovr TINYINT NOT NULL,
            pico_valor BIGINT NOT NULL,
            jogos INT NOT NULL, gols INT NOT NULL, ast INT NOT NULL,
            clubes TINYINT NOT NULL,
            temporadas TINYINT NOT NULL,
            encerrada_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_copero_pico (pico_ovr)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
        error_log('[copero] tabela: ' . $e->getMessage());
    }
}

// ── Encerramento: grava a carreira e devolve as conquistas ────────────
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    if ($idUsuario <= 0) { echo json_encode(['ok' => false, 'erro' => 'sem sessão']); exit; }

    $c = json_decode((string)($_POST['carreira'] ?? ''), true);
    if (!is_array($c)) { echo json_encode(['ok' => false, 'erro' => 'carreira inválida']); exit; }

    // Os totais são RECALCULADOS a partir das temporadas: o resumo que o
    // cliente manda é o que ele desenhou, não o que aconteceu.
    $temporadas = is_array($c['temporadas'] ?? null) ? $c['temporadas'] : [];
    $tot = ['jogos' => 0, 'gols' => 0, 'ast' => 0];
    $clubes = [];
    $picoOvr = 0; $picoValor = 0;
    $porClube = []; $continentes = [];
    foreach ($temporadas as $t) {
        $tot['jogos'] += max(0, (int)($t['jogos'] ?? 0));
        $tot['gols']  += max(0, (int)($t['gols']  ?? 0));
        $tot['ast']   += max(0, (int)($t['ast']   ?? 0));
        $picoOvr   = max($picoOvr,   (int)($t['ovr']   ?? 0));
        $picoValor = max($picoValor, (int)($t['valor'] ?? 0));
        $nome = (string)($t['clube'] ?? '');
        if ($nome !== '') {
            $clubes[$nome] = 1;
            $porClube[$nome] = ($porClube[$nome] ?? 0) + max(0, (int)($t['jogos'] ?? 0));
            $liga = coperoLigaDoClube((string)($t['liga'] ?? ''));
            $continentes[$liga['continente']] = 1;
        }
    }
    $picoOvr = min(99, $picoOvr);

    coperoGarantirTabela($pdo);
    try {
        $pdo->prepare("INSERT INTO copero_carreiras
            (id_usuario, nome, numero, posicao, pais, pico_ovr, pico_valor, jogos, gols, ast, clubes, temporadas)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")->execute([
            $idUsuario,
            mb_substr(trim((string)($c['nome'] ?? '')), 0, 40) ?: 'Sem nome',
            max(1, min(99, (int)($c['numero'] ?? 10))),
            mb_substr((string)($c['posicao'] ?? 'MC'), 0, 4),
            mb_substr((string)($c['pais'] ?? ''), 0, 4),
            $picoOvr, $picoValor,
            $tot['jogos'], $tot['gols'], $tot['ast'],
            count($clubes), count($temporadas),
        ]);
    } catch (Throwable $e) {
        error_log('[copero] gravar: ' . $e->getMessage());
    }

    // Conquistas: testadas no servidor, com os totais recalculados.
    $ctx = [
        'jogos' => $tot['jogos'], 'gols' => $tot['gols'], 'ast' => $tot['ast'],
        'picoOvr' => $picoOvr, 'picoValor' => $picoValor,
        'clubes' => count($clubes), 'temporadas' => count($temporadas),
        'maiorNoClube' => $porClube ? max($porClube) : 0,
        'continentes' => count($continentes),
        'idadeFinal' => (int)($c['idadeFinal'] ?? 0),
        'comecouAbaixo' => !empty($c['comecouAbaixo']),
        'maiorForcaClube' => (int)($c['maiorForcaClube'] ?? 0),
    ];
    $ganhas = [];
    foreach (coperoConquistas() as $id => [$icone, $nome, $desc, $teste]) {
        if ($teste($ctx)) $ganhas[] = ['id' => $id, 'icone' => $icone, 'nome' => $nome, 'desc' => $desc];
    }

    echo json_encode(['ok' => true, 'totais' => $tot, 'conquistas' => $ganhas], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Dados que a tela precisa ──────────────────────────────────────────
$catalogo = [];
foreach (COPERO_CLUBES as [$nome, $liga, $forca, $escudo]) {
    $catalogo[] = ['nome' => $nome, 'liga' => $liga, 'forca' => $forca, 'escudo' => $escudo];
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Copero — Simulador de Carreira</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800;900&display=swap" rel="stylesheet">
<style>
:root{
  --bg:#0a0a0c; --panel:#131316; --panel2:#1a1a1f; --panel3:#212127;
  --borda:#26262d; --borda2:#33333c;
  --txt:#f4f4f5; --txt2:#a1a1aa; --txt3:#71717a;
  --verde:#16a34a; --verde-claro:#22c55e; --vermelho:#ef4444;
  --num:'Inter',system-ui,sans-serif;
}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--txt);font-family:'Inter',system-ui,-apple-system,sans-serif;
  font-size:14px;line-height:1.5;-webkit-font-smoothing:antialiased}
#app{max-width:1360px;margin:0 auto;padding:18px 16px 60px}
h1,h2,h3{margin:0;letter-spacing:-.4px}
button{font-family:inherit}

/* ── Topo ───────────────────────────────────────────── */
.topo{display:flex;align-items:center;gap:12px;margin-bottom:18px}
.topo .marca{display:flex;align-items:center;gap:9px;font-weight:900;font-size:18px;letter-spacing:-.6px}
.topo .marca i{color:var(--verde-claro)}
.topo .espaco{flex:1}
.btn-topo{background:var(--panel2);border:1px solid var(--borda);color:var(--txt2);border-radius:9px;
  padding:8px 13px;font-size:12.5px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:6px}
.btn-topo:hover{color:var(--txt);border-color:var(--borda2)}

/* ── Cartão genérico ────────────────────────────────── */
.caixa{background:var(--panel);border:1px solid var(--borda);border-radius:16px}

/* ── Início ─────────────────────────────────────────── */
.inicio{max-width:640px;margin:6vh auto;text-align:center}
.inicio h1{font-size:38px;font-weight:900;margin-bottom:8px}
.inicio p.lead{color:var(--txt2);font-size:15px;margin:0 0 26px}
.modos{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px}
.modo{background:var(--panel);border:1px solid var(--borda);border-radius:14px;padding:18px;cursor:pointer;
  text-align:left;color:var(--txt)}
.modo:hover{border-color:var(--verde)}
.modo.on{border-color:var(--verde-claro);background:rgba(34,197,94,.07)}
.modo b{display:block;font-size:16px;font-weight:800;margin-bottom:3px}
.modo small{color:var(--txt2);font-size:12px}

/* ── Identidade ─────────────────────────────────────── */
.ident{display:grid;grid-template-columns:1fr 1.15fr 1fr;gap:0}
.ident-col{padding:22px 20px}
.ident-col + .ident-col{border-left:1px solid var(--borda)}
.ident-tit{text-align:center;font-size:15px;font-weight:800;margin-bottom:16px}
.ident-cab{padding:20px 22px;border-bottom:1px solid var(--borda);font-size:21px;font-weight:900}
.ident-pe{padding:16px 22px;border-top:1px solid var(--borda);display:flex;justify-content:space-between;gap:12px}

.camisa{position:relative;width:190px;margin:0 auto 16px;aspect-ratio:1/1.06}
.camisa svg{width:100%;height:100%;display:block}
.camisa-nome{position:absolute;top:41%;line-height:1;left:14%;right:14%;text-align:center;font-size:12.5px;font-weight:800;
  overflow:hidden;text-overflow:ellipsis;white-space:nowrap;
  letter-spacing:1px;color:#fff;text-transform:uppercase}
.camisa-num{position:absolute;top:50%;left:0;right:0;text-align:center;font-size:44px;font-weight:900;
  color:#fff;line-height:1;letter-spacing:-2px}

.campo-rot{font-size:9.5px;font-weight:800;letter-spacing:1px;text-transform:uppercase;color:var(--txt3);
  margin-bottom:5px;text-align:center}
.campo-linha{display:flex;gap:10px;margin-bottom:11px}
.campo-linha > div{flex:1}
.inp{width:100%;background:var(--panel2);border:1px solid var(--borda);border-radius:10px;padding:11px 13px;
  color:var(--txt);font-size:14px;font-weight:700;text-align:center;font-family:inherit}
.inp:focus{outline:none;border-color:var(--verde)}
.perna{display:flex;background:var(--panel2);border:1px solid var(--borda);border-radius:10px;padding:3px}
.perna button{flex:1;background:none;border:none;color:var(--txt3);padding:8px;border-radius:8px;
  font-size:13px;font-weight:700;cursor:pointer}
.perna button.on{background:#fff;color:#111}

.busca{position:relative;margin-bottom:12px}
.busca i{position:absolute;left:13px;top:50%;transform:translateY(-50%);color:var(--txt3);font-size:14px}
.busca input{width:100%;background:var(--panel2);border:1px solid var(--borda);border-radius:11px;
  padding:11px 13px 11px 36px;color:var(--txt);font-size:13.5px;font-family:inherit}
.busca input:focus{outline:none;border-color:var(--verde)}
.paises{background:var(--panel2);border:1px solid var(--borda);border-radius:12px;padding:8px;
  max-height:330px;overflow-y:auto;display:grid;grid-template-columns:1fr 1fr;gap:2px}
.pais{display:flex;align-items:center;gap:9px;padding:8px 9px;border-radius:8px;cursor:pointer;
  background:none;border:1px solid transparent;color:var(--txt);font-size:13px;font-weight:600;text-align:left}
.pais:hover{background:var(--panel3)}
.pais.on{border-color:var(--verde-claro);background:rgba(34,197,94,.08)}
.pais svg{width:21px;height:14px;border-radius:2px;flex:none;box-shadow:0 0 0 1px rgba(255,255,255,.14)}

.campo{position:relative;background:linear-gradient(180deg,#14532d,#166534 55%,#14532d);
  border:1px solid var(--borda2);border-radius:12px;aspect-ratio:3/4;overflow:hidden}
.campo .risco{position:absolute;background:rgba(255,255,255,.16)}
.pos{position:absolute;transform:translate(-50%,-50%);background:rgba(0,0,0,.55);color:#fff;
  border:1px solid rgba(255,255,255,.22);border-radius:999px;padding:5px 11px;font-size:11.5px;
  font-weight:800;cursor:pointer;white-space:nowrap}
.pos:hover{background:rgba(0,0,0,.75)}
.pos.on{background:#fff;color:#111;border-color:#fff}

.btn{background:#fff;color:#111;border:none;border-radius:11px;padding:12px 22px;font-size:14px;
  font-weight:800;cursor:pointer}
.btn:disabled{opacity:.35;cursor:default}
.btn2{background:transparent;color:var(--txt2);border:1px solid var(--borda2)}
.btn2:hover:not(:disabled){color:var(--txt)}

/* ── Carreira ───────────────────────────────────────── */
.carreira{display:grid;grid-template-columns:minmax(0,420px) minmax(0,1fr);gap:16px;align-items:start}

.ficha{padding:18px}
.ficha-topo{display:flex;align-items:flex-start;gap:14px;margin-bottom:16px;flex-wrap:wrap}
.ovr-caixa{width:82px;height:82px;border-radius:14px;display:flex;flex-direction:column;align-items:center;
  justify-content:center;flex:none;color:#0a0a0c}
.ovr-caixa small{font-size:9px;font-weight:800;letter-spacing:1px;opacity:.7}
.ovr-caixa b{font-size:33px;font-weight:900;line-height:1;letter-spacing:-1.5px}
.ficha-info{flex:1 1 150px;min-width:0}
.ficha-tags{display:flex;align-items:center;gap:7px;margin-bottom:7px;flex-wrap:wrap;min-height:22px}
.tag{display:inline-flex;align-items:center;gap:5px;background:var(--panel3);border-radius:6px;
  padding:3px 8px;font-size:11px;font-weight:800}
.tag.pos{background:#7f1d3a;color:#fff}
.tag svg{width:17px;height:11px;border-radius:2px;flex:none;display:block}
.ficha-clube{display:flex;align-items:center;gap:9px;font-size:20px;font-weight:900;letter-spacing:-.5px}
.ficha-num{text-align:right;flex:none;margin-left:auto;font-size:11px;color:var(--txt3);font-weight:700;letter-spacing:.5px}
.ficha-num b{display:block;font-size:19px;color:var(--txt);letter-spacing:-.5px}

.ficha-stats{display:grid;grid-template-columns:repeat(3,1fr);border-top:1px solid var(--borda);
  border-bottom:1px solid var(--borda);padding:13px 0;margin-bottom:14px}
.ficha-stats div{text-align:center}
.ficha-stats span{display:block;font-size:9.5px;font-weight:800;letter-spacing:.8px;color:var(--txt3);
  text-transform:uppercase;margin-bottom:3px}
.ficha-stats b{font-size:20px;font-weight:900;letter-spacing:-.5px}

/* ── Evento ─────────────────────────────────────────── */
.evento h3{font-size:19px;font-weight:900;margin-bottom:4px}
.evento p{color:var(--txt2);font-size:12.5px;margin:0 0 14px;line-height:1.5}
.cartas{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:11px}
.carta{background:var(--panel2);border:1px solid var(--borda);border-radius:13px;padding:13px;cursor:pointer;
  text-align:center;color:var(--txt);transition:border-color .12s,opacity .12s}
.carta:hover{border-color:var(--borda2)}
.carta b{display:block;font-size:14.5px;font-weight:800;margin-bottom:9px}
.carta.escolhida{border-color:#fff}
.carta.apagada{opacity:.32}
.efeito{display:flex;align-items:center;justify-content:space-between;gap:8px;border-radius:8px;
  padding:6px 9px;font-size:11.5px;font-weight:700;margin-top:5px}
.efeito.bom{background:rgba(34,197,94,.12);color:#4ade80}
.efeito.ruim{background:rgba(239,68,68,.12);color:#f87171}
.efeito.neutro{background:var(--panel3);color:var(--txt2)}
.efeito .pct{opacity:.75;font-size:10.5px}
.efeito.sorteado{outline:1px solid currentColor}

.clube-op{display:flex;flex-direction:column;align-items:center;gap:7px}
.clube-op .escudo{width:52px;height:52px}
.clube-op small{color:var(--txt3);font-size:10.5px;font-weight:700}

/* ── Escudo / monograma ─────────────────────────────── */
.escudo{border-radius:8px;object-fit:contain;flex:none;background:var(--panel3)}
.mono{display:inline-flex;align-items:center;justify-content:center;border-radius:8px;flex:none;
  font-weight:900;color:#fff;letter-spacing:-.5px}

/* ── Linha do tempo ─────────────────────────────────── */
.linha{padding:14px 16px;max-height:78vh;overflow-y:auto}
.linha-cab,.ano{display:grid;grid-template-columns:44px minmax(0,1fr) 46px 56px 52px 52px;gap:8px;
  align-items:center}
.linha-cab{font-size:9.5px;font-weight:800;letter-spacing:.8px;text-transform:uppercase;color:var(--txt3);
  padding:0 8px 7px}
.ano{padding:6px 8px;border-radius:9px;font-size:13px}
.ano + .ano{margin-top:2px}
.ano.vazio{color:var(--txt3)}
.ano.atual{background:var(--panel3)}
.ano-idade{display:inline-flex;align-items:center;justify-content:center;width:28px;height:22px;
  border-radius:6px;font-size:11.5px;font-weight:800;color:#0a0a0c}
.ano-clube{display:flex;align-items:center;gap:8px;min-width:0}
.ano-clube span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-weight:700}
.ano-ovr{display:inline-flex;align-items:center;justify-content:center;border-radius:6px;padding:2px 0;
  font-size:11.5px;font-weight:800;color:#0a0a0c}
.ano-n{text-align:right;font-size:12px;color:var(--txt2);font-variant-numeric:tabular-nums}

/* ── Fim ────────────────────────────────────────────── */
.fim{text-align:center;padding:34px 20px}
.fim h2{font-size:26px;font-weight:900;margin-bottom:16px}
.resumo-topo{display:grid;grid-template-columns:1.6fr 1fr;gap:14px;margin-bottom:14px}
.clubes-grade{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:11px}
.clube-card{background:var(--panel);border:1px solid var(--borda);border-radius:13px;padding:14px;text-align:center}
.clube-card .escudo,.clube-card .mono{margin:0 auto 9px}
.clube-card b{display:block;font-size:13.5px;font-weight:800;margin-bottom:8px}
.clube-card .cc-nums{display:grid;grid-template-columns:repeat(3,1fr);gap:4px;font-size:11px}
.clube-card .cc-nums span{display:block;font-size:8.5px;color:var(--txt3);font-weight:800;letter-spacing:.5px}

.conq-grade{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:11px;margin-top:14px}
.conq{display:flex;gap:11px;align-items:flex-start;background:var(--panel);border:1px solid var(--borda);
  border-radius:13px;padding:13px}
.conq .ic{font-size:24px;line-height:1;flex:none}
.conq b{display:block;font-size:13.5px;font-weight:800;margin-bottom:2px}
.conq small{color:var(--txt2);font-size:11.5px;line-height:1.45}

.rodape{margin-top:26px;font-size:10.5px;color:var(--txt3);text-align:center;line-height:1.6}

@media (max-width:980px){
  /* Empilhado: a linha do tempo é referência, a ficha é onde se joga —
     então a ficha vem primeiro e a linha desce. */
  .carreira{grid-template-columns:1fr}
  .ident{grid-template-columns:1fr}
  .ident-col + .ident-col{border-left:none;border-top:1px solid var(--borda)}
  .linha{max-height:none}
  .linha-cab,.ano{grid-template-columns:38px minmax(0,1fr) 42px 44px 44px 44px;gap:6px}
  .modos{grid-template-columns:1fr}
  .resumo-topo{grid-template-columns:1fr}
  #app{padding:12px 11px 40px}
}
</style>
</head>
<body>
<div id="app"></div>

<script>
const CLUBES    = <?= json_encode($catalogo, JSON_UNESCAPED_UNICODE) ?>;
const LIGAS     = <?= json_encode(COPERO_LIGAS, JSON_UNESCAPED_UNICODE) ?>;
const PAISES    = <?= json_encode(COPERO_PAISES, JSON_UNESCAPED_UNICODE) ?>;
const POSICOES  = <?= json_encode(COPERO_POSICOES, JSON_UNESCAPED_UNICODE) ?>;
const MODOS     = <?= json_encode(COPERO_MODOS, JSON_UNESCAPED_UNICODE) ?>;
const FAIXAS    = <?= json_encode(COPERO_FAIXAS_OVR) ?>;
const IDADE_INI = <?= COPERO_IDADE_INICIAL ?>;
const IDADE_FIM = <?= COPERO_IDADE_FINAL ?>;

const app = () => document.getElementById('app');
const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
const ri  = (a,b) => Math.floor(Math.random()*(b-a+1))+a;

/* O estado inteiro da carreira. Salvo no localStorage a cada passo pra
   fechar a aba não custar uma carreira de 24 anos. */
let S = null;
const CHAVE = 'copero:carreira';
const salvar  = () => { try { localStorage.setItem(CHAVE, JSON.stringify(S)); } catch(e){} };
const carregar= () => { try { return JSON.parse(localStorage.getItem(CHAVE)||'null'); } catch(e){ return null; } };
const apagar  = () => { try { localStorage.removeItem(CHAVE); } catch(e){} };

/* ── Bandeiras ──────────────────────────────────────────
   Desenhadas aqui, e não em emoji: emoji de bandeira só aparece onde a
   fonte do sistema tem, e no Chrome do Windows vira o par de letras. */
const BAND = {
  BRA:`<rect width="30" height="20" fill="#009b3a"/><path d="M15 2.4 27.6 10 15 17.6 2.4 10Z" fill="#fedf00"/><circle cx="15" cy="10" r="4.3" fill="#002776"/>`,
  ARG:`<rect width="30" height="20" fill="#74acdf"/><rect y="6.67" width="30" height="6.67" fill="#fff"/><circle cx="15" cy="10" r="2.5" fill="#f6b40e"/>`,
  URU:`<rect width="30" height="20" fill="#fff"/><rect y="2.2" width="30" height="2.2" fill="#0038a8"/><rect y="6.6" width="30" height="2.2" fill="#0038a8"/><rect y="11" width="30" height="2.2" fill="#0038a8"/><rect y="15.4" width="30" height="2.2" fill="#0038a8"/><rect width="11" height="11" fill="#fff"/><circle cx="5.5" cy="5.5" r="2.6" fill="#f6b40e"/>`,
  CHI:`<rect width="30" height="20" fill="#fff"/><rect y="10" width="30" height="10" fill="#d52b1e"/><rect width="10" height="10" fill="#0039a6"/><path d="M5 2.2l.8 2.4h2.5l-2 1.5.8 2.4-2.1-1.5-2.1 1.5.8-2.4-2-1.5h2.5z" fill="#fff"/>`,
  COL:`<rect width="30" height="20" fill="#fcd116"/><rect y="10" width="30" height="5" fill="#003893"/><rect y="15" width="30" height="5" fill="#ce1126"/>`,
  ENG:`<rect width="30" height="20" fill="#fff"/><rect x="12.5" width="5" height="20" fill="#ce1124"/><rect y="7.5" width="30" height="5" fill="#ce1124"/>`,
  ESP:`<rect width="30" height="20" fill="#c60b1e"/><rect y="5" width="30" height="10" fill="#ffc400"/>`,
  ITA:`<rect width="30" height="20" fill="#fff"/><rect width="10" height="20" fill="#009246"/><rect x="20" width="10" height="20" fill="#ce2b37"/>`,
  GER:`<rect width="30" height="20" fill="#000"/><rect y="6.67" width="30" height="6.67" fill="#dd0000"/><rect y="13.33" width="30" height="6.67" fill="#ffce00"/>`,
  FRA:`<rect width="30" height="20" fill="#fff"/><rect width="10" height="20" fill="#002395"/><rect x="20" width="10" height="20" fill="#ed2939"/>`,
  POR:`<rect width="30" height="20" fill="#da291c"/><rect width="12" height="20" fill="#046a38"/><circle cx="12" cy="10" r="4" fill="#ffe900"/>`,
  NED:`<rect width="30" height="20" fill="#fff"/><rect width="30" height="6.67" fill="#ae1c28"/><rect y="13.33" width="30" height="6.67" fill="#21468b"/>`,
  BEL:`<rect width="30" height="20" fill="#fdda24"/><rect width="10" height="20" fill="#000"/><rect x="20" width="10" height="20" fill="#ef3340"/>`,
  CRO:`<rect width="30" height="20" fill="#fff"/><rect width="30" height="6.67" fill="#ff0000"/><rect y="13.33" width="30" height="6.67" fill="#171796"/>`,
  TUR:`<rect width="30" height="20" fill="#e30a17"/><circle cx="12" cy="10" r="5" fill="#fff"/><circle cx="13.6" cy="10" r="4" fill="#e30a17"/><path d="M18.2 10l3.6-1.2-2.2 3 0-3.7 2.2 3z" fill="#fff"/>`,
  RUS:`<rect width="30" height="20" fill="#fff"/><rect y="6.67" width="30" height="6.67" fill="#0039a6"/><rect y="13.33" width="30" height="6.67" fill="#d52b1e"/>`,
  SCO:`<rect width="30" height="20" fill="#0065bf"/><path d="M0 0l30 20M30 0L0 20" stroke="#fff" stroke-width="3.4"/>`,
  GRE:`<rect width="30" height="20" fill="#fff"/><rect width="30" height="2.22" fill="#0d5eaf"/><rect y="4.44" width="30" height="2.22" fill="#0d5eaf"/><rect y="8.89" width="30" height="2.22" fill="#0d5eaf"/><rect y="13.33" width="30" height="2.22" fill="#0d5eaf"/><rect y="17.78" width="30" height="2.22" fill="#0d5eaf"/><rect width="11.1" height="11.1" fill="#0d5eaf"/><path d="M0 5.55h11.1M5.55 0v11.1" stroke="#fff" stroke-width="2.1"/>`,
  USA:`<rect width="30" height="20" fill="#b22234"/><rect y="2.86" width="30" height="2.86" fill="#fff"/><rect y="8.57" width="30" height="2.86" fill="#fff"/><rect y="14.29" width="30" height="2.86" fill="#fff"/><rect width="12.6" height="11.4" fill="#3c3b6e"/>`,
  MEX:`<rect width="30" height="20" fill="#fff"/><rect width="10" height="20" fill="#006847"/><rect x="20" width="10" height="20" fill="#ce1126"/>`,
  KSA:`<rect width="30" height="20" fill="#006c35"/><rect x="5" y="13.5" width="20" height="1.6" fill="#fff"/><rect x="7" y="6" width="16" height="1.4" fill="#fff"/>`,
  JPN:`<rect width="30" height="20" fill="#fff"/><circle cx="15" cy="10" r="5.4" fill="#bc002d"/>`,
  KOR:`<rect width="30" height="20" fill="#fff"/><circle cx="15" cy="10" r="4.6" fill="#cd2e3a"/><path d="M10.4 10a4.6 4.6 0 0 1 9.2 0 2.3 2.3 0 0 0-4.6 0 2.3 2.3 0 0 1-4.6 0z" fill="#0047a0"/>`,
  EGY:`<rect width="30" height="20" fill="#fff"/><rect width="30" height="6.67" fill="#ce1126"/><rect y="13.33" width="30" height="6.67" fill="#000"/>`,
  MAR:`<rect width="30" height="20" fill="#c1272d"/><path d="M15 6.6l1.5 4.5h4.7l-3.8 2.8 1.4 4.5-3.8-2.8-3.8 2.8 1.4-4.5-3.8-2.8h4.7z" fill="none" stroke="#006233" stroke-width="1"/>`,
  RSA:`<rect width="30" height="20" fill="#002395"/><rect width="30" height="10" fill="#de3831"/><path d="M0 0l12 10L0 20z" fill="#007a4d"/><rect y="8.6" width="30" height="2.8" fill="#fff"/>`,
  AUS:`<rect width="30" height="20" fill="#012169"/><path d="M0 0l15 10M15 0L0 10" stroke="#fff" stroke-width="1.7"/><path d="M7.5 0v10M0 5h15" stroke="#fff" stroke-width="2.8"/><path d="M7.5 0v10M0 5h15" stroke="#e4002b" stroke-width="1.5"/><circle cx="7.5" cy="15.5" r="1.3" fill="#fff"/><circle cx="22" cy="6" r="1" fill="#fff"/><circle cx="25" cy="11" r="1" fill="#fff"/><circle cx="21" cy="13" r="1" fill="#fff"/>`,
  NGA:`<rect width="30" height="20" fill="#fff"/><rect width="10" height="20" fill="#008751"/><rect x="20" width="10" height="20" fill="#008751"/>`,
  SEN:`<rect width="30" height="20" fill="#fdef42"/><rect width="10" height="20" fill="#00853f"/><rect x="20" width="10" height="20" fill="#e31b23"/><path d="M15 7l1 3h3.2l-2.6 2 1 3-2.6-1.9-2.6 1.9 1-3-2.6-2H14z" fill="#00853f"/>`,
  CIV:`<rect width="30" height="20" fill="#fff"/><rect width="10" height="20" fill="#f77f00"/><rect x="20" width="10" height="20" fill="#009e60"/>`,
};
const bandeira = (iso, w) => BAND[iso]
  ? `<svg viewBox="0 0 30 20" width="${w||21}" role="img" aria-label="${esc(iso)}">${BAND[iso]}</svg>`
  : `<span style="font-size:10px;font-weight:800;color:var(--txt3)">${esc(iso)}</span>`;

/* ── Escudos ────────────────────────────────────────────
   Clube sem URL vira monograma. É o caso de todos hoje: o catálogo nasceu
   sem as URLs, e a tela não pode ficar com buraco por causa disso. */
const CORES_MONO = ['#1e3a8a','#7f1d1d','#14532d','#78350f','#4c1d95','#0f766e','#831843','#3f3f46'];
function monograma(nome, tam){
  let h = 0;
  for (const c of String(nome)) h = (h * 31 + c.charCodeAt(0)) >>> 0;
  const cor = CORES_MONO[h % CORES_MONO.length];
  const ini = String(nome).split(/\s+/).slice(0,2).map(p=>p[0]||'').join('').toUpperCase();
  return `<span class="mono" style="width:${tam}px;height:${tam}px;background:${cor};font-size:${Math.round(tam*.4)}px">${esc(ini)}</span>`;
}
function escudo(clube, tam){
  const t = tam || 22;
  if (!clube) return monograma('?', t);
  if (!clube.escudo) return monograma(clube.nome, t);
  return `<img class="escudo" src="${esc(clube.escudo)}" alt="${esc(clube.nome)}" style="width:${t}px;height:${t}px"
     onerror="this.outerHTML=this.dataset.reserva" data-reserva="${esc(monograma(clube.nome,t))}">`;
}

const corDoOvr = o => (FAIXAS.find(([min]) => o >= min) || FAIXAS[FAIXAS.length-1])[2];
const acharClube = nome => CLUBES.find(c => c.nome === nome);
const dadosLiga  = id => LIGAS[id] ? {pais:LIGAS[id][0], cont:LIGAS[id][1], nome:LIGAS[id][2], nivel:LIGAS[id][3]} : null;

function moeda(v){
  if (v >= 1000000) return '€' + (v/1000000).toFixed(v >= 10000000 ? 0 : 1).replace('.', ',') + 'M';
  return '€' + Math.round(v/1000) + 'K';
}

/* ── Tela 1: modo ───────────────────────────────────── */
function telaInicio(){
  app().innerHTML = `
    <div class="inicio">
      <h1>Copero</h1>
      <p class="lead">Você tem 16 anos e nenhum clube. O que vem depois é com você.</p>
      <div class="modos">
        ${Object.entries(MODOS).map(([id,m]) => `
          <button class="modo${id==='classico'?' on':''}" data-modo="${id}" onclick="escolherModo('${id}')">
            <b>${esc(m.nome)}</b><small>${esc(m.sub)}</small>
          </button>`).join('')}
      </div>
      <button class="btn" style="width:100%" onclick="telaIdentidade()">Começar carreira</button>
      ${carregar() ? `<button class="btn btn2" style="width:100%;margin-top:9px" onclick="continuar()">Continuar a carreira salva</button>` : ''}
    </div>`;
}
let MODO = 'classico';
function escolherModo(id){
  MODO = id;
  document.querySelectorAll('.modo').forEach(m => m.classList.toggle('on', m.dataset.modo === id));
}
function continuar(){ S = carregar(); if (S) render(); }

/* ── Tela 2: identidade ─────────────────────────────── */
let rascunho = {nome:'', numero:10, perna:'Direita', pais:'', posicao:'', busca:''};

function telaIdentidade(){
  const lista = Object.entries(PAISES)
    .filter(([iso,nome]) => !rascunho.busca || nome.toLowerCase().includes(rascunho.busca.toLowerCase()))
    .sort((a,b) => a[1].localeCompare(b[1],'pt-BR'));

  // Onde cada posição fica no campo, em % da caixa.
  const NO_CAMPO = {
    GOL:[50,92], ZAG:[50,79], LE:[16,72], LD:[84,72], VOL:[50,65],
    MC:[50,52],  ME:[17,50],  MD:[83,50], MEI:[50,38], PE:[16,28], PD:[84,28], CA:[50,20],
  };

  app().innerHTML = `
    <div class="topo">
      <div class="marca"><i class="bi bi-trophy-fill"></i> Copero</div>
    </div>
    <div class="caixa">
      <div class="ident-cab">Defina sua identidade</div>
      <div class="ident">
        <div class="ident-col">
          <div class="ident-tit">Identidade</div>
          <div class="camisa">
            <svg viewBox="0 0 100 106"><path d="M50 6c-7 0-12 3-18 4L14 18l-8 16 14 8 4-6v62h72V36l4 6 14-8-8-16-18-8c-6-1-11-4-18-4z"
              fill="#166534" stroke="#22c55e" stroke-width="1.5" stroke-linejoin="round"/></svg>
            <div class="camisa-nome">${esc(rascunho.nome || '—')}</div>
            <div class="camisa-num">${esc(rascunho.numero || '10')}</div>
          </div>
          <div class="campo-linha">
            <div><div class="campo-rot">Sobrenome</div>
              <input class="inp" id="iNome" maxlength="12" value="${esc(rascunho.nome)}" placeholder="MARC"></div>
            <div style="max-width:92px"><div class="campo-rot">Número</div>
              <input class="inp" id="iNum" type="number" min="1" max="99" value="${esc(rascunho.numero)}"></div>
          </div>
          <div class="campo-rot">Perna dominante</div>
          <div class="perna">
            ${['Esquerda','Direita'].map(p => `<button class="${rascunho.perna===p?'on':''}" onclick="rascunho.perna='${p}';telaIdentidade()">${p}</button>`).join('')}
          </div>
        </div>

        <div class="ident-col">
          <div class="ident-tit">Nacionalidade</div>
          <div class="busca"><i class="bi bi-search"></i>
            <input id="iBusca" placeholder="Buscar país" value="${esc(rascunho.busca)}"></div>
          <div class="paises">
            ${lista.length ? lista.map(([iso,nome]) => `
              <button class="pais${rascunho.pais===iso?' on':''}" onclick="rascunho.pais='${iso}';telaIdentidade()">
                ${bandeira(iso)}<span>${esc(nome)}</span></button>`).join('')
              : `<div style="grid-column:1/-1;padding:18px;text-align:center;color:var(--txt3);font-size:12.5px">Nenhum país com esse nome.</div>`}
          </div>
        </div>

        <div class="ident-col">
          <div class="ident-tit">Posição</div>
          <div class="campo">
            <i class="risco" style="left:8%;right:8%;top:50%;height:1px"></i>
            <i class="risco" style="left:26%;right:26%;top:0;height:14%;border:1px solid rgba(255,255,255,.16);background:none"></i>
            <i class="risco" style="left:26%;right:26%;bottom:0;height:14%;border:1px solid rgba(255,255,255,.16);background:none"></i>
            ${Object.entries(NO_CAMPO).map(([sig,[x,y]]) => `
              <button class="pos${rascunho.posicao===sig?' on':''}" style="left:${x}%;top:${y}%"
                title="${esc(POSICOES[sig][0])}" onclick="rascunho.posicao='${sig}';telaIdentidade()">${sig}</button>`).join('')}
          </div>
        </div>
      </div>
      <div class="ident-pe">
        <button class="btn btn2" onclick="telaInicio()">Voltar</button>
        <button class="btn" id="btnConfirmar" ${rascunho.nome && rascunho.pais && rascunho.posicao ? '' : 'disabled'}
          onclick="comecarCarreira()">Confirmar identidade</button>
      </div>
    </div>
    <p class="rodape">Os nomes de clube servem para identificar dentro da simulação.
    Este jogo não é afiliado, patrocinado nem endossado por nenhum deles.</p>`;

  const iNome = document.getElementById('iNome');
  iNome.addEventListener('input', e => {
    rascunho.nome = e.target.value.toUpperCase();
    document.querySelector('.camisa-nome').textContent = rascunho.nome || '—';
    document.getElementById('btnConfirmar').disabled = !(rascunho.nome && rascunho.pais && rascunho.posicao);
  });
  document.getElementById('iNum').addEventListener('input', e => {
    rascunho.numero = Math.max(1, Math.min(99, Number(e.target.value) || 10));
    document.querySelector('.camisa-num').textContent = rascunho.numero;
  });
  const iB = document.getElementById('iBusca');
  iB.addEventListener('input', e => {
    rascunho.busca = e.target.value;
    telaIdentidade();
    const novo = document.getElementById('iBusca');
    novo.focus(); novo.setSelectionRange(novo.value.length, novo.value.length);
  });
}

/* ── Começo ─────────────────────────────────────────── */
function comecarCarreira(){
  S = {
    nome: rascunho.nome, numero: rascunho.numero, perna: rascunho.perna,
    pais: rascunho.pais, posicao: rascunho.posicao, modo: MODO,
    idade: IDADE_INI, ovr: 50, clube: null, temporadas: [],
    picoOvr: 50, picoValor: 0, maiorForcaClube: 0, comecouAbaixo: false,
    fase: 'oferta_base', evento: null, fim: false, resultado: null,
  };
  salvar(); render();
}

/* ── O laço da carreira ─────────────────────────────── */

/**
 * Onde um jogador deste país tende a COMEÇAR.
 *
 * Brasileiro sai do Brasil, não do Nice. Marroquino sai do Marrocos ou da
 * França, que é pra onde a imigração leva — e é isso que faz a escolha de
 * nacionalidade valer alguma coisa além da bandeirinha.
 *
 * Primeiro o país da pessoa; depois os destinos naturais dela. Quem não está
 * na lista cai no próprio país e, se ele não tiver liga, no mundo todo.
 */
const DESTINOS = {
  BRA:['BRA','POR'],            ARG:['ARG','ESP','ITA'],   URU:['URU','ARG','ESP'],
  CHI:['CHI','ARG'],            COL:['COL','ARG','MEX'],   ENG:['ENG','SCO'],
  ESP:['ESP','POR'],            ITA:['ITA','ESP'],         GER:['GER','AUT'],
  FRA:['FRA','BEL'],            POR:['POR','ESP'],         NED:['NED','BEL','GER'],
  BEL:['BEL','NED','FRA'],      CRO:['CRO','ITA','GER'],   TUR:['TUR','GER'],
  RUS:['RUS'],                  SCO:['SCO','ENG'],         GRE:['GRE','ITA'],
  USA:['USA','MEX'],            MEX:['MEX','USA'],         KSA:['KSA'],
  JPN:['JPN','KOR'],            KOR:['KOR','JPN'],
  // África: quem sai, sai principalmente pra França, Bélgica e Portugal.
  EGY:['EGY','KSA'],            MAR:['MAR','FRA','ESP'],   RSA:['RSA','ENG'],
  NGA:['NGA','ENG','BEL'],      SEN:['SEN','FRA','BEL'],   CIV:['CIV','FRA','BEL'],
  AUS:['AUS','ENG'],
};

/** Os países onde um jogador desta nacionalidade pode começar. */
function paisesDeInicio(pais){
  const d = DESTINOS[pais] || [pais];
  // Só os que têm liga no catálogo — Senegal e Costa do Marfim não têm, e a
  // lista deles precisa cair na França sem virar oferta vazia.
  const comLiga = new Set(Object.values(LIGAS).map(l => l[0]));
  const bons = d.filter(p => comLiga.has(p));
  return bons.length ? bons : null;
}

/**
 * Ofertas de clube compatíveis com o OVR atual.
 *
 * `soDeCasa` restringe aos países de origem da nacionalidade — é o que usa a
 * oferta de base, porque ninguém de 16 anos sai do Brasil direto pro Nice.
 * Depois disso o mundo abre: quem é bom vai pra onde quiser, e é justamente
 * isso que faz a carreira ter trajetória.
 */
function ofertas(quantos, exceto, soDeCasa){
  const teto = S.ovr + 8, piso = Math.max(40, S.ovr - 25);
  const fora = new Set(exceto || []);
  let elegiveis = CLUBES.filter(c => c.forca <= teto && c.forca >= piso && !fora.has(c.nome));

  // Sorteia de uma lista, tirando o que já saiu.
  const sortear = (lista, n, jaTem) => {
    const copia = lista.filter(c => !jaTem.has(c.nome));
    const out = [];
    while (out.length < n && copia.length) {
      out.push(copia.splice(Math.floor(Math.random()*copia.length), 1)[0]);
    }
    return out;
  };

  const sorteados = [];
  const jaTem = new Set();

  if (soDeCasa) {
    const paises = paisesDeInicio(S.pais);
    if (paises) {
      const daCasa = elegiveis.filter(c => {
        const l = dadosLiga(c.liga);
        return l && paises.includes(l.pais);
      });
      // Pega o que houver da origem e COMPLETA com o resto do mundo. Antes
      // eu descartava a origem inteira quando ela não tinha 3 clubes na
      // faixa — e aí o marroquino começava na Inglaterra e o senegalês no
      // Brasil, que é o oposto do que a nacionalidade deveria fazer.
      sortear(daCasa, quantos, jaTem).forEach(c => { sorteados.push(c); jaTem.add(c.nome); });
    }
  }

  sortear(elegiveis, quantos - sorteados.length, jaTem).forEach(c => sorteados.push(c));
  return sorteados;
}

/** Um evento que caiba no momento da carreira. */
const EVENTOS = <?= json_encode(array_map(function ($e) {
    unset($e['quando']);
    return $e;
}, coperoEventos()), JSON_UNESCAPED_UNICODE) ?>;

function eventoDaVez(){
  // As condições de cada evento vivem aqui porque o servidor não consegue
  // serializar closure — a lista lá é a fonte do CONTEÚDO, esta é do momento.
  const cabe = {
    concentracao: () => S.idade >= 18,
    treino_dobro: () => S.idade >= 17 && S.idade <= 30,
    dieta:        () => S.idade >= 19,
    polemica:     () => S.idade >= 22 && S.ovr >= 70,
    fiscal:       () => S.idade >= 25 && valorAtual() >= 8000000,
    capitao:      () => S.idade >= 24 && S.ovr >= 75,
  };
  const possiveis = EVENTOS.filter(e => (cabe[e.id] || (()=>true))());
  return possiveis.length ? possiveis[Math.floor(Math.random()*possiveis.length)] : null;
}

function valorAtual(){
  const f = S.clube ? S.clube.forca : 55;
  const o = S.ovr;
  if (o < 45) return 50000;
  let base = 100000 * Math.pow(1.19, o - 50);
  if      (S.idade <= 21) base *= 0.70 + ((S.idade - 16) * 0.06);
  else if (S.idade <= 26) base *= 1.00;
  else if (S.idade <= 29) base *= 0.85;
  else if (S.idade <= 32) base *= 0.55;
  else if (S.idade <= 35) base *= 0.25;
  else                    base *= Math.max(0.04, 0.12 - ((S.idade - 35) * 0.02));
  base *= 0.88 + (f / 400);
  if (base >= 10000000) return Math.round(base/1000000)*1000000;
  if (base >= 1000000)  return Math.round(base/100000)*100000;
  return Math.round(base/10000)*10000;
}

/** Assina com um clube e joga os anos do passo do modo. */
/** Assina a oferta N da lista atual. Por índice, e não por nome: nome vai
  * pro atributo entre aspas e clube com aspas no nome quebrava o onclick. */
function assinarOpcao(i){
  const c = (S.opcoes || [])[i];
  if (c) assinar(c);
}

function assinar(clube){
  S.clube = clube;
  if (!S.temporadas.length) {
    const l = dadosLiga(clube.liga);
    S.comecouAbaixo = !!(l && l.nivel >= 2);
  }
  S.maiorForcaClube = Math.max(S.maiorForcaClube, clube.forca);
  jogarAnos();
}

function jogarAnos(){
  const passo = MODOS[S.modo] ? MODOS[S.modo].passo : 1;
  for (let i = 0; i < passo && S.idade <= IDADE_FIM - 1; i++) {
    const t = temporada();
    S.temporadas.push(t);
    S.picoOvr   = Math.max(S.picoOvr, t.ovr);
    S.picoValor = Math.max(S.picoValor, t.valor);
    S.ovr = evoluir();
    S.idade++;
  }
  proximaFase();
}

/** Os números de um ano, com a mesma régua do motor no servidor. */
function temporada(){
  const p = POSICOES[S.posicao] || POSICOES.MC;
  const pesoGol = p[2], pesoAst = p[3];
  const f = S.clube.forca;

  const encaixe = Math.max(0.35, Math.min(1.15, 1 + ((S.ovr - f) / 40)));
  let jogos = Math.round(ri(26,42) * encaixe);
  jogos = Math.max(4, Math.min(52, jogos + (S.jogosBonus || 0)));
  S.jogosBonus = 0;

  const q = Math.max(0.2, (S.ovr - 45) / 45);
  return {
    idade: S.idade, clube: S.clube.nome, liga: S.clube.liga, ovr: S.ovr,
    jogos,
    gols: Math.max(0, Math.round(jogos * pesoGol * q * (ri(75,130)/100))),
    ast:  Math.max(0, Math.round(jogos * pesoAst * q * (ri(70,135)/100))),
    valor: valorAtual(),
  };
}

function evoluir(){
  const amb = (S.clube.forca - S.ovr) / 10;
  let d;
  if      (S.idade <= 21) d = ri(2,6) + amb;
  else if (S.idade <= 25) d = ri(1,4) + amb * 0.8;
  else if (S.idade <= 29) d = ri(-1,2) + amb * 0.5;
  else if (S.idade <= 33) d = ri(-3,1);
  else                    d = ri(-5,-1);
  if (S.ovr >= 90) d = Math.min(d, ri(0,1));
  if (S.ovr >= 96) d = Math.min(d, 0);
  return Math.max(35, Math.min(99, S.ovr + Math.round(d)));
}

/** Decide o que vem depois de jogar: evento, mercado ou fim. */
function proximaFase(){
  if (S.idade >= IDADE_FIM) { S.fase = 'fim'; S.fim = true; salvar(); render(); return; }

  // Depois dos 33 o clube pode não renovar — é o que traz a aposentadoria
  // como decisão, e não como parede de idade.
  if (S.idade >= 33 && Math.random() < 0.45) {
    S.fase = 'fim_ciclo';
    S.opcoes = ofertas(2, [S.clube.nome]);
  } else if (Math.random() < 0.55) {
    S.fase = 'evento';
    S.evento = eventoDaVez();
    S.resultado = null;
    if (!S.evento) { S.fase = 'mercado'; S.opcoes = ofertas(2, [S.clube.nome]); }
  } else {
    S.fase = 'mercado';
    S.opcoes = ofertas(2, [S.clube.nome]);
  }
  salvar(); render();
}

/** Aplica a carta escolhida, mostra o que saiu e segue. */
function escolherCarta(i){
  const carta = S.evento.cartas[i];
  const r = Math.random() * 100;
  let acc = 0, ef = carta.efeitos[carta.efeitos.length - 1];
  for (const e of carta.efeitos) { acc += e.chance; if (r <= acc) { ef = e; break; } }

  S.resultado = {carta: i, efeito: ef};
  S.ovr = Math.max(35, Math.min(99, S.ovr + (ef.ovr || 0)));
  S.jogosBonus = ef.jogos || 0;
  salvar(); render();

  // Um respiro pra pessoa VER o que saiu antes da tela virar — sem isso o
  // resultado da aposta pisca e some.
  setTimeout(() => { S.evento = null; S.resultado = null; jogarAnos(); }, 1400);
}

function aposentar(){ S.fase = 'fim'; S.fim = true; salvar(); render(); }

/* ── Render ─────────────────────────────────────────── */
function render(){
  if (!S) return telaInicio();
  if (S.fase === 'fim') return telaFim();

  const cor = corDoOvr(S.ovr);
  const l = S.clube ? dadosLiga(S.clube.liga) : null;

  app().innerHTML = `
    <div class="topo">
      <div class="marca"><i class="bi bi-trophy-fill"></i> Copero</div>
      <div class="espaco"></div>
      <button class="btn-topo" onclick="if(confirm('Abandonar esta carreira?')){apagar();S=null;telaInicio();}">
        <i class="bi bi-x-lg"></i> Abandonar</button>
    </div>
    <div class="carreira">
      <div>
        <div class="caixa ficha">
          <div class="ficha-topo">
            <div class="ovr-caixa" style="background:${cor}">
              <small>OVR</small><b>${S.ovr}</b></div>
            <div class="ficha-info">
              <div class="ficha-tags">
                <span class="tag">${bandeira(S.pais,17)} ${esc(S.pais)}</span>
                <span class="tag pos">#${esc(S.numero)} ${esc(S.posicao)}</span>
              </div>
              <div class="ficha-clube">
                ${S.clube ? escudo(S.clube, 26) + `<span>${esc(S.clube.nome)}</span>`
                          : `<span style="color:var(--txt3)">Sem clube</span>`}
              </div>
              ${l ? `<div style="font-size:11px;color:var(--txt3);margin-top:3px">${esc(l.nome)}</div>` : ''}
            </div>
            <div class="ficha-num">IDADE<b>${S.idade}</b>VALOR<b>${moeda(valorAtual())}</b></div>
          </div>
          <div class="ficha-stats">
            ${[['Jogos','jogos'],['Gols','gols'],['Ast','ast']].map(([r,k]) =>
              `<div><span>${r}</span><b>${S.temporadas.reduce((a,t)=>a+t[k],0)}</b></div>`).join('')}
          </div>
          ${blocoDecisao()}
        </div>
      </div>
      <div class="caixa linha">${linhaDoTempo()}</div>
    </div>
    <p class="rodape">Os nomes de clube servem para identificar dentro da simulação.
    Este jogo não é afiliado, patrocinado nem endossado por nenhum deles.</p>`;
}

function blocoDecisao(){
  if (S.fase === 'oferta_base') {
    if (!S.opcoes) S.opcoes = ofertas(3, [], true);
    return `<div class="evento"><h3>Oferta de base</h3>
      <p>Três clubes querem te incluir no projeto de base. Escolha onde sua carreira começa.</p>
      ${cartasDeClube(S.opcoes)}</div>`;
  }
  if (S.fase === 'mercado') {
    return `<div class="evento"><h3>Janela de transferências</h3>
      <p>Chegaram ofertas depois do seu último trecho de carreira. Você pode aceitar uma ou ficar no clube.</p>
      ${cartasDeClube(S.opcoes, true)}</div>`;
  }
  if (S.fase === 'fim_ciclo') {
    return `<div class="evento"><h3>Fim de ciclo</h3>
      <p>Seu clube decidiu não renovar. Escolha o próximo passo da sua carreira.</p>
      ${cartasDeClube(S.opcoes, false, true)}</div>`;
  }
  if (S.fase === 'evento' && S.evento) {
    const res = S.resultado;
    return `<div class="evento"><h3>${esc(S.evento.titulo)}</h3><p>${esc(S.evento.texto)}</p>
      <div class="cartas">
        ${S.evento.cartas.map((c,i) => {
          const escolhida = res && res.carta === i;
          const cls = res ? (escolhida ? ' escolhida' : ' apagada') : '';
          return `<button class="carta${cls}" ${res?'disabled':''} onclick="escolherCarta(${i})">
            <b>${esc(c.rotulo)}</b>
            ${c.efeitos.map(e => {
              const tom = (e.ovr||0) > 0 ? 'bom' : ((e.ovr||0) < 0 || e.jogos ? 'ruim' : 'neutro');
              const sorteado = escolhida && res.efeito.texto === e.texto ? ' sorteado' : '';
              return `<span class="efeito ${tom}${sorteado}">
                <span>${esc(e.texto)}</span>
                ${e.chance < 100 ? `<span class="pct">${e.chance}%</span>` : ''}</span>`;
            }).join('')}
          </button>`;
        }).join('')}
      </div></div>`;
  }
  return '';
}

function cartasDeClube(lista, comFicar, comAposentar){
  const cartas = (lista || []).map((c,i) => {
    const l = dadosLiga(c.liga);
    return `<button class="carta" onclick="assinarOpcao(${i})">
      <div class="clube-op">
        <small>Assinar com</small><b style="margin:0">${esc(c.nome)}</b>
        ${escudo(c, 52)}
        <small>${l ? esc(l.nome) : ''}</small>
      </div></button>`;
  });
  if (comFicar && S.clube) {
    cartas.push(`<button class="carta" onclick="assinar(S.clube)">
      <div class="clube-op"><small>Ficar no</small><b style="margin:0">${esc(S.clube.nome)}</b>
      ${escudo(S.clube, 52)}<small>${esc((dadosLiga(S.clube.liga)||{}).nome || '')}</small></div></button>`);
  }
  if (comAposentar) {
    cartas.push(`<button class="carta" onclick="aposentar()">
      <div class="clube-op"><b style="margin:0">Aposentar-se</b>
      <span style="font-size:34px;line-height:1">🥾</span>
      <small>Encerrar sua carreira profissional</small></div></button>`);
  }
  return `<div class="cartas">${cartas.join('')}</div>`;
}

function linhaDoTempo(){
  const porIdade = {};
  S.temporadas.forEach(t => { porIdade[t.idade] = t; });

  let html = `<div class="linha-cab"><span>Idade</span><span>Clube</span><span>OVR</span>
    <span style="text-align:right">Jogos</span><span style="text-align:right">Gols</span>
    <span style="text-align:right">Ast</span></div>`;

  for (let i = IDADE_INI; i < IDADE_FIM; i++) {
    const t = porIdade[i];
    const atual = !t && i === S.idade;
    if (t) {
      const c = acharClube(t.clube);
      const cor = corDoOvr(t.ovr);
      html += `<div class="ano">
        <span class="ano-idade" style="background:${cor}">${i}</span>
        <span class="ano-clube">${escudo(c, 20)}<span>${esc(t.clube)}</span></span>
        <span class="ano-ovr" style="background:${cor}">${t.ovr}</span>
        <span class="ano-n">${t.jogos}</span><span class="ano-n">${t.gols}</span><span class="ano-n">${t.ast}</span>
      </div>`;
    } else if (atual) {
      html += `<div class="ano atual">
        <span class="ano-idade" style="background:${corDoOvr(S.ovr)}">${i}</span>
        <span class="ano-clube" style="color:var(--txt3)"><i class="bi bi-question-circle"></i>
          <span>${S.fase==='fim_ciclo'?'Decisão de carreira…':'Escolhendo clube…'}</span></span>
        <span class="ano-ovr" style="background:${corDoOvr(S.ovr)}">${S.ovr}</span>
        <span></span><span></span><span></span></div>`;
    } else {
      html += `<div class="ano vazio"><span style="text-align:center">${i}</span>
        <span></span><span></span><span></span><span></span><span></span></div>`;
    }
  }
  return html;
}

/* ── Fim ────────────────────────────────────────────── */
async function telaFim(){
  const tot = S.temporadas.reduce((a,t)=>({jogos:a.jogos+t.jogos, gols:a.gols+t.gols, ast:a.ast+t.ast}),
                                  {jogos:0,gols:0,ast:0});
  const porClube = {};
  S.temporadas.forEach(t => {
    if (!porClube[t.clube]) porClube[t.clube] = {jogos:0,gols:0,ast:0};
    porClube[t.clube].jogos += t.jogos; porClube[t.clube].gols += t.gols; porClube[t.clube].ast += t.ast;
  });

  const cor = corDoOvr(S.picoOvr);
  app().innerHTML = `
    <div class="topo"><div class="marca"><i class="bi bi-trophy-fill"></i> Copero</div></div>
    <div class="caixa fim">
      <h2>Sua carreira chegou ao fim</h2>
      <button class="btn" onclick="apagar();S=null;telaInicio()">Jogar novamente</button>
    </div>

    <div class="resumo-topo" style="margin-top:14px">
      <div class="caixa" style="padding:18px">
        <div style="font-size:9.5px;font-weight:800;letter-spacing:1px;color:var(--txt3);text-transform:uppercase">Carreira finalizada</div>
        <div style="display:flex;align-items:center;gap:14px;margin:8px 0 14px">
          <div style="flex:1;min-width:0">
            <div style="font-size:27px;font-weight:900;letter-spacing:-1px">${esc(S.nome)}</div>
            <div class="ficha-tags" style="margin-top:6px">
              <span class="tag">#${esc(S.numero)}</span>
              <span class="tag pos">${esc(S.posicao)}</span>
              <span class="tag">${bandeira(S.pais,17)} ${esc(PAISES[S.pais]||S.pais)}</span>
            </div>
          </div>
          <div class="ovr-caixa" style="background:${cor}"><small>PICO</small><b>${S.picoOvr}</b></div>
        </div>
        <div class="ficha-stats" style="border-bottom:none;padding-bottom:0">
          <div><span>Jogos</span><b>${tot.jogos}</b></div>
          <div><span>Gols</span><b>${tot.gols}</b></div>
          <div><span>Ast</span><b>${tot.ast}</b></div>
        </div>
      </div>
      <div class="caixa" style="padding:18px;text-align:center">
        <div style="font-size:9.5px;font-weight:800;letter-spacing:1px;color:var(--txt3);text-transform:uppercase">Maior valor</div>
        <div style="font-size:31px;font-weight:900;letter-spacing:-1px;margin:8px 0">${moeda(S.picoValor)}</div>
        <div style="font-size:12px;color:var(--txt2)">${S.temporadas.length} temporadas · ${Object.keys(porClube).length} clubes</div>
      </div>
    </div>

    <div class="clubes-grade">
      ${Object.entries(porClube).map(([nome,n]) => `
        <div class="clube-card">${escudo(acharClube(nome), 44)}<b>${esc(nome)}</b>
          <div class="cc-nums">
            <div><span>Jogos</span>${n.jogos}</div><div><span>Gols</span>${n.gols}</div><div><span>Ast</span>${n.ast}</div>
          </div></div>`).join('')}
    </div>

    <div id="conquistas"></div>
    <p class="rodape">Os nomes de clube servem para identificar dentro da simulação.
    Este jogo não é afiliado, patrocinado nem endossado por nenhum deles.</p>`;

  // As conquistas são decididas no SERVIDOR, com os totais recalculados a
  // partir das temporadas — o resumo que o cliente desenha não vale como
  // prova do que aconteceu.
  try {
    const r = await fetch(location.pathname, {
      method: 'POST',
      headers: {'Content-Type':'application/x-www-form-urlencoded'},
      body: new URLSearchParams({carreira: JSON.stringify({
        nome:S.nome, numero:S.numero, posicao:S.posicao, pais:S.pais,
        temporadas:S.temporadas, idadeFinal:S.idade,
        comecouAbaixo:S.comecouAbaixo, maiorForcaClube:S.maiorForcaClube,
      })}),
    });
    const d = await r.json();
    if (d.ok && d.conquistas && d.conquistas.length) {
      document.getElementById('conquistas').innerHTML =
        `<h2 style="font-size:17px;margin:26px 0 10px">Conquistas da carreira</h2>
         <div class="conq-grade">${d.conquistas.map(c => `
           <div class="conq"><span class="ic">${c.icone}</span>
             <div><b>${esc(c.nome)}</b><small>${esc(c.desc)}</small></div></div>`).join('')}</div>`;
    }
  } catch (e) { /* sem rede, a carreira ainda aparece inteira */ }

  apagar();
}

telaInicio();
</script>
</body>
</html>
