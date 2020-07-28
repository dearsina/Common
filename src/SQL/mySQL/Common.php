<?php


namespace App\Common\SQL\mySQL;

use App\Common\str;
use mysqli_sql_exception;
use Exception;

/**
 * Class Common
 * Common is an abstract base class for all the different SQL query types.
 * Any method that is shared between more than 1 query type is found here.
 * @package App\Common\SQL\mySQL
 */
abstract class Common {
	/**
	 * The character(s) that separate the database name from the table name in an alias.
	 */
	const DB_TABLE_SEPARATOR = ":";

	/**
	 * The character(s) that separate the table name from the column name in an alias
	 */
	const TABLE_COL_SEPARATOR = ".";

	/**
	 * The database object given by the root class.
	 * @var \mysqli
	 */
	protected \mysqli $mysqli;

	/**
	 * Is SELECT query to be distinct?
	 * @var bool
	 */
	protected bool $distinct = false;

	/**
	 * Used exclusively by GROUP_CONCAT().
	 * @var string|null
	 */
	protected ?string $separator;

	/**
	 * An array where the keys are the table name (prefixed with database if not the same database as the main table)
	 * and values are the number of times that combo is being used by this query.
	 * @var array
	 */
	protected array $tables_in_use = [];

	/**
	 * The main table.
	 * An array containing the following child keys:
	 *  - name, the name of the table
	 *  - alias, the table alias
	 *  - db, the database of the table
	 *  - id, the table ID column name
	 * @var array
	 */
	protected array $table = [];

	/**
	 * Columns belonging to the main table.
	 * @var array|null
	 */
	protected ?array $columns = [];

	/**
	 * Contains the names of the columns where HTML content is accepted.
	 * @var array|null
	 */
	protected ?array $html = [];

	/**
	 * Array of columns that will be populated,
	 * either by INSERT or UPDATE queries.
	 * @var array|null
	 */
	protected ?array $set = [];

	/**
	 * The potential joins. The first key will be the type of join, either INNER or LEFT/RIGHT
	 * @var array|null
	 */
	protected ?array $join = [];

	/**
	 * Contains the order bys of the main table.
	 * @var array|null
	 */
	protected ?array $order_by = [];

	/**
	 * The LIMIT string.
	 * @var string|null
	 */
	protected ?string $limit = NULL;

	/**
	 * An array of table column name definitions, so that multiple calls to the same table definition doesn't result
	 * in multiple database calls. Is structured like so:
	 * `database` -> `table` -> `column` -> `Column metadata`
	 * @var array
	 */
	protected array $table_column_names_all = [];

	/**
	 * Same as the $tableColumnNamesAll array, except only with columns the user can edit.
	 * @var array
	 */
	protected array $table_column_names = [];

	/**
	 * An array containing table, db, etc, with keys as names,
	 * and whether they exist or not as boolean values.
	 * @var array
	 */
	protected array $exists = [];

	/**
	 * An array containing the main table where conditions.
	 * @var array|null
	 */
	protected ?array $where = [];
	protected ?array $tableAliasWithWhere = [];

	public function __construct(\mysqli $mysqli)
	{
		$this->mysqli = $mysqli;
	}

	protected function resetVariables(): void
	{
		foreach(array_keys(get_object_vars($this)) as $var){
			unset($this->{$var});
		}
	}

	/**
	 * Given a database name (string) and a table array or string,
	 * define the main table parameters and store them in the $this->table array.
	 *
	 * @param string|null  $db
	 * @param string|array $table
	 * @param string|null  $id
	 * @param bool|null    $include_removed
	 * @param bool|null    $count
	 */
	protected function setTable(?string $db, $table, ?string $id = NULL, ?bool $include_removed = NULL, ?bool $count = NULL): void
	{
		$this->table = $this->getTable($db, $table, $id, $include_removed, $count);
	}

	/**
	 * Given a database name (string) and a table array or string,
	 * returns a table array. Doesn't check if any of the values are legit.
	 * A table array contains the following child keys:
	 *  - name, the name of the table
	 *  - alias, the table alias
	 *  - db, the database of the table
	 *  - id, the table ID column name
	 *  - include_removed, whether or not we should include removed columns (default is to exclude them)
	 * All of the values can be given.
	 *
	 * @param string|null $db
	 * @param             $table
	 * @param bool|null   $include_removed
	 *
	 * @return array
	 */
	protected function getTable(?string $db, $table, ?string $id, ?bool $include_removed, ?bool $count): array
	{
		# Get the database
		$db = $db ?: $_ENV['db_database'];
		//If no database name is given, use the default one

		# Get the table name
		if(is_string($table)){
			//if the table name is a string
			$name = $table;
		} else if(str::isAssociativeArray($table)){
			//if the table is an associative array
			extract($table);
			//This can override the db name
		} else {
			//if table was not supplied or is in an unrecognisable format
			throw new mysqli_sql_exception("No table name was given.");
		}

		$array["db"] = str::i($db);
		//The database name can also be in the table array, will override the explicitly given db name

		$array["name"] = str::i($name);
		// The table name is the table name, cleaned up

		$array["alias"] = $alias ?: $this->getTableAlias($array["db"], $array["name"]);
		// If an alias is explicitly set, use it

		$array["id_col"] = $id_col ? str::i($id_col) : "{$array["name"]}_id";
		//The table ID columns (optional, default is just the table name suffixed with _id)

		$array["id"] = $id;
		//If only this one ID value is to be extracted

		$array["include_removed"] = $include_removed;
		//A boolean flag that determines whether we should ignore removed ("removed IS NULL") or include them

		$array["count"] = $count;
		//A boolean flag that determines whether we should ignore all columns and just a straight COUNT(*)

		return $array;
	}

	/**
	 * Get the table SQL string.
	 * Optionally, if the table is a sub query, return the string
	 * with the subquery.
	 * Used by SELECT and DELETE
	 *
	 * @param string|null $sub_query
	 *
	 * @return string
	 */
	protected function getTableSQL(?string $sub_query = NULL): string
	{
		if($sub_query){
			//if the main table is a sub_query
			$sub_query = str_replace("\r\n", "\r\n\t", $sub_query);
			return "FROM (\r\n\t{$sub_query}\r\n) AS `{$this->table['alias']}`";
		}
		return "FROM `{$this->table['db']}`.`{$this->table['name']}` AS `{$this->table['alias']}`";
	}

	/**
	 * Takes a valid mySQL database and table name and returns an alias.
	 * The alias is the table name + a suffix if it's the 2-n'th
	 * instance of the table.
	 * table_name,
	 * table_name2,
	 * table_name3,
	 * etc.
	 *
	 * @param $table
	 *
	 * @return string A alphanumeric string, not enclosed in SQL quotation marks
	 */
	protected function getTableAlias(string $db, string $table)
	{
		# Clean up table name for alias purposes
		# The assumption here is that the table name contains at least one alphanumeric character.
		$table = preg_replace("/[^A-Za-z0-9_]/", '', $table);

		if($db != $_ENV['db_database']){
			//if the table is NOT the same as the main database

			$table = $this->getDbAndTableString($db, $table);
			//prefix the table name with the database name and separate them with the db-table separator
		}

		# If the table has been used before, increase the count
		$this->tables_in_use[$table] += 1;

		if($this->tables_in_use[$table] == 1){
			//If this is the first table, omit the number suffix
			return $table;
		}

		# If this is table 2, 3, n, add the number as a suffix
		return $table . $this->tables_in_use[$table];
	}

