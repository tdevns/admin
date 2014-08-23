<?php
namespace TNVW\Admin;

class Install {
	const sitename = 'sitename';
	
	protected $_check;
	/**
	 * 
	 * @var Array<InstallStep>
	 */
	protected $steps = array(); //Array<InstallSteps>
	/**
	 * 
	 * @var String
	 */
	protected $sitename;
	/**
	 * 
	 * @var String
	 */
	protected $domain;
	/**
	 * 
	 * @var Your username.
	 */
	protected $username;
	/**
	 *
	 * returns Array<InstallDefaultSetting>
	 */
	public static function defaults ()
	{
		return array(
			'sitename' => '',
			'domain' => '',
			'username' => '',
			'steps' => array(
				'mysql-database' => new InstallStep (array(
					'install' => function($vars){
						mysql_connect("127.0.0.1","root");
						if(!mysql_query("create database $vars[sitename];"))
						{
							throw new \Exception("MySQL Failure: " . mysql_error());	
						}
					},
					'uninstall' => function ($vars) {
						mysql_connect("127.0.0.1","root");
						mysql_query("drop database $vars[sitename];");
					},
					'depend' => function($vars){
						if(mysql_select_db($vars[self::sitename])) return new InstallError("Database already exists.");
						return true;
					}
				)),
				'apache2' => new InstallStep (array(
					'install' => function($vars){
						
						$file = "/etc/apache2/sites-available/$vars[sitename].conf";
						if(file_exists($file)){
							//@TODO: Workaround that moves the config up a chain and/or tries to merge it.
							throw new Exception("$vars[sitename] Apache2 Configuration already exists.");
						}
						$config = <<<FILE_
    <VirtualHost *:80>
        ServerAdmin webmaster@$vars[domain].localhost
        ServerName $vars[domain].localhost
        DocumentRoot /var/www/$vars[sitename]/laravel/public
        <Directory />
                Options FollowSymLinks
                AllowOverride None
        </Directory>
        <Directory /var/www/$vars[sitename]/laravel/public/>
                Options Indexes FollowSymLinks MultiViews
                AllowOverride All
                Order allow,deny
                allow from all
        </Directory>

        ScriptAlias /cgi-bin/ /usr/lib/cgi-bin/
        <Directory "/usr/lib/cgi-bin">
                AllowOverride None
                Options +ExecCGI -MultiViews +SymLinksIfOwnerMatch
                Order allow,deny
                Allow from all
        </Directory>

        ErrorLog \${APACHE_LOG_DIR}/error.log

        # Possible values include: debug, info, notice, warn, error, crit,
        # alert, emerg.
        LogLevel warn

        CustomLog \${APACHE_LOG_DIR}/access.log combined

    Alias /doc/ "/usr/share/doc/"
    <Directory "/usr/share/doc/">
        Options Indexes MultiViews FollowSymLinks
        AllowOverride None
        Order deny,allow
        Deny from all
        Allow from 127.0.0.0/255.0.0.0 ::1/128
    </Directory>

</VirtualHost>
FILE_;
						$fp = fopen($file,"w");
						fputs($fp,$config);
						fclose($fp);

						$result = shell_exec("a2ensite $vars[sitename]");
						shell_exec("service apache2 reload");
					},
					'uninstall' => function($vars) {
						shell_exec("a2dissite $vars[sitename]");
						
						//step_i increments with each existence from /name.conf, /name.conf-bak, /name.conf-bak(n-1)
						$_fn_getConfName = function ($n=0) use ($vars)
						{
							return "/etc/apache2/sites-available/$vars[sitename].conf" . ($n?"-bak":"") . ($n>1?$n-1:"");
						};
						
						$n=0; //start at 0, go up to n which is the end of the conf file backup chain
						while( file_exists($_fn_getConfName($n)) ) //n gets increased every time there's a link in this chain.
							$n++;
						
						while($n>0) //the chain gets pushed forward so that there can be room for a fresh conf file
							shell_exec("mv " . $_fn_getConfName($n-1) . " " . $_fn_getConfName($n--));
						
						//done uninstalling
						return;
					},
					'depend' => function($vars){
						if (empty($vars[self::sitename])) return new InstallError("Site name cannot be empty.");
						if (file_exists("/etc/apache2/sites-available/$vars[sitename].conf")) return new InstallError("$vars[sitename] already exists in Apache2");
						return true;
					}
				)),
				'laravel' => new InstallStep (array(
					'install' => function($vars){
						$dir = "/var/www/$vars[sitename]";
						//@TODO: move the folder if the user wants to.
						if(file_exists($dir)) throw new Exception("$vars[sitename] already exists in your WWW directory.");
						mkdir($dir);
						chdir($dir);
						$console = shell_exec("composer create-project laravel/laravel --prefer-dist");
						shell_exec("chown -R $vars[username]:www-data /var/www/$vars[sitename]");
						shell_exec("chmod 775 -R /var/www/$vars[sitename]/laravel/app/storage");
						
						//do the MySQL settings in app/config/local
						$config = "/var/www/$vars[sitename]/laravel/app/config/local/database.php";
						if(!file_exists($config)) throw new \Exception("Config not found. This is required to complete setup.");
						$filesize = filesize($config);
						$fp = fopen($config,"r");
						$config_php = fgets($fp,$filesize);
						$find = array(
							"'database' => 'homestead',",
							"'username' => 'homestead',",
							"'password' => 'secret',"
						);
						$replace = array(
							"'database' => '$vars[sitename]',",
							"'username' => 'root',",
							"'password' => '',"
						);
						fseek($fp,0);
						fputs($fp,$config_php,strlen($config_php));
						fclose($fp);
					},
					'uninstall' => function ($vars) {
						//backup chain

						//step_i increments with each existence from /name, /name-bak, /name-bak(n-1)
						$_fn_getDirName = function ($n=0) use ($vars)
						{
							return "/var/www/$vars[sitename]" . ($n?"-bak":"") . ($n>1?$n-1:"");
						};
						
						$n=0; //start at 0, go up to n which is the end of the conf file backup chain
							while( file_exists($_fn_getDirName($n)) ) //n gets increased every time there's a link in this chain.
							$n++;
							while($n>0) //the chain gets pushed forward so that there can be room for a fresh conf file
								shell_exec("mv " . $_fn_getDirName($n-1) . " " . $_fn_getDirName($n--));
					},
					'depend' => function($vars){
						//@TODO: detect Composer
						$composer_version = shell_exec("composer --version");
						echo $composer_version;
						if (empty($vars[self::sitename])) return new InstallError("Site name cannot be empty.");
						if (file_exists("/var/www/$vars[sitename]")) return new InstallError("Site name already exists in WWW directory.");
						return true;
					}
				))
			),
			'other_options' => 'NONE'
		);
	}
	
