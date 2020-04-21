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
 * Unit test of ModerationNotifyModerator.
 */

use MediaWiki\Linker\LinkRenderer;
use MediaWiki\Moderation\EntryFactory;

require_once __DIR__ . "/autoload.php";

class ModerationNotifyModeratorTest extends ModerationUnitTestCase {
	/**
	 * Test that invalidateCache() clears the cache of ModerationNotifyModerator.
	 * @covers ModerationNotifyModerator
	 */
	public function testInvalidateCache() {
		$linkRenderer = $this->createMock( LinkRenderer::class );
		$entryFactory = $this->createMock( EntryFactory::class );
		$cache = $this->createMock( BagOStuff::class );

		$cache->expects( $this->once() )->method( 'makeKey' )->with(
			$this->identicalTo( 'moderation-newest-pending-timestamp' )
		)->willReturn( '{MockedCacheKey}' );
		$cache->expects( $this->once() )->method( 'delete' )->with(
			$this->identicalTo( '{MockedCacheKey}' )
		);

		'@phan-var LinkRenderer $linkRenderer';
		'@phan-var EntryFactory $entryFactory';
		'@phan-var BagOStuff $cache';

		$notify = new ModerationNotifyModerator( $linkRenderer, $entryFactory, $cache );
		$notify->invalidatePendingTime();
	}

	/**
	 * Test that setPendingTime() populates "getPendingTime cache" of ModerationNotifyModerator.
	 * @covers ModerationNotifyModerator
	 */
	public function testSetPendingTime() {
		$linkRenderer = $this->createMock( LinkRenderer::class );
		$entryFactory = $this->createMock( EntryFactory::class );
		$cache = $this->createMock( BagOStuff::class );

		$timestamp = '20100102030405';

		$cache->expects( $this->once() )->method( 'makeKey' )->with(
			$this->identicalTo( 'moderation-newest-pending-timestamp' )
		)->willReturn( '{MockedCacheKey}' );
		$cache->expects( $this->once() )->method( 'set' )->with(
			$this->identicalTo( '{MockedCacheKey}' ),
			$this->identicalTo( $timestamp ),
			$this->identicalTo( 24 * 60 * 60 )
		);

		'@phan-var LinkRenderer $linkRenderer';
		'@phan-var EntryFactory $entryFactory';
		'@phan-var BagOStuff $cache';

		$notify = new ModerationNotifyModerator( $linkRenderer, $entryFactory, $cache );
		$notify->setPendingTime( $timestamp );
	}

	/**
	 * Test that setPendingTime() populates "getSeen cache" of ModerationNotifyModerator.
	 * @covers ModerationNotifyModerator
	 */
	public function testSetSeen() {
		$linkRenderer = $this->createMock( LinkRenderer::class );
		$entryFactory = $this->createMock( EntryFactory::class );
		$cache = $this->createMock( BagOStuff::class );
		$user = $this->createMock( User::class );

		$userId = 654321;
		$timestamp = '20100102030405';

		$user->expects( $this->once() )->method( 'getId' )->willReturn( $userId );

		$cache->expects( $this->once() )->method( 'makeKey' )->with(
			$this->identicalTo( 'moderation-seen-timestamp' ),
			$this->identicalTo( "$userId" )
		)->willReturn( '{MockedCacheKey}' );
		$cache->expects( $this->once() )->method( 'set' )->with(
			$this->identicalTo( '{MockedCacheKey}' ),
			$this->identicalTo( $timestamp ),
			$this->identicalTo( 7 * 24 * 60 * 60 )
		);

		'@phan-var LinkRenderer $linkRenderer';
		'@phan-var EntryFactory $entryFactory';
		'@phan-var BagOStuff $cache';
		'@phan-var User $user';

		$notify = new ModerationNotifyModerator( $linkRenderer, $entryFactory, $cache );
		$notify->setSeen( $user, $timestamp );
	}

