<?php
/**
 * Plugin Name: Ideas Inbox
 * Description: An ideas inbox for your WP dashboard. Drop ideas for your future self to blog about.
 * Version:     0.2.0
 * Author:      Kelly Hoffman
 * License:     GPL-2.0-or-later
 * Text Domain: ideas-dashboard-widget
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const IDEAS_INBOX_META_KEY  = 'ideas_inbox';
const IDEAS_INBOX_NONCE     = 'ideas_inbox';
const IDEAS_INBOX_PAGE_SLUG = 'ideas-inbox';

add_action( 'wp_dashboard_setup', 'ideas_inbox_register_widget' );
add_action( 'admin_menu',         'ideas_inbox_register_page' );
add_action( 'admin_post_ideas_inbox_add',    'ideas_inbox_handle_add' );
add_action( 'admin_post_ideas_inbox_delete', 'ideas_inbox_handle_delete' );
add_action( 'admin_post_ideas_inbox_draft',  'ideas_inbox_handle_draft' );

function ideas_inbox_register_widget() {
	if ( ! current_user_can( 'edit_posts' ) ) {
		return;
	}
	wp_add_dashboard_widget(
		'ideas_inbox_widget',
		__( 'Ideas Inbox', 'ideas-dashboard-widget' ),
		'ideas_inbox_render_widget'
	);
}

function ideas_inbox_register_page() {
	add_submenu_page(
		'edit.php',
		__( 'Ideas Inbox', 'ideas-dashboard-widget' ),
		__( 'Ideas Inbox', 'ideas-dashboard-widget' ),
		'edit_posts',
		IDEAS_INBOX_PAGE_SLUG,
		'ideas_inbox_render_page'
	);
}

function ideas_inbox_get_ideas() {
	$ideas = get_user_meta( get_current_user_id(), IDEAS_INBOX_META_KEY, true );
	return is_array( $ideas ) ? $ideas : array();
}

function ideas_inbox_save_ideas( array $ideas ) {
	update_user_meta( get_current_user_id(), IDEAS_INBOX_META_KEY, array_values( $ideas ) );
}

function ideas_inbox_render_widget() {
	$ideas   = ideas_inbox_get_ideas();
	$total   = count( $ideas );
	$visible = array_slice( $ideas, -5, 5, true );
	?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:1em;">
		<input type="hidden" name="action" value="ideas_inbox_add" />
		<?php wp_nonce_field( IDEAS_INBOX_NONCE ); ?>
		<textarea
			name="idea"
			rows="2"
			style="width:100%;"
			placeholder="<?php esc_attr_e( 'Drop an idea for future you…', 'ideas-dashboard-widget' ); ?>"
			required
		></textarea>
		<p style="text-align:right;margin:.5em 0 0;">
			<button type="submit" class="button button-primary">
				<?php esc_html_e( 'Add idea', 'ideas-dashboard-widget' ); ?>
			</button>
		</p>
	</form>

	<?php if ( empty( $ideas ) ) : ?>
		<p style="color:#666;"><em><?php esc_html_e( 'No ideas yet. Drop one above.', 'ideas-dashboard-widget' ); ?></em></p>
	<?php else : ?>
		<?php ideas_inbox_render_list( array_reverse( $visible, true ) ); ?>
		<?php if ( $total > 5 ) : ?>
			<p style="text-align:right;margin:.75em 0 0;">
				<a href="<?php echo esc_url( add_query_arg( 'page', IDEAS_INBOX_PAGE_SLUG, admin_url( 'edit.php' ) ) ); ?>">
					<?php
					/* translators: %d: total number of ideas */
					echo esc_html( sprintf( __( 'View all ideas (%d) →', 'ideas-dashboard-widget' ), $total ) );
					?>
				</a>
			</p>
		<?php endif; ?>
	<?php endif; ?>
	<?php
}

function ideas_inbox_render_page() {
	if ( ! current_user_can( 'edit_posts' ) ) {
		wp_die( esc_html__( 'You do not have permission to view this page.', 'ideas-dashboard-widget' ) );
	}

	$ideas        = ideas_inbox_get_ideas();
	$total        = count( $ideas );
	$per_page     = 20;
	$total_pages  = max( 1, (int) ceil( $total / $per_page ) );
	$paged        = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
	$paged        = min( $paged, $total_pages );
	$offset       = ( $paged - 1 ) * $per_page;
	$newest_first = array_reverse( $ideas, true );
	$page_slice   = array_slice( $newest_first, $offset, $per_page, true );
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Ideas Inbox', 'ideas-dashboard-widget' ); ?></h1>

		<?php if ( empty( $ideas ) ) : ?>
			<p><em><?php esc_html_e( 'No ideas yet. Head to your dashboard to add one.', 'ideas-dashboard-widget' ); ?></em></p>
		<?php else : ?>
			<?php ideas_inbox_render_list( $page_slice ); ?>
			<?php if ( $total_pages > 1 ) : ?>
				<div class="tablenav" style="margin-top:1em;">
					<div class="tablenav-pages">
						<?php
						echo paginate_links(
							array(
								'base'      => add_query_arg(
									array(
										'page'  => IDEAS_INBOX_PAGE_SLUG,
										'paged' => '%#%',
									),
									admin_url( 'edit.php' )
								),
								'format'    => '',
								'current'   => $paged,
								'total'     => $total_pages,
								'prev_text' => __( '« Previous', 'ideas-dashboard-widget' ),
								'next_text' => __( 'Next »', 'ideas-dashboard-widget' ),
							)
						);
						?>
					</div>
				</div>
			<?php endif; ?>
		<?php endif; ?>
	</div>
	<?php
}

