#!/usr/bin/env php
<?php
/**
 * Database schema installer for AlfredPay Service Virtualization Platform.
 * Run via SSH: php bin/install-schema.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Symfony\Component\Dotenv\Dotenv;

$dotenv = new Dotenv();
$dotenv->loadEnv(__DIR__ . '/../.env');

$dsn = sprintf(
    'mysql:host=%s;port=%s;charset=utf8mb4',
    $_ENV['DB_HOST'],
    $_ENV['DB_PORT']
);

$pdo = new PDO($dsn, $_ENV['DB_USER'], $_ENV['DB_PASS'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

$dbName = $_ENV['DB_NAME'];
$pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo->exec("USE `{$dbName}`");

echo "Installing schema into '{$dbName}'...\n";

$statements = [
    // Scenario registry — seed configurations for test namespaces
    "CREATE TABLE IF NOT EXISTS `scenarios` (
        `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `namespace` VARCHAR(128) NOT NULL,
        `domain` VARCHAR(64) NOT NULL COMMENT 'compliance, rails, cards, custody, cpn',
        `name` VARCHAR(255) NOT NULL,
        `config` JSON NOT NULL COMMENT 'Full scenario configuration',
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `expires_at` DATETIME NULL COMMENT 'Auto-cleanup after this time',
        UNIQUE KEY `uq_namespace` (`namespace`),
        INDEX `idx_domain` (`domain`),
        INDEX `idx_expires` (`expires_at`)
    ) ENGINE=InnoDB",

    // Stateful entities managed by virtual services
    "CREATE TABLE IF NOT EXISTS `entities` (
        `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `namespace` VARCHAR(128) NOT NULL,
        `entity_type` VARCHAR(64) NOT NULL COMMENT 'kyc_session, customer, payment, transfer',
        `entity_ref` VARCHAR(255) NOT NULL COMMENT 'External reference ID',
        `state` VARCHAR(64) NOT NULL DEFAULT 'created',
        `data` JSON NOT NULL COMMENT 'Entity payload/attributes',
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY `uq_ns_type_ref` (`namespace`, `entity_type`, `entity_ref`),
        INDEX `idx_namespace` (`namespace`),
        INDEX `idx_state` (`state`)
    ) ENGINE=InnoDB",

    // State transition audit log
    "CREATE TABLE IF NOT EXISTS `state_history` (
        `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `entity_id` INT UNSIGNED NOT NULL,
        `from_state` VARCHAR(64) NOT NULL,
        `to_state` VARCHAR(64) NOT NULL,
        `trigger_type` VARCHAR(32) NOT NULL COMMENT 'api_call, callback, cron, manual',
        `metadata` JSON NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX `idx_entity` (`entity_id`),
        CONSTRAINT `fk_history_entity` FOREIGN KEY (`entity_id`) REFERENCES `entities`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB",

    // Pending callbacks — the callback orchestrator queue
    "CREATE TABLE IF NOT EXISTS `pending_callbacks` (
        `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `namespace` VARCHAR(128) NOT NULL,
        `entity_id` INT UNSIGNED NULL,
        `target_url` VARCHAR(1024) NOT NULL COMMENT 'Where to POST the callback',
        `http_method` VARCHAR(10) NOT NULL DEFAULT 'POST',
        `headers` JSON NULL,
        `payload` JSON NOT NULL,
        `fire_at` DATETIME NOT NULL COMMENT 'When to fire this callback',
        `status` ENUM('pending', 'fired', 'failed', 'cancelled') NOT NULL DEFAULT 'pending',
        `attempt_count` INT UNSIGNED NOT NULL DEFAULT 0,
        `max_attempts` INT UNSIGNED NOT NULL DEFAULT 3,
        `last_attempt_at` DATETIME NULL,
        `last_error` TEXT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX `idx_fire_at_status` (`status`, `fire_at`),
        INDEX `idx_namespace` (`namespace`),
        CONSTRAINT `fk_callback_entity` FOREIGN KEY (`entity_id`) REFERENCES `entities`(`id`) ON DELETE SET NULL
    ) ENGINE=InnoDB",

    // Callback history — log of all fired callbacks
    "CREATE TABLE IF NOT EXISTS `callback_history` (
        `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `callback_id` INT UNSIGNED NOT NULL,
        `namespace` VARCHAR(128) NOT NULL,
        `target_url` VARCHAR(1024) NOT NULL,
        `payload` JSON NOT NULL,
        `response_status` INT NULL,
        `response_body` TEXT NULL,
        `duration_ms` INT UNSIGNED NULL,
        `success` TINYINT(1) NOT NULL DEFAULT 0,
        `error` TEXT NULL,
        `fired_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX `idx_namespace` (`namespace`),
        INDEX `idx_callback` (`callback_id`)
    ) ENGINE=InnoDB",

    // Inbound request log — every API call received
    "CREATE TABLE IF NOT EXISTS `request_log` (
        `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `namespace` VARCHAR(128) NULL,
        `method` VARCHAR(10) NOT NULL,
        `path` VARCHAR(1024) NOT NULL,
        `headers` JSON NULL,
        `body` JSON NULL,
        `response_status` INT NOT NULL,
        `response_body` JSON NULL,
        `duration_ms` INT UNSIGNED NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX `idx_namespace` (`namespace`),
        INDEX `idx_created` (`created_at`)
    ) ENGINE=InnoDB",
];

foreach ($statements as $sql) {
    $pdo->exec($sql);
}

echo "Schema installed successfully. Tables created:\n";
$tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
foreach ($tables as $table) {
    echo "  - {$table}\n";
}
