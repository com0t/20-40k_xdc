<?php
/**
 * Plugin bot API endpoint for WordPress REST API.
 *
 * @link       https://manzoorwani.dev
 * @since      1.2.2
 *
 * @package    WPTelegram\BotAPI
 * @subpackage WPTelegram\BotAPI\restApi
 */

namespace WPTelegram\BotAPI\restApi;

use WP_REST_Request;
use WP_REST_Response;
use WPTelegram\BotAPI\API;

/**
 * Class to handle the bot API endpoint.
 *
 * @since 1.2.2
 *
 * @package    WPTelegram\BotAPI
 * @subpackage WPTelegram\BotAPI\restApi
 * @author     Manzoor Wani <@manzoorwanijk>
 */
class RESTAPIController extends RESTBaseController {

	/**
	 * The base of this controller's route.
	 *
	 * @var string
	 */
	const REST_BASE = '/(?P<method>[a-zA-Z]+)';

	/**
	 * Register the routes.
	 *
	 * @since 1.2.2
	 */
	public function register_routes() {

		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_BASE,
			[
				[
					'methods'             => 'GET, POST',
					'callback'            => [ $this, 'handle_request' ],
					'permission_callback' => [ $this, 'permissions_for_request' ],
					'args'                => self::get_test_params(),
				],
			]
		);
	}

	/**
	 * Check request permissions.
	 *
	 * @since 1.2.2
	 *
	 * @param WP_REST_Request $request WP REST API request.
	 *
	 * @return bool
	 */
	public function permissions_for_request( $request ) {
		$permission = current_user_can( 'manage_options' );

		return apply_filters( 'wptelegram_bot_api_rest_permission', $permission, $request );
	}

	/**
	 * Handle the request.
	 *
	 * @since 1.2.2
	 *
	 * @param WP_REST_Request $request WP REST API request.
	 */
	public function handle_request( WP_REST_Request $request ) {

		$bot_token  = $request->get_param( 'bot_token' );
		$api_method = $request->get_param( 'method' );
		$api_params = $request->get_param( 'api_params' );

		$body = [];
		$code = 200;

		$bot_api = new API( $bot_token );

		if ( empty( $api_params ) ) {
			$api_params = [];
		}

		$res = call_user_func( [ $bot_api, $api_method ], $api_params );

		if ( is_wp_error( $res ) ) {

			$body = [
				'ok'          => false,
				'error_code'  => 500,
				'description' => $res->get_error_code() . ' - ' . $res->get_error_message(),
			];
			$code = $body['error_code'];

		} else {

			$body = $res->get_decoded_body();
			// When using proxy, error_code may be in body.
			$code = ! empty( $body['error_code'] ) ? $body['error_code'] : $res->get_response_code();
		}

		return new WP_REST_Response( $body, $code );
	}

	/**
	 * Retrieves the query params for the settings.
	 *
	 * @since 1.2.2
	 *
	 * @return array Query parameters for the settings.
	 */
	public static function get_test_params() {
		return [
			'bot_token'  => [
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => [ __CLASS__, 'validate_param' ],
			],
			'api_params' => [
				'type'              => 'object',
				'sanitize_callback' => [ __CLASS__, 'sanitize_params' ],
				'validate_callback' => 'rest_validate_request_arg',
			],
		];
	}

	/**
	 * Validate params.
	 *
	 * @since 1.2.2
	 *
	 * @param mixed           $value   Value of the param.
	 * @param WP_REST_Request $request WP REST API request.
	 * @param string          $key     Param key.
	 */
	public static function validate_param( $value, WP_REST_Request $request, $key ) {
		switch ( $key ) {
			case 'bot_token':
				$pattern = API::BOT_TOKEN_REGEX;
				break;
		}

		return (bool) preg_match( $pattern, $value );
	}

	/**
	 * Sanitize params.
	 *
	 * @since 1.2.2
	 *
	 * @param mixed           $value   Value of the param.
	 * @param WP_REST_Request $request WP REST API request.
	 * @param string          $key     Param key.
	 */
	public static function sanitize_params( $value, WP_REST_Request $request, $key ) {
		$safe_value = self::sanitize_input( $value );

		return apply_filters( 'wptelegram_bot_api_rest_sanitize_params', $safe_value, $value, $request, $key );
	}

	/**
	 * Sanitize params.
	 *
	 * @since 1.2.4
	 *
	 * @param mixed $input Value of the param.
	 */
	public static function sanitize_input( $input ) {
		$raw_input = $input;
		if ( is_array( $input ) ) {
			foreach ( $input as $key => $value ) {
				$input[ sanitize_text_field( $key ) ] = self::sanitize_input( $value );
			}
		} else {
			$input = sanitize_text_field( $input );
		}

		return apply_filters( 'wptelegram_bot_api_rest_sanitize_input', $input, $raw_input );
	}
}
