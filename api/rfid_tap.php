<?php
// api/rfid_tap.php - Endpoint pemrosesan scan kartu RFID dari ESP32 atau Web Simulator
header('Content-Type: application/json; charset=utf-8');
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
require_once __DIR__ . '/db.php';

// Ambil input JSON atau POST/GET
$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);

$uid = '';
if (!empty($data['uid'])) {
    $uid = $data['uid'];
} elseif (!empty($_POST['uid'])) {
    $uid = $_POST['uid'];
} elseif (!empty($_GET['uid'])) {
    $uid = $_GET['uid'];
}

$ip = $_SERVER['REMOTE_ADDR'] ?? '192.168.1.100';
if (!empty($data['ip'])) {
    $ip = $data['ip'];
}

// Bersihkan format UID
$uid = strtoupper(trim(str_replace([' ', ':', '-'], '', $uid)));

if (empty($uid)) {
    echo json_encode([
        'status' => 'error',
        'action' => 'NO_UID',
        'message' => 'Parameter UID tidak ditemukan',
        'lcd_line1' => 'SCAN KARTU RFID',
        'lcd_line2' => 'SILAKAN TAP',
        'gate_open' => false,
        'beep' => 0
    ]);
    exit();
}

// Update ESP32 Heartbeat status
try {
    $stmtPing = $pdo->prepare("UPDATE `esp32_status` SET `last_ping` = NOW(), `ip_address` = :ip, `status` = 'online' WHERE `id` = 1");
    $stmtPing->execute([':ip' => $ip]);
} catch (Exception $e) {}

// Ambil data tarif & pengaturan
$settings = [];
$stmtSet = $pdo->query("SELECT setting_key, setting_value FROM system_settings");
while ($row = $stmtSet->fetch()) {
    $settings[$row['setting_key']] = $row['setting_value'];
}
$rateMobil = (int)($settings['hourly_rate_mobil'] ?? 4000);
$rateMotor = (int)($settings['hourly_rate_motor'] ?? 2000);
$freeMinutes = (int)($settings['free_minutes'] ?? 5);

// 1. Cek apakah kartu terdaftar
$stmtCard = $pdo->prepare("SELECT * FROM `rfid_cards` WHERE UPPER(`rfid_uid`) = :uid");
$stmtCard->execute([':uid' => $uid]);
$card = $stmtCard->fetch();

if (!$card) {
    echo json_encode([
        'status' => 'error',
        'action' => 'DENIED',
        'uid' => $uid,
        'message' => 'Kartu RFID belum terdaftar di sistem',
        'lcd_line1' => 'KARTU DITOLAK!',
        'lcd_line2' => 'TIDAK TERDAFTAR',
        'gate_open' => false,
        'beep' => 3
    ]);
    exit();
}

if ($card['status'] !== 'active') {
    echo json_encode([
        'status' => 'error',
        'action' => 'DENIED',
        'uid' => $uid,
        'message' => 'Kartu RFID sedang diblokir / ditangguhkan',
        'lcd_line1' => 'KARTU DIBLOKIR!',
        'lcd_line2' => 'HUBUNGI PETUGAS',
        'gate_open' => false,
        'beep' => 3
    ]);
    exit();
}

// 2. Cek apakah kendaraan sedang berada di dalam parkiran (status IN)
$stmtActiveLog = $pdo->prepare("SELECT * FROM `parking_logs` WHERE `rfid_uid` = :uid AND `status` = 'IN' ORDER BY `id` DESC LIMIT 1");
$stmtActiveLog->execute([':uid' => $uid]);
$activeLog = $stmtActiveLog->fetch();

