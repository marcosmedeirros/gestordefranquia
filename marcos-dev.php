<?php
/**
 * CENTRAL DE CORREÇÕES — só do dono da liga.
 *
 * O lugar onde todo defeito encontrado em qualquer tela do app vira um
 * diagnóstico com botão. Cada bloco RODA DE VERDADE contra o banco: nada aqui
 * é status guardado, é a pergunta sendo feita no momento em que a página abre.
 *
 * Por que existe: os defeitos da liga se repetem em família — sobra de sprint
 * encerrado poluindo consulta que não filtra sprint, dado clonado de uma
 * temporada pra outra, cache de dono desatualizado. Achar isso pelo log é
 * lento; numa tela é imediato, e dá pra consertar sem me chamar.
 *
 * ── COMO ACRESCENTAR UM DIAGNÓSTICO ─────────────────────────────────────────
 *
 *   1. Uma função `devAlgumaCoisa(PDO): array` que SÓ LÊ e devolve as linhas
 *      problemáticas. Vazio = está tudo certo.
 *   2. Um `elseif` no switch do POST, se existir conserto seguro. A ação
 *      RECONFERE o diagnóstico antes de gravar — o POST pode chegar depois de
 *      a situação ter mudado, e agir no escuro é como se estraga o que estava
 *      bom.
 *   3. Um bloco no HTML, dentro da área que faz sentido, explicando o que é o
 *      problema e por que ele importa — quem lê daqui a seis meses não vai
 *      lembrar do dia em que apareceu.
 *   4. A contagem entra em $totalProblemas e no $porArea do resumo.
 *
 * QUANDO NÃO OFERECER BOTÃO: se o conserto depende de uma decisão que o código
 * não pode tomar (refazer uma loteria, escolher qual de duas temporadas vale),
 * o bloco explica e para por aí. Adivinhar ali muda histórico de liga.
 */
require_once __DIR__ . '/backend/auth.php';
require_once __DIR__ . '/backend/db.php';
require_once __DIR__ . '/backend/helpers.php';
require_once __DIR__ . '/backend/draft_swaps.php';
requireAuth();

const DONO_DEV = 'medeirros99@gmail.com';

$user = getUserSession();
if (strtolower(trim((string)($user['email'] ?? ''))) !== DONO_DEV) {
    http_response_code(403);
    exit('Sem acesso.');
}

$pdo = db();
$LIGAS = ['ELITE', 'NEXT', 'RISE', 'ROOKIE'];

// ─────────────────────────────────────────────────────────────────────────────
// DIAGNÓSTICOS
// ─────────────────────────────────────────────────────────────────────────────

