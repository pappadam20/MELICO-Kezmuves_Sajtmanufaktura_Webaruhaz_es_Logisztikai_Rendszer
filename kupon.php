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
