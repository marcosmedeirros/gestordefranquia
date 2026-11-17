<?php
/**
 * POKÉMON PVP — a página do jogo.
 *
 * Cinco abas e uma quadra:
 *
 *   JOGAR    contra a máquina ou contra outro GM, por convite ou por fila,
 *            valendo moedas. Duas regras: ALEATÓRIA (seis sorteados, a
 *            coleção não conta) e MEU TIME (a escalação de cada um).
 *   COLEÇÃO  o que você tem e quem entra em quadra.
 *   LOJA     carta avulsa por moedas, pacote por FBA Points (3 por dia).
 *   RANKING  maior coleção e mais vitórias no PVP.
 *   GUIA     a tabela de tipos, cada Pokémon e cada golpe.
 *
 * Quando entra uma partida, a quadra toma a tela inteira: no meio de um
 * turno ninguém está comprando carta.
 *
 * A tela não sabe regra nenhuma. Não calcula dano, não decide se acertou e
 * não sabe o que o adversário escolheu — só acende o que o servidor disse
 * que pode e anima o que ele disse que aconteceu.
 *
 * @see games/core/psd_motor.php   as regras da partida
 * @see games/core/psd_online.php  a partida, o relógio e a aposta
 * @see games/core/psd_cartas.php  coleção, loja, pacotes e ranking
 */

require '../core/conexao.php';
require_once __DIR__ . '/../core/psd_online.php';

if (!isset($_SESSION['user_id'])) { header('Location: /login.php'); exit; }
$user_id = (int)$_SESSION['user_id'];
$meuNome = (string)($_SESSION['nome'] ?? $_SESSION['user_name'] ?? 'Você');

psdGarantirConta($pdo, $user_id);

/** Saldo das duas moedas, que toda tela mostra no topo. @see psdBolso */
function ppvpBolso(PDO $pdo, int $uid): array
{
    return psdBolso($pdo, $uid);
}

/** A coleção com tudo que a tela precisa pra desenhar as cartas. */
function ppvpColecao(PDO $pdo, int $uid): array
{
    $tenho = psdColecao($pdo, $uid);
    $cartas = [];
    foreach ($tenho as $e) {
        $c = psdCarta($e);
        if ($c) $cartas[] = $c;
    }
    usort($cartas, fn($a, $b) => $b['bst'] <=> $a['bst'] ?: strcmp($a['nome'], $b['nome']));

    return [
        'cartas'  => $cartas,
        'time'    => psdTime($pdo, $uid),
        'total'   => count($cartas),
        'na_dex'  => count(psdDex()),
        'bolso'   => ppvpBolso($pdo, $uid),
    ];
}

/**
 * A LOJA não manda as 569 de uma vez: manda a página pedida, já filtrada.
 * Mandar tudo seriam ~200 KB a cada abertura, e o telefone sente.
 */
function ppvpLoja(PDO $pdo, int $uid, string $busca, string $raridade, bool $soFaltam, int $pagina): array
{
    $tenho = array_flip(psdColecao($pdo, $uid));
    $busca = mb_strtolower(trim($busca));

    $lista = [];
    foreach (psdDex() as $id => $p) {
        if ($raridade !== '' && psdRaridade($id) !== $raridade) continue;
        if ($soFaltam && isset($tenho[$id])) continue;
        if ($busca !== '' && mb_strpos(mb_strtolower($p['nome']), $busca) === false) continue;

        $c = psdCarta($id);
        $c['tenho'] = isset($tenho[$id]);
        $lista[] = $c;
    }

    usort($lista, fn($a, $b) => $a['preco'] <=> $b['preco'] ?: strcmp($a['nome'], $b['nome']));

    $porPagina = 24;
    $total = count($lista);
    $pagina = max(0, min($pagina, (int)floor(max(0, $total - 1) / $porPagina)));

    return [
        'cartas'    => array_slice($lista, $pagina * $porPagina, $porPagina),
        'total'     => $total,
        'pagina'    => $pagina,
        'paginas'   => (int)ceil($total / $porPagina),
        'pacotes'   => PSD_PACOTES,
        'hoje'      => psdPacotesHoje($pdo, $uid),
        'teto_dia'  => PSD_PACOTES_DIA,
        'raridades' => PSD_RARIDADES,
        'bolso'     => ppvpBolso($pdo, $uid),
    ];
}

/**
 * O GUIA. A tabela de tipos e o índice vão inteiros (são pequenos); a
 * ficha de um Pokémon só sai quando alguém pede, porque ela carrega o
 * pool de golpes com os detalhes de cada um.
 */
function ppvpGuia(string $especie): array
{
    if ($especie === '') {
        $indice = [];
        foreach (psdDex() as $id => $p) {
            $indice[] = ['esp' => $id, 'num' => $p['num'], 'nome' => $p['nome'],
                         'tipos' => $p['tipos'], 'raridade' => psdRaridade($id)];
        }
        usort($indice, fn($a, $b) => $a['num'] <=> $b['num']);
        return ['tipos' => psdTipos(), 'indice' => $indice];
    }

    $dex = psdDex();
    if (!isset($dex[$especie])) return ['erro' => 'Não achei esse Pokémon.'];

    $p = $dex[$especie];
    $carta = psdCarta($especie);

    /* FRAQUEZA E RESISTÊNCIA: o que CADA tipo faz contra ele. É a conta
       que o jogador faz de cabeça na hora de escolher o golpe, e deixar
       ela pronta é metade da utilidade de um guia. */
    $tabela = psdTipos();
    $contra = [];
    foreach (array_keys($tabela) as $tipoAtacante) {
        $m = 1.0;
        foreach ($p['tipos'] as $meu) $m *= $tabela[$tipoAtacante][$meu] ?? 1.0;
        if ($m != 1.0) $contra[$tipoAtacante] = $m;
    }
    arsort($contra);

    $golpes = [];
    foreach ($p['pool'] as $gid) {
        $g = psdGolpe($gid);
        if (!$g) continue;
        $g['id']   = $gid;
        $g['stab'] = in_array($g['tipo'], $p['tipos'], true);
        $golpes[] = $g;
    }
    usort($golpes, fn($a, $b) => $b['power'] <=> $a['power']);

    return ['ficha' => $carta + ['contra' => $contra, 'golpes' => $golpes]];
}

// ─────────────────────────────── a API ───────────────────────────────

$acao = (string)($_REQUEST['acao'] ?? '');
if ($acao !== '') {
    header('Content-Type: application/json; charset=utf-8');
    $r = match ($acao) {
        'ver'      => psdVer($pdo, $user_id),
        'abrir'    => psdAbrir($pdo, $user_id, $meuNome,
                               in_array($_POST['modo'] ?? '', ['treino', 'fila', 'codigo'], true) ? $_POST['modo'] : 'treino',
                               (int)($_POST['aposta'] ?? 0),
                               (string)($_POST['regra'] ?? 'aleatoria')),
        'entrar'   => psdEntrar($pdo, $user_id, $meuNome, (string)($_POST['codigo'] ?? '')),
        'escolher' => psdEscolher($pdo, $user_id, ['tipo' => (string)($_POST['tipo'] ?? 'golpe'), 'i' => (int)($_POST['i'] ?? 0)]),
        'desistir' => psdDesistir($pdo, $user_id),
        'cancelar' => psdDesistirDaEspera($pdo, $user_id),

        'colecao'     => ppvpColecao($pdo, $user_id),
        'salvar_time' => psdSalvarTime($pdo, $user_id, json_decode((string)($_POST['time'] ?? '[]'), true) ?: []),
        'loja'        => ppvpLoja($pdo, $user_id, (string)($_REQUEST['busca'] ?? ''),
                                  (string)($_REQUEST['raridade'] ?? ''),
                                  !empty($_REQUEST['faltam']), (int)($_REQUEST['pagina'] ?? 0)),
        'comprar'     => psdComprarCarta($pdo, $user_id, (string)($_POST['especie'] ?? '')),
        'pacote'      => psdAbrirPacote($pdo, $user_id, (string)($_POST['tipo'] ?? 'basico')),
        'ranking'     => ['colecao' => psdRankingColecao($pdo), 'vitorias' => psdRankingVitorias($pdo)],
        'guia'        => ppvpGuia((string)($_REQUEST['especie'] ?? '')),

        default    => ['ok' => false, 'erro' => 'Ação desconhecida.'],
    };
    echo json_encode($r, JSON_UNESCAPED_UNICODE);
    exit;
}

