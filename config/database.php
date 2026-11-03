<?php
// Database configuration - update credentials for your environment
define('DB_HOST', 'localhost');
define('DB_NAME', 'vscms');
define('DB_USER', 'root');
define('DB_PASS', '');

define('TAX_RATE', 10);          // Tax percentage applied on invoices
define('CURRENCY', 'Rs.');       // Currency symbol
define('COMPANY_NAME', 'A S Motors');

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
         PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    die('Database connection failed: ' . htmlspecialchars($e->getMessage()));
}
