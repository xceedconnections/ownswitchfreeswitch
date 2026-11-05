# FreeSWITCH replaces OpenSIPS

Sofia on FreeSWITCH listens on UDP/TCP 5060 and advertises this host's public IP. The portal answers directory and dialplan requests on the loopback vhost and controls live calls over ESL (`127.0.0.1:8021`). Phones, trunks, the routing waterfall, DNC, rate cap, balance pause, live calls, hangup, and CDRs stay in the portal.

Only one process may own port 5060. OpenSIPS is stopped before FreeSWITCH starts, then removed. The `ownswitch` database is not dropped. SIP usernames and passwords stay. Phones must register again.

Run this on the server, from a root shell, after the new code is on the host (`/opt/ownswitch-src`).

```bash
cd /opt/ownswitch-src
git fetch origin
git reset --hard origin/main

# Stage 75 is skipped while this stays 0 in the persisted installer config.
grep -q '^FREESWITCH_ENABLED=' /etc/ownswitch/install.conf \
  && sed -i 's/^FREESWITCH_ENABLED=.*/FREESWITCH_ENABLED=1/' /etc/ownswitch/install.conf \
  || echo 'FREESWITCH_ENABLED=1' >> /etc/ownswitch/install.conf

# Portal first (xml_curl endpoints), then build/enable FreeSWITCH.
# The source build takes 20-40 minutes the first time. Stage 75 stops OpenSIPS
# so Sofia can bind 5060, writes the ESL password into ownswitch.env, and points
# mod_xml_curl / mod_xml_cdr at http://127.0.0.1:8081/internal/fs/ .
sudo bash deploy/install.sh --only 90
sudo bash deploy/install.sh --only 75

# Push the current trunks into Sofia gateways.
sudo -u ownswitch env OWNSWITCH_ENV_FILE=/etc/ownswitch/ownswitch.env \
  php /opt/ownswitch/current/bin/sync-opensips-trunks.php

# Purge the OpenSIPS packages, unit, and /etc/opensips. Does not drop MySQL.
sudo bash deploy/remove-opensips.sh

sudo bash deploy/install.sh --only 99
```

Health must show `freeswitch` active and `SIP 5060` owned by FreeSWITCH. It does not require the `opensips` service, `opensips.cfg`, or MI port 8888.

After that, register the phones again against the same usernames and passwords. Domain can be the public IP or the portal hostname. Live milliseconds are FreeSWITCH OPTIONS to the registered contact. Hangup is ESL `uuid_kill` (BYE both ways). CDRs arrive from `mod_xml_cdr` into the existing CDR list.
