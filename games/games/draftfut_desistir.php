<?php
/**
 * O BOTÃO DE DESISTIR.
 *
 * Fica embaixo do campinho e discreto de propósito: é saída, não ação. Colado
 * nos botões de jogar, viraria clique errado na hora em que a pessoa mais tem
 * pressa.
 *
 * A pergunta muda com a situação, porque a consequência muda — e a pessoa
 * precisa saber qual é ANTES de confirmar, não depois.
 *
 * Espera $duelo e $user_id.
 */

/* Qual ação o botão dispara. Só o duelo que ainda não começou usa outra. */
$acaoSaida = 'desistir';

$texto = 'Desistir deste draft e voltar pra tela inicial? A entrada de '
       . DF_ENTRADA . ' moedas não volta.';
$rotulo = 'Desistir do draft';

if (!empty($duelo)) {
    $meuLado = dfdLado($duelo, $user_id);
    if ((int)$duelo['pronto_' . $meuLado]) {
        /* Time fechado: não há o que desistir, a partida já vai sair. */
        return;
    }
    if ($duelo['id_desafiado'] === null) {
        /* ── DUELO ABERTO E SEM NINGUÉM: DEPENDE DE ONDE A PESSOA ESTÁ ──
           Com o quadro do duelo na tela (quando ainda não se começou a
           montar), ele já traz o "Cancelar duelo" logo abaixo do código —
           repetir aqui punha dois botões iguais na mesma tela.

           MAS QUEM JÁ COMEÇOU A MONTAR não vê aquele quadro: a tela vira o
           pacote de cartas e o campo, e este arquivo era a única saída —
           que se escondia justamente aí. Resultado: duelo aberto, ninguém
           entrou, time montado e NENHUM botão pra sair. O Kleber ficou preso
           assim em 14/10/2026, com 2.000 moedas presas no duelo: "to travado
           sempre na mesma seleção de draft, independente de qual link eu
           acesse" — e era verdade, porque o duelo faz o draft aparecer em
           qualquer endereço. */
        if (empty($d)) return;
        $texto = 'Cancelar este duelo? Ninguém entrou ainda, então as '
               . (int)$duelo['aposta'] . ' moedas voltam pra você e o draft é desfeito.';
        $rotulo = 'Cancelar o duelo';
        $acaoSaida = 'duelo_cancelar';

    } elseif (dfdDueloVirgem($duelo)) {
        /* NINGUÉM ABRIU CARTA AINDA, então não há partida pra abandonar e
           sair não custa nada — a aposta volta pros dois. É o par da entrada
           automática pelo link (09/10/2026): se dá pra entrar com um toque,
           tem que dar pra sair do mesmo jeito enquanto o jogo não começou.
           @see dfdSairSemCusto */
        $texto  = 'Sair deste duelo? Ninguém abriu carta ainda, então a aposta de '
                . (int)$duelo['aposta'] . ' moedas volta pros dois.';
        $rotulo = 'Sair do duelo';
        $acaoSaida = 'duelo_sair';
    } else {
        $texto  = 'Desistir do duelo? Seu adversário já está dentro, então ele leva as '
                . 'duas apostas — você perde as ' . (int)$duelo['aposta'] . ' moedas.';
        $rotulo = 'Desistir do duelo';
    }
}
?>
<form method="POST" class="desistir"
      data-confirmar="<?= e($texto) ?>"
      data-confirmar-ok="<?= e($rotulo) ?>" data-confirmar-perigo="1">
  <input type="hidden" name="acao" value="<?= e($acaoSaida ?? 'desistir') ?>">
  <button type="submit"><i class="bi bi-x-circle"></i> <?= e($rotulo) ?></button>
</form>
