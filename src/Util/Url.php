<?php
namespace App\Util;

class Url
{
    public static function useCleanUrls(): bool { return use_clean_urls(); }
    public static function profile(string $u): string { return profile_url($u); }
    public static function post(int $id): string { return post_url($id); }
    public static function editPost(int $id): string { return edit_post_url($id); }
    public static function followers(string $u): string { return followers_url($u); }
    public static function following(string $u): string { return following_url($u); }
    public static function notification(?string $f = null): string { return notification_url($f); }
    public static function premium(): string { return premium_url(); }
    public static function events(): string { return events_url(); }
    public static function search(): string { return search_url(); }
    public static function rules(): string { return rules_url(); }
    public static function privacy(): string { return privacy_url(); }
    public static function kvkk(): string { return kvkk_url(); }
    public static function cookiePolicy(): string { return cookie_policy_url(); }
    public static function admin(): string { return admin_url(); }
    public static function groupEditPost(string $s, int $id): string { return group_edit_post_url($s, $id); }
    public static function home(): string { return home_url(); }
    public static function invite(?string $t = null): string { return invite_url($t); }
    public static function passwordReset(?string $t = null): string { return password_reset_url($t); }
    public static function canonical(): string { return canonical_url(); }
    public static function full(string $p): string { return full_url($p); }
    public static function group(string $s): string { return group_url($s); }
    public static function groupPost(string $s, int $id): string { return group_post_url($s, $id); }
}