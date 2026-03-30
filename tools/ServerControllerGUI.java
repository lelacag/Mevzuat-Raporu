import javax.swing.*;
import java.awt.*;
import java.awt.event.ActionEvent;
import java.io.IOException;
import java.io.BufferedReader;
import java.io.InputStreamReader;
import java.util.concurrent.TimeUnit;
import java.nio.file.Files;
import java.nio.file.Path;
import java.nio.file.Paths;
import java.util.List;

public class ServerControllerGUI {
    private static JFrame frame;
    private static JTextArea logArea;
    private static JLabel statusLabel;
    private static JLabel onlineCountLabel;   // new row for user count
    private static JLabel apacheStatusLabel;
    private static JLabel mysqlStatusLabel;
    private static JLabel ngrokStatusLabel;
    private static javax.swing.JButton apacheOnButton;
    private static javax.swing.JButton apacheOffButton;
    private static javax.swing.JButton mysqlOnButton;
    private static javax.swing.JButton mysqlOffButton;
    private static javax.swing.JButton ngrokOnButton;
    private static javax.swing.JButton ngrokOffButton;
    private static javax.swing.JButton ngrokRestartButton;

    public static void createAndShowGUI() {
        frame = new JFrame("Server Controller");
        frame.setDefaultCloseOperation(JFrame.EXIT_ON_CLOSE);
        // make a bit wider so all controls (especially the restart button) display without truncation
        frame.setSize(640, 520);
        frame.setMinimumSize(new Dimension(640, 520));
        frame.setLayout(new BorderLayout());

        // Compact vertical control panel
        frame.setResizable(false);
        JPanel controlPanel = new JPanel(new GridLayout(3, 1, 6, 6));
        controlPanel.setBorder(BorderFactory.createEmptyBorder(8,8,8,8));

        // prepare uniform label and button sizes
        JLabel apacheLabel = new JLabel("Apache:");
        JLabel mysqlLabel = new JLabel("MySQL:");
        JLabel ngrokLabel = new JLabel("ngrok:");
        int maxLabelWidth = Math.max(apacheLabel.getPreferredSize().width,
                Math.max(mysqlLabel.getPreferredSize().width, ngrokLabel.getPreferredSize().width));
        Dimension labelDim = new Dimension(maxLabelWidth, apacheLabel.getPreferredSize().height);
        apacheLabel.setPreferredSize(labelDim);
        mysqlLabel.setPreferredSize(labelDim);
        ngrokLabel.setPreferredSize(labelDim);

        apacheOnButton = new JButton("On");
        apacheOffButton = new JButton("Off");
        // we'll later add the restart button; compute the maximum size after all three exist
        // temporary placeholder dimension; will be overwritten
        Dimension buttonDim = null;

        // Apache row
        JPanel apacheRow = new JPanel(new FlowLayout(FlowLayout.LEFT, 8, 4));
        apacheRow.add(apacheLabel);
        apacheOnButton.setPreferredSize(buttonDim);
        apacheOffButton.setPreferredSize(buttonDim);
        apacheRow.add(apacheOnButton);
        apacheRow.add(apacheOffButton);
        apacheStatusLabel = new JLabel("unknown");
        apacheStatusLabel.setBorder(BorderFactory.createEmptyBorder(0,8,0,0));
        apacheRow.add(apacheStatusLabel);
        controlPanel.add(apacheRow);

        // MySQL row
        JPanel mysqlRow = new JPanel(new FlowLayout(FlowLayout.LEFT, 8, 4));
        mysqlRow.add(mysqlLabel);
        mysqlOnButton = new JButton("On");
        mysqlOffButton = new JButton("Off");
        mysqlOnButton.setPreferredSize(buttonDim);
        mysqlOffButton.setPreferredSize(buttonDim);
        mysqlRow.add(mysqlOnButton);
        mysqlRow.add(mysqlOffButton);
        mysqlStatusLabel = new JLabel("unknown");
        mysqlStatusLabel.setBorder(BorderFactory.createEmptyBorder(0,8,0,0));
        mysqlRow.add(mysqlStatusLabel);
        controlPanel.add(mysqlRow);

        // ngrok row
        JPanel ngrokRow = new JPanel(new FlowLayout(FlowLayout.LEFT, 8, 4));
        ngrokRow.add(ngrokLabel);
        ngrokOnButton = new JButton("On");
        ngrokOffButton = new JButton("Off");
        ngrokRestartButton = new JButton("↻");
        ngrokRestartButton.setToolTipText("Restart the ngrok tunnel");
        JButton ngrokTestButton = new JButton("Test");
        ngrokTestButton.setToolTipText("Test ngrok tunnel connectivity");

        // now that the restart button exists compute a uniform size for all four
        Dimension a = ngrokOnButton.getPreferredSize();
        Dimension b = ngrokOffButton.getPreferredSize();
        Dimension c = ngrokRestartButton.getPreferredSize();
        Dimension d = ngrokTestButton.getPreferredSize();
        int w = Math.max(Math.max(Math.max(a.width, b.width), c.width), d.width);
        int h = Math.max(Math.max(Math.max(a.height, b.height), c.height), d.height);
        buttonDim = new Dimension(w, h);

        ngrokOnButton.setPreferredSize(buttonDim);
        ngrokOffButton.setPreferredSize(buttonDim);
        ngrokRestartButton.setPreferredSize(buttonDim);
        ngrokTestButton.setPreferredSize(buttonDim);
        ngrokStatusLabel = new JLabel("unknown");
        ngrokStatusLabel.setBorder(BorderFactory.createEmptyBorder(0,8,0,0));
        ngrokRow.add(ngrokOnButton);
        ngrokRow.add(ngrokOffButton);
        ngrokRow.add(ngrokRestartButton);
        ngrokRow.add(ngrokTestButton);
        ngrokRow.add(ngrokStatusLabel);
        controlPanel.add(ngrokRow);

        // status label below controls
        statusLabel = new JLabel("status: unknown");
        statusLabel.setBorder(BorderFactory.createEmptyBorder(4,8,4,8));
        // separate label for online user count
        onlineCountLabel = new JLabel("users online: ?");
        onlineCountLabel.setBorder(BorderFactory.createEmptyBorder(2,8,4,8));

        // add settings button on the right
        JButton settingsButton = new JButton("Settings");
        settingsButton.addActionListener((ActionEvent e) -> showSettingsDialog());

        JButton quickSetup = new JButton("Quick Setup");
        quickSetup.addActionListener((ActionEvent e) -> showSetupWizard());

        JPanel north = new JPanel(new BorderLayout());
        north.add(controlPanel, BorderLayout.CENTER);
        // stack status and user count labels vertically
        JPanel statusPane = new JPanel(new GridLayout(2,1));
        statusPane.add(statusLabel);
        statusPane.add(onlineCountLabel);
        north.add(statusPane, BorderLayout.SOUTH);
        JPanel right = new JPanel(new FlowLayout(FlowLayout.RIGHT));
        right.add(quickSetup);
        right.add(settingsButton);
        north.add(right, BorderLayout.EAST);
        frame.add(north, BorderLayout.NORTH);

        logArea = new JTextArea();
        logArea.setEditable(false);
        logArea.setFont(new Font("Monospaced", Font.PLAIN, 12));
        JScrollPane scrollPane = new JScrollPane(logArea);
        scrollPane.setPreferredSize(new Dimension(500, 320));
        frame.add(scrollPane, BorderLayout.CENTER);

        // global start/stop removed — use per-service controls

        apacheOnButton.addActionListener((ActionEvent e) -> {
            new Thread(() -> {
                appendLog("Starting apache...");
                try {
                    ServerController.startApache();
                } catch (Exception ex) {
                    appendLog("apache start error: " + ex.getMessage());
                }
                boolean ok = false;
                for (int i = 0; i < 20; i++) {
                    try { if (ServerController.isServiceActive("apache2")) { ok = true; break; } } catch (Exception ignored) {}
                    try { Thread.sleep(500); } catch (InterruptedException ignored) {}
                }
                appendLog(ok ? "apache started" : "apache start timed out");
                updateStatus();
            }).start();
        });

        apacheOffButton.addActionListener((ActionEvent e) -> {
            new Thread(() -> {
                appendLog("Stopping apache...");
                try {
                    ServerController.stopApache();
                } catch (Exception ex) {
                    appendLog("apache stop error: " + ex.getMessage());
                }
                boolean ok = false;
                for (int i = 0; i < 20; i++) {
                    try { if (!ServerController.isServiceActive("apache2")) { ok = true; break; } } catch (Exception ignored) {}
                    try { Thread.sleep(500); } catch (InterruptedException ignored) {}
                }
                appendLog(ok ? "apache stopped" : "apache stop timed out");
                updateStatus();
            }).start();
        });

        mysqlOnButton.addActionListener((ActionEvent e) -> {
            new Thread(() -> {
                appendLog("Starting mysql/mariadb...");
                try {
                    ServerController.startMysql();
                } catch (Exception ex) {
                    appendLog("mysql start error: " + ex.getMessage());
                }
                boolean ok = false;
                for (int i = 0; i < 20; i++) {
                    try { if (ServerController.isServiceActive("mysql") || ServerController.isServiceActive("mariadb") || ServerController.isServiceActive("mysqld")) { ok = true; break; } } catch (Exception ignored) {}
                    try { Thread.sleep(500); } catch (InterruptedException ignored) {}
                }
                appendLog(ok ? "mysql started" : "mysql start timed out");
                updateStatus();
            }).start();
        });

        mysqlOffButton.addActionListener((ActionEvent e) -> {
            new Thread(() -> {
                appendLog("Stopping mysql/mariadb...");
                try {
                    ServerController.stopMysql();
                } catch (Exception ex) {
                    appendLog("mysql stop error: " + ex.getMessage());
                }
                boolean ok = false;
                for (int i = 0; i < 20; i++) {
                    try { if (!ServerController.isServiceActive("mysql") && !ServerController.isServiceActive("mariadb") && !ServerController.isServiceActive("mysqld")) { ok = true; break; } } catch (Exception ignored) {}
                    try { Thread.sleep(500); } catch (InterruptedException ignored) {}
                }
                appendLog(ok ? "mysql stopped" : "mysql stop timed out");
                updateStatus();
            }).start();
        });

        ngrokOnButton.addActionListener((ActionEvent e) -> {
            new Thread(() -> {
                try {
                    if (ServerController.isNgrokRunning()) {
                        appendLog("ngrok already running");
                        updateStatus();
                        return;
                    }
                } catch (Exception ignored) {}
                // if the installed ngrok is unsupported, warn the user and allow override
                try {
                    String ver = null;
                    try { ver = ServerController.getNgrokVersion(); } catch (Exception ignore) {}
                    if (ver == null || !ServerController.isNgrokSupported(ver)) {
                        String msg = (ver==null?"No ngrok binary found or version unknown.":"Installed ngrok v"+ver+" is unsupported (requires v3.20.0+).") + " Starting may fail. Continue?";
                        int conf = JOptionPane.showConfirmDialog(frame, msg, "Start ngrok anyway?", JOptionPane.YES_NO_OPTION, JOptionPane.WARNING_MESSAGE);
                        if (conf != JOptionPane.YES_OPTION) {
                            appendLog("Start cancelled by user (unsupported ngrok version)");
                            updateStatus();
                            return;
                        } else {
                            appendLog("User confirmed start despite unsupported ngrok version: " + (ver==null?"unknown":ver));
                        }
                    }
                } catch (Exception ignore) {}

                appendLog("Starting ngrok (via systemd)...");
                boolean attemptedSystemd = false;
                try {
                    // try system unit first (requires sudo but may succeed)
                    int exit = runShellCommandExit("sudo -n systemctl start ngrok.service 2>&1");
                    if (exit == 0) {
                        attemptedSystemd = true;
                    } else {
                        appendLog("systemd(system) start returned exit " + exit);
                    }
                } catch (Exception se) {
                    appendLog("systemd(system) start failed: " + se.getMessage());
                }
                if (!attemptedSystemd) {
                    try {
                        int exit = runShellCommandExit("systemctl --user start ngrok.service 2>&1");
                        if (exit == 0) {
                            attemptedSystemd = true;
                        } else {
                            appendLog("systemd(user) start returned exit " + exit);
                        }
                    } catch (Exception se) {
                        appendLog("systemd(user) start failed: " + se.getMessage());
                    }
                }

                if (!attemptedSystemd) {
                    try {
                        ServerController.startNgrok();
                    } catch (Exception ex) {
                        appendLog("ngrok start error: " + ex.getMessage());
                    }
                }

                boolean ok = false;
                for (int i = 0; i < 20; i++) {
                    try { if (ServerController.isNgrokRunning()) { ok = true; break; } } catch (Exception ignored) {}
                    try { Thread.sleep(500); } catch (InterruptedException ignored) {}
                }
                appendLog(ok ? "ngrok started" : "ngrok start timed out");
                updateStatus();
            }).start();
        });

        ngrokOffButton.addActionListener((ActionEvent e) -> {
            new Thread(() -> {
                try {
                    if (!ServerController.isNgrokRunning()) {
                        appendLog("ngrok not running");
                        updateStatus();
                        return;
                    }
                } catch (Exception ignored) {}
                appendLog("Stopping ngrok (via systemd)...");
                boolean attemptedSystemd = false;
                try {
                    int exit = runShellCommandExit("sudo -n systemctl stop ngrok.service 2>&1");
                    if (exit == 0) {
                        attemptedSystemd = true;
                    } else {
                        appendLog("systemd(system) stop returned exit " + exit);
                    }
                } catch (Exception se) {
                    appendLog("systemd(system) stop failed: " + se.getMessage());
                }
                if (!attemptedSystemd) {
                    try {
                        int exit = runShellCommandExit("systemctl --user stop ngrok.service 2>&1");
                        if (exit == 0) {
                            attemptedSystemd = true;
                        } else {
                            appendLog("systemd(user) stop returned exit " + exit);
                        }
                    } catch (Exception se) {
                        appendLog("systemd(user) stop failed: " + se.getMessage());
                    }
                }

                if (!attemptedSystemd) {
                    try {
                        ServerController.stopNgrok();
                    } catch (Exception ex) {
                        appendLog("ngrok stop error: " + ex.getMessage());
                    }
                }

                boolean ok = false;
                for (int i = 0; i < 20; i++) {
                    try { if (!ServerController.isNgrokRunning()) { ok = true; break; } } catch (Exception ignored) {}
                    try { Thread.sleep(500); } catch (InterruptedException ignored) {}
                }
                appendLog(ok ? "ngrok stopped" : "ngrok stop timed out");
                updateStatus();
            }).start();
        });

        ngrokRestartButton.addActionListener((ActionEvent e) -> {
            new Thread(() -> {
                appendLog("Restarting ngrok...");
                SwingUtilities.invokeLater(() -> {
                    ngrokRestartButton.setEnabled(false);
                    statusLabel.setText("status: restarting ngrok...");
                });
                try {
                    ServerController.restartNgrok();
                } catch (Exception ex) {
                    appendLog("ngrok restart error: " + ex.getMessage());
                }
                boolean ok = false;
                for (int i = 0; i < 40; i++) { // allow more time for stop+start
                    try { if (ServerController.isNgrokRunning()) { ok = true; break; } } catch (Exception ignored) {}
                    try { Thread.sleep(500); } catch (InterruptedException ignored) {}
                }
                appendLog(ok ? "ngrok running after restart" : "ngrok restart timed out");
                SwingUtilities.invokeLater(() -> {
                    ngrokRestartButton.setEnabled(true);
                });
                updateStatus();
            }).start();
        });

        ngrokTestButton.addActionListener((ActionEvent e) -> {
            new Thread(() -> {
                String url = ServerController.getNgrokTunnelUrl();
                if (url == null) {
                    appendLog("ngrok tunnel not detected (no URL)");
                    return;
                }
                appendLog("Testing tunnel: " + url);
                try {
                    java.net.URL u = new java.net.URL(url);
                    java.net.HttpURLConnection conn = (java.net.HttpURLConnection) u.openConnection();
                    conn.setConnectTimeout(5000);
                    conn.setReadTimeout(5000);
                    conn.setInstanceFollowRedirects(true);
                    conn.setRequestMethod("HEAD");
                    int code = conn.getResponseCode();
                    appendLog("Test complete: HTTP " + code + " (" + conn.getResponseMessage() + ")");
                } catch (Exception ex) {
                    appendLog("Test failed: " + ex.getMessage());
                }
            }).start();
        });

        // settings button removed from compact UI; use command-line for advanced ops

        // update status every couple of seconds
        Timer timer = new Timer(2000, e -> updateStatus());
        timer.start();

        frame.setVisible(true);
        updateStatus();
    }

