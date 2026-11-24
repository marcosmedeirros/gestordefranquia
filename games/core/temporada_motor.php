<?php
/**
 * A PONTE ENTRE O MINHA TEMPORADA E O MOTOR DO SIMULADOR.
 *
 * O Minha Temporada é um jogo do /games, com tela própria, card próprio e
 * save próprio. O que ele NÃO tem é motor de basquete — e não vai ter, porque
 * já existe um pronto e testado em /simulador: simulação por posses, box
 * score, classificação, play-in, playoffs, líderes e prêmios.
 *
 * Então este arquivo é só isto: carrega as classes de lá e não leva nada do
 * resto junto. Nenhuma tela do simulador é usada, nenhuma rota dele é
 * chamada, e o save daqui não aparece no "Meus saves" de lá. A fronteira é
 * esta linha — daqui pra baixo é o jogo do /games.
 *
 * APP_BASE É DEFINIDO ANTES porque o helpers.php do simulador o deduz do
 * endereço pedido, e o endereço pedido aqui é /games/games/temporada.php.
 * Sem isto, as fotos geradas dos jogadores apontariam pra
 * /games/games/face.php, que não existe.
 */

if (!defined('APP_BASE')) define('APP_BASE', '/games/simulador');

$__sim = dirname(__DIR__, 2) . '/simulador/src';

require_once $__sim . '/Database.php';
require_once $__sim . '/helpers.php';   // url(), e(), team_logo(), player_photo()
require_once $__sim . '/League.php';    // classificação, playoffs, líderes, prêmios
require_once $__sim . '/SimEngine.php'; // o jogo em si, posse a posse
require_once $__sim . '/Installer.php'; // só pelo calendário (buildSchedule)

unset($__sim);
