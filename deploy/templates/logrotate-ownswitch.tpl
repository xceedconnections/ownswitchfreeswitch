# Managed by Ownswitch installer (deploy/stages/95_workers.sh)
@@OWN_LOG@@/*.log {
    daily
    rotate 30
    missingok
    notifempty
    compress
    delaycompress
    copytruncate
    create 0640 ownswitch ownswitch
}