	/**
	 * Given a database and a table name, fuse them together and add the separator.
	 *
	 * @param string $db
	 * @param string $table
	 *
	 * @return string
	 */
	protected function getDbAndTableString(?string $db, string $table): string
	{
		# If no DB is given, don't use it in the string
		if(!$db){
			return $table;
		}
		return $db . self::DB_TABLE_SEPARATOR . $table;
	}

	/**
	 * Sets all joins of a given type (INNER, LEFT, etc)
	 *
	 * @param string $type
	 * @param        $j
	 */
	protected function setJoins(string $type, $j): void
	{
		if(!$j){
			return;
		}

		if(str::isAssociativeArray($j)){
			$joins[] = $j;
		} else if(str::isNumericArray($j)){
			$joins = $j;
		} else if(is_string($j)){
			$joins[] = ["table" => $j];
		}

		foreach($joins as $join){
			$this->setJoin($type, $join);
		}
	}

	/**
	 * Sets a single join.
	 *
	 * @param string $type The type of join
	 * @param mixed  $a    The join data
	 */
	protected function setJoin(string $type, $a): void
	{
		if(str::isAssociativeArray($a)){
			//most commonly
			extract($a);
		} else if(str::isNumericArray($a)){
			//shouldn't happen, but just in case
			foreach($a as $join){
				$this->setJoin($type, $join);
			}
			return;
		} else if(is_string($a)){
			//If the whole join is just the name of a table
			$table = $a;
		} else {
			//Otherwise, not sure what to do with this join
			throw new mysqli_sql_exception("An incomprehensible join was passed: " . print_r($a, true));
		}

		# Generate the table array (needs to be done first, as the array is used by everyone else)
		$table = $this->getTable($db, $table, $id, $include_removed, $count);

		# Verify all the details are correct
		$this->verifyTableArray($table);

		# Set the parent table prefix to the alias, if required
		$this->setParentTableAliasPrefix($table, $on, $on_or);

		# Get join conditions (needs to come before the columns)
		$on_conditions = $this->getOnConditions($table, $on, $on_or);

		# Get columns
		$columns = $this->getColumns($table, $columns);

		# Get join where conditions
		$where_conditions = $this->getWhereConditions($table, $where, $or);

		# Get order by conditions
		$order_by_conditions = $this->getOrderBy($table, $columns, $order_by);

		$this->join[$type][] = [
			"table" => $table,
			"columns" => $columns,
			"on" => $on_conditions,
			"where" => $where_conditions,
			"order_by" => $order_by_conditions,
		];
	}

	/**
	 * Formats the entire column key value.
	 *
	 * @param $table
	 * @param $c
	 *
	 * @return array|null
	 */
	protected function getColumns($table, $c): ?array
	{
		# A request for a count will override any other column requirements
		if(($columns = $this->generateCountColumn($table)) !== NULL){
			return $columns;
		} # Columns can be ignored all together
		else if($c === false){
			return NULL;
		}

		if(is_string($c)){
			$cols[] = $c;
		} else if(is_array($c)){
			$cols = $c;
		} else {
			$cols = $this->getAllTableColumns($table);
		}

		foreach($cols as $key => $val){
			$columns[] = $this->getColumn($table, $key, $val);
		}

		return $columns;
	}

	/**
	 * If all columns are to be ignored and only a simple count offered
	 *
	 * @param $table
	 *
	 * @return array|null
	 */
	protected function generateCountColumn($table): ?array
	{
		if(!$this->table['count']){
			//if there is no count required
			return NULL;
		}

		if($table['alias'] != $this->table['alias']){
			//if this isn't the main table (the only table that can request a count)
			return [];
		}

		return [[
			"agg" => "COUNT",
			"table_alias" => $this->table['alias'],
			"name" => $this->table["id_col"],
			"alias" => "C",
			"distinct" => true
		]];
	}

	/**
	 * Formats a single column key-val pair.
	 * Returns an array with $column data with the following keys:
	 *  - `table_alias`, needs to be prefixed before the column
	 *  - `name`, column name
	 *  - `alias`, column alias, to be used at top in the SELECT statement
	 *  - `agg`, optional aggregate function that needs wrap around the name
	 *
	 * @param array $table
	 * @param       $col_alias
	 * @param       $col
	 *
	 * @return array|null
	 */
	protected function getColumn(array $table, $col_alias, $col): ?array
	{
		if($this->isGroupConcat($col)){
			return $this->formatGroupConcatCol($table, $col_alias, $col);
		}

		# "c" => ["count", "last_name"] (Aggregate functions)
		if(is_array($col) && (count($col) == 2) && $this->isAggregateFunction($col[0])){
			# Break it open
			[$agg, $col] = $col;

			# Format the aggregate function
			$agg = strtoupper($agg);
		}

		# If the $col value is still an array, ignore it
		if(is_array($col)){
			return NULL;
		}

		# Ensure the column exists
		if(!$this->columnExists($table['db'], $table['name'], $col)){
			return NULL;
		}

		# Get the alias
		$col_alias = $this->generateColumnAlias($table, $col_alias, $col);

		return [
			"table_alias" => $table['alias'],
			"name" => $col,
			"alias" => $col_alias,
			"agg" => $agg,
		];
	}

	protected function formatGroupConcatCol(array $table, ?string $col_alias, array $col): ?array
	{
		[$agg, $a] = $col;
		$sql = new Select($this->mysqli);
		return $sql->groupConcat($table, $col_alias, $a);
	}

	protected function isGroupConcat($col): bool
	{
		return is_array($col) && (count($col) == 2) && (strtoupper($col[0]) == "GROUP_CONCAT");
	}

	/**
	 * Generates a column alias for a column.
	 * If one is given (that is not numeric), it will be used instead.
	 *
	 * @param array       $table
	 * @param string|null $alias
	 * @param string      $col
	 *
	 * @return string
	 */
	protected function generateColumnAlias(array $table, ?string $alias, string $col): ?string
	{
		# If the user has given an alias, use it
		if($alias && !is_numeric($alias)){
			return str::i($alias);
		}

		if($table['alias'] == $this->table['alias']){
			//if this is the main table, no need for an alias
			return NULL;
		}

		return $table['alias'] . self::TABLE_COL_SEPARATOR . $col;
	}

	protected function getAllTableColumns(array $table)
	{
		return array_keys($this->getTableMetadata($table, false, true));
	}

	/**
	 * Boolean check to ensure the function is an aggregate function.
	 *
	 * @param string $function
	 *
	 * @return bool
	 */
	protected function isAggregateFunction(string $function): bool
	{
		return in_array(strtoupper($function), ["COUNT", "MAX", "MIN", "AVG", "SUM", "GROUP_CONCAT"]);
	}

