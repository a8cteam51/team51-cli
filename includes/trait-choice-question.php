<?php
/**
 * UI Choice Question Trait
 *
 * @package WPCOMSpecialProjects\CLI\Helper
 */

declare(strict_types=1);

namespace WPCOMSpecialProjects\CLI\Helper;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;

#[\Attribute]
trait Choice_Question {

	// region FIELDS AND CONSTANTS

	/**
	 * Error message to display when the user provides an invalid input.
	 *
	 * @var string
	 */
	public string $choice_question_error_message = 'Invalid input: %s';

	/**
	 * User provided answer value.
	 *
	 * @var string|null
	 */
	public ?string $choice_question_chosen_value = null;

	/**
	 * User provided answer key.
	 *
	 * @var string|null
	 */
	public ?string $choice_question_chosen_key = null;

	// endregion

	// region GETTERS

	/**
	 * Prompt a choice question.
	 *
	 * @param InputInterface  $input The input interface.
	 * @param OutputInterface $output The output interface.
	 * @param string          $question_text The question text.
	 * @param array           $choices The choices.
	 * @param string|null     $default_key The default key.
	 *
	 * @throws \InvalidArgumentException|\LogicException If input is invalid or getHelper is not callable.
	 *
	 * @return self
	 */
	public function choice_question_prompt( InputInterface $input, OutputInterface $output, string $question_text, array $choices, ?string $default_key = null ): self {
		$choice_keys    = array_keys( $choices );
		$choices_values = array_values( $choices );

		// Normalize the default key to the index of the choice.
		$normalized_default_key = array_search(
			$default_key ?? array_key_first( $choice_keys ),
			$choice_keys,
			true
		);

		$question = new ChoiceQuestion(
			$question_text,
			$choices_values,
			$normalized_default_key
		);

		// Enhanced validator that throws exception on invalid input
		$question->setValidator(
			function ( $user_input ) use ( $choices_values ) {
				$validated = validate_user_choice( $user_input, $choices_values );
				if ( null === $validated ) {
						throw new \InvalidArgumentException( sprintf( $this->choice_question_error_message, $user_input ) );
				}
				return $validated;
			}
		);

		if ( ! method_exists( $this, 'getHelper' ) ) {
			throw new \LogicException( '$this->getHelper not callable, ensure this trait is used in a class that extends \Symfony\Component\Console\Command\Command' );
		}

		$answer = $choices_values[ $this->getHelper( 'question' )->ask( $input, $output, $question ) ];

		$this->choice_question_chosen_key   = array_search( $answer, $choices, true );
		$this->choice_question_chosen_value = $choices[ $this->choice_question_chosen_key ];

		return $this;
	}

	/**
	 * Get the chosen key and value.
	 *
	 * @return array{0: string|null, 1: string|null}
	 */
	public function choice_question_get_answer(): array {
		return array( $this->choice_question_chosen_key, $this->choice_question_chosen_value );
	}

	/**
	 * Get the chosen key.
	 *
	 * @return string|null
	 */
	public function choice_question_get_answer_key(): ?string {
		return $this->choice_question_chosen_key;
	}

	/**
	 * Get the chosen value.
	 *
	 * @return string
	 */
	public function choice_question_get_answer_value(): string {
		return $this->choice_question_chosen_value ?? '';
	}

	// endregion
}
