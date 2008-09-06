<?php

/* functions.php
 * Library of global utility functions */

/**
 * Calculate the time based on a repeating interval in a given 
 * month and year. Ex: Fourth Thursday in November (Thanksgiving). */
function datecalc($week=-1,$dayOfWeek=-1,$month=-1,$year=-1) {
	$weekdays = array("sunday" => 0, "monday" => 1, "tuesday" => 2, "wednesday" => 3, "thursday" => 4, "friday" => 5, "saturday" => 6);
	$weeks = array("first" => 1, "second" => 2, "third" => 3, "fourth" => 4, "last" => -1);

	if ($month == -1) $month = date ("n");	// No month provided, use current month
	if ($year == -1) $year = date("Y");   	// No year provided, use current year

	// Day of week is a string, look it up in the weekdays list
	if (!is_numeric($dayOfWeek)) {
		foreach ($weekdays as $dayName => $dayNum) {
			if (strtolower($dayOfWeek) == substr($dayName,0,strlen($dayOfWeek))) {
				$dayOfWeek = $dayNum;
				break;
			}
		}
	}
	if ($dayOfWeek < 0 || $dayOfWeek > 6) return false;
	
	if (!is_numeric($week)) $week = $weeks[$week];	
	
	if ($week == -1) {
		$lastday = date("t", mktime(0,0,0,$month,1,$year));
		$tmp = (date("w",mktime(0,0,0,$month,$lastday,$year)) - $dayOfWeek) % 7;
		if ($tmp < 0) $tmp += 7;
		$day = $lastday - $tmp;
	} else {
		$tmp = ($dayOfWeek - date("w",mktime(0,0,0,$month,1,$year))) % 7;
		if ($tmp < 0) $tmp += 7;
		$day = (7 * $week) - 6 + $tmp;
	}
	
	return mktime(0,0,0,$month,$day,$year);
}

/**
 * Converts a datetime value from a MySQL datetime format to a Unix timestamp. */
function mktimestamp ($datetime) {
	$h = $mn = $s = 0;
	list($Y, $M, $D, $h, $mn, $s) = sscanf($datetime,"%d-%d-%d %d:%d:%d");
	return mktime($h, $mn, $s, $M, $D, $Y);
}

/**
 * Converts a Unix timestamp value to a datetime format suitable for entry in a
 * MySQL record. */
function mkdatetime ($timestamp) {
	return date("Y-m-d H:i:s",$timestamp);
}

/**
 * Returns the corresponding 24-hour $hour based on a 12-hour based $hour
 * and the AM (Ante Meridiem) / PM (Post Meridiem) $meridiem. */
function mk24hour ($hour, $meridiem) {
	if ($hour < 12 && $meridiem == "PM") return $hour + 12;
	if ($hour == 12 && $meridiem == "AM") return 0;
	return $hour;
}

/**
 * Returns a string of the number of years, months, days, hours, 
 * minutes and even seconds from a specified date ($date). */
function readableTime($date, $long = false) {

	$secs = time() - $date;
	if (!$secs) return false;
	$i = 0; $j = 1;
	$desc = array(1 => 'second',
				  60 => 'minute',
				  3600 => 'hour',
				  86400 => 'day',

				  604800 => 'week',
				  2628000 => 'month',
				  31536000 => 'year');


	while (list($k,) = each($desc)) $breaks[] = $k;
	sort($breaks);

	while ($i < count($breaks) && $secs >= $breaks[$i]) $i++;
	$i--;
	$break = $breaks[$i];

	$val = intval($secs / $break);
	$retval = $val . ' ' . $desc[$break] . ($val>1?'s':'');

	if ($long && $i > 0) {
		$rest = $secs % $break;
		$break = $breaks[--$i];
		$rest = intval($rest/$break);

		if ($rest > 0) {
			$resttime = $rest.' '.$desc[$break].($rest > 1?'s':'');

			$retval .= ", $resttime";
		}
	}

	return $retval;
}

