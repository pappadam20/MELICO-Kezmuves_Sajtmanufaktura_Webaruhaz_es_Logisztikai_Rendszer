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


-- TÁBLA: NOTIFICATIONS; Leírás: Rendszerüzenetek a felhasználóknak.
CREATE TABLE IF NOT EXISTS NOTIFICATIONS (
    id INT PRIMARY KEY AUTO_INCREMENT,  -- Értesítés egyedi azonosítója
    user_id INT NOT NULL,               -- Címzett felhasználó
    message TEXT NOT NULL,              -- Az üzenet szövege
    is_read TINYINT DEFAULT 0,          -- Olvasottság állapota (0: olvasatlan, 1: olvasott)
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, -- Értesítés kiküldésének ideje
    -- Kapcsolat: Ha törlik a felhasználót, az értesítései is törlődnek (CASCADE)
    FOREIGN KEY (user_id) REFERENCES USERS(id) ON DELETE CASCADE
);


-- TÁBLA: USER_COUPONS; Leírás: Nyilvántartja, melyik felhasználó melyik kupont használta fel.
CREATE TABLE IF NOT EXISTS USER_COUPONS (
    id INT PRIMARY KEY AUTO_INCREMENT,  -- Bejegyzés azonosítója
    user_id INT NOT NULL,               -- Felhasználó azonosítója
    coupon_id INT NOT NULL,             -- Megszerzett kupon azonosítója
    assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,    -- Mikor kapta meg a felhasználó
    used TINYINT DEFAULT 0,                             -- Felhasználta-e már? (0: nem, 1: igen)
    -- Kapcsolatok kényszerített törléssel (CASCADE):
    FOREIGN KEY (user_id) REFERENCES USERS(id) ON DELETE CASCADE,
    FOREIGN KEY (coupon_id) REFERENCES COUPONS(id) ON DELETE CASCADE
);


-- TÁBLA: SETTINGS; Leírás: Globális rendszerbeállítások (pl. hűségprogram küszöb).
CREATE TABLE IF NOT EXISTS SETTINGS (
    id INT PRIMARY KEY AUTO_INCREMENT,      -- Beállítás azonosítója
    coupon_percent INT DEFAULT 10,          -- Automatikus kuponok alapértelmezett százaléka (10%)
    loyalty_threshold INT DEFAULT 49999,    -- Vásárlási limit a hűségpontok/kuponok aktiválásához (49.999 Ft)
    max_discounted_items INT DEFAULT 1,     -- Alapértelmezett termékszám limit a kedvezményekhez (1 db)
    max_usage_limit INT DEFAULT 1,          -- Alapértelmezett felhasználási korlát (1 alkalom)
    coupon_validity_days INT DEFAULT 7      -- Hány napig érvényes egy generált kupon (7 nap)
);

-- Kezdeti érték beszúrása
INSERT INTO SETTINGS (coupon_percent, loyalty_threshold, max_discounted_items, max_usage_limit, coupon_validity_days)
SELECT 10, 49999, 1, 1, 7 -- LOGIKAI KORLÁT: Csak akkor hajtódik végre a beszúrás, ha a tábla még üres.
WHERE NOT EXISTS (SELECT 1 FROM SETTINGS); -- Ez megakadályozza, hogy minden futtatáskor újabb és újabb alapbeállítások jöjjenek létre.

-- Teszt felhasználók (Admin, Futár, Vásárló)
INSERT INTO USERS (id, name, profile_name, location, email, password, role) VALUES
(1, 'Admin Péter', 'Admin', 'Budapest 1055 Kossuth Lajos tér 1.', 'admin@melico.hu', '$2y$10$A2N0tcUCU3.pfTIWr0sLuOIu/zL.wRYPb.fncs7RlT7FsiVkTgJr.', '2'),
(2, 'Futár Károly', 'Futár', 'Debrecen 4024 Piac utca 10.', 'futar@melico.hu', '$2y$10$lvbfmLgUOTTWJYSHbO7dC.FDIahhT7u8.B1tcu3nP0KspDz.jFh2C', '1'),
(3, 'Vásárló Zita', 'Zita', 'Szeged 6720 Dóm tér 12.', 'vasarlo@melico.hu', '$2y$10$/ZHQwARpNhgl2UDfL45LnuqvMhfhwFwBU9FwkraYiYfcEKRr3IhWq', '0');

