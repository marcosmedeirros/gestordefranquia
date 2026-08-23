<?php
/**
 * THE PATHETIC — a primeira página.
 *
 * O jornal era uma caixa de HTML: o editor colava a página inteira e a matéria
 * seguinte apagava a anterior. Agora cada notícia é uma linha no banco, e o
 * GRAU dela é o que decide o tamanho que ela ocupa aqui — manchete na largura
 * toda, destaques dividindo a linha de baixo, notícias na grade. É a lógica da
 * banca de jornal: você bate o olho e já sabe o que é grande.
 *
 * ?n=<id> abre uma notícia inteira, com foto e texto.
 */

require_once __DIR__ . '/backend/db.php';
require_once __DIR__ . '/backend/pathetic.php';

// A página continua ABERTA — quem não está logado lê tudo. A sessão entra só
// pra saber quem é quem curte e quem comenta; sem ela, os dois botões viram
// um convite pra entrar.
if (session_status() === PHP_SESSION_NONE) session_start();
$leitorId   = (int)($_SESSION['user_id'] ?? 0);
$leitorNome = trim((string)($_SESSION['user_name'] ?? ''));

$pdo = db();

// Quem edita o jornal também modera o que aparece embaixo dele.
$editoresExtra = ['gustavodossantosgonzaga58@gmail.com'];
$souEditor = $leitorId > 0 && (
    ($_SESSION['user_type'] ?? '') === 'admin'
    || in_array(strtolower((string)($_SESSION['user_email'] ?? '')), $editoresExtra, true));

// O token vive na sessão e acompanha os formulários de curtir e comentar. Sem
// ele, um link numa mensagem qualquer curtiria em nome de quem clicasse.
if ($leitorId > 0 && empty($_SESSION['pathetic_token'])) {
    $_SESSION['pathetic_token'] = bin2hex(random_bytes(16));
}
$tokenLeitor = (string)($_SESSION['pathetic_token'] ?? '');

$erroSocial = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $leitorId > 0) {
    $ok = $tokenLeitor !== '' && hash_equals($tokenLeitor, (string)($_POST['token'] ?? ''));
    $alvo = (int)($_POST['n'] ?? 0);
    if (!$ok) {
        $erroSocial = 'A página ficou aberta tempo demais. Recarregue e tente de novo.';
    } else {
        switch ((string)($_POST['acao'] ?? '')) {
            case 'curtir':
                patheticAlternarCurtida($pdo, $alvo, $leitorId);
                break;
            case 'comentar':
                $erroSocial = patheticComentar($pdo, $alvo, $leitorId, $leitorNome, (string)($_POST['texto'] ?? ''));
                break;
            case 'apagar_comentario':
                patheticApagarComentario($pdo, (int)($_POST['comentario'] ?? 0), $leitorId, $souEditor);
                break;
        }
        // Redireciona depois de escrever: sem isto, F5 na tela recurte a
        // notícia (o clique é um interruptor) ou reenvia o comentário.
        if (!$erroSocial) {
            header('Location: /thepathetic.php?n=' . $alvo . '#conversa');
            exit;
        }
    }
}

$idPedido = isset($_GET['n']) ? (int)$_GET['n'] : 0;
$materia  = $idPedido > 0 ? patheticUma($pdo, $idPedido) : null;

// PEDIU UMA MATÉRIA QUE NÃO EXISTE MAIS. Isso acontece de verdade: o link foi
// pro grupo do WhatsApp e depois a notícia saiu do ar ou foi apagada. Cair na
// capa com HTTP 200 fazia o leitor achar que errou o clique, e dizia pro
// Google que aquele endereço é a capa — duas coisas erradas de uma vez.
$sumiu = $idPedido > 0 && !$materia;
if ($sumiu) http_response_code(404);

// A BUSCA VAI AO BANCO, e não a um filtro no navegador. Filtrar no cliente
// exigiria mandar o texto inteiro de sessenta matérias dentro da página só
// pra o caso de alguém digitar — e ainda assim só acharia o que coubesse no
// que foi mandado. No banco a busca alcança a matéria inteira.
$busca = trim((string)($_GET['q'] ?? ''));
if (mb_strlen($busca) > 80) $busca = mb_substr($busca, 0, 80);

$noticias = $busca !== ''
    ? patheticBuscar($pdo, $busca, 60)
    : patheticPublicadas($pdo, 60);
$capa     = patheticCapa($noticias);

// Curtidas e comentários de tudo que está na tela, numa consulta só.
$social = patheticContagens($pdo,
    array_merge(array_column($noticias, 'id'), $materia ? [$materia['id']] : []), $leitorId);
$comentarios = $materia ? patheticComentarios($pdo, (int)$materia['id']) : [];
$fotosDaMateria = $materia ? patheticFotos($pdo, (int)$materia['id']) : [];

// Conta a leitura DEPOIS de saber que a matéria existe e está publicada: um
// 404 não é leitura, e a capa também não — passar por um card do mosaico não
// é ter lido.
if ($materia) patheticContarView($pdo, (int)$materia['id']);

// O conteúdo antigo (a caixa de HTML) continua no banco e não é jogado fora:
// ele vira o rodapé do arquivo, abaixo das notícias. Quando o jornal tiver
// edições próprias, apagar aquela linha some com esta seção sozinha.
$legado = '';
try {
    $st = $pdo->prepare("SELECT content FROM site_pages WHERE page_key = 'thepathetic' LIMIT 1");
    $st->execute();
    $legado = trim((string)($st->fetchColumn() ?: ''));
} catch (Throwable $e) {}

$e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

/**
 * Curtidas e comentários de um card, quando existirem.
 *
 * Zero não aparece: na capa o espaço é disputado, e "0 comentários" embaixo
 * de dez cards é ruído. Na matéria aberta o zero aparece — lá ele é convite.
 */
function patheticSeloSocial(array $s, int $views = 0): string
{
    $partes = [];
    if ($views > 0)                $partes[] = '<i class="bi bi-eye-fill"></i> ' . $views;
    if (!empty($s['curtidas']))    $partes[] = '<i class="bi bi-heart-fill"></i> ' . (int)$s['curtidas'];
    if (!empty($s['comentarios'])) $partes[] = '<i class="bi bi-chat-fill"></i> ' . (int)$s['comentarios'];
    if (!$partes) return '';
    return '<span class="card-social">' . implode(' ', $partes) . '</span>';
}

