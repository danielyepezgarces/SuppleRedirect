<?php
/**
 * SuppleRedirect MediaWiki extension (Fixed - NS_MAIN only)
 *
 * Redirects non-existing pages ONLY in the main namespace
 * when accessed directly (no internal referer).
 *
 * Safe for Widgets, Templates, Special pages, etc.
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
            if ( strpos( $title, $exclude ) === 0 ) {
                return true;
            }
        }

        return false;
    }

    private static function generateLocalURL( $title ) {
        global $wgSuppleRedirectBaseURL;

        $base = $wgSuppleRedirectBaseURL['default'] ?? '/wiki/';
        return $base . rawurlencode( $title );
    }

    /**
     * Modify red links (NS_MAIN only)
     */
    public static function onHtmlPageLinkRendererEnd(
        LinkRenderer $linkRenderer,
        LinkTarget $target,
        $isKnown,
        &$text,
        &$attribs,
        &$ret
    ) {

        if ( $isKnown || $target->isExternal() || self::checkConfig() ) {
            return true;
        }

        // 🚨 SOLO namespace principal
        if ( $target->getNamespace() !== NS_MAIN ) {
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
     * Redirect only when accessed directly (NS_MAIN only)
     */
    public static function onBeforeDisplayNoArticleText( $article ) {
        global $wgSuppleRedirectPermanently, $wgServer;

        if ( self::checkConfig() ) {
            return true;
        }

        $context = $article->getContext();
        $request = $context->getRequest();
        $title = $article->getTitle();

        // 🚨 SOLO namespace principal
        if ( !$title->inNamespace( NS_MAIN ) ) {
            return true;
        }

        // Permitir ?redirect=no
        if ( strcasecmp( $request->getText( 'redirect' ), 'no' ) === 0 ) {
            return true;
        }

        // Permitir edición/creación
        $action = $request->getVal( 'action' );
        if ( in_array( $action, [ 'edit', 'submit' ], true ) ) {
            return true;
        }

        $fulltitle = $title->getFullText();

        if ( self::checkExcludes( $fulltitle ) ) {
            return true;
        }

        // 🔍 Comprobar referer interno
        $referer = $request->getHeader( 'referer' );

        if ( !empty( $referer ) ) {

            $parsedReferer = parse_url( $referer );
            $parsedServer = parse_url( $wgServer );

            if (
                isset( $parsedReferer['host'], $parsedServer['host'] ) &&
                $parsedReferer['host'] === $parsedServer['host']
            ) {
                return true; // navegación interna permitida
            }
        }

        // 🔁 Redirigir
        $url = self::generateLocalURL( $fulltitle );

        $context->getOutput()->redirect(
            $url,
            $wgSuppleRedirectPermanently ? 301 : 307
        );

        return false;
    }
}
