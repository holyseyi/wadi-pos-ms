<?php
require_once __DIR__ . '/inc/functions.php';

if (isset($_SESSION['user'])) {
    header('Location: sales.php');
    exit;
} else {
    header('Location: login.php');
    exit;
}
?>