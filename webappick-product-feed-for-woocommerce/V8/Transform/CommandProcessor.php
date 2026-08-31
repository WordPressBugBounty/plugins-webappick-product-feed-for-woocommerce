<?php
/**
 * CommandProcessor — shared executor for per-attribute output-formatting
 * commands (the Config-tab "command box" and Custom Template 2 formatters).
 *
 * V5 shipped `Output\OutputCommands::process_command()` which executed
 * bracketed command chains stored in the feedrules `limit` array, e.g.:
 *
 *   [strip_tags], [substr 0 80], […]
 *
 * V8 parsed these strings (Custom2Structure `formatter[]`, Config `limit`)
 * but never EXECUTED them — a silent parity gap. This class restores
 * execution as ONE shared engine serving both surfaces:
 *
 *   Surface 1 — Config-tab command box: `CommandTransform` (pipeline stage
 *   right after OutputTypeTransform) calls process() with the raw command
 *   STRING from `limit[index]`. The parent trio (only_parent / parent /
 *   parent_if_empty) is applied upstream in ProductRepository where product
 *   context exists (see ProductRepository::apply_parent_command()).
 *
 *   Surface 2 — Custom Template 2: Custom2Template calls process() with the
 *   parsed `['formatter' => [...]]` ARRAY and live product context, so the
 *   parent trio executes here via the injected AttributeResolver.
 *
 * Parity contract (XFRM-FRD-9.1): every legacy V5 command produces output
 * byte-identical to V5's executor, with exactly these documented deviations:
 *
 *   1. Command-word matching is case-insensitive and treats '-' and '_' as
 *      equivalent. V5 matched case-SENSITIVELY, so the documented casing
 *      `[urlToSecure]` / `[urlToUnsecure]` never matched V5's lowercase
 *      switch cases — a latent V5 bug. V8 normalizes with strtolower so all
 *      casings work. (Additive: V5 no-ops become working commands.)
 *
 *   2. The `[…]` / `[...]` fallback token. V5 documented "use the original
 *      value if the result is empty" but NEVER implemented it — the token
 *      fell through the executor's default: no-op (verified: no '…' handling
 *      anywhere in V5). V8 implements the documented semantics: when the
 *      chain contains the token and the final result is empty, the
 *      pre-command value is restored. (Additive: only fires when the chain
 *      emptied the value, which V5 would have shipped as an empty field.)
 *
 *   3. Robustness guards where V5 would raise a PHP 8 TypeError/fatal
 *      (e.g. `number_format` on a non-numeric value, `substr` with missing
 *      args): V8 returns the value unchanged instead of fataling. These
 *      paths produced no valid V5 output to preserve.
 *
 * Friendly aliases (XFRM-FRD-9.2) map onto the canonical legacy commands —
 * legacy names keep working forever. New Tier-1/2/3 commands
 * (XFRM-FRD-9.3) use kebab-case canonical names.
 *
 * Pro gating (XFRM-FRD-9.4): the whole executor is behind
 * `FeatureGate::has('output_commands')` — gate closed returns the value
 * untouched.
 *
 * @package    CTXFeed
 * @subpackage V8/Transform
 * @since      8.0.0
 * @implements XFRM-FRD-9.1, XFRM-FRD-9.2, XFRM-FRD-9.3, XFRM-FRD-9.4
 */

namespace CTXFeed\V8\Transform;

use CTXFeed\V8\Core\Config;
use CTXFeed\V8\Core\FeatureGate;
use CTXFeed\V8\Product\AttributeResolver;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Output-command executor shared by the Config tab and Custom Template 2.
 *
 * @since 8.0.0
 */
class CommandProcessor {

	/**
	 * FeatureGate key controlling command execution (Pro feature).
	 *
	 * @since 8.0.0
	 * @var string
	 */
	const FEATURE = 'output_commands';

