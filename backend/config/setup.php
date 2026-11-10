<?php
declare(strict_types=1);

/*
 * RentAttire one-command setup (safe to run many times).
 *   C:\wamp64\bin\php\php8.3.28\php.exe C:\wamp64\www\build\backend\config\setup.php
 * 1) adds the size columns if missing  2) creates test accounts, shops, products, FAQs
 * Delete this file after use.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found');
}

require_once __DIR__ . '/database.php';

function say(string $message): void
{
    echo $message . PHP_EOL;
}

function addColumn(PDO $pdo, string $table, string $column, string $definition): void
{
    $check = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $check->execute([$table, $column]);
    if ((int)$check->fetchColumn() === 0) {
        $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
        say("Added column $table.$column");
    }
}

function ensureUser(PDO $pdo, string $first, string $last, string $email, string $phone, string $password, string $role): int
{
    $find = $pdo->prepare('SELECT user_id FROM users WHERE email = ?');
    $find->execute([$email]);
    $id = $find->fetchColumn();
    if ($id) {
        $pdo->prepare("UPDATE users SET role = ?, status = 'active' WHERE user_id = ?")->execute([$role, $id]);
        say("User exists, role set to $role: $email");
        return (int)$id;
    }
    $pdo->prepare("INSERT INTO users (first_name, last_name, email, phone, password_hash, role, status) VALUES (?, ?, ?, ?, ?, ?, 'active')")
        ->execute([$first, $last, $email, $phone, password_hash($password, PASSWORD_DEFAULT), $role]);
    say("User created ($role): $email");
    return (int)$pdo->lastInsertId();
}

function ensureShop(PDO $pdo, int $vendorId, string $name, string $description, string $address, string $phone, string $email): int
{
    $find = $pdo->prepare('SELECT shop_id FROM shops WHERE shop_name = ?');
    $find->execute([$name]);
    $id = $find->fetchColumn();
    if ($id) {
        $pdo->prepare("UPDATE shops SET vendor_id = ?, status = 'approved' WHERE shop_id = ?")->execute([$vendorId, $id]);
        say("Shop exists, approved: $name");
        return (int)$id;
    }
    $pdo->prepare("INSERT INTO shops (vendor_id, shop_name, description, address, phone, email, status) VALUES (?, ?, ?, ?, ?, ?, 'approved')")
        ->execute([$vendorId, $name, $description, $address, $phone, $email]);
    say("Shop created: $name");
    return (int)$pdo->lastInsertId();
}

try {
    // Step 1: schema upgrade (ALTER TABLE commits by itself, so it runs before the transaction)
    addColumn($pdo, 'products', 'sizes', "VARCHAR(100) NOT NULL DEFAULT 'XS,S,M,L,XL'");
    addColumn($pdo, 'cart_items', 'size', 'VARCHAR(20) NULL');
    addColumn($pdo, 'order_items', 'size', 'VARCHAR(20) NULL');

    // Step 2: data
    $pdo->beginTransaction();

    $pdo->exec("INSERT IGNORE INTO categories (category_name) VALUES ('Sarees'), ('Bridal Wear'), ('Suits'), ('Dresses')");
    $categoryIds = array_flip($pdo->query('SELECT category_id, category_name FROM categories')->fetchAll(PDO::FETCH_KEY_PAIR));

    $vendorId = ensureUser($pdo, 'Demo', 'Vendor', 'vendor@test.com', '0771234567', 'Vendor@12345', 'vendor');
    ensureUser($pdo, 'Demo', 'Customer', 'customer@test.com', '0777654321', 'Customer@12345', 'customer');
    ensureUser($pdo, 'Site', 'Admin', 'admin@test.com', '0770000000', 'Admin@123456', 'admin');

    $silk = ensureShop($pdo, $vendorId, 'Colombo Silk House', 'Sarees, bridal wear and dresses', 'Colombo', '0112345678', 'silk@example.com');
    $kandy = ensureShop($pdo, $vendorId, 'Kandy Formal Wear', 'Formal suits for every occasion', 'Kandy', '0812345678', 'kandy@example.com');

    $products = [
        ['Royal Red Saree',        $silk,  'Sarees',      'A rich red saree for weddings and celebrations.', 2500, 1000, 5, 'images/saree_royal_red.png'],
        ['Classic Black Suit',     $kandy, 'Suits',       'A classic black suit for formal events.',          3000, 1000, 4, 'images/suit_classic_black.png'],
        ['Pearl Bridal Dress',     $silk,  'Bridal Wear', 'An elegant pearl bridal dress.',                   5000, 2000, 2, 'images/bridal_model.png'],
        ['Emerald Evening Dress',  $silk,  'Dresses',     'A refined emerald dress for parties and galas.',   3500, 1000, 5, 'images/evening_dress.png'],
        ['Traditional Gold Saree', $silk,  'Sarees',      'A gold-toned traditional saree.',                  2800, 1000, 4, 'images/saree_model.png'],
        ['Midnight Blue Suit',     $kandy, 'Suits',       'A polished midnight blue suit.',                   3200, 1000, 0, 'images/suit_model.png'],
        ['Ivory Bridal Gown',      $silk,  'Bridal Wear', 'A soft ivory bridal gown.',                        5500, 2000, 3, 'images/evory.jpg'],
        ['Rose Party Dress',       $silk,  'Dresses',     'A romantic rose party dress.',                     2900, 1000, 2, 'images/evening_dress.png'],
    ];

    $exists = $pdo->prepare('SELECT product_id FROM products WHERE product_name = ?');
    $insert = $pdo->prepare("INSERT INTO products (shop_id, category_id, product_name, description, price_per_day, deposit_amount, stock, sizes, image_path, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'XS,S,M,L,XL,2XL,3XL', ?, 'active')");
    $added = 0;
    foreach ($products as [$name, $shopId, $category, $description, $price, $deposit, $stock, $image]) {
        $exists->execute([$name]);
        if ($exists->fetchColumn()) {
            continue;
        }
        $insert->execute([$shopId, $categoryIds[$category], $name, $description, $price, $deposit, $stock, $image]);
        $added++;
    }
    say("Products added: $added (existing ones skipped)");

    if ((int)$pdo->query('SELECT COUNT(*) FROM faqs')->fetchColumn() === 0) {
        $faq = $pdo->prepare('INSERT INTO faqs (question, answer) VALUES (?, ?)');
        $faq->execute(['How does renting work?', 'Choose an outfit, pick your size and rental dates, add it to your cart and place the booking.']);
        $faq->execute(['What is the deposit?', 'A refundable deposit is held for each item and returned when the outfit is returned in good condition.']);
        $faq->execute(['Which size standards are shown?', 'Sizes can be viewed in US, UK, EU and IN standards, with a conversion guide on every product page.']);
        $faq->execute(['How do I list my shop?', 'Use the List Your Shop page. An administrator approves new shops before products appear.']);
        say('FAQs added');
    }

    $pdo->commit();

    say('');
    say('DONE. Total products: ' . $pdo->query('SELECT COUNT(*) FROM products')->fetchColumn());
    say('Test logins (local testing only):');
    say('  customer@test.com / Customer@12345');
    say('  vendor@test.com   / Vendor@12345');
    say('  admin@test.com    / Admin@123456');
    say('Delete setup.php now.');
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'FAILED: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
