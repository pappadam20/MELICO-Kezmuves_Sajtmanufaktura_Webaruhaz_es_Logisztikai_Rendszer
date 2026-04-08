/* 
==================== MELICO – RÓLUNK OLDAL ====================

Leírás:
Ez a fájl a MELICO webáruház „Rólunk” oldalát valósítja meg, amely bemutatja
a vállalkozás célját, termékeit, beszállítóit, valamint a vásárlói élményt.

Fő funkciók:
- Session kezelés és felhasználói állapot ellenőrzés
- Aktív kupon kezelése és visszaszámláló megjelenítése
- Jogosultság alapú megjelenítés (pl. admin menüpont)
- Dinamikus navigációs menü (bejelentkezés / profil / kosár)
- Kosár darabszám megjelenítése
- Reszponzív és modern UI elemek használata

Technikai megoldások:
- PHP session alapú felhasználókezelés
- JavaScript alapú kupon visszaszámláló (real-time frissítéssel)
- CSS és külső ikon könyvtárak (Remixicon) használata
- Strukturált HTML szekciók (about blokkok)
- ScrollReveal animációk támogatása

Biztonsági és logikai elemek:
- Kupon lejárat ellenőrzése szerver oldalon
- Session adatok tisztítása lejárt kupon esetén
- Admin jogosultság külön kezelése
- Alapvető kliensoldali integritás ellenőrzés (védelem manipuláció ellen)

Cél:
Egy informatív, esztétikus és felhasználóbarát oldal biztosítása,
amely erősíti a márka hitelességét és támogatja a vásárlói döntést.

===============================================================
*/

<?php
include "db.php";
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Ellenőrizd, hogy van-e aktív kupon
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

// Ellenőrizd, hogy a felhasználó admin-e
$isAdmin = isset($_SESSION['role']) && $_SESSION['role'] == '2';
?>
<!DOCTYPE html>
<html lang="hu">
<head>
   <meta charset="UTF-8">
   <meta name="viewport" content="width=device-width, initial-scale=1.0">

   <link rel="shortcut icon" href="assets/img/logo/MELICO LOGO 2.png" type="image/x-icon">
   <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/remixicon/3.7.0/remixicon.css">
   <link rel="stylesheet" href="assets/css/styles.css">

   <title>MELICO – Rólunk</title>

   <style>
    /* Egy kis extra stílus a visszaszámlálónak */
       .coupon-alert {
           background: linear-gradient(90deg, #ffbc3f, #ff9f00);
           color: #1a150e;
           padding: 1rem;
           text-align: center;
           font-weight: bold;
           border-bottom: 2px solid #e68a00;
           display: none; /* Alapértelmezetten rejtve, JS fedi fel ha van aktív kupon */
       }
       #timer {
         color: #000000;
         font-weight: bold;
         margin-left: 5px;
      }

      .coupon-main {
         margin-right: 10px;
      }

      .coupon-divider {
         margin: 0 10px;
         opacity: 0.6;
      }

      .coupon-expiry {
         background: rgb(255, 0, 81);
         padding: 3px 8px;
         border-radius: 6px;
      }

      #timer {
         font-family: monospace;
         font-size: 1.2rem;
         margin-left: 5px;
      }
    </style>
</head>
<body>
