<?php
// config.php - Database configuration

// FOR HOSTING ENVIRONMENT (based on your credentials):
$host = 'localhost';
$dbname = 'u182463273_ozn';  // Fixed: Added user prefix to database name
$username = 'u182463273_ozn';
$password = 'K640dFQRk4^';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    // Test the connection
    $stmt = $pdo->query("SELECT 1");
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage() . "<br><br>
    <strong>Troubleshooting:</strong><br>
    1. Database name: $dbname<br>
    2. Username: $username<br>
    3. Host: $host<br>
    4. Check if database exists in your hosting panel<br>
    5. Verify credentials in hosting control panel");
}

session_start();
