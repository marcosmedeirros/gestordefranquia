<?php
/**
 * A ESCALAÇÃO ATÉ AQUI — o bloco que acompanha as três telas do draft.
 *
 * Fica num arquivo próprio porque aparece em três momentos (escolhendo,
 * time pronto, depois da partida) e nos três conta a mesma coisa: quem já
 * está em campo, o OVR de cada um e a química que ele puxou. Três cópias
 * disso divergiriam na primeira mudança de layout.
 *
 * Espera `$d` (o draft da sessão) e `$quimParcial` já calculados.
 */
$vagas = DFUT_FORMACOES[$d['formacao']] ?? [];
?>
<div class="bloco">
  <h2>Escalação</h2>
  <p class="sub">A química conta com os vizinhos de posição: mesmo clube vale mais que mesma liga.</p>
  <div class="escal">
    <?php foreach ($vagas as $i => [$rot, $posNatural]):
      $c = $d['time'][$i] ?? null;
      $q = (int)($quimParcial['jogadores'][$i] ?? 0);
      $cls = $q >= 70 ? 'alta' : ($q >= 45 ? 'media' : 'baixa'); ?>
      <div class="esc-l <?= $c ? '' : 'vazia' ?>">
        <span class="rot"><?= e($rot) ?></span>
        <?php if ($c): ?>
          <span class="nm"><?= e($c['nome']) ?>
            <small><?= e($c['clube']) ?><?= $c['pos'] !== $posNatural
              ? ' · fora de posição (' . e($c['pos']) . ')' : '' ?></small></span>
          <span class="ov"><?= (int)$c['ovr'] ?></span>
          <span class="qm <?= $cls ?>"><?= $q ?></span>
        <?php else: ?>
          <span class="nm"><small>aguardando…</small></span>
          <span class="ov">—</span>
          <span class="qm media">—</span>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
</div>
