<?php
/**
 * coreify.php — mechanical package<->core token transform for the wordpress/abilities package.
 *
 * Modes:
 *   (default)  forward: package (WordPress\Abilities\Content\Content_Query) -> core
 *              (wp-includes abilities-api) form.
 *   --reverse: core -> package form, plus a manifest entry skeleton on stdout.
 *   --check:   round-trip acceptance gate: reverse(fixture) -> forward() -> semantic
 *              token diff vs the ORIGINAL fixture (must be clean), plus a semantic
 *              diff of the reverse output vs the hand-port package file (must be
 *              clean; known cosmetic diffs are whitespace/comments and thus ignored).
 *   --help
 *
 * Pure token-based (token_get_all + token_name), single file, no runtime deps.
 * Outputs are written under tools/out/ unless --out is given.
 */

declare( strict_types = 1 );

class CoreifyError extends RuntimeException {}

function fail( string $msg ): void {
	throw new CoreifyError( $msg );
}

/* ---------------------------------------------------------------------
 * Config
 * ------------------------------------------------------------------- */

/** Token classes the semantic diff ignores (cosmetic). */
const SEM_SKIP = array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG, T_INLINE_HTML );

/** The 10 authoring methods of Abstract_Core_Ability (this fixed order). */
const AUTHORING_METHODS = array(
	'ability_name', 'label', 'description', 'category_slug',
	'input_schema', 'output_schema', 'make_execute_callback',
	'make_permission_callback', 'ability_meta', 'required_capabilities',
);

/** Authoring method return types (all return exactly one expression). */
const AUTHORING_TYPES = array(
	'ability_name'           => 'string',
	'label'                  => 'string',
	'description'            => 'string',
	'category_slug'          => 'string',
	'input_schema'           => 'array',
	'output_schema'          => 'array',
	'make_execute_callback'  => 'callable',
	'make_permission_callback' => 'callable',
	'ability_meta'           => 'array',
	'required_capabilities'  => 'array',
);

/** Registration-array entry key per authoring method. */
const AUTHORING_ENTRY = array(
	'label'                  => 'label',
	'description'            => 'description',
	'category_slug'          => 'category',
	'input_schema'           => 'input_schema',
	'output_schema'          => 'output_schema',
	'make_execute_callback'  => 'execute_callback',
	'make_permission_callback' => 'permission_callback',
	'ability_meta'           => 'meta',
	'required_capabilities'  => 'required_capabilities',
);

/** Emission order of registration entries. */
const AUTHORING_DOCS = array(
	'ability_name' => 'The ability name slug.', 'label' => 'The ability label.', 'description' => 'The ability description.', 'category_slug' => 'The ability category slug.', 'input_schema' => 'The JSON Schema for the ability input.', 'output_schema' => 'The JSON Schema for the ability output.', 'make_execute_callback' => 'Builds the execute callback.', 'make_permission_callback' => 'Builds the permission callback.', 'ability_meta' => 'Additional ability metadata.', 'required_capabilities' => 'Capability names required to run the ability.',
);
const REG_KEYS = array(
	'label', 'description', 'category', 'input_schema', 'output_schema',
	'execute_callback', 'permission_callback', 'required_capabilities', 'meta',
);

/** Helpers re-inlined from Abstract_Core_Ability, in order. */
const INLINE_HELPERS = array( 'parse_filter_int', 'parse_list_input', 'get_input_object' );

/** Forward anchors: helper name => member after which the helper is inserted. */
const HELPER_AFTER = array(
	'parse_filter_int' => 'check_permission',
	'parse_list_input' => 'parse_filter_int',
	'get_input_object' => 'execute_content_query',
);

/** Fixed `use` block of the package form (per the hand-port). */
const PKG_IMPORTS = array( 'DateTimeZone', 'stdClass', 'WP_Error', 'WP_Post', 'WP_Post_Type', 'WP_Query', 'WP_User' );

/* ---------------------------------------------------------------------
 * Token primitives
 * ------------------------------------------------------------------- */

/**
 * Tokenize source into (id, text, line) triples.
 * Single-character tokens get ord(char) as id; lines are tracked manually.
 *
 * @return list<array{int,string,int}>
 */
function tokenize_source( string $src ): array {
	$raw = token_get_all( $src, TOKEN_PARSE );
	$out = array();
	$line = 1;
	foreach ( $raw as $t ) {
		if ( is_array( $t ) ) {
			$out[] = array( $t[0], $t[1], $t[2] );
			$line += substr_count( $t[1], "\n" );
		} else {
			$out[] = array( ord( $t ), $t, $line );
			$line += substr_count( $t, "\n" );
		}
	}
	return $out;
}

function is_skipped( array $t ): bool {
	return in_array( $t[0], SEM_SKIP, true );
}

/**
 * Structural depth delta of one token: brace/paren/bracket +1/-1.
 * String/encapsed/comment tokens contribute 0.
 */
function struct_delta( array $t ): int {
	if ( is_skipped( $t ) ) {
		return 0;
	}
	switch ( $t[0] ) {
		case 123: // {
		case T_CURLY_OPEN:
		case T_DOLLAR_OPEN_CURLY_BRACES:
		case 40: // (
		case 91: // [
			return 1;
		case 125: // }
		case 41: // )
		case 93: // ]
			return -1;
	}
	return 0;
}

/** Index of the token that closes the group opened at $open. */
function match_group( array $tokens, int $open ): int {
	$depth = 0;
	$n = count( $tokens );
	for ( $i = $open; $i < $n; $i++ ) {
		$depth += struct_delta( $tokens[ $i ] );
		if ( 0 === $depth ) {
			return $i;
		}
	}
	fail( 'unbalanced token group' );
}

/** First meaningful token at index >= $i and <= $lim (inclusive), or null. */
function next_meaningful_token( array $tokens, int $i, ?int $lim = null ): ?int {
	$n = count( $tokens );
	$lim = null === $lim ? $n - 1 : min( $lim, $n - 1 );
	for ( $j = $i; $j <= $lim; $j++ ) {
		if ( ! is_skipped( $tokens[ $j ] ) ) {
			return $j;
		}
	}
	return null;
}

/** First meaningful token at index <= $i and >= $from (inclusive), or null. */
function prev_meaningful_token( array $tokens, int $from, int $i ): ?int {
	$from = max( 0, $from );
	for ( $j = $i; $j >= $from; $j-- ) {
		if ( ! is_skipped( $tokens[ $j ] ) ) {
			return $j;
		}
	}
	return null;
}

/** Verbatim text of token span [$a..$b] inclusive. */
function span_text( array $tokens, int $a, int $b ): string {
	$s = '';
	for ( $i = $a; $i <= $b; $i++ ) {
		$s .= $tokens[ $i ][1];
	}
	return $s;
}

