<?php
/**
 * Settings.php
 *
 * Shopp settings manager
 *
 * @author Jonathan Davis
 * @version 1.2
 * @copyright 2008-2011 Ingenesis Limited
 * @license GNU GPL version 3 (or later) {@see license.txt}
 * @package shopp
 * @since 1.0
 * @subpackage settings
 **/

defined( 'WPINC' ) || header( 'HTTP/1.1 403' ) & exit; // Prevent direct access

class ShoppSettings extends DatabaseObject {

	static $table = 'meta';			// Base settings table name

	private static $object;			// Keep private reference to running object

	private $registry = array();	// Registry of setting objects
	private $installed = false;		// Flag when database tables don't exist
	private $loaded = false;		// Flag when settings are successfully loaded
	private $bootup = false;		// Load process in progress

	public $_table;					// Settings runtime table name

	/**
	 * Settings object constructor
	 *
	 * If no settings are available (the table doesn't exist),
	 * the unavailable flag is set.
	 *
	 * @author Jonathan Davis
	 * @since 1.0
	 * @version 1.1
	 **/
	private function __construct () {
		$this->_table = $this->tablename(self::$table);
		$this->bootup = ShoppLoader::is_activating();

		if ( $this->bootup ) add_action('shopp_init', array($this, 'booted'));
	}

	/**
	 * Once Shopp has init'd this will take us back out of bootup mode and allow access to the
	 * db.
	 */
	public function booted () {
		$this->bootup = false;
	}

	static function object () {
		if ( ! self::$object instanceof self )
			self::$object = new self;
		return self::$object;
	}

	/**
	 * Update the availability status of the settings database table
	 *
	 * @author Jonathan Davis
	 * @since 1.1
	 *
	 * @return boolean
	 **/
	public function available () {
		return ($this->loaded && !empty($this->registry));
	}

	/**
	 * Load settings from the database
	 *
	 * @author Jonathan Davis
	 * @since 1.0
	 *
	 * @return boolean
	 **/
	public function load ( $name = '', $arg2 = false ) {

		if ( ! empty($name) ) $where[] = "name='" . DB::clean($name) . "'";
		else {
			if ($this->bootup) return false; // Already trying to load all settings, bail out to prevent an infinite loop of DOOM!
			$this->bootup = true;
		}

		$Setting = $this->setting();
		$where = array("parent=0", "context='$Setting->context'", "type='$Setting->type'");
		$where = join(' AND ',$where);

		$settings = DB::query("SELECT name,value FROM $this->_table WHERE $where", 'array', array($this, 'register'));

		if ( ! is_array($settings) || count($settings) == 0 ) return false;
		if ( ! empty($settings) ) $this->registry = array_merge($this->registry, $settings);

		$this->bootup = false;
		return ($this->loaded = true);
	}

	public function register (&$records,$record) {
		$records[$record->name] = $this->restore($record->value);
	}

	/**
	 * Add a new setting to the registry and store it in the database
	 *
	 * @author Jonathan Davis
	 * @since 1.0
	 *
	 * @param string $name Name of the setting
	 * @param mixed $value Value of the setting
	 * @return boolean
	 **/
	public function add ($name, $value) {
		$Setting = $this->setting();
		$Setting->name = $name;
		$Setting->value = DB::clean($value);

		$data = DB::prepare($Setting);
		$dataset = DatabaseObject::dataset($data);
		if ( DB::query("INSERT $this->_table SET $dataset") )
		 	$this->registry[$name] = $this->restore(DB::clean($value));
		else return false;
		return true;
	}

	/**
	 * Updates the setting in the registry and the database
	 *
	 * @author Jonathan Davis
	 * @since 1.0
	 *
	 * @param string $name Name of the setting
	 * @param mixed $value Value of the setting to update
	 * @return boolean
	 **/
	public function update ($name,$value) {

		if ($this->get($name) == $value) return true;

		$Setting = $this->setting();
		$Setting->name = $name;
		$Setting->value = DB::clean($value);
		$data = DB::prepare($Setting);				// Prepare the data for db entry
		$dataset = DatabaseObject::dataset($data);	// Format the data in SQL

		$where = array("context='$Setting->context'","type='$Setting->type'");
		if (!empty($name)) $where[] = "name='".DB::clean($name)."'";
		$where = join(' AND ',$where);

		if (DB::query("UPDATE $this->_table SET $dataset WHERE $where"))
			$this->registry[$name] = $this->restore($value); // Update the value in the registry
		else return false;
		return true;
	}

