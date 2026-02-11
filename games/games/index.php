<?php
/**
 * GAMES/INDEX.PHP - CARREGADOR DINÂMICO DE GAMES
 * Carrega dinamicamente os games baseado no parâmetro 'game'
 */

session_start();
require '../core/conexao.php';

// Segurança
if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
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
    'xadrez' => [
        'titulo' => '♛ Xadrez',
        'arquivo' => 'xadrez.php'
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
    ]
    
];

// Valida se o game existe
if (!isset($games_disponiveis[$game])) {
    header("Location: ../index.php");
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
