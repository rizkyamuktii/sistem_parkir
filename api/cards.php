<?php
// api/cards.php - CRUD Kartu RFID Terdaftar
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/db.php';

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// 1. Ambil data kartu
if ($method === 'GET' && empty($action)) {
    try {
        $stmt = $pdo->query("SELECT * FROM `rfid_cards` ORDER BY id DESC");
        $cards = $stmt->fetchAll();
        echo json_encode(['status' => 'success', 'data' => $cards]);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit();
}

// 2. Tambah / Edit / Hapus
$rawInput = file_get_contents('php://input');
$body = json_decode($rawInput, true) ?? $_POST;

if ($action === 'create' || ($method === 'POST' && ($body['action'] ?? '') === 'create')) {
    $uid = strtoupper(trim(str_replace([' ', ':', '-'], '', $body['rfid_uid'] ?? '')));
    $owner = trim($body['owner_name'] ?? '');
    $plate = strtoupper(trim($body['plate_number'] ?? ''));
    $vtype = in_array($body['vehicle_type'] ?? '', ['Mobil', 'Motor']) ? $body['vehicle_type'] : 'Mobil';
    $balance = (int)($body['balance'] ?? 50000);

    if (empty($uid) || empty($owner) || empty($plate)) {
        echo json_encode(['status' => 'error', 'message' => 'Semua kolom wajib diisi!']);
        exit();
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO `rfid_cards` (`rfid_uid`, `owner_name`, `plate_number`, `vehicle_type`, `balance`, `status`)
            VALUES (:uid, :owner, :plate, :vtype, :balance, 'active')
        ");
        $stmt->execute([
            ':uid' => $uid,
            ':owner' => $owner,
            ':plate' => $plate,
            ':vtype' => $vtype,
            ':balance' => $balance
        ]);
        echo json_encode(['status' => 'success', 'message' => 'Kartu berhasil ditambahkan!']);
    } catch (PDOException $e) {
        if ($e->getCode() == 23000) {
            echo json_encode(['status' => 'error', 'message' => 'UID RFID tersebut sudah terdaftar!']);
        } else {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }
    exit();
}

if ($action === 'delete' || ($method === 'POST' && ($body['action'] ?? '') === 'delete')) {
    $id = (int)($body['id'] ?? $_GET['id'] ?? 0);
    try {
        $stmt = $pdo->prepare("DELETE FROM `rfid_cards` WHERE id = :id");
        $stmt->execute([':id' => $id]);
        echo json_encode(['status' => 'success', 'message' => 'Kartu berhasil dihapus!']);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit();
}

if ($action === 'toggle_status' || ($method === 'POST' && ($body['action'] ?? '') === 'toggle_status')) {
    $id = (int)($body['id'] ?? 0);
    try {
        $stmt = $pdo->prepare("UPDATE `rfid_cards` SET status = IF(status = 'active', 'suspended', 'active') WHERE id = :id");
        $stmt->execute([':id' => $id]);
        echo json_encode(['status' => 'success', 'message' => 'Status kartu diperbarui!']);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit();
}

if ($action === 'reset_slots' || ($method === 'POST' && ($body['action'] ?? '') === 'reset_slots')) {
    // Fitur reset darurat untuk mengosongkan semua slot dan menyelesaikan log
    try {
        $pdo->exec("UPDATE `parking_slots` SET `status` = 'available', `current_rfid_uid` = NULL, `occupied_since` = NULL");
        $pdo->exec("UPDATE `parking_logs` SET `status` = 'OUT', `time_out` = NOW() WHERE `status` = 'IN'");
        echo json_encode(['status' => 'success', 'message' => 'Semua slot parkir berhasil direset!']);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit();
}

echo json_encode(['status' => 'error', 'message' => 'Aksi tidak valid']);
