<?php
/**
 * SuppleRedirect MediaWiki extension version 1.1.1
 * Redirects non-existing pages to a local URL without calling REST API
 *
 * @file
 * @ingroup Extensions
 * @author Kimagurenote
 * @copyright © 2023-2025 Kimagurenote
 * @license The BSD 3-Clause License
 */

if( !defined( 'MEDIAWIKI' ) ) {
    echo "This file is an extension to the MediaWiki software and cannot be used standalone.\n";
    die( 1 );
}

use MediaWiki\Linker\LinkRenderer;
use MediaWiki\Linker\LinkTarget;
use MediaWiki\Title\Title;

class SuppleRedirect {

    /**
     * Configuration check
     * @return bool true = failed, false = ok
     */
    private static function checkConfig() {
        global $wgSuppleRedirectBaseURL;
        if( !is_array( $wgSuppleRedirectBaseURL ) || empty( $wgSuppleRedirectBaseURL ) ) {
            return true;
        }
        return false;
    }

    /**
     * Uppercase first character (UTF-8 safe)
     */
    private static function mb_ucfirst( $s ) {
        global $wgCapitalLinks, $wgCapitalLinkOverrides;

        if( $wgCapitalLinks === false ) {
            return $s;
        }

        if( function_exists( 'mb_substr' ) ) {
            $s = mb_strtoupper( mb_substr( $s, 0, 1 ) ) . mb_substr( $s, 1 );
        } else {
            $s = ucfirst( $s );
        }

        if( strpos( $s, ':' ) === false ) {
            return $s;
        }

        $title = Title::newFromText( $s );
        $ns = $title->getNamespace();
        $base = $title->getText();

        if( !empty( $wgCapitalLinkOverrides[$ns] ) && $wgCapitalLinkOverrides[$ns] === false ) {
            return $s;
        }

        return str_ireplace( $base, $base, $s );
    }

    /**
     * Check excluded titles
     */
    private static function checkExcludes( $title ) {
        global $wgSuppleRedirectExcludes;

        if( empty( $wgSuppleRedirectExcludes ) || !is_array( $wgSuppleRedirectExcludes ) ) {
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
     * Generate a local URL for non-existing pages
     */
    private static function generateLocalURL( $title ) {
        global $wgSuppleRedirectBaseURL;

        $ns = Title::newFromText($title)->getNamespace();
        $base = $wgSuppleRedirectBaseURL[$ns] ?? $wgSuppleRedirectBaseURL['default'] ?? '/wiki/';
        return $base . rawurlencode( $title );
    }

    /**
     * HtmlPageLinkRendererEnd hook
     */
    public static function onHtmlPageLinkRendererEnd( LinkRenderer $linkRenderer, LinkTarget $target, $isKnown, &$text, &$attribs, &$ret ) {
        global $wgContentNamespaces;

        if ( $isKnown || $target->isExternal() || self::checkConfig() ) {
            return true;
        }

        $ns = $target->getNamespace();
        if( !empty( $wgContentNamespaces ) && !in_array( $ns, $wgContentNamespaces, true ) ) {
            return true;
        } elseif( empty($wgContentNamespaces) && $ns != NS_MAIN ) {
            return true;
        }

        $fulltitle = $target->getText();
        if( self::checkExcludes( $fulltitle ) ) {
            return true;
        }

        // generate local URL
        $json = [
            'title' => $fulltitle,
            'url' => self::generateLocalURL($fulltitle)
        ];

        $attribs['href'] = $json['url'];
        $attribs['title'] = $json['title'];
        $attribs['class'] = "mw-redirect";
        unset( $attribs['data-redlink-url'] );
        unset( $attribs['data-redlink-title'] );

        return true;
    }

    /**
     * BeforeDisplayNoArticleText hook
     */
    public static function onBeforeDisplayNoArticleText( $article ) {
        global $wgContentNamespaces, $wgSuppleRedirectPermanently;

        if( self::checkConfig() ) {
            return true;
        }

        // redirect=no bypass
        if( strcasecmp( $article->getContext()->getRequest()->getText("redirect"), "no" ) === 0 ) {
            return true;
        }

        $ns = $article->getTitle()->getNamespace();
        if( !empty( $wgContentNamespaces ) && !in_array( $ns, $wgContentNamespaces, true ) ) {
            return true;
        } elseif( empty($wgContentNamespaces) && $ns != NS_MAIN ) {
            return true;
        }

        $fulltitle = $article->getTitle()->getFullText();
        if( self::checkExcludes( $fulltitle ) ) {
            return true;
        }

        // generate local URL
        $json = [
            'title' => $fulltitle,
            'url' => self::generateLocalURL($fulltitle)
        ];

        $url = $json['url'];
        $article->getContext()->getRequest()->response()->header(
            "Location: " . $url, true, $wgSuppleRedirectPermanently ? 301 : 307
        );
        $article->getContext()->getOutput()->addMeta("refresh", "1;URL=" . $url );

        return false;
    }

}
