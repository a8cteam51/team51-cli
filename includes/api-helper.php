<?php

use Symfony\Component\Console\Output\OutputInterface;

/**
 * Handles making and parsing the API calls to the central REST API server.
 */
final class API_Helper {
	// region FIELDS AND CONSTANTS

	/**
	 * The base URL for the OpsOasis REST API.
	 */
	private const BASE_URL = 'https://opsoasis.wpspecialprojects.com/wp-json/wpcomsp/';

	// endregion

	// region METHODS

	/**
	 * Calls a given DeployHQ endpoint and returns the response.
	 *
	 * @param   string $endpoint The endpoint to call.
	 * @param   string $method   The HTTP method to use. One of 'GET', 'POST', 'PUT', 'DELETE'.
	 * @param   mixed  $body     The body to send with the request.
	 *
	 * @return  stdClass|stdClass[]|true|null
	 */
	public static function make_deployhq_request( string $endpoint, string $method = 'GET', mixed $body = null ): stdClass|array|true|null {
		return self::make_request( self::BASE_URL . "deployhq/v1/$endpoint", $method, $body );
	}

	/**
	 * Calls a given GitHub endpoint and returns the response.
	 *
	 * @param   string $endpoint The endpoint to call.
	 * @param   string $method   The HTTP method to use. One of 'GET', 'POST', 'PUT', 'DELETE'.
	 * @param   mixed  $body     The body to send with the request.
	 *
	 * @return  stdClass|stdClass[]|true|null
	 */
	public static function make_github_request( string $endpoint, string $method = 'GET', mixed $body = null ): stdClass|array|true|null {
		return self::make_request( self::BASE_URL . "github/v1/$endpoint", $method, $body );
	}

	/**
	 * Calls a given Jetpack endpoint and returns the response.
	 *
	 * @param   string $endpoint The endpoint to call.
	 * @param   string $method   The HTTP method to use. One of 'GET', 'POST', 'PUT', 'DELETE'.
	 * @param   mixed  $body     The body to send with the request.
	 *
	 * @return  stdClass|stdClass[]|true|null
	 */
	public static function make_jetpack_request( string $endpoint, string $method = 'GET', mixed $body = null ): stdClass|array|true|null {
		return self::make_request( self::BASE_URL . "jetpack/v1/$endpoint", $method, $body );
	}

	/**
	 * Calls a given Pressable endpoint and returns the response.
	 *
	 * @param   string $endpoint The endpoint to call.
	 * @param   string $method   The HTTP method to use. One of 'GET', 'POST', 'PUT', 'DELETE'.
	 * @param   mixed  $body     The body to send with the request.
	 *
	 * @return  stdClass|stdClass[]|true|null
	 */
	public static function make_pressable_request( string $endpoint, string $method = 'GET', mixed $body = null ): stdClass|array|true|null {
		return self::make_request( self::BASE_URL . "pressable/v1/$endpoint", $method, $body );
	}

	/**
	 * Calls a given WPCOM endpoint and returns the response.
	 *
	 * @param   string $endpoint The endpoint to call.
	 * @param   string $method   The HTTP method to use. One of 'GET', 'POST', 'PUT', 'DELETE'.
	 * @param   mixed  $body     The body to send with the request.
	 *
	 * @return  stdClass|stdClass[]|true|null
	 */
	public static function make_wpcom_request( string $endpoint, string $method = 'GET', mixed $body = null ): stdClass|array|true|null {
		return self::make_request( self::BASE_URL . "wpcom/v1/$endpoint", $method, $body );
	}

	// endregion

	// region HELPERS

	/**
	 * Calls the given endpoint and returns the response.
	 *
	 * @param   string $endpoint The endpoint to call.
	 * @param   string $method   The HTTP method to use. One of 'GET', 'POST', 'PUT', 'DELETE'.
	 * @param   mixed  $body     The body to send with the request.
	 *
	 * @return  stdClass|stdClass[]|true|null
	 */
	protected static function make_request( string $endpoint, string $method, mixed $body = null ): stdClass|array|true|null {
		$body   = is_null( $body ) ? null : encode_json_content( $body );
		$result = get_remote_content(
			$endpoint,
			array(
				'Accept'        => 'application/json',
				'Content-type'  => 'application/json',
				'Authorization' => 'Basic ' . base64_encode( OPSOASIS_WP_USERNAME . ':' . OPSOASIS_APP_PASSWORD ),
			),
			$method,
			$body
		);

		if ( ! str_starts_with( (string) $result['headers']['http_code'], '2' ) ) {
			console_writeln( "❌ API error ({$result['headers']['http_code']} $endpoint): " . encode_json_content( $result['body'] ) );
			return null;
		}
		if ( is_object( $result['body'] ) && property_exists( $result['body'], 'code' ) ) {
			console_writeln( "❌ API error ({$result['body']->code} $endpoint): {$result['body']->message}" );
			return null;
		}

		// Handles HTTP status codes like 201, 202, and especially 204.
		return is_null( $result['body'] ) ? true : $result['body'];
	}

	// endregion
}
