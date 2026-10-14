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
 * ── O DESENHO, SEGUNDA VERSÃO (14/10/2026) ───────────────────────────
 *
 * A primeira saiu com borda em tudo: caixa da seção, caixa do card, caixa da
 * opção, caixa da lista. "Tá horrível", e estava — oito molduras disputando
 * a atenção numa tela cujo assunto são capas de disco e cartazes de cinema.
 * E havia um acidente por cima disso: a classe `.capa` já existia na página,
 * como o PÔSTER de uma série (`aspect-ratio: 2/3`), então o bloco inteiro
 * herdava fundo, borda e proporção de pôster. Por isso tudo aqui agora é
 * prefixado `cl-`.
 *
 * O princípio desta versão: SEPARAR POR ESPAÇO E TIPOGRAFIA, não por linha.
 * Quem divide as seções é o respiro entre elas; quem divide as opções é um
 * fundo um tom acima. Borda só onde há mesmo uma coisa em cima de outra — o
 * card, e nada mais. Cada mídia tem uma cor e ela aparece como luz (um halo
 * no alto do card, a barra do voto), nunca como contorno.
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

/* A cor de cada mídia. NÃO é a cor da pessoa (--acento) de propósito: aqui as
   três aparecem lado a lado, e três cards da mesma cor viram um bloco só. */
$capaCores = ['album' => '#a78bfa', 'filme' => '#fbbf24', 'livro' => '#34d399'];
$capaMidia = ['album' => 'musica', 'filme' => 'filmes', 'livro' => 'livro'];
?>
<style>
/* ── A CAPA ──────────────────────────────────────────────────────────
   Prefixo `cl-` em tudo. A página tem CSS próprio das abas — e `.capa`,
   sem prefixo, é o pôster de uma série lá em cima. */
.cl-capa{display:flex;flex-direction:column;gap:30px;padding-top:4px}

/* A linha de números: texto, não caixa. Ela informa o tamanho do Clube e
   depois sai da frente. */
.cl-nums{display:flex;flex-wrap:wrap;align-items:center;gap:4px 20px;
  font-size:12.5px;color:var(--txt3);padding-bottom:2px}
.cl-nums b{color:var(--txt2);font-weight:700}
.cl-nums .cl-admin{margin-left:auto}

.cl-sec{display:flex;flex-direction:column;gap:14px}
.cl-cab{display:flex;align-items:baseline;gap:12px;flex-wrap:wrap}
.cl-cab h2{font-family:var(--display);font-size:24px;font-weight:700;margin:0;
  letter-spacing:.4px;line-height:1}
.cl-cab p{margin:0;color:var(--txt3);font-size:12.5px}
.cl-cab .cl-mais{margin-left:auto;font-size:12.5px;font-weight:600;color:var(--txt2);
  text-decoration:none;white-space:nowrap;display:inline-flex;align-items:center;gap:5px}
.cl-cab .cl-mais:hover{color:var(--acento)}

/* ── OS TRÊS CARDS DA SEMANA ─────────────────────────────────────────
   Uma borda só, bem fraca, e a cor da mídia entrando como luz no alto —
   era uma tarja de 3px, que é o tipo de detalhe que envelhece a tela. */
.cl-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,300px),1fr));gap:16px}
.cl-card{position:relative;display:flex;flex-direction:column;gap:10px;
  padding:18px 20px 16px;border-radius:18px;background:var(--panel);
  border:none;overflow:hidden;transition:background var(--t) var(--ease)}
.cl-card::before{content:"";position:absolute;inset:-40% 30% auto -10%;height:170px;
  background:radial-gradient(60% 100% at 30% 0,color-mix(in srgb,var(--c) 26%,transparent),transparent 70%);
  pointer-events:none}
.cl-card:hover{background:color-mix(in srgb,var(--c) 4%,var(--panel))}
.cl-card > *{position:relative}

.cl-tipo{display:flex;align-items:center;gap:9px;font-size:11px;font-weight:800;
  text-transform:uppercase;letter-spacing:.9px;color:var(--txt2)}
.cl-tipo i{display:grid;place-items:center;width:26px;height:26px;border-radius:9px;font-size:14px;
  background:color-mix(in srgb,var(--c) 16%,transparent);color:var(--c)}
