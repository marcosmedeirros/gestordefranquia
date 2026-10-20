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

/** O que o time escalado tem que ter: 2 armadores, 2 alas, 1 pivô. */
const FNBA_FORMACAO = ['G' => 2, 'F' => 2, 'C' => 1];

/** Quantos jogadores entram na escalação. */
const FNBA_ELENCO = 5;

/** O capitão dobra. Mais que isso e a rodada vira só a escolha dele. */
const FNBA_CAPITAO = 2.0;

/**
 * O orçamento, em F$.
 *
 * Calibrado contra a temporada 2025-26 inteira (560 jogadores, dados da
 * ESPN): com teto de 40 e 100 de orçamento, os cinco melhores da liga
 * custariam 175 — não cabem. Dá pra levar um Jokic (40) e completar com
 * quatro de 15, ou cinco medianos de 20. As duas somam perto de 160 pontos
 * de média, e é isso que faz a escolha valer: não existe time óbvio.
 */
const FNBA_ORCAMENTO = 100.0;

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
    foreach (FNBA_FORMACAO as $pos => $quantos) {
        if ($porPos[$pos] !== $quantos) {
            $nomes = ['G' => 'armador', 'F' => 'ala', 'C' => 'pivô'];
            $erros[] = "A formação pede {$quantos} " . $nomes[$pos] . ($quantos > 1 ? 'es' : '')
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

    $st = $pdo->prepare("SELECT user_id, SUM(fp * IF(capitao = 1, " . FNBA_CAPITAO . ", 1)) AS pontos
                           FROM fnba_escalacoes WHERE rodada_id = ? GROUP BY user_id
                       ORDER BY pontos DESC");
    $st->execute([$rodadaId]);
    $linhas = $st->fetchAll(PDO::FETCH_ASSOC);

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

/** O mercado: quem dá pra escalar, do mais caro ao mais barato. */
function fnbaMercado(PDO $pdo): array
{
    fnbaTabelas($pdo);
    return $pdo->query("SELECT j.id, j.nome, j.pos, j.preco, j.media_fp, j.jogos_base, j.foto, t.sigla, t.id AS time_id
                          FROM fnba_jogadores j
                     LEFT JOIN fnba_times t ON t.id = j.time_id
                         WHERE j.ativo = 1
                      ORDER BY j.preco DESC, j.nome")->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
