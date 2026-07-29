<?php
/**
 * Safe Expression Evaluator
 *
 * Provides secure mathematical expression evaluation without using eval().
 * This replaces dangerous eval() calls to prevent PHP code execution vulnerabilities.
 *
 * @package CTXFeed\V5\Helper
 * @since   6.6.42
 */

namespace CTXFeed\V5\Helper;

/**
 * Class SafeExpressionEvaluator
 *
 * Evaluates mathematical expressions safely without using eval().
 * Only supports basic arithmetic operations: +, -, *, /, %, and parentheses.
 *
 * @package CTXFeed\V5\Helper
 */
class SafeExpressionEvaluator {

	/**
	 * Allowed functions for expression evaluation.
	 * These are safe mathematical functions.
	 *
	 * @var array
	 */
	private static $allowed_functions = array(
		'round',
		'ceil',
		'floor',
		'abs',
		'min',
		'max',
		'number_format',
	);

	/**
	 * Evaluate a mathematical expression safely.
	 *
	 * @param string $expression The expression to evaluate (e.g., "return $price * 1.1;").
	 * @param array  $variables  Associative array of variable names to values.
	 *
	 * @return mixed The result of the expression, or the original expression if evaluation fails.
	 */
	public static function evaluate( $expression, array $variables = array() ) {
		// Remove backslashes
		$expression = preg_replace( '/\\\\/', '', $expression );

		// Handle empty expressions
		if ( empty( trim( $expression ) ) ) {
			return '';
		}

		// Extract the return value from "return X;" syntax
		$expression = self::extract_return_expression( $expression );

		if ( empty( trim( $expression ) ) ) {
			return '';
		}

		// Substitute variables with their values
		$expression = self::substitute_variables( $expression, $variables );

		// Validate and evaluate the expression
		return self::safe_evaluate( $expression );
	}

	/**
	 * Extract the expression from "return X;" syntax.
	 *
	 * @param string $expression The full expression.
	 *
	 * @return string The extracted expression without return statement.
	 */
	private static function extract_return_expression( $expression ) {
		$expression = trim( $expression );

		// Remove "return" keyword if present
		if ( stripos( $expression, 'return' ) === 0 ) {
			$expression = trim( substr( $expression, 6 ) );
		}

		// Remove trailing semicolon if present
		$expression = rtrim( $expression, ';' );

		return trim( $expression );
	}

	/**
	 * Substitute variable placeholders with their actual values.
	 *
	 * @param string $expression The expression with variable placeholders.
	 * @param array  $variables  Associative array of variable names to values.
	 *
	 * @return string The expression with variables substituted.
	 */
	private static function substitute_variables( $expression, array $variables ) {
		foreach ( $variables as $name => $value ) {
			// Sanitize the value - only allow numeric values for security
			if ( is_numeric( $value ) ) {
				$safe_value = (string) floatval( $value );
			} elseif ( is_string( $value ) && $value !== '' ) {
				// For non-numeric strings, wrap in quotes for concatenation operations
				// But for arithmetic, we should convert or skip
				$safe_value = is_numeric( $value ) ? (string) floatval( $value ) : '0';
			} else {
				$safe_value = '0';
			}

			// Replace $variable with the value
			$expression = preg_replace(
				'/\$' . preg_quote( $name, '/' ) . '\b/',
				$safe_value,
				$expression
			);
		}

		return $expression;
	}

	/**
	 * Safely evaluate a mathematical expression.
	 *
	 * @param string $expression The expression to evaluate.
	 *
	 * @return mixed The result or original expression if invalid.
	 */
	private static function safe_evaluate( $expression ) {
		$expression = trim( $expression );

		// Handle allowed functions (round, ceil, floor, abs, min, max)
		$expression = self::process_allowed_functions( $expression );

		// If after function processing we still have function-like calls, reject
		if ( preg_match( '/[a-zA-Z_][a-zA-Z0-9_]*\s*\(/', $expression ) ) {
			// Check if it's not an allowed function
			$has_disallowed = false;
			preg_match_all( '/([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/', $expression, $matches );
			if ( ! empty( $matches[1] ) ) {
				foreach ( $matches[1] as $func_name ) {
					if ( ! in_array( strtolower( $func_name ), self::$allowed_functions, true ) ) {
						$has_disallowed = true;
						break;
					}
				}
			}
			if ( $has_disallowed ) {
				return $expression; // Return original if disallowed function found
			}
		}

		// Validate expression contains only allowed characters
		// Allow: numbers, operators, parentheses, whitespace, decimal points, commas (for function args)
		if ( ! self::is_safe_expression( $expression ) ) {
			return $expression;
		}

		// Simple case: just a number
		if ( is_numeric( $expression ) ) {
			return floatval( $expression );
		}

		// Evaluate the mathematical expression
		return self::evaluate_math( $expression );
	}

