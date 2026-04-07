<?php 
/*========================================================
  PROFIL OLDAL – BACKEND RENDSZER
========================================================
  Ez a fájl felel a felhasználói profil teljes működéséért:

  Fő funkciók:
  - felhasználói session kezelése
  - profil adatok módosítása
  - jelszócsere
  - rendelés kezelés (lemondás)
  - kupon rendszer kezelése
  - értesítések kezelése
  - fiók törlés
========================================================*/

session_start();
include "db.php";



/*========================================================
  RENDSZER BEÁLLÍTÁSOK (ADMIN KONFIG)
========================================================
  Adatbázisból dinamikusan érkezik:
  - maximális kedvezményes termékek száma
========================================================*/
$settings_res = $conn->query("SELECT max_discounted_items FROM SETTINGS LIMIT 1");
$settings = $settings_res->fetch_assoc();
$max_allowed_discounted = $settings['max_discounted_items'] ?? 1;



/*========================================================
  RENDELÉS LEMONDÁSA
========================================================
  Funkció:
  - csak "Megrendelve" státuszú rendelés törölhető
  - készlet visszakerül a raktárba
  - rendelés státusz: "Lemondva"
========================================================*/
if (isset($_POST['cancel_order'])) {
    $order_id = intval($_POST['order_id']);
    $u_id = $_SESSION['user_id'];

    /* Ellenőrzés: jogosult-e a törlésre */
    $check_stmt = $conn->prepare("SELECT id FROM ORDERS WHERE id = ? AND user_id = ? AND status = 'Megrendelve'");
    $check_stmt->bind_param("ii", $order_id, $u_id);
    $check_stmt->execute();
    $res = $check_stmt->get_result();

    if ($res->num_rows > 0) {
        /* Rendelés tételeinek lekérése */
        $items_stmt = $conn->prepare("SELECT product_id, quantity FROM ORDER_ITEMS WHERE order_id = ?");
        $items_stmt->bind_param("i", $order_id);
        $items_stmt->execute();
        $items_result = $items_stmt->get_result();

        /* Készlet visszatöltése */
        while ($item = $items_result->fetch_assoc()) {
            $p_id = $item['product_id'];
            $qty = $item['quantity'];
            
            $update_stock = $conn->prepare("UPDATE PRODUCTS SET stock = stock + ? WHERE id = ?");
            $update_stock->bind_param("ii", $qty, $p_id);
            $update_stock->execute();
            $update_stock->close();
        }
        $items_stmt->close();

        /* Rendelés státusz módosítása */
        $update_status = $conn->prepare("UPDATE ORDERS SET status = 'Lemondva' WHERE id = ?");
        $update_status->bind_param("i", $order_id);
        $update_status->execute();
        $update_status->close();
    }

    $check_stmt->close();
    header("Location: profil.php?tab=Orders");
    exit();
}



/*========================================================
  KUPON LEJÁRATI KEZELÉS (SESSION ALAPÚ)
========================================================*/
$discount = 0;
$expiry_timestamp = 0;

if (isset($_SESSION['user_id'])) {
    if (isset($_SESSION['coupon_expiry']) && $_SESSION['coupon_expiry'] > time()) {
        $discount = $_SESSION['coupon_discount'] ?? 0;
        $expiry_timestamp = $_SESSION['coupon_expiry'] * 1000; // JS-nek milliszekundum
    } else {
        unset($_SESSION['coupon_discount']);
        unset($_SESSION['coupon_expiry']);
    }
}



/*========================================================
  VISSZA NAVIGÁCIÓ KEZELÉSE
========================================================
  Biztonságos visszalépés csak engedélyezett oldalakra
========================================================*/
$allowed_pages = [
    'admin.php',
    'futar.php',
    'szallitas.php',
    'index.php',
    'kapcsolatfelvetel.php',
    'rolunk.php',
    'termekeink.php'
];

if (isset($_SERVER['HTTP_REFERER'])) {
    $ref_url = parse_url($_SERVER['HTTP_REFERER'], PHP_URL_PATH); // csak az útvonal
    $ref_page = basename($ref_url); // az oldal neve pl. index.php

    if (in_array($ref_page, $allowed_pages)) {
        $back_url = $_SERVER['HTTP_REFERER'];
    } else {
        $back_url = 'index.php';
    }
} else {
    $back_url = 'index.php';
}
