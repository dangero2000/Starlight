<?php
/**
 * Database operations for reviews.
 *
 * @file
 * @ingroup Extensions
 * @license AGPL-3.0-or-later
 */

namespace MediaWiki\Extension\Starlight;

use MediaWiki\Config\Config;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\SelectQueryBuilder;

class ReviewStore {

	private IConnectionProvider $dbProvider;
	private Config $config;

	public function __construct(
		IConnectionProvider $dbProvider,
		Config $config
	) {
		$this->dbProvider = $dbProvider;
		$this->config = $config;
	}

	/**
	 * Get a review by ID.
	 *
	 * @param int $reviewId
	 * @return array|null
	 */
	public function getReview( int $reviewId ): ?array {
		$dbr = $this->dbProvider->getReplicaDatabase();

		$row = $dbr->newSelectQueryBuilder()
			->select( '*' )
			->from( 'starlight_review' )
			->where( [ 'sr_id' => $reviewId ] )
			->caller( __METHOD__ )
			->fetchRow();

		return $row ? (array)$row : null;
	}

	/**
	 * Get reviews for a page.
	 *
	 * @param int $pageId
	 * @param string $sort Sort order
	 * @param int $limit Maximum number of reviews
	 * @param int $offset Offset for pagination
	 * @param string $status Status filter (default: 'active')
	 * @return array
	 */
	public function getReviewsForPage(
		int $pageId,
		string $sort = 'smart',
		int $limit = 10,
		int $offset = 0,
		string $status = 'active'
	): array {
		$dbr = $this->dbProvider->getReplicaDatabase();

		$queryBuilder = $dbr->newSelectQueryBuilder()
			->select( '*' )
			->from( 'starlight_review' )
			->where( [
				'sr_page_id' => $pageId,
				'sr_status' => $status,
			] )
			->limit( $limit )
			->offset( $offset )
			->caller( __METHOD__ );

		// Apply sort order
		$this->applySortOrder( $queryBuilder, $sort );

		$result = $queryBuilder->fetchResultSet();

		$reviews = [];
		foreach ( $result as $row ) {
			$reviews[] = (array)$row;
		}

		return $reviews;
	}

	/**
	 * Apply sort order to query builder.
	 *
	 * @param SelectQueryBuilder $queryBuilder
	 * @param string $sort
	 */
	private function applySortOrder( SelectQueryBuilder $queryBuilder, string $sort ): void {
		switch ( $sort ) {
			case 'newest':
				$queryBuilder->orderBy( 'sr_timestamp', SelectQueryBuilder::SORT_DESC );
				break;
			case 'oldest':
				$queryBuilder->orderBy( 'sr_timestamp', SelectQueryBuilder::SORT_ASC );
				break;
			case 'highest-rating':
				$queryBuilder->orderBy( 'sr_rating', SelectQueryBuilder::SORT_DESC )
					->orderBy( 'sr_sort_score', SelectQueryBuilder::SORT_DESC );
				break;
			case 'lowest-rating':
				$queryBuilder->orderBy( 'sr_rating', SelectQueryBuilder::SORT_ASC )
					->orderBy( 'sr_timestamp', SelectQueryBuilder::SORT_DESC );
				break;
			case 'most-verified':
				$queryBuilder->orderBy( 'sr_verify_score', SelectQueryBuilder::SORT_DESC )
					->orderBy( 'sr_timestamp', SelectQueryBuilder::SORT_DESC );
				break;
			case 'smart':
			default:
				$queryBuilder->orderBy( 'sr_sort_score', SelectQueryBuilder::SORT_DESC )
					->orderBy( 'sr_timestamp', SelectQueryBuilder::SORT_DESC );
				break;
		}
	}

	/**
	 * Get page statistics.
	 *
	 * @param int $pageId
	 * @return array|null
	 */
	public function getPageStats( int $pageId ): ?array {
		$dbr = $this->dbProvider->getReplicaDatabase();

		$row = $dbr->newSelectQueryBuilder()
			->select( '*' )
			->from( 'starlight_page_stats' )
			->where( [ 'sps_page_id' => $pageId ] )
			->caller( __METHOD__ )
			->fetchRow();

		return $row ? (array)$row : null;
	}

