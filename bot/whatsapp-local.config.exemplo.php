<?php
/**
 * Modelo de configuração do worker local. Copie para
 * bot/whatsapp-local.config.php e preencha — o arquivo real fica fora do git
 * porque carrega o token do site e a chave da Evolution.
 */
return [
    // Site em produção
    'site_url'  => 'https://fbabrasil.com.br',
    // whatsapp_config.bot_token — gerado sozinho pelo ensureWhatsAppTables()
    'bot_token' => 'COLE_AQUI',

    // Evolution API local (container fba-evolution)
    'evolution_url'       => 'http://localhost:8081',
    'evolution_instancia' => 'COLE_AQUI',
    'evolution_api_key'   => 'COLE_AQUI',
];
