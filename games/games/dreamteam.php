<?php
/**
 * dreamteam.php — Starting5x5
 *
 * Dois jogadores montam, cada um, um time titular (PG/SG/SF/PF/C) girando
 * uma roleta de escalações históricas reais (games/core/dreamteam_times.php)
 * — cada giro revela um time de 5, e quem está na vez escolhe UM desses
 * jogadores pra ocupar uma posição ainda vazia do próprio time (um jogador
 * pode servir mais de uma posição). Sem gostar do time sorteado, dá pra
 * girar de novo uma vez por rodada. Os turnos alternam até os dois times
 * ficarem completos (5 rodadas cada, 10 no total) — dá pra acompanhar o
 * time do adversário sendo montado em tempo real. No final, os dois times
 * simulam um confronto direto e o vencedor leva as duas apostas.
 *
 * Base própria (dtTimesHistoricos), sem depender do pool do Build-A-Player —
 * são escalações reais de temporadas específicas, não lendas individuais.
 *
 * Incluído por games/games/index.php — $pdo e $_SESSION já disponíveis.
 */

require_once __DIR__ . '/../core/dreamteam_times.php';

$user_id = (int)$_SESSION['user_id'];

const DT_APOSTA_MIN = 1;
const DT_APOSTA_MAX = 100;
// Contra a máquina o teto é menor — ela joga estrategicamente (ver
// dtCpuJogar), então o risco por partida é maior que jogador-vs-jogador.
const DT_APOSTA_MAX_CPU = 50;
// Modo aleatório: aposta fixa, sem link — casa automaticamente com quem
// também estiver esperando um confronto aleatório nesse momento.
const DT_APOSTA_ALEATORIA = 30;
const DT_AGUARDANDO_TIMEOUT_H = 24;
const DT_DRAFT_TIMEOUT_MIN = 20;

