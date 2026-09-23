<?php
/**
 * O MOTOR DA PUNIÇÃO: quanto tempo ela vale e quem está cumprindo o quê.
 *
 * Uma régua só, consultada por todo mundo — trades, free agency, draft, leilão
 * e a tag do time. Antes cada endpoint tinha a sua cópia (isTeamTradeBanned em
 * api/trades.php, isTeamFaBanned em api/free-agency.php), as duas com o mesmo
 * erro de contagem, e nada disso valia pra perda de pick, que era aplicada na
 * mão.
 *
 * O ERRO QUE ISTO CORRIGE. A pena só sabia contar em ciclo:
 *
 *     $banUntil = $currentCycle;                      // "esta temporada"
 *     if ($scope === 'next') $banUntil = $cycle + 1;  // "a próxima"
 *     ...
 *     return $currentCycle > 0 && $currentCycle <= $banUntil;
 *
 * Ciclo são DUAS temporadas. Então "Trades bloqueadas por uma temporada"
 * prendia o time por duas, e "próxima temporada" prendia por quatro. O admin
 * lia um prazo na tela e o time cumpria outro.
 *
 * Aqui temporada é temporada e ciclo é ciclo. O ciclo é o mesmo do reset de
 * trades — ceil(season_number / 2), a conta que api/seasons.php já usa —,
 * que é o "ciclo de trades (duas temporadas)" de que os editais falam.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/punicoes_catalogo.php';

/* ─────────────────────────────────────────────────────────────────────
   ONDE ISSO MORA
   ───────────────────────────────────────────────────────────────────── */

function punicaoGarantirEsquema(PDO $pdo): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;
    $ok = false;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS punicao_infracoes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            league VARCHAR(20) NOT NULL,
            codigo VARCHAR(8) NOT NULL,
            titulo VARCHAR(160) NOT NULL,
            artigo VARCHAR(60) NULL,
            ordem INT NOT NULL DEFAULT 0,
            ativa TINYINT(1) NOT NULL DEFAULT 1,
            criada_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_infracao (league, codigo),
            INDEX idx_infracao_liga (league, ativa, ordem)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        /* O degrau é a linha "2ª ocorrência: perde a pick + fica sem trocar".
           Ocorrência 0 é a pena que não escala — o edital escreve "já na 1ª
           ocorrência" e "de forma irrecorrível" em várias infrações. */
        $pdo->exec("CREATE TABLE IF NOT EXISTS punicao_degraus (
            id INT AUTO_INCREMENT PRIMARY KEY,
            infracao_id INT NOT NULL,
            ocorrencia TINYINT NOT NULL DEFAULT 1,
            efeitos TEXT NOT NULL,
            UNIQUE KEY uniq_degrau (infracao_id, ocorrencia),
            FOREIGN KEY (infracao_id) REFERENCES punicao_infracoes(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        foreach ([
            'infracao_id'  => 'ALTER TABLE team_punishments ADD COLUMN infracao_id INT NULL',
            'ocorrencia'   => 'ALTER TABLE team_punishments ADD COLUMN ocorrencia TINYINT NULL',
            'efeitos_json' => 'ALTER TABLE team_punishments ADD COLUMN efeitos_json TEXT NULL',
            'vigencia'       => "ALTER TABLE team_punishments ADD COLUMN vigencia VARCHAR(20) NULL",
            'vigencia_desde' => 'ALTER TABLE team_punishments ADD COLUMN vigencia_desde INT NULL',
            'vigencia_ate'   => 'ALTER TABLE team_punishments ADD COLUMN vigencia_ate INT NULL',
            /* JÁ CUMPRIDA: a punição fica registrada, mas não pune.
               Acontece sempre que o GM já pagou a pena fora do sistema — o
               admin aplicou na mão antes de a tela existir, ou a infração
               virou acordo no grupo. Registrar mesmo assim importa, porque a
               reincidência conta o degrau seguinte; o que não pode é cobrar
               duas vezes. Nasce 0: punição nova pune. */
            'ja_cumprida'    => 'ALTER TABLE team_punishments ADD COLUMN ja_cumprida TINYINT(1) NOT NULL DEFAULT 0',
        ] as $col => $sql) {
            try {
                if ($pdo->query("SHOW COLUMNS FROM team_punishments LIKE '{$col}'")->rowCount() === 0) {
                    $pdo->exec($sql);
                }
            } catch (Throwable $e) { /* tabela ainda não existe: api/punicoes.php cria */ }
        }

        /* A pick PARA DE SER APAGADA. Antes a punição dava DELETE na linha de
           `picks`, e o time continuava escolhendo: draftSincronizarOrdem() lê
           o dono a partir de `picks` e deixa a vaga como está quando não acha
           pick nenhuma ("não é o caso desta função inventar dono"). Ou seja, a
           punição mais usada da liga não tirava ninguém do draft.

           Agora a pick fica, marcada. Quem lê a ordem mostra PUNIDO e pula, e
           a Trade Machine se recusa a negociá-la. */
        try {
            if ($pdo->query("SHOW COLUMNS FROM picks LIKE 'punicao_id'")->rowCount() === 0) {
                $pdo->exec('ALTER TABLE picks ADD COLUMN punicao_id INT NULL DEFAULT NULL');
            }
        } catch (Throwable $e) {}

        $ok = true;
    } catch (Throwable $e) {
        error_log('[punicoes] esquema: ' . $e->getMessage());
    }
    return $ok;
}

