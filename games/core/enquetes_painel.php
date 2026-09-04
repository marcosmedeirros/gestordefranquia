<?php
/**
 * O PAINEL DAS ENQUETES, pra viver dentro da aba do /games.
 *
 * Nasceu como página inteira e virou aba porque é ali que a pessoa já está,
 * com o saldo na tela e as outras abas ao lado.
 *
 * Todas as classes levam o prefixo `eq-`: .card, .saldo, .selo e .sub já
 * existem no games.php com outro desenho. O prefixo entra no CSS e dentro
 * de class="" — nunca no código, senão `d.saldo` viraria `d.eq-saldo`.
 */
// Só pelas constantes (ENQ_CATEGORIAS no formulário). O motor não roda nada
// ao ser incluído, e o require_once cuida de já estar carregado pela página.
require_once __DIR__ . '/enquetes_motor.php';
?>
<style>
/*
 * AS VARIÁVEIS FICAM NO PAINEL, não no :root.
 *
 * Quando isto era página inteira, `:root` era dele. Como aba, `:root` é do
 * /games — e --bg, --panel e --texto existem lá com outros valores. Declarar
 * de novo ali repintava a página inteira, incluindo as outras quatro abas.
 * Presas ao #pane-eventos, valem só aqui dentro (os modais também estão dentro
 * dele, então herdam).
 */
#pane-eventos{--bg:#0a0a0c;--panel:#141418;--panel2:#1b1b21;--panel3:#232329;
  --borda:rgba(255,255,255,.07);--texto:#f4f4f5;--text2:#a1a1aa;--text3:#71717a;
  --verde:#22c55e;--vermelho:#ef4444;--amber:#f59e0b;--azul:#3b82f6;
  --font:'Inter',system-ui,sans-serif;--num:'Inter',sans-serif;
  color:var(--texto);font-family:var(--font)}
#pane-eventos *{box-sizing:border-box}
/* Sem regra de `body`: a de antes trocava o fundo e o padding do /games
   inteiro só por esta aba existir na página. */
.eq-wrap{max-width:none;margin:0}
.eq-topo{display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:6px}
#pane-eventos h1{font-size:23px;font-weight:900;letter-spacing:-.5px}
.eq-lead{color:var(--text2);font-size:13.5px;line-height:1.6;margin-bottom:16px;max-width:70ch}

/* A explicação ganhou espaço e virou um bloco próprio: era um parágrafo
   corrido onde a parte que mais importa — quem paga e quem embolsa —
   passava batido no meio da frase. */
.eq-como{background:var(--panel);border:1px solid var(--borda);border-radius:13px;
  padding:13px 18px;margin-bottom:18px}
.eq-como[open]{padding-bottom:15px}
.eq-como-t{display:flex;align-items:center;gap:7px;font-size:11px;font-weight:900;
  letter-spacing:.5px;text-transform:uppercase;color:var(--verde);cursor:pointer;
  list-style:none;user-select:none}
.eq-como-t::-webkit-details-marker{display:none}
/* A seta é do próprio bloco, não do navegador: o marcador padrão fica de
   fora do alinhamento e muda de desenho conforme o browser. */
.eq-como-t::after{content:'\F282';font-family:'bootstrap-icons';margin-left:auto;
  font-size:12px;color:var(--text3);transition:transform .2s ease}
.eq-como[open] .eq-como-t{margin-bottom:11px}
.eq-como[open] .eq-como-t::after{transform:rotate(180deg)}
.eq-passos{list-style:none;counter-reset:eqp;display:grid;gap:9px;
  grid-template-columns:repeat(auto-fit,minmax(280px,1fr))}
.eq-passos li{counter-increment:eqp;position:relative;padding-left:29px;
  font-size:13px;line-height:1.55;color:var(--text2)}
.eq-passos li::before{content:counter(eqp);position:absolute;left:0;top:1px;
  width:20px;height:20px;border-radius:50%;background:var(--panel3);
  border:1px solid var(--borda);color:var(--verde);
  font-size:11px;font-weight:900;display:flex;align-items:center;justify-content:center}
.eq-passos b{color:var(--texto)}
.eq-como-p{font-size:12.5px;line-height:1.6;color:var(--text3);
  margin-top:13px;padding-top:12px;border-top:1px solid var(--borda)}
.eq-como-p b{color:var(--text2)}

/* O retido, agora colado no título.
   Era um card empilhado com outros dois; sozinho e na mesma linha do "Eventos"
   ele vira uma pastilha — o bloco alto de antes desalinhava o cabeçalho. */
.eq-saldos{display:flex;gap:9px;flex-wrap:wrap}
.eq-saldo{display:flex;align-items:baseline;gap:6px;
  background:rgba(245,158,11,.1);border:1px solid rgba(245,158,11,.35);
  border-radius:999px;padding:5px 12px}
.eq-saldo b{font-family:var(--num);font-size:14px;font-weight:900;letter-spacing:-.3px}
.eq-saldo span{font-size:10px;font-weight:800;letter-spacing:.4px;
  text-transform:uppercase;color:var(--text3)}

/* O placar, no mesmo desenho dos cards de palpite da aba Apostas — é a mesma
   informação (quanto acertei), e duas caras pra ela na mesma página faria a
   pessoa reaprender a ler o número ao trocar de aba. */
.eq-placar{display:grid;gap:10px;margin-bottom:16px;
  grid-template-columns:repeat(auto-fit,minmax(112px,1fr))}
.eq-pc{background:var(--panel);border:1px solid var(--borda);border-radius:10px;
  padding:13px 14px;display:flex;flex-direction:column;gap:2px}
.eq-pc b{font-family:var(--num);font-size:22px;font-weight:800;line-height:1.05;
  font-variant-numeric:tabular-nums}
.eq-pc span{font-size:11px;color:var(--text3);font-weight:600}
.eq-pc span i{display:block;font-style:normal;font-size:10px;opacity:.75;margin-top:1px}
.eq-pc-verde b{color:var(--verde)}
.eq-pc-vermelho b{color:var(--vermelho)}

.eq-btn{background:var(--panel3);border:1px solid var(--borda);color:var(--texto);
  border-radius:10px;padding:10px 15px;font-family:var(--font);font-size:13px;
  font-weight:800;cursor:pointer;transition:border-color .15s}
