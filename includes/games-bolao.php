<?php
/**
 * A ABA BOLÃO — o cartão de tudo ou nada em FBA Points.
 *
 * Mora fora do games.php porque é tela grande e autocontida: markup, estilo e
 * script do bolão num arquivo só, e o games.php só a inclui. @see backend/bolao.php
 *
 * O QUE A TELA PRECISA DIZER O TEMPO TODO: que é tudo ou nada. A pessoa está
 * apostando até 5 mil FBA Points num cartão de três acertos, e descobrir
 * depois que "quase" não paga nada seria ter sido enganada pela tela. Por
 * isso o aviso aparece no topo, no botão e no resumo do que foi apostado.
 *
 * Espera do games.php: $bolaoEd, $bolaoMinha, $bolaoResumo, $bolaoUltima,
 * $bolaoMsg, $bolaoErro, $perfil, $ehAdminGames, $abaInicial.
 */

/** O escudo do clube, ou o monograma quando não existe imagem. */
$bolEscudo = function (string $clube): string {
    $url = bolaoEscudo($clube);
    if ($url !== '') {
        /* onerror escondendo a imagem e revelando o monograma: escudo que não
           carrega não pode virar quadrado quebrado dentro do botão. */
        return '<img class="bol-escudo" src="' . htmlspecialchars($url) . '" alt="" loading="lazy"'
             . ' onerror="this.remove()">';
    }
    $ini = mb_strtoupper(mb_substr(trim($clube), 0, 3));
    return '<span class="bol-escudo bol-mono">' . htmlspecialchars($ini) . '</span>';
};

$bolMax   = (int)($bolaoEd['aposta_max'] ?? BOLAO_APOSTA_MAX);
$bolMult  = (int)($bolaoEd['multiplicador'] ?? BOLAO_MULTIPLICADOR);
$bolSaldo = (int)($perfil['fba_points'] ?? 0);
$bolTeto  = max(0, min($bolMax, $bolSaldo));
$bolAberto = !empty($bolaoEd['aberto_agora']);

/* A DIVISÃO DOS PALPITES SÓ DEPOIS DE CRAVAR. Ver a manada antes transforma o
   bolão numa enquete — e aqui, diferente dos eventos, o palpite custa dinheiro
   e não dá pra trocar depois que fecha. */
$bolMostraPct = $bolaoMinha !== null || !$bolAberto;
$bolTotalApostas = (int)($bolaoResumo['apostadores'] ?? 0);
$bolPct = function (array $contagem, string $chave) use ($bolTotalApostas): int {
    if ($bolTotalApostas <= 0) return 0;
    return (int)round(((int)($contagem[$chave] ?? 0)) * 100 / $bolTotalApostas);
};
?>
<style>
/* ── BOLÃO ───────────────────────────────────────────────────────────── */
.bol-flash { border-radius:14px; padding:13px 16px; margin-bottom:18px; font-size:13px; font-weight:600;
             display:flex; align-items:center; gap:10px; }
