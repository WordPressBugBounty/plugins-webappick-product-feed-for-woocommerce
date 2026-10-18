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
