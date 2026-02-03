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
use MediaWiki\Extension\Starlight\SecurityLogger;
use Wikimedia\ParamValidator\ParamValidator;

class ApiReviewFlag extends ApiBase {

	private ReviewStore $reviewStore;
	private SecurityLogger $securityLogger;

	public function __construct(
		$mainModule,
		$moduleName,
		ReviewStore $reviewStore,
		SecurityLogger $securityLogger
	) {
		parent::__construct( $mainModule, $moduleName );
		$this->reviewStore = $reviewStore;
		$this->securityLogger = $securityLogger;
	}

	public function execute() {
		$user = $this->getUser();
		$params = $this->extractRequestParams();

		// Check rate limit to prevent flag abuse
		if ( $user->pingLimiter( 'starlight-flag' ) ) {
			$this->securityLogger->logRateLimitHit(
				$user,
				'flag',
				$this->getRequest()->getIP()
			);
			$this->dieWithError( 'apierror-ratelimited' );
		}

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

		// Check if user has already flagged this review
		$alreadyFlagged = $this->reviewStore->hasUserFlagged(
			$params['reviewid'],
			$user->getId(),
			$this->getRequest()->getIP()
		);

		if ( $alreadyFlagged ) {
			$this->dieWithError( 'starlight-error-already-flagged' );
		}

		// Record the flag
		$success = $this->reviewStore->addFlag(
			$params['reviewid'],
			$user->getId(),
			$params['reason'],
			$params['comment'],
			$this->getRequest()->getIP()
		);

		if ( !$success ) {
			$this->dieWithError( 'starlight-error-flag-failed' );
		}

		// Log suspicious activity if user is flagging many reviews
		$recentFlagCount = $this->reviewStore->getRecentFlagCount(
			$user->getId(),
			$this->getRequest()->getIP()
		);

		if ( $recentFlagCount > 10 ) {
			$this->securityLogger->logSuspiciousFlag(
				$user,
				$params['reviewid'],
				$params['reason'],
				$this->getRequest()->getIP()
			);
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
