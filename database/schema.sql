-- GUGU App Database Schema
-- GuraCyangwaGurisha (3G Market) - Local marketplace for Rwanda
-- SAFE: Only for GUGUapDB database. Does NOT affect ikaze or other projects.

CREATE DATABASE IF NOT EXISTS GUGUapDB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE GUGUapDB;

-- Users table
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    phone VARCHAR(15) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    avatar VARCHAR(255) DEFAULT NULL,
    province VARCHAR(50) NOT NULL,
    district VARCHAR(50) NOT NULL,
    sector VARCHAR(50) DEFAULT NULL,
    bio TEXT DEFAULT NULL,
    manner_score DECIMAL(3,1) DEFAULT 36.5,
    manner_count INT DEFAULT 0,
    is_verified TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_district (district),
    INDEX idx_province (province)
) ENGINE=InnoDB;

-- Categories
CREATE TABLE IF NOT EXISTS categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name_rw VARCHAR(50) NOT NULL,
    name_en VARCHAR(50) NOT NULL,
    icon VARCHAR(10) NOT NULL,
    sort_order INT DEFAULT 0
) ENGINE=InnoDB;

INSERT IGNORE INTO categories (id, name_rw, name_en, icon, sort_order) VALUES
(1, 'Byose', 'All', 'home', 0),
(2, 'Telefoni', 'Electronics', 'phone', 1),
(3, 'Imbaho', 'Furniture', 'couch', 2),
(4, 'Imyambaro', 'Fashion', 'shirt', 3),
(5, 'Imodoka', 'Vehicles', 'car', 4),
(6, 'Inzu', 'Real Estate', 'house', 5),
(7, 'Imikino', 'Sports', 'ball', 6),
(8, 'Ibiryo', 'Food', 'food', 7),
(9, 'Ibikoresho', 'Appliances', 'plug', 8),
(10, 'Ibindi', 'Others', 'box', 9);

-- Listings
CREATE TABLE IF NOT EXISTS listings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    category_id INT NOT NULL DEFAULT 9,
    title VARCHAR(150) NOT NULL,
    description TEXT NOT NULL,
    price INT NOT NULL DEFAULT 0,
    is_free TINYINT(1) DEFAULT 0,
    status ENUM('active', 'reserved', 'sold') DEFAULT 'active',
    province VARCHAR(50) NOT NULL,
    district VARCHAR(50) NOT NULL,
    sector VARCHAR(50) DEFAULT NULL,
    view_count INT DEFAULT 0,
    chat_count INT DEFAULT 0,
    like_count INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (category_id) REFERENCES categories(id),
    INDEX idx_status (status),
    INDEX idx_district (district),
    INDEX idx_category (category_id),
    INDEX idx_created (created_at DESC),
    FULLTEXT idx_search (title, description)
) ENGINE=InnoDB;

-- Listing images
CREATE TABLE IF NOT EXISTS listing_images (
    id INT AUTO_INCREMENT PRIMARY KEY,
    listing_id INT NOT NULL,
    image_path VARCHAR(255) NOT NULL,
    is_primary TINYINT(1) DEFAULT 0,
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (listing_id) REFERENCES listings(id) ON DELETE CASCADE,
    INDEX idx_listing (listing_id)
) ENGINE=InnoDB;

-- Favorites
CREATE TABLE IF NOT EXISTS favorites (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    listing_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (listing_id) REFERENCES listings(id) ON DELETE CASCADE,
    UNIQUE KEY unique_favorite (user_id, listing_id)
) ENGINE=InnoDB;

-- Chat rooms (one per buyer-seller-listing combo)
CREATE TABLE IF NOT EXISTS chat_rooms (
    id INT AUTO_INCREMENT PRIMARY KEY,
    listing_id INT NOT NULL,
    buyer_id INT NOT NULL,
    seller_id INT NOT NULL,
    last_message_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (listing_id) REFERENCES listings(id) ON DELETE CASCADE,
    FOREIGN KEY (buyer_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (seller_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_room (listing_id, buyer_id),
    INDEX idx_buyer (buyer_id),
    INDEX idx_seller (seller_id)
) ENGINE=InnoDB;

-- Messages
CREATE TABLE IF NOT EXISTS messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    room_id INT NOT NULL,
    sender_id INT NOT NULL,
    content TEXT NOT NULL,
    is_read TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (room_id) REFERENCES chat_rooms(id) ON DELETE CASCADE,
    FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_room (room_id),
    INDEX idx_created (created_at)
) ENGINE=InnoDB;

-- User reviews (trust score)
CREATE TABLE IF NOT EXISTS reviews (
    id INT AUTO_INCREMENT PRIMARY KEY,
    reviewer_id INT NOT NULL,
    reviewed_id INT NOT NULL,
    listing_id INT DEFAULT NULL,
    rating ENUM('good', 'bad') NOT NULL,
    comment VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (reviewer_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (reviewed_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (listing_id) REFERENCES listings(id) ON DELETE SET NULL,
    UNIQUE KEY unique_review (reviewer_id, reviewed_id, listing_id)
) ENGINE=InnoDB;

-- Search alerts
CREATE TABLE IF NOT EXISTS search_alerts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    keyword VARCHAR(100) NOT NULL,
    district VARCHAR(50) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Sessions
CREATE TABLE IF NOT EXISTS sessions (
    id VARCHAR(64) PRIMARY KEY,
    user_id INT NOT NULL,
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB;
