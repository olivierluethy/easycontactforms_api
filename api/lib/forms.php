<?php
// Form and field helpers shared by the dashboard endpoints and the public
// widget endpoints.
//
// Field definitions live in form_fields; the values a visitor submits live in
// submission_values, each carrying a snapshot of the label and type that were
// in force at submit time. That snapshot is what lets a customer rename or
// delete a field without rewriting the history of what people actually sent.

declare(strict_types=1);

/**
 * Field types the builder and the widget both understand.
 *
 * Adding a type here (plus a matching input in the widget) is all it takes to
 * extend the builder — nothing about the schema is type-specific.
 *
 * @return array<string, array{max:int, multiline:bool, label:string}>
 */
function field_types(): array
{
    return [
        'text'     => ['max' => 255,  'multiline' => false, 'label' => 'Short text'],
        'email'    => ['max' => 190,  'multiline' => false, 'label' => 'Email'],
        'phone'    => ['max' => 40,   'multiline' => false, 'label' => 'Phone'],
        'textarea' => ['max' => 5000, 'multiline' => true,  'label' => 'Long text'],
    ];
}

function is_known_field_type(string $type): bool
{
    return array_key_exists($type, field_types());
}

/**
 * The three fields every project had before custom forms existed.
 *
 * The keys match what the deployed widgets post, so a legacy submission maps
 * onto a default form one-to-one. Do not rename them.
 *
 * @return list<array{key:string, label:string, type:string, required:bool}>
 */
function default_field_definitions(): array
{
    return [
        ['key' => 'full_name', 'label' => 'Full name', 'type' => 'text',     'required' => true],
        ['key' => 'email',     'label' => 'Email',     'type' => 'email',    'required' => true],
        ['key' => 'message',   'label' => 'Message',   'type' => 'textarea', 'required' => true],
    ];
}

/**
 * Validate a list of field definitions coming from the form builder.
 *
 * Returns the normalized list. Calls json_error() and exits on the first
 * problem, so callers can treat a return value as valid.
 *
 * @return list<array{key:string, label:string, type:string, required:bool}>
 */
function validate_field_definitions(mixed $raw): array
{
    if (!is_array($raw) || $raw === []) {
        json_error('A form needs at least one field.');
    }
    if (count($raw) > 40) {
        json_error('A form can have at most 40 fields.');
    }

    $fields = [];
    $seen   = [];

    foreach (array_values($raw) as $index => $field) {
        if (!is_array($field)) {
            json_error('Each field must be an object.');
        }

        $key = strtolower(trim((string)($field['key'] ?? '')));
        if (!preg_match('/^[a-z][a-z0-9_]{0,63}$/', $key)) {
            json_error("Field #" . ($index + 1) . ": the key must start with a letter and contain only lowercase letters, numbers and underscores.");
        }
        if (isset($seen[$key])) {
            json_error("Two fields share the key '{$key}'. Field keys must be unique within a form.");
        }
        $seen[$key] = true;

        $label = trim((string)($field['label'] ?? ''));
        if ($label === '') {
            json_error("Field '{$key}' needs a label.");
        }
        if (mb_strlen($label) > 150) {
            json_error("Field '{$key}': the label must be 150 characters or fewer.");
        }

        $type = strtolower(trim((string)($field['type'] ?? 'text')));
        if (!is_known_field_type($type)) {
            json_error("Field '{$key}': '{$type}' is not a supported field type.");
        }

        $fields[] = [
            'key'      => $key,
            'label'    => $label,
            'type'     => $type,
            'required' => !empty($field['required']),
        ];
    }

    return $fields;
}

/**
 * Create a form with its fields. Returns the new form's internal id.
 *
 * @param list<array{key:string, label:string, type:string, required:bool}> $fields
 */
function create_form(PDO $pdo, int $projectId, string $name, array $fields, bool $isDefault = false): int
{
    $insert = $pdo->prepare(
        'INSERT INTO forms (public_id, project_id, form_name, form_token, is_default) VALUES (?, ?, ?, ?, ?)'
    );
    $insert->execute([uuid4(), $projectId, $name, gen_token(12), $isDefault ? 1 : 0]);
    $formId = (int)$pdo->lastInsertId();

    replace_form_fields($pdo, $formId, $fields);

    return $formId;
}

/**
 * Replace a form's field definitions with the given list.
 *
 * Rows are matched on field_key so that editing a label or reordering fields
 * preserves the field's identity — and therefore stays consistent with the
 * keys already recorded against past submissions.
 *
 * @param list<array{key:string, label:string, type:string, required:bool}> $fields
 */
