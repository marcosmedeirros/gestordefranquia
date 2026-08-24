/**
 * OS POPUPS DO SITE, no lugar dos do navegador.
 *
 * O alert() do navegador tem três problemas que não são de gosto:
 *
 * 1. Ele é do CHROME, não do site — vem com "localhost:8080 diz:" em cima,
 *    fonte do sistema, botão cinza. No meio de uma página inteira desenhada,
 *    ele parece defeito.
 * 2. Ele TRAVA a página inteira. Nada anima, nada carrega, nada responde até
 *    alguém clicar em OK.
 * 3. Ele ganha a caixinha "não deixar este site criar mais diálogos" e, uma
 *    vez marcada, o próximo alert simplesmente NÃO APARECE. A mensagem some
 *    sem aviso, e quem escreveu o código acha que ela saiu.
 *
 * Este arquivo troca window.alert por um aviso do site. É um substituto e
 * não uma função nova de propósito: são mais de 400 chamadas espalhadas por
 * dezenas de arquivos, e trocar uma por uma seria dezenas de oportunidades
 * de errar. Aqui, todas mudam de uma vez e nenhuma linha de chamada muda.
 *
 * O que NÃO dá pra trocar assim: confirm() e prompt(). Os dois DEVOLVEM
 * valor na hora ("if (!confirm(...)) return;"), e caixa desenhada em HTML só
 * responde depois — não existe como esperar sem travar a aba. Esses precisam
 * de mudança em cada chamada, uma a uma. Para eles ficam aqui o
 * confirmarSite() e o perguntarSite(), que devolvem Promise.
 */
