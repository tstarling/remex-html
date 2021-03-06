<?php

namespace RemexHtml\TreeBuilder;

/**
 * A pseudo-element used as a marker or bookmark in the list of active formatting elements
 */
class Marker implements FormattingElement {
	public $nextAFE;
	public $prevAFE;
	public $type;

	public function __construct( $type ) {
		$this->type = $type;
	}
}

