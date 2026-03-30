import java.io.BufferedReader;
import java.io.IOException;
import java.io.InputStreamReader;
import java.nio.file.Files;
import java.nio.file.Path;
import java.nio.file.Paths;
import java.time.Duration;
import java.util.ArrayList;
import java.util.List;
import java.util.concurrent.TimeUnit;

public class ServerController {
    private static final Path WEBROOT = Paths.get("/srv/www/mevzuatraporu");
    private static final Path RUNDIR = Paths.get("/var/run/servercontroller");
    private static final Path LOGDIR = Paths.get("/var/log/servercontroller");
    // fallback to user-local dirs if /var is not writable
    private static final Path USER_RUNDIR = Paths.get(System.getProperty("user.home"), ".servercontroller", "run");
    private static final Path USER_LOGDIR = Paths.get(System.getProperty("user.home"), ".servercontroller", "log");
    private static final Path USER_CONFIG = Paths.get(System.getProperty("user.home"), ".servercontroller", "config.properties");
    // PID files are written to whichever run dir is actually usable at runtime

    public static void main(String[] args) throws Exception {
        if (args.length == 0) {
            usage();
            return;
        }
        switch (args[0]) {
            case "on":
                startAll();
                break;
            case "off":
                stopAll();
                break;
            case "count":
                System.out.println(getOnlineUserCount());
                if (getLastOnlineCountError() != null) System.err.println("error: " + getLastOnlineCountError());
                break;
            case "restart-ngrok":
                restartNgrok();
                break;
            case "status":
                printStatus();
                break;
            case "log":
                if (args.length > 1) {
                    printLog(args[1]);
                } else {
                    System.out.println("Usage: java ServerController log <service>");
                }
                break;
            case "health":
                checkHealth();
                break;
            case "gui":
                javax.swing.SwingUtilities.invokeLater(() -> {
                    try {
                        ServerControllerGUI.createAndShowGUI();
                    } catch (Exception e) {
                        e.printStackTrace();
                    }
                });
                break;
            default:
                usage();
        }
    }

    public static void checkHealth() {
        System.out.println("=== Health Check ===");
        boolean ngrokUp = isNgrokRunning();
        String tunnel = getNgrokTunnelUrl();
        if (!ngrokUp || tunnel == null) {
            System.out.println("Ngrok tunnel is offline. Attempting restart...");
            try {
                restartNgrok();
                Thread.sleep(2000);
                tunnel = getNgrokTunnelUrl();
                if (tunnel != null) {
                    System.out.println("Ngrok tunnel restarted: " + tunnel);
                } else {
                    System.out.println("Ngrok restart failed.");
                }
            } catch (Exception e) {
                System.out.println("Error restarting ngrok: " + e.getMessage());
            }
        } else {
            System.out.println("Ngrok tunnel is healthy: " + tunnel);
        }
    }

    public static void printLog(String service) {
        Path logdir = Files.exists(LOGDIR) && Files.isWritable(LOGDIR) ? LOGDIR : USER_LOGDIR;
        String logFile = null;
        switch (service) {
            case "ngrok": logFile = logdir.resolve("ngrok.log").toString(); break;
            case "apache": logFile = "/var/log/apache2/error.log"; break;
            case "php": logFile = logdir.resolve("phpout.log").toString(); break;
            case "mysql": logFile = "/var/log/mysql/error.log"; break;
            default:
                System.out.println("Unknown service: " + service);
                return;
        }
        try {
            Process p = new ProcessBuilder("bash", "-c", "tail -n 40 " + logFile).start();
            p.waitFor(2, TimeUnit.SECONDS);
            try (BufferedReader r = new BufferedReader(new InputStreamReader(p.getInputStream()))) {
                String line;
                while ((line = r.readLine()) != null) System.out.println(line);
            }
        } catch (Exception e) {
            System.out.println("Error reading log: " + e.getMessage());
        }
    }

