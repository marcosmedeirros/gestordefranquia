<?php
/**
 * O POPUP DE CONFIRMAR O WHATSAPP.
 *
 * Entra pelo includes/sidebar.php, que está em toda tela de dentro: quem
 * acabou de assumir um time pode cair direto no Meu Elenco ou nas Trades pelo
 * link que mandaram no grupo, e não só no Dashboard.
 *
 * Quem decide se aparece é telefoneBotPendencia() — @see backend/telefone_bot.php.
 * Aqui é só a caixa, no mesmo desenho dos pop-ups do site (js/popups.js), pra
 * não parecer coisa de outro lugar.
 */

require_once __DIR__ . '/../backend/telefone_bot.php';

$tbUser = function_exists('getUserSession') ? getUserSession() : null;
$tbPdo  = isset($pdo) && $pdo instanceof PDO ? $pdo : (function_exists('db') ? db() : null);
$tbPend = ($tbPdo && $tbUser) ? telefoneBotPendencia($tbPdo, $tbUser) : null;

if ($tbPend):
    $tbDisplay = trim((string)$tbPend['phone']) !== ''
        ? (function_exists('formatBrazilianPhone') ? formatBrazilianPhone($tbPend['phone']) : $tbPend['phone'])
        : '';
?>
<div class="tbot-fundo" id="tbotFundo" role="dialog" aria-modal="true" aria-labelledby="tbotTit">
  <div class="tbot-cx">
    <div class="tbot-topo">
      <div class="tbot-ico">💬</div>
      <div class="tbot-tit" id="tbotTit"><?= htmlspecialchars($tbPend['titulo']) ?></div>
    </div>
    <div class="tbot-corpo">
      <p class="tbot-txt"><?= htmlspecialchars($tbPend['texto']) ?></p>
      <label class="tbot-lbl" for="tbotPhone">Teu WhatsApp (com DDD)</label>
      <input class="tbot-campo" id="tbotPhone" type="tel" inputmode="tel" autocomplete="tel"
             placeholder="11 99999-9999" value="<?= htmlspecialchars($tbDisplay) ?>">
      <div class="tbot-erro" id="tbotErro" hidden></div>
      <p class="tbot-nota">
        <i class="bi bi-shield-lock"></i>
        Só a administração vê. É usado pra te marcar nos grupos da liga e mandar os avisos do bot.
      </p>
    </div>
    <div class="tbot-pe">
      <button type="button" class="tbot-bt sec" id="tbotDepois">Agora não</button>
      <?php /* "É esse mesmo" só quando o número EXISTE E FUNCIONA — ou seja,
               no caso de quem acabou de assumir um time e só precisa
               confirmar. Oferecer o botão com o número quebrado seria
               convidar a pessoa a apertar algo que o servidor vai recusar. */
      if ($tbDisplay !== '' && $tbPend['motivo'] === 'novo'): ?>
      <button type="button" class="tbot-bt sec" id="tbotConfirmar">É esse mesmo</button>
      <?php endif; ?>
      <button type="button" class="tbot-bt" id="tbotSalvar">Salvar</button>
    </div>
  </div>
