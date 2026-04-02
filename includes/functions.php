<?php /* EN + TR comments used. */
require_once __DIR__ . '/db.php';

// Module loader — domain-specific modules are loaded first, then legacy fallback.
// Module functions use function_exists() guards so they take precedence over legacy.
$module_files = [
    __DIR__ . '/../modules/security.php',
    __DIR__ . '/../modules/polyfills.php',
    __DIR__ . '/../modules/url_helpers.php',
    __DIR__ . '/../modules/rbac.php',
    __DIR__ . '/../modules/bad_words.php',
    __DIR__ . '/../modules/admin_audit.php',
    __DIR__ . '/../modules/invitations.php',
    __DIR__ . '/../modules/user.php',
    __DIR__ . '/../modules/posts.php',
    __DIR__ . '/../modules/polls.php',
    __DIR__ . '/../modules/social.php',
    __DIR__ . '/../modules/notifications.php',
    __DIR__ . '/../modules/render.php',
    __DIR__ . '/../modules/tests.php',
    __DIR__ . '/../modules/schedule_post/schedule_post.php',
    __DIR__ . '/../modules/text.php',
    __DIR__ . '/../modules/tags.php',
    __DIR__ . '/../modules/badges.php',
    __DIR__ . '/../modules/drafts.php',
    __DIR__ . '/../modules/diff.php',
    __DIR__ . '/../modules/email.php',
    __DIR__ . '/../modules/event_codes.php',
    __DIR__ . '/../modules/geo.php',
    __DIR__ . '/../modules/admin.php',
    __DIR__ . '/../modules/groups.php',
];

foreach ($module_files as $mf) {
    if (file_exists($mf)) {
        require_once $mf;
    }
}

// Keep monolithic legacy definitions as fallback (declare guards in modules avoid conflicts)
$legacy = __DIR__ . '/functions_legacy.php';
if (file_exists($legacy)) {
    require_once $legacy;
}

// PSR-4 service layer — provides OOP interface on top of procedural modules
$bootstrap = __DIR__ . '/../src/bootstrap.php';
if (file_exists($bootstrap)) {
    require_once $bootstrap;
}

