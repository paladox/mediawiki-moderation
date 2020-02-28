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
 * Verifies that ModerationAction subclasses have consequences like AddLogEntryConsequence.
 */

use MediaWiki\Moderation\AddLogEntryConsequence;
use MediaWiki\Moderation\ApproveEditConsequence;
use MediaWiki\Moderation\BlockUserConsequence;
use MediaWiki\Moderation\DeleteRowFromModerationTableConsequence;
use MediaWiki\Moderation\IConsequence;
use MediaWiki\Moderation\InvalidatePendingTimeCacheConsequence;
use MediaWiki\Moderation\MarkAsConflictConsequence;
use MediaWiki\Moderation\MockConsequenceManager;
use MediaWiki\Moderation\ModifyPendingChangeConsequence;
use MediaWiki\Moderation\RejectBatchConsequence;
use MediaWiki\Moderation\RejectOneConsequence;
use MediaWiki\Moderation\UnblockUserConsequence;
use Wikimedia\TestingAccessWrapper;

require_once __DIR__ . "/ConsequenceTestTrait.php";

/**
 * @group Database
 */
class ActionsHaveConsequencesTest extends MediaWikiTestCase {
	use ConsequenceTestTrait;

	/** @var int */
	protected $modid;

	/** @var User */
	protected $authorUser;

	/** @var User */
	protected $moderatorUser;

	/** @var Title */
	protected $title;

	/** @var string */
	protected $text;

	/** @var string */
	protected $summary;

	/**
	 * @var ModerationError|null
	 * Exception that happened during getConsequences(), if any.
	 */
	protected $thrownException;

	/** @var string[] */
	protected $tablesUsed = [ 'user', 'moderation', 'moderation_block' ];

	/**
	 * Test consequences of modaction=reject.
	 * @covers ModerationActionReject::executeRejectOne
	 */
	public function testReject() {
		$expected = [
			new RejectOneConsequence( $this->modid, $this->moderatorUser ),
			new AddLogEntryConsequence(
				'reject',
				$this->moderatorUser,
				$this->title,
				[
					'modid' => $this->modid,
					'user' => $this->authorUser->getId(),
					'user_text' => $this->authorUser->getName()
				]
			),
			new InvalidatePendingTimeCacheConsequence()
		];
		$actual = $this->getConsequences( 'reject', [ RejectOneConsequence::class, 1 ] );

		$this->assertConsequencesEqual( $expected, $actual );
	}

	/**
	 * Test consequences of modaction=block.
	 * @covers ModerationActionBlock::execute
	 */
	public function testBlock() {
		$expected = [
			new BlockUserConsequence(
				$this->authorUser->getId(),
				$this->authorUser->getName(),
				$this->moderatorUser
			),
			new AddLogEntryConsequence(
				'block',
				$this->moderatorUser,
				$this->authorUser->getUserPage()
			)
		];
		$actual = $this->getConsequences( 'block', [ BlockUserConsequence::class, true ] );

		$this->assertConsequencesEqual( $expected, $actual );
	}

	/**
	 * Test consequences of modaction=block when the user is already blocked.
	 * @covers ModerationActionBlock::execute
	 */
	public function testNoopBlock() {
		$expected = [
			new BlockUserConsequence(
				$this->authorUser->getId(),
				$this->authorUser->getName(),
				$this->moderatorUser
			)
			// No AddLogEntryConsequence, because the user was already modblocked.
		];
		$actual = $this->getConsequences( 'block', [
			// Mocked manager won't run BlockUserConsequence and would instead return "false",
			// which is what BlockUserConsequence does when the user is already modblocked.
			// That fact should be checked by unit test of BlockUserConsequence itself, not here.
			BlockUserConsequence::class,
			false
		] );

		$this->assertConsequencesEqual( $expected, $actual );
	}

	/**
	 * Test consequences of modaction=unblock.
	 * @covers ModerationActionBlock::execute
	 */
	public function testUnblock() {
		$expected = [
			new UnblockUserConsequence( $this->authorUser->getName() ),
			new AddLogEntryConsequence(
				'unblock',
				$this->moderatorUser,
				$this->authorUser->getUserPage()
			)
		];
		$actual = $this->getConsequences( 'unblock', [ UnblockUserConsequence::class, true ] );

		$this->assertConsequencesEqual( $expected, $actual );
	}

	/**
	 * Test consequences of modaction=unblock when the user is already not blocked.
	 * @covers ModerationActionBlock::execute
	 */
	public function testNoopUnblock() {
		$expected = [
			new UnblockUserConsequence( $this->authorUser->getName() ),
			// No AddLogEntryConsequence, because the user wasn't modblocked to begin with.
		];
		$actual = $this->getConsequences( 'unblock', [
			// Mocked return value from UnblockUserConsequence: simulate "nothing changed".
			UnblockUserConsequence::class,
			false
		] );

		$this->assertConsequencesEqual( $expected, $actual );
	}

