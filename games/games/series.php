<?php
/**
 * O ENDEREÇO ANTIGO DAS SÉRIES.
 *
 * A tela saiu de /games em 30/09/2026 e virou o Clube FBA (/clube.php): os
 * jogos do Games são partida — entra, joga, sai com um placar — e isto é
 * acervo, que cresce devagar e vale por voltar depois. Junto com as outras
 * mídias (livros, filmes, música) ele pedia casa própria.
 *
 * ESTE ARQUIVO CONTINUA AQUI SÓ PELO LINK QUE JÁ FOI MANDADO. Ele circulou no
 * WhatsApp antes da mudança, e link que morre vira "o jogo sumiu". O 301 diz
 * ao navegador que a mudança é definitiva, então quem tinha salvo troca o
 * favorito sozinho.
 *
 * Passa `aba` e `u` adiante: quem mandou o perfil de alguém cai no perfil.
 *
 * @see clube.php   a tela de verdade
 */

$ida = '/clube.php?midia=series';
foreach (['aba', 'u', 'q', 'cat', 'ord'] as $k) {
    if (isset($_GET[$k]) && $_GET[$k] !== '') {
        $ida .= '&' . $k . '=' . urlencode((string)$_GET[$k]);
    }
}

header('Location: ' . $ida, true, 301);
echo '<!DOCTYPE html><meta charset="utf-8">'
   . '<meta http-equiv="refresh" content="0;url=' . htmlspecialchars($ida, ENT_QUOTES, 'UTF-8') . '">'
   . '<p>As séries agora ficam no <a href="' . htmlspecialchars($ida, ENT_QUOTES, 'UTF-8')
   . '">Clube FBA</a>.</p>';
