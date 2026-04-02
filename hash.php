<?php
/* 
=============== JELSZÓ HASH GENERÁLÁS ===============

Ez a rövid PHP szkript biztonságos, titkosított jelszavakat (hash-eket) generál
a rendszerben használt teszt felhasználók számára.

A password_hash() függvény a PHP beépített, biztonságos algoritmusát használja
(jelenleg bcrypt), így a jelszavak nem kerülnek tárolásra sima szövegként
az adatbázisban, ami növeli a rendszer biztonságát.

A generált hash-eket később be lehet illeszteni az USERS tábla "password" mezőjébe.

Teszt jelszavak:
- admin123     --> Admin felhasználó
- futar123     --> Futár felhasználó
- vasarlo123   --> Vásárló felhasználó

A "<br>" tagek biztosítják, hogy a hash-ek külön sorokban jelenjenek meg a böngészőben.
*/

echo password_hash("admin123", PASSWORD_DEFAULT);
echo "<br>";
echo password_hash("futar123", PASSWORD_DEFAULT);
echo "<br>";
echo password_hash("vasarlo123", PASSWORD_DEFAULT);
?>
