<?php
// Munkamenet indítása a felhasználói adatok és kosár kezeléséhez
session_start();

// Adatbázis kapcsolat betöltése
include "db.php";

// --- ÉRTESÍTÉSEK SZÁMÁNA LEKÉRÉSE ---
$unread_notifications = 0;

// Ha a felhasználó be van jelentkezve, lekérjük az olvasatlan értesítések számát
if (isset($_SESSION['user_id'])) {
    $u_id = $_SESSION['user_id'];

    // SQL lekérdezés az olvasatlan értesítések számolására
    $notif_count_stmt = $conn->prepare("SELECT COUNT(*) as total FROM NOTIFICATIONS WHERE user_id = ? AND is_read = 0");
    $notif_count_stmt->bind_param("i", $u_id);
    $notif_count_stmt->execute();

    // Eredmény feldolgozása
    $notif_res = $notif_count_stmt->get_result()->fetch_assoc();
    $unread_notifications = $notif_res['total'] ?? 0;

    // Statement lezárása
    $notif_count_stmt->close();
}

// Alapbeállítások lekérdezése az adatbázisból
$settings = $conn->query("SELECT * FROM SETTINGS LIMIT 1")->fetch_assoc();

// Kuponnal érintett maximális termékek száma
$max_discounted_items = $settings['max_discounted_items'] ?? 4;

// Kupon felhasználási limit
$max_usage_limit = $settings['max_usage_limit'] ?? 1;

// Az utolsó meglátogatott oldal mentése session-be
$_SESSION['last_page'] = 'index.php';




// --- 1. KUPON ÉS LEJÁRAT KEZELÉSE ---
$discount = 0;
$expiry_timestamp = 0;

// Ha a felhasználó be van jelentkezve, ellenőrizzük a kupont
if (isset($_SESSION['user_id'])) {

    // Ha létezik lejárati idő és még nem járt le
    if (isset($_SESSION['coupon_expiry']) && $_SESSION['coupon_expiry'] > time()) {
        $discount = $_SESSION['coupon_discount'] ?? 0;

        // JavaScript számára milliszekundumban tároljuk
        $expiry_timestamp = $_SESSION['coupon_expiry'] * 1000;
    } else {
        // Ha lejárt a kupon, töröljük a session változókat
        unset($_SESSION['coupon_discount']);
        unset($_SESSION['coupon_expiry']);
    }
}

// Ellenőrizzük, hogy a felhasználó admin jogosultságú-e
$isAdmin = isset($_SESSION['role']) && $_SESSION['role'] == '2';




