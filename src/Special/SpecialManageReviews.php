<?php
/**
 * Special page for managing/moderating reviews.
 *
 * @file
 * @ingroup Extensions
 * @license AGPL-3.0-or-later
 */

namespace MediaWiki\Extension\Starlight\Special;

use MediaWiki\Extension\Starlight\ReviewStore;
use SpecialPage;

class SpecialManageReviews extends SpecialPage {

	private ReviewStore $reviewStore;

	public function __construct( ReviewStore $reviewStore ) {
		parent::__construct( 'ManageReviews', 'starlight-moderate' );
		$this->reviewStore = $reviewStore;
	}

	/**
	 * @param string|null $subPage
	 */
	public function execute( $subPage ) {
		$this->setHeaders();
		$this->outputHeader();
		$this->checkPermissions();

		$output = $this->getOutput();
		$output->addModuleStyles( [ 'ext.starlight.reviews.styles' ] );

		$output->addWikiMsg( 'starlight-special-manage-desc' );

		// TODO: Implement moderation queue
		// - List flagged reviews
		// - Show deleted reviews (for those with viewdeleted permission)
		// - Bulk actions
	}

	/**
	 * @return string
	 */
	protected function getGroupName() {
		return 'wiki';
	}
}
