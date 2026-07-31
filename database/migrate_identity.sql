-- GUGU Identity & OTP migration
-- Safe to re-run; uses IF NOT EXISTS / conditional ALTERs via setup.php

CREATE TABLE IF NOT EXISTS otp_codes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    phone VARCHAR(20) NOT NULL,
    code VARCHAR(6) NOT NULL,
    purpose ENUM('login', 'register', 'verify') DEFAULT 'login',
    attempts INT DEFAULT 0,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_otp_phone (phone),
    INDEX idx_otp_expires (expires_at)
) ENGINE=InnoDB;
