<?php
/**
 * Current-user avatar forms for published content.
 *
 * @package AuthorOrUserImage
 */

namespace AuthorOrUserImage;

defined( 'ABSPATH' ) || exit;

/** Shares one native form between the block and shortcode. */
final class Front_End {
	/** Block name from blocks/avatar-upload/block.json. */
	const BLOCK_NAME = 'author-or-user-image/avatar-upload';

	/** Shortcode tag registered in register(). */
	const SHORTCODE = 'author_or_user_image_upload';

	/**
	 * Avatar storage.
	 *
	 * @var Image_Store
	 */
	private $store;

	/**
	 * Upload eligibility.
	 *
	 * @var Upload_Policy
	 */
	private $policy;

	/**
	 * Register integration hooks.
	 *
	 * @param Image_Store $store Avatar storage.
	 */
	public function __construct( Image_Store $store ) {
		$this->store  = $store;
		$this->policy = new Upload_Policy( $store );
		add_action( 'init', array( $this, 'register' ) );
		add_action( 'template_redirect', array( $this, 'prevent_cache' ), 0 );
	}

	/**
	 * Register metadata, the editor placeholder and shared stylesheet.
	 *
	 * @return void
	 */
	public function register() {
		wp_register_style( 'zmai-front-end', ZMAI_URL . 'assets/css/front-end.css', array(), ZMAI_VERSION );
		wp_register_script( 'zmai-avatar-upload-editor', ZMAI_URL . 'assets/js/avatar-upload-editor.js', array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n' ), ZMAI_VERSION, true );
		wp_set_script_translations( 'zmai-avatar-upload-editor', 'author-or-user-image' );
		register_block_type( ZMAI_PATH . 'blocks/avatar-upload', array( 'render_callback' => array( $this, 'render_form' ) ) );
		add_shortcode( 'author_or_user_image_upload', array( $this, 'render_form' ) );
	}

	/**
	 * Early no-cache headers for queried content that contains the upload block or shortcode.
	 *
	 * Page caches must also exclude pages containing these forms. A callback
	 * cannot bypass a cache that served the request before WordPress loaded.
	 * Other signed-in pages stay cacheable; rendered forms mark themselves.
	 *
	 * @return void
	 */
	public function prevent_cache() {
		if ( is_user_logged_in() && self::queried_content_has_form() ) {
			$this->mark_uncacheable();
		}
	}

	/**
	 * Whether the main query's post contains the block or shortcode.
	 *
	 * @return bool
	 */
	private static function queried_content_has_form() {
		$post = get_queried_object();
		if ( ! $post instanceof \WP_Post ) {
			return false;
		}
		return has_block( self::BLOCK_NAME, $post->post_content ) || has_shortcode( $post->post_content, self::SHORTCODE );
	}

	/**
	 * Mark the response uncacheable for page caches and send no-cache headers.
	 *
	 * @return void
	 */
	private function mark_uncacheable() {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- This is the established page-cache interoperability constant.
		}
		if ( ! headers_sent() ) {
			nocache_headers();
		}
	}