    private static void showSetupWizard() {
        JDialog dlg = new JDialog(frame, "Quick Setup Wizard", true);
        dlg.setSize(520, 360);
        JPanel cards = new JPanel(new CardLayout());

        // Page 1: Preflight
        JPanel p1 = new JPanel(new BorderLayout());
        JTextArea pre = new JTextArea();
        pre.setEditable(false);
        pre.setFont(new Font("Monospaced", Font.PLAIN, 12));
        p1.add(new JLabel("Preflight checks:"), BorderLayout.NORTH);
        p1.add(new JScrollPane(pre), BorderLayout.CENTER);

        // Page 2: ngrok
        JPanel ngrokCard = new JPanel(new BorderLayout(8,8));
        // ngrok section uses three rows: label, token field, and button row
        JPanel ngp = new JPanel(new GridLayout(3,1,6,6));
        JTextField token = new JTextField();
        JButton testToken = new JButton("Test token & save");
        JButton createUnit = new JButton("Create systemd unit");
        ngp.add(new JLabel("Paste your ngrok authtoken:"));
        ngp.add(token);
        JPanel ngbtns = new JPanel(new FlowLayout(FlowLayout.LEFT));
        // token operations
        ngbtns.add(testToken);
        JButton pasteLoad = new JButton("Paste/Load token");
        ngbtns.add(pasteLoad);
        // manage ngrok binary actions and create‑unit on same row
        JButton manageNgrok = new JButton("Manage ngrok");
        ngbtns.add(manageNgrok);
        ngbtns.add(createUnit);
        ngp.add(ngbtns);
        ngrokCard.add(ngp, BorderLayout.CENTER);

        // Page 3: Finish
        JPanel p3 = new JPanel(new BorderLayout());
        p3.add(new JLabel("Finish setup and save configuration."), BorderLayout.CENTER);

        cards.add(p1, "pre");
        cards.add(ngrokCard, "ngrok");
        cards.add(p3, "finish");

        JPanel nav = new JPanel(new FlowLayout(FlowLayout.RIGHT));
        JButton back = new JButton("Back");
        JButton next = new JButton("Next");
        JButton cancel = new JButton("Cancel");
        nav.add(back); nav.add(next); nav.add(cancel);

        dlg.setLayout(new BorderLayout());
        dlg.add(cards, BorderLayout.CENTER);
        dlg.add(nav, BorderLayout.SOUTH);

        // fill preflight
        new Thread(() -> {
            StringBuilder sb = new StringBuilder();
            try {
                sb.append("Java: ok\n");
            } catch (Exception e) { sb.append("Java: unknown\n"); }
            try { Process p = new ProcessBuilder("bash","-c","which ngrok || true").start(); p.waitFor(2, java.util.concurrent.TimeUnit.SECONDS); java.io.BufferedReader r = new java.io.BufferedReader(new java.io.InputStreamReader(p.getInputStream())); String line = r.readLine(); sb.append("ngrok: ").append(line==null?"not found":line).append('\n'); } catch (Exception ignored) {}
            try { Process p = new ProcessBuilder("bash","-c","systemctl is-active apache2 || systemctl is-active httpd || true").start(); p.waitFor(2, java.util.concurrent.TimeUnit.SECONDS); java.io.BufferedReader r = new java.io.BufferedReader(new java.io.InputStreamReader(p.getInputStream())); String out = r.readLine(); sb.append("Apache: ").append(out==null?"unknown":out).append('\n'); } catch (Exception ignored) {}
            try { Process p = new ProcessBuilder("bash","-c","systemctl is-active php-fpm.service || true").start(); p.waitFor(2, java.util.concurrent.TimeUnit.SECONDS); java.io.BufferedReader r = new java.io.BufferedReader(new java.io.InputStreamReader(p.getInputStream())); String out = r.readLine(); sb.append("PHP-FPM: ").append(out==null?"unknown":out).append('\n'); } catch (Exception ignored) {}
            SwingUtilities.invokeLater(() -> pre.setText(sb.toString()));
        }).start();

        // Paste/load token combined
        pasteLoad.addActionListener(ae -> {
            // ask user to paste or select file
            String[] opts = {"Paste from clipboard", "Load from file", "Cancel"};
            int ch = JOptionPane.showOptionDialog(frame, "Token source:", "Token input", JOptionPane.DEFAULT_OPTION, JOptionPane.QUESTION_MESSAGE, null, opts, opts[0]);
            if (ch == 0) {
                // paste
                try {
                    java.awt.datatransfer.Clipboard cb = java.awt.Toolkit.getDefaultToolkit().getSystemClipboard();
                    if (cb.isDataFlavorAvailable(java.awt.datatransfer.DataFlavor.stringFlavor)) {
                        String clip = (String) cb.getData(java.awt.datatransfer.DataFlavor.stringFlavor);
                        if (clip != null) token.setText(clip.trim());
                    }
                } catch (Exception e) {
                    appendLog("Paste failed: " + e.getMessage());
                }
            } else if (ch == 1) {
                JFileChooser chooser2 = new JFileChooser();
                int ret2 = chooser2.showOpenDialog(frame);
                if (ret2 == JFileChooser.APPROVE_OPTION) {
                    try {
                        java.nio.file.Path p = chooser2.getSelectedFile().toPath();
                        java.util.List<String> lines = Files.readAllLines(p);
                        if (!lines.isEmpty()) token.setText(lines.get(0).trim());
                    } catch (Exception ex) {
                        appendLog("Load token file failed: " + ex.getMessage());
                    }
                }
            }
        });

        // manageNgrok button provides all binary-related operations
        manageNgrok.addActionListener(ae -> {
            String[] choices = {"Install", "Update", "Cancel"};
            int c = JOptionPane.showOptionDialog(frame, "Choose ngrok action:", "Manage ngrok", JOptionPane.DEFAULT_OPTION, JOptionPane.QUESTION_MESSAGE, null, choices, choices[0]);
            switch (c) {
                case 0: // install
                    installNgrokAction();
                    break;
                case 1: // update
                    updateNgrokAction();
                    break;
                default:
                    break;
            }
        });



        createUnit.addActionListener(ae -> {
            new Thread(() -> {
                try {
                    Path cfgDir = Paths.get(System.getProperty("user.home"), ".servercontroller");
                    Files.createDirectories(cfgDir);
                    java.util.Properties cfg = new java.util.Properties();
                    try (java.io.InputStream in = Files.exists(cfgDir.resolve("config.properties")) ? Files.newInputStream(cfgDir.resolve("config.properties")) : null) {
                        if (in != null) cfg.load(in);
                    } catch (Exception ignore) {}
                    String tokenVal = cfg.getProperty("ngrok.authtoken", "");
                    String ngrokExec = cfg.getProperty("ngrok.path", System.getProperty("user.home") + "/.local/bin/ngrok");
                    if (!Files.exists(Paths.get(ngrokExec))) {
                        // try PATH
                        try {
                            Process p = new ProcessBuilder("bash","-c","command -v ngrok || true").start(); p.waitFor(2, TimeUnit.SECONDS);
                            try (BufferedReader r = new BufferedReader(new InputStreamReader(p.getInputStream()))) { String line = r.readLine(); if (line != null && !line.isBlank()) ngrokExec = line.trim(); }
                        } catch (Exception ignore) {}
                    }
                    String[] options = {"user", "system"};
                    String choice = (String) JOptionPane.showInputDialog(frame, "Create systemd unit as:", "Create unit", JOptionPane.QUESTION_MESSAGE, null, options, options[0]);
                    if (choice == null) { appendLog("Create unit cancelled"); return; }
                    String unit = "[Unit]\nDescription=ngrok tunnel\nAfter=network.target\n\n[Service]\nExecStart=" + ngrokExec + " http 127.0.0.1:8000" + (tokenVal.isBlank() ? "" : " --authtoken=" + tokenVal) + "\nRestart=on-failure\n\n[Install]\nWantedBy=multi-user.target\n";
                    if (choice.equals("system")) {
                        Path tmp = cfgDir.resolve("ngrok.service.tmp");
                        Files.writeString(tmp, unit);
                        int exit = runShellCommandExit("sudo mv " + tmp.toString() + " /etc/systemd/system/ngrok.service && sudo systemctl daemon-reload && sudo systemctl enable --now ngrok.service");
                        appendLog("system unit create exit=" + exit);
                    } else {
                        Path userUnitDir = Paths.get(System.getProperty("user.home"), ".config", "systemd", "user");
                        Files.createDirectories(userUnitDir);
                        Path unitPath = userUnitDir.resolve("ngrok.service");
                        Files.writeString(unitPath, unit);
                        int exit = runShellCommandExit("systemctl --user daemon-reload && systemctl --user enable --now ngrok.service");
                        appendLog("user unit create exit=" + exit + ", unit written to " + unitPath.toString());
                    }
                } catch (Exception ex) {
                    appendLog("Create unit error: " + ex.getMessage());
                }
            }).start();
        });






        testToken.addActionListener(ae -> {
            String t = token.getText().trim();
            if (t.isBlank()) { appendLog("No token provided"); return; }
            try {
                Path cfgDir = Paths.get(System.getProperty("user.home"), ".servercontroller");
                Files.createDirectories(cfgDir);
                java.util.Properties props = new java.util.Properties();
                props.setProperty("ngrok.authtoken", t);
                try (java.io.OutputStream out = Files.newOutputStream(cfgDir.resolve("config.properties"))) { props.store(out, "ServerController configuration"); }
                // try to set strict permissions
                try {
                    java.util.Set<java.nio.file.attribute.PosixFilePermission> perms = java.util.EnumSet.of(
                        java.nio.file.attribute.PosixFilePermission.OWNER_READ,
                        java.nio.file.attribute.PosixFilePermission.OWNER_WRITE
                    );
                    Files.setPosixFilePermissions(cfgDir.resolve("config.properties"), perms);
                } catch (Exception ignorePerm) {
                    // ignore on non-posix filesystems
                }
                appendLog("Saved ngrok token to " + cfgDir.resolve("config.properties"));

                // determine ngrok executable
                String ngrokExec = null;
                try {
                    java.util.Properties cfg = new java.util.Properties();
                    try (java.io.InputStream in = Files.newInputStream(cfgDir.resolve("config.properties"))) { cfg.load(in); }
                    String cfgPath = cfg.getProperty("ngrok.path");
                    if (cfgPath != null && !cfgPath.isBlank()) ngrokExec = cfgPath;
                } catch (Exception ignore) {}
                if (ngrokExec == null) {
                    Path userLocal = Paths.get(System.getProperty("user.home"), ".local", "bin", "ngrok");
                    if (Files.exists(userLocal) && Files.isExecutable(userLocal)) ngrokExec = userLocal.toString();
                }
                if (ngrokExec == null) {
                    // try PATH
                    try {
                        Process p = new ProcessBuilder("bash","-c","command -v ngrok || true").start();
                        p.waitFor(2, java.util.concurrent.TimeUnit.SECONDS);
                        try (java.io.BufferedReader r = new java.io.BufferedReader(new java.io.InputStreamReader(p.getInputStream()))) {
                            String line = r.readLine(); if (line != null && !line.isBlank()) ngrokExec = line.trim();
                        }
                    } catch (Exception ignored) {}
                }

                if (ngrokExec != null) {
                    appendLog("Attempting to run: " + ngrokExec + " authtoken <token>");
                    int exit = runShellCommandExit(ngrokExec + " authtoken " + t + " 2>&1");
                    if (exit == 0) {
                        appendLog("ngrok authtoken registered successfully");
                        int vexit = runShellCommandExit(ngrokExec + " version 2>&1");
                        appendLog("ngrok version exit=" + vexit);
                    } else {
                        appendLog("ngrok authtoken command returned exit " + exit + ", token saved to config but not applied to ngrok binary");
                    }
                } else {
                    appendLog("ngrok binary not found in PATH or " + System.getProperty("user.home") + "/.local/bin; token saved to config. Install ngrok to finalize (see Tools -> install). ");
                }
            } catch (Exception ex) {
                appendLog("Save token failed: " + ex.getMessage());
            }
        });

        CardLayout cl = (CardLayout) cards.getLayout();
        final String[] seq = new String[] {"pre","ngrok","finish"};
        final int[] idx = {0};

        // helper to refresh navigation state
        Runnable refreshNav = () -> {
            SwingUtilities.invokeLater(() -> {
                back.setEnabled(idx[0] > 0);
                if (idx[0] < seq.length - 1) {
                    next.setText("Next");
                } else {
                    next.setText("Finish");
                }
                cl.show(cards, seq[idx[0]]);
            });
        };

        back.addActionListener(ae -> {
            if (idx[0] > 0) {
                idx[0]--;
                refreshNav.run();
            }
        });

        next.addActionListener(ae -> {
            if (idx[0] < seq.length - 1) {
                idx[0]++;
                refreshNav.run();
                return;
            }
            // Finish action: save any token in the field to config and close dialog
            String t = token.getText().trim();
            try {
                Path cfgDir = Paths.get(System.getProperty("user.home"), ".servercontroller");
                Files.createDirectories(cfgDir);
                java.util.Properties props = new java.util.Properties();
                // merge existing config if present
                Path cfgFile = cfgDir.resolve("config.properties");
                if (Files.exists(cfgFile)) {
                    try (java.io.InputStream in = Files.newInputStream(cfgFile)) { props.load(in); }
                }
                if (!t.isBlank()) props.setProperty("ngrok.authtoken", t);
                try (java.io.OutputStream out = Files.newOutputStream(cfgFile)) { props.store(out, "ServerController configuration"); }
                // try to set strict permissions
                try {
                    java.util.Set<java.nio.file.attribute.PosixFilePermission> perms = java.util.EnumSet.of(
                        java.nio.file.attribute.PosixFilePermission.OWNER_READ,
                        java.nio.file.attribute.PosixFilePermission.OWNER_WRITE
                    );
                    Files.setPosixFilePermissions(cfgFile, perms);
                } catch (Exception ignorePerm) {}
                appendLog("Setup finished — configuration saved to " + cfgFile.toString());
            } catch (Exception ex) {
                appendLog("Finish save failed: " + ex.getMessage());
            }
            dlg.dispose();
        });

        cancel.addActionListener(ae -> dlg.dispose());

        // show initial card state
        refreshNav.run();

        dlg.setLocationRelativeTo(frame);
        dlg.setVisible(true);
    }

