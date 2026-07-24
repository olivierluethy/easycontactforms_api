<?php
// Favicon resolution for project branding.
//
// The dashboard previews an icon while the customer is still typing a URL, so
// the fast path has to be a pure string transformation with no network access.
// Fetching the page and reading its <link rel="icon"> is the slow fallback.

declare(strict_types=1);

/**
 * Extract a bare hostname from whatever the customer typed.
 *
 * Accepts "acme.com", "www.acme.com", "https://acme.com/contact?x=1", and so
 * on. Returns null when there is nothing usable yet — which is the normal state
 * while somebody is halfway through typing.
 */
function favicon_normalize_host(string $input): ?string
{
    $value = trim($input);
    if ($value === '') {
        return null;
    }

    if (!preg_match('#^[a-z][a-z0-9+.-]*://#i', $value)) {
        $value = 'https://' . $value;
    }

    $host = parse_url($value, PHP_URL_HOST);
    if (!is_string($host) || $host === '') {
        return null;
    }

    $host = strtolower($host);
    $host = preg_replace('/^www\./', '', $host) ?? $host;

    // A hostname needs at least one dot and no spaces or path characters.
    if (!preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/', $host)) {
        return null;
    }

    return $host;
}

/**
 * Icon URLs to try for a host, best first.
 *
 * Both services return a generic placeholder rather than a 404 for unknown
 * domains, which is why the caller may still prefer a parsed <link rel="icon">.
 *
 * @return list<string>
 */
function favicon_candidates(string $host): array
{
    return [
        'https://icons.duckduckgo.com/ip3/' . $host . '.ico',
        'https://www.google.com/s2/favicons?domain=' . rawurlencode($host) . '&sz=64',
    ];
}

/**
 * Fetch the page and read the icon out of its markup.
 *
 * Returns an absolute URL, or null if the page cannot be read or declares no
 * icon. Never throws: this is a nice-to-have, and a slow or hostile site must
 * not take the endpoint down with it.
 */
function favicon_from_page(string $host): ?string
{
    $pageUrl = 'https://' . $host . '/';

    $ch = curl_init($pageUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_TIMEOUT        => 4,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_USERAGENT      => 'EasyContactForm/1.0 (+https://easycontactforms.com)',
        // Only the <head> is needed; stopping early keeps a huge page from
        // tying up the request.
        CURLOPT_RANGE          => '0-65535',
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $html = curl_exec($ch);
    $ok   = $html !== false && (int)curl_getinfo($ch, CURLINFO_HTTP_CODE) < 400;
    curl_close($ch);

    if (!$ok || !is_string($html)) {
        return null;
    }

    if (!preg_match_all('#<link\s[^>]*>#i', $html, $links)) {
        return null;
    }

    foreach ($links[0] as $link) {
        if (!preg_match('/rel\s*=\s*["\']?([^"\'>]+)/i', $link, $rel)) {
            continue;
        }
        $relValue = strtolower($rel[1]);
        if (!str_contains($relValue, 'icon')) {
            continue;
        }
        if (!preg_match('/href\s*=\s*["\']([^"\']+)/i', $link, $href)) {
            continue;
        }
        return favicon_absolute_url(trim($href[1]), $host);
    }

    return null;
}

/** Resolve an href from a page's markup into an absolute https URL. */
function favicon_absolute_url(string $href, string $host): ?string
{
    if ($href === '') {
        return null;
    }
    if (str_starts_with($href, 'data:')) {
        return $href;
    }
    if (preg_match('#^https?://#i', $href)) {
        return $href;
    }
    if (str_starts_with($href, '//')) {
        return 'https:' . $href;
    }
    if (str_starts_with($href, '/')) {
        return 'https://' . $host . $href;
    }
    return 'https://' . $host . '/' . $href;
}
