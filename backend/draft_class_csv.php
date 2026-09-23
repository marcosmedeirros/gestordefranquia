<?php
/**
 * O CSV DA CLASSE DE DRAFT — as letrinhas de cada jogador e a ordem do jogo.
 *
 * O jogo exporta a lista de prospectos com uma nota por atributo (IN, MID,
 * 3PT...) e já ordenada por RATING. Até aqui a classe era cadastrada na mão,
 * com nome/posição/OVR/idade e nada mais: o draft mostrava um jogador sem
 * como comparar com outro, e a ordem do quadro não era a do jogo.
 *
 * Duas decisões que o formato exige:
 *
 * A ORDEM É A DAS LINHAS, não o RATING. O arquivo já vem ordenado, e reordenar
 * por OVR aqui quebraria empate de um jeito diferente do jogo — dois de 76
 * trocariam de lugar. A linha 1 é o pick_hint 1.
 *
 * AS NOTAS VÃO EM JSON, numa coluna só. São dez hoje e o jogo muda a lista de
 * edição pra edição; dez colunas viram migração a cada mudança, e um JSON não.
 */

/** As colunas de nota, na ORDEM em que o jogo mostra — é a ordem da tela. */
const DRAFT_CSV_NOTAS = ['IN','MID','3PT','POST D','PER D','PLAY','REB','ATHL','IQ','POT'];

/** Sem RATING e sem AGE no arquivo, o jogador entra assim. */
const DRAFT_CSV_OVR_PADRAO  = 60;
const DRAFT_CSV_IDADE_PADRAO = 18;

/** A coluna das notas, nas duas tabelas. Idempotente. */
function draftCsvGarantirColunas(PDO $pdo): void
{
    foreach (['draft_class_template_players', 'draft_pool'] as $tabela) {
        try {
            if ($pdo->query("SHOW COLUMNS FROM {$tabela} LIKE 'notas'")->rowCount() === 0) {
                $pdo->exec("ALTER TABLE {$tabela} ADD COLUMN notas TEXT NULL");
            }
        } catch (Throwable $e) {
            error_log('[draft_csv] coluna notas em ' . $tabela . ': ' . $e->getMessage());
        }
    }
}

/** 'post d' e 'POST  D' são a mesma coluna: compara sem acento, caixa nem espaço. */
function draftCsvChave(string $s): string
{
    $s = strtoupper(trim($s));
    $s = str_replace(["\xEF\xBB\xBF", '.', '_'], '', $s);   // BOM e pontuação solta
    return preg_replace('/\s+/', ' ', $s);
}

/**
 * Lê o CSV exportado do jogo.
 *
 * Aceita vírgula, ponto-e-vírgula ou tabulação (o Excel pt-BR salva com ';' e
 * colar da tela dá tabulação), e acha as colunas pelo CABEÇALHO, não pela
 * posição — o jogo tem cinco abas de exportação e cada uma põe as colunas numa
 * ordem. Coluna que não conheço é ignorada em silêncio; o que não pode faltar
 * é NAME.
 *
 * @return array{jogadores:list<array>, erros:list<string>, ignoradas:list<string>}
 */
