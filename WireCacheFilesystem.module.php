<?php namespace ProcessWire;

/**
 * Database cache handler for WireCache
 *
 * ProcessWire 3.x, Copyright 2023 by Ryan Cramer
 * https://processwire.com
 *
 * @since 2.0.218
 *
 */
class WireCacheFilesystem extends WireData implements Module, WireCacheInterface {
	
	const extension = 'cache';

	/**
	 * Cached filesystem path
	 * 
	 * @var string|null 
	 * 
	 */
	protected $path = null;

	/**
	 * Module init
	 * 
	 */
	public function init() {
		$cache = $this->wire()->cache;
		if($cache) $cache->setCacheModule($this); // set this module as the cache handler
	}

	/**
	 * Get the filesystem path to use for cache files
	 * 
	 * #pw-internal
	 * 
	 * @param bool $create Create if not exists? (default=true)
	 * @return string
	 * 
	 */
	public function path($create = true) {
		if($this->path === null) {
			$this->path = $this->wire()->config->paths->cache . 'WireCache/';
			if($create && !is_dir($this->path)) $this->wire()->files->mkdir($this->path);
		}
		return $this->path;
	}

	/**
	 * Given cache name return filename (full path)
	 * 
	 * #pw-internal
	 * 
	 * @param string $name
	 * @return string
	 * 
	 */
	public function filename($name) {
		$name = (string) $name;
		while(strpos($name, '..') !== false) $name = str_replace('..', '.', $name);
		if(strpos($name, '/') !== false) $name = str_replace('/', '_', $name);
		if(!ctype_alnum(str_replace(array('-', '_', '.'), '', $name))) {
			$name = $this->wire()->sanitizer->name($name, Sanitizer::translate, 191, '_', array(
				'allowAdjacentExtras' => true, 
				'allowDoubledReplacement' => true, 
			));
			$name = str_replace('..', '', $name);
		} else if(strlen($name) > 191) {
			$name = substr($name, 0, 191);
		}
		
		$name = trim($name, '.-_');
		$filename = $this->path() . "$name." . self::extension;
		
		return $filename;
	}

	/**
	 * Find caches by names or expirations and return requested values
	 *
	 * @param array $options
	 *  - `get` (array): Properties to get in return value, one or more of [ `name`, `expires`, `data`, `size` ] (default=all)
	 *  - `names` (array): Names of caches to find (OR condition), optionally appended with wildcard `*`.
	 *  - `expires` (array): Expirations of caches to match in ISO-8601 date format, prefixed with operator and space (see expiresMode mode below).
	 *  - `expiresMode` (string): Whether it should match any one condition 'OR', or all conditions 'AND' (default='OR')
	 * @return array Returns array of associative arrays, each containing requested properties
	 *
	 */
	public function find(array $options) {
		
		$defaults = array(
			'names' => array(),
			'expires' => array(),
			'expiresMode' => 'OR',
			'get' => array('name', 'expires', 'data'),
		);
	
		$options = array_merge($defaults, $options);
		$expires = array();
		$results = array();
		$get = $options['get']; 
		$expiresMode = $options['expiresMode'];
		$names = $options['names'];
		
		// $options['expire'] is an array of strings prefixed with operator and space
		// example: [ '<= 2023-03-08 03:00:01', '> 2024-06-14 06:52:00' ]
		// Below we convert it to arrays, i.e. [ [ '<=', 1233456789 ], [ '>', 987654321 ] ]
		// this is so that we don't have to do string parsing for every file
		
		foreach($options['expires'] as $expire) {
			$operator = '=';
			if(strpos($expire, ' ')) list($operator, $expire) = explode(' ', $expire, 2);
			$expires[] = array($operator, strtotime($expire));
		}

		if(count($names)) {
			// attempt to get directly by name when possible
			foreach($names as $key => $name) {
				if(strpos($name, '*') !== false) continue; // skip wildcards
				$filename = $this->filename($name);	
				if(file_exists($filename)) {
					$result = $this->fileMatches($filename, $get, $names, $expires, $expiresMode);
					if($result) $results[] = $result;
				}
				unset($names[$key]);
			}
			// exit now if there's nothing left to get (i.e. no wildcards)
			if(!count($names)) return $results;
		}

		// iterate through all cache files to find by wildcards in name and/or expires
		foreach(new \DirectoryIterator($this->path()) as $file) {
			if($file->isDot() || $file->isDir()) continue;
			if($file->getExtension() != self::extension) continue;
			$result = $this->fileMatches($file->getPathname(), $get, $names, $expires, $expiresMode);
			if($result !== false) $results[] = $result;
		}
		
		return $results;
	}

