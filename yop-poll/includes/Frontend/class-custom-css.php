<?php
/**
 * A poll's own CSS, held to that poll.
 *
 * @package YopPoll
 */

namespace YopPoll\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A poll's own CSS, held to that poll.
 *
 * The author writes selectors against the poll's real classes, and they keep
 * working as written. Each selector gains one condition on the element it
 * styles -- that the element is this poll's container or inside it:
 *
 *     .a .b   ->   .a .b:where([data-yop-poll-id="7"], [data-yop-poll-id="7"] *)
 *
 * The rest of the selector still matches wherever it did, so
 * `.entry-content .yop-poll-answer` reaches its answer and `.yop-poll` the
 * container itself, while `body`, the theme, and every other poll on the page
 * are out of reach. `:where()` adds no specificity, so an override that won
 * before still wins. Every placement of the poll carries the same id, so one
 * stylesheet serves them all.
 *
 * Rules inside `@media`, `@supports`, `@container` and `@layer` are held the
 * same way. `@font-face`, `@keyframes` and the other descriptor blocks hold no
 * selectors and are kept as written. `@import`, `@charset`, `@namespace` and
 * any other at-rule are dropped, and so is a rule left open at the end.
 */
class Custom_Css {

	/** At-rules whose body is more rules, held the same way. */
	private const NESTING = array( 'media', 'supports', 'container', 'layer' );

	/** At-rules whose body is descriptors, not selectors: kept as written. */
	private const VERBATIM = array( 'keyframes', 'font-face', 'property', 'counter-style', 'font-feature-values', 'font-palette-values', 'page' );

	/**
	 * Hold every rule in `$css` to one poll.
	 *
	 * @param string $css     The author's CSS, tags already stripped.
	 * @param int    $poll_id The poll whose containers it may style.
	 * @return string
	 */
	public static function scope( string $css, int $poll_id ): string {
		$in = sprintf( ':where([data-yop-poll-id="%1$d"], [data-yop-poll-id="%1$d"] *)', $poll_id );

		return self::rules( self::without_comments( $css ), $in );
	}

	/**
	 * A run of rules, each held to the poll, one per line.
	 *
	 * @param string $css The rules.
	 * @param string $in  The condition every selector gains.
	 * @return string
	 */
	private static function rules( string $css, string $in ): string {
		$out    = array();
		$length = strlen( $css );
		$i      = 0;

		while ( $i < $length ) {
			$start = $i;
			$i     = self::scan_to( $css, $i, array( '{', ';' ) );

			if ( $i >= $length ) {
				break; // Trailing text with no rule: nothing to keep.
			}

			$prelude = trim( substr( $css, $start, $i - $start ) );

			if ( ';' === $css[ $i ] ) {
				++$i; // A statement -- `@import` and its kind -- is dropped.
				continue;
			}

			$close = self::matching_brace( $css, $i );

			if ( null === $close ) {
				break; // Left open: dropped rather than left to swallow the page.
			}

			$body = substr( $css, $i + 1, $close - $i - 1 );
			$i    = $close + 1;

			if ( '' === $prelude ) {
				continue;
			}

			if ( '@' === $prelude[0] ) {
				$name = preg_match( '/^@(?:-[a-z]+-)?([a-z-]+)/i', $prelude, $match ) ? strtolower( $match[1] ) : '';

				if ( in_array( $name, self::NESTING, true ) ) {
					$out[] = $prelude . ' {' . self::rules( $body, $in ) . '}';
				} elseif ( in_array( $name, self::VERBATIM, true ) ) {
					$out[] = $prelude . ' {' . $body . '}';
				}

				continue;
			}

			$selectors = array_map(
				static function ( $selector ) use ( $in ) {
					return self::held( trim( $selector ), $in );
				},
				self::split_list( $prelude )
			);

			$selectors = array_filter(
				$selectors,
				static function ( string $selector ): bool {
					return '' !== $selector;
				}
			);

			$out[] = implode( ', ', $selectors ) . ' {' . $body . '}';
		}

		return implode( "\n", $out );
	}

