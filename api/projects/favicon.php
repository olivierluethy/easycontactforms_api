<?php
// GET /projects/favicon?url=<website>[&deep=1]
//
// Resolves a site's icon so the project settings panel can preview it before
// anything is saved. Authenticated: it makes outbound requests on the caller's
// behalf, so it is not something to leave open to the world.
//
// Without `deep`, the response is computed from the hostname alone — no network
// access, so it is fast enough to call on every keystroke (debounced). With
// `deep=1` the page is fetched and its <link rel="icon"> is read, which is
// slower but handles sites the icon services do not know.

require_once __DIR__ . '/../lib/favicon.php';

require_method('GET');

current_user();

$host = favicon_normalize_host((string)($_GET['url'] ?? ''));

if ($host === null) {
    // Not an error — the customer is probably still typing.
    json_ok(['host' => null, 'icon_url' => null, 'candidates' => []]);
}

$candidates = favicon_candidates($host);
$iconUrl    = $candidates[0];

if (!empty($_GET['deep'])) {
    $declared = favicon_from_page($host);
    if ($declared !== null) {
        $iconUrl = $declared;
        array_unshift($candidates, $declared);
    }
}

json_ok([
    'host'       => $host,
    'icon_url'   => $iconUrl,
    'candidates' => $candidates,
]);
