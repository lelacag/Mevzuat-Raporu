<?php
require_once __DIR__ . '/Autoloader.php';
\App\Autoloader::register();

$container = \App\Container::getInstance();
$__pdo = function_exists('db_connect') ? db_connect() : null;

$container->set(\App\Service\PostService::class, function () use ($__pdo) { return new \App\Service\PostService($__pdo); });
$container->set(\App\Service\PollService::class, function () use ($__pdo) { return new \App\Service\PollService($__pdo); });
$container->set(\App\Service\UserService::class, function () use ($__pdo) { return new \App\Service\UserService($__pdo); });
$container->set(\App\Service\SocialService::class, function () use ($__pdo) { return new \App\Service\SocialService($__pdo); });
$container->set(\App\Service\NotificationService::class, function () use ($__pdo) { return new \App\Service\NotificationService($__pdo); });
$container->set(\App\Service\AdminService::class, function () use ($__pdo) { return new \App\Service\AdminService($__pdo); });
$container->set(\App\Service\BadgeService::class, function () use ($__pdo) { return new \App\Service\BadgeService($__pdo); });
// Ensure default tier badges exist in the DB (idempotent)
if (function_exists('seed_default_badges')) { seed_default_badges(); }
$container->set(\App\Service\TagService::class, function () use ($__pdo) { return new \App\Service\TagService($__pdo); });
$container->set(\App\Service\GroupService::class, function () use ($__pdo) { return new \App\Service\GroupService($__pdo); });
$container->set(\App\Service\GeoService::class, function () use ($__pdo) { return new \App\Service\GeoService($__pdo); });
$container->set(\App\Service\SecurityService::class, function () { return new \App\Service\SecurityService(); });
$container->set(\App\Service\ContentService::class, function () use ($__pdo) { return new \App\Service\ContentService($__pdo); });
$container->set(\App\Service\EmailService::class, function () { return new \App\Service\EmailService(); });
$container->set(\App\Service\RbacService::class, function () use ($__pdo) { return new \App\Service\RbacService($__pdo); });

unset($__pdo);

if (!function_exists('app')) {
    function app(string $service): object {
        return \App\Container::getInstance()->get($service);
    }
}