    public static void printStatus() {
        System.out.println("=== Server Status ===");
        try {
            System.out.println("Apache:   " + (isServiceActive("apache2") ? "Running" : "Stopped"));
        } catch (Exception e) { System.out.println("Apache:   Error"); }
        System.out.println("PHP-FPM:  " + (isPhpRunning() ? "Running" : "Stopped"));
        try {
            System.out.println("MySQL:    " + (isServiceActive("mysql") ? "Running" : "Stopped"));
        } catch (Exception e) { System.out.println("MySQL:    Error"); }
        System.out.println("Ngrok:    " + (isNgrokRunning() ? "Running" : "Stopped"));
        try {
            String tunnel = getNgrokTunnelUrl();
            System.out.println("Tunnel URL: " + (tunnel != null ? tunnel : "(none)"));
        } catch (Exception e) { System.out.println("Tunnel URL: Error"); }
        try {
            Path pidFile = getRunDir().resolve("ngrokpid");
            if (Files.exists(pidFile)) {
                String pid = Files.readString(pidFile).trim();
                System.out.println("Ngrok PID: " + pid);
            }
        } catch (Exception e) {}
    }

    // Helper to get tunnel URL from ngrok API
    public static String getNgrokTunnelUrl() {
        try {
            Process p = new ProcessBuilder("curl", "-s", "http://localhost:4040/api/tunnels").start();
            p.waitFor(2, TimeUnit.SECONDS);
            try (BufferedReader r = new BufferedReader(new InputStreamReader(p.getInputStream()))) {
                StringBuilder sb = new StringBuilder();
                String line;
                while ((line = r.readLine()) != null) sb.append(line);
                String json = sb.toString();
                int idx = json.indexOf("public_url");
                if (idx >= 0) {
                    int start = json.indexOf('"', idx + 12);
                    int end = json.indexOf('"', start + 1);
                    if (start > 0 && end > start) return json.substring(start + 1, end);
                }
            }
        } catch (Exception e) {}
        return null;
    }

    public static java.util.Properties getConfig() {
        try {
            java.util.Properties cfg = loadConfig();
            if (validateAndFixConfig(cfg)) {
                saveConfig(cfg);
            }
            return cfg;
        } catch (Exception e) {
            return new java.util.Properties();
        }
    }

    private static boolean validateAndFixConfig(java.util.Properties cfg) {
        boolean changed = false;
        // Trim whitespace and remove accidental newlines in key properties.
        for (String key : new String[]{"ngrok.path", "ngrok.domain", "ngrok.port"}) {
            if (cfg.containsKey(key)) {
                String val = cfg.getProperty(key);
                if (val != null) {
                    String clean = val.replaceAll("[\r\n]", "").trim();
                    if (!clean.equals(val)) {
                        cfg.setProperty(key, clean);
                        changed = true;
                    }
                }
            }
        }
        return changed;
    }

    private static void saveConfig(java.util.Properties cfg) {
        try {
            Path cfgDir = USER_CONFIG.getParent();
            if (cfgDir != null) Files.createDirectories(cfgDir);
            try (java.io.OutputStream out = Files.newOutputStream(USER_CONFIG)) {
                cfg.store(out, "ServerController configuration");
            }
        } catch (Exception ignore) {
            // best-effort; do not fail the entire operation
        }
    }

    private static java.util.Properties loadConfig() throws IOException {
        java.util.Properties p = new java.util.Properties();
        Path cfg = USER_CONFIG;
        if (Files.exists(cfg)) {
            try (java.io.InputStream in = Files.newInputStream(cfg)) {
                p.load(in);
            }
        }
        return p;
    }

    private static void usage()  {
        System.out.println("Usage: java ServerController on|off|gui");
    }

