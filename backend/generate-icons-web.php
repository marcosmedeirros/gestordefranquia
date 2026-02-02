<?php
// Gerador de ícones via web (tenta GD). Acomoda fundo preto.
// Acesse: /backend/generate-icons-web.php

declare(strict_types=1);

$root = realpath(__DIR__ . '/..');
$srcPath = $root . '/img/fba-logo.png';
$outDir  = $root . '/img/icons';
$sizes   = [48,72,96,128,144,152,167,180,192,256,384,512,1024];

function ensureDir(string $dir): void {
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
}

function jsonOut($ok, $msg, $extra = []) {
    header('Content-Type: application/json');
    echo json_encode(array_merge(['success' => $ok, 'message' => $msg], $extra));
    exit;
}

ensureDir($outDir);
if (!file_exists($srcPath)) {
    jsonOut(false, 'Base fba-logo.png não encontrada.');
}

if (!function_exists('imagecreatefrompng')) {
    // Fallback: copiar sem alteração
    $generated = [];
    foreach ($sizes as $sz) {
        $out = sprintf('%s/icon-%d.png', $outDir, $sz);
        copy($srcPath, $out);
        $generated[] = $out;
    }
    jsonOut(true, 'Gerado via fallback (sem GD).', ['files' => $generated, 'gd' => false]);
}

$src = imagecreatefrompng($srcPath);
if (!$src) {
    jsonOut(false, 'Falha ao carregar PNG base.');
}
imagealphablending($src, true);
imagesavealpha($src, true);

$w = imagesx($src);
$h = imagesy($src);
$generated = [];

foreach ($sizes as $sz) {
    $dst = imagecreatetruecolor($sz, $sz);
    // Fundo preto opaco
    $black = imagecolorallocate($dst, 0, 0, 0);
    imagefilledrectangle($dst, 0, 0, $sz, $sz, $black);
    imagealphablending($dst, true);
    imagesavealpha($dst, false);

    // Centraliza mantendo proporção (square)
    $scale = min($sz / $w, $sz / $h);
    $nw = (int) round($w * $scale);
    $nh = (int) round($h * $scale);
    $dx = (int) floor(($sz - $nw) / 2);
    $dy = (int) floor(($sz - $nh) / 2);

    imagecopyresampled($dst, $src, $dx, $dy, 0, 0, $nw, $nh, $w, $h);
    $out = sprintf('%s/icon-%d.png', $outDir, $sz);
    imagepng($dst, $out, 9);
    imagedestroy($dst);
    $generated[] = $out;
}

imagedestroy($src);
jsonOut(true, 'Gerado com fundo preto.', ['files' => $generated, 'gd' => true]);
