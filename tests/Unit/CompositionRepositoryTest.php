<?php

declare(strict_types=1);

namespace UniversalCommerceBundles\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use UniversalCommerceBundles\Application\CompositionRepository;
use UniversalCommerceBundles\Domain\Composition;
use UniversalCommerceBundles\Domain\MetaKeys;

/**
 * CompositionRepository stores everything as ordinary WordPress post meta
 * (no WooCommerce symbols) — verified here via Brain\Monkey function mocks
 * standing in for the real postmeta table.
 */
final class CompositionRepositoryTest extends TestCase {

	private CompositionRepository $repository;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->repository = new CompositionRepository();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_is_kit_reads_the_marker_meta(): void {
		Functions\expect( 'get_post_meta' )
			->once()
			->with( 42, MetaKeys::PRODUCT_IS_KIT, true )
			->andReturn( 'yes' );

		self::assertTrue( $this->repository->isKit( 42 ) );
	}

	public function test_mark_as_kit_writes_yes(): void {
		Functions\expect( 'update_post_meta' )
			->once()
			->with( 42, MetaKeys::PRODUCT_IS_KIT, 'yes' );

		$this->repository->markAsKit( 42 );

		self::assertTrue( true, 'update_post_meta was called as expected above.' );
	}

	public function test_get_composition_decodes_stored_json(): void {
		$rows = array(
			array(
				'stock_managed_id' => 1,
				'product_id'       => 1,
				'variation_id'     => 0,
				'qty_per_kit'      => 2,
			),
		);

		Functions\expect( 'get_post_meta' )
			->once()
			->with( 42, MetaKeys::PRODUCT_COMPOSITION, true )
			->andReturn( json_encode( $rows ) );

		$composition = $this->repository->getComposition( 42 );

		self::assertSame( $rows, $composition->toArray() );
	}

	public function test_get_composition_is_empty_when_nothing_stored(): void {
		Functions\expect( 'get_post_meta' )->once()->andReturn( '' );

		self::assertTrue( $this->repository->getComposition( 42 )->isEmpty() );
	}

	public function test_save_composition_writes_json_encoded_rows(): void {
		$composition = Composition::fromRows(
			array(
				array(
					'stock_managed_id' => 1,
					'product_id'       => 1,
					'variation_id'     => 0,
					'qty_per_kit'      => 1,
				),
			)
		);

		Functions\expect( 'wp_json_encode' )->once()->andReturnUsing( 'json_encode' );
		Functions\expect( 'update_post_meta' )
			->once()
			->with( 42, MetaKeys::PRODUCT_COMPOSITION, json_encode( $composition->toArray() ) );

		$this->repository->saveComposition( 42, $composition );

		self::assertTrue( true, 'update_post_meta was called as expected above.' );
	}

	public function test_cached_validity_hint_round_trips(): void {
		Functions\expect( 'update_post_meta' )->once()->with( 42, MetaKeys::PRODUCT_COMPOSITION_VALID_HINT, 'yes' );
		$this->repository->setCachedValidityHint( 42, true );

		Functions\expect( 'get_post_meta' )->once()->andReturn( 'yes' );
		self::assertTrue( $this->repository->getCachedValidityHint( 42 ) );
	}
}
