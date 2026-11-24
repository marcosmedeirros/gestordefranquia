<?php
/**
 * O BOX SCORE DE UMA PARTIDA, em pop-up.
 *
 * Um pedaço de tela usado por dois caminhos que terminam no mesmo lugar: quem
 * ASSISTIU ao jogo o vê abrir quando o relógio zera, e quem mandou SIMULAR cai
 * nele direto. Duplicar esta tabela nos dois lugares era garantir que um dia
 * ela mudaria só num deles.
 *
 * Espera, de quem inclui:
 *   $boxJogo         o que temporadaJogoAoVivo() devolveu (jogo, times, box)
 *   $boxAbreSozinho  true pra já nascer aberto (caminho do "simular")
 *   $boxVoltar       href do botão de sair, ou null pra só fechar
 *   $cor             o validador de cor da página
 */

$bj   = $boxJogo['jogo'];
$btc  = $boxJogo['times'];
$bCasa = (int) $bj['home_id'];
$bFora = (int) $bj['away_id'];

$bPorTime = [];
foreach ($boxJogo['box'] as $b) $bPorTime[(int) $b['team_id']][] = $b;

$bVenceuCasa = (int) $bj['home_pts'] > (int) $bj['away_pts'];
?>
<div class="modal-fundo<?= !empty($boxAbreSozinho) ? ' aberto' : '' ?>" id="modalBox">
  <div class="modal" style="max-width:620px">
    <button class="modal-x" id="fecharBox" aria-label="Fechar"><i class="bi bi-x-lg"></i></button>

    <div class="box-placar" style="--cA:<?= e($cor($btc[$bFora]['primary_color'] ?? '', '#64748b')) ?>;
                                   --cB:<?= e($cor($btc[$bCasa]['primary_color'] ?? '', '#3b82f6')) ?>">
      <?php foreach ([[$bFora, !$bVenceuCasa], [$bCasa, $bVenceuCasa]] as $idx => [$tid, $venceu]):
        if ($idx === 1): ?><span class="bx">×</span><?php endif; ?>
        <div class="bt <?= $venceu ? 'venceu' : '' ?>">
          <?php $l = $btc[$tid]['logo_url'] ?? ''; ?>
          <?php if ($l): ?><img src="<?= e(temporadaEscudo($l, 48)) ?>" alt="" width="48" height="48" decoding="async">
          <?php else: ?><span class="es" style="background:<?= e($cor($btc[$tid]['primary_color'] ?? '')) ?>"><?= e($btc[$tid]['abbr'] ?? '') ?></span><?php endif; ?>
          <b><?= e(trim(($btc[$tid]['city'] ?? '') . ' ' . ($btc[$tid]['name'] ?? ''))) ?></b>
          <span class="pts"><?= (int) ($tid === $bCasa ? $bj['home_pts'] : $bj['away_pts']) ?></span>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="modal-corpo" style="max-height:62vh;overflow:auto">
      <?php foreach ([$bFora, $bCasa] as $tid): ?>
        <b class="bloco-rot" style="display:flex;align-items:center;gap:8px">
          <?php $l = $btc[$tid]['logo_url'] ?? ''; ?>
          <?php if ($l): ?><img src="<?= e(temporadaEscudo($l, 20)) ?>" alt="" width="20" height="20" loading="lazy" style="width:20px;height:20px;border-radius:6px;object-fit:cover"><?php endif; ?>
          <?= e(trim(($btc[$tid]['city'] ?? '') . ' ' . ($btc[$tid]['name'] ?? ''))) ?>
        </b>
        <table>
          <thead><tr><th></th><th class="num2">MIN</th><th class="num2">PTS</th>
            <th class="num2">REB</th><th class="num2">AST</th><th class="num2">FG</th><th class="num2">3PT</th></tr></thead>
          <tbody>
          <?php foreach ($bPorTime[$tid] ?? [] as $b): ?>
            <tr>
              <td><?= e($b['name']) ?> <small style="color:var(--txt3)"><?= e($b['pos']) ?></small></td>
              <td class="num2"><?= (int) $b['min'] ?></td>
              <td class="num2"><b><?= (int) $b['pts'] ?></b></td>
              <td class="num2"><?= (int) $b['reb'] ?></td>
              <td class="num2"><?= (int) $b['ast'] ?></td>
              <td class="num2"><?= (int) $b['fgm'] ?>/<?= (int) $b['fga'] ?></td>
              <td class="num2"><?= (int) $b['tpm'] ?>/<?= (int) $b['tpa'] ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endforeach; ?>

      <div class="linha-btns" style="margin-top:16px">
        <form method="post" style="flex:1">
          <button class="btn btn-fba" name="acao" value="proximo" style="width:100%">
            <i class="bi bi-play-circle-fill"></i> Assistir ao próximo
          </button>
        </form>
        <form method="post" style="flex:1">
          <button class="btn" name="acao" value="simular" style="width:100%">
            <i class="bi bi-fast-forward-fill"></i> Simular o próximo
          </button>
        </form>
      </div>
      <?php if ($boxVoltar !== null): ?>
        <a class="btn btn-fraco" href="<?= e($boxVoltar) ?>" style="width:100%;margin-top:9px">Voltar à temporada</a>
      <?php else: ?>
        <button class="btn btn-fraco" id="fecharBox2" style="width:100%;margin-top:9px">Fechar</button>
      <?php endif; ?>
    </div>
  </div>
</div>
<script>
(() => {
  const mb = document.getElementById('modalBox');
  const fechar = () => mb.classList.remove('aberto');
  document.getElementById('fecharBox').onclick = fechar;
  document.getElementById('fecharBox2')?.addEventListener('click', fechar);
  mb.addEventListener('click', ev => { if (ev.target === mb) fechar(); });
  document.addEventListener('keydown', ev => { if (ev.key === 'Escape') fechar(); });
  window.abrirBoxScore = () => mb.classList.add('aberto');
})();
</script>
