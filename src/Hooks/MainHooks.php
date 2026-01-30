<?php
/**
 * Main hooks for the Starlight extension.
 *
 * @file
 * @ingroup Extensions
 * @license AGPL-3.0-or-later
 */

namespace MediaWiki\Extension\Starlight\Hooks;

use MediaWiki\Hook\BeforePageDisplayHook;
use MediaWiki\Output\OutputPage;
use Skin;

class MainHooks implements BeforePageDisplayHook {

	/**
	 * Add Starlight modules to page output when needed.
	 *
	 * @param OutputPage $out
	 * @param Skin $skin
	 */
	public function onBeforePageDisplay( $out, $skin ): void {
		// Modules are added by the parser tag when <reviews> is used,
		// but we can add base styles here if needed for all pages
	}
}
