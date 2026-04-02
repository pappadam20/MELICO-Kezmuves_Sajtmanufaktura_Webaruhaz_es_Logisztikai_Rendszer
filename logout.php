/*========================================================
  KIJELENTKEZÉS (LOGOUT) FUNKCIÓ
==========================================================

  Ez a fájl felel a felhasználó biztonságos kijelentkeztetéséért.

  Lépések:
  1. Session indítása (hozzáférés a munkamenethez)
  2. Session változók törlése
  3. Session megszüntetése
  4. Visszairányítás a főoldalra (index.php)

  Cél:
  - felhasználói adatok biztonságos törlése
  - kijelentkezés utáni védett oldalak elérése megszűnik
=========================================================*/

<?php
// Session indítása, hogy hozzáférjünk a felhasználói munkamenethez
session_start();

/* 
   Minden session változó törlése
   (pl. user_id, login státusz stb.)
*/
session_unset();

/* 
   A teljes session megszüntetése
   -> kijelentkezteti a felhasználót
*/
session_destroy();

/*
   Átirányítás a főoldalra
   -> logout után nem maradhat a védett oldalon
*/
header("Location: index.php");
exit();
?>
