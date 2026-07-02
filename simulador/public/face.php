<?php
/**
 * Servidor de faces genéricas.
 *
 * Modo 1 (preferido): /face.php?id=42&name=Marcus+Walker&pos=PG
 *   → calcula categoria e índice a partir do id + nome
 *
 * Modo 2 (legado): /face.php?f=African42.png
 *   → serve o arquivo diretamente (valida pattern seguro)
 *
 * Serve a imagem PNG da pasta ../faces/ (fora do webroot).
 * Cache de 1 dia no browser.
 */

require_once dirname(__DIR__) . '/src/PlayerFace.php';

$facesDir = dirname(__DIR__) . '/faces/';

// ── Modo 2: arquivo direto ────────────────────────────────────────────────
if (isset($_GET['f'])) {
    $file = basename($_GET['f']);
    // Valida: apenas letras/espaços + dígitos + .png
    if (!preg_match('/^[A-Za-z ]+\d+\.png$/', $file)) {
        http_response_code(400); exit('bad request');
    }
    $path = $facesDir . $file;

// ── Modo 1: id + nome ──────────────────────────────────────────────────────
} else {
    $id   = max(1, (int) ($_GET['id']   ?? 1));
    $name = trim(       $_GET['name']  ?? '');
    $pos  = trim(       $_GET['pos']   ?? '');

    if ($name === '') {
        // sem nome → face neutra fixa
        $path = $facesDir . 'Caucasian1.png';
    } else {
        $file = PlayerFace::filename($id, $name, $pos);
        $path = $facesDir . $file;
    }
}

// ── Fallback se o arquivo ainda não existir ───────────────────────────────
if (!file_exists($path)) {
    $path = $facesDir . 'African1.png';
}

if (!file_exists($path)) {
    http_response_code(404); exit('face not found');
}

header('Content-Type: image/png');
header('Cache-Control: public, max-age=86400');
header('Content-Length: ' . filesize($path));
readfile($path);
exit;
