# Managed by Ownswitch installer (deploy/stages/70_rtpengine.sh)
# Rendered from deploy/templates/rtpengine.conf.tpl  -  RTPengine 11.5.x

[rtpengine]
# kernel forwarding table (0) when xt_RTPENGINE is loaded, -1 = userspace only
table = @@RTPENGINE_TABLE@@

# Media interfaces. "name!advertised" form is used when the host is behind NAT.
interface = @@RTPENGINE_INTERFACE@@

# Control (ng) protocol - loopback only, used by OpenSIPS rtpengine module
listen-ng = 127.0.0.1:@@RTPENGINE_NG_PORT@@

port-min = @@RTP_PORT_MIN@@
port-max = @@RTP_PORT_MAX@@

log-level = 5
log-facility = daemon

# Recording (see PLAN/ARCHITECTURE: recordings are verified before recording_status=success)
recording-dir = @@RTPENGINE_SPOOL_DIR@@
recording-method = @@RTPENGINE_RECORDING_METHOD_EFFECTIVE@@
recording-format = eth

# Hardening / sanity
delete-delay = 30
timeout = 60
silent-timeout = 3600
