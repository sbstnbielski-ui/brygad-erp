# BRYGAD ERP

System ERP dla firm budowlanych — projekty, finanse, kadry, rozliczenia.

**Stack:** PHP 8 · MySQL/MariaDB · PDO

## Moduły

- **Projekty** — etapy kosztów, budżety, raporty
- **Finanse** — faktury, koszty, zaliczki, alokacje, integracja API fakturowania
- **HR** — ewidencja czasu, stawki, portfele pracownicze, rozliczenia
- **Dokumenty** — upload i OCR faktur (opcjonalnie)

## Uruchomienie

```bash
cp public/config/database.example.php public/config/database.php
# uzupełnij DB_HOST, DB_NAME, DB_USER, DB_PASS

mysql -u root -p -e "CREATE DATABASE brygad_erp CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -p brygad_erp < database/schema.sql
mysql -u root -p brygad_erp < database/demo-seed.sql

cd public && php -S localhost:8080
```

**Demo:** `admin` / `demo1234`

## Struktura

```
public/     → document root aplikacji
database/   → schema.sql + demo-seed.sql
```

Wersja portfolio — bez danych produkcyjnych i credentials.
