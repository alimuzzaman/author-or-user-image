<?php
/**
 * Static PHP 7.4-8.5 compatibility and host-disabled-function guard checker.
 *
 * Compatible with PHP 7.4+ itself and runnable without WordPress booted.
 *
 * Usage:
 *   php tests/php-compatibility.php
 *   php tests/php-compatibility.php --self-test
 *   php tests/php-compatibility.php --target=/path/to/file.php
 *
 * @package AuthorOrUserImage
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

error_reporting( E_ALL );
set_error_handler(
	static function ( $errno, $errstr, $errfile, $errline ) {
		if ( 0 === ( error_reporting() & $errno ) ) {
			return false;
		}
		fwrite( STDERR, sprintf( "PHP error [errno=%d] %s in %s:%d\n", $errno, $errstr, $errfile, $errline ) );
		exit( 1 );
	}
);

/**
 * Built-in catalog of functions commonly disabled by shared/managed hosts.
 *
 * @return array<int, string>
 */
function zmai_compat_banned_host_functions() {
	return array(
		'exec',
		'shell_exec',
		'system',
		'passthru',
		'popen',
		'proc_open',
		'proc_close',
		'proc_get_status',
		'proc_nice',
		'proc_terminate',
		'pcntl_exec',
		'pcntl_fork',
		'pcntl_signal',
		'pcntl_alarm',
		'pcntl_waitpid',
		'pcntl_wait',
		'pcntl_wifexited',
		'pcntl_wifstopped',
		'pcntl_wifsignaled',
		'pcntl_wexitstatus',
		'pcntl_wtermsig',
		'pcntl_wstopsig',
		'pcntl_signal_dispatch',
		'dl',
		'show_source',
		'highlight_file',
		'phpinfo',
		'php_uname',
		'posix_kill',
		'posix_mkfifo',
		'posix_setpgid',
		'posix_setsid',
		'posix_setuid',
		'posix_getpwuid',
		'posix_uname',
		'putenv',
		'apache_child_terminate',
		'apache_setenv',
		'symlink',
		'link',
		'readlink',
		'linkinfo',
		'disk_free_space',
		'disk_total_space',
		'parse_ini_file',
		'escapeshellarg',
		'escapeshellcmd',
		'curl_exec',
		'curl_multi_exec',
		'fsockopen',
		'pfsockopen',
		'stream_socket_server',
		'mail',
		'set_time_limit',
		'ignore_user_abort',
		'ini_set',
		'ini_alter',
		'ini_restore',
		'ini_get_all',
		'chmod',
		'chown',
		'chgrp',
		'lchgrp',
		'lchown',
		'fpassthru',
		'readfile',
		'glob',
		'getmyuid',
		'getmypid',
		'getrusage',
		'get_current_user',
		'eval',
	);
}

/**
 * Host-disableable functions allowed only when guarded with function_exists().
 *
 * @return array<int, string>
 */
function zmai_compat_guarded_host_functions() {
	return array(
		'scandir',
		'error_log',
		'exif_read_data',
		'ini_get',
		'lstat',
		'mb_substr',
		'opendir',
		'readdir',
		'closedir',
	);
}

/**
 * Stdlib functions added in PHP 8.0-8.5 (missing on minimum supported PHP 7.4).
 *
 * @return array<string, string> Map of lowercase function => minimum PHP version.
 */
function zmai_compat_php8_functions() {
	return array(
		'str_contains'                   => '8.0',
		'str_starts_with'                => '8.0',
		'str_ends_with'                  => '8.0',
		'fdiv'                           => '8.0',
		'get_debug_type'                 => '8.0',
		'preg_last_error_msg'            => '8.0',
		'get_resource_id'                => '8.0',
		'array_is_list'                  => '8.1',
		'enum_exists'                    => '8.1',
		'fsync'                          => '8.1',
		'fdatasync'                      => '8.1',
		'openssl_cms_verify'             => '8.0',
		'openssl_cms_sign'               => '8.0',
		'curl_upkeep'                    => '8.2',
		'ini_parse_quantity'             => '8.2',
		'memory_reset_peak_usage'        => '8.2',
		'openssl_cipher_key_length'      => '8.2',
		'json_validate'                  => '8.3',
		'mb_str_pad'                     => '8.3',
		'ldap_exop_sync'                 => '8.3',
		'ldap_connect_wallet'            => '8.3',
		'array_find'                     => '8.4',
		'array_find_key'                 => '8.4',
		'array_any'                      => '8.4',
		'array_all'                      => '8.4',
		'mb_trim'                        => '8.4',
		'mb_ltrim'                       => '8.4',
		'mb_rtrim'                       => '8.4',
		'mb_ucfirst'                     => '8.4',
		'mb_lcfirst'                     => '8.4',
		'bcfloor'                        => '8.4',
		'bcceil'                         => '8.4',
		'bcround'                        => '8.4',
		'http_get_last_response_headers' => '8.4',
		'http_clear_last_response_headers' => '8.4',
		'grapheme_str_split'             => '8.4',
		'array_first'                    => '8.5',
		'array_last'                     => '8.5',
		'get_error_handler'              => '8.5',
		'get_exception_handler'          => '8.5',
	);
}

