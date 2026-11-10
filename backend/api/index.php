<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$action = cleanString($_GET['action'] ?? '', 60);
$data = requestData();

try {
    switch ($action) {
        case 'health':
            jsonResponse(['success' => true, 'message' => 'RentAttire API is running.']);

        case 'me':
            jsonResponse(['success' => true, 'user' => currentUser()]);

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
            $password = (string)($data['password'] ?? '');
            if ($shopName === '' || $ownerName === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8) {
                jsonResponse(['success' => false, 'message' => 'Complete all fields and use a password of at least 8 characters.'], 422);
            }
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare("INSERT INTO users (first_name,last_name,email,password_hash,role) VALUES (?,?,?,?,'vendor')");
                $parts = preg_split('/\s+/', $ownerName, 2);
                $stmt->execute([$parts[0] ?? $ownerName, $parts[1] ?? '', $email, password_hash($password, PASSWORD_DEFAULT)]);
                $userId = (int)$pdo->lastInsertId();
                $shop = $pdo->prepare("INSERT INTO shops (vendor_id,shop_name,status) VALUES (?,?, 'pending')");
                $shop->execute([$userId, $shopName]);
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
            if (!$user || !password_verify($password, $user['password_hash']) || $user['status'] !== 'active') {
                jsonResponse(['success' => false, 'message' => 'Invalid email or password, or account not active.'], 401);
            }
            session_regenerate_id(true);
            $_SESSION['user'] = ['id' => (int)$user['user_id'], 'name' => trim($user['first_name'] . ' ' . $user['last_name']), 'email' => $user['email'], 'role' => $user['role']];
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

        case 'products':
            if ($method === 'GET') {
                $sql = "SELECT p.product_id,p.shop_id,p.category_id,p.product_name,p.description,p.price_per_day,p.deposit_amount,p.stock,p.image_path,p.status,c.category_name,s.shop_name FROM products p LEFT JOIN categories c ON c.category_id=p.category_id LEFT JOIN shops s ON s.shop_id=p.shop_id";
                $params = [];
                if (($_GET['manage'] ?? '') !== '1') {
                    $sql .= " WHERE p.status='active' AND s.status='approved'";
                } else {
                    $user = requireRole(['vendor','admin']);
                    if ($user['role'] === 'vendor') { $sql .= " WHERE s.vendor_id=?"; $params[] = $user['id']; }
                }
                $sql .= " ORDER BY p.created_at DESC";
                $stmt = $pdo->prepare($sql); $stmt->execute($params);
                jsonResponse(['success' => true, 'products' => $stmt->fetchAll()]);
            }
            if ($method === 'POST') {
                $user = requireRole(['vendor','admin']);
                $productName = cleanString($data['product_name'] ?? '', 160);
                $description = cleanString($data['description'] ?? '', 3000);
                $price = filter_var($data['price_per_day'] ?? null, FILTER_VALIDATE_FLOAT);
                $stock = filter_var($data['stock'] ?? 0, FILTER_VALIDATE_INT);
                $categoryId = filter_var($data['category_id'] ?? null, FILTER_VALIDATE_INT);
                $shopId = filter_var($data['shop_id'] ?? null, FILTER_VALIDATE_INT);
                if ($productName === '' || $price === false || $price <= 0 || $stock === false || $stock < 0 || !$categoryId || !$shopId) {
                    jsonResponse(['success' => false, 'message' => 'Valid product name, category, shop, daily price and stock are required.'], 422);
                }
                if ($user['role'] === 'vendor') {
                    $check = $pdo->prepare("SELECT shop_id FROM shops WHERE shop_id=? AND vendor_id=?");
                    $check->execute([$shopId, $user['id']]);
                    if (!$check->fetch()) jsonResponse(['success' => false, 'message' => 'You cannot add products to this shop.'], 403);
                }
                $stmt = $pdo->prepare("INSERT INTO products (shop_id,category_id,product_name,description,price_per_day,deposit_amount,stock,image_path,status) VALUES (?,?,?,?,?,?,?,?,?)");
                $stmt->execute([$shopId,$categoryId,$productName,$description,$price,max(0,(float)($data['deposit_amount'] ?? 0)),$stock,cleanString($data['image_path'] ?? '',255),'active']);
                jsonResponse(['success' => true, 'message' => 'Product created.', 'product_id' => (int)$pdo->lastInsertId()], 201);
            }
            if ($method === 'PUT' || $method === 'PATCH') {
                $user = requireRole(['vendor','admin']);
                $id = (int)($_GET['id'] ?? 0);
                if ($id < 1) jsonResponse(['success' => false, 'message' => 'Product ID is required.'], 422);
                $sql = "SELECT p.product_id FROM products p JOIN shops s ON s.shop_id=p.shop_id WHERE p.product_id=?";
                $params = [$id];
                if ($user['role'] === 'vendor') { $sql .= " AND s.vendor_id=?"; $params[] = $user['id']; }
                $check=$pdo->prepare($sql); $check->execute($params);
                if (!$check->fetch()) jsonResponse(['success'=>false,'message'=>'Product not found or access denied.'],404);
                $stmt=$pdo->prepare("UPDATE products SET product_name=?,description=?,price_per_day=?,deposit_amount=?,stock=?,status=? WHERE product_id=?");
                $stmt->execute([cleanString($data['product_name'] ?? '',160),cleanString($data['description'] ?? '',3000),(float)($data['price_per_day'] ?? 0),(float)($data['deposit_amount'] ?? 0),(int)($data['stock'] ?? 0),in_array(($data['status'] ?? 'active'),['active','inactive'],true)?$data['status']:'active',$id]);
                jsonResponse(['success'=>true,'message'=>'Product updated.']);
            }
            if ($method === 'DELETE') {
                $user = requireRole(['vendor','admin']);
                $id = (int)($_GET['id'] ?? 0);
                $sql="DELETE p FROM products p JOIN shops s ON s.shop_id=p.shop_id WHERE p.product_id=?";
                $params=[$id];
                if ($user['role']==='vendor') { $sql.=" AND s.vendor_id=?"; $params[]=$user['id']; }
                $stmt=$pdo->prepare($sql); $stmt->execute($params);
                jsonResponse(['success'=>true,'message'=>$stmt->rowCount()?'Product deleted.':'Product not found or access denied.']);
            }
            jsonResponse(['success'=>false,'message'=>'Method not allowed.'],405);

        case 'cart':
            $user = requireLogin();
            if ($method === 'GET') {
                $stmt=$pdo->prepare("SELECT ci.cart_item_id,ci.product_id,ci.quantity,ci.pickup_date,ci.return_date,p.product_name,p.price_per_day,p.image_path,DATEDIFF(ci.return_date,ci.pickup_date) AS rental_days,(p.price_per_day*ci.quantity*DATEDIFF(ci.return_date,ci.pickup_date)) AS line_total FROM cart_items ci JOIN products p ON p.product_id=ci.product_id WHERE ci.user_id=? ORDER BY ci.created_at DESC");
                $stmt->execute([$user['id']]);
                jsonResponse(['success'=>true,'items'=>$stmt->fetchAll()]);
            }
            if ($method === 'POST') {
                $productId=(int)($data['product_id']??0); $qty=max(1,(int)($data['quantity']??1));
                $pickup=$data['pickup_date']??''; $return=$data['return_date']??'';
                $start=DateTime::createFromFormat('Y-m-d',(string)$pickup); $end=DateTime::createFromFormat('Y-m-d',(string)$return);
                if (!$start || !$end || $end <= $start || $start < new DateTime('today')) jsonResponse(['success'=>false,'message'=>'Choose valid future pickup and return dates.'],422);
                $check=$pdo->prepare("SELECT product_id FROM products WHERE product_id=? AND status='active' AND stock>=?");
                $check->execute([$productId,$qty]);
                if (!$check->fetch()) jsonResponse(['success'=>false,'message'=>'Product is unavailable or stock is insufficient.'],404);
                $stmt=$pdo->prepare("INSERT INTO cart_items (user_id,product_id,quantity,pickup_date,return_date) VALUES (?,?,?,?,?)");
                $stmt->execute([$user['id'],$productId,$qty,$start->format('Y-m-d'),$end->format('Y-m-d')]);
                jsonResponse(['success'=>true,'message'=>'Added to cart.'],201);
            }
            if ($method === 'DELETE') {
                $id=(int)($_GET['id']??0);
                $stmt=$pdo->prepare("DELETE FROM cart_items WHERE cart_item_id=? AND user_id=?");
                $stmt->execute([$id,$user['id']]);
                jsonResponse(['success'=>true,'message'=>'Cart item removed.']);
            }
            jsonResponse(['success'=>false,'message'=>'Method not allowed.'],405);

        case 'checkout':
            requireMethod('POST');
            $user=requireRole(['customer']);
            $pdo->beginTransaction();
            $items=$pdo->prepare("SELECT ci.*,p.price_per_day,p.deposit_amount,p.stock,p.product_name FROM cart_items ci JOIN products p ON p.product_id=ci.product_id WHERE ci.user_id=? FOR UPDATE");
            $items->execute([$user['id']]); $cartItems=$items->fetchAll();
            if (!$cartItems) { $pdo->rollBack(); jsonResponse(['success'=>false,'message'=>'Your cart is empty.'],422); }
            $total=0; $deposit=0;
            foreach ($cartItems as $item) {
                $days=(new DateTime($item['pickup_date']))->diff(new DateTime($item['return_date']))->days;
                if ($days < 1 || (int)$item['quantity'] > (int)$item['stock']) { $pdo->rollBack(); jsonResponse(['success'=>false,'message'=>'A cart item is no longer available.'],409); }
                $total += (float)$item['price_per_day']*(int)$item['quantity']*$days;
                $deposit += (float)$item['deposit_amount']*(int)$item['quantity'];
            }
            $order=$pdo->prepare("INSERT INTO orders (user_id,rental_total,deposit_total,grand_total,status) VALUES (?,?,?,?,'pending')");
            $order->execute([$user['id'],$total,$deposit,$total+$deposit]); $orderId=(int)$pdo->lastInsertId();
            $line=$pdo->prepare("INSERT INTO order_items (order_id,product_id,product_name,quantity,price_per_day,pickup_date,return_date,line_total) VALUES (?,?,?,?,?,?,?,?)");
            foreach ($cartItems as $item) {
                $days=(new DateTime($item['pickup_date']))->diff(new DateTime($item['return_date']))->days;
                $line->execute([$orderId,$item['product_id'],$item['product_name'],$item['quantity'],$item['price_per_day'],$item['pickup_date'],$item['return_date'],(float)$item['price_per_day']*(int)$item['quantity']*$days]);
            }
            $pdo->prepare("DELETE FROM cart_items WHERE user_id=?")->execute([$user['id']]);
            $pdo->commit();
            jsonResponse(['success'=>true,'message'=>'Rental booking placed.','order_id'=>$orderId,'grand_total'=>$total+$deposit],201);

        case 'orders':
            $user=requireLogin();
            if ($method==='GET') {
                if ($user['role']==='admin') {
                    $stmt=$pdo->query("SELECT o.*,u.first_name,u.last_name,u.email FROM orders o JOIN users u ON u.user_id=o.user_id ORDER BY o.created_at DESC");
                    jsonResponse(['success'=>true,'orders'=>$stmt->fetchAll()]);
                }
                $stmt=$pdo->prepare("SELECT * FROM orders WHERE user_id=? ORDER BY created_at DESC");
                $stmt->execute([$user['id']]);
                jsonResponse(['success'=>true,'orders'=>$stmt->fetchAll()]);
            }
            jsonResponse(['success'=>false,'message'=>'Method not allowed.'],405);

        case 'order-items':
            $user=requireLogin(); $orderId=(int)($_GET['order_id']??0);
            $sql="SELECT oi.* FROM order_items oi JOIN orders o ON o.order_id=oi.order_id WHERE oi.order_id=?";
            $params=[$orderId];
            if ($user['role']!=='admin') { $sql.=" AND o.user_id=?"; $params[]=$user['id']; }
            $stmt=$pdo->prepare($sql); $stmt->execute($params);
            jsonResponse(['success'=>true,'items'=>$stmt->fetchAll()]);

        case 'update-order':
            requireMethod('POST');
            requireRole(['admin']);
            $id=(int)($data['order_id']??0); $status=$data['status']??'';
            if (!in_array($status,['pending','confirmed','processing','ready_for_pickup','picked_up','returned','cancelled'],true)) jsonResponse(['success'=>false,'message'=>'Invalid order status.'],422);
            $stmt=$pdo->prepare("UPDATE orders SET status=? WHERE order_id=?"); $stmt->execute([$status,$id]);
            jsonResponse(['success'=>true,'message'=>'Order status updated.']);

        case 'contact':
            requireMethod('POST');
            $name=cleanString($data['name']??'',120); $email=strtolower(cleanString($data['email']??'',190)); $subject=cleanString($data['subject']??'',180); $message=cleanString($data['message']??'',4000);
            if ($name==='' || !filter_var($email,FILTER_VALIDATE_EMAIL) || $message==='') jsonResponse(['success'=>false,'message'=>'Name, valid email and message are required.'],422);
            $stmt=$pdo->prepare("INSERT INTO contact_messages (name,email,subject,message) VALUES (?,?,?,?)"); $stmt->execute([$name,$email,$subject,$message]);
            jsonResponse(['success'=>true,'message'=>'Your message has been received.'],201);

        case 'categories':
            $stmt=$pdo->query("SELECT category_id,category_name FROM categories ORDER BY category_name");
            jsonResponse(['success'=>true,'categories'=>$stmt->fetchAll()]);

        case 'shops':
            $stmt=$pdo->query("SELECT s.shop_id,s.shop_name,s.description,s.address,s.phone,s.email,u.first_name,u.last_name FROM shops s JOIN users u ON u.user_id=s.vendor_id WHERE s.status='approved' ORDER BY s.shop_name");
            jsonResponse(['success'=>true,'shops'=>$stmt->fetchAll()]);

        default:
            jsonResponse(['success'=>false,'message'=>'Unknown API action.'],404);
    }
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('RentAttire API error: '.$exception->getMessage());
    jsonResponse(['success'=>false,'message'=>'A server error occurred. Check the PHP error log.'],500);
}
