<?php
/**
 * Profiles a WordPress site
 *
 * @author Kurt Payne, GoDaddy.com
 * @version 1.0
 * @package WP_Profiler
 */
class wpp_profiler {

	/**
	 * Time spent in WordPress Core
	 * @var float
	 */
	private $_core = 0;

	/**
	 * Time spent in theme
	 * @var float
	 */
	private $_theme = 0;

	/**
	 * Time spent in the profiler code
	 * @var float
	 */
	private $_runtime = 0;

	/**
	 * Time spent in plugins
	 * @var float
	 */
	private $_plugin_runtime = 0;

	/**
	 * Profile information, built up during the application's execution
	 * @var array
	 */
	private $_profile = array();

	/**
	 * Stack trace of the last function call.  The stack is held here until
	 * it's recorded.  It's not recorded until it's been timed.  It won't be
	 * timed until after it's complete and the next function is in being
	 * examined, so the $_last_stack will be moved to $_profile and the current
	 * function will be moved to $_last_stack.
	 * @var array
	 */
	private $_last_stack = array();

	/**
	 * Time spent in last function call
	 * @var float
	 */
	private $_last_call_time = 0;

	/**
	 * Timestamp when the last function call was started
	 * @var float
	 */
	private $_last_call_start = 0;

	/**
	 * How to categorize the last call (core, theme, plugin)
	 * @var int
	 */
	private $_last_call_category = '';

	/**
	 * Where to save the profile when it's done
	 * @var string
	 */
	private $_profile_filename = '';

	/**
	 * App start time (as close as we can measure)
	 * @var float
	 */
	private $_start_time = 0;

	/**
	 * Path to ourselves
	 * @var string
	 */
	private $_wpp_path = ''; // Cannot rely on WPP_PATH, may be instantiated before the plugin

	/**
	 * Path to the ".profiling_enabled" flag file
	 * @var string
	 */
	private $_wpp_flag_file = '';

	/**
	 * Path to the "profiles" folder for saving the finished profile
	 * @var string
	 */
	private $_wpp_profiles_path = '';
	
	/**
	 * Last stack should be marked as plugin time
	 * @const
	 */
	const CATEGORY_PLUGIN = 1;
	
	/**
	 * Last stack should be marked as theme time
	 * @const
	 */
	const CATEGORY_THEME  = 2;

	/**
	 * Last stack should be marked as core time
	 * @const
	 */
	const CATEGORY_CORE   = 3;

	/**
	 * Constructor
	 * Initialize the object, figure out if profiling is enabled, and if so,
	 * start the profile.
	 * @return wpp_profiler
	 */
	public function __construct() {

		// Set up paths
		$this->_wpp_path = realpath(dirname(__FILE__));
		$this->_wpp_flag_file = $this->_wpp_path . DIRECTORY_SEPARATOR . '.profiling_enabled';
		$this->_wpp_profiles_path = $this->_wpp_path . DIRECTORY_SEPARATOR . 'profiles';

		// Check to see if we should profile
		$wpp_json = (file_exists($this->_wpp_flag_file) ? json_decode(file_get_contents($this->_wpp_flag_file)) : null);
		if (empty($wpp_json) || !preg_match('/' . preg_quote($wpp_json->ip) . '/', $this->get_ip())) {
			return $this;
		}

		// Kludge memory limit / time limit
		ini_set('memory_limit', '128M');
		set_time_limit(90);
		
		// Set the profile file
		$this->_profile_filename = $this->_wpp_profiles_path . DIRECTORY_SEPARATOR . $wpp_json->name . '.json';

		// Start timing
		$this->_start_time = microtime(true);
		$this->_last_call_start = microtime(true);

		// Reset state
		$this->_last_call_time = 0;
		$this->_runtime = 0;
		$this->_plugin_runtime = 0;
		$this->_core = 0;
		$this->_theme = 0;
		$this->_last_call_category = self::CATEGORY_CORE;
		$this->_last_stack = array();

		// Add a global flag to let everyone know we're profiling
		define('WPP_PROFILING_STARTED', true);

		// Add some startup information
		$this->_profile = array(
			'url'   => $this->_get_url(),
			'ip'    => (array_key_exists('HTTP_X_FORWARDED_FOR', $_SERVER) ? $_SERVER['HTTP_X_FORWARDED_FOR'] : $_SERVER['REMOTE_ADDR']),
			'pid'   => getmypid(),
			'date'  => @date('c'),
			'stack' => array()
		);

		// Monitor all function-calls
		declare(ticks=1);
		register_tick_function(array(&$this, 'tick_handler'));
	}
	