    private static void showSettingsDialog() {
        JDialog dlg = new JDialog(frame, "Settings", true);
        dlg.setSize(400, 260);
        dlg.setLayout(new BorderLayout());

        JPanel p = new JPanel(new GridLayout(7, 2, 6, 6));
        p.setBorder(BorderFactory.createEmptyBorder(10,10,10,10));

        p.add(new JLabel("VNC password:"));
        JPasswordField vncPass = new JPasswordField();
        p.add(vncPass);

        p.add(new JLabel("Confirm VNC password:"));
        JPasswordField vncPass2 = new JPasswordField();
        p.add(vncPass2);

        p.add(new JLabel("Root password:"));
        JPasswordField rootPass = new JPasswordField();
        p.add(rootPass);

        p.add(new JLabel("Confirm Root password:"));
        JPasswordField rootPass2 = new JPasswordField();
        p.add(rootPass2);

        p.add(new JLabel("ngrok domain (www.example.com):"));
        JTextField ngrokDomain = new JTextField();
        p.add(ngrokDomain);

        p.add(new JLabel("ngrok local port (default 80):"));
        JTextField ngrokPort = new JTextField("80");
        p.add(ngrokPort);

        p.add(new JLabel("ngrok path (optional):"));
        JTextField ngrokPath = new JTextField(System.getProperty("user.home") + "/.local/bin/ngrok");
        p.add(ngrokPath);

        // pre-load config values if present
        try {
            java.util.Properties cfg = ServerController.getConfig();
            if (cfg.containsKey("ngrok.domain")) ngrokDomain.setText(cfg.getProperty("ngrok.domain"));
            if (cfg.containsKey("ngrok.port")) ngrokPort.setText(cfg.getProperty("ngrok.port"));
            if (cfg.containsKey("ngrok.path")) ngrokPath.setText(cfg.getProperty("ngrok.path"));
        } catch (Exception ignore) {
        }

        JPanel btns = new JPanel();
        JButton applyVnc = new JButton("Apply VNC");
        JButton applyRoot = new JButton("Apply Root");
        btns.add(applyVnc);
        btns.add(applyRoot);

        dlg.add(p, BorderLayout.CENTER);
        dlg.add(btns, BorderLayout.SOUTH);

        applyVnc.addActionListener(ae -> {
            String p1 = new String(vncPass.getPassword());
            String p2 = new String(vncPass2.getPassword());
            if (!p1.equals(p2) || p1.isBlank()) {
                appendLog("VNC passwords do not match or empty");
                return;
            }
            new Thread(() -> applyVncPassword(p1)).start();
        });

        applyRoot.addActionListener(ae -> {
            String r1 = new String(rootPass.getPassword());
            String r2 = new String(rootPass2.getPassword());
            if (!r1.equals(r2) || r1.isBlank()) {
                appendLog("Root passwords do not match or empty");
                return;
            }
            int confirm = JOptionPane.showConfirmDialog(frame, "Setting the root password is privileged and can be risky. Continue?", "Confirm", JOptionPane.YES_NO_OPTION);
            if (confirm == JOptionPane.YES_OPTION) {
                new Thread(() -> applyRootPassword(r1)).start();
            }
        });

        // Save ngrok path to config
        JButton saveConfig = new JButton("Save Config");
        saveConfig.addActionListener(ae -> {
            try {
                java.util.Properties props = new java.util.Properties();
                props.setProperty("ngrok.path", ngrokPath.getText().trim());
                props.setProperty("ngrok.domain", ngrokDomain.getText().trim());
                props.setProperty("ngrok.port", ngrokPort.getText().trim());
                Path cfgDir = Paths.get(System.getProperty("user.home"), ".servercontroller");
                Files.createDirectories(cfgDir);
                try (java.io.OutputStream out = Files.newOutputStream(cfgDir.resolve("config.properties"))) {
                    props.store(out, "ServerController configuration");
                }
                appendLog("Saved configuration to " + cfgDir.resolve("config.properties"));
            } catch (Exception e) {
                appendLog("Config save error: " + e.getMessage());
            }
        });
        btns.add(saveConfig);

        dlg.setLocationRelativeTo(frame);
        dlg.setVisible(true);
    }

