<?php
require_once dirname(__DIR__) . '/config/app.php';
require_once ROOT . '/includes/auth.php';

start_session();
logout_user();
header('Location: ' . BASE_URL . '/dashboard/login.php');
exit;