	/**
	 * In between every call, examine the stack trace time the calls, and record
	 * the calls if the operations went through a plugin
	 * @return void
	 */
	public function tick_handler() {
		static $theme_files_cache = array();      // Cache for theme files
		static $actions_hooked = false;

		// Start timing time spent in the profiler 
		$start = microtime(true);

		// Calculate the last call time
		$this->_last_call_time = ($start - $this->_last_call_start);

		// Don't profile in non-WP scripts
		if (!defined('WP_USE_THEMES') && !defined('DOING_CRON') && !defined('WP_ADMIN') && !$this->_is_a_plugin_file($_SERVER['SCRIPT_FILENAME'])) {
			$tmp = microtime(true);
			$this->_runtime += ($tmp - $start);
			$this->_last_call_start = $tmp;
			return;
		}

		// Hook actions
		if (!$actions_hooked && function_exists('add_action')) {

			// Hook the shutdown action to save the profile when we're done
			add_action('shutdown', array(&$this, 'shutdown_handler'));

			// Don't re-hook again
			$actions_hooked = true;
		}

		// If we had a stack in the queue, track the runtime, and write it to the log
		// array() !== $this->_last_stack is slightly faster than !empty($this->_last_stack)
		// which is important since this is called on every tick
		if (self::CATEGORY_PLUGIN == $this->_last_call_category && array() !== $this->_last_stack) {

			// Write the stack to the profile
			$this->_plugin_runtime += $this->_last_call_time;

			// Add this stack to the profile
			$this->_profile['stack'][] = array(
				'plugin'  => $this->_last_stack['plugin'],
				'runtime' => $this->_last_call_time
			);

			// Reset the stack
			$this->_last_stack = array();
		} elseif (self::CATEGORY_THEME == $this->_last_call_category) {
			$this->_theme += $this->_last_call_time;
		} elseif (self::CATEGORY_CORE == $this->_last_call_category) {
			$this->_core += $this->_last_call_time;
		}

		// Examine the current stack, see if we should track it.  It should be
		// related to a plugin file if we're going to track it
		if (defined('DEBUG_BACKTRACE_IGNORE_ARGS')) {
			$bt = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
		} else {
			$bt = debug_backtrace();
		}

		// Is it a plugin?
		$plugin = false;
		foreach ($bt as $frame) {
			if (!array_key_exists('file', $frame)) {
				continue; // eval'd code
			}
			if ($this->_is_a_plugin_file($frame['file'])) {
				$plugin = $this->_get_plugin_name($frame['file']);
				break;
			}
		}

		// Is it a theme?
		$is_a_theme = false;
		if (FALSE === $plugin) {
			foreach ($bt as $frame) {
				if (!isset($frame['file'])) {
					continue; // eval'd code
				}
				if (isset($theme_files_cache[$frame['file']])) {
					$is_a_theme = $theme_files_cache[$frame['file']];
					break;
				}

				$theme_files_cache[$frame['file']] = (
					(FALSE !== strpos($frame['file'], '/themes/') || FALSE !== strpos($frame['file'], '\\themes\\')) &&  // Most common filter
					(FALSE !== strpos($frame['file'], '/wp-content/') || FALSE !== strpos($frame['file'], '\\wp-content\\')) // Second most common filter
				);
				$theme_files_cache[$frame['file']];

				if ($theme_files_cache[$frame['file']]) {
					$is_a_theme = true;
					break;
				}
			}
		}

		// If we're in a plugin, queue up the stack to be timed and logged during the next tick
		if (count($bt) > 2 && FALSE !== $plugin) {

			$this->_last_stack = $bt;
			$this->_last_stack['plugin'] = $plugin;
			$this->_last_call_category = self::CATEGORY_PLUGIN;

		// Track theme times - code can travel from core -> theme -> plugin, and the whole trace
		// will show up in the stack, but we can only categorize it as one time, so we prioritize
		// timing plugins over themes, and thems over the core.
		} elseif (FALSE !== $is_a_theme) {
			$this->_last_call_category = self::CATEGORY_THEME;
		// We must be in the core
		} else {
			$this->_last_call_category = self::CATEGORY_CORE;
		}

		// Count the time spent in here as profiler runtime
		$tmp = microtime(true);
		$this->_runtime += ($tmp - $start);

		// Reset the timer for the next tick
		$this->_last_call_start = microtime(true);
	}

