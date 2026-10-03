#!/usr/bin/env bash
# =============================================================================
#  proxmox-firewall.sh – optional: Proxmox firewall for the gateway container (default CT 103)
#  Run on the Proxmox HOST shell (root@pve):  bash proxmox-firewall.sh
#
#  Replaces the earlier rules (8000/8080) with:
#    8000/tcp  panel       -> only NPM (192.168.8.147)
#    8010/tcp  user portal -> only NPM
#    5071/udp  PBX socket  -> only the PBX (192.168.8.220)
#    everything else stays open (policy_in ACCEPT) so mail etc. keep working.
#  Phones reach 5070/5072 + RTP 40000-40100 (AstroWarp / home LAN): allowed by policy.
#  ZOIPER-PRO-TODO: no change needed here for TLS 5443 (policy ACCEPT), but forward
#  TCP 5443 + UDP 40000-40100 on the GL.iNet router to 192.168.8.100.
# =============================================================================
set -euo pipefail
CT="${CT:-103}"
NPM_IP="${NPM_IP:-192.168.8.147}"
PBX_IP="${PBX_IP:-192.168.8.220}"
F=/etc/pve/firewall/$CT.fw

[ -d /etc/pve/firewall ] || { echo "run this on the Proxmox host"; exit 1; }
mkdir -p /root/fw-backup
[ -f "$F" ] && cp -a "$F" "/root/fw-backup/$CT.fw.$(date +%Y%m%d-%H%M%S)"

cat > "$F" <<EOF
[OPTIONS]
enable: 1
policy_in: ACCEPT
policy_out: ACCEPT

[RULES]
IN ACCEPT -source $NPM_IP -p tcp -dport 8000 -log nolog # NPM -> API Users panel
IN DROP -p tcp -dport 8000 -log nolog # panel: nobody else
IN ACCEPT -source $NPM_IP -p tcp -dport 8010 -log nolog # NPM -> user portal
IN DROP -p tcp -dport 8010 -log nolog # portal: nobody else
IN ACCEPT -source $PBX_IP -p udp -dport 5071 -log nolog # PBX -> Kamailio pbx socket
IN DROP -p udp -dport 5071 -log nolog # pbx socket: nobody else
EOF

pve-firewall compile >/dev/null && echo "Syntax OK"
sleep 10
pve-firewall status
echo "Rules now on CT $CT:"; grep -E '^IN ' "$F"
