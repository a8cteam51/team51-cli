<?php

namespace WPCOMSpecialProjects\CLI\MCP;

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * MCP Server implementation for Team51 CLI
 * 
 * Implements the Model Control Protocol to expose CLI commands as tools
 * that AI models can call directly.
 */
class MCPServer {
	
	/**
	 * @var Application The Symfony Console application
	 */
	private Application $application;
	
	/**
	 * @var array<string, array> Registered tools
	 */
	private array $tools = [];
	
	/**
	 * @var resource Input stream (stdin)
	 */
	private $input_stream;
	
	/**
	 * @var resource Output stream (stdout)
	 */
	private $output_stream;
	
	/**
	 * @var resource Error stream (stderr)
	 */
	private $error_stream;
	
	public function __construct() {
		$this->input_stream = STDIN;
		$this->output_stream = STDOUT;
		$this->error_stream = STDERR;
	}
	
	/**
	 * Register commands from a Symfony Console application as MCP tools
	 */
	public function registerCommandsFromApplication( Application $application ): void {
		$this->application = $application;
		
		foreach ( $application->all() as $command ) {
			// Skip built-in commands
			if ( in_array( $command->getName(), [ 'help', 'list', 'completion' ], true ) ) {
				continue;
			}
			
			$this->registerCommand( $command );
		}
	}
	
	/**
	 * Register a single command as an MCP tool
	 */
	private function registerCommand( Command $command ): void {
		$tool_name = 'team51_' . str_replace( ':', '_', $command->getName() );
		
		$tool_definition = [
			'name' => $tool_name,
			'description' => $command->getDescription() ?: 'Team51 CLI command: ' . $command->getName(),
			'inputSchema' => [
				'type' => 'object',
				'properties' => [],
				'required' => []
			]
		];
		
		// Add arguments
		foreach ( $command->getDefinition()->getArguments() as $argument ) {
			$property_name = $argument->getName();
			$property_def = [
				'type' => 'string',
				'description' => $argument->getDescription() ?: "Argument: {$property_name}"
			];
			
			$tool_definition['inputSchema']['properties'][$property_name] = $property_def;
			
			if ( $argument->isRequired() ) {
				$tool_definition['inputSchema']['required'][] = $property_name;
			}
		}
		
		// Add options
		foreach ( $command->getDefinition()->getOptions() as $option ) {
			// Skip global options that are added to all commands
			if ( in_array( $option->getName(), [ 'dev', 'force-update', 'no-autocomplete', 'help', 'quiet', 'verbose', 'version', 'ansi', 'no-ansi', 'no-interaction' ], true ) ) {
				continue;
			}
			
			$property_name = $option->getName();
			$property_def = [
				'description' => $option->getDescription() ?: "Option: {$property_name}"
			];
			
			if ( $option->acceptValue() ) {
				$property_def['type'] = 'string';
				if ( $option->getDefault() !== null ) {
					$property_def['default'] = $option->getDefault();
				}
			} else {
				$property_def['type'] = 'boolean';
				$property_def['default'] = false;
			}
			
			$tool_definition['inputSchema']['properties'][$property_name] = $property_def;
		}
		
		$this->tools[$tool_name] = [
			'definition' => $tool_definition,
			'command' => $command
		];
	}
	
	/**
	 * Run the MCP server
	 */
	public function run(): void {
		while ( true ) {
			$line = fgets( $this->input_stream );
			if ( $line === false ) {
				break;
			}
			
			$line = trim( $line );
			if ( empty( $line ) ) {
				continue;
			}
			
			try {
				$request = json_decode( $line, true, 512, JSON_THROW_ON_ERROR );
				$response = $this->handleRequest( $request );
				
				if ( $response !== null ) {
					fwrite( $this->output_stream, json_encode( $response, JSON_UNESCAPED_SLASHES ) . "\n" );
				}
			} catch ( \Throwable $e ) {
				$error_response = [
					'jsonrpc' => '2.0',
					'id' => $request['id'] ?? null,
					'error' => [
						'code' => -32603,
						'message' => 'Internal error: ' . $e->getMessage()
					]
				];
				fwrite( $this->output_stream, json_encode( $error_response, JSON_UNESCAPED_SLASHES ) . "\n" );
			}
		}
	}
	