	/**
	 * Sets the where conditions for the main table.
	 * Used by SELECT and UPDATE queries.
	 *
	 * @param array|null $where
	 * @param array|null $or
	 */
	protected function setWhere(?array $where, ?array $or): void
	{
		$this->where = $this->getWhereConditions($this->table, $where, $or);
	}

	/**
	 * Returns an array of where AND and OR conditions.
	 * The $and and $or values have to be in array form.
	 * Used by SELECT and UPDATE queries.
	 *
	 * @param array      $table
	 * @param array|null $and
	 * @param array|null $or
	 *
	 * @return array
	 */
	protected function getWhereConditions(array $table, ?array $and, ?array $or): ?array
	{
		# Unless we're explicitly including removed, remove them
		if(!$table['include_removed']){
			$conditions["and"][] = "`{$table['alias']}`.`removed` IS NULL";
		}
		//This could potentially be limited to the main table only

		# If a particular ID has been asked for
		if($table['id']){
			$conditions["and"][] = "`{$table['alias']}`.`{$table["id_col"]}` = '{$table['id']}'";

			# Collecting all tables that have where clauses
			$this->setTableAliasWithWhere([$table['alias']]);
		}

		if(is_array($and)){
			foreach($and as $key => $val){
				$conditions["and"][] = $this->getValueComparison($table, $key, $val, true);
			}
		}

		if(is_array($or)){
			foreach($or as $key => $val){
				$conditions["or"][] = $this->getValueComparison($table, $key, $val, true);
			}
		}
		return $conditions;
	}

	/**
	 * Given a table array and on and/or or conditions,
	 * creates an array with "and" and "or" keys where each child is a condition.
	 *
	 * @param array      $table
	 * @param            $and
	 * @param array|null $or
	 *
	 * @return array
	 */
	protected function getOnConditions(array $table, $and, ?array $or): ?array
	{
		# Or conditions have to be an array because we're comparing at least two options
		if(is_array($or)){
			foreach($or as $key => $val){
				$conditions["or"][] = $this->getValueComparison($table, $key, $val);
			}
		}

		if(is_array($and)){
			foreach($and as $key => $val){
				$conditions["and"][] = $this->getValueComparison($table, $key, $val);
			}
		} else if(is_string($and)){
			/**
			 * If the $on is a string, it's assumed to be
			 * the name of a column that exists in both
			 * the joined table and the main table.
			 */
			if($this->columnExists($table['db'], $table['name'], $and)
				&& $this->columnExists($this->table['db'], $this->table['name'], $and)){
				$conditions["and"][] = "`{$table['alias']}`.`$and` = `{$this->table['alias']}`.`$and`";
			}
		}

		if(!$and && !$conditions){
			/**
			 * if there is no $on, *AND* there are no OR conditions,
			 * it's assumed that the join is on the main table's ID column,
			 * if it exists in both tables.
			 */
			if($this->columnExists($table['db'], $table['name'], $this->table["id_col"])){
				//if the joined table also has a column the same name as the main table's ID column
				$conditions["and"][] = "`{$table['alias']}`.`{$this->table["id_col"]}` = `{$this->table['alias']}`.`{$this->table["id_col"]}`";
			}
		}

		# Unless we're explicitly including removed, remove them (this has to be placed after the above if() statement to not influence it)
		if(!$table['include_removed']){
			$conditions["and"][] = "`{$table['alias']}`.`removed` IS NULL";
			/**
			 * The removed is null condition has to be on the join,
			 * and NOT on the WHERE, except for on the main table
			 * otherwise counts will be screwed.
			 */
		}

		return $conditions;
	}

	/**
	 * Prefixes a parent table alias to a table alias, if required.
	 *
	 * @param            $table
	 * @param            $and
	 * @param array|null $or
	 */
	protected function setParentTableAliasPrefix(&$table, $and, ?array $or): void
	{
		# OR conditions have to be an array because we're comparing at least two options
		if(is_array($or)){
			$parent_alias = $this->getParentAliasFromOnConditions($or);
		}

		# AND conditions can be array or string
		if(is_array($and)){
			$parent_alias = $this->getParentAliasFromOnConditions($and);
		} else if(is_string($and)){
			$parent_alias = $this->table['alias'];
		}

		# If no conditions have been explicitly mentioned
		if(!$and && !$conditions){
			$parent_alias = $this->table['alias'];
		}

		# If no parent alias can be found (main table)
		if(!$parent_alias){
			return;
		}

		# Ensure the alias exists
		if(!$this->tableAliasExists($parent_alias)){
			throw new mysqli_sql_exception("The <code>{$parent_alias}</code> table alias cannot be found. This is either because you're referencing a table that doesn't exist or doesn't exist yet. Order matters in the join array.");
		}

		# For reference, add the parent alias
		$table['parent_alias'] = $parent_alias;

		# If a parent table exists, prefix the alias with it, unless the parent is the main table
		if($parent_alias && ($parent_alias != $this->table['alias'])){
			$table['alias'] = "{$parent_alias}.{$table['alias']}";
		}
	}

	/**
	 * Given a set of conditions, return the FIRST reference to a parent table.
	 * By parent table is meant the table this table joins on.
	 *
	 * @param array $conditions
	 *
	 * @return string|null Returns the ALIAS, not the name. Or NULL if the table doesn't have one
	 */
	protected function getParentAliasFromOnConditions(array $conditions): ?string
	{
		foreach($conditions as $col => $val){
			# "col" => ["tbl_alias", "tbl_col"]
			if(is_string($col) && is_array($val) && (count($val) == 2)){
				[$tbl_alias, $tbl_col] = $val;
				return $tbl_alias;
			}

			# ["col", "eq", "tbl_alias", "tbl_col"]
			if(is_numeric($col) && is_array($val) && (count($val) == 4)){
				[$col, $eq, $tbl_alias, $tbl_col] = $val;
				return $tbl_alias;
			}

			# "col" => ["db", "name", "col"]
			if(is_string($col) && is_array($val) && (count($val) == 3)){
				[$tbl_db, $tbl_name, $tbl_col] = $val;
				return $this->getDbAndTableString($tbl_db, $tbl_name);
			}
		}

		return NULL;
	}

	/**
	 * Collecting all tables and parent tables with children that have where clauses
	 *
	 * @param array|null $tableAliasWithWhere
	 */
	protected function setTableAliasWithWhere(?array $tableAliasWithWhere): void
	{
		if(!$tableAliasWithWhere){
			return;
		}
		foreach($tableAliasWithWhere as $table){
			$this->tableAliasWithWhere[$table] = $table;
		}
	}

	protected function getTableAliasWithWhere(): ?array
	{
		if(!$this->tableAliasWithWhere){
			return NULL;
		}
		$tables = [];
		foreach($this->tableAliasWithWhere as $alias){
			$tables[$alias] = $alias;
			foreach(explode(".", $alias) as $section){
				$tables[$section] = $section;
			}
		}
		return $tables;
	}

