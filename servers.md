# Server Control Guide

This is a quick reference for starting and stopping the local development servers used by the application.

> **Tip**: under some locales the desktop folder may be named "Masaüstü" instead of "Desktop"; check that directory if you can't spot the launcher icon.


> **Prerequisite:** make sure a working `ngrok` binary is installed and you've run `ngrok authtoken <token>`.
> The bundled binary in this repo may be a zero-byte placeholder; download a proper release from
> https://ngrok.com/download if needed.


## Starting the servers

You can still run the individual commands manually as shown below, but there's now a helper Java program in `tools` that does everything for you.

```bash
# compile the controller once (requires JDK)
cd /srv/www/mevzuatraporu/tools
sudo javac ServerController.java ServerControllerGUI.java

# start everything from the repo root:
cd /srv/www/mevzuatraporu
# - command‑line mode:
sudo java -cp tools ServerController on
# - GUI mode (requires an X display, use with X11 forwarding or locally):
sudo java -cp tools ServerController gui

  The GUI now shows **Start** and **Stop** buttons along with a status line indicating whether the PHP server and ngrok tunnel are running. Logs are visible in the main pane.

  The GUI also provides buttons to start/stop **Apache** and **MySQL** and will display currently configured IP addresses detected on the machine so you can see which host:port pairs to use. The controller prefers Apache if it is active (it will skip the PHP built-in server to avoid port conflicts).

  To run the GUI from a desktop session, double-click the launcher on your Desktop (or `Masaüstü`) or run the command above from a local terminal. If you are on a remote/headless server use SSH X11 forwarding (`ssh -Y user@host`) then run the GUI command in the SSH session.

# to stop services:
sudo java -cp tools ServerController off
```

Alternatively, manual steps are:

```bash
# move into the project directory
cd /srv/www/mevzuatraporu

# 1. start PHP's built-in web server with router support for clean URLs
php -S localhost:8081 dev-router.php > /tmp/phpout.log 2>&1 &
# record its PID for later shutdown
echo $! > /tmp/phpserverpid

# 2. (optional) expose the site via ngrok
ngrok http 8081

# 3. verify it's up
curl -I http://127.0.0.1:8081/topluluklar
``` 

*Note*: the built-in server does **not** support Apache-style `.htaccess` rewriting by default, so we provide `dev-router.php` in this repo to enable clean URL paths like `/topluluklar`, `/bildirimler`, `/davet-et`, `/edmin`.

If you prefer using Apache:

```bash
sudo systemctl start apache2      # or `service apache2 start`
sudo systemctl status apache2
```  
(make sure the virtual host is configured to point at `/srv/www/mevzuatraporu`)

## Stopping the servers

```bash
# kill the PHP CLI server, if running
if [ -f /tmp/phpserverpid ]; then
    kill "$(cat /tmp/phpserverpid)" 2>/dev/null || true
    rm /tmp/phpserverpid
fi

# kill any ngrok process
pkill -f ngrok || true

# (optional) stop Apache
sudo systemctl stop apache2

# cleanup log file if you want
rm -f /tmp/phpout.log
```

## Notes

* ngrok runs in the foreground; press Ctrl+C to stop it if you launched it manually.
* Always stop the services before shutting your machine or switching networks.
* Feel free to adapt these commands to your personal workflow; this document is just a reminder.
