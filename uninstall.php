<?php
/**
 * Remove every user's ideas_inbox meta when the plugin is deleted.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_metadata( 'user', 0, 'ideas_inbox', '', true );
