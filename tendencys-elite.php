<?php
/**
 * TENDENCYS DA ELITE — a tela, só do dono.
 *
 * Todos os jogadores da ELITE com as tendências do jogo numa tabela só, e o
 * caminho pra atualizar em massa: baixar o modelo, pedir pra IA preencher a
 * partir do print, subir o CSV de volta. Mesmo caminho das letras e das
 * estatísticas (@see controle-elencos.php), com um prompt próprio.
 *
 * ── SÓ POR LINK, E SÓ PRO DONO ───────────────────────────────────────
 *
 * Não entra em menu nenhum: quem tem o endereço chega, e mesmo assim só
 * passa quem está logado com o e-mail do dono — a mesma trava do
 * marcos-dev.php. Tendência é informação de bastidor: na mão de um GM, saber
 * que o pivô do adversário tem T/SHOT 30 é vantagem que a liga não combinou
 * de dar.
 *
 * ── A TABELA É LARGA DE PROPÓSITO ────────────────────────────────────
 *
 * São dezenas de colunas e não há como caber numa tela. Em vez de esconder
 * coluna atrás de menu, a tabela rola pro lado com a coluna do jogador
 * grudada na esquerda — que é como o próprio jogo mostra, e como qualquer um
 * lê planilha.
 *
 * @see backend/tendencias.php  as regras e a gravação
 * @see backend/mcp_tools.php   a ferramenta `tendencias`, pra consultar daqui de fora
 */
require_once __DIR__ . '/backend/auth.php';
require_once __DIR__ . '/backend/db.php';
require_once __DIR__ . '/backend/helpers.php';
require_once __DIR__ . '/backend/tendencias.php';
requireAuth();

/** O mesmo dono do marcos-dev.php. */
const DONO_TEND = 'medeirros99@gmail.com';

$user = getUserSession();
if (strtolower(trim((string)($user['email'] ?? ''))) !== DONO_TEND) {
    http_response_code(403);
    exit('Sem acesso.');
}

$pdo  = db();
$liga = 'ELITE';
$aviso = null;

