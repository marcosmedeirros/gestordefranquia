<?php
// Gera ícones PWA a partir de img/fba-logo.png usando GD
// Uso: php backend/generate-icons.php

declare(strict_types=1);

$root = realpath(__DIR__ . '/..');
$srcPath = $root . '/img/fba-logo.png';
$outDir  = $root . '/img/icons';

$sizes = [16, 32, 48, 72, 96, 128, 144, 152, 167, 180, 192, 256, 384, 512, 1024];

function ensureDir(string $dir): void {
    if (!is_dir($dir)) {
        if (!mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new RuntimeException('Não foi possível criar diretório: ' . $dir);
        }
    }
}

function loadPng(string $path) {
    if (!file_exists($path)) {
        throw new RuntimeException('Arquivo de origem não encontrado: ' . $path);
    }
    $img = imagecreatefrompng($path);
    if (!$img) {
        throw new RuntimeException('Falha ao carregar PNG: ' . $path);
    }
    imagealphablending($img, true);
    imagesavealpha($img, true);
    return $img;
}

function resizePng($src, int $size) {
    $w = imagesx($src);
    $h = imagesy($src);
    $dst = imagecreatetruecolor($size, $size);
    // Fundo preto opaco
    $black = imagecolorallocate($dst, 0, 0, 0);
    imagefilledrectangle($dst, 0, 0, $size, $size, $black);
    imagealphablending($dst, true);
    imagesavealpha($dst, false);

    // Centraliza mantendo proporção
    $scale = min($size / $w, $size / $h);
    $nw = (int) round($w * $scale);
    $nh = (int) round($h * $scale);
    $dx = (int) floor(($size - $nw) / 2);
    $dy = (int) floor(($size - $nh) / 2);

    imagecopyresampled($dst, $src, $dx, $dy, 0, 0, $nw, $nh, $w, $h);
    return $dst;
}

try {
    ensureDir($outDir);
        if (!function_exists('imagecreatefrompng')) {
            // Fallback: copie uma versão com fundo preto se existir
            $bgSrc = $root . '/img/fba-logo-bg.png';
            $useSrc = file_exists($bgSrc) ? $bgSrc : $srcPath;
            // Copiar a imagem base para todos os tamanhos
            foreach ($sizes as $sz) {
                $out = sprintf('%s/icon-%d.png', $outDir, $sz);
                if (!copy($useSrc, $out)) {
                    throw new RuntimeException('Falha ao copiar para: ' . $out);
                }
                echo "Copiado (fallback): $out\n";
            }
            echo "Concluído (fallback sem GD).\n";
        } else {
            $src = loadPng($srcPath);
            foreach ($sizes as $sz) {
                $dst = resizePng($src, $sz);
                $out = sprintf('%s/icon-%d.png', $outDir, $sz);
                if (!imagepng($dst, $out, 9)) {
                    throw new RuntimeException('Falha ao salvar: ' . $out);
                }
                imagedestroy($dst);
                echo "Gerado: $out\n";
            }
            imagedestroy($src);
            echo "Concluído com sucesso.\n";
        }
} catch (Throwable $e) {
    fwrite(STDERR, '[ERRO] ' . $e->getMessage() . "\n");
    exit(1);
}
