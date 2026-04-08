/* 
==================== MELICO – RÓLUNK OLDAL ====================

Leírás:
Ez a fájl a MELICO webáruház „Rólunk” oldalát valósítja meg, amely bemutatja
a vállalkozás célját, termékeit, beszállítóit, valamint a vásárlói élményt.

Fő funkciók:
- Session kezelés és felhasználói állapot ellenőrzés
- Aktív kupon kezelése és visszaszámláló megjelenítése
- Jogosultság alapú megjelenítés (pl. admin menüpont)
- Dinamikus navigációs menü (bejelentkezés / profil / kosár)
- Kosár darabszám megjelenítése
- Reszponzív és modern UI elemek használata

Technikai megoldások:
- PHP session alapú felhasználókezelés
- JavaScript alapú kupon visszaszámláló (real-time frissítéssel)
- CSS és külső ikon könyvtárak (Remixicon) használata
- Strukturált HTML szekciók (about blokkok)
- ScrollReveal animációk támogatása

Biztonsági és logikai elemek:
- Kupon lejárat ellenőrzése szerver oldalon
- Session adatok tisztítása lejárt kupon esetén
- Admin jogosultság külön kezelése
- Alapvető kliensoldali integritás ellenőrzés (védelem manipuláció ellen)

Cél:
Egy informatív, esztétikus és felhasználóbarát oldal biztosítása,
amely erősíti a márka hitelességét és támogatja a vásárlói döntést.

===============================================================
*/

<?php
include "db.php";
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Ellenőrizd, hogy van-e aktív kupon
$discount = 0;
$expiry_timestamp = 0;

if (isset($_SESSION['user_id'])) {
    if (isset($_SESSION['coupon_expiry']) && $_SESSION['coupon_expiry'] > time()) {
        $discount = $_SESSION['coupon_discount'] ?? 0;
        $expiry_timestamp = $_SESSION['coupon_expiry'] * 1000; // JS-nek milliszekundum
    } else {
        unset($_SESSION['coupon_discount']);
        unset($_SESSION['coupon_expiry']);
    }
}

// Ellenőrizd, hogy a felhasználó admin-e
$isAdmin = isset($_SESSION['role']) && $_SESSION['role'] == '2';
?>
<!DOCTYPE html>
<html lang="hu">
<head>
   <meta charset="UTF-8">
   <meta name="viewport" content="width=device-width, initial-scale=1.0">

   <link rel="shortcut icon" href="assets/img/logo/MELICO LOGO 2.png" type="image/x-icon">
   <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/remixicon/3.7.0/remixicon.css">
   <link rel="stylesheet" href="assets/css/styles.css">

   <title>MELICO – Rólunk</title>

   <style>
    /* Egy kis extra stílus a visszaszámlálónak */
       .coupon-alert {
           background: linear-gradient(90deg, #ffbc3f, #ff9f00);
           color: #1a150e;
           padding: 1rem;
           text-align: center;
           font-weight: bold;
           border-bottom: 2px solid #e68a00;
           display: none; /* Alapértelmezetten rejtve, JS fedi fel ha van aktív kupon */
       }
       #timer {
         color: #000000;
         font-weight: bold;
         margin-left: 5px;
      }

      .coupon-main {
         margin-right: 10px;
      }

      .coupon-divider {
         margin: 0 10px;
         opacity: 0.6;
      }

      .coupon-expiry {
         background: rgb(255, 0, 81);
         padding: 3px 8px;
         border-radius: 6px;
      }

      #timer {
         font-family: monospace;
         font-size: 1.2rem;
         margin-left: 5px;
      }
    </style>
</head>
<body>

