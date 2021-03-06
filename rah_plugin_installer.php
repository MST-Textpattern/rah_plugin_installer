<?php

/**
 * Rah_plugin_installer plugin for Textpattern CMS
 *
 * @author Jukka Svahn
 * @date 2008-
 * @license GNU GPLv2
 * @link http://rahforum.biz/plugins/rah_plugin_installer
 *
 * Copyright (C) 2012 Jukka Svahn <http://rahforum.biz>
 * Licensed under GNU Genral Public License version 2
 * http://www.gnu.org/licenses/gpl-2.0.html
 */

	if(@txpinterface == 'admin') {
		add_privs('rah_plugin_installer', '1');
		add_privs('plugin_prefs.rah_plugin_installer', '1');
		register_tab('extensions', 'rah_plugin_installer', gTxt('rah_plugin_installer'));
		register_callback(array('rah_plugin_installer', 'head'), 'admin_side', 'head_end');
		register_callback(array('rah_plugin_installer', 'panes'), 'rah_plugin_installer');
		register_callback(array('rah_plugin_installer', 'prefs'), 'plugin_prefs.rah_plugin_installer');
		register_callback(array('rah_plugin_installer', 'install'), 'plugin_lifecycle.rah_plugin_installer');
	}

class rah_plugin_installer {

	static public $version = '0.4';

	public $message = array();
	
	private $cache_duration = 64800;
	private $timestamp;
	private $installed = array();

	/**
	 * Installs
	 * @param string $event Admin-side event.
	 * @param string $step Admin-side, plugin-lifecycle step.
	 */

	static public function install($event='', $step='') {
		
		global $prefs;
		
		if($step == 'deleted') {
			
			@safe_query(
				'DROP TABLE IF EXISTS '.safe_pfx('rah_plugin_installer_def')
			);
			
			safe_delete(
				'txp_prefs',
				"name like 'rah\_plugin\_installer\_%'"
			);
			
			return;
		}
		
		$current = isset($prefs['rah_plugin_installer_version']) ?
			$prefs['rah_plugin_installer_version'] : '';
		
		if($current == self::$version)
			return;
		
		if(!$current)
			@safe_query('DROP TABLE IF EXISTS '.safe_pfx('rah_plugin_installer'));
		
		/*
			Stores plugin's version and update details.
			
			* name: Plugin's name. Primary key.
			* author: Author's name.
			* author_uri: Author's website address.
			* version: Plugin's latest version number.
			* description: Plugin's description.
			* help: Cached help file.
			* type: Plugin's type.
			* md5_checksum: MD5 checksum for installer file.
		*/
		
		safe_query(
			"CREATE TABLE IF NOT EXISTS ".safe_pfx('rah_plugin_installer_def')." (
				`name` varchar(64) NOT NULL default '',
				`author` varchar(128) NOT NULL default '',
				`author_uri` varchar(128) NOT NULL default '',
				`version` varchar(10) NOT NULL default '1.0',
				`description` text NOT NULL,
				`help` text NOT NULL,
				`type` int(2) NOT NULL default 0,
				`md5_checksum` varchar(32) NOT NULL default '',
				PRIMARY KEY(`name`)
			) PACK_KEYS=1 CHARSET=utf8"
		);
		
		/*
			Add preferences strings
		*/
		
