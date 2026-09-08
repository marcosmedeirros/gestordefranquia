<?php
/**
 * O QUE O BOT LÊ ANTES DE RESPONDER UMA DÚVIDA.
 *
 * O /edital nasceu com uma fonte só — o PDF — e é por isso que ele não servia
 * pro que a liga queria. O PDF responde "qual é a regra"; a pergunta que chega
 * no grupo é "como eu faço isso" e "quanto é isso hoje". E parte do edital
 * está velha: punição, pontuação e limites mudaram no app e o papel não mudou.
 *
 * Aqui a ordem se inverte. Primeiro o que o app diz AGORA (que é verdade por
 * definição), depois o guia (que explica o app pra quem chegou), e o edital
 * por último, pro que os dois não cobrem.
 *
 * Tudo em cache estático: uma pergunta carrega isto uma vez, e o guia é o
 * pedaço caro.
 */

require_once __DIR__ . '/helpers.php';

/**
 * O GUIA DO GM, em texto.
 *
 * Lido do próprio guia.php e não de uma cópia: transcrever criaria uma segunda
 * versão da explicação, que envelheceria calada no dia em que a página fosse
 * editada — o mesmo motivo pelo qual o guia único não copia os artigos.
 *
 * O PHP embutido sai fora antes: a página tem blocos que só o servidor
 * resolve, e o que sobra deles no meio do texto é ruído pro modelo.
 */
function duvidaTextoDoGuia(): string
{
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = '';

    $arquivo = dirname(__DIR__) . '/guia.php';
    if (!is_readable($arquivo)) return $cache;

    $html = (string)file_get_contents($arquivo);

    // Fora o que não é conteúdo: PHP, script, estilo e comentário de HTML.
    $html = preg_replace('/<\?php.*?\?>/s', ' ', $html);
    $html = preg_replace('/<\?=.*?\?>/s', ' ', $html);
    $html = preg_replace('#<(script|style|head)\b[^>]*>.*?</\1>#is', ' ', $html);
    $html = preg_replace('/<!--.*?-->/s', ' ', $html);

    /* Título e item viram linha de verdade. Sem isto o texto sai como um
       parágrafo único de 20 mil caracteres, e o modelo perde a estrutura que
       diz o que é assunto e o que é passo. */
    $html = preg_replace('#</(h2|h3|h4|p|li|div|section|td|tr)>#i', "\n", $html);
    $html = preg_replace('#<(h2|h3|h4)\b[^>]*>#i', "\n## ", $html);
    $html = preg_replace('#<li\b[^>]*>#i', '- ', $html);
    $html = preg_replace('#<br\s*/?>#i', "\n", $html);

    $texto = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $texto = preg_replace('/[ \t]+/u', ' ', $texto);
    $texto = preg_replace('/\n\s*\n\s*\n+/u', "\n\n", $texto);
    $texto = trim($texto);

    // Corta o cabeçalho da página, que é menu e não conteúdo: o guia começa
    // de verdade no primeiro "## ".
    $inicio = mb_strpos($texto, '## ');
    if ($inicio !== false) $texto = mb_substr($texto, $inicio);

    return $cache = $texto;
}

/**
 * As regras que MUDAM e vivem no app: pontuação e punição.
 *
 * São justamente as que o edital erra hoje. A pontuação sai da mesma função
 * que o /pontuacao usa, e as punições saem do catálogo que o admin aplica —
 * as duas fontes que a organização mexe sem reeditar PDF nenhum.
 */
