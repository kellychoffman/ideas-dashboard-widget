<?php
/**
 * Plugin Name: Ideas Inbox
 * Description: An ideas inbox for your WP dashboard. Drop ideas for your future self to blog about.
 * Version:     0.4.0
 * Author:      Kelly Hoffman
 * License:     GPL-2.0-or-later
 * Text Domain: ideas-dashboard-widget
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const IDEAS_INBOX_VERSION   = '0.4.0';
const IDEAS_INBOX_META_KEY  = 'ideas_inbox';
const IDEAS_INBOX_NONCE     = 'ideas_inbox';
const IDEAS_INBOX_PAGE_SLUG = 'ideas-inbox';

add_action( 'wp_dashboard_setup',    'ideas_inbox_register_widget' );
add_action( 'admin_menu',            'ideas_inbox_register_page' );
add_action( 'admin_enqueue_scripts', 'ideas_inbox_enqueue_assets' );
add_action( 'admin_post_ideas_inbox_add',    'ideas_inbox_handle_add' );
add_action( 'admin_post_ideas_inbox_delete', 'ideas_inbox_handle_delete' );
add_action( 'admin_post_ideas_inbox_draft',  'ideas_inbox_handle_draft' );
add_action( 'rest_api_init',                 'ideas_inbox_register_rest_routes' );

function ideas_inbox_enqueue_assets( $hook ) {
	$ideas_inbox_page_hook = 'posts_page_' . IDEAS_INBOX_PAGE_SLUG;
	if ( 'index.php' !== $hook && $ideas_inbox_page_hook !== $hook ) {
		return;
	}
	if ( ! current_user_can( 'edit_posts' ) ) {
		return;
	}
	wp_enqueue_style( 'dashicons' );
	wp_enqueue_style( 'wp-components' );
	wp_enqueue_style(
		'ideas-inbox',
		plugins_url( 'assets/ideas-inbox.css', __FILE__ ),
		array( 'dashicons', 'wp-components' ),
		IDEAS_INBOX_VERSION
	);
	wp_enqueue_script(
		'ideas-inbox',
		plugins_url( 'assets/ideas-inbox.js', __FILE__ ),
		array( 'wp-api-fetch', 'wp-components', 'wp-element', 'wp-i18n' ),
		IDEAS_INBOX_VERSION,
		true
	);
	wp_add_inline_script(
		'ideas-inbox',
		'window.IdeasInbox = ' . wp_json_encode(
			array(
				'viewAllUrl' => add_query_arg( 'page', IDEAS_INBOX_PAGE_SLUG, admin_url( 'edit.php' ) ),
			)
		) . ';',
		'before'
	);
	wp_set_script_translations( 'ideas-inbox', 'ideas-dashboard-widget' );

	// Block editor experiment — only on the dashboard, where the widget lives.
	if ( 'index.php' === $hook ) {
		ideas_inbox_enqueue_block_editor();
	}
}

function ideas_inbox_enqueue_block_editor() {
	$asset_file = __DIR__ . '/assets/build/widget-editor.asset.php';
	if ( ! file_exists( $asset_file ) ) {
		return;
	}
	$asset = include $asset_file;

	wp_enqueue_style(
		'ideas-inbox-editor',
		plugins_url( 'assets/build/widget-editor.css', __FILE__ ),
		array( 'wp-components' ),
		$asset['version']
	);
	wp_enqueue_style(
		'ideas-inbox-editor-style',
		plugins_url( 'assets/build/style-widget-editor.css', __FILE__ ),
		array(),
		$asset['version']
	);
	wp_enqueue_script(
		'ideas-inbox-editor',
		plugins_url( 'assets/build/widget-editor.js', __FILE__ ),
		$asset['dependencies'],
		$asset['version'],
		true
	);
	wp_set_script_translations( 'ideas-inbox-editor', 'ideas-dashboard-widget' );
}

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
	if ( ! is_array( $ideas ) ) {
		return array();
	}

	$dirty = false;
	foreach ( $ideas as $k => $idea ) {
		if ( empty( $idea['id'] ) ) {
			$ideas[ $k ]['id'] = wp_generate_uuid4();
			$dirty = true;
		}
	}
	if ( $dirty ) {
		ideas_inbox_save_ideas( $ideas );
	}
	return $ideas;
}

function ideas_inbox_save_ideas( array $ideas ) {
	update_user_meta( get_current_user_id(), IDEAS_INBOX_META_KEY, array_values( $ideas ) );
}

function ideas_inbox_find_index_by_id( array $ideas, $id ) {
	foreach ( $ideas as $k => $idea ) {
		if ( isset( $idea['id'] ) && hash_equals( $idea['id'], (string) $id ) ) {
			return $k;
		}
	}
	return false;
}

function ideas_inbox_render_widget() {
	$ideas   = ideas_inbox_get_ideas();
	$total   = count( $ideas );
	$visible = array_slice( $ideas, -5, 5, true );
	?>
	<div class="ideas-inbox">
		<form class="ideas-inbox__form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="ideas_inbox_add" />
			<?php wp_nonce_field( IDEAS_INBOX_NONCE ); ?>
			<label class="screen-reader-text" for="ideas-inbox-idea">
				<?php esc_html_e( 'New idea', 'ideas-dashboard-widget' ); ?>
			</label>
			<div class="ideas-inbox__editor-mount" aria-hidden="true"></div>
			<textarea
				id="ideas-inbox-idea"
				class="ideas-inbox__textarea"
				name="idea"
				rows="7"
				placeholder="<?php esc_attr_e( 'Jot it down…', 'ideas-dashboard-widget' ); ?>"
				required
			></textarea>
			<p class="ideas-inbox__form-actions">
				<button type="submit" class="button button-primary">
					<?php esc_html_e( 'Add idea', 'ideas-dashboard-widget' ); ?>
				</button>
			</p>
		</form>

		<?php if ( 0 === $total ) : ?>
			<div class="ideas-inbox__empty">
				<span class="dashicons dashicons-lightbulb" aria-hidden="true"></span>
				<p class="ideas-inbox__empty-title">
					<?php esc_html_e( 'No ideas yet', 'ideas-dashboard-widget' ); ?>
				</p>
				<p class="ideas-inbox__empty-hint">
					<?php esc_html_e( 'Drop one above to save it for later.', 'ideas-dashboard-widget' ); ?>
				</p>
			</div>
		<?php else : ?>
			<div class="ideas-inbox__header">
				<span class="ideas-inbox__count">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: number of saved ideas */
							_n( '%s idea', '%s ideas', $total, 'ideas-dashboard-widget' ),
							number_format_i18n( $total )
						)
					);
					?>
				</span>
			</div>
			<?php ideas_inbox_render_list( array_reverse( $visible, true ) ); ?>
			<?php if ( $total > 5 ) : ?>
				<p class="ideas-inbox__view-all">
					<a href="<?php echo esc_url( add_query_arg( 'page', IDEAS_INBOX_PAGE_SLUG, admin_url( 'edit.php' ) ) ); ?>">
						<?php
						/* translators: %d: total number of ideas */
						echo esc_html( sprintf( __( 'View all ideas (%d) →', 'ideas-dashboard-widget' ), $total ) );
						?>
					</a>
				</p>
			<?php endif; ?>
		<?php endif; ?>
	</div>
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
	<div class="wrap ideas-inbox">
		<h1><?php esc_html_e( 'Ideas Inbox', 'ideas-dashboard-widget' ); ?></h1>

		<?php if ( empty( $ideas ) ) : ?>
			<div class="ideas-inbox__empty">
				<span class="dashicons dashicons-lightbulb" aria-hidden="true"></span>
				<p class="ideas-inbox__empty-title">
					<?php esc_html_e( 'No ideas yet', 'ideas-dashboard-widget' ); ?>
				</p>
				<p class="ideas-inbox__empty-hint">
					<?php esc_html_e( 'Head to your dashboard to add one.', 'ideas-dashboard-widget' ); ?>
				</p>
			</div>
		<?php else : ?>
			<?php ideas_inbox_render_list( $page_slice ); ?>
			<?php if ( $total_pages > 1 ) : ?>
				<div class="tablenav ideas-inbox__pagination">
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
	<ul class="ideas-inbox__list">
		<?php foreach ( $ideas as $index => $idea ) : ?>
			<?php ideas_inbox_render_row( $idea, $index ); ?>
		<?php endforeach; ?>
	</ul>
	<?php
}

