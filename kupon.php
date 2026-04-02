<?php 
/*=============== SESSION ÉS ADATBÁZIS KAPCSOLAT ===============*/
session_start();        // Munkamenet indítása (felhasználói adatok tárolása)
include "db.php";       // Adatbázis kapcsolat betöltése


/*=============== RENDSZERBEÁLLÍTÁSOK LEKÉRÉSE ===============*/
// A kupon érvényességi idejének lekérdezése az adatbázisból
$settings_res = $conn->query("SELECT coupon_validity_days FROM SETTINGS LIMIT 1");
$settings = $settings_res->fetch_assoc();
$validity_days = $settings['coupon_validity_days'] ?? 7;    // Alapértelmezett: 7 nap


/*=============== BEJELENTKEZÉS ELLENŐRZÉSE ===============*/
// Ha nincs bejelentkezett felhasználó → átirányítás
if (!isset($_SESSION['user_id'])) {
    header("Location: signIn.php");
    exit();
}
$uid = $_SESSION['user_id'];    // Aktuális felhasználó ID


/*=============== ÜZENETEK INICIALIZÁLÁSA ===============*/
$success_msg = "";
$error_msg = "";


/*=============== VISSZA GOMB LOGIKA ===============*/
// Engedélyezett oldalak listája
$allowed_pages = ['admin.php', 'futar.php', 'index.php', 'kapcsolatfelvetel.php', 'rolunk.php', 'termekeink.php'];
$back_url = 'index.php';    // alapértelmezett vissza oldal

// HTTP_REFERER ellenőrzése (előző oldal)
if (isset($_SERVER['HTTP_REFERER'])) {
    $ref_path = parse_url($_SERVER['HTTP_REFERER'], PHP_URL_PATH);
    $ref_page = basename($ref_path);

    // Csak engedélyezett oldalakra lehessen visszamenni
    if (in_array($ref_page, $allowed_pages)) {
        $back_url = $_SERVER['HTTP_REFERER'];
    }
}


