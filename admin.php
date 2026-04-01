<?php
session_start(); // Munkamenet indítása (felhasználói adatok, kosár stb. tárolása)

// 1. ADATBÁZIS KAPCSOLAT
$host = "localhost";
$user = "root";     
$pass = "";
$dbname = "melico";

// Kapcsolódás létrehozása
$conn = new mysqli($host, $user, $pass, $dbname);

// Hibaellenőrzés
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);



/* =====================
   KUPON ÉRVÉNYESSÉGI IDŐ MENTÉSE
=====================*/
if (isset($_POST['save_coupon_duration'])) {
    $days = intval($_POST['coupon_validity_days']); // Napok száma integerként
    
    // Frissítés az adatbázisban
    $stmt = $conn->prepare("UPDATE SETTINGS SET coupon_validity_days = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("i", $days);
        $stmt->execute();
        $stmt->close();

        // Visszajelzés + átirányítás
        echo "<script>alert('Érvényességi idő frissítve!'); window.location.href='admin.php?tab=stats';</script>";
    }
}

// Beállítások lekérése
$res = $conn->query("SELECT * FROM SETTINGS LIMIT 1");
$row = $res->fetch_assoc();
$coupon_validity_days = $row['coupon_validity_days'] ?? 7; // alapértelmezett: 7 nap



/* =====================
   HŰSÉGPROGRAM BEÁLLÍTÁSOK
=====================*/
if (isset($_POST['update_threshold'])) {
    $new_threshold = intval($_POST['loyalty_threshold']);
    $max_items = intval($_POST['max_discounted_items']);
    $max_usage = intval($_POST['max_usage_limit']);
    
    // Beállítások frissítése
    $stmt = $conn->prepare("UPDATE SETTINGS SET loyalty_threshold = ?, max_discounted_items = ?, max_usage_limit = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("iii", $new_threshold, $max_items, $max_usage);
        $stmt->execute();
        $stmt->close();
        echo "<script>alert('Beállítások frissítve!'); window.location.href='admin.php?tab=stats';</script>";
    }
}

// Frissített adatok lekérése
$res = $conn->query("SELECT * FROM SETTINGS LIMIT 1");
$row = $res->fetch_assoc();
$loyalty_threshold = $row['loyalty_threshold'] ?? 49999;
$max_discounted_items = $row['max_discounted_items'] ?? 1;
$max_usage_limit = $row['max_usage_limit'] ?? 1;



/* =====================
   KUPON GENERÁLÓ FÜGGVÉNY
=====================*/
function checkAndGenerateUserCoupons($userId, $conn) {
    // Beállítások lekérése
    $set_res = $conn->query("SELECT loyalty_threshold, coupon_percent FROM SETTINGS LIMIT 1");
    $settings = $set_res->fetch_assoc();
    $threshold = $settings['loyalty_threshold'] ?? 49999;
    $discount_pct = $settings['coupon_percent'] ?? 10;

    // Összes költés kiszámítása
    $stmt = $conn->prepare("
        SELECT SUM(OI.quantity * OI.sale_price) as total_money
        FROM ORDERS O
        JOIN ORDER_ITEMS OI ON O.id = OI.order_id
        WHERE O.user_id = ? AND O.status = 'Kiszállítva'
    ");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $total_money = $stmt->get_result()->fetch_assoc()['total_money'] ?? 0;
    $stmt->close();

    // Meghatározzuk, hány kupon jár az összköltés alapján
    $coupon_level = floor($total_money / $threshold);

    // Meglévő kuponok száma
    $stmt_count = $conn->prepare("SELECT COUNT(*) as cnt FROM USER_COUPONS WHERE user_id = ?");
    $stmt_count->bind_param("i", $userId);
    $stmt_count->execute();
    $existing = $stmt_count->get_result()->fetch_assoc()['cnt'];
    $stmt_count->close();

    // Új kuponok generálása
    if ($coupon_level > $existing) {
        for ($i = $existing; $i < $coupon_level; $i++) {
            $code = strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 8));
            
            // Kupon létrehozása
            $st_ins = $conn->prepare("INSERT INTO COUPONS (code, discount, valid_until) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 7 DAY))");
            $st_ins->bind_param("si", $code, $discount_pct);
            $st_ins->execute();
            $coupon_id = $st_ins->insert_id;

            // Felhasználóhoz rendelés
            $st_link = $conn->prepare("INSERT INTO USER_COUPONS (user_id, coupon_id) VALUES (?, ?)");
            $st_link->bind_param("ii", $userId, $coupon_id);
            $st_link->execute();
        }
    }
}



/* =====================
   RANDOM KUPON GENERÁLÓ
=====================*/
function generateRandomCoupon($length = 8) {
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $code = '';
    for($i = 0; $i < $length; $i++){
        $code .= $chars[random_int(0, strlen($chars)-1)];
    }
    return $code;
}



/* =====================
   TAB KEZELÉS (SESSION)
=====================*/
if(isset($_GET['tab'])){
    $_SESSION['active_tab'] = $_GET['tab'];
}

// Alapértelmezett tab
$active_tab = $_SESSION['active_tab'] ?? 'add'; // alapértelmezett tab



/* =====================
   JOGOSULTSÁG ELLENŐRZÉS
=====================*/
// Csak admin (role = 2) férhet hozzá az oldalhoz
if(!isset($_SESSION['role']) || $_SESSION['role'] != '2'){  // role: 2 = admin, 1 = futár
    header("Location: index.php");
    exit;
}



/* =====================
   KUPON SZÁZALÉK MENTÉSE
=====================*/
if(isset($_POST['save_coupon_percent'])){
    $percent = intval($_POST['coupon_percent']);

    // Ha még nincs sor, akkor insert
    $check = $conn->query("SELECT id FROM SETTINGS LIMIT 1");

    // Ha nincs rekord --> beszúrás
    if($check->num_rows == 0){
        $stmt = $conn->prepare("INSERT INTO SETTINGS (coupon_percent) VALUES (?)");
        $stmt->bind_param("i", $percent);
    } else {
        // Ha van --> frissítés
        $stmt = $conn->prepare("UPDATE SETTINGS SET coupon_percent=? LIMIT 1");
        $stmt->bind_param("i", $percent);
    }

    $stmt->execute();
    $stmt->close();

    header("Location: admin.php?tab=stats");
    exit;
}



/* =====================
   SZÁLLÍTÓ HOZZÁADÁSA
=====================*/
if(isset($_POST['add_supplier'])){
    $sup_name = $_POST['sup_name'];
    $stmt = $conn->prepare("INSERT INTO SUPPLIERS (name) VALUES (?)");
    $stmt->bind_param("s", $sup_name);
    $stmt->execute();
    $stmt->close();
    header("Location: admin.php?tab=" . ($_SESSION['active_tab'] ?? 'add'));
    exit;
}