	/**
	 * Friendly alias → canonical legacy command name.
	 *
	 * Keys are pre-normalized (lowercase, '-' folded to '_') — see
	 * normalize_key(). Legacy names are canonical and keep working forever;
	 * aliases are permanent additions. XFRM-FRD-9.2.
	 *
	 * @since 8.0.0
	 * @var array<string,string>
	 */
	private const ALIASES = array(
		'uppercase'         => 'strtoupper',
		'lowercase'         => 'strtolower',
		'capitalize'        => 'ucfirst',
		'capitalize_words'  => 'ucwords',
		'truncate'          => 'substr',
		'replace'           => 'str_replace',
		'pattern_replace'   => 'preg_replace',
		'format_number'     => 'number_format',
		'remove_html'       => 'strip_tags',
		'remove_shortcodes' => 'strip_shortcodes',
		'encode_entities'   => 'htmlentities',
		'encode_special'    => 'htmlspecialchars',
		'clean'             => 'clear',
		'https_links'       => 'urltosecure',
		'http_links'        => 'urltounsecure',
		'parent_only'       => 'only_parent',
		'parent_first'      => 'parent',
		// 'parent-if-empty' needs no entry — dash folding already lands on
		// the canonical 'parent_if_empty'. Same for 'wpml-price',
		// 'if-empty', 'truncate-words', etc.
	);

	/**
	 * Optional attribute resolver — required only for the parent trio
	 * (only_parent / parent / parent_if_empty) on the CT2 surface, where
	 * product context is passed to process().
	 *
	 * @since 8.0.0
	 * @var AttributeResolver|null
	 */
	private $resolver;

	/**
	 * Constructor.
	 *
	 * @since 8.0.0
	 *
	 * @param AttributeResolver|null $resolver Resolver used by the parent
	 *                                         trio to re-resolve the current
	 *                                         attribute against the parent
	 *                                         product. Optional — without it
	 *                                         parent commands are no-ops.
	 */
	public function __construct( ?AttributeResolver $resolver = null ) {
		$this->resolver = $resolver;
	}

	// ---------------------------------------------------------------------
	// Public API
	// ---------------------------------------------------------------------

	/**
	 * Execute a command chain against a value.
	 *
	 * Accepts both surfaces' shapes (XFRM-FRD-9.1):
	 *   - string  — Config-tab command box, e.g. '[strip_tags], [substr 0 80]'
	 *   - array   — Custom Template 2, shaped ['formatter' => ['strip_tags', …]]
	 *               (any extra element keys are ignored, V5 parity — V5
	 *               passed the whole $element array).
	 *
	 * The `[…]`/`[...]` token: V5 parsed it into the chain but its executor
	 * hit `default:` (no-op) — the documented "use original value if result
	 * is empty" fallback was never implemented anywhere in V5 (verified by
	 * grep). V8 implements the DOCUMENTED semantics: the pre-command value
	 * is captured, and when the chain contains the token and the final
	 * result is empty, the original is restored. This is an intentional
	 * improvement over V5's no-op; it only ADDS behavior (fires solely when
	 * the chain emptied the value). XFRM-FRD-9.1.
	 *
	 * @since 8.0.0
	 * @implements XFRM-FRD-9.1, XFRM-FRD-9.4
	 *
	 * @param mixed            $value     Current attribute value.
	 * @param string|array     $commands  Command chain (string or CT2 array).
	 * @param \WC_Product|null $product   Product context (CT2 surface) —
	 *                                    enables the parent trio.
	 * @param Config|null      $config    Feed configuration (parent trio).
	 * @param string           $attribute Attribute slug being processed
	 *                                    (parent trio re-resolution).
	 *
	 * @return mixed Transformed value (input returned untouched when the
	 *               `output_commands` gate is closed).
	 */
	public function process( $value, $commands, ?\WC_Product $product = null, ?Config $config = null, string $attribute = '' ) {
		// Pro gate — commands are a Pro feature. Closed gate: untouched.
		// XFRM-FRD-9.4.
		if ( ! FeatureGate::has( self::FEATURE ) ) {
			return $value;
		}

		$chain = self::parse_commands( $commands );
		if ( empty( $chain ) ) {
			return $value;
		}

		$original     = $value;
		$has_fallback = false;

		foreach ( $chain as $command ) {
			$command = trim( (string) $command );
			if ( '' === $command ) {
				continue;
			}

			$key = self::normalize_key( $command );

			// The […] / [...] fallback token — remembered, executed last.
			if ( '…' === $key || '...' === $key ) {
				$has_fallback = true;
				continue;
			}

			$value = $this->execute( $key, $command, $value, $product, $config, $attribute );
		}

		// Documented […] fallback semantics (improves on V5's no-op).
		if ( $has_fallback && self::is_empty_value( $value ) ) {
			return $original;
		}

		return $value;
	}

