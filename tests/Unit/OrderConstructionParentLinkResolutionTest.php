<?php

declare(strict_types=1);

namespace UniversalCommerceBundles\Tests\Unit;

use PHPUnit\Framework\TestCase;
use UniversalCommerceBundles\Application\CompositionRepository;
use UniversalCommerceBundles\Domain\MetaKeys;
use UniversalCommerceBundles\Woo\OrderConstruction;
use WC_Order;
use WC_Order_Item_Product;

/**
 * Live HTTP validation (docs/m1-closure.md, acceptance case 1) found that
 * OrderConstruction::resolveParentLinks() was only ever wired to
 * `woocommerce_checkout_order_created` — a classic-checkout-only hook. A
 * real Store API checkout builds the order through a different path and
 * fires `woocommerce_store_api_checkout_order_created` instead, so pass 2
 * never ran and every Store API order's child lines kept the stale
 * cart-item-key as their parent link forever. This test exercises the
 * resolution method itself (both real hooks now call the same method —
 * see OrderConstruction::register()), independent of which hook fired,
 * against the minimal WC_Order/WC_Order_Item_Product stand-ins in
 * tests/Fixtures/ (same require-on-demand pattern CompatibilityTest and
 * PluginRuntimeReadyTest already use for tests/Fixtures/WooCommerceStub.php).
 */
final class OrderConstructionParentLinkResolutionTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		if ( ! class_exists( 'WC_Order_Item_Product', false ) ) {
			require dirname( __DIR__ ) . '/Fixtures/WCOrderItemProductStub.php';
		}

		if ( ! class_exists( 'WC_Order', false ) ) {
			require dirname( __DIR__ ) . '/Fixtures/WCOrderStub.php';
		}
	}

	public function test_resolves_child_parent_link_from_cart_key_to_real_order_item_id(): void {
		$orderConstruction = new OrderConstruction( new CompositionRepository() );

		$parentItem = new WC_Order_Item_Product(
			array(
				'_ucb_temp_cart_key' => 'cart-key-abc',
			)
		);
		$childItem  = new WC_Order_Item_Product(
			array(
				MetaKeys::LINE_COMPONENT        => '1',
				MetaKeys::LINE_PARENT_ITEM_ID   => 'cart-key-abc',
				MetaKeys::LINE_SNAPSHOT_VERSION => '1',
			)
		);

		$order = new WC_Order(
			array(
				10 => $parentItem,
				11 => $childItem,
			)
		);

		$orderConstruction->resolveParentLinks( $order );

		self::assertSame( '10', $childItem->get_meta( MetaKeys::LINE_PARENT_ITEM_ID, true ) );
		self::assertSame( '', $parentItem->get_meta( '_ucb_temp_cart_key', true ), 'the temp cart-key marker must be cleaned up after resolution' );
	}

	public function test_leaves_non_kit_orders_untouched(): void {
		$orderConstruction = new OrderConstruction( new CompositionRepository() );

		$plainItem = new WC_Order_Item_Product( array() );
		$order     = new WC_Order( array( 1 => $plainItem ) );

		// Must not throw or alter anything when there is nothing to resolve.
		$orderConstruction->resolveParentLinks( $order );

		self::assertSame( '', $plainItem->get_meta( MetaKeys::LINE_PARENT_ITEM_ID, true ) );
	}
}
