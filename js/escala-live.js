/**
 * A ESCALA SEM RECARREGAR A PÁGINA.
 *
 * Montar a escala de uma semana são muitas ações pequenas: põe quatro na
 * lista, escala oito, corrige duas. Cada uma era um POST com redirect e um
 * carregamento inteiro — no servidor de produção, meio segundo por clique,
 * a rolagem voltando pro topo toda vez. Era isso o "travado".
 *
 * ESTRATÉGIA: o formulário continua sendo um formulário normal. O envio vai
 * por fetch, o PHP responde como sempre (o redirect do POST-Redirect-Get é
 * seguido pelo próprio fetch, então a resposta já é a página pronta), e daí
 * eu troco só os pedaços que mudaram.
 *
 * Trocar HTML pronto em vez de devolver JSON e remontar na mão é o que
 * mantém isto barato: a página é a única dona de como a escala se desenha,
 * e mexer no PHP amanhã não exige mexer aqui. Sem servidor, o formulário
 * ainda envia do jeito antigo — nada aqui é obrigatório pra funcionar.
 */
(function () {
  var BLOCOS = ['#bloco-aviso', '#bloco-pool', '#bloco-escala'];

  /** Nome digitado no datalist -> id do usuário. Remontado a cada troca. */
  function mapaDeNomes() {
    var m = {};
    document.querySelectorAll('#gente-liga option').forEach(function (o) {
      // O primeiro vence: se dois GMs tiverem o mesmo nome, o servidor ainda
      // valida o id, e o admin vê quem entrou no aviso.
      if (!(o.value in m)) m[o.value] = o.dataset.id;
    });
    return m;
  }

  /** Traduz o nome digitado pro hidden que o servidor espera. */
  function casarNome(form) {
    var busca = form.querySelector('.busca');
    if (!busca) return true;
    var alvo = form.querySelector('input[name="usuario"]');
    alvo.value = mapaDeNomes()[busca.value.trim()] || '';
    return !!alvo.value;
  }

  function trocarBlocos(html) {
    var novo = new DOMParser().parseFromString(html, 'text/html');
    BLOCOS.forEach(function (sel) {
      var de = novo.querySelector(sel), pra = document.querySelector(sel);
      if (de && pra) pra.replaceWith(de);
    });
    // O datalist vive fora dos blocos trocados e muda quando alguém entra ou
    // sai da lista — sem isto a busca continuaria oferecendo quem já entrou.
    var dlNovo = novo.querySelector('#gente-liga'), dl = document.querySelector('#gente-liga');
    if (dlNovo && dl) dl.replaceWith(dlNovo);
  }

  var ocupado = false;

  async function enviar(form, submitter) {
    if (ocupado) return;
    var dados = new FormData(form);
    // O FormData não inclui o botão clicado, e é o name dele que diz ao PHP
    // qual ação é. Sem isto todo envio cairia no primeiro if do arquivo.
    if (submitter && submitter.name) dados.append(submitter.name, submitter.value || '1');

    ocupado = true;
    document.body.classList.add('esperando');
    try {
      var r = await fetch(location.href, {
        method: 'POST', body: dados, credentials: 'same-origin',
        headers: { 'X-Requested-With': 'fetch' }
      });
      if (!r.ok) throw new Error('HTTP ' + r.status);
      trocarBlocos(await r.text());
    } catch (e) {
      // Cai pro caminho antigo em vez de engolir o clique. Uma ação que
      // silenciosamente não acontece é pior que uma página recarregando.
      form.submit();
    } finally {
      ocupado = false;
      document.body.classList.remove('esperando');
    }
  }

  /* ── Envio ──────────────────────────────────────────────────────────
   *
   * Delegado no document porque os formulários são substituídos a cada
   * ação: um listener preso ao <form> morreria junto com ele.
   *
   * NÃO é capture. O data-confirmar do popups.js escuta em capture e
   * chama stopImmediatePropagation até a pessoa responder — se este
   * rodasse antes, a ação sairia sem confirmação nenhuma. Quando ela
   * confirma, o popups.js reenvia e aí o evento chega aqui.
   */
  document.addEventListener('submit', function (ev) {
    var form = ev.target;
    if (!form.matches || !form.matches('#bloco-pool form, #bloco-escala form')) return;

    if (form.classList.contains('add-disp') && !casarNome(form)) {
      ev.preventDefault();
      var b = form.querySelector('.busca');
      window.avisarSite(
        b.value.trim()
          ? 'Não achei "' + b.value.trim() + '" na liga. Escolha um nome da lista.'
          : 'Digite o nome de quem entra nessa função.',
        'aviso'
      );
      b.focus();
      return;
    }

    ev.preventDefault();
    enviar(form, ev.submitter);
  });

  /* ── Casar o nome enquanto digita ─────────────────────────────────── */
  document.addEventListener('input', function (ev) {
    if (ev.target.classList && ev.target.classList.contains('busca')) casarNome(ev.target.form);
  });

  /* ── Trocar a fase de quem já está na lista ───────────────────────── */
  document.addEventListener('change', function (ev) {
    var sel = ev.target;
    if (!sel.closest || !sel.closest('.f-fase')) return;
    // Envia na hora: um seletor com botão "salvar" do lado seria mais um
    // clique numa tela onde o excesso de cliques é justamente o problema.
    enviar(sel.form, null);
  });
})();
