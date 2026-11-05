# Ownswitch on Hetzner Cloud

## Recommended server

Use **Ubuntu 24.04 LTS**, **x86_64 (Intel/AMD)** — not ARM (CAX).

| Use case | Hetzner type (approx.) | vCPU | RAM | Disk | Notes |
|---|---|---|---|---|---|
| **Lab / first install** | **CX32** or **CPX32** | 4 | 8 GB | 80 GB | Minimum comfortable size |
| **Production (recommended)** | **CX42** or **CPX42** | 8 | 16 GB | 160 GB | Billing + SIP + recording |
| **Higher traffic / FreeSWITCH heavy** | **CX52** / **CPX52** or larger | 16 | 32 GB | 240 GB+ | Transcoding + many concurrent calls |
| Avoid | CX22 (2 vCPU / 4 GB) | 2 | 4 GB | — | Too tight for CGRateS + FS build |
| Avoid | CAX (ARM) | — | — | — | Installer requires amd64 |

**Location:** pick the region closest to your customers/carriers (nbg1, fsn1, hel1, ash, hil, …).

**Networking:**

- Attach a **public IPv4** (required for SIP/RTP and Let’s Encrypt).
- Optional private network if you split DB later (single-node install does not need it).
- In **Hetzner Cloud Firewall**, allow inbound:
  - TCP **22** (SSH — restrict to your IP)
  - TCP **80**, **443** (portal / ACME)
  - UDP+TCP **5060** (SIP — optionally restrict to known customer/carrier CIDRs)
  - UDP **30000–40000** (RTP / RTPengine)
- Do **not** open 3306, 6379, 2012/2013 (CGRateS), 8021 (FreeSWITCH ESL).

**DNS:** create an **A record** `voip.yourdomain.com → server public IP` before or right after install.

## One-command install

SSH into the new server, then:

```bash
curl -fsSL https://raw.githubusercontent.com/xceedconnections/ownswitch/main/install-server.sh \
  | sudo bash -s -- \
      --domain voip.yourdomain.com \
      --email ops@yourdomain.com \
      --password 'ChooseALongPassword1!'
```

Omit `--password` to auto-generate (saved under `/root/ownswitch-credentials.txt`).

### What gets installed

- MySQL **8.4 LTS** (databases `ownswitch`, `opensips`, `cgrates`)
- Redis (CGRateS DataDB)
- OpenSIPS **3.6** + mysql/cgrates/rtpengine-related modules
- CGRateS **v0.10.4**
- RTPengine + recording daemon
- FreeSWITCH **1.10.12** (default on; use `--no-freeswitch` to skip)
- Nginx + PHP **8.3**-FPM + Ownswitch portal
- Firewall, fail2ban (SSH), workers, backup timer
- First administrator account + migrations

Expect **20–90 minutes** depending on CPU (FreeSWITCH source build is the slow part).

### Faster install (no FreeSWITCH)

Billing, routing, OpenSIPS, CGRateS, RTPengine still install:

```bash
curl -fsSL https://raw.githubusercontent.com/xceedconnections/ownswitch/main/install-server.sh \
  | sudo bash -s -- --domain voip.yourdomain.com --email ops@yourdomain.com --no-freeswitch
```

## After install

1. Open `https://voip.yourdomain.com` and log in with the printed credentials.
2. Change the admin password.
3. Create carriers, rate plans (set customer **and** carrier increments), customers, SIP users / IP auth.
4. Point customer devices at `voip.yourdomain.com:5060`.
5. Review `/var/lib/ownswitch-install/reports/latest.txt`.

## Re-run / upgrade

```bash
cd /opt/ownswitch-src && sudo git pull
sudo -E ./deploy/install.sh --yes
```

See also `docs/INSTALL.md`, `docs/UPGRADE.md`, `docs/OPERATIONS.md`, `docs/ACCEPTANCE.md`.
