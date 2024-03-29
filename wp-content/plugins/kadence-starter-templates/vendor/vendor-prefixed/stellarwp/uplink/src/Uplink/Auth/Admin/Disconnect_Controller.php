<?php
/**
 * @license GPL-2.0-or-later
 *
 * Modified by kadencewp on 10-January-2024 using Strauss.
 * @see https://github.com/BrianHenryIE/strauss
 */ declare( strict_types=1 );

namespace KadenceWP\KadenceStarterTemplates\StellarWP\Uplink\Auth\Admin;

use KadenceWP\KadenceStarterTemplates\StellarWP\Uplink\Auth\Authorizer;
use KadenceWP\KadenceStarterTemplates\StellarWP\Uplink\Auth\Token\Disconnector;
use KadenceWP\KadenceStarterTemplates\StellarWP\Uplink\Notice\Notice_Handler;
use KadenceWP\KadenceStarterTemplates\StellarWP\Uplink\Notice\Notice;

final class Disconnect_Controller {

	public const ARG  = 'uplink_disconnect';
	public const SLUG = 'uplink_slug';

	/**
	 * @var Authorizer
	 */
	private $authorizer;

	/**
	 * @var Disconnector
	 */
	private $disconnect;

	/**
	 * @var Notice_Handler
	 */
	private $notice;

	/**
	 * @param  Authorizer  $authorizer  The authorizer.
	 * @param  Disconnector  $disconnect  Disconnects a Token, if the user has the capability.
	 * @param  Notice_Handler  $notice  Handles storing and displaying notices.
	 */
	public function __construct(
		Authorizer $authorizer,
		Disconnector $disconnect,
		Notice_Handler $notice
	) {
		$this->authorizer = $authorizer;
		$this->disconnect = $disconnect;
		$this->notice     = $notice;
	}

	/**
	 * Get the disconnect URL to render.
	 *
	 * @param  string  $slug  The plugin/service slug.
	 *
	 * @return string
	 */
	public function get_url( string $slug ): string {
		return wp_nonce_url( add_query_arg( [
			self::ARG  => true,
			self::SLUG => $slug,
		], get_admin_url( get_current_blog_id() ) ), self::ARG );
	}

	/**
	 * Disconnect (delete) a token if the user is allowed to.
	 *
	 * @action stellarwp/uplink/{$prefix}/admin_action_{$slug}
	 *
	 * @throws \RuntimeException
	 *
	 * @return void
	 */
	public function maybe_disconnect(): void {
		if ( empty( $_GET[ self::ARG ] ) || empty( $_GET['_wpnonce'] ) || empty( $_GET[ self::SLUG ] ) ) {
			return;
		}

		if ( ! is_admin() || wp_doing_ajax() ) {
			return;
		}

		if ( wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), self::ARG ) ) {
			if ( $this->authorizer->can_auth() && $this->disconnect->disconnect( $_GET[ self::SLUG ] ) ) {
				$this->notice->add(
					new Notice( Notice::SUCCESS,
						__( 'Token disconnected.', '%TEXTDOMAIN%' ),
						true
					)
				);
			} else {
				$this->notice->add(
					new Notice( Notice::ERROR,
						__( 'Unable to disconnect token, ensure you have admin permissions.', '%TEXTDOMAIN%' ),
						true
					)
				);
			}
		} else {
			$this->notice->add(
				new Notice( Notice::ERROR,
					__( 'Unable to disconnect token: nonce verification failed.', '%TEXTDOMAIN%' ),
					true
				)
			);
		}

		$this->maybe_redirect_back();
	}

	/**
	 * Attempts to redirect the user back to their previous dashboard page while
	 * ensuring that any "Connect" token query variables are removed if they immediately
	 * attempt to Disconnect after Connecting. This prevents them from automatically
	 * getting connected again if the nonce is still valid.
	 *
	 * This will ensure the Notices set above are displayed.
	 *
	 * @return void
	 */
	private function maybe_redirect_back(): void {
		$referer = wp_get_referer();

		if ( ! $referer ) {
			return;
		}

		$referer = remove_query_arg(
			[
				Connect_Controller::TOKEN,
				Connect_Controller::LICENSE,
				Connect_Controller::SLUG,
				Connect_Controller::NONCE,
			],
			$referer
		);

		wp_safe_redirect( esc_url_raw( $referer ) );
		exit;
	}

}
