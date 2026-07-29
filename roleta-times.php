<?php
/**
 * Roleta dos 32 Times — sorteio da ordem inversa de escolha.
 *
 * Página PÚBLICA: qualquer pessoa acompanha o sorteio e o histórico de saída,
 * sem login. Girar e reiniciar continuam restritos a admin (validado de novo
 * no servidor, em api/roleta-times.php — o que se esconde aqui é só a UI).
 */
session_start();
require_once __DIR__ . '/backend/config.php';
require_once __DIR__ . '/backend/db.php';
require_once __DIR__ . '/backend/auth.php';

$pdo = db();

$rtUserId  = $_SESSION['user_id'] ?? null;
$rtIsAdmin = $rtUserId ? hasAdminAccess($pdo, (int)$rtUserId) : false;
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
<script>document.documentElement.dataset.theme = localStorage.getItem('fba-theme') || 'dark';</script>
<meta name="theme-color" content="#fc0025">
<title>Roleta dos 32 Times · FBA</title>
<link rel="icon" type="image/png" href="/games/fbagames.png">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800;900&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<style>
:root{
  --red:#fc0025;--red-2:color-mix(in srgb, var(--red) 85%, white);
  --red-soft:color-mix(in srgb, var(--red) 10%, transparent);
  --red-glow:color-mix(in srgb, var(--red) 20%, transparent);
  --border-red:color-mix(in srgb, var(--red) 22%, transparent);
  --bg:#07070a;--panel:#101013;--panel-2:#16161a;--panel-3:#1c1c21;
  --border:rgba(255,255,255,.06);--border-md:rgba(255,255,255,.10);
  --text:#f0f0f3;--text-2:#868690;--text-3:#7d7d85;
  --green:#22c55e;--amber:#f59e0b;
  --font:'Montserrat',sans-serif;--radius:14px;--radius-sm:10px;--radius-xs:6px;
  --ease:cubic-bezier(.2,.8,.2,1);--t:200ms;
}
:root[data-theme="light"]{
  --bg:#f6f7fb;--panel:#fff;--panel-2:#f2f4f8;--panel-3:#e9edf4;
  --border:#e3e6ee;--border-md:#d7dbe6;--text:#111217;--text-2:#5b6270;--text-3:#657080;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:var(--font);background:var(--bg);color:var(--text);-webkit-font-smoothing:antialiased}
.wrap{padding:20px 32px 60px}

/* Cabeçalho no padrão do site */
.page-hero{display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:14px;margin-bottom:18px}
.page-hero-eyebrow{font-size:11px;font-weight:600;letter-spacing:1.4px;text-transform:uppercase;color:var(--red);margin-bottom:4px}
.page-hero-title{font-size:26px;font-weight:800;color:var(--text);line-height:1.1}
.page-hero-sub{font-size:13px;color:var(--text-2);margin-top:4px;max-width:640px}
.page-hero-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap}

.rt-chip{display:inline-flex;align-items:center;gap:6px;background:var(--panel-2);border:1px solid var(--border);
  border-radius:999px;padding:5px 12px;font-size:12px;font-weight:600;color:var(--text-2)}
.rt-chip.ok{background:color-mix(in srgb,var(--green) 12%,transparent);border-color:color-mix(in srgb,var(--green) 32%,transparent);color:var(--green)}
.rt-chip.next{background:var(--red-soft);border-color:var(--border-red);color:var(--red)}
#rtResumo{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:18px}

.grid{display:grid;grid-template-columns:1.05fr .95fr;gap:20px;align-items:start}
@media(max-width:992px){.grid{grid-template-columns:1fr}.wrap{padding:16px 16px 60px}}

.card{background:var(--panel);border:1px solid var(--border);border-radius:var(--radius);overflow:hidden;margin-bottom:16px}
.card-head{padding:15px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:9px}
.card-head i{color:var(--red);font-size:15px}
.card-head span{font-size:14px;font-weight:600}
.card-body{padding:20px}

