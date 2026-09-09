<?php
/**
 * RESPONDER DÚVIDAS DO EDITAL, COM O EDITAL NA MÃO.
 *
 * O GM não pergunta "artigo 41" — ele pergunta "e se eu estourar o cap?". Isso
 * não é busca por palavra: é ler o edital inteiro e responder o que acontece.
 * Por isso aqui tem um modelo lendo o texto, e não um índice.
 *
 * A REGRA DA CASA É NÃO INVENTAR. O edital vai inteiro no system, a resposta
 * tem que citar o artigo, e quando a resposta não está lá o certo é dizer que
 * não está — um bot que chuta regra de liga é pior que um bot que não responde,
 * porque a pessoa age achando que está amparada.
 *
 * HTTP na unha, e não o SDK oficial: o `vendor/` deste projeto foi instalado à
 * mão no servidor e não é versionado, então uma dependência nova não chega lá
 * pelo git push que faz o deploy. Todo o resto do projeto que fala com fora
 * (WhatsApp, push, NBA) usa curl_init do mesmo jeito.
 */

require_once __DIR__ . '/edital_texto.php';

/**
 * DOIS PROVEDORES, E O GEMINI VEM PRIMEIRO.
 *
 * A liga não vai pagar por isso. O free tier do Gemini responde de graça e com
 * folga pro tamanho da FBA: o edital inteiro dá ~17,5 mil tokens de entrada e
 * cabe tranquilo na janela, e o limite diário é de centenas de perguntas.
 *
 * O modelo pode ser trocado pela variável EDITAL_IA_MODELO, sem deploy — e é
 * bom que dê, porque o Google aposenta modelo pra conta nova sem aviso: o
 * `gemini-2.5-flash` que estava aqui respondeu 404 dizendo "no longer
 * available to new users" no dia em que a chave nova foi criada.
 *
 * O caminho da Anthropic continua aqui e é usado se a chave dela estiver
 * configurada e a do Gemini não. Quem escolhe é qual chave existe no ambiente,
 * não uma opção em tela: é decisão de quem administra o servidor.
 */
const EDITAL_IA_MODELO_GEMINI    = 'gemini-3.1-flash-lite';
const EDITAL_IA_MODELO_ANTHROPIC = 'claude-opus-5';

/**
 * A fila de modelos, na ordem em que são tentados.
 *
 * O free tier vive dando 503 "high demand" — medido: numa mesma bateria de
 * testes o mesmo modelo respondeu em 2,6s e, no pedido seguinte, recusou. Um
 * bot que responde "tenta de novo" metade das vezes não serve, então o pedido
 * cai pro próximo da fila em vez de desistir.
 *
 * O primeiro é o mais rápido e o que melhor respondeu ao edital nos testes; os
 * outros entram só quando ele não está disponível.
 */
const EDITAL_IA_MODELOS_GEMINI = [
    'gemini-3.1-flash-lite',
    'gemini-3.5-flash-lite',
    'gemini-3.7-flash',
    'gemini-3.6-flash',
];

/**
 * TRAVA DE USO, do nosso lado.
 *
 * O Google não tem botão de "nunca cobrar": orçamento lá só dispara alerta por
 * e-mail, não corta o serviço. Então o corte é aqui — passou da conta do dia,
 * o bot para de perguntar e avisa, em vez de continuar gastando.
 *
 * Serve pras duas coisas: se um dia houver faturamento, é teto de gasto; sem
 * faturamento, evita queimar a cota gratuita de manhã e ficar sem à tarde.
 */
/* 400, e não 150.
   O número foi escolhido quando uma pergunta era UMA chamada. Com a conversa
   multi-turno, uma pergunta de dado custa de 2 a 4 — o modelo consulta,
   recebe, às vezes corrige e só então responde. Com 150 a cota acabava em
   ~40 perguntas, e acabou mesmo, num dia de teste. 400 mantém a trava (o free
   tier do flash-lite dá bem mais que isso) e cobre um dia de grupo movimentado. */
const EDITAL_IA_LIMITE_DIA = 400;

/* Teto de resposta. Resposta de grupo de WhatsApp é curta — mas no Gemini o
   raciocínio do modelo sai DESTE mesmo orçamento, e o 2.5-flash não deixa
   desligar (o mínimo é "low"). Apertado demais, a resposta volta vazia com
   finishReason MAX_TOKENS: o modelo gastou tudo pensando. Por isso a folga. */
const EDITAL_IA_MAX_TOKENS = 1200;
const EDITAL_IA_MAX_TOKENS_GEMINI = 4000;
/* Desistir rapido e tentar outro vale mais que esperar.
   Medido: um modelo saudavel responde em 1 a 5 segundos; passando disso ele
   esta congestionado e vai estourar o timeout de qualquer jeito. Entao as
   primeiras tentativas sao curtas e so a ULTIMA ganha folga, que e quando nao
   ha mais pra quem recorrer. */
const EDITAL_IA_TIMEOUT          = 30;
const EDITAL_IA_TIMEOUT_ULTIMA   = 45;

/**
 * Uma chave, de onde ela estiver: variável de ambiente ou `config.php`.
 *
 * O ambiente vem primeiro, que é o certo. Mas na Hostinger definir variável de
 * ambiente pra PHP é passo de painel que se perde na próxima migração, e o
 * `backend/config.php` já existe lá, já guarda senha de banco e NÃO é
 * versionado (está no .gitignore) — é onde o resto do projeto guarda segredo.
 *
 * Nunca no config.sample nem no config.local: esses vão pro git.
 */
function editalIaSegredo(string $nome): string
{
    $doAmbiente = trim((string)(getenv($nome) ?: ''));
    if ($doAmbiente !== '') return $doAmbiente;

    try {
        require_once __DIR__ . '/helpers.php';
        $cfg = loadConfig();
        $ia = $cfg['ia'] ?? [];
        return trim((string)($ia[$nome] ?? ''));
    } catch (Throwable $e) {
        return '';
    }
}

function editalIaChaveGemini(): string
{
    return editalIaSegredo('GEMINI_API_KEY');
}

function editalIaChave(): string
{
    return editalIaSegredo('ANTHROPIC_API_KEY');
}

/** Qual provedor atende agora: 'gemini', 'anthropic' ou null. */
function editalIaProvedor(): ?string
{
    if (editalIaChaveGemini() !== '') return 'gemini';
    if (editalIaChave() !== '')       return 'anthropic';
    return null;
}