// --- AI ÖSSZEFOGLALÓ GENERÁLÁSA ---
function generateAISummary($name, $descFromDb) {

   // Alap szöveg az adatbázisból
    $baseText = $descFromDb;

    // Ha nincs leírás, alapértelmezett szövegek használata
    if (empty($baseText)) {
        $descriptions = [
         // Előre definiált termékleírások
            "Camembert de Normandie AOP" => "Ez a híres francia Camembert sajt lágy, krémes állagú, karakteres ízzel rendelkezik. Tökéletes kiegészítője egy sajttálhoz.",
            "Chevre Frais" => "A Chevre Frais egy friss, lágy kecskesajt, amely krémes állagú és enyhén savanykás ízű. Kiválóan alkalmas salátákhoz.",
            "Gorgonzola Dolce DOP" => "A Gorgonzola Dolce DOP egy lágy, krémes kékpenészes sajt enyhén édeskés ízzel. Autentikus olasz minőség.",
            "Ricotta" => "A Ricotta egy lágy, friss sajt, amely könnyű és krémes állagú. Tökéletesen illik desszertekhez és töltelékekhez.",
            "Mozzarella di Bufala Campana DOP" => "Prémium friss sajt bivalytejből. Lágy, krémes textúra és enyhén édeskés íz jellemzi.",
            "Mascarpone" => "Lágy, krémes olasz sajt, amely gazdag és enyhén édeskés ízű. Különösen alkalmas desszertekhez.",
            "Brie de Meaux AOP" => "Klasszikus francia lágy sajt, krémes textúrával és diós ízzel. Az AOP minősítés garantálja a hagyományos minőséget.",
            "Burrata" => "Különleges olasz friss sajt, krémes belsővel és lágy külsővel. Kiemelkedő minőségű kézműves termék.",
            "Trappista" => "Klasszikus magyar félkemény sajt, enyhén karakteres ízzel és kellemes állaggal. Hagyományos érleléssel készül.",
            "Gouda Holland" => "Enyhén édes, lágyan olvadó textúrájú sajt. A hagyományos holland módszer biztosítja a gazdag ízt.",
            "Edami" => "Enyhén diós ízű, félkemény állagú és jól szeletelhető sajt. Kiváló választás szendvicsekhez.",
            "Maasdam" => "Lágy, kissé édeskés íz és jellegzetes lyukacsos szerkezet jellemzi. Holland tradíció alapján készül.",
            "Parmigiano Reggiano DOP" => "Karakteres, gazdag ízvilágú olasz kemény sajt. Ideális reszelve tésztákra vagy önmagában.",
            "Grana Padano DOP" => "Tradicionális olasz kemény sajt, gazdag, enyhén diós ízzel. Érlelési ideje minimum 9 hónap.",
            "Pecorino Romano DOP" => "Karakteres, juhtejből készült olasz kemény sajt. Intenzíven sós és fűszeres ízvilág.",
            "Comté AOP" => "Tradicionális francia félkemény sajt a Jura-hegységből. Íze gazdag, enyhén diós és gyümölcsös."
        ];
        // Ha nincs konkrét leírás, általános szöveg
        $baseText = $descriptions[$name] ?? "Kézműves manufaktúránk különleges terméke, közvetlenül a gazdaságból.";
    }

    // Mondatokra bontás
    $sentences = explode('.', $baseText);

    // Első mondat kiválasztása
    $summary = trim($sentences[0]); 

    // Ha túl rövid, hozzáadjuk a következő mondatot is
    if (strlen($summary) < 40 && isset($sentences[1]) && !empty(trim($sentences[1]))) {
        $summary .= ". " . trim($sentences[1]);
    }

    return $summary . ".";
}




// --- KOSÁR KEZELÉS ---