	/**
	 * 
	 * @var Array options
	 */
	public function __construct(Array $options = array()) 
	{
		$options = array_merge(self::defaults(),$options);
		$this->steps = $options['steps'];
		$this->domain = $options['domain'];
		$this->sitename = $options['sitename'];
		$this->username = $options['username'];
	}


	public function vars () {
		return array(
			'domain' => $this->domain,
			'sitename' => $this->sitename,
			'username' => $this->username
		);
	}
	
	public function check ()
	{
		//give a hash of all steps currently registered.
		$hash = "";
		$vars = $this->vars();
		
		foreach($this->steps as $i=> $step)
		{
			echo "Depend step: $i" . PHP_EOL;
			$check = $step->depend($vars);
			if($check === false) return $this->_check = false;
				else if (is_object($check) && get_class($check)=='InstallError') return $this->_check = $check;
			$hash .= spl_object_hash($step);
		}
		$hash = md5($hash);
		return $this->_check = $hash;
	}
	
	public function lastStatus () { return $this->_check; }
	
	public function install ($pass)
	{
		$hash = "";
		foreach ($this->steps as $step) {
			$hash .= spl_object_hash($step);
		}
		$hash = md5($hash);
		if($hash == $pass) {
			$vars = $this->vars();
			
			foreach ($this->steps as $i => $step) {
				echo "Installing step $i... " . PHP_EOL;
				$step->install($vars);
			}
			echo "DONE" .PHP_EOL;
		} else {
			throw new Exception("Invalid hash token. Please get a new one from ::check()");
		}
	}

	public function uninstall ()
	{
		$vars = $this->vars();
		//doesn't matter what is still there. just remove it if it exists
		foreach ($this->steps as $i => $step) {
			echo "Uninstall step $i... " . PHP_EOL;
			$step->uninstall($vars);
		}
	}
}

class InstallStep {
	/**
	 * Associative Array of Functions
	 * @unused
	 * @var Array<Function>
	 */
	public $_function;
	/**
	 * Returns true on dependencies met with the system. Returns false if otherwise.
	 * @var Function
	 */
	public $_depend;
	
	/**
	 * @var Function
	 */
	public $_install;
	
	/**
	 * @var Function
	 */
	public $_uninstall;
	
	/**
	 * 
	 * returns Array<InstallStepOption>
	 */
	public static function defaults () {
		return array (
			'install' => function(){throw new \Exception("Cannot use default install()"); return true;},
			'depend' => function(){throw new \Exception("Cannot use default depend()");return true;},
			'uninstall' => function(){throw new \Exception("Cannot use default uninstall()");return true;},
		);
	}
	
	public function __construct(Array $options = array())
	{
		$options = array_merge(self::defaults(),$options);
		$this->_depend = $options['depend'];
		$this->_install = $options['install'];
		$this->_uninstall = $options['uninstall'];
	}
	
	public function depend ($vars = array())
	{
		$dp = $this->_depend;
		return $dp($vars);
	}
	
	public function install ($vars = array())
	{
		$is = $this->_install;
		return $is($vars);
	}
	
	public function uninstall ($vars = array())
	{
		$ui = $this->_uninstall;
		return $ui($vars);
	}
}

class InstallError {
	protected $_error;
	public function __construct ($_error) {
		$this->_error = $_error;
	}
}