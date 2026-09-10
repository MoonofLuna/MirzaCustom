<?php
/**
 * x_ui.php — adapter layer that turns the multi-inbound xui_core helpers into
 * the exact return shapes ManagePanel (panels.php) expects.
 *
 * Serves BOTH panel types:
 *   - x_ui   : modern 3x-ui, multi-inbound, Xray + WireGuard protocols
 *   - vpn_ui : Sir-MmD/vpn-ui fork, all 19 protocols (see vpn_ui.php)
 *
 * The mode is taken from $panel['type'], so the same functions cover both.
 */

require_once __DIR__ . '/xui_core.php';

/** Protocols the x_ui (upstream 3x-ui) type is allowed to sell. null => no limit (vpn_ui). */
function xuisvc_allowed_protocols(array $panel): ?array
{
    if (($panel['type'] ?? '') === 'vpn_ui') {
        return null;
    }
    return array_values(array_unique(array_merge(
        xui_xray_protocols(),
        ['wireguard', 'wg-c']
    )));
}

/* -------------------------------------------------------------------------- */
/*  Inbound-id resolution                                                     */
/* -------------------------------------------------------------------------- */

/** Inbound ids for a brand-new account: product override → panel list → legacy id. */
function xuisvc_inbounds_for_new(array $panel, $product): array
{
    if (is_array($product)) {
        if (!empty($product['inbound_list'])) {
            $ids = xui_norm_ids($product['inbound_list']);
            if ($ids) {
                return $ids;
            }
        }
        if (!empty($product['inbounds'])) {
            $ids = xui_norm_ids($product['inbounds']);
            if ($ids) {
                return $ids;
            }
        }
    }
    if (!empty($panel['inbound_list'])) {
        $ids = xui_norm_ids($panel['inbound_list']);
        if ($ids) {
            return $ids;
        }
    }
    return xui_norm_ids($panel['inboundid'] ?? 1) ?: [1];
}

/**
 * Inbound ids for an existing account. The live panel scan is authoritative
 * (it reflects reality even if the admin re-configured the panel afterwards);
 * fall back to the panel's configured list.
 */
function xuisvc_inbounds_for_existing(array $panel, string $username, ?array $acct = null): array
{
    $acct = $acct ?? xui_find_client($panel['code_panel'], $username);
    if (!empty($acct['inboundIds'])) {
        return $acct['inboundIds'];
    }
    if (!empty($panel['inbound_list'])) {
        $ids = xui_norm_ids($panel['inbound_list']);
        if ($ids) {
            return $ids;
        }
    }
    return xui_norm_ids($panel['inboundid'] ?? 1) ?: [1];
}

/** Mirror x-ui_single's on-hold / connect-on-first-use expiry handling. */
function xuisvc_expire_ms(array $panel, $expireUnix, string $configType, $productName): int
{
    $expireUnix = (int) $expireUnix;
    $isTest = ($productName === 'usertest') || ($configType === 'usertest') || ($configType === 'test');
    $onHold = $isTest
        ? (($panel['on_hold_test'] ?? '') === '1')
        : (($panel['conecton'] ?? '') === 'onconecton');

    if ($expireUnix === 0) {
        return 0;
    }
    if ($onHold) {
        $remaining = $expireUnix - time();
        return -intval(($remaining / 86400) * 86400000);
    }
    return $expireUnix * 1000;
}

/** Pick a client object to feed the link builder (real stored one if present). */
function xuisvc_client_for_links(array $acct, array $field_bag): array
{
    $stored = null;
    foreach ($acct['clients'] as $c) {
        if (is_array($c) && !empty($c)) {
            $stored = $c;
            break;
        }
    }
    $proto = $acct['protocol'] ?: ($field_bag['protocol'] ?? 'vless');
    // Rebuild from the known field bag so secret / password identities are present
    // (vpn-ui's /list does not echo them back), then overlay anything the panel
    // does return (uuid, flow, mode flags, tlsDomain ...).
    $rebuilt = xui_build_client($proto, $field_bag);
    return is_array($stored) ? array_replace($rebuilt, array_filter($stored, fn($v) => $v !== null && $v !== '')) : $rebuilt;
}

