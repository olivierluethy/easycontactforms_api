<?php
// Project payload shaping and input validation.

declare(strict_types=1);

/**
 * The JSON shape of a project.
 *
 * `id` is the public UUID. The internal AUTO_INCREMENT key never leaves the
 * server — exposing it would let anyone walk to a neighbouring project.
 */
function project_payload(array $row): array
{
    return [
        'id'                 => $row['public_id'],
        'project_name'       => $row['project_name'],
        'project_token'      => $row['project_token'],
        'website_url'        => $row['website_url'] ?? null,
        'logo_url'           => $row['logo_url'] ?? null,
        'reply_from_email'   => $row['reply_from_email'] ?? null,
        'created_at'         => $row['created_at'],
        'submission_count'   => (int)($row['submission_count'] ?? 0),
        'unread_count'       => (int)($row['unread_count'] ?? 0),
        'forms_count'        => (int)($row['forms_count'] ?? 0),
        'last_submission_at' => $row['last_submission_at'] ?? null,
    ];
}

/** Validate a project name, or fail the request. */
function validate_project_name(mixed $raw): string
{
    $name = trim((string)$raw);
    if ($name === '') {
        json_error('Project name is required.');
    }
    if (mb_strlen($name) > 150) {
        json_error('Project name must be 150 characters or fewer.');
    }
    return $name;
}

/**
 * Validate an optional website URL. Returns null when the field was cleared.
 *
 * A bare domain ("acme.com") is accepted and normalized to https, because that
 * is what people type.
 */
function validate_website_url(mixed $raw): ?string
{
    $url = trim((string)$raw);
    if ($url === '') {
        return null;
    }
    if (!preg_match('#^https?://#i', $url)) {
        $url = 'https://' . $url;
    }
    if (mb_strlen($url) > 255) {
        json_error('Website URL must be 255 characters or fewer.');
    }
    if (!filter_var($url, FILTER_VALIDATE_URL) || !parse_url($url, PHP_URL_HOST)) {
        json_error('Please enter a valid website URL.');
    }
    return $url;
}

/**
 * Validate an optional logo. Accepts an https URL or an inline data: image.
 *
 * Uploaded logos are stored as data URIs rather than files: the API runs on
 * shared hosting where a writable upload directory is not guaranteed, and a
 * favicon-sized image is small enough that a column is the simpler answer.
 */
function validate_logo_url(mixed $raw): ?string
{
    $logo = trim((string)$raw);
    if ($logo === '') {
        return null;
    }

    if (str_starts_with($logo, 'data:')) {
        if (!preg_match('#^data:image/(png|jpeg|gif|webp|svg\+xml|x-icon|vnd\.microsoft\.icon);base64,[a-z0-9+/=]+$#i', $logo)) {
            json_error('That logo could not be read. Please upload a PNG, JPEG, GIF, WebP, SVG or ICO image.');
        }
        // ~150 KB of base64, i.e. roughly a 110 KB image. Plenty for a logo and
        // small enough to keep the projects list quick to load.
        if (strlen($logo) > 200_000) {
            json_error('That logo is too large. Please use an image under 100 KB.');
        }
        return $logo;
    }

    if (!preg_match('#^https?://#i', $logo) || !filter_var($logo, FILTER_VALIDATE_URL)) {
        json_error('Please enter a valid logo URL.');
    }
    if (mb_strlen($logo) > 1000) {
        json_error('Logo URL must be 1000 characters or fewer.');
    }
    return $logo;
}

/** Validate the optional reply-from address. Returns null when cleared. */
function validate_reply_from_email(mixed $raw): ?string
{
    $email = trim((string)$raw);
    if ($email === '') {
        return null;
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_error('Please enter a valid reply-from email address.');
    }
    if (mb_strlen($email) > 190) {
        json_error('Reply-from email must be 190 characters or fewer.');
    }
    return $email;
}

/**
 * Load one project belonging to the user, with its aggregate counts.
 *
 * The counts are subqueries rather than joins so a project with no submissions
 * still comes back with zeros instead of vanishing.
 */
function project_with_counts(PDO $pdo, int $userId, string $publicId): ?array
{
    $stmt = $pdo->prepare(project_select_sql() . ' WHERE p.user_id = ? AND p.public_id = ? LIMIT 1');
    $stmt->execute([$userId, $publicId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function project_select_sql(): string
{
    return
        'SELECT p.*,
                (SELECT COUNT(*) FROM submissions s WHERE s.project_id = p.id)                     AS submission_count,
                (SELECT COUNT(*) FROM submissions s WHERE s.project_id = p.id AND s.is_read = 0)   AS unread_count,
                (SELECT COUNT(*) FROM forms f WHERE f.project_id = p.id)                           AS forms_count,
                (SELECT MAX(s.created_at) FROM submissions s WHERE s.project_id = p.id)            AS last_submission_at
           FROM projects p';
}
