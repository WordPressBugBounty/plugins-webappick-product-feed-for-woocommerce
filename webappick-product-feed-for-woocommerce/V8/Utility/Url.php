<?php
/**
 * Url — locale-independent URL splitting (CBT-808).
 *
 * PHP's parse_url() (and so wp_parse_url()) replaces every byte that
 * iscntrl() calls a control character with '_'. iscntrl() follows LC_CTYPE:
 * once another plugin or the host sets a non-C locale, the bytes 0x80-0x9F
 * count as C1 control characters — and the second byte of most accented
 * letters falls in that range (ł = C5 82, ą = C4 85, ś = C5 9B). A Polish
 * image file "anioł.jpg" then left the feed as "anio%C5_.jpg" and Google
 * Merchant Center rejected the product (#69627).
 *
 * Url::parse() splits with the RFC 3986 appendix B pattern instead: plain
 * byte classes, no locale, every byte kept exactly as given.
 *
 * Url::with_query_args() sets and removes query parameters without touching
 * any other byte (CBT-818). WordPress's add_query_arg()/remove_query_arg()
 * decode every existing KEY one level per call (wp_parse_str), so the five
 * UTM passes turned WooCommerce's own variation key
 * `attribute_%25d1%2580…` into raw UTF-8 `attribute_р…`. Url::is_http_url()
 * replaces FILTER_VALIDATE_URL, which rejects every byte ≥ 0x80 and an `_`
 * in the host, so stores with a non-Latin product base or an IDN host lost
 * their UTMs.
 *
 * @package    CTXFeed
 * @subpackage V8/Utility
 * @since      8.0.38
 */

namespace CTXFeed\V8\Utility;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Url
 *
 * @since 8.0.38
 */
class Url {

	/**
	 * Component constants (PHP_URL_*) mapped to parse()'s array keys.
	 *
	 * @var array<int, string>
	 */
	private const COMPONENTS = array(
		PHP_URL_SCHEME   => 'scheme',
		PHP_URL_HOST     => 'host',
		PHP_URL_PORT     => 'port',
		PHP_URL_USER     => 'user',
		PHP_URL_PASS     => 'pass',
		PHP_URL_PATH     => 'path',
		PHP_URL_QUERY    => 'query',
		PHP_URL_FRAGMENT => 'fragment',
	);

	/**
	 * Split a URL into the same parts parse_url() returns, without touching
	 * any byte.
	 *
	 * Keys (scheme, user, pass, host, port, path, query, fragment) are set
	 * only when the URL has that part; port is an int. With $component set
	 * to a PHP_URL_* constant, returns that part or null when absent.
	 *
	 * @since 8.0.38
	 *
	 * @param string $url       URL.
	 * @param int    $component Optional PHP_URL_* constant; -1 for all parts.
	 * @return array<string, string|int>|string|int|null|false Parts, one part, or false for a malformed URL.
	 */
	public static function parse( string $url, int $component = -1 ) {
		// RFC 3986 appendix B. Explicit byte classes only — no \w, no /u,
		// no case folding — so neither LC_CTYPE nor invalid UTF-8 matter.
		if ( 1 !== preg_match( '~^(?:([A-Za-z][A-Za-z0-9+.\-]*):)?(//([^/?#]*))?([^?#]*)(\?([^#]*))?(#(.*))?$~s', $url, $m ) ) {
			return false;
		}

		$parts = array();
		if ( isset( $m[1] ) && '' !== $m[1] ) {
			$parts['scheme'] = $m[1];
		}

		if ( isset( $m[2] ) && '' !== $m[2] ) {
			$authority = self::split_authority( $m[3] );
			if ( false === $authority ) {
				return false;
			}
			$parts += $authority;
		}

		if ( isset( $m[4] ) && '' !== $m[4] ) {
			$parts['path'] = $m[4];
		}
		if ( isset( $m[5] ) && '' !== $m[5] ) {
			$parts['query'] = $m[6];
		}
		if ( isset( $m[7] ) && '' !== $m[7] ) {
			$parts['fragment'] = $m[8];
		}

		if ( -1 === $component ) {
			return $parts;
		}

		$key = self::COMPONENTS[ $component ] ?? null;

		return null !== $key && isset( $parts[ $key ] ) ? $parts[ $key ] : null;
	}

