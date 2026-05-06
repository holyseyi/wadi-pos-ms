<?php
require_once __DIR__ . '/../inc/functions.php';
require_login();

header('Content-Type: application/json');

$products = load_products();
echo json_encode($products);
?>