	/**
	 * Formats a range of value comparisons and returns them as strings.
	 * The following formats are all accepted:
	 * <code>
	 * "col = 'val'",
	 * ["col", "=", "val"],
	 * ["col", "IN", [1, 2,3]]
	 * ["col", ">", "alias", "col"],
	 * ["col", "BETWEEN", 1, 3],
	 * "col" => "val",
	 * "col" => ["alias", "col"]
	 * </code>
	 *
	 * @param array     $table
	 * @param mixed     $col
	 * @param mixed     $val
	 * @param bool|null $where If set, signals that this method was called for a WHERE clause, not
	 *
	 * @return string|null
	 */
	protected function getValueComparison(array $table, $col, $val, ?bool $where = NULL): ?string
	{
		# "db.table.col = 'complete comparison'"
		if(is_numeric($col) && is_string($val)){
			/**
			 * If $col is numeric, meaning it doesn't contain
			 * a string (column name), and $val is a string,
			 * assume $val is a complete comparison
			 */

			if($where){
				# Collecting all tables and parent tables with children that have where clauses
				$this->setTableAliasWithWhere([$table['alias']]);
			}

			return $val;
		} # "col" => ["tbl_alias", "tbl_col"]
		else if(is_string($col) && is_array($val) && (count($val) == 2)){
			[$tbl_alias, $tbl_col] = $val;

			# Both values have to exist
			if(!$tbl_alias || !$tbl_col){
				NULL;
			}

			# Ensure the join table column exists
			if(!$this->columnExists($table['db'], $table['name'], $col)){
				return NULL;
			}

			# Ensure table alias exists
			if(!$this->tableAliasExists($tbl_alias)){
				return NULL;
			}

			if($where){
				# Collecting all tables and parent tables with children that have where clauses
				$this->setTableAliasWithWhere([$table['alias'], $tbl_alias]);
			}

			return "`{$table['alias']}`.`{$col}` = `{$tbl_alias}`.`{$tbl_col}`";
		} # "col" => ["db", "name", "col"]
		else if(is_string($col) && is_array($val) && (count($val) == 3)){
			[$tbl_db, $tbl_name, $tbl_col] = $val;

			# Ensure the join table column exists
			if(!$this->columnExists($table['db'], $table['name'], $col)){
				return NULL;
			}

			# Ensure the counterpart also exists
			if(!$this->columnExists($tbl_db, $tbl_name, $tbl_col)){
				return NULL;
			}

			if($where){
				# Collecting all tables and parent tables with children that have where clauses
				$this->setTableAliasWithWhere([$table['alias'], $this->getDbAndTableString($tbl_db, $tbl_name)]);
			}

			return "`{$table['alias']}`.`{$col}` = `{$this->getDbAndTableString($tbl_db,$tbl_name)}`.`{$tbl_col}`";
			/**
			 * In cases where the same table from the same database
			 * is referenced, and tbl_db + tbl_name are given,
			 * the assumption is that it's the first table that is
			 * referenced here.
			 */
		} # ["col", "=", "val"] or ["col", "IN", [1, 2,3]]
		else if(is_numeric($col) && is_array($val) && (count($val) == 3)){
			[$col, $eq, $val] = $val;

			# Ensure the join table column exists
			if(!$this->columnExists($table['db'], $table['name'], $col)){
				return NULL;
			}

			# Ensure comparison operator is valid
			if(!$this->isValidComparisonOperator($eq)){
				return NULL;
			}

			# Ensure correct comparison operator for NULL vals
			$eq = $this->correctComparisonOperatorForNullVal($val, $eq);

			# Ensure the formatted value is valid
			if(($val = $this->formatComparisonVal($val)) === NULL){
				//A legit value can be "0"
				return NULL;
			}

			if($where){
				# Collecting all tables and parent tables with children that have where clauses
				$this->setTableAliasWithWhere([$table['alias']]);
			}

			# Negate missing NULLs in negative comparisons
			if($this->isNegativeComparisonOperator($eq)){
				return "(`{$table['alias']}`.`{$col}` {$eq} {$val} OR `{$table['alias']}`.`{$col}` IS NULL)";
				/**
				 * In cases where a condition is that a value is NOT (!=, <>, NOT IN) something,
				 * those values that are NULL will also be omitted. This prevents that from happening.
				 */
			}

			return "`{$table['alias']}`.`{$col}` {$eq} {$val}";
		} # ["col", "BETWEEN", "1", "5"],
		else if(is_numeric($col) && is_array($val) && (count($val) == 4) && (strtoupper($val[1]) == "BETWEEN")){
			[$col, $eq, $from_val, $to_val] = $val;

			# Ensure the join table column exists
			if(!$this->columnExists($table['db'], $table['name'], $col)){
				return NULL;
			}

			# Ensure the formatted FROM value is valid
			if(($from_val = $this->formatComparisonVal($from_val)) === NULL){
				return NULL;
			}

			# Ensure the formatted TO value is valid
			if(($to_val = $this->formatComparisonVal($to_val)) === NULL){
				return NULL;
			}

			if($where){
				# Collecting all tables and parent tables with children that have where clauses
				$this->setTableAliasWithWhere([$table['alias']]);
			}

			return "`{$table['alias']}`.`{$col}` BETWEEN {$from_val} AND {$to_val}";
		} # ["col", "=", "table_alias", "col"],
		else if(is_numeric($col) && is_array($val) && (count($val) == 4)){
			[$col, $eq, $tbl_alias, $tbl_col] = $val;

			# Ensure the join table column exists
			if(!$this->columnExists($table['db'], $table['name'], $col)){
				return NULL;
			}

			# Ensure comparison operator is valid
			if(!$this->isValidComparisonOperator($eq)){
				return NULL;
			}

			# Negate missing NULLs in negative comparisons
			if($this->isNegativeComparisonOperator($eq)){
				# Collecting all tables and parent tables with children that have where clauses
				$this->setTableAliasWithWhere([$table['alias']]);

				return "(`{$table['alias']}`.`{$col}` {$eq} {$val} OR `{$table['alias']}`.`{$col}` IS NULL)";
				/**
				 * In cases where a condition is that a value is NOT (!=, <>, NOT IN) something,
				 * those values that are NULL will also be omitted. This prevents that from happening.
				 */
			}

			# Ensure table alias exists
			if(!$this->tableAliasExists($tbl_alias)){
				return NULL;
			}

			if($where){
				# Collecting all tables and parent tables with children that have where clauses
				$this->setTableAliasWithWhere([$table['alias'], $tbl_alias]);
			}

			return "`{$table['alias']}`.`{$col}` {$eq} `{$tbl_alias}`.`{$tbl_col}`";
		} # "col" => "val",
		else {
			# Ensure the join table column exists
			if(!$this->columnExists($table['db'], $table['name'], $col)){
				return NULL;
			}

			# Ensure correct comparison operator for NULL vals
			$eq = $this->correctComparisonOperatorForNullVal($val, "=");

			# Ensure the formatted value is valid
			if(($val = $this->formatComparisonVal($val)) === NULL){
				//A legit value can be "0"
				return NULL;
			}

			if($where){
				# Collecting all tables and parent tables with children that have where clauses
				$this->setTableAliasWithWhere([$table['alias']]);
			}

			return "`{$table['alias']}`.`{$col}` {$eq} {$val}";
		}
	}

