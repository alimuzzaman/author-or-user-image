<?php
/**
 * WordPress personal-data integration.
 *
 * @package AuthorOrUserImage
 */

namespace AuthorOrUserImage;

defined( 'ABSPATH' ) || exit;

/**
 * Exports and erases avatar data through WordPress privacy tools.
 */
final class Privacy {
	/**
	 * Image storage service.
	 *
	 * @var Image_Store
	 */
	private $store;

	/**
	 * Privacy mutation coordinator.
	 *
	 * @var User_Cleanup
	 */
	private $cleanup;

	/**
	 * Set up privacy hooks.
	 *
	 * @param Image_Store       $store Image storage service.
	 * @param User_Cleanup|null $cleanup Privacy cleanup coordinator.
	 */
	public function __construct( Image_Store $store, ?User_Cleanup $cleanup = null ) {
		$this->store   = $store;
		$this->cleanup = null !== $cleanup ? $cleanup : new User_Cleanup( $store );
		add_action( 'admin_init', array( $this, 'add_policy_content' ) );
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_eraser' ) );
	}

	/**
	 * Add suggested text to the site's Privacy Policy guide.
	 *
	 * @return void
	 */
	public function add_policy_content() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		wp_add_privacy_policy_content(
			__( 'Author or User Image', 'author-or-user-image' ),
			'<p>' . esc_html__( 'This plugin stores profile avatar images in this site’s WordPress Media Library. It may process the user ID, avatar attachment ID, avatar URL, blocked-upload setting, and short upload-attempt counters and reset times for the relevant account. Center cropping retains the uncropped original and its metadata in the Media Library. The plugin does not send this data to an external service.', 'author-or-user-image' ) . '</p>'
		);
	}

	/**
	 * Register the exporter.
	 *
	 * @param array $exporters Registered exporters.
	 * @return array
	 */
	public function register_exporter( $exporters ) {
		$exporters['author-or-user-image'] = array(
			'exporter_friendly_name' => __( 'Author or User Image', 'author-or-user-image' ),
			'callback'               => array( $this, 'export' ),
		);

		return $exporters;
	}

	/**
	 * Register the eraser.
	 *
	 * @param array $erasers Registered erasers.
	 * @return array
	 */
	public function register_eraser( $erasers ) {
		$erasers['author-or-user-image'] = array(
			'eraser_friendly_name' => __( 'Author or User Image', 'author-or-user-image' ),
			'callback'             => array( $this, 'erase' ),
		);

		return $erasers;
	}

	/**
	 * Export one user's avatar information.
	 *
	 * @param string $email_address User email address.
	 * @param int    $page          Export page.
	 * @return array
	 */
	public function export( $email_address, $page = 1 ) {
		$user = get_user_by( 'email', sanitize_email( $email_address ) );
		if ( ! $user || 1 !== (int) $page ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		$data = array();
		$url  = $this->store->get_url( $user->ID, $this->store->get_size(), false );
		if ( $url ) {
			$data[] = array(
				'name'  => __( 'Avatar URL', 'author-or-user-image' ),
				'value' => $url,
			);
		}
		$data[] = array(
			'name'  => __( 'Avatar uploads blocked', 'author-or-user-image' ),
			'value' => $this->store->is_blocked( $user->ID ) ? __( 'Yes', 'author-or-user-image' ) : __( 'No', 'author-or-user-image' ),
		);

		$rate = get_user_meta( $user->ID, Image_Store::user_meta_key( Image_Store::UPLOAD_RATE_META ), true );
		if ( is_array( $rate ) ) {
			foreach ( array(
				'minute' => 60,
				'hour'   => 3600,
			) as $window => $seconds ) {
				if ( isset( $rate[ $window . '_count' ], $rate[ $window . '_start' ] ) && is_int( $rate[ $window . '_count' ] ) && is_int( $rate[ $window . '_start' ] ) ) {
					$data[] = array(
						'name'  => 'minute' === $window ? __( 'Upload attempts in minute window', 'author-or-user-image' ) : __( 'Upload attempts in hour window', 'author-or-user-image' ),
						'value' => (string) $rate[ $window . '_count' ],
					);
					$data[] = array(
						'name'  => 'minute' === $window ? __( 'Minute upload window resets at UTC', 'author-or-user-image' ) : __( 'Hour upload window resets at UTC', 'author-or-user-image' ),
						'value' => gmdate( 'Y-m-d H:i:s', $rate[ $window . '_start' ] + $seconds ),
					);
				}
			}
		}

		return array(
			'data' => array(
				array(
					'group_id'    => 'author-or-user-image',
					'group_label' => __( 'Author or User Image', 'author-or-user-image' ),
					'item_id'     => 'user-' . $user->ID,
					'data'        => $data,
				),
			),
			'done' => true,
		);
	}

	/**
	 * Erasure succeeds only after owner cleanup; transient busy retries stop after five pages.
	 *
	 * @param string $email_address Account email.
	 * @param int    $page Retry page; retries must attempt the same idempotent cleanup.
	 * @return array{items_removed:bool,items_retained:bool,messages:array,done:bool}
	 */
	public function erase( $email_address, $page = 1 ) {
		$user     = get_user_by( 'email', sanitize_email( $email_address ) );
		$retained = false;
		if ( $user ) {
			$retained = $this->cleanup->erase_user_data( $user->ID );
			if ( is_wp_error( $retained ) ) {
				return array(
					'items_removed'  => false,
					'items_retained' => true,
					'messages'       => array( 'zmai_busy' === $retained->get_error_code() && absint( $page ) >= 5 ? __( 'Avatar data is still locked. Ask an administrator to check the retained data before starting another erasure.', 'author-or-user-image' ) : $retained->get_error_message() ),
					'done'           => 'zmai_busy' !== $retained->get_error_code() || absint( $page ) >= 5,
				);
			}
		}

		return array(
			'items_removed'  => (bool) $user,
			'items_retained' => $retained,
			'messages'       => $retained ? array( __( 'A legacy avatar original was retained because other sites in this network may still use it. The avatar has been removed from this site.', 'author-or-user-image' ) ) : array(),
			'done'           => true,
		);
	}
}
