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



// ===============================
// BEÁLLÍTÁSOK LEKÉRÉSE (DINAMIKUS LIMIT)
// ===============================
/*
  Ebben a részben az adatbázisból lekérjük a rendszer beállításait.

  Cél:
  - admin által módosítható limitek kezelése
  - ne legyen hardcode-olva az érték a kódban
*/

// Lekérjük a SETTINGS táblából a maximálisan engedélyezett akciós termékek számát
$settings_res = $conn->query("SELECT max_discounted_items FROM SETTINGS LIMIT 1");

// Az eredmény sor lekérése asszociatív tömbként
$settings = $settings_res->fetch_assoc();

// Ha nincs beállítva érték, alapértelmezett 1-et használunk
$max_allowed_discounted = $settings['max_discounted_items'] ?? 1;



// ===============================
// FELHASZNÁLÓ SZEREPKÖR ELLENŐRZÉSE
// ===============================
/*
  Ellenőrizzük, hogy a bejelentkezett felhasználó admin jogosultsággal rendelkezik-e.

  Feltételezés:
  - session['role'] = 2 -> admin
  - minden más érték -> nem admin
*/

// Admin jogosultság vizsgálata
$isAdmin = isset($_SESSION['role']) && $_SESSION['role'] == '2';



/* ===============================
   TERMÉK ID ELLENŐRZÉS
=================================*/
/*
  Ez a rész biztosítja, hogy a felhasználó által átadott
  termék azonosító (ID) érvényes legyen.

  Ellenőrzések:
  1. Létezik-e az 'id' paraméter a URL-ben (GET kérésben)
  2. Szám-e az értéke (biztonsági ellenőrzés)
*/
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    die("Érvénytelen termék azonosító.");
}

/*
  Az ID típuskonvertálása egész számmá (int)
  Cél:
  - SQL injection elleni védelem egy alap szinten
  - biztos adattípus használata az adatbázis lekérdezéshez
*/
$product_id = (int)$_GET['id'];



/* ===============================
   VISSZA GOMB CÉLOLDAL KEZELÉSE
=================================*/
/*
  Ez a logika határozza meg, hogy a "Vissza" gomb melyik oldalra navigáljon.

  Prioritási sorrend:
  1. URL paraméter (from)
  2. Session-ben eltárolt utolsó oldal
  3. Alapértelmezett oldal (index.php)
*/

// 1. Elsődlegesen URL paraméter alapján döntünk
// Pl.: kosár.php?from=termekeink.php
if (isset($_GET['from']) && in_array($_GET['from'], ['index.php', 'termekeink.php'])) {
    $referrer = $_GET['from'];
} 

// 2. Ha nincs URL paraméter, Session alapján próbáljuk visszaállítani
// Ez akkor hasznos, ha a felhasználó navigáció közben járt az oldalon
elseif (isset($_SESSION['last_page']) && in_array($_SESSION['last_page'], ['index.php', 'termekeink.php'])) {
    $referrer = $_SESSION['last_page'];
} 

// 3. Ha sem URL paraméter, sem Session nincs → alapértelmezett oldal
// Biztonsági és fallback megoldás
else {
    $referrer = 'index.php';
}



/*========================================
  KOSÁRHOZ ADÁS (POST - SESSION ALAPÚ)
==========================================*/

