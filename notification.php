<?php

require_once __DIR__ . '/includes/header.php';

$user_id = get_current_user_id();

if (!$user_id) {
    header('Location: ' . BASE_PATH . '/landing.php');
    exit;
}

// determine filter from clean path or query string
$filter = 'all';
// clean URL form: /bildirimler/<filter>
$uri = $_SERVER['REQUEST_URI'] ?? '';
if (preg_match('#/bildirimler(?:/([^/?\#]+))?#', $uri, $m)) {
    if (!empty($m[1])) {
        $filter = $m[1];
    }
} elseif (!empty($_GET['filter'])) {
    $filter = $_GET['filter'];
}
// validate filter
$allowed = ['all','mention','comment','like','group','follow'];
if (!in_array($filter, $allowed)) {
    $filter = 'all';
}
// if we received old query-string path, redirect to canonical clean URL
if (strpos($uri, 'notification.php') !== false && isset($_GET['filter'])) {
    $dest = BASE_PATH . '/bildirimler';
    if ($filter !== 'all') $dest .= '/' . urlencode($filter);
    header('Location: ' . $dest, true, 301);
    exit;
}

// Get notifications based on filter
try {
    $pdo = db_connect();
    
    if ($filter === 'all') {
        $query = "SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 100";
        $stmt = $pdo->prepare($query);
        $stmt->execute([$user_id]);
    } elseif ($filter === 'mention') {
        $query = "SELECT * FROM notifications WHERE user_id = ? AND type = 'mention' ORDER BY created_at DESC LIMIT 100";
        $stmt = $pdo->prepare($query);
        $stmt->execute([$user_id]);
    } elseif ($filter === 'comment') {
        $query = "SELECT * FROM notifications WHERE user_id = ? AND type IN ('reply', 'comment') ORDER BY created_at DESC LIMIT 100";
        $stmt = $pdo->prepare($query);
        $stmt->execute([$user_id]);
    } elseif ($filter === 'like') {
        $query = "SELECT * FROM notifications WHERE user_id = ? AND type = 'like' ORDER BY created_at DESC LIMIT 100";
        $stmt = $pdo->prepare($query);
        $stmt->execute([$user_id]);
    } elseif ($filter === 'group') {
        $query = "SELECT * FROM notifications WHERE user_id = ? AND type = 'group' ORDER BY created_at DESC LIMIT 100";
        $stmt = $pdo->prepare($query);
        $stmt->execute([$user_id]);
    } elseif ($filter === 'follow') {
        $query = "SELECT * FROM notifications WHERE user_id = ? AND type = 'follow' ORDER BY created_at DESC LIMIT 100";
        $stmt = $pdo->prepare($query);
        $stmt->execute([$user_id]);
    }
    
    $notifications = $stmt ? $stmt->fetchAll() : [];
} catch (Exception $e) {
    error_log("Error fetching notifications: " . $e->getMessage());
    $notifications = [];
}

// Mark all notifications as read
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'mark_all_read') {
    try {
        $pdo = db_connect();
        $stmt = $pdo->prepare("UPDATE notifications SET read_at = NOW() WHERE user_id = ? AND read_at IS NULL");
        $stmt->execute([$user_id]);
    } catch (Exception $e) {
        error_log("Error marking all as read: " . $e->getMessage());
    }
    header('Location: ' . BASE_PATH . '/notification.php?filter=' . $filter);
    exit;
}