    public static void startAll() throws Exception {
        ensureDirs();
        System.out.println("starting apache and mysql (if present)...");
        startApache();
        startMysql();

        // if Apache is active, prefer it. Otherwise start the PHP built-in server.
        if (isServiceActive("apache2")) {
            System.out.println("apache active; skipping PHP built-in server");
            // wait for Apache on port 80
            waitForPort(80, Duration.ofSeconds(10));
        } else {
            System.out.println("starting php server...");
            startPhp();
            waitForPort(8000, Duration.ofSeconds(10));
        }

        System.out.println("starting ngrok tunnel...");
        startNgrok();
        waitForNgrok(Duration.ofSeconds(10));
        System.out.println("all services appear up");
    }

    private static void ensureDirs() {
        try {
            if (!Files.exists(RUNDIR) || !Files.isWritable(RUNDIR)) {
                Files.createDirectories(USER_RUNDIR);
            } else {
                Files.createDirectories(RUNDIR);
            }
            if (!Files.exists(LOGDIR) || !Files.isWritable(LOGDIR)) {
                Files.createDirectories(USER_LOGDIR);
            } else {
                Files.createDirectories(LOGDIR);
            }
        } catch (Exception e) {
            // best-effort; ignore and rely on fallback paths when used
        }
    }

    public static void stopAll() throws IOException, InterruptedException {
        killPidFile(getRunDir().resolve("phpserverpid"));
        killPidFile(getRunDir().resolve("ngrokpid"));
        System.out.println("stopped services");
    }

    private static void startPhp() throws IOException {
        Path logdir = Files.exists(LOGDIR) && Files.isWritable(LOGDIR) ? LOGDIR : USER_LOGDIR;
        String log = logdir.resolve("phpout.log").toString();
        // bind to 127.0.0.1 to avoid systems where "localhost" resolves to IPv6 ::1 only
        ProcessBuilder pb = new ProcessBuilder("bash", "-c",
            "cd " + WEBROOT + " && php -S 127.0.0.1:8000 -t . > " + log + " 2>&1 & echo $!");
        Process p = pb.start();
        try (BufferedReader r = new BufferedReader(new InputStreamReader(p.getInputStream()))) {
            String pid = r.readLine();
            if (pid != null && !pid.isBlank()) {
                Files.writeString(getRunDir().resolve("phpserverpid"), pid);
            }
        }
    }

