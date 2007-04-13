<?php
/*

 Copyright (c) 2001 - 2007 Ampache.org
 All rights reserved.

 This program is free software; you can redistribute it and/or
 modify it under the terms of the GNU General Public License v2
 as published by the Free Software Foundation.

 This program is distributed in the hope that it will be useful,
 but WITHOUT ANY WARRANTY; without even the implied warranty of
 MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 GNU General Public License for more details.

 You should have received a copy of the GNU General Public License
 along with this program; if not, write to the Free Software
 Foundation, Inc., 59 Temple Place - Suite 330, Boston, MA  02111-1307, USA.

*/
/* Make sure they aren't directly accessing it */
if (INIT_LOADED != '1') { exit; }

class Dba { 

	private static $_default_db;

	private static $config; 

	/**
	 * constructor
	 * This does nothing with the DBA class
	 */
	private function __construct() { 

		// Rien a faire

	} // construct

	/**
	 * query
	 * This is the meat of the class this does a query, it emulates
	 * The mysql_query function
	 */
	public static function query($sql) { 

		$resource = mysql_query($sql,self::dbh()); 

		return $resource; 

	} // query

	/**
	 * escape
	 * This runs a escape on a variable so that it can be safely inserted
	 * into the sql 
	 */
	public static function escape($var) { 

		$string = mysql_real_escape_string($var,self::dbh()); 
		
		return $string; 

	} // escape

	/**
	 * fetch_assoc
	 * This emulates the mysql_fetch_assoc and takes a resource result
	 */
	public static function fetch_assoc($resource) { 

		$result = mysql_fetch_assoc($resource); 

		if (!$result) { return array(); } 

		return $result;

	} // fetch_assoc

	/**
	 * fetch_row
	 * This emulates the mysql_fetch_row and takes a resource result
	 */
	public static function fetch_row($resource) { 

		$result = mysql_fetch_row($resource); 

		if (!$result) { return array(); } 

		return $result; 

	} // fetch_row

	/**
	 * _connect
	 * This connects to the database, used by the DBH function
	 */
	private static function _connect($db_name) { 

		if (self::$_default_db == $db_name) { 
			$username = Config::get('mysql_username'); 
			$hostname = Config::get('mysql_hostname'); 
			$password = Config::get('mysql_password'); 
			$database = Config::get('mysql_database'); 
		} 
		else { 
			// Do this later
		} 

		$dbh = mysql_connect($hostname,$username,$password); 
		if (!$dbh) { debug_event('Database','Error unable to connect to database' . mysql_error(),'1'); } 

		$select_db = mysql_select_db($database,$dbh); 

		return $dbh;

	} // _connect

	/**
	 * dbh
	 * This is called by the class to return the database handle
	 * for the specified database, if none is found it connects
	 */
	public static function dbh($database='') { 

		if (!$database) { $database = self::$_default_db; } 

		if (!is_resource(self::$config->get($database))) { 
			$dbh = self::_connect($database);
			self::$config->set($database,$dbh,1); 
			return $dbh;
		} 
		else { 
			return self::$config->get($database); 
		} 


	} // dbh

	/**
	 * insert_id
	 * This emulates the mysql_insert_id function, it takes
	 * an optional database target
	 */
	public static function insert_id() { 

		$id = mysql_insert_id(self::dbh()); 
		return $id; 

	} // insert_id

	/**
	 * auto_init
	 * This is the auto init function it sets up the config class
	 * and also sets the default database 
	 */
	public static function auto_init() { 

		self::$_default_db = Config::get('mysql_database'); 
		self::$config = new Config(); 

		return true; 

	} // auto_init

} // dba class

?>