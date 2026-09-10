<?php
/**
 * xui_core.php — shared client library for 3x-ui-family panels.
 *
 * Used by the `x_ui` panel type (modern 3x-ui, multi-inbound) and the `vpn_ui`
 * panel type (Sir-MmD/vpn-ui, a 3x-ui 2.9.3 fork with 19 protocols).
 *
 * Design notes:
 *  - One purchased account is replicated across N inbounds sharing ONE
 *    uuid / email / subId. Every CRUD helper therefore takes an ARRAY of
 *    inbound ids and loops.
 *  - Auth is a signed session cookie obtained from POST {base}/login. The raw
 *    cookie jar lives in a per-panel temp file; the login timestamp is cached
 *    in marzban_panel.datelogin ({"time": "...","cookie_file": "..."}).
 *  - vpn-ui returns HTTP 404 (not 401) for unauthenticated /panel/api/* calls
 *    and needs the header `X-Requested-With: XMLHttpRequest`. Both are handled
 *    here unconditionally (harmless for upstream 3x-ui).
 *  - POST bodies are sent application/x-www-form-urlencoded with `settings`
 *    as a JSON string — the shape both panels' gin handlers accept.
 *
 * This file does not modify or depend on x-ui_single.php; where that file is
 * already loaded (via panels.php) a couple of its link helpers are reused
 * opportunistically behind function_exists() guards.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/request.php';
require_once __DIR__ . '/function.php';

if (!defined('XUI_LOGIN_TTL')) {
    define('XUI_LOGIN_TTL', 3000); // seconds a cached session cookie is trusted
}

/** Protocols that yield a URI / subscription entry (Xray-native). */
function xui_xray_protocols(): array
{
    return ['vless', 'vmess', 'trojan', 'shadowsocks', 'hysteria', 'tuic', 'anytls'];
}

/** All protocols vpn-ui can host. */
function xui_all_protocols(): array
{
    return [
        'vless', 'vmess', 'trojan', 'shadowsocks', 'hysteria', 'tuic', 'anytls', 'naive',
        'l2tp', 'pptp', 'openvpn', 'openconnect', 'sstp', 'ikev2',
        'wireguard', 'wg-c', 'awg', 'gre', 'mtproto', 'ssh',
    ];
}

/* -------------------------------------------------------------------------- */
/*  Panel row + transport                                                    */
/* -------------------------------------------------------------------------- */

function xui_panel_by_code($code_panel)
{
    return select("marzban_panel", "*", "code_panel", $code_panel, "select");
}

function xui_panel_by_name($name_panel)
{
    return select("marzban_panel", "*", "name_panel", $name_panel, "select");
}

function xui_base(array $panel): string
{
    return rtrim((string) $panel['url_panel'], '/');
}

function xui_cookie_file($code_panel): string
{
    $dir = sys_get_temp_dir() ?: __DIR__;
    return $dir . DIRECTORY_SEPARATOR . 'xui_' . md5((string) $code_panel) . '.cookie';
}

function xui_headers(): array
{
    return [
        'Accept: application/json',
        'X-Requested-With: XMLHttpRequest',
    ];
}

/**
 * Ensure we hold a fresh session cookie for the panel.
 * Returns true on success or ['error' => '...'] / ['success' => false, 'msg' => '...'].
 */
function xui_login($code_panel, bool $force = false)
{
    $panel = xui_panel_by_code($code_panel);
    if (!is_array($panel) || empty($panel)) {
        return ['error' => 'Panel configuration not found.'];
    }

    $cookieFile = xui_cookie_file($code_panel);

    if (!$force && is_file($cookieFile) && filesize($cookieFile) > 0 && !empty($panel['datelogin'])) {
        $cache = json_decode($panel['datelogin'], true);
        if (is_array($cache) && !empty($cache['time'])) {
            $age = time() - strtotime($cache['time']);
            if ($age >= 0 && $age <= XUI_LOGIN_TTL) {
                return true;
            }
        }
    }

    @unlink($cookieFile);

    $fields = [
        'username' => (string) $panel['username_panel'],
        'password' => (string) $panel['password_panel'],
    ];
    if (!empty($panel['twofa_secret'])) {
        $code = xui_totp((string) $panel['twofa_secret']);
        if ($code !== null) {
            $fields['twoFactorCode'] = $code;
        }
    }

    $req = new CurlRequest(xui_base($panel) . '/login');
    $req->setTimeout(12);
    $req->setHeaders(['X-Requested-With: XMLHttpRequest']);
    $req->setCookie($cookieFile);
    $res = $req->post($fields);

    if (!empty($res['error'])) {
        return ['error' => $res['error']];
    }
    $body = json_decode($res['body'] ?? '', true);
    if (is_array($body) && array_key_exists('success', $body) && !$body['success']) {
        return ['success' => false, 'msg' => $body['msg'] ?? 'Login rejected by panel.'];
    }
    if (!is_file($cookieFile) || filesize($cookieFile) === 0) {
        return ['success' => false, 'msg' => 'Panel did not return a session cookie.'];
    }

    update(
        "marzban_panel",
        "datelogin",
        json_encode(['time' => date('Y/m/d H:i:s'), 'cookie_file' => $cookieFile]),
        'code_panel',
        $code_panel
    );

    return true;
}

