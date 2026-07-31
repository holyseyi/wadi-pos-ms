<?php
require_once __DIR__ . '/inc/functions.php';

if (isset($_SESSION['user']['username'])) {
    log_logout_event($_SESSION['user']['username']);
}

session_unset();
session_destroy();
header('Location: login.php');
exit;