	/**
	 * Process allowed mathematical functions.
	 *
	 * @param string $expression The expression containing function calls.
	 *
	 * @return string The expression with functions evaluated.
	 */
	private static function process_allowed_functions( $expression ) {
		// Process from innermost to outermost
		$max_iterations = 10; // Prevent infinite loops
		$iteration      = 0;

		while ( $iteration < $max_iterations ) {
			$found = false;

			foreach ( self::$allowed_functions as $func ) {
				// Match function(args) pattern - find innermost first
				$pattern = '/' . $func . '\s*\(([^()]*)\)/i';

				if ( preg_match( $pattern, $expression, $matches ) ) {
					$found    = true;
					$args_str = $matches[1];

					// Evaluate arguments (may be comma-separated)
					$args = array_map( 'trim', explode( ',', $args_str ) );

					// Evaluate each argument
					$evaluated_args = array();
					foreach ( $args as $arg ) {
						if ( is_numeric( $arg ) ) {
							$evaluated_args[] = floatval( $arg );
						} else {
							// Try to evaluate the argument expression
							$arg_result = self::evaluate_math( $arg );
							if ( is_numeric( $arg_result ) ) {
								$evaluated_args[] = floatval( $arg_result );
							} else {
								// Can't evaluate, return original expression
								return $expression;
							}
						}
					}

					// Call the function
					$result = self::call_safe_function( $func, $evaluated_args );

					// Replace the function call with the result
					$expression = preg_replace( $pattern, (string) $result, $expression, 1 );
				}
			}

			if ( ! $found ) {
				break;
			}
			$iteration++;
		}

		return $expression;
	}

	/**
	 * Call a safe mathematical function.
	 *
	 * @param string $func_name The function name.
	 * @param array  $args      The function arguments.
	 *
	 * @return mixed The function result.
	 */
	private static function call_safe_function( $func_name, array $args ) {
		$func_name = strtolower( $func_name );

		switch ( $func_name ) {
			case 'round':
				$value     = isset( $args[0] ) ? $args[0] : 0;
				$precision = isset( $args[1] ) ? (int) $args[1] : 0;
				return round( $value, $precision );

			case 'ceil':
				return ceil( isset( $args[0] ) ? $args[0] : 0 );

			case 'floor':
				return floor( isset( $args[0] ) ? $args[0] : 0 );

			case 'abs':
				return abs( isset( $args[0] ) ? $args[0] : 0 );

			case 'min':
				return ! empty( $args ) ? min( $args ) : 0;

			case 'max':
				return ! empty( $args ) ? max( $args ) : 0;

			case 'number_format':
				$value         = isset( $args[0] ) ? $args[0] : 0;
				$decimals      = isset( $args[1] ) ? (int) $args[1] : 0;
				$dec_point     = isset( $args[2] ) ? (string) $args[2] : '.';
				$thousands_sep = isset( $args[3] ) ? (string) $args[3] : ',';
				return number_format( $value, $decimals, $dec_point, $thousands_sep );

			default:
				return 0;
		}
	}

	/**
	 * Check if an expression is safe to evaluate.
	 *
	 * @param string $expression The expression to check.
	 *
	 * @return bool True if safe, false otherwise.
	 */
	private static function is_safe_expression( $expression ) {
		// Remove allowed content and check what's left
		$cleaned = $expression;

		// Remove numbers (including decimals and negative)
		$cleaned = preg_replace( '/[\d.]+/', '', $cleaned );

		// Remove allowed operators and characters
		$cleaned = preg_replace( '/[\s+\-*\/%(),.]+/', '', $cleaned );

		// Remove allowed function names
		foreach ( self::$allowed_functions as $func ) {
			$cleaned = preg_replace( '/' . $func . '/i', '', $cleaned );
		}

		// If anything is left, it's not safe
		return empty( trim( $cleaned ) );
	}

	/**
	 * Evaluate a mathematical expression using a simple parser.
	 *
	 * @param string $expression The mathematical expression.
	 *
	 * @return mixed The result or the original expression if evaluation fails.
	 */
	private static function evaluate_math( $expression ) {
		$expression = trim( $expression );

		// Handle empty or non-string
		if ( empty( $expression ) || ! is_string( $expression ) ) {
			return 0;
		}

		// Simple number
		if ( is_numeric( $expression ) ) {
			return floatval( $expression );
		}

		// Validate expression is safe
		if ( ! self::is_safe_expression( $expression ) ) {
			return $expression;
		}

		try {
			// Use a simple expression parser
			$result = self::parse_expression( $expression );
			return $result;
		} catch ( \Exception $e ) {
			return $expression;
		}
	}

	/**
	 * Parse and evaluate a mathematical expression.
	 * Supports: +, -, *, /, %, parentheses
	 *
	 * @param string $expression The expression to parse.
	 *
	 * @return float The result.
	 */
	private static function parse_expression( $expression ) {
		$expression = trim( $expression );

		// Tokenize the expression
		$tokens = self::tokenize( $expression );

		if ( empty( $tokens ) ) {
			return 0;
		}

		// Convert to postfix notation (Shunting Yard algorithm)
		$postfix = self::to_postfix( $tokens );

		// Evaluate postfix expression
		return self::evaluate_postfix( $postfix );
	}

