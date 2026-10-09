<?php
/**
 * ── O ARQUIVO DO LIVRO DO MÊS, EM DUAS LÍNGUAS ───────────────────────
 *
 * Pedido da Agata (09/10/2026): "dois espaços pra subir o arquivo do livro —
 * vou tentar sempre mandar o PDF em pt e em inglês (se for estrangeiro)".
 *
 * São duas vagas fixas e não uma lista, porque a pergunta de quem chega é
 * "tem em português?" e não "quantos arquivos têm". Com duas vagas nomeadas a
 * tela responde isso sem ninguém ler nome de arquivo.
 *
 * ── ONDE O ARQUIVO MORA: FORA DO DIRETÓRIO WEB ───────────────────────
 *
 * A primeira versão guardava em uploads/clube-livro, dentro do public_html,
 * com um .htaccess negando a pasta inteira. Testado em produção, o PDF saiu
 * assim mesmo — HTTP 200 no endereço direto. O .htaccess estava lá e estava
 * certo; o servidor simplesmente não o aplicou.
 *
 * Então os arquivos saíram de lá. Fora do public_html não existe endereço
 * que os alcance, e isso não depende de o servidor estar configurado de um
 * jeito ou de outro — que é a diferença entre uma trava e um pedido.
 *
 * Quem entrega é clube-livro-arquivo.php, que pergunta quem está pedindo.
 *
 * O nome em disco é sorteado. O nome original vira só rótulo na tela: dois
 * meses seguidos com um "livro.pdf" se sobrescreveriam, e um nome vindo do
 * navegador é texto de fora — não é com ele que se monta caminho de arquivo.
 *
 * ── O QUE NÃO ENTRA ──────────────────────────────────────────────────
 *
 * Só PDF e EPUB, pelo CONTEÚDO e não pela extensão: renomear um .php pra
 * .pdf é o primeiro truque que se tenta num campo de upload. Os dois formatos
 * têm assinatura no começo do arquivo, e é ela que decide.
 */

require_once __DIR__ . '/db.php';

/**
 * Onde os arquivos ficam.
 *
 * Um nível ACIMA do diretório web — em produção isso é
 * ~/domains/fbabrasil.com.br/arquivos-clube, irmão do public_html e fora do
 * alcance de qualquer URL. Em desenvolvimento cai no mesmo lugar relativo e
 * funciona igual.
 */
const CLUBE_LIVRO_DIR = __DIR__ . '/../../arquivos-clube';

/** Teto por arquivo. Livro em PDF passa fácil de 10MB; 60 cobre com folga. */
const CLUBE_LIVRO_MAX = 60 * 1024 * 1024;

/** As duas vagas, na ordem em que aparecem na tela. */
const CLUBE_LIVRO_IDIOMAS = [
    'pt' => ['rot' => 'Português', 'ico' => 'bi-translate'],
    'en' => ['rot' => 'Inglês',    'ico' => 'bi-globe'],
];

function clubeLivroArquivoTabela(PDO $pdo): void
{
    static $feito = false;
    if ($feito) return;
    $feito = true;
    /* Colunas aditivas: banco que já existe não é recriado por CREATE TABLE
       IF NOT EXISTS, e sem isto o SELECT quebra em quem atualizou. */
    foreach (['arquivo_pt' => 'VARCHAR(255) NULL',
              'arquivo_en' => 'VARCHAR(255) NULL',
              'arquivo_pt_nome' => 'VARCHAR(190) NULL',
              'arquivo_en_nome' => 'VARCHAR(190) NULL'] as $col => $tipo) {
        try { $pdo->exec("ALTER TABLE clube_semana_ciclos ADD COLUMN {$col} {$tipo}"); }
        catch (Throwable $e) { /* já existe, que é o caso normal */ }
    }
}

/**
 * É mesmo um PDF ou um EPUB?
 *
 * Pela assinatura do começo do arquivo. A extensão é escolhida por quem
 * envia, então ela não serve de prova de nada — e o EPUB é um zip, por isso
 * a assinatura dele é a do zip.
 */
function clubeLivroFormatoOk(string $caminho): ?string
{
    $fh = @fopen($caminho, 'rb');
    if (!$fh) return null;
    $cab = (string)fread($fh, 4);
    fclose($fh);
    if (strncmp($cab, '%PDF', 4) === 0) return 'pdf';
    if (strncmp($cab, "PK\x03\x04", 4) === 0) return 'epub';
    return null;
}

/**
 * Guarda o arquivo de um idioma no ciclo do livro.
 *
 * @param array $arquivo uma entrada de $_FILES
 * @return string '' quando deu certo; a mensagem de erro quando não
 */