	/**
	 * Check if the given file is in the plugins folder
	 * @param string $file
	 * @return bool
	 */
	private function _is_a_plugin_file($file) {
		static $plugin_files_cache = array();
		if (isset($plugin_files_cache[$file])) {
			return $plugin_files_cache[$file];
		}

		$plugin_files_cache[$file] = (
			(
				(FALSE !== strpos($file, '/plugins/') || FALSE !== strpos($file, '\\plugins\\')) ||     // Plugins
				(FALSE !== strpos($file, '/mu-plugins/') || FALSE !== strpos($file, '\\mu-plugins\\'))  // Must-use plugins
			) &&
			(FALSE !== strpos($file, '/wp-content/') || FALSE !== strpos($file, '\\wp-content\\')) // Second most common filter
		);

		return $plugin_files_cache[$file];
	}

	/**
	 * Guess a plugin's name from the file path
	 * @param string $path
	 * @return string
	 */
	private function _get_plugin_name($path) {
		static $seen_files_cache = array();

		// Check the cache
		if (isset($seen_files_cache[$path])) {
			return $seen_files_cache[$path];
		}

		// Trim off the base path
		$_path = realpath($path);
		if (FALSE !== strpos($_path, '/wp-content/plugins/')) {
			$_path = substr($_path, strpos($_path, '/wp-content/plugins/') + strlen('/wp-content/plugins/'));
		} elseif (FALSE !== strpos($_path, '\\wp-content\\plugins\\')) {
			$_path = substr($_path, strpos($_path, '\\wp-content\\plugins\\') + strlen('\\wp-content\\plugins\\'));
		} elseif (FALSE !== strpos($_path, '/wp-content/mu-plugins/')) {
			$_path = substr($_path, strpos($_path, '/wp-content/mu-plugins/') + strlen('/wp-content/mu-plugins/'));
		} elseif (FALSE !== strpos($_path, '\\wp-content\\mu-plugins\\')) {
			$_path = substr($_path, strpos($_path, '\\wp-content\\mu-plugins\\') + strlen('\\wp-content\\mu-plugins\\'));
		}

		// Grab the plugin name as a folder or a file
		if (FALSE !== strpos($_path, DIRECTORY_SEPARATOR)) {
			$plugin = substr($_path, 0, strpos($_path, DIRECTORY_SEPARATOR));
		} else {
			$plugin = substr($_path, 0, stripos($_path, '.php'));
		}

		// Save it to the cache
		$seen_files_cache[$path] = $plugin;

		// Return
		return $plugin;
	}