	/**
	 * Create a new review.
	 *
	 * @param array $data Review data
	 * @return int The new review ID
	 */
	public function createReview( array $data ): int {
		$dbw = $this->dbProvider->getPrimaryDatabase();

		$timestamp = wfTimestampNow();

		$dbw->newInsertQueryBuilder()
			->insertInto( 'starlight_review' )
			->row( [
				'sr_page_id' => $data['page_id'],
				'sr_user_id' => $data['user_id'],
				'sr_session_token' => $data['session_token'],
				'sr_reviewer_name' => $data['reviewer_name'],
				'sr_rating' => $data['rating'],
				'sr_experience' => $data['experience'],
				'sr_review_text' => $data['review_text'],
				'sr_timestamp' => $timestamp,
				'sr_modified' => $timestamp,
				'sr_status' => 'active',
				'sr_ip_hash' => $data['ip_hash'] ?? null,
				'sr_flags' => 0,
				'sr_verify_true' => 0,
				'sr_verify_mostly_true' => 0,
				'sr_verify_mixed' => 0,
				'sr_verify_mostly_false' => 0,
				'sr_verify_false' => 0,
				'sr_verify_inconclusive' => 0,
				'sr_verify_score' => 0,
				'sr_verify_locked' => 0,
				'sr_sort_score' => $this->calculateInitialSortScore(),
				'sr_outdated_count' => 0,
			] )
			->caller( __METHOD__ )
			->execute();

		$reviewId = $dbw->insertId();

		// Update page stats
		$this->updatePageStats( $data['page_id'] );

		// Log the creation
		$this->logAction( $reviewId, $data['user_id'] ?? 0, 'create', null, $data );

		return $reviewId;
	}

	/**
	 * Calculate initial sort score for a new review.
	 *
	 * @return float
	 */
	private function calculateInitialSortScore(): float {
		// New reviews get a neutral verification score (0.5) and full recency (1.0)
		$verificationWeight = $this->config->get( 'StarlightSortVerificationWeight' );
		$recencyWeight = $this->config->get( 'StarlightSortRecencyWeight' );

		return ( $verificationWeight * 0.5 ) + ( $recencyWeight * 1.0 );
	}

	/**
	 * Update a review.
	 *
	 * @param int $reviewId
	 * @param array $data
	 * @param int $actorId
	 * @return bool
	 */
	public function updateReview( int $reviewId, array $data, int $actorId ): bool {
		$dbw = $this->dbProvider->getPrimaryDatabase();

		$oldData = $this->getReview( $reviewId );
		if ( !$oldData ) {
			return false;
		}

		$updateData = [
			'sr_modified' => wfTimestampNow(),
		];

		if ( isset( $data['reviewer_name'] ) ) {
			$updateData['sr_reviewer_name'] = $data['reviewer_name'];
		}
		if ( isset( $data['rating'] ) ) {
			$updateData['sr_rating'] = $data['rating'];
		}
		if ( isset( $data['experience'] ) ) {
			$updateData['sr_experience'] = $data['experience'];
		}
		if ( isset( $data['review_text'] ) ) {
			$updateData['sr_review_text'] = $data['review_text'];
		}

		$dbw->newUpdateQueryBuilder()
			->update( 'starlight_review' )
			->set( $updateData )
			->where( [ 'sr_id' => $reviewId ] )
			->caller( __METHOD__ )
			->execute();

		// Update page stats if rating changed
		if ( isset( $data['rating'] ) && $data['rating'] !== $oldData['sr_rating'] ) {
			$this->updatePageStats( $oldData['sr_page_id'] );
		}

		// Log the edit
		$this->logAction( $reviewId, $actorId, 'edit', null, [ 'old' => $oldData, 'new' => $data ] );

		return true;
	}

	/**
	 * Delete (hide) a review.
	 *
	 * @param int $reviewId
	 * @param int $actorId
	 * @param string $reason
	 * @return bool
	 */
	public function deleteReview( int $reviewId, int $actorId, string $reason = '' ): bool {
		$dbw = $this->dbProvider->getPrimaryDatabase();

		$review = $this->getReview( $reviewId );
		if ( !$review ) {
			return false;
		}

		$dbw->newUpdateQueryBuilder()
			->update( 'starlight_review' )
			->set( [
				'sr_status' => 'deleted',
				'sr_modified' => wfTimestampNow(),
			] )
			->where( [ 'sr_id' => $reviewId ] )
			->caller( __METHOD__ )
			->execute();

		// Update page stats
		$this->updatePageStats( $review['sr_page_id'] );

		// Log the deletion
		$this->logAction( $reviewId, $actorId, 'delete', $reason, $review );

		return true;
	}

