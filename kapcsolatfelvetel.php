/*=============== BACKEND + SESSION KEZELÉS ===============*/
/*
  - Adatbázis kapcsolat betöltése
  - Session indítása (ha még nincs elindítva)
*/
<?php
include "db.php";

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}



/*=============== KUPON LOGIKA ===============*/
/*
  Cél:
  - Ellenőrzi, van-e aktív kupon a felhasználónál
  - Ha lejárt -> törli a sessionből
  - Ha aktív -> átadja a kedvezményt és lejárati időt JS-nek
*/
$discount = 0;
$expiry_timestamp = 0;

if (isset($_SESSION['user_id'])) {

   // Kupon érvényesség ellenőrzése
    if (isset($_SESSION['coupon_expiry']) && $_SESSION['coupon_expiry'] > time()) {
        $discount = $_SESSION['coupon_discount'] ?? 0;

        // JavaScript kompatibilis idő (ms)
        $expiry_timestamp = $_SESSION['coupon_expiry'] * 1000;
    } else {
      // lejárt kupon törlése
        unset($_SESSION['coupon_discount']);
        unset($_SESSION['coupon_expiry']);
    }
}



/*=============== ADMIN JOGOSULTSÁG ===============*/
/*
  Ellenőrzi, hogy a bejelentkezett felhasználó admin-e.
  role = 2 -> admin jogosultság
*/
$isAdmin = isset($_SESSION['role']) && $_SESSION['role'] == '2';
?>



<!DOCTYPE html>
<html lang="hu">
<head>
   <!--=============== META INFORMÁCIÓK ===============-->
   <meta charset="UTF-8">
   <meta name="viewport" content="width=device-width, initial-scale=1.0">

   <!--=============== FAVICON ===============-->
   <link rel="shortcut icon" href="assets/img/logo/MELICO LOGO 2.png" type="image/x-icon">

   <!--=============== IKONOK (REMIX ICON) ===============-->
   <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/remixicon/3.7.0/remixicon.css">

   <!--=============== SAJÁT STÍLUSLAP ===============-->
   <link rel="stylesheet" href="assets/css/styles.css">

   <title>MELICO – Kapcsolatfelvétel</title>


   <!--=============== KUPON VISSZASZÁMLÁLÓ STÍLUS ===============-->
   <style>

      /* Kupon értesítési sáv:
         A felhasználó számára megjelenő figyelmeztető sáv, amely az aktuális kuponról ad információt.
         Alapból rejtett (display: none), és JavaScript jeleníti meg dinamikusan. */
       .coupon-alert {
           background: linear-gradient(90deg, #ffbc3f, #ff9f00);
           color: #1a150e;
           padding: 1rem;
           text-align: center;
           font-weight: bold;
           border-bottom: 2px solid #e68a00;
           display: none; /* JS jeleníti meg */
       }

       /* Visszaszámláló (timer):
          A kupon lejárati idejét mutatja valós időben. */
       #timer {
         color: #000000;
         font-weight: bold;
         margin-left: 5px;
      }

      /* Kupon fő tartalom:
         A kupon szöveges részének elrendezéséhez használt elem. */
      .coupon-main {
         margin-right: 10px;
      }

      /* Elválasztó elem:
         Vizuális elválasztást biztosít a kupon információk között. */
      .coupon-divider {
         margin: 0 10px;
         opacity: 0.6;
      }

      /* Kupon lejárati jelzés:
         Kiemeli a lejárati időt egy figyelemfelkeltő háttérrel. */
      .coupon-expiry {
         background: rgb(255, 0, 81);
         padding: 3px 8px;
         border-radius: 6px;
      }

      /* Timer részletes stílus:
         Monospace betűtípus a pontos idő megjelenítéshez,
         nagyobb méret a jobb olvashatóság érdekében. */
      #timer {
         font-family: monospace;
         font-size: 1.2rem;
         margin-left: 5px;
      }
    </style>
</head>
<body>
