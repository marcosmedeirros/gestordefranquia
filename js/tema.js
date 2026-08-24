/**
 * O botão "Modo claro / Modo escuro" do menu lateral.
 *
 * O botão nasce no includes/sidebar.php, mas quem o fazia funcionar era um
 * bloco copiado dentro de cada página — cap.php, dashboard.php, admin.php e
 * mais uma dúzia, cada uma com a sua cópia. Toda tela nova nascia com o
 * botão morto, e foi o que aconteceu no calendário e na escala.
 *
 * Este arquivo é a versão única. As telas antigas seguem com a cópia delas:
 * carregar os dois no mesmo lugar registraria DOIS cliques no botão, o tema
 * trocaria e destrocaria, e o botão pareceria quebrado. Por isso ele entra
 * só onde ainda não existe handler, e a trava abaixo protege o caso de o
 * arquivo acabar incluído duas vezes.
 */
(function () {
  if (window.__temaJsLigado) return;
  window.__temaJsLigado = true;

  var CHAVE = 'fba-theme';

  function aplicar(tema) {
    var botao = document.getElementById('themeToggle');
    var claro = tema === 'light';

    // O atributo fica sempre escrito, e não removido no escuro: o script
    // inline do <head> já escreve 'dark' pra evitar o flash branco, e
    // apagá-lo aqui faria o valor divergir do que ele deixou.
    document.documentElement.setAttribute('data-theme', claro ? 'light' : 'dark');

    // O rótulo diz o estado ATUAL, não o que o clique faz — é como as
    // outras telas do app já se comportam, e trocar isso só aqui deixaria
    // o mesmo botão com dois significados dependendo da página.
    if (botao) {
      botao.innerHTML = claro
        ? '<i class="bi bi-sun"></i><span>Modo claro</span>'
        : '<i class="bi bi-moon"></i><span>Modo escuro</span>';
    }
  }

  function ligar() {
    aplicar(localStorage.getItem(CHAVE) || 'dark');
    var botao = document.getElementById('themeToggle');
    if (!botao) return;
    botao.addEventListener('click', function () {
      var atual = document.documentElement.getAttribute('data-theme') === 'light' ? 'light' : 'dark';
      var proximo = atual === 'light' ? 'dark' : 'light';
      localStorage.setItem(CHAVE, proximo);
      aplicar(proximo);
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', ligar);
  } else {
    ligar();
  }
})();
