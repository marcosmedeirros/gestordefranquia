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

    /* TRÊS EXTRAÇÕES, E VENCE A MAIS LIMPA.
       Não é redundância: os PDFs desta liga foram gerados de formas
       diferentes e cada modo quebra num deles. Medido nos três editais:

         - `-layout` sai perfeito no da ROOKIE, e no da ELITE devolve o texto
           picado em pedaços de palavra — "p elo m ódulo d e p rocessamento",
           e o artigo 12 vira "Art. 1 2", que o parser leria como artigo 1.
         - `-raw` preserva as palavras inteiras nos três, mas devolve UMA
           PALAVRA POR LINHA, que a reconstrução abaixo remonta.
         - o Ghostscript é o único que existe no servidor da Hostinger.

       Por isso todas rodam e a escolha é por medida, não por preferência. */
    $tentativas = [
        ['pdftotext', ['-layout', '-enc', 'UTF-8', $pdfPath, $saida], false],
        ['pdftotext', ['-raw', '-enc', 'UTF-8', $pdfPath, $saida], true],
        ['gs', ['-q', '-dNOPAUSE', '-dBATCH', '-sDEVICE=txtwrite',
                '-sOutputFile=' . $saida, $pdfPath], false],
    ];

    // O descarte do stderr muda de shell: o servidor é Linux, mas quem
    // desenvolve roda no Windows, e lá "2>/dev/null" cria um arquivo chamado
    // "null" e o comando falha.
    $devNull = stripos(PHP_OS_FAMILY, 'Windows') === 0 ? '2>NUL' : '2>/dev/null';

    $melhor = null; $melhorNota = -1;
    foreach ($tentativas as [$bin, $args, $remontar]) {
        $cmd = escapeshellcmd($bin) . ' ' . implode(' ', array_map('escapeshellarg', $args)) . ' ' . $devNull;
        @exec($cmd, $_, $code);
        if ($code !== 0 || !is_file($saida) || filesize($saida) < 200) continue;

        $bruto = @file_get_contents($saida);
        if ($bruto === false || trim($bruto) === '') continue;

        $t = editalNormalizar($remontar ? editalRemontarLinhas($bruto) : $bruto);
        $nota = editalNota($t);
        if ($nota > $melhorNota) { $melhorNota = $nota; $melhor = $t; }
        // Texto sem defeito nenhum não tem como melhorar: para por aqui.
        if ($nota >= 100) break;
    }
    @unlink($saida);

    return $melhor;
}

/**
 * O quanto este texto está inteiro, de 0 a 100.
 *
 * Mede as três formas de estragar que os PDFs daqui produzem: número partido
 * ("Art. 1 2"), letra solta no meio da palavra ("p elo m ódulo") e PALAVRA
 * COLADA na seguinte ("AoperacionalidadedaFBAé"). Contadas por mil caracteres,
 * pra comparar extrações de tamanhos diferentes.
 *
 * A colagem entrou depois, e faltando ela a conta escolhia errado: nos editais
 * da ELITE e da NEXT o `-layout` colava 200 palavras e mesmo assim tirava 96,
 * enquanto o `-raw`, que sai sem nenhuma, tirava 80 por causa das letras
 * soltas. O guia então exibia parágrafos ilegíveis.
 *
 * Colar pesa como número partido — mais que letra solta — porque não é só
 * feio: quebra a busca por palavra e a comparação entre editais, que é o que
 * o guia inteiro faz.
 */
function editalNota(string $txt): int
{
    $mil = max(1, mb_strlen($txt) / 1000);
    $numerosPartidos = preg_match_all('/\d \d/u', $txt);
    $letrasSoltas    = preg_match_all('/(?<=\s)\p{L}\s\p{L}{1,2}(?=\s)/u', $txt);
    // Minúscula seguida de maiúscula sem espaço: em português é onde uma
    // palavra terminou e a próxima começou sem o espaço.
    $coladas         = preg_match_all('/\p{Ll}\p{Lu}/u', $txt);
    $sujeira = ($numerosPartidos * 3 + $letrasSoltas + $coladas * 3) / $mil;
    return (int)max(0, min(100, round(100 - $sujeira * 6)));
}

