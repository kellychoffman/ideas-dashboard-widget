# Ideas Dashboard Widget

An ideas inbox for your WordPress dashboard. Drop ideas for your future self to blog about.

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
- Existing ideas appear below in reverse-chronological order.
- Each idea has two actions:
  - **Turn into draft** — creates a draft post with the idea as the content (and a trimmed title), removes it from the inbox, and opens the post editor.
  - **Delete** — removes the idea from the inbox.

## License

GPL-2.0-or-later
