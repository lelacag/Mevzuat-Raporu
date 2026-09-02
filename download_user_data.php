<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$current_user_id = get_current_user_id();
if (!$current_user_id) {
    header('Location: ' . BASE_PATH . '/giris');
    exit;
}

$user = get_user($current_user_id);
if (!$user) {
    header('Location: ' . home_url());
    exit;
}

function safe_export_value($value) {
    if ($value === null) {
        return '';
    }
    if (is_bool($value)) {
        return $value ? '1' : '0';
    }
    return trim((string)$value);
}

function export_section(array $title, array $rows) {
    $lines = [];
    $lines[] = '';
    $lines[] = str_repeat('=', 72);
    $lines[] = $title[0];
    $lines[] = str_repeat('-', 72);
    foreach ($rows as $row) {
        $lines[] = sprintf('%-24s: %s', $row[0], safe_export_value($row[1]));
    }
    return $lines;
}

$filename = 'user-data-' . preg_replace('/[^A-Za-z0-9_-]/', '_', $user['username'] ?? 'user') . '-' . date('Ymd_His') . '.txt';

$lines = [];
$lines[] = 'Kullanıcı Verisi Dışa Aktarımı';
$lines[] = 'Oluşturma Tarihi: ' . date('Y-m-d H:i:s');
$lines[] = 'Kullanıcı Adı: ' . safe_export_value($user['username'] ?? '');
$lines[] = 'Kullanıcı Kimliği: ' . safe_export_value($user['id'] ?? '');

$account_fields = [
    ['E-posta', $user['email'] ?? ''],
    ['Doğum Tarihi', $user['birthday'] ?? ''],
    ['Biyografi', $user['bio'] ?? ''],
    ['Rol', $user['role'] ?? ''],
    ['Premium', !empty($user['is_premium']) ? 'Evet' : 'Hayır'],
    ['Premium Bitişi', $user['premium_until'] ?? ''],
    ['Etkinlik Kodu', $user['event_code'] ?? ''],
    ['Oluşturulma', $user['created_at'] ?? ''],
    ['Güncellenme', $user['updated_at'] ?? ''],
    ['Silinme', $user['deleted_at'] ?? ''],
    ['Askıya Alınma', $user['suspended_until'] ?? ''],
    ['Kabul Edilen Şartlar', !empty($user['accepted_terms']) ? 'Evet' : 'Hayır'],
    ['Kabul Edilen Gizlilik', !empty($user['accepted_privacy']) ? 'Evet' : 'Hayır'],
    ['Kabul Edilen KVKK', !empty($user['accepted_kvkk']) ? 'Evet' : 'Hayır'],
    ['Kabul Edilen Çerez Politikası', !empty($user['accepted_cookies']) ? 'Evet' : 'Hayır'],
    ['Şartları Kabul Tarihi', $user['accepted_terms_at'] ?? ''],
    ['E-posta Bildirimleri', !empty($user['notify_by_email']) ? 'Açık' : 'Kapalı'],
    ['Mention Bildirimi', !empty($user['notify_on_mention']) ? 'Açık' : 'Kapalı'],
    ['Yanıt Bildirimi', !empty($user['notify_on_reply']) ? 'Açık' : 'Kapalı'],
    ['Rapor Bildirimi', !empty($user['notify_on_report']) ? 'Açık' : 'Kapalı'],
    ['Sistem Bildirimi', !empty($user['notify_on_system']) ? 'Açık' : 'Kapalı'],
];

if (array_key_exists('phone_number', $user)) {
    $account_fields[] = ['Telefon Numarası', $user['phone_number'] ?? ''];
}
if (array_key_exists('phone_verified', $user)) {
    $account_fields[] = ['Telefon Doğrulandı', !empty($user['phone_verified']) ? 'Evet' : 'Hayır'];
}

$lines = array_merge($lines, export_section(['Hesap Bilgileri'], $account_fields));

