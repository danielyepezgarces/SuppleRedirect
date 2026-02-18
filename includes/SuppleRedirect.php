<?php
/**
 * SuppleRedirect MediaWiki extension  version 1.1.0
 *	for details please see: https://www.mediawiki.org/wiki/Extension:SuppleRedirect
 *
 * Copyright (c) 2023-2025 Kimagurenote https://kimagurenote.net/
 * License: Revised BSD license http://opensource.org/licenses/BSD-3-Clause
 *
 * Function:
 *	Mediawiki extension to provide supplementary redirect.
 *
 * Dependency:
 *	MediaWiki 1.35+
 *	using MediaWiki REST API
 *	https://www.mediawiki.org/wiki/API:REST_API/Reference
 *
 * History:
 * 2025.12.14 Version 1.1.0
 *	support $wgSuppleRedirectHeader, $wgSuppleRedirectTimeout
 * 2023.08.15 Version 1.0.0
 *	1st test
 *
 * @file
 * @ingroup Extensions
 * @author Kimagurenote
 * @copyright © 2023 Kimagurenote
 * @license The BSD 3-Clause License
 */

if( !defined( 'MEDIAWIKI' ) ) {
	echo( "This file is an extension to the MediaWiki software and cannot be used standalone.\n" );
	die( 1 );
}

use MediaWiki\Linker\LinkRenderer;
use MediaWiki\Linker\LinkTarget;

class SuppleRedirect {

	/**
	 * return
	 *	true	failed
	 *	false	success
	 */
	private static function checkConfig() {
		global $wgSuppleRedirectRestURL, $wgSuppleRedirectBaseURL;

		/* configuration check */
		if( !is_array( $wgSuppleRedirectRestURL ) or !is_array( $wgSuppleRedirectBaseURL ) ) {
			return true;
		}

		return false;
	}


	/**
	 * @param string $s
	 * return string
	 */
	private static function mb_ucfirst( $s ) {
		global $wgCapitalLinks, $wgCapitalLinkOverrides;

		/* https://www.mediawiki.org/wiki/Manual:$wgCapitalLinks */
		if( $wgCapitalLinks === false ) {
			return $s;
		}

		if( function_exists( 'mb_substr' ) ) {
			$s = mb_strtoupper( mb_substr( $s, 0, 1 ) ) . mb_substr( $s, 1 );
		} else {
			$s = ucfirst( $s );
		}

		/* string not include ':' (has no namespace) */
		if( strpos( $s, ':' ) === false ) {
			return $s;
		}

		/* string include ':' (has namespace?) */
		$title = Title::newFromText( $s );
		$ns = $title->getNamespace();
		$base = $title->getText();

		if( !empty( $wgCapitalLinkOverrides[$ns] ) and $wgCapitalLinkOverrides[$ns] === false ) {
			return $s;
		}

		return str_ireplace( $base, $base, $s );
	}


	/**
	 * @param array $title
	 * return
	 *	true	exclude
	 *	false	not exclude
	 */
	private static function checkExcludes( $title ) {
		global $wgSuppleRedirectExcludes;

		/* configuration check */
		if( empty( $wgSuppleRedirectExcludes ) or !is_array( $wgSuppleRedirectExcludes ) ) {
			return false;
		}

		$title = self::mb_ucfirst( $title );

		foreach( $wgSuppleRedirectExcludes as $i ) {
			if( strcmp( $title, self::mb_ucfirst( $i ) ) === 0 ) {
				return true;
			}
		}

		return false;
	}


	/**
	 * @param object $article
	 *	https://www.mediawiki.org/wiki/Manual:Article.php
	 * return
	 *	array	json+url
	 *	null	failed
	 */
	private static function callRestAPI( $title ) {
		global $wgSuppleRedirectRestURL, $wgSuppleRedirectBaseURL, $wgSuppleRedirectTimeout, $wgSuppleRedirectHeader, $wgServer;

		/* configuration check */
		if( !is_array( $wgSuppleRedirectRestURL ) or !is_array( $wgSuppleRedirectBaseURL ) ) {
			return null;
		}

		/* make headers */
		if( isset( $wgSuppleRedirectHeader ) ) {
			if( !is_array( $wgSuppleRedirectHeader ) ) {
				return null;
			}
			$header = $wgSuppleRedirectHeader;
		}
		if( !isset( $header['Content-Type'] ) ) {
			$header['Content-Type'] = "application/x-www-form-urlencoded";
		}
		if( !isset( $header['Referer'] ) ) {
			$header['Referer'] = $wgServer;
		}
		$headers = [];
		foreach( $header as $key => $value) {
			$headers[] = "$key: $value";
		}

		/* generate stream context */
		$stream = array(
			'http' => array(
				'method' => "GET",
				'header' => implode("\r\n", $headers),
			)
		);
		if( isset( $wgSubTranslateTimeout ) ) {
			$stream['timeout'] = (float)( $wgSuppleRedirectTimeout );
		}

		/* call Rest API */
		foreach( $wgSuppleRedirectRestURL as $key => $url ) {
			$ret = file_get_contents( $url . "/v1/search/title?limit=1&q=" . rawurlencode( $title ), false, stream_context_create( $stream ));

			if( !is_string( $ret ) ) {
				return null;
			}
			$json = json_decode( $ret, true );
			/* for debug
			echo "$key($url):\n";
			var_dump( $json );
			*/
			if( !empty( $json['pages'][0] ) ) {
				$json = $json['pages'][0];
				if( !empty( $json ) and !empty( $json['title'] ) ) {
					if( strcmp( $json['title'], $title ) === 0 ) {
						break;
					}
				}
			}
			unset( $key );
		}
		if( empty( $key ) or empty( $wgSuppleRedirectBaseURL[$key] ) or empty( $json['key'] ) ) {
			return null;
		}

		$json['url'] = $wgSuppleRedirectBaseURL[$key] . rawurlencode( $json['key'] );

		return $json;
	}


