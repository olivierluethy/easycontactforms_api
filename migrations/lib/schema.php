<?php
// Schema introspection helpers used by migrations.
//
// MySQL 5.7 has no `ADD COLUMN IF NOT EXISTS`, so idempotency is achieved by
// asking information_schema before every structural change. That keeps a
// migration safe to re-run — which matters when one fails halfway on a shared
// host and has to be started again.

declare(strict_types=1);

function schema_has_table(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
    );
    $stmt->execute([$table]);
    return (int)$stmt->fetchColumn() > 0;
}

function schema_has_column(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}

function schema_has_index(PDO $pdo, string $table, string $index): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?'
    );
    $stmt->execute([$table, $index]);
    return (int)$stmt->fetchColumn() > 0;
}

function schema_has_constraint(PDO $pdo, string $table, string $constraint): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?'
    );
    $stmt->execute([$table, $constraint]);
    return (int)$stmt->fetchColumn() > 0;
}

function schema_add_column(PDO $pdo, string $table, string $column, string $definition): void
{
    if (!schema_has_column($pdo, $table, $column)) {
        $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
    }
}

function schema_add_index(PDO $pdo, string $table, string $index, string $definition): void
{
    if (!schema_has_index($pdo, $table, $index)) {
        $pdo->exec("ALTER TABLE `{$table}` ADD {$definition}");
    }
}

function schema_add_constraint(PDO $pdo, string $table, string $constraint, string $definition): void
{
    if (!schema_has_constraint($pdo, $table, $constraint)) {
        $pdo->exec("ALTER TABLE `{$table}` ADD CONSTRAINT `{$constraint}` {$definition}");
    }
}

function schema_drop_index(PDO $pdo, string $table, string $index): void
{
    if (schema_has_index($pdo, $table, $index)) {
        $pdo->exec("ALTER TABLE `{$table}` DROP INDEX `{$index}`");
    }
}
