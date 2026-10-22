<?php
/**
 * ── LER AS ESTATÍSTICAS DE UM PRINT ──────────────────────────────────
 *
 * Dois motores, em cascata: o Google Vision primeiro, o Gemini quando ele
 * não atende.
 *
 * POR QUE DOIS. O Vision é OCR puro: devolve cada palavra com as
 * coordenadas dela, e quem remonta a tabela é o `parseStatsTable` do
 * chamador, por geometria. Funciona bem e foi o que sempre rodou aqui —
 * só que a chave vive num projeto do Google sem faturamento habilitado, e
 * desde então toda chamada volta `BILLING_DISABLED`. Não é cota estourada:
 * a cota gratuita de mil imagens por mês nem chega a contar, porque o
 * Google recusa antes de processar. Enquanto ninguém vincular um cartão
 * àquele projeto, o Vision vai recusar todo dia.
 *
 * O Gemini não é OCR, é leitura: ele devolve a tabela já entendida, em
 * JSON. Não tem coordenadas e por isso não passa pelo `parseStatsTable` —
 * entrega as linhas prontas. A chave dele já roda neste servidor (é a
 * mesma do edital) e o plano gratuito não pede cartão.
 *
 * A ORDEM É DELIBERADA, e é o que o Marcos pediu em 21/10: "tem como
 * colocar o vision de primeiro, aí esgota ele vai pro gemini?". Hoje o
 * Vision falha sempre, então na prática quem atende é o Gemini — mas no
 * dia em que aquele projeto ganhar faturamento, o Vision volta a atender
 * sozinho, sem ninguém mexer aqui, e o Gemini passa a ser o que cobre o
 * que passar das mil leituras do mês.
 */

/** As colunas que a tela sabe gravar, na ordem em que aparecem no jogo. */
const OCR_STATS_CAMPOS = ['games', 'min_pg', 'pts_pg', 'reb_pg', 'ast_pg', 'stl_pg', 'blk_pg', 'fg_pct'];

/**
 * Quanto tempo a cascata pode gastar, no total, antes de desistir.
 *
 * Medido: uma leitura boa volta entre 16s e 72s, dependendo do modelo e de
 * como anda a fila do Google. 100s dá folga pra isso e ainda deixa margem
 * larga até os 300s em que o servidor corta.
 */
const OCR_STATS_ORCAMENTO = 100;

/**
 * Chama o Vision. Devolve as anotações com coordenadas, ou null.
 *
 * @param string|null $erro recebe a mensagem quando falha
 */
function ocrStatsVision(string $imagemBase64, string $chave, ?string &$erro = null): ?array
{
    $erro = null;
    if ($chave === '' || $chave === 'API_KEY') { $erro = 'Vision sem chave configurada.'; return null; }

    $ch = curl_init('https://vision.googleapis.com/v1/images:annotate?key=' . urlencode($chave));
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => json_encode([
            'requests' => [[
                'image'    => ['content' => $imagemBase64],
                'features' => [['type' => 'TEXT_DETECTION', 'maxResults' => 2000]],
            ]],
        ]),
    ]);
    $resposta = curl_exec($ch);
    $falhaCurl = curl_error($ch);
    curl_close($ch);

    if ($resposta === false || $falhaCurl !== '') { $erro = 'Vision fora do ar: ' . $falhaCurl; return null; }

    $dados = json_decode((string)$resposta, true);
    if (!empty($dados['error'])) {
        $erro = 'Vision: ' . ($dados['error']['message'] ?? 'erro desconhecido');
        return null;
    }
    $anotacoes = $dados['responses'][0]['textAnnotations'] ?? [];
    if (!$anotacoes) { $erro = 'Vision não achou texto na imagem.'; return null; }
    return $anotacoes;
}

/**
 * Chama o Gemini e devolve as linhas JÁ no formato de `$detected`.
 *
 * O schema vai declarado na requisição: sem isso o modelo às vezes embrulha
 * o JSON em markdown ou inventa um campo, e o parse do outro lado quebra.
 *
 * @param string      $mime  o tipo da imagem, como veio do data: URI
 * @param string|null $erro  recebe a mensagem quando falha
 */
