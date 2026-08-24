let profilePhotoFile = null;
let teamPhotoFile = null;

const api = async (path, options = {}) => {
  const doFetch = async (url) => {
    const res = await fetch(url, {
      headers: { 'Content-Type': 'application/json' },
      ...options,
    });
    let body = {};
    try { body = await res.json(); } catch { body = {}; }
    return { res, body };
  };

  let { res, body } = await doFetch(`/api/${path}`);
  if (res.status === 404) ({ res, body } = await doFetch(`/public/api/${path}`));
  if (!res.ok) throw body;
  return body;
};

function convertToBase64(file) {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => resolve(reader.result);
    reader.onerror = reject;
    reader.readAsDataURL(file);
  });
}

// Preview handlers
const profileUpload = document.getElementById('profile-photo-upload');
const teamUpload = document.getElementById('team-photo-upload');

profileUpload && profileUpload.addEventListener('change', (e) => {
  const file = e.target.files[0];
  if (!file) return;
  profilePhotoFile = file;
  const reader = new FileReader();
  reader.onload = (ev) => (document.getElementById('profile-photo-preview').src = ev.target.result);
  reader.readAsDataURL(file);
});

teamUpload && teamUpload.addEventListener('change', (e) => {
  const file = e.target.files[0];
  if (!file) return;
  teamPhotoFile = file;
  const reader = new FileReader();
  reader.onload = (ev) => (document.getElementById('team-photo-preview').src = ev.target.result);
  reader.readAsDataURL(file);
});

// Save profile
document.getElementById('btn-save-profile')?.addEventListener('click', async () => {
  const form = document.getElementById('form-profile');
  const fd = new FormData(form);
  const payload = {
    name: fd.get('name'),
    photo_url: profilePhotoFile ? await convertToBase64(profilePhotoFile) : null,
    phone: (fd.get('phone') || '').replace(/\D/g, ''),
    // Os três vão sempre, inclusive vazios: string vazia é como se limpa o
    // campo. Mandar só quando tem valor deixaria o GM sem jeito de apagar
    // uma cidade digitada errada.
    birth_date: fd.get('birth_date') || '',
    city: (fd.get('city') || '').trim(),
    state: fd.get('state') || '',
    // O país só vai quando o check está marcado. Mandar sempre guardaria
    // "Portugal" na conta de quem desmarcou e voltou pra São Paulo — o campo
    // fica escondido, mas o valor continua lá dentro dele.
    international: !!fd.get('international'),
    country: fd.get('international') ? (fd.get('country') || '').trim() : '',
  };
  try {
    await api('user.php', { method: 'POST', body: JSON.stringify(payload) });
    alert('Perfil atualizado com sucesso.');
    window.location.reload();
  } catch (err) {
    alert(err.error || 'Erro ao atualizar perfil');
  }
});

/* ── Moro fora do Brasil ────────────────────────────────────────────────
 * Estado e País dividem a mesma casinha: só um aparece por vez. Os dois
 * lado a lado deixariam a tela perguntando "qual estado?" pra quem acabou
 * de dizer que mora em Portugal.
 *
 * O disabled no campo escondido não é enfeite: campo escondido continua
 * sendo enviado no FormData, e sem isso a UF antiga viajaria junto com o
 * país e o servidor teria que escolher entre dois valores contraditórios.
 */
(function () {
  const chk = document.getElementById('chkInternacional');
  const fgEstado = document.getElementById('fgEstado');
  const fgPais = document.getElementById('fgPais');
  if (!chk || !fgEstado || !fgPais) return;

  const aplicar = () => {
    const fora = chk.checked;
    fgEstado.hidden = fora;
    fgPais.hidden = !fora;
    const sel = document.getElementById('selEstado');
    const inp = document.getElementById('inpPais');
    if (sel) sel.disabled = fora;
    if (inp) inp.disabled = !fora;
    if (fora && inp && !inp.value) inp.focus();
  };

  chk.addEventListener('change', aplicar);
  aplicar();
})();

// Aparência: cor de destaque + atalhos do dashboard
document.getElementById('btn-reset-accent-color')?.addEventListener('click', () => {
  const input = document.getElementById('accent-color-input');
  if (input) input.value = '#fc0025';
});

document.getElementById('btn-save-appearance')?.addEventListener('click', async () => {
  const shortcuts = Array.from(document.querySelectorAll('.shortcut-select'))
    .map(s => s.value)
    .filter((v, i, arr) => v && arr.indexOf(v) === i)
    .slice(0, 4);

  // Envia só o que é de aparência: assim não exige telefone (usuários sem
  // telefone cadastrado também conseguem salvar) nem grava edições de perfil
  // que o usuário deixou no formulário sem salvar.
  const payload = {
    accent_color: document.getElementById('accent-color-input')?.value || '',
    dashboard_shortcuts: shortcuts,
  };

  try {
    await api('user.php', { method: 'POST', body: JSON.stringify(payload) });
    alert('Aparência atualizada com sucesso.');
    window.location.reload();
  } catch (err) {
    alert(err.error || 'Erro ao atualizar aparência');
  }
});

