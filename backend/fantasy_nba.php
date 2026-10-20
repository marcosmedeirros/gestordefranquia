<?php
/**
 * ── FANTASY NBA ──────────────────────────────────────────────────────
 *
 * O fantasy de jogador de verdade da NBA, com escalação TODO DIA.
 *
 * Pedido do dono da liga em 20/10/2026: "queria mudar ele pra NBA, com os
 * dados da NBA em si... a ideia é algo que a pessoa vai entrar todo dia pra
 * escalar a sua rodada". O Fantasy FBA (backend/fantasy.php) sai depois que
 * este estiver de pé — por isso aqui é tudo `fnba_`, sem encostar nas tabelas
 * dele: os dois precisam rodar lado a lado enquanto a troca não acontece.
 *
 * ── POR QUE A RODADA É O DIA, E NÃO A SEMANA ─────────────────────────
 *
 * É a diferença entre este e o Fantasy FBA, e é o ponto do pedido. Lá a
 * rodada é semanal porque a liga joga por semana; aqui a NBA joga quase todo
 * dia, e o que se quer é o hábito: abre, escala, dorme, volta pra ver quanto
 * fez. Uma rodada = um dia de calendário da NBA (fuso de São Paulo), e ela
 * FECHA NA HORA DO PRIMEIRO JOGO do dia — escalar depois do primeiro pulo de
 * bola seria escolher sabendo.
 *
 * ── DE ONDE VEM O DADO ───────────────────────────────────────────────
 *
 * Da API pública da ESPN, que é de graça e não pede chave. Conferido em
 * 20/10/2026, nos quatro endpoints que este arquivo usa:
 *
 *   times      /apis/site/v2/.../nba/teams                    (30 times)
 *   elenco     /apis/site/v2/.../nba/teams/{id}/roster        (17 atletas)
 *   jogos      /apis/site/v2/.../nba/scoreboard?dates=AAAAMMDD
 *   boxscore   /apis/site/v2/.../nba/summary?event={id}
 *   médias     /apis/common/v3/.../statistics/byathlete?season=AAAA
 *
 * O balldontlie foi testado antes e NÃO serve: a chave que está no
 * config.local.php abre os jogos, mas /stats responde 401 — estatística lá é
 * plano pago. A ESPN entrega o boxscore inteiro, com as seis colunas que
 * interessam, inclusive turnover.
 *
 * SEMPRE COM `--compressed`/gzip. O endpoint de médias são 6,3 MB crus e
 * 418 KB comprimidos, com os 560 jogadores da liga numa requisição só: é o
 * que permite precificar todo mundo sem 560 chamadas.
 */

require_once __DIR__ . '/db.php';

/* ─────────────────────────────────────────────────────────────────────
   A RÉGUA
   ───────────────────────────────────────────────────────────────────── */

/**
 * Quanto vale cada jogada.
 *
 * As seis que ele pediu, e só elas: "pontos, assistências, rebotes, steals,
 * blocks e turnovers (esse pra perder ponto)". Nada de arremesso certo, erro
 * de lance livre ou duplo-duplo — o jogo tem que caber numa frase.
 *
 * Os pesos são os do fantasy clássico, com uma diferença: roubo e toco valem
 * 3 e não 2, porque é o que o Fantasy FBA já usa (@see FAN_SCOUTS) e porque
 * sem isso o pivô defensivo não compete com o armador que só pontua.
 */
const FNBA_PONTOS = [
    'pts' => ['Ponto',        1.0],
    'reb' => ['Rebote',       1.2],
    'ast' => ['Assistência',  1.5],
    'stl' => ['Roubo',        3.0],
    'blk' => ['Toco',         3.0],
    'to'  => ['Erro',        -1.0],
];

/** O MÍNIMO de cada posição: 2 armadores, 2 alas, 1 pivô. */
const FNBA_FORMACAO = ['G' => 2, 'F' => 2, 'C' => 1];

/**
 * Seis escalados, cinco pontuam.
 *
 * ── POR QUE O SEXTO HOMEM EXISTE ─────────────────────────────────────
 *
 * Na NBA titular vira DNP de última hora toda semana — load management,
 * lesão no aquecimento, time já classificado poupando. Sem reserva, isso
 * zera uma vaga e tira a rodada de quem não errou nada.
 *
 * A regra é a que o dono da liga desenhou: escala SEIS, contam os CINCO
 * MELHORES, e o sexto é livre de posição. Ela resolve o DNP de quebra (quem
 * não jogou fez 0 e é justamente o descartado) e, diferente de um reserva
 * clássico, vale alguma coisa TODO dia e não só no dia azarado.
 *
 * O orçamento subiu junto, de 100 pra 120: em 100, o sexto viraria
 * obrigatoriamente um jogador de 2 F$, que é o contrário de uma escolha.
 */
const FNBA_ELENCO  = 6;
const FNBA_CONTAM  = 5;

/** O capitão dobra. Mais que isso e a rodada vira só a escolha dele. */
const FNBA_CAPITAO = 2.0;

/**
 * O orçamento, em F$.
 *
 * Calibrado contra a temporada 2025-26 inteira (560 jogadores, dados da
 * ESPN): com teto de 40, os seis melhores da liga custariam 208 — não cabem
 * nos 120. Dá pra levar um Jokic (40) e completar com cinco de 16, ou seis
 * medianos de 20. É isso que faz a escolha valer: não existe time óbvio.
 *
 * Eram 100 para cinco; virou 120 para seis quando o sexto homem entrou.
 * Manter 100 faria do sexto um jogador de 2 F$ obrigatório — uma vaga sem
 * decisão nenhuma. @see FNBA_ELENCO
 */
const FNBA_ORCAMENTO = 120.0;

/**
 * De pontos de fantasy por jogo pra F$.
 *
 * 0,62 põe o melhor da liga no teto: Jokic fez 64,3 de média em 2025-26, e
 * 64,3 × 0,62 = 40. A mediana da liga (18,8) cai em 11,7 — metade do elenco
 * custa menos de 12, que é o que deixa o orçamento respirar.
 */
const FNBA_PRECO_FATOR = 0.62;
const FNBA_PRECO_MIN   = 2.0;
const FNBA_PRECO_MAX   = 40.0;

/** Jogos a menos que isto na temporada passada: preço do piso, sem histórico. */
const FNBA_JOGOS_MIN = 15;

/** O fuso que decide de que DIA é o jogo. */
const FNBA_FUSO = 'America/Sao_Paulo';

/** Moedas por colocação na rodada do dia. */
const FNBA_PREMIO = [1 => 200, 2 => 120, 3 => 80, 4 => 50, 5 => 30];

/** A raiz da API da ESPN. */
const FNBA_API_SITE = 'https://site.api.espn.com/apis/site/v2/sports/basketball/nba';
const FNBA_API_WEB  = 'https://site.web.api.espn.com/apis/common/v3/sports/basketball/nba';

/* ─────────────────────────────────────────────────────────────────────
   A CONVERSA COM A ESPN
   ───────────────────────────────────────────────────────────────────── */

/**
 * Busca um JSON da ESPN.
 *
 * GZIP NÃO É OTIMIZAÇÃO AQUI, É VIABILIDADE: o endpoint de médias são 6,3 MB
 * sem compressão e 418 KB com ela. Sem o gzip a importação estoura o tempo
 * de execução da hospedagem antes de terminar.
 *
 * Devolve null em qualquer falha — quem chama decide se para ou segue. Uma
 * importação que morre no meio é pior que uma que pula um time.
 */
