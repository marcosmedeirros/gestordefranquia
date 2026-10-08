<?php
/**
 * ── AS DUAS ESCALAÇÕES, LADO A LADO ──────────────────────────────────
 *
 * Pedido do Marcos (08/10/2026): "acho legal no final que os times montarem
 * o draft, da pra ver os dois times enquanto o jogo simula".
 *
 * Faz falta mesmo. A narração conta que o Iñaki Williams marcou, mas até
 * aqui só dava pra ver o time de quem está olhando — o adversário era um
 * nome e um número de força. Quem levou um gol não sabia de quem, e quem
 * ganhou não sabia do quê. Com as duas listas na tela, a narração passa a
 * ter elenco: o nome que aparece no lance está ali na carta, do lado certo.
 *
 * ── POR QUE NÃO SÃO DOIS CAMPINHOS ───────────────────────────────────
 *
 * O campinho é bonito e é o que a pessoa montou, mas são duas formações
 * diferentes desenhadas em posições absolutas, e dois deles lado a lado num
 * telefone viram dois selos ilegíveis. Aqui é a mesma carta mini do campo,
 * em grade, agrupada por setor — gol, defesa, meio, ataque —, que é como se
 * lê uma escalação em qualquer lugar.
 *
 * Só os ONZE. O banco não entra: não jogou a partida que está sendo narrada,
 * e a tela já é longa.
 *
 * Espera $r (com nome, forca, escudo, formacao, time e adv[...]).
 */

if (empty($r['time']) && empty($r['adv']['time'])) return;

/** Em que setor cada posição joga — a ordem é a de uma escalação lida. */
$dfeSetor = static function (string $pos): int {
    if ($pos === 'GOL') return 0;
    if (in_array($pos, ['ZAG', 'LE', 'LD', 'LAT', 'ALE', 'ALD'], true)) return 1;
    if (in_array($pos, ['VOL', 'MEI', 'ME'], true)) return 2;
    return 3;
};
$dfeRotulo = ['Goleiro', 'Defesa', 'Meio', 'Ataque'];

/**
 * Os onze de um lado, agrupados por setor.
 *
 * A formação manda na posição de cada vaga, e é por ela que o rótulo da
 * carta sai certo (um ZAG na vaga de LD aparece como LD, torto, igual ao
 * campinho). Quando a formação não veio — o time da máquina guarda a dele,
 * mas um resultado antigo pode não ter —, cai na posição natural da carta.
 */
$dfeOnze = static function (?array $time, ?string $formacao) use ($dfeSetor): array {
    if (!$time) return [];
    $setores = [[], [], [], []];
    for ($i = 0; $i < DFUT_VAGAS; $i++) {
        $c = $time[$i] ?? null;
        if (!$c) continue;
        $nat = $formacao ? draftFutPosDaVaga($formacao, $i) : (string)$c['pos'];
        $rot = $formacao ? draftFutRotuloDaVaga($formacao, $i) : (string)$c['pos'];
        $setores[$dfeSetor($nat)][] = ['c' => $c, 'rot' => $rot, 'fora' => $c['pos'] !== $nat];
    }
    return $setores;
};

$dfeLados = [
    ['nome'   => (string)($r['nome'] ?? 'Seu time'),
     'forca'  => (int)($r['forca'] ?? 0),
     'escudo' => (string)($r['escudo'] ?? ''),
     'onze'   => $dfeOnze($r['time'] ?? null, $r['formacao'] ?? null)],
    ['nome'   => (string)($r['adv']['nome'] ?? 'Adversário'),
     'forca'  => (int)($r['adv']['forca'] ?? 0),
     'escudo' => (string)($r['adv']['escudo'] ?? ''),
     'onze'   => $dfeOnze($r['adv']['time'] ?? null, $r['adv']['formacao'] ?? null)],
];
?>
<div class="bloco">
  <h2>As duas escalações</h2>
  <p class="sub">Quem entrou em campo dos dois lados. É destes onze que saem os
     nomes da narração.</p>
  <div class="escalacoes">
    <?php foreach ($dfeLados as $lado): ?>
      <div class="esc-lado">
        <div class="esc-topo">
          <?php if ($lado['escudo'] !== ''): ?>
            <img class="esc-esc" src="<?= e($lado['escudo']) ?>" alt="" loading="lazy"
                 onerror="this.style.display='none'">
          <?php endif; ?>
          <b><?= e($lado['nome']) ?></b>
          <?php if ($lado['forca'] > 0): ?><small>força <?= $lado['forca'] ?></small><?php endif; ?>
        </div>

        <?php if (!array_filter($lado['onze'])): ?>
          <p class="sub" style="margin:0">Escalação não guardada nesta partida.</p>
        <?php else: ?>
          <?php foreach ($lado['onze'] as $s => $jogadores): ?>
            <?php if (!$jogadores) continue; ?>
            <div class="esc-setor"><?= e($dfeRotulo[$s]) ?></div>
            <div class="esc-cartas">
              <?php foreach ($jogadores as $j): ?>
                <span class="esc-carta"><?= dfutCartaHtml($j['c'], ['mini' => true,
                      'rotulo' => $j['rot'], 'fora' => $j['fora']]) ?></span>
              <?php endforeach; ?>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
</div>
