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

    /*
      Paraméterek:
      - user_id -> melyik felhasználó
      - id -> termék azonosító
      - price -> összehasonlítás a kedvezményes árhoz
    */
    $check_stmt->bind_param("iid", $user_id, $id, $price);
    $check_stmt->execute();

    $res_bought = $check_stmt->get_result()->fetch_assoc();

    /* Ha nincs találat, akkor 0 */
    $already_bought_discounted = $res_bought['total'] ?? 0;
    $check_stmt->close();
}

/* Kosárban már lévő kedvezményes mennyiség */
$discount_key = $id . "_discounted";
$already_in_cart_discounted = $_SESSION['cart'][$discount_key]['quantity'] ?? 0;

/*
  Összes felhasznált kvóta:
  = már megvett + kosárban lévő
*/
$total_used_quota = $already_bought_discounted + $already_in_cart_discounted;

/*
  Kedvezmény megjelenítése csak akkor:
  - van kedvezmény
  - nem lépte túl a limitet
  - nem admin felhasználó
*/
$show_discount = ($discount > 0 && $total_used_quota < $max_allowed_discounted && !$isAdmin);

/* Végső kedvezményes ár számítása */
$price_after_discount = $price * (1 - $discount / 100);



/* ===============================
   TERMÉK ALAPÉRTELMEZETT LEÍRÁS
=================================*/

//category_1

// Ha a termék neve "Camembert de Normandie AOP" és nincs admin által megadott leírás
if ($name === "Camembert de Normandie AOP" && empty($description)) {
    $description = "Ez a híres francia Camembert sajt lágy, krémes állagú, karakteres ízzel rendelkezik. 
Tökéletes kiegészítője egy sajttálhoz, vagy egyszerűen friss kenyérrel fogyasztva. 
Frissességét és minőségét a tradicionális érlelési módszer biztosítja.";
}

// Chevre Frais alapértelmezett leírása
if ($name === "Chevre Frais" && empty($description)) {
    $description = "A Chevre Frais egy friss, lágy kecskesajt, amely krémes állagú és enyhén savanykás ízű. 
                    Kiválóan alkalmas szendvicsekhez, salátákhoz vagy önmagában fogyasztva. 
                    Minőségi alapanyagokból készül, garantálva a frissességet és a tradicionális ízt.";
}

// Gorgonzola Dolce DOP alapértelmezett leírása
if ($name === "Gorgonzola Dolce DOP" && empty($description)) {
    $description = "A Gorgonzola Dolce DOP egy lágy, krémes kékpenészes sajt enyhén édeskés ízzel. 
                    Kiválóan alkalmas salátákhoz, tésztákhoz, vagy önmagában fogyasztva. 
                    Olasz minőségi alapanyagokból készül, garantálva az autentikus ízt és a frissességet.";
}

// Ricotta alapértelmezett leírása
if ($name === "Ricotta" && empty($description)) {
    $description = "A Ricotta egy lágy, friss sajt, amely könnyű és krémes állagú. 
                    Tökéletesen illik desszertekhez, töltelékekhez, valamint salátákhoz és szendvicsekhez is. 
                    Kiváló minőségű tejből készül, garantálva a frissességet és az enyhén édeskés ízt.";
}

// Mozzarella di Bufala Campana DOP alapértelmezett leírása
if ($name === "Mozzarella di Bufala Campana DOP" && empty($description)) {
    $description = "A Mozzarella di Bufala Campana DOP egy prémium friss sajt, melyet hagyományos módszerekkel készítenek olasz bivalytej felhasználásával.  
                    Lágy, krémes textúrájú és enyhén édeskés ízű. Ideális salátákhoz, pizzához vagy önmagában, friss kenyérrel fogyasztva.";
}

// Mascarpone alapértelmezett leírása
if ($name === "Mascarpone" && empty($description)) {
    $description = "A Mascarpone egy lágy, krémes olasz sajt, amely gazdag és enyhén édeskés ízű.  
                    Különösen alkalmas desszertekhez, például tiramisuhoz, de friss kenyérrel vagy süteményekhez is kiváló.";
}

// Brie de Meaux AOP alapértelmezett leírása
if ($name === "Brie de Meaux AOP" && empty($description)) {
    $description = "A Brie de Meaux AOP egy klasszikus francia lágy sajt, krémes textúrával és jellegzetes, enyhén diós ízzel.  
                    Kiválóan fogyasztható önmagában, kenyérrel, vagy sajttál részeként. Az AOP minősítés garantálja a hagyományos előállítást és a magas minőséget.";
}

// Burrata alapértelmezett leírása
if ($name === "Burrata" && empty($description)) {
    $description = "A Burrata egy különleges olasz friss sajt, krémes belsővel és lágy külső réteggel.  
                    Tökéletes választás salátákhoz, tálakhoz vagy egyszerűen friss kenyérrel.  
                    Kiemelkedő minőségét gondos kézműves előállítás biztosítja.";
}

//--------------------------------------------------------------------------

//category_2

