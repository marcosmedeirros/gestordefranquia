<?php
/**
 * FBA GEO — o GeoGuessr da liga.
 *
 * Aparece a foto de um lugar do mundo e você diz onde é: o país, na múltipla
 * escolha, ou o ponto no mapa. Dois modos, dois rankings (@see games/core/geo.php).
 *
 * ── A CASCA É DO SITE, O RESTO É DO JOGO ─────────────────────────────
 *
 * Pedido do Marcos (14/10/2026): "a única coisa que deve ter padrão é o menu
 * lateral com padrão do site, mas o resto pode ficar à vontade pra
 * estilizar". Então esta página entra pelo shell do app — sidebar, topbar e
 * a cor que a pessoa escolheu — e daí pra dentro tem identidade própria:
 * foto em tela cheia, chips grandes, o mapa com a linha do palpite até o
 * lugar certo. Um jogo de adivinhar paisagem não pode parecer um relatório.
 *
 * ── SÓ POR LINK, POR ENQUANTO ────────────────────────────────────────
 *
 * Não existe card em games.php: quem tem o endereço entra, o resto da liga
 * não o descobre passeando pela lista. Pra publicar, é só acrescentar o card.
 *
 * ── A PARTIDA É DO SERVIDOR ──────────────────────────────────────────
 *
 * Cada rodada nasce no banco com a resposta guardada, e o palpite é conferido
 * contra ela. A tela não sabe a resposta antes de responder.
 */

/* O conexao.php é quem faz o login do games valer aqui e quem garante a linha
   em games_usuarios — sem ela não há onde creditar moeda. O resto é o que o
   menu lateral do site pede. */
require_once __DIR__ . '/../core/conexao.php';
require_once __DIR__ . '/../../backend/observador.php';
require_once __DIR__ . '/../core/geo.php';
require_once __DIR__ . '/../core/geo_desafios.php';

$user = getUserSession();
$user_id = (int)$user['id'];
/* O cartão do time no menu. Vem por timeDaTela pra respeitar o modo
   observador, igual às outras telas. */
$team = timeDaTela($pdo, $user_id);

geoTabelas($pdo);
function e($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* ── O MODO TESTE ────────────────────────────────────────────────────
   Pedido do Marcos (16/10/2026): "coloca o geo, sem pagar nada, pra eu
   testar" — e o teste é pra ele MANDAR O LINK pra alguém experimentar.

   Quem abre /games/games/geo.php?teste=1 joga a mesma partida de
   sempre — mesmo sorteio, mesmo relógio, mesma conferência, os desafios
   aparecem na tela — e nada disso mexe em dinheiro: não paga moeda, não
   marca missão nem conquista, e fica fora do ranking e do recorde. Rodar
   o jogo de verdade é o ponto: um teste por um caminho diferente não
   testa o jogo, testa o teste.

   NÃO TEM TRAVA, e é de propósito. O modo só TIRA prêmio de quem usa,
   então não há o que ganhar entrando por ele — e qualquer senha ou lista
   de convidados só atrapalharia justamente o que ele serve pra fazer,
   que é mandar o endereço pra alguém e pedir pra jogar.

   O FLAG GRUDA NA PARTIDA, não na URL: cada ação aqui termina em
   redirect, e um `?teste=1` carregado de página em página se perderia no
   primeiro F5 — aí a pessoa terminaria valendo moeda uma partida que
   começou valendo nada. Quem manda, depois de aberta, é a coluna.
   @see geoAbrirPartida */
$geoTesteNoLink = isset($_GET['teste']) && $_GET['teste'] !== '0';

$msg = $_GET['m'] ?? null;
$erro = null;
$resolvida = null;          // a rodada que acabou de ser respondida

/* ── AS AÇÕES ────────────────────────────────────────────────────────
   Tudo é POST e termina em redirect, menos o palpite — ele precisa mostrar
   na mesma tela o que era o lugar, e um redirect jogaria isso fora. */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = (string)($_POST['acao'] ?? '');

    if ($acao === 'comecar') {
        geoAbrirPartida($pdo, $user_id, (string)($_POST['modo'] ?? 'pais'),
                        !empty($_POST['teste']));
        /* O ?teste=1 volta na URL só pra tela continuar se anunciando; quem
           decide se paga é a coluna da partida. */
        header('Location: /games/games/geo.php' . (!empty($_POST['teste']) ? '?teste=1' : ''));
        exit;
    }

    if ($acao === 'proxima') {
        /* Redireciona em vez de só seguir: assim o F5 da rodada nova não
           repete o POST, e o relógio dela começa a contar da página que a
           pessoa está vendo. */
        $p = geoPartidaAberta($pdo, $user_id);
        if ($p) geoProxima($pdo, $p);
        header('Location: /games/games/geo.php');
        exit;
    }

    if ($acao === 'desistir') {
        /* ENCERRAR PAGA O QUE JÁ FOI FEITO. @see geoEncerrar */
        $p = geoPartidaAberta($pdo, $user_id);
        $eraTeste = $p && !empty($p["teste"]);
        $moedas = $p ? geoEncerrar($pdo, $p) : 0;
        $m = $moedas > 0
            ? "Partida encerrada. +{$moedas} moedas pelo que você já tinha feito."
            : ($eraTeste ? 'Partida de teste encerrada — nada foi pago.' : 'Partida encerrada.');
        /* O modo volta junto: sem isso, encerrar uma partida de teste devolve
           a pessoa pra capa normal e a próxima já valeria moeda. */
        header('Location: /games/games/geo.php?m=' . urlencode($m) . ($eraTeste ? '&teste=1' : ''));
        exit;
    }

    if ($acao === 'palpite') {
        $partida = geoPartidaAberta($pdo, $user_id);
        $rodada  = $partida ? geoRodadaAtual($pdo, (int)$partida['id']) : null;
        if (!$partida || !$rodada) {
            $erro = 'Essa rodada já foi respondida.';
        } else {
            $modoDaVez = (string)$partida['modo'];
            $resolvida = geoResponder($pdo, $partida, $rodada, [
                'pais' => (string)($_POST['pais'] ?? ''),
                'lat'  => (float)($_POST['lat'] ?? 0),
                'lon'  => (float)($_POST['lon'] ?? 0),
            ]);
            $resolvida['modo'] = $modoDaVez;
            $resolvida['palpite_lat'] = (float)($_POST['lat'] ?? 0);
            $resolvida['palpite_lon'] = (float)($_POST['lon'] ?? 0);
        }
    }
}

$partida   = geoPartidaAberta($pdo, $user_id);
/* O QUE MANDA É A PARTIDA, e o link só vale enquanto não há nenhuma: quem
   abriu valendo moeda não vira teste por trocar a URL, e quem abriu em
   teste continua em teste mesmo sem o ?teste=1 no endereço. */
$geoTeste  = $partida ? !empty($partida["teste"]) : $geoTesteNoLink;
/* O LINK ARMA A PRÓXIMA, mesmo com uma partida valendo em andamento.
   Sem isto, quem recebe o endereço e já estava no meio de uma partida
   normal via a página abrir como se nada tivesse sido pedido: o link não
   fazia nada e não dizia por quê. A de agora termina como começou — ela é
   de verdade e o acerto é dele —, e a seguinte nasce de teste. */
$geoArmaTeste = $geoTeste || $geoTesteNoLink;
$rodada    = $partida ? geoRodadaAtual($pdo, (int)$partida['id']) : null;

/* ── A RODADA QUE VENCEU SE FECHA SOZINHA ────────────────────────────
   Quando o relógio zera, o navegador só recarrega a página — quem decide é
   este trecho. Fazer assim cobre de graça o caso que um "avisa que acabou"
   no JavaScript nunca cobriria: fechar a aba no meio da rodada e voltar meia
   hora depois pra responder com calma. @see geoVencida */
if ($partida && $rodada && !$resolvida && geoVencida($rodada)) {
    $modoDaVez = (string)$partida['modo'];
    $resolvida = geoResponder($pdo, $partida, $rodada, []);
    $resolvida['modo'] = $modoDaVez;
    $partida = geoPartidaAberta($pdo, $user_id);
    $rodada  = $partida ? geoRodadaAtual($pdo, (int)$partida['id']) : null;
}

$recorde   = geoMeuRecorde($pdo, $user_id);
$restantes = geoPremiadasRestantes($pdo, $user_id);
/* OS NÚMEROS DA CAPA SÃO OS DO BARALHO QUE SORTEIA, e não os do arquivo.
   Com o jogo só de Street View, anunciar 779 lugares e 138 países seria
   prometer um mundo que não vem — o que cai na tela são os 552 lugares em
   78 países que têm panorama. @see geoLugaresJogaveis */
$nLugares  = count(geoLugaresJogaveis());
$nPaises   = count(array_unique(array_column(geoLugaresJogaveis(), 'pais')));
$moedas    = (int)($pdo->query('SELECT pontos FROM games_usuarios WHERE id = ' . $user_id)->fetchColumn() ?: 0);

/* O Leaflet entra onde é usado: na rodada do mapa, no resultado dela (que
   desenha a linha do palpite até o lugar) e agora também na rodada do modo
   país, que virou mapa-múndi clicável. */
$precisaMapa = ($partida && $rodada)
            || ($resolvida && ($resolvida['modo'] ?? '') === 'mapa');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<script>
/* O tema antes de pintar a tela, pra não piscar branco. E a marca "js", que é
   o que deixa o mapa clicável aparecer só onde ele funciona: sem script, a
   rodada cai nos seis botões do <noscript> e continua jogável. */
document.documentElement.dataset.theme = localStorage.getItem('fba-theme') || 'dark';
document.documentElement.classList.add('js');
</script>
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>FBA Geo</title>
<link rel="manifest" href="/manifest.json?v=3">
<meta name="theme-color" content="#fc0025">
<link rel="icon" type="image/png" href="/img/fba-logo.png?v=3">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&family=Inter:wght@400;600;700;800;900&family=Barlow+Condensed:wght@600;700&display=swap" rel="stylesheet">
<style>
/* Os tokens do app: é o que faz o menu lateral e a topbar aparecerem do
   jeito certo, e o que traz o tema claro de graça. */
