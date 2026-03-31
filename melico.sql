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
