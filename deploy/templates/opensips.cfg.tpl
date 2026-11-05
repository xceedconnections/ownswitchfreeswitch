#
# Ownswitch - OpenSIPS 3.6 baseline configuration
# Rendered by deploy/stages/50_opensips.sh from deploy/templates/opensips.cfg.tpl
# Placeholders use the at-at-NAME-at-at form (so OpenSIPS $variables are untouched).
#
# This is a BASELINE signalling + billing script:
#   customer auth (IP allow-list via `address` table group 1, else SIP digest)
#   -> dialog -> CGRateS authorisation/accounting -> RTPengine -> carrier selection (drouting)
# The Ownswitch provisioner may later replace/extend this file; always validate with
#   opensips -c -f /etc/opensips/opensips.cfg
# and keep the previous copy (the installer stores backups in /var/backups/ownswitch-config).
#
# address table groups:  1 = customer trunks (IP auth)   2 = carriers / upstream (inbound)
#

####### Global parameters #########

log_level=3
xlog_level=3
stderror_enabled=yes
syslog_enabled=yes
syslog_facility=LOG_LOCAL0
syslog_name="opensips"

udp_workers=@@OPENSIPS_UDP_WORKERS@@
tcp_workers=4
# Advertise socket IPs + every host/IP we know (primary FQDN, public IP, extra local IPs)
auto_aliases=yes
mpath="@@OPENSIPS_MPATH@@"

socket=@@SIP_SOCKET_UDP@@
socket=@@SIP_SOCKET_TCP@@
# Primary portal domain + public IP (extra aliases injected by stage 50)
alias="@@OWNSWITCH_DOMAIN@@"
alias="@@PUBLIC_IP@@"
@@OPENSIPS_EXTRA_ALIASES@@

####### Modules #########

loadmodule "db_mysql.so"
loadmodule "proto_udp.so"
loadmodule "proto_tcp.so"
loadmodule "signaling.so"
loadmodule "sl.so"

loadmodule "tm.so"
modparam("tm", "fr_timeout", 5)
modparam("tm", "fr_inv_timeout", 60)
modparam("tm", "restart_fr_on_each_reply", 0)
modparam("tm", "onreply_avp_mode", 1)

loadmodule "rr.so"
modparam("rr", "append_fromtag", 0)

loadmodule "maxfwd.so"
loadmodule "sipmsgops.so"
# uri.so removed in OpenSIPS 3.x (helpers live in sipmsgops)

loadmodule "mi_fifo.so"
modparam("mi_fifo", "fifo_name", "@@OPENSIPS_FIFO@@")
modparam("mi_fifo", "fifo_mode", 0666)
@@OPENSIPS_MI_HTTP_BLOCK@@

loadmodule "auth.so"
loadmodule "auth_db.so"
modparam("auth_db", "db_url", "@@OPENSIPS_DB_URL@@")
# Magnus-style: plaintext password + calculate HA1 using the softphone's realm.
# Domain may be public IP OR any FQDN that resolves here — no Outbound Proxy required.
# (calculate_ha1=0/ha1 forced one fixed realm and broke Domain=IP / alt-FQDN clients.)
modparam("auth_db", "calculate_ha1", 1)
modparam("auth_db", "password_column", "password")
# Load SIP Directmedia / DTMF pass / CallerID mode from subscriber into AVPs
modparam("auth_db", "load_credentials", "$avp(os_media)=media_policy;$avp(os_dtmf)=dtmf_pass;$avp(os_cid_mode)=cid_mode;$avp(os_cid)=rpid;$avp(os_cid_min)=cid_min_len;$avp(os_cid_max)=cid_max_len;$avp(os_allow)=allow_calls;$avp(os_dstfmt)=dst_format;$avp(os_dncid)=dnc_group_id")
# Username-only match (ignore Domain in subscriber row)
modparam("auth_db", "use_domain", 0)

loadmodule "usrloc.so"
# write-through so portal registration status reads opensips.location live
modparam("usrloc", "working_mode_preset", "single-instance-sql-write-through")
modparam("usrloc", "db_url", "@@OPENSIPS_DB_URL@@")
# AOR = username only — Domain=IP and Domain=any-FQDN share the same binding
modparam("usrloc", "use_domain", 0)

loadmodule "registrar.so"
modparam("registrar", "max_contacts", 5)
modparam("registrar", "default_expires", 3600)

@@OPENSIPS_NATHELPER_BLOCK@@

loadmodule "permissions.so"
modparam("permissions", "db_url", "@@OPENSIPS_DB_URL@@")

# dialog MUST be loaded before cgrates
loadmodule "dialog.so"
modparam("dialog", "db_url", "@@OPENSIPS_DB_URL@@")
modparam("dialog", "db_mode", 2)
# hard cap on call length (seconds) - safety net for billing
modparam("dialog", "default_timeout", 7200)
# Detect dead legs (ViciDial hangup without BYE reaching us) and clear zombie "Connected" dialogs
modparam("dialog", "options_ping_interval", 20)
modparam("dialog", "delete_delay", 5)

