<?php
/**
 * A CAPA DO CLUBE — as quatro mídias na mesma tela.
 *
 * Entra por clube.php quando `?midia=inicio`, que é por onde a página abre
 * desde 14/10/2026. Antes a porta era a aba Séries, e o banco mostrou no que
 * isso dava: 43 pessoas mexendo em séries, 21 votando no álbum ou no filme,
 * 8 dando nota em alguma coisa. "O pessoal tá entrando mas ficando só em
 * séries" — e ficava mesmo, porque o resto do Clube era uma aba que ninguém
 * tinha motivo pra clicar.
 *
 * ── O QUE A CAPA FAZ DE DIFERENTE DAS ABAS ───────────────────────────
 *
 * Ela não repete as abas em miniatura: ela traz pra cá só o que tem HORA
 * MARCADA ou pede uma ação — a votação que fecha hoje, a pesquisa aberta, a
 * nota que falta dar. Catálogo, acervo, timeline e ranking continuam nas
 * abas, que é onde se tem tempo de ler. Por isso dá pra votar na capa sem
 * sair dela: o voto é um clique, e mandar alguém mudar de página pra dar um
 * clique é como se perde a pessoa.
 *
 * Espera, de quem inclui: $pdo, $idUsuario, $ehAdminClube, $souDoLivro,
 * e as funções h() e clubeAvatar() do clube.php.
 *
 * @see backend/clube_capa.php   as consultas que juntam as mídias
 * @see clube.php                o roteamento e as ações
 */

$capaCartazes = [];
foreach (['album', 'filme', 'livro'] as $t) {
    $capaCartazes[$t] = clubeSemanaEstadoDoTipo($pdo, $t, $idUsuario);
}
$capaPesquisas = clubeLivroEnquetes($pdo, $idUsuario, 'clube');
$capaMovimento = clubeCapaMovimento($pdo, 12);
$capaNumeros   = clubeCapaNumeros($pdo);
$capaVitrine   = clubeCapaVitrineSeries($pdo, 14);

/* A cor de cada mídia. NÃO é a cor da pessoa (--acento) de propósito: aqui
   elas aparecem lado a lado, e três cards da mesma cor viram um bloco só. */
$capaCores = ['album' => '#8b5cf6', 'filme' => '#f59e0b', 'livro' => '#22c55e'];
$capaMidia = ['album' => 'musica', 'filme' => 'filmes', 'livro' => 'livro'];
?>
<style>
/* ── A CAPA ──────────────────────────────────────────────────────────
   Tudo aqui é escopado em .capa: a página já tem o CSS das abas, e um
   `.card` solto brigaria com ele. */
.capa{display:flex;flex-direction:column;gap:22px}
.capa-sec{display:flex;flex-direction:column;gap:12px}
.capa-cab{display:flex;align-items:baseline;gap:10px;flex-wrap:wrap}
.capa-cab h2{font-family:var(--display);font-size:21px;font-weight:700;margin:0;letter-spacing:.3px}
.capa-cab p{margin:0;color:var(--txt3);font-size:12.5px}
.capa-cab .mais{margin-left:auto;font-size:12.5px;font-weight:700;color:var(--acento);
  text-decoration:none;white-space:nowrap}
.capa-cab .mais:hover{text-decoration:underline}

/* Os números: uma linha, sem card, pra não competir com o que vem embaixo. */
.capa-nums{display:flex;flex-wrap:wrap;gap:6px 18px;color:var(--txt3);font-size:12.5px}
.capa-nums b{color:var(--txt);font-weight:700}

/* ── OS TRÊS CARTAZES ────────────────────────────────────────────────
   auto-fit com minmax deixa os três lado a lado no desktop, dois no tablet e
   um no celular sem media query — e todos com a mesma altura, porque o que
   os desalinharia (a lista de opções) rola dentro do próprio card. */
.cxs{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,290px),1fr));gap:14px}
.cx{display:flex;flex-direction:column;background:var(--panel);border:1px solid var(--borda);
  border-radius:16px;overflow:hidden;position:relative}
.cx::before{content:"";position:absolute;inset:0 0 auto;height:3px;background:var(--c)}
.cx-top{display:flex;align-items:center;gap:8px;padding:14px 16px 0;font-size:12px;
  font-weight:800;text-transform:uppercase;letter-spacing:.7px;color:var(--txt3)}
