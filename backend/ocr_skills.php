<?php
/**
 * ── LER OS ATRIBUTOS DE UM PRINT (View List › Simple) ────────────────
 *
 * O irmão do backend/ocr_stats.php, para a outra aba do Atualizar Elenco:
 * lá são as estatísticas (PTS, REB, AST), aqui são as letrinhas de atributo
 * (IN, MID, 3PT, POST, PER, PLAY, REB, ATHL, IQ, POT) mais idade e OVR.
 *
 * Mesma cascata, pelo mesmo motivo, e vale a pena repetir o motivo: a chave
 * do Vision vive num projeto do Google sem faturamento habilitado, então
 * toda chamada volta BILLING_DISABLED. Esta tela ficou quebrada mais tempo
 * que a outra porque o conserto saiu só para as estatísticas primeiro.
 *
 * @see backend/ocr_stats.php  os helpers (ocrStatsVision, o orçamento de
 *                             tempo, o separador de data: URI e o parse
 *                             tolerante de JSON) moram lá e são usados aqui
 */
require_once __DIR__ . '/ocr_stats.php';

/** As dez colunas de atributo, com as chaves que o parse do Vision já usa. */
const OCR_SKILLS_COLUNAS = ['IN', 'MID', '3PT', 'POST', 'PER', 'PLAY', 'REB', 'ATHL', 'IQ', 'POT'];

/**
 * Lê os atributos pelo Gemini, já no formato que a tela espera.
 *
 * Devolve uma lista de ['name' => ..., 'grades' => [coluna => nota],
 * 'age' => int|null, 'rating' => int|null] — a mesma forma que
 * parseSkillTable() produz a partir do Vision.
 *
 * @param string|null $erro recebe a mensagem quando falha
 */
function ocrSkillsGemini(string $imagemBase64, string $mime, string $chave, ?string &$erro = null): ?array
{
    $erro = null;
    if ($chave === '') { $erro = 'Gemini sem chave configurada.'; return null; }

    $colunas = implode(', ', OCR_SKILLS_COLUNAS);
    $instrucao = <<<TXT
    Esta é a captura da tela "View List > Simple" de um jogo de basquete: uma
    lista de jogadores com uma NOTA EM LETRA para cada atributo.

    Devolva um array JSON, um objeto por jogador, nesta forma exata:
      {"name": "...", "age": 00, "rating": 00,
       "grades": {"IN":"B+", "MID":"C", ... }}

    Onde:
    - name   = o nome como está escrito na coluna NAME, igualzinho, inclusive
               se estiver abreviado ("J. White" fica "J. White")
    - age    = a coluna de idade (AGE), número inteiro; nulo se não aparecer
    - rating = a coluna de OVR/RATING, número inteiro; nulo se não aparecer
    - grades = um par por coluna de atributo que APARECER na imagem, usando
               exatamente estes nomes de coluna: {$colunas}

    Regras que importam:
    - As notas são letras de A a D mais F, com + ou - opcional: A+, A, A-,
      B+, B, B-, C+, C, C-, D+, D, D-, F. Nada além disso.
    - Se uma célula mostrar DUAS notas com uma seta entre elas (o jogador
      evoluiu), vale a da DIREITA, que é a atual.
    - Coluna que não aparece na imagem simplesmente não entra em "grades".
      Não invente nota, e não repita a nota de outra coluna.
    - Não invente jogador que não esteja na lista.
    - Responda SÓ o array JSON, sem texto antes ou depois.
    TXT;

    $corpo = json_encode([
        'contents' => [[
            'role'  => 'user',
            'parts' => [
                ['text' => $instrucao],
                ['inline_data' => ['mime_type' => $mime, 'data' => $imagemBase64]],
            ],
        ]],
        // Sem responseSchema, pelo mesmo motivo medido em ocr_stats.php.
        'generationConfig' => ['temperature' => 0],
    ], JSON_UNESCAPED_UNICODE);

    /* Só os "lite", por cota e não por qualidade. @see ocr_stats.php, que
       explica: os `flash` completos têm 20 requisições por dia no plano
       gratuito, o que não atende uma tarde de atualização de elenco. */
    $modelos = ['gemini-3.1-flash-lite', 'gemini-3.5-flash-lite', 'gemini-flash-lite-latest'];
    if (defined('EDITAL_IA_MODELO_GEMINI') && str_contains(EDITAL_IA_MODELO_GEMINI, 'lite')) {
        array_unshift($modelos, EDITAL_IA_MODELO_GEMINI);
        $modelos = array_values(array_unique($modelos));
    }

    $notaValida = '/^[ABCD][+-]?$|^F$/';
    $limite = microtime(true) + OCR_STATS_ORCAMENTO;
    $ultimo = 'Gemini não respondeu.';

    foreach ($modelos as $modelo) {
      for ($tentativa = 1; $tentativa <= 2; $tentativa++) {
        if (microtime(true) >= $limite) {
            $erro = 'Os leitores de imagem estão demorando demais agora. Tente de novo em um minuto.';
            error_log('[ocr_skills/gemini] orçamento estourado; último: ' . $ultimo);
            return null;
        }
        $ch = curl_init('https://generativelanguage.googleapis.com/v1beta/models/'
                      . rawurlencode($modelo) . ':generateContent');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
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
            error_log("[ocr_skills/gemini] {$modelo} t{$tentativa}: {$ultimo}");
            if ($status === 404) break;
            continue;
        }

        $linhas = ocrStatsExtrairJson($j['candidates'][0]['content']['parts'][0]['text'] ?? '');
        if (!is_array($linhas)) { $ultimo = 'Gemini devolveu algo que não é lista.'; continue; }

        $saida = [];
        foreach ($linhas as $l) {
            $nome = trim((string)($l['name'] ?? ''));
            if ($nome === '') continue;

            /* A nota passa por um filtro: o modelo pode devolver "B++", "85"
               ou "n/a", e qualquer coisa que não seja nota de verdade vira
               coluna ausente — que a tela já sabe mostrar como vazia. */
            $grades = [];
            foreach ((array)($l['grades'] ?? []) as $col => $nota) {
                $col  = strtoupper(trim((string)$col));
                $nota = strtoupper(trim((string)$nota));
                if (!in_array($col, OCR_SKILLS_COLUNAS, true)) continue;
                if (!preg_match($notaValida, $nota)) continue;
                $grades[$col] = $nota;
            }
            if (!$grades) continue;   // linha sem nenhuma nota legível não serve

            $idade = $l['age'] ?? null;
            $ovr   = $l['rating'] ?? null;
            $saida[] = [
                'name'   => $nome,
                'grades' => $grades,
                // As faixas são as mesmas que o parse do Vision já usava, e
                // existem pra barrar o número de outra coluna.
                'age'    => (is_numeric($idade) && $idade >= 15 && $idade <= 50) ? (int)$idade : null,
                'rating' => (is_numeric($ovr)   && $ovr   >= 40 && $ovr   <= 99) ? (int)$ovr   : null,
            ];
        }
        if (!$saida) { $ultimo = 'Gemini não achou jogador na imagem.'; continue; }
        return $saida;
      }
    }

    $erro = $ultimo;
    return null;
}
