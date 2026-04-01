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
