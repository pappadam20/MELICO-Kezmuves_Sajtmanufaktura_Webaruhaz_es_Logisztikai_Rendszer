<?php
session_start(); // Munkamenet indítása (felhasználói adatok, kosár stb. tárolása)

// 1. ADATBÁZIS KAPCSOLAT
$host = "localhost";
$user = "root";     
$pass = "";
$dbname = "melico";

// Kapcsolódás létrehozása
$conn = new mysqli($host, $user, $pass, $dbname);

// Hibaellenőrzés
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);



/* =====================
   KUPON ÉRVÉNYESSÉGI IDŐ MENTÉSE
=====================*/
if (isset($_POST['save_coupon_duration'])) {
    $days = intval($_POST['coupon_validity_days']); // Napok száma integerként
    
    // Frissítés az adatbázisban
    $stmt = $conn->prepare("UPDATE SETTINGS SET coupon_validity_days = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("i", $days);
        $stmt->execute();
        $stmt->close();

        // Visszajelzés + átirányítás
        echo "<script>alert('Érvényességi idő frissítve!'); window.location.href='admin.php?tab=stats';</script>";
    }
}

// Beállítások lekérése
$res = $conn->query("SELECT * FROM SETTINGS LIMIT 1");
$row = $res->fetch_assoc();
$coupon_validity_days = $row['coupon_validity_days'] ?? 7; // alapértelmezett: 7 nap



/* =====================
   HŰSÉGPROGRAM BEÁLLÍTÁSOK
=====================*/
if (isset($_POST['update_threshold'])) {
    $new_threshold = intval($_POST['loyalty_threshold']);
    $max_items = intval($_POST['max_discounted_items']);
    $max_usage = intval($_POST['max_usage_limit']);
    
    // Beállítások frissítése
    $stmt = $conn->prepare("UPDATE SETTINGS SET loyalty_threshold = ?, max_discounted_items = ?, max_usage_limit = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("iii", $new_threshold, $max_items, $max_usage);
        $stmt->execute();
        $stmt->close();
        echo "<script>alert('Beállítások frissítve!'); window.location.href='admin.php?tab=stats';</script>";
    }
}

// Frissített adatok lekérése
$res = $conn->query("SELECT * FROM SETTINGS LIMIT 1");
$row = $res->fetch_assoc();
$loyalty_threshold = $row['loyalty_threshold'] ?? 49999;
$max_discounted_items = $row['max_discounted_items'] ?? 1;
$max_usage_limit = $row['max_usage_limit'] ?? 1;



/* =====================
   KUPON GENERÁLÓ FÜGGVÉNY
=====================*/
function checkAndGenerateUserCoupons($userId, $conn) {
    // Beállítások lekérése
    $set_res = $conn->query("SELECT loyalty_threshold, coupon_percent FROM SETTINGS LIMIT 1");
    $settings = $set_res->fetch_assoc();
    $threshold = $settings['loyalty_threshold'] ?? 49999;
    $discount_pct = $settings['coupon_percent'] ?? 10;

    // Összes költés kiszámítása
    $stmt = $conn->prepare("
        SELECT SUM(OI.quantity * OI.sale_price) as total_money
        FROM ORDERS O
        JOIN ORDER_ITEMS OI ON O.id = OI.order_id
        WHERE O.user_id = ? AND O.status = 'Kiszállítva'
    ");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $total_money = $stmt->get_result()->fetch_assoc()['total_money'] ?? 0;
    $stmt->close();

    // Meghatározzuk, hány kupon jár az összköltés alapján
    $coupon_level = floor($total_money / $threshold);

    // Meglévő kuponok száma
    $stmt_count = $conn->prepare("SELECT COUNT(*) as cnt FROM USER_COUPONS WHERE user_id = ?");
    $stmt_count->bind_param("i", $userId);
    $stmt_count->execute();
    $existing = $stmt_count->get_result()->fetch_assoc()['cnt'];
    $stmt_count->close();

    // Új kuponok generálása
    if ($coupon_level > $existing) {
        for ($i = $existing; $i < $coupon_level; $i++) {
            $code = strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 8));
            
            // Kupon létrehozása
            $st_ins = $conn->prepare("INSERT INTO COUPONS (code, discount, valid_until) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 7 DAY))");
            $st_ins->bind_param("si", $code, $discount_pct);
            $st_ins->execute();
            $coupon_id = $st_ins->insert_id;

            // Felhasználóhoz rendelés
            $st_link = $conn->prepare("INSERT INTO USER_COUPONS (user_id, coupon_id) VALUES (?, ?)");
            $st_link->bind_param("ii", $userId, $coupon_id);
            $st_link->execute();
        }
    }
}