function ocrStatsGemini(string $imagemBase64, string $mime, string $chave, ?string &$erro = null): ?array
{
    $erro = null;
    if ($chave === '') { $erro = 'Gemini sem chave configurada.'; return null; }

    $instrucao = <<<TXT
    Esta é a captura de uma tabela de estatísticas de jogadores de basquete,
    na aba "Per Game" (médias por jogo).

    Devolva uma linha por jogador da tabela, com:
      name   = o nome como está escrito na coluna NAME, sem a posição
      games  = a coluna GP (número inteiro de jogos)
      min_pg = MIN, pts_pg = PTS, reb_pg = REB, ast_pg = AST,
      stl_pg = STL, blk_pg = BLK  (todos com uma casa decimal)
      fg_pct = a coluna FG% (deixe nulo se essa coluna não aparecer)

    Regras que importam:
    - São MÉDIAS POR JOGO: quase sempre têm uma casa decimal (0.8, 24.6).
      Se um número aparecer sem o ponto, recoloque a casa decimal — "246"
      nessas colunas é 24.6, não 246. Só games é inteiro de verdade.
    - Não invente jogador que não está na tabela, e não complete valor que
      você não conseguiu ler: deixe nulo.
    - Ignore as colunas GS, TO, FLS, 3P%, FT% e POS.
    TXT;

    /* ── SEM responseSchema, E ISSO FOI MEDIDO ───────────────────────
       O jeito "certo" de pedir JSON à API é declarar um responseSchema.
       Medido nesta chave, em 21/10, três formatos sobre a mesma imagem:

         texto puro .................. HTTP 200 em 16s, de primeira
         responseMimeType json ....... 503, e 200 na segunda tentativa
         responseMimeType + schema ... 90s sem resposta, depois 503

       Com schema a chamada não completou nenhuma vez. No plano gratuito
       ele deixa o pedido pesado demais, e um leitor de print que falha é
       pior do que um que devolve JSON embrulhado em markdown — o que o
       `ocrStatsExtrairJson` aqui embaixo desembrulha sem reclamar. */
    $corpo = json_encode([
        'contents' => [[
            'role'  => 'user',
            'parts' => [
                ['text' => $instrucao],
                ['inline_data' => ['mime_type' => $mime, 'data' => $imagemBase64]],
            ],
        ]],
        'generationConfig' => ['temperature' => 0],
    ], JSON_UNESCAPED_UNICODE);

    /* ── SÓ OS "LITE", E ISSO É COTA, NÃO QUALIDADE ──────────────────
       No plano gratuito os modelos `flash` COMPLETOS têm 20 requisições por
       dia — medido em 21/10, quando o `gemini-3.5-flash` devolveu
       "Quota exceeded ... limit: 20" e mandou esperar quatro horas. Vinte
       por dia não atende uma liga de 122 times: uma tarde de atualização de
       elenco esgota isso antes do primeiro intervalo.

       Os `flash-lite` têm cota larga e seguiram respondendo no mesmo
       instante em que o completo recusava. Então a cadeia é só de lite —
       não porque leiam melhor, mas porque estão lá quando o GM aperta o
       botão. Nos testes o lite acertou 48 de 48 valores, o que basta.

       Mais de um lite na fila porque o 503 "high demand" vai e volta no
       mesmo modelo de um minuto pro outro; daí também as DUAS tentativas
       por modelo. */
    $modelos = ['gemini-3.1-flash-lite', 'gemini-3.5-flash-lite', 'gemini-flash-lite-latest'];
    if (defined('EDITAL_IA_MODELO_GEMINI') && str_contains(EDITAL_IA_MODELO_GEMINI, 'lite')) {
        array_unshift($modelos, EDITAL_IA_MODELO_GEMINI);
        $modelos = array_values(array_unique($modelos));
    }

    /* ── ORÇAMENTO DE TEMPO, E ELE EXISTE POR CONTA ──────────────────
       Quatro modelos × duas tentativas × 60s dá 480s no pior caso, e o
       servidor corta em 300 (max_execution_time no .htaccess). O pior caso
       chegaria no GM como um erro feio de servidor depois de cinco minutos
       olhando o botão girar. Aqui a cascata desiste antes disso e devolve
       uma frase que explica. */
    $limite = microtime(true) + OCR_STATS_ORCAMENTO;

    $ultimo = 'Gemini não respondeu.';
    foreach ($modelos as $modelo) {
      for ($tentativa = 1; $tentativa <= 2; $tentativa++) {
        if (microtime(true) >= $limite) {
            $erro = 'Os leitores de imagem estão demorando demais agora. Tente de novo em um minuto.';
            error_log('[ocr_stats/gemini] orçamento de ' . OCR_STATS_ORCAMENTO . 's estourado; último: ' . $ultimo);
            return null;
        }
        $ch = curl_init('https://generativelanguage.googleapis.com/v1beta/models/'
                      . rawurlencode($modelo) . ':generateContent');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
            // A chave vai no cabeçalho, não na query: URL vaza em log de
            // acesso, de proxy e de erro.
            CURLOPT_HTTPHEADER     => ['content-type: application/json', 'x-goog-api-key: ' . $chave],
            CURLOPT_POSTFIELDS     => $corpo,
        ]);
        $resposta = curl_exec($ch);
        $status   = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $falha    = curl_error($ch);
        curl_close($ch);

        if ($resposta === false || $falha !== '') { $ultimo = 'Gemini fora do ar: ' . $falha; continue; }
        $j = json_decode((string)$resposta, true);
        if ($status !== 200 || !empty($j['error'])) {
            $ultimo = 'Gemini: ' . ($j['error']['message'] ?? "HTTP {$status}");
            error_log("[ocr_stats/gemini] {$modelo} t{$tentativa}: {$ultimo}");
            if ($status === 404) break;     // modelo aposentado: nem tenta de novo
            continue;                        // 503 costuma passar na segunda
        }

        $texto  = $j['candidates'][0]['content']['parts'][0]['text'] ?? '';
        $linhas = ocrStatsExtrairJson($texto);
        if (!is_array($linhas)) { $ultimo = 'Gemini devolveu algo que não é tabela.'; continue; }

        $saida = [];
        foreach ($linhas as $l) {
            $nome = trim((string)($l['name'] ?? ''));
            if ($nome === '') continue;
            $item = ['name' => $nome];
            foreach (OCR_STATS_CAMPOS as $campo) {
                $v = $l[$campo] ?? null;
                if ($campo === 'fg_pct') {
                    // null é "a coluna não saiu no print", e não 0%.
                    $item[$campo] = ($v === null || $v === '') ? null : (float)$v;
                } elseif ($campo === 'games') {
                    $item[$campo] = (int)($v ?? 0);
                } else {
                    $item[$campo] = (float)($v ?? 0);
                }
            }
            $saida[] = $item;
        }
        if (!$saida) { $ultimo = 'Gemini não achou jogador na imagem.'; continue; }
        return $saida;
      }
    }

    $erro = $ultimo;
    return null;
}