// Mark single notification as read and redirect
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_read_id'])) {
    $mark_read_id = $_POST['mark_read_id'] ?? 0;
    if ($mark_read_id) {
        try {
            $pdo = db_connect();
            
            // Get the notification to find the post_id / type / from_user
            $stmt = $pdo->prepare("SELECT post_id, type, from_user_id FROM notifications WHERE id = ? AND user_id = ?");
            $stmt->execute([$mark_read_id, $user_id]);
            $notification = $stmt->fetch();
            
            // Mark as read
            $stmt = $pdo->prepare("UPDATE notifications SET read_at = NOW() WHERE id = ? AND user_id = ?");
            $stmt->execute([$mark_read_id, $user_id]);
            
            // Redirect based on notification type
            if ($notification) {
                if ($notification['type'] === 'follow' && !empty($notification['from_user_id'])) {
                    // Go to follower's profile
                    $stmt = $pdo->prepare("SELECT username FROM users WHERE id = ? LIMIT 1");
                    $stmt->execute([$notification['from_user_id']]);
                    $row = $stmt->fetch();
                    if ($row && !empty($row['username'])) {
                        header('Location: ' . profile_url($row['username']));
                        exit;
                    }
                }

                // Special-case replies/comments for event comments: notifications.post_id stores event_id
                if (in_array($notification['type'], ['reply', 'comment'], true) && !empty($notification['post_id'])) {
                    try {
                        $evtStmt = $pdo->prepare("SELECT 1 FROM events WHERE id = ? LIMIT 1");
                        $evtStmt->execute([$notification['post_id']]);
                        if ($evtStmt->fetch()) {
                            header('Location: ' . BASE_PATH . '/event_view.php?id=' . intval($notification['post_id']) . '#comments');
                            exit;
                        }
                    } catch (Exception $e) {
                        error_log('notification redirect event lookup error: ' . $e->getMessage());
                    }
                }

                // Fallback: treat post_id as a normal post
                if (!empty($notification['post_id'])) {
                    header('Location: ' . post_url($notification['post_id']));
                    exit;
                }
            }
        } catch (Exception $e) {
            error_log("Error marking notification as read: " . $e->getMessage());
        }
    }
    // Fallback to home
    header('Location: ' . BASE_PATH . '/index.php');
    exit;
}

// Get counts for filter tabs
$mention_count = 0;
$comment_count = 0;
$like_count = 0;
$group_count = 0;
$follow_count = 0;
$all_unread_count = 0;

try {
    $pdo = db_connect();
    
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND type = 'mention' AND read_at IS NULL");
    $stmt->execute([$user_id]);
    $mention_count = $stmt->fetch()['count'] ?? 0;
    
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND type IN ('reply', 'comment') AND read_at IS NULL");
    $stmt->execute([$user_id]);
    $comment_count = $stmt->fetch()['count'] ?? 0;
    
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND type = 'like' AND read_at IS NULL");
    $stmt->execute([$user_id]);
    $like_count = $stmt->fetch()['count'] ?? 0;
    
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND type = 'group' AND read_at IS NULL");
    $stmt->execute([$user_id]);
    $group_count = $stmt->fetch()['count'] ?? 0;

    // Follow counts
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND type = 'follow' AND read_at IS NULL");
    $stmt->execute([$user_id]);
    $follow_count = $stmt->fetch()['count'] ?? 0;

    // All unread
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND read_at IS NULL");
    $stmt->execute([$user_id]);
    $all_unread_count = $stmt->fetch()['count'] ?? 0;
} catch (Exception $e) {
    error_log("Error getting notification type counts: " . $e->getMessage());
}

?>

<div class="main-container">
    <!-- Left Sidebar -->
    <aside class="sidebar sidebar-left">
        <div class="sidebar-widget">
            <h3 class="sidebar-widget-title">Bildirimler</h3>
            <a href="<?= BASE_PATH ?>/index.php" class="btn-block primary">← Geri Dön</a>
            <form method="POST" class="form-no-margin">
                <input type="hidden" name="action" value="mark_all_read">
                <button type="submit" class="btn-block">Tümünü Okundu İşaretle</button>
            </form>

            <div class="sidebar-section sidebar-banner">
                <div class="banner-overlay">
                    <span class="badge badge-primary">Pek Yakında</span>
                </div>
                <div class="sidebar-title">🌐 Mesh Network</div>
                <p class="muted small">İnternetsiz bağlan!</p>
                <span class="muted small">Bölgeleri Keşfet »</span>
            </div>
        </div>
    </aside>

    <!-- Main Content -->
    <main class="content-area">
        <div class="card-box padded">
            <!-- Filter Tabs -->
            <div class="tab-links" role="tablist" aria-label="Bildirim Sekmeleri">
                <?php
