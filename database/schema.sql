-- BRYGAD ERP — schemat bazy danych (bez danych)
-- Wygenerowano z dumpa strukturalnego. Bez INSERT-ów, bez danych klientów.
-- Import: mysql -u root -p brygad_erp < database/schema.sql

CREATE DATABASE IF NOT EXISTS `brygad_erp` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `brygad_erp`;

-- phpMyAdmin SQL Dump
-- version 5.2.3
-- https://www.phpmyadmin.net/
--
-- Host: localhost
-- Generation Time: Cze 30, 2026 at 09:54 AM
-- Wersja serwera: 11.8.6-MariaDB-0+deb13u1 from Debian
-- Wersja PHP: 7.4.33

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Baza danych: `brygad_erp`
--

DELIMITER $$
--
-- Procedury
--
CREATE DEFINER=`brygad`@`localhost` PROCEDURE `get_worker_rate` (IN `p_worker_id` INT, IN `p_project_id` INT, IN `p_cost_node_id` INT, IN `p_date` DATE)   BEGIN
    -- Logika: Pobierz stawkę pasującą do pracownika, daty i o najwyższym priorytecie
    -- Priorytet: STAGE (1) > PROJECT (2) > GLOBAL (3)
    
    SELECT *
    FROM worker_rates 
    WHERE worker_id = p_worker_id
      -- Warunek daty (historyczność, którą masz w bazie)
      AND (valid_from <= p_date AND (valid_to IS NULL OR valid_to >= p_date))
      -- Dopasowanie zakresu
      AND (
          (scope_type = 'STAGE'   AND cost_node_id = p_cost_node_id) OR
          (scope_type = 'PROJECT' AND project_id = p_project_id) OR
          (scope_type = 'GLOBAL')
      )
    ORDER BY 
        CASE scope_type 
            WHEN 'STAGE' THEN 1 
            WHEN 'PROJECT' THEN 2 
            WHEN 'GLOBAL' THEN 3 
        END ASC,
        valid_from DESC -- W razie konfliktu dat, weź najnowszą
    LIMIT 1;
END$$

DELIMITER ;

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `assets`
--