/** Shrink a span inward to its meaningful bounds. */
function trim_span( array $tokens, int $a, int $b ): array {
	while ( $a < $b && is_skipped( $tokens[ $a ] ) ) {
		$a++;
	}
	while ( $b > $a && is_skipped( $tokens[ $b ] ) ) {
		$b--;
	}
	return array( $a, $b );
}

/** Text of span trimmed to meaningful tokens. */
function span_trim( array $tokens, int $a, int $b ): string {
	list( $a, $b ) = trim_span( $tokens, $a, $b );
	return span_text( $tokens, $a, $b );
}

/**
 * Reindent continuation lines (all lines after the first) by $delta tabs.
 * Negative delta strips at most |delta| leading tabs per line; blank or
 * shallower-indented lines are left untouched.
 */
function reindent( string $text, int $delta ): string {
	if ( 0 === $delta ) {
		return $text;
	}
	$lines = explode( "\n", $text );
	$n = count( $lines );
	for ( $i = 1; $i < $n; $i++ ) {
		if ( $delta > 0 ) {
			$lines[ $i ] = str_repeat( "\t", $delta ) . $lines[ $i ];
			continue;
		}
		if ( '' === $lines[ $i ] ) {
			continue;
		}
		$tabs = strspn( $lines[ $i ], "\t" );
		if ( $tabs >= -$delta ) {
			$lines[ $i ] = substr( $lines[ $i ], -$delta );
		}
	}
	return implode( "\n", $lines );
}

/** Strip one layer of outer quotes (single-quoted inputs only). */
function unquote( string $s ): string {
	$q = strlen( $s ) >= 2 ? $s[0] : '';
	if ( "'" === $q || '"' === $q ) {
		$body = substr( $s, 1, -1 );
		if ( "'" === $q ) {
			$body = str_replace( array( "\\'", '\\\\' ), array( "'", "\\" ), $body );
		}
		return $body;
	}
	return $s;
}

/* ---------------------------------------------------------------------
 * Statement splitting
 * ------------------------------------------------------------------- */

/**
 * Statements of token span [$from..$to]: each ends at a ';' at relative
 * paren/brace/bracket depth 0 (so closure bodies never terminate statements);
 * a trailing span (no closing ';') is kept if it has meaningful tokens.
 * Leading whitespace/comments glue to the FOLLOWING statement span.
 *
 * @return list<array{int,int}> [startToken, endToken] inclusive.
 */
function split_statements( array $tokens, int $from, int $to ): array {
	$stmts = array();
	$depth = 0;
	$start = $from;
	for ( $i = $from; $i <= $to; $i++ ) {
		$t = $tokens[ $i ];
		if ( is_skipped( $t ) ) {
			continue;
		}
		$d = struct_delta( $t );
		if ( 125 === $t[0] && 1 === $depth ) {
			// Closing brace of a brace-terminated statement (if/foreach/while/switch/try).
			// Only if the next meaningful token is not ';' (closures/do-while keep going).
			$next = next_meaningful_token( $tokens, $i + 1 );
			if ( null === $next || 59 !== $tokens[ $next ][0] ) {
				$depth += $d;
				$stmts[] = array( $start, $i );
				$start = $i + 1;
				continue;
			}
		}
		if ( 0 !== $d ) {
			$depth += $d;
			continue;
		}
		if ( 0 === $depth && 59 === $t[0] ) { // ';'
			$stmts[] = array( $start, $i );
			$start = $i + 1;
		}
	}
	if ( $start <= $to ) {
		$stmts[] = array( $start, $to );
	}
	$out = array();
	foreach ( $stmts as $st ) {
		if ( null !== next_meaningful_token( $tokens, $st[0], $st[1] ) ) {
			$out[] = $st;
		}
	}
	return $out;
}

/** Statements strictly between an open { and close } token. */
function body_statements( array $tokens, int $open, int $close ): array {
	return split_statements( $tokens, $open + 1, $close - 1 );
}

/** Index of a statement's first meaningful token. */
function stmt_start( array $tokens, array $st ): int {
	$f = next_meaningful_token( $tokens, $st[0], $st[1] );
	if ( null === $f ) {
		fail( 'empty statement span' );
	}
	return $f;
}

/** Text of a statement span, trimmed to meaningful tokens. */
function stmt_text( array $tokens, array $st ): string {
	return span_trim( $tokens, $st[0], $st[1] );
}

/* ---------------------------------------------------------------------
 * Class/member parsing
 * ------------------------------------------------------------------- */

/**
 * Index of the comment run immediately preceding a declaration start:
 * scan backwards over whitespace/comments, return index of the first
 * (leftmost) comment in the contiguous run, or $start if none.
 */
function doc_start( array $tokens, int $start ): int {
	$i = $start;
	$first = $start;
	while ( $i > 0 ) {
		$i--;
		$id = $tokens[ $i ][0];
		if ( T_WHITESPACE === $id ) {
			continue;
		}
		if ( T_COMMENT === $id || T_DOC_COMMENT === $id ) {
			$first = $i;
			continue;
		}
		break;
	}
	return $first;
}

/** Parse the single class decl: modifiers back to the leftmost, name, extends, body. */
function parse_class( array $tokens ): array {
	$n = count( $tokens );
	for ( $i = 0; $i < $n; $i++ ) {
		if ( T_CLASS !== $tokens[ $i ][0] ) {
			continue;
		}
		$prev = prev_meaningful_token( $tokens, 0, $i - 1 );
		if ( null !== $prev && T_DOUBLE_COLON === $tokens[ $prev ][0] ) {
			continue; // ::class resolution
		}
		$ni = next_meaningful_token( $tokens, $i + 1 );
		if ( null === $ni ) {
			continue;
		}
		$start = $i;
		$p = $i;
		while ( true ) {
			$pv = prev_meaningful_token( $tokens, 0, $p - 1 );
			if ( null === $pv ) {
				break;
			}
			$pid = $tokens[ $pv ][0];
			if ( T_FINAL === $pid || T_ABSTRACT === $pid || T_READONLY === $pid ) {
				$p = $pv;
			} else {
				break;
			}
		}
		$start = $p;
		$name = $tokens[ $ni ][1];
		$extends = '';
		$open = null;
		$j = $ni + 1;
		while ( true ) {
			$f = next_meaningful_token( $tokens, $j );
			if ( null === $f ) {
				fail( 'unterminated class header' );
			}
			$id = $tokens[ $f ][0];
			if ( T_EXTENDS === $id ) {
				$parent = next_meaningful_token( $tokens, $f + 1 );
				$extends = $tokens[ $parent ][1];
				$j = $parent + 1;
				continue;
			}
			if ( 123 === $id ) {
				$open = $f;
				break;
			}
			if ( T_IMPLEMENTS === $id || T_USE === $id ) {
				fail( 'class forms not supported by coreify: implements/use' );
			}
			$j = $f + 1;
		}
		$close = match_group( $tokens, $open );
		return array(
			'start'   => $start,
			'doc'     => doc_start( $tokens, $start ),
			'name'    => $name,
			'extends' => $extends,
			'open'    => $open,
			'close'   => $close,
		);
	}
	fail( 'no class declaration found' );
}

