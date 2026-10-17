<?php
// api/heartbeat.php - ESP32 status update & LCD idle screen data provider
header('Content-Type: application/json; charset=utf-8');
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
require_once __DIR__ . '/db.php';

// Ambil IP
$ip = $_SERVER['REMOTE_ADDR'] ?? '192.168.1.100';
$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);
if (!empty($data['ip'])) {
    $ip = $data['ip'];
} elseif (!empty($_GET['ip'])) {
    $ip = $_GET['ip'];
}

// Update last ping
try {
    $stmtPing = $pdo->prepare("UPDATE `esp32_status` SET `last_ping` = NOW(), `ip_address` = :ip, `status` = 'online' WHERE `id` = 1");
    $stmtPing->execute([':ip' => $ip]);
} catch (Exception $e) {}

// Hitung total dan sisa slot
$stmtCountTotal = $pdo->query("SELECT COUNT(*) as total FROM `parking_slots`");
$total = (int)$stmtCountTotal->fetch()['total'];

$stmtCountAvail = $pdo->query("SELECT COUNT(*) as avail FROM `parking_slots` WHERE `status` = 'available'");
$avail = (int)$stmtCountAvail->fetch()['avail'];

$isFull = ($avail <= 0);

// Buat teks LCD 16x2 saat standby
$line1 = "SMART PARKING";
$line2 = $isFull ? "PARKIR PENUH!" : sprintf("SISA SLOT: %02d/%02d", $avail, $total);

echo json_encode([
    'status' => 'online',
    'timestamp' => date('Y-m-d H:i:s'),
    'lcd_line1' => $line1,
    'lcd_line2' => $line2,
    'available_slots' => $avail,
    'total_slots' => $total,
    'is_full' => $isFull
]);
