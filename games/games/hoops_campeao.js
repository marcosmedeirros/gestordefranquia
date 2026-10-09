/* FBA HOOPS — A COMEMORAÇÃO DO TÍTULO, EM 3D.
 *
 * Pedido do Victor (08/10/2026): quando o time for campeão, uma cena 3D com
 * o elenco que ele escolheu comemorando e levantando o troféu.
 *
 * Não existe modelo 3D de jogador nenhum (seria um estúdio por pessoa),
 * então quem sobe ao palco são AS CARTAS dele — as mesmas do draft, com
 * foto, OVR e nome — de pé num arco, pulando. A estrela (maior OVR) fica no
 * meio, e é ela que ergue o Larry O'Brien, que é modelado aqui mesmo com
 * geometria: bola, aro, rede, corpo e base.
 *
 * O Three.js só é baixado quando alguém é campeão — ~1 em cada 80
 * temporadas —, então o resto do jogo não paga por ele. Se o WebGL ou o
 * download falhar, a cena é pulada e a tela de resultado aparece normal:
 * enfeite que quebra não pode prender o jogador.
 */
(function () {
  'use strict';
  const THREE_URL = 'https://cdnjs.cloudflare.com/ajax/libs/three.js/r128/three.min.js';
  let carregando = null;

  function carregarThree() {
    if (window.THREE) return Promise.resolve();
    if (carregando) return carregando;
    carregando = new Promise((ok, falha) => {
      const s = document.createElement('script');
      s.src = THREE_URL;
      s.onload = ok;
      s.onerror = falha;
      document.head.appendChild(s);
    });
    return carregando;
  }

  const CORES = {
    bronze: ['#2b180c', '#7a4a26', '#c48a57', '#4a2c16', '#fff4e8'],
    prata:  ['#3a4049', '#9aa3ae', '#eef2f6', '#6d7580', '#11161c'],
    ouro:   ['#4a3304', '#c08f1c', '#ffe7a1', '#8f6510', '#231600'],
    icone:  ['#0d0820', '#2e1764', '#6d3fd1', '#d9ac3f', '#ffffff'],
  };

  /* Doze fotos pedidas de uma vez: um servidor que atende uma conexão por
     vez (o PHP de desenvolvimento) ou um CDN que segura rajada derruba uma
     ou outra. Mais duas tentativas, espaçadas, e só então a carta sobe ao
     palco sem foto. */
  function carregarImagem(url, tentativa = 0) {
    return new Promise(ok => {
      if (!url) return ok(null);
      const img = new Image();
      img.crossOrigin = 'anonymous';
      img.onload = () => ok(img);
      img.onerror = () => {
        if (tentativa >= 2) return ok(null);
        setTimeout(() => carregarImagem(url, tentativa + 1).then(ok), 400 * (tentativa + 1));
      };
      img.src = url;
    });
  }

  function retanguloArredondado(g, x, y, w, h, r) {
    g.beginPath();
    g.moveTo(x + r, y); g.arcTo(x + w, y, x + w, y + h, r); g.arcTo(x + w, y + h, x, y + h, r);
    g.arcTo(x, y + h, x, y, r); g.arcTo(x, y, x + w, y, r); g.closePath();
  }

  /** Desenha a carta num canvas — é a textura que vai pro palco. */
  async function texturaDaCarta(c) {
    const W = 300, Hc = 425;
    const cv = document.createElement('canvas');
    cv.width = W; cv.height = Hc;
    const g = cv.getContext('2d');
    const [a, b, m, d, txt] = CORES[c.rar] || CORES.prata;
    const gr = g.createLinearGradient(0, 0, W, Hc);
    gr.addColorStop(0, a); gr.addColorStop(.32, b); gr.addColorStop(.52, m); gr.addColorStop(1, d);
    retanguloArredondado(g, 4, 4, W - 8, Hc - 8, 26);
    g.fillStyle = gr; g.fill();
    g.lineWidth = 5; g.strokeStyle = 'rgba(255,255,255,.75)'; g.stroke();

    const foto = await carregarImagem(c.foto);
    g.save();
    retanguloArredondado(g, 4, 4, W - 8, Hc - 8, 26); g.clip();
    if (foto) {
      const fw = 250, fh = fw * foto.height / foto.width;
      g.drawImage(foto, W - fw + 10, 22, fw, fh);
    }
    g.restore();

    g.fillStyle = txt;
    g.font = '700 74px Oswald, Impact, sans-serif';
    g.fillText(String(c.ovr), 22, 86);
    g.font = '700 30px Oswald, Impact, sans-serif';
    g.fillText(c.pos, 26, 122);

    g.fillStyle = 'rgba(0,0,0,.22)';
    retanguloArredondado(g, 18, 300, W - 36, 100, 18); g.fill();
    g.fillStyle = txt;
    g.textAlign = 'center';
    let nome = c.nome.toUpperCase();
    let tam = 34;
    g.font = `700 ${tam}px Oswald, Impact, sans-serif`;
    while (g.measureText(nome).width > W - 50 && tam > 18) { tam -= 2; g.font = `700 ${tam}px Oswald, Impact, sans-serif`; }
    g.fillText(nome, W / 2, 342);
    g.font = '600 20px Montserrat, sans-serif';
    g.globalAlpha = .8;
    g.fillText(c.time || '', W / 2, 376);
    g.globalAlpha = 1;

    const t = new THREE.CanvasTexture(cv);
    t.anisotropy = 4;
    t.encoding = THREE.sRGBEncoding;
    return t;
  }

  /** Um céu de estúdio pintado à mão, só pra o ouro ter o que refletir. */
  function ambienteDeEstudio(renderer) {
    const cv = document.createElement('canvas');
    cv.width = 512; cv.height = 256;
    const g = cv.getContext('2d');
    const fundo = g.createLinearGradient(0, 0, 0, 256);
    fundo.addColorStop(0, '#2a2030'); fundo.addColorStop(.5, '#0d0b12'); fundo.addColorStop(1, '#050507');
    g.fillStyle = fundo; g.fillRect(0, 0, 512, 256);
    const luz = (x, y, r, cor) => {
      const rg = g.createRadialGradient(x, y, 0, x, y, r);
      rg.addColorStop(0, cor); rg.addColorStop(1, 'rgba(0,0,0,0)');
      g.fillStyle = rg; g.fillRect(0, 0, 512, 256);
    };
    luz(100, 50, 70, 'rgba(255,240,210,1)'); luz(300, 40, 90, 'rgba(255,200,120,.95)');
    luz(450, 70, 60, 'rgba(255,255,255,.9)'); luz(220, 180, 120, 'rgba(255,120,40,.35)');
    const tex = new THREE.CanvasTexture(cv);
    tex.mapping = THREE.EquirectangularReflectionMapping;
    tex.encoding = THREE.sRGBEncoding;
    const pm = new THREE.PMREMGenerator(renderer);
    const env = pm.fromEquirectangular(tex).texture;
    tex.dispose(); pm.dispose();
    return env;
  }

  /** O Larry O'Brien: base, corpo afunilado, rede, aro e a bola. */
  function trofeu() {
    const ouro = new THREE.MeshStandardMaterial({ color: 0xf2c14e, metalness: 1, roughness: .22 });
    const escuro = new THREE.MeshStandardMaterial({ color: 0x1a1a1f, metalness: .6, roughness: .35 });
    const grupo = new THREE.Group();

    const base = new THREE.Mesh(new THREE.CylinderGeometry(.42, .5, .34, 48), escuro);
    base.position.y = .17; grupo.add(base);
    const friso = new THREE.Mesh(new THREE.CylinderGeometry(.43, .43, .05, 48), ouro);
    friso.position.y = .33; grupo.add(friso);

    // O corpo: um vaso afunilado (perfil girado).
    const perfil = [[.0, 0], [.3, 0], [.28, .05], [.14, .2], [.09, .55], [.08, .95], [.12, 1.05], [.0, 1.05]]
      .map(([x, y]) => new THREE.Vector2(x, y));
    const corpo = new THREE.Mesh(new THREE.LatheGeometry(perfil, 48), ouro);
    corpo.position.y = .35; grupo.add(corpo);

    // A rede: um tronco de cone aberto, em malha.
    const rede = new THREE.Mesh(new THREE.CylinderGeometry(.27, .16, .32, 16, 4, true),
      new THREE.MeshStandardMaterial({ color: 0xf2c14e, metalness: 1, roughness: .3, wireframe: true }));
    rede.position.y = 1.55; grupo.add(rede);
    const aro = new THREE.Mesh(new THREE.TorusGeometry(.27, .025, 12, 48), ouro);
    aro.rotation.x = Math.PI / 2; aro.position.y = 1.71; grupo.add(aro);
    // A bola, encostada no aro, quase entrando.
    const bola = new THREE.Mesh(new THREE.SphereGeometry(.26, 48, 32), ouro);
    bola.position.set(.05, 1.93, 0); grupo.add(bola);
    for (const r of [0, Math.PI / 2]) { // os gomos da bola
      const gomo = new THREE.Mesh(new THREE.TorusGeometry(.262, .008, 6, 64), escuro);
      gomo.rotation.y = r; gomo.position.copy(bola.position); grupo.add(gomo);
    }
    // haste ligando o corpo à rede
    const haste = new THREE.Mesh(new THREE.CylinderGeometry(.06, .08, .16, 24), ouro);
    haste.position.y = 1.43; grupo.add(haste);
    grupo.traverse(o => { if (o.isMesh) o.castShadow = true; });
    return grupo;
  }

  const ESCALA_ESTRELA = 1.12;

  async function abrir(R, opcoes = {}) {
    const fim = opcoes.aoFechar || (() => {});
    try { await carregarThree(); } catch (e) { fim(); return; }

    const tela = document.createElement('div');
    tela.className = 'celebra';
    tela.innerHTML = `<canvas></canvas>
      <div class="cel-texto"><small>Campeões da NBA</small><h2></h2><p></p></div>
      <button class="cel-fechar btn-simular"><i class="bi bi-arrow-right-circle-fill"></i><span>Continuar</span></button>`;
    $q('h2', tela).textContent = R.times.EU[0];
    $q('p', tela).textContent = `${R.v}-${R.d} na temporada regular · ${R.forca} de força`;
    document.body.appendChild(tela);
    document.body.style.overflow = 'hidden';

    let renderer;
    try {
      renderer = new THREE.WebGLRenderer({ canvas: $q('canvas', tela), antialias: true });
    } catch (e) { tela.remove(); document.body.style.overflow = ''; fim(); return; }
    renderer.setPixelRatio(Math.min(2, window.devicePixelRatio || 1));
    renderer.outputEncoding = THREE.sRGBEncoding;
    renderer.toneMapping = THREE.ACESFilmicToneMapping;
    renderer.toneMappingExposure = 1.1;
    renderer.shadowMap.enabled = true;

    const cena = new THREE.Scene();
    cena.background = new THREE.Color(0x07070b);
    cena.fog = new THREE.Fog(0x07070b, 14, 34);
    cena.environment = ambienteDeEstudio(renderer);
    const camera = new THREE.PerspectiveCamera(42, 1, .1, 100);

    // luzes: um ambiente baixo e três holofotes de cima
    cena.add(new THREE.HemisphereLight(0xffe2c0, 0x101018, .45));
    const holofotes = [[0xffffff, -5, 9, 4, 1.4], [0xffa040, 6, 8, 2, 1.2], [0xffd27a, 0, 10, -4, .9]].map(([cor, x, y, z, i]) => {
      const s = new THREE.SpotLight(cor, i, 40, .45, .5, 1);
      s.position.set(x, y, z); s.castShadow = true; s.shadow.mapSize.set(1024, 1024);
      cena.add(s); cena.add(s.target);
      return s;
    });

    // o palco
    const palco = new THREE.Mesh(new THREE.CylinderGeometry(6.2, 6.5, .4, 96),
      new THREE.MeshStandardMaterial({ color: 0x14161f, metalness: .4, roughness: .35 }));
    palco.position.y = -.2; palco.receiveShadow = true; cena.add(palco);
    const anel = new THREE.Mesh(new THREE.TorusGeometry(6.25, .05, 12, 160),
      new THREE.MeshBasicMaterial({ color: 0xff7a18 }));
    anel.rotation.x = Math.PI / 2; anel.position.y = .01; cena.add(anel);
    const chao = new THREE.Mesh(new THREE.CircleGeometry(40, 64), new THREE.MeshStandardMaterial({ color: 0x08080c, roughness: .9 }));
    chao.rotation.x = -Math.PI / 2; chao.position.y = -.4; chao.receiveShadow = true; cena.add(chao);

    // o elenco: a estrela no meio, os outros num arco atrás
    const elenco = R.elenco.filter(Boolean);
    const estrela = elenco.reduce((a, b) => (b.ovr > a.ovr ? b : a), elenco[0]);
    const outros = elenco.filter(c => c !== estrela);
    const cartas = [];
    const geo = new THREE.PlaneGeometry(1.5, 2.125);
    const montar = async (c, x, z, escala) => {
      const mat = new THREE.MeshStandardMaterial({ map: await texturaDaCarta(c), roughness: .45, metalness: .15, side: THREE.DoubleSide });
      const m = new THREE.Mesh(geo, mat);
      m.scale.setScalar(escala);
      m.position.set(x, 1.1 * escala + .02, z);
      m.castShadow = true;
      cena.add(m);
      cartas.push({ m, base: m.position.y, fase: Math.random() * Math.PI * 2, vel: 3.2 + Math.random() * 1.6, estrela: c === estrela });
    };
    await Promise.all([
      montar(estrela, 0, .9, ESCALA_ESTRELA),
      ...outros.map((c, i) => {
        const ang = Math.PI * (.08 + .84 * (i / Math.max(1, outros.length - 1)));
        // duas fileiras alternadas: ninguém some atrás do vizinho
        const raio = i % 2 ? 5.6 : 4.6;
        return montar(c, Math.cos(ang) * raio, -Math.sin(ang) * (raio * .62) - .4, .92);
      }),
    ]);
    const cartaEstrela = cartas.find(c => c.estrela);

    // o troféu, que começa na altura do peito da estrela e sobe
    const taca = trofeu();
    taca.scale.setScalar(1.05);
    cena.add(taca);
    const luzTaca = new THREE.PointLight(0xffd27a, 0, 8);
    cena.add(luzTaca);

    // confete
    const N = 700;
    const conf = new THREE.InstancedMesh(new THREE.PlaneGeometry(.09, .16),
      new THREE.MeshBasicMaterial({ side: THREE.DoubleSide, vertexColors: false }), N);
    const paleta = [0xf5c518, 0xff7a18, 0x22d3ee, 0xffffff, 0xfc0025, 0x7c3aed].map(c => new THREE.Color(c));
    const parts = [];
    const tmp = new THREE.Object3D();
    for (let i = 0; i < N; i++) {
      parts.push({ x: (Math.random() - .5) * 16, y: 6 + Math.random() * 12, z: (Math.random() - .5) * 12,
        vy: .9 + Math.random() * 1.4, rx: Math.random() * 6, ry: Math.random() * 6, gx: Math.random() * 4 + 1 });
      conf.setColorAt(i, paleta[i % paleta.length]);
    }
    cena.add(conf);

    function tamanho() {
      const w = tela.clientWidth, h = tela.clientHeight;
      renderer.setSize(w, h, false);
      camera.aspect = w / h;
      camera.fov = w / h < .8 ? 58 : 42; // em pé (celular), a câmera abre mais
      camera.updateProjectionMatrix();
    }
    tamanho();
    window.addEventListener('resize', tamanho);

    const t0 = performance.now();
    let rodando = true;
    let anterior = t0;
    const suave = x => (x <= 0 ? 0 : x >= 1 ? 1 : x * x * (3 - 2 * x));

    function quadro(agora) {
      if (!rodando) return;
      const t = (agora - t0) / 1000;
      const dt = Math.min(.05, (agora - anterior) / 1000);
      anterior = agora;

      // câmera: chega de longe e depois gira devagar em volta do palco
      const chegada = suave(t / 3.2);
      const ang = .35 * Math.sin(t * .22) + (1 - chegada) * .9;
      // Em pé (celular), a câmera recua pra o arco inteiro caber na largura.
      const dist = (13.5 - 3.9 * chegada) * (camera.aspect < .8 ? 1.45 : 1);
      camera.position.set(Math.sin(ang) * dist, 2.8 + 2.4 * (1 - chegada), Math.cos(ang) * dist);
      camera.lookAt(0, 2.75, 0);

      // as cartas pulam (a estrela pula menos: está segurando a taça)
      for (const c of cartas) {
        const amp = c.estrela ? .12 : .32;
        const pulo = Math.max(0, Math.sin(t * c.vel + c.fase)) * amp;
        c.m.position.y = c.base + pulo;
        c.m.rotation.z = Math.sin(t * c.vel * .5 + c.fase) * (c.estrela ? .03 : .08);
        c.m.lookAt(camera.position.x, c.m.position.y, camera.position.z);
      }

      // A taça sai de TRÁS da carta da estrela e sobe até ficar acima dela,
      // a partir de 2,4s — é a estrela levantando o troféu.
      const ergue = suave((t - 2.4) / 1.6);
      const e = cartaEstrela.m.position;
      const topoCarta = e.y + 1.06 * ESCALA_ESTRELA;
      taca.position.set(e.x, (e.y - 1.2) + ergue * (topoCarta - .15 - (e.y - 1.2)), e.z - .12);
      taca.rotation.y = t * .6;
      luzTaca.position.set(e.x, taca.position.y + 1.2, e.z + 1);
      luzTaca.intensity = ergue * 2.2 + Math.max(0, Math.sin(t * 3)) * .4 * ergue;
      holofotes.forEach((s, i) => s.target.position.set(Math.sin(t * .5 + i * 2) * 1.5, 1, Math.cos(t * .4 + i) * 1.2));

      // confete caindo e girando; quem chega no chão volta pro alto
      for (let i = 0; i < N; i++) {
        const p = parts[i];
        p.y -= p.vy * dt;
        if (p.y < -.3) { p.y = 8 + Math.random() * 4; }
        tmp.position.set(p.x + Math.sin(t * p.gx + i) * .4, p.y, p.z);
        tmp.rotation.set(t * p.rx, t * p.ry, 0);
        tmp.updateMatrix();
        conf.setMatrixAt(i, tmp.matrix);
      }
      conf.instanceMatrix.needsUpdate = true;

      if (t > 3.4) tela.classList.add('texto');
      renderer.render(cena, camera);
      requestAnimationFrame(quadro);
    }
    requestAnimationFrame(quadro);

    $q('.cel-fechar', tela).addEventListener('click', () => {
      rodando = false;
      window.removeEventListener('resize', tamanho);
      renderer.dispose();
      tela.classList.add('sai');
      setTimeout(() => { tela.remove(); document.body.style.overflow = ''; fim(); }, 350);
    });
  }

  function $q(s, el) { return el.querySelector(s); }

  window.HoopsCampeao = { abrir };
})();