    public static void startNgrok() throws IOException, InterruptedException {
        java.util.Properties config = loadConfig();
        String hostname = System.getenv("NGROK_HOSTNAME");
        if ((hostname == null || hostname.isBlank()) && config.containsKey("ngrok.domain")) {
            hostname = config.getProperty("ngrok.domain");
        }
        String customPort = config.getProperty("ngrok.port", "");
        // if Apache is active prefer port 80, otherwise the PHP built-in on 127.0.0.1:8000
        String target = "127.0.0.1:8000";
        try {
            if (isServiceActive("apache2")) target = "127.0.0.1:80";
        } catch (Exception e) {
            // ignore and use default
        }
        if (!customPort.isBlank()) {
            target = "127.0.0.1:" + customPort;
        }
        // determine ngrok executable path and construct command
        String ngrokExec = resolveNgrokExec();

        // verify ngrok version is  supported
        try {
            String ver = getNgrokVersion();
            if (ver == null) throw new IOException("ngrok binary not found or not executable");
            if (!isNgrokSupported(ver)) {
                throw new IOException("ngrok agent too old: " + ver + " (minimum 3.20.0). Please update ngrok from https://ngrok.com/download");
            }
        } catch (IOException e) {
            throw e;
        } catch (Exception ignored) {
            // if version cannot be determined, fail start to avoid running incompatible agent
            throw new IOException("unable to determine ngrok version; please ensure ngrok v3 is installed");
        }
        String cmd = ngrokExec + " http " + target;
        if (hostname != null && !hostname.isBlank()) {
            String ver = getNgrokVersion();
            if (ver != null) {
                String[] parts = ver.split("\\.");
                int major = Integer.parseInt(parts[0]);
                int minor = parts.length > 1 ? Integer.parseInt(parts[1]) : 0;
                // ngrok v3.36+ uses --url, v3.20+ uses --domain, older uses --hostname
                if (major > 3 || (major == 3 && minor >= 36)) {
                    cmd = ngrokExec + " http --url=https://" + hostname + " " + target;
                } else if (major == 3 && minor >= 20) {
                    cmd = ngrokExec + " http --domain=" + hostname + " " + target;
                } else {
                    cmd = ngrokExec + " http --hostname=" + hostname + " " + target;
                }
            } else {
                cmd = ngrokExec + " http --url=https://" + hostname + " " + target;
            }
        }
        Path logdir = Files.exists(LOGDIR) && Files.isWritable(LOGDIR) ? LOGDIR : USER_LOGDIR;
        Files.createDirectories(logdir);
        Files.createDirectories(getRunDir());
        String log = logdir.resolve("ngrok.log").toString();
        // use nohup to keep process running after parent exits and echo the PID
        String startCmd = "nohup " + cmd + " > " + log + " 2>&1 & echo $!";
        ProcessBuilder pb = new ProcessBuilder("bash", "-c", startCmd);
        Process p = pb.start();
        String pid = null;
        try (BufferedReader r = new BufferedReader(new InputStreamReader(p.getInputStream()))) {
            pid = r.readLine();
        }
        if (pid == null || pid.isBlank()) {
            // read tail of log to provide context
            String tail = "";
            try {
                Process tailp = new ProcessBuilder("bash", "-c", "tail -n 50 " + log + " 2>/dev/null || true").start();
                try (BufferedReader tr = new BufferedReader(new InputStreamReader(tailp.getInputStream()))) {
                    StringBuilder sb = new StringBuilder();
                    String line;
                    while ((line = tr.readLine()) != null) sb.append(line).append('\n');
                    tail = sb.toString();
                }
            } catch (Exception ignore) {}
            throw new IOException("failed to start ngrok (no pid). Log tail:\n" + tail);
        }
        pid = pid.trim();
        // verify process exists
        if (!Files.exists(Paths.get("/proc").resolve(pid))) {
            throw new IOException("ngrok process not found after start (pid=" + pid + ")");
        }
        Files.writeString(getRunDir().resolve("ngrokpid"), pid);
    }

    public static void stopNgrok() throws IOException, InterruptedException {
        // prefer stopping a systemd unit if present (user or system), otherwise fall back to pidfile or pkill
        try {
            if (systemdUnitExists("ngrok")) {
                stopService("ngrok", Duration.ofSeconds(5));
                Files.deleteIfExists(getRunDir().resolve("ngrokpid"));
                // wait briefly for processes to exit
                for (int i = 0; i < 10; i++) {
                    if (!isNgrokRunning()) break;
                    Thread.sleep(300);
                }
                return;
            }
        } catch (Exception e) {
            // ignore and continue to fallback
        }

        Path pidFile = getRunDir().resolve("ngrokpid");
        // if we have a pidfile, validate it points to an ngrok instance and kill it
        if (Files.exists(pidFile)) {
            String pid = Files.readString(pidFile).trim();
            if (!pid.isBlank() && Files.exists(Paths.get("/proc").resolve(pid))) {
                try {
                    // check cmdline contains ngrok or the resolved binary
                    String cmdline = Files.readString(Paths.get("/proc").resolve(pid).resolve("cmdline")).replace('\0',' ');
                    String resolved = resolveNgrokExec();
                    if (cmdline.contains("ngrok") || (resolved != null && !resolved.isBlank() && cmdline.contains(resolved))) {
                        // try graceful kill
                        new ProcessBuilder("kill", pid).start().waitFor();
                        for (int i = 0; i < 10; i++) {
                            if (!Files.exists(Paths.get("/proc").resolve(pid))) break;
                            Thread.sleep(300);
                        }
                        if (Files.exists(Paths.get("/proc").resolve(pid))) {
                            new ProcessBuilder("kill", "-9", pid).start().waitFor();
                        }
                    }
                } catch (Exception ignore) {}
            }
            Files.deleteIfExists(pidFile);
            // ensure no other ngrok processes remain
            if (!isNgrokRunning()) return;
        }

        // No pidfile or still running: try pkill by resolved path then by process name
        String resolved = resolveNgrokExec();
        try {
            if (resolved != null && !resolved.isBlank()) {
                new ProcessBuilder("bash", "-c", "pkill -u $(id -u) -f '" + resolved.replace("'","'\\''") + "' || true").start().waitFor();
            }
        } catch (Exception ignore) {}

        // fallback to generic pkill of any ngrok processes owned by this user
        // Use -x (exact process name match) instead of -f to avoid matching the
        // Java process whose cmdline contains 'ngrok' as an argument (e.g.
        // 'java ServerController restart-ngrok'), which would kill the JVM itself.
        try {
            new ProcessBuilder("bash", "-c", "pkill -x -u $(id -u) ngrok || true").start().waitFor();
        } catch (Exception ignore) {}

        // wait for processes to disappear, else force kill
        for (int i = 0; i < 10; i++) {
            if (!isNgrokRunning()) break;
            Thread.sleep(300);
        }
        if (isNgrokRunning()) {
            try { new ProcessBuilder("bash", "-c", "pkill -9 -x -u $(id -u) ngrok || true").start().waitFor(); } catch (Exception ignore) {}
        }
        Files.deleteIfExists(pidFile);
    }