    private static void applyVncPassword(String pass) {
        appendLog("Applying VNC password...");
        String passfile = System.getProperty("user.home") + "/.vnc/passwd";
        try {
            // Use printf to provide password to x11vnc -storepasswd
            String cmd = "bash -c \"printf '%s\\n' '" + escapeShell(pass) + "' | x11vnc -storepasswd - '" + passfile + "'\"";
            runShellCommand(cmd);
            appendLog("VNC password stored to " + passfile);
        } catch (Exception e) {
            appendLog("VNC set error: " + e.getMessage());
        }
    }

    private static void applyRootPassword(String pass) {
        appendLog("Applying root password (requires sudo/chpasswd permission)...");
        try {
            String safe = escapeShell(pass);
            String cmd = "bash -c \"echo 'root:" + safe + "' | sudo chpasswd\"";
            runShellCommand(cmd);
            appendLog("Root password set (if sudoers allows chpasswd)");
        } catch (Exception e) {
            appendLog("Root set error: " + e.getMessage());
        }
    }

    private static void runShellCommand(String cmd) throws IOException, InterruptedException {
        ProcessBuilder pb = new ProcessBuilder("bash", "-c", cmd);
        Process p = pb.start();
        try (BufferedReader r = new BufferedReader(new InputStreamReader(p.getInputStream()))) {
            String line;
            while ((line = r.readLine()) != null) appendLog(line);
        }
        p.waitFor(10, TimeUnit.SECONDS);
    }

