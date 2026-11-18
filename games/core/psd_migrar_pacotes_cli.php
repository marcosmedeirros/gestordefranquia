<?php
/**
 * ── POKÉMON PVP: OS PACOTES PAGOS EM FBA POINTS VIRAM MOEDA ──────────
 *
 * O pacote nasceu custando FBA Points e passou a custar moedas em
 * 18/11/2026. Vinte e três GMs já tinham comprado no dia anterior, e a
 * decisão do Marcos foi acertar a conta em vez de deixar pra lá:
 *
 *   1. DEVOLVE o que a pessoa pagou em FBA Points.
 *   2. COBRA o preço novo, em moedas.
 *   3. Quem não tiver moeda suficiente paga o que faltar em FBA POINTS,
 *      um por um — sem câmbio escondido, porque qualquer taxa aqui seria
 *      invenção minha em cima do dinheiro dos outros.
 *
 * Ninguém pode ficar negativo: o preço novo em moedas é MENOR que o
 * preço velho em FBA Points nos dois pacotes (150 contra 200, 400 contra
 * 600), então a devolução sempre cobre o que falta. O ensaio confere
 * isso antes de gravar qualquer coisa.
 *
 * ── RODAR DUAS VEZES NÃO DOBRA NADA ──────────────────────────────────
 *
 * A linha migrada deixa de ser `moeda = 'fbap'` e vira `'moeda'`, com o
 * preço novo gravado. Numa segunda passada não sobra o que converter.
 *
 *   php games/core/psd_migrar_pacotes_cli.php            (só mostra)
 *   php games/core/psd_migrar_pacotes_cli.php --aplicar  (grava)
 *
 * Roda NA HOSPEDAGEM: o banco só é acessível de dentro.
 */

require_once __DIR__ . '/../../backend/db.php';
require_once __DIR__ . '/psd_cartas.php';

$opts    = getopt('', ['aplicar']);
$APLICAR = isset($opts['aplicar']);

$pdo = db();
psdCartasTabelas($pdo);

// ─────────────────────────────── o que tem pra converter ───────────────────────────────

$st = $pdo->query("SELECT c.id, c.id_usuario, c.item, c.preco, u.nome
                     FROM psd_compras c JOIN games_usuarios u ON u.id = c.id_usuario
                    WHERE c.tipo = 'pacote' AND c.moeda = 'fbap'
                 ORDER BY c.id_usuario, c.id");
$linhas = $st->fetchAll(PDO::FETCH_ASSOC);

if (!$linhas) {
    echo "Nada pra converter: nenhum pacote pago em FBA Points.\n";
    exit(0);
}

$porGm = [];
foreach ($linhas as $l) {
    $uid = (int)$l['id_usuario'];
    $novo = PSD_PACOTES[$l['item']]['preco'] ?? null;
    if ($novo === null) {
        fwrite(STDERR, "pacote desconhecido na compra {$l['id']}: {$l['item']}\n");
        exit(1);
    }
    $porGm[$uid] ??= ['nome' => $l['nome'], 'pacotes' => 0, 'fbap_pago' => 0, 'devido' => 0, 'ids' => []];
    $porGm[$uid]['pacotes']++;
    $porGm[$uid]['fbap_pago'] += (int)$l['preco'];
    $porGm[$uid]['devido']    += $novo;
    $porGm[$uid]['ids'][]      = ['id' => (int)$l['id'], 'preco' => $novo];
}

// ─────────────────────────────── o plano, GM a GM ───────────────────────────────

printf("%-26s %4s %9s %9s %9s %10s %10s\n",
    'GM', 'pac', 'devolve', 'deve', 'tem', 'em moeda', 'em FBAP');
echo str_repeat('─', 86) . "\n";

$totDevolve = $totMoeda = $totFbapExtra = 0;
$problemas = [];

foreach ($porGm as $uid => $g) {
    $b = psdBolso($pdo, $uid);

    $emMoeda = min($b['moedas'], $g['devido']);
    $emFbap  = $g['devido'] - $emMoeda;

    /* O saldo de FBA Points DEPOIS: devolve tudo e tira a parte que
       sobrou. Se isso ficasse negativo a conta estaria errada. */
    $fbapDepois = $b['fbap'] + $g['fbap_pago'] - $emFbap;
    if ($fbapDepois < 0)                 $problemas[] = "{$g['nome']} ficaria com FBA Points negativo";
    if ($b['moedas'] - $emMoeda < 0)     $problemas[] = "{$g['nome']} ficaria com moedas negativas";

    printf("%-26s %4d %9s %9s %9s %10s %10s\n",
        mb_substr($g['nome'], 0, 26), $g['pacotes'],
        '+' . number_format($g['fbap_pago'], 0, ',', '.'),
        number_format($g['devido'], 0, ',', '.'),
        number_format($b['moedas'], 0, ',', '.'),
        '-' . number_format($emMoeda, 0, ',', '.'),
        $emFbap ? '-' . number_format($emFbap, 0, ',', '.') : '—');

    $porGm[$uid]['em_moeda'] = $emMoeda;
    $porGm[$uid]['em_fbap']  = $emFbap;

    $totDevolve   += $g['fbap_pago'];
    $totMoeda     += $emMoeda;
    $totFbapExtra += $emFbap;
}

echo str_repeat('─', 86) . "\n";
printf("%d GMs · devolve %s FBA Points · cobra %s moedas e %s FBA Points\n",
    count($porGm), number_format($totDevolve, 0, ',', '.'),
    number_format($totMoeda, 0, ',', '.'), number_format($totFbapExtra, 0, ',', '.'));
printf("saldo líquido de FBA Points na liga: %+s\n",
    number_format($totDevolve - $totFbapExtra, 0, ',', '.'));

if ($problemas) {
    echo "\n!! NÃO VOU APLICAR:\n";
    foreach (array_unique($problemas) as $p) echo "   · $p\n";
    exit(1);
}

if (!$APLICAR) {
    echo "\nEnsaio. Nada foi gravado. Rode com --aplicar pra valer.\n";
    exit(0);
}

// ─────────────────────────────── aplica ───────────────────────────────

echo "\naplicando…\n";
$feitos = 0;

foreach ($porGm as $uid => $g) {
    $pdo->beginTransaction();
    try {
        // Trava a linha do GM: ninguém comprando no mesmo instante atropela.
        $pdo->prepare('SELECT id FROM games_usuarios WHERE id = ? FOR UPDATE')->execute([$uid]);

        $pdo->prepare('UPDATE games_usuarios
                          SET fba_points = fba_points + ? - ?, pontos = pontos - ?
                        WHERE id = ?')
            ->execute([$g['fbap_pago'], $g['em_fbap'], $g['em_moeda'], $uid]);

        $up = $pdo->prepare("UPDATE psd_compras SET moeda = 'moeda', preco = ? WHERE id = ?");
        foreach ($g['ids'] as $c) $up->execute([$c['preco'], $c['id']]);

        $pdo->commit();
        $feitos++;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        fwrite(STDERR, "falhou em {$g['nome']}: " . $e->getMessage() . "\n");
    }
}

printf("pronto: %d de %d GMs convertidos.\n", $feitos, count($porGm));

$resta = $pdo->query("SELECT COUNT(*) FROM psd_compras WHERE tipo='pacote' AND moeda='fbap'")->fetchColumn();
printf("pacotes ainda em FBA Points: %d\n", (int)$resta);