// Notificações: manda a lista do que ficou DESMARCADO (o resto continua ligado).
document.getElementById('btn-save-notifs')?.addEventListener('click', async (e) => {
  const btn = e.currentTarget;
  const off = Array.from(document.querySelectorAll('.notif-check'))
    .filter(c => !c.checked)
    .map(c => c.dataset.key);

  const wa = document.getElementById('wa-optin');
  const payload = { notif_off: off };
  if (wa) payload.whatsapp_optin = wa.checked;

  btn.disabled = true;
  try {
    await api('user.php', { method: 'POST', body: JSON.stringify(payload) });
    const antes = btn.innerHTML;
    btn.innerHTML = '<i class="bi bi-check2"></i> Salvo!';
    setTimeout(() => { btn.innerHTML = antes; btn.disabled = false; }, 1800);
  } catch (err) {
    btn.disabled = false;
    alert(err.error || 'Erro ao salvar as notificações');
  }
});

// Change password
document.getElementById('btn-change-password')?.addEventListener('click', async () => {
  const form = document.getElementById('form-password');
  const fd = new FormData(form);
  const payload = {
    current_password: fd.get('current_password'),
    new_password: fd.get('new_password'),
  };
  try {
    await api('change-password.php', { method: 'POST', body: JSON.stringify(payload) });
    alert('Senha alterada com sucesso.');
    form.reset();
  } catch (err) {
    alert(err.error || 'Erro ao alterar senha');
  }
});

async function saveCustomHeader() {
  const useCustom = document.getElementById('use-custom-header')?.checked ? 1 : 0;
  const header    = document.getElementById('custom-header-input')?.value ?? '';
  try {
    await api('team.php', { method: 'PATCH', body: JSON.stringify({ custom_header: header, use_custom_header: useCustom }) });
  } catch (err) {
    alert(err.error || 'Erro ao salvar cabeçalho');
  }
}

function setCustomHeaderVisibility(checked) {
  const box = document.getElementById('custom-header-box');
  const off = document.getElementById('custom-header-off-msg');
  if (box) box.style.display = checked ? '' : 'none';
  if (off) off.style.display = checked ? 'none' : '';
}

let customHeaderToggleBusy = false;
async function handleCustomHeaderToggle(checked) {
  if (customHeaderToggleBusy) return;
  customHeaderToggleBusy = true;
  setCustomHeaderVisibility(checked);
  await saveCustomHeader();
  customHeaderToggleBusy = false;
}

const customHeaderToggle = document.getElementById('use-custom-header');
if (customHeaderToggle) {
  let lastTouchToggle = 0;
  // Custom header toggle — salva imediatamente ao marcar/desmarcar
  customHeaderToggle.addEventListener('change', (e) => handleCustomHeaderToggle(e.target.checked));
  // Fallback para alguns mobiles que nem sempre disparam change
  customHeaderToggle.addEventListener('touchend', (e) => {
    lastTouchToggle = Date.now();
    handleCustomHeaderToggle(e.target.checked);
  });
  customHeaderToggle.addEventListener('click', (e) => {
    if (Date.now() - lastTouchToggle < 500) return;
    handleCustomHeaderToggle(e.target.checked);
  });
}

let customHeaderSaveBusy = false;
async function handleCustomHeaderSave(btn) {
  if (customHeaderSaveBusy) return;
  customHeaderSaveBusy = true;
  const prevHtml = btn ? btn.innerHTML : null;
  if (btn) {
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm" style="margin-right:8px"></span>Salvando...';
  }
  await saveCustomHeader();
  alert('Cabecalho salvo com sucesso.');
  if (btn) {
    btn.disabled = false;
    if (prevHtml !== null) btn.innerHTML = prevHtml;
  }
  customHeaderSaveBusy = false;
}

// Save custom header
const customHeaderBtn = document.getElementById('btn-save-header');
if (customHeaderBtn) {
  let lastTouchSave = 0;
  customHeaderBtn.addEventListener('click', () => {
    if (Date.now() - lastTouchSave < 500) return;
    handleCustomHeaderSave(customHeaderBtn);
  });
  customHeaderBtn.addEventListener('touchend', () => {
    lastTouchSave = Date.now();
    handleCustomHeaderSave(customHeaderBtn);
  });
}

// Save team
document.getElementById('btn-save-team')?.addEventListener('click', async () => {
  const form = document.getElementById('form-team-settings');
  const fd = new FormData(form);
  const payload = {
    name: fd.get('name'),
    city: fd.get('city'),
    mascot: fd.get('mascot'),
    conference: fd.get('conference'),
    team_tag: fd.get('team_tag') || null,
    photo_url: teamPhotoFile ? await convertToBase64(teamPhotoFile) : null,
  };
  try {
    await api('team.php', { method: 'PUT', body: JSON.stringify(payload) });
    alert('Time atualizado com sucesso.');
  } catch (err) {
    alert(err.error || 'Erro ao atualizar time');
  }
});
