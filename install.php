<?php
	// Cool File Transfer Configuration
	// (C) 2017 CubicleSoft.  All Rights Reserved.

	if (file_exists("config.php") && (!isset($_REQUEST["action"]) || $_REQUEST["action"] != "done"))  exit();

	require_once "support/str_basics.php";
	require_once "support/flex_forms.php";
	require_once "support/random.php";
	require_once "support/csdb/db.php";

	Str::ProcessAllInput();

	session_start();
	if (!isset($_SESSION["cft_install"]))  $_SESSION["cft_install"] = array();
	if (!isset($_SESSION["cft_install"]["secret"]))
	{
		$rng = new CSPRNG();
		$_SESSION["cft_install"]["secret"] = $rng->GetBytes(64);
	}

	$ff = new FlexForms();
	$ff->SetSecretKey($_SESSION["cft_install"]["secret"]);
	$ff->CheckSecurityToken("action");

	function CFT_GetSupportedDatabases()
	{
		$result = array(
			"sqlite" => array("production" => true, "login" => false, "replication" => false, "default_dsn" => "@PATH@/sqlite_@RANDOM@.db"),
			"mysql" => array("production" => true, "login" => true, "replication" => true, "default_dsn" => "host=127.0.0.1"),
			"pgsql" => array("production" => true, "login" => true, "replication" => true, "default_dsn" => "host=localhost"),
			"oci" => array("production" => false, "login" => true, "replication" => true, "default_dsn" => "dbname=//localhost/ORCL")
		);

		return $result;
	}

	function OutputHeader($title)
	{
		global $ff;

		header("Content-type: text/html; charset=UTF-8");

?>
<!DOCTYPE html>
<html>
<head>
<title><?=htmlspecialchars($title)?> | Cool File Transfer Installer</title>
<link rel="stylesheet" href="support/install.css" type="text/css" media="all" />
<?php
		$ff->OutputJQuery();
?>
<script type="text/javascript">
setInterval(function() {
	$.post('<?=$ff->GetRequestURLBase()?>', {
		'action': 'heartbeat',
		'sec_t': '<?=$ff->CreateSecurityToken("heartbeat")?>'
	});
}, 5 * 60 * 1000);
</script>
</head>
<body>
<div id="headerwrap"><div id="header">Cool File Transfer Installer</div></div>
<div id="contentwrap"><div id="content">
<h1><?=htmlspecialchars($title)?></h1>
<?php
	}

	function OutputFooter()
	{
?>
</div></div>
<div id="footerwrap"><div id="footer">
&copy <?=date("Y")?> CubicleSoft.  All Rights Reserved.
</div></div>
</body>
</html>
<?php
	}

	$errors = array();
	if (isset($_REQUEST["action"]) && $_REQUEST["action"] == "heartbeat")
	{
		echo "OK";
	}
	else if (isset($_REQUEST["action"]) && $_REQUEST["action"] == "done")
	{
		if (!isset($_SESSION["cft_installed"]) || !$_SESSION["cft_installed"])  exit();

		OutputHeader("Installation Finished");

		$ff->OutputMessage("success", "The installation completed successfully.");

?>
<p>Cool File Transfer was successfully installed.  You now have a nifty browser-based live file transfer tool at your disposal.</p>

<p>What's next?  Secure the root Cool File Transfer directory so that the web server can't write to it.  Then, integrate Cool File Transfer into your web application by incorporating 'cft.php' (the CoolFileTransfer class) into your application's output.</p>

<p>Important configuration information is stored in the generated 'config.php' file.</p>
<?php

		OutputFooter();
	}
	else if (isset($_REQUEST["action"]) && $_REQUEST["action"] == "step3")
	{
		$databases = CFT_GetSupportedDatabases();
		$database = $_SESSION["cft_install"]["db_select"];

		if (!isset($_SESSION["cft_install"]["db_dsn"]))
		{
			$rng = new CSPRNG(true);

			$dsn = (isset($databases[$database]) ? $databases[$database]["default_dsn"] : "");
			$dsn = str_replace("@RANDOM@", $rng->GenerateString(), $dsn);
			if (strpos($dsn, "@PATH@") !== false)
			{
				@mkdir("db", 0775);
				@file_put_contents("db/index.html", "");
			}
			$dsn = str_replace("@PATH@", str_replace("\\", "/", dirname(__FILE__)) . "/db", $dsn);

			$_SESSION["cft_install"]["db_dsn"] = $dsn;
		}

		if (!isset($_SESSION["cft_install"]["db_user"]))  $_SESSION["cft_install"]["db_user"] = "";
		if (!isset($_SESSION["cft_install"]["db_pass"]))  $_SESSION["cft_install"]["db_pass"] = "";
		if (!isset($_SESSION["cft_install"]["db_name"]))  $_SESSION["cft_install"]["db_name"] = "cft";
		if (!isset($_SESSION["cft_install"]["db_table_prefix"]))  $_SESSION["cft_install"]["db_table_prefix"] = "cft_";
		if (!isset($_SESSION["cft_install"]["db_master_dsn"]))  $_SESSION["cft_install"]["db_master_dsn"] = "";
		if (!isset($_SESSION["cft_install"]["db_master_user"]))  $_SESSION["cft_install"]["db_master_user"] = "";
		if (!isset($_SESSION["cft_install"]["db_master_pass"]))  $_SESSION["cft_install"]["db_master_pass"] = "";

		$message = "";
		if (isset($_REQUEST["db_dsn"]))
		{
			// Test database access.
			$_REQUEST["db_name"] = preg_replace('/[^a-z]/', "_", strtolower($_REQUEST["db_name"]));
			if (!isset($databases[$database]))  $errors["msg"] = "Invalid database selected.  Go back and try again.";
			else if ($_REQUEST["db_dsn"] == "")
			{
				$errors["msg"] = "Please correct the errors below and try again.";
				$errors["db_dsn"] = "Please fill in this field with a valid DSN.";
			}
			else if ($_REQUEST["db_name"] == "")
			{
				$errors["msg"] = "Please correct the errors below and try again.";
				$errors["db_name"] = "Please fill in this field.";
			}
			else
			{
				require_once "support/csdb/db_" . $database . ".php";

				$classname = "CSDB_" . $database;

				try
				{
					$db = new $classname();
					$db->SetDebug(true);
					$db->Connect($database . ":" . $_REQUEST["db_dsn"], ($databases[$database]["login"] ? $_REQUEST["db_user"] : false), ($databases[$database]["login"] ? $_REQUEST["db_pass"] : false));
					$message = "Successfully connected to the server.<br><b>Running " . htmlspecialchars($db->GetDisplayName() . " " . $db->GetVersion()) . "</b><br>";
					unset($db);
				}
				catch (Exception $e)
				{
					$errors["msg"] = "Database connection attempt failed.<br>" . htmlspecialchars($e->getMessage());
				}

				if ($databases[$database]["replication"] && $_REQUEST["db_master_dsn"] != "")
				{
					try
					{
						$db = new $classname();
						$db->SetDebug(true);
						$db->Connect($database . ":" . $_REQUEST["db_master_dsn"], ($databases[$type]["login"] ? $_REQUEST["db_master_user"] : false), ($databases[$type]["login"] ? $_REQUEST["db_master_pass"] : false));
						$message .= "Successfully connected to the server.<br><b>Running " . htmlspecialchars($db->GetDisplayName() . " " . $db->GetVersion()) . "</b><br>";
						unset($db);
					}
					catch (Exception $e)
					{
						$errors["msg"] = "Database connection attempt failed.<br>" . htmlspecialchars($e->getMessage());
					}
				}
			}

			if (count($errors))  $errors["msg"] = "Please correct the errors below and try again.";
			else if (isset($_REQUEST["next"]))
			{
				$_SESSION["cft_install"]["db_dsn"] = $_REQUEST["db_dsn"];
				if ($databases[$database]["login"])  $_SESSION["cft_install"]["db_user"] = $_REQUEST["db_user"];
				if ($databases[$database]["login"])  $_SESSION["cft_install"]["db_pass"] = $_REQUEST["db_pass"];
				$_SESSION["cft_install"]["db_name"] = $_REQUEST["db_name"];
				$_SESSION["cft_install"]["db_table_prefix"] = $_REQUEST["db_table_prefix"];
				if ($databases[$database]["replication"])
				{
					$_SESSION["cft_install"]["db_master_dsn"] = $_REQUEST["db_master_dsn"];
					if ($databases[$database]["login"])  $_SESSION["cft_install"]["db_master_user"] = $_REQUEST["db_master_user"];
					if ($databases[$database]["login"])  $_SESSION["cft_install"]["db_master_pass"] = $_REQUEST["db_master_pass"];
				}

				// Generate the configuration.
				if (!count($errors))
				{
					$config = array(
						"rootpath" => str_replace("\\", "/", dirname(__FILE__)),
						"rooturl" => dirname($ff->GetFullRequestURLBase()) . "/",
						"db_select" => $_SESSION["cft_install"]["db_select"],
						"db_dsn" => $_SESSION["cft_install"]["db_dsn"],
						"db_login" => $databases[$database]["login"],
						"db_user" => $_SESSION["cft_install"]["db_user"],
						"db_pass" => $_SESSION["cft_install"]["db_pass"],
						"db_name" => $_SESSION["cft_install"]["db_name"],
						"db_table_prefix" => $_SESSION["cft_install"]["db_table_prefix"],
						"db_master_dsn" => $_SESSION["cft_install"]["db_master_dsn"],
						"db_master_user" => $_SESSION["cft_install"]["db_master_user"],
						"db_master_pass" => $_SESSION["cft_install"]["db_master_pass"],
					);
				}

				// Database setup.
				if (!count($errors))
				{
					require_once "support/csdb/db_" . $config["db_select"] . ".php";

					$dbclassname = "CSDB_" . $config["db_select"];

					try
					{
						$db = new $dbclassname($config["db_select"] . ":" . $config["db_dsn"], ($config["db_login"] ? $config["db_user"] : false), ($config["db_login"] ? $config["db_pass"] : false));
						if ($config["db_master_dsn"] != "")  $db->SetMaster($config["db_select"] . ":" . $config["db_master_dsn"], ($config["db_login"] ? $config["db_master_user"] : false), ($config["db_login"] ? $config["db_master_pass"] : false));
					}
					catch (Exception $e)
					{
						$errors["msg"] = "Database connection failed.  " . htmlspecialchars($e->getMessage());
					}
				}

				if (!count($errors))
				{
					try
					{
						$db->GetDisplayName();
						$db->GetVersion();
					}
					catch (Exception $e)
					{
						$errors["msg"] = "Database connection succeeded but unable to get server version.  " . htmlspecialchars($e->getMessage());
					}
				}

				// Create/Use the database.
				if (!count($errors))
				{
					try
					{
						$db->Query("USE", $config["db_name"]);
					}
					catch (Exception $e)
					{
						try
						{
							$db->Query("CREATE DATABASE", array($config["db_name"], "CHARACTER SET" => "utf8", "COLLATE" => "utf8_general_ci"));
							$db->Query("USE", $config["db_name"]);
						}
						catch (Exception $e)
						{
							$errors["msg"] = "Unable to create/use database '" . htmlspecialchars($config["db_name"]) . "'.  " . htmlspecialchars($e->getMessage());
						}
					}
				}

				// Create database tables.
				if (!count($errors))
				{
					$dbprefix = $config["db_table_prefix"];
					$cft_db_files = $dbprefix . "files";
					try
					{
						$filesfound = $db->TableExists($cft_db_files);
					}
					catch (Exception $e)
					{
						$errors["msg"] = "Unable to determine the existence of a database table.  " . htmlspecialchars($e->getMessage());
					}
				}

				if (!count($errors) && !$filesfound)
				{
					try
					{
						$db->Query("CREATE TABLE", array($cft_db_files, array(
							"id" => array("INTEGER", 8, "UNSIGNED" => true, "NOT NULL" => true, "PRIMARY KEY" => true, "AUTO INCREMENT" => true),
							"lastrequest" => array("INTEGER", 8, "UNSIGNED" => true, "NOT NULL" => true),
							"srcuser" => array("STRING", 1, 255, "NOT NULL" => true),
							"destuser" => array("STRING", 1, 255, "NOT NULL" => true),
							"sendtoken" => array("STRING", 1, 64, "NOT NULL" => true),
							"recvtoken" => array("STRING", 1, 64, "NOT NULL" => true),
							"filename" => array("STRING", 1, 255, "NOT NULL" => true),
							"filesize" => array("INTEGER", 8, "UNSIGNED" => true, "NOT NULL" => true),
							"port" => array("INTEGER", 4, "NOT NULL" => true),
						),
						array(
							array("KEY", array("lastrequest"), "NAME" => "lastrequest"),
							array("KEY", array("destuser"), "NAME" => "destuser"),
						)));
					}
					catch (Exception $e)
					{
						$errors["msg"] = "Unable to create the database table '" . htmlspecialchars($cft_db_files) . "'.  " . htmlspecialchars($e->getMessage());
					}
				}

				// Write the configuration to disk.
				if (!count($errors))
				{
					$data = "<" . "?php\n";
					$data .= "\$config = " . var_export($config, true) . ";\n";
					$data .= "?" . ">";

					$filename = $config["rootpath"] . "/config.php";
					if (@file_put_contents($filename, $data) === false)  $errors["msg"] = "Unable to write configuration to '" . htmlspecialchars($filename) . "'.";
					else if (function_exists("opcache_invalidate"))  @opcache_invalidate($filename, true);
				}

				if (!count($errors))
				{
					$_SESSION["cft_installed"] = true;

					header("Location: " . $ff->GetFullRequestURLBase() . "?action=done&sec_t=" . $ff->CreateSecurityToken("done"));

					exit();
				}
			}
		}

		OutputHeader("Step 3:  Configure Database");

		if (isset($databases[$database]))
		{
			if (count($errors))  $ff->OutputMessage("error", $errors["msg"]);
			else if ($message != "")  $ff->OutputMessage("info", $message);

			$contentopts = array(
				"fields" => array(
					array(
						"title" => "* DSN options",
						"type" => "text",
						"name" => "db_dsn",
						"default" => $_SESSION["cft_install"]["db_dsn"],
						"desc" => "The initial connection string to connect to the database server.  Options are driver specific.  Usually takes the form of:  host=ipaddr_or_hostname[;port=portnum] (e.g. host=127.0.0.1;port=3306)"
					),
					array(
						"use" => $databases[$database]["login"],
						"title" => "Username",
						"type" => "text",
						"name" => "db_user",
						"default" => $_SESSION["cft_install"]["db_user"],
						"desc" => "The username to use to log into the database server."
					),
					array(
						"use" => $databases[$database]["login"],
						"title" => "Password",
						"type" => "password",
						"name" => "db_pass",
						"default" => $_SESSION["cft_install"]["db_pass"],
						"desc" => "The password to use to log into the database server."
					),
					array(
						"title" => "* Database",
						"type" => "text",
						"name" => "db_name",
						"default" => $_SESSION["cft_install"]["db_name"],
						"desc" => "The database to select after connecting into the database server."
					),
					array(
						"title" => "Table prefix",
						"type" => "text",
						"name" => "db_table_prefix",
						"default" => $_SESSION["cft_install"]["db_table_prefix"],
						"desc" => "The prefix to use for table names in the selected database."
					),
					array(
						"use" => $databases[$database]["replication"],
						"title" => "Replication master - DSN options",
						"type" => "text",
						"name" => "db_master_dsn",
						"default" => $_SESSION["cft_install"]["db_master_dsn"],
						"desc" => "The connection string to connect to the master database server.  Leave blank if you aren't using database replication!  Options are driver specific.  Usually takes the form of:  host=ipaddr_or_hostname[;port=portnum] (e.g. host=somehost;port=3306)"
					),
					array(
						"use" => $databases[$database]["replication"] && $databases[$database]["login"],
						"title" => "Replication master - Username",
						"type" => "text",
						"name" => "db_master_user",
						"default" => $_SESSION["cft_install"]["db_master_user"],
						"desc" => "The username to use to log into the replication master database server."
					),
					array(
						"use" => $databases[$database]["replication"] && $databases[$database]["login"],
						"title" => "Replication master - Password",
						"type" => "password",
						"name" => "db_master_pass",
						"default" => $_SESSION["cft_install"]["db_master_pass"],
						"desc" => "The password to use to log into the replication master database server."
					)
				),
				"submit" => array("test" => "Test Connection", "next" => "Install")
			);

			$ff->Generate($contentopts, $errors);
		}
		else
		{
			$ff->OutputMessage("error", "Invalid database selected.  Go back and try again.");
		}

		OutputFooter();
	}
	else if (isset($_REQUEST["action"]) && $_REQUEST["action"] == "step2")
	{
		$databases2 = array();
		$databases = CFT_GetSupportedDatabases();
		foreach ($databases as $database => $info)
		{
			require_once "support/csdb/db_" . $database . ".php";

			try
			{
				$classname = "CSDB_" . $database;
				$db = new $classname();
				if ($db->IsAvailable() !== false)  $databases2[$database] = $db->GetDisplayName() . (!$info["production"] ? " [NOT for production use]" : "");
			}
			catch (Exception $e)
			{
			}
		}

		if (!isset($_SESSION["cft_install"]["db_select"]))  $_SESSION["cft_install"]["db_select"] = "";

		if (isset($_REQUEST["db_select"]))
		{
			if (!isset($databases[$_REQUEST["db_select"]]))  $errors["db_select"] = "Please select a database.  If none are available, make sure at least one supported PDO database driver is enabled in your PHP installation.";

			if (!count($errors))
			{
				$_SESSION["cft_install"]["db_select"] = $_REQUEST["db_select"];
				unset($_SESSION["cft_install"]["db_dsn"]);

				header("Location: " . $ff->GetFullRequestURLBase() . "?action=step3&sec_t=" . $ff->CreateSecurityToken("step3"));

				exit();
			}
		}

		OutputHeader("Step 2:  Select Database");

		if (count($errors))  $ff->OutputMessage("error", "Please correct the errors below to continue.");

		$contentopts = array(
			"fields" => array(
				array(
					"title" => "* Available databases",
					"type" => "select",
					"name" => "db_select",
					"options" => $databases2,
					"default" => $_SESSION["cft_install"]["db_select"],
					"desc" => (isset($databases2["sqlite"]) ? "SQLite should only be used for smaller installations." : "")
				),
			),
			"submit" => "Next Step"
		);

		$ff->Generate($contentopts, $errors);

		OutputFooter();
	}
	else if (isset($_REQUEST["action"]) && $_REQUEST["action"] == "step1")
	{
		if (isset($_REQUEST["submit"]))
		{
			header("Location: " . $ff->GetFullRequestURLBase() . "?action=step2&sec_t=" . $ff->CreateSecurityToken("step2"));

			exit();
		}

		OutputHeader("Step 1:  Environment Check");

		if ((double)phpversion() < 5.6)  $errors["phpversion"] = "The server is running PHP " . phpversion() . ".  The installation may succeed but the software will not function.  Running outdated versions of PHP poses a serious website security risk.  Please contact your system administrator to upgrade your PHP installation.";

		if (file_put_contents("test.dat", "a") === false)  $errors["createfiles"] = "Unable to create 'test.dat'.  Running chmod 777 on the directory may fix the problem.";
		else if (!unlink("test.dat"))  $errors["createfiles"] = "Unable to delete 'test.dat'.  Running chmod 777 on the directory may fix the problem.";

		if (!isset($_SERVER["REQUEST_URI"]))  $errors["requesturi"] = "The server does not appear to support this feature.  The installation may fail and the site might not work.";

		if (!$ff->IsSSLRequest())  $errors["ssl"] = "This software should be installed over SSL.  SSL/TLS certificates can be obtained for free.  Proceed only if this major security risk is acceptable.";

		try
		{
			$rng = new CSPRNG(true);
		}
		catch (Exception $e)
		{
			$error["csprng"] = "Please ask your system administrator to install a supported PHP version (e.g. PHP 7 or later) or extension (e.g. OpenSSL).";
		}

		// Test server creation.
		if (!function_exists("stream_socket_server"))  $error["startserver"] = "PHP is missing a critical stream socket server functions.  Application will not function.";
		else
		{
			$fp = @stream_socket_server("tcp://127.0.0.1:0", $errornum, $errorstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN);
			if ($fp === false)  $error["startserver"] = "PHP is unable to bind a port on localhost.  Either the OS or PHP is preventing a successful bind/listen.";
			else  @fclose($fp);
		}

?>
<p>The current PHP environment has been evaluated against the minimum system requirements.  Any issues found are noted below.  After correcting any issues, reload the page.</p>
<?php

		$contentopts = array(
			"fields" => array(
				array(
					"title" => "PHP 5.6.x or later",
					"type" => "static",
					"name" => "phpversion",
					"value" => (isset($errors["phpversion"]) ? "No.  Test failed." : "Yes.  Test passed.")
				),
				array(
					"title" => "Able to create files in ./",
					"type" => "static",
					"name" => "createfiles",
					"value" => (isset($errors["createfiles"]) ? "No.  Test failed." : "Yes.  Test passed.")
				),
				array(
					"title" => "\$_SERVER[\"REQUEST_URI\"] supported",
					"type" => "static",
					"name" => "requesturi",
					"value" => (isset($errors["requesturi"]) ? "No.  Test failed." : "Yes.  Test passed.")
				),
				array(
					"title" => "Installation over SSL",
					"type" => "static",
					"name" => "ssl",
					"value" => (isset($errors["ssl"]) ? "No.  Test failed." : "Yes.  Test passed.")
				),
				array(
					"title" => "Crypto-safe CSPRNG available",
					"type" => "static",
					"name" => "csprng",
					"value" => (isset($errors["csprng"]) ? "No.  Test failed." : "Yes.  Test passed.")
				),
				array(
					"title" => "Able to start a TCP/IP socket server",
					"type" => "static",
					"name" => "startserver",
					"value" => (isset($errors["startserver"]) ? "No.  Test failed." : "Yes.  Test passed.")
				)
			),
			"submit" => "Next Step",
			"submitname" => "submit"
		);

		$functions = array(
			"stream_socket_server" => "Localhost-only, dynamic port server setup",
			"stream_socket_client" => "Localhost connection establishment",
			"json_encode" => "JSON encoding/decoding (critical!)",
		);

		foreach ($functions as $function => $info)
		{
			if (!function_exists($function))  $errors["function|" . $function] = "The software will be unable to use " . $info . ".  The installation might succeed but the product may not function at all.";

			$contentopts["fields"][] = array(
				"title" => "'" . $function . "' available",
				"type" => "static",
				"name" => "function|" . $function,
				"value" => (isset($errors["function|" . $function]) ? "No.  Test failed." : "Yes.  Test passed.")
			);
		}

		$classes = array(
			"PDO" => "PDO database classes",
		);

		foreach ($classes as $class => $info)
		{
			if (!class_exists($class))  $errors["class|" . $function] = "The software will be unable to use " . $info . ".  The installation might succeed but the product may not function at all.";

			$contentopts["fields"][] = array(
				"title" => "'" . $class . "' available",
				"type" => "static",
				"name" => "class|" . $class,
				"value" => (isset($errors["class|" . $class]) ? "No.  Test failed." : "Yes.  Test passed.")
			);
		}

		$ff->Generate($contentopts, $errors);

		OutputFooter();
	}
	else
	{
		OutputHeader("Introduction");

		foreach ($_GET as $key => $val)
		{
			if (!isset($_SESSION["cft_install"][$key]))  $_SESSION["cft_install"][$key] = (string)$val;
		}

?>
<p>You are about to install Cool File Transfer:  A nifty browser-based live file transfer tool.</p>

<p><a href="<?=$ff->GetRequestURLBase()?>?action=step1&sec_t=<?=$ff->CreateSecurityToken("step1")?>">Start installation</a></p>
<?php

		OutputFooter();
	}
?>