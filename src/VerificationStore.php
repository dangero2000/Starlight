<?php
/**
 * Database operations for verification votes.
 *
 * @file
 * @ingroup Extensions
 * @license AGPL-3.0-or-later
 */

namespace MediaWiki\Extension\Starlight;

use MediaWiki\Config\Config;
use Wikimedia\Rdbms\IConnectionProvider;

class VerificationStore {

	private IConnectionProvider $dbProvider;
	private Config $config;
	private ReviewSorter $sorter;

	/**
	 * Valid verdict types.
	 */
	public const VERDICTS = [
		'true',
		'mostly_true',
		'mixed',
		'mostly_false',
		'false',
		'inconclusive',
	];

	/**
	 * Column names for each verdict type.
	 */
	private const VERDICT_COLUMNS = [
		'true' => 'sr_verify_true',
		'mostly_true' => 'sr_verify_mostly_true',
		'mixed' => 'sr_verify_mixed',
		'mostly_false' => 'sr_verify_mostly_false',
		'false' => 'sr_verify_false',
		'inconclusive' => 'sr_verify_inconclusive',
	];

	public function __construct(
		IConnectionProvider $dbProvider,
		Config $config,
		ReviewSorter $sorter
	) {
		$this->dbProvider = $dbProvider;
		$this->config = $config;
		$this->sorter = $sorter;
	}

	/**
	 * Get a user's vote on a review.
	 *
	 * @param int $reviewId
	 * @param int $userId
	 * @return array|null
	 */
	public function getUserVote( int $reviewId, int $userId ): ?array {
		$dbr = $this->dbProvider->getReplicaDatabase();

		$row = $dbr->newSelectQueryBuilder()
			->select( '*' )
			->from( 'starlight_verification' )
			->where( [
				'sv_review_id' => $reviewId,
				'sv_user_id' => $userId,
			] )
			->caller( __METHOD__ )
			->fetchRow();

		return $row ? (array)$row : null;
	}

	/**
	 * Submit or update a verification vote.
	 *
	 * @param int $reviewId
	 * @param int $userId
	 * @param string $verdict One of the VERDICTS constants
	 * @return bool
	 */
	public function vote( int $reviewId, int $userId, string $verdict ): bool {
		if ( !in_array( $verdict, self::VERDICTS, true ) ) {
			return false;
		}

		$dbw = $this->dbProvider->getPrimaryDatabase();

		// Check if review exists and isn't locked
		$review = $dbw->newSelectQueryBuilder()
			->select( [ 'sr_id', 'sr_verify_locked', 'sr_user_id' ] )
			->from( 'starlight_review' )
			->where( [ 'sr_id' => $reviewId ] )
			->caller( __METHOD__ )
			->fetchRow();

		if ( !$review || $review->sr_verify_locked ) {
			return false;
		}

		// Users cannot verify their own reviews
		if ( (int)$review->sr_user_id === $userId ) {
			return false;
		}

		// Get existing vote
		$existingVote = $this->getUserVote( $reviewId, $userId );
		$oldVerdict = $existingVote ? $existingVote['sv_verdict'] : null;

		if ( $oldVerdict === $verdict ) {
			// No change needed
			return true;
		}

		// Update or insert vote
		$dbw->newReplaceQueryBuilder()
			->replaceInto( 'starlight_verification' )
			->uniqueIndexFields( [ 'sv_review_id', 'sv_user_id' ] )
			->row( [
				'sv_review_id' => $reviewId,
				'sv_user_id' => $userId,
				'sv_verdict' => $verdict,
				'sv_timestamp' => wfTimestampNow(),
			] )
			->caller( __METHOD__ )
			->execute();

		// Update review vote counts
		$this->updateReviewVoteCounts( $reviewId, $oldVerdict, $verdict );

		return true;
	}

	/**
	 * Remove a verification vote.
	 *
	 * @param int $reviewId
	 * @param int $userId
	 * @return bool
	 */
	public function removeVote( int $reviewId, int $userId ): bool {
		$dbw = $this->dbProvider->getPrimaryDatabase();

		// Get existing vote
		$existingVote = $this->getUserVote( $reviewId, $userId );
		if ( !$existingVote ) {
			return false;
		}

		$oldVerdict = $existingVote['sv_verdict'];

		$dbw->newDeleteQueryBuilder()
			->deleteFrom( 'starlight_verification' )
			->where( [
				'sv_review_id' => $reviewId,
				'sv_user_id' => $userId,
			] )
			->caller( __METHOD__ )
			->execute();

		// Update review vote counts
		$this->updateReviewVoteCounts( $reviewId, $oldVerdict, null );

		return true;
	}

	/**
	 * Update the vote counts on a review.
	 *
	 * @param int $reviewId
	 * @param string|null $oldVerdict
	 * @param string|null $newVerdict
	 */
	private function updateReviewVoteCounts(
		int $reviewId,
		?string $oldVerdict,
		?string $newVerdict
	): void {
		$dbw = $this->dbProvider->getPrimaryDatabase();

		$updates = [];

		// Decrement old verdict count
		if ( $oldVerdict !== null && isset( self::VERDICT_COLUMNS[$oldVerdict] ) ) {
			$column = self::VERDICT_COLUMNS[$oldVerdict];
			$updates[$column] = $dbw->buildSubString(
				$column . ' - 1',
				1,
				100
			);
			// Actually we need raw SQL here
			$updates[] = $column . ' = ' . $column . ' - 1';
			unset( $updates[$column] );
		}

		// Increment new verdict count
		if ( $newVerdict !== null && isset( self::VERDICT_COLUMNS[$newVerdict] ) ) {
			$column = self::VERDICT_COLUMNS[$newVerdict];
			$updates[] = $column . ' = ' . $column . ' + 1';
		}

		if ( empty( $updates ) ) {
			return;
		}

		// Get current review data for recalculating scores
		$review = $dbw->newSelectQueryBuilder()
			->select( '*' )
			->from( 'starlight_review' )
			->where( [ 'sr_id' => $reviewId ] )
			->forUpdate()
			->caller( __METHOD__ )
			->fetchRow();

		if ( !$review ) {
			return;
		}

		// Apply the increment/decrement updates using raw SQL
		$dbw->query(
			'UPDATE ' . $dbw->tableName( 'starlight_review' ) .
			' SET ' . implode( ', ', $updates ) .
			' WHERE sr_id = ' . $dbw->addQuotes( $reviewId ),
			__METHOD__
		);

		// Now recalculate the verification score and sort score
		$this->recalculateScores( $reviewId );
	}

