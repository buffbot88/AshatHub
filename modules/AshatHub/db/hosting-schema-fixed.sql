-- AshatHub Hosting Platform Schema

-- Hosting accounts table (without foreign keys first)
CREATE TABLE IF NOT EXISTS hosting_accounts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id CHAR(36) NOT NULL,
    domain VARCHAR(255) NOT NULL UNIQUE,
    status ENUM('pending', 'active', 'paused', 'denied', 'deleted') DEFAULT 'pending',
    storage_limit INT DEFAULT 150 COMMENT 'Storage limit in MB',
    storage_used INT DEFAULT 0 COMMENT 'Storage used in MB',
    db_name VARCHAR(64) NULL,
    db_user VARCHAR(64) NULL,
    db_host VARCHAR(64) DEFAULT 'localhost',
    db_password VARCHAR(255) NULL COMMENT 'Encrypted database password',
    ftp_user VARCHAR(64) NULL,
    ftp_password VARCHAR(255) NULL COMMENT 'Encrypted FTP password',
    document_root VARCHAR(255) NULL,
    last_active TIMESTAMP NULL,
    admin_notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_status (status),
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add foreign key after table is created
ALTER TABLE hosting_accounts ADD CONSTRAINT fk_hosting_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;

-- Hosting traffic log
CREATE TABLE IF NOT EXISTS hosting_traffic (
    id INT AUTO_INCREMENT PRIMARY KEY,
    account_id INT NOT NULL,
    hits INT DEFAULT 0,
    bandwidth_bytes BIGINT DEFAULT 0,
    recorded_at DATE NOT NULL,
    UNIQUE KEY unique_day (account_id, recorded_at),
    INDEX idx_recorded (recorded_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add foreign key for traffic
ALTER TABLE hosting_traffic ADD CONSTRAINT fk_traffic_account FOREIGN KEY (account_id) REFERENCES hosting_accounts(id) ON DELETE CASCADE;

-- Hosting application queue
CREATE TABLE IF NOT EXISTS hosting_applications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id CHAR(36) NOT NULL,
    domain VARCHAR(255) NOT NULL,
    nameserver_info TEXT NULL COMMENT 'User provided NS records',
    status ENUM('submitted', 'reviewing', 'approved', 'denied') DEFAULT 'submitted',
    admin_response TEXT NULL,
    reviewed_by CHAR(36) NULL,
    reviewed_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add foreign keys for applications
ALTER TABLE hosting_applications ADD CONSTRAINT fk_app_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;
ALTER TABLE hosting_applications ADD CONSTRAINT fk_app_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL;