function duration ($start,$end) {
	return ceil(($end - $start) / 86400);
}

/** 
 * Sends an e-mail message in the format of a specified e-mail 
 * template ($template) file providing variable substitution 
 * for variables appearing in the template as a bracketed
 * [variable] with data from the coinciding $data['variable']
 * or $_POST['variable'] */
function shopp_email ($template,$data=array()) {
	
	if ( file_exists($template) ) $f = file($template);
	else die("Could not open the email template because the file does not exist or is not readable. ($template)");
	$debug = false;
	$in_body = false;
	$headers = "";
	$message = "";
	$protected = array("from","to","subject","cc","bcc");
	while ( list($linenum,$line) = each($f) ) {
		// Data parse
		if ( preg_match_all("/\[(.+?)\]/",$line,$labels,PREG_SET_ORDER) ) {
			while ( list($i,$label) = each($labels) ) {
				$code = $label[1];
				if (empty($data)) $string = $_POST[$code];
				else $string = $data[$code];
				$string = str_replace("$","\\\$",$string); // Treat $ signs as literals
				if (isset($string) && !is_array($string)) $line = preg_replace("/\[".$code."\]/",$string,$line);
			}
		}

		// Header parse
		if ( preg_match("/^(.+?):\s(.+)\n$/",$line,$found) && !$in_body ) {
			$header = $found[1];
			$string = $found[2];
			if (in_array(strtolower($header),$protected)) // Protect against header injection
				$string = str_replace(array("\r","\n"),"",urldecode($string));
			if ( strtolower($header) == "to" ) $to = $string;
			else if ( strtolower($header) == "subject" ) $subject = $string;
			else $headers .= $line;
		}
		
		// Catches the first blank line to begin capturing message body
		if ( $line == "\n" ) $in_body = true;
		if ( $in_body ) $message .= $line;
	}

	if (!$debug) mail($to,$subject,$message,$headers);
	else {
		echo "TO: $to<BR>SUBJECT: $subject<BR>MESSAGE:<BR>$message<BR><BR>HEADERS:<BR>";
		print_r($headers);
		exit();		
	}
}

/**
 * Generates an RSS-compliant string from an associative 
 * array ($data) with a specific RSS-structure. */
function build_rss ($data) {
	$xml = "";
	$xml .= "<?xml version=\"1.0\""."?".">\n";
	$xml .= "<rss version=\"2.0\">\n";
	$xml .= "<channel>\n";
	
	$xml .= "<title>".$data['title']."</title>\n";
	$xml .= "<description>".$data['description']."</description>\n";
	$xml .= "<link>".$data['link']."</link>\n";
	$xml .= "<language>en-us</language>\n";
	$xml .= "<copyright>Copyright ".date('Y').", gochampaign.com</copyright>\n";
	
	foreach($data['items'] as $item) {
		$xml .= "<item>\n";
		$xml .= "<title>".$item['title']."</title>\n";
		$xml .= "<description>".$item['description']."</description>\n";
		$xml .= "<link>".$item['link']."</link>\n";
		$xml .= "<pubDate>".$item['pubDate']."</pubDate>\n";
		$xml .= "</item>\n";
	}
	
	$xml .= "</channel>\n";
	$xml .= "</rss>\n";
	
	return $xml;
}

/**
 * Formats a number into a standardized telephone number format */
function phone ($num) {
	if (empty($num)) return "";
	$num = preg_replace("/[A-Za-z\-\s\(\)]/","",$num);
	
	if (strlen($num) == 7) sscanf($num, "%3s%4s", $prefix, $exchange);
	if (strlen($num) == 10) sscanf($num, "%3s%3s%4s", $area, $prefix, $exchange);
	if (strlen($num) == 11) sscanf($num, "%1s%3s%3s%4s",$country, $area, $prefix, $exchange);
	//if (strlen($num) > 11) sscanf($num, "%3s%3s%4s%s", $area, $prefix, $exchange, $ext);
	
	$string = "";
	$string .= (isset($country))?"$country ":"";
	$string .= (isset($area))?"($area) ":"";
	$string .= (isset($prefix))?$prefix:"";
	$string .= (isset($exchange))?"-$exchange":"";
	$string .= (isset($ext))?" x$ext":"";
	return $string;

}