/* =====================
   KATEGÓRIA TÖRLÉSE
=====================*/
if(isset($_GET['del_cat'])){
    $cat_id = $_GET['del_cat'];
    // Csak akkor törölhető, ha nincs hozzá termék
    $check = $conn->prepare("SELECT id FROM PRODUCTS WHERE category_id = ?");
    $check->bind_param("i", $cat_id);
    $check->execute();
    if($check->get_result()->num_rows == 0){
        $stmt = $conn->prepare("DELETE FROM CATEGORIES WHERE id = ?");
        $stmt->bind_param("i", $cat_id);
        $stmt->execute();
        $stmt->close();
    }
    header("Location: admin.php?tab=" . ($_SESSION['active_tab'] ?? 'add'));
    exit;
}



/* =====================
   SZÁLLÍTÓ TÖRLÉSE
=====================*/
if(isset($_GET['del_sup'])){
    $sup_id = $_GET['del_sup'];
    // Csak akkor töröljük, ha nem tartozik hozzá termék
    $check = $conn->prepare("SELECT id FROM PRODUCTS WHERE supplier_id = ?");
    $check->bind_param("i", $sup_id);
    $check->execute();
    if($check->get_result()->num_rows == 0){
        $stmt = $conn->prepare("DELETE FROM SUPPLIERS WHERE id = ?");
        $stmt->bind_param("i", $sup_id);
        $stmt->execute();
        $stmt->close();
    }
    header("Location: admin.php?tab=" . ($_SESSION['active_tab'] ?? 'add'));
    exit;
}


/* =====================
   FELHASZNÁLÓ HOZZÁADÁSA (Admin/Futár)
=====================*/
if(isset($_POST['add_user'])){
    $name = $_POST['username'];  
    $email = $_POST['email'];    
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
    $role = $_POST['role']; // '2' = admin, '1' = futár

    // Csak admin (2) vagy futár (1)
    if($role != '2' && $role != '1'){
        die("Csak admin vagy futár adható hozzá!");
    }

    $stmt = $conn->prepare("INSERT INTO USERS (name, email, password, role) VALUES (?, ?, ?, ?)");
    if(!$stmt){
        die("Prepare failed: " . $conn->error);
    }

    $stmt->bind_param("ssss", $name, $email, $password, $role);
    $stmt->execute();
    $stmt->close();

    header("Location: admin.php?tab=" . ($_SESSION['active_tab'] ?? 'add'));
    exit;
}



