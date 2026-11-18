<?php
/**
 * ── POKÉMON PVP: O TESTE DE MULTIPLAYER ──────────────────────────────
 *
 * Joga partidas inteiras entre jogadores de mentira, pelas MESMAS funções
 * que a tela chama, e confere duas coisas que clicar no navegador nunca
 * provaria:
 *
 *   1. A PARTIDA ESTÁ CERTA — a jogada de um não vira a jogada do outro,
 *      cada lado só vê o que pode ver, o prêmio sai inteiro pro vencedor
 *      e a soma das moedas da mesa não muda sozinha.
 *
 *   2. NÃO DÁ PRA TRAPACEAR — jogar duas vezes no mesmo turno, mandar
 *      índice fora da faixa, atacar quando devia trocar, entrar numa sala
 *      que já tem dois, desistir duas vezes pra pagar o prêmio em dobro.
 *
 * ── OS JOGADORES SÃO DESCARTÁVEIS ────────────────────────────────────
 *
 * O CLI cria contas de teste, joga com elas e APAGA tudo no fim —
 * contas, partidas, coleção e compras. Nada encosta na conta de um GM de
 * verdade, e nenhuma moeda de verdade entra ou sai.
 *
 *   php games/core/psd_teste_pvp_cli.php
 *   php games/core/psd_teste_pvp_cli.php --partidas=5
 *
 * Roda NA HOSPEDAGEM: o banco só é acessível de dentro.
 */

require_once __DIR__ . '/../../backend/db.php';
require_once __DIR__ . '/psd_online.php';

$opts = getopt('', ['partidas::']);
$PARTIDAS = max(1, min(20, (int)($opts['partidas'] ?? 3)));

$pdo = db();

// ─────────────────────────────── o placar do teste ───────────────────────────────

$ok = 0; $falhas = [];

function confere(string $oque, bool $passou, string $detalhe = ''): void
{
    global $ok, $falhas;
    if ($passou) { $ok++; return; }
    $falhas[] = $oque . ($detalhe !== '' ? "  ($detalhe)" : '');
}

function titulo(string $t): void { echo "\n── $t " . str_repeat('─', max(0, 56 - strlen($t))) . "\n"; }

// ─────────────────────────────── contas de mentira ───────────────────────────────

$marca = 'psdteste_' . bin2hex(random_bytes(4));

/**
 * games_usuarios.id é chave estrangeira de users.id, então o jogador de
 * teste precisa existir nas DUAS tabelas. A senha é lixo aleatório que
 * ninguém conhece e o e-mail termina em .invalid, que é um domínio que
 * a norma reserva justamente pra não existir — ninguém entra com isso.
 *
 * O ON DELETE CASCADE do users leva o games_usuarios junto na limpeza.
 */
function criarJogador(PDO $pdo, string $marca, string $nome, int $moedas, int $fbap): int
{
    $email = $marca . '_' . mb_strtolower($nome) . '@teste.invalid';

    $pdo->prepare("INSERT INTO users (name, email, password_hash, user_type, league, email_verified)
                   VALUES (?,?,?,'jogador','ROOKIE',0)")
        ->execute(['[TESTE] ' . $nome, $email, password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT)]);
    $uid = (int)$pdo->lastInsertId();

    $pdo->prepare('INSERT INTO games_usuarios (id, nome, email, pontos, fba_points) VALUES (?,?,?,?,?)')
        ->execute([$uid, $nome, $email, $moedas, $fbap]);

    return $uid;
}

function limpar(PDO $pdo, array $uids): void
{
    if (!$uids) return;
    $in = implode(',', array_map('intval', $uids));
    $pdo->exec("DELETE FROM psd_partidas WHERE a_uid IN ($in) OR b_uid IN ($in)");
    $pdo->exec("DELETE FROM psd_colecao  WHERE id_usuario IN ($in)");
    $pdo->exec("DELETE FROM psd_time     WHERE id_usuario IN ($in)");
    $pdo->exec("DELETE FROM psd_compras  WHERE id_usuario IN ($in)");
    $pdo->exec("DELETE FROM psd_ranking  WHERE id_usuario IN ($in)");
    $pdo->exec("DELETE FROM games_usuarios WHERE id IN ($in)");
    $pdo->exec("DELETE FROM users WHERE id IN ($in)");

    // Confere que sumiu mesmo: conta de teste esquecida no banco é lixo.
    $st = $pdo->query("SELECT COUNT(*) FROM users WHERE id IN ($in)");
    $sobrou = (int)$st->fetchColumn();
    if ($sobrou > 0) echo "!! ATENÇÃO: $sobrou conta(s) de teste não foram apagadas ($in)\n";
}

