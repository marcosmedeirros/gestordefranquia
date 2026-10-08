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
      <span class="qb-trilho"><span class="qb-fill"
            style="width:<?= (int)round($quim['total'] / (DFUT_VAGAS * 3) * 100) ?>%"></span></span>
      <b><?= (int)$quim['total'] ?><small style="opacity:.45;font-size:12px">/<?= DFUT_VAGAS * 3 ?></small></b>
    </div>
  </div>
  <p class="sub">Clique numa vaga vazia pra abrir as cartas dela. Pra trocar dois de
     lugar, toque num jogador e depois no outro — <b>os dois já têm que estar
     escolhidos</b>. Trocar recalcula a química na hora, inclusive de quem sobe
     do banco.</p>

  <?php /* A EXPLICAÇÃO FICA AQUI, do lado da barra que ela explica — e
           fechada, porque quem já entendeu não precisa ler de novo toda vez.
           É <details>, não pop-up: não tem o que confirmar. */ ?>
  <details class="quim-ajuda">
    <summary><i class="bi bi-info-circle"></i> Como funciona a química</summary>
    <div class="qa-corpo">
      <p>Cada jogador em campo vale de <b>0 a 3</b>, e o time soma até
         <b><?= DFUT_VAGAS * 3 ?></b>. Quanto mais química, mais forte o time joga.</p>
      <p>Os pontos saem de quantos dos <b>onze titulares</b> dividem a mesma coisa
         que ele:</p>
      <ul class="qa-regras">
        <li><b>Mesmo clube</b> — 2 jogadores dão 1 ponto · 4 dão 2 · 7 dão 3</li>
        <li><b>Mesma nação</b> — 2 dão 1 · 5 dão 2 · 8 dão 3</li>
        <li><b>Mesma liga</b> — 3 dão 1 · 5 dão 2 · 8 dão 3</li>
      </ul>
      <p>Os três somam, e o total de cada jogador para em 3.</p>
      <p><b>Fora de posição zera.</b> Quem não está na posição dele fica com 0 — e
         ainda sai da contagem dos outros, então atrapalha o time todo, não só a
         si mesmo. É a carta com a borda vermelha.</p>
      <p><b>O banco não tem química</b>, porque não está em campo. O reserva ganha
         a dele no instante em que sobe pro time.</p>
      <p><b>Ícone tem 3 sempre</b> na posição certa, conta dois pra nação dele e um
         pra toda liga. <b>Herói</b> também tem 3, conta um pra nação e dois pra
         liga dele. As cartas de coleção (Time da Semana, Futuro) pontuam como
         carta comum — o que elas dão é OVR.</p>
    </div>
  </details>

  <div class="campo" id="campo">
    <div class="linha-meio"></div><div class="circulo"></div>
    <div class="area area-cima"></div><div class="area area-baixo"></div>
    <?php foreach ($coords as $i => [$x, $y]):
      $c = $d['time'][$i] ?? null;
      $q = (int)($quim['jogadores'][$i] ?? 0);
      $rot = draftFutRotuloDaVaga($F, $i);
      $nat = draftFutPosDaVaga($F, $i); ?>
      <div class="slot <?= $c ? 'cheio' : 'vazio' ?><?= $aberta === $i ? ' aberta' : '' ?>"
           style="left:<?= $x ?>%;top:<?= $y ?>%"
           data-vaga="<?= $i ?>" data-tem="<?= $c ? 1 : 0 ?>"
           <?= $c ? 'title="' . e($c['nome']) . ' — ' . e($c['clube']) . ' · química ' . $q . '/3"' : '' ?>>
        <?php if ($c): ?>
          <span class="slot-carta"><?= dfutCartaHtml($c, ['mini' => true, 'rotulo' => $rot,
                                 'fora' => $c['pos'] !== $nat]) ?></span>
          <?= dfutQuimicaHtml($q, true) ?>
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
      <div class="slot banco-s <?= $c ? 'cheio' : 'vazio' ?><?= $aberta === $i ? ' aberta' : '' ?>"
           data-vaga="<?= $i ?>" data-tem="<?= $c ? 1 : 0 ?>"
           <?= $c ? 'title="' . e($c['nome']) . ' — ' . e($c['clube']) . '"' : '' ?>>
        <?php if ($c): ?>
          <?php /* O BANCO NÃO TEM QUÍMICA, e não mostra losango nenhum: no EA
                   FC só pontua quem está em campo, e inventar nota aqui faria
                   a pessoa escalar reserva atrás de um número que não existe.
                   Ele ganha a química no instante em que sobe pro time — o
                   motor recalcula o time inteiro a cada troca. */ ?>
          <span class="slot-carta"><?= dfutCartaHtml($c, ['mini' => true, 'rotulo' => $c['pos']]) ?></span>
        <?php else: ?>
          <?php /* Sem setor escrito: a vaga de banco aceita qualquer posição,
                   e prometer "ZAG" aqui entregaria o pacote antes de abrir. */ ?>
          <span class="s-mais">+</span><span class="s-rot">LIVRE</span>
        <?php endif; ?>
      </div>
    <?php endfor; ?>
  </div>
</div>