	/**
	 * Update page statistics.
	 *
	 * @param int $pageId
	 */
	public function updatePageStats( int $pageId ): void {
		$dbw = $this->dbProvider->getPrimaryDatabase();

		// Get aggregate stats
		$row = $dbw->newSelectQueryBuilder()
			->select( [
				'review_count' => 'COUNT(*)',
				'avg_rating' => 'AVG(sr_rating)',
				'rating_1' => 'SUM(CASE WHEN sr_rating = 1 THEN 1 ELSE 0 END)',
				'rating_2' => 'SUM(CASE WHEN sr_rating = 2 THEN 1 ELSE 0 END)',
				'rating_3' => 'SUM(CASE WHEN sr_rating = 3 THEN 1 ELSE 0 END)',
				'rating_4' => 'SUM(CASE WHEN sr_rating = 4 THEN 1 ELSE 0 END)',
				'rating_5' => 'SUM(CASE WHEN sr_rating = 5 THEN 1 ELSE 0 END)',
			] )
			->from( 'starlight_review' )
			->where( [
				'sr_page_id' => $pageId,
				'sr_status' => 'active',
			] )
			->caller( __METHOD__ )
			->fetchRow();

		// Upsert the stats
		$dbw->newReplaceQueryBuilder()
			->replaceInto( 'starlight_page_stats' )
			->uniqueIndexFields( [ 'sps_page_id' ] )
			->row( [
				'sps_page_id' => $pageId,
				'sps_review_count' => (int)$row->review_count,
				'sps_avg_rating' => round( (float)$row->avg_rating, 2 ),
				'sps_rating_1' => (int)$row->rating_1,
				'sps_rating_2' => (int)$row->rating_2,
				'sps_rating_3' => (int)$row->rating_3,
				'sps_rating_4' => (int)$row->rating_4,
				'sps_rating_5' => (int)$row->rating_5,
				'sps_updated' => wfTimestampNow(),
			] )
			->caller( __METHOD__ )
			->execute();
	}

	/**
	 * Log an action.
	 *
	 * @param int $reviewId
	 * @param int $actorId
	 * @param string $action
	 * @param string|null $reason
	 * @param array|null $data
	 */
	private function logAction(
		int $reviewId,
		int $actorId,
		string $action,
		?string $reason,
		?array $data
	): void {
		$dbw = $this->dbProvider->getPrimaryDatabase();

		$dbw->newInsertQueryBuilder()
			->insertInto( 'starlight_review_log' )
			->row( [
				'srl_review_id' => $reviewId,
				'srl_actor_id' => $actorId,
				'srl_action' => $action,
				'srl_reason' => $reason,
				'srl_timestamp' => wfTimestampNow(),
				'srl_data' => $data ? json_encode( $data ) : null,
			] )
			->caller( __METHOD__ )
			->execute();
	}

	/**
	 * Check if a user is rate limited.
	 *
	 * @param int|null $userId
	 * @param string|null $ipHash
	 * @return bool
	 */
	public function isRateLimited( ?int $userId, ?string $ipHash ): bool {
		$dbr = $this->dbProvider->getReplicaDatabase();

		$hourAgo = wfTimestamp( TS_MW, time() - 3600 );
		$dayAgo = wfTimestamp( TS_MW, time() - 86400 );

		$conditions = [];
		if ( $userId ) {
			$conditions[] = $dbr->expr( 'sr_user_id', '=', $userId );
		} elseif ( $ipHash ) {
			$conditions[] = $dbr->expr( 'sr_ip_hash', '=', $ipHash );
		} else {
			return false;
		}

		// Check hourly limit
		$hourlyCount = $dbr->newSelectQueryBuilder()
			->select( 'COUNT(*)' )
			->from( 'starlight_review' )
			->where( $conditions )
			->andWhere( $dbr->expr( 'sr_timestamp', '>=', $hourAgo ) )
			->caller( __METHOD__ )
			->fetchField();

		if ( $hourlyCount >= $this->config->get( 'StarlightRateLimitPerHour' ) ) {
			return true;
		}

		// Check daily limit
		$dailyCount = $dbr->newSelectQueryBuilder()
			->select( 'COUNT(*)' )
			->from( 'starlight_review' )
			->where( $conditions )
			->andWhere( $dbr->expr( 'sr_timestamp', '>=', $dayAgo ) )
			->caller( __METHOD__ )
			->fetchField();

		return $dailyCount >= $this->config->get( 'StarlightRateLimitPerDay' );
	}

	/**
	 * Claim anonymous reviews for a user.
	 *
	 * @param string $sessionToken
	 * @param int $userId
	 * @return int Number of reviews claimed
	 */
	public function claimReviews( string $sessionToken, int $userId ): int {
		$dbw = $this->dbProvider->getPrimaryDatabase();

		$dbw->newUpdateQueryBuilder()
			->update( 'starlight_review' )
			->set( [
				'sr_user_id' => $userId,
				'sr_session_token' => null,
			] )
			->where( [
				'sr_session_token' => $sessionToken,
				'sr_user_id' => null,
			] )
			->caller( __METHOD__ )
			->execute();

		return $dbw->affectedRows();
	}

