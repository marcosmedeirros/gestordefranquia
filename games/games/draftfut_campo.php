<?php
/**
 * O CAMPINHO E O BANCO — a tela central do draft.
 *
 * Mostra as onze posições desenhadas no gramado e os oito do banco. Cada
 * vaga vazia é um botão: clicar nela é que abre as cinco cartas. Era o
 * contrário antes — o jogo mandava a ordem e a pessoa só aceitava —, e o
 * Marcos pediu o do FIFA, em que você escolhe por onde começar.
 *
 * Espera `$d` (draft da sessão), `$quim` (química calculada) e `$aberta`
 * (a vaga cujas cartas estão na mesa, ou null).
 */
$F = $d['formacao'];
$coords = DFUT_CAMPO[$F] ?? [];
?>
<div class="bloco">
  <div class="campo-topo">
    <h2>Campo — <?= e($F) ?></h2>
    <div class="quim-barra" title="Química do time">
      <span class="qb-trilho"><span class="qb-fill" style="width:<?= (int)$quim['total'] ?>%"></span></span>
      <b><?= (int)$quim['total'] ?></b>
    </div>
  </div>
  <p class="sub">Clique numa posição pra abrir as cartas dela. Arraste não — toque
     em dois jogadores pra trocá-los de lugar.</p>

  <div class="campo" id="campo">
    <div class="linha-meio"></div><div class="circulo"></div>
    <div class="area area-cima"></div><div class="area area-baixo"></div>
    <?php foreach ($coords as $i => [$x, $y]):
      $c = $d['time'][$i] ?? null;
      $q = (int)($quim['jogadores'][$i] ?? 0);
      $rot = draftFutRotuloDaVaga($F, $i);
      $nat = draftFutPosDaVaga($F, $i);
      $cls = $q >= 70 ? 'alta' : ($q >= 45 ? 'media' : 'baixa'); ?>
      <div class="slot <?= $c ? 'cheio' : 'vazio' ?><?= $aberta === $i ? ' aberta' : '' ?><?= !empty($c['lenda']) ? ' lenda' : '' ?>"
           style="left:<?= $x ?>%;top:<?= $y ?>%"
           data-vaga="<?= $i ?>" data-tem="<?= $c ? 1 : 0 ?>">
        <?php if ($c): ?>
          <span class="s-ovr"><?= (int)$c['ovr'] ?></span>
          <?php if ($c['escudo']): ?><img class="s-esc" src="<?= e($c['escudo']) ?>" alt="" loading="lazy"><?php endif; ?>
          <span class="s-nome"><?= e(draftFutNomeCurto($c['nome'])) ?></span>
          <span class="s-rot"><?= e($rot) ?><?= $c['pos'] !== $nat ? ' ⚠' : '' ?></span>
          <span class="s-quim <?= $cls ?>"></span>
        <?php else: ?>
          <span class="s-mais">+</span>
          <span class="s-rot"><?= e($rot) ?></span>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>

  <h3 class="banco-tit">Banco <span><?= count(array_filter(array_slice($d['time'] + array_fill(0, DFUT_TOTAL, null), DFUT_VAGAS))) ?>/<?= DFUT_BANCO ?></span></h3>
  <div class="banco">
    <?php for ($i = DFUT_VAGAS; $i < DFUT_TOTAL; $i++):
      $c = $d['time'][$i] ?? null;
      $rot = draftFutRotuloDaVaga($F, $i); ?>
      <div class="slot banco-s <?= $c ? 'cheio' : 'vazio' ?><?= $aberta === $i ? ' aberta' : '' ?><?= !empty($c['lenda']) ? ' lenda' : '' ?>"
           data-vaga="<?= $i ?>" data-tem="<?= $c ? 1 : 0 ?>">
        <?php if ($c): ?>
          <span class="s-ovr"><?= (int)$c['ovr'] ?></span>
          <span class="s-nome"><?= e(draftFutNomeCurto($c['nome'])) ?></span>
          <span class="s-rot"><?= e($c['pos']) ?></span>
        <?php else: ?>
          <span class="s-mais">+</span><span class="s-rot"><?= e($rot) ?></span>
        <?php endif; ?>
      </div>
    <?php endfor; ?>
  </div>
</div>
