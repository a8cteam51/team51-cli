<?php

namespace WPCOMSpecialProjects\CLI\Helper;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Trait to add JSON output support to CLI commands for MCP compatibility
 */
trait JsonOutputTrait {
	
	/**
	 * Add the JSON format option to the command
	 */
	protected function addJsonFormatOption(): void {
		if ( ! $this->getDefinition()->hasOption( 'format' ) ) {
			$this->addOption( 'format', null, InputOption::VALUE_REQUIRED, 'The format to output the results in. Accepts `table`, `list`, `json`, or `raw`.', 'table' );
		}
	}
	
	/**
	 * Check if JSON output is requested
	 */
	protected function isJsonOutput( InputInterface $input ): bool {
		return $input->getOption( 'format' ) === 'json';
	}
	
	/**
	 * Output data as JSON
	 */
	protected function outputJson( OutputInterface $output, array $data, bool $success = true, int $exit_code = 0 ): void {
		$json_output = [
			'success' => $success,
			'exit_code' => $exit_code,
			'data' => $data
		];
		
		$output->writeln( json_encode( $json_output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
	}
	
	/**
	 * Output error as JSON
	 */
	protected function outputJsonError( OutputInterface $output, string $message, int $exit_code = 1 ): void {
		$json_output = [
			'success' => false,
			'exit_code' => $exit_code,
			'error' => $message
		];
		
		$output->writeln( json_encode( $json_output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
	}
}
