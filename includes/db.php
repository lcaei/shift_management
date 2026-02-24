<?php
/**
 * Database connection using PDO.
 * Adjust the credentials to match your environment.
 */
$db_host = 'localhost';          // usually localhost
$db_name = 'roaster';    // database name
$db_user = 'root';                // your MySQL username
$db_pass = '';                    // your MySQL password

try {
    $dsn = "mysql:host=$db_host;dbname=$db_name;charset=utf8mb4";
    $pdo = new PDO($dsn, $db_user, $db_pass);
    // Set PDO error mode to exception
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    // Set default fetch mode to associative array
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // In production, log this error instead of displaying it
    die("Database connection failed: " . $e->getMessage());
}
?>