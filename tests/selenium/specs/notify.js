'use strict';

const expect = require( 'chai' ).expect,
	EditPage = require( '../pageobjects/edit.page' ),
	PostEdit = require( '../pageobjects/postedit.page' ),
	CreateAccountPage = require( '../pageobjects/createaccount.page' );

/*
	Title of MediaWiki page which should be edited during this test.
*/
var PageName = 'Test' + Math.random();

describe( 'Postedit notification', function () {

	before( function() {
		EditPage.edit(
			PageName,
			Date.now() + ' ' + Math.random() + "\n"
		);
		PostEdit.init();
	} );

	it( 'should be visible', function () {
		expect( PostEdit.notification.isVisible(), 'notification.isVisible' ).to.be.true;
	} );

	it( 'shouldn\'t contain default text (for wikis without Moderation)', function () {
		/* i18n messages from MediaWiki core:
			postedit-confirmation-created
			postedit-confirmation-saved
		*/
		expect( PostEdit.text ).to.not.contain( 'The page has been created' );
		expect( PostEdit.text ).to.not.contain( 'Your edit was saved' );
	} );

	it ( 'should contain "Pending Review" icon', function() {
		expect( PostEdit.pendingIcon.isVisible(), 'pendingIcon.isVisible' ).to.be.true;
	} );

	it ( 'should say "your edit has been sent to moderation"', function() {
		expect( PostEdit.text )
			.to.contain( 'Success: your edit has been sent to moderation' );
	} );

	it ( 'should contain "continue editing" link', function() {

		expect( PostEdit.editLink.isVisible(), 'editLink.isVisible' ).to.be.true;

		expect( PostEdit.editLink.query.title, 'editLink.query.title' )
			.to.equal( PageName );
		expect( PostEdit.editLink.query.action, 'editLink.query.action' )
			.to.equal( 'edit' );
	} );

	it ( 'should contain "sign up" link if the user is anonymous', function() {
		expect( PostEdit.signupLink.isVisible(), 'signupLink.isVisible' ).to.be.true;
		expect(
			PostEdit.signupLink.query.title,
			'signupLink.query.title'
		).to.equal( 'Special:CreateAccount' );
	} );

	it ( 'shouldn\'t disappear after 3.5 seconds', function() {
		/* Default postedit notification of MediaWiki is removed after 3.5 seconds
			(because it's not important whether the user reads it or not).

			Make sure that Moderation has countered this behavior,
			because its message contains links that user might want to follow,
			plus information about Moderation for first-time editors.
		*/
		PostEdit.waitUsualFadeTime();
		expect( PostEdit.notification.isVisible(), 'notification.isVisible' ).to.be.true;
	} );

	it ( 'should be removed when you click on it', function() {
		/* Clicking on notification should remove it */
		PostEdit.notification.click();
		PostEdit.notification.waitForExist( 500, true ); /* Wait for it to vanish */
	} );

	it ( 'shouldn\'t contain "sign up" link if the user is logged in', function() {

		CreateAccountPage.createAccount( 'TestUser' + Math.random(), '123456' );
		EditPage.edit(
			PageName,
			Date.now() + ' ' + Math.random() + "\n"
		);
		PostEdit.init();

		expect( PostEdit.signupLink.isVisible(), 'signupLink.isVisible' ).to.be.false;
	} );
} );
