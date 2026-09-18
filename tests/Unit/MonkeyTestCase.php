<?php
/**
 * Base test case wiring Brain Monkey's setUp/tearDown into PHPUnit.
 *
 * Brain Monkey 2.x does not ship its own MonkeyTestCase; per its docs
 * (docs/wordpress-specific-tools/wordpress-setup.md) consumers provide a
 * base class that calls Brain\Monkey\setUp() before and tearDown() after
 * each test, and integrates Mockery expectations with PHPUnit.
 *
 * @package OpenCodeConnector
 */

namespace OpenCodeConnector\Tests\Unit;

use Brain\Monkey;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * Shared base for all unit tests using Brain Monkey.
 */
abstract class MonkeyTestCase extends TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * Boot Brain Monkey (and, lazily, Patchwork) before every test.
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	/**
	 * Reset Brain Monkey hooks/stubs and close Mockery after every test.
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}
}
