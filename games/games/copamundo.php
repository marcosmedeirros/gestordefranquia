<?php
/**
 * COPA DO MUNDO DE ALGUMA COISA.
 *
 * O admin geral cria uma copa do que quiser, põe os competidores e sorteia.
 * A galera vota confronto a confronto; quem tem mais voto avança, até sobrar
 * um campeão.
 *
 * Uma tela só, e não uma pública + uma de admin: o admin precisa ver
 * exatamente o que a galera vê pra decidir quando fechar a rodada. Os
 * controles dele aparecem no meio do chaveamento, e pra quem não é admin
 * simplesmente não existem.
 *
 * O motor (sorteio, apuração, pontos) vive em games/core/copa_motor.php.
 */

require_once __DIR__ . '/../core/conexao.php';
require_once __DIR__ . '/../core/copa_motor.php';

$userId = (int)($_SESSION['user_id'] ?? 0);

// Admin geral OU admin do Games. A copa é coisa do Games, e quem cuida do
// Games é quem está por perto pra abrir a votação e apurar a rodada na
// hora — deixar isso só no admin geral faria a copa parar sempre que ele
// não estivesse disponível.
$isAdmin = $userId > 0 && hasGamesAdminAccess($pdo, $userId);

copaTabelas($pdo);

$msg = $erro = null;
$destaqueSorteio = false;

/* ── Ações ──────────────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = (string)($_POST['acao'] ?? '');
    $tid  = (int)($_POST['torneio_id'] ?? 0);

    try {
        if ($acao === 'votar') {
            $r = copaVotar($pdo, $tid, (int)$_POST['confronto_id'], (int)$_POST['escolha_id'], $userId);
            if (!$r['ok']) $_SESSION['copa_flash'] = ['erro', $r['erro']];

        } elseif ($acao === 'criar' && $isAdmin) {
            // Um nome por linha. No modo SÓ NOMES a vírgula também separa,
            // porque colar uma lista pronta é o caminho mais provável. No
            // modo com foto ela não separa: URL vem cheia de vírgula (um
            // data: URI é quase só isso) e cortar ali partiria o link.
            $comFoto = ($_POST['modo'] ?? 'nomes') === 'fotos';
            $bruto = str_replace("\r", "\n", (string)($_POST['nomes'] ?? ''));
            if (!$comFoto) $bruto = str_replace(',', "\n", $bruto);
            $r = copaCriar($pdo, (string)($_POST['titulo'] ?? ''), explode("\n", $bruto), $userId);
            if ($r['ok']) {
                $_SESSION['copa_flash'] = ['ok', 'Copa criada e chaveamento sorteado!'];
                $_SESSION['copa_sorteio'] = $r['id'];
                header('Location: ?copa=' . $r['id']);
                exit;
            }
            $_SESSION['copa_flash'] = ['erro', $r['erro']];
            $_SESSION['copa_form'] = ['titulo' => $_POST['titulo'] ?? '', 'nomes' => $_POST['nomes'] ?? ''];

        } elseif ($acao === 'minutos' && $isAdmin) {
            $min = max(0, (int)($_POST['minutos'] ?? 30));
            copaDefinirMinutos($pdo, $tid, $min);
            $_SESSION['copa_flash'] = ['ok', $min > 0
                ? "Cada rodada passa a durar {$min} min — vira sozinha e já abre a seguinte."
                : 'Tempo desligado: a rodada só vira quando você apurar.'];

        } elseif ($acao === 'votacao' && $isAdmin) {
            copaVotacao($pdo, $tid, !empty($_POST['abrir']));
            $_SESSION['copa_flash'] = ['ok', !empty($_POST['abrir'])
                ? 'Votação ABERTA — a galera já pode votar.'
                : 'Votação fechada. Ninguém vota até você abrir de novo.'];

        } elseif ($acao === 'fechar' && $isAdmin) {
            // A rodada que ACABA de ser apurada — depois de fechar, o torneio
            // já aponta pra seguinte, e é o resultado desta que vai pro grupo.
            $rodApurada = (int)(copaTorneio($pdo, $tid)['rodada_atual'] ?? 1);
            $r = copaFecharRodada($pdo, $tid);
            if ($r['ok']) {
                $t = 'Rodada apurada: ' . $r['decididos'] . ' no voto';
                if ($r['sorteados']) $t .= ', ' . $r['sorteados'] . ' no sorteio (empate)';
                $t .= '. ' . $r['pagos'] . ' pessoa(s) receberam FBA Points.';
                if ($r['campeao']) $t = '🏆 CAMPEÃO: ' . $r['campeao'] . '! ' . $t;

                // Avisa o grupo. Fora da transação e num try próprio: bot
                // desligado, grupo não configurado ou fila cheia não podem
                // desfazer uma apuração que já pagou FBA Points.
                try {
                    require_once __DIR__ . '/../../backend/whatsapp.php';
                    $grupo = trim((string)($pdo->query(
                        "SELECT grupo_principal FROM whatsapp_config WHERE id=1")->fetchColumn() ?: ''));
                    $texto = copaTextoResultado($pdo, $tid, $rodApurada);
                    if ($grupo !== '' && $texto !== '' && function_exists('whatsappEnfileirar')) {
                        if (whatsappEnfileirar($pdo, $grupo, $texto, true, 'copa')) {
                            $t .= ' Resultado enviado pro grupo.';
                        }
                    }
                } catch (Throwable $e) {
                    error_log('[copa] aviso no grupo: ' . $e->getMessage());
                }

                $_SESSION['copa_flash'] = ['ok', $t];
            } else {
                $_SESSION['copa_flash'] = ['erro', (string)$r['erro']];
            }

        } elseif ($acao === 'apagar' && $isAdmin) {
            copaApagar($pdo, $tid);
            $_SESSION['copa_flash'] = ['ok', 'Copa apagada.'];
            header('Location: ?');
            exit;
        }
    } catch (Throwable $e) {
        $_SESSION['copa_flash'] = ['erro', 'Deu erro: ' . $e->getMessage()];
    }
    header('Location: ?copa=' . $tid);
    exit;
}

if (!empty($_SESSION['copa_flash'])) {
    [$t, $x] = $_SESSION['copa_flash'];
    unset($_SESSION['copa_flash']);
    if ($t === 'ok') $msg = $x; else $erro = $x;
}
$form = $_SESSION['copa_form'] ?? ['titulo' => '', 'nomes' => ''];
unset($_SESSION['copa_form']);

/* ── Dados da tela ──────────────────────────────────────────────────── */
$copaId = (int)($_GET['copa'] ?? 0);
$copa   = $copaId ? copaTorneio($pdo, $copaId) : copaAtual($pdo);
$novo   = $isAdmin && isset($_GET['nova']);

if (!empty($_SESSION['copa_sorteio']) && $copa && (int)$_SESSION['copa_sorteio'] === (int)$copa['id']) {
    $destaqueSorteio = true;
    unset($_SESSION['copa_sorteio']);
}

$chave = $comps = $ranking = [];
$rodadas = 0;
if ($copa) {
    $chave   = copaChave($pdo, (int)$copa['id'], $userId);
    $comps   = copaCompetidores($pdo, (int)$copa['id']);
    $ranking = copaRanking($pdo, (int)$copa['id']);
    $rodadas = (int)$copa['rodadas'];
}
$lista = copaLista($pdo);

$esc = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$nomeDe = fn($id) => $id && isset($comps[$id]) ? $comps[$id]['nome'] : '—';