	/**
	 * NULL vals will only work with IS and IS NOT comparison operators.
	 * This fixes any errors the user may have made by using = or <> or !=.
	 *
	 * @param $val
	 * @param $eq
	 *
	 * @return string
	 */
	protected function correctComparisonOperatorForNullVal($val, string $eq): string
	{
		# If the val is NOT NULL, ignore and return the operator as is
		if($val !== NULL){
			return $eq;
		}

		# If the operator is negative, return a negative NULL operator
		if($this->isNegativeComparisonOperator($eq)){
			return "IS NOT";
		}

		# Most commonly, return "IS" (instead of =)
		return "IS";
	}

	/**
	 * Create the row limit string, based on either start+length or length/limit values.
	 * If neither of the three values are given, the string value is not set.
	 *
	 * @param      $limit
	 * @param null $start
	 * @param null $length
	 */
	protected function setLimit($limit, $start = NULL, $length = NULL): void
	{
		if(is_array($limit)){
			[$start, $length] = $limit;
		}

		if($start && $length){
			$this->limit = "LIMIT {$start}, {$length}";
		} else if($length){
			$this->limit = "LIMIT {$length}";
		} else if($limit){
			$this->limit = "LIMIT {$limit}";
		}
	}

	protected function getJoinsSQL(?bool $with_where = true, ?bool $without_where = true): ?string
	{
		if(!$this->join){
			return NULL;
		}

		$tables = [];

		$table_alias_with_where = $this->getTableAliasWithWhere();

		foreach($this->join as $type => $joins){
			foreach($joins as $join){

				# If we ONLY want those tables that HAVE a where clause
				if($with_where && !$without_where){
					if(!$table_alias_with_where[$join['table']['alias']]){
						continue;
					}
				}

				# If we ONLY want those tables that DON'T have a where clause
				if(!$with_where && $without_where){
					if($table_alias_with_where[$join['table']['alias']]){
						continue;
					}
				}

				$tables[] = $this->getJoinTableSQL($type, $join['table']);
				$tables[] = $this->getConditionsSQL("ON", $join['on']);
			}
		}

		return implode("\r\n", array_filter($tables));
	}

//	/**
//	 * Returns an array of table aliases that have where conditions,
//	 * or who's children have where conditions.
//	 * Both the keys and the values are the name of the tables.
//	 *
//	 * @param bool|null $include_main
//	 *
//	 * @return array
//	 */
//	public function getTablesWithWhere(): array
//	{
//		$tables_with_where[$this->table['alias']] = $this->table['alias'];
//
//		foreach($this->join as $type => $joins){
//			foreach($joins as $join){
//				if(!$join['where']){
//					continue;
//				}
//				if($join['where']['or']){
//					foreach($join['where']['or'] as $and){
//						$tables_with_where[$join['table']] = $join['table'];
//					}
//				}
//				if($join['where']['or']){
//					$tables_with_where[$join['alias']] = $join['alias'];
//					$tables_with_where[$join['parent_alias']] = $join['parent_alias'];
//				}
//				if($join['where']['and']){
//					$str = "`removed` IS NULL";
//					foreach($join['where']['and'] as $and){
//						if(substr($and, strlen($str) * -1) != $str){
//							$tables_with_where[$join['table']] = $join['table'];
//						}
//					}
//				}
//			}
//		}
//
//		return $tables_with_where;
//	}

	protected function getLimitSQL(): ?string
	{
		return $this->limit;
	}

	protected function getWhereSQL(?array $alias_only = NULL, ?array $except_alias = NULL): ?string
	{
		return $this->getConditionsSQL("WHERE", $this->getAllWhereConditions($alias_only, $except_alias));
	}

	/**
	 * Returns all where conditions from all tables
	 * as strings in a simple array with AND and OR root keys.
	 *
	 * @param string|null $alias_only
	 * @param string|null $except_alias
	 *
	 * @return array
	 */
	protected function getAllWhereConditions(?array $alias_only = NULL, ?array $except_alias = NULL): array
	{
		$where = [];

		# From the main table
		if(is_array($this->where)){
			foreach($this->where as $type => $conditions){
				foreach($conditions as $condition){
					$wheres[$this->table['alias']][$type][] = $condition;
				}
			}
		}

		# From any joins
		if(is_array($this->join)){
			foreach($this->join as $join_type => $joins){
				foreach($joins as $join){
					if($join['where']){
						foreach($join['where'] as $type => $conditions){
							foreach($conditions as $condition){
								$wheres[$join['table']['alias']][$type][] = $condition;
							}
						}
					}
				}
			}
		}

		# Optional filter on table alias
		foreach($wheres as $alias => $types){
			if($alias_only){
				if(!in_array($alias, $alias_only)){
					continue;
				}
			}
			if($except_alias){
				if(in_array($alias, $except_alias)){
					continue;
				}
			}
			foreach($types as $type => $conditions){
				$where[$type] = array_merge($where[$type] ?: [], $conditions ?: []);
			}
		}

		return $where;
	}

	/**
	 * Generates and returns a given join's conditions.
	 *
	 * @param string     $condition_type
	 * @param array|null $on
	 *
	 * @return string|null
	 */
	protected function getConditionsSQL(string $condition_type, ?array $on): ?string
	{
		if(!is_array($on)){
			return NULL;
		}

		$strings = [];

		foreach($on as $type => $conditions){
			$type = strtoupper($type);

			# Trim the conditions
			$conditions = array_unique(array_filter($conditions));

			if($type == "OR"){
				$strings[] = "AND (\r\n\t" . implode("\r\n\t{$type} ", $conditions) . "\r\n)";
			} else {
				$strings[] = implode("\r\n{$type} ", $conditions);
			}
		}

		if(!array_filter($strings)){
			return NULL;
		}

		$condition_type = strtoupper($condition_type);

		return "{$condition_type} " . implode("\r\n", $strings);
	}

	/**
	 * Returns a formatted string to prefix a join.
	 *
	 * @param string $type
	 * @param array  $table
	 *
	 * @return string
	 */
	protected function getJoinTableSQL(string $type, array $table): string
	{
		return "{$type} JOIN `{$table['db']}`.`{$table['name']}` AS `{$table['alias']}`";
	}

	/**
	 * Set the columns that are going to go to the SET section of the INSERT or UPDATE query.
	 *
	 * @param array|null $set
	 * @param array|null $html
	 * @param bool|null  $ignore_empty If set to TRUE, will not throw an exception if no columns are to be set
	 */
	protected function setSet(?array $set, ?array $html, ?bool $ignore_empty = NULL): void
	{
		if(!$set){
			if($ignore_empty){
				return;
			}
			throw new mysqli_sql_exception("No data was given to set in the <code>{$this->table['name']}</code> table.");
		}
		if(!str::isNumericArray($set)){
			//if only one column is being inserted
			$set = [$set];
		}

		# Add the row(s) to the global variable
		$this->set = $set;

		# Add the columns that may contain HTML to the global variable
		$this->html = $html;

		# Grab all the columns from all the rows (not all rows need to have the same number of columns)
		foreach($this->set as $row){
			# From each row, grab all the columns
			$this->addColumns(array_keys($row));
		}

		# Remove columns the user cannot set
		$this->removeIllegalColumns();
	}