/* ── O ENVIO DO CSV ───────────────────────────────────────────────────
   Chega como POST comum e não por fetch: a tela recarrega mostrando o que
   entrou, e assim o resultado da importação é a própria tabela atualizada —
   não um "deu certo" que a pessoa tem que acreditar. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv'])) {
    $arq = $_FILES['csv'];
    if (($arq['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $aviso = ['err', 'O arquivo não chegou. Tente de novo.'];
    } elseif (($arq['size'] ?? 0) > 2 * 1024 * 1024) {
        $aviso = ['err', 'Arquivo grande demais (o limite é 2 MB).'];
    } else {
        $texto = (string)file_get_contents($arq['tmp_name']);
        $texto = preg_replace('/^\xEF\xBB\xBF/', '', $texto);   // BOM do Excel
        $linhas = array_map('str_getcsv', preg_split('/\r\n|\r|\n/', $texto));
        $linhas = array_values(array_filter($linhas, fn($l) => $l !== [null] && $l !== ['']));

        $r = tendImportar($pdo, $liga, $linhas);
        $msg = "<b>{$r['gravados']} jogador(es) atualizados.</b>";
        if ($r['ignorados']) $msg .= " {$r['ignorados']} linha(s) sem nada pra gravar.";
        if ($r['erros']) {
            $msg .= '<br><br>O que não entrou:<br>• ' . implode('<br>• ',
                array_map(fn($e) => htmlspecialchars($e, ENT_QUOTES, 'UTF-8'),
                          array_slice($r['erros'], 0, 12)));
            if (count($r['erros']) > 12) $msg .= '<br>• … e mais ' . (count($r['erros']) - 12) . '.';
        }
        $aviso = [$r['gravados'] ? ($r['erros'] ? 'warn' : 'ok') : 'err', $msg];
    }
}

$dados     = tendJogadores($pdo, $liga);
$jogadores = $dados['jogadores'];
$colunas   = $dados['colunas'];
$comDados  = count(array_filter($jogadores, fn($j) => !empty($j['tend'])));
$ultima    = max(array_merge([''], array_column($jogadores, 'atualizado_em')));

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/** O tom da célula pelo valor: tendência alta salta, baixa some. */
function tendCor(int $v): string
{
    if ($v >= 80) return 'alta';
    if ($v >= 50) return 'media';
    if ($v >= 25) return 'baixa';
    return 'nula';
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Tendencys da ELITE · FBA Manager</title>
<meta name="robots" content="noindex, nofollow">
<link rel="icon" type="image/png" href="/img/fba-logo.png?v=3">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800;900&family=Oswald:wght@500;700&display=swap" rel="stylesheet">
<style>
:root{
  --red:#fc0025;--red-soft:color-mix(in srgb,var(--red) 12%,transparent);
  --bg:#07070a;--panel:#101013;--panel-2:#16161a;--panel-3:#1c1c21;
  --border:rgba(255,255,255,.07);--border-md:rgba(255,255,255,.12);
  --text:#f0f0f3;--text-2:#8b8b95;--text-3:#6f6f79;
  --green:#22c55e;--amber:#f59e0b;--blue:#3b82f6;
  --font:'Montserrat',system-ui,sans-serif;--num:'Oswald',sans-serif;--radius:14px;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:var(--font);background:var(--bg);color:var(--text);-webkit-font-smoothing:antialiased;
  padding:22px 18px 60px}
.wrap{max-width:1500px;margin:0 auto}
a{color:inherit}

.topo{display:flex;align-items:flex-start;justify-content:space-between;gap:14px;flex-wrap:wrap;margin-bottom:16px}
h1{font-size:24px;font-weight:800;line-height:1.15}
h1 i{color:var(--red)}
.sub{font-size:13px;color:var(--text-2);margin-top:4px;max-width:720px;line-height:1.55}
.voltar{display:inline-flex;align-items:center;gap:6px;padding:8px 14px;border-radius:10px;
  border:1px solid var(--border-md);color:var(--text-2);text-decoration:none;font-size:12.5px;font-weight:600}
.voltar:hover{border-color:var(--red);color:var(--red)}

.numeros{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px}
.pilula{background:var(--panel);border:1px solid var(--border);border-radius:11px;padding:9px 15px}
.pilula b{font-family:var(--num);font-size:19px;display:block;line-height:1}
.pilula span{font-size:11px;color:var(--text-2)}

.acoes{display:flex;gap:9px;flex-wrap:wrap;align-items:center;margin-bottom:16px}
.btn{display:inline-flex;align-items:center;gap:7px;padding:10px 16px;border-radius:11px;border:1px solid var(--border-md);
  background:var(--panel-2);color:var(--text);font:inherit;font-size:13px;font-weight:600;cursor:pointer;text-decoration:none}
.btn:hover{border-color:var(--red)}
.btn.pri{background:var(--red);border-color:var(--red);color:#fff}
.btn.pri:hover{filter:brightness(1.08)}
.busca{flex:1;min-width:200px;max-width:320px;padding:10px 14px;border-radius:11px;
  border:1px solid var(--border-md);background:var(--panel-2);color:var(--text);font:inherit;font-size:13px}

.aviso{padding:13px 16px;border-radius:12px;margin-bottom:16px;font-size:13px;line-height:1.6}
.aviso.ok{background:color-mix(in srgb,var(--green) 13%,transparent);color:var(--green)}
.aviso.warn{background:color-mix(in srgb,var(--amber) 13%,transparent);color:var(--amber)}
.aviso.err{background:color-mix(in srgb,var(--red) 13%,transparent);color:#ff8a9c}

/* ── A TABELA ──────────────────────────────────────────────────────────
   Rola pro lado com o jogador grudado na esquerda. Sem isso, a vigésima
   coluna é um número sem dono. */
.tabela{overflow:auto;max-height:72vh;border:1px solid var(--border);border-radius:var(--radius);
  background:var(--panel)}
table{border-collapse:separate;border-spacing:0;width:max-content;min-width:100%}
th,td{padding:8px 10px;font-size:12.5px;white-space:nowrap;border-bottom:1px solid var(--border)}
thead th{position:sticky;top:0;z-index:3;background:var(--panel-3);font-size:11px;letter-spacing:.4px;
  text-transform:uppercase;color:var(--text-2);font-weight:700;text-align:center}
tbody tr:hover td{background:var(--panel-2)}
.col-jog{position:sticky;left:0;z-index:2;background:var(--panel);min-width:190px;font-weight:600}
thead .col-jog{z-index:4;background:var(--panel-3);text-align:left}
tbody tr:hover .col-jog{background:var(--panel-2)}
.col-jog small{display:block;font-weight:500;font-size:11px;color:var(--text-3)}
.num{font-family:var(--num);text-align:center;font-size:13.5px}
td.tend{text-align:center;font-family:var(--num);font-size:14px}
/* O valor pinta a célula: alto salta, baixo some. Ler cinquenta colunas de
   número cru é o mesmo que não ler nenhuma. */
td.alta {color:#86efac;background:color-mix(in srgb,var(--green) 15%,transparent)}
td.media{color:#cbd5e1}
td.baixa{color:var(--text-3)}
td.nula {color:#5b5b64}
td.vazio{color:#3f3f46}

.rodape{margin-top:14px;font-size:12px;color:var(--text-3);line-height:1.6}

dialog{border:1px solid var(--border-md);border-radius:16px;background:var(--panel);color:var(--text);
  max-width:620px;width:94vw;padding:0}
dialog::backdrop{background:rgba(0,0,0,.7)}
.dlg-cab{padding:18px 20px 0;font-size:17px;font-weight:800}
.dlg-corpo{padding:14px 20px 20px;font-size:13px;color:var(--text-2);line-height:1.6}
.dlg-corpo ol{margin:10px 0 14px 18px}
.dlg-corpo li{margin-bottom:7px}
.dlg-pe{display:flex;gap:9px;justify-content:flex-end;padding:0 20px 20px;flex-wrap:wrap}
input[type=file]{font:inherit;font-size:12.5px;color:var(--text-2)}
</style>
</head>
<body>
<div class="wrap">

  <div class="topo">
    <div>
      <h1><i class="bi bi-sliders"></i> Tendencys da ELITE</h1>
      <p class="sub">O que cada jogador <em>tenta</em> fazer em quadra, direto do jogo. Página sem
         link em menu nenhum e aberta só pra você — tendência é informação de bastidor.<br>
         Só entra quem tem <b><?= TEND_OVR_MINIMO ?> OVR ou mais</b>: abaixo disso é banco de fim de
         elenco, e o print a print não compensa.</p>
    </div>
    <a class="voltar" href="/dashboard.php"><i class="bi bi-arrow-left"></i> Voltar</a>
  </div>

  <?php if ($aviso): ?>
    <div class="aviso <?= h($aviso[0]) ?>"><?= $aviso[1] ?></div>
  <?php endif; ?>

  <div class="numeros">
    <div class="pilula"><b><?= count($jogadores) ?></b><span>jogadores de <?= TEND_OVR_MINIMO ?>+ OVR</span></div>
    <div class="pilula"><b><?= $comDados ?></b><span>com tendência lançada</span></div>
    <div class="pilula"><b><?= count($colunas) ?></b><span>colunas</span></div>
    <div class="pilula"><b><?= $ultima ? date('d/m H:i', strtotime($ultima)) : '—' ?></b>
      <span>último lançamento</span></div>
  </div>

  <div class="acoes">
    <button class="btn" id="bModelo"><i class="bi bi-download"></i> Baixar modelo CSV</button>
    <?php /* O MODELO SÓ DE QUEM FALTA. Com 473 jogadores, depois do primeiro
         lote o arquivo cheio é quase todo trabalho já feito — e mandar 473
         linhas pra IA pra ela preencher 40 é pedir pra ela errar as outras
         433 de volta. O botão some quando não falta ninguém, em vez de
         baixar um arquivo com zero linhas. */ ?>
    <?php if ($comDados && $comDados < count($jogadores)): ?>
      <button class="btn" id="bFaltam"><i class="bi bi-download"></i>
        Só quem falta (<?= count($jogadores) - $comDados ?>)</button>
    <?php endif; ?>
    <button class="btn" id="bPrompt"><i class="bi bi-clipboard"></i> Copiar prompt pra IA</button>
    <button class="btn pri" id="bSubir"><i class="bi bi-upload"></i> Subir CSV</button>
    <input class="busca" id="busca" type="search" placeholder="Procurar jogador ou time…">
  </div>

  <div class="tabela">
    <table id="tbl">
      <thead>
        <tr>
          <th class="col-jog">Jogador</th>
          <th>Pos</th><th>Idade</th><th>OVR</th>
          <?php foreach ($colunas as $c): ?><th><?= h($c) ?></th><?php endforeach; ?>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($jogadores as $j):
          $pos = $j['position'] . ($j['secondary_position'] ? '/' . $j['secondary_position'] : ''); ?>
          <tr data-busca="<?= h(mb_strtolower($j['name'] . ' ' . $j['time'])) ?>">
            <td class="col-jog"><?= h($j['name']) ?><small><?= h($j['time']) ?></small></td>
            <td class="num"><?= h($pos) ?></td>
            <td class="num"><?= (int)$j['age'] ?></td>
            <td class="num"><?= (int)$j['ovr'] ?></td>
            <?php foreach ($colunas as $c):
              $v = $j['tend'][$c] ?? null; ?>
              <td class="tend <?= $v === null ? 'vazio' : tendCor((int)$v) ?>">
                <?= $v === null ? '—' : (int)$v ?></td>
            <?php endforeach; ?>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <p class="rodape">
    O CSV manda nas colunas: subiu um arquivo com uma tendência que ainda não existia aqui, ela
    passa a existir e aparece na tabela. Coluna em branco quer dizer “não mexi” — pra zerar de
    verdade, escreva 0.<br>
    Pelo MCP: a ferramenta <b>tendencias</b> devolve isto em texto — por jogador, por time ou a
    liga inteira.
  </p>
</div>

<dialog id="dlg">
  <form method="POST" enctype="multipart/form-data">
    <div class="dlg-cab">Subir o CSV de tendências</div>
    <div class="dlg-corpo">
      <ol>
        <li><b>Baixe o modelo</b> — ele já vem com os <?= count($jogadores) ?> jogadores da ELITE
            de <?= TEND_OVR_MINIMO ?>+ OVR e as colunas que existem hoje.</li>
        <li><b>Copie o prompt</b>, cole na IA e anexe o print da aba Tendency. Se o print tiver
            colunas que não estão no modelo, a IA pode acrescentá-las.</li>
        <li><b>Suba o .csv</b> que ela devolver.</li>
      </ol>
      <input type="file" name="csv" accept=".csv,text/csv" required>
    </div>
    <div class="dlg-pe">
      <button type="button" class="btn" id="bFechar">Cancelar</button>
      <button type="submit" class="btn pri"><i class="bi bi-upload"></i> Enviar</button>
    </div>
  </form>
</dialog>

<script src="<?= assetUrl('/js/elenco-csv.js') ?>"></script>
<script>
const COLUNAS = <?= json_encode($colunas, JSON_UNESCAPED_UNICODE) ?>;
const JOGADORES = <?= json_encode(array_map(fn($j) => [
        'id' => (int)$j['id'], 'nome' => $j['name'], 'time' => $j['time'],
        'tend' => (object)$j['tend'],
    ], $jogadores), JSON_UNESCAPED_UNICODE) ?>;

/* O MODELO leva o valor de HOJE em cada célula, e não um arquivo vazio: assim
   a IA tem com o que comparar, e o que ela não encontrar no print continua
   valendo em vez de virar buraco. */
const semTendencia = j => !Object.keys(j.tend).length;

function modeloLinhas(soFaltam) {
  const lista = soFaltam ? JOGADORES.filter(semTendencia) : JOGADORES;
  const linhas = [['id', 'jogador', 'time', ...COLUNAS]];
  lista.forEach(j => {
    linhas.push([j.id, j.nome, j.time, ...COLUNAS.map(c => j.tend[c] ?? '')]);
  });
  return linhas;
}

document.getElementById('bModelo').addEventListener('click', () => {
  ElencoCSV.baixar('tendencias-elite.csv', modeloLinhas(false));
});

const bFaltam = document.getElementById('bFaltam');
if (bFaltam) bFaltam.addEventListener('click', () => {
  ElencoCSV.baixar('tendencias-elite-faltam.csv', modeloLinhas(true));
});

document.getElementById('bPrompt').addEventListener('click', (e) => {
  /* No prompt vai o modelo com POUCAS linhas: ele é exemplo de formato, e
     despejar 300 jogadores no chat só gasta a janela da IA. O arquivo que a
     pessoa anexa é que tem a lista inteira. */
  const m = modeloLinhas();
  const amostra = [m[0], ...m.slice(1, 6)];
  if (m.length > 6) amostra.push(['…', '…', '…', ...COLUNAS.map(() => '…')]);
  ElencoCSV.copiar(ElencoCSV.promptTendencias(ElencoCSV.paraTexto(amostra)), e.currentTarget);
});

const dlg = document.getElementById('dlg');
document.getElementById('bSubir').addEventListener('click', () => dlg.showModal());
document.getElementById('bFechar').addEventListener('click', () => dlg.close());

document.getElementById('busca').addEventListener('input', (e) => {
  const q = e.target.value.trim().toLowerCase();
  document.querySelectorAll('#tbl tbody tr').forEach(tr => {
    tr.style.display = !q || tr.dataset.busca.includes(q) ? '' : 'none';
  });
});
</script>
</body>
</html>
