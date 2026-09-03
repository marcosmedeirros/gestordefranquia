<?php
/**
 * O GUIA ÚNICO DA FBA — rascunho fechado, só do dono.
 *
 * Junta os três editais por ASSUNTO em vez de por liga, pra que a mesma regra
 * escrita em três documentos apareça lado a lado e a divergência entre elas
 * fique visível. É o passo antes de virar documento oficial.
 *
 * NÃO ESTÁ LINKADA EM LUGAR NENHUM, de propósito: enquanto o conteúdo não for
 * revisado, um GM que caísse aqui leria como regra o que ainda é rascunho. O
 * acesso é pelo endereço direto e só pra DONO_DEV — a mesma trava do
 * marcos-dev.php.
 *
 * O texto dos artigos é lido dos PDFs a cada carga; só os resumos de cada
 * assunto são escritos à mão (em backend/edital_guia.php).
 */

require_once __DIR__ . '/backend/auth.php';
require_once __DIR__ . '/backend/db.php';
require_once __DIR__ . '/backend/edital_guia.php';

const EDITAL_DONO = 'medeirros99@gmail.com';

$user = getUserSession();
if (strtolower(trim((string)($user['email'] ?? ''))) !== EDITAL_DONO) {
    http_response_code(403);
    exit('Sem acesso.');
}

$pdo = db();

$assuntos     = editalAssuntos();
$cobertura    = editalCobertura($pdo);
$divergencias = editalDivergencias($pdo);

/* O conteúdo é montado uma vez e reaproveitado pelas duas leituras da tela
   (o resumo do topo e as seções), pra não reprocessar os PDFs duas vezes. */
$porAssunto = [];
foreach (array_keys($assuntos) as $k) $porAssunto[$k] = editalPorAssunto($pdo, $k);

$totalArtigos = array_sum(array_column($cobertura, 'artigos'));
$corDaLiga = ['ELITE' => '#ef4444', 'NEXT' => '#3b82f6', 'RISE' => '#22c55e', 'ROOKIE' => '#a855f7'];
$esc = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="pt-BR" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Guia único — FBA</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
  :root{
    --bg:#0b0d10; --panel:#14171c; --panel-2:#1a1e24; --border:#252a32;
    --txt:#e7eaee; --txt-2:#a7b0bc; --txt-3:#6d7783; --red:#ef4444; --amber:#f59e0b;
    --font:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;
  }
  *{box-sizing:border-box}
  body{margin:0;background:var(--bg);color:var(--txt);font-family:var(--font);
       font-size:15px;line-height:1.6;-webkit-font-smoothing:antialiased}
  .wrap{max-width:940px;margin:0 auto;padding:26px 18px 80px}

  .topo{display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:6px}
  .topo h1{font-size:23px;margin:0;letter-spacing:-.4px}
  .selo{font-size:10.5px;font-weight:800;letter-spacing:.09em;text-transform:uppercase;
        background:rgba(245,158,11,.14);color:var(--amber);
        border:1px solid rgba(245,158,11,.35);border-radius:999px;padding:3px 10px}
  .sub{color:var(--txt-3);font-size:13px;margin:0 0 22px}

  .aviso{background:rgba(245,158,11,.07);border:1px solid rgba(245,158,11,.3);
         border-radius:12px;padding:14px 16px;margin-bottom:24px}
  .aviso b{color:var(--amber)}
  .aviso p{margin:6px 0 0;font-size:13.5px;color:var(--txt-2)}

  .cobertura{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:24px}
  .lg{border:1px solid var(--border);border-radius:10px;padding:8px 13px;background:var(--panel);
      font-size:12.5px;display:flex;align-items:center;gap:7px}
  .lg b{font-size:13px}
  .lg.sem{opacity:.55}

  .busca{position:relative;margin-bottom:22px}
  .busca input{width:100%;background:var(--panel);border:1px solid var(--border);border-radius:11px;
               color:var(--txt);padding:12px 14px 12px 40px;font-size:14.5px;font-family:var(--font)}
  .busca input:focus{outline:none;border-color:var(--txt-3)}
  .busca i{position:absolute;left:14px;top:13px;color:var(--txt-3)}

  .indice{display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:8px;margin-bottom:34px}
  .indice a{display:flex;align-items:center;gap:9px;text-decoration:none;color:var(--txt-2);
            background:var(--panel);border:1px solid var(--border);border-radius:10px;
            padding:11px 13px;font-size:13.5px;transition:.15s}
  .indice a:hover{color:var(--txt);border-color:var(--txt-3);transform:translateY(-1px)}
  .indice i{color:var(--amber);font-size:15px}

  .assunto{margin-bottom:38px;scroll-margin-top:16px}
  .assunto h2{font-size:19px;margin:0 0 4px;display:flex;align-items:center;gap:10px}
  .assunto h2 i{color:var(--amber);font-size:17px}
  .resumo{color:var(--txt-2);font-size:14px;margin:0 0 14px;padding-left:27px}
  .vazio{color:var(--txt-3);font-size:13px;font-style:italic;padding-left:27px}

  details.liga{border:1px solid var(--border);border-radius:11px;background:var(--panel);
               margin-bottom:8px;overflow:hidden}
  details.liga>summary{cursor:pointer;padding:11px 15px;font-size:13.5px;font-weight:600;
                       display:flex;align-items:center;gap:9px;list-style:none}
  details.liga>summary::-webkit-details-marker{display:none}
  details.liga>summary::after{content:'\F282';font-family:'bootstrap-icons';margin-left:auto;
                              color:var(--txt-3);font-size:12px;transition:transform .15s}
  details.liga[open]>summary::after{transform:rotate(90deg)}
  .tag{font-size:10px;font-weight:800;letter-spacing:.06em;padding:2px 8px;border-radius:999px}
  .qtd{color:var(--txt-3);font-weight:500;font-size:12.5px}
  .arts{padding:2px 15px 14px}
  .art{border-top:1px solid var(--border);padding:12px 0}
  .art:first-child{border-top:none}
  .art-num{font-size:11px;font-weight:800;color:var(--amber);letter-spacing:.05em}
  .art-cap{font-size:11px;color:var(--txt-3);margin-left:8px}
  .art-txt{white-space:pre-wrap;font-size:13.5px;color:var(--txt-2);margin-top:5px}

  mark{background:rgba(245,158,11,.28);color:var(--txt);border-radius:3px;padding:0 2px}
  .some{display:none}

  @media (max-width:560px){
    .wrap{padding:18px 13px 60px}
    .topo h1{font-size:20px}
    .indice{grid-template-columns:1fr 1fr;gap:7px}
    .indice a{font-size:12.5px;padding:10px}
    .resumo,.vazio{padding-left:0}
  }