/**
 * Perform an authenticated request against /panel/api/inbounds.
 * $path is appended to  {base}/panel/api/inbounds .
 * $form (array) is sent form-encoded on POST; null => GET.
 * Retries once after a forced re-login when the panel answers 404/401/403
 * or a success:false auth message.
 *
 * Returns ['status' => int|null, 'body' => string|null, 'error' => ?string,
 *          'json' => mixed|null].
 */
function xui_req($code_panel, string $method, string $path, ?array $form = null, bool $allowRetry = true): array
{
    $panel = xui_panel_by_code($code_panel);
    if (!is_array($panel) || empty($panel)) {
        return ['status' => null, 'body' => null, 'error' => 'Panel not found', 'json' => null];
    }

    $login = xui_login($code_panel);
    if ($login !== true) {
        $msg = $login['error'] ?? ($login['msg'] ?? 'login failed');
        return ['status' => null, 'body' => null, 'error' => $msg, 'json' => null];
    }

    $cookieFile = xui_cookie_file($code_panel);
    $url = xui_base($panel) . '/panel/api/inbounds' . $path;

    $req = new CurlRequest($url);
    $req->setTimeout(15);
    $req->setHeaders(xui_headers());
    $req->setCookie($cookieFile);

    $res = strtoupper($method) === 'POST' ? $req->post($form ?? []) : $req->get();

    $status = $res['status'] ?? null;
    $body = $res['body'] ?? null;
    $error = $res['error'] ?? null;
    $json = is_string($body) ? json_decode($body, true) : null;

    $looksUnauth = in_array((int) $status, [401, 403, 404, 307], true)
        || (is_array($json) && isset($json['success']) && !$json['success']
            && preg_match('/login|unauthor|forbidden|expired|session/i', (string) ($json['msg'] ?? '')));

    if ($looksUnauth && $allowRetry) {
        xui_login($code_panel, true);
        return xui_req($code_panel, $method, $path, $form, false);
    }

    return ['status' => $status, 'body' => $body, 'error' => $error, 'json' => $json];
}

/* -------------------------------------------------------------------------- */
/*  Inbounds                                                                  */
/* -------------------------------------------------------------------------- */

function xui_inbounds_list($code_panel): array
{
    $res = xui_req($code_panel, 'GET', '/list');
    if (!empty($res['error']) || !is_array($res['json']) || empty($res['json']['success'])) {
        return [];
    }
    return is_array($res['json']['obj']) ? $res['json']['obj'] : [];
}

/**
 * Fetch a single inbound (settings / streamSettings decoded). Per-request cache.
 */
function xui_inbound_get($code_panel, $inboundId)
{
    static $cache = [];
    $key = $code_panel . ':' . $inboundId;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $res = xui_req($code_panel, 'GET', '/get/' . (int) $inboundId);
    $obj = null;
    if (is_array($res['json']) && !empty($res['json']['success']) && is_array($res['json']['obj'])) {
        $obj = $res['json']['obj'];
        foreach (['settings', 'streamSettings', 'sniffing'] as $k) {
            if (isset($obj[$k]) && is_string($obj[$k])) {
                $decoded = json_decode($obj[$k], true);
                if (is_array($decoded)) {
                    $obj[$k] = $decoded;
                }
            }
        }
    }
    $cache[$key] = $obj;
    return $obj;
}

function xui_inbound_protocol($code_panel, $inboundId): ?string
{
    $inb = xui_inbound_get($code_panel, $inboundId);
    if (is_array($inb) && !empty($inb['protocol'])) {
        return strtolower((string) $inb['protocol']);
    }
    foreach (xui_inbounds_list($code_panel) as $row) {
        if ((int) ($row['id'] ?? 0) === (int) $inboundId && !empty($row['protocol'])) {
            return strtolower((string) $row['protocol']);
        }
    }
    return null;
}

/* -------------------------------------------------------------------------- */
/*  Client object builder                                                     */
/* -------------------------------------------------------------------------- */

/**
 * Build one client entry for settings.clients[].
 *
 * $f keys: email, uuid, expiryMs, totalBytes, subId, note, flow, password,
 *          limitIp, sshUser.
 */