/**
 * Classes, interfaces, and attributes added in PHP 8.0-8.5.
 *
 * @return array<string, string> Map of lowercase class name => minimum PHP version.
 */
function zmai_compat_php8_classes() {
	return array(
		'valueerror'             => '8.0',
		'unhandledmatcherror'    => '8.0',
		'stringable'             => '8.0',
		'phptoken'               => '8.0',
		'weakmap'                => '8.0',
		'gdimage'                => '8.0',
		'fiber'                  => '8.1',
		'fibererror'             => '8.1',
		'backedenum'             => '8.1',
		'unitenum'               => '8.1',
		'curlsharepersistenthandle' => '8.5',
		'sensitiveparameter'     => '8.2',
		'allowdynamicproperties' => '8.2',
		'random\\randomizer'     => '8.2',
		'override'               => '8.3',
		'deprecated'             => '8.4',
	);
}

/**
 * Constants added in PHP 8.0-8.5.
 *
 * @return array<string, string> Map of exact constant => minimum PHP version.
 */
function zmai_compat_php8_constants() {
	return array(
		'FILTER_VALIDATE_BOOL' => '8.0',
		'CURLSSLOPT_NO_PARTIALCHAIN' => '8.0',
		'JSON_INVALID_UTF8_IGNORE' => '7.2', // Allowed in 7.4; kept explicit for reference.
		'IMG_AVIF'             => '8.1',
		'IMAGETYPE_AVIF'       => '8.1',
		'IMAGETYPE_HEIF'       => '8.5',
		'LIBXML_NO_XXE'        => '8.4',
	);
}

/**
 * Functions deprecated or removed across PHP 7.4-8.5.
 *
 * @return array<string, string> Map of lowercase function => reason.
 */
function zmai_compat_deprecated_functions() {
	return array(
		'create_function'          => 'Removed in PHP 8.0',
		'each'                     => 'Removed in PHP 8.0',
		'get_magic_quotes_gpc'     => 'Removed in PHP 8.0',
		'get_magic_quotes_runtime' => 'Removed in PHP 8.0',
		'money_format'             => 'Removed in PHP 8.0',
		'restore_include_path'     => 'Removed in PHP 8.0',
		'hebrevc'                  => 'Removed in PHP 8.0',
		'convert_cyr_string'       => 'Removed in PHP 8.0',
		'ezmlm_hash'               => 'Removed in PHP 8.0',
		'png2wbmp'                 => 'Removed in PHP 8.0',
		'jpeg2wbmp'                => 'Removed in PHP 8.0',
		'strftime'                 => 'Deprecated in PHP 8.1',
		'gmstrftime'               => 'Deprecated in PHP 8.1',
		'strptime'                 => 'Deprecated in PHP 8.1',
		'mhash'                    => 'Deprecated in PHP 8.1',
		'mhash_keygen_s2k'         => 'Deprecated in PHP 8.1',
		'utf8_encode'              => 'Deprecated in PHP 8.2',
		'utf8_decode'              => 'Deprecated in PHP 8.2',
		'ldap_control_paged_result' => 'Removed in PHP 8.0',
		'imagedestroy'             => 'Deprecated in PHP 8.5',
		'finfo_close'              => 'Deprecated in PHP 8.5',
		'curl_close'               => 'Deprecated in PHP 8.5',
		'curl_share_close'         => 'Deprecated in PHP 8.5',
		'xml_parser_free'          => 'Deprecated in PHP 8.5',
	);
}

/**
 * Helper: find previous non-whitespace/non-comment token index.
 *
 * @param array<int, mixed> $tokens Token list.
 * @param int               $idx    Starting index.
 * @return int
 */
