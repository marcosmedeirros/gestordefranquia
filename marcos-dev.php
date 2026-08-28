<?php
/**
 * Página de correções — só do dono da liga.
 *
 * Cada bloco é um DIAGNÓSTICO que roda de verdade contra o banco, e quando
 * existe conserto seguro, o botão que o aplica. Nada aqui adivinha: se a
 * correção depende de uma decisão humana (refazer uma loteria, por exemplo),
 * o bloco explica e não oferece botão.
 *
 * Por que existe: os defeitos que apareceram na liga foram quase todos da
 * mesma família — sobra de sprint encerrado poluindo consulta que não filtra
 * sprint. Achar isso pelo log é lento; aqui é uma tela.
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
$linhaUnica = devLinhaUnicaRepetida($pdo);
$tempDup    = devTemporadasPresas($pdo);

$totalProblemas = count($zumbis) + count($repetidas) + count($ordemFora)
                + count($anosVazios) + count($linhaUnica) + count($tempDup);

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
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
  .wrap{max-width:940px;margin:0 auto}
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
  @media(max-width:520px){ .achado{flex-direction:column;align-items:stretch} button{width:100%} }
</style>
</head>
<body>
<div class="wrap">
  <a class="voltar" href="/admin.php"><i class="bi bi-arrow-left"></i> Admin</a>
  <h1>Correções</h1>
  <p class="sub">Diagnósticos rodando contra o banco agora. Onde o conserto é seguro, tem botão.</p>

  <?php if ($aviso): ?>
    <div class="aviso <?= h($aviso[0]) ?>"><?= h($aviso[1]) ?></div>
  <?php endif; ?>

  <div class="placar <?= $totalProblemas ? 'sujo' : 'limpo' ?>">
    <i class="bi bi-<?= $totalProblemas ? 'exclamation-triangle-fill' : 'check-circle-fill' ?>"></i>
    <?= $totalProblemas ? $totalProblemas . ' ponto(s) para olhar' : 'Nada pendente' ?>
  </div>

  <!-- 1 ─────────────────────────────────────────────────────────────────── -->
  <div class="bloco">
    <h2><i class="bi bi-hourglass-bottom" style="color:var(--red)"></i> Draft aberto em sprint encerrado</h2>
    <p class="por">Sessão que ficou <code>setup</code>/<code>in_progress</code> numa temporada de sprint
       fechado. Ela vence as consultas que procuram "o draft da liga", e aí as picks se ligam a um draft
       que não existe mais — foi assim que a NEXT passou a apontar para um draft de 2044.</p>
    <?php if (!$zumbis): ?>
      <p class="ok"><i class="bi bi-check2"></i> Nenhuma.</p>
    <?php else: foreach ($zumbis as $z): ?>
      <div class="achado">
        <span class="tag"><?= h($z['league']) ?></span>
        <span class="txt">Sessão <b>#<?= (int)$z['id'] ?></b> (<?= h($z['status']) ?>) na temporada
          <b>#<?= h($z['season_number']) ?></b>, ano <?= h($z['year']) ?> — sprint <?= h($z['sprint_number']) ?>
          está <b><?= h($z['sprint_status']) ?></b>. <?= (int)$z['vagas'] ?> vaga(s).</span>
        <form method="post" onsubmit="return confirm('Encerrar a sessão #<?= (int)$z['id'] ?>?')">
          <input type="hidden" name="acao" value="fechar_sessao">
          <input type="hidden" name="id" value="<?= (int)$z['id'] ?>">
          <button class="perigo" type="submit">Encerrar sessão</button>
        </form>
      </div>
    <?php endforeach; endif; ?>
  </div>

  <!-- 2 ─────────────────────────────────────────────────────────────────── -->
  <div class="bloco">
    <h2><i class="bi bi-files" style="color:var(--red)"></i> Ordem do draft com time repetido</h2>
    <p class="por">A vaga pertence ao time de ORIGEM, e é por (origem, rodada, ano) que a pick se liga a
       ela. Com o mesmo time em duas vagas da mesma rodada, N vagas apontam para uma pick só e a ligação
       morre: quem escolhe para de bater com o dono e o número da escolha some da Trade Machine.</p>
    <?php if (!$repetidas): ?>
      <p class="ok"><i class="bi bi-check2"></i> Nenhuma.</p>
    <?php else: foreach ($repetidas as $r): ?>
      <div class="achado">
        <span class="tag"><?= h($r['liga']) ?></span>
        <span class="txt">Sessão <b>#<?= (int)$r['sessao'] ?></b> (ano <?= h($r['ano']) ?>):
          <b><?= count($r['casos']) ?></b> time(s) repetido(s), <?= (int)$r['divergencias'] ?> vaga(s) divergente(s).
          <?php $ex = $r['casos'][0] ?? null; if ($ex): ?>
            Ex.: um time com <?= count($ex['picks']) ?> vagas na rodada <?= h($ex['rodada']) ?>.
          <?php endif; ?></span>
      </div>
    <?php endforeach; ?>
      <p class="nota">Sem botão de propósito: não dá para adivinhar de quem era cada vaga repetida.
         Essa ordem precisa ser refeita pela loteria.</p>
    <?php endif; ?>
  </div>

  <!-- 3 ─────────────────────────────────────────────────────────────────── -->
  <div class="bloco">
    <h2><i class="bi bi-people" style="color:var(--amber)"></i> Quem escolhe fora do dono da pick</h2>
    <p class="por">O <code>team_id</code> da ordem é um cache de quem é dono da pick. Sincronizar reescreve
       esse cache a partir das picks, resolvendo compra, swap e proteção. É idempotente.</p>
    <?php if (!$ordemFora): ?>
      <p class="ok"><i class="bi bi-check2"></i> Tudo batendo.</p>
    <?php else: foreach ($ordemFora as $o): ?>
      <div class="achado">
        <span class="tag"><?= h($o['liga']) ?></span>
        <span class="txt">Sessão <b>#<?= (int)$o['sessao'] ?></b> (ano <?= h($o['ano']) ?>,
          <?= (int)$o['vagas'] ?> vagas): <b><?= (int)$o['divergencias'] ?></b> divergente(s),
          <b><?= (int)$o['sem_pick'] ?></b> sem pick por trás.</span>
        <form method="post">
          <input type="hidden" name="acao" value="sincronizar_ordem">
          <input type="hidden" name="liga" value="<?= h($o['liga']) ?>">
          <button type="submit">Sincronizar ordem</button>
        </form>
      </div>
    <?php endforeach; ?>
      <p class="nota">Vaga sem pick não se resolve aqui: use <b>Ajustar Picks</b> no card de Draft da liga.</p>
    <?php endif; ?>
  </div>

  <!-- 4 ─────────────────────────────────────────────────────────────────── -->
  <div class="bloco">
    <h2><i class="bi bi-calendar-x" style="color:var(--amber)"></i> Ano com picks faltando</h2>
    <p class="por">Cada time deveria ter uma pick de 1ª e uma de 2ª em cada ano da faixa. Ano com menos que
       isso é buraco — normalmente de pick realocada para outro ano.</p>
    <?php if (!$anosVazios): ?>
      <p class="ok"><i class="bi bi-check2"></i> Todos os anos completos.</p>
    <?php else: foreach ($anosVazios as $a): ?>
      <div class="achado">
        <span class="tag"><?= h($a['liga']) ?></span>
        <span class="txt">
          <?php foreach (array_slice($a['falhas'], 0, 8) as $f): ?>
            <?= (int)$f['ano'] ?>: <b><?= (int)$f['tem'] ?></b>/<?= (int)$f['esperado'] ?> &nbsp;
          <?php endforeach; ?>
          <?php if (count($a['falhas']) > 8): ?> … <?= count($a['falhas']) - 8 ?> ano(s) a mais<?php endif; ?>
        </span>
      </div>
    <?php endforeach; ?>
      <p class="nota">Conserto: <b>Ajustar Picks</b> no card de Draft da liga — ele cria o que falta e não
         toca em pick negociada.</p>
    <?php endif; ?>
  </div>

  <!-- 5 ─────────────────────────────────────────────────────────────────── -->
  <div class="bloco">
    <h2><i class="bi bi-layers" style="color:var(--amber)"></i> Tabela de linha única com linha repetida</h2>
    <p class="por">Tabela que deveria ter uma linha só e foi ganhando cópias — sinal de código que insere
       onde devia atualizar. Cresce para sempre e impede a chave primária.</p>
    <?php if (!$linhaUnica): ?>
      <p class="ok"><i class="bi bi-check2"></i> Nenhuma.</p>
    <?php else: foreach ($linhaUnica as $l): ?>
      <div class="achado">
        <span class="txt"><code><?= h($l['tabela']) ?></code> tem <b><?= (int)$l['linhas'] ?></b> linhas
          com a mesma chave <code><?= h($l['chave']) ?></code>.</span>
        <form method="post" onsubmit="return confirm('Deixar só a linha mais recente de <?= h($l['tabela']) ?>?')">
          <input type="hidden" name="acao" value="dedup_linha_unica">
          <input type="hidden" name="tabela" value="<?= h($l['tabela']) ?>">
          <button class="perigo" type="submit">Deixar só a mais recente</button>
        </form>
      </div>
    <?php endforeach; ?>
      <p class="nota">Limpar resolve o sintoma. A causa é o código que grava — vale achar quem insere.</p>
    <?php endif; ?>
  </div>

  <!-- 6 ─────────────────────────────────────────────────────────────────── -->
  <div class="bloco">
    <h2><i class="bi bi-signpost-split" style="color:var(--amber)"></i> Temporada de sprint encerrado ainda em aberto</h2>
    <p class="por">Número de temporada repetido na liga é normal — cada sprint recomeça do #1. O que não é
       normal é uma temporada de sprint já fechado seguir sem <code>completed</code>: aí as consultas que
       procuram "a temporada corrente" podem pescar ela. Fechar mexe só no status; playoffs, pontuação e
       campeão dela ficam onde estão.</p>
    <?php if (!$tempDup): ?>
      <p class="ok"><i class="bi bi-check2"></i> Nenhuma.</p>
    <?php else: foreach ($tempDup as $t): ?>
      <div class="achado">
        <span class="tag"><?= h($t['league']) ?></span>
        <span class="txt">Temporada <b>#<?= h($t['season_number']) ?></b> (id <?= (int)$t['id'] ?>,
          ano <?= h($t['year']) ?>) está <b><?= h($t['status'] ?? 'NULL') ?></b> — sprint
          <?= h($t['sprint_number']) ?> já encerrou, e <?= (int)$t['irmas_fechadas'] ?> temporada(s) dele
          estão completed.</span>
        <form method="post" onsubmit="return confirm('Marcar a temporada id <?= (int)$t['id'] ?> como completed?')">
          <input type="hidden" name="acao" value="fechar_temporada">
          <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
          <button type="submit">Marcar como completed</button>
        </form>
      </div>
    <?php endforeach; endif; ?>
  </div>
</div>
</body>
</html>