/**
 * Members of a class body: constants and functions with docblock spans.
 *
 * Records: kind const|function, name, start (first modifier/const/function
 * token), end, doc (doc_start), for functions open/close (body braces) plus
 * vis/static/final/abstract flags.
 *
 * @return list<array<string,mixed>>
 */
function parse_members( array $tokens, int $open, int $close ): array {
	$members = array();
	$depth = 0;
	$i = $open + 1;
	while ( $i < $close ) {
		$t = $tokens[ $i ];
		if ( is_skipped( $t ) ) {
			$i++;
			continue;
		}
		$d = struct_delta( $t );
		if ( 0 !== $d ) {
			$depth += $d;
			$i++;
			continue;
		}
		if ( 0 !== $depth ) {
			$i++;
			continue;
		}
		$id = $t[0];
		if ( T_CONST !== $id && T_FUNCTION !== $id ) {
			$i++;
			continue;
		}
		$prev = prev_meaningful_token( $tokens, $open + 1, $i - 1 );
		if ( null !== $prev && T_DOUBLE_COLON === $tokens[ $prev ][0] ) {
			$i++; // Foo::class usage
			continue;
		}
		$p = $i;
		while ( true ) {
			$pv = prev_meaningful_token( $tokens, $open + 1, $p - 1 );
			if ( null === $pv ) {
				break;
			}
			$pid = $tokens[ $pv ][0];
			if ( T_PUBLIC === $pid || T_PROTECTED === $pid || T_PRIVATE === $pid
				|| T_STATIC === $pid || T_FINAL === $pid || T_ABSTRACT === $pid
				|| T_READONLY === $pid ) {
				$p = $pv;
			} else {
				break;
			}
		}
		if ( T_CONST === $id ) {
			$ni = next_meaningful_token( $tokens, $i + 1, $close );
			if ( null === $ni ) {
				fail( 'const without name' );
			}
			$name = $tokens[ $ni ][1];
			$end = null;
			$scan = $ni + 1;
			$d = 0;
			while ( $scan <= $close ) {
				$d += struct_delta( $tokens[ $scan ] );
				if ( 0 === $d && 59 === $tokens[ $scan ][0] ) {
					$end = $scan;
					break;
				}
				$scan++;
			}
			if ( null === $end ) {
				fail( 'const without terminating semicolon: ' . $name );
			}
			$members[] = array(
				'kind' => 'const', 'name' => $name, 'start' => $p, 'end' => $end,
				'doc' => doc_start( $tokens, $p ),
				'open' => null, 'close' => null, 'vis' => null,
				'static' => false, 'final' => false, 'abstract' => false,
			);
			$i = $end + 1;
			continue;
		}
		// function
		$ni = next_meaningful_token( $tokens, $i + 1, $close );
		if ( null === $ni ) {
			fail( 'function without name' );
		}
		if ( 38 === $tokens[ $ni ][0] ) { // '&'
			$ni = next_meaningful_token( $tokens, $ni + 1, $close );
			if ( null === $ni ) {
				fail( 'malformed function reference' );
			}
		}
		if ( T_STRING !== $tokens[ $ni ][0] ) {
			fail( 'unexpected member form near token ' . $i . ' (' . $tokens[ $ni ][1] . ')' );
		}
		$name = $tokens[ $ni ][1];
		$paren = next_meaningful_token( $tokens, $ni + 1, $close );
		if ( null === $paren || 40 !== $tokens[ $paren ][0] ) {
			fail( 'function without parens: ' . $name );
		}
		$pclose = match_group( $tokens, $paren );
		$after = next_meaningful_token( $tokens, $pclose + 1, $close );
		if ( null !== $after && 58 === $tokens[ $after ][0] ) { // ':' return-type seam
			$scan = $after;
			$rtd = 0;
			while ( $scan <= $close ) {
				$t2 = $tokens[ $scan ];
				if ( ! is_skipped( $t2 ) && 0 === $rtd ) {
					$id2 = $t2[0];
					if ( 123 === $id2 || 59 === $id2 ) {
						$after = $scan;
						break;
					}
				}
				$rtd += struct_delta( $t2 );
				$scan++;
			}
			if ( null === $after ) {
				fail( 'unterminated return type after params of ' . $name );
			}
		}
		$end = null;
		$bo = null;
		$bc = null;
		if ( null !== $after && 123 === $tokens[ $after ][0] ) {
			$bo = $after;
			$bc = match_group( $tokens, $bo );
			$end = $bc;
		} else {
			$end = $pclose;
			$e = $pclose;
			while ( $e < $close ) {
				$e++;
				if ( 59 === $tokens[ $e ][0] ) {
					$end = $e;
					break;
				}
			}
			if ( 59 !== $tokens[ $end ][0] ) {
				fail( 'function without terminating semicolon: ' . $name );
			}
		}
		$vis = null;
		$static = false;
		$final = false;
		$abstract = false;
		for ( $q = $p; $q < $ni; $q++ ) {
			$qid = $tokens[ $q ][0];
			if ( T_PUBLIC === $qid ) $vis = 'public';
			elseif ( T_PROTECTED === $qid ) $vis = 'protected';
			elseif ( T_PRIVATE === $qid ) $vis = 'private';
			elseif ( T_STATIC === $qid ) $static = true;
			elseif ( T_FINAL === $qid ) $final = true;
			elseif ( T_ABSTRACT === $qid ) $abstract = true;
		}
		$members[] = array(
			'kind' => 'function', 'name' => $name, 'start' => $p, 'end' => $end,
			'doc' => doc_start( $tokens, $p ),
			'open' => $bo, 'close' => $bc, 'vis' => $vis,
			'static' => $static, 'final' => $final, 'abstract' => $abstract,
		);
		$i = $end + 1;
	}
	if ( array() === $members ) {
		fail( 'class has no members' );
	}
	return $members;
}

/** Verbatim text from docblock to end token. */
function member_text( array $tokens, array $rec ): string {
	return span_text( $tokens, $rec['doc'], $rec['end'] );
}

function index_by_name( array $members ): array {
	$by = array();
	foreach ( $members as $rec ) {
		if ( isset( $by[ $rec['name'] ] ) ) {
			fail( 'duplicate member name: ' . $rec['name'] );
		}
		$by[ $rec['name'] ] = $rec;
	}
	return $by;
}

function find_member( array $members, string $name ): ?array {
	foreach ( $members as $rec ) {
		if ( $rec['name'] === $name ) {
			return $rec;
		}
	}
	return null;
}