	/**
	 * Make sure that the *USER* does not try to insert values
	 * into fields they're not allowed to insert values into,
	 * or don't exist.
	 *
	 * @param array $set Array of columns to set.
	 */
	protected function removeIllegalColumns(): void
	{
		# If this table has no columns the user can set
		if(!$table_metadata = $this->getTableMetadata($this->table)){
			throw new mysqli_sql_exception("The <code>{$this->table['name']}</code> table has no columns that can be set.");
		}

		# Only keep the columns that actually exist in the table (and that the user can update/insert)
		$this->columns = array_intersect($this->columns, array_keys($table_metadata));
	}

	/**
	 * Add a column to the list of columns that will be populated by the insert.
	 *
	 * @param $columns
	 */
	protected function addColumns(?array $columns)
	{
		$this->columns = array_unique(array_merge($this->columns ?: [], $columns ?: []));
	}

	/**
	 * Formats a value that is to be inserted with INSERT/UPDATE.
	 * The format is contextually dependant, thus table and column
	 * info are required.
	 *
	 * @param array  $table
	 * @param string $col
	 * @param        $val
	 *
	 * @return string|null
	 */
	protected function formatInsertVal(array $table, string $col, $val): ?string
	{
		# Table column meta data
		$table_metadata = $this->getTableMetadata($table);

		# Array values are not allowed, with exceptions
		if(is_array($val)){

			# Empty arrays
			if(empty($val)){
				//if it's an empty array
				return "NULL";
			}

			# Non-empty array with a single empty key and empty sub value
			while(true) {
				foreach($val as $k => $v){
					if(trim($k) || trim($v)){
						break 2;
					}
				}
				return "NULL";
			}

			# Otherwise, arrays are not allowed and will return an error
			throw new mysqli_sql_exception("Set values cannot be in array form. The following array was attempted set for the <b>{$col}</b> column: <pre>" . print_r($val, true) . "</pre>");
		}

		# HTML columns
		if(in_array($col, $this->html ?: [])
			&& $table_metadata[$col]['CHARACTER_MAXIMUM_LENGTH'] > 255){
			/**
			 * Only columns that are of the TEXT/LONGTEXT type,
			 * or columns that are VARCHAR but have a max length LONGER
			 * than 255 are allowed to contain HTML.
			 * In addition, the column needs to be explicitly marked
			 * to allow for HTML.
			 */
			$html = true;
		}

		# Timestamps
		if($table_metadata[$col]['DATA_TYPE'] == 'timestamp'){
			/**
			 * If the field is set to TIMESTAMP (and not DATETIME),
			 * the assumption is that the field is expecting UNIX timestamps.
			 * However, mySQL doesn't seem to accept actual timestamp INTs
			 * as input.
			 * Thus the input must be translated to a more
			 * traditional datetime string. This ignores timezones.
			 */
			if(is_numeric($val)){
				$val = date_create('@' . $val)->format("Y-m-d H:i:s");
			}
		}

		# Date
		if($table_metadata[$col]['DATA_TYPE'] == 'date'){
			# To ensure that dates in other formats are understood
			if(is_string($val) && strtotime($val)){
				$val = date_create('@' . strtotime($val))->format("Y-m-d");
			}

			if(!$val && is_string($val)){
				//if the value is ""
				return "NULL";
				// Because datetime columns do not accept strings, even empty ones
			}
		}

		# Datetime
		if($table_metadata[$col]['DATA_TYPE'] == 'datetime'){
			# To ensure that date-times in other formats are understood
			if(is_string($val) && strtotime($val)){
				$val = date_create('@' . strtotime($val))->format("Y-m-d H:i:s");
			}

			if(!$val && is_string($val)){
				//if the value is ""
				return "NULL";
				// Because datetime columns do not accept strings, even empty ones
			}
		}

		# Int columns
		if($table_metadata[$col]['DATA_TYPE'] == 'int'){
			if(!$val && is_string($val)){
				//if the value is ""
				return "NULL";
				// Because INT columns do not accept strings, even empty ones
			}
		}

		# Tinyint columns
		if($table_metadata[$col]['DATA_TYPE'] == 'tinyint'){
			/**
			 * The assumption here is that if the data type is tinyint,
			 * it's used as a de facto boolean data type.
			 * Thus, string true/false values are translated to 1/NULL values.
			 */
			if($val == "true"){
				return (int)1;
			}
			if($val == "false"){
				return "NULL";
			}
			if(!$val){
				//"", "0", 0
				return "NULL";
			}
		}

		# Format the value, ensure it conforms
		if(($val = $this->formatComparisonVal($val, $html)) === NULL){
			//A legit value can be "0"

			return "NULL";
			//if the value is not accepted, set it to be NULL
		}

		return $val;
	}

	/**
	 * Formats values used in comparisons, either in WHERE or ON clauses.
	 * Is also used at the tail end of the SET formatting for INSERT and UPDATE.
	 *
	 * @param mixed|null $val
	 *
	 * @return mixed
	 */
	protected function formatComparisonVal($val, ?bool $html = NULL)
	{
		if(str::isNumericArray($val)){
			//if the vals are an an array (belonging to the IN/NOT IN function)
			foreach($val as $v){
				if(($v = $this->formatComparisonVal($v)) === NULL){
					//A legit value can be "0"
					continue;
				}
				$vals[] = $v;
			}
			return "(" . implode(",", array_unique($vals)) . ")";
		}

		if($val === NULL){
			//If the val is actual NULL, switch it to the mySQL string equivalent
			return "NULL";
		}

		if($val === true){
			//if the value is boolean TRUE, replace it with a 1
			return 1;
		}

		if($val === false){
			//if the value is a boolean FALSE, ignore it completely
			return NULL;
		}

		# Tidy up the value (after we've checked it's not boolean or NULL)
		$val = str::i($val, $html);

		if($val == "NOW()"){
			//If it's a datetime function
			return $val;
		}

		if(is_int($val) || is_float($val)){
			//if it's a number or a float, return it as is
			return $val;
		}

		# Enclose with single quotes
		return "'{$val}'";
	}

	/**
	 * Checks to see if the operator is a valid mySQL comparison operator.
	 *
	 * @param string $operator
	 *
	 * @return bool
	 */
	protected function isValidComparisonOperator(string $operator): bool
	{
		return in_array(strtoupper($operator), ["=", "!=", "<", ">", "<=", ">=", "<>", "IS", "IS NOT", "LIKE", "NOT LIKE", "IN", "NOT IN"]);
	}

