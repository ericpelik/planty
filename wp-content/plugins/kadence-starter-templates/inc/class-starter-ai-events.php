<?php
/**
 * Class responsible for sending events AI Events to Stellar Prophecy WP AI.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use function KadenceWP\KadenceStarterTemplates\StellarWP\Uplink\get_authorization_token;
use function KadenceWP\KadenceStarterTemplates\StellarWP\Uplink\get_license_domain;
use function KadenceWP\KadenceStarterTemplates\StellarWP\Uplink\get_original_domain;
use function KadenceWP\KadenceStarterTemplates\StellarWP\Uplink\is_authorized;
use function KadenceWP\KadenceStarterTemplates\StellarWP\Uplink\get_license_key;

/**
 * Class responsible for sending events AI Events to Stellar Prophecy WP AI.
 */
class Kadence_Starter_Templates_AI_Events {

	/**
	 * The label property key for the event request.
	 */
	public const PROP_EVENT_LABEL = 'event_label';

	/**
	 * The data property key for the event request.
	 */
	public const PROP_EVENT_DATA = 'event_data';

	/**
	 * The event endpoint.
	 */
	public const ENDPOINT = '/wp-json/prophecy/v1/analytics/event';

	/**
	 * The API domain.
	 */
	public const DOMAIN = 'https://content.startertemplatecloud.com';

