<?php
/*
====================================================
TERMÉKLISTÁZÓ ÉS KOSÁRKEZELŐ LOGIKA (termekeink.php)
====================================================

Ez a fájl a webáruház egyik központi eleme, amely a következő fő feladatokat látja el:

1. MUNKAMENET KEZELÉS
- A session indításával biztosítja a felhasználói adatok (pl. kosár, kuponok) tárolását.
- Eltárolja az utolsó meglátogatott oldalt a navigáció megkönnyítésére.

2. ADATBÁZIS KAPCSOLAT
- A db.php fájlon keresztül csatlakozik az adatbázishoz.
- Lekérdezi a szükséges adatokat (termékek, kategóriák, beállítások).

3. KUPON- ÉS KEDVEZMÉNYKEZELÉS
- Ellenőrzi, hogy a felhasználónak van-e aktív kuponja.
- Figyelembe veszi a kupon lejárati idejét.
- Dinamikusan alkalmazza a kedvezményt a termékekre.
- A kedvezményes termékek darabszámát a SETTINGS táblában megadott limit szabályozza.

4. KOSÁRKEZELÉS (SESSION ALAPÚ)
- A felhasználó kosarát a session-ben tárolja.
- Termék hozzáadásakor:
  -> ellenőrzi a jogosultságot (csak vásárló adhat hozzá),
  -> ellenőrzi a raktárkészletet,
  -> kezeli a kedvezményes és normál árú tételeket külön.
- Biztosítja, hogy a felhasználó ne vásárolhasson többet, mint a rendelkezésre álló készlet.

5. KATEGÓRIÁK ÉS TERMÉKEK MEGJELENÍTÉSE
- Lekérdezi a kategóriákat és azokhoz tartozó termékeket.
- A renderCategory() függvény felel a termékek dinamikus megjelenítéséért.
- Megjeleníti:
  -> termék nevét,
  -> árát (kedvezményesen vagy normál áron),
  -> készlet állapotát vizuális jelöléssel,
  -> képet (fallback képpel, ha nincs megadva).

6. DINAMIKUS KUPON KVÓTA KEZELÉS
- Figyelembe veszi:
  -> korábbi vásárlásokat (adatbázisból),
  -> aktuális kosár tartalmát (session-ből).
- Ezek alapján számolja ki, hogy a felhasználó még hány kedvezményes terméket vásárolhat.

7. BIZTONSÁG
- Prepared statement-eket használ SQL injection ellen.
- htmlspecialchars() használata XSS támadások ellen.
- Jogosultság ellenőrzések (role alapú hozzáférés).

8. FELHASZNÁLÓI ÉLMÉNY
- Visszajelzések URL paraméterekkel (pl. sikeres kosárba helyezés, készlethiány).
- Dinamikus ármegjelenítés és kupon státusz visszajelzés.

Összességében ez a modul biztosítja a webáruház alapvető működését:
termékek böngészése, kosár kezelés és kedvezmények alkalmazása.

====================================================
*/

session_start();            // Munkamenet indítása a felhasználói adatok (pl. kosár, kupon) kezeléséhez
require_once "db.php";      // Adatbázis kapcsolat betöltése

$_SESSION['last_page'] = 'termekeink.php';  // Az utolsó meglátogatott oldal mentése (pl. visszairányításhoz)



// --- 1. KEDVEZMÉNY ÉS KOSÁR ADATOK ---

// Beállítások lekérése az adatbázisból (pl. hány termékre alkalmazható a kedvezmény)
$settings_res = $conn->query("SELECT max_discounted_items FROM SETTINGS LIMIT 1");
$settings = $settings_res->fetch_assoc();

// Maximálisan kedvezményezhető termékek száma (ha nincs adat, alapértelmezett: 1)
$max_allowed_discounted = $settings['max_discounted_items'] ?? 1;

// Alapértelmezett kedvezmény értékek inicializálása
$discount = 0;
$expiry_timestamp = 0;

// Ellenőrizzük, hogy a felhasználó be van-e jelentkezve
if (isset($_SESSION['user_id'])) {
    // Ha van érvényes kupon a session-ben és még nem járt le
    if (isset($_SESSION['coupon_expiry']) && $_SESSION['coupon_expiry'] > time()) {

        $discount = $_SESSION['coupon_discount'] ?? 0;          // Kedvezmény mértéke (%)
        $expiry_timestamp = $_SESSION['coupon_expiry'] * 1000;  // Lejárati idő (ms, JS kompatibilitás miatt)

    } else {
        // Ha a kupon lejárt vagy nem érvényes, töröljük a session-ből
        unset($_SESSION['coupon_discount']);
        unset($_SESSION['coupon_expiry']);
    }
}
