#!KAMAILIO
#
# ============================================================================
#  API Users gateway – Kamailio front door (template)
#  Rendered to /etc/kamailio/kamailio.cfg by entrypoint.sh (envsubst, GW_* vars).
#  Edit THIS file, not the rendered one.
#
#  Three UDP sockets on 192.168.8.100:
#    vpn  :5070  – Zoiper via AstroWarp. Advertises the AstroWarp virtual IP.
#    lan  :5072  – Zoiper at home on Wi-Fi.
#    pbx  :5071  – ONLY the PBX (192.168.8.220) may talk to this one.
#
#  Rules:
#    * Kamailio holds NO users/passwords. Every REGISTER/INVITE is forwarded to
#      the PBX (192.168.8.220:5099) and Asterisk challenges it. Permissions are
#      enforced by the PBX dialplan contexts.
#    * Client-originated requests can only ever go to the PBX (no open relay).
#    * Path header on REGISTER lets the PBX reach the phone back through us.
#    * rtpengine relays all audio (interfaces "vpn" and "lan").
#
#  ZOIPER-PRO-TODO (public TLS, no VPN):
#    1. disable_tcp=no, enable_tls=yes, loadmodule "tls.so",
#       modparam("tls","config","/etc/kamailio/tls.cfg") (cert from Let's Encrypt).
#    2. listen=tls:${GW_LAN_IP}:5443 advertise <public-domain-or-IP>:5443 name "pub"
#    3. In request_route, treat $Rp == 5443 like the client sockets with
#       $var(net)="pub"; add_path_received("pub"); force the "pub" socket on the
#       way back (route[TO_CLIENT]).
#    4. rtpengine: add interface pub/192.168.8.100!<public IP>, and for the
#       "pub" side use flags "RTP/SAVP" (SRTP-SDES to Zoiper) while the PBX side
#       stays "RTP/AVP".
#    5. Stricter pike + consider a fail2ban jail on the Kamailio log.
#    Search the project for ZOIPER-PRO-TODO to find every spot.
# ============================================================================

####### Global parameters #########
debug=2
log_stderror=yes
log_facility=LOG_LOCAL0
fork=yes
children=4
auto_aliases=no
disable_tcp=yes          # ZOIPER-PRO-TODO: set to no when adding TLS
server_signature=no
sip_warning=0
user_agent_header="User-Agent: gw"
server_header="Server: gw"
maxbuffer=65536

listen=udp:${GW_LAN_IP}:${GW_VPN_PORT} advertise ${GW_VPN_ADVERTISE_IP}:${GW_VPN_PORT} name "vpn"
listen=udp:${GW_LAN_IP}:${GW_LAN_PORT} name "lan"
listen=udp:${GW_LAN_IP}:${GW_PBX_SIDE_PORT} name "pbx"

####### Modules ########
loadmodule "tm.so"
loadmodule "tmx.so"
loadmodule "sl.so"
loadmodule "rr.so"
loadmodule "pv.so"
loadmodule "maxfwd.so"
loadmodule "textops.so"
loadmodule "siputils.so"
loadmodule "xlog.so"
loadmodule "sanity.so"
loadmodule "path.so"
loadmodule "nathelper.so"
loadmodule "rtpengine.so"
loadmodule "pike.so"

modparam("tm", "fr_timer", 5000)
modparam("tm", "fr_inv_timer", 60000)
modparam("rr", "enable_double_rr", 1)
modparam("path", "use_received", 1)
modparam("nathelper", "natping_interval", 0)   # PBX qualifies every 30s through us = keepalive
modparam("rtpengine", "rtpengine_sock", "udp:127.0.0.1:22222")
modparam("pike", "sampling_time_unit", 2)
modparam("pike", "reqs_density_per_unit", 30)
modparam("pike", "remove_latency", 120)