function xui_build_client(string $protocol, array $f): array
{
    $protocol = strtolower($protocol);
    $email = $f['email'];
    $uuid = $f['uuid'] ?? generateUUID();
    $pass = $f['password'] ?? xui_secret(20);
    $secret = $f['secret'] ?? xui_secret(16, true);
    $sshUser = $f['sshUser'] ?? preg_replace('/[^a-z0-9_]/i', '', explode('@', (string) $email)[0]);
    $subId = $f['subId'] ?? '';
    $note = (string) ($f['note'] ?? '');

    $base = [
        'email' => $email,
        'enable' => true,
        'expiryTime' => (int) ($f['expiryMs'] ?? 0),
        'totalGB' => (int) ($f['totalBytes'] ?? 0),
        'limitIp' => (int) ($f['limitIp'] ?? 0),
        'tgId' => '',
        'subId' => $subId,
        'comment' => $note,
        'reset' => 0,
    ];

    switch ($protocol) {
        case 'vless':
            return $base + ['id' => $uuid, 'flow' => (string) ($f['flow'] ?? '')];
        case 'vmess':
            return $base + ['id' => $uuid, 'security' => 'auto'];
        case 'tuic':
            return $base + ['id' => $uuid, 'password' => $pass];
        case 'trojan':
        case 'anytls':
            return $base + ['password' => $pass];
        case 'naive':
            return $base + ['password' => $pass, 'username' => $email];
        case 'shadowsocks':
            return $base + ['password' => $pass, 'method' => ''];
        case 'hysteria':
            return $base + ['auth' => $pass, 'version' => 2];
        case 'wireguard':
        case 'wg-c':
        case 'awg':
            return $base + ['id' => $email, 'devices' => [new stdClass()]];
        case 'gre':
            return $base + ['id' => $email, 'peers' => []];
        case 'mtproto':
            return $base + [
                'id' => $email,
                'secret' => $secret,
                'modeClassic' => true,
                'modeSecure' => true,
                'modeTls' => true,
                'tlsDomain' => 'www.google.com',
            ];
        case 'ssh':
            return $base + ['id' => $sshUser, 'password' => $pass];
        case 'l2tp':
        case 'pptp':
        case 'openvpn':
        case 'openconnect':
        case 'sstp':
        case 'ikev2':
            return $base + ['id' => explode('@', (string) $email)[0], 'password' => $pass];
        default:
            // Unknown protocol — fall back to a UUID identity, safest for Xray.
            return $base + ['id' => $uuid];
    }
}

/** Client identity used in the updateClient / delClient URL path. */
function xui_client_identity(string $protocol, array $client): string
{
    $protocol = strtolower($protocol);
    if (in_array($protocol, ['vless', 'vmess', 'tuic', 'wireguard', 'wg-c', 'awg', 'gre', 'mtproto'], true)) {
        return (string) ($client['id'] ?? $client['email'] ?? '');
    }
    if ($protocol === 'shadowsocks') {
        return (string) ($client['email'] ?? '');
    }
    if ($protocol === 'hysteria') {
        return (string) ($client['auth'] ?? $client['email'] ?? '');
    }
    if (in_array($protocol, ['ssh', 'l2tp', 'pptp', 'openvpn', 'openconnect', 'sstp', 'ikev2'], true)) {
        return (string) ($client['id'] ?? $client['email'] ?? '');
    }
    // trojan, anytls, naive
    return (string) ($client['password'] ?? $client['id'] ?? $client['email'] ?? '');
}

