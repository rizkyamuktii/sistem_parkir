<?php
// api/get_dashboard_data.php - Data live untuk dashboard monitoring
header('Content-Type: application/json; charset=utf-8');
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
require_once __DIR__ . '/db.php';

try {
    // 1. Data slot parkir beserta info kendaraan yang sedang parkir
    $stmtSlots = $pdo->query("
        SELECT 
            s.*,
            c.owner_name,
            c.plate_number,
            c.vehicle_type as card_vehicle_type,
            l.time_in,
            TIMESTAMPDIFF(MINUTE, l.time_in, NOW()) as parked_minutes
        FROM `parking_slots` s
        LEFT JOIN `rfid_cards` c ON s.current_rfid_uid = c.rfid_uid
        LEFT JOIN `parking_logs` l ON l.id = (SELECT MAX(id) FROM `parking_logs` WHERE `slot_code` = s.slot_code AND `status` = 'IN')
        ORDER BY s.slot_type ASC, s.slot_code ASC
    ");
    $slots = $stmtSlots->fetchAll();

    // 2. Statistik Ringkasan
    $totalSlots = count($slots);
    $occupiedSlots = 0;
    $availableSlots = 0;

    foreach ($slots as $slot) {
        if ($slot['status'] === 'occupied') {
            $occupiedSlots++;
        } else {
            $availableSlots++;
        }
    }

    // Hitung hari ini
    $today = date('Y-m-d');
    
    // Tap In Hari ini
    $stmtIn = $pdo->prepare("SELECT COUNT(*) as cnt FROM `parking_logs` WHERE DATE(time_in) = :today");
    $stmtIn->execute([':today' => $today]);
    $todayIn = (int)$stmtIn->fetch()['cnt'];

    // Tap Out Hari ini
    $stmtOut = $pdo->prepare("SELECT COUNT(*) as cnt, COALESCE(SUM(fee), 0) as revenue FROM `parking_logs` WHERE DATE(time_out) = :today AND status = 'OUT'");
    $stmtOut->execute([':today' => $today]);
    $outData = $stmtOut->fetch();
    $todayOut = (int)$outData['cnt'];
    $todayRevenue = (int)$outData['revenue'];

    // 3. Log Terbaru (15 data terakhir)
    $stmtLogs = $pdo->query("
        SELECT * FROM `parking_logs`
        ORDER BY id DESC
        LIMIT 15
    ");
    $recentLogs = $stmtLogs->fetchAll();

    // 4. Status ESP32 Hardware
    $stmtEsp = $pdo->query("SELECT * FROM `esp32_status` WHERE id = 1");
    $espStatus = $stmtEsp->fetch();
    $isEspOnline = false;
    if ($espStatus) {
        $lastPingTime = strtotime($espStatus['last_ping']);
        $diffSeconds = time() - $lastPingTime;
        $isEspOnline = ($diffSeconds <= 60); // online jika ping dalam 60 detik terakhir
    }

    // 5. Total kartu terdaftar
    $stmtTotalCards = $pdo->query("SELECT COUNT(*) as cnt FROM `rfid_cards`");
    $totalCards = (int)$stmtTotalCards->fetch()['cnt'];

    // 6. Cek event tap terkini (dalam 5 detik terakhir) untuk sinkronisasi hardware & animasi web
    $latestEvent = null;
    if (!empty($recentLogs)) {
        $firstLog = $recentLogs[0];
        $eventTimestamp = ($firstLog['status'] === 'OUT' && !empty($firstLog['time_out'])) 
            ? strtotime($firstLog['time_out']) 
            : strtotime($firstLog['time_in']);
        
        $secondsAgo = time() - $eventTimestamp;
        if ($secondsAgo >= 0 && $secondsAgo <= 5) {
            $isTapIn = ($firstLog['status'] === 'IN');
            $latestEvent = [
                'id' => (int)$firstLog['id'],
                'status' => $firstLog['status'],
                'slot_code' => $firstLog['slot_code'],
                'plate_number' => $firstLog['plate_number'],
                'owner_name' => $firstLog['owner_name'],
                'vehicle_type' => $firstLog['vehicle_type'],
                'fee' => (int)$firstLog['fee'],
                'seconds_ago' => $secondsAgo,
                'lcd_line1' => $isTapIn ? substr("MASUK: " . $firstLog['slot_code'], 0, 16) : substr("BYE " . $firstLog['plate_number'], 0, 16),
                'lcd_line2' => $isTapIn ? substr($firstLog['plate_number'], 0, 16) : substr("BIAYA: Rp " . number_format($firstLog['fee'], 0, ',', '.'), 0, 16)
            ];
        }
    }

    $lcdLine2 = ($availableSlots <= 0) ? "PARKIR PENUH!" : sprintf("SISA SLOT: %02d/%02d", $availableSlots, $totalSlots);

    echo json_encode([
        'status' => 'success',
        'timestamp' => date('Y-m-d H:i:s'),
        'summary' => [
            'total_slots' => $totalSlots,
            'occupied_slots' => $occupiedSlots,
            'available_slots' => $availableSlots,
            'occupancy_percentage' => $totalSlots > 0 ? round(($occupiedSlots / $totalSlots) * 100) : 0,
            'today_in' => $todayIn,
            'today_out' => $todayOut,
            'today_revenue' => $todayRevenue,
            'total_registered_cards' => $totalCards
        ],
        'slots' => $slots,
        'recent_logs' => $recentLogs,
        'latest_event' => $latestEvent,
        'lcd_idle' => [
            'line1' => 'SMART PARKING',
            'line2' => $lcdLine2
        ],
        'esp32' => [
            'device_name' => $espStatus['device_name'] ?? 'ESP32-Gate-01',
            'ip_address' => $espStatus['ip_address'] ?? '192.168.1.50',
            'last_ping' => $espStatus['last_ping'] ?? null,
            'is_online' => $isEspOnline
        ]
    ]);
} catch (Exception $e) {
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}
