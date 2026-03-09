<?php

use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

// region API

/**
 * Fetches the list of WordPress.org themes.
 *
 * @return  string[]
 */
function get_wporg_theme_choices(): array {

	$endpoint = 'themes'; // Equivalent to 'sites?type=all'.

	$response = API_Helper::make_wporg_request( $endpoint );
	if ( is_null( $response ) ) {
		return array();
	}

	if ( ! is_object( $response ) || ! isset( $response->records ) || ! is_iterable( $response->records ) ) {
		return array();
	}

	$valid_themes = array_filter( $response->records, fn( $theme ) => isset( $theme->slug ) );
	if ( empty( $valid_themes ) ) {
		return array();
	}

	return array_combine(
		array_map( fn( $theme ) => $theme->slug, $valid_themes ),
		$valid_themes
	);
}

// endregion

// region HELPERS

/**
 * Prompts the user to select a WordPress.org theme with autocomplete, retry, and "did you mean?" suggestions.
 *
 * @param   InputInterface  $input           The input object.
 * @param   OutputInterface $output          The output object.
 * @param   QuestionHelper  $question_helper The question helper instance.
 * @param   array           $themes          The list of available themes (objects with ->slug and ->name).
 * @param   int             $max_attempts    Maximum number of attempts before giving up.
 *
 * @return  string|null The selected theme slug, or null if cancelled.
 */
function prompt_wporg_theme_input( InputInterface $input, OutputInterface $output, QuestionHelper $question_helper, array $themes, int $max_attempts = 5 ): ?string {
	$theme_slugs  = array_map( fn( $theme ) => $theme->slug, $themes );
	$theme_names  = array_map( fn( $theme ) => $theme->name, $themes );
	$name_to_slug = array_combine( $theme_names, $theme_slugs );

	$autocompleter_values = array_merge( $theme_slugs, $theme_names );

	for ( $attempt = 0; $attempt < $max_attempts; $attempt++ ) {
		$output->writeln( '<info>Type a theme slug or name. Use "wpcom-theme" for internal/unlisted themes.</info>' );
		$question = new Question( '<question>Theme:</question> ' );
		$question->setAutocompleterValues( $autocompleter_values );

		$selected = $question_helper->ask( $input, $output, $question );

		if ( null === $selected || '' === \trim( (string) $selected ) ) {
			return null;
		}

		if ( \in_array( $selected, $theme_slugs, true ) ) {
			return $selected;
		}

		if ( isset( $name_to_slug[ $selected ] ) ) {
			return $name_to_slug[ $selected ];
		}

		$output->writeln( '<error>' . $selected . ' is not a valid theme slug or name.</error>' );

		$suggestions = find_similar_wporg_themes( $selected, $autocompleter_values );

		if ( ! empty( $suggestions ) ) {
			$output->writeln( '<comment>Did you mean one of these?</comment>' );
			foreach ( $suggestions as $suggestion ) {
				$output->writeln( '  • ' . $suggestion );
			}
		} else {
			$output->writeln( '<info>Available themes:</info>' );
			foreach ( $themes as $theme ) {
				$output->writeln( '  ' . $theme->slug . ' — ' . $theme->name );
			}
		}

		$output->writeln( '' );
	}

	$output->writeln( '<error>Too many invalid attempts.</error>' );
	return null;
}

/**
 * Finds theme slugs/names similar to the given input using Levenshtein distance and substring matching.
 *
 * @param   string   $needle     The user's input.
 * @param   string[] $candidates All valid slugs and names.
 * @param   int      $max        Maximum number of suggestions to return.
 *
 * @return  string[]
 */
function find_similar_wporg_themes( string $needle, array $candidates, int $max = 5 ): array {
	$needle_lower = \strtolower( $needle );
	$scored       = array();

	foreach ( $candidates as $candidate ) {
		$candidate_lower = \strtolower( $candidate );

		if ( \str_contains( $candidate_lower, $needle_lower ) || \str_contains( $needle_lower, $candidate_lower ) ) {
			$scored[ $candidate ] = 0;
			continue;
		}

		$distance  = \levenshtein( $needle_lower, $candidate_lower );
		$threshold = (int) \max( 3, \strlen( $needle ) * 0.5 );
		if ( $distance <= $threshold ) {
			$scored[ $candidate ] = $distance;
		}
	}

	\asort( $scored );
	return \array_slice( \array_keys( $scored ), 0, $max );
}

// endregion
