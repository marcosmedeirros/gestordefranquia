<?php
/**
 * buildplayer.php — Build-A-Player
 *
 * Escolhe Guard ou Big, gira as duas roletas (time + lenda) e leva UM atributo
 * da lenda sorteada pro seu build. Dez giros, dez slots, um OVR no fim.
 *
 * O sorteio é do SERVIDOR, sempre. Se o giro fosse do cliente dava pra ficar
 * regirando até cair a lenda certa — e o ranking não valeria nada.
 *
 * Incluído por games/games/index.php — $pdo e $_SESSION já disponíveis.
 */

require_once __DIR__ . '/../core/build_notas.php';

$user_id = (int)$_SESSION['user_id'];
$hoje    = date('Y-m-d');

// Recompensas por posição no Top 100 (o que você pediu).
const BP_MOEDAS = [1 => 500, 5 => 100, 10 => 50];

buildGarantirTabelaNotas($pdo);

function bpGarantirTabelas(PDO $pdo): void
{
    static $pronto = false;
    if ($pronto) return;
    $pronto = true;

    $pdo->exec("CREATE TABLE IF NOT EXISTS build_partidas (
        id INT AUTO_INCREMENT PRIMARY KEY,
        id_usuario INT NOT NULL,
        data_jogo DATE NOT NULL,
        grupo ENUM('GUARD','BIG') NOT NULL,
        slots TEXT NOT NULL,
        usados TEXT NOT NULL,
        atual_player_id INT NULL,
        ovr INT NULL,
        posicao_rank INT NULL,
        moedas INT NOT NULL DEFAULT 0,
        concluido_em DATETIME NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_bp_dia (id_usuario, data_jogo),
        INDEX idx_bp_rank (ovr)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
bpGarantirTabelas($pdo);

/** Partida do dia (ou null). */
function bpPartida(PDO $pdo, int $userId, string $hoje): ?array
{
    $st = $pdo->prepare("SELECT * FROM build_partidas WHERE id_usuario=? AND data_jogo=? LIMIT 1");
    $st->execute([$userId, $hoje]);
    $p = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($p) {
        $p['slots']  = json_decode((string)$p['slots'], true) ?: [];
        $p['usados'] = json_decode((string)$p['usados'], true) ?: [];
    }
    return $p;
}

/** Lenda com nota, do grupo pedido, que ainda não saiu nesta partida. */
function bpSortearLenda(PDO $pdo, string $grupo, array $usados): ?array
{
    $fora = '';
    $params = [$grupo];
    if ($usados) {
        $fora = ' AND n.player_id NOT IN (' . implode(',', array_fill(0, count($usados), '?')) . ')';
        $params = array_merge($params, array_map('intval', $usados));
    }

    $st = $pdo->prepare("SELECT n.*, p.nome, p.time_atual, p.times, p.altura, p.peso
                         FROM build_notas n
                         INNER JOIN hoopgrid_players p ON p.id = n.player_id
                         WHERE n.posicao_grupo = ?{$fora}
                         ORDER BY RAND() LIMIT 1");
    $st->execute($params);
    $l = $st->fetch(PDO::FETCH_ASSOC) ?: null;

    // Pool menor que os 10 slots: repete lenda em vez de deixar a partida
    // sem saída. Travar aqui prenderia o jogador — ele não terminaria o build
    // nem poderia começar outro, porque só se joga uma vez por dia.
    if (!$l && $usados) {
        $st2 = $pdo->prepare("SELECT n.*, p.nome, p.time_atual, p.times, p.altura, p.peso
                              FROM build_notas n
                              INNER JOIN hoopgrid_players p ON p.id = n.player_id
                              WHERE n.posicao_grupo = ? ORDER BY RAND() LIMIT 1");
        $st2->execute([$grupo]);
        $l = $st2->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    if (!$l) return null;

    // Time exibido na roleta: o atual, ou o primeiro da carreira.
    $time = $l['time_atual'] ?: '';
    if ($time === '' && !empty($l['times'])) {
        $lista = json_decode((string)$l['times'], true);
        if (is_array($lista) && $lista) $time = (string)$lista[0];
    }
    $l['time_exibido'] = $time ?: 'NBA';
    return $l;
}

/** OVR do build: média dos valores das dez letras escolhidas. */
function bpCalcularOvr(array $slots): int
{
    $attrs = array_keys(buildAtributos());
    $soma = 0;
    foreach ($attrs as $a) {
        $soma += buildValorDaLetra((int)($slots[$a]['nivel'] ?? 0));
    }
    return (int)round($soma / count($attrs));
}

/**
 * Posição no Top 100 de todos os tempos. Empate no OVR desempata pela
 * partida mais antiga — quem chegou primeiro fica na frente.
 */
function bpPosicaoNoRank(PDO $pdo, int $ovr, int $partidaId): int
{
    $st = $pdo->prepare("SELECT COUNT(*) + 1 FROM build_partidas
                         WHERE concluido_em IS NOT NULL
                           AND (ovr > ? OR (ovr = ? AND id < ?))");
    $st->execute([$ovr, $ovr, $partidaId]);
    return (int)$st->fetchColumn();
}

/** Moedas conforme a posição. Fora do top 10 não paga. */
function bpMoedasDaPosicao(int $pos): int
{
    if ($pos <= 1)  return BP_MOEDAS[1];
    if ($pos <= 5)  return BP_MOEDAS[5];
    if ($pos <= 10) return BP_MOEDAS[10];
    return 0;
}

// ── AÇÕES (AJAX) ────────────────────────────────────────────────────────────
if (($_POST['acao'] ?? '') !== '') {
    header('Content-Type: application/json; charset=utf-8');
    $acao = $_POST['acao'];
    $attrs = array_keys(buildAtributos());

    try {
        if ($acao === 'comecar') {
            $grupo = ($_POST['grupo'] ?? '') === 'BIG' ? 'BIG' : 'GUARD';
            if (bpPartida($pdo, $user_id, $hoje)) {
                echo json_encode(['ok' => false, 'msg' => 'Você já jogou hoje. Volte amanhã!']);
                exit;
            }
            // Sem lenda no grupo não dá pra montar nada — melhor avisar antes
            // do que abrir uma partida que não fecha.
            $st = $pdo->prepare("SELECT COUNT(*) FROM build_notas WHERE posicao_grupo = ?");
            $st->execute([$grupo]);
            if ((int)$st->fetchColumn() === 0) {
                echo json_encode(['ok' => false, 'msg' => 'Ainda não há lendas cadastradas nesse tipo de build.']);
                exit;
            }
            $vazios = [];
            foreach ($attrs as $a) $vazios[$a] = null;

            $pdo->prepare("INSERT INTO build_partidas (id_usuario, data_jogo, grupo, slots, usados) VALUES (?,?,?,?,?)")
                ->execute([$user_id, $hoje, $grupo, json_encode($vazios), json_encode([])]);

            echo json_encode(['ok' => true]);
            exit;
        }

        $partida = bpPartida($pdo, $user_id, $hoje);
        if (!$partida) { echo json_encode(['ok' => false, 'msg' => 'Comece uma partida primeiro.']); exit; }
        if ($partida['concluido_em']) { echo json_encode(['ok' => false, 'msg' => 'Esta partida já terminou.']); exit; }

        if ($acao === 'girar') {
            // Uma lenda por vez: só gira de novo depois de escolher o atributo.
            if (!empty($partida['atual_player_id'])) {
                echo json_encode(['ok' => false, 'msg' => 'Escolha um atributo da lenda atual antes de girar.']);
                exit;
            }
            $lenda = bpSortearLenda($pdo, $partida['grupo'], $partida['usados']);
            if (!$lenda) { echo json_encode(['ok' => false, 'msg' => 'Acabaram as lendas disponíveis.']); exit; }

            $pdo->prepare("UPDATE build_partidas SET atual_player_id=? WHERE id=?")
                ->execute([(int)$lenda['player_id'], (int)$partida['id']]);

            $notas = [];
            foreach ($attrs as $a) {
                $notas[$a] = ['nivel' => (int)$lenda[$a], 'letra' => BUILD_LETRAS[(int)$lenda[$a]]];
            }
            echo json_encode([
                'ok' => true,
                'lenda' => [
                    'id'     => (int)$lenda['player_id'],
                    'nome'   => $lenda['nome'],
                    'time'   => $lenda['time_exibido'],
                    'altura' => $lenda['altura'],
                    'peso'   => $lenda['peso'],
                    'notas'  => $notas,
                ],
            ]);
            exit;
        }

        if ($acao === 'escolher') {
            $attr = (string)($_POST['atributo'] ?? '');
            if (!in_array($attr, $attrs, true)) { echo json_encode(['ok' => false, 'msg' => 'Atributo inválido.']); exit; }
            if (empty($partida['atual_player_id'])) { echo json_encode(['ok' => false, 'msg' => 'Gire primeiro.']); exit; }
            if ($partida['slots'][$attr] !== null) { echo json_encode(['ok' => false, 'msg' => 'Esse slot já está preenchido.']); exit; }

            $st = $pdo->prepare("SELECT n.*, p.nome FROM build_notas n
                                 INNER JOIN hoopgrid_players p ON p.id = n.player_id
                                 WHERE n.player_id = ? LIMIT 1");
            $st->execute([(int)$partida['atual_player_id']]);
            $lenda = $st->fetch(PDO::FETCH_ASSOC);
            if (!$lenda) { echo json_encode(['ok' => false, 'msg' => 'Lenda não encontrada.']); exit; }

            $slots = $partida['slots'];
            $slots[$attr] = [
                'nivel' => (int)$lenda[$attr],
                'letra' => BUILD_LETRAS[(int)$lenda[$attr]],
                'de'    => $lenda['nome'],
            ];
            $usados = $partida['usados'];
            $usados[] = (int)$partida['atual_player_id'];

            $faltam = count(array_filter($slots, fn($s) => $s === null));
            $terminou = $faltam === 0;

            $ovr = $terminou ? bpCalcularOvr($slots) : null;
            $pdo->prepare("UPDATE build_partidas SET slots=?, usados=?, atual_player_id=NULL, ovr=?, concluido_em=? WHERE id=?")
                ->execute([
                    json_encode($slots), json_encode($usados), $ovr,
                    $terminou ? date('Y-m-d H:i:s') : null, (int)$partida['id'],
                ]);

            $resposta = ['ok' => true, 'slots' => $slots, 'faltam' => $faltam, 'terminou' => $terminou];

            if ($terminou) {
                $pos = bpPosicaoNoRank($pdo, (int)$ovr, (int)$partida['id']);
                $moedas = bpMoedasDaPosicao($pos);
                $pdo->prepare("UPDATE build_partidas SET posicao_rank=?, moedas=? WHERE id=?")
                    ->execute([$pos, $moedas, (int)$partida['id']]);
                if ($moedas > 0) {
                    $pdo->prepare("UPDATE games_usuarios SET pontos = pontos + ? WHERE id = ?")
                        ->execute([$moedas, $user_id]);
                }
                $resposta += ['ovr' => $ovr, 'posicao' => $pos, 'moedas' => $moedas];
            }

            echo json_encode($resposta);
            exit;
        }

        echo json_encode(['ok' => false, 'msg' => 'Ação desconhecida.']);
    } catch (Throwable $e) {
        error_log('[buildplayer] ' . $e->getMessage());
        echo json_encode(['ok' => false, 'msg' => 'Erro interno. Tente de novo.']);
    }
    exit;
}

// ── TELA ────────────────────────────────────────────────────────────────────
$partida  = bpPartida($pdo, $user_id, $hoje);
$ATRIBUTOS = buildAtributos();

$temNotas = (int)$pdo->query("SELECT COUNT(*) FROM build_notas")->fetchColumn();

// A posição exibida é recalculada AGORA, não a gravada no fim da partida:
// se alguém te ultrapassou depois, o certo é mostrar onde você está hoje.
// As moedas continuam sendo as da posição no momento em que você fechou —
// prêmio já pago não se recalcula.
$posicaoAtual = null;
if ($partida && $partida['concluido_em']) {
    $posicaoAtual = bpPosicaoNoRank($pdo, (int)$partida['ovr'], (int)$partida['id']);
}

// Top 10 de todos os tempos, pro quadro lateral.
$topGeral = $pdo->query("SELECT b.ovr, b.grupo, u.nome
                         FROM build_partidas b
                         INNER JOIN games_usuarios u ON u.id = b.id_usuario
                         WHERE b.concluido_em IS NOT NULL
                         ORDER BY b.ovr DESC, b.id ASC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
    .bp-wrap { max-width: 1080px; margin: 0 auto; }
    .bp-head { text-align: center; margin-bottom: 18px; }
    .bp-head h2 { font-size: 22px; font-weight: 900; margin: 0 0 6px; letter-spacing: -.3px; }
    .bp-head p { font-size: 13px; color: #9aa; margin: 0; }

    .bp-grid { display: grid; grid-template-columns: 1fr 320px; gap: 16px; align-items: start; }
    @media (max-width: 900px) { .bp-grid { grid-template-columns: 1fr; } }

    .bp-card { background: #14141a; border: 1px solid rgba(255,255,255,.08); border-radius: 14px; padding: 16px; }
    .bp-card h3 { font-size: 13px; font-weight: 800; text-transform: uppercase; letter-spacing: .8px; color: #8b8b96; margin: 0 0 12px; }

    /* Escolha inicial */
    .bp-escolha { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
    .bp-tipo { background: #1b1b23; border: 2px solid rgba(255,255,255,.08); border-radius: 14px; padding: 22px 16px; cursor: pointer; text-align: center; transition: all .18s; }
    .bp-tipo:hover { border-color: #f59e0b; transform: translateY(-2px); }
    .bp-tipo b { display: block; font-size: 19px; font-weight: 900; margin-bottom: 4px; }
    .bp-tipo span { font-size: 11.5px; color: #8b8b96; }

    /* Roletas */
    .bp-reels { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 14px; }
    .bp-reel { background: #0e0e13; border: 1px solid rgba(255,255,255,.08); border-radius: 12px; padding: 14px; text-align: center; min-height: 92px; display: flex; flex-direction: column; justify-content: center; }
    .bp-reel-label { font-size: 10px; font-weight: 800; letter-spacing: 1.2px; color: #6b6b76; margin-bottom: 6px; }
    .bp-reel-valor { font-size: 17px; font-weight: 900; line-height: 1.15; }
    .bp-reel.girando .bp-reel-valor { animation: bpFlick .09s steps(1) infinite; }
    @keyframes bpFlick { 0%,100% { opacity: 1 } 50% { opacity: .25 } }

    .bp-spin { width: 100%; background: #f59e0b; color: #17171d; border: 0; border-radius: 12px; padding: 15px; font-size: 15px; font-weight: 900; cursor: pointer; letter-spacing: .3px; }
    .bp-spin:disabled { background: #26262f; color: #6b6b76; cursor: not-allowed; }

    /* Notas da lenda sorteada */
    .bp-notas { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 8px; margin-top: 14px; }
    .bp-nota { background: #1b1b23; border: 1px solid rgba(255,255,255,.08); border-radius: 10px; padding: 10px 12px; display: flex; align-items: center; justify-content: space-between; gap: 8px; cursor: pointer; transition: all .15s; }
    .bp-nota:hover:not(.ocupado) { border-color: #f59e0b; background: #23232c; }
    .bp-nota.ocupado { opacity: .35; cursor: not-allowed; }
    .bp-nota-label { font-size: 11.5px; font-weight: 600; }
    .bp-nota-letra { font-size: 16px; font-weight: 900; flex-shrink: 0; }

    /* Slots do build */
    .bp-slots { display: flex; flex-direction: column; gap: 6px; }
    .bp-slot { display: flex; align-items: center; justify-content: space-between; gap: 8px; background: #1b1b23; border: 1px solid rgba(255,255,255,.06); border-radius: 9px; padding: 8px 11px; }
    .bp-slot-nome { font-size: 11.5px; font-weight: 600; color: #b9b9c4; }
    .bp-slot-de { font-size: 9.5px; color: #6b6b76; display: block; }
    .bp-slot-letra { font-size: 15px; font-weight: 900; flex-shrink: 0; }
    .bp-slot.vazio .bp-slot-letra { color: #3a3a45; }

    .bp-ovr { text-align: center; padding: 16px 0 4px; }
    .bp-ovr-num { font-size: 44px; font-weight: 900; line-height: 1; }
    .bp-ovr-label { font-size: 10px; font-weight: 800; letter-spacing: 1.4px; color: #6b6b76; }

    .bp-fim { text-align: center; padding: 22px 16px; }
    .bp-fim h3 { font-size: 15px; color: #e6e6ee; margin-bottom: 10px; }
    .bp-fim-pos { font-size: 32px; font-weight: 900; color: #f59e0b; }
    .bp-fim-moedas { font-size: 14px; font-weight: 700; color: #22c55e; margin-top: 8px; }

    .bp-rank-item { display: flex; align-items: center; gap: 9px; padding: 7px 0; border-bottom: 1px solid rgba(255,255,255,.05); font-size: 12px; }
    .bp-rank-pos { width: 22px; font-weight: 900; color: #6b6b76; flex-shrink: 0; }
    .bp-rank-nome { flex: 1; min-width: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .bp-rank-ovr { font-weight: 900; color: #f59e0b; }

    .bp-aviso { background: rgba(245,158,11,.1); border: 1px solid rgba(245,158,11,.3); border-radius: 10px; padding: 12px 14px; font-size: 12.5px; color: #f0c674; }
</style>

<div class="bp-wrap">
    <div class="bp-head">
        <h2>🏗️ Build-A-Player</h2>
        <p>Gire a roleta, pegue um atributo de cada lenda e monte o jogador perfeito. Uma partida por dia.</p>
    </div>

    <?php if (!$temNotas): ?>
        <div class="bp-aviso">
            <b>Base de notas ainda vazia.</b> Rode a sincronização em
            <a href="/games/admin/dadosjogadores.php" style="color:#f59e0b">Dados dos Jogadores</a>
            pra gerar as notas das lendas antes de liberar o jogo.
        </div>
    <?php else: ?>

    <div class="bp-grid">
        <div>
            <?php if (!$partida): ?>
                <div class="bp-card">
                    <h3>Escolha o tipo de build</h3>
                    <div class="bp-escolha">
                        <div class="bp-tipo" onclick="bpComecar('GUARD')">
                            <b>GUARD</b><span>PG · SG · SF</span>
                        </div>
                        <div class="bp-tipo" onclick="bpComecar('BIG')">
                            <b>BIG</b><span>PF · C</span>
                        </div>
                    </div>
                </div>
            <?php elseif ($partida['concluido_em']): ?>
                <div class="bp-card bp-fim">
                    <h3>Seu build de hoje</h3>
                    <div class="bp-ovr-num" style="color:#f59e0b"><?= (int)$partida['ovr'] ?></div>
                    <div class="bp-ovr-label">OVR · <?= htmlspecialchars($partida['grupo']) ?></div>
                    <div style="margin-top:14px">
                        <div class="bp-fim-pos">#<?= (int)($posicaoAtual ?? $partida['posicao_rank']) ?></div>
                        <div style="font-size:11px;color:#8b8b96">no ranking de todos os tempos</div>
                        <?php if ($posicaoAtual && (int)$posicaoAtual !== (int)$partida['posicao_rank']): ?>
                        <div style="font-size:10.5px;color:#6b6b76;margin-top:4px">
                            você fechou em #<?= (int)$partida['posicao_rank'] ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php if ((int)$partida['moedas'] > 0): ?>
                        <div class="bp-fim-moedas">+<?= (int)$partida['moedas'] ?> moedas 🪙</div>
                    <?php else: ?>
                        <div style="font-size:12px;color:#8b8b96;margin-top:10px">Top 10 paga moedas. Amanhã tem mais!</div>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="bp-card">
                    <div class="bp-reels">
                        <div class="bp-reel" id="bpReelTime">
                            <div class="bp-reel-label">TIME</div>
                            <div class="bp-reel-valor" id="bpTime">—</div>
                        </div>
                        <div class="bp-reel" id="bpReelLenda">
                            <div class="bp-reel-label">LENDA</div>
                            <div class="bp-reel-valor" id="bpLenda">—</div>
                        </div>
                    </div>
                    <button class="bp-spin" id="bpSpin" onclick="bpGirar()">GIRAR</button>
                    <div id="bpMsg" style="font-size:12px;color:#8b8b96;text-align:center;margin-top:10px">
                        Gire pra sortear uma lenda e escolher um atributo dela.
                    </div>
                    <div class="bp-notas" id="bpNotas"></div>
                </div>
            <?php endif; ?>
        </div>

        <div>
            <div class="bp-card" style="margin-bottom:14px">
                <h3>Seu build <span id="bpFaltam" style="float:right;color:#f59e0b"></span></h3>
                <div class="bp-slots" id="bpSlots">
                    <?php foreach ($ATRIBUTOS as $chave => $info):
                        $s = $partida['slots'][$chave] ?? null; ?>
                    <div class="bp-slot <?= $s ? '' : 'vazio' ?>" data-attr="<?= $chave ?>">
                        <div>
                            <span class="bp-slot-nome"><?= htmlspecialchars($info['label']) ?></span>
                            <span class="bp-slot-de"><?= $s ? htmlspecialchars($s['de']) : '—' ?></span>
                        </div>
                        <span class="bp-slot-letra"><?= $s ? htmlspecialchars($s['letra']) : '–' ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div class="bp-ovr">
                    <div class="bp-ovr-num" id="bpOvr"><?= $partida && $partida['ovr'] ? (int)$partida['ovr'] : '–' ?></div>
                    <div class="bp-ovr-label">OVR</div>
                </div>
            </div>

            <div class="bp-card">
                <h3>Top 10 de todos os tempos</h3>
                <?php if (!$topGeral): ?>
                    <div style="font-size:12px;color:#6b6b76">Ninguém montou um build ainda. Seja o primeiro.</div>
                <?php else: foreach ($topGeral as $i => $t): ?>
                    <div class="bp-rank-item">
                        <span class="bp-rank-pos"><?= $i + 1 ?>º</span>
                        <span class="bp-rank-nome"><?= htmlspecialchars($t['nome']) ?></span>
                        <span class="bp-rank-ovr"><?= (int)$t['ovr'] ?></span>
                    </div>
                <?php endforeach; endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
const BP_ATTRS  = <?= json_encode(array_map(fn($a) => $a['label'], $ATRIBUTOS)) ?>;
const BP_CORES  = ['#ef4444','#ef4444','#f97316','#f59e0b','#f59e0b','#eab308','#84cc16','#22c55e','#22c55e','#06b6d4','#3b82f6','#a855f7'];
const BP_LETRAS = <?= json_encode(BUILD_LETRAS) ?>;
let bpOcupado = false;

async function bpPost(dados) {
    const body = new URLSearchParams(dados);
    const res = await fetch(window.location.href, { method: 'POST', body });
    return res.json();
}

async function bpComecar(grupo) {
    if (bpOcupado) return;
    bpOcupado = true;
    const r = await bpPost({ acao: 'comecar', grupo });
    if (!r.ok) { alert(r.msg); bpOcupado = false; return; }
    window.location.reload();
}

/** Animação dos reels: roda nomes falsos até a resposta do servidor chegar. */
function bpAnimar(ligar) {
    document.getElementById('bpReelTime')?.classList.toggle('girando', ligar);
    document.getElementById('bpReelLenda')?.classList.toggle('girando', ligar);
}

async function bpGirar() {
    if (bpOcupado) return;
    bpOcupado = true;
    const btn = document.getElementById('bpSpin');
    btn.disabled = true;
    bpAnimar(true);
    document.getElementById('bpNotas').innerHTML = '';

    const r = await bpPost({ acao: 'girar' });
    // Segura a animação um pouco pra dar peso ao sorteio.
    await new Promise(res => setTimeout(res, 700));
    bpAnimar(false);

    if (!r.ok) {
        document.getElementById('bpMsg').textContent = r.msg;
        btn.disabled = false; bpOcupado = false; return;
    }

    document.getElementById('bpTime').textContent  = r.lenda.time;
    document.getElementById('bpLenda').textContent = r.lenda.nome;
    document.getElementById('bpMsg').textContent   = 'Escolha UM atributo pra levar pro seu build.';

    const preenchidos = [...document.querySelectorAll('.bp-slot')]
        .filter(s => !s.classList.contains('vazio')).map(s => s.dataset.attr);

    document.getElementById('bpNotas').innerHTML = Object.entries(r.lenda.notas).map(([chave, n]) => {
        const ocupado = preenchidos.includes(chave);
        return `<div class="bp-nota ${ocupado ? 'ocupado' : ''}" ${ocupado ? '' : `onclick="bpEscolher('${chave}')"`}>
            <span class="bp-nota-label">${BP_ATTRS[chave]}</span>
            <span class="bp-nota-letra" style="color:${BP_CORES[n.nivel]}">${n.letra}</span>
        </div>`;
    }).join('');

    bpOcupado = false;
}

async function bpEscolher(attr) {
    if (bpOcupado) return;
    bpOcupado = true;
    const r = await bpPost({ acao: 'escolher', atributo: attr });
    if (!r.ok) { alert(r.msg); bpOcupado = false; return; }

    // Atualiza o slot preenchido
    const slot = document.querySelector(`.bp-slot[data-attr="${attr}"]`);
    const dado = r.slots[attr];
    slot.classList.remove('vazio');
    slot.querySelector('.bp-slot-de').textContent = dado.de;
    const letra = slot.querySelector('.bp-slot-letra');
    letra.textContent = dado.letra;
    letra.style.color = BP_CORES[dado.nivel];

    document.getElementById('bpNotas').innerHTML = '';
    document.getElementById('bpFaltam').textContent = r.faltam ? `faltam ${r.faltam}` : '';
    document.getElementById('bpMsg').textContent = r.terminou
        ? 'Build completo!' : 'Gire de novo pra próxima lenda.';
    document.getElementById('bpSpin').disabled = false;

    if (r.terminou) window.location.reload();
    bpOcupado = false;
}

document.addEventListener('DOMContentLoaded', () => {
    const vazios = document.querySelectorAll('.bp-slot.vazio').length;
    const el = document.getElementById('bpFaltam');
    if (el) el.textContent = vazios ? `faltam ${vazios}` : '';
});
</script>