/** O modelo em uso, com a variável de ambiente podendo trocar sem deploy. */
function editalIaModelo(string $provedor): string
{
    $env = trim((string)(getenv('EDITAL_IA_MODELO') ?: ''));
    if ($env !== '') return $env;
    return $provedor === 'gemini' ? EDITAL_IA_MODELO_GEMINI : EDITAL_IA_MODELO_ANTHROPIC;
}

function editalIaLigada(): bool
{
    return editalIaProvedor() !== null;
}

/** Quantas perguntas cabem por dia. Zero ou negativo = sem trava. */
function editalIaLimiteDia(): int
{
    $env = trim((string)(getenv('EDITAL_IA_LIMITE_DIA') ?: ''));
    return $env !== '' ? (int)$env : EDITAL_IA_LIMITE_DIA;
}

function editalIaGarantirTabelaUso(PDO $pdo): void
{
    static $feito = false;
    if ($feito) return;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS edital_ia_uso (
            dia DATE NOT NULL PRIMARY KEY,
            chamadas INT NOT NULL DEFAULT 0,
            tokens_entrada BIGINT NOT NULL DEFAULT 0,
            tokens_saida BIGINT NOT NULL DEFAULT 0,
            atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
    } catch (Throwable $e) {
        error_log('[edital_ia] tabela de uso: ' . $e->getMessage());
    }
    $feito = true;
}