$bolso = ppvpBolso($pdo, $user_id);
/* Convite aberto pelo link: a tela entra sozinha quando carregar. */
$convite = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)($_GET['c'] ?? '')));
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Pokémon PVP</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<style>
  :root{
    --bg:#0e1016; --painel:#161a23; --linha:#242a37; --txt:#e8ecf5; --fraco:#8d97ab;
    --hp-verde:#49d049; --hp-amarelo:#f5c542; --hp-vermelho:#ef4444; --acento:#f5c542;
    --normal:#A8A878;--fire:#F08030;--water:#6890F0;--electric:#F8D030;--grass:#78C850;
    --ice:#98D8D8;--fighting:#C03028;--poison:#A040A0;--ground:#E0C068;--flying:#A890F0;
    --psychic:#F85888;--bug:#A8B820;--rock:#B8A038;--ghost:#705898;--dragon:#7038F8;
    --dark:#705848;--steel:#B8B8D0;--fairy:#EE99AC;--stellar:#7bd6c4;
  }
  *{box-sizing:border-box}
  body{margin:0;background:var(--bg);color:var(--txt);
       font:14px/1.45 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;
       display:flex;justify-content:center;padding:14px 14px 40px}
  .jogo{width:100%;max-width:560px}
  button{font:inherit;color:inherit}

  .topo{display:flex;align-items:center;gap:9px;margin-bottom:12px}
  .topo .voltar{background:none;border:0;color:var(--fraco);cursor:pointer;padding:4px 6px 4px 0;font-size:17px}
  .topo b{font-size:16px;letter-spacing:.3px}
  .topo .saldo{background:var(--painel);border:1px solid var(--linha);
               border-radius:999px;padding:4px 11px;font-weight:700;font-size:12.5px;
               font-variant-numeric:tabular-nums;white-space:nowrap}
  .topo .saldo:first-of-type{margin-left:auto}
  .topo .saldo i{color:var(--acento)}
  .topo .saldo.fbap i{color:#5ec8f5}

  /* ── AS ABAS ───────────────────────────────────────────────────────
     Rolam de lado no telefone em vez de quebrarem em duas linhas: cinco
     abas em 360px não cabem, e duas linhas empurrariam a quadra pra
     fora da primeira tela. */
  .abas{display:flex;gap:6px;margin-bottom:12px;overflow-x:auto;scrollbar-width:none;
        -webkit-overflow-scrolling:touch}
  .abas::-webkit-scrollbar{display:none}
  .abas button{flex:none;background:var(--painel);border:1px solid var(--linha);
               border-radius:999px;padding:6px 13px;font-size:12.5px;font-weight:600;
               cursor:pointer;white-space:nowrap;color:var(--fraco);transition:all .15s}
  .abas button:hover{color:var(--txt)}
  .abas button.on{background:var(--acento);border-color:var(--acento);color:#15181f}
  .abas.escondida{display:none}

  /* ── SALÃO ────────────────────────────────────────────────── */
  .cartao{background:var(--painel);border:1px solid var(--linha);border-radius:12px;
          padding:14px;margin-bottom:11px}
  .cartao h3{margin:0 0 4px;font-size:14.5px;display:flex;align-items:center;gap:7px}
  .cartao p{margin:0 0 11px;font-size:12.5px;color:var(--fraco);line-height:1.5}
  .bt{display:block;width:100%;background:#1e2533;border:1px solid var(--linha);
      border-radius:9px;padding:10px;font-weight:700;font-size:13.5px;cursor:pointer;
      transition:background .15s,transform .08s}
  .bt:hover{background:#273045}
  .bt:active{transform:translateY(1px)}
  .bt.ouro{background:var(--acento);color:#15181f;border-color:var(--acento)}
  .bt.ouro:hover{filter:brightness(1.08)}
  .bt:disabled{opacity:.45;cursor:not-allowed}
  .bt.perigo{background:#2a1a1d;border-color:#582a30;color:#ffb3bb}

  .linha2{display:flex;gap:8px}
  .linha2 .bt{flex:1}
  input[type=text],input[type=number]{width:100%;background:#0f131b;border:1px solid var(--linha);
      border-radius:9px;padding:10px;color:var(--txt);font:inherit;margin-bottom:9px}
  input::placeholder{color:#5c6577}
  .fichas{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:10px}
  .ficha{background:#0f131b;border:1px solid var(--linha);border-radius:999px;
         padding:5px 12px;font-size:12px;font-weight:700;cursor:pointer}
  .ficha.on{background:var(--acento);color:#15181f;border-color:var(--acento)}

  .codigo{font-size:30px;font-weight:800;letter-spacing:7px;text-align:center;
          background:#0f131b;border:1px dashed var(--linha);border-radius:10px;padding:14px;margin:10px 0}

  /* ── QUADRA ───────────────────────────────────────────────── */
  .barraTopo{display:flex;align-items:center;gap:8px;margin-bottom:8px;font-size:12px;color:var(--fraco)}
  .barraTopo .pote{margin-left:auto;color:var(--acento);font-weight:700}
  .relogio{background:var(--painel);border:1px solid var(--linha);border-radius:7px;
           padding:2px 9px;font-weight:700;font-variant-numeric:tabular-nums;color:var(--txt)}
  .relogio.curto{color:#ff8b8b;border-color:#582a30}

  .campo{position:relative;aspect-ratio:16/10;border-radius:14px;overflow:hidden;
         background:linear-gradient(#8ec5e8 0%,#b9dcf0 48%,#cfe6c0 48%,#a7cf86 100%);
         box-shadow:0 10px 30px rgba(0,0,0,.45)}
  .turno{position:absolute;top:10px;left:10px;z-index:5;background:rgba(20,24,33,.82);
         padding:3px 11px;border-radius:7px;font-weight:700;font-size:12px}
  .plat{position:absolute;border-radius:50%;background:rgba(90,140,70,.35);
        box-shadow:inset 0 2px 0 rgba(255,255,255,.25)}
  .plat.deles{width:36%;height:8%;right:10%;top:45%}
  .plat.meu{width:44%;height:10%;left:14%;bottom:4%}

  /* ── O TAMANHO DO BICHO ────────────────────────────────────────────
   *
   * Largura em porcentagem ESTRAGA: o sprite do Chimecho é quase todo
   * rabo, e forçado a 34% da quadra ele vira um gigante do lado de um
   * Jumpluff miúdo. Cada espécie tem seu tamanho no arquivo, e é ele que
   * dá a escala certa — Wailord grande, Joltik pequeno, como na série.
   *
   * Então: tamanho natural, com teto pra nenhum estourar a quadra. O
   * pixelated é porque o desenho é pequeno de origem; esticado com
   * suavização ele borra. */
  /* ── O TAMANHO E A AMPLIAÇÃO ───────────────────────────────────────
   *
   * O desenho sai no tamanho NATURAL (cada espécie tem o seu: Wailord
   * grande, Joltik pequeno) e depois é ampliado por um fator igual pros
   * dois. Largura em porcentagem, que era o que estava aqui antes,
   * achatava essa diferença — o Chimecho, que é quase todo rabo, virava
   * um gigante ao lado de um Jumpluff miúdo.
   *
   * A ampliação mora em --z e aparece em TODO keyframe: transform é uma
   * propriedade só, então uma animação que escrevesse apenas translate()
   * apagaria a escala e o bicho encolheria no meio do golpe.
   *
   * transform-origin no pé: ampliar faz ele crescer pra cima, de pé na
   * quadra, em vez de afundar no chão. */
  .bicho{position:absolute;z-index:3;filter:drop-shadow(0 6px 7px rgba(0,0,0,.35));
         transition:opacity .3s;width:auto;height:auto;image-rendering:pixelated;
         transform:scale(var(--z));transform-origin:bottom center}
  .bicho.deles{--z:1.5;right:19%;top:21%;max-width:24%;max-height:26%;
               animation:flutua 3.1s ease-in-out infinite}
  .bicho.meu{--z:1.9;left:19%;bottom:8%;max-width:26%;max-height:30%;
             animation:flutua 3.6s ease-in-out infinite .4s}
  @keyframes flutua{0%,100%{transform:scale(var(--z)) translateY(0)}
                    50%{transform:scale(var(--z)) translateY(-4px)}}
  .bicho.meu.ataca{animation:invest .5s ease-in-out}
  @keyframes invest{0%{transform:scale(var(--z)) translate(0,0)}
                    35%{transform:scale(var(--z)) translate(24px,-15px)}
                    100%{transform:scale(var(--z)) translate(0,0)}}
  .bicho.deles.ataca{animation:investD .5s ease-in-out}
  @keyframes investD{0%{transform:scale(var(--z)) translate(0,0)}
                     35%{transform:scale(var(--z)) translate(-24px,15px)}
                     100%{transform:scale(var(--z)) translate(0,0)}}
  .bicho.levou{animation:treme .42s linear 2}
  @keyframes treme{0%,100%{transform:scale(var(--z)) translateX(0)}
                   25%{transform:scale(var(--z)) translateX(-6px)}
                   50%{transform:scale(var(--z)) translateX(5px)}
                   75%{transform:scale(var(--z)) translateX(-3px)}}
  .bicho.piscou{filter:drop-shadow(0 6px 7px rgba(0,0,0,.35)) brightness(3) saturate(0)}
  .bicho.caiu{opacity:0;transform:scale(var(--z)) translateY(16px)}

  .dano{position:absolute;z-index:6;font-weight:800;font-size:22px;color:#fff;
        text-shadow:0 2px 0 #c0392b,0 0 10px rgba(0,0,0,.6);opacity:0;pointer-events:none}
  .dano.deles{right:17%;top:17%} .dano.meu{left:28%;bottom:30%}
  .dano.cura{text-shadow:0 2px 0 #1f8f4a,0 0 10px rgba(0,0,0,.6)}
  .dano.vai{animation:sobe 1s ease-out}
  @keyframes sobe{0%{opacity:0;transform:translateY(6px) scale(.8)}
                  25%{opacity:1;transform:translateY(-6px) scale(1.12)}
                  100%{opacity:0;transform:translateY(-34px) scale(1)}}
  .campo.sacode{animation:sacode .3s linear}
  @keyframes sacode{0%,100%{transform:translate(0,0)}30%{transform:translate(-3px,2px)}60%{transform:translate(3px,-2px)}}

  .barra{position:absolute;z-index:4;background:rgba(246,248,252,.93);border-radius:8px;
         padding:5px 9px 6px;min-width:168px;box-shadow:0 3px 9px rgba(0,0,0,.3);color:#1b2030}
  /* ── BARRA E BONECO EM CANTOS OPOSTOS ──────────────────────────────
   *
   * A barra ficava em cima do bicho e tapava justo a cabeça — o Noivern
   * aparecia sem rosto e o Sawsbuck sem os chifres. O boneco do
   * adversário está em cima à direita, então a barra dele vai pra
   * esquerda; o seu está embaixo à esquerda, e a sua vai pra direita.
   * É a mesma separação do Showdown, e por este motivo. */
  .barra.deles{top:7%;left:4%} .barra.meu{bottom:7%;right:4%}
  .barra .nome{display:flex;align-items:center;gap:5px;font-weight:700;font-size:12.5px}
  .barra .nv{font-weight:600;font-size:10.5px;color:#5b6477}
  .barra .st{font-size:9px;font-weight:800;letter-spacing:.5px;text-transform:uppercase;
             padding:1px 5px;border-radius:4px;color:#fff}
  .st-par{background:#c9a227}.st-brn{background:#e2622b}.st-psn,.st-tox{background:#9b4fa8}
  .st-slp{background:#6b7280}.st-frz{background:#4aa3d4}
  .barra .trilho{height:6px;border-radius:4px;background:#2d3444;margin-top:4px;overflow:hidden}
  .barra .hp{height:100%;border-radius:4px;background:var(--hp-verde);
             transition:width .9s cubic-bezier(.3,.8,.4,1),background .4s}
  .barra .num{font-size:10.5px;color:#5b6477;text-align:right;margin-top:2px;font-variant-numeric:tabular-nums}
  .barra .sub{font-size:9.5px;color:#7a8396;margin-top:1px}

  .bolas{position:absolute;z-index:4;display:flex;flex-wrap:wrap;gap:3px;width:52px}
  /* As bolinhas acompanham a barra do seu dono, logo abaixo dela. */
  .bolas.deles{top:25%;left:5%} .bolas.meu{bottom:25%;right:5%}
  .bola{width:11px;height:11px;border-radius:50%;background:#e8413a;
        border:1.5px solid rgba(0,0,0,.45);box-shadow:inset 0 -5px 0 #f3f3f3}
  .bola.caiu{background:#59606f;box-shadow:inset 0 -5px 0 #8a919f;opacity:.55}
  .pedras{position:absolute;z-index:4;font-size:11px;color:#4b3a2a;background:rgba(255,255,255,.7);
          border-radius:5px;padding:1px 6px}
  .pedras.deles{top:38%;left:5%} .pedras.meu{bottom:38%;right:5%}

  .log{background:var(--painel);border:1px solid var(--linha);border-top:0;
       border-radius:0 0 12px 12px;padding:9px 12px;min-height:50px;font-size:13px;color:#cfd6e4}
  .log b{color:#fff} .log .eficaz{color:#ffd166;font-weight:700}
  .log .fraco{color:#9aa5b8}

  .rotulo{font-size:11px;letter-spacing:1.3px;text-transform:uppercase;color:var(--fraco);margin:13px 2px 6px}
  .golpes{display:grid;grid-template-columns:1fr 1fr;gap:7px}
  .golpe{display:flex;flex-direction:column;align-items:flex-start;gap:2px;cursor:pointer;
         background:var(--painel);border:1px solid var(--linha);border-left:4px solid var(--cor,#555);
         border-radius:9px;padding:8px 10px;text-align:left;transition:transform .08s,background .15s}
  .golpe:hover:not(:disabled){background:#1d2330;transform:translateY(-1px)}
  .golpe:disabled{opacity:.4;cursor:not-allowed}
  .golpe .n{font-weight:700;font-size:13.5px}
  .golpe .b{display:flex;align-items:center;gap:6px;width:100%}
  .golpe .tipo{font-size:9.5px;font-weight:800;letter-spacing:.6px;text-transform:uppercase;
               background:var(--cor,#555);color:#15181f;padding:1px 6px;border-radius:4px}
  .golpe .pp{margin-left:auto;font-size:11px;color:var(--fraco);font-variant-numeric:tabular-nums}

  .trocas{display:grid;grid-template-columns:repeat(3,1fr);gap:7px}
  .troca{display:flex;align-items:center;gap:5px;background:var(--painel);
         border:1px solid var(--linha);border-radius:9px;padding:5px 7px;cursor:pointer;
         font-size:11.5px;font-weight:600;text-align:left}
  .troca:hover:not(:disabled){background:#1d2330}
  .troca img{width:26px;height:26px;object-fit:contain;flex:none}
  .troca:disabled{opacity:.35;cursor:not-allowed}
  .troca.atual{border-color:#3b82f6;background:#17213a}
  .troca .hpmini{display:block;height:3px;border-radius:2px;background:var(--hp-verde);margin-top:2px}

  .aviso{background:#17213a;border:1px solid #2b3a5c;border-radius:9px;padding:9px 11px;
         font-size:12.5px;color:#b9c6e0;margin-top:11px;text-align:center}
  .erro{background:#2a1a1d;border:1px solid #582a30;color:#ffb3bb;border-radius:9px;
        padding:9px 11px;font-size:12.5px;margin-bottom:10px}
  .fim{text-align:center;padding:16px}
  .fim h2{margin:0 0 4px;font-size:22px}
  .fim .premio{color:var(--acento);font-weight:800;font-size:15px;margin-bottom:12px}

  /* ── CARTAS ────────────────────────────────────────────────────────
     A borda é a raridade. Numa grade de 24 cartas, cor diz na hora o que
     nome e preço só dizem depois de ler. */
  .grade{display:grid;grid-template-columns:repeat(auto-fill,minmax(102px,1fr));gap:8px}
  .carta{position:relative;background:var(--painel);border:1px solid var(--linha);
         border-top:3px solid var(--rar,#555);border-radius:10px;padding:7px 6px 8px;
         text-align:center;cursor:pointer;transition:transform .09s,background .15s}
  .carta:hover{background:#1d2330;transform:translateY(-2px)}
  .carta.fixa{cursor:default} .carta.fixa:hover{transform:none;background:var(--painel)}
  .carta img{width:62px;height:62px;object-fit:contain;image-rendering:pixelated;margin:0 auto 2px;display:block}
  .carta .n{font-size:11.5px;font-weight:700;line-height:1.2;overflow:hidden;
            text-overflow:ellipsis;white-space:nowrap}
  .carta .r{font-size:8.5px;font-weight:800;letter-spacing:.6px;text-transform:uppercase;color:var(--rar,#999)}
  .carta .tp{display:flex;gap:3px;justify-content:center;margin-top:3px;flex-wrap:wrap}
  .carta .tp i{font-style:normal;font-size:8px;font-weight:800;letter-spacing:.4px;
               text-transform:uppercase;padding:1px 5px;border-radius:3px;color:#15181f}
  .carta .pr{margin-top:5px;font-size:11px;font-weight:700;color:var(--acento);font-variant-numeric:tabular-nums}
  .carta .pr.tem{color:#4ade80}
  .carta.escalado{outline:2px solid #3b82f6;outline-offset:-2px}
  .carta .vez{position:absolute;top:4px;left:5px;width:17px;height:17px;border-radius:50%;
              background:#3b82f6;color:#fff;font-size:10px;font-weight:800;line-height:17px}
  .carta.nova::after{content:'NOVA';position:absolute;top:4px;right:4px;background:#4ade80;
                     color:#0b1d12;font-size:8px;font-weight:800;padding:1px 4px;border-radius:3px}
  .carta.rep::after{content:'REPETIDA';position:absolute;top:4px;right:4px;background:#59606f;
                    color:#e8ecf5;font-size:8px;font-weight:800;padding:1px 4px;border-radius:3px}

  .filtros{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:10px;align-items:center}
  .filtros input[type=text]{flex:1;min-width:120px;margin:0}
  .mini{background:var(--painel);border:1px solid var(--linha);border-radius:999px;
        padding:5px 11px;font-size:11.5px;font-weight:700;cursor:pointer;color:var(--fraco)}
  .mini.on{background:var(--acento);border-color:var(--acento);color:#15181f}

  .tabela{width:100%;border-collapse:collapse;font-size:12.5px}
  .tabela th{text-align:left;font-size:10.5px;letter-spacing:.8px;text-transform:uppercase;
             color:var(--fraco);font-weight:700;padding:6px 8px;border-bottom:1px solid var(--linha)}
  .tabela td{padding:7px 8px;border-bottom:1px solid #1c212c}
  .tabela tr:last-child td{border-bottom:0}
  .tabela .pos{color:var(--fraco);font-weight:800;width:26px}
  .tabela .num{text-align:right;font-variant-numeric:tabular-nums;font-weight:700}
  .tabela tr.eu td{background:#17213a}

  .fichaTopo{display:flex;gap:12px;align-items:center;margin-bottom:12px}
  .fichaTopo img{width:84px;height:84px;object-fit:contain;image-rendering:pixelated}
  .fichaTopo h3{margin:0 0 3px;font-size:17px}
  .stats{display:grid;grid-template-columns:auto 1fr auto;gap:3px 8px;align-items:center;font-size:11.5px}
  .stats b{color:var(--fraco);font-weight:600}
  .stats .trilho{height:6px;border-radius:3px;background:#2d3444;overflow:hidden}
  .stats .trilho i{display:block;height:100%;background:var(--acento);border-radius:3px}
  .stats .v{font-variant-numeric:tabular-nums;font-weight:700;text-align:right;min-width:26px}

  .efic{display:flex;flex-wrap:wrap;gap:4px}
  .efic span{font-size:9.5px;font-weight:800;letter-spacing:.4px;text-transform:uppercase;
             padding:2px 6px;border-radius:4px;color:#15181f}
  .efic span b{font-weight:800}

  .golpeLinha{display:flex;align-items:center;gap:8px;padding:6px 8px;border-bottom:1px solid #1c212c;font-size:12px}
  .golpeLinha:last-child{border-bottom:0}
  .golpeLinha .tp{font-size:8.5px;font-weight:800;letter-spacing:.4px;text-transform:uppercase;
                  padding:1px 6px;border-radius:3px;color:#15181f;flex:none;width:62px;text-align:center}
  .golpeLinha .nm{font-weight:700;flex:1}
  .golpeLinha .dd{color:var(--fraco);font-variant-numeric:tabular-nums;font-size:11px;flex:none}
  .golpeLinha .stab{color:var(--acento);font-size:9px;font-weight:800}

  .tipoGrade{display:grid;grid-template-columns:repeat(auto-fill,minmax(84px,1fr));gap:5px}
  .tipoBt{font-size:9.5px;font-weight:800;letter-spacing:.5px;text-transform:uppercase;
          padding:5px;border-radius:5px;color:#15181f;border:0;cursor:pointer;text-align:center}

  .vazio{text-align:center;color:var(--fraco);font-size:12.5px;padding:22px 10px}

  @media (max-width:420px){
    .golpes{grid-template-columns:1fr}
    .grade{grid-template-columns:repeat(auto-fill,minmax(92px,1fr));gap:6px}
    .carta img{width:54px;height:54px}
    .barra{min-width:138px;padding:4px 7px 5px}
    .barra .nome{font-size:11.5px}
  }
</style>
</head>
<body>
<div class="jogo">

  <div class="topo">
    <button class="voltar" onclick="sair()" title="Voltar"><i class="bi bi-arrow-left"></i></button>
    <b>Pokémon PVP</b>
    <span class="saldo" title="Moedas"><i class="bi bi-coin"></i> <span id="saldo"><?= number_format($bolso['moedas'], 0, ',', '.') ?></span></span>
    <span class="saldo fbap" title="FBA Points"><i class="bi bi-lightning-charge-fill"></i> <span id="saldoFbap"><?= number_format($bolso['fbap'], 0, ',', '.') ?></span></span>
  </div>

  <nav class="abas" id="abas">
    <button data-aba="jogar"   class="on"><i class="bi bi-joystick"></i> Jogar</button>
    <button data-aba="colecao"><i class="bi bi-collection-fill"></i> Coleção</button>
    <button data-aba="loja"><i class="bi bi-bag-fill"></i> Loja</button>
    <button data-aba="ranking"><i class="bi bi-trophy-fill"></i> Ranking</button>
    <button data-aba="guia"><i class="bi bi-book-fill"></i> Guia</button>
  </nav>

  <div id="tela"></div>
</div>

<?php /* Os pop-ups são do jogo, não do navegador: confirmarSite/avisarSite. */ ?>
<script src="/js/popups.js?v=<?= @filemtime(dirname(__DIR__, 2) . '/js/popups.js') ?: 1 ?>"></script>
<script>
const EU = <?= json_encode($meuNome, JSON_UNESCAPED_UNICODE) ?>;
const EU_ID = <?= (int)$user_id ?>;
const CONVITE = <?= json_encode($convite) ?>;
const TELA = document.getElementById('tela');
const SALDO = document.getElementById('saldo');
const SALDO_FBAP = document.getElementById('saldoFbap');

let estado = null, ultimoSeq = 0, animando = false, aposta = 0, erro = '';

/* A narração do último turno, guardada. A tela se redesenha a cada 1,5s e
   sem isto o texto piscava e sumia — justo na hora em que você quer ler
   por que levou 180 de dano, e justo na tela de fim de partida. */
let ultimoLog = '';

const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
const num = n => Number(n || 0).toLocaleString('pt-BR');
const espera = ms => new Promise(r => setTimeout(r, ms));

/* ── O SPRITE ──────────────────────────────────────────────────────
 *
 * Primeiro o nosso, baixado pelo psd_sprites_cli. Se faltar — espécie
 * nova, download pela metade —, cai no espelho público em vez de deixar
 * um quadrado quebrado na quadra.
 *
 *   frente  gif animado, de frente   (o adversário)
 *   costas  gif animado, de costas   (o seu)
 *   icone   png parado               (a fileira de troca)
 */
const PSD_ESPELHO = 'https://raw.githubusercontent.com/PokeAPI/sprites/master/sprites/pokemon';
const PSD_CAMINHO = {
  frente: ['frente', 'gif', '/other/showdown'],
  costas: ['costas', 'gif', '/other/showdown/back'],
  icone:  ['icone',  'png', ''],
};

function sprite(n, qual){
  const [pasta, ext] = PSD_CAMINHO[qual];
  return `/games/img/psd/${pasta}/${n}.${ext}`;
}

/* A escada: gif animado nosso → desenho oficial nosso (os 14 da gen 9 que
   não têm animado) → espelho público. Cada degrau tenta uma vez só. */
function spriteFallback(img, n, qual){
  const [pasta, ext, caminho] = PSD_CAMINHO[qual];
  if (ext === 'gif' && !img.dataset.tentouPng) {
    img.dataset.tentouPng = '1';
    img.src = `/games/img/psd/${pasta}/${n}.png`;
    return;
  }
  img.onerror = null;
  img.src = `${PSD_ESPELHO}${caminho}/${n}.${ext}`;
}

async function chamar(acao, dados){
  const corpo = new URLSearchParams(dados || {});
  const r = await fetch('?acao=' + acao, {method:'POST', body:corpo});
  return r.json();
}

// ─────────────────────────── AS ABAS ───────────────────────────

/* A aba aberta. A quadra não é aba: quando entra uma partida ela toma a
   tela e a barra de abas some — no meio de um turno ninguém está
   comprando carta. */
let aba = 'jogar';
let regra = 'aleatoria';
let cacheColecao = null, cacheGuia = null;

const ABAS = document.getElementById('abas');
ABAS.querySelectorAll('button').forEach(b => {
  b.onclick = () => irPara(b.dataset.aba);
});

function irPara(nova){
  aba = nova;
  erro = '';
  ABAS.querySelectorAll('button').forEach(b => b.classList.toggle('on', b.dataset.aba === nova));
  desenharAba();
}

/**
 * ── A SETA VOLTA UM NÍVEL, NÃO SAI DO JOGO ───────────────────────────
 *
 * Antes ela ia direto pro /games de qualquer lugar: quem acabava de
 * desistir clicava na seta esperando a tela inicial do jogo e era jogado
 * pra fora. Agora é escada: de uma aba ou da tela de resultado volta pra
 * inicial; só da inicial é que sai pro /games.
 *
 * Partida ao vivo e espera de adversário não têm "voltar": há dinheiro em
 * jogo, e sair delas é Desistir ou Cancelar — que devolvem ou entregam o
 * prêmio. A seta diz isso em vez de fingir que fez alguma coisa.
 */
async function sair(){
  if (estado && estado.em === 'jogando' && !estado.acabou) {
    await avisarSite('Você está no meio de uma partida. Pra sair dela, use <b>Desistir</b> — '
                   + 'o prêmio vai pro adversário.', {titulo: 'Partida em andamento'});
    return;
  }

  if (estado && estado.em === 'esperando') {
    await avisarSite('Você está esperando adversário. Use <b>Cancelar e receber de volta</b> '
                   + 'pra sair sem perder a entrada.', {titulo: 'Esperando alguém'});
    return;
  }

  /* Tela de resultado ainda não dispensada, ou qualquer aba que não seja
     a inicial. O `!== fimVisto` importa: a partida terminada continua
     vindo do servidor por cinco minutos, e sem ele a seta ficaria morta
     na tela inicial durante esse tempo todo. */
  const resultadoNaTela = estado && estado.acabou && estado.id !== fimVisto;
  if (resultadoNaTela || aba !== 'jogar') {
    if (resultadoNaTela) fimVisto = estado.id;
    irPara('jogar');
    return;
  }

  location.href = '/games/';
}

function desenharAba(){
  if (aba === 'jogar')   return puxar();
  if (aba === 'colecao') return desenharColecao();
  if (aba === 'loja')    return desenharLoja();
  if (aba === 'ranking') return desenharRanking();
  if (aba === 'guia')    return desenharGuia();
}

function bolso(b){
  if (!b) return;
  if (b.moedas !== undefined) SALDO.textContent = num(b.moedas);
  if (b.fbap !== undefined)   SALDO_FBAP.textContent = num(b.fbap);
}

const TIPO_COR = t => `var(--${t})`;

function cartaHtml(c, extra){
  extra = extra || {};
  const tipos = c.tipos.map(t => `<i style="background:${TIPO_COR(t)}">${esc(t)}</i>`).join('');
  return `<div class="carta ${extra.cls||''}" style="--rar:${c.cor}"
               ${extra.onclick ? `onclick="${extra.onclick}"` : 'class="carta fixa"'}>
      ${extra.vez ? `<span class="vez">${extra.vez}</span>` : ''}
      <img src="${sprite(c.num,'icone')}" onerror="spriteFallback(this,${c.num},'icone')" alt="">
      <div class="n" title="${esc(c.nome)}">${esc(c.nome)}</div>
      <div class="r">${esc(c.rotulo)}</div>
      <div class="tp">${tipos}</div>
      ${extra.rodape !== undefined ? extra.rodape : `<div class="pr">N${c.nivel}</div>`}
    </div>`;
}

// ─────────────────────────── JOGAR ───────────────────────────

function desenharSalao(d){
  const b = d.bolso || {moedas: d.saldo ?? 0, fbap: 0};
  const pode = v => v <= (b.moedas ?? 0);

  TELA.innerHTML = `
    ${erro ? `<div class="erro">${esc(erro)}</div>` : ''}

    <div class="cartao">
      <h3><i class="bi bi-dice-5-fill"></i> Como vocês vão jogar</h3>
      <p>Vale pros dois lados da partida, e quem abre é quem escolhe.</p>
      <div class="fichas">
        <button class="ficha ${regra==='aleatoria'?'on':''}" onclick="setRegra('aleatoria')">Aleatória</button>
        <button class="ficha ${regra==='time'?'on':''}" onclick="setRegra('time')">Meu Time</button>
      </div>
      <p style="margin:0">${regra === 'aleatoria'
        ? 'Seis sorteados na hora pra cada um. A coleção não conta — é o modo em que a aposta é sempre justa.'
        : 'Cada um entra com o time que escalou na <b>Coleção</b>. Quem tem carta melhor leva vantagem.'}</p>
    </div>

    <div class="cartao">
      <h3><i class="bi bi-cpu"></i> Contra a máquina</h3>
      <p>Pra pegar o jeito. Não cobra e não paga moeda.</p>
      <button class="bt" onclick="abrir('treino',0)">Jogar agora</button>
    </div>

    <div class="cartao">
      <h3><i class="bi bi-people-fill"></i> Contra outro GM</h3>
      <p>Cada um paga a entrada e <b>quem ganhar leva as duas</b>. Cancelar antes
         de alguém entrar devolve; depois que começou, está em jogo.</p>
      <div class="fichas">
        ${[100,250,500,1000,2500,5000].map(v =>
          `<button class="ficha ${v===aposta?'on':''}" ${pode(v)?'':'disabled style="opacity:.3"'}
                   onclick="setAposta(${v})">${num(v)}</button>`).join('')}
      </div>
      <div class="linha2">
        <button class="bt ouro" ${aposta?'':'disabled'} onclick="abrir('fila',aposta)">
          <i class="bi bi-search"></i> Procurar</button>
        <button class="bt" ${aposta?'':'disabled'} onclick="abrir('codigo',aposta)">
          <i class="bi bi-link-45deg"></i> Convidar</button>
      </div>
      ${aposta ? '' : '<p style="margin:9px 0 0;text-align:center">Escolha o valor da aposta.</p>'}
    </div>

    <div class="cartao">
      <h3><i class="bi bi-box-arrow-in-right"></i> Recebi um convite</h3>
      <input type="text" id="cod" maxlength="6" placeholder="AS 6 LETRAS"
             style="text-transform:uppercase;letter-spacing:5px;text-align:center;font-weight:800">
      <button class="bt" onclick="entrar()">Entrar</button>
    </div>`;
}

function setRegra(r){ regra = r; erro=''; desenharSalao(estado || {}); }
function setAposta(v){ aposta = v; erro=''; desenharSalao(estado || {}); }

async function abrir(modo, v){
  erro = '';
  const r = await chamar('abrir', {modo, aposta:v, regra});
  if (!r.ok) { erro = r.erro || 'Não deu.'; desenharSalao(estado || {}); return; }
  ultimoSeq = 0; puxar();
}

async function entrar(codigo){
  const c = codigo || (document.getElementById('cod')?.value || '').trim();
  if (!c) return;
  erro = '';
  const r = await chamar('entrar', {codigo:c});
  if (!r.ok) { erro = r.erro || 'Não deu.'; irPara('jogar'); return; }
  ultimoSeq = 0; puxar();
}

function desenharEsperando(d){
  const link = location.origin + location.pathname + '?c=' + (d.codigo || '');
  TELA.innerHTML = `
    <div class="cartao" style="text-align:center">
      <h3 style="justify-content:center"><i class="bi bi-hourglass-split"></i> Esperando adversário</h3>
      ${d.codigo
        ? `<p>Manda o link pra quem você quer desafiar. Quem abrir já entra na partida.</p>
           <div class="codigo">${esc(d.codigo)}</div>
           <button class="bt ouro" onclick="copiar('${esc(link)}',this)">
             <i class="bi bi-clipboard"></i> Copiar o link do convite</button>`
        : `<p>Procurando alguém na <b>${d.regra === 'time' ? 'Meu Time' : 'Aleatória'}</b>
              com entrada de <b>${num(d.aposta)}</b> moedas…</p>`}
      <div style="margin-top:10px">
        <button class="bt perigo" onclick="cancelar()">Cancelar e receber de volta</button>
      </div>
    </div>`;
}

function copiar(txt, bt){
  navigator.clipboard?.writeText(txt);
  if (bt) { const antes = bt.innerHTML; bt.innerHTML = '<i class="bi bi-check-lg"></i> Copiado!';
            setTimeout(()=>bt.innerHTML = antes, 1800); }
}
async function cancelar(){ await chamar('cancelar'); ultimoSeq = 0; puxar(); }

// ─────────────────────────── COLEÇÃO ───────────────────────────

/* O time é escolhido CLICANDO nas cartas, e a ordem do clique é a ordem
   de entrada em quadra: o primeiro é quem começa a partida. Salvar só
   acontece com os seis escolhidos — time pela metade não existe. */
let timeEdit = null;

async function desenharColecao(){
  TELA.innerHTML = '<div class="vazio">Abrindo a coleção…</div>';
  const d = await (await fetch('?acao=colecao')).json();
  cacheColecao = d;
  bolso(d.bolso);
  if (timeEdit === null) timeEdit = (d.time || []).slice();
  pintarColecao();
}

function pintarColecao(){
  const d = cacheColecao;
  if (!d) return;
  const completo = timeEdit.length === 6;

  const cartas = d.cartas.map(c => {
    const i = timeEdit.indexOf(c.esp);
    return cartaHtml(c, {
      cls: i >= 0 ? 'escalado' : '',
      vez: i >= 0 ? i + 1 : null,
      onclick: `mexerTime('${c.esp}')`,
      rodape: `<div class="pr">N${c.nivel}</div>`,
    });
  }).join('');

  TELA.innerHTML = `
    ${erro ? `<div class="erro">${esc(erro)}</div>` : ''}
    <div class="cartao">
      <h3><i class="bi bi-people-fill"></i> Seu time</h3>
      <p>Clique nas cartas pra escalar os seis. A ordem do clique é a ordem de
         entrada — o primeiro começa a partida. Vale na regra <b>Meu Time</b>.</p>
      <div style="display:flex;align-items:center;gap:9px">
        <b style="font-size:19px;color:${completo?'#4ade80':'var(--acento)'}">${timeEdit.length}/6</b>
        <button class="bt ouro" style="flex:1" ${completo?'':'disabled'} onclick="salvarTime()">Salvar escalação</button>
        <button class="mini" onclick="timeEdit=[];pintarColecao()">Limpar</button>
      </div>
    </div>

    <div class="cartao">
      <h3><i class="bi bi-collection-fill"></i> Coleção
          <span style="margin-left:auto;font-size:12px;color:var(--fraco);font-weight:600">
            ${d.total} de ${d.na_dex}</span></h3>
      <div class="grade">${cartas}</div>
    </div>`;
}

function mexerTime(esp){
  const i = timeEdit.indexOf(esp);
  if (i >= 0) timeEdit.splice(i, 1);
  else if (timeEdit.length < 6) timeEdit.push(esp);
  erro = '';
  pintarColecao();
}

async function salvarTime(){
  const r = await chamar('salvar_time', {time: JSON.stringify(timeEdit)});
  if (!r.ok) { erro = r.erro || 'Não deu.'; pintarColecao(); return; }
  await avisarSite('Time salvo. É com ele que você entra na regra Meu Time.');
  cacheColecao = null; timeEdit = null; desenharColecao();
}

// ─────────────────────────── LOJA ───────────────────────────

let lojaFiltro = {busca:'', raridade:'', faltam:false, pagina:0};

async function desenharLoja(){
  TELA.innerHTML = '<div class="vazio">Abrindo a loja…</div>';
  const q = new URLSearchParams({acao:'loja', ...lojaFiltro, faltam: lojaFiltro.faltam?'1':''});
  const d = await (await fetch('?' + q)).json();
  bolso(d.bolso);

  const pacotes = Object.entries(d.pacotes).map(([k,p]) => {
    const resta = d.teto_dia - d.hoje;
    return `<button class="bt ${k==='premium'?'ouro':''}" style="flex:1"
              ${resta>0 && d.bolso.fbap >= p.preco ? '' : 'disabled'} onclick="abrirPacote('${k}')">
        ${esc(p.nome)}<br><small style="font-weight:600;opacity:.75">
        ${num(p.preco)} FBA Points · garante ${esc(d.raridades[p.garante].nome)}</small></button>`;
  }).join('');

  const filtros = ['', ...Object.keys(d.raridades)].map(r =>
    `<button class="mini ${lojaFiltro.raridade===r?'on':''}" onclick="filtrarLoja('raridade','${r}')">
       ${r===''?'Todas':esc(d.raridades[r].nome)}</button>`).join('');

  const cartas = d.cartas.map(c => cartaHtml(c, {
    onclick: c.tenho ? '' : `comprar('${c.esp}','${esc(c.nome)}',${c.preco})`,
    cls: c.tenho ? 'fixa' : '',
    rodape: c.tenho
      ? '<div class="pr tem"><i class="bi bi-check-lg"></i> tem</div>'
      : `<div class="pr">${num(c.preco)}</div>`,
  })).join('');

  TELA.innerHTML = `
    ${erro ? `<div class="erro">${esc(erro)}</div>` : ''}

    <div class="cartao">
      <h3><i class="bi bi-box-seam-fill"></i> Pacotes
          <span style="margin-left:auto;font-size:12px;color:var(--fraco);font-weight:600">
            ${d.hoje}/${d.teto_dia} hoje</span></h3>
      <p>Três por dia, somando os dois. Repetida devolve 25% do valor em moedas.
         Lendária é rara de verdade: menos de 1% por pacote.</p>
      <div class="linha2">${pacotes}</div>
    </div>

    <div class="cartao">
      <h3><i class="bi bi-bag-fill"></i> Carta avulsa</h3>
      <p>Você escolhe quem quer e paga em moedas. Quanto melhor o Pokémon, mais caro.</p>
      <div class="filtros">
        <input type="text" id="buscaLoja" placeholder="Procurar pelo nome…"
               value="${esc(lojaFiltro.busca)}" oninput="buscarLoja(this.value)">
        <button class="mini ${lojaFiltro.faltam?'on':''}" onclick="filtrarLoja('faltam',!${lojaFiltro.faltam})">
          Só o que falta</button>
      </div>
      <div class="filtros">${filtros}</div>
      ${cartas ? `<div class="grade">${cartas}</div>` : '<div class="vazio">Nada com esse filtro.</div>'}
      ${d.paginas > 1 ? `<div class="linha2" style="margin-top:10px">
          <button class="bt" ${d.pagina<=0?'disabled':''} onclick="pagLoja(${d.pagina-1})">Anterior</button>
          <button class="bt" style="flex:none;min-width:88px" disabled>${d.pagina+1}/${d.paginas}</button>
          <button class="bt" ${d.pagina>=d.paginas-1?'disabled':''} onclick="pagLoja(${d.pagina+1})">Próxima</button>
        </div>` : ''}
    </div>`;
}

let buscaTimer = null;
function buscarLoja(v){
  lojaFiltro.busca = v; lojaFiltro.pagina = 0;
  clearTimeout(buscaTimer);
  buscaTimer = setTimeout(() => {
    desenharLoja().then(() => { const i = document.getElementById('buscaLoja');
      if (i) { i.focus(); i.setSelectionRange(i.value.length, i.value.length); } });
  }, 320);
}
function filtrarLoja(k, v){ lojaFiltro[k] = v; lojaFiltro.pagina = 0; erro=''; desenharLoja(); }
function pagLoja(p){ lojaFiltro.pagina = p; desenharLoja(); }

async function comprar(esp, nome, preco){
  const ok = await confirmarSite(`Comprar <b>${esc(nome)}</b> por <b>${num(preco)}</b> moedas?`,
                                 {titulo:'Confirmar compra', confirmar:'Comprar'});
  if (!ok) return;
  const r = await chamar('comprar', {especie:esp});
  if (!r.ok) { erro = r.erro || 'Não deu.'; desenharLoja(); return; }
  bolso(r.bolso);
  cacheColecao = null; timeEdit = null;
  await avisarSite(`<b>${esc(nome)}</b> é seu. Escale ele na Coleção.`);
  desenharLoja();
}

async function abrirPacote(tipo){
  const r = await chamar('pacote', {tipo});
  if (!r.ok) { erro = r.erro || 'Não deu.'; desenharLoja(); return; }
  cacheColecao = null; timeEdit = null;
  mostrarPacote(r);
}

/* O pacote aberto vira tela: as três cartas com NOVA ou REPETIDA, e o
   quanto a repetida devolveu. Sem isso o GM clica, o saldo muda e ele
   não sabe o que aconteceu. */
function mostrarPacote(r){
  bolso(r.bolso);
  const cartas = r.cartas.map(c => cartaHtml(c, {
    cls: c.repetida ? 'rep fixa' : 'nova fixa',
    rodape: `<div class="pr">${c.repetida ? '+' + num(c.devolve) : 'N' + c.nivel}</div>`,
  })).join('');

  TELA.innerHTML = `
    <div class="cartao">
      <h3><i class="bi bi-box-seam-fill"></i> Abriu!</h3>
      <div class="grade" style="margin-bottom:12px">${cartas}</div>
      ${r.devolvido > 0
        ? `<p style="margin:0 0 10px">Repetidas devolveram <b style="color:var(--acento)">${num(r.devolvido)}</b> moedas.</p>`
        : ''}
      <p style="margin:0 0 10px">Restam <b>${r.restam}</b> pacotes hoje.</p>
      <button class="bt ouro" onclick="desenharLoja()">Voltar à loja</button>
    </div>`;
}

// ─────────────────────────── RANKING ───────────────────────────

async function desenharRanking(){
  TELA.innerHTML = '<div class="vazio">Contando…</div>';
  const d = await (await fetch('?acao=ranking')).json();

  const linhas = (lista, campos) => lista.length
    ? lista.map((x,i) => `<tr class="${x.id_usuario == EU_ID ? 'eu' : ''}">
         <td class="pos">${i+1}</td><td>${esc(x.nome)}</td>
         ${campos.map(c => `<td class="num">${num(x[c])}</td>`).join('')}</tr>`).join('')
    : '<tr><td colspan="4" class="vazio">Ninguém ainda.</td></tr>';

  TELA.innerHTML = `
    <div class="cartao">
      <h3><i class="bi bi-collection-fill"></i> Maiores coleções</h3>
      <table class="tabela"><thead><tr><th></th><th>GM</th><th class="num">Pokémon</th></tr></thead>
      <tbody>${linhas(d.colecao, ['total'])}</tbody></table>
    </div>

    <div class="cartao">
      <h3><i class="bi bi-trophy-fill"></i> Mais vitórias no PVP</h3>
      <p>Só partida contra outro GM. Treino não conta.</p>
      <table class="tabela"><thead><tr><th></th><th>GM</th>
        <th class="num">Vitórias</th><th class="num">Derrotas</th></tr></thead>
      <tbody>${linhas(d.vitorias, ['vitorias','derrotas'])}</tbody></table>
    </div>`;
}

// ─────────────────────────── GUIA ───────────────────────────

let guiaBusca = '';

async function desenharGuia(especie){
  if (especie) return fichaGuia(especie);

  TELA.innerHTML = '<div class="vazio">Abrindo o guia…</div>';
  if (!cacheGuia) cacheGuia = await (await fetch('?acao=guia')).json();
  pintarGuia();
}

function pintarGuia(){
  const d = cacheGuia;
  const b = guiaBusca.toLowerCase().trim();
  const achados = b
    ? d.indice.filter(p => p.nome.toLowerCase().includes(b)).slice(0, 36)
    : d.indice.slice(0, 36);

  /* Stellar fica de fora: é tipo de Tera, e Tera não existe aqui. Ele não
     é fraco nem forte contra nada, então só confundiria quem abre o guia
     pra decidir um golpe. */
  const tipos = Object.keys(d.tipos).filter(t => t !== 'stellar').sort();
  const grade = tipos.map(t =>
    `<button class="tipoBt" style="background:${TIPO_COR(t)}" onclick="verTipo('${t}')">${esc(t)}</button>`).join('');

  const lista = achados.map(p => `
    <div class="carta" style="--rar:#444" onclick="desenharGuia('${p.esp}')">
      <img src="${sprite(p.num,'icone')}" onerror="spriteFallback(this,${p.num},'icone')" alt="">
      <div class="n">${esc(p.nome)}</div>
      <div class="tp">${p.tipos.map(t=>`<i style="background:${TIPO_COR(t)}">${esc(t)}</i>`).join('')}</div>
    </div>`).join('');

  TELA.innerHTML = `
    <div class="cartao">
      <h3><i class="bi bi-diagram-3-fill"></i> Tabela de tipos</h3>
      <p>Clique num tipo pra ver contra o que ele é forte, fraco e inútil.</p>
      <div class="tipoGrade">${grade}</div>
    </div>

    <div class="cartao">
      <h3><i class="bi bi-search"></i> Procurar Pokémon</h3>
      <div class="filtros">
        <input type="text" id="buscaGuia" placeholder="Nome do Pokémon…" value="${esc(guiaBusca)}"
               oninput="guiaBusca=this.value;pintarGuia();document.getElementById('buscaGuia').focus()">
      </div>
      <div class="grade">${lista}</div>
      ${achados.length === 36 ? '<div class="vazio">Mostrando os 36 primeiros. Refine a busca.</div>' : ''}
    </div>`;

  const i = document.getElementById('buscaGuia');
  if (i && guiaBusca) i.setSelectionRange(i.value.length, i.value.length);
}

const EFIC_ROTULO = {4:'4×', 2:'2×', 0.5:'½', 0.25:'¼', 0:'imune'};
const EFIC_COR = m => m >= 2 ? '#ef4444' : m === 0 ? '#59606f' : '#4ade80';

function verTipo(t){
  const rel = cacheGuia.tipos[t];
  const forte = [], fraco = [], nada = [];
  for (const alvo in rel) {
    if (rel[alvo] > 1) forte.push(alvo);
    else if (rel[alvo] === 0) nada.push(alvo);
    else if (rel[alvo] < 1) fraco.push(alvo);
  }
  const bloco = (titulo, arr, cor) => arr.length
    ? `<p style="margin:0 0 4px"><b>${titulo}</b></p><div class="efic" style="margin-bottom:9px">
       ${arr.map(x=>`<span style="background:${cor}">${esc(x)}</span>`).join('')}</div>` : '';

  avisarSite(`<div style="text-align:left">
      ${bloco('Dobra o dano em', forte, '#ef4444')}
      ${bloco('Faz metade em', fraco, '#4ade80')}
      ${bloco('Não afeta', nada, '#59606f')}
    </div>`, {titulo: 'Golpe de tipo ' + t});
}

async function fichaGuia(esp){
  TELA.innerHTML = '<div class="vazio">Abrindo a ficha…</div>';
  const d = await (await fetch('?acao=guia&especie=' + encodeURIComponent(esp))).json();
  if (d.erro) { TELA.innerHTML = `<div class="erro">${esc(d.erro)}</div>`; return; }
  const f = d.ficha;

  const NOMES_BS = ['HP','Ataque','Defesa','Atq. Esp.','Def. Esp.','Velocidade'];
  const stats = f.bs.map((v,i) => `
    <b>${NOMES_BS[i]}</b>
    <span class="trilho"><i style="width:${Math.min(100, v*100/180)}%"></i></span>
    <span class="v">${v}</span>`).join('');

  const contra = Object.entries(f.contra)
    .map(([t,m]) => `<span style="background:${EFIC_COR(m)}">${esc(t)} <b>${EFIC_ROTULO[m]||m+'×'}</b></span>`)
    .join('') || '<span style="background:#59606f">nada de especial</span>';

  const golpes = f.golpes.map(g => `
    <div class="golpeLinha">
      <span class="tp" style="background:${TIPO_COR(g.tipo)}">${esc(g.tipo)}</span>
      <span class="nm">${esc(g.nome)} ${g.stab?'<span class="stab">STAB</span>':''}</span>
      <span class="dd">${g.cat === 'status' ? 'status'
          : `${g.power} pow · ${g.acc}%`} · ${g.pp} PP${g.sec ? ' · '+g.sec.chance+'%' : ''}</span>
    </div>`).join('');

  TELA.innerHTML = `
    <button class="mini" style="margin-bottom:10px" onclick="desenharGuia()">
      <i class="bi bi-arrow-left"></i> Voltar ao guia</button>

    <div class="cartao">
      <div class="fichaTopo">
        <img src="${sprite(f.num,'frente')}" onerror="spriteFallback(this,${f.num},'frente')" alt="">
        <div style="flex:1">
          <h3 style="color:${f.cor}">${esc(f.nome)}</h3>
          <div class="tp" style="display:flex;gap:4px;margin-bottom:5px">
            ${f.tipos.map(t=>`<i style="background:${TIPO_COR(t)};font-style:normal;font-size:9px;
               font-weight:800;text-transform:uppercase;padding:2px 7px;border-radius:4px;color:#15181f">${esc(t)}</i>`).join('')}
          </div>
          <div style="font-size:11.5px;color:var(--fraco)">
            ${esc(f.rotulo)} · entra nível <b style="color:var(--txt)">${f.nivel}</b> · soma ${f.bst}
          </div>
        </div>
      </div>
      <div class="stats">${stats}</div>
    </div>

    <div class="cartao">
      <h3><i class="bi bi-shield-exclamation"></i> O que machuca ele</h3>
      <p>Vermelho dobra, verde faz metade, cinza não afeta.</p>
      <div class="efic">${contra}</div>
    </div>

    <div class="cartao">
      <h3><i class="bi bi-lightning-fill"></i> Golpes que ele pode ter</h3>
      <p>A partida sorteia quatro destes. <b>STAB</b> é golpe do tipo dele, que machuca 50% a mais.
         A porcentagem no fim é a chance do efeito extra.</p>
      ${golpes}
    </div>`;
}

// ─────────────────────────── QUADRA ───────────────────────────


function corDoTipo(t){ return `var(--${t})`; }

function barraHtml(b, lado, mostrarNum){
  const pct = Math.max(0, Math.round(100 * b.hp / b.hpmax));
  const cor = pct > 50 ? 'var(--hp-verde)' : pct > 20 ? 'var(--hp-amarelo)' : 'var(--hp-vermelho)';
  return `<div class="barra ${lado}">
      <div class="nome">${esc(b.nome)} <span class="nv">N${b.nivel}</span>
        ${b.status ? `<span class="st st-${b.status}">${b.status}</span>` : ''}</div>
      <div class="trilho"><div class="hp" id="hp-${lado}" style="width:${pct}%;background:${cor}"></div></div>
      ${mostrarNum ? `<div class="num" id="num-${lado}">${b.hp}/${b.hpmax}</div>` : ''}
    </div>`;
}

function bolasHtml(n, total, lado){
  let h = `<div class="bolas ${lado}">`;
  for (let i=0;i<total;i++) h += `<i class="bola ${i<n?'':'caiu'}"></i>`;
  return h + '</div>';
}

function desenharPartida(d){
  const meu = d.eu.ativo, dele = d.ele.ativo;
  const vivosMeus = d.eu.equipe.filter(x => x.hp > 0).length;

  const campo = `
    <div class="barraTopo">
      <span>Turno ${d.turno}</span>
      ${d.treino ? '<span>· treino</span>' : ''}
      ${d.acabou ? '' : `<span class="relogio ${d.segundos<=10?'curto':''}" id="relogio">${d.segundos}s</span>`}
      ${d.premio ? `<span class="pote"><i class="bi bi-coin"></i> ${num(d.premio)}</span>` : ''}
    </div>

    <div class="campo" id="campo">
      <div class="plat deles"></div><div class="plat meu"></div>
      ${bolasHtml(d.ele.restam, 6, 'deles')}
      ${bolasHtml(vivosMeus, 6, 'meu')}
      ${d.ele.rochas ? '<div class="pedras deles"><i class="bi bi-gem"></i> pedras</div>' : ''}
      ${d.eu.rochas ? '<div class="pedras meu"><i class="bi bi-gem"></i> pedras</div>' : ''}
      ${barraHtml(dele, 'deles', false)}
      <img class="bicho deles" id="sp-deles" src="${sprite(dele.num,'frente')}"
           onerror="spriteFallback(this,${dele.num},'frente')" alt="${esc(dele.nome)}">
      <div class="dano deles" id="dano-deles"></div>
      ${barraHtml(meu, 'meu', true)}
      <img class="bicho meu" id="sp-meu" src="${sprite(meu.num,'costas')}"
           onerror="spriteFallback(this,${meu.num},'costas')" alt="${esc(meu.nome)}">
      <div class="dano meu" id="dano-meu"></div>
    </div>
    <div class="log" id="log">${ultimoLog}</div>`;

  if (d.acabou) { TELA.innerHTML = campo + fimHtml(d); return; }

  const precisaTrocar = d.eu.precisa_trocar;
  const travado = !d.falta_eu;

  const golpes = d.eu.golpes.map((g,i) => {
    const off = travado || precisaTrocar || g.pp <= 0;
    return `<button class="golpe" style="--cor:${corDoTipo(g.tipo||'normal')}"
              ${off?'disabled':''} onclick="jogar('golpe',${i})">
        <span class="n">${esc(g.nome)}</span>
        <span class="b"><span class="tipo">${esc(g.tipo)}</span>
        <span class="pp">${g.pp}/${g.ppmax}</span></span></button>`;
  }).join('');

  const trocas = d.eu.equipe.map(b => {
    const off = travado || b.hp<=0 || b.ativo;
    const pct = Math.max(0,Math.round(100*b.hp/b.hpmax));
    return `<button class="troca ${b.ativo?'atual':''}" ${off?'disabled':''} onclick="jogar('trocar',${b.i})">
        <img src="${sprite(b.num,'icone')}"
             onerror="spriteFallback(this,${b.num},'icone')">
        <span>${esc(b.nome)}<i class="hpmini" style="width:${pct}%;background:${pct>50?'var(--hp-verde)':pct>20?'var(--hp-amarelo)':'var(--hp-vermelho)'}"></i></span>
      </button>`;
  }).join('');

  let menu = '';
  if (precisaTrocar) {
    menu = `<div class="aviso">Seu Pokémon caiu. Escolha quem entra.</div>
            <div class="rotulo">Trocar</div><div class="trocas">${trocas}</div>`;
  } else if (travado) {
    menu = `<div class="aviso"><i class="bi bi-hourglass-split"></i> Esperando ${esc(d.ele.nome)} escolher…</div>`;
  } else {
    menu = `<div class="rotulo">Lutar</div><div class="golpes">${golpes}</div>
            <div class="rotulo">Trocar</div><div class="trocas">${trocas}</div>
            <div style="margin-top:14px"><button class="bt perigo" onclick="desistir()">Desistir</button></div>`;
  }

  TELA.innerHTML = campo + menu;
}

function fimHtml(d){
  const ganhei = d.venci;
  const empate = d.fim === 'empate';
  return `<div class="fim">
      <h2>${empate ? 'Empate' : ganhei ? 'Você venceu!' : 'Você perdeu'}</h2>
      <div class="premio">${
        d.treino ? 'Treino não paga moeda.'
        : empate ? 'Entrada devolvida pros dois.'
        : ganhei ? `+${num(d.premio)} moedas` : `-${num(d.aposta)} moedas`}</div>
      <button class="bt ouro" onclick="voltarAoSalao()">Jogar de novo</button>
    </div>`;
}

/* ── SAIR DA TELA DE FIM ───────────────────────────────────────────
 *
 * A partida terminada continua vindo do servidor por cinco minutos, de
 * propósito: é o que deixa você ver quem ganhou mesmo se recarregar a
 * página. Só que isso fazia o "Jogar de novo" voltar pra ela mesma, em
 * laço — a tela pedia o estado, recebia a mesma partida acabada e
 * desenhava o mesmo resultado.
 *
 * Então a tela anota qual fim já foi visto e, daí em diante, mostra o
 * salão no lugar dele. Resolver isso no servidor seria apagar a partida,
 * e aí quem recarregasse perderia o resultado. */
let fimVisto = 0;

function voltarAoSalao(){
  if (estado && estado.id) fimVisto = estado.id;
  estado = null; ultimoSeq = 0; erro = ''; ultimoLog = '';
  TELA.innerHTML = '';
  puxar();
}

async function jogar(tipo, i){
  document.querySelectorAll('.golpe,.troca').forEach(b => b.disabled = true);
  const r = await chamar('escolher', {tipo, i});
  if (!r.ok && r.erro) { erro = r.erro; }
  puxar();
}

async function desistir(){
  /* Pop-up do site, nunca o do navegador (@see js/popups.js). */
  const ok = await confirmarSite(
    'Desistir entrega a partida e o prêmio inteiro pro adversário.',
    {titulo:'Desistir?', confirmar:'Desistir', perigo:true});
  if (!ok) return;

  await chamar('desistir');

  /* Volta direto pra tela inicial, sem passar pelo resultado: quem
     desistiu já sabe como acabou. O adversário é que precisa ver — e vê,
     porque o fimVisto é de cada tela. */
  if (estado && estado.id) fimVisto = estado.id;
  ultimoSeq = 0; ultimoLog = '';
  irPara('jogar');
}

// ─────────────────────────── a animação ───────────────────────────

async function animar(d, eventos){
  animando = true;
  const log = document.getElementById('log');
  const campo = document.getElementById('campo');
  ultimoLog = '';
  if (log) log.innerHTML = '';

  for (const ev of eventos) {
    const l = ev.lado ? (ev.lado === d.meuLado ? 'meu' : 'deles') : null;
    const sp = l ? document.getElementById('sp-'+l) : null;

    if (ev.t === 'texto') {
      const cls = /super eficaz/.test(ev.m) ? 'eficaz' : /Não é muito|Não afeta|errou|falhou/.test(ev.m) ? 'fraco' : '';
      ultimoLog += `<div class="${cls}">${esc(ev.m)}</div>`;
      if (log) log.innerHTML = ultimoLog;
      await espera(620);

    } else if (ev.t === 'golpe') {
      const meuSp = document.getElementById('sp-' + (ev.lado === d.meuLado ? 'meu' : 'deles'));
      if (meuSp) { meuSp.classList.add('ataca'); setTimeout(()=>meuSp.classList.remove('ataca'), 500); }
      await espera(330);

    } else if (ev.t === 'dano') {
      if (sp) { sp.classList.add('levou','piscou'); }
      if (campo) { campo.classList.add('sacode'); setTimeout(()=>campo.classList.remove('sacode'), 300); }
      mostrarNumero(l, '-' + ev.valor, false);
      ajustarHp(l, ev.hp, ev.hpmax);
      await espera(520);
      if (sp) { sp.classList.remove('piscou'); setTimeout(()=>sp.classList.remove('levou'), 300); }

    } else if (ev.t === 'cura') {
      mostrarNumero(l, '+' + ev.valor, true);
      ajustarHp(l, ev.hp, ev.hpmax);
      await espera(480);

    } else if (ev.t === 'desmaiou') {
      if (sp) sp.classList.add('caiu');
      await espera(620);

    } else if (ev.t === 'entrou') {
      if (sp) {
        sp.classList.remove('caiu');
        sp.src = sprite(ev.bicho.num, l === 'meu' ? 'costas' : 'frente');
        sp.onerror = () => spriteFallback(sp, ev.bicho.num, l === 'meu' ? 'costas' : 'frente');
      }
      await espera(420);

    } else {
      await espera(160);
    }
  }
  animando = false;
}

function mostrarNumero(l, texto, cura){
  const el = document.getElementById('dano-'+l);
  if (!el) return;
  el.textContent = texto;
  el.classList.toggle('cura', !!cura);
  el.classList.remove('vai'); void el.offsetWidth; el.classList.add('vai');
}

function ajustarHp(l, hp, hpmax){
  const el = document.getElementById('hp-'+l);
  if (!el) return;
  const pct = Math.max(0, Math.round(100*hp/hpmax));
  el.style.width = pct + '%';
  el.style.background = pct > 50 ? 'var(--hp-verde)' : pct > 20 ? 'var(--hp-amarelo)' : 'var(--hp-vermelho)';
  const n = document.getElementById('num-'+l);
  if (n) n.textContent = hp + '/' + hpmax;
}

// ─────────────────────────── o laço ───────────────────────────

/**
 * O laço.
 *
 * Ele roda o tempo todo, inclusive com o GM na Loja — é assim que a tela
 * percebe que um convite foi aceito e puxa a pessoa pra quadra sozinha.
 * Mas ele só REDESENHA quando há partida; fora disso, quem manda na tela
 * é a aba aberta, senão a coleção piscaria a cada 1,5s.
 */
async function puxar(){
  if (animando) return;
  let d;
  try { d = await (await fetch('?acao=ver')).json(); } catch(e){ return; }

  if (d.saldo !== undefined) SALDO.textContent = num(d.saldo);

  /* A quadra só esconde as abas enquanto a partida ESTÁ ACONTECENDO.
     Acabou, elas voltam: senão o GM fica preso na tela de resultado até
     clicar "Jogar de novo", sem conseguir ir ver a coleção. */
  const emPartida = d.em === 'jogando' && !d.acabou;
  ABAS.classList.toggle('escondida', emPartida || d.em === 'esperando');

  if (!emPartida && d.em !== 'esperando' && aba !== 'jogar') return;

  if (d.em === 'nada')       { estado = d; desenharSalao(d); return; }
  if (d.em === 'esperando')  { estado = d; desenharEsperando(d); return; }
  if (d.em !== 'jogando')    { return; }

  // Fim que o GM já leu e dispensou: daqui pra frente, salão.
  if (d.acabou && d.id === fimVisto) { estado = d; desenharSalao(d); return; }

  /* A vista já vem filtrada pelo meu lado, mas os EVENTOS vêm marcados com
     'a'/'b' — então a tela precisa saber qual das duas letras sou eu pra
     saber de que lado da quadra a animação acontece. */
  d.meuLado = d.lado_eu;

  const novo = d.seq > ultimoSeq;
  const primeira = ultimoSeq === 0;
  ultimoSeq = d.seq;
  estado = d;

  desenharPartida(d);
  if (novo && !primeira && d.eventos && d.eventos.length) {
    await animar(d, d.eventos);
    desenharPartida(d);
  }
}

setInterval(() => { if (!animando) puxar(); }, 1500);
setInterval(() => {
  const r = document.getElementById('relogio');
  if (!r || !estado || estado.em !== 'jogando') return;
  const s = Math.max(0, (estado.segundos || 0) - 1);
  estado.segundos = s;
  r.textContent = s + 's';
  r.classList.toggle('curto', s <= 10);
}, 1000);

/* Convite aberto pelo link (?c=XXXXXX): entra sozinho e limpa o endereço,
   pra um F5 não tentar entrar de novo numa partida que já começou. */
if (CONVITE) {
  history.replaceState(null, '', location.pathname);
  entrar(CONVITE);
} else {
  puxar();
}
</script>
</body>
</html>
