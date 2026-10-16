<?php

namespace WPCOMSpecialProjects\CLI\Helper;

use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\StreamableInputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Terminal;

/**
 * Asks questions on a clean slate: keys typed before a question was asked are discarded rather than read as its answer.
 *
 * While a command works between questions, whatever is typed waits in the terminal and becomes the answer to the next
 * question, so a stray `y` and Enter confirms something nobody has read yet. Registered once on the application in
 * `team51-cli.php`, so every question in every command goes through it. Answers typed ahead on purpose are discarded
 * too; scripts pass flags or pipe their answers, and piped input is left alone.
 */
final class TerminalQuestionHelper extends QuestionHelper {
	/**
	 * {@inheritDoc}
	 */
	public function ask( InputInterface $input, OutputInterface $output, Question $question ): mixed {
		if ( $input->isInteractive() ) {
			self::discard_pending_input( ( $input instanceof StreamableInputInterface ? $input->getStream() : null ) ?? \STDIN );
		}

		return parent::ask( $input, $output, $question );
	}

	/**
	 * Reads and throws away whatever is waiting to be read from a terminal.
	 *
	 * Only a terminal is drained: piped input is meant to be read, and the MCP server's stdio carries JSON-RPC. The
	 * terminal leaves line mode for the drain, the way Symfony's autocompletion does, because text typed without
	 * Enter otherwise stays in its line buffer and is glued onto the real answer: `y`, then `n` and Enter, reads as
	 * `yn`, which a confirmation takes as yes.
	 *
	 * A read is only made when it is known to return something. A read that returns nothing from a terminal is taken
	 * by PHP as the end of the input, and the question would then be answered with its default without being asked.
	 *
	 * @param   resource $stream The stream the answer will be read from.
	 *
	 * @return  void
	 */
	private static function discard_pending_input( $stream ): void {
		if ( ! \stream_isatty( $stream ) || ! Terminal::hasSttyAvailable() ) {
			return;
		}

		$stty_mode = \shell_exec( 'stty -g' );
		\shell_exec( 'stty -icanon min 1 time 0' );
		try {
			while ( true ) {
				// Bytes PHP already read from the terminal wait in the stream's own buffer, out of sight of select.
				$unread_bytes = \stream_get_meta_data( $stream )['unread_bytes'];
				if ( 0 < $unread_bytes ) {
					\fread( $stream, $unread_bytes );
					continue;
				}

				$read   = array( $stream );
				$write  = null;
				$except = null;
				if ( 1 !== \stream_select( $read, $write, $except, 0 ) ) {
					break;
				}

				\fread( $stream, 1 );
			}
		} finally {
			\shell_exec( 'stty ' . $stty_mode );
		}
	}
}
