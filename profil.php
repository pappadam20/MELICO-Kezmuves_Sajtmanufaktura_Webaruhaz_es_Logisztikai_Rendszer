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



/*========================================================
  BELÉPÉS ELLENŐRZÉS (SECURITY)
========================================================*/
if (!isset($_SESSION['user_id'])) {
    header("Location: signIn.php");
    exit();
}

$user_id = $_SESSION['user_id']; // Most már biztonságosan használhatjuk bárhol alatta



/*========================================================
  ÉRTESÍTÉSEK AUTOMATIKUS OLVASOTTRA ÁLLÍTÁSA
========================================================*/
$update_notif = $conn->prepare("UPDATE NOTIFICATIONS SET is_read = 1 WHERE user_id = ? AND is_read = 0");
$update_notif->bind_param("i", $user_id);
$update_notif->execute();



/*========================================================
  ÜZENET VÁLTOZÓK
========================================================*/
$success_msg = "";
$error_msg = "";



/*========================================================
  KIJELENTKEZÉS
========================================================*/
if (isset($_POST['logout']) || isset($_GET['logout'])) {
    session_destroy();
    header("Location: index.php");
    exit();
}



/*========================================================
  PROFIL ADATOK MENTÉSE
========================================================*/
if (isset($_POST['save'])) {
    $profile_name = trim($_POST['profile_name']);
    $email        = trim($_POST['email']);
    $location     = trim($_POST['location']);

    if (empty($profile_name) || empty($email)) {
        $error_msg = "A profil név és az e-mail mező nem lehet üres!";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error_msg = "Érvénytelen e-mail formátum!";
    } else {
        $check_email = $conn->prepare("SELECT id FROM USERS WHERE email = ? AND id != ?");
        $check_email->bind_param("si", $email, $user_id);
        $check_email->execute();
        $res = $check_email->get_result();

        if ($res->num_rows > 0) {
            $error_msg = "Ez az e-mail cím már használatban van!";
        } else {
            $stmt = $conn->prepare("UPDATE USERS SET profile_name=?, email=?, location=? WHERE id=?");
            $stmt->bind_param("sssi", $profile_name, $email, $location, $user_id);
            
            if ($stmt->execute()) {
                $success_msg = "Adatok sikeresen frissítve!";
            } else {
                $error_msg = "Hiba történt a mentés során.";
            }
        }
    }
}