// Trappista alapértelmezett leírása
if ($name === "Trappista" && empty($description)) {
    $description = "A Trappista egy klasszikus magyar félkemény sajt, enyhén karakteres ízzel és kellemes állaggal.  
                    Kiváló szendvicsekhez, főzéshez vagy egyszerűen önmagában is fogyasztható.  
                    Hagyományos érlelési módszerekkel készül, mindig friss és minőségi.";
}

// Gouda Holland alapértelmezett leírása
if ($name === "Gouda Holland" && empty($description)) {
    $description = "A holland Gouda sajt enyhén édes, lágyan olvadó textúrával rendelkezik.  
                    Tökéletes szendvicsekhez, sajttálakhoz, vagy reszelve főzéshez.  
                    A hagyományos holland módszer biztosítja a gazdag és karakteres ízt.";
}

// Edami alapértelmezett leírása
if ($name === "Edami" && empty($description)) {
    $description = "Az Edami sajt enyhén diós ízű, félkemény állagú és jól szeletelhető.  
                    Kiváló választás szendvicsekhez, sajttálakhoz vagy főzéshez.  
                    Hagymás, olajos és enyhén fűszeres ételekhez is tökéletesen illik.";
}

// Maasdam alapértelmezett leírása
if ($name === "Maasdam" && empty($description)) {
    $description = "A Maasdam sajtra jellemző a lágy, kissé édeskés íz és a jellegzetes lyukacsos szerkezet.  
                    Ideális szendvicsekhez, sajttálakhoz, valamint olvasztva ételekhez.  
                    A holland tradíció és a minőségi tej biztosítja a sajt jellegzetes karakterét.";
}

//--------------------------------------------------------------------------

//category_3

// Parmigiano Reggiano DOP alapértelmezett leírása
if ($name === "Parmigiano Reggiano DOP" && empty($description)) {
    $description = "Az igazi olasz Parmigiano Reggiano DOP sajt karakteres, gazdag ízvilágú, 
                    kemény textúrájú sajt, melyet tradicionális érlelési módszerrel készítenek. 
                    Ideális reszelve tésztákra, salátákra, vagy önmagában fogyasztva.";
}

// Grana Padano DOP alapértelmezett leírása
if ($name === "Grana Padano DOP" && empty($description)) {
    $description = "A Grana Padano DOP egy tradicionális olasz kemény sajt, gazdag, enyhén diós ízzel. 
                    Érlelési ideje minimum 9 hónap, így tökéletes reszelve tésztákhoz, salátákhoz, vagy önállóan fogyasztva.";
}

// Pecorino Romano DOP alapértelmezett leírása
if ($name === "Pecorino Romano DOP" && empty($description)) {
    $description = "A Pecorino Romano DOP egy karakteres, juhtejből készült olasz kemény sajt.
                    Intenzíven sós és fűszeres ízvilága miatt kiváló tésztákhoz, reszelve
                    vagy szeletelve is remek választás.";
}

// Comté AOP alapértelmezett leírása
if ($name === "Comté AOP" && empty($description)) {
    $description = "A Comté AOP egy tradicionális francia félkemény sajt, amelyet a Jura-hegység
                    régiójában készítenek. Íze gazdag és komplex, enyhén diós és gyümölcsös
                    jegyekkel. Kiválóan olvad, ezért főzéshez és sajttálakhoz is ideális választás.";
}



// Beszállító külön változóba (opcionális)
$supplier_text = !empty($supplier_name) ? "Szállító: " . htmlspecialchars($supplier_name) : "";





/* ===============================
   KOSÁR DARABSZÁM SZÁMÍTÁSA
==================================*/
/*
  Ez a kód a kosárban lévő termékek összes darabszámát számolja ki.

  - A kosár adatai a $_SESSION['cart'] tömbben vannak tárolva
  - Minden elem tartalmaz egy 'quantity' (mennyiség) mezőt
  - A ciklus összeadja az összes mennyiséget

  Eredmény:
  -> $total_items változóban kerül eltárolásra a teljes darabszám
*/

$total_items = 0;

/* Ellenőrizzük, hogy a kosár nem üres */
if (!empty($_SESSION['cart'])) {

    /* Végigmegyünk minden kosár elemen */
    foreach ($_SESSION['cart'] as $item) {

        /* Hozzáadjuk az aktuális termék mennyiségét */
        $total_items += $item['quantity'];
    }
}



/* ===============================
   KÉP ÚTVONAL KEZELÉSE
=================================*/
/*
  Ez a rész a termékekhez tartozó képek elérési útját állítja be.

  Működés:
  - A kategória ID alapján dinamikusan kiválasztja a megfelelő mappát
  - Összeállítja a teljes képfájlt (útvonal + fájlnév)
  - Ellenőrzi, hogy a kép létezik-e a szerveren
  - Ha nem létezik, egy alapértelmezett (fallback) képet használ

  Cél:
  -> Ne törjön el az oldal, ha egy termékkép hiányzik
  -> Mindig legyen megjeleníthető kép a UI-ban
*/

