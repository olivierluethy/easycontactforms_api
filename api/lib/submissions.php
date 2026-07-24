<?php
// Submission payload shaping.
//
// A submission's values are rows in submission_values, each carrying the label
// and type that were in force when it was submitted. Responses therefore
// describe themselves: the dashboard can render any form's submissions without
// knowing anything about that form's current definition.

declare(strict_types=1);

/**
 * Load the values for a set of submissions in one query.
 *
 * @param list<int> $submissionIds
 * @return array<int, list<array{key:string, label:string, type:string, value:string}>>
 */
function fetch_submission_values(PDO $pdo, array $submissionIds): array
{
    if ($submissionIds === []) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($submissionIds), '?'));
    $stmt = $pdo->prepare(
        "SELECT submission_id, field_key, field_label, field_type, value
           FROM submission_values
          WHERE submission_id IN ({$placeholders})
          ORDER BY submission_id, sort_order, id"
    );
    $stmt->execute($submissionIds);

    $grouped = [];
    foreach ($stmt->fetchAll() as $row) {
        $grouped[(int)$row['submission_id']][] = [
            'key'   => $row['field_key'],
            'label' => $row['field_label'],
            'type'  => $row['field_type'],
            'value' => $row['value'],
        ];
    }
    return $grouped;
}

/**
 * The address the dashboard's Reply button should use: the first value stored
 * against an email-typed field that actually looks like an address.
 *
 * Computed here rather than in the client so every consumer agrees on it.
 *
 * @param list<array{key:string, label:string, type:string, value:string}> $values
 */
function reply_to_address(array $values): ?string
{
    foreach ($values as $value) {
        if ($value['type'] === 'email' && filter_var($value['value'], FILTER_VALIDATE_EMAIL)) {
            return $value['value'];
        }
    }
    return null;
}

/**
 * The JSON shape of a submission. Both ids are public UUIDs.
 *
 * @param list<array{key:string, label:string, type:string, value:string}> $values
 */
function submission_payload(array $row, array $values): array
{
    return [
        'id'         => $row['public_id'],
        'form_id'    => $row['form_public_id'],
        'form_name'  => $row['form_name'],
        'is_read'    => (bool)$row['is_read'],
        'read_at'    => $row['read_at'],
        'created_at' => $row['created_at'],
        'values'     => $values,
        'reply_to'   => reply_to_address($values),
    ];
}

/**
 * Base query for submissions, joined to their form so responses can name it.
 * Callers append their own WHERE clause; ownership is always applied by the
 * caller through require_project()/require_form() first.
 */
function submission_select_sql(): string
{
    return
        'SELECT s.id, s.public_id, s.is_read, s.read_at, s.created_at,
                f.public_id AS form_public_id, f.form_name
           FROM submissions s
           JOIN forms f ON f.id = s.form_id';
}