function fnbaBuscar(string $url, int $segundos = 25): ?array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $segundos,
        CURLOPT_ENCODING       => 'gzip,deflate',
        CURLOPT_FOLLOWLOCATION => true,
        /* O USER-AGENT PRECISA SER COMUM. Começou como
           "FBA-Manager/1.0 (+https://fbabrasil.com.br)", que é a forma
           educada de se identificar, e a ESPN devolvia 403 em /teams —
           ela barra UA com URL dentro. Testado em 20/10/2026: com este, com
           "curl/8.0" e sem UA nenhum dá 200; só o nosso dava 403. */
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) '
                                . 'AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36',
    ]);
    $corpo = curl_exec($ch);
    $http  = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $erro  = curl_error($ch);
    curl_close($ch);

    if ($corpo === false || $http !== 200) {
        error_log("[fnba] {$url} -> http={$http} {$erro}");
        return null;
    }
    $j = json_decode((string)$corpo, true);
    if (!is_array($j)) {
        error_log("[fnba] {$url} -> json invalido (" . strlen((string)$corpo) . ' bytes)');
        return null;
    }
    return $j;
}

/* ─────────────────────────────────────────────────────────────────────
   AS TABELAS
   ───────────────────────────────────────────────────────────────────── */

function fnbaTabelas(PDO $pdo): void
{
    static $feito = false;
    if ($feito) return;

    $pdo->exec("CREATE TABLE IF NOT EXISTS fnba_times (
        id INT PRIMARY KEY,                       -- o id da ESPN, não um nosso
        sigla VARCHAR(5) NOT NULL,
        nome VARCHAR(60) NOT NULL,
        logo VARCHAR(255) NULL,
        cor CHAR(6) NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    /* O id do atleta na ESPN é a chave aqui também. Guardar um id nosso ao
       lado só criaria a pergunta "qual dos dois é o certo" na primeira vez
       que um deles divergisse. */
    $pdo->exec("CREATE TABLE IF NOT EXISTS fnba_jogadores (
        id INT PRIMARY KEY,
        nome VARCHAR(80) NOT NULL,
        time_id INT NULL,
        pos ENUM('G','F','C') NOT NULL DEFAULT 'G',
        preco DECIMAL(5,1) NOT NULL DEFAULT 2.0,
        media_fp DECIMAL(5,1) NOT NULL DEFAULT 0,
        jogos_base INT NOT NULL DEFAULT 0,
        foto VARCHAR(255) NULL,
        ativo TINYINT(1) NOT NULL DEFAULT 1,
        KEY idx_fnba_j_time (time_id),
        KEY idx_fnba_j_preco (ativo, preco)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    /* UMA RODADA É UM DIA. `fecha_em` é a hora do primeiro jogo; depois dela
       a escalação está trancada. */
    $pdo->exec("CREATE TABLE IF NOT EXISTS fnba_rodadas (
        id INT AUTO_INCREMENT PRIMARY KEY,
        dia DATE NOT NULL,
        fecha_em DATETIME NOT NULL,
        status ENUM('aberta','fechada','apurada') NOT NULL DEFAULT 'aberta',
        jogos INT NOT NULL DEFAULT 0,
        apurada_em DATETIME NULL,
        UNIQUE KEY uk_fnba_dia (dia),
        KEY idx_fnba_r_status (status, dia)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS fnba_jogos (
        id INT PRIMARY KEY,                       -- id do evento na ESPN
        rodada_id INT NOT NULL,
        casa_id INT NULL,
        fora_id INT NULL,
        inicio DATETIME NOT NULL,
        estado VARCHAR(12) NOT NULL DEFAULT 'pre',
        placar_casa INT NULL,
        placar_fora INT NULL,
        KEY idx_fnba_g_rodada (rodada_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    /* A linha do boxscore. `fp` fica GRAVADO e não calculado na leitura: se a
       régua de pontos mudar amanhã, a rodada de ontem continua valendo o que
       valia quando foi jogada. */
    $pdo->exec("CREATE TABLE IF NOT EXISTS fnba_stats (
        jogo_id INT NOT NULL,
        jogador_id INT NOT NULL,
        min INT NOT NULL DEFAULT 0,
        pts INT NOT NULL DEFAULT 0,
        reb INT NOT NULL DEFAULT 0,
        ast INT NOT NULL DEFAULT 0,
        stl INT NOT NULL DEFAULT 0,
        blk INT NOT NULL DEFAULT 0,
        tov INT NOT NULL DEFAULT 0,
        fp DECIMAL(6,1) NOT NULL DEFAULT 0,
        PRIMARY KEY (jogo_id, jogador_id),
        KEY idx_fnba_s_jogador (jogador_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    /* UMA LINHA POR JOGADOR ESCALADO. O preço vai junto: o do mercado muda
       toda semana, e sem o preço do dia não dá pra remontar a escalação
       depois nem conferir se ela cabia no orçamento. */
    $pdo->exec("CREATE TABLE IF NOT EXISTS fnba_escalacoes (
        rodada_id INT NOT NULL,
        user_id INT NOT NULL,
        jogador_id INT NOT NULL,
        capitao TINYINT(1) NOT NULL DEFAULT 0,
        preco DECIMAL(5,1) NOT NULL DEFAULT 0,
        fp DECIMAL(6,1) NULL,
        PRIMARY KEY (rodada_id, user_id, jogador_id),
        KEY idx_fnba_e_user (user_id, rodada_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS fnba_resultados (
        rodada_id INT NOT NULL,
        user_id INT NOT NULL,
        pontos DECIMAL(7,1) NOT NULL DEFAULT 0,
        posicao INT NULL,
        moedas INT NOT NULL DEFAULT 0,
        PRIMARY KEY (rodada_id, user_id),
        KEY idx_fnba_res_pos (rodada_id, pontos)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $feito = true;
}

/* ─────────────────────────────────────────────────────────────────────
   A PONTUAÇÃO
   ───────────────────────────────────────────────────────────────────── */

/** Os pontos de fantasy de uma linha de boxscore. */
function fnbaPontosDaLinha(array $s): float
{
    $fp = 0.0;
    foreach (FNBA_PONTOS as $k => [$rotulo, $peso]) {
        $campo = $k === 'to' ? 'tov' : $k;
        $fp += (float)($s[$campo] ?? 0) * $peso;
    }
    return round($fp, 1);
}

/** O preço de mercado de quem faz esta média. */
function fnbaPrecoDaMedia(float $mediaFp, int $jogos): float
{
    if ($jogos < FNBA_JOGOS_MIN) return FNBA_PRECO_MIN;
    return round(max(FNBA_PRECO_MIN, min(FNBA_PRECO_MAX, $mediaFp * FNBA_PRECO_FATOR)), 1);
}

/* ─────────────────────────────────────────────────────────────────────
   A IMPORTAÇÃO
   ───────────────────────────────────────────────────────────────────── */

/** Os 30 times. Roda uma vez por temporada; mudar de logo é raro. */
function fnbaImportarTimes(PDO $pdo): int
{
    fnbaTabelas($pdo);
    $j = fnbaBuscar(FNBA_API_SITE . '/teams');
    $lista = $j['sports'][0]['leagues'][0]['teams'] ?? [];
    if (!$lista) return 0;

    $st = $pdo->prepare("INSERT INTO fnba_times (id, sigla, nome, logo, cor) VALUES (?,?,?,?,?)
                         ON DUPLICATE KEY UPDATE sigla=VALUES(sigla), nome=VALUES(nome),
                                                 logo=VALUES(logo), cor=VALUES(cor)");
    $n = 0;
    foreach ($lista as $item) {
        $t = $item['team'] ?? [];
        if (empty($t['id'])) continue;
        $st->execute([(int)$t['id'], (string)($t['abbreviation'] ?? '?'),
                      (string)($t['displayName'] ?? ''), $t['logos'][0]['href'] ?? null,
                      substr((string)($t['color'] ?? ''), 0, 6) ?: null]);
        $n++;
    }
    return $n;
}

/**
 * Os jogadores e o preço de cada um.
 *
 * Duas fontes, e as duas são necessárias: o ELENCO diz quem está em qual time
 * e em que posição (G/F/C), e as MÉDIAS dizem quanto a pessoa vale. Só o
 * elenco não precifica; só as médias não sabem de time nem de posição, e
 * trazem quem se aposentou.
 *
 * Quem está no elenco e não tem média — calouro, quem jogou pouco — fica no
 * preço do piso. É barato de propósito: é a aposta que cabe no orçamento de
 * quem gastou tudo num astro.
 */
function fnbaImportarJogadores(PDO $pdo, ?int $temporadaBase = null): array
{
    fnbaTabelas($pdo);
    $temporadaBase ??= fnbaTemporadaBase();

    /* 1) As médias, numa requisição só pra liga inteira. */
    $medias = [];
    $j = fnbaBuscar(FNBA_API_WEB . "/statistics/byathlete?season={$temporadaBase}&seasontype=2&limit=900", 60);
    if ($j) {
        $idx = [];
        foreach (($j['categories'] ?? []) as $c) {
            foreach (($c['names'] ?? []) as $i => $nome) $idx[$c['name']][$nome] = $i;
        }
        $pega = function (array $a, string $cat, string $nome) use ($idx): float {
            if (!isset($idx[$cat][$nome])) return 0.0;
            foreach ($a['categories'] as $c) {
                if ($c['name'] === $cat) return (float)($c['values'][$idx[$cat][$nome]] ?? 0);
            }
            return 0.0;
        };
        foreach (($j['athletes'] ?? []) as $a) {
            $id = (int)($a['athlete']['id'] ?? 0);
            if (!$id) continue;
            $jogos = (int)$pega($a, 'general', 'gamesPlayed');
            $fp = $pega($a, 'offensive', 'avgPoints')
                + 1.2 * $pega($a, 'general',   'avgRebounds')
                + 1.5 * $pega($a, 'offensive', 'avgAssists')
                + 3.0 * $pega($a, 'defensive', 'avgSteals')
                + 3.0 * $pega($a, 'defensive', 'avgBlocks')
                - 1.0 * $pega($a, 'offensive', 'avgTurnovers');
            $medias[$id] = ['fp' => round($fp, 1), 'jogos' => $jogos];
        }
    }

    /* 2) Os elencos, time a time. */
    $times = $pdo->query('SELECT id FROM fnba_times')->fetchAll(PDO::FETCH_COLUMN) ?: [];
    if (!$times) { fnbaImportarTimes($pdo); $times = $pdo->query('SELECT id FROM fnba_times')->fetchAll(PDO::FETCH_COLUMN) ?: []; }

    $st = $pdo->prepare("INSERT INTO fnba_jogadores (id, nome, time_id, pos, preco, media_fp, jogos_base, foto, ativo)
                         VALUES (?,?,?,?,?,?,?,?,1)
                         ON DUPLICATE KEY UPDATE nome=VALUES(nome), time_id=VALUES(time_id), pos=VALUES(pos),
                                                 preco=VALUES(preco), media_fp=VALUES(media_fp),
                                                 jogos_base=VALUES(jogos_base), foto=VALUES(foto), ativo=1");
    $vistos = [];
    foreach ($times as $tid) {
        $r = fnbaBuscar(FNBA_API_SITE . "/teams/{$tid}/roster", 30);
        foreach (($r['athletes'] ?? []) as $a) {
            $id = (int)($a['id'] ?? 0);
            if (!$id) continue;
            $m = $medias[$id] ?? ['fp' => 0.0, 'jogos' => 0];
            $st->execute([
                $id, (string)($a['displayName'] ?? '?'), (int)$tid,
                fnbaPosicao($a['position']['abbreviation'] ?? ''),
                fnbaPrecoDaMedia($m['fp'], $m['jogos']), $m['fp'], $m['jogos'],
                $a['headshot']['href'] ?? null,
            ]);
            $vistos[] = $id;
        }
        usleep(120000);   // não martelar a ESPN: 30 chamadas em ~4s
    }

    /* Quem saiu da liga some do mercado, mas a linha fica: as escalações
       antigas apontam pra ela, e rodada passada não se reescreve. */
    if ($vistos) {
        $ph = implode(',', array_fill(0, count($vistos), '?'));
        $pdo->prepare("UPDATE fnba_jogadores SET ativo = 0 WHERE id NOT IN ($ph)")->execute($vistos);
    }
    return ['jogadores' => count($vistos), 'com_media' => count($medias), 'temporada_base' => $temporadaBase];
}

/** G, F ou C — a ESPN usa PG/SG/SF/PF/C e às vezes só G/F. */
function fnbaPosicao(string $abrev): string
{
    $a = strtoupper(trim($abrev));
    if ($a === 'C' || $a === 'C-F') return 'C';
    if (str_contains($a, 'G')) return 'G';
    if (str_contains($a, 'F')) return 'F';
    return 'G';
}

/** A temporada que serve de base pro preço: a anterior à que está começando. */
function fnbaTemporadaBase(): int
{
    $hoje = new DateTimeImmutable('now', new DateTimeZone(FNBA_FUSO));
    // A temporada da NBA vira em outubro; antes disso, o ano-base é o retrasado.
    return (int)$hoje->format('n') >= 10 ? (int)$hoje->format('Y') - 1 : (int)$hoje->format('Y') - 2;
}

/**
 * Os jogos de um dia, e a rodada daquele dia.
 *
 * A rodada só existe se houver jogo: dia sem NBA não vira rodada vazia na
 * lista, que é o que faria a tela dizer "escale" num dia em que não há nada
 * pra escalar.
 */
function fnbaImportarDia(PDO $pdo, ?string $dia = null): ?array
{
    fnbaTabelas($pdo);
    $tz  = new DateTimeZone(FNBA_FUSO);
    $dia ??= (new DateTimeImmutable('now', $tz))->format('Y-m-d');
    $d   = new DateTimeImmutable($dia, $tz);

    $j = fnbaBuscar(FNBA_API_SITE . '/scoreboard?dates=' . $d->format('Ymd') . '&limit=40');
    $eventos = $j['events'] ?? [];
    if (!$eventos) return null;

    $jogos = [];
    foreach ($eventos as $e) {
        $id = (int)($e['id'] ?? 0);
        if (!$id) continue;
        $comp = $e['competitions'][0] ?? [];
        $casa = $fora = null;
        foreach (($comp['competitors'] ?? []) as $c) {
            if (($c['homeAway'] ?? '') === 'home') $casa = $c;
            else $fora = $c;
        }
        $jogos[] = [
            'id'     => $id,
            'casa'   => (int)($casa['team']['id'] ?? 0) ?: null,
            'fora'   => (int)($fora['team']['id'] ?? 0) ?: null,
            'inicio' => (new DateTimeImmutable((string)$e['date']))->setTimezone($tz)->format('Y-m-d H:i:s'),
            'estado' => (string)($e['status']['type']['state'] ?? 'pre'),
            'pc'     => isset($casa['score']) ? (int)$casa['score'] : null,
            'pf'     => isset($fora['score']) ? (int)$fora['score'] : null,
        ];
    }
    if (!$jogos) return null;

    /* O PRAZO É O PRIMEIRO PULO DE BOLA DO DIA. Não é o horário comercial nem
       meia-noite: é a hora em que a primeira informação nova aparece. */
    $inicios = array_column($jogos, 'inicio');
    sort($inicios);
    $fecha = $inicios[0];

    $pdo->prepare("INSERT INTO fnba_rodadas (dia, fecha_em, jogos) VALUES (?,?,?)
                   ON DUPLICATE KEY UPDATE fecha_em=VALUES(fecha_em), jogos=VALUES(jogos)")
        ->execute([$d->format('Y-m-d'), $fecha, count($jogos)]);

    $st = $pdo->prepare('SELECT id FROM fnba_rodadas WHERE dia = ?');
    $st->execute([$d->format('Y-m-d')]);
    $rid = (int)$st->fetchColumn();

    $ins = $pdo->prepare("INSERT INTO fnba_jogos (id, rodada_id, casa_id, fora_id, inicio, estado, placar_casa, placar_fora)
                          VALUES (?,?,?,?,?,?,?,?)
                          ON DUPLICATE KEY UPDATE estado=VALUES(estado), inicio=VALUES(inicio),
                                                  placar_casa=VALUES(placar_casa), placar_fora=VALUES(placar_fora)");
    foreach ($jogos as $g) {
        $ins->execute([$g['id'], $rid, $g['casa'], $g['fora'], $g['inicio'], $g['estado'], $g['pc'], $g['pf']]);
    }
    return ['rodada_id' => $rid, 'dia' => $d->format('Y-m-d'), 'jogos' => count($jogos), 'fecha_em' => $fecha];
}

/**
 * O boxscore de um jogo, linha por linha.
 *
 * Só grava jogo ENCERRADO: boxscore de jogo em andamento muda a cada bola, e
 * meia rodada apurada é pior que nenhuma.
 */
function fnbaImportarBoxscore(PDO $pdo, int $jogoId): int
{
    fnbaTabelas($pdo);
    $j = fnbaBuscar(FNBA_API_SITE . "/summary?event={$jogoId}", 30);
    if (!$j) return 0;

    $estado = $j['header']['competitions'][0]['status']['type']['state'] ?? 'pre';
    if ($estado !== 'post') return 0;

    $st = $pdo->prepare("INSERT INTO fnba_stats (jogo_id, jogador_id, min, pts, reb, ast, stl, blk, tov, fp)
                         VALUES (?,?,?,?,?,?,?,?,?,?)
                         ON DUPLICATE KEY UPDATE min=VALUES(min), pts=VALUES(pts), reb=VALUES(reb),
                               ast=VALUES(ast), stl=VALUES(stl), blk=VALUES(blk), tov=VALUES(tov), fp=VALUES(fp)");
    $n = 0;
    foreach (($j['boxscore']['players'] ?? []) as $time) {
        $bloco = $time['statistics'][0] ?? [];
        /* AS COLUNAS VÊM PELO NOME, nunca por posição fixa. A ESPN já mudou a
           ordem antes, e um índice cravado transformaria tocos em faltas sem
           erro nenhum aparecer. */
        $cols = array_map('strtoupper', $bloco['names'] ?? $bloco['labels'] ?? []);
        $i = fn(string $c) => array_search($c, $cols, true);
        foreach (($bloco['athletes'] ?? []) as $a) {
            $pid = (int)($a['athlete']['id'] ?? 0);
            if (!$pid || !empty($a['didNotPlay'])) continue;
            $v = $a['stats'] ?? [];
            if (!$v) continue;
            $num = function (string $col) use ($v, $i): int {
                $k = $i($col);
                return $k === false ? 0 : (int)($v[$k] ?? 0);
            };
            $linha = ['pts' => $num('PTS'), 'reb' => $num('REB'), 'ast' => $num('AST'),
                      'stl' => $num('STL'), 'blk' => $num('BLK'), 'tov' => $num('TO')];
            $st->execute([$jogoId, $pid, $num('MIN'), $linha['pts'], $linha['reb'], $linha['ast'],
                          $linha['stl'], $linha['blk'], $linha['tov'], fnbaPontosDaLinha($linha)]);
            $n++;
        }
    }
    return $n;
}

/* ─────────────────────────────────────────────────────────────────────
   A RODADA
   ───────────────────────────────────────────────────────────────────── */

/** A rodada de hoje, se houver jogo hoje. */
function fnbaRodadaDoDia(PDO $pdo, ?string $dia = null): ?array
{
    fnbaTabelas($pdo);
    $dia ??= (new DateTimeImmutable('now', new DateTimeZone(FNBA_FUSO)))->format('Y-m-d');
    $st = $pdo->prepare('SELECT * FROM fnba_rodadas WHERE dia = ?');
    $st->execute([$dia]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** Ainda dá pra escalar nesta rodada? */
function fnbaAberta(array $rodada): bool
{
    if (($rodada['status'] ?? '') !== 'aberta') return false;
    $agora = new DateTimeImmutable('now', new DateTimeZone(FNBA_FUSO));
    return $agora < new DateTimeImmutable($rodada['fecha_em'], new DateTimeZone(FNBA_FUSO));
}

/**
 * Confere uma escalação ANTES de gravar.
 *
 * Devolve a lista de problemas, vazia quando está tudo certo. Separada do
 * salvar porque a tela precisa dela pra avisar enquanto a pessoa monta, e
 * porque a regra tem que ser a mesma nos dois lugares.
 */
function fnbaValidar(PDO $pdo, array $ids, int $capitao): array
{
    $erros = [];
    $ids = array_values(array_unique(array_map('intval', $ids)));
    if (count($ids) !== FNBA_ELENCO) {
        return ['Escale ' . FNBA_ELENCO . ' jogadores diferentes.'];
    }
    if (!in_array($capitao, $ids, true)) $erros[] = 'O capitão precisa ser um dos escalados.';

    $ph = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare("SELECT id, nome, pos, preco FROM fnba_jogadores WHERE id IN ($ph) AND ativo = 1");
    $st->execute($ids);
    $js = $st->fetchAll(PDO::FETCH_ASSOC);
    if (count($js) !== count($ids)) $erros[] = 'Algum jogador saiu do mercado. Recarregue a página.';

    $custo = 0.0; $porPos = ['G' => 0, 'F' => 0, 'C' => 0];
    foreach ($js as $j) { $custo += (float)$j['preco']; $porPos[$j['pos']]++; }

    if ($custo > FNBA_ORCAMENTO + 0.001) {
        $erros[] = sprintf('O time custa F$ %.1f e o orçamento é F$ %.0f.', $custo, FNBA_ORCAMENTO);
    }
    /* MÍNIMO por posição, e não número exato: o sexto homem é livre, então
       3 armadores é uma escalação legítima — desde que os 2 alas e o pivô
       estejam lá. @see FNBA_ELENCO */
    foreach (FNBA_FORMACAO as $pos => $minimo) {
        if ($porPos[$pos] < $minimo) {
            $nomes = ['G' => 'armador', 'F' => 'ala', 'C' => 'pivô'];
            $erros[] = "A formação pede pelo menos {$minimo} " . $nomes[$pos] . ($minimo > 1 ? 'es' : '')
                     . ", e você escalou {$porPos[$pos]}.";
        }
    }
    return $erros;
}

/** Grava a escalação da rodada, trocando a anterior se houver. */
function fnbaEscalar(PDO $pdo, int $rodadaId, int $uid, array $ids, int $capitao): array
{
    fnbaTabelas($pdo);
    $st = $pdo->prepare('SELECT * FROM fnba_rodadas WHERE id = ?');
    $st->execute([$rodadaId]);
    $rodada = $st->fetch(PDO::FETCH_ASSOC);
    if (!$rodada)            return ['ok' => false, 'erros' => ['Rodada não encontrada.']];
    if (!fnbaAberta($rodada)) return ['ok' => false, 'erros' => ['A escalação desta rodada já fechou.']];

    $erros = fnbaValidar($pdo, $ids, $capitao);
    if ($erros) return ['ok' => false, 'erros' => $erros];

    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM fnba_escalacoes WHERE rodada_id = ? AND user_id = ?')
            ->execute([$rodadaId, $uid]);
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $q = $pdo->prepare("SELECT id, preco FROM fnba_jogadores WHERE id IN ($ph)");
        $q->execute(array_map('intval', $ids));
        $ins = $pdo->prepare('INSERT INTO fnba_escalacoes (rodada_id, user_id, jogador_id, capitao, preco) VALUES (?,?,?,?,?)');
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $j) {
            $ins->execute([$rodadaId, $uid, (int)$j['id'], (int)$j['id'] === $capitao ? 1 : 0, $j['preco']]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[fnba] escalar: ' . $e->getMessage());
        return ['ok' => false, 'erros' => ['Não deu pra salvar agora. Tente de novo.']];
    }
    return ['ok' => true, 'erros' => []];
}

/** A escalação de alguém numa rodada. */
function fnbaEscalacao(PDO $pdo, int $rodadaId, int $uid): array
{
    fnbaTabelas($pdo);
    $st = $pdo->prepare("SELECT e.*, j.nome, j.pos, j.foto, j.time_id, t.sigla
                           FROM fnba_escalacoes e
                           JOIN fnba_jogadores j ON j.id = e.jogador_id
                      LEFT JOIN fnba_times t ON t.id = j.time_id
                          WHERE e.rodada_id = ? AND e.user_id = ?
                       ORDER BY FIELD(j.pos,'G','F','C'), j.nome");
    $st->execute([$rodadaId, $uid]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Fecha a rodada, soma os pontos e paga.
 *
 * Só roda quando TODOS os jogos do dia terminaram: com um jogo em aberto o
 * ranking mudaria depois de publicado, que é a forma mais rápida de a liga
 * parar de confiar no placar.
 */
function fnbaApurar(PDO $pdo, int $rodadaId): array
{
    fnbaTabelas($pdo);
    $st = $pdo->prepare('SELECT * FROM fnba_rodadas WHERE id = ?');
    $st->execute([$rodadaId]);
    $rodada = $st->fetch(PDO::FETCH_ASSOC);
    if (!$rodada) return ['ok' => false, 'erro' => 'rodada não encontrada'];
    if ($rodada['status'] === 'apurada') return ['ok' => true, 'ja' => true];

    $st = $pdo->prepare("SELECT COUNT(*) FROM fnba_jogos WHERE rodada_id = ? AND estado <> 'post'");
    $st->execute([$rodadaId]);
    if ((int)$st->fetchColumn() > 0) return ['ok' => false, 'erro' => 'ainda tem jogo em aberto'];

    /* O fp de cada escalado sai do boxscore. Quem não jogou fica com 0 — e é
       isso que faz a escalação ser decisão: escalar quem está machucado custa
       a rodada. */
    $pdo->exec("UPDATE fnba_escalacoes e
                  JOIN fnba_jogos g ON g.rodada_id = e.rodada_id
                  LEFT JOIN fnba_stats s ON s.jogo_id = g.id AND s.jogador_id = e.jogador_id
                   SET e.fp = COALESCE(s.fp, 0)
                 WHERE e.rodada_id = " . (int)$rodadaId . " AND s.fp IS NOT NULL");
    $pdo->prepare('UPDATE fnba_escalacoes SET fp = 0 WHERE rodada_id = ? AND fp IS NULL')->execute([$rodadaId]);

    /* SÓ OS CINCO MELHORES DE CADA UM. Em SQL isso viraria uma janela com
       ROW_NUMBER, que o MariaDB desta hospedagem até entende — mas aqui são
       seis linhas por cartola e algumas dezenas de cartolas: ler e ordenar
       em PHP é mais claro e igualmente rápido, e deixa a regra à vista.
       @see FNBA_CONTAM */
    $st = $pdo->prepare("SELECT user_id, fp * IF(capitao = 1, " . FNBA_CAPITAO . ", 1) AS valor
                           FROM fnba_escalacoes WHERE rodada_id = ?");
    $st->execute([$rodadaId]);
    $porCartola = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $l) {
        $porCartola[(int)$l['user_id']][] = (float)$l['valor'];
    }
    $linhas = [];
    foreach ($porCartola as $uid => $valores) {
        rsort($valores);
        $linhas[] = ['user_id' => $uid, 'pontos' => array_sum(array_slice($valores, 0, FNBA_CONTAM))];
    }
    usort($linhas, fn($a, $b) => $b['pontos'] <=> $a['pontos']);

    $ins = $pdo->prepare("INSERT INTO fnba_resultados (rodada_id, user_id, pontos, posicao, moedas)
                          VALUES (?,?,?,?,?)
                          ON DUPLICATE KEY UPDATE pontos=VALUES(pontos), posicao=VALUES(posicao), moedas=VALUES(moedas)");
    $pagar = $pdo->prepare('UPDATE games_usuarios SET pontos = pontos + ? WHERE id = ?');
    $pos = 0; $pagos = 0;
    foreach ($linhas as $l) {
        $pos++;
        $moedas = FNBA_PREMIO[$pos] ?? 0;
        $ins->execute([$rodadaId, (int)$l['user_id'], (float)$l['pontos'], $pos, $moedas]);
        if ($moedas > 0) { $pagar->execute([$moedas, (int)$l['user_id']]); $pagos += $moedas; }
    }
    $pdo->prepare("UPDATE fnba_rodadas SET status = 'apurada', apurada_em = NOW() WHERE id = ?")
        ->execute([$rodadaId]);

    return ['ok' => true, 'cartolas' => count($linhas), 'moedas' => $pagos];
}

/** O ranking de uma rodada. */
function fnbaRanking(PDO $pdo, int $rodadaId, int $limite = 50): array
{
    fnbaTabelas($pdo);
    $st = $pdo->prepare("SELECT r.*, u.name AS nome
                           FROM fnba_resultados r
                      LEFT JOIN users u ON u.id = r.user_id
                          WHERE r.rodada_id = ?
                       ORDER BY r.pontos DESC LIMIT " . max(1, $limite));
    $st->execute([$rodadaId]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * O mercado: quem dá pra escalar, do mais caro ao mais barato.
 *
 * ── QUEM JOGA NA RODADA VEM MARCADO, E ISSO É O MAIS IMPORTANTE AQUI ──
 *
 * A NBA não joga com todos os times no mesmo dia. Numa terça de estreia são
 * 3 jogos: 6 times em quadra, 103 jogadores dos 517 do mercado. Os outros
 * 414 estão de folga — escalar um deles é zerar a vaga sem perceber, e o
 * cartola só descobre no dia seguinte, olhando um 0,0 ao lado do melhor
 * pivô da liga.
 *
 * Então a lista diz quem joga. A tela usa isso pra mostrar primeiro quem
 * está em quadra e apagar o resto; aqui embaixo é só o dado.
 *
 * @param int|null $rodadaId quando vem, cada linha ganha `joga` e `contra`
 */
function fnbaMercado(PDO $pdo, ?int $rodadaId = null): array
{
    fnbaTabelas($pdo);
    $lista = $pdo->query("SELECT j.id, j.nome, j.pos, j.preco, j.media_fp, j.jogos_base, j.foto,
                                 t.sigla, t.id AS time_id
                            FROM fnba_jogadores j
                       LEFT JOIN fnba_times t ON t.id = j.time_id
                           WHERE j.ativo = 1
                        ORDER BY j.preco DESC, j.nome")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (!$rodadaId) return $lista;

    /* Quem enfrenta quem, pra escrever "vs DET" ao lado do nome: saber que o
       jogador joga é metade; contra quem é o que decide entre dois iguais. */
    $st = $pdo->prepare("SELECT g.casa_id, g.fora_id, tc.sigla AS casa, tf.sigla AS fora
                           FROM fnba_jogos g
                      LEFT JOIN fnba_times tc ON tc.id = g.casa_id
                      LEFT JOIN fnba_times tf ON tf.id = g.fora_id
                          WHERE g.rodada_id = ?");
    $st->execute([$rodadaId]);
    $adv = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $g) {
        if ($g['casa_id']) $adv[(int)$g['casa_id']] = 'vs ' . $g['fora'];
        if ($g['fora_id']) $adv[(int)$g['fora_id']] = '@ '  . $g['casa'];
    }
    foreach ($lista as &$j) {
        $tid = (int)($j['time_id'] ?? 0);
        $j['joga']   = isset($adv[$tid]);
        $j['contra'] = $adv[$tid] ?? '';
    }
    unset($j);
    return $lista;
}

/* ─────────────────────────────────────────────────────────────────────
   AS LIGAS E OS RANKINGS

   Três recortes da mesma tabela de resultados, porque são três perguntas
   diferentes: "como fui no geral", "como vou contra a minha divisão" e
   "como vou contra os meus amigos". A primeira é a liga inteira, a segunda
   sai de graça do cadastro (quem tem time na ELITE disputa com a ELITE) e a
   terceira é a única que precisa de tabela.
   ───────────────────────────────────────────────────────────────────── */

/** As divisões da FBA, que viram liga de fantasy sem ninguém criar nada. */
const FNBA_DIVISOES = ['ELITE', 'NEXT', 'RISE', 'ROOKIE'];

function fnbaTabelasLigas(PDO $pdo): void
{
    static $feito = false;
    if ($feito) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS fnba_ligas (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nome VARCHAR(60) NOT NULL,
        codigo CHAR(6) NOT NULL,
        dono_id INT NOT NULL,
        criada_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_fnba_liga_cod (codigo)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS fnba_liga_membros (
        liga_id INT NOT NULL,
        user_id INT NOT NULL,
        entrou_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (liga_id, user_id),
        KEY idx_fnba_lm_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $feito = true;
}

/** A divisão da FBA de quem joga, ou null pra quem não tem time. */
function fnbaDivisaoDoUsuario(PDO $pdo, int $uid): ?string
{
    $st = $pdo->prepare('SELECT league FROM teams WHERE user_id = ? LIMIT 1');
    $st->execute([$uid]);
    $l = strtoupper((string)$st->fetchColumn());
    return in_array($l, FNBA_DIVISOES, true) ? $l : null;
}

/**
 * O RANKING GERAL: a soma de todas as rodadas já apuradas.
 *
 * `rodadas` vai junto porque sem ele a tabela mente: quem entrou ontem
 * aparece no fim com 40 pontos ao lado de quem joga há um mês, e os dois
 * números não querem dizer a mesma coisa. Com a contagem do lado, a média
 * fica visível e a comparação é honesta.
 *
 * @param string|null $divisao  limita a uma divisão da FBA
 * @param int|null    $ligaId   limita a uma liga de amigos
 */
function fnbaRankingGeral(PDO $pdo, ?string $divisao = null, ?int $ligaId = null, int $limite = 100): array
{
    fnbaTabelas($pdo);
    fnbaTabelasLigas($pdo);
    $par  = [];
    $join = '';
    $where = [];
    if ($ligaId) {
        $join .= ' JOIN fnba_liga_membros lm ON lm.user_id = r.user_id AND lm.liga_id = ? ';
        $par[] = $ligaId;
    }
    if ($divisao) {
        $join .= ' JOIN teams tm ON tm.user_id = r.user_id ';
        $where[] = 'tm.league = ?';
        $par[]   = strtoupper($divisao);
    }
    $sql = "SELECT r.user_id, u.name AS nome, SUM(r.pontos) AS total, COUNT(*) AS rodadas,
                   MAX(r.pontos) AS melhor, SUM(r.moedas) AS moedas
              FROM fnba_resultados r
              LEFT JOIN users u ON u.id = r.user_id
              {$join}"
         . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
         . " GROUP BY r.user_id, u.name ORDER BY total DESC LIMIT " . max(1, $limite);
    $st = $pdo->prepare($sql);
    $st->execute($par);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** As ligas de amigos de alguém. */
function fnbaMinhasLigas(PDO $pdo, int $uid): array
{
    fnbaTabelasLigas($pdo);
    $st = $pdo->prepare("SELECT l.*, (SELECT COUNT(*) FROM fnba_liga_membros m WHERE m.liga_id = l.id) AS membros
                           FROM fnba_ligas l
                           JOIN fnba_liga_membros lm ON lm.liga_id = l.id
                          WHERE lm.user_id = ? ORDER BY l.criada_em");
    $st->execute([$uid]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** Cria uma liga de amigos e põe o dono dentro. */
function fnbaCriarLiga(PDO $pdo, int $uid, string $nome): array
{
    fnbaTabelasLigas($pdo);
    $nome = trim($nome);
    if (mb_strlen($nome) < 3) return ['ok' => false, 'erro' => 'O nome precisa de pelo menos 3 letras.'];
    if (count(fnbaMinhasLigas($pdo, $uid)) >= 5) return ['ok' => false, 'erro' => 'Você já está em 5 ligas.'];

    /* Sem I, O, 0 e 1: o código vai ser lido em voz alta no grupo e digitado
       por quem está no celular. */
    $alfabeto = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    for ($tentativa = 0; $tentativa < 12; $tentativa++) {
        $cod = '';
        for ($i = 0; $i < 6; $i++) $cod .= $alfabeto[random_int(0, strlen($alfabeto) - 1)];
        try {
            $pdo->prepare('INSERT INTO fnba_ligas (nome, codigo, dono_id) VALUES (?,?,?)')
                ->execute([mb_substr($nome, 0, 60), $cod, $uid]);
            $id = (int)$pdo->lastInsertId();
            $pdo->prepare('INSERT IGNORE INTO fnba_liga_membros (liga_id, user_id) VALUES (?,?)')->execute([$id, $uid]);
            return ['ok' => true, 'id' => $id, 'codigo' => $cod];
        } catch (Throwable $e) {
            continue;   // código repetido: sorteia outro
        }
    }
    return ['ok' => false, 'erro' => 'Não deu pra criar agora. Tente de novo.'];
}

/** Entra numa liga pelo código. */
function fnbaEntrarNaLiga(PDO $pdo, int $uid, string $codigo): array
{
    fnbaTabelasLigas($pdo);
    $cod = strtoupper(trim($codigo));
    $st = $pdo->prepare('SELECT * FROM fnba_ligas WHERE codigo = ?');
    $st->execute([$cod]);
    $liga = $st->fetch(PDO::FETCH_ASSOC);
    if (!$liga) return ['ok' => false, 'erro' => 'Não achei liga com esse código.'];
    if (count(fnbaMinhasLigas($pdo, $uid)) >= 5) return ['ok' => false, 'erro' => 'Você já está em 5 ligas.'];
    $pdo->prepare('INSERT IGNORE INTO fnba_liga_membros (liga_id, user_id) VALUES (?,?)')
        ->execute([(int)$liga['id'], $uid]);
    return ['ok' => true, 'nome' => $liga['nome']];
}

/** Sai de uma liga. O dono não sai: a liga é dele. */
function fnbaSairDaLiga(PDO $pdo, int $uid, int $ligaId): bool
{
    fnbaTabelasLigas($pdo);
    $st = $pdo->prepare('SELECT dono_id FROM fnba_ligas WHERE id = ?');
    $st->execute([$ligaId]);
    if ((int)$st->fetchColumn() === $uid) return false;
    $pdo->prepare('DELETE FROM fnba_liga_membros WHERE liga_id = ? AND user_id = ?')->execute([$ligaId, $uid]);
    return true;
}

/** As últimas rodadas, com o meu resultado em cada uma. */
function fnbaMinhasRodadas(PDO $pdo, int $uid, int $limite = 20): array
{
    fnbaTabelas($pdo);
    $st = $pdo->prepare("SELECT r.*, res.pontos, res.posicao, res.moedas,
                                (SELECT COUNT(*) FROM fnba_resultados x WHERE x.rodada_id = r.id) AS cartolas
                           FROM fnba_rodadas r
                      LEFT JOIN fnba_resultados res ON res.rodada_id = r.id AND res.user_id = ?
                       ORDER BY r.dia DESC LIMIT " . max(1, $limite));
    $st->execute([$uid]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * A escalação de QUALQUER UM numa rodada — mas só depois que ela fecha.
 *
 * Antes do fechamento isso seria copiar o time do vizinho, e o jogo todo é
 * escolher sem saber o que os outros escolheram. Depois do apito vira o que
 * mais se comenta no grupo: "quem você pôs de capitão?".
 */
function fnbaEscalacaoDe(PDO $pdo, int $rodadaId, int $alvoId): ?array
{
    fnbaTabelas($pdo);
    $st = $pdo->prepare('SELECT * FROM fnba_rodadas WHERE id = ?');
    $st->execute([$rodadaId]);
    $rodada = $st->fetch(PDO::FETCH_ASSOC);
    if (!$rodada || fnbaAberta($rodada)) return null;

    $st = $pdo->prepare("SELECT e.*, j.nome, j.pos, j.foto, t.sigla
                           FROM fnba_escalacoes e
                           JOIN fnba_jogadores j ON j.id = e.jogador_id
                      LEFT JOIN fnba_times t ON t.id = j.time_id
                          WHERE e.rodada_id = ? AND e.user_id = ?
                       ORDER BY e.capitao DESC, e.fp DESC");
    $st->execute([$rodadaId, $alvoId]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** Quem escalou numa rodada (pra lista de "times dos outros"). */
function fnbaQuemEscalou(PDO $pdo, int $rodadaId): array
{
    fnbaTabelas($pdo);
    $st = $pdo->prepare("SELECT e.user_id, u.name AS nome, res.pontos, res.posicao,
                                COUNT(*) AS jogadores
                           FROM fnba_escalacoes e
                      LEFT JOIN users u ON u.id = e.user_id
                      LEFT JOIN fnba_resultados res ON res.rodada_id = e.rodada_id AND res.user_id = e.user_id
                          WHERE e.rodada_id = ?
                       GROUP BY e.user_id, u.name, res.pontos, res.posicao
                       ORDER BY res.pontos DESC, u.name");
    $st->execute([$rodadaId]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** Uma rodada pelo id, com os jogos dela. */
function fnbaRodadaPorId(PDO $pdo, int $id): ?array
{
    fnbaTabelas($pdo);
    $st = $pdo->prepare('SELECT * FROM fnba_rodadas WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** As próximas rodadas que já dá pra escalar (hoje e as seguintes). */
function fnbaProximasRodadas(PDO $pdo, int $quantas = 5): array
{
    fnbaTabelas($pdo);
    $st = $pdo->prepare("SELECT * FROM fnba_rodadas WHERE dia >= CURDATE() ORDER BY dia LIMIT " . max(1, $quantas));
    $st->execute();
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/* ─────────────────────────────────────────────────────────────────────
   A SEMANA

   O prêmio de verdade do jogo. A rodada do dia paga moeda — troco, pra dar
   motivo de voltar amanhã. A semana paga FBA POINTS, que é a moeda séria da
   liga: compra badge e slot, e na loja 10 moedas viram 1 ponto. Ou seja, os
   500 do primeiro lugar valem 5.000 moedas — 20 rodadas diárias ganhas.

   É de propósito: o que faz alguém escalar todo dia não é o troço de hoje, é
   não perder terreno na semana.
   ───────────────────────────────────────────────────────────────────── */

/**
 * O que cada lugar leva na semana, em FBA Points.
 *
 * Decisão dele em 20/10/2026. Para comparar: uma aposta certa paga 75 e uma
 * conquista do Geo, 25 — então a semana do Fantasy é o maior prêmio
 * recorrente da casa. É o preço de fazer a pessoa voltar sete dias seguidos.
 */
const FNBA_PREMIO_SEMANA = [1 => 500, 2 => 300, 3 => 100, 4 => 50, 5 => 50];

function fnbaTabelasSemana(PDO $pdo): void
{
    static $feito = false;
    if ($feito) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS fnba_semanas (
        id INT AUTO_INCREMENT PRIMARY KEY,
        inicio DATE NOT NULL,
        fim DATE NOT NULL,
        status ENUM('aberta','apurada') NOT NULL DEFAULT 'aberta',
        apurada_em DATETIME NULL,
        UNIQUE KEY uk_fnba_sem (inicio)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS fnba_semana_premios (
        semana_id INT NOT NULL,
        user_id INT NOT NULL,
        posicao INT NOT NULL,
        pontos DECIMAL(8,1) NOT NULL DEFAULT 0,
        rodadas INT NOT NULL DEFAULT 0,
        fba_points INT NOT NULL DEFAULT 0,
        PRIMARY KEY (semana_id, user_id),
        KEY idx_fnba_sp_pos (semana_id, posicao)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $feito = true;
}

/**
 * A segunda-feira da semana de uma data.
 *
 * SEMANA É DE SEGUNDA A DOMINGO, como ele pediu. O fechamento acontece
 * quando o domingo ACABA — ou seja, na virada pra segunda. Dizer "domingo
 * 00:00" é o jeito comum de falar disso, mas às zero hora de domingo o
 * domingo ainda não foi jogado: pagar ali deixaria um sétimo da semana de
 * fora, justo o dia de mais jogos.
 */
function fnbaSegundaDa(string $dia): string
{
    $d = new DateTimeImmutable($dia, new DateTimeZone(FNBA_FUSO));
    return $d->modify('monday this week')->format('Y-m-d');
}

/** A semana que contém este dia, criando a linha se ainda não existir. */
function fnbaSemanaDe(PDO $pdo, ?string $dia = null): array
{
    fnbaTabelasSemana($pdo);
    $dia ??= (new DateTimeImmutable('now', new DateTimeZone(FNBA_FUSO)))->format('Y-m-d');
    $ini = fnbaSegundaDa($dia);
    $fim = (new DateTimeImmutable($ini))->modify('+6 days')->format('Y-m-d');
    $pdo->prepare('INSERT IGNORE INTO fnba_semanas (inicio, fim) VALUES (?,?)')->execute([$ini, $fim]);
    $st = $pdo->prepare('SELECT * FROM fnba_semanas WHERE inicio = ?');
    $st->execute([$ini]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: ['inicio' => $ini, 'fim' => $fim, 'status' => 'aberta'];
}

/**
 * A tabela de uma semana: a soma das rodadas dela.
 *
 * Sai de fnba_resultados, e não de uma coluna somada à parte — número que
 * existe em dois lugares é número que diverge no primeiro ajuste.
 */
function fnbaTabelaDaSemana(PDO $pdo, string $inicio, string $fim, int $limite = 100): array
{
    fnbaTabelas($pdo);
    $st = $pdo->prepare("SELECT res.user_id, u.name AS nome,
                                SUM(res.pontos) AS total, COUNT(*) AS rodadas,
                                MAX(res.pontos) AS melhor, SUM(res.moedas) AS moedas
                           FROM fnba_resultados res
                           JOIN fnba_rodadas r ON r.id = res.rodada_id
                      LEFT JOIN users u ON u.id = res.user_id
                          WHERE r.dia BETWEEN ? AND ?
                       GROUP BY res.user_id, u.name
                       ORDER BY total DESC LIMIT " . max(1, $limite));
    $st->execute([$inicio, $fim]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Fecha a semana e paga os FBA Points.
 *
 * Só fecha depois que o domingo passou E todas as rodadas dela foram
 * apuradas: pagar com uma rodada em aberto é publicar um campeão que ainda
 * pode mudar.
 */
function fnbaApurarSemana(PDO $pdo, int $semanaId): array
{
    fnbaTabelasSemana($pdo);
    $st = $pdo->prepare('SELECT * FROM fnba_semanas WHERE id = ?');
    $st->execute([$semanaId]);
    $sem = $st->fetch(PDO::FETCH_ASSOC);
    if (!$sem) return ['ok' => false, 'erro' => 'semana não encontrada'];
    if ($sem['status'] === 'apurada') return ['ok' => true, 'ja' => true];

    $hoje = (new DateTimeImmutable('now', new DateTimeZone(FNBA_FUSO)))->format('Y-m-d');
    if ($hoje <= $sem['fim']) return ['ok' => false, 'erro' => 'a semana ainda não acabou'];

    $st = $pdo->prepare("SELECT COUNT(*) FROM fnba_rodadas
                          WHERE dia BETWEEN ? AND ? AND status <> 'apurada'");
    $st->execute([$sem['inicio'], $sem['fim']]);
    if ((int)$st->fetchColumn() > 0) return ['ok' => false, 'erro' => 'ainda tem rodada sem apurar'];

    $tabela = fnbaTabelaDaSemana($pdo, $sem['inicio'], $sem['fim']);
    if (!$tabela) {
        $pdo->prepare("UPDATE fnba_semanas SET status = 'apurada', apurada_em = NOW() WHERE id = ?")
            ->execute([$semanaId]);
        return ['ok' => true, 'cartolas' => 0, 'pontos' => 0];
    }

    $ins = $pdo->prepare("INSERT INTO fnba_semana_premios (semana_id, user_id, posicao, pontos, rodadas, fba_points)
                          VALUES (?,?,?,?,?,?)
                          ON DUPLICATE KEY UPDATE posicao=VALUES(posicao), pontos=VALUES(pontos),
                                                  rodadas=VALUES(rodadas), fba_points=VALUES(fba_points)");
    $pagar = $pdo->prepare("UPDATE games_usuarios SET fba_points = COALESCE(fba_points,0) + ? WHERE id = ?");
    $pos = 0; $pagos = 0;
    foreach ($tabela as $l) {
        $pos++;
        $fbap = FNBA_PREMIO_SEMANA[$pos] ?? 0;
        $ins->execute([$semanaId, (int)$l['user_id'], $pos, (float)$l['total'], (int)$l['rodadas'], $fbap]);
        if ($fbap > 0) {
            /* A conta do Games pode não existir pra quem só jogou uma rodada
               e sumiu — sem isto o prêmio cairia no vazio. */
            $pdo->prepare('INSERT IGNORE INTO games_usuarios (id, nome, pontos) VALUES (?,?,0)')
                ->execute([(int)$l['user_id'], (string)($l['nome'] ?? 'GM')]);
            $pagar->execute([$fbap, (int)$l['user_id']]);
            $pagos += $fbap;
        }
    }
    $pdo->prepare("UPDATE fnba_semanas SET status = 'apurada', apurada_em = NOW() WHERE id = ?")
        ->execute([$semanaId]);
    return ['ok' => true, 'cartolas' => count($tabela), 'pontos' => $pagos];
}

/** As semanas que já acabaram e ainda não foram pagas. */
function fnbaSemanasPendentes(PDO $pdo): array
{
    fnbaTabelasSemana($pdo);
    $st = $pdo->query("SELECT * FROM fnba_semanas
                        WHERE status = 'aberta' AND fim < CURDATE()
                     ORDER BY inicio");
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Os campeões de cada semana — a lista que ele pediu.
 *
 * Só o primeiro de cada uma: é a galeria, não a tabela. Quem quiser o resto
 * abre a semana.
 */
function fnbaCampeoesDaSemana(PDO $pdo, int $limite = 30): array
{
    fnbaTabelasSemana($pdo);
    $st = $pdo->query("SELECT s.inicio, s.fim, p.user_id, p.pontos, p.rodadas, p.fba_points,
                              u.name AS nome,
                              (SELECT COUNT(*) FROM fnba_semana_premios x WHERE x.semana_id = s.id) AS cartolas
                         FROM fnba_semanas s
                         JOIN fnba_semana_premios p ON p.semana_id = s.id AND p.posicao = 1
                    LEFT JOIN users u ON u.id = p.user_id
                        WHERE s.status = 'apurada'
                     ORDER BY s.inicio DESC LIMIT " . max(1, $limite));
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/* ─────────────────────────────────────────────────────────────────────
   OS FAVORITOS

   Quem joga todo dia escala quase sempre os mesmos nomes, e caçá-los numa
   lista de 517 (ou nos 103 que jogam hoje) é o atrito que faz a pessoa
   desistir no terceiro dia. Favoritar é a memória dela entre as rodadas.

   Fica no banco e não no navegador: quem escala no celular de manhã e
   confere no computador à noite é a mesma pessoa.
   ───────────────────────────────────────────────────────────────────── */

function fnbaTabelasFavoritos(PDO $pdo): void
{
    static $feito = false;
    if ($feito) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS fnba_favoritos (
        user_id INT NOT NULL,
        jogador_id INT NOT NULL,
        criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (user_id, jogador_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $feito = true;
}

/** Os ids favoritados por alguém. */
function fnbaFavoritos(PDO $pdo, int $uid): array
{
    fnbaTabelasFavoritos($pdo);
    $st = $pdo->prepare('SELECT jogador_id FROM fnba_favoritos WHERE user_id = ?');
    $st->execute([$uid]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
}

/**
 * Favorita, ou desfavorita se já era.
 *
 * O mesmo clique faz as duas coisas — é o que ele pediu, e é como a estrela
 * funciona em todo lugar. Devolve o estado novo pra tela não precisar
 * adivinhar.
 */
function fnbaAlternarFavorito(PDO $pdo, int $uid, int $jogadorId): bool
{
    fnbaTabelasFavoritos($pdo);
    $st = $pdo->prepare('SELECT 1 FROM fnba_favoritos WHERE user_id = ? AND jogador_id = ?');
    $st->execute([$uid, $jogadorId]);
    if ($st->fetch()) {
        $pdo->prepare('DELETE FROM fnba_favoritos WHERE user_id = ? AND jogador_id = ?')
            ->execute([$uid, $jogadorId]);
        return false;
    }
    /* Teto de 40: favorito demais é o mesmo que nenhum — a aba vira outra
       lista pra procurar dentro. */
    $st = $pdo->prepare('SELECT COUNT(*) FROM fnba_favoritos WHERE user_id = ?');
    $st->execute([$uid]);
    if ((int)$st->fetchColumn() >= 40) return false;

    $pdo->prepare('INSERT IGNORE INTO fnba_favoritos (user_id, jogador_id) VALUES (?,?)')
        ->execute([$uid, $jogadorId]);
    return true;
}