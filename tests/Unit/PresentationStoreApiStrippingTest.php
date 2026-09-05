<?php

declare(strict_types=1);

namespace UniversalCommerceBundles\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use UniversalCommerceBundles\Domain\MetaKeys;
use UniversalCommerceBundles\Woo\Presentation;

/**
 * Live HTTP validation (docs/m1-closure.md, acceptance case 1) found that
 * Presentation::stripChildrenFromResponseData() checked a
 * `$item['ucb_component']` key the real Store API response never
 * contains — every genuine cart response leaked hidden child lines. The
 * fix cross-references each Store API item's own `key` against
 * `WC()->cart->get_cart()`, where CartConstruction already tags hidden
 * children with MetaKeys::LINE_COMPONENT. This test exercises the private
 * stripping method directly (via reflection) against a fake `WC()`, so it
 * does not need real WP_REST_Response/WP_REST_Server objects — see the
 * class docblock on the exercised method for why the previous key check
 * was dead code.
 */
final class PresentationStoreApiStrippingTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @param array<string, array<string, mixed>> $cartContents Keyed by cart item key.
	 */
	private function stubCart( array $cartContents ): void {
		$cart = new class( $cartContents ) {
			/** @var array<string, array<string, mixed>> */
			private array $contents;

			/**
			 * @param array<string, array<string, mixed>> $contents
			 */
			public function __construct( array $contents ) {
				$this->contents = $contents;
			}

			/**
			 * @return array<string, array<string, mixed>>
			 */
			public function get_cart(): array {
				return $this->contents;
			}
		};

		$wc = new class( $cart ) {
			public object $cart;

			public function __construct( object $cart ) {
				$this->cart = $cart;
			}
		};

		Functions\when( 'WC' )->justReturn( $wc );
	}

	private function invokeStrip( Presentation $presentation, array $data ): array {
		$method = new \ReflectionMethod( $presentation, 'stripChildrenFromResponseData' );
		$method->setAccessible( true );

		return $method->invoke( $presentation, $data );
	}

	public function test_hides_child_line_matched_by_live_cart_item_key(): void {
		$this->stubCart(
			array(
				'parent-key' => array( MetaKeys::LINE_COMPONENT => 0 ),
				'child-key'  => array( MetaKeys::LINE_COMPONENT => 1 ),
			)
		);

		$data = array(
			'items' => array(
				array(
					'key' => 'parent-key',
					'id'  => 12,
				),
				array(
					'key' => 'child-key',
					'id'  => 10,
				),
			),
		);

		$result = $this->invokeStrip( new Presentation(), $data );

		self::assertCount( 1, $result['items'] );
		self::assertSame( 'parent-key', $result['items'][0]['key'] );
	}

	public function test_no_stripping_when_cart_reports_nothing_hidden(): void {
		$this->stubCart( array( 'only-key' => array( MetaKeys::LINE_COMPONENT => 0 ) ) );

		$data = array(
			'items' => array(
				array(
					'key' => 'only-key',
					'id'  => 5,
				),
			),
		);

		$result = $this->invokeStrip( new Presentation(), $data );

		self::assertCount( 1, $result['items'] );
	}

	public function test_recurses_into_nested_hydration_payload(): void {
		$this->stubCart(
			array(
				'parent-key' => array( MetaKeys::LINE_COMPONENT => 0 ),
				'child-key'  => array( MetaKeys::LINE_COMPONENT => 1 ),
			)
		);

		$data = array(
			'/wc/store/v1/cart' => array(
				'body' => array(
					'items' => array(
						array( 'key' => 'parent-key' ),
						array( 'key' => 'child-key' ),
					),
				),
			),
		);

		$result = $this->invokeStrip( new Presentation(), $data );

		self::assertCount( 1, $result['/wc/store/v1/cart']['body']['items'] );
		self::assertSame( 'parent-key', $result['/wc/store/v1/cart']['body']['items'][0]['key'] );
	}
}
