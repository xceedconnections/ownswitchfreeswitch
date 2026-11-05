# FreeSWITCH portal

This repository is the FreeSWITCH build of the portal. It does not install OpenSIPS. Sofia listens on UDP/TCP 5060. The portal answers directory and dialplan requests on the loopback vhost and controls live calls over ESL (`127.0.0.1:8021`).

Repository: https://github.com/xceedconnections/ownswitchfreeswitch

The OpenSIPS portal stays in https://github.com/xceedconnections/ownswitch. Do not clone that repository onto a server you want to run as FreeSWITCH.

## Fresh Ubuntu 24.04

```bash
git clone https://github.com/xceedconnections/ownswitchfreeswitch.git /opt/ownswitch-src
cd /opt/ownswitch-src
sudo bash deploy/install.sh
```

The first run builds FreeSWITCH from source. That takes 20–40 minutes. When it finishes, health must show `freeswitch` active and `SIP 5060` owned by FreeSWITCH.

Then register the phones. Domain can be the public IP or the portal hostname. Usernames and passwords are the ones saved in the portal. Live milliseconds are FreeSWITCH OPTIONS to the registered contact. Hangup is ESL `uuid_kill`. CDRs arrive from `mod_xml_cdr`.

Push trunks into Sofia after the portal is up:

```bash
sudo -u ownswitch env OWNSWITCH_ENV_FILE=/etc/ownswitch/ownswitch.env \
  php /opt/ownswitch/current/bin/sync-opensips-trunks.php
```

## A machine that already has OpenSIPS

Stop OpenSIPS, let FreeSWITCH take port 5060, then remove the OpenSIPS packages. This does not drop the `ownswitch` database.

```bash
cd /opt/ownswitch-src
git fetch origin
git reset --hard origin/main
grep -q '^FREESWITCH_ENABLED=' /etc/ownswitch/install.conf \
  && sed -i 's/^FREESWITCH_ENABLED=.*/FREESWITCH_ENABLED=1/' /etc/ownswitch/install.conf \
  || echo 'FREESWITCH_ENABLED=1' >> /etc/ownswitch/install.conf
sudo bash deploy/install.sh --only 90
sudo bash deploy/install.sh --only 75
sudo bash deploy/remove-opensips.sh
sudo bash deploy/install.sh --only 99
```

Phones must register again. SIP usernames and passwords stay.