function duvidaRegrasDoApp(PDO $pdo, string $league): string
{
    $l = [];

    try {
        require_once __DIR__ . '/pontuacao_ranking.php';
        if (function_exists('pontuacaoTextoBot')) {
            $p = trim((string)pontuacaoTextoBot($league));
            if ($p !== '') {
                $l[] = 'QUANTO VALE CADA CONQUISTA (pontuação do ranking, como está no app)';
                $l[] = $p;
                $l[] = '';
            }
        }
    } catch (Throwable $e) {
        error_log('[duvida] pontuação: ' . $e->getMessage());
    }

    try {
        $l = array_merge($l, duvidaLinhasDePunicao($pdo, $league));
    } catch (Throwable $e) {
        error_log('[duvida] punições: ' . $e->getMessage());
    }

    $l[] = duvidaRegrasDaOrganizacao();

    return implode("\n", $l);
}

/**
 * OS COMBINADOS DA ORGANIZAÇÃO que não moram em lugar nenhum.
 *
 * Nem no app (que não tem tela pra isso) nem no edital (que não os traz ou
 * traz desatualizado). São regras de operação que a organização firmou e que
 * hoje só existem na cabeça de quem está na liga há tempo — e é exatamente o
 * tipo de coisa que o novato pergunta no grupo.
 *
 * Lista pra crescer. Só entra o que a organização confirmar, escrito como ela
 * disse: aqui não há código pra conferir, então inventar detalhe é criar
 * regra. Faltando um pedaço, é melhor a linha ficar curta.
 */
function duvidaRegrasDaOrganizacao(): string
{
    return implode("\n", [
        'REGRAS DA ORGANIZAÇÃO (combinados da liga; não têm tela no app)',
        '',
        '- VETO DE TROCA / STFBA:',
        '  - Uma troca passa a ser analisada quando recebe 5 DENÚNCIAS de GMs feitas a um admin.',
        '    Não existe botão de denúncia no app: a denúncia é falar com um admin.',
        '  - Quem analisa é o STFBA. A decisão dele é FINAL — não cabe recurso e o resultado',
        '    não é revisto.',
        '  - Troca vetada pode ser REFEITA: os times podem propor de novo, em outros termos.',
        '  - Tentar refazer a mesma troca por LEILÃO é proibido. O leilão é cancelado e os GMs',
        '    envolvidos são punidos.',
    ]);
}

/**
 * O catálogo de punições e a régua do SERASA.
 *
 * "Com quantas punições eu sou expulso" é uma das perguntas que motivaram o
 * comando, e ela não tem resposta no edital: quem aplica é o admin, pelos
 * tipos cadastrados, e o SERASA conta AVISO — que é outra coisa. Dizer os dois
 * separados é o que evita a resposta errada com cara de certa.
 */
function duvidaLinhasDePunicao(PDO $pdo, string $league): array
{
    $l = [];
    $tipos = [];
    try {
        $st = $pdo->prepare("SELECT DISTINCT COALESCE(punishment_label, type) AS rotulo
                               FROM team_punishments p
                               JOIN teams t ON t.id = p.team_id
                              WHERE t.league = ? AND COALESCE(punishment_label, type) <> ''
                           ORDER BY rotulo");
        $st->execute([$league]);
        $tipos = $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Throwable $e) { /* tabela pode não existir numa instalação nova */ }

    $l[] = 'PUNIÇÕES E AVISOS (o que o app registra hoje)';
    $l[] = '- Punição e AVISO são coisas diferentes. A punição tem efeito (perder pick, ficar';
    $l[] = '  banido de trade); o aviso é registro de conduta e alimenta o FBA SERASA do time.';
    $l[] = '- O FBA SERASA é a nota de conduta que aparece na lista de times: começa cheia e cai';
    $l[] = '  a cada aviso de trade que o time leva.';
    $l[] = '- NÃO existe no app um número de punições que expulse o time automaticamente. Quem';
    $l[] = '  decide saída de GM é a organização, caso a caso. Se te perguntarem "com quantas eu';
    $l[] = '  sou expulso", responda isso e mande falar com a organização — não invente um número.';
    if ($tipos) {
        $l[] = '- Tipos já aplicados nesta liga: ' . implode(', ', array_slice($tipos, 0, 12));
    }
    $l[] = '';
    return $l;
}