	/**
	 * Set and remove query parameters, keeping every other byte of the URL.
	 *
	 * The fragment is split off first, then the query. Each existing pair
	 * whose key — decoded with `+` as a space, then rawurldecode() — is in
	 * $set is replaced IN PLACE (the original key bytes are kept; later
	 * duplicates are dropped); a pair whose decoded key is in $remove_keys
	 * is dropped; every other pair is kept verbatim. Keys of $set that were
	 * not in the URL are appended in order as `rawurlencode( key )=value`.
	 * Values are encoded exactly once: rawurlencode() with `%20` turned back
	 * into `+` (the UTM encoding since BUG-0036, V5's space → `+`). Values
	 * must therefore be passed RAW, never pre-encoded. A non-scalar value is
	 * ignored. When nothing changes the URL is returned as given; when the
	 * last pair is removed the `?` goes too.
	 *
	 * Unlike add_query_arg() it never decodes an existing key or value, never
	 * reads $_SERVER['REQUEST_URI'] and does not depend on the locale.
	 *
	 * @since 8.0.39
	 *
	 * @param string               $url         URL (absolute or relative).
	 * @param array<string, mixed> $set         Parameters to set: raw key => raw value.
	 * @param string[]             $remove_keys Raw keys to drop (keys in $set are set, not dropped).
	 * @return string
	 */
	public static function with_query_args( string $url, array $set, array $remove_keys = array() ): string {
		$values = array();
		foreach ( $set as $key => $value ) {
			if ( is_scalar( $value ) ) {
				$values[ (string) $key ] = str_replace( '%20', '+', rawurlencode( (string) $value ) );
			}
		}

		$remove = array();
		foreach ( $remove_keys as $key ) {
			if ( is_scalar( $key ) && ! isset( $values[ (string) $key ] ) ) {
				$remove[ (string) $key ] = true;
			}
		}

		if ( array() === $values && array() === $remove ) {
			return $url;
		}

		$fragment = '';
		$hash     = strpos( $url, '#' );
		if ( false !== $hash ) {
			$fragment = (string) substr( $url, $hash );
			$url      = (string) substr( $url, 0, $hash );
		}

		$base  = $url;
		$query = null;
		$mark  = strpos( $url, '?' );
		if ( false !== $mark ) {
			$base  = (string) substr( $url, 0, $mark );
			$query = (string) substr( $url, $mark + 1 );
		}

		$pairs   = null === $query || '' === $query ? array() : explode( '&', $query );
		$kept    = array();
		$done    = array();
		$changed = false;
		foreach ( $pairs as $pair ) {
			if ( '' === $pair ) {
				$kept[] = $pair;
				continue;
			}

			$eq      = strpos( $pair, '=' );
			$raw_key = false === $eq ? $pair : (string) substr( $pair, 0, $eq );
			$key     = rawurldecode( str_replace( '+', ' ', $raw_key ) );

			if ( isset( $values[ $key ] ) ) {
				if ( ! isset( $done[ $key ] ) ) {
					$done[ $key ] = true;
					$kept[]       = $raw_key . '=' . $values[ $key ];
				}
				$changed = true;
				continue;
			}

			if ( isset( $remove[ $key ] ) ) {
				$changed = true;
				continue;
			}

			$kept[] = $pair;
		}

		$append = array();
		foreach ( $values as $key => $value ) {
			if ( ! isset( $done[ $key ] ) ) {
				$append[] = rawurlencode( $key ) . '=' . $value;
			}
		}

		if ( ! $changed && array() === $append ) {
			return $url . $fragment;
		}

		// A trailing '&' must not leave an empty pair in front of the
		// appended ones, nor dangle after a removed one.
		while ( array() !== $kept && '' === end( $kept ) ) {
			array_pop( $kept );
		}

		$query = implode( '&', array_merge( $kept, $append ) );

		return $base . ( '' === $query ? '' : '?' . $query ) . $fragment;
	}

	/**
	 * Whether a value is an absolute http(s) URL with a host.
	 *
	 * Byte-safe replacement for filter_var( FILTER_VALIDATE_URL ), which
	 * rejects raw UTF-8 paths (`/товар/`), IDN hosts and an `_` in a host.
	 * Rejected: non-strings, any byte ≤ 0x20 or 0x7F (spaces, control
	 * characters), a URL Url::parse() cannot split, a scheme other than
	 * http/https (any case), and an empty host.
	 *
	 * @since 8.0.39
	 *
	 * @param mixed $value Candidate URL.
	 * @return bool
	 */
	public static function is_http_url( $value ): bool {
		if ( ! is_string( $value ) || '' === $value || 1 === preg_match( '/[\x00-\x20\x7F]/', $value ) ) {
			return false;
		}

		$parts = self::parse( $value );
		if ( ! is_array( $parts ) || ! isset( $parts['scheme'], $parts['host'] ) || '' === $parts['host'] ) {
			return false;
		}

		$scheme = strtr( (string) $parts['scheme'], 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz' );

		return 'http' === $scheme || 'https' === $scheme;
	}

	/**
	 * Split "user:pass@host:port" (host may be an [IPv6] literal).
	 *
	 * @since 8.0.38
	 *
	 * @param string $authority Authority part, without the leading "//".
	 * @return array<string, string|int>|false Parts, or false for a non-numeric port.
	 */
	private static function split_authority( string $authority ) {
		$parts = array();

		$at = strrpos( $authority, '@' );
		if ( false !== $at ) {
			$userinfo  = (string) substr( $authority, 0, $at );
			$authority = (string) substr( $authority, $at + 1 );

			$colon = strpos( $userinfo, ':' );
			if ( false === $colon ) {
				$parts['user'] = $userinfo;
			} else {
				$parts['user'] = (string) substr( $userinfo, 0, $colon );
				$parts['pass'] = (string) substr( $userinfo, $colon + 1 );
			}
		}

		$host        = $authority;
		$port_number = null;
		$colon       = strrpos( $authority, ':' );
		// A colon inside "[...]" belongs to an IPv6 literal, not a port.
		if ( false !== $colon && false === strpos( (string) substr( $authority, $colon ), ']' ) ) {
			$host = (string) substr( $authority, 0, $colon );
			$port = (string) substr( $authority, $colon + 1 );
			if ( '' !== $port ) {
				if ( 1 !== preg_match( '/^[0-9]{1,5}$/', $port ) || (int) $port > 65535 ) {
					return false;
				}
				$port_number = (int) $port;
			}
		}

		// parse_url()'s key order: host, port, user, pass.
		$ordered = array();
		if ( '' !== $host ) {
			$ordered['host'] = $host;
		}
		if ( null !== $port_number ) {
			$ordered['port'] = $port_number;
		}

		return $ordered + $parts;
	}
}