/** A tarja do grau, do lado do chapéu. */
function patheticSelo(string $grau): string
{
    $info = PATHETIC_GRAU_INFO[$grau] ?? PATHETIC_GRAU_INFO['noticia'];
    return '<span class="selo selo-' . $grau . '">' . htmlspecialchars($info['rotulo'], ENT_QUOTES, 'UTF-8') . '</span>';
}

$tituloPagina = $materia
    ? $materia['titulo'] . ' — The Pathetic'
    : ($sumiu ? 'Notícia não encontrada — The Pathetic' : 'The Pathetic — o jornal da FBA');
$descPagina = $materia ? patheticResumo($materia, 160) : 'As notícias da liga, em primeira mão.';
$fotoPagina = $materia ? patheticSrcFoto($materia['foto'] ?? '') : '';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
<meta name="theme-color" content="#0b0b0d">
<title><?= $e($tituloPagina) ?></title>

<meta property="og:type" content="<?= $materia ? 'article' : 'website' ?>">
<meta property="og:title" content="<?= $e($materia ? $materia['titulo'] : 'The Pathetic') ?>">
<meta property="og:description" content="<?= $e($descPagina) ?>">
<?php if ($fotoPagina !== ''): ?>
<meta property="og:image" content="<?= $e(preg_match('#^https?://#i', $fotoPagina) ? $fotoPagina : 'https://fbabrasil.com.br' . $fotoPagina) ?>">
<?php endif; ?>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Anton&family=Barlow+Condensed:wght@500;600;700&family=Source+Serif+4:opsz,wght@8..60,400;8..60,600;8..60,700&family=JetBrains+Mono:wght@500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
/* ═══════════════════════════════════════════════════════════════════════
   A IDENTIDADE
   Tablóide, não broadsheet. "The Pathetic" é um nome de jornal de banca que
   grita na capa, e o desenho segue: cabeçalho de caixa-alta pesada, fios
   grossos separando as seções, tarja vermelha no que é manchete.
   O texto da matéria é SERIFADO. É esse detalhe que faz a página ser lida
   como jornal e não como blog — a serifa é a assinatura de quem imprime.
   ═══════════════════════════════════════════════════════════════════════ */
