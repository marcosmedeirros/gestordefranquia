/**
 * CSV DE ELENCO: modelo, prompt pra IA e leitura — num lugar só.
 *
 * Isto existia copiado em três páginas: a do dono (atualizar-elenco.php), a
 * de terceiros (atualizar-time.php) e agora a do admin (controle-elencos.php).
 * As três cópias já tinham divergido: uma pedia à IA o CSV escrito na
 * conversa, outra pedia o arquivo pronto — e só uma avisava que a coluna TO
 * do print é turnover, não toco. Cada correção feita numa não chegava nas
 * outras.
 */
(function () {
  'use strict';

  function escapar(v) {
    v = String(v ?? '');
    return /[",\n\r]/.test(v) ? '"' + v.replace(/"/g, '""') + '"' : v;
  }

  function paraTexto(linhas) {
    return linhas.map(l => l.map(escapar).join(',')).join('\n');
  }

  function baixar(nomeArquivo, linhas) {
    // BOM na frente: sem ele o Excel no Windows abre os acentos errados.
    const csv = '﻿' + linhas.map(l => l.map(escapar).join(',')).join('\r\n');
    const url = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8;' }));
    const a = document.createElement('a');
    a.href = url; a.download = nomeArquivo;
    document.body.appendChild(a); a.click(); a.remove();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
  }

  /** Parser com campo entre aspas (nome com vírgula, etc). */
  function ler(texto) {
    texto = String(texto || '').replace(/^﻿/, '');
    const linhas = [];
    let linha = [], campo = '', aspas = false;
    for (let i = 0; i < texto.length; i++) {
      const c = texto[i];
      if (aspas) {
        if (c === '"') { if (texto[i + 1] === '"') { campo += '"'; i++; } else aspas = false; }
        else campo += c;
      } else if (c === '"') aspas = true;
      else if (c === ',') { linha.push(campo); campo = ''; }
      else if (c === '\r') { /* o \n fecha a linha */ }
      else if (c === '\n') { linha.push(campo); linhas.push(linha); linha = []; campo = ''; }
      else campo += c;
    }
    if (campo !== '' || linha.length) { linha.push(campo); linhas.push(linha); }
    return linhas.filter(l => !(l.length === 1 && l[0].trim() === ''));
  }

  async function copiar(texto, btn) {
    try { await navigator.clipboard.writeText(texto); }
    catch (e) {
      // clipboard bloqueado (http, permissão): o método antigo ainda funciona.
      const ta = document.createElement('textarea');
      ta.value = texto; ta.style.position = 'fixed'; ta.style.opacity = '0';
      document.body.appendChild(ta); ta.select(); document.execCommand('copy'); ta.remove();
    }
    if (btn) {
      const antes = btn.innerHTML;
      btn.innerHTML = '<i class="bi bi-check-lg"></i> Copiado!';
      setTimeout(() => { btn.innerHTML = antes; }, 1800);
    }
  }

  /* Pede ARQUIVO, e não o CSV escrito na conversa. Copiar do chat pra um
     editor é onde a coisa quebra: o chat mete markdown, quebra linha errado
     e o acento vem sem BOM. Pedindo o .csv pronto, o caminho é baixar e subir. */
  const PEDE_ARQUIVO =
    'IMPORTANTE: me devolva o resultado como um ARQUIVO .csv pronto pra baixar, ' +
    'codificado em UTF-8, separado por vírgula, com o mesmo cabeçalho do modelo. ' +
    'Não escreva o CSV no meio da conversa e não explique nada — só gere o arquivo.\n\n';

  const NAO_MEXA = (comTime) =>
    'NÃO altere as colunas "id"' + (comTime ? ', "time"' : '') + ' e "jogador" — ' +
    'elas ligam cada linha ao jogador certo.\n';

  /**
   * @param {string} modelo  o CSV do modelo, já em texto
   * @param {object} o       { notas:[], comOvrIdade:bool, comTime:bool }
   */
  function promptLetras(modelo, o = {}) {
    return 'Preencha este CSV com os atributos de jogadores de basquete a partir da imagem que vou anexar.\n\n' +
      'O print mostra as skills no formato do jogo: IN, MID, 3PT, POST D, PER D, PLAY, REB, ATHL, IQ, POT — ' +
      'cada uma com uma nota entre: ' + (o.notas || []).join(', ') + '.\n' +
      NAO_MEXA(o.comTime) +
      (o.comOvrIdade ? 'Preencha ovr, idade e as 10 notas de cada jogador que aparecer na imagem.\n\n'
                     : 'Preencha só as 10 notas de cada jogador que aparecer na imagem.\n\n') +
      PEDE_ARQUIVO + '--- MODELO ---\n' + modelo;
  }

  function promptStats(modelo, o = {}) {
    return 'Preencha este CSV com estatísticas (médias por jogo) de jogadores de basquete a partir da imagem que vou anexar.\n\n' +
      'O print mostra a tela "Per Game" do jogo. As colunas do CSV correspondem assim:\n' +
      'Jogos = GP · MIN = MIN · PTS = PTS · REB = REB · AST = AST · ROU = STL (roubadas) · TOC = BLK (tocos).\n\n' +
      // TO e TOC são quase a mesma sigla, e a IA pega a coluna errada sozinha:
      // é assim que armador aparece com 8,8 tocos. Dizer só "TOC = tocos" já
      // se mostrou insuficiente.
      'ATENÇÃO: a coluna TOC vem de BLK, NUNCA da coluna TO. No print, TO é turnover ' +
      '(bolas perdidas) e NÃO entra em lugar nenhum. Ignore TO, GS, FLS e as de aproveitamento.\n\n' +
      NAO_MEXA(o.comTime) + '\n' + PEDE_ARQUIVO + '--- MODELO ---\n' + modelo;
  }

  window.ElencoCSV = { escapar, paraTexto, baixar, ler, copiar, promptLetras, promptStats };
})();