	/**
	 * Validate against this site's origin and path, independently of host filters.
	 *
	 * @param mixed $url Untrusted absolute return URL.
	 * @return string Safe return URL, or the site's home page.
	 */
	public static function safe_return_url( $url ) {
		$home = home_url( '/' );
		if ( ! is_string( $url ) || '' === $url || preg_match( '/[\x00-\x20\x7f\\\\]/', $url ) ) {
			return $home;
		}
		$decoded = $url;
		for ( $i = 0; $i < 3; ++$i ) {
			$decoded = rawurldecode( $decoded );
		}
		if ( preg_match( '/[\x00-\x1f\x7f\\\\]/', $decoded ) ) {
			return $home;
		}
		$base   = wp_parse_url( $home );
		$target = wp_parse_url( $url );
		if ( ! is_array( $target ) || isset( $target['user'] ) || isset( $target['pass'] ) || empty( $target['scheme'] ) || empty( $target['host'] ) ) {
			return $home;
		}
		foreach ( array( 'scheme', 'host' ) as $part ) {
			if ( strtolower( $base[ $part ] ) !== strtolower( $target[ $part ] ) ) {
				return $home;
			}
		}
		$base_port   = isset( $base['port'] ) ? $base['port'] : ( 'https' === $base['scheme'] ? 443 : 80 );
		$target_port = isset( $target['port'] ) ? $target['port'] : ( 'https' === strtolower( $target['scheme'] ) ? 443 : 80 );
		if ( $base_port !== $target_port ) {
			return $home;
		}
		$path   = isset( $target['path'] ) ? $target['path'] : '/';
		$prefix = isset( $base['path'] ) ? $base['path'] : '/';
		for ( $i = 0; $i < 3; ++$i ) {
			$path   = rawurldecode( $path );
			$prefix = rawurldecode( $prefix );
		}
		$prefix = trailingslashit( $prefix );
		if ( 0 !== strpos( trailingslashit( $path ), $prefix ) || preg_match( '~(?:^|/)(?:\.{1,2}|wp-admin|wp-login\.php)(?:/|$)~i', $path ) || false !== strpos( $path, '%' ) || false !== strpos( $path, '//' ) ) {
			return $home;
		}
		$query = array();
		if ( isset( $target['query'] ) ) {
			wp_parse_str( $target['query'], $query );
		}
		if ( isset( $query['action'] ) || isset( $query['_wpnonce'] ) || isset( $query['redirect_to'] ) ) {
			return $home;
		}
		return remove_query_arg( array( 'zmai_status', 'zmai_error' ), $url );
	}

	/**
	 * Map only fixed result codes to translated text.
	 *
	 * @param mixed $status Result code.
	 * @return string Notice or empty string.
	 */
	public static function status_message( $status ) {
		$messages = array(
			'uploaded'         => __( 'The image was uploaded.', 'author-or-user-image' ),
			'removed'          => __( 'The image was removed.', 'author-or-user-image' ),
			'denied'           => __( 'Local avatar uploads are disabled for your account.', 'author-or-user-image' ),
			'rate-limited'     => __( 'Too many avatar uploads. Please wait before trying again.', 'author-or-user-image' ),
			'upload-error'     => __( 'The image could not be saved. Check the file and try again. If the problem continues, contact an administrator.', 'author-or-user-image' ),
			'remove-error'     => __( 'The image could not be removed. Please retry. If the problem continues, contact an administrator.', 'author-or-user-image' ),
			'upload-too-large' => __( 'The upload exceeds the server request limit. Choose a smaller image.', 'author-or-user-image' ),
		);
		return is_string( $status ) && isset( $messages[ $status ] ) ? $messages[ $status ] : '';
	}

