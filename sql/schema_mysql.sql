CREATE DATABASE IF NOT EXISTS hv_website CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE hv_website;

CREATE TABLE IF NOT EXISTS pending_registrations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  contact_name VARCHAR(255) NOT NULL,
  company_name VARCHAR(255) NOT NULL,
  tax_id VARCHAR(100) DEFAULT '',
  address VARCHAR(500) DEFAULT '',
  email VARCHAR(255) NOT NULL,
  phone VARCHAR(50) DEFAULT '',
  mobile VARCHAR(50) DEFAULT '',
  has_whatsapp TINYINT(1) DEFAULT 0,
  password_hash VARCHAR(255) NOT NULL,
  status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
  fullvendor_customer_id INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY email (email)
);

CREATE TABLE IF NOT EXISTS `users` (
  `userId` BIGINT NOT NULL AUTO_INCREMENT,
  `username` VARCHAR(300) NOT NULL DEFAULT '0',
  `password` VARCHAR(300) NOT NULL DEFAULT '0',
  `rolId` INT NOT NULL DEFAULT 0,
  `customerId` BIGINT NOT NULL DEFAULT 0,
  PRIMARY KEY (`userId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `customers` (
  `customer_id` BIGINT NOT NULL AUTO_INCREMENT,
  `customeridfullvendor` BIGINT NULL DEFAULT NULL,
  `language_id` INT DEFAULT 1,
  `company_id` INT NOT NULL,
  `user_id` VARCHAR(255) DEFAULT ' ',
  `name` VARCHAR(255) DEFAULT ' ',
  `business_name` VARCHAR(255) DEFAULT ' ',
  `tax_id` VARCHAR(255) DEFAULT ' ',
  `term_id` INT DEFAULT NULL,
  `term_name` VARCHAR(255) DEFAULT ' ',
  `group_id` INT DEFAULT NULL,
  `group_name` VARCHAR(255) DEFAULT ' ',
  `percentage_on_price` FLOAT DEFAULT 0,
  `percent_price_amount` FLOAT DEFAULT 0,
  `email` VARCHAR(255) DEFAULT ' ',
  `phone` VARCHAR(255) DEFAULT ' ',
  `cell_phone` VARCHAR(255) DEFAULT ' ',
  `notes` VARCHAR(50) DEFAULT ' ',
  `commercial_address` VARCHAR(255) DEFAULT ' ',
  `commercial_delivery_address` VARCHAR(255) DEFAULT ' ',
  `commercial_country` VARCHAR(255) DEFAULT ' ',
  `commercial_state` VARCHAR(255) DEFAULT ' ',
  `commercial_city` VARCHAR(255) DEFAULT ' ',
  `commercial_zone` VARCHAR(255) DEFAULT ' ',
  `commercial_zip_code` VARCHAR(255) DEFAULT ' ',
  `dispatch_address` VARCHAR(255) DEFAULT ' ',
  `dispatch_delivery_address` VARCHAR(255) DEFAULT ' ',
  `dispatch_country` VARCHAR(255) DEFAULT ' ',
  `dispatch_state` VARCHAR(255) DEFAULT ' ',
  `dispatch_city` VARCHAR(255) DEFAULT ' ',
  `dispatch_zone` VARCHAR(255) DEFAULT ' ',
  `dispatch_zip_code` VARCHAR(255) DEFAULT ' ',
  `dispatch_shipping_notes` VARCHAR(50) DEFAULT ' ',
  `catalog_emails` TINYINT DEFAULT 0,
  `customer_created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `customer_status` TINYINT NOT NULL DEFAULT 1,
  `cust_id_kor` VARCHAR(50) DEFAULT '' COMMENT 'Code',
  `id_kor` BIGINT DEFAULT 0,
  `assign_catalog` INT DEFAULT 0,
  `discount` FLOAT DEFAULT 0,
  `modified_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `password` VARCHAR(250) DEFAULT NULL,
  `balance` DOUBLE(15,2) DEFAULT 0.00,
  `sales` DOUBLE(15,2) DEFAULT 0.00,
  UNIQUE KEY `uq_customeridfullvendor` (`customeridfullvendor`),
  PRIMARY KEY (`customer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `catalog_categories` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `language_id` VARCHAR(8) NOT NULL,
  `category_id` VARCHAR(64) NOT NULL,
  `json_payload` LONGTEXT NOT NULL,
  `synced_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_catalog_category_lang` (`language_id`, `category_id`),
  KEY `idx_category_id` (`category_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `catalog_products` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `language_id` VARCHAR(8) NOT NULL,
  `product_id` VARCHAR(64) NOT NULL,
  `json_payload` LONGTEXT NOT NULL,
  `synced_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_catalog_product_lang` (`language_id`, `product_id`),
  KEY `idx_product_id` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `catalog_sync_state` (
  `id` TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `status` VARCHAR(24) NOT NULL DEFAULT 'idle',
  `started_at` DATETIME NULL,
  `finished_at` DATETIME NULL,
  `last_error` TEXT NULL,
  `result_json` LONGTEXT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO `catalog_sync_state` (`id`, `status`) VALUES (1, 'idle');

CREATE TABLE IF NOT EXISTS `usersList` (
  `user_id` BIGINT NOT NULL,
  `unique_id` VARCHAR(255) DEFAULT NULL,
  `company_id` BIGINT DEFAULT NULL,
  `first_name` VARCHAR(255) DEFAULT '',
  `last_name` VARCHAR(255) DEFAULT '',
  `username` VARCHAR(255) DEFAULT '',
  `email` VARCHAR(255) DEFAULT '',
  `password` VARCHAR(255) DEFAULT '',
  `profile` INT DEFAULT NULL,
  `phone_number` VARCHAR(255) DEFAULT '',
  `cell_number` VARCHAR(255) DEFAULT '',
  `fax` VARCHAR(255) DEFAULT '',
  `profile_image` VARCHAR(255) DEFAULT '',
  `email_verification` TINYINT DEFAULT 0,
  `otp` VARCHAR(255) DEFAULT '',
  `created` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `status` SMALLINT DEFAULT 1,
  `id_kor` BIGINT DEFAULT 0,
  `default` INT DEFAULT NULL,
  `token` TEXT,
  `add_customer` INT NOT NULL,
  `update_customer` INT NOT NULL,
  `send_catalog` INT NOT NULL,
  `all_customers` INT DEFAULT 0,
  `proforma` INT NOT NULL DEFAULT 0,
  `email_on_salesman_order` INT DEFAULT 1,
  `json_payload` LONGTEXT NOT NULL,
  PRIMARY KEY (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `orders` (
  `tipo_d` varchar(1) DEFAULT NULL,
  `id` bigint NOT NULL AUTO_INCREMENT,
  `order_id` bigint NOT NULL,
  `UUID` char(36) DEFAULT NULL,
  `order_number` varchar(255) DEFAULT NULL,
  `company_id` bigint DEFAULT NULL,
  `user_id` bigint DEFAULT NULL COMMENT 'Pk of vendors',
  `language_id` tinyint NOT NULL COMMENT 'PK of languages',
  `customer_id` bigint NOT NULL COMMENT 'PK of customers',
  `payment_method` tinyint NOT NULL DEFAULT 0 COMMENT '0-CashOn Delivery, 1-Payment Gateway',
  `payment_status` tinyint NOT NULL DEFAULT 0 COMMENT '0-Due, 1-Paid, 2-Refund, 3-Settled',
  `transaction_id` varchar(255) DEFAULT NULL,
  `order_comments` text,
  `order_status` int NOT NULL DEFAULT 0 COMMENT '0-Placed through 12-Rejected (see spec)',
  `delivery_status` int DEFAULT NULL COMMENT '1=Full Delivered, 0=Partial Delivered',
  `discount` float(12,2) NOT NULL DEFAULT 0.00,
  `discount_type` tinyint NOT NULL DEFAULT 1 COMMENT '1 = in %, 2 = in $ ',
  `created` datetime DEFAULT NULL,
  `updated` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `approved_by` varchar(255) DEFAULT '',
  `delivery_notes` text,
  `warehouse_user_id` int DEFAULT NULL COMMENT 'PK of warehouse_users',
  `warehouse_assign_date` datetime DEFAULT NULL,
  `source` varchar(50) NOT NULL DEFAULT 'app',
  `internal_notes` text NOT NULL,
  `token_pass` varchar(255) DEFAULT NULL,
  `date_confirmed` datetime DEFAULT NULL,
  `send_email_order` datetime DEFAULT NULL,
  `date_rejected_email` datetime DEFAULT NULL,
  `date_expired_email` datetime DEFAULT NULL,
  `expiration_days` int DEFAULT NULL,
  `amount` double DEFAULT 0,
  `discount_a` double DEFAULT 0,
  `total_amount` double DEFAULT 0,
  `amount_delivered` double DEFAULT 0,
  `discount_delivered` double DEFAULT 0,
  `total_delivered` double DEFAULT 0,
  `portal_vendor` int DEFAULT NULL,
  `virified_byportal` datetime DEFAULT NULL,
  `id_kor` bigint DEFAULT NULL,
  `cart_id` int DEFAULT NULL,
  `catalog_id` int DEFAULT NULL,
  `update_bybackend` int DEFAULT 0,
  `discount_type_since_movil` int DEFAULT NULL,
  `discount_movil` decimal(20,2) DEFAULT NULL,
  `leido` int DEFAULT 0,
  `proforma` int DEFAULT 0,
  `new_address` varchar(250) NOT NULL,
  `new_address_json` varchar(500) DEFAULT NULL,
  PRIMARY KEY (`order_id`),
  UNIQUE KEY `uuid_uk` (`UUID`),
  KEY `idx_orders_auto_id` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `order_details` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `detail_id` bigint NOT NULL,
  `order_id` bigint NOT NULL COMMENT 'PK of orders',
  `product_id` bigint NOT NULL COMMENT 'PK of products',
  `qty` double(8,2) NOT NULL DEFAULT 0.00,
  `delivered_quantity` double NOT NULL,
  `discount` float NOT NULL,
  `discount_type` tinyint NOT NULL DEFAULT 1 COMMENT '1 = in %, 2 = in $',
  `sale_price` double(16,2) NOT NULL DEFAULT 0.00,
  `fob_price` double(8,2) NOT NULL DEFAULT 0.00,
  `sale_price_app` double(16,2) NOT NULL DEFAULT 0.00,
  `purchase_price` double(8,2) NOT NULL DEFAULT 0.00,
  `comment` text NOT NULL,
  `created` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `id_kor` bigint NOT NULL,
  `comments` text NOT NULL,
  `modify_date` timestamp NULL DEFAULT NULL,
  `fixed_pricedate` timestamp NULL DEFAULT NULL,
  `amount_sales` double DEFAULT 0,
  `discount_amount` double DEFAULT 0,
  `total_amount` double DEFAULT 0,
  `amount_delivered` double DEFAULT 0,
  `discount_delivered` double DEFAULT 0,
  `total_delivered` double DEFAULT 0,
  `amount` double DEFAULT 0,
  `discountt` double DEFAULT 0,
  `delivered_date` timestamp NULL DEFAULT NULL,
  `total` double DEFAULT 0,
  `groupcustomer` varchar(200) DEFAULT NULL,
  `tipolista` varchar(200) DEFAULT NULL,
  `perc_price` double(16,2) DEFAULT NULL,
  `salesp` double(16,2) DEFAULT NULL,
  `impprice` double(16,2) DEFAULT NULL,
  `totalprice` double(16,2) DEFAULT NULL,
  `import_price` double(16,2) DEFAULT 0.00,
  `pack` double DEFAULT 0,
  `delivered_pack` double DEFAULT NULL,
  `company_id` int DEFAULT 0,
  PRIMARY KEY (`detail_id`),
  UNIQUE KEY `OrderUnique` (`order_id`,`product_id`),
  KEY `idx_order_details_auto_id` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `cart_orders` (
  `cartId` BIGINT NOT NULL AUTO_INCREMENT,
  `userId` BIGINT NOT NULL COMMENT 'Usuario logueado (users.userId)',
  `sellerId` BIGINT NOT NULL DEFAULT 0 COMMENT 'rolId=3: users.customerId; rolId=2: primer id en customers.user_id (CSV)',
  `discount` DOUBLE NOT NULL DEFAULT 0,
  `amount` DOUBLE NOT NULL DEFAULT 0,
  `fecha` DATE NOT NULL,
  PRIMARY KEY (`cartId`),
  KEY `idx_cart_orders_userId` (`userId`),
  KEY `idx_cart_orders_fecha` (`fecha`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `cart_details` (
  `detailId` BIGINT NOT NULL AUTO_INCREMENT,
  `cartId` BIGINT NOT NULL COMMENT 'FK cart_orders.cartId',
  `productId` BIGINT NOT NULL,
  `sales_price` DOUBLE NOT NULL DEFAULT 0,
  `qty` DOUBLE NOT NULL DEFAULT 0,
  `amount` DOUBLE NOT NULL DEFAULT 0,
  PRIMARY KEY (`detailId`),
  KEY `idx_cart_details_cartId` (`cartId`),
  CONSTRAINT `fk_cart_details_cart_orders`
    FOREIGN KEY (`cartId`) REFERENCES `cart_orders` (`cartId`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `portal_cart_header` (
  `userId` BIGINT NOT NULL COMMENT 'Sesión Auth userId (FullVendor user)',
  `customerId` BIGINT NOT NULL DEFAULT 0,
  `discount` DOUBLE NOT NULL DEFAULT 0,
  `percentage_on_price` DOUBLE NOT NULL DEFAULT 0,
  `percent_price_amount` DOUBLE NOT NULL DEFAULT 0,
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`userId`),
  KEY `idx_portal_cart_header_customer` (`customerId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `portal_cart_item` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `userId` BIGINT NOT NULL,
  `customerId` BIGINT NOT NULL DEFAULT 0,
  `rolId` INT NOT NULL DEFAULT 0,
  `productId` BIGINT NOT NULL,
  `qty` DOUBLE NOT NULL DEFAULT 0,
  `sales_price` DOUBLE NOT NULL DEFAULT 0,
  `fob_price` DOUBLE NOT NULL DEFAULT 0,
  `amount` DOUBLE NOT NULL DEFAULT 0,
  `product_name` VARCHAR(512) NOT NULL DEFAULT '',
  `sku` VARCHAR(255) NOT NULL DEFAULT '',
  `image` VARCHAR(1024) NOT NULL DEFAULT '',
  `moq` INT NOT NULL DEFAULT 1,
  `line_note` TEXT NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_portal_cart_item_user_product` (`userId`, `productId`),
  KEY `idx_portal_cart_item_user` (`userId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `seller_catalog_user_state` (
  `user_id` BIGINT NOT NULL COMMENT 'Vendedor: userId FullVendor en sesión',
  `company_id` INT NOT NULL DEFAULT 0,
  `selected_customer_fv` BIGINT NULL DEFAULT NULL COMMENT 'customers.customeridfullvendor',
  `carts_json` LONGTEXT NULL COMMENT 'JSON: fvId -> líneas de carrito',
  `order_notes` TEXT NULL DEFAULT NULL COMMENT 'Notas generales del pedido (vendedor)',
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`, `company_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `customer_catalog_user_state` (
  `customer_fv_id` BIGINT NOT NULL COMMENT 'customers.customeridfullvendor (sesión customerId rol 3)',
  `company_id` INT NOT NULL DEFAULT 0,
  `primary_seller_user_id` BIGINT NULL DEFAULT NULL COMMENT 'Primer ID en customers.user_id (CSV)',
  `carts_json` LONGTEXT NULL COMMENT 'JSON: bucket -> líneas (clave = primary_seller o 0)',
  `order_notes` TEXT NULL DEFAULT NULL COMMENT 'Notas del pedido (cliente)',
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`customer_fv_id`, `company_id`),
  KEY `idx_customer_catalog_state_seller` (`primary_seller_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `customer_groups` (
  `group_id` BIGINT NOT NULL AUTO_INCREMENT,
  `language_id` INT NULL DEFAULT NULL,
  `company_id` INT NULL DEFAULT NULL,
  `user_id` INT NULL DEFAULT NULL,
  `name` VARCHAR(255) NULL DEFAULT NULL,
  `percentage_on_price` VARCHAR(255) NULL DEFAULT NULL,
  `percent_price_amount` DOUBLE NULL DEFAULT NULL,
  `created_at` DATETIME NULL DEFAULT NULL,
  `group_status` TINYINT NOT NULL DEFAULT 1,
  `id_kor` BIGINT NOT NULL DEFAULT 1,
  `default` INT NULL DEFAULT NULL,
  PRIMARY KEY (`group_id`),
  KEY `idx_customer_groups_company_lang` (`company_id`, `language_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `customers_request` (
  `customer_id` BIGINT NOT NULL AUTO_INCREMENT,
  `language_id` INT DEFAULT 1,
  `approved` INT DEFAULT 0,
  `company_id` INT NOT NULL,
  `user_id` VARCHAR(255) DEFAULT ' ',
  `name` VARCHAR(255) DEFAULT ' ',
  `business_name` VARCHAR(255) DEFAULT ' ',
  `tax_id` VARCHAR(255) DEFAULT ' ',
  `term_id` INT DEFAULT NULL,
  `term_name` VARCHAR(255) DEFAULT ' ',
  `group_id` INT DEFAULT NULL,
  `group_name` VARCHAR(255) DEFAULT ' ',
  `percentage_on_price` FLOAT DEFAULT 0,
  `percent_price_amount` FLOAT DEFAULT 0,
  `email` VARCHAR(255) DEFAULT ' ',
  `phone` VARCHAR(255) DEFAULT ' ',
  `cell_phone` VARCHAR(255) DEFAULT ' ',
  `notes` VARCHAR(50) DEFAULT ' ',
  `commercial_address` VARCHAR(255) DEFAULT ' ',
  `commercial_delivery_address` VARCHAR(255) DEFAULT ' ',
  `commercial_country` VARCHAR(255) DEFAULT ' ',
  `commercial_state` VARCHAR(255) DEFAULT ' ',
  `commercial_city` VARCHAR(255) DEFAULT ' ',
  `commercial_zone` VARCHAR(255) DEFAULT ' ',
  `commercial_zip_code` VARCHAR(255) DEFAULT ' ',
  `dispatch_address` VARCHAR(255) DEFAULT ' ',
  `dispatch_delivery_address` VARCHAR(255) DEFAULT ' ',
  `dispatch_country` VARCHAR(255) DEFAULT ' ',
  `dispatch_state` VARCHAR(255) DEFAULT ' ',
  `dispatch_city` VARCHAR(255) DEFAULT ' ',
  `dispatch_zone` VARCHAR(255) DEFAULT ' ',
  `dispatch_zip_code` VARCHAR(255) DEFAULT ' ',
  `dispatch_shipping_notes` VARCHAR(50) DEFAULT ' ',
  `catalog_emails` TINYINT DEFAULT 0,
  `customer_created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `customer_status` TINYINT NOT NULL DEFAULT 1,
  `cust_id_kor` VARCHAR(50) DEFAULT '' COMMENT 'Code',
  `id_kor` BIGINT DEFAULT 0,
  `assign_catalog` INT DEFAULT 0,
  `discount` FLOAT DEFAULT 0,
  `modified_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `password` VARCHAR(250) DEFAULT NULL,
  PRIMARY KEY (`customer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `messaging_conversation` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_low` BIGINT NOT NULL COMMENT 'users.userId (PK local)',
  `user_high` BIGINT NOT NULL COMMENT 'users.userId (PK local)',
  `last_message_at` DATETIME(3) NULL DEFAULT NULL,
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_messaging_pair` (`user_low`, `user_high`),
  KEY `idx_messaging_conv_last` (`last_message_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `messaging_message` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `conversation_id` BIGINT UNSIGNED NOT NULL,
  `sender_user_id` BIGINT NOT NULL,
  `body` TEXT NULL,
  `msg_type` ENUM('text','image','video','audio','file') NOT NULL DEFAULT 'text',
  `file_rel_path` VARCHAR(512) NULL DEFAULT NULL,
  `file_name` VARCHAR(255) NULL DEFAULT NULL,
  `mime_type` VARCHAR(127) NULL DEFAULT NULL,
  `file_size` INT UNSIGNED NULL DEFAULT NULL,
  `public_token` CHAR(32) NOT NULL,
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  KEY `idx_messaging_msg_conv_id` (`conversation_id`, `id`),
  KEY `idx_messaging_msg_token` (`public_token`),
  CONSTRAINT `fk_messaging_msg_conv` FOREIGN KEY (`conversation_id`) REFERENCES `messaging_conversation` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `messaging_read` (
  `conversation_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT NOT NULL,
  `last_read_message_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `updated_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`conversation_id`, `user_id`),
  CONSTRAINT `fk_messaging_read_conv` FOREIGN KEY (`conversation_id`) REFERENCES `messaging_conversation` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
