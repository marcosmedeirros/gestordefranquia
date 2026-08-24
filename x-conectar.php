<?php
/**
 * O X (Twitter) DA LIGA: conectar a conta e ver o que já foi pra timeline.
 *
 * Três coisas numa tela só, porque são três coisas que só fazem sentido
 * juntas: a credencial do app, o botão de conectar, e a fila do que saiu.
 * Separar em páginas obrigaria a ir e voltar pra descobrir por que um post
 * não apareceu.
 *
 * Esta página TAMBÉM é o endereço de volta do OAuth (X_CALLBACK): o X manda
 * o navegador de volta pra cá com ?code=... e é aqui que a troca acontece.
 * Por isso o endereço cadastrado no portal do X tem que ser exatamente este.
 */
require_once __DIR__ . '/backend/auth.php';
require_once __DIR__ . '/backend/db.php';
require_once __DIR__ . '/backend/helpers.php';
require_once __DIR__ . '/backend/x_social.php';
// Só pelo WHATSAPP_OVR_MIN_ANUNCIO, que a tela cita pra explicar por que a
// régua daqui é mais alta. Citar o número na mão viraria mentira no dia que
// o outro mudasse.
require_once __DIR__ . '/backend/whatsapp.php';

requireAuth();
$user = getUserSession();
$pdo  = db();
if (!hasAdminAccess($pdo, (int)$user['id'])) {
    http_response_code(403);
    exit('Apenas administradores.');
}

xGarantirTabelas($pdo);
$msg = $erro = null;