function clubeLivroGuardarArquivo(PDO $pdo, int $cicloId, string $idioma, array $arquivo): string
{
    if (!isset(CLUBE_LIVRO_IDIOMAS[$idioma])) return 'Idioma desconhecido.';
    clubeLivroArquivoTabela($pdo);

    $erro = (int)($arquivo['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($erro === UPLOAD_ERR_NO_FILE) return '';                 // não mandou nada: tudo bem
    if ($erro === UPLOAD_ERR_INI_SIZE || $erro === UPLOAD_ERR_FORM_SIZE) {
        return 'O arquivo passou do tamanho que o servidor aceita.';
    }
    if ($erro !== UPLOAD_ERR_OK) return 'O envio falhou no meio do caminho.';

    $tmp = (string)($arquivo['tmp_name'] ?? '');
    if (!is_uploaded_file($tmp)) return 'Envio inválido.';
    if ((int)($arquivo['size'] ?? 0) > CLUBE_LIVRO_MAX) {
        return 'O arquivo tem mais de ' . (int)(CLUBE_LIVRO_MAX / 1048576) . 'MB.';
    }

    $formato = clubeLivroFormatoOk($tmp);
    if ($formato === null) return 'Só entra PDF ou EPUB — o arquivo enviado não é nenhum dos dois.';

    if (!is_dir(CLUBE_LIVRO_DIR) && !@mkdir(CLUBE_LIVRO_DIR, 0775, true)) {
        error_log('[clube-livro] nao criei ' . CLUBE_LIVRO_DIR);
        return 'Não consegui guardar o arquivo.';
    }

    /* Nome sorteado: o que veio do navegador é texto de fora e vira só
       rótulo. @see o cabeçalho. */
    $nome = bin2hex(random_bytes(16)) . '.' . $formato;
    if (!@move_uploaded_file($tmp, CLUBE_LIVRO_DIR . '/' . $nome)) {
        return 'Não consegui guardar o arquivo.';
    }

    /* O anterior sai do disco: cada vaga guarda um arquivo, e deixar os
       velhos lá enche o servidor com livros que ninguém mais alcança. */
    $st = $pdo->prepare("SELECT arquivo_{$idioma} FROM clube_semana_ciclos WHERE id = ?");
    $st->execute([$cicloId]);
    if ($velho = (string)($st->fetchColumn() ?: '')) {
        @unlink(CLUBE_LIVRO_DIR . '/' . basename($velho));
    }

    $rot = mb_substr(trim((string)($arquivo['name'] ?? '')), 0, 190) ?: ('livro.' . $formato);
    $pdo->prepare("UPDATE clube_semana_ciclos
                      SET arquivo_{$idioma} = ?, arquivo_{$idioma}_nome = ?
                    WHERE id = ?")->execute([$nome, $rot, $cicloId]);
    return '';
}

/** Tira o arquivo de um idioma, do banco e do disco. */
function clubeLivroApagarArquivo(PDO $pdo, int $cicloId, string $idioma): void
{
    if (!isset(CLUBE_LIVRO_IDIOMAS[$idioma])) return;
    clubeLivroArquivoTabela($pdo);
    $st = $pdo->prepare("SELECT arquivo_{$idioma} FROM clube_semana_ciclos WHERE id = ?");
    $st->execute([$cicloId]);
    if ($nome = (string)($st->fetchColumn() ?: '')) {
        @unlink(CLUBE_LIVRO_DIR . '/' . basename($nome));
    }
    $pdo->prepare("UPDATE clube_semana_ciclos
                      SET arquivo_{$idioma} = NULL, arquivo_{$idioma}_nome = NULL
                    WHERE id = ?")->execute([$cicloId]);
}

/** O que cada vaga tem hoje: [idioma => ['nome' => rótulo]] — só as cheias. */
function clubeLivroArquivos(PDO $pdo, int $cicloId): array
{
    clubeLivroArquivoTabela($pdo);
    try {
        $st = $pdo->prepare('SELECT arquivo_pt, arquivo_pt_nome, arquivo_en, arquivo_en_nome
                               FROM clube_semana_ciclos WHERE id = ?');
        $st->execute([$cicloId]);
        $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
    $fora = [];
    foreach (array_keys(CLUBE_LIVRO_IDIOMAS) as $id) {
        if (!empty($r['arquivo_' . $id])) {
            $fora[$id] = ['nome' => (string)($r['arquivo_' . $id . '_nome'] ?: 'livro')];
        }
    }
    return $fora;
}
