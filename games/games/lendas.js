/* LENDAS DA QUADRA — a cena 3D e a mesa. @see lendas.php
 *
 * Tudo que se vê no tabuleiro é geometria de verdade, montada aqui com o
 * Three.js: piso de tábuas, castelos com torres e ameias, braseiros que
 * iluminam o mapa, brasas subindo, neblina, e cada herói uma miniatura com
 * pedestal e a carta em pé. Nada é imagem de fundo.
 *
 * A regra mora no servidor. Esta tela só (1) anima, em ordem, os eventos
 * que ele devolve e (2) acende as jogadas que ele disse que são possíveis.
 */
(function () {
  'use strict';
  const L = window.LENDAS;
  const $ = (s, el = document) => el.querySelector(s);
  const h = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const esperar = ms => new Promise(r => setTimeout(r, ms));
  const T = 1.2;                          // tamanho de uma casa, em unidades de mundo
  const LARG = L.larg, ALT = L.alt;

  // ═════════════════════════════ servidor ══════════════════════════
  let pacote = null;      // o último estado que o servidor mandou
  let cartas = {};        // id → dados da carta (acumulado)
  let ultimoSeq = 0;
  let ocupado = false;
  let nivelAtual = 2;
  let modo = 'treino';      // 'treino' | 'online'
  let partidaId = null;
  let meuLado = 'A';
  let relogioFim = 0;       // quando o turno atual estoura (ms, relógio local)
  let consulta = null;      // o setInterval da consulta online

  async function api(acao, dados = {}) {
    const fd = new FormData();
    fd.append('acao', acao);
    fd.append('desde', ultimoSeq);
    for (const k in dados) fd.append(k, typeof dados[k] === 'object' ? JSON.stringify(dados[k]) : dados[k]);
    try {
      const r = await fetch(location.pathname, { method: 'POST', body: fd, credentials: 'same-origin' });
      return await r.json();
    } catch (e) {
      return { ok: false, erro: 'Sem conexão com o servidor.' };
    }
  }

  /** As jogadas da mesa vão pro treino ou pra partida online. */
  function apiJogo(acao, dados = {}) {
    if (modo === 'online') return api('on_' + acao, Object.assign({ partida: partidaId }, dados));
    return api(acao, dados);
  }

  // ═════════════════════════ entrar na mesa ════════════════════════
  /* O hub (lendas_hub.js) chama isto: com o pacote que o servidor mandou,
     e se é uma partida nova (anima o "Sua vez") ou uma em andamento. */
  window.LendasJogo = {
    entrar: (r, novo, nivel) => { if (nivel) nivelAtual = nivel; return entrarNoJogo(r, novo); },
  };

  async function entrarNoJogo(r, novo) {
    $('#tela-menu').hidden = true;
    $('#tela-jogo').hidden = false;
    $('#fim').hidden = true;
    modo = r.modo || 'treino';
    partidaId = r.partida || null;
    meuLado = r.eu;
    montarCena();
    recolorir();
    resetarCamera(true);
    limparUnidades();
    selecao = null;
    ultimoSeq = 0;
    Object.assign(cartas, r.cartas);
    pacote = r;
    // Começo de partida (ou volta a uma em andamento): sem animar o passado,
    // a cena só se ajeita no estado atual.
    ultimoSeq = Math.max(0, ...r.eventos.map(ev => ev.seq), 0);
    sincronizar(r.estado, true);
    acertarRelogio(r);
    desenharHud();
    setTimeout(() => $('#carregando').classList.add('some'), 400);
    clearInterval(consulta);
    if (modo === 'online') consulta = setInterval(consultar, 1500);
    if (r.estado.fim) { fimDeJogo(r); return; }
    if (novo) { await esperar(700); faixa(r.estado.vez === meuLado ? 'Sua vez' : `Vez de ${r.estado.jogadores[r.estado.vez].nome}`); }
  }

  // ═══════════════════════ o online: consulta e relógio ════════════
  /* Enquanto é a vez do outro, a tela pergunta ao servidor a cada 1,5s se
     algo aconteceu. É também essa pergunta que faz o servidor conferir o
     relógio — quem está esperando é o primeiro a ver o prazo do outro
     estourar. */
  async function consultar() {
    if (ocupado || !pacote || pacote.estado.fim) return;
    ocupado = true;
    const r = await apiJogo('estado', {});
    if (r && r.estado) {
      Object.assign(cartas, r.cartas || {});
      if (r.eventos && r.eventos.length) await tocarEventos(r.eventos);
      pacote = r;
      sincronizar(r.estado, false);
      acertarRelogio(r);
    }
    ocupado = false;
    if (r && r.estado) {
      desenharHud();
      if (r.estado.fim) fimDeJogo(r);
    }
  }

  function acertarRelogio(r) {
    relogioFim = modo === 'online' && !r.estado.fim ? Date.now() + (r.restante || 0) * 1000 : 0;
  }
  setInterval(() => {
    const el = $('#relogio');
    if (!relogioFim) { el.hidden = true; return; }
    const s = Math.max(0, Math.ceil((relogioFim - Date.now()) / 1000));
    el.hidden = false;
    el.textContent = `⏱ ${s}s`;
    el.classList.toggle('urgente', s <= 15);
  }, 250);

  // ═════════════════════════════ a cena ════════════════════════════
  let renderer, cena, camera, relogio, raycaster, ponteiro;
  let casas = [];           // [y][x] → { mesh, luz }
  const unidades = new Map(); // id → objeto da miniatura
  const fortalezas = {};     // 'A' | 'B' → grupo
  const chamas = [];         // luzes que tremulam
  let brasas;                // partículas
  const animando = [];       // funções chamadas a cada quadro
  const flutuantes = [];     // textos HTML presos a um ponto 3D
  let montada = false;

  const AZUL = 0x5aa2ff, VERMELHO = 0xff4a3d;
  /** A minha cor é sempre o azul — jogue de A ou de B. */
  const corDe = lado => (lado === meuLado ? AZUL : VERMELHO);

  function paraMundo(x, y) { return new THREE.Vector3((x - (LARG - 1) / 2) * T, 0, ((ALT - 1) / 2 - y) * T); }

  function texturaCanvas(w, hgt, desenhar) {
    const cv = document.createElement('canvas');
    cv.width = w; cv.height = hgt;
    desenhar(cv.getContext('2d'), w, hgt);
    const t = new THREE.CanvasTexture(cv);
    t.encoding = THREE.sRGBEncoding;
    t.anisotropy = 4;
    return t;
  }

  /** Tábuas escuras, envernizadas, com veio. */
  function texturaMadeira() {
    return texturaCanvas(512, 512, (g, w, hh) => {
      g.fillStyle = '#1a120c'; g.fillRect(0, 0, w, hh);
      const tabuas = 6;
      for (let i = 0; i < tabuas; i++) {
        const x0 = i * w / tabuas;
        const tom = 18 + Math.random() * 14;
        g.fillStyle = `rgb(${tom + 14},${tom + 4},${tom - 4})`;
        g.fillRect(x0 + 1, 0, w / tabuas - 2, hh);
        for (let k = 0; k < 40; k++) {
          g.strokeStyle = `rgba(0,0,0,${0.08 + Math.random() * 0.15})`;
          g.lineWidth = Math.random() * 2;
          g.beginPath();
          const xx = x0 + Math.random() * (w / tabuas);
          g.moveTo(xx, 0);
          g.bezierCurveTo(xx + 6, hh * .3, xx - 6, hh * .6, xx + 3, hh);
          g.stroke();
        }
        g.fillStyle = 'rgba(0,0,0,.6)'; g.fillRect(x0, 0, 2, hh);
      }
    });
  }

  /** Pedra escura, com manchas. */
  function texturaPedra() {
    return texturaCanvas(256, 256, (g, w, hh) => {
      g.fillStyle = '#2a2a30'; g.fillRect(0, 0, w, hh);
      for (let i = 0; i < 900; i++) {
        const c = 30 + Math.random() * 30;
        g.fillStyle = `rgba(${c},${c},${c + 6},${Math.random() * .5})`;
        g.fillRect(Math.random() * w, Math.random() * hh, 1 + Math.random() * 4, 1 + Math.random() * 4);
      }
      g.strokeStyle = 'rgba(0,0,0,.55)'; g.lineWidth = 2;
      for (let y = 0; y < hh; y += 32) {
        g.beginPath(); g.moveTo(0, y); g.lineTo(w, y); g.stroke();
        for (let x = (y / 32) % 2 ? 0 : 32; x < w; x += 64) { g.beginPath(); g.moveTo(x, y); g.lineTo(x, y + 32); g.stroke(); }
      }
    });
  }

  /** A moldura brilhante de uma casa: borda acesa, miolo transparente. */
  function texturaMoldura() {
    return texturaCanvas(128, 128, (g, w) => {
      const gr = g.createRadialGradient(w / 2, w / 2, w * .25, w / 2, w / 2, w * .72);
      gr.addColorStop(0, 'rgba(255,255,255,0)'); gr.addColorStop(.75, 'rgba(255,255,255,.12)'); gr.addColorStop(1, 'rgba(255,255,255,.55)');
      g.fillStyle = gr; g.fillRect(0, 0, w, w);
      g.strokeStyle = 'rgba(255,255,255,.95)'; g.lineWidth = 3; g.strokeRect(4, 4, w - 8, w - 8);
    });
  }

  function texturaFaisca() {
    return texturaCanvas(64, 64, (g, w) => {
      const gr = g.createRadialGradient(w / 2, w / 2, 0, w / 2, w / 2, w / 2);
      gr.addColorStop(0, 'rgba(255,255,255,1)'); gr.addColorStop(.25, 'rgba(255,200,120,.8)'); gr.addColorStop(1, 'rgba(255,80,0,0)');
      g.fillStyle = gr; g.fillRect(0, 0, w, w);
    });
  }

  /** Um céu de arena à noite, só pra o metal e a madeira terem o que refletir. */
  function ambiente() {
    const t = texturaCanvas(512, 256, (g, w, hh) => {
      const gr = g.createLinearGradient(0, 0, 0, hh);
      gr.addColorStop(0, '#1b2236'); gr.addColorStop(.5, '#07080d'); gr.addColorStop(1, '#030305');
      g.fillStyle = gr; g.fillRect(0, 0, w, hh);
      const luz = (x, y, r, c) => { const rg = g.createRadialGradient(x, y, 0, x, y, r); rg.addColorStop(0, c); rg.addColorStop(1, 'rgba(0,0,0,0)'); g.fillStyle = rg; g.fillRect(0, 0, w, hh); };
      luz(120, 60, 60, 'rgba(160,190,255,.9)'); luz(380, 150, 90, 'rgba(255,110,40,.6)'); luz(250, 170, 70, 'rgba(255,60,40,.4)');
    });
    t.mapping = THREE.EquirectangularReflectionMapping;
    const pm = new THREE.PMREMGenerator(renderer);
    const env = pm.fromEquirectangular(t).texture;
    pm.dispose(); t.dispose();
    return env;
  }

  function montarCena() {
    if (montada) return;
    montada = true;
    const canvas = $('#cena');
    renderer = new THREE.WebGLRenderer({ canvas, antialias: true });
    renderer.setPixelRatio(Math.min(2, window.devicePixelRatio || 1));
    renderer.outputEncoding = THREE.sRGBEncoding;
    renderer.toneMapping = THREE.ACESFilmicToneMapping;
    renderer.toneMappingExposure = 1.45;
    renderer.shadowMap.enabled = true;
    renderer.shadowMap.type = THREE.PCFSoftShadowMap;

    cena = new THREE.Scene();
    cena.background = new THREE.Color(0x05060a);
    cena.fog = new THREE.FogExp2(0x05060a, 0.03);
    cena.environment = ambiente();
    camera = new THREE.PerspectiveCamera(42, 1, 0.1, 200);
    relogio = new THREE.Clock();
    raycaster = new THREE.Raycaster();
    ponteiro = new THREE.Vector2();

    // Luz: a lua, fria, por cima; um ambiente quase nenhum. O calor vem
    // todo dos braseiros — é o que dá o ar sombrio.
    cena.add(new THREE.HemisphereLight(0x4a5a8a, 0x120a08, 0.7));
    const lua = new THREE.DirectionalLight(0x9fbcff, 1.1);
    lua.position.set(-8, 16, 6);
    lua.castShadow = true;
    lua.shadow.mapSize.set(2048, 2048);
    Object.assign(lua.shadow.camera, { left: -9, right: 9, top: 9, bottom: -9, near: 1, far: 40 });
    lua.shadow.bias = -0.0005;
    cena.add(lua);
    // Um holofote frio só sobre o tabuleiro: ele é o palco, e o resto da
    // arena continua no breu.
    const palco = new THREE.SpotLight(0xc8d8ff, 2.2, 30, .55, .6, 1.2);
    palco.position.set(0, 14, 0);
    palco.target.position.set(0, 0, 0);
    palco.castShadow = true;
    palco.shadow.mapSize.set(1024, 1024);
    cena.add(palco, palco.target);

    montarChao();
    montarTabuleiro();
    montarFortaleza('A');
    montarFortaleza('B');
    montarPilares();
    montarBrasas();
    ligarControles();

    window.addEventListener('resize', ajustarTamanho);
    ajustarTamanho();
    renderer.setAnimationLoop(quadro);
  }

  function montarChao() {
    // O chão em volta: uma quadra antiga, apagada, sumindo na neblina.
    const tex = texturaCanvas(1024, 1024, (g, w) => {
      g.fillStyle = '#0d0a08'; g.fillRect(0, 0, w, w);
      g.strokeStyle = 'rgba(255,150,80,.10)'; g.lineWidth = 6;
      g.beginPath(); g.arc(w / 2, w / 2, 140, 0, Math.PI * 2); g.stroke();
      g.beginPath(); g.moveTo(0, w / 2); g.lineTo(w, w / 2); g.stroke();
      g.beginPath(); g.arc(w / 2, 40, 330, 0, Math.PI); g.stroke();
      g.beginPath(); g.arc(w / 2, w - 40, 330, Math.PI, 0); g.stroke();
      for (let i = 0; i < 4000; i++) { g.fillStyle = `rgba(255,255,255,${Math.random() * .025})`; g.fillRect(Math.random() * w, Math.random() * w, 2, 2); }
    });
    const chao = new THREE.Mesh(new THREE.PlaneGeometry(60, 60),
      new THREE.MeshStandardMaterial({ map: tex, roughness: .55, metalness: .25, color: 0x8a8a8a }));
    chao.rotation.x = -Math.PI / 2;
    chao.position.y = -0.08;
    chao.receiveShadow = true;
    cena.add(chao);

    // A borda do tabuleiro: um degrau de pedra.
    const borda = new THREE.Mesh(new THREE.BoxGeometry(LARG * T + 0.5, 0.18, ALT * T + 0.5),
      new THREE.MeshStandardMaterial({ map: texturaPedra(), color: 0x6a6a72, roughness: .9 }));
    borda.position.y = -0.05;
    borda.receiveShadow = true;
    cena.add(borda);
  }

  function montarTabuleiro() {
    const madeira = texturaMadeira();
    const moldura = texturaMoldura();
    const geo = new THREE.BoxGeometry(T * .96, 0.12, T * .96);
    const geoLuz = new THREE.PlaneGeometry(T * .98, T * .98);
    for (let y = 0; y < ALT; y++) {
      casas[y] = [];
      for (let x = 0; x < LARG; x++) {
        // Rugosidade .58: envernizada, mas sem espelhar o holofote — com .36,
        // quem jogava do lado B via o piso estourar de branco.
        const mat = new THREE.MeshStandardMaterial({ map: madeira, roughness: .58, metalness: .05, color: (x + y) % 2 ? 0xf0e0cc : 0xc8b49c });
        const m = new THREE.Mesh(geo, mat);
        const p = paraMundo(x, y);
        m.position.set(p.x, 0.06, p.z);
        m.receiveShadow = true;
        m.userData = { casa: [x, y] };
        cena.add(m);
        const luz = new THREE.Mesh(geoLuz, new THREE.MeshBasicMaterial({
          map: moldura, transparent: true, opacity: .1, color: 0xffffff, blending: THREE.AdditiveBlending, depthWrite: false }));
        luz.rotation.x = -Math.PI / 2;
        luz.position.set(p.x, 0.125, p.z);
        luz.userData = { casa: [x, y] };
        cena.add(luz);
        casas[y][x] = { mesh: m, luz, base: corBaseCasa(x, y), estado: null };
      }
    }
    pintarCasas();
  }

  /** A cor de repouso de uma casa: azul na sua zona, vermelho na do rival. */
  function corBaseCasa(x, y) {
    if (y <= 1) return { cor: corDe('A'), op: .32 };
    if (y >= ALT - 2) return { cor: corDe('B'), op: .32 };
    return { cor: 0xffffff, op: .09 };
  }

  function pedraMat() {
    return new THREE.MeshStandardMaterial({ map: texturaPedra(), color: 0x8a8a92, roughness: .85, metalness: .05 });
  }

  /** O castelo: muralha de três casas, três torres com telhado, ameias, janelas acesas e dois braseiros. */
  function montarFortaleza(lado) {
    const g = new THREE.Group();
    const y = lado === 'A' ? 0 : ALT - 1;
    const centro = paraMundo(3, y);
    g.position.copy(centro);
    const pedra = pedraMat();
    const telhado = new THREE.MeshStandardMaterial({ color: 0x1b2a4a, roughness: .5, metalness: .4 });
    const brilho = new THREE.MeshBasicMaterial({ color: 0x7ab8ff });
    const tintas = { telhado, brilho, luzes: [], fogos: [], bandeira: null };

    const muro = new THREE.Mesh(new THREE.BoxGeometry(T * 3 - .1, 1.0, T * .8), pedra);
    muro.position.y = 0.5;
    g.add(muro);
    for (let i = -6; i <= 6; i++) { // ameias
      if (i % 2) continue;
      const a = new THREE.Mesh(new THREE.BoxGeometry(.22, .22, T * .8), pedra);
      a.position.set(i * .27, 1.11, 0);
      g.add(a);
    }
    [-T, 0, T].forEach((dx, k) => {
      // Torres baixas o bastante pra o castelo da frente não tampar o tabuleiro.
      const alt = k === 1 ? 1.75 : 1.3;
      const torre = new THREE.Mesh(new THREE.CylinderGeometry(.34, .4, alt, 12), pedra);
      torre.position.set(dx, alt / 2, 0);
      g.add(torre);
      const teto = new THREE.Mesh(new THREE.ConeGeometry(.46, .8, 12), telhado);
      teto.position.set(dx, alt + .38, 0);
      g.add(teto);
      for (let w = 0; w < 2; w++) { // janelas
        const jan = new THREE.Mesh(new THREE.PlaneGeometry(.1, .2), brilho);
        jan.position.set(dx, alt * (.45 + w * .28), lado === 'A' ? -.4 : .4);
        if (lado === 'B') jan.rotation.y = Math.PI;
        g.add(jan);
      }
    });
    // o estandarte da torre do meio
    const mastro = new THREE.Mesh(new THREE.CylinderGeometry(.02, .02, .8), new THREE.MeshStandardMaterial({ color: 0x222222, metalness: .8 }));
    mastro.position.set(0, 2.55, 0);
    g.add(mastro);
    const bandeira = new THREE.Mesh(new THREE.PlaneGeometry(.55, .32, 8, 2),
      new THREE.MeshStandardMaterial({ color: AZUL, emissive: AZUL, emissiveIntensity: .35, side: THREE.DoubleSide }));
    tintas.bandeira = bandeira.material;
    bandeira.position.set(.29, 2.8, 0);
    g.add(bandeira);
    animando.push(t => {
      const pos = bandeira.geometry.attributes.position;
      for (let i = 0; i < pos.count; i++) {
        const x = pos.getX(i);
        pos.setZ(i, Math.sin(x * 9 + t * 4 + (lado === 'A' ? 0 : 1)) * .05 * (x + .28));
      }
      pos.needsUpdate = true;
    });
    g.traverse(o => { if (o.isMesh) { o.castShadow = true; o.receiveShadow = true; o.userData.fortaleza = lado; } });

    // Braseiros nas pontas: fogo azul de um lado, vermelho do outro.
    [-1.95, 1.95].forEach(dx => {
      const base = new THREE.Mesh(new THREE.CylinderGeometry(.18, .12, .7, 10), new THREE.MeshStandardMaterial({ color: 0x1a1a1a, metalness: .9, roughness: .3 }));
      base.position.set(dx, .35, 0);
      const bacia = new THREE.Mesh(new THREE.CylinderGeometry(.3, .16, .18, 14, 1, true), new THREE.MeshStandardMaterial({ color: 0x2a2018, metalness: .8, roughness: .4, side: THREE.DoubleSide }));
      bacia.position.set(dx, .78, 0);
      g.add(base, bacia);
      const corFogo = AZUL;
      const luz = new THREE.PointLight(corFogo, 3.2, 10, 2);
      tintas.luzes.push(luz);
      luz.position.set(dx, 1.15, 0);
      g.add(luz);
      chamas.push({ luz, base: 3.2, fase: Math.random() * 10 });
      const fogo = new THREE.Sprite(new THREE.SpriteMaterial({ map: texturaFaisca(), color: corFogo, blending: THREE.AdditiveBlending, depthWrite: false }));
      fogo.position.set(dx, 1.05, 0);
      fogo.scale.set(.75, 1.1, 1);
      tintas.fogos.push(fogo.material);
      g.add(fogo);
      animando.push(t => { const s = .9 + Math.sin(t * 13 + dx) * .08 + Math.sin(t * 7.3) * .06; fogo.scale.set(.7 * s, 1.1 * s, 1); });
    });
    cena.add(g);
    g.userData.tintas = tintas;
    fortalezas[lado] = g;
  }

  /** Pinta castelos e casas conforme quem joga: o meu lado é o azul. */
  function recolorir() {
    for (const lado of ['A', 'B']) {
      const t = fortalezas[lado].userData.tintas;
      const meu = lado === meuLado;
      t.telhado.color.setHex(meu ? 0x1b2a4a : 0x3a1010);
      t.brilho.color.setHex(meu ? 0x7ab8ff : 0xff6a3d);
      t.bandeira.color.setHex(corDe(lado)); t.bandeira.emissive.setHex(corDe(lado));
      t.luzes.forEach(l => l.color.setHex(meu ? AZUL : 0xff6a1a));
      t.fogos.forEach(f => f.color.setHex(meu ? AZUL : 0xff6a1a));
    }
    for (let y = 0; y < ALT; y++) for (let x = 0; x < LARG; x++) casas[y][x].base = corBaseCasa(x, y);
    pintarCasas();
  }

  /** Pilares de pedra em volta, com tochas — dão fundo e profundidade à arena. */
  function montarPilares() {
    const pedra = pedraMat();
    const faisca = texturaFaisca();
    const n = 10;
    for (let i = 0; i < n; i++) {
      const ang = (i / n) * Math.PI * 2 + .3;
      const r = 11.5;
      const p = new THREE.Mesh(new THREE.CylinderGeometry(.45, .55, 6, 10), pedra);
      p.position.set(Math.cos(ang) * r, 3, Math.sin(ang) * r * 1.15);
      p.castShadow = true;
      cena.add(p);
      const tocha = new THREE.Sprite(new THREE.SpriteMaterial({ map: faisca, color: 0xff7a2a, blending: THREE.AdditiveBlending, depthWrite: false }));
      tocha.position.set(p.position.x * .95, 4.2, p.position.z * .95);
      tocha.scale.set(.9, 1.3, 1);
      cena.add(tocha);
      animando.push(t => { const s = 1 + Math.sin(t * 11 + i) * .1; tocha.scale.set(.9 * s, 1.3 * s, 1); });
    }
  }

  /** Brasas subindo dos braseiros e um pó fino no ar. */
  function montarBrasas() {
    const N = 260;
    const pos = new Float32Array(N * 3);
    const vel = new Float32Array(N);
    const nasce = i => {
      const lado = Math.random() < .5 ? 'A' : 'B';
      const p = paraMundo(3, lado === 'A' ? 0 : ALT - 1);
      const solta = Math.random() < .55;
      pos[i * 3] = solta ? (Math.random() - .5) * 14 : p.x + (Math.random() < .5 ? -1.95 : 1.95) + (Math.random() - .5) * .3;
      pos[i * 3 + 1] = solta ? Math.random() * 5 : 1.1;
      pos[i * 3 + 2] = solta ? (Math.random() - .5) * 14 : p.z + (Math.random() - .5) * .3;
      vel[i] = .25 + Math.random() * .6;
    };
    for (let i = 0; i < N; i++) { nasce(i); pos[i * 3 + 1] = Math.random() * 6; }
    const geo = new THREE.BufferGeometry();
    geo.setAttribute('position', new THREE.BufferAttribute(pos, 3));
    brasas = new THREE.Points(geo, new THREE.PointsMaterial({ map: texturaFaisca(), size: .12, color: 0xff9a4a,
      transparent: true, blending: THREE.AdditiveBlending, depthWrite: false }));
    cena.add(brasas);
    animando.push((t, dt) => {
      for (let i = 0; i < N; i++) {
        pos[i * 3 + 1] += vel[i] * dt;
        pos[i * 3] += Math.sin(t * 1.3 + i) * dt * .15;
        if (pos[i * 3 + 1] > 6.5) nasce(i);
      }
      geo.attributes.position.needsUpdate = true;
    });
  }

  // ═══════════════════════════ a câmera ═══════════════════════════
  /* Órbita feita aqui (o OrbitControls não vem no three.min.js): arrastar
     gira em volta do centro, a roda e a pinça aproximam. Clique é o
     arrastar que não andou — é o que separa "girar" de "escolher casa". */
  const orbita = { theta: 0, phi: .62, raio: 16, alvoTheta: 0, alvoPhi: .62, alvoRaio: 16 };
  function raioPadrao() { return camera.aspect < .8 ? 23 : camera.aspect < 1.2 ? 19 : 16.5; }
  // Quem joga de B vê o tabuleiro do outro lado: o próprio castelo perto.
  function resetarCamera(direto) {
    orbita.alvoTheta = meuLado === 'B' ? Math.PI : 0; orbita.alvoPhi = .62; orbita.alvoRaio = raioPadrao();
    // Ao entrar na partida, a câmera já nasce no lugar — sem o giro de 180°
    // de quem joga do lado B passando pela diagonal.
    if (direto) { orbita.theta = orbita.alvoTheta; orbita.phi = orbita.alvoPhi; orbita.raio = orbita.alvoRaio; }
  }

  function ligarControles() {
    const cv = $('#cena');
    const toques = new Map();
    let inicio = null, arrastou = false, distPinca = 0;
    cv.addEventListener('pointerdown', e => {
      cv.setPointerCapture(e.pointerId);
      toques.set(e.pointerId, { x: e.clientX, y: e.clientY });
      if (toques.size === 1) { inicio = { x: e.clientX, y: e.clientY, theta: orbita.alvoTheta, phi: orbita.alvoPhi }; arrastou = false; }
      if (toques.size === 2) { const [a, b] = [...toques.values()]; distPinca = Math.hypot(a.x - b.x, a.y - b.y); arrastou = true; }
    });
    cv.addEventListener('pointermove', e => {
      if (!toques.has(e.pointerId)) { passarPor(e); return; }
      toques.set(e.pointerId, { x: e.clientX, y: e.clientY });
      if (toques.size === 2) {
        const [a, b] = [...toques.values()];
        const d = Math.hypot(a.x - b.x, a.y - b.y);
        orbita.alvoRaio = Math.max(8, Math.min(28, orbita.alvoRaio * distPinca / d));
        distPinca = d;
        return;
      }
      if (!inicio) return;
      const dx = e.clientX - inicio.x, dy = e.clientY - inicio.y;
      if (Math.abs(dx) + Math.abs(dy) > 6) arrastou = true;
      if (arrastou) {
        orbita.alvoTheta = inicio.theta - dx * .006;
        orbita.alvoPhi = Math.max(.35, Math.min(1.32, inicio.phi - dy * .005));
      }
    });
    const solta = e => {
      const eraClique = toques.size === 1 && !arrastou;
      toques.delete(e.pointerId);
      if (eraClique && e.type === 'pointerup') clicar(e);
      if (!toques.size) inicio = null;
    };
    cv.addEventListener('pointerup', solta);
    cv.addEventListener('pointercancel', solta);
    cv.addEventListener('wheel', e => { e.preventDefault(); orbita.alvoRaio = Math.max(8, Math.min(28, orbita.alvoRaio * (1 + Math.sign(e.deltaY) * .08))); }, { passive: false });
    $('#bt-camera').addEventListener('click', resetarCamera);
  }

  function ajustarTamanho() {
    const w = window.innerWidth, hh = window.innerHeight;
    renderer.setSize(w, hh, false);
    camera.aspect = w / hh;
    camera.fov = camera.aspect < .8 ? 55 : 42;
    camera.updateProjectionMatrix();
    resetarCamera();
  }

  // ═════════════════════════ o quadro ═════════════════════════════
  function quadro() {
    const dt = Math.min(.05, relogio.getDelta());
    const t = relogio.elapsedTime;
    orbita.theta += (orbita.alvoTheta - orbita.theta) * .12;
    orbita.phi += (orbita.alvoPhi - orbita.phi) * .12;
    orbita.raio += (orbita.alvoRaio - orbita.raio) * .12;
    camera.position.set(Math.sin(orbita.theta) * Math.sin(orbita.phi) * orbita.raio, Math.cos(orbita.phi) * orbita.raio,
                        Math.cos(orbita.theta) * Math.sin(orbita.phi) * orbita.raio);
    camera.lookAt(0, 0, meuLado === 'B' ? -1.25 : 1.25);

    for (const c of chamas) c.luz.intensity = c.base * (.82 + Math.sin(t * 9 + c.fase) * .1 + Math.sin(t * 23 + c.fase * 2) * .08);
    for (const f of animando.slice()) f(t, dt);
    for (const u of unidades.values()) {
      u.carta.position.y = 1.0 + Math.sin(t * 1.6 + u.id) * .035;
      // A carta encara a câmera, mas só girando em pé (sem tombar).
      u.carta.rotation.y = Math.atan2(camera.position.x - u.g.position.x, camera.position.z - u.g.position.z);
      u.barra.position.y = 1.86 + Math.sin(t * 1.6 + u.id) * .035;
      if (u.anel) u.anel.material.opacity = .55 + Math.sin(t * 3 + u.id) * .2;
    }
    // casas acesas pulsam
    const pulso = .5 + Math.sin(t * 5) * .5;
    for (const linha of casas) for (const c of linha) {
      if (c.estado === 'mover') c.luz.material.opacity = .45 + pulso * .3;
      else if (c.estado === 'alvo' || c.estado === 'entrada') c.luz.material.opacity = .5 + pulso * .35;
    }
    renderer.render(cena, camera);
    posicionarFlutuantes();
  }

  // ════════════════════════ as miniaturas ═════════════════════════
  const RAR_COR = { comum: ['#3a332b', '#857462'], rara: ['#12305e', '#4d8ae0'], epica: ['#3a1260', '#9a5cf0'], lendaria: ['#4a3304', '#f2c14e'] };

  /* As fotos ficam guardadas: a da carta na mão é baixada assim que ela
     aparece, e quando vira miniatura 3D já está pronta — antes cada herói
     baixava a foto de novo na hora de entrar, e era isso que demorava. */
  const fotos = new Map();
  function imagem(url) {
    if (!url) return Promise.resolve(null);
    if (!fotos.has(url)) {
      fotos.set(url, new Promise(ok => {
        const i = new Image();
        i.crossOrigin = 'anonymous';
        i.onload = () => ok(i);
        i.onerror = () => ok(null);
        i.src = url;
      }));
    }
    return fotos.get(url);
  }

  /** A carta em pé da miniatura: retrato, nome, ATQ e VIDA. */
  function texturaDaCarta(c, lado) {
    const W = 256, Hc = 360;
    const cv = document.createElement('canvas');
    cv.width = W; cv.height = Hc;
    const g = cv.getContext('2d');
    const tex = new THREE.CanvasTexture(cv);
    tex.encoding = THREE.sRGBEncoding;
    const [c1, c2] = RAR_COR[c.rar] || RAR_COR.comum;
    const desenha = foto => {
      g.clearRect(0, 0, W, Hc);
      const gr = g.createLinearGradient(0, 0, W, Hc);
      gr.addColorStop(0, c1); gr.addColorStop(.55, c2); gr.addColorStop(1, c1);
      g.fillStyle = gr;
      g.beginPath(); g.roundRect(4, 4, W - 8, Hc - 8, 22); g.fill();
      g.save(); g.beginPath(); g.roundRect(4, 4, W - 8, Hc - 8, 22); g.clip();
      if (foto) { const fw = W * 1.05, fh = fw * foto.height / foto.width; g.drawImage(foto, (W - fw) / 2, 30, fw, fh); }
      const fade = g.createLinearGradient(0, 190, 0, 260);
      fade.addColorStop(0, 'rgba(0,0,0,0)'); fade.addColorStop(1, 'rgba(8,8,12,.95)');
      g.fillStyle = fade; g.fillRect(0, 190, W, Hc);
      g.restore();
      g.lineWidth = 7; g.strokeStyle = lado === meuLado ? '#5aa2ff' : '#ff4a3d';
      g.beginPath(); g.roundRect(4, 4, W - 8, Hc - 8, 22); g.stroke();
      g.fillStyle = '#f3e7d3'; g.textAlign = 'center';
      let tam = 28; g.font = `800 ${tam}px Cinzel, serif`;
      const nome = c.nome.toUpperCase();
      while (g.measureText(nome).width > W - 30 && tam > 15) { tam--; g.font = `800 ${tam}px Cinzel, serif`; }
      g.fillText(nome, W / 2, 270);
      g.font = '700 30px Oswald, sans-serif';
      g.fillStyle = '#ffb15c'; g.fillText('⚔ ' + c.atq, W * .28, 322);
      g.fillStyle = '#ff7a7a'; g.fillText('♥ ' + c.vida, W * .72, 322);
      tex.needsUpdate = true;
    };
    desenha(null);
    imagem(c.foto).then(f => f && desenha(f));
    return tex;
  }

  /** A barrinha de vida (e os status) em cima da miniatura. */
  function desenharBarra(u) {
    const g = u.barraCv.getContext('2d');
    const W = u.barraCv.width;
    g.clearRect(0, 0, W, 64);
    g.fillStyle = 'rgba(0,0,0,.7)'; g.beginPath(); g.roundRect(8, 18, W - 16, 22, 11); g.fill();
    const p = Math.max(0, u.vida / u.vidaMax);
    g.fillStyle = p > .5 ? '#4ade80' : p > .25 ? '#f59e0b' : '#ef4444';
    g.beginPath(); g.roundRect(11, 21, (W - 22) * p, 16, 8); g.fill();
    g.fillStyle = '#fff'; g.font = '700 16px Oswald, sans-serif'; g.textAlign = 'center';
    g.fillText(`${Math.max(0, u.vida)}/${u.vidaMax}`, W / 2, 35);
    const icones = [];
    if (u.st.escudo) icones.push('🛡' + u.st.escudo);
    if (u.st.atordoado || u.st.travado) icones.push('💫');
    if (u.st.preso || u.st.preso_agora) icones.push('⛓');
    if (u.st.maldicoes && u.st.maldicoes.length) icones.push('☠');
    g.font = '15px sans-serif'; g.fillText(icones.join(' '), W / 2, 14);
    u.barraTex.needsUpdate = true;
  }

  function criarUnidade(dados, carta) {
    const g = new THREE.Group();
    const p = paraMundo(dados.x, dados.y);
    g.position.copy(p);
    const lado = dados.dono;

    const pedestal = new THREE.Mesh(new THREE.CylinderGeometry(.42, .48, .16, 28),
      new THREE.MeshStandardMaterial({ color: 0x15161c, metalness: .85, roughness: .3 }));
    pedestal.position.y = .2;
    pedestal.castShadow = true;
    const anel = new THREE.Mesh(new THREE.TorusGeometry(.46, .025, 8, 40),
      new THREE.MeshBasicMaterial({ color: corDe(lado), transparent: true, opacity: .7 }));
    anel.rotation.x = Math.PI / 2;
    anel.position.y = .29;
    const brilho = new THREE.Mesh(new THREE.CircleGeometry(.6, 28),
      new THREE.MeshBasicMaterial({ color: corDe(lado), transparent: true, opacity: .18, blending: THREE.AdditiveBlending, depthWrite: false }));
    brilho.rotation.x = -Math.PI / 2;
    brilho.position.y = .13;

    // A carta brilha por conta própria (emissive): ela fica de frente pra
    // câmera e de costas pra lua, e sem brilho próprio o rosto sumia no escuro.
    const texCarta = texturaDaCarta(carta, lado);
    const carta3d = new THREE.Mesh(new THREE.PlaneGeometry(.92, 1.3),
      new THREE.MeshStandardMaterial({ map: texCarta, emissive: 0xffffff, emissiveMap: texCarta, emissiveIntensity: .62,
        roughness: .5, metalness: .1, side: THREE.DoubleSide, transparent: true }));
    carta3d.position.y = 1.0;
    carta3d.castShadow = true;

    const barraCv = document.createElement('canvas'); barraCv.width = 160; barraCv.height = 64;
    const barraTex = new THREE.CanvasTexture(barraCv);
    // toneMapped: false — sem isso a correção de cor da cena lavava a barra.
    barraTex.encoding = THREE.sRGBEncoding;
    const barra = new THREE.Sprite(new THREE.SpriteMaterial({ map: barraTex, depthTest: false, transparent: true, toneMapped: false }));
    barra.scale.set(1.05, .42, 1);
    barra.position.y = 1.86;
    barra.renderOrder = 10;

    g.add(pedestal, anel, brilho, carta3d, barra);
    [pedestal, carta3d].forEach(m => { m.userData.unidade = dados.id; });
    cena.add(g);
    const u = { id: dados.id, g, carta: carta3d, anel, barra, barraCv, barraTex, lado,
      vida: dados.vida, vidaMax: dados.vida_max, st: dados, cartaId: dados.carta };
    desenharBarra(u);
    unidades.set(dados.id, u);
    return u;
  }

  function removerUnidade(id) {
    const u = unidades.get(id);
    if (!u) return;
    cena.remove(u.g);
    unidades.delete(id);
  }

  function limparUnidades() { for (const id of [...unidades.keys()]) removerUnidade(id); }

  /** Põe a cena exatamente como o estado diz: quem existe, onde está, quanta vida tem. */
  function sincronizar(estado, instantaneo) {
    const vivos = new Set(estado.unidades.map(u => u.id));
    for (const id of [...unidades.keys()]) if (!vivos.has(id)) removerUnidade(id);
    for (const d of estado.unidades) {
      let u = unidades.get(d.id);
      if (!u) u = criarUnidade(d, cartas[d.carta]);
      const p = paraMundo(d.x, d.y);
      if (instantaneo) u.g.position.copy(p);
      else u.g.position.lerp(p, 1);
      u.vida = d.vida; u.vidaMax = d.vida_max; u.st = d;
      desenharBarra(u);
    }
  }

  // ═════════════════════ animação (tweens) ════════════════════════
  /* O RITMO: toda animação passa por aqui. Na primeira versão a entrada de
     um herói somava mais de um segundo de efeito, e o jogo parecia lento.
     As jogadas do rival correm ainda mais rápido: é assistir, não jogar. */
  let ritmo = .7;
  const pausa = ms => esperar(ms * ritmo);
  function tween(dur, fn) {
    dur *= ritmo;
    return new Promise(ok => {
      const ini = performance.now();
      const passo = () => {
        const k = Math.min(1, (performance.now() - ini) / dur);
        fn(k);
        if (k < 1) requestAnimationFrame(passo); else ok();
      };
      requestAnimationFrame(passo);
    });
  }
  const suave = k => k * k * (3 - 2 * k);

  /** Texto HTML que segue um ponto 3D (dano, cura, "Crítico!"). */
  function flutuar(pos3, texto, classe) {
    const el = document.createElement('div');
    el.className = 'flutua ' + (classe || '');
    el.textContent = texto;
    document.body.appendChild(el);
    const f = { el, pos: pos3.clone(), ini: performance.now() };
    flutuantes.push(f);
    setTimeout(() => { el.remove(); flutuantes.splice(flutuantes.indexOf(f), 1); }, 1300);
  }
  function posicionarFlutuantes() {
    for (const f of flutuantes) {
      const k = (performance.now() - f.ini) / 1300;
      const p = f.pos.clone(); p.y += k * 1.2;
      p.project(camera);
      f.el.style.left = ((p.x + 1) / 2 * window.innerWidth) + 'px';
      f.el.style.top = ((1 - p.y) / 2 * window.innerHeight) + 'px';
      f.el.style.opacity = String(1 - Math.max(0, k - .6) / .4);
    }
  }

  /** O herói sobe do piso, numa coluna de luz. */
  async function surgir(u, x, y, lado) {
    u.g.position.copy(paraMundo(x, y)).setY(-1.4);
    pilarDeLuz(paraMundo(x, y), corDe(lado));
    await tween(420, k => { u.g.position.y = -1.4 + suave(k) * 1.4; });
  }

  /** Coluna de luz onde um herói entra. */
  async function pilarDeLuz(pos, cor) {
    const m = new THREE.Mesh(new THREE.CylinderGeometry(.45, .45, 6, 24, 1, true),
      new THREE.MeshBasicMaterial({ color: cor, transparent: true, opacity: 0, blending: THREE.AdditiveBlending, depthWrite: false, side: THREE.DoubleSide }));
    m.position.set(pos.x, 3, pos.z);
    cena.add(m);
    await tween(520, k => { m.material.opacity = Math.sin(k * Math.PI) * .55; m.scale.set(1 - k * .5, 1, 1 - k * .5); });
    cena.remove(m);
  }

  /** Um projétil de luz em arco, pra quem ataca de longe. */
  async function projetil(de, para, cor) {
    const s = new THREE.Sprite(new THREE.SpriteMaterial({ map: texturaFaisca(), color: cor, blending: THREE.AdditiveBlending, depthWrite: false }));
    s.scale.set(.6, .6, 1);
    cena.add(s);
    const luz = new THREE.PointLight(cor, 2, 4);
    cena.add(luz);
    const a = de.clone().setY(1.2), b = para.clone().setY(1.0);
    await tween(420, k => {
      const p = a.clone().lerp(b, k); p.y += Math.sin(k * Math.PI) * 1.4;
      s.position.copy(p); luz.position.copy(p);
    });
    cena.remove(s); cena.remove(luz);
    faiscas(b, cor);
  }

  function faiscas(pos, cor) {
    const n = 18, geo = new THREE.BufferGeometry(), arr = new Float32Array(n * 3), v = [];
    for (let i = 0; i < n; i++) { arr.set([pos.x, pos.y, pos.z], i * 3); v.push(new THREE.Vector3((Math.random() - .5) * 3, Math.random() * 2.5, (Math.random() - .5) * 3)); }
    geo.setAttribute('position', new THREE.BufferAttribute(arr, 3));
    const pts = new THREE.Points(geo, new THREE.PointsMaterial({ map: texturaFaisca(), color: cor, size: .25, transparent: true, blending: THREE.AdditiveBlending, depthWrite: false }));
    cena.add(pts);
    tween(600, k => {
      for (let i = 0; i < n; i++) {
        arr[i * 3] = pos.x + v[i].x * k; arr[i * 3 + 1] = pos.y + v[i].y * k - 3 * k * k; arr[i * 3 + 2] = pos.z + v[i].z * k;
      }
      geo.attributes.position.needsUpdate = true;
      pts.material.opacity = 1 - k;
    }).then(() => cena.remove(pts));
  }

  function posDe(alvo, ladoDoAlvo) {
    if (alvo === 'fortaleza') { const f = fortalezas[ladoDoAlvo]; return f.position.clone().setY(1.2); }
    const u = unidades.get(alvo);
    return u ? u.g.position.clone() : new THREE.Vector3();
  }

  // ═════════════════════ os eventos, em ordem ═════════════════════
  const NOME_LADO = l => (l === meuLado ? 'Você' : (pacote.estado.jogadores[l].nome || 'Rival'));

  async function tocarEventos(eventos) {
    let doRival = false;
    for (const ev of eventos) {
      ultimoSeq = Math.max(ultimoSeq, ev.seq);
      if (ev.tipo === 'turno') doRival = ev.lado !== pacote.eu;
      ritmo = doRival ? .5 : .7;
      await tocar(ev);
    }
    ritmo = .7;
  }

  async function tocar(ev) {
    switch (ev.tipo) {
      case 'turno': {
        if (ev.turno === 1) return;
        const meu = ev.lado === pacote.eu;
        registrar(meu ? '— Sua vez —' : `— Vez de ${NOME_LADO(ev.lado)} —`, ev.lado);
        faixa(meu ? 'Sua vez' : `Vez de ${NOME_LADO(ev.lado)}`);
        await pausa(meu ? 500 : 350);
        return;
      }
      case 'entrou': {
        const c = cartas[ev.carta];
        registrar(`${NOME_LADO(ev.lado)} invocou ${c.nome}`, ev.lado);
        // Já apareceu no clique (entrada otimista)? Só troca o id provisório.
        const prov = unidades.get('prov');
        if (prov && ev.lado === pacote.eu) {
          unidades.delete('prov');
          prov.id = ev.unidade;
          prov.g.traverse(o => { if (o.userData.unidade === 'prov') o.userData.unidade = ev.unidade; });
          unidades.set(ev.unidade, prov);
          return;
        }
        const d = { id: ev.unidade, dono: ev.lado, x: ev.x, y: ev.y, vida: c.vida, vida_max: c.vida, carta: ev.carta, escudo: 0 };
        const u = unidades.get(ev.unidade) || criarUnidade(d, c);
        await surgir(u, ev.x, ev.y, ev.lado);
        return;
      }
      case 'moveu': {
        const u = unidades.get(ev.unidade);
        if (!u) return;
        const a = paraMundo(...ev.de), b = paraMundo(...ev.para);
        await tween(260 + 60 * (Math.abs(ev.de[0] - ev.para[0]) + Math.abs(ev.de[1] - ev.para[1])), k => {
          const q = suave(k);
          u.g.position.lerpVectors(a, b, q);
          u.g.position.y = Math.sin(q * Math.PI) * .45;
        });
        return;
      }
      case 'efeito': {
        const c = cartas[ev.carta];
        registrar(`${NOME_LADO(ev.lado)} usou ${c.nome} (${c.jogador})`, ev.lado);
        mostrarCartaJogada(c, ev.lado);
        const cor = c.classe === 'vilao' ? 0xb02020 : 0xf2c14e;
        if (ev.alvo) { const p = posDe(ev.alvo); pilarDeLuz(p, cor); faiscas(p.clone().setY(1), cor); }
        await pausa(700);
        return;
      }
      case 'ataque': {
        const u = unidades.get(ev.unidade);
        if (!u) return;
        const ladoAlvo = u.lado === 'A' ? 'B' : 'A';
        const alvoPos = posDe(ev.alvo, ladoAlvo);
        await rolarD20(ev.d20, ev.resultado);
        const dist = u.g.position.distanceTo(alvoPos.clone().setY(0));
        if (dist > T * 1.5) {
          await projetil(u.g.position, alvoPos, u.lado === meuLado ? 0x7ab8ff : 0xff6a3d);
        } else {
          const ini = u.g.position.clone();
          const meio = ini.clone().lerp(alvoPos.clone().setY(0), .55);
          await tween(170, k => u.g.position.lerpVectors(ini, meio, suave(k)));
          faiscas(alvoPos.clone().setY(1), 0xffcc66);
          await tween(220, k => u.g.position.lerpVectors(meio, ini, suave(k)));
        }
        if (ev.resultado === 'erro') flutuar(alvoPos.clone().setY(1.6), 'Errou!', 'erro');
        return;
      }
      case 'revide': registrar('Revide!'); await pausa(120); return;
      case 'escudo': {
        const u = unidades.get(ev.unidade);
        if (u) { flutuar(u.g.position.clone().setY(1.7), 'Bloqueado!', 'escudo'); faiscas(u.g.position.clone().setY(1), 0x9cc4ff); u.st = Object.assign({}, u.st, { escudo: Math.max(0, (u.st.escudo || 1) - 1) }); desenharBarra(u); }
        await pausa(280);
        return;
      }
      case 'dano': {
        const u = unidades.get(ev.unidade);
        if (!u) return;
        u.vida = ev.vida;
        desenharBarra(u);
        flutuar(u.g.position.clone().setY(1.5), '-' + ev.n, 'dano');
        const x0 = u.carta.position.x;
        await tween(260, k => { u.carta.position.x = x0 + Math.sin(k * Math.PI * 6) * .08 * (1 - k); });
        u.carta.position.x = x0;
        return;
      }
      case 'caiu': {
        const u = unidades.get(ev.unidade);
        if (!u) return;
        registrar(`${cartas[u.cartaId]?.nome || 'Herói'} caiu`, ev.lado);
        faiscas(u.g.position.clone().setY(.8), 0x888888);
        await tween(480, k => { u.g.position.y = -suave(k) * 1.8; u.carta.material.opacity = 1 - k; });
        removerUnidade(ev.unidade);
        return;
      }
      case 'pressao':
        registrar(`Pressão no garrafão: ${ev.n} de dano`, ev.lado);
        flutuar(fortalezas[ev.lado === 'A' ? 'B' : 'A'].position.clone().setY(2.6), 'Pressão!', 'dano');
        await pausa(300);
        return;
      case 'fortaleza': {
        const f = fortalezas[ev.lado];
        flutuar(f.position.clone().setY(2.2), '-' + ev.n, 'dano grande');
        faiscas(f.position.clone().setY(1.2), 0xffaa55);
        const x0 = f.position.x;
        const el = $(ev.lado === pacote.eu ? '.lado-a .fort' : '.lado-b .fort');
        el.classList.remove('leva'); void el.offsetWidth; el.classList.add('leva');
        atualizarFortaleza(ev.lado, ev.vida);
        await tween(380, k => { f.position.x = x0 + Math.sin(k * Math.PI * 8) * .09 * (1 - k); });
        f.position.x = x0;
        return;
      }
      case 'estouro': {
        const meu = ev.lado === meuLado;
        registrar(`${NOME_LADO(ev.lado)} deixou o tempo estourar (${ev.n}/3)`, ev.lado);
        aviso(meu ? `Seu tempo acabou — o turno passou (${ev.n}/3; no 3º, derrota).` : `${NOME_LADO(ev.lado)} deixou o tempo estourar (${ev.n}/3).`, !meu);
        await pausa(500);
        return;
      }
      case 'reembaralhou': registrar(`${NOME_LADO(ev.lado)} embaralhou o descarte de volta`, ev.lado); return;
      case 'fim': return;
    }
  }

  async function rolarD20(n, resultado) {
    const el = $('#d20');
    el.className = 'd20';
    el.hidden = false;
    const b = $('b', el), s = $('small', el);
    s.textContent = '';
    for (let i = 0; i < 5; i++) { b.textContent = 1 + Math.floor(Math.random() * 20); await pausa(45); }
    b.textContent = n;
    el.classList.add(resultado);
    s.textContent = resultado === 'critico' ? 'Crítico!' : resultado === 'erro' ? 'Errou' : 'Acerto';
    await pausa(resultado === 'acerto' ? 300 : 560);
    el.hidden = true;
  }

  // ═════════════════════════ a HUD ════════════════════════════════
  function atualizarFortaleza(lado, vida) {
    const sufixo = lado === pacote.eu ? 'a' : 'b';
    $('#fort-' + sufixo).style.width = (vida / L.fortaleza * 100) + '%';
    $('#fort-' + sufixo + '-n').textContent = vida;
  }

  function desenharHud() {
    const e = pacote.estado, eu = pacote.eu, ele = eu === 'A' ? 'B' : 'A';
    const je = e.jogadores[eu], jo = e.jogadores[ele];
    $('#nome-a').textContent = je.nome; $('#nome-b').textContent = jo.nome;
    atualizarFortaleza(eu, je.fortaleza); atualizarFortaleza(ele, jo.fortaleza);
    $('#mao-b').textContent = jo.mao;
    $('#baralho-b').textContent = jo.baralho;
    $('#baralho-a').textContent = je.baralho;
    $('#turno-n').textContent = `Turno ${Math.ceil(e.turno / 2)} · ${e.turno}/${L.turnos_max}`;
    const minha = e.vez === eu && !e.fim;
    $('#turno-vez').textContent = minha ? 'Sua vez' : `Vez de ${jo.nome}`;
    $('#turno-vez').classList.toggle('dele', !minha);
    $('#energia').innerHTML = `<b>${je.energia}</b><div class="orbes">${Array.from({ length: 8 }, (_, i) =>
      `<i class="${i < je.energia ? 'on' : ''}" style="${i < je.energia_max ? '' : 'opacity:.25'}"></i>`).join('')}</div><small>energia</small>`;
    desenharMao();
    const nada = minha && !pacote.opcoes.cartas.some(c => c.pode) &&
      !Object.values(pacote.opcoes.unidades).some(u => u.mover.length || u.alvos.length);
    const f = (pacote.opcoes && pacote.opcoes.feito) || {};
    $('#acoes-turno').innerHTML = minha ? [['mover', 'bi-arrows-move', 'Movimento'], ['atacar', 'bi-lightning-fill', 'Ataque'], ['carta', 'bi-suit-spade-fill', 'Carta']]
      .map(([k, ic, nome]) => `<span class="${f[k] ? 'usada' : ''}" title="${nome}: ${f[k] ? 'já usado' : 'disponível'} neste turno"><i class="bi ${ic}"></i> ${nome}</span>`).join('') : '';
    $('#bt-passar').disabled = !minha || ocupado;
    $('#bt-passar').classList.toggle('vazio', nada);
    pintarCasas();
    if (!selecao) dica(minha ? 'Escolha uma carta da mão ou um herói seu.' : '');
  }

  function cartaHtml(c, i, op) {
    const sub = c.classe === 'heroi'
      ? `<div class="c-tipo">${h(L.arquetipos[c.tipo].nome)} · ${h(c.pos)}</div><div class="c-stats"><span class="a">⚔${c.atq}</span><span class="v">♥${c.vida}</span><span class="m">↔${c.mov} ◎${c.alc}</span></div>`
      : `<div class="c-sub">${h(c.desc)}</div>`;
    const classe = { heroi: 'Herói', ferreiro: 'Ferreiro', vilao: 'Vilão' }[c.classe];
    return `<div class="carta r-${c.rar} ${op && !op.pode ? 'nao' : ''} ${selecao && selecao.tipo === 'carta' && selecao.i === i ? 'sel' : ''}" data-i="${i}">
      <div class="c-bg"></div><img class="c-foto" src="${h(c.foto)}" alt="" loading="lazy">
      <div class="c-custo">${c.custo}</div><div class="c-classe ${c.classe}">${classe}</div>
      <div class="c-nome">${h(c.classe === 'heroi' ? c.nome : c.nome)}</div>${sub}</div>`;
  }

  let maoAnterior = [];
  function desenharMao() {
    const mao = pacote.estado.jogadores[pacote.eu].mao;
    $('#mao').innerHTML = mao.map((id, i) => cartaHtml(cartas[id], i, pacote.opcoes.cartas[i])).join('');
    // Carta nova na mão entra deslizando.
    [...$('#mao').children].forEach((el, i) => { if (maoAnterior[i] !== mao[i]) el.classList.add('entra'); });
    maoAnterior = mao.slice();
    mao.forEach(id => cartas[id] && imagem(cartas[id].foto));
    $('#mao').classList.toggle('baixa', !!(selecao && selecao.tipo === 'carta' && pacote.opcoes.cartas[selecao.i] && pacote.opcoes.cartas[selecao.i].alvo !== 'nenhum'));
  }

  function mostrarCartaJogada(c, lado) {
    const el = document.createElement('div');
    el.className = 'carta-jogada ' + (lado === pacote.eu ? 'minha' : 'dele');
    el.innerHTML = cartaHtml(c, -1, null);
    document.body.appendChild(el);
    setTimeout(() => el.remove(), 1500);
  }

  function registrar(txt, lado) {
    const log = $('#log');
    const d = document.createElement('div');
    d.className = lado === pacote.eu ? 'a' : lado ? 'b' : '';
    d.textContent = txt;
    log.appendChild(d);
    while (log.children.length > 12) log.firstChild.remove();
  }

  function dica(txt) { $('#dica').textContent = txt || ''; }
  let avisoTimer;
  function aviso(txt, ok) {
    const el = $('#aviso');
    el.className = 'aviso' + (ok ? ' ok' : '');
    el.textContent = txt;
    el.hidden = false;
    clearTimeout(avisoTimer);
    avisoTimer = setTimeout(() => { el.hidden = true; }, 2200);
  }
  function faixa(txt) {
    const el = $('#aviso');
    el.className = 'aviso turno';
    el.textContent = txt;
    el.hidden = false;
    clearTimeout(avisoTimer);
    avisoTimer = setTimeout(() => { el.hidden = true; }, 1100);
  }

  /** Detalhe do herói (ou carta) apontado. */
  function mostrarDetalhe(u) {
    const el = $('#detalhe');
    if (!u) { el.hidden = true; return; }
    const c = cartas[u.cartaId];
    const a = L.arquetipos[c.tipo];
    const st = u.st;
    const tags = [];
    if (st.escudo) tags.push(`🛡 Escudo ×${st.escudo}`);
    if (st.atordoado) tags.push('💫 Atordoado');
    if (st.preso) tags.push('⛓ Marcado');
    (st.maldicoes || []).forEach(m => tags.push(`☠ −${m.n} ATQ`));
    (st.equipamentos || []).forEach(q => cartas[q] && tags.push('⚒ ' + cartas[q].nome));
    el.innerHTML = `<h4>${h(c.nome)}</h4><div class="d-sub">${h(a.nome)} · ${h(c.time)} · ${u.lado === pacote.eu ? 'seu' : 'do rival'}</div>
      <div class="d-stats"><div><b>${st.atq ?? c.atq}</b><small>ATQ</small></div><div><b>${u.vida}/${u.vidaMax}</b><small>Vida</small></div>
      <div><b>${st.mov ?? c.mov}</b><small>Anda</small></div><div><b>${st.alc ?? c.alc}</b><small>Alcance</small></div></div>
      <div class="d-hab"><b>${h(a.hab)}:</b> ${h(a.hab_desc)}</div>${tags.length ? `<div class="d-tags">${tags.map(t => `<span>${h(t)}</span>`).join('')}</div>` : ''}`;
    el.hidden = false;
  }

  // ═══════════════════════ a interação ════════════════════════════
  let selecao = null;   // {tipo:'carta', i} | {tipo:'unidade', id}

  function pintarCasas() {
    for (let y = 0; y < ALT; y++) for (let x = 0; x < LARG; x++) {
      const c = casas[y][x];
      c.estado = null;
      c.luz.material.color.setHex(c.base.cor);
      c.luz.material.opacity = c.base.op;
    }
    if (!pacote || !selecao) return;
    const acende = (lista, cor, estado) => lista.forEach(([x, y]) => {
      const c = casas[y] && casas[y][x];
      if (!c) return;
      c.estado = estado;
      c.luz.material.color.setHex(cor);
    });
    if (selecao.tipo === 'carta') {
      const op = pacote.opcoes.cartas[selecao.i];
      if (op && op.alvo === 'casa') acende(op.casas, 0x8ad8ff, 'entrada');
      if (op && op.alvo === 'unidade') acende(op.unidades.map(id => { const d = pacote.estado.unidades.find(u => u.id === id); return [d.x, d.y]; }), 0xffc04a, 'alvo');
    } else {
      const op = pacote.opcoes.unidades[selecao.id];
      if (!op) return;
      acende(op.mover, 0x4ade80, 'mover');
      const alvos = op.alvos.filter(a => a !== 'fortaleza').map(id => { const d = pacote.estado.unidades.find(u => u.id === id); return [d.x, d.y]; });
      acende(alvos, 0xff3d3d, 'alvo');
      if (op.alvos.includes('fortaleza')) {
        const yF = pacote.eu === 'A' ? ALT - 1 : 0;
        acende([[2, yF], [3, yF], [4, yF]], 0xff3d3d, 'alvo');
      }
    }
  }

  function selecionar(s) {
    selecao = s;
    desenharMao();
    pintarCasas();
    if (!s) { dica(pacote.estado.vez === pacote.eu ? 'Escolha uma carta da mão ou um herói seu.' : ''); mostrarDetalhe(null); return; }
    if (s.tipo === 'carta') {
      const c = cartas[pacote.estado.jogadores[pacote.eu].mao[s.i]];
      const op = pacote.opcoes.cartas[s.i];
      dica(op.alvo === 'casa' ? `Escolha uma casa azul para ${c.nome} entrar.`
        : c.classe === 'ferreiro' ? `Escolha um herói seu para receber ${c.nome}.` : `Escolha o herói inimigo para ${c.nome}.`);
    } else {
      const op = pacote.opcoes.unidades[s.id];
      dica(op.mover.length || op.alvos.length ? 'Verde: para onde anda. Vermelho: quem ataca.' : 'Esse herói não pode mais agir neste turno.');
      mostrarDetalhe(unidades.get(s.id));
    }
  }

  $('#mao').addEventListener('click', e => {
    const el = e.target.closest('.carta');
    if (!el || ocupado || pacote.estado.vez !== pacote.eu || pacote.estado.fim) return;
    const i = Number(el.dataset.i);
    const op = pacote.opcoes.cartas[i];
    if (!op.pode) { aviso(op.motivo || 'Energia insuficiente.'); return; }
    if (selecao && selecao.tipo === 'carta' && selecao.i === i) { selecionar(null); return; }
    if (op.alvo === 'nenhum') { jogar({ tipo: 'carta', mao: i }); return; }
    selecionar({ tipo: 'carta', i });
  });

  /** O que está debaixo do ponteiro: casa, herói ou Fortaleza. */
  function apontado(e) {
    const r = $('#cena').getBoundingClientRect();
    ponteiro.set(((e.clientX - r.left) / r.width) * 2 - 1, -((e.clientY - r.top) / r.height) * 2 + 1);
    raycaster.setFromCamera(ponteiro, camera);
    const hits = raycaster.intersectObjects(cena.children, true);
    for (const hit of hits) {
      let o = hit.object;
      while (o && !o.userData.unidade && !o.userData.casa && !o.userData.fortaleza && o.parent) o = o.parent;
      if (!o) continue;
      if (o.userData.unidade) return { unidade: o.userData.unidade };
      if (o.userData.fortaleza) return { fortaleza: o.userData.fortaleza };
      if (o.userData.casa) return { casa: o.userData.casa };
    }
    return null;
  }

  function passarPor(e) {
    if (!pacote || !montada) return;
    const a = apontado(e);
    $('#cena').style.cursor = a && (a.unidade || a.fortaleza) ? 'pointer' : 'default';
    if (!selecao || selecao.tipo !== 'unidade') mostrarDetalhe(a && a.unidade ? unidades.get(a.unidade) : null);
  }

  function clicar(e) {
    if (!pacote || ocupado || pacote.estado.fim) return;
    const a = apontado(e);
    const minha = pacote.estado.vez === pacote.eu;
    if (!a) { selecionar(null); return; }
    const unidadeNaCasa = a.casa ? pacote.estado.unidades.find(u => u.x === a.casa[0] && u.y === a.casa[1]) : null;
    const alvoUnidade = a.unidade || (unidadeNaCasa && unidadeNaCasa.id);

    if (minha && selecao && selecao.tipo === 'carta') {
      const op = pacote.opcoes.cartas[selecao.i];
      if (op.alvo === 'casa' && a.casa && op.casas.some(c => c[0] === a.casa[0] && c[1] === a.casa[1])) {
        // ENTRADA OTIMISTA: a casa é válida (o servidor disse), então o herói
        // já sobe agora, enquanto a jogada viaja. Se o servidor recusar, some.
        const id = pacote.estado.jogadores[pacote.eu].mao[selecao.i];
        const c = cartas[id];
        const u = criarUnidade({ id: 'prov', dono: pacote.eu, x: a.casa[0], y: a.casa[1], vida: c.vida, vida_max: c.vida, carta: id, escudo: 0 }, c);
        surgir(u, a.casa[0], a.casa[1], pacote.eu);
        jogar({ tipo: 'carta', mao: selecao.i, x: a.casa[0], y: a.casa[1] });
        return;
      }
      if (op.alvo === 'unidade' && alvoUnidade && op.unidades.includes(alvoUnidade)) { jogar({ tipo: 'carta', mao: selecao.i, alvo: alvoUnidade }); return; }
    }
    if (minha && selecao && selecao.tipo === 'unidade') {
      const op = pacote.opcoes.unidades[selecao.id];
      const fortRival = a.fortaleza && a.fortaleza !== pacote.eu;
      const casaFort = a.casa && a.casa[1] === (pacote.eu === 'A' ? ALT - 1 : 0) && a.casa[0] >= 2 && a.casa[0] <= 4;
      if ((fortRival || casaFort) && op.alvos.includes('fortaleza')) { jogar({ tipo: 'atacar', unidade: selecao.id, alvo: 'fortaleza' }); return; }
      if (alvoUnidade && op.alvos.includes(alvoUnidade)) { jogar({ tipo: 'atacar', unidade: selecao.id, alvo: alvoUnidade }); return; }
      if (a.casa && op.mover.some(c => c[0] === a.casa[0] && c[1] === a.casa[1])) { jogar({ tipo: 'mover', unidade: selecao.id, x: a.casa[0], y: a.casa[1] }, true); return; }
    }
    if (alvoUnidade) {
      const d = pacote.estado.unidades.find(u => u.id === alvoUnidade);
      if (minha && d && d.dono === pacote.eu) { selecionar({ tipo: 'unidade', id: alvoUnidade }); return; }
      selecao = null; pintarCasas(); desenharMao();
      mostrarDetalhe(unidades.get(alvoUnidade));
      return;
    }
    selecionar(null);
  }

  /** Manda a jogada, anima o que voltou e põe a mesa no estado novo. */
  async function jogar(jogada, manterSelecao) {
    if (ocupado) return;
    ocupado = true;
    $('#bt-passar').disabled = true;
    const unidadeSel = manterSelecao && selecao && selecao.tipo === 'unidade' ? selecao.id : null;
    selecao = null;
    pintarCasas();
    const r = await apiJogo('agir', { jogada });
    if (!r.ok) {
      removerUnidade('prov');
      aviso(r.erro || 'Não deu.');
      if (r.estado) { pacote = r; Object.assign(cartas, r.cartas); }
      ocupado = false;
      desenharHud();
      return;
    }
    Object.assign(cartas, r.cartas);
    const eu = pacote.eu;
    pacote = Object.assign({}, pacote, { eu });
    await tocarEventos(r.eventos);
    pacote = r;
    sincronizar(r.estado, false);
    acertarRelogio(r);
    ocupado = false;
    desenharHud();
    if (r.estado.fim) { fimDeJogo(r); return; }
    // Depois de andar, o mesmo herói continua escolhido, pra atacar em seguida.
    if (unidadeSel && r.opcoes.unidades[unidadeSel] && r.opcoes.unidades[unidadeSel].alvos.length) selecionar({ tipo: 'unidade', id: unidadeSel });
  }

  $('#bt-passar').addEventListener('click', () => { if (!ocupado && pacote.estado.vez === pacote.eu) jogar({ tipo: 'passar' }); });
  document.addEventListener('keydown', e => { if (e.key === 'Escape') selecionar(null); });

  function fimDeJogo(r) {
    clearInterval(consulta);
    relogioFim = 0;
    const e = r.estado;
    const venci = e.vencedor === meuLado;
    const motivo = { fortaleza: 'A Fortaleza caiu.', desistencia: 'Houve desistência.', abandono: 'O tempo de turno estourou 3 vezes.',
      tempo: 'Acabaram os turnos: venceu a Fortaleza mais inteira.' }[e.motivo] || '';
    let premio = '<small>Treino: não vale moedas, ranking nem desafios.</small>';
    if (modo === 'online') {
      const res = r.resultado || {};
      premio = res.moedas ? `<b class="fim-moedas"><i class="bi bi-coin"></i> +${res.moedas} moedas</b>` : `<small>${h(res.motivo || '')}</small>`;
      (res.desafios || []).forEach(d => { premio += `<div class="fim-desafio"><i class="bi bi-flag-fill"></i> Desafio cumprido: ${h(d.nome)} · +${d.moedas}</div>`; });
    }
    $('#fim').innerHTML = `<div class="fim-caixa ${venci ? 'vitoria' : 'derrota'}"><h2>${venci ? 'Vitória' : 'Derrota'}</h2>
      <p>${h(motivo)}<br>${premio}</p>
      <div class="acoes">${modo === 'treino' ? '<button class="bt-sec" id="bt-denovo">Jogar de novo</button>' : ''}<button class="bt-sec" id="bt-menu">Voltar ao hub</button></div></div>`;
    $('#fim').hidden = false;
    if ($('#bt-denovo')) $('#bt-denovo').addEventListener('click', async () => {
      const n = await api('treino_novo', { nivel: nivelAtual });
      if (n.ok) entrarNoJogo(n, true);
    });
    $('#bt-menu').addEventListener('click', () => location.reload());
  }

  $('#bt-sair').addEventListener('click', async () => {
    if (modo === 'online') {
      if (!confirm('Desistir da partida? Conta como derrota.')) return;
      await apiJogo('agir', { jogada: { tipo: 'desistir' } });
      location.reload();
      return;
    }
    if (!confirm('Sair do treino? A partida termina.')) return;
    await api('sair');
    location.reload();
  });
  $('#bt-ajuda').addEventListener('click', () => { $('#ajuda').hidden = false; });
  $('#bt-fechar-ajuda').addEventListener('click', () => { $('#ajuda').hidden = true; });
})();