    // Run a shell command, stream output to the UI, and return the command exit code.
    private static int runShellCommandExit(String cmd) throws IOException, InterruptedException {
        ProcessBuilder pb = new ProcessBuilder("bash", "-c", cmd);
        Process p = pb.start();
        try (BufferedReader r = new BufferedReader(new InputStreamReader(p.getInputStream()))) {
            String line;
            while ((line = r.readLine()) != null) appendLog(line);
        }
        // wait longer for systemctl calls
        p.waitFor(15, TimeUnit.SECONDS);
        return p.exitValue();
    }

    // helpers invoked by manageNgrok
    private static void installNgrokAction() {
        new Thread(() -> {
            try {
                // see if a binary is already present and report
                String existing = null;
                try {
                    Process pv = new ProcessBuilder("bash", "-c", "command -v ngrok || true").start();
                    if (pv.waitFor(2, TimeUnit.SECONDS) && pv.exitValue() == 0) {
                        try (BufferedReader r = new BufferedReader(new InputStreamReader(pv.getInputStream()))) {
                            existing = r.readLine();
                        }
                    }
                } catch (Exception ignore) {}
                if (existing != null && !existing.isBlank()) {
                    appendLog("ngrok already installed at " + existing);
                    String lv = null;
                    try {
                        Process v = new ProcessBuilder(existing, "version").start();
                        BufferedReader vr = new BufferedReader(new InputStreamReader(v.getInputStream()));
                        lv = vr.readLine();
                        if (lv != null) appendLog("current version: " + lv);
                        v.waitFor(3, TimeUnit.SECONDS);
                    } catch (Exception ignore) {}
                    String msg = "ngrok already installed at " + existing;
                    if (lv != null) msg += "\nversion: " + lv;
                    msg += "\ninstallation skipped.";
                    JOptionPane.showMessageDialog(frame, msg, "ngrok installation", JOptionPane.INFORMATION_MESSAGE);
                    appendLog("installation skipped");
                    return;
                }

                appendLog("Installing ngrok to $HOME/.local/bin...");
                String arch = System.getProperty("os.arch");
                String url = null;
                if (arch.equals("amd64") || arch.equals("x86_64")) {
                    url = "https://bin.equinox.io/c/4VmDzA7iaHb/ngrok-stable-linux-amd64.zip";
                } else {
                    appendLog("Unsupported CPU arch for auto-download: " + arch + " — please install ngrok manually.");
                }
                if (url != null) {
                    String cmd = "bash -c 'mkdir -p $HOME/.local/bin && cd /tmp && curl -fsSL -o ngrok.zip " + url + " && unzip -o ngrok.zip -d $HOME/.local/bin && chmod +x $HOME/.local/bin/ngrok && rm -f /tmp/ngrok.zip'";
                    int exit = runShellCommandExit(cmd);
                    appendLog("ngrok install exit=" + exit);
                    if (exit == 0) appendLog("ngrok installed to $HOME/.local/bin/ngrok");
                }
            } catch (Exception ex) {
                appendLog("ngrok install error: " + ex.getMessage());
            }
        }).start();
    }

