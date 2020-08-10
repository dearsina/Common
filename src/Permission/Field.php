<?php


namespace App\Common\Permission;


use App\Common\SQL\Factory;
use App\Common\str;

/**
 * Class Field
 * @package App\Common\Permission
 */
class Field {
	/**
	 * @param array|null $permissions
	 * @param array|null $override_permissions
	 *
	 * @return mixed
	 */
	static function Permission(?array $permissions, ?array $override_permissions = []){
		$sql = Factory::getInstance();

		# Get all tables (from _all_ databases)
//		$results = $sql->run("SHOW TABLE STATUS FROM `{$_ENV['db_database']}`;");
		$results = $sql->run("SELECT `table_schema` AS 'db', `table_name` AS 'Name' FROM `information_schema`.`tables` WHERE table_type = 'BASE TABLE' AND table_schema NOT IN ('information_schema','mysql','performance_schema','sys') ORDER BY `db`, `Name`;");

		# Create the form field grid
		foreach($results['rows'] as $table){
			$row = [];
			$row["Table"] = str::title($table['Name']);
			if($table['db'] != $_ENV['db_database']){
				$row["Table"] .= " <span class=\"text-silent small\">".strtoupper($table['db'])."</span>";
			}
			$crud = [
				"c" => "Create, this includes upload",
				"r" => "Read, this includes download",
				"u" => "Update, this includes reorder",
				"d" => "Delete, this includes restore"
			];
			foreach($crud as $l => $alt){
				if($override_permissions[$table['Name']][$l]){
					$permissions[$table['Name']][$l] = true;
					$disabled = true;
				} else {
					$disabled = false;
				}
				/**
				 * If custom (rel_id) permissions exist,
				 * this invalidates any RUD permissions.
				 *
				 * The reason for that is that RUD permissions
				 * would override the custom (rel_id) permissions,
				 * thus making them superfluous. So the RUD
				 * permissions are ignored instead.
				 */
				if($permissions[$table['Name']]['count_rel_id']){
					if(in_array($l,["r","u","d"])){
						$disabled = true;
					} else {
						$disabled = false;
					}
				}
				$row[strtoupper($l)] = [
					"sm" => 1,
					"parent_style" => [
						"margin-bottom" => "-1rem"
					],
					"type" => "checkbox",
					"name" => "table[{$table['Name']}][{$l}]",
					"label" => [
						"title" => strtoupper($l),
						"class" => "text-muted",
						"alt" => $alt
					],
					"value" => 1,
					"checked" => $permissions[$table['Name']][$l],
					"disabled" => $disabled
				];
			}
			$row["IDs"] = $permissions[$table['Name']]['count_rel_id'] ?: "None";
			$fields[] = [
				"row_style" => [
					"border-bottom" => ".9px solid #ddd",
					"margin-bottom" => "1rem"
				],
				"html" => array_values($row)
			];
		}

		return $fields;
	}
}