// helper to build notification URL is defined in includes/functions.php
?>
<a href="<?= notification_url('all') ?>" class="tab-link <?= $filter === 'all' ? 'active' : '' ?>">
                    📬 Tümü <?php if ($all_unread_count > 0): ?><span class="tab-count"><?= $all_unread_count ?></span><?php endif; ?>
                </a>
                <a href="<?= notification_url('mention') ?>" class="tab-link <?= $filter === 'mention' ? 'active' : '' ?>">
                    👤 @Bahsedildi <?php if ($mention_count > 0): ?><span class="tab-count"><?= $mention_count ?></span><?php endif; ?>
                </a>
                <a href="<?= notification_url('comment') ?>" class="tab-link <?= $filter === 'comment' ? 'active' : '' ?>">
                    💬 Yorumlar <?php if ($comment_count > 0): ?><span class="tab-count"><?= $comment_count ?></span><?php endif; ?>
                </a>
                <a href="<?= notification_url('like') ?>" class="tab-link <?= $filter === 'like' ? 'active' : '' ?>">
                    ❤️ Beğeniler <?php if ($like_count > 0): ?><span class="tab-count"><?= $like_count ?></span><?php endif; ?>
                </a>
                <a href="<?= notification_url('group') ?>" class="tab-link <?= $filter === 'group' ? 'active' : '' ?>">
                    👥 Gruplar <?php if ($group_count > 0): ?><span class="tab-count"><?= $group_count ?></span><?php endif; ?>
                </a>
                <a href="<?= notification_url('follow') ?>" class="tab-link <?= $filter === 'follow' ? 'active' : '' ?>">
                    ➕ Takipler <?php if ($follow_count > 0): ?><span class="tab-count"><?= $follow_count ?></span><?php endif; ?>
                </a>
            </div>
        </div>

        <!-- Notifications List -->
        <?php if (empty($notifications)): ?>
            <div class="notification-empty">
                <p class="icon-large">📭</p>
                <p class="notification-empty-title">Bildirim Yok</p>
                <p class="notification-empty-desc">Bu kategoride henüz bildirim bulunmamaktadır.</p>
            </div>
        <?php else: ?>
            <div class="notifications-grid">
                <?php foreach ($notifications as $notification): ?>
                    <?php 
                    $from_user = 'Bilinmeyen';
                    $post_content = '';
                    $post_link = '#';
                    $icon = ''; // No default dot icon — show only when type provides one
                    $message = '';
                    
                    try {
                        $pdo = db_connect();
                        if (!empty($notification['from_user_id'])) {
                            $stmt = $pdo->prepare("SELECT username FROM users WHERE id = ? LIMIT 1");
                            $stmt->execute([$notification['from_user_id']]);
                            $result = $stmt->fetch();
                            $from_user = $result ? htmlspecialchars($result['username']) : 'Bilinmeyen';
                        } else {
                            // System-generated notification
                            $from_user = 'Sistem';
                        }
                    } catch (Exception $e) {
                        error_log("Error fetching from_user: " . $e->getMessage());
                    }
                    
                    if ($notification['post_id']) {
                        try {
                            $pdo = db_connect();

                            // If this is an event-reply notification, show event preview/link
                            if ($notification['type'] === 'reply') {
                                $stmt = $pdo->prepare("SELECT id, title, description FROM events WHERE id = ? LIMIT 1");
                                $stmt->execute([$notification['post_id']]);
                                $evt = $stmt->fetch();
                                if ($evt) {
                                    $raw = trim($evt['title'] ?: $evt['description'] ?: 'Etkinlik');
                                    $max = 120;
                                    $short = mb_strlen($raw) > $max ? mb_substr($raw, 0, $max - 3) . '...' : $raw;
                                    $post_content = htmlspecialchars($short, ENT_QUOTES, 'UTF-8');
                                    $post_link = BASE_PATH . '/event_view.php?id=' . intval($evt['id']);
                                }

                            // Otherwise treat as a normal post preview
                            } else {
                                $stmt = $pdo->prepare("SELECT id, content FROM posts WHERE id = ? LIMIT 1");
                                $stmt->execute([$notification['post_id']]);
                                $post = $stmt->fetch();
                                if ($post) {
                                    // Normalize whitespace (collapse newlines and repeated spaces) and truncate safely
                                    $raw = preg_replace('/\s+/u', ' ', trim($post['content']));
                                    $max = 120; // chars to keep for preview
                                    if (mb_strlen($raw) > $max) {
                                        $short = mb_substr($raw, 0, $max - 3) . '...';
                                    } else {
                                        $short = $raw;
                                    }
                                    $post_content = htmlspecialchars($short, ENT_QUOTES, 'UTF-8');
                                    $post_link = post_url($post['id']);
                                }
                            }

                        } catch (Exception $e) {
                            error_log("Error fetching post/event for notification: " . $e->getMessage());
                        }
                    }
                    
                    if ($notification['type'] === 'mention') {
                        $message = "seni gönderide bahsetti";
                        $icon = '@';
                    } elseif ($notification['type'] === 'account_approved') {
                        // Localized account approved message
                        $message = t('notification_account_approved');
                        $icon = '✓';
                    } elseif ($notification['type'] === 'comment' || $notification['type'] === 'reply') {
                        $message = "gönderine yorum yaptı";
                        $icon = '✓';
                    } elseif ($notification['type'] === 'like') {
                        $message = "gönderini beğendi";
                        $icon = '♡';
                    } elseif ($notification['type'] === 'group') {
                        $message = "grup gönderisine yanıt verdi";
                        $icon = '⊕';
                    } elseif ($notification['type'] === 'follow') {
                        $message = "seni takip etmeye başladı";
                        $icon = '➕';
                    } else {
                        $message = "sana bildirim gönderdi";
                        $icon = '';
                    }
                    
                    if (empty($message)) {
                        $message = "yeni bildirim";
                    }
                    ?>
                    <form method="POST" class="form-block">
                        <input type="hidden" name="mark_read_id" value="<?= $notification['id'] ?>">
                        <article class="post-card notification-card <?= !$notification['read_at'] ? 'unread' : 'read' ?> <?= $icon ? 'has-icon' : 'no-icon' ?>">
                            <!-- Header: Icon + Username/Timestamp -->
                            <div class="notification-row">
                                <?php if ($icon): ?>
                                <div class="notification-icon"><?= $icon ?></div>
                                <?php endif; ?>
                                
                                <div class="notification-body">
                                    <a href="<?= profile_url($from_user) ?>" class="notification-user">
                                        @<?= $from_user ?>
                                        <?php if (!$notification['read_at']): ?>
                                            <span class="badge-new">YENİ</span>
                                        <?php endif; ?>
                                    </a>
                                    <div class="notification-meta">
                                        <?= format_time($notification['created_at']) ?>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Post Preview (aligned under username) -->
                            <?php if ($post_content): ?>
                                <div class="notification-preview"><?= $post_content ?></div>
                            <?php endif; ?>

                            <!-- Message Content -->
                            <div class="notification-message"><?= $message ?><?php if ($post_link !== '#'): ?> <span class="notification-postid">#<?= $notification['post_id'] ?></span><?php endif; ?></div>
                            
                            <!-- Divider & Button (only show if unread) -->
                            <?php if (!$notification['read_at']): ?>
                            <div class="notification-actions">
                                <button type="submit" class="btn-mark-read">Okundu olarak İşaretle</button>
                            </div>
                            <?php endif; ?>
                        </article>
                    </form>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </main>

    <!-- Right Sidebar -->
    <aside class="sidebar sidebar-right">
        <div class="sidebar-widget">
            <h3 class="sidebar-widget-title">💡 İpuçları</h3>
            <div class="sidebar-widget-desc">
                <div>
                    <strong class="muted-strong">Sekmeler:</strong> Bildirimlerinizi türe göre filtreleyin.
                </div>
                <div>
                    <strong class="muted-strong">YENİ Etiketi:</strong> Okunmamış bildirimler sarı "YENİ" etiketiyle gösterilir.
                </div>
                <div>
                    <strong class="muted-strong">Tümünü Okundu:</strong> Sol menüden "Tümünü Okundu İşaretle" butonunu kullanın.
                </div>
            </div>
        </div>
    </aside>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>


