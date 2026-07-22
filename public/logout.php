<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
logout();
header('Location: ' . BASE . '/login.php');
exit;