/** The single `return <expr> ;` expression of a method body. */
function return_expr( array $tokens, array $rec ): string {
	$rec['kind'] === 'function' || fail( 'not a function: ' . $rec['name'] );
	$rec['open'] !== null || fail( 'abstract method has no body: ' . $rec['name'] );
	$stmts = body_statements( $tokens, $rec['open'], $rec['close'] );
	$expr = null;
	foreach ( $stmts as $st ) {
		$f = stmt_start( $tokens, $st );
		if ( T_RETURN === $tokens[ $f ][0] ) {
			$expr === null || fail( 'multiple return statements in ' . $rec['name'] );
			$expr = span_trim( $tokens, $f + 1, $st[1] - 1 );
		}
	}
	if ( null === $expr ) {
		fail( 'no return statement in ' . $rec['name'] );
	}
	return $expr;
}

/** True if expr is exactly `array()`. */
function expr_is_empty_array( string $expr ): bool {
	$raw = token_get_all( '<?php ' . $expr );
	$sem = array();
	foreach ( $raw as $t ) {
		if ( is_array( $t ) ) {
			if ( in_array( $t[0], SEM_SKIP, true ) ) {
				continue;
			}
			$sem[] = array( $t[0], $t[1] );
		} else {
			$sem[] = array( ord( $t ), $t );
		}
	}
	return 3 === count( $sem ) && T_ARRAY === $sem[0][0] && 40 === $sem[1][0] && 41 === $sem[2][0];
}

/* ---------------------------------------------------------------------
 * Semantic diff
 * ------------------------------------------------------------------- */

/** Tokens minus cosmetic classes (whitespace/comments), for semantic diff. */
function sem_tokens( array $tokens ): array {
	$out = array();
	foreach ( $tokens as $t ) {
		if ( is_skipped( $t ) ) {
			continue;
		}
		$out[] = array( $t[0], $t[1], $t[2] );
	}
	return $out;
}

/** Semantic diff of two sources: null on equality, else first mismatch info. */
function semdiff( string $aText, string $bText ): ?array {
	$A = sem_tokens( tokenize_source( $aText ) );
	$B = sem_tokens( tokenize_source( $bText ) );
	$lenA = count( $A );
	$lenB = count( $B );
	$n = min( $lenA, $lenB );
	for ( $i = 0; $i < $n; $i++ ) {
		if ( $A[ $i ][0] !== $B[ $i ][0] || $A[ $i ][1] !== $B[ $i ][1] ) {
			return array( 'index' => $i, 'a' => $A[ $i ], 'b' => $B[ $i ], 'lenA' => $lenA, 'lenB' => $lenB );
		}
	}
	if ( $lenA !== $lenB ) {
		$tokA = $lenA > $n ? $A[ $n ] : array( 0, '<eof>', 0 );
		$tokB = $lenB > $n ? $B[ $n ] : array( 0, '<eof>', 0 );
		return array( 'index' => $n, 'a' => $tokA, 'b' => $tokB, 'lenA' => $lenA, 'lenB' => $lenB );
	}
	return null;
}

function token_repr( array $t ): string {
	$label = token_name( $t[0] );
	return $label . '(' . substr( $t[1], 0, 48 ) . ')';
}

function print_mismatch( string $label, array $m ): void {
	fwrite( STDERR, $label . " first mismatch at token index " . $m['index'] . "\n" );
	fwrite( STDERR, "  A: line " . $m['a'][2] . "  " . token_repr( $m['a'] ) . "\n" );
	fwrite( STDERR, "  B: line " . $m['b'][2] . "  " . token_repr( $m['b'] ) . "\n" );
	fwrite( STDERR, "  (A tokens: " . $m['lenA'] . ", B tokens: " . $m['lenB'] . ")\n" );
}

/* ---------------------------------------------------------------------
 * Naming
 * ------------------------------------------------------------------- */

/**
 * Derive names from an ability name like 'core/content-query':
 * [0] dir 'Content', [1] package class 'Content_Query', [2] core class
 * 'WP_Abilities_Content', [3] register method 'register_content_query',
 * [4] category 'content'.
 *
 * @return array{string,string,string,string,string}
 */
function slug_parts( string $abname ): array {
	$slug = $abname;
	$slash = strpos( $slug, '/' );
	if ( false !== $slash ) {
		$slug = substr( $slug, $slash + 1 );
	}
	$raw = explode( '-', $slug );
	$words = array_map( static function ( string $p ): string {
		return str_replace( ' ', '', ucwords( $p ) );
	}, $raw );
	$dir = $words[0];
	$pkgClass = implode( '_', $words );
	$coreClass = 'WP_Abilities_' . $words[0];
	$regMethod = 'register_' . implode( '_', $raw );
	$category = $raw[0];
	return array( $dir, $pkgClass, $coreClass, $regMethod, $category );
}

/* ---------------------------------------------------------------------
 * Abstract_Core_Ability helpers
 * ------------------------------------------------------------------- */

/**
 * Helper declaration texts from Abstract_Core_Ability, with protected
 * converted to private (token-level), docblock included.
 *
 * @return array<string,string>
 */
function helper_texts( string $absText ): array {
	$tokens = tokenize_source( $absText );
	$cls = parse_class( $tokens );
	$members = parse_members( $tokens, $cls['open'], $cls['close'] );
	$out = array();
	foreach ( INLINE_HELPERS as $h ) {
		$rec = find_member( $members, $h );
		if ( null === $rec ) {
			fail( 'helper not found in Abstract_Core_Ability: ' . $h );
		}
		if ( ! $rec['static'] ) {
			fail( 'helper is not static: ' . $h );
		}
		$skipWs = false;
		$text = '';
		for ( $i = $rec['doc']; $i <= $rec['end']; $i++ ) {
			$t = $tokens[ $i ];
			if ( $skipWs ) {
				$skipWs = false;
				if ( T_WHITESPACE === $t[0] ) {
					continue;
				}
			}
			if ( T_STATIC === $t[0] ) {
				// Trunk inlines the helpers as plain private methods; drop 'static'.
				$skipWs = true;
				continue;
			}
			if ( T_PROTECTED === $t[0] ) {
				$t = array( T_PROTECTED, 'private', $t[2] );
			}
			$text .= $t[1];
		}
		$out[ $h ] = $text;
	}
	return $out;
}

/**
 * Semantic token lists of the helper bodies (open..close braces exclusive),
 * for the reverse-mode drop guard.
 *
 * @return array<string,list<list<scalar>>>
 */
