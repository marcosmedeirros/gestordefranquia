<?php
session_start();
require_once __DIR__ . '/backend/auth.php';

destroyUserSession();
header('Location: /login.php');
exit;