.cl-chip{margin-left:auto;font-size:9.5px;font-weight:800;letter-spacing:.6px;padding:4px 8px;
  border-radius:7px;background:color-mix(in srgb,var(--c) 16%,transparent);color:var(--c);
  text-transform:uppercase}

.cl-obra{font-family:var(--display);font-size:27px;font-weight:700;line-height:1.08;
  letter-spacing:.3px}
.cl-autor{color:var(--txt2);font-size:13px;margin-top:-4px}
.cl-media{display:flex;align-items:baseline;gap:9px;font-size:12.5px;color:var(--txt3)}
.cl-media b{font-family:var(--display);font-size:30px;line-height:1;color:var(--txt);
  font-variant-numeric:tabular-nums}
.cl-vazio{color:var(--txt3);font-size:13.5px;line-height:1.55}
.cl-prazo{font-size:12.5px;color:var(--txt2);line-height:1.5}
.cl-prazo b{color:var(--c);font-weight:700}

/* ── AS OPÇÕES DA VOTAÇÃO ────────────────────────────────────────────
   Sem moldura: o que separa uma da outra é o fundo, e a barra de votos é a
   própria cor da mídia crescendo por baixo do texto. A escolhida ganha um
   anel — é o único contorno da tela, e é o que precisa ser visto. */
/* A barra de rolagem fica escondida e, no lugar dela, a lista some num fade
   na borda de baixo: é o mesmo recado — "tem mais" — sem uma régua cinza
   cortando o card ao meio. */
.cl-ops{display:flex;flex-direction:column;gap:5px;max-height:232px;overflow-y:auto;
  margin:2px -4px 0;padding:0 4px;scrollbar-width:none}
.cl-ops::-webkit-scrollbar{display:none}
/* `.rola` é posta por JS só quando a lista REALMENTE passa da altura: a
   máscara aplicada sempre desbotaria a última opção de uma lista curta, que
   é dizer "tem mais" quando não tem. */