:root{
  /* AS CORES SÃO DA LOGO, amostradas do arquivo: 77% da imagem é #101010,
     8% é #f0f0f0 e o verde-ácido é #e0ff00. Não há vermelho na marca do
     jornal — o vermelho da FBA fica no resto do sistema, e o Pathetic tem
     identidade própria. É isso que faz a página parecer publicação e não
     mais uma tela do app.

     Os cinzas puxam pro verde por um fio (o matiz 70, do ácido, com
     saturação de 4-6%): cinza neutro ao lado de um ácido tão saturado lê
     como cinza que sobrou, não como cinza escolhido. */
  --tinta:#f0f0f0;
  --tinta-2:#a3a49b;
  --tinta-3:#87887e;
  --fundo:#101010;
  --fundo-2:#171812;
  --fundo-3:#1f2019;
  --fio:#2a2b23;
  --fio-forte:#3e4034;
  --acido:#e0ff00;
  --acido-fosco:#b8d400;
  --vermelho:#e0ff00;      /* apelidos antigos, agora apontando pro ácido */
  --ambar:#e0ff00;
  --papel:#f0f0f0;

  --display:'Anton', 'Arial Narrow', sans-serif;
  --etiqueta:'Barlow Condensed', 'Arial Narrow', sans-serif;
  --serifa:'Source Serif 4', Georgia, 'Times New Roman', serif;
  --mono:'JetBrains Mono', ui-monospace, monospace;

  --larg:1140px;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html{-webkit-text-size-adjust:100%}
body{background:var(--fundo);color:var(--tinta);font-family:var(--serifa);
  -webkit-font-smoothing:antialiased;min-height:100vh;overflow-x:hidden}
/* PALAVRA LONGA QUEBRA. Uma URL colada no texto de uma matéria não tem espaço
   pra quebrar, e empurrava a linha 115px pra fora numa tela de 360 — o
   overflow-x:hidden do body escondia a rolagem e CORTAVA o resto da palavra,
   que é pior que rolar: a informação some sem sinal nenhum.
   Vale pro texto e pros títulos, que também são escritos por gente. */
.materia-txt,.materia-tit,.manchete-tit,.destaque-tit,.noticia-tit,
.materia-linha-fina,.linha-fina,.arquivo-html{overflow-wrap:break-word;word-break:break-word}
img{max-width:100%;display:block}
a{color:inherit;text-decoration:none}

/* ── O CABEÇALHO ─────────────────────────────────────────────────────
   A tarja animada era a única coisa que o jornal antigo tinha de próprio.
   Ela fica — mas agora como o fio de cima de uma capa inteira. */
/* A tarja animada saiu. Ela era um gradiente de vermelho pra laranja, de
   outra marca — e um gradiente correndo em cima de uma identidade tão dura
   quanto esta briga com ela. No lugar entra o fio ácido, parado, da mesma
   espessura das duas linhas que cercam o "THE" na logo. */
.tarja{height:4px;background:var(--acido)}

.cabeca{border-bottom:3px solid var(--tinta);background:var(--fundo)}
.cabeca-in{max-width:var(--larg);margin:0 auto;padding:14px 22px 14px;
  display:grid;grid-template-columns:1fr minmax(0,auto) 1fr;align-items:center;gap:14px}
.cabeca-esq,.cabeca-dir{display:flex;align-items:center;gap:10px;min-width:0}
.cabeca-dir{justify-content:flex-end}
/* O MASTHEAD É A LOGO, redesenhada em texto e não em imagem: assim ele
   escala com a tela, sobrevive ao zoom e continua sendo texto pra quem lê
   com leitor de tela. A composição é a da marca — "THE" pequeno entre dois
   fios ácidos, "PATHETIC" gigante embaixo. */
.masthead{display:flex;flex-direction:column;align-items:center;gap:2px;line-height:1}
.masthead .the{display:flex;align-items:center;gap:9px;width:100%;
  font-family:var(--etiqueta);font-size:clamp(11px,1.6vw,14px);font-weight:700;
  letter-spacing:.42em;text-indent:.42em;text-transform:uppercase;color:var(--tinta)}
.masthead .the::before,.masthead .the::after{content:"";flex:1;height:3px;background:var(--acido)}
.masthead .nome{font-family:var(--display);font-size:clamp(34px,8.2vw,72px);
  line-height:.86;letter-spacing:-.02em;text-transform:uppercase;color:var(--tinta);
  white-space:nowrap}
/* O lema, na tarja ácida com texto preto — o elemento que mais identifica a
   marca, e o único lugar da página em que o fundo é claro. */
.lema{display:block;background:var(--acido);color:#101010;
  font-family:var(--etiqueta);font-size:clamp(10.5px,1.7vw,15px);font-weight:700;
  letter-spacing:.28em;text-indent:.28em;text-transform:uppercase;text-align:center;
  padding:4px 10px 3px;margin-top:3px;width:100%}
.voltar,.selo-fba{font-family:var(--etiqueta);font-size:12px;font-weight:600;
  letter-spacing:1.4px;text-transform:uppercase;color:var(--tinta-3);
  display:inline-flex;align-items:center;gap:6px;transition:color .18s;white-space:nowrap}
.voltar:hover{color:var(--vermelho)}
.selo-fba{border:1px solid var(--fio-forte);border-radius:3px;padding:3px 8px;color:var(--tinta-2)}

/* A linha de data: em jornal ela separa o nome do jornal da notícia. */
.data-linha{border-bottom:1px solid var(--fio);background:var(--fundo-2)}
.data-in{max-width:var(--larg);margin:0 auto;padding:7px 22px;
  display:flex;align-items:center;justify-content:space-between;gap:14px;
  font-family:var(--etiqueta);font-size:11.5px;font-weight:600;letter-spacing:1.6px;
  text-transform:uppercase;color:var(--tinta-3)}
.data-in b{color:var(--tinta-2);font-weight:600}

.folha{max-width:var(--larg);margin:0 auto;padding:26px 22px 70px}

/* ── AS TARJAS DE GRAU ───────────────────────────────────────────────
   Cor sozinha não diz nada pra quem não vê cor: cada tarja tem a palavra
   escrita, e a cor é reforço. */
.selo{font-family:var(--etiqueta);font-size:11px;font-weight:700;letter-spacing:1.6px;
  text-transform:uppercase;padding:3px 8px;border-radius:2px;white-space:nowrap;flex:none}
/* Manchete: a tarja ácida com texto preto, igual ao lema da marca.
   Destaque: o mesmo ácido, mas só no contorno e na letra — a hierarquia sai
   do PESO da cor, não de trocar de cor, que é o que faria a página parecer
   um semáforo. */
.selo-manchete{background:var(--acido);color:#101010}
.selo-destaque{background:transparent;color:var(--acido);
  box-shadow:inset 0 0 0 1px color-mix(in srgb,var(--acido) 55%,transparent)}
.selo-noticia{background:var(--fundo-3);color:var(--tinta-3);
  box-shadow:inset 0 0 0 1px var(--fio-forte)}

.chapeu{font-family:var(--etiqueta);font-size:12px;font-weight:700;letter-spacing:1.8px;
  text-transform:uppercase;color:var(--acido)}
.credito{font-family:var(--etiqueta);font-size:11.5px;font-weight:500;letter-spacing:.8px;
  text-transform:uppercase;color:var(--tinta-3);display:flex;align-items:center;
  gap:7px;flex-wrap:wrap}
.credito .quem{color:var(--tinta-2)}
.credito .ponto{width:3px;height:3px;border-radius:50%;background:var(--fio-forte);flex:none}

/* ── A MANCHETE ──────────────────────────────────────────────────────
   Largura toda, foto grande, título no maior corpo da página. Uma só: duas
   manchetes empilhadas viram uma fila, e aí nenhuma é manchete. */
.manchete{display:block;border-bottom:3px double var(--fio-forte);padding-bottom:26px;margin-bottom:26px}
.manchete-foto{position:relative;aspect-ratio:21/9;overflow:hidden;background:var(--fundo-2);
  border:1px solid var(--fio);margin-bottom:18px}
.manchete-foto img{width:100%;height:100%;object-fit:cover;
  transition:transform .5s cubic-bezier(.2,.8,.2,1)}
.manchete:hover .manchete-foto img{transform:scale(1.02)}
/* As tarjas cabem na foto. Eram absolutas em left:12 sem limite de largura,
   e com `white-space:nowrap` num chapéu comprido a segunda saía pela direita
   da foto — medido: 346px de tarja numa foto de 328. Agora elas param na
   borda e a que não couber quebra pra linha de baixo. */
.manchete-tarjas{position:absolute;top:12px;left:12px;right:12px;display:flex;gap:7px;
  align-items:flex-start;flex-wrap:wrap;z-index:2}
.manchete-tarjas .selo{max-width:100%;overflow:hidden;text-overflow:ellipsis}
.manchete-txt{display:grid;gap:12px}
.manchete-tit{font-family:var(--display);font-size:clamp(30px,6.4vw,66px);line-height:.96;
  letter-spacing:-.8px;text-transform:uppercase;text-wrap:balance}
.manchete .linha-fina{font-size:clamp(15px,2.1vw,19px);line-height:1.5;color:var(--tinta-2);
  max-width:62ch}
.manchete:hover .manchete-tit{color:var(--vermelho);transition:color .2s}

/* ── OS DESTAQUES ────────────────────────────────────────────────────
   Duas colunas, com o fio no meio: é o desenho de miolo de jornal, e é o
   que diz "estas duas têm o mesmo peso". */
.faixa{display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));
  gap:0;border-bottom:1px solid var(--fio-forte);margin-bottom:26px}
.destaque{display:block;padding:0 22px 24px;border-left:1px solid var(--fio)}
.destaque:first-child{border-left:none;padding-left:0}
.destaque:last-child{padding-right:0}
.destaque-foto{aspect-ratio:16/9;overflow:hidden;background:var(--fundo-2);
  border:1px solid var(--fio);margin-bottom:13px}
.destaque-foto img{width:100%;height:100%;object-fit:cover;transition:transform .4s}
.destaque:hover .destaque-foto img{transform:scale(1.03)}
.destaque-tit{font-family:var(--display);font-size:clamp(20px,2.6vw,29px);line-height:1.04;
  letter-spacing:-.3px;text-transform:uppercase;margin:9px 0 8px;text-wrap:balance}
.destaque:hover .destaque-tit{color:var(--ambar)}
.destaque p{font-size:14.5px;line-height:1.55;color:var(--tinta-2);margin-bottom:10px}
.cima{display:flex;align-items:center;gap:9px;flex-wrap:wrap}

/* ── A GRADE ─────────────────────────────────────────────────────────── */
.secao{font-family:var(--etiqueta);font-size:13px;font-weight:700;letter-spacing:2.4px;
  text-transform:uppercase;color:var(--tinta-3);display:flex;align-items:center;
  gap:12px;margin:0 0 18px}