</div>
<style>
  .tbot-fundo{position:fixed;inset:0;z-index:20050;display:flex;align-items:center;justify-content:center;
    padding:20px;background:rgba(0,0,0,.62);backdrop-filter:blur(3px);opacity:0;transition:opacity .14s ease}
  .tbot-fundo.on{opacity:1}
  .tbot-cx{width:100%;max-width:430px;background:var(--panel,#101013);color:var(--text,#f0f0f3);
    border:1px solid var(--border-md,rgba(255,255,255,.10));border-radius:14px;
    box-shadow:0 24px 60px rgba(0,0,0,.55);overflow:hidden;
    font-family:var(--font,"Montserrat",system-ui,sans-serif);
    transform:translateY(8px) scale(.985);transition:transform .16s cubic-bezier(.2,.8,.2,1)}
  .tbot-fundo.on .tbot-cx{transform:none}
  .tbot-topo{display:flex;align-items:center;gap:11px;padding:16px 18px 0}
  .tbot-ico{width:34px;height:34px;border-radius:10px;display:flex;align-items:center;justify-content:center;
    font-size:17px;flex:none;background:rgba(34,197,94,.14)}
  .tbot-tit{font-size:15px;font-weight:800;line-height:1.25}
  .tbot-corpo{padding:12px 18px 4px}
  .tbot-txt{font-size:13.5px;line-height:1.55;color:var(--text-2,#868690);margin:0 0 14px}
  .tbot-lbl{display:block;font-size:11px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;
    color:var(--text-3,#5e5e68);margin-bottom:6px}
  .tbot-campo{width:100%;background:var(--panel-2,#16161a);border:1px solid var(--border-md,rgba(255,255,255,.10));
    color:var(--text,#f0f0f3);font-family:inherit;font-size:15px;border-radius:9px;padding:11px 13px}
  .tbot-campo:focus{outline:none;border-color:var(--red,#fc0025)}
  .tbot-erro{margin-top:8px;font-size:12.5px;line-height:1.45;color:#f87171}
  .tbot-erro button{background:none;border:0;color:#f87171;font:inherit;font-weight:700;
    text-decoration:underline;cursor:pointer;padding:0}
  .tbot-nota{display:flex;gap:7px;align-items:flex-start;margin:12px 0 0;
    font-size:11.5px;line-height:1.5;color:var(--text-3,#5e5e68)}
  .tbot-nota i{margin-top:2px}
  .tbot-pe{display:flex;gap:8px;justify-content:flex-end;padding:16px 18px;flex-wrap:wrap}
  .tbot-bt{border:0;font-family:inherit;font-size:13px;font-weight:700;border-radius:9px;
    padding:10px 18px;cursor:pointer;background:var(--red,#fc0025);color:#fff}
  .tbot-bt:hover{filter:brightness(1.1)}
  .tbot-bt:disabled{opacity:.55;cursor:default}
  .tbot-bt.sec{background:var(--panel-3,#1c1c21);color:var(--text-2,#868690);
    border:1px solid var(--border-md,rgba(255,255,255,.10))}
  @media (max-width:520px){.tbot-pe{flex-direction:column-reverse}.tbot-bt{width:100%}}
  @media (prefers-reduced-motion:reduce){.tbot-fundo,.tbot-cx{transition:none}}
</style>
<script>
(function () {
  var fundo = document.getElementById('tbotFundo');
  if (!fundo) return;

  /* Uma vez por sessão de navegação. O popup volta no próximo acesso enquanto
     o número não prestar — mas reaparecer a cada clique no menu faria a pessoa
     fechar no reflexo, sem ler. */
  try {
    if (sessionStorage.getItem('tbot-visto') === '1') { fundo.remove(); return; }
    sessionStorage.setItem('tbot-visto', '1');
  } catch (e) {}

  var campo = document.getElementById('tbotPhone');
  var erro  = document.getElementById('tbotErro');
  var bts   = fundo.querySelectorAll('.tbot-bt');

  requestAnimationFrame(function () {
    fundo.classList.add('on');
    setTimeout(function () { try { campo.focus(); } catch (e) {} }, 180);
  });

  function fechar() {
    fundo.classList.remove('on');
    setTimeout(function () { fundo.remove(); }, 180);
  }
  function travar(v) { bts.forEach(function (b) { b.disabled = v; }); }
  function mostrarErro(msg, sugestao) {
    erro.hidden = false;
    erro.textContent = msg || 'Não deu pra salvar.';
    if (sugestao) {
      erro.append(' ');
      var b = document.createElement('button');
      b.type = 'button';
      b.textContent = 'Usar ' + sugestao;
      b.onclick = function () { campo.value = sugestao; erro.hidden = true; };
      erro.append(b);
    }
  }

  async function enviar(acao) {
    travar(true);
    erro.hidden = true;
    try {
      var r = await fetch('/api/telefone-bot.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ acao: acao, phone: campo.value })
      });
      var d = await r.json().catch(function () { return {}; });
      if (!r.ok || !d.success) { mostrarErro(d.error, d.sugestao); travar(false); return; }
      fechar();
      if (acao === 'salvar' && typeof window.alert === 'function') {
        setTimeout(function () { alert('WhatsApp salvo! Agora o bot consegue te avisar.'); }, 200);
      }
    } catch (e) {
      mostrarErro('Sem conexão. Tenta de novo.');
      travar(false);
    }
  }

  document.getElementById('tbotSalvar').onclick = function () { enviar('salvar'); };
  document.getElementById('tbotDepois').onclick = function () { enviar('depois'); fechar(); };
  var bConf = document.getElementById('tbotConfirmar');
  if (bConf) bConf.onclick = function () { enviar('confirmar'); };
  campo.addEventListener('keydown', function (ev) { if (ev.key === 'Enter') enviar('salvar'); });
})();
</script>
<?php endif; ?>
