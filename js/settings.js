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
  };
  try {
    await api('user.php', { method: 'POST', body: JSON.stringify(payload) });
    alert('Perfil atualizado com sucesso.');
    window.location.reload();
  } catch (err) {
    alert(err.error || 'Erro ao atualizar perfil');
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
    photo_url: teamPhotoFile ? await convertToBase64(teamPhotoFile) : null,
  };
  try {
    await api('team.php', { method: 'PUT', body: JSON.stringify(payload) });
    alert('Time atualizado com sucesso.');
  } catch (err) {
    alert(err.error || 'Erro ao atualizar time');
  }
});
