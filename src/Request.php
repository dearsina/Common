<?php


namespace App\Common;

use App\Common\SQL\mySQL;

class Request {

	/**
	 * Where title, body, footer for the modal are stored.
	 * @var array
	 */
	protected $modal;

	/**
	 * Classes.
	 *
	 * @var log
	 */
	protected $log;

	/**
	 * @var hash
	 */
	protected $hash;

	/**
	 * @var output
	 */
	protected $output;

	/**
	 * @var sql
	 */
	protected $sql;

	function __construct () {
		$this->load_session_vars();
		$this->log = Log::getInstance();
		$this->hash = Hash::getInstance();
		$this->output = Output::getInstance();
		$this->sql = mySQL::getInstance();
	}

	/**
	 * Every time a new request is called, make sure
	 * the $_SESSION variables are translated to local variables.
	 *
	 * $user_id
	 * $role
	 *
	 * The $_SESSION variable is never sent to the client.
	 * It is stored exclusively on the server.
	 *
	 * @return bool
	 */
	public function load_session_vars(){
		if(!is_array($_SESSION)){
			return true;
		}

		foreach($_SESSION as $key => $val){
			global $$key;
			$$key = $val;
		}

		return true;
	}

	/**
	 * Handle error being sent as part of the URI,
	 * for example from  from oAuth.php.
	 *
	 * @param $a
	 *
	 * @return bool
	 */
	private function handle_errors($a){
		extract($a);

		if(!is_array($vars)){
			return true;
		}

		if(!$vars['error']){
			return true;
		}


		$this->log->error([
			"title" => str::title(urldecode($vars['error'])),
			"message" => urldecode($vars['error_description'])
		]);

		unset($a['vars']['error']);
		unset($a['vars']['error_description']);

		$this->hash->set($a);

		return false;
	}


	/**
	 * AJAX gatekeeper
	 * The method called every time an AJAX call is received from the browser.
	 * Looks for a suitable (public or protected, NOT PRIVATE) method and runs it.
	 * The order of priority is as follows:
	 * 1. action_rel_table
	 * 2. action
	 * 3. rel_table
	 *
	 * If no method is found, an error is generated.
	 *
	 * @param $a array
	 *
	 * @return bool
	 */
	public function handler($a){
		$success = $this->input($a);
		return $this->output($success);
	}

	/**
	 * Handles an incoming AJAX request.
	 *
	 * @param $a
	 *
	 * @return bool
	 */
	private function input($a){
		# Extract the vars
		extract($a);

		# Handle any errors that may be embedded into the URI
		if(!$this->handle_errors($a)){
			return false;
		}

		# Create and set the callback based on the hash
		$this->hash->setCallback($a);

		# If directions for the output have been sent with the vars
		if(is_array($vars) && $vars['div'] && $vars['div_id']){
			$this->output->set_direction($vars);
		}

		# Is the returning data meant to be set in a modal?
		if(is_array($vars) && $vars['modal']){
			$this->modal = $vars['modal'];
			if($this->modal != 'close'){
				$this->output->is_modal();
				$this->hash->silent();
			}
			//by default, modals don't affect the URL
		}

		if(is_array($vars) && $vars['_uri']){
			//If the ajax call is due to a hash change
			$this->output->uri();
		}

		# If a blank request is sent, assume it's a call to go home
		if(!$rel_table
		&& !$rel_id
		&& !$action
		&& !$vars){
			$rel_table = "home";
		}

		if(!$classPath = self::findClass($rel_table)){
			//if a class doesn't exist
			$this->log->error("No matching class for <code>".str::generate_uri($a)."</code> can be found. {$commonPath}");
			return false;
		}

		# Create a new instance of the class
		$classInstance = new $classPath($this);

		# Set the method (view is the default)
		$method = str::getMethodCase($action) ?: "view";

		# Ensure the method is available
		if(!self::methodAvailable($classInstance, $method)){
			$this->log->error("The <code>".str::generate_uri($a)."</code> method doesn't exist or is not public.");
			return false;
		}

		# Call the class action
		if(!$classInstance->$method([
			"action" => $method,
			"rel_table" => $rel_table,
			"rel_id" => $rel_id,
			"vars" => $vars
		])){
			return false;
		}

		return true;
	}

