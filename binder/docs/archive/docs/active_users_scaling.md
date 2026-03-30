Active Users view — scaling considerations

Goal: show active users (20 per page) with total active count. What to do when "active" users reach ~1,000,000.

Problems with naive approach:
- OFFSET pagination becomes slow as page number grows (database must scan/skip many rows).
- Transferring and rendering long result lists causes heavy memory and bandwidth use.
- Computing an exact total count on every request is expensive for very large tables.

Recommendations (practical, incremental):
1) Add an index on `last_activity` (and `deleted_at`) so queries like
   WHERE deleted_at IS NULL AND last_activity >= ? ORDER BY last_activity DESC
   use indexes.

2) Use keyset (cursor) pagination for deep pages
   - Avoid OFFSET. Use WHERE (last_activity < :cursor_ts OR (last_activity = :cursor_ts AND id < :cursor_id)) ORDER BY last_activity DESC, id DESC LIMIT 20
   - Return a cursor (timestamp,id) for "next" page. This is fast and stable.

3) Precompute and cache counts and first N pages
   - Maintain a lightweight cache (Redis) that stores: total_active_count, and first few pages (e.g., top 5 pages) of results refreshed every minute.
   - For ad-hoc deep access use keyset queries directly to DB.

4) Materialized view / background job
   - For larger scale, update a daily/hourly materialized table of active users (buckets by day) built by a background worker.

5) UI-level optimizations
   - Use infinite scroll with incremental loads (cursor-based) and render only visible cards (virtualization) to keep memory low.
   - Consider recommending "Top recent" and "Nearby" or filter groups (region/city) instead of showing full list by default.

6) Approximate counts
   - When exact total is not required, show an approximate count (HyperLogLog or sampled estimates) to reduce heavy COUNT(*) queries on large tables.

7) Sharding / search service
   - For massive scale, put active user index into a search service (Elasticsearch) or a dedicated Redis sorted set keyed by last_activity, which is very fast to page through and supports range queries and ranking.

Implementation path (short-term):
- Add DB index on (deleted_at, last_activity)
- Convert pagination to keyset-based cursor support (server + UI)
- Implement Redis cache for total count and first N pages

If you'd like, I can implement the index and convert the Active Users page to cursor-based pagination now (fast wins). Which approach do you want to start with?