	/**
	 * Check the handler of "GetNewMessagesAlert" hook (which is called by MediaWiki core).
	 * @param array $newtalks Parameter of GetNewMessagesAlert hook.
	 * @param bool $expectShown If true, our notification is expected to be shown.
	 * @dataProvider dataProviderNotifyHook
	 *
	 * @covers ModerationNotifyModerator
	 */
	public function testNotifyHook( array $newtalks, $expectShown ) {
		$linkRenderer = $this->createMock( LinkRenderer::class );
		$entryFactory = $this->createMock( EntryFactory::class );
		$user = $this->createMock( User::class );
		$out = $this->createMock( OutputPage::class );

		if ( $expectShown ) {
			$out->expects( $this->once() )->method( 'msg' )->with(
				$this->identicalTo( 'moderation-new-changes-appeared' )
			)->willReturn( new RawMessage( '{TextOfNotificationLink}' ) );

			$linkRenderer->expects( $this->once() )->method( 'makeLink' )->with(
				$this->IsInstanceOf( Title::class ),
				$this->identicalTo( '{TextOfNotificationLink}' )
			)->will( $this->returnCallback( function ( Title $title, $text ) {
				$this->assertTrue( $title->isSpecial( 'Moderation' ),
					"Link in the notification doesn't point to Special:Moderation." );
				return '{NotificationLink}';
			} ) );
		}

		'@phan-var LinkRenderer $linkRenderer';
		'@phan-var EntryFactory $entryFactory';

		// This test checks whether this method correctly works as a hook, so install it as a hook.
		$notify = new ModerationNotifyModerator( $linkRenderer, $entryFactory, new HashBagOStuff() );
		$this->setService( 'Moderation.NotifyModerator', $notify );
		$this->setTemporaryHook( 'GetNewMessagesAlert',
			'ModerationNotifyModerator::onGetNewMessagesAlert' );

		$newMessagesAlert = '{AnotherExistingNotification}';

		$result = Hooks::run( 'GetNewMessagesAlert',
			[ &$newMessagesAlert, $newtalks, $user, $out ] );

		$this->assertTrue( $result,
			"Return value of GetNewMessagesAlert hook doesn't match expected." );

		if ( $expectShown ) {
			$this->assertSame( '{AnotherExistingNotification}{NotificationLink}', $newMessagesAlert,
				"Our notification wasn't added." );
		} else {
			$this->assertSame( '{AnotherExistingNotification}', $newMessagesAlert,
				"Text of \$newMessagesAlert was modified when we didn't intend to add our notification." );
		}
	}

	/**
	 * Provide datasets for testNotifyHook() runs.
	 * @return array
	 */
	public function dataProviderNotifyHook() {
		return [

			// Situation 1: "You have new messages" notification (from user's talkpage) doesn't exist.
			// Then ModerationNotifyModerator::onGetNewMessagesAlert() must add its own notification.
			'no "You have new messages", should show our notification' => [ [], true ],

			// Situation 2: "You have new messages" notification already exists.
			// Because it is more important than "new changes are awaiting moderation",
			// in this case we purposely don't show notification of ModerationNotifyModerator.
			'existing "You have new messages" should suppress our notification' =>
				[ [ 'something' => 'here' ], false ]
		];
	}

