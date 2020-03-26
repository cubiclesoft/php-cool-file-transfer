<?php
	// Cool File Transfer basic send/recv API startup.
	// (C) 2017 CubicleSoft.  All Rights Reserved.

	require_once "config.php";

	require_once $config["rootpath"] . "/support/str_basics.php";

	function CFT_DisplayError($msg, $msgcode)
	{
		if (!headers_sent())  header("Content-Type: application/json");

		$result = array(
			"success" => false,
			"error" => $msg,
			"errorcode" => $msgcode
		);

		echo json_encode($result, JSON_UNESCAPED_SLASHES);
		exit();
	}

	function CFT_SendResult($result)
	{
		if (!headers_sent())  header("Content-Type: application/json");

		echo json_encode($result, JSON_UNESCAPED_SLASHES);
		exit();
	}

	// Constant-time string comparison.  Ported from CubicleSoft C++ code.
	function CFT_CTstrcmp($secret, $userinput)
	{
		$sx = 0;
		$sy = strlen($secret);
		$uy = strlen($userinput);
		$result = $sy - $uy;
		for ($ux = 0; $ux < $uy; $ux++)
		{
			$result |= ord($userinput[$ux]) ^ ord($secret[$sx]);
			$sx = ($sx + 1) % $sy;
		}

		return $result;
	}

	// Disable PHP's internal timeout.
	set_time_limit(0);

	// Validate input.
	Str::ProcessAllInput();

	if (!isset($_REQUEST["id"]) || !is_string($_REQUEST["id"]))  CFT_DisplayError("Missing 'id'.", "missing_id");
	if (!isset($_REQUEST["token"]) || !is_string($_REQUEST["token"]))  CFT_DisplayError("Missing 'token'.", "missing_token");

	// Connect to the database.
	require_once $config["rootpath"] . "/support/csdb/db_" . $config["db_select"] . ".php";

	$dbclassname = "CSDB_" . $config["db_select"];

	try
	{
		if ($config["db_master_dsn"] != "")  $db = new $dbclassname($config["db_select"] . ":" . $config["db_master_dsn"], ($config["db_login"] ? $config["db_master_user"] : false), ($config["db_login"] ? $config["db_master_pass"] : false));
		else  $db = new $dbclassname($config["db_select"] . ":" . $config["db_dsn"], ($config["db_login"] ? $config["db_user"] : false), ($config["db_login"] ? $config["db_pass"] : false));

		$db->Query("USE", $config["db_name"]);
	}
	catch (Exception $e)
	{
		CFT_DisplayError("Database connection failed.", "db_connect_failed");
	}

	$dbprefix = $config["db_table_prefix"];
	$cft_db_files = $dbprefix . "files";

	// Retrieve waiting send file information.
	try
	{
		$row = $db->GetRow("SELECT", array(
			"*",
			"FROM" => "?",
			"WHERE" => "id = ?",
		), $cft_db_files, $_REQUEST["id"]);
	}
	catch (Exception $e)
	{
		CFT_DisplayError("Unable to retrieve file.  A database error occurred.", "db_error");
	}

	if (!$row)  CFT_DisplayError("File does not exist or is invalid.", "missing_file");
?>