	/**
	 * Given a rel_table, find a class
	 *
	 * @param $rel_table
	 *
	 * @return bool|string Returns the class with path or FALSE if it can't find it
	 */
	public static function findClass($rel_table){
		# Does a custom path exist?
		$corePath = str::getClassCase("\\App\\{$rel_table}\\{$rel_table}");
		if(class_exists($corePath)) {
			return $corePath;
		}

		# Does a common path exist
		$commonPath = str::getClassCase("\\App\\Common\\{$rel_table}\\{$rel_table}");
		if(class_exists($commonPath)) {
			return $commonPath;
		}

		# If no class can be found
		return false;
	}

	/**
	 * Returns the output as a json-encoded array.
	 * @return string
	 */
	public function output($success) {
		if($_SESSION['database_calls']){
//			$this->log->info("{$_SESSION['database_calls']} database calls.");
//			print_r($_SESSION['queries']);exit;
		}

		$output = $this->output->get();

		# Make sure close requests are captured
		if($this->modal == 'close'){
			//if a modal is to be closed
			if(!$output['modal']){
				$output['modal'] = $this->modal;
			}
		}

		if($this->modal && !$output['modal']){
			//if a modal operation has been requested
			//and a modal request isn't already going
			$output['modal'] = $output['html'];
			unset($output['html']);
		}

		# Silent is the same as hash = -1, but without refreshing the screen
//		if(!$output['silent'] = $this->hash->getSilent()){
		//only if there is no need to be silent will a hash be sent
		$output['hash'] = $this->hash->get();
//		}
		$output['silent'] = $this->hash->getSilent();
		# TEMP I think we can send a hash always
		# No, otherwise the silent flag means nothing

		# Alerts
		$output['alerts'] = $this->log->getAlerts();
		/**
		 * Alerts are fed into the $output array,
		 * but have otherwise no relation to the
		 * output class.
		 */

		if($success === true){
			//not sure if this will have unintended consequences
			$output['success'] = true;
		} else if($this->log->hasFailures()){
			//if there are any errors
			$this->log->logFailures();
			//log them for posterity
			$output['success'] = false;
		} else if($success === false) {
			$output['success'] = false;
		} else {
			//otherwise, everything is fantastic
			$output['success'] = true;
		}

		$this->sql->disconnect();

		return json_encode($output);
	}

	/**
	 * Checks to see if a given method is available in the current scope.
	 * And if that method is PUBLIC. Protected and private methods
	 * are protected from outside execution via the load_ajax_call() call.
	 *
	 * If the modifier is set, it will accept any methods set at that modifier or lower:
	 * <code>
	 * private
	 * protected
	 * public
	 * </code>
	 *
	 * @param        $method
	 *
	 * @param string $modifier The minimum accepted modifier level. (Default: public)
	 * @return bool
	 * @link https://stackoverflow.com/questions/4160901/how-to-check-if-a-function-is-public-or-protected-in-php
	 */
	public static function methodAvailable($class, $method, $modifier = "public"){
		if(!$class || !$method){
			return false;
		}

		if(!method_exists($class, $method)){
			return false;
		}

		try {
			$reflection = new \ReflectionMethod($class, $method);
		}

		catch (\ReflectionException $e){
			return false;
		}

		switch($modifier){
		case 'private':
			if (!$reflection->isPrivate() && !$reflection->isProtected() && !$reflection->isPublic()) {
				return false;
			}
			break;
		case 'protected':
			if (!$reflection->isProtected() && !$reflection->isPublic()) {
				return false;
			}
			break;
		case 'public':
		default:
			if (!$reflection->isPublic()) {
				return false;
			}
			break;
		}

		return true;
	}
}