$imagePath = "assets/img/category_{$category_id}/" . $image;

if (!file_exists($imagePath)) {
    $imagePath = "assets/img/barna.jpg";    // alapértelmezett kép hiányzó esetekre
}
?>
<!DOCTYPE html>
<html lang="hu">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <!-- Oldal címe dinamikusan PHP változóból -->
    <title><?= htmlspecialchars($name) ?> – MELICO</title>

    <!-- Külső CSS fájlok betöltése -->
    <link rel="stylesheet" href="assets/css/styles.css">
    <link rel="stylesheet" href="assets/css/termekek.css">

    <!-- Ikonok (Remix Icon könyvtár) -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/remixicon/3.7.0/remixicon.css">

    <style>
        /*=============== KOSÁR IKON (FIX UI ELEMEK) ===============*/
        /*
        A kosár ikon mindig látható (fixed position),
        jobb felső sarokban jelenik meg.

        Funkció:
        - gyors kosár elérés
        - darabszám jelzése badge-ben
        */
        .cart-icon {
            position: fixed;
            top: 20px;
            right: 20px;
            font-size: 24px;
            color: #fff;
            background-color: #F4A261;
            border-radius: 50%;
            padding: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: transform 0.2s;
            text-decoration: none;
            z-index: 9999;
        }

        /* Hover animáció */
        .cart-icon:hover {
            transform: scale(1.1);
        }

        /* Kosár darabszám jelző (badge) */
        .cart-badge {
            position: absolute;
            top: -5px;
            right: -5px;
            background:red;
            color:white;
            font-size:12px;
            padding:2px 6px;
            border-radius:50%;
        }

        /*=============== MOBIL OPTIMALIZÁLÁS ===============*/
        /*
        Reszponzív kialakítás 768px alatt (tablet/mobil).

        Cél:
        - termék oldal függőleges elrendezés
        - olvashatóság javítása
        - fix elemek ne takarjanak tartalmat
        */
        @media (max-width: 768px) {
            
            /*=============== TERMÉK KONTAINER ===============*/
            .product__container {
                display: flex;
                flex-direction: column; /* kép felül, szöveg alatta */
                gap: 20px;
                padding: 15px;
            }

            /*=============== TERMÉK KÉP ===============*/
            .product__image {
                width: 100%;
                display: inline-block; /* a keret igazodik a képhez */
            }

            .product__image img {
                width: 100%;
                height: auto;
                display: block;
                object-fit: contain;
            }

            /*=============== TERMÉK ADATOK ===============*/
            .product__data {
                width: 100%;
            }

            .product__data h2 {
                font-size: 1.5rem;
                margin-bottom: 5px;
            }

            .product__data span {
                display: block;
                margin-bottom: 10px;
            }

            /* Leírás mindig látható mobilon */
            .product__data p {
                display: block !important;
                visibility: visible !important;
                margin: 10px 0;
            }

            /* Ár kiemelése */
            .favorite__price {
                font-size: 1.3rem;
                margin: 8px 0;
            }

            /* Gomb teljes szélesség mobilon */
            .favorite__button {
                width: 100%;
                padding: 12px;
                font-size: 1rem;
                margin-top: 10px;
            }

            .product__availability {
                margin: 5px 0 15px 0;
            }


            /*=============== FIX ELEMEK MOBILON ===============*/

            /* Kosár ikon igazítása kisebb képernyőn */
            .cart-icon {
                top: 15px;
                right: 15px;
            }

            /* Badge méret optimalizálása */
            .cart-badge {
                font-size: 11px;
                padding: 2px 5px;
            }

            /* Vissza gomb pozíciója a kosár mellett */
            .back-home {
                display: inline-block;
                position: fixed;
                top: 20px;
                right: 80px; /* kosár mellett */
                z-index: 9999;
            }

            /* Tartalom ne legyen kitakarva fix elemek által */
            body {
                padding-top: 80px;
            }
        }
    </style>
</head>

<body>

    <!--=============== NAVIGÁCIÓ / VISSZA GOMB ===============-->
    <!--
    Dinamikus visszanavigálás az előző oldalra.
    A htmlspecialchars() védi az URL-t XSS támadás ellen.
    -->
    <!-- VISSZA GOMB -->
    <a href="<?= htmlspecialchars($referrer) ?>" class="back-home">
        Vissza
    </a>

    <!--=============== KOSÁR IKON (CSAK NEM ADMIN FELHASZNÁLÓKNAK) ===============-->
    <!--
    A kosár ikon csak akkor jelenik meg, ha a felhasználó NEM admin.
    A badge mutatja a kosárban lévő termékek számát.
    -->
    <?php if (!$isAdmin): ?>
        <a href="kosar.php" class="cart-icon">
            <i class="ri-shopping-cart-fill"></i>
            <?php if ($total_items > 0): ?>
                <span class="cart-badge"><?= $total_items ?></span>
            <?php endif; ?>
        </a>
    <?php endif; ?>