.cl-ops.rola{-webkit-mask-image:linear-gradient(to bottom,#000 calc(100% - 28px),transparent);
  mask-image:linear-gradient(to bottom,#000 calc(100% - 28px),transparent)}
.cl-ops form{margin:0}
.cl-op{position:relative;display:flex;align-items:center;gap:10px;width:100%;text-align:left;
  padding:9px 12px;border-radius:11px;border:1px solid transparent;background:var(--panel2);
  color:var(--txt);font:inherit;font-size:13.5px;cursor:pointer;overflow:hidden;
  transition:background var(--t) var(--ease)}
.cl-op:hover{background:var(--panel3)}
.cl-op i{position:absolute;inset:0 auto 0 0;width:var(--pct,0%);
  background:color-mix(in srgb,var(--c) 22%,transparent);pointer-events:none;
  transition:width .35s var(--ease)}
.cl-op span{position:relative;flex:1;min-width:0;line-height:1.3}
.cl-op small{display:block;color:var(--txt3);font-size:11.5px;margin-top:1px}
.cl-op b{position:relative;font-size:12px;color:var(--txt3);font-weight:700;
  font-variant-numeric:tabular-nums}
.cl-op.on{border-color:color-mix(in srgb,var(--c) 55%,transparent);background:var(--panel2)}
.cl-op.on b{color:var(--c)}

/* Dar nota sem sair da capa. */
.cl-dar{display:flex;gap:8px;align-items:center;margin-top:2px}
.cl-dar select{padding:8px 10px;border-radius:10px;border:1px solid var(--borda);
  background:var(--panel2);color:var(--txt);font-size:13px;font-family:inherit}
.cl-dar select:focus{outline:none;border-color:var(--c)}
.cl-dar button{padding:8px 13px;border-radius:10px;border:1px solid transparent;cursor:pointer;
  font:inherit;font-size:12.5px;font-weight:700;
  background:color-mix(in srgb,var(--c) 17%,transparent);color:var(--c)}
.cl-dar button:hover{background:color-mix(in srgb,var(--c) 26%,transparent)}

/* As últimas opiniões: é o que dá vida ao card de quem já foi escolhido e o
   que equilibra a altura dele com a do card em votação. */
.cl-vozes{display:flex;flex-direction:column;gap:7px;margin-top:2px}
.cl-voz{display:flex;align-items:flex-start;gap:8px;font-size:12.5px;line-height:1.35}
.cl-voz img{width:22px;height:22px;border-radius:50%;object-fit:cover;flex-shrink:0;margin-top:1px}
.cl-voz span{color:var(--txt3);min-width:0}
.cl-voz span b{color:var(--txt2);font-weight:700}
.cl-voz em{font-style:normal;color:var(--c);font-weight:700}

.cl-pe{margin-top:auto;padding-top:12px;display:flex;align-items:center;gap:12px;flex-wrap:wrap;
  font-size:12.5px}
.cl-pe a{font-weight:700;color:var(--txt2);text-decoration:none;display:inline-flex;
  align-items:center;gap:5px}
.cl-pe a:hover{color:var(--c)}
.cl-pe span{color:var(--txt3)}
.cl-pe span b{color:var(--c)}

/* ── PESQUISAS ───────────────────────────────────────────────────────*/
.cl-pq{display:flex;flex-direction:column;gap:11px;padding:18px 20px;border-radius:18px;
  background:var(--panel);border:none;--c:var(--acento)}
.cl-pq h3{margin:0;font-family:var(--display);font-size:19px;font-weight:700;letter-spacing:.3px}
.cl-pq .cl-quem{font-size:12px;color:var(--txt3);margin-top:-6px}

/* ── A VITRINE DE SÉRIES ─────────────────────────────────────────────
   Prateleira que rola de lado: a capa é resumo, e catorze pôsteres em grade
   tomariam a página inteira do resto do Clube. */
.cl-vitr{display:flex;gap:12px;overflow-x:auto;padding:2px 0 10px;scrollbar-width:thin;
  scrollbar-color:var(--panel3) transparent}
.cl-vitr::-webkit-scrollbar{height:4px}
.cl-vitr::-webkit-scrollbar-track{background:transparent}
.cl-vitr::-webkit-scrollbar-thumb{background:var(--panel3);border-radius:2px}
.cl-vitr a{flex:0 0 124px;text-decoration:none;color:inherit}
.cl-pst{position:relative;aspect-ratio:2/3;border-radius:13px;overflow:hidden;background:var(--panel2);
  box-shadow:0 6px 18px rgba(0,0,0,.28);transition:transform var(--t) var(--ease)}
.cl-vitr a:hover .cl-pst{transform:translateY(-3px)}
.cl-pst img{width:100%;height:100%;object-fit:cover;display:block}
.cl-pst .cl-sem{display:flex;align-items:center;justify-content:center;height:100%;padding:10px;
  color:var(--txt3);font-size:11.5px;text-align:center;line-height:1.3}
.cl-pst .cl-qtd{position:absolute;right:7px;bottom:7px;font-size:10.5px;font-weight:800;
  padding:3px 7px;border-radius:7px;background:rgba(0,0,0,.66);color:#fff;
  backdrop-filter:blur(4px)}
.cl-vitr b{display:-webkit-box;font-size:12px;font-weight:600;margin-top:7px;line-height:1.3;
  -webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;color:var(--txt2)}

/* ── O MOVIMENTO ─────────────────────────────────────────────────────
   Lista solta, sem caixa em volta: ela já é uma coluna de texto, e emoldurar
   texto é o que fazia a página parecer um formulário. */
.cl-mov{display:flex;flex-direction:column}
.cl-mov-um{display:flex;gap:11px;padding:11px 2px;border-top:1px solid var(--borda)}
.cl-mov-um:first-child{border-top:none}
.cl-mov-um > img{width:30px;height:30px;border-radius:50%;object-fit:cover;flex-shrink:0;margin-top:1px}
.cl-mov-c{flex:1;min-width:0;font-size:13.5px;line-height:1.45;color:var(--txt2)}
.cl-mov-c .cl-quem2{font-weight:700;color:var(--txt)}
.cl-mov-c a{color:var(--txt);text-decoration:none;font-weight:600}
.cl-mov-c a:hover{color:var(--acento)}
.cl-mov-c a i{color:var(--txt3);font-size:11.5px;margin-right:4px}
.cl-nota{display:inline-block;font-size:11px;font-weight:800;padding:1px 6px;border-radius:6px;
  background:color-mix(in srgb,var(--fba) 15%,transparent);color:var(--fba-txt);margin-left:3px;
  vertical-align:1px}
.cl-txt{color:var(--txt3);font-size:12.5px;margin-top:2px;display:-webkit-box;
  -webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.cl-quando{font-size:11.5px;color:var(--txt3);white-space:nowrap;flex-shrink:0;margin-top:2px}

/* ── O CONVITE DO LIVRO ──────────────────────────────────────────────*/
.cl-convite{display:flex;align-items:center;gap:16px;flex-wrap:wrap;padding:18px 20px;
  border-radius:18px;background:var(--panel);border:none;position:relative;
  overflow:hidden;--c:#34d399}
.cl-convite::before{content:"";position:absolute;inset:-60% 20% auto -5%;height:200px;
  background:radial-gradient(55% 100% at 25% 0,color-mix(in srgb,var(--c) 20%,transparent),transparent 70%)}
.cl-convite > *{position:relative}
.cl-convite p{margin:0;flex:1;min-width:240px;font-size:13.5px;color:var(--txt2);line-height:1.5}
.cl-convite p b{color:var(--txt);font-size:15px;font-family:var(--display);font-weight:700;
  letter-spacing:.3px;display:block;margin-bottom:3px}
.cl-convite button{padding:10px 16px;border-radius:11px;border:none;cursor:pointer;font:inherit;
  font-size:13px;font-weight:700;background:var(--c);color:#06231a}
.cl-convite button:hover{filter:brightness(1.08)}

/* ── O POPUP DAS PESQUISAS (admin) ───────────────────────────────────
   É <dialog>: a caixa é da página, com a cara do app, e não o prompt do
   navegador — o Clube inteiro segue essa regra. `margin:auto` porque o shell
   define margem em dialog e a caixa nascia grudada na esquerda. */
.cl-dlg{border:1px solid var(--borda2);border-radius:20px;background:var(--panel);color:var(--txt);
  padding:0;width:min(560px,94vw);max-height:88vh;margin:auto;
  box-shadow:0 30px 80px rgba(0,0,0,.5)}
.cl-dlg::backdrop{background:rgba(0,0,0,.62)}
.cl-dlg-topo{display:flex;align-items:center;gap:10px;padding:18px 20px 14px}
.cl-dlg-topo h3{margin:0;font-family:var(--display);font-size:21px;font-weight:700;letter-spacing:.3px}
.cl-dlg-topo button{margin-left:auto;background:none;border:none;color:var(--txt3);font-size:22px;
  cursor:pointer;line-height:1;padding:0 4px}
.cl-dlg-topo button:hover{color:var(--txt)}
.cl-dlg-corpo{padding:0 20px 20px;overflow-y:auto;max-height:calc(88vh - 64px);
  display:flex;flex-direction:column;gap:16px}
.cl-dlg-corpo label{display:block;font-size:11.5px;font-weight:800;color:var(--txt3);
  margin-bottom:6px;text-transform:uppercase;letter-spacing:.6px}
.cl-dlg-corpo input[type=text],.cl-dlg-corpo textarea,.cl-dlg-corpo select{width:100%;
  padding:11px 13px;border-radius:12px;border:1px solid var(--borda);background:var(--panel2);
  color:var(--txt);font-size:13.5px;font-family:inherit}
.cl-dlg-corpo textarea{min-height:110px;resize:vertical;line-height:1.55}
.cl-dlg-corpo input:focus,.cl-dlg-corpo textarea:focus,.cl-dlg-corpo select:focus{outline:none;
  border-color:var(--acento)}
.cl-ck{display:flex;align-items:center;gap:8px;font-size:13px;color:var(--txt2);font-weight:400;
  text-transform:none;letter-spacing:0;margin:0}
.cl-ck input{width:auto}
.cl-lista{display:flex;flex-direction:column;gap:7px}
.cl-um{display:flex;align-items:center;gap:10px;padding:11px 13px;border-radius:13px;
  background:var(--panel2);font-size:13px}
.cl-um .cl-i{flex:1;min-width:0}
.cl-um small{display:block;color:var(--txt3);font-size:11.5px;margin-top:2px}
.cl-um form{margin:0}
.cl-um button{padding:6px 11px;font-size:12px;border-radius:9px;border:1px solid var(--borda2);
  background:transparent;color:var(--txt2);cursor:pointer;font-family:inherit}
.cl-um button:hover{color:var(--txt);border-color:var(--acento)}
.cl-badge{font-size:9.5px;font-weight:800;text-transform:uppercase;letter-spacing:.5px;
  padding:3px 7px;border-radius:6px;background:var(--panel3);color:var(--txt3)}
.cl-badge.on{background:color-mix(in srgb,var(--green) 18%,transparent);color:var(--green)}

@media (max-width:620px){
  .cl-capa{gap:24px}
  .cl-cab h2{font-size:21px}
  .cl-obra{font-size:24px}
  /* No celular os cards já estão empilhados: não há coluna vizinha pra
     desalinhar, e rolagem dentro de rolagem é a pior coisa de se fazer com o
     dedo. Lá a lista abre inteira. */
  .cl-ops{max-height:none;overflow:visible}
  /* O atalho do admin cabe na direita de uma linha larga; numa estreita ele
     fica pendurado sozinho. Volta pro começo da linha seguinte. */
  .cl-nums .cl-admin{margin-left:0;width:100%;margin-top:2px}
}
</style>

<div class="cl-capa">

  <?php /* ── OS NÚMEROS ──────────────────────────────────────────────
       Não é enfeite: é o que diz, na primeira linha, que o Clube é maior
       que a aba onde a pessoa costuma cair. */ ?>
  <div class="cl-nums">
    <span><b><?= number_format($capaNumeros['series'], 0, ',', '.') ?></b> séries no catálogo</span>
    <span><b><?= (int)$capaNumeros['gente'] ?></b> <?= $capaNumeros['gente'] === 1 ? 'pessoa' : 'pessoas' ?> no Clube</span>
    <span><b><?= (int)$capaNumeros['obras'] ?></b> obras já escolhidas</span>
    <span><b><?= (int)$capaNumeros['livro'] ?></b> no Clube do Livro</span>
    <?php if ($ehAdminClube): ?>
      <span class="cl-admin"><a href="#" id="btnPesquisa"
            style="color:var(--acento);font-weight:700;text-decoration:none">
        <i class="bi bi-ui-checks"></i> Nova pesquisa</a></span>
    <?php endif; ?>
  </div>

  <?php /* ── 1. ESTA SEMANA ──────────────────────────────────────────── */ ?>
  <section class="cl-sec">
    <div class="cl-cab">
      <h2>Esta semana no Clube</h2>
      <p>o que está em cartaz e o que está em votação</p>
    </div>
    <div class="cl-cards">
      <?php foreach ($capaCartazes as $tipo => $d):
        $i = $d['info']; $c = $d['cartaz']; $v = $d['enquete'];
        $cor = $capaCores[$tipo]; $mid = $capaMidia[$tipo];
        /* A ABA DO LIVRO É DE QUEM ENTROU. O card aparece pra todo mundo —
           é assim que alguém descobre que o clube existe —, mas quem não é
           membro vai pro convite em vez de uma aba que não é dele. */
        $podeAbrir = $tipo !== 'livro' || $souDoLivro || $ehAdminClube; ?>
        <article class="cl-card" style="--c:<?= $cor ?>">
          <div class="cl-tipo">
            <i class="bi bi-<?= h($i['ico']) ?>"></i> <?= h($i['rot']) ?>
            <?php if ($v): ?><span class="cl-chip">votação aberta</span><?php endif; ?>
          </div>

          <?php if ($v): ?>
            <?php /* ── VOTAÇÃO ABERTA: vem na frente do cartaz, porque é a
                 única coisa da capa com hora pra acabar. */ ?>
            <div class="cl-prazo">
              <?= $v['etapa'] === 'genero' ? 'Que gênero o clube lê' : 'Escolha o seu' ?> ·
              fecha <b><?= h($v['dia_fecha'] ?? 'hoje') ?> às <?= h($v['etapa'] === 'genero'
                  ? $v['hora_vira'] : $v['hora_fecha']) ?></b> ·
              <?= (int)$v['total'] ?> voto<?= (int)$v['total'] === 1 ? '' : 's' ?>
            </div>
            <div class="cl-ops">
              <?php foreach ($v['opcoes'] as $o):
                  $pct = $v['total'] > 0 ? round(100 * $o['votos'] / $v['total']) : 0; ?>
                <form method="POST">
                  <input type="hidden" name="acao" value="semana_votar">
                  <input type="hidden" name="ciclo" value="<?= (int)$v['id'] ?>">
                  <input type="hidden" name="opcao" value="<?= (int)$o['id'] ?>">
                  <input type="hidden" name="de" value="inicio">
                  <button type="submit" class="cl-op <?= (int)$v['meu'] === (int)$o['id'] ? 'on' : '' ?>"
                          style="--pct:<?= $pct ?>%">
                    <i></i>
                    <span><?= h($o['titulo']) ?>
                      <?php if (trim((string)$o['autor']) !== ''): ?>
                        <small><?= h($o['autor']) ?><?= $o['ano'] ? ' · ' . (int)$o['ano'] : '' ?></small>
                      <?php endif; ?></span>
                    <b><?= (int)$o['votos'] ?></b>
                  </button>
                </form>
              <?php endforeach; ?>
            </div>

          <?php elseif ($c): ?>
            <div class="cl-obra"><?= h($c['titulo']) ?></div>
            <div class="cl-autor"><?= h($c['autor']) ?><?= $c['ano'] ? ' · ' . (int)$c['ano'] : '' ?></div>
            <?php if ($c['media'] !== null): ?>
              <div class="cl-media"><b><?= h($c['media']) ?></b>
                <span>média da liga · <?= count($c['opinioes']) ?> <?= count($c['opinioes']) === 1 ? 'opinião' : 'opiniões' ?></span></div>
            <?php else: ?>
              <div class="cl-vazio">Ninguém deu nota ainda — seja o primeiro.</div>
            <?php endif; ?>

            <?php /* ── A NOTA SAI DAQUI MESMO ───────────────────────────
                 É o número mais fraco do Clube: 21 pessoas votam no da
                 semana e 8 voltam pra dar nota. Voltar é o problema — a nota
                 estava a uma aba de distância, e quem ouviu o álbum no
                 domingo não abre uma aba pra digitar um número.
                 O COMENTÁRIO CONTINUA NA ABA: cabe pouco aqui, e a timeline
                 é onde ele é lido. Por isso o texto que a pessoa já escreveu
                 viaja junto no hidden — sem ele, dar nota pela capa apagaria
                 o recado dela (o UPDATE grava os dois). */ ?>
            <form class="cl-dar" method="POST">
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
              <button type="submit">
                <?= $c['minha'] && $c['minha']['nota'] !== null ? 'Trocar a nota' : 'Dar nota' ?></button>
            </form>

            <?php /* As últimas vozes: quem leu a nota média quer saber quem
                 achou o quê. Três bastam — o resto é a timeline. */ ?>
            <?php $vozes = array_slice($c['opinioes'], 0, 3); ?>
            <?php if ($vozes): ?>
              <div class="cl-vozes">
                <?php foreach ($vozes as $op): ?>
                  <div class="cl-voz">
                    <img src="<?= h(clubeAvatar($op['photo_url'])) ?>" alt="">
                    <span><b><?= h($op['name']) ?></b><?php
                      if ($op['nota'] !== null): ?> <em><?= (int)$op['nota'] ?></em><?php endif;
                      $t = trim((string)($op['texto'] ?? ''));
                      if ($t !== ''): ?> — <?= h(mb_strimwidth($t, 0, 70, '…')) ?><?php endif; ?></span>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>

          <?php else: ?>
            <div class="cl-vazio">
              <?= $tipo === 'livro'
                  ? 'Ainda não tem livro do mês. A lista é escolhida pelo clube e a votação abre aqui.'
                  : 'O primeiro sai sexta às 20h, escolhido pelo voto da liga.' ?>
            </div>
          <?php endif; ?>

          <div class="cl-pe">
            <?php if ($podeAbrir): ?>
              <a href="?midia=<?= h($mid) ?>">
                <?php if ($c && !$v): ?>
                  <?= $c['minha'] ? 'Ver a timeline' : 'Comentar na timeline' ?> <i class="bi bi-arrow-right"></i>
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
              <span>em cartaz: <?= h($c['titulo']) ?></span>
            <?php elseif ($c && $c['minha'] && $c['minha']['nota'] !== null): ?>
              <span>sua nota: <b><?= (int)$c['minha']['nota'] ?></b></span>
            <?php endif; ?>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  </section>

  <?php /* ── 2. AS PESQUISAS DA LIGA ─────────────────────────────────
       Escopo 'clube': são as que o admin cria pelo popup, e valem pra todo
       mundo. As do Clube do Livro continuam lá dentro. */ ?>
  <?php if ($capaPesquisas): ?>
    <section class="cl-sec">
      <div class="cl-cab">
        <h2>Pesquisas</h2>
        <p>o Clube quer saber</p>
      </div>
      <div class="cl-cards">
        <?php foreach ($capaPesquisas as $e): ?>
          <div class="cl-pq">
            <h3><?= h($e['pergunta']) ?></h3>
            <div class="cl-quem">
              <?php if ($e['status'] === 'aberta'): ?>
                <?= (int)$e['pessoas'] === 1
                    ? '1 pessoa já respondeu'
                    : (int)$e['pessoas'] . ' pessoas já responderam' ?><?=
                  $e['multi'] ? ' · marque quantos quiser' : '' ?>
              <?php else: ?>
                encerrada · resultado de <?= (int)$e['pessoas'] ?> pessoa<?= (int)$e['pessoas'] === 1 ? '' : 's' ?>
              <?php endif; ?>
            </div>
            <div class="cl-ops" style="max-height:none;overflow:visible">
              <?php $tot = max(1, (int)$e['total']);
              foreach ($e['opcoes'] as $o): $pct = round(100 * $o['votos'] / $tot); ?>
                <?php if ($e['status'] === 'aberta'): ?>
                  <form method="POST">
                    <input type="hidden" name="acao" value="pesquisa_votar">
                    <input type="hidden" name="enquete" value="<?= (int)$e['id'] ?>">
                    <input type="hidden" name="opcao" value="<?= (int)$o['id'] ?>">
                    <input type="hidden" name="de" value="inicio">
                    <button type="submit" class="cl-op <?= in_array((int)$o['id'], $e['meus'], true) ? 'on' : '' ?>"
                            style="--pct:<?= $pct ?>%">
                      <i></i><span><?= h($o['texto']) ?></span><b><?= (int)$o['votos'] ?></b>
                    </button>
                  </form>
                <?php else: ?>
                  <div class="cl-op" style="--pct:<?= $pct ?>%;cursor:default">
                    <i></i><span><?= h($o['texto']) ?></span><b><?= (int)$o['votos'] ?></b>
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
    <section class="cl-sec">
      <div class="cl-cab">
        <h2><?= h($capaVitrine['rot']) ?></h2>
        <p>séries</p>
        <a class="cl-mais" href="?midia=series&amp;aba=catalogo">Catálogo completo <i class="bi bi-arrow-right"></i></a>
      </div>
      <div class="cl-vitr">
        <?php foreach ($capaVitrine['itens'] as $s):
          $pst = seriesPoster($s['poster'] ?? null); ?>
          <a href="?midia=series&amp;aba=catalogo&amp;q=<?= rawurlencode((string)$s['titulo']) ?>">
            <div class="cl-pst">
              <?php if ($pst): ?>
                <img src="<?= h($pst) ?>" alt="" loading="lazy">
              <?php else: ?>
                <div class="cl-sem"><?= h($s['titulo']) ?></div>
              <?php endif; ?>
              <span class="cl-qtd"><?= (int)$s['quantos'] ?></span>
            </div>
            <b><?= h($s['titulo']) ?></b>
          </a>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endif; ?>

  <?php /* ── 4. O MOVIMENTO ──────────────────────────────────────────── */ ?>
  <?php if ($capaMovimento): ?>
    <section class="cl-sec">
      <div class="cl-cab">
        <h2>O que a liga andou fazendo</h2>
        <p>séries, álbuns, filmes e livros na mesma linha</p>
        <a class="cl-mais" href="?midia=series&amp;aba=liga">Ver tudo das séries <i class="bi bi-arrow-right"></i></a>
      </div>
      <div class="cl-mov">
        <?php foreach ($capaMovimento as $m): ?>
          <div class="cl-mov-um">
            <img src="<?= h(clubeAvatar($m['foto'])) ?>" alt="">
            <div class="cl-mov-c">
              <span class="cl-quem2"><?= h($m['quem']) ?></span>
              <?= h($m['verbo']) ?>
              <a href="<?= h($m['link']) ?>"><i class="bi bi-<?= h($m['ico']) ?>"></i><?= h($m['titulo']) ?></a>
              <?php if ($m['nota'] !== null): ?>
                <span class="cl-nota"><?= (int)$m['nota'] ?></span>
              <?php endif; ?>
              <?php if ($m['texto']): ?>
                <div class="cl-txt"><?= h($m['texto']) ?></div>
              <?php endif; ?>
            </div>
            <span class="cl-quando"><?= h(tempoRelativoCurto($m['quando'])) ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endif; ?>

  <?php /* ── 5. O CONVITE DO CLUBE DO LIVRO ──────────────────────────
       Só pra quem está de fora: pra quem entrou, o livro já é um dos três
       cards lá de cima. */ ?>
  <?php if (!$souDoLivro): ?>
    <section class="cl-sec" id="clube-do-livro">
      <div class="cl-convite">
        <p><b>Clube do Livro</b>
          Um livro por mês, escolhido pelo clube, com timeline de notas e o arquivo
          pra ler junto. A aba só aparece pra quem entra.</p>
        <form method="POST" style="margin:0">
          <input type="hidden" name="acao" value="livro_entrar">
          <button type="submit"><i class="bi bi-plus-lg"></i> Quero participar</button>
        </form>
      </div>
    </section>
  <?php endif; ?>
</div>

<script>
/* A lista de opções que passa da altura do card ganha o fade no pé. Roda uma
   vez, e de novo quando a largura muda — o que cabe numa coluna de celular
   não é o que cabe em três colunas. */
(function(){
  function marca(){
    document.querySelectorAll('.cl-ops').forEach(function(el){
      el.classList.toggle('rola', el.scrollHeight > el.clientHeight + 4);
    });
  }
  marca();
  addEventListener('resize', marca);
})();
</script>

<?php /* ── O POPUP DO ADMIN ───────────────────────────────────────────
     Pedido do Marcos (14/10/2026): "até um popup que abre pro admin criar as
     pesquisas". Antes isso era um formulário no pé da aba do Livro — então
     pesquisa da liga inteira só nascia dentro da aba de dezessete pessoas. */ ?>
<?php if ($ehAdminClube): $pqTodas = clubeLivroPesquisasDoAdmin($pdo); ?>
<dialog class="cl-dlg" id="dlgPesquisa">
  <div class="cl-dlg-topo">
    <h3>Pesquisas do Clube</h3>
    <button type="button" data-fechar aria-label="Fechar">&times;</button>
  </div>
  <div class="cl-dlg-corpo">
    <form method="POST" style="display:flex;flex-direction:column;gap:14px">
      <input type="hidden" name="acao" value="pesquisa_criar">
      <input type="hidden" name="de" value="inicio">
      <div>
        <label for="pqPergunta">Pergunta</label>
        <input type="text" id="pqPergunta" name="pergunta" required maxlength="200"
               placeholder="Qual o melhor filme que a liga viu este ano?">
      </div>
      <div>
        <label for="pqOpcoes">Opções — uma por linha, no mínimo duas</label>
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
      <label class="cl-ck">
        <input type="checkbox" name="multi" value="1"> cada pessoa pode marcar várias opções</label>
      <div><button type="submit" class="btn pri"><i class="bi bi-plus-lg"></i> Criar pesquisa</button></div>
    </form>

    <?php if ($pqTodas): ?>
      <div>
        <label>Pesquisas já criadas</label>
        <div class="cl-lista">
          <?php foreach ($pqTodas as $p): ?>
            <div class="cl-um">
              <span class="cl-i"><?= h($p['pergunta']) ?>
                <small><?= (int)$p['pessoas'] ?> pessoa<?= (int)$p['pessoas'] === 1 ? '' : 's' ?> ·
                  <?= $p['escopo'] === 'clube' ? 'capa do Clube' : 'Clube do Livro' ?></small></span>
              <span class="cl-badge <?= $p['status'] === 'aberta' ? 'on' : '' ?>"><?= h($p['status']) ?></span>
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
/* O popup abre pelo link da linha de números e fecha no X, no Esc (de graça,
   por ser <dialog>) e no clique fora — que é o que todo mundo tenta antes de
   procurar o X. */
(function(){
  var dlg = document.getElementById('dlgPesquisa');
  var abre = document.getElementById('btnPesquisa');
  if (!dlg || !abre) return;
  abre.addEventListener('click', function(ev){ ev.preventDefault(); dlg.showModal(); });
  dlg.addEventListener('click', function(ev){
    if (ev.target === dlg) dlg.close();                 // o clique pegou o backdrop
    if (ev.target.closest('[data-fechar]')) dlg.close();
  });
})();
</script>
<?php endif; ?>
