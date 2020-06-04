<?php


namespace App\Common\Admin;


use App\Common\str;

/**
 * Class Info
 * @package App\Common\Admin
 */
class Info extends \App\Common\Common implements \App\Common\SQL\Info\InfoInterface {

	/**
	 * @inheritDoc
	 */
	public function prepare (array &$a): void
	{
		$a['join'][] = [
			"columns" => false,
			"table" => "user_role",
			"on" => [
				"rel_id" => "`admin`.`admin_id`"
			],
			"where" => [
				"rel_table" => "admin"
			]
		];
		$a['join'][] = [
			"table" => "user",
			"on" => [
				"user_id" => "`user_role`.`user_id`"
			],
			"where" => [
				"user_id" => "`user_role`.`user_id`"
			]
		];
	}

	/**
	 * @inheritDoc
	 */
	public static function format (array &$row): void
	{
		# There is only ever one user
		$row['user'] = $row['user'][0];

		# Add "name" and "full_name", and format first and last names
		str::addNames($row['user']);
	}
}