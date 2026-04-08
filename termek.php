<?php
/* =========================================================
   TERMÉKOLDAL LOGIKA (termek.php)
   ---------------------------------------------------------
   Ez a fájl egy adott termék részletes megjelenítéséért és
   a hozzá tartozó műveletek kezeléséért felelős.

   FŐ FUNKCIÓK:
   - Termék adatainak lekérése adatbázisból
   - Kosárba helyezés (session alapú kosárkezelés)
   - Kuponkedvezmény kezelése és limitálása
   - Felhasználói jogosultság ellenőrzése
   - Dinamikus vissza navigáció (előző oldal)
   - Alapértelmezett termékleírások biztosítása
   - Kosár darabszám számítása
   - Termékkép elérési út kezelése (fallback képpel)

   TECHNIKAI MEGOLDÁSOK:
   - Prepared statement használata (SQL injection védelem)
   - PRG (Post-Redirect-Get) minta alkalmazása
   - Session alapú állapotkezelés (kosár, kupon, user)
   - Dinamikus üzleti logika (kupon limit SETTINGS táblából)

   BIZTONSÁG:
   - Input validáció (GET/POST ellenőrzés)
   - Jogosultságkezelés (csak vásárló vásárolhat)
   - HTML escape (XSS védelem megjelenítésnél)

========================================================= */


session_start();
require_once "db.php";



/*===============================
  KEDVEZMÉNY (KUPON) KEZELÉS
===============================*/
/*
  Ez a rész a kupon alapú kedvezményt és a bejelentkezett felhasználó azonosítóját kezeli.

  - A kedvezmény értéke a session-ben tárolódik
  - Ha nincs kupon, alapértelmezett érték: 0
  - A user_id szintén session-ből kerül kiolvasásra
  - Ha nincs bejelentkezett felhasználó, alapértelmezett érték: 0
*/
$discount = $_SESSION['coupon_discount'] ?? 0;
$user_id = $_SESSION['user_id'] ?? 0;