$post_rows = [];
$rows = query('SELECT id, parent_id, content, likes_count, replies_count, created_at, updated_at, deleted_at FROM posts WHERE user_id = ? ORDER BY created_at DESC', [$current_user_id])->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $row) {
    $post_rows[] = ['Gönderi #' . $row['id'], str_replace("\n", ' ', mb_substr($row['content'], 0, 120))];
    $post_rows[] = ['  Oluşturulma', $row['created_at']];
    $post_rows[] = ['  Güncelleme', $row['updated_at']];
    $post_rows[] = ['  Silinme', $row['deleted_at']];
    $post_rows[] = ['  Beğeni', $row['likes_count']];
    $post_rows[] = ['  Yanıt', $row['replies_count']];
    $post_rows[] = ['  Parent ID', $row['parent_id'] ?? 'Yok'];
}
if (empty($post_rows)) {
    $post_rows[] = ['Gönderi', 'Yok'];
}
$lines = array_merge($lines, export_section(['Gönderiler'], $post_rows));

$like_rows = [];
$rows = query('SELECT l.id, l.post_id, p.user_id AS author_id, l.reaction, l.created_at FROM likes l LEFT JOIN posts p ON p.id = l.post_id WHERE l.user_id = ? ORDER BY l.created_at DESC', [$current_user_id])->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $row) {
    $like_rows[] = ['Beğeni #' . $row['id'], 'Gönderi ID: ' . $row['post_id'] . ', Yazar ID: ' . ($row['author_id'] ?? 'Bilinmiyor')];
    $like_rows[] = ['  Reaksiyon', $row['reaction']];
    $like_rows[] = ['  Oluşturulma', $row['created_at']];
}
if (empty($like_rows)) {
    $like_rows[] = ['Beğeni', 'Yok'];
}
$lines = array_merge($lines, export_section(['Beğeniler'], $like_rows));

$follow_rows = [];
$rows = query('SELECT follower_id, following_id, created_at FROM follows WHERE follower_id = ? OR following_id = ? ORDER BY created_at DESC', [$current_user_id, $current_user_id])->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $row) {
    if ($row['follower_id'] === $current_user_id) {
        $follow_rows[] = ['Takip Edilen', $row['following_id']];
    }
    if ($row['following_id'] === $current_user_id) {
        $follow_rows[] = ['Takip Eden', $row['follower_id']];
    }
}
if (empty($follow_rows)) {
    $follow_rows[] = ['Takip Bilgisi', 'Yok'];
}
$lines = array_merge($lines, export_section(['Takip Bilgileri'], $follow_rows));

$notif_rows = [];
$rows = query('SELECT id, type, from_user_id, post_id, created_at, read_at FROM notifications WHERE user_id = ? ORDER BY created_at DESC', [$current_user_id])->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $row) {
    $notif_rows[] = ['Bildirim #' . $row['id'], $row['type']];
    $notif_rows[] = ['  Gönderen', $row['from_user_id'] ?? 'Yok'];
    $notif_rows[] = ['  Gönderi', $row['post_id'] ?? 'Yok'];
    $notif_rows[] = ['  Oluşturulma', $row['created_at']];
    $notif_rows[] = ['  Okunma', $row['read_at'] ?? 'Yok'];
}
if (empty($notif_rows)) {
    $notif_rows[] = ['Bildirim', 'Yok'];
}
$lines = array_merge($lines, export_section(['Bildirimler'], $notif_rows));

$badge_rows = [];
$rows = query('SELECT badge_text, badge_color, status, created_at FROM user_custom_badges WHERE user_id = ? ORDER BY created_at DESC', [$current_user_id])->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $row) {
    $badge_rows[] = ['Özel Rozet', $row['badge_text']];
    $badge_rows[] = ['  Renk', $row['badge_color']];
    $badge_rows[] = ['  Durum', $row['status']];
    $badge_rows[] = ['  Oluşturulma', $row['created_at']];
}
if (empty($badge_rows)) {
    $badge_rows[] = ['Özel Rozet', 'Yok'];
}
$lines = array_merge($lines, export_section(['Özel Rozetler'], $badge_rows));

$premium_rows = [];
$rows = query('SELECT plan_type, status, start_date, end_date, payment_method, created_at FROM premium_subscriptions WHERE user_id = ? ORDER BY created_at DESC', [$current_user_id])->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $row) {
    $premium_rows[] = ['Premium Abonelik', $row['plan_type']];
    $premium_rows[] = ['  Durum', $row['status']];
    $premium_rows[] = ['  Başlangıç', $row['start_date']];
    $premium_rows[] = ['  Bitiş', $row['end_date']];
    $premium_rows[] = ['  Ödeme Yöntemi', $row['payment_method'] ?? ''];
    $premium_rows[] = ['  Oluşturulma', $row['created_at']];
}
if (empty($premium_rows)) {
    $premium_rows[] = ['Premium Abonelik', 'Yok'];
}
$lines = array_merge($lines, export_section(['Premium Abonelikler'], $premium_rows));