	/**
	 * Split a raw command chain into individual command tokens.
	 *
	 * String form mirrors V5 `OutputCommands::get_functions()`: split on
	 * ',' then take the text between '[' and ']' of each segment. (Literal
	 * commas inside a command are impossible — that's why the `comma`
	 * keyword exists; V5 parity.) Array form takes the CT2
	 * `['formatter' => […]]` list.
	 *
	 * @since 8.0.0
	 * @implements XFRM-FRD-9.1
	 *
	 * @param string|array $commands Raw chain.
	 * @return string[] Command tokens (may be empty).
	 */
	public static function parse_commands( $commands ): array {
		if ( is_array( $commands ) ) {
			$list = isset( $commands['formatter'] ) && is_array( $commands['formatter'] )
				? $commands['formatter']
				: array();

			return array_values(
				array_filter(
					$list,
					static function ( $c ) {
						return is_string( $c ) && '' !== $c;
					}
				)
			);
		}

		if ( ! is_string( $commands ) || '' === trim( $commands ) ) {
			return array();
		}

		$out = array();
		foreach ( explode( ',', $commands ) as $segment ) {
			if ( '' === $segment ) {
				continue;
			}
			$cmd = self::between( $segment, '[', ']' );
			if ( '' !== $cmd ) {
				$out[] = $cmd;
			}
		}

		return $out;
	}

	/**
	 * Scan a command chain for the parent trio and translate the FIRST hit
	 * into its V5 output_type code equivalent.
	 *
	 * Used by ProductRepository::apply_parent_command() so the Config-tab
	 * surface reuses the repository's existing parent re-resolution
	 * machinery (same path as output_type codes 18/19/20) rather than a
	 * second FormatOutput port. XFRM-FRD-9.5.
	 *
	 * @since 8.0.0
	 *
	 * @param string|array $commands Raw command chain.
	 * @return string '18' (only_parent), '19' (parent), '20'
	 *                (parent_if_empty), or '' when the chain has none.
	 */
	public static function parent_output_code( $commands ): string {
		foreach ( self::parse_commands( $commands ) as $command ) {
			switch ( self::normalize_key( trim( (string) $command ) ) ) {
				case 'only_parent':
					return '18';
				case 'parent':
					return '19';
				case 'parent_if_empty':
					return '20';
			}
		}

		return '';
	}

	// ---------------------------------------------------------------------
	// Command dispatch
	// ---------------------------------------------------------------------

	/**
	 * Normalize a command token to its canonical match key.
	 *
	 * Mirrors V5's two-step word extraction (`get_function()` splits on
	 * space, `get_function_command()` splits on '=>') then applies the V8
	 * normalization: lowercase + '-'→'_' folding + alias resolution. The
	 * lowercase step is the documented urlToSecure casing fix (V5 matched
	 * case-sensitively, so the documented `[urlToSecure]` casing never
	 * matched its lowercase switch). XFRM-FRD-9.2.
	 *
	 * @since 8.0.0
	 *
	 * @param string $command Full trimmed command token (word + args).
	 * @return string Canonical command key.
	 */
	private static function normalize_key( string $command ): string {
		$word = explode( ' ', $command, 2 )[0];
		$word = explode( '=>', $word, 2 )[0];

		// The fallback token is matched verbatim (no folding needed).
		if ( '…' === $word || '...' === $word ) {
			return $word;
		}

		$key = str_replace( '-', '_', strtolower( trim( $word ) ) );

		return self::ALIASES[ $key ] ?? $key;
	}

