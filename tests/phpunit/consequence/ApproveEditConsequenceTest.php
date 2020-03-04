<?php

/*
	Extension:Moderation - MediaWiki extension.
	Copyright (C) 2020 Edward Chernenko.

	This program is free software; you can redistribute it and/or modify
	it under the terms of the GNU General Public License as published by
	the Free Software Foundation; either version 3 of the License, or
	(at your option) any later version.

	This program is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU General Public License for more details.
*/

/**
 * @file
 * Unit test of ApproveEditConsequence.
 */

use MediaWiki\Moderation\ApproveEditConsequence;
use Wikimedia\TestingAccessWrapper;

/**
 * @group Database
 */
class ApproveEditConsequenceTest extends MediaWikiTestCase {
	/** @var string[] */
	protected $tablesUsed = [ 'user', 'page', 'logging' ];

	/**
	 * Verify that ApproveEditConsequence makes a new edit.
	 * @covers MediaWiki\Moderation\ApproveEditConsequence
	 * @dataProvider dataProviderApproveEdit
	 * @param $params
	 */
	public function testApproveEdit( array $params ) {
		$opt = (object)$params;

		$opt->bot = $opt->bot ?? false;
		$opt->minor = $opt->minor ?? false;
		$opt->summary = $opt->summary ?? 'Some summary ' . rand( 0, 100000 );

		$user = empty( $opt->anonymously ) ?
			self::getTestUser( $opt->bot ? [ 'bot' ] : [] )->getUser() :
			User::newFromName( '127.0.0.1', false );
		$title = Title::newFromText( $opt->title ?? 'UTPage-' . rand( 0, 100000 ) );
		$newText = 'New text ' . rand( 0, 100000 );

		// Creating the new page.
		// TODO: test modification of existing page, including edit conflicts.
		$baseRevId = 0;

		// Monitor RecentChange_save hook.
		// Note: we can't use $this->setTemporaryHook(), because it removes existing hook (if any),
		// and Moderation itself uses this hook (so it can't be removed during tests).
		global $wgHooks;
		$hooks = $wgHooks; // For setMwGlobals() below

		$hookFired = false;
		$hooks['RecentChange_save'][] = function ( RecentChange $rc )
			use ( &$hookFired, $user, $title, $newText, $baseRevId, $opt )
		{
			$hookFired = true;

			$this->assertEquals( $title->getFullText(), $rc->getTitle()->getFullText() );
			$this->assertEquals( $user->getName(), $rc->getPerformer()->getName() );
			$this->assertEquals( $user->getId(), $rc->getPerformer()->getId() );
			$this->assertEquals( $opt->minor ? 1 : 0, $rc->getAttribute( 'rc_minor' ) );
			$this->assertEquals( $opt->bot ? 1 : 0, $rc->getAttribute( 'rc_bot' ) );
			$this->assertEquals( $opt->summary, $rc->getAttribute( 'rc_comment' ) );

			$revid = $rc->getAttribute( 'rc_this_oldid' );
			$this->assertNotSame( 0, $revid );

			$rev = Revision::newFromId( $revid );
			$this->assertEquals( $baseRevId, $rev->getParentId() );
			$this->assertEquals( $newText, $rev->getContent()->getNativeData() );

			return true;
		};
		$this->setMwGlobals( 'wgHooks', $hooks );

		// Enter approve mode, as ApproveEditConsequence is not supposed to be used outside of it.
		// Otherwise this edit will just get queued for moderation again.
		ModerationCanSkip::enterApproveMode();

		// Create and run the Consequence.
		$consequence = new ApproveEditConsequence(
			$user, $title, $newText, $opt->summary, $opt->bot, $opt->minor, $baseRevId );
		$status = $consequence->run();

		$this->assertTrue( $status->isOK(),
			"ApproveEditConsequence failed: " . $status->getMessage()->plain() );
		$this->assertTrue( $hookFired, "ApproveEditConsequence: didn't edit anything." );
	}

	/**
	 * Provide datasets for testApproveEdit() runs.
	 * @return array
	 */
	public function dataProviderApproveEdit() {
		return [
			'logged-in edit' => [ [] ],
			'anonymous edit' => [ [ 'anonymously' => true ] ],
			'edit in Project namespace' => [ [ 'title' => 'Project:Title in another namespace' ] ],
			'edit in existing page' => [ [ 'existing' => true ] ],
			'edit with edit summary' => [ [ 'summary' => 'Summary 1' ] ],
			'bot edit' => [ [ 'bot' => true ] ],
			'minor edit' => [ [ 'minor' => true, 'existing' => true ] ]
		];
	}

	/**
	 * Disable post-approval global state.
	 */
	public function tearDown() {
		// If the previous test used Approve, it enabled "all edits should bypass moderation" mode.
		// Disable it now.
		$canSkip = TestingAccessWrapper::newFromClass( ModerationCanSkip::class );
		$canSkip->inApprove = false;

		parent::tearDown();
	}
}