	/**
	 * Checks to see if the operator is a negative one.
	 *
	 * @param string $operator
	 *
	 * @return bool
	 */
	protected function isNegativeComparisonOperator(string $operator): bool
	{
		return in_array(strtoupper($operator), ["!=", "<>", "NOT LIKE", "NOT IN"]);
	}

	/**
	 * Returns a table (array) of metadata for a given table, on a column-per-row basis.
	 * Can be called externally like so:
	 * <code>
	 * $this->sql->getTableMetadata($tbl, true, true);
	 * </code>
	 *
	 * @param string|array $table   A table array (containing at least `db` and `name` keys)
	 * @param bool|null    $refresh Whether or not to force a refresh of the metadata
	 * @param bool|null    $all     Whether or not to return all columns (not just the ones the user can update)
	 *
	 * @return array
	 */
	public function getTableMetadata($table, ?bool $refresh = NULL, ?bool $all = NULL): array
	{
		if(is_string($table)){
			$table = [
				"name" => $table,
				"db" => $_ENV['db_database'],
			];
		}

		# Verify all the details are correct
		$this->verifyTableArray($table);

		if($refresh){
			//If the table data is to be refreshed
			$this->loadTableMetadata($table);
		} else if(!$this->table_column_names_all[$table["db"]][$table["name"]]){
			//if the table data doesn't exist yet
			$this->loadTableMetadata($table);
		}

		if($all){
			//if the user has requested *all* columns (including those the user cannot update)
			return $this->table_column_names_all[$table["db"]][$table["name"]];
		}

		# Return only the columns they can update (that can be no columns)
		return $this->table_column_names[$table["db"]][$table["name"]] ?: [];
	}

	/**
	 * Given a database-table, will load the table column metadata to the `tableColumnNamesAll` and
	 * `tableColumnNames` arrays.
	 *
	 * @param array $table
	 */
	protected function loadTableMetadata(array $table): void
	{
		# Write the query for table metadata
		$query = "SELECT * FROM `INFORMATION_SCHEMA`.`COLUMNS` WHERE `TABLE_SCHEMA` = '{$table['db']}' AND `TABLE_NAME` = '{$table['name']}' ORDER BY `ORDINAL_POSITION` ASC";

		# Run the query to get table metadata (assuming the database-table combo exists)
		$result = $this->mysqli->query($query);

		# Go thru the result (assuming the database-table exists))
		if(is_object($result)){
			# Get the columns the user cannot update
			$columns_user_cannot_update = $this->getTableColumnsUsersCannotUpdate($table['name']);

			# Save each result row
			while($c = $result->fetch_assoc()) {
				$this->table_column_names_all[$table['db']][$table['name']][$c['COLUMN_NAME']] = $c;
				if(!in_array($c['COLUMN_NAME'], $columns_user_cannot_update)){
					$this->table_column_names[$table['db']][$table['name']][$c['COLUMN_NAME']] = $c;
				}
			}
			$result->close();
		}
	}

	/**
	 * Generates and returns an order by string, if required.
	 * Checks to see if the column exists, and that the direction is valid.
	 *
	 * @param array      $table
	 * @param array|null $columns
	 * @param array|null $order_bys
	 *
	 * @return array|null
	 */
	protected function getOrderBy(array $table, ?array $columns, ?array $order_bys): ?array
	{
		if(!$order_bys){
			return NULL;
		}

		foreach($order_bys as $col => $dir){
			$dir = strtoupper($dir);

			if(!in_array($dir, ["ASC", "DESC"])){
				throw new mysqli_sql_exception("Order by directions have to either be ASC or DESC. <code>{$dir}</code> is not an recognised value.");
			}

			# Column
			if($key = array_search($col, array_column($columns, 'name')) !== false){
				//if the column being ordered is the name of a column
				$order_by[] = "`{$columns[$key]['table_alias']}`.`$col` {$dir}";
				continue;
			}

			# Alias
			if($key = array_search($col, array_column($columns, 'alias')) !== false){
				//if the column being ordered is the alias of a column
				$order_by[] = "`$col` {$dir}";
				continue;
			}

			# "Hidden" column
			if($this->columnExists($table['db'], $table['name'], $col)){
				//If the table to order by is not part of the columns to display, but is still a valid column
				$order_by[] = "`{$table['alias']}`.`{$col}` {$dir}";
				continue;
			}
		}

		return $order_by;
	}

	/**
	 * Generates a standard SQL UPDATE query.
	 * @return string
	 */
	protected function generateUpdateSQL(): string
	{
		$query[] = "UPDATE `{$this->table['db']}`.`{$this->table['name']}` AS `{$this->table['alias']}`";
		$query[] = $this->getJoinsSQL();
		$query[] = $this->getSetSQL();
		$query[] = $this->getWhereSQL();
		$query[] = $this->getLimitSQL();
		return implode("\r\n", array_filter($query));
	}

	/**
	 * Gets all the variables formatted to be set in a UPDATE query.
	 * @return string
	 */
	protected function getSetSQL(): string
	{
		foreach(reset($this->set) as $col => $val){

			# Make sure the column is part of the accepted set
			if(is_string($col) && !in_array($col, $this->columns)){
				continue;
			}

			# "col" => ["tbl_alias", "tbl_col"]
			if(is_string($col) && is_array($val) && (count($val) == 2)){
				[$tbl_alias, $tbl_col] = $val;

				# Both values have to exist
				if(!$tbl_alias || !$tbl_col){
					NULL;
				}

				# Ensure table alias exists
				if(!$this->tableAliasExists($tbl_alias)){
					continue;
				}

				$strings[] = "`{$this->table['alias']}`.`{$col}` = `{$tbl_alias}`.`{$tbl_col}`";
				continue;
			}

			# "col" => ["tbl_db", "tbl_name", "tbl_col"]
			if(is_string($col) && is_array($val) && (count($val) == 3)){
				[$tbl_db, $tbl_name, $tbl_col] = $val;

				# Ensure the counterpart also exists
				if(!$this->columnExists($tbl_db, $tbl_name, $tbl_col)){
					continue;
				}

				$strings[] = "`{$this->table['alias']}`.`{$col}` = `{$this->getDbAndTableString($tbl_db,$tbl_name)}`.`{$tbl_col}`";
				/**
				 * In cases where the same table from the same database
				 * is referenced, and tbl_db + tbl_name are given,
				 * the assumption is that it's the first table that is
				 * referenced here.
				 */
				continue;
			}

			# "col" => ["tbl_db", "tbl_name", "tbl_col", "calc"]
			if(is_string($col) && is_array($val) && (count($val) == 4)){
				[$tbl_db, $tbl_name, $tbl_col, $calc] = $val;

				# Ensure the counterpart also exists
				if(!$this->columnExists($tbl_db, $tbl_name, $tbl_col)){
					continue;
				}

				/**
				 * Calc is any suffixed calculation text value to
				 * perform on the value. Is used by the re-ordering method
				 * to shift values up or down.
				 */

				$strings[] = "`{$this->table['alias']}`.`{$col}` = `{$this->getDbAndTableString($tbl_db,$tbl_name)}`.`{$tbl_col}` {$calc}";
				/**
				 * In cases where the same table from the same database
				 * is referenced, and tbl_db + tbl_name are given,
				 * the assumption is that it's the first table that is
				 * referenced here.
				 */
				continue;
			}

			# "col" => ["tbl_db", "tbl_name", "tbl_col", "pre", "post"]
			if(is_string($col) && is_array($val) && (count($val) == 5)){
				[$tbl_db, $tbl_name, $tbl_col, $pre, $post] = $val;

				# Ensure the counterpart also exists
				if(!$this->columnExists($tbl_db, $tbl_name, $tbl_col)){
					continue;
				}

				/**
				 * The pre and post vars constitute a
				 * free-form format for complex requests,
				 * like DATE_ADD().
				 * Any of the values can be NULL
				 */

				$strings[] = "`{$this->table['alias']}`.`{$col}` = {$pre}`{$this->getDbAndTableString($tbl_db,$tbl_name)}`.`{$tbl_col}`{$post}";
				/**
				 * In cases where the same table from the same database
				 * is referenced, and tbl_db + tbl_name are given,
				 * the assumption is that it's the first table that is
				 * referenced here.
				 */
				continue;
			}

			# "col" => "val",
			$val = $this->formatInsertVal($this->table, $col, $val);
			$strings[] = "`{$this->table['alias']}`.`$col` = {$val}";
		}
		return "SET\r\n\t" . implode(",\r\n\t", $strings);
	}

