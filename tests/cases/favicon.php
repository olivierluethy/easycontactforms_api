<?php
// Favicon resolution. The normalization is a pure function and is tested
// directly; the endpoint is tested for its contract and its auth requirement.
//
// Nothing here reaches the network: the deep-fetch path depends on third-party
// sites being up, which is not something a test suite should rely on.

declare(strict_types=1);

require_once ECF_API_ROOT . '/api/lib/favicon.php';

test('favicon: hostnames are normalized out of whatever the customer types', function () {
    assert_same('acme.com', favicon_normalize_host('acme.com'));
    assert_same('acme.com', favicon_normalize_host('www.acme.com'));
    assert_same('acme.com', favicon_normalize_host('https://acme.com'));
    assert_same('acme.com', favicon_normalize_host('http://www.acme.com/contact?ref=1#top'));
    assert_same('acme.com', favicon_normalize_host('  HTTPS://ACME.COM/  '));
    assert_same('shop.acme.co.uk', favicon_normalize_host('shop.acme.co.uk/products'));
    assert_same('acme.com', favicon_normalize_host('acme.com:8443/x'));
});

test('favicon: half-typed and invalid input yields null rather than an error', function () {
    // The settings panel calls this on every keystroke, so the in-between
    // states have to be ordinary, not exceptional.
    assert_same(null, favicon_normalize_host(''));
    assert_same(null, favicon_normalize_host('   '));
    assert_same(null, favicon_normalize_host('h'));
    assert_same(null, favicon_normalize_host('https://'));
    assert_same(null, favicon_normalize_host('acme'));
    assert_same(null, favicon_normalize_host('not a domain'));
    assert_same(null, favicon_normalize_host('http://'));
});

test('favicon: candidates are ordered and correctly encoded', function () {
    $candidates = favicon_candidates('acme.com');
    assert_count_is(2, $candidates);
    assert_same('https://icons.duckduckgo.com/ip3/acme.com.ico', $candidates[0]);
    assert_contains('domain=acme.com', $candidates[1]);
    assert_contains('sz=64', $candidates[1]);
});

test('favicon: relative hrefs resolve against the site', function () {
    assert_same('https://acme.com/favicon.ico', favicon_absolute_url('/favicon.ico', 'acme.com'));
    assert_same('https://acme.com/assets/icon.png', favicon_absolute_url('assets/icon.png', 'acme.com'));
    assert_same('https://cdn.acme.com/icon.png', favicon_absolute_url('//cdn.acme.com/icon.png', 'acme.com'));
    assert_same('https://other.example/icon.png', favicon_absolute_url('https://other.example/icon.png', 'acme.com'));
    assert_same('data:image/png;base64,AAA', favicon_absolute_url('data:image/png;base64,AAA', 'acme.com'));
    assert_same(null, favicon_absolute_url('', 'acme.com'));
});

test('favicon: the endpoint returns a candidate icon for a valid domain', function () {
    $token = register_user('favicon@example.test');

    $res = http_get('/projects/favicon?url=' . urlencode('https://acme.com/contact'), $token);
    assert_status(200, $res, 'resolve favicon');
    assert_same('acme.com', $res['data']['host']);
    assert_same('https://icons.duckduckgo.com/ip3/acme.com.ico', $res['data']['icon_url']);
    assert_count_is(2, $res['data']['candidates']);
});

test('favicon: the endpoint treats half-typed input as an empty result', function () {
    $token = register_user('favicon2@example.test');

    $res = http_get('/projects/favicon?url=' . urlencode('ac'), $token);
    assert_status(200, $res, 'partial input should not be an error');
    assert_same(null, $res['data']['host']);
    assert_same(null, $res['data']['icon_url']);
});

test('favicon: the endpoint requires authentication', function () {
    // It makes outbound requests, so it must not be usable by anonymous callers.
    assert_status(401, http_get('/projects/favicon?url=acme.com'), 'favicon without a token');
});