function helper_bodies( string $absText ): array {
	$tokens = tokenize_source( $absText );
	$cls = parse_class( $tokens );
	$members = parse_members( $tokens, $cls['open'], $cls['close'] );
	$out = array();
	foreach ( INLINE_HELPERS as $h ) {
		$rec = find_member( $members, $h );
		if ( null === $rec ) {
			fail( 'helper not found in Abstract_Core_Ability: ' . $h );
		}
		// Build full semantic token list of body span (including structure tokens).
		$list = array();
		for ( $i = $rec['open'] + 1; $i <= $rec['close'] - 1; $i++ ) {
			$t = $tokens[ $i ];
			if ( is_skipped( $t ) ) {
				continue;
			}
			$list[] = array( $t[0], $t[1] );
		}
		$out[ $h ] = $list;
	}
	return $out;
}

function registration_stmt( array $tokens, array $stmts ): array {
	foreach ( $stmts as $k => $st ) {
		$f = stmt_start( $tokens, $st );
		if ( T_STRING === $tokens[$f][0] && 'wp_register_ability' === $tokens[$f][1] ) {
			return array( $k, $f );
		}
	}
	fail( 'no wp_register_ability(...) statement found' );
}

/* ---------------------------------------------------------------------
 * FORWARD transform
 * ------------------------------------------------------------------- */

/**
 * Build the wp_register_ability(...) call from lifted authoring expressions.
 * $vals: entry key => expression text (multi-line continuation lines carry
 * their own indentation, reindented +2 at the seam).
 */
function build_registration( string $nameLit, array $vals ): string {
	$keys = array();
	foreach ( REG_KEYS as $k ) {
		if ( isset( $vals[ $k ] ) ) {
			$keys[] = $k;
		}
	}
	$maxlen = 0;
	foreach ( $keys as $k ) {
		$maxlen = max( $maxlen, strlen( $k ) + 2 );
	}
	$out = "\t\twp_register_ability(\n";
	$out .= "\t\t\t" . $nameLit . ",\n";
	$out .= "\t\t\tarray(\n";
	foreach ( $keys as $k ) {
		$pad = str_repeat( ' ', $maxlen - ( strlen( $k ) + 2 ) + 1 );
		$out .= "\t\t\t\t'" . $k . "'" . $pad . '=> ' . reindent( $vals[ $k ], 2 ) . ",\n";
	}
	$out .= "\t\t\t)\n";
	$out .= "\t\t);\n";
	return $out;
}

/**
 * Forward transform: package class text -> core class text.
 *
 * @param string $absText Raw text of src/Abstract_Core_Ability.php.
 */
function transform_forward( string $pkgText, string $absText ): string {
	$tokens = tokenize_source( $pkgText );
	$cls = parse_class( $tokens );
	$members = parse_members( $tokens, $cls['open'], $cls['close'] );
	$by = index_by_name( $members );

	$abname = unquote( return_expr( $tokens, $by['ability_name'] ) );
	list( $dir, $pkgClass, $coreClass, $regMethod, $category ) = slug_parts( $abname );
	if ( $cls['name'] !== $pkgClass ) {
		fail( 'unexpected class name ' . $cls['name'] . ' for ability ' . $abname . ' (expected ' . $pkgClass . ')' );
	}
	if ( isset( $by['register'] ) || isset( $by['register_content_query'] ) ) {
		fail( 'package class must not declare register()/register_content_query()' );
	}
	foreach ( INLINE_HELPERS as $h ) {
		if ( isset( $by[ $h ] ) ) {
			fail( 'package class must not declare helper ' . $h . ' (inherited from Abstract_Core_Ability)' );
		}
	}

	/* --- authoring values ------------------------------------------------ */
	$vals = array();
	foreach ( AUTHORING_ENTRY as $m => $entry ) {
		$rec = $by[ $m ] ?? null;
		if ( null === $rec ) {
			fail( 'authoring method missing in package class: ' . $m );
		}
		$vals[ $entry ] = return_expr( $tokens, $rec );
	}
	$nameLit = "'" . $abname . "'";

	if ( isset( $vals['required_capabilities'] ) && expr_is_empty_array( $vals['required_capabilities'] ) ) {
		unset( $vals['required_capabilities'] );
	}

	/* --- register_content_query() prologue from input_schema() ------------- */
	$inRec = $by['input_schema'];
	$stmts = body_statements( $tokens, $inRec['open'], $inRec['close'] );
	$assigns = array();
	$postTypesAt = -1;
	$sawSpliceGuard = false;
	foreach ( $stmts as $idx => $st ) {
		$f = stmt_start( $tokens, $st );
		$id = $tokens[ $f ][0];
		if ( T_RETURN === $id ) {
			continue; // the schema return itself becomes the registration entry
		}
		if ( T_IF === $id ) {
			$sawSpliceGuard = true;
			continue;
		}
		if ( T_VARIABLE !== $id ) {
			fail( 'unexpected statement in input_schema(): ' . token_repr( $tokens[ $f ] ) );
		}
		if ( '$post_types' === $tokens[ $f ][1] ) {
			$postTypesAt = count( $assigns );
		}
		$assigns[] = $st;
	}
	if ( $sawSpliceGuard ) {
		fail( 'input_schema() must not contain the void early-return splice' );
	}
	if ( $postTypesAt < 0 ) {
		fail( 'input_schema() missing a $post_types assignment' );
	}

	$probe = array();
	foreach ( $assigns as $st ) {
		$probe[] = stmt_text( $tokens, $st );
	}

	/* --- assemble register_content_query() --------------------------------- */
	$regCq = "\tprivate function " . $regMethod . "(): void {\n";
	foreach ( $assigns as $idx => $st ) {
		$regCq .= "\t\t" . stmt_text( $tokens, $st ) . "\n";
		if ( $postTypesAt === $idx ) {
			$regCq .= "\t\tif ( empty( \$post_types ) ) {\n";
			$regCq .= "\t\t\treturn;\n";
			$regCq .= "\t\t}\n\n";
		}
	}
	$regCq .= "\n" . build_registration( $nameLit, $vals );
	$regCq .= "\t}\n";

	$registerFn = "\t/**\n"
		. "\t * Registers all content abilities.\n"
		. "\t *\n"
		. "\t * Must run on the `wp_abilities_api_init` hook.\n"
		. "\t *\n"
		. "\t * @since 7.2.0\n"
		. "\t */\n"
		. "\tpublic function register(): void {\n"
		. "\t\t\$this->register_content_query();\n\n"
		. "\t\t/*\n"
		. "\t\t * Future write-oriented content abilities can be registered here, reusing\n"
		. "\t\t * the shared helpers below (get_exposed_post_type(), format_post(),\n"
		. "\t\t * check_permission()).\n"
		. "\t\t */\n"
		. "\t}\n";

	/* --- verbatim constants and methods, with helper re-inline -------------- */
	$chunks = array();
	$pending = array();
	foreach ( HELPER_AFTER as $h => $anchor ) {
		$pending[ $h ] = $anchor;
	}
	$helpTexts = helper_texts( $absText );

	$placeHelpers = static function ( string $anchor ) use ( &$methodChunks, &$pending, $helpTexts ) {
		while ( true ) {
			$placed = false;
			foreach ( INLINE_HELPERS as $h ) {
				if ( isset( $pending[ $h ] ) && $pending[ $h ] === $anchor ) {
					$methodChunks[] = "\t" . $helpTexts[ $h ] . "\n";
					unset( $pending[ $h ] );
					$anchor = $h;
					$placed = true;
				}
			}
			if ( ! $placed ) {
				break;
			}
		}
	};

	$constChunk = '';
	$methodChunks = array();
	foreach ( $members as $rec ) {
		$name = $rec['name'];
		if ( 'const' === $rec['kind'] ) {
			$constChunk .= "\t" . member_text( $tokens, $rec ) . "\n";
			continue;
		}
		if ( in_array( $name, AUTHORING_METHODS, true ) ) {
			continue; // consumed by the registration rebuild
		}
		$methodChunks[] = "\t" . member_text( $tokens, $rec ) . "\n";
		$placeHelpers( $name );
	}
	if ( $pending !== array() ) {
		fail( 'helpers without anchor in package order: ' . implode( ', ', array_keys( $pending ) ) );
	}

	$body = $constChunk . "\n" . $registerFn . "\n" . $regCq . "\n" . implode( "\n", $methodChunks );

	$header = "<?php\n"
		. "/**\n"
		. ' * Abilities API: ' . $coreClass . " class.\n"
		. " *\n"
		. " * @package WordPress\n"
		. " * @subpackage Abilities API\n"
		. " * @since 7.2.0\n"
		. " */\n\n"
		. "declare( strict_types = 1 );\n\n"
		. 'final class ' . $coreClass . " {\n\n";

	return $header . $body . "}\n";
}