.secao::after{content:"";flex:1;height:1px;background:var(--fio)}

/* ── A BUSCA ──────────────────────────────────────────────────────────
   Uma linha só, ácida no foco. Fica acima da lista e não do mosaico: o
   mosaico é a capa do dia, a lista é o arquivo — e é no arquivo que se
   procura. */
.busca{display:flex;gap:9px;align-items:center;margin:0 0 20px}
.busca input{flex:1;min-width:0;background:var(--fundo-2);border:1px solid var(--fio-forte);
  border-radius:0;padding:11px 14px;color:var(--tinta);font-family:var(--etiqueta);
  font-size:15px;font-weight:600;letter-spacing:.5px;outline:none;transition:border-color .15s}
.busca input::placeholder{color:var(--tinta-3);font-weight:500;letter-spacing:.8px;text-transform:uppercase;font-size:13px}
.busca input:focus{border-color:var(--acido)}
.busca button{flex:none;padding:11px 20px;border:none;background:var(--acido);color:#101010;
  font-family:var(--etiqueta);font-size:13px;font-weight:700;letter-spacing:1.6px;
  text-transform:uppercase;cursor:pointer;transition:filter .15s}
.busca button:hover{filter:brightness(1.08)}
.busca .limpar{background:transparent;color:var(--tinta-3);
  box-shadow:inset 0 0 0 1px var(--fio-forte)}
.busca .limpar:hover{color:var(--acido);box-shadow:inset 0 0 0 1px var(--acido)}
.achou{font-family:var(--etiqueta);font-size:13px;letter-spacing:1.4px;text-transform:uppercase;
  color:var(--tinta-3);margin:-8px 0 20px}
.achou b{color:var(--acido)}

/* ── O MOSAICO ────────────────────────────────────────────────────────
   Nem grade regular nem lista: um mosaico de verdade, em que os retângulos
   têm tamanhos diferentes e se encaixam. A primeira peça ocupa duas colunas
   e duas linhas — é a manchete —, e as outras preenchem em volta. É o
   desenho de capa de revista, e é o que faz bater o olho e já saber o que é
   grande sem ler tarja nenhuma.

   auto-fill com 200px de mínimo: em 360px dá uma coluna (tudo empilha, e a
   peça grande simplesmente ocupa a largura), em 700px dá três, em 1140 dá
   cinco. Nenhum breakpoint escrito à mão. */
.mosaico{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));
  grid-auto-rows:170px;gap:12px;margin-bottom:34px}
.peca{position:relative;display:block;overflow:hidden;background:var(--fundo-2);
  border:1px solid var(--fio)}
.peca img{width:100%;height:100%;object-fit:cover;
  transition:transform .5s cubic-bezier(.2,.8,.2,1);filter:grayscale(.35) contrast(1.05)}
.peca:hover img{transform:scale(1.05);filter:grayscale(0) contrast(1)}
/* A tinta por cima da foto: sem ela o título branco desaparece em qualquer
   foto clara, e um jornal não pode depender da sorte da imagem. */
.peca::after{content:"";position:absolute;inset:0;pointer-events:none;
  background:linear-gradient(180deg,rgba(16,16,16,.05) 0%,rgba(16,16,16,.55) 52%,rgba(16,16,16,.94) 100%)}
.peca-txt{position:absolute;left:0;right:0;bottom:0;z-index:2;padding:13px 14px;
  display:flex;flex-direction:column;gap:6px}
.peca-tit{font-family:var(--display);font-size:17px;line-height:1.02;letter-spacing:-.2px;
  text-transform:uppercase;color:var(--tinta);text-wrap:balance;
  overflow-wrap:break-word;word-break:break-word}
.peca:hover .peca-tit{color:var(--acido)}
.peca .cima{position:absolute;top:10px;left:10px;right:10px;z-index:2;
  display:flex;gap:6px;flex-wrap:wrap;align-items:flex-start}
