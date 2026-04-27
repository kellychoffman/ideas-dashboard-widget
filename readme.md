# Ideas Inbox

A dashboard widget for your WordPress site. Drop ideas for your future self to blog about.

## Try it in WordPress Playground

[**Open a live preview**](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/kellychoffman/ideas-dashboard-widget/main/blueprint.json) — boots a throwaway WordPress site in your browser with the plugin installed and activated. No local setup required.

Every pull request also gets its own preview link posted as a sticky comment, so reviewers can try the proposed change with one click.

## What it does

Adds a dashboard widget where you can jot down blog ideas as they hit you, without breaking flow to start a draft. Each idea is saved to your own user account — nobody else sees your list. When an idea is ready to become a post, one click converts it to a draft and opens the editor.

## Design notes

This plugin is deliberately small:

- **No custom post type** — would add clutter to the admin sidebar for something this lightweight.
- **No custom database table** — overkill for a handful of short notes.
- **Stored as a single `ideas_inbox` user meta entry** — one row in `wp_usermeta` per user, a plain PHP array of `{ text, time }` items.
- **Private by default** — per-user storage means the widget is a personal inbox, not a shared team list.
- **Cleans up on uninstall** — deleting the plugin removes every user's stored ideas via `uninstall.php`.

## Requirements

- WordPress 5.0+
- User needs the `edit_posts` capability (Contributor and up) to see and use the widget.

## Install

1. Copy the plugin folder to `wp-content/plugins/ideas-dashboard-widget/`.
2. Activate it under **Plugins** in the admin.
3. Visit your dashboard — the **Ideas Inbox** widget appears there.

## Usage

- Type an idea into the textarea and click **Add idea**.
- The widget shows your 5 most recent ideas in reverse-chronological order. When you have more than 5, a **View all ideas** link appears at the bottom of the widget.
- All ideas also live on a dedicated admin screen at **Posts → Ideas Inbox**, paginated 20 at a time.
- Each idea has two actions:
  - **Turn into draft** — creates a draft post with the idea as the content (and a trimmed title), removes it from the inbox, and opens the post editor.
  - **Delete** — removes the idea from the inbox.

## Changelog

### 0.5.0

- Click an idea's text in the dashboard widget to load it back into the textarea for editing. The submit button switches to **Save**, and a **Cancel** button returns the form to a fresh entry. Editing preserves the idea's original timestamp. Adds `PATCH /ideas-inbox/v1/ideas/{id}` for the update.

### 0.4.0

- Add and delete ideas without a full page reload. Uses a `ideas-inbox/v1` REST namespace with `wp.apiFetch`; the existing `admin-post.php` handlers stay as the no-JS fallback.
- Each idea now carries a stable UUID. Existing ideas get IDs assigned lazily on first read.

## License

GPL-2.0-or-later