    /**
     * Restart the tunnel by stopping the current ngrok process and starting
     * a new one.  Caller may wish to wrap this in logging or a timeout.
     */
    public static void restartNgrok() throws IOException, InterruptedException {
        stopNgrok();
        startNgrok();
    }

    public static boolean isNgrokRunning() {
        try {
            Path pidFile = getRunDir().resolve("ngrokpid");
            if (Files.exists(pidFile)) {
                String pid = Files.readString(pidFile).trim();
                if (!pid.isEmpty() && Files.exists(Paths.get("/proc").resolve(pid))) return true;
            }
            // fallback: check for running ngrok processes for this user
            Process p = new ProcessBuilder("bash", "-c", "pgrep -u $(id -u) -a ngrok || true").start();
            try (BufferedReader r = new BufferedReader(new InputStreamReader(p.getInputStream()))) {
                String line;
                while ((line = r.readLine()) != null) {
                    if (!line.isBlank()) return true;
                }
            }
        } catch (Exception e) {
            // ignore
        }
        return false;
    }

    // last error message (for GUI logging) when count lookup returns -1
    private static String lastOnlineCountError = null;

    /**
     * Returns the number of currently online users by invoking a PHP CLI snippet.
     * Falls back to -1 on error and stores diagnostic text in lastOnlineCountError.
     */
    public static int getOnlineUserCount() {
        lastOnlineCountError = null;
        try {
            // run a short PHP snippet to load config/db and count online users
            // find php binary in PATH
            String phpBin = null;
            try {
                Process which = new ProcessBuilder("bash","-c","command -v php || true").start();
                which.waitFor(1, TimeUnit.SECONDS);
                try (BufferedReader r = new BufferedReader(new InputStreamReader(which.getInputStream()))) {
                    phpBin = r.readLine();
                }
            } catch (Exception ignore) {}
            if (phpBin == null || phpBin.isBlank()) {
                lastOnlineCountError = "php binary not found";
                return -1;
            }
            // use an absolute path rather than __DIR__ (PHP -r sets __DIR__ to cwd)
            String php = "require '" + WEBROOT.resolve("includes/db.php").toString() + "'; $pdo=db_connect(); echo (int)$pdo->query('SELECT COUNT(*) FROM users WHERE is_online = 1')->fetchColumn();";
            ProcessBuilder pb = new ProcessBuilder(phpBin, "-r", php);
            Process p = pb.start();
            // wait briefly for command to finish
            p.waitFor(2, TimeUnit.SECONDS);
            try (BufferedReader r = new BufferedReader(new InputStreamReader(p.getInputStream()))) {
                String line = r.readLine();
                if (line != null && !line.isBlank()) {
                    try { return Integer.parseInt(line.trim()); } catch (NumberFormatException ignore) {}
                }
            }
            // if nothing on stdout, read stderr for clues
            try (BufferedReader err = new BufferedReader(new InputStreamReader(p.getErrorStream()))) {
                StringBuilder sb = new StringBuilder();
                String eLine;
                while ((eLine = err.readLine()) != null) {
                    sb.append(eLine).append("\n");
                }
                if (sb.length() > 0) {
                    lastOnlineCountError = sb.toString().trim();
                }
            }
        } catch (Exception e) {
            lastOnlineCountError = e.getMessage();
        }
        return -1;
    }

