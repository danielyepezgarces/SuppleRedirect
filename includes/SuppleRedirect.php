<?php
/**
 * SuppleRedirect MediaWiki extension version 1.2.0 (Advanced Direct Access Control)
 *
 * Redirects non-existing pages ONLY when accessed directly (no internal referer).
 * Allows normal creation flow when coming from inside the wiki.
 *
 * @license BSD-3-Clause
 */

if ( !defined( 'MEDIAWIKI' ) ) {
    echo "This file is an extension to MediaWiki and cannot be used standalone.\n";
    die( 1 );
}

use MediaWiki\Linker\LinkRenderer;
use MediaWiki\Linker\LinkTarget;
use MediaWiki\Title\Title;

class SuppleRedirect {

    private static function checkConfig() {
        global $wgSuppleRedirectBaseURL;
        return ( !is_array( $wgSuppleRedirectBaseURL ) || empty( $wgSuppleRedirectBaseURL ) );
    }

    private static function checkExcludes( $title ) {
        global $wgSuppleRedirectExcludes;

        if ( empty( $wgSuppleRedirectExcludes ) || !is_array( $wgSuppleRedirectExcludes ) ) {
            return false;
        }

        foreach ( $wgSuppleRedirectExcludes as $exclude ) {
            if ( strcmp( $title, $exclude ) === 0 ) {
                return true;
            }
        }

        return false;
    }

    private static function generateLocalURL( $title ) {
        global $wgSuppleRedirectBaseURL;

        $titleObj = Title::newFromText( $title );
        if ( !$titleObj ) {
            return '/wiki/' . rawurlencode( $title );
        }

        $ns = $titleObj->getNamespace();
        $base = $wgSuppleRedirectBaseURL[$ns]
            ?? $wgSuppleRedirectBaseURL['default']
            ?? '/wiki/';

        return $base . rawurlencode( $title );
    }

    /**
     * Modify red links
     */
    public static function onHtmlPageLinkRendererEnd(
        LinkRenderer $linkRenderer,
        LinkTarget $target,
        $isKnown,
        &$text,
        &$attribs,
        &$ret
    ) {
        global $wgContentNamespaces;

        if ( $isKnown || $target->isExternal() || self::checkConfig() ) {
            return true;
        }

        $ns = $target->getNamespace();

        if (
            ( !empty( $wgContentNamespaces ) && !in_array( $ns, $wgContentNamespaces, true ) )
            || ( empty( $wgContentNamespaces ) && $ns !== NS_MAIN )
        ) {
            return true;
        }

        $fulltitle = $target->getText();

        if ( self::checkExcludes( $fulltitle ) ) {
            return true;
        }

        $attribs['href'] = self::generateLocalURL( $fulltitle );
        $attribs['title'] = $fulltitle;
        $attribs['class'] = 'mw-redirect';

        unset( $attribs['data-redlink-url'], $attribs['data-redlink-title'] );

        return true;
    }

    /**
     * Redirect only when accessed directly (no internal referer)
     */
    public static function onBeforeDisplayNoArticleText( $article ) {
        global $wgContentNamespaces, $wgSuppleRedirectPermanently, $wgServer;

        if ( self::checkConfig() ) {
            return true;
        }

        $context = $article->getContext();
        $request = $context->getRequest();
        $title = $article->getTitle();

        // Manual bypass
        if ( strcasecmp( $request->getText( 'redirect' ), 'no' ) === 0 ) {
            return true;
        }

        // Allow creation/edit actions
        $action = $request->getVal( 'action' );
        if ( in_array( $action, [ 'edit', 'submit' ], true ) ) {
            return true;
        }

        $ns = $title->getNamespace();

        if (
            ( !empty( $wgContentNamespaces ) && !in_array( $ns, $wgContentNamespaces, true ) )
            || ( empty( $wgContentNamespaces ) && $ns !== NS_MAIN )
        ) {
            return true;
        }

        $fulltitle = $title->getFullText();

        if ( self::checkExcludes( $fulltitle ) ) {
            return true;
        }

        // 🔍 Check referer
        $referer = $request->getHeader( 'referer' );

        if ( !empty( $referer ) ) {

            $parsedReferer = parse_url( $referer );
            $parsedServer = parse_url( $wgServer );

            // Allow only if referer is same host
            if (
                isset( $parsedReferer['host'], $parsedServer['host'] ) &&
                $parsedReferer['host'] === $parsedServer['host']
            ) {
                return true; // internal navigation allowed
            }
        }

        // If no valid internal referer → redirect
        $url = self::generateLocalURL( $fulltitle );

        $context->getOutput()->redirect(
            $url,
            $wgSuppleRedirectPermanently ? 301 : 307
        );

        return false;
    }
}
