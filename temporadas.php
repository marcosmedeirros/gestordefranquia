<?php
require_once __DIR__ . '/backend/auth.php';
requireAuth();
header('Location: /admin.php#temporadas');
exit;