/** Quantas perguntas já foram feitas hoje. */
function editalIaUsoDeHoje(PDO $pdo): int
{
    editalIaGarantirTabelaUso($pdo);
    try {
        $st = $pdo->prepare('SELECT chamadas FROM edital_ia_uso WHERE dia = CURDATE()');
        $st->execute();
        return (int)($st->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * Marca mais uma pergunta no contador do dia.
 *
 * Conta a CHAMADA, e não o sucesso: pedido que falhou no meio do caminho
 * também consumiu cota lá do outro lado.
 */
function editalIaRegistrarUso(PDO $pdo, int $entrada = 0, int $saida = 0): void
{
    editalIaGarantirTabelaUso($pdo);
    try {
        $pdo->prepare('INSERT INTO edital_ia_uso (dia, chamadas, tokens_entrada, tokens_saida)
                       VALUES (CURDATE(), 1, ?, ?)
                       ON DUPLICATE KEY UPDATE chamadas = chamadas + 1,
                                               tokens_entrada = tokens_entrada + VALUES(tokens_entrada),
                                               tokens_saida = tokens_saida + VALUES(tokens_saida)')
            ->execute([$entrada, $saida]);
    } catch (Throwable $e) {
        error_log('[edital_ia] registrar uso: ' . $e->getMessage());
    }
}

/**
 * COMO A LIGA ESTÁ CONFIGURADA HOJE, lido do banco.
 *
 * O edital é um PDF: ele congela no dia em que foi escrito, e a liga continua
 * andando. A ROOKIE é o exemplo — o edital dela fala em 10 temporadas por
 * sprint, e no app são 15. Sem estes números o bot repetia o PDF com toda a
 * confiança do mundo e mandava o GM planejar a franquia errado.
 *
 * Aqui vai o que o SISTEMA diz, que é o que de fato acontece quando o GM
 * clica. Onde os dois discordam, manda este bloco.
 */
function editalIaFatosDoApp(PDO $pdo, string $league): string
{
    // EDITAL_LIGAS_SEM_MOEDA mora no guia, que não é carregado por este
    // arquivo — sem isto a função morria no meio e o bloco saía vazio.
    require_once __DIR__ . '/edital_guia.php';

    $l = [];
    try {
        $st = $pdo->prepare('SELECT c.max_seasons, s.cap_min, s.cap_max, s.cap_mode,
                                    s.max_trades, s.trades_enabled, s.fa_enabled,
                                    (SELECT COUNT(*) FROM teams t WHERE t.league = c.league) AS times
                               FROM league_sprint_config c
                          LEFT JOIN league_settings s ON s.league = c.league
                              WHERE c.league = ?');
        $st->execute([$league]);
        $c = $st->fetch(PDO::FETCH_ASSOC);
        if (!$c) return '';

        $l[] = "COMO A {$league} ESTÁ CONFIGURADA NO APP HOJE";
        $l[] = '(dados lidos do sistema agora; onde isto discordar do edital, VALE ISTO)';
        $l[] = '';
        $l[] = "- Times na liga: {$c['times']}";
        $l[] = "- Temporadas por sprint: {$c['max_seasons']}";

        /* O cap muda de natureza entre as ligas, e confundir os dois é o erro
           mais comum de quem vem de outra liga. Fora da ELITE ele soma os
           CAP_TOP_N melhores, e não o elenco inteiro — quem acha que conta
           todo mundo calcula errado quanto tem de espaço. O número sai da
           constante que o app usa de verdade pra somar. */
        require_once __DIR__ . '/helpers.php';
        $l[] = ($c['cap_mode'] ?? '') === 'salary'
            ? "- Cap: por SALÁRIO (folha do elenco), de {$c['cap_min']} a {$c['cap_max']}"
            : "- Cap: soma do OVR dos " . CAP_TOP_N . " MELHORES do elenco (não é o elenco todo), "
              . "de {$c['cap_min']} a {$c['cap_max']}";

        $l[] = "- Trocas por temporada: {$c['max_trades']}";
        $l[] = '- Trocas agora: ' . (!empty($c['trades_enabled']) ? 'ABERTAS' : 'FECHADAS');
        $l[] = '- Free agency agora: ' . (!empty($c['fa_enabled']) ? 'ABERTA' : 'FECHADA');
        $l[] = '- Moedas: ' . (in_array($league, EDITAL_LIGAS_SEM_MOEDA, true)
            ? 'a liga NÃO usa moedas (o edital ainda fala delas; não valem mais)'
            : 'a liga usa moedas na free agency e no leilão');

        $st = $pdo->prepare("SELECT sp.sprint_number, MAX(se.season_number) AS temp
                               FROM sprints sp LEFT JOIN seasons se ON se.sprint_id = sp.id
                              WHERE sp.league = ? AND sp.status = 'active'
                           GROUP BY sp.sprint_number LIMIT 1");
        $st->execute([$league]);
        if ($s = $st->fetch(PDO::FETCH_ASSOC)) {
            $l[] = "- Momento: sprint {$s['sprint_number']}, temporada {$s['temp']} de {$c['max_seasons']}";
        }
    } catch (Throwable $e) {
        error_log('[edital_ia] fatos do app: ' . $e->getMessage());
        return '';
    }
    return implode("\n", $l);
}

/**
 * OS DETALHES DE OPERAÇÃO QUE NÃO ESTÃO EM LUGAR NENHUM.
 *
 * Nem no edital (que fala da regra, não da tela) nem no banco (que guarda o
 * estado, não o macete). É o que o GM veterano sabe e o novato descobre
 * perguntando no grupo — que é exatamente o que este bot existe pra evitar.
 *
 * Lista pra crescer: cada dúvida que aparecer duas vezes no grupo vira linha
 * aqui. Só entra o que foi conferido no código, e não o que se supõe.
 */
function editalIaDetalhesDoApp(): string
{
    return implode("\n", [
        'DETALHES DE COMO O APP FUNCIONA NA PRÁTICA',
        '(não está no edital; é o funcionamento real das telas)',
        '',
        '- Aposentar jogador: a opção só aparece pra quem tem MAIS DE 35 anos, ou seja,',
        '  36 em diante. Não aparecendo, é porque o atleta está com menos que isso',
        '  cadastrado — corrija a idade dele em Meu Elenco (editar jogador) e a opção surge.',
        '  O sistema recusa aposentadoria de quem tem 35 ou menos, então não adianta insistir.',
        '',
        /* Conferido em api/draft.php: ensureRound2DeadlineSet() só liga o
           relógio quando current_round vira 2, ROUND2_PREFERENCIAS é 5, e
           resolveRound2MocksIfDue() deixa a vaga em aberto quando não há
           preferência livre. É a dúvida que mais volta no grupo em dia de
           draft, e a parte do "só começa quando a 1ª acaba" é a que some das
           respostas quando não está escrita como fato. */
        '- 2ª RODADA DO DRAFT: é toda de uma vez, sem vez de ninguém.',
        '  - O cronômetro de 20 MINUTOS só COMEÇA QUANDO A 1ª RODADA TERMINA. Enquanto a 1ª',
        '    estiver rolando, a 2ª nem abriu e o relógio não está correndo — não existe prazo',
        '    de 20 minutos valendo antes disso.',
        '  - Aberta a 2ª, todas as picks dela ficam disponíveis ao mesmo tempo, e cada vaga',
        '    aceita até 5 preferências em ordem.',
        '  - Quando o prazo vence, o sistema resolve da pick mais alta pra mais baixa: a vaga',
        '    leva a 1ª preferência que ainda estiver livre; se todas já foram, desce a lista.',
        '  - Quem deixou o mock da 1ª rodada ligado tem a fila dele aproveitada como',
        '    preferência das vagas de 2ª que estiverem vazias.',
        '  - Vaga sem nenhuma preferência livre fica EM ABERTO e o admin preenche depois.',
    ]);
}

/** As telas do site, pro bot saber ONDE se faz cada coisa. */
function editalIaComoUsarOApp(): string
{
    try {
        require_once __DIR__ . '/edital_guia.php';
        $l = ['ONDE SE FAZ CADA COISA NO SITE (fbabrasil.com.br)', ''];
        foreach (editalPaginas() as $grupo => $telas) {
            foreach ($telas as [$url, $nome, $desc]) $l[] = "- {$nome} ({$url}): {$desc}";
        }
        return implode("\n", $l);
    } catch (Throwable $e) {
        return '';
    }
}

/**
 * O que o modelo pode e não pode fazer.
 *
 * Escrito curto de propósito: cada regra aqui é uma coisa que dá errado no
 * grupo se faltar. O tom é o de um GM veterano respondendo no grupo, porque é
 * onde a resposta vai cair — e não o de quem lê regulamento em voz alta.
 */
function editalIaInstrucoes(string $league, ?array $quem = null, ?array $citados = null): string
{
    $linhas = [
        "Você é o assistente da FBA Brasil, uma liga de fantasy de basquete no NBA 2K.",
        "Quem pergunta é um GM da liga {$league}, no grupo de WhatsApp. Você responde",
        'QUALQUER pergunta sobre a liga — como o app funciona, o que diz a regra, e também',
        'os dados: campeões, classificação, elencos, OVR, estatística, trocas, picks.',
        '',
        /* O bot respondeu as dispensas do Pelicans (ROOKIE) com a regra do
           waiver, que é só da ELITE, e concluiu que o time nunca dispensou
           ninguém. A liga do grupo é o padrão, não uma venda nos olhos. */
        "A LIGA MANDA NA RESPOSTA. O grupo é da {$league}, e é dela que você fala por padrão.",
        '- Sem liga dita, é a do grupo: "quem lidera?" é o líder dela, não da ELITE.',
        '- Mas se a pergunta cita um TIME, a liga é a DAQUELE time — descubra com teams.league',
        '  antes de responder. O Pelicans é da ROOKIE mesmo que o grupo seja outro.',
        '- As ligas NÃO funcionam igual. Cap, moedas, waiver e limites mudam entre elas. Nunca',
        '  aplique a regra da ELITE a time de outra liga: confira na configuração e no esquema',
        '  qual vale pra liga em questão.',
        '',
        'DADOS: você tem a ferramenta consultar_dados, que roda SELECT no banco da liga.',
        '- SEMPRE dentro da SPRINT ATUAL. O bloco "A SPRINT ATUAL" tem os season_id de cada liga;',
        '  toda consulta com temporada filtra por eles. Ciclo antigo não vale e confunde: existe',
        '  mais de uma "T1" no banco, e a que interessa é a desta sprint.',
        '- Pergunta sobre FATO da liga? Consulte. Não responda de cabeça e não estime.',
        '  "Quem foi campeão da T1", "quem mais foi aos playoffs", "qual lenda mais evoluiu",',
        '  "compare o jogador X com o Y" — tudo isso é consulta, não é memória.',
        '- Consultou e não veio nada? Diga que não achou. Não preencha o buraco com suposição.',
        '- Erro na consulta: leia a mensagem, corrija e tente de novo. Você tem poucas tentativas,',
        '  então pense na consulta antes de mandar.',
        '- Traga o número na resposta. "O Mafia foi campeão da T1 com 52-30" vale mais que',
        '  "o Mafia foi bem".',
        '- NUNCA cite id de banco na resposta ("o usuário de ID 43", "o time 51"). Id é coisa de',
        '  dentro; quem perguntou quer nome. Sem o nome, diga que não achou.',
        '',
        'MEMÓRIA: a liga te ensina o vocabulário dela, e você guarda com a ferramenta lembrar.',
        /* Os exemplos usam nome INVENTADO de propósito. Com um time real
           dentro ("chama o Blue Foxes de patinho"), o modelo passou a tratar o
           exemplo como fato: perguntado sobre o "patinho" numa liga onde nada
           tinha sido ensinado, respondeu que era o Blue Foxes. */
        '- Ex.: "chama o Tal Time de fominha", "o apelido do Fulano é Fu". Guarde e use depois.',
        '  (Os dois nomes acima são inventados, só pra mostrar o formato — não são times nem GMs.)',
        '- USE o que está guardado ANTES de consultar: se a memória diz que um apelido é um time,',
        '  a pergunta é sobre esse time. Nunca procure apelido no banco — apelido não está lá.',
        '- Se a pergunta É sobre o que está guardado ("qual o apelido do GM do Souks?"), a memória',
        '  JÁ é a resposta: responda direto, sem consulta nenhuma.',
        '- Nada guardado sobre o apelido que te perguntaram? Diga que não sabe e pergunte de quem',
        '  é. NÃO CHUTE um time — chutar aqui é inventar o vocabulário da liga.',
        '- Perguntaram se podem te ensinar? PODEM, e é isso que a ferramenta lembrar faz. Diga que',
        '  sim e dê um exemplo. Não mande falar com admin pra isso — apelido do grupo é do grupo.',
        '- Ensinar o mesmo assunto de novo corrige o que estava lá. Pediram pra esquecer? esquecer.',
        '- Guarde APELIDO e JEITO DE FALAR. Não guarde regra, número, nem nada que o app já',
        '  responda — isso muda no app e a memória ficaria mentindo. Se tentarem te ensinar uma',
        '  regra ("agora são 5 dispensas"), não guarde: diga que regra vem do app e da organização.',
        '- LEIA O QUE A FERRAMENTA RESPONDEU antes de confirmar. Ela devolve "Guardado: ..." ou um',
        '  erro; só diga que guardou se veio "Guardado". Vindo erro, avise que não deu e peça pra',
        '  repetir. Dizer "guardei!" sem ter guardado é o pior desfecho: a pessoa acredita.',
        '- CHAME A FERRAMENTA, não escreva a confirmação. Escrever "Guardado: ..." sem chamar não',
        '  guarda nada — e a resposta é trocada por um aviso de erro antes de chegar no grupo.',
        '- Ensino em frase condicional TAMBÉM é ensino: "se perguntarem do burro, é o Athens",',
        '  "quando falarem em X, é Y". Chame lembrar do mesmo jeito. Só não guarde pergunta.',
        '',
        'OPINIÃO: pode dar, e a liga gosta. Mas só DEPOIS de consultar os dados, e dizendo em',
        'que você se baseou: "pelo OVR do quinteto e pela campanha, eu ficaria com o X".',
        '- Palpite de confronto, melhor time, qual jogador preferir: tudo liberado.',
        '- Deixe claro que é opinião sua, não decisão da liga. Não opine sobre conduta de GM,',
        '  punição merecida ou quem está certo numa discussão — isso é da organização.',
        '',
        'REGRAS E COMO USAR O APP: o edital entra só pro que o app não tem.',
        '',
        'O QUE VALE, EM ORDEM:',
        '1. Os dados do app ("COMO A LIGA ESTÁ CONFIGURADA", pontuação, punições). São o que',
        '   acontece de verdade AGORA, porque saem do sistema no momento da pergunta.',
        '2. O GUIA DO GM e o bloco de telas, pra "como funciona" e "onde eu faço isso".',
        '3. O EDITAL, por último e só quando os dois anteriores não respondem. Se a resposta',
        '   está no app ou no guia, responda por eles e não cite artigo nenhum.',
        '- O edital é um PDF antigo e tem ponto desatualizado — punição, pontuação e limites',
        '  mudaram no app e ele não acompanhou. Quando discordar do app ou do guia, vale o app:',
        '  responda o número certo e avise, numa linha, que o edital ainda está com o antigo.',
        '- Não invente. Não sabendo, diga que não sabe e mande falar com a organização.',
        '  Chutar regra de liga é pior que não responder: a pessoa age achando que está amparada.',
        '- Não invente NÚMERO que não esteja nas fontes: quantas punições expulsam, quantos avisos',
        '  valem o quê. Se o número não está aqui, é porque a decisão é da organização, caso a caso.',
        '',
        'COMO FALAR:',
        '- Como um GM veterano explicando pro novato, não como advogado lendo o regulamento.',
        '- Traduza o juridiquês. "Sanção pecuniária progressiva" vira "a multa aumenta a cada vez".',
        '- Diga SEMPRE onde se faz a coisa no app: "isso é na aba Trades", "no card CAP do',
        '  Dashboard". É o que a pessoa foi buscar; a regra sozinha não resolve o problema dela.',
        '- Cite artigo SÓ quando a resposta vier do edital. Explicando uma tela ou um número do',
        '  app, citar artigo confunde — dá a entender que a fonte é o PDF quando não é.',
        '',
        'FORMATO:',
        // 8 e não 6: resposta com dados precisa caber a lista. Continua sendo
        // teto de mensagem de grupo, não de relatório.
        '- Português do Brasil, direto, no máximo 8 linhas.',
        '- Sem saudação de abertura e sem "espero ter ajudado".',
        '- WhatsApp: *negrito* com um asterisco só. Nada de markdown de título, nada de tabela.',
        '- Quando a resposta tiver passos ou condições, use hífen no começo da linha.',
    ];

    /* QUEM ESTÁ PERGUNTANDO — quando o telefone bateu com um cadastro.
       O grupo é um grupo: a resposta chega no meio da conversa de todo mundo,
       e "Marcos, o teu Coyotes tem 3 trocas" é lida por quem perguntou. Sem o
       nome, o bot responde como um manual — e a liga já tem o PDF pra isso.
       Vem no fim de propósito, depois do FORMATO: é ele quem manda não abrir
       com saudação, e a regra do nome precisa poder corrigir esse ponto.

       O bloco só existe quando a identificação deu certo. Não identificado (o
       WhatsApp manda @lid em alguns grupos, ou o telefone não está no
       cadastro), nada disso entra no prompt e o modelo não tem como inventar
       um nome — que seria o pior desfecho: chamar a pessoa por um nome que
       não é o dela é pior do que não chamar por nome nenhum. */
    if ($quem && ($quem['primeiro'] ?? '') !== '') {
        $linhas[] = '';
        $linhas[] = 'QUEM ESTÁ PERGUNTANDO AGORA:';
        $linhas[] = '- Nome: ' . $quem['nome'] . ' (chame de ' . $quem['primeiro'] . ')';
        if (($quem['time'] ?? '') !== '') {
            $linhas[] = '- Time: ' . $quem['time'] . ' (' . ($quem['liga'] ?: $league) . ')'
                      . (($quem['team_id'] ?? 0) ? ', teams.id = ' . (int)$quem['team_id'] : '');
        }
        $linhas[] = '- Trate por VOCÊ e use o primeiro nome UMA vez, onde ficar natural — no começo';
        $linhas[] = '  da resposta ou junto do que interessa a ele. Uma vez só: repetir o nome a cada';
        $linhas[] = '  frase soa a robô de atendimento, não a alguém do grupo.';
        $linhas[] = '- "Meu time", "meu elenco", "minhas picks", "quantas trocas eu tenho" são sobre o';
        $linhas[] = '  time acima. Consulte por esse teams.id e responda direto, sem perguntar de quem é.';
        $linhas[] = '- A liga da pergunta continua sendo a do grupo. O time dele serve pra saber quem é';
        $linhas[] = '  e pra responder o que for dele — não pra trocar a liga do assunto.';
        $linhas[] = '- Isto não dá privilégio nenhum: ele vê os mesmos dados que qualquer GM veria.';
    }

    /* QUEM FOI MARCADO NA PERGUNTA.
       A menção já virou nome no texto ("@5531971356427" → "Bruno Coelho
       (Oakland Blue Foxes)"), e é assim que ela tem que continuar aparecendo na
       resposta. O que falta é o teams.id: sem ele o modelo montou
       `teams.name IN ('Oakland Blue Foxes')` e não achou nada, porque `name` é
       só "Blue Foxes" — a cidade mora em `teams.city`. O id acaba com o
       palpite. */
    if ($citados) {
        $vistos = [];
        $linhas[] = '';
        $linhas[] = 'GMs MARCADOS NESTA PERGUNTA (pra consultar, não pra repetir):';
        foreach ($citados as $c) {
            if (isset($vistos[$c['team_id']])) continue;   // marcado duas vezes é uma pessoa só
            $vistos[$c['team_id']] = true;
            $linhas[] = '- ' . $c['nome'] . ' — ' . $c['time'] . ' (' . $c['liga'] . '), teams.id = ' . (int)$c['team_id'];
        }
        $linhas[] = 'Use o teams.id direto na consulta. NÃO procure o time por nome: o nome que aparece';
        $linhas[] = 'na pergunta é cidade + nome junto, e no banco isso são duas colunas.';
        $linhas[] = 'Na resposta, chame a pessoa pelo primeiro nome — nunca pelo número nem pelo id.';
    }

    return implode("\n", $linhas);
}

/**
 * Manda a pergunta pro modelo com o edital no contexto.
 *
 * @return array{ok:bool,resposta:?string,erro:?string,uso:?array}
 */
function editalIaPerguntar(PDO $pdo, string $league, string $pergunta, ?array $quem = null, ?array $citados = null): array
{
    $erro = fn(string $m) => ['ok' => false, 'resposta' => null, 'erro' => $m, 'uso' => null];

    $provedor = editalIaProvedor();
    if ($provedor === null) return $erro('A consulta ao edital ainda não foi ligada aqui.');

    $pergunta = trim($pergunta);
    if (mb_strlen($pergunta) < 5)  return $erro('Escreve a dúvida junto do comando. Ex.: /edital posso trocar jogador emprestado?');
    if (mb_strlen($pergunta) > 500) return $erro('Pergunta muito longa — resume em uma frase.');

    $edital = editalTexto($pdo, $league);
    if ($edital === null) return $erro("Não achei o edital da {$league} pra consultar.");

    /* A TRAVA VEM ANTES DE GASTAR. Conferir depois de perguntar não seria
       trava nenhuma: o pedido já teria saído. */
    $limite = editalIaLimiteDia();
    if ($limite > 0 && editalIaUsoDeHoje($pdo) >= $limite) {
        return $erro('O limite de consultas de hoje já foi usado. '
                   . 'Amanhã volta, ou pergunta pra organização.');
    }

    if ($provedor === 'gemini') {
        return editalIaPerguntarGemini($pdo, $league, $edital, $pergunta, $erro, $quem, $citados);
    }

    $chave = editalIaChave();

    /* O EDITAL VAI EM BLOCO PRÓPRIO E CACHEADO.
       Ele é o mesmo texto em toda pergunta e responde por quase todo o custo
       do pedido; sem o cache, cada dúvida no grupo paga o edital inteiro de
       novo. O bloco das instruções vem DEPOIS, e é o último ponto de cache:
       tudo que varia (a pergunta) fica fora do prefixo cacheado. */
    $payload = [
        'model'      => editalIaModelo('anthropic'),
        'max_tokens' => EDITAL_IA_MAX_TOKENS,
        // Dúvida de regulamento não pede raciocínio longo, e o bot responde no
        // meio de uma conversa: esforço baixo é resposta boa e rápida.
        'output_config' => ['effort' => 'low'],
        'system' => [
            [
                'type' => 'text',
                'text' => "EDITAL DA LIGA {$league} — FBA BRASIL\n\n" . $edital,
                'cache_control' => ['type' => 'ephemeral'],
            ],
            [
                'type' => 'text',
                // SEM cache aqui: as instruções agora carregam quem perguntou, e
                // isso muda a cada GM. O edital, que é o caro, tem o ponto de
                // cache dele logo acima e continua sendo reaproveitado.
                'text' => editalIaInstrucoes($league, $quem, $citados),
            ],
        ],
        'messages' => [
            ['role' => 'user', 'content' => $pergunta],
        ],
    ];

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => EDITAL_IA_TIMEOUT,
        CURLOPT_HTTPHEADER     => [
            'content-type: application/json',
            'x-api-key: ' . $chave,
            'anthropic-version: 2023-06-01',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
    $corpo  = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $falha  = curl_error($ch);
    curl_close($ch);

    if ($corpo === false || $falha !== '') {
        error_log('[edital_ia] curl: ' . $falha);
        return $erro('Não consegui consultar o edital agora. Tenta de novo em um minuto.');
    }

    $j = json_decode((string)$corpo, true);
    if ($status !== 200 || !is_array($j)) {
        error_log('[edital_ia] http ' . $status . ': ' . mb_substr((string)$corpo, 0, 400));
        return $erro('Não consegui consultar o edital agora. Tenta de novo em um minuto.');
    }

    /* Recusa do modelo chega com HTTP 200 e stop_reason "refusal" — o content
       vem vazio, então ler o texto sem conferir isto devolveria string vazia
       como se fosse resposta. */
    if (($j['stop_reason'] ?? '') === 'refusal') {
        return $erro('Não consegui responder essa. Fala com a organização.');
    }

    $texto = '';
    foreach (($j['content'] ?? []) as $bloco) {
        if (($bloco['type'] ?? '') === 'text') $texto .= $bloco['text'];
    }
    $texto = trim($texto);
    if ($texto === '') return $erro('Vieram só linhas vazias. Tenta reformular a pergunta.');

    return ['ok' => true, 'resposta' => $texto, 'erro' => null, 'uso' => $j['usage'] ?? null];
}

/**
 * Quantas vezes o modelo pode consultar o banco antes de responder.
 *
 * Três cobre o que aparece na prática: uma consulta que erra a coluna, a
 * correção, e a resposta. Mais que isso costuma ser o modelo insistindo numa
 * pergunta que os dados não respondem — e aí o certo é ele dizer que não achou.
 */
const DUVIDA_MAX_RODADAS = 3;

/**
 * Uma ida ao Gemini, com a fila de modelos como plano B.
 *
 * Separado do resto porque agora a conversa tem várias idas: o modelo pede
 * dados, recebe, e só então responde. Antes era uma só e o loop de modelos
 * podia morar junto do resto.
 *
 * @return array{0:bool, 1:?array, 2:?array} [ok, json da resposta, erro pronto]
 */
function editalIaChamarGemini(PDO $pdo, array $payload, callable $erro): array
{
    $corpoJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    // O modelo escolhido à mão manda; senão, a fila padrão.
    $escolhido = trim((string)(getenv('EDITAL_IA_MODELO') ?: ''));
    $fila = $escolhido !== '' ? [$escolhido] : EDITAL_IA_MODELOS_GEMINI;

    $ultimoErro = null;
    foreach ($fila as $i => $modelo) {
        // O primeiro tem o tempo todo; os seguintes são plano B e não podem
        // deixar o GM esperando um minuto e meio cada.
        $ultima  = ($i === count($fila) - 1);
        $timeout = $ultima ? EDITAL_IA_TIMEOUT_ULTIMA : EDITAL_IA_TIMEOUT;

        /* CONTA ANTES DE GASTAR, e não depois.
           A chamada pode levar dezenas de segundos, e nesse tempo a conexão
           com o MySQL cai — medido: depois de um pedido de 40s, tanto gravar
           quanto ler o contador falhavam calados e ele voltava a zero. Contar
           antes também é o que faz a trava valer: a cota é reservada, não
           registrada no fim.

           Conta a CHAMADA, e não o sucesso: pedido que falhou no meio do
           caminho consumiu cota do outro lado do mesmo jeito. */
        editalIaRegistrarUso($pdo);

        $ch = curl_init('https://generativelanguage.googleapis.com/v1beta/models/'
                      . rawurlencode($modelo) . ':generateContent');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            // A chave vai no cabeçalho, e não na query: URL vaza em log de
            // acesso, de proxy e de erro.
            CURLOPT_HTTPHEADER     => [
                'content-type: application/json',
                'x-goog-api-key: ' . editalIaChaveGemini(),
            ],
            CURLOPT_POSTFIELDS => $corpoJson,
        ]);
        $corpo  = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $falha  = curl_error($ch);
        curl_close($ch);

        if ($corpo === false || $falha !== '') {
            error_log("[duvida/gemini] {$modelo} curl: " . $falha);
            $ultimoErro = 'Não consegui responder agora. Tenta de novo em um minuto.';
            continue;
        }

        $j = json_decode((string)$corpo, true);

        /* O teto diário é o erro esperado do free tier, e merece resposta
           própria: "tenta de novo em um minuto" mandaria a pessoa insistir à
           toa até o dia seguinte. Não adianta trocar de modelo — a cota é da
           chave. */
        if ($status === 429) {
            error_log("[duvida/gemini] {$modelo} 429: " . editalIaSemSegredo((string)$corpo));
            return [false, null, $erro('O limite de consultas de hoje acabou. Tenta mais tarde, '
                                     . 'ou pergunta pra organização.')];
        }

        // 503 é fila do modelo, e 404 é modelo que saiu do ar pra contas novas:
        // nos dois casos o próximo da fila pode atender.
        if ($status === 503 || $status === 404 || $status >= 500) {
            error_log("[duvida/gemini] {$modelo} http {$status}, tentando o próximo");
            $ultimoErro = 'Não consegui responder agora. Tenta de novo em um minuto.';
            continue;
        }

        if ($status !== 200 || !is_array($j)) {
            error_log("[duvida/gemini] {$modelo} http {$status}: " . editalIaSemSegredo((string)$corpo));
            return [false, null, $erro('Não consegui responder agora. Tenta de novo em um minuto.')];
        }

        // Bloqueio por filtro de conteúdo vem com 200 e sem candidato nenhum.
        if (!empty($j['promptFeedback']['blockReason'])) {
            return [false, null, $erro('Não consegui responder essa. Fala com a organização.')];
        }

        $j['__modelo'] = $modelo;
        return [true, $j, null];
    }

    return [false, null, $erro($ultimoErro ?? 'Não consegui responder agora.')];
}

/**
 * A conversa com o Gemini, com o banco à disposição.
 *
 * O modelo recebe as fontes de texto (regras, guia, edital) E uma ferramenta
 * pra consultar os dados. Pergunta de regra ele responde direto; pergunta de
 * fato — quem foi campeão, quem tem mais OVR — ele consulta e responde com o
 * que voltou. É a diferença entre um bot que sabe as regras e um que conhece
 * a liga.
 */
function editalIaPerguntarGemini(PDO $pdo, string $league, string $edital, string $pergunta, callable $erro, ?array $quem = null, ?array $citados = null): array
{
    require_once __DIR__ . '/duvida_contexto.php';
    require_once __DIR__ . '/duvida_dados.php';

    /* A ORDEM É A DA PRECEDÊNCIA, e ela mudou quando o /edital virou /duvida.
       Antes o PDF vinha primeiro e era o assunto; agora ele é a última fonte.
       Quem chega no grupo pergunta "como eu faço" e "quanto é hoje" — o
       edital responde "qual é a regra", e em ponto importante ele está velho.
       Então: o que o app diz agora, o guia que explica o app, as regras que a
       organização mexe pelo painel, e o edital pro que sobrar. */
    $partes = [];

    $fatos = editalIaFatosDoApp($pdo, $league);
    if ($fatos !== '') $partes[] = ['text' => $fatos];

    $regras = duvidaRegrasDoApp($pdo, $league);
    if ($regras !== '') $partes[] = ['text' => $regras];

    $guia = duvidaTextoDoGuia();
    if ($guia !== '') {
        $partes[] = ['text' => "GUIA DO GM — como o app funciona, explicado pra quem chegou\n\n" . $guia];
    }

    $telas = editalIaComoUsarOApp();
    if ($telas !== '') $partes[] = ['text' => $telas];

    $partes[] = ['text' => editalIaDetalhesDoApp()];

    // O esquema do banco vai junto: sem ele o modelo escreve consulta com
    // coluna inventada e queima uma rodada aprendendo o que já podia saber.
    $partes[] = ['text' => duvidaEsquemaParaIA($pdo)];

    // Logo depois do esquema, e antes de tudo mais: é o recorte que vale pra
    // toda consulta que ele for escrever.
    $sprint = duvidaSprintAtual($pdo);
    if ($sprint !== '') $partes[] = ['text' => $sprint];

    // O vocabulário que a liga ensinou. Vai depois do esquema e antes das
    // instruções, que é onde ele avisa o que fazer (e o que não fazer) com isso.
    require_once __DIR__ . '/duvida_memoria.php';
    $memoria = duvidaMemoriaTexto($pdo, $league);
    if ($memoria !== '') $partes[] = ['text' => $memoria];

    /* O EDITAL NAO VEM MAIS AQUI: virou ferramenta (buscar_no_edital).
       Sao 17,5 mil tokens, e com a conversa multi-turno isso passou a ser pago
       em CADA rodada. Medido: com ele dentro, o modelo estourava os 15s sem
       comecar a responder, o pedido caia de modelo em modelo e a pergunta
       terminava em 100 segundos com "nao consegui". Fora do prompt ele continua
       ao alcance, e so pra pergunta de regra. Ver duvidaBuscarNoEdital(). */

    // As instruções por último: a regra de precedência é lida com todas as
    // fontes já na mão.
    $partes[] = ['text' => editalIaInstrucoes($league, $quem, $citados)];

    $tools = [[
        'function_declarations' => [[
            'name' => 'consultar_dados',
            'description' =>
                'Roda uma consulta SQL de LEITURA no banco da FBA e devolve as linhas. '
              . 'Use sempre que a resposta depender de um dado da liga: campeões, '
              . 'classificação, elenco, OVR, estatística, trocas, picks, histórico de '
              . 'jogador. Não invente número que você pode consultar. '
              . 'Só SELECT, uma instrução, nas tabelas listadas no esquema.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'sql' => [
                        'type' => 'string',
                        'description' => 'A consulta SELECT, em MySQL. Use LIMIT.',
                    ],
                    'motivo' => [
                        'type' => 'string',
                        'description' => 'Em poucas palavras, o que você quer descobrir com ela.',
                    ],
                ],
                'required' => ['sql'],
            ],
        ], [
            'name' => 'buscar_no_edital',
            'description' =>
                'Procura artigos do edital da liga por palavra. Use só quando a pergunta for '
              . 'de REGRA e nem o app nem o guia responderem — o edital é a última fonte e '
              . 'tem ponto desatualizado. Devolve até 4 artigos.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'termo' => [
                        'type' => 'string',
                        'description' => 'Palavras do assunto. Ex.: "leilão lance", "punição pick".',
                    ],
                ],
                'required' => ['termo'],
            ],
        ], [
            'name' => 'lembrar',
            'description' =>
                'Guarda um apelido ou jeito de falar do grupo, pra usar nas próximas conversas. '
              . 'Chame quando alguém ENSINAR algo: "chama o Blue Foxes de patinho", "o Marcos é '
              . 'o Medeiros". Ensinar o mesmo assunto de novo SUBSTITUI o que estava lá — é assim '
              . 'que se corrige. Não guarde regra, número nem nada que o app já responda.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'assunto' => ['type' => 'string',
                        'description' => 'A quem ou a que isso se refere. Ex.: "Oakland Blue Foxes".'],
                    'fato' => ['type' => 'string',
                        'description' => 'O que lembrar. Ex.: "o grupo chama de patinho".'],
                ],
                'required' => ['assunto', 'fato'],
            ],
        ], [
            'name' => 'esquecer',
            'description' => 'Apaga o que foi guardado sobre um assunto. Chame quando pedirem '
                           . 'pra esquecer ou disserem que não é mais assim.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'assunto' => ['type' => 'string', 'description' => 'O assunto a esquecer.'],
                ],
                'required' => ['assunto'],
            ],
        ]],
    ]];

    $contents = [['role' => 'user', 'parts' => [['text' => $pergunta]]]];

    /* O modelo já anunciou "Guardado: o time do burro é o Athens" sem ter
       chamado lembrar — a frase saiu igualzinha à que a ferramenta devolve, e
       nada foi gravado. Aconteceu no grupo, com o Kevyn, e é o pior tipo de
       erro: todo mundo acredita e só descobre na pergunta seguinte.
       Instrução não basta contra isso; aqui a confirmação passa a depender de
       um fato, e não da boa vontade do modelo. */
    $gravouMesmo = false;

    for ($rodada = 1; $rodada <= DUVIDA_MAX_RODADAS; $rodada++) {
        /* NA ÚLTIMA RODADA ELE É OBRIGADO A RESPONDER.
           Sem isso o modelo pedia dados até o fim e nunca escrevia a resposta:
           "qual time vai vencer a próxima temporada" consultava campanha,
           ranking e elenco, acabavam as rodadas, e o GM recebia "não consegui
           fechar". Ele já tinha tudo na mão; faltava dizer que era hora.

           O jeito é `tool_config` com mode NONE, e não tirar `tools` do
           payload. Tirar foi a primeira tentativa e saiu pior: o histórico
           tem functionCall e functionResponse, e sem a declaração das funções
           o Gemini devolve texto VAZIO com finishReason STOP — medido, na
           pergunta sobre quem o Coyotes eliminou. As declarações ficam, o
           direito de chamar é que sai. */
        $ultimaRodada = ($rodada === DUVIDA_MAX_RODADAS);

        $payload = [
            'system_instruction' => ['parts' => $partes],
            'contents' => $contents,
            'generationConfig' => [
                'maxOutputTokens' => EDITAL_IA_MAX_TOKENS_GEMINI,
                // Regra é leitura, não criação. Opinião sobre time e jogador
                // sai boa mesmo assim: ela vem dos dados, não da temperatura.
                'temperature'     => 0.3,

                /* SEM thinkingConfig, de propósito.
                   Testado contra a API: `thinking_level` não existe no v1beta, e
                   `thinkingConfig.thinkingBudget = 0` é recusado com 400 pelos
                   modelos 3.x — neles o raciocínio não desliga. Como ele sai do
                   mesmo orçamento da resposta, o teto acima é generoso: apertado,
                   o modelo gasta tudo pensando e devolve texto vazio. */
            ],
        ];
        $payload['tools'] = $tools;
        if ($ultimaRodada) {
            /* Os dois nomes, de propósito: o REST do v1beta documenta
               camelCase, o resto deste payload usa snake_case e funciona, e
               o campo que o modelo não conhecer ele ignora. Mandar os dois
               custa nada e cobre a versão que estiver do outro lado. */
            $modo = ['function_calling_config' => ['mode' => 'NONE']];
            $payload['tool_config'] = $modo;
            $payload['toolConfig']  = ['functionCallingConfig' => ['mode' => 'NONE']];
            // E o pedido em português, que é o que o modelo lê de verdade.
            $contents[] = ['role' => 'user', 'parts' => [['text' =>
                'Agora responda a pergunta com o que você já consultou. Não peça mais dados. '
              . 'Se o que você tem não responde, diga isso em uma frase.']]];
            $payload['contents'] = $contents;
        }

        [$ok, $j, $err] = editalIaChamarGemini($pdo, $payload, $erro);
        if (!$ok) return $err;

        $cand = $j['candidates'][0] ?? null;
        $partesResposta = $cand['content']['parts'] ?? [];

        // O modelo pediu dados? Roda, devolve, e a conversa continua.
        $chamadas = [];
        $texto = '';
        foreach ($partesResposta as $parte) {
            if (isset($parte['functionCall'])) $chamadas[] = $parte['functionCall'];
            if (isset($parte['text']))         $texto .= $parte['text'];
        }

        if ($chamadas && $rodada < DUVIDA_MAX_RODADAS) {
            $contents[] = ['role' => 'model', 'parts' => $partesResposta];
            $respostas = [];
            foreach ($chamadas as $c) {
                $nome = (string)($c['name'] ?? 'consultar_dados');
                if ($nome === 'buscar_no_edital') {
                    $termo = (string)($c['args']['termo'] ?? '');
                    $resultado = duvidaBuscarNoEdital($pdo, $league, $termo);
                    error_log('[duvida/edital] busca: ' . $termo);
                } elseif ($nome === 'lembrar') {
                    // Agora dá pra saber QUEM ensinou: a coluna ensinado_por
                    // existe desde o começo e vinha sempre nula, porque o bot
                    // não sabia com quem estava falando.
                    $resultado = duvidaMemoriaGravar($pdo, $league,
                        (string)($c['args']['assunto'] ?? ''),
                        (string)($c['args']['fato'] ?? ''),
                        $quem['nome'] ?? null);
                    // Guarda o desfecho pra conferir a resposta no fim: o modelo
                    // já disse "Guardado" sem ter chamado esta função.
                    if (str_starts_with($resultado, 'Guardado')) $gravouMesmo = true;
                    error_log('[duvida/memoria] ' . $resultado);
                } elseif ($nome === 'esquecer') {
                    $resultado = duvidaMemoriaApagar($pdo, $league,
                        (string)($c['args']['assunto'] ?? ''));
                    error_log('[duvida/memoria] esquecer: ' . $resultado);
                } else {
                    $r = duvidaConsultar($pdo, (string)($c['args']['sql'] ?? ''));
                    // O motivo entra no log: "RECUSADA" sozinho não separava
                    // consulta proibida de erro do banco, e as duas apareciam
                    // iguais quando a conexão caía.
                    error_log('[duvida/sql] ' . ($r['ok'] ? 'ok' : 'FALHOU (' . $r['erro'] . ')') . ': ' . $r['sql']);
                    $resultado = duvidaResultadoParaIA($r);
                }
                $respostas[] = ['functionResponse' => [
                    'name'     => $nome,
                    'response' => ['resultado' => $resultado],
                ]];
            }
            $contents[] = ['role' => 'user', 'parts' => $respostas];
            continue;
        }

        $texto = trim($texto);
        if ($texto === '') {
            $motivo = (string)($cand['finishReason'] ?? '');
            error_log('[duvida/gemini] resposta vazia, finishReason=' . $motivo
                    . ($chamadas ? ' (pediu dados na última rodada)' : ''));
            if ($chamadas) {
                // Gastou as rodadas consultando e não concluiu. Insistir aqui
                // custaria outra chamada pra provavelmente repetir o ciclo.
                return $erro('Essa eu não consegui fechar com os dados que achei. Tenta perguntar de outro jeito.');
            }
            return $erro($motivo === 'MAX_TOKENS'
                ? 'A resposta ficou longa demais e foi cortada. Tenta uma pergunta mais específica.'
                : 'Vieram só linhas vazias. Tenta reformular a pergunta.');
        }

        $texto = duvidaCorrigirFalsoGuardado($texto, $gravouMesmo);

        return ['ok' => true, 'resposta' => $texto, 'erro' => null,
                'uso' => ($j['usageMetadata'] ?? []) + ['modelo' => $j['__modelo'] ?? '?']];
    }

    return $erro('Essa eu não consegui fechar. Tenta perguntar de outro jeito.');
}

/**
 * O corpo do erro sem a chave dentro.
 *
 * O Google DEVOLVE a chave na mensagem de erro ("Consumer 'api_key:AQ...' has
 * been suspended"), e o log de erro do site não é lugar de segredo: quem tem
 * acesso ao log passa a ter a chave. Some também o que parecer chave em
 * qualquer outro formato.
 */
function editalIaSemSegredo(string $texto, int $limite = 400): string
{
    $texto = preg_replace('/(api_key:)\s*\S+/i', '$1[oculta]', $texto);
    $texto = preg_replace('/\b(AIza|AQ\.)[A-Za-z0-9._\-]{10,}/', '[chave oculta]', $texto);
    return mb_substr($texto, 0, $limite);
}