.cx-top i{color:var(--c);font-size:15px}
.cx-tag{margin-left:auto;font-size:9.5px;font-weight:800;letter-spacing:.5px;padding:3px 7px;
  border-radius:6px;background:color-mix(in srgb,var(--c) 16%,transparent);color:var(--c)}
.cx-corpo{padding:10px 16px 16px;display:flex;flex-direction:column;gap:8px;flex:1}
.cx-tit{font-family:var(--display);font-size:25px;font-weight:700;line-height:1.1;
  letter-spacing:.3px}
.cx-sub{color:var(--txt2);font-size:13px;margin-top:-2px}
.cx-nota{display:flex;align-items:center;gap:8px;font-size:12.5px;color:var(--txt3)}
.cx-nota b{font-family:var(--display);font-size:24px;line-height:1;color:var(--txt)}
.cx-vazio{color:var(--txt3);font-size:13px;line-height:1.5}
.cx-prazo{font-size:12px;color:var(--txt2)}
.cx-prazo b{color:var(--c)}
/* A lista de opções rola DENTRO do card: dez álbuns num card deixariam a
   coluna do lado com um metro de vazio. */
.cx-ops{max-height:218px;overflow-y:auto;padding-right:2px;margin:0 -2px}
.cx-ops .vop{padding:7px 10px;font-size:13px}
/* No celular os cards já estão empilhados: não há coluna vizinha pra
   desalinhar, e rolagem dentro de rolagem é a pior coisa de se fazer com o
   dedo. Lá a lista abre inteira. */
@media (max-width:620px){.cx-ops{max-height:none;overflow:visible}}
/* Dar nota sem sair da capa: o select e o botão numa linha só. */
.cx-dar{display:flex;gap:7px;align-items:center;margin-top:2px}
.cx-dar select{padding:7px 9px;border-radius:9px;border:1px solid var(--borda);
  background:var(--panel2);color:var(--txt);font-size:13px;font-family:inherit}
