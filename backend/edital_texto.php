<?php
/**
 * O TEXTO DO EDITAL, TIRADO DO PDF E GUARDADO NO BANCO.
 *
 * O edital de cada liga é um PDF em uploads/editais/, e PDF não se lê de
 * dentro de uma resposta de bot: abrir e extrair custa segundos, e o bot
 * responde no meio de uma conversa de grupo. Então o texto é extraído UMA vez,
 * quando o PDF entra, e vive na coluna `league_settings.edital` — que já
 * existia vazia desde a migração de 2025.
 *
 * A extração roda com o que a máquina tiver: `pdftotext` (poppler) quando
 * existe, senão o Ghostscript, que está instalado no servidor da Hostinger.
 * São dois binários porque nenhum dos dois está garantido nos dois lugares —
 * aqui só tem o primeiro, lá só o segundo.
 */

require_once __DIR__ . '/db.php';

/** Onde ficam os PDFs enviados pelo admin. */
function editalDiretorio(): string
{
    return dirname(__DIR__) . '/uploads/editais';
}

/**
 * Roda o extrator disponível e devolve o texto cru, ou null se nenhum serviu.
 *
 * Não lança: sem extrator o edital simplesmente não fica pesquisável, e isso
 * não pode derrubar o upload do PDF nem a resposta do bot.
 */
function editalExtrairTexto(string $pdfPath): ?string
{
    if (!is_file($pdfPath) || !is_readable($pdfPath)) return null;

    $saida = tempnam(sys_get_temp_dir(), 'edital_') ?: null;
    if ($saida === null) return null;

    // O primeiro que existir ganha. `-layout` e o txtwrite do gs preservam a
    // ordem de leitura; sem isso o texto de duas colunas sai embaralhado.
    $tentativas = [
        ['pdftotext', ['-layout', '-enc', 'UTF-8', $pdfPath, $saida]],
        ['gs', ['-q', '-dNOPAUSE', '-dBATCH', '-sDEVICE=txtwrite',
                '-sOutputFile=' . $saida, $pdfPath]],
    ];

    // O descarte do stderr muda de shell: o servidor é Linux, mas quem
    // desenvolve roda no Windows, e lá "2>/dev/null" cria um arquivo chamado
    // "null" e o comando falha.
    $devNull = stripos(PHP_OS_FAMILY, 'Windows') === 0 ? '2>NUL' : '2>/dev/null';

    $texto = null;
    foreach ($tentativas as [$bin, $args]) {
        $cmd = escapeshellcmd($bin) . ' ' . implode(' ', array_map('escapeshellarg', $args)) . ' ' . $devNull;
        @exec($cmd, $_, $code);
        if ($code === 0 && is_file($saida) && filesize($saida) > 200) {
            $texto = @file_get_contents($saida);
            if ($texto !== false && trim($texto) !== '') break;
            $texto = null;
        }
    }
    @unlink($saida);

    return $texto !== null ? editalNormalizar($texto) : null;
}

/**
 * Deixa o texto legível pra busca e pra leitura.
 *
 * O PDF do edital tem ZERO-WIDTH SPACE (U+200B) depois do ponto dos títulos —
 * "I.<invisível> DA NATUREZA". Sem tirar, "I. DA NATUREZA" não casa com nada e
 * a busca falha sem motivo aparente, que é o pior tipo de bug pra achar
 * depois. O resto é o de sempre: o layout de duas colunas deixa corredores de
 * espaço, e cada página larga um número solto no meio do texto.
 */
function editalNormalizar(string $txt): string
{
    // Invisíveis: zero-width space/non-joiner/joiner e BOM.
    $txt = preg_replace('/[\x{200B}\x{200C}\x{200D}\x{FEFF}]/u', '', $txt);
    // Quebra de página vira quebra de linha.
    $txt = str_replace("\f", "\n", $txt);
    $txt = str_replace("\r\n", "\n", $txt);
    // Corredores de espaço do layout viram um espaço só.
    $txt = preg_replace('/[ \t]{2,}/', ' ', $txt);
    /* Número de página sozinho numa linha.
       O lookahead é o que faz isto funcionar no FIM de um bloco: sem ele o
       regex exigia uma quebra depois do número, e a última página de cada
       trecho sobrava — o artigo terminava com um "5" solto pendurado. */
    $txt = preg_replace('/\n[ \t]*\d{1,3}[ \t]*(?=\n|$)/', '', $txt);
    // Três ou mais linhas em branco viram uma.
    $txt = preg_replace('/\n{3,}/', "\n\n", $txt);

    $linhas = array_map('rtrim', explode("\n", $txt));
    return trim(implode("\n", $linhas));
}

/**
 * Extrai o texto do PDF da liga e grava em league_settings.edital.
 *
 * Chamado no upload (api/edital.php) e sob demanda, quando o bot precisa do
 * texto e a coluna está vazia — que é o caso dos três editais que já estavam
 * no ar antes disto existir.
 *
 * @return array{ok:bool,erro:?string,chars:int}
 */
function editalSincronizar(PDO $pdo, string $league): array
{
    $league = strtoupper(trim($league));
    if (!in_array($league, ['ELITE', 'NEXT', 'RISE', 'ROOKIE'], true)) {
        return ['ok' => false, 'erro' => 'Liga inválida', 'chars' => 0];
    }

    $st = $pdo->prepare("SELECT edital_file FROM league_settings WHERE league = ?");
    $st->execute([$league]);
    $arquivo = (string)($st->fetchColumn() ?: '');
    if ($arquivo === '') {
        return ['ok' => false, 'erro' => 'Esta liga não tem edital cadastrado.', 'chars' => 0];
    }

    // basename() porque o nome vem do banco: mesmo tendo sido gravado pelo
    // upload, ele não pode virar caminho pra fora da pasta dos editais.
    $caminho = editalDiretorio() . '/' . basename($arquivo);
    $texto = editalExtrairTexto($caminho);
    if ($texto === null || mb_strlen($texto) < 500) {
        return ['ok' => false, 'erro' => 'Não consegui ler o texto deste PDF.', 'chars' => 0];
    }

    $pdo->prepare("UPDATE league_settings SET edital = ? WHERE league = ?")
        ->execute([$texto, $league]);

    return ['ok' => true, 'erro' => null, 'chars' => mb_strlen($texto)];
}