    public static String getLastOnlineCountError() {
        return lastOnlineCountError;
    }

    private static boolean systemdUnitExists(String name) throws IOException, InterruptedException {
        // check user unit first, then system unit
        Process pUser = new ProcessBuilder("bash", "-c", "systemctl --user cat " + name + ".service >/dev/null 2>&1 && echo yes || true").start();
        if (pUser.waitFor(2, TimeUnit.SECONDS)) {
            try (BufferedReader r = new BufferedReader(new InputStreamReader(pUser.getInputStream()))) {
                String out = r.readLine();
                if (out != null && out.trim().equals("yes")) return true;
            }
        }
        Process pSystem = new ProcessBuilder("bash", "-c", "systemctl cat " + name + ".service >/dev/null 2>&1 && echo yes || true").start();
        if (pSystem.waitFor(2, TimeUnit.SECONDS)) {
            try (BufferedReader r = new BufferedReader(new InputStreamReader(pSystem.getInputStream()))) {
                String out = r.readLine();
                return out != null && out.trim().equals("yes");
            }
        }
        return false;
    }

    public static boolean isPhpRunning() {
        try {
            return Files.exists(getRunDir().resolve("phpserverpid"));
        } catch (Exception e) {
            return false;
        }
    }

    private static Path getRunDir() {
        try {
            if (Files.exists(RUNDIR) && Files.isWritable(RUNDIR)) return RUNDIR;
        } catch (Exception e) {
            // fallthrough to user dir
        }
        return USER_RUNDIR;
    }

    private static Path getLogDir() {
        try {
            if (Files.exists(LOGDIR) && Files.isWritable(LOGDIR)) return LOGDIR;
        } catch (Exception e) {
            // fallthrough to user log dir
        }
        return USER_LOGDIR;
    }

    public static void startApache() throws IOException {
        // attempt to start apache via sudo and wait for it to become active
        try {
            startService("apache2", Duration.ofSeconds(10));
        } catch (InterruptedException e) {
            Thread.currentThread().interrupt();
        }
    }

    public static void stopApache() throws IOException, InterruptedException {
        stopService("apache2", Duration.ofSeconds(10));
    }

    public static void startMysql() throws IOException {
        // try common service names via sudo and wait for one to become active
        try {
            if (startService("mysql", Duration.ofSeconds(10))) return;
            if (startService("mariadb", Duration.ofSeconds(10))) return;
            startService("mysqld", Duration.ofSeconds(10));
        } catch (InterruptedException e) {
            Thread.currentThread().interrupt();
        }
    }

    public static void stopMysql() throws IOException, InterruptedException {
        // stop all common service names and wait for inactive
        stopService("mysql", Duration.ofSeconds(10));
        stopService("mariadb", Duration.ofSeconds(10));
        stopService("mysqld", Duration.ofSeconds(10));
    }