function zmai_compat_prev_sig( array $tokens, $idx ) {
	for ( $i = $idx - 1; $i >= 0; $i-- ) {
		if ( is_array( $tokens[ $i ] ) && in_array( $tokens[ $i ][0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
			continue;
		}
		return $i;
	}
	return -1;
}

/**
 * Helper: find next non-whitespace/non-comment token index.
 *
 * @param array<int, mixed> $tokens Token list.
 * @param int               $idx    Starting index.
 * @return int
 */
function zmai_compat_next_sig( array $tokens, $idx ) {
	$count = count( $tokens );
	for ( $i = $idx + 1; $i < $count; $i++ ) {
		if ( is_array( $tokens[ $i ] ) && in_array( $tokens[ $i ][0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
			continue;
		}
		return $i;
	}
	return -1;
}

/**
 * Parse a token string guard call like function_exists('foo') or class_exists('Bar').
 *
 * @param array<int, mixed>  $tokens     Token list.
 * @param int                $start_idx  Index of paren '(' after guard function name.
 * @param array<int, string> $out_names  Parsed guarded symbol names.
 * @return int Index of closing ')'.
 */
function zmai_compat_extract_guard_names( array $tokens, $start_idx, &$out_names ) {
	$depth = 0;
	$count = count( $tokens );
	for ( $j = $start_idx; $j < $count; $j++ ) {
		$tok = $tokens[ $j ];
		if ( '(' === $tok ) {
			++$depth;
		} elseif ( ')' === $tok ) {
			--$depth;
			if ( 0 === $depth ) {
				return $j;
			}
		} elseif ( 1 === $depth && is_array( $tok ) && T_CONSTANT_ENCAPSED_STRING === $tok[0] ) {
			$raw         = substr( $tok[1], 1, -1 );
			$raw         = str_replace( '\\\\', '\\', $raw );
			$raw         = ltrim( $raw, '\\' );
			$out_names[] = strtolower( $raw );
		}
	}
	return $start_idx;
}

/**
 * Collect un-negated guards inside a condition token slice.
 *
 * @param array<int, mixed> $tokens Token list.
 * @param int               $from   Start token index.
 * @param int               $to     End token index (inclusive).
 * @return array<string, array<int, string>> Map with keys functions, classes, constants, version_guard.
 */
function zmai_compat_scan_condition_guards( array $tokens, $from, $to ) {
	$guards = array(
		'functions'     => array(),
		'classes'       => array(),
		'constants'     => array(),
		'version_guard' => false,
		'neg_functions' => array(),
	);

	for ( $i = $from; $i <= $to; $i++ ) {
		$tok = $tokens[ $i ];
		if ( ! is_array( $tok ) ) {
			continue;
		}
		if ( T_STRING === $tok[0] && 'PHP_VERSION_ID' === $tok[1] ) {
			$next = zmai_compat_next_sig( $tokens, $i );
			if ( $next > 0 && '<' === $tokens[ $next ] ) {
				$guards['version_guard'] = true;
			}
			continue;
		}
		if ( T_STRING !== $tok[0] ) {
			continue;
		}
		$name = strtolower( $tok[1] );
		$prev = zmai_compat_prev_sig( $tokens, $i );
		$next = zmai_compat_next_sig( $tokens, $i );
		if ( $next < 0 || $next > $to || '(' !== $tokens[ $next ] ) {
			continue;
		}
		$negated = ( $prev >= 0 && '!' === $tokens[ $prev ] );
		$names   = array();
		zmai_compat_extract_guard_names( $tokens, $next, $names );
		if ( 'function_exists' === $name ) {
			if ( $negated ) {
				$guards['neg_functions'] = array_merge( $guards['neg_functions'], $names );
			} else {
				$guards['functions'] = array_merge( $guards['functions'], $names );
			}
		} elseif ( in_array( $name, array( 'class_exists', 'interface_exists', 'trait_exists' ), true ) && ! $negated ) {
			$guards['classes'] = array_merge( $guards['classes'], $names );
		} elseif ( 'defined' === $name && ! $negated ) {
			foreach ( $names as $cname ) {
				$guards['constants'][] = strtoupper( $cname );
			}
		} elseif ( 'version_compare' === $name ) {
			$guards['version_guard'] = true;
		}
	}

	return $guards;
}

/**
 * Scan one PHP file for static compatibility and disabled-function violations.
 *
 * @param string $path Absolute path to PHP file.
 * @return array<int, array<string, mixed>> List of violations.
 */
function zmai_compat_scan_file( $path ) {
	$source = (string) file_get_contents( $path );
	$tokens = token_get_all( $source );
	$count  = count( $tokens );
	$issues = array();

	$banned_host     = array_flip( zmai_compat_banned_host_functions() );
	$guarded_host    = array_flip( zmai_compat_guarded_host_functions() );
	$php8_functions  = zmai_compat_php8_functions();
	$php8_classes    = zmai_compat_php8_classes();
	$php8_constants  = zmai_compat_php8_constants();
	$deprecated_fns  = zmai_compat_deprecated_functions();

	$nullsafe_tok = defined( 'T_NULLSAFE_OBJECT_OPERATOR' ) ? T_NULLSAFE_OBJECT_OPERATOR : -1;
	$enum_tok     = defined( 'T_ENUM' ) ? T_ENUM : -1;
	$readonly_tok = defined( 'T_READONLY' ) ? T_READONLY : -1;
	$match_tok    = defined( 'T_MATCH' ) ? T_MATCH : -1;
	$qual_tok     = defined( 'T_NAME_QUALIFIED' ) ? T_NAME_QUALIFIED : -1;
	$fqual_tok    = defined( 'T_NAME_FULLY_QUALIFIED' ) ? T_NAME_FULLY_QUALIFIED : -1;
	$attr_tok     = defined( 'T_ATTRIBUTE' ) ? T_ATTRIBUTE : -1;

	$add_issue = static function ( $code, $line, $symbol, $message ) use ( &$issues, $path ) {
		$issues[] = array(
			'file'    => $path,
			'line'    => (int) $line,
			'code'    => $code,
			'symbol'  => $symbol,
			'message' => $message,
		);
	};

	// Stack of brace blocks; each frame tracks guards active within that block and exit-guarantees for parent.
	$brace_depth   = 0;
	$block_guards  = array( 0 => array( 'functions' => array(), 'classes' => array(), 'constants' => array(), 'version_guard' => false ) );
	$pending_guard = null; // Prepared by if/elseif before '{'.
	$stmt_guards   = array( 'functions' => array(), 'classes' => array(), 'constants' => array(), 'version_guard' => false );
	$stmt_ternary  = false;
	$stmt_neg_funs = array();

	for ( $i = 0; $i < $count; $i++ ) {
		$tok = $tokens[ $i ];

		// Raw character tokens.
		if ( ! is_array( $tok ) ) {
			if ( ';' === $tok ) {
				$stmt_guards   = array( 'functions' => array(), 'classes' => array(), 'constants' => array(), 'version_guard' => false );
				$stmt_ternary  = false;
				$stmt_neg_funs = array();
			} elseif ( '?' === $tok ) {
				$stmt_ternary = true;
			} elseif ( ':' === $tok && $stmt_ternary && ! empty( $stmt_neg_funs ) ) {
				$stmt_guards['functions'] = array_merge( $stmt_guards['functions'], $stmt_neg_funs );
			} elseif ( '{' === $tok ) {
				++$brace_depth;
				if ( is_array( $pending_guard ) ) {
					$block_guards[ $brace_depth ] = array(
						'functions'     => $pending_guard['functions'],
						'classes'       => $pending_guard['classes'],
						'constants'     => $pending_guard['constants'],
						'version_guard' => $pending_guard['version_guard'],
						'neg_functions' => $pending_guard['neg_functions'],
						'has_exit'      => false,
					);
					$pending_guard = null;
				} else {
					$block_guards[ $brace_depth ] = array(
						'functions'     => array(),
						'classes'       => array(),
						'constants'     => array(),
						'version_guard' => false,
						'neg_functions' => array(),
						'has_exit'      => false,
					);
				}
			} elseif ( '}' === $tok ) {
				$closing = $block_guards[ $brace_depth ] ?? null;
				unset( $block_guards[ $brace_depth ] );
				if ( $brace_depth > 0 ) {
					--$brace_depth;
				}
				if ( is_array( $closing ) && ! empty( $closing['has_exit'] ) && ! empty( $closing['neg_functions'] ) ) {
					$block_guards[ $brace_depth ]['functions'] = array_values(
						array_unique( array_merge( $block_guards[ $brace_depth ]['functions'], $closing['neg_functions'] ) )
					);
				}
				$stmt_guards   = array( 'functions' => array(), 'classes' => array(), 'constants' => array(), 'version_guard' => false );
				$stmt_ternary  = false;
				$stmt_neg_funs = array();
			}
			continue;
		}

		$tid  = $tok[0];
		$text = $tok[1];
		$line = $tok[2];

		if ( in_array( $tid, array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
			continue;
		}

		// Record early return / throw in current block for negative guard propagation.
		if ( in_array( $tid, array( T_RETURN, T_THROW, T_EXIT ), true ) && isset( $block_guards[ $brace_depth ] ) ) {
			$block_guards[ $brace_depth ]['has_exit'] = true;
		}

		// Parse `if ( ... ) {` / `elseif ( ... ) {` conditions to scope guards to the block.
		if ( in_array( $tid, array( T_IF, T_ELSEIF ), true ) ) {
			$open = zmai_compat_next_sig( $tokens, $i );
			if ( $open > 0 && '(' === $tokens[ $open ] ) {
				$depth = 0;
				$close = $open;
				for ( $k = $open; $k < $count; $k++ ) {
					if ( '(' === $tokens[ $k ] ) {
						++$depth;
					} elseif ( ')' === $tokens[ $k ] ) {
						--$depth;
						if ( 0 === $depth ) {
							$close = $k;
							break;
						}
					}
				}
				$after = zmai_compat_next_sig( $tokens, $close );
				if ( $after > 0 && '{' === $tokens[ $after ] ) {
					$pending_guard = zmai_compat_scan_condition_guards( $tokens, $open, $close );
				}
			}
			continue;
		}

		// Deprecated `${var}` / `${expr}` string interpolation (PHP 8.2).
		if ( T_DOLLAR_OPEN_CURLY_BRACES === $tid ) {
			$add_issue( 'deprecated_dollar_brace_interpolation', $line, '${...}', 'Deprecated ${...} string interpolation (PHP 8.2+); use {$var}.' );
			continue;
		}

		// `eval(...)` keyword construct.
		if ( T_EVAL === $tid ) {
			$add_issue( 'banned_host_function', $line, 'eval', 'Use of eval() is banned on hardened hosts and flagged by security scanners.' );
			continue;
		}

		// PHP 8.4 implicit nullable typed parameters: function foo( Type $param = null ).
		if ( T_FUNCTION === $tid ) {
			$open = -1;
			for ( $k = $i + 1; $k < $count; $k++ ) {
				if ( '{' === $tokens[ $k ] || ';' === $tokens[ $k ] ) {
					break;
				}
				if ( '(' === $tokens[ $k ] ) {
					$open = $k;
					break;
				}
			}
			if ( $open > 0 ) {
				$depth = 0;
				$close = $open;
				for ( $k = $open; $k < $count; $k++ ) {
					if ( '(' === $tokens[ $k ] ) {
						++$depth;
					} elseif ( ')' === $tokens[ $k ] ) {
						--$depth;
						if ( 0 === $depth ) {
							$close = $k;
							break;
						}
					}
				}
				$chunks   = array( array() );
				$cidx     = 0;
				$pdepth   = 0;
				for ( $k = $open + 1; $k < $close; $k++ ) {
					$pt = $tokens[ $k ];
					if ( ! is_array( $pt ) && in_array( $pt, array( '(', '[', '{' ), true ) ) {
						++$pdepth;
					} elseif ( ! is_array( $pt ) && in_array( $pt, array( ')', ']', '}' ), true ) ) {
						--$pdepth;
					} elseif ( ',' === $pt && 0 === $pdepth ) {
						++$cidx;
						$chunks[ $cidx ] = array();
						continue;
					}
					if ( ! ( is_array( $pt ) && in_array( $pt[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) ) {
						$chunks[ $cidx ][] = $pt;
					}
				}
				foreach ( $chunks as $param_tokens ) {
					if ( empty( $param_tokens ) ) {
						continue;
					}
					$eq_pos  = -1;
					$var_pos = -1;
					foreach ( $param_tokens as $pidx => $pt ) {
						if ( '=' === $pt && -1 === $eq_pos ) {
							$eq_pos = $pidx;
						}
						if ( is_array( $pt ) && T_VARIABLE === $pt[0] && -1 === $var_pos ) {
							$var_pos = $pidx;
						}
					}
					if ( $eq_pos > $var_pos && $var_pos > 0 ) {
						$def_tok = $param_tokens[ $eq_pos + 1 ] ?? null;
						if ( is_array( $def_tok ) && T_STRING === $def_tok[0] && 'null' === strtolower( $def_tok[1] ) && ! isset( $param_tokens[ $eq_pos + 2 ] ) ) {
							$has_nullable = false;
							$has_type     = false;
							for ( $t = 0; $t < $var_pos; $t++ ) {
								$tt = $param_tokens[ $t ];
								if ( '?' === $tt ) {
									$has_nullable = true;
								} elseif ( is_array( $tt ) && in_array( $tt[0], array( T_PUBLIC, T_PROTECTED, T_PRIVATE, $readonly_tok ), true ) ) {
									continue;
								} elseif ( '&' === $tt || ( is_array( $tt ) && T_ELLIPSIS === $tt[0] ) ) {
									continue;
								} elseif ( is_array( $tt ) && T_STRING === $tt[0] && 'null' === strtolower( $tt[1] ) ) {
									$has_nullable = true;
								} else {
									$has_type = true;
								}
							}
							if ( $has_type && ! $has_nullable ) {
								$pline = is_array( $param_tokens[ $var_pos ] ) ? $param_tokens[ $var_pos ][2] : $line;
								$pname = is_array( $param_tokens[ $var_pos ] ) ? $param_tokens[ $var_pos ][1] : '$param';
								$add_issue( 'implicit_nullable_param', $pline, $pname, 'Implicitly nullable typed parameter is deprecated in PHP 8.4+; use explicit ?Type.' );
							}
						}
					}
				}
			}
			continue;
		}

		// Helper closure: check active guards at current cursor.
		$is_fn_guarded = static function ( $fn_name ) use ( &$block_guards, &$stmt_guards, $brace_depth ) {
			$fn_name = strtolower( $fn_name );
			if ( in_array( $fn_name, $stmt_guards['functions'], true ) ) {
				return true;
			}
			for ( $d = 0; $d <= $brace_depth; $d++ ) {
				if ( isset( $block_guards[ $d ]['functions'] ) && in_array( $fn_name, $block_guards[ $d ]['functions'], true ) ) {
					return true;
				}
			}
			return false;
		};

		$is_class_guarded = static function ( $class_name ) use ( &$block_guards, &$stmt_guards, $brace_depth ) {
			$class_name = strtolower( ltrim( $class_name, '\\' ) );
			if ( in_array( $class_name, $stmt_guards['classes'], true ) ) {
				return true;
			}
			for ( $d = 0; $d <= $brace_depth; $d++ ) {
				if ( isset( $block_guards[ $d ]['classes'] ) && in_array( $class_name, $block_guards[ $d ]['classes'], true ) ) {
					return true;
				}
			}
			return false;
		};

		$is_const_guarded = static function ( $const_name ) use ( &$block_guards, &$stmt_guards, $brace_depth ) {
			$const_name = strtoupper( $const_name );
			if ( in_array( $const_name, $stmt_guards['constants'], true ) ) {
				return true;
			}
			for ( $d = 0; $d <= $brace_depth; $d++ ) {
				if ( isset( $block_guards[ $d ]['constants'] ) && in_array( $const_name, $block_guards[ $d ]['constants'], true ) ) {
					return true;
				}
			}
			return false;
		};

		$has_version_guard = static function () use ( &$block_guards, &$stmt_guards, $brace_depth ) {
			if ( ! empty( $stmt_guards['version_guard'] ) ) {
				return true;
			}
			for ( $d = 0; $d <= $brace_depth; $d++ ) {
				if ( ! empty( $block_guards[ $d ]['version_guard'] ) ) {
					return true;
				}
			}
			return false;
		};

		// Track inline statement-level guards like `function_exists('mb_substr') ? mb_substr(...) : ...`.
		if ( T_STRING === $tid && in_array( strtolower( $text ), array( 'function_exists', 'class_exists', 'interface_exists', 'trait_exists', 'defined' ), true ) ) {
			$prev = zmai_compat_prev_sig( $tokens, $i );
			$next = zmai_compat_next_sig( $tokens, $i );
			$gfn  = strtolower( $text );
			$is_member = $prev >= 0 && is_array( $tokens[ $prev ] ) && in_array( $tokens[ $prev ][0], array( T_OBJECT_OPERATOR, $nullsafe_tok, T_DOUBLE_COLON, T_FUNCTION ), true );
			if ( ! $is_member && $next > 0 && '(' === $tokens[ $next ] ) {
				$negated = ( $prev >= 0 && '!' === $tokens[ $prev ] );
				$names   = array();
				zmai_compat_extract_guard_names( $tokens, $next, $names );
				if ( 'function_exists' === $gfn ) {
					if ( $negated ) {
						$stmt_neg_funs = array_merge( $stmt_neg_funs, $names );
					} else {
						$stmt_guards['functions'] = array_merge( $stmt_guards['functions'], $names );
					}
				} elseif ( 'defined' === $gfn && ! $negated ) {
					foreach ( $names as $cn ) {
						$stmt_guards['constants'][] = strtoupper( $cn );
					}
				} elseif ( ! $negated ) {
					$stmt_guards['classes'] = array_merge( $stmt_guards['classes'], $names );
				}
			}
			continue;
		}

		// Check identifier/call tokens.
		if ( in_array( $tid, array( T_STRING, $qual_tok, $fqual_tok ), true ) ) {
			$prev = zmai_compat_prev_sig( $tokens, $i );
			$next = zmai_compat_next_sig( $tokens, $i );

			$skip_prev_types = array( T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_CLASS, T_INTERFACE, T_TRAIT, T_CONST, T_NAMESPACE, T_EXTENDS, T_IMPLEMENTS, T_USE );
			if ( $nullsafe_tok > 0 ) {
				$skip_prev_types[] = $nullsafe_tok;
			}
			if ( $enum_tok > 0 ) {
				$skip_prev_types[] = $enum_tok;
			}

			$is_member_or_decl = $prev >= 0 && is_array( $tokens[ $prev ] ) && in_array( $tokens[ $prev ][0], $skip_prev_types, true );
			$is_namespaced_sub = false;
			$is_global_prefixed = false;
			if ( $prev >= 0 && is_array( $tokens[ $prev ] ) && T_NS_SEPARATOR === $tokens[ $prev ][0] ) {
				$before_sep = zmai_compat_prev_sig( $tokens, $prev );
				if ( $before_sep >= 0 && is_array( $tokens[ $before_sep ] ) && T_STRING === $tokens[ $before_sep ][0] ) {
					$is_namespaced_sub = true;
				} else {
					$is_global_prefixed = true;
				}
			}

			$raw_name  = ltrim( $text, '\\' );
			$low_name  = strtolower( $raw_name );
			$is_call   = ! $is_member_or_decl && ! $is_namespaced_sub && ( false === strpos( $raw_name, '\\' ) || $is_global_prefixed ) && $next > 0 && '(' === $tokens[ $next ];
			$is_new    = $prev >= 0 && is_array( $tokens[ $prev ] ) && T_NEW === $tokens[ $prev ][0];
			$is_static = $next > 0 && is_array( $tokens[ $next ] ) && T_DOUBLE_COLON === $tokens[ $next ][0];

			// 1. Function call checks.
			if ( $is_call ) {
				if ( isset( $banned_host[ $low_name ] ) ) {
					$add_issue( 'banned_host_function', $line, $low_name, 'Calls banned host-disabled function ' . $low_name . '().' );
				}
				if ( isset( $guarded_host[ $low_name ] ) && ! $is_fn_guarded( $low_name ) ) {
					$add_issue( 'unguarded_host_function', $line, $low_name, 'Calls host-disableable function ' . $low_name . '() without a function_exists() guard.' );
				}
				if ( isset( $php8_functions[ $low_name ] ) && ! $is_fn_guarded( $low_name ) ) {
					$add_issue( 'php8_only_function', $line, $low_name, 'Calls PHP ' . $php8_functions[ $low_name ] . '+ function ' . $low_name . '() without a function_exists() guard (plugin requires PHP 7.4).' );
				}
				if ( isset( $deprecated_fns[ $low_name ] ) && ! $has_version_guard() ) {
					$add_issue( 'deprecated_function', $line, $low_name, 'Calls deprecated/removed PHP function ' . $low_name . '() (' . $deprecated_fns[ $low_name ] . ').' );
				}
				// Reflection check for any internal deprecated PHP function on the running PHP version.
				if ( function_exists( $low_name ) && ! $has_version_guard() ) {
					$rf = new ReflectionFunction( $low_name );
					if ( $rf->isInternal() && $rf->isDeprecated() ) {
						$add_issue( 'deprecated_function_runtime', $line, $low_name, 'PHP ' . PHP_VERSION . ' marks built-in function ' . $low_name . '() deprecated.' );
					}
				}
				// PHP 8.3 deprecated argument-less get_class() / get_parent_class().
				if ( in_array( $low_name, array( 'get_class', 'get_parent_class' ), true ) ) {
					$after_paren = zmai_compat_next_sig( $tokens, $next );
					if ( $after_paren > 0 && ')' === $tokens[ $after_paren ] ) {
						$add_issue( 'deprecated_argless_get_class', $line, $low_name . '()', 'Calling ' . $low_name . '() without arguments is deprecated since PHP 8.3.' );
					}
				}
			}

			// 2. PHP 8.0+ class/interface instantiation or static access checks.
			if ( ( $is_new || $is_static ) && isset( $php8_classes[ $low_name ] ) && ! $is_class_guarded( $low_name ) ) {
				$add_issue( 'php8_only_class', $line, $raw_name, 'References PHP ' . $php8_classes[ $low_name ] . '+ class/interface ' . $raw_name . ' without a class_exists()/interface_exists() guard.' );
			}

			// 3. Deprecated or PHP 8.0+ constants.
			if ( ! $is_call && ! $is_member_or_decl && ! $is_namespaced_sub ) {
				if ( 'E_STRICT' === $raw_name && ! $has_version_guard() ) {
					$add_issue( 'deprecated_constant_e_strict', $line, 'E_STRICT', 'E_STRICT is deprecated in PHP 8.4+.' );
				}
				if ( isset( $php8_constants[ $raw_name ] ) && version_compare( $php8_constants[ $raw_name ], '8.0', '>=' ) && ! $is_const_guarded( $raw_name ) ) {
					$add_issue( 'php8_only_constant', $line, $raw_name, 'References PHP ' . $php8_constants[ $raw_name ] . '+ constant ' . $raw_name . ' without a defined() guard.' );
				}
			}
		}

		// PHP 8.0+ attribute syntax check for PHP 8.x attributes when unparsed as comments in 7.4 or tokens in 8.0+.
		if ( $attr_tok > 0 && $tid === $attr_tok ) {
			$attr_next = zmai_compat_next_sig( $tokens, $i );
			if ( $attr_next > 0 && is_array( $tokens[ $attr_next ] ) ) {
				$attr_name = strtolower( ltrim( $tokens[ $attr_next ][1], '\\' ) );
				if ( isset( $php8_classes[ $attr_name ] ) ) {
					$add_issue( 'php8_attribute', $line, $tokens[ $attr_next ][1], 'Uses PHP ' . $php8_classes[ $attr_name ] . '+ attribute.' );
				}
			}
		}

		// PHP 8.1+ match / readonly / enum keywords if running on 8.0+.
		if ( ( $match_tok > 0 && $tid === $match_tok ) || ( $enum_tok > 0 && $tid === $enum_tok ) || ( $readonly_tok > 0 && $tid === $readonly_tok ) ) {
			$add_issue( 'php8_only_syntax', $line, $text, 'Uses PHP 8.0+ keyword "' . $text . '" unsupported on PHP 7.4.' );
		}
	}

	return $issues;
}

/**
 * Resolve production PHP files matching tools/check-release.py manifest.
 *
 * @param string $root Repository root path.
 * @return array<int, string>
 */
function zmai_compat_production_files( $root ) {
	$files = array(
		$root . '/author_image.php',
		$root . '/index.php',
		$root . '/uninstall.php',
	);
	$inc = glob( $root . '/includes/*.php' );
	if ( is_array( $inc ) ) {
		sort( $inc );
		$files = array_merge( $files, $inc );
	}
	return $files;
}

/**
 * Run negative & positive self-tests using temporary synthetic PHP snippets.
 *
 * @return int Exit code.
 */
function zmai_compat_run_self_test() {
	$tmp_dir = sys_get_temp_dir() . '/zmai-php-compat-' . str_replace( '.', '-', uniqid( '', true ) );
	if ( ! mkdir( $tmp_dir, 0700, true ) ) {
		fwrite( STDERR, "Could not create temporary directory for self-test.\n" );
		return 1;
	}

	$cases = array(
		'exec' => array(
			'code'     => "<?php\nfunction t() { return exec('id'); }\n",
			'expected' => 'banned_host_function',
		),
		'scandir_unguarded' => array(
			'code'     => "<?php\nfunction t() { return scandir('.'); }\n",
			'expected' => 'unguarded_host_function',
		),
		'error_log_unguarded' => array(
			'code'     => "<?php\nfunction t() { error_log('x'); }\n",
			'expected' => 'unguarded_host_function',
		),
		'ini_get_unguarded' => array(
			'code'     => "<?php\nfunction t() { return ini_get('post_max_size'); }\n",
			'expected' => 'unguarded_host_function',
		),
		'lstat_unguarded' => array(
			'code'     => "<?php\nfunction t(\$p) { return lstat(\$p); }\n",
			'expected' => 'unguarded_host_function',
		),
		'str_starts_with_unguarded' => array(
			'code'     => "<?php\nfunction t(\$s) { return str_starts_with(\$s, 'a'); }\n",
			'expected' => 'php8_only_function',
		),
		'json_validate_unguarded' => array(
			'code'     => "<?php\nfunction t(\$s) { return json_validate(\$s); }\n",
			'expected' => 'php8_only_function',
		),
		'value_error_unguarded' => array(
			'code'     => "<?php\nfunction t() { return new ValueError('bad'); }\n",
			'expected' => 'php8_only_class',
		),
		'filter_validate_bool_unguarded' => array(
			'code'     => "<?php\nfunction t(\$v) { return filter_var(\$v, FILTER_VALIDATE_BOOL); }\n",
			'expected' => 'php8_only_constant',
		),
		'implicit_nullable_param' => array(
			'code'     => "<?php\nfunction t( WP_User \$u = null ) { return \$u; }\n",
			'expected' => 'implicit_nullable_param',
		),
		'dollar_brace_interpolation' => array(
			'code'     => "<?php\nfunction t(\$x) { return \"\${x}\"; }\n",
			'expected' => 'deprecated_dollar_brace_interpolation',
		),
		'argless_get_class' => array(
			'code'     => "<?php\nclass C { function t() { return get_class(); } }\n",
			'expected' => 'deprecated_argless_get_class',
		),
		'deprecated_e_strict' => array(
			'code'     => "<?php\nfunction t() { return E_STRICT; }\n",
			'expected' => 'deprecated_constant_e_strict',
		),
		'deprecated_utf8_encode' => array(
			'code'     => "<?php\nfunction t(\$s) { return utf8_encode(\$s); }\n",
			'expected' => 'deprecated_function',
		),
		'deprecated_imagedestroy' => array(
			'code'     => "<?php\nfunction t(\$i) { imagedestroy(\$i); }\n",
			'expected' => 'deprecated_function',
		),
		'guarded_ok' => array(
			'code'     => "<?php\nfunction t(\$p, \$s) {\n  if ( function_exists('scandir') ) { scandir(\$p); }\n  if ( function_exists('error_log') ) { error_log('ok'); }\n  if ( function_exists('lstat') ) { @lstat(\$p); }\n  \$d = ! function_exists('scandir') ? array() : scandir(\$p);\n  \$m = function_exists('ini_get') ? ini_get('post_max_size') : '';\n  \$u = function_exists('mb_substr') ? mb_substr(\$s, 0, 5) : substr(\$s, 0, 5);\n  return array(\$d, \$m, \$u);\n}\nfunction t2( ?WP_User \$u = null ) { return \$u; }\n",
			'expected' => null,
		),
	);

	$checks = 0;
	$failed = 0;
	try {
		foreach ( $cases as $name => $case ) {
			$file = $tmp_dir . '/' . $name . '.php';
			file_put_contents( $file, $case['code'] );
			$found = zmai_compat_scan_file( $file );
			$codes = array();
			foreach ( $found as $item ) {
				$codes[] = $item['code'];
			}
			++$checks;
			if ( null === $case['expected'] ) {
				$ok = empty( $found );
			} else {
				$ok = in_array( $case['expected'], $codes, true );
			}
			if ( ! $ok ) {
				++$failed;
				echo 'FAIL self-test ' . $name . ' (got: ' . implode( ',', $codes ) . ")\n";
			} else {
				echo 'PASS self-test ' . $name . "\n";
			}
		}
	} finally {
		$left = glob( $tmp_dir . '/*.php' );
		if ( is_array( $left ) ) {
			foreach ( $left as $file ) {
				unlink( $file );
			}
		}
		rmdir( $tmp_dir );
	}

	echo "SELF_TEST_RESULT {$checks} checks, {$failed} failed\n";
	return $failed > 0 ? 1 : 0;
}

$target    = '';
$self_test = false;
foreach ( array_slice( $argv, 1 ) as $arg ) {
	if ( '--self-test' === $arg ) {
		$self_test = true;
	} elseif ( 0 === strpos( $arg, '--target=' ) ) {
		$target = substr( $arg, strlen( '--target=' ) );
	} else {
		fwrite( STDERR, "Unknown argument: {$arg}\n" );
		exit( 2 );
	}
}

if ( $self_test ) {
	exit( zmai_compat_run_self_test() );
}

$root  = dirname( __DIR__ );
$files = '' !== $target ? array( $target ) : zmai_compat_production_files( $root );
$all_issues = array();
foreach ( $files as $file ) {
	if ( ! is_file( $file ) ) {
		fwrite( STDERR, "Missing file: {$file}\n" );
		exit( 2 );
	}
	$all_issues = array_merge( $all_issues, zmai_compat_scan_file( $file ) );
}

if ( $all_issues ) {
	foreach ( $all_issues as $issue ) {
		fwrite( STDERR, sprintf( "FAIL %s:%d [%s] %s (%s)\n", $issue['file'], $issue['line'], $issue['code'], $issue['message'], $issue['symbol'] ) );
	}
	exit( 1 );
}

echo 'PASS static PHP 7.4-8.5 compatibility & host-disabled-function check on ' . count( $files ) . ' files (PHP ' . PHP_VERSION . ")\n";
exit( 0 );
