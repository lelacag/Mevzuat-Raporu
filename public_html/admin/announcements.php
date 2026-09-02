<?php /* EN + TR comments used. */
/**
 * Admin: Announcements Management
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

$current_user_id = get_current_user_id();
if (!$current_user_id) {
    header('Location: ' . BASE_PATH . '/giris');
    exit;
}
$current_user = get_user($current_user_id);
require_admin_perm('manage_notifications');

$reqPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
$is_bulk_optin_page = isset($_GET['action']) && $_GET['action'] === 'bulk_optin';
if ($is_bulk_optin_page || $reqPath === '/admin/bulk_optin_mail.php' || $reqPath === BASE_PATH . '/admin/bulk_optin_mail.php' || $reqPath === '/admin/bulk-optin-mail' || $reqPath === BASE_PATH . '/admin/bulk-optin-mail') {
    $db = db_connect();
    ensure_bulk_optin_tables();

    $flash_error = null;
    $flash_success = null;
    $preview_rows = [];
    $job_details = null;
    $view = $_GET['view'] ?? 'default';

    function run_bulk_optin_worker(): array {
        if (!function_exists('process_bulk_optin_queue')) {
            require_once __DIR__ . '/../includes/functions.php';
        }
        if (!function_exists('process_bulk_optin_queue')) {
            return ['ok' => false, 'message' => 'Bulk queue helper bulunamadı.'];
        }
        try {
            $logPath = __DIR__ . '/../tmp/bulk_optin_admin_trigger.log';
            $start = date('c');
            $meta = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : 'cli';
            @file_put_contents($logPath, "$start WEB-trigger start: $meta\n", FILE_APPEND);
            $res = process_bulk_optin_queue([
                'lock_file' => __DIR__ . '/../tmp/mevoptin.lock',
                'lock_attempts' => 5,
                'lock_wait_usec' => 100000,
            ]);
            $end = date('c');
            @file_put_contents($logPath, "$end WEB-trigger result: " . print_r($res, true) . "\n", FILE_APPEND);
            return $res;
        } catch (Throwable $e) {
            $logPath = __DIR__ . '/../tmp/bulk_optin_admin_trigger.log';
            @file_put_contents($logPath, date('c') . " WEB-trigger exception: " . $e->getMessage() . "\n", FILE_APPEND);
            return ['ok' => false, 'message' => 'Web üzerinden worker çalıştırılırken hata: ' . $e->getMessage()];
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
        require_csrf();

        if ($_POST['action'] === 'create') {
            $title = trim($_POST['title'] ?? '');
            $subject = trim($_POST['subject'] ?? '');
            $body = trim($_POST['body'] ?? '');
            $xml = trim($_POST['xml'] ?? '');
            $dry_run = isset($_POST['dry_run']);
            $save_as = $_POST['save_as'] ?? 'queued';
            $is_draft = $save_as === 'draft';
            $edit_job_id = isset($_POST['job_id']) ? (int)$_POST['job_id'] : 0;
            $existing_job = null;
            if ($edit_job_id > 0) {
                $stmt = $db->prepare("SELECT * FROM bulk_optin_jobs WHERE id = ? LIMIT 1");
                $stmt->execute([$edit_job_id]);
                $existing_job = $stmt->fetch(PDO::FETCH_ASSOC);
            }

            $has_recipient_input = isset($_FILES['bulk_file']) && isset($_FILES['bulk_file']['error']) && $_FILES['bulk_file']['error'] !== UPLOAD_ERR_NO_FILE;
            $has_recipient_input = $has_recipient_input || $xml !== '';
            $parsed = ['rows' => [], 'errors' => []];

            if ($has_recipient_input) {
                if (isset($_FILES['bulk_file']) && isset($_FILES['bulk_file']['error']) && $_FILES['bulk_file']['error'] !== UPLOAD_ERR_NO_FILE) {
                    $parsed = parse_bulk_optin_upload_file($_FILES['bulk_file']);
                } elseif ($xml !== '') {
                    $parsed = parse_bulk_optin_xml($xml);
                }
            } elseif (!$existing_job) {
                $parsed['errors'][] = 'Lütfen XML verisi veya CSV/Excel dosyası sağlayın.';
            }

            if ($title === '' || $subject === '' || $body === '') {
                $flash_error = 'Lütfen tüm alanları doldurun.';
            } elseif (!empty($parsed['errors'])) {
                $flash_error = implode('<br>', array_map('htmlspecialchars', $parsed['errors']));
            } elseif (empty($parsed['rows']) && !$existing_job) {
                $flash_error = 'Geçerli bir e-posta adresi bulunamadı.';
            } else {
                if ($dry_run) {
                    if ($existing_job) {
                        $flash_success = sprintf('%d adet mevcut alıcı bulundu. Dry-run modunda hiçbir e-posta kuyruğa alınmadı.', (int)$existing_job['total']);
                    } else {
                        $flash_success = sprintf('%d adet alıcı bulundu. Dry-run modunda hiçbir e-posta kuyruğa alınmadı.', count($parsed['rows']));
                    }
                } else {
                    if ($existing_job && $existing_job['status'] === 'draft') {
                        $status = $is_draft ? 'draft' : 'queued';
                        $stmt = $db->prepare("UPDATE bulk_optin_jobs SET title = ?, subject = ?, body = ?, status = ? WHERE id = ?");
                        $stmt->execute([$title, $subject, $body, $status, $edit_job_id]);

                        $added = 0;
                        if (!empty($parsed['rows'])) {
                            $added = queue_bulk_optin_emails($edit_job_id, $parsed['rows']);
                            $total = (int)$existing_job['total'] + $added;
                            $update = $db->prepare("UPDATE bulk_optin_jobs SET total = ? WHERE id = ?");
                            $update->execute([$total, $edit_job_id]);
                        } else {
                            $total = (int)$existing_job['total'];
                        }

                        $job_id = $edit_job_id;
                        $flash_success = $is_draft
                            ? sprintf('Taslak güncellendi: %d kayıt mevcut. İş ID: %d', $total, $job_id)
                            : sprintf('Kuyruğa alındı: %d kayıt. İş ID: %d', $total, $job_id);
                        if (!$is_draft && $total > 0) {
                            $workerResult = run_bulk_optin_worker();
                            if ($workerResult['ok']) {
                                $flash_success .= ' Gönderim başlatıldı.';
                                if (!empty($workerResult['output'])) {
                                    $flash_success .= ' (' . htmlspecialchars($workerResult['output']) . ')';
                                }
                            } else {
                                $msg = $workerResult['message'] ?? '';
                                if (str_contains($msg, 'Worker zaten çalışıyor') || str_contains($msg, 'kilit alınamıyor')) {
                                    $flash_success .= ' İş kuyruğa alındı; mevcut gönderim tamamlandığında otomatik olarak işlenecek.';
                                } else {
                                    $flash_error = 'Worker çalıştırılamadı: ' . htmlspecialchars($msg ?: 'Bilinmeyen hata');
                                }
                            }
                        }
                    } else {
                        $status = $is_draft ? 'draft' : 'queued';
                        $stmt = $db->prepare("INSERT INTO bulk_optin_jobs (title, subject, body, created_by, status, total) VALUES (?, ?, ?, ?, ?, 0)");
                        $stmt->execute([$title, $subject, $body, $current_user_id, $status]);
                        $job_id = $db->lastInsertId();
                        $added = queue_bulk_optin_emails($job_id, $parsed['rows']);
                        $final_status = $added > 0 ? $status : 'completed';
                        $update = $db->prepare("UPDATE bulk_optin_jobs SET total = ?, status = ? WHERE id = ?");
                        $update->execute([$added, $final_status, $job_id]);
                        $flash_success = $is_draft
                            ? sprintf('Taslak olarak kaydedildi: %d adet e-posta eklendi. İş ID: %d', $added, $job_id)
                            : sprintf('Kuyruğa %d adet e-posta eklendi. İş ID: %d', $added, $job_id);
                        if (!$is_draft && $added > 0) {
                            $workerResult = run_bulk_optin_worker();
                            if ($workerResult['ok']) {
                                $flash_success .= ' Gönderim başlatıldı.';
                                if (!empty($workerResult['output'])) {
                                    $flash_success .= ' (' . htmlspecialchars($workerResult['output']) . ')';
                                }
                            } else {
                                $msg = $workerResult['message'] ?? '';
                                if (str_contains($msg, 'Worker zaten çalışıyor') || str_contains($msg, 'kilit alınamıyor')) {
                                    $flash_success .= ' İş kuyruğa alındı; mevcut gönderim tamamlandığında otomatik olarak işlenecek.';
                                } else {
                                    $flash_error = 'Worker çalıştırılamadı: ' . htmlspecialchars($msg ?: 'Bilinmeyen hata');
                                }
                            }
                        }
                    }
                }

                if (!empty($parsed['rows'])) {
                    $preview_rows = $parsed['rows'];
                } elseif ($existing_job) {
                    $stmt = $db->prepare("SELECT email, name, source FROM bulk_optin_emails WHERE job_id = ? ORDER BY id ASC LIMIT 15");
                    $stmt->execute([$existing_job['id']]);
                    $preview_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                }
                if (!empty($preview_rows)) {
                    $optedOutCount = 0;
                    $emails = array_unique(array_map('mb_strtolower', array_column($preview_rows, 'email')));
                    if (!empty($emails)) {
                        $placeholders = implode(',', array_fill(0, count($emails), '?'));
                        $stmt = $db->prepare("SELECT LOWER(email) AS email FROM bulk_optin_optouts WHERE LOWER(email) IN ($placeholders)");
                        $stmt->execute($emails);
                        $optedOut = array_flip($stmt->fetchAll(PDO::FETCH_COLUMN, 0));
                    } else {
                        $optedOut = [];
                    }
                    foreach ($preview_rows as &$row) {
                        $row['opted_out'] = !empty($row['email']) && isset($optedOut[mb_strtolower($row['email'])]);
                        if ($row['opted_out']) {
                            $optedOutCount++;
                        }
                    }
                    unset($row);
                }
            }
        } elseif ($_POST['action'] === 'send_job' && isset($_POST['job_id'])) {
            $job_id = (int)$_POST['job_id'];
            $stmt = $db->prepare("SELECT status FROM bulk_optin_jobs WHERE id = ? LIMIT 1");
            $stmt->execute([$job_id]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$existing) {
                $flash_error = 'Geçersiz iş seçimi.';
            } elseif ($existing['status'] === 'queued' || $existing['status'] === 'sending') {
                $flash_success = 'Seçilen iş zaten kuyruğa alınmış.';
            } elseif ($existing['status'] === 'draft') {
                $update = $db->prepare("UPDATE bulk_optin_jobs SET status = 'queued' WHERE id = ?");
                $update->execute([$job_id]);
                $flash_success = 'Taslak iş kuyruğa alındı.';
                $workerResult = run_bulk_optin_worker();
                if ($workerResult['ok']) {
                    $flash_success .= ' Gönderim başlatıldı.';
                    if (!empty($workerResult['output'])) {
                        $flash_success .= ' (' . htmlspecialchars($workerResult['output']) . ')';
                    }
                } else {
                    $msg = $workerResult['message'] ?? '';
                    if (str_contains($msg, 'Worker zaten çalışıyor') || str_contains($msg, 'kilit alınamıyor')) {
                        $flash_success .= ' İş kuyruğa alındı; mevcut gönderim tamamlandığında otomatik olarak işlenecek.';
                    } else {
                        $flash_error = 'Worker çalıştırılamadı: ' . htmlspecialchars($msg ?: 'Bilinmeyen hata');
                    }
                }
            } else {
                $flash_error = 'Bu iş durum için gönderim yapılamaz.';
            }
        } elseif ($_POST['action'] === 'run_worker' && isset($_POST['job_id'])) {
            $job_id = (int)$_POST['job_id'];
            $stmt = $db->prepare("SELECT status FROM bulk_optin_jobs WHERE id = ? LIMIT 1");
            $stmt->execute([$job_id]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$existing) {
                $flash_error = 'Geçersiz iş seçimi.';
            } elseif ($existing['status'] === 'draft') {
                $flash_error = 'Taslak iş için önce kuyruğa alın.';
            } else {
                $result = run_bulk_optin_worker();
                if ($result['ok']) {
                    $flash_success = 'Bulk worker çalıştırıldı.';
                    if (!empty($result['output'])) {
                        $flash_success .= ' Çıktı: ' . htmlspecialchars($result['output']);
                    }
                } else {
                    $flash_error = 'Worker çalıştırılamadı: ' . htmlspecialchars($result['message'] ?? 'Bilinmeyen hata');
                    if (!empty($result['output'])) {
                        $flash_error .= ' Çıktı: ' . htmlspecialchars($result['output']);
                    }
                }
            }
        } elseif ($_POST['action'] === 'delete_job' && isset($_POST['job_id'])) {
            if (empty($_POST['confirm_delete'])) {
                $flash_error = 'İşi silmek için onay kutusunu işaretleyin.';
            } else {
            $job_id = (int)$_POST['job_id'];
            $stmt = $db->prepare("SELECT status FROM bulk_optin_jobs WHERE id = ? LIMIT 1");
            $stmt->execute([$job_id]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$existing) {
                $flash_error = 'Geçersiz iş seçimi.';
            } elseif (!in_array($existing['status'], ['draft', 'completed'], true)) {
                $flash_error = 'Sadece taslak veya tamamlanmış işler silinebilir.';
            } else {
                $stmt = $db->prepare("DELETE FROM bulk_optin_emails WHERE job_id = ?");
                $stmt->execute([$job_id]);
                $stmt = $db->prepare("DELETE FROM bulk_optin_jobs WHERE id = ?");
                $stmt->execute([$job_id]);
                header('Location: ' . BASE_PATH . '/admin/announcements.php?action=bulk_optin');
                exit;
            }
            }
        } elseif ($_POST['action'] === 'delete_email' && isset($_POST['job_id'], $_POST['email_id'])) {
            if (empty($_POST['confirm_delete'])) {
                $flash_error = 'Alıcıyı silmek için onay kutusunu işaretleyin.';
            } else {
            $job_id = (int)$_POST['job_id'];
            $email_id = (int)$_POST['email_id'];
            $stmt = $db->prepare("SELECT status FROM bulk_optin_jobs WHERE id = ? LIMIT 1");
            $stmt->execute([$job_id]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$existing) {
                $flash_error = 'Geçersiz iş seçimi.';
            } elseif ($existing['status'] !== 'draft') {
                $flash_error = 'Bu satır sadece taslak işler için silinebilir.';
            } else {
                $stmt = $db->prepare("DELETE FROM bulk_optin_emails WHERE job_id = ? AND id = ? AND status = 'pending'");
                $stmt->execute([$job_id, $email_id]);
                if ($stmt->rowCount() === 0) {
                    $flash_error = 'Bu satır silinemedi veya zaten işleme alınmış olabilir.';
                } else {
                    $update = $db->prepare("UPDATE bulk_optin_jobs SET total = GREATEST(total - 1, 0) WHERE id = ?");
                    $update->execute([$job_id]);
                    $flash_success = 'E-posta satırı işten çıkarıldı.';
                }
            }
            }
        } elseif ($_POST['action'] === 'edit_email' && isset($_POST['job_id'], $_POST['email_id'])) {
            $job_id = (int)$_POST['job_id'];
            $email_id = (int)$_POST['email_id'];
            $name = trim($_POST['name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $source = trim($_POST['source'] ?? '');

            $stmt = $db->prepare("SELECT status FROM bulk_optin_jobs WHERE id = ? LIMIT 1");
            $stmt->execute([$job_id]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$existing) {
                $flash_error = 'Geçersiz iş seçimi.';
            } elseif ($existing['status'] !== 'draft') {
                $flash_error = 'Bu satır yalnızca taslak işler için düzenlenebilir.';
            } elseif ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $flash_error = 'Geçerli bir e-posta adresi girin.';
            } else {
                $stmt = $db->prepare("SELECT id FROM bulk_optin_emails WHERE job_id = ? AND LOWER(email) = LOWER(?) AND id != ? LIMIT 1");
                $stmt->execute([$job_id, $email, $email_id]);
                if ($stmt->fetch()) {
                    $flash_error = 'Bu e-posta zaten aynı iş içinde mevcut.';
                } else {
                    $update = $db->prepare("UPDATE bulk_optin_emails SET name = ?, email = ?, source = ? WHERE job_id = ? AND id = ? AND status = 'pending'");
                    $update->execute([$name ?: null, $email, $source ?: null, $job_id, $email_id]);
                    if ($update->rowCount() === 0) {
                        $flash_error = 'Satır güncellenemedi veya durumu nedeniyle değiştirilemez.';
                    } else {
                        $flash_success = 'E-posta satırı güncellendi.';
                    }
                }
            }
        }
    }

    $job_id = isset($_GET['job_id']) ? (int)$_GET['job_id'] : 0;
    $email_page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $email_per_page = 50;
    $email_offset = ($email_page - 1) * $email_per_page;
    if ($job_id > 0) {
        $stmt = $db->prepare("SELECT bj.*, u.username FROM bulk_optin_jobs bj JOIN users u ON bj.created_by = u.id WHERE bj.id = ? LIMIT 1");
        $stmt->execute([$job_id]);
        $job_details = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($job_details && in_array($job_details['status'], ['queued', 'sending'], true)) {
            $autoWorkerResult = run_bulk_optin_worker();
            if ($autoWorkerResult['ok']) {
                $flash_success = ($flash_success ? $flash_success . ' ' : '') . 'Queued job processing started automatically.';
                $stmt = $db->prepare("SELECT bj.*, u.username FROM bulk_optin_jobs bj JOIN users u ON bj.created_by = u.id WHERE bj.id = ? LIMIT 1");
                $stmt->execute([$job_id]);
                $job_details = $stmt->fetch(PDO::FETCH_ASSOC);
            } else {
                $msg = $autoWorkerResult['message'] ?? '';
                if (str_contains($msg, 'Worker zaten çalışıyor') || str_contains($msg, 'kilit alınamıyor')) {
                    $flash_success = ($flash_success ? $flash_success . ' ' : '') . 'Worker hâlihazırda çalışıyor veya kilit alınamıyor; iş kuyruğa alındı ve otomatik olarak işlenecek.';
                } else {
                    $flash_error = ($flash_error ? $flash_error . ' ' : '') . 'Worker otomatik olarak çalıştırılamadı: ' . htmlspecialchars($msg ?: 'Bilinmeyen hata');
                }
            }
        }

        if ($job_details) {
            $stmt = $db->prepare("SELECT COUNT(*) FROM bulk_optin_emails WHERE job_id = ?");
            $stmt->execute([$job_id]);
            $email_total = (int)$stmt->fetchColumn();
            $stmt = $db->prepare("SELECT id, email, name, source, status, attempts, sent_at, last_error FROM bulk_optin_emails WHERE job_id = ? ORDER BY id ASC LIMIT ? OFFSET ?");
            $stmt->bindValue(1, $job_id, PDO::PARAM_INT);
            $stmt->bindValue(2, $email_per_page, PDO::PARAM_INT);
            $stmt->bindValue(3, $email_offset, PDO::PARAM_INT);
            $stmt->execute();
            $job_details['emails'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $job_details['email_page'] = $email_page;
            $job_details['email_total'] = $email_total;
            $job_details['email_pages'] = max(1, (int)ceil($email_total / $email_per_page));
        }
    }

    $jobs = $db->query("SELECT bj.*, u.username FROM bulk_optin_jobs bj JOIN users u ON bj.created_by = u.id ORDER BY bj.created_at DESC LIMIT 25")->fetchAll(PDO::FETCH_ASSOC);
    if ($job_id === 0) {
        $hasQueued = false;
        foreach ($jobs as $job) {
            if (in_array($job['status'], ['queued', 'sending'], true)) {
                $hasQueued = true;
                break;
            }
        }
        if ($hasQueued) {
            $autoWorkerResult = run_bulk_optin_worker();
            if ($autoWorkerResult['ok']) {
                $flash_success = ($flash_success ? $flash_success . ' ' : '') . 'Queued jobs processing started automatically.';
                $jobs = $db->query("SELECT bj.*, u.username FROM bulk_optin_jobs bj JOIN users u ON bj.created_by = u.id ORDER BY bj.created_at DESC LIMIT 25")->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $msg = $autoWorkerResult['message'] ?? '';
                if (str_contains($msg, 'Worker zaten çalışıyor') || str_contains($msg, 'kilit alınamıyor')) {
                    $flash_success = ($flash_success ? $flash_success . ' ' : '') . 'Worker hâlihazırda çalışıyor veya kilit alınamıyor; iş kuyruğa alındı ve otomatik olarak işlenecek.';
                } else {
                    $flash_error = ($flash_error ? $flash_error . ' ' : '') . 'Worker otomatik olarak çalıştırılamadı: ' . htmlspecialchars($msg ?: 'Bilinmeyen hata');
                }
            }
        }
    }

    $opted_out_total = 0;
    $opted_out_emails = [];
    $opted_out_page = max(1, (int)($_GET['page'] ?? 1));
    $opted_out_per_page = 50;
    if ($view === 'opted_out') {
        $countStmt = $db->prepare("SELECT COUNT(*) FROM bulk_optin_optouts");
        $countStmt->execute();
        $opted_out_total = (int)$countStmt->fetchColumn();
        $offset = ($opted_out_page - 1) * $opted_out_per_page;
        $stmt = $db->prepare("SELECT email, reason, created_at FROM bulk_optin_optouts ORDER BY created_at DESC LIMIT ? OFFSET ?");
        $stmt->bindValue(1, $opted_out_per_page, PDO::PARAM_INT);
        $stmt->bindValue(2, $offset, PDO::PARAM_INT);
        $stmt->execute();
        $opted_out_emails = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $opted_out_pages = max(1, (int)ceil($opted_out_total / $opted_out_per_page));
    }

    $form_title = $_POST['title'] ?? $job_details['title'] ?? '';
    $form_subject = $_POST['subject'] ?? $job_details['subject'] ?? '';
    $form_body = $_POST['body'] ?? $job_details['body'] ?? "Merhaba %NAME%,\n\nSizi %SOURCE% aracılığıyla tanıdık ve platformumuza davet etmek istiyoruz.\n\nKayıt olmak için: %REGISTER_LINK%\n\nAbonelikten çıkmak için: %OPT_OUT_LINK%";
    $form_xml = $_POST['xml'] ?? '';
    $csrf_token = generate_csrf_token();
    if ($job_details && $job_details['status'] === 'sending') {
        if (!isset($extra_head)) {
            $extra_head = '';
        }
        $extra_head .= '<meta http-equiv="refresh" content="15">';
    }
    require_once __DIR__ . '/_header.php';
    ?>
        <style nonce="<?= htmlspecialchars($csp_nonce ?? '', ENT_QUOTES, 'UTF-8') ?>">
            .admin-page { max-width: 1200px; margin: 0 auto; padding: 20px; font-family: system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial, sans-serif; }
            .admin-page h1 { margin-bottom: 20px; }
            .admin-alert { padding: 15px; border-radius: 6px; margin-bottom: 20px; }
            .admin-alert.error { background: #ffe5e5; color: #900; }
            .admin-alert.success { background: #e8f7e8; color: #0a660a; }
            .admin-form textarea, .admin-form input[type=text] { width: 100%; padding: 10px; margin-bottom: 12px; border: 1px solid #ccc; border-radius: 4px; }
            .admin-form label { display: block; margin-bottom: 4px; font-weight: 600; }
            .admin-form button { padding: 10px 16px; border: none; border-radius: 4px; background: #0073e6; color: white; cursor: pointer; }
            .admin-form button:hover { background: #005bb5; }
            .admin-grid { display: grid; grid-template-columns: 1fr; gap: 20px; }
            .admin-card { border: 1px solid #ddd; border-radius: 8px; padding: 18px; background: white; }
            .admin-table { width: 100%; border-collapse: collapse; }
            .admin-table th, .admin-table td { border: 1px solid #ddd; padding: 10px; text-align: left; }
            .admin-table th { background: #f7f7f7; }
            .admin-small { font-size: 0.9rem; color: #555; }
            .admin-meta { margin-top: 10px; color: #555; }
            .admin-tabs { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:20px; }
            .admin-tab { padding:10px 14px; border-radius:6px; background:#f4f4f4; color:#333; text-decoration:none; border:1px solid #ddd; font-weight:600; }
            .admin-tab.active { background:#0073e6; color:#fff; border-color:#0073e6; }
            .admin-progress { margin-top: 16px; }
            .admin-progress-label { margin-bottom: 6px; font-weight: 600; }
            .admin-progress-bar { height: 16px; background: #e6e6e6; border-radius: 8px; overflow: hidden; }
            .admin-progress-fill { height: 100%; background: #4caf50; transition: width 0.25s ease; }
        </style>
        <main class="admin-page">
            <h1>Bulk Mail Gönderimi</h1>
            <div class="admin-tabs">
                <a href="?action=bulk_optin" class="admin-tab<?= $view !== 'opted_out' ? ' active' : '' ?>">Gönderim</a>
                <a href="?action=bulk_optin&view=opted_out" class="admin-tab<?= $view === 'opted_out' ? ' active' : '' ?>">Abonelikten Çıkmışlar</a>
            </div>
            <?php if ($flash_error): ?>
                <div class="admin-alert error"><?= $flash_error ?></div>
            <?php endif; ?>
            <?php if ($flash_success): ?>
                <div class="admin-alert success"><?= $flash_success ?></div>
            <?php endif; ?>
            <?php if ($view === 'opted_out'): ?>
                <section class="admin-card">
                    <h2>Abonelikten Çıkmış E-posta Adresleri</h2>
                    <p class="admin-small"><?= sprintf('%d kayıt bulundu.', $opted_out_total) ?></p>
                    <div class="admin-table-wrapper">
                        <table class="admin-table">
                            <thead>
                                <tr>
                                    <th>E-posta</th>
                                    <th>Sebep</th>
                                    <th>Tarih</th>
                                </tr>
                            </thead>
                        <tbody>
                            <?php if (empty($opted_out_emails)): ?>
                                <tr><td colspan="3" class="admin-small">Abonelikten çıkan adres bulunamadı.</td></tr>
                            <?php else: ?>
                                <?php foreach ($opted_out_emails as $row): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($row['email']) ?></td>
                                        <td><?= htmlspecialchars($row['reason']) ?></td>
                                        <td><?= htmlspecialchars($row['created_at']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                    </div>
                    <?php if ($opted_out_total > $opted_out_per_page): ?>
                        <div style="margin-top:16px; display:flex; flex-wrap:wrap; gap:8px; align-items:center;">
                            <span class="admin-small">Sayfa <?= $opted_out_page ?> / <?= $opted_out_pages ?> (<?= $opted_out_total ?> kayıt)</span>
                            <?php for ($p = 1; $p <= $opted_out_pages; $p++): ?>
                                <?php if ($p === $opted_out_page): ?>
                                    <span style="padding:6px 10px; border:1px solid #0073e6; border-radius:4px; background:#0073e6; color:white;"><?= $p ?></span>
                                <?php else: ?>
                                    <a href="?action=bulk_optin&view=opted_out&page=<?= $p ?>" style="padding:6px 10px; border:1px solid #ddd; border-radius:4px; text-decoration:none; color:#0073e6;"><?= $p ?></a>
                                <?php endif; ?>
                            <?php endfor; ?>
                        </div>
                    <?php endif; ?>
                </section>
                <?php require_once __DIR__ . '/_footer.php'; exit; ?>
            <?php endif; ?>
            <div class="admin-grid">
                <section class="admin-card">
                    <h2>Yeni Bulk Mail</h2>
                    <form method="post" enctype="multipart/form-data" class="admin-form">
                        <input type="hidden" name="action" value="create">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                        <?php if (isset($job_details['id']) && $job_details['status'] === 'draft'): ?>
                            <input type="hidden" name="job_id" value="<?= (int)$job_details['id'] ?>">
                        <?php endif; ?>
                        <label for="title">İş Başlığı</label>
                        <input type="text" id="title" name="title" required value="<?= htmlspecialchars($form_title) ?>">

                        <label for="subject">E-posta Konusu</label>
                        <input type="text" id="subject" name="subject" required value="<?= htmlspecialchars($form_subject) ?>">

                        <label for="body">E-posta İçeriği</label>
                        <textarea id="body" name="body" rows="10" required><?= htmlspecialchars($form_body) ?></textarea>

                        <label for="bulk_file">CSV / Excel Yüklemesi</label>
                        <input type="file" id="bulk_file" name="bulk_file" accept=".csv,.xlsx,.xls" class="admin-file-input">
                        <p class="admin-small">CSV veya Excel yükleyebilirsiniz. Sütunlar <code>name,email,source</code> şeklinde olmalıdır. Eğer XML kullanmak istiyorsanız XML alanını da doldurabilirsiniz.</p>

                        <label for="xml">XML Yüklemesi</label>
                        <textarea id="xml" name="xml" rows="10"><?= htmlspecialchars($form_xml ?: "<contacts>\n  <contact>\n    <name>Ali Veli</name>\n    <email>ali@example.com</email>\n    <source>newsletter</source>\n  </contact>\n</contacts>") ?></textarea>
                        <p class="admin-small">Her <code>&lt;contact&gt;</code> öğesi için <code>&lt;name&gt;</code>, <code>&lt;email&gt;</code> ve isterseniz <code>&lt;source&gt;</code> kullanın.</p>

                        <label><input type="checkbox" name="dry_run" <?= isset($_POST['dry_run']) ? 'checked' : '' ?>> Dry-run: Sadece doğrulama yap, kuyruk oluşturma.</label>
                        <div style="display:flex; gap:12px; flex-wrap:wrap; align-items:center;">
                            <button type="submit" name="save_as" value="queued">Kuyruğa Al</button>
                            <button type="submit" name="save_as" value="draft">Taslak Olarak Kaydet</button>
                        </div>
                    </form>
                </section>
                <?php if (!empty($preview_rows)): ?>
                <section class="admin-card">
                    <h2>Önizleme</h2>
                    <p class="admin-small"><?= sprintf('%d kayıt bulundu.', count($preview_rows)) ?><?= isset($optedOutCount) && $optedOutCount > 0 ? ' — ' . sprintf('%d adres abonelikten çıkmış.', $optedOutCount) : '' ?></p>
                    <div class="admin-table-wrapper">
                        <table class="admin-table">
                            <thead>
                                <tr>
                                    <th>E-posta</th>
                                    <th>İsim</th>
                                    <th>Kaynak</th>
                                    <th>Abonelikten Çıktı</th>
                                </tr>
                            </thead>
                        <tbody>
                            <?php foreach (array_slice($preview_rows, 0, 15) as $row): ?>
                                <tr>
                                    <td><?= htmlspecialchars($row['email']) ?></td>
                                    <td><?= htmlspecialchars($row['name'] ?? '') ?></td>
                                    <td><?= htmlspecialchars($row['source'] ?? '') ?></td>
                                    <td><?= !empty($row['opted_out']) ? 'Evet' : 'Hayır' ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (count($preview_rows) > 15): ?>
                                <tr><td colspan="3" class="admin-small">...ve <?= count($preview_rows) - 15 ?> daha kayıt</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                    </div>
                </section>
                <?php endif; ?>
                <section class="admin-card">
                    <h2>Son İşler</h2>
                    <div class="admin-table-wrapper">
                        <table class="admin-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Başlık</th>
                                    <th>Durum</th>
                                    <th>Toplam</th>
                                    <th>Gönderildi</th>
                                    <th>İlerleme</th>
                                    <th>Başarısız</th>
                                    <th>Oluşturan</th>
                                    <th>Oluşturulma</th>
                                    <th>Sil</th>
                                </tr>
                            </thead>
                        <tbody>
                        <?php foreach ($jobs as $job): ?>
                            <tr>
                                <td><a href="?action=bulk_optin&job_id=<?= (int)$job['id'] ?>">#<?= (int)$job['id'] ?></a></td>
                                <td><?= htmlspecialchars($job['title']) ?></td>
                                <td><?= htmlspecialchars($job['status'] === 'sending' ? 'sending (işleniyor)' : $job['status']) ?></td>
                                <td><?= (int)$job['total'] ?></td>
                                <td><?= (int)$job['sent_count'] ?></td>
                                <td><?= $job['total'] > 0 ? round(($job['sent_count'] * 100) / $job['total']) . '%' : '0%' ?></td>
                                <td><?= (int)$job['failed_count'] ?></td>
                                <td><?= htmlspecialchars($job['username']) ?></td>
                                <td><?= htmlspecialchars($job['created_at']) ?></td>
                                <td>
                                    <?php if (in_array($job['status'], ['draft', 'completed'], true)): ?>
                                            <form method="post" style="margin:0;">
                                                <input type="hidden" name="action" value="delete_job">
                                                <input type="hidden" name="job_id" value="<?= (int)$job['id'] ?>">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                                <label style="display:flex;align-items:center;gap:6px;font-size:12px;margin-bottom:4px;">
                                                    <input type="checkbox" name="confirm_delete" value="1" required>
                                                    Onayla
                                                </label>
                                                <button type="submit" style="padding:6px 10px; border:none; border-radius:4px; background:#d9534f; color:white; cursor:pointer;">Sil</button>
                                            </form>
                                        <?php else: ?>
                                            &mdash;
                                        <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                </section>
            </div>

            <?php if ($job_details): ?>
                <section class="admin-card">
                    <h2>İş Detayı: #<?= (int)$job_details['id'] ?> <?= htmlspecialchars($job_details['title']) ?></h2>
                    <?php
                        $jobTotal = (int)$job_details['total'];
                        $jobSent = (int)$job_details['sent_count'];
                        $jobFailed = (int)$job_details['failed_count'];
                        $jobPercent = $jobTotal > 0 ? min(100, max(0, (int)round(($jobSent * 100) / $jobTotal))) : 0;
                        $jobRemaining = max(0, $jobTotal - $jobSent - $jobFailed);
                    ?>
                    <div class="admin-meta">
                        Durum: <?= htmlspecialchars($job_details['status'] === 'sending' ? 'sending (işleniyor)' : $job_details['status']) ?> • Toplam: <?= $jobTotal ?> • Gönderildi: <?= $jobSent ?> • Başarısız: <?= $jobFailed ?> • Kalan: <?= $jobRemaining ?>
                    </div>
                    <div class="admin-progress">
                        <div class="admin-progress-label">İlerleme: <?= $jobPercent ?>% (<?= $jobSent ?>/<?= $jobTotal ?>)</div>
                        <div class="admin-progress-bar"><div class="admin-progress-fill" style="width: <?= $jobPercent ?>%;"></div></div>
                    </div>
                    <?php if ($job_details['status'] === 'draft'): ?>
                        <form method="post" style="margin:16px 0; display:inline-block;">
                            <input type="hidden" name="action" value="send_job">
                            <input type="hidden" name="job_id" value="<?= (int)$job_details['id'] ?>">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                            <button type="submit" class="admin-form" style="padding:10px 16px; border:none; border-radius:4px; background:#0073e6; color:white; cursor:pointer;">Taslak Gönder</button>
                        </form>
                    <?php endif; ?>
                    <?php if (in_array($job_details['status'], ['draft', 'completed'], true)): ?>
                        <form method="post" style="margin:16px 0; display:inline-block; margin-left:12px;">
                            <input type="hidden" name="action" value="delete_job">
                            <input type="hidden" name="job_id" value="<?= (int)$job_details['id'] ?>">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                            <label style="display:flex;align-items:center;gap:6px;font-size:12px;margin-bottom:6px;">
                                <input type="checkbox" name="confirm_delete" value="1" required>
                                Bu işi silmeyi onaylıyorum
                            </label>
                            <button type="submit" class="admin-form" style="padding:10px 16px; border:none; border-radius:4px; background:#d9534f; color:white; cursor:pointer;">Sil</button>
                        </form>
                    <?php endif; ?>
                    <?php if (in_array($job_details['status'], ['queued', 'sending'], true)): ?>
                        <form method="post" style="margin:16px 0; display:inline-block; margin-left:12px;">
                            <input type="hidden" name="action" value="run_worker">
                            <input type="hidden" name="job_id" value="<?= (int)$job_details['id'] ?>">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                            <button type="submit" class="admin-form" style="padding:10px 16px; border:none; border-radius:4px; background:#28a745; color:white; cursor:pointer;">Worker Çalıştır / Gönderimi Başlat</button>
                        </form>
                    <?php endif; ?>
                    <div class="admin-table-wrapper">
                        <table class="admin-table">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>E-posta</th>
                                    <th>İsim</th>
                                    <th>Kaynak</th>
                                    <th>Durum</th>
                                    <th>Deneme</th>
                                    <th>Gönderilme</th>
                                    <th>Hata</th>
                                    <th>İşlemler</th>
                                </tr>
                            </thead>
                        <tbody>
                            <?php foreach ($job_details['emails'] as $emailRow): ?>
                                <tr>
                                    <td><?= (int)$emailRow['id'] ?></td>
                                    <td>
                                        <?php if ($job_details['status'] === 'draft' && $emailRow['status'] === 'pending'): ?>
                                            <form method="post" style="margin:0; display:flex; gap:4px; align-items:center;">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                                <input type="hidden" name="action" value="edit_email">
                                                <input type="hidden" name="job_id" value="<?= (int)$job_details['id'] ?>">
                                                <input type="hidden" name="email_id" value="<?= (int)$emailRow['id'] ?>">
                                                <input type="text" name="email" value="<?= htmlspecialchars($emailRow['email']) ?>" style="width:220px; padding:6px; border:1px solid #ccc; border-radius:4px;">
                                        <?php else: ?>
                                            <?= htmlspecialchars($emailRow['email']) ?>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($job_details['status'] === 'draft' && $emailRow['status'] === 'pending'): ?>
                                                <input type="text" name="name" value="<?= htmlspecialchars($emailRow['name']) ?>" style="width:140px; padding:6px; border:1px solid #ccc; border-radius:4px;">
                                        <?php else: ?>
                                            <?= htmlspecialchars($emailRow['name']) ?>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($job_details['status'] === 'draft' && $emailRow['status'] === 'pending'): ?>
                                                <input type="text" name="source" value="<?= htmlspecialchars($emailRow['source']) ?>" style="width:140px; padding:6px; border:1px solid #ccc; border-radius:4px;">
                                        <?php else: ?>
                                            <?= htmlspecialchars($emailRow['source']) ?>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars($emailRow['status']) ?></td>
                                    <td><?= (int)$emailRow['attempts'] ?></td>
                                    <td><?= htmlspecialchars($emailRow['sent_at']) ?></td>
                                    <td><?= htmlspecialchars($emailRow['last_error']) ?></td>
                                    <td>
                                        <?php if ($job_details['status'] === 'draft' && $emailRow['status'] === 'pending'): ?>
                                                <button type="submit" class="admin-form" style="padding:6px 10px; border:none; border-radius:4px; background:#0073e6; color:white; cursor:pointer;">Kaydet</button>
                                            </form>
                                            <form method="post" style="margin:4px 0 0;">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                                <input type="hidden" name="action" value="delete_email">
                                                <input type="hidden" name="job_id" value="<?= (int)$job_details['id'] ?>">
                                                <input type="hidden" name="email_id" value="<?= (int)$emailRow['id'] ?>">
                                                <label style="display:flex;align-items:center;gap:4px;font-size:11px;margin-bottom:4px;">
                                                    <input type="checkbox" name="confirm_delete" value="1" required>
                                                    Onay
                                                </label>
                                                <button type="submit" class="admin-form" style="padding:6px 10px; border:none; border-radius:4px; background:#d9534f; color:white; cursor:pointer;">Sil</button>
                                            </form>
                                        <?php else: ?>
                                            &mdash;
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                    <?php if (!empty($job_details['email_pages']) && $job_details['email_pages'] > 1): ?>
                        <div style="margin-top:16px; display:flex; flex-wrap:wrap; gap:8px; align-items:center;">
                            <span class="admin-small">Sayfa <?= (int)$job_details['email_page'] ?> / <?= (int)$job_details['email_pages'] ?> (<?= (int)$job_details['email_total'] ?> kayıt)</span>
                            <?php for ($p = 1; $p <= $job_details['email_pages']; $p++): ?>
                                <?php if ($p === $job_details['email_page']): ?>
                                    <span style="padding:6px 10px; border:1px solid #0073e6; border-radius:4px; background:#0073e6; color:white;"><?= $p ?></span>
                                <?php else: ?>
                                    <a href="?action=bulk_optin&job_id=<?= (int)$job_details['id'] ?>&page=<?= $p ?>" style="padding:6px 10px; border:1px solid #ddd; border-radius:4px; text-decoration:none; color:#0073e6;"><?= $p ?></a>
                                <?php endif; ?>
                            <?php endfor; ?>
                        </div>
                    <?php endif; ?>
                </section>
            <?php endif; ?>
        </main>
<?php require_once __DIR__ . '/_footer.php';
    exit;
}

/**
 * Trigger the announcement email worker in-process (web-side).
 * Reads queued announcement_emails rows and sends up to $batchSize emails.
 */