	/**
	 * Recalculate verification score and sort score for a review.
	 *
	 * @param int $reviewId
	 */
	public function recalculateScores( int $reviewId ): void {
		$dbw = $this->dbProvider->getPrimaryDatabase();

		// Get fresh review data
		$review = $dbw->newSelectQueryBuilder()
			->select( '*' )
			->from( 'starlight_review' )
			->where( [ 'sr_id' => $reviewId ] )
			->caller( __METHOD__ )
			->fetchRow();

		if ( !$review ) {
			return;
		}

		$reviewArray = (array)$review;

		// Calculate new scores
		$verifyScore = $this->sorter->calculateVerifyScore( $reviewArray );
		$sortScore = $this->sorter->calculateSortScore( $reviewArray );

		// Update the review
		$dbw->newUpdateQueryBuilder()
			->update( 'starlight_review' )
			->set( [
				'sr_verify_score' => $verifyScore,
				'sr_sort_score' => $sortScore,
			] )
			->where( [ 'sr_id' => $reviewId ] )
			->caller( __METHOD__ )
			->execute();
	}

	/**
	 * Get all votes for a review.
	 *
	 * @param int $reviewId
	 * @return array
	 */
	public function getVotesForReview( int $reviewId ): array {
		$dbr = $this->dbProvider->getReplicaDatabase();

		$result = $dbr->newSelectQueryBuilder()
			->select( '*' )
			->from( 'starlight_verification' )
			->where( [ 'sv_review_id' => $reviewId ] )
			->orderBy( 'sv_timestamp', 'DESC' )
			->caller( __METHOD__ )
			->fetchResultSet();

		$votes = [];
		foreach ( $result as $row ) {
			$votes[] = (array)$row;
		}

		return $votes;
	}

	/**
	 * Lock verification on a review (prevents further votes).
	 *
	 * @param int $reviewId
	 * @return bool
	 */
	public function lockVerification( int $reviewId ): bool {
		$dbw = $this->dbProvider->getPrimaryDatabase();

		$dbw->newUpdateQueryBuilder()
			->update( 'starlight_review' )
			->set( [ 'sr_verify_locked' => 1 ] )
			->where( [ 'sr_id' => $reviewId ] )
			->caller( __METHOD__ )
			->execute();

		return $dbw->affectedRows() > 0;
	}

	/**
	 * Unlock verification on a review.
	 *
	 * @param int $reviewId
	 * @return bool
	 */
	public function unlockVerification( int $reviewId ): bool {
		$dbw = $this->dbProvider->getPrimaryDatabase();

		$dbw->newUpdateQueryBuilder()
			->update( 'starlight_review' )
			->set( [ 'sr_verify_locked' => 0 ] )
			->where( [ 'sr_id' => $reviewId ] )
			->caller( __METHOD__ )
			->execute();

		return $dbw->affectedRows() > 0;
	}

	/**
	 * Check if a user can verify a review.
	 *
	 * @param int $reviewId
	 * @param \MediaWiki\User\User $user
	 * @return bool
	 */
	public function canVerify( int $reviewId, $user ): bool {
		// Must be logged in
		if ( !$user->isRegistered() ) {
			return false;
		}

		// Must have permission
		if ( !$user->isAllowed( 'starlight-verify' ) ) {
			return false;
		}

		$dbr = $this->dbProvider->getReplicaDatabase();

		// Check if review exists and isn't locked
		$review = $dbr->newSelectQueryBuilder()
			->select( [ 'sr_id', 'sr_verify_locked', 'sr_user_id', 'sr_status' ] )
			->from( 'starlight_review' )
			->where( [ 'sr_id' => $reviewId ] )
			->caller( __METHOD__ )
			->fetchRow();

		if ( !$review ) {
			return false;
		}

		// Can't verify deleted reviews
		if ( $review->sr_status !== 'active' ) {
			return false;
		}

		// Can't verify locked reviews
		if ( $review->sr_verify_locked ) {
			return false;
		}

		// Can't verify own reviews
		if ( (int)$review->sr_user_id === $user->getId() ) {
			return false;
		}

		return true;
	}

	/**
	 * Get verification statistics for a review.
	 *
	 * @param array $review
	 * @return array
	 */
	public function getVerificationStats( array $review ): array {
		$total = 0;
		$verdictCounts = [];

		foreach ( self::VERDICTS as $verdict ) {
			$column = self::VERDICT_COLUMNS[$verdict];
			$count = (int)( $review[$column] ?? 0 );
			$verdictCounts[$verdict] = $count;
			$total += $count;
		}

		$score = (float)( $review['sr_verify_score'] ?? 0 );
		$status = $this->sorter->getVerificationStatus( $score, $total );

		return [
			'verdicts' => $verdictCounts,
			'total' => $total,
			'score' => $score,
			'status' => $status,
			'locked' => (bool)( $review['sr_verify_locked'] ?? false ),
		];
	}
}