function replace_form_fields(PDO $pdo, int $formId, array $fields): void
{
    $keys = array_column($fields, 'key');

    // Drop fields the customer removed.
    if ($keys === []) {
        $pdo->prepare('DELETE FROM form_fields WHERE form_id = ?')->execute([$formId]);
    } else {
        $placeholders = implode(',', array_fill(0, count($keys), '?'));
        $pdo->prepare("DELETE FROM form_fields WHERE form_id = ? AND field_key NOT IN ({$placeholders})")
            ->execute(array_merge([$formId], $keys));
    }

    $upsert = $pdo->prepare(
        'INSERT INTO form_fields (form_id, field_key, label, field_type, is_required, sort_order)
         VALUES (:form_id, :field_key, :label, :field_type, :is_required, :sort_order)
         ON DUPLICATE KEY UPDATE
            label       = VALUES(label),
            field_type  = VALUES(field_type),
            is_required = VALUES(is_required),
            sort_order  = VALUES(sort_order)'
    );

    foreach ($fields as $order => $field) {
        $upsert->execute([
            ':form_id'     => $formId,
            ':field_key'   => $field['key'],
            ':label'       => $field['label'],
            ':field_type'  => $field['type'],
            ':is_required' => $field['required'] ? 1 : 0,
            ':sort_order'  => $order,
        ]);
    }
}

/**
 * Fetch a form's fields in display order.
 *
 * @return list<array{key:string, label:string, type:string, required:bool}>
 */
function fetch_form_fields(PDO $pdo, int $formId): array
{
    $stmt = $pdo->prepare(
        'SELECT field_key, label, field_type, is_required
           FROM form_fields WHERE form_id = ? ORDER BY sort_order, id'
    );
    $stmt->execute([$formId]);

    return array_map(
        static fn (array $row) => [
            'key'      => $row['field_key'],
            'label'    => $row['label'],
            'type'     => $row['field_type'],
            'required' => (bool)$row['is_required'],
        ],
        $stmt->fetchAll()
    );
}

/**
 * The JSON shape of a form. `id` is the public UUID — the internal primary key
 * is never part of a response.
 */
function form_payload(array $form, array $fields, ?int $submissionCount = null, ?int $unreadCount = null): array
{
    $payload = [
        'id'         => $form['public_id'],
        'form_name'  => $form['form_name'],
        'form_token' => $form['form_token'],
        'is_default' => (bool)$form['is_default'],
        'created_at' => $form['created_at'],
        'fields'     => $fields,
    ];
    if ($submissionCount !== null) {
        $payload['submission_count'] = $submissionCount;
    }
    if ($unreadCount !== null) {
        $payload['unread_count'] = $unreadCount;
    }
    return $payload;
}

/**
 * Validate what a visitor submitted against a form's field definitions.
 *
 * Returns the values to store, in field order. Empty optional fields are left
 * out entirely rather than stored as blanks, so a submission only ever contains
 * what the visitor actually filled in.
 *
 * Calls json_error() and exits on the first problem, using the field's own
 * label so the message means something on the customer's site.
 *
 * @param list<array{key:string, label:string, type:string, required:bool}> $definitions
 * @param array<string, mixed> $submitted
 * @return list<array{key:string, label:string, type:string, value:string, order:int}>
 */
function validate_submitted_values(array $definitions, array $submitted): array
{
    $types  = field_types();
    $values = [];

    foreach ($definitions as $order => $field) {
        $raw = $submitted[$field['key']] ?? null;

        // Arrays and objects are not values any field type accepts.
        if (is_array($raw) || is_object($raw)) {
            json_error("{$field['label']} is not valid.");
        }

        $value = trim((string)($raw ?? ''));

        if ($value === '') {
            if ($field['required']) {
                json_error("{$field['label']} is required.");
            }
            continue;
        }

        $rules = $types[$field['type']] ?? $types['text'];
        if (mb_strlen($value) > $rules['max']) {
            json_error("{$field['label']} must be {$rules['max']} characters or fewer.");
        }

        if ($field['type'] === 'email' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
            json_error("{$field['label']} must be a valid email address.");
        }
        if ($field['type'] === 'phone' && !preg_match('/^[0-9+()\/.\s-]{3,40}$/', $value)) {
            json_error("{$field['label']} must be a valid phone number.");
        }

        $values[] = [
            'key'   => $field['key'],
            'label' => $field['label'],
            'type'  => $field['type'],
            'value' => $value,
            'order' => $order,
        ];
    }

    return $values;
}

/** The form a bare project_token submits to. */
function default_form_for_project(PDO $pdo, int $projectId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT * FROM forms WHERE project_id = ? ORDER BY is_default DESC, id ASC LIMIT 1'
    );
    $stmt->execute([$projectId]);
    $form = $stmt->fetch();

    return $form ?: null;
}
