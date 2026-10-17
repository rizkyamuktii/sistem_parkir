<?php
// api/db.php - Koneksi Database dengan Auto-Migration
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");

date_default_timezone_set('Asia/Jakarta');

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$host = '127.0.0.1';
$user = 'root';
$pass = '';
$dbname = 'sistem_parkir';

try {
    // Koneksi awal tanpa memilih database untuk memastikan database ada
    $pdo = new PDO("mysql:host=$host;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    // Buat database jika belum ada
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbname` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `$dbname`");
    $pdo->exec("SET time_zone = '+07:00'");

    // Auto buat tabel jika belum ada
    $pdo->exec("
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

        CREATE TABLE IF NOT EXISTS `parking_slots` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `slot_code` VARCHAR(10) NOT NULL UNIQUE,
            `slot_type` ENUM('Mobil', 'Motor') DEFAULT 'Mobil',
            `status` ENUM('available', 'occupied', 'reserved') DEFAULT 'available',
            `current_rfid_uid` VARCHAR(32) DEFAULT NULL,
            `occupied_since` DATETIME DEFAULT NULL,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB;

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

        CREATE TABLE IF NOT EXISTS `system_settings` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `setting_key` VARCHAR(50) NOT NULL UNIQUE,
            `setting_value` VARCHAR(255) NOT NULL,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB;

        CREATE TABLE IF NOT EXISTS `esp32_status` (
            `id` INT PRIMARY KEY DEFAULT 1,
            `device_name` VARCHAR(50) DEFAULT 'ESP32-Gate-01',
            `ip_address` VARCHAR(50) DEFAULT '192.168.1.100',
            `last_ping` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `status` VARCHAR(20) DEFAULT 'online'
        ) ENGINE=InnoDB;
    ");

    // Inisialisasi data default slot jika kosong
    $stmtSlot = $pdo->query("SELECT COUNT(*) as cnt FROM `parking_slots`");
    if ($stmtSlot->fetch()['cnt'] == 0) {
        $pdo->exec("
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
            ('B-04', 'Motor', 'available');
        ");
    }

    // Kartu RFID dibiarkan kosong agar pengguna dapat mendaftarkan kartu sendiri

    // Inisialisasi settings jika kosong
    $stmtSet = $pdo->query("SELECT COUNT(*) as cnt FROM `system_settings`");
    if ($stmtSet->fetch()['cnt'] == 0) {
        $pdo->exec("
            INSERT INTO `system_settings` (`setting_key`, `setting_value`) VALUES
            ('hourly_rate_mobil', '4000'),
            ('hourly_rate_motor', '2000'),
            ('free_minutes', '5'),
            ('system_name', 'Smart Parking ESP32');
            
            INSERT INTO `esp32_status` (`id`, `device_name`, `ip_address`, `last_ping`, `status`)
            VALUES (1, 'ESP32-Gate-Master', '192.168.1.50', NOW(), 'online')
            ON DUPLICATE KEY UPDATE `id` = 1;
        ");
    } else {
        // Pastikan free_minutes diset ke 5 menit
        $pdo->exec("UPDATE `system_settings` SET `setting_value` = '5' WHERE `setting_key` = 'free_minutes'");
    }

} catch (PDOException $e) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'status' => 'error',
        'message' => 'Gagal koneksi database MySQL: ' . $e->getMessage()
    ]);
    exit();
}
