<?php
/**
 * ── AS APOSTAS DO DIA, SEM MÃO ────────────────────────────────────────
 *
 * Todo dia uma liga joga, e às 11h saem as apostas dela. Era trabalho de
 * digitação: abrir o admin, criar sete a dezoito eventos, escrever as opções
 * de cada um. Dava o que dá trabalho repetitivo — em 06/10/2026 a "Posição do
 * FMVP" foi criada duas vezes (#456 e #457) e a #452 saiu rotulada "Quartas 04
 * - LESTE?" com as opções de semifinal dentro. Quem apostou nessas não tinha
 * como saber.
 *
 * ── QUAL LIGA JOGA HOJE ──────────────────────────────────────────────
 *
 * Não se adivinha: está no calendário. Cada liga tem uma entrada `live`
 * semanal, e o título diz a fase — "Regular ELITE" na quarta, "Playoffs ELITE"
 * na quinta. O dia da semana decide, e a hora da entrada vira o prazo da
 * aposta. Sem entrada no calendário, nada é criado: é melhor o dia passar sem
 * aposta do que sair aposta de liga que não joga.
 *
 * ── O QUE SE SABE E O QUE SE CHUTA ───────────────────────────────────
 *
 * O chaveamento das quartas sai da classificação (1x8, 2x7, 3x6, 4x5), e os
 * nomes dos times são reais. Semifinal, final e campeão ainda não têm dono na
 * hora de abrir, e aí as opções são as mesmas que a liga já usava à mão —
 * "Oeste 1" / "Oeste 2", "Campeao do Oeste" / "Campeão do Leste".
 *
 * Os candidatos a MVP e companhia são PALPITE, e isso é escolha declarada da
 * liga (06/10/2026): saem dos líderes da temporada anterior daquela liga. Não
 * saem do OVR — conferido, não tem relação: no dia 05/10 a liga pôs Air
 * Santos e Gryll Russell, e o topo de OVR da ELITE era Erving, Jordan,
 * Garnett. Toda lista de palpite termina em "Outro", que é quem paga quando o
 * vencedor não estava entre os nomes.
 *
 * ── O PAGAMENTO NÃO CHUTA NUNCA ──────────────────────────────────────
 *
 * Quem resolve é o card Pontuação: `season_awards` para os prêmios,
 * `season_standings` para os seeds, `playoff_series` para os jogos. O robô só
 * paga quando o vencedor casa COM CERTEZA — nome idêntico, ou nenhum nome da
 * lista premiado (e aí ganha o "Outro"). Qualquer dúvida, inclusive nome
 * parecido escrito diferente, não paga: fica na fila com o motivo, porque um
 * pagamento errado mexe no saldo de cinquenta pessoas e o certo é um humano
 * olhar. @see apostasAutoResolver
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/apostas.php';   // apostasPrazoEmTexto()

/** O prêmio fixo por acerto, igual ao do admin à mão (@see admin-apostas.php). */
const APOSTA_PREMIO = 75;

/** As cinco posições, na ordem em que a liga escreve. */
const APOSTA_POSICOES = ['PG', 'SG', 'SF', 'PF', 'C'];

/* ═══════════════════════════ ESTRUTURA ═══════════════════════════════ */

/**
 * Dá a `eventos` e `opcoes` as colunas que a automação precisa.
 *
 * ELAS NÃO EXISTIAM, E ERA ISSO QUE IMPEDIA PAGAR SOZINHO: `eventos` só tinha
 * `nome` em texto livre, escrito de um jeito diferente a cada dia ("Rise - ",
 * "RISE - ", "ROOKIE - ", ou sem prefixo), e `opcoes` só tinha `descricao`.
 * Para saber de que liga e de que prêmio era um evento, só sobrava adivinhar
 * pelo título — e para saber que time é "Bison", comparar string. Com `liga`,
 * `season_id`, `tipo` e a referência da opção, o vínculo passa a ser por id.
 *
 * Idempotente: roda em todo request do cron sem custo depois da primeira vez.
 */
