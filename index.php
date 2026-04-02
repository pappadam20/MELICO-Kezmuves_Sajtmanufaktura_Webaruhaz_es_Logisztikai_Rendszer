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