<!--==================== HEADER ====================-->
<!-- A weboldal fejléc része, amely tartalmazza a navigációs menüt -->
<header class="header" id="header">
   <nav class="nav container">

      <!-- Logó, visszavisz a főoldalra -->
      <a href="index.php" class="nav__logo">
         <img src="assets/img/logo/MELICO LOGO.png" alt="MELICO Logo" />
      </a>

      <!-- Navigációs menü -->
      <div class="nav__menu" id="nav-menu">
         <ul class="nav__list">

            <!-- Menüelemek, aktív oldal kiemeléssel PHP segítségével -->
            <li class="nav__item">
               <a href="index.php" class="nav__link <?= basename($_SERVER['PHP_SELF'])=='index.php' ? 'active-link' : ''; ?>">Főoldal</a>
            </li>

            <li class="nav__item">
               <a href="termekeink.php" class="nav__link <?= basename($_SERVER['PHP_SELF'])=='termekeink.php' ? 'active-link' : ''; ?>">Termékeink</a>
            </li>

            <li class="nav__item">
               <a href="rolunk.php" class="nav__link <?= basename($_SERVER['PHP_SELF'])=='rolunk.php' ? 'active-link' : ''; ?>">Rólunk</a>
            </li>

            <li class="nav__item">
               <a href="kapcsolatfelvetel.php" class="nav__link <?= basename($_SERVER['PHP_SELF'])=='kapcsolatfelvetel.php' ? 'active-link' : ''; ?>">Kapcsolatfelvétel</a>
            </li>

            <!-- Admin menüpont csak admin jogosultság esetén jelenik meg -->
            <?php if($isAdmin): ?>
            <li class="nav__item">
               <a href="admin.php" class="nav__link <?= basename($_SERVER['PHP_SELF'])=='admin.php' ? 'active-link' : ''; ?>">Admin</a>
            </li>
            <?php endif; ?>

            <!-- Bejelentkezés vagy profil ikon dinamikusan -->
            <li class="nav__item">
               <?php if (isset($_SESSION['user_id'])): ?>
                  <a href="profil.php" class="nav__link nav__profile">
                        <i class="ri-user-line"></i>
                  </a>
               <?php else: ?>
                  <!-- Nem bejelentkezett felhasználó -->
                  <a href="signIn.php" class="nav__signin button">Bejelentkezés</a>
               <?php endif; ?>
            </li>

            <!-- Kupon és kosár csak nem admin felhasználóknak -->
            <?php if (!$isAdmin): ?>

               <!-- Kupon ikon -->
               <li class="nav__item">
                  <a href="kupon.php" class="nav__link <?php echo basename($_SERVER['PHP_SELF'])=='kupon.php' ? 'active-link' : ''; ?>">
                     <i class="ri-coupon-2-line"></i>
                  </a>
               </li>

               <!-- Kosár ikon + darabszám megjelenítése -->
               <li class="nav__item">
                   <a href="kosar.php" class="nav__link"><i class="ri-shopping-cart-fill"></i>
                   <?php 
                   /* Kosárban lévő termékek összesítése session alapján */
                   $total_items = 0;
                   if (!empty($_SESSION['cart'])) {
                       foreach ($_SESSION['cart'] as $item) {
                           $total_items += $item['quantity'];
                       }
                       /* Darabszám megjelenítése, ha nem üres */
                       if ($total_items > 0) echo "($total_items)";
                   }
                   ?>
                   </a>
               </li>
            <?php endif; ?>
         </ul>

         <!-- Mobil menü bezáró ikon -->
         <div class="nav__close" id="nav-close">
            <i class="ri-close-line"></i>
         </div>

         <!-- Dekoratív képek a menüben -->
        <img src="assets/img/cheese2.png" alt="image" class="nav__img-1">
        <img src="assets/img/cheese1.png" alt="image" class="nav__img-2">
      </div>

      <div class="nav__toggle" id="nav-toggle">
         <i class="ri-menu-fill"></i>
      </div>
   </nav>
</header>

<!--==================== MAIN ====================-->
<main class="main">

<!-- Kupon figyelmeztető sáv (csak ha van aktív kedvezmény) -->
<?php if ($discount > 0): ?>
      <div id="coupon-countdown" class="coupon-alert" style="display: block;">
         <i class="ri-time-line"></i> 

         <!-- Kupon információ -->
         <span class="coupon-main">
            FIGYELEM! Van egy <strong><?= $discount ?>%-os</strong> kuponod! Lejár:
         </span>

         <!-- Idő visszaszámláló -->
         <span class="coupon-expiry">
            <span id="timer">--:--:--</span>
         </span>
      </div>
      <?php endif; ?>

      <!-- Háttérkép -->
      <img src="assets/img/Rólunk-bg.png" alt="image" class="home__bg">

