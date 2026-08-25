<?php
/**
 * GAMES/INDEX.PHP - CARREGADOR DINÂMICO DE GAMES
 * Carrega dinamicamente os games baseado no parâmetro 'game'
 */

if (session_status() === PHP_SESSION_NONE) session_start();
require '../core/conexao.php';

// Segurança
if (!isset($_SESSION['user_id'])) {
    header("Location: /login.php");
    exit;
}

// Pega qual game vai carregar
$game = isset($_GET['game']) ? sanitize($_GET['game']) : 'flappy';

// Mapa de games disponíveis
$games_disponiveis = [
    'flappy' => [
        'titulo' => '🐦 Flappy Bird',
        'arquivo' => 'flappy.php'
    ],
    'pinguim' => [
        'titulo' => '🐧 Pinguim - Dino Runner',
        'arquivo' => 'pinguim.php'
    ],
    'acerteacesta' => [
        'titulo' => '🏀 O Lance Livre Infinito',
        'arquivo' => 'acerteacesta.php'
    ],
    'xadrez' => [
        'titulo' => '♛ Xadrez',
        'arquivo' => 'xadrez.php'
    ],
    // Só por link: de propósito fora do grid de jogos (games.php). Quem tem o
    // link da sala entra; quem não tem não descobre o jogo passeando pela lista.
    'stop' => [
        'titulo' => '🛑 Stop',
        'arquivo' => 'stop.php'
    ],
    'memoria' => [
        'titulo' => '🧠 Jogo da Memória',
        'arquivo' => 'memoria.php'
    ],
    'termo' => [
        'titulo' => '📝 Termo',
        'arquivo' => 'termo.php'
    ],
    'apostas' => [
        'titulo' => '💰 Apostas',
        'arquivo' => 'apostas.php'
    ],
    'corrida' => [
        'titulo' => '🏎️ Corrida Neon',
        'arquivo' => 'corrida.php'
    ],
    'poker' => [
        'titulo' => '🃏 Poker',
        'arquivo' => 'poker.php'
    ],
    'grade' => [
        'titulo' => '🏀 Grade NBA',
        'arquivo' => 'grade.php'
    ],
    'boxnba' => [
        'titulo' => '🏀 Box NBA',
        'arquivo' => 'boxnba.php'
    ],
    'buildplayer' => [
        'titulo' => '🛠️ Build a Player',
        'arquivo' => 'buildplayer.php'
    ],
    'conexoes' => [
        'titulo' => '🔗 Conexões NBA',
        'arquivo' => 'conexoes.php'
    ],
    'quemsoueu' => [
        'titulo' => '🤔 Quem Sou Eu?',
        'arquivo' => 'quemsoueu.php'
    ],
    'bomba' => [
        'titulo' => '💣 Bomba',
        'arquivo' => 'bomba.php'
    ],
    'blackjack' => [
        'titulo' => '🃏 Blackjack',
        'arquivo' => 'blackjack.php'
    ],
    'roleta' => [
        'titulo' => '🎡 Roleta',
        'arquivo' => 'roleta.php'
    ],
    'hoopgrid' => [
        'titulo' => '🏀 Hoop Grid',
        'arquivo' => 'hoopgrid.php'
    ],
    'penalti' => [
        'titulo' => '⚽ Copa Pênaltis',
        'arquivo' => 'penalti.php'
    ],
    'buildplayer' => [
        'titulo' => '🏗️ Build-A-Player',
        'arquivo' => 'buildplayer.php'
    ],
    'dreamteam' => [
        'titulo' => '⚔️ Starting5x5',
        'arquivo' => 'dreamteam.php'
    ],
    'quizdodia' => [
        'titulo' => '💬 Quiz do Dia',
        'arquivo' => 'quizdodia.php'
    ],
];

// Valida se o game existe
if (!isset($games_disponiveis[$game])) {
    header("Location: /games.php");
    exit;
}

$game_config = $games_disponiveis[$game];
$arquivo_game = __DIR__ . '/' . $game_config['arquivo'];

// Se o arquivo de game específico existir, carrega ele
if (file_exists($arquivo_game)) {
    include $arquivo_game;
} else {
    die("Jogo não encontrado: " . htmlspecialchars($game));
}

function sanitize($input) {
    return preg_replace('/[^a-z0-9_-]/', '', strtolower($input));
}
?>
