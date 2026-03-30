Post display

Current behavior:
- Posts are ordered by `created_at DESC` (most recent first).
- There is no pagination built into the timeline views (`index.php` / `get_posts`), the `get_posts` helper accepts a `limit` parameter only.
- There is no current "trending" algorithm; however `posts` table stores `likes_count` and `replies_count`, so a simple trending query could order by `likes_count DESC, replies_count DESC, created_at DESC`.

Recommended improvements:
- Add pagination to timeline (offset/limit or cursor-based) and expose a `page` parameter in `index.php`.
- Add a `sort` parameter to `get_posts` to support `recent` (default) and `trending` modes. Example: `ORDER BY likes_count DESC, replies_count DESC, created_at DESC` for trending.
- Add caching or precomputed scores for scale (score = likes*2 + replies*1 + recency factor).

If you'd like, I can implement 1) pagination with page param, or 2) a `trending` sort option next.