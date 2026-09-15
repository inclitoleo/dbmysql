CREATE TABLE IF NOT EXISTS account (
    id INT NOT NULL AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_account_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS orders (
    id INT NOT NULL AUTO_INCREMENT,
    account_id INT NOT NULL,
    total DECIMAL(10,2) NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_orders_account (account_id),
    CONSTRAINT fk_orders_account FOREIGN KEY (account_id) REFERENCES account (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS logs (
    id BIGINT NOT NULL AUTO_INCREMENT,
    message VARCHAR(255) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO account (name, email) VALUES ('Seed User', 'seed@example.com');
INSERT IGNORE INTO orders (account_id, total) VALUES (1, 10.50);
INSERT IGNORE INTO logs (message) VALUES ('seed log');
