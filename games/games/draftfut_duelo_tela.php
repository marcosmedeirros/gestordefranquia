<?php
/**
 * ── A TELA DO DUELO ──────────────────────────────────────────────────
 *
 * Três momentos, e cada um responde uma pergunta diferente:
 *   · abriu e ninguém entrou  -> "qual é o código?"
 *   · os dois dentro          -> "falta eu ou falta ele?"
 *   · terminou                -> "quem ganhou?"
 *
 * A narração do duelo reaproveita o mesmo bloco da partida contra o bot
 * (@see draftfut.php), porque é a mesma partida vista do mesmo motor — duas
 * telas divergiriam na primeira mudança.
 *
 * Espera $duelo ou $dueloFim, e $user_id.
 */

$dl = $duelo ?? $dueloFim;
if (!$dl) return;

$souCriador = dfdSouCriador($dl, $user_id);
$meuLado    = $souCriador ? 'criador' : 'desafiado';
$outroLado  = $souCriador ? 'desafiado' : 'criador';
$euPronto   = (int)$dl['pronto_' . $meuLado] === 1;
$elePronto  = (int)$dl['pronto_' . $outroLado] === 1;
$meuNome    = (string)($dl['nome_' . $meuLado] ?: 'Seu time');
$eleNome    = (string)($dl['nome_' . $outroLado] ?: 'Adversário');
?>

<?php if ($dl['status'] === 'concluido'): ?>

  <?php /* ── TERMINOU ──────────────────────────────────────────────── */ ?>
  <?php
  $meusGols  = (int)$dl['gols_' . $meuLado];
  $deleGols  = (int)$dl['gols_' . $outroLado];
  $venci     = $dl['id_vencedor'] !== null && (int)$dl['id_vencedor'] === $user_id;
  $empatou   = $dl['id_vencedor'] === null;
  ?>
  <div class="bloco">
    <div class="duelo-fim <?= $venci ? 'ganhou' : ($empatou ? 'empatou' : 'perdeu') ?>">
      <span class="df-rot"><?= $venci ? 'Você ganhou o duelo' : ($empatou ? 'Empate' : 'Você perdeu o duelo') ?></span>
      <span class="df-placar"><?= $meusGols ?> <i>x</i> <?= $deleGols ?></span>
      <span class="df-times"><?= e($meuNome) ?> <i>contra</i> <?= e($eleNome) ?></span>
      <span class="df-premio">
        <?php if ($venci): ?>
          +<?= (int)$dl['aposta'] * 2 ?> moedas
        <?php elseif ($empatou): ?>
          aposta devolvida (<?= (int)$dl['aposta'] ?>)
        <?php else: ?>
          perdeu <?= (int)$dl['aposta'] ?> moedas
        <?php endif; ?>
      </span>
    </div>
    <form method="POST" style="margin-top:14px">
      <input type="hidden" name="acao" value="duelo_fechar">
      <button class="btn pri" type="submit"><i class="bi bi-check2"></i> Fechar</button>
    </form>
  </div>

  <?php
  /* A NARRAÇÃO DO DUELO, no mesmo formato da do bot. $r é o que o bloco de
     partida da página espera; montá-lo aqui é o que permite reaproveitar
     aquele JavaScript do relógio sem copiar nada. */
  $p = json_decode((string)$dl['resultado'], true);
  if (is_array($p) && !empty($p['lances'])):
      /* O criador é sempre a casa no motor; quem está do outro lado vê o
         mesmo jogo com os nomes nos lugares certos. */
      $r = [
          'partida' => $p,
          'nome'    => (string)($dl['nome_criador'] ?: 'Criador'),
          'forca'   => (int)$dl['forca_criador'],
          'quimica' => (int)$dl['quimica_criador'],
          'modo'    => 'pvp',
          /* No duelo os dois lados são franquias da liga, então os dois
             escudos saem da mesma fonte — e cada um vê o seu à esquerda. */
          'escudo'  => dfdEscudoDoTime($pdo, $user_id),
          'premio'  => $venci ? (int)$dl['aposta'] * 2 : 0,
          'adv'     => ['nome' => (string)($dl['nome_desafiado'] ?: 'Desafiado'),
                        'forca' => (int)$dl['forca_desafiado'],
                        'escudo' => dfdEscudoDoTime($pdo, (int)$dl['id_desafiado'])],
          /* A REVANCHE SÓ EXISTE AQUI, no duelo: contra o bot não há com quem
             jogar de novo — é só abrir outro draft. `chamou` conta se o
             adversário já clicou, pro botão dizer "aceitar" em vez de pedir
             uma coisa que já está pedida. */
          'revanche' => ['duelo'  => (int)$dl['id'],
                         'aposta' => (int)$dl['aposta'],
                         'chamou' => !empty($convite)
                                     && (int)$convite['id_criador'] === (int)$dl['id_' . $outroLado]],
      ];
      include __DIR__ . '/draftfut_narracao.php';
  endif;
  ?>

