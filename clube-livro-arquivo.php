<?php
/**
 * ── O DOWNLOAD DO LIVRO DO MÊS ───────────────────────────────────────
 *
 * Os arquivos ficam fora de /public de propósito, então o servidor web não
 * os serve sozinho: quem entrega é este arquivo, e por isso ele pode perguntar
 * quem está pedindo antes.
 *
 * E precisa perguntar. Um PDF de livro é obra de outra pessoa; o clube é um
 * grupo fechado de gente da liga lendo junto, e um endereço que qualquer um
 * abre é outra coisa. Aqui só passa quem está logado E é do clube do livro —
 * a mesma porta das enquetes e das resenhas.
 *
 * O nome do arquivo em disco é sorteado e nunca vem da URL: o que a pessoa
 * pede é o CICLO e o IDIOMA, e o caminho sai do banco. Assim não existe
 * "../" pra tentar.
 */

require_once __DIR__ . '/backend/db.php';
require_once __DIR__ . '/backend/auth.php';
requireAuth();

require_once __DIR__ . '/backend/clube_livro.php';
require_once __DIR__ . '/backend/clube_livro_arquivos.php';

$pdo = db();
$uid = (int)($_SESSION['user_id'] ?? 0);

/* A mesma porta do resto do clube do livro — admin entra sempre, como nas
   outras ações. */
$ehAdmin = (($_SESSION['user_type'] ?? '') === 'admin');
if (!$ehAdmin && !clubeLivroEhMembro($pdo, $uid)) {
    http_response_code(403);
    exit('Só quem está no Clube do Livro baixa o arquivo.');
}

$ciclo  = (int)($_GET['ciclo'] ?? 0);
$idioma = (string)($_GET['idioma'] ?? '');
if (!$ciclo || !isset(CLUBE_LIVRO_IDIOMAS[$idioma])) {
    http_response_code(404);
    exit('Arquivo não encontrado.');
}

clubeLivroArquivoTabela($pdo);
$st = $pdo->prepare("SELECT arquivo_{$idioma} AS arq, arquivo_{$idioma}_nome AS nome
                       FROM clube_semana_ciclos WHERE id = ? AND tipo = 'livro'");
$st->execute([$ciclo]);
$r = $st->fetch(PDO::FETCH_ASSOC);
if (!$r || empty($r['arq'])) {
    http_response_code(404);
    exit('Arquivo não encontrado.');
}

/* basename() porque o caminho vem do banco, mas o banco também é escrito por
   código — e uma camada a mais aqui não custa nada. */
$caminho = CLUBE_LIVRO_DIR . '/' . basename((string)$r['arq']);
if (!is_file($caminho)) {
    http_response_code(404);
    exit('O arquivo não está mais no servidor.');
}

$ext  = strtolower(pathinfo($caminho, PATHINFO_EXTENSION));
$tipo = $ext === 'epub' ? 'application/epub+zip' : 'application/pdf';
$nome = (string)($r['nome'] ?: ('livro.' . $ext));
/* Sem quebra de linha e sem aspas no cabeçalho: o nome veio de fora um dia. */
$nome = preg_replace('/[\r\n"]+/', ' ', $nome);

header('Content-Type: ' . $tipo);
header('Content-Length: ' . filesize($caminho));
header('Content-Disposition: inline; filename="' . $nome . '"');
header('X-Content-Type-Options: nosniff');
readfile($caminho);