:root{
  --red:#fc0025;
  --red-2:color-mix(in srgb, var(--red) 85%, white);
  --red-soft:color-mix(in srgb, var(--red) 10%, transparent);
  --red-glow:color-mix(in srgb, var(--red) 18%, transparent);
  --bg:#07070a; --panel:#101013; --panel-2:#16161a; --panel-3:#1c1c21;
  --border:rgba(255,255,255,.06); --border-md:rgba(255,255,255,.10);
  --border-red:color-mix(in srgb, var(--red) 22%, transparent);
  --text:#f0f0f3; --text-2:#868690; --text-3:#7d7d85;
  --green:#22c55e; --amber:#f59e0b; --blue:#3b82f6;
  --sidebar-w:260px;
  --font:'Montserrat',sans-serif;
  --radius:14px; --radius-sm:10px; --radius-xs:6px;
  --ease:cubic-bezier(.2,.8,.2,1); --t:200ms;
}
:root[data-theme="light"]{
  --bg:#f6f7fb; --panel:#fff; --panel-2:#f2f4f8; --panel-3:#e9edf4;
  --border:#e3e6ee; --border-md:#d7dbe6; --text:#12141a; --text-2:#5b6172; --text-3:#6b7080;
}
</style>
<?php include __DIR__ . '/../../includes/shell-css.php'; ?>
<?php /* AS BANDEIRAS ENTRAM SEMPRE (flag-icons, MIT). A folha é pequena e só
     baixa o desenho das que a página usar. Ela já esteve junto do Leaflet,
     dentro do "se precisa de mapa" — e aí a bandeira aparecia nas opções da
     rodada e sumia no resultado, que é outra tela e não tem mapa. */ ?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/flag-icons/7.5.0/css/flag-icons.min.css">
<?php if ($precisaMapa): ?>
  <?php /* Leaflet + tiles do OpenStreetMap: os dois de graça, e a atribuição
       fica no canto do mapa, como a licença pede. */ ?>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css">
  <script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js"></script>
<?php endif; ?>
<style>
/* ══════════════════════════════════════════════════════════════════
   O VISUAL DO JOGO. Daqui pra baixo nada é do app: é um jogo de olhar
   paisagem, então a foto manda e o resto se cala em volta dela.
   ══════════════════════════════════════════════════════════════════ */
/* ── A BARRA DE ROLAGEM RESERVADA ────────────────────────────────────
   Parece detalhe e era a causa da tela preta no Street View. A página
   começa curta, o mapa carrega, ela fica alta, a barra de rolagem aparece —
   e o palco perde uns pixels de largura. O panorama do Google mede o espaço
   uma vez, ao abrir, e não refaz a conta: a imagem ia pro lugar errado e
   sobrava preto. Reservando a barra desde o começo, nada muda de tamanho e
   o problema não chega a existir. */
html{scrollbar-gutter:stable}
.content{font-family:Inter,system-ui,sans-serif}
#geo{max-width:1080px;margin:0 auto;padding:0 0 48px;
  --c:var(--blue)}

/* ── A FOTO ──────────────────────────────────────────────────────────
   Ela é o enunciado da pergunta, então ocupa o quanto puder: largura
   inteira, 16/10 no desktop e mais alta no celular (foto deitada em tela
   em pé vira uma tarja). O gradiente existe pra o texto que fica por cima
   não depender da sorte do céu da foto. */
.geo-palco{position:relative;border-radius:22px;overflow:hidden;background:var(--panel-2);
  aspect-ratio:16/10;box-shadow:0 24px 60px rgba(0,0,0,.45)}
.geo-palco img{width:100%;height:100%;object-fit:cover;display:block;
  animation:geoEntra .5s var(--ease)}
.geo-palco iframe{width:100%;height:100%;border:0;display:block}
/* COM O PANORAMA, O PALCO É MAIS ALTO: dá pra andar lá dentro, e quem anda
   precisa de céu e chão na tela. E o HUD deixa de capturar o mouse — ele
   flutua por cima do Street View, e arrastar em cima dele tem que girar a
   vista, não esbarrar numa caixa invisível. */
/* COM PANORAMA O PALCO CRESCE. A foto é uma coisa só e cabe em qualquer
   altura; no Street View a pessoa ANDA, e andar em 184 pixels de altura é
   espiar por uma fresta. O mínimo em pixels existe porque proporção sozinha
   não garante nada: numa coluna estreita, 16/9 vira uma tarja. */
.geo-palco.rua{aspect-ratio:16/10;min-height:380px}
.geo-palco.rua .geo-hud,.geo-palco.rua .geo-pergunta{pointer-events:none}
.geo-palco.rua .geo-hud .caixa,.geo-palco.rua .geo-hud form{pointer-events:auto}
/* O degradê de baixo fica: é ele que faz a pergunta ser lida sobre
   qualquer cena. Só encolhe, pra não escurecer meia rua. */
.geo-palco.rua::after{height:30%}
/* A placa que cobre o cartão do Google no alto à esquerda. Fica ABAIXO do
   HUD (z-index 1 contra 2), então o que se vê é a chapa escura atrás do
   placar — e o que não se vê é o nome do lugar. */
