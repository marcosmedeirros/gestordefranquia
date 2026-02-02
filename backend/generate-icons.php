<?php
// Gera ícones PWA a partir de img/fba-logo.png usando GD
// Uso: php backend/generate-icons.php

declare(strict_types=1);

$root = realpath(__DIR__ . '/..');
$srcPath = $root . '/img/fba-logo.png';
$outDir  = $root . '/img/icons';

$sizes = [48, 72, 96, 128, 144, 152, 167, 180, 192, 256, 384, 512, 1024];

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
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
    imagefilledrectangle($dst, 0, 0, $size, $size, $transparent);
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $size, $size, $w, $h);
    return $dst;
}

try {
    ensureDir($outDir);
        if (!function_exists('imagecreatefrompng')) {
            // Fallback: copiar a imagem original para todos os tamanhos (sem redimensionar)
            foreach ($sizes as $sz) {
                $out = sprintf('%s/icon-%d.png', $outDir, $sz);
                if (!copy($srcPath, $out)) {
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