/* A peça grande: duas colunas, duas linhas, e o título no corpo de manchete. */
.peca-g{grid-column:span 2;grid-row:span 2}
.peca-g .peca-tit{font-size:clamp(22px,3.4vw,38px);line-height:.98}
.peca-g .peca-txt{padding:18px 20px;gap:9px}
.peca-g .linha-fina{font-size:14.5px;line-height:1.45;color:var(--tinta-2);
  display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.peca-social{margin-top:2px}
/* A peça média: duas colunas, uma linha. Quebra a monotonia do quadrado. */
.peca-m{grid-column:span 2}
/* Sem foto o mosaico não vira buraco: o fundo escurece e o título ocupa. */
.peca-vazia{background:var(--fundo-2);display:flex;align-items:flex-end}
.peca-vazia::after{background:none}
@media (max-width:560px){
  /* Duas colunas IGUAIS. O auto-fill com minmax(200px,1fr) numa tela de
     390 encaixava uma coluna de 200 e jogava o resto (149) na segunda — o
     mosaico ficava torto, com um lado sempre maior. Aqui o numero de
     colunas e dito, e as duas dividem igual. */
  .mosaico{grid-template-columns:repeat(2,minmax(0,1fr));grid-auto-rows:140px;gap:9px}
  .peca-g{grid-column:span 2;grid-row:span 2}
  /* Em duas colunas a peca media (span 2) empurrava a anterior pra ficar
     sozinha em meia largura, com um buraco do lado. Voltando a span 1, as
     pecas comuns se pareiam de duas em duas e o mosaico fecha certinho. */
  .peca-m{grid-column:span 1}
  .peca-tit{font-size:15px}
}

.grade{display:grid;grid-template-columns:repeat(auto-fill,minmax(268px,1fr));gap:24px}
.noticia{display:flex;flex-direction:column;background:var(--fundo-2);
  border:1px solid var(--fio);transition:border-color .2s,transform .2s}
.noticia:hover{border-color:var(--fio-forte);transform:translateY(-2px)}
.noticia-foto{aspect-ratio:3/2;overflow:hidden;background:var(--fundo-3);flex:none}
.noticia-foto img{width:100%;height:100%;object-fit:cover}
.noticia-corpo{padding:14px 15px 15px;display:flex;flex-direction:column;gap:8px;flex:1}
.noticia-tit{font-family:var(--display);font-size:19px;line-height:1.1;letter-spacing:-.2px;
  text-transform:uppercase;text-wrap:balance}
.noticia:hover .noticia-tit{color:var(--vermelho)}
.noticia p{font-size:13.5px;line-height:1.55;color:var(--tinta-3);flex:1}

/* ── A MATÉRIA ABERTA ────────────────────────────────────────────────
   Coluna estreita — 68 caracteres — porque é o que o olho lê sem se perder
   ao voltar pra linha seguinte. A capa pode ser larga; o texto não. */
.materia{max-width:740px;margin:0 auto}
.materia-cima{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:14px}
.materia-tit{font-family:var(--display);font-size:clamp(30px,5.6vw,52px);line-height:1;
  letter-spacing:-.6px;text-transform:uppercase;margin-bottom:14px;text-wrap:balance}
.materia-linha-fina{font-family:var(--serifa);font-size:clamp(16px,2.2vw,20px);line-height:1.5;
  color:var(--tinta-2);margin-bottom:18px}
.materia-credito{display:flex;align-items:center;gap:9px;flex-wrap:wrap;
  padding:13px 0;border-top:1px solid var(--fio);border-bottom:1px solid var(--fio);margin-bottom:22px}
.materia-foto{margin-bottom:8px;border:1px solid var(--fio)}
.materia-foto img{width:100%;height:auto}
.materia-legenda{font-family:var(--etiqueta);font-size:12px;letter-spacing:.6px;
  color:var(--tinta-3);margin-bottom:24px;text-transform:uppercase}
.materia-txt{font-size:18px;line-height:1.72}
.materia-txt p{margin-bottom:1.05em}
.materia-txt p:last-child{margin-bottom:0}
.materia-txt strong{font-weight:700;color:var(--tinta)}
/* A capitular: a letra grande no primeiro parágrafo. É o gesto mais antigo
   do ramo e o que diz "a matéria começa aqui" sem escrever nada. */
.materia-txt > p:first-child::first-letter{font-family:var(--display);font-size:3.1em;
  line-height:.82;float:left;margin:.06em .1em 0 0;color:var(--acido)}

/* ── O QUE A BARRA DO EDITOR PRODUZ ───────────────────────────────────
   Intertítulo, citação e a foto no meio do texto. Os três existem pra quebrar
   a coluna: matéria longa sem nada que interrompa é parede, e o olho desiste
   antes do terceiro parágrafo. */
.materia-txt .txt-tit{font-family:var(--display);font-size:clamp(20px,3vw,28px);
  line-height:1.05;letter-spacing:-.2px;text-transform:uppercase;color:var(--tinta);
  margin:1.5em 0 .5em;text-wrap:balance}
.materia-txt .txt-tit:first-child{margin-top:0}
/* A citação com o fio ácido na lateral: é a fala que a matéria quer que fique
   na cabeça, e o fio é o mesmo elemento da marca. */
.materia-txt .txt-citacao{margin:1.3em 0;padding:2px 0 2px 20px;
  border-left:4px solid var(--acido);font-size:1.14em;line-height:1.5;
  color:var(--tinta);font-style:italic}
.materia-txt .txt-foto{margin:1.6em 0;border:1px solid var(--fio)}
.materia-txt .txt-foto img{width:100%;height:auto;display:block}
.materia-txt .txt-foto figcaption{font-family:var(--etiqueta);font-size:12px;
  letter-spacing:.6px;text-transform:uppercase;color:var(--tinta-3);
  padding:8px 10px;background:var(--fundo-2)}

/* ── CURTIR E COMENTAR ────────────────────────────────────────────────
   Ficam DEPOIS do texto, nunca antes: o que a pessoa veio fazer é ler. O
   número aparece mesmo com zero — esconder o contador faz parecer que a
   função não existe, e aí ninguém é o primeiro. */
.reacoes{display:flex;align-items:center;gap:10px;flex-wrap:wrap;
  margin-top:28px;padding-top:18px;border-top:1px solid var(--fio)}
.btn-curtir{display:inline-flex;align-items:center;gap:8px;padding:9px 16px;
  border:1px solid var(--fio-forte);border-radius:999px;background:transparent;
  color:var(--tinta-2);font-family:var(--etiqueta);font-size:13px;font-weight:600;
  letter-spacing:.8px;text-transform:uppercase;cursor:pointer;transition:.16s}
.btn-curtir:hover{border-color:var(--vermelho);color:var(--vermelho)}
.btn-curtir.curtida{background:var(--vermelho);border-color:var(--vermelho);color:#fff}
.btn-curtir b{font-family:var(--mono);font-size:12.5px;font-variant-numeric:tabular-nums}
.reacoes .conta{font-family:var(--etiqueta);font-size:12.5px;letter-spacing:.8px;
  text-transform:uppercase;color:var(--tinta-3)}

.conversa{margin-top:34px}
.conversa h2{font-family:var(--etiqueta);font-size:13px;font-weight:700;letter-spacing:2.4px;
  text-transform:uppercase;color:var(--tinta-3);display:flex;align-items:center;gap:12px;margin-bottom:18px}
.conversa h2::after{content:"";flex:1;height:1px;background:var(--fio)}
.coment{display:flex;gap:12px;padding:14px 0;border-top:1px solid var(--fio)}
.coment:first-of-type{border-top:none}
.coment-ini{width:34px;height:34px;border-radius:50%;flex:none;display:grid;place-items:center;
  background:var(--fundo-3);color:var(--tinta-2);font-family:var(--etiqueta);
  font-size:14px;font-weight:700}
.coment-corpo{flex:1;min-width:0}
.coment-cab{display:flex;align-items:baseline;gap:9px;flex-wrap:wrap;margin-bottom:5px}
.coment-cab b{font-size:13.5px;font-weight:700;color:var(--tinta)}
.coment-cab span{font-family:var(--etiqueta);font-size:11.5px;letter-spacing:.8px;
  text-transform:uppercase;color:var(--tinta-3)}
.coment-corpo p{font-size:15.5px;line-height:1.6;color:var(--tinta-2);
  overflow-wrap:break-word;word-break:break-word}
.coment-apagar{background:none;border:none;color:var(--tinta-3);cursor:pointer;
  font-size:12px;padding:2px 4px;margin-left:auto;flex:none;transition:.15s}
.coment-apagar:hover{color:var(--vermelho)}

.form-coment{margin-top:20px}
.form-coment textarea{width:100%;min-height:92px;resize:vertical;background:var(--fundo-2);
  border:1px solid var(--fio-forte);border-radius:10px;padding:12px 14px;color:var(--tinta);
  font-family:var(--serifa);font-size:15.5px;line-height:1.55;outline:none;transition:border-color .16s}
.form-coment textarea:focus{border-color:var(--vermelho)}
.form-coment .pe{display:flex;align-items:center;justify-content:space-between;gap:12px;
  margin-top:10px;flex-wrap:wrap}
.form-coment .quem{font-family:var(--etiqueta);font-size:12px;letter-spacing:.8px;
  text-transform:uppercase;color:var(--tinta-3)}
.form-coment button{padding:10px 22px;border:none;border-radius:999px;background:var(--vermelho);
  color:#fff;font-family:var(--etiqueta);font-size:13px;font-weight:700;letter-spacing:1.2px;
  text-transform:uppercase;cursor:pointer;transition:filter .15s}
.form-coment button:hover{filter:brightness(1.12)}
.entrar-pra-falar{margin-top:20px;padding:16px;border:1px dashed var(--fio-forte);
  border-radius:10px;text-align:center;color:var(--tinta-3);font-size:14.5px}
.entrar-pra-falar a{color:var(--vermelho);text-decoration:underline}
.erro-social{margin-top:14px;padding:11px 14px;border-radius:9px;font-size:14px;
  background:color-mix(in srgb,var(--vermelho) 12%,transparent);
  border:1px solid color-mix(in srgb,var(--vermelho) 35%,transparent);color:var(--vermelho)}
.sem-coment{color:var(--tinta-3);font-size:14.5px;padding:6px 0 2px}

/* O selo de curtida/comentário nos cards da capa. */
.card-social{display:inline-flex;align-items:center;gap:11px;font-family:var(--etiqueta);
  font-size:11.5px;letter-spacing:.8px;text-transform:uppercase;color:var(--tinta-3)}
.card-social i{font-style:normal}

.voltar-capa{display:inline-flex;align-items:center;gap:8px;margin-top:34px;
  font-family:var(--etiqueta);font-size:13px;font-weight:600;letter-spacing:1.6px;
  text-transform:uppercase;color:var(--tinta-3);border-top:1px solid var(--fio);
  padding-top:18px;width:100%;transition:color .18s}
.voltar-capa:hover{color:var(--vermelho)}

/* ── SEM NOTÍCIA / ARQUIVO ───────────────────────────────────────────── */
.vazio{text-align:center;padding:90px 20px;border:1px dashed var(--fio-forte)}
.vazio i{font-size:42px;color:var(--fio-forte);display:block;margin-bottom:16px}
.vazio b{font-family:var(--display);font-size:26px;text-transform:uppercase;
  display:block;margin-bottom:8px;letter-spacing:-.3px}
.vazio p{color:var(--tinta-3);font-size:15px}

.arquivo{margin-top:60px;padding-top:26px;border-top:3px double var(--fio-forte)}
.arquivo-html{color:var(--tinta-2);font-size:15.5px;line-height:1.65}
.arquivo-html img{margin:14px 0}
.arquivo-html h1,.arquivo-html h2,.arquivo-html h3{font-family:var(--display);
  text-transform:uppercase;letter-spacing:-.3px;color:var(--tinta);margin:20px 0 10px}
.arquivo-html p{margin-bottom:.9em}
.arquivo-html a{color:var(--vermelho);text-decoration:underline}

/* O rodapé usava --fio-forte (#3a3a41), que é COR DE LINHA, não de texto:
   1,74:1 contra o fundo, abaixo do 4,5:1 mínimo e praticamente invisível.
   --tinta-3 dá 4,7:1 e continua discreto. */
.rodape{max-width:var(--larg);margin:0 auto;padding:22px;border-top:1px solid var(--fio);
  font-family:var(--etiqueta);font-size:11.5px;letter-spacing:1.4px;text-transform:uppercase;
  color:var(--tinta-3);text-align:center}

/* ── CELULAR ─────────────────────────────────────────────────────────
   O que muda: o cabeçalho vira uma coluna (o masthead não cabe entre dois
   grupos de botão em 375px), os destaques empilham e o fio vertical entre
   eles vira horizontal — vertical em coluna única não separa nada. */
@media (max-width:820px){
  .cabeca-in{grid-template-columns:1fr auto;padding:12px 16px 10px;gap:10px}
  .masthead{grid-column:1/-1;order:2;font-size:clamp(34px,11vw,50px);justify-content:center}
  .cabeca-esq{order:1}
  .cabeca-dir{order:1}
  .data-in{padding:6px 16px;font-size:10.5px;letter-spacing:1.1px}
  .folha{padding:20px 16px 50px}
  .destaque{padding:0 0 22px;border-left:none;border-top:1px solid var(--fio);padding-top:20px}
  .destaque:first-child{border-top:none;padding-top:0}
  .manchete-foto{aspect-ratio:16/10}
  .grade{grid-template-columns:1fr;gap:18px}
  .materia-txt{font-size:17px;line-height:1.68}
}
@media (max-width:440px){
  .data-in span:last-child{display:none}
  .grade{gap:16px}
  .materia-txt > p:first-child::first-letter{font-size:2.6em}
}
</style>
</head>
<body>

<div class="tarja"></div>

<header class="cabeca">
  <div class="cabeca-in">
    <div class="cabeca-esq">
      <a class="voltar" href="/dashboard.php"><i class="bi bi-arrow-left"></i> Voltar</a>
    </div>
    <a class="masthead" href="/thepathetic.php" aria-label="The Pathetic — investigue o lado bruto">
      <span class="the">The</span>
      <span class="nome">Pathetic</span>
      <span class="lema">Investigue o lado bruto</span>
    </a>
    <div class="cabeca-dir">
      <span class="selo-fba">FBA</span>
    </div>
  </div>
</header>

<div class="data-linha">
  <div class="data-in">
    <span><?php
      $diasSem = ['domingo','segunda','terça','quarta','quinta','sexta','sábado'];
      $meses   = ['','janeiro','fevereiro','março','abril','maio','junho','julho','agosto','setembro','outubro','novembro','dezembro'];
      echo $e($diasSem[(int)date('w')] . ', ' . (int)date('j') . ' de ' . $meses[(int)date('n')] . ' de ' . date('Y'));
    ?></span>
    <span><b><?= count($noticias) ?></b> <?= count($noticias) === 1 ? 'notícia publicada' : 'notícias publicadas' ?></span>
  </div>
</div>

<main class="folha">

<?php if ($sumiu): ?>

  <div class="vazio">
    <i class="bi bi-file-earmark-x"></i>
    <b>Esta notícia saiu do ar</b>
    <p>Ela pode ter sido despublicada ou apagada. O resto do jornal continua aqui.</p>
    <a class="voltar-capa" href="/thepathetic.php" style="justify-content:center;border-top:none;margin-top:22px">
      <i class="bi bi-arrow-left"></i> Ir para a capa
    </a>
  </div>

<?php elseif ($materia): /* ─────────── UMA MATÉRIA ─────────── */ ?>

  <article class="materia">
    <div class="materia-cima">
      <?= patheticSelo($materia['grau']) ?>
      <?php if (trim((string)$materia['chapeu']) !== ''): ?>
        <span class="chapeu"><?= $e($materia['chapeu']) ?></span>
      <?php endif; ?>
    </div>

    <h1 class="materia-tit"><?= $e($materia['titulo']) ?></h1>

    <?php $resumo = trim((string)$materia['resumo']); if ($resumo !== ''): ?>
      <p class="materia-linha-fina"><?= $e($resumo) ?></p>
    <?php endif; ?>

    <div class="materia-credito credito">
      <span class="quem">Por <?= $e($materia['autor_nome']) ?></span>
      <i class="ponto"></i>
      <span><?= $e(patheticQuando($materia['publicada_em'] ?: $materia['criada_em'])) ?></span>
      <?php if (trim((string)$materia['texto']) !== ''): ?>
        <i class="ponto"></i>
        <span><?= patheticMinutos($materia['texto']) ?> min de leitura</span>
      <?php endif; ?>
    </div>

    <?php $foto = patheticSrcFoto($materia['foto']); if ($foto !== ''): ?>
      <figure class="materia-foto">
        <img src="<?= $e($foto) ?>" alt="<?= $e($materia['titulo']) ?>">
      </figure>
      <?php if (trim((string)$materia['foto_credito']) !== ''): ?>
        <p class="materia-legenda"><?= $e($materia['foto_credito']) ?></p>
      <?php else: ?>
        <div style="margin-bottom:24px"></div>
      <?php endif; ?>
    <?php endif; ?>

    <div class="materia-txt"><?= patheticTextoHtml($materia['texto'], $fotosDaMateria) ?></div>

    <?php $s = $social[(int)$materia['id']] ?? ['curtidas'=>0,'comentarios'=>0,'euCurti'=>false]; ?>
    <div class="reacoes">
      <?php if ($leitorId > 0): ?>
        <form method="post" style="display:contents">
          <input type="hidden" name="token" value="<?= $e($tokenLeitor) ?>">
          <input type="hidden" name="acao" value="curtir">
          <input type="hidden" name="n" value="<?= (int)$materia['id'] ?>">
          <button class="btn-curtir <?= $s['euCurti'] ? 'curtida' : '' ?>"
                  title="<?= $s['euCurti'] ? 'Você curtiu' : 'Curtir' ?>">
            <i class="bi bi-heart<?= $s['euCurti'] ? '-fill' : '' ?>"></i>
            <b><?= (int)$s['curtidas'] ?></b>
          </button>
        </form>
      <?php else: ?>
        <span class="btn-curtir" style="cursor:default">
          <i class="bi bi-heart"></i> <b><?= (int)$s['curtidas'] ?></b>
        </span>
      <?php endif; ?>
      <span class="conta"><?= (int)$s['comentarios'] ?> <?= (int)$s['comentarios'] === 1 ? 'comentário' : 'comentários' ?></span>
      <?php // +1 porque a leitura de agora acabou de ser contada, e o número
            // veio do banco antes disso.
            $vw = (int)($materia['views'] ?? 0) + 1; ?>
      <span class="conta"><?= $vw ?> <?= $vw === 1 ? 'leitura' : 'leituras' ?></span>
    </div>

    <section class="conversa" id="conversa">
      <h2>Comentários</h2>

      <?php if (!$comentarios): ?>
        <p class="sem-coment">Ninguém comentou ainda.</p>
      <?php endif; ?>

      <?php foreach ($comentarios as $c): ?>
        <div class="coment">
          <div class="coment-ini"><?= $e(mb_strtoupper(mb_substr(trim((string)$c['autor_nome']) ?: '?', 0, 1))) ?></div>
          <div class="coment-corpo">
            <div class="coment-cab">
              <b><?= $e($c['autor_nome']) ?></b>
              <span><?= $e(patheticQuando($c['criado_em'])) ?></span>
              <?php if ($souEditor || (int)$c['id_usuario'] === $leitorId): ?>
                <form method="post" style="margin-left:auto"
                      onsubmit="return confirm('Apagar este comentário?')">
                  <input type="hidden" name="token" value="<?= $e($tokenLeitor) ?>">
                  <input type="hidden" name="acao" value="apagar_comentario">
                  <input type="hidden" name="n" value="<?= (int)$materia['id'] ?>">
                  <input type="hidden" name="comentario" value="<?= (int)$c['id'] ?>">
                  <button class="coment-apagar" title="Apagar"><i class="bi bi-trash"></i></button>
                </form>
              <?php endif; ?>
            </div>
            <p><?= nl2br($e($c['texto']), false) ?></p>
          </div>
        </div>
      <?php endforeach; ?>

      <?php if ($erroSocial): ?>
        <div class="erro-social"><?= $e($erroSocial) ?></div>
      <?php endif; ?>

      <?php if ($leitorId > 0): ?>
        <form class="form-coment" method="post">
          <input type="hidden" name="token" value="<?= $e($tokenLeitor) ?>">
          <input type="hidden" name="acao" value="comentar">
          <input type="hidden" name="n" value="<?= (int)$materia['id'] ?>">
          <textarea name="texto" maxlength="<?= PATHETIC_MAX_COMENTARIO ?>" required
                    placeholder="O que você achou?"><?= $e((string)($_POST['texto'] ?? '')) ?></textarea>
          <div class="pe">
            <span class="quem">Assinando como <?= $e($leitorNome ?: 'você') ?></span>
            <button type="submit">Comentar</button>
          </div>
        </form>
      <?php else: ?>
        <div class="entrar-pra-falar">
          <a href="/login.php">Entre na sua conta</a> pra curtir e comentar.
        </div>
      <?php endif; ?>
    </section>

    <a class="voltar-capa" href="/thepathetic.php">
      <i class="bi bi-arrow-left"></i> Voltar à capa
    </a>
  </article>

<?php elseif (!$noticias): /* ─────────── JORNAL VAZIO ─────────── */ ?>

  <div class="vazio">
    <i class="bi bi-newspaper"></i>
    <b>Nada publicado ainda</b>
    <p>Quando sair notícia, ela aparece aqui.</p>
  </div>

<?php else: /* ─────────── A CAPA ─────────── */ ?>

  <?php
    // O MOSAICO só existe na capa limpa. Buscando, o que a pessoa quer é a
    // lista de resultados — um mosaico de resultados esconderia metade deles
    // atrás de peças grandes e mandaria a ordem de relevância pro lixo.
    // A ORDEM DO MOSAICO É O GRAU, e não a data. Ordenado por data, a peça
    // grande virava a última notícia publicada — e a manchete do dia ia parar
    // num quadradinho de canto, que é o oposto de ter grau. Dentro do mesmo
    // grau, aí sim vale a data.
    $pesoDoGrau = ['manchete' => 0, 'destaque' => 1, 'noticia' => 2];
    $comFoto = array_values(array_filter($noticias, fn($n) => patheticSrcFoto($n['foto']) !== ''));
    usort($comFoto, function ($a, $b) use ($pesoDoGrau) {
        $pa = $pesoDoGrau[$a['grau']] ?? 9;
        $pb = $pesoDoGrau[$b['grau']] ?? 9;
        if ($pa !== $pb) return $pa <=> $pb;
        return strcmp((string)($b['publicada_em'] ?: $b['criada_em']),
                      (string)($a['publicada_em'] ?: $a['criada_em']));
    });
    $noMosaico = $busca === '' ? array_slice($comFoto, 0, 7) : [];
  ?>

  <?php if ($noMosaico): ?>
    <div class="mosaico">
      <?php foreach (array_values($noMosaico) as $k => $n):
        // A primeira peça é grande, a terceira e a sexta são médias. É o que
        // dá o encaixe irregular do mosaico — todas iguais viraria grade.
        $cls = $k === 0 ? 'peca-g' : (($k === 2 || $k === 5) ? 'peca-m' : '');
        $foto = patheticSrcFoto($n['foto']);
      ?>
        <a class="peca <?= $cls ?>" href="/thepathetic.php?n=<?= (int)$n['id'] ?>">
          <img src="<?= $e($foto) ?>" alt="<?= $e($n['titulo']) ?>" <?= $k === 0 ? 'fetchpriority="high"' : 'loading="lazy"' ?>>
          <div class="cima">
            <?= patheticSelo($n['grau']) ?>
            <?php if ($k === 0 && trim((string)$n['chapeu']) !== ''): ?>
              <span class="selo selo-noticia"><?= $e($n['chapeu']) ?></span>
            <?php endif; ?>
          </div>
          <div class="peca-txt">
            <h2 class="peca-tit"><?= $e($n['titulo']) ?></h2>
            <?php $ss = patheticSeloSocial($social[(int)$n['id']] ?? [], (int)($n['views'] ?? 0));
                  if ($ss && $k === 0): ?><div class="peca-social"><?= $ss ?></div><?php endif; ?>
            <?php if ($k === 0): $r = patheticResumo($n, 170); if ($r !== ''): ?>
              <p class="linha-fina"><?= $e($r) ?></p>
            <?php endif; endif; ?>
          </div>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <form class="busca" method="get" action="/thepathetic.php" role="search">
    <input type="search" name="q" value="<?= $e($busca) ?>"
           placeholder="Procurar por título ou palavra na notícia"
           aria-label="Procurar no jornal">
    <button type="submit">Procurar</button>
    <?php if ($busca !== ''): ?>
      <a class="busca-limpar" href="/thepathetic.php"><button type="button" class="limpar">Limpar</button></a>
    <?php endif; ?>
  </form>

  <?php if ($busca !== ''): ?>
    <p class="achou">
      <?php if ($noticias): ?>
        <b><?= count($noticias) ?></b> <?= count($noticias) === 1 ? 'notícia encontrada' : 'notícias encontradas' ?> para "<?= $e($busca) ?>"
      <?php else: ?>
        Nenhuma notícia com "<?= $e($busca) ?>".
      <?php endif; ?>
    </p>
  <?php endif; ?>

  <?php /* A manchete solta e a faixa de destaques saíram: o mosaico já conta
       a hierarquia, com TAMANHO em vez de tarja, e repetir as mesmas cinco
       notícias logo abaixo dele fazia a capa andar em círculo. O que vem
       depois da busca é o ARQUIVO — tudo que o jornal publicou. */ ?>

  <?php
    // O ARQUIVO: tudo, inclusive o que está no mosaico. Antes eram as sobras,
    // e numa capa de cinco notícias sobrava zero — a página terminava logo
    // depois do mosaico, com um campo de busca solto embaixo e nada pra
    // procurar. Aqui embaixo é a lista completa, do mais novo pro mais velho,
    // e é sobre ela que a busca trabalha.
    $naGrade = $noticias;
  ?>
  <?php if ($naGrade): ?>
    <h2 class="secao"><?= $busca !== '' ? 'Resultados' : 'Todas as notícias' ?></h2>
    <div class="grade">
      <?php foreach ($naGrade as $n): $foto = patheticSrcFoto($n['foto']); ?>
        <a class="noticia" href="/thepathetic.php?n=<?= (int)$n['id'] ?>">
          <?php if ($foto !== ''): ?>
            <div class="noticia-foto"><img src="<?= $e($foto) ?>" alt="<?= $e($n['titulo']) ?>" loading="lazy"></div>
          <?php endif; ?>
          <div class="noticia-corpo">
            <div class="cima">
              <?= patheticSelo($n['grau']) ?>
              <?php if (trim((string)$n['chapeu']) !== ''): ?>
                <span class="chapeu" style="font-size:11px"><?= $e($n['chapeu']) ?></span>
              <?php endif; ?>
            </div>
            <h3 class="noticia-tit"><?= $e($n['titulo']) ?></h3>
            <?php $r = patheticResumo($n, 120); if ($r !== ''): ?><p><?= $e($r) ?></p><?php endif; ?>
            <div class="credito">
              <span class="quem"><?= $e($n['autor_nome']) ?></span>
              <i class="ponto"></i>
              <span><?= $e(patheticQuando($n['publicada_em'] ?: $n['criada_em'])) ?></span>
              <?php $ss = patheticSeloSocial($social[(int)$n['id']] ?? [], (int)($n['views'] ?? 0)); if ($ss): ?>
                <i class="ponto"></i><?= $ss ?>
              <?php endif; ?>
            </div>
          </div>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if ($legado !== ''): ?>
    <section class="arquivo">
      <h2 class="secao">Do arquivo</h2>
      <div class="arquivo-html"><?= $legado ?></div>
    </section>
  <?php endif; ?>

<?php endif; ?>

</main>

<footer class="rodape">The Pathetic · FBA</footer>

</body>
</html>
