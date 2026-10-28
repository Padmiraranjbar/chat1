CREATE DATABASE IF NOT EXISTS accounting_app CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE accounting_app;

CREATE TABLE IF NOT EXISTS settings (
  id INT PRIMARY KEY AUTO_INCREMENT,
  business_name VARCHAR(190) NOT NULL DEFAULT 'کسب‌وکار من',
  business_type ENUM('store','company','personal','custom') NOT NULL DEFAULT 'store',
  currency VARCHAR(20) NOT NULL DEFAULT 'تومان'
) ENGINE=InnoDB;

INSERT INTO settings (id,business_name,business_type,currency)
SELECT 1,'کسب‌وکار من','store','تومان'
WHERE NOT EXISTS (SELECT 1 FROM settings WHERE id=1);

CREATE TABLE IF NOT EXISTS accounts (
  id INT PRIMARY KEY,
  code VARCHAR(20) NOT NULL UNIQUE,
  title VARCHAR(120) NOT NULL,
  type ENUM('asset','liability','equity','income','expense') NOT NULL
) ENGINE=InnoDB;

INSERT IGNORE INTO accounts (id,code,title,type) VALUES
(1,'101','صندوق','asset'),
(2,'201','حساب‌های پرداختنی','liability'),
(3,'301','سرمایه','equity'),
(4,'401','درآمد فروش','income'),
(5,'501','خرید کالا','expense');

CREATE TABLE IF NOT EXISTS products (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  name VARCHAR(190) NOT NULL,
  sku VARCHAR(80) NULL UNIQUE,
  stock DECIMAL(15,3) NOT NULL DEFAULT 0,
  purchase_price DECIMAL(15,2) NOT NULL DEFAULT 0,
  sale_price DECIMAL(15,2) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS journal_entries (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  entry_date DATE NOT NULL,
  description VARCHAR(255) NOT NULL,
  source_type VARCHAR(30) NOT NULL,
  source_id BIGINT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_source (source_type,source_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS journal_lines (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  entry_id BIGINT NOT NULL,
  account_id INT NOT NULL,
  debit DECIMAL(15,2) NOT NULL DEFAULT 0,
  credit DECIMAL(15,2) NOT NULL DEFAULT 0,
  CONSTRAINT fk_line_entry FOREIGN KEY (entry_id) REFERENCES journal_entries(id),
  CONSTRAINT fk_line_account FOREIGN KEY (account_id) REFERENCES accounts(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS transactions (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  txn_type ENUM('purchase','sale') NOT NULL,
  txn_date DATE NOT NULL,
  product_id BIGINT NOT NULL,
  quantity DECIMAL(15,3) NOT NULL,
  unit_price DECIMAL(15,2) NOT NULL,
  total DECIMAL(15,2) NOT NULL,
  payment_method ENUM('cash','credit') NOT NULL DEFAULT 'cash',
  party_name VARCHAR(190) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_txn_product FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB;
