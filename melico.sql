-- MELICO adatbázis létrehozása, magyar ékezetes támogatással
CREATE DATABASE IF NOT EXISTS melico CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci;
USE melico;

-- TÁBLA: USERS; Feladat: Felhasználói fiókok és jogosultságok kezelése
CREATE TABLE IF NOT EXISTS USERS (
    id INT PRIMARY KEY AUTO_INCREMENT,      -- Egyedi azonosító
    name VARCHAR(100) NOT NULL,             -- Bejelentkezési név
    profile_name VARCHAR(100),              -- Megjelenített név a profilon
    location VARCHAR(255),                  -- Szállítási cím/Lakhely
    email VARCHAR(100) NOT NULL,            -- Kapcsolattartási email
    password VARCHAR(255) NOT NULL,         -- Titkosított jelszó (Hash)
    role ENUM('0','1','2') NOT NULL,         -- Jogosultsági szintek: 0=Vásárló, 1=Futár, 2=Admin
    coupon_discount INT DEFAULT NULL,   -- Aktuális egyedi kupon mértéke (%)
    coupon_expiry INT DEFAULT NULL      -- Kupon lejárati ideje (időbélyeg vagy nap)
);


-- TÁBLA: CATEGORIES; Leírás: Termékcsoportosítás (pl. Lágy sajtok, Kemény sajtok).
CREATE TABLE IF NOT EXISTS CATEGORIES (
    id INT PRIMARY KEY AUTO_INCREMENT,  -- Kategória egyedi azonosítója
    name VARCHAR(50),   -- Kategória neve
    description TEXT    -- Kategória rövid leírása
);


-- TÁBLA: SUPPLIERS; Leírás: A beszállító partnerek és manufaktúrák adatai.
CREATE TABLE IF NOT EXISTS SUPPLIERS (
    id INT PRIMARY KEY AUTO_INCREMENT,  -- Beszállító egyedi azonosítója
    name VARCHAR(100),              -- A cég vagy manufaktúra neve
    contact VARCHAR(100),           -- Kapcsolattartó személy neve
    email VARCHAR(100) NOT NULL,    --Beszállító központi email címe
    phone VARCHAR(20),              -- Beszállító telefonszáma
    description TEXT    -- Bemutatkozó szöveg
);


-- TÁBLA: PRODUCTS; Leírás: Az elérhető termékek listája, árral és készletinfóval
CREATE TABLE IF NOT EXISTS PRODUCTS (
    id INT PRIMARY KEY AUTO_INCREMENT,  -- Termék egyedi azonosítója
    category_id INT,    -- Kapcsolat a kategóriákhoz
    supplier_id INT,    -- Kapcsolat a beszállítóhoz
    name VARCHAR(100),  -- Termék neve (pl. Camembert)
    description TEXT,   -- Termékleírás, összetevők
    price INT,          -- Jelenlegi eladási ár (HUF)
    image VARCHAR(255), -- A termékről készült kép fájlneve vagy URL-je
    stock INT,  -- Aktuális raktárkészlet
    -- Kapcsolatok definiálása (Idegen kulcsok):
    FOREIGN KEY (category_id) REFERENCES CATEGORIES(id), -- Idegen kulcs a kategóriához
    FOREIGN KEY (supplier_id) REFERENCES SUPPLIERS(id)   -- Idegen kulcs a szállítóhoz
);


-- TÁBLA: ORDERS; Leírás: Leadott rendelések és azok állapota, ETA adatokkal kiegészítve.
CREATE TABLE IF NOT EXISTS ORDERS (
    id INT PRIMARY KEY AUTO_INCREMENT,          -- Rendelés egyedi azonosító száma
    user_id INT,                                -- A rendelést leadó vásárló azonosítója
    date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,   -- A rendelés leadásának pontos ideje
    status ENUM('Megrendelve','Szállítás alatt','Kiszállítva','Lemondva') NOT NULL, -- Aktuális státusz
    shipping_address VARCHAR(255),  -- Pontos szállítási cím a rendelés idején
    -- Google Maps API alapú szállítási becslések:
    eta_seconds INT DEFAULT NULL,         -- Várható út időtartama másodpercekben
    eta_text VARCHAR(100) DEFAULT NULL,    -- Olvasható érkezési idő
    eta_arrival DATETIME DEFAULT NULL,     -- Várható pontos érkezés dátuma
    -- Kapcsolat a felhasználóval:
    FOREIGN KEY (user_id) REFERENCES USERS(id)
);


-- TÁBLA: ORDER_ITEMS; Leírás: Kapcsolótábla a rendelések és termékek között (tételek).
CREATE TABLE IF NOT EXISTS ORDER_ITEMS (
    id INT PRIMARY KEY AUTO_INCREMENT,      -- Tétel egyedi azonosítója
    order_id INT,                           -- Melyik rendeléshez tartozik?
    product_id INT,                         -- Melyik terméket vették meg?
    quantity INT,                           -- Vásárolt darabszám
    sale_price INT,                         -- Rögzített ár (vásárláskori ár, nem változik ha később drágul a termék)
    -- Kapcsolatok a rendeléssel és a termékkel:
    FOREIGN KEY (order_id) REFERENCES ORDERS(id),
    FOREIGN KEY (product_id) REFERENCES PRODUCTS(id)
);


-- TÁBLA: REVIEWS; Leírás: Felhasználói értékelések (1-5 csillag).
CREATE TABLE IF NOT EXISTS REVIEWS (
    id INT PRIMARY KEY AUTO_INCREMENT,  -- Értékelés egyedi azonosítója
    user_id INT,                        -- Ki írta az értékelést
    product_id INT,                        -- Melyik terméket értékelték
    stars ENUM('1','2','3','4','5'),       -- Pontszám 1-től 5-ig
    -- Kapcsolatok:
    FOREIGN KEY (user_id) REFERENCES USERS(id),
    FOREIGN KEY (product_id) REFERENCES PRODUCTS(id)
);


-- TÁBLA: COUPONS; Leírás: Kedvezménykódok kezelése korlátozásokkal.
CREATE TABLE IF NOT EXISTS COUPONS (
    id INT PRIMARY KEY AUTO_INCREMENT,  -- Kupon belső azonosítója
    code VARCHAR(20) NOT NULL UNIQUE,   -- A kuponkód
    discount INT NOT NULL DEFAULT 10,   -- Kedvezmény mértéke százalékban
    valid_until DATETIME,               -- A kupon érvényességének vége
    max_items INT DEFAULT 1,         -- Maximum hány termékre érvényesíthető a kosárban
    usage_limit INT DEFAULT 1        -- Összesen hányszor használható fel a rendszerben
);
