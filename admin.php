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