// A minha situação na copa, pro cabeçalho dizer em que sequência eu estou.
$minhaSeq = null;
if ($copa && $userId) {
    $st = $pdo->prepare("SELECT * FROM copa_sequencias WHERE torneio_id=? AND user_id=?");
    $st->execute([(int)$copa['id'], $userId]);
    $minhaSeq = $st->fetch(PDO::FETCH_ASSOC) ?: null;
}
?><!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<script>document.documentElement.dataset.theme = localStorage.getItem('fba-theme') || 'dark';</script>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="theme-color" content="#f59e0b">
<title><?= $copa ? $esc($copa['titulo']) : 'Copa do Mundo' ?> — FBA Games</title>
<link rel="icon" type="image/png" href="/games/fbagames.png">
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
  :root{
    --ouro:#f59e0b; --ouro-2:#fbbf24;
    --bg:#08080b; --panel:#101014; --panel-2:#16161b; --panel-3:#1e1e25;
    --border:rgba(255,255,255,.07); --border-md:rgba(255,255,255,.13);
    --text:#f2f2f5; --text-2:#8a8a95; --text-3:#6f6f7a;
    --verde:#22c55e; --vermelho:#ef4444;
    --font:'Montserrat',sans-serif; --raio:14px;
  }
  :root[data-theme="light"]{
    --bg:#f6f7fb; --panel:#fff; --panel-2:#f1f3f8; --panel-3:#e6eaf2;
    --border:#e3e6ee; --border-md:#d3d8e4; --text:#12141a; --text-2:#5a6070; --text-3:#7a8092;
    /* O ouro do tema escuro dava 1.5:1 sobre fundo claro — o nome do
       vencedor sumia na tela. Aqui ele desce até o âmbar queimado, que
       mantém a mesma leitura de "ouro" e passa dos 4.5:1. O fundo dos
       botões continua o ouro vivo, com texto escuro por cima. */
    --ouro:#a35c00; --ouro-2:#8a4d00;
    --verde:#15803d; --vermelho:#b91c1c;
  }
  /* O botão dourado é o único lugar em que o ouro é FUNDO, e aí ele precisa
     seguir vivo nos dois temas — o escurecido some contra o texto preto. */
  :root[data-theme="light"] .bt.ouro{background:#f59e0b;border-color:#f59e0b;color:#241500}
  :root[data-theme="light"] .campeao{
    background:linear-gradient(135deg,rgba(245,158,11,.2),rgba(245,158,11,.06));
    border-color:rgba(163,92,0,.35);
  }
  :root[data-theme="light"] .lado .barra{background:rgba(245,158,11,.24)}
  *{box-sizing:border-box;margin:0;padding:0}
  body{background:var(--bg);color:var(--text);font-family:var(--font);font-size:14px;
       min-height:100vh;padding-bottom:50px}
  a{color:inherit;text-decoration:none}
  .wrap{max-width:1240px;margin:0 auto;padding:18px 16px}

  /* ── Topo ─────────────────────────────────────────────────────── */
  .topo{display:flex;align-items:center;gap:12px;margin-bottom:14px;flex-wrap:wrap}
  .voltar{color:var(--text-3);font-size:17px;border:1px solid var(--border-md);
          border-radius:9px;padding:7px 10px;line-height:1;flex:none}
  .voltar:hover{color:var(--text);border-color:var(--ouro)}
  .tit{font-size:21px;font-weight:900;line-height:1.15;flex:1;min-width:180px}
  .tit small{display:block;font-size:10.5px;font-weight:800;letter-spacing:1.3px;
             text-transform:uppercase;color:var(--ouro);margin-bottom:2px}

  .aviso{border-radius:11px;padding:11px 14px;font-size:12.5px;font-weight:700;
         margin-bottom:14px;display:flex;gap:9px;align-items:flex-start;line-height:1.45}
  .aviso.ok{background:rgba(34,197,94,.1);border:1px solid rgba(34,197,94,.3);color:#4ade80}
  .aviso.bad{background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.3);color:#f87171}

  .cx{background:var(--panel);border:1px solid var(--border);border-radius:var(--raio);
      padding:16px 18px;margin-bottom:14px}
  .cx h2{font-size:11px;font-weight:900;letter-spacing:1.2px;text-transform:uppercase;
         color:var(--text-3);margin-bottom:12px}

  /* ── Estado / controles ───────────────────────────────────────── */
  .estado{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:14px}
  .selo{font-size:10.5px;font-weight:900;letter-spacing:.7px;text-transform:uppercase;
        padding:5px 11px;border-radius:999px;border:1px solid}
  .selo.aberta{background:rgba(34,197,94,.12);border-color:rgba(34,197,94,.35);color:#4ade80}
  .selo.fechada{background:rgba(239,68,68,.1);border-color:rgba(239,68,68,.3);color:#f87171}
  .selo.rodada{background:rgba(245,158,11,.12);border-color:rgba(245,158,11,.35);color:var(--ouro-2)}
  .selo.neutro{background:var(--panel-2);border-color:var(--border-md);color:var(--text-2)}

  .bt{font-family:inherit;font-size:12.5px;font-weight:800;border-radius:10px;padding:9px 15px;
      cursor:pointer;border:1px solid var(--border-md);background:var(--panel-2);color:var(--text);
      display:inline-flex;align-items:center;gap:7px}
  .bt:hover{border-color:var(--ouro);color:var(--ouro-2)}
  .bt.ouro{background:var(--ouro);border-color:var(--ouro);color:#1a1205}
  .bt.ouro:hover{filter:brightness(1.08);color:#1a1205}
  .bt.verde{background:var(--verde);border-color:var(--verde);color:#052e16}
  .bt.verde:hover{filter:brightness(1.08);color:#052e16}
  .bt.perigo{color:#f87171;border-color:rgba(239,68,68,.3)}
  .bt.perigo:hover{border-color:var(--vermelho);color:var(--vermelho)}

  /* ── O chaveamento ────────────────────────────────────────────── */
  /* Rola na horizontal no próprio contêiner: uma copa de 64 tem 6 colunas e
     não cabe em celular nenhum. O corpo da página nunca rola pro lado. */
  .bracket-wrap{overflow-x:auto;padding-bottom:10px;-webkit-overflow-scrolling:touch}
  /* ── Os dois desenhos do mesmo chaveamento ─────────────────────────
     No computador, o espelhado: final no meio e as chaves convergindo dos
     dois lados, que é o formato que todo mundo reconhece. No celular ele
     não cabe — com 6 rodadas são 11 colunas —, então lá fica a fila de
     sempre. A troca é no CSS porque depende da largura da tela, não do
     aparelho: quem virar o celular ou abrir numa janela estreita no PC vê
     o que couber. */
  .so-pc{display:none}
  .so-celular{display:block}
  @media (min-width:900px){
    .so-pc{display:block}
    .so-celular{display:none}
  }

  /* ── Caber sem rolar ────────────────────────────────────────────────
     No espelhado as colunas DIVIDEM a largura disponível em vez de terem
     tamanho fixo: rolar um chaveamento é perder a única coisa que ele faz
     bem, que é mostrar o caminho inteiro de uma vez.
     A largura vira um mínimo, não um valor — a coluna encolhe até ali e só
     então o quadro passa a rolar. Numa copa de 64 (11 colunas) rolar é
     inevitável; de 16 pra baixo cabe. */
  .espelhado{width:100%}
  .espelhado .coluna{flex:1 1 0;min-width:0}
  /* O aperto é por número de rodadas, e não por largura de tela: são as
     rodadas que dizem quantas colunas existem. */
  .espelhado[data-rodadas="4"]{gap:9px}
  .espelhado[data-rodadas="4"] .coluna{min-width:132px}
  .espelhado[data-rodadas="5"]{gap:7px}
  .espelhado[data-rodadas="5"] .coluna{min-width:118px}
  .espelhado[data-rodadas="5"] .lado{padding:8px 8px;font-size:11.5px}
  .espelhado[data-rodadas="6"]{gap:4px}
  .espelhado[data-rodadas="6"] .coluna{min-width:98px}
  .espelhado[data-rodadas="6"] .lado{padding:7px;font-size:11px;gap:5px}
  .espelhado[data-rodadas="6"] .col-tit{font-size:9px;letter-spacing:.4px}
  /* Com a coluna estreita, foto grande não sobra espaço pro nome. O teto
     vale só no espelhado — no celular a coluna tem largura própria. */
  .espelhado[data-rodadas="5"] .cara{max-width:26px;max-height:26px}
  .espelhado[data-rodadas="6"] .cara{max-width:22px;max-height:22px}
  /* A final é o destino do desenho inteiro, então ela não fica espremida
     entre duas colunas iguais às outras. */
  .espelhado .coluna-final{justify-content:center}
  .espelhado .coluna-final .col-tit{color:var(--ouro)}
  .espelhado .coluna-final .duelo{border-color:rgba(245,158,11,.4);
    box-shadow:0 0 0 1px rgba(245,158,11,.10)}
  .bracket{display:flex;gap:14px;min-width:min-content;align-items:stretch}
  .coluna{display:flex;flex-direction:column;justify-content:space-around;gap:8px;
          min-width:var(--col);flex:none}
  .col-tit{font-size:10px;font-weight:900;letter-spacing:1px;text-transform:uppercase;
           color:var(--text-3);text-align:center;padding-bottom:6px;position:sticky;top:0}
  .col-tit.agora{color:var(--ouro)}

  .duelo{background:var(--panel-2);border:1px solid var(--border);border-radius:11px;
         overflow:hidden;display:flex;flex-direction:column}
  .duelo.ativo{border-color:rgba(245,158,11,.45);box-shadow:0 0 0 1px rgba(245,158,11,.12)}
  .lado{display:flex;align-items:center;gap:8px;padding:9px 11px;position:relative;
        font-size:12.5px;font-weight:700;background:none;border:0;width:100%;text-align:left;
        font-family:inherit;color:var(--text);cursor:default}
  .lado + .lado{border-top:1px solid var(--border)}
  .lado .nome{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
  .lado .n{font-size:11.5px;font-weight:900;color:var(--text-3);font-variant-numeric:tabular-nums}
  /* A barra de proporção fica ATRÁS do nome, não do lado: com 32 confrontos
     na tela, uma barra separada por linha viraria uma parede de gráfico. */
  .lado .barra{position:absolute;inset:0;background:rgba(245,158,11,.13);
               transform-origin:left;transition:transform .3s ease;z-index:0}
  .lado .nome,.lado .n,.lado i{position:relative;z-index:1}
  /* A CARA DO COMPETIDOR.
     O tamanho vem de uma variável no .bracket, então os três botões trocam
     uma linha só e todas as fotos acompanham — inclusive as das rodadas que
     ainda vão aparecer. A coluna cresce junto, senão a foto grande espremeria
     o nome até sobrar reticência. */
  .bracket{--cara:30px;--col:206px}
  .bracket[data-tam="p"]{--cara:20px;--col:196px}
  .bracket[data-tam="g"]{--cara:52px;--col:250px}
  .lado .cara{width:var(--cara);height:var(--cara);border-radius:50%;object-fit:cover;
              flex:none;border:1px solid var(--border-md);background:var(--panel-3)}
  .lado.perde .cara{filter:grayscale(1)}
  .tamanhos{display:flex;align-items:center;gap:5px;margin:-4px 0 12px}
  .tamanhos span{font-size:10px;font-weight:800;letter-spacing:.8px;text-transform:uppercase;
                 color:var(--text-3);margin-right:3px}
  .tam{width:26px;height:24px;border-radius:7px;border:1px solid var(--border-md);
       background:var(--panel-2);color:var(--text-3);font-family:inherit;
       font-size:11px;font-weight:800;cursor:pointer}
  .tam.on{border-color:var(--ouro);color:var(--ouro-2);background:rgba(245,158,11,.1)}
  .modos{display:flex;gap:6px;margin-bottom:8px}
  .modo{font-size:11.5px;font-weight:800;padding:6px 12px;border-radius:8px;
        border:1px solid var(--border-md);background:var(--panel-2);color:var(--text-3);
        font-family:inherit;cursor:pointer}
  .modo.on{border-color:var(--ouro);color:var(--ouro-2);background:rgba(245,158,11,.1)}
  .envio{display:flex;align-items:center;gap:11px;flex-wrap:wrap;margin-bottom:9px}
  .bt-envio{display:inline-flex;align-items:center;gap:7px;font-size:12px;font-weight:800;
            padding:9px 14px;border-radius:10px;cursor:pointer;flex:none;
            border:1px dashed var(--border-md);background:var(--panel-2);color:var(--text-2)}
  .bt-envio:hover{border-color:var(--ouro);color:var(--ouro-2)}
  .envio.ocupado .bt-envio{opacity:.55;pointer-events:none}
  .envio-dica{font-size:11.5px;color:var(--text-3);line-height:1.5;flex:1;min-width:180px}
  .lado.vence{color:var(--ouro-2)}
  .lado.vence .n{color:var(--ouro-2)}
  .lado.perde{opacity:.42}
  .lado.meu::after{content:"";position:absolute;left:0;top:0;bottom:0;width:3px;
                   background:var(--verde);z-index:2}
  button.lado{cursor:pointer}
  button.lado:hover{background:var(--panel-3)}
  /* O confronto em que já votei fica em verde, e não mais em âmbar: a ação
     já foi feita. Mas o clique continua valendo — dá pra trocar de lado
     enquanto a rodada não fecha. */
  .duelo.votado{border-color:rgba(34,197,94,.32)}
  .bye{padding:9px 11px;font-size:11px;font-weight:800;color:var(--text-3);
       text-transform:uppercase;letter-spacing:.6px;border-top:1px solid var(--border)}
  .duelo-pe{padding:5px 11px;font-size:9.5px;font-weight:800;color:var(--text-3);
            border-top:1px solid var(--border);display:flex;justify-content:space-between;gap:8px}
  .vazio-duelo{padding:14px 11px;font-size:11.5px;color:var(--text-3);text-align:center}

  .campeao{background:linear-gradient(135deg,rgba(245,158,11,.16),rgba(245,158,11,.04));
           border:1px solid rgba(245,158,11,.4);border-radius:var(--raio);padding:22px;
           text-align:center;margin-bottom:14px}
  .campeao i{font-size:34px;color:var(--ouro)}
  .campeao .nome{font-size:26px;font-weight:900;margin-top:6px;line-height:1.15}
  .campeao .rot{font-size:10.5px;font-weight:900;letter-spacing:1.6px;
                text-transform:uppercase;color:var(--ouro);margin-top:4px}

  /* ── Formulário e listas ──────────────────────────────────────── */
  label{display:block;font-size:10.5px;font-weight:900;letter-spacing:.8px;
        text-transform:uppercase;color:var(--text-3);margin-bottom:6px}
  input[type=text],textarea,select{width:100%;background:var(--panel-2);color:var(--text);
        border:1px solid var(--border-md);border-radius:10px;padding:10px 12px;
        font-family:inherit;font-size:13px}
  input:focus,textarea:focus,select:focus{outline:none;border-color:var(--ouro)}
  textarea{min-height:180px;resize:vertical;line-height:1.6}
  .campo{margin-bottom:14px}
  .dica{font-size:11.5px;color:var(--text-3);margin-top:6px;line-height:1.5}
  .contador{font-weight:900;color:var(--ouro-2)}
  .presets{display:flex;gap:7px;flex-wrap:wrap;margin-top:8px}
  .preset{font-size:11px;font-weight:800;padding:6px 11px;border-radius:8px;
          border:1px solid var(--border-md);background:var(--panel-2);color:var(--text-2);cursor:pointer}
  .preset:hover{border-color:var(--ouro);color:var(--ouro-2)}

  table{width:100%;border-collapse:collapse;font-size:12.5px}
  th{text-align:left;font-size:9.5px;font-weight:900;letter-spacing:.8px;text-transform:uppercase;
     color:var(--text-3);padding:0 8px 8px}
  td{padding:8px;border-top:1px solid var(--border)}
  /* O CABEÇALHO SEGUE O VALOR. As colunas de número são alinhadas à direita
     e os títulos estavam à esquerda: "ACERTOS" ficava no começo da coluna e
     o 8 no fim dela, a meia tela de distância um do outro. */
  th.num,td.num{text-align:right;font-variant-numeric:tabular-nums}
  td.num{font-weight:800}
  /* O nome fica com a sobra e os números com o que precisam. Sem isto a
     tabela dividia as cinco colunas por igual e o vão entre o nome e o
     primeiro número ficava maior que o nome. */
  th.pos{width:38px}
  th.num{width:96px}
  .rk-nome-col{width:auto}
  td.gm{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:0}
  /* No celular as três colunas de número comiam a tela e sobravam 33px pro
     nome — o suficiente pra "Cai…". Elas encolhem pro tamanho do número,
     que é o que precisam, e a sobra volta pro nome. */
  @media (max-width:560px){
    table{font-size:12px}
    th{font-size:8.5px;letter-spacing:.2px;padding:0 4px 8px}
    td{padding:8px 4px}
    th.pos{width:26px}
    th.num{width:52px}
  }
  tr.eu td{color:var(--ouro-2)}

  .copas{display:flex;gap:8px;flex-wrap:wrap}
  .copa-lk{font-size:11.5px;font-weight:800;padding:7px 12px;border-radius:9px;
           border:1px solid var(--border-md);background:var(--panel-2);color:var(--text-2)}
  .copa-lk.on{border-color:var(--ouro);color:var(--ouro-2);background:rgba(245,158,11,.1)}

  .vazio{text-align:center;padding:34px 16px;color:var(--text-3);font-size:13px;line-height:1.6}

  @media (max-width:620px){
    .wrap{padding:14px 12px}
    .tit{font-size:18px}
    .bracket{--col:184px}
    .bracket[data-tam="g"]{--col:214px}
  }
  <?php /* O sorteio recém-feito entra caindo, um confronto por vez. É o
           momento que a galera assiste — sem isso o chaveamento simplesmente
           aparece pronto e o sorteio não acontece pra ninguém. */ ?>
  @keyframes cai{from{opacity:0;transform:translateY(-10px) scale(.97)}to{opacity:1;transform:none}}
  .sorteando .coluna:first-child .duelo{animation:cai .34s backwards}
  @media (prefers-reduced-motion:reduce){.sorteando .coluna:first-child .duelo{animation:none}}
</style>
</head>
<body>
<div class="wrap">

  <div class="topo">
    <a class="voltar" href="/games.php" title="Voltar aos games"><i class="bi bi-arrow-left"></i></a>
    <div class="tit">
      <small>Copa do Mundo</small>
      <?= $copa ? $esc($copa['titulo']) : 'Nenhuma copa ainda' ?>
    </div>
    <?php /* Copiar link vale pra TODO MUNDO, e não só pro admin: quem está
             gostando da copa é quem chama os outros pra votar, e o voto de
             mais gente é o que faz o resultado valer alguma coisa. */ ?>
    <?php if ($copa && !$novo): ?>
    <?php
      $urlCopa = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
               . '://' . ($_SERVER['HTTP_HOST'] ?? 'fbabrasil.com.br')
               . '/games/games/copamundo.php?copa=' . (int)$copa['id'];

      // O nome da copa vai JUNTO do link. Link solto no grupo não diz do que
      // é nem o que se espera de quem clicar — e quem compartilha acabava
      // digitando isso na frente, toda vez, na mão.
      //
      // A chamada muda com o estado: copa encerrada não tem voto pra pedir, e
      // convidar pra votar no que já acabou faz a pessoa clicar à toa.
      $chamada = $copa['status'] !== 'ativo'
          ? 'Veja como terminou:'
          : (!empty($copa['votacao']) ? 'A votação está aberta — vote aqui:' : 'Acompanhe aqui:');

      $textoCopa = '🏆 ' . $copa['titulo'] . "\n" . $chamada . "\n" . $urlCopa;
    ?>
    <button class="bt" id="btCopiar"
            data-link="<?= $esc($urlCopa) ?>"
            data-texto="<?= $esc($textoCopa) ?>">
      <i class="bi bi-link-45deg"></i> <span>Copiar link</span>
    </button>
    <?php endif; ?>
    <?php if ($isAdmin && !$novo): ?>
    <a class="bt ouro" href="?nova=1"><i class="bi bi-plus-lg"></i> Nova copa</a>
    <?php endif; ?>
  </div>

  <?php if ($msg): ?><div class="aviso ok"><i class="bi bi-check-circle-fill"></i><span><?= $esc($msg) ?></span></div><?php endif; ?>
  <?php if ($erro): ?><div class="aviso bad"><i class="bi bi-exclamation-triangle-fill"></i><span><?= $esc($erro) ?></span></div><?php endif; ?>

<?php if ($novo): /* ── CRIAR ────────────────────────────────────────── */ ?>
  <div class="cx">
    <h2>Criar uma copa</h2>
    <form method="post">
      <input type="hidden" name="acao" value="criar">
      <input type="hidden" name="modo" id="campoModo" value="nomes">
      <div class="campo">
        <label>Do que é a copa?</label>
        <input type="text" name="titulo" maxlength="120" required
               placeholder="Copa do Mundo dos Salgados, Melhor Camisa da NBA…"
               value="<?= $esc($form['titulo']) ?>">
      </div>
      <div class="campo">
        <label>Competidores <span class="contador" id="conta"></span></label>
        <?php /* Dois modos porque são dois trabalhos diferentes: colar uma
                 lista que já existe, ou montar uma copa em que a cara de cada
                 competidor é metade da graça. Um campo só, com a foto sempre
                 opcional, faria o modo simples parecer incompleto. */ ?>
        <div class="modos">
          <button type="button" class="modo on" data-modo="nomes">Só nomes</button>
          <button type="button" class="modo" data-modo="fotos">Nome e foto</button>
        </div>

        <?php /* O envio fica junto do modo com foto e não num passo separado:
                 escolher os arquivos JÁ é montar a lista — cada foto vira uma
                 linha "Nome | url" com o nome tirado do arquivo, pronta pra
                 corrigir antes de sortear. */ ?>
        <div class="envio" id="envio" style="display:none">
          <label class="bt-envio">
            <i class="bi bi-upload"></i> Enviar fotos do computador
            <input type="file" id="arquivos" accept="image/*" multiple hidden>
          </label>
          <span class="envio-dica" id="envioDica">
            Dá pra escolher várias de uma vez — o nome do arquivo vira o nome
            do competidor.
          </span>
        </div>
        <textarea name="nomes" id="nomes" required
                  placeholder="Coxinha&#10;Pastel&#10;Empada&#10;Kibe"><?= $esc($form['nomes']) ?></textarea>
        <div class="dica" id="previsao">
          Pode colar uma lista pronta — vírgula também separa. Nomes repetidos
          e linhas vazias são descartados.
        </div>
        <div class="dica" id="dicaFoto" style="display:none">
          Uma linha por competidor: <b>Nome | link da foto</b>. Quem ficar sem
          link aparece só com o nome — dá pra misturar.
        </div>
        <?php /* Os presets não travam nada: são só um atalho pra saber quantos
                 faltam. Qualquer número de 2 a 64 monta chaveamento.

                 O rótulo perdeu o "competidores" e virou só o número: com a
                 palavra em cada botão, cinco botões grandes pareciam cinco
                 OPÇÕES de tamanho, e não uma régua. A frase acima diz o que
                 eles são, uma vez só. */ ?>
        <div class="dica" style="margin-top:10px">
          Vale <b>qualquer número de 2 a <?= COPA_MAX ?></b> — não precisa ser
          8, 16 ou 32. Quando não fecha em potência de 2, quem sobra
          <b>passa direto na primeira rodada</b>, e quem passa é sorteado
          junto com o resto.
        </div>
        <div class="presets">
          <?php foreach (COPA_TAMANHOS as $tam): ?>
          <button type="button" class="preset" data-alvo="<?= $tam ?>"><?= $tam ?></button>
          <?php endforeach; ?>
        </div>
      </div>
      <div style="display:flex;gap:9px;flex-wrap:wrap">
        <button class="bt ouro"><i class="bi bi-shuffle"></i> Sortear o chaveamento</button>
        <a class="bt" href="?">Cancelar</a>
      </div>
    </form>
  </div>

<?php elseif (!$copa): ?>
  <div class="cx"><div class="vazio">
    Nenhuma copa foi criada ainda.<br>
    <?= $isAdmin ? 'Clique em <b>Nova copa</b> pra começar.' : 'Assim que o admin criar uma, ela aparece aqui.' ?>
  </div></div>

<?php else: /* ── A COPA ───────────────────────────────────────────── */
  $encerrada = $copa['status'] !== 'ativo';
  $rodAtual  = (int)$copa['rodada_atual'];
  $votando   = !$encerrada && !empty($copa['votacao']);
?>

  <?php if ($encerrada && $copa['campeao_id']): ?>
  <div class="campeao">
    <i class="bi bi-trophy-fill"></i>
    <div class="nome"><?= $esc($nomeDe((int)$copa['campeao_id'])) ?></div>
    <div class="rot">Campeão · <?= $esc($copa['titulo']) ?></div>
  </div>
  <?php endif; ?>

  <div class="estado">
    <span class="selo rodada"><?= $esc(copaNomeRodada($rodAtual, $rodadas)) ?></span>
    <?php if (!$encerrada): ?>
      <span class="selo <?= $votando ? 'aberta' : 'fechada' ?>"
            <?= $votando ? 'title="Dá pra trocar o voto até a rodada ser apurada"' : '' ?>>
        <?= $votando ? 'Votação aberta' : 'Votação fechada' ?>
      </span>
      <?php if ($votando): ?>
        <span class="selo neutro" style="font-weight:600">
          <i class="bi bi-arrow-repeat"></i> dá pra mudar o voto
        </span>
        <?php /* O relógio da rodada. O servidor manda o instante do fim e o
                 navegador conta sozinho: contar no PHP daria um número parado
                 que envelhece na tela aberta. */ ?>
        <?php if (!empty($copa['fecha_em'])): ?>
          <span class="selo aberta" id="relogio"
                data-fim="<?= $esc(str_replace(' ', 'T', (string)$copa['fecha_em'])) ?>"
                title="Quando o tempo acabar, a rodada é apurada e a próxima abre sozinha">
            <i class="bi bi-clock"></i> <span id="relogioTxt">…</span>
          </span>
        <?php endif; ?>
      <?php endif; ?>
    <?php else: ?>
      <span class="selo neutro">Encerrada</span>
    <?php endif; ?>
    <span class="selo neutro"><?= (int)$copa['tamanho'] ?> competidores</span>
    <?php if ($minhaSeq && (int)$minhaSeq['pontos'] > 0): ?>
      <span class="selo neutro">
        Você: <?= (int)$minhaSeq['pontos'] ?> pts
        <?php if ((int)$minhaSeq['sequencia'] > 0): ?>· 🔥 sequência <?= (int)$minhaSeq['sequencia'] + 1 ?><?php endif; ?>
      </span>
    <?php endif; ?>
  </div>

  <?php if ($isAdmin && !$encerrada): ?>
  <div class="cx">
    <h2>Controle do admin</h2>
    <div style="display:flex;gap:9px;flex-wrap:wrap;align-items:center">
      <form method="post" style="display:inline">
        <input type="hidden" name="acao" value="votacao">
        <input type="hidden" name="torneio_id" value="<?= (int)$copa['id'] ?>">
        <input type="hidden" name="abrir" value="<?= $votando ? '' : '1' ?>">
        <button class="bt <?= $votando ? '' : 'verde' ?>">
          <i class="bi bi-<?= $votando ? 'pause-fill' : 'play-fill' ?>"></i>
          <?= $votando ? 'Fechar a votação' : 'Abrir a votação' ?>
        </button>
      </form>

      <form method="post" style="display:inline" data-confirmar="Apurar os votos, pagar quem acertou e montar a próxima rodada? Não dá pra desfazer.">
        <input type="hidden" name="acao" value="fechar">
        <input type="hidden" name="torneio_id" value="<?= (int)$copa['id'] ?>">
        <button class="bt ouro"><i class="bi bi-flag-fill"></i>
          Apurar <?= $esc(mb_strtolower(copaNomeRodada($rodAtual, $rodadas))) ?> e avançar
        </button>
      </form>

      <?php /* Quanto tempo cada rodada dura. Zero devolve a copa ao modo
               antigo, em que ela só anda quando alguém aperta o botão. */ ?>
      <form method="post" style="display:flex;gap:6px;align-items:center">
        <input type="hidden" name="acao" value="minutos">
        <input type="hidden" name="torneio_id" value="<?= (int)$copa['id'] ?>">
        <label style="font-size:12px;color:var(--text3)" for="minutosRodada">rodada de</label>
        <input id="minutosRodada" type="number" name="minutos" min="0" max="10080"
               value="<?= (int)($copa['minutos_rodada'] ?? 30) ?>"
               style="width:74px;text-align:center">
        <span style="font-size:12px;color:var(--text3)">min</span>
        <button class="bt"><i class="bi bi-check2"></i> salvar</button>
      </form>

      <span class="dica" style="margin:0">
        <?= copaQuantosVotaram($pdo, (int)$copa['id'], $rodAtual) ?> pessoa(s) votaram nesta rodada.
        <?php if ((int)($copa['minutos_rodada'] ?? 0) === 0): ?>
          <br>Com 0 minuto, a rodada só vira quando você apurar aqui.
        <?php else: ?>
          <br>Ao abrir a votação, a rodada vira sozinha depois de
          <?= (int)$copa['minutos_rodada'] ?> min e a próxima já começa.
        <?php endif; ?>
      </span>

      <form method="post" style="margin-left:auto"
            data-confirmar="APAGAR a copa inteira, com votos e pontuação? Não dá pra desfazer.">
        <input type="hidden" name="acao" value="apagar">
        <input type="hidden" name="torneio_id" value="<?= (int)$copa['id'] ?>">
        <button class="bt perigo"><i class="bi bi-trash"></i> Apagar</button>
      </form>
    </div>
  </div>
  <?php endif; ?>

  <div class="cx">
    <h2>O chaveamento</h2>
    <?php /* O controle só aparece na copa que TEM foto. Numa copa só de
             nomes ele seria um botão que não muda nada visível. */ ?>
    <?php if (array_filter(array_column($comps, 'foto'))): ?>
    <div class="tamanhos">
      <span>Foto</span>
      <button type="button" class="tam" data-tam="p">P</button>
      <button type="button" class="tam on" data-tam="m">M</button>
      <button type="button" class="tam" data-tam="g">G</button>
    </div>
    <?php endif; ?>
    <?php
      /**
       * O DESENHO DE UM CONFRONTO.
       *
       * Virou função porque o chaveamento é desenhado DUAS vezes: espelhado
       * no computador (final no meio, chaves convergindo dos dois lados) e
       * em fila no celular, onde espelhar não cabe. Um confronto desenhado
       * em dois lugares divergiria na primeira mudança.
       */
      $desenhaDuelo = function ($c, $r) use ($copa, $comps, $esc, $nomeDe, $votando, $rodAtual, $encerrada, $userId) {
          $aId  = (int)$c['a_id'];
          $bId  = (int)$c['b_id'];
          $venc = (int)$c['vencedor_id'];
          $bye  = !$bId;
          // Quem já votou continua com os botões: o voto agora troca de lado
          // enquanto a rodada está aberta. Antes o markup virava div depois
          // do primeiro clique, e quem errou o botão ficava preso — o que só
          // fazia sentido quando o placar aparecia e trocar era vantagem.
          $jaVotei   = !empty($c['meu_voto']);
          $podeVotar = $votando && $r === $rodAtual && !$venc && $bId && $userId;
          $total = (int)$c['votos_a'] + (int)$c['votos_b'];
          // A barra mostra a fatia de cada um. Sem voto nenhum ela fica em
          // zero — 50/50 numa disputa vazia parece empate técnico, e não é.
          $fa = $total ? (int)$c['votos_a'] / $total : 0;
          $fb = $total ? (int)$c['votos_b'] / $total : 0;
          // CONFRONTO EM ABERTO NÃO MOSTRA PLACAR. Quem chega depois via pra
          // onde a manada estava indo e ia junto — a copa media quem votava
          // por último, não quem lê melhor. Os números vêm zerados do motor
          // (copaChave); aqui é só a tela deixar de reservar lugar pra eles.
          $oculto      = !empty($c['placar_oculto']);
          $mostraVotos = !$oculto;

          // Os dois lados saem do mesmo molde: botão quando dá pra votar,
          // div quando não. Duplicar o markup faria o placar divergir de um
          // lado pro outro na primeira mudança.
          $lado = function ($id, $votos, $fatia) use ($c, $venc, $podeVotar, $copa, $esc, $nomeDe, $mostraVotos, $comps) {
              if (!$id) return;
              $cls = 'lado';
              if ($venc) $cls .= $venc === $id ? ' vence' : ' perde';
              if ((int)$c['meu_voto'] === $id) $cls .= ' meu';
              $tag = $podeVotar ? 'button' : 'div';
              ?>
              <?php if ($podeVotar): ?>
              <form method="post" style="display:contents">
                <input type="hidden" name="acao" value="votar">
                <input type="hidden" name="torneio_id" value="<?= (int)$copa['id'] ?>">
                <input type="hidden" name="confronto_id" value="<?= (int)$c['id'] ?>">
                <input type="hidden" name="escolha_id" value="<?= $id ?>">
              <?php endif; ?>
              <<?= $tag ?> class="<?= $cls ?>"<?= $podeVotar ? ' type="submit"' : '' ?>>
                <span class="barra" style="transform:scaleX(<?= round($fatia, 3) ?>)"></span>
                <?php if ($venc === $id): ?><i class="bi bi-caret-right-fill"></i><?php endif; ?>
                <?php /* A foto só entra pra quem tem. O onerror remove a
                         imagem em vez de trocar por um placeholder: numa
                         copa sem fotos, um avatar cinza em toda linha
                         ocuparia espaço sem dizer nada. */ ?>
                <?php if ($f = ($comps[$id]['foto'] ?? null)): ?>
                <img class="cara" src="<?= $esc($f) ?>" alt="" loading="lazy" onerror="this.remove()">
                <?php endif; ?>
                <?php /* O title repete o nome porque a coluna estreita corta
                         com reticência — numa copa de 64 quase todo nome
                         longo aparece cortado, e passar o mouse é o jeito de
                         ler o inteiro sem rolar a chave. */ ?>
                <span class="nome" title="<?= $esc($nomeDe($id)) ?>"><?= $esc($nomeDe($id)) ?></span>
                <?php if ($mostraVotos): ?><span class="n"><?= (int)$votos ?></span><?php endif; ?>
              </<?= $tag ?>>
              <?php if ($podeVotar): ?></form><?php endif; ?>
              <?php
          };
          ?>
          <?php /* Votado manda no visual: quem já escolheu vê verde, e o
                   âmbar de "ativo" fica pros que ainda faltam — que é o que
                   a pessoa procura ao descer a chave. */ ?>
          <div class="duelo <?= $jaVotei ? 'votado' : ($podeVotar ? 'ativo' : '') ?>">
            <?php $lado($aId, $c['votos_a'], $fa); ?>
            <?php if ($bye): ?>
              <div class="bye"><i class="bi bi-fast-forward-fill"></i> passou sem confronto</div>
            <?php else: $lado($bId, $c['votos_b'], $fb); endif; ?>

            <?php if (!empty($c['no_sorteio']) || ($c['meu_voto'] && $venc)): ?>
            <div class="duelo-pe">
              <?php if (!empty($c['no_sorteio'])): ?><span>empate · decidido no sorteio</span><?php endif; ?>
              <?php if ($c['meu_voto'] && $venc): ?>
                <span style="color:<?= (int)$c['meu_voto'] === $venc ? 'var(--verde)' : 'var(--vermelho)' ?>">
                  <?= (int)$c['meu_voto'] === $venc ? 'você acertou' : 'você errou' ?>
                </span>
              <?php endif; ?>
            </div>
            <?php endif; ?>
          </div>
          <?php
      };

      /** Uma coluna: o nome da rodada e os confrontos que forem passados. */
      $desenhaColuna = function ($r, $lista) use ($desenhaDuelo, $esc, $rodadas, $rodAtual, $encerrada) {
          ?>
          <div class="coluna">
            <div class="col-tit <?= (!$encerrada && $r === $rodAtual) ? 'agora' : '' ?>">
              <?= $esc(copaNomeRodada($r, $rodadas)) ?>
            </div>
            <?php if (!$lista): ?>
              <div class="duelo"><div class="vazio-duelo">aguardando</div></div>
            <?php else: foreach ($lista as $c) $desenhaDuelo($c, $r); endif; ?>
          </div>
          <?php
      };

      /**
       * A metade da chave que vai pra cada lado.
       *
       * O chaveamento espelhado parte a rodada ao meio: os primeiros
       * confrontos sobem pela esquerda, os últimos pela direita, e os dois
       * caminhos só se encontram na final. Como cada rodada tem metade dos
       * confrontos da anterior, a divisão pelo meio segue valendo em todas
       * — o vencedor do primeiro confronto da esquerda continua na esquerda
       * até chegar lá.
       *
       * A final não divide: ela É o centro.
       */
      $metade = function ($r, $lado) use ($chave, $rodadas) {
          $l = $chave[$r] ?? [];
          if ($r >= $rodadas) return $l;
          $n = intdiv(count($l), 2);
          return $lado === 'e' ? array_slice($l, 0, $n) : array_slice($l, $n);
      };
    ?>

    <?php /* ESPELHADO — só no computador. Final no meio, chaves subindo dos
             dois lados. É o formato que todo mundo reconhece de tabela de
             mata-mata, e ele precisa de largura: com 6 rodadas são 11
             colunas, o que não cabe em tela de celular. */ ?>
    <div class="bracket-wrap<?= $destaqueSorteio ? ' sorteando' : '' ?> so-pc">
      <div class="bracket espelhado" data-rodadas="<?= (int)$rodadas ?>">
        <?php for ($r = 1; $r < $rodadas; $r++) $desenhaColuna($r, $metade($r, 'e')); ?>

        <div class="coluna coluna-final">
          <div class="col-tit <?= (!$encerrada && $rodadas === $rodAtual) ? 'agora' : '' ?>">
            <?= $esc(copaNomeRodada($rodadas, $rodadas)) ?>
          </div>
          <?php if (empty($chave[$rodadas])): ?>
            <div class="duelo"><div class="vazio-duelo">aguardando</div></div>
          <?php else: foreach ($chave[$rodadas] as $c) $desenhaDuelo($c, $rodadas); endif; ?>
        </div>

        <?php for ($r = $rodadas - 1; $r >= 1; $r--) $desenhaColuna($r, $metade($r, 'd')); ?>
      </div>
    </div>

    <?php /* EM FILA — só no celular, e igual ao que sempre foi: uma coluna
             por rodada, rolando pro lado dentro do próprio quadro. */ ?>
    <div class="bracket-wrap<?= $destaqueSorteio ? ' sorteando' : '' ?> so-celular">
      <div class="bracket linear">
        <?php for ($r = 1; $r <= $rodadas; $r++) $desenhaColuna($r, $chave[$r] ?? []); ?>
      </div>
    </div>

    <?php $pagaAgora = copaRodadaPaga($rodAtual, $rodadas); ?>
    <?php if (!$encerrada && !$votando): ?>
    <div class="dica" style="margin-top:12px">
      <?php /* Dizia que "dá pra trocar o voto enquanto a rodada estiver
               aberta", e não dá: copaVotar recusa o segundo voto desde que a
               troca foi fechada. A tela prometia o contrário das outras duas
               dicas logo abaixo. */ ?>
      A votação está fechada. Quando o admin abrir, é só clicar no competidor
      pra votar — <b>o voto não muda depois</b>, e o placar só aparece quando
      a rodada for apurada.
    </div>
    <?php elseif ($votando && $pagaAgora): ?>
    <div class="dica" style="margin-top:12px">
      Clique no nome pra votar — <b>o voto não muda depois</b>, e <b>ninguém vê o
      placar até a rodada ser apurada</b>. Cada palpite certo vale FBA Points,
      e quem acerta a maioria da rodada aumenta a sequência: o próximo acerto
      passa a valer mais.
    </div>
    <?php elseif ($votando): ?>
    <?php /* A rodada conta pro chaveamento mas não paga. Dizer isso na hora
             de votar evita a decepção depois — e é justo: a pessoa decide se
             quer votar sabendo o que ganha. */ ?>
    <div class="dica" style="margin-top:12px">
      Clique no nome pra votar — <b>o voto não muda depois</b>, e <b>ninguém vê o
      placar até a rodada ser apurada</b>.
      <b>Esta fase ainda não paga FBA Points</b>: eles começam nas oitavas de
      final, e a sequência começa a contar lá. O voto de agora decide quem
      chega lá.
    </div>
    <?php endif; ?>
  </div>

  <?php if ($ranking): ?>
  <div class="cx">
    <h2>Quem está indo bem</h2>
    <table>
      <thead><tr><th class="pos">#</th><th class="rk-nome-col">GM</th><th class="num">Acertos</th><th class="num">Sequência</th><th class="num">FBA Points</th></tr></thead>
      <tbody>
        <?php foreach ($ranking as $i => $r): ?>
        <tr class="<?= (int)$r['user_id'] === $userId ? 'eu' : '' ?>">
          <td><?= $i + 1 ?></td>
          <td class="gm"><?= $esc($r['nome']) ?></td>
          <td class="num"><?= (int)$r['acertos'] ?></td>
          <td class="num"><?= (int)$r['sequencia'] > 0 ? '🔥 ' . ((int)$r['sequencia'] + 1) : '—' ?></td>
          <td class="num"><?= (int)$r['pontos'] ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
<?php endif; ?>

  <?php if (count($lista) > 1 || ($lista && !$novo)): ?>
  <div class="cx">
    <h2>Todas as copas</h2>
    <div class="copas">
      <?php foreach ($lista as $c): ?>
      <a class="copa-lk <?= $copa && (int)$c['id'] === (int)$copa['id'] ? 'on' : '' ?>"
         href="?copa=<?= (int)$c['id'] ?>">
        <?= $esc($c['titulo']) ?>
        <?php if ($c['status'] !== 'ativo'): ?> · encerrada<?php endif; ?>
      </a>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

</div>

<?php if ($novo): ?>
<script>
/* Conta os nomes enquanto a pessoa digita e diz o que vai sair: quantos
   jogam a primeira rodada e quantos passam direto. Descobrir isso só depois
   de sortear seria tarde — o sorteio não se desfaz. */
(function () {
  var ta = document.getElementById('nomes');
  var conta = document.getElementById('conta');
  var prev = document.getElementById('previsao');
  var base = prev.textContent;

  function modoAtual() {
    var b = document.querySelector('.modo.on');
    return b ? b.dataset.modo : 'nomes';
  }

  function limpos() {
    var vistos = {}, out = [];
    // No modo com foto a vírgula NÃO separa: ela aparece dentro de URL
    // (data: URIs vivem cheias delas) e cortar ali partiria o link no meio.
    var pedacos = modoAtual() === 'fotos' ? ta.value.split('\n') : ta.value.split(/[\n,]/);
    pedacos.forEach(function (linha) {
      // Só o que vem ANTES da barra é o nome — é ele que conta e que não
      // pode repetir.
      var n = String(linha).split('|')[0].trim().replace(/\s+/g, ' ');
      if (!n) return;
      var k = n.toLowerCase();
      if (vistos[k]) return;
      vistos[k] = 1;
      out.push(n);
    });
    return out;
  }

  function atualizar() {
    var n = limpos().length;
    conta.textContent = n ? '(' + n + ')' : '';
    if (n < 2) { prev.textContent = base; return; }
    if (n > 64) { prev.textContent = 'São ' + n + ' — o máximo é 64.'; return; }

    var slots = 2; while (slots < n) slots *= 2;
    var byes = slots - n;
    var rodadas = Math.round(Math.log2(slots));
    var txt = n + ' competidores · chaveamento de ' + slots + ' · ' + rodadas + ' rodadas até o campeão';
    if (byes > 0) txt += byes === 1 ? ' · 1 passa sem confronto na primeira (sorteado)'
                                    : ' · ' + byes + ' passam sem confronto na primeira (sorteados)';
    prev.textContent = txt;
  }

  ta.addEventListener('input', atualizar);
  atualizar();

  /* ── Os dois modos de entrada ─────────────────────────────────────── */
  var campoModo = document.getElementById('campoModo');
  var dicaFoto  = document.getElementById('dicaFoto');
  document.querySelectorAll('.modo').forEach(function (b) {
    b.addEventListener('click', function () {
      document.querySelectorAll('.modo').forEach(function (x) { x.classList.remove('on'); });
      b.classList.add('on');
      var comFoto = b.dataset.modo === 'fotos';
      campoModo.value = b.dataset.modo;
      dicaFoto.style.display = comFoto ? '' : 'none';
      document.getElementById('envio').style.display = comFoto ? '' : 'none';
      // A previsão do chaveamento fica nos DOIS modos: saber quantas rodadas
      // e quantos byes vão sair importa igual, com foto ou sem.
      ta.placeholder = comFoto
        ? 'Coxinha | https://exemplo.com/coxinha.jpg\nPastel | https://exemplo.com/pastel.jpg\nEmpada'
        : 'Coxinha\nPastel\nEmpada\nKibe';
      atualizar();
    });
  });

  /* ── Envio de fotos ───────────────────────────────────────────────
   *
   * Cada foto que sobe vira uma linha "Nome | url" no fim do textarea. O
   * texto que já estava fica: quem envia em duas levas não perde a
   * primeira, e quem já digitou nomes à mão vê as fotos chegarem embaixo.
   *
   * Envia TUDO numa requisição só. Uma por arquivo seriam 32 idas ao
   * servidor pra montar uma copa, e a primeira que falhasse deixaria a
   * lista pela metade sem ninguém saber quais entraram.
   */
  var envio = document.getElementById('envio');
  var inputArq = document.getElementById('arquivos');
  var envioDica = document.getElementById('envioDica');
  var dicaBase = envioDica ? envioDica.textContent : '';

  if (inputArq) inputArq.addEventListener('change', async function () {
    var arqs = [...inputArq.files];
    if (!arqs.length) return;

    var fd = new FormData();
    arqs.forEach(function (f) { fd.append('fotos[]', f); });

    envio.classList.add('ocupado');
    envioDica.textContent = 'Enviando ' + arqs.length + ' foto(s)…';

    try {
      var r = await fetch('/games/api/copa-foto.php', {
        method: 'POST', body: fd, credentials: 'same-origin'
      });
      var d = await r.json();
      if (!d.ok) throw new Error(d.erro || 'não deu');

      var linhas = d.fotos.map(function (f) { return f.nome + ' | ' + f.url; });
      if (linhas.length) {
        var atual = ta.value.replace(/\s*$/, '');
        ta.value = (atual ? atual + '\n' : '') + linhas.join('\n');
        ta.dispatchEvent(new Event('input'));
      }

      // Os que falharam aparecem NOMEADOS. "3 de 16 falharam" faria a pessoa
      // conferir as dezesseis pra descobrir quais.
      envioDica.textContent = linhas.length + ' foto(s) na lista.'
        + (d.erros && d.erros.length ? ' Fora: ' + d.erros.join('; ') : '');
    } catch (e) {
      envioDica.textContent = 'Não deu pra enviar: ' + (e.message || 'erro');
    } finally {
      envio.classList.remove('ocupado');
      // Zera o input pra escolher o MESMO arquivo de novo disparar o change.
      inputArq.value = '';
      setTimeout(function () { envioDica.textContent = dicaBase; }, 6000);
    }
  });

  document.querySelectorAll('.preset').forEach(function (b) {
    b.addEventListener('click', function () {
      // Diz o que aquele número PRODUZ, e não só quantos faltam: o alvo de
      // 40 vira um chaveamento de 64 com 24 passando direto, e é isso que
      // ajuda a escolher — a diferença entre 40 e 48 é quantos passam sem
      // jogar, não o número em si.
      var alvo = Number(b.dataset.alvo);
      var faltam = alvo - limpos().length;
      var slots = 2; while (slots < alvo) slots *= 2;
      var byes = slots - alvo;
      var forma = alvo + ' → chaveamento de ' + slots
                + (byes ? ', ' + byes + ' passam direto' : ', sem bye');

      prev.textContent = (faltam > 0 ? 'Faltam ' + faltam + '. '
                        : faltam < 0 ? 'Passou ' + (-faltam) + '. '
                        : 'É exatamente isso. ') + forma + '.';
    });
  });
})();
</script>
<?php endif; ?>

<?php /* Os popups do site. Sem isto o caminho de reserva do "copiar link"
         cairia no prompt() do navegador, que é justamente o que foi tirado
         do resto do app. */ ?>
<script>
/* ── Tamanho da foto no chaveamento ──────────────────────────────────
 *
 * Troca uma variável CSS no .bracket, e não o width de cada imagem: as
 * fotos das rodadas futuras ainda nem existem no DOM quando a escolha é
 * feita, e nascem já no tamanho certo por herdarem a variável.
 *
 * A escolha fica no localStorage porque é preferência de quem olha, não
 * da copa: cada um enxerga de um jeito, e ninguém quer reescolher a cada
 * rodada que abre.
 */
(function () {
  // Os DOIS chaveamentos — o espelhado e o em fila. Só um está visível por
  // vez, mas quem gira o celular ou redimensiona a janela troca de um pro
  // outro sem recarregar, e o tamanho tem que valer nos dois.
  var brs = document.querySelectorAll('.bracket');
  var bts = document.querySelectorAll('.tam');
  if (!brs.length || !bts.length) return;

  function aplicar(t, guardar) {
    brs.forEach(function (br) { br.dataset.tam = t; });
    bts.forEach(function (b) { b.classList.toggle('on', b.dataset.tam === t); });
    if (guardar) { try { localStorage.setItem('copa-tam-foto', t); } catch (e) {} }
  }

  var salvo = null;
  try { salvo = localStorage.getItem('copa-tam-foto'); } catch (e) {}
  aplicar(salvo === 'p' || salvo === 'g' ? salvo : 'm', false);

  bts.forEach(function (b) {
    b.addEventListener('click', function () { aplicar(b.dataset.tam, true); });
  });
})();
</script>

<script>
/* ── O relógio da rodada ─────────────────────────────────────────────
 *
 * O servidor manda o instante em que a rodada vira; a contagem é feita
 * aqui porque um número calculado no PHP nasce velho e envelhece mais a
 * cada minuto que a aba fica aberta.
 *
 * Zerou, a página recarrega uma vez: o cron já virou a rodada do lado de
 * lá, e insistir num chaveamento vencido é pior que um recarregamento.
 */
(function () {
  var el = document.getElementById('relogio');
  var txt = document.getElementById('relogioTxt');
  if (!el || !txt) return;

  var fim = new Date(el.dataset.fim).getTime();
  if (!fim) { el.hidden = true; return; }

  var recarregou = false;
  function tique() {
    var falta = Math.floor((fim - Date.now()) / 1000);
    if (falta <= 0) {
      txt.textContent = 'apurando…';
      // Um respiro pro cron fechar antes de recarregar, e uma vez só.
      if (!recarregou) { recarregou = true; setTimeout(function () { location.reload(); }, 8000); }
      return;
    }
    var m = Math.floor(falta / 60), s = falta % 60;
    txt.textContent = m + ':' + (s < 10 ? '0' : '') + s;
    // Abaixo de dois minutos o selo avisa que está no fim.
    el.classList.toggle('fechada', falta <= 120);
    el.classList.toggle('aberta', falta > 120);
  }
  tique();
  setInterval(tique, 1000);
})();
</script>

<script src="/js/popups.js"></script>
<script>
/**
 * COPIAR O LINK DA COPA.
 *
 * O clipboard moderno só existe em HTTPS (e em localhost). Em produção é
 * HTTPS, mas quem abrir por http puro cairia num erro silencioso — daí o
 * caminho antigo com textarea + execCommand como reserva.
 *
 * E se os dois falharem, o link é SELECIONADO na tela: a pessoa copia na
 * mão. Um botão de copiar que não copia e não diz nada é pior que não ter
 * botão.
 */
(function () {
  var bt = document.getElementById('btCopiar');
  if (!bt) return;
  var rotulo = bt.querySelector('span');
  var original = rotulo.textContent;
  var timer = null;

  function avisar(texto, ok) {
    rotulo.textContent = texto;
    bt.style.color = ok ? 'var(--verde)' : '';
    bt.style.borderColor = ok ? 'var(--verde)' : '';
    clearTimeout(timer);
    timer = setTimeout(function () {
      rotulo.textContent = original;
      bt.style.color = '';
      bt.style.borderColor = '';
    }, 2200);
  }

  function copiaAntiga(txt) {
    var ta = document.createElement('textarea');
    ta.value = txt;
    // Fora da tela, mas não display:none — o execCommand precisa que o
    // campo esteja de fato no documento e selecionável.
    ta.style.cssText = 'position:fixed;left:-9999px;top:0';
    document.body.appendChild(ta);
    ta.select();
    var deu = false;
    try { deu = document.execCommand('copy'); } catch (e) { deu = false; }
    document.body.removeChild(ta);
    return deu;
  }

  bt.addEventListener('click', async function () {
    // Copia o texto com o NOME da copa e a chamada; o link puro fica de
    // reserva pro último caso, em que a pessoa precisa selecionar na tela e
    // aí um bloco de três linhas atrapalha mais que ajuda.
    var texto = bt.dataset.texto || bt.dataset.link;
    var link = bt.dataset.link;
    try {
      if (navigator.clipboard && window.isSecureContext) {
        await navigator.clipboard.writeText(texto);
        avisar('Link copiado!', true);
        return;
      }
      throw new Error('sem clipboard');
    } catch (e) {
      if (copiaAntiga(texto)) { avisar('Link copiado!', true); return; }
      // Última saída: mostra o link pronto pra copiar na mão.
      window.avisarSite
        ? window.avisarSite('Copie o link:\n\n' + link, 'aviso')
        : prompt('Copie o link:', link);
      avisar('Copie na mão', false);
    }
  });
})();
</script>

<?php if ($destaqueSorteio): ?>
<script>
/* Escalona a entrada dos confrontos sorteados. O atraso vai no style e não
   numa classe por índice: são até 32 confrontos, e 32 regras de CSS pra isso
   seria pior que uma linha de JS. */
document.querySelectorAll('.sorteando .coluna:first-child .duelo').forEach(function (d, i) {
  d.style.animationDelay = Math.min(i * 55, 1800) + 'ms';
});
</script>
<?php endif; ?>
</body>
</html>