/**
 * O texto do edital da liga, extraindo na hora se ainda não foi extraído.
 *
 * A extração sob demanda acontece uma vez por edital: da segunda chamada em
 * diante sai direto do banco.
 */
function editalTexto(PDO $pdo, string $league): ?string
{
    $league = strtoupper(trim($league));
    try {
        $st = $pdo->prepare("SELECT edital FROM league_settings WHERE league = ?");
        $st->execute([$league]);
        $txt = (string)($st->fetchColumn() ?: '');
        if (mb_strlen($txt) >= 500) return $txt;

        $r = editalSincronizar($pdo, $league);
        if (!$r['ok']) return null;

        $st->execute([$league]);
        return (string)($st->fetchColumn() ?: '') ?: null;
    } catch (Throwable $e) {
        error_log('[edital] texto ' . $league . ': ' . $e->getMessage());
        return null;
    }
}

/**
 * Os capítulos do edital, na ordem em que aparecem.
 *
 * Indexa pelo TÍTULO e não pelo número romano de propósito: o edital da ROOKIE
 * tem dois capítulos "VI" e pula de VII pra XI, então o número não identifica
 * nada. O título identifica.
 *
 * @return list<array{titulo:string,inicio:int,fim:int}>
 */
function editalCapitulos(string $texto): array
{
    $linhas = explode("\n", $texto);
    $marcos = [];
    foreach ($linhas as $i => $l) {
        $t = trim($l);
        if (!preg_match('/^([IVXL]+)\.\s+(.{6,})$/u', $t, $m)) continue;
        $titulo = trim($m[2]);
        // Capítulo é o título em caixa alta; os incisos ("I. Jogadores com...")
        // usam o mesmo numeral romano e são texto normal.
        if (mb_strtoupper($titulo, 'UTF-8') !== $titulo) continue;
        if (!preg_match('/\p{Lu}{3}/u', $titulo)) continue;
        $marcos[] = ['titulo' => $titulo, 'inicio' => $i];
    }

    $out = [];
    foreach ($marcos as $k => $c) {
        $c['fim'] = isset($marcos[$k + 1]) ? $marcos[$k + 1]['inicio'] - 1 : count($linhas) - 1;
        $out[] = $c;
    }
    return $out;
}

/**
 * O título do capítulo em caixa de leitura.
 *
 * No PDF os títulos são caixa alta, e caixa alta no WhatsApp parece grito. Mas
 * MB_CASE_TITLE sozinho não serve: ele devolve "Da Natureza E Essência Da Fba"
 * — com a sigla da liga virando palavra e as preposições em maiúscula, que não
 * é como se escreve título em português.
 */
function editalTituloLegivel(string $titulo): string
{
    $minusculas = ['de','da','do','das','dos','e','em','no','na','nos','nas','a','o','as','os','por','para','com'];
    $siglas     = ['FBA','GM','GMS','NBA','OVR','G-LEAGUE','PRA','MVP'];

    $palavras = preg_split('/\s+/u', mb_strtolower(trim($titulo), 'UTF-8'));
    $out = [];
    foreach ($palavras as $i => $p) {
        $limpa = mb_strtoupper($p, 'UTF-8');
        if (in_array($limpa, $siglas, true)) { $out[] = $limpa; continue; }
        // Preposição fica minúscula, menos quando abre o título.
        if ($i > 0 && in_array($p, $minusculas, true)) { $out[] = $p; continue; }
        $out[] = mb_convert_case($p, MB_CASE_TITLE, 'UTF-8');
    }
    return implode(' ', $out);
}

/**
 * Os artigos do edital: número, texto completo e o capítulo em que vivem.
 *
 * É a unidade que o bot cita. Um artigo vai do "Art. N" até o próximo, então
 * parágrafos e incisos ficam junto do artigo a que pertencem — que é como o
 * documento deve ser lido.
 *
 * @return list<array{num:int,titulo:string,capitulo:string,texto:string}>
 */
function editalArtigos(string $texto): array
{
    $linhas = explode("\n", $texto);
    $caps = editalCapitulos($texto);
    $capDaLinha = function (int $i) use ($caps): string {
        foreach ($caps as $c) if ($i >= $c['inicio'] && $i <= $c['fim']) return $c['titulo'];
        return '';
    };

    $marcos = [];
    foreach ($linhas as $i => $l) {
        if (preg_match('/^\s*Art\.\s*(\d{1,3})\s*º?\.?/u', $l, $m)) {
            $marcos[] = ['num' => (int)$m[1], 'inicio' => $i];
        }
    }

    $out = [];
    foreach ($marcos as $k => $a) {
        $fim = isset($marcos[$k + 1]) ? $marcos[$k + 1]['inicio'] - 1 : count($linhas) - 1;
        $corpo = trim(implode("\n", array_slice($linhas, $a['inicio'], $fim - $a['inicio'] + 1)));
        if ($corpo === '') continue;
        $out[] = [
            'num'      => $a['num'],
            'capitulo' => $capDaLinha($a['inicio']),
            'texto'    => $corpo,
        ];
    }
    return $out;
}
