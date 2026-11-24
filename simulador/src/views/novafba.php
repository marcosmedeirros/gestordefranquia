<?php
/**
 * MINHA TEMPORADA — a escolha do time antes de tudo começar.
 *
 * Duas perguntas e nada mais: qual liga e qual franquia. O resto do jogo é
 * simular. Os times vêm do banco do site na hora, então o que aparece aqui é
 * o elenco de hoje — não uma cópia que envelhece.
 *
 * A LISTA VEM DA MAIS FORTE PRA MAIS FRACA de propósito. É a informação que
 * decide a partida inteira: pegar o último da fila e levantar o título paga
 * muito mais moeda do que confirmar o favorito, e quem escolhe precisa ver
 * isso antes de escolher, não depois.
 */
require_once dirname(__DIR__) . '/helpers.php';
require_once dirname(__DIR__) . '/Accounts.php';
require_once dirname(__DIR__) . '/InstallerFBA.php';

$uid   = Accounts::userId();
$free  = Accounts::freeSlots($uid);
$liga  = strtoupper(trim((string) ($_GET['liga'] ?? '')));
if (!in_array($liga, InstallerFBA::LIGAS, true)) $liga = '';
$erro  = trim((string) ($_GET['err'] ?? ''));

$times = $liga ? InstallerFBA::timesDaLiga($liga) : [];

/* A força de cada time na escala da própria liga: 100 é o melhor elenco, 0 é
   o pior. Não é o /power do site — lá entram campeão, vice e idade —, mas é a
   mesma pergunta respondida com o que o jogo usa pra simular: OVR. */
$ovrs = array_map(fn($t) => (float) $t['ovr_medio'], $times);
$piso = $ovrs ? min($ovrs) : 0;
$topo = $ovrs ? max($ovrs) : 1;

render_lobby_header('Minha Temporada', ['page' => 'saves', 'home' => url('saves')]);
page_head('Minha Temporada', [
    'eyebrow' => 'FBA',
    'sub' => 'Escolha um time da FBA e simule a temporada inteira — os 82 jogos, a corrida pelos prêmios e os playoffs. Elencos como estão hoje no site.',
    'actions' => '<a class="btn" href="' . url('saves') . '">' . bi('arrow-left') . 'Meus saves</a>',
]);

if (!$free): ?>
  <section class="panel">
    <p class="dim">Seus dois slots de save estão ocupados. Apague um em <a href="<?= url('saves') ?>">Meus saves</a> pra começar outra temporada.</p>
  </section>
<?php render_footer(); return; endif; ?>

<?php if ($erro): ?>
  <section class="panel" style="border-color:#b91c1c">
    <p><strong>Não deu pra começar:</strong> <?= e($erro) ?></p>
  </section>
<?php endif; ?>

<section class="panel">
  <?= panel_head('1. A liga', ['icon' => 'trophy-fill']) ?>
  <div class="race">
    <?php foreach (InstallerFBA::LIGAS as $l): ?>
      <a class="race-col" style="<?= $l === $liga ? 'outline:2px solid var(--team);' : '' ?>"
         href="<?= url('novafba', ['liga' => $l]) ?>">
        <div class="sub-h"><?= e($l) ?></div>
        <p class="dim">
          <?php if ($l === 'ELITE'): ?>A divisão de cima: 32 times, os melhores elencos da FBA.
          <?php elseif ($l === 'NEXT'): ?>30 times, um degrau abaixo da ELITE.
          <?php elseif ($l === 'RISE'): ?>30 times em formação.
          <?php else: ?>30 times, a porta de entrada — elencos mais jovens e mais parelhos.
          <?php endif; ?>
        </p>
      </a>
    <?php endforeach; ?>
  </div>
</section>

<?php if (!$liga): ?>
  <section class="panel"><p class="dim">Escolha uma liga acima pra ver os times.</p></section>
<?php else: ?>

<form method="post" action="index.php?action=create-save-fba">
  <input type="hidden" name="liga" value="<?= e($liga) ?>">
  <input type="hidden" name="slot" value="<?= (int) $free[0] ?>">

  <section class="panel">
    <?= panel_head('2. O time', ['icon' => 'people-fill', 'meta' => 'Do elenco mais forte pro mais fraco']) ?>
    <div class="slots">
      <?php foreach ($times as $i => $t):
        $nome  = trim((string) $t['city'] . ' ' . (string) $t['name']);
        $media = (float) $t['ovr_medio'];
        $forca = $topo > $piso ? (int) round(($media - $piso) / ($topo - $piso) * 100) : 50;
        $cor   = preg_match('/^#[0-9a-f]{6}$/i', trim((string) $t['cor'])) ? trim((string) $t['cor']) : '#334155';
        $logo  = trim((string) $t['photo_url']);
      ?>
      <label class="slot" style="<?= e(team_theme_style(['primary_color' => $cor, 'secondary_color' => '#e2e8f0'])) ?>">
        <input type="radio" name="fba_team_id" value="<?= (int) $t['id'] ?>"
               <?= $i === 0 ? 'required' : '' ?> style="position:absolute;opacity:0">
        <div class="slot-top">
          <?php if ($logo): ?>
            <img src="<?= e($logo) ?>" alt="" width="56" height="56"
                 style="border-radius:12px;object-fit:cover;background:<?= e($cor) ?>">
          <?php else: ?>
            <?= team_logo(strtoupper(substr((string) $t['city'], 0, 3)), $cor, 'lg') ?>
          <?php endif; ?>
          <div class="slot-id">
            <span class="eyebrow"><?= strtoupper((string) $t['conference']) === 'LESTE' ? 'Leste' : 'Oeste' ?></span>
            <b><?= e($nome) ?></b>
            <small>
              OVR médio <?= number_format($media, 1, ',', '.') ?> ·
              teto <?= (int) $t['teto'] ?> ·
              <?= (int) $t['jogadores'] ?> jogadores
            </small>
          </div>
        </div>
        <div class="slot-foot">
          <span class="eyebrow">Força na liga</span>
          <div style="height:8px;border-radius:99px;background:#1e293b;overflow:hidden">
            <div style="height:100%;width:<?= max(3, $forca) ?>%;background:var(--team-bright,<?= e($cor) ?>)"></div>
          </div>
          <small class="dim">
            <?php if ($forca >= 80): ?>Favorito. Título paga pouco.
            <?php elseif ($forca >= 50): ?>Briga pelos playoffs.
            <?php elseif ($forca >= 25): ?>Azarão. Título paga bem.
            <?php else: ?>Pior da liga. Título paga o máximo.
            <?php endif; ?>
          </small>
        </div>
      </label>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="panel">
    <?= panel_head('3. Seu nome', ['icon' => 'person-badge']) ?>
    <p>
      <label class="eyebrow" for="gm_name">Como você quer ser chamado</label><br>
      <input id="gm_name" name="gm_name" maxlength="40" placeholder="GM" class="inp">
    </p>
    <p><button class="btn btn-team btn-lg" type="submit"><?= bi('play-fill') ?>Começar a temporada</button></p>
    <p class="dim">
      Monta a liga inteira com os elencos de agora e o calendário de 82 jogos. Leva alguns segundos.
    </p>
  </section>
</form>

<style>
/* O cartão inteiro é o botão: o rádio fica escondido e quem marca é a borda. */
.slot:has(input[type=radio]) { cursor: pointer; }
.slot:has(input[type=radio]:checked) { outline: 2px solid var(--team-bright, #38bdf8); }
</style>
<?php endif; ?>
<?php render_footer(); ?>
