<?php

namespace WPCOMSpecialProjects\CLI\Helper;

use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Completion\CompletionSuggestions;

trait AutocompleteTrait {

	/**
	 * Complete the input and provide suggestions.
	 *
	 * @param CompletionInput       $input       The completion input.
	 * @param CompletionSuggestions $suggestions The completion suggestions.
	 *
	 * @return void
	 */
	public function complete( CompletionInput $input, CompletionSuggestions $suggestions ): void {
		$args     = $input->getArguments();
		$arg_keys = array_keys( $args );
		foreach ( $arg_keys as $arg ) {
			if ( ! in_array( $arg, array( 'command' ) ) ) {
				$arg = $arg;
				$suggestions->suggestValue( $arg );
			}
		}

		$options  = $input->getOptions();
		$opt_keys = array_keys( $options );
		foreach ( $opt_keys as $opt ) {
			if ( ! in_array( $opt, array( 'ansi', 'contractor', 'help', 'no-interaction', 'version', 'verbose', 'quiet', 'dev' ) ) ) {
				$opt = '--' . $opt;
				$suggestions->suggestValue( $opt );
			}
		}
	}
}
