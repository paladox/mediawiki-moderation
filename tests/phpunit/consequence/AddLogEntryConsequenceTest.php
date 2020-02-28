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
 * Unit test of AddLogEntryConsequence.
 */

use MediaWiki\Moderation\AddLogEntryConsequence;
use Wikimedia\TestingAccessWrapper;

/**
 * @group Database
 */
class AddLogEntryConsequenceTest extends MediaWikiTestCase {

	/** @var string[] */
	protected $tablesUsed = [ 'user', 'logging' ];

	/**
	 * Verify that AddLogEntryConsequence creates a log entry when executed.
	 * @param string $subtype
	 * @param string $username
	 * @param string $pageName
	 * @param array $params
	 * @param bool $runApproveHook
	 * @covers MediaWiki\Moderation\AddLogEntryConsequence
	 * @dataProvider dataProviderAddLogEntry
	 */
	public function testAddLogEntry( $subtype, $username, $pageName, array $params,
		$runApproveHook
	) {
		$user = User::createNew( $username );
		$title = Title::newFromText( $pageName );

		// Clean ApproveHook::$logEntriesToFix before the test.
		$approveHook = TestingAccessWrapper::newFromClass( ModerationApproveHook::class );
		$approveHook->logEntriesToFix = [];

		// Create and run the Consequence.
		$consequence = new AddLogEntryConsequence( $subtype, $user, $title, $params,
			$runApproveHook );
		$consequence->run();

		// Test the new LogEntry that appeared in the database.
		$dbw = wfGetDB( DB_MASTER );
		$logid = $dbw->selectField( 'logging', 'log_id', '', __METHOD__ );

		$logEntry = DatabaseLogEntry::newFromId( $logid, $dbw );

		$this->assertEquals( 'moderation', $logEntry->getType() );
		$this->assertEquals( $subtype, $logEntry->getSubtype() );
		$this->assertEquals( $user->getName(), $logEntry->getPerformer()->getName() );
		$this->assertEquals( $title->getPrefixedText(),
			$logEntry->getTarget()->getPrefixedText() );
		$this->assertEquals( $params, $logEntry->getParameters() );

		// Check whether ApproveHook has queued this LogEntry for modification.
		$entriesToFix = $approveHook->logEntriesToFix;
		if ( $runApproveHook && empty( $params['revid'] ) ) {
			// ApproveHook must populate "revid" parameter of this LogEntry.
			$this->assertArrayHasKey( $logid, $entriesToFix );
			$this->assertInstanceOf( ManualLogEntry::class, $entriesToFix[$logid] );
		} else {
			// This logentry doesn't need ApproveHook.
			$this->assertArrayNotHasKey( $logid, $entriesToFix );
		}
	}

	/**
	 * Provide datasets for testAddLogEntry() runs.
	 */
	public function dataProviderAddLogEntry() {
		return [
			'normal LogEntry' => [
				'reject',
				'Some moderator',
				'Talk:Title in non-main namespace, spaces_and_underscores',
				[
					'someparam' => 123,
					'anotherparam' => 'anothervalue',
					'nonscalar' => [ 'key1' => [ 11, 12 ], 'key2' => [ 'val21', 'val22' ] ],
					'revid' => null
				],
				false // Don't run ApproveHook
			],
			'logEntry with missing revid parameter (must be fixed by ApproveHook)' => [
				'approve',
				'AnotherModerator',
				'SampleArticle',
				[ 'revid' => null ],
				true // Run ApproveHook
			],
			'logEntry that doesn\'t need to be fixed by ApproveHook' => [
				'approve',
				'AnotherModerator',
				'SampleArticle',
				[ 'revid' => 123 ],
				true // Run ApproveHook
			]
		];
	}
}