<!--==================== RÓLUNK ====================-->
<!-- MELICO Manufaktúra bemutatása -->
<section class="about section">
   <div class="about__container container grid">

      <div class="about__data">
         <h2 class="section__title">Rólunk</h2>

         <p class="about__description">
            A MELICO egy prémium minőségű kézműves sajtokra specializálódott webáruház,
            amelynek célja, hogy a különleges gasztronómiai élményeket kereső vásárlók
            számára egyszerűen, gyorsan és biztonságosan tegye elérhetővé
            a legjobb hazai és nemzetközi sajtokat.
         </p>

         <p class="about__description">
            Hiszünk abban, hogy egy igazán jó sajt nem csupán egy termék,
            hanem élmény is. Az alapanyagok minősége, az érlelés módja
            és a gondos szállítás mind hozzájárulnak ahhoz,
            hogy vásárlóink valódi prémium minőséget kapjanak.
         </p>
      </div>

      <!-- Illusztráció -->
      <img src="assets/img/about-melico.jpg" alt="MELICO manufaktúra" class="about-img">
   </div>
</section>


<!--==================== TOVÁBBI SZEKCIÓK ====================-->
<!-- A további szekciók (Termékeink, Beszállítók, Szállítás, Vásárlói élmény) 
     ugyanazt a struktúrát követik: kép + szöveg kombináció grid elrendezésben -->


<!--==================== SAJTJAINK ====================-->
<section class="about section about--reverse">
   <div class="about__container container grid">

      <img src="assets/img/cheese3.png" alt="Kézműves sajtok" class="about-img">

      <div class="about__data">
         <h2 class="section__title">Termékeink</h2>

         <p class="about__description">
            Kínálatunkban gondosan válogatott kézműves sajtok szerepelnek,
            amelyek hagyományos receptek alapján, természetes alapanyagokból készülnek.
            A friss lágy sajtoktól kezdve az érlelt, karakteres ízvilágú különlegességekig
            mindenki megtalálhatja a számára tökéletes választást.
         </p>

         <p class="about__description">
            Fontos számunkra az állandó minőség, ezért kizárólag megbízható
            termelőkkel dolgozunk együtt, akik számára a sajt nem tömegtermék,
            hanem szenvedély.
         </p>
      </div>
   </div>
</section>

<!--==================== BESZÁLLÍTÓK ====================-->
<section class="about section">
   <div class="about__container container grid">

      <div class="about__data">
         <h2 class="section__title">Beszállítóink</h2>

         <p class="about__description">
            A MELICO beszállítói között kis családi gazdaságok és elismert
            sajtműhelyek egyaránt megtalálhatók. Közös bennük a minőség iránti
            elkötelezettség és a hagyományos sajtészítési technikák tisztelete.
         </p>

         <p class="about__description">
            Partnereinkkel szoros kapcsolatot ápolunk, így pontosan tudjuk,
            honnan származik minden egyes termék, és biztosítani tudjuk
            a folyamatos, megbízható ellátást.
         </p>
      </div>

      <img src="assets/img/Beszállítóink.jpg" alt="Sajt beszállítók" class="about-img">
   </div>
</section>

<!--==================== SZÁLLÍTÁS ====================-->
<section class="about section about--reverse">
   <div class="about__container container grid">

      <img src="assets/img/MELICO Beszállítói furgon.png" alt="Szállítás" class="about-img">

      <div class="about__data">
         <h2 class="section__title">Szállítás és frissesség</h2>

         <p class="about__description">
            A sajtok minőségének megőrzése kiemelten fontos számunkra,
            ezért a rendeléseket gondosan csomagolva, ellenőrzött körülmények között
            juttatjuk el vásárlóinkhoz.
         </p>

         <p class="about__description">
            Saját kiszállítási rendszerünk lehetővé teszi,
            hogy a rendelés minden lépése nyomon követhető legyen,
            így a termékek frissen és biztonságosan érkeznek meg.
         </p>
      </div>
   </div>
</section>

<!--==================== VÁSÁRLÓI ÉLMÉNY ====================-->
<section class="about section">
   <div class="about__container container grid">

      <div class="about__data">
         <h2 class="section__title">Vásárlói élmény</h2>

         <p class="about__description">
            Webáruházunkat úgy alakítottuk ki, hogy a vásárlás gyors,
            átlátható és kényelmes legyen. A részletes termékleírások,
            vásárlói vélemények és egyszerű rendelési folyamat
            mind ezt a célt szolgálják.
         </p>

         <p class="about__description">
            Legyen szó egy különleges vacsoráról, ajándékról vagy
            mindennapi gasztronómiai élvezetről, a MELICO-nál
            biztos lehet benne, hogy minőségi sajtot választ.
         </p>

         <a href="termekeink.php" class="button">Termékeink megtekintése</a>
      </div>

      <img src="assets/img/Vásárlói élmény.jpg" alt="Vásárlói élmény" class="about-img">
   </div>
</section>

</main>