	/**
	 * Shutdown handler function
	 * @return void
	 */
	public function shutdown_handler() {

		// Make sure we've actually started (wp-cron??)
		if (!defined('WPP_PROFILING_STARTED') || !WPP_PROFILING_STARTED) {
			return;
		}

		// Last call time
		$this->_last_call_time = (microtime(true) - $this->_last_call_start);

		// Account for the last stack we measured
		if (self::CATEGORY_PLUGIN == $this->_last_call_category && array() !== $this->_last_stack) {

			// Write the stack to the profile
			$this->_plugin_runtime += $this->_last_call_time;

			// Add this stack to the profile
			$this->_profile['stack'][] = array(
				'plugin'  => $this->_last_stack['plugin'],
				'runtime' => $this->_last_call_time
			);

			// Reset the stack
			$this->_last_stack = array();
		} elseif (self::CATEGORY_THEME == $this->_last_call_category) {
			$this->_theme += $this->_last_call_time;
		} elseif (self::CATEGORY_CORE == $this->_last_call_category) {
			$this->_core += $this->_last_call_time;
		}

		// Total runtime by plugin
		$plugin_totals = array();
		if (!empty($this->_profile['stack'])) {
			foreach ($this->_profile['stack'] as $stack) {
				if (empty($plugin_totals[$stack['plugin']])) {
					$plugin_totals[$stack['plugin']] = 0;
				}
				$plugin_totals[$stack['plugin']] += $stack['runtime'];
			}
		}
		foreach ($plugin_totals as $k => $v) {
			$plugin_totals[$k] = $v;
		}

		// Stop timing total run
		$tmp = microtime(true);
		$runtime = ($tmp - $this->_start_time);

		// Count the time spent in here as profiler runtime
		$this->_runtime += ($tmp - $this->_last_call_start);

		// Is the whole script a plugin? (e.g. http://mysite.com/wp-content/plugins/somescript.php)
		if ($this->_is_a_plugin_file($_SERVER['SCRIPT_FILENAME'])) {
			$this->_profile['runtime'] = array(
				'total'     => $runtime,
				'wordpress' => 0,
				'theme'     => 0,
				'plugins'   => ($runtime - $this->_runtime),
				'profile'   => $this->_runtime,
				'breakdown' => array(
					$this->_get_plugin_name($_SERVER['SCRIPT_FILENAME']) => ($runtime - $this->_runtime)
				)
			);
		} elseif (
			(FALSE !== strpos($_SERVER['SCRIPT_FILENAME'], '/themes/') || FALSE !== strpos($_SERVER['SCRIPT_FILENAME'], '\\themes\\')) &&  // Most common filter
			(FALSE !== strpos($_SERVER['SCRIPT_FILENAME'], '/wp-content/') || FALSE !== strpos($file, '\\wp-content\\')) // Second most common filter
			) {
			$this->_profile['runtime'] = array(
				'total'     => $runtime,
				'wordpress' => 0.0,
				'theme'     => ($runtime - $this->_runtime),
				'plugins'   => 0.0,
				'profile'   => $this->_runtime,
				'breakdown' => array()
			);
		} else {
			// Add runtime information
			$this->_profile['runtime'] = array(
				'total'     => $runtime,
				'wordpress' => $this->_core,
				'theme'     => $this->_theme,
				'plugins'   => $this->_plugin_runtime,
				'profile'   => $this->_runtime,
				'breakdown' => $plugin_totals
			);
		}

		// Additional metrics
		$this->_profile['memory'] = memory_get_peak_usage(true);
		$this->_profile['stacksize'] = count($this->_profile['stack']);
		$this->_profile['queries'] = get_num_queries();

		// Throw away unneeded information to make the profiles smaller
		unset($this->_profile['stack']);

		// Open the file and acquire an exclusive lock (prevent multiple hits from stomping on our
		// previous profiles
		$fp = fopen($this->_profile_filename, 'a+');
		$wait = 30; // Wait 30 iterations (3 seconds)
		while (!flock($fp, LOCK_EX) && $wait--) {
			usleep(100 * 1000);
		}

		// If we've waited too long, bail, don't add this profile, there's too
		// much traffic
		if ($wait <= 0) {
			return;
		}

		fwrite($fp, json_encode($this->_profile) . PHP_EOL);

		// Release the lock and close the file
		flock($fp, LOCK_UN);
		fclose($fp);
	}
	
	/**
	 * Get the current URL
	 * @return string
	 */
	private function _get_url() {
		$protocol = 'http://';
		if ((!empty($_SERVER['HTTPS']) && 'on' == strtolower($_SERVER['HTTPS'])) || 443 == $_SERVER['SERVER_PORT']) {
			$protocol = 'https://';
		}
		$domain = $_SERVER['HTTP_HOST'];
		if (!empty($_SERVER['REQUEST_URI'])) {
			$file = '';
			$query_string = '';
			$path = $_SERVER['REQUEST_URI'];
		} else {
			$file = '';
			if (!empty($_SERVER['SCRIPT_NAME'])) {
				$file = $_SERVER['SCRIPT_NAME'];
			}
			$path = '';
			if (!empty($_SERVER['PATH_INFO'])) {
				$path = $_SERVER['PATH_INFO'];
			} elseif (!empty($_SERVER['REDIRECT_URL'])) {
				$path = $_SERVER['REDIRECT_URL'];
			}
			$query_string = '';
			if (!empty($_SERVER['QUERY_STRING'])) {
				$query_string = '?' . $_SERVER['QUERY_STRING'];
			}
		}
		return $protocol.$domain.$file.$path.$query_string;
	}
	
	/**
	 * Get the user's IP
	 * @return string
	 */
	public function get_ip() {
		static $ip = '';
		if (!empty($ip)) {
			return $ip;
		} else {
			if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
				$ip = filter_var($_SERVER['HTTP_X_FORWARDED_FOR'], FILTER_SANITIZE_STRING);
			} else {
				$ip = filter_var($_SERVER['REMOTE_ADDR'], FILTER_SANITIZE_STRING);
			}
			return $ip;
		}
	}
}