function apostasAutoEstrutura(PDO $pdo): void
{
    static $feito = false;
    if ($feito) return;

    $tem = function (string $tabela, string $coluna) use ($pdo): bool {
        $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns
                              WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
        $st->execute([$tabela, $coluna]);
        return (bool)$st->fetchColumn();
    };

    $colunas = [
        'eventos' => [
            'liga'        => "VARCHAR(10) NULL",
            'season_id'   => "INT NULL",
            'tipo'        => "VARCHAR(32) NULL",
            'tipo_ref'    => "VARCHAR(40) NULL",
            'auto'        => "TINYINT(1) NOT NULL DEFAULT 0",
            'resolvido_em' => "DATETIME NULL",
            /* `eventos` não tinha data de criação. Sem ela não há como saber
               quanto tempo um rascunho esperou revisão — e a carência antes de
               publicar sozinho depende exatamente disso. */
            'criado_em_auto' => "DATETIME NULL",
        ],
        'opcoes' => [
            'ref_tipo' => "VARCHAR(16) NULL",
            'ref_id'   => "INT NULL",
            'ref_txt'  => "VARCHAR(120) NULL",
        ],
    ];
    foreach ($colunas as $tabela => $defs) {
        foreach ($defs as $col => $def) {
            if ($tem($tabela, $col)) continue;
            $pdo->exec("ALTER TABLE `{$tabela}` ADD COLUMN `{$col}` {$def}");
        }
    }
    /* A fila do que não deu pra pagar sozinho. Em tabela própria porque é
       conversa com o admin, não estado da aposta: a mesma aposta pode entrar,
       ser resolvida à mão e sair, sem que `eventos` mude de forma. */
    $pdo->exec("CREATE TABLE IF NOT EXISTS apostas_auto_fila (
        id INT AUTO_INCREMENT PRIMARY KEY,
        evento_id INT NOT NULL,
        motivo VARCHAR(255) NOT NULL,
        detalhe TEXT NULL,
        criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        resolvido_em DATETIME NULL,
        /* Marca de que o dono já foi avisado desta pendência. O cron de
           pagamento roda de dez em dez minutos, e sem esta coluna a mesma
           aposta pendente geraria seis mensagens por hora — que é o jeito
           mais rápido de alguém silenciar o bot e perder o aviso que
           importa. */
        avisado_em DATETIME NULL,
        UNIQUE KEY uq_evento (evento_id)
    ) DEFAULT CHARSET=utf8mb4");
    /* A tabela pode ser anterior a esta coluna. */
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns
                          WHERE table_schema = DATABASE() AND table_name = 'apostas_auto_fila'
                            AND column_name = 'avisado_em'");
    $st->execute();
    if (!$st->fetchColumn()) {
        $pdo->exec("ALTER TABLE apostas_auto_fila ADD COLUMN avisado_em DATETIME NULL");
    }

    /* O WHATSAPP DO DONO, pra avisar do rascunho e da fila. */
    if (!function_exists('apostasAutoNumeroDoDono')) {
        /** O número do dono da liga, no formato da Evolution, ou '' quando não dá. */
        function apostasAutoNumeroDoDono(PDO $pdo): string
        {
            try {
                $st = $pdo->query("SELECT phone FROM users
                                    WHERE user_type = 'admin' AND phone IS NOT NULL AND phone <> ''
                                 ORDER BY id ASC LIMIT 1");
                $tel = (string)($st->fetchColumn() ?: '');
                if ($tel === '' || !function_exists('whatsappNumero')) return '';
                $d = whatsappNumero($tel);
                return $d ? $d . '@s.whatsapp.net' : '';
            } catch (Throwable $e) {
                return '';
            }
        }
    }

    $feito = true;
}

/* ═══════════════════════════ O DIA ══════════════════════════════════ */

/**
 * As ligas que jogam numa data, e em que fase, segundo o calendário.
 *
 * @return array<int, array{liga:string, fase:string, hora:string, titulo:string}>
 */
function apostasAutoDiaDaLiga(PDO $pdo, string $data): array
{
    $dow = (int)date('N', strtotime($data));   // 1=segunda .. 7=domingo
    $out = [];
    $st = $pdo->query("SELECT league, titulo, inicio, repete, repete_ate
                         FROM calendario_eventos WHERE tipo = 'live'");
    foreach ($st as $r) {
        $liga = strtoupper(trim((string)$r['league']));
        if ($liga === '') continue;

        /* Entrada semanal vale em todo dia da semana dela; entrada única só na
           data dela. `repete_ate` encerra a recorrência quando preenchido. */
        $semanal = strtolower((string)$r['repete']) === 'semanal';
        $inicio  = strtotime((string)$r['inicio']);
        if ($semanal) {
            if ((int)date('N', $inicio) !== $dow) continue;
            if (!empty($r['repete_ate']) && strtotime($data) > strtotime((string)$r['repete_ate'])) continue;
            if (strtotime($data) < strtotime(date('Y-m-d', $inicio))) continue;
        } elseif (date('Y-m-d', $inicio) !== date('Y-m-d', strtotime($data))) {
            continue;
        }

        $titulo = (string)$r['titulo'];
        $fase = preg_match('/playoff/i', $titulo) ? 'playoffs' : 'regular';
        $out[] = ['liga' => $liga, 'fase' => $fase, 'hora' => date('H:i:s', $inicio), 'titulo' => $titulo];
    }
    return $out;
}

/** A temporada em curso de uma liga (a que ainda não terminou na sprint ativa). */
function apostasAutoTemporadaAtual(PDO $pdo, string $liga): ?array
{
    $st = $pdo->prepare("SELECT se.id, se.season_number, se.year, se.status, se.current_phase
                           FROM seasons se JOIN sprints sp ON sp.id = se.sprint_id
                          WHERE sp.status = 'active' AND se.league = ? AND se.status <> 'completed'
                       ORDER BY se.season_number ASC LIMIT 1");
    $st->execute([$liga]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** A última temporada CONCLUÍDA da liga — é dela que saem os palpites. */
function apostasAutoTemporadaAnterior(PDO $pdo, string $liga): ?array
{
    $st = $pdo->prepare("SELECT se.id, se.season_number FROM seasons se
                          WHERE se.league = ? AND se.status = 'completed'
                       ORDER BY se.id DESC LIMIT 1");
    $st->execute([$liga]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/* ═══════════════════════ OS CANDIDATOS ══════════════════════════════ */

/** O premiado de um tipo numa temporada, se já houver. */
function apostasAutoPremiado(PDO $pdo, int $seasonId, string $tipo): ?string
{
    $st = $pdo->prepare('SELECT player_name FROM season_awards
                          WHERE season_id = ? AND award_type = ? LIMIT 1');
    $st->execute([$seasonId, $tipo]);
    $v = $st->fetchColumn();
    return $v === false ? null : (string)$v;
}

/**
 * Os líderes de uma estatística numa temporada.
 *
 * O nome vem do `player_season_log` daquela temporada, não de `players`: quem
 * foi dispensado depois não tem mais linha em `players`, e o líder de pontos
 * de uma temporada não pode desaparecer da lista por ter trocado de time.
 */
function apostasAutoLideres(PDO $pdo, int $seasonId, string $coluna, int $quantos = 4): array
{
    $permitidas = ['pts_pg', 'ast_pg', 'reb_pg', 'stl_pg', 'blk_pg'];
    if (!in_array($coluna, $permitidas, true)) return [];
    $st = $pdo->prepare("SELECT (SELECT l.player_name FROM player_season_log l
                                  WHERE l.player_id = ps.player_id AND l.season_id = ps.season_id LIMIT 1) nome
                           FROM player_season_stats ps
                          WHERE ps.season_id = ? AND ps.games >= 20 AND ps.{$coluna} > 0
                       ORDER BY ps.{$coluna} DESC LIMIT ?");
    $st->bindValue(1, $seasonId, PDO::PARAM_INT);
    $st->bindValue(2, $quantos, PDO::PARAM_INT);
    $st->execute();
    return array_values(array_filter(array_column($st->fetchAll(PDO::FETCH_ASSOC), 'nome')));
}

/**
 * Quatro palpites para um prêmio, + "Outro".
 *
 * A régua, escolhida pela liga: o premiado da temporada passada abre a lista,
 * e os líderes da estatística que aquele prêmio costuma seguir completam. É
 * palpite, e é por isso que o "Outro" existe — ele é a opção honesta de quem
 * acha que nenhum dos quatro leva.
 */
function apostasAutoCandidatos(PDO $pdo, string $liga, string $premio, ?int $temporadaAnterior,
                                ?int $temporadaAtual = null): array
{
    $nomes = [];
    if ($temporadaAnterior) {
        if ($p = apostasAutoPremiado($pdo, $temporadaAnterior, $premio)) $nomes[] = $p;

        /* CADA PRÊMIO TEM A SUA PRÓPRIA FONTE, e isso não é capricho: a
           primeira versão puxava os cestinhas para todos, e as cinco apostas
           saíam com os mesmos quatro nomes dentro — Pippen, LeBron, Jordan em
           MVP, MIP, ROY e 6º homem. Lista repetida não é palpite, é enfeite:
           quem aposta não tem no que pensar, e o prêmio deixa de ter cara.

           O MIP compara a temporada com a anterior (quem mais cresceu em
           pontos), o ROY olha os mais novos, o 6º homem os que jogaram menos
           minuto — cada um do jeito que o prêmio é ganho. */
        $fonte = [
            'mvp'     => fn() => apostasAutoLideres($pdo, $temporadaAnterior, 'pts_pg', 6),
            'dpoy'    => fn() => array_merge(apostasAutoLideres($pdo, $temporadaAnterior, 'blk_pg', 3),
                                             apostasAutoLideres($pdo, $temporadaAnterior, 'stl_pg', 3)),
            'mip'     => fn() => apostasAutoMaisCresceram($pdo, $liga, $temporadaAnterior, 6),
            /* O ROY NÃO SAI DA TEMPORADA PASSADA, e isso foi medido: novato é
               quem não jogou antes, então procurá-lo nas estatísticas do ano
               anterior acertava 0 de 48 nas temporadas já fechadas. Procurando
               pelos novatos de OVR mais alto da temporada que vai começar,
               acerta 19 de 48. */
            'roy'     => fn() => apostasAutoNovatos($pdo, $liga, $temporadaAtual, 6),
            '6th_man' => fn() => apostasAutoDoBanco($pdo, $temporadaAnterior, 6),
        ];
        $lista = isset($fonte[$premio]) ? $fonte[$premio]() : [];
        foreach ($lista as $n) {
            if (!in_array($n, $nomes, true)) $nomes[] = $n;
            if (count($nomes) >= 4) break;
        }
        /* Se a fonte própria não encheu (liga sem duas temporadas, ou sem
           ninguém no perfil), os cestinhas completam: melhor um nome plausível
           do que uma aposta com duas opções. */
        if (count($nomes) < 4) {
            foreach (apostasAutoLideres($pdo, $temporadaAnterior, 'pts_pg', 6) as $n) {
                if (!in_array($n, $nomes, true)) $nomes[] = $n;
                if (count($nomes) >= 4) break;
            }
        }
    }
    /* Sem temporada anterior (liga nova, primeira temporada da sprint) não há
       de onde tirar palpite: a aposta sai só com "Outro" e o admin completa.
       Melhor isso do que inventar quatro nomes. */
    $ops = [];
    foreach (array_slice($nomes, 0, 4) as $n) {
        $ops[] = ['desc' => $n, 'ref_tipo' => 'jogador', 'ref_txt' => $n];
    }
    $ops[] = ['desc' => 'Outro', 'ref_tipo' => 'outro'];
    return $ops;
}

/**
 * Quem mais cresceu em pontos de uma temporada para a seguinte — o perfil do MIP.
 *
 * Exige as duas temporadas com estatística; sem a de trás não há crescimento a
 * medir, e a função devolve vazio em vez de inventar um ranking qualquer.
 */
function apostasAutoMaisCresceram(PDO $pdo, string $liga, int $seasonId, int $quantos = 4): array
{
    $st = $pdo->prepare("SELECT id FROM seasons WHERE league = ? AND status = 'completed' AND id < ?
                      ORDER BY id DESC LIMIT 1");
    $st->execute([$liga, $seasonId]);
    $antes = $st->fetchColumn();
    if ($antes === false) return [];

    $st = $pdo->prepare("SELECT (SELECT l.player_name FROM player_season_log l
                                  WHERE l.player_id = agora.player_id AND l.season_id = agora.season_id
                                  LIMIT 1) nome
                           FROM player_season_stats agora
                           JOIN player_season_stats antes
                             ON antes.player_id = agora.player_id AND antes.season_id = ?
                          WHERE agora.season_id = ? AND agora.games >= 20 AND antes.games >= 10
                       ORDER BY (agora.pts_pg - antes.pts_pg) DESC LIMIT ?");
    $st->bindValue(1, (int)$antes, PDO::PARAM_INT);
    $st->bindValue(2, $seasonId, PDO::PARAM_INT);
    $st->bindValue(3, $quantos, PDO::PARAM_INT);
    $st->execute();
    return array_values(array_filter(array_column($st->fetchAll(PDO::FETCH_ASSOC), 'nome')));
}

/**
 * Os novatos de maior OVR da liga — o perfil do ROY.
 *
 * SAI DE `players`, NÃO DO LOG, e tem que ser assim: a aposta abre antes da
 * temporada rodar, e `player_season_log` só ganha a linha quando ela termina.
 * O elenco de `players`, por outro lado, já está montado — o draft é a fase em
 * que a liga está quando as apostas do dia saem.
 *
 * Novato é `seasons_in_league = 0`: nunca completou temporada nesta liga. É o
 * mesmo contador que `recalcularTemporadasNaLiga` mantém, então quem voltou de
 * uma dispensa com histórico não entra aqui por engano.
 */
function apostasAutoNovatos(PDO $pdo, string $liga, ?int $seasonIdAtual, int $quantos = 4): array
{
    $st = $pdo->prepare("SELECT p.name FROM players p JOIN teams t ON t.id = p.team_id
                          WHERE t.league = ? AND COALESCE(p.seasons_in_league, 0) = 0
                       ORDER BY p.ovr DESC, p.name ASC LIMIT ?");
    $st->bindValue(1, $liga, PDO::PARAM_STR);
    $st->bindValue(2, $quantos, PDO::PARAM_INT);
    $st->execute();
    return array_column($st->fetchAll(PDO::FETCH_ASSOC), 'name');
}

/** Os mais novos com minutagem. Reserva do ROY quando não há novato cadastrado. */
function apostasAutoMaisNovos(PDO $pdo, int $seasonId, int $quantos = 4): array
{
    $st = $pdo->prepare("SELECT l.player_name nome
                           FROM player_season_stats ps
                           JOIN player_season_log l
                             ON l.player_id = ps.player_id AND l.season_id = ps.season_id
                          WHERE ps.season_id = ? AND ps.games >= 15 AND l.age IS NOT NULL
                       ORDER BY l.age ASC, ps.pts_pg DESC LIMIT ?");
    $st->bindValue(1, $seasonId, PDO::PARAM_INT);
    $st->bindValue(2, $quantos, PDO::PARAM_INT);
    $st->execute();
    return array_values(array_filter(array_column($st->fetchAll(PDO::FETCH_ASSOC), 'nome')));
}

/**
 * Os melhores de pouco minuto — o perfil do 6º homem.
 *
 * O corte é por minutagem e não pelo `role` da ficha, porque `role` é o de
 * hoje: quem veio do banco na temporada passada pode estar titular agora, e a
 * aposta é sobre o que ele era quando jogou.
 */
function apostasAutoDoBanco(PDO $pdo, int $seasonId, int $quantos = 4): array
{
    $st = $pdo->prepare("SELECT (SELECT l.player_name FROM player_season_log l
                                  WHERE l.player_id = ps.player_id AND l.season_id = ps.season_id
                                  LIMIT 1) nome
                           FROM player_season_stats ps
                          WHERE ps.season_id = ? AND ps.games >= 20 AND ps.min_pg < 28
                       ORDER BY ps.pts_pg DESC LIMIT ?");
    $st->bindValue(1, $seasonId, PDO::PARAM_INT);
    $st->bindValue(2, $quantos, PDO::PARAM_INT);
    $st->execute();
    return array_values(array_filter(array_column($st->fetchAll(PDO::FETCH_ASSOC), 'nome')));
}

/**
 * O jogo da semana daquela liga, se o leilão já fechou HOJE.
 *
 * O confronto não se adivinha: ele é arrematado em `leilao_semana_historico`,
 * e o fechamento sai meio-dia (os registros batem — 12:00:01, 12:00:02,
 * 12:00:12). Por isso a criação das apostas roda 12:05 e não 11h, como era no
 * primeiro desenho: às 11h o jogo ainda não existe.
 *
 * SÓ VALE O QUE FECHOU HOJE. Sem esse corte, num dia em que o leilão atrasou
 * a aposta sairia com o confronto da SEMANA PASSADA — dois times que não vão
 * se enfrentar, e um pagamento que nunca casaria. Não achar nada é resposta
 * boa: a aposta não é criada agora e o cron de dez em dez minutos tenta de
 * novo mais tarde.
 *
 * O nome curto vem de `teams`; o do histórico traz a cidade junto ("Chicago
 * Rail Foxes"), e no resto das apostas o time é "Rail Foxes".
 */
function apostasAutoJogoDaSemana(PDO $pdo, string $liga): ?array
{
    try {
        $st = $pdo->prepare("SELECT h.id, h.time1_id, h.time2_id, h.vencedor_team_id,
                                    COALESCE(TRIM(t1.name), h.time1_nome) AS time1,
                                    COALESCE(TRIM(t2.name), h.time2_nome) AS time2
                               FROM leilao_semana_historico h
                          LEFT JOIN teams t1 ON t1.id = h.time1_id
                          LEFT JOIN teams t2 ON t2.id = h.time2_id
                              WHERE h.league = ? AND DATE(h.fechado_em) = CURDATE()
                                AND h.time1_id IS NOT NULL AND h.time2_id IS NOT NULL
                           ORDER BY h.id DESC LIMIT 1");
        $st->execute([strtoupper(trim($liga))]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        /* A tabela é do Games e pode não existir num banco enxuto. */
        error_log('[apostas_auto] jogo da semana ' . $liga . ': ' . $e->getMessage());
        return null;
    }
}

/** Os times mais fortes de uma conferência, pela classificação da temporada. */
function apostasAutoTopTimes(PDO $pdo, int $seasonId, ?string $conferencia, int $quantos): array
{
    $sql = "SELECT t.id, TRIM(t.name) nome, ss.position
              FROM season_standings ss JOIN teams t ON t.id = ss.team_id
             WHERE ss.season_id = ?"
         . ($conferencia ? ' AND ss.conference = ?' : '')
         . ' ORDER BY ss.position ASC LIMIT ' . max(1, $quantos);
    $st = $pdo->prepare($sql);
    $st->execute($conferencia ? [$seasonId, $conferencia] : [$seasonId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/* ═══════════════════════ O CATÁLOGO DO DIA ══════════════════════════ */

/**
 * As apostas que um dia de uma liga deve ter.
 *
 * Cada uma leva `tipo` e `tipo_ref`, que é o que depois permite pagar sem
 * comparar título: o resolvedor procura por tipo, não por texto.
 */
function apostasAutoCatalogo(PDO $pdo, string $liga, string $fase, array $temporada): array
{
    $seasonId = (int)$temporada['id'];
    $ant = apostasAutoTemporadaAnterior($pdo, $liga);
    $antId = $ant ? (int)$ant['id'] : null;
    /* Os seeds e as quartas se montam da classificação; quando a temporada
       corrente ainda não tem classificação, usa-se a anterior, que é a última
       foto real da força dos times. */
    $paraClassificacao = apostasAutoTopTimes($pdo, $seasonId, 'OESTE', 1) ? $seasonId : (int)($antId ?: $seasonId);

    $apostas = [];

    /* O JOGO DA SEMANA VALE NAS DUAS FASES: o leilão dele roda toda semana,
       independente de a liga estar na temporada regular ou no playoff. Entra
       primeiro porque é a aposta do dia que a liga mais comenta. */
    if ($jogo = apostasAutoJogoDaSemana($pdo, $liga)) {
        $apostas[] = [
            'nome' => 'Quem vence o jogo da semana?',
            'tipo' => 'jogo_semana',
            /* O id do confronto, e não só a liga: o pagamento tem que ler o
               vencedor DAQUELE jogo. Guardar só a liga faria a aposta da
               semana passada ser resolvida pelo jogo da semana seguinte. */
            'tipo_ref' => (string)$jogo['id'],
            'opcoes' => [
                ['desc' => $jogo['time1'], 'ref_tipo' => 'time', 'ref_id' => (int)$jogo['time1_id']],
                ['desc' => $jogo['time2'], 'ref_tipo' => 'time', 'ref_id' => (int)$jogo['time2_id']],
            ],
        ];
    }

    if ($fase === 'regular') {
        foreach (['LESTE', 'OESTE'] as $conf) {
            $times = apostasAutoTopTimes($pdo, $paraClassificacao, $conf, 5);
            $ops = [];
            foreach ($times as $t) {
                $ops[] = ['desc' => $t['nome'], 'ref_tipo' => 'time', 'ref_id' => (int)$t['id']];
            }
            $ops[] = ['desc' => 'Outro', 'ref_tipo' => 'outro'];
            $apostas[] = ['nome' => "Quem vai ser o seed1 - {$conf}?", 'tipo' => 'seed1',
                          'tipo_ref' => $conf, 'opcoes' => $ops];
        }
        /* AS DE PRÊMIO NASCEM ABERTAS, COM A SUGESTÃO DO ROBÔ — decisão da
           liga em 07/10/2026, no primeiro dia do cron: "pode criar os
           jogadores mesmo, qualquer coisa eu arrumo na hora". O desenho
           anterior as deixava em rascunho esperando revisão (a lista à mão
           acerta 64% contra 29% da sugestão), mas o custo de um dia sem
           aposta pesou mais que o palpite mediano — e editar opção ou
           corrigir vencedor continua a um clique. O mecanismo de rascunho
           ('revisar' => true) segue existindo pra quem precisar dele. */
        $premios = ['mvp' => 'MVP', 'mip' => 'MIP', 'dpoy' => 'DPOY',
                    'roy' => 'ROY', '6th_man' => '6º homem'];
        foreach ($premios as $tipo => $rotulo) {
            $apostas[] = ['nome' => "Quem vai ser o {$rotulo}?", 'tipo' => $tipo, 'tipo_ref' => null,
                          'opcoes' => apostasAutoCandidatos($pdo, $liga, $tipo, $antId, $seasonId)];
        }
        // Especiais que o próprio banco responde depois.
        $apostas[] = ['nome' => 'O MVP vai ser de que posição?', 'tipo' => 'pos_mvp', 'tipo_ref' => null,
                      'opcoes' => apostasAutoOpcoesPosicao()];
        $cest = [];
        foreach (apostasAutoLideres($pdo, (int)($antId ?: 0), 'pts_pg', 4) as $n) {
            $cest[] = ['desc' => $n, 'ref_tipo' => 'jogador', 'ref_txt' => $n];
        }
        $cest[] = ['desc' => 'Outro', 'ref_tipo' => 'outro'];
        $apostas[] = ['nome' => 'Cestinha da temporada regular', 'tipo' => 'cestinha', 'tipo_ref' => null,
                      'opcoes' => $cest];
        $maisV = [];
        foreach (apostasAutoTopTimes($pdo, $paraClassificacao, null, 4) as $t) {
            $maisV[] = ['desc' => $t['nome'], 'ref_tipo' => 'time', 'ref_id' => (int)$t['id']];
        }
        $maisV[] = ['desc' => 'Outro', 'ref_tipo' => 'outro'];
        $apostas[] = ['nome' => 'Qual franquia terá mais vitórias?', 'tipo' => 'mais_vitorias',
                      'tipo_ref' => null, 'opcoes' => $maisV];
        return $apostas;
    }

    /* ── PLAYOFFS ─────────────────────────────────────────────────── */
    foreach (['OESTE', 'LESTE'] as $conf) {
        $oito = apostasAutoTopTimes($pdo, $paraClassificacao, $conf, 8);
        /* 1x8, 2x7, 3x6, 4x5 — o cruzamento de sempre. Sem os oito times
           classificados, as quartas daquela conferência não saem: metade de
           um chaveamento é pior que nenhum. */
        if (count($oito) === 8) {
            foreach ([[0, 7], [1, 6], [2, 5], [3, 4]] as $i => [$a, $b]) {
                $apostas[] = [
                    'nome' => 'Quartas 0' . ($i + 1) . " - {$conf}?",
                    'tipo' => 'serie', 'tipo_ref' => "r1:{$conf}:" . ($i + 1),
                    'opcoes' => [
                        ['desc' => $oito[$a]['nome'], 'ref_tipo' => 'time', 'ref_id' => (int)$oito[$a]['id']],
                        ['desc' => $oito[$b]['nome'], 'ref_tipo' => 'time', 'ref_id' => (int)$oito[$b]['id']],
                    ],
                ];
            }
        }
        $cf = ucfirst(strtolower($conf));
        foreach ([1, 2] as $n) {
            $apostas[] = [
                'nome' => "Semis 0{$n} - {$conf}?", 'tipo' => 'serie', 'tipo_ref' => "r2:{$conf}:{$n}",
                'opcoes' => [
                    ['desc' => "{$cf} " . ($n * 2 - 1), 'ref_tipo' => 'vaga'],
                    ['desc' => "{$cf} " . ($n * 2),     'ref_tipo' => 'vaga'],
                ],
            ];
        }
        $apostas[] = [
            'nome' => "Final {$cf}", 'tipo' => 'serie', 'tipo_ref' => "cf:{$conf}:1",
            'opcoes' => [
                ['desc' => "Semi {$cf} 1", 'ref_tipo' => 'vaga'],
                ['desc' => "Semi {$cf} 2", 'ref_tipo' => 'vaga'],
            ],
        ];
    }
    $apostas[] = [
        'nome' => 'Campeão FBA', 'tipo' => 'serie', 'tipo_ref' => 'final::1',
        'opcoes' => [
            ['desc' => 'Campeão do Oeste', 'ref_tipo' => 'vaga', 'ref_txt' => 'OESTE'],
            ['desc' => 'Campeão do Leste', 'ref_tipo' => 'vaga', 'ref_txt' => 'LESTE'],
        ],
    ];
    $apostas[] = ['nome' => 'Posição do FMVP', 'tipo' => 'pos_fmvp', 'tipo_ref' => null,
                  'opcoes' => apostasAutoOpcoesPosicao()];
    /* Quantos 4x0: dos 278 confrontos já jogados na liga, 52 terminaram em
       varrida — então "nenhum" e "1" são as faixas comuns, e 3+ é a zebra. */
    $apostas[] = ['nome' => 'Quantas varridas (4x0) vai ter?', 'tipo' => 'varridas', 'tipo_ref' => null,
                  'opcoes' => [
                      ['desc' => 'Nenhuma', 'ref_tipo' => 'numero', 'ref_txt' => '0'],
                      ['desc' => '1',       'ref_tipo' => 'numero', 'ref_txt' => '1'],
                      ['desc' => '2',       'ref_tipo' => 'numero', 'ref_txt' => '2'],
                      ['desc' => '3 ou mais', 'ref_tipo' => 'numero', 'ref_txt' => '3+'],
                  ]];
    /* Quantos jogos 7: mesma fonte (`playoff_series.jogos`), outra pergunta. */
    $apostas[] = ['nome' => 'Quantos jogos 7 vai ter?', 'tipo' => 'jogos7', 'tipo_ref' => null,
                  'opcoes' => [
                      ['desc' => 'Nenhum', 'ref_tipo' => 'numero', 'ref_txt' => '0'],
                      ['desc' => '1 ou 2', 'ref_tipo' => 'numero', 'ref_txt' => '1-2'],
                      ['desc' => '3 ou 4', 'ref_tipo' => 'numero', 'ref_txt' => '3-4'],
                      ['desc' => '5 ou mais', 'ref_tipo' => 'numero', 'ref_txt' => '5+'],
                  ]];
    return $apostas;
}

/** As cinco posições como opções de aposta. */
function apostasAutoOpcoesPosicao(): array
{
    $ops = [];
    foreach (APOSTA_POSICOES as $p) $ops[] = ['desc' => $p, 'ref_tipo' => 'posicao', 'ref_txt' => $p];
    return $ops;
}

/* ═══════════════════════════ CRIAR ══════════════════════════════════ */

/**
 * Cria as apostas de uma data.
 *
 * @param bool  $aplicar false só descreve, sem gravar nada.
 * @param array|null $somenteTipos Limita a estes tipos. O cron de pagamento
 *        usa com ['jogo_semana'] pra cobrir o leilão que fechou atrasado —
 *        limitado de propósito, porque recriar o catálogo inteiro de dez em
 *        dez minutos ressuscitaria a aposta que o admin apagou de propósito.
 * @return array{criadas:array, puladas:array, erros:array}
 */
function apostasAutoCriar(PDO $pdo, string $data, bool $aplicar = false,
                          ?array $somenteTipos = null): array
{
    apostasAutoEstrutura($pdo);
    $r = ['criadas' => [], 'puladas' => [], 'erros' => []];

    foreach (apostasAutoDiaDaLiga($pdo, $data) as $dia) {
        $temporada = apostasAutoTemporadaAtual($pdo, $dia['liga']);
        if (!$temporada) {
            $r['erros'][] = "{$dia['liga']}: sem temporada em curso na sprint ativa";
            continue;
        }
        $prazo = $data . ' ' . $dia['hora'];
        $catalogo = apostasAutoCatalogo($pdo, $dia['liga'], $dia['fase'], $temporada);

        /* NUM DIA DE PLAYOFF O CRON SÓ FAZ O JOGO DA SEMANA. O chaveamento
           vem da classificação, e às 12:05 ela ainda é a da temporada
           ANTERIOR — quem a torna definitiva é o registro da temporada
           regular, que o admin faz quando bem entende. Criar aqui sairia com
           os confrontos do ano passado, e a trava contra duplicata depois
           impediria o registro de corrigir.

           Então quem cria aposta de playoff é apostasAutoCriarPlayoffs, no
           momento do registro, para as quatro ligas. Isso também cobre a RISE
           (Regular e Playoffs na mesma sexta, que o dia não distingue) e a
           ROOKIE (sem entrada de Playoffs no calendário).

           O jogo da semana fica, porque depende do leilão de meio-dia e não
           da classificação: a ELITE, por exemplo, tem o leilão na quarta e o
           playoff na quinta. */
        if ($dia['fase'] === 'playoffs') {
            $catalogo = array_values(array_filter($catalogo, fn($a) => $a['tipo'] === 'jogo_semana'));
        }

        if ($somenteTipos !== null) {
            $catalogo = array_values(array_filter($catalogo,
                fn($a) => in_array($a['tipo'], $somenteTipos, true)));
        }
        apostasAutoGravarCatalogo($pdo, $catalogo, $dia['liga'], (int)$temporada['id'],
                                  $prazo, $dia['fase'], $aplicar, $r);
    }
    return $r;
}

/**
 * Grava um catálogo já montado, pulando o que já existe.
 *
 * Separado de apostasAutoCriar porque há duas portas de entrada: o cron do dia
 * e o registro da classificação (@see apostasAutoCriarPlayoffs). Duas cópias
 * desta gravação divergiriam, e é justamente aqui que mora a trava contra
 * duplicata.
 */
function apostasAutoGravarCatalogo(PDO $pdo, array $catalogo, string $liga, int $seasonId,
                                   string $prazo, string $fase, bool $aplicar, array &$r): void
{
    foreach ($catalogo as $a) {
        /* A TRAVA CONTRA DUPLICATA, que é o erro que mais apareceu à mão.
           Uma aposta por (liga, temporada, tipo, tipo_ref) — rodar o cron
           duas vezes, ou rodar depois de alguém ter criado à mão com a
           automação ligada, não gera a segunda. */
        $ja = $pdo->prepare("SELECT id FROM eventos
                              WHERE liga = ? AND season_id = ? AND tipo = ?
                                AND COALESCE(tipo_ref, '') = COALESCE(?, '') LIMIT 1");
        $ja->execute([$liga, $seasonId, $a['tipo'], $a['tipo_ref']]);
        if ($id = $ja->fetchColumn()) {
            $r['puladas'][] = "{$liga} {$a['nome']} (já existe, #{$id})";
            continue;
        }
        if (!$aplicar) {
            $r['criadas'][] = ['liga' => $liga, 'fase' => $fase, 'prazo' => $prazo,
                               'nome' => $a['nome'], 'tipo' => $a['tipo'],
                               'status' => !empty($a['revisar']) ? 'rascunho' : 'aberta',
                               'opcoes' => array_column($a['opcoes'], 'desc')];
            continue;
        }
        try {
            $pdo->beginTransaction();
            /* APOSTA DE PALPITE NASCE RASCUNHO; o resto nasce aberta.
               Medido em 06/10/2026 nas 48 temporadas já fechadas: a lista
               feita à mão acerta o vencedor em 64% das vezes (MVP 73%,
               ROY 79%) e a melhor regra automática em 29%. O robô não
               chega perto do olho de quem acompanha a simulação — então
               ele deixa o palpite pronto como sugestão e espera revisão,
               em vez de abrir uma aposta em que o "Outro" ganha quase
               sempre e todo mundo aposta só nele.
               Rascunho não aparece pra liga: a tela do jogador e o texto
               do WhatsApp filtram status 'aberta'. E não fica preso —
               apostasAutoPublicarPendentes abre depois do prazo de
               carência, pra que o dia nunca fique sem aposta. */
            $status = !empty($a['revisar']) ? 'rascunho' : 'aberta';
            $ins = $pdo->prepare("INSERT INTO eventos
                (nome, data_limite, status, liga, season_id, tipo, tipo_ref, auto, criado_em_auto)
                VALUES (?, ?, ?, ?, ?, ?, ?, 1, NOW())");
            $ins->execute([$a['nome'], $prazo, $status, $liga, $seasonId,
                           $a['tipo'], $a['tipo_ref']]);
            $eid = (int)$pdo->lastInsertId();
            $op = $pdo->prepare("INSERT INTO opcoes
                (evento_id, descricao, odd, odd_inicial, ref_tipo, ref_id, ref_txt)
                VALUES (?, ?, 1.00, 1.00, ?, ?, ?)");
            foreach ($a['opcoes'] as $o) {
                $op->execute([$eid, $o['desc'], $o['ref_tipo'] ?? null,
                              $o['ref_id'] ?? null, $o['ref_txt'] ?? null]);
            }
            $pdo->commit();
            $r['criadas'][] = ['id' => $eid, 'liga' => $liga, 'fase' => $fase,
                               'prazo' => $prazo, 'nome' => $a['nome'], 'tipo' => $a['tipo'],
                               'status' => $status,
                               'opcoes' => array_column($a['opcoes'], 'desc')];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $r['erros'][] = "{$liga} {$a['nome']}: " . $e->getMessage();
        }
}
}

/**
 * Cria as apostas de playoff de uma liga assim que a classificação fecha.
 *
 * ── POR QUE NÃO ESPERAR O DIA ────────────────────────────────────────
 *
 * O calendário não serve para todas: a RISE tem Regular e Playoffs na MESMA
 * sexta, então o dia não distingue as duas, e a ROOKIE não tem entrada de
 * Playoffs nenhuma — pelo calendário ela nunca teria aposta de playoff.
 *
 * Mas existe um instante melhor que o dia: quando a classificação da temporada
 * regular é registrada (o primeiro salvamento, o mesmo que fecha a urna da
 * loteria). É aí que as posições viram definitivas, e é delas que sai o
 * cruzamento 1x8, 2x7, 3x6, 4x5. Antes disso o chaveamento seria chute; depois
 * disso ele é fato.
 *
 * @param string|null $prazo Quando a aposta fecha; o padrão é daqui a 3h.
 */
function apostasAutoCriarPlayoffs(PDO $pdo, string $liga, int $seasonId,
                                  bool $aplicar = false, ?string $prazo = null): array
{
    apostasAutoEstrutura($pdo);
    $liga = strtoupper(trim($liga));
    $r = ['criadas' => [], 'puladas' => [], 'erros' => []];

    $st = $pdo->prepare('SELECT id, season_number FROM seasons WHERE id = ? AND league = ?');
    $st->execute([$seasonId, $liga]);
    $temporada = $st->fetch(PDO::FETCH_ASSOC);
    if (!$temporada) {
        $r['erros'][] = "temporada {$seasonId} não é da {$liga}";
        return $r;
    }

    if ($prazo === null) {
        /* A HORA DO PLAYOFF, quando o calendário a tem e ela ainda não passou
           hoje; três horas daqui quando não tem. O prazo só precisa cair
           antes da simulação e depois de agora — prazo no passado nasceria
           fechado, e a liga veria uma aposta em que não dá pra apostar. */
        $prazo = date('Y-m-d H:i:s', time() + 3 * 3600);
        foreach (apostasAutoDiaDaLiga($pdo, date('Y-m-d')) as $dia) {
            if ($dia['liga'] !== $liga || $dia['fase'] !== 'playoffs') continue;
            $candidato = date('Y-m-d') . ' ' . $dia['hora'];
            if (strtotime($candidato) > time()) $prazo = $candidato;
        }
    }

    $catalogo = array_values(array_filter(
        apostasAutoCatalogo($pdo, $liga, 'playoffs', $temporada),
        /* O jogo da semana entra pelo cron do dia, não por aqui: ele depende
           do leilão de meio-dia e não da classificação. */
        fn($a) => $a['tipo'] !== 'jogo_semana'
    ));
    apostasAutoGravarCatalogo($pdo, $catalogo, $liga, (int)$temporada['id'], $prazo, 'playoffs', $aplicar, $r);
    return $r;
}

/* ═══════════════════════════ O GRUPO ════════════════════════════════ */

/**
 * Avisa o grupo principal que abriu aposta, marcando todo mundo.
 *
 * COM @TODOS DE PROPÓSITO, e só aqui e no pagamento: aposta tem prazo, e quem
 * só lê o grupo à noite perde a janela. É o mesmo motivo de o aviso sair
 * quando abre e quando paga, e não a cada palpite — marcar a liga três vezes
 * por dia é o caminho mais curto pra todo mundo silenciar o grupo.
 *
 * Rascunho não entra: ele não está aberto pra ninguém ainda. Quando a revisão
 * (ou a carência) o abrir, ele sai no aviso daquele momento.
 *
 * @param array $criadas O que apostasAutoCriar/CriarPlayoffs devolveu.
 */
function apostasAutoAvisarAbertas(PDO $pdo, array $criadas): bool
{
    $txt = apostasAutoTextoAbertas($criadas);
    return $txt === '' ? false : apostasAutoMandarProGrupo($pdo, $txt);
}

/** O texto do aviso de abertura. Separado do envio pra poder ser conferido. */
function apostasAutoTextoAbertas(array $criadas): string
{
    $abertas = array_values(array_filter($criadas,
        fn($c) => ($c['status'] ?? 'aberta') === 'aberta'));
    if (!$abertas) return '';

    $porLiga = [];
    foreach ($abertas as $c) $porLiga[$c['liga']][] = $c['nome'];

    $l = ['🎯 *APOSTAS ABERTAS*', ''];
    foreach ($porLiga as $liga => $nomes) {
        $l[] = '*' . $liga . '* — ' . count($nomes) . ' aposta' . (count($nomes) === 1 ? '' : 's');
        /* Até dez nomes. Num dia de playoff são dezoito, e a lista inteira
           vira uma parede que ninguém lê — o que importa é saber que abriu e
           quantas são. */
        foreach (array_slice($nomes, 0, 10) as $n) $l[] = '• ' . $n;
        if (count($nomes) > 10) $l[] = '_e mais ' . (count($nomes) - 10) . '_';
        $l[] = '';
    }
    $prazo = $abertas[0]['prazo'] ?? '';
    if ($prazo !== '') {
        /* O texto do prazo já vem conjugado ("falta 1 dia", "faltam 2h"), e
           um "fecham" na frente faz "fecham falta 1 dia". */
        $l[] = '⏰ _' . apostasPrazoEmTexto($prazo) . ' pra palpitar_';
        $l[] = '';
    }
    $l[] = '_Palpite na aba Apostas do /games._';

    return implode("\n", $l);
}

/** Avisa o grupo do que foi pago, marcando todo mundo. */
function apostasAutoAvisarPagas(PDO $pdo, array $pagas): bool
{
    $txt = apostasAutoTextoPagas($pagas);
    return $txt === '' ? false : apostasAutoMandarProGrupo($pdo, $txt);
}

/** O texto do aviso de pagamento. Separado do envio pra poder ser conferido. */
function apostasAutoTextoPagas(array $pagas): string
{
    if (!$pagas) return '';

    $l = ['🏁 *APOSTAS PAGAS*', ''];
    foreach ($pagas as $i => $p) {
        if ($i > 0) $l[] = '';
        $l[] = '*' . $p['evt']['nome'] . '*';
        $l[] = '✅ ' . ($p['vencedor'] ?? '—');
        $q = (int)($p['quantos'] ?? 0);
        /* Zero acertos é informação, não erro: dizer "ninguém acertou" é
           melhor que omitir a aposta e deixar quem palpitou sem resposta. */
        $l[] = $q > 0
            ? '_' . $q . ' acertaram — +' . APOSTA_PREMIO . ' FBA Points cada_'
            : '_ninguém acertou_';
    }
    $l[] = '';
    $l[] = '_Veja tudo na aba Apostas do /games._';

    return implode("\n", $l);
}

/**
 * Põe o texto na fila do grupo principal com @todos.
 *
 * Engole a falha: avisar é consequência de abrir e de pagar, não parte. Um
 * WhatsApp fora do ar não pode fazer o cron parar no meio nem deixar aposta
 * sem pagar.
 */
function apostasAutoMandarProGrupo(PDO $pdo, string $texto): bool
{
    try {
        require_once __DIR__ . '/whatsapp.php';
        if (!function_exists('whatsappParaGrupoPrincipal')) return false;
        whatsappParaGrupoPrincipal($pdo, $texto, 'apostas', true);
        return true;
    } catch (Throwable $e) {
        error_log('[apostas_auto] avisar grupo: ' . $e->getMessage());
        return false;
    }
}

/**
 * Paga as apostas de série FEITAS À MÃO cujo resultado o banco já sabe.
 *
 * O resolvedor normal só toca no que o robô criou (`auto = 1`), porque é lá
 * que existem liga, tipo e referência. Mas o dia de playoff de 06/10/2026
 * deixou dezoito apostas manuais vencidas esperando encerramento um a um —
 * e as de quartas carregam, nas duas opções, os nomes exatos dos times.
 *
 * A adoção paga SÓ o caso sem ambiguidade, e cada exigência abaixo barra um
 * erro real:
 *   · título de série (quartas/semi/final/campeão) — sem isso, "Qual
 *     franquia terá mais vitórias?" com dois times que também se enfrentaram
 *     num mata-mata seria paga com a resposta da pergunta errada;
 *   · exatamente DUAS opções, e as duas batendo com nome de time — opção
 *     "Oeste 1" não é time, e aposta de três opções não é série;
 *   · UM único confronto decidido com exatamente esse par, e só na temporada
 *     de playoff mais recente da liga — o mesmo par pode ter se enfrentado
 *     na edição passada, e aposta velha não se paga com resultado novo.
 * Qualquer coisa fora disso fica como sempre foi: na mão do admin.
 */
function apostasAutoAdotarManuais(PDO $pdo, bool $aplicar = false): array
{
    $pagas = [];
    try {
        $evts = $pdo->query("SELECT e.id, e.nome FROM eventos e
                              WHERE e.status = 'aberta' AND COALESCE(e.auto, 0) = 0
                                AND e.data_limite < NOW()
                           ORDER BY e.id")->fetchAll(PDO::FETCH_ASSOC);
        if (!$evts) return [];

        $ops = $pdo->prepare('SELECT id, descricao FROM opcoes WHERE evento_id = ? ORDER BY id');
        /* O confronto: os dois nomes, em qualquer ordem, na temporada de
           playoff mais recente da liga dentro da sprint ativa. */
        $serie = $pdo->prepare("
            SELECT ps.id, ps.winner_team_id, TRIM(tw.name) vencedor_nome
              FROM playoff_series ps
              JOIN seasons se ON se.id = ps.season_id
              JOIN sprints sp ON sp.id = se.sprint_id AND sp.status = 'active'
              JOIN teams ta ON ta.id = ps.team_a_id
              JOIN teams tb ON tb.id = ps.team_b_id
         LEFT JOIN teams tw ON tw.id = ps.winner_team_id
             WHERE ((TRIM(ta.name) = ? AND TRIM(tb.name) = ?)
                 OR (TRIM(ta.name) = ? AND TRIM(tb.name) = ?))
               AND ps.season_id = (
                   SELECT MAX(ps2.season_id) FROM playoff_series ps2
                     JOIN seasons se2 ON se2.id = ps2.season_id
                     JOIN sprints sp2 ON sp2.id = se2.sprint_id AND sp2.status = 'active'
                    WHERE se2.league = se.league)");

        foreach ($evts as $e) {
            if (!preg_match('/quartas|semi|final|campe/iu', (string)$e['nome'])) continue;
            $ops->execute([(int)$e['id']]);
            $opcoes = $ops->fetchAll(PDO::FETCH_ASSOC);
            if (count($opcoes) !== 2) continue;

            $d1 = trim((string)$opcoes[0]['descricao']);
            $d2 = trim((string)$opcoes[1]['descricao']);
            if ($d1 === '' || $d2 === '' || $d1 === $d2) continue;

            $serie->execute([$d1, $d2, $d2, $d1]);
            $achadas = $serie->fetchAll(PDO::FETCH_ASSOC);
            if (count($achadas) !== 1) continue;                 // zero ou ambíguo: fica pro admin
            if (!$achadas[0]['winner_team_id']) continue;        // série ainda sem vencedor

            $vencNome = (string)$achadas[0]['vencedor_nome'];
            $opVenc = null;
            foreach ($opcoes as $o) {
                if (trim((string)$o['descricao']) === $vencNome) $opVenc = (int)$o['id'];
            }
            if ($opVenc === null) continue;

            if (!$aplicar) {
                $pagas[] = ['evt' => $e, 'op' => $opVenc, 'quantos' => null,
                            'vencedor' => $vencNome, 'motivo' => 'série decidida (aposta manual adotada)'];
                continue;
            }
            $n = apostasAutoPagar($pdo, (int)$e['id'], $opVenc);
            if ($n < 0) continue;
            $pagas[] = ['evt' => $e, 'op' => $opVenc, 'quantos' => $n,
                        'vencedor' => $vencNome, 'motivo' => 'série decidida (aposta manual adotada)'];
        }
    } catch (Throwable $e) {
        /* Adoção é cortesia: falhar aqui não pode derrubar o pagamento das
           apostas do robô, que rodam logo depois no mesmo passe. */
        error_log('[apostas_auto] adotar manuais: ' . $e->getMessage());
    }
    return $pagas;
}

/** O texto de uma opção, pelo id — o aviso diz o que venceu, não um número. */
function apostasAutoDescricaoDaOpcao(array $opcoes, int $opcaoId): string
{
    foreach ($opcoes as $o) {
        if ((int)$o['id'] === $opcaoId) return (string)$o['descricao'];
    }
    return '';
}

/* ═══════════════════════════ RESOLVER ═══════════════════════════════ */

/**
 * Abre os rascunhos que ninguém revisou.
 *
 * A REDE DE SEGURANÇA DO DIA. O rascunho existe pra o dono da liga trocar os
 * quatro palpites por outros melhores; se ele não aparecer, a aposta tem que
 * sair de qualquer jeito — um dia sem aposta de MVP é pior que uma aposta com
 * palpite mediano. Passada a carência, abre com o que o robô sugeriu.
 *
 * Nunca abre rascunho de prazo vencido: publicaria uma aposta que já nasceu
 * fechada, e quem a visse na tela não entenderia por que não dá pra apostar.
 *
 * @param int $carenciaMin Minutos de espera desde a criação.
 */
function apostasAutoPublicarPendentes(PDO $pdo, int $carenciaMin = 180, bool $aplicar = false): array
{
    apostasAutoEstrutura($pdo);
    $st = $pdo->prepare("SELECT id, nome, liga, data_limite, criado_em_auto FROM eventos
                          WHERE auto = 1 AND status = 'rascunho'
                            AND data_limite > NOW()
                            AND COALESCE(criado_em_auto, NOW()) <= DATE_SUB(NOW(), INTERVAL ? MINUTE)
                       ORDER BY id");
    $st->execute([max(0, $carenciaMin)]);
    $linhas = $st->fetchAll(PDO::FETCH_ASSOC);
    if ($aplicar && $linhas) {
        $up = $pdo->prepare("UPDATE eventos SET status = 'aberta' WHERE id = ? AND status = 'rascunho'");
        foreach ($linhas as $l) $up->execute([(int)$l['id']]);
    }
    return $linhas;
}

/**
 * O vencedor de uma aposta, segundo o que o card Pontuação já gravou.
 *
 * @return array{op:?int, motivo:string} `op` null significa "ainda não dá" ou
 *         "não dá com certeza", e `motivo` diz qual dos dois.
 */
function apostasAutoVencedor(PDO $pdo, array $evt, array $opcoes): array
{
    $sid = (int)$evt['season_id'];
    $tipo = (string)$evt['tipo'];

    /* ── Prêmio de jogador: o nome tem que bater exato ───────────── */
    if (in_array($tipo, ['mvp', 'mip', 'dpoy', 'roy', '6th_man'], true)) {
        $nome = apostasAutoPremiado($pdo, $sid, $tipo);
        if ($nome === null) return ['op' => null, 'motivo' => 'o card ainda não tem esse prêmio'];
        return apostasAutoCasaNome($opcoes, $nome);
    }

    /* ── Posição do premiado ─────────────────────────────────────── */
    if ($tipo === 'pos_mvp' || $tipo === 'pos_fmvp') {
        $nome = apostasAutoPremiado($pdo, $sid, $tipo === 'pos_mvp' ? 'mvp' : 'finals_mvp');
        if ($nome === null) return ['op' => null, 'motivo' => 'o card ainda não tem esse prêmio'];
        $pos = apostasAutoPosicaoDoJogador($pdo, $sid, $nome);
        if ($pos === null) {
            return ['op' => null, 'motivo' => "não achei a posição de {$nome}"];
        }
        foreach ($opcoes as $o) {
            if ($o['ref_tipo'] === 'posicao' && strtoupper((string)$o['ref_txt']) === $pos) {
                return ['op' => (int)$o['id'], 'motivo' => "{$nome} é {$pos}"];
            }
        }
        return ['op' => null, 'motivo' => "posição {$pos} não está entre as opções"];
    }

    /* ── Cestinha ────────────────────────────────────────────────── */
    if ($tipo === 'cestinha') {
        $lider = apostasAutoLideres($pdo, $sid, 'pts_pg', 1);
        if (!$lider) return ['op' => null, 'motivo' => 'a temporada ainda não tem estatística'];
        return apostasAutoCasaNome($opcoes, $lider[0]);
    }

    /* ── Seed 1 da conferência, e mais vitórias ──────────────────── */
    if ($tipo === 'seed1' || $tipo === 'mais_vitorias') {
        $conf = $tipo === 'seed1' ? (string)$evt['tipo_ref'] : null;
        $sql = "SELECT ss.team_id FROM season_standings ss WHERE ss.season_id = ?"
             . ($conf ? ' AND ss.conference = ?' : '')
             . ($tipo === 'mais_vitorias' ? ' ORDER BY ss.wins DESC, ss.position ASC'
                                          : ' ORDER BY ss.position ASC') . ' LIMIT 1';
        $st = $pdo->prepare($sql);
        $st->execute($conf ? [$sid, $conf] : [$sid]);
        $time = $st->fetchColumn();
        if ($time === false) return ['op' => null, 'motivo' => 'a temporada ainda não tem classificação'];
        return apostasAutoCasaTime($opcoes, (int)$time);
    }

    /* ── Jogo da semana ──────────────────────────────────────────── */
    if ($tipo === 'jogo_semana') {
        $st = $pdo->prepare('SELECT vencedor_team_id FROM leilao_semana_historico WHERE id = ?');
        $st->execute([(int)$evt['tipo_ref']]);
        $venc = $st->fetchColumn();
        if ($venc === false) return ['op' => null, 'motivo' => 'não achei esse jogo da semana'];
        /* Vencedor em branco é jogo que ainda não foi declarado no painel —
           espera, não problema. O leilão fecha ao meio-dia e o resultado só
           entra depois da simulação. */
        if ($venc === null || (int)$venc === 0) {
            return ['op' => null, 'motivo' => 'o jogo da semana ainda não tem vencedor declarado'];
        }
        return apostasAutoCasaTime($opcoes, (int)$venc);
    }

    /* ── Confronto de playoff ────────────────────────────────────── */
    if ($tipo === 'serie') {
        [$fase, $conf] = array_pad(explode(':', (string)$evt['tipo_ref']), 3, '');
        $st = $pdo->prepare("SELECT team_a_id, team_b_id, winner_team_id FROM playoff_series
                              WHERE season_id = ? AND fase = ?"
             . ($conf !== '' ? ' AND conferencia = ?' : ' AND (conferencia IS NULL OR conferencia = \'\')')
             . ' ORDER BY id ASC');
        $st->execute($conf !== '' ? [$sid, $fase, $conf] : [$sid, $fase]);
        $series = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!$series) return ['op' => null, 'motivo' => 'esse confronto ainda não foi jogado'];

        /* ACHA A SÉRIE PELOS TIMES, NÃO PELA ORDEM. A primeira versão casava
           "Quartas 01" com a primeira linha de `playoff_series` por id, o que
           só funciona se o motor gravar as séries exatamente na ordem 1x8,
           2x7, 3x6, 4x5 — e nada garante isso. A aposta já carrega os dois
           times nas opções; a série que tem esses dois é a dela, em qualquer
           ordem de gravação. */
        $meus = [];
        foreach ($opcoes as $o) if ($o['ref_tipo'] === 'time') $meus[] = (int)$o['ref_id'];
        if (count($meus) === 2) {
            foreach ($series as $s) {
                $par = [(int)$s['team_a_id'], (int)$s['team_b_id']];
                sort($par); $alvo = $meus; sort($alvo);
                if ($par !== $alvo) continue;
                if (!$s['winner_team_id']) {
                    return ['op' => null, 'motivo' => 'o confronto ainda não tem vencedor'];
                }
                return apostasAutoCasaTime($opcoes, (int)$s['winner_team_id']);
            }
            return ['op' => null, 'motivo' => 'não achei a série desses dois times'];
        }

        /* Opções de vaga ("Oeste 1", "Campeão do Leste"): o campeão dá pra
           resolver, porque basta saber de que conferência ele veio. As demais
           vagas, não — e aí é fila, que é o certo: ninguém sabe se "Oeste 1"
           era o time que ganhou. */
        if ($fase === 'final' && count($series) === 1 && $series[0]['winner_team_id']) {
            $campeao = (int)$series[0]['winner_team_id'];
            $cf = $pdo->prepare('SELECT conference FROM season_standings
                                  WHERE season_id = ? AND team_id = ? LIMIT 1');
            $cf->execute([$sid, $campeao]);
            $conferencia = strtoupper((string)($cf->fetchColumn() ?: ''));
            if ($conferencia === '') {
                return ['op' => null, 'motivo' => "não sei de que conferência é o time #{$campeao}"];
            }
            foreach ($opcoes as $o) {
                if (strtoupper((string)$o['ref_txt']) === $conferencia) {
                    return ['op' => (int)$o['id'], 'motivo' => "campeão veio do {$conferencia}"];
                }
            }
            return ['op' => null, 'motivo' => "campeão do {$conferencia} não casa com as opções"];
        }
        return ['op' => null, 'motivo' => 'ainda não há times definidos nessas opções'];
    }

    /* ── Contagens do chaveamento ────────────────────────────────── */
    if ($tipo === 'varridas' || $tipo === 'jogos7') {
        $st = $pdo->prepare("SELECT COUNT(*) total,
                                    SUM(jogos = 4) varridas, SUM(jogos = 7) sete
                               FROM playoff_series WHERE season_id = ? AND winner_team_id IS NOT NULL");
        $st->execute([$sid]);
        $c = $st->fetch(PDO::FETCH_ASSOC);
        if (!$c || !(int)$c['total']) return ['op' => null, 'motivo' => 'o playoff ainda não foi jogado'];
        /* Só conta quando o chaveamento ACABOU: o campeão tem que existir,
           senão "nenhuma varrida" seria verdade no meio do caminho e falsa no
           fim. */
        $fim = $pdo->prepare("SELECT COUNT(*) FROM playoff_series
                               WHERE season_id = ? AND fase = 'final' AND winner_team_id IS NOT NULL");
        $fim->execute([$sid]);
        if (!$fim->fetchColumn()) return ['op' => null, 'motivo' => 'o playoff ainda não terminou'];
        $n = $tipo === 'varridas' ? (int)$c['varridas'] : (int)$c['sete'];
        foreach ($opcoes as $o) {
            if ($o['ref_tipo'] !== 'numero') continue;
            if (apostasAutoFaixaCasa((string)$o['ref_txt'], $n)) {
                return ['op' => (int)$o['id'], 'motivo' => "deu {$n}"];
            }
        }
        return ['op' => null, 'motivo' => "deu {$n}, e nenhuma faixa cobre isso"];
    }

    return ['op' => null, 'motivo' => "tipo '{$tipo}' não tem regra de resolução"];
}

/** Uma faixa de opção ("0", "1-2", "3+") cobre um número? */
function apostasAutoFaixaCasa(string $faixa, int $n): bool
{
    $faixa = trim($faixa);
    if (preg_match('/^(\d+)\+$/', $faixa, $m))            return $n >= (int)$m[1];
    if (preg_match('/^(\d+)\s*-\s*(\d+)$/', $faixa, $m))  return $n >= (int)$m[1] && $n <= (int)$m[2];
    if (ctype_digit($faixa))                              return $n === (int)$faixa;
    return false;
}

/**
 * Casa o nome do premiado com uma opção.
 *
 * COMPARAÇÃO EXATA, de propósito. "C. Webber" e "Chris Webber" podem ser a
 * mesma pessoa, e o parecido mais próximo acertaria quase sempre — mas quando
 * errasse, pagaria a opção errada para dezenas de apostadores de uma vez, e
 * sem ninguém perceber. O único afrouxamento é o do espaço em branco e da
 * caixa, que não mudam quem é o jogador.
 */
function apostasAutoCasaNome(array $opcoes, string $nome): array
{
    $chave = fn($s) => mb_strtolower(preg_replace('/\s+/', ' ', trim((string)$s)));
    $alvo = $chave($nome);
    $outro = null;
    foreach ($opcoes as $o) {
        if ($o['ref_tipo'] === 'outro') { $outro = (int)$o['id']; continue; }
        $cand = $o['ref_txt'] !== null && $o['ref_txt'] !== '' ? $o['ref_txt'] : $o['descricao'];
        if ($chave($cand) === $alvo) return ['op' => (int)$o['id'], 'motivo' => "venceu {$nome}"];
    }
    /* Ninguém da lista levou: quem apostou "Outro" acertou. Isso É certeza —
       a lista é fechada e o vencedor não está nela. */
    if ($outro !== null) {
        return ['op' => $outro, 'motivo' => "{$nome} não estava na lista: paga Outro"];
    }
    return ['op' => null, 'motivo' => "{$nome} não casa com nenhuma opção e não há Outro"];
}

/** Casa um time vencedor com uma opção, por id — sem texto no meio. */
function apostasAutoCasaTime(array $opcoes, int $teamId): array
{
    $outro = null;
    foreach ($opcoes as $o) {
        if ($o['ref_tipo'] === 'outro') { $outro = (int)$o['id']; continue; }
        if ($o['ref_tipo'] === 'time' && (int)$o['ref_id'] === $teamId) {
            return ['op' => (int)$o['id'], 'motivo' => "venceu o time #{$teamId}"];
        }
    }
    /* Opção de vaga ("Oeste 1", "Campeão do Leste") não é time ainda: não dá
       pra dizer se o vencedor é ela. Fica pro humano. */
    foreach ($opcoes as $o) {
        if ($o['ref_tipo'] === 'vaga') {
            return ['op' => null, 'motivo' => 'ainda não há times definidos nessas opções'];
        }
    }
    if ($outro !== null) return ['op' => $outro, 'motivo' => "time #{$teamId} fora da lista: paga Outro"];
    return ['op' => null, 'motivo' => "time #{$teamId} não casa com nenhuma opção"];
}

/** A posição de um jogador premiado, pelo log da temporada e depois por `players`. */
function apostasAutoPosicaoDoJogador(PDO $pdo, int $seasonId, string $nome): ?string
{
    $st = $pdo->prepare('SELECT position FROM player_season_log
                          WHERE season_id = ? AND player_name = ? AND position IS NOT NULL
                       ORDER BY id DESC LIMIT 1');
    $st->execute([$seasonId, $nome]);
    $p = $st->fetchColumn();
    if ($p === false || $p === null || $p === '') {
        $st = $pdo->prepare('SELECT position FROM players WHERE name = ? LIMIT 1');
        $st->execute([$nome]);
        $p = $st->fetchColumn();
    }
    if ($p === false || $p === null) return null;
    $p = strtoupper(trim(explode('/', (string)$p)[0]));
    return in_array($p, APOSTA_POSICOES, true) ? $p : null;
}

/* ═══════════════════════════ PAGAR ══════════════════════════════════ */

/**
 * Encerra uma aposta e paga quem acertou.
 *
 * A conta é a mesma do admin à mão: +75 FBA Points e +1 acerto por pessoa que
 * apostou na opção vencedora, uma vez só por pessoa (@see admin-apostas.php).
 * Em transação com o encerramento, porque pagar sem encerrar pagaria de novo
 * na próxima rodada do cron.
 */
function apostasAutoPagar(PDO $pdo, int $eventoId, int $opcaoId): int
{
    $pdo->beginTransaction();
    try {
        /* FOR UPDATE e a conferência do status: duas rodadas do cron ao mesmo
           tempo não podem pagar a mesma aposta duas vezes. */
        $st = $pdo->prepare('SELECT status FROM eventos WHERE id = ? FOR UPDATE');
        $st->execute([$eventoId]);
        if (($st->fetchColumn() ?: '') !== 'aberta') { $pdo->rollBack(); return -1; }

        $pdo->prepare("UPDATE eventos SET status = 'encerrada', vencedor_opcao_id = ?,
                              resolvido_em = NOW() WHERE id = ?")->execute([$opcaoId, $eventoId]);
        $pay = $pdo->prepare('UPDATE games_usuarios u
                              JOIN (SELECT DISTINCT id_usuario FROM palpites WHERE opcao_id = ?) p
                                ON p.id_usuario = u.id
                               SET u.fba_points = u.fba_points + ' . APOSTA_PREMIO . ',
                                   u.acertos_eventos = u.acertos_eventos + 1');
        $pay->execute([$opcaoId]);
        $n = $pay->rowCount();
        $pdo->commit();
        return $n;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[apostas_auto] pagar evento ' . $eventoId . ': ' . $e->getMessage());
        return -1;
    }
}

/**
 * Varre as apostas abertas da automação e paga o que der com certeza.
 *
 * @param bool $aplicar false só descreve.
 * @return array{pagas:array, esperando:array, fila:array}
 */
function apostasAutoResolver(PDO $pdo, bool $aplicar = false): array
{
    apostasAutoEstrutura($pdo);
    $r = ['pagas' => [], 'esperando' => [], 'fila' => []];

    /* Primeiro as manuais adotáveis, depois as do robô: as duas entram no
       mesmo aviso de "APOSTAS PAGAS". @see apostasAutoAdotarManuais */
    foreach (apostasAutoAdotarManuais($pdo, $aplicar) as $paga) $r['pagas'][] = $paga;

    $evts = $pdo->query("SELECT id, nome, liga, season_id, tipo, tipo_ref
                           FROM eventos
                          WHERE auto = 1 AND status = 'aberta' AND tipo IS NOT NULL
                       ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);

    $ops = $pdo->prepare('SELECT id, descricao, ref_tipo, ref_id, ref_txt
                            FROM opcoes WHERE evento_id = ? ORDER BY id');
    foreach ($evts as $e) {
        $ops->execute([(int)$e['id']]);
        $opcoes = $ops->fetchAll(PDO::FETCH_ASSOC);
        if (!$opcoes) { $r['fila'][] = ['evt' => $e, 'motivo' => 'aposta sem opções']; continue; }

        $v = apostasAutoVencedor($pdo, $e, $opcoes);
        if ($v['op'] === null) {
            /* "Ainda não deu" é espera, não problema: o card não foi
               preenchido. Só vira fila o que já tem resultado e não casa. */
            $espera = str_contains($v['motivo'], 'ainda não');
            $r[$espera ? 'esperando' : 'fila'][] = ['evt' => $e, 'motivo' => $v['motivo']];
            if (!$espera && $aplicar) {
                $pdo->prepare('INSERT INTO apostas_auto_fila (evento_id, motivo, detalhe)
                               VALUES (?, ?, ?)
                               ON DUPLICATE KEY UPDATE motivo = VALUES(motivo)')
                    ->execute([(int)$e['id'], mb_substr($v['motivo'], 0, 250), $e['nome']]);
            }
            continue;
        }
        if (!$aplicar) {
            $r['pagas'][] = ['evt' => $e, 'op' => $v['op'], 'motivo' => $v['motivo'], 'quantos' => null,
                             'vencedor' => apostasAutoDescricaoDaOpcao($opcoes, (int)$v['op'])];
            continue;
        }
        $n = apostasAutoPagar($pdo, (int)$e['id'], (int)$v['op']);
        if ($n < 0) { $r['fila'][] = ['evt' => $e, 'motivo' => 'erro ao pagar']; continue; }
        $pdo->prepare('UPDATE apostas_auto_fila SET resolvido_em = NOW() WHERE evento_id = ?')
            ->execute([(int)$e['id']]);
        $r['pagas'][] = ['evt' => $e, 'op' => $v['op'], 'motivo' => $v['motivo'], 'quantos' => $n,
                         'vencedor' => apostasAutoDescricaoDaOpcao($opcoes, (int)$v['op'])];
    }
    return $r;
}
