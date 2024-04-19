<?php

namespace WPCOMSpecialProjects\CLI\Helper;

use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Completion\CompletionSuggestions;

/**
 * Generic implementation for Symfony Console autocompletion.
 */
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
		$input_arguments = \array_keys( $input->getArguments() );
		foreach ( $input_arguments as $argument ) {
			if ( 'command' !== $argument ) { // Special case for the actual command name, i.e. the first argument.
				$suggestions->suggestValue( $argument );
			}
		}

		$input_options = \array_keys( $input->getOptions() );
		foreach ( $input_options as $option ) {
			if ( ! \in_array( $option, array( 'ansi', 'help', 'no-interaction', 'version', 'verbose', 'quiet', 'dev' ), true ) ) {
				$suggestions->suggestValue( '--' . $option );
			}
		}
	}
}