.bol-flash.ok   { background:rgba(37,198,119,.12); border:1px solid rgba(37,198,119,.3); color:#25c677; }
.bol-flash.erro { background:rgba(252,0,37,.1);   border:1px solid rgba(252,0,37,.28);  color:#ff5a6e; }

.bol-card { background:var(--panel); border:1px solid var(--border-md); border-radius:18px; overflow:hidden; }

.bol-hero { display:flex; align-items:center; gap:18px; padding:22px 24px 18px;
            background:linear-gradient(118deg, rgba(252,0,37,.16), rgba(252,0,37,0) 62%); flex-wrap:wrap; }
.bol-tag { display:inline-block; font-size:10px; font-weight:800; letter-spacing:1.4px; text-transform:uppercase;
           color:var(--red); background:rgba(252,0,37,.12); border:1px solid rgba(252,0,37,.25);
           padding:3px 9px; border-radius:999px; margin-bottom:9px; }
.bol-hero h2 { font-size:23px; font-weight:800; margin:0 0 5px; line-height:1.15; }
.bol-hero p  { font-size:13px; color:var(--text-2); margin:0; max-width:46ch; line-height:1.5; }
.bol-hero-txt { flex:1; min-width:220px; }
.bol-selos { display:flex; gap:10px; flex-shrink:0; }
.bol-paga { text-align:center; padding:14px 20px; border-radius:16px; flex-shrink:0;
            background:rgba(252,0,37,.1); border:1px solid rgba(252,0,37,.3);
            display:flex; flex-direction:column; justify-content:center; }
/* O contador é NEUTRO de propósito: o 2x é a manchete do card, e dois selos
   berrando a mesma cor brigariam pelo olho. */
.bol-conta { background:var(--panel-2); border-color:var(--border-md); }
.bol-conta .bol-paga-n { color:var(--text); }
.bol-paga-n { font-size:34px; font-weight:800; line-height:1; color:var(--red); }
.bol-paga-l { font-size:10px; font-weight:700; letter-spacing:.6px; text-transform:uppercase;
              color:var(--text-3); margin-top:6px; }

/* O aviso RESPIRA do cabeçalho: sem a margem de cima ele encostava na faixa
   do título e os dois viravam um bloco só. */
.bol-aviso { display:flex; align-items:flex-start; gap:10px; margin:14px 24px 6px; padding:12px 14px;
             background:rgba(245,158,11,.08); border:1px solid rgba(245,158,11,.25); border-radius:12px;
             font-size:12.5px; color:#f5b544; line-height:1.5; }
.bol-aviso i { font-size:14px; margin-top:1px; flex-shrink:0; }

.bol-sec { padding:20px 24px 6px; }
.bol-sec-h { display:flex; align-items:center; gap:10px; font-size:13.5px; font-weight:800; margin-bottom:14px; }
.bol-sec-h small { font-weight:600; color:var(--text-3); font-size:11.5px; letter-spacing:.3px; }
.bol-num { width:22px; height:22px; border-radius:7px; background:var(--red); color:#fff; flex-shrink:0;
           display:flex; align-items:center; justify-content:center; font-size:11.5px; font-weight:800; }

.bol-times { display:grid; grid-template-columns:repeat(auto-fill, minmax(132px, 1fr)); gap:10px; }
.bol-time { position:relative; display:block; cursor:pointer; }
.bol-time input { position:absolute; opacity:0; pointer-events:none; }
.bol-time-in { display:flex; flex-direction:column; align-items:center; gap:9px; padding:16px 10px 13px;
               background:var(--panel-2); border:1.5px solid var(--border-md); border-radius:14px;
               transition:border-color .15s, background .15s, transform .1s; position:relative; }
.bol-time:hover .bol-time-in { border-color:rgba(255,255,255,.22); }
.bol-time input:checked + .bol-time-in { border-color:var(--red); background:rgba(252,0,37,.08); }
.bol-time input:disabled + .bol-time-in { opacity:.5; cursor:not-allowed; }

/* ── FECHADO, O CARTÃO VIRA PAINEL ────────────────────────────────────
   Depois do prazo ninguém escolhe mais nada, e o apagado de "formulário
   desabilitado" era o estado errado: o que a tela passa a mostrar é COMO A
   LIGA APOSTOU, e isso é informação pra ler, não controle pra clicar. */
.bol-placar .bol-time input:disabled + .bol-time-in { opacity:1; cursor:default; }
.bol-placar .bol-time { cursor:default; }
.bol-placar .bol-time:hover .bol-time-in { border-color:var(--border-md); }
.bol-placar .bol-time input:checked + .bol-time-in { border-color:var(--red); }
.bol-time-in { overflow:hidden; }
.bol-barra { position:absolute; left:0; right:0; bottom:0; height:4px; background:rgba(255,255,255,.07); }
/* A barra é SEMPRE da mesma cor, inclusive na escolha de quem está olhando:
   verde no cartão da pessoa leria como "acertou", e o resultado ainda não
   saiu. O que marca o cartão dela é a borda e o ✓, como antes. */
.bol-barra span { display:block; height:100%; background:var(--red); border-radius:0 3px 3px 0; transition:width .4s; }
.bol-time input:checked + .bol-time-in::after { content:'\F26E'; font-family:'bootstrap-icons'; position:absolute;
               top:7px; right:9px; font-size:13px; color:var(--red); }
.bol-escudo { width:46px; height:46px; object-fit:contain; display:block; }
.bol-mono { display:flex; align-items:center; justify-content:center; border-radius:50%;
            background:var(--panel-3); color:var(--text-2); font-size:13px; font-weight:800; letter-spacing:.5px; }
.bol-time-nome { font-size:12.5px; font-weight:700; text-align:center; line-height:1.25; }
.bol-time-pct { font-size:10.5px; font-weight:700; color:var(--text-3); }
.bol-placar .bol-time-pct { font-size:12px; color:var(--text-2); }
.bol-placar .bol-time-pct b { font-size:14px; color:var(--text); }

.bol-valor { display:flex; align-items:center; gap:16px; flex-wrap:wrap;
             background:var(--panel-2); border:1px solid var(--border-md); border-radius:14px; padding:16px 18px; }
.bol-valor input[type=range] { flex:1; min-width:180px; accent-color:var(--red); }
.bol-valor input[type=number] { width:116px; background:var(--panel-3); border:1px solid var(--border-md);
             border-radius:10px; color:var(--text); font-size:16px; font-weight:800; padding:9px 11px; text-align:right; }
.bol-retorno { font-size:12.5px; color:var(--text-2); }
.bol-retorno b { color:#25c677; font-size:16px; font-weight:800; }
.bol-saldo-dica { font-size:11.5px; color:var(--text-3); margin-top:9px; }

.bol-rodape { padding:18px 24px 24px; }
.bol-enviar { width:100%; border:none; border-radius:13px; padding:15px; cursor:pointer;
              background:var(--red); color:#fff; font-size:14px; font-weight:800; letter-spacing:.3px;
              transition:opacity .15s, filter .15s; }
.bol-enviar:hover:not(:disabled) { filter:brightness(1.1); }
.bol-enviar:disabled { opacity:.42; cursor:not-allowed; }
.bol-desistir { display:block; width:100%; margin-top:10px; background:none; border:none; cursor:pointer;
                color:var(--text-3); font-size:12px; font-weight:600; padding:8px; }
.bol-desistir:hover { color:#ff5a6e; }

.bol-meu { display:flex; align-items:center; gap:14px; flex-wrap:wrap; margin:0 24px 18px; padding:14px 16px;
           background:rgba(37,198,119,.08); border:1px solid rgba(37,198,119,.25); border-radius:13px; }
.bol-meu-l { font-size:10.5px; font-weight:800; letter-spacing:.7px; text-transform:uppercase; color:#25c677; }
.bol-meu-v { font-size:13px; font-weight:700; margin-top:3px; }
.bol-meu-n { margin-left:auto; text-align:right; }
.bol-meu-n b { font-size:19px; font-weight:800; }

.bol-fechado { margin:0 24px 18px; padding:14px 16px; border-radius:13px; font-size:12.5px; line-height:1.55;
               background:var(--panel-2); border:1px solid var(--border-md); color:var(--text-2); }

.bol-admin { margin-top:18px; background:var(--panel); border:1px dashed rgba(168,85,247,.4);
             border-radius:16px; padding:18px 20px; }
.bol-admin h3 { font-size:12px; font-weight:800; letter-spacing:1px; text-transform:uppercase;
                color:#a855f7; margin:0 0 14px; }
.bol-admin-linha { display:flex; gap:10px; flex-wrap:wrap; align-items:center; margin-bottom:12px; }
.bol-admin select, .bol-admin input[type=datetime-local] {
    background:var(--panel-2); border:1px solid var(--border-md); border-radius:9px;
    color:var(--text); font-size:12.5px; padding:8px 10px; }
.bol-admin button { border:none; border-radius:9px; padding:8px 14px; cursor:pointer;
                    font-size:12.5px; font-weight:700; }
.bol-admin .bt-rox { background:rgba(168,85,247,.16); color:#c084fc; }
.bol-admin .bt-cinza { background:var(--panel-3); color:var(--text-2); }

.bol-lista { margin-top:18px; }
.bol-linha { display:flex; align-items:center; gap:12px; padding:10px 14px; border-radius:11px;
             background:var(--panel-2); border:1px solid var(--border); margin-bottom:7px; font-size:12.5px; }
.bol-linha .nm { font-weight:700; flex-shrink:0; }
.bol-linha .pl { color:var(--text-3); flex:1; min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.bol-linha .vl { font-weight:800; flex-shrink:0; }
.bol-linha.ganhou { border-color:rgba(37,198,119,.35); background:rgba(37,198,119,.07); }
.bol-linha.perdeu { opacity:.6; }

@media (max-width:560px) {
    .bol-hero { padding:18px 16px; }
    .bol-hero h2 { font-size:19px; }
    .bol-selos { width:100%; }
    .bol-paga { flex:1; padding:12px 14px; }
    .bol-paga-n { font-size:27px; }
    .bol-aviso, .bol-meu, .bol-fechado { margin-left:16px; margin-right:16px; }
    .bol-sec, .bol-rodape { padding-left:16px; padding-right:16px; }
    .bol-times { grid-template-columns:repeat(auto-fill, minmax(104px, 1fr)); }
    .bol-escudo { width:38px; height:38px; }
    .bol-valor input[type=number] { width:100%; }
}
</style>

<?php if ($bolaoMsg): ?>
    <div class="bol-flash ok"><i class="bi bi-check-circle-fill"></i><span><?= htmlspecialchars($bolaoMsg) ?></span></div>
<?php endif; ?>
<?php if ($bolaoErro): ?>
    <div class="bol-flash erro"><i class="bi bi-exclamation-octagon-fill"></i><span><?= htmlspecialchars($bolaoErro) ?></span></div>
<?php endif; ?>

<?php if (!$bolaoEd): ?>
    <div class="card"><div class="vazio">
        <i class="bi bi-ticket-perforated"></i>
        <p>Nenhum bolão rolando agora. Quando a organização abrir um, ele aparece aqui.</p>
    </div></div>
<?php else: ?>

<form method="POST" class="bol-card<?= $bolAberto ? '' : ' bol-placar' ?>" id="bolaoForm">
    <input type="hidden" name="bolao_acao" value="apostar">

    <div class="bol-hero">
        <div class="bol-hero-txt">
            <span class="bol-tag">Bolão</span>
            <h2><?= htmlspecialchars((string)$bolaoEd['titulo']) ?></h2>
            <?php if (!empty($bolaoEd['subtitulo'])): ?>
            <p><?= htmlspecialchars((string)$bolaoEd['subtitulo']) ?></p>
            <?php endif; ?>
        </div>
        <div class="bol-selos">
            <?php if ($bolTotalApostas > 0): ?>
            <!-- QUANTOS JÁ JOGARAM aparece sempre, aberto ou fechado: é só o
                 tamanho do bolão, não diz em quem a liga apostou — então não
                 vira manada como a divisão por time viraria. -->
            <div class="bol-paga bol-conta">
                <div class="bol-paga-n"><?= $bolTotalApostas ?></div>
                <div class="bol-paga-l"><?= $bolTotalApostas === 1 ? 'palpite feito' : 'palpites feitos' ?></div>
            </div>
            <?php endif; ?>
            <div class="bol-paga">
                <div class="bol-paga-n"><?= $bolMult ?>x</div>
                <div class="bol-paga-l">se acertar tudo</div>
            </div>
        </div>
    </div>

    <div class="bol-aviso">
        <i class="bi bi-exclamation-triangle-fill"></i>
        <span><b>É tudo ou nada.</b> Os <?= (int)$bolaoEd['quantos_caem'] ?> rebaixados e o campeão têm que estar
        certos. Errou um, perde o que apostou — não existe prêmio parcial.</span>
    </div>

    <?php if ($bolaoMinha): ?>
    <div class="bol-meu">
        <div>
            <div class="bol-meu-l"><i class="bi bi-check2-circle"></i> Seu cartão</div>
            <div class="bol-meu-v">
                <?= htmlspecialchars(implode(' + ', $bolaoMinha['palpite_queda'])) ?> caem ·
                <?= htmlspecialchars((string)$bolaoMinha['palpite_campeao']) ?> campeão
            </div>
        </div>
        <div class="bol-meu-n">
            <b><?= number_format((int)$bolaoMinha['valor'], 0, ',', '.') ?></b>
            <div class="bol-meu-l">apostados · paga <?= number_format((int)$bolaoMinha['valor'] * $bolMult, 0, ',', '.') ?></div>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!$bolAberto): ?>
    <div class="bol-fechado">
        <i class="bi bi-lock-fill"></i>
        <?= $bolaoMinha
            ? 'O prazo fechou e seu cartão está valendo — não dá mais pra trocar nem desistir.'
            : 'O prazo fechou e você não apostou neste bolão.' ?>
        Abaixo, como a liga se dividiu: fica assim até sair o resultado.
    </div>
    <?php endif; ?>

    <!-- ── 1. quem cai ──────────────────────────────────────────────── -->
    <div class="bol-sec">
        <div class="bol-sec-h">
            <span class="bol-num">1</span> Quem cai?
            <small><?= $bolAberto ? 'escolha ' . (int)$bolaoEd['quantos_caem'] : 'como a liga apostou' ?></small>
        </div>
        <div class="bol-times" id="bolQueda">
            <?php foreach ($bolaoEd['candidatos_queda'] as $c):
                $marcado = $bolaoMinha && in_array($c, $bolaoMinha['palpite_queda'], true); ?>
            <label class="bol-time">
                <input type="checkbox" name="queda[]" value="<?= htmlspecialchars($c) ?>"
                       <?= $marcado ? 'checked' : '' ?> <?= $bolAberto ? '' : 'disabled' ?> data-bol-queda>
                <span class="bol-time-in">
                    <?= $bolEscudo($c) ?>
                    <span class="bol-time-nome"><?= htmlspecialchars($c) ?></span>
                    <?php if ($bolMostraPct && $bolTotalApostas): $pc = $bolPct($bolaoResumo['queda'] ?? [], $c); ?>
                    <span class="bol-time-pct"><b><?= $pc ?>%</b> dos cartões</span>
                    <span class="bol-barra"><span style="width:<?= $pc ?>%"></span></span>
                    <?php endif; ?>
                </span>
            </label>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- ── 2. o campeão ─────────────────────────────────────────────── -->
    <div class="bol-sec">
        <div class="bol-sec-h"><span class="bol-num">2</span> Quem é o campeão?
            <small><?= $bolAberto ? 'escolha 1' : 'como a liga apostou' ?></small></div>
        <div class="bol-times">
            <?php foreach ($bolaoEd['candidatos_titulo'] as $c):
                $marcado = $bolaoMinha && (string)$bolaoMinha['palpite_campeao'] === $c; ?>
            <label class="bol-time">
                <input type="radio" name="campeao" value="<?= htmlspecialchars($c) ?>"
                       <?= $marcado ? 'checked' : '' ?> <?= $bolAberto ? '' : 'disabled' ?> data-bol-campeao>
                <span class="bol-time-in">
                    <?= $bolEscudo($c) ?>
                    <span class="bol-time-nome"><?= htmlspecialchars($c) ?></span>
                    <?php if ($bolMostraPct && $bolTotalApostas): $pc = $bolPct($bolaoResumo['campeao'] ?? [], $c); ?>
                    <span class="bol-time-pct"><b><?= $pc ?>%</b> dos cartões</span>
                    <span class="bol-barra"><span style="width:<?= $pc ?>%"></span></span>
                    <?php endif; ?>
                </span>
            </label>
            <?php endforeach; ?>
        </div>
    </div>

    <?php if ($bolAberto): ?>
    <!-- ── 3. quanto ────────────────────────────────────────────────── -->
    <div class="bol-sec">
        <div class="bol-sec-h"><span class="bol-num">3</span> Quanto você aposta?</div>
        <div class="bol-valor">
            <input type="range" id="bolRange" min="0" max="<?= $bolTeto ?>" step="50"
                   value="<?= (int)($bolaoMinha['valor'] ?? 0) ?>" <?= $bolTeto > 0 ? '' : 'disabled' ?>>
            <input type="number" id="bolValor" name="valor" min="1" max="<?= $bolTeto ?: 1 ?>"
                   value="<?= (int)($bolaoMinha['valor'] ?? 0) ?>" <?= $bolTeto > 0 ? '' : 'disabled' ?>>
            <div class="bol-retorno">Se cravar: <b id="bolRetorno">0</b> FBA Points</div>
        </div>
        <div class="bol-saldo-dica">
            Você tem <b><?= number_format($bolSaldo, 0, ',', '.') ?></b> FBA Points ·
            teto de <?= number_format($bolMax, 0, ',', '.') ?> por cartão
            <?= $bolaoMinha ? ' · trocar o cartão acerta a diferença no seu saldo na hora' : '' ?>
        </div>
    </div>

    <div class="bol-rodape">
        <button type="submit" class="bol-enviar" id="bolEnviar" disabled>
            <?= $bolaoMinha ? 'Trocar meu cartão' : 'Apostar' ?>
        </button>
        <?php if ($bolaoMinha): ?>
        <!-- Nome PRÓPRIO, e não outro bolao_acao: dois campos com o mesmo
             nome no form dependeriam da ordem do DOM pra decidir a ação. -->
        <button type="submit" class="bol-desistir" name="bolao_cancelar" value="1"
                data-confirmar="Desistir do bolão? Os <?= number_format((int)$bolaoMinha['valor'], 0, ',', '.') ?> FBA Points voltam pro seu saldo.">
            Desistir e receber meus pontos de volta
        </button>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</form>

<?php if ($bolMostraPct && $bolTotalApostas): ?>
<div class="bol-saldo-dica" style="margin-top:14px;text-align:center">
    <!-- Quantos jogaram já está no selo do topo; aqui sobra o que ele não diz. -->
    <?= number_format((int)$bolaoResumo['total'], 0, ',', '.') ?> FBA Points na mesa
</div>
<?php endif; ?>

<?php /* ── O ADMIN: abre, fecha e lança o resultado ────────────────── */ ?>
<?php if ($ehAdminGames): ?>
<div class="bol-admin">
    <h3><i class="bi bi-shield-lock"></i> Organização</h3>

    <form method="POST" class="bol-admin-linha">
        <input type="hidden" name="bolao_acao" value="chave">
        <span style="font-size:12.5px;color:var(--text-2)">Apostas:</span>
        <button type="submit" name="aberto" value="1" class="<?= !empty($bolaoEd['aberto']) ? 'bt-rox' : 'bt-cinza' ?>">Abertas</button>
        <button type="submit" name="aberto" value="0" class="<?= empty($bolaoEd['aberto']) ? 'bt-rox' : 'bt-cinza' ?>">Fechadas</button>
    </form>

    <form method="POST" class="bol-admin-linha">
        <input type="hidden" name="bolao_acao" value="prazo">
        <span style="font-size:12.5px;color:var(--text-2)">Fecha sozinho em:</span>
        <input type="datetime-local" name="fecha_em"
               value="<?= $bolaoEd['fecha_em'] ? date('Y-m-d\TH:i', strtotime((string)$bolaoEd['fecha_em'])) : '' ?>">
        <button type="submit" class="bt-rox">Salvar prazo</button>
        <?php if ($bolaoEd['fecha_em']): ?>
        <button type="submit" name="limpar" value="1" class="bt-cinza">Tirar prazo</button>
        <?php endif; ?>
    </form>

    <form method="POST" class="bol-admin-linha"
          data-confirmar="Lançar o resultado paga os ganhadores na hora e fecha o bolão. Isso não tem volta. Confirma?">
        <input type="hidden" name="bolao_acao" value="resolver">
        <span style="font-size:12.5px;color:var(--text-2)">Resultado — caíram:</span>
        <?php for ($i = 0; $i < (int)$bolaoEd['quantos_caem']; $i++): ?>
        <select name="r_queda[]" required>
            <option value="">time <?= $i + 1 ?>...</option>
            <?php foreach ($bolaoEd['candidatos_queda'] as $c): ?>
            <option value="<?= htmlspecialchars($c) ?>"><?= htmlspecialchars($c) ?></option>
            <?php endforeach; ?>
        </select>
        <?php endfor; ?>
        <span style="font-size:12.5px;color:var(--text-2)">campeão:</span>
        <select name="r_campeao" required>
            <option value="">escolha...</option>
            <?php foreach ($bolaoEd['candidatos_titulo'] as $c): ?>
            <option value="<?= htmlspecialchars($c) ?>"><?= htmlspecialchars($c) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="bt-rox">Lançar e pagar</button>
    </form>

    <?php $bolTodas = bolaoApostasDaEdicao($pdo, (int)$bolaoEd['id']); ?>
    <?php if ($bolTodas): ?>
    <div class="bol-lista">
        <?php foreach ($bolTodas as $a): ?>
        <div class="bol-linha">
            <span class="nm"><?= htmlspecialchars((string)($a['nome'] ?? 'GM')) ?></span>
            <span class="pl"><?= htmlspecialchars(implode(' + ', $a['palpite_queda'])) ?> ·
                <?= htmlspecialchars((string)$a['palpite_campeao']) ?></span>
            <span class="vl"><?= number_format((int)$a['valor'], 0, ',', '.') ?></span>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php endif; /* $bolaoEd */ ?>

<?php /* ── Como terminou o bolão anterior ──────────────────────────── */ ?>
<?php if ($bolaoUltima): ?>
<div class="bol-card" style="margin-top:20px">
    <div class="bol-hero" style="background:linear-gradient(118deg, rgba(37,198,119,.12), transparent 62%)">
        <div class="bol-hero-txt">
            <span class="bol-tag" style="color:#25c677;background:rgba(37,198,119,.12);border-color:rgba(37,198,119,.25)">Encerrado</span>
            <h2><?= htmlspecialchars((string)$bolaoUltima['titulo']) ?></h2>
            <p>Caíram <b><?= htmlspecialchars(implode(' e ', $bolaoUltima['resultado_queda'])) ?></b>,
               campeão <b><?= htmlspecialchars((string)$bolaoUltima['resultado_campeao']) ?></b>.</p>
        </div>
    </div>
    <?php $bolGanhou = array_values(array_filter(bolaoApostasDaEdicao($pdo, (int)$bolaoUltima['id']),
                                                fn($a) => $a['status'] === 'ganhou')); ?>
    <div class="bol-sec" style="padding-bottom:20px">
        <div class="bol-sec-h"><i class="bi bi-trophy-fill" style="color:#f5b544"></i>
            <?= $bolGanhou ? count($bolGanhou) . ' cravaram o cartão' : 'Ninguém cravou o cartão' ?></div>
        <?php foreach ($bolGanhou as $a): ?>
        <div class="bol-linha ganhou">
            <span class="nm"><?= htmlspecialchars((string)($a['nome'] ?? 'GM')) ?></span>
            <span class="pl">apostou <?= number_format((int)$a['valor'], 0, ',', '.') ?></span>
            <span class="vl" style="color:#25c677">+<?= number_format((int)$a['premio'], 0, ',', '.') ?></span>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<script>
/* ── O CARTÃO SÓ ENVIA COMPLETO ───────────────────────────────────────
 *
 * Três regras na tela, pra ninguém descobrir no servidor: no máximo os N
 * times da queda (o terceiro clique não marca, em vez de desmarcar outro
 * por conta própria), um campeão, e um valor dentro do saldo. O servidor
 * confere tudo de novo — isto aqui é pra não gastar um clique à toa.
 */
(function () {
    const form = document.getElementById('bolaoForm');
    if (!form) return;

    const QUANTOS = <?= (int)($bolaoEd['quantos_caem'] ?? 2) ?>;
    const MULT    = <?= $bolMult ?>;
    const TETO    = <?= $bolTeto ?>;

    const quedas  = [...form.querySelectorAll('[data-bol-queda]')];
    const campeao = [...form.querySelectorAll('[data-bol-campeao]')];
    const range   = document.getElementById('bolRange');
    const valor   = document.getElementById('bolValor');
    const retorno = document.getElementById('bolRetorno');
    const enviar  = document.getElementById('bolEnviar');
    if (!enviar) return;

    const fmt = n => Number(n).toLocaleString('pt-BR');

    function marcados() { return quedas.filter(c => c.checked).length; }

    function conferir() {
        const n = marcados();
        // Cheio: o que não está marcado desliga, e o clique seguinte não some
        // com uma escolha que a pessoa já tinha feito.
        quedas.forEach(c => { c.disabled = (!c.checked && n >= QUANTOS); });

        const v = Math.min(TETO, Math.max(0, parseInt(valor?.value || '0', 10) || 0));
        if (retorno) retorno.textContent = fmt(v * MULT);
        enviar.disabled = !(n === QUANTOS && campeao.some(r => r.checked) && v >= 1);
    }

    quedas.forEach(c => c.addEventListener('change', conferir));
    campeao.forEach(r => r.addEventListener('change', conferir));

    // O cursor e o número são o mesmo valor visto de dois jeitos.
    range?.addEventListener('input', () => { valor.value = range.value; conferir(); });
    valor?.addEventListener('input', () => {
        let v = parseInt(valor.value || '0', 10) || 0;
        if (v > TETO) { v = TETO; valor.value = v; }
        if (range) range.value = v;
        conferir();
    });

    conferir();
})();
</script>