/* ─────────────────────────────────────────────────────────────────────
   TEMPORADA E CICLO
   ───────────────────────────────────────────────────────────────────── */

/**
 * Em que temporada e em que ciclo a liga está agora.
 *
 * O ciclo sai da temporada, não de teams.current_cycle: aquela coluna é um
 * cache sincronizado na virada e um time que entrou depois fica com o número
 * velho. A conta é a mesma de api/seasons.php — temporadas 1-2 no ciclo 1,
 * 3-4 no ciclo 2.
 */
function punicaoMomentoDaLiga(PDO $pdo, string $league): array
{
    require_once __DIR__ . '/helpers.php';
    $t = temporadaAtivaDaLiga($pdo, $league);
    $temporada = (int)($t['season_number'] ?? 0);
    if ($temporada <= 0) {
        try {
            $st = $pdo->prepare('SELECT MAX(current_cycle) FROM teams WHERE league = ?');
            $st->execute([strtoupper(trim($league))]);
            $ciclo = (int)($st->fetchColumn() ?: 0);
        } catch (Throwable $e) { $ciclo = 0; }
        return ['temporada' => 0, 'ciclo' => $ciclo];
    }
    return ['temporada' => $temporada, 'ciclo' => (int)ceil($temporada / 2)];
}

/**
 * De uma duração do catálogo para o par (o que contar, até quando).
 *
 * `evento` é a pena que acontece de uma vez — perder a pick, anular a troca.
 * Não tem prazo pra correr, então não entra na conta de "está cumprindo".
 */
function punicaoVigenciaDe(string $duracao, int $temporada, int $ciclo): array
{
    /* O `desde` existe por causa do "na temporada subsequente" do edital: essa
       pena NÃO vale no resto da temporada em que a infração aconteceu, começa
       na virada. Sem ele eu repetiria o erro que vim consertar, só na outra
       direção — prendendo antes da hora em vez de depois. */
    return match (strtoupper(trim($duracao))) {
        'TEMPORADA'      => ['vigencia' => 'TEMPORADA', 'desde' => $temporada,     'ate' => $temporada],
        'TEMPORADA_NEXT' => ['vigencia' => 'TEMPORADA', 'desde' => $temporada + 1, 'ate' => $temporada + 1],
        'CICLO'          => ['vigencia' => 'CICLO',     'desde' => $ciclo,         'ate' => $ciclo],
        'CICLO_NEXT'     => ['vigencia' => 'CICLO',     'desde' => $ciclo + 1,     'ate' => $ciclo + 1],
        'PERMANENTE'     => ['vigencia' => 'PERMANENTE','desde' => null,           'ate' => null],
        default          => ['vigencia' => 'EVENTO',    'desde' => null,           'ate' => null],
    };
}

/** A pena já começou e ainda não acabou? */
function punicaoVigente(?string $vigencia, ?int $ate, int $temporada, int $ciclo, ?int $desde = null): bool
{
    return match (strtoupper(trim((string)$vigencia))) {
        'TEMPORADA'  => $ate !== null && $temporada > 0
                        && $temporada <= $ate && ($desde === null || $temporada >= $desde),
        'CICLO'      => $ate !== null && $ciclo > 0
                        && $ciclo <= $ate && ($desde === null || $ciclo >= $desde),
        'PERMANENTE' => true,
        default      => false,   // evento não corre no tempo
    };
}