/* Roleta */
.roda-area{display:flex;flex-direction:column;align-items:center;gap:20px}
.roda-palco{position:relative;width:min(360px,82vw);height:min(360px,82vw)}
.roda-ponteiro{position:absolute;top:-6px;left:50%;transform:translateX(-50%);width:0;height:0;z-index:5;
  border-left:14px solid transparent;border-right:14px solid transparent;border-top:22px solid var(--red);
  filter:drop-shadow(0 2px 4px rgba(0,0,0,.45))}
.roda{width:100%;height:100%;border-radius:50%;position:relative;overflow:hidden;
  border:6px solid var(--panel-3);box-shadow:0 0 0 2px var(--border-md),0 20px 50px -20px rgba(0,0,0,.6);
  transition:transform 4.2s cubic-bezier(.12,.72,.15,1)}
.rt-fatias{position:absolute;inset:0;border-radius:50%}
.rt-rotulo{position:absolute;top:50%;left:50%;width:50%;height:2px;transform-origin:0 50%;
  display:flex;align-items:center;justify-content:flex-end;padding-right:14px}
.rt-rotulo span{font-size:12px;font-weight:800;color:#fff;white-space:nowrap;text-shadow:0 1px 3px rgba(0,0,0,.55)}
.rt-roda-vazia{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;
  text-align:center;color:var(--text-3);font-size:13px;padding:24px}
.roda-hub{position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);width:52px;height:52px;border-radius:50%;
  background:var(--panel);border:3px solid var(--red);z-index:4;display:flex;align-items:center;justify-content:center;
  color:var(--red);font-size:18px}

.btn-girar{background:var(--red);border:none;color:#fff;font-family:var(--font);font-weight:700;font-size:14px;
  border-radius:var(--radius-sm);padding:12px 28px;cursor:pointer;transition:background var(--t)}
.btn-girar:hover:not(:disabled){background:var(--red-2)}
.btn-girar:disabled{background:var(--panel-3);color:var(--text-3);cursor:not-allowed}
.btn-ghost{background:transparent;border:1px solid var(--border-md);color:var(--text-2);font-family:var(--font);
  font-weight:600;font-size:12px;border-radius:var(--radius-xs);padding:7px 13px;cursor:pointer}
.btn-ghost:hover{border-color:var(--border-red);color:var(--red)}

/* Anúncio do time que acabou de sair */
#rtAnuncio{min-height:0}
#rtAnuncio.mostrar .rt-anuncio-box{animation:anuncioEntra .55s var(--ease)}
@keyframes anuncioEntra{0%{opacity:0;transform:scale(.86)}60%{transform:scale(1.04)}100%{opacity:1;transform:scale(1)}}
.rt-anuncio-box{background:linear-gradient(135deg,var(--red-soft),transparent);border:1px solid var(--border-red);
  border-radius:var(--radius);padding:16px 20px;text-align:center;margin-bottom:16px}
.rt-anuncio-pick{font-size:11px;font-weight:800;letter-spacing:1.2px;text-transform:uppercase;color:var(--red)}
.rt-anuncio-time{font-size:26px;font-weight:800;margin-top:2px}
.rt-anuncio-sub{font-size:12px;color:var(--text-3);margin-top:2px}

/* Botão de copiar (histórico e anúncio) — texto pronto pra colar no WhatsApp */
.rt-copy-btn{background:var(--panel-2);border:1px solid var(--border-md);color:var(--text-2);
  border-radius:var(--radius-xs);width:28px;height:28px;flex-shrink:0;cursor:pointer;
  display:inline-flex;align-items:center;justify-content:center;font-size:13px;transition:all var(--t)}
.rt-copy-btn:hover{border-color:var(--border-red);color:var(--red);background:var(--red-soft)}
.rt-copy-btn-grande{width:auto;height:auto;padding:8px 16px;font-size:12.5px;font-weight:600;
  font-family:var(--font);margin-top:12px}

/* Urna */
.rt-urna-item{display:flex;align-items:center;gap:10px;padding:8px 10px;border-radius:var(--radius-sm);
  background:var(--panel-2);border:1px solid var(--border);margin-bottom:6px}
