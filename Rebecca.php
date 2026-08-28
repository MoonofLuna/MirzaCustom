<?php
include('config.php');
require_once 'request.php';
/*
 * Rebecca panel integration — https://github.com/rebeccapanel/Rebecca
 *
 * Rebecca is a Go-rewritten fork of Marzban that deliberately keeps most of
 * Marzban's REST route names/shapes for compatibility, but with two
 * important differences this integration is built around:
 *
 *   1. Auth: instead of Marzban's username/password -> JWT exchange, bots
 *      and third-party integrations are meant to use a static personal
 *      API key ("rk_...", created from the dashboard under
 *      My Account -> API Keys) sent as `Authorization: Bearer rk_...`.
 *      There is no login step and nothing to cache/refresh — every
 *      function below just attaches the stored key straight from the DB.
 *      (The classic POST /api/admin/token flow still exists for
 *      "older Marzban-compatible clients" if you ever prefer that instead
 *      — Marzban.php's token_panel()/adduser() pattern would work almost
 *      unchanged against it — but the API's own docs recommend the rk_
 *      key for bots, so that's what this file uses.)
 *
 *   2. User creation requires a `service_id` (Rebecca's replacement for
 *      Marzban's raw `inbounds`/`proxies` payload — manual inbounds are
 *      rejected by the API). Rather than adding a new DB column, this
 *      integration reuses the `marzban_panel.inboundid` column (the same
 *      column the x-ui/alireza single-panel integrations already use to
 *      store a single numeric id) to hold the Rebecca service_id.
 *
 * Storage convention used throughout this file (all existing columns,
 * no schema change required):
 *   - marzban_panel.url_panel     -> Rebecca panel base URL, e.g. https://panel.example.com:8000
 *   - marzban_panel.secret_code   -> the rk_... personal API key (same
 *                                    column the Hiddify integration uses
 *                                    for its static API key)
 *   - marzban_panel.inboundid     -> the numeric service_id to assign new
 *                                    users to (falls back to a product's
 *                                    own `inbounds` field if set, same
 *                                    convention as the other panel types)
 *
 * IMPORTANT — verify before going live:
 * Rebecca publishes a full OpenAPI document (internal/app/api/openapi/openapi.json)
 * but its response *schemas* (field-by-field) weren't fully inspectable
 * while writing this integration — only the path list and prose
 * descriptions were. The field names read below (status, data_limit,
 * expire, used_traffic, subscription_url, note, data_limit_reset_strategy)
 * mirror Marzban's classic response shape, since Rebecca's own docs
 * describe /api/admin/token as kept "for older Marzban-compatible
 * clients." Before relying on this in production: create one test user
 * through adduser_rebecca() below, var_dump() the raw response body, and
 * confirm the field names (and whether `expire` is a unix timestamp or an
 * ISO date string) match what's assumed here — adjust if not.
 */

#-----------------------------#
function findRebeccaPanelByName($location)
{
    $panel = select("marzban_panel", "*", "name_panel", $location, "select");
    if (!is_array($panel) || empty($panel)) {
        return null;
    }
    return $panel;
}
#-----------------------------#
function rebecca_json_headers()
{
    return array(
        'accept: application/json',
        'Content-Type: application/json',
    );
}
#-----------------------------#
function getuser_rebecca($username_account, $location)
{
    $panel = findRebeccaPanelByName($location);
    if ($panel === null) {
        return ["error" => "Panel configuration not found for the requested location."];
    }
    $url = rtrim($panel['url_panel'], '/') . '/api/user/' . $username_account;
    $req = new CurlRequest($url);
    $req->setHeaders(rebecca_json_headers());
    $req->setBearerToken($panel['secret_code']);
    return $req->get();
}
#-----------------------------#
function getusers_rebecca($location, $status = null)
{
    $panel = findRebeccaPanelByName($location);
    if ($panel === null) {
        return ["error" => "Panel configuration not found for the requested location."];
    }
    $url = rtrim($panel['url_panel'], '/') . '/api/users';
    if ($status) {
        $url .= '?status=' . urlencode($status);
    }
    $req = new CurlRequest($url);
    $req->setHeaders(rebecca_json_headers());
    $req->setBearerToken($panel['secret_code']);
    return $req->get();
}
#-----------------------------#
function adduser_rebecca($location, $data_limit, $username_ac, $timestamp, $note = '', $data_limit_reset = 'no_reset', $name_product = false)
{
    $panel = findRebeccaPanelByName($location);
    if ($panel === null) {
        return ["error" => "Panel configuration not found for the requested location."];
    }
    $service_id = $panel['inboundid'];
    if ($name_product != false && $name_product != "usertest") {
        $product = select("product", "*", "name_product", $name_product, "select");
        if ($product !== false && !empty($product['inbounds'])) {
            $service_id = $product['inbounds'];
        }
    }
    $data = array(
        "username" => $username_ac,
        "service_id" => intval($service_id),
        "data_limit" => $data_limit,
        "note" => $note,
        "data_limit_reset_strategy" => $data_limit_reset,
    );
    if ($timestamp == 0) {
        $data["expire"] = 0;
    } else {
        $data["expire"] = $timestamp;
    }
    $url = rtrim($panel['url_panel'], '/') . '/api/user';
    $req = new CurlRequest($url);
    $req->setHeaders(rebecca_json_headers());
    $req->setBearerToken($panel['secret_code']);
    return $req->post(json_encode($data));
}
#-----------------------------#
function removeuser_rebecca($location, $username)
{
    $panel = findRebeccaPanelByName($location);
    if ($panel === null) {
        return ["error" => "Panel configuration not found for the requested location."];
    }
    $url = rtrim($panel['url_panel'], '/') . '/api/user/' . $username;
    $req = new CurlRequest($url);
    $req->setHeaders(rebecca_json_headers());
    $req->setBearerToken($panel['secret_code']);
    return $req->delete();
}
#-----------------------------#
function ResetUserDataUsage_rebecca($username_account, $location)
{
    $panel = findRebeccaPanelByName($location);
    if ($panel === null) {
        return ["error" => "Panel configuration not found for the requested location."];
    }
    $url = rtrim($panel['url_panel'], '/') . '/api/user/' . $username_account . '/reset';
    $req = new CurlRequest($url);
    $req->setHeaders(rebecca_json_headers());
    $req->setBearerToken($panel['secret_code']);
    return $req->post(array());
}
#-----------------------------#
function revoke_sub_rebecca($username_account, $location)
{
    $panel = findRebeccaPanelByName($location);
    if ($panel === null) {
        return ["error" => "Panel configuration not found for the requested location."];
    }
    $url = rtrim($panel['url_panel'], '/') . '/api/user/' . $username_account . '/revoke_sub';
    $req = new CurlRequest($url);
    $req->setHeaders(rebecca_json_headers());
    $req->setBearerToken($panel['secret_code']);
    return $req->post(array());
}
#-----------------------------#
function Modifyuser_rebecca($location, $username, array $data)
{
    $panel = findRebeccaPanelByName($location);
    if ($panel === null) {
        return ["error" => "Panel configuration not found for the requested location."];
    }
    $url = rtrim($panel['url_panel'], '/') . '/api/user/' . $username;
    $req = new CurlRequest($url);
    $req->setHeaders(rebecca_json_headers());
    $req->setBearerToken($panel['secret_code']);
    return $req->put(json_encode($data));
}
