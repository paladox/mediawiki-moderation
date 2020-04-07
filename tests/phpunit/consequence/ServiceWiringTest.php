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
 * Unit test of ServiceWiring.
 */

use MediaWiki\MediaWikiServices;

require_once __DIR__ . "/autoload.php";

class ServiceWiringTest extends ModerationUnitTestCase {
	/**
	 * Ensure that all Moderation services are instantiated without errors.
	 * @dataProvider dataProviderGetService
	 * @covers MediaWiki\Moderation\ServiceWiring
	 */
	public function testGetService( $serviceName ) {
		$this->resetServices();

		$services = MediaWikiServices::getInstance();
		$result = $services->getService( $serviceName );

		// TODO: check $result (technically there are strict return value typehints,
		// but we can't trust tested code to have them)
		$this->assertNotNull( $result );
	}

	/**
	 * Provide datasets for testGetService() runs.
	 * @return array
	 */
	public function dataProviderGetService() {
		return [
			[ 'Moderation.ActionFactory' ],
			[ 'Moderation.ActionLinkRenderer' ],
			[ 'Moderation.ApproveHook' ],
			[ 'Moderation.CanSkip' ],
			[ 'Moderation.ConsequenceManager' ],
			[ 'Moderation.EditFormOptions' ],
			[ 'Moderation.EntryFactory' ],
			[ 'Moderation.NotifyModerator' ],
			[ 'Moderation.Preload' ],
			[ 'Moderation.TimestampFormatter' ],
		];
	}
}
