<?php

namespace Wikimedia\RemexHtml\TreeBuilder;
use Wikimedia\RemexHtml\Tokenizer\Attributes;

class DOMBuilder implements TreeHandler {
	private $doc;
	private $errorCallback;

	public function __construct( $errorCallback = null ) {
		$this->errorCallback = $errorCallback;
	}

	public function getDocument() {
		return $this->doc;
	}

	public function startDocument() {
		$this->doc = new \DOMDocument;
	}

	public function endDocument( $pos ) {
	}

	private function insertNode( $preposition, $refElement, $node ) {
		if ( $preposition === TreeBuilder::ROOT ) {
			$parent = $this->doc;
			$refNode = null;
		} elseif ( $preposition === TreeBuilder::BEFORE ) {
			$parent = $refElement->userData->parentNode;
			$refNode = $refElement->userData;
		} else {
			$parent = $refElement->userData;
			$refNode = null;
		}
		$parent->insertBefore( $node, $refNode );
	}

	public function characters( $preposition, $refElement, $text, $start, $length,
		$sourceStart, $sourceLength
	) {
		$node = $this->doc->createTextNode( substr( $text, $start, $length ) );
		$this->insertNode( $preposition, $refElement, $node );
	}

	public function insertElement( $preposition, $refElement, Element $element, $void,
		$sourceStart, $sourceLength
	) {
		$node = $this->doc->createElementNS(
			$element->namespace,
			$element->name );

		foreach ( $element->getAttributeObjects() as $attr ) {
			if ( $attr->namespaceURI !== null ) {
				$node->setAttributeNS(
					$attr->namespaceURI,
					$attr->qualifiedName,
					$attr->value );
			} else {
				$node->setAttribute( $attr->localName, $attr->value );
			}
		}
		$this->insertNode( $preposition, $refElement, $node );
		$element->userData = $node;
	}

	public function endTag( Element $element, $sourceStart, $sourceLength ) {
	}

	public function doctype( $name, $public, $system, $quirks, $sourceStart, $sourceLength ) {
	}

	public function comment( $preposition, $refElement, $text, $sourceStart, $sourceLength ) {
		$node = $this->doc->createComment( $text );
		$this->insertNode( $preposition, $refElement, $node );
	}

	public function error( $text, $pos ) {
		if ( $this->errorCallback ) {
			call_user_func( $this->errorCallback, $text, $pos );
		}
	}

	public function mergeAttributes( Element $element, Attributes $attrs, $sourceStart ) {
		$node = $element->userData;
		foreach ( $attrs->getArrayCopy() as $name => $value ) {
			if ( !$node->hasAttribute( $name ) ) {
				$node->setAttribute( $name, $value );
			}
		}
	}

	public function reparentNode( Element $element, Element $newParent, $sourceStart ) {
		$node = $element->userData;
		$newParentNode = $newParent->userData;
		$newParentNode->appendChild( $node );
	}

	public function removeNode( Element $element, $sourceStart ) {
		$node = $element->userData;
		$node->parent->removeChild( $node );
	}

	public function reparentChildren( Element $element, Element $newParent, $sourceStart ) {
		$node = $element->userData;
		$newParentNode = $element->userData;
		foreach ( $node->childNodes as $child ) {
			$newParentNode->appendChild( $child );
		}
	}
}
