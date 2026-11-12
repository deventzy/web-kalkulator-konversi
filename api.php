<?php

session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>(!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),'httponly'=>true,'samesite'=>'Lax']);
session_start();

header('Content-Type: application/json; charset=utf-8');

/* =========================
   ADMIN PASSWORD
   GANTI password di bawah ini dengan password admin yang aman!
   ========================= */

$ADMIN_PASSWORD = '112233';

function requireAdmin() {
    if (empty($_SESSION['is_admin'])) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Akses ditolak. Hanya admin yang bisa melakukan aksi ini.'
        ]);
        exit;
    }
}

/* =========================
   DATABASE INFINITYFREE
   ========================= */

$host = 'sql105.infinityfree.com';
$db   = 'if0_42580321_konversi_db';
$user = 'if0_42580321';
$pass = 'bf0L35n2qIB';
$port = 3306;

try {

    $pdo = new PDO(
        "mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4",
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );

} catch (PDOException $e) {

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Database gagal terhubung',
        'error' => $e->getMessage()
    ]);

    exit;
}


/* =========================
   BUAT TABEL JIKA BELUM ADA
   ========================= */

$pdo->exec("
    CREATE TABLE IF NOT EXISTS kv_store (
        id INT AUTO_INCREMENT PRIMARY KEY,
        key_name VARCHAR(255) NOT NULL UNIQUE,
        value LONGTEXT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP
    )
");


$pdo->exec("
    CREATE TABLE IF NOT EXISTS riwayat (
        id VARCHAR(100) PRIMARY KEY,
        data LONGTEXT NOT NULL,
        created_at BIGINT NOT NULL
    )
");

$pdo->exec("
    CREATE TABLE IF NOT EXISTS konversi (
        id VARCHAR(100) PRIMARY KEY,
        tipe VARCHAR(30) NOT NULL DEFAULT 'konversi',
        nama_prospek VARCHAR(255) NOT NULL,
        bahan VARCHAR(100) NULL,
        markup_berat DECIMAL(10,2) NOT NULL DEFAULT 0,
        panjang_sample_cm DECIMAL(14,3) NULL,
        berat_sample_gram DECIMAL(14,3) NULL,
        status VARCHAR(40) NOT NULL DEFAULT 'MENUNGGU_ACC',
        timestamp_data BIGINT NULL,
        raw_json LONGTEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )
");

$pdo->exec("
    CREATE TABLE IF NOT EXISTS konversi_varian (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        konversi_id VARCHAR(100) NOT NULL,
        nama_varian VARCHAR(255) NOT NULL,
        panjang_target_cm DECIMAL(14,3) NULL,
        berat_konversi_gram DECIMAL(14,3) NULL,
        berat_konversi_kg DECIMAL(14,6) NULL,
        berat_markup_kg DECIMAL(14,6) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX (konversi_id)
    )
");

$pdo->exec("
    CREATE TABLE IF NOT EXISTS konversi_harga (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        konversi_id VARCHAR(100) NOT NULL,
        varian_id BIGINT UNSIGNED NOT NULL,
        harga_bb_kg DECIMAL(14,2) NOT NULL DEFAULT 0,
        harga_penawaran_pcs DECIMAL(14,2) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX (konversi_id),
        INDEX (varian_id)
    )
");


/* =========================
   HELPER KV STORE (untuk pending-kalkulasi)
   ========================= */

function kvGet(PDO $pdo, string $key) {
    $stmt = $pdo->prepare("SELECT value FROM kv_store WHERE key_name = :key LIMIT 1");
    $stmt->execute([':key' => $key]);
    $row = $stmt->fetch();
    if (!$row) return null;
    $decoded = json_decode($row['value'], true);
    return is_array($decoded) ? $decoded : null;
}

function kvSet(PDO $pdo, string $key, $value) {
    $json = json_encode($value, JSON_UNESCAPED_UNICODE);
    $stmt = $pdo->prepare("
        INSERT INTO kv_store
        (key_name, value)
        VALUES
        (:key, :value)
        ON DUPLICATE KEY UPDATE
        value = :value2
    ");
    $stmt->execute([
        ':key' => $key,
        ':value' => $json,
        ':value2' => $json
    ]);
}


/* =========================
   ACTION
   ========================= */

$action = $_GET['action'] ?? '';



/* =========================================================
   ADMIN LOGIN / LOGOUT / STATUS
   ========================================================= */

if ($action === 'admin_login') {

    $input = json_decode(file_get_contents('php://input'), true);
    $password = (string)($input['password'] ?? '');

    if ($password !== '' && hash_equals($ADMIN_PASSWORD, $password)) {
        session_regenerate_id(true);
        $_SESSION['is_admin'] = true;
        echo json_encode(['success' => true]);
    } else {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'message' => 'Password admin salah'
        ]);
    }

    exit;
}

if ($action === 'admin_logout') {

    unset($_SESSION['is_admin']);

    echo json_encode(['success' => true]);

    exit;
}

if ($action === 'admin_status') {

    echo json_encode([
        'isAdmin' => !empty($_SESSION['is_admin'])
    ]);

    exit;
}



/* =========================================================
   STORAGE GET
   Dipanggil oleh:
   storeGet()
   ========================================================= */

if ($action === 'storage') {

    $key = $_GET['key'] ?? '';

    if ($key === '') {

        http_response_code(400);

        echo json_encode([
            'success' => false,
            'message' => 'Key kosong'
        ]);

        exit;
    }

    $stmt = $pdo->prepare("
        SELECT value
        FROM kv_store
        WHERE key_name = :key
        LIMIT 1
    ");

    $stmt->execute([
        ':key' => $key
    ]);

    $row = $stmt->fetch();

    if (!$row) {

        echo json_encode([
            'value' => null
        ]);

        exit;
    }

    $value = json_decode(
        $row['value'],
        true
    );

    echo json_encode([
        'value' => $value
    ]);

    exit;
}



/* =========================================================
   STORAGE SET
   Dipanggil oleh:
   storeSet()
   ========================================================= */

if ($action === 'storage_set') {

    $input = json_decode(
        file_get_contents('php://input'),
        true
    );

    if (!is_array($input)) {

        http_response_code(400);

        echo json_encode([
            'success' => false,
            'message' => 'Data JSON tidak valid'
        ]);

        exit;
    }

    $key = $input['key'] ?? '';
    $value = $input['value'] ?? null;

    if ($key === '') {

        http_response_code(400);

        echo json_encode([
            'success' => false,
            'message' => 'Key kosong'
        ]);

        exit;
    }

    // "pending-kalkulasi" hanya boleh diubah lewat action khusus
    // (pending_add / pending_customer_acc / pending_submit / pending_reject)
    // supaya ACC & hapus tetap dibatasi hanya untuk admin.
    if ($key === 'pending-kalkulasi') {

        http_response_code(403);

        echo json_encode([
            'success' => false,
            'message' => 'Gunakan endpoint pending_add / pending_customer_acc / pending_submit / pending_reject'
        ]);

        exit;
    }

    $json = json_encode(
        $value,
        JSON_UNESCAPED_UNICODE
    );

    try {

        $stmt = $pdo->prepare("
            INSERT INTO kv_store
            (key_name, value)
            VALUES
            (:key, :value)
            ON DUPLICATE KEY UPDATE
            value = :value2
        ");

        $stmt->execute([
            ':key' => $key,
            ':value' => $json,
            ':value2' => $json
        ]);

        echo json_encode([
            'success' => true
        ]);

    } catch (Exception $e) {

        http_response_code(500);

        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }

    exit;
}



/* =========================================================
   STORAGE DELETE
   ========================================================= */

if ($action === 'storage_delete') {

    $input = json_decode(
        file_get_contents('php://input'),
        true
    );

    $key = $input['key'] ?? '';

    if ($key === '') {

        http_response_code(400);

        echo json_encode([
            'success' => false,
            'message' => 'Key kosong'
        ]);

        exit;
    }

    $stmt = $pdo->prepare("
        DELETE FROM kv_store
        WHERE key_name = :key
    ");

    $stmt->execute([
        ':key' => $key
    ]);

    echo json_encode([
        'success' => true
    ]);

    exit;
}



/* =========================================================
   GET RIWAYAT
   Dipanggil oleh:
   getRiwayat()
   ========================================================= */

if ($action === 'riwayat') {

    try {

        $stmt = $pdo->query("
            SELECT id, data, created_at
            FROM riwayat
            ORDER BY created_at DESC
        ");

        $items = [];

        while ($row = $stmt->fetch()) {

            $decoded = json_decode(
                $row['data'],
                true
            );

            if (!is_array($decoded)) {
                $decoded = [];
            }

            $decoded['id'] = (string)$row['id'];

            $items[] = $decoded;
        }

        echo json_encode([
            'success' => true,
            'items' => $items
        ]);

    } catch (Exception $e) {

        http_response_code(500);

        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }

    exit;
}



/* =========================================================
   ADD RIWAYAT
   Dipanggil oleh:
   addRiwayat()
   ========================================================= */

if ($action === 'riwayat_add') {

    requireAdmin();

    $item = json_decode(
        file_get_contents('php://input'),
        true
    );

    if (!is_array($item) || empty($item['id'])) {

        http_response_code(400);

        echo json_encode([
            'success' => false,
            'message' => 'Data tidak valid'
        ]);

        exit;
    }

    try {

        $stmt = $pdo->prepare("
            INSERT INTO riwayat
            (id, data, created_at)
            VALUES
            (:id, :data, :created_at)
            ON DUPLICATE KEY UPDATE
            data = :data2
        ");

        $json = json_encode(
            $item,
            JSON_UNESCAPED_UNICODE
        );

        $stmt->execute([
            ':id' => $item['id'],
            ':data' => $json,
            ':created_at' => $item['timestamp'] ?? time(),
            ':data2' => $json
        ]);

        echo json_encode([
            'success' => true,
            'message' => 'Data berhasil masuk riwayat'
        ]);

    } catch (Exception $e) {

        http_response_code(500);

        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }

    exit;
}



/* =========================================================
   DELETE RIWAYAT
   Dipanggil oleh:
   deleteRiwayat()
   ========================================================= */

if ($action === 'riwayat_delete') {

    requireAdmin();

    $input = json_decode(
        file_get_contents('php://input'),
        true
    );

    $id = $input['id'] ?? '';

    if ($id === '') {

        http_response_code(400);

        echo json_encode([
            'success' => false,
            'message' => 'ID tidak ditemukan'
        ]);

        exit;
    }

    try {

        $stmt = $pdo->prepare("
            DELETE FROM riwayat
            WHERE id = :id
        ");

        $stmt->execute([
            ':id' => $id
        ]);

        echo json_encode([
            'success' => true,
            'message' => 'Riwayat berhasil dihapus'
        ]);

    } catch (Exception $e) {

        http_response_code(500);

        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }

    exit;
}



/* =========================================================
   KONVERSI ADD
   Menyimpan data Konversi/Volume ke tabel yang mudah dibaca.
   ========================================================= */

if ($action === 'konversi_add') {

    $item = json_decode(file_get_contents('php://input'), true);

    if (!is_array($item) || empty($item['id'])) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Data konversi tidak valid'
        ]);
        exit;
    }

    $id = (string)$item['id'];
    $tipe = (string)($item['type'] ?? 'konversi');
    $nama = (string)($item['nama'] ?? 'Prospek Tanpa Nama');
    $bahan = isset($item['bahan']) ? (string)$item['bahan'] : null;
    $markup = (float)($item['markup'] ?? 0);
    $sPanjang = isset($item['sPanjang']) ? (float)$item['sPanjang'] : null;
    $sBerat = isset($item['sBerat']) ? (float)$item['sBerat'] : null;
    $status = (string)($item['status'] ?? 'MENUNGGU_ACC');
    $timestamp = isset($item['timestamp']) ? (int)$item['timestamp'] : time();
    $rawJson = json_encode($item, JSON_UNESCAPED_UNICODE);

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            INSERT INTO konversi
            (id, tipe, nama_prospek, bahan, markup_berat,
             panjang_sample_cm, berat_sample_gram, status,
             timestamp_data, raw_json)
            VALUES
            (:id, :tipe, :nama, :bahan, :markup,
             :panjang, :berat, :status,
             :timestamp, :raw)
            ON DUPLICATE KEY UPDATE
                tipe = VALUES(tipe),
                nama_prospek = VALUES(nama_prospek),
                bahan = VALUES(bahan),
                markup_berat = VALUES(markup_berat),
                panjang_sample_cm = VALUES(panjang_sample_cm),
                berat_sample_gram = VALUES(berat_sample_gram),
                status = VALUES(status),
                timestamp_data = VALUES(timestamp_data),
                raw_json = VALUES(raw_json)
        ");

        $stmt->execute([
            ':id' => $id,
            ':tipe' => $tipe,
            ':nama' => $nama,
            ':bahan' => $bahan,
            ':markup' => $markup,
            ':panjang' => $sPanjang,
            ':berat' => $sBerat,
            ':status' => $status,
            ':timestamp' => $timestamp,
            ':raw' => $rawJson
        ]);

        // Hindari duplikasi detail saat data yang sama disimpan ulang.
        $pdo->prepare("DELETE FROM konversi_harga WHERE konversi_id = :id")
            ->execute([':id' => $id]);
        $pdo->prepare("DELETE FROM konversi_varian WHERE konversi_id = :id")
            ->execute([':id' => $id]);

        // Untuk Konversi biasa: item['varians']; untuk Volume: satu baris ringkasan.
        $variants = [];
        if (!empty($item['varians']) && is_array($item['varians'])) {
            $variants = $item['varians'];
        } else {
            $variants = [[
                'nama' => $item['chosenFrom'] ?? 'Hasil Volume',
                'panjang' => null,
                'beratKonversiG' => $item['chosenG'] ?? null,
                'beratKonversiKg' => isset($item['chosenG']) ? ((float)$item['chosenG'] / 1000) : null,
                'beratMarkupKg' => $item['beratMarkupKg'] ?? null,
                'bbRows' => $item['bbRows'] ?? []
            ]];
        }

        $varStmt = $pdo->prepare("
            INSERT INTO konversi_varian
            (konversi_id, nama_varian, panjang_target_cm,
             berat_konversi_gram, berat_konversi_kg, berat_markup_kg)
            VALUES
            (:konversi_id, :nama, :panjang, :gram, :kg, :markupkg)
        ");

        $priceStmt = $pdo->prepare("
            INSERT INTO konversi_harga
            (konversi_id, varian_id, harga_bb_kg, harga_penawaran_pcs)
            VALUES
            (:konversi_id, :varian_id, :bb, :harga)
        ");

        foreach ($variants as $v) {
            if (!is_array($v)) continue;

            $varStmt->execute([
                ':konversi_id' => $id,
                ':nama' => (string)($v['nama'] ?? 'Varian'),
                ':panjang' => isset($v['panjang']) ? (float)$v['panjang'] : null,
                ':gram' => isset($v['beratKonversiG']) ? (float)$v['beratKonversiG'] : null,
                ':kg' => isset($v['beratKonversiKg']) ? (float)$v['beratKonversiKg'] : null,
                ':markupkg' => isset($v['beratMarkupKg']) ? (float)$v['beratMarkupKg'] : null
            ]);

            $varianId = (int)$pdo->lastInsertId();

            if (!empty($v['bbRows']) && is_array($v['bbRows'])) {
                foreach ($v['bbRows'] as $b) {
                    if (!is_array($b)) continue;

                    $priceStmt->execute([
                        ':konversi_id' => $id,
                        ':varian_id' => $varianId,
                        ':bb' => (float)($b['bb'] ?? 0),
                        ':harga' => (float)($b['harga'] ?? 0)
                    ]);
                }
            }
        }

        $pdo->commit();

        echo json_encode([
            'success' => true,
            'message' => 'Data konversi berhasil disimpan lengkap'
        ]);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Gagal menyimpan konversi',
            'error' => $e->getMessage()
        ]);
    }

    exit;
}


/* =========================================================
   PENDING ADD
   Dipanggil oleh user biasa saat mengirim hasil perhitungan
   dari halaman Konversi / Metode Volume ke Pending.
   Tidak butuh admin.
   ========================================================= */

if ($action === 'pending_add') {

    $item = json_decode(
        file_get_contents('php://input'),
        true
    );

    if (!is_array($item) || empty($item['id'])) {

        http_response_code(400);

        echo json_encode([
            'success' => false,
            'message' => 'Data tidak valid'
        ]);

        exit;
    }

    $list = kvGet($pdo, 'pending-kalkulasi');
    if (!is_array($list)) $list = [];

    array_unshift($list, $item);

    kvSet($pdo, 'pending-kalkulasi', $list);

    echo json_encode([
        'success' => true
    ]);

    exit;
}



/* =========================================================
   PENDING — CUSTOMER ACC
   ADMIN ONLY
   ========================================================= */

if ($action === 'pending_customer_acc') {

    requireAdmin();

    $input = json_decode(
        file_get_contents('php://input'),
        true
    );

    $id = $input['id'] ?? '';

    if ($id === '') {

        http_response_code(400);

        echo json_encode([
            'success' => false,
            'message' => 'ID tidak ditemukan'
        ]);

        exit;
    }

    $list = kvGet($pdo, 'pending-kalkulasi');
    if (!is_array($list)) $list = [];

    $found = false;

    foreach ($list as &$it) {
        if (($it['id'] ?? '') === $id) {
            $it['status'] = 'CUSTOMER_ACC';
            $found = true;
            break;
        }
    }
    unset($it);

    if (!$found) {

        http_response_code(404);

        echo json_encode([
            'success' => false,
            'message' => 'Data tidak ditemukan di Pending'
        ]);

        exit;
    }

    kvSet($pdo, 'pending-kalkulasi', $list);

    try {
        $stmt = $pdo->prepare("
            UPDATE konversi
            SET status = 'CUSTOMER_ACC'
            WHERE id = :id
        ");
        $stmt->execute([':id' => $id]);
    } catch (Exception $e) {
        // Pending tetap tersimpan walau tabel detail gagal di-update.
    }

    echo json_encode([
        'success' => true
    ]);

    exit;
}



/* =========================================================
   PENDING — SUBMIT KE DATABASE (RIWAYAT)
   ADMIN ONLY
   ========================================================= */

if ($action === 'pending_submit') {

    requireAdmin();

    $input = json_decode(
        file_get_contents('php://input'),
        true
    );

    $id = $input['id'] ?? '';

    if ($id === '') {

        http_response_code(400);

        echo json_encode([
            'success' => false,
            'message' => 'ID tidak ditemukan'
        ]);

        exit;
    }

    $list = kvGet($pdo, 'pending-kalkulasi');
    if (!is_array($list)) $list = [];

    $idx = null;
    foreach ($list as $i => $it) {
        if (($it['id'] ?? '') === $id) {
            $idx = $i;
            break;
        }
    }

    if ($idx === null) {

        http_response_code(404);

        echo json_encode([
            'success' => false,
            'message' => 'Data tidak ditemukan di Pending'
        ]);

        exit;
    }

    $item = $list[$idx];

    if (($item['status'] ?? '') !== 'CUSTOMER_ACC') {

        http_response_code(400);

        echo json_encode([
            'success' => false,
            'message' => 'Data belum di-ACC customer'
        ]);

        exit;
    }

    try {

        $json = json_encode(
            $item,
            JSON_UNESCAPED_UNICODE
        );

        $stmt = $pdo->prepare("
            INSERT INTO riwayat
            (id, data, created_at)
            VALUES
            (:id, :data, :created_at)
            ON DUPLICATE KEY UPDATE
            data = :data2
        ");

        $stmt->execute([
            ':id' => $item['id'],
            ':data' => $json,
            ':created_at' => $item['timestamp'] ?? time(),
            ':data2' => $json
        ]);

        array_splice($list, $idx, 1);
        kvSet($pdo, 'pending-kalkulasi', $list);

        $stmtStatus = $pdo->prepare("
            UPDATE konversi
            SET status = 'SELESAI'
            WHERE id = :id
        ");
        $stmtStatus->execute([':id' => $id]);

        echo json_encode([
            'success' => true,
            'message' => 'Data berhasil masuk ke Database'
        ]);

    } catch (Exception $e) {

        http_response_code(500);

        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }

    exit;
}



/* =========================================================
   PENDING — HAPUS / SELESAI (REJECT)
   ADMIN ONLY
   ========================================================= */

if ($action === 'pending_reject') {

    requireAdmin();

    $input = json_decode(
        file_get_contents('php://input'),
        true
    );

    $id = $input['id'] ?? '';

    if ($id === '') {

        http_response_code(400);

        echo json_encode([
            'success' => false,
            'message' => 'ID tidak ditemukan'
        ]);

        exit;
    }

    $list = kvGet($pdo, 'pending-kalkulasi');
    if (!is_array($list)) $list = [];

    $newList = array_values(array_filter(
        $list,
        function ($it) use ($id) {
            return ($it['id'] ?? '') !== $id;
        }
    ));

    kvSet($pdo, 'pending-kalkulasi', $newList);

    echo json_encode([
        'success' => true
    ]);

    exit;
}



/* =========================================================
   ACTION LAMA — TETAP DIPERTAHANKAN
   Biar sistem lama tidak rusak
   ========================================================= */


/* =========================
   PENDING GET
   ========================= */

if ($action === 'pending_get') {

    try {

        $stmt = $pdo->query("
            SELECT *
            FROM konversi
            WHERE status IN ('PENDING', 'ACC')
            ORDER BY id DESC
        ");

        $items = [];

        while ($row = $stmt->fetch()) {

            $decoded = json_decode(
                $row['data'],
                true
            );

            if (!is_array($decoded)) {
                $decoded = [];
            }

            $decoded['id'] = (string)$row['id'];
            $decoded['status'] = $row['status'];
            $decoded['created_at'] = $row['created_at'];

            $items[] = $decoded;
        }

        echo json_encode([
            'success' => true,
            'items' => $items
        ]);

    } catch (Exception $e) {

        http_response_code(500);

        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }

    exit;
}



/* =========================
   PENDING ACC (legacy, tabel "konversi" tidak lagi dipakai front-end)
   ========================= */

if ($action === 'pending_acc') {

    $input = json_decode(
        file_get_contents('php://input'),
        true
    );

    $id = $input['id'] ?? null;

    if (!$id) {

        http_response_code(400);

        echo json_encode([
            'success' => false,
            'message' => 'ID tidak ditemukan'
        ]);

        exit;
    }

    try {

        $stmt = $pdo->prepare("
            UPDATE konversi
            SET status = 'ACC'
            WHERE id = :id
        ");

        $stmt->execute([
            ':id' => $id
        ]);

        echo json_encode([
            'success' => true
        ]);

    } catch (Exception $e) {

        http_response_code(500);

        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }

    exit;
}



/* =========================
   SUBMIT
   ========================= */

if ($action === 'submit') {

    $input = json_decode(
        file_get_contents('php://input'),
        true
    );

    $id = $input['id'] ?? null;

    if (!$id) {

        http_response_code(400);

        echo json_encode([
            'success' => false,
            'message' => 'ID tidak ditemukan'
        ]);

        exit;
    }

    try {

        $stmt = $pdo->prepare("
            SELECT status
            FROM konversi
            WHERE id = :id
        ");

        $stmt->execute([
            ':id' => $id
        ]);

        $row = $stmt->fetch();

        if (!$row) {

            http_response_code(404);

            echo json_encode([
                'success' => false,
                'message' => 'Data tidak ditemukan'
            ]);

            exit;
        }

        if ($row['status'] !== 'ACC') {

            http_response_code(400);

            echo json_encode([
                'success' => false,
                'message' => 'Data belum di-ACC'
            ]);

            exit;
        }

        $stmt = $pdo->prepare("
            UPDATE konversi
            SET status = 'SELESAI'
            WHERE id = :id
        ");

        $stmt->execute([
            ':id' => $id
        ]);

        echo json_encode([
            'success' => true
        ]);

    } catch (Exception $e) {

        http_response_code(500);

        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }

    exit;
}



/* =========================
   DELETE LAMA
   ========================= */

if ($action === 'delete') {

    $input = json_decode(
        file_get_contents('php://input'),
        true
    );

    $id = $input['id'] ?? null;

    if (!$id) {

        http_response_code(400);

        echo json_encode([
            'success' => false,
            'message' => 'ID tidak ditemukan'
        ]);

        exit;
    }

    try {

        $stmt = $pdo->prepare("
            DELETE FROM konversi
            WHERE id = :id
        ");

        $stmt->execute([
            ':id' => $id
        ]);

        echo json_encode([
            'success' => true
        ]);

    } catch (Exception $e) {

        http_response_code(500);

        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }

    exit;
}



/* =========================
   ACTION TIDAK DIKENAL
   ========================= */

http_response_code(404);

echo json_encode([
    'success' => false,
    'message' => 'Action tidak ditemukan'
]);