    private static void updateNgrokAction() {
        new Thread(() -> {
            int choice = JOptionPane.showOptionDialog(frame,
                    "Update ngrok to the latest supported version.",
                    "Update ngrok",
                    JOptionPane.DEFAULT_OPTION,
                    JOptionPane.QUESTION_MESSAGE,
                    null,
                    new Object[]{"Proceed", "Cancel"},
                    "Proceed");
            if (choice != 0) {
                appendLog("Update cancelled by user");
                return;
            }

            appendLog("Starting automatic ngrok update (checking version first)");

            String ngrokExec = "ngrok";
            try {
                Process p = new ProcessBuilder("bash","-c","command -v ngrok || true").start();
                if (p.waitFor(2, TimeUnit.SECONDS) && p.exitValue() == 0) {
                    try (BufferedReader r = new BufferedReader(new InputStreamReader(p.getInputStream()))) {
                        String line = r.readLine();
                        if (line != null && !line.isBlank()) ngrokExec = line.trim();
                    }
                }
            } catch (Exception ignore) {}

            String cur = null;
            try {
                Process pv = new ProcessBuilder(ngrokExec, "version").start();
                BufferedReader r = new BufferedReader(new InputStreamReader(pv.getInputStream()));
                String l = r.readLine();
                if (l != null) {
                    java.util.regex.Matcher m = java.util.regex.Pattern.compile("([0-9]+\\.[0-9]+(\\.[0-9]+)?)").matcher(l);
                    if (m.find()) cur = m.group(1);
                }
                pv.waitFor(3, TimeUnit.SECONDS);
            } catch (Exception ignore) {}
            appendLog("current version: " + (cur==null?"<none>":cur));

            boolean didUpdate = false;
            boolean curIsV3 = (cur != null && cur.startsWith("3"));
            if (curIsV3) {
                try {
                    appendLog("running '"+ngrokExec+" update'");
                    int r = runShellCommandExit(ngrokExec + " update");
                    appendLog("ngrok update exit="+r);
                    didUpdate = (r == 0);
                } catch (Exception e) {
                    appendLog("built-in update failed: " + e.getMessage());
                }
            }

            if (!didUpdate) {
                appendLog("falling back to download-based update");
                String curLine = curIsV3 ? "CURVER="+cur : "";
                String script = String.join("\n",
                    "set -euo pipefail",
                    "mkdir -p $HOME/.local/bin/backups || true",
                    "TMP=$(mktemp -d) && cd $TMP || exit 1",
                    "CAND='https://bin.equinox.io/c/4VmDzA7iaHb/ngrok-v3-stable-linux-amd64.tgz'",
                    "# perform up to three download attempts to avoid CDN mis-served v2 archives",
                    "for i in 1 2 3; do",
                    "  echo 'download attempt ' $i",
                    "  curl -fsSL -o ngrok_dl $CAND || { echo download failed; exit 2; }",
                    "  file ngrok_dl || true",
                    "  mkdir -p out && tar -xzf ngrok_dl -C out || true",
                    "  NG=$(find out -type f -name ngrok -perm /u+x -print -quit || true)",
                    "  [ -z \"$NG\" ] && { echo no binary found; exit 3; }",
                    "  CANDVER=$($NG version 2>&1 | grep -oE '([0-9]+\\.[0-9]+(\\.[0-9]+)?)')",
                    "  echo candidate: $CANDVER",
                    "  if echo \"$CANDVER\" | grep -qE '^3\\.'; then",
                    "     break",
                    "  else",
                    "     echo wrong version, retrying...",
                    "     rm -rf ngrok_dl out/*",
                    "     continue",
                    "  fi",
                    "done",
                    curLine,
                    "if [ -n \"$CURVER\" ] && [ \"$CANDVER\" = \"$CURVER\" ]; then",
                    "  echo already up-to-date",
                    "  exit 0",
                    "fi",
                    "if ! echo \"$CANDVER\" | grep -qE '^3\\.'; then",
                    "  echo candidate not v3 after retries && exit 4",
                    "fi",
                    "mv -v $NG $HOME/.local/bin/ngrok && chmod +x $HOME/.local/bin/ngrok",
                    "echo Installed: $HOME/.local/bin/ngrok version || true",
                    "rm -rf $TMP"
                );
                try {
                    int ex = runShellCommandExit(script);
                    appendLog("update script exit=" + ex);
                    if (ex == 4) {
                        JOptionPane.showMessageDialog(frame,
                            "Download-based update repeatedly fetched an incorrect (v2) binary.\n" +
                            "Please visit https://ngrok.com/download and install manually, or try again later.",
                            "ngrok update failed", JOptionPane.WARNING_MESSAGE);
                    }
                    if (ex == 0 && !curIsV3) {
                        JOptionPane.showMessageDialog(frame,
                            "Your installed ngrok version appears to be v2.x ("+cur+").\n" +
                            "Automatic updating cannot upgrade a v2 installation.\n" +
                            "Please download and install ngrok v3 manually (see log for instructions).",
                            "ngrok not upgraded", JOptionPane.INFORMATION_MESSAGE);
                        appendLog("Update returned success but current version remains v2");
                    }
                } catch (Exception ex) {
                    appendLog("update script threw exception: " + ex.getMessage());
                }
            }
        }).start();
    }

