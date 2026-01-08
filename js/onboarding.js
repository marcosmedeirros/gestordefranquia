let currentTeamId = null;
let userPhotoFile = null;
let teamPhotoFile = null;

const api = (path, options = {}) => fetch(`/api/${path}`, {
    headers: { 'Content-Type': 'application/json' },
    ...options,
}).then(async res => {
    const body = await res.json().catch(() => ({}));
    if (!res.ok) throw body;
    return body;
});

function nextStep(step) {
    // Hide all steps
    document.querySelectorAll('.step-content').forEach(el => el.classList.remove('active'));
    document.querySelectorAll('.step').forEach(el => el.classList.remove('active'));
    
    // Show target step
    document.getElementById(`step-${step}`).classList.add('active');
    document.getElementById(`step-indicator-${step}`).classList.add('active');
    
    // Mark previous steps as completed
    for (let i = 1; i < step; i++) {
        document.getElementById(`step-indicator-${i}`).classList.add('completed');
    }
    
    window.scrollTo(0, 0);
}

function prevStep(step) {
    nextStep(step);
}

async function saveTeamAndFinish() {
    const form = document.getElementById('form-team');
    const formData = new FormData(form);
    
    const data = {
        name: formData.get('name'),
        city: formData.get('city'),
        mascot: formData.get('mascot'),
        photo_url: teamPhotoFile ? await convertToBase64(teamPhotoFile) : null
    };
    
    if (!data.name || !data.city) {
        alert('Por favor, preencha o nome e cidade do time.');
        return;
    }
    
    try {
        const result = await api('team.php', {
            method: 'POST',
            body: JSON.stringify(data)
        });
        
        currentTeamId = result.team_id;
        
        // Redireciona para dashboard
        window.location.href = '/dashboard.php';
    } catch (err) {
        alert(err.error || 'Erro ao criar time');
    }
}

function convertToBase64(file) {
    return new Promise((resolve, reject) => {
        const reader = new FileReader();
        reader.onload = () => resolve(reader.result);
        reader.onerror = reject;
        reader.readAsDataURL(file);
    });
}

// User photo upload preview
document.getElementById('user-photo-upload')?.addEventListener('change', function(e) {
    const file = e.target.files[0];
    if (file) {
        userPhotoFile = file;
        const reader = new FileReader();
        reader.onload = function(event) {
            document.getElementById('user-photo-preview').src = event.target.result;
        };
        reader.readAsDataURL(file);
    }
});

// Team photo upload preview
document.getElementById('team-photo-upload')?.addEventListener('change', function(e) {
    const file = e.target.files[0];
    if (file) {
        teamPhotoFile = file;
        const reader = new FileReader();
        reader.onload = function(event) {
            document.getElementById('team-photo-preview').src = event.target.result;
        };
        reader.readAsDataURL(file);
    }
});