/**
 * Remonta o texto do `-raw`, que vem com uma palavra por linha.
 *
 * Junta tudo e reinsere a quebra só onde a estrutura do documento manda: antes
 * de "Art. N", de um parágrafo (§), de um inciso romano e de um título de
 * capítulo. É determinístico e não depende de adivinhar onde a frase acabava —
 * num edital, a estrutura ESTÁ nesses marcadores.
 */
function editalRemontarLinhas(string $bruto): string
{
    $t = preg_replace('/[\x{200B}\x{200C}\x{200D}\x{FEFF}]/u', '', $bruto);
    $t = preg_replace('/\s+/u', ' ', $t);                        // tudo numa linha
    $t = preg_replace('/\s*\(\s*/u', ' (', $t);                  // "( progression )" -> "(progression)"
    $t = preg_replace('/\s*\)\s*/u', ') ', $t);
    $t = preg_replace('/\s+([,.;:%º°])/u', '$1', $t);            // pontuação colada de volta

    // A estrutura volta aqui: cada marcador abre uma linha.
    $t = preg_replace('/\s*(Art\.\s*\d)/u', "\n\n$1", $t);
    $t = preg_replace('/\s*(§\s*\d)/u', "\n$1", $t);
    $t = preg_replace('/\s*(Parágrafo único)/iu', "\n$1", $t);
    $t = preg_replace('/\s+([IVXL]{1,4}\.\s+\p{Lu})/u', "\n$1", $t);

    return trim($t);
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

    /* NÚMERO DE PÁGINA CAÍDO NO MEIO DA FRASE.
       Nos PDFs da ELITE e da NEXT a paginação não fica numa linha própria: ela
       aterrissa dentro do texto corrido e com os dígitos separados — "resultará
       nas 1 0 seguintes penalidades", "recebe $52 1 6 25º colocado". Some assim
       porque é essa a assinatura: dígitos SOLTOS um do outro. Número de
       verdade no edital vem junto ("35%", "$60", "13 atletas") e não casa.

       A ordem importa. O número do artigo sofre a mesma separação ("Art. 1 2°"),
       então ele é remendado ANTES — senão a limpeza comeria o número e sobraria
       "Art. °". */
    $txt = preg_replace_callback(
        '/((?:Art\.|§|n[ºo°])\s*)(\d(?:\s\d){1,2})/u',
        fn($m) => $m[1] . preg_replace('/\s+/', '', $m[2]),
        $txt
    );
    $txt = preg_replace('/(?<=\s)\d(?:\s\d){1,2}(?=\s)/u', '', $txt);
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

    editalGarantirColuna($pdo);
    $pdo->prepare("UPDATE league_settings SET edital = ? WHERE league = ?")
        ->execute([$texto, $league]);

    return ['ok' => true, 'erro' => null, 'chars' => mb_strlen($texto)];
}

/**
 * A coluna `edital` precisa ser MEDIUMTEXT, e não TEXT.
 *
 * TEXT guarda 65.535 BYTES — não caracteres. O edital da ELITE tem 66.690
 * caracteres, e em UTF-8 cada acento ocupa dois bytes, então ele passava do
 * limite com folga. O MySQL truncava e o PDO levantava "Data too long", a
 * gravação falhava, e como o texto nunca chegava ao banco a extração era
 * refeita a cada leitura: a página do guia levava 48 segundos porque
 * reprocessava os três PDFs treze vezes.
 *
 * MEDIUMTEXT vai a 16 MB, que cobre qualquer edital que esta liga venha a ter.
 */
function editalGarantirColuna(PDO $pdo): void
{
    static $feito = false;
    if ($feito) return;
    $feito = true;
    try {
        $col = $pdo->query("SHOW COLUMNS FROM league_settings LIKE 'edital'")->fetch(PDO::FETCH_ASSOC);
        if ($col && stripos((string)$col['Type'], 'mediumtext') === false) {
            $pdo->exec("ALTER TABLE league_settings MODIFY COLUMN edital MEDIUMTEXT NULL");
        }
    } catch (Throwable $e) {
        error_log('[edital] coluna: ' . $e->getMessage());
    }
}

/**
 * O texto do edital da liga, extraindo na hora se ainda não foi extraído.
 *
 * A extração sob demanda acontece uma vez por edital: da segunda chamada em
 * diante sai direto do banco.
 */