/* ---------------------------------------------------------------------
 * REVERSE transform
 * ------------------------------------------------------------------- */

/**
 * Extract the registration entries of the wp_register_ability(...) statement.
 *
 * @return array{string,array<string,string>} [ability name literal without quotes, entries key => expr text]
 */
function parse_registration( array $tokens, array $stmt ): array {
	list( $a, $b ) = $stmt;
	$f = stmt_start( $tokens, $stmt );
	'wp_register_ability' === $tokens[ $f ][1] || fail( 'not a wp_register_ability() call' );
	$paren = next_meaningful_token( $tokens, $f + 1, $b );
	$paren !== null && 40 === $tokens[ $paren ][0] || fail( 'wp_register_ability( missing ( at line ' . $tokens[ $f ][2] );
	$arg1 = next_meaningful_token( $tokens, $paren + 1, $b );
	$arg1 !== null || fail( 'wp_register_ability() first argument missing' );
	$abname = unquote( $tokens[ $arg1 ][1] );
	$comma = next_meaningful_token( $tokens, $arg1 + 1, $b );
	$comma !== null && 44 === $tokens[ $comma ][0] || fail( 'wp_register_ability() entries not a comma-separated array' );
	$arrTok = next_meaningful_token( $tokens, $comma + 1, $b );
	$arrTok !== null && T_ARRAY === $tokens[ $arrTok ][0] || fail( 'registration entries must be array( ... )' );
	$paren2 = next_meaningful_token( $tokens, $arrTok + 1, $b );
	$paren2 !== null && 40 === $tokens[ $paren2 ][0] || fail( 'array( without ( in registration' );
	$close = match_group( $tokens, $paren2 );

	$entries = array();
	$i = $paren2 + 1;
	while ( $i < $close ) {
		$f2 = next_meaningful_token( $tokens, $i, $close );
		if ( null === $f2 ) {
			break;
		}
		if ( T_CONSTANT_ENCAPSED_STRING !== $tokens[ $f2 ][0] ) {
			break; // end of entries: trailing comma precedes the array close
		}
		$key = unquote( $tokens[ $f2 ][1] );
		$arrow = next_meaningful_token( $tokens, $f2 + 1, $close );
		$arrow !== null && T_DOUBLE_ARROW === $tokens[ $arrow ][0] || fail( "entry '$key' missing =>" );
		$d = 0;
		$vend = null;
		$found = false;
		for ( $j = $arrow + 1; $j <= $close; $j++ ) {
			$d += struct_delta( $tokens[ $j ] );
			if ( 0 === $d && 44 === $tokens[ $j ][0] ) {
				$vend = $j - 1;
				$found = true;
				break;
			}
			if ( $j === $close ) {
				$vend = $j - 1;
				$found = true;
			}
		}
		if ( ! $found || null === $vend || $vend < $arrow + 1 ) {
			fail( "entry '$key' without value" );
		}
		$entries[ $key ] = span_trim( $tokens, $arrow + 1, $vend );
		$i = $vend + 2; // skip ','
	}
	return array( $abname, $entries );
}

/** Build the manifest entry skeleton print (reverse mode). */
function build_manifest( string $abname ): string {
	list( $dir, $pkgClass, $coreClass, $regMethod, $category ) = slug_parts( $abname );
	$lines = array(
		'=== Manifest entry skeleton (human-verify before applying to src/Manifest.php) ===',
		"'" . $abname . "' => array(",
		"\t'name'           => '" . $abname . "',",
		"\t'class'          => '" . $dir . '\\' . $pkgClass . "',",
		"\t'category'       => '" . $category . "',",
		"\t'tier'           => 'core',",
		"\t'min_wp_version' => '6.9',",
		"\t'core_since'     => '7.2',",
		"\t'requires'       => array( 'show_in_abilities' ),",
		"\t'renamed_from'   => null,",
		"\t'proposal'       => 'https://github.com/WordPress/wordpress-develop/pull/12195',",
		"),",
		'',
		'Note: tier=core, core_since=7.2, requires=[show_in_abilities] per settled design.',
		'min_wp_version/proposal values are copied from the existing Manifest.php entry;',
		'WP2-flagged for human confirmation.',
	);
	return implode( "\n", $lines );
}

/**
 * Reverse transform: core class text -> package class text.
 */
