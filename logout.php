<?php
session_start();
require_once __DIR__ . '/backend/auth.php';
//vai pro login
destroyUserSession();
header('Location: /login.php');
exit;
