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
        /* O bloco "Duelo aberto" já tem o botão de cancelar, logo abaixo do
           código. Repetir aqui embaixo punha dois "Cancelar duelo" na mesma
           tela, um deles longe do que explica a consequência. */
        return;
    }
    {
        $texto  = 'Desistir do duelo? Seu adversário já está dentro, então ele leva as '
                . 'duas apostas — você perde as ' . (int)$duelo['aposta'] . ' moedas.';
        $rotulo = 'Desistir do duelo';
    }
}
?>
<form method="POST" class="desistir"
      data-confirmar="<?= e($texto) ?>"
      data-confirmar-ok="<?= e($rotulo) ?>" data-confirmar-perigo="1">
  <input type="hidden" name="acao" value="desistir">
  <button type="submit"><i class="bi bi-x-circle"></i> <?= e($rotulo) ?></button>
</form>
