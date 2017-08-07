<?php
	// Cool File Transfer send endpoint.
	// (C) 2017 CubicleSoft.  All Rights Reserved.

	require_once "../base.php";

	// Validate the security token.
	if (CFT_CTstrcmp($row->sendtoken, $_REQUEST["token"]))  CFT_DisplayError("File send token is invalid.", "invalid_token");

	if (isset($_FILES["file"]))
	{
		// Handle incoming file data.
		require_once $config["rootpath"] . "/support/flex_forms.php";
		require_once $config["rootpath"] . "/support/flex_forms_fileuploader.php";

		$files = FlexForms::NormalizeFiles("file");
		if (!isset($files[0]))  CFT_DisplayError("File data was submitted but is missing.", "bad_input");
		if (!$files[0]["success"])  CFT_DisplayError($files[0]["error"], $files[0]["errorcode"]);

		if ($row->port < 1)  CFT_DisplayError("Receiver not initialized.", "recv_not_initialized");

		// Update the database.
		try
		{
			$db->Query("UPDATE", array($cft_db_files, array(
				"lastrequest" => time(),
			), "WHERE" => "id = ?"), $row->id);
		}
		catch (Exception $e)
		{
			CFT_DisplayError("Unable to update the file.  A database error occurred.", "db_error");
		}

		// Start response.  Prevent the browser from disconnecting.
		header("Content-Type: application/json");
		@ob_flush();
		@flush();

		// Connect to the server.
		$fp = @stream_socket_client("tcp://127.0.0.1:" . $row->port, $errornum, $errorstr, 30, STREAM_CLIENT_CONNECT);
		if ($fp === false)  CFT_DisplayError("Failed to connect to local transport interface.", "local_transport_failed");

		// Send the information to the server.
		// There might be a security vulnerability here with hijacking uploaded data via TCP/IP port reuse.
		// To solve, the server could present some information first (e.g. the recvtoken and, if valid, this code then sends sendtoken).
		// But doing all of that requires an extra round trip over TCP/IP, which seems overkill.  An irony since I tend to do overkill.
		$data = array(
			"token" => $row->recvtoken,
			"filename" => $files[0]["file"]
		);

		if (isset($_SERVER["HTTP_CONTENT_RANGE"]))  $data["startpos"] = FlexForms_FileUploader::GetFileStartPosition();

		@fwrite($fp, json_encode($data, JSON_UNESCAPED_SLASHES) . "\n");

		$data = @fgets($fp);

		fclose($fp);

		$result = @json_decode($data, true);
		if (!is_array($result) || !isset($result["success"]))  CFT_DisplayError("Receiver responded with invalid response.", "invalid_receiver_response");

		CFT_SendResult($result);
	}
	else if (isset($_REQUEST["action"]) && $_REQUEST["action"] === "cancel")
	{
		// Cancelling is a matter of deleting the database row.
		try
		{
			$db->Query("DELETE", array($cft_db_files, "WHERE" => "id = ?"), $row->id);
		}
		catch (Exception $e)
		{
			CFT_DisplayError("Unable to cancel the file.  A database error occurred.", "db_error");
		}
	}
	else
	{
		// Start a TCP/IP server on a random port number.
		$serverfp = @stream_socket_server("tcp://127.0.0.1:0", $errornum, $errorstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN);
		if ($serverfp === false)  CFT_DisplayError("Failed to start local transport interface.", "local_transport_failed");

		$serverinfo = stream_socket_get_name($serverfp, false);
		$pos = strrpos($serverinfo, ":");
		$serverip = substr($serverinfo, 0, $pos);
		$serverport = (int)substr($serverinfo, $pos + 1);

		// Update the database with the new server port.
		try
		{
			$db->Query("UPDATE", array($cft_db_files, array(
				"lastrequest" => time(),
				"port" => -$serverport,
			), "WHERE" => "id = ? AND port < 1"), $row->id);
		}
		catch (Exception $e)
		{
			CFT_DisplayError("Unable to update the file.  A database error occurred.", "db_error");
		}

		// Prepare for the main loop.  Prevent the browser from disconnecting.
		header("Content-Type: application/json");
		@ob_flush();
		@flush();

		// Main loop.
		$lastdbupdate = $lastoutput = time();
		do
		{
			// Wait for a connection.
			// I'm aware that this is NOT the correct way to use stream_select().
			// It's for non-blocking use only but stream_socket_accept() doesn't block until there is a connection AND I don't want early termination of the TCP/IP handshake.
			$readfps = array($serverfp);
			$writefps = array();
			$exceptfps = NULL;
			$timeout = min($lastdbupdate + 60 - time(), $lastoutput + 15 - time());
			if ($timeout < 1)  $timeout = 0;
			$timeout++;
			$result = @stream_select($readfps, $writefps, $exceptfps, $timeout);
			if ($result === false)  CFT_DisplayError("Failed to wait for local transport interface.", "local_transport_failed");

			if (count($readfps) && ($fp = @stream_socket_accept($serverfp)) !== false)
			{
				$lastdbupdate = 0;

				fclose($fp);
			}

			// Update the database once per minute.
			if ($lastdbupdate < time() - 60)
			{
				try
				{
					$db->Query("UPDATE", array($cft_db_files, array(
						"lastrequest" => time(),
					), "WHERE" => "id = ?"), $row->id);
				}
				catch (Exception $e)
				{
					CFT_DisplayError("Unable to update the file.  A database error occurred.", "db_error");
				}

				try
				{
					$row = $db->GetRow("SELECT", array(
						"*",
						"FROM" => "?",
						"WHERE" => "id = ?",
					), $cft_db_files, $row->id);
				}
				catch (Exception $e)
				{
					CFT_DisplayError("Unable to retrieve file.  A database error occurred.", "db_error");
				}

				if (!$row)  CFT_DisplayError("Recipient rejected the request.  Try again later.", "recipient_rejected");

				if ($row->port > 0)  break;

				$lastdbupdate = time();
			}

			// Output some whitespace every 15 seconds to keep the client connection alive.
			if ($lastoutput < time() - 15)
			{
				echo " ";
				@ob_flush();
				@flush();

				$lastoutput = time();
			}

		} while (1);
	}

	$result = array(
		"success" => true
	);

	CFT_SendResult($result);
?>