	/**
	 * Checks to see if the database and table exist.
	 *
	 * @param array $table
	 *
	 * @return bool
	 */
	protected function verifyTableArray(array $table): bool
	{
		# Ensure the database exists
		if(!$this->dbExists($table["db"])){
			throw new mysqli_sql_exception("The database <code>{$table["db"]}</code> doesn't seem to exists or the current user does not have access to it.");
		}

		# Ensure the table exists in the database
		if(!$this->tableExists($table["db"], $table["name"])){
			throw new mysqli_sql_exception("The <code>{$table["name"]}</code> table does not seem to exist in the <code>{$table["db"]}</code> database, or the current user does not have access to it.");
		}

		return true;
	}

	/**
	 * Doest $db exist (and has this user access to it)?
	 * Can be called multiple times, will only query the
	 * database once and store the results for future use.
	 *
	 * @param string $db
	 *
	 * @return bool
	 */
	protected function dbExists(string $db): bool
	{
		$db = str::i($db);
		if(key_exists($db, $this->exists['db'] ?: [])){
			return $this->exists['db'][$db];
		}
		$query = "SELECT COUNT(*) FROM `INFORMATION_SCHEMA`.`COLUMNS` WHERE `TABLE_SCHEMA` = '{$db}'";

		$this->exists['db'][$db] = (bool)$this->mysqli->query($query)->fetch_assoc()["COUNT(*)"];
		return $this->exists['db'][$db];
	}

	/**
	 * Does $table exist in $db (and has this user access to it)?
	 * Can be called multiple times, will only query the
	 * database once and store the results for future use.
	 *
	 * @param string $db
	 * @param string $table
	 *
	 * @return bool
	 */
	public function tableExists(string $db, string $table): bool
	{
		# Clean the database name
		$db = str::i($db);

		# Clean the table name
		$table = str::i($table);

		# if the database-table combo has already been checked before, reuse results
		if(key_exists($db, $this->exists['table'] ?: [])){
			if(key_exists($table, $this->exists['table'][$db])){
				return $this->exists['table'][$db][$table];
			}
		}

		# Write the query for table metadata
		$query = "SELECT COUNT(*) FROM `INFORMATION_SCHEMA`.`COLUMNS` WHERE `TABLE_SCHEMA` = '{$db}' AND `TABLE_NAME` = '{$table}'";

		# Does this database-table combo exist?
		$this->exists[$db][$table] = (bool)$this->mysqli->query($query)->fetch_assoc()["COUNT(*)"];

		return $this->exists[$db][$table];
	}

	/**
	 * Does this db-table-col combo exist?
	 *
	 * @param string $db
	 * @param string $table
	 * @param string $col
	 *
	 * @return bool
	 */
	protected function columnExists(?string $db, string $table, string $col): bool
	{
		# Clean the database name
		if(!$db = str::i($db)){
			//if none is supplied, assume the generic db
			$db = $_ENV['db_database'];
		}

		# Clean the table name
		if(!$table = str::i($table)){
			return false;
		}

		# Clean the column name
		if(!$col = str::i($col)){
			return false;
		}

		# if the database-table-col combo has already been checked before, reuse results
		if(key_exists($db, $this->exists['col'] ?: [])){
			if(key_exists($table, $this->exists['col'][$db] ?: [])){
				if(key_exists($col, $this->exists['col'][$db][$table] ?: [])){
					return $this->exists['col'][$db][$table][$col];
				}
			}
		}

		# Write the query for table metadata
		$query = "SELECT COUNT(*) FROM `INFORMATION_SCHEMA`.`COLUMNS` WHERE `TABLE_SCHEMA` = '{$db}' AND `TABLE_NAME` = '{$table}' AND `COLUMN_NAME` = '{$col}'";

		# Does this database-table-col combo exist?
		$this->exists['col'][$db][$table][$col] = (bool)$this->mysqli->query($query)->fetch_assoc()["COUNT(*)"];

		return $this->exists['col'][$db][$table][$col];
	}

	/**
	 * Checks to see if a table alias exists.
	 *
	 * @param $alias
	 *
	 * @return bool
	 */
	protected function tableAliasExists($alias): bool
	{
		if($alias == $this->table['alias']){
			return true;
		}

		if(!$this->join){
			return false;
		}

		foreach($this->join as $type => $joins){
			foreach($joins as $join){
				if($alias == $join['table']['alias']){
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Check to see if there where conditions have been included,
	 * once you ignore the "`remove` IS NULL" condition.
	 * Does so by counting the number of conditions in total,
	 * and checking if that number is higher than the number of
	 * "`remove` IS NULL" conditions.
	 * @return bool
	 */
	protected function whereConditionsExist(): bool
	{
		if(!$where = $this->getAllWhereConditions()){
			return false;
		}

		foreach($where as $type => $conditions){
			$conditions_count += count($conditions);
		}

		# From the main table
		$removed_conditions = $this->table['include_removed'] ? 0 : 1;

		# From any joins
		if(is_array($this->join)){
			foreach($this->join as $join_type => $joins){
				foreach($joins as $join){
					$removed_conditions += $join['table']['include_removed'] ? 0 : 1;
				}
			}
		}

		if($conditions_count > $removed_conditions){
			return true;
		}

		return false;
	}

	/**
	 * Returns a list of columns a user is NOT allowed to update.
	 *
	 * @param string $table
	 *
	 * @return array
	 */
	public function getTableColumnsUsersCannotUpdate(string $table): array
	{
		return [
			"${table}_id",
			"created",
			"created_by",
			"updated",
			"updated_by",
			"removed",
			"removed_by",
		];
	}
}