if (!function_exists('run_announcement_email_worker')) {
    function run_announcement_email_worker(int $batchSize = 20): array {
        try {
            $logPath = __DIR__ . '/../tmp/announcement_email_web_trigger.log';
            @file_put_contents($logPath, date('c') . " WEB-trigger start\n", FILE_APPEND);

            global $db;
            if (!$db) {
                return ['ok' => false, 'message' => 'DB bağlantısı bulunamadı.'];
            }
            if (!function_exists('send_email')) {
                require_once __DIR__ . '/../includes/functions.php';
            }
            if (!function_exists('full_url')) {
                require_once __DIR__ . '/../modules/url_helpers.php';
            }
            $maxAttempts = 5;
            $retryDelay  = 600;

            $stmt = $db->prepare(
                "SELECT ae.*, a.title, a.content, a.slug
                   FROM announcement_emails ae
                   JOIN announcements a ON ae.announcement_id = a.id
                  WHERE ae.status = 'queued'
                    AND ae.attempts < ?
                    AND (ae.next_attempt_at IS NULL OR ae.next_attempt_at <= NOW())
                  ORDER BY ae.id ASC
                  LIMIT ?"
            );
            $stmt->execute([$maxAttempts, $batchSize]);
            $jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $sent = 0; $failed = 0;
            foreach ($jobs as $job) {
                $subject = '[Mevzuat Raporu Duyuru] ' . $job['title'];
                $body  = "Merhaba,\n\n";
                $body .= $job['content'] . "\n\n";
                $body .= "Daha fazla: " . full_url(BASE_PATH . '/duyuru/' . urlencode($job['slug'])) . "\n\n";
                $body .= "İyi günler!";

                $sendOk = false; $error = '';
                try { $sendOk = send_email($job['email'], $subject, $body); }
                catch (Throwable $e) { $error = $e->getMessage(); }

                if ($sendOk) {
                    $db->prepare("UPDATE announcement_emails SET status='sent', attempts=attempts+1, sent_at=NOW(), last_error=NULL WHERE id=?")->execute([$job['id']]);
                    $sent++;
                } else {
                    $error = $error ?: 'unknown error';
                    $status = ($job['attempts'] + 1 >= $maxAttempts) ? 'failed' : 'queued';
                    $next   = date('Y-m-d H:i:s', time() + $retryDelay);
                    $db->prepare("UPDATE announcement_emails SET status=?, attempts=attempts+1, next_attempt_at=?, last_error=? WHERE id=?")->execute([$status, $next, $error, $job['id']]);
                    $failed++;
                }

                // Update parent announcement aggregate status
                $cntStmt = $db->prepare("SELECT COUNT(*) AS tot, SUM(status='sent') AS sent_c, SUM(status='failed') AS failed_c, SUM(status='queued') AS queued_c FROM announcement_emails WHERE announcement_id=?");
                $cntStmt->execute([$job['announcement_id']]);
                $cnt = $cntStmt->fetch(PDO::FETCH_ASSOC);
                if ($cnt['queued_c'] > 0) {
                    $aggStatus = 'sending';
                } elseif ($cnt['failed_c'] > 0 && $cnt['sent_c'] == 0) {
                    $aggStatus = 'failed';
                } else {
                    $aggStatus = 'sent';
                }
                $db->prepare("UPDATE announcements SET email_status=? WHERE id=?")->execute([$aggStatus, $job['announcement_id']]);
            }

            $result = ['ok' => true, 'sent' => $sent, 'failed' => $failed, 'total' => count($jobs)];
            @file_put_contents($logPath, date('c') . " WEB-trigger result: " . print_r($result, true) . "\n", FILE_APPEND);
            return $result;
        } catch (Throwable $e) {
            $logPath = __DIR__ . '/../tmp/announcement_email_web_trigger.log';
            @file_put_contents($logPath, date('c') . " WEB-trigger exception: " . $e->getMessage() . "\n", FILE_APPEND);
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }
}

// Ensure the admin header (and its CSS injection) is included so styles load correctly
$db = db_connect();
ensure_announcements_columns();
ensure_announcement_email_table();

function celebrations_table_exists() {
    try {
        $stmt = query("SHOW TABLES LIKE 'celebrations'");
        return (bool)$stmt->fetch();
    } catch (Exception $e) {
        error_log('celebrations table check failed: ' . $e->getMessage());
        return false;
    }
}

function ensure_celebrations_table() {
    if (celebrations_table_exists()) {
        return true;
    }
    try {
        query("CREATE TABLE IF NOT EXISTS `celebrations` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `text` TEXT NOT NULL,
            `display_date` DATE NOT NULL,
            `text_color` VARCHAR(7) NOT NULL DEFAULT '#000000',
            `background_color` VARCHAR(7) NOT NULL DEFAULT '#ffffff',
            `is_active` TINYINT(1) NOT NULL DEFAULT 1,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY (`display_date`),
            KEY (`is_active`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        return true;
    } catch (Exception $e) {
        error_log('Failed to create celebrations table: ' . $e->getMessage());
        return false;
    }
}

$action = $_GET['action'] ?? 'list';
$announcement_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$announcement_image_upload_snippet = null;

// Some installs might not have the `sources` column yet (DB migrations may be pending).
// Detect it and fall back to the older schema if needed.
$has_sources_column = false;
try {
    $chk = $db->query("SHOW COLUMNS FROM announcements LIKE 'sources'");
    $has_sources_column = (bool)$chk->fetch();
} catch (Exception $e) {
    $has_sources_column = false;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['celebration_action'])) {
    require_csrf();
    if ($_POST['celebration_action'] === 'save') {
        $celebration_edit_id = isset($_POST['celebration_id']) ? intval($_POST['celebration_id']) : 0;
        $celebration_text = trim($_POST['celebration_text'] ?? '');
        $celebration_date = trim($_POST['celebration_date'] ?? '');
        $celebration_text_color = trim($_POST['celebration_text_color'] ?? '#000000');
        $celebration_background_color = trim($_POST['celebration_background_color'] ?? '#ffffff');
        $celebration_is_active = !empty($_POST['celebration_is_active']) ? 1 : 0;
        $celebration_errors = [];

        if ($celebration_text === '') {
            $celebration_errors[] = 'Kutlama metni boş bırakılamaz.';
        }
        $parsed_date = DateTime::createFromFormat('Y-m-d', $celebration_date);
        if (!$parsed_date || $parsed_date->format('Y-m-d') !== $celebration_date) {
            $celebration_errors[] = 'Lütfen geçerli bir tarih seçin.';
        }
        if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $celebration_text_color)) {
            $celebration_errors[] = 'Lütfen geçerli bir metin rengi seçin.';
            $celebration_text_color = '#000000';
        }
        if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $celebration_background_color)) {
            $celebration_errors[] = 'Lütfen geçerli bir arka plan rengi seçin.';
            $celebration_background_color = '#ffffff';
        }

        if (empty($celebration_errors)) {
            if (!ensure_celebrations_table()) {
                $celebration_errors[] = 'Kutlamalar tablosu mevcut değil veya oluşturulamıyor. Lütfen DB migrasyonlarını çalıştırın.';
            }
        }

        if (empty($celebration_errors)) {
            if ($celebration_edit_id > 0) {
                query('UPDATE celebrations SET text = ?, display_date = ?, text_color = ?, background_color = ?, is_active = ?, updated_at = NOW() WHERE id = ?', [$celebration_text, $celebration_date, $celebration_text_color, $celebration_background_color, $celebration_is_active, $celebration_edit_id]);
                $_SESSION['flash'] = 'Kutlama kaydı güncellendi.';
            } else {
                query('INSERT INTO celebrations (text, display_date, text_color, background_color, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, NOW(), NOW())', [$celebration_text, $celebration_date, $celebration_text_color, $celebration_background_color, $celebration_is_active]);
                $_SESSION['flash'] = 'Yeni kutlama kaydı eklendi.';
            }
            header('Location: ' . BASE_PATH . '/admin/announcements.php?action=celebrations');
            exit;
        }
    } elseif ($_POST['celebration_action'] === 'delete') {
        if (empty($_POST['confirm_delete'])) {
            $_SESSION['flash_error'] = 'Kutlamayı silmek için onay kutusunu işaretleyin.';
            header('Location: ' . BASE_PATH . '/admin/announcements.php?action=celebrations');
            exit;
        }
        $celebration_delete_id = isset($_POST['celebration_id']) ? intval($_POST['celebration_id']) : 0;
        if ($celebration_delete_id > 0 && ensure_celebrations_table()) {
            query('DELETE FROM celebrations WHERE id = ?', [$celebration_delete_id]);
            $_SESSION['flash'] = 'Kutlama kaydı silindi.';
        } else {
            $_SESSION['flash'] = 'Kutlamalar tablosu mevcut değil veya silme işlemi gerçekleştirilemiyor.';
        }
        header('Location: ' . BASE_PATH . '/admin/announcements.php?action=celebrations');
        exit;
    }
}

