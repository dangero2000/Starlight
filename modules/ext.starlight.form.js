/**
 * Review form module - handles form submission
 *
 * @license AGPL-3.0-or-later
 */
( function () {
	'use strict';

	var starlightForm = {
		api: null,
		activeDialog: null,
		previousFocus: null,

		init: function () {
			this.api = new mw.Api();
			this.bindEvents();
		},

		bindEvents: function () {
			var self = this;

			// Open dialog button
			$( document ).on( 'click', '.starlight-write-review-button', function () {
				var $button = $( this );
				var $dialog = $button.siblings( '.starlight-form-dialog' );
				self.openDialog( $dialog, $button );
			} );

			// Close dialog button
			$( document ).on( 'click', '.starlight-form-close', function () {
				var $dialog = $( this ).closest( '.starlight-form-dialog' );
				self.closeDialog( $dialog );
			} );

			// Close dialog on backdrop click
			$( document ).on( 'click', '.starlight-form-dialog-backdrop', function () {
				var $dialog = $( this ).closest( '.starlight-form-dialog' );
				self.closeDialog( $dialog );
			} );

			// Close dialog on Escape key
			$( document ).on( 'keydown', function ( e ) {
				if ( e.key === 'Escape' && self.activeDialog ) {
					self.closeDialog( self.activeDialog );
				}
			} );

			// Form submission
			$( document ).on( 'submit', '.starlight-review-form', function ( e ) {
				e.preventDefault();
				self.submitForm( $( this ) );
			} );

			// Generate new name button
			$( document ).on( 'click', '.starlight-regenerate-name', function () {
				self.generateNewName();
			} );
		},

		openDialog: function ( $dialog, $trigger ) {
			// Store previous focus to restore later
			this.previousFocus = $trigger;
			this.activeDialog = $dialog;

			// Show dialog
			$dialog.removeAttr( 'hidden' );

			// Focus the first focusable element in the form
			var $firstInput = $dialog.find( 'input:not([type="hidden"]), textarea, button' ).first();
			if ( $firstInput.length ) {
				$firstInput.trigger( 'focus' );
			}

			// Prevent body scroll while dialog is open
			$( 'body' ).addClass( 'starlight-dialog-open' );
		},

		closeDialog: function ( $dialog ) {
			$dialog.attr( 'hidden', true );
			this.activeDialog = null;

			// Restore focus to trigger element
			if ( this.previousFocus ) {
				this.previousFocus.trigger( 'focus' );
				this.previousFocus = null;
			}

			// Restore body scroll
			$( 'body' ).removeClass( 'starlight-dialog-open' );
		},

		submitForm: function ( $form ) {
			var self = this;
			var pageId = $form.data( 'page-id' );

			// Gather form data - rating is now a radio button
			var rating = $form.find( 'input[name="rating"]:checked' ).val();
			var name = $form.find( 'input[name="name"]' ).val().trim();
			var experience = $form.find( 'input[name="experience"]' ).val().trim();
			var text = $form.find( 'textarea[name="text"]' ).val().trim();
			var remember = $form.find( 'input[name="remember-me"]' ).is( ':checked' );
			var saveName = $form.find( 'input[name="save-name"]' ).is( ':checked' );

			// Client-side validation
			if ( !rating ) {
				this.showError( $form, mw.msg( 'starlight-error-rating-required' ) );
				return;
			}

			if ( !name ) {
				this.showError( $form, mw.msg( 'starlight-error-name-required' ) );
				return;
			}

			if ( !experience ) {
				this.showError( $form, mw.msg( 'starlight-error-experience-required' ) );
				return;
			}

			// Disable form during submission
			var $submitBtn = $form.find( '.starlight-submit-button' );
			$submitBtn.prop( 'disabled', true );

			this.api.postWithToken( 'csrf', {
				action: 'starlightsubmit',
				pageid: pageId,
				rating: rating,
				name: name,
				experience: experience,
				text: text,
				remember: remember,
				savename: saveName
			} ).done( function ( response ) {
				$submitBtn.prop( 'disabled', false );

				if ( response.starlightsubmit && response.starlightsubmit.success ) {
					mw.notify( mw.msg( 'starlight-success-submitted' ), { type: 'success' } );

					// Close the dialog
					var $dialog = $form.closest( '.starlight-form-dialog' );
					if ( $dialog.length ) {
						self.closeDialog( $dialog );
					}

					// Reload the page to show the new review
					window.location.reload();
				}
			} ).fail( function ( code, result ) {
				$submitBtn.prop( 'disabled', false );
				var msg = result.error && result.error.info || mw.msg( 'starlight-error-submit-failed' );
				self.showError( $form, msg );
			} );
		},

		showError: function ( $form, message ) {
			var $error = $form.find( '.starlight-form-error' );
			if ( !$error.length ) {
				$error = $( '<div class="starlight-form-error"></div>' );
				$form.prepend( $error );
			}
			$error.text( message ).show();
		},

		resetForm: function ( $form ) {
			$form.find( '.starlight-form-error' ).hide();
			$form.find( 'input[name="rating"]' ).prop( 'checked', false );
			$form.find( 'input[name="experience"]' ).val( '' );
			$form.find( 'textarea[name="text"]' ).val( '' );
			// Keep name field populated
		},

		generateNewName: function () {
			// Get a new random name from the server
			// For now, just use a simple client-side generation
			var adjectives = [ 'Happy', 'Clever', 'Brave', 'Calm', 'Eager' ];
			var nouns = [ 'Traveler', 'Explorer', 'Reader', 'Writer', 'Thinker' ];

			var adj = adjectives[ Math.floor( Math.random() * adjectives.length ) ];
			var noun = nouns[ Math.floor( Math.random() * nouns.length ) ];

			$( '#starlight-name' ).val( adj + ' ' + noun );
		}
	};

	mw.hook( 'wikipage.content' ).add( function () {
		if ( $( '.starlight-write-review-button' ).length || $( '.starlight-review-form' ).length ) {
			starlightForm.init();
		}
	} );

	mw.starlight = mw.starlight || {};
	mw.starlight.form = starlightForm;

}() );
