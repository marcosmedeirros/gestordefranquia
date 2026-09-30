<?php
/**
 * CLUBE FBA — o que a liga anda vendo, lendo e ouvindo.
 *
 * SAIU DE /games DE PROPÓSITO. Os jogos do Games são partida: entra, joga,
 * sai com um placar. Isto é o contrário — é acervo, cresce devagar e o valor
 * está em voltar depois pra ver o que os outros marcaram. Misturado com os
 * joguinhos ele seria mais um card entre trinta; aqui ele é a casa, e as
 * outras mídias entram como aba em vez de virar outro jogo solto.
 *
 * ── AS ABAS DE MÍDIA ─────────────────────────────────────────────────
 *
 * Séries está de pé. Livros, Filmes e Música aparecem DESLIGADAS e dizendo
 * que não chegaram, em vez de não aparecerem: quem abre a página entende na
 * hora que o Clube é maior que séries, e sabe o que vem. Prometer em cinza é
 * honesto; prometer em link que abre tela vazia não é.
 *
 * ── O QUE CADA ABA DE SÉRIES RESPONDE ────────────────────────────────
 *
 *   Catálogo  — o que existe pra marcar (e a busca que vai até o TMDB).
 *   Meu perfil— os meus números, o meu top e o ACERVO: a lista de tudo que
 *               eu marquei, com filtro, busca, ordem, a nota na própria
 *               linha e o X pra tirar.
 *   A liga    — os rankings da liga, o movimento e tudo que alguém daqui viu.
 *   Pessoas   — o diário de cada um, procurando pelo nome.
 *
 * A regra do jogo está em backend/series.php; aqui é tela e roteamento.
 *
 * @see backend/series.php            as regras e as consultas
 * @see backend/series_tmdb.php       a busca que sai do catálogo
 * @see games/core/series_importar_cli.php  de onde o catálogo vem
 * @see games/games/series.php        o endereço antigo, que só redireciona
 */

session_start();
require_once __DIR__ . '/backend/db.php';
require_once __DIR__ . '/backend/series.php';

$idUsuario = (int)($_SESSION['user_id'] ?? 0);
$pdo = db();

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/** A foto de alguém, com o fallback da casa. */
function clubeAvatar(?string $foto): string
{
    $foto = trim((string)$foto);
    return $foto !== '' ? $foto : '/img/default-avatar.png';
}

/**
 * AS MÍDIAS DO CLUBE.
 *
 * `ok` é o que separa aba de promessa. Ligar uma é trocar false por true
 * depois que a tela dela existir — e a ordem aqui é a ordem na barra.
 */
const CLUBE_MIDIAS = [
    'series' => ['rot' => 'Séries', 'ico' => 'projector-fill',    'ok' => true],
    'livros' => ['rot' => 'Livros', 'ico' => 'book-fill',         'ok' => false],
    'filmes' => ['rot' => 'Filmes', 'ico' => 'film',              'ok' => false],
    'musica' => ['rot' => 'Música', 'ico' => 'music-note-beamed', 'ok' => false],
];

/**
 * O VERBO DO FEED.
 *
 * SERIES_ESTADOS tem os rótulos do botão ("Assistida"), que servem pra
 * escolher e não pra contar: "Marco Assistida Breaking Bad" não é frase.
 * O feed conta o que a pessoa fez, então usa verbo.
 */
const CLUBE_VERBO = ['quero' => 'quer ver', 'assistindo' => 'está vendo', 'assistida' => 'assistiu'];

$midia = (string)($_GET['midia'] ?? 'series');
if (!isset(CLUBE_MIDIAS[$midia]) || !CLUBE_MIDIAS[$midia]['ok']) {
    if (!isset(CLUBE_MIDIAS[$midia])) $midia = 'series';
}

/* ── AS AÇÕES RESPONDEM JSON ──────────────────────────────────────────
   A tela nunca recarrega pra marcar uma série: quem está varrendo a grade
   marcando cinco coisas perderia o lugar a cada clique. */
