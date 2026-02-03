<?php
/**
 * API module for submitting verification votes.
 *
 * @file
 * @ingroup Extensions
 * @license AGPL-3.0-or-later
 */

namespace MediaWiki\Extension\Starlight\Api;

use ApiBase;
use MediaWiki\Extension\Starlight\ReviewStore;
use MediaWiki\Extension\Starlight\VerificationStore;
use Wikimedia\ParamValidator\ParamValidator;

class ApiReviewVerify extends ApiBase {

	private VerificationStore $verificationStore;
	private ReviewStore $reviewStore;

	public function __construct(
		$mainModule,
		$moduleName,
		VerificationStore $verificationStore,
		ReviewStore $reviewStore
	) {
		parent::__construct( $mainModule, $moduleName );
		$this->verificationStore = $verificationStore;
		$this->reviewStore = $reviewStore;
	}

	public function execute() {
		$user = $this->getUser();
		$params = $this->extractRequestParams();

		// Check permission
		$this->checkUserRightsAny( 'starlight-verify' );

		// Must be logged in
		if ( !$user->isRegistered() ) {
			$this->dieWithError( 'starlight-error-login-required' );
		}

		// Check if user can verify this review
		if ( !$this->verificationStore->canVerify( $params['reviewid'], $user ) ) {
			$this->dieWithError( 'starlight-error-cannot-verify' );
		}

		// Submit the vote
		$success = $this->verificationStore->vote(
			$params['reviewid'],
			$user->getId(),
			$params['verdict']
		);

		if ( !$success ) {
			$this->dieWithError( 'starlight-error-verify-failed' );
		}

		// Get updated review data and verification stats
		$review = $this->reviewStore->getReview( $params['reviewid'] );
		$stats = $review ? $this->verificationStore->getVerificationStats( $review ) : null;

		$this->getResult()->addValue( null, $this->getModuleName(), [
			'success' => true,
			'reviewid' => $params['reviewid'],
			'verdict' => $params['verdict'],
			'verification' => $stats ? [
				'status' => $stats['status'],
				'total' => $stats['total'],
				'score' => $stats['score'],
			] : null,
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
			'verdict' => [
				ParamValidator::PARAM_TYPE => VerificationStore::VERDICTS,
				ParamValidator::PARAM_REQUIRED => true,
			],
		];
	}

	protected function getExamplesMessages() {
		return [
			'action=starlightverify&reviewid=456&verdict=true&token=abc123'
				=> 'apihelp-starlightverify-example',
		];
	}
}
