<?php
require_once __DIR__ . '/backend/auth.php';
require_once __DIR__ . '/backend/db.php';
require_once __DIR__ . '/backend/helpers.php';
requireAuth();

$user = getUserSession();
$pdo = db();

function isValidHexColor(?string $c): bool
{
    if ($c === null) return false;
    return (bool)preg_match('/^#[0-9a-fA-F]{6}$/', $c);
}

function slugifyTeamName(string $name): string
{
    $s = trim($name);
    if ($s === '') return '';
    $s = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s;
    $s = strtolower($s);
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    $s = trim($s ?? '', '-');
    return $s ?: '';
}

$stmtTeam = $pdo->prepare('SELECT * FROM teams WHERE user_id = ? LIMIT 1');
$stmtTeam->execute([$user['id']]);
$team = $stmtTeam->fetch() ?: null;

if (!$team) {
    http_response_code(404);
    echo "Time não encontrado.";
    exit;
}

$availableModules = [
    ['key' => 'roster',   'label' => 'Elenco (Jogadores)'],
    ['key' => 'lineup',   'label' => 'Escalação (Titulares)'],
    ['key' => 'titles',   'label' => 'Títulos'],
    ['key' => 'picks',    'label' => 'Picks'],
    ['key' => 'ranking',  'label' => 'Posição no Ranking'],
    ['key' => 'ai_avgs',  'label' => 'Médias IA (Idade/OVR)'],
    ['key' => 'trades',   'label' => 'Trades Feitas'],
];
$moduleKeys = array_map(fn($m) => $m['key'], $availableModules);

$flash = null;
$flashType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $publicEnabled = isset($_POST['public_enabled']) ? 1 : 0;
    $primary = trim((string)($_POST['public_primary_color'] ?? ''));
    $secondary = trim((string)($_POST['public_secondary_color'] ?? ''));

    $modulesRaw = (string)($_POST['public_modules'] ?? '');
    $decoded = json_decode($modulesRaw, true);
    $modules = is_array($decoded) ? $decoded : [];
    $modules = array_values(array_filter($modules, fn($k) => is_string($k) && in_array($k, $moduleKeys, true)));
    $modules = array_values(array_unique($modules));

    // Slug by team name, ensure unique.
    $baseSlug = slugifyTeamName((string)($team['name'] ?? ''));
    if ($baseSlug === '') $baseSlug = 'time-' . (int)$team['id'];
    $candidate = $baseSlug;
    $i = 2;
    $stmtExists = $pdo->prepare('SELECT id FROM teams WHERE public_slug = ? AND id <> ? LIMIT 1');
    while (true) {
        $stmtExists->execute([$candidate, (int)$team['id']]);
        if (!$stmtExists->fetchColumn()) break;
        $candidate = $baseSlug . '-' . $i;
        $i++;
    }

    $primaryToSave = isValidHexColor($primary) ? $primary : null;
    $secondaryToSave = isValidHexColor($secondary) ? $secondary : null;
    $modulesToSave = $modules ? json_encode($modules, JSON_UNESCAPED_SLASHES) : null;

    $stmtUpdate = $pdo->prepare('
        UPDATE teams
        SET public_enabled = ?,
            public_slug = ?,
            public_primary_color = ?,
            public_secondary_color = ?,
            public_modules = ?
        WHERE id = ?
        LIMIT 1
    ');
    $stmtUpdate->execute([
        $publicEnabled,
        $candidate,
        $primaryToSave,
        $secondaryToSave,
        $modulesToSave,
        (int)$team['id'],
    ]);

    $stmtTeam->execute([$user['id']]);
    $team = $stmtTeam->fetch() ?: $team;
    $flash = 'Configuração salva.';
}

$publicUrl = null;
if (!empty($team['public_slug'])) {
    $publicUrl = '/times/' . $team['public_slug'];
}

$existingModules = [];
if (!empty($team['public_modules'])) {
    $decodedExisting = json_decode((string)$team['public_modules'], true);
    if (is_array($decodedExisting)) {
        $existingModules = array_values(array_filter($decodedExisting, fn($k) => is_string($k) && in_array($k, $moduleKeys, true)));
    }
}
if (!$existingModules) {
    $existingModules = $moduleKeys; // default: all
}

