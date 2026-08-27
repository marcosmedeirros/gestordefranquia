<?php
/**
 * A LOTERIA EM MODO DE ENSAIO.
 *
 * Mesma tela da loteria de verdade, sorteando de verdade — e sem que nada
 * disso conte. O sorteio já não escrevia no banco; quem aplica a ordem ao
 * draft é o "Confirmar", que aqui não existe. Então a cerimônia inteira
 * acontece dentro do navegador de quem abriu a página e some quando ele
 * fecha a aba.
 *
 * Serve pra duas coisas: ensaiar a transmissão antes do dia, com a ordem e
 * os grupos que se quiser experimentar, e deixar qualquer GM entender o
 * modelo mexendo nele em vez de lendo a regra.
 *
 * O arquivo existe só pra dar um endereço limpo pra isso — quem monta a
 * tela é o lottery.php, para que o ensaio nunca fique diferente do que vai
 * ao ar. Duas telas parecidas envelhecem em direções diferentes.
 */
$MODO_TESTE_LOTERIA = true;
require __DIR__ . '/lottery.php';