(function () {
  'use strict';

  if (window.__popupsDoSite) return;
  window.__popupsDoSite = true;

  var nativoAlert = window.alert.bind(window);

  /* ── O estilo ─────────────────────────────────────────────────────────
     Injetado por JS junto com o resto: assim uma página nova só precisa
     carregar este arquivo, sem lembrar de um CSS separado que ninguém vê
     faltando até o dia em que a caixa aparece sem formatação. */
  function garantirEstilo() {
    if (document.getElementById('popup-site-css')) return;
    var st = document.createElement('style');
    st.id = 'popup-site-css';
    st.textContent = [
      '.psite-fundo{position:fixed;inset:0;z-index:20000;display:flex;align-items:center;',
      '  justify-content:center;padding:20px;background:rgba(0,0,0,.62);backdrop-filter:blur(3px);',
      '  opacity:0;transition:opacity .14s ease}',
      '.psite-fundo.on{opacity:1}',
      '.psite-cx{width:100%;max-width:420px;background:var(--panel,#101013);color:var(--text,#f0f0f3);',
      '  border:1px solid var(--border-md,rgba(255,255,255,.10));border-radius:14px;',
      '  box-shadow:0 24px 60px rgba(0,0,0,.55);overflow:hidden;',
      '  font-family:var(--font,"Montserrat",system-ui,sans-serif);',
      '  transform:translateY(8px) scale(.985);transition:transform .16s cubic-bezier(.2,.8,.2,1)}',
      '.psite-fundo.on .psite-cx{transform:none}',
      '.psite-topo{display:flex;align-items:center;gap:11px;padding:16px 18px 0}',
      '.psite-ico{width:34px;height:34px;border-radius:10px;display:flex;align-items:center;',
      '  justify-content:center;font-size:17px;flex:none}',
      '.psite-tit{font-size:15px;font-weight:800;line-height:1.2}',
      '.psite-corpo{padding:12px 18px 4px;font-size:13.5px;line-height:1.55;',
      '  color:var(--text-2,#868690);white-space:pre-wrap;word-break:break-word;',
      '  max-height:52vh;overflow-y:auto}',
      '.psite-campo{width:100%;margin-top:12px;background:var(--panel-2,#16161a);',
      '  border:1px solid var(--border-md,rgba(255,255,255,.10));color:var(--text,#f0f0f3);',
      '  font-family:inherit;font-size:13.5px;border-radius:9px;padding:9px 12px}',
      '.psite-campo:focus{outline:none;border-color:var(--red,#fc0025)}',
      '.psite-pe{display:flex;gap:8px;justify-content:flex-end;padding:14px 18px 16px;flex-wrap:wrap}',
      '.psite-bt{border:0;font-family:inherit;font-size:13px;font-weight:700;border-radius:9px;',
      '  padding:9px 18px;cursor:pointer;background:var(--red,#fc0025);color:#fff}',
      '.psite-bt:hover{filter:brightness(1.1)}',
      '.psite-bt.sec{background:var(--panel-3,#1c1c21);color:var(--text-2,#868690);',
      '  border:1px solid var(--border-md,rgba(255,255,255,.10))}',
      '@media (max-width:520px){.psite-pe{flex-direction:column-reverse}.psite-bt{width:100%}}',
      '@media (prefers-reduced-motion:reduce){.psite-fundo,.psite-cx{transition:none}}'
    ].join('\n');
    (document.head || document.documentElement).appendChild(st);
  }

  var TIPOS = {
    aviso: { ico: 'ℹ️', cor: '#3b82f6', tit: 'Aviso' },
    erro:  { ico: '⚠️', cor: '#fc0025', tit: 'Ops' },
    ok:    { ico: '✅', cor: '#22c55e', tit: 'Pronto' },
    duvida:{ ico: '❓', cor: '#f59e0b', tit: 'Confirmar' }
  };

  /**
   * Adivinha o tom pela mensagem.
   *
   * Não é enfeite: "Erro ao salvar" e "Time criado!" merecem cores
   * diferentes, e as 400 chamadas que já existem não vão passar a informar
   * o tom. Adivinhar erra às vezes; não adivinhar erra sempre.
   */
  function tomDaMensagem(txt) {
    var t = (txt || '').toLowerCase();
    if (/erro|falha|inválid|invalid|não foi|nao foi|não deu|nao deu|impossív|negad/.test(t)) return 'erro';
    if (/sucesso|criad|salv|atualizad|enviad|conclu|pronto|removid|excluíd|excluid/.test(t)) return 'ok';
    return 'aviso';
  }

  /** Monta a caixa. `campos` são os botões e o input opcional. */
  function abrir(opcoes) {
    garantirEstilo();

    var tipo = TIPOS[opcoes.tipo] || TIPOS.aviso;
    var fundo = document.createElement('div');
    fundo.className = 'psite-fundo';
    fundo.setAttribute('role', 'dialog');
    fundo.setAttribute('aria-modal', 'true');

    var cx = document.createElement('div');
    cx.className = 'psite-cx';

    var topo = document.createElement('div');
    topo.className = 'psite-topo';
    topo.innerHTML = '<div class="psite-ico" style="background:color-mix(in srgb,' + tipo.cor +
      ' 14%,transparent);color:' + tipo.cor + '">' + tipo.ico + '</div>' +
      '<div class="psite-tit"></div>';
    topo.querySelector('.psite-tit').textContent = opcoes.titulo || tipo.tit;

    var corpo = document.createElement('div');
    corpo.className = 'psite-corpo';
    // textContent e não innerHTML: a mensagem pode conter nome digitado por
    // usuário, e um <img onerror> ali dentro seria script rodando na página.
    corpo.textContent = opcoes.texto == null ? '' : String(opcoes.texto);

    var campo = null;
    if (opcoes.comCampo) {
      campo = document.createElement('input');
      campo.type = 'text';
      campo.className = 'psite-campo';
      campo.value = opcoes.valorInicial || '';
      corpo.appendChild(campo);
    }

    var pe = document.createElement('div');
    pe.className = 'psite-pe';

    cx.appendChild(topo);
    cx.appendChild(corpo);
    cx.appendChild(pe);
    fundo.appendChild(cx);
    document.body.appendChild(fundo);
    requestAnimationFrame(function () { fundo.classList.add('on'); });

    var travaFoco = document.activeElement;

    function fechar(valor) {
      if (!fundo.parentNode) return;
      fundo.classList.remove('on');
      document.removeEventListener('keydown', naTecla, true);
      // Espera a transição pra não sumir com um piscão.
      setTimeout(function () { if (fundo.parentNode) fundo.remove(); }, 150);
      // Devolve o foco pra onde estava: sem isso, quem usa teclado é jogado
      // pro começo da página toda vez que uma caixa fecha.
      if (travaFoco && travaFoco.focus) { try { travaFoco.focus(); } catch (e) {} }
      if (opcoes.aoFechar) opcoes.aoFechar(valor);
    }

    function naTecla(ev) {
      if (ev.key === 'Escape') { ev.preventDefault(); fechar(opcoes.valorEscape); }
      else if (ev.key === 'Enter' && (!campo || document.activeElement === campo)) {
        ev.preventDefault();
        fechar(campo ? campo.value : true);
      }
    }
    document.addEventListener('keydown', naTecla, true);

    (opcoes.botoes || []).forEach(function (b) {
      var el = document.createElement('button');
      el.type = 'button';
      el.className = 'psite-bt' + (b.secundario ? ' sec' : '');
      el.textContent = b.rotulo;
      el.onclick = function () { fechar(typeof b.valor === 'function' ? b.valor(campo) : b.valor); };
      pe.appendChild(el);
    });

    // Clicar no fundo escuro cancela, como todo modal do site faz.
    fundo.addEventListener('mousedown', function (ev) {
      if (ev.target === fundo) fechar(opcoes.valorEscape);
    });

    var focar = campo || pe.lastElementChild;
    if (focar) setTimeout(function () { focar.focus(); if (campo) campo.select(); }, 40);

    return fechar;
  }

  /* ── alert() ─────────────────────────────────────────────────────────── */
  window.alert = function (mensagem) {
    // Antes do <body> existir não há onde pendurar a caixa. Cai no nativo, que
    // é feio mas aparece — melhor que a mensagem sumir.
    if (!document.body) { nativoAlert(mensagem); return; }
    abrir({
      tipo: tomDaMensagem(mensagem),
      texto: mensagem,
      valorEscape: undefined,
      botoes: [{ rotulo: 'OK', valor: true }]
    });
  };

  /* ── Os que precisam de Promise ──────────────────────────────────────── */

  /**
   * Confirmação do site. Devolve Promise<boolean>.
   *
   * Substituto do confirm(), que não dá pra trocar por cima: ele devolve o
   * valor NA HORA, e caixa em HTML só responde depois.
   *
   *   if (!await confirmarSite('Apagar?')) return;
   */
  window.confirmarSite = function (texto, opcoes) {
    opcoes = opcoes || {};
    return new Promise(function (resolve) {
      abrir({
        tipo: opcoes.perigo ? 'erro' : 'duvida',
        titulo: opcoes.titulo,
        texto: texto,
        valorEscape: false,
        aoFechar: function (v) { resolve(v === true); },
        botoes: [
          { rotulo: opcoes.cancelar || 'Cancelar', valor: false, secundario: true },
          { rotulo: opcoes.confirmar || 'Confirmar', valor: true }
        ]
      });
    });
  };

  /** Substituto do prompt(). Devolve Promise<string|null>. */
  window.perguntarSite = function (texto, valorInicial, opcoes) {
    opcoes = opcoes || {};
    return new Promise(function (resolve) {
      abrir({
        tipo: 'duvida',
        titulo: opcoes.titulo,
        texto: texto,
        comCampo: true,
        valorInicial: valorInicial,
        valorEscape: null,
        aoFechar: function (v) { resolve(v === undefined ? null : v); },
        botoes: [
          { rotulo: 'Cancelar', valor: null, secundario: true },
          { rotulo: 'OK', valor: function (c) { return c ? c.value : ''; } }
        ]
      });
    });
  };

  /** Aviso com tom escolhido na mão, quando adivinhar não serve. */
  window.avisarSite = function (texto, tipo, titulo) {
    if (!document.body) { nativoAlert(texto); return; }
    abrir({
      tipo: tipo || 'aviso',
      titulo: titulo,
      texto: texto,
      valorEscape: undefined,
      botoes: [{ rotulo: 'OK', valor: true }]
    });
  };
})();
