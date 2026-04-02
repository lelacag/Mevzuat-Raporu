<?php
// polls module
if (!function_exists('create_poll')) {
    function create_poll($user_id, $title, $post_id = null, $group_post_id = null, $options = []) {
        if (is_user_creation_restricted($user_id)) {
            return ['error' => 'rookie_restricted'];
        }
        $title = trim($title ?? '');
        $norm = [];
        foreach ($options as $o) {
            $t = trim((string)$o);
            if ($t === '') continue;
            $norm[] = $t;
        }
        $norm = array_values(array_unique($norm));
        if (count($norm) < 2) return ['error' => 'need_two_options'];
        if (count($norm) > 10) return ['error' => 'too_many_options'];

        $pdo = db_connect();
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("INSERT INTO polls (post_id, group_post_id, user_id, title) VALUES (?, ?, ?, ?)");
            $stmt->execute([$post_id, $group_post_id, $user_id, $title]);
            $poll_id = (int)$pdo->lastInsertId();
            $opt_stmt = $pdo->prepare("INSERT INTO poll_options (poll_id, text) VALUES (?, ?)");
            foreach ($norm as $opt) {
                $opt_stmt->execute([$poll_id, $opt]);
            }
            $slug = generate_slug($title) ?: 'anket';
            $slug .= '-' . $poll_id;
            if (column_exists('polls', 'slug')) {
                $pdo->prepare("UPDATE polls SET slug = ? WHERE id = ?")->execute([$slug, $poll_id]);
            }
            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            return ['error' => 'db_error', 'message' => $e->getMessage()];
        }
        return ['id' => $poll_id, 'count' => count($norm), 'slug' => $slug];
    }
}

if (!function_exists('vote_poll')) {
    function vote_poll($user_id, $poll_id, $option_id) {
        $pdo = db_connect();
        $p = $pdo->prepare("SELECT id FROM polls WHERE id = ? LIMIT 1");
        $p->execute([$poll_id]);
        if (!$p->fetch()) return ['error' => 'poll_not_found'];

        if ((int)$option_id === 0) {
            try {
                $pdo->beginTransaction();
                $v = $pdo->prepare("SELECT id, option_id FROM poll_votes WHERE poll_id = ? AND user_id = ? LIMIT 1");
                $v->execute([$poll_id, $user_id]);
                $existing = $v->fetch(PDO::FETCH_ASSOC);
                if (!$existing) {
                    $pdo->commit();
                    return ['status' => 'no_change'];
                }
                $dec = $pdo->prepare("UPDATE poll_options SET votes_count = GREATEST(votes_count - 1, 0) WHERE id = ?");
                $dec->execute([$existing['option_id']]);
                $del = $pdo->prepare("DELETE FROM poll_votes WHERE id = ?");
                $del->execute([$existing['id']]);
                $pdo->commit();
                return ['status' => 'removed'];
            } catch (Exception $e) {
                $pdo->rollBack();
                return ['error' => 'db_error', 'message' => $e->getMessage()];
            }
        }

        $o = $pdo->prepare("SELECT id, votes_count FROM poll_options WHERE id = ? AND poll_id = ? LIMIT 1");
        $o->execute([$option_id, $poll_id]);
        $opt = $o->fetch(PDO::FETCH_ASSOC);
        if (!$opt) return ['error' => 'option_not_found'];

        try {
            $pdo->beginTransaction();
            $v = $pdo->prepare("SELECT id, option_id FROM poll_votes WHERE poll_id = ? AND user_id = ? LIMIT 1");
            $v->execute([$poll_id, $user_id]);
            $existing = $v->fetch(PDO::FETCH_ASSOC);
            if ($existing) {
                if ((int)$existing['option_id'] === (int)$option_id) {
                    $pdo->commit();
                    return ['status' => 'no_change'];
                }
                $dec = $pdo->prepare("UPDATE poll_options SET votes_count = GREATEST(votes_count - 1, 0) WHERE id = ?");
                $dec->execute([$existing['option_id']]);
                $upd = $pdo->prepare("UPDATE poll_votes SET option_id = ? WHERE id = ?");
                $upd->execute([$option_id, $existing['id']]);
                $inc = $pdo->prepare("UPDATE poll_options SET votes_count = votes_count + 1 WHERE id = ?");
                $inc->execute([$option_id]);
                $pdo->commit();
                return ['status' => 'changed'];
            }
            $ins = $pdo->prepare("INSERT INTO poll_votes (poll_id, user_id, option_id) VALUES (?, ?, ?)");
            $ins->execute([$poll_id, $user_id, $option_id]);
            $inc = $pdo->prepare("UPDATE poll_options SET votes_count = votes_count + 1 WHERE id = ?");
            $inc->execute([$option_id]);
            $pdo->commit();
            return ['status' => 'voted'];
        } catch (Exception $e) {
            $pdo->rollBack();
            return ['error' => 'db_error', 'message' => $e->getMessage()];
        }
    }
}

if (!function_exists('get_poll_for_post')) {
    function get_poll_for_post($post_id) {
        if (!$post_id) return null;
        $pdo = db_connect();

        try {
            $stmt = $pdo->prepare("SELECT * FROM polls WHERE post_id = ? LIMIT 1");
            $stmt->execute([$post_id]);
            $poll = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$poll) return null;
            $opts = $pdo->prepare("SELECT id, text, votes_count FROM poll_options WHERE poll_id = ? ORDER BY id ASC");
            $opts->execute([$poll['id']]);
            $poll['options'] = $opts->fetchAll(PDO::FETCH_ASSOC);

            $user_id = function_exists('get_current_user_id') ? get_current_user_id() : null;
            $poll['user_vote'] = null;
            if ($user_id) {
                $v = $pdo->prepare("SELECT option_id FROM poll_votes WHERE poll_id = ? AND user_id = ? LIMIT 1");
                $v->execute([$poll['id'], $user_id]);
                $row = $v->fetch(PDO::FETCH_ASSOC);
                if ($row) $poll['user_vote'] = (int)$row['option_id'];
            }

            return $poll;
        } catch (PDOException $e) {
            error_log('get_poll_for_post DB error: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('get_poll_for_group_post')) {
    function get_poll_for_group_post($group_post_id) {
        if (!$group_post_id) return null;
        $pdo = db_connect();

        try {
            $stmt = $pdo->prepare("SELECT * FROM polls WHERE group_post_id = ? LIMIT 1");
            $stmt->execute([$group_post_id]);
            $poll = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$poll) return null;
            $opts = $pdo->prepare("SELECT id, text, votes_count FROM poll_options WHERE poll_id = ? ORDER BY id ASC");
            $opts->execute([$poll['id']]);
            $poll['options'] = $opts->fetchAll(PDO::FETCH_ASSOC);

            $user_id = function_exists('get_current_user_id') ? get_current_user_id() : null;
            $poll['user_vote'] = null;
            if ($user_id) {
                $v = $pdo->prepare("SELECT option_id FROM poll_votes WHERE poll_id = ? AND user_id = ? LIMIT 1");
                $v->execute([$poll['id'], $user_id]);
                $row = $v->fetch(PDO::FETCH_ASSOC);
                if ($row) $poll['user_vote'] = (int)$row['option_id'];
            }

            return $poll;
        } catch (PDOException $e) {
            error_log('get_poll_for_group_post DB error: ' . $e->getMessage());
            return null;
        }
    }
}
