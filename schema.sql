-- ============================================================
-- Corporate Trade Reconciliation & Audit Log System Database Schema
-- Database: ledgerchain_recon
-- Engine: InnoDB
-- ============================================================

CREATE DATABASE IF NOT EXISTS `ledgerchain_recon` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `ledgerchain_recon`;

-- ------------------------------------------------------------
-- Table: users
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `reconciliation_exceptions`;
DROP TABLE IF EXISTS `reconciliation_runs`;
DROP TABLE IF EXISTS `trades`;
DROP TABLE IF EXISTS `audit_log`;
DROP TABLE IF EXISTS `users`;

CREATE TABLE `users` (
  `id` VARCHAR(64) NOT NULL,
  `name` VARCHAR(255) NOT NULL,
  `email` VARCHAR(255) NOT NULL UNIQUE,
  `password` VARCHAR(255) NOT NULL,
  `role` ENUM('ADMIN', 'AUDITOR') NOT NULL DEFAULT 'AUDITOR',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Table: trades
-- ------------------------------------------------------------
CREATE TABLE `trades` (
  `id` VARCHAR(64) NOT NULL,
  `external_trade_id` VARCHAR(64) NOT NULL,
  `source_system` VARCHAR(64) NOT NULL,
  `symbol` VARCHAR(32) NOT NULL,
  `quantity` INT NOT NULL,
  `price` DECIMAL(15, 4) NOT NULL,
  `side` ENUM('BUY', 'SELL') NOT NULL,
  `trade_date` DATE NOT NULL,
  `status` VARCHAR(32) NOT NULL DEFAULT 'PENDING',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Table: reconciliation_runs
-- ------------------------------------------------------------
CREATE TABLE `reconciliation_runs` (
  `id` VARCHAR(64) NOT NULL,
  `run_date` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `source_a` VARCHAR(64) NOT NULL,
  `source_b` VARCHAR(64) NOT NULL,
  `total_compared` INT NOT NULL,
  `matched_count` INT NOT NULL,
  `mismatched_count` INT NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Table: reconciliation_exceptions
-- ------------------------------------------------------------
CREATE TABLE `reconciliation_exceptions` (
  `id` VARCHAR(64) NOT NULL,
  `run_id` VARCHAR(64) NULL,
  `trade_id_a` VARCHAR(64) NULL,
  `trade_id_b` VARCHAR(64) NULL,
  `source_a` VARCHAR(64) NOT NULL,
  `source_b` VARCHAR(64) NOT NULL,
  `exception_type` VARCHAR(64) NOT NULL,
  `status` ENUM('OPEN', 'RESOLVED', 'IGNORED') NOT NULL DEFAULT 'OPEN',
  `symbol` VARCHAR(32) NOT NULL,
  `snapshot_a` JSON NULL,
  `snapshot_b` JSON NULL,
  `resolution_note` TEXT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `resolved_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_exc_run` FOREIGN KEY (`run_id`) REFERENCES `reconciliation_runs` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_exc_trade_a` FOREIGN KEY (`trade_id_a`) REFERENCES `trades` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_exc_trade_b` FOREIGN KEY (`trade_id_b`) REFERENCES `trades` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Table: audit_log
-- ------------------------------------------------------------
CREATE TABLE `audit_log` (
  `id` INT AUTO_INCREMENT NOT NULL,
  `log_id` VARCHAR(64) NOT NULL,
  `actor` VARCHAR(255) NOT NULL,
  `action` VARCHAR(128) NOT NULL,
  `entity_type` VARCHAR(128) NOT NULL,
  `entity_id` VARCHAR(128) NOT NULL,
  `details` TEXT NOT NULL,
  `timestamp` VARCHAR(64) NOT NULL,
  `prev_hash` VARCHAR(64) NOT NULL,
  `hash` VARCHAR(64) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_log_id` (`log_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Seed Data: Demo Users
-- Admin: admin@corp.test / admin123
-- Auditor: auditor@corp.test / audit123
-- ------------------------------------------------------------
INSERT INTO `users` (`id`, `name`, `email`, `password`, `role`) VALUES
('u_admin', 'Amir Khan', 'admin@corp.test', '$2y$10$gVKsAwOcY/knAumfie3.Fuimw010o3mBr5SQzkKvvJDyO3IsaQZFu', 'ADMIN'),
('u_auditor', 'Sara Ahmed', 'auditor@corp.test', '$2y$10$bP0yd.cSarp2kDq.THJj3eFP7IKbREDwK6XRCqHjsXOc9CpuLm.V.', 'AUDITOR');

-- ------------------------------------------------------------
-- Seed Data: Sample Trades
-- ------------------------------------------------------------
INSERT INTO `trades` (`id`, `external_trade_id`, `source_system`, `symbol`, `quantity`, `price`, `side`, `trade_date`, `status`) VALUES
('t_seed_1', 'TRX-1001', 'Internal', 'AAPL', 100, 189.50, 'BUY', '2026-07-18', 'PENDING'),
('t_seed_2', 'TRX-1001', 'BrokerA',  'AAPL', 100, 189.50, 'BUY', '2026-07-18', 'PENDING'),
('t_seed_3', 'TRX-1002', 'Internal', 'MSFT', 50,  412.10, 'SELL', '2026-07-18', 'PENDING'),
('t_seed_4', 'TRX-1002', 'BrokerA',  'MSFT', 50,  409.75, 'SELL', '2026-07-18', 'PENDING'),
('t_seed_5', 'TRX-1003', 'Internal', 'TSLA', 25,  245.00, 'BUY', '2026-07-19', 'PENDING'),
('t_seed_6', 'TRX-1004', 'BrokerA',  'NVDA', 10,  118.30, 'BUY', '2026-07-19', 'PENDING'),
('t_seed_7', 'TRX-1005', 'Internal', 'GOOG', 40,  179.60, 'SELL', '2026-07-19', 'PENDING'),
('t_seed_8', 'TRX-1005', 'BrokerA',  'GOOG', 45,  179.60, 'SELL', '2026-07-19', 'PENDING');

-- ------------------------------------------------------------
-- Schema migration: Add trigger_type to reconciliation_runs
-- ------------------------------------------------------------
ALTER TABLE reconciliation_runs ADD COLUMN trigger_type ENUM('MANUAL', 'SCHEDULED') NOT NULL DEFAULT 'MANUAL' AFTER mismatched_count;

-- ------------------------------------------------------------
-- Schema migration: Add notification_sent to reconciliation_runs
-- ------------------------------------------------------------
ALTER TABLE reconciliation_runs ADD COLUMN notification_sent TINYINT(1) NOT NULL DEFAULT 0 AFTER trigger_type;
