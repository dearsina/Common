<?php

namespace App\Common;

use App\Common\SQL\Factory;

class Country {
	/**
	 * Get an array of currency code options
	 * to use in a dropdown select.
	 *
	 * @return array
	 */
	public static function getCurrencyCodeOptions(): array
	{
		$sql = Factory::getInstance();
		foreach($sql->select([
			"columns" => [
				"currency_code",
				"countries" => ["group_concat", [
					"distinct" => true,
					"columns" => "name",
					"order_by" => [
						"country" => "ASC"
					],
					"separator" => ", "
				]
				]
			],
			"table" => "country",
			"where" => [
				["currency_code", "<>", ""]
			],
			"order_by" => [
				"currency_code" => "ASC"
			]
		]) as $id => $country){
			$currency_code_options[$country['currency_code']] = "{$country['currency_code']}, used by {$country['countries']}";
		}

		return $currency_code_options;
	}

	/**
	 * Given a user ID, get that user's local currency code.
	 * Based on the geolocation data collected from each user.
	 *
	 * @param string|null $user_id
	 *
	 * @return string|null Three letter currency code
	 */
	public static function getLocalUserCurrency(?string $user_id): ?string
	{
		if(!$user_id){
			return NULL;
		}

		$sql = Factory::getInstance();

		if(!$currency = $sql->select([
			"columns" => "ip",
			"table" => "connection",
			"join" => [[
				"columns" => false,
				"table" => "geolocation",
				"on" => "ip"
			],[
				"columns" => "currency_code",
				"table" => "country",
				"on" => [
					"country_code" => ["geolocation", "country_code"]
				]
			]],
			"where" => [
				["closed", "IS", NULL],
				"user_id" => $user_id
			],
			"order_by" => [
				"created" => "DESC"
			],
			"limit" => 1
		])){
			return NULL;
		}

		return $currency['geolocation'][0]['country'][0]['currency_code'];
	}
}