/* A volta do OAuth. Vem por GET porque quem redireciona é o X. */
if (isset($_GET['code'], $_GET['state'])) {
    [$ok, $texto] = xConcluirConexao($pdo, (string)$_GET['code'], (string)$_GET['state']);
    $_SESSION['x_flash'] = [$ok ? 'ok' : 'erro', $texto];
    header('Location: /x-conectar.php');
    exit;
}
if (isset($_GET['error'])) {
    $_SESSION['x_flash'] = ['erro', 'O X recusou a autorização: ' . htmlspecialchars((string)$_GET['error'])];
    header('Location: /x-conectar.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $c0 = xConfig($pdo, true) ?: [];
    try {
        if (isset($_POST['salvar_app'])) {
            // O campo do secret aparece cheio de bolinhas quando já existe um
            // guardado: mostrar o secret de verdade na tela é credencial
            // vazando no primeiro print. Só que salvar as bolinhas por cima do
            // secret bom quebraria a conexão em silêncio — então elas, e o
            // campo vazio, querem dizer "não mexe nesse".
            $seg = trim((string)$_POST['client_secret']);
            $mexeu = $seg !== '' && mb_strpos($seg, '•') === false;
            $pdo->prepare("UPDATE x_config SET client_id = ?, client_secret = ? WHERE id = 1")
                ->execute([trim((string)$_POST['client_id']) ?: null, $mexeu ? $seg : ($c0['client_secret'] ?? null)]);
            xConfig($pdo, true);
            $_SESSION['x_flash'] = ['ok', 'Credenciais salvas. Agora clique em "Conectar a conta".'];

        } elseif (isset($_POST['conectar'])) {
            $url = xUrlAutorizacao($pdo);
            if (!$url) {
                $_SESSION['x_flash'] = ['erro', 'Falta o Client ID.'];
            } else {
                header('Location: ' . $url);
                exit;
            }

        } elseif (isset($_POST['desconectar'])) {
            xDesconectar($pdo);
            $_SESSION['x_flash'] = ['ok', 'Conta desconectada. A credencial do app continua salva.'];

        } elseif (isset($_POST['alternar'])) {
            $col = $_POST['alternar'] === 'trade' ? 'postar_trade'
                 : ($_POST['alternar'] === 'news' ? 'postar_news' : 'ativo');
            $pdo->exec("UPDATE x_config SET {$col} = 1 - {$col} WHERE id = 1");
            xConfig($pdo, true);

        } elseif (isset($_POST['teste'])) {
            // O teste entra na FILA, não vai direto: assim ele passa pelo
            // mesmo caminho de um post de verdade — token, cota, espaçamento.
            // Um teste que atalha o caminho normal não prova nada sobre ele.
            $ref = 'teste:' . date('YmdHis');
            if (xEnfileirar($pdo, "Teste da integração da FBA. Se você está lendo isso, funcionou.", 'teste', $ref)) {
                $r = xProcessarFila($pdo, 1);
                $_SESSION['x_flash'] = $r['postados']
                    ? ['ok', 'Postado! Confira a timeline.']
                    : ['erro', 'Entrou na fila mas não saiu agora (' . ($r['motivo'] ?: 'erro') . '). Veja a lista abaixo.'];
            } else {
                $_SESSION['x_flash'] = ['erro', 'Não deu pra enfileirar — a conta está conectada e ligada?'];
            }

        } elseif (isset($_POST['soltar'])) {
            $r = xProcessarFila($pdo, 1);
            $_SESSION['x_flash'] = ['ok', sprintf('Postados: %d, falhas: %d%s',
                $r['postados'], $r['falhas'], $r['motivo'] ? ' (' . $r['motivo'] . ')' : '')];
        }
    } catch (Throwable $e) {
        $_SESSION['x_flash'] = ['erro', 'Deu erro: ' . $e->getMessage()];
    }
    header('Location: /x-conectar.php');
    exit;
}

if (!empty($_SESSION['x_flash'])) {
    [$tipo, $texto] = $_SESSION['x_flash'];
    unset($_SESSION['x_flash']);
    if ($tipo === 'ok') $msg = $texto; else $erro = $texto;
}

$c     = xConfig($pdo, true) ?: [];
$cota  = xCota($pdo);
$fila  = xUltimos($pdo, 25);
$pend  = count(array_filter($fila, fn($f) => empty($f['postado_em'])));
$conectado = !empty($c['refresh_token']);
$esc   = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
?><!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<script>document.documentElement.dataset.theme = localStorage.getItem('fba-theme') || 'dark';</script>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="theme-color" content="#fc0025">
<title>X da Liga — FBA Manager</title>
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
  :root { --red:#fc0025; --bg:#07070a; --panel:#101013; --panel-2:#16161a; --panel-3:#1c1c21;
          --border:rgba(255,255,255,.06); --border-md:rgba(255,255,255,.10);
          --text:#f0f0f3; --text-2:#868690; --text-3:#7d7d85; --green:#22c55e; --amber:#f59e0b;
          --font:'Montserrat',sans-serif; --radius:14px; --radius-sm:10px; }
  :root[data-theme="light"] { --bg:#f6f7fb; --panel:#fff; --panel-2:#f2f4f8; --panel-3:#e9edf4;
          --border:#e3e6ee; --border-md:#d7dbe6; --text:#12141a; --text-2:#5a6070; --text-3:#7a8092; }
  *{box-sizing:border-box} body{margin:0;background:var(--bg);color:var(--text);font-family:var(--font);font-size:14px}
  .wrap{max-width:880px;margin:0 auto;padding:24px 18px 60px}
  .topo{display:flex;align-items:center;gap:12px;margin-bottom:6px}
  .topo h1{font-size:20px;font-weight:800;margin:0}
  .topo a{color:var(--text-3);text-decoration:none;font-size:16px}
  .topo a:hover{color:var(--text)}
  .sub{color:var(--text-3);font-size:13px;margin-bottom:20px;max-width:66ch;line-height:1.55}
  .aviso{border-radius:var(--radius-sm);padding:11px 15px;font-size:13px;margin-bottom:16px;line-height:1.5}
  .aviso.ok{background:color-mix(in srgb,var(--green) 10%,transparent);border:1px solid color-mix(in srgb,var(--green) 30%,transparent);color:var(--green)}
  .aviso.bad{background:color-mix(in srgb,var(--red) 10%,transparent);border:1px solid color-mix(in srgb,var(--red) 30%,transparent);color:var(--red)}
  .cx{background:var(--panel);border:1px solid var(--border);border-radius:var(--radius);padding:18px 20px;margin-bottom:14px}
  .cx h2{font-size:12px;font-weight:800;letter-spacing:.8px;text-transform:uppercase;color:var(--text-3);margin:0 0 14px}
  .status{display:flex;align-items:center;gap:14px;flex-wrap:wrap}
  .bola{width:11px;height:11px;border-radius:50%;flex:none}
  .bola.on{background:var(--green);box-shadow:0 0 0 4px color-mix(in srgb,var(--green) 18%,transparent)}
  .bola.off{background:var(--text-3)}
  .status b{font-size:15px}
  .status .quem{flex:1;min-width:170px}
  .status .quem span{display:block;font-size:12px;color:var(--text-3);font-weight:600;margin-top:2px}
  .num{display:flex;gap:10px;flex-wrap:wrap;margin-top:16px}
  .num div{background:var(--panel-2);border:1px solid var(--border);border-radius:var(--radius-sm);padding:10px 14px;min-width:104px}
  .num b{display:block;font-size:19px;font-weight:800;font-variant-numeric:tabular-nums}
  .num span{font-size:11px;color:var(--text-3);font-weight:700;text-transform:uppercase;letter-spacing:.4px}
  label{display:block;font-size:12px;font-weight:700;color:var(--text-2);margin:0 0 6px}
  input[type=text],input[type=password]{width:100%;background:var(--panel-2);border:1px solid var(--border-md);color:var(--text);
        font-family:var(--font);font-size:13.5px;border-radius:var(--radius-sm);padding:10px 13px;margin-bottom:13px}
  input:focus{outline:none;border-color:color-mix(in srgb,var(--red) 45%,transparent)}
  .btn{background:var(--red);border:0;color:#fff;font-family:var(--font);font-size:13px;font-weight:700;
       border-radius:var(--radius-sm);padding:10px 18px;cursor:pointer}
  .btn:hover{filter:brightness(1.12)}
  .btn.sec{background:var(--panel-3);color:var(--text-2);border:1px solid var(--border-md)}
  .btn.ok{background:var(--green)}
  .linha-btn{display:flex;gap:9px;flex-wrap:wrap}
  .linha-btn form{margin:0}
  .sw{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:11px 0;border-bottom:1px solid var(--border)}
  .sw:last-child{border-bottom:0}
  .sw p{margin:0;font-size:13.5px;font-weight:700}
  .sw p i{display:block;font-style:normal;font-size:12px;color:var(--text-3);font-weight:600;margin-top:2px;max-width:52ch;line-height:1.45}
  .pill{border:0;font-family:var(--font);font-size:11.5px;font-weight:800;border-radius:20px;padding:6px 15px;cursor:pointer;flex:none}
  .pill.on{background:color-mix(in srgb,var(--green) 14%,transparent);color:var(--green)}
  .pill.off{background:var(--panel-3);color:var(--text-3)}
  .post{border-top:1px solid var(--border);padding:12px 0;display:flex;gap:12px;align-items:flex-start}
  .post:first-of-type{border-top:0}
  .post .ic{font-size:15px;flex:none;margin-top:1px}
  .post .txt{flex:1;min-width:0;font-size:13px;line-height:1.5;white-space:pre-wrap;word-break:break-word}
  .post .meta{font-size:11.5px;color:var(--text-3);font-weight:600;margin-top:5px}
  .post .meta a{color:var(--text-3)}
  .post.err .meta{color:var(--red)}
  .vazio{text-align:center;padding:34px 20px;color:var(--text-3)}
  ol.passos{margin:0;padding-left:20px;font-size:13px;color:var(--text-2);line-height:1.7}
  ol.passos code{background:var(--panel-3);border-radius:5px;padding:2px 6px;font-size:12px;word-break:break-all}
  @media (max-width:620px){ .wrap{padding:18px 13px 50px} .num div{flex:1;min-width:0} }
</style>
<script src="/js/popups.js"></script>
</head>
<body>
<div class="wrap">
  <div class="topo">
    <a href="/admin.php" title="Voltar ao admin"><i class="bi bi-arrow-left"></i></a>
    <h1>X da Liga</h1>
  </div>
  <div class="sub">
    Trade bombástica e notícia do The Pathetic viram post na timeline, sozinhos.
    A conta continua sua: o app posta em nome dela, e você segue tuitando normal
    pelo celular. Nada aqui lê ou mexe no que você publica por fora.
  </div>

  <?php if ($msg): ?><div class="aviso ok"><i class="bi bi-check-circle-fill"></i> <?= $esc($msg) ?></div><?php endif; ?>
  <?php if ($erro): ?><div class="aviso bad"><i class="bi bi-exclamation-triangle-fill"></i> <?= $esc($erro) ?></div><?php endif; ?>

  <div class="cx">
    <h2>Situação</h2>
    <div class="status">
      <span class="bola <?= $conectado && !empty($c['ativo']) ? 'on' : 'off' ?>"></span>
      <div class="quem">
        <b><?= $conectado ? '@' . $esc($c['conta'] ?: 'conta conectada') : 'Nenhuma conta conectada' ?></b>
        <span><?= $conectado
            ? (!empty($c['ativo']) ? 'ligado — postando automático' : 'conectado, mas desligado')
            : 'conecte abaixo pra começar' ?></span>
      </div>
      <?php if ($conectado): ?>
      <form method="POST" style="margin:0">
        <button class="pill <?= !empty($c['ativo']) ? 'on' : 'off' ?>" name="alternar" value="ativo">
          <?= !empty($c['ativo']) ? 'LIGADO' : 'DESLIGADO' ?>
        </button>
      </form>
      <?php endif; ?>
    </div>

    <div class="num">
      <div><b><?= (int)$cota['usados'] ?></b><span>posts em <?= $esc($cota['mes']) ?></span></div>
      <div><b><?= (int)$cota['restam'] ?></b><span>ainda cabem</span></div>
      <div><b><?= (int)$pend ?></b><span>na fila</span></div>
    </div>
  </div>

  <?php if ($conectado): ?>
  <div class="cx">
    <h2>O que vira post</h2>
    <form method="POST" class="sw">
      <p>Trades bombásticas
         <i>Só com jogador de <?= X_OVR_MIN_TRADE ?>+ envolvido. O grupo do WhatsApp usa <?= WHATSAPP_OVR_MIN_ANUNCIO ?>, mas a timeline é pública — régua mais alta pra não virar feed de troca de reserva.</i></p>
      <button class="pill <?= !empty($c['postar_trade']) ? 'on' : 'off' ?>" name="alternar" value="trade">
        <?= !empty($c['postar_trade']) ? 'ON' : 'OFF' ?></button>
    </form>
    <form method="POST" class="sw">
      <p>The Pathetic
         <i>Manchete e link quando sai notícia dos graus que já avisam o grupo. Uma vez por notícia: editar depois não posta de novo.</i></p>
      <button class="pill <?= !empty($c['postar_news']) ? 'on' : 'off' ?>" name="alternar" value="news">
        <?= !empty($c['postar_news']) ? 'ON' : 'OFF' ?></button>
    </form>
  </div>
  <?php endif; ?>

  <div class="cx">
    <h2>Credencial do app</h2>
    <?php if (empty($c['client_id'])): ?>
    <ol class="passos" style="margin-bottom:16px">
      <li>Abra <code>developer.x.com</code> e crie um projeto + app (o plano Free serve).</li>
      <li>Em <b>User authentication settings</b>: ligue OAuth 2.0, tipo <b>Web App</b>,
          permissão <b>Read and write</b>.</li>
      <li>Em <b>Callback URI</b> cole exatamente: <code><?= X_CALLBACK ?></code></li>
      <li>Copie o <b>Client ID</b> e o <b>Client Secret</b> e cole aqui embaixo.</li>
    </ol>
    <?php endif; ?>
    <form method="POST">
      <label>Client ID</label>
      <input type="text" name="client_id" value="<?= $esc($c['client_id'] ?? '') ?>" placeholder="OAuth 2.0 Client ID" autocomplete="off">
      <label>Client Secret</label>
      <input type="password" name="client_secret" value="<?= !empty($c['client_secret']) ? '••••••••••••' : '' ?>"
             placeholder="deixe em branco pra não mudar" autocomplete="off">
      <div class="linha-btn">
        <button class="btn" name="salvar_app" value="1">Salvar credencial</button>
      </div>
    </form>
  </div>

  <div class="cx">
    <h2>Conexão</h2>
    <div class="linha-btn">
      <?php if (!$conectado): ?>
        <form method="POST"><button class="btn ok" name="conectar" value="1" <?= empty($c['client_id']) ? 'disabled' : '' ?>>
          <i class="bi bi-twitter-x"></i> Conectar a conta</button></form>
      <?php else: ?>
        <form method="POST"><button class="btn sec" name="teste" value="1">Postar um teste</button></form>
        <form method="POST"><button class="btn sec" name="soltar" value="1">Soltar 1 da fila</button></form>
        <form method="POST" data-confirmar="Desconectar a conta? Os posts param até você conectar de novo.">
          <button class="btn sec" name="desconectar" value="1">Desconectar</button></form>
      <?php endif; ?>
    </div>
  </div>

  <div class="cx">
    <h2>Últimos posts</h2>
    <?php if (!$fila): ?>
      <div class="vazio">Nada ainda. Quando sair uma trade bombástica ou uma notícia, aparece aqui.</div>
    <?php else: foreach ($fila as $f):
      $falhou = empty($f['postado_em']) && (int)$f['tentativas'] >= X_MAX_TENTATIVAS; ?>
      <div class="post <?= $falhou ? 'err' : '' ?>">
        <span class="ic"><?= $f['postado_em'] ? '✅' : ($falhou ? '❌' : '⏳') ?></span>
        <div class="txt"><?= $esc($f['texto']) ?>
          <div class="meta">
            <?php if ($f['postado_em']): ?>
              postado <?= date('d/m \à\s H:i', strtotime($f['postado_em'])) ?>
              <?php if ($f['tweet_id'] && $c['conta']): ?>
                · <a href="https://x.com/<?= $esc($c['conta']) ?>/status/<?= $esc($f['tweet_id']) ?>" target="_blank" rel="noopener">ver na timeline</a>
              <?php endif; ?>
            <?php elseif ($falhou): ?>
              desistiu depois de <?= (int)$f['tentativas'] ?> tentativas — <?= $esc($f['ultimo_erro'] ?: 'sem detalhe') ?>
            <?php else: ?>
              na fila<?= (int)$f['tentativas'] ? ' · ' . (int)$f['tentativas'] . ' tentativa(s): ' . $esc($f['ultimo_erro'] ?: '') : '' ?>
            <?php endif; ?>
          </div>
        </div>
      </div>
    <?php endforeach; endif; ?>
  </div>
</div>
</body>
</html>