/** Persist the credentials needed to rebuild non-Xray links later. */
function xuisvc_creds_save(string $username, array $creds): void
{
    if ((int) select("invoice", "*", "username", $username, "count") > 0) {
        update("invoice", "xui_creds", json_encode($creds, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "username", $username);
    }
}

/** Load stored credentials for an account, or []. */
function xuisvc_creds_load(string $username): array
{
    $row = select("invoice", "xui_creds", "username", $username, "select");
    if (is_array($row) && !empty($row['xui_creds'])) {
        $d = json_decode($row['xui_creds'], true);
        if (is_array($d)) {
            return $d;
        }
    }
    return [];
}

/* -------------------------------------------------------------------------- */
/*  ManagePanel-shaped operations                                             */
/* -------------------------------------------------------------------------- */

/** createUser → ['status','username','subscription_url','configs','files','panel_inbounds','subId'|'msg'] */
function xuisvc_create(array $panel, $product, string $usernameC, array $Data_Config): array
{
    $ids = xuisvc_inbounds_for_new($panel, $product);
    if (empty($ids)) {
        return ['status' => 'Unsuccessful', 'msg' => 'No inbound id configured for this panel.'];
    }

    $uuid = generateUUID();
    $subId = bin2hex(random_bytes(8));
    $productName = is_array($product) ? ($product['name_product'] ?? null) : null;

    // Generate every identity once and thread it through, so the exact objects
    // we send can be rebuilt afterwards for link assembly / later re-fetch.
    $field_bag = [
        'email' => $usernameC,
        'uuid' => $uuid,
        'password' => xui_secret(20),
        'secret' => xui_secret(16, true),
        'sshUser' => preg_replace('/[^a-z0-9_]/i', '', explode('@', $usernameC)[0]) ?: ('u' . substr(bin2hex(random_bytes(4)), 0, 6)),
        'subId' => $subId,
        'expiryMs' => xuisvc_expire_ms($panel, $Data_Config['expire'] ?? 0, (string) ($Data_Config['type'] ?? ''), $productName),
        'totalBytes' => (int) ($Data_Config['data_limit'] ?? 0),
        'note' => (string) ($Data_Config['note'] ?? ''),
        'flow' => '',
    ];

    $res = xui_add_client($panel['code_panel'], $ids, $field_bag, xuisvc_allowed_protocols($panel));
    if (empty($res['success'])) {
        return ['status' => 'Unsuccessful', 'msg' => $res['msg'] ?? 'addClient failed'];
    }

    // Remember creds + which inbound each protocol landed on for later re-fetch.
    xuisvc_creds_save($usernameC, [
        'uuid' => $uuid,
        'password' => $field_bag['password'],
        'secret' => $field_bag['secret'],
        'sshUser' => $field_bag['sshUser'],
        'subId' => $subId,
        'inbounds' => array_values($ids),
        'protocols' => $res['protocols'] ?? [],
    ]);

    $acct = xui_find_client($panel['code_panel'], $usernameC);
    // Prefer the exact object we just sent for the first inbound.
    $sent = $res['clients'][$ids[0]] ?? null;
    $client = is_array($sent) && !empty($sent)
        ? $sent
        : xuisvc_client_for_links($acct, $field_bag);
    $links = xui_client_links($panel['code_panel'], $ids, $client, $subId, ['email' => $usernameC]);

    $configs = $links['configs'];
    if (empty($configs)) {
        $configs = ['در دسترس نیست'];
    }

    return [
        'status' => 'successful',
        'username' => $usernameC,
        'subscription_url' => $links['subscription_url'],
        'configs' => $configs,
        'files' => $links['files'],
        'panel_inbounds' => json_encode(array_values($ids)),
        'subId' => $subId,
    ];
}

/** DataUser → standard ManagePanel DataUser output shape (+ 'files'). */
function xuisvc_datauser(array $panel, string $username): array
{
    $t = xui_get_traffic($panel['code_panel'], $username);
    if (empty($t['success']) || !is_array($t['obj'])) {
        return ['status' => 'Unsuccessful', 'msg' => $t['msg'] ?? 'User not found'];
    }
    $obj = $t['obj'];
    $acct = xui_find_client($panel['code_panel'], $username);

    $total = (int) ($obj['total'] ?? 0);
    $used = (int) ($obj['up'] ?? 0) + (int) ($obj['down'] ?? 0);
    $expiryTime = (int) ($obj['expiryTime'] ?? 0);
    $expire = $expiryTime !== 0 ? $expiryTime / 1000 : 0;

    $status = !empty($obj['enable']) ? 'active' : 'disabled';
    if ($total !== 0 && ($total - $used) <= 0) {
        $status = 'limited';
    }
    if ($expiryTime > 0 && ($expire - time()) <= 0) {
        $status = 'expired';
    }
    if ($expiryTime < -10000) {
        $status = 'on_hold';
        $expire = 0;
    }

    $creds = xuisvc_creds_load($username);
    $subId = $acct['subId'] ?: ($creds['subId'] ?? ($obj['subId'] ?? ''));
    $ids = xuisvc_inbounds_for_existing($panel, $username, $acct);
    $client = xuisvc_client_for_links($acct, [
        'email' => $username,
        'uuid' => $acct['uuid'] ?? ($creds['uuid'] ?? null),
        'password' => $creds['password'] ?? null,
        'secret' => $creds['secret'] ?? null,
        'sshUser' => $creds['sshUser'] ?? null,
        'subId' => $subId,
        'protocol' => $acct['protocol'] ?? null,
    ]);
    $links = xui_client_links($panel['code_panel'], $ids, $client, (string) $subId, ['email' => $username]);

    $lastOnline = (int) ($obj['lastOnline'] ?? 0);
    $online_at = $lastOnline === 0 ? 'offline' : date('Y-m-d H:i:s', $lastOnline > 2000000000 ? $lastOnline / 1000 : $lastOnline);

    return [
        'status' => $status,
        'username' => $username,
        'data_limit' => $total,
        'expire' => $expire,
        'online_at' => $online_at,
        'used_traffic' => $used,
        'links' => $links['configs'],
        'subscription_url' => $links['subscription_url'],
        'sub_updated_at' => null,
        'sub_last_user_agent' => null,
        'files' => $links['files'],
    ];
}

/** Modifyuser → ['status'=>bool, 'data'|'msg']. $config['settings'] is a JSON string. */
function xuisvc_modify(array $panel, string $username, array $config): array
{
    $patch = xuisvc_extract_patch($config);
    if (empty($patch)) {
        return ['status' => false, 'msg' => 'nothing to update'];
    }
    // Identity fields must never be blindly rewritten across inbounds.
    unset($patch['id'], $patch['password'], $patch['auth'], $patch['flow']);

    $acct = xui_find_client($panel['code_panel'], $username);
    if (!$acct['found']) {
        return ['status' => false, 'msg' => 'User not found'];
    }
    $ids = xuisvc_inbounds_for_existing($panel, $username, $acct);

    $r = xui_update_client($panel['code_panel'], $ids, $username, $patch);
    return $r['success']
        ? ['status' => true, 'data' => $r]
        : ['status' => false, 'msg' => $r['msg'] ?? 'update failed'];
}

/** Revoke_sub → ['status'=>'successful'|'Unsuccessful', 'configs', 'subscription_url', 'files']. */
function xuisvc_revoke(array $panel, string $username): array
{
    $newSub = bin2hex(random_bytes(8));
    $acct = xui_find_client($panel['code_panel'], $username);
    if (!$acct['found']) {
        return ['status' => 'Unsuccessful', 'msg' => 'User not found'];
    }
    $ids = xuisvc_inbounds_for_existing($panel, $username, $acct);

    $r = xui_update_client($panel['code_panel'], $ids, $username, ['subId' => $newSub]);
    if (empty($r['success'])) {
        return ['status' => 'Unsuccessful', 'msg' => $r['msg'] ?? 'revoke failed'];
    }

    $creds = xuisvc_creds_load($username);
    $creds['subId'] = $newSub;
    xuisvc_creds_save($username, $creds);

    $acct = xui_find_client($panel['code_panel'], $username);
    $client = xuisvc_client_for_links($acct, [
        'email' => $username,
        'uuid' => $acct['uuid'] ?? ($creds['uuid'] ?? null),
        'password' => $creds['password'] ?? null,
        'secret' => $creds['secret'] ?? null,
        'sshUser' => $creds['sshUser'] ?? null,
        'subId' => $newSub,
        'protocol' => $acct['protocol'] ?? null,
    ]);
    $links = xui_client_links($panel['code_panel'], $ids, $client, $newSub, ['email' => $username]);

    return [
        'status' => 'successful',
        'configs' => $links['configs'],
        'subscription_url' => $links['subscription_url'],
        'files' => $links['files'],
        'subId' => $newSub,
    ];
}

/** RemoveUser → ['status'=>'successful'|'Unsuccessful','username','msg']. */
function xuisvc_remove(array $panel, string $username): array
{
    $ids = xuisvc_inbounds_for_existing($panel, $username);
    $r = xui_del_client($panel['code_panel'], $ids, $username);
    return [
        'status' => !empty($r['success']) ? 'successful' : 'Unsuccessful',
        'username' => $username,
        'msg' => $r['msg'] ?? '',
    ];
}

/** ResetUserDataUsage → ['status'=>bool,'data'|'msg']. */
function xuisvc_reset(array $panel, string $username): array
{
    $ids = xuisvc_inbounds_for_existing($panel, $username);
    $r = xui_reset_traffic($panel['code_panel'], $ids, $username);
    return $r['success']
        ? ['status' => true, 'data' => $r]
        : ['status' => false, 'msg' => $r['msg'] ?? 'reset failed'];
}

/** Pull clients[0] out of the various $config shapes callers pass to Modifyuser. */
function xuisvc_extract_patch(array $config): array
{
    if (isset($config['settings'])) {
        $s = is_string($config['settings']) ? json_decode($config['settings'], true) : $config['settings'];
        if (is_array($s) && isset($s['clients'][0]) && is_array($s['clients'][0])) {
            return $s['clients'][0];
        }
    }
    if (isset($config['clients'][0]) && is_array($config['clients'][0])) {
        return $config['clients'][0];
    }
    // Flat map (e.g. Change_status on some types passes ['enable'=>bool])
    $flat = array_intersect_key($config, array_flip(['enable', 'totalGB', 'expiryTime', 'subId', 'limitIp', 'comment', 'reset']));
    return $flat;
}