/*
  Ez a blokk kezeli a termék kosárba helyezését.

  Fő funkciók:
  - Jogosultság ellenőrzés (csak vásárló)
  - Készlet ellenőrzés
  - Kosár kezelés SESSION-ben
  - Kupon / kedvezmény logika
  - Duplikált termékek kezelése
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_to_cart'])) {

    /*==============================
      JOGOSULTSÁG ELLENŐRZÉS
    ==============================*/
    /*
      Csak bejelentkezett vásárló adhat kosárhoz.
      role != 0 -> nincs jogosultság
    */
    if (!isset($_SESSION['user_id']) || $_SESSION['role'] != '0') {
        header("Location: termek.php?id=" . (int)$_POST['id'] . "&error=no_permission");
        exit;
    }

    /*==============================
      TERMÉK BEOLVASÁSA
    ==============================*/
    $p_id = (int)$_POST['id'];

    $stmt = $conn->prepare("SELECT name, price, stock FROM PRODUCTS WHERE id = ?");
    $stmt->bind_param("i", $p_id);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($product = $res->fetch_assoc()) {

        /*==============================
          KOSÁR INICIALIZÁLÁS
        ==============================*/
        if (!isset($_SESSION['cart'])) $_SESSION['cart'] = [];


        /*==============================
          KÉSZLET ELLENŐRZÉS
        ==============================*/
        /*
          Megnézzük, mennyi van már ebből a termékből a kosárban,
          hogy ne lehessen túllépni a készletet.
        */
        $total_in_cart = 0;
        foreach($_SESSION['cart'] as $item) {
            if ($item['product_id'] == $p_id) {
                $total_in_cart += $item['quantity'];
            }
        }

        if ($product['stock'] > $total_in_cart) {


            /*==============================
              KUPON / KEDVEZMÉNY LOGIKA
            ==============================*/
            /*
              - Max kedvezményes darabszám korlát
              - Ha elfogy, normál áron kerül a kosárba
            */
            if ($discount > 0) {

                $discount_key = $p_id . "_discounted";
                $discounted_qty = $_SESSION['cart'][$discount_key]['quantity'] ?? 0;

                if ($discounted_qty < $max_allowed_discounted) {

                    // Kedvezményes ár számítása
                    $price_after_discount = $product['price'] * (1 - ($discount / 100));

                    // Első darab létrehozása
                    if (!isset($_SESSION['cart'][$discount_key])) {
                        $_SESSION['cart'][$discount_key] = [
                            'product_id' => $p_id,
                            'name' => $product['name'] . " (Akciós)",
                            'price' => $price_after_discount,
                            'quantity' => 1
                        ];
                    } else {
                        $_SESSION['cart'][$discount_key]['quantity']++;
                    }

                } else {


                    /*==============================
                      NORMÁL ÁRAS HOZZÁADÁS
                      (ha elfogyott az akciós limit)
                    ==============================*/
                    if (!isset($_SESSION['cart'][$p_id])) {
                        $_SESSION['cart'][$p_id] = [
                            'product_id' => $p_id,
                            'name' => $product['name'],
                            'price' => $product['price'],
                            'quantity' => 1
                        ];
                    } else {
                        $_SESSION['cart'][$p_id]['quantity']++;
                    }
                }

            } else {

                /*==============================
                  KUPON NÉLKÜLI HOZZÁADÁS
                ==============================*/
                if (!isset($_SESSION['cart'][$p_id])) {
                    $_SESSION['cart'][$p_id] = [
                        'product_id' => $p_id,
                        'name' => $product['name'],
                        'price' => $product['price'],
                        'quantity' => 1
                    ];
                } else {
                    $_SESSION['cart'][$p_id]['quantity']++;
                }
            }

            /*==============================
              SIKERES HOZZÁADÁS
            ==============================*/
            header("Location: termek.php?id=$p_id&added=1");
            exit;

        } else {

            /*==============================
              NINCS ELÉG KÉSZLET
            ==============================*/
            header("Location: termek.php?id=$p_id&error=1");
            exit;
        }
    }
}



/* ===============================
   TERMÉK ADATAINAK BETÖLTÉSE
=================================*/
/*
  Ez a blokk egyetlen termék adatainak lekérdezésére szolgál
  az adatbázisból a termék ID alapján.

  Használat:
  - Prepared statement (SQL injekció ellen védett)
  - Több táblából származó adatok JOIN-nal
*/
if ($stmt = $conn->prepare("
    SELECT p.id, p.name, p.price, p.stock, p.category_id, p.image, c.name, p.description, s.name
    FROM PRODUCTS p
    JOIN CATEGORIES c ON p.category_id = c.id
    LEFT JOIN SUPPLIERS s ON p.supplier_id = s.id
    WHERE p.id = ?
")) {

    /*
      Paraméter kötése:
      - "i" = integer (termék ID)
      - $product_id = a lekérdezett termék azonosítója
    */
    $stmt->bind_param("i", $product_id);

    // Lekérdezés futtatása
    $stmt->execute();

    // Eredmény memóriába töltése
    $stmt->store_result();

    /*
      Ellenőrzés:
      - pontosan 1 találatnak kell lennie
      - ha nincs találat -> hibát dobunk
    */
    if ($stmt->num_rows !== 1) die("A termék nem található.");

    /*
      Eredmény változókhoz kötése:
      - az SQL SELECT sorrendje alapján történik
    */
    $stmt->bind_result($id, $name, $price, $stock, $category_id, $image, $category_name, $description, $supplier_name);
    
    // Adatok kiolvasása
    $stmt->fetch();

    // Statement lezárása (erőforrás felszabadítás)
    $stmt->close();

} else {
    /*
      Ha a prepare() sikertelen:
      - adatbázis hiba vagy rossz SQL
    */
    die("Adatbázis hiba.");
}



/*=========================================================
  KUPON / KEDVEZMÉNY KVÓTA KEZELÉS
=========================================================*/
/*
  Ez a logika kezeli a termékekhez kapcsolt kedvezményes vásárlási
  limitet (kvótát).

  Cél:
  - Egy felhasználó csak meghatározott mennyiségben vásárolhasson
    kedvezményes áron.
  - Figyelembe veszi:
      1. korábbi rendeléseket (adatbázisból)
      2. jelenlegi kosár tartalmát (session)
*/


/* Már kedvezményesen megvásárolt mennyiség */
$already_bought_discounted = 0;


/*
  Ellenőrzés csak akkor történik, ha:
  - van bejelentkezett felhasználó
  - van aktív kedvezmény
*/
if ($user_id > 0 && $discount > 0) {

    /*
      Lekérdezzük az eddig kedvezményesen vásárolt mennyiséget
      az adott termékre.
    */
    $check_stmt = $conn->prepare("
        SELECT SUM(oi.quantity) as total 
        FROM ORDER_ITEMS oi
        JOIN ORDERS o ON oi.order_id = o.id
        WHERE o.user_id = ? AND oi.product_id = ? AND oi.sale_price < ?
    ");