</style>
</head>
<body>
<div class="wrap">

  <div class="topo">
    <h1>Guia único da FBA</h1>
    <span class="selo">rascunho · só você vê</span>
  </div>
  <p class="sub">
    Os editais das quatro ligas organizados por assunto, e não por liga —
    <?= (int)$totalArtigos ?> artigos lidos direto dos PDFs.
  </p>

  <div class="aviso">
    <b>Isto ainda não é o edital.</b>
    <p>
      A página não está linkada em lugar nenhum e só abre pra você. Os resumos de cada assunto
      foram escritos a partir dos editais e do código; os artigos abaixo de cada resumo são o
      texto original, lido dos PDFs a cada carga. Antes de virar documento oficial, as
      divergências listadas ao final precisam de decisão sua.
    </p>
  </div>

  <div class="cobertura">
    <?php foreach ($cobertura as $liga => $c): ?>
      <span class="lg <?= $c['ok'] ? '' : 'sem' ?>">
        <span class="tag" style="background:<?= $corDaLiga[$liga] ?>22;color:<?= $corDaLiga[$liga] ?>"><?= $esc($liga) ?></span>
        <?php if ($c['ok']): ?>
          <b><?= (int)$c['artigos'] ?></b> artigos
        <?php else: ?>
          <span style="color:var(--txt-3)">sem edital</span>
        <?php endif; ?>
      </span>
    <?php endforeach; ?>
  </div>

  <div class="busca">
    <i class="bi bi-search"></i>
    <input type="search" id="q" placeholder="Buscar no guia inteiro — ex.: cap, leilão, punição, moedas"
           autocomplete="off">
  </div>

  <div class="indice" id="indice">
    <?php foreach ($assuntos as $k => $a): ?>
      <a href="#<?= $esc($k) ?>"><i class="bi <?= $esc($a['icone']) ?>"></i> <?= $esc($a['titulo']) ?></a>
    <?php endforeach; ?>
    <a href="#telas"><i class="bi bi-window-stack"></i> Como usar o site</a>
  </div>

  <?php foreach ($assuntos as $k => $a):
    $achados = $porAssunto[$k] ?? [];
    $total   = array_sum(array_map('count', $achados)); ?>
    <section class="assunto" id="<?= $esc($k) ?>" data-busca="<?= $esc(mb_strtolower($a['titulo'] . ' ' . $a['resumo'])) ?>">
      <h2><i class="bi <?= $esc($a['icone']) ?>"></i> <?= $esc($a['titulo']) ?></h2>
      <p class="resumo"><?= $esc($a['resumo']) ?></p>

      <?php if (!$total): ?>
        <p class="vazio">Nenhum artigo dos editais fala disso. Assunto a definir.</p>
      <?php else: foreach ($achados as $liga => $arts): ?>
        <details class="liga">
          <summary>
            <span class="tag" style="background:<?= $corDaLiga[$liga] ?>22;color:<?= $corDaLiga[$liga] ?>"><?= $esc($liga) ?></span>
            <span class="qtd"><?= count($arts) ?> artigo<?= count($arts) > 1 ? 's' : '' ?></span>
          </summary>
          <div class="arts">
            <?php foreach ($arts as $art): ?>
              <div class="art">
                <span class="art-num">ART. <?= (int)$art['num'] ?></span>
                <span class="art-cap"><?= $esc($art['capitulo']) ?></span>
                <div class="art-txt"><?= $esc($art['texto']) ?></div>
              </div>
            <?php endforeach; ?>
          </div>
        </details>
      <?php endforeach; endif; ?>
    </section>
  <?php endforeach; ?>

  <!-- COMO SE USA O SITE. Não sai dos editais: eles dizem que existe teto
       salarial, não em que tela se confere o espaço que sobrou. Essa metade
       das dúvidas do grupo não tinha onde ser respondida. -->
  <section class="assunto" id="telas">
    <h2><i class="bi bi-window-stack"></i> Como usar cada página</h2>
    <p class="resumo">
      O que cada tela do site faz. A regra por trás está nos assuntos acima; aqui é a operação.
    </p>
    <?php foreach (editalPaginas() as $grupo => $telas): ?>
      <div style="margin-bottom:14px">
        <div style="font-size:11.5px;font-weight:800;letter-spacing:.06em;color:var(--txt-3);margin-bottom:7px">
          <?= $esc(mb_strtoupper($grupo, 'UTF-8')) ?>
        </div>
        <?php foreach ($telas as [$url, $nome, $desc]): ?>
          <div style="border:1px solid var(--border);border-radius:10px;background:var(--panel);
                      padding:11px 14px;margin-bottom:6px">
            <div style="display:flex;align-items:baseline;gap:9px;flex-wrap:wrap">
              <b style="font-size:14px"><?= $esc($nome) ?></b>
              <code style="font-size:11.5px;color:var(--txt-3)"><?= $esc($url) ?></code>
            </div>
            <div style="font-size:13.5px;color:var(--txt-2);margin-top:3px"><?= $esc($desc) ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>
  </section>

  <?php if ($divergencias): ?>
    <section class="assunto" id="divergencias">
      <h2><i class="bi bi-exclamation-octagon-fill" style="color:var(--red)"></i>
          O que precisa de decisão sua</h2>
      <p class="resumo">
        Encontrado lendo os três editais. Enquanto um destes estiver aberto, publicar este guia
        como regra oficializaria uma contradição.
      </p>
      <?php foreach ($divergencias as $d): ?>
        <div class="aviso" style="<?= !empty($d['grave'])
            ? 'background:rgba(239,68,68,.07);border-color:rgba(239,68,68,.3)' : '' ?>">
          <b style="<?= !empty($d['grave']) ? 'color:var(--red)' : '' ?>"><?= $esc($d['titulo']) ?></b>
          <p><?= $esc($d['texto']) ?></p>
        </div>
      <?php endforeach; ?>
    </section>
  <?php endif; ?>

</div>

<script>
/* A busca filtra as seções e destaca o termo dentro dos artigos abertos.
   Tudo em memória: a página já veio inteira do servidor, e ir buscar de novo
   a cada tecla seria pedir três PDFs por letra digitada. */
(function () {
  const campo = document.getElementById('q');
  const secoes = [...document.querySelectorAll('.assunto')];
  const indice = document.getElementById('indice');

  const semAcento = s => s.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();

  function filtrar() {
    const t = semAcento(campo.value.trim());
    indice.classList.toggle('some', t.length > 0);

    secoes.forEach(s => {
      if (!t) { s.classList.remove('some'); return; }
      const alvo = semAcento(s.innerText);
      s.classList.toggle('some', !alvo.includes(t));
      // Abre as ligas da seção que casou, senão o resultado fica escondido
      // dentro de um accordeon fechado e parece que não achou nada.
      if (alvo.includes(t)) s.querySelectorAll('details').forEach(d => d.open = true);
    });
  }

  let timer;
  campo.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(filtrar, 120); });
})();
</script>
</body>
</html>
