/**
 * Verification module - handles verification voting
 *
 * @license AGPL-3.0-or-later
 */
( function () {
	'use strict';

	var starlightVerify = {
		api: null,

		init: function () {
			this.api = new mw.Api();
			this.bindEvents();
		},

		bindEvents: function () {
			var self = this;

			// Verification button clicks
			$( document ).on( 'click', '.starlight-verify-btn', function () {
				var $btn = $( this );
				var reviewId = $btn.data( 'review-id' );
				var verdict = $btn.data( 'verdict' );

				self.submitVote( reviewId, verdict, $btn );
			} );
		},

		submitVote: function ( reviewId, verdict, $btn ) {
			var self = this;
			var $container = $btn.closest( '.starlight-verify-buttons' );

			// Disable buttons during submission
			$container.find( '.starlight-verify-btn' ).prop( 'disabled', true );

			this.api.postWithToken( 'csrf', {
				action: 'starlightverify',
				reviewid: reviewId,
				verdict: verdict
			} ).done( function ( response ) {
				$container.find( '.starlight-verify-btn' ).prop( 'disabled', false );

				if ( response.starlightverify && response.starlightverify.success ) {
					// Update button states
					$container.find( '.starlight-verify-btn' )
						.removeClass( 'starlight-verify-btn-selected' );
					$btn.addClass( 'starlight-verify-btn-selected' );

					// Show brief confirmation
					self.showVoteConfirmation( $container, verdict );
				}
			} ).fail( function ( code, result ) {
				$container.find( '.starlight-verify-btn' ).prop( 'disabled', false );
				var msg = result.error && result.error.info || mw.msg( 'starlight-error-verify-failed' );
				mw.notify( msg, { type: 'error' } );
			} );
		},

		showVoteConfirmation: function ( $container, verdict ) {
			var $confirmation = $container.find( '.starlight-verify-confirmation' );
			if ( !$confirmation.length ) {
				$confirmation = $( '<span class="starlight-verify-confirmation"></span>' );
				$container.append( $confirmation );
			}

			$confirmation.text( '✓' ).fadeIn( 200 ).delay( 1000 ).fadeOut( 200 );
		}
	};

	mw.hook( 'wikipage.content' ).add( function () {
		if ( $( '.starlight-verify-buttons' ).length ) {
			starlightVerify.init();
		}
	} );

	mw.starlight = mw.starlight || {};
	mw.starlight.verify = starlightVerify;

}() );
