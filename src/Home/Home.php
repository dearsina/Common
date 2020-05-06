<?php


namespace App\Common\Home;


use App\Common\Common;
use App\Common\str;
use App\UI\Card;
use App\UI\Grid;
use App\UI\Icon;
use App\UI\Page;

class Home extends Common {

	/**
	 * This is the junction method that is called
	 * on both "/home" and "/". It will look for
	 * custom rule home pages in App\Home, if none are found
	 * will display the generic home.
	 *
	 * @param $a
	 *
	 * @return bool
	 */
	public function view($a) : bool
	{
		# User needs to be logged in
		if(!$this->user->isLoggedIn()){
			return $this->accessDenied();
		}

		global $role;

		# Ideally there is an app method for this role
		if($classPath = str::findClass($role, "Home")){
			//if a App class for this role exists, use it

			# Create a new instance of the class
			$classInstance = new $classPath($this);

			# Set the method (view is the default)
			$method = str::getMethodCase($action) ?: "view";

			# Ensure the method is available
			if(!str::methodAvailable($classInstance, $method)){
				throw new \Exception("The <code>".str::generate_uri($a)."</code> method doesn't exist or is not public.");
				return false;
			}

			# Use the app method
			return $classInstance->$method([
				"action" => $method,
				"rel_table" => $rel_table,
				"rel_id" => $rel_id,
				"vars" => $vars
			]);
		}

		# Otherwise, use the generic view
		return $this->genericView($a);
	}

	private function genericView($a) : bool
	{
		global $role;

		$page = new Page([
			"title" => "Generic {$role} home",
			"subtitle" => "Create a <code>".str::getClassCase("\\App\\Home\\{$role}")."</code> class to avoid this screen.",
			"icon" => Icon::get($role)
		]);

		# Make sure the app has at least one admin
		if(!$this->sql->select(["table" => "admin"])){
			//if the app has no admins
			$page->setGrid([
				"html" => $this->user->card()->newAdmin()
			]);
		}

		$rows["User IP address"] =  $_SERVER['REMOTE_ADDR'];
		$rows["User Agent"] =  $_SERVER['HTTP_USER_AGENT'];
		$rows["Cookies"] =  str::pre(print_r($_COOKIE, true));

		$card = new Card([
			"header" => "This is the generic home for ".str::A($role),
			"rows" => [
				"sm" => 3,
				"rows" => $rows,
			],
			"body" => $body
		]);

		$page->setGrid($card->getHTML());

		$this->output->html($page->getHTML());

		return true;
	}
}