/**
 * Tira o array JSON de uma resposta que pode vir embrulhada.
 *
 * Sem o responseSchema o modelo às vezes responde com o JSON dentro de uma
 * cerca de markdown, ou com uma frase antes. Em vez de exigir que ele se
 * comporte, pega-se o primeiro `[` até o último `]`.
 */
function ocrStatsExtrairJson(string $texto): ?array
{
    $texto = trim($texto);
    if ($texto === '') return null;

    $direto = json_decode($texto, true);
    if (is_array($direto)) return $direto;

    // ```json ... ``` ou ``` ... ```
    if (preg_match('~```(?:json)?\s*(.+?)\s*```~is', $texto, $m)) {
        $dentro = json_decode(trim($m[1]), true);
        if (is_array($dentro)) return $dentro;
        $texto = trim($m[1]);
    }

    $ini = strpos($texto, '[');
    $fim = strrpos($texto, ']');
    if ($ini === false || $fim === false || $fim <= $ini) return null;
    $recorte = json_decode(substr($texto, $ini, $fim - $ini + 1), true);
    return is_array($recorte) ? $recorte : null;
}

/**
 * Separa um data: URI em mime e base64.
 *
 * @return array{0:string,1:string} o base64 puro e o mime (image/png por padrão)
 */
function ocrStatsSepararImagem(string $bruto): array
{
    $mime = 'image/png';
    if (preg_match('~^data:(image/[a-z0-9.+-]+);base64,~i', $bruto, $m)) {
        $mime = strtolower($m[1]);
    }
    $b64 = str_contains($bruto, 'base64,') ? explode('base64,', $bruto, 2)[1] : $bruto;
    return [trim($b64), $mime];
}