	/**
	 * Check the handler of "BeforeInitialize" hook (which is called by MediaWiki core).
	 * @param bool $expectInstalled True to assert that GetNewMessagesAlert hook handler was installed.
	 * @dataProvider dataProviderInstallHook
	 *
	 * @covers ModerationNotifyModerator
	 */
	public function testInstallHook( $expectInstalled, array $opt ) {
		$isModerator = $opt['isModerator'] ?? true;
		$isSpecialModeration = $opt['isSpecialModeration'] ?? false;
		$pendingTimeCached = $opt['pendingTimeCached'] ?? false;
		$pendingTimeUncached = $opt['pendingTimeUncached'] ?? false;
		$seenTime = $opt['seenTime'] ?? false;

		$linkRenderer = $this->createMock( LinkRenderer::class );
		$entryFactory = $this->createMock( EntryFactory::class );

		$title = $this->createMock( Title::class );
		$out = $this->createMock( OutputPage::class );
		$user = $this->createMock( User::class );

		$userId = 456;
		$user->expects( $this->any() )->method( 'getId' )->willReturn( $userId );

		$user->expects( $this->once() )->method( 'isAllowed' )->with(
			$this->identicalTo( 'moderation' )
		)->willReturn( $isModerator );

		$cache = new HashBagOStuff();
		$cache->set( $cache->makeKey( 'moderation-newest-pending-timestamp' ), $pendingTimeCached );
		$cache->set( $cache->makeKey( 'moderation-seen-timestamp', "$userId" ), $seenTime );

		$expectedCacheContents = [];

		if ( !$isModerator ) {
			// Hook won't be installed.
			$title->expects( $this->never() )->method( 'isSpecial' );
		} else {
			$title->expects( $this->once() )->method( 'isSpecial' )->with(
				$this->identicalTo( 'Moderation' )
			)->willReturn( $isSpecialModeration );

			if ( !$isSpecialModeration && $pendingTimeCached === false ) {
				// PendingTime wasn't found in the cache, so it will be loaded from the database.
				$entryFactory->expects( $this->once() )->method( 'loadRow' )->with(
					$this->identicalTo( [ 'mod_rejected' => 0, 'mod_merged_revid' => 0 ] ),
					$this->identicalTo( [ 'mod_timestamp AS timestamp' ] ),
					$this->identicalTo( DB_REPLICA ),
					$this->identicalTo( [ 'USE INDEX' => 'moderation_folder_pending' ] )
				)->willReturn( $pendingTimeUncached ? (object)[ 'timestamp' => $pendingTimeUncached ] : false );

				// Obtained $pendingTimeUncached should be stored in the cache (used in assertions below).
				$cacheKey = $cache->makeKey( 'moderation-newest-pending-timestamp' );
				$expectedCacheContents[$cacheKey] = $pendingTimeUncached ?: 0;
			}
		}

		'@phan-var LinkRenderer $linkRenderer';
		'@phan-var EntryFactory $entryFactory';

		// This test checks whether this method correctly works as a hook, so install it as a hook.
		$notify = new ModerationNotifyModerator( $linkRenderer, $entryFactory, $cache );
		$this->setService( 'Moderation.NotifyModerator', $notify );

		$this->hideDeprecated( 'Hooks::clear' );
		Hooks::clear( 'GetNewMessagesAlert' );

		$this->hideDeprecated( 'Hooks::clear' );
		Hooks::clear( 'EchoCanAbortNewMessagesAlert' );

		// Run the tested hook.
		$unused = null;
		$result = Hooks::run( 'BeforeInitialize',
			[ &$title, &$unused, &$out, &$user, new FauxRequest( [] ), new MediaWiki() ] );
		$this->assertTrue( $result, "BeforeInitialize hook didn't return true." );

		$hooksAfterTest = Hooks::getHandlers( 'GetNewMessagesAlert' );

		// Analyze the situation afterwards.
		foreach ( $expectedCacheContents as $key => $val ) {
			$this->assertSame( $val, $cache->get( $key ), "Cache[$key] != $val" );
		}

		if ( !$expectInstalled ) {
			$this->assertNotContains(
				'ModerationNotifyModerator::onGetNewMessagesAlert',
				Hooks::getHandlers( 'GetNewMessagesAlert' ),
				"onGetNewMessagesAlert must NOT be installed."
			);
			$this->assertNotContains(
				'ModerationNotifyModerator::onEchoCanAbortNewMessagesAlert',
				Hooks::getHandlers( 'EchoCanAbortNewMessagesAlert' ),
				"onEchoCanAbortNewMessagesAlert must NOT be installed."
			);
			return;
		}

		$this->assertContains(
			'ModerationNotifyModerator::onGetNewMessagesAlert',
			Hooks::getHandlers( 'GetNewMessagesAlert' ),
			"onGetNewMessagesAlert must be installed."
		);
		$this->assertContains(
			'ModerationNotifyModerator::onEchoCanAbortNewMessagesAlert',
			Hooks::getHandlers( 'EchoCanAbortNewMessagesAlert' ),
			"onEchoCanAbortNewMessagesAlert must be installed."
		);
	}

	/**
	 * Provide datasets for testInstallHook() runs.
	 * @return array
	 */
	public function dataProviderInstallHook() {
		return [
			'Not installed (not a moderator)' => [ false, [ 'isModerator' => false ] ],
			'Not installed (on Special:Moderation)' =>
				[ false, [ 'isSpecialModeration' => true ] ],
			'Not installed (no pending edits, according to cache)' =>
				[ false, [ 'pendingTimeCached' => 0 ] ],
			'Not installed (no pending edits, according to the database)' =>
				[ false, [ 'pendingTimeUncached' => 0 ] ],
			'Not installed (SeenTime is newer than the most recent pending edit)' =>
				[ false, [
					'pendingTimeUncached' => '2010010203040506',
					'seenTime' => '2015010203040506'
				] ],
			'Installed (SeenTime is unknown, which means that this moderator hasn\'t visited for a while)' =>
				[ true, [
					'pendingTimeUncached' => '2010010203040506',
					'seenTime' => false
				] ],
			'Installed (SeenTime is less than pendingTimeUncached)' =>
				[ true, [
					'pendingTimeUncached' => '2010010203040506',
					'seenTime' => '2005010203040506'
				] ],
			'Installed (SeenTime is less than pendingTimeCached)' =>
				[ true, [
					'pendingTimeCached' => '2010010203040506',
					'seenTime' => '2005010203040506'
				] ],
		];
	}

}
