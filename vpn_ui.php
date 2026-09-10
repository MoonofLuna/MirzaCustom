<?php
/**
 * vpn_ui.php — panel type `vpn_ui` (github.com/Sir-MmD/vpn-ui).
 *
 * vpn-ui is a Go fork of 3x-ui 2.9.3 with the same /panel/api/inbounds/* surface
 * plus 19 protocols (L2TP, PPTP, OpenVPN, OpenConnect, SSTP, IKEv2, WireGuard,
 * AmneziaWG, GRE, MTProto, SSH, AnyTLS, TUIC, Naive, and Xray vmess/vless/
 * trojan/shadowsocks/hysteria), per-protocol certificate generation and
 * config-download endpoints.
 *
 * All behaviour is shared with the `x_ui` type through xui_core.php / x_ui.php;
 * the `xuisvc_*` functions branch on $panel['type'] so `vpn_ui`:
 *   - accepts every protocol (xuisvc_allowed_protocols() returns null),
 *   - delivers non-URI protocols as a credentials text block or a .conf/.ovpn
 *     file (see xui_client_links() in xui_core.php),
 *   - can call vpnui_gen_cert() for openvpn / ocserv / sstp / ikev2.
 *
 * The bot only manages client accounts inside inbounds the admin already
 * created in the vpn-ui web UI — it never provisions inbounds.
 */

require_once __DIR__ . '/x_ui.php';

/** Protocols a vpn_ui panel can host (informational / admin validation). */
function vpn_ui_protocols(): array
{
    return xui_all_protocols();
}