####### Routing ########
request_route {
	$var(net) = "lan";
	$var(fromside) = "client";

	if ($Rp == ${GW_PBX_SIDE_PORT}) {
		# PBX-only socket
		if ($si != "${GW_PBX_IP}") {
			xlog("L_WARN", "drop: $si:$sp hit the PBX-only socket\n");
			exit;
		}
		$var(fromside) = "pbx";
	} else {
		if ($si == "${GW_PBX_IP}") { exit; }   # PBX must use its own socket
		if ($Rp == ${GW_VPN_PORT}) { $var(net) = "vpn"; }

		if (!pike_check_req()) {
			xlog("L_ALERT", "pike: flood from $si, dropping\n");
			exit;
		}
		if ($(ua{s.tolower}) =~ "(friendly-scanner|sipvicious|sipcli|sip-scan|sundayddr|vaxsip|pplsip|iwar|sipsak)") {
			exit;
		}
		# Things API users never need (also blocks transfers at the edge).
		# In-dialog INFO is still allowed (some phones send DTMF that way).
		if (is_method("REFER|PUBLISH|SUBSCRIBE|MESSAGE|INFO")) {
			if (!(is_method("INFO") && has_totag())) {
				sl_send_reply("403", "Not allowed");
				exit;
			}
		}
	}

	if (!mf_process_maxfwd_header("10")) {
		sl_send_reply("483", "Too Many Hops");
		exit;
	}
	if (!sanity_check()) {
		exit;
	}

	if (is_method("CANCEL")) {
		if (t_check_trans()) {
			rtpengine_delete();
			route(RELAY);
		}
		exit;
	}

	if (!is_method("ACK")) {
		if (t_precheck_trans()) {
			t_check_trans();
			exit;
		}
		t_check_trans();
	}

	if (has_totag()) {
		route(WITHINDLG);
		exit;
	}

	if ($var(fromside) == "client") {
		route(FROM_CLIENT);
	} else {
		route(FROM_PBX);
	}
}

# ---------- new requests from a phone: ALWAYS to the PBX -------------------
route[FROM_CLIENT] {
	if (is_method("ACK")) {
		if (t_check_trans()) { route(RELAY); }
		exit;
	}
	# Only API-user accounts may pass through this gateway. Asterisk picks the endpoint by
	# the From user or the Authorization username, so without this check someone on your
	# VPN / Wi-Fi could try the PBX's REAL extensions (701, 702 ...) through the gateway.
	if (!($fU =~ "^apiu-[0-9a-f]+$") || ($au != $null && !($au =~ "^apiu-[0-9a-f]+$"))
	    || (is_method("REGISTER") && !($tU =~ "^apiu-[0-9a-f]+$"))) {
		xlog("L_NOTICE", "blocked non-API username from=$fU auth=$au door=$var(net) src=$si:$sp\n");
		sl_send_reply("403", "Forbidden");
		exit;
	}
	remove_hf("Route");          # never let a client steer us anywhere
	force_send_socket(udp:${GW_LAN_IP}:${GW_PBX_SIDE_PORT});

	if (is_method("REGISTER")) {
		# Asterisk rejects Path (420) unless the REGISTER says it supports it.
		# Phones like Zoiper don't, so the proxy vouches for it.
		if (!($hdr(Supported) =~ "path")) {
			append_hf("Supported: path\r\n");
		}
		if ($var(net) == "vpn") {
			add_path_received("vpn");
		} else {
			add_path_received("lan");
		}
	}
	if (is_method("INVITE")) {
		set_contact_alias();
		record_route();
		if ($var(net) == "vpn") {
			add_rr_param(";net=vpn");
		} else {
			add_rr_param(";net=lan");
		}
		route(RTPE);
	}

	$rd = "${GW_PBX_IP}";
	$rp = ${GW_PBX_PORT};
	$du = "sip:${GW_PBX_IP}:${GW_PBX_PORT}";
	route(RELAY);
}