if ($idUsuario > 0 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $acao  = (string)($_POST['acao'] ?? '');
    $serie = (int)($_POST['serie'] ?? 0);

    if ($acao === 'marcar') {
        echo json_encode(seriesMarcar($pdo, $idUsuario, $serie, (string)($_POST['estado'] ?? ''))
            + ['perfil' => seriesPerfil($pdo, $idUsuario)], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($acao === 'avaliar') {
        $n = $_POST['nota'] === '' ? null : (int)$_POST['nota'];
        echo json_encode(seriesAvaliar($pdo, $idUsuario, $serie, $n)
            + ['perfil' => seriesPerfil($pdo, $idUsuario)], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($acao === 'favoritar') {
        echo json_encode(seriesFavoritar($pdo, $idUsuario, $serie)
            + ['perfil' => seriesPerfil($pdo, $idUsuario)], JSON_UNESCAPED_UNICODE);
        exit;
    }
    /* O X DA LISTA. Vem com o perfil de volta porque tirar muda os quatro
       números do topo, e eles não podem mentir até o próximo F5. */
    if ($acao === 'tirar') {
        echo json_encode(seriesTirar($pdo, $idUsuario, $serie)
            + ['perfil' => seriesPerfil($pdo, $idUsuario)], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($acao === 'comentar') {
        echo json_encode(seriesComentar($pdo, $idUsuario, $serie, (string)($_POST['texto'] ?? '')),
                         JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode(['ok' => false, 'erro' => 'Ação desconhecida.']);
    exit;
}

/* A busca também é JSON: uma consulta por pausa na digitação. */
if ($idUsuario > 0 && isset($_GET['json'])) {
    header('Content-Type: application/json; charset=utf-8');
    if ($_GET['json'] === 'busca') {
        echo json_encode(['series' => seriesBuscar($pdo, (string)($_GET['q'] ?? ''), $idUsuario)],
                         JSON_UNESCAPED_UNICODE);
        exit;
    }
    /* ── A BUSCA QUE VAI ALÉM DO CATÁLOGO ─────────────────────────────
       Separada da busca local de propósito. A local responde na hora e é o
       caso de quase toda tecla digitada; esta fala com o TMDB e pode levar
       dois segundos. Juntas num endpoint só, toda busca pagaria o preço da
       minoria — e a tela não teria como avisar que está procurando fora.

       A página só chega aqui quando a local devolveu pouca coisa. */
    if ($_GET['json'] === 'busca_fora') {
        require_once __DIR__ . '/backend/series_tmdb.php';
        $termo = (string)($_GET['q'] ?? '');
        $novas = seriesProcurarNoTmdb($pdo, $termo);
        echo json_encode(['novas' => $novas, 'series' => seriesBuscar($pdo, $termo, $idUsuario)],
                         JSON_UNESCAPED_UNICODE);
        exit;
    }
    /* A FICHA VEM COM A LIGA DENTRO. Duas chamadas pra abrir um popup
       deixariam a lista de recados piscando depois do resto; e quem abre a
       ficha abre justamente pra ver o que os outros disseram. */
    if ($_GET['json'] === 'serie') {
        $id = (int)($_GET['id'] ?? 0);
        echo json_encode(['serie' => seriesUma($pdo, $id, $idUsuario),
                          'quem'  => seriesQuemMarcou($pdo, $id)], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode([]);
    exit;
}

$aba = (string)($_GET['aba'] ?? 'catalogo');
/* A ABA "MINHAS SÉRIES" VIROU O ACERVO DO PERFIL em 30/09/2026. Eram duas
   telas pra mesma pergunta, e a do perfil ficou melhor: tem filtro, busca,
   ordem e o X. O endereço antigo continua chegando em algum lugar certo. */
if ($aba === 'minhas') $aba = 'perfil';
$catalogo = $idUsuario > 0 ? seriesQuantasNoCatalogo($pdo) : 0;
$perfil   = $idUsuario > 0 ? seriesPerfil($pdo, $idUsuario) : null;

/* Quem eu estou olhando na aba Pessoas. Zero = a lista. */
$vendo  = (int)($_GET['u'] ?? 0);
$pessoa = $vendo > 0 ? seriesQuemE($pdo, $vendo) : null;
if (!$pessoa) $vendo = 0;

/* ── OS FILTROS DO ACERVO ─────────────────────────────────────────────
   Vão na URL, e não em estado de JavaScript, porque "minhas assistidas por
   nota" é uma tela que dá pra guardar nos favoritos e mandar pra alguém. */
$cat         = (string)($_GET['cat'] ?? '');
if (!isset(SERIES_ESTADOS[$cat])) $cat = '';
$termoAcervo = trim((string)($_GET['ac'] ?? ''));
$termoPessoa = trim((string)($_GET['nome'] ?? ''));
$ordem       = (string)($_GET['ord'] ?? 'movimento');
$qInicial    = (string)($_GET['q'] ?? '');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Clube FBA</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800;900&family=Barlow+Condensed:wght@600;700&display=swap" rel="stylesheet">
<style>
:root{
  --bg:#08090c; --panel:#111318; --panel2:#171a21; --panel3:#1e222b;
  --borda:#252a35; --borda2:#333a49;
  --txt:#f2f4f7; --txt2:#98a1b0; --txt3:#68707e;
  /* O ROXO É DO CLUBE. Os jogos da casa são verdes e vermelhos de quadra;
     o Clube não é esporte, e a cor separa a página na hora. */
  --acento:#a855f7; --acento-2:#4c1d95;
  --imdb:#f5c518; --fba:#22c55e;
  --display:'Barlow Condensed','Inter',system-ui,sans-serif;
}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--txt);font:15px/1.5 Inter,system-ui,sans-serif;
  -webkit-font-smoothing:antialiased}
a{color:inherit}
#app{max-width:1180px;margin:0 auto;padding:14px 14px 90px}

.topo{position:sticky;top:0;z-index:40;display:flex;align-items:center;gap:10px;
  margin:0 -14px 12px;padding:10px 14px;background:var(--bg);flex-wrap:wrap;
  border-bottom:1px solid transparent;transition:border-color .2s}
.topo.rolou{border-bottom-color:var(--borda)}
.voltar{width:34px;height:34px;display:flex;align-items:center;justify-content:center;
  border-radius:10px;border:1px solid var(--borda);background:var(--panel);text-decoration:none;flex-shrink:0}
.marca{font-family:var(--display);font-size:21px;font-weight:700;letter-spacing:.3px;
  display:flex;align-items:center;gap:8px;white-space:nowrap}
.marca i{color:var(--acento)}
.busca{position:relative;flex:1;min-width:190px;display:flex;align-items:center}
.busca > .bi{position:absolute;left:11px;color:var(--txt3);font-size:13px;pointer-events:none}
.busca input{width:100%;padding:9px 12px 9px 33px;border-radius:11px;border:1px solid var(--borda);
  background:var(--panel);color:var(--txt);font-size:13.5px}
.busca input:focus{outline:none;border-color:var(--acento)}

/* ── A BARRA DAS MÍDIAS ─────────────────────────────────────────────
   Ela é o que diz que o Clube é maior que séries. As desligadas ficam
   visíveis e apagadas: promessa em cinza, não em link que abre nada. */
/* CENTRALIZADAS, mas com SAFE: as duas barras rolam de lado quando não cabem,
   e centro puro num container que rola empurra o começo pra fora do alcance
   — no celular a primeira aba ficaria inacessível. `safe center` centraliza
   quando sobra espaço e volta pro começo quando falta. */
.midias{display:flex;justify-content:safe center;gap:6px;margin-bottom:14px;
  overflow-x:auto;scrollbar-width:none;padding-bottom:2px}
.midias::-webkit-scrollbar{display:none}
.midias a,.midias span{display:flex;align-items:center;gap:6px;padding:8px 13px;border-radius:11px;
  font-size:13px;font-weight:700;text-decoration:none;white-space:nowrap;
  border:1px solid var(--borda);background:var(--panel);color:var(--txt2)}
.midias a:hover{border-color:var(--borda2);color:var(--txt)}
.midias a.on{border-color:var(--acento);background:rgba(168,85,247,.14);color:var(--acento)}
.midias span{opacity:.4;cursor:default}
.midias span .breve{font-size:9.5px;font-weight:800;text-transform:uppercase;letter-spacing:.5px;
  padding:1px 5px;border-radius:5px;background:var(--panel3);color:var(--txt3)}

.abas{display:flex;justify-content:safe center;gap:4px;margin-bottom:16px;
  overflow-x:auto;scrollbar-width:none}
.abas::-webkit-scrollbar{display:none}
.abas a{padding:8px 13px;border-radius:10px;font-size:13px;font-weight:700;color:var(--txt2);
  text-decoration:none;white-space:nowrap}
.abas a.on{background:var(--panel2);color:var(--txt)}

/* ── A GRADE DE PÔSTERES ────────────────────────────────────────────
   O pôster é o que faz achar a série: ninguém lê trinta títulos, mas todo
   mundo reconhece a capa de longe. Por isso ele é o card inteiro, e o texto
   entra por baixo. */
.grade{display:grid;grid-template-columns:repeat(auto-fill,minmax(132px,1fr));gap:13px}
.card{background:none;border:0;padding:0;text-align:left;color:inherit;cursor:pointer;
  display:flex;flex-direction:column;gap:6px}
.capa{position:relative;aspect-ratio:2/3;border-radius:11px;overflow:hidden;
  background:var(--panel2);border:1px solid var(--borda)}
.capa img{width:100%;height:100%;object-fit:cover;display:block}
.capa .vazia{width:100%;height:100%;display:flex;align-items:center;justify-content:center;
  color:var(--txt3);font-size:26px}
.card:hover .capa{border-color:var(--acento)}
.selo{position:absolute;top:6px;left:6px;display:flex;align-items:center;gap:3px;
  padding:2px 6px;border-radius:7px;font-size:10.5px;font-weight:800;
  background:rgba(0,0,0,.72);backdrop-filter:blur(3px)}
.selo.imdb{color:var(--imdb)}
.marca-estado{position:absolute;top:6px;right:6px;width:23px;height:23px;border-radius:7px;
  display:flex;align-items:center;justify-content:center;font-size:12px;
  background:rgba(0,0,0,.72);backdrop-filter:blur(3px)}
.marca-estado.assistida{color:var(--fba)}
.marca-estado.assistindo{color:var(--acento)}
.marca-estado.quero{color:var(--txt2)}
.minha-nota{position:absolute;bottom:6px;right:6px;padding:2px 7px;border-radius:7px;
  font-size:11.5px;font-weight:900;background:var(--acento);color:#fff}
.c-tit{font-size:12.5px;font-weight:700;line-height:1.25;overflow:hidden;
  display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical}
.c-ano{font-size:11px;color:var(--txt3);font-variant-numeric:tabular-nums}

.bloco{background:var(--panel);border:1px solid var(--borda);border-radius:14px;
  padding:15px;margin-bottom:13px}
.bloco h3{margin:0 0 12px;font-family:var(--display);font-size:17px;font-weight:700;
  letter-spacing:.3px;display:flex;align-items:center;gap:8px;text-transform:uppercase}
.bloco h3 i{color:var(--acento)}
.bloco h3 .conta{margin-left:auto;font-family:Inter;font-size:12px;color:var(--txt3);
  text-transform:none;letter-spacing:0;font-weight:600}
.vazio{color:var(--txt3);font-size:13px;padding:14px 0;text-align:center}

.fichas{display:grid;grid-template-columns:repeat(4,1fr);gap:12px}
.ficha{text-align:center}
.ficha .v{font-family:var(--display);font-size:28px;font-weight:700;line-height:1}
.ficha .r{font-size:10.5px;color:var(--txt3);text-transform:uppercase;letter-spacing:.5px;margin-top:3px}

/* ── O POPUP DO JOGO, nunca o do navegador ──────────────────────────
   Vale pra tudo: a ficha da série, a confirmação e o recado de erro. */
.fundo{position:fixed;inset:0;background:rgba(0,0,0,.72);backdrop-filter:blur(4px);z-index:60;
  display:flex;align-items:center;justify-content:center;padding:16px}
.fundo[hidden]{display:none}
.pop{width:100%;max-width:640px;max-height:88vh;overflow-y:auto;background:var(--panel);
  border:1px solid var(--borda2);border-radius:16px;padding:16px;animation:sobe .18s ease}
@keyframes sobe{from{opacity:0;transform:translateY(10px) scale(.98)}to{opacity:1;transform:none}}
.pop-topo{display:flex;gap:14px;margin-bottom:14px}
.pop-capa{width:122px;flex-shrink:0;border-radius:11px;overflow:hidden;background:var(--panel2);
  border:1px solid var(--borda);aspect-ratio:2/3}
.pop-capa img{width:100%;height:100%;object-fit:cover;display:block}
.pop-tit{font-family:var(--display);font-size:25px;font-weight:700;line-height:1.1;letter-spacing:.2px}
.pop-sub{font-size:12px;color:var(--txt3);margin-top:3px}
.pop-gen{font-size:11.5px;color:var(--txt2);margin-top:7px}
.pop-sin{font-size:13px;color:var(--txt2);line-height:1.5;margin:12px 0}

.notas{display:flex;gap:8px;margin-top:11px;flex-wrap:wrap}
.nota-caixa{flex:1;min-width:80px;padding:8px 10px;border-radius:10px;background:var(--panel3);
  border:1px solid var(--borda);text-align:center}
.nota-caixa .n{font-family:var(--display);font-size:22px;font-weight:700;line-height:1}
.nota-caixa .r{font-size:9.5px;color:var(--txt3);text-transform:uppercase;letter-spacing:.5px;margin-top:2px}
.nota-caixa.imdb .n{color:var(--imdb)}
.nota-caixa.fba .n{color:var(--fba)}
.nota-caixa.eu .n{color:var(--acento)}

.rot{font-size:10.5px;color:var(--txt3);text-transform:uppercase;letter-spacing:.5px;
  font-weight:700;margin:14px 0 6px;display:flex;align-items:center;gap:8px}
.rot .conta{margin-left:auto;font-weight:600;letter-spacing:0;text-transform:none;font-size:11px}
.estados{display:flex;gap:6px;flex-wrap:wrap}
.est{flex:1;min-width:92px;padding:9px 8px;border-radius:10px;border:1px solid var(--borda);
  background:var(--panel3);color:var(--txt2);font-size:12px;font-weight:700;cursor:pointer;
  display:flex;align-items:center;justify-content:center;gap:5px}
.est:hover{border-color:var(--borda2);color:var(--txt)}
.est.on{border-color:var(--acento);background:rgba(168,85,247,.14);color:var(--acento)}
.notinhas{display:flex;gap:4px;flex-wrap:wrap}
.notinha{width:34px;height:34px;border-radius:9px;border:1px solid var(--borda);
  background:var(--panel3);color:var(--txt2);font-size:12.5px;font-weight:800;cursor:pointer}
.notinha:hover{border-color:var(--borda2);color:var(--txt)}
.notinha.on{background:var(--acento);border-color:var(--acento);color:#fff}
.notinha:disabled{opacity:.35;cursor:not-allowed}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:7px;padding:9px 14px;
  border-radius:11px;border:1px solid var(--borda2);background:var(--panel3);color:var(--txt);
  font-size:13px;font-weight:700;cursor:pointer;text-decoration:none}
.btn.pri{background:var(--acento);border-color:var(--acento);color:#fff}
.btn.fav.on{background:var(--imdb);border-color:var(--imdb);color:#111}
.btn:disabled{opacity:.45;cursor:not-allowed}
.pop-acoes{display:flex;gap:8px;justify-content:flex-end;margin-top:16px;flex-wrap:wrap}
.recado{font-size:12.5px;color:#fca5a5;margin-top:9px;min-height:17px}

/* ── O RECADO DE UMA LINHA ──────────────────────────────────────────
   Curto de propósito: o lugar dele é embaixo do pôster, ao lado de outros
   vinte. Resenha de três parágrafos ninguém leria. */
.caixa-recado{display:flex;gap:8px;align-items:flex-start}
.caixa-recado textarea{flex:1;min-height:58px;resize:vertical;padding:9px 11px;border-radius:11px;
  border:1px solid var(--borda);background:var(--panel3);color:var(--txt);
  font:13px/1.45 Inter,system-ui,sans-serif}
.caixa-recado textarea:focus{outline:none;border-color:var(--acento)}
.caixa-recado textarea:disabled{opacity:.4}
.sobra{font-size:10.5px;color:var(--txt3);font-variant-numeric:tabular-nums;margin-top:4px;
  text-align:right}

/* ── O QUE A LIGA DIZ ───────────────────────────────────────────────── */
.diz{display:flex;flex-direction:column;gap:9px}
.diz-um{display:flex;gap:9px;align-items:flex-start;padding:9px 10px;border-radius:11px;
  background:var(--panel3);border:1px solid var(--borda)}
.av{width:28px;height:28px;border-radius:50%;object-fit:cover;flex-shrink:0;background:var(--panel2)}
.av.g{width:52px;height:52px}
.diz-cab{display:flex;align-items:center;gap:7px;flex-wrap:wrap;font-size:12.5px}
.diz-cab b{font-weight:700}
.diz-cab .oq{color:var(--txt3);font-size:11.5px}
.pino{padding:1px 6px;border-radius:5px;font-size:9.5px;font-weight:800;letter-spacing:.4px;
  background:var(--panel);border:1px solid var(--borda);color:var(--txt3);text-transform:uppercase}
.nota-pino{padding:1px 7px;border-radius:6px;font-size:11.5px;font-weight:900;
  background:var(--acento);color:#fff;font-variant-numeric:tabular-nums}
.diz-txt{font-size:13px;color:var(--txt2);line-height:1.45;margin-top:4px}
.diz-eu{border-color:var(--acento-2);background:rgba(168,85,247,.08)}

/* ── O TOP DO PERFIL ────────────────────────────────────────────────── */
/* O TOP TEM DEZ e a grade não pode fixar cinco colunas: numa tela estreita
   elas espremem o pôster até ele não valer mais como capa. Auto-fill põe
   quantas couberem, que é o mesmo comportamento da grade do catálogo. */
.top-lista{display:grid;grid-template-columns:repeat(auto-fill,minmax(112px,1fr));gap:10px}
.top-lista .pos{position:absolute;bottom:6px;left:6px;width:21px;height:21px;border-radius:6px;
  background:var(--imdb);color:#111;font-size:11px;font-weight:900;
  display:flex;align-items:center;justify-content:center}

.linha-liga{display:flex;align-items:flex-start;gap:10px;padding:9px 0;
  border-bottom:1px solid var(--borda);font-size:13px}
.linha-liga:last-child{border-bottom:0}
.linha-liga > img.capinha{width:32px;height:48px;object-fit:cover;border-radius:6px;flex-shrink:0;
  background:var(--panel2);cursor:pointer}
.linha-liga .corpo{flex:1;min-width:0}
.linha-liga .n{font-weight:900;color:var(--acento);font-variant-numeric:tabular-nums;flex-shrink:0}

/* ── OS TRÊS RANKINGS ───────────────────────────────────────────────
   Lado a lado porque a pergunta é comparativa: o que a liga gostou, o que a
   liga viu e o que a liga ainda quer ver dizem coisas diferentes juntos. */
.tri{display:grid;grid-template-columns:repeat(auto-fit,minmax(272px,1fr));gap:13px;
  margin-bottom:13px}
.tri .bloco{margin-bottom:0}
.rk{display:flex;align-items:center;gap:9px;padding:6px 0;border-bottom:1px solid var(--borda);
  cursor:pointer;background:none;border-left:0;border-right:0;border-top:0;width:100%;
  text-align:left;color:inherit;font:inherit}
.rk:last-child{border-bottom:0}
.rk:hover .rk-t{color:var(--acento)}
.rk .rk-p{width:19px;font-family:var(--display);font-size:17px;font-weight:700;color:var(--txt3);
  text-align:center;flex-shrink:0}
.rk img{width:27px;height:40px;object-fit:cover;border-radius:5px;flex-shrink:0;background:var(--panel2)}
.rk .rk-t{flex:1;min-width:0;font-size:12.5px;font-weight:600;overflow:hidden;
  text-overflow:ellipsis;white-space:nowrap}
.rk .rk-v{font-family:var(--display);font-size:18px;font-weight:700;flex-shrink:0;
  font-variant-numeric:tabular-nums}
.rk .rk-v small{font-family:Inter;font-size:9.5px;font-weight:700;color:var(--txt3);
  text-transform:uppercase;letter-spacing:.4px;margin-left:2px}
.rk.nota .rk-v{color:var(--fba)}
.rk.vistas .rk-v{color:var(--acento)}
.rk.querem .rk-v{color:var(--imdb)}

/* ── A LISTA DE TUDO QUE A LIGA VIU ─────────────────────────────────── */
.ordena{display:flex;gap:5px;flex-wrap:wrap;margin-bottom:11px}
.ordena a{padding:5px 10px;border-radius:8px;font-size:11.5px;font-weight:700;
  text-decoration:none;color:var(--txt3);border:1px solid var(--borda);background:var(--panel3)}
.ordena a.on{color:var(--acento);border-color:var(--acento);background:rgba(168,85,247,.12)}
.linha-serie{display:flex;align-items:center;gap:11px;padding:9px 0;width:100%;
  border:0;border-bottom:1px solid var(--borda);background:none;color:inherit;
  text-align:left;font:inherit;cursor:pointer}
.linha-serie:last-child{border-bottom:0}
.linha-serie img{width:34px;height:51px;object-fit:cover;border-radius:6px;flex-shrink:0;
  background:var(--panel2)}
.linha-serie .ls-c{flex:1;min-width:0}
/* PRECISA SER BLOCO: é um <span>, e em elemento inline o overflow:hidden
   não vale — o título comprido furava a linha e dava rolagem lateral na
   página inteira no celular. */
.linha-serie .ls-t{display:block;font-size:13.5px;font-weight:700;overflow:hidden;
  text-overflow:ellipsis;white-space:nowrap}
.linha-serie:hover .ls-t{color:var(--acento)}
.linha-serie .ls-s{font-size:11.5px;color:var(--txt3);display:flex;gap:9px;flex-wrap:wrap;margin-top:2px}
.linha-serie .ls-s i{font-size:10px}
.linha-serie .ls-n{font-family:var(--display);font-size:22px;font-weight:700;color:var(--fba);
  flex-shrink:0;font-variant-numeric:tabular-nums;min-width:34px;text-align:right}

/* ── AS PESSOAS DO CLUBE ────────────────────────────────────────────── */
.gente{display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:11px}
.gente a{display:flex;gap:11px;align-items:center;padding:11px;border-radius:12px;
  border:1px solid var(--borda);background:var(--panel3);text-decoration:none}
.gente a:hover{border-color:var(--acento)}
.gente .nm{font-size:13.5px;font-weight:700;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.gente .sb{font-size:11.5px;color:var(--txt3);margin-top:2px;font-variant-numeric:tabular-nums}
.procura-nome{display:flex;gap:8px;margin-bottom:13px}
.procura-nome input{flex:1;min-width:0;padding:9px 12px;border-radius:11px;border:1px solid var(--borda);
  background:var(--panel3);color:var(--txt);font-size:13.5px}
.procura-nome input:focus{outline:none;border-color:var(--acento)}

.cab-pessoa{display:flex;align-items:center;gap:13px;margin-bottom:14px}
.cab-pessoa .nm{font-family:var(--display);font-size:26px;font-weight:700;line-height:1.05}
.cab-pessoa .sb{font-size:12px;color:var(--txt3);margin-top:2px}

/* ── A PROMESSA DAS OUTRAS MÍDIAS ───────────────────────────────────── */
.breve-caixa{text-align:center;padding:34px 16px}
/* FILHO DIRETO: sem o ">", este tamanho pegava também o ícone de dentro
   do botão e inchava o botão inteiro. */
.breve-caixa > i{font-size:38px;color:var(--acento);opacity:.55}
.breve-caixa h2{font-family:var(--display);font-size:25px;font-weight:700;margin:12px 0 6px;
  letter-spacing:.3px}
.breve-caixa p{color:var(--txt2);font-size:13.5px;margin:0 auto;max-width:430px;line-height:1.55}

/* ── O ACERVO DO PERFIL ─────────────────────────────────────────────
   Lista e não grade: aqui a pergunta é "qual eu dei 10?" e "tira essa", e as
   duas se respondem lendo uma coluna de cima a baixo. Pôster vira miniatura,
   porque quem chegou até aqui já sabe o que marcou. */
.filtros{display:flex;gap:5px;flex-wrap:wrap;margin-bottom:10px}
.filtros a{display:flex;align-items:center;gap:6px;padding:7px 12px;border-radius:10px;
  font-size:12.5px;font-weight:700;text-decoration:none;color:var(--txt2);
  border:1px solid var(--borda);background:var(--panel3)}
.filtros a:hover{border-color:var(--borda2);color:var(--txt)}
.filtros a.on{border-color:var(--acento);background:rgba(168,85,247,.14);color:var(--acento)}
.filtros .qt{font-size:10.5px;font-weight:800;padding:0 5px;border-radius:5px;
  background:rgba(0,0,0,.3);color:var(--txt3);font-variant-numeric:tabular-nums}
.filtros a.on .qt{color:var(--acento)}

.acervo{display:flex;flex-direction:column}
.ac-um{display:flex;align-items:center;gap:11px;padding:9px 0;
  border-bottom:1px solid var(--borda)}
.ac-um:last-child{border-bottom:0}
.ac-um.saindo{opacity:.25;transition:opacity .18s}
.ac-um > img{width:34px;height:51px;object-fit:cover;border-radius:6px;flex-shrink:0;
  background:var(--panel2);cursor:pointer}
.ac-c{flex:1;min-width:0}
.ac-t{font-size:13.5px;font-weight:700;cursor:pointer;overflow:hidden;text-overflow:ellipsis;
  white-space:nowrap}
.ac-t:hover{color:var(--acento)}
.ac-t span{color:var(--txt3);font-weight:600;font-size:11.5px}
.ac-s{font-size:11.5px;color:var(--txt3);display:flex;gap:8px;flex-wrap:wrap;
  align-items:center;margin-top:3px}
.ac-s .pino.assistida{color:var(--fba);border-color:rgba(34,197,94,.35)}
.ac-s .pino.assistindo{color:var(--acento);border-color:rgba(168,85,247,.4)}
.ac-s .pino.top{color:var(--imdb);border-color:rgba(245,197,24,.4)}
.ac-r{color:var(--txt2);font-style:italic;overflow:hidden;text-overflow:ellipsis;
  white-space:nowrap;max-width:100%}
.ac-n{flex-shrink:0;width:58px;padding:7px 6px;border-radius:9px;border:1px solid var(--borda);
  background:var(--panel3);color:var(--txt);font:700 13px Inter,system-ui,sans-serif;
  text-align:center;cursor:pointer}
.ac-n:focus{outline:none;border-color:var(--acento)}
.ac-n:disabled{opacity:.35;cursor:not-allowed}
.ac-n.deu{border-color:var(--fba);color:var(--fba)}
.ac-x{flex-shrink:0;width:32px;height:32px;border-radius:9px;border:1px solid var(--borda);
  background:var(--panel3);color:var(--txt3);cursor:pointer;display:flex;
  align-items:center;justify-content:center;font-size:13px}
.ac-x:hover{border-color:#ef4444;color:#ef4444;background:rgba(239,68,68,.1)}

/* O POPUP DE CONFIRMAR é o do jogo, nunca o confirm() do navegador. */
.pop.pergunta{max-width:400px}
.pop.pergunta h4{font-family:var(--display);font-size:21px;font-weight:700;margin:0 0 8px;
  letter-spacing:.2px}
.pop.pergunta p{color:var(--txt2);font-size:13.5px;margin:0;line-height:1.5}
.btn.perigo{background:#ef4444;border-color:#ef4444;color:#fff}

@media (max-width:560px){
  .fichas{grid-template-columns:repeat(2,1fr);row-gap:14px}
  .grade{grid-template-columns:repeat(auto-fill,minmax(104px,1fr));gap:10px}
  .pop-topo{flex-direction:column}
  .pop-capa{width:100%;max-width:170px;margin:0 auto}
  .marca{font-size:19px}
  .cab-pessoa .nm{font-size:22px}
  /* No celular o recado empurraria a nota e o X pra fora da linha. Ele
     continua na ficha, que é onde se escreve. */
  .ac-r{display:none}
  .ac-um{gap:9px}
}
</style>
</head>
<body>
<div id="app">

  <div class="topo">
    <a href="/dashboard.php" class="voltar" title="Voltar"><i class="bi bi-arrow-left"></i></a>
    <div class="marca"><i class="bi bi-collection-fill"></i> Clube FBA</div>
    <?php if ($idUsuario > 0 && $midia === 'series' && $catalogo > 0): ?>
      <div class="busca">
        <i class="bi bi-search"></i>
        <input type="search" id="busca" autocomplete="off" spellcheck="false"
               value="<?= h($qInicial) ?>"
               placeholder="Buscar série" aria-label="Buscar série">
      </div>
    <?php endif; ?>
  </div>

  <?php /* A BARRA DAS MÍDIAS VEM ANTES DE TUDO, inclusive antes do aviso de
       login: ela é o que explica o que esta página é. */ ?>
  <div class="midias">
    <?php foreach (CLUBE_MIDIAS as $k => $m): ?>
      <?php if ($m['ok']): ?>
        <a href="?midia=<?= $k ?>" class="<?= $midia === $k ? 'on' : '' ?>">
          <i class="bi bi-<?= $m['ico'] ?>"></i> <?= h($m['rot']) ?></a>
      <?php else: ?>
        <span><i class="bi bi-<?= $m['ico'] ?>"></i> <?= h($m['rot']) ?>
          <span class="breve">em breve</span></span>
      <?php endif; ?>
    <?php endforeach; ?>
  </div>

<?php if (!CLUBE_MIDIAS[$midia]['ok']): ?>
  <?php /* Só chega aqui quem digitou a mídia na URL: o link não existe. */ ?>
  <div class="bloco">
    <div class="breve-caixa">
      <i class="bi bi-<?= CLUBE_MIDIAS[$midia]['ico'] ?>"></i>
      <h2><?= h(CLUBE_MIDIAS[$midia]['rot']) ?> ainda não abriu</h2>
      <p>Esta aba está no plano do Clube, mas não tem tela ainda. Séries é o que
         está de pé — comece por lá.</p>
      <p style="margin-top:14px"><a class="btn pri" href="?midia=series">
        <i class="bi bi-projector-fill"></i> Ir pras séries</a></p>
    </div>
  </div>

<?php elseif ($idUsuario <= 0): ?>
  <div class="bloco">
    <h3><i class="bi bi-person-lock"></i> Precisa entrar</h3>
    <p style="color:var(--txt2);margin:0 0 13px">
      O que você marca fica salvo na sua conta — entre na FBA pra começar o seu diário.
    </p>
    <a class="btn pri" href="/login.php"><i class="bi bi-box-arrow-in-right"></i> Entrar</a>
  </div>

<?php elseif ($catalogo === 0): ?>
  <?php /* CATÁLOGO VAZIO É ESTADO, NÃO ERRO. Quem abrir antes de a importação
       rodar merece saber o que falta em vez de uma grade em branco. */ ?>
  <div class="bloco">
    <h3><i class="bi bi-hourglass-split"></i> O catálogo ainda não chegou</h3>
    <p style="color:var(--txt2);margin:0 0 6px">
      As séries entram por uma importação que roda na mão, uma vez:
    </p>
    <pre style="background:var(--panel3);border:1px solid var(--borda);border-radius:10px;
                padding:11px;font-size:12px;overflow-x:auto;color:var(--txt2)">php games/core/series_importar_cli.php --chave=SUA_CHAVE_TMDB --gravar</pre>
    <p style="color:var(--txt3);font-size:12.5px;margin:0">
      A chave é gratuita e sai em dois minutos em themoviedb.org → Configurações → API.
    </p>
  </div>

<?php else: ?>
  <div class="abas">
    <?php foreach (['catalogo' => 'Catálogo', 'perfil' => 'Meu perfil',
                    'liga' => 'A liga', 'pessoas' => 'Pessoas'] as $k => $rot): ?>
      <a href="?midia=series&amp;aba=<?= $k ?>" class="<?= $aba === $k ? 'on' : '' ?>"><?= h($rot) ?></a>
    <?php endforeach; ?>
  </div>

  <?php if ($aba === 'catalogo'): ?>
    <div class="bloco">
      <h3><i class="bi bi-collection-play-fill"></i> <span id="tituloGrade">Mais populares</span></h3>
      <div class="grade" id="grade"></div>
      <div class="vazio" id="gradeFora" hidden><i class="bi bi-arrow-repeat"></i>
        Procurando fora do catálogo…</div>
      <div class="vazio" id="gradeVazia" hidden>Nenhuma série com esse nome.</div>
    </div>

  <?php elseif ($aba === 'perfil'): ?>
    <?= diarioDe($pdo, $perfil, null) ?>

    <?php /* ── O ACERVO ──────────────────────────────────────────────────
         A tela de gerenciar, e não só de olhar. Três grades gigantes em ordem
         de "mexi por último" dava pra ver e não dava pra achar: aqui entra o
         filtro por categoria, a busca no que é meu, a ordem (inclusive por
         nota, que é a pergunta mais comum e a mais chata na grade de pôster),
         a nota editável na própria linha e o X. */ ?>
    <div class="bloco">
      <?php $acervo = seriesMeuAcervo($pdo, $idUsuario, $cat, $termoAcervo, $ordem); ?>
      <h3><i class="bi bi-collection-fill"></i> Meu acervo
        <span class="conta" id="acConta"><?= count($acervo) ?></span></h3>

      <div class="filtros">
        <?php
        $catContas = ['' => (int)$perfil['assistidas'] + (int)$perfil['assistindo'] + (int)$perfil['quero'],
                      'quero' => (int)$perfil['quero'], 'assistindo' => (int)$perfil['assistindo'],
                      'assistida' => (int)$perfil['assistidas']];
        $catRot = ['' => 'Todas'] + SERIES_ESTADOS;
        foreach ($catRot as $k => $rot):
            $liga = '?midia=series&aba=perfil' . ($k !== '' ? '&cat=' . $k : '')
                  . ($termoAcervo !== '' ? '&ac=' . urlencode($termoAcervo) : '')
                  . ($ordem !== 'movimento' ? '&ord=' . urlencode($ordem) : '');
        ?>
          <a href="<?= h($liga) ?>" class="<?= $cat === (string)$k ? 'on' : '' ?>">
            <?= h($rot) ?> <span class="qt"><?= (int)$catContas[$k] ?></span></a>
        <?php endforeach; ?>
      </div>

      <form class="procura-nome" method="get">
        <input type="hidden" name="midia" value="series">
        <input type="hidden" name="aba" value="perfil">
        <?php if ($cat !== ''): ?><input type="hidden" name="cat" value="<?= h($cat) ?>"><?php endif; ?>
        <?php if ($ordem !== 'movimento'): ?><input type="hidden" name="ord" value="<?= h($ordem) ?>"><?php endif; ?>
        <input type="search" name="ac" value="<?= h($termoAcervo) ?>"
               placeholder="Procurar nas minhas" aria-label="Procurar nas minhas séries"
               autocomplete="off">
        <button class="btn pri" type="submit"><i class="bi bi-search"></i></button>
        <?php if ($termoAcervo !== ''): ?>
          <a class="btn" href="?midia=series&amp;aba=perfil<?= $cat !== '' ? '&amp;cat=' . h($cat) : '' ?>"
             title="Limpar"><i class="bi bi-x-lg"></i></a>
        <?php endif; ?>
      </form>

      <div class="ordena">
        <?php
        $ordens = ['movimento' => 'Mais recentes', 'nota' => 'Maior nota', 'notaasc' => 'Menor nota',
                   'titulo' => 'A–Z', 'imdb' => 'IMDb'];
        /* A ordem do acervo e a da aba A liga dividem o mesmo `ord` na URL de
           propósito: são abas diferentes, o valor de uma nunca chega na outra,
           e um segundo nome de parâmetro só daria link mais comprido. */
        foreach ($ordens as $k => $rot):
            $liga = '?midia=series&aba=perfil' . ($cat !== '' ? '&cat=' . $cat : '')
                  . ($termoAcervo !== '' ? '&ac=' . urlencode($termoAcervo) : '')
                  . '&ord=' . $k;
        ?>
          <a href="<?= h($liga) ?>"
             class="<?= ($ordem === $k || ($k === 'movimento' && !isset($ordens[$ordem]))) ? 'on' : '' ?>">
            <?= h($rot) ?></a>
        <?php endforeach; ?>
      </div>

      <?php if (!$acervo): ?>
        <div class="vazio"><?= $termoAcervo !== ''
            ? 'Nenhuma série sua com esse nome.'
            : 'Nada nessa categoria ainda — marque no catálogo.' ?></div>
      <?php else: ?>
        <div class="acervo" id="acervo">
          <?php foreach ($acervo as $s): ?>
            <div class="ac-um" data-linha="<?= (int)$s['id'] ?>">
              <?php $u = seriesPoster($s['poster']); ?>
              <img src="<?= h($u ?: '') ?>" alt="" loading="lazy" data-serie="<?= (int)$s['id'] ?>">
              <div class="ac-c">
                <div class="ac-t" data-serie="<?= (int)$s['id'] ?>"><?= h($s['titulo']) ?>
                  <span><?= h(seriesAnos($s['ano_inicio'] ? (int)$s['ano_inicio'] : null,
                                         $s['ano_fim'] ? (int)$s['ano_fim'] : null,
                                         !empty($s['em_exibicao']))) ?></span></div>
                <div class="ac-s">
                  <span class="pino <?= h($s['estado']) ?>"><?= h(SERIES_ESTADOS[$s['estado']] ?? '') ?></span>
                  <?php if (!empty($s['favorita'])): ?>
                    <span class="pino top"><i class="bi bi-star-fill"></i> <?= (int)$s['favorita'] ?>º</span>
                  <?php endif; ?>
                  <?php if ($s['nota_imdb'] !== null): ?>
                    <span style="color:var(--imdb)"><i class="bi bi-star-fill"></i>
                      <?= h(number_format((float)$s['nota_imdb'], 1, ',', '')) ?></span>
                  <?php endif; ?>
                  <?php if (!empty($s['meu_recado'])): ?>
                    <span class="ac-r">“<?= h($s['meu_recado']) ?>”</span>
                  <?php endif; ?>
                </div>
              </div>
              <?php /* A NOTA SE EDITA AQUI, sem abrir a ficha: quem entrou pra
                   arrumar as notas vai mexer em dez de uma vez, e dez popups
                   seriam dez esperas. Quem só pôs na fila não avalia — o campo
                   vem travado em vez de recusar depois do clique. */ ?>
              <select class="ac-n" data-nota-de="<?= (int)$s['id'] ?>"
                      aria-label="Sua nota para <?= h($s['titulo']) ?>"
                      <?= $s['estado'] === 'quero' ? 'disabled title="Marque como assistida pra dar nota"' : '' ?>>
                <option value=""<?= $s['minha_nota'] === null ? ' selected' : '' ?>>—</option>
                <?php for ($n = 10; $n >= 1; $n--): ?>
                  <option value="<?= $n ?>"<?= (int)$s['minha_nota'] === $n ? ' selected' : '' ?>><?= $n ?></option>
                <?php endfor; ?>
              </select>
              <button class="ac-x" data-tirar="<?= (int)$s['id'] ?>"
                      data-titulo="<?= h($s['titulo']) ?>"
                      title="Tirar do meu perfil" aria-label="Tirar do meu perfil">
                <i class="bi bi-x-lg"></i></button>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

  <?php elseif ($aba === 'pessoas'): ?>
    <?php if ($vendo > 0): ?>
      <?php /* O DIÁRIO DE OUTRA PESSOA é o mesmo do meu, e sai da mesma função:
           duas versões divergiriam no primeiro ajuste, e a de visita seria a
           que ninguém olha. */ ?>
      <div class="bloco">
        <div class="cab-pessoa">
          <img class="av g" src="<?= h(clubeAvatar($pessoa['photo_url'])) ?>" alt=""
               onerror="this.src='/img/default-avatar.png'">
          <div style="min-width:0">
            <div class="nm"><?= h($pessoa['nome']) ?></div>
            <div class="sb"><?= $pessoa['league'] ? h($pessoa['league']) . ' · ' : '' ?>
              <?= $vendo === $idUsuario ? 'o seu diário' : 'o diário no Clube' ?></div>
          </div>
          <a class="btn" href="?midia=series&amp;aba=pessoas" style="margin-left:auto">
            <i class="bi bi-arrow-left"></i> Pessoas</a>
        </div>
      </div>
      <?= diarioDe($pdo, seriesPerfil($pdo, $vendo), $vendo) ?>

    <?php else: ?>
      <div class="bloco">
        <h3><i class="bi bi-people-fill"></i> Quem está no Clube</h3>
        <?php /* A BUSCA DE PESSOA RECARREGA A PÁGINA, e é de propósito: nome de
             gente se digita inteiro e uma vez, não letra por letra como título
             de série — e o resultado é um link que dá pra mandar no grupo. */ ?>
        <form class="procura-nome" method="get">
          <input type="hidden" name="midia" value="series">
          <input type="hidden" name="aba" value="pessoas">
          <input type="search" name="nome" value="<?= h($termoPessoa) ?>"
                 placeholder="Procurar pelo nome" aria-label="Procurar pessoa pelo nome"
                 autocomplete="off">
          <button class="btn pri" type="submit"><i class="bi bi-search"></i> Procurar</button>
        </form>

        <?php $gente = seriesPessoas($pdo, $termoPessoa); ?>
        <?php if (!$gente): ?>
          <div class="vazio">
            <?= $termoPessoa !== ''
                ? 'Ninguém com esse nome marcou série ainda.'
                : 'Ninguém marcou nada ainda. Seja o primeiro.' ?>
          </div>
        <?php else: ?>
          <div class="gente">
            <?php foreach ($gente as $g): ?>
              <a href="?midia=series&amp;aba=pessoas&amp;u=<?= (int)$g['id'] ?>">
                <img class="av g" src="<?= h(clubeAvatar($g['photo_url'])) ?>" alt=""
                     onerror="this.src='/img/default-avatar.png'">
                <div style="min-width:0">
                  <div class="nm"><?= h($g['nome']) ?></div>
                  <div class="sb"><?= (int)$g['assistidas'] ?> assistidas
                    <?php if ($g['nota_media'] !== null): ?>
                      · média <?= h(number_format((float)$g['nota_media'], 1, ',', '')) ?>
                    <?php endif; ?>
                  </div>
                  <div class="sb"><?= (int)$g['assistindo'] ?> vendo · <?= (int)$g['quero'] ?> na fila</div>
                </div>
              </a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    <?php endif; ?>

  <?php else: ?>
    <?php /* ── A LIGA ───────────────────────────────────────────────────
         Três rankings, o movimento e, embaixo, tudo que alguém daqui já viu.
         A ordem é a da pergunta: primeiro o que a liga achou, depois quem
         fez o quê, e por último a lista pra procurar uma série específica. */ ?>
    <div class="tri">
      <?php
      $rankings = [
          'nota'   => ['Mais bem avaliadas', 'star-fill',     'nota'],
          'vistas' => ['Mais assistidas',    'eye-fill',      'pessoas'],
          'querem' => ['Mais na fila',       'bookmark-fill', 'pessoas'],
      ];
      foreach ($rankings as $tipo => [$titulo, $ico, $unidade]):
          $lista = seriesRankingDaLiga($pdo, $tipo, 10);
      ?>
        <div class="bloco">
          <h3><i class="bi bi-<?= $ico ?>"></i> <?= h($titulo) ?></h3>
          <?php if (!$lista): ?>
            <div class="vazio"><?= $tipo === 'nota'
                ? 'Ainda não tem série com ' . SERIES_RANKING_MIN . ' notas da liga.'
                : 'Ninguém marcou nada ainda.' ?></div>
          <?php else: ?>
            <?php foreach ($lista as $i => $s): ?>
              <button class="rk <?= $tipo ?>" data-serie="<?= (int)$s['id'] ?>">
                <span class="rk-p"><?= $i + 1 ?></span>
                <?php $u = seriesPoster($s['poster']); ?>
                <img src="<?= h($u ?: '') ?>" alt="" loading="lazy">
                <span class="rk-t"><?= h($s['titulo']) ?></span>
                <span class="rk-v"><?= $tipo === 'nota'
                      ? h(number_format((float)$s['valor'], 1, ',', ''))
                      : (int)$s['valor'] ?><?php if ($tipo !== 'nota'): ?><small>
                      <?= (int)$s['valor'] === 1 ? 'pessoa' : 'pessoas' ?></small><?php endif; ?></span>
              </button>
            <?php endforeach; ?>
            <?php if ($tipo === 'nota'): ?>
              <div style="font-size:11px;color:var(--txt3);margin-top:9px">
                Entra com <?= SERIES_RANKING_MIN ?> notas ou mais — uma nota só não é média de ninguém.
              </div>
            <?php endif; ?>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="bloco">
      <h3><i class="bi bi-activity"></i> O que a liga andou fazendo</h3>
      <?php $mov = seriesMovimentoDaLiga($pdo, 25); ?>
      <?php if (!$mov): ?>
        <div class="vazio">Ninguém marcou nada ainda. Seja o primeiro.</div>
      <?php else: ?>
        <?php foreach ($mov as $m): ?>
          <div class="linha-liga">
            <?php $u = seriesPoster($m['poster']); ?>
            <img class="capinha" src="<?= h($u ?: '') ?>" alt="" loading="lazy"
                 data-serie="<?= (int)$m['id'] ?>">
            <div class="corpo">
              <div class="diz-cab">
                <img class="av" src="<?= h(clubeAvatar($m['photo_url'])) ?>" alt=""
                     onerror="this.src='/img/default-avatar.png'">
                <b><?= h($m['quem']) ?></b>
                <span class="oq"><?= h(CLUBE_VERBO[$m['estado']] ?? '') ?></span>
                <b style="color:var(--acento)"><?= h($m['titulo']) ?></b>
              </div>
              <?php if (!empty($m['comentario'])): ?>
                <div class="diz-txt">“<?= h($m['comentario']) ?>”</div>
              <?php endif; ?>
            </div>
            <?php if ($m['nota'] !== null): ?><span class="n"><?= (int)$m['nota'] ?></span><?php endif; ?>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <div class="bloco">
      <?php $tudo = seriesQueALigaTocou($pdo, $ordem, 120); ?>
      <h3><i class="bi bi-list-ul"></i> Tudo que a liga já viu
        <span class="conta"><?= count($tudo) ?></span></h3>
      <?php /* SÓ O QUE ALGUÉM DAQUI TOCOU. O catálogo tem mil e seiscentas e
           listá-las aqui seria repetir a aba Catálogo com outro nome — o que
           esta lista tem de próprio é o recorte da liga. */ ?>
      <div class="ordena">
        <?php foreach (['movimento' => 'Mais recentes', 'nota' => 'Nota da liga',
                        'pessoas' => 'Mais gente', 'titulo' => 'A–Z'] as $k => $rot): ?>
          <a href="?midia=series&amp;aba=liga&amp;ord=<?= $k ?>"
             class="<?= $ordem === $k ? 'on' : '' ?>"><?= h($rot) ?></a>
        <?php endforeach; ?>
      </div>
      <?php if (!$tudo): ?>
        <div class="vazio">Ninguém marcou nada ainda.</div>
      <?php else: ?>
        <?php foreach ($tudo as $s): ?>
          <button class="linha-serie" data-serie="<?= (int)$s['id'] ?>">
            <?php $u = seriesPoster($s['poster']); ?>
            <img src="<?= h($u ?: '') ?>" alt="" loading="lazy">
            <span class="ls-c">
              <span class="ls-t"><?= h($s['titulo']) ?>
                <span style="color:var(--txt3);font-weight:600;font-size:11.5px">
                  <?= h(seriesAnos($s['ano_inicio'] ? (int)$s['ano_inicio'] : null,
                                   $s['ano_fim'] ? (int)$s['ano_fim'] : null,
                                   !empty($s['em_exibicao']))) ?></span>
              </span>
              <span class="ls-s">
                <span><i class="bi bi-people-fill"></i> <?= (int)$s['pessoas'] ?></span>
                <span><i class="bi bi-star-fill"></i> <?= (int)$s['avaliacoes'] ?>
                  <?= (int)$s['avaliacoes'] === 1 ? 'nota' : 'notas' ?></span>
                <?php if ((int)$s['recados'] > 0): ?>
                  <span><i class="bi bi-chat-fill"></i> <?= (int)$s['recados'] ?>
                    <?= (int)$s['recados'] === 1 ? 'recado' : 'recados' ?></span>
                <?php endif; ?>
                <?php if ($s['nota_imdb'] !== null): ?>
                  <span style="color:var(--imdb)"><i class="bi bi-star-fill"></i>
                    <?= h(number_format((float)$s['nota_imdb'], 1, ',', '')) ?> IMDb</span>
                <?php endif; ?>
              </span>
            </span>
            <span class="ls-n"><?= $s['nota_fba'] !== null
                ? h(number_format((float)$s['nota_fba'], 1, ',', '')) : '—' ?></span>
          </button>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  <?php endif; ?>
<?php endif; ?>
</div>

<?php
/**
 * O cartão de uma série na grade.
 *
 * Mora numa função porque a grade nasce em vários lugares — catálogo, as três
 * listas de "minhas", o top do perfil e o perfil de outra pessoa — e escrito
 * em cada um ia divergir no primeiro ajuste de layout.
 */
function cartaoDeSerie(array $s, bool $comPosicao = false): string
{
    $url = seriesPoster($s['poster']);
    $ico = ['assistida' => 'check-lg', 'assistindo' => 'play-fill', 'quero' => 'bookmark-fill'];

    $html = '<button class="card" data-serie="' . (int)$s['id'] . '">'
          . '<span class="capa">'
          . ($url ? '<img src="' . h($url) . '" alt="" loading="lazy">'
                  : '<span class="vazia"><i class="bi bi-tv"></i></span>');

    if ($s['nota_imdb'] !== null) {
        $html .= '<span class="selo imdb"><i class="bi bi-star-fill"></i>'
               . number_format((float)$s['nota_imdb'], 1, ',', '') . '</span>';
    }
    if (!empty($s['estado'])) {
        $html .= '<span class="marca-estado ' . h($s['estado']) . '">'
               . '<i class="bi bi-' . ($ico[$s['estado']] ?? 'dot') . '"></i></span>';
    }
    if (!empty($s['minha_nota'])) {
        $html .= '<span class="minha-nota">' . (int)$s['minha_nota'] . '</span>';
    }
    if ($comPosicao && !empty($s['favorita'])) {
        $html .= '<span class="pos">' . (int)$s['favorita'] . '</span>';
    }

    return $html . '</span>'
         . '<span class="c-tit">' . h($s['titulo']) . '</span>'
         . '<span class="c-ano">'
         . h(seriesAnos($s['ano_inicio'] ? (int)$s['ano_inicio'] : null,
                        $s['ano_fim'] ? (int)$s['ano_fim'] : null, !empty($s['em_exibicao'])))
         . '</span></button>';
}

/**
 * O DIÁRIO DE UMA PESSOA — os números, o top e as listas.
 *
 * Serve pro meu perfil e pra visita, e é a mesma função nos dois: o perfil de
 * outro não pode ser uma versão empobrecida do meu, porque é ele que responde
 * "o que o cara andou vendo", que é a pergunta que traz gente pra cá.
 *
 * @param int|null $de null = sou eu; as listas por estado só entram na visita,
 *                     porque no meu caso a aba "Minhas séries" já as mostra.
 */
function diarioDe(PDO $pdo, array $perfil, ?int $de): string
{
    $meu = $de === null;
    ob_start();
    ?>
    <div class="bloco">
      <h3><i class="bi bi-person-badge-fill"></i> <?= $meu ? 'Meu diário' : 'Os números' ?></h3>
      <div class="fichas">
        <div class="ficha"><div class="v" <?= $meu ? 'id="pAssistidas"' : '' ?>><?= (int)$perfil['assistidas'] ?></div><div class="r">assistidas</div></div>
        <div class="ficha"><div class="v" <?= $meu ? 'id="pAssistindo"' : '' ?>><?= (int)$perfil['assistindo'] ?></div><div class="r">assistindo</div></div>
        <div class="ficha"><div class="v" <?= $meu ? 'id="pQuero"' : '' ?>><?= (int)$perfil['quero'] ?></div><div class="r">quero ver</div></div>
        <div class="ficha"><div class="v" <?= $meu ? 'id="pNota"' : '' ?>><?= $perfil['nota_media'] !== null
              ? number_format((float)$perfil['nota_media'], 1, ',', '') : '—' ?></div>
          <div class="r"><?= $meu ? 'sua nota média' : 'nota média' ?></div></div>
      </div>
    </div>

    <div class="bloco">
      <h3><i class="bi bi-star-fill"></i> Top <?= SERIES_TOP ?></h3>
      <?php if (!$perfil['top']): ?>
        <div class="vazio"><?= $meu
            ? 'Abra uma série que você já assistiu e toque em Top ' . SERIES_TOP . '.'
            : 'Não montou o top ainda.' ?></div>
      <?php else: ?>
        <div class="top-lista"><?php foreach ($perfil['top'] as $s) echo cartaoDeSerie($s, true); ?></div>
      <?php endif; ?>
    </div>

    <?php if (!$meu): ?>
      <?php foreach (SERIES_ESTADOS as $chave => $rotulo): ?>
        <?php $lista = seriesMinhas($pdo, $de, $chave); ?>
        <div class="bloco">
          <h3>
            <i class="bi bi-<?= $chave === 'assistida' ? 'check-circle-fill'
                              : ($chave === 'assistindo' ? 'play-circle-fill' : 'bookmark-fill') ?>"></i>
            <?= h($rotulo) ?>
            <span class="conta"><?= count($lista) ?></span>
          </h3>
          <?php if (!$lista): ?>
            <div class="vazio">Nada aqui.</div>
          <?php else: ?>
            <div class="grade"><?php foreach ($lista as $s) echo cartaoDeSerie($s); ?></div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
    <?php
    return (string)ob_get_clean();
}
?>

<?php if ($idUsuario > 0 && $midia === 'series' && $catalogo > 0): ?>
<?php /* ── A FICHA DA SÉRIE ─────────────────────────────────────────────
     Um popup e não uma página: marcar é um gesto de meio segundo, e ir e
     voltar de página a cada série perderia o lugar na grade. */ ?>
<div class="fundo" id="popSerie" hidden>
  <div class="pop">
    <div class="pop-topo">
      <div class="pop-capa" id="fCapa"></div>
      <div style="min-width:0;flex:1">
        <div class="pop-tit" id="fTitulo"></div>
        <div class="pop-sub" id="fSub"></div>
        <div class="pop-gen" id="fGen"></div>
        <div class="notas">
          <div class="nota-caixa imdb"><div class="n" id="fImdb">—</div><div class="r">IMDb</div></div>
          <div class="nota-caixa fba"><div class="n" id="fFba">—</div><div class="r" id="fFbaR">FBA</div></div>
          <div class="nota-caixa eu"><div class="n" id="fEu">—</div><div class="r">você</div></div>
        </div>
      </div>
    </div>

    <div class="pop-sin" id="fSinopse"></div>

    <div class="rot">Onde essa entra</div>
    <div class="estados" id="fEstados">
      <?php foreach (SERIES_ESTADOS as $k => $rot): ?>
        <button class="est" data-estado="<?= h($k) ?>"><?= h($rot) ?></button>
      <?php endforeach; ?>
    </div>

    <div class="rot" id="fRotNota">Sua nota</div>
    <div class="notinhas" id="fNotas">
      <?php for ($n = 1; $n <= 10; $n++): ?>
        <button class="notinha" data-nota="<?= $n ?>"><?= $n ?></button>
      <?php endfor; ?>
    </div>

    <?php /* NOTA É UM NÚMERO E NÚMERO NÃO CONTA POR QUE. "8" pode ser
         decepção de quem esperava 10 e euforia de quem esperava 5 — e é essa
         frase que faz a lista da liga valer a leitura. */ ?>
    <div class="rot" id="fRotRecado">Seu recado</div>
    <div class="caixa-recado">
      <div style="flex:1;min-width:0">
        <textarea id="fRecadoTxt" maxlength="280" rows="2"
                  placeholder="Uma linha sobre ela — o que te pegou, o que te irritou."></textarea>
        <div class="sobra"><span id="fSobra">280</span> sobrando</div>
      </div>
      <button class="btn" id="fSalvaRecado"><i class="bi bi-check-lg"></i> Salvar</button>
    </div>

    <div class="rot">O que a liga diz <span class="conta" id="fQuantos"></span></div>
    <div class="diz" id="fDiz"></div>

    <div class="recado" id="fRecado"></div>

    <div class="pop-acoes">
      <?php /* O BOTÃO DIZ O DESTINO, não o sentimento: "Favorita" não contava
           que existe uma lista, nem que ela tem tamanho. */ ?>
      <button class="btn fav" id="fFav"><i class="bi bi-star"></i>
        <span>Top <?= SERIES_TOP ?></span></button>
      <button class="btn pri" data-fechar>Pronto</button>
    </div>
  </div>
</div>

<?php /* O POPUP DE CONFIRMAR. Tirar do perfil apaga nota, recado e a vaga no
     top junto — é pouco pra pedir F5 de volta e demais pra fazer calado. */ ?>
<div class="fundo" id="popPergunta" hidden>
  <div class="pop pergunta">
    <h4 id="pqTit">Tirar do seu perfil?</h4>
    <p id="pqTxt"></p>
    <div class="pop-acoes">
      <button class="btn" data-nao>Deixa</button>
      <button class="btn perigo" id="pqSim"><i class="bi bi-x-lg"></i> Tirar</button>
    </div>
  </div>
</div>

<script>
(function () {
  var POSTER = <?= json_encode(SERIES_IMG_BASE . SERIES_POSTER_G) ?>;
  var ESTADOS = <?= json_encode(SERIES_ESTADOS, JSON_UNESCAPED_UNICODE) ?>;
  var VERBO = <?= json_encode(CLUBE_VERBO, JSON_UNESCAPED_UNICODE) ?>;
  var ICONE = {assistida: 'check-lg', assistindo: 'play-fill', quero: 'bookmark-fill'};
  var TOP = <?= SERIES_TOP ?>;
  var EU = <?= (int)$idUsuario ?>;
  var LIMITE_RECADO = 280;

  var pop = document.getElementById('popSerie');
  var grade = document.getElementById('grade');
  var atual = null;                       // a série aberta no popup
  var quem = [];                          // quem da liga já mexeu nela

  function $(id) { return document.getElementById(id); }
  function num(v) { return v === null || v === undefined || v === '' ? null : Number(v); }
  function umaCasa(v) { return v === null ? '—' : String(v).replace('.', ','); }
  function escapa(t) {
    return String(t).replace(/[&<>"]/g, function (c) {
      return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;'}[c];
    });
  }
  function avatar(u) { return u && String(u).trim() !== '' ? u : '/img/default-avatar.png'; }

  /* ── O cartão, igual ao do PHP ──────────────────────────────────────
     Ele nasce nos dois lados: no servidor, pras grades que já vêm prontas, e
     aqui, pra busca que troca a grade sem recarregar. */
  function cartao(s) {
    var capa = s.poster
      ? '<img src="' + POSTER.replace('w500', 'w342') + s.poster + '" alt="" loading="lazy">'
      : '<span class="vazia"><i class="bi bi-tv"></i></span>';
    var selo = s.nota_imdb !== null
      ? '<span class="selo imdb"><i class="bi bi-star-fill"></i>' + umaCasa(s.nota_imdb) + '</span>' : '';
    var est = s.estado
      ? '<span class="marca-estado ' + s.estado + '"><i class="bi bi-' + (ICONE[s.estado] || 'dot') + '"></i></span>' : '';
    var minha = s.minha_nota ? '<span class="minha-nota">' + s.minha_nota + '</span>' : '';
    var ano = s.ano_inicio ? (s.em_exibicao == 1 ? s.ano_inicio + '–'
             : (s.ano_fim && s.ano_fim != s.ano_inicio ? s.ano_inicio + '–' + s.ano_fim : s.ano_inicio)) : '';
    return '<button class="card" data-serie="' + s.id + '"><span class="capa">'
         + capa + selo + est + minha + '</span>'
         + '<span class="c-tit">' + escapa(s.titulo) + '</span>'
         + '<span class="c-ano">' + ano + '</span></button>';
  }

  /* ── A busca ────────────────────────────────────────────────────────
     Uma consulta por PAUSA na digitação, não por tecla: quem escreve "breaking
     bad" dispararia doze idas ao servidor e jogaria onze fora. */
  var busca = document.getElementById('busca');
  var relogio = null;
  if (busca && grade) {
    busca.addEventListener('input', function () {
      clearTimeout(relogio);
      relogio = setTimeout(carregarGrade, 220);
    });
  } else if (busca) {
    /* FORA DO CATÁLOGO A BUSCA VIRA UM ATALHO. A caixa fica no topo em todas
       as abas porque procurar série é o gesto mais comum da página; onde não
       tem grade pra trocar, o Enter leva pro catálogo já procurando. */
    busca.addEventListener('keydown', function (e) {
      if (e.key !== 'Enter') return;
      e.preventDefault();
      location.href = '?midia=series&aba=catalogo&q=' + encodeURIComponent(busca.value.trim());
    });
  }

  /* O CATÁLOGO TEM FUNDO FALSO. O importador trouxe as mil e seiscentas mais
     conhecidas; quem procurar a série obscura que só ele assiste não acharia.
     Então: a busca local responde na hora e, se veio pouca coisa, a página
     pergunta ao TMDB — e a partir daí aquela série existe pra liga inteira.

     O limite é POUCO, não ZERO: quem digita "dark" acha um punhado de coisas
     com "dark" no nome e mesmo assim quer a série alemã. */
  var MINIMO_LOCAL = 5;
  var buscaEmCurso = 0;

  function carregarGrade() {
    if (!grade) return;
    var q = busca ? busca.value.trim() : '';
    var meuTurno = ++buscaEmCurso;

    fetch(location.pathname + '?json=busca&q=' + encodeURIComponent(q))
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (meuTurno !== buscaEmCurso) return;    // já digitaram outra coisa
        var lista = pintarLista(d.series || [], q);

        if (q.length >= 3 && lista.length < MINIMO_LOCAL) {
          $('gradeFora').hidden = false;
          $('gradeVazia').hidden = true;
          fetch(location.pathname + '?json=busca_fora&q=' + encodeURIComponent(q))
            .then(function (r) { return r.json(); })
            .then(function (d2) {
              if (meuTurno !== buscaEmCurso) return;
              $('gradeFora').hidden = true;
              pintarLista(d2.series || [], q);
            })
            .catch(function () { $('gradeFora').hidden = true; });
        }
      })
      .catch(function () {});
  }

  function pintarLista(lista, q) {
    grade.innerHTML = lista.map(cartao).join('');
    $('gradeVazia').hidden = lista.length > 0;
    $('tituloGrade').textContent = q ? 'Resultados de "' + q + '"' : 'Mais populares';
    return lista;
  }
  if (grade) carregarGrade();

  /* ── Abrir a ficha ──────────────────────────────────────────────────
     Qualquer coisa com data-serie abre: o cartão da grade, a linha do
     ranking, a linha da lista da liga e o pôster do feed. */
  document.addEventListener('click', function (e) {
    var c = e.target.closest('[data-serie]');
    if (!c) return;
    abrir(Number(c.dataset.serie));
  });

  function abrir(id) {
    fetch(location.pathname + '?json=serie&id=' + id)
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d.serie) return;
        atual = d.serie;
        quem = d.quem || [];
        pintar();
        pop.hidden = false;
      });
  }

  function pintar() {
    var s = atual;
    $('fCapa').innerHTML = s.poster
      ? '<img src="' + POSTER + s.poster + '" alt="">'
      : '<span class="vazia" style="display:flex;align-items:center;justify-content:center;height:100%"><i class="bi bi-tv"></i></span>';
    $('fTitulo').textContent = s.titulo;

    var partes = [];
    var ano = s.ano_inicio ? (s.em_exibicao == 1 ? s.ano_inicio + '–'
             : (s.ano_fim && s.ano_fim != s.ano_inicio ? s.ano_inicio + '–' + s.ano_fim : s.ano_inicio)) : '';
    if (ano) partes.push(ano);
    if (s.temporadas) partes.push(s.temporadas + (s.temporadas == 1 ? ' temporada' : ' temporadas'));
    if (s.episodios) partes.push(s.episodios + ' episódios');
    if (s.titulo_original && s.titulo_original !== s.titulo) partes.push(s.titulo_original);
    $('fSub').textContent = partes.join(' · ');
    $('fGen').textContent = s.generos || '';
    $('fSinopse').textContent = s.sinopse || 'Sem sinopse no catálogo.';

    $('fImdb').textContent = umaCasa(num(s.nota_imdb));
    $('fFba').textContent  = umaCasa(num(s.nota_fba));
    $('fFbaR').textContent = Number(s.votos_fba) > 0
      ? 'FBA · ' + s.votos_fba + (Number(s.votos_fba) === 1 ? ' nota' : ' notas') : 'FBA';
    $('fEu').textContent   = s.minha_nota ? s.minha_nota : '—';

    document.querySelectorAll('#fEstados .est').forEach(function (b) {
      b.classList.toggle('on', b.dataset.estado === s.estado);
    });

    /* SÓ QUEM ASSISTIU AVALIA — e a tela diz por quê antes de a pessoa
       tentar, em vez de recusar depois do clique. O recado segue a nota: quem
       só pôs na fila não tem o que contar ainda. */
    var podeAvaliar = s.estado === 'assistida' || s.estado === 'assistindo';
    document.querySelectorAll('#fNotas .notinha').forEach(function (b) {
      b.disabled = !podeAvaliar;
      b.classList.toggle('on', podeAvaliar && Number(b.dataset.nota) === Number(s.minha_nota));
    });
    $('fRotNota').textContent = podeAvaliar
      ? 'Sua nota' : 'Sua nota — marque como assistida pra poder dar';

    var txt = $('fRecadoTxt');
    txt.value = s.meu_recado || '';
    txt.disabled = !podeAvaliar;
    $('fSalvaRecado').disabled = !podeAvaliar;
    $('fRotRecado').textContent = podeAvaliar
      ? 'Seu recado' : 'Seu recado — marque como assistida pra poder escrever';
    contarSobra();

    pintarQuem();

    var fav = $('fFav');
    fav.classList.toggle('on', !!s.favorita);
    fav.querySelector('i').className = s.favorita ? 'bi bi-star-fill' : 'bi bi-star';
    fav.querySelector('span').textContent = s.favorita
      ? s.favorita + 'º no seu top' : 'Top ' + TOP;
    $('fRecado').textContent = '';
  }

  /* ── O QUE A LIGA DIZ ───────────────────────────────────────────────
     É o que transforma a ficha de uma página de catálogo numa conversa. Quem
     escreveu vem primeiro (o servidor já ordena assim), e a minha linha fica
     marcada pra eu achar o meu recado sem ler a lista inteira. */
  function pintarQuem() {
    var alvo = $('fDiz');
    $('fQuantos').textContent = quem.length
      ? quem.length + (quem.length === 1 ? ' pessoa' : ' pessoas') : '';
    if (!quem.length) {
      alvo.innerHTML = '<div class="vazio">Ninguém da liga marcou essa ainda.</div>';
      return;
    }
    alvo.innerHTML = quem.map(function (p) {
      var eu = Number(p.user_id) === EU;
      var nota = p.nota !== null && p.nota !== undefined
        ? '<span class="nota-pino">' + p.nota + '</span>' : '';
      var txt = p.comentario
        ? '<div class="diz-txt">“' + escapa(p.comentario) + '”</div>' : '';
      return '<div class="diz-um' + (eu ? ' diz-eu' : '') + '">'
           + '<img class="av" src="' + escapa(avatar(p.photo_url)) + '" alt=""'
           + ' onerror="this.src=\'/img/default-avatar.png\'">'
           + '<div style="flex:1;min-width:0">'
           + '<div class="diz-cab"><b>' + escapa(eu ? 'Você' : p.nome) + '</b>'
           + '<span class="oq">' + (VERBO[p.estado] || '') + '</span>'
           + (p.league ? '<span class="pino">' + escapa(p.league) + '</span>' : '')
           + '</div>' + txt + '</div>' + nota + '</div>';
    }).join('');
  }

  /* ── As ações ───────────────────────────────────────────────────────── */
  function manda(corpo) {
    return fetch(location.pathname, {method: 'POST', body: new URLSearchParams(corpo)})
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d.ok) { $('fRecado').textContent = d.erro || 'Não deu.'; return null; }
        $('fRecado').textContent = '';
        atualizarPerfil(d.perfil);
        return d;
      })
      .catch(function () { $('fRecado').textContent = 'Sem resposta do servidor.'; return null; });
  }

  document.getElementById('fEstados').addEventListener('click', function (e) {
    var b = e.target.closest('.est');
    if (!b || !atual) return;
    manda({acao: 'marcar', serie: atual.id, estado: b.dataset.estado}).then(function (d) {
      if (!d) return;
      atual.estado = d.estado;
      /* Sair da lista leva nota, recado e favorita junto — o servidor já fez
         isso, e a tela tem que contar a mesma história. */
      if (!d.estado || d.estado === 'quero') {
        atual.minha_nota = null; atual.meu_recado = null; atual.favorita = null;
      }
      recarregarQuem();
      pintar(); recarregarGrade();
    });
  });

  document.getElementById('fNotas').addEventListener('click', function (e) {
    var b = e.target.closest('.notinha');
    if (!b || b.disabled || !atual) return;
    manda({acao: 'avaliar', serie: atual.id, nota: b.dataset.nota}).then(function (d) {
      if (!d) return;
      atual.minha_nota = d.nota;
      atual.nota_fba = d.media;
      atual.votos_fba = d.votos;
      recarregarQuem();
      pintar(); recarregarGrade();
    });
  });

  /* ── O recado ───────────────────────────────────────────────────────── */
  var caixa = $('fRecadoTxt');
  caixa.addEventListener('input', contarSobra);
  function contarSobra() {
    $('fSobra').textContent = Math.max(0, LIMITE_RECADO - caixa.value.length);
  }

  $('fSalvaRecado').addEventListener('click', function () {
    if (!atual) return;
    var b = this;
    b.disabled = true;
    fetch(location.pathname, {
      method: 'POST',
      body: new URLSearchParams({acao: 'comentar', serie: atual.id, texto: caixa.value})
    })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        b.disabled = false;
        if (!d.ok) { $('fRecado').textContent = d.erro || 'Não deu.'; return; }
        $('fRecado').textContent = '';
        atual.meu_recado = d.comentario;
        recarregarQuem();
      })
      .catch(function () { b.disabled = false; $('fRecado').textContent = 'Sem resposta do servidor.'; });
  });

  /* A lista da liga fica velha assim que eu mexo na minha linha. Em vez de
     remendar o array na mão — e arriscar mostrar uma coisa e o banco ter
     outra — pergunta de novo, que é uma consulta pequena. */
  function recarregarQuem() {
    if (!atual) return;
    fetch(location.pathname + '?json=serie&id=' + atual.id)
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d.serie || !atual || d.serie.id != atual.id) return;
        quem = d.quem || [];
        atual.nota_fba = d.serie.nota_fba;
        atual.votos_fba = d.serie.votos_fba;
        pintarQuem();
        $('fFba').textContent = umaCasa(num(atual.nota_fba));
      })
      .catch(function () {});
  }

  document.getElementById('fFav').addEventListener('click', function () {
    if (!atual) return;
    manda({acao: 'favoritar', serie: atual.id}).then(function (d) {
      if (!d) return;
      atual.favorita = d.favorita;
      pintar();
    });
  });

  function recarregarGrade() { if (grade) carregarGrade(); }

  /* O perfil muda a cada marca, e o número no topo do perfil não pode mentir
     até o próximo F5. */
  function atualizarPerfil(p) {
    if (!p) return;
    var mapa = {pAssistidas: p.assistidas, pAssistindo: p.assistindo, pQuero: p.quero};
    Object.keys(mapa).forEach(function (id) { if ($(id)) $(id).textContent = mapa[id]; });
    if ($('pNota')) $('pNota').textContent = p.nota_media === null ? '—' : umaCasa(p.nota_media);
  }

  /* ── Fechar ─────────────────────────────────────────────────────────── */
  var mexi = false;                       // marquei, avaliei ou escrevi algo?
  pop.addEventListener('click', function (e) {
    if (e.target === pop || e.target.hasAttribute('data-fechar')) fechar();
  });
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    /* A pergunta está por cima da ficha: o Escape fecha a de cima. */
    if (pq && !pq.hidden) { pq.hidden = true; pqQuando = null; return; }
    if (!pop.hidden) fechar();
  });
  function fechar() {
    pop.hidden = true;
    atual = null;
    quem = [];
    /* AS LISTAS DO SERVIDOR FICAM VELHAS depois de marcar: o top do perfil, os
       rankings da liga e a lista de tudo. Quem está nelas vê o certo ao
       fechar, sem precisar apertar F5 — e só recarrega se de fato mexeu. */
    if (!grade && mexi) location.reload();
  }
  ['fEstados', 'fNotas', 'fFav', 'fSalvaRecado'].forEach(function (id) {
    $(id).addEventListener('click', function () { mexi = true; });
  });

  /* ── O ACERVO: a nota na linha e o X ────────────────────────────────
     Duas ações que não passam pela ficha, porque quem entrou aqui entrou pra
     arrumar em lote: dez notas seriam dez popups, e tirar seria abrir a ficha
     pra desmarcar três vezes. */
  var acervo = $('acervo');
  if (acervo) {
    acervo.addEventListener('change', function (e) {
      var sel = e.target.closest('.ac-n');
      if (!sel) return;
      var linha = sel.closest('.ac-um');
      sel.disabled = true;
      fetch(location.pathname, {
        method: 'POST',
        body: new URLSearchParams({acao: 'avaliar', serie: sel.dataset.notaDe, nota: sel.value})
      })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          sel.disabled = false;
          if (!d.ok) { avisar('Não deu', d.erro || 'Tente de novo.'); return; }
          /* O servidor pode dizer outra coisa: clicar na mesma nota limpa. */
          sel.value = d.nota === null || d.nota === undefined ? '' : d.nota;
          sel.classList.toggle('deu', sel.value !== '');
          atualizarPerfil(d.perfil);
          /* Um pisca verde e pronto: recarregar a lista inteira jogaria a
             pessoa de volta pro topo no meio da arrumação. */
          linha.animate([{background: 'rgba(34,197,94,.16)'}, {background: 'transparent'}],
                        {duration: 600});
        })
        .catch(function () { sel.disabled = false; avisar('Sem resposta', 'O servidor não respondeu.'); });
    });

    acervo.addEventListener('click', function (e) {
      var x = e.target.closest('.ac-x');
      if (!x) return;
      e.stopPropagation();                      // não abre a ficha junto
      var linha = x.closest('.ac-um');
      pergunta('Tirar do seu perfil?',
               '“' + x.dataset.titulo + '” sai da sua lista, e a nota, o recado e a vaga '
               + 'no top vão com ela. Dá pra marcar de novo depois.',
               function () {
        linha.classList.add('saindo');
        fetch(location.pathname, {
          method: 'POST',
          body: new URLSearchParams({acao: 'tirar', serie: x.dataset.tirar})
        })
          .then(function (r) { return r.json(); })
          .then(function (d) {
            if (!d.ok) {
              linha.classList.remove('saindo');
              avisar('Não deu', d.erro || 'Tente de novo.');
              return;
            }
            linha.remove();
            atualizarPerfil(d.perfil);
            var conta = $('acConta');
            if (conta) conta.textContent = acervo.querySelectorAll('.ac-um').length;
            if (!acervo.querySelectorAll('.ac-um').length) location.reload();
          })
          .catch(function () {
            linha.classList.remove('saindo');
            avisar('Sem resposta', 'O servidor não respondeu.');
          });
      });
    });
  }

  /* ── PERGUNTAR E AVISAR, sempre no popup do jogo ─────────────────────
     confirm() e alert() do navegador não usam a cara da página, não dão pra
     escrever em português de gente e no celular vêm com o nome do site em
     cima. O jogo inteiro é assim; isto aqui segue a casa. */
  var pq = $('popPergunta'), pqQuando = null;
  function pergunta(titulo, texto, quando) {
    $('pqTit').textContent = titulo;
    $('pqTxt').textContent = texto;
    $('pqSim').hidden = false;
    $('pqSim').innerHTML = '<i class="bi bi-x-lg"></i> Tirar';
    pq.querySelector('[data-nao]').textContent = 'Deixa';
    pqQuando = quando;
    pq.hidden = false;
  }
  function avisar(titulo, texto) {
    $('pqTit').textContent = titulo;
    $('pqTxt').textContent = texto;
    $('pqSim').hidden = true;
    pq.querySelector('[data-nao]').textContent = 'Fechar';
    pqQuando = null;
    pq.hidden = false;
  }
  pq.addEventListener('click', function (e) {
    if (e.target === pq || e.target.hasAttribute('data-nao')) { pq.hidden = true; pqQuando = null; }
    if (e.target.closest('#pqSim')) {
      pq.hidden = true;
      var f = pqQuando; pqQuando = null;
      if (f) f();
    }
  });

  var topo = document.querySelector('.topo');
  addEventListener('scroll', function () { topo.classList.toggle('rolou', scrollY > 4); }, {passive: true});
})();
</script>
<?php endif; ?>
</body>
</html>
