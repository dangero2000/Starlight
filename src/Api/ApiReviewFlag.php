<?php
/**
 * API module for flagging reviews.
 *
 * @file
 * @ingroup Extensions
 * @license AGPL-3.0-or-later
 */

namespace MediaWiki\Extension\Starlight\Api;

use ApiBase;
use MediaWiki\Extension\Starlight\ReviewStore;
use Wikimedia\ParamValidator\ParamValidator;

class ApiReviewFlag extends ApiBase {

	private ReviewStore $reviewStore;

	public function __construct(
		$mainModule,
		$moduleName,
		ReviewStore $reviewStore
	) {
		parent::__construct( $mainModule, $moduleName );
		$this->reviewStore = $reviewStore;
	}

	public function execute() {
		$user = $this->getUser();
		$params = $this->extractRequestParams();

		// Check permission
		$this->checkUserRightsAny( 'starlight-flag' );

		// Get the review
		$review = $this->reviewStore->getReview( $params['reviewid'] );
		if ( !$review ) {
			$this->dieWithError( 'starlight-error-review-not-found' );
		}

		// Can't flag your own review
		if ( $user->isRegistered() && (int)$review['sr_user_id'] === $user->getId() ) {
			$this->dieWithError( 'starlight-error-cannot-flag-own' );
		}

		// TODO: Implement flagging logic
		// For now, just increment the flag counter

		$this->getResult()->addValue( null, $this->getModuleName(), [
			'success' => true,
			'reviewid' => $params['reviewid'],
		] );
	}

	public function needsToken() {
		return 'csrf';
	}

	public function isWriteMode() {
		return true;
	}

	public function getAllowedParams() {
		return [
			'reviewid' => [
				ParamValidator::PARAM_TYPE => 'integer',
				ParamValidator::PARAM_REQUIRED => true,
			],
			'reason' => [
				ParamValidator::PARAM_TYPE => [
					'spam', 'inappropriate', 'fake', 'outdated', 'other'
				],
				ParamValidator::PARAM_REQUIRED => true,
			],
			'comment' => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_DEFAULT => '',
			],
		];
	}

	protected function getExamplesMessages() {
		return [
			'action=starlightflag&reviewid=456&reason=spam&token=abc123'
				=> 'apihelp-starlightflag-example',
		];
	}
}