	/**
	 * Save a setting to the database
	 *
	 * @author Jonathan Davis
	 * @since 1.0
	 *
	 * @param string $name Name of the setting to save
	 * @param mixed $value Value of the setting
	 * @return void
	 **/
	public function save ($name=false,$value=false) {

		if ( empty($name) ) return false;

		// Update or Insert as needed
		if ( is_null($this->get($name)) ) $this->add($name,$value);
		else $this->update($name,$value);

	}

	/**
	 * Save a setting to the database if it does not already exist
	 *
	 * @author Jonathan Davis
	 * @since 1.1
	 *
	 * @param string $name Name of the setting to save
	 * @param mixed $value Value of the setting
	 * @return void
	 **/
	public function setup ($name,$value) {
		if (is_null($this->get($name))) $this->add($name, $value);
	}

	/**
	 * Remove a setting from the registry and the database
	 *
	 * @author Jonathan Davis
	 * @since 1.0
	 *
	 * @param string $name Name of the setting to remove
	 * @return boolean
	 **/
	public function delete ($name=false) {
		$null = null;
		if (empty($name)) return false;
		$Setting = $this->setting();

		$where = array("context='$Setting->context'","type='$Setting->type'");
		if (!empty($name)) $where[] = "name='".DB::clean($name)."'";
		$where = join(' AND ',$where);

		if (!DB::query("DELETE FROM $this->_table WHERE $where")) return false;
		if (isset($this->registry[$name])) $this->registry[$name] = $null;
		return true;
	}

	/**
	 * Get a specific setting from the registry
	 *
	 * If no setting is available in the registry, try
	 * loading it directly from the database.
	 *
	 * @author Jonathan Davis
	 * @since 1.0
	 *
	 * @param string $name The name of the setting
	 * @return mixed The value of the setting
	 **/
	public function &get ( $name ) {

		$null = null;

		if ( $this->bootup ) {// Prevent infinite loop of DOOM!
			return $null;
		}

		if ( ! $this->available() )
			$this->load();

		if ( ! array_key_exists($name,$this->registry) )
			$this->load($name);

		if ( ! isset($this->registry[$name]) )	// Return null and add an entry to the registry
			$this->registry[$name] = $null;		// to avoid repeat database queries

		$setting = apply_filters( 'shopp_get_setting', $this->registry[$name], $name);

		return $setting;

	}

	/**
	 * Restores a serialized value to a runtime object/structure
	 *
	 * @author Jonathan Davis
	 * @since 1.0
	 *
	 * @param string $value A value to restore if necessary
	 * @return mixed
	 **/
	public function restore ($value) {
		if ( ! is_string($value) ) return $value;
		// Return unserialized, if serialized value
		if ( is_serialized($value) ) {
			$restored = unserialize($value);
			if ( empty($restored) ) $restored = unserialize( stripslashes($value) );
			if ( false !== $restored ) return $restored;
		}
		return $value;
	}

	/**
	 * Provides a blank setting object template
	 *
	 * @author Jonathan Davis
	 * @since 1.0
	 *
	 * @return object
	 **/
	public function setting () {
		$setting = new stdClass();
		$setting->_datatypes = array( 'context' => 'string', 'type' => 'string',
									  'name' => 'string', 'value' => 'string',
									  'created' => 'date', 'modified' => 'date' );
		$setting->context = 'shopp';
		$setting->type = 'setting';
		$setting->name = null;
		$setting->value = null;
		$setting->created = null;
		$setting->modified = null;
		return $setting;
	}

	/**
	 * Automatically collect and save settings from a POST form
	 *
	 * @author Jonathan Davis
	 * @since 1.0
	 *
	 * @return void
	 **/
	public function saveform () {
		if ( empty($_POST['settings']) || ! is_array($_POST['settings']) ) return false;
		foreach ($_POST['settings'] as $setting => $value)
			$this->save($setting, $value);
	}

	/**
	 * Provides the installed database schema version from the database (if available)
	 *
	 * Queries the database to get the installed database version number. If not available,
	 * also checks the legacy
	 *
	 * @author Jonathan Davis
	 * @since 1.3
	 *
	 * @param string $legacy Set to anything but boolean false to attempt to lookup the version from the pre-1.2 settings table
	 * @return integer The installed database schema version number (0 means not installed)
	 **/
	public static function dbversion ( $legacy = false ) {

		$source = $legacy ? 'setting' : self::$table;
		$table = DatabaseObject::tablename($source);
		$version = DB::query("SELECT value FROM $table WHERE name='db_version'", 'col');

		// Try again using the legacy table
		if ( false === $version && false === $legacy ) $version = self::dbversion('legacy');

	 	ShoppSettings()->registry[$name] = (int)$version;
		return (int)$version;
	}

} // END class Settings