// ==========================================
// KONDISI 1: KENDARAAN KELUAR (TAP OUT)
// ==========================================
if ($activeLog) {
    $timeIn = new DateTime($activeLog['time_in']);
    $timeOut = new DateTime();
    $secondsDiff = $timeOut->getTimestamp() - $timeIn->getTimestamp();

    // Pencegahan re-tap / kartu tertahan di reader saat baru masuk (kurang dari 8 detik)
    if ($secondsDiff < 8) {
        $stmtCountAvail = $pdo->query("SELECT COUNT(*) as avail FROM `parking_slots` WHERE `status` = 'available'");
        $availableSlots = (int)$stmtCountAvail->fetch()['avail'];

        echo json_encode([
            'status' => 'success',
            'action' => 'TAP_IN_ALREADY',
            'uid' => $uid,
            'owner' => $card['owner_name'],
            'plate' => $card['plate_number'],
            'vehicle_type' => $card['vehicle_type'],
            'slot' => $activeLog['slot_code'],
            'time_in' => $activeLog['time_in'],
            'available_slots' => $availableSlots,
            'lcd_line1' => substr("MASUK: " . $activeLog['slot_code'], 0, 16),
            'lcd_line2' => substr($card['plate_number'], 0, 16),
            'gate_open' => true,
            'beep' => 1
        ]);
        exit();
    }

    $interval = $timeIn->diff($timeOut);
    $totalMinutes = ($interval->days * 24 * 60) + ($interval->h * 60) + $interval->i;
    if ($totalMinutes < 1) $totalMinutes = 1;

    $rate = ($card['vehicle_type'] === 'Motor') ? $rateMotor : $rateMobil;
    $fee = 0;

    if ($totalMinutes > $freeMinutes) {
        $billableHours = ceil($totalMinutes / 60);
        $fee = (int)($billableHours * $rate);
    }

    $hoursText = floor($totalMinutes / 60);
    $minutesText = $totalMinutes % 60;
    $durationString = ($hoursText > 0 ? "{$hoursText}j " : "") . "{$minutesText}m";

    // Update log
    $stmtUpdateLog = $pdo->prepare("
        UPDATE `parking_logs`
        SET `time_out` = NOW(),
            `duration_minutes` = :duration,
            `fee` = :fee,
            `status` = 'OUT'
        WHERE `id` = :id
    ");
    $stmtUpdateLog->execute([
        ':duration' => $totalMinutes,
        ':fee' => $fee,
        ':id' => $activeLog['id']
    ]);

    // Kosongkan slot parkir terkait
    $stmtFreeSlot = $pdo->prepare("
        UPDATE `parking_slots`
        SET `status` = 'available',
            `current_rfid_uid` = NULL,
            `occupied_since` = NULL
        WHERE `slot_code` = :slot_code OR `current_rfid_uid` = :uid
    ");
    $stmtFreeSlot->execute([
        ':slot_code' => $activeLog['slot_code'],
        ':uid' => $uid
    ]);

    // Hitung sisa slot tersedia
    $stmtCountAvail = $pdo->query("SELECT COUNT(*) as avail FROM `parking_slots` WHERE `status` = 'available'");
    $availableSlots = (int)$stmtCountAvail->fetch()['avail'];

    // Format tampilan LCD 16x2 (maksimal 16 karakter per baris)
    $line1 = substr("BYE " . $card['plate_number'], 0, 16);
    $feeStr = "Rp " . number_format($fee, 0, ',', '.');
    $line2 = substr("BIAYA: " . $feeStr, 0, 16);

    echo json_encode([
        'status' => 'success',
        'action' => 'TAP_OUT',
        'uid' => $uid,
        'owner' => $card['owner_name'],
        'plate' => $card['plate_number'],
        'vehicle_type' => $card['vehicle_type'],
        'slot' => $activeLog['slot_code'],
        'time_in' => $activeLog['time_in'],
        'time_out' => $timeOut->format('Y-m-d H:i:s'),
        'duration_minutes' => $totalMinutes,
        'duration_string' => $durationString,
        'fee' => $fee,
        'fee_formatted' => $feeStr,
        'available_slots' => $availableSlots,
        'lcd_line1' => $line1,
        'lcd_line2' => $line2,
        'gate_open' => true,
        'beep' => 1
    ]);
    exit();
}

// ==========================================
// KONDISI 2: KENDARAAN MASUK (TAP IN)
// ==========================================
// Cari slot yang sesuai dengan jenis kendaraan
$stmtFindSlot = $pdo->prepare("
    SELECT * FROM `parking_slots`
    WHERE `status` = 'available' AND `slot_type` = :vtype
    ORDER BY `id` ASC LIMIT 1
");
$stmtFindSlot->execute([':vtype' => $card['vehicle_type']]);
$assignedSlot = $stmtFindSlot->fetch();

// Jika slot spesifik jenis kendaraan habis, cari slot available apapun
if (!$assignedSlot) {
    $stmtFindAny = $pdo->query("SELECT * FROM `parking_slots` WHERE `status` = 'available' ORDER BY `id` ASC LIMIT 1");
    $assignedSlot = $stmtFindAny->fetch();
}

// Jika parkir penuh
if (!$assignedSlot) {
    echo json_encode([
        'status' => 'error',
        'action' => 'FULL',
        'uid' => $uid,
        'owner' => $card['owner_name'],
        'message' => 'Semua slot parkir telah terisi penuh',
        'lcd_line1' => 'MOHON MAAF',
        'lcd_line2' => 'PARKIR SUDAH PENUH',
        'gate_open' => false,
        'beep' => 2
    ]);
    exit();
}

// Tempati slot
$stmtOccupy = $pdo->prepare("
    UPDATE `parking_slots`
    SET `status` = 'occupied',
        `current_rfid_uid` = :uid,
        `occupied_since` = NOW()
    WHERE `id` = :slot_id
");
$stmtOccupy->execute([
    ':uid' => $uid,
    ':slot_id' => $assignedSlot['id']
]);

// Catat log masuk
$stmtInsertLog = $pdo->prepare("
    INSERT INTO `parking_logs`
    (`rfid_uid`, `slot_code`, `owner_name`, `plate_number`, `vehicle_type`, `time_in`, `status`)
    VALUES
    (:uid, :slot_code, :owner, :plate, :vtype, NOW(), 'IN')
");
$stmtInsertLog->execute([
    ':uid' => $uid,
    ':slot_code' => $assignedSlot['slot_code'],
    ':owner' => $card['owner_name'],
    ':plate' => $card['plate_number'],
    ':vtype' => $card['vehicle_type']
]);

// Hitung sisa slot tersedia
$stmtCountAvail = $pdo->query("SELECT COUNT(*) as avail FROM `parking_slots` WHERE `status` = 'available'");
$availableSlots = (int)$stmtCountAvail->fetch()['avail'];

// Format LCD 16x2
$line1 = substr("MASUK: " . $assignedSlot['slot_code'], 0, 16);
$line2 = substr($card['plate_number'], 0, 16);

echo json_encode([
    'status' => 'success',
    'action' => 'TAP_IN',
    'uid' => $uid,
    'owner' => $card['owner_name'],
    'plate' => $card['plate_number'],
    'vehicle_type' => $card['vehicle_type'],
    'slot' => $assignedSlot['slot_code'],
    'time_in' => date('Y-m-d H:i:s'),
    'available_slots' => $availableSlots,
    'lcd_line1' => $line1,
    'lcd_line2' => $line2,
    'gate_open' => true,
    'beep' => 1
]);