$ip_rows = [];
$rows = query('SELECT ip_address, created_at FROM user_ip_history WHERE user_id = ? ORDER BY created_at DESC LIMIT 100', [$current_user_id])->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $row) {
    $ip_rows[] = ['IP Adresi', $row['ip_address']];
    $ip_rows[] = ['  Kaydedilme', $row['created_at']];
}
if (empty($ip_rows)) {
    $ip_rows[] = ['IP Geçmişi', 'Yok'];
}
$lines = array_merge($lines, export_section(['IP Geçmişi'], $ip_rows));

$report_rows = [];
$rows = query('SELECT id, target_type, target_id, reason, created_at FROM reports WHERE reporter_id = ? ORDER BY created_at DESC', [$current_user_id])->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $row) {
    $report_rows[] = ['Rapor #' . $row['id'], $row['target_type'] . ' #' . $row['target_id']];
    $report_rows[] = ['  Sebep', $row['reason']];
    $report_rows[] = ['  Oluşturulma', $row['created_at']];
}
if (empty($report_rows)) {
    $report_rows[] = ['Raporlar', 'Yok'];
}
$lines = array_merge($lines, export_section(['Raporlar'], $report_rows));

$event_rows = [];
$rows = query('SELECT id, title, event_date, is_active, created_at FROM events WHERE created_by = ? ORDER BY created_at DESC', [$current_user_id])->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $row) {
    $event_rows[] = ['Etkinlik #' . $row['id'], $row['title']];
    $event_rows[] = ['  Tarih', $row['event_date']];
    $event_rows[] = ['  Aktif', $row['is_active'] ? 'Evet' : 'Hayır'];
    $event_rows[] = ['  Oluşturulma', $row['created_at']];
}
if (empty($event_rows)) {
    $event_rows[] = ['Etkinlikler', 'Yok'];
}
$lines = array_merge($lines, export_section(['Etkinlikler'], $event_rows));

$event_comment_rows = [];
$rows = query('SELECT id, event_id, content, likes_count, reports_count, parent_id, created_at, updated_at FROM events_comments WHERE user_id = ? ORDER BY created_at DESC', [$current_user_id])->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $row) {
    $event_comment_rows[] = ['Etkinlik Yorumu #' . $row['id'], str_replace("\n", ' ', mb_substr($row['content'], 0, 120))];
    $event_comment_rows[] = ['  Etkinlik ID', $row['event_id']];
    $event_comment_rows[] = ['  Beğeni', $row['likes_count']];
    $event_comment_rows[] = ['  Rapor', $row['reports_count']];
    $event_comment_rows[] = ['  Parent ID', $row['parent_id'] ?? 'Yok'];
    $event_comment_rows[] = ['  Oluşturulma', $row['created_at']];
    $event_comment_rows[] = ['  Güncelleme', $row['updated_at']];
}
if (empty($event_comment_rows)) {
    $event_comment_rows[] = ['Etkinlik Yorumları', 'Yok'];
}
$lines = array_merge($lines, export_section(['Etkinlik Yorumları'], $event_comment_rows));

$post_edit_rows = [];
$rows = query('SELECT id, post_id, edited_at, editor_id, previous_content, new_content FROM post_edits WHERE post_id IN (SELECT id FROM posts WHERE user_id = ?) ORDER BY edited_at DESC', [$current_user_id])->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $row) {
    $post_edit_rows[] = ['Düzenleme #' . $row['id'], 'Gönderi ID: ' . $row['post_id']];
    $post_edit_rows[] = ['  Düzenlenme', $row['edited_at']];
    $post_edit_rows[] = ['  Düzenleyen', $row['editor_id'] ?? 'Bilinmiyor'];
}
if (empty($post_edit_rows)) {
    $post_edit_rows[] = ['Gönderi Düzenlemeleri', 'Yok'];
}
$lines = array_merge($lines, export_section(['Gönderi Düzenleme Geçmişi'], $post_edit_rows));

$lines[] = '';
$lines[] = 'Dışa aktarma tamamlandı. Bu dosya yalnızca kişisel arşiv amaçlıdır.';

$_SESSION['user_data_exported_at'] = date('Y-m-d H:i:s');

header('Content-Type: text/plain; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
echo implode("\n", $lines);
exit;
