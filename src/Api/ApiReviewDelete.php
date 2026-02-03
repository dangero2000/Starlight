<?php
/**
 * API module for deleting reviews.
 *
 * @file
 * @ingroup Extensions
 * @license AGPL-3.0-or-later
 */

namespace MediaWiki\Extension\Starlight\Api;

use ApiBase;
use MediaWiki\Extension\Starlight\ReviewStore;
use MediaWiki\Extension\Starlight\SessionManager;
use Wikimedia\ParamValidator\ParamValidator;

class ApiReviewDelete extends ApiBase {

	private ReviewStore $reviewStore;
	private SessionManager $sessionManager;

	public function __construct(
		$mainModule,
		$moduleName,
		ReviewStore $reviewStore,
		SessionManager $sessionManager
	) {
		parent::__construct( $mainModule, $moduleName );
		$this->reviewStore = $reviewStore;
		$this->sessionManager = $sessionManager;
	}

	public function execute() {
		$user = $this->getUser();
		$params = $this->extractRequestParams();

		// Check rate limit before any processing
		if ( $user->pingLimiter( 'starlight-delete' ) ) {
			$this->dieWithError( 'apierror-ratelimited' );
		}

		// Get the review
		$review = $this->reviewStore->getReview( $params['reviewid'] );
		if ( !$review ) {
			$this->dieWithError( 'starlight-error-review-not-found' );
		}

		// Check permission to delete
		$canDelete = false;

		// Owner can delete their own review
		if ( $this->sessionManager->canEditReview( $review, $user ) ) {
			$canDelete = true;
		}

		// Moderators can delete any review
		if ( $this->getAuthority()->isAllowed( 'starlight-moderate' ) ) {
			$canDelete = true;
		}

		if ( !$canDelete ) {
			// Increment failure rate limiter to prevent brute-force token guessing
			$user->pingLimiter( 'starlight-edit-fail' );
			$this->dieWithError( 'starlight-error-cannot-delete' );
		}

		// Perform the deletion
		$success = $this->reviewStore->deleteReview(
			$params['reviewid'],
			$user->getId(),
			$params['reason']
		);

		if ( !$success ) {
			$this->dieWithError( 'starlight-error-delete-failed' );
		}

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
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_DEFAULT => '',
			],
		];
	}

	protected function getExamplesMessages() {
		return [
			'action=starlightdelete&reviewid=456&reason=Spam&token=abc123'
				=> 'apihelp-starlightdelete-example',
		];
	}
}