    private static boolean startService(String name, Duration timeout) throws IOException, InterruptedException {
        Path log = getLogDir().resolve(name + ".ctl.log");
        Files.createDirectories(log.getParent());
        ProcessBuilder pb = new ProcessBuilder("bash", "-c", "sudo systemctl start " + name + " || true");
        pb.redirectErrorStream(true);
        // append process output directly to the log file to avoid blocking reads
        pb.redirectOutput(java.lang.ProcessBuilder.Redirect.appendTo(log.toFile()));
        Process p = pb.start();
        // give the command a short moment to run, but we don't block reading its output here
        p.waitFor(5, TimeUnit.SECONDS);
        long start = System.nanoTime();
        while (Duration.ofNanos(System.nanoTime() - start).compareTo(timeout) < 0) {
            try {
                if (isServiceActive(name)) return true;
            } catch (Exception e) {
                // ignore and retry
            }
            Thread.sleep(500);
        }
        return false;
    }

    private static boolean stopService(String name, Duration timeout) throws IOException, InterruptedException {
        Path log = getLogDir().resolve(name + ".ctl.log");
        Files.createDirectories(log.getParent());
        ProcessBuilder pb = new ProcessBuilder("bash", "-c", "sudo systemctl stop " + name + " || true");
        pb.redirectErrorStream(true);
        // append process output directly to the log file to avoid blocking reads
        pb.redirectOutput(java.lang.ProcessBuilder.Redirect.appendTo(log.toFile()));
        Process p = pb.start();
        // give the command a short moment to run, but we don't block reading its output here
        p.waitFor(5, TimeUnit.SECONDS);
        long start = System.nanoTime();
        while (Duration.ofNanos(System.nanoTime() - start).compareTo(timeout) < 0) {
            try {
                if (!isServiceActive(name)) return true;
            } catch (Exception e) {
                // if isServiceActive fails, assume stopped and return true
                return true;
            }
            Thread.sleep(500);
        }
        return false;
    }

    public static boolean isServiceActive(String name) throws IOException, InterruptedException {
        Process p = new ProcessBuilder("bash", "-c", "systemctl is-active " + name).start();
        if (p.waitFor(3, TimeUnit.SECONDS) && p.exitValue() == 0) {
            try (BufferedReader r = new BufferedReader(new InputStreamReader(p.getInputStream()))) {
                String out = r.readLine();
                return out != null && out.trim().equals("active");
            }
        }
        return false;
    }

    public static List<String> getLocalIPs() throws IOException {
        List<String> ips = new ArrayList<>();
        try {
            Process p = new ProcessBuilder("bash", "-c", "hostname -I 2>/dev/null || true").start();
            try (BufferedReader r = new BufferedReader(new InputStreamReader(p.getInputStream()))) {
                String line = r.readLine();
                if (line != null && !line.isBlank()) {
                    for (String ip : line.trim().split("\\s+")) {
                        if (!ip.startsWith("127.")) ips.add(ip);
                    }
                }
            }
        } catch (Exception e) {
            // ignore
        }
        return ips;
    }

    private static void waitForPort(int port, Duration timeout) throws IOException, InterruptedException {
        long start = System.nanoTime();
        while (Duration.ofNanos(System.nanoTime() - start).compareTo(timeout) < 0) {
            Process p = new ProcessBuilder("bash", "-c", "ss -ltn | awk '{print $4}'").start();
            try (BufferedReader r = new BufferedReader(new InputStreamReader(p.getInputStream()))) {
                String line;
                while ((line = r.readLine()) != null) {
                    if (line.endsWith(":" + port) || line.endsWith("." + port)) {
                        return;
                    }
                }
            }
            Thread.sleep(500);
        }
        throw new IOException("timeout waiting for port " + port);
    }

    private static void waitForUrl(String url, Duration timeout) throws IOException, InterruptedException {
        // removed: this helper was unused. Use external curl checks when needed.
    }

    private static void waitForNgrok(Duration timeout) throws IOException, InterruptedException {
        long start = System.nanoTime();
        while (Duration.ofNanos(System.nanoTime() - start).compareTo(timeout) < 0) {
            String exec = resolveNgrokExec();
            Process p = new ProcessBuilder(exec, "api", "tunnels", "list").start();
            if (p.waitFor(3, TimeUnit.SECONDS) && p.exitValue() == 0) {
                return;
            }
            Thread.sleep(500);
        }
        throw new IOException("timeout waiting for ngrok API");
    }