# CGRateS SessionS bi-directional JSON-RPC (listen_bijson), loopback only
loadmodule "cgrates.so"
modparam("cgrates", "cgrates_engine", "@@CGR_BIJSON_ADDR@@")

loadmodule "rtpengine.so"
modparam("rtpengine", "rtpengine_sock", "@@RTPENGINE_SOCK@@")

# signalling accounting (backup / reconciliation feed next to CGRateS CDRs)
loadmodule "acc.so"
modparam("acc", "db_url", "@@OPENSIPS_DB_URL@@")
modparam("acc", "early_media", 0)
modparam("acc", "report_cancels", 1)
# OpenSIPS 3.x: extra_fields + $acc_extra(...) — db_extra was removed
# src = SIP username (attribution); cli = presented CallerID; gw = drouting gwid (T{trunkId})
modparam("acc", "extra_fields", "db: src->src_user; dst->dst_user; ip->src_ip; cli->cli; gw->gw")

# dynamic routing (carrier selection / failover) - data loaded from the opensips DB
loadmodule "drouting.so"
modparam("drouting", "db_url", "@@OPENSIPS_DB_URL@@")
# OpenSIPS 3.6+: gw_id_avp defaults OFF — enable so ACC can store selected trunk (T{id})
modparam("drouting", "gw_id_avp", "$avp(dr_gw_id)")
# OpenSIPS 3.x: no gw_attrs_avp modparam — pass $avp(dr_gw_attrs) as do_routing() 5th arg

# Rate CAP check via portal PHP (opensips-module-exec). Optional — stage/deploy installs when available.
@@OPENSIPS_EXEC_BLOCK@@

# DNC hot-path (cachedb_redis and/or sqlops) — stage injects when modules exist
@@OPENSIPS_DNC_MODULES@@

# DID → SIP user aliases (Ownswitch DID assignments)
@@OPENSIPS_ALIAS_DB_BLOCK@@

# Outbound REGISTER towards providers that require digest registration (Magnus user/pass trunks).
# Optional: package opensips-uac-module. If the .so is missing, comment these two lines.
@@OPENSIPS_UAC_BLOCK@@

####### Routing logic #########

