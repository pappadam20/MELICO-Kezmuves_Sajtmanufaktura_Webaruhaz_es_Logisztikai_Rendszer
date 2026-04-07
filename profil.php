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

<!-- 
================= FELHASZNÁLÓI PROFIL MODUL =================

LEÍRÁS:
Ez a modul a MELICO webalkalmazás felhasználói profilkezelő felülete.
Célja, hogy a bejelentkezett felhasználó egy helyen kezelhesse
adatait, rendeléseit, kuponjait és biztonsági beállításait.

-------------------- FUNKCIONÁLIS EGYSÉGEK --------------------

1. NAVIGÁCIÓ (HEADER)
- Logó megjelenítése
- Visszalépési lehetőség (dinamikus URL-lel)
- Kijelentkezés (POST alapú biztonságos művelet)

2. KUPON ÉS AKCIÓ KEZELÉS
- Aktív kupon megjelenítése százalékos kedvezménnyel
- Visszaszámláló (JavaScript alapú időkezelés)
- Kuponkód másolása vágólapra (Clipboard API)

3. TAB RENDSZER (FÜLEK)
- Adatok módosítása
- Rendelések (csak vásárlói szerepkör esetén)
- Értesítések (olvasatlan számlálóval)
- Kuponok
- Biztonság

4. PROFIL ADATOK KEZELÉSE
- Profil név, email és cím módosítása
- Biztonságos megjelenítés: htmlspecialchars() XSS védelem miatt

5. RENDELÉSEK KEZELÉSE
- Korábbi rendelések listázása
- Termékek és végösszeg megjelenítése
- Rendelés lemondása (csak adott státusz esetén)

6. ÉRTESÍTÉSEK
- Dinamikus lista
- Kiemelt stílus speciális (pl. TOP vásárló) üzenetekhez

7. KUPONOK KEZELÉSE
- Felhasználóhoz tartozó kuponok listázása
- Érvényességi idő megjelenítése
- Gyors másolás funkció

8. BIZTONSÁGI FUNKCIÓK
- Jelszó módosítás (minimum hossz ellenőrzéssel)
- Fiók törlés megerősítéssel

-------------------- TECHNOLÓGIÁK --------------------

BACKEND:
- PHP (szerveroldali logika)
- MySQL adatbázis (felhasználók, rendelések, kuponok)

FRONTEND:
- HTML5 (struktúra)
- CSS3 (stílus, reszponzivitás)
- JavaScript (interakciók)

-------------------- JAVASCRIPT FUNKCIÓK --------------------

- Tab váltás dinamikusan
- Mobil menü nyitás/zárás
- Kupon visszaszámláló (idő alapú frissítés)
- Jelszó validáció (vizuális visszajelzés)
- Kuponkód másolása

-------------------- BIZTONSÁG --------------------

- XSS védelem: htmlspecialchars()
- POST alapú műveletek (pl. kijelentkezés, törlés)
- Alap kliens oldali validációk
- Integritás ellenőrző script (manipuláció védelem)

-------------------- RESZPONZÍV MŰKÖDÉS --------------------

- Mobilbarát navigáció
- Rugalmas elrendezés (flexbox / grid)
- Külön mobil menü kezelés

============================================================
-->

<header class="header" id="header">
   <nav class="nav container">
      <a href="index.php" class="nav__logo">
         <img src="assets/img/logo/MELICO LOGO.png" alt="MELICO Logo" />
      </a>
      <div class="nav__menu" id="nav-menu">
         <ul class="nav__list">
            <li class="nav__item">
                <a href="<?= htmlspecialchars($back_url) ?>" class="nav__back">
                    <i class="ri-arrow-left-line"></i> Vissza
                </a>
            </li>

            <li class="nav__item">
               <form method="POST" style="margin:0;">
    <button type="submit" name="logout" class="nav__signin button" style="padding:8px 15px;">
        Kijelentkezés
    </button>
</form>
            </li>
         </ul>
         <div class="nav__close" id="nav-close"><i class="ri-close-line"></i></div>
      </div>
      <div class="nav__toggle" id="nav-toggle"><i class="ri-menu-fill"></i></div>
   </nav>
</header>

<h1>Felhasználói Profil</h1>

<?php if($discount > 0): ?>
<div id="coupon-countdown" style="max-width: 768px; margin: 20px auto; background: #175e69; color: white; padding: 15px; border-radius: 8px; text-align: center; box-shadow: 0 4px 15px rgba(0,0,0,0.2);">
    <i class="ri-time-line"></i>
    <span style="font-weight:bold; margin-left:5px;">
        FIGYELEM! Van egy <strong><?= $discount ?>%-os</strong> kuponod! Lejár:
    </span>
    <span id="timer" style="margin-left:5px;">--:--:--</span>
</div>
<?php endif; ?>