if ($action === 'celebrations') {
    $celebration_page = true;
    $celebration_errors = $celebration_errors ?? [];
    $celebration_text = '';
    $celebration_date = '';
    $celebration_text_color = '#000000';
    $celebration_background_color = '#ffffff';
    $celebration_is_active = 1;
    $celebration_edit_id = 0;

    if (!ensure_celebrations_table()) {
        $celebration_errors[] = 'Kutlamalar tablosu mevcut değil veya oluşturulamıyor. Lütfen DB migrasyonlarını çalıştırın.';
        $celebrations = [];
    } else {
        if (isset($_GET['id'])) {
            $celebration_edit_id = intval($_GET['id']);
            if ($celebration_edit_id > 0) {
                $celebration_edit = query('SELECT * FROM celebrations WHERE id = ? LIMIT 1', [$celebration_edit_id])->fetch();
                if ($celebration_edit) {
                    $celebration_text = $celebration_edit['text'];
                    $celebration_date = $celebration_edit['display_date'];
                    $celebration_text_color = $celebration_edit['text_color'];
                    $celebration_background_color = $celebration_edit['background_color'];
                    $celebration_is_active = $celebration_edit['is_active'] ? 1 : 0;
                }
            }
        }
        $celebrations = query('SELECT * FROM celebrations ORDER BY MONTH(display_date), DAY(display_date), created_at DESC')->fetchAll();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    require_csrf();
    if ($_POST['action'] === 'create' || $_POST['action'] === 'edit') {
        $title = $_POST['title'] ?? '';
        $summary = $_POST['summary'] ?? '';
        $content = $_POST['content'] ?? '';
        $sources = $_POST['sources'] ?? '';
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        $announcement_image_upload_snippet = null;

        // Button-based save mode: publish vs draft
        $save_as = $_POST['save_as'] ?? 'publish';
        if ($save_as === 'draft') {
            $is_active = 0;
        } else {
            $is_active = 1;
        }

        $is_upload_action = isset($_POST['upload_image']);
        if ($is_upload_action) {
            if (!empty($_FILES['announcement_image']) && isset($_FILES['announcement_image']['error']) && $_FILES['announcement_image']['error'] !== UPLOAD_ERR_NO_FILE) {
                $file = $_FILES['announcement_image'];
                if ($file['error'] !== UPLOAD_ERR_OK) {
                    $uploadErrors = [
                        UPLOAD_ERR_INI_SIZE => 'Yüklenen dosya PHP ayarlarında belirtilen maksimum boyuttan büyük.',
                        UPLOAD_ERR_FORM_SIZE => 'Yüklenen dosya form tarafından izin verilen maksimum boyutu aşıyor.',
                        UPLOAD_ERR_PARTIAL => 'Dosya yalnızca kısmen yüklendi. Lütfen tekrar deneyin.',
                        UPLOAD_ERR_NO_FILE => 'Dosya seçilmedi.',
                        UPLOAD_ERR_NO_TMP_DIR => 'Geçici dosya dizini bulunamadı.',
                        UPLOAD_ERR_CANT_WRITE => 'Dosya diske yazılamadı.',
                        UPLOAD_ERR_EXTENSION => 'Dosya yüklemesi bir eklenti tarafından durduruldu.',
                    ];
                    $flash_error = $uploadErrors[$file['error']] ?? 'Resim yüklenirken bilinmeyen bir hata oluştu.';
                    error_log('[ANNOUNCEMENTS] upload error code=' . $file['error'] . ' message=' . $flash_error);
                } elseif ($file['size'] > 5 * 1024 * 1024) {
                    $flash_error = 'Dosya çok büyük. Maksimum boyut 5MB.';
                } else {
                    $mime = null;
                    if (function_exists('finfo_open')) {
                        $finfo = finfo_open(FILEINFO_MIME_TYPE);
                        if ($finfo !== false) {
                            $mime = finfo_file($finfo, $file['tmp_name']);
                            finfo_close($finfo);
                        }
                    }
                    if ($mime === null && function_exists('mime_content_type')) {
                        $mime = mime_content_type($file['tmp_name']);
                    }
                    $allowed_mimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                    if ($mime === null) {
                        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                        $mime = $ext === 'jpg' || $ext === 'jpeg' ? 'image/jpeg' : ($ext === 'png' ? 'image/png' : ($ext === 'gif' ? 'image/gif' : ($ext === 'webp' ? 'image/webp' : '')));
                    }
                    if (!in_array($mime, $allowed_mimes, true)) {
                        $flash_error = 'Geçersiz dosya türü. Yalnızca JPEG, PNG, GIF ve WebP kabul edilir.';
                    } else {
                        $projectRoot = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
                        $uploadRoot = $projectRoot . '/tmp/announcements';
                        if (!is_dir($uploadRoot)) {
                            if (!@mkdir($uploadRoot, 0755, true) && !is_dir($uploadRoot)) {
                                $flash_error = 'Resim deposu oluşturulamadı. Sunucu izinlerini kontrol edin.';
                            }
                        }
                        if (empty($flash_error)) {
                            $resolvedUploadRoot = realpath($uploadRoot);
                            if ($resolvedUploadRoot !== false) {
                                $uploadRoot = $resolvedUploadRoot;
                            }
                            $basename = preg_replace('/[^A-Za-z0-9._-]/', '_', basename($file['name']));
                            $filename = time() . '_' . bin2hex(random_bytes(4)) . '_' . $basename;
                            $dest = $uploadRoot . '/' . $filename;
                            $dest = preg_replace('#/+#', '/', $dest);
                            if (@move_uploaded_file($file['tmp_name'], $dest)) {
                                $urlPath = '/announcement.php?serve=image&name=' . rawurlencode($filename);
                                $announcement_image_upload_snippet = '[image]' . full_url($urlPath) . '[/image]';
                                $flash_success = 'Resim yüklendi. İçeriğe yapıştırmak için aşağıdaki kodu kullanın.';
                            } else {
                                $file_debug = [];
                                $file_debug[] = 'tmp=' . ($file['tmp_name'] ?? '');
                                $file_debug[] = 'is_uploaded=' . (is_uploaded_file($file['tmp_name']) ? 'yes' : 'no');
                                $file_debug[] = 'writable=' . (is_writable($uploadRoot) ? 'yes' : 'no');
                                $file_debug[] = 'uploadRoot=' . $uploadRoot;
                                if (function_exists('realpath')) {
                                    $file_debug[] = 'uploadRoot_real=' . (realpath($uploadRoot) ?: 'none');
                                }
                                $file_debug[] = 'dest=' . $dest;

                                // extra diagnostics: owner/perm of tmp file and upload dir, process uid/gid, php temp and open_basedir
                                if (!empty($file['tmp_name']) && file_exists($file['tmp_name'])) {
                                    $st = @stat($file['tmp_name']);
                                    if ($st) {
                                        $file_debug[] = 'tmp_owner=' . ($st['uid'] ?? '?') . '/' . ($st['gid'] ?? '?');
                                        $file_debug[] = 'tmp_perms=' . sprintf('%o', $st['mode'] & 0777);
                                    } else {
                                        $file_debug[] = 'tmp_stat=failed';
                                    }
                                } else {
                                    $file_debug[] = 'tmp_exists=' . (file_exists($file['tmp_name']) ? 'yes' : 'no');
                                }

                                $dstSt = @stat($uploadRoot);
                                if ($dstSt) {
                                    $file_debug[] = 'uploadRoot_owner=' . ($dstSt['uid'] ?? '?') . '/' . ($dstSt['gid'] ?? '?');
                                    $file_debug[] = 'uploadRoot_perms=' . sprintf('%o', $dstSt['mode'] & 0777);
                                } else {
                                    $file_debug[] = 'uploadRoot_stat=failed';
                                }

                                if (function_exists('posix_geteuid')) {
                                    $euid = posix_geteuid();
                                    $egid = posix_getegid();
                                    $file_debug[] = 'proc_euid=' . $euid . '/' . $egid;
                                    if (function_exists('posix_getpwuid')) {
                                        $pw = posix_getpwuid($euid);
                                        if ($pw) { $file_debug[] = 'proc_user=' . ($pw['name'] ?? $euid); }
                                    }
                                }
                                $file_debug[] = 'php_upload_tmp_dir=' . ini_get('upload_tmp_dir');
                                $file_debug[] = 'sys_temp_dir=' . sys_get_temp_dir();
                                $file_debug[] = 'open_basedir=' . ini_get('open_basedir');

                                $fallback_ok = false;
                                if (!empty($file['tmp_name']) && file_exists($file['tmp_name'])) {
                                    $fallback_ok = @rename($file['tmp_name'], $dest);
                                    if (!$fallback_ok) {
                                        $fallback_ok = @copy($file['tmp_name'], $dest) && @unlink($file['tmp_name']);
                                        $file_debug[] = 'fallback_copy=' . ($fallback_ok ? 'success' : 'failed');
                                    } else {
                                        $file_debug[] = 'fallback_rename=success';
                                    }
                                }

                                $lastErr = error_get_last();
                                if ($lastErr) {
                                    $file_debug[] = 'err=' . trim($lastErr['message']);
                                }
                                error_log('[ANNOUNCEMENTS] move_uploaded_file failed: ' . implode(' | ', $file_debug));
                                if ($fallback_ok) {
                                    $urlPath = '/assets/uploads/announcements/' . $filename;
                                    $announcement_image_upload_snippet = '[image]' . full_url($urlPath) . '[/image]';
                                    $flash_success = 'Resim yüklendi (fallback ile). İçeriğe yapıştırmak için aşağıdaki kodu kullanın.';
                                } else {
                                    $flash_error = 'Dosya kaydedilirken bir hata oluştu. Lütfen dizin izinlerini kontrol edin.';
                                }
                            }
                        }
                    }
                }
            } else {
                $flash_error = 'Lütfen yüklemek için bir resim seçin.';
            }

            if (!empty($flash_error)) {
                $_SESSION['flash_error'] = $flash_error;
            } elseif (!empty($flash_success)) {
                $_SESSION['flash'] = $flash_success;
            }

            $edit_announcement = array_merge($edit_announcement ?? [], [
                'title' => $title,
                'summary' => $summary,
                'content' => $content,
                'sources' => $sources,
                'is_active' => $is_active,
            ]);
            if (isset($_POST['id'])) {
                $edit_announcement['id'] = (int)$_POST['id'];
            }
        }

        // Preview requested? Render preview server-side immediately (no JS)
        if (!$is_upload_action && isset($_POST['preview'])) {
            if (empty($title) || empty($summary) || empty($content)) {
                $_SESSION['flash_error'] = 'Tüm alanlar gerekli.';
            } else {
                $preview = [
                    'title' => $title,
                    'summary' => $summary,
                    'content' => $content,
                    'sources' => $sources,
                    'is_active' => $is_active,
                ];
                // keep form populated for the user to continue editing
                $edit_announcement = array_merge($edit_announcement ?? [], [
                    'title' => $title,
                    'summary' => $summary,
                    'content' => $content,
                    'sources' => $sources,
                    'is_active' => $is_active,
                ]);
                if (isset($_POST['id'])) {
                    $edit_announcement['id'] = (int)$_POST['id'];
                }
            }
        } elseif (!$is_upload_action) {
            $isDraft = ($save_as === 'draft');

        if (!$isDraft && (empty($title) || empty($summary) || empty($content))) {
                $_SESSION['flash_error'] = 'Tüm alanlar gerekli.';
            } else {
                // Generate SEO-friendly slug from title
                $slug = generate_slug($title);
                // If title is empty or slug generation fails, create a safe fallback.
                if (empty($slug)) {
                    $slug = 'announcement-' . time() . '-' . bin2hex(random_bytes(4));
                }
                // Ensure uniqueness: append -2, -3 etc. if slug already exists
                $base_slug = $slug;
                $suffix = 1;
                $exclude_id = ($_POST['action'] === 'edit') ? (int)$_POST['id'] : 0;
                while (true) {
                    $chk = $db->prepare("SELECT id FROM announcements WHERE slug = ? AND id != ?");
                    $chk->execute([$slug, $exclude_id]);
                    if (!$chk->fetch()) break;
                    $suffix++;
                    $slug = $base_slug . '-' . $suffix;
                }

                $send_email = isset($_POST['send_email']) ? 1 : 0;
        $send_at = !empty($_POST['send_at']) ? trim($_POST['send_at']) : null;
        $send_at_ts = null;
        if ($send_at) {
            $send_at_ts = date('Y-m-d H:i:s', strtotime($send_at));
        }

        $email_status = (!$isDraft && $send_email) ? 'queued' : null;

        if ($_POST['action'] === 'create') {
                    if ($has_sources_column) {
                        $stmt = $db->prepare("INSERT INTO announcements (title, slug, summary, content, sources, created_by, is_active, send_email, email_status, send_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                        $stmt->execute([$title, $slug, $summary, $content, $sources, $current_user_id, $is_active, $send_email, $email_status, $send_at_ts]);
                    } else {
                        $stmt = $db->prepare("INSERT INTO announcements (title, slug, summary, content, created_by, is_active, send_email, email_status, send_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                        $stmt->execute([$title, $slug, $summary, $content, $current_user_id, $is_active, $send_email, $email_status, $send_at_ts]);
                    }
                    $announcement_id = $db->lastInsertId();
                    $_SESSION['flash'] = 'Duyuru başarıyla oluşturuldu.';
                } else {
                    $id = (int)$_POST['id'];
                    if ($has_sources_column) {
                        $stmt = $db->prepare("UPDATE announcements SET title = ?, slug = ?, summary = ?, content = ?, sources = ?, is_active = ?, send_email = ?, email_status = ?, send_at = ? WHERE id = ?");
                        $stmt->execute([$title, $slug, $summary, $content, $sources, $is_active, $send_email, $email_status, $send_at_ts, $id]);
                    } else {
                        $stmt = $db->prepare("UPDATE announcements SET title = ?, slug = ?, summary = ?, content = ?, is_active = ?, send_email = ?, email_status = ?, send_at = ? WHERE id = ?");
                        $stmt->execute([$title, $slug, $summary, $content, $is_active, $send_email, $email_status, $send_at_ts, $id]);
                    }
                    $announcement_id = $id;
                    $_SESSION['flash'] = 'Duyuru başarıyla güncellendi.';
                }

                // Queue announcement emails for processing if requested
                if (!$isDraft && $send_email && $announcement_id) {
                    $next_attempt = $send_at_ts ? $send_at_ts : date('Y-m-d H:i:s');
                    $insertSql = "INSERT IGNORE INTO announcement_emails (announcement_id, user_id, email, status, attempts, next_attempt_at) "
                        . "SELECT ?, id, email, 'queued', 0, ? FROM users WHERE email_verified = 1 AND deleted_at IS NULL";
                    $stmt = $db->prepare($insertSql);
                    $stmt->execute([$announcement_id, $next_attempt]);

                    // If no scheduled time (or it's now/past), trigger the worker immediately
                    if (!$send_at_ts || strtotime($send_at_ts) <= time()) {
                        run_announcement_email_worker(20);
                    }
                }

                header('Location: ' . BASE_PATH . '/admin/announcements.php');
                exit;
            }
        }
    } elseif ($_POST['action'] === 'delete') {
        if (empty($_POST['confirm_delete'])) {
            $_SESSION['flash_error'] = 'Duyuruyu silmek için onay kutusunu işaretleyin.';
            header('Location: ' . BASE_PATH . '/admin/announcements.php');
            exit;
        }
        $id = (int)$_POST['id'];
        $db->prepare("DELETE FROM announcements WHERE id = ?")->execute([$id]);
        $_SESSION['flash'] = 'Duyuru silindi.';
        header('Location: ' . BASE_PATH . '/admin/announcements.php');
        exit;
    } elseif ($_POST['action'] === 'retry_announcement_emails') {
        if (empty($_POST['confirm_send'])) {
            $_SESSION['flash_error'] = 'E-posta gönderimini başlatmak için onay kutusunu işaretleyin.';
            $id = (int)($_POST['id'] ?? 0);
            header('Location: ' . BASE_PATH . '/admin/announcements.php?action=edit&id=' . $id);
            exit;
        }
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            // Add any users not yet in the queue (new registrations since original queue build)
            $db->prepare(
                "INSERT IGNORE INTO announcement_emails (announcement_id, user_id, email, status, attempts, next_attempt_at) "
                . "SELECT ?, u.id, u.email, 'queued', 0, NOW() FROM users u WHERE u.email_verified=1 AND u.deleted_at IS NULL"
            )->execute([$id]);

            // Check current state before resetting
            $chk = $db->prepare("SELECT status, COUNT(*) AS cnt FROM announcement_emails WHERE announcement_id=? GROUP BY status");
            $chk->execute([$id]);
            $statusCounts = [];
            while ($row = $chk->fetch(PDO::FETCH_ASSOC)) {
                $statusCounts[$row['status']] = (int)$row['cnt'];
            }
            $totalRows   = array_sum($statusCounts);
            $alreadySent = $statusCounts['sent'] ?? 0;
            $pendingRows = ($statusCounts['queued'] ?? 0) + ($statusCounts['failed'] ?? 0) + ($statusCounts['sending'] ?? 0);

            if ($totalRows === 0) {
                // No rows at all — queue was never built; build it now
                $db->prepare(
                    "INSERT IGNORE INTO announcement_emails (announcement_id, user_id, email, status, attempts, next_attempt_at) "
                    . "SELECT ?, u.id, u.email, 'queued', 0, NOW() FROM users u WHERE u.email_verified=1 AND u.deleted_at IS NULL"
                )->execute([$id]);
                $db->prepare("UPDATE announcements SET email_status='queued' WHERE id=?")->execute([$id]);
                $workerResult = run_announcement_email_worker(20);
                $msg = $workerResult['ok']
                    ? ('Kuyruk oluşturuldu ve gönderim başladı. Gönderilen: ' . ($workerResult['sent'] ?? 0) . ', Başarısız: ' . ($workerResult['failed'] ?? 0))
                    : ('Worker hatası: ' . ($workerResult['message'] ?? '?'));
            } elseif ($pendingRows === 0 && $alreadySent > 0) {
                // All rows already sent — just fix the stuck aggregate status
                $db->prepare("UPDATE announcements SET email_status='sent' WHERE id=?")->execute([$id]);
                $msg = 'Tüm e-postalar zaten gönderilmiş (' . $alreadySent . ' alıcı). Durum güncellendi: gönderildi.';
            } else {
                // Reset failed/stuck/sending rows so they are retried
                $db->prepare(
                    "UPDATE announcement_emails SET status='queued', attempts=0, next_attempt_at=NOW(), last_error=NULL "
                    . "WHERE announcement_id=? AND status IN ('failed','queued','sending')"
                )->execute([$id]);
                $db->prepare("UPDATE announcements SET email_status='queued' WHERE id=?")->execute([$id]);
                $workerResult = run_announcement_email_worker(20);
                $msg = $workerResult['ok']
                    ? ('E-posta gönderimi başlatıldı. Gönderilen: ' . ($workerResult['sent'] ?? 0) . ', Başarısız: ' . ($workerResult['failed'] ?? 0) . ' (Toplam kuyruk: ' . $totalRows . ')')
                    : ('Worker hatası: ' . ($workerResult['message'] ?? '?'));
            }
            $_SESSION['flash'] = $msg;
        }
        header('Location: ' . BASE_PATH . '/admin/announcements.php?action=edit&id=' . $id);
        exit;
    } elseif ($_POST['action'] === 'resend_announcement_emails') {
        if (empty($_POST['confirm_resend'])) {
            $_SESSION['flash_error'] = 'Herkese tekrar göndermek için onay kutusunu işaretleyin.';
            $id = (int)($_POST['id'] ?? 0);
            header('Location: ' . BASE_PATH . '/admin/announcements.php?action=edit&id=' . $id);
            exit;
        }
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            // Reset ALL rows (including already sent) so everyone receives the email again
            $db->prepare(
                "UPDATE announcement_emails SET status='queued', attempts=0, next_attempt_at=NOW(), last_error=NULL, sent_at=NULL "
                . "WHERE announcement_id=?"
            )->execute([$id]);
            // Also add any users registered after the original queue was built
            $db->prepare(
                "INSERT IGNORE INTO announcement_emails (announcement_id, user_id, email, status, attempts, next_attempt_at) "
                . "SELECT ?, u.id, u.email, 'queued', 0, NOW() FROM users u WHERE u.email_verified=1 AND u.deleted_at IS NULL"
            )->execute([$id]);
            $db->prepare("UPDATE announcements SET email_status='queued' WHERE id=?")->execute([$id]);
            $workerResult = run_announcement_email_worker(20);
            $msg = $workerResult['ok']
                ? ('Yeniden gönderim başlatıldı. Gönderilen: ' . ($workerResult['sent'] ?? 0) . ', Başarısız: ' . ($workerResult['failed'] ?? 0))
                : ('Worker hatası: ' . ($workerResult['message'] ?? '?'));
            $_SESSION['flash'] = $msg;
        }
        header('Location: ' . BASE_PATH . '/admin/announcements.php?action=edit&id=' . $id);
        exit;
    }
}

// Get announcements
$stmt = $db->query("SELECT a.*, u.username FROM announcements a JOIN users u ON a.created_by = u.id ORDER BY a.created_at DESC");
$announcements = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get announcement for editing
$edit_announcement = null;
if ($action === 'edit' && $announcement_id > 0) {
    $stmt = $db->prepare("SELECT * FROM announcements WHERE id = ?");
    $stmt->execute([$announcement_id]);
    $edit_announcement = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($edit_announcement && !isset($edit_announcement['sources'])) {
        $edit_announcement['sources'] = '';
    }
}
// Preview data when user requests a preview (server-side, no JS)
$preview = null;

// Support preview via GET ?action=preview&id=... (preview saved announcement)
if ($action === 'preview' && $announcement_id > 0) {
    try {
        $stmt = $db->prepare("SELECT * FROM announcements WHERE id = ? LIMIT 1");
        $stmt->execute([$announcement_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $preview = $row;
            if (!isset($preview['sources'])) $preview['sources'] = '';
            // Populate edit form so template shows preview + form
            $edit_announcement = $preview;
            // Treat this as an edit view so the create/edit template branch renders
            $action = 'edit';
        } else {
            $_SESSION['flash_error'] = 'Duyuru bulunamadı.';
            header('Location: ' . BASE_PATH . '/admin/announcements.php');
            exit;
        }
    } catch (Exception $e) {
        $_SESSION['flash_error'] = 'Önizleme yüklenirken hata oluştu.';
        header('Location: ' . BASE_PATH . '/admin/announcements.php');
        exit;
    }
}

// Generate CSRF token for forms
$csrf_token = generate_csrf_token();

// Include header / admin navigation after server-side processing
require_once __DIR__ . '/_header.php';

if ($action === 'celebrations') {
    require_once __DIR__ . '/_nav.php';
    ?>
        <style>
            .celebration-form { display: grid; gap: 0; }
            .celebration-form-body { padding: 20px; display: grid; gap: 16px; }
            .celebration-form .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
            .celebration-color-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
            .color-field-group { display: flex; align-items: center; gap: 8px; }
            .color-field-group input[type="color"] { width: 44px; height: 38px; padding: 2px; border: 1px solid #ddd; border-radius: 4px; cursor: pointer; flex-shrink: 0; }
            .color-field-group input[type="text"] { flex: 1; }
            .celebration-checkbox-row { display: flex; align-items: center; gap: 8px; height: 38px; margin-top: 26px; }
            .celebration-checkbox-row input[type="checkbox"] { width: 16px; height: 16px; cursor: pointer; }
            .celebration-badge { display: inline-flex; align-items: center; gap: 6px; padding: 5px 10px; border-radius: 999px; font-size: 12px; font-weight: 600; }
            .celebration-actions { display: flex; gap: 6px; flex-wrap: wrap; align-items: center; }
            .celebration-actions a.btn-edit { border: 1px solid #d1d5db; border-radius: 5px; background: #fff; color: #111827; padding: 6px 12px; text-decoration: none; font-size: 12px; }
            .celebration-actions a.btn-edit:hover { background: #f3f4f6; }
            .celebration-actions button.delete { border: 1px solid #dc2626; border-radius: 5px; background: #fff5f5; color: #b91c1c; padding: 6px 12px; font-size: 12px; cursor: pointer; }
            .celebration-actions button.delete:hover { background: #fee2e2; }
            .celebration-status-badge { display: inline-block; padding: 3px 8px; border-radius: 3px; font-size: 11px; font-weight: 700; text-transform: uppercase; }
            .celebration-status-badge.active { background: #e6ffed; color: #1d6a3f; border: 1px solid #a7f0c8; }
            .celebration-status-badge.inactive { background: #f3f4f6; color: #6b7280; border: 1px solid #d1d5db; }
            .celebration-text-cell { max-width: 280px; white-space: pre-wrap; word-break: break-word; font-size: 13px; }
        </style>
        <div class="admin-page">
            <h1 class="page-title">🎉 Kutlamalar</h1>

            <?php if (!empty($celebration_errors)): ?>
                <div class="alert alert-error">
                    <?= htmlspecialchars(implode(' ', $celebration_errors)) ?>
                </div>
            <?php endif; ?>

            <div class="section">
                <h2><?= $celebration_edit_id > 0 ? '✏️ Kutlamayı Düzenle' : '➕ Yeni Kutlama Ekle' ?></h2>
                <form method="post" class="celebration-form">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                    <input type="hidden" name="celebration_action" value="save">
                    <input type="hidden" name="celebration_id" value="<?= (int)$celebration_edit_id ?>">

                    <div class="celebration-form-body">
                        <div class="form-group">
                            <label for="celebration_text">Kutlama Metni</label>
                            <textarea id="celebration_text" name="celebration_text" class="form-control" rows="4" required><?= htmlspecialchars($celebration_text) ?></textarea>
                            <span class="helper-text">Yeni satır eklemek için Enter tuşunu kullanın. Metin satırları banner'da ayrı satırlar olarak gösterilecektir.</span>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="celebration_date">Kutlama Tarihi</label>
                                <input id="celebration_date" type="date" name="celebration_date" class="form-control" value="<?= htmlspecialchars($celebration_date) ?>" required>
                            </div>
                            <div class="form-group">
                                <label for="celebration_is_active">Durum</label>
                                <div class="celebration-checkbox-row">
                                    <input id="celebration_is_active" type="checkbox" name="celebration_is_active" value="1" <?= $celebration_is_active ? 'checked' : '' ?>>
                                    <label for="celebration_is_active" style="margin:0; font-weight:400; cursor:pointer;">Bugün göster</label>
                                </div>
                            </div>
                        </div>

                        <div class="celebration-color-row">
                            <div class="form-group">
                                <label for="celebration_text_color">Metin Rengi</label>
                                <div class="color-field-group">
                                    <input type="color" id="celebration_text_color" name="celebration_text_color" value="<?= htmlspecialchars($celebration_text_color) ?>">
                                    <span class="helper-text muted"><?= htmlspecialchars($celebration_text_color) ?></span>
                                </div>
                            </div>
                            <div class="form-group">
                                <label for="celebration_background_color">Arka Plan Rengi</label>
                                <div class="color-field-group">
                                    <input type="color" id="celebration_background_color" name="celebration_background_color" value="<?= htmlspecialchars($celebration_background_color) ?>">
                                    <span class="helper-text muted"><?= htmlspecialchars($celebration_background_color) ?></span>
                                </div>
                            </div>
                        </div>

                        <div class="form-group" style="margin-bottom:0;">
                            <button type="submit" class="btn btn-approve"><?= $celebration_edit_id > 0 ? 'Güncelle' : 'Kaydet' ?></button>
                            <?php if ($celebration_edit_id > 0): ?>
                                <a href="<?= BASE_PATH ?>/admin/announcements.php?action=celebrations" class="btn" style="margin-left:8px; background:#f3f4f6; color:#374151;">İptal</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </form>
            </div>

            <div class="section">
                <h2>Mevcut Kutlamalar</h2>
                <div class="admin-table-wrapper">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th style="width:110px;">Tarih</th>
                                <th>Metin</th>
                                <th style="width:90px;">Önizleme</th>
                                <th style="width:80px;">Durum</th>
                                <th style="width:140px;">İşlemler</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($celebrations)): ?>
                                <tr>
                                    <td colspan="5" style="text-align:center; color:#9ca3af; padding:30px 14px;">Henüz kutlama eklenmemiş.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($celebrations as $celebration): ?>
                                    <tr>
                                        <td><?= htmlspecialchars(date('d.m', strtotime($celebration['display_date']))) ?></td>
                                        <td class="celebration-text-cell"><?= htmlspecialchars($celebration['text']) ?></td>
                                        <td>
                                            <span class="celebration-badge" style="background: <?= htmlspecialchars($celebration['background_color']) ?>; color: <?= htmlspecialchars($celebration['text_color']) ?>;">Örnek</span>
                                        </td>
                                        <td>
                                            <span class="celebration-status-badge <?= $celebration['is_active'] ? 'active' : 'inactive' ?>">
                                                <?= $celebration['is_active'] ? 'Aktif' : 'Pasif' ?>
                                            </span>
                                        </td>
                                        <td class="celebration-actions">
                                            <a href="<?= BASE_PATH ?>/admin/announcements.php?action=celebrations&id=<?= (int)$celebration['id'] ?>" class="btn-edit">Düzenle</a>
                                            <form method="post" style="display:inline-block; margin:0;">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                                <input type="hidden" name="celebration_action" value="delete">
                                                <input type="hidden" name="celebration_id" value="<?= (int)$celebration['id'] ?>">
                                                <label style="display:flex;align-items:center;gap:4px;font-size:11px;margin-bottom:4px;">
                                                    <input type="checkbox" name="confirm_delete" value="1" required>
                                                    Onay
                                                </label>
                                                <button type="submit" class="delete">Sil</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php
    require_once __DIR__ . '/_footer.php';
    exit;
}
?>
        <style>
            /* Fallback admin styling (applies even if external CSS fails to load) */
            .admin-page { max-width: 1200px; margin: 0 auto; padding: 20px; font-family: system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial, sans-serif; }
            .page-title { font-size: 28px; margin: 0 0 14px; }
            a { color: #5a9a3c; text-decoration: none; }
            a:hover { text-decoration: underline; }
            .btn { display: inline-block; padding: 8px 14px; border-radius: 4px; font-weight: 700; text-decoration: none; cursor: pointer; }
            .btn-approve { background: #5a9a3c; color: #fff !important; }
            .btn-cancel { background: #ddd; color: #333; }
            .section { border: 1px solid #e0e0e0; border-radius: 6px; background: #fff; box-shadow: 0 2px 6px rgba(0,0,0,0.06); padding: 0; margin-bottom: 22px; }
            .section h2 { margin: 0; padding: 15px 18px; background: #fafafa; border-bottom: 1px solid #e0e0e0; font-size: 16px; }
            .admin-table { width: 100%; border-collapse: collapse; margin-top: 12px; }
            .admin-table th, .admin-table td { padding: 12px 14px; border-bottom: 1px solid #f0f0f0; }
            .admin-table th { background: #fafafa; text-transform: uppercase; font-size: 12px; color: #666; }
            .badge { display: inline-block; padding: 3px 8px; border-radius: 3px; font-size: 10px; font-weight: 700; text-transform: uppercase; }
            .badge-premium { background: #5a9a3c; color: #fff; }
            .badge-rookie { background: #fff3cd; color: #856404; }
            .alert { padding: 12px 14px; border-radius: 4px; margin-bottom: 16px; }
            .alert-success { background: #e6ffed; border: 1px solid #a7f0c8; color: #1d6a3f; }
            .alert-error { background: #ffe6e6; border: 1px solid #f0a7a7; color: #8a1d1d; }
            .helper-text { color: #666; font-size: 12px; margin-top: 4px; display: block; }
            .form-group { margin-bottom: 16px; }
            .form-control { width: 100%; padding: 10px 12px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px; }
            .flex-row { display: flex; flex-wrap: wrap; gap: 10px; }
        </style>
        <div class="admin-page">
            <h1 class="page-title">📢 Duyurular</h1>

            <?php if (isset($_SESSION['flash'])): ?>
                <div class="alert alert-success">
                    <?= htmlspecialchars($_SESSION['flash']) ?>
                </div>
                <?php unset($_SESSION['flash']); ?>
            <?php endif; ?>

            <?php if (isset($_SESSION['flash_error'])): ?>
                <div class="alert alert-error">
                    <?= htmlspecialchars($_SESSION['flash_error']) ?>
                </div>
                <?php unset($_SESSION['flash_error']); ?>
            <?php endif; ?>

            <?php if ($action === 'create' || $action === 'edit'): ?>
                <!-- Create/Edit Form -->
                <div class="section">
                    <h2><?= $action === 'create' ? '➕ Yeni Duyuru Ekle' : '✏️ Duyuruyu Düzenle' ?></h2>
                    
                    <?php if ($preview): ?>
                        <div class="section" style="border-color:#d0f0d8;">
                            <h2>🔎 Önizleme</h2>
                            <div style="padding:16px;">
                                <h3 style="margin:0 0 8px;"><?= htmlspecialchars($preview['title']) ?></h3>
                                <div style="color:#666;margin-bottom:12px;"><?= render_rich_text($preview['summary'] ?? '') ?></div>
                                <div style="background:#fafafa;border:1px solid #eee;padding:12px;border-radius:4px;"><?= render_rich_text($preview['content'] ?? '') ?></div>
                                <?php if (!empty($preview['sources'])): ?>
                                    <div style="margin-top:10px;font-size:13px;color:#444;">
                                            <strong>Kaynaklar</strong>
                                        <div style="margin-top:6px;"><?= render_rich_text($preview['sources']) ?></div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <form id="announcement-edit-form" method="POST" enctype="multipart/form-data" class="form-padded">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                        <input type="hidden" name="action" value="<?= $action ?>">
                        <?php if ($edit_announcement): ?>
                            <input type="hidden" name="id" value="<?= $edit_announcement['id'] ?>">
                        <?php endif; ?>

                        <div class="form-group">
                            <label>Başlık</label>
                            <input type="text" name="title" class="form-control" value="<?= $edit_announcement ? htmlspecialchars($edit_announcement['title']) : '' ?>">
                        </div>

                        <div class="form-group">
                            <label>Özet</label>
                            <textarea name="summary" class="form-control" rows="3"><?= $edit_announcement ? htmlspecialchars($edit_announcement['summary']) : '' ?></textarea>
                            <small class="helper-text muted">Ana sayfada gösterilecek kısa açıklama (2-3 satır)</small>
                        </div>

                        <div class="form-group">
                            <label>İçerik (Blog)</label>
                            <textarea name="content" class="form-control" rows="10"><?= $edit_announcement ? htmlspecialchars($edit_announcement['content']) : '' ?></textarea>
                            <small class="helper-text muted">Tam blog içeriği - tıklandığında gösterilecek</small>
                        </div>

                        <div class="form-group">
                            <label>Resim Yükle</label>
                            <input type="file" name="announcement_image" accept="image/jpeg,image/png,image/gif,image/webp" class="form-control">
                            <button type="submit" name="upload_image" value="1" class="btn btn-approve" style="margin-top:8px;">📤 Resmi Yükle</button>
                            <small class="helper-text muted">Yükledikten sonra oluşan kodu içerik alanına yapıştırın: [image]URL[/image]</small>
                        </div>

                        <?php if (!empty($announcement_image_upload_snippet)): ?>
                            <div class="form-group">
                                <label>Resim Embed Kodu</label>
                                <textarea readonly class="form-control" rows="2"><?= htmlspecialchars($announcement_image_upload_snippet) ?></textarea>
                                <small class="helper-text muted">Bu kodu içerik alanına yapıştırarak resmi duyuruya ekleyebilirsiniz.</small>
                            </div>
                        <?php endif; ?>

                        <div class="form-group">
                            <label>Kaynaklar</label>
                            <textarea name="sources" class="form-control" rows="3"><?= $edit_announcement ? htmlspecialchars($edit_announcement['sources'] ?? '') : '' ?></textarea>
                            <small class="helper-text muted">Her satır bir kaynak olabilir. Linkler için [link url="https://..."]metin[/link] kullanabilirsiniz.</small>
                        </div>

                        <div class="form-group">
                            <label><input type="checkbox" name="send_email" value="1" <?= isset($edit_announcement['send_email']) && $edit_announcement['send_email'] ? 'checked' : '' ?>> E-posta olarak da gönder</label>
                            <small class="helper-text muted">Seçiliyse bu duyuru mail gönderim kuyruğuna alınır.</small>
                        </div>

                        <div class="form-group">
                            <label>Gönderim zamanı</label>
                            <input type="datetime-local" name="send_at" class="form-control" value="<?= isset($edit_announcement['send_at']) ? htmlspecialchars(date('Y-m-d\TH:i', strtotime($edit_announcement['send_at']))) : '' ?>">
                            <small class="helper-text muted">Boş bırakırsanız hemen kuyruğa eklenir.</small>
                        </div>

                        <?php if (!empty($edit_announcement['id']) && !empty($edit_announcement['send_email'])): ?>
                        <?php
                            $es = $edit_announcement['email_status'] ?? null;
                            $emailStatusLabels = ['queued' => '⏳ Kuyrukta', 'sending' => '📨 Gönderiliyor', 'sent' => '✅ Gönderildi', 'failed' => '❌ Başarısız'];
                            $emailStatusColors = ['queued' => '#856404', 'sending' => '#0c5460', 'sent' => '#155724', 'failed' => '#721c24'];
                            $esLabel = $emailStatusLabels[$es] ?? ($es ? htmlspecialchars($es) : '—');
                            $esColor = $emailStatusColors[$es] ?? '#333';
                        ?>
                        <div class="form-group">
                            <label>E-posta Gönderim Durumu</label>
                            <div style="margin-bottom:8px;">
                                <strong style="color:<?= $esColor ?>;"><?= $esLabel ?></strong>
                            </div>
                            <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;">
                            <?php if (in_array($es, ['queued', 'failed', 'sending'], true)): ?>
                                <form method="POST" style="margin:0;padding:8px;border:1px solid #c3e6cb;border-radius:6px;background:#f0fff4;">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                    <input type="hidden" name="action" value="retry_announcement_emails">
                                    <input type="hidden" name="id" value="<?= (int)$edit_announcement['id'] ?>">
                                    <label style="display:flex;align-items:center;gap:6px;font-size:12px;margin-bottom:6px;">
                                        <input type="checkbox" name="confirm_send" value="1" required>
                                        E-posta gönderimini şimdi başlatmayı onaylıyorum
                                    </label>
                                    <button type="submit" class="btn btn-approve">📤 Şimdi Gönder / Yeniden Dene</button>
                                </form>
                            <?php endif; ?>
                                <form method="POST" style="margin:0;padding:8px;border:1px solid #f5c6cb;border-radius:6px;background:#fff5f5;">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                    <input type="hidden" name="action" value="resend_announcement_emails">
                                    <input type="hidden" name="id" value="<?= (int)$edit_announcement['id'] ?>">
                                    <label style="display:flex;align-items:center;gap:6px;font-size:12px;margin-bottom:6px;">
                                        <input type="checkbox" name="confirm_resend" value="1" required>
                                        Herkese (gönderilmişler dahil) tekrar göndermeyi onaylıyorum
                                    </label>
                                    <button type="submit" class="btn btn-revoke">🔁 Herkese Tekrar Gönder</button>
                                </form>
                            </div>
                            <small class="helper-text muted" style="display:block;margin-top:4px;">
                                <strong>Şimdi Gönder:</strong> Sadece bekleyen/başarısız olanları sıfırlar.
                                &nbsp;|&nbsp;
                                <strong>Herkese Tekrar Gönder:</strong> Tüm alıcılara (gönderilmiş dahil) yeniden gönderir.
                            </small>
                        </div>
                        <?php endif; ?>

                        <div class="form-group">
                            <label>Biçimlendirme (kısa not)</label>
                            <pre class="helper-text" style="background:#f5f5f5;border:1px solid #ddd;padding:8px;white-space:pre-wrap;font-family:monospace;font-size:0.9em;">
[b]Kalın[/b]
[i]İtalik[/i]
[h2]Başlık[/h2]
[link url="https://example.com"]Bağlantı[/link]
- Liste maddesi
                            </pre>
                        </div>

                        <div class="form-group">
                            <label>
                                <input type="checkbox" name="is_active" value="1" <?= !$edit_announcement || $edit_announcement['is_active'] ? 'checked' : '' ?>>
                                Aktif
                            </label>
                        </div>

                        <div class="flex-row">
                            <button type="submit" name="save_as" value="publish" class="btn btn-approve">💾 Yayınla</button>
                            <button type="submit" name="save_as" value="draft" class="btn btn-cancel">⏳ Taslak Olarak Kaydet</button>
                            <button type="submit" name="preview" value="1" class="btn btn-approve" style="color:#fff">👁️ Önizle</button>
                            <a href="<?= BASE_PATH ?>/admin/announcements.php" class="btn btn-cancel">İptal</a>
                        </div>
                    </form>

                </div>
            <?php else: ?>
                <!-- List View -->
                <div class="mb-20">
                    <a href="<?= BASE_PATH ?>/admin/announcements.php?action=create" class="btn btn-approve">+ Yeni Duyuru</a>
                </div>

                <div class="section">
                    <h2>Duyurular (<?= count($announcements) ?>)</h2>

                    <?php if (empty($announcements)): ?>
                        <div class="empty-state">Henüz duyuru eklenmemiş.</div>
                    <?php else: ?>
                        <div class="admin-table-wrapper">
                            <table class="admin-table">
                                <thead>
                                    <tr>
                                        <th>Başlık</th>
                                        <th>Oluşturan</th>
                                        <th>Durum</th>
                                        <th>E-posta</th>
                                        <th>Gönderim Zamanı</th>
                                        <th>Tarih</th>
                                        <th>İşlemler</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($announcements as $ann): ?>
                                        <tr>
                                            <td><a href="<?= BASE_PATH ?>/admin/announcements.php?action=edit&id=<?= $ann['id'] ?>" style="color:#0c5460; text-decoration:none;"><strong><?= htmlspecialchars($ann['title']) ?></strong></a></td>
                                            <td>@<?= htmlspecialchars($ann['username']) ?></td>
                                            <td>
                                                <?php if ($ann['is_active']): ?>
                                                    <span class="badge badge-premium">✓ Yayınlandı</span>
                                                <?php else: ?>
                                                    <span class="badge badge-rookie">⏳ Taslak</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?= $ann['send_email'] ? '✔ E-posta' : '—' ?> <br>
                                                <?= $ann['email_status'] ? htmlspecialchars($ann['email_status']) : '—' ?>
                                            </td>
                                            <td><?= $ann['send_at'] ? date('d.m.Y H:i', strtotime($ann['send_at'])) : 'hemen' ?></td>
                                            <td><?= date('d.m.Y H:i', strtotime($ann['created_at'])) ?></td>
                                            <td class="admin-actions">
                                                <a href="<?= BASE_PATH ?>/admin/announcements.php?action=preview&id=<?= $ann['id'] ?>" class="btn btn-approve" style="color:#fff">👁️ Önizle</a>
                                                <a href="<?= BASE_PATH ?>/admin/announcements.php?action=edit&id=<?= $ann['id'] ?>" class="btn btn-approve" style="color:#fff">✏️ Düzenle</a>
                                                <form method="POST" class="form-inline" style="display:inline-block;vertical-align:middle;">
                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="id" value="<?= $ann['id'] ?>">
                                                    <label style="display:flex;align-items:center;gap:4px;font-size:11px;margin-bottom:4px;">
                                                        <input type="checkbox" name="confirm_delete" value="1" required>
                                                        Onay
                                                    </label>
                                                    <button type="submit" class="btn btn-revoke">🗑️ Sil</button>
                                                </form> 
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include __DIR__ . '/_footer.php'; ?>
