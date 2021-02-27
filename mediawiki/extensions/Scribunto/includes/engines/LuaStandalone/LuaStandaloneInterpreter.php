<?php

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class Scribunto_LuaStandaloneInterpreter extends Scribunto_LuaInterpreter {
	protected static $nextInterpreterId = 0;

	/**
	 * @var Scribunto_LuaStandaloneEngine
	 */
	public $engine;

	/**
	 * @var bool
	 */
	public $enableDebug;

	/**
	 * @var resource|bool
	 */
	public $proc;

	/**
	 * @var resource
	 */
	public $writePipe;

	/**
	 * @var resource
	 */
	public $readPipe;

	/**
	 * @var ScribuntoException
	 */
	public $exitError;

	/**
	 * @var int
	 */
	public $id;

	/**
	 * @var LoggerInterface
	 */
	protected $logger;

	/**
	 * @var callable[]
	 */
	protected $callbacks;

	/**
	 * @param Scribunto_LuaStandaloneEngine $engine
	 * @param array $options
	 * @throws MWException
	 * @throws Scribunto_LuaInterpreterNotFoundError
	 * @throws ScribuntoException
	 */
	public function __construct( $engine, array $options ) {
		$this->id = self::$nextInterpreterId++;

		if ( $options['errorFile'] === null ) {
			$options['errorFile'] = wfGetNull();
		}

		if ( $options['luaPath'] === null ) {
			$path = false;

			// Note, if you alter these, also alter getLuaVersion() below
			if ( PHP_OS == 'Linux' ) {
				if ( PHP_INT_SIZE == 4 ) {
					$path = 'lua5_1_5_linux_32_generic/lua';
				} elseif ( PHP_INT_SIZE == 8 ) {
					$path = 'lua5_1_5_linux_64_generic/lua';
				}
			} elseif ( PHP_OS == 'Windows' || PHP_OS == 'WINNT' || PHP_OS == 'Win32' ) {
				if ( PHP_INT_SIZE == 4 ) {
					$path = 'lua5_1_5_Win32_bin/lua5.1.exe';
				} elseif ( PHP_INT_SIZE == 8 ) {
					$path = 'lua5_1_5_Win64_bin/lua5.1.exe';
				}
			} elseif ( PHP_OS == 'Darwin' ) {
				$path = 'lua5_1_5_mac_lion_fat_generic/lua';
			}
			if ( $path === false ) {
				throw new Scribunto_LuaInterpreterNotFoundError(
					'No Lua interpreter was given in the configuration, ' .
					'and no bundled binary exists for this platform.' );
			}
			$options['luaPath'] = __DIR__ . "/binaries/$path";

			if ( !is_executable( $options['luaPath'] ) ) {
				throw new MWException(
					sprintf( 'The lua binary (%s) is not executable.', $options['luaPath'] )
				);
			}
		}

		$this->engine = $engine;
		$this->enableDebug = !empty( $options['debug'] );
		$this->logger = $options['logger'] ?? new NullLogger();

		$pipes = null;
		$cmd = wfEscapeShellArg(
			$options['luaPath'],
			__DIR__ . '/mw_main.lua',
			dirname( dirname( __DIR__ ) ),
			(string)$this->id,
			(string)PHP_INT_SIZE
		);
		if ( php_uname( 's' ) == 'Linux' ) {
			// Limit memory and CPU
			$cmd = wfEscapeShellArg(
				'exec', # proc_open() passes $cmd to 'sh -c' on Linux, so add an 'exec' to bypass it
				'/bin/sh',
				__DIR__ . '/lua_ulimit.sh',
				(string)$options['cpuLimit'], # soft limit (SIGXCPU)
				(string)( $options['cpuLimit'] + 1 ), # hard limit
				(string)intval( $options['memoryLimit'] / 1024 ),
				$cmd );
		}

		if ( php_uname( 's' ) == 'Windows NT' ) {
			// Like the passthru() in older versions of PHP,
			// PHP's invokation of cmd.exe in proc_open() is broken:
			// http://news.php.net/php.internals/21796
			// Unlike passthru(), it is not fixed in any PHP version,
			// so we use the fix similar to one in wfShellExec()
			$cmd = '"' . $cmd . '"';
		}

		$this->logger->debug( __METHOD__ . ": creating interpreter: $cmd" );

		// Check whether proc_open is available before trying to call it (e.g.
		// PHP's disable_functions may have removed it)
		if ( !function_exists( 'proc_open' ) ) {
			throw $this->engine->newException( 'scribunto-luastandalone-proc-error-proc-open' );
		}

		// Clear the "last error", so if proc_open fails we can know any
		// warning was generated by that.
		error_clear_last();

		$this->proc = proc_open(
			$cmd,
			[
				[ 'pipe', 'r' ],
				[ 'pipe', 'w' ],
				[ 'file', $options['errorFile'], 'a' ]
			],
			$pipes );
		if ( !$this->proc ) {
			$err = error_get_last();
			if ( !empty( $err['message'] ) ) {
				throw $this->engine->newException( 'scribunto-luastandalone-proc-error-msg',
					[ 'args' => [ $err['message'] ] ] );
			} else {
				throw $this->engine->newException( 'scribunto-luastandalone-proc-error' );
			}
		}
		$this->writePipe = $pipes[0];
		$this->readPipe = $pipes[1];
	}

	public function __destruct() {
		$this->terminate();
	}

	/**
	 * Fetch the Lua version
	 * @param array $options Engine options
	 * @return string|null
	 */
	public static function getLuaVersion( array $options ) {
		if ( $options['luaPath'] === null ) {
			// We know which versions are distributed, no need to run them.
			if ( PHP_OS == 'Linux' ) {
				return 'Lua 5.1.5';
			} elseif ( PHP_OS == 'Windows' || PHP_OS == 'WINNT' || PHP_OS == 'Win32' ) {
				return 'Lua 5.1.4';
			} elseif ( PHP_OS == 'Darwin' ) {
				return 'Lua 5.1.5';
			} else {
				return null;
			}
		}

		// Ask the interpreter what version it is, using the "-v" option.
		// The output is expected to be one line, something like these:
		// Lua 5.1.5  Copyright (C) 1994-2012 Lua.org, PUC-Rio
		// LuaJIT 2.0.0 -- Copyright (C) 2005-2012 Mike Pall. http://luajit.org/
		$cmd = wfEscapeShellArg( $options['luaPath'] ) . ' -v';
		$handle = popen( $cmd, 'r' );
		if ( $handle ) {
			$ret = fgets( $handle, 80 );
			pclose( $handle );
			if ( $ret && preg_match( '/^Lua(?:JIT)? \S+/', $ret, $m ) ) {
				return $m[0];
			}
		}
		return null;
	}

	public function terminate() {
		if ( $this->proc ) {
			$this->logger->debug( __METHOD__ . ": terminating" );
			proc_terminate( $this->proc );
			proc_close( $this->proc );
			$this->proc = false;
		}
	}

	public function quit() {
		if ( !$this->proc ) {
			return;
		}
		$this->dispatch( [ 'op' => 'quit' ] );
		proc_close( $this->proc );
	}

	public function testquit() {
		if ( !$this->proc ) {
			return;
		}
		$this->dispatch( [ 'op' => 'testquit' ] );
		proc_close( $this->proc );
	}

	/**
	 * @param string $text
	 * @param string $chunkName
	 * @return Scribunto_LuaStandaloneInterpreterFunction
	 */
	public function loadString( $text, $chunkName ) {
		$this->cleanupLuaChunks();

		$result = $this->dispatch( [
			'op' => 'loadString',
			'text' => $text,
			'chunkName' => $chunkName
		] );
		return new Scribunto_LuaStandaloneInterpreterFunction( $this->id, $result[1] );
	}

	/** @inheritDoc */
	public function callFunction( $func, ...$args ) {
		if ( !( $func instanceof Scribunto_LuaStandaloneInterpreterFunction ) ) {
			throw new MWException( __METHOD__ . ': invalid function type' );
		}
		if ( $func->interpreterId !== $this->id ) {
			throw new MWException( __METHOD__ . ': function belongs to a different interpreter' );
		}
		$args = func_get_args();
		unset( $args[0] );
		// $args is now conveniently a 1-based array, as required by the Lua server

		$this->cleanupLuaChunks();

		$result = $this->dispatch( [
			'op' => 'call',
			'id' => $func->id,
			'nargs' => count( $args ),
			'args' => $args,
		] );
		// Convert return values to zero-based
		return array_values( $result );
	}

	/** @inheritDoc */
	public function wrapPhpFunction( $callable ) {
		static $uid = 0;
		$id = "anonymous*" . ++$uid;
		$this->callbacks[$id] = $callable;
		$ret = $this->dispatch( [
			'op' => 'wrapPhpFunction',
			'id' => $id,
		] );
		return $ret[1];
	}

	public function cleanupLuaChunks() {
		if ( isset( Scribunto_LuaStandaloneInterpreterFunction::$anyChunksDestroyed[$this->id] ) ) {
			unset( Scribunto_LuaStandaloneInterpreterFunction::$anyChunksDestroyed[$this->id] );
			$this->dispatch( [
				'op' => 'cleanupChunks',
				'ids' => Scribunto_LuaStandaloneInterpreterFunction::$activeChunkIds[$this->id]
			] );
		}
	}

	/** @inheritDoc */
	public function isLuaFunction( $object ) {
		return $object instanceof Scribunto_LuaStandaloneInterpreterFunction;
	}

	/** @inheritDoc */
	public function registerLibrary( $name, array $functions ) {
		// Make sure all ids are unique, even when libraries share the same name
		// which is especially relevant for "mw_interface" (T211203).
		static $uid = 0;
		$uid++;

		$functionIds = [];
		foreach ( $functions as $funcName => $callback ) {
			$id = "$name-$funcName-$uid";
			$this->callbacks[$id] = $callback;
			$functionIds[$funcName] = $id;
		}
		$this->dispatch( [
			'op' => 'registerLibrary',
			'name' => $name,
			'functions' => $functionIds,
		] );
	}

	/**
	 * Get interpreter status
	 * @return array
	 */
	public function getStatus() {
		$result = $this->dispatch( [
			'op' => 'getStatus',
		] );
		return $result[1];
	}

	public function pauseUsageTimer() {
	}

	public function unpauseUsageTimer() {
	}

	/**
	 * Fill in missing nulls in a list received from Lua
	 *
	 * @param array $array List received from Lua
	 * @param int $count Number of values that should be in the list
	 * @return array Non-sparse array
	 */
	private static function fixNulls( array $array, $count ) {
		if ( count( $array ) === $count ) {
			return $array;
		} else {
			return array_replace( array_fill( 1, $count, null ), $array );
		}
	}

	/**
	 * Handle a protocol 'call' message from Lua
	 * @param array $message
	 * @return array Response message to send to Lua
	 */
	protected function handleCall( $message ) {
		$message['args'] = self::fixNulls( $message['args'], $message['nargs'] );
		try {
			$result = $this->callback( $message['id'], $message['args'] );
		} catch ( Scribunto_LuaError $e ) {
			return [
				'op' => 'error',
				'value' => $e->getLuaMessage(),
			];
		}

		// Convert to a 1-based array
		if ( $result !== null && count( $result ) ) {
			$result = array_combine( range( 1, count( $result ) ), $result );
		} else {
			$result = [];
		}

		return [
			'op' => 'return',
			'nvalues' => count( $result ),
			'values' => $result
		];
	}

	/**
	 * Call a registered/wrapped PHP function from Lua
	 * @param string $id Callback ID
	 * @param array $args Arguments to pass to the callback
	 * @return mixed Return value from the callback
	 */
	protected function callback( $id, array $args ) {
		return ( $this->callbacks[$id] )( ...$args );
	}

	/**
	 * Handle a protocol error response
	 *
	 * Converts the encoded Lua error to an appropriate exception and throws it.
	 *
	 * @param array $message
	 */
	protected function handleError( $message ) {
		$opts = [];
		if ( preg_match( '/^(.*?):(\d+): (.*)$/', $message['value'], $m ) ) {
			$opts['module'] = $m[1];
			$opts['line'] = $m[2];
			$message['value'] = $m[3];
		}
		if ( isset( $message['trace'] ) ) {
			$opts['trace'] = array_values( $message['trace'] );
		}
		throw $this->engine->newLuaError( $message['value'], $opts );
	}

	/**
	 * Send a protocol message to Lua, and handle any responses
	 * @param array $msgToLua
	 * @return mixed Response data
	 */
	protected function dispatch( $msgToLua ) {
		$this->sendMessage( $msgToLua );
		while ( true ) {
			$msgFromLua = $this->receiveMessage();

			switch ( $msgFromLua['op'] ) {
				case 'return':
					return self::fixNulls( $msgFromLua['values'], $msgFromLua['nvalues'] );
				case 'call':
					$msgToLua = $this->handleCall( $msgFromLua );
					$this->sendMessage( $msgToLua );
					break;
				case 'error':
					$this->handleError( $msgFromLua );
					return [];
				default:
					$this->logger->error( __METHOD__ . ": invalid response op \"{$msgFromLua['op']}\"" );
					throw $this->engine->newException( 'scribunto-luastandalone-decode-error' );
			}
		}
	}

	/**
	 * Send a protocol message to Lua
	 * @param array $msg
	 */
	protected function sendMessage( $msg ) {
		$this->debug( "TX ==> {$msg['op']}" );
		$this->checkValid();
		// Send the message
		$encMsg = $this->encodeMessage( $msg );
		if ( !fwrite( $this->writePipe, $encMsg ) ) {
			// Write error, probably the process has terminated
			// If it has, handleIOError() will throw. If not, throw an exception ourselves.
			$this->handleIOError();
			throw $this->engine->newException( 'scribunto-luastandalone-write-error' );
		}
	}

	/**
	 * Receive a protocol message from Lua
	 * @return array
	 */
	protected function receiveMessage() {
		$this->checkValid();
		// Read the header
		$header = fread( $this->readPipe, 16 );
		if ( strlen( $header ) !== 16 ) {
			$this->handleIOError();
			throw $this->engine->newException( 'scribunto-luastandalone-read-error' );
		}
		$length = $this->decodeHeader( $header );

		// Read the reply body
		$body = '';
		$lengthRemaining = $length;
		while ( $lengthRemaining ) {
			$buffer = fread( $this->readPipe, $lengthRemaining );
			if ( $buffer === false || feof( $this->readPipe ) ) {
				$this->handleIOError();
				throw $this->engine->newException( 'scribunto-luastandalone-read-error' );
			}
			$body .= $buffer;
			$lengthRemaining -= strlen( $buffer );
		}
		$body = strtr( $body, [
			'\\r' => "\r",
			'\\n' => "\n",
			'\\\\' => '\\',
		] );
		$msg = unserialize( $body );
		$this->debug( "RX <== {$msg['op']}" );
		return $msg;
	}

	/**
	 * Encode a protocol message to send to Lua
	 * @param mixed $message
	 * @return string
	 */
	protected function encodeMessage( $message ) {
		$serialized = $this->encodeLuaVar( $message );
		$length = strlen( $serialized );
		$check = $length * 2 - 1;

		return sprintf( '%08x%08x%s', $length, $check, $serialized );
	}

	/**
	 * @param mixed $var
	 * @param int $level
	 *
	 * @return string
	 * @throws MWException
	 */
	protected function encodeLuaVar( $var, $level = 0 ) {
		if ( $level > 100 ) {
			throw new MWException( __METHOD__ . ': recursion depth limit exceeded' );
		}
		$type = gettype( $var );
		switch ( $type ) {
			case 'boolean':
				return $var ? 'true' : 'false';
			case 'integer':
				return $var;
			case 'double':
				if ( !is_finite( $var ) ) {
					if ( is_nan( $var ) ) {
						return '(0/0)';
					}
					if ( $var === INF ) {
						return '(1/0)';
					}
					if ( $var === -INF ) {
						return '(-1/0)';
					}
					throw new MWException( __METHOD__ . ': cannot convert non-finite number' );
				}
				return sprintf( '%.17g', $var );
			case 'string':
				return '"' .
					strtr( $var, [
						'"' => '\\"',
						'\\' => '\\\\',
						"\n" => '\\n',
						"\r" => '\\r',
						"\000" => '\\000',
					] ) .
					'"';
			case 'array':
				$s = '{';
				foreach ( $var as $key => $element ) {
					if ( $s !== '{' ) {
						$s .= ',';
					}

					// Lua's number type can't represent most integers beyond 2**53, so stringify such keys
					if ( is_int( $key ) && ( $key > 9007199254740992 || $key < -9007199254740992 ) ) {
						$key = sprintf( '%d', $key );
					}

					$s .= '[' . $this->encodeLuaVar( $key, $level + 1 ) . ']' .
						'=' . $this->encodeLuaVar( $element, $level + 1 );
				}
				$s .= '}';
				return $s;
			case 'object':
				if ( !( $var instanceof Scribunto_LuaStandaloneInterpreterFunction ) ) {
					throw new MWException( __METHOD__ . ': unable to convert object of type ' .
						get_class( $var ) );
				} elseif ( $var->interpreterId !== $this->id ) {
					throw new MWException(
						__METHOD__ . ': unable to convert function belonging to a different interpreter'
					);
				} else {
					return 'chunks[' . intval( $var->id ) . ']';
				}
			case 'resource':
				throw new MWException( __METHOD__ . ': unable to convert resource' );
			case 'NULL':
				return 'nil';
			default:
				throw new MWException( __METHOD__ . ': unable to convert variable of unknown type' );
		}
	}

	/**
	 * Verify protocol header and extract the body length.
	 * @param string $header
	 * @return int Length
	 */
	protected function decodeHeader( $header ) {
		$length = substr( $header, 0, 8 );
		$check = substr( $header, 8, 8 );
		if ( !preg_match( '/^[0-9a-f]+$/', $length ) || !preg_match( '/^[0-9a-f]+$/', $check ) ) {
			throw $this->engine->newException( 'scribunto-luastandalone-decode-error' );
		}
		$length = hexdec( $length );
		$check = hexdec( $check );
		if ( $length * 2 - 1 !== $check ) {
			throw $this->engine->newException( 'scribunto-luastandalone-decode-error' );
		}
		return $length;
	}

	/**
	 * @throws ScribuntoException
	 */
	protected function checkValid() {
		if ( !$this->proc ) {
			$this->logger->error( __METHOD__ . ": process already terminated" );
			if ( $this->exitError ) {
				throw $this->exitError;
			} else {
				throw $this->engine->newException( 'scribunto-luastandalone-gone' );
			}
		}
	}

	/**
	 * @throws ScribuntoException
	 */
	protected function handleIOError() {
		$this->checkValid();

		// Terminate, fetch the status, then close. proc_close()'s return
		// value isn't helpful here because there's no way to differentiate a
		// signal-kill from a normal exit.
		proc_terminate( $this->proc );
		while ( true ) {
			$status = proc_get_status( $this->proc );
			// XXX: Should proc_get_status docs be changed so that
			// its documented as possibly returning false?
			if ( $status === false ) {
				// WTF? Let the caller throw an appropriate error.
				return;
			}
			if ( !$status['running'] ) {
				break;
			}
			usleep( 10000 ); // Give the killed process a chance to be scheduled
		}
		proc_close( $this->proc );
		$this->proc = false;

		// proc_open() sometimes uses a shell, check for shell-style signal reporting.
		if ( !$status['signaled'] && ( $status['exitcode'] & 0x80 ) === 0x80 ) {
			$status['signaled'] = true;
			$status['termsig'] = $status['exitcode'] - 128;
		}

		if ( $status['signaled'] ) {
			if ( defined( 'SIGXCPU' ) && $status['termsig'] === SIGXCPU ) {
				$this->exitError = $this->engine->newException( 'scribunto-common-timeout' );
			} else {
				$this->exitError = $this->engine->newException( 'scribunto-luastandalone-signal',
					[ 'args' => [ $status['termsig'] ] ] );
			}
		} else {
			$this->exitError = $this->engine->newException( 'scribunto-luastandalone-exited',
				[ 'args' => [ $status['exitcode'] ] ] );
		}
		throw $this->exitError;
	}

	/**
	 * @param string $msg
	 */
	protected function debug( $msg ) {
		if ( $this->enableDebug ) {
			$this->logger->debug( "Lua: $msg" );
		}
	}
}
