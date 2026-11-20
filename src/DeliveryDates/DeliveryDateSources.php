<?php
/**
 * Chooses the delivery date source.
 *
 * @package ConnectTheDots
 */

namespace ConnectTheDots\DeliveryDates;

defined( 'ABSPATH' ) || exit;

/**
 * Finds the source that matches the shop or the setting.
 */
class DeliveryDateSources {

	/**
	 * The first available source: Order Delivery Date, Delivery & Pickup Date Time, then status only.
	 * Other plugins can add their own source with the ctdflow_delivery_date_sources filter.
	 */
	public static function detect(): DeliveryDateSource {
		/**
		 * Filters the sources that are tried, in order, to find the delivery date of the orders. The
		 * first source that is available is used. Without one, orders are selected by status only.
		 *
		 * @param DeliveryDateSource[] $sources The sources.
		 */
		$sources = apply_filters( 'ctdflow_delivery_date_sources', array( new OrderDeliveryDateSource(), new WooDeliverySource() ) );

		foreach ( (array) $sources as $source ) {
			if ( $source instanceof DeliveryDateSource && $source->isAvailable() ) {
				return $source;
			}
		}

		return new StatusOnlySource();
	}

	/**
	 * The source for a date_source setting value. Auto and unknown ids detect.
	 *
	 * @param string $id Setting id: auto, order-delivery-date, woo-delivery or status.
	 */
	public static function forSetting( string $id ): DeliveryDateSource {
		switch ( $id ) {
			case 'order-delivery-date':
				return new OrderDeliveryDateSource();
			case 'woo-delivery':
				return new WooDeliverySource();
			case 'status':
				return new StatusOnlySource();
			default:
				return self::detect();
		}
	}
}