	/**
	 * Tokenize a mathematical expression.
	 *
	 * @param string $expression The expression.
	 *
	 * @return array Array of tokens.
	 */
	private static function tokenize( $expression ) {
		$tokens  = array();
		$number  = '';
		$length  = strlen( $expression );
		$i       = 0;

		while ( $i < $length ) {
			$char = $expression[ $i ];

			if ( is_numeric( $char ) || $char === '.' ) {
				$number .= $char;
			} elseif ( $char === '-' && ( empty( $tokens ) || end( $tokens ) === '(' || in_array( end( $tokens ), array( '+', '-', '*', '/', '%' ), true ) ) ) {
				// Unary minus
				$number .= $char;
			} else {
				if ( $number !== '' ) {
					$tokens[] = floatval( $number );
					$number   = '';
				}

				if ( in_array( $char, array( '+', '-', '*', '/', '%', '(', ')' ), true ) ) {
					$tokens[] = $char;
				} elseif ( ! ctype_space( $char ) ) {
					// Invalid character
					throw new \Exception( 'Invalid character in expression' );
				}
			}

			$i++;
		}

		if ( $number !== '' ) {
			$tokens[] = floatval( $number );
		}

		return $tokens;
	}

	/**
	 * Convert infix tokens to postfix notation (Shunting Yard algorithm).
	 *
	 * @param array $tokens The infix tokens.
	 *
	 * @return array The postfix tokens.
	 */
	private static function to_postfix( array $tokens ) {
		$output   = array();
		$operator_stack = array();

		$precedence = array(
			'+' => 1,
			'-' => 1,
			'*' => 2,
			'/' => 2,
			'%' => 2,
		);

		foreach ( $tokens as $token ) {
			if ( is_numeric( $token ) ) {
				$output[] = $token;
			} elseif ( $token === '(' ) {
				$operator_stack[] = $token;
			} elseif ( $token === ')' ) {
				while ( ! empty( $operator_stack ) && end( $operator_stack ) !== '(' ) {
					$output[] = array_pop( $operator_stack );
				}
				array_pop( $operator_stack ); // Remove '('
			} elseif ( isset( $precedence[ $token ] ) ) {
				while (
					! empty( $operator_stack ) &&
					end( $operator_stack ) !== '(' &&
					isset( $precedence[ end( $operator_stack ) ] ) &&
					$precedence[ end( $operator_stack ) ] >= $precedence[ $token ]
				) {
					$output[] = array_pop( $operator_stack );
				}
				$operator_stack[] = $token;
			}
		}

		while ( ! empty( $operator_stack ) ) {
			$output[] = array_pop( $operator_stack );
		}

		return $output;
	}

	/**
	 * Evaluate a postfix expression.
	 *
	 * @param array $postfix The postfix tokens.
	 *
	 * @return float The result.
	 */
	private static function evaluate_postfix( array $postfix ) {
		$stack = array();

		foreach ( $postfix as $token ) {
			if ( is_numeric( $token ) ) {
				$stack[] = floatval( $token );
			} else {
				if ( count( $stack ) < 2 ) {
					throw new \Exception( 'Invalid expression' );
				}

				$b = array_pop( $stack );
				$a = array_pop( $stack );

				switch ( $token ) {
					case '+':
						$stack[] = $a + $b;
						break;
					case '-':
						$stack[] = $a - $b;
						break;
					case '*':
						$stack[] = $a * $b;
						break;
					case '/':
						if ( $b == 0 ) {
							$stack[] = 0; // Avoid division by zero
						} else {
							$stack[] = $a / $b;
						}
						break;
					case '%':
						if ( $b == 0 ) {
							$stack[] = 0;
						} else {
							$stack[] = fmod( $a, $b );
						}
						break;
				}
			}
		}

		return ! empty( $stack ) ? array_pop( $stack ) : 0;
	}

	/**
	 * Evaluate a simple string concatenation or return expression.
	 * This handles cases where the template needs to return a processed value.
	 *
	 * @param string $expression The expression.
	 * @param array  $variables  Variables for substitution.
	 *
	 * @return string The result.
	 */
	public static function evaluate_string( $expression, array $variables = array() ) {
		// Remove backslashes
		$expression = preg_replace( '/\\\\/', '', $expression );

		// Extract return expression
		$expression = self::extract_return_expression( $expression );

		// If it's a quoted string, extract and return it
		if ( preg_match( '/^["\'](.*)["\']\s*;?\s*$/', $expression, $matches ) ) {
			return $matches[1];
		}

		// If it's a variable, substitute and return
		if ( preg_match( '/^\$([a-zA-Z_][a-zA-Z0-9_]*)\s*;?\s*$/', $expression, $matches ) ) {
			$var_name = $matches[1];
			return isset( $variables[ $var_name ] ) ? $variables[ $var_name ] : '';
		}

		// Try mathematical evaluation
		$result = self::evaluate( $expression, $variables );

		return is_numeric( $result ) ? (string) $result : $result;
	}
}