	/**
	 * Update review sort scores (for maintenance).
	 *
	 * @param int $limit Number of reviews to update
	 * @return int Number updated
	 */
	public function updateSortScores( int $limit = 1000 ): int {
		// This would be called by a maintenance script to recalculate
		// sort scores as reviews age
		// Implementation depends on ReviewSorter
		return 0;
	}

	/**
	 * Check if a user has already flagged a review.
	 *
	 * @param int $reviewId
	 * @param int $userId User ID (0 for anonymous)
	 * @param string $ip IP address for anonymous users
	 * @return bool
	 */
	public function hasUserFlagged( int $reviewId, int $userId, string $ip ): bool {
		$dbr = $this->dbProvider->getReplicaDatabase();

		$queryBuilder = $dbr->newSelectQueryBuilder()
			->select( 'COUNT(*)' )
			->from( 'starlight_review_log' )
			->where( [
				'srl_review_id' => $reviewId,
				'srl_action' => 'flag',
			] )
			->caller( __METHOD__ );

		if ( $userId > 0 ) {
			$queryBuilder->andWhere( [ 'srl_actor_id' => $userId ] );
		} else {
			// For anonymous users, check by IP in the data JSON
			// This is less efficient but necessary for anonymous flag tracking
			$queryBuilder->andWhere(
				$dbr->expr( 'srl_data', IDatabase::LIKE,
					new \Wikimedia\Rdbms\LikeValue(
						$dbr->anyString(),
						'"ip":"' . $ip . '"',
						$dbr->anyString()
					)
				)
			);
		}

		return (int)$queryBuilder->fetchField() > 0;
	}

	/**
	 * Add a flag to a review.
	 *
	 * @param int $reviewId
	 * @param int $userId User ID (0 for anonymous)
	 * @param string $reason Flag reason
	 * @param string $comment Optional comment
	 * @param string $ip IP address
	 * @return bool
	 */
	public function addFlag(
		int $reviewId,
		int $userId,
		string $reason,
		string $comment,
		string $ip
	): bool {
		$dbw = $this->dbProvider->getPrimaryDatabase();

		// Log the flag
		$this->logAction( $reviewId, $userId, 'flag', $reason, [
			'comment' => $comment,
			'ip' => $ip,
		] );

		// Increment the flag counter on the review
		$dbw->newUpdateQueryBuilder()
			->update( 'starlight_review' )
			->set( [ 'sr_flags = sr_flags + 1' ] )
			->where( [ 'sr_id' => $reviewId ] )
			->caller( __METHOD__ )
			->execute();

		// If this was an 'outdated' flag, also increment that counter
		if ( $reason === 'outdated' ) {
			$dbw->newUpdateQueryBuilder()
				->update( 'starlight_review' )
				->set( [ 'sr_outdated_count = sr_outdated_count + 1' ] )
				->where( [ 'sr_id' => $reviewId ] )
				->caller( __METHOD__ )
				->execute();
		}

		return true;
	}

	/**
	 * Get the number of flags a user has submitted recently.
	 *
	 * @param int $userId User ID (0 for anonymous)
	 * @param string $ip IP address for anonymous users
	 * @return int
	 */
	public function getRecentFlagCount( int $userId, string $ip ): int {
		$dbr = $this->dbProvider->getReplicaDatabase();

		$hourAgo = wfTimestamp( TS_MW, time() - 3600 );

		$queryBuilder = $dbr->newSelectQueryBuilder()
			->select( 'COUNT(*)' )
			->from( 'starlight_review_log' )
			->where( [
				'srl_action' => 'flag',
			] )
			->andWhere( $dbr->expr( 'srl_timestamp', '>=', $hourAgo ) )
			->caller( __METHOD__ );

		if ( $userId > 0 ) {
			$queryBuilder->andWhere( [ 'srl_actor_id' => $userId ] );
		} else {
			// For anonymous users, check by IP in the data JSON
			$queryBuilder->andWhere(
				$dbr->expr( 'srl_data', IDatabase::LIKE,
					new \Wikimedia\Rdbms\LikeValue(
						$dbr->anyString(),
						'"ip":"' . $ip . '"',
						$dbr->anyString()
					)
				)
			);
		}

		return (int)$queryBuilder->fetchField();
	}
}