$primaryValue = $team['public_primary_color'] ?: '#fc0025';
$secondaryValue = $team['public_secondary_color'] ?: '#ff2a44';
$isEnabled = (int)($team['public_enabled'] ?? 0) === 1;

$labelByKey = [];
foreach ($availableModules as $m) $labelByKey[$m['key']] = $m['label'];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <script>document.documentElement.dataset.theme = localStorage.getItem('fba-theme') || 'dark';</script>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title>Página do Time — FBA Manager</title>
    <?php include __DIR__ . '/includes/head-pwa.php'; ?>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="/css/styles.css">
    <style>
        .page-card { border: 1px solid var(--fba-border); background: var(--fba-panel); border-radius: 18px; padding: 18px; }
        .module-item { display:flex; gap:12px; align-items:center; padding:10px 12px; border:1px solid var(--fba-border); border-radius:14px; background: color-mix(in srgb, var(--fba-panel-2) 88%, transparent); }
        .module-handle { cursor: grab; opacity:.8; }
        .module-actions button { width: 34px; height: 34px; border-radius: 10px; }
        .module-item.dragging { opacity:.65; }
    </style>
</head>
<body>
    <div class="dashboard-sidebar"><?php include __DIR__ . '/includes/sidebar.php'; ?></div>

    <main class="dashboard-content">
        <div class="container-fluid" style="max-width: 1100px;">
            <div class="d-flex align-items-center justify-content-between mb-3">
                <div>
                    <h2 class="mb-1" style="font-weight:800;">Página Pública do Time</h2>
                    <div class="text-muted">Configure cores e módulos para a landing page pública.</div>
                </div>
                <div class="d-flex gap-2">
                    <?php if ($publicUrl): ?>
                        <a class="btn btn-outline-light" href="<?= htmlspecialchars($publicUrl) ?>" target="_blank" rel="noopener">Abrir Página</a>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($flash): ?>
                <div class="alert alert-<?= htmlspecialchars($flashType) ?>"><?= htmlspecialchars($flash) ?></div>
            <?php endif; ?>

            <form method="post" class="page-card">
                <div class="row g-3">
                    <div class="col-12">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="public_enabled" name="public_enabled" <?= $isEnabled ? 'checked' : '' ?>>
                            <label class="form-check-label" for="public_enabled">Página pública ativa</label>
                        </div>
                        <div class="text-muted" style="font-size:.9rem;">
                            Link: <span class="text-white"><?= htmlspecialchars($publicUrl ?: '/times/(slug)') ?></span>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Cor primária</label>
                        <div class="input-group">
                            <input type="color" class="form-control form-control-color" id="primaryPicker" value="<?= htmlspecialchars($primaryValue) ?>" title="Primária">
                            <input type="text" class="form-control" name="public_primary_color" id="primaryText" value="<?= htmlspecialchars($primaryValue) ?>" placeholder="#RRGGBB">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Cor secundária</label>
                        <div class="input-group">
                            <input type="color" class="form-control form-control-color" id="secondaryPicker" value="<?= htmlspecialchars($secondaryValue) ?>" title="Secundária">
                            <input type="text" class="form-control" name="public_secondary_color" id="secondaryText" value="<?= htmlspecialchars($secondaryValue) ?>" placeholder="#RRGGBB">
                        </div>
                    </div>

                    <div class="col-12">
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <label class="form-label mb-0">Módulos</label>
                            <div class="text-muted" style="font-size:.9rem;">Arraste para ordenar</div>
                        </div>

                        <input type="hidden" name="public_modules" id="public_modules" value="<?= htmlspecialchars(json_encode($existingModules, JSON_UNESCAPED_SLASHES)) ?>">

                        <div id="modulesList" class="d-grid gap-2">
                            <?php foreach ($existingModules as $k): $label = $labelByKey[$k] ?? $k; ?>
                                <div class="module-item" draggable="true" data-key="<?= htmlspecialchars($k) ?>">
                                    <div class="module-handle"><i class="bi bi-grip-vertical"></i></div>
                                    <div class="flex-grow-1">
                                        <div class="form-check m-0">
                                            <input class="form-check-input module-check" type="checkbox" checked id="mod_<?= htmlspecialchars($k) ?>">
                                            <label class="form-check-label" for="mod_<?= htmlspecialchars($k) ?>"><?= htmlspecialchars($label) ?></label>
                                        </div>
                                    </div>
                                    <div class="module-actions d-flex gap-1">
                                        <button type="button" class="btn btn-outline-light btn-sm move-up" title="Subir"><i class="bi bi-arrow-up"></i></button>
                                        <button type="button" class="btn btn-outline-light btn-sm move-down" title="Descer"><i class="bi bi-arrow-down"></i></button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <div class="mt-3">
                            <button type="button" class="btn btn-outline-light btn-sm" id="addAllModules">Adicionar módulos faltantes</button>
                        </div>
                    </div>

                    <div class="col-12 d-flex gap-2 justify-content-end">
                        <button type="submit" class="btn btn-danger">Salvar</button>
                    </div>
                </div>
            </form>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const allModules = <?= json_encode($availableModules, JSON_UNESCAPED_SLASHES) ?>;
        const modulesList = document.getElementById('modulesList');
        const modulesHidden = document.getElementById('public_modules');

        function syncColor(pickerId, textId) {
            const picker = document.getElementById(pickerId);
            const text = document.getElementById(textId);
            picker.addEventListener('input', () => { text.value = picker.value; });
            text.addEventListener('input', () => {
                if (/^#[0-9a-fA-F]{6}$/.test(text.value)) picker.value = text.value;
            });
        }
        syncColor('primaryPicker', 'primaryText');
        syncColor('secondaryPicker', 'secondaryText');

        function serializeModules() {
            const keys = Array.from(modulesList.querySelectorAll('.module-item'))
                .filter(el => el.querySelector('.module-check')?.checked)
                .map(el => el.getAttribute('data-key'))
                .filter(Boolean);
            modulesHidden.value = JSON.stringify(keys);
        }

        modulesList.addEventListener('change', (e) => {
            if (e.target && e.target.classList.contains('module-check')) serializeModules();
        });

        modulesList.addEventListener('click', (e) => {
            const btnUp = e.target.closest('.move-up');
            const btnDown = e.target.closest('.move-down');
            if (!btnUp && !btnDown) return;
            const item = e.target.closest('.module-item');
            if (!item) return;
            if (btnUp) {
                const prev = item.previousElementSibling;
                if (prev) modulesList.insertBefore(item, prev);
            } else {
                const next = item.nextElementSibling;
                if (next) modulesList.insertBefore(next, item);
            }
            serializeModules();
        });

        let dragEl = null;
        modulesList.addEventListener('dragstart', (e) => {
            const item = e.target.closest('.module-item');
            if (!item) return;
            dragEl = item;
            item.classList.add('dragging');
            e.dataTransfer.effectAllowed = 'move';
        });
        modulesList.addEventListener('dragend', () => {
            if (dragEl) dragEl.classList.remove('dragging');
            dragEl = null;
            serializeModules();
        });
        modulesList.addEventListener('dragover', (e) => {
            e.preventDefault();
            const item = e.target.closest('.module-item');
            if (!dragEl || !item || item === dragEl) return;
            const rect = item.getBoundingClientRect();
            const before = (e.clientY - rect.top) < rect.height / 2;
            modulesList.insertBefore(dragEl, before ? item : item.nextElementSibling);
        });

        document.getElementById('addAllModules').addEventListener('click', () => {
            const existing = new Set(Array.from(modulesList.querySelectorAll('.module-item')).map(el => el.getAttribute('data-key')));
            for (const m of allModules) {
                if (existing.has(m.key)) continue;
                const el = document.createElement('div');
                el.className = 'module-item';
                el.setAttribute('draggable', 'true');
                el.setAttribute('data-key', m.key);
                el.innerHTML = `
                    <div class="module-handle"><i class="bi bi-grip-vertical"></i></div>
                    <div class="flex-grow-1">
                        <div class="form-check m-0">
                            <input class="form-check-input module-check" type="checkbox" checked id="mod_${m.key}">
                            <label class="form-check-label" for="mod_${m.key}">${m.label}</label>
                        </div>
                    </div>
                    <div class="module-actions d-flex gap-1">
                        <button type="button" class="btn btn-outline-light btn-sm move-up" title="Subir"><i class="bi bi-arrow-up"></i></button>
                        <button type="button" class="btn btn-outline-light btn-sm move-down" title="Descer"><i class="bi bi-arrow-down"></i></button>
                    </div>
                `;
                modulesList.appendChild(el);
            }
            serializeModules();
        });

        serializeModules();
    </script>
</body>
</html>