/*========================================================
  JELSZÓ CSERE
========================================================*/
if (isset($_POST['change_password'])) {
    $current_pass = $_POST['current_password'];
    $new_pass = $_POST['new_password'];

    if (strlen($new_pass) < 6) {
        $error_msg = "A jelszónak legalább 6 karakternek kell lennie!";
    } else {
        $stmt = $conn->prepare("SELECT password FROM USERS WHERE id=?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $current_hashed = $result['password'];

        if (!password_verify($current_pass, $current_hashed)) {
            $error_msg = "Hibás jelenlegi jelszó!";
        } elseif (password_verify($new_pass, $current_hashed)) {
            $error_msg = "Ez már a jelenlegi jelszó!";
        } else {
            $hashed_password = password_hash($new_pass, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE USERS SET password=? WHERE id=?");
            $stmt->bind_param("si", $hashed_password, $user_id);

            if ($stmt->execute()) {
                $success_msg = "Jelszó frissítve!";
            } else {
                $error_msg = "Hiba történt.";
            }
        }
    }
}



/*========================================================
  FELHASZNÁLÓ ADATOK LEKÉRÉSE
========================================================*/
$stmt = $conn->prepare("SELECT name, profile_name, location, email, role FROM USERS WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

if (!$user) {
    session_destroy();
    header("Location: signIn.php");
    exit();
}

$isAdmin = ($user['role'] == '2');



/*========================================================
  KUPON RENDSZER (SZEMÉLYES + GLOBÁLIS)
========================================================*/

/* Személyes kupon */
$personal_coupon = null;
if ($user['role'] == '0') {
    $personal_coupon_query = "
        SELECT C.id, C.code, C.discount, C.valid_until 
        FROM USER_COUPONS UC
        JOIN COUPONS C ON UC.coupon_id = C.id
        WHERE UC.user_id = ? AND UC.used = 0 AND C.valid_until >= NOW()
        ORDER BY UC.assigned_at DESC LIMIT 1";

    $p_stmt = $conn->prepare($personal_coupon_query);
    $p_stmt->bind_param("i", $user_id);
    $p_stmt->execute();
    $personal_coupon = $p_stmt->get_result()->fetch_assoc();
    $p_stmt->close();
}


// --- GLOBÁLIS AKTÍV KUPON LEKÉRÉSE ---
$now = date("Y-m-d H:i:s");
$global_coupon_query = "SELECT id, code, discount, valid_until FROM COUPONS 
                        WHERE valid_until >= ? 
                        ORDER BY id DESC LIMIT 1";
$g_stmt = $conn->prepare($global_coupon_query);
$g_stmt->bind_param("s", $now);
$g_stmt->execute();
$global_coupon = $g_stmt->get_result()->fetch_assoc();

$is_global_used = false;
if ($global_coupon) {
    // Ellenőrizzük, hogy a felhasználó felhasználta-e már ezt a konkrét globális kupont
    $check_used = $conn->prepare("SELECT id FROM USER_COUPONS WHERE user_id = ? AND coupon_id = ? AND used = 1");
    $check_used->bind_param("ii", $user_id, $global_coupon['id']);
    $check_used->execute();
    if ($check_used->get_result()->num_rows > 0) {
        $is_global_used = true;
    }
}

// 5. RENDELÉSEK LEKÉRÉSE
$order_query = $conn->prepare("
    SELECT 
        O.id, 
        O.date, 
        O.status, 
        SUM(OI.quantity * OI.sale_price) AS total_price,
        GROUP_CONCAT(CONCAT(P.name, ' (', C.name, ') - ', OI.quantity, ' db') SEPARATOR '<br>') AS items_details
    FROM ORDERS O
    LEFT JOIN ORDER_ITEMS OI ON O.id = OI.order_id
    LEFT JOIN PRODUCTS P ON OI.product_id = P.id
    LEFT JOIN CATEGORIES C ON P.category_id = C.id
    WHERE O.user_id = ? 
    GROUP BY O.id 
    ORDER BY O.date DESC
");
$order_query->bind_param("i", $user_id);
$order_query->execute();
$orders = $order_query->get_result();

// =================== 5.5 ÉRTESÍTÉSEK LEKÉRÉSE ===================
$notif_stmt = $conn->prepare("SELECT * FROM NOTIFICATIONS WHERE user_id = ? ORDER BY created_at DESC");
$notif_stmt->bind_param("i", $user_id);
$notif_stmt->execute();
$notif_res = $notif_stmt->get_result();

$unread_count = 0;
$all_notifs = [];
while($n = $notif_res->fetch_assoc()){
    if($n['is_read'] == 0) $unread_count++;
    $all_notifs[] = $n;
}
// ===============================================================

// =================== 5.6 KUPONOK LEKÉRÉSE ===================
// Csak azokat a kuponokat kérjük le, amiket a user MEGKAPOTT, de MÉG NEM VÁLTOTT BE (used = 0)
$coupon_stmt = $conn->prepare("
    SELECT C.id, C.code, C.discount, C.valid_until 
    FROM USER_COUPONS UC 
    JOIN COUPONS C ON UC.coupon_id = C.id 
    WHERE UC.user_id = ? AND UC.used = 0 AND C.valid_until >= NOW()
    ORDER BY C.valid_until ASC
");
$coupon_stmt->bind_param("i", $user_id);
$coupon_stmt->execute();
$coupons_res = $coupon_stmt->get_result();
$my_active_coupons = $coupons_res->fetch_all(MYSQLI_ASSOC);

// ===============================================================

// 6. FELHASZNÁLÓ FIÓK TÖRLÉSE
if (isset($_POST['delete_account'])) {
    $current_pass = $_POST['current_password'] ?? '';
    $confirm_pass = $_POST['confirm_password'] ?? '';

    if ($current_pass !== $confirm_pass) {
        $error_msg = "A jelszavak nem egyeznek!"; // Hiba, ha nem egyezik
    } else {
        // Lekérjük a jelenlegi jelszót az adatbázisból
        $stmt = $conn->prepare("SELECT password FROM USERS WHERE id=?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $hashed_password = $result['password'];

        if (!password_verify($current_pass, $hashed_password)) {
            $error_msg = "Hibás jelszó! A fiók nem lett törölve.";
        } else {
            // Fiók törlése
            $stmt = $conn->prepare("DELETE FROM USERS WHERE id=?");
            $stmt->bind_param("i", $user_id);
            if ($stmt->execute()) {
                session_destroy();
                header("Location: index.php?msg=account_deleted");
                exit();
            } else {
                $error_msg = "Hiba történt a fiók törlése közben.";
            }
        }
    }
}
?>
<!-- 
    PROFIL OLDAL (MELICO)

    Ez a HTML dokumentum a felhasználói profil oldal megjelenítéséért felel.
    Tartalmazza:
    - az alap meta adatokat (karakterkódolás, reszponzivitás),
    - a külső CSS fájlok betöltését (globális stílusok, ikonok),
    - valamint egyedi, oldalhoz tartozó beágyazott stílusokat.

    Az oldal fő funkciói:
    - felhasználói adatok megjelenítése és szerkesztése,
    - rendelések listázása státuszokkal,
    - értesítések kezelése,
    - többfüles (tabos) navigáció a különböző profil részek között.

    A beépített CSS biztosítja:
    - a profil kártya kinézetét,
    - a tabos navigáció stílusát,
    - táblázatok formázását,
    - státusz jelöléseket (pl. rendelés állapota),
    - visszajelző üzenetek (success/error) megjelenítését,
    - valamint az értesítések vizuális kiemelését.

    Az oldal reszponzív, így különböző képernyőméreteken is megfelelően jelenik meg.
-->

<!DOCTYPE html>
<html lang="hu">
<head>
    <!-- Alap meta adatok és stílusok betöltése a profil oldalhoz -->
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">  <!-- Reszponzív megjelenítés mobil eszközökön -->

    <!-- Az oldal címe a böngésző fülön -->
    <title>MELICO - Profilom</title>

    <!-- Külső CSS fájl betöltése (globális stílusok) -->
    <link rel="stylesheet" href="assets/css/styles.css">

    <!-- Ikon készlet (Remixicon) betöltése -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/remixicon/3.7.0/remixicon.css">

    <style>
        /* Oldal főcímének (h1) formázása:
        Középre igazítja a címet, valamint felső és alsó margót ad,
        hogy vizuálisan elkülönüljön a többi tartalomtól. */
        h1 { 
            text-align: center; 
            margin-bottom: 20px; 
            margin-top: 30px; 
        }

        /* Tab gombok konténerének elrendezése:
        Flexbox segítségével középre igazított, egymás melletti gombok,
        térközzel és mobilbarát tördeléssel. */
        .tab { 
            display: flex; 
            justify-content: center; 
            gap: 10px; 
            margin-bottom: 15px; 
            flex-wrap: wrap; 
        }

        /* Tab gombok alap stílusa:
        Egységes megjelenésű, kattintható gombok lekerekített sarkokkal. */
        .tab button { 
            padding: 10px 20px; 
            border: none; 
            background-color: #28afc4; 
            color: white; 
            cursor: pointer; 
            border-radius: 4px; 
            font-weight: 500; 
        }

        /* Aktív tab kiemelése:
        Sötétebb háttérszín jelzi az aktuálisan kiválasztott fület. */
        .tab button.active { 
            background-color: #175e69; 
        }

        /* Profil kártya (fő konténer):
        Középre igazított, árnyékolt doboz, amely tartalmazza a profil tartalmát. */
        .profile-card { 
            background-color: #fff; 
            border-radius: 3px; 
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.35); 
            width: 768px; 
            max-width: 90%; 
            margin: 0 auto 50px auto; 
            padding: 20px; 
            display: flex; 
            flex-direction: column; 
        }

        /* Tab tartalmak alapból rejtve:
        JavaScript segítségével jelennek meg a megfelelő fül kiválasztásakor. */
        .tabcontent { 
            display: none; 
        }

        /* Táblázat alap stílus:
        Egységes kinézet, teljes szélesség kihasználása. */
        table { 
            border-collapse: collapse; 
            width: 100%; 
            margin-top: 20px; 
        }

        /* Táblázat cellák:
        Keretezett, jól olvasható cellák megfelelő belső margóval. */
        th, td { 
            border: 1px solid #ccc; 
            padding: 12px; 
            text-align: left; 
            vertical-align: top; 
        }

        /* Táblázat fejléc:
        Világos háttérrel kiemeli az oszlopneveket. */
        th { 
            background-color: #f9f9f9; 
        }

        /* Input mezők és űrlap elemek:
        Egységes kinézetű beviteli mezők lekerekített sarkokkal. */
        input, textarea, select { 
            width: 100%; 
            padding: 10px; 
            margin: 8px 0; 
            border: 1px solid #ccc; 
            border-radius: 4px; 
            box-sizing: border-box; 
        }

        /* Általános gomb stílus:
        Egységes kinézetű akciógombok (pl. mentés, módosítás). */
        .button { 
            padding: 10px 20px; 
            margin-top: 10px; 
            display: inline-block; 
            background-color: #175e69; 
            color: white; 
            text-decoration: none; 
            border-radius: 4px; 
            border: none; 
            cursor: pointer; 
            font-weight: bold; 
            text-align: center; 
            transition: background-color 0.3s ease; 
        }

        /* Gomb hover effekt:
        Színváltás vizuális visszajelzésként. */
        .button:hover { 
            background-color: #00b2cd; 
        }

        /* Jelszó gomb külön stílusa:
        Sötétebb háttérrel különbözteti meg a többi gombtól. */
        #pass_btn { 
            background-color: #444; 
        }

        /* Rendelés státusz jelző (badge):
        Kis kapszula alakú címke a rendelés állapotának megjelenítésére. */
        .status-badge { 
            padding: 5px 10px; 
            border-radius: 20px; 
            font-size: 12px; 
            font-weight: bold; 
            display: inline-block; 
        }

        /* Különböző státuszok színezése:
        Vizualizálja a rendelés aktuális állapotát. */
        .status-Megrendelve { 
            background: #e3f2fd; 
            color: #1976d2; 
        }

        .status-Szállítás { 
            background: #fff3e0; 
            color: #f57c00; 
        }

        .status-Kiszállítva { 
            background: #e8f5e9; 
            color: #388e3c; 
        }

        /* Üzenetek (siker/hiba):
        Középre igazított visszajelző dobozok. */
        .msg { 
            padding: 10px; 
            border-radius: 4px; 
            margin-bottom: 15px; 
            text-align: center; 
            width: 100%; 
            box-sizing: border-box;
        }

        /* Siker üzenet:
        Zöld háttérrel jelzi a sikeres műveletet. */
        .success { 
            background-color: #d4edda; 
            color: #155724; 
        }

        /* Hiba üzenet:
        Piros háttérrel jelzi a hibát vagy sikertelen műveletet. */
        .error { 
            background-color: #f8d7da; 
            color: #721c24; 
        }

        /* Vissza navigáció link:
        Ikonnal ellátott visszalépési lehetőség. */
        .nav__back { 
            display: flex; 
            align-items: center; 
            column-gap: .5rem; 
            color: var(--title-color); 
            font-weight: var(--font-medium); 
            transition: .3s; 
        }

        /* Ikon méret:
        A vissza gomb ikon méretének beállítása. */
        .nav__back i { 
            font-size: 1.25rem; 
        }

        /* Hover effekt a vissza gombon:
        Színváltás interaktív visszajelzésként. */
        .nav__back:hover { 
            color: #ffbc3f; 
        }

        /* Értesítés elemek:
        Listaelemek egységes kinézete és elválasztása. */
        .notification-item { 
            padding: 15px; 
            border-bottom: 1px solid #eee; 
            transition: background 0.3s; 
        }

        /* Olvasatlan értesítés kiemelése:
        Kiemelt háttér és bal oldali sáv jelzi az új üzenetet. */
        .notification-unread { 
            background-color: #f0faff; 
            border-left: 4px solid #28afc4; 
        }

        /* Értesítés időbélyeg:
        Halványabb színnel jelenik meg az időinformáció. */
        .notification-item small { 
            color: #888; 
            display: block; 
            margin-top: 5px; 
        }

    </style>
</head>
<body>