	/**
	 * Does file match requested names and expires? Returns result array when yes
	 * 
	 * @param string $filename
	 * @param array $get Names of properties to get [ 'name', 'expires', 'data' ]
	 * @param array $names Names of caches to get, optionally appended with wildcard*
	 * @param array $expires Expires conditions to match
	 * @param string $expiresMode One of 'OR' or 'AND'
	 * @return array|false Returns result array when file matches, false when it doesn't
	 * 
	 */
	protected function fileMatches($filename, array $get, array $names, array $expires, $expiresMode) {
		$basename = basename($filename, '.' . self::extension);
		if(count($names)) {
			// check if file matches requested names or wildcards
			if(!$this->nameMatchesNames($basename, $names)) return false;
		}
		if(count($expires)) {
			// check if file matches requested expirations
			if(!$this->timeMatchesExpires(filemtime($filename), $expires, $expiresMode)) return false;
		}
		// at this point the file matches
		$result = array();
		foreach($get as $property) {
			switch($property) {
				case 'name': $result['name'] = $basename; break;
				case 'expires': $result['expires'] = date('Y-m-d H:i:s', filemtime($filename)); break;
				case 'data': $result['data'] = $this->wire()->files->fileGetContents($filename); break;
				case 'size': $result['size'] = filesize($filename); break;
			}
		}
		return $result;
	}

	/**
	 * Does given name match the names in given array?
	 * 
	 * @param string $name
	 * @param array $names
	 * @return bool
	 * 
	 */
	protected function nameMatchesNames($name, array $names) {
		$match = false;
		foreach($names as $matchName) {
			if(strpos($matchName, '*')) {
				$matchName = rtrim($matchName, '*');
				$match = strpos($name, $matchName) === 0;
			} else {
				$match = $name === $matchName;

			}
			if($match) break;
		}
		return $match;
	}

	/**
	 * Does given time fall within the given expires conditions?
	 * 
	 * @param int $time
	 * @param array $expires
	 * @param string $expiresMode
	 * @return bool
	 * 
	 */
	protected function timeMatchesExpires($time, array $expires, $expiresMode) {
		$numMatches = 0;
		foreach($expires as $expire) {
			list($operator, $expireTime) = $expire;
			if($this->timeMatchesExpire($time, $operator, $expireTime)) {
				$numMatches++;
				if($expiresMode === 'OR') break;
			}
		}
		return ($expiresMode === 'AND' ? $numMatches === count($expires) : $numMatches > 0);
	}

	/**
	 * Does given time match the given operator and expireTime?
	 * 
	 * @param int $time
	 * @param string $operator
	 * @param int $expireTime
	 * @return bool
	 * 
	 */
	protected function timeMatchesExpire($time, $operator, $expireTime) {
		switch($operator) {
			case '=': return $expireTime == $time;
			case '>': return $time > $expireTime;
			case '<': return $time < $expireTime;
			case '<=': return $time <= $expireTime;
			case '>=': return $time >= $expireTime;
		}
		return false;
	}

	/**
	 * Save a cache
	 *
	 * @param string $name
	 * @param string $data
	 * @param string $expire
	 * @return bool
	 *
	 */
	public function save($name, $data, $expire) {
		$filename = $this->filename($name);
		if($this->wire()->files->filePutContents($filename, $data, LOCK_EX)) {
			touch($filename, strtotime($expire));
			return true;
		}
		return false;
	}

	/**
	 * Delete a cache by name
	 *
	 * @param string $name
	 * @return bool
	 *
	 */
	public function delete($name) {
		$filename = $this->filename($name);
		if(file_exists($filename)) $this->wire()->files->unlink($filename);
		return true;
	}

	/**
	 * Delete all caches
	 *
	 * @return int
	 *
	 */
	public function deleteAll() {
		return $this->_deleteAll(false);
	}

	/**
	 * Expire all caches
	 *
	 * @return int
	 *
	 */
	public function expireAll() {
		return $this->_deleteAll(true);
	}
	
	/**
	 * Implementation for deleteAll and expireAll methods
	 *
	 * @param bool|null $expireAll 
	 *  - `null` to delete without exception 
	 *  - `true` to delete all except those that weren’t saved with expiration dates
	 *  - `false` to delete all except those with WireCache::expireReserved 
	 * @return int
	 * @throws WireException
	 *
	 */
	protected function _deleteAll($expireAll = null) {
		$files = $this->wire()->files;
		$never = strtotime(WireCache::expireNever);
		$reserved = strtotime(WireCache::expireReserved);
		$qty = 0;
		foreach(new \DirectoryIterator($this->path()) as $file) {
			if($file->isDot() || $file->isDir()) continue;
			if($file->getExtension() != self::extension) continue;
			$mtime = $file->getMTime();
			if($expireAll && $mtime <= $never) continue;
			if(!$expireAll && $mtime == $reserved) continue;
			if($files->unlink($file->getPathname())) $qty++;
		}
		return $qty;
	}
	
	public function ___install() {
		$this->path(true);
	}
	
	public function ___uninstall() {
		$path = $this->path(false);
		if(is_dir($path)) {
			$this->wire()->files->rmdir($path, true);
		}
	}
}