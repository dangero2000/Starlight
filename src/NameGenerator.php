<?php
/**
 * Random name generation for anonymous reviewers.
 *
 * @file
 * @ingroup Extensions
 * @license AGPL-3.0-or-later
 */

namespace MediaWiki\Extension\Starlight;

class NameGenerator {

	/**
	 * Adjectives for name generation.
	 */
	private const ADJECTIVES = [
		'Adventurous', 'Ambitious', 'Amiable', 'Ancient', 'Artistic',
		'Bold', 'Brave', 'Bright', 'Brilliant', 'Bustling',
		'Calm', 'Careful', 'Charming', 'Cheerful', 'Clever',
		'Curious', 'Daring', 'Dazzling', 'Devoted', 'Diligent',
		'Eager', 'Earnest', 'Elegant', 'Eloquent', 'Energetic',
		'Fair', 'Faithful', 'Famous', 'Fancy', 'Fearless',
		'Gallant', 'Gentle', 'Glorious', 'Golden', 'Graceful',
		'Happy', 'Hardy', 'Harmonious', 'Helpful', 'Honest',
		'Imaginative', 'Industrious', 'Innovative', 'Inspired', 'Intrepid',
		'Jolly', 'Joyful', 'Jubilant', 'Just', 'Keen',
		'Kind', 'Knowing', 'Learned', 'Legendary', 'Lively',
		'Lucky', 'Luminous', 'Magical', 'Majestic', 'Merry',
		'Mighty', 'Mindful', 'Modest', 'Musical', 'Mysterious',
		'Noble', 'Notable', 'Observant', 'Optimistic', 'Orderly',
		'Patient', 'Peaceful', 'Pleasant', 'Plucky', 'Polite',
		'Quiet', 'Quick', 'Radiant', 'Rational', 'Reliable',
		'Resilient', 'Resourceful', 'Roaming', 'Robust', 'Sage',
		'Serene', 'Shining', 'Silent', 'Sincere', 'Skilled',
		'Spirited', 'Splendid', 'Steadfast', 'Sterling', 'Stoic',
		'Swift', 'Talented', 'Thoughtful', 'Thriving', 'Tranquil',
		'Trusty', 'Valiant', 'Vibrant', 'Vigilant', 'Virtuous',
		'Wandering', 'Warm', 'Watchful', 'Whimsical', 'Wise',
		'Witty', 'Wonderful', 'Worthy', 'Zealous', 'Zesty',
	];

	/**
	 * Nouns for name generation.
	 */
	private const NOUNS = [
		'Adventurer', 'Alchemist', 'Ambassador', 'Apprentice', 'Archer',
		'Artisan', 'Astronomer', 'Baker', 'Bard', 'Blacksmith',
		'Builder', 'Captain', 'Carpenter', 'Cartographer', 'Champion',
		'Chef', 'Chronicler', 'Citizen', 'Clockmaker', 'Collector',
		'Commander', 'Companion', 'Composer', 'Conductor', 'Courier',
		'Crafter', 'Curator', 'Dancer', 'Diplomat', 'Director',
		'Dreamer', 'Elder', 'Engineer', 'Explorer', 'Farmer',
		'Fisher', 'Forester', 'Gardener', 'Guardian', 'Guide',
		'Healer', 'Herald', 'Herbalist', 'Historian', 'Hunter',
		'Inventor', 'Jeweler', 'Journeyer', 'Judge', 'Keeper',
		'Knight', 'Librarian', 'Linguist', 'Locksmith', 'Lookout',
		'Mage', 'Mariner', 'Mason', 'Merchant', 'Messenger',
		'Miller', 'Miner', 'Minstrel', 'Monk', 'Musician',
		'Navigator', 'Noble', 'Nomad', 'Observer', 'Oracle',
		'Painter', 'Pathfinder', 'Philosopher', 'Pilgrim', 'Pioneer',
		'Playwright', 'Poet', 'Potter', 'Protector', 'Ranger',
		'Reader', 'Researcher', 'Rider', 'Sage', 'Sailor',
		'Scholar', 'Scribe', 'Sculptor', 'Seeker', 'Sentinel',
		'Shepherd', 'Singer', 'Smith', 'Soldier', 'Songbird',
		'Sorcerer', 'Spinner', 'Stargazer', 'Steward', 'Storyteller',
		'Strategist', 'Student', 'Surveyor', 'Tailor', 'Teacher',
		'Thinker', 'Tinker', 'Trader', 'Traveler', 'Treasurer',
		'Voyager', 'Wanderer', 'Warden', 'Watchmaker', 'Weaver',
		'Wizard', 'Woodworker', 'Writer', 'Yeoman', 'Zealot',
	];

	private SessionManager $sessionManager;

	public function __construct( SessionManager $sessionManager ) {
		$this->sessionManager = $sessionManager;
	}

	/**
	 * Generate a random adjective-noun name.
	 *
	 * @return string
	 */
	public function generateRandomName(): string {
		$adjective = self::ADJECTIVES[ array_rand( self::ADJECTIVES ) ];
		$noun = self::NOUNS[ array_rand( self::NOUNS ) ];

		return $adjective . ' ' . $noun;
	}

	/**
	 * Get the default name for a user.
	 *
	 * For logged-in users, returns their display name.
	 * For anonymous users with a saved name, returns that.
	 * For anonymous users without a saved name, generates a new random name.
	 *
	 * @param \MediaWiki\User\User $user
	 * @return string
	 */
	public function getDefaultName( $user ): string {
		// Logged-in users use their display name
		if ( $user->isRegistered() ) {
			$realName = $user->getRealName();
			return $realName !== '' ? $realName : $user->getName();
		}

		// Check for a saved persistent name
		$persistentName = $this->sessionManager->getPersistentName();
		if ( $persistentName !== null ) {
			return $persistentName;
		}

		// Generate a new random name
		return $this->generateRandomName();
	}

	/**
	 * Check if the user has a persistent name saved.
	 *
	 * @return bool
	 */
	public function hasPersistentName(): bool {
		return $this->sessionManager->hasPersistentName();
	}

	/**
	 * Save a name as the persistent name for this anonymous user.
	 *
	 * @param string $name
	 */
	public function savePersistentName( string $name ): void {
		$this->sessionManager->setPersistentName( $name );
	}

	/**
	 * Clear the persistent name.
	 */
	public function clearPersistentName(): void {
		$this->sessionManager->clearPersistentName();
	}

	/**
	 * Get all available adjectives.
	 *
	 * @return array
	 */
	public static function getAdjectives(): array {
		return self::ADJECTIVES;
	}

	/**
	 * Get all available nouns.
	 *
	 * @return array
	 */
	public static function getNouns(): array {
		return self::NOUNS;
	}
}