-- Kategóriák betöltése
INSERT INTO CATEGORIES (id, name, description) VALUES
(1, 'Lágy és Friss Sajtok', 'Rövid érlelésű, magas nedvességtartalmú sajtok.'),
(2, 'Félkemény Sajtok', 'Közepes ideig érlelt, jól szeletelhető sajtok.'),
(3, 'Kemény Sajtok', 'Hosszú érlelésű, alacsony nedvességtartalmú, reszelhető vagy törhető sajtok.');

-- Beszállítók betöltése
INSERT INTO SUPPLIERS (id, name, contact, email, phone, description) VALUES
(1, 'Tiszántúli Sajtműhely', 'Kovács Elemér', 'kovacs.elemer@tisza.hu', '+36 30 123 4567', 'Klasszikus, hagyományos receptúrákra építő családi vállalkozás.'),
(2, 'Bakonyi Kézműves Gazdaság', 'Nagy Anna', 'nagy.anna@bakony.hu', '+36 20 987 6543', 'Kecskesajtokra specializálódott gazdaság, természetes takarmányozással.'),
(3, 'Pannon Sajtkerék', 'Tóth Balázs', 'toth.balazs@pannon.hu', '+36 70 555 1212', 'Erős, karakteres, kékpenészes és érlelt sajtok mestere.'),
(4, 'Dél-Alföldi Érlelő', 'Kiss Katalin', 'kiss.katalin@dalfold.hu', '+36 30 222 3344', 'Hosszú érlelési idejű, kemény sajtokra koncentrál, olasz inspirációkkal.'),
(5, 'Szekszárdi Borvidék Sajt', 'Varga Gábor', 'varga.gabor@szekszard.hu', '+36 20 111 2233', 'Különleges, borral és párlattal mosott kérgű sajtok.'),
(6, 'Erdélyi Manufaktúra', 'Popescu Elena', 'elena.popescu@erdely.ro', '+40 74 123 0000', 'Hagyományos erdélyi receptek alapján készült, friss savósajtok.'),
(7, 'Chili Suli', 'Fodor Bence', 'fodor.bence@chili.hu', '+36 30 777 8899', 'Fűszeres, különleges sajtok gyártója, prémium chili felhasználásával.'),
(8, 'Zalai Tejtermék', 'Molnár Péter', 'molnar.peter@zalatej.hu', '+36 20 444 5566', 'Friss, puha sajtok specialistája (mozzarella, krémsajt).'),
(9, 'Kisalföldi Gazdaság', 'Szabó Virág', 'szabo.virag@kfold.hu', '+36 70 999 0011', 'Holland típusú sajtokat gyártó modern üzem.');

-- Termékek feltöltése képhivatkozásokkal
INSERT INTO PRODUCTS (id, category_id, supplier_id, name, description, price, image, stock) VALUES
(1, 1, 1, 'Camembert de Normandie AOP', NULL, 8900, 'Camembert de Normandie AOP.png', 85),
(2, 1, 2, 'Chevre Frais', NULL, 9900, 'Chevre Frais.png', 60),
(3, 1, 3, 'Gorgonzola Dolce DOP', NULL, 11900, 'Gorgonzola Dolce DOP.png', 45),
(4, 1, 2, 'Ricotta', NULL, 6900, 'Ricotta.png', 95),
(5, 2, 1, 'Trappista', NULL, 4900, 'Trappista.png', 70),
(6, 2, 3, 'Gouda Holland', NULL, 6900, 'Gouda Holland.png', 120),
(7, 3, 4, 'Parmigiano Reggiano DOP', NULL, 18900, 'Parmigiano Reggiano DOP.png', 30),
(8, 3, 4, 'Grana Padano DOP', NULL, 14900, 'Grana Padano DOP.png', 25),
(9, 2, 5, 'Edami', NULL, 5900, 'Edami.png', 40),
(10, 1, 6, 'Mozzarella di Bufala Campana DOP', NULL, 10900, 'Mozzarella di Bufala Campana DOP.png', 110),
(11, 3, 7, 'Pecorino Romano DOP', NULL, 16900, 'Pecorino Romano DOP.png', 50),
(12, 1, 8, 'Mascarpone', NULL, 4900, 'Mascarpone.png', 150),
(13, 2, 9, 'Maasdam', NULL, 6900, 'Maasdam.png', 65),
(14, 1, 2, 'Brie de Meaux AOP', NULL, 12900, 'Brie de Meaux AOP.png', 80),
(15, 1, 3, 'Burrata', NULL, 9900, 'Burrata.png', 35),
(16, 3, 7, 'Comté AOP', NULL, 17900, 'Comté AOP.png', 20);