	/**
	 * One selector, with the poll's condition on the element it styles.
	 *
	 * Before a pseudo-element if there is one, because a pseudo-element has to
	 * end its selector and anything after it would void the whole rule.
	 *
	 * @param string $selector The selector.
	 * @param string $in       The condition.
	 * @return string
	 */
	private static function held( string $selector, string $in ): string {
		if ( '' === $selector ) {
			return '';
		}

		$at     = strlen( $selector );
		$depth  = 0;
		$length = strlen( $selector );

		for ( $i = 0; $i < $length; $i++ ) {
			$char = $selector[ $i ];

			if ( '"' === $char || "'" === $char ) {
				$i = self::string_end( $selector, $i ) - 1;
				continue;
			}

			if ( '(' === $char || '[' === $char ) {
				++$depth;
			} elseif ( ')' === $char || ']' === $char ) {
				--$depth;
			} elseif ( 0 === $depth && ':' === $char && ':' === ( $selector[ $i + 1 ] ?? '' ) ) {
				$at = $i;
				break;
			}
		}

		// The four pseudo-elements CSS2 wrote with a single colon.
		if ( strlen( $selector ) === $at && preg_match( '/:(before|after|first-line|first-letter)\s*$/i', $selector, $match, PREG_OFFSET_CAPTURE ) ) {
			$at = (int) $match[0][1];
		}

		return substr( $selector, 0, $at ) . $in . substr( $selector, $at );
	}

	/**
	 * A selector list split at its own commas, not those inside brackets,
	 * functions or strings.
	 *
	 * @param string $selectors The selector list.
	 * @return string[]
	 */
	private static function split_list( string $selectors ): array {
		$parts  = array();
		$depth  = 0;
		$start  = 0;
		$length = strlen( $selectors );

		for ( $i = 0; $i < $length; $i++ ) {
			$char = $selectors[ $i ];

			if ( '"' === $char || "'" === $char ) {
				$i = self::string_end( $selectors, $i ) - 1;
			} elseif ( '(' === $char || '[' === $char ) {
				++$depth;
			} elseif ( ')' === $char || ']' === $char ) {
				--$depth;
			} elseif ( ',' === $char && 0 === $depth ) {
				$parts[] = substr( $selectors, $start, $i - $start );
				$start   = $i + 1;
			}
		}

		$parts[] = substr( $selectors, $start );

		return $parts;
	}

	/**
	 * The first of `$stops` at or after `$i`, outside strings.
	 *
	 * @param string   $css   The CSS.
	 * @param int      $i     Where to start.
	 * @param string[] $stops The characters to stop at.
	 * @return int Its offset, or the length when there is none.
	 */
	private static function scan_to( string $css, int $i, array $stops ): int {
		$length = strlen( $css );

		while ( $i < $length ) {
			$char = $css[ $i ];

			if ( '"' === $char || "'" === $char ) {
				$i = self::string_end( $css, $i );
				continue;
			}

			if ( in_array( $char, $stops, true ) ) {
				return $i;
			}

			++$i;
		}

		return $length;
	}

	/**
	 * The brace that closes the one at `$open`, counting nested blocks and
	 * skipping strings.
	 *
	 * @param string $css  The CSS.
	 * @param int    $open Offset of the `{`.
	 * @return int|null Its offset, or null when the block is never closed.
	 */
	private static function matching_brace( string $css, int $open ): ?int {
		$depth  = 0;
		$length = strlen( $css );

		for ( $i = $open; $i < $length; $i++ ) {
			$char = $css[ $i ];

			if ( '"' === $char || "'" === $char ) {
				$i = self::string_end( $css, $i ) - 1;
			} elseif ( '{' === $char ) {
				++$depth;
			} elseif ( '}' === $char && 0 === --$depth ) {
				return $i;
			}
		}

		return null;
	}

	/**
	 * The offset just past the string that opens at `$i`.
	 *
	 * @param string $css The CSS.
	 * @param int    $i   Offset of the opening quote.
	 * @return int
	 */
	private static function string_end( string $css, int $i ): int {
		$quote  = $css[ $i ];
		$length = strlen( $css );

		for ( ++$i; $i < $length; $i++ ) {
			if ( '\\' === $css[ $i ] ) {
				++$i;
			} elseif ( $quote === $css[ $i ] ) {
				return $i + 1;
			}
		}

		return $length;
	}

	/**
	 * The CSS with its comments taken out, strings left alone.
	 *
	 * @param string $css The CSS.
	 * @return string
	 */
	private static function without_comments( string $css ): string {
		$out    = '';
		$length = strlen( $css );
		$i      = 0;

		while ( $i < $length ) {
			if ( '/' === $css[ $i ] && '*' === ( $css[ $i + 1 ] ?? '' ) ) {
				$end = strpos( $css, '*/', $i + 2 );
				$i   = false === $end ? $length : $end + 2;
				continue;
			}

			if ( '"' === $css[ $i ] || "'" === $css[ $i ] ) {
				$end  = self::string_end( $css, $i );
				$out .= substr( $css, $i, $end - $i );
				$i    = $end;
				continue;
			}

			$out .= $css[ $i ];
			++$i;
		}

		return $out;
	}
}
