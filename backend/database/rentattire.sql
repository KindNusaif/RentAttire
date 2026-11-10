CREATE DATABASE IF NOT EXISTS rentattire_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE rentattire_db;

CREATE TABLE IF NOT EXISTS users (
  user_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  first_name VARCHAR(80) NOT NULL,
  last_name VARCHAR(80) NOT NULL DEFAULT '',
  email VARCHAR(190) NOT NULL UNIQUE,
  phone VARCHAR(30) NULL,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('customer','vendor','admin') NOT NULL DEFAULT 'customer',
  status ENUM('active','pending','suspended') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS shops (
  shop_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  vendor_id INT UNSIGNED NOT NULL,
  shop_name VARCHAR(150) NOT NULL,
  description TEXT NULL,
  address VARCHAR(255) NULL,
  phone VARCHAR(30) NULL,
  email VARCHAR(190) NULL,
  logo_path VARCHAR(255) NULL,
  status ENUM('pending','approved','rejected','inactive') NOT NULL DEFAULT 'pending',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_shops_vendor FOREIGN KEY (vendor_id) REFERENCES users(user_id) ON DELETE CASCADE,
  INDEX idx_shops_status (status)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS categories (
  category_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  category_name VARCHAR(100) NOT NULL UNIQUE,
  description VARCHAR(500) NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS size_types (
  size_type_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  type_code VARCHAR(50) NOT NULL UNIQUE,
  type_name VARCHAR(100) NOT NULL,
  category_group ENUM('Men','Women','Kids','Traditional Sri Lankan Wear') NOT NULL,
  description VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS size_options (
  size_option_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  size_type_id INT UNSIGNED NOT NULL,
  size_code VARCHAR(50) NOT NULL,
  display_label VARCHAR(100) NOT NULL,
  uk_size VARCHAR(20) NULL,
  us_size VARCHAR(20) NULL,
  eu_size VARCHAR(20) NULL,
  chest_bust_inches DECIMAL(5,1) NULL,
  waist_inches DECIMAL(5,1) NULL,
  hip_inches DECIMAL(5,1) NULL,
  height_cm DECIMAL(5,1) NULL,
  length_inches DECIMAL(5,1) NULL,
  sort_order INT NOT NULL DEFAULT 0,
  CONSTRAINT fk_size_options_type FOREIGN KEY (size_type_id) REFERENCES size_types(size_type_id) ON DELETE CASCADE,
  INDEX idx_size_options_type (size_type_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS products (
  product_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  shop_id INT UNSIGNED NOT NULL,
  category_id INT UNSIGNED NOT NULL,
  size_type_id INT UNSIGNED NULL,
  product_name VARCHAR(160) NOT NULL,
  description TEXT NULL,
  price_per_day DECIMAL(10,2) NOT NULL,
  deposit_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
  stock INT UNSIGNED NOT NULL DEFAULT 0,
  sizes VARCHAR(100) NOT NULL DEFAULT 'XS,S,M,L,XL',
  size_inventory TEXT NULL,
  size_measurements TEXT NULL,
  custom_measurement_notes TEXT NULL,
  image_path VARCHAR(255) NULL,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_products_shop FOREIGN KEY (shop_id) REFERENCES shops(shop_id) ON DELETE CASCADE,
  CONSTRAINT fk_products_category FOREIGN KEY (category_id) REFERENCES categories(category_id),
  CONSTRAINT fk_products_size_type FOREIGN KEY (size_type_id) REFERENCES size_types(size_type_id) ON DELETE SET NULL,
  INDEX idx_products_status (status),
  INDEX idx_products_category (category_id),
  INDEX idx_products_size_type (size_type_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS product_variants (
  variant_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id INT UNSIGNED NOT NULL,
  size_option_id INT UNSIGNED NULL,
  variant_label VARCHAR(160) NOT NULL,
  sku VARCHAR(60) NULL,
  stock INT UNSIGNED NOT NULL DEFAULT 1,
  price_override DECIMAL(10,2) NULL,
  custom_measurements JSON NULL,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_variants_product FOREIGN KEY (product_id) REFERENCES products(product_id) ON DELETE CASCADE,
  CONSTRAINT fk_variants_option FOREIGN KEY (size_option_id) REFERENCES size_options(size_option_id) ON DELETE SET NULL,
  INDEX idx_variants_product (product_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS product_images (
  image_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id INT UNSIGNED NOT NULL,
  image_path VARCHAR(255) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_product_images_product FOREIGN KEY (product_id) REFERENCES products(product_id) ON DELETE CASCADE,
  INDEX idx_product_images_product (product_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS product_unavailability (
  availability_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id INT UNSIGNED NOT NULL,
  start_date DATE NOT NULL,
  end_date DATE NOT NULL,
  reason VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_product_unavailability_product FOREIGN KEY (product_id) REFERENCES products(product_id) ON DELETE CASCADE,
  INDEX idx_product_unavailability_dates (product_id,start_date,end_date)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS cart_items (
  cart_item_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  product_id INT UNSIGNED NOT NULL,
  variant_id INT UNSIGNED NULL,
  quantity INT UNSIGNED NOT NULL DEFAULT 1,
  size VARCHAR(255) NULL,
  pickup_date DATE NOT NULL,
  return_date DATE NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_cart_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
  CONSTRAINT fk_cart_product FOREIGN KEY (product_id) REFERENCES products(product_id) ON DELETE CASCADE,
  CONSTRAINT fk_cart_variant FOREIGN KEY (variant_id) REFERENCES product_variants(variant_id) ON DELETE SET NULL,
  INDEX idx_cart_user (user_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS orders (
  order_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  rental_total DECIMAL(12,2) NOT NULL,
  deposit_total DECIMAL(12,2) NOT NULL DEFAULT 0,
  grand_total DECIMAL(12,2) NOT NULL,
  status ENUM('pending','confirmed','processing','ready_for_pickup','picked_up','returned','cancelled') NOT NULL DEFAULT 'pending',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_orders_user FOREIGN KEY (user_id) REFERENCES users(user_id),
  INDEX idx_orders_user (user_id),
  INDEX idx_orders_status (status)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS order_items (
  order_item_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id INT UNSIGNED NOT NULL,
  product_id INT UNSIGNED NULL,
  variant_id INT UNSIGNED NULL,
  product_name VARCHAR(160) NOT NULL,
  quantity INT UNSIGNED NOT NULL,
  size VARCHAR(255) NULL,
  vendor_status ENUM('pending','accepted','rejected','preparing','ready_for_pickup','picked_up','returned','cancelled') NOT NULL DEFAULT 'pending',
  price_per_day DECIMAL(10,2) NOT NULL,
  pickup_date DATE NOT NULL,
  return_date DATE NOT NULL,
  line_total DECIMAL(12,2) NOT NULL,
  CONSTRAINT fk_order_items_order FOREIGN KEY (order_id) REFERENCES orders(order_id) ON DELETE CASCADE,
  CONSTRAINT fk_order_items_product FOREIGN KEY (product_id) REFERENCES products(product_id) ON DELETE SET NULL,
  CONSTRAINT fk_order_items_variant FOREIGN KEY (variant_id) REFERENCES product_variants(variant_id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS contact_messages (
  message_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(190) NOT NULL,
  subject VARCHAR(180) NULL,
  message TEXT NOT NULL,
  status ENUM('new','read','replied') NOT NULL DEFAULT 'new',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS faqs (
  faq_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  question VARCHAR(500) NOT NULL,
  answer TEXT NOT NULL,
  status ENUM('published','draft') NOT NULL DEFAULT 'published',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT IGNORE INTO categories (category_name, description) VALUES
('Sarees', 'Traditional Sri Lankan sarees, Osari, and evening sarees.'),
('Bridal Wear', 'Bridal sarees, bridal gowns, pearl and lace wedding dresses.'),
('Suits', 'Formal suits, blazers, and tuxedos for men.'),
('Dresses', 'Evening gowns, cocktail dresses, and party wear.'),
('Traditional Sri Lankan Wear', 'Kandyan Osari, National dress, Sarongs, and Lama Sarees.'),
('Kids & Teens', 'Rental attire for boys and girls of all ages.');