function ideas_inbox_render_list( array $ideas ) {
	?>
	<ul style="margin:0;padding:0;list-style:none;">
		<?php foreach ( $ideas as $index => $idea ) : ?>
			<li style="padding:.6em 0;border-top:1px solid #eee;">
				<div style="white-space:pre-wrap;"><?php echo esc_html( $idea['text'] ); ?></div>
				<div style="font-size:.85em;color:#666;margin-top:.25em;">
					<?php
					/* translators: %s: human-readable time difference, e.g. "3 hours" */
					echo esc_html( sprintf( __( '%s ago', 'ideas-dashboard-widget' ), human_time_diff( (int) $idea['time'] ) ) );
					?>
					&nbsp;·&nbsp;
					<a href="<?php echo esc_url( ideas_inbox_action_url( 'ideas_inbox_draft', $index ) ); ?>">
						<?php esc_html_e( 'Turn into draft', 'ideas-dashboard-widget' ); ?>
					</a>
					&nbsp;·&nbsp;
					<a href="<?php echo esc_url( ideas_inbox_action_url( 'ideas_inbox_delete', $index ) ); ?>" style="color:#b32d2e;">
						<?php esc_html_e( 'Delete', 'ideas-dashboard-widget' ); ?>
					</a>
				</div>
			</li>
		<?php endforeach; ?>
	</ul>
	<?php
}

function ideas_inbox_action_url( $action, $index ) {
	return wp_nonce_url(
		add_query_arg(
			array(
				'action' => $action,
				'index'  => (int) $index,
			),
			admin_url( 'admin-post.php' )
		),
		IDEAS_INBOX_NONCE
	);
}

function ideas_inbox_verify_request() {
	if ( ! current_user_can( 'edit_posts' ) ) {
		wp_die( esc_html__( 'You do not have permission to manage ideas.', 'ideas-dashboard-widget' ) );
	}
	check_admin_referer( IDEAS_INBOX_NONCE );
}

function ideas_inbox_redirect_back() {
	$referer = wp_get_referer();
	wp_safe_redirect( $referer ? $referer : admin_url( 'index.php' ) );
	exit;
}

function ideas_inbox_handle_add() {
	ideas_inbox_verify_request();

	$raw = isset( $_POST['idea'] ) ? wp_unslash( $_POST['idea'] ) : '';
	$text = sanitize_textarea_field( $raw );

	if ( '' !== trim( $text ) ) {
		$ideas   = ideas_inbox_get_ideas();
		$ideas[] = array(
			'text' => $text,
			'time' => time(),
		);
		ideas_inbox_save_ideas( $ideas );
	}

	ideas_inbox_redirect_back();
}

function ideas_inbox_handle_delete() {
	ideas_inbox_verify_request();

	$index = isset( $_GET['index'] ) ? (int) $_GET['index'] : -1;
	$ideas = ideas_inbox_get_ideas();

	if ( isset( $ideas[ $index ] ) ) {
		unset( $ideas[ $index ] );
		ideas_inbox_save_ideas( $ideas );
	}

	ideas_inbox_redirect_back();
}

function ideas_inbox_handle_draft() {
	ideas_inbox_verify_request();

	$index = isset( $_GET['index'] ) ? (int) $_GET['index'] : -1;
	$ideas = ideas_inbox_get_ideas();

	if ( ! isset( $ideas[ $index ] ) ) {
		ideas_inbox_redirect_back();
	}

	$idea    = $ideas[ $index ];
	$post_id = wp_insert_post(
		array(
			'post_title'   => wp_trim_words( $idea['text'], 10, '…' ),
			'post_content' => "<!-- wp:paragraph -->\n<p>" . str_replace( "\n", '<br>', esc_html( $idea['text'] ) ) . "</p>\n<!-- /wp:paragraph -->",
			'post_status'  => 'draft',
			'post_author'  => get_current_user_id(),
		),
		true
	);

	if ( is_wp_error( $post_id ) || ! $post_id ) {
		ideas_inbox_redirect_back();
	}

	unset( $ideas[ $index ] );
	ideas_inbox_save_ideas( $ideas );

	wp_safe_redirect( get_edit_post_link( $post_id, 'redirect' ) );
	exit;
}