    private static void uploadNgrokAction() {
        new Thread(() -> {
            try {
                JFileChooser chooser = new JFileChooser();
                chooser.setDialogTitle("Select ngrok v3 binary or archive");
                int ret = chooser.showOpenDialog(frame);
                if (ret != JFileChooser.APPROVE_OPTION) {
                    appendLog("Upload cancelled");
                    return;
                }
                String sel = chooser.getSelectedFile().getAbsolutePath();
                // run shell script to handle extraction/validation/install
                String script = String.join("\n",
                    "set -euo pipefail",
                    "SEL=\"" + sel.replace("\"","\\\"") + "\"",
                    "TMP=$(mktemp -d)",
                    "cd $TMP",
                    "if [[ $SEL == *.zip ]]; then unzip -q $SEL; else tar -xzf $SEL; fi",
                    "NG=$(find . -type f -name ngrok -perm /u+x -print -quit || true)",
                    "if [ -z \"$NG\" ]; then echo 'no executable' >&2; exit 1; fi",
                    "VER=$($NG version 2>&1 || true)",
                    "echo $VER",
                    "if ! echo \"$VER\" | grep -qE '^v?3\\.'; then echo 'not v3' >&2; exit 2; fi",
                    "mkdir -p $HOME/.local/bin/backups || true",
                    "[ -x $HOME/.local/bin/ngrok ] && cp -v $HOME/.local/bin/ngrok $HOME/.local/bin/backups/ngrok.bak.$(date +%s) || true",
                    "mv -v $NG $HOME/.local/bin/ngrok",
                    "chmod +x $HOME/.local/bin/ngrok",
                    "echo 'installed:' && $HOME/.local/bin/ngrok version"
                );
                int exit = runShellCommandExit(script);
                appendLog("upload script exit=" + exit);
            } catch (Exception ex) {
                appendLog("Upload/install failed: " + ex.getMessage());
            }
            updateStatus();
        }).start();
    }