route {
	# =====================================================================
	# REGISTER FIRST — never proxy/relay (FQDN DNS to ourselves → 483/500).
	# Must run before Max-Forwards / loose_route / is_myself checks.
	# Accepts Domain = public IP OR any FQDN pointed at this server (no Proxy).
	# =====================================================================
	if (is_method("REGISTER")) {
		# Always local: never DNS-relay REGISTER (fixes Domain FQDN → 483 Too Many Hops)
		# OpenSIPS 3.3+: rewritehost() removed → sethost()
		sethost("@@PUBLIC_IP@@");
		xlog("L_INFO", "REGISTER from=$si port=$sp user=$tU realm=$td ruri=$ru\n");
		# Empty realm: accept Domain=IP or FQDN (Eyebeam + Asterisk/ViciDial). calculate_ha1=1.
		# Fall back to $td challenge only for the WWW-Authenticate realm display.
		if (!www_authorize("", "subscriber")) {
			xlog("L_WARN", "REGISTER auth failed user=$tU realm=$td from=$si\n");
			www_challenge("", "auth");
			exit;
		}
		@@OPENSIPS_NAT_REGISTER_BLOCK@@
		if (!save("location")) {
			xlog("L_ERR", "REGISTER save() failed for $tU\n");
			sl_reply_error();
			exit;
		}
		xlog("L_INFO", "REGISTER OK $tU@$td from $si\n");
		exit;
	}

	if (!mf_process_maxfwd_header(10)) {
		send_reply(483, "Too Many Hops");
		exit;
	}

	# SIP keep-alive towards us
	if (is_method("OPTIONS") && !$rU) {
		send_reply(200, "OK");
		exit;
	}
	if (is_method("OPTIONS")) {
		send_reply(200, "OK");
		exit;
	}

	# ---- in-dialog requests ----
	if (has_totag()) {
		# Absorb DTMF (SIP INFO) when this dialog was created with dtmf_pass=no
		if (is_method("INFO") && $dlg_val(os_dtmf) == "no") {
			xlog("L_INFO", "DTMF INFO blocked in-dialog for $fU\n");
			send_reply(200, "OK");
			exit;
		}
		if (loose_route()) {
			if (is_method("INVITE|ACK|BYE|UPDATE|PRACK") && $avp(os_media) != "direct") {
				if ($avp(os_media) == "record") {
					rtpengine_manage("replace-session-connection replace-origin ICE=remove recording-label=$ci");
				} else {
					rtpengine_manage();
				}
			}
			route(relay);
		} else {
			if (is_method("ACK")) {
				if (t_check_trans()) {
					t_relay();
				}
				exit;
			}
			send_reply(404, "Not here");
		}
		exit;
	}

	# ---- initial requests ----
	if (is_method("CANCEL")) {
		if (t_check_trans()) {
			t_relay();
		}
		exit;
	}

	t_check_trans();

	if (!is_method("INVITE|OPTIONS")) {
		send_reply(405, "Method Not Allowed");
		exit;
	}

	# ---- INVITE ----
	# Customer IP ACL → trust source IP. Softphones (Eyebeam) still send digest — we MUST
	# still proxy_authorize so load_credentials fills os_cid_mode / os_dncid / media AVPs.
	# Without that, Softphone Display Name mode never runs (AVPs stay NULL → passthrough).
	# Carrier ACL → deliver only if local AOR exists; else fall through to digest.
	# 4th arg captures address.context_info (sip:username) for IP peers without digest.
	if (check_source_address(1, $sp, "any", $avp(os_addr_ctx))) {
		if (is_present_hf("Proxy-Authorization") || is_present_hf("Authorization")) {
			if (proxy_authorize("", "subscriber")) {
				$avp(os_auth_user) = $au;
				consume_credentials();
			} else {
				xlog("L_WARN", "IP ACL ok but INVITE digest failed user=$fU from=$si (AVPs may be empty)\n");
			}
		} else {
			xlog("L_INFO", "IP ACL peer (no digest) from=$si user=$fU ctx=$avp(os_addr_ctx)\n");
		}
	} else {
		if (check_source_address(2)) {
			@@OPENSIPS_ALIAS_LOOKUP@@
			if (lookup("location")) {
				route(inbound_from_carrier_deliver);
				exit;
			}
			xlog("L_WARN", "carrier ACL but no AOR for $rU - customer digest path\n");
		}
		# Realm = From domain ($fd): IP or any FQDN; username-only DB lookup
		# Empty realm = accept Domain IP or FQDN (avoids $fd mismatch → 403 Forbidden)
		if (!proxy_authorize("", "subscriber")) {
			# Avoid punctuation immediately after $si / $fU (OpenSIPS xlog parse bug)
			xlog("L_WARN", "INVITE auth failed user=$fU from=$si\n");
			proxy_challenge("", "auth");
			exit;
		}
		$avp(os_auth_user) = $au;
		consume_credentials();
	}

	# Disabled SIP / prepaid empty balance → Payment Required (before ACC / dialog — no CDR)
	if ($avp(os_allow) != NULL && $(avp(os_allow){s.int}) == 0) {
		xlog("L_WARN", "Payment Required user=$au from=$si\n");
		send_reply(402, "Payment Required");
		exit;
	}

	# DID → SIP user (when alias_db is loaded)
	@@OPENSIPS_ALIAS_LOOKUP@@

	# Local extension / SIP-user → registered AOR (username-only; Domain may be IP/FQDN)
	if (lookup("location")) {
		xlog("L_INFO", "local AOR hit for $rU from $fU\n");
		record_route();
		# B=BYE on timeout; P/p=OPTIONS ping caller/callee → drop zombies when peer gone
		create_dialog("BPp");
		route(resolve_os_src);
		$dlg_val(os_dst) = $rU;
		$acc_extra(src) = $dlg_val(os_src);
		$acc_extra(dst) = $rU;
		$acc_extra(cli) = $fU;
		$acc_extra(ip) = $si;
		if ($avp(os_dtmf) != NULL) {
			$dlg_val(os_dtmf) = $avp(os_dtmf);
		} else {
			$dlg_val(os_dtmf) = "yes";
		}
		do_accounting("db", "cdr|missed|failed");
		if ($avp(os_media) == NULL || $(avp(os_media){s.len}) == 0) {
			$avp(os_media) = "relay";
		}
		route(apply_media);
		t_on_reply("handle_reply");
		route(relay);
		exit;
	}

	record_route();
	create_dialog("BPp");
	# Prefer digest auth user — $fU may be presented CallerID (ViciDial)
	route(resolve_os_src);
	$dlg_val(os_dst) = $rU;
	# Presented CLI starts as From user; apply_sip_cid replaces with display_name / fixed
	$dlg_val(os_cli) = $fU;
	$acc_extra(src) = $dlg_val(os_src);
	$acc_extra(dst) = $rU;
	$acc_extra(cli) = $dlg_val(os_cli);
	$acc_extra(ip) = $si;
	# Persist DTMF policy for in-dialog INFO filtering
	if ($avp(os_dtmf) != NULL) {
		$dlg_val(os_dtmf) = $avp(os_dtmf);
	} else {
		$dlg_val(os_dtmf) = "yes";
	}

	# Resolve Softphone Display Name → os_cli / ACC cli BEFORE do_accounting so
	# missed/failed CDRs do not keep the SIP username stamped above.
	route(apply_sip_cid);

	# CGRateS: accounting when available; portal prepaid empty balance already blocked via allow_calls
	if (!cgrates_auth()) {
		xlog("L_WARN", "cgrates_auth failed user=$au to=$rU from=$si (continuing; portal allow_calls enforced)\n");
	} else {
		cgrates_acc("cdr|missed");
	}
	do_accounting("db", "cdr|missed|failed");

	# Media: $avp(os_media) loaded from subscriber.media_policy on digest auth (else relay)
	if ($avp(os_media) == NULL || $(avp(os_media){s.len}) == 0) {
		$avp(os_media) = "relay";
	}

	route(apply_media);
	t_on_reply("handle_reply");
	t_on_failure("carrier_failover");

	# SIP-user DNC: block before any trunk/vendor is contacted (uses original dialled $rU)
	route(check_sip_dnc);

	# Group "1" is filled by Ownswitch (php bin/sync-opensips-trunks.php / trunk+route save)
	# 5th arg = gw attrs pvar (fu=/fd=/rstrip= from trunk sync). strip/pri_prefix via DR.
	if (!do_routing(1, , , , $avp(dr_gw_attrs))) {
		xlog("L_WARN", "no route for $rU from $fU - sync trunks/routes\n");
		acc_db_request("503 No Route", "acc");
		send_reply(503, "No Route");
		exit;
	}

	# Trunk DNC uses original dialled digits ($dlg_val(os_dst)) — before strip so list matches portal
	route(check_dnc);
	route(apply_ruri_strip);
	route(apply_sip_cid);
	route(check_cid_len);
	route(apply_trunk_attrs);
	route(check_trunk_cid_len);
	route(check_rate_cap);
	route(apply_sip_dst_format);
	route(apply_trunk_dst_format);
	route(acc_cli_gw);
	route(relay);
}