	/**
	 * Render for the current visitor using allowlisted presentation attributes only.
	 *
	 * @param mixed $attributes Block or shortcode attributes.
	 * @return string Escaped form markup.
	 */
	public function render_form( $attributes = array() ) {
		// Widgets and templates render after the early hook, so the form marks its own page.
		if ( is_user_logged_in() ) {
			$this->mark_uncacheable();
		}
		wp_enqueue_style( 'zmai-front-end' );
		ob_start();
		// Shortcodes in widgets/templates may run after the theme printed its head.
		if ( did_action( 'wp_head' ) && ! wp_style_is( 'zmai-front-end', 'done' ) ) {
			wp_print_styles( array( 'zmai-front-end' ) );
		}
		$opts          = $this->normalize_attributes( $attributes );
		$wrapper_class = 'zmai-front-end' . ( '' !== $opts['alignment'] ? ' zmai-align-' . $opts['alignment'] : '' );
		$home          = wp_parse_url( home_url( '/' ) );
		$origin        = $home['scheme'] . '://' . $home['host'] . ( isset( $home['port'] ) ? ':' . $home['port'] : '' );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- The strict origin/path allowlist below validates this URL before use.
		$request = isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
		$return  = self::safe_return_url( $origin . $request );
		?>
		<div class="<?php echo esc_attr( $wrapper_class ); ?>">
		<?php if ( ! is_user_logged_in() ) : ?>
			<p><a href="<?php echo esc_url( wp_login_url( $return ) ); ?>"><?php echo esc_html( $opts['loginLabel'] ); ?></a></p>
		<?php else : ?>
			<?php
			$user_id      = get_current_user_id();
			$id           = wp_unique_id( 'zmai-avatar-' );
			$preview_size = $opts['previewSize'] > 0 ? $opts['previewSize'] : $this->store->get_size();
			$blocked      = $this->store->is_blocked( $user_id );
			$url          = $blocked ? '' : $this->store->get_url( $user_id, $preview_size, false );
			$action       = add_query_arg(
				array(
					'action'       => 'zmai_save_avatar',
					'zmai_context' => 'front',
					'zmai_return'  => $return,
				),
				admin_url( 'admin-post.php' )
			);
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Read-only fixed-code notice; raw text is never rendered.
			$status = isset( $_GET['zmai_status'] ) ? wp_unslash( $_GET['zmai_status'] ) : '';
			?>
			<div class="zmai-status" role="status" aria-live="polite"><p><?php echo esc_html( self::status_message( $status ) ); ?></p></div>
			<?php if ( $url && $opts['showPreview'] ) : ?>
				<img class="zmai-avatar-preview" src="<?php echo esc_url( $url ); ?>" alt="<?php esc_attr_e( 'Your current avatar', 'author-or-user-image' ); ?>" width="<?php echo absint( $preview_size ); ?>" height="<?php echo absint( $preview_size ); ?>">
			<?php endif; ?>
			<?php if ( $blocked ) : ?>
				<p><?php esc_html_e( 'An administrator has disabled local avatar uploads for your account.', 'author-or-user-image' ); ?></p>
			<?php elseif ( $this->policy->can_upload( $user_id ) ) : ?>
				<form class="zmai-upload-form" method="post" enctype="multipart/form-data" action="<?php echo esc_url( $action ); ?>">
					<input type="hidden" name="action" value="zmai_save_avatar">
					<input type="hidden" name="avatar_type" value="user">
					<input type="hidden" name="zmai_context" value="front">
					<input type="hidden" name="zmai_return" value="<?php echo esc_url( $return ); ?>">
					<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( 'zmai_save_avatar' ) ); ?>">
					<label for="<?php echo esc_attr( $id ); ?>"><?php esc_html_e( 'Choose your avatar image', 'author-or-user-image' ); ?></label>
					<input type="file" id="<?php echo esc_attr( $id ); ?>" name="user_avatar" accept="image/jpeg,image/png,image/webp" aria-describedby="<?php echo esc_attr( $id . '-help' ); ?>" required>
					<p id="<?php echo esc_attr( $id . '-help' ); ?>">
					<?php
						printf(
							/* translators: %s: Maximum upload file size supported by this site. */
							esc_html__( 'Choose a JPEG, PNG or WebP image up to %s and 4096 by 4096 pixels. Animated images are not supported. Your image will be cropped to a square from the center.', 'author-or-user-image' ),
							esc_html( size_format( min( wp_max_upload_size(), Image_Processor::MAX_FILE_SIZE ) ) )
						);
					?>
					</p>
					<button type="submit"><?php echo esc_html( $opts['uploadLabel'] ); ?></button>
				</form>
			<?php else : ?>
				<p><?php esc_html_e( 'Your role is not allowed to upload a new avatar on this site. You can still remove your avatar.', 'author-or-user-image' ); ?></p>
			<?php endif; ?>
			<?php if ( $url && ! $blocked && current_user_can( 'read' ) ) : ?>
				<form class="zmai-remove-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="zmai_remove_avatar">
					<input type="hidden" name="avatar_type" value="user">
					<input type="hidden" name="zmai_context" value="front">
					<input type="hidden" name="zmai_return" value="<?php echo esc_url( $return ); ?>">
					<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( 'zmai_remove_avatar' ) ); ?>">
					<button type="submit"><?php echo esc_html( $opts['removeLabel'] ); ?></button>
				</form>
			<?php endif; ?>
		<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Strictly normalize allowlisted presentation attributes for both block and shortcode callers.
	 *
	 * @param mixed $attributes Untrusted caller attributes.
	 * @return array{showPreview:bool,previewSize:int,alignment:string,uploadLabel:string,removeLabel:string,loginLabel:string}
	 */
	private function normalize_attributes( $attributes ) {
		$attrs = is_array( $attributes ) ? $attributes : array();

		$raw_show     = $this->pick_attribute( $attrs, 'showPreview', 'show_preview', true );
		$show_preview = true;
		if ( is_bool( $raw_show ) ) {
			$show_preview = $raw_show;
		} elseif ( is_int( $raw_show ) ) {
			$show_preview = 0 !== $raw_show;
		} elseif ( is_string( $raw_show ) ) {
			$lower = strtolower( trim( $raw_show ) );
			if ( in_array( $lower, array( 'false', '0', 'no' ), true ) ) {
				$show_preview = false;
			} elseif ( in_array( $lower, array( 'true', '1', 'yes' ), true ) ) {
				$show_preview = true;
			}
		}

		$raw_size     = $this->pick_attribute( $attrs, 'previewSize', 'preview_size', 0 );
		$preview_size = 0;
		if ( ( is_int( $raw_size ) || ( is_string( $raw_size ) && preg_match( '/^[0-9]+$/D', trim( $raw_size ) ) ) ) && (int) $raw_size > 0 ) {
			$preview_size = min( 512, max( 32, (int) $raw_size ) );
		}

		$raw_align = $attrs['alignment'] ?? '';
		$alignment = is_string( $raw_align ) && in_array( $raw_align, array( 'left', 'center', 'right' ), true ) ? $raw_align : '';

		return array(
			'showPreview' => $show_preview,
			'previewSize' => $preview_size,
			'alignment'   => $alignment,
			'uploadLabel' => $this->sanitize_label(
				$this->pick_attribute( $attrs, 'uploadLabel', 'upload_label', '' ),
				80,
				__( 'Upload image', 'author-or-user-image' )
			),
			'removeLabel' => $this->sanitize_label(
				$this->pick_attribute( $attrs, 'removeLabel', 'remove_label', '' ),
				80,
				__( 'Remove image', 'author-or-user-image' )
			),
			'loginLabel'  => $this->sanitize_label(
				$this->pick_attribute( $attrs, 'loginLabel', 'login_label', '' ),
				120,
				__( 'Log in to manage your avatar.', 'author-or-user-image' )
			),
		);
	}

	/**
	 * Read an attribute supporting block camelCase, shortcode snake_case, and lowercased shortcode keys.
	 *
	 * @param array<string,mixed> $attrs   Caller attribute map.
	 * @param string              $camel   CamelCase attribute key.
	 * @param string              $snake   Snake_case attribute key.
	 * @param mixed               $fallback Default value when unset.
	 * @return mixed
	 */
	private function pick_attribute( array $attrs, $camel, $snake, $fallback ) {
		if ( array_key_exists( $camel, $attrs ) ) {
			return $attrs[ $camel ];
		}
		if ( array_key_exists( $snake, $attrs ) ) {
			return $attrs[ $snake ];
		}
		$lower = strtolower( $camel );
		if ( array_key_exists( $lower, $attrs ) ) {
			return $attrs[ $lower ];
		}
		return $fallback;
	}

	/**
	 * Sanitize and bound a plain-text UI label attribute.
	 *
	 * @param mixed  $value    Raw attribute value.
	 * @param int    $max_len  Maximum length.
	 * @param string $fallback Default translated label.
	 * @return string
	 */
	private function sanitize_label( $value, $max_len, $fallback ) {
		if ( ! is_string( $value ) ) {
			return $fallback;
		}
		$clean = trim( sanitize_text_field( $value ) );
		if ( '' === $clean ) {
			return $fallback;
		}
		return function_exists( 'mb_substr' ) ? mb_substr( $clean, 0, $max_len ) : substr( $clean, 0, $max_len );
	}
}