	/**
	 * Execute a single command against the value.
	 *
	 * Legacy cases are a faithful port of V5 `OutputCommands::process_command()`
	 * including its quirks (see per-case comments). Unknown commands are a
	 * no-op (V5 `default:` parity). XFRM-FRD-9.1, XFRM-FRD-9.3.
	 *
	 * @since 8.0.0
	 *
	 * @param string           $key       Canonical command key.
	 * @param string           $command   Full command token (word + args).
	 * @param mixed            $value     Current value.
	 * @param \WC_Product|null $product   Product context (parent trio).
	 * @param Config|null      $config    Feed configuration (parent trio).
	 * @param string           $attribute Attribute slug (parent trio).
	 *
	 * @return mixed Transformed value.
	 */
	private function execute( string $key, string $command, $value, ?\WC_Product $product, ?Config $config, string $attribute ) {
		// The parent trio operates on product context, not the scalar value.
		if ( 'only_parent' === $key || 'parent' === $key || 'parent_if_empty' === $key ) {
			return $this->apply_parent_command( $key, $value, $product, $config, $attribute );
		}

		// Non-scalar values (arrays/objects) can't run string commands —
		// V5 would fatal; we no-op (documented robustness guard).
		if ( ! is_scalar( $value ) && null !== $value ) {
			return $value;
		}

		$str = null === $value ? '' : (string) $value;

		switch ( $key ) {
			// -------------------------------------------------- legacy V5.
			case 'substr':
				// V5 quirk: strips ALL tags first, then substr; args split
				// on whitespace. Missing args → no-op guard (V5 fataled).
				$args = preg_split( '/\s+/', trim( $command ) );
				if ( ! isset( $args[1], $args[2] ) || ! is_numeric( $args[1] ) || ! is_numeric( $args[2] ) ) {
					return $value;
				}
				return substr( $this->strip_all_tags( $str ), (int) $args[1], (int) $args[2] );

			case 'strip_tags':
				return $this->strip_all_tags( $str );

			case 'htmlentities':
				return htmlentities( $str );

			case 'htmlspecialchars':
				// V5 documented this for CT2 but never implemented it —
				// its switch had no case. Implemented per the docs.
				return htmlspecialchars( $str );

			case 'clear':
				// V5 CommonHelper::strip_invalid_xml (byte-loop variant).
				return $this->strip_invalid_xml_v5( $value );

			case 'ucwords':
				// V5 quirk: lowercases FIRST, then ucwords.
				return ucwords( mb_strtolower( $str ) );

			case 'ucfirst':
				// V5 quirk: lowercases FIRST, then ucfirst.
				return ucfirst( mb_strtolower( $str ) );

			case 'strtoupper':
				return mb_strtoupper( $str );

			case 'strtolower':
				return mb_strtolower( $str );

			case 'strip_shortcodes':
				return $this->remove_shortcodes_v5( $str );

			case 'number_format':
				return $this->format_number( $str, $command );

			case 'urltounsecure':
				// V5's parser never lowercased the command word, so the
				// documented `[urlToUnsecure]` casing never matched in V5 —
				// latent V5 bug, fixed by normalize_key()'s strtolower.
				return ( 0 === strpos( $str, 'http' ) )
					? str_replace( 'https://', 'http://', $str )
					: $value;

			case 'urltosecure':
				// Same casing fix as urltounsecure above.
				return ( 0 === strpos( $str, 'http' ) )
					? str_replace( 'http://', 'https://', $str )
					: $value;

			case 'str_replace':
				return $this->replace_string( $str, $command, 'str_replace' );

			case 'preg_replace':
				return $this->replace_string( $str, $command, 'preg_replace' );

			// ------------------------------------------- new (Tier-1/2).
			case 'if_empty':
				// [if-empty => text] — output text when value is empty.
				if ( '' !== trim( $str ) ) {
					return $value;
				}
				$args = explode( '=>', $command, 2 );
				return isset( $args[1] ) ? trim( $args[1] ) : $value;

			case 'multiply':
			case 'add':
				$args = preg_split( '/\s+/', trim( $command ) );
				if ( ! isset( $args[1] ) || ! is_numeric( $args[1] ) || ! is_numeric( $str ) ) {
					return $value;
				}
				$n = (float) $args[1];
				return (string) ( 'multiply' === $key ? (float) $str * $n : (float) $str + $n );

			case 'round':
				if ( ! is_numeric( $str ) ) {
					return $value;
				}
				$args      = preg_split( '/\s+/', trim( $command ) );
				$precision = isset( $args[1] ) && is_numeric( $args[1] ) ? (int) $args[1] : 0;
				return (string) round( (float) $str, $precision );

			case 'sentence_case':
				$lower = mb_strtolower( $str );
				return mb_strtoupper( mb_substr( $lower, 0, 1 ) ) . mb_substr( $lower, 1 );

			case 'truncate_words':
				$args = preg_split( '/\s+/', trim( $command ) );
				if ( ! isset( $args[1] ) || ! is_numeric( $args[1] ) || (int) $args[1] < 1 ) {
					return $value;
				}
				// Strips tags first, like substr does.
				$stripped = trim( $this->strip_all_tags( $str ) );
				if ( '' === $stripped ) {
					return $stripped;
				}
				$words = preg_split( '/\s+/', $stripped );
				return implode( ' ', array_slice( $words, 0, (int) $args[1] ) );

			case 'remove_emojis':
				$result = preg_replace(
					'/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}\x{FE0F}\x{200D}\x{1F1E6}-\x{1F1FF}]/u',
					'',
					$str
				);
				return null === $result ? $value : $result;

			case 'trim':
				return trim( $str );

			case 'collapse_spaces':
				return trim( (string) preg_replace( '/\s+/', ' ', $str ) );

			case 'remove_newlines':
				$flat = (string) preg_replace( '/\r\n|\r|\n/', ' ', $str );
				return (string) preg_replace( '/ {2,}/', ' ', $flat );

			case 'remove_page_builder':
				// "Remove page-builder / block markup" — cleans the markup; it
				// does NOT try to extract the description. Renders + strips
				// shortcodes (Divi / WPBakery), drops Gutenberg block-delimiter
				// (and any HTML) comments, strips the remaining tags (entities
				// decoded first), then collapses whitespace to single spaces.
				$pb = $this->remove_shortcodes_v5( $str );
				$pb = (string) preg_replace( '/<!--.*?-->/s', '', $pb );
				$pb = $this->strip_all_tags( html_entity_decode( $pb, ENT_QUOTES ) );
				return trim( (string) preg_replace( '/\s+/u', ' ', $pb ) );

			case 'cdata':
				// Mirrors OutputTypeTransform case '9' (without the XML
				// feed-type gate — the command is an explicit request).
				if ( '' === $str ) {
					return $value;
				}
				$clean = str_replace( array( '<![CDATA[', ']]>' ), '', html_entity_decode( $str ) );
				return '<![CDATA[' . $clean . ']]>';

			case 'strip_invalid_xml':
				// Mirrors OutputTypeTransform case '10' (hardened variant:
				// XML 1.0 pass + invisible-char pass + filter). Distinct
				// from legacy `clear`, which keeps V5's byte-loop parity.
				return $this->strip_invalid_xml_hardened( $str );

			case 'integer':
				// Mirrors OutputTypeTransform case '5'.
				return (string) (int) $str;

			case 'decode_entities':
				// Mirrors OutputTypeTransform case '22'.
				return htmlspecialchars_decode( $str, ENT_XML1 | ENT_QUOTES );

			// ---------------------------------------------- new (Tier-3).
			case 'date_format':
				return $this->format_date( $str, $command, $value );

			case 'wpml_price':
				/**
				 * Filter a price value for WPML multi-currency conversion.
				 *
				 * V5 documented a `wpml_price` command but had NO executor
				 * case for it (verified — no implementation anywhere in
				 * V5), so this is a passthrough hook: WPML/WCML compat
				 * layers convert the amount by hooking this filter (e.g.
				 * delegating to `wcml_raw_price_amount`).
				 *
				 * @since 8.0.0
				 *
				 * @param mixed $value Current price value.
				 */
				return apply_filters( 'ctxfeed_wpml_price', $value );

			// V5 `default:` parity — unknown commands are a no-op.
			default:
				return $value;
		}
	}

