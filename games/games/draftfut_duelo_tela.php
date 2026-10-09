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
  /* O resultado e lido aqui porque o bloco do placar precisa saber se houve
     narracao pra decidir se ja pode aparecer. */
  $p = json_decode((string)$dl['resultado'], true);
  $meusGols  = (int)$dl['gols_' . $meuLado];
  $deleGols  = (int)$dl['gols_' . $outroLado];
  $venci     = $dl['id_vencedor'] !== null && (int)$dl['id_vencedor'] === $user_id;
  $empatou   = $dl['id_vencedor'] === null;
  ?>
  <?php
  /* ── O PLACAR NÃO PODE CHEGAR ANTES DO JOGO ───────────────────────
     Pedido do Marcos (08/10/2026): "o resultado ta aparecendo antes do jogo
     simular". E aparecia mesmo: o quadro de "Você perdeu o duelo 0 x 2"
     ficava no topo enquanto a narração ia no 42'. Era ler a última página
     antes do livro — o mesmo erro que o placar da partida já tinha corrigido
     quando passou a andar com os lances.

     Agora este bloco nasce escondido e o mesmo JavaScript que revela os
     botões do fim revela ele (@see draftfut_narracao.php). Quando NÃO há
     narração — um W.O., em que partida não houve —, aparece de cara, porque
     aí não há o que esperar. */
  $temNarracao = is_array($p ?? null) && !empty($p['lances']);
  ?>
  <div class="bloco" id="dueloFimCaixa"<?= $temNarracao ? ' style="display:none"' : '' ?>>
    <div class="duelo-fim <?= $venci ? 'ganhou' : ($empatou ? 'empatou' : 'perdeu') ?>">
      <span class="df-rot"><?= $venci ? 'Você ganhou o duelo' : ($empatou ? 'Empate' : 'Você perdeu o duelo') ?></span>
      <span class="df-placar"><?= $meusGols ?> <i>x</i> <?= $deleGols ?></span>
      <?php /* ── DECIDIU NOS PÊNALTIS ────────────────────────────────
           O placar grande continua sendo o do tempo normal, porque foi ele
           que aconteceu em campo: 1x1 nos pênaltis é 1x1. Quem decidiu vem
           escrito embaixo, senão o quadro diz "Você ganhou o duelo 1 x 1" e
           não explica como. */
        $dfPen = (is_array($p ?? null) && !empty($p['penaltis'])) ? $p['penaltis'] : null;
        if ($dfPen):
          /* O motor joga com o criador em casa; a vista é de quem olha. */
          $meusPen = $souCriador ? (int)$dfPen[0] : (int)$dfPen[1];
          $delePen = $souCriador ? (int)$dfPen[1] : (int)$dfPen[0]; ?>
        <span class="df-pen"><?= $meusPen ?> x <?= $delePen ?> nos pênaltis</span>
      <?php endif; ?>
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
  if (is_array($p) && !empty($p['lances'])):
      /* O criador é sempre a casa no motor; quem está do outro lado vê o
         mesmo jogo com os nomes nos lugares certos. */
      /* OS LADOS SÃO FIXOS, e não relativos a quem olha: o motor jogou com
         o criador em casa e a narração conta a partida nessa ordem, então as
         escalações têm que seguir a mesma. */
      $draftCasa = dfdLerDraft($dl, 'criador');
      $draftFora = dfdLerDraft($dl, 'desafiado');
      $r = [
          'partida' => $p,
          'nome'    => (string)($dl['nome_criador'] ?: 'Criador'),
          'forca'   => (int)$dl['forca_criador'],
          'quimica' => (int)$dl['quimica_criador'],
          'modo'    => 'pvp',
          /* A CASA É SEMPRE O CRIADOR, e por isso o escudo, o nome e a
             escalação deste lado são os dele — não os de quem está olhando.
             Com o escudo de $user_id aqui, o desafiado via o nome do criador
             com o próprio escudo ao lado. */
          'escudo'  => dfdEscudoDoTime($pdo, (int)$dl['id_criador']),
          /* Os dois drafts ficam guardados na linha do duelo (@see
             dfdSalvarDraft), e é de lá que saem as escalações da tela. */
          'formacao' => (string)($draftCasa['formacao'] ?? ''),
          'time'     => $draftCasa['time'] ?? null,
          'premio'  => $venci ? (int)$dl['aposta'] * 2 : 0,
          'adv'     => ['nome' => (string)($dl['nome_desafiado'] ?: 'Desafiado'),
                        'forca' => (int)$dl['forca_desafiado'],
                        'escudo' => dfdEscudoDoTime($pdo, (int)$dl['id_desafiado']),
                        'formacao' => (string)($draftFora['formacao'] ?? ''),
                        'time' => $draftFora['time'] ?? null],
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
     prefere. Desde 09/10/2026 o link ENTRA SOZINHO: quem abre já está no
     duelo, com a aposta debitada, e pode sair sem custo enquanto nenhuma
     carta foi aberta (@see draftfut.php, "O LINK ENTRA SOZINHO"). */
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
    <?php
    /* ── QUANTAS CARTAS FALTAM, DE CADA LADO ──────────────────────────
       Pedido do Marcos (08/10/2026): "mostre algo sinalizando quantas cartas
       falta o adversario abrir". "Montando" não dizia nada: podia ser alguém
       que abriu o jogo e saiu, ou alguém que está na última vaga. Quem está
       esperando precisa saber se vale a pena ficar na página.

       O número é real, não estimativa: o draft do duelo é gravado a cada
       carta escolhida (@see dfdSalvarDraft), então o que está no banco é
       exatamente onde a pessoa parou. */
    $dlConta = static function (?array $draft): int {
        if (!$draft || empty($draft['time'])) return 0;
        $n = 0;
        foreach ($draft['time'] as $c) if ($c) $n++;
        return $n;
    };
    $meuFeito  = $euPronto  ? DFUT_TOTAL : $dlConta(dfdLerDraft($dl, $meuLado));
    $eleFeito  = $elePronto ? DFUT_TOTAL : $dlConta(dfdLerDraft($dl, $outroLado));
    $dlFaltam  = static fn(int $feito) => max(0, DFUT_TOTAL - $feito);
    ?>
    <div class="duelo-lados">
      <div class="dlado <?= $euPronto ? 'ok' : '' ?>">
        <b><?= e($meuNome) ?></b>
        <small><?= $euPronto
            ? 'pronto · força ' . (int)$dl['forca_' . $meuLado]
            : $meuFeito . ' de ' . DFUT_TOTAL . ' cartas' ?></small>
        <?php if (!$euPronto): ?>
          <span class="dbarra"><i style="width:<?= (int)round($meuFeito / DFUT_TOTAL * 100) ?>%"></i></span>
        <?php endif; ?>
      </div>
      <span class="dvs">x</span>
      <div class="dlado <?= $elePronto ? 'ok' : '' ?>" id="dueloEle">
        <b><?= e($eleNome) ?></b>
        <small><?= $elePronto ? 'pronto' : $eleFeito . ' de ' . DFUT_TOTAL . ' cartas' ?></small>
        <?php if (!$elePronto): ?>
          <span class="dbarra"><i style="width:<?= (int)round($eleFeito / DFUT_TOTAL * 100) ?>%"></i></span>
        <?php endif; ?>
      </div>
    </div>
    <?php if ($euPronto && !$elePronto): ?>
      <p class="sub" style="margin:12px 0 0">Seu time está guardado.
        <?php if ($eleFeito === 0): ?>
          O adversário ainda não abriu nenhuma carta.
        <?php else: ?>
          Faltam <b><?= $dlFaltam($eleFeito) ?></b> cartas pra ele terminar.
        <?php endif; ?>
        Quando ele fechar, a partida roda e aparece aqui — pode fechar a página.</p>
    <?php elseif (!$euPronto && $elePronto): ?>
      <p class="sub" style="margin:12px 0 0">O adversário já fechou o time dele e está
         esperando. Faltam <b><?= $dlFaltam($meuFeito) ?></b> cartas pra você.</p>
    <?php elseif (!$euPronto && !$elePronto && $eleFeito > 0): ?>
      <p class="sub" style="margin:12px 0 0">Os dois ainda estão montando — faltam
         <b><?= $dlFaltam($eleFeito) ?></b> cartas pra ele e
         <b><?= $dlFaltam($meuFeito) ?></b> pra você.</p>
    <?php endif; ?>
    <p class="sub dpulso" id="dueloPulso" style="margin:10px 0 0;display:none">
      <i class="bi bi-arrow-repeat"></i> acompanhando o adversário…</p>
  </div>

<?php endif; ?>

<?php if ($dl['status'] !== 'concluido'): ?>
<script>
/* ── A ESPERA DEIXA DE SER UMA FOTO ──────────────────────────────────
   O Vinícius fechou o time, ficou na tela e nunca viu o jogo: a partida
   roda quando alguém carrega a página, e ele não carregava mais nada.
   Agora a tela pergunta de cinco em cinco segundos.

   A CONTAGEM ANDA SEM RECARREGAR, e a página só volta inteira quando o
   assunto muda de verdade (a partida saiu, alguém entrou, alguém saiu) —
   recarregar a cada cinco segundos tiraria o texto de baixo do dedo de
   quem está lendo, e no celular tiraria a rolagem do lugar.

   O intervalo PARA quando a aba sai de foco: ninguém precisa de atualização
   de uma tela que não está sendo vista, e deixar rodando é bateria do
   celular de graça. Ao voltar, pergunta na hora. */
(function () {
  /* A TELA DIZ EM QUE FASE ELA FOI DESENHADA. Sem isso, o criador que
     esperava adversário ficava preso: alguém entrava, o servidor
     respondia "montando" e a tela do código continuava na frente, sem
     nada pra mostrar que o duelo já tinha dois. */
  const url = '?estado=1&v=multi&fase=<?= $dl['id_desafiado'] === null ? 'aguardando' : 'montando' ?>';
  const pulso = document.getElementById('dueloPulso');
  const alvoEle = document.getElementById('dueloEle');
  let timer = null, parado = false;

  if (pulso) pulso.style.display = '';

  async function olhar() {
    if (parado) return;
    let j;
    try {
      const r = await fetch(url, {headers: {'Accept': 'application/json'}});
      if (!r.ok) return;
      j = await r.json();
    } catch (e) {
      /* Internet oscilou: não faz nada e tenta no próximo. Avisar de
         falha de rede numa tela de espera só assusta. */
      return;
    }
    if (!j) return;

    if (j.recarregar) {
      parado = true;
      clearInterval(timer);
      /* O aviso diz o que mudou. "A partida saiu" num duelo em que alguém
         só acabou de entrar seria mentira de meio segundo, mas mentira. */
      const recado = j.fase === 'fim' ? 'a partida saiu, abrindo…'
                   : (j.fase === 'montando' ? 'seu adversário entrou!'
                   : 'o duelo mudou, abrindo…');
      if (pulso) pulso.innerHTML = '<i class="bi bi-hourglass-split"></i> ' + recado;
      /* Sem o cod na volta: ele já cumpriu o papel e ficaria no endereço
         pra sempre, reaparecendo em cada F5 depois do duelo. */
      window.location.replace('draftfut.php?v=multi');
      return;
    }

    if (j.fase === 'montando' && alvoEle) {
      const feito = Number(j.ele) || 0, total = Number(j.total) || 19;
      alvoEle.querySelector('small').textContent = j.elePronto
        ? 'pronto' : feito + ' de ' + total + ' cartas';
      const barra = alvoEle.querySelector('.dbarra');
      if (j.elePronto) {
        /* Time fechado não tem mais o que encher: a barra sai, como sai na
           versão que o PHP desenha (ela só existe enquanto falta carta). */
        alvoEle.classList.add('ok');
        if (barra) barra.remove();
      } else if (barra) {
        const i = barra.querySelector('i');
        if (i) i.style.width = Math.round(feito / total * 100) + '%';
      }
    }
  }

  function ligar() { clearInterval(timer); timer = setInterval(olhar, 5000); }
  ligar();
  document.addEventListener('visibilitychange', () => {
    if (document.hidden) { clearInterval(timer); }
    else if (!parado) { olhar(); ligar(); }
  });
})();
</script>
<?php endif; ?>
