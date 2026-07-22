<?php
$host = $_SERVER['HTTP_HOST'] ?? '';
if (!preg_match('/^(localhost|127\.0\.0\.1)(:\d+)?$/', $host)) { http_response_code(404); exit('Not found'); }
require_once __DIR__ . '/backend/db.php';
session_start();
$pdo = db();
$st = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$st->execute([isset($_GET['id']) ? (int)$_GET['id'] : 2]);
$u = $st->fetch(PDO::FETCH_ASSOC);
if (!$u) { exit('nao encontrado'); }
foreach (['user_id'=>'id','user_name'=>'name','user_email'=>'email','user_type'=>'user_type','user_league'=>'league','user_photo'=>'photo_url','user_phone'=>'phone','user_accent_color'=>'accent_color','user_dashboard_shortcuts'=>'dashboard_shortcuts','user_approved'=>'approved'] as $k=>$c) { $_SESSION[$k] = $u[$c]; }
header('Location: ' . ($_GET['to'] ?? 'dashboard.php'));
