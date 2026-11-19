<?php
require_once __DIR__ . '/includes/bootstrap.php';
mat_logout();
mat_redirect(MAT_URL . '/login.php?out=1');
