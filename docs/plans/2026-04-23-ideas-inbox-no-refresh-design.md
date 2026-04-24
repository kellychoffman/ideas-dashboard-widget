# Ideas Inbox — no-refresh add/delete

**Issue:** [#10 — Explore disabling hard refresh every time an action is taken on an idea](https://github.com/kellychoffman/ideas-dashboard-widget/issues/10)

## Summary

Today, adding or deleting an idea posts to `admin-post.php` and redirects back, triggering a full page reload. This design replaces those reloads with REST calls that update the DOM in place, while keeping the current form-POST handlers as the no-JS fallback.

Edit-in-place is planned as a follow-up; the design below introduces stable idea IDs so that work drops in cleanly.

## Key decisions

- **Scope:** add + delete only. "Turn into draft" keeps its redirect (it's an intentional navigation to the editor).
- **Endpoint style:** WP REST API, namespace `ideas-inbox/v1`.
- **Identity:** each idea gets a stable UUID. Positional indexes stay as the no-JS fallback URL shape for existing `admin-post` handlers.
- **DOM strategy:** PHP is the single source of truth for row markup. The add response returns a pre-rendered `<li>` fragment; JS does `insertAdjacentHTML`. JS duplicates only the empty state and list skeleton (a few lines each).
- **Widget "fill-in" on delete:** *revised during implementation.* The delete response returns a pre-rendered `fill_html` for the next-oldest idea whenever `total >= 5` after the delete. The client appends it when the widget list has fewer than 5 visible rows, keeping the widget consistently full. The earlier "accept a temporary gap" decision was reversed once the gap turned out to be more visible than expected.
- **Error surface:** inline `notice notice-error` div. No toast / snackbar.

## Data model

```php
array(
    'id'   => 'a1b2c3d4-...', // NEW — wp_generate_uuid4()
    'text' => '...',
    'time' => 1745432100,
)
```

**Lazy migration** inside `ideas_inbox_get_ideas()`: any idea without an `id` gets one assigned on first read, persisted on the same request. Idempotent, zero-downtime.

**Helper:**

```php
function ideas_inbox_find_index_by_id( array $ideas, $id ) {
    foreach ( $ideas as $k => $idea ) {
        if ( isset( $idea['id'] ) && hash_equals( $idea['id'], (string) $id ) ) {
            return $k;
        }
    }
    return false;
}
```

## REST endpoints

Namespace: `ideas-inbox/v1`. Auth: `current_user_can( 'edit_posts' )`. Nonce: `wp_rest` via `wp.apiFetch`.

### `POST /ideas`

- Args: `text` (required, string, `sanitize_textarea_field`).
- Empty text → 400 `empty_idea`.
- Creates `{id, text, time}`, saves, returns `{id, html, total}`.
- `html` is `ideas_inbox_render_row($idea)` captured with `ob_start`.

### `DELETE /ideas/{id}`

- Looks up by UUID. Not found → 404.
- Unsets, saves, returns `{total, fill_html}`.
- `fill_html` is the pre-rendered `<li>` for the idea at position `total - 5` in the oldest-first array when `total >= 5`, otherwise `null`. The widget uses it to backfill; the submenu page ignores it.

## Client JS flow

Dependencies: add `wp-api-fetch` to the script enqueue.

```js
var apiFetch = wp.apiFetch;

// Intercept add form
form.addEventListener('submit', async function (e) {
    if (!apiFetch) return; // no JS support → native POST
    e.preventDefault();
    var text = textarea.value.trim();
    if (!text) return;
    submitButton.disabled = true;
    try {
        var res = await apiFetch({
            path: '/ideas-inbox/v1/ideas',
            method: 'POST',
            data: { text: text },
        });
        onAddSuccess(res);
    } catch (err) {
        showError(err);
    } finally {
        submitButton.disabled = false;
    }
});
```

The existing ConfirmDialog's `onConfirm` changes: instead of `window.location.href = url`, extract the `data-id` from the pending row and call `apiFetch({ method: 'DELETE', path: '/ideas-inbox/v1/ideas/' + id })`.

**DOM sync helpers:**

- `onAddSuccess({id, html, total})`:
  - If empty state is visible, remove it and inject the list skeleton (header + count span + `<ul>`).
  - `list.insertAdjacentHTML('afterbegin', html)`.
  - Widget only: if the list now has more than 5 rows, remove the last.
  - Update `.ideas-inbox__count`.
  - Clear textarea, refocus.
- `onDeleteSuccess({total}, rowElement)`:
  - Remove the `<li>` by `data-id`.
  - Update count, "View all (N)" text / visibility.
  - If `total === 0`: swap list for empty state markup.

JS-side template helpers (`createEmptyState()`, `createListSkeleton()`) stay small and mirror PHP output for those two fragments only.

## Progressive enhancement

- Form keeps `action="admin-post.php"` + the `ideas_inbox_add` hidden input. `e.preventDefault()` runs only when `wp.apiFetch` is available. No JS → native POST → redirect (today's behavior).
- Delete `<a>` keeps its `admin-post.php` href and inline `confirm()` fallback. JS strips the `onclick` and routes through the ConfirmDialog as it already does. No JS → the native link works.
- The existing `admin-post` handlers also need to assign a UUID on create so no-JS-created ideas get IDs immediately (not only on next read).

## Edge cases

- **Submenu page, delete leaves page empty, `paged > 1`:** fall back to `window.location.reload()` — simplest, rarely hit.
- **Two tabs, delete race:** REST returns 404 on stale ID. Inline error; UI stays intact.
- **Permission change mid-session:** REST returns 401/403; inline error.

## File-by-file changes

1. `ideas-dashboard-widget.php`
   - Bump `IDEAS_INBOX_VERSION` to `0.4.0`.
   - `ideas_inbox_get_ideas()` — lazy UUID assignment.
   - `ideas_inbox_find_index_by_id()` — new.
   - `ideas_inbox_handle_add()` — assign UUID on create.
   - `ideas_inbox_render_list()` — extract row into `ideas_inbox_render_row( $idea )`, add `data-id` to `<li>`.
   - `ideas_inbox_register_rest_routes()` + `ideas_inbox_rest_add()` + `ideas_inbox_rest_delete()` — new.
   - `ideas_inbox_enqueue_assets()` — add `wp-api-fetch` dep, `wp_add_inline_script` for the namespace constant.
2. `assets/ideas-inbox.js`
   - Add submit interceptor, DOM sync helpers, DELETE call from ConfirmDialog.
3. `assets/ideas-inbox.css`
   - Minimal additions for the inline error notice (only if `wp-components` doesn't cover it).
4. `readme.md`
   - Changelog entry for 0.4.0.

## Testing plan (manual)

1. Fresh install, dashboard widget: add 3 ideas — no refresh, count increments.
2. With 8 ideas total, delete a visible one from the widget — row removed, count decrements, widget shows 4 (accepted).
3. Disable JS in DevTools: form submits via POST + redirect; delete link uses inline `confirm()` and redirects.
4. Submenu page, 21 ideas: delete the only idea on page 2 → page reloads (fallback).
5. Log out in another tab, attempt delete → inline error, UI intact.
6. Two tabs, add in A then delete in B with stale state → 404, inline error, UI intact.
7. Empty state ↔ populated state transitions (first add, last delete) work without reload.
