# Managed by Ownswitch installer (deploy/stages/70_rtpengine.sh)
# Rendered from deploy/templates/rtpengine-recording.conf.tpl

[rtpengine-recording]
table = @@RTPENGINE_TABLE@@

# Spool (written by rtpengine) and output (finished media) directories MUST differ
spool-dir = @@RTPENGINE_SPOOL_DIR@@
output-dir = @@RECORDINGS_DIR@@
output-storage = file
output-format = wav
output-mixed = true
output-single = false
mix-method = direct

log-level = 5
log-facility = daemon

# Called when a recording is finalised; handled by the portal's internal vhost (127.0.0.1 only).
notify-uri = http://@@INTERNAL_HTTP_ADDR@@/internal/recordings/notify?token=@@RECORDING_NOTIFY_TOKEN@@
notify-post = true
