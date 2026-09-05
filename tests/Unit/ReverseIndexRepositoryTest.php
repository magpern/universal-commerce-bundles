<?php

declare(strict_types=1);

namespace UniversalCommerceBundles\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use UniversalCommerceBundles\Application\ReverseIndexRepository;

/**
 * The component => kits reverse index (docs/ARCHITECTURE.md, "Invalidation
 * and reverse lookup"), stored as one option — verified here against a
 * simple in-memory stand-in for get_option()/update_option() (Brain\Monkey
 * doesn't emulate stateful storage, so a tiny fake fills that role).
 */
final class ReverseIndexRepositoryTest extends TestCase {

	private ReverseIndexRepository $repository;

	/** @var array<string, mixed> */
	private array $fakeOptions = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->fakeOptions = array();
		$this->repository  = new ReverseIndexRepository();

		Functions\when( 'get_option' )->alias(
			function ( string $name, $fallback = false ) {
				return $this->fakeOptions[ $name ] ?? $fallback;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( string $name, $value ): bool {
				$this->fakeOptions[ $name ] = $value;

				return true;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_a_fresh_component_has_no_kits(): void {
		self::assertSame( array(), $this->repository->kitsForComponent( 1 ) );
	}

	public function test_set_kit_components_indexes_every_stock_managed_id(): void {
		$this->repository->setKitComponents( 100, array( 1, 2 ) );

		self::assertSame( array( 100 ), $this->repository->kitsForComponent( 1 ) );
		self::assertSame( array( 100 ), $this->repository->kitsForComponent( 2 ) );
	}

	public function test_shared_component_across_two_kits(): void {
		$this->repository->setKitComponents( 100, array( 1 ) );
		$this->repository->setKitComponents( 200, array( 1 ) );

		self::assertSame( array( 100, 200 ), $this->repository->kitsForComponent( 1 ) );
	}

	public function test_re_setting_a_kits_components_removes_stale_entries(): void {
		$this->repository->setKitComponents( 100, array( 1, 2 ) );
		$this->repository->setKitComponents( 100, array( 2 ) );

		self::assertSame( array(), $this->repository->kitsForComponent( 1 ) );
		self::assertSame( array( 100 ), $this->repository->kitsForComponent( 2 ) );
	}

	public function test_remove_kit_clears_every_entry(): void {
		$this->repository->setKitComponents( 100, array( 1, 2 ) );
		$this->repository->removeKit( 100 );

		self::assertSame( array(), $this->repository->kitsForComponent( 1 ) );
		self::assertSame( array(), $this->repository->kitsForComponent( 2 ) );
	}

	public function test_rebuild_replaces_the_whole_index(): void {
		$this->repository->setKitComponents( 999, array( 5 ) );

		$this->repository->rebuild(
			array(
				100 => array( 1, 2 ),
				200 => array( 2 ),
			)
		);

		self::assertSame( array(), $this->repository->kitsForComponent( 5 ) );
		self::assertSame( array( 100 ), $this->repository->kitsForComponent( 1 ) );
		self::assertSame( array( 100, 200 ), $this->repository->kitsForComponent( 2 ) );
	}
}
