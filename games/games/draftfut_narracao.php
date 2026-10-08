<?php
/**
 * ── A NARRAÇÃO DA PARTIDA ────────────────────────────────────────────
 *
 * Saiu de dentro de draftfut.php em 08/10/2026, quando o duelo por código
 * passou a precisar da mesma tela. Duas cópias divergiriam na primeira
 * mudança — e a partida é a mesma, vista do mesmo motor.
 *
 * Espera $r com: nome, forca, quimica, modo, premio, adv[nome,forca] e
 * $p com os lances. Quem inclui monta esses dois.
 */
if (!isset($r, $p) || empty($p['lances'])) return;
?>
  <div class="bloco">
    <h2>Partida</h2>
    <div class="relogio" id="relogio">0'</div>
    <div class="placar">
      <?php /* ── O ESCUDO DE CADA LADO ──────────────────────────────────
           Pedido do Marcos (08/10/2026): "tu tem os escudos, sendo assim,
           use a logo do time e contra o bot, use o escudo do time que ta
           enfrentando". Um placar com dois nomes escritos é um placar de
           planilha; com os escudos, é um jogo. Quem não tiver escudo mostra
           a inicial, e o lugar continua ocupado — sem buraco no meio. */
        $dfEsc = static function (array $lado): string {
            $u = trim((string)($lado['escudo'] ?? ''));
            if ($u !== '') {
                return '<img class="pl-esc" src="' . e($u) . '" alt="" loading="lazy" '
                     . 'onerror="this.style.display=\'none\'">';
            }
            return '<span class="pl-mono">' . e(mb_substr((string)($lado['nome'] ?? '?'), 0, 1)) . '</span>';
        }; ?>
      <div class="t">
        <?= $dfEsc($r) ?>
        <b><?= e($r['nome']) ?></b><small>força <?= $r['forca'] ?> · química <?= $r['quimica'] ?>/<?= DFUT_VAGAS * 3 ?></small></div>
      <?php /* O PLACAR COMEÇA EM 0x0 E ANDA COM A NARRAÇÃO. Ele vinha pronto,
           com os lances contando depois o que já estava escrito em cima —
           era ler a última página antes do livro. Agora o gol aparece no
           minuto em que acontece. */ ?>
      <div class="g"><span id="gc">0</span> <span style="color:var(--txt3)">x</span> <span id="gf">0</span></div>
      <div class="t">
        <?= $dfEsc($r['adv']) ?>
        <b><?= e($r['adv']['nome']) ?></b><small>força <?= $r['adv']['forca'] ?>
        · <?= $r['modo'] === 'pvp' ? 'outro GM' : 'bot' ?></small></div>
    </div>
    <div class="narra" id="narra"></div>
    <?php /* ── O QUE FAZER DEPOIS DO APITO ──────────────────────────────
         Pedido do Marcos (08/10/2026): "apos finalizar o jogo o Pular pro fim
         some e vira Voltar pra tela inicial ou novo draft". Faz sentido: no
         fim, "pular" não leva a lugar nenhum — o botão que sobra tem que ser
         uma saída. Os dois ficam escondidos até o fim e trocam de lugar com o
         "Pular", que é escondido pelo mesmo JavaScript. */ ?>
    <div id="fecho" style="display:none">
      <div style="text-align:center;margin:14px 0">
        <?php if ($r['premio'] > 0): ?>
          <span class="aviso ok" style="display:inline-block"><i class="bi bi-coin"></i>
            +<?= $r['premio'] ?> moedas</span>
        <?php else: ?>
          <span class="aviso err" style="display:inline-block">Sem prêmio dessa vez.</span>
        <?php endif; ?>
      </div>
      <div class="fim-acoes">
        <?php if (!empty($r['revanche'])): ?>
          <?php /* REVANCHE: só no duelo. Um clique de cada um e a partida
                   nasce com a MESMA aposta, sem código e sem link — se o
                   outro já clicou, este clique entra no duelo dele. */ ?>
          <form method="POST" style="margin:0">
            <input type="hidden" name="acao" value="duelo_revanche">
            <input type="hidden" name="duelo" value="<?= (int)$r['revanche']['duelo'] ?>">
            <button class="btn pri" type="submit">
              <i class="bi bi-arrow-counterclockwise"></i>
              <?php if (!empty($r['revanche']['chamou'])): ?>
                Aceitar a revanche (<?= (int)$r['revanche']['aposta'] ?> moedas)
              <?php else: ?>
                Revanche por <?= (int)$r['revanche']['aposta'] ?> moedas
              <?php endif; ?></button>
          </form>
        <?php else: ?>
          <form method="POST" style="margin:0"><input type="hidden" name="acao" value="novo">
            <input type="hidden" name="v" value="bot">
            <button class="btn pri" type="submit"><i class="bi bi-arrow-repeat"></i> Novo draft</button></form>
        <?php endif; ?>
        <form method="POST" style="margin:0"><input type="hidden" name="acao" value="novo">
          <button class="btn" type="submit"><i class="bi bi-house"></i> Voltar pra tela inicial</button></form>
      </div>
    </div>
    <div id="pularCaixa" style="margin-top:14px;text-align:center">
      <button class="btn" type="button" id="pular">Pular pro fim</button>
    </div>
  </div>
  <script>
  /* A PARTIDA PASSA, ELA NÃO É LIDA. O relógio anda, o placar vira no
     minuto do gol e o prêmio só aparece no apito final — quem está vendo
     não sabe o resultado até ele acontecer. "Pular pro fim" existe pra
     quem já viu essa parte. */
  const LANCES = <?= json_encode($p['lances'], JSON_UNESCAPED_UNICODE) ?>;
  const alvo = document.getElementById('narra');
  const elGc = document.getElementById('gc'), elGf = document.getElementById('gf');
  const elRel = document.getElementById('relogio'), elG = document.querySelector('.placar .g');
  let i = 0, timer = null;

  function desenha(l, animar){
    const d = document.createElement('div');
    d.className = 'lance ' + l.tipo;
    if (!animar) d.classList.add('pronto');
    d.innerHTML = `<span class="m">${l.min}'</span><span>${l.texto}</span>`;
    alvo.appendChild(d);
    alvo.scrollTop = alvo.scrollHeight;

    elRel.textContent = l.min + "'";
    if (l.casa !== undefined) {
      const virou = elGc.textContent != l.casa || elGf.textContent != l.fora;
      elGc.textContent = l.casa; elGf.textContent = l.fora;
      if (virou && animar) { elG.classList.add('pulsa'); setTimeout(() => elG.classList.remove('pulsa'), 240); }
    }
    if (l.tipo === 'fim') {
      document.getElementById('fecho').style.display = '';
      /* "Pular pro fim" no fim não leva a lugar nenhum. */
      var pc = document.getElementById('pularCaixa');
      if (pc) pc.style.display = 'none';
    }
  }

  function passo(){
    if (i >= LANCES.length) { clearInterval(timer); return; }
    desenha(LANCES[i++], true);
  }
  timer = setInterval(passo, 850);
  passo();
  document.getElementById('pular').onclick = () => {
    clearInterval(timer);
    while (i < LANCES.length) desenha(LANCES[i++], false);
  };
  </script>
