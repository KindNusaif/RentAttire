<?php
/*
 * Run from Command Prompt:
 * php create_admin.php "Admin" "User" "admin@example.com" "StrongPassword123!"
 * Delete this file after creating the first administrator.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found');
}
require_once __DIR__ . '/database.php';
if ($argc !== 5) {
    fwrite(STDERR, "Usage: php create_admin.php FirstName LastName Email Password\n");
    exit(1);
}
[$script, $first, $last, $email, $plainPassword] = $argv;
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($plainPassword) < 12) {
    fwrite(STDERR, "Use a valid email and password of at least 12 characters.\n");
    exit(1);
}
$stmt = $pdo->prepare("INSERT INTO users (first_name,last_name,email,password_hash,role,status) VALUES (?,?,?,?,'admin','active')");
$stmt->execute([$first, $last, strtolower($email), password_hash($plainPassword, PASSWORD_DEFAULT)]);
fwrite(STDOUT, "Administrator account created. Remove create_admin.php after use.\n");