function transform_reverse( string $coreText ): string {
	$tokens = tokenize_source( $coreText );
	$cls = parse_class( $tokens );
	$members = parse_members( $tokens, $cls['open'], $cls['close'] );
	$by = index_by_name( $members );
	isset( $by['register_content_query'] ) || fail( 'no register_content_query() in core form' );

	$cqRec = $by['register_content_query'];
	$stmts = body_statements( $tokens, $cqRec['open'], $cqRec['close'] );

	list( $regIdx, $regTok ) = array_values( registration_stmt( $tokens, $stmts ) );
	list( $abname, $entries ) = parse_registration( $tokens, $stmts[ $regIdx ] );
	list( $dir, $pkgClass, $coreClass, $regMethod, $category ) = slug_parts( $abname );
	if ( $cls['name'] !== $coreClass ) {
		fail( 'core class name ' . $cls['name'] . ' does not match derived ' . $coreClass . ' for ' . $abname );
	}

	/* prologue assignments before wp_register_ability(...), splice dropped */
	$guardCount = 0;
	$assigns = array();
	for ( $k = 0; $k < $regIdx; $k++ ) {
		$f = stmt_start( $tokens, $stmts[ $k ] );
		$id = $tokens[ $f ][0];
		if ( T_IF === $id ) {
			$guardCount++;
			continue;
		}
		if ( T_VARIABLE === $id ) {
			$assigns[] = $stmts[ $k ];
			continue;
		}
		fail( 'unexpected statement before wp_register_ability(): ' . token_repr( $tokens[ $f ] ) );
	}
	1 === $guardCount || fail( 'expected exactly one void early-return guard, found ' . $guardCount );

	/* helper drop with body-equality guard */
	$absPath = default_abstract_path();
	$base = helper_bodies( read_file( $absPath ) );

	/* authoring method bodies */
	$authors = array();

	$nameLit = "'" . $abname . "'";
	$authors['ability_name'] = 'return ' . $nameLit . ';';
	$mkret = static function ( string $entry ) use ( $entries ): string {
		isset( $entries[ $entry ] ) || fail( 'registration entry missing: ' . $entry );
		return 'return ' . reindent( $entries[ $entry ], -2 ) . ';';
	};
	$authors['label'] = $mkret( 'label' );
	$authors['description'] = $mkret( 'description' );
	$authors['category_slug'] = $mkret( 'category' );
	$authors['output_schema'] = $mkret( 'output_schema' );
	$authors['make_execute_callback'] = $mkret( 'execute_callback' );
	$authors['make_permission_callback'] = $mkret( 'permission_callback' );
	$authors['ability_meta'] = $mkret( 'meta' );

	$rc = 'return array();';
	if ( isset( $entries['required_capabilities'] ) ) {
		$rc = 'return ' . reindent( $entries['required_capabilities'], -2 ) . ';';
	}
	$authors['required_capabilities'] = $rc;

	$prologue = '';
	foreach ( $assigns as $st ) {
		$prologue .= "\t\t" . stmt_text( $tokens, $st ) . "\n\n";
	}
	$authors['input_schema'] = $prologue . "\t\t" . 'return ' . reindent( $entries['input_schema'] ?? '', -2 ) . ';' . "\n";

	/* assemble */
	$nsParts = array( $dir, $pkgClass );
	$out = "<?php\n"
		. "/**\n"
		. ' * Canonical WordPress abilities: `' . $abname . "` ability (tier core).\n"
		. " *\n"
		. ' * Mechanical port of wp-includes/abilities/class-' . str_replace( '_', '-', strtolower( $coreClass ) ) . ".php,\n"
		. " * generated by tools/coreify.php --reverse; hand-finish header docblock.\n"
		. " *\n"
		. " * Deviations from trunk (authoring surface only): the registration entry\n"
		. " * point is decomposed into Abstract_Core_Ability abstract methods;\n"
		. ' * parse_filter_int(), parse_list_input() and get_input_object() are inherited' . ",\n"
		. " * from Abstract_Core_Ability instead of declared here.\n"
		. " */\n\n"
		. "declare( strict_types = 1 );\n\n"
		. 'namespace WordPress\\Abilities\\' . $dir . ";\n\n";
	foreach ( PKG_IMPORTS as $imp ) {
		$out .= 'use ' . $imp . ";\n";
	}
	$out .= "\nuse WordPress\\Abilities\\Abstract_Core_Ability;\n\n";
	$out .= '/**' . "\n"
		. ' * Canonical WordPress abilities: `' . $category . "` ability.\n"
		. " *\n"
		. " * @generated by tools/coreify.php --reverse. Hand-finish this docblock.\n"
		. " */\n"
		. 'final class ' . $pkgClass . " extends Abstract_Core_Ability {\n\n";

	$sections = array();
	$constSections = array();
	foreach ( $members as $rec ) {
		$name = $rec['name'];
		if ( 'const' === $rec['kind'] ) {
			$constSections[] = "\t" . member_text( $tokens, $rec ) . "\n";
			continue;
		}
		if ( 'register' === $name || 'register_content_query' === $name ) {
			continue; // consumed by the authoring rebuild
		}
		if ( in_array( $name, INLINE_HELPERS, true ) ) {
			/* body-equality guard: only drop when identical to the base-class helper */
			$mismatch = null;
			for ( $i = $rec['open'] + 1; $i <= $rec['close'] - 1; $i++ ) {
				$t = $tokens[ $i ];
				if ( is_skipped( $t ) ) {
					continue;
				}
				$mismatch[] = array( $t[0], $t[1] );
			}
			if ( $mismatch !== $base[ $name ] ) {
				fail( 'core helper ' . $name . ' differs from Abstract_Core_Ability; keep it or refresh the abstract class' );
			}
			continue; // drop
		}
		$sections[] = "\t" . member_text( $tokens, $rec ) . "\n";
	}

	/* authoring methods, in fixed order, after constants */
	$authorChunks = array();
	foreach ( AUTHORING_METHODS as $m ) {
		$sig = AUTHORING_TYPES[ $m ];
		$lead = ( "\t\t" === substr( $authors[ $m ], 0, 2 ) ) ? '' : "\t\t";
		$authorChunks[] = "\t/**\n\t * " . AUTHORING_DOCS[ $m ] . "\n\t */\n"
			. "\tprotected function " . $m . '(): ' . $sig . " {\n"
			. $lead . $authors[ $m ] . "\n"
			. "\t}\n";
	}

	$out .= implode( "\n", $constSections ) . implode( "\n", $authorChunks ) . "\n" . implode( "\n", $sections ) . "}\n";

	return $out;
}

/* ---------------------------------------------------------------------
 * CLI
 * ------------------------------------------------------------------- */

function default_root(): string {
	return dirname( __DIR__ );
}

function default_abstract_path(): string {
	return default_root() . '/src/Abstract_Core_Ability.php';
}

function usage(): void {
	echo <<<'TXT'
coreify.php — mechanical package<->core token transform for wordpress/abilities.

Usage:
  php coreify.php [--in <path>] [--out <path>]
  php coreify.php --reverse [--in <path>] [--out <path>]
  php coreify.php --check [--in <path>] [--abs <path>]
  php coreify.php --help

Modes:
  (default)  forward: package -> core form. Input defaults to
             src/Content/Content_Query.php. Writes the fixture-shaped class
             to tools/out/class-wp-abilities-content.php (unless --out).
  --reverse  core -> package form (+ manifest entry skeleton on stdout).
             Input defaults to the trunk fixture
             tests/Fixtures/abilities-api/abilities/class-wp-abilities-content.php.
             Writes tools/out/Content/Content_Query.php (unless --out).
  --check    acceptance gate: reverse(fixture) -> forward() -> semantic token
             diff vs the ORIGINAL fixture (hard gate, prints first mismatch).
             Also diffs the reverse output vs the hand-port
             src/Content/Content_Query.php. Exits non-zero on divergence.

Options:
  --in <path>    input file (per-mode default as above; the same path serves
                 as the fixture input for --check and reverse)
  --out <path>   output file (default tools/out/...)
  --abs <path>   path to src/Abstract_Core_Ability.php (default derived from
                 the repo root)
  --pkg <path>   --check only: package reference file for the second diff
                 (default src/Content/Content_Query.php)
  --help         this help

Exit codes: 0 success; 1 transform/semantic-diff failure; 2 usage error.
TXT;
}

