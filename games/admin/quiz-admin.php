<?php
/**
 * Admin do Quiz do Dia — a fila de perguntas.
 *
 * A fila é tudo que tem data_uso NULL, servida na ordem da coluna `ordem`.
 * Uma pergunta só ganha data quando alguém abre o jogo naquele dia, então
 * mexer na fila aqui muda o que vai sair amanhã, nunca o que já saiu.
 *
 * Pergunta já usada não pode ser editada nem apagada: os votos apontam pra
 * ela, e trocar o texto embaixo de um resultado publicado reescreveria a
 * história do dia.
 */

if (session_status() === PHP_SESSION_NONE) session_start();
require __DIR__ . '/../core/conexao.php';

if (!isset($_SESSION['user_id'])) { header('Location: /login.php'); exit; }

$st = $pdo->prepare("SELECT is_admin FROM games_usuarios WHERE id = ?");
$st->execute([(int)$_SESSION['user_id']]);
if ((int)($st->fetchColumn() ?: 0) !== 1) {
    die('Acesso negado: área restrita a administradores.');
}

// As tabelas nascem no jogo; aqui só garanto que existem pra quem abrir o
// admin antes de qualquer pessoa ter aberto o quiz.
$pdo->exec("CREATE TABLE IF NOT EXISTS quiz_perguntas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    pergunta VARCHAR(255) NOT NULL,
    opcoes TEXT NOT NULL,
    ordem INT NOT NULL DEFAULT 0,
    data_uso DATE NULL,
    resolvido_em DATETIME NULL,
    vencedoras VARCHAR(40) NULL,
    total_votos INT NOT NULL DEFAULT 0,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_dia (data_uso),
    INDEX idx_fila (data_uso, ordem, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

require_once __DIR__ . '/../core/quiz_perguntas.php';
quizSemear($pdo);

$msg = ''; $erro = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';
    try {
        if ($acao === 'criar') {
            $pergunta = trim((string)($_POST['pergunta'] ?? ''));
            $opcoes = array_values(array_filter(array_map(
                fn($o) => trim((string)$o), $_POST['opcao'] ?? []
            ), fn($o) => $o !== ''));

            if (mb_strlen($pergunta) < 5)  throw new InvalidArgumentException('Escreva a pergunta.');
            if (count($opcoes) !== 5)      throw new InvalidArgumentException('São exatamente 5 opções.');
            if (count(array_unique($opcoes)) !== 5) throw new InvalidArgumentException('Tem opção repetida.');

            // Entra no fim da fila.
            $fim = (int)$pdo->query("SELECT COALESCE(MAX(ordem), 0) FROM quiz_perguntas")->fetchColumn();
            $pdo->prepare("INSERT INTO quiz_perguntas (pergunta, opcoes, ordem) VALUES (?,?,?)")
                ->execute([mb_substr($pergunta, 0, 255), json_encode($opcoes, JSON_UNESCAPED_UNICODE), $fim + 1]);
            $msg = 'Pergunta cadastrada no fim da fila.';

        } elseif ($acao === 'apagar') {
            // O data_uso IS NULL no WHERE é o que protege o que já foi ao ar.
            $n = $pdo->prepare("DELETE FROM quiz_perguntas WHERE id = ? AND data_uso IS NULL");
            $n->execute([(int)($_POST['id'] ?? 0)]);
            $msg = $n->rowCount() ? 'Pergunta removida da fila.' : 'Essa pergunta já foi usada — não dá pra apagar.';

        } elseif ($acao === 'topo') {
            $id = (int)($_POST['id'] ?? 0);
            $menor = (int)$pdo->query("SELECT COALESCE(MIN(ordem), 0) FROM quiz_perguntas WHERE data_uso IS NULL")->fetchColumn();
            $pdo->prepare("UPDATE quiz_perguntas SET ordem = ? WHERE id = ? AND data_uso IS NULL")
                ->execute([$menor - 1, $id]);
            $msg = 'Essa é a próxima a sair.';
        }
    } catch (InvalidArgumentException $e) {
        $erro = $e->getMessage();
    } catch (Throwable $e) {
        error_log('[quiz-admin] ' . $e->getMessage());
        $erro = 'Falhou ao salvar.';
    }
}

$fila = $pdo->query("SELECT * FROM quiz_perguntas WHERE data_uso IS NULL
                     ORDER BY ordem, id")->fetchAll(PDO::FETCH_ASSOC);
$usadas = $pdo->query("SELECT * FROM quiz_perguntas WHERE data_uso IS NOT NULL
                       ORDER BY data_uso DESC LIMIT 30")->fetchAll(PDO::FETCH_ASSOC);

function e(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function ops(array $p): array { $o = json_decode((string)$p['opcoes'], true); return is_array($o) ? $o : []; }
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Quiz do Dia — admin</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700;800;900&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
:root{
  --bg:#07070a;--panel:#101013;--panel2:#16161a;--panel3:#1c1c21;
  --border:rgba(255,255,255,.07);--border2:rgba(255,255,255,.14);
  --red:#fc0025;--red-soft:rgba(252,0,37,.12);
  --text:#f0f0f3;--text2:#868690;--text3:#3c3c44;
  --green:#22c55e;--green-soft:rgba(34,197,94,.12);
  --amber:#f59e0b;--amber-soft:rgba(245,158,11,.12);
  --font:'Poppins',sans-serif;--num:ui-monospace,Menlo,Consolas,monospace;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:var(--font);background:var(--bg);color:var(--text);min-height:100vh;-webkit-font-smoothing:antialiased}
.topbar{display:flex;align-items:center;gap:10px;padding:10px 14px;background:var(--panel);
  border-bottom:1px solid var(--border);position:sticky;top:0;z-index:50}
.back-btn{display:flex;align-items:center;justify-content:center;width:32px;height:32px;border-radius:9px;
  border:1px solid var(--border);background:transparent;color:var(--text2);text-decoration:none;font-size:14px}
.back-btn:hover{border-color:var(--red);color:var(--red)}
.titulo{font-size:15px;font-weight:800}.titulo span{color:var(--red)}
.main{max-width:720px;margin:0 auto;padding:14px 14px 50px}
.card{background:var(--panel);border:1px solid var(--border);border-radius:14px;padding:16px;margin-bottom:14px}
.card-title{font-size:10px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:var(--text2);
  margin-bottom:12px;display:flex;align-items:center;justify-content:space-between;gap:8px}
label{display:block;font-size:10px;font-weight:700;letter-spacing:.8px;text-transform:uppercase;
  color:var(--text2);margin:12px 0 5px}
input{width:100%;background:var(--panel2);border:1.5px solid var(--border);border-radius:10px;padding:11px 12px;
  font-family:var(--font);font-size:14px;font-weight:600;color:var(--text);outline:none}
input:focus{border-color:var(--red)}
input::placeholder{color:var(--text3);font-weight:500}
.btn{background:var(--red);color:#fff;border:0;border-radius:11px;padding:13px;font-family:var(--font);
  font-size:14px;font-weight:800;cursor:pointer;width:100%;margin-top:14px}
.btn:hover{filter:brightness(1.12)}
.linha{display:flex;align-items:flex-start;gap:10px;background:var(--panel2);border:1px solid var(--border);
  border-radius:11px;padding:11px 12px;margin-bottom:8px}
.linha-txt{flex:1;min-width:0}
.linha-txt b{display:block;font-size:13.5px;font-weight:700;line-height:1.35;margin-bottom:3px}
.linha-txt small{font-size:11px;color:var(--text2);line-height:1.5}
.pos{font-family:var(--num);font-size:11px;font-weight:700;color:var(--text3);flex:none;
  width:24px;text-align:right;padding-top:2px}
.acoes{display:flex;gap:5px;flex:none}
.mini{background:transparent;border:1px solid var(--border);color:var(--text2);border-radius:8px;
  width:28px;height:28px;font-size:12px;cursor:pointer;display:flex;align-items:center;justify-content:center}
.mini:hover{border-color:var(--red);color:var(--red)}
.data{font-family:var(--num);font-size:11px;font-weight:700;color:var(--amber);flex:none;padding-top:2px}
.tag{font-size:9px;font-weight:800;letter-spacing:.5px;text-transform:uppercase;padding:2px 7px;
  border-radius:20px;border:1px solid;white-space:nowrap}
.tag.aberta{color:var(--amber);border-color:rgba(245,158,11,.3);background:var(--amber-soft)}
.tag.fechada{color:var(--green);border-color:rgba(34,197,94,.3);background:var(--green-soft)}
.alerta{border-radius:11px;padding:11px 13px;font-size:12.5px;font-weight:600;margin-bottom:14px}
.alerta.ok{background:var(--green-soft);border:1px solid rgba(34,197,94,.3);color:var(--green)}
.alerta.ruim{background:var(--red-soft);border:1px solid rgba(252,0,37,.3);color:var(--red)}
.vazio{font-size:12.5px;color:var(--text2);text-align:center;padding:16px 0;line-height:1.5}
</style>
</head>
<body>

<div class="topbar">
  <a href="/games/games/quizdodia.php" class="back-btn" title="Ver o jogo"><i class="bi bi-arrow-left"></i></a>
  <span class="titulo">Quiz do <span>Dia</span> · fila</span>
</div>

<div class="main">

<?php if ($msg): ?><div class="alerta ok"><?= e($msg) ?></div><?php endif; ?>
<?php if ($erro): ?><div class="alerta ruim"><?= e($erro) ?></div><?php endif; ?>

<div class="card">
  <div class="card-title">Nova pergunta</div>
  <form method="post">
    <input type="hidden" name="acao" value="criar">
    <label>Pergunta</label>
    <input name="pergunta" maxlength="255" placeholder="Quem é o maior ídolo do Lakers?" required>
    <?php for ($i = 0; $i < 5; $i++): ?>
      <label>Opção <?= chr(65 + $i) ?></label>
      <input name="opcao[]" maxlength="120" required>
    <?php endfor; ?>
    <button class="btn" type="submit">Colocar no fim da fila</button>
  </form>
</div>

<div class="card">
  <div class="card-title">
    <span>Na fila</span>
    <span style="color:var(--text3)"><?= count($fila) ?> dia<?= count($fila) === 1 ? '' : 's' ?></span>
  </div>
  <?php if (!$fila): ?>
    <p class="vazio">A fila está vazia — amanhã o jogo abre sem pergunta.</p>
  <?php else: foreach ($fila as $n => $p): ?>
    <div class="linha">
      <span class="pos"><?= $n + 1 ?>º</span>
      <div class="linha-txt">
        <b><?= e($p['pergunta']) ?></b>
        <small><?= e(implode(' · ', ops($p))) ?></small>
      </div>
      <div class="acoes">
        <?php if ($n > 0): ?>
        <form method="post"><input type="hidden" name="acao" value="topo">
          <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
          <button class="mini" title="Mandar pro topo"><i class="bi bi-arrow-up"></i></button></form>
        <?php endif; ?>
        <form method="post" onsubmit="return confirm('Apagar essa pergunta da fila?')">
          <input type="hidden" name="acao" value="apagar">
          <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
          <button class="mini" title="Apagar"><i class="bi bi-trash"></i></button></form>
      </div>
    </div>
  <?php endforeach; endif; ?>
</div>

<div class="card">
  <div class="card-title">Já foram ao ar</div>
  <?php if (!$usadas): ?>
    <p class="vazio">Nenhuma pergunta saiu ainda.</p>
  <?php else: foreach ($usadas as $p):
    $d = DateTime::createFromFormat('Y-m-d', (string)$p['data_uso']);
    $venc = json_decode((string)$p['vencedoras'], true) ?: [];
    $o = ops($p);
    $nomes = array_map(fn($i) => $o[$i] ?? '?', $venc); ?>
    <div class="linha">
      <span class="data"><?= $d ? e($d->format('d/m')) : '??' ?></span>
      <div class="linha-txt">
        <b><?= e($p['pergunta']) ?></b>
        <small>
          <?php if (!$p['resolvido_em']): ?>
            em votação
          <?php elseif ($nomes): ?>
            venceu <b style="color:var(--green)"><?= e(implode(' e ', $nomes)) ?></b>
            · <?= (int)$p['total_votos'] ?> voto<?= (int)$p['total_votos'] === 1 ? '' : 's' ?>
          <?php else: ?>
            sem prêmio · <?= (int)$p['total_votos'] ?> voto<?= (int)$p['total_votos'] === 1 ? '' : 's' ?>
          <?php endif; ?>
        </small>
      </div>
      <span class="tag <?= $p['resolvido_em'] ? 'fechada' : 'aberta' ?>">
        <?= $p['resolvido_em'] ? 'apurada' : 'aberta' ?></span>
    </div>
  <?php endforeach; endif; ?>
</div>

</div>
</body>
</html>