    // Return true if a systemd unit with the given name exists on this system.
    // Return "user" if a user-level unit exists, "system" if a system-level unit exists, otherwise "none".
    private static String getUnitScope(String unit) {
        try {
            ProcessBuilder pbUser = new ProcessBuilder("bash", "-c", "systemctl --user list-unit-files " + unit + " --no-pager --no-legend");
            Process pu = pbUser.start();
            if (pu.waitFor(2, TimeUnit.SECONDS) && pu.exitValue() == 0) {
                try (BufferedReader r = new BufferedReader(new InputStreamReader(pu.getInputStream()))) {
                    String line = r.readLine();
                    if (line != null && !line.isBlank()) return "user";
                }
            }
            ProcessBuilder pb = new ProcessBuilder("bash", "-c", "systemctl list-unit-files " + unit + " --no-pager --no-legend");
            Process p = pb.start();
            if (p.waitFor(3, TimeUnit.SECONDS) && p.exitValue() == 0) {
                try (BufferedReader r = new BufferedReader(new InputStreamReader(p.getInputStream()))) {
                    String line = r.readLine();
                    if (line != null && !line.isBlank()) return "system";
                }
            }
        } catch (Exception e) {
            // ignore
        }
        return "none";
    }

    private static String escapeShell(String s) {
        return s.replace("'", "'\\''");
    }

    private static void runCommand(String cmd) {
        appendLog("Running " + cmd + "...");
        new Thread(() -> {
            try {
                if ("on".equals(cmd)) {
                    ServerController.startAll();
                } else if ("off".equals(cmd)) {
                    ServerController.stopAll();
                }
                appendLog("Command " + cmd + " finished.");
            } catch (Exception ex) {
                appendLog("Error: " + ex.getMessage());
                ex.printStackTrace();
            }
            updateStatus();
        }).start();
    }

    private static void updateStatus() {
        // basic status: existence of pid files + ngrok
        StringBuilder status = new StringBuilder();
        try {
            boolean phpRunning = ServerController.isPhpRunning();
            status.append(phpRunning ? "PHP running; " : "PHP stopped; ");

            boolean ngrokRunning = ServerController.isNgrokRunning();
            status.append(ngrokRunning ? "ngrok running; " : "ngrok stopped; ");

            boolean apacheActive = ServerController.isServiceActive("apache2");
            status.append(apacheActive ? "apache active; " : "apache inactive; ");

            boolean mysqlActive = ServerController.isServiceActive("mysql") || ServerController.isServiceActive("mariadb") || ServerController.isServiceActive("mysqld");
            status.append(mysqlActive ? "mysql active; " : "mysql inactive; ");

            List<String> ips = ServerController.getLocalIPs();
            if (!ips.isEmpty()) {
                status.append("IPs: ");
                for (String ip : ips) status.append(ip).append(" ");
            }
        } catch (Exception e) {
            status.append("status error: ").append(e.getMessage());
        }
        final String s = status.toString();
        SwingUtilities.invokeLater(() -> {
            statusLabel.setText("Status: " + s);
            int online = ServerController.getOnlineUserCount();
            if (online >= 0) {
                onlineCountLabel.setText("users online: " + online);
            } else {
                onlineCountLabel.setText("users online: error");
                String err = ServerController.getLastOnlineCountError();
                if (err != null && !err.isBlank()) {
                    appendLog("online-count error: " + err);
                }
            }
            try {
                boolean apacheActive = ServerController.isServiceActive("apache2");
                apacheStatusLabel.setText(apacheActive ? "online" : "offline");
                apacheStatusLabel.setForeground(apacheActive ? Color.GREEN.darker() : Color.RED);
                if (apacheOnButton != null) apacheOnButton.setEnabled(!apacheActive);
                if (apacheOffButton != null) apacheOffButton.setEnabled(apacheActive);
            } catch (Exception e) {
                apacheStatusLabel.setText("unknown");
                apacheStatusLabel.setForeground(Color.GRAY);
            }
            try {
                boolean mysqlActive = ServerController.isServiceActive("mysql") || ServerController.isServiceActive("mariadb") || ServerController.isServiceActive("mysqld");
                mysqlStatusLabel.setText(mysqlActive ? "online" : "offline");
                mysqlStatusLabel.setForeground(mysqlActive ? Color.GREEN.darker() : Color.RED);                if (mysqlOnButton != null) mysqlOnButton.setEnabled(!mysqlActive);
                if (mysqlOffButton != null) mysqlOffButton.setEnabled(mysqlActive);            } catch (Exception e) {
                mysqlStatusLabel.setText("unknown");
                mysqlStatusLabel.setForeground(Color.GRAY);
            }
            try {
                boolean ngrokRunning = ServerController.isNgrokRunning();
                String ver = null;
                try { ver = ServerController.getNgrokVersion(); } catch (Exception ignore) {}
                boolean supported = ServerController.isNgrokSupported(ver);
                if (!supported) {
                    ngrokStatusLabel.setText(ver==null?"ngrok missing":"ngrok v"+ver+" (unsupported)");
                    ngrokStatusLabel.setForeground(Color.ORANGE.darker());
                    // allow user override: enable buttons but mark with warning
                    if (ngrokOnButton != null) ngrokOnButton.setEnabled(!ngrokRunning);
                    if (ngrokOffButton != null) ngrokOffButton.setEnabled(ngrokRunning);
                } else {
                    ngrokStatusLabel.setText(ngrokRunning ? "online" : "offline");
                    ngrokStatusLabel.setForeground(ngrokRunning ? Color.GREEN.darker() : Color.RED);
                    if (ngrokOnButton != null) ngrokOnButton.setEnabled(!ngrokRunning);
                    if (ngrokOffButton != null) ngrokOffButton.setEnabled(ngrokRunning);
                }
            } catch (Exception e) {
                ngrokStatusLabel.setText("unknown");
                ngrokStatusLabel.setForeground(Color.GRAY);
            }
        });
    }

    private static void appendLog(String line) {
        SwingUtilities.invokeLater(() -> {
            logArea.append(line + "\n");
            logArea.setCaretPosition(logArea.getDocument().getLength());
        });
    }
}
