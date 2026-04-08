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



//=============== KATEGÓRIÁK LEKÉRÉSE ===============//
// Az összes kategória lekérdezése az adatbázisból,
// amelyeket a navigációs menüben és a terméklista szűréséhez használunk
$categories = $conn->query("SELECT * FROM CATEGORIES");

//=============== FELHASZNÁLÓI SZEREPKÖR ===============//
// Ellenőrizzük, hogy a bejelentkezett felhasználó admin-e (role = 2)
$isAdmin = isset($_SESSION['role']) && $_SESSION['role'] == '2';

//=============== KOSÁR KEZELÉSE (POST KÉRÉS) ===============//
// Akkor fut le, ha a felhasználó terméket ad a kosárhoz
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_to_cart'])) {

    // Csak bejelentkezett vásárló (role = 0) tehet terméket a kosárba
    if (!isset($_SESSION['user_id']) || $_SESSION['role'] != '0') {
        header("Location: termekeink.php?error=no_permission");
        exit;
    }

    // Termék ID biztonságos egész számmá alakítása
    $p_id = (int)$_POST['id'];
    
    // Termék lekérdezése az adatbázisból (név, ár, készlet)
    $stmt = $conn->prepare("SELECT name, price, stock FROM PRODUCTS WHERE id = ?");
    $stmt->bind_param("i", $p_id);
    $stmt->execute();
    $res = $stmt->get_result();
    
    // Ha a termék létezik
    if ($product = $res->fetch_assoc()) {
        // Ha még nincs kosár session, inicializáljuk
        if (!isset($_SESSION['cart'])) $_SESSION['cart'] = [];
        
        //=============== KÉSZLET ELLENŐRZÉS ===============//
        // Megszámoljuk, hogy az adott termékből mennyi van már a kosárban
        $total_in_cart = 0;
        foreach($_SESSION['cart'] as $item) {
            if ($item['product_id'] == $p_id) {
                $total_in_cart += $item['quantity'];
            }
        }

        // Csak akkor engedjük hozzáadni, ha van még készlet
        if ($product['stock'] > $total_in_cart) {
            

            //=============== KUPON LOGIKA ===============//
            // Ha van aktív kedvezmény (pl. kupon)
            if ($discount > 0) {

                // Egyedi kulcs az akciós termékhez (külön tároljuk a kosárban)
                $discount_key = $p_id . "_discounted";

                // Jelenlegi akciós darabszám lekérdezése
                $discounted_qty = $_SESSION['cart'][$discount_key]['quantity'] ?? 0;


                // Ellenőrizzük, hogy belefér-e még a kedvezményes darabszám limitbe
                if ($discounted_qty < $max_allowed_discounted) {

                    // Kedvezményes ár kiszámítása (% alapján)
                    $price_after_discount = $product['price'] * (1 - ($discount / 100));
                    
                    // Ha még nincs ilyen akciós termék a kosárban -> hozzáadás
                    if (!isset($_SESSION['cart'][$discount_key])) {
                        $_SESSION['cart'][$discount_key] = [
                            'product_id' => $p_id,
                            'name' => $product['name'] . " (Akciós)",
                            'price' => $price_after_discount,
                            'quantity' => 1
                        ];
                    } else {
                        // Ha már van → darabszám növelése
                        $_SESSION['cart'][$discount_key]['quantity']++;
                    }
                } else {
                    //=============== NORMÁL ÁR (LIMIT ELÉRVE) ===============//
                    // Ha elfogyott az akciós keret, normál áron kerül a kosárba
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
                //=============== NINCS KEDVEZMÉNY ===============//
                // Alap működés: normál ár
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
            
            // Sikeres hozzáadás után visszairányítás
            header("Location: termekeink.php?added=1");
            exit;

        } else {
            //=============== NINCS KÉSZLET ===============//
            // Ha nincs elegendő készlet
            header("Location: termekeink.php?error=no_stock");
            exit;
        }
    }
}

// Kosár összesített darabszámának kiszámolása a fejlécben megjelenítéshez
// Végigiterál a session-ben tárolt kosár elemein, és összeadja a darabszámokat
$total_items = 0;

if (!empty($_SESSION['cart'])) {
    foreach ($_SESSION['cart'] as $item) {
        $total_items += $item['quantity'];  // Minden termék mennyiségének hozzáadása az összeghez
    }
}



// --- 3. RENDERELÉS ÉS FUNKCIÓK ---

/*
 Ez a függvény egy adott kategória termékeit jeleníti meg dinamikusan.
 Feladata:
 - Lekéri az adott kategóriához tartozó termékeket az adatbázisból
 - Figyelembe veszi a felhasználó kuponkedvezményét és annak korlátait
 - Ellenőrzi, hogy a felhasználó mennyi kedvezményes terméket vásárolt már
 - Megjeleníti az árakat (eredeti és kedvezményes)
 - Kezeli a készlet állapot vizuális jelzését
 - Lehetővé teszi a kosárba helyezést (csak vásárló szerepkör esetén)
*/
function renderCategory($conn, $cat_id, $cat_title, $cat_subtitle) {

    // Session adatok lekérése (aktuális felhasználó és kuponkedvezmény)
    $discount = $_SESSION['coupon_discount'] ?? 0;
    $user_id = $_SESSION['user_id'] ?? 0;

    // Beállítások lekérése: maximum hány termékre használható a kupon
    $set_res = $conn->query("SELECT max_discounted_items FROM SETTINGS LIMIT 1");
    $settings = $set_res->fetch_assoc();
    $max_limit = $settings['max_discounted_items'] ?? 1;

    // Termékek lekérdezése az adott kategóriából
    $stmt = $conn->prepare("SELECT id, name, price, image, category_id, stock FROM PRODUCTS WHERE category_id = ?");
    $stmt->bind_param("i", $cat_id);
    $stmt->execute();
    $result = $stmt->get_result();

    // Kategória szekció megnyitása
    echo '<section class="favorite section" id="category' . $cat_id . '">';
    echo '   <h2 class="section__title">' . htmlspecialchars($cat_title) . '</h2>';
    echo '   <div class="favorite__container container grid">';

    // Termékek bejárása
    while ($row = $result->fetch_assoc()) {
        $p_id = $row['id'];
        
        // Korábbi kedvezményes vásárlások összesítése (adatbázisból)
        $already_bought_discounted = 0;
        if ($user_id > 0 && $discount > 0) {
            $check_stmt = $conn->prepare("
                SELECT SUM(oi.quantity) as total 
                FROM ORDER_ITEMS oi
                JOIN ORDERS o ON oi.order_id = o.id
                WHERE o.user_id = ? AND oi.product_id = ? AND oi.sale_price < ?
            ");
            $check_stmt->bind_param("iid", $user_id, $p_id, $row['price']);
            $check_stmt->execute();
            $res_bought = $check_stmt->get_result()->fetch_assoc();
            $already_bought_discounted = $res_bought['total'] ?? 0;
            $check_stmt->close();
        }

        // Kosárban lévő kedvezményes mennyiség lekérése (sessionből)
        $discount_key = $p_id . "_discounted";
        $already_in_cart_discounted = $_SESSION['cart'][$discount_key]['quantity'] ?? 0;
        
        // Összes eddig felhasznált kedvezmény
        $total_used_quota = $already_bought_discounted + $already_in_cart_discounted;
        
        // Eldöntjük, hogy megjeleníthető-e még a kedvezmény
        $show_discount = ($discount > 0 && $total_used_quota < $max_limit);
        
        // Készlet állapot alapján CSS osztály hozzárendelése
        if ($row['stock'] > 15) { $stockClass = "stock-high"; } 
        elseif ($row['stock'] > 0) { $stockClass = "stock-medium"; } 
        else { $stockClass = "stock-zero"; }

        // Kép elérési út összeállítása (fallback ha nincs kép)
        $imgPath = "assets/img/category_{$row['category_id']}/" . $row['image'];
        if (!file_exists($imgPath) || empty($row['image'])) { $imgPath = "assets/img/no-image.png"; }

        // Ár számítás (eredeti és kedvezményes)
        $original_price = $row['price'];
        $price_after_discount = $original_price * (1 - $discount / 100);
        ?>


        <!-- Egy termék kártya -->
        <article class="favorite__card <?= $stockClass ?>">

            <!-- Termék link és kép -->
            <a href="termek.php?id=<?= $p_id ?>&from=termekeink.php">
               <img src="<?= $imgPath ?>" class="favorite__img" alt="<?= htmlspecialchars($row['name']) ?>">
            </a>
            
            <!-- Termék adatok -->
            <div class="favorite__data">
                <h2 class="favorite__title"><?= htmlspecialchars($row['name']) ?></h2>
                <span class="favorite__subtitle"><?= htmlspecialchars($cat_subtitle) ?></span>
                
                <!-- Ár megjelenítés -->
                <h3 class="favorite__price">
                    <?php if ($show_discount): ?>

                        <!-- Eredeti ár áthúzva -->
                        <span style="text-decoration: line-through; color: #aaa; font-size: 0.8rem;">
                            <?= number_format($original_price, 0, '', ' ') ?> Ft
                        </span>

                        <!-- Kedvezményes ár -->
                        <span style="color: #ffbc3f;"> 
                            <?= number_format($price_after_discount, 0, '', ' ') ?> Ft/kg
                        </span>
                        <br>

                        <!-- Maradék kupon -->
                        <small style="font-size: 1.2rem; font-weight: bold; color: #f7ff8c;">
                            Maradt: <?= $max_limit - $total_used_quota ?> db
                        </small>


                    <?php else: ?>

                        <!-- Normál ár -->
                        <?= number_format($original_price, 0, '', ' ') ?> Ft/kg

                        <!-- Ha elfogyott a kupon -->
                        <?php if($discount > 0 && $total_used_quota >= $max_limit): ?>
                            <br><small style="font-size: 1.2rem; font-weight: bold; color: red;">Nincs több kuponod!</small>
                        <?php endif; ?>
                    <?php endif; ?>
                </h3>
            </div>

            <!-- Kosárba gomb (csak vásárlóknak) -->
            <?php if(isset($_SESSION['role']) && $_SESSION['role'] == '0'): ?>
               <form method="POST">
                  <input type="hidden" name="id" value="<?= $p_id ?>">
                  <button type="submit" name="add_to_cart" class="favorite__button button">
                     <i class="ri-add-line"></i>
                  </button>
               </form>
            <?php endif; ?>
        </article>
        <?php
    }

    // Szekció lezárása
    echo '   </div>';
    echo '</section>';
}
?>

<!DOCTYPE html>
<html lang="hu">
<head>
   <meta charset="UTF-8">                                                   <!-- Karakterkódolás beállítása (UTF-8, magyar ékezetek támogatása) -->
   <meta name="viewport" content="width=device-width, initial-scale=1.0">   <!-- Reszponzív megjelenítés mobil eszközökhöz -->

   <!-- Weboldal ikon (favicon) -->
   <link rel="shortcut icon" href="assets/img/logo/MELICO LOGO 2.png" type="image/x-icon">
   
    <!-- Ikonok (Remixicon könyvtár betöltése) -->
   <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/remixicon/3.7.0/remixicon.css">
   
    <!-- Fő stíluslap -->
   <link rel="stylesheet" href="assets/css/styles.css">

   <!-- Oldal címe a böngésző fülön -->
   <title>MELICO – Termékeink</title>

   <style>
    /*=============== KUPON ÉRTESÍTŐ SÁV ===============*/
    /* Felső figyelmeztető sáv, amely aktív kupon esetén jelenik meg */
       .coupon-alert {
           background: linear-gradient(90deg, #ffbc3f, #ff9f00);    /* Narancs átmenetes háttér */
           color: #1a150e;                    /* Sötét szöveg a kontraszt miatt */
           padding: 1rem;                       /* Belső térköz */
           text-align: center;                  /* Szöveg középre igazítása */
           font-weight: bold;                   /* Félkövér szöveg */
           border-bottom: 2px solid #e68a00;    /* Alsó szegély kiemeléshez */
           display: none;                       /* Alapból rejtett, JS jeleníti meg ha van aktív kupon */
       }

       /*=============== VISSZASZÁMLÁLÓ IDŐZÍTŐ ===============*/
       #timer {
         color: #000000;    /* Fekete szöveg */
         font-weight: bold;     /* Félkövér kiemelés */
         margin-left: 5px;      /* Kis térköz bal oldalon */
      }

      /*=============== KUPON SZÖVEG RÉSZEK ===============*/
      .coupon-main {
         margin-right: 10px;    /* Jobb oldali térköz */
      }

      .coupon-divider {
         margin: 0 10px;    /* Két oldalra térköz */
         opacity: 0.6;      /* Halvány elválasztó */
      }

      /*=============== KUPON LEJÁRATI IDŐ KIEMELÉS ===============*/
      .coupon-expiry {
         background: rgb(255, 0, 81);   /* Piros háttér (figyelemfelkeltés) */
         padding: 3px 8px;                  /* Belső térköz */
         border-radius: 6px;                /* Lekerekített sarkok */
      }

      /*=============== VISSZASZÁMLÁLÓ IDŐZÍTŐ ===============*/
      #timer {
         font-family: monospace;    /* Fix szélességű betűtípus (digitális hatás) */
         font-size: 1.2rem;         /* Nagyobb méret a jobb láthatóságért */
         margin-left: 5px;          /* Kis térköz bal oldalon */
      }
    </style>
</head>
<body>

<header class="header" id="header">
   <nav class="nav container">
      <a href="index.php" class="nav__logo">
         <img src="assets/img/logo/MELICO LOGO.png" alt="MELICO Logo" />
      </a>

      <!-- NAVIGÁCIÓS MENÜ -->
      <div class="nav__menu" id="nav-menu">
         <ul class="nav__list">
            <!-- Alap menüpontok -->
            <li class="nav__item"><a href="index.php" class="nav__link">Főoldal</a></li>
            <li class="nav__item"><a href="termekeink.php" class="nav__link active-link">Termékeink</a></li>
            <li class="nav__item"><a href="rolunk.php" class="nav__link">Rólunk</a></li>
            <li class="nav__item"><a href="kapcsolatfelvetel.php" class="nav__link">Kapcsolatfelvétel</a></li>

            <!-- ADMIN MENÜ: csak admin felhasználóknak jelenik meg -->
            <?php if($isAdmin): ?>
            <li class="nav__item">
               <a href="admin.php" class="nav__link <?php echo basename($_SERVER['PHP_SELF'])=='admin.php' ? 'active-link' : ''; ?>">Admin</a>
            </li>
            <?php endif; ?>

            <!-- BEJELENTKEZÉS / PROFIL -->
            <li class="nav__item">
               <?php if (isset($_SESSION['user_id'])): ?>
                  <a href="profil.php" class="nav__link nav__profile">
                        <i class="ri-user-line"></i>
                  </a>
               <?php else: ?>
                <!-- Ha nincs: bejelentkezés gomb -->
                  <a href="signIn.php" class="nav__signin button">Bejelentkezés</a>
               <?php endif; ?>
            </li>

            <!-- KUPON ÉS KOSÁR: nem admin felhasználóknak -->
            <?php if (!$isAdmin): ?>
                <!-- Kupon oldal ikon -->
               <li class="nav__item">
                  <a href="kupon.php" class="nav__link <?php echo basename($_SERVER['PHP_SELF'])=='kupon.php' ? 'active-link' : ''; ?>">
                     <i class="ri-coupon-2-line"></i>
                  </a>
               </li>

               <!-- Kosár ikon és darabszám kijelzés -->
               <li class="nav__item">
                   <a href="kosar.php" class="nav__link"><i class="ri-shopping-cart-fill"></i>
                   <?php 
                   $total_items = 0;
                   if (!empty($_SESSION['cart'])) {
                       foreach ($_SESSION['cart'] as $item) {
                           $total_items += $item['quantity'];
                       }
                       /* Csak akkor jelenik meg a darabszám, ha van termék a kosárban */
                       if ($total_items > 0) echo "($total_items)";
                   }
                   ?>
                   </a>
               </li>
            <?php endif; ?>
         </ul>

         <!-- Mobil menü bezáró ikon -->
         <div class="nav__close" id="nav-close"><i class="ri-close-line"></i></div>

         <!-- Dekoratív képek a menüben -->
         <img src="assets/img/cheese2.png" alt="image" class="nav__img-1">
         <img src="assets/img/cheese1.png" alt="image" class="nav__img-2">
      </div>

      <!-- Mobil menü megnyitó ikon -->
      <div class="nav__toggle" id="nav-toggle"><i class="ri-menu-fill"></i></div>
   </nav>
</header>