	// ---------------------------------------------------------------------
	// Parent trio (CT2 surface)
	// ---------------------------------------------------------------------

	/**
	 * Apply only_parent / parent / parent_if_empty using the injected
	 * AttributeResolver (Custom Template 2 surface).
	 *
	 * Semantics mirror ProductRepository::resolve_against_parent() (codes
	 * 18/19/20) rather than V5 FormatOutput's fresh full re-resolution —
	 * the repository machinery is V8's single source of truth for parent
	 * fallback behavior. Non-variation products are a no-op (V5 parity).
	 * On the Config-tab surface these commands are applied upstream by
	 * ProductRepository::apply_parent_command(); process() is then called
	 * without product context so this path no-ops there. XFRM-FRD-9.5.
	 *
	 * @since 8.0.0
	 *
	 * @param string           $key       'only_parent' | 'parent' | 'parent_if_empty'.
	 * @param mixed            $value     Current value.
	 * @param \WC_Product|null $product   Product context.
	 * @param Config|null      $config    Feed configuration.
	 * @param string           $attribute Attribute slug to re-resolve.
	 *
	 * @return mixed
	 */
	private function apply_parent_command( string $key, $value, ?\WC_Product $product, ?Config $config, string $attribute ) {
		if ( null === $product || null === $config || null === $this->resolver || '' === $attribute ) {
			return $value;
		}

		if ( ! $product->is_type( 'variation' ) ) {
			return $value;
		}

		// parent_if_empty (code-20 semantics): keep a populated current value.
		if ( 'parent_if_empty' === $key && '' !== $value && null !== $value && array() !== $value ) {
			return $value;
		}

		$parent_id = $product->get_parent_id();
		if ( ! $parent_id ) {
			return $value;
		}

		$parent = wc_get_product( $parent_id );
		if ( ! $parent instanceof \WC_Product ) {
			return $value;
		}

		$parent_value = $this->resolver->resolve(
			$parent,
			array(
				'type'    => 'attribute',
				'wc_attr' => $attribute,
			),
			$config
		);

		// only_parent (code-18 semantics): always the parent value.
		if ( 'only_parent' === $key ) {
			return $parent_value;
		}

		// parent (code-19 semantics): parent first, current as fallback.
		if ( 'parent' === $key ) {
			return ( '' === $parent_value || null === $parent_value ) ? $value : $parent_value;
		}

		// parent_if_empty: current was empty (checked above) → parent value.
		return $parent_value;
	}