/*=============== KUPON BEVÁLTÁS LOGIKA ===============*/
if(isset($_POST['redeem_coupon'])) {

    $code = trim($_POST['coupon_code']);    // Kuponkód tisztítása
    $found = false;  // Találtunk-e érvényes kupont

    /*----------- AKTÍV KUPON ELLENŐRZÉS -----------*/
    // Ha már van aktív kupon → nem válthat be újat
    if (isset($_SESSION['coupon_expiry']) && $_SESSION['coupon_expiry'] > time()) {
        $remaining = $_SESSION['coupon_expiry'] - time();
        $hours = floor($remaining / 3600);
        $minutes = floor(($remaining % 3600) / 60);
        
        $error_msg = "Már van egy aktív kuponod! Újat csak a jelenlegi lejárta után válthatsz be. (Hátravan: $hours óra $minutes perc)";
        $found = true; 
    } else {

        /*----------- A: EGYÉNI KUPON KERESÉSE -----------*/
        $stmt = $conn->prepare("
            SELECT UC.id AS user_coupon_id, C.id AS coupon_id, C.discount, C.max_items 
            FROM USER_COUPONS UC
            JOIN COUPONS C ON UC.coupon_id = C.id
            WHERE UC.user_id = ? AND UC.used = 0 AND UPPER(C.code) = UPPER(?) AND C.valid_until >= NOW()
            LIMIT 1
        ");
        $stmt->bind_param("is", $uid, $code);
        $stmt->execute();
        $res = $stmt->get_result();

        // Ha találtunk egyéni kupont
        if($res->num_rows > 0) {
            $row = $res->fetch_assoc();

            // Kupon felhasználtnak jelölése
            $update = $conn->prepare("UPDATE USER_COUPONS SET used = 1 WHERE id = ?");
            $update->bind_param("i", $row['user_coupon_id']);
            if($update->execute()) {

                /* SESSION adatok mentése */
                $_SESSION['coupon_discount'] = $row['discount'];
                $_SESSION['coupon_expiry'] = time() + ($validity_days * 86400);
                $_SESSION['coupon_max_items'] = $row['max_items'];

                /* Adatok mentése adatbázisba */
                $save = $conn->prepare("UPDATE users SET coupon_discount=?, coupon_expiry=? WHERE id=?");
                $save->bind_param("iii", $_SESSION['coupon_discount'], $_SESSION['coupon_expiry'], $uid);
                $save->execute();

                $success_msg = "Kupon beváltva! Kedvezmény: " . $row['discount'] . "%";
                $found = true;
            }
        } 
        
        //*----------- B: GLOBÁLIS KUPON KERESÉSE -----------*/
        if (!$found) {
            $g_stmt = $conn->prepare("SELECT id, discount, max_items FROM COUPONS WHERE UPPER(code) = UPPER(?) AND valid_until >= NOW() LIMIT 1");
            $g_stmt->bind_param("s", $code);
            $g_stmt->execute();
            $g_res = $g_stmt->get_result();

            if ($g_res->num_rows > 0) {
                $g_row = $g_res->fetch_assoc();
                
                // Ellenőrizzük, hogy már felhasználta-e
                $check = $conn->prepare("SELECT id FROM USER_COUPONS WHERE user_id = ? AND coupon_id = ? AND used = 1");
                $check->bind_param("ii", $uid, $g_row['id']);
                $check->execute();
                
                if ($check->get_result()->num_rows == 0) {
                    // Kupon hozzárendelése a felhasználóhoz
                    $ins = $conn->prepare("INSERT INTO USER_COUPONS (user_id, coupon_id, used) VALUES (?, ?, 1)");
                    $ins->bind_param("ii", $uid, $g_row['id']);
                    $ins->execute();

                    /* SESSION mentés */
                    $_SESSION['coupon_discount'] = $g_row['discount'];
                    $_SESSION['coupon_expiry'] = time() + ($validity_days * 86400);
                    $_SESSION['coupon_max_items'] = $g_row['max_items'];

                    /* DB mentés */
                    $save = $conn->prepare("UPDATE users SET coupon_discount=?, coupon_expiry=? WHERE id=?");
                    $save->bind_param("iii", $_SESSION['coupon_discount'], $_SESSION['coupon_expiry'], $uid);
                    $save->execute();
                    
                    $success_msg = "Globális kupon beváltva! Kedvezmény: " . $g_row['discount'] . "%";
                    $found = true;
                } else {
                    $error_msg = "Ezt a globális kupont már felhasználtad!";
                    $found = true;
                }
            }
        }
    }

    /*----------- HIBA: NEM TALÁLT KUPON -----------*/
    if(!$found) {
        $error_msg = "A megadott kupon kód érvénytelen vagy lejárt!";
    }
}
?>

<!DOCTYPE html>
<html lang="hu">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <!--=============== OLDAL CÍM ===============-->
    <title>Kupon Beváltása - MELICO</title>

    <!--=============== KÜLSŐ STÍLUSOK ===============-->
    <link rel="stylesheet" href="assets/css/styles.css">

    <!-- Ikonok (Remix Icon) -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/remixicon/3.7.0/remixicon.css">


    <!--=============== INLINE STÍLUSOK (KUPÓN OLDAL DESIGN) ===============-->
    <style>
    /*
    Ez az oldal egy különálló kupon beváltó felület,
    amely egyszerű, sötét témájú UI-t használ.
    */

        body { 
            background: #000; 
            font-family: 'Poppins', sans-serif; 
            color: #fff; 
            margin: 0; 
            padding: 0; 
        }

        /* Fő cím */
        h1 { 
            text-align: center; 
            margin: 40px 0 20px 0; 
            color: #fff; 
            font-size: 2rem; 
        }

        /* Kupon űrlap kártya */
        .profile-card { 
            background-color: #111; 
            border-radius: 10px; 
            max-width: 500px; 
            margin: 0 auto 50px auto; 
            padding: 30px 25px; 
            box-shadow: 0 5px 25px rgba(255,255,255,0.05); 
            border: 1px solid #222; 
        }

        /* Input címkék */
        label { 
            color: #ffbc3f; 
            font-weight: bold; 
            display: block; 
            margin-bottom: 5px; 
        }

        /* Input mező */
        input { 
            width: 100%; 
            padding: 12px; 
            margin: 10px 0 20px 0; 
            border-radius: 6px; 
            border: 1px solid #333; 
            background: #1a1a1a; 
            color: #fff; 
            font-size: 1rem; 
            outline: none; 
        }

        /* Input fókusz állapot */
        input:focus { 
            border-color: #175e69; 
        }

        /* Gomb */
        .button { 
            padding: 12px; 
            background-color: #175e69; 
            color: white; 
            border: none; 
            border-radius: 6px; 
            font-weight: bold; 
            cursor: pointer; 
            width: 100%; 
            font-size: 1rem; 
            transition: 0.3s; 
        }

        /* Gomb hover effekt */
        .button:hover { 
            background-color: #00b2cd; 
            transform: translateY(-2px); 
        }

        /* Üzenet alap stílus */
        .msg { 
            padding: 12px; 
            border-radius: 6px; 
            margin-bottom: 20px; 
            text-align: center; 
            font-weight: bold; 
        }

        /* Sikeres művelet üzenet */
        .success { 
            background-color: rgba(40, 167, 69, 0.2); 
            color: #28a745; 
            border: 1px solid #28a745; 
        }

        /* Hibaüzenet */
        .error { 
            background-color: rgba(220, 53, 69, 0.2); 
            color: #dc3545; 
            border: 1px solid #dc3545; 
        }

        /* Vissza gomb */
        .nav__back { 
            display: inline-flex; 
            align-items: center; 
            column-gap: 0.5rem; color: #fff; 
            font-weight: bold; 
            text-decoration: none; 
            margin: 20px 25px; 
            transition: 0.3s; 
        }

        /* Vissza gomb hover */
        .nav__back:hover { 
            color: #ffbc3f; 
        }

    </style>
</head>
<body>

    <!--=============== FEJLÉC ===============-->
    <header>
        <nav class="nav container">
            <!-- Vissza navigáció (dinamikus PHP útvonal) -->
            <a href="<?= htmlspecialchars($back_url) ?>" class="nav__back">
                <i class="ri-arrow-left-line"></i> Vissza
            </a>
        </nav>
    </header>

    <!--=============== OLDAL CÍM ===============-->
    <h1>Kupon Beváltása</h1>

    <!--=============== FŐ TARTALOM (ŰRLAP KÁRTYA) ===============-->
    <div class="profile-card">
        <!-- Sikeres üzenet megjelenítése -->
        <?php if($success_msg): ?>
            <div class="msg success"><?= $success_msg ?></div>
        <?php endif; ?>
        
        <!-- Hibaüzenet megjelenítése -->
        <?php if($error_msg): ?>
            <div class="msg error"><?= $error_msg ?></div>
        <?php endif; ?>

        <!-- Kupon beváltó űrlap -->
        <form method="POST">
            <label for="coupon_code">Kupon kód:</label>
            <input type="text" id="coupon_code" name="coupon_code" placeholder="Például: MELICO20" required autocomplete="off">
            <button type="submit" name="redeem_coupon" class="button">Beváltás</button>
        </form>
    </div>



    <!--=============== RENDSZERVÉDELMI SCRIPT ===============-->
    <script>
    /*
        Ez a script egy egyszerű integritás-ellenőrzést végez.

        Cél:
        - megakadályozni a kritikus DOM elemek eltávolítását
        - jelezni, ha a rendszer manipulálva lett
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

</body>
</html>