function ideas_inbox_render_idea_text( $text ) {
	$text = (string) $text;
	// Block markup (from the isolated editor) — run through do_blocks then kses.
	if ( has_blocks( $text ) ) {
		return wp_kses_post( do_blocks( $text ) );
	}
	// Existing plain-text ideas: preserve newlines, escape everything else.
	return nl2br( esc_html( $text ) );
}

function ideas_inbox_render_row( array $idea, $index = 0, $include_confirm_fallback = true ) {
	$id = isset( $idea['id'] ) ? $idea['id'] : '';
	?>
	<li class="ideas-inbox__row" data-id="<?php echo esc_attr( $id ); ?>">
		<div class="ideas-inbox__row-text"><?php echo ideas_inbox_render_idea_text( $idea['text'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
		<div class="ideas-inbox__row-meta">
			<span class="ideas-inbox__row-time">
				<?php
				/* translators: %s: human-readable time difference, e.g. "3 hours" */
				echo esc_html( sprintf( __( '%s ago', 'ideas-dashboard-widget' ), human_time_diff( (int) $idea['time'] ) ) );
				?>
			</span>
			<span class="ideas-inbox__row-actions">
				<a
					class="button button-small"
					href="<?php echo esc_url( ideas_inbox_action_url( 'ideas_inbox_draft', $index ) ); ?>"
				>
					<?php esc_html_e( 'Turn into draft', 'ideas-dashboard-widget' ); ?>
				</a>
				<a
					class="ideas-inbox__delete"
					href="<?php echo esc_url( ideas_inbox_action_url( 'ideas_inbox_delete', $index ) ); ?>"
					aria-label="<?php esc_attr_e( 'Delete idea', 'ideas-dashboard-widget' ); ?>"
					<?php if ( $include_confirm_fallback ) : ?>
					onclick="return confirm( <?php echo wp_json_encode( __( 'Delete this idea?', 'ideas-dashboard-widget' ) ); ?> );"
					<?php endif; ?>
				>
					<span class="dashicons dashicons-trash" aria-hidden="true"></span>
				</a>
			</span>
		</div>
	</li>
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

	$raw  = isset( $_POST['idea'] ) ? wp_unslash( $_POST['idea'] ) : '';
	$text = wp_kses_post( $raw );

	if ( '' !== trim( wp_strip_all_tags( $text ) ) ) {
		$ideas   = ideas_inbox_get_ideas();
		$ideas[] = array(
			'id'   => wp_generate_uuid4(),
			'text' => $text,
			'time' => time(),
		);
		ideas_inbox_save_ideas( $ideas );
	}

	ideas_inbox_redirect_back();
}

function ideas_inbox_register_rest_routes() {
	$permission = static function () {
		return current_user_can( 'edit_posts' );
	};

	register_rest_route(
		'ideas-inbox/v1',
		'/ideas',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'ideas_inbox_rest_add',
			'permission_callback' => $permission,
			'args'                => array(
				'text' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'wp_kses_post',
				),
			),
		)
	);

	register_rest_route(
		'ideas-inbox/v1',
		'/ideas/(?P<id>[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12})',
		array(
			'methods'             => WP_REST_Server::DELETABLE,
			'callback'            => 'ideas_inbox_rest_delete',
			'permission_callback' => $permission,
		)
	);
}