	/**
	 * @param LinkRenderer $linkRenderer
	 * @param LinkTarget $target
	 * @param $isKnown
	 * @param &$text
	 * @param &$attribs
	 * @param &$ret
	 *	https://www.mediawiki.org/wiki/Manual:Hooks/HtmlPageLinkRendererEnd
	 * return bool
	 *	true	生成処理を継続
	 *	false	生成処理を中断し、$ret で上書き
	 */
	public static function onHtmlPageLinkRendererEnd( LinkRenderer $linkRenderer, LinkTarget $target, $isKnown, &$text, &$attribs, &$ret ) {
		global $wgContentNamespaces, $wgSuppleRedirectPermanently;

		if ( $isKnown ) {
			return true;
		}

		if ( $target->isExternal() ) {
			return true;
		}

		/* configuration check */
		if( self::checkConfig() ) {
			return true;
		}

		/* get namespace */
		$ns = $target->getNamespace();

		if( empty( $wgContentNamespaces ) ) {
			if( $ns != NS_MAIN ) {
				return true;
			}
		} elseif ( !in_array( $ns, $wgContentNamespaces, true ) ) {
			return true;
		}

		/* get title */
		$fulltitle = $target->getText();

		/* check $wgSuppleRedirectExcludes */
		if( self::checkExcludes( $fulltitle ) ) {
			return true;
		}

		/* call Rest API */
		$json = self::callRestAPI( $fulltitle );
		if( empty( $json ) ) {
			return true;
		}

		/* set link */
		$attribs['href'] = $json['url'];
		$attribs['title'] = $json['title'];
		//$attribs['class'] = $linkRenderer->getLinkClasses( LinkTarget );
		$attribs['class'] = "mw-redirect";
		unset( $attribs['data-redlink-url'] );
		unset( $attribs['data-redlink-title'] );

		return true;
	}


	/**
	 * @param object $article
	 *	https://www.mediawiki.org/wiki/Manual:Article.php
	 * return bool
	 */
	public static function onBeforeDisplayNoArticleText( $article ) {
		global $wgContentNamespaces, $wgSuppleRedirectPermanently;

		/* configuration check */
		if( self::checkConfig() ) {
			return true;
		}

		/* redirect=no */
		// if( !empty( $_GET["redirect"] ) ) {
		// https://www.mediawiki.org/wiki/Manual:$wgRequest
		if( strcasecmp( $article->getContext()->getRequest()->getText("redirect"), "no" ) === 0 ) {
			return true;
		}

		/* get namespace */
		$ns = $article->getTitle()->getNamespace();

		if( empty( $wgContentNamespaces ) ) {
			if( $ns != NS_MAIN ) {
				return true;
			}
		} elseif ( !in_array( $ns, $wgContentNamespaces, true ) ) {
			return true;
		}

		/* get title */
		$fulltitle = $article->getTitle()->getFullText();

		/* check $wgSuppleRedirectExcludes */
		if( self::checkExcludes( $fulltitle ) ) {
			return true;
		}

		/* call Rest API */
		$json = self::callRestAPI( $fulltitle );
		if( empty( $json ) ) {
			return true;
		}

		/* set redirect */
		$url = $json['url'];
		// $article->getContext()->getRequest()->response()->statusHeader( $wgSuppleRedirectPermanently ? 301 : 307 );
		$article->getContext()->getRequest()->response()->header( "Location: " . $url, true, $wgSuppleRedirectPermanently ? 301 : 307 );
		$article->getContext()->getOutput()->addMeta("refresh", "1;URL=" . $url );

		return false;
	}

}
