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

/**
 * O NOME ESTÁ CORTADO? ("L. James" em vez de "LeBron James")
 *
 * É o que o export do jogo escreve quando a coluna é estreita, e o que chega
 * no banco é o que os GMs leem pelo resto da carreira daquele jogador — na
 * urna do draft, no elenco, no /trocas do grupo.
 *
 * A classe 2025 da ELITE entrou assim e precisou ser desabreviada jogador por
 * jogador depois, cruzando com outras ligas pra descobrir de quem era cada
 * sobrenome; dois nunca deram pra resolver e continuam abreviados, porque nada
 * no app diz quem é o "A. Cardinal" de 23 anos. Recusar na porta é o único
 * momento em que o conserto é barato.
 *
 * LETRA-PONTO-ESPAÇO é o corte. "V.J. Edgecombe" e "B.H. Born" passam: ali não
 * há espaço depois do primeiro ponto, e são os nomes dos caras.
 */
function draftNomeCortado(string $nome): bool
{
    return (bool)preg_match('/^\p{L}\.\s/u', trim($nome));
}

/** A frase da recusa, igual em toda porta por onde a classe entra. */
function draftAvisoNomeCortado(array $nomes): string
{
    $mostra = array_slice($nomes, 0, 6);
    return count($nomes) . ' nome(s) vêm com o primeiro nome abreviado ('
         . implode(', ', $mostra) . (count($nomes) > count($mostra) ? ', …' : '')
         . '). Use o nome inteiro — "LeBron James", não "L. James".';
}

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

/**
 * A MESMA COLUNA COM OUTRO NOME.
 *
 * A tela de prospectos do jogo não usa os mesmos rótulos do arquivo que ele
 * exporta: lá é INS, INS D e PLMK onde o export escreve IN, POST D e PLAY.
 * Quem monta a classe copiando da tela mandava as letrinhas com esses nomes,
 * e elas eram descartadas em silêncio — a classe entrava sem nota nenhuma e
 * nada na tela dizia por quê.
 */
const DRAFT_CSV_SINONIMOS = [
    'INS'   => 'IN',        // Inside Scoring
    'INS D' => 'POST D',    // Inside Defense
    'PLMK'  => 'PLAY',      // Playmaking
];

/** 'post d' e 'POST  D' são a mesma coluna: compara sem acento, caixa nem espaço. */
function draftCsvChave(string $s): string
{
    $s = strtoupper(trim($s));
    /* Fora TUDO que não é letra, número ou espaço: o BOM, a pontuação solta e
       a setinha de ordenação que a tela cola junto do título ("▼OVR"). */
    $s = preg_replace('/[^A-Z0-9 ]+/', '', $s);
    $s = trim(preg_replace('/\s+/', ' ', $s));
    return DRAFT_CSV_SINONIMOS[$s] ?? $s;
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
    $cortados = [];
    foreach (array_slice($linhas, 1) as $n => $linha) {
        $col = str_getcsv($linha, $sep);
        $nome = $pegar($col, 'NAME');
        if ($nome === '') continue;   // linha de rodapé/separador do export

        // Nome repetido no arquivo é erro de quem montou, e importar os dois
        // faria o mesmo prospecto aparecer duas vezes na urna.
        $k = mb_strtolower($nome);
        if (isset($vistos[$k])) { $erros[] = "Linha " . ($n + 2) . ": \"{$nome}\" aparece mais de uma vez."; continue; }
        $vistos[$k] = true;

        /* PRIMEIRO NOME CORTADO NÃO ENTRA. "L. James" é o que o export do
           jogo escreve quando a coluna é estreita, e o que chega no banco é
           o que os GMs leem pelo resto da carreira daquele jogador — na
           urna do draft, no elenco, no /trocas do grupo. A classe da ELITE
           que entrou assim precisou ser desabreviada jogador por jogador
           depois, cruzando com outras ligas pra descobrir de quem era cada
           sobrenome, e cinco nunca deram pra resolver.

           Recusar na porta é o único momento em que o conserto é barato:
           quem tem o arquivo troca a coluna e importa de novo. */
        if (draftNomeCortado($nome)) { $cortados[] = $nome; continue; }

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

    if ($cortados) $erros[] = draftAvisoNomeCortado($cortados) . ' Exporte o arquivo de novo.';
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
