<?php


namespace App\Common\User;

use App\Common\SQL\Info\Common;
use App\Common\SQL\Info\InfoInterface;
use App\Common\str;

class Info extends Common implements InfoInterface {
	public function prepare(&$a) : void
	{
		$a['left_join'][] = "user_role";
	}
	public static function format(array &$row) : void
	{
		# Add "name" and "full_name", and format first and last names
		str::addNames($row);

		# Clean up email
		$row['email'] = strtolower($row['email']);
		//email addresses must be parsed as lowercase
		//because they're used in string comparisons
	}
}