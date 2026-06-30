-- BRYGAD ERP — minimalne dane demo (bezpieczne do repo)
-- Uruchom PO imporcie database/schema.sql
--
-- Login: admin / demo1234

USE `brygad_erp`;

INSERT INTO `company_settings` (
  `id`, `company_name`, `company_nip`, `company_regon`,
  `company_address`, `company_city`, `company_post_code`,
  `company_email`, `company_phone`, `company_website`,
  `default_bank_account`, `default_bank_name`, `default_place_of_issue`,
  `default_payment_days`, `issuer_name`
) VALUES (
  1,
  'BRYGAD Demo Sp. z o.o.',
  '0000000000',
  '000000000',
  'ul. Budowlana 1',
  'Warszawa',
  '00-001',
  'kontakt@brygad-demo.pl',
  '500 000 000',
  'https://example.com',
  '00 0000 0000 0000 0000 0000 0000',
  'Bank Demo',
  'Warszawa',
  14,
  'Jan Kowalski'
);

INSERT INTO `workers` (`id`, `first_name`, `last_name`, `phone`, `email`, `worker_type`, `is_active`) VALUES
(1, 'Jan', 'Kowalski', '500100200', 'jan.kowalski@brygad-demo.pl', 'permanent', 1),
(2, 'Anna', 'Nowak', '500100201', 'anna.nowak@brygad-demo.pl', 'permanent', 1);

INSERT INTO `users` (`id`, `worker_id`, `display_name`, `first_name`, `last_name`, `email`, `login`, `password_hash`, `role`, `is_active`) VALUES
(1, 1, 'Administrator', 'Jan', 'Kowalski', 'admin@brygad-demo.pl', 'admin', '$2b$12$wnC1/Dk9.Cg93rggssIdkOucf/9m1GUJ3zAGEREssRNgd5DGnv2PW', 'admin', 1),
(2, 2, 'Anna Nowak', 'Anna', 'Nowak', 'anna.nowak@brygad-demo.pl', 'anowak', '$2b$12$wnC1/Dk9.Cg93rggssIdkOucf/9m1GUJ3zAGEREssRNgd5DGnv2PW', 'worker', 1);

INSERT INTO `projects` (`id`, `name`, `project_type`, `status`, `start_date`, `contract_amount`) VALUES
(1, 'Budowa osiedla „Zielone Wzgórze”', 'standard', 'active', '2026-01-15', 2500000.00),
(2, 'Remont hali magazynowej', 'micro', 'active', '2026-03-01', 180000.00);

INSERT INTO `project_cost_nodes` (`id`, `project_id`, `parent_id`, `name`, `sort_order`) VALUES
(1, 1, NULL, 'Roboty ziemne', 1),
(2, 1, NULL, 'Stan surowy', 2),
(3, 1, 2, 'Fundamenty', 1),
(4, 2, NULL, 'Demontaż', 1);

INSERT INTO `worker_rates` (`id`, `worker_id`, `scope_type`, `project_id`, `cost_node_id`, `base_rate`, `valid_from`, `valid_to`) VALUES
(1, 1, 'GLOBAL', NULL, NULL, 45.00, '2026-01-01', NULL),
(2, 2, 'GLOBAL', NULL, NULL, 38.00, '2026-01-01', NULL);