function parse_args( array $argv ): array {
	$o = array(
		'mode' => 'forward',
		'in' => null,
		'out' => null,
		'abs' => null,
		'pkg' => null,
		'help' => false,
	);
	$n = count( $argv );
	for ( $i = 1; $i < $n; $i++ ) {
		$arg = $argv[ $i ];
		switch ( true ) {
			case $arg === '--reverse':
				$o['mode'] = 'reverse';
				break;
			case $arg === '--check':
				$o['mode'] = 'check';
				break;
			case $arg === '--help':
			case $arg === '-h':
				$o['help'] = true;
				break;
			case $arg === '--in':
				$o['in'] = $argv[ ++$i ] ?? fail( '--in requires a path' );
				break;
			case $arg === '--out':
				$o['out'] = $argv[ ++$i ] ?? fail( '--out requires a path' );
				break;
			case $arg === '--abs':
				$o['abs'] = $argv[ ++$i ] ?? fail( '--abs requires a path' );
				break;
			case $arg === '--pkg':
				$o['pkg'] = $argv[ ++$i ] ?? fail( '--pkg requires a path' );
				break;
			default:
				fail( 'unknown argument: ' . $arg . ' (try --help)' );
		}
	}
	return $o;
}

function read_file( string $path ): string {
	$text = file_get_contents( $path );
	if ( false === $text ) {
		fail( 'cannot read: ' . $path );
	}
	return $text;
}

function write_out( string $path, string $text ): void {
	$dir = dirname( $path );
	if ( '' !== $dir && ! is_dir( $dir ) ) {
		if ( ! @mkdir( $dir, 0777, true ) && ! is_dir( $dir ) ) {
			fail( 'cannot create directory: ' . $dir );
		}
	}
	if ( false === file_put_contents( $path, $text ) ) {
		fail( 'cannot write: ' . $path );
	}
	echo '[coreify] wrote ' . $path . " (" . strlen( $text ) . " bytes)\n";
}

function run( array $o ): int {
	$root = default_root();
	$absPath = $o['abs'] ?? default_abstract_path();

	if ( 'forward' === $o['mode'] ) {
		$in = $o['in'] ?? $root . '/src/Content/Content_Query.php';
		$out = $o['out'] ?? $root . '/tools/out/class-wp-abilities-content.php';
		$result = transform_forward( read_file( $in ), read_file( $absPath ) );
		write_out( $out, $result );
		echo "[coreify] forward OK: {$in} -> {$out}\n";
		return 0;
	}

	if ( 'reverse' === $o['mode'] ) {
		$in = $o['in'] ?? $root . '/tests/Fixtures/abilities-api/abilities/class-wp-abilities-content.php';
		$coreText = read_file( $in );
		$result = transform_reverse( $coreText );
		$abname = reverse_ability_name( $coreText );
		$dir = slug_parts( $abname ) ? slug_parts( $abname )[0] : '';
		$defaultOut = $root . '/tools/out/' . ( $dir ?: 'Content' ) . '/Content_Query.php';
		$out = $o['out'] ?? $defaultOut;
		write_out( $out, $result );
		echo "[coreify] reverse OK: {$in} -> {$out}\n";
		echo build_manifest( $abname ) . "\n";
		return 0;
	}

	/* --- check -------------------------------------------------------------- */
	$in = $o['in'] ?? $root . '/tests/Fixtures/abilities-api/abilities/class-wp-abilities-content.php';
	$pkgPath = $o['pkg'] ?? $root . '/src/Content/Content_Query.php';
	$fixture = read_file( $in );
	$handport = read_file( $pkgPath );
	$absText = read_file( $absPath );

	$revOut = $root . '/tools/out/Content/Content_Query.php';
	$fwdOut = $root . '/tools/out/class-wp-abilities-content.php';

	$pkgText = transform_reverse( $fixture );
	write_out( $revOut, $pkgText );
	$coreText = transform_forward( $pkgText, $absText );
	write_out( $fwdOut, $coreText );

	$okA = true;
	$okB = true;
	$mA = semdiff( $coreText, $fixture );
	if ( null !== $mA ) {
		$okA = false;
		fwrite( STDERR, "[check] FAIL: round-trip forward output vs ORIGINAL fixture\n" );
		print_mismatch( 'roundtrip-vs-fixture', $mA );
	} else {
		echo "[check] PASS: round-trip forward output vs ORIGINAL fixture (0 semantic differences)\n";
	}
	$mB = semdiff( $pkgText, $handport );
	if ( null !== $mB ) {
		$okB = false;
		fwrite( STDERR, "[check] FAIL: reverse output vs hand-port package file\n" );
		print_mismatch( 'reverse-vs-handport', $mB );
	} else {
		echo "[check] PASS: reverse output vs hand-port package file (0 semantic differences)\n";
	}
	if ( ! $okA || ! $okB ) {
		return 1;
	}
	echo "[check] OK: round-trip invariant holds.\n";
	return 0;
}

/** Ability name alone (for default reverse out path). */
function reverse_ability_name( string $coreText ): string {
	$tokens = tokenize_source( $coreText );
	$cls = parse_class( $tokens );
	$members = parse_members( $tokens, $cls['open'], $cls['close'] );
	$by = index_by_name( $members );
	isset( $by['register_content_query'] ) || fail( 'no register_content_query() in core form' );
	$rec = $by['register_content_query'];
	$stmts = body_statements( $tokens, $rec['open'], $rec['close'] );
	list( $idx ) = registration_stmt( $tokens, $stmts );
	list( $abname, $entries ) = parse_registration( $tokens, $stmts[ $idx ] );
	return $abname;
}

/* ---- main ---- */
$o = parse_args( $argv );
if ( $o['help'] ) {
	usage();
	exit( 0 );
}
try {
	exit( run( $o ) );
} catch ( CoreifyError $e ) {
	fwrite( STDERR, 'coreify: ' . $e->getMessage() . "\n" );
	exit( 1 );
} catch ( Throwable $e ) {
	fwrite( STDERR, 'coreify: unexpected ' . get_class( $e ) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n" );
	exit( 1 );
}