	// ---------------------------------------------------------------------
	// Legacy helpers (V5-verbatim ports)
	// ---------------------------------------------------------------------

	/**
	 * The str_replace / preg_replace command — argument parsing + execution.
	 *
	 * Faithful port of V5 `OutputCommands::replace_string()`:
	 *   - the command splits on '=>' with limit 3;
	 *   - both args are trimmed;
	 *   - QUIRK: when an arg contains the literal word `comma`, the
	 *     UNTRIMMED raw arg has 'comma' replaced by ',' — preserving any
	 *     surrounding spaces (so `str_replace=> comma => ;` searches for
	 *     ' , ' with spaces). Replicated exactly;
	 *   - preg_replace wraps both args in wp_unslash.
	 *
	 * @since 8.0.0
	 * @implements XFRM-FRD-9.1
	 *
	 * @param string $output  Current value.
	 * @param string $command Full command token.
	 * @param string $type    'str_replace' or 'preg_replace'.
	 *
	 * @return mixed Replaced value (preg_replace may return null on a bad
	 *               pattern — V5 shipped that result; preserved).
	 */
	private function replace_string( string $output, string $command, string $type ) {
		$args = explode( '=>', $command, 3 );
		if ( ! array_key_exists( 1, $args ) || ! array_key_exists( 2, $args ) ) {
			return $output;
		}

		list( $argument1, $argument2 ) = array_map( 'trim', array( $args[1], $args[2] ) );

		// V5 quirk: comma-keyword substitution operates on the UNTRIMMED arg.
		if ( false !== strpos( $args[1], 'comma' ) ) {
			$argument1 = str_replace( 'comma', ',', $args[1] );
		}

		if ( false !== strpos( $args[2], 'comma' ) ) {
			$argument2 = str_replace( 'comma', ',', $args[2] );
		}

		if ( 'str_replace' === $type ) {
			return str_replace( (string) $argument1, (string) $argument2, $output );
		}

		return preg_replace( wp_unslash( $argument1 ), wp_unslash( $argument2 ), $output );
	}

	/**
	 * The number_format command — V5 `OutputCommands::format_number()` port.
	 *
	 * Args split on space (limit 4). Separator args are the KEYWORDS
	 * point/comma/space mapped to '.', ',', ' '. V5's if/elseif chain
	 * semantics preserved exactly:
	 *   - an unknown decimal-separator keyword leaves that slot UNSET, so
	 *     the call degrades to the 2-arg `number_format($v, $decimals)`;
	 *   - the thousands-separator slot is ALWAYS set ('' when the keyword
	 *     is absent/unknown), so any valid decimal keyword produces the
	 *     4-arg call with '' thousands separator.
	 * Skips entirely when the value is empty (V5 `! empty()` guard — note
	 * '0' is empty() and passes through). Non-numeric values return
	 * unchanged (V5 fataled under PHP 8 — documented robustness guard).
	 *
	 * @since 8.0.0
	 * @implements XFRM-FRD-9.1
	 *
	 * @param string $output  Current value.
	 * @param string $command Full command token.
	 * @return string
	 */
	private function format_number( string $output, string $command ): string {
		if ( empty( $output ) || ! is_numeric( $output ) ) {
			return $output;
		}

		$args      = explode( ' ', $command, 4 );
		$arguments = array( 0 => '' );

		if ( isset( $args[1] ) ) {
			$arguments[1] = $args[1];
		}

		if ( isset( $args[2] ) && 'point' === $args[2] ) {
			$arguments[2] = '.';
		} elseif ( isset( $args[2] ) && 'comma' === $args[2] ) {
			$arguments[2] = ',';
		} elseif ( isset( $args[2] ) && 'space' === $args[2] ) {
			$arguments[2] = ' ';
		}

		if ( isset( $args[3] ) && 'point' === $args[3] ) {
			$arguments[3] = '.';
		} elseif ( isset( $args[3] ) && 'comma' === $args[3] ) {
			$arguments[3] = ',';
		} elseif ( isset( $args[3] ) && 'space' === $args[3] ) {
			$arguments[3] = ' ';
		} else {
			$arguments[3] = '';
		}

		if ( isset( $arguments[1], $arguments[2], $arguments[3] ) ) {
			return number_format( (float) $output, (int) $arguments[1], $arguments[2], $arguments[3] );
		}

		if ( isset( $arguments[1] ) ) {
			return number_format( (float) $output, (int) $arguments[1] );
		}

		return number_format( (float) $output );
	}

