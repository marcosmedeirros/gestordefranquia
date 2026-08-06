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
// Aviso de desafio direto (push + WhatsApp). O carregador dos games não puxa o
// backend do site, então o require é local — push.php já traz helpers e whatsapp.
require_once __DIR__ . '/../../backend/push.php';

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
// Desafio direto a uma pessoa específica: se ela não responder, a aposta volta.
// Prazo mais curto que o da sala aberta — aqui alguém está segurando moeda
// esperando resposta de uma pessoa só.
const DT_CONVITE_TIMEOUT_H = 6;

// Copa: mata-mata de 4 ou 8 jogadores reais, entrada única e o campeão leva o
// pote inteiro. Diferente do duelo, todo mundo monta o time ao mesmo tempo —
// esperar 8 pessoas se revezarem em 40 turnos seria insuportável.
const DT_COPA_TAMANHOS = [4, 8];
const DT_COPA_APOSTA_MIN = 1;
const DT_COPA_APOSTA_MAX = 100;
// Prazo pra montar o time depois que a copa lota. Quem não terminar tem o
// elenco completado pelo sistema: um ausente não pode travar a copa dos outros.
const DT_COPA_DRAFT_MIN = 5;
const DT_COPA_LOBBY_TIMEOUT_H = 6;

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

    // Reroll: um por jogador no DRAFT INTEIRO, não um por rodada. Precisa de uma
    // coluna por lado — a antiga reroll_disponivel era única e zerava a cada
    // turno, o que na prática dava um giro extra em toda rodada.
    try {
        if (!$pdo->query("SHOW COLUMNS FROM dreamteam_duelos LIKE 'reroll_criador'")->fetch()) {
            $pdo->exec("ALTER TABLE dreamteam_duelos
                ADD COLUMN reroll_criador TINYINT(1) NOT NULL DEFAULT 1,
                ADD COLUMN reroll_desafiado TINYINT(1) NOT NULL DEFAULT 1");
        }
    } catch (PDOException $e) {
        error_log('[dreamteam] migração reroll: ' . $e->getMessage());
    }

    // Revanche: cada lado marca a sua intenção; quando os dois marcam, nasce um
    // duelo novo com a mesma aposta e revanche_duelo_id aponta pra ele (é o que
    // impede a mesma partida de gerar duas revanches).
    try {
        if (!$pdo->query("SHOW COLUMNS FROM dreamteam_duelos LIKE 'revanche_criador'")->fetch()) {
            $pdo->exec("ALTER TABLE dreamteam_duelos
                ADD COLUMN revanche_criador TINYINT(1) NOT NULL DEFAULT 0,
                ADD COLUMN revanche_desafiado TINYINT(1) NOT NULL DEFAULT 0,
                ADD COLUMN revanche_duelo_id INT NULL");
        }
    } catch (PDOException $e) {
        error_log('[dreamteam] migração revanche: ' . $e->getMessage());
    }

    // A chave inteira (todos os confrontos, com quartos e boxscore) é gravada de
    // uma vez em `chave` quando o draft fecha. Guardar o resultado pronto em vez
    // de simular sob demanda é o que faz recarregar a página não re-simular nada
    // e todo mundo enxergar exatamente a mesma copa.
    $pdo->exec("CREATE TABLE IF NOT EXISTS dreamteam_copas (
        id INT AUTO_INCREMENT PRIMARY KEY,
        codigo VARCHAR(8) NOT NULL,
        id_criador INT NOT NULL,
        tamanho TINYINT NOT NULL,
        aposta INT NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'aguardando',
        chave MEDIUMTEXT NULL,
        id_campeao INT NULL,
        criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        draft_ate DATETIME NULL,
        concluido_em DATETIME NULL,
        UNIQUE KEY uk_dtc_codigo (codigo),
        INDEX idx_dtc_status (status),
        INDEX idx_dtc_criador (id_criador)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS dreamteam_copa_jogadores (
        id INT AUTO_INCREMENT PRIMARY KEY,
        copa_id INT NOT NULL,
        user_id INT NOT NULL,
        roster TEXT NULL,
        time_sorteado_id VARCHAR(20) NULL,
        reroll_disponivel TINYINT(1) NOT NULL DEFAULT 1,
        pronto TINYINT(1) NOT NULL DEFAULT 0,
        seed INT NULL,
        entrou_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_dtcj_copa_user (copa_id, user_id),
        INDEX idx_dtcj_copa (copa_id),
        INDEX idx_dtcj_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
dtGarantirTabelas($pdo);

function dtGerarCodigo(PDO $pdo): string
{
    $chars = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    for ($tentativa = 0; $tentativa < 20; $tentativa++) {
        $codigo = '';
        for ($i = 0; $i < 6; $i++) $codigo .= $chars[random_int(0, strlen($chars) - 1)];
        // Único entre duelos E copas: o link de convite é o mesmo formato pros dois,
        // então um código repetido deixaria "entrar" ambíguo.
        $st = $pdo->prepare('SELECT 1 FROM dreamteam_duelos WHERE codigo = ?
                             UNION ALL SELECT 1 FROM dreamteam_copas WHERE codigo = ?');
        $st->execute([$codigo, $codigo]);
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

    // Peso da diferença de elenco. A curva já foi mais suave (5.0, com piso/teto em
    // 8/92) e dava zebra demais: com 3 a 5 de vantagem média por jogador o time
    // pior ainda vencia ~31% das vezes, e mesmo com 5+ de vantagem vencia ~19%.
    //
    // Medido em 40 mil confrontos gerados pela própria roleta, esta curva deixa:
    // 0-1 de diferença ~46% (jogo parelho segue moeda ao ar, como tem que ser),
    // 2-3 ~33%, 3-5 ~22% e 5+ ~7%. O piso de 5% é o que sobra de zebra — o elenco
    // muito superior perde de vez em quando, mas vira exceção de verdade.
    $chanceA = max(5, min(95, 50 + $diffMedio * 7.5));
    $aGanha = random_int(1, 1000) <= (int)round($chanceA * 10);

    $vencedorLado = $aGanha ? 'a' : 'b';
    $favorito = $diffMedio >= 0 ? 'a' : 'b';
    $forca = abs($diffMedio);
    // Zebra: o azarão venceu um confronto que era claramente pra perder. Só conta quando a
    // diferença de elenco é real (>= 3 de OVR médio por jogador) — abaixo disso os times estão
    // equilibrados e qualquer um ganhar é normal, não zebra.
    //
    // Serve só pra apertar a margem (logo abaixo): a tela NÃO mostra que foi zebra.
    // Havia um selo "🦓 Zebra! O time mais fraco levou essa" no resultado e ele foi
    // retirado de propósito — não colocar de volta.
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
    // A máquina joga com a MESMA regra do humano: um giro extra no duelo inteiro.
    // Antes ela regirava toda rodada em que o melhor disponível fosse fraco, o
    // que virou vantagem depois que o reroll humano passou a ser um só por duelo.
    $usouReroll = false;
    if ((int)($atual['reroll_desafiado'] ?? 0) === 1 && (!$melhor || $melhor['jogador']['ovr'] < 82)) {
        $novoTime = dtSortearTimeValido($roster);
        $melhorNovo = dtMelhorEscolha($novoTime, $roster);
        if ($melhorNovo && (!$melhor || $melhorNovo['jogador']['ovr'] > $melhor['jogador']['ovr'])) {
            $melhor = $melhorNovo;
        }
        $usouReroll = true;
    }
    if (!$melhor) return; // nunca deveria acontecer — dtSortearTimeValido garante >=1 opção

    $roster[$melhor['pos']] = ['nome' => $melhor['jogador']['nome'], 'ovr' => $melhor['jogador']['ovr'], 'pos' => $melhor['jogador']['pos']];
    $pdo->prepare('UPDATE dreamteam_duelos SET roster_desafiado = ? WHERE id = ?')->execute([json_encode($roster), $dueloId]);
    if ($usouReroll) {
        $pdo->prepare('UPDATE dreamteam_duelos SET reroll_desafiado = 0 WHERE id = ?')->execute([$dueloId]);
    }

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
        $pdo->prepare("UPDATE dreamteam_duelos SET turno = 'criador', time_sorteado_id = NULL WHERE id = ?")
            ->execute([$dueloId]);
    }
}

function dtDueloAtivo(PDO $pdo, int $userId): ?array
{
    // A ordem importa: um convite recebido não pode passar na frente de um duelo
    // que a pessoa já está jogando. Por isso a prioridade é explícita (draft >
    // aguardando > convite > simulado) em vez de só "não-terminado primeiro".
    $st = $pdo->prepare("
        SELECT * FROM dreamteam_duelos
        WHERE (id_criador = ? OR id_desafiado = ?)
          AND status IN ('aguardando', 'draft', 'convite', 'simulado')
        ORDER BY FIELD(status, 'draft', 'aguardando', 'convite', 'simulado'), id DESC
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
        WHERE (id_criador = ? OR id_desafiado = ?) AND status IN ('aguardando', 'draft', 'convite')
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

    // Desafio direto que ninguém respondeu: devolve a aposta de quem desafiou.
    $st = $pdo->prepare("SELECT id, id_criador, aposta FROM dreamteam_duelos
                         WHERE status = 'convite' AND criado_em < DATE_SUB(NOW(), INTERVAL ? HOUR)");
    $st->execute([DT_CONVITE_TIMEOUT_H]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $d) {
        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE games_usuarios SET pontos = pontos + ? WHERE id = ?')
                ->execute([(int)$d['aposta'], (int)$d['id_criador']]);
            $pdo->prepare("UPDATE dreamteam_duelos SET status = 'expirado' WHERE id = ?")->execute([$d['id']]);
            $pdo->commit();
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); }
    }

    // Copa que nunca encheu: devolve a entrada de quem estava esperando.
    $st = $pdo->prepare("SELECT * FROM dreamteam_copas WHERE status = 'aguardando' AND criado_em < DATE_SUB(NOW(), INTERVAL ? HOUR)");
    $st->execute([DT_COPA_LOBBY_TIMEOUT_H]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $pdo->beginTransaction();
        try {
            dtCopaReembolsar($pdo, $c, 'expirada');
            $pdo->commit();
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); }
    }

    // Copa cujo prazo de montagem venceu. Normalmente quem está jogando dispara isso
    // pelo polling; esta varredura cobre o caso de todo mundo ter fechado a página —
    // senão a copa (e as moedas dentro dela) ficaria parada pra sempre.
    $st = $pdo->query("SELECT id FROM dreamteam_copas WHERE status = 'draft' AND draft_ate IS NOT NULL AND draft_ate <= NOW()");
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $copaId) {
        dtCopaProcessar($pdo, (int)$copaId);
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
            'nome' => dtNomeExibicaoCurto($pdo, (int)$r['user_id']),
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

/** Versão curta (só o nome do time, sem a cidade) — usada onde o espaço é apertado. */
function dtNomeExibicaoCurto(PDO $pdo, int $userId): string
{
    $time = dtTimeDoUsuario($pdo, $userId);
    if ($time['nome_curto']) return $time['nome_curto'];
    return dtNomeExibicao($pdo, $userId);
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
            'nome_a' => dtNomeExibicaoCurto($pdo, (int)$r['user_a']),
            'logo_a' => dtTimeDoUsuario($pdo, (int)$r['user_a'])['logo'],
            'vitorias_a' => $vitoriasA,
            'nome_b' => dtNomeExibicaoCurto($pdo, (int)$r['user_b']),
            'logo_b' => dtTimeDoUsuario($pdo, (int)$r['user_b'])['logo'],
            'vitorias_b' => $vitoriasB,
            'lider' => $vitoriasA > $vitoriasB ? 'a' : ($vitoriasB > $vitoriasA ? 'b' : null),
        ];
    }
    return $out;
}

/**
 * As rivalidades do próprio usuário: contra quem ele mais jogou, com o placar da
 * série. Diferente de dtMaioresRivalidades, aqui ele é SEMPRE o lado esquerdo —
 * é a leitura que interessa ("eu 3 x 5 fulano"), não a do par em abstrato.
 */
function dtMinhasRivalidades(PDO $pdo, int $userId, int $limite = 5): array
{
    $st = $pdo->prepare("
        SELECT
            CASE WHEN id_criador = :eu THEN id_desafiado ELSE id_criador END AS rival,
            COUNT(*) AS total,
            SUM(CASE WHEN id_vencedor = :eu2 THEN 1 ELSE 0 END) AS minhas,
            SUM(CASE WHEN id_vencedor <> :eu3 THEN 1 ELSE 0 END) AS dele
        FROM dreamteam_duelos
        WHERE status = 'simulado' AND id_desafiado > 0
          AND (id_criador = :eu4 OR id_desafiado = :eu5)
        GROUP BY rival
        ORDER BY total DESC, minhas DESC
        LIMIT :lim
    ");
    foreach (['eu','eu2','eu3','eu4','eu5'] as $p) $st->bindValue($p, $userId, PDO::PARAM_INT);
    $st->bindValue('lim', $limite, PDO::PARAM_INT);
    $st->execute();

    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $minhas = (int)$r['minhas'];
        $dele = (int)$r['dele'];
        $out[] = [
            'total' => (int)$r['total'],
            'nome_rival' => dtNomeExibicaoCurto($pdo, (int)$r['rival']),
            'logo_rival' => dtTimeDoUsuario($pdo, (int)$r['rival'])['logo'],
            'minhas' => $minhas,
            'dele' => $dele,
            'lider' => $minhas > $dele ? 'eu' : ($dele > $minhas ? 'rival' : null),
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
    // 'nome' completo (cidade + time) pras telas largas; 'nome_curto' só o time,
    // pros rankings e rivalidades, onde duas colunas dividem a linha e "Las Vegas
    // Coyotes" era cortado na metade.
    $out = $t
        ? [
            'nome' => trim($t['city'] . ' ' . $t['name']),
            'nome_curto' => trim($t['name']) !== '' ? trim($t['name']) : trim($t['city'] . ' ' . $t['name']),
            'logo' => getTeamPhoto($t['photo_url'] ?? null),
          ]
        : ['nome' => null, 'nome_curto' => null, 'logo' => null];
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
        // O giro extra é do jogador e vale pro duelo inteiro — cada lado tem o seu.
        $out['reroll_disponivel'] = (int)($duelo[$souCriador ? 'reroll_criador' : 'reroll_desafiado'] ?? 1) === 1;
        $out['time_sorteado'] = dtTimePorId((string)$duelo['time_sorteado_id']);
    }

    if ($duelo['status'] === 'simulado') {
        $resultado = json_decode((string)$duelo['resultado'], true) ?: [];
        $out['resultado'] = $resultado;
        $out['meu_lado'] = $meuLado === 'criador' ? 'a' : 'b';
        $out['eu_venci'] = ((int)$duelo['id_vencedor'] === $userId);

        // Revanche: só entre jogadores, e só enquanto os dois tiverem como bancar.
        $out['revanche_disponivel'] = $ehPvp && ($duelo['revanche_duelo_id'] ?? null) === null;
        $out['revanche_eu'] = (int)($duelo[$souCriador ? 'revanche_criador' : 'revanche_desafiado'] ?? 0) === 1;
        $out['revanche_oponente'] = (int)($duelo[$souCriador ? 'revanche_desafiado' : 'revanche_criador'] ?? 0) === 1;
    }

    if ($duelo['status'] === 'convite') {
        $out['sou_desafiante'] = $souCriador;
    }

    return $out;
}

// ── COPA (mata-mata) ────────────────────────────────────────────────────────

/** A copa em que o usuário está agora — inclui a encerrada, pra ele ver o resultado ao voltar. */
function dtCopaAtiva(PDO $pdo, int $userId): ?array
{
    $st = $pdo->prepare("
        SELECT c.* FROM dreamteam_copas c
        JOIN dreamteam_copa_jogadores j ON j.copa_id = c.id
        WHERE j.user_id = ? AND c.status IN ('aguardando', 'draft', 'encerrada')
        ORDER BY (c.status IN ('aguardando', 'draft')) DESC, c.id DESC
        LIMIT 1
    ");
    $st->execute([$userId]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** Só o que impede começar outra coisa — a encerrada não conta, senão a pessoa nunca mais jogaria. */
function dtCopaEmAndamento(PDO $pdo, int $userId): ?array
{
    $st = $pdo->prepare("
        SELECT c.* FROM dreamteam_copas c
        JOIN dreamteam_copa_jogadores j ON j.copa_id = c.id
        WHERE j.user_id = ? AND c.status IN ('aguardando', 'draft')
        ORDER BY c.id DESC LIMIT 1
    ");
    $st->execute([$userId]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function dtCopaJogadores(PDO $pdo, int $copaId): array
{
    $st = $pdo->prepare('SELECT * FROM dreamteam_copa_jogadores WHERE copa_id = ? ORDER BY COALESCE(seed, 999), id');
    $st->execute([$copaId]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** Nome de exibição de um participante: o time de franquia manda; sem time, o nome pessoal. */
function dtCopaIdentidade(PDO $pdo, int $userId): array
{
    $time = dtTimeDoUsuario($pdo, $userId);
    if ($time['nome']) return ['user_id' => $userId, 'nome' => $time['nome'], 'logo' => $time['logo']];
    $st = $pdo->prepare('SELECT nome FROM games_usuarios WHERE id = ?');
    $st->execute([$userId]);
    return ['user_id' => $userId, 'nome' => $st->fetchColumn() ?: 'Jogador', 'logo' => null];
}

/** Fecha um roster incompleto pegando sempre o melhor elegível disponível. Usado no auto-draft de quem sumiu. */
function dtCopaFecharRoster(array $roster): array
{
    // O teto de voltas é folga pura (5 vagas): protege contra loop infinito caso
    // um sorteio azarado não ofereça ninguém novo pras vagas que sobraram.
    for ($volta = 0; $volta < 60 && !dtRosterCompleto($roster); $volta++) {
        $time = dtSortearTimeValido($roster);
        $escolha = dtMelhorEscolha($time, $roster);
        if (!$escolha) continue;
        $j = $escolha['jogador'];
        $roster[$escolha['pos']] = ['nome' => $j['nome'], 'ovr' => $j['ovr'], 'pos' => $j['pos']];
    }
    return $roster;
}

/**
 * Monta a chave e simula a copa inteira de uma vez.
 *
 * As duplas da primeira fase saem de um embaralhamento simples: como as seeds
 * já são sorteadas, parear em sequência dá exatamente a mesma distribuição que
 * o chaveamento clássico 1x8/4x5/2x7/3x6, sem a complicação.
 */
function dtCopaMontarChave(PDO $pdo, array $jogadores, int $aposta): array
{
    $slots = [];
    foreach ($jogadores as $j) {
        $ident = dtCopaIdentidade($pdo, (int)$j['user_id']);
        $ident['roster'] = json_decode((string)$j['roster'], true) ?: dtRosterVazio();
        $ident['ovr'] = dtSomaRoster($ident['roster']);
        $slots[] = $ident;
    }

    $nomesFases = [8 => ['Quartas de final', 'Semifinal', 'Final'], 4 => ['Semifinal', 'Final']];
    $fases = [];
    $restantes = $slots;
    $rotulos = $nomesFases[count($slots)] ?? ['Final'];

    foreach ($rotulos as $rotulo) {
        $partidas = [];
        $vencedores = [];
        for ($i = 0; $i < count($restantes); $i += 2) {
            $a = $restantes[$i];
            $b = $restantes[$i + 1];
            $res = dtCalcularResultado($a['roster'], $b['roster']);
            $vencedores[] = $res['vencedor'] === 'a' ? $a : $b;
            $partidas[] = [
                'a' => ['user_id' => $a['user_id'], 'nome' => $a['nome'], 'logo' => $a['logo'], 'ovr' => $a['ovr']],
                'b' => ['user_id' => $b['user_id'], 'nome' => $b['nome'], 'logo' => $b['logo'], 'ovr' => $b['ovr']],
                'resultado' => $res,
            ];
        }
        $fases[] = ['nome' => $rotulo, 'partidas' => $partidas];
        $restantes = $vencedores;
    }

    $campeao = $restantes[0];
    return [
        'fases' => $fases,
        'campeao' => ['user_id' => $campeao['user_id'], 'nome' => $campeao['nome'], 'logo' => $campeao['logo']],
        'pote' => $aposta * count($slots),
        'rosters' => array_map(fn($s) => ['user_id' => $s['user_id'], 'nome' => $s['nome'], 'roster' => $s['roster']], $slots),
    ];
}

/**
 * Fecha o draft quando todo mundo terminou (ou quando o prazo estourou), simula
 * a chave e paga o campeão.
 *
 * Roda dentro de transação com a copa travada porque qualquer requisição de
 * qualquer participante pode chegar aqui ao mesmo tempo — sem a trava, dois
 * pollings simultâneos pagariam o pote duas vezes.
 */
function dtCopaProcessar(PDO $pdo, int $copaId): void
{
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT * FROM dreamteam_copas WHERE id = ? FOR UPDATE');
        $st->execute([$copaId]);
        $copa = $st->fetch(PDO::FETCH_ASSOC);
        if (!$copa || $copa['status'] !== 'draft') { $pdo->rollBack(); return; }

        $jogadores = dtCopaJogadores($pdo, $copaId);
        $faltam = array_filter($jogadores, fn($j) => (int)$j['pronto'] !== 1);
        $prazoAcabou = $copa['draft_ate'] !== null && strtotime($copa['draft_ate']) <= time();
        if ($faltam && !$prazoAcabou) { $pdo->rollBack(); return; }

        // Prazo estourou com gente pendurada: completa o time de quem sumiu em vez
        // de cancelar — os que jogaram direito não perdem a copa por causa de um ausente.
        foreach ($faltam as $j) {
            $roster = dtCopaFecharRoster(json_decode((string)$j['roster'], true) ?: dtRosterVazio());
            $pdo->prepare('UPDATE dreamteam_copa_jogadores SET roster = ?, pronto = 1 WHERE id = ?')
                ->execute([json_encode($roster), (int)$j['id']]);
        }

        $jogadores = dtCopaJogadores($pdo, $copaId);
        shuffle($jogadores);
        foreach ($jogadores as $i => $j) {
            $pdo->prepare('UPDATE dreamteam_copa_jogadores SET seed = ? WHERE id = ?')->execute([$i + 1, (int)$j['id']]);
        }

        $chave = dtCopaMontarChave($pdo, $jogadores, (int)$copa['aposta']);
        $campeaoId = (int)$chave['campeao']['user_id'];

        $pdo->prepare('UPDATE games_usuarios SET pontos = pontos + ? WHERE id = ?')
            ->execute([(int)$chave['pote'], $campeaoId]);
        $pdo->prepare("UPDATE dreamteam_copas SET status = 'encerrada', chave = ?, id_campeao = ?, concluido_em = NOW() WHERE id = ?")
            ->execute([json_encode($chave), $campeaoId, $copaId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[dreamteam] copa processar: ' . $e->getMessage());
    }
}

/** Copa que encheu agora: marca o início do draft e dispara o prazo. */
function dtCopaIniciarDraft(PDO $pdo, int $copaId): void
{
    $pdo->prepare("UPDATE dreamteam_copas SET status = 'draft', draft_ate = DATE_ADD(NOW(), INTERVAL ? MINUTE)
                   WHERE id = ? AND status = 'aguardando'")
        ->execute([DT_COPA_DRAFT_MIN, $copaId]);
}

/** Devolve a entrada de todo mundo e encerra a copa. Usado no cancelamento e na expiração do lobby. */
function dtCopaReembolsar(PDO $pdo, array $copa, string $statusFinal): void
{
    foreach (dtCopaJogadores($pdo, (int)$copa['id']) as $j) {
        $pdo->prepare('UPDATE games_usuarios SET pontos = pontos + ? WHERE id = ?')
            ->execute([(int)$copa['aposta'], (int)$j['user_id']]);
    }
    $pdo->prepare('UPDATE dreamteam_copas SET status = ?, concluido_em = NOW() WHERE id = ?')
        ->execute([$statusFinal, (int)$copa['id']]);
}

function dtCopaSerializar(PDO $pdo, ?array $copa, int $userId): ?array
{
    if (!$copa) return null;
    $copaId = (int)$copa['id'];
    $jogadores = dtCopaJogadores($pdo, $copaId);

    $eu = null;
    $lista = [];
    $emDraft = $copa['status'] === 'draft';
    foreach ($jogadores as $j) {
        $ident = dtCopaIdentidade($pdo, (int)$j['user_id']);
        $ident['pronto'] = (int)$j['pronto'] === 1;
        // Durante a montagem todo mundo vê o time de todo mundo se formando —
        // mesma regra do duelo, onde dá pra acompanhar o adversário escolhendo.
        if ($emDraft) {
            $r = json_decode((string)$j['roster'], true) ?: dtRosterVazio();
            $ident['roster'] = $r;
            $ident['ovr'] = dtSomaRoster($r);
        }
        $lista[] = $ident;
        if ((int)$j['user_id'] === $userId) $eu = $j;
    }

    $out = [
        'id' => $copaId,
        'codigo' => $copa['codigo'],
        'status' => $copa['status'],
        'tamanho' => (int)$copa['tamanho'],
        'aposta' => (int)$copa['aposta'],
        'pote' => (int)$copa['aposta'] * (int)$copa['tamanho'],
        'sou_criador' => (int)$copa['id_criador'] === $userId,
        'participantes' => $lista,
        'vagas' => (int)$copa['tamanho'] - count($jogadores),
    ];

    if ($copa['status'] === 'draft' && $eu) {
        $out['meu_roster'] = json_decode((string)$eu['roster'], true) ?: dtRosterVazio();
        $out['estou_pronto'] = (int)$eu['pronto'] === 1;
        $out['reroll_disponivel'] = (bool)$eu['reroll_disponivel'];
        $out['time_sorteado'] = dtTimePorId((string)$eu['time_sorteado_id']);
        $out['segundos_restantes'] = $copa['draft_ate'] ? max(0, strtotime($copa['draft_ate']) - time()) : null;
    }

    if ($copa['status'] === 'encerrada') {
        $out['chave'] = json_decode((string)$copa['chave'], true) ?: null;
        $out['sou_campeao'] = (int)$copa['id_campeao'] === $userId;
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
            if (dtCopaEmAndamento($pdo, $user_id)) {
                echo json_encode(["ok" => false, "msg" => "Você está numa copa — termine ela antes."]);
                exit;
            }
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
            if (dtCopaEmAndamento($pdo, $user_id)) {
                echo json_encode(["ok" => false, "msg" => "Você está numa copa — termine ela antes."]);
                exit;
            }
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
            if (dtCopaEmAndamento($pdo, $user_id)) {
                echo json_encode(["ok" => false, "msg" => "Você está numa copa — termine ela antes."]);
                exit;
            }
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
                            time_sorteado_id = NULL, entrou_em = NOW()
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
            if (dtCopaEmAndamento($pdo, $user_id)) {
                echo json_encode(["ok" => false, "msg" => "Você está numa copa — termine ela antes."]);
                exit;
            }
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
                                time_sorteado_id = NULL, entrou_em = NOW()
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
            // 'convite' entra junto: é o desafio direto que a pessoa mandou e quer
            // retirar antes do outro responder. Nos dois casos só o criador pagou.
            if (!in_array($duelo['status'], ['aguardando', 'convite'], true)) {
                echo json_encode(['ok' => false, 'msg' => 'Só dá pra cancelar antes de alguém entrar.']);
                exit;
            }

            $pdo->beginTransaction();
            try {
                $st = $pdo->prepare('SELECT status FROM dreamteam_duelos WHERE id = ? FOR UPDATE');
                $st->execute([$duelo['id']]);
                if (!in_array((string)$st->fetchColumn(), ['aguardando', 'convite'], true)) {
                    $pdo->rollBack();
                    echo json_encode(['ok' => false, 'msg' => 'Esse duelo já saiu da espera.']);
                    exit;
                }
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

        // Encerra um duelo PvP travado porque o oponente sumiu, devolvendo a aposta aos DOIS.
        //
        // Só vale enquanto o draft está rolando: depois que os dois times ficam completos o duelo
        // já foi simulado e pago, não há o que cancelar. E só em PvP — a máquina nunca abandona,
        // e liberar isso contra ela viraria "desistir da partida que está ficando ruim" de graça.
        // Como ninguém ganha nem perde moeda (os dois recebem de volta), não há proveito em usar.
        if ($acao === 'desistir') {
            $duelo = dtDueloAtivo($pdo, $user_id);
            if (!$duelo || $duelo['status'] !== 'draft') {
                echo json_encode(['ok' => false, 'msg' => 'Só dá pra encerrar durante a escolha dos jogadores.']);
                exit;
            }
            if ((int)$duelo['id_desafiado'] <= 0) {
                echo json_encode(['ok' => false, 'msg' => 'Não dá pra encerrar um duelo contra a máquina.']);
                exit;
            }

            $pdo->beginTransaction();
            try {
                // Relê com lock: sem isso, dois cliques (ou os dois jogadores juntos) podiam
                // devolver a aposta duas vezes.
                $st = $pdo->prepare('SELECT * FROM dreamteam_duelos WHERE id = ? FOR UPDATE');
                $st->execute([$duelo['id']]);
                $atual = $st->fetch(PDO::FETCH_ASSOC);
                if (!$atual || $atual['status'] !== 'draft') {
                    $pdo->rollBack();
                    echo json_encode(['ok' => false, 'msg' => 'Esse duelo já foi encerrado.']);
                    exit;
                }
                $aposta = (int)$atual['aposta'];
                $pdo->prepare('UPDATE games_usuarios SET pontos = pontos + ? WHERE id = ?')->execute([$aposta, (int)$atual['id_criador']]);
                $pdo->prepare('UPDATE games_usuarios SET pontos = pontos + ? WHERE id = ?')->execute([$aposta, (int)$atual['id_desafiado']]);
                $pdo->prepare("UPDATE dreamteam_duelos SET status = 'cancelado', concluido_em = NOW() WHERE id = ?")->execute([$atual['id']]);
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
                $pdo->prepare('UPDATE dreamteam_duelos SET time_sorteado_id = ? WHERE id = ?')
                    ->execute([$novoTime['id'], $atual['id']]);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
            echo json_encode(['ok' => true]);
            exit;
        }

        // Gira de novo o time sorteado — UMA vez no draft inteiro, não uma por
        // rodada. Cada lado tem o seu (reroll_criador / reroll_desafiado).
        if ($acao === 'girar_de_novo') {
            $duelo = dtDueloAtivo($pdo, $user_id);
            if (!$duelo || $duelo['status'] !== 'draft') { echo json_encode(['ok' => false, 'msg' => 'Esse duelo não está em draft.']); exit; }
            $lado = ((int)$duelo['id_criador'] === $user_id) ? 'criador' : 'desafiado';
            $colReroll = $lado === 'criador' ? 'reroll_criador' : 'reroll_desafiado';
            if ($duelo['turno'] !== $lado) { echo json_encode(['ok' => false, 'msg' => 'Não é sua vez.']); exit; }
            if ($duelo['time_sorteado_id'] === null) { echo json_encode(['ok' => false, 'msg' => 'Sorteie um time primeiro.']); exit; }
            if ((int)($duelo[$colReroll] ?? 0) !== 1) { echo json_encode(['ok' => false, 'msg' => 'Você já usou seu giro extra nesse duelo.']); exit; }

            $pdo->beginTransaction();
            try {
                $st = $pdo->prepare('SELECT * FROM dreamteam_duelos WHERE id = ? FOR UPDATE');
                $st->execute([$duelo['id']]);
                $atual = $st->fetch(PDO::FETCH_ASSOC);
                if (!$atual || $atual['status'] !== 'draft' || $atual['turno'] !== $lado || (int)($atual[$colReroll] ?? 0) !== 1 || $atual['time_sorteado_id'] === null) {
                    $pdo->rollBack();
                    echo json_encode(['ok' => false, 'msg' => 'Não deu pra girar de novo.']);
                    exit;
                }
                $roster = json_decode((string)$atual[$lado === 'criador' ? 'roster_criador' : 'roster_desafiado'], true) ?: dtRosterVazio();
                $novoTime = dtSortearTimeValido($roster);
                $pdo->prepare("UPDATE dreamteam_duelos SET time_sorteado_id = ?, {$colReroll} = 0 WHERE id = ?")
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
                    $pdo->prepare("UPDATE dreamteam_duelos SET turno = ?, time_sorteado_id = NULL WHERE id = ?")
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

        // ── DESAFIO DIRETO ──────────────────────────────────────────────────
        // Procura gente pelo nome da pessoa OU pelo nome do time de franquia —
        // na liga quase todo mundo se conhece pelo time, não pelo nome de cadastro.
        if ($acao === 'buscar_oponentes') {
            $termo = trim((string)($_POST['termo'] ?? ''));
            if (mb_strlen($termo) < 2) { echo json_encode(['ok' => true, 'jogadores' => []]); exit; }

            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $termo) . '%';
            $st = $pdo->prepare("
                SELECT g.id, g.nome,
                       TRIM(CONCAT(COALESCE(t.city, ''), ' ', COALESCE(t.name, ''))) AS time_nome,
                       t.photo_url
                FROM games_usuarios g
                LEFT JOIN teams t ON t.user_id = g.id
                WHERE g.id <> ?
                  AND (g.nome LIKE ? OR TRIM(CONCAT(COALESCE(t.city, ''), ' ', COALESCE(t.name, ''))) LIKE ?)
                ORDER BY g.nome
                LIMIT 8
            ");
            $st->execute([$user_id, $like, $like]);

            $jogadores = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $u) {
                $ocupado = dtDueloEmAndamento($pdo, (int)$u['id']) || dtCopaEmAndamento($pdo, (int)$u['id']);
                $jogadores[] = [
                    'id' => (int)$u['id'],
                    'nome' => $u['nome'],
                    'time_nome' => $u['time_nome'] !== '' ? $u['time_nome'] : null,
                    'time_logo' => $u['photo_url'] ? getTeamPhoto($u['photo_url']) : null,
                    'ocupado' => (bool)$ocupado,
                ];
            }
            echo json_encode(['ok' => true, 'jogadores' => $jogadores]);
            exit;
        }

        if ($acao === 'desafiar') {
            if (dtCopaEmAndamento($pdo, $user_id)) { echo json_encode(['ok' => false, 'msg' => 'Você está numa copa — termine ela antes.']); exit; }
            if (dtDueloEmAndamento($pdo, $user_id)) { echo json_encode(['ok' => false, 'msg' => 'Você já tem um duelo em andamento.']); exit; }

            $alvo = (int)($_POST['oponente'] ?? 0);
            $aposta = (int)($_POST['aposta'] ?? 0);
            if ($alvo <= 0 || $alvo === $user_id) { echo json_encode(['ok' => false, 'msg' => 'Escolha alguém pra desafiar.']); exit; }
            if ($aposta < DT_APOSTA_MIN || $aposta > DT_APOSTA_MAX) {
                echo json_encode(['ok' => false, 'msg' => 'A aposta deve ser entre ' . DT_APOSTA_MIN . ' e ' . DT_APOSTA_MAX . ' moedas.']);
                exit;
            }

            $stAlvo = $pdo->prepare('SELECT nome FROM games_usuarios WHERE id = ?');
            $stAlvo->execute([$alvo]);
            $nomeAlvo = $stAlvo->fetchColumn();
            if (!$nomeAlvo) { echo json_encode(['ok' => false, 'msg' => 'Jogador não encontrado.']); exit; }
            if (dtDueloEmAndamento($pdo, $alvo) || dtCopaEmAndamento($pdo, $alvo)) {
                echo json_encode(['ok' => false, 'msg' => $nomeAlvo . ' já está em partida agora. Tente daqui a pouco.']);
                exit;
            }

            $pdo->beginTransaction();
            try {
                $st = $pdo->prepare('SELECT pontos FROM games_usuarios WHERE id = ? FOR UPDATE');
                $st->execute([$user_id]);
                if ((int)$st->fetchColumn() < $aposta) {
                    $pdo->rollBack();
                    echo json_encode(['ok' => false, 'msg' => 'Saldo insuficiente.']);
                    exit;
                }
                $pdo->prepare('UPDATE games_usuarios SET pontos = pontos - ? WHERE id = ?')->execute([$aposta, $user_id]);
                $codigo = dtGerarCodigo($pdo);
                $pdo->prepare("INSERT INTO dreamteam_duelos (codigo, id_criador, id_desafiado, aposta, status) VALUES (?, ?, ?, ?, 'convite')")
                    ->execute([$codigo, $user_id, $alvo, $aposta]);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }

            // Avisa a pessoa desafiada. O link abre o jogo direto, e como o convite
            // é o estado ativo dela lá dentro, ela cai na tela de aceitar/recusar.
            //
            // Só push, sem WhatsApp: é um convite pra jogar, não um aviso de liga —
            // não justifica mensagem no celular de ninguém. Por isso vai direto no
            // sendPushRaw em vez do sendPushToUser (que manda pelos dois canais),
            // com a checagem de preferência feita aqui pra quem desligou "Games".
            //
            // Fora da transação de propósito: push que falha não pode desfazer o
            // desafio nem devolver erro pra quem desafiou.
            try {
                if (userWantsNotif($pdo, $alvo, 'games')) {
                    $meu = dtTimeDoUsuario($pdo, $user_id);
                    $quem = $meu['nome'] ?: dtNomeExibicao($pdo, $user_id);
                    sendPushRaw($pdo, $alvo, [
                        'title' => 'Desafio no Starting5x5',
                        'body'  => $quem . ' te desafiou valendo ' . $aposta . ' moedas. Toque pra aceitar ou recusar.',
                        'url'   => '/games/games/index.php?game=dreamteam',
                    ]);
                }
            } catch (Throwable $e) {
                error_log('[dreamteam] push desafio: ' . $e->getMessage());
            }

            echo json_encode(['ok' => true]);
            exit;
        }

        if ($acao === 'responder_desafio') {
            $aceitar = ($_POST['aceitar'] ?? '') === '1';
            $duelo = dtDueloAtivo($pdo, $user_id);
            if (!$duelo || $duelo['status'] !== 'convite' || (int)$duelo['id_desafiado'] !== $user_id) {
                echo json_encode(['ok' => false, 'msg' => 'Esse desafio não está mais disponível.']);
                exit;
            }

            $pdo->beginTransaction();
            try {
                $st = $pdo->prepare('SELECT * FROM dreamteam_duelos WHERE id = ? FOR UPDATE');
                $st->execute([$duelo['id']]);
                $atual = $st->fetch(PDO::FETCH_ASSOC);
                if (!$atual || $atual['status'] !== 'convite') {
                    $pdo->rollBack();
                    echo json_encode(['ok' => false, 'msg' => 'Esse desafio não está mais disponível.']);
                    exit;
                }
                $aposta = (int)$atual['aposta'];

                if (!$aceitar) {
                    $pdo->prepare('UPDATE games_usuarios SET pontos = pontos + ? WHERE id = ?')
                        ->execute([$aposta, (int)$atual['id_criador']]);
                    $pdo->prepare("UPDATE dreamteam_duelos SET status = 'recusado', concluido_em = NOW() WHERE id = ?")
                        ->execute([$atual['id']]);
                    $pdo->commit();
                    echo json_encode(['ok' => true]);
                    exit;
                }

                $stSaldo = $pdo->prepare('SELECT pontos FROM games_usuarios WHERE id = ? FOR UPDATE');
                $stSaldo->execute([$user_id]);
                if ((int)$stSaldo->fetchColumn() < $aposta) {
                    $pdo->rollBack();
                    echo json_encode(['ok' => false, 'msg' => 'Saldo insuficiente pra aceitar esse desafio.']);
                    exit;
                }
                $pdo->prepare('UPDATE games_usuarios SET pontos = pontos - ? WHERE id = ?')->execute([$aposta, $user_id]);
                $pdo->prepare("UPDATE dreamteam_duelos SET status = 'draft', turno = ?, entrou_em = NOW() WHERE id = ?")
                    ->execute([random_int(0, 1) === 0 ? 'criador' : 'desafiado', $atual['id']]);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
            echo json_encode(['ok' => true]);
            exit;
        }

        // ── REVANCHE ────────────────────────────────────────────────────────
        // Os dois precisam querer. O primeiro a clicar só marca; o segundo é
        // quem de fato cria o duelo novo, com a mesma aposta.
        if ($acao === 'revanche') {
            $duelo = dtDueloAtivo($pdo, $user_id);
            if (!$duelo || $duelo['status'] !== 'simulado') { echo json_encode(['ok' => false, 'msg' => 'Não há partida pra revanche.']); exit; }
            if ((int)$duelo['id_desafiado'] <= 0) { echo json_encode(['ok' => false, 'msg' => 'Revanche só vale em duelo entre jogadores.']); exit; }

            $souCriador = (int)$duelo['id_criador'] === $user_id;
            $col = $souCriador ? 'revanche_criador' : 'revanche_desafiado';

            $pdo->beginTransaction();
            try {
                $st = $pdo->prepare('SELECT * FROM dreamteam_duelos WHERE id = ? FOR UPDATE');
                $st->execute([$duelo['id']]);
                $atual = $st->fetch(PDO::FETCH_ASSOC);
                if (!$atual || $atual['status'] !== 'simulado' || $atual['revanche_duelo_id'] !== null) {
                    $pdo->rollBack();
                    echo json_encode(['ok' => false, 'msg' => 'A revanche dessa partida já foi criada.']);
                    exit;
                }

                $aposta = (int)$atual['aposta'];
                // Só marca se der pra bancar: melhor recusar agora do que deixar o
                // outro esperando uma revanche que vai falhar na hora de cobrar.
                $stSaldo = $pdo->prepare('SELECT pontos FROM games_usuarios WHERE id = ? FOR UPDATE');
                $stSaldo->execute([$user_id]);
                if ((int)$stSaldo->fetchColumn() < $aposta) {
                    $pdo->rollBack();
                    echo json_encode(['ok' => false, 'msg' => 'Saldo insuficiente pra bancar a revanche (' . $aposta . ' moedas).']);
                    exit;
                }

                $pdo->prepare("UPDATE dreamteam_duelos SET {$col} = 1 WHERE id = ?")->execute([$atual['id']]);
                $osDois = ($souCriador ? 1 : (int)$atual['revanche_criador']) === 1
                       && ($souCriador ? (int)$atual['revanche_desafiado'] : 1) === 1;

                if ($osDois) {
                    $idA = (int)$atual['id_criador'];
                    $idB = (int)$atual['id_desafiado'];
                    // Trava os dois saldos antes de cobrar: entre marcar e criar, o
                    // outro pode ter gasto as moedas em qualquer outro jogo.
                    $saldos = [];
                    foreach ([$idA, $idB] as $uid) {
                        $s = $pdo->prepare('SELECT pontos FROM games_usuarios WHERE id = ? FOR UPDATE');
                        $s->execute([$uid]);
                        $saldos[$uid] = (int)$s->fetchColumn();
                    }
                    if ($saldos[$idA] < $aposta || $saldos[$idB] < $aposta) {
                        $pdo->rollBack();
                        echo json_encode(['ok' => false, 'msg' => 'Um dos dois ficou sem saldo pra revanche.']);
                        exit;
                    }
                    foreach ([$idA, $idB] as $uid) {
                        $pdo->prepare('UPDATE games_usuarios SET pontos = pontos - ? WHERE id = ?')->execute([$aposta, $uid]);
                    }
                    // Quem perdeu começa escolhendo — pequena compensação pela derrota.
                    $perdedor = (int)$atual['id_vencedor'] === $idA ? 'desafiado' : 'criador';
                    $codigo = dtGerarCodigo($pdo);
                    $pdo->prepare("INSERT INTO dreamteam_duelos (codigo, id_criador, id_desafiado, aposta, modo, status, turno, entrou_em)
                                   VALUES (?, ?, ?, ?, ?, 'draft', ?, NOW())")
                        ->execute([$codigo, $idA, $idB, $aposta, $atual['modo'] ?? 'amigo', $perdedor]);
                    $novoId = (int)$pdo->lastInsertId();
                    $pdo->prepare('UPDATE dreamteam_duelos SET revanche_duelo_id = ? WHERE id = ?')->execute([$novoId, $atual['id']]);
                }
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
            echo json_encode(['ok' => true]);
            exit;
        }

        // Um código só, dois destinos possíveis: o campo "Código" e o link de
        // convite servem tanto pra duelo quanto pra copa, então quem pergunta é o
        // cliente, que depois chama 'entrar' ou 'copa_entrar'.
        if ($acao === 'tipo_codigo') {
            $codigo = strtoupper(trim((string)($_POST['codigo'] ?? '')));
            $tipo = null;
            if ($codigo !== '') {
                $st = $pdo->prepare('SELECT 1 FROM dreamteam_copas WHERE codigo = ?');
                $st->execute([$codigo]);
                if ($st->fetchColumn()) {
                    $tipo = 'copa';
                } else {
                    $st = $pdo->prepare('SELECT 1 FROM dreamteam_duelos WHERE codigo = ?');
                    $st->execute([$codigo]);
                    if ($st->fetchColumn()) $tipo = 'duelo';
                }
            }
            echo json_encode(['ok' => true, 'tipo' => $tipo]);
            exit;
        }

        // ── COPA ────────────────────────────────────────────────────────────
        if ($acao === 'copa_criar') {
            if (dtCopaEmAndamento($pdo, $user_id)) { echo json_encode(['ok' => false, 'msg' => 'Você já está numa copa.']); exit; }
            if (dtDueloEmAndamento($pdo, $user_id)) { echo json_encode(['ok' => false, 'msg' => 'Termine seu duelo antes de abrir uma copa.']); exit; }

            $tamanho = (int)($_POST['tamanho'] ?? 0);
            $aposta = (int)($_POST['aposta'] ?? 0);
            if (!in_array($tamanho, DT_COPA_TAMANHOS, true)) { echo json_encode(['ok' => false, 'msg' => 'A copa é de 4 ou 8 times.']); exit; }
            if ($aposta < DT_COPA_APOSTA_MIN || $aposta > DT_COPA_APOSTA_MAX) {
                echo json_encode(['ok' => false, 'msg' => 'A entrada deve ser entre ' . DT_COPA_APOSTA_MIN . ' e ' . DT_COPA_APOSTA_MAX . ' moedas.']);
                exit;
            }

            $pdo->beginTransaction();
            try {
                $st = $pdo->prepare('SELECT pontos FROM games_usuarios WHERE id = ? FOR UPDATE');
                $st->execute([$user_id]);
                if ((int)$st->fetchColumn() < $aposta) {
                    $pdo->rollBack();
                    echo json_encode(['ok' => false, 'msg' => 'Saldo insuficiente.']);
                    exit;
                }
                $pdo->prepare('UPDATE games_usuarios SET pontos = pontos - ? WHERE id = ?')->execute([$aposta, $user_id]);
                $codigo = dtGerarCodigo($pdo);
                $pdo->prepare("INSERT INTO dreamteam_copas (codigo, id_criador, tamanho, aposta, status) VALUES (?, ?, ?, ?, 'aguardando')")
                    ->execute([$codigo, $user_id, $tamanho, $aposta]);
                $copaId = (int)$pdo->lastInsertId();
                $pdo->prepare('INSERT INTO dreamteam_copa_jogadores (copa_id, user_id, roster) VALUES (?, ?, ?)')
                    ->execute([$copaId, $user_id, json_encode(dtRosterVazio())]);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
            echo json_encode(['ok' => true]);
            exit;
        }

        if ($acao === 'copa_entrar') {
            if (dtCopaEmAndamento($pdo, $user_id)) { echo json_encode(['ok' => false, 'msg' => 'Você já está numa copa.']); exit; }
            if (dtDueloEmAndamento($pdo, $user_id)) { echo json_encode(['ok' => false, 'msg' => 'Termine seu duelo antes de entrar numa copa.']); exit; }

            $codigo = strtoupper(trim((string)($_POST['codigo'] ?? '')));
            if ($codigo === '') { echo json_encode(['ok' => false, 'msg' => 'Informe o código da copa.']); exit; }

            $pdo->beginTransaction();
            try {
                // Trava a copa antes de contar as vagas: sem isso, duas pessoas entrando
                // no mesmo instante numa copa com 1 vaga passariam as duas.
                $st = $pdo->prepare("SELECT * FROM dreamteam_copas WHERE codigo = ? FOR UPDATE");
                $st->execute([$codigo]);
                $copa = $st->fetch(PDO::FETCH_ASSOC);
                if (!$copa) {
                    $pdo->rollBack();
                    echo json_encode(['ok' => false, 'msg' => 'Copa não encontrada.']);
                    exit;
                }
                if ($copa['status'] !== 'aguardando') {
                    $pdo->rollBack();
                    echo json_encode(['ok' => false, 'msg' => 'Essa copa já começou.']);
                    exit;
                }
                $stCount = $pdo->prepare('SELECT COUNT(*) FROM dreamteam_copa_jogadores WHERE copa_id = ?');
                $stCount->execute([(int)$copa['id']]);
                $inscritos = (int)$stCount->fetchColumn();
                if ($inscritos >= (int)$copa['tamanho']) {
                    $pdo->rollBack();
                    echo json_encode(['ok' => false, 'msg' => 'Copa cheia.']);
                    exit;
                }

                $stSaldo = $pdo->prepare('SELECT pontos FROM games_usuarios WHERE id = ? FOR UPDATE');
                $stSaldo->execute([$user_id]);
                if ((int)$stSaldo->fetchColumn() < (int)$copa['aposta']) {
                    $pdo->rollBack();
                    echo json_encode(['ok' => false, 'msg' => 'Saldo insuficiente pra essa entrada.']);
                    exit;
                }
                $pdo->prepare('UPDATE games_usuarios SET pontos = pontos - ? WHERE id = ?')->execute([(int)$copa['aposta'], $user_id]);
                $pdo->prepare('INSERT INTO dreamteam_copa_jogadores (copa_id, user_id, roster) VALUES (?, ?, ?)')
                    ->execute([(int)$copa['id'], $user_id, json_encode(dtRosterVazio())]);

                if ($inscritos + 1 >= (int)$copa['tamanho']) dtCopaIniciarDraft($pdo, (int)$copa['id']);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
            echo json_encode(['ok' => true]);
            exit;
        }

        // Sair do lobby (ou cancelar a copa, se for o criador) — só antes de começar.
        if ($acao === 'copa_sair') {
            $copa = dtCopaEmAndamento($pdo, $user_id);
            if (!$copa) { echo json_encode(['ok' => false, 'msg' => 'Você não está numa copa.']); exit; }

            $pdo->beginTransaction();
            try {
                $st = $pdo->prepare('SELECT * FROM dreamteam_copas WHERE id = ? FOR UPDATE');
                $st->execute([(int)$copa['id']]);
                $atual = $st->fetch(PDO::FETCH_ASSOC);
                if (!$atual || $atual['status'] !== 'aguardando') {
                    $pdo->rollBack();
                    echo json_encode(['ok' => false, 'msg' => 'A copa já começou, não dá mais pra sair.']);
                    exit;
                }

                if ((int)$atual['id_criador'] === $user_id) {
                    // Criador saindo desmonta a copa: quem já tinha entrado recebe de volta.
                    dtCopaReembolsar($pdo, $atual, 'cancelada');
                } else {
                    $pdo->prepare('UPDATE games_usuarios SET pontos = pontos + ? WHERE id = ?')
                        ->execute([(int)$atual['aposta'], $user_id]);
                    $pdo->prepare('DELETE FROM dreamteam_copa_jogadores WHERE copa_id = ? AND user_id = ?')
                        ->execute([(int)$atual['id'], $user_id]);
                }
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
            echo json_encode(['ok' => true]);
            exit;
        }

        // Draft da copa: cada um no seu ritmo, sem turno compartilhado.
        if (in_array($acao, ['copa_sortear', 'copa_girar', 'copa_escolher', 'copa_reposicionar'], true)) {
            $copa = dtCopaAtiva($pdo, $user_id);
            if (!$copa || $copa['status'] !== 'draft') { echo json_encode(['ok' => false, 'msg' => 'Essa copa não está em montagem.']); exit; }

            $pdo->beginTransaction();
            try {
                $st = $pdo->prepare('SELECT * FROM dreamteam_copa_jogadores WHERE copa_id = ? AND user_id = ? FOR UPDATE');
                $st->execute([(int)$copa['id'], $user_id]);
                $eu = $st->fetch(PDO::FETCH_ASSOC);
                if (!$eu || (int)$eu['pronto'] === 1) {
                    $pdo->rollBack();
                    echo json_encode(['ok' => false, 'msg' => 'Seu time já está fechado.']);
                    exit;
                }
                $roster = json_decode((string)$eu['roster'], true) ?: dtRosterVazio();

                if ($acao === 'copa_sortear' || $acao === 'copa_girar') {
                    $ehGiro = ($acao === 'copa_girar');
                    if (!$ehGiro && $eu['time_sorteado_id'] !== null) {
                        $pdo->rollBack();
                        echo json_encode(['ok' => false, 'msg' => 'Você já sorteou um time nessa rodada.']);
                        exit;
                    }
                    if ($ehGiro && ($eu['time_sorteado_id'] === null || (int)$eu['reroll_disponivel'] !== 1)) {
                        $pdo->rollBack();
                        echo json_encode(['ok' => false, 'msg' => 'Você já usou seu giro extra nessa copa.']);
                        exit;
                    }
                    // Sortear normal NÃO devolve o giro extra: ele é um por copa.
                    // Antes o sorteio de cada rodada zerava o contador, e na prática
                    // dava um giro por rodada.
                    $novoTime = dtSortearTimeValido($roster);
                    $sql = $ehGiro
                        ? 'UPDATE dreamteam_copa_jogadores SET time_sorteado_id = ?, reroll_disponivel = 0 WHERE id = ?'
                        : 'UPDATE dreamteam_copa_jogadores SET time_sorteado_id = ? WHERE id = ?';
                    $pdo->prepare($sql)->execute([$novoTime['id'], (int)$eu['id']]);
                    $pdo->commit();
                    echo json_encode(['ok' => true]);
                    exit;
                }

                if ($acao === 'copa_reposicionar') {
                    $de = strtoupper(trim((string)($_POST['de'] ?? '')));
                    $para = strtoupper(trim((string)($_POST['para'] ?? '')));
                    $jogador = $roster[$de] ?? null;
                    if (!in_array($de, dtPosicoes(), true) || !in_array($para, dtPosicoes(), true) || !$jogador
                        || ($roster[$para] ?? null) !== null || !in_array($para, $jogador['pos'] ?? [], true)) {
                        $pdo->rollBack();
                        echo json_encode(['ok' => false, 'msg' => 'Não dá pra mover esse jogador pra essa posição.']);
                        exit;
                    }
                    $roster[$para] = $jogador;
                    $roster[$de] = null;
                    $pdo->prepare('UPDATE dreamteam_copa_jogadores SET roster = ? WHERE id = ?')
                        ->execute([json_encode($roster), (int)$eu['id']]);
                    $pdo->commit();
                    echo json_encode(['ok' => true]);
                    exit;
                }

                // copa_escolher
                $nomeJogador = trim((string)($_POST['jogador'] ?? ''));
                $posEscolhida = strtoupper(trim((string)($_POST['posicao'] ?? '')));
                if ($nomeJogador === '' || !in_array($posEscolhida, dtPosicoes(), true)) {
                    $pdo->rollBack();
                    echo json_encode(['ok' => false, 'msg' => 'Escolha inválida.']);
                    exit;
                }
                $time = dtTimePorId((string)$eu['time_sorteado_id']);
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
                $completo = dtRosterCompleto($roster);
                // reroll_disponivel NÃO volta pra 1: o giro extra é um por copa,
                // não um por rodada.
                $pdo->prepare('UPDATE dreamteam_copa_jogadores SET roster = ?, pronto = ?, time_sorteado_id = NULL WHERE id = ?')
                    ->execute([json_encode($roster), $completo ? 1 : 0, (int)$eu['id']]);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }

            // Fora da transação acima: dtCopaProcessar abre a sua própria e só age
            // quando o último time fica pronto (ou quando o prazo estoura).
            dtCopaProcessar($pdo, (int)$copa['id']);
            echo json_encode(['ok' => true]);
            exit;
        }

        // Tira o usuário da copa encerrada pra ele poder entrar em outra.
        if ($acao === 'copa_sair_encerrada') {
            $copa = dtCopaAtiva($pdo, $user_id);
            if ($copa && $copa['status'] === 'encerrada') {
                $pdo->prepare('DELETE FROM dreamteam_copa_jogadores WHERE copa_id = ? AND user_id = ?')
                    ->execute([(int)$copa['id'], $user_id]);
            }
            echo json_encode(['ok' => true]);
            exit;
        }

        if ($acao === 'estado') {
            $duelo = dtDueloAtivo($pdo, $user_id);
            // O mesmo poll cuida do duelo e da copa: são telas alternativas, nunca
            // simultâneas, e um hash só evita duas requisições de 3 em 3 segundos.
            $copa = dtCopaAtiva($pdo, $user_id);
            if ($copa && $copa['status'] === 'draft') {
                dtCopaProcessar($pdo, (int)$copa['id']);
                $copa = dtCopaAtiva($pdo, $user_id);
            }
            $st = $pdo->prepare('SELECT pontos FROM games_usuarios WHERE id = ?');
            $st->execute([$user_id]);
            echo json_encode([
                'ok' => true,
                'duelo' => dtSerializar($pdo, $duelo, $user_id),
                'copa' => dtCopaSerializar($pdo, $copa, $user_id),
                'pontos' => (int)($st->fetchColumn() ?: 0),
            ]);
            exit;
        }

        if ($acao === 'ranking') {
            echo json_encode([
                'ok' => true,
                'ranking' => dtRankingVitorias($pdo),
                'minhas_rivalidades' => dtMinhasRivalidades($pdo, $user_id),
                'rivalidades' => dtMaioresRivalidades($pdo),
            ]);
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
$copaAtual = dtCopaAtiva($pdo, $user_id);
if ($copaAtual && $copaAtual['status'] === 'draft') {
    dtCopaProcessar($pdo, (int)$copaAtual['id']);
    $copaAtual = dtCopaAtiva($pdo, $user_id);
}
$copaInicial = dtCopaSerializar($pdo, $copaAtual, $user_id);
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

/* ── Desafio direto e revanche ────────────────────────────────────────── */
.dt-ou{display:flex;align-items:center;gap:10px;margin:16px 0;color:var(--text3);font-size:10px;font-weight:700;letter-spacing:1px;text-transform:uppercase}
.dt-ou::before,.dt-ou::after{content:'';flex:1;height:1px;background:var(--border)}

.dt-busca-lista{margin-top:8px;display:flex;flex-direction:column;gap:6px}
.dt-busca-item{display:flex;align-items:center;gap:9px;padding:8px 10px;border-radius:10px;background:var(--panel2);border:1px solid var(--border);min-width:0}
.dt-busca-item img{width:26px;height:26px;border-radius:6px;object-fit:cover;flex-shrink:0}
.dt-busca-txt{flex:1;min-width:0}
.dt-busca-nome{font-size:12.5px;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.dt-busca-sub{font-size:10.5px;color:var(--text2);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.dt-busca-btn{flex-shrink:0;background:var(--red);border:1px solid var(--red);color:#fff;border-radius:8px;font-family:var(--font);font-size:11px;font-weight:700;padding:7px 12px;cursor:pointer;transition:.15s}
.dt-busca-btn:hover:not(:disabled){filter:brightness(1.1)}
.dt-busca-btn:disabled{opacity:.4;cursor:not-allowed;background:transparent;color:var(--text2);border-color:var(--border)}

.dt-convite{text-align:center;padding:22px 18px}
.dt-convite-logo{width:64px;height:64px;border-radius:15px;object-fit:cover;margin:0 auto 12px;display:block}
.dt-convite-quem{font-size:19px;font-weight:900;line-height:1.2;margin-bottom:6px}
.dt-convite-txt{font-size:13px;color:var(--text2);margin-bottom:18px}
.dt-convite-acoes{display:flex;gap:10px}
.dt-convite-acoes button{flex:1}

/* Menu e Revanche dividem a linha no topo do resultado. O Menu fica menor: a
   ação em destaque é a revanche, que é o que a pessoa costuma querer ali. */
.dt-acoes-fim{display:flex;gap:8px;margin-bottom:14px}
/* Os botões herdam width:100% da classe base; sem zerar isso o Menu tomava a
   linha inteira e espremia a revanche em ~24px. */
.dt-acoes-fim > *{margin-top:0}
.dt-acoes-fim > button:first-child{flex:0 0 auto;width:auto;padding-left:20px;padding-right:20px}
.dt-acoes-fim > *:not(:first-child){flex:1;width:auto;min-width:0}
.dt-acoes-fim > button:only-child{flex:1;width:auto}
.dt-revanche-status{display:flex;align-items:center;justify-content:center;text-align:center;font-size:11.5px;font-weight:700;color:var(--amber);padding:11px;border-radius:11px;background:var(--amber-soft);border:1px solid color-mix(in srgb, var(--amber) 35%, transparent)}

/* ── Copa ─────────────────────────────────────────────────────────────── */
.dt-copa-vagas{display:grid;grid-template-columns:repeat(2,1fr);gap:8px;margin-bottom:14px}
.dt-copa-vaga{display:flex;align-items:center;gap:8px;padding:9px 11px;border-radius:11px;background:var(--panel2);border:1.5px solid var(--border);min-width:0}
.dt-copa-vaga.livre{border-style:dashed;color:var(--text3);justify-content:center;font-size:11px;font-weight:700}
.dt-copa-vaga.eu{border-color:var(--red);background:var(--red-soft)}
.dt-copa-vaga img{width:22px;height:22px;border-radius:5px;object-fit:cover;flex-shrink:0}
.dt-copa-vaga-nome{font-size:11.5px;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;min-width:0}
.dt-copa-pronto{margin-left:auto;font-size:9px;font-weight:800;letter-spacing:.5px;padding:2px 6px;border-radius:20px;flex-shrink:0}
.dt-copa-pronto.sim{background:var(--green-soft);color:var(--green)}
.dt-copa-pronto.nao{background:var(--amber-soft);color:var(--amber)}
.dt-copa-pote{display:flex;align-items:center;justify-content:center;gap:8px;padding:12px;border-radius:12px;background:var(--amber-soft);border:1.5px solid color-mix(in srgb, var(--amber) 40%, transparent);margin-bottom:14px}
.dt-copa-pote strong{font-size:20px;font-weight:900;color:var(--amber)}
.dt-copa-pote span{font-size:11px;font-weight:700;color:var(--text2);text-transform:uppercase;letter-spacing:1px}
.dt-copa-timer{font-size:12px;font-weight:800;color:var(--amber);text-align:center;margin-bottom:10px}
.dt-copa-timer.urgente{color:var(--red)}

/* Adversários com o time deles se formando ao vivo, durante a montagem. */
.dt-copa-rivais{display:flex;flex-direction:column;gap:8px}
.dt-copa-rival{background:var(--panel2);border:1px solid var(--border);border-radius:11px;padding:9px 10px}
.dt-copa-rival-topo{display:flex;align-items:center;gap:8px;margin-bottom:7px;min-width:0}
.dt-copa-rival-topo img{width:20px;height:20px;border-radius:5px;object-fit:cover;flex-shrink:0}
.dt-copa-rival-nome{font-size:11.5px;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;flex:1;min-width:0}
.dt-copa-rival-ovr{font-size:11px;font-weight:800;color:var(--text2);font-variant-numeric:tabular-nums;flex-shrink:0}
.dt-copa-mini{display:grid;grid-template-columns:repeat(5,1fr);gap:4px}
.dt-copa-mini-slot{border:1px dashed var(--border2);border-radius:7px;padding:5px 2px;text-align:center;min-width:0}
.dt-copa-mini-slot.cheio{border-style:solid;background:var(--panel3)}
.dt-copa-mini-pos{display:block;font-size:8px;font-weight:800;letter-spacing:.5px;color:var(--text3)}
.dt-copa-mini-slot.cheio .dt-copa-mini-pos{color:var(--text2)}
.dt-copa-mini-nome{display:block;font-size:9.5px;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.dt-copa-mini-ovr{display:block;font-size:10px;font-weight:900;font-variant-numeric:tabular-nums}

.dt-chave{display:flex;flex-direction:column;gap:18px}
.dt-fase-titulo{font-size:10px;font-weight:800;letter-spacing:1.5px;text-transform:uppercase;color:var(--text2);margin-bottom:8px;display:flex;align-items:center;gap:8px}
.dt-fase-titulo::after{content:'';flex:1;height:1px;background:var(--border)}
.dt-jogo{background:var(--panel2);border:1.5px solid var(--border);border-radius:12px;overflow:hidden;margin-bottom:8px;transition:border-color .2s}
.dt-jogo.meu{border-color:var(--red)}
.dt-jogo-lado{display:flex;align-items:center;gap:9px;padding:9px 11px;min-width:0}
.dt-jogo-lado + .dt-jogo-lado{border-top:1px solid var(--border)}
.dt-jogo-lado img{width:24px;height:24px;border-radius:6px;object-fit:cover;flex-shrink:0}
.dt-jogo-nome{font-size:12px;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;min-width:0;flex:1}
.dt-jogo-placar{font-size:16px;font-weight:900;font-variant-numeric:tabular-nums;flex-shrink:0;color:var(--text2)}
.dt-jogo-lado.venceu{background:var(--green-soft)}
.dt-jogo-lado.venceu .dt-jogo-nome{color:var(--green)}
.dt-jogo-lado.venceu .dt-jogo-placar{color:var(--green)}
.dt-jogo-lado.perdeu{opacity:.5}
.dt-jogo-esperando{padding:9px 11px;text-align:center;font-size:10px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:var(--text3)}
.dt-jogo-detalhe{border-top:1px solid var(--border);padding:11px;background:var(--panel)}
.dt-ver-mais{width:100%;background:transparent;border:none;border-top:1px solid var(--border);color:var(--text2);font-family:var(--font);font-size:10px;font-weight:700;letter-spacing:1px;text-transform:uppercase;padding:7px;cursor:pointer;transition:.15s}
.dt-ver-mais:hover{color:var(--text);background:var(--panel3)}

.dt-campeao{text-align:center;padding:24px 18px;border-radius:14px;background:linear-gradient(160deg,var(--amber-soft),transparent);border:1.5px solid color-mix(in srgb, var(--amber) 45%, transparent);margin-bottom:14px}
.dt-campeao-coroa{font-size:38px;line-height:1;margin-bottom:8px}
.dt-campeao img{width:64px;height:64px;border-radius:14px;object-fit:cover;margin-bottom:10px}
.dt-campeao-rotulo{font-size:9.5px;font-weight:800;letter-spacing:2px;text-transform:uppercase;color:var(--amber);margin-bottom:4px}
.dt-campeao-nome{font-size:21px;font-weight:900;line-height:1.2;margin-bottom:8px}
.dt-campeao-premio{font-size:12.5px;font-weight:700;color:var(--text2)}
.dt-campeao-premio strong{color:var(--amber)}

@media (max-width:400px){
  .dt-copa-vagas{grid-template-columns:1fr}
  .dt-campeao-nome{font-size:18px}
}

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

/* margin-top serve pra não encostar no botão de cima: colado, o spinner virava
   alvo do toque de quem mirava a borda de baixo do "Copiar link". */
.dt-spinner{width:32px;height:32px;border:3px solid var(--border);border-top-color:var(--red);border-radius:50%;margin:18px auto 14px;animation:dt-spin 1s linear infinite}
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
let ESTADO_INICIAL = { duelo: <?= json_encode($estadoInicial) ?>, copa: <?= json_encode($copaInicial) ?> };
const DT_COPA_APOSTA_PADRAO = 30;
// Copa encerrada que a pessoa já viu e dispensou — mesma ideia do dueloDispensadoId.
let copaDispensadaId = parseInt(localStorage.getItem('dt_copa_dispensada') || '', 10) || null;
let copaAnimadaId = parseInt(localStorage.getItem('dt_copa_animada') || '', 10) || null; // chave cuja animação já rodou
let copaEmAnimacao = false;
let copaTamanhoSel = 4;
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
        <div class="dt-tab" id="dtTabCopa" onclick="dtTrocarTab('copa')">🏆 Copa</div>
        <div class="dt-tab" id="dtTabEntrar" onclick="dtTrocarTab('entrar')">Código</div>
        <div class="dt-tab" id="dtTabCpu" onclick="dtTrocarTab('cpu')">CPU 🤖</div>
      </div>
      <div id="dtTabConteudo"></div>
    </div>
    <div class="dtcard" id="dtRankingCard">
      <div class="dtcard-title"><i class="bi bi-award-fill me-1"></i>Ranking — confronto online</div>
      <div id="dtRankingBody"><p class="dt-empty">Carregando ranking...</p></div>
    </div>
    <?php /* As rivalidades do próprio GM vêm antes das gerais — é o que ele quer
             ver primeiro. O card se esconde sozinho enquanto ele não tiver
             confronto online nenhum (ver dtCarregarRanking). */ ?>
    <div class="dtcard" id="dtMinhasRivalidadesCard" style="display:none">
      <div class="dtcard-title"><i class="bi bi-person-fill me-1"></i>Suas rivalidades</div>
      <div id="dtMinhasRivalidadesBody"></div>
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
    // Suas rivalidades: só aparece pra quem já tem confronto online. Quem nunca
    // jogou contra ninguém não precisa de um card vazio ocupando a tela.
    const minhasCard = document.getElementById('dtMinhasRivalidadesCard');
    const minhasBody = document.getElementById('dtMinhasRivalidadesBody');
    if (minhasCard && minhasBody) {
      const minhas = r.minhas_rivalidades || [];
      minhasCard.style.display = minhas.length ? '' : 'none';
      if (minhas.length) {
        minhasBody.innerHTML = `<div class="dt-ranking-lista">${minhas.map(rv => `
          <div class="dt-ranking-item">
            <span class="dt-ranking-nome" style="${rv.lider === 'eu' ? 'font-weight:900;color:var(--text)' : ''}"><span>Você</span></span>
            <span class="dt-ranking-vd"><b style="${rv.lider === 'eu' ? 'color:var(--green)' : ''}">${rv.minhas}</b> × <b style="${rv.lider === 'rival' ? 'color:var(--green)' : ''}">${rv.dele}</b></span>
            <span class="dt-ranking-nome" style="justify-content:flex-end;${rv.lider === 'rival' ? 'font-weight:900;color:var(--text)' : ''}"><span>${esc(rv.nome_rival)}</span>${dtLogoImg(rv.logo_rival)}</span>
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
  document.getElementById('dtTabCopa').classList.toggle('active', tab === 'copa');
  document.getElementById('dtTabCpu').classList.toggle('active', tab === 'cpu');
  const c = document.getElementById('dtTabConteudo');
  if (tab === 'copa') {
    c.innerHTML = `
      <div class="field" style="margin-bottom:12px">
        <label>Tamanho da copa</label>
        <div class="dt-tabs" style="margin-bottom:0">
          <div class="dt-tab active" id="dtCopaTam4" onclick="dtCopaTamanho(4)">4 times</div>
          <div class="dt-tab" id="dtCopaTam8" onclick="dtCopaTamanho(8)">8 times</div>
        </div>
      </div>
      <div class="field">
        <label>Entrada por jogador (1 a 100 moedas)</label>
        <input type="number" id="dtCopaAposta" min="1" max="100" value="${DT_COPA_APOSTA_PADRAO}" oninput="dtCopaAtualizarPote()">
        <p class="field-hint" id="dtCopaHint"></p>
      </div>
      <button class="btn-dt" id="dtBtnCopaCriar" onclick="dtCopaCriar()"><i class="bi bi-trophy me-2"></i>Abrir copa</button>
      <p class="field-hint" style="margin-top:10px">Mata-mata de verdade: quando a copa enche, todo mundo monta seu titular ao mesmo tempo, a chave é sorteada e os jogos rolam até sobrar um. O campeão leva o pote inteiro.</p>`;
    dtCopaTamanho(copaTamanhoSel);
  } else if (tab === 'criar') {
    c.innerHTML = `
      <div class="field">
        <label>Aposta (1 a 100 moedas)</label>
        <input type="number" id="dtAposta" min="1" max="100" value="20">
        <p class="field-hint">Vale pros dois jeitos abaixo. Debitada na hora e devolvida se ninguém aceitar.</p>
      </div>

      <div class="field" style="margin-top:14px">
        <label>Desafiar alguém direto</label>
        <input type="text" id="dtBuscaOponente" placeholder="Nome da pessoa ou do time..." autocomplete="off" oninput="dtBuscarOponentes()">
        <div id="dtBuscaResultado"></div>
      </div>

      <div class="dt-ou"><span>ou</span></div>

      <button class="btn-dt" id="dtBtnCriar" onclick="dtCriarDuelo()"><i class="bi bi-link-45deg me-2"></i>Criar duelo por link</button>
      <p class="field-hint" style="margin-top:8px">Abre uma sala e gera um link de convite pra mandar pra quem você quiser.</p>`;
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

// ── Desafio direto ──────────────────────────────────────────────────────────
let dtBuscaTimer = null;
let dtBuscaSeq = 0; // descarta resposta de busca antiga que chegou fora de ordem

function dtBuscarOponentes() {
  clearTimeout(dtBuscaTimer);
  // Espera a pessoa parar de digitar: sem isso é uma requisição por tecla.
  dtBuscaTimer = setTimeout(dtBuscarOponentesAgora, 300);
}

async function dtBuscarOponentesAgora() {
  const termo = (document.getElementById('dtBuscaOponente')?.value || '').trim();
  const alvo = document.getElementById('dtBuscaResultado');
  if (!alvo) return;
  if (termo.length < 2) { alvo.innerHTML = ''; return; }

  const seq = ++dtBuscaSeq;
  try {
    const r = await dtPost('buscar_oponentes', { termo });
    if (seq !== dtBuscaSeq) return;
    if (!r.ok || !r.jogadores.length) {
      alvo.innerHTML = `<p class="field-hint" style="margin-top:8px">Ninguém encontrado com esse nome.</p>`;
      return;
    }
    alvo.innerHTML = `<div class="dt-busca-lista">${r.jogadores.map(j => `
      <div class="dt-busca-item">
        ${dtLogoImg(j.time_logo)}
        <div class="dt-busca-txt">
          <div class="dt-busca-nome">${esc(j.time_nome || j.nome)}</div>
          <div class="dt-busca-sub">${j.time_nome ? esc(j.nome) : 'sem time'}${j.ocupado ? ' · em partida' : ''}</div>
        </div>
        <button class="dt-busca-btn" id="dtDesafio${j.id}" ${j.ocupado ? 'disabled' : `onclick="dtDesafiar(${j.id})"`}>
          ${j.ocupado ? 'Ocupado' : 'Desafiar'}
        </button>
      </div>`).join('')}</div>`;
  } catch (e) {
    if (seq === dtBuscaSeq) alvo.innerHTML = `<p class="field-hint" style="margin-top:8px">Falha na busca. Tente de novo.</p>`;
  }
}

async function dtDesafiar(oponente) {
  const aposta = parseInt(document.getElementById('dtAposta').value, 10);
  await dtAcaoBotao(`dtDesafio${oponente}`, 'desafiar', { oponente, aposta });
}

async function dtResponderDesafio(aceitar) {
  await dtAcaoBotao(aceitar ? 'dtBtnAceitar' : 'dtBtnRecusar', 'responder_desafio', { aceitar: aceitar ? '1' : '0' });
}

async function dtRevanche() {
  await dtAcaoBotao('dtBtnRevanche', 'revanche');
}

async function dtCriarDuelo() {
  const aposta = parseInt(document.getElementById('dtAposta').value, 10);
  await dtAcaoBotao('dtBtnCriar', 'criar', { aposta });
}

async function dtEntrarDuelo() {
  const codigo = document.getElementById('dtCodigo').value.trim().toUpperCase();
  if (!codigo) return;
  // O mesmo campo (e o mesmo link de convite) serve pra duelo e pra copa — o
  // servidor diz qual dos dois é aquele código antes de entrar.
  let acao = 'entrar';
  try {
    const r = await dtPost('tipo_codigo', { codigo });
    if (r.ok && r.tipo === 'copa') acao = 'copa_entrar';
  } catch (e) { /* sem resposta, tenta como duelo mesmo */ }
  await dtAcaoBotao('dtBtnEntrar', acao, { codigo });
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
      <button class="btn-dt" id="dtBtnCopiarLink" onclick="dtCopiarLink('${duelo.codigo}', this)"><i class="bi bi-link-45deg me-2"></i>Copiar link do convite</button>
      <p class="dtcard-sub" style="text-align:center;margin-bottom:4px">Aposta: <strong>${duelo.aposta} moedas</strong></p>
      <div class="dt-spinner"></div>
      <p class="dt-empty">Assim que alguém entrar com o código, a tela avança sozinha.</p>
      <button class="btn-dt-ghost" onclick="dtCancelar()"><i class="bi bi-x-circle me-1"></i>Cancelar e receber a aposta de volta</button>
    </div>`;
}

// ── Tela: desafio direto (convite a uma pessoa específica) ──────────────────
function renderConvite(duelo) {
  const nome = dtNomeOponente(duelo);
  const logo = dtLogoOponente(duelo);
  const foto = logo ? `<img class="dt-convite-logo" src="${esc(logo)}" alt="">` : '';

  if (duelo.sou_desafiante) {
    document.getElementById('dtMain').innerHTML = `
      <div class="dtcard dt-convite">
        ${foto}
        <div class="dt-convite-quem">${esc(nome)}</div>
        <div class="dt-convite-txt">Desafio enviado. Assim que aceitarem, o draft começa aqui mesmo.</div>
        <p class="dtcard-sub" style="margin-bottom:4px">Aposta: <strong>${duelo.aposta} moedas</strong></p>
        <div class="dt-spinner"></div>
        <button class="btn-dt-ghost" onclick="dtCancelar()"><i class="bi bi-x-circle me-1"></i>Cancelar e receber a aposta de volta</button>
      </div>`;
    return;
  }

  document.getElementById('dtMain').innerHTML = `
    <div class="dtcard dt-convite">
      ${foto}
      <div class="dt-convite-quem">${esc(nome)}</div>
      <div class="dt-convite-txt">te desafiou pra um Starting5x5 valendo <strong>${duelo.aposta} moedas</strong>. Quem ganhar leva as duas apostas.</div>
      <div class="dt-convite-acoes">
        <button class="btn-dt-ghost" id="dtBtnRecusar" onclick="dtResponderDesafio(false)">Recusar</button>
        <button class="btn-dt" id="dtBtnAceitar" style="margin-top:0" onclick="dtResponderDesafio(true)"><i class="bi bi-check-lg me-1"></i>Aceitar</button>
      </div>
    </div>`;
}

function dtLinkConvite(codigo) {
  const url = new URL(window.location.href);
  url.search = '';
  url.searchParams.set('game', 'dreamteam');
  url.searchParams.set('codigo', codigo);
  return url.toString();
}

// O botão vem por parâmetro (`this` no onclick) porque a mesma função serve o
// duelo e a copa, que têm ids diferentes. Antes o id estava fixo no do duelo:
// na copa o retorno "Link copiado!" nunca aparecia e, sem sinal nenhum, parecia
// que o botão não estava funcionando.
async function dtCopiarLink(codigo, btn = null) {
  const link = dtLinkConvite(codigo);
  if (!btn) btn = document.getElementById('dtBtnCopiarLink');
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
    const r = await dtPost(dtAcao("reposicionar"), { de, para });
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

// Card do time sorteado + botão de girar. Vale pro duelo e pra copa: muda só qual
// ação vai pro servidor (ver dtAcao), então o HTML fica num lugar só.
function dtCardSorteio(meuRoster, timeSorteado, rerollDisponivel, podeAgir) {
  // Guardados aqui (e não no chamador) porque dtCliqueJogador lê os dois — assim
  // duelo e copa não precisam repetir a mesma preparação.
  window.__dtTimeAtual = timeSorteado || null;
  window.__dtVagas = POSICOES.filter(p => !meuRoster[p]);

  if (!timeSorteado) {
    if (!podeAgir) return '';
    return `<div class="dtcard" style="text-align:center">
      <div class="dtcard-title" style="margin-bottom:2px">Sua vez de montar o time</div>
      <p class="dtcard-sub" style="margin-bottom:14px">Gire a roleta pra ver qual escalação histórica aparece — daí escolhe 1 jogador dela.</p>
      <button class="btn-dt" id="dtBtnSortear" onclick="dtSortearTime()"><i class="bi bi-shuffle me-2"></i>Sortear Time</button>
    </div>`;
  }

  const t = timeSorteado;
  const vagas = POSICOES.filter(p => !meuRoster[p]);
  const jaTenho = new Set(Object.values(meuRoster).filter(Boolean).map(j => j.nome));
  window.__dtTimeAtual = timeSorteado;
  window.__dtVagas = vagas;

  return `<div class="dtcard">
    <div class="dt-time-nome">${esc(t.nome)}</div>
    <div class="dt-time-ano">Titular sorteado</div>
    <div class="dt-jogadores-grid" id="dtJogadoresGrid">
      ${t.jogadores.map((j, i) => {
        const jaNoTime = jaTenho.has(j.nome);
        const posDisponiveis = jaNoTime ? [] : j.pos.filter(p => vagas.includes(p));
        const clicavel = podeAgir && posDisponiveis.length > 0;
        return `<div class="dt-jogador-card ${clicavel ? 'clicavel' : 'desabilitado'}" id="dtJog${i}" ${clicavel ? `onclick="dtCliqueJogador(${i})"` : ''}>
          <span class="dt-jogador-nome">${esc(j.nome)}${jaNoTime ? ' <small style="color:var(--text3)">(já no seu time)</small>' : ''}</span>
          <span class="dt-jogador-pos">${j.pos.map(p => `<span class="dt-pos-badge">${p}</span>`).join('')}</span>
          <span class="dt-jogador-ovr" style="color:${dtCorOvr(j.ovr)}">${j.ovr}</span>
        </div>`;
      }).join('')}
    </div>
    <button class="btn-dt-amber" id="dtBtnGirar" onclick="dtGirarDeNovo()" ${(podeAgir && rerollDisponivel) ? '' : 'disabled'}>
      <i class="bi bi-arrow-repeat me-1"></i>${rerollDisponivel ? `Girar de novo (1x ${dtContexto === 'copa' ? 'na copa' : 'no duelo'})` : 'Giro extra já usado'}
    </button>
  </div>`;
}

function renderDraft(duelo) {
  dtContexto = 'duelo';
  const meuTotal = dtSomaRosterJs(duelo.meu_roster);
  const oponenteTotal = dtSomaRosterJs(duelo.oponente_roster);

  let html = dtRenderRoster(dtNomeMeu(duelo), duelo.meu_roster, meuTotal, duelo.minha_vez, dtLogoMeu(duelo));
  html += dtRenderRoster(dtNomeOponente(duelo), duelo.oponente_roster, oponenteTotal, false, dtLogoOponente(duelo));

  html += `<div class="dt-turno-banner ${duelo.minha_vez ? 'minha-vez' : 'vez-oponente'}">
    ${duelo.minha_vez ? '<i class="bi bi-hand-index-thumb"></i> Sua vez — escolha um jogador' : `Vez de ${esc(dtNomeOponente(duelo))}...`}
  </div>`;

  html += dtCardSorteio(duelo.meu_roster, duelo.time_sorteado, duelo.reroll_disponivel, duelo.minha_vez);

  // Saída pra quando o oponente abandona no meio do draft e o duelo trava. Some na simulação
  // (aí o duelo já foi resolvido e pago) e não aparece contra a máquina, que nunca abandona.
  if (duelo.eh_pvp) {
    html += `<button class="btn-dt-ghost" id="dtBtnDesistir" onclick="dtDesistir()">
      <i class="bi bi-flag me-1"></i>Oponente sumiu — encerrar e devolver as apostas
    </button>`;
  }

  document.getElementById('dtMain').innerHTML = html;
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
    const r = await dtPost(dtAcao("escolher"), { jogador: nome, posicao });
    if (!r.ok) { await dtAlerta(r.msg); return; }
    await atualizar();
  } catch (e) {
    // silencioso
  } finally {
    processando = false;
  }
}

/**
 * Duelo e copa usam a mesma tela de montagem (dtCardSorteio, dtRenderRoster,
 * dtCliqueJogador) — o que muda é qual ação vai pro servidor. Em vez de duplicar
 * o HTML, quem renderiza define o contexto e as funções abaixo despacham.
 */
let dtContexto = 'duelo';
function dtAcao(nome) {
  const mapa = {
    duelo: { sortear: 'sortear_time', girar: 'girar_de_novo', escolher: 'escolher_jogador', reposicionar: 'reposicionar_jogador' },
    copa:  { sortear: 'copa_sortear',  girar: 'copa_girar',    escolher: 'copa_escolher',    reposicionar: 'copa_reposicionar' },
  };
  return mapa[dtContexto][nome];
}

async function dtGirarDeNovo() {
  await dtAcaoBotao('dtBtnGirar', dtAcao('girar'));
}

async function dtSortearTime() {
  await dtAcaoBotao('dtBtnSortear', dtAcao('sortear'));
}

async function dtDesistir() {
  if (!(await dtConfirmar('Encerrar o duelo? Os dois recebem a aposta de volta.'))) return;
  await dtAcaoBotao('dtBtnDesistir', 'desistir');
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

  // "Menu" e não "Jogar de novo": o botão volta pra tela inicial, não puxa outra
  // partida — quem quer jogar de novo contra o mesmo oponente usa a revanche ao lado.
  //
  // Revanche: mesma dupla, mesma aposta, sem passar pelo lobby. Precisa dos dois,
  // então enquanto só um clicou o lugar do botão vira o aviso de que está esperando.
  // Quando o segundo aceita, o duelo novo nasce e o poll leva os dois pro draft.
  let acaoRevanche = '';
  if (duelo.revanche_disponivel) {
    acaoRevanche = duelo.revanche_eu
      ? `<div class="dt-revanche-status"><i class="bi bi-hourglass-split me-1"></i>Aguardando ${nomeOp}...</div>`
      : `<button class="btn-dt-amber" id="dtBtnRevanche" onclick="dtRevanche()" style="margin-top:0">
           <i class="bi bi-fire me-1"></i>${duelo.revanche_oponente ? 'Aceitar revanche' : 'Revanche'} · ${duelo.aposta}
         </button>`;
  }

  let html = `<div class="dt-acoes-fim">
    <button class="btn-dt-ghost" onclick="dtNovoDuelo(${duelo.id})" style="margin-top:0"><i class="bi bi-list me-1"></i>Menu</button>
    ${acaoRevanche}
  </div>`;

  html += `<div class="dtcard">
    <div class="dt-resultado-msg ${duelo.eu_venci ? 'venceu' : 'perdeu'}">
      ${duelo.eu_venci ? `🏆 Você venceu! +${duelo.aposta * 2} moedas` : `Você perdeu essa. -${duelo.aposta} moedas`}
    </div>
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
  ESTADO_INICIAL = { duelo: null, copa: null };
  renderCriarEntrar();
}

/** Sai da tela da copa encerrada e libera a pessoa pra entrar em outra. */
async function dtCopaSairEncerrada(copaId) {
  copaDispensadaId = copaId;
  localStorage.setItem('dt_copa_dispensada', String(copaId));
  ESTADO_INICIAL = { duelo: null, copa: null };
  renderCriarEntrar();
  try { await dtPost('copa_sair_encerrada'); } catch (e) { /* a tela já saiu; o servidor limpa no próximo acesso */ }
}

// ── Copa (mata-mata de 4 ou 8) ──────────────────────────────────────────────
function dtCopaTamanho(n) {
  copaTamanhoSel = n;
  document.getElementById('dtCopaTam4')?.classList.toggle('active', n === 4);
  document.getElementById('dtCopaTam8')?.classList.toggle('active', n === 8);
  dtCopaAtualizarPote();
}

function dtCopaAtualizarPote() {
  const hint = document.getElementById('dtCopaHint');
  if (!hint) return;
  const aposta = parseInt(document.getElementById('dtCopaAposta')?.value, 10);
  if (!Number.isFinite(aposta) || aposta < 1) { hint.textContent = 'Informe a entrada.'; return; }
  hint.innerHTML = `Debitada ao entrar. Com ${copaTamanhoSel} jogadores o campeão leva <strong>${aposta * copaTamanhoSel} moedas</strong>.`;
}

async function dtCopaCriar() {
  const aposta = parseInt(document.getElementById('dtCopaAposta').value, 10);
  await dtAcaoBotao('dtBtnCopaCriar', 'copa_criar', { tamanho: copaTamanhoSel, aposta });
}

async function dtCopaSair() {
  const msg = 'Sair da copa? Sua entrada volta pra você.';
  if (!(await dtConfirmar(msg))) return;
  await dtAcaoBotao('dtBtnCopaSair', 'copa_sair');
}

function renderCopa(copa) {
  if (copa.status === 'aguardando') { renderCopaLobby(copa); return; }
  if (copa.status === 'draft') { renderCopaDraft(copa); return; }
  if (copa.status === 'encerrada') { renderCopaChave(copa); return; }
  renderCriarEntrar();
}

/** Sala de espera: quem já entrou, quantas vagas faltam e o link pra chamar gente. */
function renderCopaLobby(copa) {
  const vagas = [];
  copa.participantes.forEach(p => {
    const eu = p.user_id === MEU_USER_ID;
    vagas.push(`<div class="dt-copa-vaga${eu ? ' eu' : ''}">
      ${dtLogoImg(p.logo)}<span class="dt-copa-vaga-nome">${esc(p.nome)}${eu ? ' (você)' : ''}</span>
    </div>`);
  });
  for (let i = 0; i < copa.vagas; i++) {
    vagas.push(`<div class="dt-copa-vaga livre"><i class="bi bi-hourglass me-1"></i>vaga aberta</div>`);
  }

  document.getElementById('dtMain').innerHTML = `
    <div class="dtcard">
      <div class="dtcard-title"><i class="bi bi-trophy me-1"></i>Copa de ${copa.tamanho} — sala de espera</div>
      <div class="dt-copa-pote">
        <strong>${copa.pote}</strong><span>moedas pro campeão</span>
      </div>
      <div class="dt-copa-vagas">${vagas.join('')}</div>
      <div class="dt-codigo-box">
        <div class="dt-codigo-valor">${esc(copa.codigo)}</div>
        <div class="dt-codigo-label">Código da copa</div>
      </div>
      <button class="btn-dt" id="dtBtnCopaLink" onclick="dtCopiarLink('${copa.codigo}', this)"><i class="bi bi-link-45deg me-2"></i>Copiar link do convite</button>
      <div class="dt-spinner"></div>
      <p class="dt-empty">${copa.vagas === 1 ? 'Falta 1 pessoa' : `Faltam ${copa.vagas} pessoas`} pra começar. Assim que encher, todo mundo monta o time ao mesmo tempo.</p>
      <button class="btn-dt-ghost" id="dtBtnCopaSair" onclick="dtCopaSair()">
        <i class="bi bi-x-circle me-1"></i>${copa.sou_criador ? 'Cancelar a copa e devolver as entradas' : 'Sair e receber a entrada de volta'}
      </button>
    </div>`;
}

/** Draft da copa: cada um monta o seu, no próprio ritmo, com prazo comum. */
function renderCopaDraft(copa) {
  dtContexto = 'copa';
  const prontos = copa.participantes.filter(p => p.pronto).length;
  // Os adversários com o time deles se formando ao vivo. O próprio time fica de
  // fora daqui — aparece logo abaixo, em tamanho grande e com o reposicionamento.
  const outros = copa.participantes.filter(p => p.user_id !== MEU_USER_ID);
  let html = `<div class="dtcard" style="margin-bottom:10px">
    <div class="dtcard-title" style="margin-bottom:8px"><i class="bi bi-people me-1"></i>Copa de ${copa.tamanho} — montando os times</div>
    <div class="dt-copa-timer" id="dtCopaTimer"></div>
    <div class="dt-copa-rivais">
      ${outros.map(p => `
        <div class="dt-copa-rival">
          <div class="dt-copa-rival-topo">
            ${dtLogoImg(p.logo)}
            <span class="dt-copa-rival-nome">${esc(p.nome)}</span>
            <span class="dt-copa-rival-ovr">${p.ovr || 0}</span>
            <span class="dt-copa-pronto ${p.pronto ? 'sim' : 'nao'}">${p.pronto ? 'pronto' : 'montando'}</span>
          </div>
          <div class="dt-copa-mini">
            ${POSICOES.map(pos => {
              const j = p.roster ? p.roster[pos] : null;
              return `<div class="dt-copa-mini-slot${j ? ' cheio' : ''}">
                <span class="dt-copa-mini-pos">${pos}</span>
                ${j ? `<span class="dt-copa-mini-nome">${esc(j.nome.split(' ').slice(-1)[0])}</span>
                       <span class="dt-copa-mini-ovr" style="color:${dtCorOvr(j.ovr)}">${j.ovr}</span>` : ''}
              </div>`;
            }).join('')}
          </div>
        </div>`).join('')}
    </div>
    <p class="dt-empty" style="margin-top:10px">${prontos} de ${copa.tamanho} já fecharam o time.</p>
  </div>`;

  html += dtRenderRoster('Seu time', copa.meu_roster, dtSomaRosterJs(copa.meu_roster), !copa.estou_pronto, copa.participantes.find(p => p.user_id === MEU_USER_ID)?.logo || null);

  if (copa.estou_pronto) {
    html += `<div class="dtcard" style="text-align:center">
      <div class="dtcard-title" style="margin-bottom:6px">Time fechado</div>
      <p class="dtcard-sub" style="margin-bottom:0">Agora é esperar os outros. Quando o último fechar (ou o prazo acabar), a chave é sorteada e os jogos começam.</p>
      <div class="dt-spinner"></div>
    </div>`;
  } else {
    html += dtCardSorteio(copa.meu_roster, copa.time_sorteado, copa.reroll_disponivel, true);
  }

  document.getElementById('dtMain').innerHTML = html;
  dtCopaTicTimer(copa.segundos_restantes);
}

/**
 * Conta o prazo do draft no cliente, de 1 em 1 segundo. Fica fora do estado que
 * o poll compara (ver atualizar) — senão o cronômetro sozinho já mudaria o hash
 * a cada 3s e a tela seria reconstruída no meio da escolha de alguém.
 */
function dtCopaTicTimer(segundos) {
  clearInterval(window.__dtCopaTimer);
  if (segundos === null || segundos === undefined) return;
  let restam = Math.max(0, segundos);
  const pinta = () => {
    const el = document.getElementById('dtCopaTimer');
    if (!el) { clearInterval(window.__dtCopaTimer); return; }
    const m = Math.floor(restam / 60), s = restam % 60;
    el.textContent = restam > 0
      ? `Prazo pra montar: ${m}:${String(s).padStart(2, '0')}`
      : 'Prazo encerrado — fechando a chave...';
    el.classList.toggle('urgente', restam <= 60);
    if (restam-- <= 0) clearInterval(window.__dtCopaTimer);
  };
  pinta();
  window.__dtCopaTimer = setInterval(pinta, 1000);
}

// ── Chave ───────────────────────────────────────────────────────────────────
function dtCopaJogoHtml(p, faseIdx, jogoIdx, revelado) {
  const r = p.resultado;
  const souEu = p.a.user_id === MEU_USER_ID || p.b.user_id === MEU_USER_ID;
  const vencA = revelado && r.vencedor === 'a';
  const vencB = revelado && r.vencedor === 'b';
  const lado = (t, placar, venceu, perdeu) => `
    <div class="dt-jogo-lado ${venceu ? 'venceu' : (perdeu ? 'perdeu' : '')}">
      ${dtLogoImg(t.logo)}
      <span class="dt-jogo-nome">${esc(t.nome)}</span>
      <span class="dt-jogo-placar">${revelado ? placar : '–'}</span>
    </div>`;
  return `<div class="dt-jogo${souEu ? ' meu' : ''}" id="dtJogo${faseIdx}_${jogoIdx}">
    ${lado(p.a, r.placar_a, vencA, revelado && !vencA)}
    ${lado(p.b, r.placar_b, vencB, revelado && !vencB)}
    ${revelado ? `<button class="dt-ver-mais" onclick="dtCopaVerJogo(${faseIdx},${jogoIdx})">ver o jogo</button>
      <div class="dt-jogo-detalhe" id="dtDet${faseIdx}_${jogoIdx}" style="display:none"></div>` : ''}
  </div>`;
}

function dtCopaVerJogo(faseIdx, jogoIdx) {
  const box = document.getElementById(`dtDet${faseIdx}_${jogoIdx}`);
  if (!box) return;
  if (box.style.display !== 'none') { box.style.display = 'none'; return; }
  const p = window.__dtChave.fases[faseIdx].partidas[jogoIdx];
  const r = p.resultado;
  const qa = r.quartos.map(q => q.a);
  const qb = r.quartos.map(q => q.b);
  const linha = (t, meus, outros, total, venceu) => `
    <tr>
      <td class="dt-qt-time"><span class="dt-qt-time-wrap">${dtLogoImg(t.logo)}<span>${esc(t.nome)}</span></span></td>
      ${meus.map((q, i) => `<td class="${q > outros[i] ? 'dt-qt-venceu' : ''}">${q}</td>`).join('')}
      <td class="dt-qt-total ${venceu ? 'liderando' : ''}">${total}</td>
    </tr>`;
  box.innerHTML = `
    <table class="dt-qt-tabela">
      <thead><tr><th>Time</th><th>Q1</th><th>Q2</th><th>Q3</th><th>Q4</th><th class="dt-qt-col-total">Total</th></tr></thead>
      <tbody>
        ${linha(p.a, qa, qb, r.placar_a, r.vencedor === 'a')}
        ${linha(p.b, qb, qa, r.placar_b, r.vencedor === 'b')}
      </tbody>
    </table>
    ${dtRenderBoxscore(p.a.nome, r.boxscore_a)}
    ${dtRenderBoxscore(p.b.nome, r.boxscore_b)}`;
  box.style.display = '';
}

function dtCopaChaveHtml(chave, revelados) {
  return `<div class="dt-chave">${chave.fases.map((f, fi) => {
    // Fase só aparece depois que a anterior terminou de ser revelada.
    const jogosRevelados = revelados[fi] ?? 0;
    if (fi > 0 && jogosRevelados === 0 && (revelados[fi - 1] ?? 0) < chave.fases[fi - 1].partidas.length) return '';
    return `<div>
      <div class="dt-fase-titulo">${esc(f.nome)}</div>
      ${f.partidas.map((p, pi) => dtCopaJogoHtml(p, fi, pi, pi < jogosRevelados)).join('')}
    </div>`;
  }).join('')}</div>`;
}

function renderCopaChave(copa) {
  const chave = copa.chave;
  if (!chave) { renderCriarEntrar(); return; }
  window.__dtChave = chave;

  const jaViu = copa.id === copaAnimadaId;
  if (jaViu) { dtCopaPintarFinal(copa, chave); return; }
  if (copaEmAnimacao) return;
  dtCopaAnimar(copa, chave);
}

/** Revela a chave jogo a jogo, fase a fase — é o "assistir a copa acontecer". */
async function dtCopaAnimar(copa, chave) {
  copaEmAnimacao = true;
  const revelados = chave.fases.map(() => 0);
  const pintar = () => {
    document.getElementById('dtMain').innerHTML = `
      <div class="dtcard" style="margin-bottom:12px">
        <div class="dtcard-title" style="margin-bottom:2px"><i class="bi bi-trophy me-1"></i>Copa de ${copa.tamanho}</div>
        <p class="dtcard-sub" style="margin-bottom:0">${copa.pote} moedas em jogo. Acompanhe a chave.</p>
      </div>
      ${dtCopaChaveHtml(chave, revelados)}`;
  };
  pintar();
  await new Promise(r => setTimeout(r, 700));

  for (let fi = 0; fi < chave.fases.length; fi++) {
    for (let pi = 0; pi < chave.fases[fi].partidas.length; pi++) {
      revelados[fi] = pi + 1;
      pintar();
      const meu = chave.fases[fi].partidas[pi];
      const souEu = meu.a.user_id === MEU_USER_ID || meu.b.user_id === MEU_USER_ID;
      document.getElementById(`dtJogo${fi}_${pi}`)?.scrollIntoView({ behavior: 'smooth', block: 'center' });
      // O próprio jogo respira um pouco mais — é o que a pessoa quer ver.
      await new Promise(r => setTimeout(r, souEu ? 1500 : 850));
    }
    await new Promise(r => setTimeout(r, 500));
  }

  copaAnimadaId = copa.id;
  localStorage.setItem('dt_copa_animada', String(copa.id));
  copaEmAnimacao = false;
  dtCopaPintarFinal(copa, chave);
}

function dtCopaPintarFinal(copa, chave) {
  const revelados = chave.fases.map(f => f.partidas.length);
  const c = chave.campeao;
  const logo = c.logo ? `<img src="${esc(c.logo)}" alt="">` : '';
  document.getElementById('dtMain').innerHTML = `
    <div class="dt-acoes-fim">
      <button class="btn-dt-ghost" onclick="dtCopaSairEncerrada(${copa.id})" style="margin-top:0"><i class="bi bi-list me-1"></i>Menu</button>
    </div>
    <div class="dt-campeao">
      <div class="dt-campeao-coroa">🏆</div>
      ${logo}
      <div class="dt-campeao-rotulo">${copa.sou_campeao ? 'Você é o campeão' : 'Campeão da copa'}</div>
      <div class="dt-campeao-nome">${esc(c.nome)}</div>
      <div class="dt-campeao-premio">${copa.sou_campeao
        ? `Levou <strong>+${chave.pote} moedas</strong>`
        : `Levou <strong>${chave.pote} moedas</strong>`}</div>
    </div>
    ${dtCopaChaveHtml(chave, revelados)}`;
}

// Quem chegou por um link de convite quer ENTRAR em algo, não rever o que já
// acabou. Sem isso a pessoa clicava no link e caía na tela do último jogo dela,
// parecendo que o link não funcionou. Partida/copa em andamento continua vindo
// na frente — aí ela realmente precisa terminar antes.
//
// Vale SÓ na primeira renderização. Quando era permanente, a partida/copa que
// terminava com a pessoa jogando também caía nessa regra: no fim da copa a tela
// pulava a chave e mandava todo mundo que tinha entrado pelo link pro menu,
// sem simulação, sem campeão, sem nada.
let dtLinkPendente = !!new URLSearchParams(window.location.search).get('codigo');
function dtEncerrado(status) {
  return status === 'simulado' || status === 'encerrada';
}

// ── Orquestração ─────────────────────────────────────────────────────────────
// A copa tem prioridade sobre o duelo: são telas alternativas e o servidor não
// deixa a pessoa estar nas duas, então quando existe copa ativa é ela que manda.
function renderTela(estado) {
  const duelo = estado ? estado.duelo : null;
  const copa = estado ? estado.copa : null;
  // Consome a marca do link aqui: a partir da segunda renderização o que termina
  // com a pessoa jogando tem que aparecer normalmente.
  const veioDeLink = dtLinkPendente;
  dtLinkPendente = false;

  if (copa && copa.status === 'encerrada' && (copa.id === copaDispensadaId || veioDeLink)) {
    renderTelaDuelo(duelo, veioDeLink);
    return;
  }
  if (copa) { renderCopa(copa); return; }
  renderTelaDuelo(duelo, veioDeLink);
}

function renderTelaDuelo(duelo, veioDeLink = false) {
  if (!duelo) { renderCriarEntrar(); return; }
  if (dtEncerrado(duelo.status) && veioDeLink) { renderCriarEntrar(); return; }
  if (duelo.status === 'simulado' && duelo.id === dueloDispensadoId) { renderCriarEntrar(); return; }
  if (duelo.status === 'aguardando') { renderAguardando(duelo); return; }
  if (duelo.status === 'convite') { renderConvite(duelo); return; }
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
    const estado = { duelo: r.duelo, copa: r.copa };
    // O cronômetro do draft da copa muda a cada segundo; fora do hash pra não
    // forçar re-render de 3 em 3 segundos só por causa dele (ver dtCopaTicTimer).
    const hash = JSON.stringify(estado, (k, v) => k === 'segundos_restantes' ? undefined : v);
    if (hash === ultimoEstadoHash && !forcar) return;
    ultimoEstadoHash = hash;
    renderTela(estado);
  } catch (e) { /* silencioso — próximo poll tenta de novo */ }
}

renderTela(ESTADO_INICIAL);
// Arrow em vez de passar `atualizar` direto: o setInterval não deve empurrar argumento nenhum
// pro parâmetro `forcar`.
setInterval(() => atualizar(), 3000);
</script>

</body>
</html>