/** Já começou, ou é pena marcada pra virada? */
function punicaoAindaNaoComecou(?string $vigencia, ?int $desde, int $temporada, int $ciclo): bool
{
    if ($desde === null) return false;
    return match (strtoupper(trim((string)$vigencia))) {
        'TEMPORADA' => $temporada > 0 && $temporada < $desde,
        'CICLO'     => $ciclo > 0 && $ciclo < $desde,
        default     => false,
    };
}

/** "até a temporada 5" / "a partir da temporada 3" — o que a tela mostra. */
function punicaoVigenciaTexto(?string $vigencia, ?int $ate, ?int $desde = null, int $temporada = 0, int $ciclo = 0): string
{
    $v = strtoupper(trim((string)$vigencia));
    if ($v === 'PERMANENTE') return 'sem prazo, até ser revertida';
    if ($v !== 'TEMPORADA' && $v !== 'CICLO') return 'aplicada de uma vez';

    $unidade = $v === 'TEMPORADA' ? 'temporada' : 'ciclo';
    $comeca  = punicaoAindaNaoComecou($v, $desde, $temporada, $ciclo);

    if ($comeca) {
        [$em, $de, $ata] = $v === 'TEMPORADA' ? ['na', 'da', 'até a'] : ['no', 'do', 'até o'];
        return $desde === $ate
            ? "só {$em} {$unidade} " . (int)$desde
            : "{$de} {$unidade} " . (int)$desde . " {$ata} " . (int)$ate;
    }
    return $v === 'TEMPORADA'
        ? 'até o fim da temporada ' . (int)$ate
        : 'até o fim do ciclo ' . (int)$ate . ' (2 temporadas)';
}

/* ─────────────────────────────────────────────────────────────────────
   QUEM ESTÁ CUMPRINDO O QUÊ
   ───────────────────────────────────────────────────────────────────── */

/**
 * Os efeitos que pesam sobre o time AGORA.
 *
 * @return array<string,array> efeito => ['valor','vigencia','ate','texto','punicao_id','infracao']
 */
function punicaoEfeitosAtivos(PDO $pdo, int $teamId): array
{
    static $cache = [];
    if (isset($cache[$teamId])) return $cache[$teamId];
    if (!punicaoGarantirEsquema($pdo)) return $cache[$teamId] = [];

    try {
        $st = $pdo->prepare('SELECT league FROM teams WHERE id = ?');
        $st->execute([$teamId]);
        $league = (string)($st->fetchColumn() ?: '');
        if ($league === '') return $cache[$teamId] = [];

        $m = punicaoMomentoDaLiga($pdo, $league);

        $st = $pdo->prepare("SELECT tp.id, tp.efeitos_json, tp.vigencia, tp.vigencia_desde, tp.vigencia_ate,
                                    i.titulo AS infracao, i.artigo
                               FROM team_punishments tp
                          LEFT JOIN punicao_infracoes i ON i.id = tp.infracao_id
                              WHERE tp.team_id = ? AND tp.reverted_at IS NULL
                                AND tp.ja_cumprida = 0
                                AND tp.efeitos_json IS NOT NULL
                           ORDER BY tp.id ASC");
        $st->execute([$teamId]);

        $saida = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $efeitos = json_decode((string)$p['efeitos_json'], true);
            if (!is_array($efeitos)) continue;

            foreach ($efeitos as $e) {
                $nome = strtoupper(trim((string)($e['efeito'] ?? '')));
                if (!isset(PUNICAO_EFEITOS[$nome])) continue;

                /* A vigência pode ser por efeito (a mesma infração dá rotação
                   por uma temporada e trade ban pelo ciclo) ou da punição
                   inteira. A do efeito manda. */
                $vig   = $e['vigencia'] ?? $p['vigencia'];
                $ate   = array_key_exists('ate', $e) ? $e['ate'] : $p['vigencia_ate'];
                $desde = array_key_exists('desde', $e) ? $e['desde'] : $p['vigencia_desde'];
                $ate   = $ate === null ? null : (int)$ate;
                $desde = $desde === null ? null : (int)$desde;
                if (!punicaoVigente($vig, $ate, $m['temporada'], $m['ciclo'], $desde)) continue;

                /* Duas punições com o mesmo efeito: fica a que dura mais. Quem
                   pergunta quer saber até quando está preso, não quantas penas
                   somou. PERMANENTE (ate = null) ganha de qualquer prazo. */
                $atual = $saida[$nome] ?? null;
                $mandaEsta = $atual === null
                    || $ate === null
                    || ($atual['ate'] !== null && (int)$ate > (int)$atual['ate']);

                $linha = [
                    'valor'      => isset($e['valor']) ? (int)$e['valor'] : null,
                    'vigencia'   => strtoupper(trim((string)$vig)),
                    'desde'      => $desde,
                    'ate'        => $ate,
                    'texto'      => punicaoVigenciaTexto($vig, $ate, $desde, $m['temporada'], $m['ciclo']),
                    'label'      => PUNICAO_EFEITOS[$nome]['label'],
                    'tag'        => PUNICAO_EFEITOS[$nome]['tag'],
                    'punicao_id' => (int)$p['id'],
                    'infracao'   => $p['infracao'] ?: null,
                    'artigo'     => $p['artigo'] ?: null,
                ];

                /* PERDA_TRADES soma: duas penas de 8 e de 1 tiram 9 do ciclo,
                   não 8. É a única em que "fica a maior" seria perdão. */
                if ($nome === 'PERDA_TRADES' && $atual !== null) {
                    $linha['valor'] = (int)$atual['valor'] + (int)$linha['valor'];
                    $linha['ate']   = ($atual['ate'] === null || $ate === null)
                        ? null : max((int)$atual['ate'], (int)$ate);
                    $saida[$nome] = $linha;
                    continue;
                }

                if ($mandaEsta) $saida[$nome] = $linha;
            }
        }
        return $cache[$teamId] = $saida;
    } catch (Throwable $e) {
        error_log('[punicoes] efeitos ativos: ' . $e->getMessage());
        return $cache[$teamId] = [];
    }
}