	/**
	 * Test consequences of modaction=approve.
	 * @covers ModerationActionApprove::executeApproveOne
	 */
	public function testApprove() {
		$actual = $this->getConsequences( 'approve',
			[ ApproveEditConsequence::class, Status::newGood() ]
		);
		$expected = [
			new ApproveEditConsequence(
				$this->authorUser,
				$this->title,
				$this->text,
				$this->summary,
				false, // isBot
				false, // isMinor
				0 // $baseRevId
			),
			new AddLogEntryConsequence(
				'approve',
				$this->moderatorUser,
				$this->title,
				[
					'revid' => $this->title->getLatestRevID( IDBAccessObject::READ_LATEST )
				],
				true // ApproveHook enabled
			),
			new DeleteRowFromModerationTableConsequence( $this->modid ),
			new InvalidatePendingTimeCacheConsequence()
		];

		$this->assertConsequencesEqual( $expected, $actual );
	}

	/**
	 * Test consequences of modaction=approve when it results in edit conflict.
	 * @covers ModerationEntryEdit::doApprove
	 */
	public function testApproveEditConflict() {
		$actual = $this->getConsequences( 'approve',
			[ ApproveEditConsequence::class, Status::newFatal( 'moderation-edit-conflict' ) ]
		);
		$expected = [
			new ApproveEditConsequence(
				$this->authorUser,
				$this->title,
				$this->text,
				$this->summary,
				false, // isBot
				false, // isMinor
				0 // $baseRevId
			),
			new MarkAsConflictConsequence( $this->modid )
		];

		$this->assertConsequencesEqual( $expected, $actual );
		$this->assertNotNull( $this->thrownException,
			"Despite the edit conflict, modaction=approve didn't throw an exception." );
		$this->assertTrue( $this->thrownException->status->hasMessage( 'moderation-edit-conflict' ),
			"Status of modaction=approve doesn't have \"moderation-edit-conflict\" message." );
	}

	// NOTE: running Approve without process isolation (like in ModerationTestsuite framework)
	// would confuse ApproveHooks class. Need a way to clean ApproveHooks between tests.
	// If ApproveHooks themselves use consequences, mocked Manager can be used too.

	/**
	 * Test consequences of modaction=approveall.
	 * @covers ModerationActionApprove::executeApproveAll
	 */
	public function testApproveAll() {
		$actual = $this->getConsequences( 'approveall',
			[ ApproveEditConsequence::class, Status::newGood() ]
		);
		$expected = [
			new ApproveEditConsequence(
				$this->authorUser,
				$this->title,
				$this->text,
				$this->summary,
				false, // isBot
				false, // isMinor
				0 // $baseRevId
			),
			new AddLogEntryConsequence(
				'approve',
				$this->moderatorUser,
				$this->title,
				[
					'revid' => $this->title->getLatestRevID( IDBAccessObject::READ_LATEST )
				],
				true // ApproveHook enabled
			),
			new DeleteRowFromModerationTableConsequence( $this->modid ),
			new AddLogEntryConsequence(
				'approveall',
				$this->moderatorUser,
				$this->authorUser->getUserPage(),
				[
					'4::count' => 1
				]
			),
			new InvalidatePendingTimeCacheConsequence()
		];

		$this->assertConsequencesEqual( $expected, $actual );
	}

	/**
	 * Test consequences of modaction=editchangesubmit.
	 * @covers ModerationActionEditChangeSubmit::execute
	 */
	public function testEditChangeSubmit() {
		$dbw = wfGetDB( DB_MASTER );
		$row = $dbw->selectRow( 'moderation', [ 'mod_text', 'mod_comment' ], '', __METHOD__ );

		$expected = [
			new ModifyPendingChangeConsequence(
				$this->modid,
				'Some new text',
				'Some new summary',
				$row->mod_text,
				$row->mod_comment,
				$this->title,
				$this->authorUser
			),
			new AddLogEntryConsequence(
				'editchange',
				$this->moderatorUser,
				$this->title,
				[
					'modid' => $this->modid
				]
			)
		];

		$this->setMwGlobals( 'wgModerationEnableEditChange', true );
		$actual = $this->getConsequences( 'editchangesubmit',
			[ ModifyPendingChangeConsequence::class, true ],
			[
				'wpTextbox1' => 'Some new text',
				'wpSummary' => 'Some new summary'
			]
		);

		$this->assertConsequencesEqual( $expected, $actual );
	}

	/**
	 * Test consequences of modaction=editchangesubmit when both text and summary are unchanged.
	 * @covers ModerationActionEditChangeSubmit::execute
	 */
	public function testNoopEditChangeSubmit() {
		$dbw = wfGetDB( DB_MASTER );
		$row = $dbw->selectRow( 'moderation', [ 'mod_text', 'mod_comment' ], '', __METHOD__ );

		$expected = [
			new ModifyPendingChangeConsequence(
				$this->modid,
				'Some new text',
				'Some new summary',
				$row->mod_text,
				$row->mod_comment,
				$this->title,
				$this->authorUser
			),
			// No AddLogEntryConsequence, because there were no changes.
		];

		$this->setMwGlobals( 'wgModerationEnableEditChange', true );
		$actual = $this->getConsequences( 'editchangesubmit',
			[
				// Mocked return value from ModifyPendingChangeConsequence:
				// simulate "nothing changed".
				ModifyPendingChangeConsequence::class,
				false
			],
			[
				'wpTextbox1' => 'Some new text',
				'wpSummary' => 'Some new summary'
			]
		);

		$this->assertConsequencesEqual( $expected, $actual );
	}