# Resolve SIP account for Live Calls / ACC src / billing.
# Priority: digest $au → captured auth AVP → address context sip:user → non-phone $fU → $fU
route[resolve_os_src] {
	$var(src) = $au;
	if ($var(src) == NULL || $(var(src){s.len}) == 0) {
		$var(src) = $avp(os_auth_user);
	}
	# address.context_info is "sip:username" — use s.select (NOT re.subst with // — // starts a comment!)
	if (($var(src) == NULL || $(var(src){s.len}) == 0)
		&& $avp(os_addr_ctx) != NULL && $(avp(os_addr_ctx){s.len}) > 4) {
		$var(src) = $(avp(os_addr_ctx){s.select,1,:});
	}
	if ($var(src) == NULL || $(var(src){s.len}) == 0) {
		$var(src) = $fU;
	}
	$dlg_val(os_src) = $var(src);
	xlog("L_INFO", "os_src=$dlg_val(os_src) au=$au fU=$fU ctx=$avp(os_addr_ctx) from=$si\n");
}

# Called only after alias+lookup already succeeded for a carrier-sourced INVITE
route[inbound_from_carrier_deliver] {
	record_route();
	create_dialog("BPp");
	route(resolve_os_src);
	$dlg_val(os_dst) = $rU;
	$acc_extra(src) = $dlg_val(os_src);
	$acc_extra(dst) = $rU;
	$acc_extra(cli) = $fU;
	$acc_extra(ip) = $si;
	do_accounting("db", "cdr|missed|failed");
	$avp(os_media) = "relay";
	route(apply_media);
	t_on_reply("handle_reply");
	route(relay);
}

# Direct = skip RTPengine; Relay = rtpengine_manage(); Record = rtpengine + recording
# dtmf_pass=no → strip telephone-event so RFC2833 DTMF is not negotiated
route[apply_media] {
	$var(dtmf_flags) = "";
	if ($avp(os_dtmf) == "no") {
		$var(dtmf_flags) = " codec-strip-telephone-event";
	}
	if ($avp(os_media) == "direct") {
		xlog("L_INFO", "media=direct for $fU (no rtpengine)\n");
		return;
	}
	if ($avp(os_media) == "record") {
		xlog("L_INFO", "media=record for $fU (rtpengine + record)\n");
		rtpengine_manage("replace-session-connection replace-origin ICE=remove recording-label=$ci$var(dtmf_flags)");
		return;
	}
	if ($(var(dtmf_flags){s.len}) > 0) {
		rtpengine_manage("replace-session-connection replace-origin ICE=remove$var(dtmf_flags)");
		return;
	}
	rtpengine_manage();
}

