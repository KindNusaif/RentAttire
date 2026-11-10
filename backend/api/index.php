<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$action = cleanString($_GET['action'] ?? '', 60);
$data = requestData();
$csrfExemptActions = ['register','vendor-register','login'];
if (in_array($method, ['POST','PUT','PATCH','DELETE'], true) && !in_array($action, $csrfExemptActions, true)) {
    $csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!is_string($csrfToken) || !hash_equals((string)$_SESSION['csrf_token'], $csrfToken)) {
        jsonResponse(['success' => false, 'message' => 'Security token expired. Refresh the page and try again.'], 403);
    }
}

try {
    switch ($action) {
        case 'health':
            jsonResponse(['success' => true, 'message' => 'RentAttire API is running.']);

        case 'me':
            jsonResponse(['success' => true, 'user' => currentUser()]);

        case 'csrf':
            jsonResponse(['success' => true]);

        case 'register':
            requireMethod('POST');
            $firstName = cleanString($data['first_name'] ?? '', 80);
            $lastName = cleanString($data['last_name'] ?? '', 80);
            $email = strtolower(cleanString($data['email'] ?? '', 190));
            $phone = cleanString($data['phone'] ?? '', 30);
            $password = (string)($data['password'] ?? '');
            if ($firstName === '' || $lastName === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8) {
                jsonResponse(['success' => false, 'message' => 'Enter your name, a valid email and a password of at least 8 characters.'], 422);
            }
            $stmt = $pdo->prepare("INSERT INTO users (first_name,last_name,email,phone,password_hash,role) VALUES (?,?,?,?,?,'customer')");
            try {
                $stmt->execute([$firstName, $lastName, $email, $phone, password_hash($password, PASSWORD_DEFAULT)]);
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') jsonResponse(['success' => false, 'message' => 'An account with this email already exists.'], 409);
                throw $e;
            }
            session_regenerate_id(true);
            $_SESSION['user'] = ['id' => (int)$pdo->lastInsertId(), 'name' => $firstName . ' ' . $lastName, 'email' => $email, 'role' => 'customer'];
            jsonResponse(['success' => true, 'message' => 'Registration successful.', 'user' => $_SESSION['user']], 201);

        case 'vendor-register':
            requireMethod('POST');
            $shopName = cleanString($data['shop_name'] ?? '', 150);
            $ownerName = cleanString($data['owner_name'] ?? '', 150);
            $email = strtolower(cleanString($data['email'] ?? '', 190));
            $phone = cleanString($data['phone'] ?? '', 30);
            $shopEmail = strtolower(cleanString($data['shop_email'] ?? $email, 190));
            $address = cleanString($data['address'] ?? '', 255);
            $description = cleanString($data['description'] ?? '', 3000);
            $password = (string)($data['password'] ?? '');
            if ($shopName === '' || $ownerName === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || !filter_var($shopEmail, FILTER_VALIDATE_EMAIL) || strlen($password) < 8) {
                jsonResponse(['success' => false, 'message' => 'Complete the required fields, use valid email addresses, and choose a password of at least 8 characters.'], 422);
            }
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare("INSERT INTO users (first_name,last_name,email,phone,password_hash,role,status) VALUES (?,?,?,?,?,'vendor','pending')");
                $parts = preg_split('/\s+/', $ownerName, 2);
                $stmt->execute([$parts[0] ?? $ownerName, $parts[1] ?? '', $email, $phone, password_hash($password, PASSWORD_DEFAULT)]);
                $userId = (int)$pdo->lastInsertId();
                $shop = $pdo->prepare("INSERT INTO shops (vendor_id,shop_name,description,address,phone,email,status) VALUES (?,?,?,?,?,?, 'pending')");
                $shop->execute([$userId, $shopName, $description, $address, $phone, $shopEmail]);
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                if ($e instanceof PDOException && $e->getCode() === '23000') jsonResponse(['success' => false, 'message' => 'An account with this email already exists.'], 409);
                throw $e;
            }
            jsonResponse(['success' => true, 'message' => 'Vendor application submitted. Awaiting admin approval.'], 201);

        case 'login':
            requireMethod('POST');
            $email = strtolower(cleanString($data['email'] ?? '', 190));
            $password = (string)($data['password'] ?? '');
            $stmt = $pdo->prepare("SELECT user_id,first_name,last_name,email,password_hash,role,status FROM users WHERE email=? LIMIT 1");
            $stmt->execute([$email]);
            $user = $stmt->fetch();
            $accountCanSignIn = $user && ($user['status'] === 'active' || ($user['role'] === 'vendor' && $user['status'] === 'pending'));
            if (!$user || !password_verify($password, $user['password_hash']) || !$accountCanSignIn) {
                jsonResponse(['success' => false, 'message' => 'Invalid email or password, or account not active.'], 401);
            }
            session_regenerate_id(true);
            $_SESSION['user'] = ['id' => (int)$user['user_id'], 'name' => trim($user['first_name'] . ' ' . $user['last_name']), 'email' => $user['email'], 'role' => $user['role'], 'status' => $user['status']];
            jsonResponse(['success' => true, 'message' => 'Login successful.', 'user' => $_SESSION['user']]);

        case 'logout':
            requireMethod('POST');
            $_SESSION = [];
            if (ini_get('session.use_cookies')) {
                $params = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', $params['secure'], $params['httponly']);
            }
            session_destroy();
            jsonResponse(['success' => true, 'message' => 'Signed out successfully.']);

        case 'size-types':
            $stmt = $pdo->query("SELECT size_type_id, type_code, type_name, category_group, description FROM size_types ORDER BY size_type_id");
            jsonResponse(['success' => true, 'size_types' => $stmt->fetchAll()]);

        case 'size-options':
            $typeId = filter_var($_GET['size_type_id'] ?? null, FILTER_VALIDATE_INT);
            if ($typeId) {
                $stmt = $pdo->prepare("SELECT * FROM size_options WHERE size_type_id = ? ORDER BY sort_order, size_option_id");
                $stmt->execute([$typeId]);
            } else {
                $stmt = $pdo->query("SELECT * FROM size_options ORDER BY size_type_id, sort_order, size_option_id");
            }
            jsonResponse(['success' => true, 'size_options' => $stmt->fetchAll()]);

        case 'product-variants':
            $productId = (int)($_GET['product_id'] ?? 0);
            if ($productId < 1) jsonResponse(['success' => false, 'message' => 'Product ID is required.'], 422);
            $visibility = $pdo->prepare("SELECT 1 FROM products p JOIN shops s ON s.shop_id=p.shop_id JOIN users u ON u.user_id=s.vendor_id WHERE p.product_id=? AND p.status='active' AND s.status='approved' AND u.status='active'");
            $visibility->execute([$productId]);
            if (!$visibility->fetchColumn()) {
                $user = requireRole(['vendor','admin']);
                if ($user['role'] === 'vendor') {
                    $owner = $pdo->prepare('SELECT 1 FROM products p JOIN shops s ON s.shop_id=p.shop_id WHERE p.product_id=? AND s.vendor_id=?');
                    $owner->execute([$productId, $user['id']]);
                    if (!$owner->fetchColumn()) jsonResponse(['success' => false, 'message' => 'Product not found or access denied.'], 404);
                }
            }
            $stmt = $pdo->prepare("SELECT pv.*, so.display_label, so.uk_size, so.us_size, so.eu_size, so.chest_bust_inches, so.waist_inches, so.hip_inches, so.height_cm, so.length_inches FROM product_variants pv LEFT JOIN size_options so ON so.size_option_id=pv.size_option_id WHERE pv.product_id=? ORDER BY pv.variant_id");
            $stmt->execute([$productId]);
            jsonResponse(['success' => true, 'variants' => $stmt->fetchAll()]);

        case 'products':
            if ($method === 'GET') {
                $sql = "SELECT p.product_id,p.shop_id,p.category_id,p.size_type_id,p.product_name,p.description,p.price_per_day,p.deposit_amount,p.stock,p.sizes,p.size_inventory,p.custom_measurement_notes,p.image_path,p.status,c.category_name,s.shop_name,st.type_code AS size_type_code,st.type_name AS size_type_name,st.category_group FROM products p LEFT JOIN categories c ON c.category_id=p.category_id LEFT JOIN shops s ON s.shop_id=p.shop_id LEFT JOIN users u ON u.user_id=s.vendor_id LEFT JOIN size_types st ON st.size_type_id=p.size_type_id";
                $params = [];
                if (($_GET['manage'] ?? '') !== '1') {
                    $sql .= " WHERE p.status='active' AND s.status='approved' AND u.status='active'";
                    if (isset($_GET['id'])) { $sql .= " AND p.product_id=?"; $params[] = (int)$_GET['id']; }
                    if (isset($_GET['shop_id'])) { $sql .= " AND p.shop_id=?"; $params[] = (int)$_GET['shop_id']; }
                } else {
                    $user = requireRole(['vendor','admin']);
                    if ($user['role'] === 'vendor') { $sql .= " WHERE s.vendor_id=?"; $params[] = $user['id']; }
                }
                $sql .= " ORDER BY p.created_at DESC";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $products = $stmt->fetchAll();

                $vStmt = $pdo->prepare("SELECT pv.*, so.display_label, so.uk_size, so.us_size, so.eu_size, so.chest_bust_inches, so.waist_inches, so.hip_inches, so.height_cm, so.length_inches FROM product_variants pv LEFT JOIN size_options so ON so.size_option_id=pv.size_option_id WHERE pv.product_id=? ORDER BY pv.variant_id");
                $imageStmt = $pdo->prepare('SELECT image_path FROM product_images WHERE product_id=? ORDER BY image_id');
                foreach ($products as &$product) {
                    $vStmt->execute([$product['product_id']]);
                    $product['variants'] = $vStmt->fetchAll();
                    $imageStmt->execute([$product['product_id']]);
                    $gallery = array_column($imageStmt->fetchAll(), 'image_path');
                    $product['images'] = $gallery ?: ($product['image_path'] ? [$product['image_path']] : []);
                }
                unset($product);

                jsonResponse(['success' => true, 'products' => $products]);
            }

            if ($method === 'POST') {
                $user = requireRole(['vendor','admin']);
                $productName = cleanString($data['product_name'] ?? '', 160);
                $description = cleanString($data['description'] ?? '', 3000);
                $price = filter_var($data['price_per_day'] ?? null, FILTER_VALIDATE_FLOAT);
                $stock = filter_var($data['stock'] ?? 0, FILTER_VALIDATE_INT);
                $categoryId = filter_var($data['category_id'] ?? null, FILTER_VALIDATE_INT);
                $shopId = filter_var($data['shop_id'] ?? null, FILTER_VALIDATE_INT);
                $sizeTypeId = filter_var($data['size_type_id'] ?? null, FILTER_VALIDATE_INT) ?: null;
                $customNotes = cleanString($data['custom_measurement_notes'] ?? '', 1000);
                $sizesStr = cleanString($data['sizes'] ?? 'Free Size', 100);

                if ($productName === '' || $price === false || $price <= 0 || $stock === false || $stock < 0 || !$categoryId) {
                    jsonResponse(['success' => false, 'message' => 'Valid product name, category, shop, daily price and stock are required.'], 422);
                }
                $deposit = filter_var($data['deposit_amount'] ?? 0, FILTER_VALIDATE_FLOAT);
                if ($deposit === false || $deposit < 0) jsonResponse(['success' => false, 'message' => 'Security deposit must be a non-negative amount.'], 422);
                $categoryCheck = $pdo->prepare('SELECT category_id FROM categories WHERE category_id=?');
                $categoryCheck->execute([$categoryId]);
                if (!$categoryCheck->fetchColumn()) jsonResponse(['success' => false, 'message' => 'Choose a valid product category.'], 422);
                $shopStatus = '';
                if ($user['role'] === 'vendor') {
                    $shopQuery = $pdo->prepare('SELECT s.shop_id,s.status,u.status AS vendor_status FROM shops s JOIN users u ON u.user_id=s.vendor_id WHERE s.vendor_id=? AND (? IS NULL OR s.shop_id=?) ORDER BY s.shop_id LIMIT 1');
                    $shopQuery->execute([$user['id'], $shopId, $shopId]);
                    $vendorShop = $shopQuery->fetch();
                    if (!$vendorShop) jsonResponse(['success' => false, 'message' => 'Create a shop before adding products.'], 403);
                    $shopId = (int)$vendorShop['shop_id'];
                    $shopStatus = $vendorShop['status'];
                } else {
                    if (!$shopId) jsonResponse(['success' => false, 'message' => 'Choose a shop for this product.'], 422);
                    $shopQuery = $pdo->prepare('SELECT status FROM shops WHERE shop_id=?');
                    $shopQuery->execute([$shopId]);
                    $shopStatus = (string)$shopQuery->fetchColumn();
                    if ($shopStatus === '') jsonResponse(['success' => false, 'message' => 'Choose a valid shop.'], 422);
                }
                if ($sizeTypeId) {
                    $sizeTypeCheck = $pdo->prepare('SELECT size_type_id FROM size_types WHERE size_type_id=?');
                    $sizeTypeCheck->execute([$sizeTypeId]);
                    if (!$sizeTypeCheck->fetchColumn()) jsonResponse(['success' => false, 'message' => 'Choose a valid size type.'], 422);
                }
                $variants = isset($data['variants']) && is_array($data['variants'])
                    ? normalizeProductVariants($data['variants'], $sizeTypeId)
                    : [];
                if ($variants) {
                    $stock = array_sum(array_column($variants, 'stock'));
                    $availableLabels = array_column(array_filter($variants, static fn(array $variant): bool => $variant['stock'] > 0), 'variant_label');
                    $sizesStr = cleanString(implode(',', $availableLabels), 100);
                }
                $requestedStatus = in_array($data['status'] ?? 'active', ['active','inactive'], true) ? $data['status'] : 'active';
                $productStatus = $requestedStatus === 'active' && $shopStatus === 'approved' && ($user['role'] === 'admin' || ($vendorShop['vendor_status'] ?? '') === 'active') ? 'active' : 'inactive';
                $pdo->beginTransaction();
                $stmt = $pdo->prepare("INSERT INTO products (shop_id,category_id,size_type_id,product_name,description,price_per_day,deposit_amount,stock,sizes,custom_measurement_notes,image_path,status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
                $stmt->execute([
                    $shopId,
                    $categoryId,
                    $sizeTypeId,
                    $productName,
                    $description,
                    $price,
                    $deposit,
                    $stock,
                    $sizesStr,
                    $customNotes,
                    null,
                    $productStatus
                ]);
                $newProductId = (int)$pdo->lastInsertId();

                if ($variants) {
                    $vInsert = $pdo->prepare("INSERT INTO product_variants (product_id,size_option_id,variant_label,sku,stock,price_override,custom_measurements,status) VALUES (?,?,?,?,?,?,?,?)");
                    foreach ($variants as $index => $variant) {
                        $vInsert->execute([$newProductId, $variant['size_option_id'], $variant['variant_label'], $variant['sku'] ?: "SKU-$newProductId-" . ($index + 1), $variant['stock'], $variant['price_override'], $variant['custom_measurements'], $variant['stock'] > 0 ? 'active' : 'inactive']);
                    }
                }

                $pdo->commit();
                jsonResponse(['success' => true, 'message' => 'Product created successfully with sizing configuration.', 'product_id' => $newProductId], 201);
            }

            if ($method === 'PUT' || $method === 'PATCH') {
                $user = requireRole(['vendor','admin']);
                $id = (int)($_GET['id'] ?? 0);
                if ($id < 1) jsonResponse(['success' => false, 'message' => 'Product ID is required.'], 422);

                $sql = "SELECT p.product_id,p.shop_id,s.status AS shop_status,u.status AS vendor_status FROM products p JOIN shops s ON s.shop_id=p.shop_id JOIN users u ON u.user_id=s.vendor_id WHERE p.product_id=?";
                $params = [$id];
                if ($user['role'] === 'vendor') { $sql .= " AND s.vendor_id=?"; $params[] = $user['id']; }
                $check = $pdo->prepare($sql); $check->execute($params);
                $productOwner = $check->fetch();
                if (!$productOwner) jsonResponse(['success' => false, 'message' => 'Product not found or access denied.'], 404);
                $productName = cleanString($data['product_name'] ?? '', 160);
                $price = filter_var($data['price_per_day'] ?? null, FILTER_VALIDATE_FLOAT);
                $deposit = filter_var($data['deposit_amount'] ?? 0, FILTER_VALIDATE_FLOAT);
                $stock = filter_var($data['stock'] ?? 0, FILTER_VALIDATE_INT);
                $categoryId = filter_var($data['category_id'] ?? null, FILTER_VALIDATE_INT);
                $sizeTypeId = filter_var($data['size_type_id'] ?? null, FILTER_VALIDATE_INT) ?: null;
                if ($productName === '' || $price === false || $price <= 0 || $deposit === false || $deposit < 0 || $stock === false || $stock < 0 || !$categoryId) {
                    jsonResponse(['success' => false, 'message' => 'Valid product details, category, price, deposit, and stock are required.'], 422);
                }
                $categoryCheck = $pdo->prepare('SELECT category_id FROM categories WHERE category_id=?');
                $categoryCheck->execute([$categoryId]);
                if (!$categoryCheck->fetchColumn()) jsonResponse(['success' => false, 'message' => 'Choose a valid product category.'], 422);
                if ($sizeTypeId) {
                    $sizeTypeCheck = $pdo->prepare('SELECT size_type_id FROM size_types WHERE size_type_id=?');
                    $sizeTypeCheck->execute([$sizeTypeId]);
                    if (!$sizeTypeCheck->fetchColumn()) jsonResponse(['success' => false, 'message' => 'Choose a valid size type.'], 422);
                }
                $variants = isset($data['variants']) && is_array($data['variants'])
                    ? normalizeProductVariants($data['variants'], $sizeTypeId)
                    : null;
                if ($variants !== null) {
                    $stock = array_sum(array_column($variants, 'stock'));
                }
                $requestedStatus = in_array($data['status'] ?? 'active', ['active','inactive'], true) ? $data['status'] : 'active';
                $productStatus = $requestedStatus === 'active' && $productOwner['shop_status'] === 'approved' && ($user['role'] === 'admin' || $productOwner['vendor_status'] === 'active') ? 'active' : 'inactive';
                $pdo->beginTransaction();
                $sizeTypeId = filter_var($data['size_type_id'] ?? null, FILTER_VALIDATE_INT) ?: null;
                $stmt = $pdo->prepare("UPDATE products SET category_id=?,product_name=?,description=?,price_per_day=?,deposit_amount=?,stock=?,size_type_id=?,custom_measurement_notes=?,status=? WHERE product_id=?");
                $stmt->execute([
                    $categoryId,
                    $productName,
                    cleanString($data['description'] ?? '', 3000),
                    $price,
                    $deposit,
                    $stock,
                    $sizeTypeId,
                    cleanString($data['custom_measurement_notes'] ?? '', 1000),
                    $productStatus,
                    $id
                ]);

                if ($variants !== null) {
                    $futureReservations = $pdo->prepare("SELECT COUNT(*) FROM order_items oi JOIN orders o ON o.order_id=oi.order_id WHERE oi.product_id=? AND oi.variant_id IS NOT NULL AND oi.pickup_date>=CURRENT_DATE AND o.status<>'cancelled' AND oi.vendor_status NOT IN ('rejected','cancelled')");
                    $futureReservations->execute([$id]);
                    if ((int)$futureReservations->fetchColumn() > 0) {
                        $pdo->rollBack();
                        jsonResponse(['success' => false, 'message' => 'Size inventory cannot be replaced while future rentals are booked. Update stock without changing the size options.'], 409);
                    }
                    $pdo->prepare("DELETE FROM product_variants WHERE product_id=?")->execute([$id]);
                    $vInsert = $pdo->prepare("INSERT INTO product_variants (product_id,size_option_id,variant_label,sku,stock,price_override,custom_measurements,status) VALUES (?,?,?,?,?,?,?,?)");
                    foreach ($variants as $index => $variant) {
                        $vInsert->execute([$id, $variant['size_option_id'], $variant['variant_label'], $variant['sku'] ?: "SKU-$id-" . ($index + 1), $variant['stock'], $variant['price_override'], $variant['custom_measurements'], $variant['stock'] > 0 ? 'active' : 'inactive']);
                    }
                    $availableLabels = array_column(array_filter($variants, static fn(array $variant): bool => $variant['stock'] > 0), 'variant_label');
                    $pdo->prepare('UPDATE products SET sizes=? WHERE product_id=?')->execute([cleanString(implode(',', $availableLabels), 100), $id]);
                }

                $pdo->commit();
                jsonResponse(['success' => true, 'message' => 'Product updated successfully.']);
            }

            if ($method === 'DELETE') {
                $user = requireRole(['vendor','admin']);
                $id = (int)($_GET['id'] ?? 0);
                $ownerSql = 'SELECT p.product_id FROM products p JOIN shops s ON s.shop_id=p.shop_id WHERE p.product_id=?';
                $ownerParams = [$id];
                if ($user['role'] === 'vendor') { $ownerSql .= ' AND s.vendor_id=?'; $ownerParams[] = $user['id']; }
                $owned = $pdo->prepare($ownerSql);
                $owned->execute($ownerParams);
                if (!$owned->fetchColumn()) jsonResponse(['success' => false, 'message' => 'Product not found or access denied.'], 404);
                $hasBookings = $pdo->prepare('SELECT COUNT(*) FROM order_items WHERE product_id=?');
                $hasBookings->execute([$id]);
                if ((int)$hasBookings->fetchColumn() > 0) {
                    $pdo->prepare("UPDATE products SET status='inactive' WHERE product_id=?")->execute([$id]);
                    jsonResponse(['success' => true, 'message' => 'Product archived because it has rental history.']);
                }
                $pdo->prepare('DELETE FROM products WHERE product_id=?')->execute([$id]);
                jsonResponse(['success' => true, 'message' => 'Product deleted.']);
            }
            jsonResponse(['success' => false, 'message' => 'Method not allowed.'], 405);

        case 'cart':
            $user = requireLogin();
            if ($method === 'GET') {
                $stmt = $pdo->prepare("SELECT ci.cart_item_id,ci.product_id,ci.variant_id,ci.quantity,ci.pickup_date,ci.return_date,ci.size,p.product_name,p.price_per_day,p.image_path,p.deposit_amount,s.shop_name,DATEDIFF(ci.return_date,ci.pickup_date) AS rental_days,(p.price_per_day*ci.quantity*DATEDIFF(ci.return_date,ci.pickup_date)) AS line_total,pv.variant_label FROM cart_items ci JOIN products p ON p.product_id=ci.product_id JOIN shops s ON s.shop_id=p.shop_id LEFT JOIN product_variants pv ON pv.variant_id=ci.variant_id WHERE ci.user_id=? ORDER BY ci.created_at DESC");
                $stmt->execute([$user['id']]);
                jsonResponse(['success' => true, 'items' => $stmt->fetchAll()]);
            }
            if ($method === 'POST') {
                $productId = (int)($data['product_id'] ?? 0);
                $variantId = filter_var($data['variant_id'] ?? null, FILTER_VALIDATE_INT) ?: null;
                $qty = filter_var($data['quantity'] ?? null, FILTER_VALIDATE_INT);
                $pickup = cleanString($data['pickup_date'] ?? '', 10);
                $return = cleanString($data['return_date'] ?? '', 10);
                $size = cleanString($data['size'] ?? '', 255);

                $start = DateTime::createFromFormat('!Y-m-d', $pickup);
                $end = DateTime::createFromFormat('!Y-m-d', $return);
                if (!$productId || $qty === false || $qty < 1 || !$start || $start->format('Y-m-d') !== $pickup || !$end || $end->format('Y-m-d') !== $return || $end <= $start || $start < new DateTime('today')) {
                    jsonResponse(['success' => false, 'message' => 'Choose valid future pickup and return dates.'], 422);
                }

                $check = $pdo->prepare("SELECT p.product_id,p.stock,p.sizes FROM products p JOIN shops s ON s.shop_id=p.shop_id JOIN users v ON v.user_id=s.vendor_id WHERE p.product_id=? AND p.status='active' AND s.status='approved' AND v.status='active'");
                $check->execute([$productId]);
                $prod = $check->fetch();
                if (!$prod) jsonResponse(['success' => false, 'message' => 'Product is unavailable.'], 404);

                $variantLabel = null;
                if ($variantId) {
                    $vCheck = $pdo->prepare("SELECT pv.variant_id,pv.stock,pv.variant_label,so.display_label,so.uk_size,so.us_size,so.eu_size FROM product_variants pv LEFT JOIN size_options so ON so.size_option_id=pv.size_option_id WHERE pv.variant_id=? AND pv.product_id=? AND pv.status='active'");
                    $vCheck->execute([$variantId, $productId]);
                    $vRow = $vCheck->fetch();
                    if (!$vRow || (int)$vRow['stock'] < 1) {
                        jsonResponse(['success' => false, 'message' => 'The selected size/variant is currently out of stock.'], 422);
                    }
                    $variantLabel = $vRow['variant_label'];
                    $allowedSizes = array_filter([$vRow['variant_label'], $vRow['display_label'], 'US ' . $vRow['us_size'], 'UK ' . $vRow['uk_size'], 'EU ' . $vRow['eu_size']]);
                    if (!in_array($size, $allowedSizes, true)) jsonResponse(['success' => false, 'message' => 'The chosen size does not match the selected inventory option.'], 422);
                } else {
                    $variantCheck = $pdo->prepare("SELECT COUNT(*) FROM product_variants WHERE product_id=? AND status='active'");
                    $variantCheck->execute([$productId]);
                    if ((int)$variantCheck->fetchColumn() > 0) jsonResponse(['success' => false, 'message' => 'Select an available size option before adding this product.'], 422);
                    if (!in_array($size, array_map('trim', explode(',', (string)$prod['sizes'])), true)) {
                        jsonResponse(['success' => false, 'message' => 'Choose a valid size for this product.'], 422);
                    }
                }
                if ($size === '') {
                    jsonResponse(['success' => false, 'message' => 'Please select the required clothing size/measurements.'], 422);
                }
                $availableQuantity = availableRentalQuantity($pdo, $productId, $variantId, $pickup, $return);
                if ($qty > $availableQuantity) {
                    jsonResponse(['success' => false, 'message' => 'Only ' . $availableQuantity . ' of this size is available for those rental dates.'], 409);
                }

                $stmt = $pdo->prepare("INSERT INTO cart_items (user_id,product_id,variant_id,quantity,pickup_date,return_date,size) VALUES (?,?,?,?,?,?,?)");
                $stmt->execute([$user['id'], $productId, $variantId, $qty, $start->format('Y-m-d'), $end->format('Y-m-d'), $size]);
                jsonResponse(['success' => true, 'message' => 'Added to cart.'], 201);
            }
            if ($method === 'DELETE') {
                $id = (int)($_GET['id'] ?? 0);
                $stmt = $pdo->prepare("DELETE FROM cart_items WHERE cart_item_id=? AND user_id=?");
                $stmt->execute([$id, $user['id']]);
                jsonResponse(['success' => true, 'message' => 'Cart item removed.']);
            }
            jsonResponse(['success' => false, 'message' => 'Method not allowed.'], 405);

        case 'checkout':
            requireMethod('POST');
            $user = requireRole(['customer']);
            $pdo->beginTransaction();
            $items = $pdo->prepare("SELECT ci.*, p.price_per_day, p.deposit_amount, p.stock, p.status AS product_status,p.product_name,pv.stock AS variant_stock,s.status AS shop_status,v.status AS vendor_status FROM cart_items ci JOIN products p ON p.product_id=ci.product_id JOIN shops s ON s.shop_id=p.shop_id JOIN users v ON v.user_id=s.vendor_id LEFT JOIN product_variants pv ON pv.variant_id=ci.variant_id WHERE ci.user_id=? ORDER BY p.product_id,ci.variant_id FOR UPDATE");
            $items->execute([$user['id']]);
            $cartItems = $items->fetchAll();
            if (!$cartItems) {
                $pdo->rollBack();
                jsonResponse(['success' => false, 'message' => 'Your cart is empty.'], 422);
            }

            $total = 0; $deposit = 0;
            foreach ($cartItems as $index => $item) {
                $days = (new DateTime($item['pickup_date']))->diff(new DateTime($item['return_date']))->days;
                if ($days < 1 || $item['product_status'] !== 'active' || $item['shop_status'] !== 'approved' || $item['vendor_status'] !== 'active') {
                    $pdo->rollBack();
                    jsonResponse(['success' => false, 'message' => 'One or more items in your cart is no longer available in the requested quantity.'], 409);
                }
                $requestedQuantity = (int)$item['quantity'];
                foreach ($cartItems as $otherIndex => $other) {
                    if ($otherIndex === $index || (int)$other['product_id'] !== (int)$item['product_id'] || (int)($other['variant_id'] ?? 0) !== (int)($item['variant_id'] ?? 0)) continue;
                    if ($other['pickup_date'] < $item['return_date'] && $other['return_date'] > $item['pickup_date']) $requestedQuantity += (int)$other['quantity'];
                }
                $productLock = $pdo->prepare('SELECT product_id FROM products WHERE product_id=? FOR UPDATE');
                $productLock->execute([$item['product_id']]);
                $availableQuantity = availableRentalQuantity($pdo, (int)$item['product_id'], $item['variant_id'] ? (int)$item['variant_id'] : null, $item['pickup_date'], $item['return_date']);
                if ($requestedQuantity > $availableQuantity) {
                    $pdo->rollBack();
                    jsonResponse(['success' => false, 'message' => 'A selected size is no longer available for the requested rental dates. Please update your cart.'], 409);
                }
                $total += (float)$item['price_per_day'] * (int)$item['quantity'] * $days;
                $deposit += (float)$item['deposit_amount'] * (int)$item['quantity'];
            }

            $order = $pdo->prepare("INSERT INTO orders (user_id,rental_total,deposit_total,grand_total,status) VALUES (?,?,?,?,'pending')");
            $order->execute([$user['id'], $total, $deposit, $total + $deposit]);
            $orderId = (int)$pdo->lastInsertId();

            $line = $pdo->prepare("INSERT INTO order_items (order_id,product_id,variant_id,product_name,quantity,price_per_day,pickup_date,return_date,line_total,size) VALUES (?,?,?,?,?,?,?,?,?,?)");

            foreach ($cartItems as $item) {
                $days = (new DateTime($item['pickup_date']))->diff(new DateTime($item['return_date']))->days;
                $lineTotal = (float)$item['price_per_day'] * (int)$item['quantity'] * $days;
                $line->execute([
                    $orderId,
                    $item['product_id'],
                    $item['variant_id'] ?: null,
                    $item['product_name'],
                    $item['quantity'],
                    $item['price_per_day'],
                    $item['pickup_date'],
                    $item['return_date'],
                    $lineTotal,
                    $item['size'] ?? ''
                ]);
            }

            $pdo->prepare("DELETE FROM cart_items WHERE user_id=?")->execute([$user['id']]);
            $pdo->commit();
            jsonResponse(['success' => true, 'message' => 'Rental booking placed successfully.', 'order_id' => $orderId, 'grand_total' => $total + $deposit], 201);

        case 'orders':
            $user = requireLogin();
            if ($method === 'GET') {
                if ($user['role'] === 'admin') {
                    $stmt = $pdo->query("SELECT o.*,u.first_name,u.last_name,u.email FROM orders o JOIN users u ON u.user_id=o.user_id ORDER BY o.created_at DESC");
                    jsonResponse(['success' => true, 'orders' => $stmt->fetchAll()]);
                }
                $stmt = $pdo->prepare("SELECT * FROM orders WHERE user_id=? ORDER BY created_at DESC");
                $stmt->execute([$user['id']]);
                jsonResponse(['success' => true, 'orders' => $stmt->fetchAll()]);
            }
            jsonResponse(['success' => false, 'message' => 'Method not allowed.'], 405);

        case 'order-items':
            $user = requireLogin();
            $orderId = (int)($_GET['order_id'] ?? 0);
            $sql = "SELECT oi.* FROM order_items oi JOIN orders o ON o.order_id=oi.order_id WHERE oi.order_id=?";
            $params = [$orderId];
            if ($user['role'] !== 'admin') { $sql .= " AND o.user_id=?"; $params[] = $user['id']; }
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            jsonResponse(['success' => true, 'items' => $stmt->fetchAll()]);

        case 'update-order':
            requireMethod('POST');
            requireRole(['admin']);
            $id = (int)($data['order_id'] ?? 0);
            $status = $data['status'] ?? '';
            if (!in_array($status, ['pending','confirmed','processing','ready_for_pickup','picked_up','returned','cancelled'], true)) {
                jsonResponse(['success' => false, 'message' => 'Invalid order status.'], 422);
            }
            $stmt = $pdo->prepare("UPDATE orders SET status=? WHERE order_id=?");
            $stmt->execute([$status, $id]);
            jsonResponse(['success' => true, 'message' => 'Order status updated.']);

        case 'vendor-shops':
            $user = requireRole(['vendor']);
            if ($method === 'GET') {
                $profile = $pdo->prepare('SELECT first_name,last_name,email,phone,status FROM users WHERE user_id=?');
                $profile->execute([$user['id']]);
                $shopList = $pdo->prepare('SELECT * FROM shops WHERE vendor_id=? ORDER BY created_at');
                $shopList->execute([$user['id']]);
                jsonResponse(['success' => true, 'profile' => $profile->fetch(), 'shops' => $shopList->fetchAll()]);
            }
            if ($method === 'POST') {
                $name = cleanString($data['shop_name'] ?? '', 150);
                $email = strtolower(cleanString($data['email'] ?? '', 190));
                if ($name === '' || ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL))) {
                    jsonResponse(['success' => false, 'message' => 'Enter a shop name and valid contact email.'], 422);
                }
                $insert = $pdo->prepare("INSERT INTO shops (vendor_id,shop_name,description,address,phone,email,status) VALUES (?,?,?,?,?,?, 'pending')");
                $insert->execute([$user['id'], $name, cleanString($data['description'] ?? '', 3000), cleanString($data['address'] ?? '', 255), cleanString($data['phone'] ?? '', 30), $email ?: null]);
                jsonResponse(['success' => true, 'message' => 'Shop submitted for admin approval.', 'shop_id' => (int)$pdo->lastInsertId()], 201);
            }
            if ($method === 'PUT' || $method === 'PATCH') {
                $shopId = filter_var($_GET['id'] ?? $data['shop_id'] ?? null, FILTER_VALIDATE_INT);
                if (!$shopId) jsonResponse(['success' => false, 'message' => 'Shop ID is required.'], 422);
                $shopStmt = $pdo->prepare('SELECT * FROM shops WHERE shop_id=? AND vendor_id=?');
                $shopStmt->execute([$shopId, $user['id']]);
                $shop = $shopStmt->fetch();
                if (!$shop) jsonResponse(['success' => false, 'message' => 'Shop not found or access denied.'], 404);
                if (array_key_exists('is_active', $data)) {
                    if ($data['is_active'] && !in_array($shop['status'], ['approved','inactive'], true)) {
                        jsonResponse(['success' => false, 'message' => 'Only admin-approved shops can be activated.'], 403);
                    }
                    $newStatus = $data['is_active'] ? 'approved' : 'inactive';
                    $pdo->prepare('UPDATE shops SET status=? WHERE shop_id=? AND vendor_id=?')->execute([$newStatus, $shopId, $user['id']]);
                    jsonResponse(['success' => true, 'message' => $data['is_active'] ? 'Shop activated.' : 'Shop deactivated.']);
                }
                $name = cleanString($data['shop_name'] ?? '', 150);
                $email = strtolower(cleanString($data['email'] ?? '', 190));
                if ($name === '' || ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL))) {
                    jsonResponse(['success' => false, 'message' => 'Enter a shop name and valid contact email.'], 422);
                }
                $pdo->beginTransaction();
                $nextStatus = $shop['status'] === 'rejected' ? 'pending' : $shop['status'];
                $pdo->prepare('UPDATE shops SET shop_name=?,description=?,address=?,phone=?,email=?,status=? WHERE shop_id=? AND vendor_id=?')
                    ->execute([$name, cleanString($data['description'] ?? '', 3000), cleanString($data['address'] ?? '', 255), cleanString($data['phone'] ?? '', 30), $email ?: null, $nextStatus, $shopId, $user['id']]);
                $ownerName = cleanString($data['owner_name'] ?? '', 160);
                if ($ownerName !== '') {
                    $parts = preg_split('/\s+/', $ownerName, 2);
                    $pdo->prepare('UPDATE users SET first_name=?,last_name=?,phone=? WHERE user_id=?')
                        ->execute([$parts[0], $parts[1] ?? '', cleanString($data['owner_phone'] ?? $data['phone'] ?? '', 30), $user['id']]);
                }
                $pdo->commit();
                jsonResponse(['success' => true, 'message' => 'Shop profile saved.']);
            }
            jsonResponse(['success' => false, 'message' => 'Method not allowed.'], 405);

        case 'shop-logo':
            requireMethod('POST');
            $user = requireRole(['vendor']);
            $shopId = filter_var($_POST['shop_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$shopId) jsonResponse(['success' => false, 'message' => 'Shop ID is required.'], 422);
            $owner = $pdo->prepare('SELECT logo_path FROM shops WHERE shop_id=? AND vendor_id=?');
            $owner->execute([$shopId, $user['id']]);
            $oldPath = $owner->fetchColumn();
            if ($oldPath === false) jsonResponse(['success' => false, 'message' => 'Shop not found or access denied.'], 404);
            if (!isset($_FILES['logo'])) jsonResponse(['success' => false, 'message' => 'Choose a shop logo to upload.'], 422);
            $path = storeUploadedImage($_FILES['logo']);
            $pdo->prepare('UPDATE shops SET logo_path=? WHERE shop_id=? AND vendor_id=?')->execute([$path, $shopId, $user['id']]);
            if (is_string($oldPath) && str_starts_with($oldPath, 'backend/uploads/')) {
                $oldFile = dirname(__DIR__, 2) . '/' . $oldPath;
                if (is_file($oldFile)) unlink($oldFile);
            }
            jsonResponse(['success' => true, 'message' => 'Shop logo updated.', 'logo_path' => $path]);

        case 'vendor-dashboard':
            $user = requireRole(['vendor']);
            $summary = $pdo->prepare(
                "SELECT u.status AS vendor_status,
                    (SELECT COUNT(*) FROM products p JOIN shops s ON s.shop_id=p.shop_id WHERE s.vendor_id=u.user_id) AS total_products,
                    (SELECT COUNT(DISTINCT oi.order_id) FROM order_items oi JOIN products p ON p.product_id=oi.product_id JOIN shops s ON s.shop_id=p.shop_id WHERE s.vendor_id=u.user_id) AS total_orders,
                    (SELECT COUNT(*) FROM order_items oi JOIN products p ON p.product_id=oi.product_id JOIN shops s ON s.shop_id=p.shop_id WHERE s.vendor_id=u.user_id AND oi.vendor_status='pending' AND (SELECT status FROM orders WHERE order_id=oi.order_id)<>'cancelled') AS pending_requests,
                    (SELECT COUNT(*) FROM order_items oi JOIN products p ON p.product_id=oi.product_id JOIN shops s ON s.shop_id=p.shop_id WHERE s.vendor_id=u.user_id AND oi.vendor_status='picked_up') AS active_rentals,
                    (SELECT COALESCE(SUM(oi.line_total),0) FROM order_items oi JOIN products p ON p.product_id=oi.product_id JOIN shops s ON s.shop_id=p.shop_id WHERE s.vendor_id=u.user_id AND oi.vendor_status='returned') AS earnings
                 FROM users u WHERE u.user_id=?"
            );
            $summary->execute([$user['id']]);
            $dashboard = $summary->fetch();
            $shops = $pdo->prepare('SELECT shop_id,shop_name,status FROM shops WHERE vendor_id=? ORDER BY created_at');
            $shops->execute([$user['id']]);
            $recent = $pdo->prepare(
                "SELECT oi.order_item_id,oi.order_id,oi.product_name,oi.quantity,oi.size,oi.pickup_date,oi.return_date,oi.line_total,oi.vendor_status,u.first_name,u.last_name
                 FROM order_items oi JOIN orders o ON o.order_id=oi.order_id JOIN users u ON u.user_id=o.user_id
                 JOIN products p ON p.product_id=oi.product_id JOIN shops s ON s.shop_id=p.shop_id
                 WHERE s.vendor_id=? ORDER BY o.created_at DESC LIMIT 8"
            );
            $recent->execute([$user['id']]);
            jsonResponse(['success' => true, 'dashboard' => $dashboard, 'shops' => $shops->fetchAll(), 'recent_orders' => $recent->fetchAll()]);

        case 'vendor-orders':
            $user = requireRole(['vendor']);
            if ($method === 'GET') {
                $stmt = $pdo->prepare(
                    "SELECT oi.order_item_id,oi.order_id,oi.product_id,oi.variant_id,oi.product_name,oi.quantity,oi.size,oi.pickup_date,oi.return_date,oi.line_total,oi.vendor_status,o.status AS order_status,o.created_at,u.first_name,u.last_name,u.email,p.shop_id,s.shop_name
                     FROM order_items oi JOIN orders o ON o.order_id=oi.order_id JOIN users u ON u.user_id=o.user_id
                     JOIN products p ON p.product_id=oi.product_id JOIN shops s ON s.shop_id=p.shop_id
                     WHERE s.vendor_id=? ORDER BY o.created_at DESC"
                );
                $stmt->execute([$user['id']]);
                jsonResponse(['success' => true, 'orders' => $stmt->fetchAll()]);
            }
            if ($method === 'POST') {
                $itemId = filter_var($data['order_item_id'] ?? null, FILTER_VALIDATE_INT);
                $newStatus = cleanString($data['status'] ?? '', 30);
                $transitions = [
                    'pending' => ['accepted','rejected'],
                    'accepted' => ['preparing','cancelled'],
                    'preparing' => ['ready_for_pickup','cancelled'],
                    'ready_for_pickup' => ['picked_up','cancelled'],
                    'picked_up' => ['returned'],
                    'returned' => [],
                    'rejected' => [],
                    'cancelled' => []
                ];
                if (!$itemId || !isset($transitions[$newStatus])) jsonResponse(['success' => false, 'message' => 'Choose a valid rental item and status.'], 422);
                $item = $pdo->prepare(
                    'SELECT oi.order_item_id,oi.order_id,oi.vendor_status,p.product_id,s.shop_id,s.status AS shop_status
                     FROM order_items oi JOIN products p ON p.product_id=oi.product_id JOIN shops s ON s.shop_id=p.shop_id
                     WHERE oi.order_item_id=? AND s.vendor_id=?'
                );
                $item->execute([$itemId, $user['id']]);
                $ownedItem = $item->fetch();
                if (!$ownedItem) jsonResponse(['success' => false, 'message' => 'Rental item not found or access denied.'], 404);
                if (!in_array($newStatus, $transitions[$ownedItem['vendor_status']], true)) jsonResponse(['success' => false, 'message' => 'That order status transition is not allowed.'], 409);
                if (!in_array($newStatus, ['rejected','cancelled'], true)) {
                    requireVendorShop($pdo, (int)$user['id'], (int)$ownedItem['shop_id']);
                }
                $pdo->beginTransaction();
                $pdo->prepare('UPDATE order_items SET vendor_status=? WHERE order_item_id=?')->execute([$newStatus, $itemId]);
                $statusesQuery = $pdo->prepare('SELECT vendor_status FROM order_items WHERE order_id=?');
                $statusesQuery->execute([$ownedItem['order_id']]);
                $statuses = array_column($statusesQuery->fetchAll(), 'vendor_status');
                $orderStatus = null;
                if ($statuses && !in_array('pending', $statuses, true)) {
                    if (count(array_intersect($statuses, ['accepted','rejected','cancelled'])) === count($statuses)) $orderStatus = in_array('accepted', $statuses, true) ? 'confirmed' : 'cancelled';
                    elseif (in_array('preparing', $statuses, true)) $orderStatus = 'processing';
                    elseif (in_array('picked_up', $statuses, true)) $orderStatus = 'picked_up';
                    elseif (in_array('ready_for_pickup', $statuses, true)) $orderStatus = 'ready_for_pickup';
                    elseif (in_array('returned', $statuses, true)) $orderStatus = 'returned';
                    else $orderStatus = 'confirmed';
                }
                if ($orderStatus !== null) $pdo->prepare('UPDATE orders SET status=? WHERE order_id=?')->execute([$orderStatus, $ownedItem['order_id']]);
                $pdo->commit();
                jsonResponse(['success' => true, 'message' => 'Rental status updated.']);
            }
            jsonResponse(['success' => false, 'message' => 'Method not allowed.'], 405);

        case 'admin-vendors':
            $admin = requireRole(['admin']);
            if ($method === 'GET') {
                $stmt = $pdo->query(
                    "SELECT u.user_id,u.first_name,u.last_name,u.email,u.phone,u.status AS vendor_status,u.created_at,
                            s.shop_id,s.shop_name,s.status AS shop_status,s.address,s.email AS shop_email,s.phone AS shop_phone
                     FROM users u LEFT JOIN shops s ON s.vendor_id=u.user_id WHERE u.role='vendor'
                     ORDER BY FIELD(u.status,'pending','active','suspended'),u.created_at DESC"
                );
                jsonResponse(['success' => true, 'vendors' => $stmt->fetchAll()]);
            }
            if ($method === 'POST') {
                $vendorId = filter_var($data['vendor_id'] ?? null, FILTER_VALIDATE_INT);
                $decision = cleanString($data['decision'] ?? '', 20);
                if (!$vendorId || !in_array($decision, ['approve','reject','suspend','reactivate'], true)) jsonResponse(['success' => false, 'message' => 'Choose a vendor and valid admin action.'], 422);
                $vendor = $pdo->prepare("SELECT user_id,status FROM users WHERE user_id=? AND role='vendor'");
                $vendor->execute([$vendorId]);
                if (!$vendor->fetch()) jsonResponse(['success' => false, 'message' => 'Vendor not found.'], 404);
                $pdo->beginTransaction();
                if ($decision === 'approve') {
                    $pdo->prepare("UPDATE users SET status='active' WHERE user_id=?")->execute([$vendorId]);
                    $pdo->prepare("UPDATE shops SET status='approved' WHERE vendor_id=? AND status IN ('pending','rejected')")->execute([$vendorId]);
                } elseif ($decision === 'reject') {
                    $pdo->prepare("UPDATE users SET status='suspended' WHERE user_id=?")->execute([$vendorId]);
                    $pdo->prepare("UPDATE shops SET status='rejected' WHERE vendor_id=? AND status='pending'")->execute([$vendorId]);
                } elseif ($decision === 'suspend') {
                    $pdo->prepare("UPDATE users SET status='suspended' WHERE user_id=?")->execute([$vendorId]);
                } else {
                    $pdo->prepare("UPDATE users SET status='active' WHERE user_id=?")->execute([$vendorId]);
                }
                $pdo->commit();
                jsonResponse(['success' => true, 'message' => 'Vendor account updated.']);
            }
            jsonResponse(['success' => false, 'message' => 'Method not allowed.'], 405);

        case 'admin-overview':
            requireRole(['admin']);
            $overview = [
                'vendors' => (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='vendor'")->fetchColumn(),
                'pending_vendors' => (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='vendor' AND status='pending'")->fetchColumn(),
                'shops' => (int)$pdo->query('SELECT COUNT(*) FROM shops')->fetchColumn(),
                'products' => (int)$pdo->query('SELECT COUNT(*) FROM products')->fetchColumn(),
                'orders' => (int)$pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn(),
                'active_orders' => (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status IN ('confirmed','processing','ready_for_pickup','picked_up')")->fetchColumn(),
                'platform_rentals' => (float)$pdo->query("SELECT COALESCE(SUM(rental_total),0) FROM orders WHERE status<>'cancelled'")->fetchColumn()
            ];
            jsonResponse(['success' => true, 'overview' => $overview]);

        case 'shops':
            if (($_GET['manage'] ?? '') === '1') {
                requireRole(['admin']);
                if ($method === 'GET') {
                    $stmt = $pdo->query('SELECT s.*,u.first_name,u.last_name,u.email AS vendor_email,u.status AS vendor_status FROM shops s JOIN users u ON u.user_id=s.vendor_id ORDER BY s.created_at DESC');
                    jsonResponse(['success' => true, 'shops' => $stmt->fetchAll()]);
                }
                if ($method === 'PATCH' || $method === 'POST') {
                    $shopId = filter_var($data['shop_id'] ?? null, FILTER_VALIDATE_INT);
                    $status = cleanString($data['status'] ?? '', 20);
                    if (!$shopId || !in_array($status, ['approved','rejected','inactive'], true)) jsonResponse(['success' => false, 'message' => 'Choose a valid shop and status.'], 422);
                    $stmt = $pdo->prepare('UPDATE shops SET status=? WHERE shop_id=?');
                    $stmt->execute([$status, $shopId]);
                    jsonResponse(['success' => true, 'message' => 'Shop status updated.']);
                }
                jsonResponse(['success' => false, 'message' => 'Method not allowed.'], 405);
            }
            $stmt = $pdo->query("SELECT s.shop_id,s.shop_name,s.description,s.address,s.phone,s.email,s.logo_path,u.first_name,u.last_name,COUNT(p.product_id) AS product_count FROM shops s JOIN users u ON u.user_id=s.vendor_id LEFT JOIN products p ON p.shop_id=s.shop_id AND p.status='active' WHERE s.status='approved' AND u.status='active' GROUP BY s.shop_id ORDER BY s.shop_name");
            jsonResponse(['success' => true, 'shops' => $stmt->fetchAll()]);

        case 'product-images':
            $user = requireRole(['vendor','admin']);
            $productId = filter_var($_GET['product_id'] ?? $data['product_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$productId) jsonResponse(['success' => false, 'message' => 'Product ID is required.'], 422);
            $ownerSql = 'SELECT p.image_path FROM products p JOIN shops s ON s.shop_id=p.shop_id WHERE p.product_id=?';
            $ownerParams = [$productId];
            if ($user['role'] === 'vendor') {
                $ownerSql .= ' AND s.vendor_id=?';
                $ownerParams[] = $user['id'];
            }
            $owner = $pdo->prepare($ownerSql);
            $owner->execute($ownerParams);
            $productImage = $owner->fetch();
            if (!$productImage) jsonResponse(['success' => false, 'message' => 'Product not found or access denied.'], 404);
            if ($method === 'GET') {
                $images = $pdo->prepare('SELECT image_id,image_path FROM product_images WHERE product_id=? ORDER BY image_id');
                $images->execute([$productId]);
                jsonResponse(['success' => true, 'images' => $images->fetchAll()]);
            }
            if ($method === 'POST') {
                $files = $_FILES['images'] ?? null;
                if (!$files || !is_array($files['name'] ?? null)) jsonResponse(['success' => false, 'message' => 'Choose one or more images.'], 422);
                $imageCount = $pdo->prepare('SELECT COUNT(*) FROM product_images WHERE product_id=?');
                $imageCount->execute([$productId]);
                $totalImages = max((int)$imageCount->fetchColumn(), $productImage['image_path'] ? 1 : 0);
                $fileCount = count($files['name']);
                if ($fileCount < 1 || $fileCount + $totalImages > 8) jsonResponse(['success' => false, 'message' => 'Products can have at most 8 gallery images.'], 422);
                $paths = [];
                try {
                    foreach ($files['name'] as $index => $unusedName) {
                        $file = ['name' => $files['name'][$index], 'type' => $files['type'][$index], 'tmp_name' => $files['tmp_name'][$index], 'error' => $files['error'][$index], 'size' => $files['size'][$index]];
                        $paths[] = storeUploadedImage($file);
                    }
                    $pdo->beginTransaction();
                    $insertImage = $pdo->prepare('INSERT INTO product_images (product_id,image_path) VALUES (?,?)');
                    foreach ($paths as $path) $insertImage->execute([$productId, $path]);
                    if (!$productImage['image_path']) $pdo->prepare('UPDATE products SET image_path=? WHERE product_id=?')->execute([$paths[0], $productId]);
                    $pdo->commit();
                } catch (Throwable $uploadError) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    foreach ($paths as $path) {
                        $filePath = dirname(__DIR__, 2) . '/' . $path;
                        if (is_file($filePath)) unlink($filePath);
                    }
                    throw $uploadError;
                }
                jsonResponse(['success' => true, 'message' => 'Product images uploaded.', 'images' => $paths], 201);
            }
            if ($method === 'DELETE') {
                $imageId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
                $imageStmt = $pdo->prepare('SELECT pi.image_path FROM product_images pi WHERE pi.image_id=? AND pi.product_id=?');
                $imageStmt->execute([$imageId, $productId]);
                $path = $imageStmt->fetchColumn();
                if ($path === false) jsonResponse(['success' => false, 'message' => 'Image not found.'], 404);
                $pdo->prepare('DELETE FROM product_images WHERE image_id=? AND product_id=?')->execute([$imageId, $productId]);
                $next = $pdo->prepare('SELECT image_path FROM product_images WHERE product_id=? ORDER BY image_id LIMIT 1');
                $next->execute([$productId]);
                $nextPath = $next->fetchColumn();
                if ($productImage['image_path'] === $path) $pdo->prepare('UPDATE products SET image_path=? WHERE product_id=?')->execute([$nextPath ?: null, $productId]);
                if (str_starts_with((string)$path, 'backend/uploads/')) {
                    $filePath = dirname(__DIR__, 2) . '/' . $path;
                    if (is_file($filePath)) unlink($filePath);
                }
                jsonResponse(['success' => true, 'message' => 'Product image deleted.']);
            }
            jsonResponse(['success' => false, 'message' => 'Method not allowed.'], 405);

        case 'availability':
            if ($method === 'GET') {
                $productId = filter_var($_GET['product_id'] ?? null, FILTER_VALIDATE_INT);
                $pickup = cleanString($_GET['pickup_date'] ?? '', 10);
                $return = cleanString($_GET['return_date'] ?? '', 10);
                if ($productId && $pickup !== '' && $return !== '') {
                    $start = DateTime::createFromFormat('!Y-m-d', $pickup);
                    $end = DateTime::createFromFormat('!Y-m-d', $return);
                    if (!$start || !$end || $end <= $start) jsonResponse(['success' => false, 'message' => 'Enter a valid rental date range.'], 422);
                    $variantId = filter_var($_GET['variant_id'] ?? null, FILTER_VALIDATE_INT) ?: null;
                    jsonResponse(['success' => true, 'available_quantity' => availableRentalQuantity($pdo, $productId, $variantId, $pickup, $return)]);
                }
                $user = requireRole(['vendor']);
                $productId = filter_var($_GET['product_id'] ?? null, FILTER_VALIDATE_INT);
                if (!$productId) jsonResponse(['success' => false, 'message' => 'Product ID is required.'], 422);
                $owner = $pdo->prepare('SELECT 1 FROM products p JOIN shops s ON s.shop_id=p.shop_id WHERE p.product_id=? AND s.vendor_id=?');
                $owner->execute([$productId, $user['id']]);
                if (!$owner->fetchColumn()) jsonResponse(['success' => false, 'message' => 'Product not found or access denied.'], 404);
                $blackouts = $pdo->prepare('SELECT availability_id,start_date,end_date,reason FROM product_unavailability WHERE product_id=? ORDER BY start_date');
                $blackouts->execute([$productId]);
                jsonResponse(['success' => true, 'unavailable_dates' => $blackouts->fetchAll()]);
            }
            $user = requireRole(['vendor']);
            if ($method === 'POST') {
                $productId = filter_var($data['product_id'] ?? null, FILTER_VALIDATE_INT);
                $startValue = cleanString($data['start_date'] ?? '', 10);
                $endValue = cleanString($data['end_date'] ?? '', 10);
                $start = DateTime::createFromFormat('!Y-m-d', $startValue);
                $end = DateTime::createFromFormat('!Y-m-d', $endValue);
                if (!$productId || !$start || !$end || $end < $start || $start < new DateTime('today')) jsonResponse(['success' => false, 'message' => 'Choose a product and valid current or future unavailable dates.'], 422);
                $owner = $pdo->prepare('SELECT p.product_id FROM products p JOIN shops s ON s.shop_id=p.shop_id WHERE p.product_id=? AND s.vendor_id=?');
                $owner->execute([$productId, $user['id']]);
                if (!$owner->fetchColumn()) jsonResponse(['success' => false, 'message' => 'Product not found or access denied.'], 404);
                $pdo->prepare('INSERT INTO product_unavailability (product_id,start_date,end_date,reason) VALUES (?,?,?,?)')
                    ->execute([$productId, $start->format('Y-m-d'), $end->format('Y-m-d'), cleanString($data['reason'] ?? '', 255)]);
                jsonResponse(['success' => true, 'message' => 'Unavailable rental dates saved.'], 201);
            }
            if ($method === 'DELETE') {
                $availabilityId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
                $delete = $pdo->prepare('DELETE pu FROM product_unavailability pu JOIN products p ON p.product_id=pu.product_id JOIN shops s ON s.shop_id=p.shop_id WHERE pu.availability_id=? AND s.vendor_id=?');
                $delete->execute([$availabilityId, $user['id']]);
                jsonResponse(['success' => true, 'message' => $delete->rowCount() ? 'Unavailable dates removed.' : 'Date block not found or access denied.']);
            }
            jsonResponse(['success' => false, 'message' => 'Method not allowed.'], 405);

        case 'contact':
            requireMethod('POST');
            $name = cleanString($data['name'] ?? '', 120);
            $email = strtolower(cleanString($data['email'] ?? '', 190));
            $subject = cleanString($data['subject'] ?? '', 180);
            $message = cleanString($data['message'] ?? '', 4000);
            if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $message === '') {
                jsonResponse(['success' => false, 'message' => 'Name, valid email and message are required.'], 422);
            }
            $stmt = $pdo->prepare("INSERT INTO contact_messages (name,email,subject,message) VALUES (?,?,?,?)");
            $stmt->execute([$name, $email, $subject, $message]);
            jsonResponse(['success' => true, 'message' => 'Your message has been received.'], 201);

        case 'categories':
            $stmt = $pdo->query("SELECT category_id, category_name, description FROM categories ORDER BY category_id");
            jsonResponse(['success' => true, 'categories' => $stmt->fetchAll()]);

        case 'my-orders':
            $user = requireLogin();
            $stmt = $pdo->prepare("SELECT * FROM orders WHERE user_id=? ORDER BY created_at DESC");
            $stmt->execute([$user['id']]);
            $orders = $stmt->fetchAll();
            $itemStmt = $pdo->prepare("SELECT product_name,quantity,price_per_day,pickup_date,return_date,line_total,size,variant_id FROM order_items WHERE order_id=?");
            foreach ($orders as &$order) {
                $itemStmt->execute([$order['order_id']]);
                $order['items'] = $itemStmt->fetchAll();
            }
            unset($order);
            jsonResponse(['success' => true, 'orders' => $orders]);

        case 'profile':
            $user = requireLogin();
            $stmt = $pdo->prepare("SELECT first_name,last_name,email,phone,role,created_at FROM users WHERE user_id=?");
            $stmt->execute([$user['id']]);
            jsonResponse(['success' => true, 'profile' => $stmt->fetch()]);

        case 'faqs':
            $stmt = $pdo->query("SELECT faq_id,question,answer FROM faqs WHERE status='published' ORDER BY faq_id");
            jsonResponse(['success' => true, 'faqs' => $stmt->fetchAll()]);

        default:
            jsonResponse(['success' => false, 'message' => 'Unknown API action.'], 404);
    }
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('RentAttire API error: ' . $exception->getMessage());
    jsonResponse(['success' => false, 'message' => 'A server error occurred. Check the PHP error log.'], 500);
}