	/**
	 * Test consequences of modaction=rejectall.
	 * @covers ModerationActionReject::executeRejectAll
	 */
	public function testRejectAll() {
		$expected = [
			new RejectBatchConsequence( [ $this->modid ], $this->moderatorUser ),
			new AddLogEntryConsequence(
				'rejectall',
				$this->moderatorUser,
				$this->authorUser->getUserPage(),
				[
					'4::count' => 1
				]
			),
			new InvalidatePendingTimeCacheConsequence()
		];
		$actual = $this->getConsequences( 'rejectall', [ RejectBatchConsequence::class, 1 ] );

		$this->assertConsequencesEqual( $expected, $actual );
	}

	/**
	 * Ensure that readonly actions don't have any consequences.
	 * @param string $modaction
	 * @param Closure|null $beforeCallback Will be called before the test.
	 * @dataProvider dataProviderNoConsequenceActions
	 * @coversNothing
	 */
	public function testNoConsequenceActions( $modaction, Closure $beforeCallback = null ) {
		if ( $beforeCallback ) {
			$beforeCallback->call( $this );
		}

		$this->assertConsequencesEqual( [], $this->getConsequences( $modaction ) );
	}

	/**
	 * Provide datasets for testNoConsequenceActions() runs.
	 * @return array
	 */
	public function dataProviderNoConsequenceActions() {
		return [
			[ 'show', null ],
			[ 'showimg', null ],
			[ 'preview', null ],
			[ 'merge', function () {
				$dbw = wfGetDB( DB_MASTER );
				$dbw->update( 'moderation',
					[ 'mod_conflict' => 1 ],
					[ 'mod_id' => $this->modid ]
				);
			} ],
			[ 'editchange', function () {
				$this->setMwGlobals( 'wgModerationEnableEditChange', true );
			} ]
		];
	}

	/**
	 * Get an array of consequences after running $modaction on an edit that was queued in setUp().
	 * @param string $modaction
	 * @param array $mockedResult Parameters to pass to $manager->mockResult().
	 * @param array $extraParams Additional HTTP request parameters when running ModerationAction.
	 * @return IConsequence[]
	 *
	 * @phan-param array{0:class-string,1:mixed}|null $mockedResult
	 */
	private function getConsequences( $modaction, array $mockedResult = null, $extraParams = [] ) {
		// Replace real ConsequenceManager with a mock.
		list( $scope, $manager ) = MockConsequenceManager::install();

		// Invoke ModerationAction with requested modid.
		$request = new FauxRequest( [
			'modaction' => $modaction,
			'modid' => $this->modid
		] + $extraParams );
		$context = new DerivativeContext( RequestContext::getMain() );
		$context->setTitle( SpecialPage::getTitleFor( 'Moderation' ) );
		$context->setRequest( $request );
		$context->setUser( $this->moderatorUser );

		if ( $mockedResult ) {
			$manager->mockResult( ...$mockedResult );
		}

		$this->thrownException = null;

		$action = ModerationAction::factory( $context );
		try {
			$action->run();
		} catch ( ModerationError $exception ) {
			$this->thrownException = $exception;
		}

		return $manager->getConsequences();
	}

	/**
	 * Queue an edit for moderation. Populate all fields ($this->modid, etc.) used by actual tests.
	 */
	public function setUp() {
		parent::setUp();

		$name = $this->getName();
		if ( $name == 'testValidCovers' || $name == 'testMediaWikiTestCaseParentSetupCalled' ) {
			return;
		}

		$this->authorUser = self::getTestUser()->getUser();
		$this->moderatorUser = self::getTestUser( [ 'moderator', 'automoderated' ] )->getUser();

		$this->title = Title::newFromText( 'UTPage-' . rand( 0, 100000 ) );
		$this->text = 'Some text ' . rand( 0, 100000 );
		$this->summary = 'Sample edit summary ' . rand( 0, 100000 );

		$page = WikiPage::factory( $this->title );
		$page->doEditContent(
			ContentHandler::makeContent( $this->text, null, CONTENT_MODEL_WIKITEXT ),
			$this->summary,
			EDIT_INTERNAL,
			false,
			$this->authorUser
		);

		$dbw = wfGetDB( DB_MASTER );
		$this->modid = (int)$dbw->selectField( 'moderation', 'mod_id', '', __METHOD__ );
		$this->assertNotSame( 0, $this->modid );
	}

	/**
	 * Queue an edit for moderation. Populate all fields ($this->modid, etc.) used by actual tests.
	 */
	public function tearDown() {
		// If the previous test used Approve, it enabled "all edits should bypass moderation" mode.
		// Disable it now.
		$canSkip = TestingAccessWrapper::newFromClass( ModerationCanSkip::class );
		$canSkip->inApprove = false;

		parent::tearDown();
	}
}