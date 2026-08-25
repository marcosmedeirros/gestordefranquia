<?php
/**
 * UPLOAD DAS FOTOS DA COPA.
 *
 * Recebe uma ou várias imagens e devolve a URL de cada uma, junto com um
 * nome sugerido tirado do nome do arquivo. Quem chama é a tela de criação
 * da copa, que monta as linhas "Nome | url" com o que voltar daqui.
 *
 * ── Por que o nome vem do arquivo ───────────────────────────────────────
 *
 * Quem monta uma copa de figurinhas já tem as imagens salvas com o nome
 * certo — "coxinha.jpg", "pastel.png". Aproveitar isso transforma "escolher
 * 16 arquivos" numa copa inteira pronta, com os nomes já preenchidos e
 * editáveis antes de sortear. Pedir o nome de novo, um por um, seria digitar
 * o que o computador já sabe.
 */

require_once __DIR__ . '/../../backend/db.php';
require_once __DIR__ . '/../../backend/auth.php';

header('Content-Type: application/json; charset=utf-8');

requireAuth();
$pdo = db();
$userId = (int)($_SESSION['user_id'] ?? 0);

// Mesma régua da criação da copa: admin geral ou admin do Games.
if ($userId <= 0 || !hasGamesAdminAccess($pdo, $userId)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'erro' => 'Só quem administra o Games envia foto.']);
    exit;
}

if (empty($_FILES['fotos'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'erro' => 'Nenhum arquivo recebido.']);
    exit;
}

/** As extensões que valem. webp e avif entram porque é o que o celular gera. */
const COPA_EXT = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif'];

/** 6MB por foto. Foto de bracket é miniatura — acima disso é foto crua. */
const COPA_MAX_BYTES = 6 * 1024 * 1024;

/** Teto por envio, alinhado com o máximo de competidores. */
const COPA_MAX_ARQUIVOS = 64;

$dir = dirname(__DIR__, 2) . '/uploads/copa';
if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'erro' => 'Não deu pra preparar a pasta de upload.']);
    exit;
}

// O PHP entrega múltiplos arquivos como arrays paralelos. Normalizo pra uma
// lista de arquivos, que é como o resto do código quer pensar nisso.
$brutos = $_FILES['fotos'];
$arquivos = [];
if (is_array($brutos['name'])) {
    foreach (array_keys($brutos['name']) as $i) {
        $arquivos[] = [
            'name'     => $brutos['name'][$i],
            'tmp_name' => $brutos['tmp_name'][$i],
            'error'    => $brutos['error'][$i],
            'size'     => $brutos['size'][$i],
        ];
    }
} else {
    $arquivos[] = $brutos;
}

if (count($arquivos) > COPA_MAX_ARQUIVOS) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'erro' => 'No máximo ' . COPA_MAX_ARQUIVOS . ' fotos por vez.']);
    exit;
}

/**
 * O nome do competidor, tirado do arquivo.
 *
 * "coxinha_de_frango.jpg" vira "Coxinha De Frango". Underscore e hífen viram
 * espaço porque é assim que nome de arquivo escreve espaço, e o número solto
 * no fim ("foto (1)") sai fora — ele é do gerenciador de arquivos, não do
 * competidor.
 */
function copaNomeDoArquivo(string $arquivo): string
{
    $n = pathinfo($arquivo, PATHINFO_FILENAME);
    $n = str_replace(['_', '-', '.'], ' ', $n);
    $n = preg_replace('/\s*\(\d+\)\s*$/u', '', $n);   // "foto (1)"
    $n = preg_replace('/\s+/u', ' ', trim($n));
    if ($n === '') return 'Sem nome';
    return mb_substr(mb_convert_case($n, MB_CASE_TITLE, 'UTF-8'), 0, 80);
}

$out = [];
$erros = [];

foreach ($arquivos as $f) {
    $nomeOriginal = (string)($f['name'] ?? 'arquivo');

    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $erros[] = $nomeOriginal . ': não chegou inteiro';
        continue;
    }
    if ((int)$f['size'] > COPA_MAX_BYTES) {
        $erros[] = $nomeOriginal . ': passa de 6MB';
        continue;
    }

    $ext = strtolower(pathinfo($nomeOriginal, PATHINFO_EXTENSION));
    if (!in_array($ext, COPA_EXT, true)) {
        $erros[] = $nomeOriginal . ': formato não aceito';
        continue;
    }

    // A extensão diz o que o arquivo ALEGA ser; getimagesize diz o que ele é.
    // Sem esta checagem, um .php renomeado pra .jpg entraria na pasta —
    // e a pasta é servida pelo mesmo servidor que executa PHP.
    $info = @getimagesize($f['tmp_name']);
    if ($info === false) {
        $erros[] = $nomeOriginal . ': não é uma imagem de verdade';
        continue;
    }

    // Nome do arquivo salvo é gerado aqui, e nunca o que veio de fora: o
    // nome original pode trazer "../" ou uma segunda extensão.
    $destino = sprintf('copa_%d_%s.%s', $userId, bin2hex(random_bytes(8)), $ext);
    $caminho = $dir . '/' . $destino;

    if (!move_uploaded_file($f['tmp_name'], $caminho)) {
        $erros[] = $nomeOriginal . ': não deu pra salvar';
        continue;
    }
    @chmod($caminho, 0644);

    $out[] = [
        'nome' => copaNomeDoArquivo($nomeOriginal),
        'url'  => '/uploads/copa/' . $destino,
    ];
}

echo json_encode(['ok' => true, 'fotos' => $out, 'erros' => $erros], JSON_UNESCAPED_SLASHES);