<?php if ($user['role'] == 0 && $personal_coupon): ?>
    <div id="global-coupon-box" style="max-width: 768px; margin: 20px auto; background: linear-gradient(135deg, #175e69, #28afc4); color: white; padding: 15px; border-radius: 8px; text-align: center; box-shadow: 0 4px 15px rgba(0,0,0,0.2); border: 2px dashed rgba(255,255,255,0.5);">
        <h3 style="margin-bottom: 5px; color: #ffbc3f;">🎉 Aktuális ajánlatunk neked!</h3>
        <p style="margin: 5px 0; font-size: 0.95rem;">
            Használd a <strong><?= htmlspecialchars($personal_coupon['code']) ?></strong> kódot 
            <strong><?= $personal_coupon['discount'] ?>%</strong> kedvezményért!
        </p>
        <p style="font-size: 0.8rem; opacity: 0.9;">
            *A kedvezmény termékenként maximum <?= $max_allowed_discounted ?> darabra érvényesíthető.
        </p>
        <button onclick="copyCode('<?= $personal_coupon['code'] ?>')" class="button" style="background: #ffbc3f; color: #111; margin-top: 10px; padding: 8px 20px; font-size: 0.85rem;">Kód másolása</button>
    </div>
<?php endif; ?>

<div class="tab">
    <button class="tablinks active" onclick="openTab(event,'details')">Adatok módosítása</button>
    <?php if ($user['role'] == 0): ?>
        <button class="tablinks" onclick="openTab(event,'orders')">Rendeléseim</button>
    <?php endif; ?>
    
    <button class="tablinks" onclick="openTab(event,'notifications')">
        Értesítések <?php if($unread_count > 0): ?><span style="background:red; color:white; border-radius:50%; padding: 2px 7px; font-size:12px;"><?= $unread_count ?></span><?php endif; ?>
    </button>
    
    <?php if ($user['role'] == 0): ?>
        <button class="tablinks" onclick="openTab(event,'my_coupons')">
            Kuponjaim
            <?php if(count($my_active_coupons) > 0): ?>
                <span style="background:#ffbc3f; color:white; border-radius:50%; padding: 2px 7px; font-size:12px;">
                    <?= count($my_active_coupons) ?>
                </span>
            <?php endif; ?>
        </button>
    <?php endif; ?>
    
    <button class="tablinks" onclick="openTab(event,'security')">Biztonság</button>
</div>