	/**
	 * Handle an MCP request
	 */
	private function handleRequest( array $request ): ?array {
		$method = $request['method'] ?? '';
		$id = $request['id'] ?? null;
		
		switch ( $method ) {
			case 'initialize':
				return $this->handleInitialize( $request, $id );
				
			case 'tools/list':
				return $this->handleToolsList( $request, $id );
				
			case 'tools/call':
				return $this->handleToolsCall( $request, $id );
				
			case 'notifications/initialized':
				// No response needed for notifications
				return null;
				
			default:
				return [
					'jsonrpc' => '2.0',
					'id' => $id,
					'error' => [
						'code' => -32601,
						'message' => 'Method not found: ' . $method
					]
				];
		}
	}
	
	/**
	 * Handle initialize request
	 */
	private function handleInitialize( array $request, $id ): array {
		return [
			'jsonrpc' => '2.0',
			'id' => $id,
			'result' => [
				'protocolVersion' => '2024-11-05',
				'capabilities' => [
					'tools' => [
						'listChanged' => false
					]
				],
				'serverInfo' => [
					'name' => 'team51-cli-mcp-server',
					'version' => '1.0.0'
				]
			]
		];
	}
	
	/**
	 * Handle tools/list request
	 */
	private function handleToolsList( array $request, $id ): array {
		$tools = [];
		foreach ( $this->tools as $tool ) {
			$tools[] = $tool['definition'];
		}
		
		return [
			'jsonrpc' => '2.0',
			'id' => $id,
			'result' => [
				'tools' => $tools
			]
		];
	}
	
	/**
	 * Handle tools/call request
	 */
	private function handleToolsCall( array $request, $id ): array {
		$tool_name = $request['params']['name'] ?? '';
		$arguments = $request['params']['arguments'] ?? [];
		
		if ( ! isset( $this->tools[$tool_name] ) ) {
			return [
				'jsonrpc' => '2.0',
				'id' => $id,
				'error' => [
					'code' => -32602,
					'message' => 'Tool not found: ' . $tool_name
				]
			];
		}
		
		try {
			$result = $this->executeTool( $this->tools[$tool_name]['command'], $arguments );
			
			return [
				'jsonrpc' => '2.0',
				'id' => $id,
				'result' => [
					'content' => [
						[
							'type' => 'text',
							'text' => $result
						]
					]
				]
			];
		} catch ( \Throwable $e ) {
			return [
				'jsonrpc' => '2.0',
				'id' => $id,
				'error' => [
					'code' => -32603,
					'message' => 'Tool execution failed: ' . $e->getMessage()
				]
			];
		}
	}
	
	/**
	 * Execute a tool (CLI command)
	 */
	private function executeTool( Command $command, array $arguments ): string {
		try {
			// Convert MCP arguments to Symfony Console input
			$input_array = [ 'command' => $command->getName() ];
			
			// Add arguments
			foreach ( $command->getDefinition()->getArguments() as $argument ) {
				$arg_name = $argument->getName();
				if ( isset( $arguments[$arg_name] ) ) {
					$input_array[$arg_name] = $arguments[$arg_name];
				}
			}
			
			// Add options
			foreach ( $command->getDefinition()->getOptions() as $option ) {
				$opt_name = $option->getName();
				if ( isset( $arguments[$opt_name] ) ) {
					$input_array['--' . $opt_name] = $arguments[$opt_name];
				}
			}
			
			// Force JSON output for MCP compatibility if the command supports it
			if ( $command->getDefinition()->hasOption( 'format' ) ) {
				$input_array['--format'] = 'json';
			}
			
			// Add common options to prevent interactive prompts
			$input_array['--no-interaction'] = true;
			if ( $command->getDefinition()->hasOption( 'no-autocomplete' ) ) {
				$input_array['--no-autocomplete'] = true;
			}
			
			$input = new ArrayInput( $input_array );
			$input->setInteractive( false );
			$output = new BufferedOutput();
			
			// Execute the command
			$exit_code = $command->run( $input, $output );
			$output_content = $output->fetch();
			
			// Try to parse as JSON first, if it fails return as plain text
			$json_output = json_decode( $output_content, true );
			if ( json_last_error() === JSON_ERROR_NONE ) {
				return json_encode( [
					'success' => $exit_code === 0,
					'exit_code' => $exit_code,
					'data' => $json_output
				], JSON_PRETTY_PRINT );
			} else {
				return json_encode( [
					'success' => $exit_code === 0,
					'exit_code' => $exit_code,
					'output' => $output_content
				], JSON_PRETTY_PRINT );
			}
		} catch ( \Throwable $e ) {
			return json_encode( [
				'success' => false,
				'exit_code' => 1,
				'error' => $e->getMessage(),
				'trace' => $e->getTraceAsString()
			], JSON_PRETTY_PRINT );
		}
	}
}