/**
 * Determines if the current client is a known web crawler bot */
function is_robot() {
	$bots = array("Googlebot","TeomaAgent","Zyborg","Gulliver","Architext spider","FAST-WebCrawler","Slurp","Ask Jeeves","ia_archiver","Scooter","Mercator","crawler@fast","Crawler","InfoSeek sidewinder","Lycos_Spider_(T-Rex)","Fluffy the Spider","Ultraseek","MantraAgent","Moget","MuscatFerret","VoilaBot","Sleek Spider","KIT_Fireball","WebCrawler");
	foreach($bots as $bot) {
		if (strpos(strtolower($_SERVER['HTTP_USER_AGENT']),strtolower($bot))) return true;
	}
	return false;
}

function shopp_prereqs () {
	$errors = array();
	// Check PHP version
	if (!version_compare(PHP_VERSION, '5.0.0', '>')) 
		$errors[] = "Shopp requires PHP version 5+.  You are using PHP version ".PHP_VERSION;
		
	// Check WordPress version
	if (!version_compare(get_bloginfo('version'),'2.4.0','>'))
		$errors[] = "Shopp requires WordPress version 2.5+.  You are using WordPress version ".get_bloginfo('version');
	
	// Check for cURL
	if( !function_exists("curl_init") &&
	      !function_exists("curl_setopt") &&
	      !function_exists("curl_exec") &&
	      !function_exists("curl_close") ) $errors[] = "Shopp requires the cURL library for processing transactions securely. Your web hosting environment does not currently have cURL installed (or built into PHP).";
	
	// Check for GD
	if (!function_exists("gd_info")) $errors[] = "Shopp requires the GD image library with JPEG support for generating gallery and thumbnail images.  Your web hosting environment does not currently have GD installed (or built into PHP).";
	else {
		$gd = gd_info();
		if (!$gd['JPG Support']) $errors[] = "Shopp requires JPEG support in the GD image library.  Your web hosting environment does not currently have a version of GD installed that has JPEG support.";
	}
	
	if (!empty($errors)) {
		foreach ($errors as $error) {
			echo '<style type="text/css">body { font: 13px "Lucida Grande", "Lucida Sans Unicode", Tahoma, Verdana, sans-serif;
			}</style>';
			echo "<p>$error</p>";
		}
		echo '<p>Sorry! You will not be able to use Shopp.  For more information, see the <a href="http://docs.shopplugin.net/Installation">online Shopp documentation.</p>';
		exit();
	}
	return true;
}


function shopp_debug ($object) {
	global $Shopp;
	ob_start();
	print_r($object);
	$result = ob_get_contents();
	ob_end_clean();
	$Shopp->_debug->objects .= "<br/><br/>".str_replace("\n","<br/>",$result);
}

function get_filemeta ($file) {
	if (!file_exists($file)) return false;
	if (!is_readable($file)) return false;

	$meta = false;
	$string = "";
	
	$f = @fopen($file, "r");
	if (!$f) return false;
	while (!feof($f)) {
		$buffer = fgets($f,80);
		if (preg_match("/\/\*/",$buffer)) $meta = true;
		if ($meta) $string .= $buffer;
		if (preg_match("/\*\//",$buffer)) break;
	}
	fclose($f);

	return $string;
}

/**
 * Recursively searches directories and one-level deep of 
 * sub-directories for files with a specific extension
 * NOTE: Files are saved to the $found parameter, 
 * an array passed by reference, not a returned value */