.eq-btn:hover{border-color:rgba(255,255,255,.22)}
.eq-btn.eq-pri{background:var(--verde);border-color:var(--verde);color:#08160c}
.eq-btn.eq-mal{color:var(--vermelho);border-color:rgba(239,68,68,.4)}
.eq-btn:disabled{opacity:.45;cursor:not-allowed}

/* ── A MESA: uma aposta por linha, odds em coluna ─────────────────────
 *
 * O formato de casa de aposta, que só cabe aqui porque o teto de 4
 * alternativas dá às colunas uma largura previsível. A grade de cards
 * ficou pro que não é linha (o detalhe de uma encerrada, por exemplo).
 */
.eq-grade{display:flex;flex-direction:column;gap:18px}

.eq-grupo{border:1px solid var(--borda);border-radius:13px;overflow:hidden}
.eq-grupo-h{display:flex;align-items:center;justify-content:space-between;gap:10px;
  background:rgba(34,197,94,.13);border-bottom:1px solid var(--borda);
  padding:9px 14px;font-size:12px;font-weight:900;letter-spacing:.4px;
  text-transform:uppercase;color:var(--verde)}
.eq-grupo-n{font-size:10.5px;font-weight:800;color:var(--text3);
  background:var(--panel);border-radius:99px;padding:2px 8px}

.eq-mesa{border-bottom:1px solid var(--borda);background:var(--panel);transition:background .4s ease,box-shadow .4s ease}
/* O pisca de quem chegou pelo link do grupo. Sai sozinho depois de 2,6s: é
   pra dizer "é este aqui", não pra marcar o card pra sempre. */
.eq-alvo{background:color-mix(in srgb,var(--amber) 12%,var(--panel));box-shadow:inset 3px 0 0 var(--amber)}
@media (prefers-reduced-motion:reduce){.eq-mesa{transition:none}}
.eq-mesa:last-child{border-bottom:0}
.eq-mesa.eq-minha{background:rgba(59,130,246,.05)}
.eq-mesa-linha{display:flex;align-items:stretch;gap:12px;padding:11px 14px}
.eq-mesa-t{flex:1;min-width:0;display:flex;flex-direction:column;justify-content:center;gap:2px}
.eq-mesa-t b{font-size:13.5px;font-weight:800;letter-spacing:-.1px}
.eq-mesa-t small{font-size:11px;color:var(--text3);
  white-space:nowrap;overflow:hidden;text-overflow:ellipsis}

/* Uma coluna por alternativa, todas do mesmo tamanho: é o alinhamento entre
   linhas vizinhas que faz a odd ser comparável de bater o olho. */
.eq-cols{display:grid;gap:6px;flex:0 0 auto;
  grid-template-columns:repeat(var(--n,2),96px)}
.eq-mesa[data-alts="3"] .eq-cols{--n:3}
.eq-mesa[data-alts="4"] .eq-cols{--n:4}
.eq-col{position:relative;overflow:hidden;display:flex;flex-direction:column;
  align-items:center;justify-content:center;gap:1px;
  background:var(--panel2);border:1px solid var(--borda);border-radius:9px;
  padding:8px 6px;color:var(--texto);font-family:var(--font);cursor:pointer;
  transition:border-color .15s,background .15s}
.eq-col:hover:not([disabled]){border-color:rgba(34,197,94,.5);background:var(--panel3)}
.eq-col[disabled]{cursor:default;opacity:.75}
.eq-col.eq-tem{border-color:rgba(59,130,246,.5)}
.eq-col-t{position:relative;font-size:10.5px;font-weight:700;color:var(--text2);
  max-width:100%;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.eq-col-o{position:relative;font-family:var(--num);font-size:16px;font-weight:900;color:var(--amber)}
.eq-col-m{position:relative;font-size:9px;font-weight:700;color:var(--azul)}
.eq-col-barra{position:absolute;left:0;bottom:0;height:2px;background:rgba(34,197,94,.5)}

/* A odd com a caixa aberta fica marcada: sem isso, com uma caixa por vez na
   página inteira, some a pista de qual delas foi clicada. */
.eq-col.eq-on{border-color:var(--verde);background:rgba(34,197,94,.12)}

/* Meus eventos: uma linha por aposta que eu banco. */
.eq-meus{display:flex;flex-direction:column;gap:1px;
  border:1px solid var(--borda);border-radius:12px;overflow:hidden}
.eq-meu{display:flex;align-items:center;gap:12px;flex-wrap:wrap;
  background:var(--panel);border-bottom:1px solid var(--borda);padding:11px 14px}
.eq-meu:last-child{border-bottom:0}
.eq-meu-t{flex:1;min-width:200px}
.eq-meu-t b{display:block;font-size:13px;font-weight:800}
.eq-meu-t small{font-size:11px;color:var(--text3)}
.eq-meu-acoes{display:flex;align-items:center;gap:7px;flex-wrap:wrap}
#pane-eventos .eq-meu-acoes select{width:auto;max-width:230px;font-size:12px;padding:8px 10px}
.eq-retido{color:var(--amber);font-weight:800}
@media(max-width:560px){ .eq-meu-acoes{width:100%} #pane-eventos .eq-meu-acoes select{max-width:none;flex:1} }
.eq-box-alvo{font-size:11px;font-weight:800;color:var(--text2);margin-bottom:7px}

@media(max-width:700px){
  /* No celular a linha vira duas: o texto em cima, as odds embaixo, ocupando
     a largura toda — 96px fixos por coluna não cabem em 390. */
  .eq-mesa-linha{flex-direction:column;gap:9px}
  .eq-cols{grid-template-columns:repeat(var(--n,2),1fr);width:100%}
  .eq-mesa-t small{white-space:normal}
}
.eq-card{background:var(--panel);border:1px solid var(--borda);border-radius:15px;
  padding:16px 17px}
.eq-card.eq-minha{border-color:rgba(59,130,246,.35)}
.eq-card.eq-paga{opacity:.72}
.eq-ch{display:flex;align-items:flex-start;gap:10px;flex-wrap:wrap;margin-bottom:4px}
#pane-eventos .eq-ch h2{font-size:15.5px;font-weight:800;letter-spacing:-.2px;flex:1;min-width:200px}
.eq-selo{font-size:9.5px;font-weight:900;letter-spacing:.6px;text-transform:uppercase;
  padding:4px 8px;border-radius:99px;white-space:nowrap}
.eq-selo.eq-aberta{background:rgba(34,197,94,.16);color:var(--verde)}
.eq-selo.eq-paga{background:rgba(113,113,122,.2);color:var(--text2)}
.eq-selo.eq-cancelada{background:rgba(239,68,68,.14);color:var(--vermelho)}
.eq-sub{font-size:12px;color:var(--text3);margin-bottom:12px}
.eq-sub b{color:var(--text2)}

.eq-alts{display:flex;flex-direction:column;gap:7px}
.eq-alt{display:flex;align-items:center;gap:11px;background:var(--panel2);
  border:1px solid var(--borda);border-radius:11px;padding:11px 13px;width:100%;
  color:var(--texto);font-family:var(--font);text-align:left;cursor:pointer;
  position:relative;overflow:hidden;transition:border-color .15s}
.eq-alt:hover{border-color:rgba(255,255,255,.2)}
.eq-alt.eq-ganhou{border-color:rgba(34,197,94,.5);background:rgba(34,197,94,.09)}
.eq-alt.eq-tem{border-color:rgba(59,130,246,.4)}
.eq-alt[disabled]{cursor:default}
.eq-barra{position:absolute;left:0;top:0;bottom:0;background:rgba(255,255,255,.045);
  transition:width .4s ease}
.eq-alt-txt{flex:1;min-width:0;font-size:13.5px;font-weight:700;position:relative}
.eq-alt-min{display:block;font-size:10.5px;font-weight:600;color:var(--text3);margin-top:2px}
.eq-odd{font-family:var(--num);font-size:17px;font-weight:900;color:var(--amber);
  position:relative;white-space:nowrap}
.eq-odd small{display:block;font-size:8.5px;font-weight:800;color:var(--text3);
  letter-spacing:.5px;text-transform:uppercase;text-align:right}

/* O formulário de aposta, embaixo da linha da aposta escolhida. */
.eq-box{background:var(--panel3);border-top:1px solid rgba(34,197,94,.35);
  padding:11px 14px}
.eq-box-linha{display:flex;gap:7px;flex-wrap:wrap;align-items:center}
#pane-eventos .eq-box-linha input{width:110px;font-size:17px;font-weight:900;
  font-family:var(--num);text-align:center;padding:8px 10px}
.eq-box-previa{font-size:12px;color:var(--text2);margin-top:8px;line-height:1.5}
.eq-nota-dono{font-size:12px;color:var(--text3);margin-top:10px;
  background:var(--panel2);border:1px solid var(--borda);border-radius:9px;padding:9px 11px}

.eq-linha-dono{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px;
  padding-top:12px;border-top:1px solid var(--borda)}
.eq-vazio{color:var(--text3);font-size:13px;padding:26px 0;text-align:center}
/* O encerrado fica no fim, atrás de uma busca: o que importa na chegada é
   o que ainda dá pra apostar. */
.eq-fim{margin-top:30px;padding-top:22px;border-top:1px solid var(--borda)}
.eq-fim-topo{display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:14px}
#pane-eventos .eq-fim-topo h2{font-size:14px;font-weight:800;letter-spacing:-.2px}
#pane-eventos .eq-busca{max-width:260px;margin-left:auto;font-size:13px}
.eq-cont{font-size:11.5px;color:var(--text3);font-weight:700}

/* As encerradas são histórico: viram linha, não card. Uma clicada abre o
   card inteiro embaixo, pra quem quiser conferir o que pagou o quê. */
.eq-linhas{display:flex;flex-direction:column;gap:1px;
  border:1px solid var(--borda);border-radius:12px;overflow:hidden}
.eq-li{display:flex;align-items:center;gap:10px;width:100%;text-align:left;
  background:var(--panel);border:0;border-bottom:1px solid var(--borda);
  padding:10px 13px;color:var(--texto);font-family:var(--font);cursor:pointer}
.eq-li:last-of-type{border-bottom:0}
.eq-li:hover{background:var(--panel2)}
.eq-li-t{flex:1;min-width:0;font-size:13px;font-weight:700;
  white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.eq-li-t small{display:block;font-size:10.5px;font-weight:600;color:var(--text3);
  margin-top:1px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.eq-li-v{font-size:11.5px;font-weight:700;color:var(--text2);white-space:nowrap}
.eq-li-seta{color:var(--text3);font-size:11px}
.eq-li-aberto{padding:11px 13px;background:var(--panel2);border-bottom:1px solid var(--borda)}
.eq-li-aberto .eq-card{border:0;background:none;padding:0}
@media(max-width:560px){ .eq-li-v{display:none} }

.eq-modal{position:fixed;inset:0;background:rgba(0,0,0,.72);display:none;
  align-items:center;justify-content:center;padding:16px;z-index:50}
.eq-modal.eq-on{display:flex}
.eq-mbox{background:var(--panel);border:1px solid var(--borda);border-radius:16px;
  padding:20px;width:100%;max-width:520px;max-height:88vh;overflow:auto}
#pane-eventos .eq-mbox h3{font-size:17px;font-weight:900;margin-bottom:4px}
.eq-mbox p.eq-aj{font-size:12px;color:var(--text3);margin-bottom:14px;line-height:1.55}
#pane-eventos label{display:block;font-size:10px;font-weight:800;letter-spacing:.6px;
  text-transform:uppercase;color:var(--text3);margin:11px 0 5px}
#pane-eventos input,#pane-eventos textarea,#pane-eventos select{width:100%;background:var(--panel2);border:1px solid var(--borda);
  border-radius:9px;padding:10px 12px;color:var(--texto);font-family:var(--font);
  font-size:14px;font-weight:600;outline:none}
#pane-eventos input:focus,#pane-eventos textarea:focus{border-color:rgba(255,255,255,.24)}
.eq-alt-linha{display:grid;grid-template-columns:1fr 92px;gap:7px;margin-bottom:7px}
.eq-duo{display:grid;grid-template-columns:1fr 1fr;gap:9px}
.eq-dica{display:block;font-size:10.5px;color:var(--text3);margin-top:4px}
.eq-aviso{background:rgba(245,158,11,.1);border:1px solid rgba(245,158,11,.3);
  border-radius:10px;padding:10px 12px;font-size:12px;color:var(--text2);
  line-height:1.55;margin-top:12px}
.eq-mfoot{display:flex;gap:8px;justify-content:flex-end;margin-top:16px;flex-wrap:wrap}
@media(max-width:560px){ .eq-duo{grid-template-columns:1fr} #pane-eventos h1{font-size:20px} }
</style>
<?php /* Sem <head>/<body> aqui: isto é um pedaço da página, não uma página.
         Eles sobraram da conversão e ficavam soltos no meio do /games. */ ?>
<div class="eq-wrap">
  <div class="eq-topo">
    <?php /* Nada de "voltar aos games": já estamos dentro deles, e a aba de
             onde se veio está logo acima. */ ?>
    <h1>Eventos</h1>
    <?php /* O retido fica AQUI, colado no título, e não lá embaixo perdido
             entre a explicação e a lista: é moeda sua que está presa agora,
             e a pessoa precisa ver isso ao chegar, não ao rolar. */ ?>
    <div class="eq-saldos" id="saldos" hidden></div>
    <?php /* Criar é de todo mundo, e por isso o botão já nasce visível: ele
             ficava escondido esperando a listagem dizer se a pessoa era
             admin, e piscava na tela de quem tinha permissão. */ ?>
    <button class="eq-btn eq-pri" id="btnCriar" style="margin-left:auto" onclick="abrirCriar()">
      <i class="bi bi-plus-lg"></i> Criar evento
    </button>
  </div>
  <?php /* Em passos, e não num parágrafo corrido: quem chega aqui pela
           primeira vez precisa entender que o criador é a CASA — bota o
           dinheiro dele e fica com o dos outros quando eles erram. Escondido
           no meio de uma frase, isso passava batido. */ ?>
  <?php /* Fechado por padrão: quem já entendeu passa por ele todo dia, e o
           bloco aberto empurrava as apostas — o motivo da visita — pra baixo
           da dobra. <details> guarda o estado de aberto sem uma linha de JS. */ ?>
  <details class="eq-como">
    <summary class="eq-como-t"><i class="bi bi-info-circle-fill"></i> Como funciona</summary>
    <ol class="eq-passos">
      <li><b>Aposta é sobre o que vai acontecer.</b> "O Vasco cai em 2026?",
        "quem ganha a ELITE?", "o Fulano troca antes do draft?" — coisas que
        um dia acontecem e todo mundo consegue conferir. Gosto pessoal não
        serve: quem declara o resultado é quem paga, e numa pergunta de
        opinião ele declararia o que lhe convém.</li>
      <li><b>Quem cria vira a banca.</b> Você escreve a pergunta, as opções e a
        odd de cada uma. A partir daí é <b>contra você</b> que os outros apostam.</li>
      <li><b>Quem paga é você, com as suas moedas.</b> Se alguém apostar 100 numa
        odd de 2.00 e acertar, saem 200 do seu saldo: as 100 dele de volta mais
        100 de lucro.</li>
      <li><b>Se eles erram, o dinheiro é seu.</b> Todas as moedas apostadas nas
        opções que não venceram ficam com você — é assim que bancar dá lucro.</li>
      <li><b>Nada fica devendo.</b> Enquanto a aposta está aberta, o pior
        resultado possível fica <b>retido</b> do seu saldo. Você só consegue abrir
        uma aposta que consiga pagar, e a retenção volta quando ela é encerrada.</li>
      <li><b>Você declara o resultado</b> em "Meus eventos", aqui embaixo. O
        pagamento sai na hora, e a casa fica com <?= ENQ_TAXA_CASA ?>% do que
        você lucrar (nada, se você perder).</li>
    </ol>
    <p class="eq-como-p">
      Na hora de apostar, a odd <b>trava no clique</b>: é a que você recebe, mesmo
      que ela mude depois. E ela muda — quanto mais dinheiro entra numa opção,
      menos ela paga, e mais pagam as outras.
    </p>
  </details>

  <?php /* O PLACAR, no mesmo desenho do que a aba Apostas mostra nos palpites.
           Vem antes da lista porque é o que a pessoa quer saber ao chegar:
           quanto isso me deu e o quanto eu acerto. Fica escondido enquanto ela
           não tiver apostado nada — cinco zeros não informam ninguém. */ ?>
  <div class="eq-placar" id="placar" hidden></div>

  <div class="eq-fim-topo" id="topoAbertas" hidden>
    <h2>Eventos abertos</h2>
    <span class="eq-cont" id="contAbertas"></span>
    <input class="eq-busca" id="buscaAbertas" type="search" placeholder="Buscar por pergunta ou banca…"
           oninput="pintarAbertas()">
  </div>
  <div id="lista" class="eq-grade"><p class="eq-vazio">Carregando…</p></div>

  <?php /* O que eu banco fica aqui embaixo, e não dentro de cada mesa lá em
           cima: declarar e cancelar são coisa de uma pessoa só, e repetidos
           em toda mesa do dono poluíam a lista que é de todo mundo. */ ?>
  <div class="eq-fim" id="blocoMeus" hidden>
    <div class="eq-fim-topo">
      <h2>Meus eventos</h2>
      <span class="eq-cont" id="contMeus"></span>
    </div>
    <div id="listaMeus" class="eq-meus"></div>
  </div>

  <?php /* As encerradas (pagas ou canceladas) não competem com as abertas por
           atenção: descem pro fim e ganham uma busca, porque com o tempo elas
           passam a ser muitas e o que se quer ali é achar uma específica. */ ?>
  <div class="eq-fim" id="blocoFim" hidden>
    <div class="eq-fim-topo">
      <h2>Eventos encerrados</h2>
      <span class="eq-cont" id="contFim"></span>
      <input class="eq-busca" id="buscaFim" type="search" placeholder="Buscar por pergunta ou banca…"
             oninput="pintarEncerradas()">
    </div>
    <div id="listaFim" class="eq-linhas"></div>
  </div>
</div>

<!-- Criar -->
<div class="eq-modal" id="mCriar">
  <div class="eq-mbox">
    <h3>Novo evento</h3>
    <p class="eq-aj">Você vira a casa desta aposta. Escolha odds que consiga pagar:
      quanto maior a odd, mais fica retido do seu saldo.</p>

    <?php /* O aviso fica ANTES do campo, não depois: escrito embaixo, chega
             quando a pergunta já foi digitada e ninguém reescreve. */ ?>
    <div class="eq-aviso" style="margin:0 0 6px">
      <b>Pergunte sobre um acontecimento</b>, não sobre gosto. Você é quem
      declara o resultado — numa pergunta de opinião ("melhor filme", "melhor
      jogador de todos os tempos") não há como a liga conferir se a resposta
      foi honesta.
    </div>

    <label for="cTitulo">Pergunta</label>
    <input id="cTitulo" maxlength="160" placeholder="Ex: O Vasco vai cair em 2026?">

    <label for="cDesc">Como o resultado vai ser conferido (opcional)</label>
    <input id="cDesc" maxlength="400" placeholder="Ex: pela tabela final do Brasileirão">

    <?php /* As opções vêm do motor (ENQ_CATEGORIAS), não escritas de novo
             aqui: duas listas separadas saem do lugar na primeira mudança. */ ?>
    <label for="cCat">Categoria</label>
    <select id="cCat">
      <?php foreach (ENQ_CATEGORIAS as $c): ?>
        <option value="<?= htmlspecialchars($c, ENT_QUOTES) ?>"><?= htmlspecialchars($c) ?></option>
      <?php endforeach; ?>
    </select>

    <label>Alternativas e odds</label>
    <div id="cAlts"></div>
    <button class="eq-btn" style="padding:7px 11px;font-size:12px" onclick="addAlt()">
      <i class="bi bi-plus"></i> mais uma
    </button>

    <div class="eq-duo" style="margin-top:6px">
      <div><label for="cPessoa">Máximo por pessoa</label><input id="cPessoa" type="number" value="200" min="10"></div>
      <div><label for="cTotal">Máximo total</label><input id="cTotal" type="number" value="1000" min="10"></div>
    </div>
    <?php
      /* DIA E HORA, não uma contagem de dias.
         "Aberta por 7 dias" nunca casa com o evento: o jogo tem hora marcada,
         e aceitar aposta em quem ganha depois de a bola subir é a brecha que
         a liga acha uma vez só.

         O valor inicial sai do relógio do SERVIDOR, não do navegador: assim
         "agora" é sempre o horário de Brasília, mesmo pra quem estiver com o
         computador em outro fuso. Nasce preenchido com o instante atual, pra
         quem for marcar uma hora de hoje só precisar mexer na hora. */
      $tzBR = new DateTimeZone('America/Sao_Paulo');
      $agora = new DateTime('now', $tzBR);
      $limite = (clone $agora)->modify('+' . ENQ_DIAS_MAX . ' days');
    ?>
    <label for="cQuando">Fecha em (horário de Brasília)</label>
    <input id="cQuando" type="datetime-local"
           value="<?= $agora->format('Y-m-d\TH:i') ?>"
           min="<?= $agora->format('Y-m-d\TH:i') ?>"
           max="<?= $limite->format('Y-m-d\TH:i') ?>">
    <small class="eq-dica">Depois desta hora ninguém mais aposta.
      No máximo <?= ENQ_DIAS_MAX ?> dias à frente.</small>

    <div class="eq-aviso" id="cAviso"></div>
    <div class="eq-mfoot">
      <button class="eq-btn" onclick="fechar('mCriar')">Cancelar</button>
      <button class="eq-btn eq-pri" id="cEnviar" onclick="criar()">Criar</button>
    </div>
  </div>
</div>

<script>
const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
const n = v => Number(v || 0).toLocaleString('pt-BR');
let DADOS = null, alvo = null, ABERTAS = [], ENCERRADAS = [];

async function api(acao, corpo, metodo) {
  const r = await fetch('/api/enquetes.php?acao=' + acao, {
    method: metodo || (corpo ? 'POST' : 'GET'),
    headers: {'Content-Type': 'application/json'},
    body: corpo ? JSON.stringify(corpo) : undefined,
  });
  return await r.json();
}

function fechar(id){ document.getElementById(id).classList.remove('eq-on'); }

async function carregar() {
  const d = await api('listar');
  if (!d.ok) { document.getElementById('lista').innerHTML = '<p class="eq-vazio">Não deu pra carregar.</p>'; return; }
  DADOS = d;

  // Criar é de todo mundo: a retenção impede abrir um evento que o saldo não
  // cobre, e a organização confere depois pelo painel de Eventos.
  const btn = document.getElementById('btnCriar');
  if (btn) btn.hidden = false;

  /* SÓ O RETIDO.
     "Suas moedas" e "livres pra bancar" repetiam o contador de moedas que já
     está no topo da página — três números iguais na mesma tela, e dois deles
     redundantes. O retido fica porque é o único que o topo não mostra: é
     moeda que existe no saldo mas não dá pra gastar enquanto o evento corre. */
  const cx = document.getElementById('saldos');
  if (cx) {
    cx.innerHTML = d.retido
      ? `<div class="eq-saldo"><b style="color:var(--amber)">${n(d.retido)}</b>
           <span>retido nos seus eventos</span></div>`
      : '';
    cx.hidden = !d.retido;
  }

  pintarPlacar(d);

  // Em cima o que ainda aceita aposta; embaixo, o histórico.
  const lista = d.enquetes || [];
  ABERTAS    = lista.filter(e => e.status === 'aberta');
  ENCERRADAS = lista.filter(e => e.status !== 'aberta');
  pintarAbertas();
  pintarMeus();
  pintarEncerradas();
  irParaOEventoDoLink();
}

/**
 * Quem chegou pelo link do grupo (#enq-12) cai no evento, não no topo da aba.
 *
 * O aviso do WhatsApp é sobre UM evento; abrir a lista inteira e deixar a
 * pessoa procurar o card no meio de trinta é perder o motivo do link. Só roda
 * depois de pintar, porque antes disso o elemento não existe.
 */
function irParaOEventoDoLink() {
  const m = (location.hash || '').match(/^#enq-(\d+)$/);
  if (!m) return;
  const alvo = document.getElementById('enq-' + m[1]);
  // Evento já encerrado ou cancelado some da lista: sem card, fica no topo
  // mesmo, que é melhor que rolar pro vazio.
  if (!alvo) return;
  alvo.scrollIntoView({ behavior: 'smooth', block: 'center' });
  alvo.classList.add('eq-alvo');
  setTimeout(() => alvo.classList.remove('eq-alvo'), 2600);
}

/**
 * O placar de eventos: ganho, saldo, acertos, erros e aproveitamento.
 *
 * Só aparece pra quem já apostou. Uma fileira de zeros na primeira visita não
 * conta nada e ainda empurra a lista de eventos pra baixo, que é o que a
 * pessoa veio ver.
 */
function pintarPlacar(d) {
  const cx = document.getElementById('placar');
  if (!cx) return;
  const p = d.placar || {};
  // Ganhos também contam: quem só BANCA não tem acerto nem erro (ele não
  // aposta), e mesmo assim é quem mais tem resultado pra ver.
  const jogou = (p.acertos || 0) + (p.erros || 0) + (p.abertos || 0) > 0
             || !!p.ganhos || !!p.apostado || !!d.retido;
  cx.hidden = !jogou;
  if (!jogou) { cx.innerHTML = ''; return; }

  const plural = (n, um, muitos) => (n === 1 ? um : muitos);
  // O ganho é o único que pode ser negativo, e o sinal é a informação: sem o
  // "+" na frente, ganhar 200 e ter 200 parados na conta viram o mesmo número.
  const g = p.ganhos || 0;
  const cor = g > 0 ? 'eq-pc-verde' : (g < 0 ? 'eq-pc-vermelho' : '');
  const sinal = g > 0 ? '+' : '';

  cx.innerHTML = `
    <div class="eq-pc ${cor}">
      <b>${sinal}${n(g)}</b>
      <span>ganhos com eventos${p.apostado ? `<i>${n(p.apostado)} apostados</i>` : ''}</span>
    </div>
    <div class="eq-pc">
      <b>${n(d.saldo || 0)}</b>
      <span>saldo${d.retido ? `<i>${n(d.retido)} retido</i>` : ''}</span>
    </div>
    <div class="eq-pc eq-pc-verde">
      <b>${p.acertos || 0}</b>
      <span>${plural(p.acertos || 0, 'acerto', 'acertos')}</span>
    </div>
    <div class="eq-pc eq-pc-vermelho">
      <b>${p.erros || 0}</b>
      <span>${plural(p.erros || 0, 'erro', 'erros')}</span>
    </div>
    <div class="eq-pc">
      <b>${p.aproveitamento || 0}%</b>
      <!-- Sobre os já decididos: contar o que ainda não saiu como erro
           derrubaria o número de quem acabou de apostar. -->
      <span>aproveitamento${p.abertos ? `<i>${p.abertos} em aberto</i>` : '<i>dos já decididos</i>'}</span>
    </div>`;
}

/** O que a pessoa digitou, contra pergunta, detalhe, categoria e quem banca. */
const combina = (e, q) =>
  !q || `${e.titulo} ${e.descricao || ''} ${e.categoria || ''} ${e.criador}`
        .toLowerCase().includes(q);

function pintarAbertas() {
  // A busca só aparece quando há o bastante pra procurar dentro.
  const topo = document.getElementById('topoAbertas');
  if (topo) topo.hidden = ABERTAS.length < 2;

  const q = (document.getElementById('buscaAbertas')?.value || '').trim().toLowerCase();
  const achadas = ABERTAS.filter(e => combina(e, q));
  const cont = document.getElementById('contAbertas');
  if (cont) cont.textContent = q ? `${achadas.length} de ${ABERTAS.length}` : `${ABERTAS.length} no total`;

  document.getElementById('lista').innerHTML = achadas.length
    ? porCategoria(achadas)
    : `<p class="eq-vazio">${
        q ? 'Nenhum evento aberto com esse termo.'
          : (ENCERRADAS.length ? 'Nenhum evento aberto agora.' : 'Nenhum evento ainda.')}</p>`;
}

/**
 * As apostas agrupadas, cada grupo com sua faixa de título.
 *
 * Os grupos saem na ordem em que a lista já vem — a aposta mais recente
 * primeiro — e não em ordem alfabética: o assunto que acabou de ganhar
 * aposta nova sobe, que é o que a pessoa veio ver. "Outras" é sempre a
 * última: é o balde do que ninguém classificou, não um assunto.
 */
function porCategoria(lista) {
  // "Outros" com S no fim é o nome que o motor grava (ENQ_CATEGORIAS); as
  // apostas criadas antes da categoria existir não têm nenhuma e caem aqui.
  const BALDE = 'Outros';
  const grupos = new Map();
  for (const e of lista) {
    const cat = (e.categoria || '').trim() || BALDE;
    if (!grupos.has(cat)) grupos.set(cat, []);
    grupos.get(cat).push(e);
  }
  const nomes = [...grupos.keys()].filter(c => c !== BALDE);
  if (grupos.has(BALDE)) nomes.push(BALDE);

  return nomes.map(cat => `
    <section class="eq-grupo">
      <header class="eq-grupo-h">
        <span>${esc(cat)}</span>
        <span class="eq-grupo-n">${grupos.get(cat).length}</span>
      </header>
      ${grupos.get(cat).map(mesa).join('')}
    </section>`).join('');
}

/**
 * Uma aposta em uma linha: a pergunta à esquerda, as odds em colunas.
 *
 * É o formato de casa de aposta, e ele cabe aqui porque toda aposta tem de 2
 * a 4 alternativas — o teto de 4 é o que permite as colunas terem largura
 * fixa. Clicar numa odd abre o campo de valor embaixo da própria linha.
 */
/**
 * O máximo que EU posso apostar neste evento, agora.
 *
 * São três tetos ao mesmo tempo, e vale o menor: o que sobra do meu limite
 * por pessoa, o que sobra do total do evento e o que eu tenho livre no
 * bolso. Antes o campo abria com 50 fixo — um número que não era o mínimo
 * nem o máximo, e que todo mundo apagava pra digitar outro.
 */
function tetoDaAposta(e) {
  const porPessoa = (e.max_pessoa || 0) - (e.meu_total || 0);
  const doEvento  = (e.max_total  || 0) - (e.apostado  || 0);
  const noBolso   = DADOS?.livre ?? 0;
  return Math.max(0, Math.min(porPessoa, doEvento, noBolso));
}

function mesa(e) {
  const total = e.apostado || 1;
  const podeApostar = !e.sou_dono;

  const colunas = e.alternativas.map(a => `
    <button class="eq-col ${a.meu ? 'eq-tem' : ''}" data-alvo="${e.id}_${a.id}"
            ${podeApostar ? `onclick="abrirAposta(${e.id},${a.id})"` : 'disabled'}
            title="${esc(a.texto)}">
      <span class="eq-col-t">${esc(a.texto)}</span>
      <span class="eq-col-o">${Number(a.odd).toFixed(2)}</span>
      ${a.meu ? `<span class="eq-col-m">você: ${n(a.meu)}</span>` : ''}
      <span class="eq-col-barra" style="width:${Math.round((a.apostado / total) * 100)}%"></span>
    </button>`).join('');

  // Os campos de valor ficam FORA da grade de colunas: dentro, abrir um
  // empurraria as odds vizinhas pro lado no meio da leitura.
  const caixas = e.alternativas.map(a => `
    <div class="eq-box" id="box_${e.id}_${a.id}" hidden>
      <div class="eq-box-alvo">${esc(a.texto)} · odd ${Number(a.odd).toFixed(2)}</div>
      <div class="eq-box-linha">
        <input type="number" id="val_${e.id}_${a.id}" min="${DADOS.limites.aposta_min}"
               max="${tetoDaAposta(e)}" value="${tetoDaAposta(e)}"
               oninput="previa(${e.id},${a.id})">
        <button class="eq-btn eq-pri" onclick="apostar(${e.id},${a.id})">Apostar</button>
        <button class="eq-btn" onclick="fecharBox(${e.id},${a.id})">Cancelar</button>
      </div>
      <div class="eq-box-previa" id="prev_${e.id}_${a.id}"></div>
    </div>`).join('');

  return `
  <div class="eq-mesa ${e.sou_dono ? 'eq-minha' : ''}" id="enq-${e.id}" data-alts="${e.alternativas.length}">
    <div class="eq-mesa-linha">
      <div class="eq-mesa-t">
        <b>${esc(e.titulo)}</b>
        <small>${esc(e.criador)}${e.sou_dono ? ' (você)' : ''} · ${n(e.apostado)} de ${n(e.max_total)}
          ${e.meu_total ? ` · você apostou ${n(e.meu_total)}` : ''}</small>
        ${e.descricao ? `<small>${esc(e.descricao)}</small>` : ''}
      </div>
      <div class="eq-cols">${colunas}</div>
    </div>
    ${caixas}
  </div>`;
}

/**
 * "Meus eventos": o que EU banco, com o que só eu posso fazer.
 *
 * Declarar e cancelar moravam dentro de cada mesa lá em cima, e com isso a
 * lista de apostas — que é de todo mundo — carregava um bloco de controles
 * que só o dono usa, em toda mesa dele. Aqui embaixo eles ficam juntos, e a
 * listagem volta a ser só odds.
 */
function pintarMeus() {
  const bloco = document.getElementById('blocoMeus');
  if (!bloco) return;
  const meus = ABERTAS.filter(e => e.sou_dono);
  bloco.hidden = !meus.length;
  if (!meus.length) return;

  document.getElementById('contMeus').textContent =
    `${meus.length} aberta${meus.length > 1 ? 's' : ''}`;

  document.getElementById('listaMeus').innerHTML = meus.map(e => `
    <div class="eq-meu">
      <div class="eq-meu-t">
        <b>${esc(e.titulo)}</b>
        <small>${esc(e.categoria || 'Outros')} · ${n(e.apostado)} de ${n(e.max_total)} apostados${
          e.retido ? ` · <b class="eq-retido">${n(e.retido)} retido do seu saldo</b>` : ''}</small>
      </div>
      <div class="eq-meu-acoes">
        <select id="res_${e.id}">
          <option value="">Declarar o resultado…</option>
          ${e.alternativas.map(a => `<option value="${a.id}">${esc(a.texto)} · ${
            n(a.apostado)} apostado</option>`).join('')}
        </select>
        <button class="eq-btn" onclick="fecharEnquete(${e.id})">Pagar</button>
        <button class="eq-btn eq-mal" onclick="cancelar(${e.id})">Cancelar e devolver</button>
      </div>
    </div>`).join('');
}

/** O histórico do fim da página, filtrado pelo que a pessoa digitou. */
function pintarEncerradas() {
  const bloco = document.getElementById('blocoFim');
  if (!bloco) return;
  bloco.hidden = !ENCERRADAS.length;
  if (!ENCERRADAS.length) return;

  const q = (document.getElementById('buscaFim')?.value || '').trim().toLowerCase();
  const achadas = ENCERRADAS.filter(e => combina(e, q));

  document.getElementById('contFim').textContent =
    q ? `${achadas.length} de ${ENCERRADAS.length}` : `${ENCERRADAS.length} no total`;
  document.getElementById('listaFim').innerHTML = achadas.length
    ? achadas.map(linha).join('')
    : '<p class="eq-vazio">Nenhum evento encerrado com esse termo.</p>';
}

/** Uma encerrada em uma linha só; o card inteiro fica atrás da clicada. */
function linha(e) {
  const venceu = e.alternativas.find(a => a.id === e.vencedora);
  const meu = e.meu_total
    ? (e.vencedora && e.alternativas.some(a => a.id === e.vencedora && a.meu) ? 'você acertou' : 'você apostou')
    : '';
  return `
  <button class="eq-li" onclick="alternarLinha(${e.id})" aria-expanded="false" id="li_${e.id}">
    <span class="eq-li-t">${esc(e.titulo)}
      <small>${e.categoria ? `${esc(e.categoria)} · ` : ''}${esc(e.criador)}${
        venceu ? ` · deu ${esc(venceu.texto)}` : ' · cancelada'}${meu ? ` · ${meu}` : ''}</small>
    </span>
    <span class="eq-li-v">${n(e.apostado)} apostado</span>
    <span class="eq-selo eq-${e.status}">${e.status}</span>
    <i class="bi bi-chevron-down eq-li-seta"></i>
  </button>
  <div class="eq-li-aberto" id="det_${e.id}" hidden>${card(e)}</div>`;
}

function alternarLinha(id) {
  const det = document.getElementById(`det_${id}`);
  const bt  = document.getElementById(`li_${id}`);
  if (!det) return;
  det.hidden = !det.hidden;
  bt?.setAttribute('aria-expanded', String(!det.hidden));
  const seta = bt?.querySelector('.eq-li-seta');
  if (seta) seta.className = `bi bi-chevron-${det.hidden ? 'down' : 'up'} eq-li-seta`;
}

function card(e) {
  const aberta = e.status === 'aberta';
  const total = e.apostado || 1;
  return `
  <div class="eq-card ${e.sou_dono ? 'eq-minha' : ''} ${e.status === 'paga' ? 'eq-paga' : ''}">
    <div class="eq-ch">
      <h2>${esc(e.titulo)}</h2>
      <span class="eq-selo eq-${e.status}">${e.status === 'aberta' ? 'aberta' : e.status === 'paga' ? 'paga' : e.status}</span>
    </div>
    <div class="eq-sub">
      banca: <b>${esc(e.criador)}${e.sou_dono ? ' (você)' : ''}</b> ·
      <b>${n(e.apostado)}</b> de ${n(e.max_total)} apostados ·
      até <b>${n(e.max_pessoa)}</b> por pessoa
      ${e.sou_dono && e.retido ? ` · <b style="color:var(--amber)">${n(e.retido)} retido do seu saldo</b>` : ''}
      ${e.meu_total ? ` · você apostou <b>${n(e.meu_total)}</b>` : ''}
      ${e.descricao ? `<br>${esc(e.descricao)}` : ''}
    </div>
    <div class="eq-alts">
      ${e.alternativas.map(a => `
        <button class="eq-alt ${e.vencedora === a.id ? 'eq-ganhou' : ''} ${a.meu ? 'eq-tem' : ''}"
                ${aberta && !e.sou_dono ? `onclick="abrirAposta(${e.id},${a.id})"` : 'disabled'}>
          <span class="eq-barra" style="width:${Math.round((a.apostado / total) * 100)}%"></span>
          <span class="eq-alt-txt">${esc(a.texto)}
            <span class="eq-alt-min">${n(a.apostado)} apostado${a.meu ? ` · você: ${n(a.meu)}` : ''}${e.vencedora === a.id ? ' · venceu' : ''}</span>
          </span>
          <span class="eq-odd">${Number(a.odd).toFixed(2)}<small>odd</small></span>
        </button>
        <?php /* O formulário nasce FECHADO embaixo de cada alternativa e abre
                 no clique: apostar é escolher a opção e dizer quanto, e um
                 modal por cima tirava da vista justamente a odd que a pessoa
                 está avaliando. */ ?>
        <div class="eq-box" id="box_${e.id}_${a.id}" hidden>
          <div class="eq-box-linha">
            <input type="number" id="val_${e.id}_${a.id}" min="${DADOS.limites.aposta_min}"
                   max="${tetoDaAposta(e)}" value="${tetoDaAposta(e)}"
                   oninput="previa(${e.id},${a.id})">
            <button class="eq-btn eq-pri" onclick="apostar(${e.id},${a.id})">Apostar</button>
            <button class="eq-btn" onclick="fecharBox(${e.id},${a.id})">Cancelar</button>
          </div>
          <div class="eq-box-previa" id="prev_${e.id}_${a.id}"></div>
        </div>`).join('')}
    </div>
    ${aberta && e.sou_dono ? `
      <div class="eq-nota-dono">
        Você banca esta aposta — quem aposta são os outros. O resultado você declara aqui embaixo.
      </div>` : ''}
    <?php /* SÓ O DONO declara e cancela — nem o admin geral.
             Quem banca é quem responde pelo resultado com o próprio saldo;
             um terceiro declarando decide o destino de moeda alheia. O admin
             continua com o "reverter pagamento" logo abaixo, que é conserto
             de erro, não decisão sobre a aposta. */ ?>
    ${e.sou_dono && e.status === 'aberta' ? `
      <div class="eq-linha-dono">
        <select id="res_${e.id}" style="max-width:230px">
          <option value="">Declarar o resultado…</option>
          ${e.alternativas.map(a => `<option value="${a.id}">${esc(a.texto)}</option>`).join('')}
        </select>
        <button class="eq-btn" onclick="fecharEnquete(${e.id})">Pagar</button>
        <button class="eq-btn eq-mal" onclick="cancelar(${e.id})">Cancelar e devolver</button>
      </div>` : ''}
    ${DADOS.admin && e.status === 'paga' ? `
      <div class="eq-linha-dono">
        <button class="eq-btn eq-mal" onclick="cancelar(${e.id})">Reverter pagamento (admin)</button>
      </div>` : ''}
  </div>`;
}

/* ── Criar ─────────────────────────────────────────────────────────── */
function addAlt(texto, odd) {
  const box = document.getElementById('cAlts');
  if (box.children.length >= (DADOS?.limites?.alt_max || 4)) return;
  const d = document.createElement('div');
  d.className = 'eq-alt-linha';
  d.innerHTML = `<input class="cAltTxt" maxlength="120" placeholder="Alternativa" value="${esc(texto || '')}">
                 <input class="cAltOdd" type="number" step="0.05" min="1.1" max="10"
                        value="${odd || '2.00'}" oninput="previaCriar()">`;
  box.appendChild(d);
  previaCriar();
}

function abrirCriar() {
  document.getElementById('cAlts').innerHTML = '';
  addAlt('', '2.00'); addAlt('', '2.00');
  document.getElementById('cTitulo').value = '';
  document.getElementById('cDesc').value = '';
  previaCriar();
  document.getElementById('mCriar').classList.add('eq-on');
}

/**
 * A conta que o criador precisa ver ANTES de criar: quanto pode ficar preso.
 * É o mesmo pior caso que o servidor calcula — mostrar aqui evita criar uma
 * enquete que vai ser recusada.
 */
function previaCriar() {
  const odds = [...document.querySelectorAll('.cAltOdd')].map(i => Number(i.value) || 0);
  const maiorOdd = Math.max(0, ...odds);
  const total = Number(document.getElementById('cTotal').value) || 0;
  const pior = Math.ceil(total * (maiorOdd - 1));
  const livre = DADOS?.livre ?? 0;
  const cabe = pior <= livre;
  document.getElementById('cAviso').innerHTML =
    `No pior caso — todo o dinheiro na alternativa de odd ${maiorOdd.toFixed(2)} —
     você paga <b>${n(pior)}</b> moedas. Você tem <b>${n(livre)}</b> livres.
     ${cabe ? '' : '<br><b style="color:var(--vermelho)">Não cabe: baixe a odd, o total, ou espere liberar retenção.</b>'}
     <br><span style="color:var(--text3)">A casa fica com ${DADOS?.limites?.taxa ?? 5}% do seu lucro — e nada se você perder.</span>`;
  document.getElementById('cEnviar').disabled = !cabe;
}
['cTotal','cPessoa'].forEach(id => document.addEventListener('input', e => {
  if (e.target && e.target.id === id) previaCriar();
}));

async function criar() {
  const alts = [...document.querySelectorAll('.eq-alt-linha')].map(l => ({
    texto: l.querySelector('.cAltTxt').value.trim(),
    odd: Number(l.querySelector('.cAltOdd').value),
  })).filter(a => a.texto);
  const r = await api('criar', {
    // Só a data: os dias saíram da tela, e o motor cai no padrão se ela faltar.
    fecha_em: document.getElementById('cQuando').value || null,
    titulo: document.getElementById('cTitulo').value.trim(),
    descricao: document.getElementById('cDesc').value.trim(),
    categoria: document.getElementById('cCat').value.trim(),
    alternativas: alts,
    max_por_pessoa: Number(document.getElementById('cPessoa').value),
    max_total: Number(document.getElementById('cTotal').value),
  });
  if (!r.ok) { alert(r.erro); return; }
  fechar('mCriar');
  carregar();
}

/* ── Apostar ───────────────────────────────────────────────────────── */
/**
 * Abre o formulário embaixo da alternativa escolhida.
 *
 * Um por vez: com dois abertos, dá pra digitar num e confirmar no outro.
 */
/**
 * Clicar na odd abre o campo de valor; clicar de novo na mesma fecha.
 *
 * Sem o segundo clique, quem abriu por engano ficava com a caixa aberta até
 * achar o "Cancelar" — e o caminho de volta tem que ser o mesmo da ida.
 */
function abrirAposta(enqId, altId) {
  const box = document.getElementById(`box_${enqId}_${altId}`);
  if (!box) return;
  const jaEstavaAberta = !box.hidden;
  document.querySelectorAll('.eq-box').forEach(b => b.hidden = true);
  document.querySelectorAll('.eq-col.eq-on').forEach(c => c.classList.remove('eq-on'));
  if (jaEstavaAberta) return;

  box.hidden = false;
  document.querySelector(`.eq-col[data-alvo="${enqId}_${altId}"]`)?.classList.add('eq-on');
  previa(enqId, altId);
  box.querySelector('input')?.focus();
}

function fecharBox(enqId, altId) {
  const box = document.getElementById(`box_${enqId}_${altId}`);
  if (box) box.hidden = true;
  document.querySelector(`.eq-col[data-alvo="${enqId}_${altId}"]`)?.classList.remove('eq-on');
}

/** O que a pessoa recebe se acertar — e o motivo, quando não dá pra apostar. */
function previa(enqId, altId) {
  const e = DADOS.enquetes.find(x => x.id === enqId);
  const a = e?.alternativas.find(x => x.id === altId);
  const campo = document.getElementById(`val_${enqId}_${altId}`);
  const alvo  = document.getElementById(`prev_${enqId}_${altId}`);
  if (!e || !a || !campo || !alvo) return;

  /* O CAMPO NÃO DEIXA PASSAR DO TETO.
     O atributo `max` do input só vale no submit de um <form>, e aqui não há
     form nenhum — dava pra digitar 5.000 num evento de 900 e só descobrir no
     erro. Agora o número volta pro teto na hora, e a mensagem explica qual
     dos três limites pegou. */
  const teto = tetoDaAposta(e);
  const min  = DADOS.limites.aposta_min;
  let v = Number(campo.value) || 0;
  if (v > teto) { v = teto; campo.value = String(teto); }

  const retorno = Math.round(v * a.odd);
  const porPessoa = (e.max_pessoa || 0) - (e.meu_total || 0);
  const doEvento  = (e.max_total  || 0) - (e.apostado  || 0);
  // O motivo em vez de um botão apagado sem explicação.
  const problema = teto < min
      ? (porPessoa < min ? `Você já apostou o limite deste evento (${n(e.max_pessoa)}).`
      : doEvento  < min ? 'Este evento já bateu o teto total.'
      : `Você tem ${n(DADOS.livre)} moedas livres.`)
    : v < min ? `A aposta mínima é ${n(min)}.`
    : null;

  /* O "Máx" ao lado do lucro.
     Sem ele, um evento onde o criador pôs 10 por pessoa abre o campo com 10 e
     parece que a tela travou no mínimo — os dois números são o mesmo, e nada
     na tela dizia qual dos dois. Diz também DE ONDE veio o limite, que é a
     pergunta seguinte: do evento, do meu bolso ou do quanto eu já apostei. */
  const porQue = teto === porPessoa ? (e.meu_total ? 'o que sobra do seu limite aqui' : 'o limite por pessoa deste evento')
               : teto === doEvento  ? 'o que falta pro evento lotar'
               : 'as suas moedas livres';

  alvo.innerHTML = problema
    ? `<b style="color:var(--vermelho)">${problema}</b>`
    : `Na odd <b>${Number(a.odd).toFixed(2)}</b>, acertando você recebe <b>${n(retorno)}</b> —
       lucro de <b>${n(retorno - v)}</b>. · Máx <b>${n(teto)}</b>
       <span style="color:var(--text3)">(${porQue})</span>
       <br><span style="color:var(--text3)">A odd trava agora: é a que você recebe, mesmo que ela mude depois.</span>`;
  const bt = alvo.parentElement.querySelector('.eq-pri');
  if (bt) bt.disabled = !!problema;
}

async function apostar(enqId, altId) {
  const campo = document.getElementById(`val_${enqId}_${altId}`);
  const r = await api('apostar', {
    enquete_id: enqId, alternativa_id: altId, valor: Number(campo?.value || 0),
  });
  if (!r.ok) { alert(r.erro); return; }
  carregar();
}

/* ── Fechar / cancelar ─────────────────────────────────────────────── */
async function fecharEnquete(id) {
  const sel = document.getElementById('res_' + id);
  const alt = Number(sel.value);
  if (!alt) { alert('Escolha qual alternativa venceu.'); return; }
  if (!confirm(`Declarar "${sel.options[sel.selectedIndex].text}" como resultado?\n\nOs pagamentos saem na hora.`)) return;
  const r = await api('fechar', {enquete_id: id, alternativa_id: alt});
  if (!r.ok) { alert(r.erro); return; }
  alert(`Pago. Ganhadores receberam ${n(r.pago_aos_ganhadores)}.\n`
      + `Seu resultado: ${r.lucro_do_criador >= 0 ? '+' : ''}${n(r.lucro_do_criador)} moedas`
      + (r.taxa ? ` (taxa da casa: ${n(r.taxa)})` : ''));
  carregar();
}

async function cancelar(id) {
  if (!confirm('Cancelar e devolver todas as apostas?')) return;
  const r = await api('cancelar', {enquete_id: id});
  if (!r.ok) { alert(r.erro); return; }
  carregar();
}

carregar();
</script>
