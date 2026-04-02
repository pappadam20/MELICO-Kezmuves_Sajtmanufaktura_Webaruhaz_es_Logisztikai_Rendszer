<?php 
/*=============== SESSION ÉS ADATBÁZIS KAPCSOLAT ===============*/
session_start();        // Munkamenet indítása (felhasználói adatok tárolása)
include "db.php";       // Adatbázis kapcsolat betöltése


/*=============== RENDSZERBEÁLLÍTÁSOK LEKÉRÉSE ===============*/
// A kupon érvényességi idejének lekérdezése az adatbázisból
$settings_res = $conn->query("SELECT coupon_validity_days FROM SETTINGS LIMIT 1");
$settings = $settings_res->fetch_assoc();
$validity_days = $settings['coupon_validity_days'] ?? 7;    // Alapértelmezett: 7 nap


/*=============== BEJELENTKEZÉS ELLENŐRZÉSE ===============*/
// Ha nincs bejelentkezett felhasználó → átirányítás
if (!isset($_SESSION['user_id'])) {
    header("Location: signIn.php");
    exit();
}
$uid = $_SESSION['user_id'];    // Aktuális felhasználó ID


/*=============== ÜZENETEK INICIALIZÁLÁSA ===============*/
$success_msg = "";
$error_msg = "";


/*=============== VISSZA GOMB LOGIKA ===============*/
// Engedélyezett oldalak listája
$allowed_pages = ['admin.php', 'futar.php', 'index.php', 'kapcsolatfelvetel.php', 'rolunk.php', 'termekeink.php'];
$back_url = 'index.php';    // alapértelmezett vissza oldal

// HTTP_REFERER ellenőrzése (előző oldal)
if (isset($_SERVER['HTTP_REFERER'])) {
    $ref_path = parse_url($_SERVER['HTTP_REFERER'], PHP_URL_PATH);
    $ref_page = basename($ref_path);

    // Csak engedélyezett oldalakra lehessen visszamenni
    if (in_array($ref_page, $allowed_pages)) {
        $back_url = $_SERVER['HTTP_REFERER'];
    }
}


/*=============== KUPON BEVÁLTÁS LOGIKA ===============*/
if(isset($_POST['redeem_coupon'])) {

    $code = trim($_POST['coupon_code']);    // Kuponkód tisztítása
    $found = false;  // Találtunk-e érvényes kupont

    /*----------- AKTÍV KUPON ELLENŐRZÉS -----------*/
    // Ha már van aktív kupon → nem válthat be újat
    if (isset($_SESSION['coupon_expiry']) && $_SESSION['coupon_expiry'] > time()) {
        $remaining = $_SESSION['coupon_expiry'] - time();
        $hours = floor($remaining / 3600);
        $minutes = floor(($remaining % 3600) / 60);
        
        $error_msg = "Már van egy aktív kuponod! Újat csak a jelenlegi lejárta után válthatsz be. (Hátravan: $hours óra $minutes perc)";
        $found = true; 
    } else {

        /*----------- A: EGYÉNI KUPON KERESÉSE -----------*/
        $stmt = $conn->prepare("
            SELECT UC.id AS user_coupon_id, C.id AS coupon_id, C.discount, C.max_items 
            FROM USER_COUPONS UC
            JOIN COUPONS C ON UC.coupon_id = C.id
            WHERE UC.user_id = ? AND UC.used = 0 AND UPPER(C.code) = UPPER(?) AND C.valid_until >= NOW()
            LIMIT 1
        ");
        $stmt->bind_param("is", $uid, $code);
        $stmt->execute();
        $res = $stmt->get_result();

        // Ha találtunk egyéni kupont
        if($res->num_rows > 0) {
            $row = $res->fetch_assoc();

            // Kupon felhasználtnak jelölése
            $update = $conn->prepare("UPDATE USER_COUPONS SET used = 1 WHERE id = ?");
            $update->bind_param("i", $row['user_coupon_id']);
            if($update->execute()) {

                /* SESSION adatok mentése */
                $_SESSION['coupon_discount'] = $row['discount'];
                $_SESSION['coupon_expiry'] = time() + ($validity_days * 86400);
                $_SESSION['coupon_max_items'] = $row['max_items'];

                /* Adatok mentése adatbázisba */
                $save = $conn->prepare("UPDATE users SET coupon_discount=?, coupon_expiry=? WHERE id=?");
                $save->bind_param("iii", $_SESSION['coupon_discount'], $_SESSION['coupon_expiry'], $uid);
                $save->execute();

                $success_msg = "Kupon beváltva! Kedvezmény: " . $row['discount'] . "%";
                $found = true;
            }
        } 