    private static void killPidFile(Path pidFile) throws IOException, InterruptedException {
        if (Files.exists(pidFile)) {
            String pid = Files.readString(pidFile).trim();
            if (!pid.isEmpty()) {
                // try graceful termination then force if still running
                try {
                    new ProcessBuilder("kill", pid).start().waitFor();
                } catch (Exception e) {
                    // ignore
                }
                // wait up to 5s for process to exit
                try {
                    for (int i = 0; i < 10; i++) {
                        if (!Files.exists(Paths.get("/proc").resolve(pid))) break;
                        Thread.sleep(500);
                    }
                    if (Files.exists(Paths.get("/proc").resolve(pid))) {
                        // force kill
                        try { new ProcessBuilder("kill", "-9", pid).start().waitFor(); } catch (Exception ex) {}
                    }
                } catch (InterruptedException ie) {
                    // restore interrupt
                    Thread.currentThread().interrupt();
                }
            }
            Files.deleteIfExists(pidFile);
        }
    }

    private static String resolveNgrokExec() {
        String ngrokExec = "ngrok";
        try {
            java.util.Properties cfg = loadConfig();
            String cfgPath = cfg.getProperty("ngrok.path");
            if (cfgPath != null && !cfgPath.isBlank()) ngrokExec = cfgPath;
        } catch (Exception ignored) {}
        Path userLocal = Paths.get(System.getProperty("user.home"), ".local", "bin", "ngrok");
        if ((ngrokExec == null || ngrokExec.equals("ngrok")) && Files.exists(userLocal) && Files.isExecutable(userLocal)) {
            ngrokExec = userLocal.toString();
        }
        if (ngrokExec.equals("ngrok")) {
            try {
                Process p = new ProcessBuilder("bash", "-c", "command -v ngrok || true").start();
                if (p.waitFor(2, TimeUnit.SECONDS)) {
                    try (BufferedReader r = new BufferedReader(new InputStreamReader(p.getInputStream()))) {
                        String line = r.readLine();
                        if (line != null && !line.isBlank()) ngrokExec = line.trim();
                    }
                }
            } catch (Exception ignored) {}
        }
        return ngrokExec;
    }

    public static String getNgrokVersion() throws IOException, InterruptedException {
        String exec = resolveNgrokExec();
        if (exec == null || exec.isBlank()) return null;
        Process p = new ProcessBuilder(exec, "version").start();
        if (p.waitFor(3, TimeUnit.SECONDS)) {
            try (BufferedReader r = new BufferedReader(new InputStreamReader(p.getInputStream()))) {
                String line;
                StringBuilder sb = new StringBuilder();
                while ((line = r.readLine()) != null) {
                    sb.append(line).append('\n');
                }
                String out = sb.toString().trim();
                if (out.isEmpty()) return null;
                // expected formats: 'ngrok version 3.20.0' or 'ngrok version 2.3.41'
                java.util.regex.Matcher m = java.util.regex.Pattern.compile("(\\d+\\.\\d+(?:\\.\\d+)?)").matcher(out);
                if (m.find()) return m.group(1);
                return out.split("\\s+")[0];
            }
        }
        return null;
    }

    public static boolean isNgrokSupported(String ver) {
        if (ver == null) return false;
        try {
            String[] parts = ver.split("\\.");
            int major = Integer.parseInt(parts[0]);
            int minor = parts.length > 1 ? Integer.parseInt(parts[1]) : 0;
            int patch = parts.length > 2 ? Integer.parseInt(parts[2]) : 0;
            if (major > 3) return true;
            if (major < 3) return false;
            // major == 3
            if (minor > 20) return true;
            if (minor < 20) return false;
            return patch >= 0; // any 3.20.x or greater
        } catch (Exception e) {
            return false;
        }
    }
}
