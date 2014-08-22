<?php
namespace TNVW\Admin;

class Install {
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
						mysql_query("create database $vars[sitename];");
					},
					'uninstall' => function () {
							
					},
					'depend' => function($vars){
						return !mysql_select_db($vars[sitename]);
					}
				)),
				'folder' => new InstallStep (array(
					'install' => function($vars){
						mkdir("/var/www/$vars[sitename]");
					},
					'depend' => function($vars){
						return !empty($vars[sitename]) && !file_exists("/var/www/$vars[sitename]");
					}
				)),
				'apache2' => new InstallStep (array(
					'install' => function($vars){
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
						$fp = fopen("/etc/apache2/sites-available/$vars[sitename].conf","w");
						fputs($fp,$config);
						fclose($fp);

						$result = shell_exec("a2ensite $vars[sitename]");
						shell_exec("service apache2 reload");
					},
					'depend' => function($vars){
						return !empty($vars[sitename]) && !file_exists("/etc/apache2/sites-available/$vars[sitename].conf");
					}
				)),
				'laravel' => new InstallStep (array(
					'install' => function($vars){
						$dir = "/var/www/$vars[sitename]";
						if(!file_exists($dir)) mkdir("/var/www/$vars[sitename]");
						chdir("/var/www/$vars[sitename]");
						$console = shell_exec("composer create-project laravel/laravel --prefer-dist");
						shell_exec("chown -R $vars[username]:www-data /var/www/$vars[sitename]");
						shell_exec("chmod 775 -R /var/www/$vars[sitename]/laravel/app/storage");
						error_log($console);
					},
					'depend' => function($vars){
						//@TODO: detect Composer
						$composer_version = shell_exec("composer --version");
						error_log($composer_version);
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
	
	public function check ()
	{
		//give a hash of all steps currently registered.
		$hash = "";
		$vars = array(
			'domain' => $this->domain,
			'sitename' => $this->sitename,
			'username' => $this->username
		);
		
		foreach($this->steps as $step)
		{
			if(!$step->depend($vars)) return false;
			$hash .= spl_object_hash($step);
		}
		return md5($hash);
	}
	
	public function install ($pass)
	{
		$hash = "";
		foreach ($this->steps as $step) {
			$hash .= spl_object_hash($step);
		}
		$hash = md5($hash);
		if($hash == $pass) {
			$vars = array(
				'domain' => $this->domain,
				'sitename' => $this->sitename,
				'username' => $this->username
			);
			
			foreach ($this->steps as $i => $step) {
				echo "Installing step $i... " . PHP_EOL;
				$step->install($vars);
			}
			echo "DONE" .PHP_EOL;
		} else {
			throw new Exception("Invalid hash token. Please get a new one from ::check()");
		}
	}
}

class InstallStep {
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
	 * 
	 * returns Array<InstallStepOption>
	 */
	public static function defaults () {
		return array (
			'install' => function(){throw new Exception("Cannot use default install()"); return true;},
			'depend' => function(){throw new Exception("Cannot use default depend()");return true;},
		);
	}
	
	public function __construct(Array $options = array())
	{
		$options = array_merge(self::defaults(),$options);
		$this->_depend = $options['depend'];
		$this->_install = $options['install'];
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
}