.geo-palco .tapa{position:absolute;top:0;left:0;width:228px;height:100px;z-index:1;
  background:#0b0b0f;border-radius:0 0 18px 0}
@keyframes geoEntra{from{opacity:0;transform:scale(1.04)}}
.geo-palco .carregando{position:absolute;inset:0;display:grid;place-items:center;
  color:var(--text-3);font-size:13px;letter-spacing:.4px}
.geo-palco::after{content:"";position:absolute;inset:auto 0 0;height:45%;pointer-events:none;
  background:linear-gradient(to top,rgba(0,0,0,.82),transparent)}
.geo-hud{position:absolute;left:0;right:0;top:0;display:flex;align-items:flex-start;gap:12px;
  padding:16px 18px;z-index:2}
.geo-hud .caixa{display:flex;flex-direction:column;gap:1px;padding:9px 14px;border-radius:14px;
  background:rgba(8,8,12,.62);backdrop-filter:blur(10px);border:1px solid rgba(255,255,255,.1)}
.geo-hud .caixa b{font-family:'Barlow Condensed',Inter,sans-serif;font-size:27px;line-height:1;
  letter-spacing:.5px;color:#fff}
.geo-hud .caixa span{font-size:9.5px;text-transform:uppercase;letter-spacing:1px;color:#c9c9d2;
  white-space:nowrap}
.geo-hud form{margin-left:auto}
.geo-sair{white-space:nowrap;padding:9px 13px;border-radius:12px;border:1px solid rgba(255,255,255,.14);
  background:rgba(8,8,12,.62);backdrop-filter:blur(10px);color:#e7e7ee;font:inherit;font-size:12.5px;
  font-weight:700;cursor:pointer}
.geo-sair:hover{border-color:var(--red);color:#fff}
.geo-pergunta{position:absolute;left:18px;bottom:16px;z-index:2;font-family:'Barlow Condensed',Inter,sans-serif;
  font-size:30px;font-weight:700;letter-spacing:.6px;color:#fff;text-shadow:0 2px 20px rgba(0,0,0,.6)}
.geo-pergunta small{display:block;font-family:Inter,sans-serif;font-size:12.5px;font-weight:500;
  letter-spacing:0;color:#cfcfda;text-shadow:none;margin-top:2px}


/* ══════════════════════════════════════════════════════════════════
   O MODO JOGO — a foto grande e o mapa no canto dela
   ══════════════════════════════════════════════════════════════════
   O desenho antigo era o do site: um cartão com a foto e, embaixo, um
   bloco com o mapa. Pra responder era preciso rolar a tela, perder a
   foto de vista, clicar no mapa e rolar de volta. Num jogo que é
   comparar a paisagem com o mundo, os dois precisam estar à vista ao
   mesmo tempo — é por isso que o GeoGuessr põe o mapa por cima da
   imagem, e é o que esta parte faz.

   TUDO AQUI DEPENDE DO `html.js`. Sem script não há mapa clicável nem
   painel que cresce, e a página volta a ser a lista de botões em
   coluna, que é a versão que funciona sem JavaScript. */
/* ── DURANTE A RODADA, A PÁGINA É DA FOTO ────────────────────────────
   A coluna do site tem 1080px e o cabeçalho "Jogos da liga / FBA Geo /
   Olhe a foto e diga onde é no mundo" come outros 110 de altura. Os
   dois fazem sentido numa tela de conteúdo e nenhum numa rodada: o
   título repete o que a pessoa está fazendo, e a margem lateral só
   diminui a paisagem que ela precisa examinar. Enquanto a rodada está
   de pé, os dois saem — e voltam na tela de resultado e na capa, que
   são telas de leitura. */
html.js body.geo-jogando .dash-hero{display:none}
html.js body.geo-jogando #geo{max-width:1500px}
html.js .geo-jogo{position:relative}

/* A FOTO OCUPA A TELA. Altura em dvh e não aspect-ratio: o que importa
   é caber na janela junto com a barra de cima, e não manter proporção.
   O teto em pixels evita que num monitor 4K a foto vire um painel de
   rua a dois metros de distância. */
html.js .geo-jogo .geo-palco{aspect-ratio:auto;height:min(74dvh,720px);min-height:0}
html.js .geo-jogo .geo-palco.rua{aspect-ratio:auto;height:min(74dvh,720px)}

/* ── O PAINEL DO CANTO ──────────────────────────────────────────────
   Encolhido ele é um selo de mapa; com o mouse em cima vira a mesa de
   trabalho. A transição é de largura e altura porque mudar `transform`
   deixaria o Leaflet desenhando tiles em escala errada no meio do
   caminho. */
html.js .geo-painel{position:absolute;right:14px;bottom:14px;z-index:6;
  width:min(330px,44vw);display:flex;flex-direction:column;gap:8px;
  /* O PAINEL NÃO PASSA DA FOTO. Sem o teto ele crescia pra baixo com o
     mapa, o botão e os seis países, saía pela borda do palco e punha
     uma barra de rolagem no meio do jogo. */
  max-height:calc(100% - 28px);overflow:hidden;
  padding:8px;border-radius:18px;
  background:rgba(8,8,12,.72);backdrop-filter:blur(14px);
  border:1px solid rgba(255,255,255,.12);
  box-shadow:0 18px 50px rgba(0,0,0,.5);
  transition:width var(--t) var(--ease),box-shadow var(--t) var(--ease)}
html.js .geo-painel:hover,
html.js .geo-painel:focus-within,
html.js .geo-painel.aberto{width:min(560px,46vw);box-shadow:0 26px 70px rgba(0,0,0,.6)}

/* O mapa cresce junto. Altura também em dvh pra sobrar foto por cima
   dele em tela baixa — mapa que cobre a paisagem inteira não ajuda a
   adivinhar a paisagem. */
html.js .geo-painel .geo-mapa{height:min(26vh,200px);margin:0;border-radius:12px;
  border-color:rgba(255,255,255,.12);
  transition:height var(--t) var(--ease)}
html.js .geo-painel:hover .geo-mapa,
html.js .geo-painel:focus-within .geo-mapa,
html.js .geo-painel.aberto .geo-mapa{height:min(38vh,340px)}

/* A linha do botão: a dica some no painel pequeno (não cabe e não é
   lida) e volta quando ele abre. */
html.js .geo-painel .geo-mapa-l{margin:0;gap:8px}
html.js .geo-painel .geo-mapa-l p{display:none;font-size:12px;color:#c9c9d2}
html.js .geo-painel:hover .geo-mapa-l p,
html.js .geo-painel.aberto .geo-mapa-l p{display:block}
html.js .geo-painel .geo-confirmar{flex:1;justify-content:center}

/* AS OPÇÕES DE PAÍS VIRAM DUAS COLUNAS dentro do painel, e rolam se
   passarem da altura. Seis botões de largura inteira empurrariam o
   mapa pra fora da tela. */
/* A LISTA SÓ APARECE COM O PAINEL ABERTO. Fechado, o canto é o mapa e
   mais nada — seis botões ali embaixo empurravam o painel pra fora da
   foto. Quem prefere clicar no nome abre o painel; quem joga pelo mapa
   nunca precisa dela. */
html.js .geo-painel .geo-ops{display:none;grid-template-columns:1fr 1fr;gap:6px;margin:0;
  max-height:min(22vh,170px);overflow-y:auto;overflow-x:hidden}
html.js .geo-painel:hover .geo-ops,
html.js .geo-painel:focus-within .geo-ops,
html.js .geo-painel.aberto .geo-ops{display:grid}
html.js .geo-painel .geo-ops button{min-width:0;padding:8px 10px;font-size:12.5px;
  border-radius:11px;gap:8px;
  background:rgba(255,255,255,.06);border-color:rgba(255,255,255,.12);color:#eaeaf2}
html.js .geo-painel .geo-ops button:hover{background:rgba(255,255,255,.12)}
html.js .geo-painel .geo-ops button i{width:21px;height:21px;font-size:10.5px;
  background:rgba(255,255,255,.1);color:#c9c9d2}

/* A ABA. No computador ela é só o rótulo do canto; no celular é o que
   abre e fecha o painel, porque lá não existe "passar o mouse". */
html.js .geo-painel-aba{display:flex;align-items:center;gap:8px;width:100%;
  padding:7px 10px;border:0;border-radius:11px;background:transparent;
  color:#eaeaf2;font:inherit;font-size:12.5px;font-weight:700;cursor:pointer;
  text-align:left}
html.js .geo-painel-aba .seta{margin-left:auto;font-size:11px;color:#9a9aa8;
  transition:transform var(--t) var(--ease)}
html.js .geo-painel.aberto .geo-painel-aba .seta{transform:rotate(180deg)}
html.js .geo-painel-aba:hover{background:rgba(255,255,255,.07)}

/* ── NO CELULAR ─────────────────────────────────────────────────────
   O painel passa a ocupar a largura toda e nasce FECHADO: a tela é
   pequena e a foto é a pergunta, então ela fica inteira até a pessoa
   decidir responder. Fechado, só a aba aparece. */
@media (max-width:820px){
  html.js .geo-jogo .geo-palco,
  html.js .geo-jogo .geo-palco.rua{height:min(58dvh,520px)}
  html.js .geo-painel{left:10px;right:10px;bottom:10px;width:auto}
  html.js .geo-painel:hover{width:auto}
  html.js .geo-painel .geo-mapa,
  html.js .geo-painel .geo-mapa-l,
  html.js .geo-painel .geo-ops{display:none}
  html.js .geo-painel.aberto .geo-mapa{display:block;height:min(38dvh,340px)}
  html.js .geo-painel.aberto .geo-mapa-l{display:flex}
  html.js .geo-painel.aberto .geo-ops{display:grid;max-height:30dvh}
  html.js .geo-painel.aberto .geo-mapa-l p{display:none}
}
/* Quem pediu menos movimento não recebe painel que cresce sozinho: ele
   fica no tamanho aberto e pronto. */
@media (prefers-reduced-motion:reduce){
  html.js .geo-painel{transition:none}
  html.js .geo-painel .geo-mapa{transition:none}
}

/* ── A REVELAÇÃO DO RESULTADO ────────────────────────────────────────
   A mesma foto da pergunta, agora com a resposta escrita nela. Altura
   menor que a do palco de propósito: aqui ela não é mais o enunciado,
   é a lembrança — o que interessa agora é o nome do lugar e os números
   logo abaixo. */
.geo-revela{position:relative;border-radius:16px;overflow:hidden;margin:-4px 0 14px;
  height:min(34vh,260px);background:var(--panel-2)}
.geo-revela img{width:100%;height:100%;object-fit:cover;display:block}
.geo-revela::after{content:"";position:absolute;inset:auto 0 0;height:70%;
  background:linear-gradient(to top,rgba(0,0,0,.88),transparent);pointer-events:none}
.geo-revela .geo-veredito{position:absolute;left:16px;bottom:12px;margin:0;z-index:1;
  text-shadow:0 2px 18px rgba(0,0,0,.7)}
@media (max-width:820px){ .geo-revela{height:min(26vh,190px)} }

/* Só aparece onde o script roda. Sem ele, o <noscript> assume. */
html:not(.js) .so-js{display:none !important}

/* ── O RELÓGIO ───────────────────────────────────────────────────────
   Os dois últimos minutos de qualquer coisa são iguais; o que muda é o
   último meio minuto, e aí ele pulsa em vermelho pra ninguém dizer que
   não viu. tabular-nums porque dígito que dança incomoda mais que o
   tempo acabando. */
.geo-hud .relogio b{font-variant-numeric:tabular-nums}
.geo-hud .relogio.apertou{border-color:color-mix(in srgb,var(--red) 55%,transparent)}
.geo-hud .relogio.apertou b{color:var(--red-2);animation:geoPulsa 1s steps(2,end) infinite}
@keyframes geoPulsa{50%{opacity:.45}}

/* ── O MAPA-MÚNDI DAS OPÇÕES ─────────────────────────────────────────
   Sem tiles: o fundo é o próprio desenho dos países. Além de ficar mais
   limpo, tira os nomes escritos que vêm nos tiles — num jogo de adivinhar
   país, mapa legendado é cola. */
.leaflet-container{background:var(--panel-2)}
.geo-pino{display:grid;place-items:center;width:26px;height:26px;border-radius:50%;
  font:800 12px/1 Inter,sans-serif;color:#06202e;background:var(--c);
  border:2px solid rgba(0,0,0,.35);cursor:pointer}
.geo-pino.eu{background:var(--green);color:#052e16}

/* ── AS OPÇÕES DE PAÍS ───────────────────────────────────────────────
   Alvo grande, número à esquerda e o nome inteiro: no celular isto é
   apertado com o polegar, e nome cortado é palpite no escuro. */
.geo-ops{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,250px),1fr));gap:10px;
  margin-top:16px}
.geo-ops form{display:contents}
.geo-ops button{display:flex;align-items:center;gap:12px;width:100%;padding:16px 18px;
  border-radius:16px;border:1px solid var(--border);background:var(--panel);color:var(--text);
  font:inherit;font-size:15.5px;font-weight:700;cursor:pointer;text-align:left;
  transition:transform var(--t) var(--ease),border-color var(--t) var(--ease),background var(--t) var(--ease)}
.geo-ops button i{display:grid;place-items:center;width:26px;height:26px;border-radius:9px;flex:none;
  background:var(--panel-3);color:var(--text-3);font-size:12px;font-style:normal;font-weight:800}
.geo-ops button:hover{transform:translateY(-2px);border-color:var(--c);background:var(--panel-2)}
.geo-ops button:hover i{background:color-mix(in srgb,var(--c) 22%,transparent);color:var(--c)}
.geo-ops button:disabled{opacity:.45;transform:none;cursor:default}
/* O escolhido, na lista e no mapa, é o mesmo verde — é o que amarra os dois
   jeitos de responder numa escolha só. */
/* A BANDEIRA. Tamanho fixo e canto arredondado pra todas ficarem iguais — a
   folha do flag-icons entrega cada uma na proporção dela, e sem isto a lista
   vira uma escada. O nome fica com o resto do espaço e corta com reticências
   em vez de empurrar o botão. */
.geo-ops button .fi{flex:none;width:26px;height:19.5px;border-radius:3px;
  box-shadow:0 0 0 1px rgba(0,0,0,.35) inset;background-size:cover}
.geo-ops button .nm{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.leaflet-tooltip .fi{width:18px;height:13.5px;border-radius:2px;vertical-align:-2px}
.geo-ops button.eu{border-color:var(--green);background:color-mix(in srgb,var(--green) 14%,var(--panel))}
.geo-ops button.eu i{background:var(--green);color:#052e16}

/* ── O MAPA DO PALPITE ───────────────────────────────────────────────*/
.geo-mapa{height:min(52vh,440px);border-radius:20px;margin-top:16px;overflow:hidden;
  border:1px solid var(--border)}
.geo-mapa-l{display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-top:12px}
.geo-mapa-l p{flex:1;min-width:220px;margin:0;color:var(--text-2);font-size:13px}
.geo-confirmar{padding:15px 28px;border-radius:14px;border:none;cursor:pointer;font:inherit;
  font-size:15.5px;font-weight:800;background:var(--c);color:#06202e;
  transition:filter var(--t) var(--ease),opacity var(--t) var(--ease)}
/* Desligado ele fica CINZA, e não a cor do jogo esmaecida: verde escuro com
   letra escura por cima não se lê, e o botão parecia quebrado em vez de
   esperando. */
.geo-confirmar:disabled{background:var(--panel-3);color:var(--text-3);cursor:not-allowed}
.geo-confirmar:not(:disabled):hover{filter:brightness(1.08)}

/* ── O RESULTADO DA RODADA ───────────────────────────────────────────
   O veredito é a primeira coisa, grande; a distância vem como número; e o
   mapa embaixo mostra a linha do palpite até onde era de verdade — é o
   momento em que se aprende alguma coisa jogando. */
.geo-result{position:relative;overflow:hidden;border-radius:22px;padding:22px;margin-bottom:18px;
  background:var(--panel);border:1px solid var(--border);animation:geoSobe .35s var(--ease)}
@keyframes geoSobe{from{opacity:0;transform:translateY(10px)}}
.geo-result::before{content:"";position:absolute;inset:-60% 35% auto -10%;height:230px;pointer-events:none;
  background:radial-gradient(60% 100% at 30% 0,color-mix(in srgb,var(--c) 28%,transparent),transparent 70%)}
.geo-result > *{position:relative}
.geo-veredito{display:flex;align-items:center;gap:12px;font-family:'Barlow Condensed',Inter,sans-serif;
  font-size:34px;font-weight:700;letter-spacing:.5px;line-height:1;color:var(--c)}
.geo-veredito i{font-size:28px}
.geo-era{font-size:17px;margin-top:8px}
.geo-era b{font-weight:800}
.geo-era .fi{display:inline-block;width:24px;height:18px;border-radius:3px;vertical-align:-3px;
  box-shadow:0 0 0 1px rgba(0,0,0,.35) inset;background-size:cover}
.geo-numeros{display:flex;gap:26px;flex-wrap:wrap;margin-top:14px}
.geo-numeros div b{display:block;font-family:'Barlow Condensed',Inter,sans-serif;font-size:30px;
  line-height:1;color:var(--text)}
.geo-numeros div span{font-size:10.5px;text-transform:uppercase;letter-spacing:.9px;color:var(--text-3)}
.geo-credito{margin-top:14px;font-size:11.5px;color:var(--text-3)}
.geo-credito a{color:var(--text-2)}
.geo-fim{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-top:16px}
.geo-fim .geo-voltar{display:inline-flex;align-items:center;gap:8px;padding:15px 22px;border-radius:14px;
  border:1px solid var(--border-md);background:var(--panel-2);color:var(--text-2);font-size:14.5px;
  font-weight:700}
.geo-fim .geo-voltar:hover{border-color:var(--c);color:var(--text)}
.geo-mapa-res{height:260px;border-radius:16px;margin-top:16px;overflow:hidden;border:1px solid var(--border)}

/* ── A ENTRADA: OS DOIS MODOS ────────────────────────────────────────*/
.geo-capa{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,300px),1fr));gap:16px}
.geo-modo{position:relative;overflow:hidden;display:flex;flex-direction:column;gap:10px;
  padding:24px;border-radius:22px;background:var(--panel);border:1px solid var(--border);
  text-align:left;color:inherit;font:inherit;cursor:pointer;
  transition:transform var(--t) var(--ease),border-color var(--t) var(--ease)}
.geo-modo::before{content:"";position:absolute;inset:-55% 25% auto -10%;height:240px;pointer-events:none;
  background:radial-gradient(60% 100% at 30% 0,color-mix(in srgb,var(--c) 30%,transparent),transparent 70%)}
.geo-modo > *{position:relative}
.geo-modo:hover{transform:translateY(-3px);border-color:color-mix(in srgb,var(--c) 45%,transparent)}
.geo-modo .ico{display:grid;place-items:center;width:46px;height:46px;border-radius:15px;font-size:22px;
  background:color-mix(in srgb,var(--c) 16%,transparent);color:var(--c)}
.geo-modo h3{margin:0;font-family:'Barlow Condensed',Inter,sans-serif;font-size:27px;font-weight:700;
  letter-spacing:.4px}
.geo-modo p{margin:0;color:var(--text-2);font-size:13.5px;line-height:1.5}
.geo-modo .rec{margin-top:6px;padding-top:12px;border-top:1px solid var(--border);
  font-size:12px;color:var(--text-3)}
.geo-modo .rec b{font-family:'Barlow Condensed',Inter,sans-serif;font-size:19px;color:var(--c);
  margin-right:3px}
.geo-nums{display:flex;flex-wrap:wrap;gap:6px 20px;font-size:12.5px;color:var(--text-3);margin:2px 0 18px}
.geo-nums b{color:var(--text-2)}

/* ── RANKING ─────────────────────────────────────────────────────────*/
.geo-ranks{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,320px),1fr));gap:18px;
  margin-top:26px}
.geo-rank h4{margin:0 0 10px;font-family:'Barlow Condensed',Inter,sans-serif;font-size:21px;
  font-weight:700;letter-spacing:.3px}
.geo-linha{display:flex;align-items:center;gap:12px;padding:11px 14px;border-radius:13px;font-size:14px}
.geo-linha:nth-child(odd){background:var(--panel)}
.geo-linha .pos{font-family:'Barlow Condensed',Inter,sans-serif;font-size:19px;color:var(--text-3);
  min-width:24px;text-align:center}
.geo-linha .pos.ouro{color:#f5c518}.geo-linha .pos.prata{color:#c5ccd6}.geo-linha .pos.bronze{color:#cd7f32}
.geo-linha .gm{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-weight:600}
.geo-linha .v{font-weight:800;color:var(--c);font-variant-numeric:tabular-nums}
.geo-linha.eu{background:color-mix(in srgb,var(--c) 14%,transparent)}
.geo-vazio{color:var(--text-3);font-size:13.5px}
.geo-aviso{padding:13px 17px;border-radius:14px;margin-bottom:16px;font-size:13.5px}
.geo-aviso.ok{background:color-mix(in srgb,var(--green) 13%,transparent);color:var(--green)}
.geo-aviso.err{background:color-mix(in srgb,var(--red) 13%,transparent);color:var(--red-2)}
.geo-aviso.teste{background:color-mix(in srgb,var(--amber) 14%,transparent);color:var(--amber);
  border:1px solid color-mix(in srgb,var(--amber) 35%,transparent)}

/* ── DESAFIOS ────────────────────────────────────────────────────────*/
.geo-ganhou{display:flex;align-items:center;gap:13px;padding:14px 18px;border-radius:16px;
  margin-bottom:12px;background:color-mix(in srgb,var(--green) 14%,var(--panel));
  border:1px solid color-mix(in srgb,var(--green) 40%,transparent);animation:geoSobe .35s var(--ease)}
.geo-ganhou i{font-size:24px;color:var(--green);flex:none}
.geo-ganhou b{display:block;font-size:14.5px}
.geo-ganhou span{font-size:12.5px;color:var(--text-2)}
/* A conquista tem cor própria: ela é rara e não pode parecer missão. */
.geo-ganhou.raro{background:color-mix(in srgb,#f5c518 13%,var(--panel));
  border-color:color-mix(in srgb,#f5c518 45%,transparent)}
.geo-ganhou.raro i{color:#f5c518}
.geo-desafios{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,330px),1fr));
  gap:18px;margin-top:26px}
.geo-bloco{background:var(--panel);border:1px solid var(--border);border-radius:18px;padding:18px}
.geo-bloco h4{margin:0 0 12px;font-family:'Barlow Condensed',Inter,sans-serif;font-size:21px;
  font-weight:700;letter-spacing:.3px}
.geo-des{display:flex;align-items:center;gap:11px;padding:9px 0;font-size:13.5px;
  border-top:1px solid var(--border)}
.geo-des:first-of-type{border-top:0}
.geo-des i{font-size:15px;color:var(--text-3);flex:none}
.geo-des span{flex:1;min-width:0}
.geo-des small{display:block;font-size:11.5px;color:var(--text-3)}
.geo-des b{font-size:12.5px;color:var(--text-2);white-space:nowrap}
/* Feito fica riscado e some de cor — continua na lista como prova. */
.geo-des.ok span{color:var(--text-3);text-decoration:line-through}
.geo-des.ok i{color:var(--green)}
.geo-des.ok b{color:var(--green)}

@media (max-width:620px){
  .geo-palco{aspect-ratio:4/3;border-radius:18px}
  /* No celular o panorama fica em pe: mais ceu e mais chao, que e o que
     se olha pra descobrir onde se esta. */
  .geo-palco.rua{aspect-ratio:3/4;min-height:420px}
  /* TRES CAIXAS MAIS O BOTAO NAO CABEM em 390px de tela: no celular o
     placar encolhe e o Encerrar fica so no X. O que nao pode e o relogio
     sair da tela — ele e a informacao que muda. */
  .geo-hud{padding:10px;gap:7px}
  .geo-hud .caixa{padding:7px 10px;border-radius:12px}
  .geo-hud .caixa b{font-size:21px}
  .geo-hud .caixa span{font-size:8.5px;letter-spacing:.6px}
  .geo-sair{padding:9px 11px}
  .geo-sair .txt{display:none}
  .geo-pergunta{font-size:24px;left:14px;bottom:12px}
  .geo-hud{padding:12px}
  .geo-veredito{font-size:28px}
  .geo-modo{padding:20px}
}
<?php include __DIR__ . '/../../includes/accent-color.php'; ?>
</style>
</head>
<body<?= ($partida && $rodada) ? ' class="geo-jogando"' : '' ?>>

<?php include __DIR__ . '/../../includes/sidebar.php'; ?>
<div class="sb-overlay" id="sbOverlay"></div>

<header class="topbar">
  <button class="menu-btn" id="menuBtn"><i class="bi bi-list"></i></button>
  <div class="topbar-title">FBA <em>Geo</em></div>
</header>

<main class="main">
  <div class="dash-hero">
    <div>
      <div class="dash-eyebrow">Jogos da liga</div>
      <h1 class="dash-title">FBA Geo</h1>
      <p class="dash-sub">Olhe a foto e diga onde é no mundo.</p>
    </div>
  </div>

<div class="content">
<div id="geo">
<?php if (!$geoTeste && $geoTesteNoLink && $partida): ?>
<div class="geo-aviso teste">Você tem uma partida <b>valendo</b> em andamento — ela
  segue normal e paga o que valer. A <b>próxima</b> é que será de teste.</div>
<?php endif; ?>
<?php if ($geoTeste): ?>
<?php /* A FAIXA FICA A PARTIDA INTEIRA. Descobrir no fim que nada foi pago
         seria a pior hora de descobrir — ela tem que estar na tela antes do
         primeiro palpite e continuar lá. */ ?>
<div class="geo-aviso teste"><b>Partida de teste.</b> Joga igualzinho, mas não
  paga nada: sem moeda, sem missão, e fora do ranking e do recorde.</div>
<?php endif; ?>
<?php if ($msg): ?><div class="geo-aviso ok"><?= e($msg) ?></div><?php endif; ?>
<?php if ($erro): ?><div class="geo-aviso err"><?= e($erro) ?></div><?php endif; ?>

<?php /* ── O RESULTADO DA RODADA QUE ACABOU ─────────────────────────── */ ?>
<?php if ($resolvida):
  $lug = $resolvida['lugar'];
  $ehMapa = ($resolvida['modo'] ?? '') === 'mapa';
  /* ── O VEREDITO ─────────────────────────────────────────────────────
     No mapa não existe certo e errado, existe por quanto — e chamar de
     "quase lá" um palpite que caiu a 10.000 km é piada involuntária. A
     frase e a cor acompanham a distância, que é a única coisa que o modo
     mede de verdade. */
  if (!empty($resolvida['estourou'])) {
      /* Sem palpite não há distância pra mostrar: o painel diz o que era e
         para por aí. @see geoVencida */
      [$frase, $ico, $cor] = ['Tempo esgotado', 'hourglass-bottom', 'var(--red)'];
  } elseif (!$ehMapa) {
      [$frase, $ico, $cor] = $resolvida['acertou']
          ? ['Acertou!', 'check-circle-fill', 'var(--green)']
          : ['Errou', 'x-circle-fill', 'var(--red)'];
  } else {
      $km = (float)$resolvida['km'];
      [$frase, $ico, $cor] = $km <= 50   ? ['Na mosca!',  'bullseye',      'var(--green)']
                           : ($km <= 500  ? ['Perto',      'geo-fill',      'var(--green)']
                           : ($km <= 2000 ? ['Quase lá',   'rulers',        'var(--amber)']
                           : ($km <= 6000 ? ['Longe',      'rulers',        'var(--amber)']
                                          : ['Outro mundo','globe-americas','var(--red)'])));
  } ?>
  <?php /* ── O QUE A JOGADA RENDEU DE DESAFIO ────────────────────────
       Vem ANTES do veredito, e separado dele, porque é a notícia boa: no
       meio do painel de "errou" ela passaria batida, e conquista que ninguém
       vê não faz ninguém voltar. @see games/core/geo_desafios.php */ ?>
  <?php foreach (($resolvida['ganhos'] ?? []) as $g): ?>
    <div class="geo-ganhou <?= $g['tipo'] === 'conquista' ? 'raro' : '' ?>">
      <i class="bi bi-<?= $g['tipo'] === 'conquista' ? 'trophy-fill' : 'check2-circle' ?>"></i>
      <div>
        <b><?= $g['tipo'] === 'conquista' ? 'Conquista' : 'Missão do dia' ?>:
           <?= e($g['texto']) ?></b>
        <span><?= $g['tipo'] === 'conquista'
            ? '+' . (int)$g['pts'] . ' FBA Points — e essa só dá uma vez na vida.'
            : '+' . (int)$g['moedas'] . ' moedas' ?></span>
      </div>
    </div>
  <?php endforeach; ?>

  <div class="geo-result" style="--c:<?= $cor ?>">
    <?php /* ── A REVELAÇÃO ─────────────────────────────────────────────
         A foto volta, agora com o nome do lugar em cima dela. Antes o
         resultado era texto puro: a pessoa olhava uma paisagem por dois
         minutos, respondia, e a imagem sumia junto com a resposta — sem
         chance de ligar o que ela viu ao lugar que era. É esse encontro
         que faz a próxima rodada ser mais fácil, e é a melhor parte do
         jogo. @see games/games/geo_foto.php */ ?>
    <div class="geo-revela">
      <img src="/games/games/geo_foto.php?r=<?= (int)$resolvida['rodada_id'] ?>"
           alt="<?= e($lug['nome']) ?>" loading="lazy">
      <div class="geo-veredito">
        <i class="bi bi-<?= $ico ?>"></i> <?= $frase ?>
      </div>
    </div>
    <?php $bandeira = (string)(geoPaises()[(string)($lug['iso'] ?? '')]['iso2'] ?? ''); ?>
    <div class="geo-era">Era <b><?= e($lug['nome']) ?></b>,
      <?php if ($bandeira !== ''): ?><span class="fi fi-<?= e($bandeira) ?>"></span> <?php endif; ?>
      <?= e($lug['pais']) ?>.</div>

    <?php if ($ehMapa && empty($resolvida["estourou"])): ?>
      <div class="geo-numeros">
        <div><b><?= number_format((float)$resolvida['km'], 0, ',', '.') ?> km</b><span>de distância</span></div>
        <div><b><?= number_format((int)$resolvida['pontos'], 0, ',', '.') ?></b><span>pontos na rodada</span></div>
        <div><b><?= number_format((int)$resolvida['pontos_total'], 0, ',', '.') ?></b><span>no total</span></div>
      </div>
      <?php /* O MAPA DA CORREÇÃO: o palpite, o lugar e a linha entre os dois.
           É aqui que a pessoa descobre que confundiu Porto com Lisboa — e é
           por isso que ele vale mais que a frase "errou por 300 km". */ ?>
      <div class="geo-mapa-res" id="mapaRes"
           data-plat="<?= (float)$resolvida['palpite_lat'] ?>" data-plon="<?= (float)$resolvida['palpite_lon'] ?>"
           data-clat="<?= (float)$lug['lat'] ?>" data-clon="<?= (float)$lug['lon'] ?>"
           data-nome="<?= e($lug['nome']) ?>"></div>
    <?php endif; ?>

    <?php /* O CRÉDITO DA FOTO. As licenças CC exigem autor e licença visíveis,
         então ele aparece aqui e não escondido num rodapé. Quando o Commons
         não devolveu o autor, a linha some em vez de inventar um nome — o
         link pro arquivo original fica, e é lá que o crédito completo está. */
    $autor = trim((string)($lug['autor'] ?? ''));
    $lic   = trim((string)($lug['licenca'] ?? ''));
    /* Quando a rodada foi panorama, a cena era do Google e não daquela foto
       — creditar o fotógrafo do Commons aqui seria crédito errado. */
    if (geoRua($lug)): ?>
      <div class="geo-credito">panorama do Google Street View</div>
    <?php else: ?>
    <div class="geo-credito">
      <?= $autor !== '' ? 'foto de ' . e($autor) . ' · ' : '' ?><?= $lic !== '' ? e($lic) . ' · ' : '' ?>
      <a target="_blank" rel="noopener"
         href="https://commons.wikimedia.org/wiki/File:<?= rawurlencode((string)$lug['img']) ?>">Wikimedia Commons</a>
    </div>
    <?php endif; ?>

    <?php if (!empty($resolvida['fim'])): ?>
      <div class="geo-numeros" style="margin-top:18px">
        <?php if ($ehMapa): ?>
          <div><b><?= number_format((int)$resolvida['pontos_total'], 0, ',', '.') ?></b><span>pontos na partida</span></div>
        <?php else: ?>
          <div><b><?= (int)$resolvida['acertos_total'] ?></b><span>acertos seguidos</span></div>
        <?php endif; ?>
        <div><b><?= !empty($resolvida['moedas']) ? '+' . (int)$resolvida['moedas'] : '0' ?></b><span>moedas</span></div>
      </div>
      <?php if (empty($resolvida['moedas'])): ?>
        <?php /* O MOTIVO CERTO. A frase culpava sempre o teto do dia — e
             numa partida de teste, que nunca paga, ela dizia que o limite
             tinha passado quando não tinha. Dois motivos diferentes, duas
             frases. */ ?>
        <p class="geo-credito"><?= $geoTeste
            ? 'Partida de teste: ela não paga moeda nem entra no ranking.'
            : 'Sem moedas desta vez: o teto de ' . GEO_PREMIADAS_DIA . ' partidas premiadas do dia já passou. O ranking continua valendo.' ?></p>
      <?php endif; ?>
      <div class="geo-fim">
        <form method="POST" style="margin:0">
          <input type="hidden" name="acao" value="comecar">
          <input type="hidden" name="modo" value="<?= $ehMapa ? 'mapa' : 'pais' ?>">
          <?php if ($geoArmaTeste): ?><input type="hidden" name="teste" value="1"><?php endif; ?>
          <button class="geo-confirmar" type="submit"><i class="bi bi-arrow-repeat"></i> Jogar de novo</button>
        </form>
        <a class="geo-voltar" href="/games/games/geo.php"><i class="bi bi-house"></i> Tela inicial</a>
      </div>
    <?php else: ?>
      <?php /* ── A PARTIDA CONTINUA, MAS QUEM ABRE A PRÓXIMA É A PESSOA ──
           Antes a rodada seguinte já vinha montada embaixo do resultado,
           com o relógio correndo — ou seja, o tempo de ler o que aconteceu
           saía do tempo de pensar na próxima. Agora o resultado fica
           sozinho, e o botão é que começa a contagem. @see geoProxima */ ?>
      <div class="geo-fim">
        <form method="POST" style="margin:0">
          <input type="hidden" name="acao" value="proxima">
          <button class="geo-confirmar" type="submit">
            <?= $ehMapa
                ? 'Próxima rodada <i class="bi bi-arrow-right"></i>'
                : 'Continuar a série <i class="bi bi-arrow-right"></i>' ?></button>
        </form>
        <span class="geo-credito" style="margin:0">
          <?php $faltam = GEO_RODADAS_MAPA - (int)$resolvida['feitas'];
                $seq = (int)$resolvida['acertos_total']; ?>
          <?= $ehMapa
              ? ($faltam === 1 ? 'Falta a última rodada.' : "Faltam {$faltam} de " . GEO_RODADAS_MAPA . '.')
              : ($seq === 1 ? 'Você está com 1 acerto.' : "Você está com {$seq} acertos seguidos.") ?>
          O relógio só começa quando você clicar.</span>
      </div>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php if ($partida && !$rodada && !$resolvida):
  /* ── A PARTIDA FICOU PARADA ENTRE DUAS RODADAS ─────────────────────
     Agora que a próxima só nasce com o clique, existe um estado novo: a
     pessoa respondeu, fechou a aba e voltou depois. A partida está de pé e
     não há rodada nenhuma — sem este bloco, a tela mostraria a entrada e o
     jogo pareceria ter sumido com o progresso dela. */
  $ehMapa = $partida['modo'] === 'mapa'; ?>
  <div class="geo-result" style="--c:var(--blue)">
    <div class="geo-veredito"><i class="bi bi-hourglass-split"></i> Partida em andamento</div>
    <div class="geo-era">
      <?= $ehMapa
          ? 'Você tem <b>' . number_format((int)$partida['pontos'], 0, ',', '.') . '</b> pontos.'
          : 'Você está em <b>' . (int)$partida['acertos'] . '</b> acertos seguidos.' ?>
    </div>
    <div class="geo-fim">
      <form method="POST" style="margin:0">
        <input type="hidden" name="acao" value="proxima">
        <button class="geo-confirmar" type="submit">Continuar <i class="bi bi-arrow-right"></i></button>
      </form>
      <form method="POST" style="margin:0"
            data-confirmar="Encerrar esta partida? O que você já fez continua valendo no ranking.">
        <input type="hidden" name="acao" value="desistir">
        <button class="geo-voltar" type="submit"><i class="bi bi-x-lg"></i> Encerrar</button>
      </form>
    </div>
  </div>

<?php endif; ?>

<?php if ($partida && $rodada):
  /* ── JOGANDO ──────────────────────────────────────────────────────── */
  $lug = $rodada['dados'];
  $ehMapa = $partida['modo'] === 'mapa';
  $opcoes = $ehMapa ? [] : (array)json_decode((string)$rodada['opcoes'], true);
  $letras = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H'];
  /* O PANORAMA QUANDO DÁ, A FOTO QUANDO NÃO DÁ. Precisa de chave da Maps
     Embed API configurada e de o lugar ter cobertura — sem um dos dois,
     geoRua() devolve null e a rodada é a foto do Commons de sempre. */
  $rua = geoRua($lug); ?>
  <div class="geo-jogo" style="--c:<?= $ehMapa ? 'var(--blue)' : 'var(--green)' ?>">
    <div class="geo-palco<?= $rua ? ' rua' : '' ?>">
      <?php if ($rua): ?>
        <?php /* O referrerpolicy não é enfeite: é ele que manda o domínio pro
             Google, e é por domínio que a chave é restringida no console. */ ?>
        <?php /* SEM allowfullscreen, e não é esquecimento: em tela cheia o
             iframe cobre a página inteira e a placa que esconde o endereço,
             que é um elemento NOSSO e vive fora dele, fica pra trás — o
             cartão "515 Rue de la Reine, Quebec" apareceria inteirinho. A
             pessoa gira e anda do mesmo jeito dentro do palco. */ ?>
        <iframe src="<?= e($rua) ?>" title="Onde é isso?"
                referrerpolicy="strict-origin-when-cross-origin"></iframe>
        <?php /* ── O CANTO QUE ENTREGA A RESPOSTA ─────────────────────────
             O Street View incorporado desenha um cartão no alto à esquerda
             com o nome do panorama e um "Ver no Google Maps" — e clicar ali
             abre o mapa com o lugar escrito. Num jogo de adivinhar onde é,
             isso é o gabarito grudado na pergunta. A placa cobre esse canto;
             o logo do Google, que é atribuição e não controle, continua no
             pé da imagem onde o Google pede que fique. */ ?>
        <div class="tapa"></div>
      <?php else: ?>
        <div class="carregando">carregando a foto…</div>
        <?php /* A foto vem pelo número da rodada, e não pelo endereço do
             Commons: o nome do arquivo lá é o nome do lugar.
             @see games/games/geo_foto.php */ ?>
        <img src="/games/games/geo_foto.php?r=<?= (int)$rodada['id'] ?>" alt="Onde é isso?"
             onload="this.previousElementSibling && this.previousElementSibling.remove()">
      <?php endif; ?>
      <div class="geo-hud">
        <div class="caixa">
          <b><?= $ehMapa ? (int)$rodada['n'] . '/' . GEO_RODADAS_MAPA : (int)$partida['acertos'] ?></b>
          <span><?= $ehMapa ? 'rodada' : 'seguidos' ?></span>
        </div>
        <?php if ($ehMapa): ?>
          <div class="caixa">
            <b><?= number_format((int)$partida['pontos'], 0, ',', '.') ?></b><span>pontos</span>
          </div>
        <?php endif; ?>
        <?php /* ── O RELÓGIO ───────────────────────────────────────────────
             Começa de onde o SERVIDOR diz que está, não de dois minutos
             cheios: recarregar a página não ganha tempo nenhum. Quando
             zera, o JavaScript só recarrega — quem fecha a rodada é o PHP.
             @see geoVencida */ ?>
        <div class="caixa relogio" id="relogio" data-restam="<?= geoRestam($rodada) ?>">
          <b>--:--</b><span>tempo</span>
        </div>
        <form method="POST">
          <input type="hidden" name="acao" value="desistir">
          <button class="geo-sair" type="submit"><i class="bi bi-x-lg"></i><span class="txt"> Encerrar</span></button>
        </form>
      </div>
      <div class="geo-pergunta">
        <?= $ehMapa ? 'Onde fica?' : 'Que país é esse?' ?>
        <small><?= $ehMapa
            ? 'Clique no mapa — quanto mais perto, mais pontos.'
            : 'Uma chance. Errou, a série acaba.' ?></small>
      </div>
    </div>

    <?php /* ── O CANTO DO MAPA ─────────────────────────────────────────
         Reclamação que chegou pelos GMs (16/10/2026): "não tá legal de
         jogar". O motivo era o desenho da página — a foto era um cartão
         e o mapa, um bloco EMBAIXO dela. Pra responder era preciso rolar
         a tela, perder a foto de vista, clicar, e rolar de volta pra
         conferir. Num jogo que é olhar a paisagem e apontar no mapa, os
         dois têm que estar à vista ao mesmo tempo.

         Agora é o canto do GeoGuessr: o mapa encolhido sobre a foto,
         crescendo quando o mouse chega perto (ou no toque, no celular).
         O botão de confirmar mora dentro dele, que é onde a mão já
         está. @see geoPainel no script */ ?>
    <aside class="geo-painel" id="geoPainel">
      <button type="button" class="geo-painel-aba so-js" id="geoPainelAba"
              aria-expanded="false" aria-controls="geoPainel">
        <i class="bi bi-globe-americas"></i>
        <span><?= $ehMapa ? 'Apontar no mapa' : 'Escolher o país' ?></span>
        <i class="bi bi-chevron-up seta"></i>
      </button>
    <?php if ($ehMapa): ?>
      <div class="geo-mapa" id="mapa"></div>
      <form method="POST" class="geo-mapa-l" id="formMapa">
        <input type="hidden" name="acao" value="palpite">
        <input type="hidden" name="lat" id="lat"><input type="hidden" name="lon" id="lon">
        <p id="dicaMapa">Clique no mapa onde você acha que é. Dá pra mudar quantas vezes quiser
          antes de confirmar.</p>
        <button class="geo-confirmar" type="submit" id="btnMapa" disabled>
          <i class="bi bi-geo-alt-fill"></i> Confirmar palpite</button>
      </form>
    <?php else:
      /* ── O MAPA DAS OPÇÕES ─────────────────────────────────────────
         Pedido do Marcos: "clica no país e ele fica verdinho". Os seis
         candidatos aparecem acesos no mapa-múndi e a pessoa escolhe ali.

         SÃO OS SEIS DE SEMPRE, e não o mundo inteiro, de propósito: clicar
         em qualquer um dos 196 transformaria a maior sequência — que é o
         ranking deste modo — numa conta de 1 em 196, e ninguém passaria de
         dois acertos. Com os seis acesos, a dificuldade é a de antes e o
         mapa ainda ensina onde ficam as alternativas, que é a melhor parte.

         A LISTA DE BOTÕES CONTINUA EMBAIXO. País pequeno em mapa-múndi no
         celular é alvo de um milímetro; o alfinete com a letra ajuda, o
         botão resolve. Clicar num ou no outro é a mesma coisa.

         @see games/data/paises.geo.json  os contornos (Natural Earth) */
      $mapaOps = [];
      foreach ($opcoes as $k => $op) {
          $iso = geoIsoDoPais((string)$op);
          $fp  = geoPaises()[$iso] ?? null;
          $mapaOps[] = ['nome' => (string)$op, 'iso' => $iso, 'letra' => $letras[$k] ?? '?',
                        'iso2' => (string)($fp['iso2'] ?? ''),
                        'lat' => $fp['lat'] ?? null, 'lon' => $fp['lon'] ?? null];
      } ?>
      <div class="geo-mapa so-js" id="mapaPais"
           data-ops='<?= e(json_encode($mapaOps, JSON_UNESCAPED_UNICODE)) ?>'></div>
      <form method="POST" class="geo-mapa-l so-js" id="formPais">
        <input type="hidden" name="acao" value="palpite">
        <input type="hidden" name="pais" id="paisEscolhido">
        <p id="dicaPais">Clique no país aceso que você acha que é — ou escolha na lista abaixo.</p>
        <button class="geo-confirmar" type="submit" id="btnPais" disabled>
          <i class="bi bi-flag-fill"></i> Confirmar palpite</button>
      </form>
      <div class="geo-ops so-js" id="listaPais">
        <?php foreach ($mapaOps as $op): ?>
          <button type="button" data-pais="<?= e($op['nome']) ?>">
            <i><?= $op['letra'] ?></i>
            <?php /* A bandeira é enfeite ÚTIL: ela é a cara do país, e quem
                 não reconhece o nome às vezes reconhece a bandeira. Quando
                 falta o código de duas letras, some sem deixar buraco. */ ?>
            <?php if ($op['iso2'] !== ''): ?>
              <span class="fi fi-<?= e($op['iso2']) ?>" aria-hidden="true"></span>
            <?php endif; ?>
            <span class="nm"><?= e($op['nome']) ?></span></button>
        <?php endforeach; ?>
      </div>
      <?php /* SEM JAVASCRIPT A RODADA AINDA SE JOGA. O mapa e a lista acima
           dependem de script; estes seis botões são formulário puro, e
           aparecem só quando o script não subiu. */ ?>
      <noscript>
        <div class="geo-ops">
          <?php foreach ($opcoes as $k => $op): ?>
            <form method="POST">
              <input type="hidden" name="acao" value="palpite">
              <input type="hidden" name="pais" value="<?= e($op) ?>">
              <button type="submit"><i><?= $letras[$k] ?? '?' ?></i> <?= e($op) ?></button>
            </form>
          <?php endforeach; ?>
        </div>
      </noscript>
    <?php endif; ?>
    </aside>
  </div>

<?php elseif (!$partida):
  /* ── A ENTRADA ──────────────────────────────────────────────────────
     Só quando NÃO há partida de pé. Era um else solto, e com a pausa entre
     rodadas isso passou a significar "partida em andamento mais a tela de
     começar outra" na mesma página — dois caminhos contraditórios à vista,
     e o segundo encerrava o primeiro sem avisar. */ ?>
  <div class="geo-nums">
    <span><b><?= number_format($nLugares, 0, ',', '.') ?></b> lugares</span>
    <span><b><?= $nPaises ?></b> países</span>
    <span><b><?= number_format($moedas, 0, ',', '.') ?></b> moedas na conta</span>
    <span>hoje ainda valem moedas <b><?= $restantes ?></b> partida<?= $restantes === 1 ? '' : 's' ?></span>
  </div>

  <div class="geo-capa">
    <form method="POST" style="margin:0;display:contents">
      <input type="hidden" name="acao" value="comecar">
      <input type="hidden" name="modo" value="pais">
      <?php if ($geoArmaTeste): ?><input type="hidden" name="teste" value="1"><?php endif; ?>
      <button class="geo-modo" type="submit" style="--c:var(--green)">
        <span class="ico"><i class="bi bi-flag-fill"></i></span>
        <h3>Que país é esse?</h3>
        <p><?= GEO_OPCOES ?> opções por foto. Acertou, vem outra; errou, acabou. O ranking é a
           maior sequência — e cada acerto paga <?= GEO_MOEDA_ACERTO ?> moedas.</p>
        <span class="rec"><b><?= (int)$recorde['pais']['a'] ?></b> seu recorde
          · <?= (int)$recorde['pais']['n'] ?> partida<?= (int)$recorde['pais']['n'] === 1 ? '' : 's' ?></span>
      </button>
    </form>
    <form method="POST" style="margin:0;display:contents">
      <input type="hidden" name="acao" value="comecar">
      <input type="hidden" name="modo" value="mapa">
      <?php if ($geoArmaTeste): ?><input type="hidden" name="teste" value="1"><?php endif; ?>
      <button class="geo-modo" type="submit" style="--c:var(--blue)">
        <span class="ico"><i class="bi bi-geo-alt-fill"></i></span>
        <h3>Onde fica no mapa?</h3>
        <p><?= GEO_RODADAS_MAPA ?> rodadas, clicando no mapa do mundo. Quanto mais perto, mais
           pontos: 5.000 no alvo. Cada 100 pontos vale <?= GEO_MOEDA_MAPA_CEM ?> moeda — rodada
           cravada paga 50.</p>
        <span class="rec"><b><?= number_format((int)$recorde['mapa']['p'], 0, ',', '.') ?></b> seu recorde
          · <?= (int)$recorde['mapa']['n'] ?> partida<?= (int)$recorde['mapa']['n'] === 1 ? '' : 's' ?></span>
      </button>
    </form>
  </div>

  <?php /* ── OS DESAFIOS ──────────────────────────────────────────────
       As três do dia primeiro, porque são as que se cumprem HOJE; as
       conquistas embaixo, como vitrine do que ainda falta. O que já foi
       feito continua na lista, riscado: tirar da tela apagaria a única
       prova de que a pessoa fez. */
  $feitos = geoMeusDesafios($pdo, $user_id);
  $doDia  = geoMissoesDoDia(); ?>
  <div class="geo-desafios">
    <div class="geo-bloco">
      <h4><i class="bi bi-calendar-check"></i> Missões de hoje</h4>
      <?php foreach ($doDia as $k): $m = geoMissoes()[$k]; $ok = !empty($feitos[$k]); ?>
        <div class="geo-des <?= $ok ? 'ok' : '' ?>">
          <i class="bi bi-<?= $ok ? 'check-circle-fill' : 'circle' ?>"></i>
          <span><?= e($m['t']) ?></span>
          <b><?= (int)$m['moedas'] ?> moedas</b>
        </div>
      <?php endforeach; ?>
      <p class="geo-credito">Trocam à meia-noite, e são as mesmas pra liga inteira.</p>
    </div>

    <div class="geo-bloco">
      <h4><i class="bi bi-trophy"></i> Conquistas</h4>
      <?php foreach (geoConquistas() as $k => $q): $ok = !empty($feitos[$k]); ?>
        <div class="geo-des <?= $ok ? 'ok' : '' ?>">
          <i class="bi bi-<?= $ok ? 'trophy-fill' : 'circle' ?>"></i>
          <span><?= e($q['t']) ?> <small><?= e($q['d']) ?></small></span>
          <b><?= (int)$q['pts'] ?> FBA</b>
        </div>
      <?php endforeach; ?>
      <p class="geo-credito">Cada uma paga uma vez na vida, em FBA Points.</p>
    </div>
  </div>

  <div class="geo-ranks">
    <?php foreach ([['pais', 'Maiores sequências', 'seguidos', 'var(--green)'],
                    ['mapa', 'Mais pontos no mapa', 'pts', 'var(--blue)']] as [$modo, $titulo, $un, $cor]):
      $r = geoRanking($pdo, $modo, 8); ?>
      <div class="geo-rank" style="--c:<?= $cor ?>">
        <h4><?= e($titulo) ?></h4>
        <?php if (!$r): ?>
          <p class="geo-vazio">Ninguém jogou ainda. Seja o primeiro.</p>
        <?php else: foreach ($r as $i => $x):
          $medalha = [0 => 'ouro', 1 => 'prata', 2 => 'bronze'][$i] ?? ''; ?>
          <div class="geo-linha<?= (int)$x['user_id'] === $user_id ? ' eu' : '' ?>">
            <span class="pos <?= $medalha ?>"><?= $i + 1 ?></span>
            <span class="gm"><?= e($x['gm'] ?: 'GM') ?></span>
            <span class="v"><?= number_format((int)$x['melhor'], 0, ',', '.') ?> <?= $un ?></span>
          </div>
        <?php endforeach; endif; ?>
      </div>
    <?php endforeach; ?>
  </div>

  <p class="geo-credito" style="margin-top:22px">
    As fotos vêm do <a href="https://commons.wikimedia.org" target="_blank" rel="noopener">Wikimedia
    Commons</a> e os lugares do Wikidata — tudo de licença livre, com o autor creditado em cada
    rodada. O mapa é OpenStreetMap.</p>
<?php endif; ?>
</div>
</div>
</main>

<?php /* O botão de menu do celular e o de tema, os mesmos de todas as telas. */ ?>
<script src="/js/popups.js"></script>
<script src="/js/sidebar.js"></script>
<script src="/js/tema.js"></script>

<?php if ($partida && $rodada && $rua): ?>
<script>
/* ── O PANORAMA QUANDO O PALCO MUDA DE TAMANHO ───────────────────────
   O Street View mede o espaço uma vez, ao abrir, e desenha pra aquele
   tamanho. Se o palco mudar depois — o celular virando de lado é o caso
   real —, ele não refaz a conta sozinho e a imagem fica no lugar errado.

   O iframe é de outro domínio, então não há como pedir nada lá dentro. O
   que há é mudar o tamanho dele por fora: um pixel a mais e de volta faz o
   Street View medir de novo. É um pixel dentro de um palco que esconde o
   que passa da borda, então ninguém vê.

   (A mudança mais comum — a barra de rolagem aparecendo quando o mapa
   carrega e roubando uns pixels de largura — foi resolvida antes de
   acontecer: o CSS reserva a barra desde o começo, com scrollbar-gutter.) */
(function(){
  var f = document.querySelector('.geo-palco iframe');
  if (!f) return;
  var marcado;

  function cutuca(){
    f.style.height = (f.clientHeight + 1) + 'px';
    setTimeout(function(){ f.style.height = ''; }, 400);
  }
  function emBreve(){ clearTimeout(marcado); marcado = setTimeout(cutuca, 250); }

  /* Quem é vigiado é o PALCO e quem muda é o IFRAME: vigiar o próprio
     iframe entraria em laço, porque a mudança dispararia a si mesma. */
  if (window.ResizeObserver) new ResizeObserver(emBreve).observe(f.parentNode);
  window.addEventListener('orientationchange', emBreve);
})();
</script>
<?php endif; ?>

<?php if ($partida && $rodada): ?>
<script>
/* ── O RELÓGIO DA RODADA ─────────────────────────────────────────────
   Ele não decide nada: desenha a contagem que o servidor mandou e, quando
   zera, recarrega a página. Quem fecha a rodada é o PHP, que confere a hora
   de nascimento dela no banco — mexer neste contador no console só muda o
   número na tela. */
(function(){
  var el = document.getElementById('relogio');
  if (!el) return;
  var fim = Date.now() + (parseInt(el.dataset.restam, 10) || 0) * 1000;
  var mostrador = el.querySelector('b'), acabou = false;

  function pinta(){
    var s = Math.max(0, Math.round((fim - Date.now()) / 1000));
    mostrador.textContent = Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0');
    el.classList.toggle('apertou', s <= 30);
    if (s <= 0 && !acabou) { acabou = true; location.reload(); }
  }
  pinta();
  setInterval(pinta, 250);   // 250ms e não 1s: assim o segundo não "pula"
})();
</script>
<?php endif; ?>

<?php if ($partida && $rodada && $partida['modo'] === 'pais'): ?>
<script>
/* ── O MAPA-MÚNDI DAS OPÇÕES ─────────────────────────────────────────
   Desenha o mundo apagado e acende os seis candidatos. Clicar num país
   aceso (ou no alfinete dele, ou no botão da lista) pinta de verde e libera
   o confirmar. Tudo isso é só escolha: quem compara o palpite com a
   resposta é o servidor, que nem recebe o resto. */
(function(){
  var caixa = document.getElementById('mapaPais');
  if (!caixa || !window.L) return;
  var ops = JSON.parse(caixa.dataset.ops || '[]');
  var porIso = {};
  ops.forEach(function(o){ if (o.iso) porIso[o.iso] = o; });

  var mapa = L.map('mapaPais', {
    zoomControl: true, attributionControl: false, minZoom: 1, maxZoom: 6,
    worldCopyJump: true
  }).setView([22, 10], 1);

  var APAGADO = {color: 'rgba(255,255,255,.14)', weight: .7, fillColor: '#1c1c21', fillOpacity: 1};
  var ACESO   = {color: '#7dd3fc', weight: 1.4, fillColor: '#2563eb', fillOpacity: .62};
  var MEU     = {color: '#86efac', weight: 2,   fillColor: '#22c55e', fillOpacity: .78};

  var escolhido = null, formas = {}, pinos = {};

  /* O nome com a bandeirinha antes, pra etiqueta do mapa dizer a mesma
     coisa que o botao da lista. */
  function etiqueta(o){
    return (o.iso2 ? '<span class="fi fi-' + o.iso2 + '"></span> ' : '') + o.nome;
  }

  function escolher(o){
    escolhido = o;
    Object.keys(formas).forEach(function(iso){
      formas[iso].setStyle(iso === o.iso ? MEU : ACESO);
    });
  document.getElementById('mapaPais')._leaflet_map = mapa;
    Object.keys(pinos).forEach(function(iso){
      var d = pinos[iso].getElement();
      if (d) d.querySelector('.geo-pino').classList.toggle('eu', iso === o.iso);
    });
    document.querySelectorAll('#listaPais button').forEach(function(b){
      b.classList.toggle('eu', b.dataset.pais === o.nome);
    });
    document.getElementById('paisEscolhido').value = o.nome;
    document.getElementById('btnPais').disabled = false;
    document.getElementById('dicaPais').textContent =
      'Escolhido: ' + o.nome + '. Dá pra trocar até confirmar.';
  }

  /* Os contornos vêm de um arquivo só, do nosso servidor, e o navegador o
     guarda em cache: da segunda rodada em diante o mapa é instantâneo. */
  fetch('/games/data/paises.geo.json').then(function(r){ return r.json(); }).then(function(geo){
    L.geoJSON(geo, {
      style: function(f){ return porIso[f.id] ? ACESO : APAGADO; },
      onEachFeature: function(f, camada){
        var o = porIso[f.id];
        if (!o) { camada.options.interactive = false; return; }
        formas[f.id] = camada;
        camada.bindTooltip(etiqueta(o), {sticky: true});
        camada.on('click', function(){ escolher(o); });
      }
    }).addTo(mapa);
  }).catch(function(){
    /* Sem os contornos ainda dá pra jogar: ficam os alfinetes e a lista. */
    document.getElementById('dicaPais').textContent =
      'O mapa não carregou. Escolha na lista abaixo.';
  });

  /* O ALFINETE DE CADA CANDIDATO. Não é enfeite: Togo e Singapura em
     mapa-múndi são um ponto de um milímetro, e no celular ninguém acerta o
     polegar neles. O alfinete é o alvo grande do país pequeno. */
  ops.forEach(function(o){
    if (o.lat === null || o.lon === null) return;
    var m = L.marker([o.lat, o.lon], {
      icon: L.divIcon({className: '', iconSize: [26, 26], iconAnchor: [13, 13],
        html: '<div class="geo-pino" style="--c:#38bdf8">' + o.letra + '</div>'})
    }).addTo(mapa);
    m.bindTooltip(etiqueta(o), {direction: 'top'});
    m.on('click', function(){ escolher(o); });
    pinos[o.iso] = m;
  });

  document.querySelectorAll('#listaPais button').forEach(function(b){
    b.addEventListener('click', function(){
      var o = ops.filter(function(x){ return x.nome === b.dataset.pais; })[0];
      if (!o) return;
      escolher(o);
      document.getElementById('mapaPais')._geoMexido = true;
      if (o.lat !== null) mapa.panTo([o.lat, o.lon]);
    });
  });

  /* Clique duplo no confirmar mandaria duas respostas pra mesma rodada. */
  document.getElementById('formPais').addEventListener('submit', function(){
    document.getElementById('btnPais').disabled = true;
  });
})();
</script>
<?php endif; ?>

<?php if ($partida && $rodada && $partida['modo'] === 'mapa'): ?>
<script>
/* O MAPA DO PALPITE. Mundo inteiro visível, um alfinete por clique, e o
   botão só acende quando há onde cravar — confirmar sem palpite mandaria o
   ponto (0,0), no meio do Atlântico. */
(function(){
  var mapa = L.map('mapa', {worldCopyJump: true, minZoom: 1}).setView([18, 0], 2);
  /* O painel do canto precisa alcançar o mapa pra remedi-lo quando
     cresce. @see geoPainel */
  document.getElementById('mapa')._leaflet_map = mapa;
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 12, attribution: '© OpenStreetMap'
  }).addTo(mapa);

  var pino = null;
  mapa.on('click', function(ev){
    var p = ev.latlng.wrap();
    /* A partir daqui a vista é da pessoa: o painel para de reenquadrar o
       mundo quando cresce. @see geoPainel */
    document.getElementById('mapa')._geoMexido = true;
    if (pino) pino.setLatLng(p); else pino = L.marker(p).addTo(mapa);
    document.getElementById('lat').value = p.lat.toFixed(5);
    document.getElementById('lon').value = p.lng.toFixed(5);
    document.getElementById('btnMapa').disabled = false;
    document.getElementById('dicaMapa').textContent =
      'Palpite cravado. Dá pra mudar clicando de novo, ou confirme aí do lado.';
  });

  /* Dois cliques no confirmar mandariam duas respostas pra mesma rodada; a
     segunda o servidor recusa, mas o botão travado evita o susto. */
  document.getElementById('formMapa').addEventListener('submit', function(){
    document.getElementById('btnMapa').disabled = true;
  });
})();
</script>
<?php endif; ?>

<?php if ($resolvida && ($resolvida['modo'] ?? '') === 'mapa'): ?>
<script>
/* O MAPA DA CORREÇÃO: alfinete no palpite, bandeira no lugar certo e a
   linha entre os dois, com o enquadramento ajustado pra os dois caberem. */
(function(){
  var el = document.getElementById('mapaRes');
  if (!el) return;
  var p = [parseFloat(el.dataset.plat), parseFloat(el.dataset.plon)];
  var c = [parseFloat(el.dataset.clat), parseFloat(el.dataset.clon)];
  var mapa = L.map('mapaRes', {zoomControl: false, attributionControl: false, dragging: false,
                               scrollWheelZoom: false, doubleClickZoom: false});
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {maxZoom: 10}).addTo(mapa);
  L.marker(p).addTo(mapa).bindTooltip('seu palpite', {permanent: false});
  L.circleMarker(c, {radius: 9, color: '#22c55e', weight: 3, fillOpacity: .9})
    .addTo(mapa).bindTooltip(el.dataset.nome, {permanent: true, direction: 'top'});
  L.polyline([p, c], {color: '#f59e0b', weight: 2, dashArray: '6 6'}).addTo(mapa);
  mapa.fitBounds(L.latLngBounds([p, c]).pad(0.35));
})();
</script>
<?php endif; ?>
<?php if ($partida && $rodada): ?>
<script>
/* ── O PAINEL DO CANTO ────────────────────────────────────────────────
   Duas coisas que o CSS sozinho não resolve.

   1. A ABA, que no celular é o que abre e fecha — lá não existe passar o
      mouse, e sem ela o mapa ficaria inalcançável.

   2. O LEAFLET PRECISA SER AVISADO. Ele mede o contêiner uma vez e guarda;
      quando o painel cresce, o mapa continua desenhado no tamanho antigo e
      sobra cinza na metade nova, com os cliques caindo no lugar errado. O
      `invalidateSize` é o que manda ele medir de novo, e vai no FIM da
      transição (transitionend) pra não medir no meio do caminho. */
(function () {
  var painel = document.getElementById('geoPainel');
  if (!painel) return;
  var aba = document.getElementById('geoPainelAba');

  function remedir() {
    /* Os três ids possíveis: o mapa do palpite, o das opções de país e
       nada (sem JS não chega aqui). Quem existir, remede. */
    ['mapa', 'mapaPais'].forEach(function (id) {
      var el = document.getElementById(id);
      if (!el || !el._leaflet_map) return;
      var m = el._leaflet_map;
      m.invalidateSize({ animate: false });
      /* E REENQUADRA O MUNDO, enquanto a pessoa não apontou nada. O zoom
         de abertura foi escolhido pro mapa largo de antes; no painel do
         canto, e principalmente no celular, ele cai em cima da África e
         some com metade do planeta. Depois do primeiro palpite a vista é
         da pessoa e ninguém mexe nela. */
      if (!el._geoMexido) m.fitBounds([[-58, -170], [78, 180]], { animate: false });
    });
  }

  if (aba) {
    aba.addEventListener('click', function () {
      var aberto = painel.classList.toggle('aberto');
      aba.setAttribute('aria-expanded', aberto ? 'true' : 'false');
      remedir();
    });
  }
  /* No computador quem abre é o mouse, e o fim da animação é a deixa. */
  painel.addEventListener('transitionend', function (ev) {
    if (ev.propertyName === 'width' || ev.propertyName === 'height') remedir();
  });
  /* Mapa que nasce dentro de painel pequeno já nasce medido errado. */
  window.addEventListener('load', remedir);
})();
</script>
<?php endif; ?>
</body>
</html>
