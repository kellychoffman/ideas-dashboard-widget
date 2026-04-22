<?php
/**
 * Plugin Name: Ideas Dashboard Widget
 * Description: An ideas inbox for your WP dashboard. Drop ideas for your future self to blog about.
 * Version:     0.1.0
 * Author:      Kelly Hoffman
 * License:     GPL-2.0-or-later
 * Text Domain: ideas-dashboard-widget
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const IDEAS_INBOX_META_KEY = 'ideas_inbox';
const IDEAS_INBOX_NONCE    = 'ideas_inbox';

add_action( 'wp_dashboard_setup', 'ideas_inbox_register_widget' );
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

function ideas_inbox_get_ideas() {
	$ideas = get_user_meta( get_current_user_id(), IDEAS_INBOX_META_KEY, true );
	return is_array( $ideas ) ? $ideas : array();
}

function ideas_inbox_save_ideas( array $ideas ) {
	update_user_meta( get_current_user_id(), IDEAS_INBOX_META_KEY, array_values( $ideas ) );
}

function ideas_inbox_render_widget() {
	$ideas = ideas_inbox_get_ideas();
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
		<ul style="margin:0;padding:0;list-style:none;">
			<?php foreach ( array_reverse( $ideas, true ) as $index => $idea ) : ?>
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
	<?php endif; ?>
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
	wp_safe_redirect( admin_url( 'index.php' ) );
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
			'post_content' => $idea['text'],
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
