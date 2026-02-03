<?php
/**
 * Sorting algorithm for reviews.
 *
 * @file
 * @ingroup Extensions
 * @license AGPL-3.0-or-later
 */

namespace MediaWiki\Extension\Starlight;

use MediaWiki\Config\Config;

class ReviewSorter {

	private Config $config;

	public function __construct( Config $config ) {
		$this->config = $config;
	}

	/**
	 * Calculate sort score for a review.
	 *
	 * @param array $review Review data from database
	 * @return float Sort score between 0 and 1
	 */
	public function calculateSortScore( array $review ): float {
		$verificationScore = $this->getVerificationScore( $review );
		$recencyScore = $this->getRecencyScore( $review['sr_timestamp'] );
		$confidenceBonus = $this->getConfidenceBonus( $review );

		$verificationWeight = $this->config->get( 'StarlightSortVerificationWeight' );
		$recencyWeight = $this->config->get( 'StarlightSortRecencyWeight' );

		return ( $verificationWeight * $verificationScore )
			 + ( $recencyWeight * $recencyScore )
			 + $confidenceBonus;
	}

	/**
	 * Get normalized verification score (0-1).
	 *
	 * @param array $review
	 * @return float
	 */
	private function getVerificationScore( array $review ): float {
		$totalVotes = $this->getTotalVotes( $review );

		// Unverified reviews get neutral score
		if ( $totalVotes === 0 ) {
			return 0.5;
		}

		// Raw score is between -2 and +2
		$rawScore = (float)( $review['sr_verify_score'] ?? 0 );

		// Normalize to 0-1 range
		return ( $rawScore + 2 ) / 4;
	}

	/**
	 * Get recency score with decay.
	 *
	 * @param string $timestamp MediaWiki timestamp
	 * @return float Score between 0 and 1
	 */
	private function getRecencyScore( string $timestamp ): float {
		$ageSeconds = time() - wfTimestamp( TS_UNIX, $timestamp );
		$ageDays = $ageSeconds / 86400;

		$halfLife = $this->config->get( 'StarlightSortRecencyHalfLife' );
		$staleThreshold = $this->config->get( 'StarlightStaleThresholdDays' );
		$stalePenalty = $this->config->get( 'StarlightStalePenalty' );

		// Sigmoid decay with half-life
		$recencyScore = 1 / ( 1 + ( $ageDays / $halfLife ) );

		// Apply stale penalty for very old reviews
		if ( $ageDays > $staleThreshold ) {
			$recencyScore *= $stalePenalty;
		}

		return $recencyScore;
	}

	/**
	 * Get confidence bonus based on number of verification votes.
	 *
	 * @param array $review
	 * @return float Bonus between 0 and confidence weight
	 */
	private function getConfidenceBonus( array $review ): float {
		$totalVotes = $this->getTotalVotes( $review );

		if ( $totalVotes === 0 ) {
			return 0;
		}

		$threshold = $this->config->get( 'StarlightSortConfidenceThreshold' );
		$weight = $this->config->get( 'StarlightSortConfidenceWeight' );

		$confidenceRatio = min( $totalVotes / $threshold, 1 );

		return $confidenceRatio * $weight;
	}

	/**
	 * Get total number of verification votes.
	 *
	 * @param array $review
	 * @return int
	 */
	private function getTotalVotes( array $review ): int {
		return (int)( $review['sr_verify_true'] ?? 0 )
			 + (int)( $review['sr_verify_mostly_true'] ?? 0 )
			 + (int)( $review['sr_verify_mixed'] ?? 0 )
			 + (int)( $review['sr_verify_mostly_false'] ?? 0 )
			 + (int)( $review['sr_verify_false'] ?? 0 )
			 + (int)( $review['sr_verify_inconclusive'] ?? 0 );
	}

	/**
	 * Calculate weighted verification score from vote counts.
	 *
	 * @param array $review
	 * @return float Score between -2 and +2
	 */
	public function calculateVerifyScore( array $review ): float {
		$totalVotes = $this->getTotalVotes( $review );

		if ( $totalVotes === 0 ) {
			return 0;
		}

		// Weight each verdict type
		$score = ( (int)$review['sr_verify_true'] * 2 )
			   + ( (int)$review['sr_verify_mostly_true'] * 1 )
			   + ( (int)$review['sr_verify_mixed'] * 0 )
			   + ( (int)$review['sr_verify_mostly_false'] * -1 )
			   + ( (int)$review['sr_verify_false'] * -2 );

		// Inconclusive doesn't affect score

		// Exclude inconclusive from denominator for score calculation
		$scoredVotes = $totalVotes - (int)( $review['sr_verify_inconclusive'] ?? 0 );

		if ( $scoredVotes === 0 ) {
			return 0;
		}

		return $score / $scoredVotes;
	}

	/**
	 * Get verification status label based on score.
	 *
	 * @param float $score Verification score (-2 to +2)
	 * @param int $totalVotes Total number of votes
	 * @return string Status key
	 */
	public function getVerificationStatus( float $score, int $totalVotes ): string {
		// No votes at all
		if ( $totalVotes === 0 ) {
			return 'unverified';
		}

		$minVotes = $this->config->get( 'StarlightVerificationHideCountsUntilThreshold' );

		// Has votes but below threshold - show "pending" status
		if ( $totalVotes < $minVotes ) {
			return 'pending';
		}

		if ( $score >= 1.5 ) {
			return 'accurate';
		} elseif ( $score >= 0.5 ) {
			return 'mostly-accurate';
		} elseif ( $score >= -0.5 ) {
			return 'mixed';
		} elseif ( $score >= -1.5 ) {
			return 'mostly-inaccurate';
		} else {
			return 'inaccurate';
		}
	}

	/**
	 * Check if a review is considered stale.
	 *
	 * @param string $timestamp MediaWiki timestamp
	 * @return bool
	 */
	public function isStale( string $timestamp ): bool {
		$ageSeconds = time() - wfTimestamp( TS_UNIX, $timestamp );
		$ageDays = $ageSeconds / 86400;
		$staleThreshold = $this->config->get( 'StarlightStaleThresholdDays' );

		return $ageDays > $staleThreshold;
	}

	/**
	 * Get age of review in days.
	 *
	 * @param string $timestamp MediaWiki timestamp
	 * @return int
	 */
	public function getAgeInDays( string $timestamp ): int {
		$ageSeconds = time() - wfTimestamp( TS_UNIX, $timestamp );
		return (int)( $ageSeconds / 86400 );
	}
}
