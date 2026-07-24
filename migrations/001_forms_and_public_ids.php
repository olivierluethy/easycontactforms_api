<?php
// v1 → v2.
//
// v1 had three tables and a fixed name/email/message form baked into the
// submissions table. v2 introduces forms + form_fields between projects and
// submissions, stores submitted values as rows in submission_values, adds
// unguessable public_ids everywhere, and adds read state.
//
// Every step is guarded, so re-running the migration is a no-op.
//
// The default form's fields are keyed `full_name` / `email` / `message` on
// purpose: those are exactly the keys the already-deployed widgets post, so
// legacy submissions map onto the new model one-to-one.

declare(strict_types=1);

return function (PDO $pdo, callable $log): void {
    // ── projects: public_id, branding, reply-from ────────────────────────
    $log('projects: adding public_id, branding and reply-from columns');
    schema_add_column($pdo, 'projects', 'public_id', 'CHAR(36) NULL AFTER `id`');
    schema_add_column($pdo, 'projects', 'website_url', 'VARCHAR(255) NULL AFTER `project_token`');
    schema_add_column($pdo, 'projects', 'logo_url', 'TEXT NULL AFTER `website_url`');
    schema_add_column($pdo, 'projects', 'reply_from_email', 'VARCHAR(190) NULL AFTER `logo_url`');

    backfill_public_ids($pdo, 'projects', $log);
    $pdo->exec('ALTER TABLE `projects` MODIFY `public_id` CHAR(36) NOT NULL');
    schema_add_index($pdo, 'projects', 'uniq_projects_public_id', 'UNIQUE KEY `uniq_projects_public_id` (`public_id`)');
    // v1 carried both a UNIQUE and a plain index on project_token; the UNIQUE
    // alone does the job.
    schema_drop_index($pdo, 'projects', 'idx_projects_token');

    // ── forms + form_fields ──────────────────────────────────────────────
    $log('forms: creating tables');
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `forms` (
          `id`         INT          NOT NULL AUTO_INCREMENT,
          `public_id`  CHAR(36)     NOT NULL,
          `project_id` INT          NOT NULL,
          `form_name`  VARCHAR(150) NOT NULL,
          `form_token` VARCHAR(40)  NOT NULL,
          `is_default` TINYINT(1)   NOT NULL DEFAULT 0,
          `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          UNIQUE KEY `uniq_forms_public_id` (`public_id`),
          UNIQUE KEY `uniq_forms_token` (`form_token`),
          KEY `idx_forms_project` (`project_id`),
          CONSTRAINT `fk_forms_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `form_fields` (
          `id`          INT          NOT NULL AUTO_INCREMENT,
          `form_id`     INT          NOT NULL,
          `field_key`   VARCHAR(64)  NOT NULL,
          `label`       VARCHAR(150) NOT NULL,
          `field_type`  VARCHAR(20)  NOT NULL DEFAULT 'text',
          `is_required` TINYINT(1)   NOT NULL DEFAULT 1,
          `sort_order`  INT          NOT NULL DEFAULT 0,
          PRIMARY KEY (`id`),
          UNIQUE KEY `uniq_form_fields_key` (`form_id`, `field_key`),
          KEY `idx_form_fields_order` (`form_id`, `sort_order`),
          CONSTRAINT `fk_form_fields_form` FOREIGN KEY (`form_id`) REFERENCES `forms` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    // ── one default form per existing project ────────────────────────────
    $projects = $pdo->query('SELECT id, project_name FROM projects ORDER BY id')->fetchAll();
    $needsForm = $pdo->prepare('SELECT COUNT(*) FROM forms WHERE project_id = ?');
    $insertForm = $pdo->prepare(
        'INSERT INTO forms (public_id, project_id, form_name, form_token, is_default) VALUES (?, ?, ?, ?, 1)'
    );
    $insertField = $pdo->prepare(
        'INSERT INTO form_fields (form_id, field_key, label, field_type, is_required, sort_order)
         VALUES (?, ?, ?, ?, 1, ?)'
    );

    $created = 0;
    foreach ($projects as $project) {
        $needsForm->execute([$project['id']]);
        if ((int)$needsForm->fetchColumn() > 0) {
            continue; // already migrated
        }
        $insertForm->execute([uuid4(), $project['id'], 'Contact form', gen_token(12)]);
        $formId = (int)$pdo->lastInsertId();
        foreach (default_form_fields() as $order => $field) {
            $insertField->execute([$formId, $field['key'], $field['label'], $field['type'], $order]);
        }
        $created++;
    }
    $log("forms: created {$created} default form(s)");

    // ── submissions: public_id, form_id, read state ──────────────────────
    $log('submissions: adding public_id, form_id and read state');
    schema_add_column($pdo, 'submissions', 'public_id', 'CHAR(36) NULL AFTER `id`');
    schema_add_column($pdo, 'submissions', 'form_id', 'INT NULL AFTER `project_id`');
    schema_add_column($pdo, 'submissions', 'is_read', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER `form_id`');
    schema_add_column($pdo, 'submissions', 'read_at', 'DATETIME NULL AFTER `is_read`');

    backfill_public_ids($pdo, 'submissions', $log);
    $pdo->exec('ALTER TABLE `submissions` MODIFY `public_id` CHAR(36) NOT NULL');
    schema_add_index($pdo, 'submissions', 'uniq_submissions_public_id', 'UNIQUE KEY `uniq_submissions_public_id` (`public_id`)');

    // Attach every orphan submission to its project's default form.
    $attached = $pdo->exec(
        'UPDATE submissions s
            JOIN forms f ON f.project_id = s.project_id AND f.is_default = 1
             SET s.form_id = f.id
           WHERE s.form_id IS NULL'
    );
    $log("submissions: attached {$attached} submission(s) to their default form");

    $pdo->exec('ALTER TABLE `submissions` MODIFY `form_id` INT NOT NULL');
    schema_add_index($pdo, 'submissions', 'idx_submissions_form', 'KEY `idx_submissions_form` (`form_id`, `created_at`)');
    schema_add_index($pdo, 'submissions', 'idx_submissions_unread', 'KEY `idx_submissions_unread` (`project_id`, `is_read`)');
    schema_add_constraint(
        $pdo,
        'submissions',
        'fk_submissions_form',
        'FOREIGN KEY (`form_id`) REFERENCES `forms` (`id`) ON DELETE CASCADE'
    );

    // ── submission_values, backfilled from the legacy columns ────────────
    $log('submission_values: creating table');
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `submission_values` (
          `id`            INT          NOT NULL AUTO_INCREMENT,
          `submission_id` INT          NOT NULL,
          `field_key`     VARCHAR(64)  NOT NULL,
          `field_label`   VARCHAR(150) NOT NULL,
          `field_type`    VARCHAR(20)  NOT NULL,
          `value`         TEXT         NOT NULL,
          `sort_order`    INT          NOT NULL DEFAULT 0,
          PRIMARY KEY (`id`),
          UNIQUE KEY `uniq_submission_values_key` (`submission_id`, `field_key`),
          KEY `idx_submission_values_order` (`submission_id`, `sort_order`),
          CONSTRAINT `fk_submission_values_submission` FOREIGN KEY (`submission_id`) REFERENCES `submissions` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    // Only submissions that still have legacy data and no value rows yet.
    $legacy = $pdo->query(
        'SELECT s.id, s.full_name, s.email, s.message
           FROM submissions s
          WHERE NOT EXISTS (SELECT 1 FROM submission_values v WHERE v.submission_id = s.id)
          ORDER BY s.id'
    )->fetchAll();

    $insertValue = $pdo->prepare(
        'INSERT INTO submission_values (submission_id, field_key, field_label, field_type, value, sort_order)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $copied = 0;
    foreach ($legacy as $row) {
        foreach (default_form_fields() as $order => $field) {
            $value = $row[$field['legacy_column']] ?? null;
            if ($value === null) {
                continue;
            }
            $insertValue->execute([$row['id'], $field['key'], $field['label'], $field['type'], $value, $order]);
        }
        $copied++;
    }
    $log("submission_values: copied {$copied} legacy submission(s)");

    // Legacy columns become nullable and are left in place as an untouched
    // pre-migration copy. Nothing reads them from here on.
    $pdo->exec('ALTER TABLE `submissions` MODIFY `full_name` VARCHAR(150) NULL');
    $pdo->exec('ALTER TABLE `submissions` MODIFY `email` VARCHAR(190) NULL');
    $pdo->exec('ALTER TABLE `submissions` MODIFY `message` TEXT NULL');

    // Everything that existed before this migration has already been seen by
    // its owner. Marking it unread would greet them with a wall of false "new".
    $marked = $pdo->exec('UPDATE submissions SET is_read = 1, read_at = created_at WHERE is_read = 0');
    $log("submissions: marked {$marked} pre-existing submission(s) as read");
};

/**
 * The three fields every v1 project implicitly had. `legacy_column` maps each
 * one back to the submissions column it used to live in.
 *
 * @return list<array{key:string, label:string, type:string, legacy_column:string}>
 */
function default_form_fields(): array
{
    return [
        ['key' => 'full_name', 'label' => 'Full name', 'type' => 'text',     'legacy_column' => 'full_name'],
        ['key' => 'email',     'label' => 'Email',     'type' => 'email',    'legacy_column' => 'email'],
        ['key' => 'message',   'label' => 'Message',   'type' => 'textarea', 'legacy_column' => 'message'],
    ];
}

/**
 * Fill public_id on every row that lacks one, generating each UUID in PHP.
 * A single `UPDATE … SET public_id = UUID()` would be far quicker but would
 * produce sequential, time-derived version 1 UUIDs — guessable, and therefore
 * useless as a security boundary.
 */
function backfill_public_ids(PDO $pdo, string $table, callable $log): void
{
    $ids = $pdo->query("SELECT id FROM `{$table}` WHERE public_id IS NULL OR public_id = ''")
               ->fetchAll(PDO::FETCH_COLUMN);
    if (!$ids) {
        return;
    }
    $update = $pdo->prepare("UPDATE `{$table}` SET public_id = ? WHERE id = ?");
    foreach ($ids as $id) {
        $update->execute([uuid4(), $id]);
    }
    $log(sprintf('%s: generated %d public_id(s)', $table, count($ids)));
}