# SIP-user CallerID policy (before trunk From override):
#   passthrough  = leave From as ViciDial / Eyebeam sent; still prefer Display digits for CDR
#   fixed        = force portal CallerID (rpid / os_cid)
#   display_name = use Eyebeam/softphone Display Name as From user when it looks like a number
# Eyebeam: From: "12138007000" <sip:owner@host>  →  $fn=display, $fU=owner
# IMPORTANT: append_hf(From) does NOT refresh $fU — store presented CLI in $dlg_val(os_cli)
# so ACC / CDR CallerID is the number, not the SIP username.
route[apply_sip_cid] {
	# Preserve CLI already resolved on an earlier call (before do_accounting).
	# A second call after From rewrite can no longer read Display Name from $fn.
	$var(prior_cli) = $dlg_val(os_cli);
	if ($var(prior_cli) == NULL || $(var(prior_cli){s.len}) == 0 || $var(prior_cli) == $fU
		|| !($var(prior_cli) =~ "^[+0-9]{3,30}$")) {
		$dlg_val(os_cli) = $fU;
		$var(prior_cli) = "";
	}
	$var(mode) = $avp(os_cid_mode);
	$var(user) = $fU;
	$var(dom) = $fd;
	if ($var(dom) == NULL || $(var(dom){s.len}) == 0) {
		$var(dom) = $td;
	}

	# ---- Collect Display Name / CLI digits from every place Eyebeam may put them ----
	$var(dn) = $fn;
	if ($var(dn) == NULL || $(var(dn){s.len}) == 0) {
		$var(dn) = $(hdr(From){nameaddr.name});
	}
	# Raw From: "12138007000" <sip:user@host>  or  12138007000 <sip:user@host>
	if (($var(dn) == NULL || $(var(dn){s.len}) == 0) && is_present_hf("From")) {
		$var(rawf) = $hdr(From);
		if ($var(rawf) =~ "\"[+0-9]{3,30}\"") {
			$var(dn) = $(var(rawf){re.subst,/.*"([+0-9]{3,30})".*/\1/});
		} else if ($var(rawf) =~ "^[+0-9]{3,30}[[:space:]]*<") {
			$var(dn) = $(var(rawf){re.subst,/^([+0-9]{3,30})[[:space:]]*<.*/\1/});
		}
	}
	if ($var(dn) == NULL || $(var(dn){s.len}) == 0) {
		$var(dn) = $(hdr(P-Asserted-Identity){nameaddr.name});
	}
	if ($var(dn) == NULL || $(var(dn){s.len}) == 0) {
		$var(dn) = $(hdr(Remote-Party-ID){nameaddr.name});
	}
	if ($var(dn) == NULL || $(var(dn){s.len}) == 0) {
		$var(dn) = $(hdr(P-Preferred-Identity){nameaddr.name});
	}
	# PAI / RPID / PPI may carry the number as URI user (no display name)
	$var(pai_u) = $(hdr(P-Asserted-Identity){nameaddr.uri}{uri.user});
	$var(rpid_u) = $(hdr(Remote-Party-ID){nameaddr.uri}{uri.user});
	$var(ppi_u) = $(hdr(P-Preferred-Identity){nameaddr.uri}{uri.user});

	$var(dn_digits) = "";
	if ($var(dn) != NULL && $(var(dn){s.len}) > 0) {
		$var(dn_digits) = $(var(dn){re.subst,#[^0-9+]##g});
	}
	if (($var(dn_digits) == NULL || $(var(dn_digits){s.len}) < 3) && $var(pai_u) != NULL) {
		$var(dn_digits) = $(var(pai_u){re.subst,#[^0-9+]##g});
	}
	if (($var(dn_digits) == NULL || $(var(dn_digits){s.len}) < 3) && $var(rpid_u) != NULL) {
		$var(dn_digits) = $(var(rpid_u){re.subst,#[^0-9+]##g});
	}
	if (($var(dn_digits) == NULL || $(var(dn_digits){s.len}) < 3) && $var(ppi_u) != NULL) {
		$var(dn_digits) = $(var(ppi_u){re.subst,#[^0-9+]##g});
	}

	xlog("L_INFO", "SIP CID probe mode='$var(mode)' fn='$fn' dn='$var(dn)' digits='$var(dn_digits)' FromUser=$fU auth=$au\n");

	# AVP missing (IP ACL skipped digest) but Display digits present → Softphone Display Name
	if (($var(mode) == NULL || $(var(mode){s.len}) == 0) && $(var(dn_digits){s.len}) >= 3
		&& $(var(dn_digits){s.len}) <= 30 && !($fU =~ "^[+0-9]{8,30}$")) {
		$var(mode) = "display_name";
		xlog("L_INFO", "SIP CID mode inferred display_name (AVP empty) digits='$var(dn_digits)'\n");
	}

	# Always prefer Display digits for CDR when From user is the SIP username (not a phone)
	if ($(var(dn_digits){s.len}) >= 3 && $(var(dn_digits){s.len}) <= 30
		&& ($var(dn_digits) =~ "^[+0-9]{3,30}$") && !($fU =~ "^[+0-9]{8,30}$")) {
		$dlg_val(os_cli) = $var(dn_digits);
		$acc_extra(cli) = $var(dn_digits);
	}

	if ($var(mode) == NULL || $(var(mode){s.len}) == 0 || $var(mode) == "passthrough") {
		if ($dlg_val(os_cli) =~ "^[+0-9]{3,30}$") {
			xlog("L_INFO", "SIP CID passthrough CDR-cli='$dlg_val(os_cli)' (From user=$fU kept)\n");
		} else {
			xlog("L_INFO", "SIP CID passthrough From=$fU fn='$fn' mode='$var(mode)'\n");
		}
		return;
	}

	if ($var(mode) == "fixed" && $avp(os_cid) != NULL && $(avp(os_cid){s.len}) > 0) {
		$var(user) = $(avp(os_cid){re.subst,#[^0-9+]##g});
	} else if ($var(mode) == "display_name") {
		if ($(var(dn_digits){s.len}) >= 3 && $(var(dn_digits){s.len}) <= 30
			&& ($var(dn_digits) =~ "^[+0-9]{3,30}$")) {
			$var(user) = $var(dn_digits);
		} else if ($(var(prior_cli){s.len}) >= 3 && ($var(prior_cli) =~ "^[+0-9]{3,30}$")) {
			# Re-entry after From rewrite: keep digits from the earlier apply_sip_cid call
			$var(user) = $var(prior_cli);
			$dlg_val(os_cli) = $var(prior_cli);
			$acc_extra(cli) = $var(prior_cli);
			xlog("L_INFO", "SIP CID display_name keep prior os_cli=$var(prior_cli)\n");
			return;
		} else if ($avp(os_cid) != NULL && $(avp(os_cid){s.len}) > 0) {
			# Fallback: portal CallerID number when Eyebeam did not send Display Name on INVITE
			$var(user) = $(avp(os_cid){re.subst,#[^0-9+]##g});
			xlog("L_WARN", "SIP CID display_name missing on INVITE — using portal CallerID '$var(user)'\n");
		} else {
			xlog("L_WARN", "SIP CID display_name empty fn='$fn' dn='$var(dn)' From=$fU — set Eyebeam Display Name to digits\n");
			return;
		}
	} else {
		return;
	}
	$dlg_val(os_cli) = $var(user);
	$acc_extra(cli) = $var(user);
	remove_hf("From");
	append_hf("From: <sip:$var(user)@$var(dom)>;tag=$ft\r\n");
	remove_hf("P-Asserted-Identity");
	append_hf("P-Asserted-Identity: <sip:$var(user)@$var(dom)>\r\n");
	remove_hf("Remote-Party-ID");
	append_hf("Remote-Party-ID: <sip:$var(user)@$var(dom)>;party=calling;privacy=off;screen=yes\r\n");
	xlog("L_INFO", "SIP CID mode=$var(mode) to sip:$var(user)@$var(dom) os_cli=$dlg_val(os_cli)\n");
}

# ACC extras after CID/trunk rewrite: keep SIP user in src, presented CLI in cli, trunk gwid in gw
# Never use $fU for cli after From rewrite — $fU stays as the original SIP username.
route[acc_cli_gw] {
	$acc_extra(src) = $dlg_val(os_src);
	$acc_extra(dst) = $dlg_val(os_dst);
	$acc_extra(ip) = $si;
	if ($dlg_val(os_cli) != NULL && $(dlg_val(os_cli){s.len}) > 0) {
		$acc_extra(cli) = $dlg_val(os_cli);
	} else {
		$acc_extra(cli) = $fU;
	}
	# Prefer gw_id_avp (gwid like T12); fall back to attrs tid=
	$var(gw) = $avp(dr_gw_id);
	if ($var(gw) == NULL || $(var(gw){s.len}) == 0) {
		$var(gw) = $avp(___dr_gw_id);
	}
	if (($var(gw) == NULL || $(var(gw){s.len}) == 0) && $avp(dr_gw_attrs) != NULL) {
		$var(tid) = $(avp(dr_gw_attrs){param.value,tid});
		if ($var(tid) != NULL && $(var(tid){s.len}) > 0) {
			$var(gw) = "T" + $var(tid);
		}
	}
	$acc_extra(gw) = $var(gw);
	xlog("L_INFO", "ACC cli=$acc_extra(cli) sip=$dlg_val(os_src) gw=$var(gw)\n");
}

# Multi remove-prefix (attrs rstrip=22,011,00) — strip tech prefix from RURI before send
route[apply_ruri_strip] {
	if ($avp(dr_gw_attrs) == NULL) {
		return;
	}
	$var(attrs) = $avp(dr_gw_attrs);
	$var(rs) = $(var(attrs){param.value,rstrip});
	if ($var(rs) == NULL || $(var(rs){s.len}) == 0) {
		return;
	}
	$var(rs) = $(var(rs){s.unescape.param});
	$var(i) = 0;
	while ($var(i) < 8) {
		$var(p) = $(var(rs){s.select,$var(i),,});
		if ($var(p) == NULL || $(var(p){s.len}) == 0) {
			break;
		}
		if ($(rU{s.substr,0,$(var(p){s.len})}) == $var(p)) {
			$rU = $(rU{s.substr,$(var(p){s.len}),0});
			xlog("L_INFO", "trunk remove-prefix $var(p) RURI user=$rU\n");
			break;
		}
		$var(i) = $var(i) + 1;
	}
}

# Apply Magnus Fromuser / Fromdomain / CID prefixes from gateway attrs (fu=;fd=;cidadd=;cidrem=)
# When fu= is set it ALWAYS overrides SIP-user CallerID and PBX-sent From (ViciDial etc.).
# Start from os_cli (display_name / fixed already applied) — $fU is still the SIP username.
route[apply_trunk_attrs] {
	if ($avp(dr_gw_attrs) == NULL) {
		return;
	}
	$var(attrs) = $avp(dr_gw_attrs);
	$var(fu) = $(var(attrs){param.value,fu});
	$var(fd) = $(var(attrs){param.value,fd});
	$var(cidadd) = $(var(attrs){param.value,cidadd});
	$var(cidrem) = $(var(attrs){param.value,cidrem});

	if ($dlg_val(os_cli) != NULL && $(dlg_val(os_cli){s.len}) > 0) {
		$var(user) = $dlg_val(os_cli);
	} else {
		$var(user) = $fU;
	}
	if ($var(cidrem) != NULL && $(var(cidrem){s.len}) > 0) {
		$var(cidrem) = $(var(cidrem){s.unescape.param});
		if ($(var(user){s.substr,0,$(var(cidrem){s.len})}) == $var(cidrem)) {
			$var(user) = $(var(user){s.substr,$(var(cidrem){s.len}),0});
		}
	}
	if ($var(cidadd) != NULL && $(var(cidadd){s.len}) > 0) {
		$var(user) = $(var(cidadd){s.unescape.param}) + $var(user);
	}
	if ($var(fu) != NULL && $(var(fu){s.len}) > 0) {
		$var(user) = $(var(fu){s.unescape.param});
	}
	# Never send quoted/display junk as SIP user part toward the vendor
	$var(user) = $(var(user){re.subst,#[^0-9A-Za-z+_-]##g});
	$var(dom) = $fd;
	if ($var(fd) != NULL && $(var(fd){s.len}) > 0) {
		$var(dom) = $(var(fd){s.unescape.param});
	}
	if (($var(fu) != NULL && $(var(fu){s.len}) > 0) || ($var(fd) != NULL && $(var(fd){s.len}) > 0)
		|| ($var(cidadd) != NULL && $(var(cidadd){s.len}) > 0) || ($var(cidrem) != NULL && $(var(cidrem){s.len}) > 0)) {
		$dlg_val(os_cli) = $var(user);
		remove_hf("From");
		append_hf("From: <sip:$var(user)@$var(dom)>;tag=$ft\r\n");
		remove_hf("P-Asserted-Identity");
		append_hf("P-Asserted-Identity: <sip:$var(user)@$var(dom)>\r\n");
		xlog("L_INFO", "trunk From rewrite to sip:$var(user)@$var(dom) os_cli=$dlg_val(os_cli)\n");
	}
}

# SIP-user CallerID digit length (from subscriber cid_min_len / cid_max_len)
# Use os_cli (presented CLI) — $fU is still the SIP username after From rewrite.
route[check_cid_len] {
	if ($dlg_val(os_cli) != NULL && $(dlg_val(os_cli){s.len}) > 0) {
		$var(clid) = $(dlg_val(os_cli){re.subst,#[^0-9]##g});
	} else {
		$var(clid) = $(fU{re.subst,#[^0-9]##g});
	}
	$var(clen) = $(var(clid){s.len});
	if ($avp(os_cid_min) != NULL && $(avp(os_cid_min){s.int}) > 0 && $var(clen) < $(avp(os_cid_min){s.int})) {
		xlog("L_WARN", "Wrong CallerID Length min user=$au cli=$var(clid) len=$var(clen)\n");
		send_reply(484, "Wrong CallerID Length");
		exit;
	}
	if ($avp(os_cid_max) != NULL && $(avp(os_cid_max){s.int}) > 0 && $var(clen) > $(avp(os_cid_max){s.int})) {
		xlog("L_WARN", "Wrong CallerID Length max user=$au cli=$var(clid) len=$var(clen)\n");
		send_reply(484, "Wrong CallerID Length");
		exit;
	}
}

# Rate CAP: portal compares buy rate vs SIP-user CAP and trunk CAP (bin/dial-cap-check.php)
# (OPENSIPS_RATE_CAP_ROUTE injected on its own line — never put multiline placeholders inside # comments)
@@OPENSIPS_RATE_CAP_ROUTE@@

# DNC: trunk attrs dncid=N → Redis SISMEMBER or indexed MySQL dnc_cache (never PHP exec)
@@OPENSIPS_DNC_ROUTE@@

# SIP-user destination format (subscriber.dst_format via load_credentials): plus | digits | as_is
route[apply_sip_dst_format] {
	if ($avp(os_dstfmt) == NULL || $(avp(os_dstfmt){s.len}) == 0 || $avp(os_dstfmt) == "as_is") {
		return;
	}
	$var(d) = $(rU{re.subst,#[^0-9]##g});
	if ($(var(d){s.len}) < 3) {
		return;
	}
	if ($avp(os_dstfmt) == "plus" || $avp(os_dstfmt) == "e164_plus") {
		$rU = "+" + $var(d);
		xlog("L_INFO", "sip dst_format=plus RURI=$rU\n");
		return;
	}
	if ($avp(os_dstfmt) == "digits" || $avp(os_dstfmt) == "e164_digits") {
		$rU = $var(d);
		xlog("L_INFO", "sip dst_format=digits RURI=$rU\n");
	}
}

# Trunk destination format (attrs dstfmt=plus|digits) — applied after SIP user format
route[apply_trunk_dst_format] {
	if ($avp(dr_gw_attrs) == NULL) {
		return;
	}
	$var(df) = $(avp(dr_gw_attrs){param.value,dstfmt});
	if ($var(df) == NULL || $(var(df){s.len}) == 0 || $var(df) == "as_is") {
		return;
	}
	$var(d) = $(rU{re.subst,#[^0-9]##g});
	if ($(var(d){s.len}) < 3) {
		return;
	}
	if ($var(df) == "plus" || $var(df) == "e164_plus") {
		$rU = "+" + $var(d);
		xlog("L_INFO", "trunk dst_format=plus RURI=$rU\n");
		return;
	}
	if ($var(df) == "digits" || $var(df) == "e164_digits") {
		$rU = $var(d);
		xlog("L_INFO", "trunk dst_format=digits RURI=$rU\n");
	}
}

# Trunk attrs cidmin=/cidmax= after From rewrite
route[check_trunk_cid_len] {
	if ($avp(dr_gw_attrs) == NULL) {
		return;
	}
	$var(attrs) = $avp(dr_gw_attrs);
	$var(cmin) = $(var(attrs){param.value,cidmin});
	$var(cmax) = $(var(attrs){param.value,cidmax});
	# Use presented CLI (os_cli) — $fU stays SIP username after From rewrite
	if ($dlg_val(os_cli) != NULL && $(dlg_val(os_cli){s.len}) > 0) {
		$var(clid) = $(dlg_val(os_cli){re.subst,#[^0-9]##g});
	} else {
		$var(clid) = $(fU{re.subst,#[^0-9]##g});
	}
	$var(clen) = $(var(clid){s.len});
	if ($var(cmin) != NULL && $(var(cmin){s.int}) > 0 && $var(clen) < $(var(cmin){s.int})) {
		xlog("L_WARN", "Wrong CallerID Length trunk min len=$var(clen)\n");
		send_reply(484, "Wrong CallerID Length");
		exit;
	}
	if ($var(cmax) != NULL && $(var(cmax){s.int}) > 0 && $var(clen) > $(var(cmax){s.int})) {
		xlog("L_WARN", "Wrong CallerID Length trunk max len=$var(clen)\n");
		send_reply(484, "Wrong CallerID Length");
		exit;
	}
}

route[relay] {
	if (!t_relay()) {
		send_reply(500, "Internal Error");
	}
	exit;
}

onreply_route[handle_reply] {
	if (has_body("application/sdp") && $avp(os_media) != "direct") {
		rtpengine_manage();
	}
}

failure_route[carrier_failover] {
	if (t_was_cancelled()) {
		exit;
	}
	# Only fail over on codes from Settings → Failover SIP codes (synced below)
	# @@FAILOVER_SIP_STATUS@@ is replaced at deploy / settings save, e.g. "408|480|486|503"
	if (!t_check_status("@@FAILOVER_SIP_STATUS@@")) {
		xlog("L_INFO", "failover skip: reply $T_reply_code not in failover list\n");
		rtpengine_delete();
		exit;
	}
	xlog("L_WARN", "failover after code=$T_reply_code trying next trunk\n");
	# try next gateway of the selected route
	if (use_next_gw()) {
		route(check_dnc);
		route(apply_ruri_strip);
		route(apply_sip_cid);
		route(check_cid_len);
		route(apply_trunk_attrs);
		route(check_trunk_cid_len);
		route(check_rate_cap);
		route(apply_sip_dst_format);
		route(apply_trunk_dst_format);
		route(acc_cli_gw);
		t_on_failure("carrier_failover");
		t_relay();
		exit;
	}
	rtpengine_delete();
}