/**
 * Ligas que se regem pelo edital de outra.
 *
 * A RISE nunca teve documento próprio, e hoje quem responde por ela é o edital
 * da ROOKIE. Registrar isso aqui é diferente de copiar o PDF pra ela: a liga
 * continua sem edital PRÓPRIO — o que aparece na tela e no bot é o da ROOKIE,
 * dito com todas as letras.
 *
 * Some daqui no dia em que a RISE tiver o seu.
 */
const EDITAL_HERDA_DE = ['RISE' => 'ROOKIE'];

function editalTexto(PDO $pdo, string $league): ?string
{
    $league = strtoupper(trim($league));

    // O próprio primeiro: subir o PDF da liga passa a valer na hora, sem que
    // ninguém precise lembrar de desfazer a herança.
    $proprio = editalTextoProprio($pdo, $league);
    if ($proprio !== null) return $proprio;

    $herda = EDITAL_HERDA_DE[$league] ?? null;
    return $herda !== null ? editalTextoProprio($pdo, $herda) : null;
}

/** O edital cadastrado PARA esta liga, sem herança nenhuma. */
function editalTextoProprio(PDO $pdo, string $league): ?string
{
    $league = strtoupper(trim($league));

    /* Cache por request. A página do guia pergunta pelo texto de cada liga uma
       vez por assunto — treze vezes — e sem isto seriam treze idas ao banco
       por liga, cada uma trazendo 60 KB de volta. Pior: quando a extração
       falha, `editalTexto` cai no sincronizar, e treze falhas viram treze
       reprocessamentos dos PDFs. */
    static $memoria = [];
    if (array_key_exists($league, $memoria)) return $memoria[$league];

    try {
        $st = $pdo->prepare("SELECT edital FROM league_settings WHERE league = ?");
        $st->execute([$league]);
        $txt = (string)($st->fetchColumn() ?: '');
        if (mb_strlen($txt) >= 500) return $memoria[$league] = $txt;

        $r = editalSincronizar($pdo, $league);
        // O null também entra no cache: liga sem edital não pode custar uma
        // tentativa de extração por seção da página.
        if (!$r['ok']) return $memoria[$league] = null;

        $st->execute([$league]);
        return $memoria[$league] = ((string)($st->fetchColumn() ?: '') ?: null);
    } catch (Throwable $e) {
        error_log('[edital] texto ' . $league . ': ' . $e->getMessage());
        return $memoria[$league] = null;
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
        /* `[\d\s]` no número, e não `\d`: mesmo com a extração escolhida pela
           nota, um PDF pode entregar "Art. 1 2°". Lendo só o primeiro dígito,
           o artigo 12 entraria na lista como artigo 1 — e o edital passaria a
           ter dois artigos 1, com o 12 inalcançável por /edital 12. */
        if (preg_match('/^\s*Art\.\s*(\d[\d\s]{0,3}?)\s*[º°]?\s*\./u', $l, $m)) {
            $marcos[] = ['num' => (int)preg_replace('/\s+/', '', $m[1]), 'inicio' => $i];
        }
    }

    $out = [];
    foreach ($marcos as $k => $a) {
        $fim = isset($marcos[$k + 1]) ? $marcos[$k + 1]['inicio'] - 1 : count($linhas) - 1;
        $corpo = trim(implode("\n", array_slice($linhas, $a['inicio'], $fim - $a['inicio'] + 1)));
        if ($corpo === '') continue;

        /* ARTIGO FANTASMA: o texto CITA um artigo ("... na forma do Art. 41.")
           e a citação cai no começo de uma linha, então vira um marco igual ao
           de verdade — só que sem corpo nenhum além do próprio "Art. 41.".
           Isso criava um segundo artigo 41 na ELITE e na NEXT, e o guia
           anunciava numeração repetida que não existe no documento.

           Sobrar nada depois do marcador é o que separa a citação do artigo:
           artigo tem texto, citação não. */
        if (trim(preg_replace('/^\s*Art\.\s*\d[\d\s]{0,3}?\s*[º°]?\s*\.\s*/u', '', $corpo)) === '') continue;
        $out[] = [
            'num'      => $a['num'],
            'capitulo' => $capDaLinha($a['inicio']),
            'texto'    => $corpo,
        ];
    }
    return $out;
}