function find_files ($extension, $directory, $root, &$found) {
	if (is_dir($directory)) {
		$Directory = @dir($directory);
		if ($Directory) {
			while (( $file = $Directory->read() ) !== false) {
				if (substr($file, 0, 1) == ".") continue; 					 // Ignore .dot files
				if (is_dir("$directory/$file") && $directory == $root)		 // Scan one deep more than root
					find_files($extension,"$directory/$file",$root, $found); // but avoid recursive scans
				if (substr($file,strlen($extension)*-1) == $extension)
					$found[] = substr($directory,strlen($root))."/$file";	 // Add the file to the found list
			}
			return true;
		}
	}
	return false;
}

/**
 * List files and directories inside the specified path */
if(!function_exists('scandir')) {
	function scandir($dir, $sortorder = 0) {
		if(is_dir($dir) && $dirlist = @opendir($dir)) {
			$files = array();
			while(($file = readdir($dirlist)) !== false) $files[] = $file;
			closedir($dirlist);
			($sortorder == 0) ? asort($files) : rsort($files);
			return $files;
		} else return false;
	}
}

function filter_dotfiles ($name) {
	return (substr($name,0,1) != ".");
}

/**
 * Checks an object for a declared property
 * if() checks to see if the function is already available (as in PHP 5) */
if (!function_exists('property_exists')) {
	function property_exists($object, $property) {
		return array_key_exists($property, get_object_vars($object));
	}
}

/**
 * sort_tree
 * Sorts a heirarchical tree of data */
function sort_tree ($items,$parent=0,$key=-1,$depth=-1) {
	$depth++;
	$result = array();
	if ($items) { 
		foreach ($items as $item) {
			if ($item->parent == $parent) {
				$item->parentkey = $key;
				$item->depth = $depth;
				$result[] = $item;
				$children = sort_tree($items, $item->id, count($result)-1, $depth);
				$result = array_merge($result,$children); // Add children in as they are found
			}
		}
	}
	$depth--;
	return $result;
}

/**
 * file_mimetype
 * Tries a variety of methods to determine a file's mimetype */
function file_mimetype ($file) {
	if (function_exists('finfo_open')) {
		// Try using PECL module
		$f = finfo_open(FILEINFO_MIME);
		$mime = finfo_file($f, $file);
		finfo_close($f);
		return $mime;
	} elseif (class_exists('finfo')) {
		// Or class
		$f = new finfo(FILEINFO_MIME);
		return $f->file($file);
	} elseif (strlen($mime=@shell_exec("file -bI ".escapeshellarg($file)))!=0) {
		// Use shell if allowed
		return trim($mime);
	} elseif (strlen($mime=@shell_exec("file -bi ".escapeshellarg($file)))!=0) {
		// Use shell if allowed
		return trim($mime);
	} elseif (function_exists('mime_content_type')) {
		// Try with magic-mime if available
		return mime_content_type($file);
	}
}

/**
 * Returns a list marked-up as drop-down menu options */
function menuoptions ($list,$selected=null,$values=false) {
	if (!is_array($list)) return "";
	$string = "";
	foreach ($list as $value => $text) {
		if ($values) {
			if ($value == $selected) $string .= "<option value=\"$value\" selected=\"selected\">$text</option>";
			else  $string .= "<option value=\"$value\">$text</option>";
		} else {
			if ($text == $selected) $string .= "<option selected=\"selected\">$text</option>";
			else  $string .= "<option>$text</option>";
		}
	}
	return $string;
}

function scan_money_format ($format) {
	$f = array(
		"cpos" => true,
		"currency" => "",
		"precision" => 0,
		"decimals" => "",
		"thousands" => ""
	);
	
	$ds = strpos($format,'#'); $de = strrpos($format,'#')+1;
	$df = substr($format,$ds,($de-$ds));
	
	$f['cpos'] = true;
	if ($de == strlen($format)) $f['currency'] = substr($format,0,$ds);
	else {
		$f['currency'] = substr($format,$de);
		$f['cpos'] = false;
	}

	$dd = 0;
	$dl = array();
	while($i < strlen($df)) {
		$c = substr($df,$i++,1);
		if ($c != "#") {
			$dl[] = $c;
			$dd = 0;
		} else $dd++;
	}
	$f['precision'] = $dd;
	
	if (isset($dl[1])) {
		$f['decimals'] = $dl[1];
		$f['thousands'] = $dl[0];
	} else $f['decimals'] = $dl[0];

	return $f;
}