	/**
	 * Registers all necessary hooks.
	 *
	 * @action plugins_loaded
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'kadencestartertemplates/ai/event', [ $this, 'handle_event' ], 10, 2 );
		add_action( 'rest_api_init', [ $this, 'register_route' ], 10, 0 );
	}

	/**
	 * Registers the analytics/event endpoint in the REST API.
	 *
	 * @action rest_api_init
	 *
	 * @return void
	 */
	public function register_route(): void {
		register_rest_route(
			'kadence-starter-library/v1',
			'/handle_event',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'handle_event_endpoint' ),
					'permission_callback' => array( $this, 'verify_user_can_edit' ),
					'args'                => [
						self::PROP_EVENT_LABEL => [
							'description'       => __( 'The Event Label', 'kadence-starter-templates' ),
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
						self::PROP_EVENT_DATA  => [
							'description' => __( 'The Event Data', 'kadence-starter-templates' ),
							'type'        => 'object',
						],
					],
				)
			)
		);
	}

	/**
	 * Checks if the current user has access to edit posts.
	 *
	 * @return bool
	 */
	public function verify_user_can_edit(): bool {
		return current_user_can( 'edit_posts' );
	}
	/**
	 * Get the current license key for the plugin.
	 *
	 * @return string 
	 */
	public function get_current_license_key() {

		if ( function_exists( 'kadence_blocks_get_current_license_data' ) ) {
			$data = kadence_blocks_get_current_license_data();
			if ( ! empty( $data['key'] ) ) {
				return $data['key'];
			}
		} elseif ( class_exists( 'Kadence_Theme_Pro' ) ) {
			$pro_data = array();
			if ( function_exists( '\KadenceWP\KadencePro\StellarWP\Uplink\get_license_key' ) ) {
				$pro_data['ktp_api_key'] = \KadenceWP\KadencePro\StellarWP\Uplink\get_license_key( 'kadence-theme-pro' );
			}
			if ( empty( $pro_data ) ) {
				if ( is_multisite() && ! apply_filters( 'kadence_activation_individual_multisites', false ) ) {
					$pro_data = get_site_option( 'ktp_api_manager' );
				} else {
					$pro_data = get_option( 'ktp_api_manager' );
				}
			}
			if ( ! empty( $pro_data['ktp_api_key'] ) ) {
				return $pro_data['ktp_api_key'];
			}
		} else {
			$key = get_license_key( 'kadence-starter-templates' );
			if ( ! empty( $key ) ) {
				return $key;
			}
		}
		return '';
	}
	/**
	 * Sends events to Prophecy WP (if the user has opted in through AI auth).
	 *
	 * @action kadencestartertemplates/ai/event
	 *
	 * @return void
	 */
	public function handle_event( string $name, array $context ): void {
		// Only pass tracking events if AI has been activated through Opt in.
		$slug = class_exists( '\KadenceWP\KadenceBlocks\App' ) ? 'kadence-blocks' : 'kadence-starter-templates';
		if ( class_exists( '\KadenceWP\KadenceBlocks\App' ) ) {
			$token          = \KadenceWP\KadenceBlocks\StellarWP\Uplink\get_authorization_token( $slug );
			$auth_url       = \KadenceWP\KadenceBlocks\StellarWP\Uplink\build_auth_url( apply_filters( 'kadence-blocks-auth-slug', $slug ), get_license_domain() );
		} else {
			$token          = get_authorization_token( $slug );
			$auth_url       = build_auth_url( apply_filters( 'kadence-blocks-auth-slug', $slug ), get_license_domain() );
		}
		$license_key    = $this->get_current_license_key();
		$is_authorized = false;
		if ( $token && $license_key ) {
			$is_authorized = is_authorized( $license_key, $token, get_license_domain() );
		}
		if ( ! $is_authorized ) {
			return;
		}

		/**
		 * Filters the URL used to send events to.
		 *
		 * @param string The URL to use when sending events.
		 */
		$url = apply_filters( 'kadenceblocks/ai/event_url', self::DOMAIN . self::ENDPOINT );

		wp_remote_post(
			$url,
			array(
				'timeout'  => 20,
				'blocking' => false,
				'headers'  => array(
					'X-Prophecy-Token' => $this->get_prophecy_token_header(),
					'Content-Type'     => 'application/json',
				),
				'body'     => wp_json_encode( [
					'name'    => $name,
					'context' => $context,
				] ),
			)
		);
	}

	/**
	 * Constructs a consistent X-Prophecy-Token header.
	 *
	 * @param array $args An array of arguments to include in the encoded header.
	 *
	 * @return string The base64 encoded string.
	 */
	public function get_prophecy_token_header( $args = [] ) {
		$site_url     = get_original_domain();
		$site_name    = get_bloginfo( 'name' );
		$license_key  = $this->get_current_license_key();
		$defaults = [
			'domain'          => $site_url,
			'key'             => ! empty( $license_key ) ? $license_key : '',
			'site_name'       => $site_name,
			'product_slug'    => 'kadence-starter-templates',
			'product_version' => KADENCE_STARTER_TEMPLATES_VERSION,
		];

		$parsed_args = wp_parse_args( $args, $defaults );

		return base64_encode( json_encode( $parsed_args ) );
	}

	/**
	 * Configures various event requests to the /analytics/event endpoint
	 * and sends them to ProphecyWP.
	 *
	 * @param WP_REST_Request $request The request to the endpoint.
	 */
	public function handle_event_endpoint( WP_REST_Request $request ): WP_REST_Response {
		$event_label = $request->get_param( self::PROP_EVENT_LABEL );
		$event_data  = $request->get_param( self::PROP_EVENT_DATA );

		$event       = '';
		$context     = array();

		switch ( $event_label ) {
			case 'ai_wizard_started':
				$event = 'AI Wizard Started';
				break;

			case 'ai_wizard_update':
				$event = 'AI Wizard Update';
				$context = [
					'organization_type' => $event_data['entityType'] ?? '',
					'location_type'     => $event_data['locationType'] ?? '',
					'location'          => $event_data['location'] ?? '',
					'industry'          => $event_data['industry'] ?? '',
					'mission_statement' => $event_data['missionStatement'] ?? '',
					'keywords'          => $event_data['keywords'] ?? '',
					'tone'              => $event_data['tone'] ?? '',
					'collections'       => $event_data['customCollections'] ?? '',
				];
				break;
			case 'ai_wizard_complete':
				$event = 'AI Wizard Complete';
				$context = [
					'organization_type' => $event_data['entityType'] ?? '',
					'location_type'     => $event_data['locationType'] ?? '',
					'location'          => $event_data['location'] ?? '',
					'industry'          => $event_data['industry'] ?? '',
					'mission_statement' => $event_data['missionStatement'] ?? '',
					'keywords'          => $event_data['keywords'] ?? '',
					'tone'              => $event_data['tone'] ?? '',
					'collections'       => $event_data['customCollections'] ?? '',
				];
				break;
			case 'import_ai_starter_template':
				$event = 'Imported AI Starter Template';
				$context = [
					'starter_slug'       => $event_data['slug'] ?? '',
					'starter_name'       => $event_data['name'] ?? '',
					'starter_is_dark'    => $event_data['is_dark'] ?? false,
					'starter_has_woo'    => $event_data['has_woo'] ?? false,
					'starter_has_posts'  => $event_data['has_posts'] ?? false,
				];
				break;
			case 'collection_updated':
				$event = 'Collection Updated';
				$context = [
					'collection_name' => $this->get_custom_collection_name_by_id( $event_data['customCollections'], $event_data['photoLibrary'] ),
				];
				break;
		}

		if ( strlen( $event ) !== 0 ) {
			do_action( 'kadencestartertemplates/ai/event', $event, $context );

			return new WP_REST_Response( [ 'message' => 'Event handled.' ], 200 );
		}

		return new WP_REST_Response( array( 'message' => 'Event not handled.' ), 500 );
	}

	/**
	 * Searches an array of collections for the name of a collection with a specific ID.
	 *
	 * @param array $collections An array of collections.
	 * @param string $id The ID of a collection.
	 *
	 * @return array
	 */
	private function get_custom_collection_name_by_id( array $collections, string $id ): string {
		foreach ( $collections as $collection ) {
			if ( $collection['value'] === $id ) {
				return $collection['label'] ?? '';
			}

			return '';
		}
	}
}