		foreach(
			array(
				'version' => self::$version,
				'updated' => 0,
				'checksum' => ''
			) as $name => $value
		) {
			if(!isset($prefs['rah_plugin_installer_'.$name]) || $name == 'version') {
				set_pref('rah_plugin_installer_'.$name,$value,'rah_pins',2,'',0);
				$prefs['rah_plugin_installer_'.$name] = $value;
			}
		}
	}

	/**
	 * Constructor
	 */
	
	public function __construct() {
		
		self::install();
		
		$this->timestamp = @safe_strtotime('now');
		
		if(function_exists('curl_init') && !@ini_get('allow_url_fopen')) {
			$this->message[] = 'open_ports_or_install_curl';
		}
		
		if(($updates = $this->check_updates()) && $updates) {
			$this->message[] = $updates;
		}
		
		foreach(
			safe_rows(
				'name, version',
				'txp_plugin',
				'1=1'
			) as $a
		) {
			$this->installed[$a['name']] = $a['version'];
		}
	}

	/**
	 * Delivers the panes
	 */

	static public function panes() {
		global $step;
		
		require_privs('rah_plugin_installer');
		require_privs('plugin');
		
		$steps = 
			array(
				'view' => false,
				'download' => true,
				'update' => true,
			);
		
		$pane = new rah_plugin_installer();
		
		if($pane->message || !$step || !bouncer($step, $steps))
			$step = 'view';
		
		$pane->$step();
	}

	/**
	 * Lists all plugins
	 * @param string $message Activity message.
	 */

	public function view($message='') {
		
		global $event;
		
		pagetop(gTxt('rah_plugin_installer'), $message);
		
		$installed = $this->installed;
		
		$rs = 
			safe_rows(
				'name, version, description',
				'rah_plugin_installer_def',
				'1=1'
			);
		
		$out[] =
		
			'	<div id="rah_plugin_installer_container" class="rah_ui_container">'.n.
			
			'		<p class="rah_ui_nav">'.
						'<span class="rah_ui_sep">&#187;</span> '.
						'<a id="rah_plugin_installer_update" href="?event='.$event.'&amp;step=update&amp;_txp_token='.form_token().'">'.
							gTxt('rah_plugin_installer_check_for_updates').
						'</a>'.
			'		</p>'.
			
			($this->message ? '		<p id="warning">'.$this->message[0].'</p>' : '').
			
			'		<table cellspacing="0" cellpadding="0" id="list">'.n.
			'			<thead>'.n.
			'				<tr>'.n.
			'					<th>'.gTxt('rah_plugin_installer_name').'</th>'.n.
			'					<th>'.gTxt('rah_plugin_installer_version').'</th>'.n.
			'					<th>'.gTxt('rah_plugin_installer_description').'</th>'.n.
			'					<th>'.gTxt('rah_plugin_installer_installed_version').'</th>'.n.
			'					<th>&#160;</th>'.n.
			'				</tr>'.n.
			'			</thead>'.n.
			'			<tbody>'.n;
		
		if($rs) {
			foreach($rs as $a) {

				$action = 'install';
				$ins = '&#160;';
			 	
			 	if(isset($installed[$a['name']])) {
			 		$ins = htmlspecialchars($installed[$a['name']]);
			 		$action = $ins == $a['version'] ? '' : 'update';
			 	}
			
				$out[] = 
					'				<tr>'.n.
					'					<td>'.htmlspecialchars($a['name']).'</td>'.n.
					'					<td>'.htmlspecialchars($a['version']).'</td>'.n.
					'					<td>'.htmlspecialchars($a['description']).'</td>'.n.
					'					<td>'.$ins.'</td>'.n.
					'					<td>'.($action ? '<a href="?event='.$event.'&amp;step=download&amp;name='.htmlspecialchars($a['name']).'&amp;_txp_token='.form_token().'">'.gTxt('rah_plugin_installer_'.$action).'</a>' : '&#160;').'</td>'.n.
					'				</tr>'.n;
			}
		} else
			$out[] =
				'			<tr>'.n.
				'				<td colspan="5">'.($updates ? $updates : gTxt('rah_plugin_installer_no_plugins')).'</td>'.n.
				'			</tr>'.n;
		
		$out[] = 
			
			'			</tbody>'.n.
			'		</table>'.n.
			'	</div>'.n;
			
		echo implode('', $out);
	}

	/**
	 * Checks for updates
	 * @param bool $manual If user-launched update check, or auto.
	 * @return string Returned message as a language string.
	 */

	private function check_updates($manual=false) {
		
		global $prefs;
		
		$now = strtotime('now');
		
		$wait = !$manual ? 604800 : 1800;
		
		if($prefs['rah_plugin_installer_updated'] + $wait >= $now) {
			return $manual ? 'already_up_to_date' : '';
		}
		
		$def = $this->get_plugin('http://rahforum.biz/?rah_plugin_installer=1&rah_version=2' , $manual ? 30 : 5);
		
		/*
			Update the last-update timestamp if we got payload
		*/
		
		if($def) {
			
			safe_update(
				'txp_prefs',
				"val='$now'",
				"name='rah_plugin_installer_updated'"
			);
		
			$prefs['rah_plugin_installer_updated'] = $now;
		}
		
		if(!$def || !preg_match('!^[a-zA-Z0-9/+]*={0,2}$!',$def)) {
			return 'could_not_fetch';
		}
		
		$def = base64_decode($def);
		$md5 = md5($def);
		
		if($md5 == $prefs['rah_plugin_installer_checksum'])
			return 'already_up_to_date';
		
		safe_update(
			'txp_prefs',
			"val='$md5'",
			"name='rah_plugin_installer_checksum'"
		);
		
		$this->import($this->parse($def));
		
		return 'definition_updates_checked';
	}

	/**
	 * Fire manual listing refresh
	 */

	public function update() {
		$this->view('', true);
	}

	/**
	 * Parses update file
	 * @param string $file File to parse.
	 * @return array
	 */

	private function parse($file) {

		$file = explode(n, $file);
		$plugin = '';
		$out = array();

		foreach($file as $line) {
			
			$line = trim($line);
			
			if(!$line || strpos($line,'#') === 0)
				continue;

			/*
				Set the plugin name
			*/
			
			if(strpos($line,'@') === 0 && strpos($line,'_') == 4) {
				$plugin = substr($line, 1);
				continue;
			}
			
			if(!$plugin)
				continue;
			
			if(!preg_match('/^(\w+)\s*=>\s*(.+)$/', $line, $m))
				continue;
				
			if(empty($m[1]) || empty($m[2]))
				continue;
					
			$out[$plugin][$m[1]] = $m[2];
		}
		
		return $out;
	}
	
	/**
	 * Imports update file to the database
	 * @param array $inc Definitions to import.
	 * @return Nothing.
	 */

	private function import($inc) {
		
		$plugin = array();
		
		$rs = 
			safe_rows(
				'name, version',
				'rah_plugin_installer_def',
				'1=1'
			);
		
		foreach($rs as $a)
			$plugin[$a['name']] = $a['version'];
		
		foreach($inc as $name => $a) {
			
			if(!isset($a['description']) || !isset($a['version']))
				continue;
			
			if(!isset($plugin[$name])) {
				
				safe_insert(
					'rah_plugin_installer_def',
					"name='".doSlash($name)."',
					version='".doSlash($a['version'])."',
					description='".doSlash($a['description'])."'"
				);
					
			}
			else if($plugin[$name] != $a['version']) {
			
				safe_update(
					'rah_plugin_installer_def',
					"version='".doSlash($a['version'])."',
					description='".doSlash($a['description'])."'",
					"name='".doSlash($name)."'"
				);
				
			}
			
			unset($plugin[$name]);
		}
		
		if(!empty($plugin)) {
			safe_delete(
				'rah_plugin_installer_def',
				'name in('.implode(',', quote_list(array_keys($plugin))).')'
			);
		}
	}

	/**
	 * Download the plugin code and run Textpattern's plugin installer
	 */

	public function download() {
		
		$name = gps('name');
		
		$def = 
			safe_row(
				'name, version',
				'rah_plugin_installer_def',
				"name='".doSlash($name)."' LIMIT 0, 1"
			);
		
		if(!$name || !$def) {
			$this->view('rah_plugin_installer_incorrect_selection');
			return;
		}
		
		if(fetch('version', 'txp_plugin', 'name', $name) == $def['version']) {
			$this->view('rah_plugin_installer_already_installed');
			return;
		}
		
		$url = 'http://rahforum.biz/?rah_plugin_download='.$name;
		$url = function_exists('gzencode') ? $url . '&rah_type=zip' : $url;
			
		$plugin = $this->get_plugin($url);
		
		if(empty($plugin)) {
			$this->view('rah_plugin_installer_downloading_plugin_failed');
			return;
		}

		$_POST['install_new'] = 'Upload';	
		$_POST['plugin'] = $plugin;
		$_POST['plugin64'] = $plugin;
		$_POST['event'] = 'plugin';
		$_POST['step'] = 'plugin_verify';
		$_POST['_txp_token'] = form_token();
		
		$step = 'plugin_verify';
		$event = 'plugin';
		
		include_once txpath.'/include/txp_plugin.php';
		exit;
	}

	/**
	 * Downloads remote file
	 * @param string $url URL to download.
	 * @param int $timeout Connection timeout in seconds.
	 * @return string Contents of the file. False on failure.
	 */

	private function get_plugin($url, $timeout=10) {
		
		@set_time_limit(0);
		@ignore_user_abort(true);
		
		/*
			If cURL isn't available,
			use file_get_contents if possible
		*/
			
		if(!function_exists('curl_init')) {
			
			if(!ini_get('allow_url_fopen'))
				return false;
			
			$context = 
				stream_context_create(
					array(
					'http' => 
						array(
							'timeout' => $timeout
						)
					)
				);

			@$file = file_get_contents($url, 0, $context);
			return !$file ? false : trim($file);
		}
		
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_FAILONERROR, 1);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
		curl_setopt($ch, CURLOPT_MAXREDIRS, 20);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
		curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);

		$file = curl_exec($ch);
		$http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);
		
		return $file !== false && $http == '200' ? trim($file) : false;
	}

	/**
	 * Redirect to the admin-side interface
	 */

	static public function prefs() {
		header('Location: ?event=rah_plugin_installer');
		echo 
			'<p>'.n.
			'	<a href="?event=rah_plugin_installer">'.gTxt('continue').'</a>'.n.
			'</p>';
	}

	/**
	 * Adds styles to the <head>
	 */

	static public function head() {
		global $event;
		
		if($event != 'rah_plugin_installer')
			return;
		
		echo <<<EOF
			<style type="text/css">
				#rah_plugin_installer_container {
					width: 950px;
					margin: 0 auto;	
				}
				#rah_plugin_installer_container table {
					width: 100%;	
				}
			</style>
EOF;
	}
}

?>