function dtGarantirTabelas(PDO $pdo): void
{
    static $pronto = false;
    if ($pronto || $pdo->inTransaction()) return;
    $pronto = true;

    // Versão anterior do jogo (cap de OVR) já criou dreamteam_duelos com outro
    // schema (time_criador/pronto_criador/ovr_criador...). Como o jogo nunca
    // saiu do link direto de teste, não existe duelo real pra preservar — se a
    // tabela antiga sobreviveu, derruba e recria já no schema novo.
    try {
        $temColunaNova = $pdo->query("SHOW COLUMNS FROM dreamteam_duelos LIKE 'turno'")->fetch();
        if (!$temColunaNova) {
            $pdo->exec("DROP TABLE IF EXISTS dreamteam_duelos");
        }
    } catch (PDOException $e) {
        // Tabela ainda não existe — segue pro CREATE abaixo normalmente.
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS dreamteam_duelos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        codigo VARCHAR(8) NOT NULL,
        id_criador INT NOT NULL,
        id_desafiado INT NULL,
        aposta INT NOT NULL,
        modo VARCHAR(10) NOT NULL DEFAULT 'amigo',
        status VARCHAR(20) NOT NULL DEFAULT 'aguardando',
        turno VARCHAR(10) NULL,
        roster_criador TEXT NULL,
        roster_desafiado TEXT NULL,
        time_sorteado_id VARCHAR(20) NULL,
        reroll_disponivel TINYINT(1) NOT NULL DEFAULT 1,
        resultado TEXT NULL,
        id_vencedor INT NULL,
        criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        entrou_em DATETIME NULL,
        concluido_em DATETIME NULL,
        UNIQUE KEY uk_dt_codigo (codigo),
        INDEX idx_dt_criador (id_criador),
        INDEX idx_dt_desafiado (id_desafiado),
        INDEX idx_dt_modo_status (modo, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Migração aditiva: mesa que já existia antes do modo aleatório ganha a coluna sem perder duelo nenhum.
    try {
        if (!$pdo->query("SHOW COLUMNS FROM dreamteam_duelos LIKE 'modo'")->fetch()) {
            $pdo->exec("ALTER TABLE dreamteam_duelos ADD COLUMN modo VARCHAR(10) NOT NULL DEFAULT 'amigo' AFTER aposta");
            $pdo->exec("ALTER TABLE dreamteam_duelos ADD INDEX idx_dt_modo_status (modo, status)");
        }
    } catch (PDOException $e) {
        error_log('[dreamteam] migração modo: ' . $e->getMessage());
    }
}
dtGarantirTabelas($pdo);

function dtGerarCodigo(PDO $pdo): string
{
    $chars = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    for ($tentativa = 0; $tentativa < 20; $tentativa++) {
        $codigo = '';
        for ($i = 0; $i < 6; $i++) $codigo .= $chars[random_int(0, strlen($chars) - 1)];
        $st = $pdo->prepare('SELECT 1 FROM dreamteam_duelos WHERE codigo = ?');
        $st->execute([$codigo]);
        if (!$st->fetchColumn()) return $codigo;
    }
    throw new Exception('Não foi possível gerar um código único. Tente de novo.');
}

function dtRosterVazio(): array
{
    $r = [];
    foreach (dtPosicoes() as $p) $r[$p] = null;
    return $r;
}

function dtVagasAbertas(array $roster): array
{
    return array_keys(array_filter($roster, fn($v) => $v === null));
}

function dtRosterCompleto(array $roster): bool
{
    return count(dtVagasAbertas($roster)) === 0;
}

function dtTimePorId(?string $id): ?array
{
    if (!$id) return null;
    foreach (dtTimesHistoricos() as $t) if ($t['id'] === $id) return $t;
    return null;
}

/** Nomes que já estão no roster (pra não deixar repetir o mesmo jogador em 2 posições — vários times históricos reaproveitam o mesmo nome, tipo Jordan/Pippen/Rodman em várias temporadas dos Bulls). */
function dtNomesNoRoster(array $roster): array
{
    $nomes = [];
    foreach ($roster as $p) if ($p) $nomes[$p['nome']] = true;
    return $nomes;
}

/** Sorteia um time com pelo menos 1 jogador NOVO (ainda não escolhido) elegível pra alguma vaga aberta — nunca um giro "morto". */
function dtSortearTimeValido(array $roster): array
{
    $vagas = dtVagasAbertas($roster);
    $jaTenho = dtNomesNoRoster($roster);
    $todos = dtTimesHistoricos();
    $candidatos = array_values(array_filter($todos, function ($time) use ($vagas, $jaTenho) {
        foreach ($time['jogadores'] as $j) {
            if (isset($jaTenho[$j['nome']])) continue;
            foreach ($j['pos'] as $p) if (in_array($p, $vagas, true)) return true;
        }
        return false;
    }));
    if (!$candidatos) $candidatos = $todos;
    return $candidatos[array_rand($candidatos)];
}

function dtSomaRoster(array $roster): int
{
    $s = 0;
    foreach ($roster as $p) if ($p) $s += (int)$p['ovr'];
    return $s;
}

function dtFrasePosicao(string $pos): string
{
    $frases = [
        'PG' => 'orquestrando o ataque no armador',
        'SG' => 'castigando o arco de fora',
        'SF' => 'atacando de todos os ângulos',
        'PF' => 'brigando duro no garrafão',
        'C'  => 'protegendo o aro e dominando o rebote',
    ];
    return $frases[$pos] ?? 'brilhando em quadra';
}

/** Distribui um total inteiro entre chaves conforme pesos, batendo a soma exata (método dos maiores restos). */
function dtDistribuirTotal(array $pesos, int $total): array
{
    $somaPesos = array_sum($pesos);
    if ($somaPesos <= 0) return array_fill_keys(array_keys($pesos), 0);

    $base = [];
    $restos = [];
    $somaBase = 0;
    foreach ($pesos as $k => $p) {
        $exato = $total * ($p / $somaPesos);
        $base[$k] = (int)floor($exato);
        $restos[$k] = $exato - $base[$k];
        $somaBase += $base[$k];
    }
    $falta = $total - $somaBase;
    arsort($restos);
    foreach (array_keys($restos) as $k) {
        if ($falta <= 0) break;
        $base[$k]++;
        $falta--;
    }
    return $base;
}

/** Boxscore de um time: soma de PTS bate exatamente com o placar; REB/AST pesados por posição (armador assiste mais, pivô rebota mais). */
function dtGerarBoxscore(array $roster, int $placarTime): array
{
    $pesosPts = [];
    foreach ($roster as $pos => $j) $pesosPts[$pos] = max(1, (int)$j['ovr']);
    $pts = dtDistribuirTotal($pesosPts, $placarTime);

    $pesoRebPos = ['PG' => 0.6, 'SG' => 0.7, 'SF' => 0.9, 'PF' => 1.3, 'C' => 1.5];
    $pesoAstPos = ['PG' => 1.6, 'SG' => 1.1, 'SF' => 0.9, 'PF' => 0.6, 'C' => 0.5];
    $pesosReb = [];
    $pesosAst = [];
    foreach ($roster as $pos => $j) {
        $pesosReb[$pos] = $pesoRebPos[$pos] * (0.8 + random_int(0, 40) / 100);
        $pesosAst[$pos] = $pesoAstPos[$pos] * (0.8 + random_int(0, 40) / 100);
    }
    $reb = dtDistribuirTotal($pesosReb, random_int(34, 46));
    $ast = dtDistribuirTotal($pesosAst, random_int(18, 27));

    $box = [];
    foreach ($roster as $pos => $j) {
        $box[] = ['pos' => $pos, 'nome' => $j['nome'], 'ovr' => (int)$j['ovr'], 'pts' => $pts[$pos], 'reb' => $reb[$pos], 'ast' => $ast[$pos]];
    }
    return $box;
}

/** 4 parciais que somam exatamente ao placar final de cada lado — o front revela elas indo, tipo simcast. */
function dtGerarQuartos(int $placarA, int $placarB): array
{
    // Pesos INDEPENDENTES pra cada lado — usar o mesmo peso pros dois fazia o time com mais
    // pontos no total vencer literalmente todo quarto (nunca dava virada, só diferença de escala).
    //
    // A amplitude depende de quão apertado terminou: jogo decidido no detalhe oscila muito e
    // troca de liderança várias vezes; atropelo tem quartos mais parelhos, o líder abre e
    // administra. É o que faz a virada acontecer "dependendo da força do time".
    $margem = abs($placarA - $placarB);
    if ($margem <= 6)       $amp = 5;  // jogo duro: quartos de 5 a 15 pontos de peso
    elseif ($margem <= 14)  $amp = 4;
    elseif ($margem <= 22)  $amp = 3;
    else                    $amp = 2;  // atropelo: quartos previsíveis

    $pesosA = [];
    $pesosB = [];
    for ($q = 1; $q <= 4; $q++) {
        $pesosA[$q] = random_int(10 - $amp, 10 + $amp);
        $pesosB[$q] = random_int(10 - $amp, 10 + $amp);
    }
    $partesA = dtDistribuirTotal($pesosA, $placarA);
    $partesB = dtDistribuirTotal($pesosB, $placarB);

    $quartos = [];
    for ($q = 1; $q <= 4; $q++) $quartos[] = ['a' => $partesA[$q], 'b' => $partesB[$q]];
    return $quartos;
}

/** Mesmo espírito de buildSimularPlayoffs (build_liga.php): força → chance → random_int. Placar final já sai pronto pra virar boxscore + quartos. */
function dtCalcularResultado(array $rosterA, array $rosterB): array
{
    $ovrA = dtSomaRoster($rosterA);
    $ovrB = dtSomaRoster($rosterB);
    $diffMedio = ($ovrA - $ovrB) / 5;

    // Time melhor ganha bem mais: com ~6 de OVR médio de vantagem já vence 4 em cada 5. O teto de
    // 92% (piso de 8%) é o espaço da zebra — mesmo o elenco muito superior perde de vez em quando,
    // mas raramente. Times parelhos seguem perto do 50/50.
    $chanceA = max(8, min(92, 50 + $diffMedio * 5.0));
    $aGanha = random_int(1, 1000) <= (int)round($chanceA * 10);

    $vencedorLado = $aGanha ? 'a' : 'b';
    $favorito = $diffMedio >= 0 ? 'a' : 'b';
    $forca = abs($diffMedio);
    // Zebra: o azarão venceu um confronto que era claramente pra perder. Só conta quando a
    // diferença de elenco é real (>= 3 de OVR médio por jogador) — abaixo disso os times estão
    // equilibrados e qualquer um ganhar é normal, não zebra.
    $ehZebra = ($vencedorLado !== $favorito) && $forca >= 3;

    // A margem definida aqui é a margem FINAL de verdade: o perdedor parte da base e o vencedor
    // é a base + margem. Antes o placar saía de duas bases sorteadas separadamente (100±10 cada),
    // então a diferença entre elas somava até 20 pontos por fora — a zebra "apertada" de 1 a 6
    // acabava terminando com 10, 20, 26 de diferença.
    if ($ehZebra) {
        $margem = random_int(1, 6); // vitória no sufoco, como tem que ser
    } else {
        // Quanto maior a superioridade, maior tende a ser o placar — mas com sorte no meio, então
        // o favorito também vence jogo apertado de vez em quando.
        $margem = min(34, random_int(2, 10) + (int)round($forca * 1.4));
    }
    $baseFundo = 100 + random_int(-12, 12);
    $placarVencedor = $baseFundo + $margem;
    if ($aGanha) { $placarA = $placarVencedor; $placarB = $baseFundo; }
    else { $placarB = $placarVencedor; $placarA = $baseFundo; }

    $boxA = dtGerarBoxscore($rosterA, $placarA);
    $boxB = dtGerarBoxscore($rosterB, $placarB);

    $destaques = [];
    foreach ([['box' => $boxA, 'lado' => 'a'], ['box' => $boxB, 'lado' => 'b']] as $grupo) {
        $itens = $grupo['box'];
        usort($itens, fn($x, $y) => $y['pts'] - $x['pts']);
        foreach (array_slice($itens, 0, 2) as $j) {
            $destaques[] = ['lado' => $grupo['lado'], 'nome' => $j['nome'], 'pontos' => $j['pts'], 'frase' => dtFrasePosicao($j['pos'])];
        }
    }
    usort($destaques, fn($x, $y) => $y['pontos'] - $x['pontos']);

    return [
        'ovr_a' => $ovrA, 'ovr_b' => $ovrB, 'chance_a' => round($chanceA, 1),
        'placar_a' => $placarA, 'placar_b' => $placarB, 'vencedor' => $vencedorLado,
        'zebra' => $ehZebra,
        'quartos' => dtGerarQuartos($placarA, $placarB),
        'boxscore_a' => $boxA, 'boxscore_b' => $boxB,
        'destaques' => $destaques,
    ];
}

/** Melhor jogador NOVO do time sorteado pra uma vaga aberta do roster (maior OVR entre os elegíveis, ignora quem já está no time). */
function dtMelhorEscolha(array $time, array $roster): ?array
{
    $vagas = dtVagasAbertas($roster);
    $jaTenho = dtNomesNoRoster($roster);
    $melhor = null;
    foreach ($time['jogadores'] as $j) {
        if (isset($jaTenho[$j['nome']])) continue;
        $posDisponiveis = array_values(array_intersect($j['pos'], $vagas));
        if (!$posDisponiveis) continue;
        if (!$melhor || $j['ovr'] > $melhor['jogador']['ovr']) {
            $melhor = ['jogador' => $j, 'pos' => $posDisponiveis[0]];
        }
    }
    return $melhor;
}

/**
 * Resolve o turno da CPU inteiro: sorteia, decide se vale a pena girar de
 * novo (só troca se a segunda opção for mais forte), escolhe, e passa a vez
 * de volta (ou finaliza + simula, se os dois times ficarem completos). Não
 * abre transação própria — quem chama já deve estar com a linha travada.
 */
function dtCpuJogar(PDO $pdo, int $dueloId): void
{
    $st = $pdo->prepare('SELECT * FROM dreamteam_duelos WHERE id = ? FOR UPDATE');
    $st->execute([$dueloId]);
    $atual = $st->fetch(PDO::FETCH_ASSOC);
    if (!$atual || $atual['status'] !== 'draft' || $atual['turno'] !== 'desafiado') return;

    $roster = json_decode((string)$atual['roster_desafiado'], true) ?: dtRosterVazio();
    // A CPU não usa botão — sorteia o próprio time na hora (o "Sortear Time" manual é só pro humano).
    $time = dtSortearTimeValido($roster);

    $melhor = dtMelhorEscolha($time, $roster);
    if (!$melhor || $melhor['jogador']['ovr'] < 82) {
        $novoTime = dtSortearTimeValido($roster);
        $melhorNovo = dtMelhorEscolha($novoTime, $roster);
        if ($melhorNovo && (!$melhor || $melhorNovo['jogador']['ovr'] > $melhor['jogador']['ovr'])) {
            $melhor = $melhorNovo;
        }
    }
    if (!$melhor) return; // nunca deveria acontecer — dtSortearTimeValido garante >=1 opção

    $roster[$melhor['pos']] = ['nome' => $melhor['jogador']['nome'], 'ovr' => $melhor['jogador']['ovr'], 'pos' => $melhor['jogador']['pos']];
    $pdo->prepare('UPDATE dreamteam_duelos SET roster_desafiado = ? WHERE id = ?')->execute([json_encode($roster), $dueloId]);

    $rosterCriador = json_decode((string)$atual['roster_criador'], true) ?: dtRosterVazio();
    if (dtRosterCompleto($roster) && dtRosterCompleto($rosterCriador)) {
        $resultado = dtCalcularResultado($rosterCriador, $roster);
        $vencedorId = $resultado['vencedor'] === 'a' ? (int)$atual['id_criador'] : (int)$atual['id_desafiado'];
        $premio = (int)$atual['aposta'] * 2;
        $pdo->prepare('UPDATE games_usuarios SET pontos = pontos + ? WHERE id = ?')->execute([$premio, $vencedorId]);
        $pdo->prepare("UPDATE dreamteam_duelos SET status = 'simulado', resultado = ?, id_vencedor = ?, concluido_em = NOW() WHERE id = ?")
            ->execute([json_encode($resultado), $vencedorId, $dueloId]);
    } else {
        // Passa a vez pro criador (sempre humano) sem sortear — ele sorteia clicando no botão.
        $pdo->prepare("UPDATE dreamteam_duelos SET turno = 'criador', time_sorteado_id = NULL, reroll_disponivel = 1 WHERE id = ?")
            ->execute([$dueloId]);
    }
}

function dtDueloAtivo(PDO $pdo, int $userId): ?array
{
    $st = $pdo->prepare("
        SELECT * FROM dreamteam_duelos
        WHERE (id_criador = ? OR id_desafiado = ?)
          AND status IN ('aguardando', 'draft', 'simulado')
        ORDER BY (status IN ('aguardando', 'draft')) DESC, id DESC
        LIMIT 1
    ");
    $st->execute([$userId, $userId]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** Só o que trava criar/entrar num duelo novo — 'simulado' não conta (senão a pessoa nunca mais jogaria de novo). */
function dtDueloEmAndamento(PDO $pdo, int $userId): ?array
{
    $st = $pdo->prepare("
        SELECT * FROM dreamteam_duelos
        WHERE (id_criador = ? OR id_desafiado = ?) AND status IN ('aguardando', 'draft')
        ORDER BY id DESC LIMIT 1
    ");
    $st->execute([$userId, $userId]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function dtExpirarAntigos(PDO $pdo): void
{
    $st = $pdo->prepare("SELECT id, id_criador, aposta FROM dreamteam_duelos WHERE status = 'aguardando' AND criado_em < DATE_SUB(NOW(), INTERVAL ? HOUR)");
    $st->execute([DT_AGUARDANDO_TIMEOUT_H]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $d) {
        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE games_usuarios SET pontos = pontos + ? WHERE id = ?')->execute([(int)$d['aposta'], (int)$d['id_criador']]);
            $pdo->prepare("UPDATE dreamteam_duelos SET status = 'expirado' WHERE id = ?")->execute([$d['id']]);
            $pdo->commit();
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); }
    }

    $st = $pdo->prepare("SELECT id, id_criador, id_desafiado, aposta FROM dreamteam_duelos WHERE status = 'draft' AND entrou_em < DATE_SUB(NOW(), INTERVAL ? MINUTE)");
    $st->execute([DT_DRAFT_TIMEOUT_MIN]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $d) {
        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE games_usuarios SET pontos = pontos + ? WHERE id = ?')->execute([(int)$d['aposta'], (int)$d['id_criador']]);
            if ((int)$d['id_desafiado'] > 0) {
                $pdo->prepare('UPDATE games_usuarios SET pontos = pontos + ? WHERE id = ?')->execute([(int)$d['aposta'], (int)$d['id_desafiado']]);
            }
            $pdo->prepare("UPDATE dreamteam_duelos SET status = 'expirado' WHERE id = ?")->execute([$d['id']]);
            $pdo->commit();
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); }
    }
}

/** Ranking de mais vitórias — só conta confronto online (id_desafiado > 0, exclui a máquina). */
function dtRankingVitorias(PDO $pdo, int $limite = 10): array
{
    $st = $pdo->prepare("
        SELECT x.user_id,
               SUM(CASE WHEN x.venceu = 1 THEN 1 ELSE 0 END) AS vitorias,
               SUM(CASE WHEN x.venceu = 0 THEN 1 ELSE 0 END) AS derrotas
        FROM (
            SELECT id_criador AS user_id, (id_vencedor = id_criador) AS venceu
            FROM dreamteam_duelos WHERE status = 'simulado' AND id_desafiado > 0
            UNION ALL
            SELECT id_desafiado AS user_id, (id_vencedor = id_desafiado) AS venceu
            FROM dreamteam_duelos WHERE status = 'simulado' AND id_desafiado > 0
        ) x
        GROUP BY x.user_id
        ORDER BY vitorias DESC, derrotas ASC
        LIMIT ?
    ");
    $st->bindValue(1, $limite, PDO::PARAM_INT);
    $st->execute();

    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[] = [
            'nome' => dtNomeExibicao($pdo, (int)$r['user_id']),
            'logo' => dtTimeDoUsuario($pdo, (int)$r['user_id'])['logo'],
            'vitorias' => (int)$r['vitorias'],
            'derrotas' => (int)$r['derrotas'],
        ];
    }
    return $out;
}

/** Nome de exibição de um usuário — time de franquia se tiver, senão o nome pessoal do GM. */
function dtNomeExibicao(PDO $pdo, int $userId): string
{
    $time = dtTimeDoUsuario($pdo, $userId);
    if ($time['nome']) return $time['nome'];
    $st = $pdo->prepare('SELECT nome FROM games_usuarios WHERE id = ?');
    $st->execute([$userId]);
    return $st->fetchColumn() ?: 'GM';
}

/** Top rivalidades: os pares que mais se enfrentaram em confronto online, com o placar da série (vitórias de cada um no head-to-head). */
function dtMaioresRivalidades(PDO $pdo, int $limite = 5): array
{
    $st = $pdo->prepare("
        SELECT
            LEAST(id_criador, id_desafiado) AS user_a,
            GREATEST(id_criador, id_desafiado) AS user_b,
            COUNT(*) AS total,
            SUM(CASE WHEN id_vencedor = LEAST(id_criador, id_desafiado) THEN 1 ELSE 0 END) AS vitorias_a,
            SUM(CASE WHEN id_vencedor = GREATEST(id_criador, id_desafiado) THEN 1 ELSE 0 END) AS vitorias_b
        FROM dreamteam_duelos
        WHERE status = 'simulado' AND id_desafiado > 0
        GROUP BY user_a, user_b
        ORDER BY total DESC
        LIMIT ?
    ");
    $st->bindValue(1, $limite, PDO::PARAM_INT);
    $st->execute();

    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $vitoriasA = (int)$r['vitorias_a'];
        $vitoriasB = (int)$r['vitorias_b'];
        $out[] = [
            'total' => (int)$r['total'],
            'nome_a' => dtNomeExibicao($pdo, (int)$r['user_a']),
            'logo_a' => dtTimeDoUsuario($pdo, (int)$r['user_a'])['logo'],
            'vitorias_a' => $vitoriasA,
            'nome_b' => dtNomeExibicao($pdo, (int)$r['user_b']),
            'logo_b' => dtTimeDoUsuario($pdo, (int)$r['user_b'])['logo'],
            'vitorias_b' => $vitoriasB,
            'lider' => $vitoriasA > $vitoriasB ? 'a' : ($vitoriasB > $vitoriasA ? 'b' : null),
        ];
    }
    return $out;
}

/** Time de franquia (nome + logo) do usuário, o mesmo de teams usado no resto do app — null se ele não tiver time. */
function dtTimeDoUsuario(PDO $pdo, int $userId): array
{
    static $cache = [];
    if (array_key_exists($userId, $cache)) return $cache[$userId];
    $st = $pdo->prepare('SELECT name, city, photo_url FROM teams WHERE user_id = ? LIMIT 1');
    $st->execute([$userId]);
    $t = $st->fetch(PDO::FETCH_ASSOC);
    $out = $t
        ? ['nome' => trim($t['city'] . ' ' . $t['name']), 'logo' => getTeamPhoto($t['photo_url'] ?? null)]
        : ['nome' => null, 'logo' => null];
    $cache[$userId] = $out;
    return $out;
}

function dtSerializar(PDO $pdo, ?array $duelo, int $userId): ?array
{
    if (!$duelo) return null;
    $souCriador = (int)$duelo['id_criador'] === $userId;
    $meuLado = $souCriador ? 'criador' : 'desafiado';
    $oponenteLado = $souCriador ? 'desafiado' : 'criador';

    $ehPvp = false;
    $oponenteNome = null;
    $oponenteTime = ['nome' => null, 'logo' => null];
    if ($duelo['id_desafiado'] !== null) {
        $oponenteId = $souCriador ? (int)$duelo['id_desafiado'] : (int)$duelo['id_criador'];
        if ($oponenteId === 0) {
            $oponenteNome = 'Máquina 🤖';
        } else {
            $ehPvp = true;
            $st = $pdo->prepare('SELECT nome FROM games_usuarios WHERE id = ?');
            $st->execute([$oponenteId]);
            $oponenteNome = $st->fetchColumn() ?: 'Oponente';
            $oponenteTime = dtTimeDoUsuario($pdo, $oponenteId);
        }
    }
    // Nome de verdade de quem está vendo a tela — só usado no lugar de "Você" quando é PvP
    // (contra a máquina soa mais natural continuar chamando de "Você"). O time de franquia,
    // quando existe, tem prioridade sobre o nome pessoal em qualquer confronto (até vs CPU).
    $stMeuNome = $pdo->prepare('SELECT nome FROM games_usuarios WHERE id = ?');
    $stMeuNome->execute([$userId]);
    $meuNome = $stMeuNome->fetchColumn() ?: 'Você';
    $meuTime = dtTimeDoUsuario($pdo, $userId);

    $out = [
        'id' => (int)$duelo['id'],
        'codigo' => $duelo['codigo'],
        'status' => $duelo['status'],
        'aposta' => (int)$duelo['aposta'],
        'modo' => $duelo['modo'] ?? 'amigo',
        'sou_criador' => $souCriador,
        'eh_pvp' => $ehPvp,
        'oponente_nome' => $oponenteNome,
        'oponente_time_nome' => $oponenteTime['nome'],
        'oponente_time_logo' => $oponenteTime['logo'],
        'meu_nome' => $meuNome,
        'meu_time_nome' => $meuTime['nome'],
        'meu_time_logo' => $meuTime['logo'],
    ];

    if (in_array($duelo['status'], ['draft', 'simulado'], true)) {
        $out['meu_roster'] = json_decode((string)$duelo[$meuLado === 'criador' ? 'roster_criador' : 'roster_desafiado'], true) ?: dtRosterVazio();
        $out['oponente_roster'] = json_decode((string)$duelo[$oponenteLado === 'criador' ? 'roster_criador' : 'roster_desafiado'], true) ?: dtRosterVazio();
    }

    if ($duelo['status'] === 'draft') {
        $out['minha_vez'] = ($duelo['turno'] === $meuLado);
        $out['reroll_disponivel'] = (bool)$duelo['reroll_disponivel'];
        $out['time_sorteado'] = dtTimePorId((string)$duelo['time_sorteado_id']);
    }

    if ($duelo['status'] === 'simulado') {
        $resultado = json_decode((string)$duelo['resultado'], true) ?: [];
        $out['resultado'] = $resultado;
        $out['meu_lado'] = $meuLado === 'criador' ? 'a' : 'b';
        $out['eu_venci'] = ((int)$duelo['id_vencedor'] === $userId);
    }

    return $out;
}

// ── AÇÕES ────────────────────────────────────────────────────────────────────
if (($_POST['acao'] ?? '') !== '') {
    header('Content-Type: application/json; charset=utf-8');
    $acao = $_POST['acao'];
    dtExpirarAntigos($pdo);

    try {
        if ($acao === 'criar') {
            if (dtDueloEmAndamento($pdo, $user_id)) {
                echo json_encode(['ok' => false, 'msg' => 'Você já tem um duelo em andamento.']);
                exit;
            }
            $aposta = (int)($_POST['aposta'] ?? 0);
            if ($aposta < DT_APOSTA_MIN || $aposta > DT_APOSTA_MAX) {
                echo json_encode(['ok' => false, 'msg' => 'A aposta deve ser entre ' . DT_APOSTA_MIN . ' e ' . DT_APOSTA_MAX . ' moedas.']);
                exit;
            }

            $pdo->beginTransaction();
            try {
                $st = $pdo->prepare('SELECT pontos FROM games_usuarios WHERE id = ? FOR UPDATE');
                $st->execute([$user_id]);
                $saldo = (int)$st->fetchColumn();
                if ($saldo < $aposta) {
                    $pdo->rollBack();
                    echo json_encode(['ok' => false, 'msg' => 'Saldo insuficiente.']);
                    exit;
                }
                $pdo->prepare('UPDATE games_usuarios SET pontos = pontos - ? WHERE id = ?')->execute([$aposta, $user_id]);

                $codigo = dtGerarCodigo($pdo);
                $pdo->prepare("INSERT INTO dreamteam_duelos (codigo, id_criador, aposta, status) VALUES (?, ?, ?, 'aguardando')")
                    ->execute([$codigo, $user_id, $aposta]);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
            echo json_encode(['ok' => true]);
            exit;
        }

        // Contra a máquina: sem sala de espera — sorteia quem começa e, se for
        // a CPU, ela já joga o primeiro turno dela na mesma requisição.
        if ($acao === 'criar_vs_cpu') {
            if (dtDueloEmAndamento($pdo, $user_id)) {
                echo json_encode(['ok' => false, 'msg' => 'Você já tem um duelo em andamento.']);
                exit;
            }
            $aposta = (int)($_POST['aposta'] ?? 0);
            if ($aposta < DT_APOSTA_MIN || $aposta > DT_APOSTA_MAX_CPU) {
                echo json_encode(['ok' => false, 'msg' => 'Contra a máquina a aposta deve ser entre ' . DT_APOSTA_MIN . ' e ' . DT_APOSTA_MAX_CPU . ' moedas.']);
                exit;
            }

            $pdo->beginTransaction();
            try {
                $st = $pdo->prepare('SELECT pontos FROM games_usuarios WHERE id = ? FOR UPDATE');
                $st->execute([$user_id]);
                $saldo = (int)$st->fetchColumn();
                if ($saldo < $aposta) {
                    $pdo->rollBack();
                    echo json_encode(['ok' => false, 'msg' => 'Saldo insuficiente.']);
                    exit;
                }
                $pdo->prepare('UPDATE games_usuarios SET pontos = pontos - ? WHERE id = ?')->execute([$aposta, $user_id]);

                $rosterVazio = json_encode(dtRosterVazio());
                $primeiroTurno = random_int(0, 1) ? 'criador' : 'desafiado';
                // time_sorteado_id nasce vazio — a CPU sorteia sozinha na hora dela (dtCpuJogar); o
                // criador (sempre humano) só sorteia quando clicar em "Sortear Time".

                $codigo = dtGerarCodigo($pdo);
                $pdo->prepare("INSERT INTO dreamteam_duelos
                        (codigo, id_criador, id_desafiado, aposta, status, turno, roster_criador, roster_desafiado, time_sorteado_id, reroll_disponivel, entrou_em)
                        VALUES (?, ?, 0, ?, 'draft', ?, ?, ?, NULL, 1, NOW())")
                    ->execute([$codigo, $user_id, $aposta, $primeiroTurno, $rosterVazio, $rosterVazio]);
                $dueloId = (int)$pdo->lastInsertId();

                if ($primeiroTurno === 'desafiado') {
                    dtCpuJogar($pdo, $dueloId);
                }
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
            echo json_encode(['ok' => true]);
            exit;
        }

        if ($acao === 'entrar') {
            if (dtDueloEmAndamento($pdo, $user_id)) {
                echo json_encode(['ok' => false, 'msg' => 'Você já tem um duelo em andamento.']);
                exit;
            }
            $codigo = strtoupper(trim((string)($_POST['codigo'] ?? '')));
            if ($codigo === '') {
                echo json_encode(['ok' => false, 'msg' => 'Digite um código.']);
                exit;
            }

            $pdo->beginTransaction();
            try {
                $st = $pdo->prepare('SELECT * FROM dreamteam_duelos WHERE codigo = ? FOR UPDATE');
                $st->execute([$codigo]);
                $duelo = $st->fetch(PDO::FETCH_ASSOC);
                if (!$duelo) { $pdo->rollBack(); echo json_encode(['ok' => false, 'msg' => 'Código não encontrado.']); exit; }
                if ($duelo['status'] !== 'aguardando') {
                    $pdo->rollBack();
                    // draft/simulado = já tinha 2 pessoas quando esse código foi usado; cancelado/expirado = a
                    // sala nunca chegou a fechar. Mensagens diferentes pra ficar claro o motivo.
                    $msg = in_array($duelo['status'], ['draft', 'simulado'], true)
                        ? 'Sala cheia — esse duelo já tem 2 jogadores.'
                        : 'Esse duelo não está mais disponível.';
                    echo json_encode(['ok' => false, 'msg' => $msg]);
                    exit;
                }
                if ((int)$duelo['id_criador'] === $user_id) { $pdo->rollBack(); echo json_encode(['ok' => false, 'msg' => 'Você não pode entrar no seu próprio duelo.']); exit; }

                $aposta = (int)$duelo['aposta'];
                $stS = $pdo->prepare('SELECT pontos FROM games_usuarios WHERE id = ? FOR UPDATE');
                $stS->execute([$user_id]);
                $saldo = (int)$stS->fetchColumn();
                if ($saldo < $aposta) {
                    $pdo->rollBack();
                    echo json_encode(['ok' => false, 'msg' => "Saldo insuficiente pra cobrir a aposta ({$aposta} moedas)."]);
                    exit;
                }
                $pdo->prepare('UPDATE games_usuarios SET pontos = pontos - ? WHERE id = ?')->execute([$aposta, $user_id]);

                $rosterVazio = json_encode(dtRosterVazio());
                $primeiroTurno = random_int(0, 1) ? 'criador' : 'desafiado';
                // PvP: os dois lados são humanos — ninguém sorteia até clicar em "Sortear Time".
                $pdo->prepare("UPDATE dreamteam_duelos
                        SET id_desafiado = ?, status = 'draft', turno = ?, roster_criador = ?, roster_desafiado = ?,
                            time_sorteado_id = NULL, reroll_disponivel = 1, entrou_em = NOW()
                        WHERE id = ?")
                    ->execute([$user_id, $primeiroTurno, $rosterVazio, $rosterVazio, $duelo['id']]);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
            echo json_encode(['ok' => true]);
            exit;
        }

        // Modo aleatório: sem link, aposta fixa. Casa com quem já estiver esperando (o mais
        // antigo primeiro); se ninguém estiver, cria a sala e fica esperando do mesmo jeito.
        if ($acao === 'jogar_aleatorio') {
            if (dtDueloEmAndamento($pdo, $user_id)) {
                echo json_encode(['ok' => false, 'msg' => 'Você já tem um duelo em andamento.']);
                exit;
            }
            $aposta = DT_APOSTA_ALEATORIA;

            $pdo->beginTransaction();
            try {
                // FOR UPDATE aqui garante que só 1 pessoa por vez consegue casar com essa sala —
                // a mesma trava que impede uma 3ª pessoa de entrar num duelo por código.
                $stEspera = $pdo->prepare("
                    SELECT * FROM dreamteam_duelos
                    WHERE status = 'aguardando' AND modo = 'aleatorio' AND id_criador != ?
                    ORDER BY criado_em ASC LIMIT 1 FOR UPDATE
                ");
                $stEspera->execute([$user_id]);
                $duelo = $stEspera->fetch(PDO::FETCH_ASSOC);

                $st = $pdo->prepare('SELECT pontos FROM games_usuarios WHERE id = ? FOR UPDATE');
                $st->execute([$user_id]);
                $saldo = (int)$st->fetchColumn();
                if ($saldo < $aposta) {
                    $pdo->rollBack();
                    echo json_encode(['ok' => false, 'msg' => "Saldo insuficiente pra cobrir a aposta ({$aposta} moedas)."]);
                    exit;
                }
                $pdo->prepare('UPDATE games_usuarios SET pontos = pontos - ? WHERE id = ?')->execute([$aposta, $user_id]);

                if ($duelo) {
                    // Alguém já estava esperando — casa direto, o confronto começa na hora.
                    $rosterVazio = json_encode(dtRosterVazio());
                    $primeiroTurno = random_int(0, 1) ? 'criador' : 'desafiado';
                    $pdo->prepare("UPDATE dreamteam_duelos
                            SET id_desafiado = ?, status = 'draft', turno = ?, roster_criador = ?, roster_desafiado = ?,
                                time_sorteado_id = NULL, reroll_disponivel = 1, entrou_em = NOW()
                            WHERE id = ?")
                        ->execute([$user_id, $primeiroTurno, $rosterVazio, $rosterVazio, $duelo['id']]);
                } else {
                    // Ninguém esperando — abre a sala e fica aguardando, igual ao modo amigo.
                    $codigo = dtGerarCodigo($pdo);
                    $pdo->prepare("INSERT INTO dreamteam_duelos (codigo, id_criador, aposta, modo, status) VALUES (?, ?, ?, 'aleatorio', 'aguardando')")
                        ->execute([$codigo, $user_id, $aposta]);
                }
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
            echo json_encode(['ok' => true]);
            exit;
        }

        if ($acao === 'cancelar') {
            $duelo = dtDueloAtivo($pdo, $user_id);
            if (!$duelo) { echo json_encode(['ok' => false, 'msg' => 'Nenhum duelo pra cancelar.']); exit; }
            if ((int)$duelo['id_criador'] !== $user_id) { echo json_encode(['ok' => false, 'msg' => 'Só quem criou pode cancelar.']); exit; }
            if ($duelo['status'] !== 'aguardando') { echo json_encode(['ok' => false, 'msg' => 'Só dá pra cancelar antes de alguém entrar.']); exit; }

            $pdo->beginTransaction();
            try {
                $pdo->prepare('UPDATE games_usuarios SET pontos = pontos + ? WHERE id = ?')->execute([(int)$duelo['aposta'], $user_id]);
                $pdo->prepare("UPDATE dreamteam_duelos SET status = 'cancelado' WHERE id = ?")->execute([$duelo['id']]);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
            echo json_encode(['ok' => true]);
            exit;
        }

        // Sorteia o time da rodada — ação manual (botão "Sortear Time"), só quando ainda não sorteou nessa vez.
        if ($acao === 'sortear_time') {
            $duelo = dtDueloAtivo($pdo, $user_id);
            if (!$duelo || $duelo['status'] !== 'draft') { echo json_encode(['ok' => false, 'msg' => 'Esse duelo não está em draft.']); exit; }
            $lado = ((int)$duelo['id_criador'] === $user_id) ? 'criador' : 'desafiado';
            if ($duelo['turno'] !== $lado) { echo json_encode(['ok' => false, 'msg' => 'Não é sua vez.']); exit; }
            if ($duelo['time_sorteado_id'] !== null) { echo json_encode(['ok' => false, 'msg' => 'Você já sorteou um time nessa rodada.']); exit; }

            $pdo->beginTransaction();
            try {
                $st = $pdo->prepare('SELECT * FROM dreamteam_duelos WHERE id = ? FOR UPDATE');
                $st->execute([$duelo['id']]);
                $atual = $st->fetch(PDO::FETCH_ASSOC);
                if (!$atual || $atual['status'] !== 'draft' || $atual['turno'] !== $lado || $atual['time_sorteado_id'] !== null) {
                    $pdo->rollBack();
                    echo json_encode(['ok' => false, 'msg' => 'Não deu pra sortear agora.']);
                    exit;
                }
                $roster = json_decode((string)$atual[$lado === 'criador' ? 'roster_criador' : 'roster_desafiado'], true) ?: dtRosterVazio();
                $novoTime = dtSortearTimeValido($roster);
                $pdo->prepare('UPDATE dreamteam_duelos SET time_sorteado_id = ?, reroll_disponivel = 1 WHERE id = ?')
                    ->execute([$novoTime['id'], $atual['id']]);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
            echo json_encode(['ok' => true]);
            exit;
        }

        // Gira de novo o time já sorteado nessa rodada — 1 vez, só de quem está na vez.
        if ($acao === 'girar_de_novo') {
            $duelo = dtDueloAtivo($pdo, $user_id);
            if (!$duelo || $duelo['status'] !== 'draft') { echo json_encode(['ok' => false, 'msg' => 'Esse duelo não está em draft.']); exit; }
            $lado = ((int)$duelo['id_criador'] === $user_id) ? 'criador' : 'desafiado';
            if ($duelo['turno'] !== $lado) { echo json_encode(['ok' => false, 'msg' => 'Não é sua vez.']); exit; }
            if ($duelo['time_sorteado_id'] === null) { echo json_encode(['ok' => false, 'msg' => 'Sorteie um time primeiro.']); exit; }
            if ((int)$duelo['reroll_disponivel'] !== 1) { echo json_encode(['ok' => false, 'msg' => 'Você já girou de novo nessa rodada.']); exit; }

            $pdo->beginTransaction();
            try {
                $st = $pdo->prepare('SELECT * FROM dreamteam_duelos WHERE id = ? FOR UPDATE');
                $st->execute([$duelo['id']]);
                $atual = $st->fetch(PDO::FETCH_ASSOC);
                if (!$atual || $atual['status'] !== 'draft' || $atual['turno'] !== $lado || (int)$atual['reroll_disponivel'] !== 1 || $atual['time_sorteado_id'] === null) {
                    $pdo->rollBack();
                    echo json_encode(['ok' => false, 'msg' => 'Não deu pra girar de novo.']);
                    exit;
                }
                $roster = json_decode((string)$atual[$lado === 'criador' ? 'roster_criador' : 'roster_desafiado'], true) ?: dtRosterVazio();
                $novoTime = dtSortearTimeValido($roster);
                $pdo->prepare('UPDATE dreamteam_duelos SET time_sorteado_id = ?, reroll_disponivel = 0 WHERE id = ?')
                    ->execute([$novoTime['id'], $atual['id']]);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
            echo json_encode(['ok' => true]);
            exit;
        }

        // Move um jogador já escolhido pra outra posição em que ele também é elegível, liberando
        // a posição antiga — não consome a rodada, é só reorganização (ex: eu tinha um PG/SG no
        // PG, apareceu um PG melhor, então libero o PG movendo esse jogador pro SG aberto).
        if ($acao === 'reposicionar_jogador') {
            $duelo = dtDueloAtivo($pdo, $user_id);
            if (!$duelo || $duelo['status'] !== 'draft') { echo json_encode(['ok' => false, 'msg' => 'Esse duelo não está em draft.']); exit; }
            $lado = ((int)$duelo['id_criador'] === $user_id) ? 'criador' : 'desafiado';
            if ($duelo['turno'] !== $lado) { echo json_encode(['ok' => false, 'msg' => 'Não é sua vez.']); exit; }

            $de = strtoupper(trim((string)($_POST['de'] ?? '')));
            $para = strtoupper(trim((string)($_POST['para'] ?? '')));
            if (!in_array($de, dtPosicoes(), true) || !in_array($para, dtPosicoes(), true) || $de === $para) {
                echo json_encode(['ok' => false, 'msg' => 'Reposicionamento inválido.']);
                exit;
            }

            $pdo->beginTransaction();
            try {
                $st = $pdo->prepare('SELECT * FROM dreamteam_duelos WHERE id = ? FOR UPDATE');
                $st->execute([$duelo['id']]);
                $atual = $st->fetch(PDO::FETCH_ASSOC);
                if (!$atual || $atual['status'] !== 'draft' || $atual['turno'] !== $lado) {
                    $pdo->rollBack();
                    echo json_encode(['ok' => false, 'msg' => 'Esse duelo não está mais disponível.']);
                    exit;
                }

                $colRoster = $lado === 'criador' ? 'roster_criador' : 'roster_desafiado';
                $roster = json_decode((string)$atual[$colRoster], true) ?: dtRosterVazio();
                $jogador = $roster[$de] ?? null;
                if (!$jogador) {
                    $pdo->rollBack();
                    echo json_encode(['ok' => false, 'msg' => 'Não tem jogador nessa posição.']);
                    exit;
                }
                if ($roster[$para] !== null) {
                    $pdo->rollBack();
                    echo json_encode(['ok' => false, 'msg' => 'Essa posição já está ocupada.']);
                    exit;
                }
                if (!in_array($para, $jogador['pos'] ?? [], true)) {
                    $pdo->rollBack();
                    echo json_encode(['ok' => false, 'msg' => 'Esse jogador não joga nessa posição.']);
                    exit;
                }

                $roster[$para] = $jogador;
                $roster[$de] = null;
                $pdo->prepare("UPDATE dreamteam_duelos SET {$colRoster} = ? WHERE id = ?")->execute([json_encode($roster), $atual['id']]);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
            echo json_encode(['ok' => true]);
            exit;
        }

        // Escolhe 1 jogador do time sorteado pra uma posição vazia do próprio time.
        if ($acao === 'escolher_jogador') {
            $duelo = dtDueloAtivo($pdo, $user_id);
            if (!$duelo || $duelo['status'] !== 'draft') { echo json_encode(['ok' => false, 'msg' => 'Esse duelo não está em draft.']); exit; }
            $lado = ((int)$duelo['id_criador'] === $user_id) ? 'criador' : 'desafiado';
            if ($duelo['turno'] !== $lado) { echo json_encode(['ok' => false, 'msg' => 'Não é sua vez.']); exit; }

            $nomeJogador = trim((string)($_POST['jogador'] ?? ''));
            $posEscolhida = strtoupper(trim((string)($_POST['posicao'] ?? '')));
            if ($nomeJogador === '' || !in_array($posEscolhida, dtPosicoes(), true)) {
                echo json_encode(['ok' => false, 'msg' => 'Escolha inválida.']);
                exit;
            }

            $pdo->beginTransaction();
            try {
                $st = $pdo->prepare('SELECT * FROM dreamteam_duelos WHERE id = ? FOR UPDATE');
                $st->execute([$duelo['id']]);
                $atual = $st->fetch(PDO::FETCH_ASSOC);
                if (!$atual || $atual['status'] !== 'draft' || $atual['turno'] !== $lado) {
                    $pdo->rollBack();
                    echo json_encode(['ok' => false, 'msg' => 'Esse duelo não está mais disponível pra escolher.']);
                    exit;
                }

                $time = dtTimePorId((string)$atual['time_sorteado_id']);
                $jogador = null;
                if ($time) foreach ($time['jogadores'] as $j) if ($j['nome'] === $nomeJogador) { $jogador = $j; break; }
                if (!$jogador) {
                    $pdo->rollBack();
                    echo json_encode(['ok' => false, 'msg' => 'Jogador não encontrado no time sorteado.']);
                    exit;
                }
                if (!in_array($posEscolhida, $jogador['pos'], true)) {
                    $pdo->rollBack();
                    echo json_encode(['ok' => false, 'msg' => 'Esse jogador não joga nessa posição.']);
                    exit;
                }

                $colRoster = $lado === 'criador' ? 'roster_criador' : 'roster_desafiado';
                $roster = json_decode((string)$atual[$colRoster], true) ?: dtRosterVazio();
                if ($roster[$posEscolhida] !== null) {
                    $pdo->rollBack();
                    echo json_encode(['ok' => false, 'msg' => 'Essa posição já está preenchida.']);
                    exit;
                }
                if (isset(dtNomesNoRoster($roster)[$jogador['nome']])) {
                    $pdo->rollBack();
                    echo json_encode(['ok' => false, 'msg' => 'Esse jogador já está no seu time.']);
                    exit;
                }
                $roster[$posEscolhida] = ['nome' => $jogador['nome'], 'ovr' => $jogador['ovr'], 'pos' => $jogador['pos']];
                $pdo->prepare("UPDATE dreamteam_duelos SET {$colRoster} = ? WHERE id = ?")->execute([json_encode($roster), $atual['id']]);

                $rosterCriador = $lado === 'criador' ? $roster : (json_decode((string)$atual['roster_criador'], true) ?: dtRosterVazio());
                $rosterDesafiado = $lado === 'desafiado' ? $roster : (json_decode((string)$atual['roster_desafiado'], true) ?: dtRosterVazio());

                if (dtRosterCompleto($rosterCriador) && dtRosterCompleto($rosterDesafiado)) {
                    $resultado = dtCalcularResultado($rosterCriador, $rosterDesafiado);
                    $vencedorId = $resultado['vencedor'] === 'a' ? (int)$atual['id_criador'] : (int)$atual['id_desafiado'];
                    $premio = (int)$atual['aposta'] * 2;
                    $pdo->prepare('UPDATE games_usuarios SET pontos = pontos + ? WHERE id = ?')->execute([$premio, $vencedorId]);
                    $pdo->prepare("UPDATE dreamteam_duelos SET status = 'simulado', resultado = ?, id_vencedor = ?, concluido_em = NOW() WHERE id = ?")
                        ->execute([json_encode($resultado), $vencedorId, $atual['id']]);
                } else {
                    $proximoLado = $lado === 'criador' ? 'desafiado' : 'criador';
                    // time_sorteado_id fica vazio pro próximo turno — se for humano, ele sorteia
                    // clicando em "Sortear Time"; se for CPU, ela sorteia sozinha logo abaixo.
                    $pdo->prepare("UPDATE dreamteam_duelos SET turno = ?, time_sorteado_id = NULL, reroll_disponivel = 1 WHERE id = ?")
                        ->execute([$proximoLado, $atual['id']]);

                    if ($proximoLado === 'desafiado' && (int)$atual['id_desafiado'] === 0) {
                        dtCpuJogar($pdo, (int)$atual['id']);
                    }
                }
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
            echo json_encode(['ok' => true]);
            exit;
        }

        if ($acao === 'estado') {
            $duelo = dtDueloAtivo($pdo, $user_id);
            $st = $pdo->prepare('SELECT pontos FROM games_usuarios WHERE id = ?');
            $st->execute([$user_id]);
            echo json_encode(['ok' => true, 'duelo' => dtSerializar($pdo, $duelo, $user_id), 'pontos' => (int)($st->fetchColumn() ?: 0)]);
            exit;
        }

        if ($acao === 'ranking') {
            echo json_encode(['ok' => true, 'ranking' => dtRankingVitorias($pdo), 'rivalidades' => dtMaioresRivalidades($pdo)]);
            exit;
        }

        echo json_encode(['ok' => false, 'msg' => 'Ação inválida.']);
    } catch (Throwable $e) {
        error_log('[dreamteam] ' . $e->getMessage());
        echo json_encode(['ok' => false, 'msg' => 'Erro interno. Tente de novo.']);
    }
    exit;
}

// ── TELA ────────────────────────────────────────────────────────────────────
dtExpirarAntigos($pdo);
$duelo = dtDueloAtivo($pdo, $user_id);
$estadoInicial = dtSerializar($pdo, $duelo, $user_id);
$stPontos = $pdo->prepare('SELECT pontos FROM games_usuarios WHERE id = ?');
$stPontos->execute([$user_id]);
$meuSaldo = (int)($stPontos->fetchColumn() ?: 0);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no">
<title>Starting5x5 — Dream Team em Duelo</title>
<link rel="icon" type="image/png" href="/games/fbagames.png">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700;800;900&display=swap" rel="stylesheet">
<style>
:root{
  --bg:#07070a;--panel:#101013;--panel2:#16161a;--panel3:#1c1c21;
  --border:rgba(255,255,255,.07);--border2:rgba(255,255,255,.14);
  --red:#fc0025;--red-soft:rgba(252,0,37,.12);--red-glow:rgba(252,0,37,.25);
  --text:#f0f0f3;--text2:#868690;--text3:#3c3c44;
  --green:#22c55e;--green-soft:rgba(34,197,94,.12);
  --amber:#f59e0b;--amber-soft:rgba(245,158,11,.12);
  --blue:#3b82f6;--blue-soft:rgba(59,130,246,.12);
  --radius:14px;--font:'Poppins',sans-serif;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:var(--font);background:var(--bg);color:var(--text);min-height:100vh;-webkit-font-smoothing:antialiased;overflow-x:hidden}

.topbar{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:10px 14px;background:var(--panel);border-bottom:1px solid var(--border);position:sticky;top:0;z-index:50}
.topbar-left{display:flex;align-items:center;gap:10px}
.back-btn{display:flex;align-items:center;justify-content:center;width:32px;height:32px;border-radius:9px;border:1px solid var(--border);background:transparent;color:var(--text2);text-decoration:none;font-size:14px;transition:.2s;flex-shrink:0}
.back-btn:hover{border-color:var(--red);color:var(--red);background:var(--red-soft)}
.game-title{font-size:15px;font-weight:800;color:var(--text)}
.game-title span{color:var(--red)}
.topbar-right{display:flex;align-items:center;gap:6px}
.chip{display:flex;align-items:center;gap:4px;padding:4px 10px;border-radius:20px;background:var(--panel2);border:1px solid var(--border);font-size:11px;font-weight:700;color:var(--text);white-space:nowrap}

.main{max-width:680px;margin:0 auto;padding:16px 12px 60px}
.dtcard{background:var(--panel);border:1px solid var(--border);border-radius:var(--radius);padding:18px;margin-bottom:14px;color:var(--text)}
.dtcard-title{font-size:10px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:var(--text2);margin-bottom:12px}
.dtcard-sub{font-size:12.5px;color:var(--text2);line-height:1.55;margin-bottom:14px}

.dt-tabs{display:flex;gap:8px;margin-bottom:16px}
.dt-tab{flex:1;text-align:center;padding:10px;border-radius:10px;background:var(--panel2);border:1.5px solid var(--border);cursor:pointer;font-size:12px;font-weight:700;color:var(--text2);transition:.15s}
.dt-tab.active{border-color:var(--red);background:var(--red-soft);color:var(--red)}
.dt-tab-destaque{border-color:var(--amber);color:var(--amber);box-shadow:0 0 0 1px color-mix(in srgb, var(--amber) 35%, transparent)}
.dt-tab-destaque.active{border-color:var(--amber);background:var(--amber-soft);color:var(--amber);box-shadow:0 0 12px color-mix(in srgb, var(--amber) 45%, transparent)}

.field label{display:block;font-size:9px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:var(--text2);margin-bottom:6px}
.field input{width:100%;background:var(--panel2);border:1.5px solid var(--border);border-radius:10px;padding:11px 12px;font-family:var(--font);font-size:14px;font-weight:700;color:var(--text);outline:none;transition:.15s}
.field input:focus{border-color:var(--red);background:var(--red-soft)}
.field-hint{font-size:11px;color:var(--text3);margin-top:6px}

.btn-dt{width:100%;padding:13px;border-radius:11px;border:none;background:var(--red);color:#fff;font-family:var(--font);font-size:14px;font-weight:800;cursor:pointer;transition:.15s;margin-top:14px}
.btn-dt:hover:not(:disabled){filter:brightness(1.1)}
.btn-dt:disabled{opacity:.5;cursor:not-allowed}
.btn-dt-ghost{width:100%;padding:11px;border-radius:11px;border:1px solid var(--border2);background:transparent;color:var(--text2);font-family:var(--font);font-size:12.5px;font-weight:700;cursor:pointer;margin-top:8px}
.btn-dt-ghost:hover{border-color:var(--red);color:var(--red)}
.btn-dt-amber{width:100%;padding:11px;border-radius:11px;border:1.5px solid var(--amber);background:var(--amber-soft);color:var(--amber);font-family:var(--font);font-size:12.5px;font-weight:800;cursor:pointer;margin-top:10px}
.btn-dt-amber:hover:not(:disabled){background:var(--amber);color:#1a1200}
.btn-dt-amber:disabled{opacity:.4;cursor:not-allowed}

.dt-codigo-box{text-align:center;padding:22px;background:var(--panel2);border:1.5px dashed var(--border2);border-radius:12px;margin-bottom:14px}
.dt-codigo-valor{font-size:34px;font-weight:900;letter-spacing:6px;color:var(--red);font-variant-numeric:tabular-nums}
.dt-codigo-label{font-size:10px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:var(--text2);margin-top:6px}

.dt-spinner{width:32px;height:32px;border:3px solid var(--border);border-top-color:var(--red);border-radius:50%;margin:0 auto 14px;animation:dt-spin 1s linear infinite}
@keyframes dt-spin{to{transform:rotate(360deg)}}

.dt-empty{text-align:center;padding:20px;color:var(--text2);font-size:12.5px}

/* Roster (PG/SG/SF/PF/C) */
.dt-roster-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:8px}
.dt-roster-nome{font-size:12px;font-weight:800;display:inline-flex;align-items:center;gap:6px;min-width:0}
.dt-roster-nome span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.dt-roster-total{font-size:11px;color:var(--amber);font-weight:800}
.dt-roster-grid{display:grid;grid-template-columns:repeat(5,1fr);gap:6px}
.dt-roster-slot{background:var(--panel2);border:1.5px dashed var(--border2);border-radius:9px;padding:8px 4px;text-align:center;min-height:58px;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:2px}
.dt-roster-slot.preenchido{border-style:solid;border-color:var(--border2);background:var(--panel3)}
.dt-roster-slot.movivel{cursor:pointer;border-color:var(--blue)}
.dt-roster-slot.movivel:hover{background:var(--blue-soft)}
.dt-roster-slot .dt-rs-pos{font-size:8.5px;font-weight:700;letter-spacing:.5px;color:var(--text3);text-transform:uppercase}
.dt-roster-slot .dt-rs-nome{font-size:9.5px;font-weight:700;color:var(--text);line-height:1.2;word-break:break-word}
.dt-roster-slot .dt-rs-ovr{font-size:11px;font-weight:900;color:var(--amber)}

.dt-turno-banner{text-align:center;padding:10px;border-radius:10px;margin-bottom:14px;font-size:12.5px;font-weight:700}
.dt-turno-banner.minha-vez{background:var(--red-soft);color:var(--red);border:1px solid var(--red-glow)}
.dt-turno-banner.vez-oponente{background:var(--panel2);color:var(--text2);border:1px solid var(--border)}

.dt-time-nome{text-align:center;font-size:13px;font-weight:800;margin-bottom:2px}
.dt-time-ano{text-align:center;font-size:10.5px;color:var(--text2);margin-bottom:12px}
.dt-jogadores-grid{display:flex;flex-direction:column;gap:8px;margin-bottom:6px}
.dt-jogador-card{display:flex;align-items:center;gap:10px;background:var(--panel2);border:1.5px solid var(--border);border-radius:11px;padding:10px 12px;transition:.12s}
.dt-jogador-card.clicavel{cursor:pointer}
.dt-jogador-card.clicavel:hover{border-color:var(--red);background:var(--red-soft)}
.dt-jogador-card.desabilitado{opacity:.35}
.dt-jogador-nome{font-size:13px;font-weight:700;flex:1}
.dt-jogador-pos{display:flex;gap:4px}
.dt-pos-badge{font-size:9.5px;font-weight:800;padding:2px 7px;border-radius:6px;background:var(--panel3);color:var(--text2);border:1px solid var(--border2)}
.dt-jogador-ovr{font-size:15px;font-weight:900;color:var(--amber);min-width:28px;text-align:right}

.dt-escolha-pos{display:flex;gap:6px;margin-top:8px}
.dt-escolha-pos button{flex:1;padding:8px;border-radius:8px;border:1.5px solid var(--red);background:var(--red-soft);color:var(--red);font-family:var(--font);font-size:11px;font-weight:800;cursor:pointer}
.dt-escolha-pos button:hover{background:var(--red);color:#fff}

.dt-placar{display:flex;align-items:center;justify-content:center;gap:18px;margin-bottom:16px}
.dt-placar-lado{flex:1;text-align:center}
.dt-placar-nome{font-size:11.5px;font-weight:700;color:var(--text2);margin-bottom:6px;text-transform:uppercase;letter-spacing:.5px;display:flex;align-items:center;justify-content:center;gap:6px}
.dt-placar-num{font-size:40px;font-weight:900;font-variant-numeric:tabular-nums}
.dt-placar-num.vencedor{color:var(--green)}
.dt-placar-x{font-size:16px;color:var(--text3);font-weight:700}

.dt-resultado-msg{text-align:center;padding:14px;border-radius:11px;margin-bottom:16px;font-size:14px;font-weight:800}
.dt-resultado-msg.venceu{background:var(--green-soft);color:var(--green);border:1px solid rgba(34,197,94,.35)}
.dt-resultado-msg.perdeu{background:var(--red-soft);color:var(--red);border:1px solid rgba(252,0,37,.3)}

.dt-quartos-resumo{text-align:center;font-size:11.5px;color:var(--text2);margin-bottom:4px;font-variant-numeric:tabular-nums}
.dt-zebra-badge{text-align:center;padding:9px;border-radius:10px;margin-bottom:14px;font-size:12.5px;font-weight:800;background:var(--amber-soft);color:var(--amber);border:1px solid color-mix(in srgb, var(--amber) 40%, transparent)}
.dt-box-table{width:100%;border-collapse:collapse;font-size:12px}
.dt-box-table th{text-align:center;font-size:9.5px;font-weight:700;color:var(--text2);text-transform:uppercase;letter-spacing:.5px;padding:6px 4px;border-bottom:1px solid var(--border)}
.dt-box-table td{text-align:center;padding:7px 4px;border-bottom:1px solid var(--border);font-variant-numeric:tabular-nums}
.dt-box-table td.dt-box-nome{text-align:left;font-weight:700;font-variant-numeric:normal}
.dt-box-table td.dt-box-pos{color:var(--text2);font-weight:700;font-size:10.5px}
.dt-box-table tr:last-child td{border-bottom:none}
.dt-simcast-quartos{text-align:center;font-size:12px;color:var(--text2);margin-top:12px;min-height:16px}

/* Tabela de pontuação por quarto (Q1..Q4 + total), usada ao vivo no simcast e no resultado. */
.dt-qt-tabela{width:100%;border-collapse:collapse;margin-top:14px;font-variant-numeric:tabular-nums}
.dt-qt-tabela th{font-size:9.5px;font-weight:700;color:var(--text2);text-transform:uppercase;letter-spacing:.5px;padding:5px 2px;text-align:center}
.dt-qt-tabela th:first-child{text-align:left}
.dt-qt-tabela td{padding:8px 2px;text-align:center;font-size:13px;font-weight:700;color:var(--text2);border-top:1px solid var(--border)}
.dt-qt-tabela td.dt-qt-time{text-align:left;font-size:11.5px;font-weight:700;color:var(--text);max-width:0;width:42%}
.dt-qt-time-wrap{display:flex;align-items:center;gap:6px;min-width:0}
.dt-qt-time-wrap span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.dt-qt-tabela td.dt-qt-venceu{color:var(--text);background:color-mix(in srgb, var(--green) 12%, transparent)}
.dt-qt-tabela td.dt-qt-vazio{color:var(--text3)}
.dt-qt-tabela td.dt-qt-total{font-size:16px;font-weight:900;color:var(--text)}
.dt-qt-tabela td.dt-qt-total.liderando{color:var(--green)}
.dt-qt-tabela th.dt-qt-col-total{color:var(--text)}

.dt-destaque{display:flex;align-items:center;gap:10px;padding:9px 10px;background:var(--panel2);border:1px solid var(--border);border-radius:10px;margin-bottom:6px}
.dt-destaque-pts{font-size:15px;font-weight:900;color:var(--amber);min-width:34px;text-align:center}
.dt-destaque-txt{font-size:12px;color:var(--text)}
.dt-destaque-txt b{color:var(--text)}

.dt-ranking-lista{display:flex;flex-direction:column;gap:6px}
.dt-ranking-item{display:flex;align-items:center;gap:10px;padding:9px 12px;background:var(--panel2);border:1px solid var(--border);border-radius:10px}
.dt-ranking-pos{font-size:11px;font-weight:900;color:var(--text2);min-width:22px}
.dt-ranking-nome{font-size:12.5px;font-weight:700;color:var(--text);flex:1;min-width:0;display:flex;align-items:center;gap:6px}
.dt-ranking-nome span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.dt-ranking-vd{font-size:12px;white-space:nowrap;font-variant-numeric:tabular-nums}

.dt-time-logo{width:20px;height:20px;object-fit:contain;border-radius:5px;flex-shrink:0;background:var(--panel3);vertical-align:middle}
.dt-placar-nome .dt-time-logo{width:18px;height:18px}

@media(max-width:420px){
  .dt-roster-grid{grid-template-columns:repeat(5,1fr);gap:4px}
  .dt-roster-slot{padding:6px 2px;min-height:50px}
  .dt-roster-slot .dt-rs-nome{font-size:8.5px}
}

/* Modal próprio do jogo — substitui alert()/confirm() nativos do navegador. */
.dt-modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:500;align-items:center;justify-content:center;padding:20px}
.dt-modal-overlay.show{display:flex}
.dt-modal{background:var(--panel2);border:1px solid var(--border2);border-radius:16px;padding:22px 20px;max-width:340px;width:100%;box-shadow:0 20px 50px rgba(0,0,0,.5)}
.dt-modal-msg{font-size:14px;font-weight:600;color:var(--text);text-align:center;line-height:1.5;margin-bottom:18px;white-space:pre-line}
.dt-modal-botoes{display:flex;gap:10px}
.dt-modal-botoes button{flex:1;padding:12px;border-radius:10px;border:none;font-family:var(--font);font-size:13px;font-weight:800;cursor:pointer;transition:.15s}
.dt-modal-btn-ok{background:var(--red);color:#fff}
.dt-modal-btn-ok:hover{filter:brightness(1.1)}
.dt-modal-btn-cancelar{background:var(--panel3);color:var(--text2);border:1px solid var(--border2) !important}
.dt-modal-btn-cancelar:hover{color:var(--text)}
</style>
</head>
<body>

<div class="topbar">
  <div class="topbar-left">
    <a href="/games.php" class="back-btn" title="Voltar"><i class="bi bi-arrow-left"></i></a>
    <span class="game-title">Starting<span>5x5</span></span>
  </div>
  <div class="topbar-right">
    <div class="chip" style="color:var(--amber)"><img src="../moeda.png" style="width:16px;height:16px;object-fit:contain;vertical-align:middle"><span id="chipSaldo"><?= $meuSaldo ?></span></div>
  </div>
</div>

<div class="main" id="dtMain">
  <div class="dtcard"><div class="dt-spinner"></div><p class="dt-empty">Carregando...</p></div>
</div>

<div class="dt-modal-overlay" id="dtModalOverlay">
  <div class="dt-modal">
    <p class="dt-modal-msg" id="dtModalMsg"></p>
    <div class="dt-modal-botoes" id="dtModalBotoes"></div>
  </div>
</div>

<script>
const POSICOES = ['PG', 'SG', 'SF', 'PF', 'C'];
const MEU_USER_ID = <?= $user_id ?>;
const DT_APOSTA_ALEATORIA = <?= DT_APOSTA_ALEATORIA ?>;
let ESTADO_INICIAL = <?= json_encode($estadoInicial) ?>;
// dueloDispensadoId/resultadoFinalId guardados no localStorage (não só em memória) — senão um
// F5 ou sair-e-voltar pra página perdia essas marcações e o simcast rodava de novo do zero, ou
// a tela de resultado já vista/dispensada reaparecia como se fosse novidade.
let dueloDispensadoId = parseInt(localStorage.getItem('dt_dispensado_id') || '', 10) || null;
let processando = false; // trava o poll enquanto uma ação (escolher/girar/reposicionar) está em voo
let resultadoEmAnimacaoId = null; // duelo cujo simcast está rodando NESSA aba agora — só trava polls repetidos, não precisa persistir
let resultadoFinalId = parseInt(localStorage.getItem('dt_resultado_final_id') || '', 10) || null; // duelo cujo simcast já terminou — renderiza a tela final direto

function esc(s) {
  const d = document.createElement('div');
  d.textContent = s ?? '';
  return d.innerHTML;
}

// Modal próprio do jogo no lugar de alert()/confirm() nativos — mesma cara do resto do app,
// sem aquela barra "site diz" do navegador.
function dtAlerta(msg) {
  return new Promise((resolve) => {
    document.getElementById('dtModalMsg').textContent = msg;
    document.getElementById('dtModalBotoes').innerHTML = `<button class="dt-modal-btn-ok" id="dtModalOk">OK</button>`;
    document.getElementById('dtModalOverlay').classList.add('show');
    document.getElementById('dtModalOk').onclick = () => {
      document.getElementById('dtModalOverlay').classList.remove('show');
      resolve();
    };
  });
}

function dtConfirmar(msg) {
  return new Promise((resolve) => {
    document.getElementById('dtModalMsg').textContent = msg;
    document.getElementById('dtModalBotoes').innerHTML = `
      <button class="dt-modal-btn-cancelar" id="dtModalCancelar">Cancelar</button>
      <button class="dt-modal-btn-ok" id="dtModalConfirmar">Confirmar</button>`;
    document.getElementById('dtModalOverlay').classList.add('show');
    const fechar = (resultado) => {
      document.getElementById('dtModalOverlay').classList.remove('show');
      resolve(resultado);
    };
    document.getElementById('dtModalCancelar').onclick = () => fechar(false);
    document.getElementById('dtModalConfirmar').onclick = () => fechar(true);
  });
}

async function dtPost(acao, params = {}) {
  const body = new URLSearchParams({ acao, ...params });
  const res = await fetch(window.location.href, { method: 'POST', body });
  return res.json();
}

// ── Tela: criar / entrar / vs CPU ───────────────────────────────────────────
function renderCriarEntrar() {
  document.getElementById('dtMain').innerHTML = `
    <div class="dtcard">
      <div class="dtcard-title"><i class="bi bi-trophy me-1"></i>Starting5x5</div>
      <p class="dtcard-sub">Gire a roleta de escalações históricas e escolha 1 jogador por vez pra montar seu titular (PG/SG/SF/PF/C). Aposte moedas e desafie um amigo — ou a máquina.</p>
      <div class="dt-tabs">
        <div class="dt-tab dt-tab-destaque active" id="dtTabAleatorio" onclick="dtTrocarTab('aleatorio')">⚡ Aleatório</div>
        <div class="dt-tab" id="dtTabCriar" onclick="dtTrocarTab('criar')">Amigo</div>
        <div class="dt-tab" id="dtTabEntrar" onclick="dtTrocarTab('entrar')">Código</div>
        <div class="dt-tab" id="dtTabCpu" onclick="dtTrocarTab('cpu')">CPU 🤖</div>
      </div>
      <div id="dtTabConteudo"></div>
    </div>
    <div class="dtcard" id="dtRankingCard">
      <div class="dtcard-title"><i class="bi bi-award-fill me-1"></i>Ranking — confronto online</div>
      <div id="dtRankingBody"><p class="dt-empty">Carregando ranking...</p></div>
    </div>
    <div class="dtcard" id="dtRivalidadesCard">
      <div class="dtcard-title"><i class="bi bi-fire me-1"></i>Maiores rivalidades</div>
      <div id="dtRivalidadesBody"><p class="dt-empty">Carregando...</p></div>
    </div>`;
  // Veio de um link de convite (?codigo=XXXXXX)? Já cai direto na aba "Entrar com código" com ele preenchido.
  // Sem link, o modo aleatório é o padrão — é o jeito mais rápido de cair num confronto.
  const codigoConvite = new URLSearchParams(window.location.search).get('codigo');
  dtTrocarTab(codigoConvite ? 'entrar' : 'aleatorio');
  if (codigoConvite) {
    const input = document.getElementById('dtCodigo');
    if (input) input.value = codigoConvite.toUpperCase();
  }
  dtCarregarRanking();
}

async function dtCarregarRanking() {
  const body = document.getElementById('dtRankingBody');
  const rivBody = document.getElementById('dtRivalidadesBody');
  try {
    const r = await dtPost('ranking');
    if (!r.ok) return;
    if (body) {
      if (!r.ranking.length) {
        body.innerHTML = `<p class="dt-empty">Ninguém disputou um confronto online ainda — seja o primeiro!</p>`;
      } else {
        body.innerHTML = `<div class="dt-ranking-lista">${r.ranking.map((rk, i) => `
          <div class="dt-ranking-item">
            <span class="dt-ranking-pos">${i + 1}º</span>
            <span class="dt-ranking-nome">${dtLogoImg(rk.logo)}<span>${esc(rk.nome)}</span></span>
            <span class="dt-ranking-vd"><b style="color:var(--green)">${rk.vitorias}V</b> <span style="color:var(--text3)">/</span> <b style="color:var(--text2)">${rk.derrotas}D</b></span>
          </div>`).join('')}</div>`;
      }
    }
    if (rivBody) {
      if (!r.rivalidades.length) {
        rivBody.innerHTML = `<p class="dt-empty">Nenhum par de GMs se enfrentou mais de uma vez ainda.</p>`;
      } else {
        rivBody.innerHTML = `<div class="dt-ranking-lista">${r.rivalidades.map(rv => `
          <div class="dt-ranking-item">
            <span class="dt-ranking-nome" style="${rv.lider === 'a' ? 'font-weight:900;color:var(--text)' : ''}">${dtLogoImg(rv.logo_a)}<span>${esc(rv.nome_a)}</span></span>
            <span class="dt-ranking-vd"><b style="${rv.lider === 'a' ? 'color:var(--green)' : ''}">${rv.vitorias_a}</b> × <b style="${rv.lider === 'b' ? 'color:var(--green)' : ''}">${rv.vitorias_b}</b></span>
            <span class="dt-ranking-nome" style="justify-content:flex-end;${rv.lider === 'b' ? 'font-weight:900;color:var(--text)' : ''}"><span>${esc(rv.nome_b)}</span>${dtLogoImg(rv.logo_b)}</span>
          </div>`).join('')}</div>`;
      }
    }
  } catch (e) {
    if (body) body.innerHTML = `<p class="dt-empty">Não deu pra carregar o ranking agora.</p>`;
    if (rivBody) rivBody.innerHTML = `<p class="dt-empty">Não deu pra carregar agora.</p>`;
  }
}

function dtTrocarTab(tab) {
  document.getElementById('dtTabCriar').classList.toggle('active', tab === 'criar');
  document.getElementById('dtTabEntrar').classList.toggle('active', tab === 'entrar');
  document.getElementById('dtTabAleatorio').classList.toggle('active', tab === 'aleatorio');
  document.getElementById('dtTabCpu').classList.toggle('active', tab === 'cpu');
  const c = document.getElementById('dtTabConteudo');
  if (tab === 'criar') {
    c.innerHTML = `
      <div class="field">
        <label>Aposta (1 a 100 moedas)</label>
        <input type="number" id="dtAposta" min="1" max="100" value="20">
        <p class="field-hint">Debitada na hora — devolvida se ninguém entrar em 24h. Depois de criado, dá pra copiar um link de convite.</p>
      </div>
      <button class="btn-dt" id="dtBtnCriar" onclick="dtCriarDuelo()"><i class="bi bi-plus-circle me-2"></i>Criar duelo</button>`;
  } else if (tab === 'entrar') {
    c.innerHTML = `
      <div class="field">
        <label>Código do duelo</label>
        <input type="text" id="dtCodigo" maxlength="6" placeholder="Ex: A7K2QX" style="text-transform:uppercase;letter-spacing:3px;text-align:center;font-size:18px">
      </div>
      <button class="btn-dt" id="dtBtnEntrar" onclick="dtEntrarDuelo()"><i class="bi bi-box-arrow-in-right me-2"></i>Entrar no duelo</button>`;
  } else if (tab === 'aleatorio') {
    c.innerHTML = `
      <div class="field">
        <p class="field-hint" style="margin-top:0">Aposta fixa de <strong>${DT_APOSTA_ALEATORIA} moedas</strong>, sem link — clique em jogar e, assim que outra pessoa também clicar no modo aleatório, o confronto começa na hora.</p>
      </div>
      <button class="btn-dt" id="dtBtnAleatorio" onclick="dtJogarAleatorio()"><i class="bi bi-shuffle me-2"></i>Jogar (${DT_APOSTA_ALEATORIA} moedas)</button>`;
  } else {
    c.innerHTML = `
      <div class="field">
        <label>Aposta (1 a 50 moedas)</label>
        <input type="number" id="dtApostaCpu" min="1" max="50" value="20">
        <p class="field-hint">A máquina joga estratégico — escolhe sempre o melhor jogador disponível e usa o reroll dela quando o time sorteado é fraco. Vence, leva o dobro; perde, fica sem nada.</p>
      </div>
      <button class="btn-dt" id="dtBtnCpu" onclick="dtCriarVsCpu()"><i class="bi bi-cpu me-2"></i>Desafiar a máquina</button>`;
  }
}

// Executa uma ação de botão com segurança: trava o botão enquanto a requisição está em voo e
// SEMPRE destrava no final (mesmo se a rede cair no meio). Sem o finally, uma falha de rede
// deixava o botão disabled pra sempre — o poll não consertava, porque o guard anti-flicker não
// re-renderiza quando o estado do servidor não mudou. Era a "travada" ao clicar.
async function dtAcaoBotao(btnId, acao, params = {}) {
  const btn = document.getElementById(btnId);
  if (btn && btn.disabled) return;
  if (btn) btn.disabled = true;
  try {
    const r = await dtPost(acao, params);
    if (!r.ok) { await dtAlerta(r.msg); return; }
    await atualizar(true);
  } catch (e) {
    await dtAlerta('Falha de conexão. Tente de novo.');
  } finally {
    const atual = document.getElementById(btnId);
    if (atual) atual.disabled = false;
  }
}

async function dtCriarDuelo() {
  const aposta = parseInt(document.getElementById('dtAposta').value, 10);
  await dtAcaoBotao('dtBtnCriar', 'criar', { aposta });
}

async function dtEntrarDuelo() {
  const codigo = document.getElementById('dtCodigo').value.trim().toUpperCase();
  if (!codigo) return;
  await dtAcaoBotao('dtBtnEntrar', 'entrar', { codigo });
}

async function dtCriarVsCpu() {
  const aposta = parseInt(document.getElementById('dtApostaCpu').value, 10);
  await dtAcaoBotao('dtBtnCpu', 'criar_vs_cpu', { aposta });
}

async function dtJogarAleatorio() {
  await dtAcaoBotao('dtBtnAleatorio', 'jogar_aleatorio');
}

// ── Tela: aguardando oponente ────────────────────────────────────────────────
function renderAguardando(duelo) {
  // Modo aleatório não tem código/link pra compartilhar — casa sozinho com quem
  // também estiver esperando, então a tela só mostra "procurando".
  if (duelo.modo === 'aleatorio') {
    document.getElementById('dtMain').innerHTML = `
      <div class="dtcard">
        <div class="dtcard-title"><i class="bi bi-shuffle me-1"></i>Procurando oponente...</div>
        <div class="dt-spinner"></div>
        <p class="dtcard-sub" style="text-align:center;margin-bottom:4px">Aposta: <strong>${duelo.aposta} moedas</strong></p>
        <p class="dt-empty">Assim que outra pessoa clicar em "Jogar" no modo aleatório, o confronto começa na hora.</p>
        <button class="btn-dt-ghost" onclick="dtCancelar()"><i class="bi bi-x-circle me-1"></i>Cancelar e receber a aposta de volta</button>
      </div>`;
    return;
  }
  document.getElementById('dtMain').innerHTML = `
    <div class="dtcard">
      <div class="dtcard-title"><i class="bi bi-hourglass-split me-1"></i>Aguardando oponente</div>
      <div class="dt-codigo-box">
        <div class="dt-codigo-valor">${esc(duelo.codigo)}</div>
        <div class="dt-codigo-label">Compartilhe esse código</div>
      </div>
      <button class="btn-dt" id="dtBtnCopiarLink" onclick="dtCopiarLink('${duelo.codigo}')"><i class="bi bi-link-45deg me-2"></i>Copiar link do convite</button>
      <p class="dtcard-sub" style="text-align:center;margin-bottom:4px">Aposta: <strong>${duelo.aposta} moedas</strong></p>
      <div class="dt-spinner"></div>
      <p class="dt-empty">Assim que alguém entrar com o código, a tela avança sozinha.</p>
      <button class="btn-dt-ghost" onclick="dtCancelar()"><i class="bi bi-x-circle me-1"></i>Cancelar e receber a aposta de volta</button>
    </div>`;
}

function dtLinkConvite(codigo) {
  const url = new URL(window.location.href);
  url.search = '';
  url.searchParams.set('game', 'dreamteam');
  url.searchParams.set('codigo', codigo);
  return url.toString();
}

async function dtCopiarLink(codigo) {
  const link = dtLinkConvite(codigo);
  const btn = document.getElementById('dtBtnCopiarLink');
  try {
    await navigator.clipboard.writeText(link);
  } catch (e) {
    // Sem permissão de clipboard (ex: http sem contexto seguro) — seleciona um campo temporário como fallback.
    const tmp = document.createElement('textarea');
    tmp.value = link;
    tmp.style.position = 'fixed';
    tmp.style.opacity = '0';
    document.body.appendChild(tmp);
    tmp.select();
    try { document.execCommand('copy'); } catch (e2) { /* ignora — pior caso, usuário copia manualmente */ }
    document.body.removeChild(tmp);
  }
  if (btn) {
    const original = btn.innerHTML;
    btn.innerHTML = '<i class="bi bi-check-lg me-2"></i>Link copiado!';
    setTimeout(() => { if (btn.isConnected) btn.innerHTML = original; }, 2000);
  }
}

async function dtCancelar() {
  if (!(await dtConfirmar('Cancelar o duelo e receber a aposta de volta?'))) return;
  const r = await dtPost('cancelar');
  if (!r.ok) { await dtAlerta(r.msg); return; }
  await atualizar(true);
}

// Contra a máquina continua "Você" (soa mais natural); em PvP usa o nome de verdade
// de quem está vendo a tela, pra ficar tipo um confronto real entre os dois GMs.
// Time de franquia (nome + logo) tem prioridade sobre o nome pessoal, em qualquer confronto —
// contra a máquina sem time vira "Você"; em PvP sem time vira o nome pessoal do GM.
function dtNomeMeu(duelo) {
  if (duelo.meu_time_nome) return duelo.meu_time_nome;
  return duelo.eh_pvp ? (duelo.meu_nome || 'Você') : 'Você';
}
function dtLogoMeu(duelo) {
  return duelo.meu_time_logo || null;
}
function dtNomeOponente(duelo) {
  return duelo.oponente_time_nome || duelo.oponente_nome;
}
function dtLogoOponente(duelo) {
  return duelo.oponente_time_logo || null;
}
function dtLogoImg(url) {
  return url ? `<img class="dt-time-logo" src="${esc(url)}" alt="">` : '';
}

// Faixas de OVR — do neon (craque) até o amarelo apagado (coadjuvante).
function dtCorOvr(ovr) {
  ovr = Number(ovr);
  if (ovr >= 95) return '#39ff14';
  if (ovr >= 90) return '#22c55e';
  if (ovr >= 85) return '#a3e635';
  if (ovr >= 80) return '#f59e0b';
  return '#c2660d';
}

// ── Tela: draft ao vivo ──────────────────────────────────────────────────────
// podeReposicionar: só true pro "Seu time" durante sua vez — clicar num jogador com
// posição dupla e a outra vaga aberta move ele pra lá, liberando a posição atual.
function dtRenderRoster(nome, roster, total, podeReposicionar = false, logo = null) {
  const slots = POSICOES.map(pos => {
    const j = roster[pos];
    if (!j) return `<div class="dt-roster-slot"><span class="dt-rs-pos">${pos}</span></div>`;
    const alvo = (podeReposicionar && Array.isArray(j.pos)) ? j.pos.find(p => p !== pos && !roster[p]) : null;
    const clique = alvo ? ` onclick="dtReposicionar('${pos}','${alvo}')" title="Mover para ${alvo}"` : '';
    return `<div class="dt-roster-slot preenchido${alvo ? ' movivel' : ''}"${clique}><span class="dt-rs-pos">${pos}</span><span class="dt-rs-nome">${esc(j.nome.split(' ').slice(-1)[0])}</span><span class="dt-rs-ovr" style="color:${dtCorOvr(j.ovr)}">${j.ovr}</span></div>`;
  }).join('');
  return `
    <div class="dtcard" style="margin-bottom:10px">
      <div class="dt-roster-head">
        <span class="dt-roster-nome">${dtLogoImg(logo)}<span>${esc(nome)}</span></span>
        <span class="dt-roster-total">${total} OVR</span>
      </div>
      <div class="dt-roster-grid">${slots}</div>
    </div>`;
}

async function dtReposicionar(de, para) {
  if (processando) return;
  processando = true;
  try {
    const r = await dtPost('reposicionar_jogador', { de, para });
    if (!r.ok) { await dtAlerta(r.msg); return; }
    await atualizar(true);
  } catch (e) {
    // silencioso
  } finally {
    // Só libera DEPOIS que a tela já atualizou — senão um clique durante a janela entre a resposta
    // chegar e o re-render acontecer podia disparar outra ação numa tela que já tava desatualizada
    // (ex: clicar de novo bem na hora que o duelo virava "simulado" e o alerta pipocava sem motivo).
    processando = false;
  }
}

function dtSomaRosterJs(roster) {
  return POSICOES.reduce((s, p) => s + (roster[p] ? Number(roster[p].ovr) : 0), 0);
}

function renderDraft(duelo) {
  const meuTotal = dtSomaRosterJs(duelo.meu_roster);
  const oponenteTotal = dtSomaRosterJs(duelo.oponente_roster);

  let html = dtRenderRoster(dtNomeMeu(duelo), duelo.meu_roster, meuTotal, duelo.minha_vez, dtLogoMeu(duelo));
  html += dtRenderRoster(dtNomeOponente(duelo), duelo.oponente_roster, oponenteTotal, false, dtLogoOponente(duelo));

  html += `<div class="dt-turno-banner ${duelo.minha_vez ? 'minha-vez' : 'vez-oponente'}">
    ${duelo.minha_vez ? '<i class="bi bi-hand-index-thumb"></i> Sua vez — escolha um jogador' : `Vez de ${esc(dtNomeOponente(duelo))}...`}
  </div>`;

  if (duelo.minha_vez && !duelo.time_sorteado) {
    html += `<div class="dtcard" style="text-align:center">
      <div class="dtcard-title" style="margin-bottom:2px">Sua vez de montar o time</div>
      <p class="dtcard-sub" style="margin-bottom:14px">Gire a roleta pra ver qual escalação histórica aparece — daí escolhe 1 jogador dela.</p>
      <button class="btn-dt" id="dtBtnSortear" onclick="dtSortearTime()"><i class="bi bi-shuffle me-2"></i>Sortear Time</button>
    </div>`;
  }

  if (duelo.time_sorteado) {
    const t = duelo.time_sorteado;
    const vagas = POSICOES.filter(p => !duelo.meu_roster[p]);
    const jaTenho = new Set(Object.values(duelo.meu_roster).filter(Boolean).map(j => j.nome));
    html += `<div class="dtcard">
      <div class="dt-time-nome">${esc(t.nome)}</div>
      <div class="dt-time-ano">Titular sorteado</div>
      <div class="dt-jogadores-grid" id="dtJogadoresGrid">
        ${t.jogadores.map((j, i) => {
          const jaNoTime = jaTenho.has(j.nome);
          const posDisponiveis = jaNoTime ? [] : j.pos.filter(p => vagas.includes(p));
          const clicavel = duelo.minha_vez && posDisponiveis.length > 0;
          return `<div class="dt-jogador-card ${clicavel ? 'clicavel' : 'desabilitado'}" id="dtJog${i}" ${clicavel ? `onclick="dtCliqueJogador(${i})"` : ''}>
            <span class="dt-jogador-nome">${esc(j.nome)}${jaNoTime ? ' <small style="color:var(--text3)">(já no seu time)</small>' : ''}</span>
            <span class="dt-jogador-pos">${j.pos.map(p => `<span class="dt-pos-badge">${p}</span>`).join('')}</span>
            <span class="dt-jogador-ovr" style="color:${dtCorOvr(j.ovr)}">${j.ovr}</span>
          </div>`;
        }).join('')}
      </div>
      <button class="btn-dt-amber" id="dtBtnGirar" onclick="dtGirarDeNovo()" ${(duelo.minha_vez && duelo.reroll_disponivel) ? '' : 'disabled'}>
        <i class="bi bi-arrow-repeat me-1"></i>${duelo.reroll_disponivel ? 'Girar de novo (1x)' : 'Reroll já usado nessa rodada'}
      </button>
    </div>`;
  }

  document.getElementById('dtMain').innerHTML = html;
  window.__dtTimeAtual = duelo.time_sorteado;
  window.__dtVagas = POSICOES.filter(p => !duelo.meu_roster[p]);
}

function dtCliqueJogador(idx) {
  const j = window.__dtTimeAtual.jogadores[idx];
  const posDisponiveis = j.pos.filter(p => window.__dtVagas.includes(p));
  if (posDisponiveis.length === 1) {
    dtEscolherJogador(j.nome, posDisponiveis[0]);
    return;
  }
  // Mais de uma posição elegível aberta — pergunta qual preencher.
  const card = document.getElementById(`dtJog${idx}`);
  card.innerHTML += `<div class="dt-escolha-pos">${posDisponiveis.map(p => `<button onclick="event.stopPropagation();dtEscolherJogador('${j.nome.replace(/'/g, "\\'")}','${p}')">${p}</button>`).join('')}</div>`;
}

async function dtEscolherJogador(nome, posicao) {
  if (processando) return;
  processando = true;
  try {
    const r = await dtPost('escolher_jogador', { jogador: nome, posicao });
    if (!r.ok) { await dtAlerta(r.msg); return; }
    await atualizar();
  } catch (e) {
    // silencioso
  } finally {
    processando = false;
  }
}

async function dtGirarDeNovo() {
  await dtAcaoBotao('dtBtnGirar', 'girar_de_novo');
}

async function dtSortearTime() {
  await dtAcaoBotao('dtBtnSortear', 'sortear_time');
}

// ── Tela: resultado (simcast por quartos → boxscore final) ──────────────────
function renderResultado(duelo) {
  if (duelo.id === resultadoFinalId) { renderResultadoFinal(duelo); return; }
  if (duelo.id === resultadoEmAnimacaoId) return; // simcast já rodando — ignora poll repetido
  resultadoEmAnimacaoId = duelo.id;
  dtRodarSimcast(duelo);
}

async function dtRodarSimcast(duelo) {
  const r = duelo.resultado;
  const nomeEu = esc(dtNomeMeu(duelo));
  const nomeOp = esc(dtNomeOponente(duelo));
  document.getElementById('dtMain').innerHTML = `
    <div class="dtcard">
      <div class="dtcard-title"><i class="bi bi-broadcast me-1"></i>Simulando o confronto...</div>
      <div class="dt-placar">
        <div class="dt-placar-lado"><div class="dt-placar-nome">${dtLogoImg(dtLogoMeu(duelo))}<span>${nomeEu}</span></div><div class="dt-placar-num" id="dtSimA">0</div></div>
        <div class="dt-placar-x">×</div>
        <div class="dt-placar-lado"><div class="dt-placar-nome">${dtLogoImg(dtLogoOponente(duelo))}<span>${nomeOp}</span></div><div class="dt-placar-num" id="dtSimB">0</div></div>
      </div>
      <div class="dt-simcast-quartos" id="dtSimStatus"></div>
      <div id="dtSimTabela"></div>
    </div>`;
  const numA = document.getElementById('dtSimA');
  const numB = document.getElementById('dtSimB');
  const statusEl = document.getElementById('dtSimStatus');
  const tabelaEl = document.getElementById('dtSimTabela');
  tabelaEl.innerHTML = dtTabelaQuartos(duelo, 0);

  let cumA = 0, cumB = 0;
  await new Promise(res => setTimeout(res, 800)); // pausa inicial antes do 1º quarto, dá suspense
  for (let q = 0; q < 4; q++) {
    statusEl.textContent = `${q + 1}º quarto em andamento...`;
    await new Promise(res => setTimeout(res, 1400));
    const qa = duelo.meu_lado === 'a' ? r.quartos[q].a : r.quartos[q].b;
    const qb = duelo.meu_lado === 'a' ? r.quartos[q].b : r.quartos[q].a;
    cumA += qa; cumB += qb;
    numA.textContent = cumA;
    numB.textContent = cumB;
    // Destaca em verde quem tá na frente NAQUELE momento — já a partir do 1º quarto, e
    // atualiza a cada quarto, então acompanha virada em vez de só marcar o vencedor no final.
    numA.classList.toggle('vencedor', cumA > cumB);
    numB.classList.toggle('vencedor', cumB > cumA);
    tabelaEl.innerHTML = dtTabelaQuartos(duelo, q + 1);
    statusEl.textContent = cumA === cumB
      ? `Fim do ${q + 1}º quarto — empate!`
      : `Fim do ${q + 1}º quarto — ${cumA > cumB ? nomeEu : nomeOp} na frente`;
  }
  await new Promise(res => setTimeout(res, 950));
  resultadoFinalId = duelo.id;
  localStorage.setItem('dt_resultado_final_id', String(duelo.id));
  renderResultadoFinal(duelo);
}

// Tabela Q1..Q4 + total. `revelados` limita quantos quartos aparecem (o simcast vai revelando
// um por vez); o quarto vencido por cada lado fica destacado, e o total do líder sai em verde.
function dtTabelaQuartos(duelo, revelados = 4) {
  const r = duelo.resultado;
  let totA = 0, totB = 0;
  const celA = [], celB = [];
  for (let q = 0; q < 4; q++) {
    if (q < revelados) {
      const qa = duelo.meu_lado === 'a' ? r.quartos[q].a : r.quartos[q].b;
      const qb = duelo.meu_lado === 'a' ? r.quartos[q].b : r.quartos[q].a;
      totA += qa; totB += qb;
      celA.push(`<td class="${qa > qb ? 'dt-qt-venceu' : ''}">${qa}</td>`);
      celB.push(`<td class="${qb > qa ? 'dt-qt-venceu' : ''}">${qb}</td>`);
    } else {
      celA.push('<td class="dt-qt-vazio">–</td>');
      celB.push('<td class="dt-qt-vazio">–</td>');
    }
  }
  return `<table class="dt-qt-tabela">
    <thead><tr><th>Time</th><th>Q1</th><th>Q2</th><th>Q3</th><th>Q4</th><th class="dt-qt-col-total">Total</th></tr></thead>
    <tbody>
      <tr>
        <td class="dt-qt-time"><span class="dt-qt-time-wrap">${dtLogoImg(dtLogoMeu(duelo))}<span>${esc(dtNomeMeu(duelo))}</span></span></td>
        ${celA.join('')}
        <td class="dt-qt-total ${totA > totB ? 'liderando' : ''}">${totA}</td>
      </tr>
      <tr>
        <td class="dt-qt-time"><span class="dt-qt-time-wrap">${dtLogoImg(dtLogoOponente(duelo))}<span>${esc(dtNomeOponente(duelo))}</span></span></td>
        ${celB.join('')}
        <td class="dt-qt-total ${totB > totA ? 'liderando' : ''}">${totB}</td>
      </tr>
    </tbody>
  </table>`;
}

function dtRenderBoxscore(nome, box) {
  const linhas = box.map(j => `
    <tr>
      <td class="dt-box-pos">${j.pos}</td>
      <td class="dt-box-nome">${esc(j.nome)}</td>
      <td>${j.pts}</td>
      <td>${j.reb}</td>
      <td>${j.ast}</td>
    </tr>`).join('');
  return `<div class="dtcard">
    <div class="dtcard-title">${esc(nome)}</div>
    <div style="overflow-x:auto">
      <table class="dt-box-table">
        <thead><tr><th></th><th style="text-align:left">Jogador</th><th>PTS</th><th>REB</th><th>AST</th></tr></thead>
        <tbody>${linhas}</tbody>
      </table>
    </div>
  </div>`;
}

function renderResultadoFinal(duelo) {
  const r = duelo.resultado;
  const nomeEuRaw = dtNomeMeu(duelo);
  const nomeOpRaw = dtNomeOponente(duelo);
  const nomeEu = esc(nomeEuRaw);
  const nomeOp = esc(nomeOpRaw);
  const meuPlacar = duelo.meu_lado === 'a' ? r.placar_a : r.placar_b;
  const oponentePlacar = duelo.meu_lado === 'a' ? r.placar_b : r.placar_a;
  const meuBox = duelo.meu_lado === 'a' ? r.boxscore_a : r.boxscore_b;
  const oponenteBox = duelo.meu_lado === 'a' ? r.boxscore_b : r.boxscore_a;

  let html = `<button class="btn-dt" onclick="dtNovoDuelo(${duelo.id})" style="margin-top:0;margin-bottom:14px"><i class="bi bi-arrow-repeat me-2"></i>Jogar de novo</button>`;
  html += `<div class="dtcard">
    <div class="dt-resultado-msg ${duelo.eu_venci ? 'venceu' : 'perdeu'}">
      ${duelo.eu_venci ? `🏆 Você venceu! +${duelo.aposta * 2} moedas` : `Você perdeu essa. -${duelo.aposta} moedas`}
    </div>
    ${r.zebra ? `<div class="dt-zebra-badge">🦓 Zebra! O time mais fraco levou essa.</div>` : ''}
    <div class="dt-placar">
      <div class="dt-placar-lado">
        <div class="dt-placar-nome">${dtLogoImg(dtLogoMeu(duelo))}<span>${nomeEu}</span></div>
        <div class="dt-placar-num ${duelo.eu_venci ? 'vencedor' : ''}">${meuPlacar}</div>
      </div>
      <div class="dt-placar-x">×</div>
      <div class="dt-placar-lado">
        <div class="dt-placar-nome">${dtLogoImg(dtLogoOponente(duelo))}<span>${nomeOp}</span></div>
        <div class="dt-placar-num ${!duelo.eu_venci ? 'vencedor' : ''}">${oponentePlacar}</div>
      </div>
    </div>
    ${dtTabelaQuartos(duelo)}
    <div class="dtcard-title" style="margin-top:16px">Destaques do confronto</div>
    ${r.destaques.map(d => {
      const souEu = d.lado === duelo.meu_lado;
      return `<div class="dt-destaque">
        <div class="dt-destaque-pts">${d.pontos}</div>
        <div class="dt-destaque-txt"><b>${esc(d.nome)}</b> (${souEu ? nomeEu : nomeOp}) — ${esc(d.frase)}.</div>
      </div>`;
    }).join('')}
  </div>`;
  html += dtRenderBoxscore(`Boxscore — ${nomeEuRaw}`, meuBox);
  html += dtRenderBoxscore(`Boxscore — ${nomeOpRaw}`, oponenteBox);
  html += dtRenderRoster(nomeEuRaw, duelo.meu_roster, dtSomaRosterJs(duelo.meu_roster), false, dtLogoMeu(duelo));
  html += dtRenderRoster(nomeOpRaw, duelo.oponente_roster, dtSomaRosterJs(duelo.oponente_roster), false, dtLogoOponente(duelo));
  document.getElementById('dtMain').innerHTML = html;
}

function dtNovoDuelo(dueloId) {
  dueloDispensadoId = dueloId;
  localStorage.setItem('dt_dispensado_id', String(dueloId));
  ESTADO_INICIAL = null;
  renderCriarEntrar();
}

// ── Orquestração ─────────────────────────────────────────────────────────────
function renderTela(duelo) {
  if (!duelo) { renderCriarEntrar(); return; }
  if (duelo.status === 'simulado' && duelo.id === dueloDispensadoId) { renderCriarEntrar(); return; }
  if (duelo.status === 'aguardando') { renderAguardando(duelo); return; }
  if (duelo.status === 'draft') { renderDraft(duelo); return; }
  if (duelo.status === 'simulado') { renderResultado(duelo); return; }
  renderCriarEntrar();
}

// Só re-renderiza quando o estado realmente muda — sem isso, cada poll de 3s reconstruía a
// tela inteira (mesmo parado esperando o oponente), dando aquele flicker/"atualizando toda hora".
let ultimoEstadoHash = JSON.stringify(ESTADO_INICIAL);

// forcar=true ignora o guard de hash: usado logo depois de uma ação do próprio jogador, pra
// garantir que a tela seja reconstruída mesmo quando o estado do servidor não mudou (ex: o
// "girar de novo" pode sortear o MESMO time, então o hash fica igual e sem o forcar a tela
// ficaria congelada com o botão travado, parecendo que o botão não funciona).
async function atualizar(forcar = false) {
  if (processando && !forcar) return;
  try {
    const r = await dtPost('estado');
    if (!r.ok) return;
    document.getElementById('chipSaldo').textContent = r.pontos;
    const hash = JSON.stringify(r.duelo);
    if (hash === ultimoEstadoHash && !forcar) return;
    ultimoEstadoHash = hash;
    renderTela(r.duelo);
  } catch (e) { /* silencioso — próximo poll tenta de novo */ }
}

renderTela(ESTADO_INICIAL);
// Arrow em vez de passar `atualizar` direto: o setInterval não deve empurrar argumento nenhum
// pro parâmetro `forcar`.
setInterval(() => atualizar(), 3000);
</script>

</body>
</html>
