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
      <div class="t"><b><?= e($r['nome']) ?></b><small>força <?= $r['forca'] ?> · química <?= $r['quimica'] ?>/<?= DFUT_VAGAS * 3 ?></small></div>
      <?php /* O PLACAR COMEÇA EM 0x0 E ANDA COM A NARRAÇÃO. Ele vinha pronto,
           com os lances contando depois o que já estava escrito em cima —
           era ler a última página antes do livro. Agora o gol aparece no
           minuto em que acontece. */ ?>
      <div class="g"><span id="gc">0</span> <span style="color:var(--txt3)">x</span> <span id="gf">0</span></div>
      <div class="t"><b><?= e($r['adv']['nome']) ?></b><small>força <?= $r['adv']['forca'] ?>
        · <?= $r['modo'] === 'pvp' ? 'outro GM' : 'máquina' ?></small></div>
    </div>
    <div class="narra" id="narra"></div>
    <div id="fecho" style="display:none">
      <div style="text-align:center;margin:14px 0">
        <?php if ($r['premio'] > 0): ?>
          <span class="aviso ok" style="display:inline-block"><i class="bi bi-coin"></i>
            +<?= $r['premio'] ?> moedas</span>
        <?php else: ?>
          <span class="aviso err" style="display:inline-block">Sem prêmio dessa vez.</span>
        <?php endif; ?>
      </div>
      <form method="POST" style="text-align:center"><input type="hidden" name="acao" value="novo">
        <button class="btn pri" type="submit"><i class="bi bi-arrow-repeat"></i> Novo draft</button></form>
    </div>
    <div style="margin-top:14px;text-align:center">
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
    if (!animar) d.style.animation = 'none';
    d.innerHTML = `<span class="m">${l.min}'</span><span>${l.texto}</span>`;
    alvo.appendChild(d);
    alvo.scrollTop = alvo.scrollHeight;

    elRel.textContent = l.min + "'";
    if (l.casa !== undefined) {
      const virou = elGc.textContent != l.casa || elGf.textContent != l.fora;
      elGc.textContent = l.casa; elGf.textContent = l.fora;
      if (virou && animar) { elG.classList.add('pulsa'); setTimeout(() => elG.classList.remove('pulsa'), 240); }
    }
    if (l.tipo === 'fim') document.getElementById('fecho').style.display = '';
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