/** Sessão de draft aberta numa temporada de sprint já encerrado. */
function devSessoesZumbi(PDO $pdo): array
{
    $sql = "SELECT ds.id, ds.league, ds.status, se.season_number, se.year,
                   spr.sprint_number, spr.status AS sprint_status,
                   (SELECT COUNT(*) FROM draft_order o WHERE o.draft_session_id = ds.id) AS vagas
              FROM draft_sessions ds
              JOIN seasons se ON se.id = ds.season_id
              JOIN sprints spr ON spr.id = se.sprint_id
             WHERE ds.status IN ('setup','in_progress') AND spr.status <> 'active'
             ORDER BY ds.league, ds.id";
    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

/** Ordem do draft com o mesmo time de origem em mais de uma vaga da rodada. */
function devOrigemRepetida(PDO $pdo, array $ligas): array
{
    $saida = [];
    foreach ($ligas as $liga) {
        $d = draftAbertoDaLiga($pdo, $liga);
        if (!$d) continue;
        $r = draftConferirOrdem($pdo, (int)$d['id']);
        if (!empty($r['origem_repetida'])) {
            $saida[] = ['liga' => $liga, 'sessao' => $d['id'], 'ano' => $r['ano'],
                        'casos' => $r['origem_repetida'], 'divergencias' => count($r['divergencias'])];
        }
    }
    return $saida;
}

/** Vaga do draft sem a pick correspondente, e quem escolhe fora do dono. */
function devOrdemFora(PDO $pdo, array $ligas): array
{
    $saida = [];
    foreach ($ligas as $liga) {
        $d = draftAbertoDaLiga($pdo, $liga);
        if (!$d) continue;
        $r = draftConferirOrdem($pdo, (int)$d['id']);
        $semPick = count($r['sem_pick']);
        $div = count($r['divergencias']);
        if ($semPick || $div) {
            $saida[] = ['liga' => $liga, 'sessao' => $d['id'], 'ano' => $r['ano'],
                        'vagas' => $r['vagas'], 'sem_pick' => $semPick, 'divergencias' => $div];
        }
    }
    return $saida;
}

/**
 * Ano sem pick nenhuma dentro da faixa que a liga deveria ter.
 * É o rastro da pick que foi realocada e deixou o ano de origem vazio.
 */
function devAnosVazios(PDO $pdo, array $ligas): array
{
    $saida = [];
    foreach ($ligas as $liga) {
        $n = (int)$pdo->query("SELECT COUNT(*) FROM teams WHERE league = " . $pdo->quote($liga))->fetchColumn();
        if (!$n) continue;
        $esperado = $n * 2;   // uma de 1ª e uma de 2ª por time

        $st = $pdo->prepare("SELECT p.season_year, COUNT(*) AS n
                               FROM picks p JOIN teams t ON t.id = p.original_team_id
                              WHERE t.league = ? GROUP BY p.season_year ORDER BY p.season_year");
        $st->execute([$liga]);
        $porAno = [];
        foreach ($st as $r) $porAno[(int)$r['season_year']] = (int)$r['n'];
        if (!$porAno) continue;

        $anos = array_keys($porAno);
        $falhas = [];
        for ($a = min($anos); $a <= max($anos); $a++) {
            $tem = $porAno[$a] ?? 0;
            if ($tem < $esperado) $falhas[] = ['ano' => $a, 'tem' => $tem, 'esperado' => $esperado];
        }
        if ($falhas) $saida[] = ['liga' => $liga, 'falhas' => $falhas];
    }
    return $saida;
}

/**
 * Estatística CLONADA de uma temporada que ainda não foi disputada.
 *
 * O avanço de temporada copiava player_season_stats da anterior "como ponto
 * de partida". O card do jogador passava a mostrar os números do ano passado
 * como se fossem do novo. A cópia parou de acontecer; o que já foi copiado
 * continua no banco.
 */
function devStatsClonadas(PDO $pdo): array
{
    try {
        return $pdo->query("
            SELECT ps.season_id, s.league, s.season_number, s.status, COUNT(*) AS linhas,
                   SUM(ps.source <> 'clonado' OR ps.source IS NULL) AS lancadas
              FROM player_season_stats ps
              JOIN seasons s ON s.id = ps.season_id
             WHERE ps.source = 'clonado'
             GROUP BY ps.season_id
             ORDER BY s.league, s.season_number")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}

/** Tabelas de linha única que ganharam linha repetida. */
function devLinhaUnicaRepetida(PDO $pdo): array
{
    $saida = [];
    foreach ([['maintenance_mode', 'id']] as [$tabela, $chave]) {
        try {
            $st = $pdo->query("SELECT `$chave` AS k, COUNT(*) AS n FROM `$tabela` GROUP BY `$chave` HAVING n > 1");
            foreach ($st as $r) $saida[] = ['tabela' => $tabela, 'chave' => $r['k'], 'linhas' => (int)$r['n']];
        } catch (Throwable $e) { /* tabela pode não existir */ }
    }
    return $saida;
}

/**
 * Temporada de sprint ENCERRADO que não ficou como 'completed'.
 *
 * Número de temporada repetido na liga é normal: cada sprint recomeça do #1.
 * O que não é normal é uma temporada de sprint fechado seguir "em aberto" —
 * aí toda consulta que procura "a temporada corrente" pode pescar ela.
 * Foi o caso da #1 da RISE (id 105), do sprint 1, parada em 'planejamento'
 * enquanto as outras 15 do mesmo sprint estavam completed.
 */
function devTemporadasPresas(PDO $pdo): array
{
    return $pdo->query("SELECT s.id, s.league, s.season_number, s.year, s.status,
                               spr.sprint_number,
                               (SELECT COUNT(*) FROM seasons x
                                 WHERE x.sprint_id = s.sprint_id AND x.status = 'completed') AS irmas_fechadas
                          FROM seasons s
                          JOIN sprints spr ON spr.id = s.sprint_id
                         WHERE spr.status <> 'active'
                           AND (s.status IS NULL OR s.status <> 'completed')
                         ORDER BY s.league, s.season_number")->fetchAll(PDO::FETCH_ASSOC);
}

// ─────────────────────────────────────────────────────────────────────────────
// CORREÇÕES
// ─────────────────────────────────────────────────────────────────────────────
$aviso = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = (string)($_POST['acao'] ?? '');
    try {
        if ($acao === 'fechar_sessao') {
            $id = (int)($_POST['id'] ?? 0);
            // Confere de novo antes de mexer: o POST pode chegar depois de a
            // situação ter mudado, e fechar uma sessão viva seria estrago.
            $ok = false;
            foreach (devSessoesZumbi($pdo) as $z) if ((int)$z['id'] === $id) $ok = true;
            if (!$ok) throw new RuntimeException("A sessão #$id não está mais na lista — recarregue a página.");
            $pdo->prepare("UPDATE draft_sessions SET status = 'completed' WHERE id = ?")->execute([$id]);
            $aviso = ['ok', "Sessão #$id encerrada. Ela era de um sprint já fechado."];

        } elseif ($acao === 'sincronizar_ordem') {
            $liga = (string)($_POST['liga'] ?? '');
            $d = draftAbertoDaLiga($pdo, $liga);
            if (!$d) throw new RuntimeException("A $liga não tem draft aberto.");
            $r = draftSincronizarOrdem($pdo, (int)$d['id']);
            $aviso = ['ok', "Ordem da $liga sincronizada: {$r['donos']} dono(s) e {$r['swaps']} swap(s) ajustados."];

        } elseif ($acao === 'fechar_temporada') {
            $id = (int)($_POST['id'] ?? 0);
            $ok = false;
            foreach (devTemporadasPresas($pdo) as $t) if ((int)$t['id'] === $id) $ok = true;
            if (!$ok) throw new RuntimeException("A temporada id $id não está mais na lista — recarregue.");
            // Só o status muda. O histórico dela (playoffs, pontuação, campeão)
            // fica onde está: ela é uma temporada de verdade, só ficou aberta.
            $pdo->prepare("UPDATE seasons SET status = 'completed' WHERE id = ?")->execute([$id]);
            $aviso = ['ok', "Temporada id $id marcada como completed. Nenhum dado dela foi tocado."];

        } elseif ($acao === 'limpar_stats_clonadas') {
            $sid = (int)($_POST['season_id'] ?? 0);
            $ok = false;
            foreach (devStatsClonadas($pdo) as $c) if ((int)$c['season_id'] === $sid) $ok = true;
            if (!$ok) throw new RuntimeException("A temporada $sid não está mais na lista — recarregue.");
            // Só o que foi CLONADO. O que alguém lançou de verdade nessa mesma
            // temporada fica: são linhas diferentes, e apagar as duas seria
            // perder lançamento real por causa de uma cópia automática.
            $st = $pdo->prepare("DELETE FROM player_season_stats WHERE season_id = ? AND source = 'clonado'");
            $st->execute([$sid]);
            $aviso = ['ok', $st->rowCount() . " linha(s) clonada(s) apagada(s). O que foi lançado à mão ficou."];

        } elseif ($acao === 'dedup_linha_unica') {
            $tabela = (string)($_POST['tabela'] ?? '');
            if ($tabela !== 'maintenance_mode') throw new RuntimeException('Tabela não prevista.');
            $pdo->exec("CREATE TABLE _dev_tmp AS SELECT * FROM `$tabela` ORDER BY updated_at DESC LIMIT 1");
            $pdo->exec("DELETE FROM `$tabela`");
            $pdo->exec("INSERT INTO `$tabela` SELECT * FROM _dev_tmp");
            $pdo->exec("DROP TABLE _dev_tmp");
            $aviso = ['ok', "$tabela ficou com uma linha só (a mais recente)."];

        } elseif ($acao !== '') {
            throw new RuntimeException('Ação desconhecida.');
        }
    } catch (Throwable $e) {
        $aviso = ['erro', $e->getMessage()];
    }
}

$zumbis     = devSessoesZumbi($pdo);
$repetidas  = devOrigemRepetida($pdo, $LIGAS);
$ordemFora  = devOrdemFora($pdo, $LIGAS);
$anosVazios = devAnosVazios($pdo, $LIGAS);
$clonadas   = devStatsClonadas($pdo);
$linhaUnica = devLinhaUnicaRepetida($pdo);
$tempDup    = devTemporadasPresas($pdo);

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/**
 * Um achado pronto pro card: o texto do que está errado e, quando existe
 * conserto seguro, o formulário que o aplica.
 */
function achado(string $texto, ?array $acao = null, string $nota = ''): array
{
    return ['texto' => $texto, 'acao' => $acao, 'nota' => $nota];
}

/* ─────────────────────────────────────────────────────────────────────────────
 * O MAPA: uma entrada por PÁGINA DO MENU.
 *
 * A página é a unidade porque é assim que o problema chega ("o draft está
 * errado", "as picks sumiram") — e é assim que dá pra ver, de relance, qual
 * tela está sã e qual não está.
 *
 * O mesmo defeito pode aparecer em mais de um card quando afeta mais de uma
 * tela: quem escolhe fora do dono da pick estraga o quadro do Draft E o número
 * da escolha na Trade Machine.
 *
 * `verificacoes` diz o que este card SABE olhar. Card sem verificação nenhuma
 * aparece como "sem verificação" — é honesto, e mostra onde falta cobertura em
 * vez de fingir que está tudo bem.
 * ─────────────────────────────────────────────────────────────────────────── */
$P = [];   // achados por página

foreach ($zumbis as $z) {
    $a = achado(
        "Sessão #{$z['id']} ({$z['status']}) na temporada #{$z['season_number']}, ano {$z['year']} — o sprint {$z['sprint_number']} já encerrou. {$z['vagas']} vaga(s).",
        ['acao' => 'fechar_sessao', 'id' => (int)$z['id'], 'rotulo' => 'Encerrar sessão', 'perigo' => true,
         'confirma' => "Encerrar a sessão #{$z['id']}?"],
        'Ela vence as consultas que procuram "o draft da liga", e as picks passam a se ligar a um draft que não existe mais.'
    );
    $P['drafts.php'][] = ['liga' => $z['league']] + $a;
    $P['lottery.php'][] = ['liga' => $z['league']] + $a;
}

foreach ($repetidas as $r) {
    $ex = $r['casos'][0] ?? null;
    $P['drafts.php'][] = ['liga' => $r['liga']] + achado(
        "Sessão #{$r['sessao']} (ano {$r['ano']}): " . count($r['casos']) . " time(s) com mais de uma vaga na mesma rodada"
        . ($ex ? ", um deles com " . count($ex['picks']) . " vagas" : '') . ". {$r['divergencias']} vaga(s) divergente(s).",
        null,
        'Sem botão: não dá pra adivinhar de quem era cada vaga repetida. Esta ordem precisa ser refeita pela loteria.'
    );
}

foreach ($ordemFora as $o) {
    $a = achado(
        "Sessão #{$o['sessao']} (ano {$o['ano']}, {$o['vagas']} vagas): {$o['divergencias']} vaga(s) com quem escolhe diferente do dono da pick, {$o['sem_pick']} sem pick por trás.",
        ['acao' => 'sincronizar_ordem', 'liga' => $o['liga'], 'rotulo' => 'Sincronizar ordem'],
        'Vaga sem pick não se resolve aqui: use "Ajustar Picks" no card de Draft do admin.'
    );
    $P['drafts.php'][] = ['liga' => $o['liga']] + $a;
    $P['trades.php'][] = ['liga' => $o['liga']] + $a;
    $P['picks.php'][]  = ['liga' => $o['liga']] + $a;
}

foreach ($anosVazios as $v) {
    $lista = [];
    foreach (array_slice($v['falhas'], 0, 6) as $f) $lista[] = "{$f['ano']}: {$f['tem']}/{$f['esperado']}";
    $a = achado(
        'Anos com pick faltando — ' . implode(' · ', $lista)
        . (count($v['falhas']) > 6 ? ' e mais ' . (count($v['falhas']) - 6) : ''),
        null,
        'Conserto: "Ajustar Picks" no card de Draft do admin. Ele cria o que falta e não toca em pick negociada.'
    );
    $P['picks.php'][]  = ['liga' => $v['liga']] + $a;
    $P['trades.php'][] = ['liga' => $v['liga']] + $a;
}

foreach ($clonadas as $c) {
    $a = achado(
        "Temporada #{$c['season_number']} ({$c['status']}): {$c['linhas']} linha(s) de estatística copiadas da temporada anterior.",
        ['acao' => 'limpar_stats_clonadas', 'season_id' => (int)$c['season_id'], 'rotulo' => 'Apagar as copiadas', 'perigo' => true,
         'confirma' => "Apagar as {$c['linhas']} linhas copiadas?"],
        'Apaga só o que tem source=clonado; lançamento feito à mão na mesma temporada fica.'
    );
    $P['statsjogadores.php'][] = ['liga' => $c['league']] + $a;
    $P['my-roster.php'][]      = ['liga' => $c['league']] + $a;
    $P['players.php'][]        = ['liga' => $c['league']] + $a;
}

foreach ($tempDup as $t) {
    $P['admin.php'][] = ['liga' => $t['league']] + achado(
        "Temporada #{$t['season_number']} (id {$t['id']}, ano {$t['year']}) está \"{$t['status']}\" — o sprint {$t['sprint_number']} já encerrou, e {$t['irmas_fechadas']} temporada(s) dele estão completed.",
        ['acao' => 'fechar_temporada', 'id' => (int)$t['id'], 'rotulo' => 'Marcar como completed',
         'confirma' => "Marcar a temporada id {$t['id']} como completed?"],
        'Mexe só no status: playoffs, pontuação e campeão dela ficam onde estão.'
    );
}

foreach ($linhaUnica as $l) {
    $P['admin.php'][] = achado(
        "A tabela {$l['tabela']} tem {$l['linhas']} linhas com a mesma chave ({$l['chave']}).",
        ['acao' => 'dedup_linha_unica', 'tabela' => $l['tabela'], 'rotulo' => 'Deixar só a mais recente', 'perigo' => true,
         'confirma' => "Deixar só a linha mais recente de {$l['tabela']}?"],
        'Limpar resolve o sintoma; a causa é o código que grava.'
    );
}

/* As páginas do menu, na ordem do menu. `ver` é o que este card sabe olhar. */
$PAGINAS = [
    ['Draft e picks', [
        ['drafts.php', 'Draft', 'bi-trophy', ['sessão de sprint encerrado', 'ordem com time repetido', 'quem escolhe x dono da pick']],
        ['lottery.php', 'Loteria', 'bi-shuffle', ['sessão de sprint encerrado']],
        ['picks.php', 'Picks', 'bi-calendar-check-fill', ['ano com pick faltando', 'quem escolhe x dono da pick']],
        ['trades.php', 'Trades', 'bi-arrow-left-right', ['ano com pick faltando', 'quem escolhe x dono da pick']],
    ]],
    ['Elenco e números', [
        ['my-roster.php', 'Meu Elenco', 'bi-person-fill', ['estatística copiada de temporada não disputada']],
        ['statsjogadores.php', 'Stats', 'bi-bar-chart-line-fill', ['estatística copiada de temporada não disputada']],
        ['players.php', 'Jogadores', 'bi-person-lines-fill', ['estatística copiada de temporada não disputada']],
        ['teams.php', 'Times', 'bi-people-fill', []],
        ['tatica.php', 'Tática', 'bi-clipboard2-pulse', []],
        ['cap.php', 'Salário Cap', 'bi-cash-stack', []],
    ]],
    ['Mercado', [
        ['free-agency.php', 'Free Agency', 'bi-coin', []],
        ['dispensas.php', 'Dispensas', 'bi-hourglass-split', []],
        ['leilao.php', 'Leilão', 'bi-hammer', []],
        ['mercado.php', 'Mercado', 'bi-shop', []],
    ]],
    ['Liga', [
        ['admin.php', 'Admin', 'bi-shield-lock-fill', ['temporada de sprint encerrado em aberto', 'tabela de linha única duplicada']],
        ['rankings.php', 'Rankings', 'bi-bar-chart-fill', []],
        ['tabela.php', 'Tabela', 'bi-table', []],
        ['history.php', 'Prêmios', 'bi-trophy-fill', []],
        ['calendario.php', 'Calendário', 'bi-calendar3', []],
        ['hall-da-fama.php', 'Hall da Fama', 'bi-award-fill', []],
        ['mundo-fba.php', 'Mundo FBA', 'bi-globe2', []],
        ['estatisticas.php', 'Estatísticas', 'bi-bar-chart-line-fill', []],
        ['timeline.php', 'Timeline', 'bi-collection-play-fill', []],
        ['ouvidoria.php', 'Ouvidoria', 'bi-chat-dots', []],
        ['thepathetic.php', 'The Pathetic', 'bi-newspaper', []],
        ['games.php', 'Games', 'bi-controller', []],
    ]],
];

$totalProblemas = 0;
foreach ($P as $lista) $totalProblemas += count($lista);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Correções · FBA</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Oswald:wght@600;700&display=swap" rel="stylesheet">
<style>
  :root{
    --bg:#0b0d10; --panel:#14181d; --panel-2:#1b2027; --border:#262d36;
    --text:#e8ecf1; --text-2:#a8b3c0; --text-3:#6d7a8a;
    --red:#fc0025; --amber:#f5c542; --green:#22c55e; --blue:#38bdf8;
    --radius:14px; --font:'Inter',system-ui,sans-serif;
  }
  *{box-sizing:border-box}
  body{margin:0;background:var(--bg);color:var(--text);font-family:var(--font);
       font-size:14px;line-height:1.5;padding:24px 16px 64px}
  /* Tela cheia: sao dezenas de cards, e uma coluna de 940px no meio deixava
     dois terços do monitor vazios enquanto a lista descia sem fim. */
  .wrap{max-width:1680px;margin:0 auto}
  /* Os cards se acomodam sozinhos: 3 ou 4 num monitor, 1 no celular. */
  .grade{display:grid;grid-template-columns:repeat(auto-fill,minmax(330px,1fr));gap:14px;margin-bottom:8px}
  .pg{background:var(--panel);border:1px solid var(--border);border-radius:var(--radius);
      padding:15px 16px;display:flex;flex-direction:column;gap:10px}
  .pg.ok{opacity:.62}
  .pg.sujo{border-color:rgba(252,0,37,.35)}
  .pg-cab{display:flex;align-items:center;gap:9px}
  .pg-cab i{font-size:16px;color:var(--text-3)}
  .pg.sujo .pg-cab i{color:var(--red)}
  .pg-nome{font-weight:700;font-size:14px;flex:1;min-width:0}
  .pg-nome a{color:var(--text);text-decoration:none}
  .pg-nome a:hover{color:var(--red)}
  .pg-sel{font-family:'Oswald',sans-serif;font-size:11px;letter-spacing:.5px;padding:2px 8px;border-radius:999px;white-space:nowrap}
  .pg-sel.ok{background:rgba(34,197,94,.1);color:var(--green);border:1px solid rgba(34,197,94,.28)}
  .pg-sel.sujo{background:rgba(252,0,37,.1);color:var(--red);border:1px solid rgba(252,0,37,.3)}
  .pg-sel.nada{background:var(--panel-2);color:var(--text-3);border:1px solid var(--border)}
  .pg-ver{font-size:11px;color:var(--text-3);line-height:1.5}
  .pg-ver b{color:var(--text-2);font-weight:600}
  .item{background:var(--panel-2);border-left:3px solid var(--red);border-radius:8px;padding:10px 11px;font-size:12.5px;line-height:1.5}
  .item .liga{font-family:'Oswald',sans-serif;font-size:10px;letter-spacing:.6px;color:var(--amber);display:block;margin-bottom:3px}
  .item .nota{display:block;margin-top:6px;color:var(--text-3);font-size:11.5px}
  .item form{margin-top:9px}
  h1{font-family:'Oswald',sans-serif;font-size:26px;margin:0 0 4px;letter-spacing:.5px}
  .sub{color:var(--text-3);font-size:13px;margin-bottom:22px}
  .placar{display:inline-flex;align-items:center;gap:9px;padding:9px 15px;border-radius:11px;
          font-weight:700;font-size:13.5px;margin-bottom:24px}
  .placar.limpo{background:rgba(34,197,94,.1);border:1px solid rgba(34,197,94,.3);color:var(--green)}
  .placar.sujo{background:rgba(252,0,37,.09);border:1px solid rgba(252,0,37,.3);color:var(--red)}
  .bloco{background:var(--panel);border:1px solid var(--border);border-radius:var(--radius);
         padding:18px;margin-bottom:16px}
  .bloco h2{font-size:15px;margin:0 0 4px;display:flex;align-items:center;gap:9px}
  .bloco .por{color:var(--text-3);font-size:12.5px;margin:0 0 14px;max-width:74ch}
  .ok{color:var(--green)}
  .achado{background:var(--panel-2);border:1px solid var(--border);border-left:3px solid var(--red);
          border-radius:9px;padding:11px 13px;margin-bottom:9px;
          display:flex;flex-wrap:wrap;align-items:center;gap:11px}
  .achado .txt{flex:1;min-width:220px;font-size:13px}
  .achado b{color:var(--text)}
  .tag{font-family:'Oswald',sans-serif;font-size:11px;letter-spacing:.6px;padding:3px 8px;
       border-radius:6px;background:rgba(245,197,66,.12);color:var(--amber);white-space:nowrap}
  button{font-family:var(--font);font-weight:700;font-size:12.5px;padding:8px 13px;border-radius:9px;
         border:1px solid var(--blue);background:transparent;color:var(--blue);cursor:pointer;white-space:nowrap}
  button:hover{background:rgba(56,189,248,.12)}
  button.perigo{border-color:var(--red);color:var(--red)}
  button.perigo:hover{background:rgba(252,0,37,.1)}
  .nota{font-size:12px;color:var(--text-3);margin-top:9px;padding-left:2px}
  .aviso{padding:11px 14px;border-radius:11px;margin-bottom:20px;font-size:13.5px;font-weight:600}
  .aviso.ok{background:rgba(34,197,94,.1);border:1px solid rgba(34,197,94,.3);color:var(--green)}
  .aviso.erro{background:rgba(252,0,37,.1);border:1px solid rgba(252,0,37,.3);color:var(--red)}
  code{background:var(--panel-2);padding:1px 5px;border-radius:4px;font-size:12px;color:var(--amber)}
  a.voltar{color:var(--text-3);font-size:13px;text-decoration:none}
  a.voltar:hover{color:var(--text)}
  /* Cabeçalho de área. A página cresce a cada bug que aparece, e uma pilha de
     blocos iguais deixa de ser legível — agrupar por assunto mantém o
     "onde está o problema" respondível de relance. */
  .area{font-family:'Oswald',sans-serif;font-size:12px;letter-spacing:1.4px;color:var(--text-3);
        margin:26px 0 10px;padding-bottom:7px;border-bottom:1px solid var(--border)}
  .area:first-of-type{margin-top:8px}
  .resumo{display:flex;flex-wrap:wrap;gap:8px;margin:-12px 0 22px}
  .resumo-item{background:var(--panel);border:1px solid var(--border);border-radius:8px;
               padding:5px 11px;font-size:12.5px;color:var(--text-2);text-decoration:none}
  .resumo-item:hover{border-color:var(--red);color:var(--text)}
  .resumo-item b{color:var(--red);font-family:'Oswald',sans-serif;font-size:14px;margin-right:3px}
  @media(max-width:520px){ .achado{flex-direction:column;align-items:stretch} button{width:100%} }
</style>
</head>
<body>
<div class="wrap">
  <a class="voltar" href="/admin.php"><i class="bi bi-arrow-left"></i> Admin</a>
  <h1>Central de correções</h1>
  <p class="sub">Um card por tela do app. Cada um roda os diagnósticos que sabe fazer, agora, contra o banco.</p>

  <?php if ($aviso): ?>
    <div class="aviso <?= h($aviso[0]) ?>"><?= h($aviso[1]) ?></div>
  <?php endif; ?>

  <div class="placar <?= $totalProblemas ? 'sujo' : 'limpo' ?>">
    <i class="bi bi-<?= $totalProblemas ? 'exclamation-triangle-fill' : 'check-circle-fill' ?>"></i>
    <?= $totalProblemas ? $totalProblemas . ' ponto(s) para olhar' : 'Nada pendente em nenhuma tela' ?>
  </div>

  <?php if ($totalProblemas): ?>
    <?php /* Atalho pros cards com problema: com dezenas de telas, procurar o
             vermelho no meio do verde é rolar a página inteira. */ ?>
    <div class="resumo">
      <?php foreach ($P as $slug => $itens): if (!$itens) continue; ?>
        <a class="resumo-item" href="#pg-<?= h($slug) ?>"><b><?= count($itens) ?></b> <?= h($slug) ?></a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php foreach ($PAGINAS as [$area, $telas]): ?>
    <div class="area"><?= h($area) ?></div>
    <div class="grade">
      <?php foreach ($telas as [$slug, $nome, $icone, $ver]):
        $itens = $P[$slug] ?? [];
        $temProblema = (bool)$itens;
        // Três estados, e não dois: "sem verificação" não é "está tudo bem".
        // Fingir verde numa tela que ninguém olha é pior que admitir o buraco.
        $classe = $temProblema ? 'sujo' : 'ok';
        ?>
        <div class="pg <?= $classe ?>" id="pg-<?= h($slug) ?>">
          <div class="pg-cab">
            <i class="bi <?= h($icone) ?>"></i>
            <span class="pg-nome"><a href="/<?= h($slug) ?>" target="_blank"><?= h($nome) ?></a></span>
            <?php if ($temProblema): ?>
              <span class="pg-sel sujo"><?= count($itens) ?> <?= count($itens) === 1 ? 'ACHADO' : 'ACHADOS' ?></span>
            <?php elseif ($ver): ?>
              <span class="pg-sel ok">OK</span>
            <?php else: ?>
              <span class="pg-sel nada">SEM VERIFICAÇÃO</span>
            <?php endif; ?>
          </div>

          <?php foreach ($itens as $it): ?>
            <div class="item">
              <?php if (!empty($it['liga'])): ?><span class="liga"><?= h($it['liga']) ?></span><?php endif; ?>
              <?= h($it['texto']) ?>
              <?php if ($it['nota']): ?><span class="nota"><?= h($it['nota']) ?></span><?php endif; ?>
              <?php if ($it['acao']): $a = $it['acao']; ?>
                <form method="post"<?= isset($a['confirma']) ? ' onsubmit="return confirm(' . htmlspecialchars(json_encode($a['confirma']), ENT_QUOTES) . ')"' : '' ?>>
                  <?php foreach ($a as $k => $v): if (in_array($k, ['rotulo', 'perigo', 'confirma'], true)) continue; ?>
                    <input type="hidden" name="<?= h($k) ?>" value="<?= h($v) ?>">
                  <?php endforeach; ?>
                  <button type="submit"<?= !empty($a['perigo']) ? ' class="perigo"' : '' ?>><?= h($a['rotulo']) ?></button>
                </form>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>

          <?php if ($ver): ?>
            <div class="pg-ver"><b>Verifica:</b> <?= h(implode(' · ', $ver)) ?></div>
          <?php else: ?>
            <div class="pg-ver">Nenhum diagnóstico escrito para esta tela ainda.</div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endforeach; ?>

<?php
/*
 * CONFERÊNCIA DE PICKS — veio da página do Draft.
 *
 * Ela morava lá embaixo em drafts.php, visível pra qualquer GM que abrisse a
 * tela: dois botões de manutenção no meio de uma página que é do jogo. Um
 * deles GRAVA (reatribui as vagas), e não é decisão de GM.
 *
 * Aqui é o lugar: os cards acima já dizem que uma liga está com a ordem fora
 * do dono da pick, e este bloco é o detalhe — qual vaga, de quem era, com
 * quem está — mais o botão que arruma.
 *
 * Chama a mesma API de antes (conferir_picks / ajustar_picks): a conta não
 * mudou de lugar, só quem consegue pedir por ela.
 */
$ligasComDraft = [];
foreach ($LIGAS as $lg) {
    try { if (draftAbertoDaLiga($pdo, $lg)) $ligasComDraft[] = $lg; }
    catch (Throwable $e) { error_log('[dev/picks] ' . $e->getMessage()); }
}
?>
<div class="pagina" style="margin-top:26px">
  <div class="pg-nome"><i class="bi bi-clipboard-check"></i> Conferência de picks do draft</div>
  <div class="pg-ver" style="margin-bottom:12px">
    Confere se cada escolha está com o dono certo — trocas, swaps e proteções.
    <b>Revisar</b> só compara e não altera nada; <b>Ajustar</b> grava, pondo cada
    escolha com o dono atual da pick.
  </div>

  <?php if (!$ligasComDraft): ?>
    <div class="item">Nenhuma liga com draft aberto agora — nada a conferir.</div>
  <?php else: ?>
    <div class="item" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
      <select id="devPickLiga" style="padding:7px 10px;border-radius:8px">
        <?php foreach ($ligasComDraft as $lg): ?>
          <option value="<?= h($lg) ?>"><?= h($lg) ?></option>
        <?php endforeach; ?>
      </select>
      <button type="button" onclick="devRevisarPicks()">Revisar picks</button>
      <button type="button" onclick="devAjustarPicks()"
              title="Põe cada escolha com o dono atual da pick, resolvendo trocas e swaps">Ajustar picks</button>
    </div>
    <div id="devRevisao" style="margin-top:12px"></div>
  <?php endif; ?>
</div>

<script>
const devEsc = s => String(s ?? '').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));

/** A sessão de draft aberta da liga escolhida. */
async function devSessao(liga) {
  const r = await fetch('/api/draft.php?action=active_draft&league=' + encodeURIComponent(liga));
  const d = await r.json();
  return d?.draft?.id || d?.draft_session?.id || null;
}

async function devRevisarPicks() {
  const box = document.getElementById('devRevisao');
  const liga = document.getElementById('devPickLiga').value;
  box.innerHTML = '<div class="pg-ver">Conferindo…</div>';
  try {
    const id = await devSessao(liga);
    if (!id) { box.innerHTML = '<div class="pg-ver">A ' + devEsc(liga) + ' não tem draft montado agora.</div>'; return; }
    const r = await fetch('/api/draft.php?action=conferir_picks&draft_session_id=' + id);
    const d = await r.json();
    if (d.success === false) throw new Error(d.error || 'falhou');

    const bloco = (titulo, cor, linhas) => linhas.length ? `
      <div style="border:1px solid ${cor}55;background:${cor}18;border-radius:10px;padding:11px 13px;margin-bottom:9px">
        <div style="font-weight:700;font-size:12.5px;color:${cor};margin-bottom:6px">${titulo} (${linhas.length})</div>
        <div style="font-size:12px;line-height:1.7;opacity:.85">${linhas.join('<br>')}</div>
      </div>` : '';

    const partes = [
      bloco('Escolhas com o dono errado', '#fc0025',
        (d.divergencias || []).map(x => `<b>${x.rodada}ª rodada, pick ${x.pick}</b> (de ${devEsc(x.origem_nome)}): `
          + `está com <b>${devEsc(x.esta_com_nome)}</b>, deveria ser <b>${devEsc(x.deveria_nome)}</b>`
          + (x.swap ? ' — por swap' : ''))),
      bloco('Time dono de mais de uma vaga na mesma rodada', '#fc0025',
        (d.origem_repetida || []).map(x => `<b>${devEsc(x.time_nome)}</b> aparece como dono das picks `
          + `${x.picks.join(', ')} na ${x.rodada}ª rodada`)),
      bloco('Proteções ainda não resolvidas', '#f59e0b',
        (d.protecoes || []).map(x => `<b>Pick ${x.pick}</b> (de ${devEsc(x.origem_nome)}, com ${devEsc(x.dono_nome)}) — proteção ${devEsc(x.protecao)}`)),
      bloco('Sem pick cadastrada — a vaga fica com o time de origem', '#f59e0b',
        (d.sem_pick || []).map(x => `<b>${x.rodada}ª rodada, pick ${x.pick}</b> — ${devEsc(x.origem_nome)} não tem pick deste ano na tabela`)),
      bloco('Swaps resolvidos', '#22c55e',
        (d.swaps || []).map(x => `<b>${devEsc(x.melhor_dono_nome)}</b> ficou com a pick ${x.melhor_pick} (de ${devEsc(x.melhor_de_nome)}) e `
          + `<b>${devEsc(x.pior_dono_nome)}</b> com a ${x.pior_pick} (de ${devEsc(x.pior_de_nome)})`)),
    ];

    const problema = (d.divergencias || []).length || (d.sem_pick || []).length || (d.protecoes || []).length;
    box.innerHTML = `<div class="pg-ver">Draft de <b>${d.ano}</b> · ${d.vagas} escolhas conferidas</div>`
      + (problema ? partes.join('')
         : `<div style="border:1px solid #22c55e55;background:#22c55e18;border-radius:10px;padding:11px 13px;margin-bottom:9px">
              <b style="color:#22c55e;font-size:12.5px">Está tudo certo</b>
              <div style="font-size:12px;opacity:.85;margin-top:3px">Cada escolha está com o dono da pick.</div>
            </div>` + partes.join(''));
  } catch (e) {
    box.innerHTML = '<div class="pg-ver" style="color:#fca5a5">Erro: ' + devEsc(e.message || 'não deu pra conferir') + '</div>';
  }
}

async function devAjustarPicks() {
  const liga = document.getElementById('devPickLiga').value;
  if (!confirm('Ajustar as picks da ' + liga + '? Cada escolha vai pro dono atual da pick.')) return;
  const box = document.getElementById('devRevisao');
  try {
    const id = await devSessao(liga);
    if (!id) { box.innerHTML = '<div class="pg-ver">A ' + devEsc(liga) + ' não tem draft montado agora.</div>'; return; }
    const r = await fetch('/api/draft.php', {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'ajustar_picks', draft_session_id: id })
    });
    const d = await r.json();
    if (d.success === false) throw new Error(d.error || 'falhou');
    // A revisão roda logo depois: quem clicou precisa VER que ficou certo,
    // e não só ler que mexeu em três vagas.
    await devRevisarPicks();
    box.insertAdjacentHTML('afterbegin',
      `<div style="border:1px solid #22c55e55;background:#22c55e18;border-radius:10px;padding:10px 13px;margin-bottom:9px;font-size:12.5px">
         <b style="color:#22c55e">${devEsc(d.message || 'Ajustado.')}</b></div>`);
  } catch (e) {
    alert('Erro: ' + (e.message || 'não deu pra ajustar'));
  }
}
</script>
</div>
</body>
</html>