# ---------- new requests from the PBX (INVITE / OPTIONS qualify / NOTIFY) --
route[FROM_PBX] {
	if ($hdr(Route) =~ "sip:vpn@") {
		$var(net) = "vpn";
	}
	# Path route with ;received= -> path module sets $du to the phone's real address.
	# (loose_route() returns -3 here because the only Route is our own; that's
	#  expected, so we check the destination instead of the return code.)
	loose_route();
	if ($du == $null) {
		sl_send_reply("404", "No route to API user");
		exit;
	}
	if (is_method("INVITE")) {
		record_route();
		if ($var(net) == "vpn") {
			add_rr_param(";net=vpn");
		} else {
			add_rr_param(";net=lan");
		}
		route(RTPE);
	}
	route(TO_CLIENT_SOCKET);
	route(RELAY);
}

# ---------- in-dialog (BYE, re-INVITE, ACK ...) ----------------------------
route[WITHINDLG] {
	if (!loose_route()) {
		if (is_method("ACK") && t_check_trans()) {
			route(RELAY);
			exit;
		}
		sl_send_reply("404", "Not here");
		exit;
	}
	if (check_route_param("net=vpn")) {
		$var(net) = "vpn";
	}

	if ($var(fromside) == "client") {
		# anti-relay: a phone can only reach the PBX
		if ($du != $null && $(du{uri.host}) != "${GW_PBX_IP}") {
			xlog("L_WARN", "anti-relay: $si tried to route to $du\n");
			sl_send_reply("403", "Forbidden");
			exit;
		}
		if ($du == $null && $rd != "${GW_PBX_IP}") {
			sl_send_reply("403", "Forbidden");
			exit;
		}
		force_send_socket(udp:${GW_LAN_IP}:${GW_PBX_SIDE_PORT});
		if (is_method("INVITE|UPDATE")) { set_contact_alias(); }
	} else {
		handle_ruri_alias();     # phone behind NAT/VPN: use the alias we stored
		route(TO_CLIENT_SOCKET);
	}

	if (is_method("BYE")) {
		rtpengine_delete();
	} else if (is_method("INVITE|UPDATE|ACK|PRACK")) {
		route(RTPE);
	}
	route(RELAY);
}

route[TO_CLIENT_SOCKET] {
	if ($var(net) == "vpn") {
		force_send_socket(udp:${GW_LAN_IP}:${GW_VPN_PORT});
	} else {
		force_send_socket(udp:${GW_LAN_IP}:${GW_LAN_PORT});
	}
	# ZOIPER-PRO-TODO: else-if net == "pub" -> force_send_socket(tls:${GW_LAN_IP}:5443)
}

# ---------- media: offers carry direction, answers just follow the call ----
route[RTPE] {
	if (!has_body("application/sdp")) { return; }
	if ($var(fromside) == "client") {
		$var(rf) = "direction=" + $var(net) + " direction=lan replace-origin replace-session-connection ICE=remove RTP/AVP SIP-source-address";
	} else {
		$var(rf) = "direction=lan direction=" + $var(net) + " replace-origin replace-session-connection ICE=remove RTP/AVP";
	}
	# ZOIPER-PRO-TODO: for net "pub" use RTP/SAVP toward the phone (SRTP), RTP/AVP toward the PBX.
	rtpengine_manage("$var(rf)");
}

route[RELAY] {
	if (is_method("INVITE|UPDATE|PRACK")) {
		t_on_reply("MANAGE_REPLY");
	}
	if (is_method("INVITE")) {
		t_on_failure("MANAGE_FAILURE");
	}
	if (!t_relay()) {
		sl_reply_error();
	}
	exit;
}

onreply_route[MANAGE_REPLY] {
	if ($si == "${GW_PBX_IP}") {
		# reply travelling PBX -> phone
		if (has_body("application/sdp")) {
			rtpengine_manage("replace-origin replace-session-connection ICE=remove RTP/AVP");
		}
	} else {
		# reply travelling phone -> PBX
		if (status =~ "(1[0-9][0-9])|(2[0-9][0-9])") {
			set_contact_alias();
		}
		if (has_body("application/sdp")) {
			rtpengine_manage("replace-origin replace-session-connection ICE=remove RTP/AVP SIP-source-address");
		}
	}
}

failure_route[MANAGE_FAILURE] {
	rtpengine_delete();
}
