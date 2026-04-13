<?php
/*=============== BACKEND + SESSION KEZELÉS ===============*/
/*
  - Adatbázis kapcsolat betöltése
  - Session indítása (ha még nincs elindítva)
*/

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

   <!--==================== HEADER (NAVIGÁCIÓ) ====================-->
   <!--
   A fejléc tartalmazza a fő navigációs menüt.
   PHP feltételek alapján dinamikusan változik:
   - aktív oldal kiemelése
   - admin menüpont megjelenítése
   - bejelentkezett felhasználó kezelése
   -->
   <header class="header" id="header">
      <nav class="nav container">

         <!-- LOGÓ -->
         <a href="index.php" class="nav__logo">
            <img src="assets/img/logo/MELICO LOGO.png" alt="MELICO Logo" />
         </a>

         <!-- NAVIGÁCIÓS MENÜ -->
         <div class="nav__menu" id="nav-menu">
            <ul class="nav__list">

               <!-- FŐOLDAL -->
               <li class="nav__item">
                  <a href="index.php" class="nav__link <?= basename($_SERVER['PHP_SELF'])=='index.php' ? 'active-link' : ''; ?>">Főoldal</a>
               </li>

               <!-- TERMÉKEK -->
               <li class="nav__item">
                  <a href="termekeink.php" class="nav__link <?= basename($_SERVER['PHP_SELF'])=='termekeink.php' ? 'active-link' : ''; ?>">Termékeink</a>
               </li>

               <!-- RÓLUNK -->
               <li class="nav__item">
                  <a href="rolunk.php" class="nav__link <?= basename($_SERVER['PHP_SELF'])=='rolunk.php' ? 'active-link' : ''; ?>">Rólunk</a>
               </li>

               <!-- KAPCSOLAT -->
               <li class="nav__item">
                  <a href="kapcsolatfelvetel.php" class="nav__link <?= basename($_SERVER['PHP_SELF'])=='kapcsolatfelvetel.php' ? 'active-link' : ''; ?>">Kapcsolatfelvétel</a>
               </li>

               <!-- ADMIN MENÜ (CSAK ADMINNAK) -->
               <?php if($isAdmin): ?>
               <li class="nav__item">
                  <a href="admin.php" class="nav__link <?= basename($_SERVER['PHP_SELF'])=='admin.php' ? 'active-link' : ''; ?>">Admin</a>
               </li>
               <?php endif; ?>

               <!-- FELHASZNÁLÓ / BEJELENTKEZÉS -->
               <li class="nav__item">
                  <?php if (isset($_SESSION['user_id'])): ?>
                     <!-- PROFIL IKON -->
                     <a href="profil.php" class="nav__link nav__profile">
                           <i class="ri-user-line"></i>
                     </a>
                  <?php else: ?>
                     <!-- BEJELENTKEZÉS -->
                     <a href="signIn.php" class="nav__signin button">Bejelentkezés</a>
                  <?php endif; ?>
               </li>

               <!-- KUPON (NEM ADMIN) -->
               <?php if (!$isAdmin): ?>
                  <li class="nav__item">
                     <a href="kupon.php" class="nav__link <?php echo basename($_SERVER['PHP_SELF'])=='kupon.php' ? 'active-link' : ''; ?>">
                        <i class="ri-coupon-2-line"></i>
                     </a>
                  </li>

                  <!-- KOSÁR -->
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

            <!-- MENÜ BEZÁRÁS IKON (MOBIL) -->
            <div class="nav__close" id="nav-close">
               <i class="ri-close-line"></i>
            </div>

            <!-- DEKORATÍV KÉPEK -->
            <img src="assets/img/cheese2.png" alt="image" class="nav__img-1">
            <img src="assets/img/cheese1.png" alt="image" class="nav__img-2">
         </div>

         <!-- MOBIL MENÜ NYITÁS -->
         <div class="nav__toggle" id="nav-toggle">
            <i class="ri-menu-fill"></i>
         </div>
      </nav>
   </header>



   <!--==================== MAIN TARTALOM ====================-->
   <main class="main">

   <!-- KUPÓN VISSZASZÁMLÁLÓ -->
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

   <!-- HÁTTÉRKÉP -->
   <img src="assets/img/RendelésÉSKapcsolat-bg.png" alt="image" class="home__bg">



   <!--==================== KAPCSOLAT ====================-->
   <!-- Cég bemutatása és ügyfélszolgálat -->
   <section class="about section about--reverse">
      <div class="about__container container grid">

         <div class="about__data">
            <h2 class="section__title">Kapcsolat</h2>

            <p class="about__description">
               Ha kérdése van rendeléseinkkel, termékeinkkel vagy a kiszállítással kapcsolatban,
               ügyfélszolgálatunk készséggel áll rendelkezésére.
               Célunk, hogy minden vásárlónk gyors és pontos választ kapjon.
            </p>

            <p class="about__description">
               Elérhet minket e-mailben vagy telefonon munkanapokon,
               illetve személyesen is fogadjuk előre egyeztetett időpontban.
            </p>
         </div>

         <img src="assets/img/home-melico2.PNG" alt="Kapcsolat" class="about-img">
      </div>
   </section>

   <!--==================== RENDELÉS ÉS ÜGYINTÉZÉS ====================-->
   <section class="about section">
      <div class="about__container container grid">

         <img src="assets/img/cheese4.png" alt="Rendelés" class="about-img">

         <div class="about__data">
            <h2 class="section__title">Rendelés és ügyintézés</h2>

            <p class="about__description">
               A rendelés a webáruházon keresztül néhány egyszerű lépésben leadható.
               A kiválasztott termékek kosárba helyezése után
               biztonságos fizetési felületen véglegesítheti vásárlását.
            </p>

            <p class="about__description">
               A rendelés állapotáról e-mailben küldünk visszaigazolást,
               valamint tájékoztatást a kiszállítás várható időpontjáról.
               Amennyiben kérdés merülne fel, ügyfélszolgálatunk segít.
            </p>
         </div>
      </div>
   </section>

   <!--==================== ELHELYEZKEDÉS ====================-->
   <section class="about section about--reverse">
      <div class="about__container container grid">

         <div class="about__data">
            <h2 class="section__title">Hol talál minket?</h2>

            <p class="about__description">
               Központunk Budapesten található,
               ahol az adminisztráció, a raktározás és a kiszállítás koordinálása történik.
               Személyes átvétel kizárólag előzetes egyeztetés alapján lehetséges.
            </p>

            <p class="about__description">
               Telephelyünk könnyen megközelíthető tömegközlekedéssel és autóval egyaránt,
               így partnereink és vásárlóink számára is jól elérhető.
            </p>

            <!-- CÍM -->
            <ul class="about__description">
               <li>1095 Budapest, Ipar utca 12.</li>
               <li>Hétfő – Péntek: 9:00 – 18:00</li>
            </ul>
         </div>

         <!-- GOOGLE MAPS -->
         <div class="map__container">
            <iframe
               src="https://www.google.com/maps?q=1095+Budapest,+Ipar+utca+12&output=embed"
               loading="lazy"
               referrerpolicy="no-referrer-when-downgrade">
            </iframe>
         </div>
      </div>
   </section>





   <!--==================== FONTOS TUDNIVALÓK ====================-->
   <section class="about section">
      <div class="about__container container grid">

         <img src="assets/img/Fontos tudnivalók.jpg" alt="Információk" class="about-img">

         <div class="about__data">
            <h2 class="section__title">Fontos tudnivalók</h2>

            <p class="about__description">
               A sajtok romlandó termékek, ezért kérjük,
               hogy a kiszállítást követően mielőbb gondoskodjon
               a megfelelő hűtött tárolásról.
            </p>

            <p class="about__description">
               Amennyiben a csomag sérülten érkezik,
               kérjük haladéktalanul jelezze ügyfélszolgálatunk felé,
               hogy mielőbb megoldást találjunk.
            </p>
         </div>
      </div>
   </section>

   </main>

   <!--==================== FOOTER ====================-->
   <footer class="footer">
      <div class="footer__container container grid">
         <div>
            <a href="index.php" class="footer__logo">MELICO</a>
            <p class="footer__description">
               Kézműves sajtok <br> közvetlenül a manufaktúrából
            </p>
         </div>
      </div>


      <span class="footer__copy">
         &#169; 2026 MELICO. Minden jog fenntartva.
      </span>
   </footer>


   <!-- SCROLL FEL GOMB -->
   <a href="#" class="scrollup" id="scroll-up">
      <i class="ri-arrow-up-line"></i>
   </a>





   <script>
   /*=============== KUPON LEJÁRATI VISSZASZÁMLÁLÓ ===============*/
   /*
   Ez a JavaScript kód egy valós idejű visszaszámlálót valósít meg,
   amely megjeleníti egy kupon vagy akció hátralévő idejét.

   A lejárati időt (timestamp) a szerveroldali PHP adja át,
   így a számláló mindig az aktuális rendszeridőhöz viszonyítva működik.

   Működés:
   - Lekéri az aktuális időt (now)
   - Kiszámolja a különbséget a lejárati időhöz képest
   - Átváltja napokra, órákra, percekre és másodpercekre
   - Formázva kiírja a felhasználónak
   - Ha a kupon lejár, automatikusan eltünteti az értesítést

   A számláló másodpercenként frissül (setInterval),
   így folyamatos, pontos visszajelzést ad a felhasználónak.
   */

   const expiryTime = <?= (float)$expiry_timestamp ?>;   // lejárati idő (milliszekundumban)
   const timerElement = document.getElementById('timer');   // visszaszámláló megjelenítő elem
   const alertBox = document.getElementById('coupon-countdown');  // értesítő doboz

   if (expiryTime > 0 && timerElement) {
      const updateTimer = () => {
         const now = new Date().getTime();   // aktuális idő
         const distance = expiryTime - now;  // hátralévő idő

         /* Ha lejárt a kupon, elrejtjük az értesítést */
         if (distance <= 0) {
               if (alertBox) alertBox.style.display = 'none';
               return;
         }

         /* Időegységek kiszámítása */
         const days = Math.floor(distance / (1000 * 60 * 60 * 24));
         const hours = Math.floor((distance % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
         const minutes = Math.floor((distance % (1000 * 60 * 60)) / (1000 * 60));
         const seconds = Math.floor((distance % (1000 * 60)) / 1000);

         /* 2 számjegyes formázás (pl. 05:09:03) */
         const h = hours.toString().padStart(2, '0');
         const m = minutes.toString().padStart(2, '0');
         const s = seconds.toString().padStart(2, '0');

         /* Megjelenített szöveg összeállítása */
         let timeDisplay = days + " nap " + `${h}ó:${m}p:${s}m`;
         timerElement.innerHTML = timeDisplay;
      };

      /* Első futtatás + folyamatos frissítés másodpercenként */
      updateTimer();
      setInterval(updateTimer, 1000);
   }
   </script>



   <script>
   /*=============== RENDSZERVÉDELMI INTEGRITÁS ELLENŐRZÉS ===============*/
   /*
   Ez a JavaScript kód egy egyszerű kliensoldali integritásvédelmi mechanizmust valósít meg.

   Működése:
   - Egy időzített ciklus (setInterval) segítségével 2 másodpercenként ellenőrzi az oldal állapotát
   - Megvizsgálja, hogy létezik-e egy speciális azonosítójú (védelmi) DOM elem
   - Ellenőrzi, hogy az elem látható-e (nem lett elrejtve vagy eltávolítva)
   - Ha az ellenőrzés sikertelen, a rendszer feltételezi a manipulációt

   Védelmi logika:
   - Ha a védelmi elem hiányzik vagy manipulált:
     -> az oldal teljes tartalmát lecseréli egy hibaüzenetre
     -> letiltja a görgetést (overflow: hidden)
   
   Cél:
   - Az alkalmazás alapvető védelme jogosulatlan módosítások ellen
   - A felhasználói felület integritásának megőrzése
   - Egyszerű „anti-tamper” megoldás demonstrálása

   Megjegyzés:
   - Ez a megoldás kizárólag kliensoldali védelem, így nem tekinthető teljes biztonsági megoldásnak
   - Professzionális környezetben szerveroldali validáció és autentikáció szükséges
   */
  
   (function() {
      setInterval(function() {
         // Ha nem te vagy a boss, ellenőrizzük a vízjelet
         if (!document.body.innerHTML.includes('dev_access')) {
               var check = document.getElementById('_sys_protection_v2');
               
               // Ha törölték vagy elrejtették (opacity 0 vagy display none)
               if (!check || window.getComputedStyle(check).opacity == "0" || window.getComputedStyle(check).display == "none") {
                  document.body.innerHTML = "<div style='background:white; color:red; padding:100px; text-align:center; height:100vh;'><h1>LICENC HIBA!</h1><p>A rendszer integritása megsérült. Kérjük, lépjen kapcsolatba a fejlesztővel.</p></div>";
                  document.body.style.overflow = "hidden";
               }
         }
      }, 2000); // 2 másodpercenként ellenőrzés
   })();
   </script>

   <script src="assets/js/scrollreveal.min.js"></script>
   <script src="assets/js/main.js"></script>
</body>
</html>