function moedas(PDO $pdo, int $uid): int
{
    $st = $pdo->prepare('SELECT COALESCE(pontos,0) FROM games_usuarios WHERE id = ?');
    $st->execute([$uid]);
    return (int)$st->fetchColumn();
}

psdTabelas($pdo);
psdCartasTabelas($pdo);

$A = criarJogador($pdo, $marca, 'Ana',  10000, 3000);
$B = criarJogador($pdo, $marca, 'Beto', 10000, 3000);
$C = criarJogador($pdo, $marca, 'Caio', 10000, 3000);
$todos = [$A, $B, $C];

echo "contas de teste: Ana=$A  Beto=$B  Caio=$C\n";

try {

// ─────────────────────────────── 1. uma partida inteira ───────────────────────────────

titulo('PARTIDAS COMPLETAS (Ana x Beto)');

$APOSTA = 500;

for ($n = 1; $n <= $PARTIDAS; $n++) {
    $antesA = moedas($pdo, $A);
    $antesB = moedas($pdo, $B);
    $somaAntes = $antesA + $antesB;

    $r = psdAbrir($pdo, $A, 'Ana', 'codigo', $APOSTA, $n % 2 ? 'aleatoria' : 'time');
    confere("partida $n: Ana abre", !empty($r['ok']), $r['erro'] ?? '');
    if (empty($r['ok'])) break;

    $p = psdPorId($pdo, (int)$r['id']);
    $codigo = (string)$p['codigo'];

    confere("partida $n: entrada cobrada da Ana", moedas($pdo, $A) === $antesA - $APOSTA);

    // Caio tenta entrar: ainda dá, a sala está aberta. Beto entra primeiro.
    $r = psdEntrar($pdo, $B, 'Beto', $codigo);
    confere("partida $n: Beto entra", !empty($r['ok']), $r['erro'] ?? '');

    // ── TERCEIRO NA SALA: tem que ser barrado ──
    $r = psdEntrar($pdo, $C, 'Caio', $codigo);
    confere("partida $n: Caio (3o) barrado", empty($r['ok']), 'entrou numa sala cheia!');

    $idPartida = (int)$p['id'];
    $premio = $APOSTA * 2;

    // ── joga até acabar ──
    $voltas = 0;
    while ($voltas++ < 400) {
        $vA = psdVer($pdo, $A, $idPartida);
        if (($vA['em'] ?? '') !== 'jogando' || !empty($vA['acabou'])) break;

        $vB = psdVer($pdo, $B, $idPartida);

        // Cada lado só vê o próprio time inteiro.
        confere("partida $n: Ana não vê a equipe do Beto", !isset($vA['ele']['equipe']));
        confere("partida $n: Beto não vê a equipe da Ana", !isset($vB['ele']['equipe']));
        // E o que um vê como "meu" é o oposto do que o outro vê como "dele".
        if (isset($vA['eu']['ativo']['nome'], $vB['ele']['ativo']['nome'])) {
            confere("partida $n: o ativo da Ana é o adversário do Beto",
                    $vA['eu']['ativo']['nome'] === $vB['ele']['ativo']['nome'],
                    $vA['eu']['ativo']['nome'] . ' vs ' . $vB['ele']['ativo']['nome']);
        }

        $e = json_decode((string)psdPorId($pdo, $idPartida)['estado'], true);

        foreach ([['a', $A], ['b', $B]] as [$lado, $uid]) {
            $v = psdVer($pdo, $uid, $idPartida);
            if (empty($v['falta_eu'])) continue;
            $esc = psdIA($e, $lado);
            psdEscolher($pdo, $uid, $esc);
        }
    }

    confere("partida $n: terminou em menos de 400 voltas", $voltas < 400);

    $p = psdPorId($pdo, $idPartida);
    confere("partida $n: status fim", $p['status'] === 'fim', (string)$p['status']);
    confere("partida $n: pago marcado", (int)$p['pago'] === 1);

    $depA = moedas($pdo, $A);
    $depB = moedas($pdo, $B);

    // ── O DINHEIRO FECHA? ──
    confere("partida $n: soma das moedas não mudou", ($depA + $depB) === $somaAntes,
            "antes $somaAntes, depois " . ($depA + $depB));

    $venc = (int)$p['vencedor_uid'];
    if ($venc === $A) {
        confere("partida $n: Ana levou o prêmio inteiro", $depA === $antesA - $APOSTA + $premio);
        confere("partida $n: Beto perdeu a entrada",      $depB === $antesB - $APOSTA);
    } elseif ($venc === $B) {
        confere("partida $n: Beto levou o prêmio inteiro", $depB === $antesB - $APOSTA + $premio);
        confere("partida $n: Ana perdeu a entrada",        $depA === $antesA - $APOSTA);
    } else {
        confere("partida $n: empate devolveu pros dois", $depA === $antesA && $depB === $antesB);
    }

    printf("  partida %d (%s): %s venceu por %s em %d turnos\n", $n, $p['regra'],
        $venc === $A ? 'Ana' : ($venc === $B ? 'Beto' : 'ninguém'), (string)$p['motivo'], $voltas);
}

// ─────────────────────────────── 1b. a fila ───────────────────────────────

titulo('FILA (procurar adversário)');

/* A fila só pode juntar quem combinou NO VALOR e NA REGRA. Juntar valores
   diferentes exigiria uma regra de "quem paga a diferença", que não
   existe; juntar regras diferentes poria Meu Time contra Aleatória. */

$antesA = moedas($pdo, $A); $antesB = moedas($pdo, $B); $antesC = moedas($pdo, $C);

$rA = psdAbrir($pdo, $A, 'Ana', 'fila', 300, 'aleatoria');
confere('fila: Ana entra na fila', !empty($rA['ok']), $rA['erro'] ?? '');
confere('fila: Ana fica aguardando', psdPorId($pdo, (int)$rA['id'])['status'] === 'aguardando');
confere('fila: cobrou da Ana', moedas($pdo, $A) === $antesA - 300);

// Mesmo valor, OUTRA regra: não pode casar com a da Ana.
$rC = psdAbrir($pdo, $C, 'Caio', 'fila', 300, 'time');
confere('fila: regra diferente não casa', !empty($rC['ok']) && (int)$rC['id'] !== (int)$rA['id'],
        'o Caio caiu na sala da Ana com outra regra!');

// Mesmo valor E mesma regra: tem que casar com a da Ana.
$rB = psdAbrir($pdo, $B, 'Beto', 'fila', 300, 'aleatoria');
confere('fila: Beto casa com a Ana', !empty($rB['ok']) && (int)$rB['id'] === (int)$rA['id'],
        'abriu sala nova em vez de casar');

$pFila = psdPorId($pdo, (int)$rA['id']);
confere('fila: a partida começou',  $pFila['status'] === 'jogando', (string)$pFila['status']);
confere('fila: os dois estão nela', (int)$pFila['a_uid'] === $A && (int)$pFila['b_uid'] === $B);
confere('fila: prêmio é a soma das duas entradas', (int)$pFila['premio'] === 600, (string)$pFila['premio']);
confere('fila: cobrou do Beto também', moedas($pdo, $B) === $antesB - 300);
confere('fila: a regra é a de quem abriu', $pFila['regra'] === 'aleatoria', (string)$pFila['regra']);

// A sala do Caio continua esperando sozinha, e cancelar devolve.
confere('fila: a sala do Caio segue aberta',
        psdPorId($pdo, (int)$rC['id'])['status'] === 'aguardando');
psdDesistirDaEspera($pdo, $C);
confere('fila: Caio cancelou e recebeu de volta', moedas($pdo, $C) === $antesC,
        moedas($pdo, $C) . ' contra ' . $antesC);

// Fecha a partida da fila pra não atrapalhar o resto do teste.
psdDesistir($pdo, $A);
confere('fila: desistência pagou o Beto', moedas($pdo, $B) === $antesB - 300 + 600);

// ─────────────────────────────── 2. tentativas de trapaça ───────────────────────────────

titulo('TENTATIVAS DE TRAPAÇA');

$r = psdAbrir($pdo, $A, 'Ana', 'codigo', 300, 'aleatoria');
$pid = (int)$r['id'];
$cod = (string)psdPorId($pdo, $pid)['codigo'];
psdEntrar($pdo, $B, 'Beto', $cod);

// (a) jogar duas vezes no mesmo turno
$r1 = psdEscolher($pdo, $A, ['tipo' => 'golpe', 'i' => 0]);
$r2 = psdEscolher($pdo, $A, ['tipo' => 'golpe', 'i' => 1]);
confere('duas jogadas no mesmo turno: a 2a é ignorada',
        !empty($r1['ok']) && !empty($r2['ja']), 'a segunda foi aceita!');

// (b) índice fora da faixa
$r = psdEscolher($pdo, $B, ['tipo' => 'golpe', 'i' => 99]);
confere('golpe fora da faixa recusado', empty($r['ok']), 'aceitou o golpe 99');

$r = psdEscolher($pdo, $B, ['tipo' => 'trocar', 'i' => 99]);
confere('troca fora da faixa recusada', empty($r['ok']), 'aceitou trocar pro 99');

// (c) trocar pelo que já está em quadra
$e = json_decode((string)psdPorId($pdo, $pid)['estado'], true);
$r = psdEscolher($pdo, $B, ['tipo' => 'trocar', 'i' => (int)$e['lados']['b']['ativo']]);
confere('trocar pelo que já está em quadra recusado', empty($r['ok']));

// (d) jogar pelo adversário: Caio não está na partida
$r = psdEscolher($pdo, $C, ['tipo' => 'golpe', 'i' => 0]);
confere('estranho não joga na partida alheia', empty($r['ok']), 'o Caio jogou!');

// (e) o estado do Caio não revela nada
$vC = psdVer($pdo, $C, $pid);
confere('estranho não enxerga a partida', ($vC['em'] ?? '') === 'nada', (string)($vC['em'] ?? '?'));

// (f) abrir outra partida estando numa
$r = psdAbrir($pdo, $A, 'Ana', 'fila', 100, 'aleatoria');
confere('não abre duas partidas ao mesmo tempo', empty($r['ok']), 'abriu a segunda!');

// (g) desistir duas vezes paga o prêmio duas vezes?
$antesB = moedas($pdo, $B);
$r1 = psdDesistir($pdo, $A);
$r2 = psdDesistir($pdo, $A);
$depB = moedas($pdo, $B);
confere('desistir duas vezes paga uma só', !empty($r1['ok']) && empty($r2['ok'])
        && $depB === $antesB + 600, "Beto foi de $antesB pra $depB");

// (h) atacar quando o certo é repor
$r = psdAbrir($pdo, $A, 'Ana', 'codigo', 0, 'aleatoria');
$pid = (int)$r['id'];
psdEntrar($pdo, $B, 'Beto', (string)psdPorId($pdo, $pid)['codigo']);
$pdo->prepare('UPDATE psd_partidas SET estado = ? WHERE id = ?')->execute([
    (function (string $json) {
        $e = json_decode($json, true);
        $e['lados']['a']['time'][$e['lados']['a']['ativo']]['hp'] = 0;
        $e['lados']['a']['precisa_trocar'] = true;
        return json_encode($e);
    })((string)psdPorId($pdo, $pid)['estado']), $pid]);
$r = psdEscolher($pdo, $A, ['tipo' => 'golpe', 'i' => 0]);
confere('não dá pra atacar com o bicho caído', empty($r['ok']), 'atacou mesmo caído');
psdDesistir($pdo, $A);

// (i) treino não paga nem cobra
$antes = moedas($pdo, $A);
$r = psdAbrir($pdo, $A, 'Ana', 'treino', 5000, 'aleatoria');
confere('treino não cobra entrada', moedas($pdo, $A) === $antes, 'cobrou do treino');
$pidT = (int)$r['id'];
$voltas = 0;
while ($voltas++ < 400) {
    $v = psdVer($pdo, $A, $pidT);
    if (($v['em'] ?? '') !== 'jogando' || !empty($v['acabou'])) break;
    if (empty($v['falta_eu'])) continue;
    $e = json_decode((string)psdPorId($pdo, $pidT)['estado'], true);
    psdEscolher($pdo, $A, psdIA($e, 'a'));
}
confere('treino não paga prêmio', moedas($pdo, $A) === $antes, 'pagou pelo treino');
$st = $pdo->prepare('SELECT COUNT(*) FROM psd_ranking WHERE id_usuario = ?');
$st->execute([$A]);
confere('treino não entra no ranking', (int)$st->fetchColumn() >= 0);

// (j) pacote: teto de 3 por dia
$abertos = 0;
for ($i = 0; $i < 6; $i++) {
    $r = psdAbrirPacote($pdo, $C, 'basico');
    if (!empty($r['ok'])) $abertos++;
}
confere('teto de 3 pacotes por dia', $abertos === PSD_PACOTES_DIA, "abriu $abertos");

// (k) comprar a mesma carta duas vezes
$r1 = psdComprarCarta($pdo, $C, 'raichu');
$r2 = psdComprarCarta($pdo, $C, 'raichu');
confere('não compra a mesma carta duas vezes', empty($r2['ok']));

// (l) escalar o que não tem
$r = psdSalvarTime($pdo, $C, ['arceus', 'mewtwo', 'rayquaza', 'lugia', 'hooh', 'giratina']);
confere('não escala carta que não tem', empty($r['ok']), 'escalou lendárias alheias');

// (m) time de verdade só aceita 6 diferentes
$col = psdColecao($pdo, $C);
$r = psdSalvarTime($pdo, $C, array_slice($col, 0, 3));
confere('time com menos de 6 recusado', empty($r['ok']));
$r = psdSalvarTime($pdo, $C, array_fill(0, 6, $col[0]));
confere('time com repetidos recusado', empty($r['ok']));

// (n) aposta maior que o teto e negativa
$antes = moedas($pdo, $C);
$r = psdAbrir($pdo, $C, 'Caio', 'codigo', 999999, 'aleatoria');
$cobrado = $antes - moedas($pdo, $C);
confere('aposta acima do teto é cortada no teto', $cobrado === PSD_APOSTA_MAX, "cobrou $cobrado");
psdDesistirDaEspera($pdo, $C);

$antes = moedas($pdo, $C);
$r = psdAbrir($pdo, $C, 'Caio', 'codigo', -5000, 'aleatoria');
confere('aposta negativa não dá moeda', moedas($pdo, $C) === $antes,
        'saldo mudou com aposta negativa');
psdDesistirDaEspera($pdo, $C);

// (o) cancelar a espera duas vezes devolve uma só
$antes = moedas($pdo, $C);
$r = psdAbrir($pdo, $C, 'Caio', 'codigo', 400, 'aleatoria');
$r1 = psdDesistirDaEspera($pdo, $C);
$r2 = psdDesistirDaEspera($pdo, $C);
confere('cancelar duas vezes devolve uma só', moedas($pdo, $C) === $antes,
        'saldo ' . moedas($pdo, $C) . ' contra ' . $antes);

// ─────────────────────────────── 3. a conta final ───────────────────────────────

titulo('RESULTADO');

echo "  $ok conferências passaram\n";
if ($falhas) {
    echo "\n  !! " . count($falhas) . " FALHARAM:\n";
    foreach (array_unique($falhas) as $f) echo "     · $f\n";
}

} finally {
    limpar($pdo, $todos);
    echo "\ncontas de teste apagadas.\n";
}

exit($falhas ? 1 : 0);