<?php elseif ($dl['id_desafiado'] === null): ?>

  <?php /* ── ABRIU E ESPERA ADVERSÁRIO ─────────────────────────────── */ ?>
  <?php
  /* O LINK QUE JÁ CAI NO DUELO CERTO. Ditar seis letras no grupo funciona,
     mas mandar um link funciona melhor — e o código continua aí pra quem
     prefere. O link NÃO entra sozinho: ele abre a tela com o código posto e
     o adversário confirma, porque entrar custa a aposta e ninguém pode ser
     cobrado por ter clicado num link. */
  $dlEsquema = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
  $dlHost    = (string)($_SERVER['HTTP_HOST'] ?? 'fbabrasil.com.br');
  $dlLink    = $dlEsquema . '://' . $dlHost . '/games/games/draftfut.php?v=multi&cod='
             . rawurlencode((string)$dl['codigo']);
  ?>
  <div class="bloco">
    <h2>Duelo aberto</h2>
    <p class="sub">Manda o link ou o código pro seu adversário. Enquanto ele não entra,
       você já pode montar o seu time — e pode cancelar sem perder nada.</p>
    <div class="codigo-caixa">
      <span class="cod"><?= e((string)$dl['codigo']) ?></span>
      <span class="cod-sub">aposta de <b><?= (int)$dl['aposta'] ?></b> moedas</span>
    </div>
    <div class="duelo-acoes">
      <button class="btn pri" type="button" data-copiar="<?= e($dlLink) ?>">
        <i class="bi bi-link-45deg"></i> Copiar link</button>
      <button class="btn" type="button" data-copiar="<?= e((string)$dl['codigo']) ?>">
        <i class="bi bi-clipboard"></i> Copiar código</button>
      <form method="POST" style="margin:0">
        <input type="hidden" name="acao" value="duelo_cancelar">
        <button class="btn" type="submit" data-confirmar="Cancelar o duelo e receber a aposta de volta?">
          <i class="bi bi-x-lg"></i> Cancelar duelo</button>
      </form>
    </div>
  </div>

<?php else: ?>

  <?php /* ── OS DOIS DENTRO ────────────────────────────────────────── */ ?>
  <div class="bloco">
    <h2>Duelo <?= e((string)$dl['codigo']) ?></h2>
    <p class="sub">Aposta de <b><?= (int)$dl['aposta'] ?></b> moedas. A partida sai quando
       os dois times estiverem prontos.</p>
    <div class="duelo-lados">
      <div class="dlado <?= $euPronto ? 'ok' : '' ?>">
        <b><?= e($meuNome) ?></b>
        <small><?= $euPronto ? 'pronto · força ' . (int)$dl['forca_' . $meuLado] : 'montando' ?></small>
      </div>
      <span class="dvs">x</span>
      <div class="dlado <?= $elePronto ? 'ok' : '' ?>">
        <b><?= e($eleNome) ?></b>
        <small><?= $elePronto ? 'pronto' : 'montando' ?></small>
      </div>
    </div>
    <?php if ($euPronto && !$elePronto): ?>
      <p class="sub" style="margin:12px 0 0">Seu time está guardado. Assim que ele terminar,
         a partida roda e aparece aqui — pode fechar a página.</p>
    <?php endif; ?>
  </div>

<?php endif; ?>
