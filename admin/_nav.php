<?php /* EN + TR comments used. */
// Simple admin navigation partial
$path = $_SERVER['REQUEST_URI'] ?? '';
$active = '';
if (strpos($path, '/admin/index.php') !== false || strpos($path, '/admin/') === 0) $active = 'index';
if (strpos($path, 'users.php') !== false) $active = 'users';
if (strpos($path, 'pending_users.php') !== false) $active = 'pending_users';
if (strpos($path, 'reports.php') !== false) $active = 'reports';
if (strpos($path, 'badges.php') !== false || strpos($path, 'badges_edit.php') !== false) $active = 'badges';
if (strpos($path, 'badwords.php') !== false) $active = 'badwords';
if (strpos($path, 'premium_') !== false) $active = 'premium';
if (strpos($path, 'pending_review.php') !== false) $active = 'pending_review';
if (strpos($path, 'approved_words.php') !== false) $active = 'approved_words';

// Get pending review count
$pending_stmt = query("SELECT COUNT(*) as count FROM posts WHERE review_status = 'pending' AND deleted_at IS NULL");
$pending_count = $pending_stmt->fetch()['count'] ?? 0;

// Get pending user approvals count
$pending_users_stmt = query("SELECT COUNT(*) as count FROM users WHERE is_approved = 0 AND deleted_at IS NULL");
$pending_users_count = $pending_users_stmt->fetch()['count'] ?? 0;
?>

<nav class="admin-nav">
    <?php if (is_admin() || admin_has_perm(null, 'view_admin_dashboard')): ?>
    <a href="<?= BASE_PATH ?>/admin/index.php" class="<?= $active === 'index' ? 'active' : '' ?>">Panel</a>
<?php endif; ?>
    <?php if (is_admin() || admin_has_perm(null, 'manage_users')): ?>
    <a href="<?= BASE_PATH ?>/admin/users.php" class="<?= $active === 'users' ? 'active' : '' ?>">Kullanıcılar</a>
<?php endif; ?>
    <?php if (is_admin() || admin_has_perm(null, 'approve_profiles')): ?>
    <a href="<?= BASE_PATH ?>/admin/pending_users.php" class="<?= $active === 'pending_users' ? 'active' : '' ?>">
        🌱 Onay Bekleyenler
        <?php if ($pending_users_count > 0): ?>
            <span class="notification-badge"><?= $pending_users_count ?></span>
        <?php endif; ?>
    </a>
<?php endif; ?>
    <?php if (is_admin() || admin_has_perm(null, 'view_reports') || admin_has_perm(null, 'moderate_content')): ?>
    <a href="<?= BASE_PATH ?>/admin/reports.php" class="<?= $active === 'reports' ? 'active' : '' ?>">Raporlar</a>
<?php endif; ?>
    <?php if (is_admin() || admin_has_perm(null, 'moderate_content')): ?>
    <a href="<?= BASE_PATH ?>/admin/pending_review.php" class="<?= $active === 'pending_review' ? 'active' : '' ?>">
        Şüpheli İçerik
        <?php if ($pending_count > 0): ?>
            <span class="notification-badge"><?= $pending_count ?></span>
        <?php endif; ?>
    </a>
<?php endif; ?>
    <?php if (is_admin() || admin_has_perm(null, 'manage_whitelist')): ?>
    <a href="<?= BASE_PATH ?>/admin/approved_words.php" class="<?= $active === 'approved_words' ? 'active' : '' ?>">Beyaz Liste</a>
<?php endif; ?>
    <?php if (is_admin() || admin_has_perm(null, 'manage_badges')): ?>
    <a href="<?= BASE_PATH ?>/admin/badges.php" class="<?= $active === 'badges' ? 'active' : '' ?>">Rozetler</a>
<?php endif; ?>
    <?php if (is_admin() || admin_has_perm(null, 'manage_roles')): ?>
        <a href="<?= BASE_PATH ?>/admin/roles.php" class="<?= strpos($path, 'roles.php') !== false ? 'active' : '' ?>"><?= t('admin_roles_title') ?></a>
    <?php endif; ?>
    <?php if (is_admin() || admin_has_perm(null, 'manage_bad_words')): ?>
    <a href="<?= BASE_PATH ?>/admin/badwords.php" class="<?= $active === 'badwords' ? 'active' : '' ?>">Kötü Kelimeler</a>
