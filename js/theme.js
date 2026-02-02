// theme.js - SIMPLES E FUNCIONAL

// 1. Quando a página carrega, aplica o tema salvo
document.addEventListener('DOMContentLoaded', function() {
    // Pega o tema salvo ou usa 'dark' como padrão
    const temaSalvo = localStorage.getItem('tema');
    const tema = temaSalvo || 'dark';
    
    // Aplica o tema
    document.documentElement.setAttribute('data-theme', tema);
    
    // Configura o switch se ele existir na página
    const switchElement = document.getElementById('themeSwitch');
    if (switchElement) {
        // Define o estado inicial do switch
        switchElement.checked = (tema === 'light');
        
        // Quando o switch for clicado, muda o tema
        switchElement.addEventListener('change', function() {
            const novoTema = this.checked ? 'light' : 'dark';
            
            // Salva no localStorage
            localStorage.setItem('tema', novoTema);
            
            // Aplica no site TODO
            document.documentElement.setAttribute('data-theme', novoTema);
            
            // Atualiza a cor do PWA
            const metaCor = document.querySelector('meta[name="theme-color"]');
            if (metaCor) {
                metaCor.content = novoTema === 'dark' ? '#0a0a0c' : '#f8f9fa';
            }
            
            console.log('Tema alterado para:', novoTema);
        });
    }
});