.cx-dar select:focus{outline:none;border-color:var(--c)}
.cx-dar .btn{padding:7px 12px;font-size:12.5px}
.cx-pe{margin-top:auto;padding-top:10px;display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.cx-pe a{font-size:12.5px;font-weight:700;color:var(--txt2);text-decoration:none}
.cx-pe a:hover{color:var(--txt)}
.cx-minha{font-size:12px;color:var(--txt3)}
.cx-minha b{color:var(--c)}

/* ── A VITRINE DE SÉRIES ─────────────────────────────────────────────
   Rola de lado, como prateleira: a capa é resumo, e empilhar catorze
   pôsteres em grade roubaria a página inteira do resto do Clube. */
.vitr{display:flex;gap:10px;overflow-x:auto;padding-bottom:6px;scrollbar-width:thin}
.vitr::-webkit-scrollbar{height:6px}
.vitr::-webkit-scrollbar-thumb{background:var(--borda2);border-radius:3px}
.vitr a{flex:0 0 118px;text-decoration:none;color:inherit}
.vitr .pst{position:relative;aspect-ratio:2/3;border-radius:11px;overflow:hidden;
  background:var(--panel2);border:1px solid var(--borda)}
.vitr .pst img{width:100%;height:100%;object-fit:cover;display:block}
.vitr .pst .sem{display:flex;align-items:center;justify-content:center;height:100%;
  color:var(--txt3);font-size:11px;text-align:center;padding:8px;line-height:1.3}
.vitr .pst .n{position:absolute;right:6px;bottom:6px;font-size:10.5px;font-weight:800;
  padding:2px 6px;border-radius:6px;background:rgba(0,0,0,.72);color:#fff}
.vitr b{display:block;font-size:12px;font-weight:600;margin-top:6px;line-height:1.25;
  display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}

/* ── O MOVIMENTO ─────────────────────────────────────────────────────*/
.mov{display:flex;flex-direction:column}
.mov-um{display:flex;gap:10px;padding:10px 0;border-bottom:1px solid var(--borda)}
.mov-um:last-child{border-bottom:none}
.mov-um > img{width:30px;height:30px;border-radius:50%;object-fit:cover;flex-shrink:0}
.mov-c{flex:1;min-width:0;font-size:13.5px;line-height:1.4}
.mov-c .q{font-weight:700}
.mov-c .o{color:var(--txt2)}
.mov-c a{color:inherit;text-decoration:none}
.mov-c a:hover{text-decoration:underline}
.mov-nota{display:inline-block;font-size:11px;font-weight:800;padding:1px 6px;border-radius:5px;
  background:color-mix(in srgb,var(--fba) 16%,transparent);color:var(--fba-txt);margin-left:4px}
.mov-txt{color:var(--txt3);font-size:12.5px;margin-top:2px;
  display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.mov-q{font-size:11.5px;color:var(--txt3);white-space:nowrap;flex-shrink:0}
.mov-ico{font-size:12px;color:var(--txt3);margin-right:2px}

/* ── O POPUP DAS PESQUISAS (admin) ───────────────────────────────────
   É <dialog>: a caixa é da página, com a cara do app, e não o prompt do
   navegador. O Clube inteiro segue essa regra (@see data-confirmar). */
/* `margin:auto` porque o shell define margem em dialog e a caixa nascia
   grudada na esquerda, por cima do menu. */
.pq-dlg{border:1px solid var(--borda2);border-radius:16px;background:var(--panel);
  color:var(--txt);padding:0;width:min(560px,94vw);max-height:88vh;margin:auto}
.pq-dlg::backdrop{background:rgba(0,0,0,.62)}
.pq-topo{display:flex;align-items:center;gap:10px;padding:16px 18px;border-bottom:1px solid var(--borda)}
.pq-topo h3{margin:0;font-family:var(--display);font-size:19px;font-weight:700}
.pq-topo button{margin-left:auto;background:none;border:none;color:var(--txt3);font-size:20px;
  cursor:pointer;line-height:1}
.pq-corpo{padding:16px 18px 18px;overflow-y:auto;max-height:calc(88vh - 62px);
  display:flex;flex-direction:column;gap:14px}
.pq-corpo label{display:block;font-size:12px;font-weight:700;color:var(--txt2);margin-bottom:5px}
.pq-corpo input[type=text],.pq-corpo textarea,.pq-corpo select{width:100%;padding:9px 11px;
  border-radius:10px;border:1px solid var(--borda);background:var(--panel2);color:var(--txt);
  font-size:13.5px;font-family:inherit}
.pq-corpo textarea{min-height:104px;resize:vertical;line-height:1.5}
.pq-corpo input:focus,.pq-corpo textarea:focus,.pq-corpo select:focus{outline:none;border-color:var(--acento)}
.pq-ck{display:flex;align-items:center;gap:7px;font-size:13px;color:var(--txt2);font-weight:400}
.pq-ck input{width:auto}
.pq-lista{display:flex;flex-direction:column;gap:8px}
.pq-um{display:flex;align-items:center;gap:10px;padding:9px 11px;border:1px solid var(--borda);
  border-radius:11px;background:var(--panel2);font-size:13px}
.pq-um .pq-i{flex:1;min-width:0}
.pq-um small{display:block;color:var(--txt3);font-size:11.5px}
.pq-um form{margin:0}
.pq-um button{padding:5px 10px;font-size:12px;border-radius:8px;border:1px solid var(--borda2);
  background:var(--panel);color:var(--txt2);cursor:pointer}
.pq-um button:hover{color:var(--txt);border-color:var(--acento)}
.pq-badge{font-size:9.5px;font-weight:800;text-transform:uppercase;letter-spacing:.5px;
  padding:2px 6px;border-radius:5px;background:var(--panel3);color:var(--txt3)}
.pq-badge.on{background:color-mix(in srgb,var(--green) 18%,transparent);color:var(--green)}
</style>

<div class="capa">

  <?php /* ── OS NÚMEROS ──────────────────────────────────────────────
       Não é enfeite: é o que diz, na primeira linha, que o Clube é maior
       que a aba onde a pessoa costuma cair. */ ?>
  <div class="capa-nums">
    <span><b><?= number_format($capaNumeros['series'], 0, ',', '.') ?></b> séries no catálogo</span>
    <span><b><?= (int)$capaNumeros['gente'] ?></b> <?= $capaNumeros['gente'] === 1 ? 'pessoa' : 'pessoas' ?> no Clube</span>
    <span><b><?= (int)$capaNumeros['obras'] ?></b> obras já escolhidas</span>
    <span><b><?= (int)$capaNumeros['livro'] ?></b> no Clube do Livro</span>
    <?php if ($ehAdminClube): ?>
      <button type="button" class="btn" id="btnPesquisa"
              style="margin-left:auto;padding:5px 11px;font-size:12px">
        <i class="bi bi-ui-checks"></i> Nova pesquisa</button>
    <?php endif; ?>
  </div>

  <?php /* ── 1. ESTA SEMANA ──────────────────────────────────────────── */ ?>
  <section class="capa-sec">
    <div class="capa-cab">
      <h2>Esta semana no Clube</h2>
      <p>o que está em cartaz e o que está em votação</p>
    </div>
    <div class="cxs">
      <?php foreach ($capaCartazes as $tipo => $d):
        $i = $d['info']; $c = $d['cartaz']; $v = $d['enquete'];
        $cor = $capaCores[$tipo]; $mid = $capaMidia[$tipo];
        /* A ABA DO LIVRO É DE QUEM ENTROU. O card aparece pra todo mundo —
           é assim que alguém descobre que o clube existe —, mas quem não é
           membro vai pro convite em vez de uma aba que não é dele. */
        $podeAbrir = $tipo !== 'livro' || $souDoLivro || $ehAdminClube; ?>
        <article class="cx" style="--c:<?= $cor ?>">
          <div class="cx-top">
            <i class="bi bi-<?= h($i['ico']) ?>"></i> <?= h($i['rot']) ?>
            <?php if ($v): ?><span class="cx-tag">votação aberta</span><?php endif; ?>
          </div>
          <div class="cx-corpo">
            <?php if ($v): ?>
              <?php /* ── VOTAÇÃO ABERTA: ela vem na frente do cartaz, porque é
                   a única coisa da capa com hora pra acabar. */ ?>
              <div class="cx-prazo">
                <?= $v['etapa'] === 'genero' ? 'Que gênero o clube lê' : 'Escolha o seu' ?> ·
                fecha <b><?= h($v['dia_fecha'] ?? 'hoje') ?> às <?= h($v['etapa'] === 'genero'
                    ? $v['hora_vira'] : $v['hora_fecha']) ?></b> ·
                <?= (int)$v['total'] ?> voto<?= (int)$v['total'] === 1 ? '' : 's' ?>
              </div>
              <div class="cx-ops vops">
                <?php foreach ($v['opcoes'] as $o):
                    $pct = $v['total'] > 0 ? round(100 * $o['votos'] / $v['total']) : 0; ?>
                  <form method="POST">
                    <input type="hidden" name="acao" value="semana_votar">
                    <input type="hidden" name="ciclo" value="<?= (int)$v['id'] ?>">
                    <input type="hidden" name="opcao" value="<?= (int)$o['id'] ?>">
                    <input type="hidden" name="de" value="inicio">
                    <button type="submit" class="vop <?= (int)$v['meu'] === (int)$o['id'] ? 'on' : '' ?>"
                            style="--pct:<?= $pct ?>%">
                      <i class="vop-bar"></i>
                      <span class="vop-tit"><?= h($o['titulo']) ?>
                        <?php if (trim((string)$o['autor']) !== ''): ?>
                          <small><?= h($o['autor']) ?><?= $o['ano'] ? ' · ' . (int)$o['ano'] : '' ?></small>
                        <?php endif; ?></span>
                      <span class="vop-n"><?= (int)$o['votos'] ?></span>
                    </button>
                  </form>
                <?php endforeach; ?>
              </div>
            <?php elseif ($c): ?>
              <div class="cx-tit"><?= h($c['titulo']) ?></div>
              <div class="cx-sub"><?= h($c['autor']) ?><?= $c['ano'] ? ' · ' . (int)$c['ano'] : '' ?></div>
              <?php if ($c['media'] !== null): ?>
                <div class="cx-nota"><b><?= h($c['media']) ?></b>
                  média da liga · <?= count($c['opinioes']) ?> <?= count($c['opinioes']) === 1 ? 'opinião' : 'opiniões' ?></div>
              <?php else: ?>
                <div class="cx-nota">ninguém deu nota ainda — seja o primeiro</div>
              <?php endif; ?>

              <?php /* ── A NOTA SAI DAQUI MESMO ───────────────────────────
                   É o número mais fraco do Clube: 21 pessoas votam no da
                   semana e 8 voltam pra dar nota. Voltar é o problema — a
                   nota estava a uma aba de distância, e quem ouviu o álbum
                   no domingo não abre uma aba pra digitar um número.
                   O COMENTÁRIO CONTINUA NA ABA: cabe pouco aqui, e a
                   timeline é onde ele é lido. Por isso o texto que a pessoa
                   já escreveu viaja junto no hidden — sem ele, dar nota pela
                   capa apagaria o recado dela (o UPDATE grava os dois). */ ?>
              <form class="cx-dar" method="POST">
                <input type="hidden" name="acao" value="semana_opinar">
                <input type="hidden" name="ciclo" value="<?= (int)$c['ciclo'] ?>">
                <input type="hidden" name="de" value="inicio">
                <input type="hidden" name="texto" value="<?= h($c['minha']['texto'] ?? '') ?>">
                <select name="nota" aria-label="Sua nota">
                  <option value="">nota…</option>
                  <?php for ($n = 10; $n >= 0; $n--): ?>
                    <option value="<?= $n ?>" <?= $c['minha'] && $c['minha']['nota'] !== null
                        && (int)$c['minha']['nota'] === $n ? 'selected' : '' ?>><?= $n ?></option>
                  <?php endfor; ?>
                </select>
                <button type="submit" class="btn">
                  <?= $c['minha'] && $c['minha']['nota'] !== null ? 'Trocar a nota' : 'Dar nota' ?></button>
              </form>
            <?php else: ?>
              <div class="cx-vazio">
                <?= $tipo === 'livro'
                    ? 'Ainda não tem livro do mês. A lista é escolhida pelo clube e a votação abre aqui.'
                    : 'O primeiro sai sexta às 20h, escolhido pelo voto da liga.' ?>
              </div>
            <?php endif; ?>

            <div class="cx-pe">
              <?php if ($podeAbrir): ?>
                <a href="?midia=<?= h($mid) ?>">
                  <?php if ($c && !$v): ?>
                    <?= $c['minha'] ? 'Ver a timeline' : 'Dar nota e comentar' ?> <i class="bi bi-arrow-right"></i>
                  <?php else: ?>
                    Abrir <?= h(mb_strtolower(CLUBE_MIDIAS[$mid]['rot'])) ?> <i class="bi bi-arrow-right"></i>
                  <?php endif; ?>
                </a>
              <?php else: ?>
                <?php /* O convite está nesta mesma capa, lá embaixo — mandar
                     pra aba de Música pra achar o botão era o caminho antigo. */ ?>
                <a href="#clube-do-livro">Entrar no Clube do Livro <i class="bi bi-arrow-right"></i></a>
              <?php endif; ?>
              <?php if ($c && $v): ?>
                <span class="cx-minha">em cartaz: <?= h($c['titulo']) ?></span>
              <?php elseif ($c && $c['minha'] && $c['minha']['nota'] !== null): ?>
                <span class="cx-minha">sua nota: <b><?= (int)$c['minha']['nota'] ?></b></span>
              <?php endif; ?>
            </div>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  </section>

  <?php /* ── 2. AS PESQUISAS DA LIGA ─────────────────────────────────
       Escopo 'clube': são as que o admin cria pelo popup, e valem pra
       todo mundo. As do Clube do Livro continuam lá dentro. */ ?>
  <?php if ($capaPesquisas): ?>
    <section class="capa-sec">
      <div class="capa-cab">
        <h2>Pesquisas</h2>
        <p>o Clube quer saber</p>
      </div>
      <div class="cxs">
        <?php foreach ($capaPesquisas as $e): ?>
          <div class="bloco" style="margin:0">
            <h3 style="margin:0 0 4px;font-size:16px"><i class="bi bi-ui-checks"></i> <?= h($e['pergunta']) ?></h3>
            <div class="sem-prazo">
              <?php if ($e['status'] === 'aberta'): ?>
                aberta · <?= (int)$e['pessoas'] === 1
                    ? '1 pessoa já respondeu'
                    : (int)$e['pessoas'] . ' pessoas já responderam' ?><?=
                  $e['multi'] ? ' · marque quantos quiser' : '' ?>
              <?php else: ?>
                encerrada · resultado de <?= (int)$e['pessoas'] ?> pessoa<?= (int)$e['pessoas'] === 1 ? '' : 's' ?>
              <?php endif; ?>
            </div>
            <div class="vops">
              <?php $tot = max(1, (int)$e['total']);
              foreach ($e['opcoes'] as $o): $pct = round(100 * $o['votos'] / $tot); ?>
                <?php if ($e['status'] === 'aberta'): ?>
                  <form method="POST">
                    <input type="hidden" name="acao" value="pesquisa_votar">
                    <input type="hidden" name="enquete" value="<?= (int)$e['id'] ?>">
                    <input type="hidden" name="opcao" value="<?= (int)$o['id'] ?>">
                    <input type="hidden" name="de" value="inicio">
                    <button type="submit" class="vop <?= in_array((int)$o['id'], $e['meus'], true) ? 'on' : '' ?>"
                            style="--pct:<?= $pct ?>%">
                      <i class="vop-bar"></i>
                      <span class="vop-tit"><?= h($o['texto']) ?></span>
                      <span class="vop-n"><?= (int)$o['votos'] ?></span>
                    </button>
                  </form>
                <?php else: ?>
                  <div class="vop" style="--pct:<?= $pct ?>%;cursor:default">
                    <i class="vop-bar"></i>
                    <span class="vop-tit"><?= h($o['texto']) ?></span>
                    <span class="vop-n"><?= (int)$o['votos'] ?></span>
                  </div>
                <?php endif; ?>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endif; ?>

  <?php /* ── 3. A VITRINE DE SÉRIES ──────────────────────────────────── */ ?>
  <?php if ($capaVitrine['itens']): ?>
    <section class="capa-sec">
      <div class="capa-cab">
        <h2><?= h($capaVitrine['rot']) ?></h2>
        <p>séries</p>
        <a class="mais" href="?midia=series&amp;aba=catalogo">Catálogo completo <i class="bi bi-arrow-right"></i></a>
      </div>
      <div class="vitr">
        <?php foreach ($capaVitrine['itens'] as $s):
          $pst = seriesPoster($s['poster'] ?? null); ?>
          <a href="?midia=series&amp;aba=catalogo&amp;q=<?= rawurlencode((string)$s['titulo']) ?>">
            <div class="pst">
              <?php if ($pst): ?>
                <img src="<?= h($pst) ?>" alt="" loading="lazy">
              <?php else: ?>
                <div class="sem"><?= h($s['titulo']) ?></div>
              <?php endif; ?>
              <span class="n"><?= (int)$s['quantos'] ?></span>
            </div>
            <b><?= h($s['titulo']) ?></b>
          </a>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endif; ?>

  <?php /* ── 4. O MOVIMENTO ──────────────────────────────────────────── */ ?>
  <?php if ($capaMovimento): ?>
    <section class="capa-sec">
      <div class="capa-cab">
        <h2>O que a liga andou fazendo</h2>
        <p>séries, álbuns, filmes e livros na mesma linha</p>
        <a class="mais" href="?midia=series&amp;aba=liga">Ver tudo das séries <i class="bi bi-arrow-right"></i></a>
      </div>
      <div class="bloco" style="margin:0;padding:4px 16px">
        <div class="mov">
          <?php foreach ($capaMovimento as $m): ?>
            <div class="mov-um">
              <img src="<?= h(clubeAvatar($m['foto'])) ?>" alt="">
              <div class="mov-c">
                <span class="q"><?= h($m['quem']) ?></span>
                <span class="o"><?= h($m['verbo']) ?></span>
                <a href="<?= h($m['link']) ?>"><i class="bi bi-<?= h($m['ico']) ?> mov-ico"></i><?= h($m['titulo']) ?></a>
                <?php if ($m['nota'] !== null): ?>
                  <span class="mov-nota"><?= (int)$m['nota'] ?></span>
                <?php endif; ?>
                <?php if ($m['texto']): ?>
                  <div class="mov-txt"><?= h($m['texto']) ?></div>
                <?php endif; ?>
              </div>
              <span class="mov-q"><?= h(tempoRelativoCurto($m['quando'])) ?></span>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </section>
  <?php endif; ?>

  <?php /* ── 5. O CONVITE DO CLUBE DO LIVRO ──────────────────────────
       Só pra quem está de fora: pra quem entrou, o livro já é um dos três
       cards lá de cima. */ ?>
  <?php if (!$souDoLivro): ?>
    <section class="capa-sec" id="clube-do-livro">
      <div class="bloco convite" style="margin:0">
        <p><b><i class="bi bi-book-half"></i> Clube do Livro</b> — um livro por mês, escolhido
          pelo clube, com timeline de notas e o arquivo pra ler junto. A aba só aparece
          pra quem entra.</p>
        <form method="POST" style="margin:0">
          <input type="hidden" name="acao" value="livro_entrar">
          <button type="submit" class="btn pri"><i class="bi bi-plus-lg"></i> Quero participar</button>
        </form>
      </div>
    </section>
  <?php endif; ?>
</div>

<?php /* ── O POPUP DO ADMIN ───────────────────────────────────────────
     Pedido do Marcos (14/10/2026): "até um popup que abre pro admin criar as
     pesquisas". Antes isso era um formulário no pé da aba do Livro — então
     pesquisa da liga inteira só nascia dentro da aba de dezessete pessoas. */ ?>
<?php if ($ehAdminClube): $pqTodas = clubeLivroPesquisasDoAdmin($pdo); ?>
<dialog class="pq-dlg" id="dlgPesquisa">
  <div class="pq-topo">
    <h3><i class="bi bi-ui-checks"></i> Pesquisas do Clube</h3>
    <button type="button" data-fechar aria-label="Fechar">&times;</button>
  </div>
  <div class="pq-corpo">
    <form method="POST" style="display:flex;flex-direction:column;gap:12px">
      <input type="hidden" name="acao" value="pesquisa_criar">
      <input type="hidden" name="de" value="inicio">
      <div>
        <label for="pqPergunta">Pergunta</label>
        <input type="text" id="pqPergunta" name="pergunta" required maxlength="200"
               placeholder="Qual o melhor filme que a liga viu este ano?">
      </div>
      <div>
        <label for="pqOpcoes">Opções <span style="font-weight:400;color:var(--txt3)">— uma por linha, no mínimo duas</span></label>
        <textarea id="pqOpcoes" name="opcoes" required
                  placeholder="Parasita&#10;A Viagem de Chihiro&#10;Interestelar"></textarea>
      </div>
      <div>
        <label for="pqEscopo">Onde ela aparece</label>
        <select id="pqEscopo" name="escopo">
          <option value="clube">Na capa do Clube — todo mundo vê e vota</option>
          <option value="livro">Só no Clube do Livro — quem entrou lá</option>
        </select>
      </div>
      <label class="pq-ck">
        <input type="checkbox" name="multi" value="1"> cada pessoa pode marcar várias opções</label>
      <div><button type="submit" class="btn pri"><i class="bi bi-plus-lg"></i> Criar pesquisa</button></div>
    </form>

    <?php if ($pqTodas): ?>
      <div>
        <label style="margin-bottom:8px">Pesquisas já criadas</label>
        <div class="pq-lista">
          <?php foreach ($pqTodas as $p): ?>
            <div class="pq-um">
              <span class="pq-i"><?= h($p['pergunta']) ?>
                <small><?= (int)$p['pessoas'] ?> pessoa<?= (int)$p['pessoas'] === 1 ? '' : 's' ?> ·
                  <?= $p['escopo'] === 'clube' ? 'capa do Clube' : 'Clube do Livro' ?></small></span>
              <span class="pq-badge <?= $p['status'] === 'aberta' ? 'on' : '' ?>"><?= h($p['status']) ?></span>
              <form method="POST">
                <input type="hidden" name="acao"
                       value="pesquisa_<?= $p['status'] === 'aberta' ? 'fechar' : 'reabrir' ?>">
                <input type="hidden" name="enquete" value="<?= (int)$p['id'] ?>">
                <input type="hidden" name="de" value="inicio">
                <button type="submit"><?= $p['status'] === 'aberta' ? 'Encerrar' : 'Reabrir' ?></button>
              </form>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>
  </div>
</dialog>
<script>
/* O popup abre pelo botão da linha de números e fecha no X, no Esc (de
   graça, por ser <dialog>) e no clique fora — que é o que todo mundo tenta
   antes de procurar o X. */
(function(){
  var dlg = document.getElementById('dlgPesquisa');
  var abre = document.getElementById('btnPesquisa');
  if (!dlg || !abre) return;
  abre.addEventListener('click', function(){ dlg.showModal(); });
  dlg.addEventListener('click', function(ev){
    if (ev.target === dlg) dlg.close();                 // o clique pegou o backdrop
    if (ev.target.closest('[data-fechar]')) dlg.close();
  });
})();
</script>
<?php endif; ?>
