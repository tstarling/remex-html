<?php

namespace Wikimedia\RemexHtml;

class Tokenizer {
	const STATE_START = 1;
	const STATE_DATA = 2;
	const STATE_RCDATA = 3;
	const STATE_RAWTEXT = 4;
	const STATE_SCRIPT_DATA = 5;
	const STATE_PLAINTEXT = 6;
	const STATE_EOF = 7;

	// Match indices for the data state regex
	const MD_END_TAG_OPEN = 1;
	const MD_TAG_NAME = 2;
	const MD_COMMENT = 3;
	const MD_COMMENT_END = 4;
	const MD_DOCTYPE = 5;
	const MD_DT_NAME_WS = 6;
	const MD_DT_NAME = 7;
	const MD_DT_PUBLIC_WS = 8;
	const MD_DT_PUBLIC_DQ = 9;
	const MD_DT_PUBLIC_SQ = 10;
	const MD_DT_PUBSYS_WS = 11;
	const MD_DT_PUBSYS_DQ = 12;
	const MD_DT_PUBSYS_SQ = 13;
	const MD_DT_SYSTEM_WS = 14;
	const MD_DT_SYSTEM_DQ = 15;
	const MD_DT_SYSTEM_SQ = 16;
	const MD_DT_BOGUS = 17;
	const MD_DT_END = 18;
	const MD_CDATA = 19;
	const MD_BOGUS_COMMENT = 20;

	// Match indices for the character reference regex
	const MC_PREFIX = 1;
	const MC_DECIMAL = 2;
	const MC_HEXDEC = 3;
	const MC_SEMICOLON = 4;
	const MC_HASH = 5;
	const MC_NAMED = 6;
	const MC_SUFFIX = 7;
	const MC_INVALID = 8;

	// Match indices for the attribute regex
	const MA_SLASH = 1;
	const MA_NAME = 2;
	const MA_DQUOTED = 3;
	const MA_SQUOTED = 4;
	const MA_UNQUOTED = 5;

	const REPLACEMENT_CHAR = "\xef\xbf\xbd";
	const BYTE_ORDER_MARK = "\xef\xbb\xbf";

	protected $ignoreErrors;
	protected $ignoreCharRefs;
	protected $ignoreNulls;
	protected $listener;
	protected $state;
	protected $preprocessed;

	public function __construct( TokenHandler $listener, $text, $options ) {
		$this->listener = $listener;
		$this->text = $text;
		$this->pos = 0;
		$this->preprocessed = false;
		$this->length = strlen( $text );
		$this->ignoreErrors = !empty( $options['ignoreErrors'] );
		$this->ignoreCharRefs = !empty( $options['ignoreCharRefs'] );
		$this->ignoreNulls = !empty( $options['ignoreNulls'] );
		$this->skipPreprocess = !empty( $options['skipPreprocess'] );
	}

	public function getPreprocessedText() {
		$this->preprocess();
		return $this->text;
	}

	protected function preprocess() {
		if ( $this->preprocessed || $this->skipPreprocess ) {
			return;
		}

		// Normalize line endings
		$this->text = strtr( $this->text, [
			"\r\n" => "\n",
			"\r" => "\n" ] );
		$this->length = strlen( $this->text );

		// Raise parse errors for any control characters
		if ( !$this->ignoreErrors ) {
			$pos = 0;
			$re = '/[' .
				'\x{0001}-\x{0008}' .
				'\x{000E}-\x{001F}' .
				'\x{007F}-\x{009F}' .
				'\x{FDD0}-\x{FDEF}' .
				'\x{000B}' .
				'\x{FFFE}\x{FFFF}' .
				'\x{1FFFE}\x{1FFFF}' .
				'\x{2FFFE}\x{2FFFF}' .
				'\x{3FFFE}\x{3FFFF}' .
				'\x{4FFFE}\x{4FFFF}' .
				'\x{5FFFE}\x{5FFFF}' .
				'\x{6FFFE}\x{6FFFF}' .
				'\x{7FFFE}\x{7FFFF}' .
				'\x{8FFFE}\x{8FFFF}' .
				'\x{9FFFE}\x{9FFFF}' .
				'\x{AFFFE}\x{AFFFF}' .
				'\x{BFFFE}\x{BFFFF}' .
				'\x{CFFFE}\x{CFFFF}' .
				'\x{DFFFE}\x{DFFFF}' .
				'\x{EFFFE}\x{EFFFF}' .
				'\x{FFFFE}\x{FFFFF}' .
				'\x{10FFFE}\x{10FFFF}]/u';
			while ( $pos < $this->length ) {
				if ( !preg_match( $re, $this->text, $m, PREG_OFFSET_CAPTURE, $pos ) ) {
					break;
				}
				$pos = $m[0][1];
				$this->error( "disallowed control character", $pos );
				$pos += strlen( $m[0][0] );
			}
		}
	}

	public function beginStepping() {
		$this->state = self::STATE_START;
		$this->preprocess();
	}

	public function step() {
		if ( $this->state === null ) {
			$this->fatal( "beginStepping() must be called before step()" );
		}
		return $this->executeInternal( false );
	}

	public function execute( $state = self::STATE_START, $appropriateEndTag = null ) {
		$this->state = $state;
		$this->appropriateEndTag = $appropriateEndTag;
		$this->preprocess();
		$this->executeInternal( true );
	}

