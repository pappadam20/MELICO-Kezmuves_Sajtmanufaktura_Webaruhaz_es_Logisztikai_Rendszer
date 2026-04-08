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
