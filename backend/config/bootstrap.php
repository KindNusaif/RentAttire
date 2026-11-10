<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.use_strict_mode', '1');
    $isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
header('X-CSRF-Token: ' . $_SESSION['csrf_token']);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/database.php';

function jsonResponse(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function requestData(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return $_POST;
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : $_POST;
}

function requireMethod(string $method): void
{
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== strtoupper($method)) {
        jsonResponse(['success' => false, 'message' => 'Method not allowed.'], 405);
    }
}

function currentUser(): ?array
{
    return $_SESSION['user'] ?? null;
}

function requireLogin(): array
{
    $user = currentUser();
    if (!$user) {
        jsonResponse(['success' => false, 'message' => 'Please sign in first.'], 401);
    }
    return $user;
}

function requireRole(array $roles): array
{
    $user = requireLogin();
    if (!in_array($user['role'], $roles, true)) {
        jsonResponse(['success' => false, 'message' => 'You do not have permission to perform this action.'], 403);
    }
    global $pdo;
    $status = $pdo->prepare('SELECT status FROM users WHERE user_id=?');
    $status->execute([$user['id']]);
    $accountStatus = $status->fetchColumn();
    if ($accountStatus === false || $accountStatus === 'suspended' || ($accountStatus !== 'active' && $user['role'] !== 'vendor')) {
        jsonResponse(['success' => false, 'message' => 'This account cannot perform this action.'], 403);
    }
    return $user;
}

function cleanString(mixed $value, int $maxLength = 255): string
{
    $value = trim((string)($value ?? ''));
    return mb_substr($value, 0, $maxLength);
}

function requireVendorShop(PDO $pdo, int $vendorId, int $shopId, bool $mustBeApproved = true): array
{
    $stmt = $pdo->prepare('SELECT s.* FROM shops s JOIN users u ON u.user_id=s.vendor_id WHERE s.shop_id=? AND s.vendor_id=? AND u.status=?');
    $stmt->execute([$shopId, $vendorId, 'active']);
    $shop = $stmt->fetch();
    if (!$shop) {
        jsonResponse(['success' => false, 'message' => 'Shop not found or access denied.'], 404);
    }
    if ($mustBeApproved && $shop['status'] !== 'approved') {
        jsonResponse(['success' => false, 'message' => 'This shop must be approved and active before this action.'], 403);
    }
    return $shop;
}

function storeUploadedImage(array $file): string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) {
        throw new RuntimeException('The image upload failed.');
    }
    if (($file['size'] ?? 0) < 1 || $file['size'] > 5 * 1024 * 1024) {
        throw new RuntimeException('Each image must be smaller than 5 MB.');
    }
    $imageInfo = @getimagesize($file['tmp_name']);
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!$imageInfo || !isset($extensions[$mime])) {
        throw new RuntimeException('Only valid JPEG, PNG, and WebP images are allowed.');
    }
    $directory = dirname(__DIR__) . '/uploads';
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        throw new RuntimeException('The image upload directory is unavailable.');
    }
    $filename = bin2hex(random_bytes(20)) . '.' . $extensions[$mime];
    if (!move_uploaded_file($file['tmp_name'], $directory . '/' . $filename)) {
        throw new RuntimeException('The image could not be saved.');
    }
    return 'backend/uploads/' . $filename;
}

function availableRentalQuantity(PDO $pdo, int $productId, ?int $variantId, string $pickupDate, string $returnDate): int
{
    $inventory = $pdo->prepare(
        $variantId
            ? "SELECT stock FROM product_variants WHERE variant_id=? AND product_id=? AND status='active'"
            : "SELECT stock FROM products WHERE product_id=? AND status='active'"
    );
    $inventory->execute($variantId ? [$variantId, $productId] : [$productId]);
    $stock = $inventory->fetchColumn();
    if ($stock === false || (int)$stock < 1) {
        return 0;
    }

    $blocked = $pdo->prepare('SELECT 1 FROM product_unavailability WHERE product_id=? AND start_date<? AND end_date>? LIMIT 1');
    $blocked->execute([$productId, $returnDate, $pickupDate]);
    if ($blocked->fetchColumn()) {
        return 0;
    }

    $reserved = $pdo->prepare(
        "SELECT COALESCE(SUM(oi.quantity),0)
         FROM order_items oi JOIN orders o ON o.order_id=oi.order_id
         WHERE oi.product_id=? AND oi.variant_id <=> ?
           AND o.status<>'cancelled' AND oi.vendor_status NOT IN ('rejected','cancelled')
           AND oi.pickup_date<? AND oi.return_date>?"
    );
    $reserved->execute([$productId, $variantId, $returnDate, $pickupDate]);
    return max(0, (int)$stock - (int)$reserved->fetchColumn());
}

function normalizeProductVariants(array $variants, ?int $sizeTypeId): array
{
    if (count($variants) > 40) {
        jsonResponse(['success' => false, 'message' => 'A product can have at most 40 size options.'], 422);
    }
    $normalized = [];
    $labels = [];
    global $pdo;
    foreach ($variants as $variant) {
        if (!is_array($variant)) {
            jsonResponse(['success' => false, 'message' => 'Each size option must be valid.'], 422);
        }
        $label = cleanString($variant['variant_label'] ?? '', 160);
        $stock = filter_var($variant['stock'] ?? null, FILTER_VALIDATE_INT);
        $sizeOptionId = filter_var($variant['size_option_id'] ?? null, FILTER_VALIDATE_INT) ?: null;
        if ($label === '' || $stock === false || $stock < 0 || isset($labels[mb_strtolower($label)])) {
            jsonResponse(['success' => false, 'message' => 'Enter unique size labels and non-negative whole-number quantities.'], 422);
        }
        if ($sizeOptionId) {
            $option = $pdo->prepare('SELECT size_option_id FROM size_options WHERE size_option_id=? AND size_type_id=?');
            $option->execute([$sizeOptionId, $sizeTypeId]);
            if (!$sizeTypeId || !$option->fetchColumn()) {
                jsonResponse(['success' => false, 'message' => 'A selected size is not part of this product size type.'], 422);
            }
        }
        $measurements = $variant['custom_measurements'] ?? null;
        if (is_string($measurements) && $measurements !== '') {
            $measurements = json_decode($measurements, true);
            if ($measurements === null) {
                jsonResponse(['success' => false, 'message' => 'Size measurements must contain valid JSON.'], 422);
            }
        }
        if ($measurements !== null && !is_array($measurements)) {
            jsonResponse(['success' => false, 'message' => 'Size measurements must be a JSON object.'], 422);
        }
        $normalized[] = [
            'size_option_id' => $sizeOptionId,
            'variant_label' => $label,
            'sku' => cleanString($variant['sku'] ?? '', 60),
            'stock' => $stock,
            'price_override' => isset($variant['price_override']) && $variant['price_override'] !== '' ? filter_var($variant['price_override'], FILTER_VALIDATE_FLOAT) : null,
            'custom_measurements' => $measurements === null ? null : json_encode($measurements, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
        if ($normalized[array_key_last($normalized)]['price_override'] !== null && ($normalized[array_key_last($normalized)]['price_override'] === false || $normalized[array_key_last($normalized)]['price_override'] < 0)) {
            jsonResponse(['success' => false, 'message' => 'Size-specific price adjustments must be non-negative numbers.'], 422);
        }
        $labels[mb_strtolower($label)] = true;
    }
    return $normalized;
}