// Kosár inicializálása, ha még nem létezik
if (!isset($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

// Termék hozzáadása kosárhoz
if (isset($_POST['add_to_cart'])) {

// Csak vásárló szerepkör adhat hozzá terméket
    if (!isset($_SESSION['user_id']) || $_SESSION['role'] != '0') {
        $_SESSION['error'] = 'Csak vásárlók tehetnek terméket a kosárba!';
        header("Location: index.php");
        exit();
    }

    // Termék ID lekérése
    $p_id = (int)$_POST['id'];

    // Termék adatainak lekérdezése
    $stmt = $conn->prepare("SELECT name, price, stock FROM PRODUCTS WHERE id = ?");
    $stmt->bind_param("i", $p_id);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($product = $res->fetch_assoc()) {

      // Kosár inicializálása, ha szükséges
        if (!isset($_SESSION['cart'])) $_SESSION['cart'] = [];

        // Összes darabszám kiszámítása a kosárban
        $total_in_cart = 0;
        foreach($_SESSION['cart'] as $item) {
            if ($item['product_id'] == $p_id) {
                $total_in_cart += $item['quantity'];
            }
        }

        // Készlet ellenőrzése
        if ($product['stock'] > $total_in_cart) {

            // --- KUPON LOGIKA ---
            if ($discount > 0) {

               // Egyedi kulcs az akciós termékhez
                $discount_key = $p_id . "_discounted";

                // Kosárban lévő akciós mennyiség
                $discounted_in_cart = $_SESSION['cart'][$discount_key]['quantity'] ?? 0;

                // Korábban vásárolt akciós mennyiség lekérdezése
                $already_bought_discounted = 0;

                $check_stmt = $conn->prepare("
                    SELECT SUM(oi.quantity) as total 
                    FROM ORDER_ITEMS oi
                    JOIN ORDERS o ON oi.order_id = o.id
                    WHERE o.user_id = ? 
                    AND oi.product_id = ? 
                    AND oi.sale_price < ?
                ");
                $check_stmt->bind_param("iid", $_SESSION['user_id'], $p_id, $product['price']);
                $check_stmt->execute();

                $res_bought = $check_stmt->get_result()->fetch_assoc();
                $already_bought_discounted = $res_bought['total'] ?? 0;
                $check_stmt->close();

                // Teljes felhasznált kupon mennyiség
                $total_used_quota = $discounted_in_cart + $already_bought_discounted;

                // Ha még van kupon felhasználási lehetőség
                if ($total_used_quota < $max_discounted_items) {

                     // Kedvezményes ár kiszámítása
                    $price_after_discount = $product['price'] * (1 - ($discount / 100));

                    // Termék hozzáadása akciós áron
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

                    // Ha elfogyott a kupon, normál ár alkalmazása
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

                // Ha nincs kupon, normál ár
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
            // Hiba, ha nincs elég készlet
            $_SESSION['error'] = 'Nincs több készleten ebből a termékből!';
        }
    }

    // Visszairányítás a főoldalra
    header("Location: index.php?added=1");
    exit();
}
?>
<!--=============== HTML ALAPSTRUKTÚRA (HEAD RÉSZ) ===============-->
<!--
    Ez a dokumentum a MELICO weboldal alap HTML szerkezetét tartalmazza.
    A <head> szekcióban kerülnek meghatározásra a meta adatok (karakterkódolás, reszponzivitás),
    a külső erőforrások (ikonok, betűtípusok, stíluslapok), valamint az oldal címe.

    Tartalmaz továbbá egy egyedi stílusblokkot is, amely a kupon értesítési sáv (coupon-alert)
    és a hozzá tartozó visszaszámláló megjelenítéséért felel.

    A kupon sáv alapértelmezetten rejtett (display: none), és JavaScript segítségével jelenik meg,
    amikor a felhasználónak aktív kedvezménye van.
-->
<!DOCTYPE html>
<html lang="hu">
<head>
   <meta charset="UTF-8">
   <meta name="viewport" content="width=device-width, initial-scale=1.0">

   <!--=============== FAVICON ===============-->
   <link rel="shortcut icon" href="assets/img/logo/MELICO LOGO 2.png" type="image/x-icon">

   <!--=============== REMIXICONS ===============-->
   <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/remixicon/3.7.0/remixicon.css">

   <!--=============== CSS ===============-->
   <link rel="stylesheet" href="assets/css/styles.css">

   <title>MELICO - Kézműves Sajtmanufaktúra</title>

   <style>
       /*=============== KUPON ÉRTESÍTŐ SÁV ===============*/
       /* Felső figyelmeztető sáv aktív kupon esetén (JS jeleníti meg) */
       .coupon-alert {
           background: linear-gradient(90deg, #ffbc3f, #ff9f00);  /* Narancs-sárga átmenet */
           color: #1a150e;                      /* Sötét szöveg a kontrasztért */
           padding: 1rem;                          /* Belső térköz */
           text-align: center;                     /* Középre igazított szöveg */
           font-weight: bold;                      /* Kiemelt szöveg */
           border-bottom: 2px solid #e68a00;    /* Alsó elválasztó vonal */
           display: none;                       /* Alapból rejtett, JS aktiválja ha van kupon */
       }

       /*=============== IDŐZÍTŐ (VISSZASZÁMLÁLÓ) ===============*/
       /* Kupon lejárati idejének megjelenítése */
       #timer {
         color: #000000;   /* Fekete szöveg */
         font-weight: bold;
         margin-left: 5px;    /* Kis térköz a szöveg után */
      }

      /*=============== KUPON SZÖVEG ELEMEK ===============*/
      .coupon-main {
         margin-right: 10px;  /* Távolság a következő elemtől */
      }

      .coupon-divider {
         margin: 0 10px;   /* Két oldalról térköz */
         opacity: 0.6;     /* Halványabb elválasztó */
      }

      /*=============== LEJÁRATI KIEMELÉS ===============*/
      .coupon-expiry {
         background: rgb(255, 0, 81);  /* Figyelemfelkeltő piros háttér */
         padding: 3px 8px;                /* Kis belső tér */
         border-radius: 6px;              /* Lekerekített sarkok */
      }

      #timer {
         font-family: monospace; /* Digitális hatás */
         font-size: 1.2rem;      /* Kicsit nagyobb méret */
         margin-left: 5px;       /* Kis térköz a szöveg után */
      }
   </style>
</head>
<body>

   <!--==================== OLDAL FELÉPÍTÉSE (HEADER - MAIN - FOOTER) ====================-->
   <!-- 
   Ez a fájl a webáruház főoldalának teljes szerkezetét tartalmazza, amely három fő részre tagolódik:

   1. HEADER (Fejléc):
      - Tartalmazza a logót és a navigációs menüt.
      - Dinamikusan kezeli az aktív oldalt (PHP segítségével).
      - Jogosultság alapú megjelenítés:
         -> Admin menüpont csak admin felhasználóknak jelenik meg.
         -> Bejelentkezett felhasználók profil ikonja látható.
         -> Nem bejelentkezett felhasználók számára bejelentkezés gomb jelenik meg.
      - Kosár ikon mutatja a kiválasztott termékek darabszámát.
      - Reszponzív (mobilon hamburger menüvel működik).

   2. MAIN (Fő tartalom):
      Több különálló szekcióból épül fel:

      - Kupon visszaszámláló:
      Csak akkor jelenik meg, ha a felhasználónak aktív kuponja van.
      Dinamikusan mutatja a lejárati időt.

      - Home (Hero szekció):
      Nyitó rész háttérképpel, navigációs gyorslinkekkel és CTA gombbal.

      - Újdonságok:
      Adatbázisból lekért legfrissebb termékek (TOP 3).
      PHP ciklus generálja, AI összefoglalóval.

      - Rólunk:
      Rövid bemutatkozás a cégről és link a részletes oldalra.

      - Kedvencek:
      Legnépszerűbb termékek (eladások alapján).
      Tartalmazza:
         -> dinamikus árkalkulációt (kuponnal/kupon nélkül)
         -> készlet alapú vizuális jelölést
         -> kosárba helyezés funkciót
         -> kupon felhasználási limit kezelését

      - Vélemények:
      Statikus vásárlói visszajelzések megjelenítése.

      - Kapcsolat (CTA):
      Felhívás kapcsolatfelvételre külön szekcióban.

   3. FOOTER (Lábléc):
      - Céginformációk (név, leírás)
      - Cím és nyitvatartás
      - Kapcsolati adatok
      - Közösségi média linkek
      - Copyright

   TECHNIKAI JELLEMZŐK:
   - PHP + MySQL alapú dinamikus adatkezelés
   - Session kezelés (felhasználó, kosár, kuponok)
   - Reszponzív kialakítás
   - Dinamikus tartalomgenerálás (SQL lekérdezések)
   - Felhasználói jogosultság kezelés (admin / vásárló)
   - UI/UX elemek: visszajelzések, animációk, állapotjelzések

   A struktúra célja egy modern, felhasználóbarát és jól skálázható webáruház főoldal megvalósítása.
   -->




   <!--==================== HEADER ====================-->
   <!-- A fejléc tartalmazza a logót és a navigációs menüt -->
   <header class="header" id="header">
   <nav class="nav container">

      <!-- LOGÓ -->
      <!-- A főoldalra navigál -->
      <a href="index.php" class="nav__logo">
         <img src="assets/img/logo/MELICO LOGO.png" alt="MELICO Logo" />
      </a>

      <!-- NAVIGÁCIÓS MENÜ -->
      <div class="nav__menu" id="nav-menu">
         <ul class="nav__list">

            <!-- MENÜPONTOK -->
            <!-- Aktív oldal kiemelése PHP-val -->
            <li class="nav__item">
               <a href="index.php" class="nav__link <?php echo basename($_SERVER['PHP_SELF'])=='index.php' ? 'active-link' : ''; ?>">Főoldal</a>
            </li>

            <li class="nav__item">
               <a href="termekeink.php" class="nav__link <?php echo basename($_SERVER['PHP_SELF'])=='termekeink.php' ? 'active-link' : ''; ?>">Termékeink</a>
            </li>

            <li class="nav__item">
               <a href="rolunk.php" class="nav__link <?php echo basename($_SERVER['PHP_SELF'])=='rolunk.php' ? 'active-link' : ''; ?>">Rólunk</a>
            </li>

            <li class="nav__item">
               <a href="kapcsolatfelvetel.php" class="nav__link <?php echo basename($_SERVER['PHP_SELF'])=='kapcsolatfelvetel.php' ? 'active-link' : ''; ?>">Kapcsolatfelvétel</a>
            </li>

            <!-- ADMIN MENÜ (csak adminok látják) -->
            <?php if($isAdmin): ?>
            <li class="nav__item">
               <a href="admin.php" class="nav__link <?php echo basename($_SERVER['PHP_SELF'])=='admin.php' ? 'active-link' : ''; ?>">Admin</a>
            </li>
            <?php endif; ?>

            <!-- FELHASZNÁLÓ KEZELÉS -->
            <!-- Ha be van jelentkezve -> profil ikon -->
            <!-- Ha nincs -> bejelentkezés gomb -->
            <li class="nav__item">
               <?php if (isset($_SESSION['user_id'])): ?>
                  <a href="profil.php" class="nav__link nav__profile">
                        <i class="ri-user-line"></i>
                  </a>
               <?php else: ?>
                  <a href="signIn.php" class="nav__signin button">Bejelentkezés</a>
               <?php endif; ?>
            </li>

            <!-- KUPON ÉS KOSÁR (nem adminnak) -->
            <?php if (!$isAdmin): ?>
               <li class="nav__item">
                  <a href="kupon.php" class="nav__link <?php echo basename($_SERVER['PHP_SELF'])=='kupon.php' ? 'active-link' : ''; ?>">
                     <i class="ri-coupon-2-line"></i>
                  </a>
               </li>

               <!-- KOSÁR IKON + DARABSZÁM -->
               <li class="nav__item">
                   <a href="kosar.php" class="nav__link"><i class="ri-shopping-cart-fill"></i>
                   <?php 
                   $total_items = 0;
                   if (!empty($_SESSION['cart'])) {
                       foreach ($_SESSION['cart'] as $item) {
                           $total_items += $item['quantity'];
                       }
                       if ($total_items > 0) echo "($total_items)";
                   }
                   ?>
                   </a>
               </li>
            <?php endif; ?>
         </ul>

         <!-- MOBIL MENÜ BEZÁRÓ GOMB -->
         <div class="nav__close" id="nav-close"><i class="ri-close-line"></i></div>

         <!-- Díszítő képek -->
         <img src="assets/img/cheese2.png" alt="image" class="nav__img-1">
         <img src="assets/img/cheese1.png" alt="image" class="nav__img-2">
      </div>

      <!-- MOBIL MENÜ NYITÓ GOMB -->
      <div class="nav__toggle" id="nav-toggle"><i class="ri-menu-fill"></i></div>
   </nav>
</header>



   <!--==================== MAIN ====================-->
   <main class="main">

      <!-- KUPON VISSZASZÁMLÁLÓ -->
      <!-- Csak akkor jelenik meg, ha van aktív kupon -->
      <?php if ($discount > 0): ?>
      <div id="coupon-countdown" class="coupon-alert" style="display: block;">
         <i class="ri-time-line"></i> 

         <span class="coupon-main">
            FIGYELEM! Van egy <strong><?= $discount ?>%-os</strong> kuponod! Lejár:
         </span>

         <span class="coupon-expiry">
            <span id="timer">--:--:--</span>
         </span>
      </div>
      <?php endif; ?>
      


      <!--==================== HOME SZEKCIÓ ====================-->
      <!-- Fő nyitó rész (hero section) -->
      <section class="home section" id="home">

         <!-- Háttérkép -->
         <img src="assets/img/home-bg.jpg" alt="image" class="home__bg">
         <div class="home__shadow"></div>

         <!-- Tartalom -->
         <div class="home__container container grid">

            <!-- Szöveges rész -->
            <div class="home__data">
               <h1 class="home__title">
                  <a href="#home" class="nav__link active-link">Főoldal<br></a>

                  <a href="#new" class="nav__link">Újdonságok<br></a>

                 <!-- <a href="#about" class="nav__link">Rólunk<br></a> -->

                  <a href="#favorite" class="nav__link">Kedvenceink<br></a>

                  <a href="#testimonial" class="nav__link">Vásárlói Vélemények<br></a>

                 <!--<a href="#visit" class="nav__link">Kapcsolatfelvétel<br></a>-->
               </h1>

               <!-- CTA gomb -->
               <a href="termekeink.php" class="button">Fedezze Fel Termékeinket</a>

            </div>

            <!-- Kép -->
            <div class="home__image">
               <img src="assets/img/cheese3.png" alt="image" class="home__img">
            </div>
         </div>
      </section>



      <!--==================== ÚJDONSÁGOK ====================-->
      <!-- Legújabb termékek listázása adatbázisból -->
      <section class="new section" id="new">
         <h2 class="section__title">Újdonságok</h2>

         <div class="new__container container grid">

            <!-- Termék kártyák -->
            <div class="new__content grid">
               <!-- PHP ciklus generálja -->
               <?php
               $new_products_query = "SELECT id, name, description, category_id, image FROM PRODUCTS ORDER BY id DESC LIMIT 3";
               $new_products_result = $conn->query($new_products_query);

               if ($new_products_result && $new_products_result->num_rows > 0) {
                  while ($product = $new_products_result->fetch_assoc()) {
                     $name = htmlspecialchars($product['name']);
                     $ai_summary = generateAISummary($product['name'], $product['description']);
                     $imgPath = "assets/img/category_" . $product['category_id'] . "/" . $product['image'];
                     
                     if (empty($product['image']) || !file_exists($imgPath)) {
                           $imgPath = "assets/img/no-image.png";
                     }
               ?>
                     <article class="new__card">
                        <div class="new__data">
                           <h2 class="new__title"><?php echo $name; ?></h2>
                           <p class="new__description"><?php echo htmlspecialchars($ai_summary); ?></p>
                        </div>

                        <img src="<?php echo $imgPath; ?>" 
                              alt="<?php echo $name; ?>" 
                              class="new__img" 
                              style="cursor: pointer;" 
                              onclick="window.location.href='termek.php?id=<?php echo $product['id']; ?>&from=index.php'">
                     </article>
               <?php
                  }
               } else {
                  echo "<p>Hamarosan újabb finomságokkal jelentkezünk!</p>";
               }
               ?>
            </div>

            <!-- További termékek gomb -->
            <a href="termekeink.php" class="new__button button">További Termékeink</a>
         </div>
      </section>



      <!--==================== RÓLUNK ====================-->
      <!-- Bemutatkozó szekció -->
      <section class="about section" id="about">

         <div class="about__container container grid">

            <!-- Szöveg -->
            <div class="about__data">
               <h2 class="section__title">Rólunk</h2>

               <p class="about__description">
                  A MELICO prémium minőségű kézműves sajtokat kínál, a hagyományos receptek és a friss, helyi alapanyagok felhasználásával. 
                  Webáruházunk egyszerűvé teszi az egyedi sajtok online rendelését.
               </p>

               <a href="rolunk.php" class="button">Tudjon Meg Többet</a>

               <img src="assets/img/logo/MELICO LOGO 2.png" alt="image" class="about__cheese">
            </div>

            <!-- Kép -->
            <img src="assets/img/about-melico.jpg" alt="image" class="about-img">
         </div>
      </section>
      


      <!--==================== KEDVENCEK ====================-->
      <!-- Legnépszerűbb termékek (eladások alapján) -->

      <?php if (isset($_SESSION['error'])): ?>
         <p style="color:red; text-align:center; margin:15px 0;">
            <?= $_SESSION['error']; ?>
         </p>
         <?php unset($_SESSION['error']); ?>
      <?php endif; ?>

      <section class="favorite section" id="favorite">

         <h2 class="section__title">Vásárlói Kedvencek</h2>

         <!-- PHP generálja TOP 3 terméket -->
         <div class="favorite__container container grid">
            <?php
            $sql = "
                  SELECT p.id, p.name, p.price, p.stock, p.category_id, c.name AS category_name,
                        COALESCE(SUM(oi.quantity),0) AS total_sold, p.image
                  FROM PRODUCTS p
                  JOIN CATEGORIES c ON p.category_id = c.id
                  LEFT JOIN ORDER_ITEMS oi ON p.id = oi.product_id
                  GROUP BY p.id
                  ORDER BY total_sold DESC
                  LIMIT 3
            ";

            $result = $conn->query($sql);

            if ($result && $result->num_rows > 0) {
                  while ($row = $result->fetch_assoc()) {
                     $product_id = (int)$row['id'];
                     $product_name = htmlspecialchars($row['name']);
                     $category_name = htmlspecialchars($row['category_name']);
                     $category_id = (int)$row['category_id'];
                     
                     // --- VIZUÁLIS ÁR KALKULÁCIÓ ---
                     $original_price = $row['price'];
                     $price_after_discount = $original_price;
                     if ($discount > 0) {
                        $price_after_discount = $original_price * (1 - ($discount / 100));
                     }

                     if ($row['stock'] > 15) { $stockClass = "stock-high"; } 
                     elseif ($row['stock'] > 0) { $stockClass = "stock-medium"; } 
                     else { $stockClass = "stock-zero"; }

                     $imgPath = "assets/img/category_{$category_id}/" . $row['image'];
                     if (!file_exists($imgPath) || empty($row['image'])) {
                        $imgPath = "assets/img/no-image.png";
                     }
            ?>
                     <article class="favorite__card <?= $stockClass ?>">
                        <a href="termek.php?id=<?= $product_id ?>&from=index.php">
                           <img src="<?= $imgPath ?>" class="favorite__img" alt="<?= $product_name ?>">
                        </a>

                        <div class="favorite__data">
                           <h2 class="favorite__title"><?= $product_name ?></h2>
                           <span class="favorite__subtitle"><?= $category_name ?></span>
                           
                           <h3 class="favorite__price">

                           <?php
                           // KVÓTA SZÁMOLÁS
                           $already_bought_discounted = 0;

                           if (isset($_SESSION['user_id']) && $discount > 0) {
                              $check_stmt = $conn->prepare("
                                 SELECT SUM(oi.quantity) as total 
                                 FROM ORDER_ITEMS oi
                                 JOIN ORDERS o ON oi.order_id = o.id
                                 WHERE o.user_id = ? AND oi.product_id = ? AND oi.sale_price < ?
                              ");
                              $check_stmt->bind_param("iid", $_SESSION['user_id'], $product_id, $original_price);
                              $check_stmt->execute();
                              $res_bought = $check_stmt->get_result()->fetch_assoc();
                              $already_bought_discounted = $res_bought['total'] ?? 0;
                              $check_stmt->close();
                           }

                           $discount_key = $product_id . "_discounted";
                           $already_in_cart_discounted = $_SESSION['cart'][$discount_key]['quantity'] ?? 0;

                           $total_used_quota = $already_bought_discounted + $already_in_cart_discounted;

                           $show_discount = ($discount > 0 && $total_used_quota < $max_discounted_items);
                           $price_after_discount = $original_price * (1 - $discount / 100);
                           ?>

                           <?php if ($show_discount): ?>

                              <span style="text-decoration: line-through; color: #aaa; font-size: 0.8rem;">
                                 <?= number_format($original_price, 0, '', ' ') ?> Ft
                              </span>
                              <span style="color: #ffbc3f;"> 
                                 <?= number_format($price_after_discount, 0, '', ' ') ?> Ft/kg
                              </span>
                              <br>
                              <small style="font-weight:bold; color:#f7ff8c;">
                                 Maradt: <?= $max_discounted_items - $total_used_quota ?> db
                              </small>

                           <?php else: ?>

                              <?= number_format($original_price, 0, '', ' ') ?> Ft/kg

                              <?php if ($discount > 0 && $total_used_quota >= 4): ?>
                                 <br>
                                 <small style="color:red; font-weight:bold;">
                                       Nincs több kuponod!
                                 </small>
                              <?php endif; ?>

                           <?php endif; ?>

                           </h3>
                        </div>

                        <?php if(isset($_SESSION['role']) && $_SESSION['role'] == '0'): ?>
                        <form method="POST">
                           <input type="hidden" name="id" value="<?= $row['id'] ?>">
                           <button type="submit" name="add_to_cart" class="favorite__button button">
                              <i class="ri-add-line"></i>
                           </button>
                        </form>
                     <?php endif; ?>
                  </article>
            <?php
                  }
            }
            ?>
         </div>
      </section>

      <!--==================== VÉLEMÉNYEK ====================-->
      <!-- Vásárlói visszajelzések -->
      <section class="testimonial section" id="testimonial">
         <h2 class="section__title">Vásárlói Vélemények</h2>

         <div class="testimonial__container container grid">
            <!-- Statikus vélemények -->

            <div class="testimonial__content grid">
               <article class="testimonial__card">
                  <div class="testimonial__data">
                     <i class="ri-double-quotes-l testimonial__icon"></i>
                     <p class="testimonial__description">
                        A sajtok mindig frissek és ízletesek. Ez a minőség egyedülálló, és a kiszállítás gyors és pontos.
                     </p>

                     <div class="testimonial__profile">
                        <img src="assets/img/Vásárlói Vélemények/Péter.jpg" alt="image" class="testimonial__img">
                        <div>
                           <h3 class="testimonial__name">Péter</h3>
                           <span class="testimonial__status">Rendszeres Vásárló</span>
                        </div>
                     </div>
                  </div>
               </article>


               <article class="testimonial__card">
                  <div class="testimonial__data">
                     <i class="ri-double-quotes-l testimonial__icon"></i>
                     <p class="testimonial__description">
                        Különösen tetszik, hogy a rendelés után végig nyomon követhető a kiszállítás.
                        A sajtok kifogástalan állapotban érkeztek meg, érezhető a gondos logisztika.
                     </p>

                     <div class="testimonial__profile">
                        <img src="assets/img/Vásárlói Vélemények/Katalin.jpg" alt="image" class="testimonial__img">
                        <div>
                           <h3 class="testimonial__name">Katalin</h3>
                           <span class="testimonial__status">Prémium Vásárló</span>
                        </div>
                     </div>
                  </div>
               </article>



               <article class="testimonial__card">
                  <div class="testimonial__data">
                     <i class="ri-double-quotes-l testimonial__icon"></i>
                     <p class="testimonial__description">
                        Ritkán találni ilyen jól szervezett kézműves webáruházat.
                        A futár pontos volt, a termékek pedig valóban prémium minőséget képviselnek.
                     </p>

                     <div class="testimonial__profile">
                        <img src="assets/img/Vásárlói Vélemények/Balázs.jpg" alt="image" class="testimonial__img">
                        <div>
                           <h3 class="testimonial__name">Balázs</h3>
                           <span class="testimonial__status">Visszatérő Vásárló</span>
                        </div>
                     </div>
                  </div>
               </article>

            </div>
         </div>
      </section>