function draftCsvLer(string $conteudo): array
{
    $erros = [];
    $conteudo = str_replace(["\r\n", "\r"], "\n", trim($conteudo));
    if ($conteudo === '') return ['jogadores' => [], 'erros' => ['Arquivo vazio.'], 'ignoradas' => []];

    $linhas = array_values(array_filter(explode("\n", $conteudo), fn($l) => trim($l) !== ''));
    if (count($linhas) < 2) {
        return ['jogadores' => [], 'erros' => ['O arquivo tem cabeçalho e nenhuma linha de jogador.'], 'ignoradas' => []];
    }

    // O separador é o que mais aparece no cabeçalho — não dá pra assumir vírgula:
    // "POST D" não tem vírgula, mas um nome "Jr., Gary" tem.
    $sep = ',';
    $melhor = -1;
    foreach ([",", ";", "\t"] as $cand) {
        $n = count(str_getcsv($linhas[0], $cand));
        if ($n > $melhor) { $melhor = $n; $sep = $cand; }
    }

    $cab = array_map('draftCsvChave', str_getcsv($linhas[0], $sep));
    $idx = [];
    $ignoradas = [];
    $conhecidas = array_merge(['NAME','POS','POSITION','AGE','RATING','OVR'], DRAFT_CSV_NOTAS);
    foreach ($cab as $i => $nome) {
        if ($nome === '') continue;
        if (in_array($nome, $conhecidas, true)) { $idx[$nome] = $i; }
        else { $ignoradas[] = $nome; }
    }

    if (!isset($idx['NAME'])) {
        return ['jogadores' => [], 'ignoradas' => $ignoradas,
                'erros' => ['Não achei a coluna NAME no cabeçalho. Colunas lidas: ' . implode(', ', $cab)]];
    }

    $pegar = function (array $col, string $chave) use ($idx): string {
        return isset($idx[$chave]) ? trim((string)($col[$idx[$chave]] ?? '')) : '';
    };

    $jogadores = [];
    $vistos = [];
    foreach (array_slice($linhas, 1) as $n => $linha) {
        $col = str_getcsv($linha, $sep);
        $nome = $pegar($col, 'NAME');
        if ($nome === '') continue;   // linha de rodapé/separador do export

        // Nome repetido no arquivo é erro de quem montou, e importar os dois
        // faria o mesmo prospecto aparecer duas vezes na urna.
        $k = mb_strtolower($nome);
        if (isset($vistos[$k])) { $erros[] = "Linha " . ($n + 2) . ": \"{$nome}\" aparece mais de uma vez."; continue; }
        $vistos[$k] = true;

        $ovrTxt = $pegar($col, 'RATING') !== '' ? $pegar($col, 'RATING') : $pegar($col, 'OVR');
        $idaTxt = $pegar($col, 'AGE');
        $posTxt = $pegar($col, 'POS') !== '' ? $pegar($col, 'POS') : $pegar($col, 'POSITION');

        $notas = [];
        foreach (DRAFT_CSV_NOTAS as $atr) {
            $v = strtoupper($pegar($col, $atr));
            // A nota é uma letra com sinal opcional (A+, B-, F). Qualquer outra
            // coisa vira vazio em vez de ir pro banco como lixo.
            if ($v !== '' && preg_match('/^[A-F][+-]?$/', $v)) $notas[$atr] = $v;
        }

        $jogadores[] = [
            'pick_hint' => count($jogadores) + 1,   // A ORDEM É A DO ARQUIVO
            'name'      => mb_substr($nome, 0, 120),
            'position'  => mb_substr($posTxt, 0, 20),
            'ovr'       => ($ovrTxt !== '' && is_numeric($ovrTxt)) ? (int)$ovrTxt : DRAFT_CSV_OVR_PADRAO,
            'age'       => ($idaTxt !== '' && is_numeric($idaTxt)) ? (int)$idaTxt : DRAFT_CSV_IDADE_PADRAO,
            'notas'     => $notas ? json_encode($notas, JSON_UNESCAPED_UNICODE) : null,
        ];
    }

    if (!$jogadores) $erros[] = 'Nenhuma linha de jogador com NAME preenchido.';
    return ['jogadores' => $jogadores, 'erros' => $erros, 'ignoradas' => array_values(array_unique($ignoradas))];
}

/**
 * Troca os jogadores de um template pelos do CSV.
 *
 * SUBSTITUI, não soma: o arquivo é a classe inteira, e importar duas vezes
 * somando daria a classe em dobro. Em transação, porque uma classe pela metade
 * é pior que nenhuma.
 *
 * @return array{ok:bool, gravados:int, erro:?string}
 */
function draftCsvAplicarNoTemplate(PDO $pdo, int $templateId, array $jogadores): array
{
    if ($templateId <= 0 || !$jogadores) return ['ok' => false, 'gravados' => 0, 'erro' => 'Nada pra gravar.'];
    draftCsvGarantirColunas($pdo);
    try {
        $pdo->beginTransaction();
        $pdo->prepare('DELETE FROM draft_class_template_players WHERE template_id = ?')->execute([$templateId]);
        $ins = $pdo->prepare('INSERT INTO draft_class_template_players
                                (template_id, name, position, ovr, age, pick_hint, notas)
                              VALUES (?,?,?,?,?,?,?)');
        foreach ($jogadores as $j) {
            $ins->execute([$templateId, $j['name'], $j['position'], $j['ovr'], $j['age'], $j['pick_hint'], $j['notas']]);
        }
        $pdo->commit();
        return ['ok' => true, 'gravados' => count($jogadores), 'erro' => null];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[draft_csv] aplicar: ' . $e->getMessage());
        return ['ok' => false, 'gravados' => 0, 'erro' => 'Erro ao gravar a classe.'];
    }
}

/**
 * A TAG VERDE: quais templates já têm as letrinhas.
 *
 * "Tem notas" é ter nota em ALGUM jogador — classe importada pelo CSV tem em
 * todos, e classe cadastrada na mão não tem em nenhum. Devolve
 * [template_id => quantos jogadores com nota].
 */
function draftCsvTemplatesComNotas(PDO $pdo): array
{
    draftCsvGarantirColunas($pdo);
    try {
        $st = $pdo->query("SELECT template_id, COUNT(*) AS n
                             FROM draft_class_template_players
                            WHERE notas IS NOT NULL AND notas <> ''
                         GROUP BY template_id");
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(int)$r['template_id']] = (int)$r['n'];
        return $out;
    } catch (Throwable $e) {
        error_log('[draft_csv] templates com notas: ' . $e->getMessage());
        return [];
    }
}
