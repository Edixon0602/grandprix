<?php
declare(strict_types=1);

final class DatabaseSchema
{
    public static function statements(): array
    {
        $suffix = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        return [
            "CREATE TABLE IF NOT EXISTS gp_schema_meta (
                meta_key VARCHAR(80) NOT NULL PRIMARY KEY,
                meta_value VARCHAR(255) NOT NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ){$suffix}",
            "CREATE TABLE IF NOT EXISTS gp_customers (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                public_key VARCHAR(80) NOT NULL UNIQUE,
                full_name VARCHAR(160) NOT NULL,
                identity_document VARCHAR(40) NOT NULL UNIQUE,
                email VARCHAR(190) NULL UNIQUE,
                phone VARCHAR(40) NULL,
                password_hash VARCHAR(255) NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'active',
                last_login_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_gp_customers_status (status)
            ){$suffix}",
            "CREATE TABLE IF NOT EXISTS gp_vehicles (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                code VARCHAR(40) NOT NULL UNIQUE,
                plate VARCHAR(40) NULL,
                model VARCHAR(120) NOT NULL,
                color VARCHAR(80) NULL,
                image_path VARCHAR(255) NOT NULL DEFAULT '../assets/moto-blue.png',
                traccar_device_id BIGINT UNSIGNED NOT NULL UNIQUE,
                traccar_unique_id VARCHAR(80) NULL,
                sim_phone VARCHAR(40) NULL,
                command_secret TEXT NULL,
                relay_verified TINYINT(1) NOT NULL DEFAULT 0,
                data_commands_verified TINYINT(1) NOT NULL DEFAULT 0,
                commands_enabled TINYINT(1) NOT NULL DEFAULT 1,
                status VARCHAR(30) NOT NULL DEFAULT 'active',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_gp_vehicles_status (status)
            ){$suffix}",
            "CREATE TABLE IF NOT EXISTS gp_contracts (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                contract_number VARCHAR(60) NOT NULL UNIQUE,
                customer_id BIGINT UNSIGNED NOT NULL,
                vehicle_id BIGINT UNSIGNED NOT NULL,
                total_weeks SMALLINT UNSIGNED NOT NULL DEFAULT 50,
                weekly_amount DECIMAL(12,2) NOT NULL,
                financed_amount DECIMAL(12,2) NOT NULL,
                start_date DATE NOT NULL,
                status VARCHAR(30) NOT NULL DEFAULT 'active',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT fk_gp_contract_customer FOREIGN KEY (customer_id) REFERENCES gp_customers(id),
                CONSTRAINT fk_gp_contract_vehicle FOREIGN KEY (vehicle_id) REFERENCES gp_vehicles(id),
                INDEX idx_gp_contract_customer (customer_id, status),
                INDEX idx_gp_contract_vehicle (vehicle_id, status)
            ){$suffix}",
            "CREATE TABLE IF NOT EXISTS gp_contract_weeks (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                contract_id BIGINT UNSIGNED NOT NULL,
                week_number SMALLINT UNSIGNED NOT NULL,
                due_date DATE NOT NULL,
                amount DECIMAL(12,2) NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'pending',
                paid_at DATETIME NULL,
                payment_report_id BIGINT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT fk_gp_week_contract FOREIGN KEY (contract_id) REFERENCES gp_contracts(id) ON DELETE CASCADE,
                UNIQUE KEY uq_gp_contract_week (contract_id, week_number),
                INDEX idx_gp_week_status (contract_id, status, due_date)
            ){$suffix}",
            "CREATE TABLE IF NOT EXISTS gp_payment_reports (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                contract_id BIGINT UNSIGNED NOT NULL,
                week_number SMALLINT UNSIGNED NOT NULL,
                bank VARCHAR(100) NOT NULL,
                reference_number VARCHAR(80) NOT NULL,
                transfer_date DATE NOT NULL,
                amount DECIMAL(12,2) NOT NULL,
                proof_path VARCHAR(255) NULL,
                proof_mime VARCHAR(80) NULL,
                notes VARCHAR(500) NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'review',
                reviewed_by VARCHAR(190) NULL,
                reviewed_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT fk_gp_payment_contract FOREIGN KEY (contract_id) REFERENCES gp_contracts(id) ON DELETE CASCADE,
                UNIQUE KEY uq_gp_payment_reference (bank, reference_number),
                INDEX idx_gp_payment_review (status, created_at),
                INDEX idx_gp_payment_contract (contract_id, week_number)
            ){$suffix}",
            "CREATE TABLE IF NOT EXISTS gp_command_logs (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                traccar_device_id BIGINT UNSIGNED NOT NULL,
                command_key VARCHAR(80) NOT NULL,
                command_label VARCHAR(160) NOT NULL,
                risk_level VARCHAR(20) NOT NULL,
                channel VARCHAR(20) NOT NULL,
                traccar_type VARCHAR(80) NOT NULL,
                command_fingerprint CHAR(64) NOT NULL,
                status VARCHAR(30) NOT NULL,
                result_summary VARCHAR(500) NULL,
                reason VARCHAR(300) NULL,
                requested_by VARCHAR(190) NOT NULL,
                ip_hash CHAR(64) NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_gp_command_device (traccar_device_id, created_at),
                INDEX idx_gp_command_status (status, created_at)
            ){$suffix}",
        ];
    }

    public static function migrate(PDO $pdo): void
    {
        foreach (self::statements() as $sql) $pdo->exec($sql);
        $statement = $pdo->prepare(
            "INSERT INTO gp_schema_meta (meta_key, meta_value) VALUES ('schema_version', '7.2.0')
             ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)"
        );
        $statement->execute();
    }
}