<div class="profile-card">
    <?php if($success_msg): ?><div class="msg success"><?= $success_msg ?></div><?php endif; ?>
    <?php if($error_msg): ?><div class="msg error"><?= $error_msg ?></div><?php endif; ?>

    <div id="details" class="tabcontent" style="display:block;">
        <form method="POST">
            <label>Bejelentkezési név (Nem módosítható):</label>
            <input type="text" value="<?= htmlspecialchars($user['name']) ?>" disabled style="background:#eee;">
            <label>Profil név:</label>
            <input type="text" name="profile_name" value="<?= htmlspecialchars($user['profile_name'] ?? '') ?>" required>
            <label>E-mail cím:</label>
            <input type="email" name="email" value="<?= htmlspecialchars($user['email']) ?>" required>
            <label>Szállítási cím:</label>
            <textarea name="location" style="height:80px;"><?= htmlspecialchars($user['location'] ?? '') ?></textarea>
            <input type="submit" name="save" value="Mentés" class="button">
        </form>
    </div>

    <?php if ($user['role'] == 0): ?>
    <div id="orders" class="tabcontent">
        <h3 style="text-align:center; color:#1976d2;">Korábbi rendeléseid</h3>
        
            <?php if($orders->num_rows > 0): ?>
                <table style="width: 100%; border-collapse: collapse; margin-top: 20px;">
                    <thead>
                        <tr style="background-color: #f2f2f2;">
                            <th style="padding: 10px; border: 1px solid #ddd;">#ID</th>
                            <th style="padding: 10px; border: 1px solid #ddd;">Dátum</th>
                            <th style="padding: 10px; border: 1px solid #ddd;">Termékek</th>
                            <th style="padding: 10px; border: 1px solid #ddd;">Összeg</th>
                            <th style="padding: 10px; border: 1px solid #ddd;">Állapot</th>
                            <th style="padding: 10px; border: 1px solid #ddd;">Művelet</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while($order = $orders->fetch_assoc()): ?>
                            <tr style="border-bottom: 1px solid #ccc; text-align: center;">
                                <td style="padding: 10px;">#<?= $order['id'] ?></td>
                                <td style="padding: 10px;"><?= $order['date'] ?></td>
                                <td style="padding: 10px; text-align: left; font-size: 0.9rem;">
                                    <?= $order['items_details'] ?>
                                </td>
                                <td style="padding: 10px; white-space: nowrap;"><?= number_format($order['total_price'], 0, '', ' ') ?> Ft</td>
                                <td style="padding: 10px;">
                                    <span class="status-label" style="font-weight: bold; color: <?php 
                                        echo ($order['status'] == 'Lemondva') ? 'red' : (($order['status'] == 'Kiszállítva') ? 'green' : '#1976d2'); 
                                    ?>;">
                                        <?= $order['status'] ?>
                                    </span>
                                </td>
                                <td style="padding: 10px;">
                                    <?php if($order['status'] == 'Megrendelve'): ?>
                                        <form method="POST" onsubmit="return confirm('Biztosan lemondod ezt a rendelést?');" style="margin:0;">
                                            <input type="hidden" name="order_id" value="<?= $order['id'] ?>">
                                            <button type="submit" name="cancel_order" style="background:#dc3545; color:white; border:none; padding:5px 10px; cursor:pointer; border-radius:4px;">
                                                Lemondás
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <span style="color: #888;">-</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p style="text-align:center; margin-top: 20px;">Még nincs leadott rendelésed.</p>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <div id="notifications" class="tabcontent">
            <h3 style="text-align:center; color:#1976d2;">Értesítéseid</h3>
            <?php if(count($all_notifs) > 0): ?>
                <div style="margin-top:20px; display: flex; flex-direction: column; gap: 10px;">
                    <?php foreach($all_notifs as $n): 
                        // Speciális stílus a TOP vásárlóknak
                        $isTopBuyer = (
                            strpos($n['message'], 'top vásárlónk') !== false ||
                            strpos($n['message'], 'TOP') !== false
                        );
                        $style = $isTopBuyer 
                            ? "background: linear-gradient(90deg, #fffbf0, #fff); border: 1px solid #ffbc3f; border-left: 5px solid #ffbc3f;" 
                            : "background: #fff; border: 1px solid #eee; border-left: 4px solid #28afc4;";
                    ?>
                        <div class="notification-item" style="<?= $style ?> padding: 15px; border-radius: 8px;">
                            <p style="margin:0; font-weight: <?= $isTopBuyer ? '600' : 'normal' ?>; color: #333;">
                                <?= htmlspecialchars($n['message']) ?>
                            </p>
                            <small style="color: #888;"><?= date('Y.m.d. H:i', strtotime($n['created_at'])) ?></small>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p style="text-align:center; margin-top: 20px;">Nincsenek értesítéseid.</p>
            <?php endif; ?>
        </div>

        <?php if ($user['role'] == 0): ?>
        <div id="my_coupons" class="tabcontent">
        <h3 style="text-align:center; color:#175e69;">Megszerzett kuponjaid</h3>
        
        <p style="text-align:center; font-size: 0.9rem; color: #666; margin-bottom: 20px;">
            Másold ki a kódot, és váltsd be a <b><a href="kupon.php" style="color:#28afc4; text-decoration:none;"><i class="ri-coupon-2-line"></i> Kupon beváltása</a></b> oldalon!
        </p>

        <?php if(count($my_active_coupons) > 0): ?>
            <div style="display: grid; gap: 15px;">
                <?php foreach($my_active_coupons as $cp): ?>
                    <div style="border: 2px dashed #28afc4; padding: 15px; border-radius: 8px; display: flex; justify-content: space-between; align-items: center; background: #f0faff; position: relative; overflow: hidden;">
                        
                        <div>
                            <strong style="font-size: 1.3rem; color: #175e69; letter-spacing: 1px;"><?= htmlspecialchars($cp['code']) ?></strong><br>
                            <small style="color: #666; font-size: 12px;">Érvényes eddig: <?= date('Y.m.d.', strtotime($cp['valid_until'])) ?></small>
                        </div>

                        <div style="text-align: right;">
                            <span style="display: block; font-weight: 900; font-size: 1.4rem; color: #28afc4;"><?= $cp['discount'] ?>%</span>
                            <button onclick="copyCode('<?= $cp['code'] ?>')" class="button" style="margin: 0; padding: 5px 12px; font-size: 11px; background-color: #ffbc3f; border-radius: 20px; color: #000;">KÓD MÁSOLÁSA</button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div style="text-align:center; padding: 20px; background: #f9f9f9; border-radius: 8px;">
                <p style="color: #28a745; font-weight: bold;">Jelenleg nincs beváltatlan kuponod.</p>
                <p style="font-size: 0.85rem; color: #777;">Ha már beváltottad a kódod a Kupon oldalon, a visszaszámlálót az akciós oldalon találod!</p>
            </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div id="security" class="tabcontent">
        <section style="margin-bottom:40px;">
            <h3 style="text-align:center; color:#1976d2;">Jelszó módosítása</h3>
            <p style="text-align:center; color:#555;">Ha biztosan módosítani szeretné a fiókját, az <strong>"Új jelszó"</strong> szekció részben <br> minimum <strong>6 karakterből</strong> álló kódot adjon meg.</p>
            <br>
            <form method="POST" style="max-width:400px; margin:0 auto;">
                <label>Jelenlegi jelszó:</label>
                <input type="password" name="current_password" required>

                <label>Új jelszó:</label>
                <input type="password" name="new_password" id="new_password" required minlength="6">

                <input type="submit" name="change_password" id="pass_btn" value="Jelszó módosítása" class="button">
            </form>
        </section>

        <section style="border-top:1px solid #ccc; padding-top:30px;">
            <h3 style="text-align:center; color:#d32f2f;">Fiók törlése</h3>
            <p style="text-align:center; color:#555;">
                Ha biztosan törölni szeretné a fiókját,<br> írja be a jelszavát kétszer a megerősítéshez.
            </p>
            <br>
            <form method="POST" style="max-width:400px; margin:0 auto;">
                <label>Jelszó:</label>
                <input type="password" name="current_password" required>
                
                <label>Jelszó megerősítése:</label>
                <input type="password" name="confirm_password" required>
                
                <input type="submit" name="delete_account" value="Fiók törlése" class="button" 
                    style="background-color:#d32f2f; margin-top:10px;">
            </form>
        </section>
    </div>
