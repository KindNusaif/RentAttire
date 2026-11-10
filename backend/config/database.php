<?php
declare(strict_types=1);

/*
 * RentAttire database connection.
 * For a default WAMP installation: username=root, password="".
 * Change these values if your MySQL installation uses different credentials.
 */
$host = '127.0.0.1';
$dbname = 'rentattire_db';
$username = 'root';
$password = '';

try {
    $pdo = new PDO(
        "mysql:host={$host};dbname={$dbname};charset=utf8mb4",
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
} catch (PDOException $exception) {
    error_log('RentAttire database connection error: ' . $exception->getMessage());
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'Database connection failed. Check backend/config/database.php.']);
    exit;
}