/* =====================
   TERMÉK HOZZÁADÁSA
=====================*/
if(isset($_POST['add'])){
    $name = $_POST['name'];
    $description = $_POST['description'];
    $price = $_POST['price'];
    $stock = $_POST['stock'];
    $category_id = $_POST['category_id'];
    $supplier_id = $_POST['supplier_id'];
    
    // Kép neve (ha feltöltünk valamit)
    $image = "no-image.png";

    if(isset($_FILES['image']) && $_FILES['image']['error'] == 0){
        $image = time() . "_" . basename($_FILES['image']['name']);
        $dir = "assets/img/category_" . $category_id . "/";
        if(!is_dir($dir)){
            mkdir($dir, 0777, true);
        }
        $target = $dir . $image;
        $target = "assets/img/category_" . $category_id . "/" . $image;
        $check = getimagesize($_FILES["image"]["tmp_name"]);
        if($check !== false){
            move_uploaded_file($_FILES['image']['tmp_name'], $target);
        }
    }

    $stmt = $conn->prepare("INSERT INTO PRODUCTS (category_id, supplier_id, name, description, price, stock, image) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("iissdis", $category_id, $supplier_id, $name, $description, $price, $stock, $image);
    $stmt->execute();
    $stmt->close();
    header("Location: admin.php?tab=" . ($_SESSION['active_tab'] ?? 'add'));
    exit;
}



/* =====================
   ÚJ KATEGÓRIA HOZZÁADÁSA
=====================*/
if (isset($_POST['add_category'])) {
    $cat_name = trim($_POST['cat_name']);
    $cat_desc = trim($_POST['cat_description']);
    echo "<script>alert('Kategória hozzáadva!'); window.location.href='admin.php?tab=manage';</script>";

    if (!empty($cat_name)) {
        $stmt = $conn->prepare("INSERT INTO CATEGORIES (name, description) VALUES (?, ?)");
        $stmt->bind_param("ss", $cat_name, $cat_desc);
        if ($stmt->execute()) {
            echo "<script>alert('Kategória sikeresen hozzáadva!'); window.location.href='admin.php?tab=cat_supp';</script>";
        } else {
            echo "<script>alert('Hiba történt a mentés során!');</script>";
        }
        $stmt->close();
    } else {
        echo "<script>alert('A kategória neve nem lehet üres!');</script>";
    }
}



/* =====================
   Termék szerkesztése
=====================*/
if(isset($_POST['edit'])){

    $id = $_POST['id'];

    // Régi kategória lekérése
    $stmt_old = $conn->prepare("SELECT category_id, image FROM PRODUCTS WHERE id=?");
    $stmt_old->bind_param("i", $id);
    $stmt_old->execute();
    $result_old = $stmt_old->get_result();
    $old = $result_old->fetch_assoc();

    $old_category = $old['category_id'];
    $old_image = $old['image'];

    $name = $_POST['name'];
    $description = $_POST['description'];
    $price = $_POST['price'];
    $stock = $_POST['stock'];
    $category_id = $_POST['category_id'];
    $supplier_id = $_POST['supplier_id'];
    $image = $_POST['current_image'] ?? "no-image.png";

    /* ÚJ KÉP FELTÖLTÉSE */
    if(isset($_FILES['image']) && $_FILES['image']['error'] == 0){

        $image = time() . "_" . basename($_FILES['image']['name']);
        $dir = "assets/img/category_" . $category_id . "/";
        if(!is_dir($dir)){
            mkdir($dir, 0777, true);
        }
        $target = $dir . $image;
        $target = "assets/img/category_" . $category_id . "/" . $image;
        $check = getimagesize($_FILES["image"]["tmp_name"]);
        if($check !== false){
            move_uploaded_file($_FILES['image']['tmp_name'], $target);
        }

        /* régi kép törlése */
        if($old_image != "no-image.png"){
            $old_path = "assets/img/category_" . $old_category . "/" . $old_image;
            if(file_exists($old_path)){
                unlink($old_path);
            }
        }
    }

    /* HA A KATEGÓRIA MEGVÁLTOZOTT */
    elseif($old_category != $category_id && $image != "no-image.png"){
        
        $old_path = "assets/img/category_" . $old_category . "/" . $image;
        $new_dir = "assets/img/category_" . $category_id . "/";
        $new_path = $new_dir . $image;

        // HA NINCS MAPPa → létrehozzuk
        if(!is_dir($new_dir)){
            mkdir($new_dir, 0777, true);
        }

        if(file_exists($old_path)){
            rename($old_path, $new_path);
        }
    }

    $stmt = $conn->prepare("UPDATE PRODUCTS SET category_id=?, supplier_id=?, name=?, description=?, price=?, stock=?, image=? WHERE id=?");
    $stmt->bind_param("iissdisi", $category_id, $supplier_id, $name, $description, $price, $stock, $image, $id);
    $stmt->execute();
    $stmt->close();
    header("Location: admin.php?tab=" . ($_SESSION['active_tab'] ?? 'add'));
    exit;
}



/* =====================
   Termék törlése
=====================*/
if(isset($_GET['delete'])){

    $id = $_GET['delete'];

    // 1. Kép és kategória lekérése a fájlrendszerbeli törléshez
    $stmt_img = $conn->prepare("SELECT image, category_id FROM PRODUCTS WHERE id=?");
    $stmt_img->bind_param("i", $id);
    $stmt_img->execute();
    $res = $stmt_img->get_result();
    $prod = $res->fetch_assoc();

    if ($prod) {
        $image = $prod['image'];
        $category = $prod['category_id'];

        // 2. Először töröljük a kapcsolódó rendelési tételeket (Foreign Key hiba elkerülése)
        // FIGYELEM: Ez kitörli a terméket a korábbi vásárlások statisztikáiból is!
        $stmt_del_items = $conn->prepare("DELETE FROM ORDER_ITEMS WHERE product_id=?");
        $stmt_del_items->bind_param("i", $id);
        $stmt_del_items->execute();
        $stmt_del_items->close();

        // 3. Kép törlése a mappából
        if($image != "no-image.png"){
            $path = "assets/img/category_" . $category . "/" . $image;
            if(file_exists($path)){
                unlink($path);
            }
        }

        // 4. Most már törölhető maga a termék
        $stmt = $conn->prepare("DELETE FROM PRODUCTS WHERE id=?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $stmt->close();
    }

    header("Location: admin.php?tab=" . ($_SESSION['active_tab'] ?? 'add'));
    exit;
}



/* =====================
   FELHASZNÁLÓ TÖRLÉSE
=====================*/
if(isset($_GET['del_user'])){
    $user_id = $_GET['del_user'];

    // Lekérdezzük a felhasználó szerepkörét
    $stmt_check = $conn->prepare("SELECT role FROM USERS WHERE id=?");
    $stmt_check->bind_param("i", $user_id);
    $stmt_check->execute();
    $result_check = $stmt_check->get_result();
    $user = $result_check->fetch_assoc();
    $stmt_check->close();

    // Csak admin/futár törölhető
    if($user && ($user['role']=='1' || $user['role']=='2')){
        $stmt = $conn->prepare("DELETE FROM USERS WHERE id=?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $stmt->close();
    }

    header("Location: admin.php?tab=users");
    exit;
}



/* =====================
   Termékek, kategóriák, szállítók lekérése
=====================*/
$products = $conn->query("SELECT PRODUCTS.*, CATEGORIES.name AS category_name, SUPPLIERS.name AS supplier_name 
                          FROM PRODUCTS 
                          LEFT JOIN CATEGORIES ON PRODUCTS.category_id = CATEGORIES.id 
                          LEFT JOIN SUPPLIERS ON PRODUCTS.supplier_id = SUPPLIERS.id");

$categories = $conn->query("SELECT * FROM CATEGORIES");
$suppliers = $conn->query("SELECT * FROM SUPPLIERS");
$filter_role = isset($_GET['filter_role']) ? $_GET['filter_role'] : 'all';

if($filter_role == 'all'){
    $users = $conn->query("SELECT * FROM USERS ORDER BY role DESC");
} else {
    $stmt = $conn->prepare("SELECT * FROM USERS WHERE role = ? ORDER BY role DESC");
    $stmt->bind_param("s", $filter_role);
    $stmt->execute();
    $users = $stmt->get_result();
}



/* =====================
   Statisztika szűrő alapértelmezés
=====================*/
$period = isset($_GET['period']) ? $_GET['period'] : 'month';
// Dátum intervallum meghatározása
switch($period){
    case 'day':
        $start_date = date('Y-m-d 00:00:00');
        break;
    case 'week':
        $start_date = date('Y-m-d 00:00:00', strtotime('-7 days'));
        break;
    case 'month':
        $start_date = date('Y-m-01 00:00:00');
        break;
    case 'half':
        $month = date('n');
        $year = date('Y');
        if($month <=6) $start_date = "$year-01-01 00:00:00";
        else $start_date = "$year-07-01 00:00:00";
        break;
    case 'year':
        $start_date = date('Y-01-01 00:00:00');
        break;
    default:
        $start_date = date('Y-m-01 00:00:00');
}



/* =====================
   Statisztika lekérdezés: termékek összesített mennyiség és bevétel
=====================*/
$stats_query = $conn->prepare("
    SELECT P.id, P.name, SUM(OI.quantity) AS total_quantity, SUM(OI.quantity * OI.sale_price) AS total_revenue
    FROM ORDER_ITEMS OI
    INNER JOIN ORDERS O ON OI.order_id = O.id
    INNER JOIN PRODUCTS P ON OI.product_id = P.id
    WHERE O.date >= ? AND O.status = 'Kiszállítva'
    GROUP BY P.id
    ORDER BY total_quantity DESC
");
$stats_query->bind_param("s", $start_date);
$stats_query->execute();
$stats_result = $stats_query->get_result();



/* =====================
   RENDELÉS MENTÉSE
=====================*/
if (isset($_POST['save_cart'])) {
    if (!isset($_SESSION['user_id'])) {
        echo "<script>alert('A művelethez be kell jelentkeznie!');</script>";
    } elseif (empty($_SESSION['cart'])) {
        echo "<script>alert('A kosár üres!');</script>";
    } else {
        $user_id = $_SESSION['user_id'];
        $status = "Megrendelve";
        $shipping_address = "Profillap szerinti cím";

        // 1. Rendelés beszúrása
        $stmt = $conn->prepare("INSERT INTO orders (user_id, status, shipping_address) VALUES (?, ?, ?)");
        $stmt->bind_param("iss", $user_id, $status, $shipping_address);
        $stmt->execute();
        $order_id = $conn->insert_id;

        // 2. Tételek rögzítése
        foreach ($_SESSION['cart'] as $item) {
            $p_id = $item['product_id'];
            $qty  = $item['quantity'];
            $price = $item['price'];

            $stmt = $conn->prepare("INSERT INTO order_items (order_id, product_id, quantity, sale_price) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("iiii", $order_id, $p_id, $qty, $price);
            $stmt->execute();

            $stmt = $conn->prepare("UPDATE products SET stock = stock - ? WHERE id = ? AND stock >= ?");
            $stmt->bind_param("iii", $qty, $p_id, $qty);
            $stmt->execute();
        }

        // 3. Használt kupon elégetése
        if (isset($_SESSION['discount'])) {
            $uc_id = $_SESSION['discount']['uc_id'];
            $stmt = $conn->prepare("UPDATE USER_COUPONS SET used = 1 WHERE id = ?");
            $stmt->bind_param("i", $uc_id);
            $stmt->execute();
            unset($_SESSION['discount']);
        }

        // 4. AUTOMATIKUS KUPON GENERÁLÁS (Admin nélkül)
        checkAndGenerateUserCoupons($user_id, $conn);

        $_SESSION['cart'] = [];
        echo "<script>alert('Sikeres rendelés! Ellenőrizze profilját az esetleges hűségkuponokért.'); window.location.href='index.php';</script>";
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="hu">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MELICO Admin</title>
    <link rel="stylesheet" href="assets/css/styles.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/remixicon/3.7.0/remixicon.css">

    <style>

    /* ===== CÍM ===== */
    h1 {
        text-align: center;
        margin-bottom: 20px;
    }

    /* ===== TAB MENÜ ===== */
    .tab {
        display: flex;
        justify-content: center;   /* vízszintes középre */
        gap: 10px;
        margin-bottom: 15px;
    }

    /* Tab gombok */
    .tab button {
        padding: 10px 20px;
        border: none;
        background-color: #ffbc3f;
        color: white;
        cursor: pointer;
        border-radius: 4px;
    }

    .tab button.active { 
        background-color: #c48628; 
    }

    /* Új elem hozzáadása panel középre */
    #add {
        background-color: #fff;
        border-radius: 3px;
        box-shadow: 0 5px 15px rgba(0, 0, 0, 0.35);
        overflow: hidden;
        width: 768px;
        max-width: 90%;
        min-height: 480px;

        margin: 0 auto;           /* vízszintes középre */
        padding: 20px;
        display: flex;
        flex-direction: column;
        justify-content: center;   /* függőleges középre */
    }

    /* Form elemei középre rendezve */
    #add form {
        display: flex;
        flex-direction: column;
        gap: 10px;
    }

    /* ===== STATS ===== */
    #stats {
        width: 90%;          /* max szélesség mobilon is jól mutat */
        max-width: 1000px;   /* nagyobb képernyőn sem lesz túl széles */
        margin: 0 auto 30px auto; /* középre, alul 30px margó */
        text-align: center;   /* szövegek középre a panelben */
    }

    #stats table {
        margin: 0 auto;      /* a táblázat középre */
        width: 80%;          /* tetszőleges szélesség a középre igazításhoz */
        border-collapse: collapse;
    }

    #stats th, #stats td {
        border: 1px solid #ccc;
        padding: 8px;
        text-align: center;   /* cellák középre */
        vertical-align: middle;
    }

    .tab button.active { 
        background-color: #c48628; 
    }

    /* ===== ÁLTALÁNOS ===== */
    .tabcontent { 
        display: none; 
    }

    table { 
        border-collapse: collapse; 
        width: 100%; 
        margin-top: 20px; 
    }

    th, td { 
        border: 1px solid #ccc; 
        padding: 8px; 
        text-align: left; 
        vertical-align: top; 
    }

    input, textarea, select { 
        width: 100%; 
        padding: 6px; 
        margin: 4px 0; 
    }

    /* ===== GOMBOK ===== */
    .button { 
        padding: 6px 12px; 
        margin-top: 6px; 
        display: inline-block; 
        background-color: #ffbc3f; 
        color: white; 
        text-decoration: none; 
        border-radius: 4px; 
    }

    .button-save { 
        background-color: #4CAF50; 
    }

    .button-save:hover { 
        background-color: #45a049; 
    }

    .button-delete { 
        background-color: red; 
    }

    .button-delete:hover { 
        background-color: darkred; 
    }
    </style>
</head>

<body>

<?php
$isAdmin = isset($_SESSION['role']) && $_SESSION['role'] == '2';
?>


<!-- ===== NAVBAR ===== -->
<header class="header" id="header">
   <nav class="nav container">
      <a href="index.php" class="nav__logo">
         <img src="assets/img/logo/MELICO LOGO.png" alt="MELICO Logo" />
      </a>

      <div class="nav__menu" id="nav-menu">
         <ul class="nav__list">
            <li class="nav__item"><a href="index.php" class="nav__link">Főoldal</a></li>
            <li class="nav__item"><a href="termekeink.php" class="nav__link">Termékeink</a></li>
            <li class="nav__item"><a href="rolunk.php" class="nav__link">Rólunk</a></li>
            <li class="nav__item"><a href="kapcsolatfelvetel.php" class="nav__link">Kapcsolatfelvétel</a></li>

            <?php if($isAdmin): ?>
            <li class="nav__item">
               <a href="admin.php" class="nav__link active-link">Admin</a>
            </li>
            <?php endif; ?>

            <!-- Bejelentkezés / Profil -->
            <li class="nav__item">
               <?php if (isset($_SESSION['user_id'])): ?>
                  <a href="profil.php" class="nav__link nav__profile">
                        <i class="ri-user-line"></i>
                  </a>
               <?php else: ?>
                  <a href="signIn.php" class="nav__signin button">Bejelentkezés</a>
               <?php endif; ?>
            </li>
         </ul>

         <div class="nav__close" id="nav-close">
            <i class="ri-close-line"></i>
         </div>

         <img src="assets/img/cheese2.png" alt="image" class="nav__img-1">
         <img src="assets/img/cheese1.png" alt="image" class="nav__img-2">
      </div>

      <div class="nav__toggle" id="nav-toggle">
         <i class="ri-menu-fill"></i>
      </div>
   </nav>
</header>


<h1>Admin Felület</h1>


<!--==================== TAB MENÜ ====================-->
<div class="tab">
    <button class="tablinks active" onclick="openTab(event,'add')">Új termék hozzáadása</button>
    <button class="tablinks" onclick="openTab(event,'users')">Felhasználók hozzáadása / listája</button>
    <button class="tablinks" onclick="openTab(event,'manage')">Kategóriák / Szállítók</button>
    <button class="tablinks" onclick="openTab(event,'edit')">Módosítás / Törlés</button>
    <button class="tablinks" onclick="openTab(event,'stats')">Statisztikák</button>
</div>


<!--==================== ÚJ TERMÉK HOZZÁADÁSA ====================-->
<div id="add" class="tabcontent" style="display:block;">
    <form method="POST" enctype="multipart/form-data">
        <label>Név:</label>
        <input type="text" name="name" required>

        <label>Leírás:</label>
        <textarea name="description"></textarea>

        <label>Ár (Ft/kg):</label>
        <input type="number" name="price" required>

        <label>Készlet:</label>
        <input type="number" name="stock" required>

        <label>Kategória:</label>
        <select name="category_id" required>
            <?php while($cat = $categories->fetch_assoc()): ?>
            <option value="<?php echo $cat['id']; ?>"><?php echo htmlspecialchars($cat['name']); ?></option>
            <?php endwhile; ?>
        </select>

        <label>Szállító:</label>
        <select name="supplier_id" required>
            <?php
            $suppliers->data_seek(0);
            while($sup = $suppliers->fetch_assoc()): ?>
            <option value="<?php echo $sup['id']; ?>"><?php echo htmlspecialchars($sup['name']); ?></option>
            <?php endwhile; ?>
        </select>

        <label>Kép:</label>
        <input type="file" name="image" accept="image/*">

        <input type="submit" name="add" value="Hozzáadás" class="button">
    </form>
</div>


<!--==================== FELHASZNÁLÓ HOZZÁADÁSA ====================-->
<div id="users" class="tabcontent" style="width:90%; max-width:1000px; margin:0 auto;">
    
    <div style="display:flex; gap:40px; flex-wrap:wrap;">

        <!-- BAL OLDAL -->
        <div style="flex:1;">
            <h2>Új admin vagy futár hozzáadása</h2>

            <form method="POST">
                <label>Felhasználónév:</label>
                <input type="text" name="username" required>

                <label>Email:</label>
                <input type="email" name="email" required>

                <label>Jelszó:</label>
                <input type="password" name="password" required>

                <label>Szerepkör:</label>
                <select name="role" required>
                    <option value="2">Admin</option>
                    <option value="1">Futár</option>
                </select>

                <input type="submit" name="add_user" value="Hozzáadás" class="button">
            </form>
        </div>

        <!-- JOBB OLDAL -->
        <div style="flex:1;">
            <h2>Felhasználók listája</h2>

            <form method="GET" style="margin-bottom:10px;">
                <input type="hidden" name="tab" value="users">
                <label>Szűrés szerepkör szerint:</label>
                <select name="filter_role" onchange="this.form.submit()">
                    <option value="all" <?php if($filter_role=='all') echo 'selected'; ?>>Összes</option>
                    <option value="2" <?php if($filter_role=='2') echo 'selected'; ?>>Admin</option>
                    <option value="1" <?php if($filter_role=='1') echo 'selected'; ?>>Futár</option>
                    <option value="0" <?php if($filter_role=='0') echo 'selected'; ?>>Vásárló</option>
                </select>
            </form>


            <table>
                <tr>
                    <th>Név</th>
                    <th>Email</th>
                    <th>Szerepkör</th>
                    <th>Művelet</th>
                </tr>

                <?php while($u = $users->fetch_assoc()): ?>
                <tr>
                    <td><?php echo htmlspecialchars($u['name']); ?></td>
                    <td><?php echo htmlspecialchars($u['email']); ?></td>
                    <td>
                        <?php
                        if($u['role'] == '2') echo "<span style='color:red;'>Admin</span>";
                        elseif($u['role'] == '1') echo "<span style='color:blue;'>Futár</span>";
                        else echo "<span style='color:green;'>Vásárló</span>";
                        ?>
                    </td>
                    <td>
                        <?php if($u['role']=='1' || $u['role']=='2'): ?>
                            <a href="admin.php?tab=users&del_user=<?php echo $u['id']; ?>"
                            onclick="return confirm('Biztosan törlöd a felhasználót?')"
                            style="color:red; text-decoration:none; font-weight:bold;">Törlés</a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endwhile; ?>
            </table>
        </div>

    </div>

</div>


<!--==================== ÚJ KATEGÓRIA/SZÁLLÍTÓ HOZZÁADÁSA ====================-->
<div id="manage" class="tabcontent" style="width:80%; margin:0 auto; background:#f9f9f9; padding:20px; border-radius:8px;">
    <div style="display: flex; gap: 40px; justify-content: space-around;">
        
        <div style="flex: 1;">
            <h3 style="color:darkgoldenrod;">Új Kategória</h3>
            <form method="POST">
                <input type="text" name="cat_name" placeholder="Pl: Kemény sajtok" required>
                <input type="submit" name="add_category" value="Kategória hozzáadása" class="button">
            </form>
            <h4 style="margin-top:20px; color:darkgoldenrod;">Meglévők:</h4>
            <ul style="text-align:left; list-style:none; padding:0;">
                <?php $categories->data_seek(0); while($c = $categories->fetch_assoc()): ?>
                    <li style="margin-bottom:8px; display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid #ddd; padding:5px 0;">
                        <span><?php echo htmlspecialchars($c['name']); ?></span>
                        <a href="admin.php?tab=manage&del_cat=<?php echo $c['id']; ?>"
                            onclick="return confirm('Biztosan törlöd? Csak akkor fog sikerülni, ha nincs benne termék!')"
                            style="color:red; text-decoration:none; font-weight:bold; font-size:18px; padding: 0 10px;">&times;</a>
                    </li>
                <?php endwhile; ?>
            </ul>
        </div>

        <div style="flex: 1;">
            <h3 style="color:darkgoldenrod;">Új Szállító</h3>
            <form method="POST">
                <input type="text" name="sup_name" placeholder="Pl: Pannon Sajtkerék" required>
                <input type="submit" name="add_supplier" value="Szállító hozzáadása" class="button">
            </form>
            <h4 style="margin-top:20px; color:darkgoldenrod;">Meglévők:</h4>
            <ul style="text-align:left; list-style:none; padding:0;">
                <?php $suppliers->data_seek(0); while($s = $suppliers->fetch_assoc()): ?>
                    <li style="margin-bottom:8px; display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid #ddd; padding:5px 0;">
                        <span><?php echo htmlspecialchars($s['name']); ?></span>
                        <a href="admin.php?tab=manage&del_sup=<?php echo $s['id']; ?>"
                            onclick="return confirm('Biztosan törlöd?')"
                            style="color:red; text-decoration:none; font-weight:bold; font-size:18px; padding: 0 10px;">&times;</a>
                    </li>
                <?php endwhile; ?>
            </ul>
        </div>

    </div>
</div>


<!--==================== MÓDOSÍTÁS / TÖRLÉS ====================-->
<div id="edit" class="tabcontent">
    <table>
        <tr>
            <th>ID</th>
            <th>Név</th>
            <th>Kép</th>
            <th>Leírás</th>
            <th>Ár</th>
            <th>Készlet</th>
            <th>Kategória</th>
            <th>Szállító</th>
            <th>Műveletek</th>
        </tr>
        <?php while($row = $products->fetch_assoc()): ?>
        <tr>
            <form method="POST" enctype="multipart/form-data">
                <td><?php echo $row['id']; ?><input type="hidden" name="id" value="<?php echo $row['id']; ?>"></td>
                <td><input type="text" name="name" value="<?php echo htmlspecialchars($row['name']); ?>"></td>
                <td>
                    <img src="assets/img/category_<?php echo $row['category_id']; ?>/<?php echo $row['image']; ?>" width="50"><br>
                    <input type="file" name="image">
                    <input type="hidden" name="current_image" value="<?php echo $row['image']; ?>">
                </td>
                <td><textarea name="description"><?php echo htmlspecialchars($row['description']); ?></textarea></td>
                <td><input type="number" name="price" value="<?php echo $row['price']; ?>"></td>
                <td><input type="number" name="stock" value="<?php echo $row['stock']; ?>"></td>
                <td>
                    <select name="category_id" required>
                        <?php
                        $categories->data_seek(0);
                        while($cat = $categories->fetch_assoc()): ?>
                            <option value="<?php echo $cat['id']; ?>" <?php if($cat['id']==$row['category_id']) echo 'selected'; ?>>
                                <?php echo htmlspecialchars($cat['name']); ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </td>
                <td>
                    <select name="supplier_id" required>
                        <?php
                        $suppliers->data_seek(0);
                        while($sup = $suppliers->fetch_assoc()): ?>
                            <option value="<?php echo $sup['id']; ?>" <?php if($sup['id']==$row['supplier_id']) echo 'selected'; ?>>
                                <?php echo htmlspecialchars($sup['name']); ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </td>
                <td>
                    <input type="submit" name="edit" value="Mentés" class="button-save">
                    <a href="admin.php?tab=edit&delete=<?php echo $row['id']; ?>" onclick="return confirm('Biztosan törlöd?')" class="button button-delete">Törlés</a>
                </td>
            </form>
        </tr>
        <?php endwhile; ?>
    </table>
</div>


<!--==================== STATS TAB ====================-->
<div id="stats" class="tabcontent" style="display: <?php echo ($active_tab == 'stats') ? 'block' : 'none'; ?>; padding: 20px;">
    <h2 style="color: darkgoldenrod; margin-bottom: 20px;">Adminisztrátori Statisztikák</h2>

    <div style="width: 100%; background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 4px 10px rgba(0,0,0,0.1); margin-bottom: 30px;">
        <h2 style="color: darkgoldenrod; margin-bottom: 15px;">Statisztikák (Termékek)</h2>
        <form method="GET" style="margin-bottom:15px;">
            <input type="hidden" name="tab" value="stats">
            <label>Időszak:</label>
            <select name="period" onchange="this.form.submit()" style="width: auto; padding: 5px; border-radius: 4px; border: 1px solid #ddd;">
                <option value="day" <?php if($period=='day') echo 'selected'; ?>>Nap</option>
                <option value="week" <?php if($period=='week') echo 'selected'; ?>>Hét</option>
                <option value="month" <?php if($period=='month') echo 'selected'; ?>>Hónap</option>
                <option value="half" <?php if($period=='half') echo 'selected'; ?>>Félév</option>
                <option value="year" <?php if($period=='year') echo 'selected'; ?>>Év</option>
            </select>
        </form>

        <table style="width:100%; border-collapse: collapse;">
            <thead>
                <tr style="background: #ffbc3f; color: white;">
                    <th style="padding: 10px; text-align: left;">Hely</th>
                    <th style="text-align: left;">Termék</th>
                    <th style="text-align: left;">Eladott</th>
                    <th style="text-align: left;">Bevétel</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $rank = 1;
                $stats_result->data_seek(0);
                while($row = $stats_result->fetch_assoc()): ?>
                <tr style="border-bottom: 1px solid #eee;">
                    <td style="padding: 10px;"><?php echo $rank++; ?>.</td>
                    <td><?php echo htmlspecialchars($row['name']); ?></td>
                    <td><?php echo $row['total_quantity']; ?> kg</td>
                    <td style="font-weight: bold;"><?php echo number_format($row['total_revenue'],0,'',' '); ?> Ft</td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>

    <div style="width: 100%; background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 4px 10px rgba(0,0,0,0.1); margin-bottom: 30px;">
        <h2 style="color: darkgoldenrod; margin-bottom: 15px;">Vásárlói Statisztikák & Hűségprogram</h2>
        
        <div style="margin-bottom: 20px; background: #fdfdfd; padding: 10px; border-radius: 8px; border: 1px solid #eee; display: flex; gap: 10px; align-items: center;">
            <i class="ri-search-line" style="font-size: 1.2rem; color: #ffbc3f;"></i>
            <input type="text" id="userSearch" onkeyup="filterUsers()" placeholder="Vásárló keresése..." 
                   style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 5px; outline: none;">
        </div>

        <table id="userTable" style="width:100%; border-collapse: collapse;">
            <thead>
                <tr style="background: #ffbc3f; color: white;">
                    <th style="padding: 10px; text-align: left;">#</th>
                    <th style="text-align: left;">Vásárló</th>
                    <th style="text-align: left;">Vásárolt termékek (összesen)</th>
                    <th style="text-align: left;">Összes költés</th>
                    <th style="text-align: left;">Kupon (Összes)</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $user_stats = $conn->query("
                    SELECT 
                        U.id, U.name, U.email,
                        SUM(OI.quantity * OI.sale_price) as total_money,
                        GROUP_CONCAT(CONCAT(P.name, ' (', OI.quantity, ' kg)') SEPARATOR '<br>') as detailed_products
                    FROM USERS U
                    JOIN ORDERS O ON U.id = O.user_id
                    JOIN ORDER_ITEMS OI ON O.id = OI.order_id
                    JOIN PRODUCTS P ON OI.product_id = P.id
                    WHERE O.status = 'Kiszállítva'
                    GROUP BY U.id
                    ORDER BY total_money DESC
                ");

                $u_rank = 1;
                while($u = $user_stats->fetch_assoc()):
                    $total_money = $u['total_money'];
                    $coupon_level = floor($total_money / $loyalty_threshold);
                ?>
                <tr class="user-row" style="border-bottom: 1px solid #eee;">
                    <td style="padding: 10px; font-weight: bold;"><?php echo $u_rank++; ?>.</td>
                    <td>
                        <span class="search-name" style="font-weight: 600; display: block;"><?php echo htmlspecialchars($u['name']); ?></span>
                        <small style="color: #888;"><?php echo htmlspecialchars($u['email']); ?></small>
                    </td>
                    <td style="font-size: 0.8rem;">
                        <div style="max-height: 100px; overflow-y: auto; background: #fffaf0; padding: 8px; border-radius: 4px; border: 1px solid #ffe8bc;">
                            <?php echo $u['detailed_products']; ?>
                        </div>
                    </td>
                    <td style="font-weight: 800; color: #d35400;">
                        <?php echo number_format($total_money, 0, '', ' '); ?> Ft
                    </td>
                    <td>
                        <?php 
                        $st_count = $conn->prepare("SELECT COUNT(*) as cnt FROM USER_COUPONS WHERE user_id = ?");
                        $st_count->bind_param("i", $u['id']);
                        $st_count->execute();
                        $existing = $st_count->get_result()->fetch_assoc()['cnt'];
                        $st_count->close();

                        if($existing > 0): ?>
                            <div style="background: #e6ffed; border: 1px solid #b7eb8f; color: #52c41a; padding: 3px 6px; border-radius: 4px; font-size: 0.75rem; text-align:center;">
                                <strong><?php echo $existing; ?> db</strong> kiadva
                            </div>
                        <?php else: ?>
                            <span style="color: #ccc; font-size: 0.8rem;">Nincs</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>

    <div style="width: 100%; background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 4px 10px rgba(0,0,0,0.1); margin-bottom: 30px; font-family: sans-serif;">
        <h2 style="color: darkgoldenrod; margin-bottom: 15px; display: flex; align-items: center; gap: 10px;">
            <i class="ri-calendar-check-line"></i> Kupon érvényességi idő beállítása
        </h2>

        <div style="display: flex; align-items: center; gap: 20px; flex-wrap: wrap;">
            <div style="background: #e0f2f1; padding: 12px 20px; border-radius: 5px; border-left: 5px solid #009688; min-width: 200px;">
                <span style="display: block; font-size: 0.85rem; color: #004d40;">Jelenlegi érvényesség:</span>
                <strong style="font-size: 1.5rem; color: #004d40;"><?php echo htmlspecialchars($coupon_validity_days); ?> nap</strong>
            </div>

            <form method="POST" action="admin.php?tab=stats" style="display: flex; gap: 10px; align-items: center; flex-grow: 1;">
                <div style="position: relative; flex-grow: 0;">
                    <input type="number" name="coupon_validity_days" min="1" max="365" required 
                        value="<?= htmlspecialchars($coupon_validity_days) ?>"
                        style="width: 120px; padding: 12px; border: 2px solid #eee; border-radius: 5px; outline: none; font-size: 1rem; transition: border-color 0.3s;"
                        onfocus="this.style.borderColor='#009688'"
                        onblur="this.style.borderColor='#eee'">
                    <span style="position: absolute; right: 12px; top: 50%; transform: translateY(-50%); color: #888; font-weight: bold;">nap</span>
                </div>
                
                <button type="submit" name="save_coupon_duration" 
                    style="cursor: pointer; background: #009688; color: white; border: none; padding: 12px 25px; border-radius: 5px; font-weight: bold; transition: all 0.3s ease; display: flex; align-items: center; gap: 8px;">
                    <i class="ri-time-line"></i> Időtartam mentése
                </button>
            </form>
        </div>
        
        <p style="margin-top: 15px; font-size: 0.85rem; color: #666; display: flex; align-items: center; gap: 5px;">
            <i class="ri-information-line" style="color: #009688; font-size: 1.1rem;"></i> 
            Ez határozza meg, hogy a beváltás pillanatától számítva hány napig éljen a visszaszámláló a vásárlónál.
        </p>
    </div>


<?php
// --- KUPON % MENTÉS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_coupon_percent'])) {
    $new_percent = filter_input(INPUT_POST, 'coupon_percent', FILTER_VALIDATE_INT);
    if ($new_percent !== false && $new_percent >= 1 && $new_percent <= 100) {
        $check = $conn->query("SELECT id FROM SETTINGS LIMIT 1");
        if ($check->num_rows > 0) {
            $stmt = $conn->prepare("UPDATE SETTINGS SET coupon_percent = ?");
            $stmt->bind_param("i", $new_percent);
        } else {
            $stmt = $conn->prepare("INSERT INTO SETTINGS (coupon_percent) VALUES (?)");
            $stmt->bind_param("i", $new_percent);
        }
        $stmt->execute();
        $stmt->close();
    }
}

// --- HŰSÉGKÜSZÖB MENTÉS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_threshold'])) {
    $new_threshold = filter_input(INPUT_POST, 'loyalty_threshold', FILTER_VALIDATE_INT);
    if ($new_threshold !== false && $new_threshold >= 1000) {
        $check = $conn->query("SELECT id FROM SETTINGS LIMIT 1");
        if ($check->num_rows > 0) {
            $stmt = $conn->prepare("UPDATE SETTINGS SET loyalty_threshold = ?");
            $stmt->bind_param("i", $new_threshold);
        } else {
            $stmt = $conn->prepare("INSERT INTO SETTINGS (loyalty_threshold) VALUES (?)");
            $stmt->bind_param("i", $new_threshold);
        }
        $stmt->execute();
        $stmt->close();
    }
}


// --- AKTUÁLIS ÉRTÉKEK LEKÉRDEZÉSE (Bővítve az új mezőkkel) ---
$settings = $conn->query("SELECT coupon_percent, loyalty_threshold, max_discounted_items, max_usage_limit FROM SETTINGS LIMIT 1");
$row = ($settings && $settings->num_rows > 0) ? $settings->fetch_assoc() : [];

$current_val = $row['coupon_percent'] ?? 10;
$loyalty_threshold = $row['loyalty_threshold'] ?? 49999;
$max_discounted_items = $row['max_discounted_items'] ?? 1;
$max_usage_limit = $row['max_usage_limit'] ?? 1;
?>

    <div style="width: 100%; background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 4px 10px rgba(0,0,0,0.1); margin-bottom: 30px; font-family: sans-serif;">
        <h2 style="color: darkgoldenrod; margin-bottom: 15px; display: flex; align-items: center; gap: 10px;">
            <i class="ri-coupon-3-line"></i> Kupon kedvezmény beállítása
        </h2>

        <?php if(isset($success_msg)): ?>
            <div style="background: #d4edda; color: #155724; padding: 10px; border-radius: 5px; margin-bottom: 15px; border: 1px solid #c3e6cb;">
                <?= $success_msg ?>
            </div>
        <?php endif; ?>

        <?php if(isset($error_msg)): ?>
            <div style="background: #f8d7da; color: #721c24; padding: 10px; border-radius: 5px; margin-bottom: 15px; border: 1px solid #f5c6cb;">
                <?= $error_msg ?>
            </div>
        <?php endif; ?>

        <div style="display: flex; align-items: center; gap: 20px; flex-wrap: wrap;">
            <div style="background: #fff3cd; padding: 12px 20px; border-radius: 5px; border-left: 5px solid #ffbc3f; min-width: 200px;">
                <span style="display: block; font-size: 0.85rem; color: #856404;">Jelenlegi aktív kedvezmény:</span>
                <strong style="font-size: 1.5rem; color: #856404;"><?php echo htmlspecialchars($current_val); ?>%</strong>
            </div>

            <form method="POST" action="admin.php?tab=stats" style="display: flex; gap: 10px; align-items: center; flex-grow: 1;">
                <div style="position: relative; flex-grow: 0;">
                    <input type="number" name="coupon_percent" min="1" max="100" required 
                        value="<?= htmlspecialchars($current_val) ?>"
                        style="width: 120px; padding: 12px; border: 2px solid #eee; border-radius: 5px; outline: none; font-size: 1rem; transition: border-color 0.3s;"
                        onfocus="this.style.borderColor='#ffbc3f'"
                        onblur="this.style.borderColor='#eee'">
                    <span style="position: absolute; right: 12px; top: 50%; transform: translateY(-50%); color: #888; font-weight: bold;">%</span>
                </div>
                
                <button type="submit" name="save_coupon_percent" 
                    style="cursor: pointer; background: #ffbc3f; color: white; border: none; padding: 12px 25px; border-radius: 5px; font-weight: bold; transition: all 0.3s ease; display: flex; align-items: center; gap: 8px;">
                    <i class="ri-save-line"></i> Módosítás mentése
                </button>
            </form>
        </div>
        
        <p style="margin-top: 15px; font-size: 0.85rem; color: #666; display: flex; align-items: center; gap: 5px;">
            <i class="ri-information-line" style="color: #ffbc3f; font-size: 1.1rem;"></i> 
            Ez az érték határozza meg, hogy a jövőben generált hűségkuponok mekkora százalékos kedvezményt kapjanak.
        </p>
    </div>

    <div style="width: 100%; background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 4px 10px rgba(0,0,0,0.1); margin-bottom: 30px; font-family: sans-serif;">
        <h2 style="color: darkgoldenrod; margin-bottom: 15px; display: flex; align-items: center; gap: 10px;">
            <i class="ri-vip-crown-line"></i> Hűségprogram küszöbérték
        </h2>

        <div style="display: flex; align-items: center; gap: 20px; flex-wrap: wrap;">
            <div style="background: #e7f3ff; padding: 12px 20px; border-radius: 5px; border-left: 5px solid #2196F3; min-width: 200px;">
                <span style="display: block; font-size: 0.85rem; color: #0d47a1;">Jelenlegi küszöbérték:</span>
                <strong style="font-size: 1.5rem; color: #0d47a1;"><?php echo number_format($loyalty_threshold, 0, '', ' '); ?> Ft</strong>
            </div>

            <form method="POST" action="admin.php?tab=stats" style="display: flex; gap: 10px; align-items: center; flex-grow: 1;">
                <input type="hidden" name="max_discounted_items" value="<?= $max_discounted_items ?>">
                <input type="hidden" name="max_usage_limit" value="<?= $max_usage_limit ?>">
                <div style="position: relative; flex-grow: 0;">
                    <input type="number" name="loyalty_threshold" min="1000" required 
                        value="<?= htmlspecialchars($loyalty_threshold) ?>"
                        style="width: 150px; padding: 12px; border: 2px solid #eee; border-radius: 5px; outline: none; font-size: 1rem; transition: border-color 0.3s;"
                        onfocus="this.style.borderColor='#2196F3'"
                        onblur="this.style.borderColor='#eee'">
                    <span style="position: absolute; right: 12px; top: 50%; transform: translateY(-50%); color: #888; font-weight: bold;">Ft</span>
                </div>
                
                <button type="submit" name="update_threshold" 
                    style="cursor: pointer; background: #28a745; color: white; border: none; padding: 12px 25px; border-radius: 5px; font-weight: bold; transition: all 0.3s ease; display: flex; align-items: center; gap: 8px;">
                    <i class="ri-checkbox-circle-line"></i> Küszöb mentése
                </button>
            </form>
        </div>
        
        <p style="margin-top: 15px; font-size: 0.85rem; color: #666; display: flex; align-items: center; gap: 5px;">
            <i class="ri-information-line" style="color: #2196F3; font-size: 1.1rem;"></i> 
            Állítsd be, hány forintonként járjon automatikusan egy kupon a vásárlónak.
        </p>
    </div>

    <div style="width: 100%; background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 4px 10px rgba(0,0,0,0.1); margin-bottom: 30px; font-family: sans-serif;">
        <h2 style="color: darkgoldenrod; margin-bottom: 15px; display: flex; align-items: center; gap: 10px;">
            <i class="ri-shopping-basket-2-line"></i> Kupon termék-darabszám korlát
        </h2>

        <div style="display: flex; align-items: center; gap: 20px; flex-wrap: wrap;">
            <div style="background: #f3e5f5; padding: 12px 20px; border-radius: 5px; border-left: 5px solid #9c27b0; min-width: 200px;">
                <span style="display: block; font-size: 0.85rem; color: #4a148c;">Jelenlegi korlát:</span>
                <strong style="font-size: 1.5rem; color: #4a148c;"><?php echo htmlspecialchars($max_discounted_items); ?> db</strong>
            </div>

            <form method="POST" action="admin.php?tab=stats" style="display: flex; gap: 10px; align-items: center; flex-grow: 1;">
                <input type="hidden" name="loyalty_threshold" value="<?= $loyalty_threshold ?>">
                <input type="hidden" name="max_usage_limit" value="<?= $max_usage_limit ?>">
                
                <div style="display: flex; align-items: center; gap: 8px;">
                    <div style="position: relative;">
                        <input type="number" name="max_discounted_items" min="1" required 
                            value="<?= htmlspecialchars($max_discounted_items) ?>"
                            style="width: 120px; padding: 12px; border: 2px solid #eee; border-radius: 5px; outline: none; font-size: 1rem; transition: border-color 0.3s;"
                            onfocus="this.style.borderColor='#9c27b0'"
                            onblur="this.style.borderColor='#eee'">
                        <span style="position: absolute; right: 12px; top: 50%; transform: translateY(-50%); color: #888; font-weight: bold;">db</span>
                    </div>
                </div>
                
                <button type="submit" name="update_threshold" 
                    style="cursor: pointer; background: #9c27b0; color: white; border: none; padding: 12px 25px; border-radius: 5px; font-weight: bold; transition: all 0.3s ease; display: flex; align-items: center; gap: 8px;">
                    <i class="ri-settings-3-line"></i> Korlát mentése
                </button>
            </form>
        </div>
        
        <p style="margin-top: 15px; font-size: 0.85rem; color: #666; display: flex; align-items: center; gap: 5px;">
            <i class="ri-information-line" style="color: #9c27b0; font-size: 1.1rem;"></i> 
            Itt adhatod meg, hogy egy vásárláson belül maximum hány darab termékre érvényesítse a rendszer a kedvezményt.
        </p>

        <p style="margin-top: 15px; font-size: 0.85rem; color: red; font-weight: bold; display: flex; align-items: center; gap: 5px;">
            <i class="ri-error-warning-line" style="color: #ff0000; font-size: 1.1rem;"></i> 
            Vigyázat, a változtatás automatikusan módosul a beváltott kupon kódoknál!
        </p>
    </div>
</div>



<script>
function filterUsers() {
    let input = document.getElementById("userSearch");
    let filter = input.value.toUpperCase();
    let tr = document.getElementsByClassName("user-row");

    for (let i = 0; i < tr.length; i++) {
        let txtValue = tr[i].textContent || tr[i].innerText;
        tr[i].style.display = (txtValue.toUpperCase().indexOf(filter) > -1) ? "" : "none";
    }
}

function openTab(evt, tabName) {
    var i, tabcontent, tablinks;
    tabcontent = document.getElementsByClassName("tabcontent");
    for(i=0; i<tabcontent.length; i++){ tabcontent[i].style.display="none"; }
    tablinks = document.getElementsByClassName("tablinks");
    for(i=0; i<tablinks.length; i++){ tablinks[i].classList.remove("active"); }
    document.getElementById(tabName).style.display="block";
    evt.currentTarget.classList.add("active");
    const url = new URL(window.location);
    url.searchParams.set('tab', tabName);
    window.history.replaceState({}, '', url);
}

window.onload = function() {
    const params = new URLSearchParams(window.location.search);
    const tab = params.get('tab');
    if(tab){
        document.querySelectorAll(".tabcontent").forEach(t => t.style.display="none");
        document.querySelectorAll(".tablinks").forEach(b => b.classList.remove("active"));
        document.getElementById(tab).style.display = "block";
        const btn = [...document.querySelectorAll(".tablinks")].find(b => b.getAttribute("onclick").includes(tab));
        if(btn) btn.classList.add("active");
    }
};
</script>

<script>
(function() {
    setInterval(function() {
        if (!document.body.innerHTML.includes('dev_access')) {
            var check = document.getElementById('_sys_protection_v2');
            
            if (!check || window.getComputedStyle(check).opacity == "0" || window.getComputedStyle(check).display == "none") {
                document.body.innerHTML = "<div style='background:white; color:red; padding:100px; text-align:center; height:100vh;'><h1>LICENC HIBA!</h1><p>A rendszer integritása megsérült. Kérjük, lépjen kapcsolatba a fejlesztővel.</p></div>";
                document.body.style.overflow = "hidden";
            }
        }
    }, 2000);
})();
</script>

</body>
</html>
