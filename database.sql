-- Database Schema: Sistem Monitoring Parkir Berbasis ESP32
CREATE DATABASE IF NOT EXISTS `sistem_parkir` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `sistem_parkir`;

-- 1. Tabel Kartu RFID Terdaftar
CREATE TABLE IF NOT EXISTS `rfid_cards` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `rfid_uid` VARCHAR(32) NOT NULL UNIQUE,
    `owner_name` VARCHAR(100) NOT NULL,
    `plate_number` VARCHAR(20) NOT NULL,
    `vehicle_type` ENUM('Mobil', 'Motor') DEFAULT 'Mobil',
    `balance` INT DEFAULT 50000,
    `status` ENUM('active', 'suspended') DEFAULT 'active',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- 2. Tabel Slot Parkir
CREATE TABLE IF NOT EXISTS `parking_slots` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `slot_code` VARCHAR(10) NOT NULL UNIQUE,
    `slot_type` ENUM('Mobil', 'Motor') DEFAULT 'Mobil',
    `status` ENUM('available', 'occupied', 'reserved') DEFAULT 'available',
    `current_rfid_uid` VARCHAR(32) DEFAULT NULL,
    `occupied_since` DATETIME DEFAULT NULL,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- 3. Tabel Riwayat / Log Parkir
CREATE TABLE IF NOT EXISTS `parking_logs` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `rfid_uid` VARCHAR(32) NOT NULL,
    `slot_code` VARCHAR(10) DEFAULT NULL,
    `owner_name` VARCHAR(100) DEFAULT NULL,
    `plate_number` VARCHAR(20) DEFAULT NULL,
    `vehicle_type` VARCHAR(20) DEFAULT 'Mobil',
    `time_in` DATETIME NOT NULL,
    `time_out` DATETIME DEFAULT NULL,
    `duration_minutes` INT DEFAULT 0,
    `fee` INT DEFAULT 0,
    `status` ENUM('IN', 'OUT') DEFAULT 'IN',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- 4. Tabel Pengaturan Sistem
CREATE TABLE IF NOT EXISTS `system_settings` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `setting_key` VARCHAR(50) NOT NULL UNIQUE,
    `setting_value` VARCHAR(255) NOT NULL,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Inisialisasi Data Default Pengaturan
INSERT INTO `system_settings` (`setting_key`, `setting_value`) VALUES
('total_capacity', '12'),
('hourly_rate_mobil', '4000'),
('hourly_rate_motor', '2000'),
('free_minutes', '15'),
('system_name', 'Smart Parking ESP32')
ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`);

-- Inisialisasi Slot Parkir Default (A-01 s/d A-08 Mobil, B-01 s/d B-04 Motor)
INSERT INTO `parking_slots` (`slot_code`, `slot_type`, `status`) VALUES
('A-01', 'Mobil', 'available'),
('A-02', 'Mobil', 'available'),
('A-03', 'Mobil', 'available'),
('A-04', 'Mobil', 'available'),
('A-05', 'Mobil', 'available'),
('A-06', 'Mobil', 'available'),
('A-07', 'Mobil', 'available'),
('A-08', 'Mobil', 'available'),
('B-01', 'Motor', 'available'),
('B-02', 'Motor', 'available'),
('B-03', 'Motor', 'available'),
('B-04', 'Motor', 'available')
ON DUPLICATE KEY UPDATE `slot_code` = `slot_code`;

-- Inisialisasi Contoh Kartu RFID
INSERT INTO `rfid_cards` (`rfid_uid`, `owner_name`, `plate_number`, `vehicle_type`, `balance`) VALUES
('E3A19B2C', 'Ahmad Rizky', 'B 1234 ABC', 'Mobil', 100000),
('74F921B5', 'Siti Rahma', 'B 5678 DEF', 'Mobil', 75000),
('A28B33C1', 'Budi Santoso', 'B 9012 GHI', 'Motor', 40000),
('5D2E7A99', 'Dewi Lestari', 'B 3456 JKL', 'Mobil', 50000)
ON DUPLICATE KEY UPDATE `rfid_uid` = `rfid_uid`;