<?php endif; ?>
    <?php if (is_admin() || admin_has_perm(null, 'manage_billing') || admin_has_perm(null, 'view_billing_reports')): ?>
    <a href="<?= BASE_PATH ?>/admin/premium_users.php" class="<?= $active === 'premium' ? 'active' : '' ?>">Premium Kullanıcılar</a>
    <a href="<?= BASE_PATH ?>/admin/premium_subscriptions.php" class="<?= strpos($_SERVER['REQUEST_URI'], 'premium_subscriptions.php') !== false ? 'active' : '' ?>">Premium Abonelikler</a>
    <a href="<?= BASE_PATH ?>/admin/premium_reconcile.php" class="<?= strpos($_SERVER['REQUEST_URI'], 'premium_reconcile.php') !== false ? 'active' : '' ?>">Premium Mutabakat</a>
    <a href="<?= BASE_PATH ?>/admin/iap_status.php" class="<?= strpos($_SERVER['REQUEST_URI'], 'iap_status.php') !== false ? 'active' : '' ?>">IAP Status</a>
    <a href="<?= BASE_PATH ?>/admin/premium_settings.php" class="<?= strpos($_SERVER['REQUEST_URI'], 'premium_settings.php') !== false ? 'active' : '' ?>">Premium Ayarları</a>
<?php endif; ?>
    <?php if (is_admin() || admin_has_perm(null, 'manage_events') || admin_has_perm(null, 'approve_events')): ?>
    <a href="<?= BASE_PATH ?>/admin/events.php" class="<?= strpos($_SERVER['REQUEST_URI'], 'events.php') !== false ? 'active' : '' ?>">Etkinlikler</a>
<?php endif; ?>
    <?php if (is_admin() || admin_has_perm(null, 'manage_notifications')): ?>
    <a href="<?= BASE_PATH ?>/admin/announcements.php" class="<?= strpos($_SERVER['REQUEST_URI'], 'announcements.php') !== false ? 'active' : '' ?>">📢 Duyurular</a>
<?php endif; ?>
    <?php if (is_admin()): ?>
    <a href="<?= BASE_PATH ?>/admin/index.php?section=maintenance" class="<?= (strpos($_SERVER['REQUEST_URI'], 'section=maintenance') !== false) ? 'active' : '' ?>">⚠️ Bakım Modu</a>
    <?php endif; ?>
    <!-- dev-only nav removed -->
    <?php if (is_admin() || admin_has_perm(null, 'view_system_logs')): ?>
    <a href="<?= BASE_PATH ?>/admin/url_sessions.php" class="<?= strpos($_SERVER['REQUEST_URI'], 'url_sessions.php') !== false ? 'active' : '' ?>">URL Oturumları</a>
<?php endif; ?>
    <?php if (is_admin() || admin_has_perm(null, 'moderate_content')): ?>
    <a href="<?= BASE_PATH ?>/admin/captcha_dashboard.php" class="<?= strpos($_SERVER['REQUEST_URI'], 'captcha_dashboard.php') !== false ? 'active' : '' ?>">CAPTCHA Panosu</a>
    <a href="<?= BASE_PATH ?>/admin/captcha_offenders.php" class="<?= strpos($_SERVER['REQUEST_URI'], 'captcha_offenders.php') !== false ? 'active' : '' ?>">CAPTCHA Şüphelileri</a>
<?php endif; ?>
    <?php if (is_admin() || admin_has_perm(null, 'view_system_logs')): ?>
    <a href="<?= BASE_PATH ?>/admin/blocked_ips.php" class="<?= strpos($_SERVER['REQUEST_URI'], 'blocked_ips.php') !== false ? 'active' : '' ?>">Engellenen IP'ler</a>
    <a href="<?= BASE_PATH ?>/admin/audit_logs.php" class="<?= strpos($_SERVER['REQUEST_URI'], 'audit_logs.php') !== false ? 'active' : '' ?>">Denetim Kayıtları</a>
<?php endif; ?>
    <?php if (is_admin() || admin_has_perm(null, 'approve_profiles')): ?>
    <a href="<?= BASE_PATH ?>/admin/signup_requests.php" class="<?= strpos($_SERVER['REQUEST_URI'], 'signup_requests.php') !== false ? 'active' : '' ?>">Kayıt Talepleri</a>
<?php endif; ?>
</nav>