	/**
	 * The date-format command — reformat a date value via a PHP date format.
	 *
	 * Syntax: `[date-format => F jcomma Y]`. The format is everything
	 * after the first '=>' (PHP date formats contain spaces, so the
	 * whitespace-splitting the numeric commands use is unusable), and the
	 * literal word `comma` becomes ',' — a real comma is impossible
	 * inside a command because the chain splits on commas first (same
	 * wire trick as str_replace's keyword).
	 *
	 * Wall-clock values (WC dates resolve to local strings with no
	 * offset) parse AND format as UTC — a pure reformat with no timezone
	 * shifting, independent of the PHP default timezone; values carrying
	 * an explicit offset keep it. A pure 10-digit value that the date
	 * parser rejects is treated as a Unix timestamp (sale-price date
	 * meta ships those). Unparseable values and missing/empty formats
	 * return the value unchanged (robustness guard).
	 *
	 * @since 8.0.0
	 * @implements XFRM-FRD-9.3
	 *
	 * @param string $str     Current value as string.
	 * @param string $command Full command token.
	 * @param mixed  $value   Original value (returned on no-op).
	 * @return mixed
	 */
	private function format_date( string $str, string $command, $value ) {
		$args = explode( '=>', $command, 2 );
		if ( ! isset( $args[1] ) ) {
			return $value;
		}

		$format = trim( str_replace( 'comma', ',', $args[1] ) );
		$input  = trim( $str );
		if ( '' === $format || '' === $input ) {
			return $value;
		}

		try {
			$date = new \DateTime( $input, new \DateTimeZone( 'UTC' ) );
		} catch ( \Exception $e ) {
			if ( ! preg_match( '/^\d{10}$/', $input ) ) {
				return $value;
			}
			$date = new \DateTime( '@' . $input );
		}

		return $date->format( $format );
	}

	/**
	 * Strip all HTML tags — V5 `CommonHelper::strip_all_tags()` port
	 * (WP_Error → '', otherwise wp_strip_all_tags).
	 *
	 * @since 8.0.0
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function strip_all_tags( $value ): string {
		if ( class_exists( '\WP_Error' ) && $value instanceof \WP_Error ) {
			return '';
		}

		$value = (string) $value;

		return function_exists( 'wp_strip_all_tags' )
			? (string) wp_strip_all_tags( $value )
			: trim( strip_tags( $value ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags, WordPressVIPMinimum.Functions.StripTags.StripTagsOneParameter -- Non-WP fallback (unit tests); V5-parity strip behavior must not change.
	}

	/**
	 * Invalid-XML strip — V5 `CommonHelper::strip_invalid_xml()` VERBATIM
	 * port (byte loop) for the legacy `clear` command.
	 *
	 * V5 quirks preserved on purpose:
	 *   - `empty($value)` guard returns '' — including for the string '0';
	 *   - int/float inputs pass through untouched;
	 *   - the loop is per-BYTE (ord/chr), so only ASCII control bytes
	 *     0x00-0x08, 0x0B, 0x0C, 0x0E-0x1F are removed — multibyte
	 *     sequences are never touched (every byte ≥ 0x80 passes the
	 *     0x20–0xD7FF branch).
	 *
	 * The hardened V8 variant lives in strip_invalid_xml_hardened() for the
	 * NEW `strip-invalid-xml` command; the two are intentionally distinct
	 * so legacy `clear` output stays byte-identical to V5.
	 *
	 * @since 8.0.0
	 * @implements XFRM-FRD-9.1
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	private function strip_invalid_xml_v5( $value ) {
		$ret = '';

		if ( empty( $value ) ) {
			return $ret;
		}

		if ( is_int( $value ) || is_float( $value ) ) {
			return $value;
		}

		$value  = (string) $value;
		$length = strlen( $value );

		for ( $i = 0; $i < $length; $i++ ) {
			$current = ord( $value[ $i ] );

			if (
				( 0x9 === $current )
				|| ( 0xA === $current )
				|| ( 0xD === $current )
				|| ( $current >= 0x20 && $current <= 0xD7FF )
				|| ( $current >= 0xE000 && $current <= 0xFFFD )
				|| ( $current >= 0x10000 && $current <= 0x10FFFF )
			) {
				$ret .= chr( $current );
			}
		}

		return $ret;
	}

	/**
	 * Shortcode removal — V5 `CommonHelper::remove_shortcodes()` port for
	 * the legacy `strip_shortcodes` command:
	 *   1. do_shortcode() — expand registered shortcodes;
	 *   2. strip_shortcodes() — strip registered leftovers;
	 *   3. V5 byte-loop invalid-XML strip;
	 *   4. catch-all regex (V5-verbatim) for ANY remaining [tag] pattern.
	 *
	 * @since 8.0.0
	 * @implements XFRM-FRD-9.1
	 *
	 * @param string $content Value.
	 * @return string
	 */
	private function remove_shortcodes_v5( string $content ): string {
		if ( '' === $content ) {
			return '';
		}

		if ( function_exists( 'do_shortcode' ) ) {
			$content = (string) do_shortcode( $content );
		}

		if ( function_exists( 'strip_shortcodes' ) ) {
			$content = (string) strip_shortcodes( $content );
		}

		$content = (string) $this->strip_invalid_xml_v5( $content );

		// Catch-all regex — V5-verbatim (V5/Helper/CommonHelper.php:99).
		$expression = '/\[\/*[č?a-zA-Z1-90_| -=\'"\{\}]*\/*\]/m';
		$result     = preg_replace( $expression, '', $content );

		return null === $result ? $content : $result;
	}