CREATE TABLE `assets` (
  `id` int(10) UNSIGNED NOT NULL,
  `type` enum('car_passenger','car_delivery','truck','excavator','lift','tool','other') NOT NULL DEFAULT 'other',
  `name` varchar(150) NOT NULL COMMENT 'Np. Skoda Octavia lub CAT 320',
  `reg_number` varchar(50) DEFAULT NULL,
  `serial_number` varchar(100) DEFAULT NULL,
  `usage_unit` enum('km','mth','none') DEFAULT 'km',
  `current_usage` decimal(10,1) DEFAULT 0.0,
  `production_year` year(4) DEFAULT NULL,
  `purchase_date` date DEFAULT NULL,
  `owner_user_id` int(10) UNSIGNED DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `notes` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `asset_bookings`
--

CREATE TABLE `asset_bookings` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `asset_id` int(10) UNSIGNED NOT NULL,
  `worker_id` int(10) UNSIGNED DEFAULT NULL,
  `project_id` int(10) UNSIGNED DEFAULT NULL,
  `customer_name` varchar(200) DEFAULT NULL,
  `start_date` datetime NOT NULL,
  `end_date` datetime NOT NULL,
  `status` enum('draft','confirmed','completed','cancelled') DEFAULT 'draft',
  `description` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `asset_events`
--

CREATE TABLE `asset_events` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `asset_id` int(10) UNSIGNED NOT NULL,
  `event_category` enum('technical','insurance','service','repair','other') NOT NULL,
  `title` varchar(150) NOT NULL,
  `due_date` date NOT NULL,
  `completed_at` date DEFAULT NULL,
  `status` enum('planned','done','overdue') DEFAULT 'planned',
  `remind_days_before` int(11) DEFAULT 14,
  `attachment_path` varchar(500) DEFAULT NULL,
  `cost_net` decimal(10,2) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `asset_static_documents`
--

CREATE TABLE `asset_static_documents` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `asset_id` int(10) UNSIGNED NOT NULL,
  `title` varchar(150) NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `uploaded_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `company_cost_categories`
--

CREATE TABLE `company_cost_categories` (
  `id` int(11) NOT NULL,
  `category_key` varchar(50) NOT NULL,
  `name` varchar(100) NOT NULL,
  `is_system` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 100,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `company_cost_subcategories`
--

CREATE TABLE `company_cost_subcategories` (
  `id` int(11) NOT NULL,
  `category_key` varchar(50) NOT NULL,
  `name` varchar(120) NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 100,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `company_settings`
--

CREATE TABLE `company_settings` (
  `id` int(10) UNSIGNED NOT NULL,
  `company_name` varchar(255) NOT NULL DEFAULT '',
  `company_nip` varchar(20) NOT NULL DEFAULT '',
  `company_regon` varchar(20) NOT NULL DEFAULT '',
  `company_address` varchar(500) NOT NULL DEFAULT '',
  `company_city` varchar(100) NOT NULL DEFAULT '',
  `company_post_code` varchar(10) NOT NULL DEFAULT '',
  `company_email` varchar(255) NOT NULL DEFAULT '',
  `company_phone` varchar(50) NOT NULL DEFAULT '',
  `company_website` varchar(255) NOT NULL DEFAULT '',
  `logo_path` varchar(500) DEFAULT NULL COMMENT 'Ścieżka do logo firmy (uploads/)',
  `default_bank_account` varchar(50) NOT NULL DEFAULT '' COMMENT 'Domyślne konto bankowe na fakturach',
  `default_bank_name` varchar(100) NOT NULL DEFAULT '' COMMENT 'Nazwa banku',
  `default_place_of_issue` varchar(100) NOT NULL DEFAULT 'Czerwonak',
  `default_payment_days` int(11) NOT NULL DEFAULT 14,
  `default_payment_method` varchar(30) NOT NULL DEFAULT 'transfer',
  `default_currency` varchar(5) NOT NULL DEFAULT 'PLN',
  `default_notes` text DEFAULT NULL COMMENT 'Domyślne uwagi na fakturze',
  `default_description_footer` text DEFAULT NULL COMMENT 'Domyślna stopka opisu Fakturowni',
  `fakturownia_department_id` int(11) DEFAULT NULL COMMENT 'Domyślny ID działu Fakturowni',
  `fakturownia_lang` varchar(5) NOT NULL DEFAULT 'pl',
  `issuer_name` varchar(255) NOT NULL DEFAULT '' COMMENT 'Imię i nazwisko wystawcy faktur',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `cost_allocations`
--

CREATE TABLE `cost_allocations` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `invoice_id` bigint(20) UNSIGNED NOT NULL,
  `project_id` bigint(20) UNSIGNED DEFAULT NULL,
  `cost_node_id` bigint(20) UNSIGNED DEFAULT NULL,
  `amount` decimal(12,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `documents`
--

CREATE TABLE `documents` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `type` enum('invoice_cost','contract','annex','protocol') NOT NULL DEFAULT 'invoice_cost' COMMENT 'Faktura kosztowa, Umowa, Aneks, Protokół',
  `status` enum('draft','approved','archived') NOT NULL DEFAULT 'draft',
  `vendor_id` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'Kontrahent z tabeli investors (opcjonalny)',
  `source_name` varchar(255) DEFAULT NULL COMMENT 'Nazwa kontrahenta spoza listy (wymagane, gdy vendor_id IS NULL)',
  `project_id` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'Projekt domyślny/kontekstowy (koszt i tak wynika z alokacji)',
  `worker_id` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'Dokument przypięty do pracownika (np. umowa, certyfikat)',
  `created_by` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'Kto wprowadził do systemu (User ID)',
  `number` varchar(100) NOT NULL COMMENT 'Numer obcy (np. faktury)',
  `issue_date` date NOT NULL,
  `due_date` date DEFAULT NULL COMMENT 'Termin płatności',
  `sale_date` date DEFAULT NULL,
  `currency` char(3) NOT NULL DEFAULT 'PLN',
  `amount_net` decimal(12,2) NOT NULL DEFAULT 0.00,
  `amount_vat` decimal(12,2) NOT NULL DEFAULT 0.00,
  `amount_gross` decimal(12,2) NOT NULL DEFAULT 0.00,
  `file_path` varchar(500) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `approved_at` datetime DEFAULT NULL COMMENT 'Data zatwierdzenia kosztu'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--
-- Wyzwalacze `documents`
--
DELIMITER $$
CREATE TRIGGER `trg_doc_validate_vendor_insert` BEFORE INSERT ON `documents` FOR EACH ROW BEGIN
  -- Jeśli vendor_id jest pusty ORAZ source_name jest pusty/same spacje
  IF (NEW.vendor_id IS NULL AND (NEW.source_name IS NULL OR TRIM(NEW.source_name) = '')) THEN
    SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'BŁĄD: Dokument musi mieć wybranego Kontrahenta (z listy) LUB wpisaną Nazwę (ręcznie).';
  END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_doc_validate_vendor_update` BEFORE UPDATE ON `documents` FOR EACH ROW BEGIN
  IF (NEW.vendor_id IS NULL AND (NEW.source_name IS NULL OR TRIM(NEW.source_name) = '')) THEN
    SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'BŁĄD: Dokument musi mieć wybranego Kontrahenta (z listy) LUB wpisaną Nazwę (ręcznie).';
  END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `document_allocations`
--

CREATE TABLE `document_allocations` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `document_id` bigint(20) UNSIGNED NOT NULL,
  `project_id` bigint(20) UNSIGNED DEFAULT NULL,
  `cost_node_id` bigint(20) UNSIGNED DEFAULT NULL,
  `category` varchar(50) NOT NULL DEFAULT 'material' COMMENT 'Kategoria: material, equipment, subcontracting, transport, other (projektowe) lub flota, media, ubezpieczenia, certyfikaty, podatki, narzedzia, inne (firmowe)',
  `amount_net` decimal(12,2) NOT NULL DEFAULT 0.00,
  `description` varchar(255) DEFAULT NULL,
  `is_legacy` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = Stara alokacja (przed migracją na pozycje)',
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `document_items`
--

CREATE TABLE `document_items` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `document_id` bigint(20) UNSIGNED NOT NULL COMMENT 'FK do documents',
  `item_name` varchar(255) NOT NULL COMMENT 'Nazwa pozycji/usługi',
  `quantity` decimal(12,2) NOT NULL DEFAULT 1.00 COMMENT 'Ilość (zwiększona precyzja)',
  `unit` varchar(20) NOT NULL DEFAULT 'szt' COMMENT 'Jednostka (szt, usł, godz, m2, mb, kg, kpl)',
  `unit_price_net` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'Cena jednostkowa netto',
  `vat_rate` varchar(10) NOT NULL DEFAULT '23' COMMENT 'Stawka VAT (23, 8, 5, 0, zw)',
  `amount_net` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'Kwota netto pozycji',
  `amount_vat` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'Kwota VAT pozycji',
  `amount_gross` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'Kwota brutto pozycji',
  `sort_order` int(11) NOT NULL DEFAULT 0 COMMENT 'Kolejność wyświetlania',
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Pozycje faktur kosztowych';

--
-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `document_item_allocations`
--

CREATE TABLE `document_item_allocations` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `document_item_id` bigint(20) UNSIGNED NOT NULL COMMENT 'FK do document_items',
  `project_id` bigint(20) UNSIGNED NOT NULL COMMENT 'FK do projects',
  `cost_node_id` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'FK do project_cost_nodes (etap/pod-etap)',
  `amount` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'Kwota przypisana (może być częściowa)',
  `notes` text DEFAULT NULL COMMENT 'Notatki do alokacji',
  `created_by` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'FK do users (kto utworzył)',
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Alokacje pozycji faktur kosztowych do projektów/etapów';

--
-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `document_types`
--

CREATE TABLE `document_types` (
  `id` int(10) UNSIGNED NOT NULL,
  `code` varchar(50) NOT NULL,
  `name` varchar(120) NOT NULL,
  `has_validity` tinyint(1) NOT NULL DEFAULT 1,
  `requires_file` tinyint(1) NOT NULL DEFAULT 0,
  `default_reminder_days` int(11) NOT NULL DEFAULT 30,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 100,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `erp_products`
--

CREATE TABLE `erp_products` (
  `id` int(11) UNSIGNED NOT NULL,
  `name` varchar(255) NOT NULL,
  `code` varchar(50) DEFAULT NULL,
  `unit` varchar(20) NOT NULL DEFAULT 'szt',
  `vat_rate` varchar(10) NOT NULL DEFAULT '23',
  `price_net` decimal(12,2) NOT NULL DEFAULT 0.00,
  `description` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `fakturownia_product_id` int(11) UNSIGNED DEFAULT NULL,
  `created_by` int(11) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `fakturownia_api_log`
--

CREATE TABLE `fakturownia_api_log` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `endpoint` varchar(255) NOT NULL COMMENT 'Ścieżka API (np. /invoices.json)',
  `http_method` enum('GET','POST','PUT','DELETE','PATCH') NOT NULL COMMENT 'Metoda HTTP',
  `http_status` smallint(5) UNSIGNED DEFAULT NULL COMMENT 'Kod odpowiedzi HTTP',
  `request_json` text DEFAULT NULL COMMENT 'Body żądania (BEZ api_token)',
  `response_json` text DEFAULT NULL COMMENT 'Body odpowiedzi z API',
  `retry_count` tinyint(3) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Ile razy ponowiono żądanie',
  `error_message` varchar(500) DEFAULT NULL COMMENT 'Treść błędu (jeśli wystąpił)',
  `created_at` datetime NOT NULL DEFAULT current_timestamp() COMMENT 'Timestamp żądania'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `fakturownia_archive_files`
--

CREATE TABLE `fakturownia_archive_files` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `source_type` enum('sale','cost') NOT NULL,
  `source_local_id` bigint(20) UNSIGNED NOT NULL,
  `fakturownia_id` bigint(20) UNSIGNED NOT NULL,
  `file_kind` enum('pdf','xml','upo') NOT NULL DEFAULT 'pdf',
  `storage_tier` enum('hot','cold') NOT NULL DEFAULT 'hot',
  `document_date` date DEFAULT NULL,
  `storage_year` smallint(5) UNSIGNED NOT NULL,
  `storage_month` tinyint(3) UNSIGNED NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `mime_type` varchar(100) NOT NULL DEFAULT 'application/pdf',
  `file_size` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `checksum_sha256` char(64) NOT NULL,
  `archived_at` datetime DEFAULT NULL,
  `last_verified_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `fakturownia_clients`
--

CREATE TABLE `fakturownia_clients` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `erp_client_id` bigint(20) UNSIGNED NOT NULL COMMENT 'FK do investors.id (klient ERP)',
  `fakturownia_id` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'ID klienta po stronie Fakturowni',
  `synced_at` datetime DEFAULT NULL COMMENT 'Ostatnia synchronizacja z Fakturownią',
  `created_at` datetime NOT NULL DEFAULT current_timestamp() COMMENT 'Data utworzenia rekordu'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `fakturownia_cost_allocations`
--

CREATE TABLE `fakturownia_cost_allocations` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `cost_invoice_id` bigint(20) UNSIGNED NOT NULL COMMENT 'FK do fakturownia_cost_invoices',
  `project_id` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'Projekt docelowy',
  `cost_node_id` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'Etap/pod-etap kosztowy',
  `company_cost_category` varchar(50) DEFAULT NULL COMMENT 'Kategoria kosztu firmy (flota, media, ubezpieczenia...), NULL = alokacja do projektu',
  `company_cost_subcategory` varchar(120) DEFAULT NULL COMMENT 'Podkategoria kosztu firmy, NULL = brak podkategorii albo alokacja do projektu',
  `allocation_percent` decimal(5,2) DEFAULT NULL COMMENT 'Procent alokacji (0-100)',
  `amount_net` decimal(12,2) NOT NULL DEFAULT 0.00,
  `amount_gross` decimal(12,2) NOT NULL DEFAULT 0.00,
  `description` varchar(255) DEFAULT NULL COMMENT 'Opis alokacji',
  `source_position_id` int(11) DEFAULT NULL COMMENT 'Indeks pozycji z API Fakturowni (0-based), NULL = cała faktura',
  `created_by_user_id` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'User, który dodał wpis',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Podział kosztów faktur na projekty i etapy w ERP';

--
-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `fakturownia_cost_invoices`
--

CREATE TABLE `fakturownia_cost_invoices` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `fakturownia_id` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'ID dokumentu po stronie Fakturowni',
  `ksef_number` varchar(120) DEFAULT NULL COMMENT 'Numer identyfikacyjny KSeF',
  `invoice_number` varchar(120) DEFAULT NULL COMMENT 'Numer dokumentu od dostawcy',
  `supplier_name` varchar(255) NOT NULL COMMENT 'Nazwa dostawcy',
  `supplier_nip` varchar(20) DEFAULT NULL COMMENT 'NIP dostawcy',
  `issue_date` date DEFAULT NULL COMMENT 'Data wystawienia',
  `sale_date` date DEFAULT NULL COMMENT 'Data sprzedaży/usługi',
  `due_date` date DEFAULT NULL COMMENT 'Termin płatności',
  `currency` char(3) NOT NULL DEFAULT 'PLN',
  `amount_net` decimal(12,2) NOT NULL DEFAULT 0.00,
  `amount_vat` decimal(12,2) NOT NULL DEFAULT 0.00,
  `amount_gross` decimal(12,2) NOT NULL DEFAULT 0.00,
  `payment_status` enum('unpaid','partial','paid','unknown') NOT NULL DEFAULT 'unknown',
  `workflow_status` enum('new','assigned','accepted','rejected','archived') NOT NULL DEFAULT 'new' COMMENT 'Status wewnętrzny ERP',
  `owner_note` varchar(500) DEFAULT NULL COMMENT 'Notatka właściciela / księgowości',
  `decided_by_user_id` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'User, który zatwierdził rozdział',
  `decided_at` datetime DEFAULT NULL COMMENT 'Data decyzji biznesowej',
  `source_payload_json` longtext DEFAULT NULL COMMENT 'Surowy payload API (bez tokenu)',
  `imported_at` datetime NOT NULL DEFAULT current_timestamp() COMMENT 'Pierwszy import do ERP',
  `synced_at` datetime DEFAULT NULL COMMENT 'Ostatnia synchronizacja statusu',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Inbox faktur kosztowych pobieranych z Fakturowni/KSeF';

--
-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `fakturownia_cost_status_history`
--

CREATE TABLE `fakturownia_cost_status_history` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `cost_invoice_id` bigint(20) UNSIGNED NOT NULL,
  `from_status` enum('new','assigned','accepted','rejected','archived') DEFAULT NULL,
  `to_status` enum('new','assigned','accepted','rejected','archived') NOT NULL,
  `changed_by_user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `change_note` varchar(500) DEFAULT NULL,
  `changed_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Historia zmian statusów workflow dla faktur kosztowych';

--
-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `fakturownia_invoices`
--

CREATE TABLE `fakturownia_invoices` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `erp_contract_id` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'ID umowy/projektu w ERP (projects.id lub przyszła tabela contracts)',
  `erp_milestone_id` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'ID etapu/kamienia milowego w ERP (przyszłe rozszerzenie)',
  `erp_invoice_sale_id` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'ID faktury sprzedażowej ERP (invoices_sale.id)',
  `fakturownia_id` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'ID faktury po stronie Fakturowni',
  `fakturownia_number` varchar(100) DEFAULT NULL COMMENT 'Numer faktury nadany przez Fakturownię (np. FV 1/02/2026)',
  `gov_id` varchar(100) DEFAULT NULL COMMENT 'Numer referencyjny KSeF',
  `gov_status` enum('pending','ok','error') NOT NULL DEFAULT 'pending' COMMENT 'Status wysyłki do KSeF',
  `status` enum('draft','sent','paid') NOT NULL DEFAULT 'draft' COMMENT 'Status faktury w obiegu',
  `pdf_path` varchar(500) DEFAULT NULL COMMENT 'Ścieżka do pobranego PDF faktury',
  `request_hash` char(32) NOT NULL COMMENT 'MD5 hash danych żądania — idempotencja',
  `created_at` datetime NOT NULL DEFAULT current_timestamp() COMMENT 'Data utworzenia rekordu',
  `synced_at` datetime DEFAULT NULL COMMENT 'Ostatnia synchronizacja z Fakturownią',
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp() COMMENT 'Ostatnia modyfikacja rekordu (np. zmiana gov_status)'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `fakturownia_products`
--

CREATE TABLE `fakturownia_products` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `fakturownia_product_id` bigint(20) UNSIGNED DEFAULT NULL,
  `code` varchar(100) DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `unit` varchar(30) DEFAULT NULL,
  `tax` varchar(20) DEFAULT NULL,
  `price_net` decimal(12,2) DEFAULT NULL,
  `price_gross` decimal(12,2) DEFAULT NULL,
  `currency` char(3) NOT NULL DEFAULT 'PLN',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `source_payload_json` mediumtext DEFAULT NULL,
  `synced_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `finance_items`
--

CREATE TABLE `finance_items` (
  `id` int(11) NOT NULL,
  `item_type` enum('INVOICE_COST','INVOICE_REVENUE','RECEIPT','FIXED_COST') NOT NULL,
  `category` varchar(50) DEFAULT NULL COMMENT 'Kategoria kosztu: flota, media, ubezpieczenia, certyfikaty, podatki, narzedzia, inne',
  `subcategory` varchar(120) DEFAULT NULL,
  `project_id` bigint(20) UNSIGNED DEFAULT NULL,
  `etap_id` bigint(20) UNSIGNED DEFAULT NULL,
  `company_id` bigint(20) UNSIGNED DEFAULT NULL,
  `company_name` varchar(255) DEFAULT NULL,
  `title` varchar(255) NOT NULL COMMENT 'Tytuł/opis dokumentu',
  `description` text DEFAULT NULL COMMENT 'Szczegółowy opis',
  `doc_number` varchar(100) DEFAULT NULL COMMENT 'Numer dokumentu',
  `issue_date` date DEFAULT NULL COMMENT 'Data wystawienia/zakupu',
  `payment_date` date DEFAULT NULL COMMENT 'Data zapłaty',
  `amount_net` decimal(12,2) NOT NULL DEFAULT 0.00,
  `amount_gross` decimal(12,2) NOT NULL DEFAULT 0.00,
  `currency` char(3) DEFAULT 'PLN',
  `file_path` varchar(255) DEFAULT NULL,
  `status` enum('pending','approved','rejected') DEFAULT 'approved',
  `created_at` datetime DEFAULT current_timestamp(),
  `created_by` bigint(20) UNSIGNED DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `hb_accounts`
--

CREATE TABLE `hb_accounts` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `household_id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(100) NOT NULL COMMENT 'np. mBank, Portfel, Skarpeta',
  `type` enum('bank','cash','savings','other') NOT NULL DEFAULT 'bank',
  `currency` char(3) NOT NULL DEFAULT 'PLN',
  `current_balance` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'Aktualizowane triggerem lub aplikacją',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `hb_bills`
--

CREATE TABLE `hb_bills` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `household_id` bigint(20) UNSIGNED NOT NULL,
  `category_id` bigint(20) UNSIGNED NOT NULL COMMENT 'Domyślna kategoria',
  `default_account_id` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'Domyślne konto płatności',
  `name` varchar(150) NOT NULL COMMENT 'np. Czynsz, Netflix',
  `amount_type` enum('fixed','variable') NOT NULL DEFAULT 'fixed',
  `fixed_amount` decimal(12,2) DEFAULT NULL COMMENT 'Kwota stała (jeśli fixed)',
  `due_day` tinyint(3) UNSIGNED NOT NULL DEFAULT 10 COMMENT 'Dzień płatności (1-31)',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `hb_bill_items`
--

CREATE TABLE `hb_bill_items` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `bill_id` bigint(20) UNSIGNED NOT NULL,
  `household_id` bigint(20) UNSIGNED NOT NULL COMMENT 'Denormalizacja dla szybszych zapytań',
  `period` date NOT NULL COMMENT 'Okres rozliczeniowy, np. 2026-02-01',
  `due_date` date NOT NULL COMMENT 'Konkretna data płatności',
  `amount_due` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'Ile trzeba zapłacić',
  `amount_paid` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'Ile już wpłacono',
  `status` enum('unpaid','partial','paid') NOT NULL DEFAULT 'unpaid',
  `payment_date` date DEFAULT NULL COMMENT 'Data ostatniej wpłaty',
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `hb_budgets`
--

CREATE TABLE `hb_budgets` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `household_id` bigint(20) UNSIGNED NOT NULL,
  `category_id` bigint(20) UNSIGNED NOT NULL,
  `period` date NOT NULL COMMENT 'Miesiąc, np. 2026-02-01',
  `limit_amount` decimal(12,2) NOT NULL DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `hb_categories`
--

CREATE TABLE `hb_categories` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `household_id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(100) NOT NULL,
  `type` enum('income','expense') NOT NULL DEFAULT 'expense',
  `icon` varchar(50) DEFAULT NULL COMMENT 'np. klasa fontawesome',
  `is_active` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `hb_households`
--

CREATE TABLE `hb_households` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `owner_user_id` bigint(20) UNSIGNED NOT NULL COMMENT 'Właściciel budżetu (Admin)',
  `name` varchar(100) NOT NULL DEFAULT 'Budżet Domowy',
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `hb_household_members`
--

CREATE TABLE `hb_household_members` (
  `household_id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `role` enum('owner','member','viewer') NOT NULL DEFAULT 'member'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `hb_transactions`
--

CREATE TABLE `hb_transactions` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `household_id` bigint(20) UNSIGNED NOT NULL,
  `account_id` bigint(20) UNSIGNED NOT NULL COMMENT 'Konto źródłowe',
  `transfer_account_id` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'Tylko dla typu transfer (konto docelowe)',
  `direction` enum('income','expense','transfer') NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `date` date NOT NULL,
  `period` date NOT NULL COMMENT 'Denormalizacja: YYYY-MM-01 dla łatwego grupowania',
  `category_id` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'NULL dla transferów',
  `bill_item_id` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'Powiązanie z rachunkiem',
  `description` varchar(255) DEFAULT NULL,
  `visibility` enum('shared','private') NOT NULL DEFAULT 'shared' COMMENT 'shared = widoczny dla całego household; private = tylko owner',
  `owner_user_id` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'Dla private: user_id właściciela wpisu; NULL dla shared',
  `include_in_household_total` tinyint(1) NOT NULL DEFAULT 1 COMMENT '1 = wpis wchodzi do sum globalnych household; 0 = wyłączony z sum',
  `created_by` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'User z głównej tabeli users',
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `hr_alerts`
--

CREATE TABLE `hr_alerts` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `worker_id` bigint(20) UNSIGNED NOT NULL,
  `document_id` bigint(20) UNSIGNED NOT NULL,
  `alert_type` enum('expiring','expired') NOT NULL,
  `due_date` date NOT NULL,
  `remind_at` date NOT NULL,
  `status` enum('open','acknowledged','closed') NOT NULL DEFAULT 'open',
  `acknowledged_at` datetime DEFAULT NULL,
  `closed_at` datetime DEFAULT NULL,
  `closed_note` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Wyzwalacze `hr_alerts`
--
DELIMITER $$
CREATE TRIGGER `trg_hr_alerts_bi_set_worker` BEFORE INSERT ON `hr_alerts` FOR EACH ROW SET NEW.worker_id = (
  SELECT wd.worker_id
  FROM worker_documents wd
  WHERE wd.id = NEW.document_id
  LIMIT 1
)
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_hr_alerts_bu_set_worker` BEFORE UPDATE ON `hr_alerts` FOR EACH ROW SET NEW.worker_id = (
  SELECT wd.worker_id
  FROM worker_documents wd
  WHERE wd.id = NEW.document_id
  LIMIT 1
)
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `investors`
--

CREATE TABLE `investors` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `type` enum('business','private') NOT NULL DEFAULT 'business',
  `first_name` varchar(100) DEFAULT NULL,
  `last_name` varchar(100) DEFAULT NULL,
  `name` varchar(255) NOT NULL COMMENT 'Pełna nazwa firmy lub Imię i Nazwisko',
  `nip` varchar(20) DEFAULT NULL COMMENT 'NIP bez kresek',
  `regon` varchar(20) DEFAULT NULL COMMENT 'REGON',
  `krs` varchar(50) DEFAULT NULL COMMENT 'KRS / EDG',
  `address` varchar(255) DEFAULT NULL COMMENT 'Ulica, kod, miasto',
  `email` varchar(100) DEFAULT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `website` varchar(255) DEFAULT NULL COMMENT 'Strona WWW',
  `contact_person` varchar(100) DEFAULT NULL COMMENT 'Imię i nazwisko osoby do kontaktu',
  `bank_name` varchar(100) DEFAULT NULL COMMENT 'Nazwa banku',
  `bank_account` varchar(50) DEFAULT NULL COMMENT 'Numer konta bankowego',
  `notes` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `investor_notes`
--

CREATE TABLE `investor_notes` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `investor_id` bigint(20) UNSIGNED NOT NULL,
  `note` text NOT NULL,
  `created_by` bigint(20) UNSIGNED NOT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `investor_reminders`
--

CREATE TABLE `investor_reminders` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `investor_id` bigint(20) UNSIGNED NOT NULL,
  `remind_at` date NOT NULL,
  `title` varchar(255) NOT NULL,
  `note` text DEFAULT NULL,
  `is_done` tinyint(1) NOT NULL DEFAULT 0,
  `done_at` datetime DEFAULT NULL,
  `created_by` bigint(20) UNSIGNED NOT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `invoices`
--

CREATE TABLE `invoices` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `number` varchar(50) NOT NULL,
  `contractor` varchar(150) NOT NULL,
  `date` date NOT NULL,
  `amount_gross` decimal(12,2) NOT NULL,
  `scope` enum('business','private') NOT NULL DEFAULT 'business',
  `status` enum('draft','approved') NOT NULL DEFAULT 'draft',
  `file_path` varchar(255) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `invoices_sale`
--

CREATE TABLE `invoices_sale` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `invoice_number` varchar(100) NOT NULL COMMENT 'Numer własny faktury',
  `document_kind` enum('sale_vat','sale_correction','sale_advance','sale_final','sale_proforma','sale_other') NOT NULL DEFAULT 'sale_vat',
  `source_system` enum('manual','fakturownia','comarch') NOT NULL DEFAULT 'manual',
  `source_external_id` bigint(20) UNSIGNED DEFAULT NULL,
  `source_raw_kind` varchar(40) DEFAULT NULL,
  `source_created_at` datetime DEFAULT NULL,
  `client_id` bigint(20) UNSIGNED NOT NULL COMMENT 'FK do investors (płatnik)',
  `issue_date` date NOT NULL COMMENT 'Data wystawienia',
  `sale_date` date NOT NULL COMMENT 'Data sprzedaży/wykonania usługi',
  `due_date` date NOT NULL COMMENT 'Termin płatności',
  `payment_date` date DEFAULT NULL COMMENT 'Data faktycznego rozliczenia',
  `currency` char(3) NOT NULL DEFAULT 'PLN',
  `split_payment` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Mechanizm podzielonej płatności (split payment)',
  `payment_method` enum('transfer','cash','card','other') NOT NULL DEFAULT 'transfer' COMMENT 'Sposób płatności',
  `payment_days` int(11) DEFAULT 14 COMMENT 'Termin płatności w dniach',
  `bank_account` varchar(50) DEFAULT NULL COMMENT 'Numer konta bankowego do przelewu',
  `place_of_issue` varchar(100) DEFAULT 'Czerwonak' COMMENT 'Miejsce wystawienia faktury',
  `amount_net` decimal(12,2) NOT NULL DEFAULT 0.00,
  `amount_vat` decimal(12,2) NOT NULL DEFAULT 0.00,
  `amount_gross` decimal(12,2) NOT NULL DEFAULT 0.00,
  `status` enum('draft','issued','paid','partially_paid','overdue','cancelled') NOT NULL DEFAULT 'draft',
  `exclude_from_analytics` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = wyklucz z analiz ERP, Fakturownia dostaje paid',
  `file_path` varchar(500) DEFAULT NULL COMMENT 'Link do wygenerowanego PDF/skanu',
  `notes` text DEFAULT NULL,
  `fakturownia_options_json` text DEFAULT NULL COMMENT 'Zaawansowane opcje payloadu Fakturownia (nagłówek)',
  `seller_data_json` text DEFAULT NULL COMMENT 'Snapshot danych sprzedawcy z company_settings w momencie tworzenia',
  `created_by` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` datetime DEFAULT NULL,
  `deleted_by` bigint(20) UNSIGNED DEFAULT NULL,
  `delete_reason` varchar(500) DEFAULT NULL,
  `sync_attention_required` tinyint(1) NOT NULL DEFAULT 0,
  `sync_attention_note` varchar(500) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `invoice_audit_log`
--

CREATE TABLE `invoice_audit_log` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `invoice_sale_id` bigint(20) UNSIGNED DEFAULT NULL,
  `action` varchar(80) NOT NULL,
  `old_values` longtext DEFAULT NULL,
  `new_values` longtext DEFAULT NULL,
  `user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `reason` varchar(500) DEFAULT NULL,
  `source` varchar(80) NOT NULL DEFAULT 'erp',
  `external_fakturownia_id` bigint(20) UNSIGNED DEFAULT NULL,
  `external_gov_id` varchar(120) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Audyt krytycznych operacji na fakturach sprzedazowych';

--
-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `invoice_sale_allocations`
--

CREATE TABLE `invoice_sale_allocations` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `invoice_id` bigint(20) UNSIGNED NOT NULL,
  `project_id` bigint(20) UNSIGNED NOT NULL,
  `cost_node_id` bigint(20) UNSIGNED DEFAULT NULL,
  `amount_net` decimal(12,2) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `created_by` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `invoice_sale_items`
--

CREATE TABLE `invoice_sale_items` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `invoice_id` bigint(20) UNSIGNED NOT NULL,
  `project_id` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'Alokacja przychodu do projektu',
  `cost_node_id` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'Alokacja do etapu (opcjonalnie)',
  `item_name` varchar(255) NOT NULL,
  `quantity` decimal(12,2) NOT NULL DEFAULT 1.00,
  `unit` varchar(20) NOT NULL DEFAULT 'szt' COMMENT 'szt, m2, godz, usł',
  `unit_price_net` decimal(12,2) NOT NULL DEFAULT 0.00,
  `vat_rate` varchar(10) NOT NULL DEFAULT '23' COMMENT '23, 8, 0, zw',
  `amount_net` decimal(12,2) NOT NULL DEFAULT 0.00,
  `amount_vat` decimal(12,2) NOT NULL DEFAULT 0.00,
  `amount_gross` decimal(12,2) NOT NULL DEFAULT 0.00,
  `fakturownia_item_options_json` text DEFAULT NULL COMMENT 'Zaawansowane opcje pozycji do payloadu Fakturownia',
  `sort_order` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `invoice_sale_jst_data`
--

CREATE TABLE `invoice_sale_jst_data` (
  `id` int(10) UNSIGNED NOT NULL,
  `invoice_sale_id` bigint(20) UNSIGNED NOT NULL,
  `jst_buyer_name` varchar(500) NOT NULL DEFAULT '',
  `jst_buyer_nip` varchar(20) NOT NULL DEFAULT '',
  `jst_buyer_street` varchar(200) NOT NULL DEFAULT '',
  `jst_buyer_post_code` varchar(10) NOT NULL DEFAULT '',
  `jst_buyer_city` varchar(100) NOT NULL DEFAULT '',
  `jst_recipient_name` varchar(500) NOT NULL DEFAULT '',
  `jst_recipient_nip` varchar(20) NOT NULL DEFAULT '',
  `jst_recipient_street` varchar(200) NOT NULL DEFAULT '',
  `jst_recipient_post_code` varchar(10) NOT NULL DEFAULT '',
  `jst_recipient_city` varchar(100) NOT NULL DEFAULT '',
  `jst_recipient_note` text NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `invoice_sale_payments`
--

CREATE TABLE `invoice_sale_payments` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `invoice_id` bigint(20) UNSIGNED NOT NULL COMMENT 'FK do invoices_sale',
  `amount_net` decimal(12,2) NOT NULL,
  `payment_date` date NOT NULL,
  `notes` varchar(500) DEFAULT NULL,
  `source` enum('manual','fk_sync','retention_settled') NOT NULL DEFAULT 'manual',
  `created_by` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'FK do users',
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `invoice_sale_retentions`
--

CREATE TABLE `invoice_sale_retentions` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `invoice_id` bigint(20) UNSIGNED NOT NULL COMMENT 'FK do invoices_sale',
  `retention_percent` decimal(5,2) NOT NULL DEFAULT 5.00 COMMENT 'Procent retencji (domyślnie 5%)',
  `retention_amount` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'Kwota retencji PLN',
  `return_date` date DEFAULT NULL COMMENT 'Planowana data zwrotu/odbioru środków',
  `reminder_date` date DEFAULT NULL COMMENT 'Data przypomnienia',
  `status` enum('active','due_soon','overdue','settled') NOT NULL DEFAULT 'active' COMMENT 'active=bieżąca, due_soon=<30 dni do zwrotu, overdue=po terminie, settled=rozliczona',
  `settled_at` datetime DEFAULT NULL COMMENT 'Kiedy rozliczono retencję',
  `settled_by_user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `notes` varchar(500) DEFAULT NULL,
  `created_by_user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Retencje powiązane z fakturami sprzedażowymi SPRUTEX ERP';

--
-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `projects`
--

CREATE TABLE `projects` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(100) NOT NULL,
  `project_type` enum('standard','micro') NOT NULL DEFAULT 'standard' COMMENT 'standard = duży projekt, micro = małe zlecenie',
  `investor_id` bigint(20) UNSIGNED DEFAULT NULL,
  `contract_amount` decimal(12,2) DEFAULT NULL COMMENT 'Kwota umowy bazowej (netto)',
  `status` enum('planned','active','finished') NOT NULL DEFAULT 'planned',
  `archived_at` datetime DEFAULT NULL,
  `is_internal` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = Baza/Warsztat. Zalecane zamiast NULL w logach.',
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `project_cost_nodes`
--

CREATE TABLE `project_cost_nodes` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `project_id` bigint(20) UNSIGNED NOT NULL,
  `parent_id` bigint(20) UNSIGNED DEFAULT NULL,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL COMMENT 'Opcjonalny opis etapu kosztów - wyświetlany na listach dla ułatwienia identyfikacji',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Drzewo kosztów. Roadmapa: dodać path/nested sets przy optymalizacji.';

--
-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `project_revenues`
--

CREATE TABLE `project_revenues` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `project_id` bigint(20) UNSIGNED NOT NULL,
  `cost_node_id` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'Etap/Pod-etap (opcjonalnie)',
  `type` enum('contract','annex','bonus') NOT NULL DEFAULT 'contract',
  `name` varchar(150) NOT NULL COMMENT 'np. Umowa Główna, Aneks nr 1',
  `description` text DEFAULT NULL,
  `amount_net` decimal(12,2) NOT NULL DEFAULT 0.00,
  `signed_date` date NOT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `push_notifications_log`
--

CREATE TABLE `push_notifications_log` (
  `id` int(11) NOT NULL,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `subscription_id` int(11) DEFAULT NULL,
  `notification_type` varchar(50) NOT NULL COMMENT 'task_new, task_updated, task_overdue, test',
  `payload` text DEFAULT NULL,
  `status` enum('sent','failed','expired') NOT NULL DEFAULT 'sent',
  `error_message` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `push_subscriptions`
--

CREATE TABLE `push_subscriptions` (
  `id` int(11) NOT NULL,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `endpoint` varchar(500) NOT NULL,
  `p256dh` varchar(200) NOT NULL,
  `auth` varchar(100) NOT NULL,
  `user_agent` varchar(500) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `last_used_at` datetime DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `sales_noninvoice_allocations`
--

CREATE TABLE `sales_noninvoice_allocations` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `entry_id` bigint(20) UNSIGNED NOT NULL,
  `project_id` bigint(20) UNSIGNED NOT NULL,
  `cost_node_id` bigint(20) UNSIGNED DEFAULT NULL,
  `amount_net` decimal(12,2) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `created_by` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `sales_noninvoice_entries`
--

CREATE TABLE `sales_noninvoice_entries` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `entry_number` varchar(50) NOT NULL,
  `client_id` bigint(20) UNSIGNED DEFAULT NULL,
  `counterparty_name_manual` varchar(255) DEFAULT NULL,
  `title` varchar(150) NOT NULL,
  `notes` text DEFAULT NULL,
  `issue_date` date NOT NULL,
  `due_date` date DEFAULT NULL,
  `payment_date` date DEFAULT NULL,
  `currency` char(3) NOT NULL DEFAULT 'PLN',
  `amount_net` decimal(12,2) NOT NULL,
  `amount_vat` decimal(12,2) NOT NULL DEFAULT 0.00,
  `amount_gross` decimal(12,2) NOT NULL,
  `payment_status` enum('unpaid','paid') NOT NULL DEFAULT 'unpaid',
  `created_by` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `settlements`
--

CREATE TABLE `settlements` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `worker_id` bigint(20) UNSIGNED NOT NULL,
  `type` enum('payout','advance','reimbursement','bonus','correction') NOT NULL,
  `advance_kind` enum('private','company') DEFAULT NULL,
  `amount` decimal(12,2) NOT NULL,
  `date` date NOT NULL,
  `period` date NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `linked_expense_id` bigint(20) UNSIGNED DEFAULT NULL,
  `created_by_user_id` bigint(20) UNSIGNED DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `system_settings`
--

CREATE TABLE `system_settings` (
  `id` int(11) NOT NULL,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `tasks`
--

CREATE TABLE `tasks` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `priority` enum('low','medium','high') NOT NULL DEFAULT 'medium',
  `due_date` date DEFAULT NULL COMMENT 'Termin wykonania (opcjonalny)',
  `created_by` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `is_active` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `task_assignments`
--

CREATE TABLE `task_assignments` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `task_id` bigint(20) UNSIGNED NOT NULL,
  `worker_id` bigint(20) UNSIGNED NOT NULL,
  `status` enum('todo','doing','done') NOT NULL DEFAULT 'todo',
  `completed_at` datetime DEFAULT NULL,
  `assigned_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `task_attachments`
--

CREATE TABLE `task_attachments` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `task_id` bigint(20) UNSIGNED NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `original_name` varchar(255) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `task_comments`
--

CREATE TABLE `task_comments` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `task_id` bigint(20) UNSIGNED NOT NULL,
  `worker_id` bigint(20) UNSIGNED DEFAULT NULL,
  `user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `comment` text NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `users`
--

CREATE TABLE `users` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `worker_id` bigint(20) UNSIGNED DEFAULT NULL,
  `display_name` varchar(100) DEFAULT NULL,
  `first_name` varchar(50) NOT NULL,
  `last_name` varchar(50) NOT NULL,
  `email` varchar(150) NOT NULL,
  `login` varchar(50) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` varchar(30) NOT NULL DEFAULT 'worker',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp(),
  `last_login` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- --------------------------------------------------------

--
-- Zastąpiona struktura widoku `view_finance_ledger`
-- (See below for the actual view)
--
CREATE TABLE `view_finance_ledger` (
`ledger_source` varchar(19)
,`source_id` decimal(20,0)
,`allocation_id` decimal(20,0)
,`date` date
,`period` date
,`project_id` decimal(20,0)
,`cost_node_id` bigint(20) unsigned
,`amount` decimal(12,2)
,`counterparty_name` varchar(255)
,`description` varchar(500)
);

-- --------------------------------------------------------

--
-- Zastąpiona struktura widoku `view_project_finances`
-- (See below for the actual view)
--
CREATE TABLE `view_project_finances` (
`project_id` bigint(20) unsigned
,`project_name` varchar(100)
,`status` enum('planned','active','finished')
,`total_revenue` decimal(34,2)
,`total_labor_cost` decimal(34,2)
,`total_material_cost` decimal(34,2)
,`current_profit` decimal(36,2)
);

-- --------------------------------------------------------

--
-- Zastąpiona struktura widoku `view_project_finance_documents`
-- (See below for the actual view)
--
CREATE TABLE `view_project_finance_documents` (
`item_id` decimal(20,0)
,`source_table` varchar(16)
,`item_type` varchar(15)
,`project_id` bigint(20) unsigned
,`etap_id` decimal(20,0)
,`company_name` varchar(255)
,`title` varchar(264)
,`description` mediumtext
,`doc_number` varchar(100)
,`issue_date` date
,`amount_net` decimal(12,2)
,`amount_gross` decimal(12,2)
,`currency` varchar(3)
,`file_path` varchar(500)
,`status` varchar(10)
,`cost_category` varchar(32)
,`created_at` datetime /* mariadb-5.3 */
,`created_by` decimal(20,0)
,`view_url` varchar(58)
);

-- --------------------------------------------------------

--
-- Zastąpiona struktura widoku `v_asset_alerts`
-- (See below for the actual view)
--
CREATE TABLE `v_asset_alerts` (
`event_id` bigint(20) unsigned
,`asset_id` int(10) unsigned
,`asset_name` varchar(150)
,`asset_type` enum('car_passenger','car_delivery','truck','excavator','lift','tool','other')
,`event_category` enum('technical','insurance','service','repair','other')
,`title` varchar(150)
,`due_date` date
,`status` enum('planned','done','overdue')
,`days_left` int(8)
,`alert_level` int(1)
);

-- --------------------------------------------------------

--
-- Zastąpiona struktura widoku `v_worker_advances_details`
-- (See below for the actual view)
--
CREATE TABLE `v_worker_advances_details` (
`advance_id` bigint(20) unsigned
,`worker_id` bigint(20) unsigned
,`first_name` varchar(50)
,`last_name` varchar(50)
,`worker_name` varchar(101)
,`type` enum('PRIVATE','COMPANY')
,`amount` decimal(12,2)
,`amount_settled` decimal(34,2)
,`amount_remaining` decimal(35,2)
,`issue_date` date
,`salary_period` date
,`description` varchar(255)
,`status` enum('open','closed')
,`closed_at` binary(0)
,`closed_by` binary(0)
,`closed_by_name` binary(0)
,`closed_note` binary(0)
,`created_by_name` varchar(50)
,`created_at` datetime
,`ledger_entries_count` bigint(21)
,`files_count` bigint(21)
);

-- --------------------------------------------------------

--
-- Zastąpiona struktura widoku `v_worker_balance`
-- (See below for the actual view)
--
CREATE TABLE `v_worker_balance` (
`worker_id` bigint(20) unsigned
,`current_balance` decimal(34,2)
);

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `workers`
--

CREATE TABLE `workers` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `first_name` varchar(50) NOT NULL,
  `last_name` varchar(50) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `worker_type` enum('permanent','temporary','contractor') DEFAULT 'permanent',
  `notes` text DEFAULT NULL,
  `vacation_limit` int(11) DEFAULT 26 COMMENT 'Roczny limit urlopu (dni)',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `worker_advances`
--

CREATE TABLE `worker_advances` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `worker_id` bigint(20) UNSIGNED NOT NULL,
  `type` enum('PRIVATE','COMPANY') NOT NULL COMMENT 'PRIVATE: na życie, COMPANY: na wydatki firmowe',
  `amount` decimal(12,2) NOT NULL,
  `issue_date` date NOT NULL,
  `salary_period` date DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `status` enum('open','closed') NOT NULL DEFAULT 'open',
  `created_by` bigint(20) UNSIGNED NOT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `worker_advance_files`
--

CREATE TABLE `worker_advance_files` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `advance_id` bigint(20) UNSIGNED NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `original_name` varchar(255) DEFAULT NULL,
  `mime_type` varchar(120) DEFAULT NULL,
  `file_size` bigint(20) UNSIGNED DEFAULT NULL,
  `uploaded_at` datetime NOT NULL DEFAULT current_timestamp(),
  `uploaded_by_user_id` bigint(20) UNSIGNED DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `worker_documents`
--

CREATE TABLE `worker_documents` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `worker_id` bigint(20) UNSIGNED NOT NULL,
  `document_type_id` int(10) UNSIGNED NOT NULL,
  `title` varchar(255) NOT NULL,
  `document_number` varchar(120) DEFAULT NULL,
  `issuer` varchar(255) DEFAULT NULL,
  `valid_from` date DEFAULT NULL,
  `valid_to` date DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `reminder_days` int(11) DEFAULT NULL,
  `status` enum('active','archived') NOT NULL DEFAULT 'active',
  `active_unique_guard` tinyint(4) GENERATED ALWAYS AS (case when `status` = 'active' then 1 else NULL end) STORED,
  `archived_at` datetime DEFAULT NULL,
  `archived_reason` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `worker_document_files`
--

CREATE TABLE `worker_document_files` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `document_id` bigint(20) UNSIGNED NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `original_name` varchar(255) DEFAULT NULL,
  `mime_type` varchar(120) DEFAULT NULL,
  `file_size` bigint(20) UNSIGNED DEFAULT NULL,
  `uploaded_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `worker_expenses`
--

CREATE TABLE `worker_expenses` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `worker_id` bigint(20) UNSIGNED NOT NULL,
  `project_id` bigint(20) UNSIGNED DEFAULT NULL,
  `document_id` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'Opcjonalne powiązanie z fakturą w documents',
  `advance_id` bigint(20) UNSIGNED DEFAULT NULL,
  `cost_node_id` bigint(20) UNSIGNED DEFAULT NULL,
  `date` date NOT NULL,
  `period` date NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `description` varchar(255) NOT NULL,
  `receipt_path` varchar(255) DEFAULT NULL,
  `status` enum('pending','approved','rejected','reimbursed') NOT NULL DEFAULT 'pending',
  `expense_type` varchar(32) NOT NULL DEFAULT 'cash_other' COMMENT 'np. fuel, material, parking, cash_other',
  `company_category` varchar(120) DEFAULT NULL,
  `company_subcategory` varchar(120) DEFAULT NULL,
  `paid_by_employee` tinyint(1) NOT NULL DEFAULT 0,
  `created_by_user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `approved_by_user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `payment_source` enum('employee','wallet') DEFAULT NULL,
  `wallet_advance_id` bigint(20) UNSIGNED DEFAULT NULL,
  `wallet_ledger_id` bigint(20) UNSIGNED DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `worker_expense_advance_allocations`
--

CREATE TABLE `worker_expense_advance_allocations` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `worker_expense_id` bigint(20) UNSIGNED NOT NULL,
  `worker_advance_id` bigint(20) UNSIGNED NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Alokacje wydatku pracownika do zaliczek firmowych FIFO';

--
-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `worker_ledger`
--

CREATE TABLE `worker_ledger` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `worker_id` bigint(20) UNSIGNED NOT NULL,
  `entry_type` enum('ADVANCE','EXPENSE_DOC','CASH_RETURN','SETTLEMENT_DEDUCTION','MANUAL_COST') NOT NULL,
  `amount` decimal(12,2) NOT NULL COMMENT 'Ujemne: pracownik bierze (dług rośnie), Dodatnie: pracownik rozlicza/oddaje (dług maleje)',
  `entry_date` date NOT NULL,
  `advance_id` bigint(20) UNSIGNED DEFAULT NULL,
  `expense_id` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'Ref do worker_expenses',
  `document_id` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'Ref do documents (faktura)',
  `settlement_id` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'Ref do settlements (potrącenie z wypłaty)',
  `description` varchar(255) DEFAULT NULL,
  `created_by` bigint(20) UNSIGNED NOT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `worker_rates`
--

CREATE TABLE `worker_rates` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `worker_id` bigint(20) UNSIGNED NOT NULL,
  `scope_type` enum('GLOBAL','PROJECT','STAGE') NOT NULL DEFAULT 'GLOBAL' COMMENT 'Określa poziom ważności stawki',
  `project_id` bigint(20) UNSIGNED DEFAULT NULL,
  `cost_node_id` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'Dla stawek per Etap (scope_type=STAGE)',
  `base_rate` decimal(12,2) NOT NULL,
  `saturday_rate` decimal(12,2) DEFAULT NULL,
  `saturday_overtime_rate` decimal(12,2) DEFAULT NULL,
  `sunday_rate` decimal(12,2) DEFAULT NULL,
  `sunday_overtime_rate` decimal(12,2) DEFAULT NULL,
  `night_rate` decimal(12,2) DEFAULT NULL COMMENT 'Jawna stawka za nockę (PLN), nie mnożnik',
  `night_overtime_rate` decimal(12,2) DEFAULT NULL,
  `sick_rate` decimal(12,2) DEFAULT NULL COMMENT 'Stawka za L4',
  `vacation_rate` decimal(12,2) DEFAULT NULL COMMENT 'Stawka za Urlop',
  `overtime_rate` decimal(12,2) DEFAULT NULL,
  `delegation_rate` decimal(12,2) DEFAULT NULL,
  `delegation_overtime_rate` decimal(12,2) DEFAULT NULL,
  `valid_from` date NOT NULL,
  `valid_to` date DEFAULT NULL COMMENT 'NULL = aktualna. APP LOGIC: Przy dodaniu nowej, zamknij starą datą wczorajszą.'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `worker_wallet_funding`
--

CREATE TABLE `worker_wallet_funding` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `worker_id` bigint(20) UNSIGNED NOT NULL,
  `advance_id` bigint(20) UNSIGNED NOT NULL,
  `direction` enum('OUT_TOPUP','IN_RETURN') NOT NULL COMMENT 'OUT_TOPUP=wydanie z firmy, IN_RETURN=zwrot do firmy',
  `amount` decimal(12,2) NOT NULL,
  `source_kind` enum('cash','bank','other') NOT NULL DEFAULT 'cash',
  `source_ref` varchar(120) DEFAULT NULL,
  `note` varchar(255) DEFAULT NULL,
  `movement_date` date NOT NULL,
  `created_by` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `work_logs`
--

CREATE TABLE `work_logs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `worker_id` bigint(20) UNSIGNED NOT NULL,
  `project_id` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'Zalecane: Projekt "Wewnętrzny" zamiast NULL',
  `work_type` enum('work','sick','vacation') NOT NULL DEFAULT 'work' COMMENT 'Rodzaj wpisu: Praca, L4, Urlop',
  `cost_node_id` bigint(20) UNSIGNED DEFAULT NULL,
  `date` date NOT NULL,
  `period` date NOT NULL COMMENT '1. dzień miesiąca',
  `hours` decimal(6,2) NOT NULL DEFAULT 0.00,
  `workday_hours` decimal(5,2) DEFAULT 0.00 COMMENT 'Godziny robocze (standard)',
  `workday_overtime` decimal(5,2) DEFAULT 0.00 COMMENT 'Nadgodziny robocze (50%)',
  `saturday_hours` decimal(5,2) DEFAULT 0.00 COMMENT 'Godziny w sobotę',
  `saturday_overtime` decimal(5,2) DEFAULT 0.00 COMMENT 'Nadgodziny w sobotę',
  `sunday_hours` decimal(5,2) DEFAULT 0.00 COMMENT 'Godziny w niedzielę',
  `sunday_overtime` decimal(5,2) DEFAULT 0.00 COMMENT 'Nadgodziny w niedzielę',
  `night_hours` decimal(5,2) DEFAULT 0.00 COMMENT 'Godziny nocne',
  `night_overtime` decimal(5,2) DEFAULT 0.00 COMMENT 'Nadgodziny nocne',
  `delegation_hours` decimal(5,2) DEFAULT 0.00 COMMENT 'Godziny delegacji',
  `delegation_overtime` decimal(5,2) DEFAULT 0.00 COMMENT 'Nadgodziny delegacji',
  `vacation_hours` decimal(5,2) DEFAULT 0.00 COMMENT 'Urlop (w godzinach)',
  `sickleave_hours` decimal(5,2) DEFAULT 0.00 COMMENT 'Chorobowe (w godzinach)',
  `absence_days` decimal(4,2) DEFAULT NULL COMMENT 'Ilość dni (np. 1.0, 0.5). NULL dla zwykłej pracy',
  `is_paid` tinyint(1) DEFAULT 1 COMMENT '1 = Płatne przez firmę, 0 = Bezpłatne/ZUS (koszt 0 dla projektu)',
  `overtime_hours` decimal(6,2) NOT NULL DEFAULT 0.00,
  `description` text DEFAULT NULL,
  `is_weekend` tinyint(1) NOT NULL DEFAULT 0,
  `is_saturday` tinyint(1) NOT NULL DEFAULT 0,
  `is_sunday` tinyint(1) NOT NULL DEFAULT 0,
  `is_delegation` tinyint(1) NOT NULL DEFAULT 0,
  `is_night` tinyint(1) NOT NULL DEFAULT 0,
  `system_rate_snapshot` decimal(12,2) DEFAULT NULL COMMENT 'NULL jeśli pending, wypełniane przy approve',
  `system_cost` decimal(12,2) NOT NULL DEFAULT 0.00,
  `final_cost` decimal(12,2) DEFAULT NULL,
  `status` enum('pending','approved','locked') NOT NULL DEFAULT 'pending',
  `created_by_user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `approved_by_user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `locked_by_user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `locked_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
--
-- Wyzwalacze `work_logs`
--
DELIMITER $$
CREATE TRIGGER `before_insert_work_logs_validate` BEFORE INSERT ON `work_logs` FOR EACH ROW BEGIN
    IF NEW.workday_hours < 0 OR NEW.workday_overtime < 0 OR
       NEW.saturday_hours < 0 OR NEW.saturday_overtime < 0 OR
       NEW.sunday_hours < 0 OR NEW.sunday_overtime < 0 OR
       NEW.night_hours < 0 OR NEW.night_overtime < 0 OR
       NEW.delegation_hours < 0 OR NEW.delegation_overtime < 0 OR
       NEW.vacation_hours < 0 OR NEW.sickleave_hours < 0 THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'BŁĄD DANYCH: Godziny w work_logs nie mogą być ujemne.';
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `before_update_work_logs_validate` BEFORE UPDATE ON `work_logs` FOR EACH ROW BEGIN
    IF NEW.workday_hours < 0 OR NEW.workday_overtime < 0 OR
       NEW.saturday_hours < 0 OR NEW.saturday_overtime < 0 OR
       NEW.sunday_hours < 0 OR NEW.sunday_overtime < 0 OR
       NEW.night_hours < 0 OR NEW.night_overtime < 0 OR
       NEW.delegation_hours < 0 OR NEW.delegation_overtime < 0 OR
       NEW.vacation_hours < 0 OR NEW.sickleave_hours < 0 THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'BŁĄD DANYCH: Godziny w work_logs nie mogą być ujemne.';
    END IF;
END
$$
DELIMITER ;

--
-- Indeksy dla zrzutów tabel
--

--
-- Indeksy dla tabeli `assets`
--
ALTER TABLE `assets`
  ADD PRIMARY KEY (`id`);

--
-- Indeksy dla tabeli `asset_bookings`
--
ALTER TABLE `asset_bookings`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_booking_period` (`asset_id`,`start_date`,`end_date`);

--
-- Indeksy dla tabeli `asset_events`
--
ALTER TABLE `asset_events`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_asset_due` (`asset_id`,`due_date`);

--
-- Indeksy dla tabeli `asset_static_documents`
--
ALTER TABLE `asset_static_documents`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_static_doc_asset` (`asset_id`);

--
-- Indeksy dla tabeli `company_cost_categories`
--
ALTER TABLE `company_cost_categories`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_company_cost_categories_key` (`category_key`),
  ADD KEY `idx_company_cost_categories_active` (`is_active`,`sort_order`,`name`);

--
-- Indeksy dla tabeli `company_cost_subcategories`
--
ALTER TABLE `company_cost_subcategories`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_company_cost_subcategories_name` (`category_key`,`name`),
  ADD KEY `idx_company_cost_subcategories_category` (`category_key`,`sort_order`,`name`);

--
-- Indeksy dla tabeli `company_settings`
--
ALTER TABLE `company_settings`
  ADD PRIMARY KEY (`id`);

--
-- Indeksy dla tabeli `cost_allocations`
--
ALTER TABLE `cost_allocations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_alloc_inv` (`invoice_id`),
  ADD KEY `fk_alloc_proj` (`project_id`),
  ADD KEY `fk_alloc_node` (`cost_node_id`);

--
-- Indeksy dla tabeli `documents`
--
ALTER TABLE `documents`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_vendor_number` (`vendor_id`,`number`),
  ADD UNIQUE KEY `uq_source_number_date` (`source_name`,`number`,`issue_date`),
  ADD KEY `idx_doc_status` (`status`),
  ADD KEY `idx_doc_type` (`type`),
  ADD KEY `idx_doc_dates` (`issue_date`,`due_date`),
  ADD KEY `idx_doc_vendor` (`vendor_id`),
  ADD KEY `fk_docs_project` (`project_id`),
  ADD KEY `fk_docs_worker` (`worker_id`),
  ADD KEY `fk_docs_creator` (`created_by`);

--
-- Indeksy dla tabeli `document_allocations`
--
ALTER TABLE `document_allocations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_alloc_doc` (`document_id`),
  ADD KEY `idx_alloc_project` (`project_id`),
  ADD KEY `idx_alloc_node` (`cost_node_id`),
  ADD KEY `idx_alloc_category` (`category`);

--
-- Indeksy dla tabeli `document_items`
--
ALTER TABLE `document_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_doc_item_document` (`document_id`),
  ADD KEY `idx_doc_item_sort` (`sort_order`);

--
-- Indeksy dla tabeli `document_item_allocations`
--
ALTER TABLE `document_item_allocations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_dia_item` (`document_item_id`),
  ADD KEY `idx_dia_project` (`project_id`),
  ADD KEY `idx_dia_cost_node` (`cost_node_id`),
  ADD KEY `fk_dia_created_by` (`created_by`);

--
-- Indeksy dla tabeli `document_types`
--
ALTER TABLE `document_types`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`);

--
-- Indeksy dla tabeli `erp_products`
--
ALTER TABLE `erp_products`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_erp_products_name` (`name`),
  ADD KEY `idx_erp_products_active` (`is_active`),
  ADD KEY `idx_erp_products_code` (`code`);

--
-- Indeksy dla tabeli `fakturownia_api_log`
--
ALTER TABLE `fakturownia_api_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_created_at` (`created_at`),
  ADD KEY `idx_http_status` (`http_status`);

--
-- Indeksy dla tabeli `fakturownia_archive_files`
--
ALTER TABLE `fakturownia_archive_files`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_archive_source_kind` (`source_type`,`source_local_id`,`file_kind`),
  ADD KEY `idx_fakturownia_id` (`fakturownia_id`),
  ADD KEY `idx_storage_tier` (`storage_tier`),
  ADD KEY `idx_document_date` (`document_date`);

--
-- Indeksy dla tabeli `fakturownia_clients`
--
ALTER TABLE `fakturownia_clients`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_erp_client` (`erp_client_id`),
  ADD KEY `idx_fakturownia_id` (`fakturownia_id`);

--
-- Indeksy dla tabeli `fakturownia_cost_allocations`
--
ALTER TABLE `fakturownia_cost_allocations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_cost_alloc_invoice` (`cost_invoice_id`),
  ADD KEY `idx_cost_alloc_project` (`project_id`),
  ADD KEY `idx_cost_alloc_node` (`cost_node_id`),
  ADD KEY `idx_cost_alloc_user` (`created_by_user_id`),
  ADD KEY `idx_cost_alloc_position` (`source_position_id`),
  ADD KEY `idx_fca_company_subcategory` (`company_cost_subcategory`);

--
-- Indeksy dla tabeli `fakturownia_cost_invoices`
--
ALTER TABLE `fakturownia_cost_invoices`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_cost_fakturownia_id` (`fakturownia_id`),
  ADD UNIQUE KEY `uq_cost_ksef_number` (`ksef_number`),
  ADD KEY `idx_cost_workflow_status` (`workflow_status`),
  ADD KEY `idx_cost_supplier_nip` (`supplier_nip`),
  ADD KEY `idx_cost_issue_date` (`issue_date`),
  ADD KEY `idx_cost_imported_at` (`imported_at`),
  ADD KEY `idx_cost_decided_by` (`decided_by_user_id`);

--
-- Indeksy dla tabeli `fakturownia_cost_status_history`
--
ALTER TABLE `fakturownia_cost_status_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_cost_hist_invoice` (`cost_invoice_id`),
  ADD KEY `idx_cost_hist_changed_at` (`changed_at`),
  ADD KEY `fk_cost_hist_user` (`changed_by_user_id`);

--
-- Indeksy dla tabeli `fakturownia_invoices`
--
ALTER TABLE `fakturownia_invoices`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_request_hash` (`request_hash`),
  ADD UNIQUE KEY `uq_fakturownia_id` (`fakturownia_id`),
  ADD KEY `idx_erp_contract` (`erp_contract_id`),
  ADD KEY `idx_gov_status` (`gov_status`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_erp_invoice_sale_id` (`erp_invoice_sale_id`);

--
-- Indeksy dla tabeli `fakturownia_products`
--
ALTER TABLE `fakturownia_products`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_fakturownia_product_id` (`fakturownia_product_id`),
  ADD KEY `idx_code` (`code`),
  ADD KEY `idx_is_active` (`is_active`);

--
-- Indeksy dla tabeli `finance_items`
--
ALTER TABLE `finance_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_fi_project` (`project_id`),
  ADD KEY `idx_fi_company` (`company_id`),
  ADD KEY `idx_fi_creator` (`created_by`),
  ADD KEY `idx_finance_items_subcategory` (`subcategory`);

--
-- Indeksy dla tabeli `hb_accounts`
--
ALTER TABLE `hb_accounts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_hba_household` (`household_id`);

--
-- Indeksy dla tabeli `hb_bills`
--
ALTER TABLE `hb_bills`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_hbb_household` (`household_id`),
  ADD KEY `fk_hbb_category` (`category_id`),
  ADD KEY `fk_hbb_account` (`default_account_id`);

--
-- Indeksy dla tabeli `hb_bill_items`
--
ALTER TABLE `hb_bill_items`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_bill_period` (`bill_id`,`period`),
  ADD KEY `idx_hbbi_household_due` (`household_id`,`due_date`),
  ADD KEY `idx_hbbi_period` (`period`);

--
-- Indeksy dla tabeli `hb_budgets`
--
ALTER TABLE `hb_budgets`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_budget_cat_period` (`household_id`,`category_id`,`period`),
  ADD KEY `fk_hbbud_category` (`category_id`),
  ADD KEY `idx_hbbud_household_period` (`household_id`,`period`);

--
-- Indeksy dla tabeli `hb_categories`
--
ALTER TABLE `hb_categories`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_hbc_household` (`household_id`);

--
-- Indeksy dla tabeli `hb_households`
--
ALTER TABLE `hb_households`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_hb_household_owner` (`owner_user_id`);

--
-- Indeksy dla tabeli `hb_household_members`
--
ALTER TABLE `hb_household_members`
  ADD PRIMARY KEY (`household_id`,`user_id`),
  ADD KEY `idx_hbhm_user` (`user_id`);

--
-- Indeksy dla tabeli `hb_transactions`
--
ALTER TABLE `hb_transactions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_hbt_household_category_period` (`household_id`,`category_id`,`period`),
  ADD KEY `idx_hbt_date` (`date`),
  ADD KEY `idx_hbt_account` (`account_id`),
  ADD KEY `fk_hbt_account_target` (`transfer_account_id`),
  ADD KEY `fk_hbt_category` (`category_id`),
  ADD KEY `fk_hbt_bill_item` (`bill_item_id`),
  ADD KEY `fk_hbt_created_by` (`created_by`),
  ADD KEY `idx_visibility` (`visibility`),
  ADD KEY `idx_owner_user_id` (`owner_user_id`),
  ADD KEY `idx_include_total` (`include_in_household_total`);

--
-- Indeksy dla tabeli `hr_alerts`
--
ALTER TABLE `hr_alerts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_doc_type` (`document_id`,`alert_type`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_remind_at` (`remind_at`),
  ADD KEY `idx_due_date` (`due_date`),
  ADD KEY `idx_worker` (`worker_id`);

--
-- Indeksy dla tabeli `investors`
--
ALTER TABLE `investors`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_investor_name` (`name`),
  ADD KEY `idx_investors_type` (`type`);

--
-- Indeksy dla tabeli `investor_notes`
--
ALTER TABLE `investor_notes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_inv_notes_investor` (`investor_id`);

--
-- Indeksy dla tabeli `investor_reminders`
--
ALTER TABLE `investor_reminders`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_inv_rem_investor` (`investor_id`),
  ADD KEY `idx_remind_at_done` (`remind_at`,`is_done`);

--
-- Indeksy dla tabeli `invoices`
--
ALTER TABLE `invoices`
  ADD PRIMARY KEY (`id`);

--
-- Indeksy dla tabeli `invoices_sale`
--
ALTER TABLE `invoices_sale`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_invoice_number` (`invoice_number`),
  ADD KEY `idx_isale_client` (`client_id`),
  ADD KEY `idx_isale_dates` (`issue_date`,`due_date`),
  ADD KEY `idx_isale_status` (`status`),
  ADD KEY `fk_isale_creator` (`created_by`),
  ADD KEY `idx_source_system_external` (`source_system`,`source_external_id`),
  ADD KEY `idx_document_kind` (`document_kind`),
  ADD KEY `idx_invoices_sale_deleted_at` (`deleted_at`);

--
-- Indeksy dla tabeli `invoice_audit_log`
--
ALTER TABLE `invoice_audit_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_invoice_audit_invoice` (`invoice_sale_id`),
  ADD KEY `idx_invoice_audit_action` (`action`),
  ADD KEY `idx_invoice_audit_created_at` (`created_at`);

--
-- Indeksy dla tabeli `invoice_sale_allocations`
--
ALTER TABLE `invoice_sale_allocations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_isa_invoice` (`invoice_id`),
  ADD KEY `idx_isa_project` (`project_id`);

--
-- Indeksy dla tabeli `invoice_sale_items`
--
ALTER TABLE `invoice_sale_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_isale_item_invoice` (`invoice_id`),
  ADD KEY `idx_isale_item_project` (`project_id`),
  ADD KEY `idx_isale_item_node` (`cost_node_id`);

--
-- Indeksy dla tabeli `invoice_sale_jst_data`
--
ALTER TABLE `invoice_sale_jst_data`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_invoice_sale_jst` (`invoice_sale_id`);

--
-- Indeksy dla tabeli `invoice_sale_payments`
--
ALTER TABLE `invoice_sale_payments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_isp_invoice_id` (`invoice_id`),
  ADD KEY `idx_isp_payment_date` (`payment_date`);

--
-- Indeksy dla tabeli `invoice_sale_retentions`
--
ALTER TABLE `invoice_sale_retentions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_retention_invoice` (`invoice_id`),
  ADD KEY `idx_retention_status` (`status`),
  ADD KEY `idx_retention_return_date` (`return_date`),
  ADD KEY `idx_retention_reminder_date` (`reminder_date`),
  ADD KEY `fk_retention_settled_by` (`settled_by_user_id`),
  ADD KEY `fk_retention_created_by` (`created_by_user_id`);

--
-- Indeksy dla tabeli `projects`
--
ALTER TABLE `projects`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_project_investor` (`investor_id`),
  ADD KEY `idx_projects_archived` (`archived_at`),
  ADD KEY `idx_project_type` (`project_type`);

--
-- Indeksy dla tabeli `project_cost_nodes`
--
ALTER TABLE `project_cost_nodes`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_node_name` (`project_id`,`parent_id`,`name`),
  ADD KEY `fk_nodes_parent` (`parent_id`);

--
-- Indeksy dla tabeli `project_revenues`
--
ALTER TABLE `project_revenues`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_rev_project` (`project_id`),
  ADD KEY `idx_rev_cost_node` (`cost_node_id`);

--
-- Indeksy dla tabeli `push_notifications_log`
--
ALTER TABLE `push_notifications_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_push_log_user` (`user_id`),
  ADD KEY `fk_push_log_subscription` (`subscription_id`);

--
-- Indeksy dla tabeli `push_subscriptions`
--
ALTER TABLE `push_subscriptions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_push_user_endpoint` (`user_id`,`endpoint`(255)),
  ADD KEY `idx_push_user_active` (`user_id`,`is_active`);

--
-- Indeksy dla tabeli `sales_noninvoice_allocations`
--
ALTER TABLE `sales_noninvoice_allocations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_snia_entry` (`entry_id`),
  ADD KEY `idx_snia_project` (`project_id`);

--
-- Indeksy dla tabeli `sales_noninvoice_entries`
--
ALTER TABLE `sales_noninvoice_entries`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_snie_entry_number` (`entry_number`),
  ADD KEY `idx_snie_client` (`client_id`),
  ADD KEY `idx_snie_issue_date` (`issue_date`),
  ADD KEY `idx_snie_status` (`payment_status`),
  ADD KEY `idx_snie_counterparty_manual` (`counterparty_name_manual`);

--
-- Indeksy dla tabeli `settlements`
--
ALTER TABLE `settlements`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_settl_worker_period` (`worker_id`,`period`),
  ADD KEY `fk_settl_exp` (`linked_expense_id`),
  ADD KEY `fk_settl_creator` (`created_by_user_id`);

--
-- Indeksy dla tabeli `system_settings`
--
ALTER TABLE `system_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `setting_key` (`setting_key`);

--
-- Indeksy dla tabeli `tasks`
--
ALTER TABLE `tasks`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_active` (`is_active`),
  ADD KEY `idx_due_date` (`due_date`),
  ADD KEY `fk_tasks_creator` (`created_by`);

--
-- Indeksy dla tabeli `task_assignments`
--
ALTER TABLE `task_assignments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_task_worker` (`task_id`,`worker_id`),
  ADD KEY `idx_worker_status` (`worker_id`,`status`),
  ADD KEY `idx_status` (`status`);

--
-- Indeksy dla tabeli `task_attachments`
--
ALTER TABLE `task_attachments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_task_files` (`task_id`);

--
-- Indeksy dla tabeli `task_comments`
--
ALTER TABLE `task_comments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `worker_id` (`worker_id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `idx_task_id` (`task_id`),
  ADD KEY `idx_created_at` (`created_at`);

--
-- Indeksy dla tabeli `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_users_login` (`login`),
  ADD UNIQUE KEY `uk_users_worker` (`worker_id`),
  ADD KEY `idx_users_is_active` (`is_active`);

--
-- Indeksy dla tabeli `workers`
--
ALTER TABLE `workers`
  ADD PRIMARY KEY (`id`);

--
-- Indeksy dla tabeli `worker_advances`
--
ALTER TABLE `worker_advances`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_adv_creator` (`created_by`),
  ADD KEY `idx_advances_worker_status` (`worker_id`,`status`),
  ADD KEY `idx_adv_type_salary_period_worker` (`type`,`salary_period`,`worker_id`);

--
-- Indeksy dla tabeli `worker_advance_files`
--
ALTER TABLE `worker_advance_files`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_advance_files_advance` (`advance_id`),
  ADD KEY `fk_advance_files_uploader` (`uploaded_by_user_id`);

--
-- Indeksy dla tabeli `worker_documents`
--
ALTER TABLE `worker_documents`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_one_active_doc_per_title` (`worker_id`,`document_type_id`,`title`(100),`active_unique_guard`),
  ADD KEY `idx_worker` (`worker_id`),
  ADD KEY `idx_type` (`document_type_id`),
  ADD KEY `idx_valid_to` (`valid_to`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_worker_type_status_archived` (`worker_id`,`document_type_id`,`status`);

--
-- Indeksy dla tabeli `worker_document_files`
--
ALTER TABLE `worker_document_files`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_document` (`document_id`);

--
-- Indeksy dla tabeli `worker_expenses`
--
ALTER TABLE `worker_expenses`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_exp_proj_node_period` (`project_id`,`cost_node_id`,`period`),
  ADD KEY `idx_exp_worker` (`worker_id`),
  ADD KEY `fk_exp_node` (`cost_node_id`),
  ADD KEY `fk_exp_creator` (`created_by_user_id`),
  ADD KEY `fk_exp_approver` (`approved_by_user_id`),
  ADD KEY `fk_exp_document` (`document_id`),
  ADD KEY `idx_worker_expenses_status` (`status`),
  ADD KEY `fk_exp_advance` (`advance_id`),
  ADD KEY `idx_worker_expenses_payment_source` (`payment_source`),
  ADD KEY `idx_worker_expenses_wallet_advance` (`wallet_advance_id`),
  ADD KEY `idx_worker_expenses_wallet_ledger` (`wallet_ledger_id`),
  ADD KEY `idx_worker_expenses_company_category` (`company_category`),
  ADD KEY `idx_worker_expenses_company_subcategory` (`company_subcategory`);

--
-- Indeksy dla tabeli `worker_expense_advance_allocations`
--
ALTER TABLE `worker_expense_advance_allocations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_weaa_expense` (`worker_expense_id`),
  ADD KEY `idx_weaa_advance` (`worker_advance_id`);

--
-- Indeksy dla tabeli `worker_ledger`
--
ALTER TABLE `worker_ledger`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_ledger_worker_date` (`worker_id`,`entry_date`),
  ADD KEY `fk_ledger_creator` (`created_by`),
  ADD KEY `idx_ledger_advance` (`advance_id`);

--
-- Indeksy dla tabeli `worker_rates`
--
ALTER TABLE `worker_rates`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_rates_worker` (`worker_id`,`valid_from`),
  ADD KEY `fk_rates_proj` (`project_id`),
  ADD KEY `fk_rates_cost_node` (`cost_node_id`);

--
-- Indeksy dla tabeli `worker_wallet_funding`
--
ALTER TABLE `worker_wallet_funding`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_wallet_funding_worker_date` (`worker_id`,`movement_date`),
  ADD KEY `idx_wallet_funding_advance` (`advance_id`),
  ADD KEY `idx_wallet_funding_direction_date` (`direction`,`movement_date`),
  ADD KEY `fk_wallet_funding_user` (`created_by`);

--
-- Indeksy dla tabeli `work_logs`
--
ALTER TABLE `work_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_logs_period_worker` (`period`,`worker_id`),
  ADD KEY `idx_logs_status` (`status`),
  ADD KEY `idx_logs_proj_period` (`project_id`,`period`),
  ADD KEY `idx_logs_proj_node_period` (`project_id`,`cost_node_id`,`period`),
  ADD KEY `fk_logs_worker` (`worker_id`),
  ADD KEY `fk_logs_creator` (`created_by_user_id`),
  ADD KEY `fk_logs_approver` (`approved_by_user_id`),
  ADD KEY `fk_logs_locker` (`locked_by_user_id`),
  ADD KEY `idx_work_logs_cost_node_id` (`cost_node_id`);

--
-- AUTO_INCREMENT dla zrzuconych tabel
--

--
-- AUTO_INCREMENT dla tabeli `assets`
--
ALTER TABLE `assets`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT dla tabeli `asset_bookings`
--
ALTER TABLE `asset_bookings`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT dla tabeli `asset_events`
--
ALTER TABLE `asset_events`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT dla tabeli `asset_static_documents`
--
ALTER TABLE `asset_static_documents`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT dla tabeli `company_cost_categories`
--
ALTER TABLE `company_cost_categories`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4474;

--
-- AUTO_INCREMENT dla tabeli `company_cost_subcategories`
--
ALTER TABLE `company_cost_subcategories`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=35;

--
-- AUTO_INCREMENT dla tabeli `company_settings`
--
ALTER TABLE `company_settings`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT dla tabeli `cost_allocations`
--
ALTER TABLE `cost_allocations`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT dla tabeli `documents`
--
ALTER TABLE `documents`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT dla tabeli `document_allocations`
--
ALTER TABLE `document_allocations`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT dla tabeli `document_items`
--
ALTER TABLE `document_items`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT dla tabeli `document_item_allocations`
--
ALTER TABLE `document_item_allocations`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT dla tabeli `document_types`
--
ALTER TABLE `document_types`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT dla tabeli `erp_products`
--
ALTER TABLE `erp_products`
  MODIFY `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=397;

--
-- AUTO_INCREMENT dla tabeli `fakturownia_api_log`
--
ALTER TABLE `fakturownia_api_log`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2367;

--
-- AUTO_INCREMENT dla tabeli `fakturownia_archive_files`
--
ALTER TABLE `fakturownia_archive_files`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT dla tabeli `fakturownia_clients`
--
ALTER TABLE `fakturownia_clients`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=205;

--
-- AUTO_INCREMENT dla tabeli `fakturownia_cost_allocations`
--
ALTER TABLE `fakturownia_cost_allocations`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=67;

--
-- AUTO_INCREMENT dla tabeli `fakturownia_cost_invoices`
--
ALTER TABLE `fakturownia_cost_invoices`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=199;

--
-- AUTO_INCREMENT dla tabeli `fakturownia_cost_status_history`
--
ALTER TABLE `fakturownia_cost_status_history`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=54;

--
-- AUTO_INCREMENT dla tabeli `fakturownia_invoices`
--
ALTER TABLE `fakturownia_invoices`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=895;

--
-- AUTO_INCREMENT dla tabeli `fakturownia_products`
--
ALTER TABLE `fakturownia_products`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1070;

--
-- AUTO_INCREMENT dla tabeli `finance_items`
--
ALTER TABLE `finance_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=42;

--
-- AUTO_INCREMENT dla tabeli `hb_accounts`
--
ALTER TABLE `hb_accounts`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT dla tabeli `hb_bills`
--
ALTER TABLE `hb_bills`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT dla tabeli `hb_bill_items`
--
ALTER TABLE `hb_bill_items`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT dla tabeli `hb_budgets`
--
ALTER TABLE `hb_budgets`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT dla tabeli `hb_categories`
--
ALTER TABLE `hb_categories`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;

--
-- AUTO_INCREMENT dla tabeli `hb_households`
--
ALTER TABLE `hb_households`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT dla tabeli `hb_transactions`
--
ALTER TABLE `hb_transactions`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=22;

--
-- AUTO_INCREMENT dla tabeli `hr_alerts`
--
ALTER TABLE `hr_alerts`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT dla tabeli `investors`
--
ALTER TABLE `investors`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=190;

--
-- AUTO_INCREMENT dla tabeli `investor_notes`
--
ALTER TABLE `investor_notes`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT dla tabeli `investor_reminders`
--
ALTER TABLE `investor_reminders`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT dla tabeli `invoices`
--
ALTER TABLE `invoices`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT dla tabeli `invoices_sale`
--
ALTER TABLE `invoices_sale`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=906;

--
-- AUTO_INCREMENT dla tabeli `invoice_audit_log`
--
ALTER TABLE `invoice_audit_log`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=55;

--
-- AUTO_INCREMENT dla tabeli `invoice_sale_allocations`
--
ALTER TABLE `invoice_sale_allocations`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT dla tabeli `invoice_sale_items`
--
ALTER TABLE `invoice_sale_items`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1293;

--
-- AUTO_INCREMENT dla tabeli `invoice_sale_jst_data`
--
ALTER TABLE `invoice_sale_jst_data`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT dla tabeli `invoice_sale_payments`
--
ALTER TABLE `invoice_sale_payments`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1058;

--
-- AUTO_INCREMENT dla tabeli `invoice_sale_retentions`
--
ALTER TABLE `invoice_sale_retentions`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT dla tabeli `projects`
--
ALTER TABLE `projects`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=42;

--
-- AUTO_INCREMENT dla tabeli `project_cost_nodes`
--
ALTER TABLE `project_cost_nodes`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=46;

--
-- AUTO_INCREMENT dla tabeli `project_revenues`
--
ALTER TABLE `project_revenues`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=40;

--
-- AUTO_INCREMENT dla tabeli `push_notifications_log`
--
ALTER TABLE `push_notifications_log`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT dla tabeli `push_subscriptions`
--
ALTER TABLE `push_subscriptions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT dla tabeli `sales_noninvoice_allocations`
--
ALTER TABLE `sales_noninvoice_allocations`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=19;

--
-- AUTO_INCREMENT dla tabeli `sales_noninvoice_entries`
--
ALTER TABLE `sales_noninvoice_entries`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT dla tabeli `settlements`
--
ALTER TABLE `settlements`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=79;

--
-- AUTO_INCREMENT dla tabeli `system_settings`
--
ALTER TABLE `system_settings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT dla tabeli `tasks`
--
ALTER TABLE `tasks`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT dla tabeli `task_assignments`
--
ALTER TABLE `task_assignments`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT dla tabeli `task_attachments`
--
ALTER TABLE `task_attachments`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT dla tabeli `task_comments`
--
ALTER TABLE `task_comments`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT dla tabeli `users`
--
ALTER TABLE `users`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT dla tabeli `workers`
--
ALTER TABLE `workers`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=19;

--
-- AUTO_INCREMENT dla tabeli `worker_advances`
--
ALTER TABLE `worker_advances`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=93;

--
-- AUTO_INCREMENT dla tabeli `worker_advance_files`
--
ALTER TABLE `worker_advance_files`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT dla tabeli `worker_documents`
--
ALTER TABLE `worker_documents`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT dla tabeli `worker_document_files`
--
ALTER TABLE `worker_document_files`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT dla tabeli `worker_expenses`
--
ALTER TABLE `worker_expenses`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=33;

--
-- AUTO_INCREMENT dla tabeli `worker_expense_advance_allocations`
--
ALTER TABLE `worker_expense_advance_allocations`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;

--
-- AUTO_INCREMENT dla tabeli `worker_ledger`
--
ALTER TABLE `worker_ledger`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=138;

--
-- AUTO_INCREMENT dla tabeli `worker_rates`
--
ALTER TABLE `worker_rates`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT dla tabeli `worker_wallet_funding`
--
ALTER TABLE `worker_wallet_funding`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=24;

--
-- AUTO_INCREMENT dla tabeli `work_logs`
--
ALTER TABLE `work_logs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=790;

-- --------------------------------------------------------

--
-- Struktura widoku `view_finance_ledger`
--
DROP TABLE IF EXISTS `view_finance_ledger`;

CREATE ALGORITHM=UNDEFINED DEFINER=`brygad`@`localhost` SQL SECURITY DEFINER VIEW `view_finance_ledger`  AS SELECT 'cash' FROM (`worker_expenses` `we` left join `workers` `w` on(`w`.`id` = `we`.`worker_id`)) WHERE `we`.`status` = 'approved' AND `we`.`document_id` is nullunion allselect 'labor' collate utf8mb4_unicode_ci AS `ledger_source`,`wl`.`id` AS `source_id`,NULL AS `allocation_id`,`wl`.`date` AS `date`,`wl`.`period` AS `period`,coalesce(`wl`.`project_id`,1) AS `project_id`,`wl`.`cost_node_id` AS `cost_node_id`,`wl`.`final_cost` AS `amount`,cast(concat('Robocizna: ',`w`.`first_name`,' ',`w`.`last_name`) as char(255) charset utf8mb4) collate utf8mb4_unicode_ci AS `counterparty_name`,cast(`wl`.`description` as char(500) charset utf8mb4) collate utf8mb4_unicode_ci AS `description` from (`work_logs` `wl` left join `workers` `w` on(`w`.`id` = `wl`.`worker_id`)) where `wl`.`status` = 'approved' union all select 'invoice_cost_legacy' collate utf8mb4_unicode_ci AS `ledger_source`,`d`.`id` AS `source_id`,`da`.`id` AS `allocation_id`,`d`.`issue_date` AS `date`,cast(date_format(`d`.`issue_date`,'%Y-%m-01') as date) AS `period`,coalesce(`da`.`project_id`,`d`.`project_id`,1) AS `project_id`,`da`.`cost_node_id` AS `cost_node_id`,`da`.`amount_net` AS `amount`,cast(coalesce(`i`.`name`,`d`.`source_name`) as char(255) charset utf8mb4) collate utf8mb4_unicode_ci AS `counterparty_name`,cast(concat(`d`.`number`,' | ',coalesce(`da`.`description`,'')) as char(500) charset utf8mb4) collate utf8mb4_unicode_ci AS `description` from ((`document_allocations` `da` join `documents` `d` on(`da`.`document_id` = `d`.`id`)) left join `investors` `i` on(`i`.`id` = `d`.`vendor_id`)) where `d`.`type` = 'invoice_cost' and `d`.`status` = 'approved' and `da`.`is_legacy` = 1 union all select 'invoice_cost' collate utf8mb4_unicode_ci AS `ledger_source`,`di`.`id` AS `source_id`,`dia`.`id` AS `allocation_id`,`d`.`issue_date` AS `date`,cast(date_format(`d`.`issue_date`,'%Y-%m-01') as date) AS `period`,`dia`.`project_id` AS `project_id`,`dia`.`cost_node_id` AS `cost_node_id`,`dia`.`amount` AS `amount`,cast(coalesce(`i`.`name`,`d`.`source_name`) as char(255) charset utf8mb4) collate utf8mb4_unicode_ci AS `counterparty_name`,cast(concat(`d`.`number`,' | ',`di`.`item_name`,' | ',coalesce(`dia`.`notes`,'')) as char(500) charset utf8mb4) collate utf8mb4_unicode_ci AS `description` from (((`document_item_allocations` `dia` join `document_items` `di` on(`dia`.`document_item_id` = `di`.`id`)) join `documents` `d` on(`di`.`document_id` = `d`.`id`)) left join `investors` `i` on(`i`.`id` = `d`.`vendor_id`)) where `d`.`type` = 'invoice_cost' and `d`.`status` = 'approved' union all select 'fixed' collate utf8mb4_unicode_ci AS `ledger_source`,`fi`.`id` AS `source_id`,NULL AS `allocation_id`,`fi`.`issue_date` AS `date`,cast(date_format(`fi`.`issue_date`,'%Y-%m-01') as date) AS `period`,coalesce(`fi`.`project_id`,1) AS `project_id`,`fi`.`etap_id` AS `cost_node_id`,`fi`.`amount_gross` AS `amount`,cast(coalesce(`fi`.`company_name`,'Koszt stały') as char(255) charset utf8mb4) collate utf8mb4_unicode_ci AS `counterparty_name`,cast(`fi`.`title` as char(500) charset utf8mb4) collate utf8mb4_unicode_ci AS `description` from `finance_items` `fi` where `fi`.`item_type` = 'FIXED_COST' and `fi`.`status` = 'approved'  ;

-- --------------------------------------------------------

--
-- Struktura widoku `view_project_finances`
--
DROP TABLE IF EXISTS `view_project_finances`;

CREATE ALGORITHM=UNDEFINED DEFINER=`brygad`@`localhost` SQL SECURITY DEFINER VIEW `view_project_finances`  AS SELECT `p`.`id` AS `project_id`, `p`.`name` AS `project_name`, `p`.`status` AS `status`, coalesce(sum(`r`.`amount_net`),0) AS `total_revenue`, (select coalesce(sum(coalesce(`wl`.`final_cost`,`wl`.`system_cost`,0)),0) from `work_logs` `wl` where `wl`.`project_id` = `p`.`id` and `wl`.`status` = 'approved') AS `total_labor_cost`, (select coalesce(sum(`ca`.`amount`),0) from `cost_allocations` `ca` where `ca`.`project_id` = `p`.`id`) AS `total_material_cost`, coalesce(sum(`r`.`amount_net`),0) - (select coalesce(sum(coalesce(`wl`.`final_cost`,`wl`.`system_cost`,0)),0) from `work_logs` `wl` where `wl`.`project_id` = `p`.`id` and `wl`.`status` = 'approved') - (select coalesce(sum(`ca`.`amount`),0) from `cost_allocations` `ca` where `ca`.`project_id` = `p`.`id`) AS `current_profit` FROM (`projects` `p` left join `project_revenues` `r` on(`r`.`project_id` = `p`.`id`)) GROUP BY `p`.`id`, `p`.`name`, `p`.`status` ;

-- --------------------------------------------------------

--
-- Struktura widoku `view_project_finance_documents`
--
DROP TABLE IF EXISTS `view_project_finance_documents`;

CREATE ALGORITHM=UNDEFINED DEFINER=`brygad`@`localhost` SQL SECURITY DEFINER VIEW `view_project_finance_documents`  AS SELECT `d`.`id` AS `item_id`, 'documents' AS `source_table`, 'INVOICE_COST' AS `item_type`, `d`.`project_id` AS `project_id`, NULL AS `etap_id`, coalesce(`inv`.`name`,`d`.`source_name`) AS `company_name`, concat('Faktura ',`d`.`number`) AS `title`, `d`.`description` AS `description`, `d`.`number` AS `doc_number`, `d`.`issue_date` AS `issue_date`, `d`.`amount_net` AS `amount_net`, `d`.`amount_gross` AS `amount_gross`, `d`.`currency` AS `currency`, `d`.`file_path` AS `file_path`, `d`.`status` AS `status`, 'cost' AS `cost_category`, `d`.`created_at` AS `created_at`, `d`.`created_by` AS `created_by`, concat('/documents/edit.php?id=',`d`.`id`) AS `view_url` FROM (`documents` `d` left join `investors` `inv` on(`inv`.`id` = `d`.`vendor_id`)) WHERE `d`.`type` = 'invoice_cost'union all select `fi`.`id` AS `id`,'finance_items' AS `finance_items`,`fi`.`item_type` AS `item_type`,`fi`.`project_id` AS `project_id`,`fi`.`etap_id` AS `etap_id`,coalesce(`inv`.`name`,`fi`.`company_name`) AS `COALESCE(inv.name, fi.company_name)`,`fi`.`title` AS `title`,`fi`.`description` AS `description`,`fi`.`doc_number` AS `doc_number`,`fi`.`issue_date` AS `issue_date`,`fi`.`amount_net` AS `amount_net`,`fi`.`amount_gross` AS `amount_gross`,`fi`.`currency` AS `currency`,`fi`.`file_path` AS `file_path`,`fi`.`status` AS `status`,'cost' AS `cost`,`fi`.`created_at` AS `created_at`,`fi`.`created_by` AS `created_by`,concat('/finanse/items/edit.php?id=',`fi`.`id`) AS `CONCAT('/finanse/items/edit.php?id=', fi.id)` from (`finance_items` `fi` left join `investors` `inv` on(`inv`.`id` = `fi`.`company_id`)) union all select `we`.`id` AS `id`,'worker_expenses' AS `worker_expenses`,'RECEIPT' AS `RECEIPT`,`we`.`project_id` AS `project_id`,`we`.`cost_node_id` AS `cost_node_id`,concat(`w`.`first_name`,' ',`w`.`last_name`) collate utf8mb4_unicode_ci AS `Name_exp_6`,concat('Wydatek: ',`we`.`description`) collate utf8mb4_unicode_ci AS `CONCAT('Wydatek: ', we.description) COLLATE utf8mb4_unicode_ci`,`we`.`description` collate utf8mb4_unicode_ci AS `we.description COLLATE utf8mb4_unicode_ci`,NULL AS `NULL`,`we`.`date` AS `date`,`we`.`amount` AS `amount`,`we`.`amount` AS `amount`,'PLN' AS `PLN`,`we`.`receipt_path` collate utf8mb4_unicode_ci AS `we.receipt_path COLLATE utf8mb4_unicode_ci`,`we`.`status` collate utf8mb4_unicode_ci AS `we.status COLLATE utf8mb4_unicode_ci`,`we`.`expense_type` collate utf8mb4_unicode_ci AS `we.expense_type COLLATE utf8mb4_unicode_ci`,`we`.`created_at` AS `created_at`,`we`.`created_by_user_id` AS `created_by_user_id`,concat('/finanse/wydatki/index.php?expense_id=',`we`.`id`) AS `CONCAT('/finanse/wydatki/index.php?expense_id=', we.id)` from (`worker_expenses` `we` join `workers` `w` on(`w`.`id` = `we`.`worker_id`)) where `we`.`document_id` is null union all select `pr`.`id` AS `id`,'project_revenues' AS `project_revenues`,'INVOICE_REVENUE' AS `INVOICE_REVENUE`,`pr`.`project_id` AS `project_id`,NULL AS `NULL`,'-' AS `-`,`pr`.`name` AS `name`,`pr`.`description` AS `description`,NULL AS `NULL`,`pr`.`signed_date` AS `signed_date`,`pr`.`amount_net` AS `amount_net`,`pr`.`amount_net` AS `amount_net`,'PLN' AS `PLN`,NULL AS `NULL`,'approved' AS `approved`,`pr`.`type` AS `type`,`pr`.`created_at` AS `created_at`,NULL AS `NULL`,concat('/projekty/edit.php?id=',`pr`.`project_id`) AS `CONCAT('/projekty/edit.php?id=', pr.project_id)` from `project_revenues` `pr`  ;

-- --------------------------------------------------------

--
-- Struktura widoku `v_asset_alerts`
--
DROP TABLE IF EXISTS `v_asset_alerts`;

CREATE ALGORITHM=UNDEFINED DEFINER=`brygad`@`localhost` SQL SECURITY DEFINER VIEW `v_asset_alerts`  AS SELECT `ae`.`id` AS `event_id`, `ae`.`asset_id` AS `asset_id`, `a`.`name` AS `asset_name`, `a`.`type` AS `asset_type`, `ae`.`event_category` AS `event_category`, `ae`.`title` AS `title`, `ae`.`due_date` AS `due_date`, `ae`.`status` AS `status`, to_days(`ae`.`due_date`) - to_days(curdate()) AS `days_left`, CASE WHEN `ae`.`status` = 'planned' AND `ae`.`due_date` < curdate() THEN 2 WHEN `ae`.`status` = 'planned' AND `ae`.`due_date` <= curdate() + interval `ae`.`remind_days_before` day THEN 1 ELSE 0 END AS `alert_level` FROM (`asset_events` `ae` join `assets` `a` on(`ae`.`asset_id` = `a`.`id`)) WHERE `ae`.`status` = 'planned' ;

-- --------------------------------------------------------

--
-- Struktura widoku `v_worker_advances_details`
--
DROP TABLE IF EXISTS `v_worker_advances_details`;

CREATE ALGORITHM=UNDEFINED DEFINER=`brygad`@`localhost` SQL SECURITY DEFINER VIEW `v_worker_advances_details`  AS SELECT `wa`.`id` AS `advance_id`, `wa`.`worker_id` AS `worker_id`, `w`.`first_name` AS `first_name`, `w`.`last_name` AS `last_name`, concat(`w`.`first_name`,' ',`w`.`last_name`) AS `worker_name`, `wa`.`type` AS `type`, `wa`.`amount` AS `amount`, coalesce((select sum(`worker_ledger`.`amount`) from `worker_ledger` where `worker_ledger`.`advance_id` = `wa`.`id` and `worker_ledger`.`amount` > 0),0) AS `amount_settled`, `wa`.`amount`- coalesce((select sum(`worker_ledger`.`amount`) from `worker_ledger` where `worker_ledger`.`advance_id` = `wa`.`id` and `worker_ledger`.`amount` > 0),0) AS `amount_remaining`, `wa`.`issue_date` AS `issue_date`, `wa`.`salary_period` AS `salary_period`, `wa`.`description` AS `description`, `wa`.`status` AS `status`, NULL AS `closed_at`, NULL AS `closed_by`, NULL AS `closed_by_name`, NULL AS `closed_note`, `u`.`login` AS `created_by_name`, `wa`.`created_at` AS `created_at`, (select count(0) from `worker_ledger` where `worker_ledger`.`advance_id` = `wa`.`id`) AS `ledger_entries_count`, coalesce((select count(0) from `worker_advance_files` where `worker_advance_files`.`advance_id` = `wa`.`id`),0) AS `files_count` FROM ((`worker_advances` `wa` join `workers` `w` on(`w`.`id` = `wa`.`worker_id`)) left join `users` `u` on(`u`.`id` = `wa`.`created_by`)) ;

-- --------------------------------------------------------

--
-- Struktura widoku `v_worker_balance`
--
DROP TABLE IF EXISTS `v_worker_balance`;

CREATE ALGORITHM=UNDEFINED DEFINER=`brygad`@`localhost` SQL SECURITY DEFINER VIEW `v_worker_balance`  AS SELECT `wl`.`worker_id` AS `worker_id`, sum(`wl`.`amount`) AS `current_balance` FROM `worker_ledger` AS `wl` GROUP BY `wl`.`worker_id` ;

--
-- Ograniczenia dla zrzutów tabel
--

--
-- Ograniczenia dla tabeli `asset_bookings`
--
ALTER TABLE `asset_bookings`
  ADD CONSTRAINT `fk_booking_asset` FOREIGN KEY (`asset_id`) REFERENCES `assets` (`id`) ON DELETE CASCADE;

--
-- Ograniczenia dla tabeli `asset_events`
--
ALTER TABLE `asset_events`
  ADD CONSTRAINT `fk_events_asset` FOREIGN KEY (`asset_id`) REFERENCES `assets` (`id`) ON DELETE CASCADE;

--
-- Ograniczenia dla tabeli `asset_static_documents`
--
ALTER TABLE `asset_static_documents`
  ADD CONSTRAINT `fk_static_doc_asset` FOREIGN KEY (`asset_id`) REFERENCES `assets` (`id`) ON DELETE CASCADE;

--
-- Ograniczenia dla tabeli `cost_allocations`
--
ALTER TABLE `cost_allocations`
  ADD CONSTRAINT `fk_alloc_inv` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_alloc_node` FOREIGN KEY (`cost_node_id`) REFERENCES `project_cost_nodes` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_alloc_proj` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`);

--
-- Ograniczenia dla tabeli `documents`
--
ALTER TABLE `documents`
  ADD CONSTRAINT `fk_docs_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_docs_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_docs_vendor` FOREIGN KEY (`vendor_id`) REFERENCES `investors` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_docs_worker` FOREIGN KEY (`worker_id`) REFERENCES `workers` (`id`) ON DELETE SET NULL;

--
-- Ograniczenia dla tabeli `document_allocations`
--
ALTER TABLE `document_allocations`
  ADD CONSTRAINT `fk_doc_alloc_document` FOREIGN KEY (`document_id`) REFERENCES `documents` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_doc_alloc_node` FOREIGN KEY (`cost_node_id`) REFERENCES `project_cost_nodes` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_doc_alloc_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`);

--
-- Ograniczenia dla tabeli `document_items`
--
ALTER TABLE `document_items`
  ADD CONSTRAINT `fk_document_items_document` FOREIGN KEY (`document_id`) REFERENCES `documents` (`id`) ON DELETE CASCADE;

--
-- Ograniczenia dla tabeli `document_item_allocations`
--
ALTER TABLE `document_item_allocations`
  ADD CONSTRAINT `fk_dia_cost_node` FOREIGN KEY (`cost_node_id`) REFERENCES `project_cost_nodes` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_dia_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_dia_document_item` FOREIGN KEY (`document_item_id`) REFERENCES `document_items` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_dia_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE;

--
-- Ograniczenia dla tabeli `fakturownia_cost_allocations`
--
ALTER TABLE `fakturownia_cost_allocations`
  ADD CONSTRAINT `fk_cost_alloc_invoice` FOREIGN KEY (`cost_invoice_id`) REFERENCES `fakturownia_cost_invoices` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_cost_alloc_node` FOREIGN KEY (`cost_node_id`) REFERENCES `project_cost_nodes` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_cost_alloc_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_cost_alloc_user` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Ograniczenia dla tabeli `fakturownia_cost_invoices`
--
ALTER TABLE `fakturownia_cost_invoices`
  ADD CONSTRAINT `fk_cost_decided_by_user` FOREIGN KEY (`decided_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Ograniczenia dla tabeli `fakturownia_cost_status_history`
--
ALTER TABLE `fakturownia_cost_status_history`
  ADD CONSTRAINT `fk_cost_hist_invoice` FOREIGN KEY (`cost_invoice_id`) REFERENCES `fakturownia_cost_invoices` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_cost_hist_user` FOREIGN KEY (`changed_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Ograniczenia dla tabeli `finance_items`
--
ALTER TABLE `finance_items`
  ADD CONSTRAINT `fk_fi_company_v2` FOREIGN KEY (`company_id`) REFERENCES `investors` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_fi_creator_v2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_fi_project_v2` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE SET NULL;

--
-- Ograniczenia dla tabeli `hb_accounts`
--
ALTER TABLE `hb_accounts`
  ADD CONSTRAINT `fk_hba_household` FOREIGN KEY (`household_id`) REFERENCES `hb_households` (`id`) ON DELETE CASCADE;

--
-- Ograniczenia dla tabeli `hb_bills`
--
ALTER TABLE `hb_bills`
  ADD CONSTRAINT `fk_hbb_account` FOREIGN KEY (`default_account_id`) REFERENCES `hb_accounts` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_hbb_category` FOREIGN KEY (`category_id`) REFERENCES `hb_categories` (`id`),
  ADD CONSTRAINT `fk_hbb_household` FOREIGN KEY (`household_id`) REFERENCES `hb_households` (`id`) ON DELETE CASCADE;

--
-- Ograniczenia dla tabeli `hb_bill_items`
--
ALTER TABLE `hb_bill_items`
  ADD CONSTRAINT `fk_hbbi_bill` FOREIGN KEY (`bill_id`) REFERENCES `hb_bills` (`id`),
  ADD CONSTRAINT `fk_hbbi_household` FOREIGN KEY (`household_id`) REFERENCES `hb_households` (`id`) ON DELETE CASCADE;

--
-- Ograniczenia dla tabeli `hb_budgets`
--
ALTER TABLE `hb_budgets`
  ADD CONSTRAINT `fk_hbbud_category` FOREIGN KEY (`category_id`) REFERENCES `hb_categories` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_hbbud_household` FOREIGN KEY (`household_id`) REFERENCES `hb_households` (`id`) ON DELETE CASCADE;

--
-- Ograniczenia dla tabeli `hb_categories`
--
ALTER TABLE `hb_categories`
  ADD CONSTRAINT `fk_hbc_household` FOREIGN KEY (`household_id`) REFERENCES `hb_households` (`id`) ON DELETE CASCADE;

--
-- Ograniczenia dla tabeli `hb_households`
--
ALTER TABLE `hb_households`
  ADD CONSTRAINT `fk_hb_household_owner` FOREIGN KEY (`owner_user_id`) REFERENCES `users` (`id`);

--
-- Ograniczenia dla tabeli `hb_household_members`
--
ALTER TABLE `hb_household_members`
  ADD CONSTRAINT `fk_hbhm_household` FOREIGN KEY (`household_id`) REFERENCES `hb_households` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_hbhm_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Ograniczenia dla tabeli `hb_transactions`
--
ALTER TABLE `hb_transactions`
  ADD CONSTRAINT `fk_hbt_account` FOREIGN KEY (`account_id`) REFERENCES `hb_accounts` (`id`),
  ADD CONSTRAINT `fk_hbt_account_target` FOREIGN KEY (`transfer_account_id`) REFERENCES `hb_accounts` (`id`),
  ADD CONSTRAINT `fk_hbt_bill_item` FOREIGN KEY (`bill_item_id`) REFERENCES `hb_bill_items` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_hbt_category` FOREIGN KEY (`category_id`) REFERENCES `hb_categories` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_hbt_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_hbt_household` FOREIGN KEY (`household_id`) REFERENCES `hb_households` (`id`) ON DELETE CASCADE;

--
-- Ograniczenia dla tabeli `hr_alerts`
--
ALTER TABLE `hr_alerts`
  ADD CONSTRAINT `fk_hr_alerts_document` FOREIGN KEY (`document_id`) REFERENCES `worker_documents` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_hr_alerts_worker` FOREIGN KEY (`worker_id`) REFERENCES `workers` (`id`);

--
-- Ograniczenia dla tabeli `investor_notes`
--
ALTER TABLE `investor_notes`
  ADD CONSTRAINT `fk_inv_notes_investor` FOREIGN KEY (`investor_id`) REFERENCES `investors` (`id`) ON DELETE CASCADE;

--
-- Ograniczenia dla tabeli `investor_reminders`
--
ALTER TABLE `investor_reminders`
  ADD CONSTRAINT `fk_inv_rem_investor` FOREIGN KEY (`investor_id`) REFERENCES `investors` (`id`) ON DELETE CASCADE;

--
-- Ograniczenia dla tabeli `invoices_sale`
--
ALTER TABLE `invoices_sale`
  ADD CONSTRAINT `fk_isale_client` FOREIGN KEY (`client_id`) REFERENCES `investors` (`id`),
  ADD CONSTRAINT `fk_isale_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Ograniczenia dla tabeli `invoice_sale_allocations`
--
ALTER TABLE `invoice_sale_allocations`
  ADD CONSTRAINT `fk_isa_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `invoices_sale` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_isa_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE;

--
-- Ograniczenia dla tabeli `invoice_sale_items`
--
ALTER TABLE `invoice_sale_items`
  ADD CONSTRAINT `fk_isale_item_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `invoices_sale` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_isale_item_node` FOREIGN KEY (`cost_node_id`) REFERENCES `project_cost_nodes` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_isale_item_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE SET NULL;

--
-- Ograniczenia dla tabeli `invoice_sale_jst_data`
--
ALTER TABLE `invoice_sale_jst_data`
  ADD CONSTRAINT `fk_jst_invoice_sale` FOREIGN KEY (`invoice_sale_id`) REFERENCES `invoices_sale` (`id`) ON DELETE CASCADE;

--
-- Ograniczenia dla tabeli `invoice_sale_payments`
--
ALTER TABLE `invoice_sale_payments`
  ADD CONSTRAINT `fk_isp_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `invoices_sale` (`id`) ON DELETE CASCADE;

--
-- Ograniczenia dla tabeli `invoice_sale_retentions`
--
ALTER TABLE `invoice_sale_retentions`
  ADD CONSTRAINT `fk_retention_created_by` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_retention_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `invoices_sale` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_retention_settled_by` FOREIGN KEY (`settled_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Ograniczenia dla tabeli `projects`
--
ALTER TABLE `projects`
  ADD CONSTRAINT `fk_project_investor` FOREIGN KEY (`investor_id`) REFERENCES `investors` (`id`) ON DELETE SET NULL;

--
-- Ograniczenia dla tabeli `project_cost_nodes`
--
ALTER TABLE `project_cost_nodes`
  ADD CONSTRAINT `fk_nodes_parent` FOREIGN KEY (`parent_id`) REFERENCES `project_cost_nodes` (`id`),
  ADD CONSTRAINT `fk_nodes_proj` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`);

--
-- Ograniczenia dla tabeli `project_revenues`
--
ALTER TABLE `project_revenues`
  ADD CONSTRAINT `fk_rev_cost_node` FOREIGN KEY (`cost_node_id`) REFERENCES `project_cost_nodes` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_rev_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE;

--
-- Ograniczenia dla tabeli `push_notifications_log`
--
ALTER TABLE `push_notifications_log`
  ADD CONSTRAINT `fk_push_log_subscription` FOREIGN KEY (`subscription_id`) REFERENCES `push_subscriptions` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_push_log_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Ograniczenia dla tabeli `push_subscriptions`
--
ALTER TABLE `push_subscriptions`
  ADD CONSTRAINT `fk_push_subscriptions_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Ograniczenia dla tabeli `sales_noninvoice_allocations`
--
ALTER TABLE `sales_noninvoice_allocations`
  ADD CONSTRAINT `fk_snia_entry` FOREIGN KEY (`entry_id`) REFERENCES `sales_noninvoice_entries` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_snia_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE;

--
-- Ograniczenia dla tabeli `sales_noninvoice_entries`
--
ALTER TABLE `sales_noninvoice_entries`
  ADD CONSTRAINT `fk_snie_client` FOREIGN KEY (`client_id`) REFERENCES `investors` (`id`);

--
-- Ograniczenia dla tabeli `settlements`
--
ALTER TABLE `settlements`
  ADD CONSTRAINT `fk_settl_creator` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_settl_exp` FOREIGN KEY (`linked_expense_id`) REFERENCES `worker_expenses` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_settl_worker` FOREIGN KEY (`worker_id`) REFERENCES `workers` (`id`);

--
-- Ograniczenia dla tabeli `tasks`
--
ALTER TABLE `tasks`
  ADD CONSTRAINT `fk_tasks_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Ograniczenia dla tabeli `task_assignments`
--
ALTER TABLE `task_assignments`
  ADD CONSTRAINT `fk_task_assignments_task` FOREIGN KEY (`task_id`) REFERENCES `tasks` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_task_assignments_worker` FOREIGN KEY (`worker_id`) REFERENCES `workers` (`id`);

--
-- Ograniczenia dla tabeli `task_attachments`
--
ALTER TABLE `task_attachments`
  ADD CONSTRAINT `fk_task_files` FOREIGN KEY (`task_id`) REFERENCES `tasks` (`id`) ON DELETE CASCADE;

--
-- Ograniczenia dla tabeli `task_comments`
--
ALTER TABLE `task_comments`
  ADD CONSTRAINT `task_comments_ibfk_1` FOREIGN KEY (`task_id`) REFERENCES `tasks` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `task_comments_ibfk_2` FOREIGN KEY (`worker_id`) REFERENCES `workers` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `task_comments_ibfk_3` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Ograniczenia dla tabeli `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `fk_users_worker` FOREIGN KEY (`worker_id`) REFERENCES `workers` (`id`);

--
-- Ograniczenia dla tabeli `worker_advances`
--
ALTER TABLE `worker_advances`
  ADD CONSTRAINT `fk_adv_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `fk_adv_worker` FOREIGN KEY (`worker_id`) REFERENCES `workers` (`id`);

--
-- Ograniczenia dla tabeli `worker_advance_files`
--
ALTER TABLE `worker_advance_files`
  ADD CONSTRAINT `fk_advance_files_advance` FOREIGN KEY (`advance_id`) REFERENCES `worker_advances` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_advance_files_uploader` FOREIGN KEY (`uploaded_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Ograniczenia dla tabeli `worker_documents`
--
ALTER TABLE `worker_documents`
  ADD CONSTRAINT `fk_worker_documents_type` FOREIGN KEY (`document_type_id`) REFERENCES `document_types` (`id`),
  ADD CONSTRAINT `fk_worker_documents_worker` FOREIGN KEY (`worker_id`) REFERENCES `workers` (`id`);

--
-- Ograniczenia dla tabeli `worker_document_files`
--
ALTER TABLE `worker_document_files`
  ADD CONSTRAINT `fk_worker_document_files_document` FOREIGN KEY (`document_id`) REFERENCES `worker_documents` (`id`) ON DELETE CASCADE;

--
-- Ograniczenia dla tabeli `worker_expenses`
--
ALTER TABLE `worker_expenses`
  ADD CONSTRAINT `fk_exp_advance` FOREIGN KEY (`advance_id`) REFERENCES `worker_advances` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_exp_approver` FOREIGN KEY (`approved_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_exp_creator` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_exp_document` FOREIGN KEY (`document_id`) REFERENCES `documents` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_exp_node` FOREIGN KEY (`cost_node_id`) REFERENCES `project_cost_nodes` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_exp_proj` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`),
  ADD CONSTRAINT `fk_exp_worker` FOREIGN KEY (`worker_id`) REFERENCES `workers` (`id`),
  ADD CONSTRAINT `fk_worker_expenses_wallet_advance` FOREIGN KEY (`wallet_advance_id`) REFERENCES `worker_advances` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_worker_expenses_wallet_ledger` FOREIGN KEY (`wallet_ledger_id`) REFERENCES `worker_ledger` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Ograniczenia dla tabeli `worker_ledger`
--
ALTER TABLE `worker_ledger`
  ADD CONSTRAINT `fk_ledger_adv` FOREIGN KEY (`advance_id`) REFERENCES `worker_advances` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_ledger_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `fk_ledger_worker` FOREIGN KEY (`worker_id`) REFERENCES `workers` (`id`);

--
-- Ograniczenia dla tabeli `worker_rates`
--
ALTER TABLE `worker_rates`
  ADD CONSTRAINT `fk_rates_cost_node` FOREIGN KEY (`cost_node_id`) REFERENCES `project_cost_nodes` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_rates_proj` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_rates_worker` FOREIGN KEY (`worker_id`) REFERENCES `workers` (`id`) ON DELETE CASCADE;

--
-- Ograniczenia dla tabeli `worker_wallet_funding`
--
ALTER TABLE `worker_wallet_funding`
  ADD CONSTRAINT `fk_wallet_funding_advance` FOREIGN KEY (`advance_id`) REFERENCES `worker_advances` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_wallet_funding_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_wallet_funding_worker` FOREIGN KEY (`worker_id`) REFERENCES `workers` (`id`);

--
-- Ograniczenia dla tabeli `work_logs`
--
ALTER TABLE `work_logs`
  ADD CONSTRAINT `fk_logs_approver` FOREIGN KEY (`approved_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_logs_creator` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_logs_locker` FOREIGN KEY (`locked_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_logs_node` FOREIGN KEY (`cost_node_id`) REFERENCES `project_cost_nodes` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_logs_proj` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`),
  ADD CONSTRAINT `fk_logs_worker` FOREIGN KEY (`worker_id`) REFERENCES `workers` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