function xui_settings_blob(string $protocol, array $client): string
{
    $settings = ['clients' => [$client]];
    if (in_array(strtolower($protocol), ['vless', 'vmess'], true)) {
        $settings['decryption'] = 'none';
        $settings['fallbacks'] = [];
    }
    return json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/* -------------------------------------------------------------------------- */
/*  Client CRUD (multi-inbound)                                               */
/* -------------------------------------------------------------------------- */

/**
 * Create the same account (shared email / uuid / subId) in every inbound id.
 *
 * $f is the field bag passed to xui_build_client (email, uuid, expiryMs,
 * totalBytes, subId, note, flow). Protocol is auto-detected per inbound.
 *
 * Returns ['success' => bool, 'msg' => string, 'results' => [id => bool],
 *          'protocols' => [id => proto]].
 */
function xui_add_client($code_panel, array $inboundIds, array $f, ?array $allowProtocols = null): array
{
    $inboundIds = xui_norm_ids($inboundIds);
    if (empty($inboundIds)) {
        return ['success' => false, 'msg' => 'no inbound id configured', 'results' => []];
    }

    $panel = xui_panel_by_code($code_panel);
    $ok = [];
    $results = [];
    $protocols = [];
    $clients = [];
    $lastMsg = '';

    foreach ($inboundIds as $id) {
        $proto = xui_inbound_protocol($code_panel, $id) ?? strtolower((string) ($panel['default_protocol'] ?? 'vless'));
        $protocols[$id] = $proto;

        if (is_array($allowProtocols) && !in_array($proto, $allowProtocols, true)) {
            $results[$id] = false;
            $lastMsg = "inbound {$id}: protocol '{$proto}' not supported by this panel type";
            continue;
        }

        $client = xui_build_client($proto, $f);
        $clients[$id] = $client;
        $form = ['id' => (int) $id, 'settings' => xui_settings_blob($proto, $client)];
        $res = xui_req($code_panel, 'POST', '/addClient', $form);
        $good = is_array($res['json']) && !empty($res['json']['success']);
        $results[$id] = $good;
        if ($good) {
            $ok[] = $id;
        } else {
            $lastMsg = $res['error'] ?? ($res['json']['msg'] ?? 'addClient failed');
        }
    }

    if (count($ok) === count($inboundIds)) {
        return ['success' => true, 'msg' => 'ok', 'results' => $results, 'protocols' => $protocols, 'clients' => $clients];
    }

    // Partial failure — roll back the ones that were created so we don't leak accounts.
    if (!empty($ok)) {
        xui_del_client($code_panel, $ok, $f['email']);
    }
    return ['success' => false, 'msg' => $lastMsg ?: 'partial failure', 'results' => $results, 'protocols' => $protocols, 'clients' => $clients];
}

/**
 * Locate an account by email across all inbounds.
 *
 * Returns ['found' => bool, 'email' => ?string, 'protocol' => ?string,
 *          'uuid' => ?string, 'identity' => ?string, 'subId' => ?string,
 *          'inboundIds' => int[], 'clients' => [id => clientArray],
 *          'stats' => mergedTrafficArray].
 */
function xui_find_client($code_panel, string $email): array
{
    $out = [
        'found' => false, 'email' => $email, 'protocol' => null, 'uuid' => null,
        'identity' => null, 'subId' => null, 'inboundIds' => [], 'clients' => [], 'stats' => null,
    ];

    $up = 0;
    $down = 0;
    $total = 0;
    $expiry = 0;
    $enable = false;
    $lastOnline = 0;

    foreach (xui_inbounds_list($code_panel) as $inb) {
        $id = (int) ($inb['id'] ?? 0);
        $proto = strtolower((string) ($inb['protocol'] ?? ''));

        $settings = $inb['settings'] ?? '{}';
        if (is_string($settings)) {
            $settings = json_decode($settings, true);
        }
        $clients = is_array($settings) && isset($settings['clients']) ? $settings['clients'] : [];

        foreach ($clients as $c) {
            if ((string) ($c['email'] ?? '') !== $email) {
                continue;
            }
            $out['found'] = true;
            $out['protocol'] = $out['protocol'] ?? $proto;
            $out['uuid'] = $out['uuid'] ?? ($c['id'] ?? null);
            $out['subId'] = $out['subId'] ?? ($c['subId'] ?? null);
            $out['identity'] = $out['identity'] ?? xui_client_identity($proto, $c);
            $out['inboundIds'][] = $id;
            $out['clients'][$id] = $c;

            foreach (($inb['clientStats'] ?? []) as $s) {
                if ((string) ($s['email'] ?? '') !== $email) {
                    continue;
                }
                $up += (int) ($s['up'] ?? 0);
                $down += (int) ($s['down'] ?? 0);
                $total = max($total, (int) ($s['total'] ?? 0));
                $expiry = $expiry ?: (int) ($s['expiryTime'] ?? 0);
                $enable = $enable || (bool) ($s['enable'] ?? false);
                $lastOnline = max($lastOnline, (int) ($s['lastOnline'] ?? $s['last_online'] ?? 0));
            }
        }
    }

    if ($out['found']) {
        $out['inboundIds'] = array_values(array_unique($out['inboundIds']));
        $out['stats'] = [
            'email' => $email,
            'up' => $up,
            'down' => $down,
            'total' => $total,
            'expiryTime' => $expiry,
            'enable' => $enable,
            'lastOnline' => $lastOnline,
        ];
    }
    return $out;
}

/**
 * Aggregate traffic for an account. Tries getClientTraffics/{email} first,
 * falls back to a full inbound scan.
 *
 * Returns ['success' => bool, 'obj' => array|null, 'msg' => string].
 */
function xui_get_traffic($code_panel, string $email): array
{
    $res = xui_req($code_panel, 'GET', '/getClientTraffics/' . rawurlencode($email));
    if (is_array($res['json']) && !empty($res['json']['success']) && is_array($res['json']['obj'])) {
        return ['success' => true, 'obj' => $res['json']['obj'], 'msg' => 'ok'];
    }

    $acct = xui_find_client($code_panel, $email);
    if ($acct['found'] && is_array($acct['stats'])) {
        $obj = $acct['stats'];
        $obj['subId'] = $acct['subId'];
        $obj['uuid'] = $acct['uuid'];
        $obj['inboundIds'] = $acct['inboundIds'];
        return ['success' => true, 'obj' => $obj, 'msg' => 'ok'];
    }

    $msg = is_array($res['json']) ? ($res['json']['msg'] ?? 'user not found') : ($res['error'] ?? 'user not found');
    return ['success' => false, 'obj' => null, 'msg' => $msg];
}

/**
 * Apply a partial patch to the account in every inbound it lives in.
 * $patch is merged into each stored client object (existing fields preserved).
 *
 * Returns ['success' => bool, 'msg' => string, 'results' => [id => bool]].
 */
function xui_update_client($code_panel, array $inboundIds, string $email, array $patch): array
{
    $acct = xui_find_client($code_panel, $email);
    if (!$acct['found']) {
        return ['success' => false, 'msg' => 'account not found on panel', 'results' => []];
    }

    $ids = xui_norm_ids($inboundIds ?: $acct['inboundIds']);
    $ids = array_values(array_intersect($ids, $acct['inboundIds'])) ?: $acct['inboundIds'];

    $results = [];
    $lastMsg = '';
    foreach ($ids as $id) {
        $proto = xui_inbound_protocol($code_panel, $id) ?? (string) $acct['protocol'];
        $current = $acct['clients'][$id] ?? [];
        $merged = array_replace_recursive($current, $patch);
        $identity = xui_client_identity($proto, $current ?: $merged);
        if ($identity === '') {
            $results[$id] = false;
            $lastMsg = "inbound {$id}: could not resolve client identity";
            continue;
        }
        $form = ['id' => (int) $id, 'settings' => xui_settings_blob($proto, $merged)];
        $res = xui_req($code_panel, 'POST', '/updateClient/' . rawurlencode($identity), $form);
        $good = is_array($res['json']) && !empty($res['json']['success']);
        $results[$id] = $good;
        if (!$good) {
            $lastMsg = $res['error'] ?? ($res['json']['msg'] ?? 'updateClient failed');
        }
    }

    $allGood = $results && !in_array(false, $results, true);
    return ['success' => $allGood, 'msg' => $allGood ? 'ok' : ($lastMsg ?: 'update failed'), 'results' => $results];
}

/** Delete the account from every given inbound. "not found" counts as success. */
function xui_del_client($code_panel, array $inboundIds, string $email): array
{
    $ids = xui_norm_ids($inboundIds);
    if (empty($ids)) {
        $acct = xui_find_client($code_panel, $email);
        $ids = $acct['inboundIds'];
    }
    $results = [];
    $lastMsg = '';
    foreach ($ids as $id) {
        $res = xui_req($code_panel, 'POST', '/' . (int) $id . '/delClientByEmail/' . rawurlencode($email));
        $json = $res['json'];
        $good = is_array($json) && !empty($json['success']);
        if (!$good && is_array($json) && preg_match('/not found|no client/i', (string) ($json['msg'] ?? ''))) {
            $good = true;
        }
        $results[$id] = $good;
        if (!$good) {
            $lastMsg = $res['error'] ?? (is_array($json) ? ($json['msg'] ?? 'delete failed') : 'delete failed');
        }
    }
    $allGood = $results && !in_array(false, $results, true);
    return ['success' => $allGood || empty($results), 'msg' => $allGood ? 'ok' : ($lastMsg ?: 'delete failed'), 'results' => $results];
}

/** Zero the traffic counters in every given inbound. */
function xui_reset_traffic($code_panel, array $inboundIds, string $email): array
{
    $ids = xui_norm_ids($inboundIds);
    if (empty($ids)) {
        $acct = xui_find_client($code_panel, $email);
        $ids = $acct['inboundIds'];
    }
    $results = [];
    $lastMsg = '';
    foreach ($ids as $id) {
        $res = xui_req($code_panel, 'POST', '/' . (int) $id . '/resetClientTraffic/' . rawurlencode($email));
        $good = is_array($res['json']) && !empty($res['json']['success']);
        $results[$id] = $good;
        if (!$good) {
            $lastMsg = $res['error'] ?? (is_array($res['json']) ? ($res['json']['msg'] ?? 'reset failed') : 'reset failed');
        }
    }
    $allGood = $results && !in_array(false, $results, true);
    return ['success' => $allGood, 'msg' => $allGood ? 'ok' : ($lastMsg ?: 'reset failed'), 'results' => $results];
}

/** Optional cron helper: purge depleted clients (id -1 == all inbounds). */
function xui_del_depleted($code_panel, int $inboundId = -1): array
{
    $res = xui_req($code_panel, 'POST', '/delDepletedClients/' . $inboundId);
    return ['success' => is_array($res['json']) && !empty($res['json']['success']), 'msg' => is_array($res['json']) ? ($res['json']['msg'] ?? '') : ($res['error'] ?? '')];
}

/* -------------------------------------------------------------------------- */
/*  Link / config assembly                                                    */
/* -------------------------------------------------------------------------- */

/**
 * Build the deliverable configs for an account.
 *
 * Returns [
 *   'subscription_url' => string,
 *   'configs'          => string[]   (URIs and/or plaintext credential blocks),
 *   'files'            => [['name' => 'x.conf', 'content' => '...'], ...],
 * ]
 */
function xui_client_links($code_panel, array $inboundIds, array $client, string $subId, array $opts = []): array
{
    $panel = xui_panel_by_code($code_panel);
    $ids = xui_norm_ids($inboundIds);
    $email = (string) ($client['email'] ?? ($opts['email'] ?? ''));

    $subBase = rtrim((string) ($panel['linksubx'] ?: xui_base($panel)), '/');
    $subUrl = $subBase . '/' . $subId;

    $configs = [];
    $files = [];

    $protos = [];
    foreach ($ids as $id) {
        $protos[$id] = xui_inbound_protocol($code_panel, $id) ?? strtolower((string) ($panel['default_protocol'] ?? 'vless'));
    }
    $hasXray = (bool) array_intersect($protos, xui_xray_protocols());

    if ($hasXray) {
        $links = xui_fetch_sub_links($subUrl);
        if (($panel['sub_json'] ?? '') !== 'offsubjson') {
            // best-effort; ignored if the panel has no json endpoint
            xui_http_get(xui_base($panel) . '/json/' . $subId);
        }
        foreach ($links as $l) {
            $configs[] = $l;
        }
        if (empty($links)) {
            foreach ($ids as $id) {
                if (!in_array($protos[$id], xui_xray_protocols(), true)) {
                    continue;
                }
                $built = xui_build_uri_from_inbound($code_panel, $id, $client);
                if ($built) {
                    $configs[] = $built;
                }
            }
        }
    }

    foreach ($ids as $id) {
        $proto = $protos[$id];
        switch ($proto) {
            case 'wireguard':
            case 'wg-c':
            case 'awg':
                $endpoint = $proto === 'awg' ? 'awg-configs' : 'wgc-configs';
                $txt = xui_pull_config_text($code_panel, $id, $email, $endpoint);
                if ($txt !== null && $txt !== '') {
                    $files[] = ['name' => ($proto === 'awg' ? 'awg-' : 'wg-') . $id . '.conf', 'content' => $txt];
                    $configs[] = strtoupper($proto) . " (inbound {$id}):\n" . $txt;
                }
                break;
            case 'gre':
                $txt = xui_pull_config_text($code_panel, $id, $email, 'gre-configs');
                if ($txt !== null && $txt !== '') {
                    $configs[] = "GRE (inbound {$id}):\n" . $txt;
                }
                break;
            case 'ssh':
                $txt = xui_pull_config_text($code_panel, $id, $email, 'ssh-configs');
                if ($txt !== null && $txt !== '') {
                    $configs[] = "SSH (inbound {$id}):\n" . $txt;
                }
                break;
            case 'openvpn':
                foreach (['udp', 'tcp'] as $ovpnProto) {
                    $r = xui_req($code_panel, 'GET', '/' . (int) $id . '/ovpn/' . $ovpnProto);
                    $body = is_string($r['body'] ?? null) ? $r['body'] : '';
                    $isErr = is_array($r['json']) && array_key_exists('success', $r['json']) && empty($r['json']['success']);
                    if ((int) ($r['status'] ?? 0) >= 200 && (int) ($r['status'] ?? 0) < 300 && trim($body) !== '' && !$isErr) {
                        $files[] = ['name' => 'openvpn-' . $id . '-' . $ovpnProto . '.ovpn', 'content' => $body];
                        $configs[] = "OPENVPN {$ovpnProto} (inbound {$id}):\n" . $body;
                    }
                }
                break;
            case 'mtproto':
                $link = xui_mtproto_link($code_panel, $id, $client);
                if ($link !== null) {
                    $configs[] = $link;
                }
                break;
            case 'l2tp':
            case 'pptp':
            case 'ikev2':
            case 'sstp':
            case 'openconnect':
                $configs[] = xui_credentials_block($code_panel, $id, $proto, $client);
                break;
        }
    }

    return ['subscription_url' => $subUrl, 'configs' => array_values(array_filter($configs)), 'files' => $files];
}

/**
 * Build a Telegram MTProto proxy link. vpn-ui has no endpoint for this — the
 * link is assembled from the client's `secret` plus the inbound host/port.
 * Mode precedence: FakeTLS ("ee" + secret + hex(domain)) > Secure ("dd" + secret)
 * > Classic (secret).
 */
function xui_mtproto_link($code_panel, $inboundId, array $client): ?string
{
    $panel = xui_panel_by_code($code_panel);
    $host = parse_url(xui_base($panel), PHP_URL_HOST) ?: '';
    if ($host === '') {
        return null;
    }
    $inb = xui_inbound_get($code_panel, $inboundId);
    $port = (int) ($inb['port'] ?? 0);

    $secret = (string) ($client['secret'] ?? '');
    if ($secret === '' && is_array($inb['settings']['clients'] ?? null)) {
        foreach ($inb['settings']['clients'] as $c) {
            if (($c['email'] ?? '') === ($client['email'] ?? '') && !empty($c['secret'])) {
                $secret = (string) $c['secret'];
                break;
            }
        }
    }
    if ($secret === '' || $port === 0) {
        return null;
    }

    if (!empty($client['modeTls'])) {
        $domain = (string) ($client['tlsDomain'] ?? 'www.google.com');
        $wire = 'ee' . $secret . bin2hex($domain);
    } elseif (!empty($client['modeSecure'])) {
        $wire = 'dd' . $secret;
    } else {
        $wire = $secret;
    }

    return sprintf('https://t.me/proxy?server=%s&port=%d&secret=%s', rawurlencode($host), $port, $wire);
}

function xui_credentials_block($code_panel, $inboundId, string $proto, array $client): string
{
    $panel = xui_panel_by_code($code_panel);
    $host = parse_url(xui_base($panel), PHP_URL_HOST) ?: xui_base($panel);
    $user = (string) ($client['id'] ?? $client['email'] ?? '');
    $pass = (string) ($client['password'] ?? '');

    $lines = [
        strtoupper($proto) . ' — inbound ' . $inboundId,
        'Server: ' . $host,
        'Username: ' . $user,
        'Password: ' . $pass,
    ];

    $inb = xui_inbound_get($code_panel, $inboundId);
    $psk = $inb['settings']['ipsecPsk'] ?? null;
    if ($psk) {
        $lines[] = 'IPsec PSK: ' . $psk;
    }
    return implode("\n", $lines);
}

/** GET a panel config endpoint and return its file payload as [name,content]. */
function xui_pull_config_file($code_panel, $inboundId, string $email, string $endpoint, string $filename): array
{
    $txt = xui_pull_config_text($code_panel, $inboundId, $email, $endpoint);
    if ($txt === null || $txt === '') {
        return [];
    }
    return [['name' => $filename, 'content' => $txt]];
}

/**
 * Fetch a per-client config endpoint (wgc-configs / awg-configs / gre-configs /
 * ssh-configs) and flatten whatever shape it returns into a single text block.
 *
 * vpn-ui returns a bare JSON array for these: e.g.
 *   wgc-configs -> [{"deviceIndex":0,"config":"[Interface]\n..."}]
 *   ssh-configs -> [{"remark":"","host":"1.2.3.4","port":22,"link":"ssh://..."}]
 * 3x-ui-style {"success":true,"obj":...} is also handled.
 */
function xui_pull_config_text($code_panel, $inboundId, string $email, string $endpoint): ?string
{
    $res = xui_req($code_panel, 'GET', '/' . (int) $inboundId . '/' . $endpoint . '?email=' . rawurlencode($email));
    if (!empty($res['error'])) {
        return null;
    }

    $json = $res['json'];
    $payload = null;

    if (is_array($json)) {
        if (array_key_exists('success', $json)) {
            if (empty($json['success'])) {
                return null;
            }
            $payload = $json['obj'] ?? null;
        } else {
            // bare array / object
            $payload = $json;
        }
    }

    if ($payload === null) {
        return is_string($res['body']) && trim($res['body']) !== '' ? $res['body'] : null;
    }
    if (is_string($payload)) {
        return $payload;
    }

    // Normalise to a list of entries
    $entries = (is_array($payload) && array_is_list($payload)) ? $payload : [$payload];
    $parts = [];
    foreach ($entries as $entry) {
        if (is_string($entry)) {
            $parts[] = $entry;
            continue;
        }
        if (!is_array($entry)) {
            continue;
        }
        foreach (['config', 'conf', 'content', 'text', 'link', 'uri', 'plain', 'singbox'] as $k) {
            if (!empty($entry[$k]) && is_string($entry[$k])) {
                $parts[] = $entry[$k];
                break;
            }
        }
    }
    if (empty($parts)) {
        return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    return implode("\n\n", array_values(array_unique($parts)));
}

/** Reuse x-ui_single.php's subscription fetcher when it is already loaded. */
function xui_fetch_sub_links(string $subUrl): array
{
    if (function_exists('fetch_subscription_links_with_retry')) {
        $r = fetch_subscription_links_with_retry($subUrl, 3, 1000000);
        return is_array($r['links'] ?? null) ? $r['links'] : [];
    }
    $body = xui_http_get($subUrl);
    if (!is_string($body) || $body === '') {
        return [];
    }
    $decoded = base64_decode(trim($body), true);
    $text = ($decoded !== false && preg_match('#(vless|vmess|trojan|ss|hysteria2?|tuic)://#i', $decoded)) ? $decoded : $body;
    $out = [];
    foreach (preg_split('/\R/', trim($text)) as $line) {
        $line = trim($line);
        if ($line !== '' && preg_match('#^[a-z0-9]+://#i', $line)) {
            $out[] = $line;
        }
    }
    return $out;
}

function xui_build_uri_from_inbound($code_panel, $inboundId, array $client): ?string
{
    if (!function_exists('build_vless_link_from_inbound')) {
        return null;
    }
    $panel = xui_panel_by_code($code_panel);
    $inb = xui_inbound_get($code_panel, $inboundId);
    if (!is_array($inb)) {
        return null;
    }
    $host = parse_url(xui_base($panel), PHP_URL_HOST) ?: 'localhost';
    return build_vless_link_from_inbound($inb, $client, $host);
}

/* -------------------------------------------------------------------------- */
/*  Certificate generation (vpn-ui only)                                      */
/* -------------------------------------------------------------------------- */

/** $kind ∈ openvpn | ocserv | sstp | ikev2 — returns the PEM bundle array. */
function vpnui_gen_cert($code_panel, string $kind): array
{
    $map = [
        'openvpn' => 'generate-openvpn-certs',
        'ocserv' => 'generate-ocserv-cert',
        'openconnect' => 'generate-ocserv-cert',
        'sstp' => 'generate-sstp-cert',
        'ikev2' => 'generate-ikev2-cert',
    ];
    $ep = $map[strtolower($kind)] ?? null;
    if ($ep === null) {
        return ['success' => false, 'msg' => 'unknown certificate kind'];
    }
    $res = xui_req($code_panel, 'POST', '/' . $ep, []);
    if (is_array($res['json']) && !empty($res['json']['success'])) {
        return ['success' => true, 'obj' => $res['json']['obj'] ?? null, 'msg' => $res['json']['msg'] ?? 'ok'];
    }
    return ['success' => false, 'msg' => $res['error'] ?? (is_array($res['json']) ? ($res['json']['msg'] ?? 'cert generation failed') : 'cert generation failed')];
}

/* -------------------------------------------------------------------------- */
/*  Small utilities                                                           */
/* -------------------------------------------------------------------------- */

/** Normalise an inbound-id spec (array | JSON string | "1,2 3") to int[]. */
function xui_norm_ids($ids): array
{
    if (is_string($ids)) {
        $trim = trim($ids);
        if ($trim === '') {
            return [];
        }
        $decoded = json_decode($trim, true);
        $ids = is_array($decoded) ? $decoded : preg_split('/[\s,]+/', $trim);
    }
    if (!is_array($ids)) {
        $ids = [$ids];
    }
    $out = [];
    foreach ($ids as $v) {
        $n = (int) $v;
        if ($n > 0 && !in_array($n, $out, true)) {
            $out[] = $n;
        }
    }
    return $out;
}

function xui_secret(int $len = 20, bool $hex = false): string
{
    $bytes = random_bytes(max(8, (int) ceil($len / 2)));
    $s = $hex ? bin2hex($bytes) : rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    return substr($s, 0, $len);
}

function xui_http_get(string $url, ?string $cookieFile = null)
{
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 12);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['User-Agent: MirzaSub/1.0', 'Accept: */*']);
    if ($cookieFile) {
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    }
    $body = curl_exec($ch);
    curl_close($ch);
    return $body === false ? null : $body;
}

/** RFC 6238 TOTP (SHA1, 6 digits, 30s) from a base32 secret. */
function xui_totp(string $base32Secret, int $digits = 6, int $period = 30): ?string
{
    $key = xui_base32_decode(preg_replace('/[^A-Za-z2-7]/', '', $base32Secret));
    if ($key === '' ) {
        return null;
    }
    $counter = pack('N*', 0) . pack('N*', (int) floor(time() / $period));
    $hash = hash_hmac('sha1', $counter, $key, true);
    $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
    $part = substr($hash, $offset, 4);
    $value = (unpack('N', $part)[1] & 0x7FFFFFFF) % (10 ** $digits);
    return str_pad((string) $value, $digits, '0', STR_PAD_LEFT);
}

function xui_base32_decode(string $b32): string
{
    if ($b32 === '') {
        return '';
    }
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $b32 = strtoupper($b32);
    $bits = '';
    foreach (str_split($b32) as $ch) {
        $pos = strpos($alphabet, $ch);
        if ($pos === false) {
            continue;
        }
        $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 8) as $byte) {
        if (strlen($byte) === 8) {
            $out .= chr(bindec($byte));
        }
    }
    return $out;
}
