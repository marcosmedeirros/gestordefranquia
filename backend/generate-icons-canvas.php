<?php
// Gerador de ícones via Canvas (sem GD). Acesse: /backend/generate-icons-canvas.php
// Gera PNGs com fundo preto a partir de /img/fba-logo.png

declare(strict_types=1);

$root = realpath(__DIR__ . '/..');
$outDir = $root . '/img/icons';

function ensureDir(string $dir): void {
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
}

function jsonOut(bool $ok, string $msg, array $extra = []): void {
    header('Content-Type: application/json');
    echo json_encode(array_merge(['success' => $ok, 'message' => $msg], $extra));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $payload = json_decode(file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        jsonOut(false, 'Payload inválido.');
    }

    $size = (int) ($payload['size'] ?? 0);
    $dataUrl = (string) ($payload['dataUrl'] ?? '');

    if ($size <= 0 || !preg_match('/^data:image\/png;base64,/', $dataUrl)) {
        jsonOut(false, 'Dados inválidos.');
    }

    $base64 = preg_replace('/^data:image\/png;base64,/', '', $dataUrl);
    $data = base64_decode($base64, true);

    if ($data === false) {
        jsonOut(false, 'Falha ao decodificar imagem.');
    }

    ensureDir($outDir);
    $out = sprintf('%s/icon-%d.png', $outDir, $size);
    if (file_put_contents($out, $data) === false) {
        jsonOut(false, 'Falha ao salvar arquivo.');
    }

    jsonOut(true, 'Salvo', ['file' => $out]);
}

?><!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Gerar ícones (Canvas)</title>
    <style>
        body { background:#0b0b0b; color:#fff; font-family: Arial, sans-serif; padding:20px; }
        button { padding:10px 16px; border:0; background:#fc0025; color:#fff; border-radius:6px; cursor:pointer; }
        .log { margin-top:12px; white-space:pre-wrap; font-family: Consolas, monospace; font-size:12px; }
    </style>
</head>
<body>
    <h2>Gerar ícones com fundo preto</h2>
    <p>Base: /img/logo-fba-preta.jpg. Este gerador aplica fundo preto em todos os tamanhos.</p>
    <button id="gen">Gerar ícones</button>
    <div id="log" class="log"></div>

    <script>
        const sizes = [16, 32, 48, 72, 96, 128, 144, 152, 167, 180, 192, 256, 384, 512, 1024];
        const logEl = document.getElementById('log');

        function log(msg) {
            logEl.textContent += msg + '\n';
        }

        async function generate() {
            logEl.textContent = '';
            const img = new Image();
            img.src = '/img/logo-fba-preta.jpg';
            await img.decode();

            for (const size of sizes) {
                const canvas = document.createElement('canvas');
                canvas.width = size;
                canvas.height = size;
                const ctx = canvas.getContext('2d');

                // Fundo preto
                ctx.fillStyle = '#000';
                ctx.fillRect(0, 0, size, size);

                // Centraliza mantendo proporção
                const scale = Math.min(size / img.width, size / img.height);
                const nw = Math.round(img.width * scale);
                const nh = Math.round(img.height * scale);
                const dx = Math.floor((size - nw) / 2);
                const dy = Math.floor((size - nh) / 2);

                ctx.drawImage(img, dx, dy, nw, nh);

                const dataUrl = canvas.toDataURL('image/png');
                const res = await fetch(location.pathname, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ size, dataUrl })
                });
                const json = await res.json();
                if (!json.success) {
                    log(`Erro ${size}px: ${json.message}`);
                } else {
                    log(`OK ${size}px`);
                }
            }
            log('Concluído.');
        }

        document.getElementById('gen').addEventListener('click', () => {
            generate().catch(err => log('Erro: ' + err.message));
        });
    </script>
</body>
</html>