function money ($amount,$format=false) {
	global $Shopp;
	
	$locale = $Shopp->Settings->get('base_operations');
	if (!$format) $format = $locale['currency']['format'];
	if (!$format) $format = array("cpos"=>true,"currency"=>"$","precision"=>2,"decimals"=>".","thousands" => ",");
	
	$number = number_format($amount, $format['precision'], $format['decimals'], $format['thousands']);
	if ($format['cpos']) return $format['currency'].$number;
	else return $number.$format['currency'];
}

function value_is_true ($value) {
	switch (strtolower($value)) {
		case "yes": case "true": case "1": case "on": return true;
		default: return false;
	}
}

function valid_input ($type) {
	$inputs = array("text","hidden","checkbox","radio","button","submit");
	if (in_array($type,$inputs) !== false) return true;
	return false;
}

function build_query_request ($request=array()) {
	$query = "";
	foreach ($request as $name => $value) {
		if (strlen($query) > 0) $query .= "&";
		$query .= "$name=$value";
	}
	return $query;
}


class FTPClient {
	var $connected = false;
	
	function FTPClient ($host, $user, $password) {
		$this->connect($host, $user, $password);
		if ($this->connected) ftp_pasv($this->connection,true);
		else return false;
		return true;
	}
	
	/** 
	 * Connects to the FTP server */
	function connect($host, $user, $password) {
		$this->connection = @ftp_connect($host);
		$this->connected = @ftp_login($this->connection,$user,$password);
		if (!$this->connected) return false;
		return true;
	}
	
	/**
	 * update()
	 * Recursively copies files from a src $path to the $remote path */
	function update ($path,$remote) {
		$path = trailingslashit($path);
		$remote = trailingslashit($remote);
		$remote = $this->remappath($remote);
		
		$files = scandir($path);
		$excludes = array(".","..");
		foreach ($files as $file) {
			if (in_array($file,$excludes)) continue;
			if (is_dir($path.$file)) {
				if (@ftp_chdir($this->connection,$remote.$file)) {
					$this->update($path.$file,$remote.$file);
				} else $this->mkdir($remote.$file);
			} else $this->put($path.$file,$remote.$file);
		}
	}
	
	/**
	 * put()
	 * Copies the target file to the remote location */
	function put ($file,$remote) {
		if (ftp_put($this->connection,$remote,$file,FTP_BINARY))
			return ftp_chmod($this->connection, 0644, $remote);
	}
	
	/**
	 * mkdir()
	 * Makes a new remote directory with correct permissions */
	function mkdir ($path) {
		if (ftp_mkdir($this->connection,$path)) 
			ftp_chmod($this->connection,0755,$path);
		else return false;
		return true;
	}
	
	/**
	 * mkdir()
	 * Gets the current directory */
	function pwd () {
		return ftp_pwd($this->connection);
	}
	
	/**
	 * scan()
	 * Gets a list of files in a directory/current directory */
	function scan ($path=false) {
		if (!$path) $path = $this->pwd();
		return ftp_nlist($this->connection,$path);
	}
	
	/**
	 * isdir()
	 * Determines if the file is a directory or a file */
	function isdir ($file=false) {
		if (!$file) $file = $this->pwd();
	    if (ftp_size($this->connection, $file) == '-1')
	        return true; // Directory
	    else return false; // File
	}
	
	/**
	 * remappath()
	 * Remap a given path to the root path of the FTP server 
	 * to take into account root jails common in FTP setups */
	function remappath ($path) {
		$files = $this->scan();
		foreach ($files as $file) {
			$filepath = $this->pwd().$file;
			if (!$this->isdir($this->pwd().$file)) continue;
			$index = strrpos($path,$filepath);
			if ($index != -1) return substr($path,$index);
		}
	}

}

shopp_prereqs();  // Run by default at include

?>