function ideas_inbox_rest_add( WP_REST_Request $request ) {
	// $request['text'] is already sanitized via wp_kses_post.
	$text = (string) $request['text'];
	if ( '' === trim( wp_strip_all_tags( $text ) ) ) {
		return new WP_Error(
			'ideas_inbox_empty',
			__( 'Idea cannot be empty.', 'ideas-dashboard-widget' ),
			array( 'status' => 400 )
		);
	}

	$ideas = ideas_inbox_get_ideas();
	$idea  = array(
		'id'   => wp_generate_uuid4(),
		'text' => $text,
		'time' => time(),
	);
	$ideas[] = $idea;
	ideas_inbox_save_ideas( $ideas );

	// $index is passed to render_row so the rendered row's no-JS
	// delete/draft hrefs point at the correct positional admin-post URLs.
	$index = count( $ideas ) - 1;

	ob_start();
	ideas_inbox_render_row( $idea, $index, false );
	$html = ob_get_clean();

	return rest_ensure_response(
		array(
			'id'    => $idea['id'],
			'html'  => $html,
			'total' => count( $ideas ),
		)
	);
}

function ideas_inbox_rest_delete( WP_REST_Request $request ) {
	$ideas = ideas_inbox_get_ideas();
	$index = ideas_inbox_find_index_by_id( $ideas, $request['id'] );

	if ( false === $index ) {
		return new WP_Error(
			'ideas_inbox_not_found',
			__( 'Idea not found.', 'ideas-dashboard-widget' ),
			array( 'status' => 404 )
		);
	}

	unset( $ideas[ $index ] );
	$ideas = array_values( $ideas );
	ideas_inbox_save_ideas( $ideas );

	$total     = count( $ideas );
	$fill_html = null;

	// Widget shows the 5 most recent. When total is still ≥ 5, the idea
	// that should now be the bottom visible row sits at index total - 5
	// of the oldest-first array. Client decides whether to use this.
	if ( $total >= 5 ) {
		$fill_index = $total - 5;
		$fill_idea  = $ideas[ $fill_index ];
		ob_start();
		ideas_inbox_render_row( $fill_idea, $fill_index, false );
		$fill_html = ob_get_clean();
	}

	return rest_ensure_response(
		array(
			'total'     => $total,
			'fill_html' => $fill_html,
		)
	);
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

	$idea          = $ideas[ $index ];
	$has_blocks    = has_blocks( $idea['text'] );
	$title_source  = $has_blocks ? wp_strip_all_tags( $idea['text'] ) : $idea['text'];
	$post_content  = $has_blocks
		? $idea['text']
		: "<!-- wp:paragraph -->\n<p>" . str_replace( "\n", '<br>', esc_html( $idea['text'] ) ) . "</p>\n<!-- /wp:paragraph -->";
	$post_id       = wp_insert_post(
		array(
			'post_title'   => wp_trim_words( $title_source, 10, '…' ),
			'post_content' => $post_content,
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
