<?php

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Exception\ExceptionInterface;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

// region HTTP

/**
 * Performs the remote HTTPS request and returns the response.
 *
 * @param   string      $url     The fully-qualified request URL to send the request to.
 * @param   array       $headers The headers to send with the request.
 * @param   string      $method  The HTTP method to use for the request.
 * @param   string|null $content The content to send with the request.
 *
 * @return  array|null
 */
function get_remote_content( string $url, array $headers = array(), string $method = 'GET', ?string $content = null ): ?array {
	$options = array(
		'http' => array(
			'header'        => implode(
				"\r\n",
				array_map(
					static fn( $key, $value ) => "$key: $value",
					array_keys( $headers ),
					array_values( $headers )
				)
			),
			'method'        => $method,
			'content'       => $content,
			'timeout'       => 60,
			'ignore_errors' => true,
		),
	);
	$context = stream_context_create( $options );

	$result = @file_get_contents( $url, false, $context ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	if ( false === $result ) {
		return null;
	}

	return array(
		'headers' => parse_http_headers( $http_response_header ),
		'body'    => '' === $result ? null : decode_json_content( $result ),
	);
}

/**
 * Transforms the raw HTTP response headers into an associative array.
 *
 * @param   array $http_response_header The HTTP response headers.
 *
 * @link    https://www.php.net/manual/en/reserved.variables.httpresponseheader.php#117203
 *
 * @return  array
 */
function parse_http_headers( array $http_response_header ): array {
	$headers = array();

	foreach ( $http_response_header as $header ) {
		$header = explode( ':', $header, 2 );
		if ( 2 === count( $header ) ) {
			$headers[ trim( $header[0] ) ] = trim( $header[1] );
		} else {
			$headers[] = trim( $header[0] );
			if ( preg_match( '#HTTP/[0-9.]+\s+(\d+)#', $header[0], $out ) ) {
				$headers['http_code'] = (int) $out[1];
			}
		}
	}

	return $headers;
}

// endregion

// region WRAPPERS

/**
 * Decodes a JSON object and displays an error on failure.
 *
 * @param   string  $json        The JSON object to decode.
 * @param   boolean $associative Whether to return an associative array or an object. Default object.
 * @param   integer $flags       The JSON decoding flags. Default 0.
 *
 * @return  object|array|null
 */
function decode_json_content( string $json, bool $associative = false, int $flags = 0 ): object|array|null {
	try {
		return json_decode( $json, $associative, 512, $flags | JSON_THROW_ON_ERROR );
	} catch ( JsonException $exception ) {
		console_writeln( "JSON Decoding Exception: {$exception->getMessage()}" );
		console_writeln( 'Original JSON:' . PHP_EOL . $json );
		console_writeln( $exception->getTraceAsString() );
		return null;
	}
}

/**
 * Encodes some given data into a JSON object.
 *
 * @param   mixed   $data  The data to encode.
 * @param   integer $flags The JSON encoding flags. Default 0.
 *
 * @return  string|null
 */
function encode_json_content( mixed $data, int $flags = 0 ): ?string {
	try {
		return json_encode( $data, $flags | JSON_THROW_ON_ERROR );
	} catch ( JsonException $exception ) {
		console_writeln( "JSON Encoding Exception: {$exception->getMessage()}" );
		console_writeln( 'Original data:' . PHP_EOL . print_r( $data, true ) );
		console_writeln( $exception->getTraceAsString() );
		return null;
	}
}

/**
 * Runs a command and returns the exit code.
 *
 * @param   Application     $application   The application instance.
 * @param   string          $command_name  The name of the command to run.
 * @param   array           $command_input The input to pass to the command.
 * @param   OutputInterface $output        The output to use for the command.
 * @param   boolean         $interactive   Whether to run the command in interactive mode.
 *
 * @return integer  The command exit code.
 * @throws ExceptionInterface   If the command does not exist or if the input is invalid.
 */
function run_app_command( Application $application, string $command_name, array $command_input, OutputInterface $output, bool $interactive = false ): int {
	$command_name = explode( '|', $command_name )[0]; // Remove any aliases from the command name.
	$command      = $application->find( $command_name );

	$input = new ArrayInput( $command_input );
	$input->setInteractive( $interactive );

	return $command->run( $input, $output );
}

/**
 * Runs a system command and returns the output.
 *
 * @param   array   $command           The command to run.
 * @param   string  $working_directory The working directory to run the command in.
 * @param   boolean $exit_on_error     Whether to exit if the command returns an error or not.
 *
 * @link    https://symfony.com/doc/current/components/process.html
 *
 * @return  Process
 */
function run_system_command( array $command, string $working_directory = '.', bool $exit_on_error = true ): Process {
	$process = new Process( $command, $working_directory );

	try {
		$process->mustRun();
	} catch ( ProcessFailedException $exception ) {
		console_writeln( "Process Failed Exception: {$exception->getMessage()}" );
		console_writeln( 'Original command:' . \PHP_EOL . \print_r( $command, true ) );
		console_writeln( $exception->getTraceAsString() );

		if ( $exit_on_error ) {
			exit( $exception->getCode() );
		}
	}

	return $process;
}

// endregion

// region CONSOLE

/**
 * Displays a message to the console if the console verbosity level is at least as high as the message's level.
 *
 * @param   string  $message   The message to display.
 * @param   integer $verbosity The verbosity level of the message.
 *
 * @return  void
 */
function console_writeln( string $message, int $verbosity = 0 ): void {
	global $team51_cli_output;

	if ( $verbosity <= $team51_cli_output->getVerbosity() ) {
		$team51_cli_output->writeln( $message );
	}
}

/**
 * Grabs a value from the console input. Allows for a null value if no input is given.
 *
 * @param   InputInterface  $input         The input instance.
 * @param   OutputInterface $output        The output instance.
 * @param   string          $name          The name of the value to grab.
 * @param   callable|null   $no_input_func The function to call if no input is given.
 *
 * @return  string|null
 */
function maybe_get_string_input( InputInterface $input, OutputInterface $output, string $name, ?callable $no_input_func = null ): ?string {
	$string = $input->hasOption( $name ) ? $input->getOption( $name ) : $input->getArgument( $name );

	// If we don't have a value, prompt for one.
	if ( empty( $string ) && is_callable( $no_input_func ) ) {
		$string = $no_input_func( $input, $output );
	}

	return empty( $string ) ? null : $string;
}

/**
 * Grabs a value from the console input. Exits if no input is given.
 *
 * @param   InputInterface  $input         The input instance.
 * @param   OutputInterface $output        The output instance.
 * @param   string          $name          The name of the value to grab.
 * @param   callable|null   $no_input_func The function to call if no input is given.
 *
 * @return  string
 */
function get_string_input( InputInterface $input, OutputInterface $output, string $name, ?callable $no_input_func = null ): string {
	$string = maybe_get_string_input( $input, $output, $name, $no_input_func );

	if ( empty( $string ) ) {
		$output->writeln( "<error>No value was provided for the '$name' input. Aborting!</error>" );
		exit( 1 );
	}

	return $string;
}

/**
 * Grabs a value from the console input and validates it against a list of allowed values.
 *
 * @param   InputInterface  $input         The input instance.
 * @param   OutputInterface $output        The output instance.
 * @param   string          $name          The name of the value to grab.
 * @param   string[]        $valid_values  The valid values for the option.
 * @param   callable|null   $no_input_func The function to call if no input is given.
 * @param   string|null     $default_value The default value for the option.
 *
 * @return  string|null
 */
function get_enum_input( InputInterface $input, OutputInterface $output, string $name, array $valid_values, ?callable $no_input_func = null, ?string $default_value = null ): ?string {
	$option = maybe_get_string_input( $input, $output, $name, $no_input_func );

	if ( $option !== $default_value ) {
		foreach ( (array) $option as $value ) {
			if ( ! in_array( $value, $valid_values, false ) ) { // phpcs:ignore WordPress.PHP.StrictInArray.FoundNonStrictFalse
				$output->writeln( "<error>Invalid value for input '$name': $value</error>" );
				exit( 1 );
			}
		}
	}

	return $option;
}

/**
 * Grabs a value from the console input and validates it as an email.
 *
 * @param   InputInterface  $input         The console input.
 * @param   OutputInterface $output        The console output.
 * @param   callable|null   $no_input_func The function to call if no input is given.
 * @param   string          $name          The name of the value to grab.
 *
 * @return  string
 */
function get_email_input( InputInterface $input, OutputInterface $output, ?callable $no_input_func = null, string $name = 'email' ): string {
	$email = get_string_input( $input, $output, $name, $no_input_func );

	if ( false === filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
		$output->writeln( '<error>The provided email is invalid. Aborting!</error>' );
		exit( 1 );
	}

	return $email;
}

/**
 * Grabs a value from the console input and validates it as a URL or a numeric string.
 *
 * @param   InputInterface  $input         The console input.
 * @param   OutputInterface $output        The console output.
 * @param   callable|null   $no_input_func The function to call if no input is given.
 * @param   string          $name          The name of the value to grab.
 *
 * @return  string
 */
function get_site_input( InputInterface $input, OutputInterface $output, ?callable $no_input_func = null, string $name = 'site' ): string {
	$site_id_or_url = get_string_input( $input, $output, $name, $no_input_func );

	if ( str_contains( $site_id_or_url, 'http' ) ) {
		$site_id_or_url = parse_url( $site_id_or_url, PHP_URL_HOST );
		if ( false === $site_id_or_url ) {
			$output->writeln( '<error>Invalid URL provided. Aborting!</error>' );
			exit( 1 );
		}
	}

	return $site_id_or_url;
}

/**
 * Grabs a value from the console input and validates it as a numeric string or an email.
 *
 * @param   InputInterface  $input         The console input.
 * @param   OutputInterface $output        The console output.
 * @param   callable|null   $no_input_func The function to call if no input is given.
 * @param   string          $name          The name of the value to grab.
 *
 * @return  string
 */
function get_user_input( InputInterface $input, OutputInterface $output, ?callable $no_input_func = null, string $name = 'user' ): string {
	$user = get_string_input( $input, $output, $name, $no_input_func );

	if ( ! is_numeric( $user ) && false === filter_var( $user, FILTER_VALIDATE_EMAIL ) ) {
		$output->writeln( '<error>The provided user is invalid. Aborting!</error>' );
		exit( 1 );
	}

	return $user;
}

/**
 * Validates a given user's choice against a list of choices, and returns the key of the valid choice.
 * Tries to handle the case where the user input was either the key or the value, and always returns the key.
 *
 * @param   mixed $value   The user's input. Expected to be the key or the value of a choice.
 * @param   array $choices The list of valid choices in key => value format.
 *
 * @return  mixed|null
 */
function validate_user_choice( mixed $value, array $choices ): mixed {
	if ( isset( $choices[ $value ] ) ) { // Handle the case where the user's input was the key.
		return $value;
	}

	return array_flip( $choices )[ $value ] ?? null;
}

/**
 * Outputs a table to the console. Useful to standardize the output throughout the application.
 *
 * @param   OutputInterface $output       The console output.
 * @param   array           $rows         The rows to output.
 * @param   array           $headers      The headers to output.
 * @param   string|null     $header_title The title to use for the header.
 *
 * @return  void
 */
function output_table( OutputInterface $output, array $rows, array $headers, ?string $header_title = null ): void {
	$table = new Table( $output );
	$table->setStyle( 'box-double' );

	$table->setHeaderTitle( $header_title );
	$table->setHeaders( $headers );

	$table->setRows( $rows );

	$output->writeln( '' ); // Empty line for UX purposes.
	$table->render();
}

// endregion

// region POLYFILLS

/**
 * Returns whether two given strings are equal or not in a case-insensitive manner.
 *
 * @param   string $string_1 The first string.
 * @param   string $string_2 The second string.
 *
 * @return  boolean
 */
function is_case_insensitive_match( string $string_1, string $string_2 ): bool {
	return 0 === strcasecmp( $string_1, $string_2 );
}

/**
 * Returns a slugified version of a given string. Partially inspired by WordPress's `sanitize_key` function.
 *
 * @param   string $value The string to slugify.
 *
 * @return  string
 */
function slugify( string $value ): string {
	$value = strtolower( $value ); // Lowercase the string.
	return dashify( $value );
}

/**
 * Returns a dashified version of a given string.
 *
 * @param   string $value The string to dashify.
 *
 * @return  string
 */
function dashify( string $value ): string {
	$value = preg_replace( '/[^A-Za-z0-9\-]+/', '-', $value ); // Replace non-alphanumeric characters with hyphens.
	$value = preg_replace( '/-+/', '-', $value ); // Replace multiple contiguous hyphens with a single hyphen.

	return trim( $value, '-' ); // Trim any leading or trailing hyphens.
}

// endregion