.rt-urna-item img{width:30px;height:30px;border-radius:8px;object-fit:contain;background:var(--panel-3);flex-shrink:0}
.rt-urna-txt{min-width:0}
.rt-urna-time{font-size:13px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.rt-urna-gm{font-size:11px;color:var(--text-3);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}

/* Histórico de saída */
.rt-hist-linha{display:flex;align-items:center;gap:10px;padding:10px 12px;border-radius:var(--radius-sm);
  background:var(--panel-2);border:1px solid var(--border);margin-bottom:7px;flex-wrap:wrap}
.rt-hist-saida{font-size:10px;font-weight:700;color:var(--text-3);text-transform:uppercase;letter-spacing:.5px;
  min-width:62px;flex-shrink:0}
.rt-hist-pick{font-family:var(--font);font-size:12px;font-weight:800;color:var(--red);background:var(--red-soft);
  border:1px solid var(--border-red);border-radius:999px;padding:3px 10px;flex-shrink:0}
.rt-hist-logo{width:28px;height:28px;border-radius:7px;object-fit:contain;background:var(--panel-3);flex-shrink:0}
.rt-hist-time{flex:1;min-width:120px;font-size:13px;font-weight:600;line-height:1.25}
.rt-hist-time small{display:block;font-size:11px;font-weight:400;color:var(--text-3)}
.rt-hist-hora{font-size:11px;color:var(--text-3);flex-shrink:0}

.rt-vazio{text-align:center;padding:26px 16px;color:var(--text-3)}
.rt-vazio i{font-size:26px;display:block;margin-bottom:8px}
.rt-vazio p{font-size:12px}

.scroll{max-height:440px;overflow-y:auto}
input:focus-visible,button:focus-visible,a:focus-visible{outline:2px solid var(--red);outline-offset:2px}
@media (prefers-reduced-motion: reduce){
  *,*::before,*::after{animation-duration:.01ms!important;animation-iteration-count:1!important;
    transition-duration:.01ms!important;scroll-behavior:auto!important}
}
</style>
</head>
<body>
<div class="wrap">

  <div class="page-hero">
    <div>
      <div class="page-hero-eyebrow">FBA · Sorteio</div>
      <h1 class="page-hero-title"><i class="bi bi-record-circle" style="color:var(--red);margin-right:8px"></i>Roleta dos 32 Times</h1>
      <p class="page-hero-sub">Cada giro tira um time da urna. O primeiro a sair fica com a <b>escolha 32</b>, o seguinte com a 31, e assim por diante até a escolha 1. Sorteio aleatório, feito no servidor.</p>
    </div>
    <div class="page-hero-actions">
      <?php if ($rtIsAdmin): ?>
        <button class="btn-ghost" id="rtReiniciar"><i class="bi bi-arrow-counterclockwise"></i> Reiniciar</button>
      <?php endif; ?>
    </div>
  </div>

  <div id="rtResumo"></div>
  <div id="rtAnuncio"></div>

  <div class="grid">
    <div>
      <div class="card">
        <div class="card-head"><i class="bi bi-record-circle"></i><span>Roleta</span></div>
        <div class="card-body">
          <div class="roda-area">
            <div class="roda-palco">
              <div class="roda-ponteiro"></div>
              <div class="roda" id="rtRoda"></div>
              <div class="roda-hub"><i class="bi bi-dice-5-fill"></i></div>
            </div>
            <?php if ($rtIsAdmin): ?>
              <button class="btn-girar" id="rtGirar" disabled>Carregando...</button>
            <?php else: ?>
              <p style="font-size:12px;color:var(--text-3);text-align:center">
                <i class="bi bi-eye"></i> Acompanhe o sorteio — só a organização gira a roleta.
              </p>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card-head"><i class="bi bi-inbox"></i><span>Ainda na urna</span></div>
        <div class="card-body scroll"><div id="rtUrna"></div></div>
      </div>
    </div>

    <div class="card">
      <div class="card-head"><i class="bi bi-list-ol"></i><span>Ordem de saída</span></div>
      <div class="card-body scroll"><div id="rtHistorico"></div></div>
    </div>
  </div>
</div>

<script src="/js/roleta-times.js?v=<?= @filemtime(__DIR__ . '/js/roleta-times.js') ?: 1 ?>"></script>
</body>
</html>