	/**
	 * Hardened invalid-XML strip for the NEW `strip-invalid-xml` command —
	 * mirrors OutputTypeTransform case '10' (XML 1.0 disallowed code
	 * points pass + invisible feed-breaking characters pass + the
	 * `ctxfeed_strip_invalid_xml` filter). Kept in sync with
	 * OutputTypeTransform::strip_invalid_xml(); duplicated rather than
	 * extracted so OutputTypeTransform stays untouched.
	 *
	 * @since 8.0.0
	 * @implements XFRM-FRD-9.3
	 *
	 * @param string $value Value.
	 * @return string
	 */
	private function strip_invalid_xml_hardened( string $value ): string {
		if ( '' === $value ) {
			return '';
		}

		// Pass 1: XML 1.0 hard requirement.
		$step1 = preg_replace(
			'/[^\x09\x0A\x0D\x20-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u',
			'',
			$value
		);
		if ( null !== $step1 ) {
			$value = $step1;
		}

		// Pass 2: invisible / feed-breaking but XML-valid code points.
		$step2 = preg_replace(
			'/[\x{007F}\x{0080}-\x{009F}\x{00AD}\x{061C}\x{180E}\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2064}\x{2066}-\x{206F}\x{FEFF}\x{FFF9}-\x{FFFB}]/u',
			'',
			$value
		);
		if ( null !== $step2 ) {
			$value = $step2;
		}

		/** This filter is documented in V8/Transform/OutputTypeTransform.php */
		return apply_filters( 'ctxfeed_strip_invalid_xml', $value );
	}

	// ---------------------------------------------------------------------
	// Small utilities
	// ---------------------------------------------------------------------

	/**
	 * Whether a chain result counts as "empty" for the […] fallback.
	 *
	 * A null, '' or whitespace-only string is empty; '0' is NOT (a real
	 * numeric result must survive).
	 *
	 * @since 8.0.0
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	private static function is_empty_value( $value ): bool {
		if ( null === $value ) {
			return true;
		}

		if ( is_string( $value ) ) {
			return '' === trim( $value );
		}

		return false;
	}

	/**
	 * Substring between two delimiters — V5 `FeedHelper::get_string_between()`
	 * shape (same helper Custom2Structure uses).
	 *
	 * @since 8.0.0
	 *
	 * @param string $haystack Source string.
	 * @param string $start    Opening delimiter.
	 * @param string $end      Closing delimiter.
	 * @return string Substring, or '' when either delimiter is absent.
	 */
	private static function between( string $haystack, string $start, string $end ): string {
		$ini = strpos( $haystack, $start );
		if ( false === $ini ) {
			return '';
		}
		$ini += strlen( $start );
		$len  = strpos( $haystack, $end, $ini );
		if ( false === $len ) {
			return '';
		}

		return substr( $haystack, $ini, $len - $ini );
	}
}
