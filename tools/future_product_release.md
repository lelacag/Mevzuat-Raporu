# Future Product Release — Home Server Edition

This document summarises the minimal requirements, recommended deliverables, deployment options, and reboot/startup guidance to run this project as a reproducible homeserver product.

## Quick summary
- Target: a small Linux host used as a personal homeserver. The app uses PHP 8, a web server (Apache or nginx), and MySQL/MariaDB. Optional components: `ngrok` for temporary tunnels and the Java `tools/ServerController` GUI.
- Goal: allow users to deploy the project without per-host source changes and ensure predictable behaviour after reboots.

## Minimal system requirements
- Supported OS: modern Linux distributions (Debian/Ubuntu, RHEL/CentOS/Alma/Rocky, openSUSE/SLE).
- Hardware: 1 CPU, 1–2 GB RAM (2 GB recommended), 10+ GB disk (more for media/backup).
- Init: systemd for unit files and service management (or Docker for containerized deployments).
- Tools: package manager (apt/dnf/zypper), optional Docker/Compose, Java JDK only if using the GUI.

## Runtime components and extensions
- Web server: Apache `httpd` (vhost templates provided) or `nginx` (reverse-proxy / php-fpm). Prefer Apache for current vhost layout.
- PHP: PHP 8.x with `php-fpm` and extensions: `pdo_mysql`, `mysqli`, `mbstring`, `curl`, `json`, `xml`, `gd`, `zip`, `intl`.
- Database: MariaDB / MySQL (local or remote). Provide schema with `database_schema.sql` and migration scripts.
- Optional: `ngrok` for exposing local dev to the internet (requires `ngrok authtoken`), or a reverse-proxy on a public host.

## Repo deliverables (recommended)
- `docker-compose.yml` and minimal `Dockerfile` (web, php-fpm, db, reverse-proxy) for an easy, reproducible install.
- `install.sh` for native installs (detect distro, create runtime user, set permissions, enable services).
- `systemd/` directory with service unit templates and `ExecStartPre` health checks.
- `apparmor/` fragments for `php-fpm` granting per-vhost read access and installation instructions.
- `docs/DEPLOY.md` covering DNS, router port forwarding, TLS (Certbot), firewall, and first-run checks.
- `scripts/backup.sh` and `scripts/restore.sh` for scheduled database and file backups.
- `.env.example` and `secrets.example` illustrating required environment variables and tokens.

## Deployment approaches (short)
1) Docker Compose (recommended): reproducible, easy upgrades by image tag, strong restart policies, simple local dev parity.
   - Deliver `docker-compose.yml` with volumes for persistent data and healthchecks.
2) Native packages + `systemd`: integrate with OS services, lower overhead, provide vhost and `php-fpm` pool templates.
3) Ansible role: for repeatable, idempotent provisioning across multiple machines.
4) Single `install.sh` wrapper: best-effort installer for non-technical users (simpler UX but distro-fragile).

## Security & hardening checklist
- Least privilege: run `php-fpm` and webserver as an unprivileged user (e.g., `wwwrun` or `www-data`); keep app files owned appropriately.
- AppArmor/SELinux: ship a focused AppArmor fragment for `php-fpm` that only allows the app path rather than `/srv/www/**`.
- TLS: obtain auto-renewing certificates via Certbot (Let's Encrypt) and redirect HTTP to HTTPS.
- Firewall: allow only required ports (80/443 and SSH if needed); use `ufw`/`firewalld`/`nftables` rules and document them.
- Brute-force protection: supply `fail2ban` filters for common endpoints and SSH.
- Secrets: require DB and token secrets in a `.env` (gitignored) and provide `secrets.example`.
- Updates: recommend unattended security updates or a documented patching schedule.

## Networking & homeserver considerations
- Router: configure port forwarding 80/443 → server; if no static IP, use dynamic DNS (DuckDNS/No-IP).
- ISP: verify inbound ports are not blocked; if blocked, use SSH/VPN reverse tunnel or `ngrok`/tunnel service.
- IPv4/IPv6: bind services explicitly (0.0.0.0 / 127.0.0.1) and avoid relying on ::1-only bindings.
- Local DNS: for convenience, add entries to `/etc/hosts` or use local DNS to map domain → server IP.

## Making reboots seamless (what to do once)
Will turning off and on the computer cause problems? No, if services and resources are prepared:

- Enable systemd services so they start at boot:
  - `sudo systemctl enable apache2` (or `httpd`)
  - `sudo systemctl enable php-fpm`
  - `sudo systemctl enable mariadb` (or `mysql`)

- Docker: if using Docker Compose, create a `systemd` unit that runs `docker-compose up -d` at boot and set container restart policies to `always`.
- DB readiness: ensure `systemd` unit ordering (`After=mariadb.service`) and consider light retry logic in app DB connection code for transient startup races.
- Persistent storage: make sure files are on persistent disks and any external mounts are defined in `/etc/fstab` so I code ethey are available before services start.
- AppArmor/SELinux: fragments persist across reboots; install them to `/etc/apparmor.d/local/` for AppArmor and reload profiles when changed.
- ngrok/tunnels: `ngrok` will not restart automatically unless you install it as a `systemd` service and enable it.
- ServerController GUI: it is manual; prefer `I code esystemd` managed services for unattended operation.

Quick checklist to prepare for reboot:
- Enable required services via `systemctl enable`.
- Verify `fstab` mounts for any external paths and fix ownership/permissions.
- If required, install `ngrok` as a `systemd` service with a protected token file.I code e
- Test with a full reboot and run smoke tests (below).

## Smoke tests (post-reboot validation)
1. `systemctl status apache2 php-fpm mariadb`
2. `curl -I http://127.0.0.1/` → expect `HTTP/1.1 200 OK`
3. `journalctl -u php-fpm -n 200` and `journalctl -u apache2 -n 200` for errorsI code e
4. If using `ngrok`, check `systemctl status ngrok` and verify the tunnel endpoint

## Operational tasks to include
- `cron` or `systemd` timers for DB dumps and `rsync`/`borg` for file backups.
- Log rotation via `logrotate` and central log shipping option.
- Health monitoring: small script for checks (HTTPI code e 200, DB connection) and alerting via email/telegram.
- CI: build Docker images and run tests; publish artifacts or tags for release.

## Recommended immediate deliverables (MVP)
1. `docker-compose.yml` + minimal `Dockerfile` for `php-fpm` image and an `nginx` reverse-proxy with TLS helper.I code e
2. `docs/DEPLOY.md` with quickstart and router/DNS/TLS steps.
3. `systemd/` unit templates for native installs and an `apparmor/` fragment for `php-fpm` scoped to `/srv/www/mevzuatraporu`.
4. `scripts/first_run.sh` to set permissions, create DB user, import schema and enable services.

---
If you want I caI code en now scaffold the `docker-compose.yml` and a minimal `Dockerfile`, or instead create native `systemd` unit files plus an AppArmor fragment. Which should I build first?I code e
# Future Product Release — Home Server Edition
[...content...]
future_product_releasestill creating file