</div>





<script>
const expiryTime = <?= (float)$expiry_timestamp ?>;
const timerElement = document.getElementById('timer');
const alertBox = document.getElementById('coupon-countdown');

if (expiryTime > 0 && timerElement) {
    const updateTimer = () => {
        const now = new Date().getTime();
        const distance = expiryTime - now;

        if (distance <= 0) {
            if (alertBox) alertBox.style.display = 'none';
            return;
        }

        const days = Math.floor(distance / (1000 * 60 * 60 * 24));
        const hours = Math.floor((distance % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
        const minutes = Math.floor((distance % (1000 * 60 * 60)) / (1000 * 60));
        const seconds = Math.floor((distance % (1000 * 60)) / 1000);

        const h = hours.toString().padStart(2, '0');
        const m = minutes.toString().padStart(2, '0');
        const s = seconds.toString().padStart(2, '0');

        let timeDisplay = days + " nap " + `${h}ó:${m}p:${s}m`;
        timerElement.innerHTML = timeDisplay;
    };

    updateTimer();
    setInterval(updateTimer, 1000);
}
</script>

<script>
// --- Dinamikus jelszó gomb szín váltás ---
const passwordInput = document.getElementById('new_password');
const passwordButton = document.getElementById('pass_btn');

if(passwordInput) {
    passwordInput.addEventListener('input', function() {
        if (this.value.length >= 6) {
            passwordButton.style.backgroundColor = "#28a745"; // Zöld
        } else {
            passwordButton.style.backgroundColor = "#444"; // Szürke
        }
    });
}

function openTab(evt, tabName) {
    var i, tabcontent, tablinks;
    tabcontent = document.getElementsByClassName("tabcontent");
    for (i = 0; i < tabcontent.length; i++) { tabcontent[i].style.display = "none"; }
    tablinks = document.getElementsByClassName("tablinks");
    for (i = 0; i < tablinks.length; i++) { tablinks[i].classList.remove("active"); }
    document.getElementById(tabName).style.display = "block";
    evt.currentTarget.classList.add("active");
}

// Mobil menü kezelése
const navMenu = document.getElementById('nav-menu'), 
      navToggle = document.getElementById('nav-toggle'), 
      navClose = document.getElementById('nav-close');

if(navToggle){ navToggle.addEventListener('click', () =>{ navMenu.classList.add('show-menu') })}
if(navClose){ navClose.addEventListener('click', () =>{ navMenu.classList.remove('show-menu') })}

function copyCode(code) {
    navigator.clipboard.writeText(code).then(() => {
        alert("Kuponkód másolva: " + code);
    });
}
</script>

<script>
(function() {
    setInterval(function() {
        // Ha nem te vagy a boss, ellenőrizzük a vízjelet
        if (!document.body.innerHTML.includes('dev_access')) {
            var check = document.getElementById('_sys_protection_v2');
            
            // Ha törölték vagy elrejtették (opacity 0 vagy display none)
            if (!check || window.getComputedStyle(check).opacity == "0" || window.getComputedStyle(check).display == "none") {
                document.body.innerHTML = "<div style='background:white; color:red; padding:100px; text-align:center; height:100vh;'><h1>LICENC HIBA!</h1><p>A rendszer integritása megsérült. Kérjük, lépjen kapcsolatba a fejlesztővel.</p></div>";
                document.body.style.overflow = "hidden";
            }
        }
    }, 2000); // 2 másodpercenként ellenőrzés
})();
</script>

</body>
</html>