	protected function executeInternal( $loop ) {
		$eof = false;

		do {
			switch ( $this->state ) {
			case self::STATE_DATA:
				$this->state = $this->dataState( $loop );
				break;
			case self::STATE_RCDATA:
				$this->state = $this->textElementState( false );
				break;
			case self::STATE_RAWTEXT:
				$this->state = $this->textElementState( true );
				break;
			case self::STATE_SCRIPT_DATA:
				$this->state = $this->scriptDataState();
				break;
			case self::STATE_PLAINTEXT:
				$this->state = $this->plaintextState();
				break;
			case self::STATE_START:
				$this->listener->startDocument();
				$this->state = self::STATE_DATA;
				break;
			case self::STATE_EOF:
				$this->listener->endDocument();
				$eof = true;
				break 2;
			default:
				$this->fatal( 'invalid state' );
			}
		} while ( $loop );

		return !$eof;
	}

	protected function dataState( $loop ) {
		$re = "~ <
			(?:
				( /? )                        # 1. End tag open

				(                             # 2. Tag name
					# Try to match the ASCII letter required for the start of a start
					# or end tag. If this fails, a slash matched above can be
					# backtracked and then fed into the bogus comment alternative below.
					[a-zA-Z]

					# Then capture the rest of the tag name
					[^\t\n\f />]*
				) |

				# Comment
				!--
				(?:
					-> | # Invalid short close
					(                         # 3. Comment contents
						-> | 
						(?:
							(?! --> )
							(?! --!> )
							[^>]
						)*+
					)
					(                         # 4. Comment close
						--> |   # Normal close
						--!> |  # Comment end bang
						> |     # Other invalid close
						        # EOF
					)
				) |
				( (?i)                        # 5. Doctype
					! DOCTYPE

					# There must be at least one whitespace character to suppress
					# a parse error, but if there isn't one, this is still a
					# DOCTYPE. There is no way for the DOCTYPE string to end up
					# as a character node, the DOCTYPE subexpression must always
					# wholly match if we matched up to this point.

					( [\t\n\f ]*+ )           # 6. Required whitespace
					( [^\t\n\f >]*+ )         # 7. DOCTYPE name
					[\t\n\f ]*+
					(?:
						# After DOCTYPE name state
						PUBLIC
						( [\t\n\f ]* )            # 8. Required whitespace
						(?:
							\" ( [^\">]* ) \"? |  # 9. Double-quoted identifier
							' ( [^'>]* ) '? |     # 10. Single-quoted identifier
							# Non-match: bogus
						)
						(?:
							# After DOCTYPE public identifier state
							# Assert quoted identifier before here
							(?<= \" | ' )
							( [\t\n\f ]* )            # 11. Required whitespace
							(?:
								\" ( [^\">]* ) \"? |  # 12. Double-quoted identifier
								' ( [^'>]* ) '? |     # 13. Single-quoted identifier
								# Non-match: no system ID
							)
						)?
						|
						SYSTEM
						( [\t\n\f ]* )            # 14. Required whitespace
						(?:
							\" ( [^\">]* ) \"? |  # 15. Double-quoted identifier
							' ( [^'>]* ) '? |     # 16. Single-quoted identifier
							# Non-match: bogus
						)
						|  # No keyword is OK
					)
					[\t\n\f ]*
					( [^>]*+ )                # 17. Bogus DOCTYPE
					( >? )                    # 18. End of DOCTYPE
				) |
				( ! \[CDATA\[ ) |             # 19. CDATA section
				( [!?/] [^>]*+ ) >?           # 20. Bogus comment

				# Anything else: parse error and emit literal less-than sign.
				# We will let the match fail at this position and later check
				# for less-than signs in the resulting text node.
			)
			~x";

		$nextState = self::STATE_DATA;
		do {
			$count = preg_match( $re, $this->text, $m, PREG_OFFSET_CAPTURE, $this->pos );
			if ( $count === false ) {
				$this->throwPregError();
			} elseif ( !$count ) {
				// Text runs to end
				$this->emitDataRange( $this->pos, $this->length - $this->pos );
				$this->pos = $this->length;
				$nextState = self::STATE_EOF;
				break;
			}

			$startPos = $m[0][1];
			$tagName = isset( $m[self::MD_TAG_NAME] ) ? $m[self::MD_TAG_NAME][0] : '';

			$this->emitDataRange( $this->pos, $startPos - $this->pos );
			$this->pos = $startPos;
			$nextPos = $m[0][1] + strlen( $m[0][0] );

			if ( strlen( $tagName ) ) {
				// Tag
				$isEndTag = (bool)strlen( $m[self::MD_END_TAG_OPEN][0] );
				if ( !$this->ignoreNulls ) {
					$tagName = $this->handleNulls( $tagName, $m[self::MD_TAG_NAME][1] );
				}
				$tagName = strtolower( $tagName );
				$this->pos = $nextPos;
				$attribs = $this->consumeAttribs();
				$eof = !$this->emitAndConsumeAfterAttribs( $tagName, $attribs, $isEndTag, $startPos );
				$nextPos = $this->pos;
				if ( $eof ) {
					$nextState = self::STATE_EOF;
					break;
				}

				// Respect any state switch imposed by the parser
				$nextState = $this->state;
			} elseif ( $m[0][0] === '<!--->' ) {
				$this->error( 'not enough dashes in empty comment', $startPos );
				$this->listener->comment( '', $startPos, $nextPos - $startPos );
			} elseif ( isset( $m[self::MD_COMMENT_END] ) && $m[self::MD_COMMENT_END][1] >= 0 ) {
				// Comment
				$this->interpretCommentMatches( $m );
			} elseif ( isset( $m[self::MD_DOCTYPE] ) && $m[self::MD_DOCTYPE][1] >= 0 ) {
				// DOCTYPE
				$this->interpretDoctypeMatches( $m );
			} elseif ( isset( $m[self::MD_CDATA] ) && $m[self::MD_CDATA][1] >= 0 ) {
				// CDATA
				$this->pos += strlen( $m[self::MD_CDATA][0] );
				$endPos = strpos( $this->text, ']]>', $this->pos );
				if ( $endPos === false ) {
					$this->emitCdataRange( $this->pos, $this->length - $this->pos,
						$startPos, $this->length - $startPos );
					$this->pos = $this->length;
					$nextState = self::STATE_EOF;
					break;
				} else {
					$outerEndPos = $endPos + strlen( ']]>' );
					$this->emitCdataRange( $this->pos, $endPos - $this->pos,
						$startPos, $outerEndPos - $startPos );
					$nextPos = $outerEndPos;
				}
			} elseif ( isset ( $m[self::MD_BOGUS_COMMENT] ) && $m[self::MD_BOGUS_COMMENT][1] >= 0 ) {
				// Bogus comment
				$contents = $m[self::MD_BOGUS_COMMENT][0];
				$bogusPos = $m[self::MD_BOGUS_COMMENT][1];
				if ( $m[0][0] === '</>' ) {
					$this->error( "empty end tag" );
					// No token emitted
				} elseif ( $m[0][0] === '</' ) {
					$this->error( 'EOF in end tag' );
					$this->listener->characters( '</', 0, 2, $m[0][1], 2 );
				} else {
					$this->error( "unexpected <{$contents[0]} interpreted as bogus comment" );
					if ( $contents[0] !== '?' ) {
						// For starting types other than <?, the initial character is
						// not in the tag contents
						$contents = substr( $contents, 1 );
						$bogusPos++;
					}

					$contents = $this->handleNulls( $contents, $bogusPos );
					$this->listener->comment( $contents, $startPos, $nextPos - $startPos );
				}
			} else {
				$this->fatal( 'unexpected data state match' );
			}
			$this->pos = $nextPos;
		} while ( $loop && $nextState === self::STATE_DATA );

		return $nextState;
	}

	protected function interpretCommentMatches( $m ) {
		$outerStart = $m[0][1];
		$outerLength = strlen( $m[0][0] );
		$innerStart = $outerStart + strlen( '<!--' );
		$innerLength = isset( $m[self::MD_COMMENT] ) ? strlen( $m[self::MD_COMMENT][0] ) : 0;
		$contents = $innerLength ? $m[self::MD_COMMENT][0] : '';
		if ( !$this->ignoreNulls ) {
			$contents = $this->handleNulls( $contents, $innerStart );
		}

		if ( !$this->ignoreErrors ) {
			$close = $m[4][0];
			$closePos = $m[4][1];
			if ( $close === '--!>' ) {
				$this->error( 'invalid comment end bang', $closePos );
			} elseif ( $close === '>' ) {
				$this->error( 'comment ended without dashes', $closePos );
			} elseif ( $close === '' ) {
				$this->error( 'EOF in comment', $closePos );
			}

			$dashSearchLength = $innerLength;
			while ( $dashSearchLength > 0 && $contents[$dashSearchLength - 1] === '-' ) {
				$this->error( 'invalid extra dash at comment end',
					$innerStart + $dashSearchLength - 1 );
				$dashSearchLength--;
			}

			$offset = 0;
			while ( $offset !== false && $offset < $dashSearchLength ) {
				$offset = strpos( $contents, '--', $offset );
				if ( $offset !== false ) {
					$this->error( 'bare "--" found in comment', $innerStart + $offset );
					$offset += 2;
				}
			}
		}

		$this->listener->comment( $contents, $outerStart, $outerLength );
	}

	protected function interpretDoctypeMatches( $m ) {
		$igerr = $this->ignoreErrors;
		$name = null;
		$public = null;
		$system = null;
		$quirks = false;
		$eof = false;

		if ( !strlen( $m[self::MD_DT_END][0] ) ) {
			// Missing ">" can only be caused by EOF
			if ( !$igerr ) {
				$this->error( 'unterminated DOCTYPE' );
			}
			$quirks = true;
			$eof = true;
		}

		if ( strlen( $m[self::MD_DT_BOGUS][0] ) ) {
			// Bogus DOCTYPE state
			if ( !$igerr ) {
				$this->error( 'invalid DOCTYPE contents', $m[12][0] );
			}
			$quirks = true;
		}

		if ( !$igerr && !$eof && !strlen( $m[self::MD_DT_NAME_WS][0] ) ) {
			$this->error( 'missing whitespace', $m[self::MD_DT_NAME_WS][1] );
		}

		if ( strlen( $m[self::MD_DT_NAME][0] ) ) {
			// DOCTYPE name
			$name = strtolower( $m[self::MD_DT_NAME][0] );
		} else {
			if ( !$eof && !$igerr ) {
				$this->error( 'missing DOCTYPE name',
					$m[self::MD_DOCTYPE][1] + strlen( '!DOCTYPE' ) );
			}
			$quirks = true;
		}

		if ( isset( $m[self::MD_DT_PUBLIC_WS] ) && $m[self::MD_DT_PUBLIC_WS][1] >= 0 ) {
			// PUBLIC keyword found
			if ( !$igerr && !$eof && !strlen( $m[self::MD_DT_PUBLIC_WS][0] ) ) {
				$this->error( 'missing whitespace', $m[self::MD_DT_PUBLIC_WS][1] );
			}
			$public = $this->interpretDoctypeQuoted( $m,
				self::MD_DT_PUBLIC_DQ, self::MD_DT_PUBLIC_SQ, $quirks );
			if ( $public === null && !$eof && !$igerr ) {
				$this->error( 'missing public identifier', $m[self::MD_DT_PUBLIC_WS][1] );
			}

			// Check for a system ID after the public ID
			$haveDq = isset( $m[self::MD_DT_PUBSYS_DQ] ) && $m[self::MD_DT_PUBSYS_DQ][1] >= 0;
			$haveSq = isset( $m[self::MD_DT_PUBSYS_SQ] ) && $m[self::MD_DT_PUBSYS_SQ][1] >= 0;
			if ( $haveDq || $haveSq ) {
				if ( !$igerr && !strlen( $m[self::MD_DT_PUBSYS_WS][0] ) ) {
					$this->error( 'missing whitespace', $m[self::MD_DT_PUBSYS_WS][1] );
				}
				$system = $this->interpretDoctypeQuoted( $m, 
					self::MD_DT_PUBSYS_DQ, self::MD_DT_PUBSYS_SQ, $quirks );
			}
		} elseif ( isset( $m[self::MD_DT_SYSTEM_WS] ) && $m[self::MD_DT_SYSTEM_WS][1] >= 0 ) {
			// SYSTEM keyword found
			if ( !$igerr && !strlen( $m[self::MD_DT_SYSTEM_WS][0] ) ) {
				$this->error( 'missing whitespace', $m[self::MD_DT_SYSTEM_WS][1] );
			}
			$system = $this->interpretDoctypeQuoted( $m,
				self::MD_DT_SYSTEM_DQ, self::MD_DT_SYSTEM_SQ, $quirks );
		}
		$this->listener->doctype( $name, $public, $system, $quirks, $m[0][1], strlen( $m[0][0] ) );
	}

	protected function interpretDoctypeQuoted( $m, $dq, $sq, &$quirks ) {
		if ( isset( $m[$dq] ) && $m[$dq][1] >= 0 ) {
			$value = $m[$dq][0];
			$endPos = $m[$dq][1] + strlen( $value );
		} elseif ( isset( $m[$sq] ) && $m[$sq][1] >= 0 ) {
			$value = $m[$sq][0];
			$endPos = $m[$sq][1] + strlen( $value );
		} else {
			return null;
		}
		if ( !$this->ignoreErrors && $this->text[$endPos] === '>' ) {
			$this->error( 'DOCTYPE identifier terminated by ">"', $endPos );
			$quirks = true;
		}
		return $value;
	}

	protected function handleNulls( $text, $sourcePos ) {
		if ( $this->ignoreNulls ) {
			return $text;
		}
		if ( !$this->ignoreErrors ) {
			while ( true ) {
				$nullPos = strpos( $text, "\0" );
				if ( $nullPos === false ) {
					break;
				}
				$this->error( "replaced null character", $sourcePos + $nullPos );
				if ( $nullPos < strlen( $text ) - 1 ) {
					$nullPos = strpos( $text, "\0", $nullPos + 1 );
				} else {
					break;
				}
			}
		}
		return str_replace( "\0", self::REPLACEMENT_CHAR, $text );
	}

	protected function handleAsciiErrors( $mask, $text, $offset, $length, $sourcePos ) {
		while ( $length > 0 ) {
			$validLength = strcspn( $text, $mask, $offset, $length );
			$offset += $validLength;
			$length -= $validLength;
			if ( $length <= 0 ) {
				break;
			}
			$char = $text[$offset];
			$codepoint = ord( $char );
			if ( $codepoint < 0x20 || $codepoint >= 0x7f ) {
				$this->error( sprintf( 'unexpected U+00%02X', $codepoint ), $offset + $sourcePos );
			} else {
				$this->error( "unexpected \"$char\"", $offset + $sourcePos );
			}
			$offset++;
			$length--;
		}
	}

	protected function handleCharRefs( $text, $sourcePos, $inAttr = false, $additionalAllowedChar = '' ) {
		if ( $this->ignoreCharRefs ) {
			return $text;
		}
		// Efficiently translate a few common cases.
		// Although this doesn't translate any error cases, running this
		// function in !$ignoreError mode would cause the string offsets to
		// be wrong when we come to the preg_match_all.
		// Note that the table was missing about 500 named character entites
		// in HHVM 3.12 compared to HTML 5 as published on 28 October 2014.
		if ( $this->ignoreErrors ) {
			$text = html_entity_decode( $text, ENT_HTML5 | ENT_QUOTES );
		}

		static $re;
		if ( $re === null ) {
			$knownNamed = HTMLData::$namedEntityRegex;
			$re = "~
				( .*? )                      # 1. prefix
				&
				(?:
					\# (?:
						0*(\d+)           |  # 2. decimal
						x0*([0-9A-Fa-f]+)    # 3. hexadecimal
					)
					( ; ) ?                  # 4. semicolon
					|
					( \# )                   # 5. bare hash
					|
					($knownNamed)            # 6. known named
					(?:
						(?<! ; )             # Assert no semicolon prior
						( [=a-zA-Z0-9] )     # 7. attribute suffix
					)?
					|
					( [a-zA-Z0-9]+ ; )       # 8. invalid named
				)
				# S = study, for efficient knownNamed
				# A = anchor, to avoid unnecessary movement of the whole pattern on failure
				~xAsS";
		}
		$out = '';
		$pos = 0;
		$length = strlen( $text );
		$matches = [];
		$count = preg_match_all( $re, $text, $matches, PREG_SET_ORDER );
		if ( $count === false ) {
			$this->throwPregError();
		}

		foreach ( $matches as $m ) {
			$out .= $m[self::MC_PREFIX];
			$errorPos = $sourcePos + $pos + strlen( $m[self::MC_PREFIX] );
			$lastPos = $pos;
			$pos += strlen( $m[0] );

			if ( isset( $m[self::MC_HASH] ) && strlen( $m[self::MC_HASH] ) ) {
				// Bare &#
				$this->error( 'Expected digits after &#', $errorPos );
				$out .= '&#';
				continue;
			}

			$knownNamed = isset( $m[self::MC_NAMED] ) ? $m[self::MC_NAMED] : '';
			$attributeSuffix = isset( $m[self::MC_SUFFIX] ) ? $m[self::MC_SUFFIX] : '';

			$haveSemicolon =
				( isset( $m[self::MC_SEMICOLON] ) && strlen( $m[self::MC_SEMICOLON] ) )
				|| ( strlen( $knownNamed ) && $knownNamed[ strlen( $knownNamed ) - 1 ] === ';' )
				|| ( isset( $m[self::MC_INVALID] ) && strlen( $m[self::MC_INVALID] ) );

			if ( $inAttr && !$haveSemicolon ) {
				if ( strlen( $attributeSuffix ) ) {
					if ( !$this->ignoreErrors && $attributeSuffix === '=' ) {
						$this->error( 'invalid equals sign after named character reference' );
					}
					$out .= '&' . $knownNamed . $attributeSuffix;
					continue;
				}
			}

			if ( !$this->ignoreErrors && !$haveSemicolon ) {
				$this->error( 'character reference missing semicolon', $errorPos );
			}

			if ( isset( $m[self::MC_DECIMAL] ) && strlen( $m[self::MC_DECIMAL] ) ) {
				// Decimal
				if ( strlen( $m[self::MC_DECIMAL] ) > 7 ) {
					$this->error( 'invalid numeric reference', $errorPos );
					$out .= self::REPLACEMENT_CHAR;
					continue;
				}
				$codepoint = intval( $m[self::MC_DECIMAL] );
			} elseif ( isset( $m[self::MC_HEXDEC] ) && strlen( $m[self::MC_HEXDEC] ) ) {
				// Hexadecimal
				if ( strlen( $m[self::MC_HEXDEC] ) > 6 ) {
					$this->error( 'invalid numeric reference', $errorPos );
					$out .= self::REPLACEMENT_CHAR;
					continue;
				}
				$codepoint = intval( $m[self::MC_HEXDEC], 16 );
			} elseif ( $knownNamed !== '' ) {
				$out .= HTMLData::$namedEntityTranslations[$knownNamed] . $attributeSuffix;
				continue;
			} elseif ( isset( $m[self::MC_INVALID] ) && strlen( $m[self::MC_INVALID] ) ) {
				if ( !$this->ignoreErrors ) {
					$this->error( 'invalid named reference', $errorPos );
				}
				$out .= '&' . $m[self::MC_INVALID];
				continue;
			} else {
				$this->fatal( 'unable to identify char ref submatch' );
			}

			// Interpret $codepoint
			if ( $codepoint === 0
				|| ( $codepoint >= 0xD800 && $codepoint <= 0xDFFF )
				|| $codepoint > 0x10FFFF
			) {
				if ( !$this->ignoreErrors ) {
					$this->error( 'invalid numeric reference', $errorPos );
				}
				$out .= self::REPLACEMENT_CHAR;
			} elseif ( isset( HTMLData::$legacyNumericEntities[$codepoint] ) ) {
				if ( !$this->ignoreErrors ) {
					$this->error( 'invalid reference to non-ASCII control character', $errorPos );
				}
				$out .= HTMLData::$legacyNumericEntities[$codepoint];
			} else {
				if ( !$this->ignoreErrors ) {
					$disallowedCodepoints = [
						0x000B => true,
						0xFFFE => true, 0xFFFF => true,
						0x1FFFE => true, 0x1FFFF => true,
						0x2FFFE => true, 0x2FFFF => true,
						0x3FFFE => true, 0x3FFFF => true,
						0x4FFFE => true, 0x4FFFF => true,
						0x5FFFE => true, 0x5FFFF => true,
						0x6FFFE => true, 0x6FFFF => true,
						0x7FFFE => true, 0x7FFFF => true,
						0x8FFFE => true, 0x8FFFF => true,
						0x9FFFE => true, 0x9FFFF => true,
						0xAFFFE => true, 0xAFFFF => true,
						0xBFFFE => true, 0xBFFFF => true,
						0xCFFFE => true, 0xCFFFF => true,
						0xDFFFE => true, 0xDFFFF => true,
						0xEFFFE => true, 0xEFFFF => true,
						0xFFFFE => true, 0xFFFFF => true,
						0x10FFFE => true, 0x10FFFF => true];
					if (
						( $codepoint >= 1 && $codepoint <= 8 ) ||
						( $codepoint >= 0x0d && $codepoint <= 0x1f ) ||
						( $codepoint >= 0x7f && $codepoint <= 0x9f ) ||
						( $codepoint >= 0xfdd0 && $codepoint <= 0xfdef ) ||
						isset( $disallowedCodepoints[$codepoint] )
					) {
						$this->error( 'invalid numeric reference to control character',
							$errorPos );
					}
				}

				$out .= \UtfNormal\Utils::codepointToUtf8( $codepoint );
			}
		}
		if ( $pos < $length ) {
			$out .= substr( $text, $pos );
		}
		return $out;
	}

	protected function emitDataRange( $pos, $length ) {
		if ( $length === 0 ) {
			return;
		}
		if ( $this->ignoreCharRefs && $this->ignoreNulls && $this->ignoreErrors ) {
			$this->listener->characters( $this->text, $pos, $length, $pos, $length );
		} else {
			if ( !$this->ignoreErrors ) {
				// Any bare "<" in a data state text node is a parse error.
				// Uniquely to the data state, nulls are just flagged as errors
				// and passed through, they are not replaced.
				$this->handleAsciiErrors( "<\0", $this->text, $pos, $length, 0 );
			}

			$text = substr( $this->text, $pos, $length );
			$text = $this->handleCharRefs( $text, $pos );
			$this->listener->characters( $text, 0, strlen( $text ), $pos, $length );
		}
	}

	protected function emitCdataRange( $innerPos, $innerLength, $outerPos, $outerLength ) {
		$this->listener->characters( $this->text, $innerPos, $innerLength,
			$outerPos, $outerLength );
	}

	protected function emitRawTextRange( $ignoreCharRefs, $pos, $length ) {
		if ( $length === 0 ) {
			return;
		}
		$ignoreCharRefs = $ignoreCharRefs || $this->ignoreCharRefs;
		if ( $ignoreCharRefs && $this->ignoreNulls ) {
			$this->listener->characters( $this->text, $pos, $length, $pos, $length );
		} else {
			$text = substr( $this->text, $pos, $length );
			if ( !$ignoreCharRefs ) {
				$text = $this->handleCharRefs( $text, $pos );
			}
			$text = $this->handleNulls( $text, $pos );
			$this->listener->characters( $text, 0, strlen( $text ), $pos, $length );
		}
	}

	public function switchState( $state, $appropriateEndTag ) {
		$this->state = $state;
		$this->appropriateEndTag = $appropriateEndTag;
	}

	protected function textElementState( $ignoreCharRefs ) {
		if ( $this->appropriateEndTag === null ) {
			$this->emitRawTextRange( $ignoreCharRefs, $this->pos, $this->length - $this->pos );
			$this->pos = $this->length;
			return self::STATE_EOF;
		}

		$re = "~< # RCDATA/RAWTEXT less-than sign state
			/     # RCDATA/RAWTEXT end tag open state
			{$this->appropriateEndTag}  # ASCII letters
			(?:
				( [\t\n\f ]*/> ) |            # 1. Self-closing tag
				( [\t\n\f ]*/ ) |             # 2. Broken self-closing tag
				( [\t\n\f ]*> ) |             # 3. Emit end tag token
				( [\t\n\f ]+ )                # 4. Attribute state
			)
			~ix";

		$count = preg_match( $re, $this->text, $m, PREG_OFFSET_CAPTURE, $this->pos );

		if ( $count === false ) {
			$this->throwPregError();
		} elseif ( !$count ) {
			// Text runs to end
			$this->emitRawTextRange( $ignoreCharRefs, $this->pos, $this->length - $this->pos );
			$this->pos = $this->length;
			return self::STATE_EOF;
		}
		$startPos = $m[0][1];
		$selfCloseMatch = isset( $m[1] ) ? $m[1][0] : '';
		$brokenSelfCloseMatch = isset( $m[2] ) ? $m[2][0] : '';
		$endTagMatch = isset( $m[3] ) ? $m[3][0] : '';

		// Emit text before tag
		$this->emitRawTextRange( $ignoreCharRefs, $this->pos, $startPos - $this->pos );

		$matchLength = strlen( $m[0][0] );
		$this->pos = $startPos + $matchLength;
		if ( strlen( $selfCloseMatch ) ) {
			// FIXME: unacknowledged self close needs handling?
			$this->listener->endTag( $this->appropriateEndTag, $startPos, $matchLength );
			return self::STATE_DATA;
		} elseif ( strlen( $brokenSelfCloseMatch ) ) {
			if ( $this->pos >= $this->length ) {
				$this->error( 'unclosed RCDATA/RAWTEXT element', $startPos );
				return self::STATE_EOF;
			} else {
				$this->error( 'unexpected character' );
				// Switch to the before attribute name state
			}
		} elseif ( strlen( $endTagMatch ) ) {
			$this->listener->endTag( $this->appropriateEndTag, $startPos, $matchLength );
			return self::STATE_DATA;
		} // else whitespace, go to attribute state

		// Before attribute name state
		$attribs = $this->consumeAttribs();
		$eof = !$this->emitAndConsumeAfterAttribs( $this->appropriateEndTag, $attribs,
			true, $startPos );
		if ( $eof ) {
			return self::STATE_EOF;
		} else {
			return self::STATE_DATA;
		}
	}

	protected function consumeAttribs() {
		$re = '~
			[\t\n\f ]*+  # Ignored whitespace before attribute name
			(?! /> )     # Do not consume self-closing end of tag
			(?! > )      # Do not consume normal closing bracket

			(?:
				# Before attribute name state
				# A bare slash at this point, not part of a self-closing end tag, is
				# consumed and ignored (with a parse error), returning to the before
				# attribute name state.
				( / ) |    # 1. Bare slash

				# Attribute name state
				# Note that the first character can be an equals sign, this is a parse error
				# but still generates an attribute called "=". Thus the only way the match
				# could fail here is due to EOF.

				( [^\t\n\f />] [^\t\n\f =/>]*+ )  # 2. Attribute name

				(?:
					=
					# Before attribute value state
					# Ignore whitespace
					[\t\n\f ]*+
					(?:
						# If an end-quote is omitted, the attribute will run to the end of the
						# string, leaving no closing bracket. So the caller will detect the
						# unexpected EOF and will not emit the tag, which is correct.
						" ( [^"]*+ ) "? |       # 3. Double-quoted attribute value
						\' ( [^\']*+ ) \'? |    # 4. Single-quoted attribute value
						( [^\t\n\f >]*+ )       # 5. Unquoted attribute value
					)
					# Or nothing: an attribute with an empty value. The attribute name was
					# terminated by a slash, closing bracket or EOF
					|
				)
			)
			# The /A modifier causes preg_match_all to give contiguous chunks
			~xA';
		$count = preg_match_all( $re, $this->text, $m,
			PREG_SET_ORDER | PREG_OFFSET_CAPTURE, $this->pos );
		if ( $count === false ) {
			$this->throwPregError();
		} elseif ( $count ) {
			$this->pos = $m[$count - 1][0][1] + strlen( $m[$count - 1][0][0] );
			$attribs = new LazyAttributes( $m, function ( $m ) {
				return $this->interpretAttribMatches( $m );
			} );
		} else {
			$attribs = new PlainAttributes();
		}

		// Consume trailing whitespace. This is strictly part of the before attribute
		// name state, but we didn't consume it in the regex since we used a principle
		// of one match equals one attribute.
		$this->pos += strspn( $this->text, "\t\n\f ", $this->pos );
		return $attribs;
	}

	protected function interpretAttribMatches( $matches ) {
		$attributes = [];
		foreach ( $matches as $m ) {
			if ( strlen( $m[self::MA_SLASH][0] ) ) {
				$this->error( 'unexpected bare slash', $m[self::MA_SLASH][1] );
				continue;
			}
			if ( $this->ignoreNulls ) {
				$name = $m[self::MA_NAME][0];
			} else {
				$name = $this->handleNulls( $m[self::MA_NAME][0], $m[self::MA_NAME][1] );
			}
			$name = strtolower( $name );
			$additionalAllowedChar = '';
			if ( isset( $m[self::MA_DQUOTED] ) && strlen( $m[self::MA_DQUOTED][0] ) ) {
				// Double-quoted attribute value
				$additionalAllowedChar = '"';
				$value = $m[self::MA_DQUOTED][0];
				$pos = $m[self::MA_DQUOTED][1];
			} elseif ( isset( $m[self::MA_SQUOTED] ) && strlen( $m[self::MA_SQUOTED][0] ) ) {
				// Single-quoted attribute value
				$additionalAllowedChar = "'";
				$value = $m[self::MA_SQUOTED][0];
				$pos = $m[self::MA_SQUOTED][1];
			} elseif ( isset( $m[self::MA_UNQUOTED] ) && strlen ( $m[self::MA_UNQUOTED][0] ) ) {
				// Unquoted attribute value
				$value = $m[self::MA_UNQUOTED][0];
				$pos = $m[self::MA_UNQUOTED][1];
				// Search for parse errors
				if ( !$this->ignoreErrors ) {
					$this->handleAsciiErrors( "\"'<=`", $value, 0, strlen( $value ), $pos );
				}
			} else {
				$value = '';
			}
			if ( $additionalAllowedChar && !$this->ignoreErrors ) {
				// After attribute value (quoted) state
				// Quoted attributes must be followed by a space, "/" or ">"
				$aavPos = $m[0][1] + strlen( $m[0][0] );
				if ( $aavPos < $this->length ) {
					$aavChar = $this->text[$aavPos];
					if ( !preg_match( '~^[\t\n\f />]~', $aavChar ) ) {
						$this->error( 'missing space between attributes', $aavPos );
					}
				}
			}
			if ( $value !== '' ) {
				if ( !$this->ignoreNulls ) {
					$value = $this->handleNulls( $value, $pos );
				}
				if ( !$this->ignoreCharRefs ) {
					$value = $this->handleCharRefs( $value, $pos, true, $additionalAllowedChar );
				}
			}
			if ( isset( $attributes[$name] ) ) {
				$this->error( "duplicate attribute", $m[0][1] );
			} else {
				$attributes[$name] = $value;
			}
		}
		return $attributes;
	}

	protected function printContext( $pos ) {
		$contextStart = max( $pos - 20, 0 );
		print str_replace( "\n", "¶", substr( $this->text, $contextStart, 40 ) ) . "\n";
		print str_repeat( ' ', $pos - $contextStart ) . "^\n";
	}

	protected function emitAndConsumeAfterAttribs( $tagName, $attribs, $isEndTag, $startPos ) {
		$pos = $this->pos;
		if ( $pos >= $this->length ) {
			$this->error( 'unexpected end of file inside tag' );
			return false;
		}
		if ( $isEndTag && !$this->ignoreErrors && $attribs->count() ) {
			$this->error( 'end tag has an attribute' );
		}

		if ( $this->text[$pos] === '/' && $this->text[$pos + 1] === '>' ) {
			$pos += 2;
			$selfClose = true;
		} elseif ( $this->text[$pos] === '>' ) {
			$pos++;
			$selfClose = false;
		} else {
			$this->fatal( 'failed to find an already-matched ">"' );
		}
		$this->pos = $pos;
		if ( $isEndTag ) {
			if ( $selfClose ) {
				$this->error( 'self-closing end tag' );
			}
			$this->listener->endTag( $tagName, $startPos, $pos - $startPos );
		} else {
			$this->listener->startTag( $tagName, $attribs, $selfClose, $startPos, $pos - $startPos );
		}
		return true;
	}

	protected function plaintextState() {
		$this->emitRawTextRange( true, $this->pos, $this->length - $this->pos );
		return self::STATE_EOF;
	}

	protected function scriptDataState() {
		if ( $this->appropriateEndTag === null ) {
			$this->pos = $this->length;
			return self::STATE_EOF;
		}
		$re = <<<REGEX
~
			(?: # Outer loop start
				# Script data state
				# Stop iteration if we previously matched an appropriate end tag.
				# This is a conditional subpattern: if capture 1 previously
				# matched, then run the pattern /$./ which always fails.
				(?(1) $. )
				.*?
				(?:
					$ |
					( </ {$this->appropriateEndTag} ) |       # 1. Appropriate end tag
					<!--
					# Script data escaped dash dash state
					# Hyphens at this point are consumed without a state transition
					# and so are not part of a comment-end.
					-*+

					(?: # Inner loop start
						# Script data escaped state
						.*?
						(?:
							$ |
							# Stop at, but do not consume, comment-close or end tag.
							# This causes the inner loop to exit, since restarting the
							# inner loop at this input position will cause the loop
							# body to match zero characters. Repeating a zero-character
							# match causes the repeat to terminate.
							(?= --> ) |
							(?= </ {$this->appropriateEndTag} ) |
							<script [\t\n\f />]
							# Script data double escaped state
							.*?
							(?:
								$ |
								# Stop at, but do not consume, comment-close
								(?= --> ) |
								</script [\t\n\f />]
							)
						)
					)*+


					# Consume the comment close which exited the inner loop, if any
					(?: --> )?
				)
			)*+
			~xsiA
REGEX;

		$count = preg_match( $re, $this->text, $m, 0, $this->pos );
		if ( $count === false ) {
			$this->throwPregError();
		} elseif ( !$count ) {
			$this->fatal( 'unexpected regex failure: this pattern can match zero characters' );
		}

		if ( !isset( $m[1] ) || $m[1] === '' ) {
			// EOF in script data state: no text node emitted
			$this->pos = $this->length;
			return self::STATE_EOF;
		}

		$startPos = $this->pos;
		$matchLength = strlen( $m[0] );
		$textLength = $matchLength - strlen( $m[1] );
		$this->emitRawTextRange( true, $startPos, $textLength );
		$this->pos = $startPos + $matchLength;
		$attribs = $this->consumeAttribs();
		$eof = !$this->emitAndConsumeAfterAttribs( $this->appropriateEndTag, $attribs,
			true, $startPos );
		if ( $eof ) {
			return self::STATE_EOF;
		} else {
			return self::STATE_DATA;
		}
	}

	protected function error( $text, $pos = null ) {
		if ( !$this->ignoreErrors ) {
			if ( $pos === null ) {
				$pos = $this->pos;
			}
			$this->listener->error( $text, $pos );
		}
	}

	protected function fatal( $text ) {
		throw new \Exception( __CLASS__ . ": " . $text );
	}

	protected function throwPregError() {
		switch ( preg_last_error() ) {
		case PREG_NO_ERROR:
			$msg = "PCRE returned false but gave PREG_NO_ERROR";
			break;
		case PREG_INTERNAL_ERROR:
			$msg = "PCRE internal error";
			break;
		case PREG_BACKTRACK_LIMIT_ERROR:
			$msg = "pcre.backtrack_limit exhausted";
			break;
		case PREG_RECURSION_LIMIT_ERROR:
			$msg = "pcre.recursion_limit exhausted";
			break;
		case PREG_JIT_STACKLIMIT_ERROR:
			$msg = "PCRE JIT stack space exhausted";
			break;
		case PREG_BAD_UTF8_ERROR:
		case PREG_BAD_UTF8_OFFSET_ERROR:
		default:
			$msg = "PCRE unexpected error";
		}

		throw new \Exception( __CLASS__.": $msg" );
	}
}