/** Atalho: este efeito está pegando no time? Devolve os dados ou null. */
function punicaoEfeitoAtivo(PDO $pdo, int $teamId, string $efeito): ?array
{
    return punicaoEfeitosAtivos($pdo, $teamId)[strtoupper(trim($efeito))] ?? null;
}

/**
 * Em que degrau o time está nesta infração.
 *
 * A reincidência conta DENTRO DO CICLO, como os dois editais escrevem
 * ("conforme a reincidência dentro do mesmo ciclo de 2 temporadas"). Punição
 * de dois ciclos atrás não agrava a de hoje — e punição revertida some da
 * conta, porque reverter é dizer que não houve.
 */
function punicaoOcorrenciasNoCiclo(PDO $pdo, int $teamId, int $infracaoId): int
{
    if ($infracaoId <= 0 || !punicaoGarantirEsquema($pdo)) return 0;
    try {
        $st = $pdo->prepare('SELECT league FROM teams WHERE id = ?');
        $st->execute([$teamId]);
        $league = (string)($st->fetchColumn() ?: '');
        $m = punicaoMomentoDaLiga($pdo, $league);
        if ($m['ciclo'] <= 0) return 0;

        // O ciclo C cobre as temporadas 2C-1 e 2C.
        $de  = $m['ciclo'] * 2 - 1;
        $ate = $m['ciclo'] * 2;

        /* A temporada em que a punição foi aplicada não fica gravada, então o
           corte é pela data de início da temporada mais antiga do ciclo. */
        $st = $pdo->prepare("SELECT MIN(created_at) FROM seasons
                              WHERE league = ? AND season_number BETWEEN ? AND ?");
        $st->execute([$league, $de, $ate]);
        $desde = $st->fetchColumn();

        /* PENA JÁ CUMPRIDA CONTA AQUI, de propósito: ela não pune de novo,
           mas a infração aconteceu, e é a infração que move o degrau. Tirar
           daqui faria o reincidente voltar pra 1ª ocorrência só porque a
           primeira pena foi paga fora do sistema. */
        $sql = 'SELECT COUNT(*) FROM team_punishments
                 WHERE team_id = ? AND infracao_id = ? AND reverted_at IS NULL';
        $par = [$teamId, $infracaoId];
        if ($desde) { $sql .= ' AND created_at >= ?'; $par[] = $desde; }

        $st = $pdo->prepare($sql);
        $st->execute($par);
        return (int)$st->fetchColumn();
    } catch (Throwable $e) {
        error_log('[punicoes] ocorrências: ' . $e->getMessage());
        return 0;
    }
}

/**
 * O degrau que vale para a próxima ocorrência desta infração.
 *
 * @return array ['ocorrencia' => n, 'efeitos' => [...], 'fim_da_escala' => bool]
 */
function punicaoProximoDegrau(PDO $pdo, int $teamId, int $infracaoId): array
{
    $jaTeve = punicaoOcorrenciasNoCiclo($pdo, $teamId, $infracaoId);
    $proxima = $jaTeve + 1;

    $degraus = [];
    try {
        $st = $pdo->prepare('SELECT ocorrencia, efeitos FROM punicao_degraus
                              WHERE infracao_id = ? ORDER BY ocorrencia ASC');
        $st->execute([$infracaoId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $d) {
            $degraus[(int)$d['ocorrencia']] = json_decode((string)$d['efeitos'], true) ?: [];
        }
    } catch (Throwable $e) {
        error_log('[punicoes] degraus: ' . $e->getMessage());
    }
    if (!$degraus) return ['ocorrencia' => $proxima, 'efeitos' => [], 'fim_da_escala' => false];

    // Ocorrência 0: a pena é a mesma sempre ("já na 1ª ocorrência").
    if (isset($degraus[0])) {
        return ['ocorrencia' => $proxima, 'efeitos' => $degraus[0], 'fim_da_escala' => false];
    }

    /* Passou do último degrau: repete o último em vez de não punir nada. O
       edital termina a escala em "exclusão da liga", e quem chega lá não
       deixa de ser punido por ter passado do fim da tabela. */
    $maior = max(array_keys($degraus));
    $usa = $degraus[$proxima] ?? $degraus[$maior];
    return [
        'ocorrencia'    => $proxima,
        'efeitos'       => $usa,
        'fim_da_escala' => $proxima > $maior,
    ];
}

/* ─────────────────────────────────────────────────────────────────────
   A SEMENTE DO QUADRO
   ───────────────────────────────────────────────────────────────────── */

/**
 * Põe o quadro do edital no banco, uma vez por liga.
 *
 * Só INSERE o que falta: o admin pode corrigir um título ou trocar um degrau
 * pela tela sem que a próxima carga desfaça. Quadro é dado dele, não meu.
 */
function punicaoSemearQuadro(PDO $pdo, string $league): int
{
    if (!punicaoGarantirEsquema($pdo)) return 0;
    $league = strtoupper(trim($league));
    $novas = 0;
    try {
        foreach (punicaoQuadroSemente($league) as $i => [$codigo, $titulo, $artigo, $degraus]) {
            $st = $pdo->prepare('SELECT id FROM punicao_infracoes WHERE league = ? AND codigo = ?');
            $st->execute([$league, $codigo]);
            $id = (int)($st->fetchColumn() ?: 0);
            if ($id) continue;

            $pdo->prepare('INSERT INTO punicao_infracoes (league, codigo, titulo, artigo, ordem)
                           VALUES (?,?,?,?,?)')
                ->execute([$league, $codigo, $titulo, $artigo, $i + 1]);
            $id = (int)$pdo->lastInsertId();
            $novas++;

            $ins = $pdo->prepare('INSERT INTO punicao_degraus (infracao_id, ocorrencia, efeitos) VALUES (?,?,?)');
            foreach ($degraus as $ocorrencia => $efeitos) {
                $ins->execute([$id, (int)$ocorrencia, json_encode($efeitos, JSON_UNESCAPED_UNICODE)]);
            }
        }
    } catch (Throwable $e) {
        error_log('[punicoes] semear ' . $league . ': ' . $e->getMessage());
    }
    return $novas;
}

/* ─────────────────────────────────────────────────────────────────────
   PERDA DE PICK
   ───────────────────────────────────────────────────────────────────── */

/**
 * Tira a pick do time — SEM APAGAR A LINHA.
 *
 * Era o furo mais caro do sistema antigo: a punição dava DELETE em `picks`, e
 * o time seguia escolhendo normalmente. A ordem do draft é reescrita a partir
 * de `picks` por draftSincronizarOrdem(), que diz, com todas as letras, que
 * "vaga sem pick correspondente fica como está: não é o caso desta função
 * inventar dono". Sem pick, a vaga simplesmente ficava com quem estava lá.
 * Ou seja: a punição mais aplicada da liga não tirava ninguém do draft.
 *
 * Agora a pick fica e leva um carimbo. Quem lê a ordem mostra PUNIDO e pula a
 * vez; a Trade Machine se recusa a negociá-la.
 *
 * QUAL PICK. Os dois editais dizem a mesma coisa (ELITE art. 73; ROOKIE
 * art. 46): só a PRÓPRIA de 1ª rodada, a mais próxima. E se o time já
 * negociou a própria, a pena não pega a de terceiros — espera a próxima
 * temporada em que ele voltar a ter a dele.
 *
 * @return array ['ok'=>bool, 'pick_id'=>?int, 'ano'=>?int, 'motivo'=>string]
 */
function punicaoPerderPick(PDO $pdo, int $teamId, int $punicaoId, ?int $pickId = null): array
{
    if (!punicaoGarantirEsquema($pdo)) return ['ok' => false, 'pick_id' => null, 'ano' => null, 'motivo' => 'esquema'];
    try {
        /* PICK JÁ ESCOLHIDA NÃO SE PERDE.
           "A mais próxima" é quase sempre a do draft que está rodando — e se
           ele já escolheu com ela, tirá-la não pune nada: o jogador está no
           elenco e a vaga aparece como PUNIDO depois de ter sido usada. A
           pena tem que cair na próxima que ainda vale, que é o espírito do
           art. 73 (recai sobre a escolha própria... disponível).
           Mesma régua da Trade Machine, que também não deixa negociar pick
           gasta. @see backend/picks_usadas.php */
        require_once __DIR__ . '/picks_usadas.php';
        $usadas = picksJaUsadas($pdo, true);

        if ($pickId) {
            // Pick apontada a dedo pelo admin: vale qualquer uma que seja dele.
            $st = $pdo->prepare('SELECT id, season_year FROM picks WHERE id = ? AND team_id = ? AND punicao_id IS NULL');
            $st->execute([$pickId, $teamId]);
            $pick = $st->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($pick && isset($usadas[(int)$pick['id']])) {
                return ['ok' => false, 'pick_id' => null, 'ano' => null,
                        'motivo' => 'Essa pick já foi usada no draft — escolha outra.'];
            }
        } else {
            /* A própria de 1ª rodada, a mais próxima, que ele ainda tenha.
               `original_team_id = team_id` é o que separa "a minha" de "a que
               eu comprei de alguém". */
            $st = $pdo->prepare("SELECT id, season_year FROM picks
                                  WHERE team_id = ? AND original_team_id = ? AND round = '1'
                                    AND punicao_id IS NULL
                               ORDER BY CAST(season_year AS UNSIGNED) ASC, id ASC");
            $st->execute([$teamId, $teamId]);
            $pick = null;
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $cand) {
                if (isset($usadas[(int)$cand['id']])) continue;
                $pick = $cand;
                break;
            }
        }

        if (!$pick) {
            /* Não tem a própria agora. O edital manda esperar, não pegar a de
               terceiros — então a punição fica registrada e pendente, e é
               cobrada quando ele voltar a ter (punicaoCobrarPicksPendentes). */
            return ['ok' => false, 'pick_id' => null, 'ano' => null,
                    'motivo' => 'O time não tem a própria pick de 1ª rodada. A perda fica pendente '
                              . 'e será cobrada na próxima temporada em que ele voltar a ter (art. 73 / art. 46).'];
        }

        $pdo->prepare('UPDATE picks SET punicao_id = ? WHERE id = ?')->execute([$punicaoId, (int)$pick['id']]);
        return ['ok' => true, 'pick_id' => (int)$pick['id'], 'ano' => (int)$pick['season_year'], 'motivo' => ''];
    } catch (Throwable $e) {
        error_log('[punicoes] perder pick: ' . $e->getMessage());
        return ['ok' => false, 'pick_id' => null, 'ano' => null, 'motivo' => 'erro ao marcar a pick'];
    }
}

/** Devolve a pick quando a punição é revertida. */
function punicaoDevolverPicks(PDO $pdo, int $punicaoId): int
{
    if (!punicaoGarantirEsquema($pdo)) return 0;
    try {
        $st = $pdo->prepare('UPDATE picks SET punicao_id = NULL WHERE punicao_id = ?');
        $st->execute([$punicaoId]);
        return $st->rowCount();
    } catch (Throwable $e) {
        error_log('[punicoes] devolver pick: ' . $e->getMessage());
        return 0;
    }
}

/**
 * As perdas que ficaram esperando o time voltar a ter a própria pick.
 *
 * Roda quando o quadro de picks muda (virada de temporada, fim de draft). Sem
 * isto, a pena de quem já tinha negociado a própria some sozinha — que é
 * exatamente o que o edital não quer: ele manda transferir, não perdoar.
 */
function punicaoCobrarPicksPendentes(PDO $pdo, ?string $league = null): int
{
    if (!punicaoGarantirEsquema($pdo)) return 0;
    try {
        $sql = "SELECT tp.id, tp.team_id FROM team_punishments tp
                  JOIN teams t ON t.id = tp.team_id
                 WHERE tp.reverted_at IS NULL
                   /* Pena já cumprida não fica esperando pick: ela não é
                      pra ser cobrada, senão a cobrança pendente furaria o
                      'já cumpriu' na primeira virada de temporada. */
                   AND tp.ja_cumprida = 0
                   AND tp.efeitos_json LIKE '%PERDA_PICK_1R%'
                   AND NOT EXISTS (SELECT 1 FROM picks p WHERE p.punicao_id = tp.id)";
        $par = [];
        if ($league) { $sql .= ' AND t.league = ?'; $par[] = strtoupper(trim($league)); }

        $st = $pdo->prepare($sql);
        $st->execute($par);
        $n = 0;
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $r = punicaoPerderPick($pdo, (int)$p['team_id'], (int)$p['id']);
            if ($r['ok']) {
                error_log("[punicoes] pendente cobrada: punição {$p['id']}, pick {$r['pick_id']} ({$r['ano']})");
                $n++;
            }
        }
        return $n;
    } catch (Throwable $e) {
        error_log('[punicoes] cobrar pendentes: ' . $e->getMessage());
        return 0;
    }
}

/** Esta pick está perdida por punição? (a Trade Machine pergunta) */
function punicaoPickPerdida(PDO $pdo, int $pickId): bool
{
    if ($pickId <= 0) return false;
    try {
        if ($pdo->query("SHOW COLUMNS FROM picks LIKE 'punicao_id'")->rowCount() === 0) return false;
        $st = $pdo->prepare('SELECT punicao_id FROM picks WHERE id = ?');
        $st->execute([$pickId]);
        return (int)($st->fetchColumn() ?: 0) > 0;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * AS PUNIÇÕES DE ANTES DO MOTOR, traduzidas para o formato novo.
 *
 * Elas gravavam o efeito em colunas de `teams` (ban_trades_until_cycle e
 * irmãs) e a duração na régua velha, aquela que confundia temporada com
 * ciclo. Enquanto existissem dois caminhos de leitura, o bug continuaria vivo
 * num deles — então elas são convertidas uma vez e o caminho antigo sai.
 *
 * A conversão é conservadora de propósito: `season_scope` antigo vira CICLO,
 * que é o que o sistema REALMENTE cumpria, não o que o rótulo prometia.
 * Encurtar a pena de alguém sem o admin mandar seria decidir por ele.
 *
 * Roda uma vez só (app_flags). Punição já revertida não é convertida: não há
 * o que cumprir.
 */
function punicaoMigrarLegado(PDO $pdo): int
{
    if (!punicaoGarantirEsquema($pdo)) return 0;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS app_flags (
            flag VARCHAR(100) PRIMARY KEY,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $st = $pdo->prepare('SELECT 1 FROM app_flags WHERE flag = ?');
        $st->execute(['punicoes_motor_2026_09']);
        if ($st->fetchColumn()) return 0;

        $de = [
            'BAN_TRADES'          => 'BAN_TRADES',
            'BAN_TRADES_PICKS'    => 'BAN_TRADES_PICKS',
            'BAN_FREE_AGENCY'     => 'BAN_FREE_AGENCY',
            'ROTACAO_AUTOMATICA'  => 'ROTACAO_AUTOMATICA',
            'PERDA_PICK_1R'       => 'PERDA_PICK_1R',
            'PERDA_PICK_ESPECIFICA' => 'PERDA_PICK_ESPECIFICA',
            'AVISO_FORMAL'        => 'ADVERTENCIA',
        ];
        $tipos = "'" . implode("','", array_keys($de)) . "'";
        $linhas = $pdo->query("SELECT id, team_id, type, effect_type, ban_until_cycle
                                 FROM team_punishments
                                WHERE reverted_at IS NULL AND efeitos_json IS NULL
                                  AND COALESCE(effect_type, type) IN ({$tipos})")
                      ->fetchAll(PDO::FETCH_ASSOC);

        $up = $pdo->prepare('UPDATE team_punishments
                                SET efeitos_json = ?, vigencia = ?, vigencia_desde = ?, vigencia_ate = ?
                              WHERE id = ?');
        $n = 0;
        foreach ($linhas as $l) {
            $efeito = $de[strtoupper((string)($l['effect_type'] ?: $l['type']))] ?? null;
            if (!$efeito) continue;

            $periodo = (PUNICAO_EFEITOS[$efeito]['duracao'] ?? 'evento') === 'periodo';
            $ate = $periodo ? (int)($l['ban_until_cycle'] ?? 0) : 0;
            $vig = ($periodo && $ate > 0) ? 'CICLO' : 'EVENTO';
            $ate = $vig === 'CICLO' ? $ate : null;

            $up->execute([
                json_encode([['efeito' => $efeito, 'vigencia' => $vig, 'desde' => null, 'ate' => $ate]], JSON_UNESCAPED_UNICODE),
                $vig, null, $ate, (int)$l['id'],
            ]);
            $n++;
        }

        $pdo->prepare('INSERT IGNORE INTO app_flags (flag) VALUES (?)')->execute(['punicoes_motor_2026_09']);
        if ($n) error_log("[punicoes] legado convertido: {$n} punição(ões)");
        return $n;
    } catch (Throwable $e) {
        error_log('[punicoes] migrar legado: ' . $e->getMessage());
        return 0;
    }
}

/** O quadro de uma liga, do jeito que a tela precisa. */
function punicaoQuadroDaLiga(PDO $pdo, string $league): array
{
    if (!punicaoGarantirEsquema($pdo)) return [];
    $league = strtoupper(trim($league));
    punicaoSemearQuadro($pdo, $league);
    try {
        $st = $pdo->prepare('SELECT id, codigo, titulo, artigo, ordem, ativa
                               FROM punicao_infracoes WHERE league = ? ORDER BY ordem, codigo');
        $st->execute([$league]);
        $infracoes = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!$infracoes) return [];

        $ids = array_column($infracoes, 'id');
        $in = implode(',', array_map('intval', $ids));
        $degraus = $pdo->query("SELECT infracao_id, ocorrencia, efeitos FROM punicao_degraus
                                 WHERE infracao_id IN ({$in}) ORDER BY ocorrencia")->fetchAll(PDO::FETCH_ASSOC);

        $porInfracao = [];
        foreach ($degraus as $d) {
            $porInfracao[(int)$d['infracao_id']][] = [
                'ocorrencia' => (int)$d['ocorrencia'],
                'efeitos'    => json_decode((string)$d['efeitos'], true) ?: [],
            ];
        }
        foreach ($infracoes as &$i) {
            $i['id'] = (int)$i['id'];
            $i['ativa'] = (int)$i['ativa'];
            $i['degraus'] = $porInfracao[$i['id']] ?? [];
        }
        return $infracoes;
    } catch (Throwable $e) {
        error_log('[punicoes] quadro: ' . $e->getMessage());
        return [];
    }
}

/** "Perde a própria pick de 1ª rodada + fica sem trocar até o fim do ciclo 3" */
function punicaoEfeitosTexto(array $efeitos, int $temporada = 0, int $ciclo = 0): string
{
    $partes = [];
    foreach ($efeitos as $e) {
        $nome = strtoupper(trim((string)($e['efeito'] ?? '')));
        $info = PUNICAO_EFEITOS[$nome] ?? null;
        if (!$info) continue;
        $txt = $info['label'];
        if (isset($e['valor'])) $txt .= ' (' . (int)$e['valor'] . ')';
        if (!empty($e['duracao']) && $temporada > 0) {
            $v = punicaoVigenciaDe((string)$e['duracao'], $temporada, $ciclo);
            $txt .= ' — ' . punicaoVigenciaTexto($v['vigencia'], $v['ate'], $v['desde'], $temporada, $ciclo);
        }
        $partes[] = $txt;
    }
    return $partes ? implode(' + ', $partes) : 'Sem efeito automático';
}
