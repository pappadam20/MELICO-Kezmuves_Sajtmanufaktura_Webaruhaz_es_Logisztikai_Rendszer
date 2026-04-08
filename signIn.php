<?php
session_start();    // Session indítása (felhasználói adatok tárolásához)
include "db.php";   // Külső adatbázis kapcsolat (ha használod)

//=============== ADATBÁZIS KAPCSOLAT ===============
$host = "localhost";
$user = "root";
$pass = "";
$db   = "melico";

// Kapcsolódás MySQL adatbázishoz
$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) {
    die("Database error");  // Hiba esetén leáll
}

//=============== REGISZTRÁCIÓ ===============
if (isset($_POST['register'])) {

    // Űrlap adatok beolvasása
    $name  = $_POST['name'];
    $email = $_POST['email'];
    // Jelszó biztonságos hash-elése
    $passw = password_hash($_POST['password'], PASSWORD_DEFAULT);

    // Felhasználó mentése adatbázisba (prepared statement – SQL injection védelem)
    $stmt = $conn->prepare("INSERT INTO USERS (name, email, password) VALUES (?, ?, ?)");
    $stmt->bind_param("sss", $name, $email, $passw);
    $stmt->execute();
    $stmt->close();

    // Sikeres regisztráció visszajelzés
    echo "<script>alert('Sikeres regisztráció!');</script>";
}

//=============== BEJELENTKEZÉS ===============
if (isset($_POST['login'])) {
    $email = $_POST['email'];
    $passw = $_POST['password'];

    // Felhasználó lekérdezése email alapján
    $stmt = $conn->prepare("SELECT id, password, role, coupon_discount, coupon_expiry FROM USERS WHERE email = ?");
    if (!$stmt) {
        die("SQL hiba: " . $conn->error);   // Hibakezelés
    }
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $stmt->store_result();

    // Ha létezik ilyen felhasználó
    if ($stmt->num_rows > 0) {

        $stmt->bind_result($id, $hashed, $role, $coupon_discount, $coupon_expiry);
        $stmt->fetch();

        // Jelszó ellenőrzése
        if (password_verify($passw, $hashed)) {

            // Session adatok mentése
            $_SESSION['user_id'] = $id;
            $_SESSION['role'] = $role;

            //=============== KUPON KEZELÉS ===============
            // Csak akkor töltjük vissza, ha még érvényes
            if (!empty($coupon_expiry) && $coupon_expiry > time()) {
                $_SESSION['coupon_discount'] = $coupon_discount;
                $_SESSION['coupon_expiry'] = $coupon_expiry;
            }

            //=============== ÁTIRÁNYÍTÁS SZEREPKÖR ALAPJÁN ===============
            if ($role == '2') {
                header("Location: admin.php");  // Admin felület
            } elseif ($role == '1') {
                header("Location: futar.php");  // Futár felület
            } else {
                header("Location: index.php");  // Vásárlói felület
            }
            exit;

        } else {
            // Hibás jelszó
            $error = "Hibás email vagy jelszó!";
        }

    } else {
        // Nincs ilyen email
        $error = "Hibás email vagy jelszó!";
    }

    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="hu">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <!--=============== FAVICON ===============-->
    <link rel="shortcut icon" href="assets/img/logo/MELICO LOGO 2.png">

    <!--=============== REMIXICONS ===============-->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">

    <title>Bejelentkezés</title>

    <style>

        /* Űrlap alap elrendezése: elemek egymás alatt, középre igazítva */
        form{
            display:flex;
            flex-direction:column;
            align-items:center;
        }

        /* Gomb elhelyezése az űrlapon belül */
        form button{
            margin-top:10px;
        }

        /* VISSZA GOMB – linkként működő gomb stílus */
        .back-btn{
            display:block;          /* blokk szintű elem a könnyebb pozicionálásért */
            margin-top:10px;        /* távolság a felette lévő elemtől */
            text-align:center;      /* szöveg középre igazítása */
            text-decoration:none;   /* alapértelmezett aláhúzás eltávolítása */
            color:#ac5c00;          /* fő szín (brand színhez igazítva) */
            font-weight:600;        /* félkövér kiemelés */
        }

        /* Hover effekt a jobb felhasználói élményért */
        .back-btn:hover{
            text-decoration:underline;  /* aláhúzás megjelenítése hover esetén */
        }

    </style>

    <link rel="stylesheet" href="assets/css/SignIn.css">

</head>
<body>

    <div class="container" id="container">

        <!-- ========== REGISZTRÁCIÓS ŰRLAP ========== -->
        <div class="form-container sign-up">
        <form method="POST">

            <h1>Regisztráció</h1>

            <!-- Felhasználó neve -->
            <input type="text" name="name" placeholder="Név" required>

            <!-- Email cím -->
            <input type="email" name="email" placeholder="Email" required>

            <!-- Jelszó -->
            <input type="password" name="password" placeholder="Jelszó" required>

            <!-- Regisztráció gomb -->
            <button type="submit" name="register">Regisztráció</button>

            <!-- Visszalépés a főoldalra -->
            <a href="index.php" class="back-btn">Vissza a főoldalra</a>

        </form>
    </div>


    <!-- ========== BEJELENTKEZÉSI ŰRLAP ========== -->
    <div class="form-container sign-in">
    <form method="POST">

        <h1>Bejelentkezés</h1>

        <!-- Hibakezelés megjelenítése -->
        <?php if (!empty($error)) : ?>
            <p style="color:red; margin-bottom:10px;"><?= htmlspecialchars($error) ?></p>
        <?php endif; ?>


        <!-- ========== Bejelentkezési adatok ========== -->

        <!-- Email mező -->
        <input type="email" name="email" placeholder="Email" required>

        <!-- Jelszó mező -->
        <input type="password" name="password" placeholder="Jelszó" required>

        <!-- Bejelentkezés gomb -->
        <button type="submit" name="login">Bejelentkezés</button>

        <!-- Visszalépés a főoldalra -->
        <a href="index.php" class="back-btn">Vissza a főoldalra</a>

    </form>
    </div>


    <!-- ========== VÁLTÓ PANEL (LOGIN / REGISTER ANIMÁCIÓ) ========== -->
    <div class="toggle-container">
        <div class="toggle">

            <!-- Bal oldal: visszaváltás bejelentkezésre -->
            <div class="toggle-panel toggle-left">
                <h1>Üdvözöljük Újra!</h1>
                <button class="hidden" id="login">Bejelentkezés</button>
            </div>

            <!-- Jobb oldal: váltás regisztrációra -->
            <div class="toggle-panel toggle-right">
                <h1>Üdvözlünk!</h1>
                <button class="hidden" id="register">Regisztráció</button>
            </div>

        </div>
    </div>

    </div>
